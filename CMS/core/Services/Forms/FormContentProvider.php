<?php
/**
 * Inhaltstyp-Provider „Formulare“ (`form`): Papierkorb und Export/Import der Formulardefinitionen.
 *
 * Exportiert werden nur Definitionen (Felder, Einstellungen) – keine Eingänge (Personendaten). Das eingebaute
 * Kontaktformular kann nicht in den Papierkorb und wird beim Import nur bei „überschreiben“ aktualisiert.
 *
 * @package CMSv2\Core\Services\Forms
 */

declare(strict_types=1);

namespace CMS\Services\Forms;

use CMS\Database;
use CMS\Services\ContentTypes\ExchangeFilter;
use CMS\Services\ContentTypes\ExchangeProvider;
use CMS\Services\ContentTypes\ExportBatch;
use CMS\Services\ContentTypes\ImportContext;
use CMS\Services\ContentTypes\ImportPreview;
use CMS\Services\ContentTypes\ImportResult;
use CMS\Services\ContentTypes\TrashItem;
use CMS\Services\ContentTypes\TrashProvider;
use CMS\Services\ContentTypes\TrashResult;

if (!defined('ABSPATH')) {
    exit;
}

final class FormContentProvider implements TrashProvider, ExchangeProvider
{
    public const TYPE = 'form';

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return __('forms.provider.forms');
    }

    public function capability(): string
    {
        return 'manage_forms';
    }

    // ── Papierkorb ───────────────────────────────────────────────────────────

    public function countTrashed(string $search = ''): int
    {
        return $this->available() ? FormService::getInstance()->count(['status' => FormService::STATUS_TRASH, 'search' => $search]) : 0;
    }

    public function listTrashed(int $offset, int $limit, string $search = ''): array
    {
        if (!$this->available()) {
            return [];
        }
        $db = Database::instance();
        $table = FormService::getInstance()->table();
        $params = [FormService::STATUS_TRASH];
        $where = 'status = ?';
        if (trim($search) !== '') {
            $like = '%' . addcslashes(trim($search), '%_\\') . '%';
            $where .= ' AND (title LIKE ? OR form_key LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $rows = $db->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY trashed_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}", $params) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $form = FormService::hydrate($row);
            $items[] = new TrashItem(
                self::TYPE,
                (int) $form['id'],
                (string) $form['title'],
                (string) ($form['trashed_at'] ?? ''),
                $form['trashed_by'] !== null ? (int) $form['trashed_by'] : null,
                (string) ($form['previous_status'] ?? ''),
                '/admin/forms?action=edit&id=' . (int) $form['id'],
                ['form_key' => (string) $form['form_key']]
            );
        }

        return $items;
    }

    public function restore(int $id, int $userId): TrashResult
    {
        if (!$this->available() || !FormService::getInstance()->restore($id)) {
            return TrashResult::failure(TrashResult::CODE_ERROR, __('forms.admin.error_not_found'));
        }

        return TrashResult::success(__('forms.admin.restored'));
    }

    public function deletionWarnings(int $id): array
    {
        if (!$this->available()) {
            return [];
        }
        $count = FormSubmissionService::getInstance()->count(['form_id' => $id, 'status' => 'any']);

        return $count > 0 ? [sprintf(__('forms.provider.warning_submissions'), $count)] : [];
    }

    public function deletePermanently(int $id, int $userId, bool $confirmed = false): TrashResult
    {
        $form = $this->available() ? FormService::getInstance()->find($id) : null;
        if ($form === null || $form['status'] !== FormService::STATUS_TRASH) {
            return TrashResult::failure(TrashResult::CODE_ERROR, __('forms.admin.error_not_found'));
        }
        $warnings = $this->deletionWarnings($id);
        if ($warnings !== [] && !$confirmed) {
            return TrashResult::confirmationRequired($warnings, __('forms.provider.confirm_delete'));
        }
        if (!FormService::getInstance()->deletePermanently($id)) {
            return TrashResult::failure(TrashResult::CODE_ERROR, __('forms.admin.error_save'));
        }

        return TrashResult::success(__('forms.admin.deleted'));
    }

    public function expiredIds(\DateTimeImmutable $trashedBefore, int $limit): array
    {
        if (!$this->available()) {
            return [];
        }
        $table = FormService::getInstance()->table();
        $limit = max(1, min(1000, $limit));

        return array_map('intval', Database::instance()->get_col(
            "SELECT id FROM {$table} WHERE status = ? AND trashed_at IS NOT NULL AND trashed_at < ? ORDER BY trashed_at ASC LIMIT {$limit}",
            [FormService::STATUS_TRASH, $trashedBefore->format('Y-m-d H:i:s')]
        ) ?: []);
    }

    // ── Export/Import ────────────────────────────────────────────────────────

    public function importPriority(): int
    {
        return 40;
    }

    public function countExport(ExchangeFilter $filter): int
    {
        return $this->available() ? count($this->exportIds($filter, 0, 100000)) : 0;
    }

    public function exportBatch(ExchangeFilter $filter, int $cursor, int $limit): ExportBatch
    {
        if (!$this->available()) {
            return new ExportBatch([], [], $cursor, true);
        }
        $limit = max(1, min(500, $limit));
        $ids = $this->exportIds($filter, $cursor, $limit + 1);
        $done = count($ids) <= $limit;
        $ids = array_slice($ids, 0, $limit);

        $records = [];
        $next = $cursor;
        foreach ($ids as $id) {
            $form = FormService::getInstance()->find($id);
            if ($form === null) {
                continue;
            }
            $next = $id;
            $records[] = [
                'id' => (int) $form['id'],
                'form_key' => (string) $form['form_key'],
                'title' => (string) $form['title'],
                'status' => (string) $form['status'],
                'is_builtin' => (bool) $form['is_builtin'],
                'fields' => $form['fields'],
                'settings' => $form['settings'],
                'created_at' => (string) $form['created_at'],
                'updated_at' => (string) $form['updated_at'],
            ];
        }

        return new ExportBatch($records, [], $next, $done);
    }

    public function preview(array $record, ImportContext $context): ImportPreview
    {
        $title = FormI18n::cleanText((string) ($record['title'] ?? ''), 255);
        $key = FormService::normalizeKey((string) ($record['form_key'] ?? ''));
        if ($title === '' || $key === '' || !is_array($record['fields'] ?? null)) {
            return new ImportPreview(ImportPreview::ACTION_INVALID, $title !== '' ? $title : $key, '', __('forms.provider.invalid_record'));
        }

        return $this->available() && FormService::getInstance()->keyExists($key)
            ? new ImportPreview(ImportPreview::ACTION_CONFLICT, $title, $key, __('forms.provider.key_exists'))
            : new ImportPreview(ImportPreview::ACTION_CREATE, $title, $key);
    }

    public function import(array $record, ImportContext $context): ImportResult
    {
        $preview = $this->preview($record, $context);
        if ($preview->action === ImportPreview::ACTION_INVALID || !$this->available()) {
            return new ImportResult(ImportResult::FAILED, null, $preview->message !== '' ? $preview->message : __('forms.admin.error_save'));
        }

        $service = FormService::getInstance();
        $key = FormService::normalizeKey((string) $record['form_key']);
        $status = (string) ($record['status'] ?? FormService::STATUS_ACTIVE);
        if ($context->importAsDraft || !in_array($status, FormService::STATUSES, true)) {
            $status = FormService::STATUS_INACTIVE;
        }
        $data = [
            'title' => (string) $record['title'],
            'fields' => $record['fields'],
            'settings' => is_array($record['settings'] ?? null) ? $record['settings'] : [],
            'status' => $status,
        ];

        if ($preview->action === ImportPreview::ACTION_CONFLICT) {
            $existing = $service->findByKey($key);
            if ($context->conflictStrategy === ImportContext::STRATEGY_SKIP || $existing === null) {
                if ($existing !== null) {
                    $context->map(self::TYPE, (int) ($record['id'] ?? 0), (int) $existing['id']);
                }

                return new ImportResult(ImportResult::SKIPPED, $existing !== null ? (int) $existing['id'] : null, __('forms.provider.key_exists'));
            }
            if ($context->conflictStrategy === ImportContext::STRATEGY_OVERWRITE) {
                if ($existing['status'] === FormService::STATUS_TRASH) {
                    $service->restore((int) $existing['id']);
                }
                $result = $service->update((int) $existing['id'], $data);
                if (empty($result['success'])) {
                    return new ImportResult(ImportResult::FAILED, null, (string) ($result['error'] ?? ''));
                }
                $context->map(self::TYPE, (int) ($record['id'] ?? 0), (int) $existing['id']);

                return new ImportResult(ImportResult::UPDATED, (int) $existing['id']);
            }
            $key = $service->uniqueKey($key);
        }

        $result = $service->create($data + ['form_key' => $key], $context->userId);
        if (empty($result['success'])) {
            return new ImportResult(ImportResult::FAILED, null, (string) ($result['error'] ?? ''));
        }
        $context->map(self::TYPE, (int) ($record['id'] ?? 0), (int) $result['id']);

        return new ImportResult($preview->action === ImportPreview::ACTION_CONFLICT ? ImportResult::RENAMED : ImportResult::CREATED, (int) $result['id']);
    }

    /**
     * @return list<int>
     */
    private function exportIds(ExchangeFilter $filter, int $afterId, int $limit): array
    {
        $table = FormService::getInstance()->table();
        $where = ['id > ?', 'status <> ?'];
        $params = [$afterId, FormService::STATUS_TRASH];
        $statuses = array_values(array_intersect($filter->statuses, FormService::STATUSES));
        if ($statuses !== []) {
            $where[] = 'status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($params, ...$statuses);
        }
        if ($filter->dateFrom !== '') {
            $where[] = 'updated_at >= ?';
            $params[] = $filter->dateFrom . (strlen($filter->dateFrom) === 10 ? ' 00:00:00' : '');
        }
        if ($filter->dateTo !== '') {
            $where[] = 'updated_at <= ?';
            $params[] = $filter->dateTo . (strlen($filter->dateTo) === 10 ? ' 23:59:59' : '');
        }
        $limit = max(1, $limit);

        return array_map('intval', Database::instance()->get_col(
            "SELECT id FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY id ASC LIMIT {$limit}",
            $params
        ) ?: []);
    }

    private function available(): bool
    {
        return FormService::getInstance()->isAvailable();
    }
}
