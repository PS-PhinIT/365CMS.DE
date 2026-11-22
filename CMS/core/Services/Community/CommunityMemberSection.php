<?php
/**
 * Mitgliederbereich „Community“ (PR #4): Meine Themen, Meine Beiträge, Abos, Einstellungen und – für die Moderation – Freigaben und
 * Meldungen unter `/member/plugin/community`. Die Rolle `moderator` hat keinen Zugang zu `/admin`; ihre Warteschlange und die Meldungen
 * laufen deshalb hier und im Frontend.
 *
 * Reine Oberfläche über den Diensten: jede Entscheidung treffen `SubscriptionService`, `ModerationService` und `ReportService` (Rechte,
 * Pflichtgrund, Protokoll). POST läuft über den Core-Token der Registry (`csrf => core`), das Ergebnis kommt als Flash nach der
 * 303-Weiterleitung. Sichtbar ist der Bereich nur bei aktivem Modul (die Anmeldung geschieht hinter dem Modul-Gate in `CommunityHooks`).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community;

use CMS\Member\PluginDashboardRegistry;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Repository\UserStatsRepository;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\ModerationService;
use CMS\Services\Community\Service\NotificationDispatcher;
use CMS\Services\Community\Service\ProfileService;
use CMS\Services\Community\Service\ReportService;
use CMS\Services\Community\Service\SubscriptionService;
use CMS\Services\Community\Support\Codes;
use CMS\Services\Community\Support\Cursor;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Urls;

if (!defined('ABSPATH')) {
    exit;
}

final class CommunityMemberSection
{
    public const SLUG = 'community';

    /** Reiter in Anzeigereihenfolge; `moderation` nur für die Moderation. */
    public const TABS = ['themen' => 'Meine Themen', 'beitraege' => 'Meine Beiträge', 'abos' => 'Abos', 'einstellungen' => 'Einstellungen', 'moderation' => 'Moderation'];

    private const PER_PAGE = 20;
    private const EXCERPT = 140;
    private const TYPE_LABELS = [
        'community.reply' => 'Antworten auf meine Themen und Abos',
        'community.new_topic' => 'Neue Themen in abonnierten Foren',
        'community.quote' => 'Zitate meiner Beiträge',
        'community.reaction' => 'Reaktionen auf meine Beiträge',
        'community.solution' => 'Lösung in meinen Themen markiert',
    ];
    private const AUTO_LABELS = ['all' => 'Bei eigenen Themen und Antworten', 'created' => 'Nur bei eigenen Themen', 'off' => 'Nie automatisch'];

    public static function register(PluginDashboardRegistry $registry): void
    {
        $registry->register([
            'plugin' => 'cms-community',
            'slug' => self::SLUG,
            'label' => 'Community',
            'icon' => '💬',
            'category' => 'plugins',
            'priority' => 40,
            'csrf' => 'core',
            'dashboard_widget' => [
                'title' => 'Community',
                'description' => 'Deine Themen, Beiträge und Abos im Forum.',
                'color' => '#7c3aed',
                'stats_callback' => [self::class, 'stats'],
            ],
            'render_callback' => [self::class, 'render'],
            'post_callback' => [self::class, 'handlePost'],
        ]);
    }

    /** @return array{count: int, label: string} */
    public static function stats(object $user): array
    {
        $count = 0;
        try {
            $row = (new UserStatsRepository())->get((int) ($user->id ?? 0));
            $count = (int) ($row['post_count'] ?? 0);
        } catch (\Throwable) {
        }

        return ['count' => $count, 'label' => $count === 1 ? 'Beitrag im Forum' : 'Beiträge im Forum'];
    }

    // ── Reiter ──────────────────────────────────────────────────────────────────────────────────────────────────

    /** Reiter, die dieses Konto sieht (Moderation nur mit Bereich). */
    public static function tabsFor(bool $moderator): array
    {
        return $moderator ? self::TABS : array_diff_key(self::TABS, ['moderation' => true]);
    }

    /** Gewählter Reiter aus `?tab=`; unbekannt oder nicht sichtbar: der erste. */
    public static function normalizeTab(mixed $tab, bool $moderator): string
    {
        $tabs = self::tabsFor($moderator);

        return is_string($tab) && isset($tabs[$tab]) ? $tab : (string) array_key_first($tabs);
    }

    private static function isModerator(int $userId): bool
    {
        try {
            $scope = CommunityAccess::instance()->moderationScope($userId);

            return $scope['global'] || $scope['forum_ids'] !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    // ── POST ────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $params
     * @return array{type: string, message: string, redirect: string}|null
     */
    public static function handlePost(object $user, array $params = []): ?array
    {
        $userId = (int) ($user->id ?? 0);
        $action = is_string($_POST['community_action'] ?? null) ? (string) $_POST['community_action'] : '';
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $tab = 'themen';
        $result = ['success' => false, 'error' => 'unknown_action'];
        $ok = 'Erledigt.';
        if ($userId < 1) {
            return null;
        }
        switch ($action) {
            case 'unsubscribe':
                $tab = 'abos';
                $type = (string) ($_POST['type'] ?? '');
                $id = (int) ($_POST['id'] ?? 0);
                $result = in_array($type, SubscriptionService::TARGET_TYPES, true) && $id > 0
                    ? SubscriptionService::instance()->unsubscribe($userId, $type, $id)
                    : ['success' => false, 'error' => 'invalid_input'];
                $ok = 'Abo beendet.';
                break;
            case 'save_preferences':
                $tab = 'einstellungen';
                $result = SubscriptionService::instance()->savePreferences($userId, self::preferenceChanges());
                $ok = 'Einstellungen gespeichert.';
                break;
            case 'save_signature':
                $tab = 'einstellungen';
                $result = ProfileService::instance()->saveSignature($userId, is_string($_POST['signature'] ?? null) ? (string) $_POST['signature'] : '');
                $ok = I18n::t('community.member.signature_saved');
                break;
            case 'approve_post':
            case 'reject_post':
                $tab = 'moderation';
                $postId = (int) ($_POST['id'] ?? 0);
                $service = ModerationService::instance();
                $result = $action === 'approve_post' ? $service->approvePost($userId, $postId, $reason) : $service->rejectPost($userId, $postId, $reason);
                $ok = $action === 'approve_post' ? 'Beitrag freigegeben.' : 'Beitrag abgelehnt.';
                break;
            case 'resolve_report':
                $tab = 'moderation';
                $status = (string) ($_POST['status'] ?? '');
                $result = ReportService::instance()->resolve($userId, (int) ($_POST['id'] ?? 0), $status, $reason, isset($_POST['up_to']) ? (int) $_POST['up_to'] : null);
                $ok = !empty($result['remaining']) ? 'Teilweise erledigt, bitte erneut absenden.' : 'Meldung erledigt.';
                break;
        }
        $redirect = '/member/plugin/' . self::SLUG . '?tab=' . $tab;

        return !empty($result['success'])
            ? ['type' => 'success', 'message' => $ok, 'redirect' => $redirect]
            : ['type' => 'danger', 'message' => self::errorText($result), 'redirect' => $redirect];
    }

    /** @return array<string, mixed> Änderungen für `savePreferences()` aus dem Formular. */
    private static function preferenceChanges(): array
    {
        $types = [];
        foreach (array_keys(self::TYPE_LABELS) as $type) {
            $types[$type] = isset($_POST['notify_types'][$type]);
        }
        $locale = (string) ($_POST['locale'] ?? '');

        return [
            'auto_subscribe' => (string) ($_POST['auto_subscribe'] ?? 'all'),
            'notify_mail' => isset($_POST['notify_mail']),
            'notify_types' => $types,
            'locale' => in_array($locale, I18n::LOCALES, true) ? $locale : null,
        ];
    }

    /** @param array<string, mixed> $result */
    private static function errorText(array $result): string
    {
        $code = (string) ($result['error'] ?? 'db_error');
        foreach ([Codes::errorKey($code), Codes::accessKey($code)] as $key) {
            $text = I18n::t($key);
            if ($text !== '' && $text !== $key) {
                return $text;
            }
        }

        return 'Das hat nicht geklappt (' . $code . ').';
    }

    // ── Ausgabe ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $params */
    public static function render(object $user, array $params = []): void
    {
        $userId = (int) ($user->id ?? 0);
        $moderator = self::isModerator($userId);
        $tab = self::normalizeTab($_GET['tab'] ?? null, $moderator);
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        echo '<ul class="nav nav-tabs mb-3" aria-label="Community-Bereiche">';
        foreach (self::tabsFor($moderator) as $key => $label) {
            echo '<li class="nav-item"><a class="nav-link' . ($key === $tab ? ' active' : '') . '"' . ($key === $tab ? ' aria-current="page"' : '')
                . ' href="/member/plugin/' . self::SLUG . '?tab=' . $e($key) . '">' . $e($label) . '</a></li>';
        }
        echo '</ul>';

        try {
            match ($tab) {
                'beitraege' => self::renderPosts($userId, $e),
                'abos' => self::renderSubscriptions($userId, $e),
                'einstellungen' => self::renderPreferences($userId, $e),
                'moderation' => self::renderModeration($userId, $e),
                default => self::renderTopics($userId, $e),
            };
        } catch (\Throwable $exception) {
            \CMS\Logger::instance()->withChannel('community')->warning('Mitgliederbereich nicht darstellbar: {exception}', ['exception' => $exception]);
            echo '<div class="alert alert-danger" role="alert">Dieser Bereich konnte nicht geladen werden.</div>';
        }
    }

    private static function cursor(): ?Cursor
    {
        return is_string($_GET['nach'] ?? null) ? Cursor::decode((string) $_GET['nach']) : null;
    }

    private static function more(?string $next, string $tab, \Closure $e): void
    {
        if ($next !== null) {
            echo '<p><a class="btn" href="/member/plugin/' . self::SLUG . '?tab=' . $e($tab) . '&amp;nach=' . $e(rawurlencode($next)) . '">Weiter</a></p>';
        }
    }

    private static function date(mixed $utc): string
    {
        return is_string($utc) && $utc !== '' ? I18n::date($utc, 'd.m.Y H:i') : '–';
    }

    /**
     * Nur Zeilen in Foren, die das Mitglied noch lesen darf (ein entzogenes Leserecht blendet auch eigene Inhalte aus).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function readable(int $userId, array $rows): array
    {
        $forums = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['forum_id'], $rows)));
        $allowed = array_flip(CommunityAccess::instance()->filterReadable($userId, $forums));

        return array_values(array_filter($rows, static fn (array $row): bool => isset($allowed[(int) $row['forum_id']])));
    }

    private static function renderTopics(int $userId, \Closure $e): void
    {
        $repo = new TopicRepository();
        $locale = I18n::requestLocale();
        $pending = self::readable($userId, $repo->listByUser($userId, 'pending', null, 10)->items);
        if ($pending !== []) {
            echo '<div class="alert alert-info" role="status"><strong>Wartet auf Freigabe:</strong><ul class="mb-0">';
            foreach ($pending as $topic) {
                echo '<li>' . $e($topic['title']) . ' <span class="text-secondary">(' . $e(self::date($topic['created_at'])) . ')</span></li>';
            }
            echo '</ul></div>';
        }
        $page = $repo->listByUser($userId, 'published', self::cursor(), self::PER_PAGE);
        $topics = self::readable($userId, $page->items);
        if ($topics === []) {
            echo '<p class="text-secondary">Du hast noch keine Themen angelegt.</p>';

            return;
        }
        echo '<div class="table-responsive"><table class="table table-vcenter"><thead><tr><th scope="col">Thema</th><th scope="col">Antworten</th><th scope="col">Angelegt</th></tr></thead><tbody>';
        foreach ($topics as $topic) {
            echo '<tr><td><a href="' . $e(Urls::topic((int) $topic['id'], (string) ($topic['slug'] ?? ''), $locale)) . '">' . $e($topic['title']) . '</a></td>'
                . '<td>' . (int) $topic['reply_count'] . '</td><td class="text-nowrap">' . $e(self::date($topic['created_at'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        self::more($page->next, 'themen', $e);
    }

    private static function renderPosts(int $userId, \Closure $e): void
    {
        $posts = new PostRepository();
        $topics = new TopicRepository();
        $locale = I18n::requestLocale();
        $page = $posts->listRepliesByUser($userId, 'published', self::cursor(), self::PER_PAGE);
        $rows = self::readable($userId, $page->items);
        if ($rows === []) {
            echo '<p class="text-secondary">Du hast noch keine Antworten geschrieben.</p>';

            return;
        }
        echo '<ul class="list-group mb-3">';
        $titles = $topics->findMany(array_column($rows, 'topic_id'));
        foreach ($rows as $row) {
            $topic = $titles[(int) $row['topic_id']] ?? null;
            $excerpt = trim((string) preg_replace('/\s+/u', ' ', (string) ($row['body'] ?? '')));
            $excerpt = mb_strlen($excerpt, 'UTF-8') > self::EXCERPT ? mb_substr($excerpt, 0, self::EXCERPT, 'UTF-8') . '…' : $excerpt;
            echo '<li class="list-group-item"><a href="' . $e(Urls::post((int) $row['id'], $locale)) . '">' . $e($topic['title'] ?? 'Thema') . '</a>'
                . ' <span class="text-secondary">· ' . $e(self::date($row['created_at'])) . '</span><div class="text-secondary">' . $e($excerpt) . '</div></li>';
        }
        echo '</ul>';
        self::more($page->next, 'beitraege', $e);
    }

    private static function renderSubscriptions(int $userId, \Closure $e): void
    {
        $result = SubscriptionService::instance()->subscriptions($userId, is_string($_GET['nach'] ?? null) ? (string) $_GET['nach'] : null, self::PER_PAGE);
        $items = (array) ($result['items'] ?? []);
        if ($items === []) {
            echo '<p class="text-secondary">Du hast keine Abos. Auf einer Themenseite kannst du ein Thema abonnieren.</p>';

            return;
        }
        $locale = I18n::requestLocale();
        $topics = new TopicRepository();
        $forums = (new ForumRepository())->tree();
        $field = PluginDashboardRegistry::instance()->csrfField(self::SLUG);
        echo '<div class="table-responsive"><table class="table table-vcenter"><thead><tr><th scope="col">Ziel</th><th scope="col">Art</th><th scope="col"><span class="visually-hidden">Aktion</span></th></tr></thead><tbody>';
        // Eine Abfrage für alle Themen der Seite und eine für die Leserechte aller Foren (nicht je Abo).
        $topicRows = $topics->findMany(array_column(array_filter($items, static fn (array $item): bool => $item['target_type'] === 'topic'), 'target_id'));
        $forumIds = [];
        foreach ($items as $item) {
            $forumIds[] = $item['target_type'] === 'topic' ? (int) ($topicRows[(int) $item['target_id']]['forum_id'] ?? 0) : (int) $item['target_id'];
        }
        $readable = array_flip(CommunityAccess::instance()->filterReadable($userId, $forumIds));
        foreach ($items as $item) {
            $isTopic = $item['target_type'] === 'topic';
            $row = $isTopic ? ($topicRows[(int) $item['target_id']] ?? null) : ($forums[(int) $item['target_id']] ?? null);
            $allowed = $row !== null && isset($readable[$isTopic ? (int) $row['forum_id'] : (int) $item['target_id']]);
            $title = $allowed ? (string) $row['title'] : 'Nicht mehr verfügbar';
            $url = !$allowed ? null : ($isTopic ? Urls::topic((int) $row['id'], (string) ($row['slug'] ?? ''), $locale) : Urls::forum((string) $row['slug'], $locale));
            echo '<tr><td>' . ($url !== null ? '<a href="' . $e($url) . '">' . $e($title) . '</a>' : $e($title)) . '</td><td>' . ($isTopic ? 'Thema' : 'Forum') . '</td><td class="text-end">'
                . '<form method="post" action="/member/plugin/' . self::SLUG . '" class="d-inline">' . $field
                . '<input type="hidden" name="community_action" value="unsubscribe"><input type="hidden" name="type" value="' . $e($item['target_type']) . '"><input type="hidden" name="id" value="' . (int) $item['target_id'] . '">'
                . '<button type="submit" class="btn btn-sm">Abo beenden</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
        if (!empty($result['next'])) {
            echo '<p><a class="btn" href="/member/plugin/' . self::SLUG . '?tab=abos&amp;nach=' . $e(rawurlencode((string) $result['next'])) . '">Weiter</a></p>';
        }
    }

    private static function renderPreferences(int $userId, \Closure $e): void
    {
        $prefs = SubscriptionService::instance()->preferences($userId);
        $field = PluginDashboardRegistry::instance()->csrfField(self::SLUG);
        echo '<form method="post" action="/member/plugin/' . self::SLUG . '" class="vstack gap-3">' . $field . '<input type="hidden" name="community_action" value="save_preferences">';
        echo '<div><label class="form-label" for="cc-auto">Themen automatisch abonnieren</label><select class="form-select" id="cc-auto" name="auto_subscribe">';
        foreach (self::AUTO_LABELS as $value => $label) {
            echo '<option value="' . $e($value) . '"' . ($prefs['auto_subscribe'] === $value ? ' selected' : '') . '>' . $e($label) . '</option>';
        }
        echo '</select></div>';
        echo '<label class="form-check"><input class="form-check-input" type="checkbox" name="notify_mail" value="1"' . ($prefs['notify_mail'] ? ' checked' : '') . '><span class="form-check-label">Benachrichtigungen auch per E-Mail</span></label>';
        echo '<fieldset><legend class="form-label">Ich möchte benachrichtigt werden bei</legend>';
        foreach (self::TYPE_LABELS as $type => $label) {
            if (!array_key_exists($type, $prefs['notify_types'])) {
                continue;
            }
            echo '<label class="form-check"><input class="form-check-input" type="checkbox" name="notify_types[' . $e($type) . ']" value="1"' . (!empty($prefs['notify_types'][$type]) ? ' checked' : '') . '><span class="form-check-label">' . $e($label) . '</span></label>';
        }
        echo '</fieldset><div><label class="form-label" for="cc-locale">Sprache der Benachrichtigungen</label><select class="form-select" id="cc-locale" name="locale"><option value="">Wie die Website</option>';
        foreach (['de' => 'Deutsch', 'en' => 'English'] as $value => $label) {
            echo '<option value="' . $e($value) . '"' . ($prefs['locale'] === $value ? ' selected' : '') . '>' . $e($label) . '</option>';
        }
        echo '</select></div><div><button type="submit" class="btn btn-primary">Speichern</button></div></form>';
        $signature = ProfileService::instance()->signature($userId);
        echo '<form method="post" action="/member/plugin/' . self::SLUG . '" class="vstack gap-3 mt-4">' . $field . '<input type="hidden" name="community_action" value="save_signature">'
            . '<div><label class="form-label" for="cc-signature">' . $e(I18n::t('community.member.signature')) . '</label>'
            . '<textarea class="form-control" id="cc-signature" name="signature" rows="3" maxlength="' . ProfileService::SIGNATURE_MAX . '" aria-describedby="cc-signature-help">' . $e($signature) . '</textarea>'
            . '<div class="form-text" id="cc-signature-help">' . $e(I18n::t('community.member.signature_help', ['max' => ProfileService::SIGNATURE_MAX, 'lines' => ProfileService::SIGNATURE_LINES])) . '</div></div>'
            . '<div><button type="submit" class="btn">' . $e(I18n::t('community.member.signature_save')) . '</button>'
            . ' <a class="btn btn-link" href="' . $e(Urls::member($userId, I18n::requestLocale())) . '">' . $e(I18n::t('community.member.view_profile')) . '</a></div></form>';
    }

    private static function renderModeration(int $userId, \Closure $e): void
    {
        $field = PluginDashboardRegistry::instance()->csrfField(self::SLUG);
        $locale = I18n::requestLocale();
        $queue = ModerationService::instance()->queue($userId, null, 10);
        echo '<h3 class="h4">Freigaben</h3>';
        if (empty($queue['success'])) {
            echo '<p class="text-secondary">' . $e(self::errorText($queue)) . '</p>';
        } elseif (($queue['items'] ?? []) === []) {
            echo '<p class="text-secondary">Keine Beiträge in der Warteschlange.</p>';
        } else {
            foreach ((array) $queue['items'] as $post) {
                echo '<div class="card mb-3"><div class="card-body"><strong>' . $e($post['topic_title'] ?? '') . '</strong> <span class="text-secondary">· Mitglied #' . (int) ($post['user_id'] ?? 0) . ' · ' . $e(self::date($post['created_at'] ?? '')) . '</span>'
                    . '<pre class="bg-body-tertiary rounded p-2 my-2 text-wrap overflow-auto">' . $e($post['body'] ?? '') . '</pre>'
                    . '<form method="post" action="/member/plugin/' . self::SLUG . '" class="d-flex flex-wrap gap-2">' . $field
                    . '<input type="hidden" name="id" value="' . (int) $post['id'] . '"><label class="visually-hidden" for="cc-r' . (int) $post['id'] . '">Grund</label>'
                    . '<input class="form-control w-auto" id="cc-r' . (int) $post['id'] . '" name="reason" maxlength="500" placeholder="Grund (bei Ablehnung Pflicht)">'
                    . '<button class="btn btn-success" type="submit" name="community_action" value="approve_post">Freigeben</button>'
                    . '<button class="btn btn-outline-danger" type="submit" name="community_action" value="reject_post">Ablehnen</button></form></div></div>';
            }
            if (!empty($queue['has_more'])) {
                echo '<p class="text-secondary">Weitere Beiträge warten; die Liste zeigt die ältesten zehn.</p>';
            }
        }

        $reports = ReportService::instance()->listOpen($userId, null, 10);
        echo '<h3 class="h4 mt-4">Meldungen</h3>';
        if (empty($reports['success'])) {
            echo '<p class="text-secondary">' . $e(self::errorText($reports)) . '</p>';

            return;
        }
        if (($reports['items'] ?? []) === []) {
            echo '<p class="text-secondary">Keine offenen Meldungen.</p>';

            return;
        }
        $labels = ['post' => 'Beitrag', 'topic' => 'Thema', 'user' => 'Mitglied'];
        foreach ((array) $reports['items'] as $bundle) {
            $link = $bundle['target_type'] === 'post' ? '<a href="' . $e(Urls::post((int) $bundle['target_id'], $locale)) . '">ansehen</a>' : '';
            echo '<div class="card mb-3"><div class="card-body"><strong>' . $e($labels[$bundle['target_type']] ?? $bundle['target_type']) . ' #' . (int) $bundle['target_id'] . '</strong> '
                . $link . ' <span class="text-secondary">· ' . (int) $bundle['reports'] . ' Meldung(en) von ' . (int) $bundle['reporters'] . ' · seit ' . $e(self::date($bundle['first_at'])) . '</span>'
                . '<form method="post" action="/member/plugin/' . self::SLUG . '" class="d-flex flex-wrap gap-2 mt-2">' . $field
                . '<input type="hidden" name="community_action" value="resolve_report"><input type="hidden" name="id" value="' . (int) $bundle['first_id'] . '"><input type="hidden" name="up_to" value="' . (int) $bundle['last_id'] . '">'
                . '<label class="visually-hidden" for="cc-s' . (int) $bundle['first_id'] . '">Ergebnis</label><select class="form-select w-auto" id="cc-s' . (int) $bundle['first_id'] . '" name="status">'
                . '<option value="valid">Berechtigt (Maßnahme ergriffen)</option><option value="resolved">Erledigt</option><option value="invalid">Unbegründet</option></select>'
                . '<label class="visually-hidden" for="cc-n' . (int) $bundle['first_id'] . '">Notiz</label><input class="form-control w-auto" id="cc-n' . (int) $bundle['first_id'] . '" name="reason" maxlength="500" placeholder="Notiz (optional)">'
                . '<button class="btn btn-primary" type="submit">Erledigen</button></form></div></div>';
        }
    }
}
