<?php
/**
 * Öffentliche Download-Routen: kontrollierte Auslieferung und Frontend-Seiten.
 *
 * - `/download/<slug>[/<version>]`: einzige Auslieferungsroute. Prüft Status, Zugriff und Grenzwert, zählt den
 *   Abruf ohne Personendaten und streamt die Datei aus dem privaten Speicher (realpath-geprüft, mit
 *   Range-Unterstützung). Externe Versionen leiten – optional über eine Hinweisseite mit signiertem Link – weiter.
 * - `/downloads`, `/downloads/category/<slug>`, `/downloads/<slug>`: Archiv und Einzelseite. Die Inhalte kommen
 *   aus `views/downloads/{archive,single}.php` (Theme-Override: `themes/<slug>/downloads/<name>.php`) und werden
 *   im Seiten-Template des Themes ausgegeben.
 * - `/downloads/file/<slug>`: frühere Plugin-URL, leitet per 301 auf `/download/<slug>` um.
 *
 * @package CMSv2\Core\Services\Downloads
 */

declare(strict_types=1);

namespace CMS\Services\Downloads;

use CMS\Logger;
use CMS\Router;
use CMS\Security;
use CMS\ThemeManager;

if (!defined('ABSPATH')) {
    exit;
}

final class DownloadFrontendController
{
    private const STREAM_CHUNK = 1048576;

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    public function registerRoutes(object $router): void
    {
        $router->addRoute('GET', '/download/:slug', fn (string $slug) => $this->deliver($slug, ''));
        $router->addRoute('GET', '/download/:slug/:version', fn (string $slug, string $version) => $this->deliver($slug, $version));
        // Routen-Parameter erlauben keine Punkte; Versionsnummern wie „1.2.0“ erhalten deshalb eine Route für
        // genau den angefragten Pfad (Zeichen sind durch das Muster auf [A-Za-z0-9._-] begrenzt).
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (str_contains($path, '.') && preg_match('#^(?:/en)?(/download/(' . DownloadSecurity::SLUG_PATTERN . ')/(' . DownloadSecurity::VERSION_PATTERN . '))$#', $path, $match) === 1) {
            $router->addRoute('GET', $match[1], fn () => $this->deliver($match[2], $match[3]));
        }
        $router->addRoute('GET', '/downloads', fn () => $this->archive(''));
        $router->addRoute('GET', '/downloads/category/:slug', fn (string $slug) => $this->archive($slug));
        $router->addRoute('GET', '/downloads/file/:slug', fn (string $slug) => $this->redirectLegacyFile($slug));
        $router->addRoute('GET', '/downloads/:slug', fn (string $slug) => $this->single($slug));
    }

    // ── Auslieferung ─────────────────────────────────────────────────────────

