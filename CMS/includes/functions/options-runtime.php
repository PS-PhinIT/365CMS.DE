<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get site option
 */
function get_option(string $key, $default = null) {
    // Zentraler Request-Cache: Autoload-Optionen in einer Abfrage, übrige Schlüssel einzeln
    // nachgeladen; Schreibzugriffe auf `settings` leeren den Cache (CMS\Database::noteWrite()).
    $value = CMS\Services\OptionStore::getInstance()->get($key);

    return $value ?? $default;
}

/**
 * Update site option
 */
function update_option(string $key, $value): bool {
    $db = CMS\Database::instance();

    $stmt = $db->prepare("SELECT COUNT(*) as count FROM {$db->prefix()}settings WHERE option_name = ?");
    $stmt->execute([$key]);

    $jsonValue = is_array($value) ? json_encode($value) : (string) $value;

    if ($stmt->fetch()->count > 0) {
        return $db->update('settings', ['option_value' => $jsonValue], ['option_name' => $key]);
    }

    return $db->insert('settings', [
        'option_name' => $key,
        'option_value' => $jsonValue,
        'autoload' => 1,
    ]) > 0;
}

/**
 * Liefert den aktuell konfigurierten Website-Namen aus den Settings.
 */
function cms_get_site_name(): string {
    static $siteName = null;

    if ($siteName !== null) {
        return $siteName;
    }

    $fallback = defined('SITE_NAME') ? SITE_NAME : '365CMS';

    try {
        $db = CMS\Database::instance();
        $value = $db->get_var(
            "SELECT option_value FROM {$db->prefix()}settings WHERE option_name IN ('site_name', 'site_title') ORDER BY FIELD(option_name, 'site_name', 'site_title') LIMIT 1"
        );

        $siteName = is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    } catch (\Throwable) {
        $siteName = $fallback;
    }

    return $siteName;
}

/**
 * Liefert den aktuellen Website-Titel für Browsertabs/Meta.
 */
function cms_get_site_title(): string {
    return cms_get_site_name();
}

/**
 * Liefert die unterstützten Frontend-Sprachen inklusive Basissprache.
 *
 * @return string[]
 */
function cms_get_archive_locales(): array {
    static $locales = null;

    if (is_array($locales)) {
        return $locales;
    }

    $locales = ['de'];

    try {
        if (class_exists('\\CMS\\Services\\ContentLocalizationService')) {
            $extraLocales = \CMS\Services\ContentLocalizationService::getInstance()->getContentLocales();
            foreach ($extraLocales as $locale) {
                if (!is_string($locale)) {
                    continue;
                }

                $normalized = strtolower(trim($locale));
                if ($normalized === '' || in_array($normalized, $locales, true)) {
                    continue;
                }

                $locales[] = $normalized;
            }
        }
    } catch (\Throwable) {
    }

    return $locales;
}

/**
 * Normalisiert die gewünschte Frontend-Sprache für Archiv-Routen.
 */
