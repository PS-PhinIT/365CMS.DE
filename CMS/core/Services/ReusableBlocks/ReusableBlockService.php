<?php
/**
 * Wiederverwendbare Editor.js-Blöcke (4.1.0, Bereich 3b – „Synced Snippets“), in der Oberfläche „EditorJS Block
 * Vorlagen“ (Verwaltung) und Block „Vorlagen“ (Editor).
 *
 * Ein Block hat einen Titel und Editor.js-Inhalt je Sprache (`content`, `content_en`). Beiträge, Seiten und andere
 * Blöcke binden ihn über den Editor.js-Block `reusableBlock` mit den Daten `{ "id": 12 }` ein; gerendert wird immer
 * der aktuelle Stand. Der Nutzungsindex (`reusable_block_usage`) wird beim Speichern der Inhalte gepflegt und dient
 * für „Verwendet in“, die Löschwarnung und das Aktualisieren verknüpfter Inhalte (Cache, Suchindex) nach einer
 * Änderung am Block.
 *
 * Rekursionsschutz: Ein Block, der (direkt oder über andere Blöcke) sich selbst einbindet, wird beim Speichern
 * abgelehnt; beim Rendern bricht der Dienst zusätzlich bei Wiederholung oder ab Tiefe 3 ab (Frontend: nichts,
 * Editor-Vorschau: Hinweis).
 *
 * Datenbank, Uhr, Sanitizer, Renderer und die Folgeaktion nach Änderungen lassen sich für Tests ersetzen.
 *
 * @package CMSv2\Core\Services\ReusableBlocks
 */

declare(strict_types=1);

namespace CMS\Services\ReusableBlocks;

