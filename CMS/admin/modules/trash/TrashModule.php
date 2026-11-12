<?php
declare(strict_types=1);

/**
 * Zentraler Papierkorb (/admin/trash): alle registrierten Inhaltstypen (Seiten, Beiträge, Medien, Hub-Sites,
 * Downloads, Formulare …) mit Wiederherstellen, endgültigem Löschen, Leeren je Typ und der Frist für das
 * automatische Leeren. Sichtbar sind nur Typen, für die der Benutzer `manage_trash` und die Capability des
 * Providers besitzt (`TrashService::visibleProviders()`).
 */

use CMS\Auth;
use CMS\Services\ContentTypes\TrashItem;
use CMS\Services\ContentTypes\TrashProvider;
use CMS\Services\Trash\TrashConfirmationStore;
use CMS\Services\Trash\TrashListActions;
use CMS\Services\Trash\TrashService;
use CMS\Services\Trash\TrashSupport;

if (!defined('ABSPATH')) {
    exit;
}

final class TrashModule
{
    public const PER_PAGE = 50;
    private const CONFIRMATION_CONTEXT = 'central';

    private TrashService $service;

    public function __construct()
    {
        $this->service = TrashService::getInstance();
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function getData(array $query): array
    {
        $providers = $this->service->visibleProviders();
        $counts = [];
        foreach ($providers as $type => $provider) {
            $counts[$type] = $this->safeCount($provider);
        }

        $type = is_string($query['type'] ?? null) && isset($providers[$query['type']]) ? (string) $query['type'] : '';
        if ($type === '') {
            // Erster Typ mit Einträgen, sonst der erste sichtbare.
            foreach ($counts as $candidate => $count) {
                if ($count > 0) {
                    $type = $candidate;
                    break;
                }
            }
            $type = $type !== '' ? $type : (string) (array_key_first($providers) ?? '');
        }

        $search = mb_substr(trim(strip_tags(is_scalar($query['q'] ?? null) ? (string) $query['q'] : '')), 0, 100);
        $items = [];
        $total = 0;
        $pages = 1;
        $page = 1;
        if ($type !== '') {
            $provider = $providers[$type];
            $total = $search !== '' ? $this->safeCount($provider, $search) : $counts[$type];
            $pages = max(1, (int) ceil($total / self::PER_PAGE));
            $page = max(1, min($pages, (int) ($query['page'] ?? 1)));
            try {
                $items = $total > 0 ? $provider->listTrashed(($page - 1) * self::PER_PAGE, self::PER_PAGE, $search) : [];
            } catch (\Throwable) {
                $items = [];
            }
        }

        $confirmation = TrashConfirmationStore::pull(self::CONFIRMATION_CONTEXT);

        return [
            'types' => array_map(static fn (TrashProvider $provider): string => $provider->label(), $providers),
            'counts' => $counts,
            'type' => $type,
            'items' => array_map(fn (TrashItem $item): array => $this->itemRow($item), $items),
            'search' => $search,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'retention_days' => $this->service->retentionDays(),
            'retention_max' => TrashService::MAX_RETENTION_DAYS,
            'expired' => $this->safeExpired(),
            'confirmation' => is_array($confirmation) && isset($providers[(string) ($confirmation['type'] ?? '')]) ? $confirmation : null,
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function handlePost(array $post): array
    {
        $action = is_scalar($post['action'] ?? null) ? (string) $post['action'] : '';
        $userId = (int) (Auth::instance()->currentUser()->id ?? 0);
        $type = is_scalar($post['type'] ?? null) ? (string) $post['type'] : '';
        $back = self::url(['type' => $type !== '' ? $type : null]);

        if ($action === 'save_retention') {
            $days = filter_var($post['retention_days'] ?? null, FILTER_VALIDATE_INT);
            if ($days === false || $days < 0 || $days > TrashService::MAX_RETENTION_DAYS) {
                return ['success' => false, 'error' => TrashSupport::t('trash.central.retention_invalid', TrashService::MAX_RETENTION_DAYS), '__redirect' => $back];
            }
            try {
                $saved = $this->service->saveRetentionDays($days, $userId);
            } catch (\Throwable) {
                return ['success' => false, 'error' => TrashSupport::t('trash.error.failed'), '__redirect' => $back];
            }

            return [
                'success' => true,
                'message' => $saved > 0 ? TrashSupport::t('trash.central.retention_saved', $saved) : TrashSupport::t('trash.central.retention_saved_off'),
                '__redirect' => $back,
            ];
        }

        $provider = $this->service->visibleProviders()[$type] ?? null;
        if ($provider === null) {
            return ['success' => false, 'error' => TrashSupport::t('trash.error.not_found'), '__redirect' => self::url()];
        }

        $actions = new TrashListActions($provider, self::CONFIRMATION_CONTEXT);
        $ids = is_array($post['ids'] ?? null) ? $post['ids'] : [(int) ($post['id'] ?? 0)];
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        $confirmed = !empty($post['confirmed']);

        $result = match ($action) {
            'restore' => $actions->restore($ids, $userId),
            'delete_permanently' => $actions->delete($ids, $userId, $confirmed),
            'empty' => $actions->emptyTrash(
                array_map(static fn (TrashItem $item): int => $item->id, $provider->listTrashed(0, TrashService::EMPTY_BATCH_LIMIT)),
                $userId,
                $confirmed,
                fn (): int => $this->safeCount($provider),
                'central'
            ),
            default => ['success' => false, 'error' => TrashSupport::t('trash.error.failed')],
        };
        $result['__redirect'] = $back;

        return $result;
    }

    /** @param array<string, scalar|null> $query */
    public static function url(array $query = []): string
    {
        $query = array_filter($query, static fn ($value): bool => $value !== null && $value !== '');

        return '/admin/trash' . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /** @return array<string, mixed> */
    private function itemRow(TrashItem $item): array
    {
        $user = '';
        if ($item->trashedBy !== null && $item->trashedBy > 0) {
            try {
                $row = \CMS\Database::instance()->get_row(
                    'SELECT username, display_name FROM ' . \CMS\Database::instance()->getPrefix() . 'users WHERE id = ? LIMIT 1',
                    [$item->trashedBy]
                );
                $user = $row !== null ? (string) (($row->display_name ?? '') !== '' ? $row->display_name : ($row->username ?? '')) : '';
            } catch (\Throwable) {
            }
        }

        return [
            'id' => $item->id,
            'title' => $item->title !== '' ? $item->title : '#' . $item->id,
            'trashed_at' => $item->trashedAt,
            'trashed_by' => $user,
            'previous_status' => $item->previousStatus,
            'edit_url' => $item->editUrl,
            'meta' => $item->meta,
        ];
    }

    private function safeCount(TrashProvider $provider, string $search = ''): int
    {
        try {
            return $provider->countTrashed($search);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function safeExpired(): int
    {
        try {
            return $this->service->countExpired();
        } catch (\Throwable) {
            return 0;
        }
    }
}