function cms_resolve_archive_locale(?string $locale = null): string {
    $resolvedLocale = strtolower(trim((string) ($locale ?? '')));

    try {
        if ($resolvedLocale !== '' && class_exists('\\CMS\\Services\\ContentLocalizationService')) {
            $normalized = \CMS\Services\ContentLocalizationService::getInstance()->normalizeLocale($resolvedLocale);
            if ($normalized !== '') {
                $resolvedLocale = $normalized;
            }
        }
    } catch (\Throwable) {
    }

    if ($resolvedLocale === '') {
        try {
            if (class_exists('\\CMS\\Services\\ContentLocalizationService')) {
                $context = \CMS\Services\ContentLocalizationService::getInstance()->resolveRequestContext((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/'));
                $normalized = strtolower(trim((string) ($context['locale'] ?? 'de')));
                if ($normalized !== '') {
                    $resolvedLocale = $normalized;
                }
            }
        } catch (\Throwable) {
        }
    }

    return $resolvedLocale !== '' ? $resolvedLocale : 'de';
}

/**
 * Liefert den Fallback-Slug für Kategorie-/Tag-Archive je Sprache.
 */
function cms_get_default_archive_base(string $type, string $locale = 'de'): string {
    $type = strtolower(trim($type));
    $locale = cms_resolve_archive_locale($locale);

    return match ($type) {
        'category' => $locale === 'en' ? 'category' : 'kategorie',
        'tag' => 'tag',
        default => '',
    };
}

/**
 * Normalisiert einen Segment-Slug für öffentliche Archiv-Basen.
 */
function cms_normalize_archive_base(string $value, string $fallback): string {
    $normalized = trim(mb_strtolower($value, 'UTF-8'));
    $normalized = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $normalized);
    $normalized = preg_replace('/[^a-z0-9]+/u', '-', $normalized) ?? '';
    $normalized = trim($normalized, '-');

    return $normalized !== '' ? $normalized : $fallback;
}

/**
 * Liefert die konfigurierte Archiv-Basis für Kategorien oder Tags.
 */
function cms_get_archive_base(string $type, ?string $locale = null): string {
    static $cache = [];

    $type = strtolower(trim($type));
    if (!in_array($type, ['category', 'tag'], true)) {
        return '';
    }

    $resolvedLocale = cms_resolve_archive_locale($locale);
    $cacheKey = $type . '|' . $resolvedLocale;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $default = cms_get_default_archive_base($type, $resolvedLocale);
    $settingKey = $type . '_base_' . $resolvedLocale;
    $value = $default;

    try {
        if (class_exists('\\CMS\\Services\\SettingsService')) {
            $value = \CMS\Services\SettingsService::getInstance()->getString('routing', $settingKey, $default);
        } else {
            $db = \CMS\Database::instance();
            $dbValue = $db->get_var(
                "SELECT option_value FROM {$db->prefix()}settings WHERE option_name = ? LIMIT 1",
                ['routing.' . $settingKey]
            );

            if (is_string($dbValue) && trim($dbValue) !== '') {
                $value = $dbValue;
            }
        }
    } catch (\Throwable) {
        $value = $default;
    }

    $cache[$cacheKey] = cms_normalize_archive_base((string) $value, $default);

    return $cache[$cacheKey];
}

/**
 * Liefert alle gültigen Alias-Basen für einen Archiv-Typ.
 *
 * @return string[]
 */
function cms_get_archive_base_aliases(string $type): array {
    static $aliases = [];

    $type = strtolower(trim($type));
    if (!in_array($type, ['category', 'tag'], true)) {
        return [];
    }

    if (isset($aliases[$type])) {
        return $aliases[$type];
    }

    $values = [];
    foreach (cms_get_archive_locales() as $locale) {
        $values[] = cms_get_default_archive_base($type, $locale);
        $values[] = cms_get_archive_base($type, $locale);
    }

    $aliases[$type] = array_values(array_unique(array_filter(array_map(static function (string $value): string {
        return trim($value);
    }, $values))));

    return $aliases[$type];
}

/**
 * Analysiert einen Request-Pfad auf Kategorie-/Tag-Archiv-Routen.
 *
 * @return array{type:string,locale:string,base_uri:string,base_segment:string,tail:string}|null
 */
function cms_parse_archive_request_path(string $path): ?array {
    $pathOnly = (string) (parse_url(trim($path), PHP_URL_PATH) ?? trim($path));
    $pathOnly = '/' . ltrim($pathOnly !== '' ? $pathOnly : '/', '/');

    try {
        if (class_exists('\\CMS\\Services\\ContentLocalizationService')) {
            $context = \CMS\Services\ContentLocalizationService::getInstance()->resolveRequestContext($pathOnly);
        } else {
            $context = [
                'base_uri' => $pathOnly,
                'locale' => 'de',
            ];
        }
    } catch (\Throwable) {
        $context = [
            'base_uri' => $pathOnly,
            'locale' => 'de',
        ];
    }

    $baseUri = '/' . trim((string) ($context['base_uri'] ?? $pathOnly), '/');
    $baseUri = preg_replace('#/+#', '/', $baseUri) ?? $baseUri;
    $baseUri = $baseUri === '' ? '/' : $baseUri;

    $segments = array_values(array_filter(explode('/', trim($baseUri, '/')), static fn(string $segment): bool => $segment !== ''));
    if ($segments === []) {
        return null;
    }

    $firstSegment = strtolower(rawurldecode((string) ($segments[0] ?? '')));
    foreach (['category', 'tag'] as $type) {
        if (!in_array($firstSegment, cms_get_archive_base_aliases($type), true)) {
            continue;
        }

        return [
            'type' => $type,
            'locale' => cms_resolve_archive_locale((string) ($context['locale'] ?? 'de')),
            'base_uri' => $baseUri,
            'base_segment' => $firstSegment,
            'tail' => implode('/', array_slice($segments, 1)),
        ];
    }

    return null;
}

/**
 * Prüft, ob ein Request-Pfad auf ein Kategorie-/Tag-Archiv zeigt.
 */
function cms_is_archive_request_path(string $path, ?string $type = null): bool {
    $archive = cms_parse_archive_request_path($path);
    if ($archive === null) {
        return false;
    }

    if ($type === null) {
        return true;
    }

    return strtolower(trim($type)) === (string) ($archive['type'] ?? '');
}

/**
 * Schreibt Legacy-/Alias-Pfade für Kategorie-/Tag-Archive auf die aktuelle CMS-Konfiguration um.
 */
function cms_rewrite_archive_path(string $path, ?string $locale = null): string {
    $archive = cms_parse_archive_request_path($path);
    if ($archive === null) {
        return $path;
    }

    $targetLocale = cms_resolve_archive_locale($locale ?? (string) ($archive['locale'] ?? 'de'));
    $rewritten = '/' . cms_get_archive_base((string) ($archive['type'] ?? ''), $targetLocale);
    $tail = trim((string) ($archive['tail'] ?? ''), '/');

    if ($tail !== '') {
        $rewritten .= '/' . $tail;
    }

    $query = (string) (parse_url($path, PHP_URL_QUERY) ?? '');
    if ($query !== '') {
        $rewritten .= '?' . $query;
    }

    $fragment = (string) (parse_url($path, PHP_URL_FRAGMENT) ?? '');
    if ($fragment !== '') {
        $rewritten .= '#' . $fragment;
    }

    return $rewritten;
}

/**
 * Liefert den lokalisierten Pfad zu einem Kategorie-/Tag-Archiv.
 */
function cms_get_archive_path(string $type, string $slug = '', ?string $locale = null): string {
    $resolvedLocale = cms_resolve_archive_locale($locale);
    $basePath = '/' . cms_get_archive_base($type, $resolvedLocale);
    $normalizedSlug = trim($slug, '/');

    if ($normalizedSlug !== '') {
        $basePath .= '/' . rawurlencode(rawurldecode($normalizedSlug));
    }

    try {
        if (class_exists('\\CMS\\Services\\ContentLocalizationService')) {
            return \CMS\Services\ContentLocalizationService::getInstance()->buildLocalizedPath($basePath, $resolvedLocale);
        }
    } catch (\Throwable) {
    }

    return $resolvedLocale !== '' && $resolvedLocale !== 'de'
        ? '/' . $resolvedLocale . $basePath
        : $basePath;
}

/**
 * Liefert die absolute URL zu einem Kategorie-/Tag-Archiv.
 */
function cms_get_archive_url(string $type, string $slug = '', ?string $locale = null): string {
    return cms_runtime_base_url(ltrim(cms_get_archive_path($type, $slug, $locale), '/'));
}

if (!function_exists('cms_escape_like')) {
/**
 * Maskiert LIKE-Platzhalter (`%`, `_`) und den Standard-Escape `\` für Suchbegriffe aus Benutzereingaben.
 * Ohne Maskierung findet die Suche nach `%` alle Datensätze, und Muster wie `%_%_%_` erzeugen teure Scans.
 */
function cms_escape_like(string $value): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}
}

