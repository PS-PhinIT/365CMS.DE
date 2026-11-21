<?php
declare(strict_types=1);

/**
 * Community – Admin-Modul (4.2.0, PR #3): Übersicht, Freigaben, Meldungen, Sperren, Moderationsprotokoll, Themen, Ränge, Einstellungen.
 *
 * Reine Oberfläche: Jede Entscheidung trifft ein Dienst unter `CMS\Services\Community\Service\` (Rechte über `CommunityAccess`,
 * Pflichtgrund, Moderationsprotokoll, Hooks). Dieses Modul liest, bereitet auf und reicht Eingaben unverändert weiter.
 * Struktur (Kategorien/Foren) und Rechte-Matrix folgen im zweiten Teil von PR #3.
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Database;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Service\BanService;
use CMS\Services\Community\Service\ModerationService;
use CMS\Services\Community\Service\RankService;
use CMS\Services\Community\Service\ReportService;
use CMS\Services\Community\Support\Codes;
use CMS\Services\Community\Support\Cursor;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Settings;

final class CommunityAdminModule
{
    /** Bereich => [Seitenpfad, Titel, Capabilities (eine genügt)]. */
    public const SECTIONS = [
        'overview' => ['/admin/community', 'Community', ['community.moderate', 'community.manage']],
        'queue' => ['/admin/community-queue', 'Freigaben', ['community.moderate']],
        'reports' => ['/admin/community-reports', 'Meldungen', ['community.moderate']],
        'topics' => ['/admin/community-topics', 'Themen', ['community.moderate']],
        'sanctions' => ['/admin/community-sanctions', 'Sperren', ['community.moderate']],
        'log' => ['/admin/community-log', 'Moderationsprotokoll', ['community.moderate']],
        'ranks' => ['/admin/community-ranks', 'Ränge', ['community.manage']],
        'settings' => ['/admin/community-settings', 'Community-Einstellungen', ['community.manage']],
    ];

    public const PER_PAGE = 25;

    /** Zeitangaben der Sperre: Auswahl in Tagen (0 = dauerhaft, nur mit `community.ban`, der Dienst prüft das). */
    public const BAN_DAYS = [1, 3, 7, 14, 30, 90, 365, 0];

    public function __construct(private readonly string $section = 'overview')
    {
    }

    /** @param array<string, scalar|null> $params */
    public static function url(string $section, array $params = []): string
    {
        $path = self::SECTIONS[$section][0] ?? '/admin/community';
        $params = array_filter($params, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== 0);

        return $path . ($params !== [] ? '?' . http_build_query($params) : '');
    }

    /** Meldungstext zu einem Fehlercode eines Dienstes. */
    public static function errorText(mixed $code): string
    {
        $code = is_string($code) ? $code : 'db_error';
        $key = Codes::errorKey($code);
        $text = I18n::t($key);

        return $text !== '' && $text !== $key ? $text : 'Aktion nicht möglich (' . $code . ').';
    }

    public static function date(mixed $utc): string
    {
        return is_string($utc) && $utc !== '' ? I18n::date($utc, 'd.m.Y H:i') : '–';
    }

    // ── Daten ───────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function getData(int $userId, array $query): array
    {
        return match ($this->section) {
            'queue' => $this->queueData($userId, $query),
            'reports' => $this->reportsData($userId, $query),
            'topics' => $this->topicsData($query),
            'sanctions' => $this->sanctionsData($userId, $query),
            'log' => $this->logData($userId, $query),
            'ranks' => ['ranks' => RankService::instance()->all()],
            'settings' => $this->settingsData(),
            default => $this->overviewData($userId),
        };
    }

    /** @return array<string, mixed> */
    private function overviewData(int $userId): array
    {
        $queue = ModerationService::instance()->queueSummary($userId);
        $db = Database::instance();
        $count = static function (string $table, string $where = '1=1') use ($db): int {
            try {
                return (int) ($db->get_var('SELECT COUNT(*) FROM ' . $db->getPrefix() . $table . ' WHERE ' . $where) ?? 0);
            } catch (\Throwable) {
                return 0;
            }
        };
        $forumCount = $count('community_forums');
        $categoryCount = $count('community_categories');
        $settings = Settings::getInstance();

        return [
            'queue' => ['count' => (int) ($queue['count'] ?? 0), 'oldest_at' => $queue['oldest_at'] ?? null, 'ok' => !empty($queue['success'])],
            'reports' => ReportService::instance()->countOpen($userId),
            'stats' => [
                'forums' => $forumCount,
                'topics' => $count('community_topics', "status = 'published'"),
                'posts' => $count('community_posts', "status = 'published'"),
                'bans' => $count('community_bans', 'lifted_at IS NULL AND (ends_at IS NULL OR ends_at > UTC_TIMESTAMP())'),
            ],
            'checklist' => [
                ['ok' => $categoryCount > 0, 'text' => 'Mindestens eine Kategorie angelegt', 'link' => '/admin/community-structure'],
                ['ok' => $forumCount > 0, 'text' => 'Mindestens ein Forum angelegt', 'link' => '/admin/community-structure'],
                ['ok' => $settings->bool('guests_can_read'), 'text' => 'Gäste dürfen lesen (optional, für öffentliche Foren)', 'link' => '/admin/community-settings'],
                ['ok' => $settings->bool('require_verified_email'), 'text' => 'Schreiben nur mit bestätigter E-Mail-Adresse', 'link' => '/admin/community-settings'],
            ],
            'base_path' => '/' . $settings->string('base_path'),
        ];
    }

    /** @return array<string, mixed> */
    private function queueData(int $userId, array $query): array
    {
        $cursor = is_string($query['cursor'] ?? null) ? $query['cursor'] : null;
        $result = ModerationService::instance()->queue($userId, $cursor, self::PER_PAGE);

        return ['result' => $result, 'forums' => $this->forumNames()];
    }

    /** @return array<string, mixed> */
    private function reportsData(int $userId, array $query): array
    {
        $service = ReportService::instance();
        $filter = [];
        $type = $query['type'] ?? '';
        if (is_string($type) && in_array($type, ReportService::TARGET_TYPES, true)) {
            $filter['target_type'] = $type;
        }
        $cursor = is_string($query['cursor'] ?? null) ? $query['cursor'] : null;
        $list = $service->listOpen($userId, $cursor, self::PER_PAGE, $filter);

        $detail = null;
        $targetType = $query['target_type'] ?? '';
        $targetId = (int) ($query['target_id'] ?? 0);
        if (is_string($targetType) && in_array($targetType, ReportService::TARGET_TYPES, true) && $targetId > 0) {
            $detail = ['target_type' => $targetType, 'target_id' => $targetId] + $service->bundle($userId, $targetType, $targetId);
        }

        return ['result' => $list, 'detail' => $detail, 'type' => $filter['target_type'] ?? '', 'forums' => $this->forumNames()];
    }

    /** @return array<string, mixed> */
    private function topicsData(array $query): array
    {
        $forums = (new ForumRepository())->tree();
        $forumId = (int) ($query['forum_id'] ?? 0);
        if ($forumId < 1 || !isset($forums[$forumId])) {
            $forumId = 0;
        }
        $status = is_string($query['status'] ?? null) && in_array($query['status'], ['published', 'pending', 'hidden'], true) ? (string) $query['status'] : 'published';
        $items = [];
        $next = null;
        if ($forumId > 0) {
            $cursor = is_string($query['cursor'] ?? null) ? Cursor::decode($query['cursor']) : null;
            $page = (new TopicRepository())->listForum($forumId, gmdate('Y-m-d H:i:s'), $cursor, self::PER_PAGE, $status);
            $items = $page->items;
            $next = $page->next;
        }

        return ['forums' => $forums, 'forum_id' => $forumId, 'status' => $status, 'items' => $items, 'next' => $next];
    }

    /** @return array<string, mixed> */
    private function sanctionsData(int $userId, array $query): array
    {
        $cursor = is_string($query['cursor'] ?? null) ? $query['cursor'] : null;

        return ['result' => BanService::instance()->listActive($userId, $cursor, self::PER_PAGE), 'ban_days' => self::BAN_DAYS, 'scopes' => BanService::SCOPES];
    }

    /** @return array<string, mixed> */
    private function logData(int $userId, array $query): array
    {
        $filter = [];
        $action = $query['action'] ?? '';
        if (is_string($action) && $action !== '') {
            $filter['action'] = $action;
        }
        $actor = (int) ($query['actor_id'] ?? 0);
        if ($actor > 0) {
            $filter['actor_id'] = $actor;
        }
        $cursor = is_string($query['cursor'] ?? null) ? $query['cursor'] : null;

        return [
            'result' => ModerationService::instance()->log($userId, $filter, $cursor, self::PER_PAGE),
            'filter' => $filter + ['action' => '', 'actor_id' => 0],
            'actions' => \CMS\Services\Community\Repository\ModerationLogRepository::ACTIONS,
        ];
    }

    /** @return array<string, mixed> */
    private function settingsData(): array
    {
        return ['values' => Settings::getInstance()->all(), 'definitions' => Settings::DEFINITIONS];
    }

    /** @return array<int, string> */
    private function forumNames(): array
    {
        $names = [];
        foreach ((new ForumRepository())->tree() as $id => $forum) {
            $names[(int) $id] = (string) ($forum['title'] ?? '');
        }

        return $names;
    }

    // ── Aktionen (POST) ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed> `success`, `message` | `error`
     */
    public function handlePost(array $post, int $userId): array
    {
        $action = is_string($post['action'] ?? null) ? (string) $post['action'] : '';
        $reason = trim((string) ($post['reason'] ?? ''));

        return match ($action) {
            'approve_post' => $this->outcome(ModerationService::instance()->approvePost($userId, (int) ($post['id'] ?? 0), $reason), 'Beitrag freigegeben.'),
            'reject_post' => $this->outcome(ModerationService::instance()->rejectPost($userId, (int) ($post['id'] ?? 0), $reason), 'Beitrag abgelehnt.'),
            'resolve_report' => $this->resolveReport($post, $userId, $reason),
            'ban' => $this->ban($post, $userId, $reason),
            'lift_ban' => $this->outcome(BanService::instance()->lift($userId, (int) ($post['id'] ?? 0), $reason), 'Sperre aufgehoben.'),
            'bulk' => $this->bulk($post, $userId, $reason),
            'save_rank' => $this->saveRank($post, $userId),
            'delete_rank' => $this->outcome(RankService::instance()->delete($userId, (int) ($post['id'] ?? 0)), 'Rang gelöscht.'),
            'save_settings' => $this->saveSettings($post),
            default => ['success' => false, 'error' => 'Unbekannte Aktion.'],
        };
    }

    /**
     * @param array<string, mixed> $result Ergebnis eines Dienstes
     * @return array<string, mixed>
     */
    private function outcome(array $result, string $message): array
    {
        if (!empty($result['success'])) {
            return ['success' => true, 'message' => $message];
        }

        return ['success' => false, 'error' => self::errorText($result['error'] ?? null)];
    }

    /** @return array<string, mixed> */
    private function resolveReport(array $post, int $userId, string $note): array
    {
        $status = (string) ($post['status'] ?? '');
        $result = ReportService::instance()->resolve($userId, (int) ($post['id'] ?? 0), $status, $note, isset($post['up_to']) ? (int) $post['up_to'] : null);
        if (!empty($result['success']) && !empty($result['remaining'])) {
            return ['success' => true, 'message' => 'Teilweise erledigt: weitere offene Meldungen zum Ziel bleiben, bitte erneut absenden.'];
        }

        return $this->outcome($result, 'Meldung erledigt.');
    }

    /** @return array<string, mixed> */
    private function ban(array $post, int $userId, string $reason): array
    {
        $days = (int) ($post['days'] ?? 7);
        if (!in_array($days, self::BAN_DAYS, true)) {
            return ['success' => false, 'error' => self::errorText('invalid_input')];
        }
        $scope = (string) ($post['scope'] ?? 'write');
        $result = BanService::instance()->ban($userId, (int) ($post['user_id'] ?? 0), $scope, $days === 0 ? null : $days * 86400, $reason, trim((string) ($post['note'] ?? '')));

        return $this->outcome($result, 'Mitglied gesperrt.');
    }

    /** @return array<string, mixed> */
    private function bulk(array $post, int $userId, string $reason): array
    {
        $operation = (string) ($post['operation'] ?? '');
        $ids = array_values(array_filter(array_map('intval', is_array($post['ids'] ?? null) ? $post['ids'] : []), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return ['success' => false, 'error' => 'Bitte mindestens ein Thema auswählen.'];
        }
        $params = ['reason' => $reason];
        if ($operation === 'topic.move') {
            $params['target_forum_id'] = (int) ($post['target_forum_id'] ?? 0);
        }
        $result = ModerationService::instance()->bulk($userId, $operation, $ids, $params);
        if (empty($result['success'])) {
            return ['success' => false, 'error' => self::errorText($result['error'] ?? null)];
        }
        $failed = (int) ($result['failed'] ?? 0);

        return [
            'success' => $failed === 0,
            'message' => sprintf('%d erledigt, %d fehlgeschlagen.', (int) ($result['done'] ?? 0), $failed),
            'error' => sprintf('%d erledigt, %d fehlgeschlagen.', (int) ($result['done'] ?? 0), $failed),
        ];
    }

    /** @return array<string, mixed> */
    private function saveRank(array $post, int $userId): array
    {
        $id = (int) ($post['id'] ?? 0);
        $data = [
            'title' => trim((string) ($post['title'] ?? '')),
            'title_en' => ($t = trim((string) ($post['title_en'] ?? ''))) === '' ? null : $t,
            'min_posts' => ($m = trim((string) ($post['min_posts'] ?? ''))) === '' ? null : (int) $m,
            'color' => ($c = trim((string) ($post['color'] ?? ''))) === '' ? null : strtolower($c),
            'icon' => ($i = trim((string) ($post['icon'] ?? ''))) === '' ? null : $i,
            'sort_order' => (int) ($post['sort_order'] ?? 0),
        ];

        return $this->outcome(RankService::instance()->save($userId, $data, $id > 0 ? $id : null), 'Rang gespeichert.');
    }

    /** @return array<string, mixed> */
    private function saveSettings(array $post): array
    {
        $values = [];
        foreach (Settings::DEFINITIONS as $key => $definition) {
            $type = $definition['type'];
            $field = 's_' . $key;
            if ($type === 'bool') {
                $values[$key] = isset($post[$field]) && $post[$field] === '1';
            } elseif ($type === 'int' || $type === 'slug') {
                if (isset($post[$field]) && is_scalar($post[$field])) {
                    $values[$key] = $type === 'int' ? (int) $post[$field] : trim((string) $post[$field]);
                }
            }
        }

        // Strukturen
        $limits = [];
        foreach (Settings::TRUST_LEVELS as $level) {
            foreach (Settings::LIMIT_FIELDS as $limitField) {
                $limits[$level][$limitField] = (int) ($post['limit'][$level][$limitField] ?? 0);
            }
        }
        $values['trust_limits'] = $limits;

        $permissions = [];
        foreach (Settings::PERMISSION_SUBJECTS as $subject) {
            foreach (Settings::PERMISSION_KEYS as $permission) {
                $permissions[$subject][$permission] = isset($post['perm'][$subject][$permission]) ? 1 : 0;
            }
        }
        $values['default_permissions'] = $permissions;

        $reactions = is_array($post['reaction_set'] ?? null) ? array_values(array_intersect(Settings::REACTION_KEYS, array_map('strval', $post['reaction_set']))) : [];
        $values['reaction_set'] = $reactions;

        $types = [];
        foreach (Settings::ATTACHMENT_TYPES as $ext) {
            $types[$ext] = isset($post['attachment_types'][$ext]) ? 1 : 0;
        }
        $values['attachment_types'] = $types;

        $filter = ['replacement' => trim((string) ($post['wf_replacement'] ?? '***'))];
        foreach (Settings::WORD_FILTER_LISTS as $list) {
            $lines = preg_split('/\R/u', (string) ($post['wf_' . $list] ?? '')) ?: [];
            $filter[$list] = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
        }
        $values['word_filter'] = $filter;

        return Settings::getInstance()->save($values)
            ? ['success' => true, 'message' => 'Einstellungen gespeichert.']
            : ['success' => false, 'error' => 'Einstellungen konnten nicht gespeichert werden.'];
    }
}
