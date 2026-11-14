<?php
declare(strict_types=1);

namespace CMS\Services\SEO {
    final class SeoSettingsStore
    {
        /** @param array<string, string> $values */
        public function __construct(private array $values)
        {
        }

        public function getSetting(string $key, string $default = ''): string
        {
            return $this->values[$key] ?? $default;
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    define('ABSPATH', $root . 'CMS' . DIRECTORY_SEPARATOR);
    require ABSPATH . 'core/Services/SEO/SeoAnalyticsRenderer.php';

    use CMS\Services\SEO\SeoAnalyticsRenderer;
    use CMS\Services\SEO\SeoSettingsStore;

    /** @throws RuntimeException */
    function analyticsAssert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function analyticsRenderer(array $settings): SeoAnalyticsRenderer
    {
        return new SeoAnalyticsRenderer(new SeoSettingsStore($settings));
    }

    // 1) Ohne Konfiguration keine Ausgabe und keine CSP-Erweiterung
    $empty = analyticsRenderer([]);
    analyticsAssert($empty->getAnalyticsHeadCode() === '', 'Ohne Anbieter darf kein Analytics-Code ausgegeben werden.');
    analyticsAssert($empty->getCspSources() === [], 'Ohne Anbieter darf die CSP nicht erweitert werden.');

    // 2) Gültige IDs → JSON-Konfiguration + externer Loader, kein Inline-Tracking-Code
    $renderer = analyticsRenderer([
        'analytics_ga4_id' => 'g-abc12345',
        'analytics_gtm_id' => 'GTM-XY12Z',
        'analytics_fb_pixel_id' => '123456789',
        'analytics_matomo_url' => 'https://stats.example.org/matomo',
        'analytics_matomo_site_id' => '3',
        'analytics_anonymize_ip' => '1',
    ]);
    $config = $renderer->getClientConfig();
    analyticsAssert(array_keys($config['providers']) === ['matomo', 'ga4', 'gtm', 'metaPixel'], 'Aktive Anbieter wurden nicht erkannt.');
    analyticsAssert($config['providers']['ga4']['id'] === 'G-ABC12345' && $config['providers']['ga4']['category'] === 'analytics', 'GA4 falsch normalisiert.');
    analyticsAssert($config['providers']['gtm']['category'] === 'marketing' && $config['providers']['metaPixel']['category'] === 'marketing', 'GTM/Pixel müssen die Marketing-Einwilligung verlangen.');
    $head = $renderer->getAnalyticsHeadCode();
    analyticsAssert(str_contains($head, 'id="cms-analytics-config"') && str_contains($head, 'js/cms-analytics.js'), 'Konfiguration oder Loader fehlen.');
    analyticsAssert(!str_contains($head, 'gtag(') && !str_contains($head, '_paq') && !str_contains($head, 'fbq('), 'Es darf kein Inline-Tracking-Code ausgegeben werden.');

    // 3) CSP nur für die konfigurierten Hosts
    $csp = $renderer->getCspSources();
    analyticsAssert(in_array('https://stats.example.org', $csp['script-src'], true), 'Matomo-Origin fehlt in script-src.');
    analyticsAssert(in_array('https://www.googletagmanager.com', $csp['script-src'], true) && in_array('https://connect.facebook.net', $csp['script-src'], true), 'Anbieter-Hosts fehlen in script-src.');
    analyticsAssert(!in_array("'unsafe-inline'", array_merge(...array_values($csp)), true), 'CSP darf nicht aufgeweicht werden.');

    // 4) Manipulierte IDs/URLs werden verworfen
    $evil = analyticsRenderer([
        'analytics_ga4_id' => "G-ABC');alert(1);//",
        'analytics_gtm_id' => 'GTM-"><script>',
        'analytics_fb_pixel_id' => '12ab',
        'analytics_matomo_url' => 'http://insecure.example.org/',
    ]);
    analyticsAssert($evil->getClientConfig()['providers'] === [], 'Ungültige IDs oder unsichere Matomo-URL wurden akzeptiert.');
    analyticsAssert($evil->getAnalyticsHeadCode() === '', 'Für ungültige Konfiguration wurde Code ausgegeben.');

    // 5) Explizit deaktivierte Alt-Flags werden respektiert, Admin-Ausschluss greift
    $disabled = analyticsRenderer(['analytics_ga4_id' => 'G-ABC12345', 'analytics_ga4_enabled' => '0']);
    analyticsAssert($disabled->getClientConfig()['providers'] === [], 'Explizit deaktivierter Anbieter wurde geladen.');
    $_SESSION['user_role'] = 'admin';
    $excluded = analyticsRenderer(['analytics_ga4_id' => 'G-ABC12345', 'analytics_exclude_admins' => '1']);
    analyticsAssert($excluded->getAnalyticsHeadCode() === '' && $excluded->getCspSources() === [], 'Admin-Ausschluss greift nicht.');
    unset($_SESSION['user_role']);

    echo "Analytics consent loader tests passed.\n";
}