/**
 * SQL-Fragment für öffentlich sichtbare Blog-Beiträge.
 */
function cms_post_publication_where(string $alias = ''): string {
    $normalizedAlias = trim($alias);
    if ($normalizedAlias !== '') {
        $normalizedAlias = rtrim($normalizedAlias, '.') . '.';
    }

    $currentDateTime = date('Y-m-d H:i:s');

    return "({$normalizedAlias}status = 'published' AND ({$normalizedAlias}published_at IS NULL OR {$normalizedAlias}published_at <= '{$currentDateTime}'))";
}

/**
 * Prüft, ob ein Beitrag bereits öffentlich sichtbar ist.
 *
 * @param array<string,mixed>|object $post
 */
function cms_post_is_publicly_visible(array|object $post): bool {
    $status = is_array($post)
        ? (string) ($post['status'] ?? '')
        : (string) ($post->status ?? '');

    if ($status !== 'published') {
        return false;
    }

    $publishedAt = is_array($post)
        ? trim((string) ($post['published_at'] ?? ''))
        : trim((string) ($post->published_at ?? ''));

    if ($publishedAt === '') {
        return true;
    }

    $timestamp = strtotime($publishedAt);
    if ($timestamp === false) {
        return true;
    }

    return $timestamp <= time();
}

