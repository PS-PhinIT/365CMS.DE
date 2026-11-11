<?php
/**
 * Beitragsrevisionen: Snapshots, Aufbewahrung, Vergleich.
 *
 * Revisionen sind Nach-dem-Speichern-Stände eines Beitrags. Neue Revisionen entstehen
 * beim manuellen Speichern (Typ manual), beim Autosave höchstens alle zehn Minuten
 * (autosave), bei einer Wiederherstellung (restore) und als Ausgangsstand (baseline),
 * wenn ein Beitrag noch keine Revision aus 4.0.0 besitzt oder der aktuelle Stand vor einer
 * Wiederherstellung noch nicht gesichert ist. Zeilen aus älteren Versionen bleiben als
 * Typ legacy erhalten. Pro Beitrag werden die letzten 30 Revisionen aufbewahrt.
 *
 * Der Vergleich stellt eine Revision ihrer Vorgängerin gegenüber: Titel/Meta-Felder als
 * Wort-Diff, der Editor.js-Inhalt Block für Block. Das gespeicherte Inhaltsformat bleibt
 * unverändert (Editor.js-JSON in posts.content / content_en).
 *
 * @package CMS\Services
 */

declare(strict_types=1);

namespace CMS\Services;

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Database;
use CMS\Json;
use CMS\Logger;

final class PostRevisionService
{
    public const MAX_REVISIONS_PER_POST = 30;
    public const AUTOSAVE_INTERVAL_SECONDS = 600;

    public const TYPE_MANUAL = 'manual';
    public const TYPE_AUTOSAVE = 'autosave';
    public const TYPE_RESTORE = 'restore';
    public const TYPE_BASELINE = 'baseline';
    public const TYPE_LEGACY = 'legacy';

    private const TYPE_LABELS = [
        self::TYPE_MANUAL => 'Gespeichert',
        self::TYPE_AUTOSAVE => 'Autosave',
        self::TYPE_RESTORE => 'Wiederhergestellt',
        self::TYPE_BASELINE => 'Ausgangsstand',
        self::TYPE_LEGACY => 'Ältere Version',
    ];

    /** Felder des Fingerabdrucks – Status und Datumswerte bewusst ausgenommen. */
    private const FINGERPRINT_FIELDS = [
        'title', 'title_en', 'slug', 'slug_en', 'content', 'content_en', 'excerpt', 'excerpt_en',
        'featured_image', 'meta_title', 'meta_description', 'category_id', 'tags',
        'author_display_name', 'author_display_url',
    ];

    /** @var array<string,string> Vergleichsfelder (Spalte => Label) */
    private const COMPARE_TEXT_FIELDS = [
        'title' => 'Titel (DE)',
        'title_en' => 'Titel (EN)',
        'slug' => 'Slug (DE)',
        'slug_en' => 'Slug (EN)',
        'excerpt' => 'Kurzfassung (DE)',
        'excerpt_en' => 'Kurzfassung (EN)',
        'meta_title' => 'Meta-Titel',
        'meta_description' => 'Meta-Beschreibung',
        'featured_image' => 'Beitragsbild',
        'category_name' => 'Kategorie',
        'tags' => 'Tags',
        'author_display_name' => 'Autorenname im Artikel',
        'author_display_url' => 'Website des Autors',
    ];

    private const BLOCK_TYPE_LABELS = [
        'paragraph' => 'Absatz',
        'header' => 'Überschrift',
        'list' => 'Liste',
        'checklist' => 'Checkliste',
        'quote' => 'Zitat',
        'code' => 'Code',
        'codeTabs' => 'Code-Tabs',
        'terminal' => 'Terminal',
        'table' => 'Tabelle',
        'image' => 'Bild',
        'imageGallery' => 'Bildergalerie',
        'carousel' => 'Karussell',
        'mediaText' => 'Medien & Text',
        'embed' => 'Einbettung',
        'linkTool' => 'Link',
        'attaches' => 'Datei',
        'delimiter' => 'Trenner',
        'spacer' => 'Abstand',
        'warning' => 'Warnung',
        'alert' => 'Hinweis',
        'callout' => 'Hinweisbox',
        'raw' => 'HTML',
        'columns' => 'Spalten',
        'accordion' => 'Akkordeon',
        'details' => 'Aufklappbereich',
        'button' => 'Button',
        'mermaid' => 'Diagramm',
        'drawingTool' => 'Zeichnung',
        'prosCons' => 'Pro & Contra',
        'changelog' => 'Changelog',
        'apiEndpoint' => 'API-Endpunkt',
    ];

