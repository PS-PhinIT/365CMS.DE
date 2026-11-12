<?php
/**
 * Übersetzungsstatus von Beiträgen und Seiten (Inhalte → Übersetzungen).
 *
 * Quelle ist die deutsche Fassung (`title`, `content`, `excerpt`), Ziel die Spalten der Inhaltssprache
 * (`title_en`, `content_en`, `excerpt_en`). Der Status je Inhalt und Sprache:
 * - `missing`  – keine Übersetzung (Titel und Inhalt leer),
 * - `current`  – der Fingerabdruck der Quelle entspricht dem Stand beim letzten Abgleich,
 * - `outdated` – die Quelle wurde seitdem geändert.
 *
 * Abgleich (`translation_sync`): beim Speichern, wenn sich die Übersetzung geändert hat (jemand hat sie
 * bearbeitet), bei „DE → EN kopieren“, bei „Als aktuell markieren“ und – einmalig – für bereits vorhandene
 * Übersetzungen ohne Eintrag (gelten ab Einführung als aktuell). Keine Site-Dimension, Hub-Sites nicht enthalten.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\AuditLogger;
use CMS\Database;
use CMS\Hooks;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class TranslationStatusService
{
    public const TYPES = ['post' => 'posts', 'page' => 'pages'];
    public const STATUSES = ['missing', 'current', 'outdated'];
    public const PER_PAGE = 50;

    /** Inhaltssprachen mit eigenen Spalten in posts/pages. */
    public const COLUMN_LOCALES = ['en'];

    private static ?self $instance = null;
    private Database $db;
    private string $prefix;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    public function isAvailable(): bool
    {
        try {
            return $this->db->tableExists($this->prefix . 'translation_sync');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Sprachen der Matrix: konfigurierte Inhaltssprachen, für die Spalten existieren. */
    public function locales(): array
    {
        try {
            $configured = ContentLocalizationService::getInstance()->getContentLocales();
        } catch (\Throwable) {
            $configured = ['en'];
        }

        return array_values(array_intersect(self::COLUMN_LOCALES, $configured)) ?: ['en'];
    }

    /** Hooks: Abgleich nach dem Speichern und nach „DE → EN kopieren“. */
    public static function register(): void
    {
        foreach (['cms_after_post_save' => 'post', 'cms_after_page_save' => 'page'] as $hook => $type) {
            Hooks::addAction($hook, static function (mixed $id = 0) use ($type): void {
                self::getInstance()->syncIfTranslationChanged($type, (int) $id);
            }, 80);
        }
        foreach (['cms_after_post_copy_de_to_en' => 'post', 'cms_after_page_copy_de_to_en' => 'page'] as $hook => $type) {
            Hooks::addAction($hook, static function (mixed $id = 0, mixed $payload = [], mixed $userId = 0) use ($type): void {
                self::getInstance()->markCurrent($type, (int) $id, 'en', (int) $userId, 'copy');
            }, 80);
        }
    }

    /**
     * @param array{types?:list<string>,type?:string,locale?:string,status?:string,search?:string} $filters
     * @return array{items:list<array<string,mixed>>,counts:array<string,int>,total:int,page:int,pages:int}
     */
    public function matrix(array $filters, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $types = array_values(array_intersect(array_keys(self::TYPES), $filters['types'] ?? array_keys(self::TYPES)));
        if (!empty($filters['type']) && in_array($filters['type'], $types, true)) {
            $types = [(string) $filters['type']];
        }
        $locales = $this->locales();
        $empty = ['items' => [], 'counts' => array_fill_keys(self::STATUSES, 0), 'total' => 0, 'page' => 1, 'pages' => 1];
        if ($types === [] || !$this->isAvailable()) {
            return $empty;
        }

        $rows = [];
        foreach ($types as $type) {
            $rows = array_merge($rows, $this->loadRows($type, (string) ($filters['search'] ?? '')));
        }

        $this->baselineUntracked($rows, $locales);
        $sync = $this->syncRecords($rows);

        $items = [];
        $counts = array_fill_keys(self::STATUSES, 0);
        $statusFilter = in_array($filters['status'] ?? '', self::STATUSES, true) ? (string) $filters['status'] : '';
        $localeFilter = in_array($filters['locale'] ?? '', $locales, true) ? (string) $filters['locale'] : '';
        foreach ($rows as $row) {
            $cells = [];
            $matches = $statusFilter === '';
            foreach ($locales as $locale) {
                $record = $sync[$row['type'] . ':' . $row['id'] . ':' . $locale] ?? null;
                $status = $this->statusFor($row, $locale, $record);
                $cells[$locale] = [
                    'status' => $status,
                    'synced_at' => $record['synced_at'] ?? '',
                    'method' => $record['method'] ?? '',
                ];
                if ($localeFilter === '' || $localeFilter === $locale) {
                    $counts[$status]++;
                    if ($statusFilter === $status) {
                        $matches = true;
                    }
                }
            }
            if ($matches) {
                $items[] = [
                    'type' => $row['type'],
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'status' => $row['status'],
                    'updated_at' => $row['updated_at'],
                    'cells' => $cells,
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => strcmp((string) $b['updated_at'], (string) $a['updated_at']));
        $total = count($items);
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = max(1, min($pages, $page));

        return [
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'counts' => $counts,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /** Status eines einzelnen Inhalts (für Editor und Tests). */
    public function statusOf(string $type, int $id, string $locale = 'en'): string
    {
        $row = $this->loadRow($type, $id);
        if ($row === null) {
            return 'missing';
        }
        $record = $this->syncRecords([$row])[$type . ':' . $id . ':' . $locale] ?? null;

        return $this->statusFor($row, $locale, $record);
    }

    /** Übersetzung als aktuell markieren (Quelle und Übersetzung neu abgleichen). */
    public function markCurrent(string $type, int $id, string $locale, int $userId, string $method = 'manual'): bool
    {
        $row = $this->loadRow($type, $id);
        if ($row === null || !in_array($locale, self::COLUMN_LOCALES, true) || !$this->hasTranslation($row, $locale)) {
            return false;
        }
        $ok = $this->store($type, $id, $locale, $row, $userId, $method);
        if ($ok && $method === 'manual') {
            try {
                AuditLogger::instance()->log(AuditLogger::CAT_CONTENT, 'translations.marked_current', sprintf('Übersetzung (%s) als aktuell markiert.', strtoupper($locale)), $type === 'post' ? 'posts' : 'pages', $id, ['user_id' => $userId]);
            } catch (\Throwable) {
            }
        }

        return $ok;
    }

    /**
     * Nach dem Speichern: Hat sich die Übersetzung gegenüber dem letzten Abgleich geändert, wurde sie bearbeitet und
     * gilt als aktuell. Ändert sich nur die Quelle, bleibt der Eintrag – die Übersetzung wird damit „veraltet“.
     */
    public function syncIfTranslationChanged(string $type, int $id): void
    {
        if ($id <= 0 || !isset(self::TYPES[$type]) || !$this->isAvailable()) {
            return;
        }
        try {
            $row = $this->loadRow($type, $id);
            if ($row === null) {
                return;
            }
            $records = $this->syncRecords([$row]);
            foreach (self::COLUMN_LOCALES as $locale) {
                if (!$this->hasTranslation($row, $locale)) {
                    continue;
                }
                $record = $records[$type . ':' . $id . ':' . $locale] ?? null;
                if ($record === null || $record['target_hash'] !== self::targetHash($row, $locale)) {
                    $this->store($type, $id, $locale, $row, (int) (\CMS\Auth::instance()->currentUser()->id ?? 0), 'edit');
                }
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('translations')->warning('Übersetzungsstatus konnte nicht abgeglichen werden.', ['type' => $type, 'id' => $id, 'exception' => $e->getMessage()]);
        }
    }

    // ── Intern ──────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function loadRows(string $type, string $search): array
    {
        $table = $this->prefix . self::TYPES[$type];
        $excerpt = $type === 'post' ? 'excerpt, excerpt_en' : "excerpt, '' AS excerpt_en";
        $sql = "SELECT id, title, content, {$excerpt}, title_en, content_en, status, updated_at
                FROM {$table}
                WHERE trashed_at IS NULL";
        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $sql .= ' AND (title LIKE ? OR title_en LIKE ?)';
            $like = '%' . addcslashes(mb_substr($search, 0, 100), '\\%_') . '%';
            $params = [$like, $like];
        }
        $sql .= ' ORDER BY updated_at DESC LIMIT 2000';

        return array_map(static fn (array $row): array => ['type' => $type, 'id' => (int) $row['id']] + $row, $this->db->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    private function loadRow(string $type, int $id): ?array
    {
        if (!isset(self::TYPES[$type]) || $id <= 0) {
            return null;
        }
        $table = $this->prefix . self::TYPES[$type];
        $excerpt = $type === 'post' ? 'excerpt, excerpt_en' : "excerpt, '' AS excerpt_en";
        $row = $this->db->execute("SELECT id, title, content, {$excerpt}, title_en, content_en, status, updated_at FROM {$table} WHERE id = ?", [$id])->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? ['type' => $type, 'id' => (int) $row['id']] + $row : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function syncRecords(array $rows): array
    {
        $records = [];
        foreach (array_chunk($rows, 500) as $chunk) {
            $conditions = [];
            $params = [];
            foreach ($chunk as $row) {
                $conditions[] = '(content_type = ? AND content_id = ?)';
                $params[] = $row['type'];
                $params[] = $row['id'];
            }
            if ($conditions === []) {
                continue;
            }
            foreach ($this->db->execute("SELECT * FROM {$this->prefix}translation_sync WHERE " . implode(' OR ', $conditions), $params)->fetchAll(\PDO::FETCH_ASSOC) as $record) {
                $records[$record['content_type'] . ':' . (int) $record['content_id'] . ':' . $record['locale']] = $record;
            }
        }

        return $records;
    }

    /**
     * Vorhandene Übersetzungen ohne Abgleich gelten ab Einführung des Übersetzungsstatus als aktuell.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $locales
     */
    private function baselineUntracked(array $rows, array $locales): void
    {
        $records = $this->syncRecords($rows);
        foreach ($rows as $row) {
            foreach ($locales as $locale) {
                if ($this->hasTranslation($row, $locale) && !isset($records[$row['type'] . ':' . $row['id'] . ':' . $locale])) {
                    $this->store((string) $row['type'], (int) $row['id'], $locale, $row, null, 'baseline');
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $record
     */
    private function statusFor(array $row, string $locale, ?array $record): string
    {
        if (!$this->hasTranslation($row, $locale)) {
            return 'missing';
        }
        if ($record === null) {
            return 'current';
        }

        return hash_equals((string) $record['source_hash'], self::sourceHash($row)) ? 'current' : 'outdated';
    }

    /** @param array<string, mixed> $row */
    private function hasTranslation(array $row, string $locale): bool
    {
        if ($locale !== 'en') {
            return false;
        }

        return trim((string) ($row['title_en'] ?? '')) !== '' || self::hasBody((string) ($row['content_en'] ?? ''));
    }

    private static function hasBody(string $content): bool
    {
        $content = trim($content);
        if ($content === '') {
            return false;
        }
        $decoded = json_decode($content, true);

        return !is_array($decoded) || !empty($decoded['blocks']);
    }

    /** @param array<string, mixed> $row */
    public static function sourceHash(array $row): string
    {
        return sha1(trim((string) ($row['title'] ?? '')) . "\0" . self::normalizeContent((string) ($row['content'] ?? '')) . "\0" . trim((string) ($row['excerpt'] ?? '')));
    }

    /** @param array<string, mixed> $row */
    public static function targetHash(array $row, string $locale = 'en'): string
    {
        return sha1(trim((string) ($row['title_' . $locale] ?? '')) . "\0" . self::normalizeContent((string) ($row['content_' . $locale] ?? '')) . "\0" . trim((string) ($row['excerpt_' . $locale] ?? '')));
    }

    /** Editor.js-JSON ohne Zeitstempel/Versionsangabe vergleichen (nur die Blöcke zählen). */
    private static function normalizeContent(string $content): string
    {
        $decoded = json_decode($content, true);
        if (is_array($decoded) && isset($decoded['blocks']) && is_array($decoded['blocks'])) {
            $blocks = array_map(static function (mixed $block): mixed {
                if (is_array($block)) {
                    unset($block['id']);
                }

                return $block;
            }, $decoded['blocks']);

            return (string) json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return trim($content);
    }

    /** @param array<string, mixed> $row */
    private function store(string $type, int $id, string $locale, array $row, ?int $userId, string $method): bool
    {
        try {
            $this->db->execute(
                "INSERT INTO {$this->prefix}translation_sync (content_type, content_id, locale, source_hash, target_hash, method, synced_by, synced_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE source_hash = VALUES(source_hash), target_hash = VALUES(target_hash), method = VALUES(method),
                    synced_by = VALUES(synced_by), synced_at = VALUES(synced_at)",
                [$type, $id, $locale, self::sourceHash($row), self::targetHash($row, $locale), $method, $userId !== null && $userId > 0 ? $userId : null, date('Y-m-d H:i:s')]
            );

            return true;
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('translations')->warning('Übersetzungsabgleich konnte nicht gespeichert werden.', ['exception' => $e->getMessage()]);

            return false;
        }
    }
}