    public function deliver(string $slug, string $version): void
    {
        $service = DownloadService::getInstance();
        $slug = strtolower(rawurldecode($slug));
        $version = rawurldecode($version);
        $item = $service->getBySlug($slug);

        // Entwürfe, archivierte und gelöschte Downloads gibt es öffentlich nicht (Personal sieht Entwürfe).
        if ($item === null || !$this->isDeliverable($item)) {
            $this->notFound();

            return;
        }
        if ($version !== '' && !DownloadSecurity::isValidVersion($version)) {
            $this->notFound();

            return;
        }

        $resolved = $service->resolveVersion($item, $version === '' ? 'latest' : $version);
        if ($resolved === null) {
            $this->notFound();

            return;
        }

        self::noIndexHeaders();
        $state = $service->accessState($item);
        if ($state !== DownloadService::ACCESS_GRANTED) {
            $this->denied($item, $state);

            return;
        }

        $followUp = self::isFollowUpRequest((string) ($_SERVER['HTTP_RANGE'] ?? ''), (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $retryAfter = DownloadStatsService::getInstance()->rateLimitRetryAfter(Security::getClientIp(), $followUp);
        if ($retryAfter > 0) {
            if (!headers_sent()) {
                header('Retry-After: ' . $retryAfter);
            }
            Router::instance()->renderError(429, __('downloads.error.rate_limit_title'), __('downloads.error.rate_limit'));

            return;
        }

        if (!empty($resolved['is_external'])) {
            $this->deliverExternal($item, $resolved);

            return;
        }

        $path = $service->absolutePath($resolved);
        if ($path === null) {
            Logger::instance()->withChannel('downloads')->error('Download-Datei fehlt im Speicher.', [
                'download_id' => (int) $item['id'],
                'version_id' => (int) $resolved['id'],
            ]);
            $this->notFound();

            return;
        }

        $this->count($item, $resolved);
        $this->stream($path, $resolved);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isDeliverable(array $item): bool
    {
        $status = (string) ($item['status'] ?? '');

        return $status === 'published' || ($status === 'draft' && DownloadService::getInstance()->isStaff());
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $version
     */
    private function deliverExternal(array $item, array $version): void
    {
        $settings = DownloadSettings::getInstance();
        $url = (string) $version['external_url'];
        if (!DownloadSecurity::isAllowedExternalUrl($url, $settings->string('external_allowed_domains'))) {
            Logger::instance()->withChannel('downloads')->warning('Externes Download-Ziel ist nicht (mehr) erlaubt.', ['download_id' => (int) $item['id']]);
            $this->notFound();

            return;
        }

        $downloadId = (int) $item['id'];
        $versionId = (int) $version['id'];
        $confirmed = DownloadSecurity::verifyExternalToken(
            $downloadId,
            $versionId,
            (int) ($_GET['e'] ?? 0),
            is_string($_GET['t'] ?? null) ? (string) $_GET['t'] : ''
        );

        if ($settings->bool('show_external_notice') && !$confirmed) {
            $expires = time() + DownloadSecurity::EXTERNAL_TOKEN_TTL;
            $continueUrl = DownloadRenderer::fileUrl((string) $item['slug'], empty($version['is_current']) ? (string) $version['version'] : '')
                . '?' . http_build_query(array_filter([
                    'e' => $expires,
                    't' => DownloadSecurity::externalToken($downloadId, $versionId, $expires),
                    'src' => is_string($_GET['src'] ?? null) ? (string) $_GET['src'] : '',
                ]));
            $this->renderPage('external', __('downloads.external.title'), [
                'item' => $item,
                'host' => (string) parse_url($url, PHP_URL_HOST),
                'continue_url' => $continueUrl,
                'back_url' => DownloadRenderer::singleUrl((string) $item['slug'], DownloadRenderer::language()),
            ], '', true);

            return;
        }

        $this->count($item, $version);
        // Externes, geprüftes Ziel: Router::redirect() lässt nur eigene Pfade zu.
        self::redirectAbsolute($url, true);
    }

    /**
     * Folgeanfragen eines Download-Managers (Range ab Byte > 0) und HEAD sind kein neuer Abruf.
     */
    public static function isFollowUpRequest(string $range, string $method): bool
    {
        $range = trim($range);
        if ($range !== '' && preg_match('/^bytes=0+-/i', $range) !== 1) {
            return true;
        }

        return strtoupper($method) === 'HEAD';
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $version
     */
    private function count(array $item, array $version): void
    {
        if (self::isFollowUpRequest((string) ($_SERVER['HTTP_RANGE'] ?? ''), (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            return;
        }

        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $source = DownloadSecurity::verifySource(is_string($_GET['src'] ?? null) ? (string) $_GET['src'] : '');
        $visitor = DownloadSecurity::visitorKey(session_status() === PHP_SESSION_ACTIVE ? (string) session_id() : '', Security::getClientIp(), $userAgent);

        DownloadStatsService::getInstance()->recordHit(
            (int) $item['id'],
            (int) $version['id'],
            $source,
            $visitor,
            $userAgent,
            DownloadService::getInstance()->isStaff()
        );
    }

    /**
     * Datei ausliefern (Anhang, kein Sniffing, Range für Fortsetzen großer Dateien).
     *
     * @param array<string, mixed> $version
     */
    private function stream(string $path, array $version): never
    {
        $size = (int) filesize($path);
        $range = DownloadSecurity::parseRange((string) ($_SERVER['HTTP_RANGE'] ?? ''), $size);
        $name = (string) ($version['file_name'] ?? '') !== '' ? (string) $version['file_name'] : basename($path);
        $mime = (string) ($version['mime_type'] ?? '');
        if (preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $mime) !== 1) {
            $mime = 'application/octet-stream';
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        @set_time_limit(0);

        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . DownloadSecurity::contentDisposition($name));
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store');
        header('Content-Security-Policy: default-src \'none\'; sandbox');
        if ((string) ($version['sha256'] ?? '') !== '') {
            header('ETag: "' . $version['sha256'] . '"');
        }

        if ($range === false) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }

        $start = 0;
        $end = max(0, $size - 1);
        if (is_array($range)) {
            [$start, $end] = $range;
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        $length = $size > 0 ? $end - $start + 1 : 0;
        header('Content-Length: ' . $length);

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD' || $length <= 0) {
            exit;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            exit;
        }
        if ($start > 0) {
            fseek($handle, $start);
        }
        $remaining = $length;
        while ($remaining > 0 && !feof($handle) && connection_status() === CONNECTION_NORMAL) {
            $chunk = fread($handle, (int) min(self::STREAM_CHUNK, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }
        fclose($handle);
        exit;
    }

    /**
     * Kein Zugriff: Anmeldung anbieten bzw. Hinweis zeigen – nie eine Datei-URL.
     *
     * @param array<string, mixed> $item
     */
    private function denied(array $item, string $state): void
    {
        if ($state === DownloadService::ACCESS_LOGIN) {
            self::redirectAbsolute(DownloadRenderer::loginUrl(DownloadRenderer::singleUrl((string) $item['slug'], DownloadRenderer::language())), false);
        }

        Router::instance()->renderError(
            403,
            __('downloads.error.forbidden_title'),
            $state === DownloadService::ACCESS_MEMBERSHIP ? __('downloads.lock.membership') : __('downloads.lock.role')
        );
    }

    public function redirectLegacyFile(string $slug): void
    {
        $slug = strtolower(rawurldecode($slug));
        if (!DownloadSecurity::isValidSlug($slug)) {
            $this->notFound();

            return;
        }
        Router::instance()->redirect('/download/' . $slug, 301);
    }

    // ── Seiten ───────────────────────────────────────────────────────────────

    public function archive(string $categorySlug): void
    {
        $service = DownloadService::getInstance();
        if (!$service->isAvailable()) {
            $this->notFound();

            return;
        }

        $settings = DownloadSettings::getInstance();
        $lang = DownloadRenderer::language();
        $categorySlug = strtolower(rawurldecode($categorySlug));
        $category = null;
        if ($categorySlug !== '') {
            $category = DownloadSecurity::isValidSlug($categorySlug) ? $service->repository()->findTermBySlug('category', $categorySlug) : null;
            if ($category === null || (string) $category['status'] !== 'active') {
                $this->notFound();

                return;
            }
        }

        $search = $settings->bool('show_search') ? DownloadService::plainText(is_scalar($_GET['q'] ?? null) ? (string) $_GET['q'] : '', 100) : '';
        $filters = ['status' => 'published', 'search' => $search];
        if ($category !== null) {
            $filters['category_id'] = (int) $category['id'];
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = $service->list($filters, $page, $settings->int('per_page'), 'manual');
        $items = [];
        foreach ($result['items'] as $item) {
            $items[(int) $item['id']] = $item;
        }

        $renderer = DownloadRenderer::getInstance();
        $models = [];
        if ($items !== []) {
            $versions = $service->repository()->versionsForItems(array_keys($items));
            foreach ($items as $id => $item) {
                $current = null;
                foreach ($versions[$id] ?? [] as $row) {
                    if ((int) ($row['current_flag'] ?? 0) === 1) {
                        $current = DownloadService::hydrateVersion($row);
                        break;
                    }
                }
                if ($current !== null) {
                    $models[] = $renderer->viewModel($item, $current, [], DownloadRenderer::DEFAULT_OPTIONS, 'page');
                }
            }
        }

        $categories = [];
        foreach ($service->categories(true) as $term) {
            if ((int) ($term['item_count'] ?? 0) > 0) {
                $categories[] = [
                    'name' => (string) $term['name'],
                    'slug' => (string) $term['slug'],
                    'description' => (string) ($term['description'] ?? ''),
                    'icon' => (string) ($term['icon'] ?? ''),
                    'count' => (int) $term['item_count'],
                    'url' => DownloadRenderer::categoryUrl((string) $term['slug'], $lang),
                    'active' => $category !== null && (int) $category['id'] === (int) $term['id'],
                ];
            }
        }

        $title = $lang === 'en' && $settings->string('archive_title_en') !== '' ? $settings->string('archive_title_en') : $settings->string('archive_title');
        $description = $lang === 'en' && $settings->string('archive_description_en') !== '' ? $settings->string('archive_description_en') : $settings->string('archive_description');
        if ($category !== null) {
            $title = (string) $category['name'] . ' – ' . $title;
            $description = (string) ($category['description'] ?? '') !== '' ? (string) $category['description'] : $description;
        }

        $baseUrl = $category !== null ? DownloadRenderer::categoryUrl((string) $category['slug'], $lang) : DownloadRenderer::archiveUrl($lang);
        $pageUrl = static fn (int $number): string => $baseUrl . (($query = http_build_query(array_filter(['q' => $search, 'page' => $number > 1 ? $number : null]))) !== '' ? '?' . $query : '');

        $this->renderPage('archive', $title, [
            'title' => $title,
            'description' => $description,
            'downloads' => $models,
            'categories' => $categories,
            'category' => $category,
            'show_search' => $settings->bool('show_search'),
            'show_categories' => $settings->bool('show_category_overview'),
            'search' => $search,
            'archive_url' => DownloadRenderer::archiveUrl($lang),
            'form_action' => $baseUrl,
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'prev_url' => $result['page'] > 1 ? $pageUrl($result['page'] - 1) : '',
            'next_url' => $result['page'] < $result['pages'] ? $pageUrl($result['page'] + 1) : '',
            'lang' => $lang,
        ], $description, $search !== '' || $result['page'] > 1);
    }

    public function single(string $slug): void
    {
        $service = DownloadService::getInstance();
        $slug = strtolower(rawurldecode($slug));
        $item = $service->getBySlug($slug);
        if ($item === null || !$this->isDeliverable($item)) {
            $this->notFound();

            return;
        }

        $lang = DownloadRenderer::language();
        $full = $service->withDetails([(int) $item['id'] => $item])[(int) $item['id']];
        $settings = DownloadSettings::getInstance();
        $renderer = DownloadRenderer::getInstance();

        $current = null;
        foreach ($full['versions'] as $version) {
            if (!empty($version['is_current'])) {
                $current = $version;
            }
        }
        $options = DownloadRenderer::DEFAULT_OPTIONS;
        $options['showProperties'] = array_keys($settings->propertyFieldMap());
        $model = $current !== null ? $renderer->viewModel($item, $current, $full['properties'], $options, 'page') : null;

        $locked = $service->accessState($item) !== DownloadService::ACCESS_GRANTED;
        $versions = [];
        foreach ($full['versions'] as $version) {
            $versions[] = [
                'version' => (string) $version['version'],
                'is_current' => !empty($version['is_current']),
                'is_external' => !empty($version['is_external']),
                'release_date_label' => DownloadRenderer::formatDate((string) $version['release_date'], $lang),
                'size_label' => (int) $version['file_size'] > 0 ? DownloadSecurity::formatBytes((int) $version['file_size']) : '',
                'sha256' => !empty($version['is_external']) ? '' : (string) $version['sha256'],
                'changelog' => trim(strip_tags((string) $version['changelog'])),
                'file_name' => (string) $version['file_name'],
                'url' => $locked ? '' : DownloadRenderer::fileUrl((string) $item['slug'], !empty($version['is_current']) ? '' : (string) $version['version']),
            ];
        }

        $title = (string) $item['seo_title'] !== '' ? (string) $item['seo_title'] : (string) $item['title'];
        $description = (string) $item['seo_description'] !== '' ? (string) $item['seo_description'] : DownloadRenderer::excerpt((string) $item['summary'], 300);

        $this->renderPage('single', $title, [
            'item' => $full,
            'download' => $model,
            'versions' => $versions,
            'tags' => $full['tags'],
            'description_html' => (string) $item['description'],
            'archive_url' => DownloadRenderer::archiveUrl($lang),
            'schema' => $model !== null && (string) $item['status'] === 'published' ? $this->schema($full, $model, $current ?? []) : null,
            'is_draft' => (string) $item['status'] === 'draft',
            'lang' => $lang,
        ], $description, !empty($item['seo_noindex']) || (string) $item['status'] !== 'published', (string) $item['title']);
    }

    /**
     * Schema.org: SoftwareApplication für Programme/Archive/Links, sonst DigitalDocument.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $model
     * @param array<string, mixed> $version
     * @return array<string, mixed>
     */
    private function schema(array $item, array $model, array $version): array
    {
        $group = (string) $model['file_group'];
        $isSoftware = in_array($group, ['archive', 'link', 'file'], true);
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $isSoftware ? 'SoftwareApplication' : 'DigitalDocument',
            'name' => (string) $item['title'],
            'url' => (string) $model['single_url'],
        ];
        if ((string) $item['summary'] !== '') {
            $schema['description'] = (string) $item['summary'];
        }
        if ((string) $model['release_date'] !== '') {
            $schema['dateModified'] = (string) $model['release_date'];
        }
        if ((string) $model['author'] !== '') {
            $schema['author'] = ['@type' => 'Organization', 'name' => (string) $model['author']];
        }
        if ((string) $model['license'] !== '' && preg_match('#^https?://#i', (string) $model['license']) === 1) {
            $schema['license'] = (string) $model['license'];
        }
        if ((string) $model['cover_url'] !== '') {
            $schema['image'] = (string) $model['cover_url'];
        }
        if ($isSoftware) {
            $schema['applicationCategory'] = (string) $model['category'] !== '' ? (string) $model['category'] : 'Utility';
            if ((string) $model['version'] !== '') {
                $schema['softwareVersion'] = (string) $model['version'];
            }
            if ((string) $model['requirements'] !== '') {
                $schema['softwareRequirements'] = (string) $model['requirements'];
            }
            if ((int) $model['size'] > 0) {
                $schema['fileSize'] = (string) $model['size_label'];
            }
            // Öffentliche Downloads sind kostenlos abrufbar; die Auslieferungs-URL ist die kontrollierte Route.
            if ((string) ($item['access_level'] ?? 'public') === 'public') {
                $schema['downloadUrl'] = DownloadRenderer::fileUrl((string) $item['slug']);
                $schema['offers'] = ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'];
            }
            $schema['operatingSystem'] = (string) $model['requirements'] !== '' ? (string) $model['requirements'] : 'Any';
        } else {
            $mime = (string) ($version['mime_type'] ?? '');
            if ($mime !== '') {
                $schema['encodingFormat'] = $mime;
            }
            if ((string) $model['version'] !== '') {
                $schema['version'] = (string) $model['version'];
            }
        }

        return $schema;
    }

    /**
     * Inhalts-Partial rendern und im Seiten-Template des Themes ausgeben.
     *
     * @param array<string, mixed> $vars
     */
    private function renderPage(string $view, string $title, array $vars, string $description = '', bool $noIndex = false, string $heading = ''): void
    {
        $renderer = DownloadRenderer::getInstance();
        $renderer->markAssetsNeeded();
        if ($noIndex) {
            self::noIndexHeaders();
        }

        $file = $this->viewPath($view);
        $content = '';
        if ($file !== null) {
            $level = ob_get_level();
            ob_start();
            try {
                (static function (string $__file, array $data): void {
                    include $__file;
                })($file, $vars);
                $content = (string) ob_get_clean();
            } catch (\Throwable $e) {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
                Logger::instance()->withChannel('downloads')->error('Download-Seite konnte nicht gerendert werden.', [
                    'view' => $view,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $lang = DownloadRenderer::language();
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/downloads'), PHP_URL_PATH);
        ThemeManager::instance()->render('page', [
            'title' => $title,
            'page' => [
                'id' => 0,
                'title' => $heading !== '' ? $heading : $title,
                'slug' => trim($path, '/'),
                'content' => $content,
                'meta_description' => $description,
                'content_updated_at' => '',
            ],
            'contentLocale' => $lang,
        ]);
    }

    private function viewPath(string $view): ?string
    {
        if (!in_array($view, ['archive', 'single', 'external'], true)) {
            return null;
        }
        try {
            $themePath = ThemeManager::instance()->getThemePath();
        } catch (\Throwable) {
            $themePath = '';
        }
        if ($themePath !== '') {
            $candidate = rtrim($themePath, '/\\') . '/downloads/' . $view . '.php';
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        $core = rtrim((string) ABSPATH, '/\\') . '/views/downloads/' . $view . '.php';

        return is_file($core) ? $core : null;
    }

    private static function redirectAbsolute(string $url, bool $noReferrer): never
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            if ($noReferrer) {
                header('Referrer-Policy: no-referrer');
            }
            header('Location: ' . str_replace(["\r", "\n"], '', $url), true, 302);
        }
        exit;
    }

    private function notFound(): void
    {
        Router::instance()->render404();
    }

    private static function noIndexHeaders(): void
    {
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow', true);
        }
    }
}