/**
 * Prüft, ob ein Beitrag als geplant markiert ist.
 *
 * @param array<string,mixed>|object $post
 */
function cms_post_is_scheduled(array|object $post): bool {
    $status = is_array($post)
        ? (string) ($post['status'] ?? '')
        : (string) ($post->status ?? '');

    if ($status !== 'published') {
        return false;
    }

    $publishedAt = is_array($post)
        ? trim((string) ($post['published_at'] ?? ''))
        : trim((string) ($post->published_at ?? ''));

    if ($publishedAt === '') {
        return false;
    }

    $timestamp = strtotime($publishedAt);
    if ($timestamp === false) {
        return false;
    }

    return $timestamp > time();
}

/**
 * true, wenn die Anfrage über einen vertrauenswürdigen Proxy/CDN kam (siehe TrustedProxyService).
 */
function cms_runtime_request_via_trusted_proxy(): bool {
    try {
        return class_exists(\CMS\Services\TrustedProxyService::class)
            && \CMS\Services\TrustedProxyService::requestViaTrustedProxy();
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Liefert das aktuelle Request-Schema mit Proxy-Unterstützung.
 *
 * Eine Herabstufung auf http per X-Forwarded-Proto wird nur von vertrauenswürdigen Proxys
 * übernommen, damit gefälschte Header keine http-Asset-URLs in gecachte Seiten schreiben.
 */
function cms_runtime_scheme(): string {
    $forwardedProto = trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwardedProto !== '') {
        $parts = explode(',', $forwardedProto);
        $scheme = strtolower(trim((string) ($parts[0] ?? '')));
        if ($scheme === 'https' || ($scheme === 'http' && cms_runtime_request_via_trusted_proxy())) {
            return $scheme;
        }
    }

    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if (($https !== '' && $https !== 'off' && $https !== '0') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return 'https';
    }

    $siteScheme = strtolower((string) parse_url((string) (defined('SITE_URL') ? SITE_URL : ''), PHP_URL_SCHEME));
    if ($siteScheme === 'https') {
        return 'https';
    }

    $requestScheme = strtolower(trim((string) ($_SERVER['REQUEST_SCHEME'] ?? '')));
    if (in_array($requestScheme, ['http', 'https'], true)) {
        return $requestScheme;
    }

    if ($siteScheme === 'http') {
        return 'http';
    }

    return 'https';
}

/**
 * true, wenn der Hostname zur Installation gehört (SITE_URL-Host, dessen www-Variante oder Hub-Domain).
 */
function cms_runtime_host_is_allowed(string $hostname, string $siteHost): bool {
    if ($hostname === $siteHost || $hostname === 'www.' . $siteHost || 'www.' . $hostname === $siteHost) {
        return true;
    }

    static $hubDomains = [];
    static $resolving = false;
    if (array_key_exists($hostname, $hubDomains)) {
        return $hubDomains[$hostname];
    }

    if ($resolving || str_starts_with($hostname, '[') || filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
        return false;
    }

    $resolving = true;
    try {
        $allowed = class_exists(\CMS\Services\SiteTableService::class)
            && \CMS\Services\SiteTableService::getInstance()->isHubDomain($hostname);
    } catch (\Throwable) {
        $allowed = false;
    } finally {
        $resolving = false;
    }

    return $hubDomains[$hostname] = $allowed;
}

/**
 * Liefert den aktuellen Host der Anfrage.
 *
 * Host-Header sind fälschbar und landen in Script-/Asset-URLs gecachter Seiten. Daher zählt
 * X-Forwarded-Host nur hinter vertrauenswürdigen Proxys, und es werden ausschließlich Hosts der
 * eigenen Installation übernommen; sonst gilt der Host aus SITE_URL.
 */
function cms_runtime_host(): string {
    $siteUrl = (string) (defined('SITE_URL') ? SITE_URL : '');
    $siteHost = strtolower(trim((string) parse_url($siteUrl, PHP_URL_HOST), '.'));
    $sitePort = (int) parse_url($siteUrl, PHP_URL_PORT);

    $candidates = [];
    if (trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '')) !== '' && cms_runtime_request_via_trusted_proxy()) {
        $candidates[] = (string) $_SERVER['HTTP_X_FORWARDED_HOST'];
    }
    $candidates[] = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $candidates[] = (string) ($_SERVER['SERVER_NAME'] ?? '');

    $isLoopbackSite = in_array($siteHost, ['localhost', '127.0.0.1', '[::1]'], true);

    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }

        $parts = explode(',', $candidate);
        $host = strtolower(trim((string) ($parts[0] ?? ''), " \t\n\r\0\x0B."));
        if ($host === '' || preg_match('/^(\[[0-9a-f:]+\]|[a-z0-9.-]+)(?::(\d{1,5}))?$/i', $host, $match) !== 1) {
            continue;
        }

        // Ohne konfigurierte SITE_URL (z. B. Installer) gibt es keine Referenz für eine Allowlist.
        if ($siteHost === '') {
            return $host;
        }

        $hostname = trim((string) $match[1], '.');
        if (!cms_runtime_host_is_allowed($hostname, $siteHost)) {
            continue;
        }

        $port = isset($match[2]) ? (int) $match[2] : 0;
        if ($port > 0 && ($port === $sitePort || $isLoopbackSite)) {
            return $hostname . ':' . $port;
        }

        return $sitePort > 0 ? $hostname . ':' . $sitePort : $hostname;
    }

    if ($siteHost === '') {
        return '';
    }

    return $sitePort > 0 ? $siteHost . ':' . $sitePort : $siteHost;
}

