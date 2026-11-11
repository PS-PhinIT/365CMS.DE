<?php
/**
 * Inhaltstyp-Provider „Formulareingänge“ (`form_submission`): nur Papierkorb.
 *
 * Kein Export/Import, weil Eingänge Personendaten enthalten (Auskunft läuft über die DSGVO-Anfragen).
 *
 * @package CMSv2\Core\Services\Forms
 */

declare(strict_types=1);

namespace CMS\Services\Forms;

use CMS\Database;
use CMS\Services\ContentTypes\TrashItem;
use CMS\Services\ContentTypes\TrashProvider;
use CMS\Services\ContentTypes\TrashResult;

if (!defined('ABSPATH')) {
    exit;
}

final class FormSubmissionContentProvider implements TrashProvider
{
    public const TYPE = 'form_submission';

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return __('forms.provider.submissions');
    }

    public function capability(): string
    {
        return 'view_form_submissions';
    }

    public function countTrashed(string $search = ''): int
    {
        return $this->available()
            ? FormSubmissionService::getInstance()->count(['status' => FormSubmissionService::STATUS_TRASH, 'search' => $search])
            : 0;
    }

    public function listTrashed(int $offset, int $limit, string $search = ''): array
    {
        if (!$this->available()) {
            return [];
        }

        $items = [];
        $rows = FormSubmissionService::getInstance()->list(
            ['status' => FormSubmissionService::STATUS_TRASH, 'search' => $search, 'order' => 'trashed'],
            $offset,
            $limit
        );
        foreach ($rows as $submission) {
            $title = trim($submission['subject'] !== '' ? $submission['subject'] : $submission['sender_name']);
            if ($title === '') {
                $title = '#' . $submission['id'];
            }
            $items[] = new TrashItem(
                self::TYPE,
                (int) $submission['id'],
                $title . ($submission['form_title'] !== '' ? ' (' . $submission['form_title'] . ')' : ''),
                (string) ($submission['trashed_at'] ?? ''),
                $submission['trashed_by'],
                (string) ($submission['previous_status'] ?? ''),
                '/admin/form-submissions?view=detail&id=' . (int) $submission['id'],
                ['form_id' => (int) $submission['form_id'], 'created_at' => (string) $submission['created_at']]
            );
        }

        return $items;
    }

    public function restore(int $id, int $userId): TrashResult
    {
        if (!$this->available() || !FormSubmissionService::getInstance()->restore($id)) {
            return TrashResult::failure(TrashResult::CODE_ERROR, __('forms.admin.submission_not_found'));
        }

        return TrashResult::success(__('forms.admin.restored'));
    }

    public function deletionWarnings(int $id): array
    {
        return [];
    }

    public function deletePermanently(int $id, int $userId, bool $confirmed = false): TrashResult
    {
        $service = FormSubmissionService::getInstance();
        $submission = $this->available() ? $service->find($id) : null;
        if ($submission === null || $submission['status'] !== FormSubmissionService::STATUS_TRASH) {
            return TrashResult::failure(TrashResult::CODE_ERROR, __('forms.admin.submission_not_found'));
        }

        return $service->deletePermanently($id)
            ? TrashResult::success(__('forms.admin.deleted'))
            : TrashResult::failure(TrashResult::CODE_ERROR, __('forms.admin.error_save'));
    }

    public function expiredIds(\DateTimeImmutable $trashedBefore, int $limit): array
    {
        if (!$this->available()) {
            return [];
        }
        $table = FormSubmissionService::getInstance()->table();
        $limit = max(1, min(1000, $limit));

        return array_map('intval', Database::instance()->get_col(
            "SELECT id FROM {$table} WHERE status = 'trash' AND trashed_at IS NOT NULL AND trashed_at < ? ORDER BY trashed_at ASC LIMIT {$limit}",
            [$trashedBefore->format('Y-m-d H:i:s')]
        ) ?: []);
    }

    private function available(): bool
    {
        return FormService::getInstance()->isAvailable();
    }
}
