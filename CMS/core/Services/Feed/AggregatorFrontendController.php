<?php
/**
 * Öffentliche Seiten des Feed-Aggregators (ehemals Plugin cms-feed).
 *
 * Routen (deutsch, `/en/…` löst der Router auf):
 * - `/<archiv-slug>` (Standard `/feeds`): alle öffentlichen Bereiche mit Suche und Seitenblättern,
 * - `/<archiv-slug>/embed`: dieselbe Liste als schlanke Einbettungsseite ohne Theme-Rahmen (iframe nur auf
 *   derselben Website: `X-Frame-Options: SAMEORIGIN`, `frame-ancestors 'self'`),
 * - `/feed/<bereich>` und `/<archiv-slug>/<bereich>`: Bereichsseite im Layout des Bereichs (Raster, Liste, Magazin).
 *
 * - `/admin/feeds[?tab=…]`: frühere Plugin-Verwaltung, 301 auf die passende Unterseite von Einstellungen → Feeds.
 *
 * `/feed` selbst bleibt der RSS-Ausgang des Cores (`FeedService`). Ist „Einwilligung erforderlich“ aktiv, erscheinen
 * die Artikel erst nach Zustimmung zu „Externe Medien“ bzw. zum Dienst `cms_feed` (Vorschaubilder kommen von fremden
 * Servern). Inhalte kommen aus `views/feeds/{archive,category,card,consent,embed}.php`, Theme-Override:
 * `themes/<slug>/feeds/<name>.php`.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Feed;

use CMS\Logger;
use CMS\Router;
use CMS\Security;
use CMS\Services\CookieConsentService;
use CMS\ThemeManager;

if (!defined('ABSPATH')) {
    exit;
}

final class AggregatorFrontendController
{
    public const CONSENT_SERVICE = 'cms_feed';
    public const CONSENT_CATEGORY = 'external_media';
    public const VIEWS = ['archive', 'category', 'card', 'consent', 'embed'];

    private static ?self $instance = null;
    private bool $assetsNeeded = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /** Frühere Unterseiten der Plugin-Verwaltung `/admin/feeds?tab=…` → Unterseiten unter Einstellungen → Feeds. */
    private const LEGACY_ADMIN_SECTIONS = [
        'dashboard' => '', 'channels' => 'sources', 'categories' => 'categories', 'items' => 'items',
        'digests' => 'digests', 'catalog' => 'import', 'settings' => 'settings',
    ];

    public function registerRoutes(object $router): void
    {
        $router->addRoute('GET', '/admin/feeds', fn () => $this->redirectLegacyAdmin());

        if (!AggregatorSettings::isEnabled()) {
            return;
        }

        $slug = AggregatorSettings::archiveSlug();
        $router->addRoute('GET', '/' . $slug, fn () => $this->archive(false));
        $router->addRoute('GET', '/' . $slug . '/embed', fn () => $this->archive(true));
        $router->addRoute('GET', '/feed/:catSlug', fn (string $catSlug) => $this->category($catSlug));
        $router->addRoute('GET', '/' . $slug . '/:catSlug', fn (string $catSlug) => $this->category($catSlug));
    }

    public function redirectLegacyAdmin(): void
    {
        $tab = is_scalar($_GET['tab'] ?? null) ? (string) $_GET['tab'] : '';
        $section = self::LEGACY_ADMIN_SECTIONS[$tab] ?? '';
        $target = '/admin/settings?tab=feeds' . ($section !== '' ? '&section=' . $section : '');
        if ($section === 'settings' && is_scalar($_GET['stab'] ?? null) && in_array((string) $_GET['stab'], ['general', 'design'], true)) {
            $target .= '&stab=' . (string) $_GET['stab'];
        }
        Router::instance()->redirect($target, 301);
    }

    /** Liegt der aktuelle Pfad im Aggregator (für Assets)? */
    public function assetsNeeded(): bool
    {
        return $this->assetsNeeded;
    }

    public function archive(bool $embed): void
    {
        $repo = AggregatorRepository::getInstance();
        if (!$repo->isReady()) {
            $this->notFound();

            return;
        }

        $lang = FeedI18n::requestLocale();
        $settings = $this->localizedSettings($lang);
        $archivePath = self::archivePath($lang);
        if (!self::hasConsent()) {
            $this->renderConsent($lang, (string) $settings['archive_title'], $embed);

            return;
        }

        $search = self::searchTerm();
        $filters = ['public' => true];
        if ($search !== '') {
            $filters['search'] = $search;
        }
        $perPage = (int) $settings['per_page'];
        [$page, $pages, $total, $items] = $this->page($filters, $perPage);

        $vars = [
            'settings' => $settings,
            'categories' => $this->publicCategories($lang),
            'items' => $items,
            'search' => $search,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'archive_url' => $archivePath . ($embed ? '/embed' : ''),
            'archive_path' => $archivePath,
            'prev_url' => $page > 1 ? self::pageUrl($archivePath . ($embed ? '/embed' : ''), $search, $page - 1) : '',
            'next_url' => $page < $pages ? self::pageUrl($archivePath . ($embed ? '/embed' : ''), $search, $page + 1) : '',
            'lang' => $lang,
            'embed' => $embed,
        ];

        if ($embed) {
            $this->renderEmbed($vars, (string) $settings['archive_title'], $lang);

            return;
        }
        $this->renderPage('archive', (string) $settings['archive_title'], $vars, (string) $settings['archive_description'], $search !== '' || $page > 1);
    }

    public function category(string $catSlug): void
    {
        $repo = AggregatorRepository::getInstance();
        if (!$repo->isReady()) {
            $this->notFound();

            return;
        }

        $slug = strtolower(rawurldecode($catSlug));
        $category = preg_match('/^[a-z0-9-]{1,150}$/', $slug) === 1 ? $repo->categoryBySlug($slug) : null;
        if ($category === null || (int) ($category['is_public'] ?? 0) !== 1) {
            $this->notFound();

            return;
        }

        $lang = FeedI18n::requestLocale();
        $settings = $this->localizedSettings($lang);
        $name = self::localizedField($category, 'name', $lang);
        if (!self::hasConsent()) {
            $this->renderConsent($lang, $name, false);

            return;
        }

        $search = self::searchTerm();
        $filters = ['public' => true, 'category_id' => (int) $category['id']];
        if ($search !== '') {
            $filters['search'] = $search;
        }
        $perPage = (int) ($category['items_per_page'] ?? 0) > 0 ? (int) $category['items_per_page'] : (int) $settings['per_page'];
        [$page, $pages, $total, $items] = $this->page($filters, max(4, min(100, $perPage)));
        $categoryUrl = self::categoryPath((string) $category['slug'], $lang);
        $description = self::localizedField($category, 'description', $lang);

        $this->renderPage('category', $name, [
            'settings' => $settings,
            'category' => [
                'id' => (int) $category['id'],
                'name' => $name,
                'description' => $description,
                'icon' => (string) ($category['icon'] ?? ''),
                'slug' => (string) $category['slug'],
                'layout' => LegacyNormalizer::layout($category['layout'] ?? 'grid'),
            ],
            'categories' => $this->publicCategories($lang, (int) $category['id']),
            'sources' => $repo->sources(['category_id' => (int) $category['id'], 'status' => 'active']),
            'items' => $items,
            'search' => $search,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'archive_path' => self::archivePath($lang),
            'archive_url' => $categoryUrl,
            'prev_url' => $page > 1 ? self::pageUrl($categoryUrl, $search, $page - 1) : '',
            'next_url' => $page < $pages ? self::pageUrl($categoryUrl, $search, $page + 1) : '',
            'lang' => $lang,
            'embed' => false,
        ], $description !== '' ? $description : (string) $settings['archive_description'], $search !== '' || $page > 1);
    }

    /**
     * Gibt eine Artikelkarte aus (für eigene Theme-Templates nutzbar).
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $settings
     */
    public function renderCard(array $item, array $settings, string $lang, bool $hero = false): void
    {
        $file = $this->viewPath('card');
        if ($file === null) {
            return;
        }
        (static function (string $__file, array $item, array $settings, string $lang, bool $hero): void {
            include $__file;
        })($file, $item, $settings, $lang, $hero);
    }

    /** Hat der Besucher in die Anzeige externer Inhalte eingewilligt (oder ist das abgeschaltet)? */
    public static function hasConsent(): bool
    {
        if (!(bool) AggregatorSettings::get('require_consent')) {
            return true;
        }
        try {
            return CookieConsentService::getInstance()->hasConsentForService(self::CONSENT_SERVICE, self::CONSENT_CATEGORY, true);
        } catch (\Throwable) {
            return true;
        }
    }

    public static function archivePath(string $lang): string
    {
        return FeedI18n::path('/' . AggregatorSettings::archiveSlug(), $lang);
    }

    public static function categoryPath(string $slug, string $lang): string
    {
        return FeedI18n::path('/feed/' . rawurlencode($slug), $lang);
    }

    /**
     * Nur öffentliche http(s)-Adressen ohne Zugangsdaten und ohne interne Hosts; sonst leer.
     */
    public static function safeUrl(mixed $value): string
    {
        $url = trim(is_scalar($value) ? (string) $value : '');
        if ($url === '' || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return '';
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return '';
        }

        return $url;
    }

    /** Kopf-Assets (Stylesheet und Design-Token) nur auf Aggregator-Seiten. */
    public function printHeadAssets(): void
    {
        if (!$this->assetsNeeded) {
            return;
        }
        echo '<link rel="stylesheet" href="' . self::e(self::assetUrl('css/feeds.css')) . '">' . "\n";
        echo '<style ' . Security::instance()->nonceAttr() . '>' . AggregatorSettings::tokenCss() . '</style>' . "\n";
    }

    public function printFooterAssets(): void
    {
        if ($this->assetsNeeded) {
            echo '<script src="' . self::e(self::assetUrl('js/feeds.js')) . '" defer></script>' . "\n";
        }
    }

    // ── Intern ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $filters
     * @return array{0:int,1:int,2:int,3:list<array<string, mixed>>}
     */
    private function page(array $filters, int $perPage): array
    {
        $repo = AggregatorRepository::getInstance();
        $total = $repo->countItems($filters);
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = max(1, min($pages, min(999, is_scalar($_GET['page'] ?? null) ? (int) $_GET['page'] : 1)));
        $items = $total > 0 ? $repo->items($filters, ($page - 1) * $perPage, $perPage) : [];
        foreach ($items as &$item) {
            $item['category_name'] = self::localizedField(['name' => $item['category_name'] ?? '', 'name_en' => $item['category_name_en'] ?? ''], 'name', FeedI18n::requestLocale());
        }
        unset($item);

        return [$page, $pages, $total, $items];
    }

    /**
     * @return list<array{id:int,name:string,slug:string,icon:string,url:string,count:int,active:bool}>
     */
    private function publicCategories(string $lang, int $activeId = 0): array
    {
        $list = [];
        foreach (AggregatorRepository::getInstance()->categories(true) as $category) {
            $list[] = [
                'id' => (int) $category['id'],
                'name' => self::localizedField($category, 'name', $lang),
                'slug' => (string) $category['slug'],
                'icon' => (string) ($category['icon'] ?? ''),
                'url' => self::categoryPath((string) $category['slug'], $lang),
                'count' => (int) ($category['item_count'] ?? 0),
                'active' => (int) $category['id'] === $activeId,
            ];
        }

        return $list;
    }

    /** @return array<string, bool|int|string> */
    private function localizedSettings(string $lang): array
    {
        $settings = AggregatorSettings::all();
        foreach (['archive_title', 'archive_description'] as $key) {
            $settings[$key] = AggregatorSettings::localized($key, $lang);
        }

        return $settings;
    }

    /** @param array<string, mixed> $row */
    private static function localizedField(array $row, string $key, string $lang): string
    {
        $english = trim((string) ($row[$key . '_en'] ?? ''));

        return $lang === 'en' && $english !== '' ? $english : (string) ($row[$key] ?? '');
    }

    private static function searchTerm(): string
    {
        $raw = is_scalar($_GET['q'] ?? null) ? (string) $_GET['q'] : '';

        return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($raw)) ?? ''), 0, 120);
    }

    private static function pageUrl(string $base, string $search, int $page): string
    {
        $query = http_build_query(array_filter(['q' => $search, 'page' => $page > 1 ? $page : null], static fn ($v) => $v !== '' && $v !== null));

        return $base . ($query !== '' ? '?' . $query : '');
    }

    private function renderConsent(string $lang, string $title, bool $embed): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
        }
        $vars = [
            'lang' => $lang,
            'title' => $title,
            'preferences_url' => FeedI18n::path('/cookie-einstellungen', $lang),
            'home_url' => FeedI18n::path('/', $lang),
            'embed' => $embed,
        ];
        self::noIndexHeaders();
        if ($embed) {
            $vars['content'] = $this->capture('consent', $vars);
            $this->renderEmbedShell($vars['content'], $title, $lang);

            return;
        }
        $this->renderPage('consent', $title, $vars, '', true);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function renderPage(string $view, string $title, array $vars, string $description = '', bool $noIndex = false): void
    {
        $this->assetsNeeded = true;
        if ($noIndex) {
            self::noIndexHeaders();
        }
        $content = $this->capture($view, $vars);
        $lang = (string) ($vars['lang'] ?? 'de');
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        ThemeManager::instance()->render('page', [
            'title' => $title,
            'page' => [
                'id' => 0,
                'title' => $title,
                'slug' => trim($path, '/'),
                'content' => $content,
                'meta_description' => $description,
                'content_updated_at' => '',
            ],
            'contentLocale' => $lang,
        ]);
    }

    /** @param array<string, mixed> $vars */
    private function renderEmbed(array $vars, string $title, string $lang): void
    {
        $this->renderEmbedShell($this->capture('archive', $vars), $title, $lang);
    }

    /** Schlanke Seite für die Einbettung (iframe) ohne Theme-Kopf und -Fuß. */
    private function renderEmbedShell(string $content, string $title, string $lang): void
    {
        $this->assetsNeeded = true;
        self::noIndexHeaders();
        self::allowSameOriginFraming();
        $file = $this->viewPath('embed');
        if ($file === null) {
            echo $content;

            return;
        }
        (static function (string $__file, string $content, string $title, string $lang): void {
            include $__file;
        })($file, $content, $title, $lang);
    }

    /** @param array<string, mixed> $vars */
    private function capture(string $view, array $vars): string
    {
        $file = $this->viewPath($view);
        if ($file === null) {
            return '';
        }
        $level = ob_get_level();
        ob_start();
        try {
            $controller = $this;
            (static function (string $__file, array $data, AggregatorFrontendController $controller): void {
                include $__file;
            })($file, $vars, $controller);

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            Logger::instance()->withChannel('feeds')->error('Feed-Seite konnte nicht gerendert werden.', [
                'view' => $view,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function viewPath(string $view): ?string
    {
        if (!in_array($view, self::VIEWS, true)) {
            return null;
        }
        try {
            $themePath = ThemeManager::instance()->getThemePath();
        } catch (\Throwable) {
            $themePath = '';
        }
        if ($themePath !== '') {
            $candidate = rtrim($themePath, '/\\') . '/feeds/' . $view . '.php';
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        $core = rtrim((string) ABSPATH, '/\\') . '/views/feeds/' . $view . '.php';

        return is_file($core) ? $core : null;
    }

    private static function assetUrl(string $path): string
    {
        return function_exists('cms_asset_url') ? (string) cms_asset_url($path) : '/assets/' . $path;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function notFound(): void
    {
        Router::instance()->render404();
    }

    /**
     * Die Einbettungsseite darf in iframes derselben Website erscheinen (sonst gilt `DENY` bzw. `frame-ancestors 'none'`).
     */
    private static function allowSameOriginFraming(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Frame-Options: SAMEORIGIN', true);
        foreach (headers_list() as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2) + [1 => '']);
            if (in_array(strtolower($name), ['content-security-policy', 'content-security-policy-report-only'], true)
                && str_contains($value, "frame-ancestors 'none'")) {
                header($name . ': ' . str_replace("frame-ancestors 'none'", "frame-ancestors 'self'", $value), true);
            }
        }
    }

    private static function noIndexHeaders(): void
    {
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow', true);
        }
    }
}