/**
 * Liefert die Runtime-Basis-URL der aktuellen Anfrage.
 */
function cms_runtime_base_url(string $path = ''): string {
    $siteUrl = trim((string) (defined('SITE_URL') ? SITE_URL : ''));
    if ($siteUrl === '') {
        if ($path === '') {
            return '';
        }

        return '/' . ltrim($path, '/');
    }

    $siteParts = parse_url($siteUrl);
    if (!is_array($siteParts) || empty($siteParts['host'])) {
        return $path === '' ? $siteUrl : rtrim($siteUrl, '/') . '/' . ltrim($path, '/');
    }

    $scheme = cms_runtime_scheme();
    $host = cms_runtime_host();
    if ($host === '') {
        $host = strtolower((string) $siteParts['host']);
        $port = isset($siteParts['port']) ? (int) $siteParts['port'] : 0;
        if ($port > 0) {
            $host .= ':' . $port;
        }
    } elseif (!str_contains($host, ':') && isset($siteParts['port'])) {
        $port = (int) $siteParts['port'];
        $isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        if ($port > 0 && !$isDefaultPort) {
            $host .= ':' . $port;
        }
    }

    $basePath = trim((string) ($siteParts['path'] ?? ''), '/');
    $baseUrl = $scheme . '://' . $host;
    if ($basePath !== '') {
        $baseUrl .= '/' . $basePath;
    }

    if ($path === '') {
        return $baseUrl;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

/**
 * Liefert die Basis-URL des Asset-Verzeichnisses oder einen Asset-Unterpfad.
 */
function cms_assets_url(string $path = ''): string {
    $baseUrl = cms_runtime_base_url('assets');
    $baseUrl = rtrim(str_replace('\\', '/', $baseUrl), '/');

    if ($path === '') {
        return $baseUrl;
    }

    return $baseUrl . '/' . ltrim(str_replace('\\', '/', $path), '/');
}

/**
 * Liefert den Dateisystempfad des Asset-Verzeichnisses oder eines Asset-Unterpfads.
 */
function cms_assets_path(string $path = ''): string {
    $basePath = defined('ASSETS_PATH')
        ? (string) ASSETS_PATH
        : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR;

    $basePath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $basePath), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if ($path === '') {
        return $basePath;
    }

    return $basePath . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
}

/**
 * Liefert die Asset-Version auf Basis des Dateistands oder einen definierten Fallback.
 */
function cms_asset_version(string $relativePath, string|int|null $fallback = ''): string {
    $assetPath = cms_assets_path($relativePath);

    if (is_file($assetPath)) {
        $modified = filemtime($assetPath);
        if ($modified !== false) {
            return (string) $modified;
        }
    }

    return $fallback === null ? '' : (string) $fallback;
}

/**
 * Liefert eine Asset-URL optional mit zentraler Versionierung.
 */
function cms_asset_url(string $relativePath, bool $withVersion = true, string|int|null $fallbackVersion = ''): string {
    $url = cms_assets_url($relativePath);

    if (!$withVersion) {
        return $url;
    }

    $version = cms_asset_version($relativePath, $fallbackVersion);

    return $version !== '' ? $url . '?v=' . rawurlencode($version) : $url;
}

/**
 * Liefert die CSP-Runtime-Scripts (DOMPurify + Trusted-Types-/Style-Nonce-Runtime).
 *
 * Muss als erstes Script im <head> jeder HTML-Seite ausgegeben werden, die unter
 * der nonce-basierten CSP mit `require-trusted-types-for 'script'` läuft.
 * Mehrfachaufrufe pro Request liefern nur beim ersten Mal Markup.
 *
 * @param bool $deferSanitizer Frontend: DOMPurify per defer, Runtime inline (nicht render-blockierend).
 */
function cms_csp_runtime_tags(bool $deferSanitizer = false): string {
    static $rendered = false;
    if ($rendered) {
        return '';
    }
    $rendered = true;

    // Module (z. B. Analytics) können hier gezielt CSP-Quellen freigeben, bevor
    // die Runtime ihre Trusted-Types-Script-Origins übernimmt.
    if (class_exists(\CMS\Hooks::class)) {
        \CMS\Hooks::doAction('cms_csp_prepare');
    }

    $nonceAttr = class_exists(\CMS\Security::class) ? \CMS\Security::instance()->nonceAttr() : '';
    $nonceAttr = $nonceAttr !== '' ? ' ' . $nonceAttr : '';
    $scriptOrigins = class_exists(\CMS\Security::class) ? \CMS\Security::instance()->getAllowedScriptOrigins() : [];
    $originsAttr = $scriptOrigins !== []
        ? ' data-script-origins="' . htmlspecialchars(implode(' ', $scriptOrigins), ENT_QUOTES, 'UTF-8') . '"'
        : '';
    $purifyUrl = htmlspecialchars(cms_asset_url('dompurify/purify.min.js'), ENT_QUOTES, 'UTF-8');

    if ($deferSanitizer) {
        // Frontend (PageSpeed): DOMPurify (~29 KB) nicht render-blockierend per defer – es läuft vor allen
        // späteren defer-Scripts; die kleine Runtime wird inline ausgegeben (kein blockierender Request).
        // Nur für Seiten, deren Scripts HTML erst per defer/nach dem Laden zuweisen.
        $runtimeFile = rtrim(defined('ASSETS_PATH') ? (string) ASSETS_PATH : ABSPATH . 'assets/', '/\\') . '/js/cms-csp-runtime.js';
        $runtime = is_readable($runtimeFile) ? (string) file_get_contents($runtimeFile) : '';
        // „</script“ (etwa im Doc-Kommentar) würde das Inline-Script vorzeitig beenden.
        $runtime = str_ireplace('</script', '<\\/script', $runtime);
        if ($runtime !== '') {
            return '<script src="' . $purifyUrl . '"' . $nonceAttr . ' defer></script>' . "\n"
                . '<script id="cms-csp-runtime"' . $nonceAttr . $originsAttr . '>' . $runtime . '</script>' . "\n";
        }
    }

    return '<script src="' . $purifyUrl . '"' . $nonceAttr . '></script>' . "\n"
        . '<script src="' . htmlspecialchars(cms_asset_url('js/cms-csp-runtime.js'), ENT_QUOTES, 'UTF-8') . '"' . $nonceAttr . $originsAttr . '></script>' . "\n";
}