    /** Schlüssel in Block-Daten, die keinen lesbaren Inhalt tragen. */
    private const BLOCK_SKIP_KEYS = [
        'id', 'style', 'level', 'alignment', 'align', 'variant', 'withBorder', 'withBackground', 'stretched',
        'withHeadings', 'meta', 'width', 'height', 'size', 'color', 'background', 'theme', 'layout', 'ratio',
        'checked', 'counterType', 'start', 'tunes', 'icon', 'service', 'source', 'type', 'embed',
        'spacing', 'spacingTop', 'spacingBottom', 'borderStyle', 'showBorder', 'shadow', 'rounded', 'preset',
        'widthPreset', 'maxHeight', 'imageWidth', 'imagePosition', 'imageFit', 'verticalAlignment', 'columns',
        'lineWidth', 'design', 'textColor', 'backgroundColor', 'fontSize',
    ];

    private const DIFF_MAX_CELLS = 160000;

    private static ?self $instance = null;

    private Database $db;
    private string $prefix;
    private string $table;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->table = $this->prefix . 'post_revisions';
        $this->ensureSchema();
    }

    // ── Schema ─────────────────────────────────────────────────────────────

    /**
     * Ergänzt die Revisionsspalten aus Schema v24, falls die Migration noch nicht gelaufen ist.
     */
    private function ensureSchema(): void
    {
        $this->db->runSchemaOnce('post_revisions_v24', __FILE__, function (): bool {
            try {
                if (!$this->db->columnExists($this->table, 'post_id')) {
                    // Tabelle fehlt noch – SchemaManager/PostsModule legen sie an; später erneut prüfen.
                    return false;
                }

                // Entspricht Migration v24 (MigrationManager) – greift, falls die Migration noch aussteht.
                $columns = [
                    'revision_type' => "ALTER TABLE {$this->table} ADD COLUMN revision_type VARCHAR(20) NOT NULL DEFAULT 'legacy'",
                    'created_by' => "ALTER TABLE {$this->table} ADD COLUMN created_by INT UNSIGNED DEFAULT NULL",
                    'featured_image' => "ALTER TABLE {$this->table} ADD COLUMN featured_image VARCHAR(500) DEFAULT NULL",
                    'meta_title' => "ALTER TABLE {$this->table} ADD COLUMN meta_title VARCHAR(255) DEFAULT NULL",
                    'meta_description' => "ALTER TABLE {$this->table} ADD COLUMN meta_description TEXT DEFAULT NULL",
                    'fingerprint' => "ALTER TABLE {$this->table} ADD COLUMN fingerprint CHAR(40) DEFAULT NULL",
                ];

                foreach ($columns as $column => $sql) {
                    if (!$this->db->columnExists($this->table, $column)) {
                        $this->db->getPdo()->exec($sql);
                    }
                }
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('admin.posts')->warning('Revisionsspalten (Schema v24) konnten nicht ergänzt werden.', [
                    'exception' => $e->getMessage(),
                ]);

                return false;
            }

            return true;
        });
    }

    // ── Snapshots ──────────────────────────────────────────────────────────

    /**
     * Liest den gespeicherten Zustand eines Beitrags als Revisions-Snapshot.
     *
     * @return array<string,mixed>|null
     */
    public function buildSnapshotFromPost(int $postId): ?array
    {
        if ($postId <= 0) {
            return null;
        }

        $row = $this->db->get_row(
            "SELECT p.id, p.title, p.title_en, p.slug, p.slug_en, p.content, p.content_en, p.excerpt, p.excerpt_en,
                    p.status, p.category_id, p.featured_image, p.tags, p.meta_title, p.meta_description,
                    p.author_id, p.author_display_name, p.author_display_url, p.published_at, p.content_updated_at,
                    c.name AS category_name
             FROM {$this->prefix}posts p
             LEFT JOIN {$this->prefix}post_categories c ON c.id = p.category_id
             WHERE p.id = ?
             LIMIT 1",
            [$postId]
        );

        if (!is_object($row)) {
            return null;
        }

        $snapshot = (array) $row;
        $snapshot['post_id'] = $postId;
        $tagNames = $this->getPostTagNames($postId);
        $snapshot['tags'] = $tagNames !== []
            ? implode(', ', $tagNames)
            : implode(', ', $this->splitTagNames((string) ($snapshot['tags'] ?? '')));

        return $this->normalizeSnapshot($snapshot);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function normalizeSnapshot(array $snapshot): array
    {
        $normalized = [
            'post_id' => (int) ($snapshot['post_id'] ?? $snapshot['id'] ?? 0),
            'status' => (string) ($snapshot['status'] ?? ''),
            'category_id' => (int) ($snapshot['category_id'] ?? 0),
            'author_id' => (int) ($snapshot['author_id'] ?? 0),
            'published_at' => $this->nullableString($snapshot['published_at'] ?? null),
            'content_updated_at' => $this->nullableString($snapshot['content_updated_at'] ?? null),
        ];

        foreach (['title', 'title_en', 'slug', 'slug_en', 'content', 'content_en', 'excerpt', 'excerpt_en', 'category_name', 'tags', 'featured_image', 'meta_title', 'meta_description', 'author_display_name', 'author_display_url'] as $field) {
            $value = $snapshot[$field] ?? '';
            $normalized[$field] = is_scalar($value) ? (string) $value : '';
        }

        return $normalized;
    }

    /**
     * Fingerabdruck der inhaltlich relevanten Felder. Editor.js-Metadaten wie `time` fließen nicht ein,
     * damit erneutes Speichern ohne Änderung keine neue Revision erzeugt.
     *
     * @param array<string,mixed> $snapshot
     */
    public function computeFingerprint(array $snapshot): string
    {
        $parts = [];
        foreach (self::FINGERPRINT_FIELDS as $field) {
            $value = $snapshot[$field] ?? '';
            if ($field === 'category_id') {
                $parts[$field] = (int) $value;
                continue;
            }

            $value = is_scalar($value) ? (string) $value : '';
            if ($field === 'tags') {
                // Tags sind eine Menge – Reihenfolge und Groß-/Kleinschreibung zählen nicht als Änderung.
                $tags = array_map(static fn(string $tag): string => mb_strtolower($tag), $this->splitTagNames($value));
                sort($tags, SORT_STRING);
                $parts[$field] = implode(',', array_values(array_unique($tags)));
                continue;
            }

            $parts[$field] = ($field === 'content' || $field === 'content_en')
                ? $this->normalizeContentForFingerprint($value)
                : trim($value);
        }

        return sha1((string) json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ── Aufzeichnen ────────────────────────────────────────────────────────

    /**
     * Speichert den aktuellen (bereits gespeicherten) Stand als Revision.
     *
     * @param array<string,mixed>|null $snapshot Optionaler Snapshot; Standard: aktueller DB-Stand.
     * @return array{stored:bool,reason:string,revision_id:int}
     */
    public function record(int $postId, string $type, ?int $userId = null, ?array $snapshot = null): array
    {
        $type = $this->normalizeType($type);
        $snapshot = $snapshot !== null ? $this->normalizeSnapshot($snapshot + ['post_id' => $postId]) : $this->buildSnapshotFromPost($postId);
        if ($snapshot === null || $postId <= 0) {
            return ['stored' => false, 'reason' => 'missing', 'revision_id' => 0];
        }

        $fingerprint = $this->computeFingerprint($snapshot);
        if ($this->getLatestFingerprint($postId) === $fingerprint) {
            return ['stored' => false, 'reason' => 'unchanged', 'revision_id' => 0];
        }

        if ($type === self::TYPE_AUTOSAVE && $this->hasRecentRevisionOfType($postId, self::TYPE_AUTOSAVE, self::AUTOSAVE_INTERVAL_SECONDS)) {
            return ['stored' => false, 'reason' => 'throttled', 'revision_id' => 0];
        }

        $revisionId = $this->insertRevision($postId, $type, $userId, $snapshot, $fingerprint);
        if ($revisionId <= 0) {
            return ['stored' => false, 'reason' => 'failed', 'revision_id' => 0];
        }

        $this->prune($postId);

        return ['stored' => true, 'reason' => 'stored', 'revision_id' => $revisionId];
    }

    /**
     * Sichert einen Ausgangsstand, bevor ein Beitrag überschrieben wird.
     *
     * Mit $onlyWhenUntracked = true nur, wenn noch keine Revision aus 4.0.0 existiert (Bestandsbeiträge);
     * sonst immer dann, wenn der Stand vom Fingerabdruck der jüngsten Revision abweicht
     * (z. B. vor einer Wiederherstellung nach ungesicherten Autosaves).
     *
     * @param array<string,mixed>|null $snapshot
     */
    public function captureBaseline(int $postId, ?array $snapshot = null, bool $onlyWhenUntracked = true): bool
    {
        if ($postId <= 0) {
            return false;
        }

        if ($onlyWhenUntracked && $this->hasTrackedRevisions($postId)) {
            return true;
        }

        $snapshot = $snapshot !== null ? $this->normalizeSnapshot($snapshot + ['post_id' => $postId]) : $this->buildSnapshotFromPost($postId);
        if ($snapshot === null) {
            return true;
        }

        $fingerprint = $this->computeFingerprint($snapshot);
        if ($this->getLatestFingerprint($postId) === $fingerprint) {
            return true;
        }

        return $this->insertRevision($postId, self::TYPE_BASELINE, null, $snapshot, $fingerprint) > 0;
    }

    public function hasTrackedRevisions(int $postId): bool
    {
        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE post_id = ? AND revision_type <> ?",
            [$postId, self::TYPE_LEGACY]
        ) > 0;
    }

    /**
     * Entfernt alles jenseits der letzten MAX_REVISIONS_PER_POST Revisionen.
     */
    public function prune(int $postId): void
    {
        try {
            $oldestKeptId = (int) $this->db->get_var(
                "SELECT id FROM {$this->table} WHERE post_id = ? ORDER BY id DESC LIMIT 1 OFFSET " . (self::MAX_REVISIONS_PER_POST - 1),
                [$postId]
            );
            if ($oldestKeptId > 0) {
                $this->db->execute(
                    "DELETE FROM {$this->table} WHERE post_id = ? AND id < ?",
                    [$postId, $oldestKeptId]
                );
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.posts')->warning('Alte Beitragsrevisionen konnten nicht bereinigt werden.', [
                'post_id' => $postId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    // ── Lesen ──────────────────────────────────────────────────────────────

    /**
     * @return list<array{id:int,type:string,type_label:string,created_at:string,created_at_label:string,author_label:string}>
     */
    public function listRevisions(int $postId, int $limit = self::MAX_REVISIONS_PER_POST): array
    {
        if ($postId <= 0) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $rows = $this->db->get_results(
            "SELECT pr.id, pr.revision_type, pr.created_at, pr.created_by, pr.author_id,
                    cu.display_name AS creator_display_name, cu.username AS creator_username,
                    au.display_name AS author_user_display_name, au.username AS author_username
             FROM {$this->table} pr
             LEFT JOIN {$this->prefix}users cu ON cu.id = pr.created_by
             LEFT JOIN {$this->prefix}users au ON au.id = pr.author_id
             WHERE pr.post_id = ?
             ORDER BY pr.id DESC
             LIMIT {$limit}",
            [$postId]
        ) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->buildRevisionMeta((array) $row);
        }

        return $items;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getRevision(int $postId, int $revisionId): ?array
    {
        if ($postId <= 0 || $revisionId <= 0) {
            return null;
        }

        $row = $this->db->get_row(
            "SELECT pr.*, cu.display_name AS creator_display_name, cu.username AS creator_username,
                    au.display_name AS author_user_display_name, au.username AS author_username
             FROM {$this->table} pr
             LEFT JOIN {$this->prefix}users cu ON cu.id = pr.created_by
             LEFT JOIN {$this->prefix}users au ON au.id = pr.author_id
             WHERE pr.post_id = ? AND pr.id = ?
             LIMIT 1",
            [$postId, $revisionId]
        );

        return is_object($row) ? (array) $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getPreviousRevision(int $postId, int $revisionId): ?array
    {
        $previousId = (int) $this->db->get_var(
            "SELECT id FROM {$this->table} WHERE post_id = ? AND id < ? ORDER BY id DESC LIMIT 1",
            [$postId, $revisionId]
        );

        return $previousId > 0 ? $this->getRevision($postId, $previousId) : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,type:string,type_label:string,created_at:string,created_at_label:string,author_label:string}
     */
    public function buildRevisionMeta(array $row): array
    {
        $type = $this->normalizeType((string) ($row['revision_type'] ?? self::TYPE_LEGACY));
        $createdAt = (string) ($row['created_at'] ?? '');
        $timestamp = $createdAt !== '' ? strtotime($createdAt) : false;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'type' => $type,
            'type_label' => self::TYPE_LABELS[$type],
            'created_at' => $timestamp !== false ? date('c', $timestamp) : '',
            'created_at_label' => $timestamp !== false ? date('d.m.Y, H:i', $timestamp) . ' Uhr' : 'Unbekanntes Datum',
            'author_label' => $this->resolveAuthorLabel($row),
        ];
    }

    // ── Vergleich ──────────────────────────────────────────────────────────

    /**
     * Vergleicht eine Revision mit ihrer Vorgängerversion.
     *
     * @return array<string,mixed>|null
     */
    public function compare(int $postId, int $revisionId): ?array
    {
        $revision = $this->getRevision($postId, $revisionId);
        if ($revision === null) {
            return null;
        }

        $previous = $this->getPreviousRevision($postId, $revisionId);
        $after = $this->normalizeSnapshot($revision);
        $before = $previous !== null ? $this->normalizeSnapshot($previous) : $this->normalizeSnapshot([]);

        $fields = [];
        foreach (self::COMPARE_TEXT_FIELDS as $field => $label) {
            $old = $this->normalizeInlineText((string) ($before[$field] ?? ''));
            $new = $this->normalizeInlineText((string) ($after[$field] ?? ''));
            if ($old === $new) {
                continue;
            }

            $fields[] = [
                'field' => $field,
                'label' => $label,
                'before' => $old,
                'after' => $new,
                'segments' => $this->diffText($old, $new),
            ];
        }

        $content = [];
        foreach (['content' => 'Inhalt (DE)', 'content_en' => 'Inhalt (EN)'] as $field => $label) {
            $diff = $this->diffEditorContent((string) ($before[$field] ?? ''), (string) ($after[$field] ?? ''));
            if ($field === 'content_en' && $diff['blocks'] === []) {
                continue;
            }

            $diff['field'] = $field;
            $diff['label'] = $label;
            $content[] = $diff;
        }

        $hasContentChanges = false;
        foreach ($content as $entry) {
            if (($entry['stats']['added'] ?? 0) + ($entry['stats']['removed'] ?? 0) + ($entry['stats']['changed'] ?? 0) > 0) {
                $hasContentChanges = true;
                break;
            }
        }

        return [
            'revision' => $this->buildRevisionMeta($revision),
            'previous' => $previous !== null ? $this->buildRevisionMeta($previous) : null,
            'fields' => $fields,
            'content' => $content,
            'has_changes' => $fields !== [] || $hasContentChanges,
        ];
    }

    /**
     * Block-für-Block-Vergleich zweier Editor.js-Inhalte.
     *
     * @return array{blocks:list<array<string,mixed>>,stats:array{added:int,removed:int,changed:int,unchanged:int}}
     */
    public function diffEditorContent(string $beforeRaw, string $afterRaw): array
    {
        $before = $this->extractBlocks($beforeRaw);
        $after = $this->extractBlocks($afterRaw);
        $stats = ['added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 0];

        $keysBefore = array_map(static fn(array $block): string => $block['key'], $before);
        $keysAfter = array_map(static fn(array $block): string => $block['key'], $after);
        $ops = $this->diffSequences($keysBefore, $keysAfter);

        $result = [];
        $pendingRemoved = [];
        $pendingAdded = [];

        $flush = function () use (&$pendingRemoved, &$pendingAdded, &$result, &$stats, $before, $after): void {
            $pairs = min(count($pendingRemoved), count($pendingAdded));
            $usedRemoved = [];
            $usedAdded = [];
            for ($k = 0; $k < $pairs; $k++) {
                $old = $before[$pendingRemoved[$k]];
                $new = $after[$pendingAdded[$k]];
                if ($old['type'] !== $new['type']) {
                    continue;
                }

                $usedRemoved[$k] = true;
                $usedAdded[$k] = true;
            }

            $removedQueue = [];
            foreach ($pendingRemoved as $k => $index) {
                if (empty($usedRemoved[$k])) {
                    $removedQueue[] = $before[$index];
                }
            }
            foreach ($removedQueue as $block) {
                $result[] = $this->buildBlockEntry('removed', $block['type'], [['op' => 'del', 'text' => $block['text']]]);
                $stats['removed']++;
            }

            foreach ($pendingAdded as $k => $index) {
                $new = $after[$index];
                if (!empty($usedAdded[$k])) {
                    $old = $before[$pendingRemoved[$k]];
                    $result[] = $this->buildBlockEntry('changed', $new['type'], $this->diffText($old['text'], $new['text']));
                    $stats['changed']++;
                    continue;
                }

                $result[] = $this->buildBlockEntry('added', $new['type'], [['op' => 'ins', 'text' => $new['text']]]);
                $stats['added']++;
            }

            $pendingRemoved = [];
            $pendingAdded = [];
        };

        foreach ($ops as [$op, $i, $j]) {
            if ($op === 'del') {
                $pendingRemoved[] = $i;
                continue;
            }
            if ($op === 'ins') {
                $pendingAdded[] = $j;
                continue;
            }

            $flush();
            $old = $before[$i];
            $new = $after[$j];
            if ($old['text'] === $new['text'] && $old['type'] === $new['type']) {
                $result[] = $this->buildBlockEntry('unchanged', $new['type'], [['op' => 'eq', 'text' => $new['text']]]);
                $stats['unchanged']++;
                continue;
            }

            $result[] = $this->buildBlockEntry('changed', $new['type'], $this->diffText($old['text'], $new['text']));
            $stats['changed']++;
        }
        $flush();

        return ['blocks' => $result, 'stats' => $stats];
    }

    /**
     * Wortweiser Textvergleich.
     *
     * @return list<array{op:string,text:string}>
     */
    public function diffText(string $before, string $after): array
    {
        if ($before === $after) {
            return $before === '' ? [] : [['op' => 'eq', 'text' => $before]];
        }

        $tokensBefore = $this->tokenize($before);
        $tokensAfter = $this->tokenize($after);
        $segments = [];

        foreach ($this->diffSequences($tokensBefore, $tokensAfter) as [$op, $i, $j]) {
            $text = $op === 'ins' ? $tokensAfter[$j] : $tokensBefore[$i];
            $last = count($segments) - 1;
            if ($last >= 0 && $segments[$last]['op'] === $op) {
                $segments[$last]['text'] .= $text;
                continue;
            }

            $segments[] = ['op' => $op, 'text' => $text];
        }

        return $segments;
    }

    // ── Interna ────────────────────────────────────────────────────────────

    /**
     * Längste gemeinsame Teilfolge mit vorgeschaltetem Präfix-/Suffix-Abgleich.
     * Zu große Mittelteile werden als vollständiges Löschen + Einfügen gewertet.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0:string,1:int,2:int}>
     */
    private function diffSequences(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $start = 0;
        while ($start < $n && $start < $m && $a[$start] === $b[$start]) {
            $start++;
        }

        $endA = $n - 1;
        $endB = $m - 1;
        while ($endA >= $start && $endB >= $start && $a[$endA] === $b[$endB]) {
            $endA--;
            $endB--;
        }

        $ops = [];
        for ($k = 0; $k < $start; $k++) {
            $ops[] = ['eq', $k, $k];
        }

        $lenA = $endA - $start + 1;
        $lenB = $endB - $start + 1;

        if ($lenA > 0 && $lenB > 0 && $lenA * $lenB <= self::DIFF_MAX_CELLS) {
            $width = $lenB + 1;
            $table = array_fill(0, ($lenA + 1) * $width, 0);
            for ($i = $lenA - 1; $i >= 0; $i--) {
                for ($j = $lenB - 1; $j >= 0; $j--) {
                    $table[$i * $width + $j] = $a[$start + $i] === $b[$start + $j]
                        ? $table[($i + 1) * $width + $j + 1] + 1
                        : max($table[($i + 1) * $width + $j], $table[$i * $width + $j + 1]);
                }
            }

            $i = 0;
            $j = 0;
            while ($i < $lenA && $j < $lenB) {
                if ($a[$start + $i] === $b[$start + $j]) {
                    $ops[] = ['eq', $start + $i, $start + $j];
                    $i++;
                    $j++;
                } elseif ($table[($i + 1) * $width + $j] >= $table[$i * $width + $j + 1]) {
                    $ops[] = ['del', $start + $i, -1];
                    $i++;
                } else {
                    $ops[] = ['ins', -1, $start + $j];
                    $j++;
                }
            }
            for (; $i < $lenA; $i++) {
                $ops[] = ['del', $start + $i, -1];
            }
            for (; $j < $lenB; $j++) {
                $ops[] = ['ins', -1, $start + $j];
            }
        } else {
            for ($i = 0; $i < $lenA; $i++) {
                $ops[] = ['del', $start + $i, -1];
            }
            for ($j = 0; $j < $lenB; $j++) {
                $ops[] = ['ins', -1, $start + $j];
            }
        }

        for ($k = 1; $endA + $k < $n; $k++) {
            $ops[] = ['eq', $endA + $k, $endB + $k];
        }

        return $ops;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $tokens = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return is_array($tokens) ? array_values($tokens) : [$text];
    }

    /**
     * @return list<array{key:string,type:string,text:string}>
     */
    private function extractBlocks(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = Json::decodeArray($raw, []);
        if (!isset($decoded['blocks']) || !is_array($decoded['blocks'])) {
            $text = $this->normalizeBlockText(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $raw)));

            return $text === '' ? [] : [['key' => 'tx:' . sha1('html' . "\0" . $text), 'type' => 'html', 'text' => $text]];
        }

        $blocks = [];
        foreach ($decoded['blocks'] as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($block['type'] ?? '')) ?? '';
            $type = $type !== '' ? $type : 'unbekannt';
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $text = $type === 'table' ? $this->extractTableText($data) : $this->normalizeBlockText(implode("\n", $this->collectStrings($data)));
            if ($text === '' && in_array($type, ['delimiter', 'spacer'], true)) {
                $text = $type === 'delimiter' ? '* * *' : '—';
            }

            $id = is_scalar($block['id'] ?? null) ? trim((string) $block['id']) : '';
            $blocks[] = [
                'key' => $id !== '' ? 'id:' . $id : 'tx:' . sha1($type . "\0" . $text),
                'type' => $type,
                'text' => $text,
            ];
        }

        return $blocks;
    }

    /**
     * @param array<mixed> $data
     * @return list<string>
     */
    private function collectStrings(array $data, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }

        $strings = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::BLOCK_SKIP_KEYS, true) && !is_array($value)) {
                continue;
            }

            if (is_array($value)) {
                foreach ($this->collectStrings($value, $depth + 1) as $string) {
                    $strings[] = $string;
                }
                continue;
            }

            if (is_string($value) || is_int($value) || is_float($value)) {
                $string = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($string !== '') {
                    $strings[] = $string;
                }
            }
        }

        return $strings;
    }

    /**
     * @param array<mixed> $data
     */
    private function extractTableText(array $data): string
    {
        $rows = is_array($data['content'] ?? null) ? $data['content'] : [];
        $lines = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $cells = [];
            foreach ($row as $cell) {
                $cells[] = is_scalar($cell) ? trim(html_entity_decode(strip_tags((string) $cell), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
            }
            $lines[] = implode(' | ', $cells);
        }

        return $this->normalizeBlockText(implode("\n", $lines));
    }

    private function normalizeBlockText(string $text): string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $normalized = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? '');
            if ($line !== '') {
                $normalized[] = $line;
            }
        }

        return implode("\n", $normalized);
    }

    private function normalizeInlineText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
    }

    /**
     * @param list<array{op:string,text:string}> $segments
     * @return array{status:string,type:string,type_label:string,segments:list<array{op:string,text:string}>}
     */
    private function buildBlockEntry(string $status, string $type, array $segments): array
    {
        return [
            'status' => $status,
            'type' => $type,
            'type_label' => self::BLOCK_TYPE_LABELS[$type] ?? \CMS\Services\EditorJs\EditorJsBlockRegistry::label($type) ?? $type,
            'segments' => $segments,
        ];
    }

    private function normalizeContentForFingerprint(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }

        $decoded = Json::decodeArray($trimmed, []);
        if (isset($decoded['blocks']) && is_array($decoded['blocks'])) {
            return (string) json_encode($decoded['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $trimmed;
    }

    private function getLatestFingerprint(int $postId): ?string
    {
        $row = $this->db->get_row(
            "SELECT id, fingerprint FROM {$this->table} WHERE post_id = ? ORDER BY id DESC LIMIT 1",
            [$postId]
        );

        if (!is_object($row)) {
            return null;
        }

        $fingerprint = trim((string) ($row->fingerprint ?? ''));
        if ($fingerprint !== '') {
            return $fingerprint;
        }

        // Ältere Zeilen besitzen noch keinen Fingerabdruck – einmalig berechnen und nachtragen.
        $revision = $this->getRevision($postId, (int) $row->id);
        if ($revision === null) {
            return null;
        }

        $fingerprint = $this->computeFingerprint($this->normalizeSnapshot($revision));
        try {
            $this->db->execute("UPDATE {$this->table} SET fingerprint = ? WHERE id = ?", [$fingerprint, (int) $row->id]);
        } catch (\Throwable) {
            // Nur Cache – Fehler sind unkritisch.
        }

        return $fingerprint;
    }

    private function hasRecentRevisionOfType(int $postId, string $type, int $seconds): bool
    {
        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE post_id = ? AND revision_type = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$postId, $type, max(1, $seconds)]
        ) > 0;
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function insertRevision(int $postId, string $type, ?int $userId, array $snapshot, string $fingerprint): int
    {
        try {
            $this->db->execute(
                "INSERT INTO {$this->table} (
                    post_id, revision_type, title, title_en, slug, slug_en, content, content_en, excerpt, excerpt_en,
                    status, category_id, category_name, tags, featured_image, meta_title, meta_description,
                    author_id, author_display_name, author_display_url, created_by, published_at, content_updated_at, fingerprint
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $postId,
                    $type,
                    $this->truncate((string) $snapshot['title'], 255),
                    $this->truncate((string) $snapshot['title_en'], 255),
                    $this->truncate((string) $snapshot['slug'], 255),
                    $this->truncate((string) $snapshot['slug_en'], 255),
                    (string) $snapshot['content'],
                    (string) $snapshot['content_en'],
                    (string) $snapshot['excerpt'],
                    (string) $snapshot['excerpt_en'],
                    $this->truncate((string) $snapshot['status'], 20),
                    (int) $snapshot['category_id'] > 0 ? (int) $snapshot['category_id'] : null,
                    $this->truncate((string) $snapshot['category_name'], 150),
                    $this->truncate((string) $snapshot['tags'], 500),
                    $this->truncate((string) $snapshot['featured_image'], 500),
                    $this->truncate((string) $snapshot['meta_title'], 255),
                    (string) $snapshot['meta_description'],
                    (int) $snapshot['author_id'] > 0 ? (int) $snapshot['author_id'] : null,
                    $this->truncate((string) $snapshot['author_display_name'], 150),
                    $this->truncate((string) $snapshot['author_display_url'], 500),
                    $userId !== null && $userId > 0 ? $userId : null,
                    $this->nullableString($snapshot['published_at'] ?? null),
                    $this->nullableString($snapshot['content_updated_at'] ?? null),
                    $fingerprint,
                ]
            );

            return (int) $this->db->lastInsertId();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.posts')->warning('Beitragsrevision konnte nicht gespeichert werden.', [
                'post_id' => $postId,
                'revision_type' => $type,
                'exception' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * @return list<string>
     */
    private function getPostTagNames(int $postId): array
    {
        try {
            $names = $this->db->get_col(
                "SELECT t.name
                 FROM {$this->prefix}post_tags t
                 INNER JOIN {$this->prefix}post_tag_rel ptr ON ptr.tag_id = t.id
                 WHERE ptr.post_id = ?
                 ORDER BY t.name ASC",
                [$postId]
            );
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(array_map(static fn(mixed $name): string => trim((string) $name), is_array($names) ? $names : []), static fn(string $name): bool => $name !== ''));
    }

    /**
     * @return list<string>
     */
    private function splitTagNames(string $value): array
    {
        $names = [];
        foreach (preg_split('/[\r\n,]+/', $value) ?: [] as $entry) {
            $name = trim((string) $entry);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolveAuthorLabel(array $row): string
    {
        foreach (['creator_display_name', 'creator_username'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        if ((int) ($row['created_by'] ?? 0) > 0) {
            return 'Benutzer #' . (int) $row['created_by'];
        }

        // Ältere Revisionen und Ausgangsstände kennen nur den Beitragsautor.
        foreach (['author_user_display_name', 'author_username'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return (int) ($row['author_id'] ?? 0) > 0 ? 'Benutzer #' . (int) $row['author_id'] : 'Unbekannt';
    }

    private function normalizeType(string $type): string
    {
        return array_key_exists($type, self::TYPE_LABELS) ? $type : self::TYPE_LEGACY;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' && !str_starts_with($value, '0000-00-00') ? $value : null;
    }

    private function truncate(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
