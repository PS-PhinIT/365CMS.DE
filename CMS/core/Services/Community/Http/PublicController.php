<?php
/**
 * Community – öffentliche Leseseiten (PR #4, Teil 1): Startseite, Forum, Thema, Dauerlink eines Beitrags.
 *
 * Alles läuft über `CommunityAccess` (404 statt 403: ein nicht lesbares Forum oder Thema ist von einem fehlenden nicht zu unterscheiden).
 * Vorlagen: `themes/<aktiv>/community/<name>.php` zuerst, sonst `CMS/views/community/<name>.php`. Seiten mit Mitgliedsdaten sind
 * `private, no-store`; interne Foren erscheinen nie in einem öffentlichen Cache.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Http;

use CMS\Auth;
use CMS\Hooks;
use CMS\Services\Community\Repository\CategoryRepository;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\MemberRepository;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\PostService;
use CMS\Services\Community\Service\ReadStateService;
use CMS\Services\Community\Service\RenderService;
use CMS\Services\Community\Service\TopicService;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\ReadScope;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Urls;
use CMS\Services\Security\CaptchaService;
use CMS\ThemeManager;

if (!defined('ABSPATH')) {
    exit;
}

final class PublicController
{
    private const VIEWS = ['index', 'forum', 'topic', 'not-found', 'compose', 'listing', 'search', 'member'];

    private static ?self $instance = null;

    /**
     * Prüfstelle (nur Tests: Abfragebudget, Barrierefreiheit): statt Theme und Vorlage bekommt sie Ansicht, Variablen, Kopfdaten und Status
     * (`TESTS/community-performance`, `community-a11y`). Im Betrieb nie gesetzt.
     *
     * @var \Closure(string, array<string, mixed>, array<string, mixed>, int): void|null
     */
    private static ?\Closure $sink = null;

    /** @var array<string, mixed> Kopfdaten der aktuellen Seite (Titel, noindex, kanonische Adresse, Vor/Zurück) */
    private array $meta = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Setzt die Prüfstelle (nur Tests); `null` stellt die Ausgabe über Theme und Vorlage wieder her. */
    public static function setSink(?\Closure $sink): void
    {
        self::$sink = $sink;
    }

    public function registerRoutes(object $router): void
    {
        if (!method_exists($router, 'addRoute')) {
            return;
        }
        foreach (['de', 'en'] as $locale) {
            $p = Urls::routePatterns($locale);
            // Feste Adressen zuerst: `/thema/12/ungelesen` und `/forum/suche` passten sonst auf `topic_page` bzw. `forum`.
            $router->addRoute('GET', $p['unread'], fn (string $id) => $this->unread((int) $id));
            $router->addRoute('GET', $p['search'], fn () => $this->search());
            foreach (Urls::LISTINGS as $kind) {
                $router->addRoute('GET', $p['list_' . $kind], fn () => $this->listing($kind));
            }
            $router->addRoute('GET', $p['member'], fn (string $id) => $this->member($id));
            $router->addRoute('GET', $p['topic'], fn (string $ref) => $this->topic($ref, null));
            $router->addRoute('GET', $p['topic_page'], fn (string $ref, string $page) => $this->topic($ref, $page));
            $router->addRoute('GET', $p['post'], fn (string $id) => $this->post($id));
            $router->addRoute('GET', $p['index'], fn () => $this->index());
            $router->addRoute('GET', $p['forum'], fn (string $slug) => $this->forum($slug, null));
            $router->addRoute('GET', $p['forum_page'], fn (string $slug, string $page) => $this->forum($slug, $page));
        }
    }

    // ── Seiten ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function index(): void
    {
        // Ein Lesebereich je Seitenanfrage: Forum und Thema werden nur einmal gelesen (`Support\ReadScope`).
        ReadScope::run(fn () => $this->indexPage());
    }

    private function indexPage(): void
    {
        $locale = I18n::requestLocale();
        $viewer = $this->viewerId();
        $access = CommunityAccess::instance();
        $forums = (new ForumRepository())->tree();
        $readable = array_flip($access->filterReadable($viewer, array_map('intval', array_keys($forums))));

        $groups = [];
        foreach ((new CategoryRepository())->listVisible() as $category) {
            $groups[(int) $category['id']] = ['category' => $category, 'forums' => []];
        }
        $groups[0] ??= ['category' => null, 'forums' => []];
        foreach ($forums as $id => $forum) {
            if (!isset($readable[(int) $id]) || (int) ($forum['category_visible'] ?? 1) !== 1 || ($forum['status'] ?? '') === 'hidden') {
                continue;
            }
            $categoryId = (int) $forum['category_id'];
            if (isset($groups[$categoryId])) {
                $groups[$categoryId]['forums'][] = $forum;
            }
        }
        $groups = array_filter($groups, static fn (array $group): bool => $group['forums'] !== []);

        $this->meta = [
            'title' => $this->siteTitle($locale), 'canonical' => Urls::index($locale), 'noindex' => $groups === [],
            'description' => $locale === 'en' ? 'Community forum: ask questions, find answers and join the discussion.' : 'Community-Forum: Fragen stellen, Antworten finden und mitdiskutieren.',
        ];
        $this->render('index', ['groups' => $groups, 'locale' => $locale, 'viewer' => $viewer]);
    }

    public function forum(string $slug, ?string $pageSegment): void
    {
        // Ein Lesebereich je Seitenanfrage: Forum und Thema werden nur einmal gelesen (`Support\ReadScope`).
        ReadScope::run(fn () => $this->forumPage($slug, $pageSegment));
    }

    private function forumPage(string $slug, ?string $pageSegment): void
    {
        $locale = I18n::requestLocale();
        $repo = new ForumRepository();
        $forum = $repo->findBySlug($slug, $locale);
        if ($forum === null || ($pageSegment !== null && Urls::parsePageSegment($pageSegment, $locale) === null)) {
            $this->notFound();

            return;
        }
        $viewer = $this->viewerId();
        $forumId = (int) $forum['id'];
        $topics = TopicService::instance();
        $cursor = is_string($_GET['nach'] ?? null) ? (string) $_GET['nach'] : null;
        $result = $topics->listForum($viewer, $forumId, $cursor);
        if (empty($result['success'])) {
            $this->notFound();

            return;
        }
        $items = (array) $result['items'];
        $accounts = $this->accounts(array_map(static fn (array $t): int => (int) ($t['user_id'] ?? 0), $items));
        $own = Urls::forumSlugFor((string) $forum['slug'], $forum['slug_en'] ?? null, $locale);
        $canonical = Urls::forum($own, $locale);
        $this->meta = [
            'title' => $this->localized($forum, 'title', $locale) . ' – ' . $this->siteTitle($locale),
            'canonical' => $canonical,
            // Keyset statt Seitenzahl: Folgeseiten und leere Foren nicht indizieren.
            'noindex' => $cursor !== null || $items === [] || !$this->isPublic($forumId),
            'description' => self::excerpt($this->localized($forum, 'description', $locale)) ?: ($locale === 'en' ? 'Topics and questions in the forum “' . $this->localized($forum, 'title', $locale) . '”.' : 'Themen und Fragen im Forum „' . $this->localized($forum, 'title', $locale) . '“.'),
        ];
        $children = [];
        foreach ($repo->tree() as $id => $child) {
            if ((int) ($child['parent_id'] ?? 0) === $forumId && CommunityAccess::instance()->filterReadable($viewer, [(int) $id]) !== []) {
                $children[] = $child;
            }
        }
        $canCreate = $viewer === null || CommunityAccess::instance()->can($viewer, 'create_topic', ['forum_id' => $forumId])->allowed;
        $this->render('forum', [
            'can_create' => $canCreate, 'new_topic_url' => Urls::newTopic($own, $locale),
            'forum' => $forum, 'children' => $children, 'topics' => $items, 'accounts' => $accounts, 'next' => $result['next'] ?? null,
            'locale' => $locale, 'viewer' => $viewer, 'canonical' => $canonical,
        ]);
    }

    public function topic(string $ref, ?string $pageSegment): void
    {
        // Ein Lesebereich je Seitenanfrage: Forum und Thema werden nur einmal gelesen (`Support\ReadScope`).
        ReadScope::run(fn () => $this->topicPage($ref, $pageSegment));
    }

    private function topicPage(string $ref, ?string $pageSegment): void
    {
        $locale = I18n::requestLocale();
        $parsed = Urls::parseSegment($ref);
        $page = 1;
        if ($pageSegment !== null) {
            $page = Urls::parsePageSegment($pageSegment, $locale) ?? 0;
        }
        if ($parsed === null || $page < 1) {
            $this->notFound();

            return;
        }
        $viewer = $this->viewerId();
        $topicService = TopicService::instance();
        $topic = $topicService->get($viewer, $parsed['id']);
        if ($topic === null) {
            $this->notFound();

            return;
        }
        // Verweis-Thema nach dem Verschieben: dauerhaft zum Ziel.
        if (($topic['status'] ?? '') === 'moved' && (int) ($topic['moved_to_topic_id'] ?? 0) > 0) {
            $target = $topicService->get($viewer, (int) $topic['moved_to_topic_id']);
            if ($target === null) {
                $this->notFound();

                return;
            }
            $this->redirect(Urls::topic((int) $target['id'], (string) ($target['slug'] ?? ''), $locale), 301);
        }
        $canonical = Urls::topic((int) $topic['id'], (string) ($topic['slug'] ?? ''), $locale, $page);
        // Falscher Slug: 301 über die ID.
        if ($parsed['slug'] !== rawurlencode((string) ($topic['slug'] ?? '')) && $parsed['slug'] !== (string) ($topic['slug'] ?? '')) {
            $this->redirect($canonical, 301);
        }

        $posts = PostService::instance();
        $after = $posts->cursorForPage((int) $topic['id'], $page);
        if ($after === null) {
            $this->notFound();

            return;
        }
        $list = $posts->listPage($viewer, (int) $topic['id'], $after);
        if (empty($list['success'])) {
            $this->notFound();

            return;
        }
        $items = RenderService::instance()->present($viewer, (array) $list['items'], $locale);
        $authorIds = array_map(static fn (array $p): int => (int) ($p['user_id'] ?? 0), $items);
        $accounts = $this->accounts($authorIds);
        $signatures = \CMS\Services\Community\Service\ProfileService::instance()->signatures($authorIds);

        if ($viewer !== null && $items !== []) {
            try {
                $ids = array_map(static fn (array $p): int => (int) $p['id'], array_filter($items, static fn (array $p): bool => ($p['view'] ?? '') === 'full'));
                ReadStateService::instance()->markTopicPageRead($viewer, (int) $topic['id'], $ids);
            } catch (\Throwable) {
            }
        }

        $perPage = max(1, Settings::getInstance()->int('posts_per_page'));
        $pages = max(1, (int) ceil(((int) $topic['reply_count'] + 1) / $perPage));
        $forum = (new ForumRepository())->find((int) $topic['forum_id']);
        $public = $this->isPublic((int) $topic['forum_id']);
        $this->meta = [
            'title' => (string) $topic['title'] . ' – ' . $this->siteTitle($locale),
            'canonical' => $canonical,
            'noindex' => !$public || ($topic['status'] ?? '') !== 'published',
            // Beschreibung für Suchmaschinen und Link-Vorschauen: Anfang des ersten gezeigten Beitrags (nur Beiträge, die die Seite im Volltext zeigt)
            'description' => self::excerpt((string) (array_values(array_filter($items, static fn (array $p): bool => ($p['view'] ?? '') === 'full'))[0]['html'] ?? '')),
            'prev' => $page > 1 ? Urls::topic((int) $topic['id'], (string) $topic['slug'], $locale, $page - 1) : null,
            'next' => $page < $pages ? Urls::topic((int) $topic['id'], (string) $topic['slug'], $locale, $page + 1) : null,
        ];
        // Strukturierte Daten (schema.org) nur für die erste Seite eines öffentlichen, veröffentlichten Themas
        if ($page === 1 && empty($this->meta['noindex'])) {
            $this->meta['jsonld'] = self::structuredData($topic, $forum, $items, $accounts, $canonical);
        }
        $access = CommunityAccess::instance();
        $canReply = $viewer === null || $access->can($viewer, 'reply', ['topic_id' => (int) $topic['id']])->allowed;
        $editable = [];
        if ($viewer !== null) {
            foreach ($items as $item) {
                if (($item['view'] ?? '') === 'full' && $access->can($viewer, 'edit', ['post_id' => (int) $item['id']])->allowed) {
                    $editable[(int) $item['id']] = true;
                }
            }
        }
        $reactionService = \CMS\Services\Community\Service\ReactionService::instance();
        $subscribed = false;
        $canModerate = false;
        $canSolve = false;
        if ($viewer !== null) {
            try {
                $subscribed = \CMS\Services\Community\Service\SubscriptionService::instance()->status($viewer, 'topic', (int) $topic['id']) !== null;
            } catch (\Throwable) {
            }
            $canModerate = $access->can($viewer, 'moderate', ['topic_id' => (int) $topic['id']])->allowed;
            $canSolve = $access->can($viewer, 'mark_solution', ['topic_id' => (int) $topic['id']])->allowed;
        }
        $this->render('topic', [
            'reactions' => ['available' => $reactionService->available(), 'emoji' => \CMS\Services\Community\Service\ReactionService::EMOJI, 'summaries' => $reactionService->summaries($viewer, $items)],
            'subscribed' => $subscribed, 'can_moderate' => $canModerate, 'can_solve' => $canSolve,
            'can_reply' => $canReply, 'editable' => $editable,
            'topic' => $topic, 'forum' => $forum, 'posts' => $items, 'accounts' => $accounts, 'signatures' => $signatures, 'page' => $page, 'pages' => $pages,
            'locale' => $locale, 'viewer' => $viewer, 'canonical' => $canonical,
        ]);
    }

    /** Globale Listen `/forum/neu`, `/forum/aktiv`, `/forum/unbeantwortet` über alle lesbaren Foren (Keyset über `?nach=`). */
    public function listing(string $kind): void
    {
        // Ein Lesebereich je Seitenanfrage: Forum und Thema werden nur einmal gelesen (`Support\ReadScope`).
        ReadScope::run(fn () => $this->listingPage($kind));
    }

    private function listingPage(string $kind): void
    {
        $locale = I18n::requestLocale();
        $viewer = $this->viewerId();
        $cursor = is_string($_GET['nach'] ?? null) ? (string) $_GET['nach'] : null;
        $result = TopicService::instance()->listGlobal($viewer, $kind, $cursor);
        if (empty($result['success'])) {
            $this->notFound();

            return;
        }
        $items = (array) $result['items'];
        $forums = (new ForumRepository())->tree();
        $titles = ['new' => 'Neue Themen', 'active' => 'Aktive Themen', 'unanswered' => 'Unbeantwortete Themen'];
        $canonical = Urls::listing($kind, $locale);
        // Listen sind ein Überblick, kein eigener Inhalt: nicht indizieren.
        $this->meta = ['title' => $titles[$kind] . ' – Community', 'canonical' => $canonical, 'noindex' => true];
        $this->render('listing', [
            'kind' => $kind, 'heading' => $titles[$kind], 'topics' => $items, 'forums' => $forums,
            'accounts' => $this->accounts(array_map(static fn (array $t): int => (int) ($t['user_id'] ?? 0), $items)),
            'next' => $result['next'] ?? null, 'locale' => $locale, 'viewer' => $viewer, 'canonical' => $canonical,
        ]);
    }

    /** Suche `/forum/suche?q=…&forum=…&seite=N` (nur GET; Rechte, Drossel und Gäste-Regeln entscheidet `CommunitySearch`). */
    public function search(): void
    {
        // Ein Lesebereich je Seitenanfrage: Forum und Thema werden nur einmal gelesen (`Support\ReadScope`).
        ReadScope::run(fn () => $this->searchPage());
    }

    private function searchPage(): void
    {
        $locale = I18n::requestLocale();
        $viewer = $this->viewerId();
        $query = is_string($_GET['q'] ?? null) ? trim((string) $_GET['q']) : '';
        $forumId = is_string($_GET['forum'] ?? null) && preg_match('/^[1-9]\d{0,9}$/D', (string) $_GET['forum']) === 1 ? (int) $_GET['forum'] : 0;
        $page = is_string($_GET['seite'] ?? null) && preg_match('/^[1-9]\d{0,3}$/D', (string) $_GET['seite']) === 1 ? (int) $_GET['seite'] : 1;
        $result = null;
        if ($query !== '') {
            $filters = $forumId > 0 ? ['forum_id' => $forumId] : [];
            $result = \CMS\Services\Community\Service\CommunitySearch::instance()->search($viewer, $query, $filters, $page);
        }
        // Suchergebnisse gehören nicht in einen Suchindex (Gleichheit mit den Listen).
        $this->meta = ['title' => 'Suche – Community', 'canonical' => Urls::search($locale), 'noindex' => true];
        $forums = [];
        $access = CommunityAccess::instance();
        $readable = array_flip($access->readableForumIds($viewer));
        foreach ((new ForumRepository())->tree() as $id => $forum) {
            if (isset($readable[(int) $id])) {
                $forums[(int) $id] = $forum;
            }
        }
        $this->render('search', ['query' => $query, 'forum_id' => $forumId, 'forums' => $forums, 'result' => $result, 'page' => $page, 'locale' => $locale, 'viewer' => $viewer]);
    }

    /** Öffentliches Profil `/forum/mitglieder/{id}` (`Service\ProfileService`): nur aktive Konten mit Beiträgen, Themen nur aus lesbaren Foren. */
    public function member(string $id): void
    {
        ReadScope::run(fn () => $this->memberPage($id));
    }

    private function memberPage(string $id): void
    {
        $locale = I18n::requestLocale();
        $viewer = $this->viewerId();
        $userId = preg_match('/^[1-9]\d{0,9}$/D', $id) === 1 ? (int) $id : 0;
        try {
            $profile = $userId > 0 && $userId <= 4294967295 ? \CMS\Services\Community\Service\ProfileService::instance()->profile($viewer, $userId, $locale) : null;
        } catch (\Throwable) {
            $profile = null;
        }
        if ($profile === null) {
            $this->notFound();

            return;
        }
        // Profile gehören nicht in einen Suchindex (Mitgliedsdaten, Gleichheit mit den Listen).
        $this->meta = ['title' => $profile['name'] . ' – ' . $this->siteTitle($locale), 'canonical' => Urls::member($userId, $locale), 'noindex' => true];
        $this->render('member', ['profile' => $profile, 'locale' => $locale, 'viewer' => $viewer]);
    }

    /** `/forum/thema/{id}/ungelesen`: zum ersten ungelesenen Beitrag, sonst zum Thema. Gäste und nicht lesbare Themen: wie das Thema. */
    public function unread(int $id): void
    {
        $locale = I18n::requestLocale();
        $viewer = $this->viewerId();
        $topic = TopicService::instance()->get($viewer, $id);
        if ($topic === null) {
            $this->notFound();

            return;
        }
        $postId = $viewer === null ? null : ReadStateService::instance()->firstUnreadPostId($viewer, $id);
        if ($postId === null) {
            $this->redirect(Urls::topic((int) $topic['id'], (string) ($topic['slug'] ?? ''), $locale), 302);
        }
        $page = PostService::instance()->pageOf((int) $topic['id'], $postId);
        $this->redirect(Urls::topic((int) $topic['id'], (string) ($topic['slug'] ?? ''), $locale, $page) . '#post-' . $postId, 302);
    }

    /** Dauerlink `/forum/beitrag/{id}`: weiter zu Thema, Seite und Anker. */
    public function post(string $id): void
    {
        $locale = I18n::requestLocale();
        $postId = (int) $id;
        $viewer = $this->viewerId();
        $post = $postId > 0 ? (new \CMS\Services\Community\Repository\PostRepository())->find($postId) : null;
        $topic = $post === null ? null : TopicService::instance()->get($viewer, (int) $post['topic_id']);
        if ($post === null || $topic === null || !CommunityAccess::instance()->can($viewer, 'read', ['post_id' => $postId])->allowed) {
            $this->notFound();

            return;
        }
        $page = PostService::instance()->pageOf((int) $topic['id'], $postId);
        $this->redirect(Urls::topic((int) $topic['id'], (string) ($topic['slug'] ?? ''), $locale, $page) . '#post-' . $postId, 302);
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /** Für `ComposeController`: Seite mit Kopfdaten ausgeben (gleiche Hülle, Cache-Kopf und Vorlagenwahl wie die Leseseiten). */
    public function renderPage(string $view, array $vars, array $meta, int $status = 200): void
    {
        $this->meta = $meta;
        if ($status !== 200) {
            http_response_code($status);
        }
        $this->render($view, $vars, $status);
    }

    /** Für `ComposeController`: 404 wie bei den Leseseiten. */
    public function showNotFound(): void
    {
        $this->notFound();
    }

    public function currentViewerId(): ?int
    {
        return $this->viewerId();
    }

    /** Texte von `community.js` (`data-cc-messages`), Schlüssel ohne das Präfix `community.js.`. */
    public const JS_MESSAGES = ['offline', 'expired', 'unexpected', 'subscribed', 'unsubscribed', 'report_title', 'reason', 'reason_required', 'note', 'reported'];

    /** @return array{url: string, token: string, reasons: string, reason_required: bool, messages: string}|null */
    private function apiInfo(): ?array
    {
        if ($this->viewerId() === null) {
            return null;
        }
        $reasons = [];
        foreach (\CMS\Services\Community\Service\ReportService::REASONS as $reason) {
            $reasons[$reason] = I18n::t('community.report.reason.' . $reason);
        }
        $messages = [];
        foreach (self::JS_MESSAGES as $key) {
            $messages[$key] = I18n::t('community.js.' . $key);
        }

        return [
            'url' => Urls::apiBase(I18n::requestLocale()),
            'token' => \CMS\Security::instance()->generateToken(ApiController::TOKEN_ACTION),
            'reasons' => (string) json_encode($reasons, JSON_UNESCAPED_UNICODE),
            'reason_required' => Settings::getInstance()->bool('moderation_reason_required'),
            'messages' => (string) json_encode($messages, JSON_UNESCAPED_UNICODE),
        ];
    }

    private function viewerId(): ?int
    {
        try {
            $id = (int) (Auth::instance()->currentUser()->id ?? 0);
        } catch (\Throwable) {
            $id = 0;
        }

        return $id > 0 ? $id : null;
    }

    /** Darf ein Gast das Forum lesen? Nur dann gehört es in Suche, Sitemap und öffentlichen Cache. */
    private function isPublic(int $forumId): bool
    {
        return CommunityAccess::instance()->can(null, 'read', ['forum_id' => $forumId])->allowed;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function accounts(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        try {
            return $ids === [] ? [] : (new MemberRepository())->accounts($ids);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $row */
    private function localized(array $row, string $field, string $locale): string
    {
        $en = $row[$field . '_en'] ?? null;

        return $locale === 'en' && is_string($en) && $en !== '' ? $en : (string) ($row[$field] ?? '');
    }

    /**
     * Kurzbeschreibung aus gereinigtem HTML oder Text für `<meta name="description">`: ohne Markup und Zeilenumbrüche, höchstens
     * `$max` Zeichen, an einer Wortgrenze gekürzt. Leer, wenn nichts übrig bleibt.
     */
    private static function excerpt(string $text, int $max = 155): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1, 'UTF-8');
        $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($space !== false && $space > (int) ($max * 0.6)) {
            $cut = mb_substr($cut, 0, $space, 'UTF-8');
        }

        return rtrim($cut, " \t.,;:–-") . '…';
    }

    /**
     * schema.org-Daten einer Themenseite: `QAPage` mit `Question` in Q&A-Foren, sonst `DiscussionForumPosting`. Nur Angaben, die die Seite selbst
     * öffentlich zeigt (Titel, Anfang des ersten Beitrags, Datum, Anzeigename, Zahl der Antworten); eine als Lösung markierte Antwort nur, wenn sie
     * auf dieser Seite steht (keine zusätzliche Abfrage). Kein Konto, keine ID, keine IP-Kennung. `null`, wenn der erste Beitrag nicht im Volltext steht.
     *
     * @param array<string, mixed> $topic
     * @param array<string, mixed>|null $forum
     * @param list<array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $accounts
     * @return array<string, mixed>|null
     */
    private static function structuredData(array $topic, ?array $forum, array $items, array $accounts, string $canonical): ?array
    {
        $first = null;
        foreach ($items as $item) {
            if (($item['view'] ?? '') === 'full' && !empty($item['is_first_post'])) {
                $first = $item;
                break;
            }
        }
        if ($first === null) {
            return null;
        }
        $iso = static fn (mixed $utc): ?string => is_string($utc) && $utc !== '' ? str_replace(' ', 'T', $utc) . 'Z' : null;
        $author = static function (array $post) use ($accounts): ?array {
            $account = $accounts[(int) ($post['user_id'] ?? 0)] ?? null;
            $name = $account === null ? '' : (string) ($account['display_name'] ?: $account['username']);

            return $name === '' ? null : ['@type' => 'Person', 'name' => $name];
        };
        $url = Urls::absolute($canonical);
        $replies = max(0, (int) ($topic['reply_count'] ?? 0));
        $question = array_filter([
            '@type' => $forum !== null && ($forum['type'] ?? '') === 'qa' ? 'Question' : 'DiscussionForumPosting',
            'name' => (string) $topic['title'],
            'headline' => (string) $topic['title'],
            'url' => $url,
            'text' => self::excerpt((string) ($first['html'] ?? ''), 500),
            'datePublished' => $iso($first['created_at'] ?? $topic['created_at'] ?? null),
            'dateModified' => $iso($topic['last_post_at'] ?? null),
            'author' => $author($first),
            'commentCount' => $replies,
            'answerCount' => $replies,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
        if (($question['@type'] ?? '') === 'Question') {
            unset($question['headline'], $question['commentCount']);
            $solutionId = (int) ($topic['solution_post_id'] ?? 0);
            foreach ($items as $item) {
                if ($solutionId > 0 && (int) ($item['id'] ?? 0) === $solutionId && ($item['view'] ?? '') === 'full') {
                    $question['acceptedAnswer'] = array_filter([
                        '@type' => 'Answer',
                        'text' => self::excerpt((string) ($item['html'] ?? ''), 500),
                        'url' => Urls::absolute(Urls::post($solutionId, 'de')),
                        'dateCreated' => $iso($item['created_at'] ?? null),
                        'author' => $author($item),
                    ], static fn (mixed $value): bool => $value !== null && $value !== '');
                }
            }

            return ['@context' => 'https://schema.org', '@type' => 'QAPage', 'mainEntity' => $question];
        }
        unset($question['name'], $question['answerCount']);

        return ['@context' => 'https://schema.org'] + $question;
    }

    private function siteTitle(string $locale): string
    {
        return $locale === 'en' ? 'Community' : 'Community';
    }

    private function redirect(string $path, int $status): never
    {
        header('Location: ' . $path, true, $status);
        exit;
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->meta = ['title' => 'Nicht gefunden', 'noindex' => true];
        $this->render('not-found', [], 404);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(string $view, array $vars, int $status = 200): void
    {
        if (!in_array($view, self::VIEWS, true)) {
            $view = 'not-found';
        }
        // Angemeldete Mitglieder erhalten die JSON-API (Reaktion, Abo, Melden, Vorschau, Moderation): Basisadresse und dauerhaftes Token.
        $vars['api'] = $this->apiInfo();
        $this->meta['js'] = $vars['api'] !== null;
        // Visueller Editor im Schreibformular (SunEditor, lokal): neu und Antwort visuell, Bearbeiten zuerst als Markdown (verlustfrei).
        $editable = $view === 'compose' && $vars['api'] !== null && empty($vars['readonly']);
        $vars['editor'] = $editable ? [
            'mode' => ($vars['mode'] ?? '') === 'edit' ? 'markdown' : 'visual',
            'css' => cms_asset_url('suneditor/css/suneditor.min.css'),
            'js' => cms_asset_url('suneditor/suneditor.min.js'),
            'lang' => cms_asset_url('suneditor/lang/de.js'),
        ] : null;
        $this->meta['editor'] = $vars['editor'] !== null;
        // Captcha im Schreibformular (Core-Dienst): Skript und Stil nur, wenn das Formular eine Aufgabe enthält.
        $this->meta['captcha'] = $view === 'compose' && !empty($vars['captcha']);
        $meta = $this->meta;
        // Hinweis aus einem Schreibvorgang (z. B. „wartet auf Freigabe“) einmal anzeigen.
        $vars['flash'] = $_SESSION['community_flash'] ?? null;
        unset($_SESSION['community_flash']);
        if (self::$sink !== null) {
            (self::$sink)($view, $vars, $meta, $status);

            return;
        }
        Hooks::addAction('head', static function () use ($meta): void {
            echo self::headTags($meta);
        }, 5);
        // Seiten mit Mitgliedsdaten oder Formularen nicht gemeinsam zwischenspeichern.
        if (!headers_sent()) {
            header('Cache-Control: private, no-store');
            if (!empty($meta['noindex'])) {
                header('X-Robots-Tag: noindex, follow');
            }
        }
        $theme = ThemeManager::instance();
        $theme->getHeader(['title' => (string) ($meta['title'] ?? 'Community'), 'error_code' => $status === 404 ? 404 : null]);
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        extract($vars, EXTR_SKIP);
        include $this->viewPath($view);
        $theme->getFooter();
    }

    /** @param array<string, mixed> $meta */
    private static function headTags(array $meta): string
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $out = '<link rel="stylesheet" href="' . $e(cms_asset_url('css/community.css')) . '">' . "\n";
        if (!empty($meta['js'])) {
            $out .= '<script type="module" src="' . $e(cms_asset_url('js/community/community.js')) . '"></script>' . "\n";
        }
        if (!empty($meta['editor'])) {
            $out .= '<script type="module" src="' . $e(cms_asset_url('js/community/editor.js')) . '"></script>' . "\n";
        }
        if (!empty($meta['captcha'])) {
            $out .= '<link rel="stylesheet" href="' . $e(cms_asset_url(CaptchaService::assetPaths()['css'])) . '">' . "\n";
            $out .= '<script type="module" src="' . $e(cms_asset_url(CaptchaService::assetPaths()['js'])) . '"></script>' . "\n";
        }
        if (is_array($meta['jsonld'] ?? null) && empty($meta['noindex'])) {
            // Datenblock (kein Skript): das CSP des CMS führt ihn nicht aus; `<`, `>` und `&` sind maskiert, ein `</script>` im Text bricht nicht aus
            $json = json_encode($meta['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (is_string($json)) {
                $out .= '<script type="application/ld+json">' . $json . '</script>' . "\n";
            }
        }
        if (!empty($meta['description']) && empty($meta['noindex'])) {
            $out .= '<meta name="description" content="' . $e((string) $meta['description']) . '">' . "\n";
        }
        if (!empty($meta['noindex'])) {
            $out .= '<meta name="robots" content="noindex, follow">' . "\n";
        }
        if (!empty($meta['canonical'])) {
            $out .= '<link rel="canonical" href="' . $e(Urls::absolute((string) $meta['canonical'])) . '">' . "\n";
        }
        foreach (['prev', 'next'] as $rel) {
            if (!empty($meta[$rel])) {
                $out .= '<link rel="' . $rel . '" href="' . $e(Urls::absolute((string) $meta[$rel])) . '">' . "\n";
            }
        }

        return $out;
    }

    private function viewPath(string $view): string
    {
        try {
            $themePath = ThemeManager::instance()->getThemePath();
        } catch (\Throwable) {
            $themePath = '';
        }
        if ($themePath !== '') {
            $candidate = rtrim($themePath, '/\\') . '/community/' . $view . '.php';
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return ABSPATH . 'views/community/' . $view . '.php';
    }
}
