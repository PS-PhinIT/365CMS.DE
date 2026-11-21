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
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Urls;
use CMS\ThemeManager;

if (!defined('ABSPATH')) {
    exit;
}

final class PublicController
{
    private const VIEWS = ['index', 'forum', 'topic', 'not-found', 'compose'];

    private static ?self $instance = null;

    /** @var array<string, mixed> Kopfdaten der aktuellen Seite (Titel, noindex, kanonische Adresse, Vor/Zurück) */
    private array $meta = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function registerRoutes(object $router): void
    {
        if (!method_exists($router, 'addRoute')) {
            return;
        }
        foreach (['de', 'en'] as $locale) {
            $p = Urls::routePatterns($locale);
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

        $this->meta = ['title' => $this->siteTitle($locale), 'canonical' => Urls::index($locale), 'noindex' => $groups === []];
        $this->render('index', ['groups' => $groups, 'locale' => $locale, 'viewer' => $viewer]);
    }

    public function forum(string $slug, ?string $pageSegment): void
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
            'prev' => $page > 1 ? Urls::topic((int) $topic['id'], (string) $topic['slug'], $locale, $page - 1) : null,
            'next' => $page < $pages ? Urls::topic((int) $topic['id'], (string) $topic['slug'], $locale, $page + 1) : null,
        ];
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
        $this->render('topic', [
            'can_reply' => $canReply, 'editable' => $editable,
            'topic' => $topic, 'forum' => $forum, 'posts' => $items, 'accounts' => $accounts, 'page' => $page, 'pages' => $pages,
            'locale' => $locale, 'viewer' => $viewer, 'canonical' => $canonical,
        ]);
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
        $meta = $this->meta;
        // Hinweis aus einem Schreibvorgang (z. B. „wartet auf Freigabe“) einmal anzeigen.
        $vars['flash'] = $_SESSION['community_flash'] ?? null;
        unset($_SESSION['community_flash']);
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
