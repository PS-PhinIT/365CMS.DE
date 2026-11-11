<?php
/**
 * Gemeinsame Papierkorb-Logik für Inhalte mit Status-Spalte (Beiträge, Seiten).
 *
 * - In den Papierkorb: `status = 'trash'`, vorheriger Status in `previous_status`, `trashed_at`/`trashed_by`;
 *   der Slug wird freigegeben (Original in `trash_slug`/`trash_slug_en`, Platzhalter `__trashed-<id>`).
 * - Wiederherstellen: vorheriger Status (sonst Entwurf), Original-Slug bzw. erster freier Kandidat.
 * - Beide Vorgänge feuern den save-Hook des Typs, damit Suchindex, Sitemap, Feeds und IndexNow den
 *   Inhalt entfernen bzw. wieder aufnehmen; endgültiges Löschen feuert den `*_deleted`-Hook.
 * - `updated_at` bleibt bei Papierkorb und Wiederherstellen unverändert (der Inhalt selbst ändert sich nicht).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Trash;

use CMS\AuditLogger;
use CMS\Database;
use CMS\Hooks;
use CMS\Logger;
use CMS\Services\ContentTypes\TrashItem;
use CMS\Services\ContentTypes\TrashResult;

if (!defined('ABSPATH')) {
    exit;
}

abstract class AbstractContentTrashProvider implements TrashableProvider
{
    protected readonly Database $db;
    protected readonly string $prefix;

    private ?bool $slugParking = null;

    /** @var array<string, bool> */
    private array $tableExists = [];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    /** Tabellenname ohne Präfix (`posts`, `pages`). */
    abstract protected function table(): string;

    abstract protected function slugMaxLength(): int;

    /** @return list<string> Status, die beim Wiederherstellen übernommen werden dürfen */
    abstract protected function restorableStatuses(): array;

    abstract protected function saveHook(): string;

    abstract protected function deletedHook(): string;

    /** Audit-Schlüssel und Entitätstyp (`posts`, `pages`). */
    abstract protected function auditEntity(): string;

    abstract protected function manageUrl(int $id): string;

    /** Deutsches Substantiv für Audit-Einträge (Audit-Texte sind wie im übrigen Core deutsch). */
    abstract protected function auditNoun(): string;

    /**
     * Entfernt Revisionen und Nutzungsdaten vor dem Löschen der Zeile.
     *
     * @return array<string, int> Tabelle => Anzahl entfernter Zeilen
     */
    abstract protected function deleteDependentData(int $id): array;

    public function countTrashed(string $search = ''): int
    {
        [$where, $params] = $this->trashWhere($search);

        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}{$this->table()} t WHERE {$where}", $params);
    }

    public function listTrashed(int $offset, int $limit, string $search = ''): array
    {
        [$where, $params] = $this->trashWhere($search);
        $slugColumn = $this->supportsSlugParking() ? 'COALESCE(NULLIF(t.trash_slug, \'\'), t.slug)' : 't.slug';
        $params[] = max(1, min(500, $limit));
        $params[] = max(0, $offset);

        $rows = $this->db->get_results(
            "SELECT t.id, t.title, t.title_en, {$slugColumn} AS original_slug, t.previous_status, t.trashed_at, t.trashed_by,
                    u.display_name AS trashed_by_name, u.username AS trashed_by_username
             FROM {$this->prefix}{$this->table()} t
             LEFT JOIN {$this->prefix}users u ON u.id = t.trashed_by
             WHERE {$where}
             ORDER BY (t.trashed_at IS NULL) ASC, t.trashed_at DESC, t.id DESC
             LIMIT ? OFFSET ?",
            $params
        ) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            $title = trim((string) ($row->title ?? ''));
            if ($title === '') {
                $title = trim((string) ($row->title_en ?? ''));
            }

            $trashedByName = trim((string) ($row->trashed_by_name ?? ''));
            if ($trashedByName === '') {
                $trashedByName = trim((string) ($row->trashed_by_username ?? ''));
            }

            $items[] = new TrashItem(
                $this->type(),
                $id,
                $title !== '' ? $title : '#' . $id,
                TrashSupport::timestamp($row->trashed_at ?? null),
                ($row->trashed_by ?? null) !== null ? (int) $row->trashed_by : null,
                (string) ($row->previous_status ?? ''),
                $this->manageUrl($id),
                [
                    'slug' => (string) ($row->original_slug ?? ''),
                    'trashed_by_name' => $trashedByName,
                ]
            );
        }

        return $items;
    }

    public function isTrashed(int $id): bool
    {
        return $id > 0 && (string) ($this->db->get_var(
            "SELECT status FROM {$this->prefix}{$this->table()} WHERE id = ? LIMIT 1",
            [$id]
        ) ?? '') === 'trash';
    }

    public function trash(int $id, int $userId): TrashResult
    {
        $row = $this->fetchRow($id);
        if ($row === null) {
            return TrashResult::failure(TrashResult::CODE_NOT_FOUND, TrashSupport::t('trash.error.not_found'));
        }

        $currentStatus = $row['status'];
        if ($currentStatus === 'trash') {
            return TrashResult::failure(TrashResult::CODE_INVALID_STATE, TrashSupport::t('trash.error.already_trashed'));
        }

        $set = ['status = ?', 'previous_status = ?', 'trashed_at = ?', 'trashed_by = ?', 'updated_at = updated_at'];
        $params = ['trash', $currentStatus !== null && $currentStatus !== '' ? $currentStatus : 'draft', date('Y-m-d H:i:s'), $userId > 0 ? $userId : null];
        if ($this->supportsSlugParking()) {
            $set[] = 'trash_slug = ?';
            $params[] = $row['slug'];
            $set[] = 'trash_slug_en = ?';
            $params[] = $row['slug_en'] !== '' ? $row['slug_en'] : null;
            $set[] = 'slug = ?';
            $params[] = TrashSlug::parked($id);
            $set[] = 'slug_en = NULL';
        }
        $params[] = $id;
        $params[] = $currentStatus;

        try {
            $statement = $this->db->execute(
                "UPDATE {$this->prefix}{$this->table()} SET " . implode(', ', $set) . ' WHERE id = ? AND status <=> ? LIMIT 1',
                $params
            );
            if ($statement->rowCount() < 1) {
                return TrashResult::failure(TrashResult::CODE_INVALID_STATE, TrashSupport::t('trash.error.state_changed'));
            }
        } catch (\Throwable $e) {
            return $this->error('trash', $id, $e);
        }

        $this->afterVisibilityChange($id, 'trash');
        $this->audit('trash', sprintf('%s „%s“ in den Papierkorb verschoben.', $this->auditNoun(), $row['title']), $id, [
            'previous_status' => $currentStatus,
            'slug' => $row['slug'],
            'user_id' => $userId,
        ]);

        return TrashResult::success(TrashSupport::t('trash.msg.trashed', $row['title']), [
            'id' => $id,
            'previous_status' => (string) $currentStatus,
        ]);
    }

    public function restore(int $id, int $userId): TrashResult
    {
        $row = $this->fetchRow($id);
        if ($row === null) {
            return TrashResult::failure(TrashResult::CODE_NOT_FOUND, TrashSupport::t('trash.error.not_found'));
        }

        if ($row['status'] !== 'trash') {
            return TrashResult::failure(TrashResult::CODE_INVALID_STATE, TrashSupport::t('trash.error.not_in_trash'));
        }

        $status = in_array($row['previous_status'], $this->restorableStatuses(), true) ? $row['previous_status'] : 'draft';
        $originalSlug = $row['trash_slug'] !== '' ? $row['trash_slug'] : $row['slug'];
        $slug = TrashSlug::resolve(
            $originalSlug,
            fn (string $candidate): bool => $this->isSlugTaken($candidate, $id),
            $this->slugMaxLength(),
            (string) $id
        );

        $originalSlugEn = $this->supportsSlugParking() && $row['trash_slug'] !== '' ? $row['trash_slug_en'] : $row['slug_en'];
        $slugEn = null;
        if ($originalSlugEn !== '') {
            $slugEn = TrashSlug::resolve(
                $originalSlugEn,
                fn (string $candidate): bool => $candidate !== $slug && $this->isLocalizedSlugTaken($candidate, $id),
                $this->slugMaxLength(),
                (string) $id
            );
        }

        $set = ['status = ?', 'previous_status = NULL', 'trashed_at = NULL', 'trashed_by = NULL', 'slug = ?', 'slug_en = ?', 'updated_at = updated_at'];
        $params = [$status, $slug, $slugEn];
        if ($this->supportsSlugParking()) {
            $set[] = 'trash_slug = NULL';
            $set[] = 'trash_slug_en = NULL';
        }
        $params[] = $id;

        try {
            $statement = $this->db->execute(
                "UPDATE {$this->prefix}{$this->table()} SET " . implode(', ', $set) . " WHERE id = ? AND status = 'trash' LIMIT 1",
                $params
            );
            if ($statement->rowCount() < 1) {
                return TrashResult::failure(TrashResult::CODE_INVALID_STATE, TrashSupport::t('trash.error.state_changed'));
            }
        } catch (\Throwable $e) {
            return $this->error('restore', $id, $e);
        }

        $slugChanged = $slug !== $originalSlug || ($slugEn ?? '') !== $originalSlugEn;
        $this->afterVisibilityChange($id, 'restore');
        $this->audit('restore', sprintf('%s „%s“ aus dem Papierkorb wiederhergestellt.', $this->auditNoun(), $row['title']), $id, [
            'status' => $status,
            'slug' => $slug,
            'original_slug' => $originalSlug,
            'slug_en' => $slugEn,
            'slug_changed' => $slugChanged,
            'user_id' => $userId,
        ]);

        $message = TrashSupport::t('trash.msg.restored', $row['title']);
        if ($slugChanged) {
            $message .= ' ' . TrashSupport::t('trash.msg.slug_changed', $originalSlug !== '' ? $originalSlug : '—', $slug);
        }

        return TrashResult::success($message, [
            'id' => $id,
            'status' => $status,
            'slug' => $slug,
            'slug_en' => $slugEn,
            'slug_changed' => $slugChanged,
        ]);
    }

    public function deletionWarnings(int $id): array
    {
        return [];
    }

    public function deletePermanently(int $id, int $userId, bool $confirmed = false): TrashResult
    {
        $row = $this->fetchRow($id);
        if ($row === null) {
            return TrashResult::failure(TrashResult::CODE_NOT_FOUND, TrashSupport::t('trash.error.not_found'));
        }

        if ($row['status'] !== 'trash') {
            return TrashResult::failure(TrashResult::CODE_INVALID_STATE, TrashSupport::t('trash.error.delete_requires_trash'));
        }

        $warnings = $this->deletionWarnings($id);
        if ($warnings !== [] && !$confirmed) {
            return TrashSupport::confirmationRequired($warnings, $row['title']);
        }

        $pdo = $this->db->getPdo();
        $ownTransaction = !$pdo->inTransaction();
        try {
            if ($ownTransaction) {
                $pdo->beginTransaction();
            }

            $removed = $this->deleteDependentData($id);
            $statement = $this->db->execute(
                "DELETE FROM {$this->prefix}{$this->table()} WHERE id = ? AND status = 'trash' LIMIT 1",
                [$id]
            );
            if ($statement->rowCount() < 1) {
                if ($ownTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                return TrashResult::failure(TrashResult::CODE_INVALID_STATE, TrashSupport::t('trash.error.state_changed'));
            }

            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return $this->error('delete', $id, $e);
        }

        Hooks::doAction($this->deletedHook(), $id);
        TrashSupport::clearContentCache($this->db, $this->auditEntity() . '_trash_delete', $id);
        $this->audit('delete_permanently', sprintf('%s „%s“ endgültig gelöscht.', $this->auditNoun(), $row['title']), $id, [
            'slug' => $row['trash_slug'] !== '' ? $row['trash_slug'] : $row['slug'],
            'removed' => $removed,
            'warnings' => $warnings,
            'user_id' => $userId,
        ], $userId > 0 ? 'warning' : 'info');

        return TrashResult::success(TrashSupport::t('trash.msg.deleted', $row['title']), ['id' => $id, 'removed' => $removed]);
    }

    /**
     * Einträge ohne Zeitstempel (z. B. importierte Papierkorb-Inhalte) erhalten beim ersten Fristlauf den
     * aktuellen Zeitpunkt – die Frist beginnt damit erst, wenn der Papierkorb sie kennt.
     */
    public function expiredIds(\DateTimeImmutable $trashedBefore, int $limit): array
    {
        try {
            $this->db->execute(
                "UPDATE {$this->prefix}{$this->table()} SET trashed_at = ?, updated_at = updated_at WHERE status = 'trash' AND trashed_at IS NULL",
                [date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('trash')->warning('Papierkorb-Einträge ohne Zeitstempel konnten nicht markiert werden.', [
                'type' => $this->type(),
                'exception' => $e->getMessage(),
            ]);
        }

        $ids = $this->db->get_col(
            "SELECT id FROM {$this->prefix}{$this->table()} WHERE status = 'trash' AND trashed_at < ? ORDER BY trashed_at ASC, id ASC LIMIT ?",
            [$trashedBefore->format('Y-m-d H:i:s'), max(1, $limit)]
        );

        return array_values(array_map('intval', $ids));
    }

    /**
     * @return array{0:string,1:list<mixed>}
     */
    protected function trashWhere(string $search): array
    {
        $where = "t.status = 'trash'";
        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $like = TrashSupport::likePattern($search);
            $columns = ['t.title', 't.title_en', 't.slug'];
            if ($this->supportsSlugParking()) {
                $columns[] = 't.trash_slug';
            }
            $where .= ' AND (' . implode(' OR ', array_map(static fn (string $column): string => $column . ' LIKE ?', $columns)) . ')';
            $params = array_fill(0, count($columns), $like);
        }

        return [$where, $params];
    }

    /**
     * @return array{id:int,title:string,status:?string,previous_status:string,slug:string,slug_en:string,trash_slug:string,trash_slug_en:string}|null
     */
    protected function fetchRow(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $trashColumns = $this->supportsSlugParking() ? ', trash_slug, trash_slug_en' : '';
        $row = $this->db->get_row(
            "SELECT id, title, title_en, status, previous_status, slug, slug_en{$trashColumns} FROM {$this->prefix}{$this->table()} WHERE id = ? LIMIT 1",
            [$id]
        );
        if ($row === null) {
            return null;
        }

        $title = trim((string) ($row->title ?? ''));
        if ($title === '') {
            $title = trim((string) ($row->title_en ?? ''));
        }

        return [
            'id' => (int) $row->id,
            'title' => $title !== '' ? $title : '#' . (int) $row->id,
            'status' => ($row->status ?? null) !== null ? (string) $row->status : null,
            'previous_status' => (string) ($row->previous_status ?? ''),
            'slug' => (string) ($row->slug ?? ''),
            'slug_en' => (string) ($row->slug_en ?? ''),
            'trash_slug' => (string) ($row->trash_slug ?? ''),
            'trash_slug_en' => (string) ($row->trash_slug_en ?? ''),
        ];
    }

    protected function isSlugTaken(string $slug, int $ignoreId): bool
    {
        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}{$this->table()} WHERE slug = ? AND id <> ?",
            [$slug, $ignoreId]
        ) > 0;
    }

    protected function isLocalizedSlugTaken(string $slug, int $ignoreId): bool
    {
        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}{$this->table()} WHERE (slug_en = ? OR slug = ?) AND id <> ?",
            [$slug, $slug, $ignoreId]
        ) > 0;
    }

    /** Spalten `trash_slug`/`trash_slug_en` vorhanden? Ohne sie bleibt der Slug im Papierkorb belegt. */
    public function supportsSlugParking(): bool
    {
        if ($this->slugParking === null) {
            try {
                $this->slugParking = $this->db->columnExists($this->prefix . $this->table(), 'trash_slug')
                    && $this->db->columnExists($this->prefix . $this->table(), 'trash_slug_en');
            } catch (\Throwable) {
                $this->slugParking = false;
            }
        }

        return $this->slugParking;
    }

    protected function hasTable(string $table): bool
    {
        if (!array_key_exists($table, $this->tableExists)) {
            try {
                $this->tableExists[$table] = $this->db->tableExists($this->prefix . $table);
            } catch (\Throwable) {
                $this->tableExists[$table] = false;
            }
        }

        return $this->tableExists[$table];
    }

    /**
     * Löscht Zeilen einer abhängigen Tabelle, sofern vorhanden.
     *
     * @param list<mixed> $params
     */
    protected function deleteFrom(string $table, string $where, array $params): int
    {
        if (!$this->hasTable($table)) {
            return 0;
        }

        return $this->db->execute("DELETE FROM {$this->prefix}{$table} WHERE {$where}", $params)->rowCount();
    }

    /**
     * Inhalt wird unsichtbar bzw. wieder sichtbar: save-Hook (Suchindex, Sitemap, Feeds, IndexNow) und Inhalts-Cache.
     */
    protected function afterVisibilityChange(int $id, string $reason): void
    {
        try {
            Hooks::doAction($this->saveHook(), $id, ['trash_action' => $reason], []);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('trash')->warning('Folgeaktionen nach Papierkorb-Statuswechsel fehlgeschlagen.', [
                'type' => $this->type(),
                'id' => $id,
                'reason' => $reason,
                'exception' => $e->getMessage(),
            ]);
        }

        TrashSupport::clearContentCache($this->db, $this->auditEntity() . '_' . $reason, $id);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    protected function audit(string $action, string $description, int $id, array $metadata, string $severity = 'info'): void
    {
        AuditLogger::instance()->log(
            AuditLogger::CAT_CONTENT,
            $this->auditEntity() . '.' . $action,
            $description,
            $this->auditEntity(),
            $id,
            $metadata,
            $severity
        );
    }

    protected function error(string $action, int $id, \Throwable $e): TrashResult
    {
        Logger::instance()->withChannel('trash')->error('Papierkorb-Aktion fehlgeschlagen.', [
            'type' => $this->type(),
            'action' => $action,
            'id' => $id,
            'exception_class' => get_debug_type($e),
            'exception' => $e->getMessage(),
        ]);

        return TrashResult::failure(TrashResult::CODE_ERROR, TrashSupport::t('trash.error.failed'));
    }
}
