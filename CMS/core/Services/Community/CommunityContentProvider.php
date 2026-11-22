<?php
/**
 * Papierkorb-Provider der Community (PR #5): gelöschte Foren-Themen im zentralen Papierkorb des Core (`/admin/trash`).
 *
 * - Eintrag = Thema mit `status = deleted` (Details und Entscheidungen in `Service\TopicTrashService`); Beiträge, Anhänge und alles
 *   Übrige hängen am Thema und gehen mit ihm. Nur Administratoren sehen und bedienen diesen Typ: die Capability ist `community.manage`
 *   zusätzlich zu `manage_trash` (HANDOFF: „Themen im Papierkorb sieht nur `admin`“).
 * - Wiederherstellen: ohne Slug-Konflikt (Themenadressen laufen über die ID). Abgelehnte Themen lassen sich nicht wiederherstellen.
 * - Endgültig löschen: `deletionWarnings()` meldet offene Meldungen; ohne Bestätigung löscht weder die Liste noch der Fristlauf
 *   (`confirmation_required`: der Eintrag bleibt liegen, bis er von Hand bestätigt wird).
 * - Wird immer angemeldet, auch bei abgeschaltetem Modul (`CommunityHooks`): die Daten bleiben beim Abschalten erhalten und die
 *   Aufbewahrungsfrist des Papierkorbs gilt weiter. Ohne Datenbankzugriff beim Anmelden; der Dienst entsteht erst bei Gebrauch.
 * - Der Suchindex des Core bekommt keinen Provider: interne Foren dürfen nie in die Suche der Website (die Community hat
 *   `CommunitySearch`).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community;

use CMS\Services\Community\Service\TopicTrashService;
use CMS\Services\Community\Support\CommunityCapabilities;
use CMS\Services\Community\Support\I18n;
use CMS\Services\ContentTypes\TrashItem;
use CMS\Services\ContentTypes\TrashProvider;
use CMS\Services\ContentTypes\TrashResult;

if (!defined('ABSPATH')) {
    exit;
}

final class CommunityContentProvider implements TrashProvider
{
    public const TYPE = 'community_topic';

    /** Ziel der Titelverknüpfung im zentralen Papierkorb. */
    public const MANAGE_URL = '/admin/community-topics';

    /** @var \Closure(): TopicTrashService */
    private \Closure $service;

    /**
     * @param \Closure(): TopicTrashService|null $service Standard: `TopicTrashService::instance()` (erst bei Gebrauch)
     */
    public function __construct(?\Closure $service = null)
    {
        $this->service = $service ?? static fn (): TopicTrashService => TopicTrashService::instance();
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return I18n::t('community.trash.label');
    }

    public function capability(): string
    {
        return CommunityCapabilities::MANAGE;
    }

    public function countTrashed(string $search = ''): int
    {
        try {
            return $this->service()->count($search);
        } catch (\Throwable $e) {
            $this->fail('count', 0, $e);

            return 0;
        }
    }

    public function listTrashed(int $skip, int $limit, string $search = ''): array
    {
        try {
            $items = [];
            foreach ($this->service()->list($skip, $limit, $search) as $row) {
                $items[] = new TrashItem(
                    self::TYPE,
                    $row['id'],
                    $row['title'] !== '' ? $row['title'] : '#' . $row['id'],
                    substr($row['trashed_at'], 0, 19),
                    $row['trashed_by'],
                    $row['previous_status'],
                    self::MANAGE_URL,
                    [
                        'forum' => $row['forum_title'],
                        'replies' => $row['reply_count'],
                        'rejected' => $row['rejected'],
                        'restorable' => $row['restorable'],
                        'trashed_by_name' => $row['trashed_by_name'],
                    ]
                );
            }

            return $items;
        } catch (\Throwable $e) {
            $this->fail('list', 0, $e);

            return [];
        }
    }

    public function restore(int $id, int $userId): TrashResult
    {
        try {
            $result = $this->service()->restore($id, $userId);
        } catch (\Throwable $e) {
            return $this->failure('restore', $id, $e);
        }
        if (!empty($result['success'])) {
            return TrashResult::success(I18n::t('community.trash.restored', ['title' => (string) $result['title']]), [
                'id' => $id,
                'status' => (string) $result['status'],
            ]);
        }

        return $this->mapError($result);
    }

    public function deletionWarnings(int $id): array
    {
        try {
            $reports = $this->service()->openReports($id);
        } catch (\Throwable $e) {
            $this->fail('warnings', $id, $e);

            // Unbekannter Zustand: lieber nachfragen als blind löschen.
            return [I18n::t('community.trash.warning.unknown')];
        }

        return $reports > 0 ? [I18n::t('community.trash.warning.reports', ['count' => $reports])] : [];
    }

    public function deletePermanently(int $id, int $userId, bool $confirmed = false): TrashResult
    {
        try {
            $title = $this->service()->title($id);
            $warnings = $this->deletionWarnings($id);
            if ($warnings !== [] && !$confirmed) {
                return TrashResult::failure(
                    TrashResult::CODE_CONFIRMATION_REQUIRED,
                    I18n::t('community.trash.confirm', ['title' => $title]),
                    ['warnings' => $warnings, 'title' => $title]
                );
            }
            $result = $this->service()->purge($id, $userId);
        } catch (\Throwable $e) {
            return $this->failure('delete', $id, $e);
        }
        if (!empty($result['success'])) {
            return TrashResult::success(I18n::t('community.trash.deleted', ['title' => (string) $result['title']]), ['id' => $id, 'removed' => (array) $result['removed']]);
        }

        return $this->mapError($result);
    }

    public function expiredIds(\DateTimeImmutable $trashedBefore, int $limit): array
    {
        $utc = $trashedBefore->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return array_values(array_map('intval', $this->service()->expiredIds($utc, $limit)));
    }

    private function service(): TopicTrashService
    {
        return ($this->service)();
    }

    /**
     * @param array<string, mixed> $result Fehlerergebnis des Dienstes
     */
    private function mapError(array $result): TrashResult
    {
        $error = (string) ($result['error'] ?? '');

        return match (true) {
            in_array($error, ['topic_not_found', 'invalid_input'], true) => TrashResult::failure(TrashResult::CODE_NOT_FOUND, I18n::t('community.trash.error.not_found')),
            $error === 'forum_not_found' => TrashResult::failure(TrashResult::CODE_INVALID_STATE, I18n::t('community.trash.error.forum_missing')),
            $error === 'invalid_state' && ($result['reason'] ?? '') === 'rejected' => TrashResult::failure(TrashResult::CODE_INVALID_STATE, I18n::t('community.trash.error.rejected')),
            $error === 'invalid_state' => TrashResult::failure(TrashResult::CODE_INVALID_STATE, I18n::t('community.trash.error.state')),
            default => TrashResult::failure(TrashResult::CODE_ERROR, I18n::t('community.trash.error.failed')),
        };
    }

    private function failure(string $operation, int $id, \Throwable $e): TrashResult
    {
        $this->fail($operation, $id, $e);

        return TrashResult::failure(TrashResult::CODE_ERROR, I18n::t('community.trash.error.failed'));
    }

    private function fail(string $operation, int $id, \Throwable $e): void
    {
        \CMS\Logger::instance()->withChannel('community')->warning('Community-Papierkorb fehlgeschlagen ({operation}): {exception}', [
            'operation' => $operation,
            'topic_id' => $id,
            'exception' => $e,
        ]);
    }
}
