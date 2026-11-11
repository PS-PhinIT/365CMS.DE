<?php
/**
 * Inhaltstyp-Provider „Downloads“ (`download`): Papierkorb.
 *
 * In den Papierkorb verschiebt die Downloads-Verwaltung selbst (vorheriger Status, Zeitpunkt, Benutzer).
 * Endgültiges Löschen entfernt Versionen samt Dateien, Eigenschaften, Verwendungsindex und Statistik; ist der
 * Download noch in Beiträgen oder Seiten eingebunden, ist eine Bestätigung nötig.
 *
 * @package CMSv2\Core\Services\Downloads
 */

declare(strict_types=1);

namespace CMS\Services\Downloads;

use CMS\Services\ContentTypes\TrashItem;
use CMS\Services\ContentTypes\TrashProvider;
use CMS\Services\ContentTypes\TrashResult;

if (!defined('ABSPATH')) {
    exit;
}

final class DownloadContentProvider implements TrashProvider
{
    public const TYPE = 'download';

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return __('downloads.provider.label');
    }

    public function capability(): string
    {
        return 'manage_downloads';
    }

    public function countTrashed(string $search = ''): int
    {
        $service = DownloadService::getInstance();

        return $service->isAvailable() ? $service->repository()->countItems(['status' => 'trash', 'search' => $search]) : 0;
    }

    public function listTrashed(int $offset, int $limit, string $search = ''): array
    {
        $service = DownloadService::getInstance();
        if (!$service->isAvailable()) {
            return [];
        }

        $repo = $service->repository();
        $params = ['trash'];
        $where = 'status = ?';
        if (trim($search) !== '') {
            $like = '%' . addcslashes(trim($search), '%_\\') . '%';
            $where .= ' AND (title LIKE ? OR slug LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        $params[] = max(1, min(500, $limit));
        $params[] = max(0, $offset);

        $items = [];
        foreach ($repo->fetchAll(
            'SELECT id, title, slug, previous_status, trashed_at, trashed_by FROM ' . $repo->table('downloads_items')
            . " WHERE {$where} ORDER BY trashed_at DESC, id DESC LIMIT ? OFFSET ?",
            $params
        ) as $row) {
            $items[] = new TrashItem(
                self::TYPE,
                (int) $row['id'],
                (string) $row['title'],
                (string) ($row['trashed_at'] ?? ''),
                $row['trashed_by'] !== null ? (int) $row['trashed_by'] : null,
                (string) ($row['previous_status'] ?? ''),
                '/admin/downloads?status=trash',
                ['slug' => (string) $row['slug']]
            );
        }

        return $items;
    }

    public function restore(int $id, int $userId): TrashResult
    {
        return DownloadService::getInstance()->restore($id, $userId)
            ? TrashResult::success(__('downloads.admin.restored'))
            : TrashResult::failure(TrashResult::CODE_ERROR, __('downloads.error.not_found'));
    }

    public function deletionWarnings(int $id): array
    {
        $usages = DownloadUsageService::getInstance()->usagesFor($id);

        return $usages !== [] ? [sprintf(__('downloads.provider.warning_usage'), count($usages))] : [];
    }

    public function deletePermanently(int $id, int $userId, bool $confirmed = false): TrashResult
    {
        $service = DownloadService::getInstance();
        $item = $service->get($id);
        if ($item === null || (string) $item['status'] !== 'trash') {
            return TrashResult::failure(TrashResult::CODE_ERROR, __('downloads.error.not_found'));
        }
        $warnings = $this->deletionWarnings($id);
        if ($warnings !== [] && !$confirmed) {
            return TrashResult::confirmationRequired($warnings, __('downloads.provider.confirm_delete'));
        }

        return $service->deletePermanently($id, $userId)
            ? TrashResult::success(__('downloads.admin.deleted'))
            : TrashResult::failure(TrashResult::CODE_ERROR, __('downloads.error.save_failed'));
    }

    public function expiredIds(\DateTimeImmutable $trashedBefore, int $limit): array
    {
        $service = DownloadService::getInstance();
        if (!$service->isAvailable()) {
            return [];
        }
        $repo = $service->repository();

        return array_map('intval', array_column($repo->fetchAll(
            'SELECT id FROM ' . $repo->table('downloads_items') . ' WHERE status = ? AND trashed_at IS NOT NULL AND trashed_at < ? ORDER BY trashed_at ASC LIMIT ?',
            ['trash', $trashedBefore->format('Y-m-d H:i:s'), max(1, min(1000, $limit))]
        ), 'id'));
    }
}