use CMS\Database;
use CMS\Hooks;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class ReusableBlockService
{
    public const BLOCK_TYPE = 'reusableBlock';
    public const CONTENT_TYPE = 'reusable_block';
    public const STATUS_ACTIVE = 'active';
    public const MAX_DEPTH = 3;
    public const REVISION_LIMIT = 20;
    public const TITLE_MAX = 150;

    /** Inhaltsarten, deren Verwendungen „Verwendet in“ auflöst. */
    private const USAGE_TABLES = [
        'post' => ['table' => 'posts', 'edit' => '/admin/posts?action=edit&id=', 'label' => 'Beitrag'],
        'page' => ['table' => 'pages', 'edit' => '/admin/pages?action=edit&id=', 'label' => 'Seite'],
        self::CONTENT_TYPE => ['table' => 'reusable_blocks', 'edit' => '/admin/reusable-blocks?action=edit&id=', 'label' => 'Vorlage'],
    ];

    private const SAVE_HOOKS = ['post' => 'cms_after_post_save', 'page' => 'cms_after_page_save'];

    private static ?self $instance = null;

    /** @var list<int> IDs der gerade gerenderten Blöcke (Rekursionsschutz) */
    private static array $renderStack = [];

    private Database $db;
    private string $prefix;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(string): string Editor.js-JSON bereinigen */
    private \Closure $sanitizer;
    /** @var \Closure(string): string Editor.js-JSON → HTML */
    private \Closure $renderer;
    /** @var \Closure(int, list<array{type:string,id:int}>): void nach Änderung/Löschen eines Blocks */
    private \Closure $onChanged;

    public function __construct(
        ?Database $db = null,
        ?\Closure $clock = null,
        ?\Closure $sanitizer = null,
        ?\Closure $renderer = null,
        ?\Closure $onChanged = null
    ) {
        $this->db = $db ?? Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->clock = $clock ?? static fn (): int => time();
        $this->sanitizer = $sanitizer ?? static fn (string $json): string => \CMS\Services\EditorJsService::getInstance()->sanitize($json);
        $this->renderer = $renderer ?? static fn (string $json): string => \CMS\Services\EditorService::getInstance()->renderContent($json);
        $this->onChanged = $onChanged ?? \Closure::fromCallable([self::class, 'refreshLinkedContent']);
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    // ── Reine Hilfen ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * IDs aller eingebundenen Blöcke in einem Editor.js-Inhalt (auch in verschachtelten Blöcken, z. B. Spalten).
     *
     * @param string|array<mixed> $content
     * @return list<int>
     */
    public static function extractBlockIds(string|array $content): array
    {
        if (is_string($content)) {
            $content = trim($content);
            if ($content === '' || ($content[0] !== '{' && $content[0] !== '[')) {
                return [];
            }
            $decoded = json_decode($content, true, 64);
            if (!is_array($decoded)) {
                return [];
            }
            $content = $decoded;
        }
        $ids = [];
        self::collectIds($content, $ids, 0);

        return array_keys($ids);
    }

    /**
     * @param array<mixed> $node
     * @param array<int, true> $ids
     */
    private static function collectIds(array $node, array &$ids, int $depth): void
    {
        if ($depth > 32) {
            return;
        }
        if (($node['type'] ?? null) === self::BLOCK_TYPE && is_array($node['data'] ?? null)) {
            $id = self::normalizeId($node['data']['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                self::collectIds($value, $ids, $depth + 1);
            } elseif (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[') && str_contains($value, self::BLOCK_TYPE)) {
                // Verschachtelte Editor.js-Inhalte, die als JSON-Zeichenkette gespeichert sind (z. B. Spalten-Blöcke).
                $decoded = json_decode($value, true, 64);
                if (is_array($decoded)) {
                    self::collectIds($decoded, $ids, $depth + 1);
                }
            }
        }
    }

    public static function normalizeId(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_string($value) && preg_match('/^\d{1,10}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return 0;
    }

    /**
     * Sanitizer des Editor.js-Blocks: nur die ID bleibt erhalten.
     *
     * @param array<string, mixed> $data
     * @return array{id: int}
     */
    public static function sanitizeBlockData(array $data): array
    {
        return ['id' => self::normalizeId($data['id'] ?? 0)];
    }

    /**
     * Würde Block $blockId einen Kreis bilden, wenn er $includedIds einbindet? $contentOf liefert den Inhalt
     * (beide Sprachen zusammen) eines anderen Blocks.
     *
     * @param list<int> $includedIds
     * @param callable(int): string $contentOf
     */
    public static function createsCycle(int $blockId, array $includedIds, callable $contentOf, int $maxNodes = 500): bool
    {
        $queue = $includedIds;
        $seen = [];
        while ($queue !== [] && count($seen) < $maxNodes) {
            $current = (int) array_shift($queue);
            if ($current === $blockId && $blockId > 0) {
                return true;
            }
            if ($current <= 0 || isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach (self::extractBlockIds($contentOf($current)) as $next) {
                $queue[] = $next;
            }
        }

        return false;
    }

    /**
     * Längste Kette eingebundener Vorlagen ab $ids (jede Vorlage zählt eine Ebene). Zyklen sind vorher ausgeschlossen;
     * $guard begrenzt die Rekursion trotzdem.
     *
     * @param list<int> $ids
     */
    public static function chainDepth(array $ids, callable $contentOf, int $guard = 10): int
    {
        $max = 0;
        foreach (array_unique($ids) as $id) {
            if ((int) $id <= 0 || $guard <= 0) {
                continue;
            }
            $max = max($max, 1 + self::chainDepth(self::extractBlockIds($contentOf((int) $id)), $contentOf, $guard - 1));
        }

        return $max;
    }

    public static function hasBlocks(?string $json): bool
    {
        $json = trim((string) $json);
        if ($json === '') {
            return false;
        }
        $decoded = json_decode($json, true, 64);

        return is_array($decoded) && is_array($decoded['blocks'] ?? null) && $decoded['blocks'] !== [];
    }

    // ── Rendern ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * HTML eines Blocks in der gewünschten Sprache (EN fällt auf DE zurück, wenn `content_en` leer ist).
     *
     * @param string $mode `frontend` oder `preview` (Editor-Vorschau mit Hinweisen statt leerer Ausgabe)
     */
    public function render(int $id, string $locale = 'de', string $mode = 'frontend'): string
    {
        $preview = $mode === 'preview';
        if ($id <= 0) {
            return $preview ? self::notice('Bitte eine Vorlage auswählen.') : '';
        }
        if (in_array($id, self::$renderStack, true) || count(self::$renderStack) >= self::MAX_DEPTH) {
            $this->logWarning('Wiederverwendbarer Block wurde wegen Rekursion nicht gerendert.', ['block_id' => $id, 'stack' => self::$renderStack]);

            return $preview ? self::notice(sprintf('Vorlage #%d bindet sich selbst ein oder ist zu tief verschachtelt (höchstens %d Ebenen) und wird nicht angezeigt.', $id, self::MAX_DEPTH)) : '';
        }

        $block = $this->find($id);
        if ($block === null) {
            return $preview ? self::notice(sprintf('Vorlage #%d existiert nicht mehr. Im Frontend erscheint an dieser Stelle nichts.', $id)) : '';
        }

        $content = $locale === 'en' && self::hasBlocks($block['content_en']) ? (string) $block['content_en'] : $block['content'];
        self::$renderStack[] = $id;
        try {
            $html = ($this->renderer)($content);
        } catch (\Throwable $e) {
            $this->logWarning('Wiederverwendbarer Block konnte nicht gerendert werden.', ['block_id' => $id, 'exception' => $e->getMessage()]);
            $html = '';
        } finally {
            array_pop(self::$renderStack);
        }
        if (trim($html) === '') {
            return $preview ? self::notice(sprintf('Vorlage „%s“ ist leer.', $block['title'])) : '';
        }

        return '<div class="cms-reusable-block" data-reusable-block="' . $id . '">' . $html . '</div>';
    }

    /**
     * Editor.js-Inhalt einer Vorlage für den Suchindex (EN fällt auf DE zurück). Verschachtelte Vorlagen löst
     * SiteSearchService::plainText() rekursiv auf; Wiederholung und Tiefe > MAX_DEPTH ergeben leeren Text.
     *
     * @param array<string, mixed> $data Blockdaten `{ id }`
     */
    public function searchContent(array $data, string $locale = 'de'): string
    {
        $id = self::normalizeId($data['id'] ?? 0);
        if ($id <= 0 || in_array($id, self::$renderStack, true) || count(self::$renderStack) >= self::MAX_DEPTH) {
            return '';
        }
        $block = $this->find($id);
        if ($block === null) {
            return '';
        }
        $content = $locale === 'en' && self::hasBlocks($block['content_en']) ? (string) $block['content_en'] : $block['content'];
        self::$renderStack[] = $id;
        try {
            return \CMS\Services\SiteSearchService::plainText($content, $locale);
        } finally {
            array_pop(self::$renderStack);
        }
    }

    private static function notice(string $text): string
    {
        return '<div class="cms-reusable-block cms-reusable-block--notice"><p class="text-secondary mb-0">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p></div>';
    }

    // ── Lesen ───────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @return array{id:int,title:string,content:string,content_en:?string,status:string,created_by:int,updated_by:int,created_at:string,updated_at:string}|null
     */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $row = $this->db->get_row(
                "SELECT * FROM {$this->prefix}reusable_blocks WHERE id = ? AND status = ? LIMIT 1",
                [$id, self::STATUS_ACTIVE]
            );
        } catch (\Throwable $e) {
            $this->logWarning('Wiederverwendbarer Block konnte nicht geladen werden.', ['block_id' => $id, 'exception' => $e->getMessage()]);

            return null;
        }
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'title' => (string) ($row->title ?? ''),
            'content' => (string) ($row->content ?? ''),
            'content_en' => isset($row->content_en) ? (string) $row->content_en : null,
            'status' => (string) ($row->status ?? self::STATUS_ACTIVE),
            'created_by' => (int) ($row->created_by ?? 0),
            'updated_by' => (int) ($row->updated_by ?? 0),
            'created_at' => (string) ($row->created_at ?? ''),
            'updated_at' => (string) ($row->updated_at ?? ''),
        ];
    }

    /**
     * Alle aktiven Blöcke mit Zahl der Verwendungen, optional gefiltert.
     *
     * @return list<array{id:int,title:string,updated_at:string,usage_count:int,has_en:bool}>
     */
    public function all(string $search = ''): array
    {
        $where = 'b.status = ?';
        $params = [self::STATUS_ACTIVE];
        $search = trim($search);
        if ($search !== '') {
            $where .= ' AND b.title LIKE ?';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }
        try {
            $rows = $this->db->get_results(
                "SELECT b.id, b.title, b.updated_at, b.content_en,
                        (SELECT COUNT(*) FROM {$this->prefix}reusable_block_usage u WHERE u.block_id = b.id) AS usage_count
                 FROM {$this->prefix}reusable_blocks b
                 WHERE {$where}
                 ORDER BY b.title ASC, b.id ASC
                 LIMIT 500",
                $params
            ) ?: [];
        } catch (\Throwable $e) {
            $this->logWarning('Liste der wiederverwendbaren Blöcke konnte nicht geladen werden.', ['exception' => $e->getMessage()]);

            return [];
        }

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'title' => (string) ($row->title ?? ''),
            'updated_at' => (string) ($row->updated_at ?? ''),
            'usage_count' => (int) ($row->usage_count ?? 0),
            'has_en' => self::hasBlocks(isset($row->content_en) ? (string) $row->content_en : ''),
        ], $rows);
    }

    /**
     * Auswahl im Editor (ID, Titel).
     *
     * @return list<array{id:int,title:string}>
     */
    public function choices(string $search = ''): array
    {
        return array_map(static fn (array $block): array => ['id' => $block['id'], 'title' => $block['title']], $this->all($search));
    }

    /**
     * Wo wird der Block verwendet?
     *
     * @return list<array{type:string,id:int,title:string,edit_url:string,label:string}>
     */
    public function usages(int $blockId): array
    {
        if ($blockId <= 0) {
            return [];
        }
        try {
            $rows = $this->db->get_results(
                "SELECT content_type, content_id FROM {$this->prefix}reusable_block_usage WHERE block_id = ? ORDER BY content_type, content_id",
                [$blockId]
            ) ?: [];
        } catch (\Throwable $e) {
            $this->logWarning('Verwendungen konnten nicht gelesen werden.', ['block_id' => $blockId, 'exception' => $e->getMessage()]);

            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            $type = (string) $row->content_type;
            $id = (int) $row->content_id;
            $meta = self::USAGE_TABLES[$type] ?? null;
            $title = '';
            if ($meta !== null) {
                try {
                    $title = (string) ($this->db->get_var("SELECT title FROM {$this->prefix}{$meta['table']} WHERE id = ? LIMIT 1", [$id]) ?? '');
                } catch (\Throwable) {
                    $title = '';
                }
            }
            $result[] = [
                'type' => $type,
                'id' => $id,
                'title' => $title !== '' ? $title : '#' . $id,
                'edit_url' => $meta !== null ? $meta['edit'] . $id : '',
                'label' => $meta['label'] ?? $type,
            ];
        }

        return $result;
    }

    /**
     * Editor.js-Blöcke eines Blocks für „In normale Blöcke umwandeln“ (ohne Block-IDs, damit Editor.js neue vergibt).
     *
     * @return list<array<string, mixed>>
     */
    public function blocksForConversion(int $id, string $locale = 'de'): array
    {
        $block = $this->find($id);
        if ($block === null) {
            return [];
        }
        $json = $locale === 'en' && self::hasBlocks($block['content_en']) ? (string) $block['content_en'] : $block['content'];
        $decoded = json_decode($json, true, 64);
        $blocks = is_array($decoded) && is_array($decoded['blocks'] ?? null) ? $decoded['blocks'] : [];
        $result = [];
        foreach ($blocks as $item) {
            if (!is_array($item) || !is_string($item['type'] ?? null)) {
                continue;
            }
            unset($item['id']);
            $result[] = $item;
        }

        return $result;
    }

    // ── Schreiben ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Anlegen ($id = 0) oder Ändern. Vor einer Änderung wird der bisherige Stand als Revision gesichert.
     *
     * @return array{success: bool, id?: int, error?: string, message?: string}
     */
    public function save(int $id, string $title, string $content, ?string $contentEn, int $userId): array
    {
        $title = self::cleanTitle($title);
        if ($title === '') {
            return ['success' => false, 'error' => 'Bitte einen Titel angeben.'];
        }
        $content = ($this->sanitizer)($content !== '' ? $content : '{"blocks":[]}');
        $contentEn = $contentEn !== null && trim($contentEn) !== '' ? ($this->sanitizer)($contentEn) : null;
        if ($contentEn !== null && !self::hasBlocks($contentEn)) {
            $contentEn = null;
        }

        $existing = $id > 0 ? $this->find($id) : null;
        if ($id > 0 && $existing === null) {
            return ['success' => false, 'error' => 'Die Vorlage existiert nicht mehr.'];
        }

        $included = array_values(array_unique(array_merge(self::extractBlockIds($content), self::extractBlockIds((string) $contentEn))));
        if ($id > 0 && in_array($id, $included, true)) {
            return ['success' => false, 'error' => 'Eine Vorlage kann sich nicht selbst einbinden.'];
        }
        if ($id > 0 && self::createsCycle($id, $included, fn (int $other): string => $this->combinedContent($other))) {
            return ['success' => false, 'error' => 'Die Vorlage würde sich über eine eingebundene Vorlage selbst einbinden.'];
        }
        // FUN-410-05: Die Tiefe wird schon beim Speichern geprüft – sonst fehlen tiefere Ebenen im Frontend still.
        $depth = ($id > 0 ? $this->ancestorDepth($id) : 0) + 1 + self::chainDepth($included, fn (int $other): string => $this->combinedContent($other));
        if ($depth > self::MAX_DEPTH) {
            return ['success' => false, 'error' => sprintf('Die Vorlage wäre zu tief verschachtelt (%d Ebenen, höchstens %d). Bitte eine Ebene entfernen.', $depth, self::MAX_DEPTH)];
        }

        $now = $this->now();
        try {
            if ($existing === null) {
                $this->db->execute(
                    "INSERT INTO {$this->prefix}reusable_blocks (title, content, content_en, status, created_by, updated_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$title, $content, $contentEn, self::STATUS_ACTIVE, $userId ?: null, $userId ?: null, $now, $now]
                );
                $id = (int) $this->db->lastInsertId();
                $this->syncUsage(self::CONTENT_TYPE, $id, [$content, (string) $contentEn]);
                $this->fireHook('cms_reusable_block_saved', $id);

                return ['success' => true, 'id' => $id, 'message' => 'Vorlage angelegt.'];
            }

            $unchanged = $existing['title'] === $title && $existing['content'] === $content && ($existing['content_en'] ?? null) === $contentEn;
            if ($unchanged) {
                return ['success' => true, 'id' => $id, 'message' => 'Keine Änderungen.'];
            }
            $this->addRevision($existing, $userId);
            $this->db->execute(
                "UPDATE {$this->prefix}reusable_blocks SET title = ?, content = ?, content_en = ?, updated_by = ?, updated_at = ? WHERE id = ?",
                [$title, $content, $contentEn, $userId ?: null, $now, $id]
            );
            $this->syncUsage(self::CONTENT_TYPE, $id, [$content, (string) $contentEn]);
        } catch (\Throwable $e) {
            $this->logWarning('Wiederverwendbarer Block konnte nicht gespeichert werden.', ['block_id' => $id, 'exception' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Die Vorlage konnte nicht gespeichert werden.'];
        }

        $this->fireHook('cms_reusable_block_saved', $id);
        $this->notifyChanged($id);

        return ['success' => true, 'id' => $id, 'message' => 'Vorlage gespeichert. Beiträge und Seiten, die sie nutzen, zeigen sofort den neuen Stand.'];
    }

    /**
     * @return array{success: bool, id?: int, error?: string, message?: string}
     */
    public function duplicate(int $id, int $userId): array
    {
        $block = $this->find($id);
        if ($block === null) {
            return ['success' => false, 'error' => 'Die Vorlage existiert nicht mehr.'];
        }
        $title = self::cleanTitle($block['title'] . ' (Kopie)');
        $result = $this->save(0, $title, $block['content'], $block['content_en'], $userId);
        if (!empty($result['success'])) {
            $result['message'] = 'Kopie angelegt.';
        }

        return $result;
    }

    /**
     * Löschen. Wird der Block noch verwendet, ist eine Bestätigung nötig; die Inhalte zeigen danach an der Stelle
     * nichts mehr (Editor: Hinweis).
     *
     * @return array{success: bool, error?: string, message?: string, needs_confirmation?: bool, usages?: list<array<string, mixed>>}
     */
    public function delete(int $id, bool $confirmed): array
    {
        if ($this->find($id) === null) {
            return ['success' => false, 'error' => 'Die Vorlage existiert nicht mehr.'];
        }
        $usages = $this->usages($id);
        if ($usages !== [] && !$confirmed) {
            return [
                'success' => false,
                'needs_confirmation' => true,
                'usages' => $usages,
                'error' => sprintf('Die Vorlage wird an %d Stelle(n) verwendet. Bitte das Löschen bestätigen – dort erscheint danach nichts mehr.', count($usages)),
            ];
        }
        // FUN-410-06: auch mittelbar verknüpfte Inhalte (Beitrag → Vorlage B → diese Vorlage) neu indizieren.
        $linked = $this->linkedContent($id);
        try {
            $this->db->execute("DELETE FROM {$this->prefix}reusable_block_revisions WHERE block_id = ?", [$id]);
            $this->db->execute("DELETE FROM {$this->prefix}reusable_blocks WHERE id = ?", [$id]);
            // Einbindungen durch den gelöschten Block und Verweise auf ihn (IDs werden nie neu vergeben).
            $this->db->execute("DELETE FROM {$this->prefix}reusable_block_usage WHERE content_type = ? AND content_id = ?", [self::CONTENT_TYPE, $id]);
            $this->db->execute("DELETE FROM {$this->prefix}reusable_block_usage WHERE block_id = ?", [$id]);
        } catch (\Throwable $e) {
            $this->logWarning('Wiederverwendbarer Block konnte nicht gelöscht werden.', ['block_id' => $id, 'exception' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Die Vorlage konnte nicht gelöscht werden.'];
        }
        $this->fireHook('cms_reusable_block_deleted', $id);
        try {
            ($this->onChanged)($id, $linked);
        } catch (\Throwable $e) {
            $this->logWarning('Verknüpfte Inhalte konnten nach dem Löschen nicht aktualisiert werden.', ['block_id' => $id, 'exception' => $e->getMessage()]);
        }

        return ['success' => true, 'message' => 'Vorlage gelöscht.'];
    }

    // ── Verlauf ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @return list<array{id:int,title:string,created_at:string,created_by_name:string}>
     */
    public function revisions(int $blockId): array
    {
        try {
            $rows = $this->db->get_results(
                "SELECT r.id, r.title, r.created_at, COALESCE(NULLIF(u.display_name, ''), u.username, '') AS created_by_name
                 FROM {$this->prefix}reusable_block_revisions r
                 LEFT JOIN {$this->prefix}users u ON u.id = r.created_by
                 WHERE r.block_id = ?
                 ORDER BY r.created_at DESC, r.id DESC
                 LIMIT " . self::REVISION_LIMIT,
                [$blockId]
            ) ?: [];
        } catch (\Throwable $e) {
            $this->logWarning('Verlauf konnte nicht gelesen werden.', ['block_id' => $blockId, 'exception' => $e->getMessage()]);

            return [];
        }

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'title' => (string) ($row->title ?? ''),
            'created_at' => (string) ($row->created_at ?? ''),
            'created_by_name' => (string) ($row->created_by_name ?? ''),
        ], $rows);
    }

    /**
     * Stellt eine Fassung wieder her (der aktuelle Stand wird vorher selbst zur Revision).
     *
     * @return array{success: bool, id?: int, error?: string, message?: string}
     */
    public function restoreRevision(int $blockId, int $revisionId, int $userId): array
    {
        try {
            $row = $this->db->get_row(
                "SELECT title, content, content_en FROM {$this->prefix}reusable_block_revisions WHERE id = ? AND block_id = ? LIMIT 1",
                [$revisionId, $blockId]
            );
        } catch (\Throwable) {
            $row = null;
        }
        if ($row === null) {
            return ['success' => false, 'error' => 'Diese Fassung gibt es nicht mehr.'];
        }
        $current = $this->find($blockId);
        if ($current === null) {
            return ['success' => false, 'error' => 'Die Vorlage existiert nicht mehr.'];
        }
        $result = $this->save($blockId, (string) ($row->title ?: $current['title']), (string) $row->content, isset($row->content_en) ? (string) $row->content_en : null, $userId);
        if (!empty($result['success'])) {
            $result['message'] = 'Fassung wiederhergestellt.';
        }

        return $result;
    }

    // ── Nutzungsindex ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Verwendungen eines Inhalts neu schreiben (nach dem Speichern).
     *
     * @param list<string> $contents Editor.js-Inhalte des Inhalts (z. B. DE und EN)
     */
    public function syncUsage(string $contentType, int $contentId, array $contents): void
    {
        if ($contentId <= 0 || preg_match('/^[a-z_]{1,30}$/', $contentType) !== 1) {
            return;
        }
        $ids = [];
        foreach ($contents as $content) {
            foreach (self::extractBlockIds((string) $content) as $blockId) {
                $ids[$blockId] = true;
            }
        }
        try {
            $this->db->execute(
                "DELETE FROM {$this->prefix}reusable_block_usage WHERE content_type = ? AND content_id = ?",
                [$contentType, $contentId]
            );
            foreach (array_keys($ids) as $blockId) {
                $this->db->execute(
                    "INSERT INTO {$this->prefix}reusable_block_usage (block_id, content_type, content_id) VALUES (?, ?, ?)",
                    [$blockId, $contentType, $contentId]
                );
            }
        } catch (\Throwable $e) {
            $this->logWarning('Nutzungsindex konnte nicht aktualisiert werden.', ['type' => $contentType, 'id' => $contentId, 'exception' => $e->getMessage()]);
        }
    }

    public function removeUsage(string $contentType, int $contentId): void
    {
        try {
            $this->db->execute(
                "DELETE FROM {$this->prefix}reusable_block_usage WHERE content_type = ? AND content_id = ?",
                [$contentType, $contentId]
            );
        } catch (\Throwable $e) {
            $this->logWarning('Nutzungsindex konnte nicht bereinigt werden.', ['type' => $contentType, 'id' => $contentId, 'exception' => $e->getMessage()]);
        }
    }

    /**
     * Alle Inhalte, die den Block direkt oder über andere Blöcke einbinden (ohne die Blöcke selbst).
     *
     * @return list<array{type:string,id:int}>
     */
    /**
     * Wie viele Vorlagen-Ebenen liegen über $blockId (Vorlagen, die ihn – auch mittelbar – einbinden)?
     */
    private function ancestorDepth(int $blockId, int $guard = 10): int
    {
        if ($guard <= 0) {
            return 0;
        }
        try {
            $parents = array_map('intval', $this->db->get_col(
                "SELECT DISTINCT content_id FROM {$this->prefix}reusable_block_usage WHERE content_type = ? AND block_id = ?",
                [self::CONTENT_TYPE, $blockId]
            ) ?: []);
        } catch (\Throwable) {
            return 0;
        }
        $max = 0;
        foreach ($parents as $parent) {
            if ($parent > 0 && $parent !== $blockId) {
                $max = max($max, 1 + $this->ancestorDepth($parent, $guard - 1));
            }
        }

        return $max;
    }

    public function linkedContent(int $blockId): array
    {
        $result = [];
        $queue = [$blockId];
        $seenBlocks = [];
        while ($queue !== [] && count($seenBlocks) < 500) {
            $current = (int) array_shift($queue);
            if (isset($seenBlocks[$current])) {
                continue;
            }
            $seenBlocks[$current] = true;
            try {
                $rows = $this->db->get_results(
                    "SELECT content_type, content_id FROM {$this->prefix}reusable_block_usage WHERE block_id = ?",
                    [$current]
                ) ?: [];
            } catch (\Throwable) {
                $rows = [];
            }
            foreach ($rows as $row) {
                $type = (string) $row->content_type;
                $id = (int) $row->content_id;
                if ($type === self::CONTENT_TYPE) {
                    $queue[] = $id;
                    continue;
                }
                $result[$type . ':' . $id] = ['type' => $type, 'id' => $id];
            }
        }

        return array_values($result);
    }

    // ── Intern ──────────────────────────────────────────────────────────────────────────────────────────────────

    private function notifyChanged(int $blockId): void
    {
        try {
            ($this->onChanged)($blockId, $this->linkedContent($blockId));
        } catch (\Throwable $e) {
            $this->logWarning('Verknüpfte Inhalte konnten nach der Blockänderung nicht aktualisiert werden.', ['block_id' => $blockId, 'exception' => $e->getMessage()]);
        }
    }

    /**
     * Standard-Folgeaktion: Seiten-Cache leeren und für jeden verknüpften Beitrag bzw. jede Seite die Speicher-Hooks
     * auslösen (Suchindex, Sitemap, Medien-Verwendung rendern den Block dann aufgelöst neu).
     *
     * @param list<array{type:string,id:int}> $linked
     */
    private static function refreshLinkedContent(int $blockId, array $linked): void
    {
        try {
            \CMS\CacheManager::instance()->clear();
        } catch (\Throwable) {
        }
        foreach ($linked as $item) {
            $hook = self::SAVE_HOOKS[$item['type']] ?? '';
            if ($hook === '') {
                continue;
            }
            try {
                Hooks::doAction($hook, (int) $item['id'], ['reusable_block' => $blockId], []);
            } catch (\Throwable) {
            }
        }
        Hooks::doAction('cms_reusable_block_changed', $blockId, $linked);
    }

    private function fireHook(string $hook, int $blockId): void
    {
        try {
            Hooks::doAction($hook, $blockId);
        } catch (\Throwable $e) {
            $this->logWarning('Folgeaktion nach Blockänderung fehlgeschlagen.', ['hook' => $hook, 'block_id' => $blockId, 'exception' => $e->getMessage()]);
        }
    }

    /**
     * @param array{id:int,title:string,content:string,content_en:?string} $block
     */
    private function addRevision(array $block, int $userId): void
    {
        $this->db->execute(
            "INSERT INTO {$this->prefix}reusable_block_revisions (block_id, title, content, content_en, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)",
            [$block['id'], $block['title'], $block['content'], $block['content_en'], $userId ?: null, $this->now()]
        );
        $keep = $this->db->get_results(
            "SELECT id FROM {$this->prefix}reusable_block_revisions WHERE block_id = ? ORDER BY created_at DESC, id DESC LIMIT " . self::REVISION_LIMIT,
            [$block['id']]
        ) ?: [];
        $keepIds = array_map(static fn (object $row): int => (int) $row->id, $keep);
        if (count($keepIds) >= self::REVISION_LIMIT) {
            $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
            $this->db->execute(
                "DELETE FROM {$this->prefix}reusable_block_revisions WHERE block_id = ? AND id NOT IN ({$placeholders})",
                array_merge([$block['id']], $keepIds)
            );
        }
    }

    /** Beide Sprachfassungen als ein JSON-Dokument (für die Kreisprüfung). */
    private function combinedContent(int $id): string
    {
        $block = $this->find($id);
        if ($block === null) {
            return '';
        }

        return (string) json_encode([
            json_decode($block['content'], true, 64),
            json_decode((string) ($block['content_en'] ?? ''), true, 64),
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function cleanTitle(string $title): string
    {
        $title = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($title)));

        return function_exists('mb_substr') ? mb_substr($title, 0, self::TITLE_MAX, 'UTF-8') : substr($title, 0, self::TITLE_MAX);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s', ($this->clock)());
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logWarning(string $message, array $context): void
    {
        try {
            Logger::instance()->withChannel('content.reusable_blocks')->warning($message, $context);
        } catch (\Throwable) {
        }
    }
}
