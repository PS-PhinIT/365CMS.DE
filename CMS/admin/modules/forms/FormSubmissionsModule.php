<?php
declare(strict_types=1);

/**
 * Formulareingänge – Admin-Logik für Übersicht, Detailansicht, Status, Papierkorb, CSV-Export,
 * Datei-Abruf und DSGVO-Exportdateien.
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Services\Forms\FormI18n;
use CMS\Services\Forms\FormPrivacyService;
use CMS\Services\Forms\FormService;
use CMS\Services\Forms\FormSubmissionService;
use CMS\Services\Forms\FormUploadService;

class FormSubmissionsModule
{
    public const TABS = ['inbox', 'privacy'];
    public const STATUS_FILTERS = ['all', 'new', 'read', 'done', 'trash'];
    public const BULK_ACTIONS = ['mark_new', 'mark_read', 'mark_done', 'trash', 'restore', 'delete'];
    public const ACTIONS = ['bulk', 'set_status', 'trash', 'restore', 'delete', 'empty_trash', 'delete_export'];
    public const PER_PAGE = 25;
    public const MAX_BULK = 200;

    private FormService $forms;
    private FormSubmissionService $submissions;

    public function __construct()
    {
        $this->forms = FormService::getInstance();
        $this->submissions = FormSubmissionService::getInstance();
    }

    public function isAvailable(): bool
    {
        return $this->forms->isAvailable();
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function url(array $query = []): string
    {
        $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== 0 && $value !== 'all');

        return '/admin/form-submissions' . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /**
     * Filter aus der Anfrage (GET oder versteckte Felder eines POST).
     *
     * @param array<string, mixed> $source
     * @return array{form: int, status: string, q: string, page: int}
     */
    public static function filters(array $source): array
    {
        $status = strtolower(trim(is_scalar($source['status'] ?? null) ? (string) $source['status'] : ''));

        return [
            'form' => max(0, (int) ($source['form'] ?? 0)),
            'status' => in_array($status, self::STATUS_FILTERS, true) ? $status : 'all',
            'q' => FormI18n::cleanText(is_scalar($source['q'] ?? null) ? (string) $source['q'] : '', 100),
            'page' => max(1, (int) ($source['page'] ?? 1)),
        ];
    }

    /**
     * @param array{form: int, status: string, q: string, page: int} $filters
     * @return array<string, mixed>
     */
    private static function serviceFilter(array $filters): array
    {
        $filter = ['search' => $filters['q']];
        if ($filters['form'] > 0) {
            $filter['form_id'] = $filters['form'];
        }
        if ($filters['status'] !== 'all') {
            $filter['status'] = $filters['status'];
        }

        return $filter;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function getInboxData(array $query, bool $canManage): array
    {
        $filters = self::filters($query);
        $available = $this->isAvailable();
        $base = ['tab' => 'inbox', 'view' => 'list', 'available' => $available, 'filters' => $filters, 'can_manage' => $canManage];
        if (!$available) {
            return $base + ['items' => [], 'total' => 0, 'pages' => 1, 'counts' => [], 'forms' => []];
        }

        $serviceFilter = self::serviceFilter($filters);
        $total = $this->submissions->count($serviceFilter);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $filters['page'] = min($filters['page'], $pages);
        $items = $this->submissions->list($serviceFilter, ($filters['page'] - 1) * self::PER_PAGE, self::PER_PAGE);

        $counts = $this->submissions->countByStatus($filters['form']);
        $counts['all'] = (int) $counts['new'] + (int) $counts['read'] + (int) $counts['done'];

        return [
            'filters' => $filters,
            'items' => $items,
            'total' => $total,
            'pages' => $pages,
            'counts' => $counts,
            'forms' => $this->forms->choicesById(),
            'privacy_exports' => count(FormPrivacyService::listExports()),
        ] + $base;
    }

    /**
     * Detailansicht; ein neuer Eingang gilt mit dem Öffnen als gelesen.
     *
     * @return array<string, mixed>|null
     */
    public function getDetailData(int $id, bool $canManage): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $submission = $this->submissions->find($id);
        if ($submission === null) {
            return null;
        }
        if ($submission['status'] === FormSubmissionService::STATUS_NEW) {
            $this->submissions->markRead($id);
            $submission = $this->submissions->find($id) ?? $submission;
        }

        $user = null;
        if (!empty($submission['user_id'])) {
            $row = \CMS\Database::instance()->get_row(
                'SELECT id, username, display_name FROM ' . \CMS\Database::instance()->getPrefix() . 'users WHERE id = ? LIMIT 1',
                [(int) $submission['user_id']]
            );
            if ($row) {
                $user = ['id' => (int) $row->id, 'name' => (string) (($row->display_name ?? '') !== '' ? $row->display_name : $row->username)];
            }
        }

        return [
            'tab' => 'inbox',
            'view' => 'detail',
            'available' => true,
            'can_manage' => $canManage,
            'submission' => $submission,
            'user' => $user,
            'form_exists' => $this->forms->find((int) $submission['form_id']) !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPrivacyData(bool $canManage): array
    {
        return [
            'tab' => 'privacy',
            'view' => 'privacy',
            'available' => $this->isAvailable(),
            'can_manage' => $canManage,
            'exports' => FormPrivacyService::listExports(),
            'keep_days' => FormPrivacyService::EXPORT_KEEP_DAYS,
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function handlePost(array $post, int $userId, bool $canManage): array
    {
        $action = strtolower(trim(is_scalar($post['action'] ?? null) ? (string) $post['action'] : ''));
        if (!in_array($action, self::ACTIONS, true)) {
            return ['success' => false, 'error' => __('forms.inbox.error_unknown_action')];
        }
        if (!$this->isAvailable()) {
            return ['success' => false, 'error' => __('forms.admin.unavailable')];
        }

        $filters = self::filters(is_array($post['filter'] ?? null) ? $post['filter'] : []);
        $listUrl = self::url(['form' => $filters['form'], 'status' => $filters['status'], 'q' => $filters['q'], 'page' => $filters['page'] > 1 ? $filters['page'] : null]);

        if ($action === 'delete_export') {
            if (!$canManage) {
                return ['success' => false, 'error' => __('forms.inbox.error_manage_required'), '__redirect' => self::url(['tab' => 'privacy'])];
            }
            $name = is_scalar($post['name'] ?? null) ? (string) $post['name'] : '';
            $ok = FormPrivacyService::deleteExport($name);
            if ($ok) {
                AuditLogger::instance()->log(AuditLogger::CAT_SETTING, 'forms.privacy.export_delete', 'DSGVO-Exportdatei der Formulareingänge gelöscht', 'form_submission', null, ['file' => $name], 'warning');
            }

            return [
                'success' => $ok,
                'message' => __('forms.privacy.export_deleted'),
                'error' => __('forms.privacy.export_not_found'),
                '__redirect' => self::url(['tab' => 'privacy']),
            ];
        }

        if ($action === 'empty_trash') {
            if (!$canManage) {
                return ['success' => false, 'error' => __('forms.inbox.error_manage_required'), '__redirect' => $listUrl];
            }
            $deleted = 0;
            do {
                $batch = $this->submissions->list(['status' => FormSubmissionService::STATUS_TRASH] + ($filters['form'] > 0 ? ['form_id' => $filters['form']] : []), 0, 200);
                $count = $this->submissions->deleteMany(array_map(static fn (array $row): int => (int) $row['id'], $batch));
                $deleted += $count;
            } while ($batch !== [] && $count > 0);
            $this->audit('forms.submissions.empty_trash', 'Papierkorb der Formulareingänge geleert', ['count' => $deleted], 'warning');

            return ['success' => true, 'message' => sprintf(__('forms.inbox.deleted_count'), $deleted), '__redirect' => $listUrl];
        }

        if ($action === 'bulk') {
            $bulk = strtolower(trim(is_scalar($post['bulk_action'] ?? null) ? (string) $post['bulk_action'] : ''));
            $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', is_array($post['ids'] ?? null) ? $post['ids'] : [])))), 0, self::MAX_BULK);
            if (!in_array($bulk, self::BULK_ACTIONS, true)) {
                return ['success' => false, 'error' => __('forms.inbox.error_bulk_action'), '__redirect' => $listUrl];
            }
            if ($ids === []) {
                return ['success' => false, 'error' => __('forms.inbox.error_bulk_empty'), '__redirect' => $listUrl];
            }
            if ($bulk === 'delete' && !$canManage) {
                return ['success' => false, 'error' => __('forms.inbox.error_manage_required'), '__redirect' => $listUrl];
            }
            $done = 0;
            foreach ($ids as $id) {
                $done += $this->apply($bulk, $id, $userId) ? 1 : 0;
            }
            $this->audit('forms.submissions.bulk', 'Sammelaktion für Formulareingänge', ['action' => $bulk, 'count' => $done], in_array($bulk, ['trash', 'delete'], true) ? 'warning' : 'info');

            return ['success' => true, 'message' => sprintf(__('forms.inbox.bulk_done'), $done), '__redirect' => $listUrl];
        }

        $id = (int) ($post['id'] ?? 0);
        $returnToDetail = !empty($post['return_detail']);
        $detailUrl = self::url(['view' => $id]);
        if ($this->submissions->find($id) === null) {
            return ['success' => false, 'error' => __('forms.admin.submission_not_found'), '__redirect' => $listUrl];
        }

        $operation = $action;
        if ($action === 'set_status') {
            $status = strtolower(trim(is_scalar($post['status'] ?? null) ? (string) $post['status'] : ''));
            if (!in_array($status, FormSubmissionService::STATUSES, true)) {
                return ['success' => false, 'error' => __('forms.inbox.error_unknown_action'), '__redirect' => $detailUrl];
            }
            $operation = 'mark_' . $status;
        }
        if ($operation === 'delete' && !$canManage) {
            return ['success' => false, 'error' => __('forms.inbox.error_manage_required'), '__redirect' => $detailUrl];
        }

        if (!$this->apply($operation, $id, $userId)) {
            return ['success' => false, 'error' => __('forms.admin.error_save'), '__redirect' => $returnToDetail ? $detailUrl : $listUrl];
        }
        if (in_array($operation, ['trash', 'restore', 'delete'], true)) {
            $this->audit('forms.submissions.' . $operation, 'Formulareingang: ' . $operation, ['id' => $id], $operation === 'restore' ? 'info' : 'warning');
        }

        $message = match ($operation) {
            'trash' => __('forms.inbox.trashed'),
            'restore' => __('forms.admin.restored'),
            'delete' => __('forms.admin.deleted'),
            default => __('forms.inbox.status_changed'),
        };

        return [
            'success' => true,
            'message' => $message,
            '__redirect' => $returnToDetail && !in_array($operation, ['trash', 'delete'], true) ? $detailUrl : $listUrl,
        ];
    }

    private function apply(string $operation, int $id, int $userId): bool
    {
        return match ($operation) {
            'mark_new' => $this->submissions->setStatus($id, FormSubmissionService::STATUS_NEW) || $this->hasStatus($id, FormSubmissionService::STATUS_NEW),
            'mark_read' => $this->submissions->setStatus($id, FormSubmissionService::STATUS_READ) || $this->hasStatus($id, FormSubmissionService::STATUS_READ),
            'mark_done' => $this->submissions->setStatus($id, FormSubmissionService::STATUS_DONE) || $this->hasStatus($id, FormSubmissionService::STATUS_DONE),
            'trash' => $this->submissions->trash($id, $userId),
            'restore' => $this->submissions->restore($id),
            'delete' => $this->hasStatus($id, FormSubmissionService::STATUS_TRASH) && $this->submissions->deletePermanently($id),
            default => false,
        };
    }

    private function hasStatus(int $id, string $status): bool
    {
        $submission = $this->submissions->find($id);

        return $submission !== null && $submission['status'] === $status;
    }

    /**
     * CSV-Export der gefilterten Eingänge (UTF-8 mit BOM, Semikolon, Schutz vor Formel-Injektion).
     *
     * @param array<string, mixed> $query
     */
    public function streamCsv(array $query): never
    {
        $filters = self::filters($query);
        $export = $this->submissions->csvExport(self::serviceFilter($filters));
        $form = $filters['form'] > 0 ? $this->forms->find($filters['form']) : null;
        $name = 'formulareingaenge' . ($form !== null ? '-' . $form['form_key'] : '') . '-' . date('Ymd-His') . '.csv';

        $this->audit('forms.submissions.export', 'Formulareingänge als CSV exportiert', ['form_id' => $filters['form'], 'status' => $filters['status']]);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'wb');
        if ($out !== false) {
            fwrite($out, "\xEF\xBB\xBF");
            $safe = static fn (string $value): string => $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
            fputcsv($out, $export['columns'], ';', '"', '');
            foreach ($export['rows'] as $row) {
                $line = [];
                foreach ($export['columns'] as $column) {
                    $line[] = $safe((string) ($row[$column] ?? ''));
                }
                fputcsv($out, $line, ';', '"', '');
            }
            fclose($out);
        }
        exit;
    }

    /**
     * Hochgeladene Datei eines Eingangs ausliefern (nur aus dem privaten Speicher, als Anhang).
     */
    public function streamFile(int $fileId): void
    {
        $file = $this->isAvailable() ? $this->submissions->findFile($fileId) : null;
        if ($file === null || FormUploadService::getInstance()->path($file) === null) {
            return;
        }
        $this->audit('forms.submissions.file', 'Datei eines Formulareingangs abgerufen', ['file_id' => $fileId, 'submission_id' => (int) $file['submission_id']]);
        FormUploadService::getInstance()->stream($file);
    }

    /**
     * DSGVO-Exportdatei ausliefern.
     */
    public function streamPrivacyExport(string $name): void
    {
        $path = FormPrivacyService::exportPath($name);
        if ($path === null || !is_file($path)) {
            return;
        }
        $this->audit('forms.privacy.export_download', 'DSGVO-Exportdatei der Formulareingänge abgerufen', ['file' => basename($path)]);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(string $action, string $description, array $context = [], string $severity = 'info'): void
    {
        AuditLogger::instance()->log(AuditLogger::CAT_CONTENT, $action, $description, 'form_submission', isset($context['id']) ? (int) $context['id'] : null, $context, $severity);
    }
}
