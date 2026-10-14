<?php
declare(strict_types=1);

/**
 * Meridian CMS Default Theme – Functions
 *
 * @package CMSDefault
 * @version 1.0.9
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('MERIDIAN_THEME_FUNCTIONS_LOADED')) {
    return;
}

define('MERIDIAN_THEME_FUNCTIONS_LOADED', true);

define('MERIDIAN_THEME_VERSION', '1.0.9');
define('MERIDIAN_THEME_DIR',     THEME_PATH . 'cms-default/');
define('MERIDIAN_THEME_URL',     \CMS\ThemeManager::instance()->getThemeUrl());

/**
 * Liest eine Option aus `settings` – über den Core-Options-Cache (ab 3.4.12),
 * auf älteren Cores per Einzelabfrage.
 */
function meridian_option(string $name, ?string $default = null): ?string
{
    if (class_exists('\\CMS\\Services\\OptionStore')) {
        return \CMS\Services\OptionStore::getInstance()->get($name, $default);
    }

    try {
        $db = \CMS\Database::instance();
        $value = $db->get_var("SELECT option_value FROM {$db->getPrefix()}settings WHERE option_name = ? LIMIT 1", [$name]);
    } catch (\Throwable) {
        return $default;
    }

    return $value !== null ? (string) $value : $default;
}

/**
 * @param list<string> $names
 * @return array<string, ?string>
 */
function meridian_options(array $names, ?string $default = null): array
{
    if (class_exists('\\CMS\\Services\\OptionStore')) {
        return \CMS\Services\OptionStore::getInstance()->getMany($names, $default);
    }

    $result = [];
    foreach ($names as $name) {
        $result[$name] = meridian_option($name, $default);
    }

    return $result;
}

/**
 * Meridian CMS Default Theme Bootstrap
 */
class MeridianCMSDefaultTheme
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Head-Assets
        \CMS\Hooks::addAction('head', [$this, 'outputPreconnect'],       1);
        \CMS\Hooks::addAction('head', [$this, 'outputLocalFonts'],       5);
        \CMS\Hooks::addAction('head', [$this, 'enqueueStyles'],         10);
        \CMS\Hooks::addAction('head', [$this, 'outputMetaTags'],        15);
        \CMS\Hooks::addAction('head', [$this, 'outputCustomStyles'],    20);
        \CMS\Hooks::addAction('head', [$this, 'outputCustomHeaderCode'], 99);

        // Footer-Scripts
        \CMS\Hooks::addAction('before_footer', [$this, 'enqueueScripts'], 10);
        \CMS\Hooks::addAction('before_footer', [$this, 'outputCookieBanner'], 20);

        // Menüpositionen
        \CMS\Hooks::addFilter('register_menu_locations', [$this, 'registerMenuLocations']);

        // Standard-Menü beim ersten Start
        \CMS\Hooks::addAction('cms_init', [$this, 'seedDefaultMenus']);
    }

    // ─── Preconnect / Performance ───────────────────────────────────────────

    public function outputPreconnect(): void
    {
        if (!$this->shouldLoadExternalFonts()) {
            return;
        }

        echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    }

    /**
     * Prüft ob Local Fonts (DSGVO) im CMS aktiviert sind
     */
    private ?bool $localFontsEnabled = null;

    private function isLocalFontsEnabled(): bool
    {
        // Wird pro Request mehrfach abgefragt (outputLocalFonts + shouldLoadExternalFonts).
        if ($this->localFontsEnabled !== null) {
            return $this->localFontsEnabled;
        }

        return $this->localFontsEnabled = (meridian_option('privacy_use_local_fonts', '0') === '1');
    }

    // ─── Local / Remote Fonts ───────────────────────────────────────────────

    public function outputLocalFonts(): void
    {
        $localCssPath = defined('ASSETS_PATH') ? ASSETS_PATH . 'css/local-fonts.css' : '';
        $localCssUrl  = function_exists('cms_asset_url')
            ? cms_asset_url('css/local-fonts.css')
            : (defined('SITE_URL') ? SITE_URL . '/assets/css/local-fonts.css' : '');

        if ($this->isLocalFontsEnabled() && $localCssPath && file_exists($localCssPath) && $localCssUrl) {
            $href = htmlspecialchars($localCssUrl, ENT_QUOTES, 'UTF-8');
            echo '<link rel="stylesheet" href="' . $href . '">' . "\n";

            return;
        }

        if (!$this->shouldLoadExternalFonts()) {
            return;
        }

        $fontUrl = $this->getGoogleFontsStylesheetUrl();
        if ($fontUrl !== '') {
            echo '<link href="' . htmlspecialchars($fontUrl, ENT_QUOTES, 'UTF-8') . '" rel="stylesheet">' . "\n";
        }
    }

    private function shouldLoadExternalFonts(): bool
    {
        if ($this->isLocalFontsEnabled()) {
            return false;
        }

        try {
            // Standard aus: externe Fonts übermitteln die Besucher-IP an Google (DSGVO).
            return (bool) \CMS\Services\ThemeCustomizer::instance()->get('typography', 'google_fonts', false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getGoogleFontsStylesheetUrl(): string
    {
        try {
            $customizer = \CMS\Services\ThemeCustomizer::instance();
            $fontBody = (string) $customizer->get('typography', 'font_family_body', 'dm-sans');
            $fontHeading = (string) $customizer->get('typography', 'font_family_heading', 'libre-baskerville');
        } catch (\Throwable $e) {
            $fontBody = 'dm-sans';
            $fontHeading = 'libre-baskerville';
        }

        $googleMap = [
            'dm-sans' => 'DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400',
            'libre-baskerville' => 'Libre+Baskerville:ital,wght@0,400;0,700;1,400',
            'inter' => 'Inter:wght@400;500;600;700',
            'playfair-display' => 'Playfair+Display:ital,wght@0,400;0,700;1,400',
            'merriweather' => 'Merriweather:ital,wght@0,400;0,700;1,400',
        ];

        $families = [];

        if (isset($googleMap[$fontBody])) {
            $families[] = $googleMap[$fontBody];
        }

        if ($fontHeading !== $fontBody && isset($googleMap[$fontHeading])) {
            $families[] = $googleMap[$fontHeading];
        }

        if (!in_array('DM+Mono:wght@400;500', $families, true)) {
            $families[] = 'DM+Mono:wght@400;500';
        }

        if ($families === []) {
            return '';
        }

        return 'https://fonts.googleapis.com/css2?family=' . implode('&family=', $families) . '&display=swap';
    }

    /** @return array{font_body:string,font_heading:string,font_size_base:?int,font_line_height:?float} */
    private function getLocalFontRuntimeSettings(): array
    {
        try {
            $db = \CMS\Database::instance();
            $rows = $db->get_results(
                "SELECT option_name, option_value FROM {$db->getPrefix()}settings WHERE option_name IN ('font_body', 'font_heading', 'font_size_base', 'font_line_height')"
            ) ?: [];
        } catch (\Throwable $e) {
            return [
                'font_body' => '',
                'font_heading' => '',
                'font_size_base' => null,
                'font_line_height' => null,
            ];
        }

        $settings = [
            'font_body' => '',
            'font_heading' => '',
            'font_size_base' => null,
            'font_line_height' => null,
        ];

        foreach ($rows as $row) {
            $name = (string) ($row->option_name ?? '');
            $value = trim((string) ($row->option_value ?? ''));

            if ($name === 'font_body' || $name === 'font_heading') {
                $settings[$name] = $this->sanitizeFontKey($value);
                continue;
            }

            if ($name === 'font_size_base' && $value !== '' && is_numeric($value)) {
                $settings[$name] = max(10, min(72, (int) round((float) $value)));
                continue;
            }

            if ($name === 'font_line_height' && $value !== '' && is_numeric(str_replace(',', '.', $value))) {
                $settings[$name] = max(1.0, min(3.0, (float) str_replace(',', '.', $value)));
            }
        }

        return $settings;
    }

    private function resolveConfiguredFontStack(string $fontKey, string $fallback): string
    {
        $fontKey = $this->sanitizeFontKey($fontKey);
        if ($fontKey === '') {
            return $fallback;
        }

        $customStack = $this->getStoredFontStack($fontKey);
        if ($customStack !== '') {
            return $customStack;
        }

        return match ($fontKey) {
            'system-ui' => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
            'arial' => 'Arial, "Helvetica Neue", Helvetica, sans-serif',
            'georgia' => 'Georgia, "Times New Roman", Times, serif',
            'times-new-roman' => '"Times New Roman", Times, serif',
            'courier-new' => '"Courier New", Courier, monospace',
            'verdana' => 'Verdana, Geneva, sans-serif',
            'trebuchet-ms' => '"Trebuchet MS", "Lucida Grande", sans-serif',
            'inter' => '"Inter", system-ui, sans-serif',
            'dm-sans' => '"DM Sans", system-ui, sans-serif',
            'libre-baskerville' => '"Libre Baskerville", Georgia, serif',
            'playfair-display' => '"Playfair Display", Georgia, serif',
            'merriweather' => '"Merriweather", Georgia, serif',
            default => $fallback,
        };
    }

    private function getStoredFontStack(string $fontKey): string
    {
        try {
            $value = meridian_option('font_stack_' . $fontKey, '');
        } catch (\Throwable $e) {
            return '';
        }

        return trim((string) $value);
    }

    private function sanitizeFontKey(string $fontKey): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($fontKey))) ?? '';
    }

    // ─── Stylesheets ────────────────────────────────────────────────────────

    public function enqueueStyles(): void
    {
        $themeUrl = MERIDIAN_THEME_URL;
        $version  = MERIDIAN_THEME_VERSION;
        $href = $themeUrl . '/style.css?v=' . $version;
        if (class_exists('\\CMS\\Services\\AssetOptimizerService')) {
            $href = \CMS\Services\AssetOptimizerService::instance()->getAssetUrl(
                MERIDIAN_THEME_DIR . 'style.css',
                $themeUrl . '/style.css',
                'css',
                $version
            );
        }

        echo '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }

    // ─── Meta Tags ──────────────────────────────────────────────────────────

    public function outputMetaTags(): void
    {
        // Vollständige SEO-Ausgabe des Cores: Beschreibung, Robots, Canonical, Open Graph,
        // Twitter Cards und JSON-LD inkl. Redaktions-Overrides aus dem SEO-Panel.
        $headTags = '';
        try {
            $seo = \CMS\Services\SEOService::getInstance();
            $headTags = method_exists($seo, 'renderCurrentHeadTags') ? $seo->renderCurrentHeadTags() : '';
            if ($headTags === '') {
                $metaDesc = trim($seo->getMetaDescription());
                if ($metaDesc !== '') {
                    $headTags = '<meta name="description" content="' . htmlspecialchars($metaDesc, ENT_QUOTES, 'UTF-8') . '">' . "\n";
                }
            }
        } catch (\Throwable $e) {
            $headTags = '';
        }

        echo $headTags;
        echo '<meta name="theme-color" content="#f7f6f2">' . "\n";
        echo '<link rel="alternate" type="application/rss+xml" title="' . htmlspecialchars((defined('SITE_NAME') ? SITE_NAME : '365CMS') . ' – Feed', ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars(rtrim((string) SITE_URL, '/') . '/feed', ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }

    // ─── Custom CSS aus Customizer ───────────────────────────────────────────

    public function outputCustomStyles(): void
    {
        try {
            $c = \CMS\Services\ThemeCustomizer::instance();

            // ── Farben ────────────────────────────────────────────────────────
            $accent       = (string)$c->get('colors', 'accent_color',        '#c0862a');
            $accentDark   = (string)$c->get('colors', 'accent_dark_color',   '#a06b18');
            $ink          = (string)$c->get('colors', 'ink_color',           '#1a1a18');
            $inkSoft      = (string)$c->get('colors', 'ink_soft_color',      '#3d3d3a');
            $inkMuted     = (string)$c->get('colors', 'ink_muted_color',     '#7a7a74');
            $ground       = (string)$c->get('colors', 'ground_color',        '#f7f6f2');
            $surface      = (string)$c->get('colors', 'surface_color',       '#ffffff');
            $surfaceTint  = (string)$c->get('colors', 'surface_tint_color',  '#f2f1ec');
            $rule         = (string)$c->get('colors', 'rule_color',          '#e2e0d8');
            $headerBg     = (string)$c->get('colors', 'header_bg_color',     '#ffffff');
            $stripe       = (string)$c->get('colors', 'header_stripe_color', '#1a1a18');
            $linkColor    = (string)$c->get('colors', 'link_color',          $accent);
            $linkHover    = (string)$c->get('colors', 'link_hover_color',    $accentDark);
            $catBarBg     = (string)$c->get('colors', 'category_bar_bg',     '#f2f1ec');
            $catBarText   = (string)$c->get('colors', 'category_bar_text',   '#3d3d3a');

            // ── Footer ────────────────────────────────────────────────────────
            $footerBg     = (string)$c->get('footer', 'footer_bg_color',     '#1a1a18');
            $footerText   = (string)$c->get('footer', 'footer_text_color',   '#9a9a94');
            $footerAccent = (string)$c->get('footer', 'footer_accent_color', '#c0862a');

            // ── Layout ────────────────────────────────────────────────────────
            $maxWidth     = max(600, (int)$c->get('layout', 'max_width',       1140));
            $colWidth     = max(300, (int)$c->get('layout', 'post_col_width',   680));
            $borderRadius = max(0,   (int)$c->get('layout', 'border_radius',     3));
            $cardGap      = max(4,   (int)$c->get('layout', 'card_gap',          24));
            $stickyHeader = (string)$c->get('layout', 'sticky_header', '1') !== '0';

            // ── Typografie ────────────────────────────────────────────────────
            $baseFontSz    = max(10, (int)$c->get('typography', 'font_size_base',      15));
            $lineHeight    = (float)str_replace(',', '.', (string)$c->get('typography', 'line_height', '1.6'));
            if ($lineHeight < 1.0 || $lineHeight > 3.0) { $lineHeight = 1.6; }
            $headingWeight = (int)$c->get('typography', 'heading_weight', 700);
            if (!in_array($headingWeight, [600, 700, 800, 900])) { $headingWeight = 700; }
            $fontBody      = (string)$c->get('typography', 'font_family_body',    'dm-sans');
            $fontHeading   = (string)$c->get('typography', 'font_family_heading', 'libre-baskerville');
            $lsHeadings    = (string)$c->get('typography', 'letter_spacing_headings', '0');
            $h1Size        = max(18, (int)$c->get('typography', 'h1_size', 38));
            $h2Size        = max(14, (int)$c->get('typography', 'h2_size', 28));
            $h3Size        = max(12, (int)$c->get('typography', 'h3_size', 22));

            if ($this->isLocalFontsEnabled()) {
                $fontRuntimeSettings = $this->getLocalFontRuntimeSettings();

                if ($fontRuntimeSettings['font_body'] !== '') {
                    $fontBody = $fontRuntimeSettings['font_body'];
                }

                if ($fontRuntimeSettings['font_heading'] !== '') {
                    $fontHeading = $fontRuntimeSettings['font_heading'];
                }

                if ($fontRuntimeSettings['font_size_base'] !== null) {
                    $baseFontSz = $fontRuntimeSettings['font_size_base'];
                }

                if ($fontRuntimeSettings['font_line_height'] !== null) {
                    $lineHeight = $fontRuntimeSettings['font_line_height'];
                }
            }

            // ── Navigation ────────────────────────────────────────────────────
            $navFontSize   = max(11, (int)$c->get('navigation', 'nav_font_size',     14));
            $navUppercase  = (string)$c->get('navigation', 'nav_uppercase', '0') !== '0';
            $navLetterSp   = (string)$c->get('navigation', 'nav_letter_spacing', '0');

            // ── Header-Streifen ───────────────────────────────────────────────
            $stripeEnabled = (string)$c->get('header', 'header_stripe_enabled', '1') !== '0';

            // ── Erweitert ─────────────────────────────────────────────────────
            $customCss     = (string)$c->get('advanced', 'custom_css', '');

            // ── Schrift-Familien-Mapping ──────────────────────────────────────
            $fontBodyCss = $this->resolveConfiguredFontStack($fontBody, '"DM Sans", system-ui, sans-serif');
            $fontHeadingCss = $this->resolveConfiguredFontStack($fontHeading, '"Libre Baskerville", Georgia, serif');

            $esc = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

            echo '<style id="meridian-custom-vars" ' . \CMS\Security::instance()->nonceAttr() . '>' . "\n";

            // ── CSS Custom Properties (:root) ─────────────────────────────────
            // Abgeleitete Farben berechnen (leere DB-Werte auf Style.css-Defaults zurückfallen)
            $accent      = $accent      ?: '#c0862a';
            $accentDark  = $accentDark  ?: '#a06b18';
            $ink         = $ink         ?: '#1a1a18';
            $inkSoft     = $inkSoft     ?: '#3d3d3a';
            $inkMuted    = $inkMuted    ?: '#7a7a74';
            $ground      = $ground      ?: '#f7f6f2';
            $surface     = $surface     ?: '#ffffff';
            $surfaceTint = $surfaceTint ?: '#f2f1ec';
            $rule        = $rule        ?: '#e2e0d8';
            $headerBg    = $headerBg    ?: '#ffffff';
            $stripe      = $stripe      ?: '#1a1a18';
            $linkColor   = $linkColor   ?: $accent;
            $linkHover   = $linkHover   ?: $accentDark;
            $catBarBg    = $catBarBg    ?: '#f2f1ec';
            $catBarText  = $catBarText  ?: '#3d3d3a';
            $footerBg    = $footerBg    ?: '#1a1a18';
            $footerText  = $footerText  ?: '#9a9a94';
            $footerAccent = $footerAccent ?: '#c0862a';
            // Barrierefreiheit: Sekundärtexte (Datum, Lesezeit, Meta) und Akzent-Links müssen auf
            // Grund- und Kartenfläche WCAG AA (4,5:1) erreichen. Die Voreinstellungen lagen bei
            // 4,0:1 (ink-muted), 2,0:1 (ink-ghost) und 3,1:1 (accent als Textfarbe).
            $textBackgrounds = [$ground, $surface, $surfaceTint];
            $inkMuted = $this->ensureReadableTextColor($inkMuted, $textBackgrounds);
            $inkGhost = $this->ensureReadableTextColor($this->lightenHex($inkMuted, 0.12), $textBackgrounds);
            $accentText = $this->ensureReadableTextColor($accent, $textBackgrounds);
            $linkColor = $this->ensureReadableTextColor($linkColor, $textBackgrounds);
            $linkHover = $this->ensureReadableTextColor($linkHover, $textBackgrounds);

            echo ':root {' . "\n";
            echo '  --accent:           ' . $esc($accent)       . ";\n";
            echo '  --accent-dark:      ' . $esc($accentDark)   . ";\n";
            echo '  --accent-text:      ' . $esc($accentText)   . ";\n";
            echo '  --accent-light:     ' . $this->hexToRgba($accent, 0.08) . ";\n";
            echo '  --accent-mid:       ' . $this->hexToRgba($accent, 0.15) . ";\n";
            echo '  --ink:              ' . $esc($ink)          . ";\n";
            echo '  --ink-soft:         ' . $esc($inkSoft)      . ";\n";
            echo '  --ink-muted:        ' . $esc($inkMuted)     . ";\n";
            echo '  --ink-ghost:        ' . $esc($inkGhost)     . ";\n";
            echo '  --ground:           ' . $esc($ground)       . ";\n";
            echo '  --surface:          ' . $esc($surface)      . ";\n";
            echo '  --surface-tint:     ' . $esc($surfaceTint)  . ";\n";
            echo '  --rule:             ' . $esc($rule)         . ";\n";
            echo '  --rule-heavy:       ' . $esc($rule)         . ";\n";
            echo '  --tag-bg:           ' . $esc($surfaceTint)  . ";\n";
            echo '  --tag-color:        ' . $esc($inkMuted)     . ";\n";
            echo '  --max:              ' . $maxWidth           . "px;\n";
            echo '  --col:              ' . $colWidth           . "px;\n";
            echo '  --r:                ' . $borderRadius       . "px;\n";
            echo '  --font-sans:        ' . $fontBodyCss        . ";\n";
            echo '  --font-serif:       ' . $fontHeadingCss     . ";\n";
            echo '  --card-gap:         ' . $cardGap            . "px;\n";
            echo '}' . "\n";

            // ── Basis-Elemente ────────────────────────────────────────────────
            echo 'body { font-size:' . $baseFontSz . 'px; line-height:' . $lineHeight . '; background:' . $esc($ground) . '; color:' . $esc($ink) . "; }\n";
            echo 'h1,h2,h3,h4,h5,h6 { font-weight:' . $headingWeight . '; letter-spacing:' . $esc($lsHeadings) . "; }\n";
            echo 'h1 { font-size:' . $h1Size . "px; }\n";
            echo 'h2 { font-size:' . $h2Size . "px; }\n";
            echo 'h3 { font-size:' . $h3Size . "px; }\n";
            // Post-Body-Links separat, damit globaler a:hover nicht Menü/Buttons betrifft
            echo '.post-body a, .article-content a { color:' . $esc($linkColor) . "; }\n";
            echo '.post-body a:hover, .article-content a:hover { color:' . $esc($linkHover) . "; }\n";

            // ── Header ────────────────────────────────────────────────────────
            echo '.site-header { background:' . $esc($headerBg) . "; }\n";
            echo '.site-header::before { height:' . ($stripeEnabled ? '3px' : '0') . '; background:' . $esc($stripe) . "; }\n";
            if (!$stickyHeader) {
                echo ".site-header { position:static !important; top:auto !important; }\n";
            }

            // ── Navigation ────────────────────────────────────────────────────
            $navTransform = $navUppercase ? 'uppercase' : 'none';
            echo '.main-nav a, .nav-link { font-size:' . $navFontSize . 'px; text-transform:' . $navTransform . '; letter-spacing:' . $esc($navLetterSp) . "; }\n";
            echo '.category-bar { background:' . $esc($catBarBg) . "; }\n";
            echo '.category-bar a, .category-bar .cat-label { color:' . $esc($catBarText) . "; }\n";
            echo '.category-bar a:hover, .category-bar a.active { color:' . $esc($accentText) . "; }\n";

            // ── Karten & Grid ─────────────────────────────────────────────────
            echo '.card-grid, .articles-grid, .dashboard-grid { gap:' . $cardGap . "px; }\n";
            echo '.article-row + .article-row { margin-top:' . $cardGap . "px; }\n";

            // ── Footer ────────────────────────────────────────────────────────
            echo 'footer, .site-footer { background:' . $esc($footerBg) . '; color:' . $esc($footerText) . "; }\n";
            echo 'footer a, .site-footer a { color:' . $esc($footerText) . "; }\n";
            echo 'footer a:hover, .site-footer a:hover, .ft-col a:hover { color:' . $esc($footerAccent) . " !important; }\n";
            echo '.ft-col h4, .ft-col h2, .ft-col-title, .footer-col-title { color:' . $esc($footerAccent) . "; }\n";
            echo '.ft-copyright { color:' . $esc($footerText) . "; opacity:.7; }\n";

            // ── Custom CSS ────────────────────────────────────────────────────
            if ($customCss && trim($customCss) !== '') {
                echo "\n/* ── Custom CSS (Theme Customizer) ── */\n" . $customCss . "\n";
            }

            echo '</style>' . "\n";

        } catch (\Throwable $e) {
            error_log('meridian outputCustomStyles error: ' . $e->getMessage());
        }
    }

    /**
     * Hilfsfunktion: HEX zu rgba()
     */
    private function hexToRgba(string $hex, float $alpha): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . $alpha . ')';
    }

    /**
     * Hilfsfunktion: Farbe in Richtung Weiß aufhellen (für --ink-ghost Ableitung)
     * $factor 0 = unverändert · 1 = weiß
     */
    private function lightenHex(string $hex, float $factor): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = (int)round(hexdec(substr($hex, 0, 2)) + (255 - hexdec(substr($hex, 0, 2))) * $factor);
        $g = (int)round(hexdec(substr($hex, 2, 2)) + (255 - hexdec(substr($hex, 2, 2))) * $factor);
        $b = (int)round(hexdec(substr($hex, 4, 2)) + (255 - hexdec(substr($hex, 4, 2))) * $factor);
        return sprintf('#%02x%02x%02x', min(255, $r), min(255, $g), min(255, $b));
    }

    /**
     * WCAG-Kontrastverhältnis zweier Hex-Farben (1–21).
     */
    private function contrastRatio(string $foreground, string $background): float
    {
        $luminance = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            if (strlen($hex) === 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }
            if (preg_match('/^[0-9a-f]{6}$/i', $hex) !== 1) {
                return 0.0;
            }
            $channels = [];
            foreach ([0, 2, 4] as $offset) {
                $c = hexdec(substr($hex, $offset, 2)) / 255;
                $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            }
            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };

        $a = $luminance($foreground);
        $b = $luminance($background);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * Dunkelt eine Textfarbe schrittweise ab, bis sie auf allen Hintergründen WCAG AA (4,5:1)
     * erreicht. Bereits ausreichende (auch individuell gewählte) Farben bleiben unverändert.
     *
     * @param list<string> $backgrounds
     */
    private function ensureReadableTextColor(string $color, array $backgrounds, float $minimum = 4.5): string
    {
        $hex = ltrim($color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (preg_match('/^[0-9a-f]{6}$/i', $hex) !== 1) {
            return $color;
        }

        $rgb = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        for ($step = 0; $step < 40; $step++) {
            $candidate = sprintf('#%02x%02x%02x', ...$rgb);
            $readable = true;
            foreach ($backgrounds as $background) {
                if ($this->contrastRatio($candidate, $background) < $minimum) {
                    $readable = false;
                    break;
                }
            }
            if ($readable) {
                return $step === 0 ? $color : $candidate;
            }
            $rgb = array_map(static fn(int|float $c): int => max(0, (int) floor($c * 0.94)), $rgb);
        }

        return sprintf('#%02x%02x%02x', ...$rgb);
    }

    // ─── Custom Header Code (SEO / Tracking) ─────────────────────────────────

    public function outputCustomHeaderCode(): void
    {
        try {
            $code = \CMS\Services\SEOService::getInstance()->getCustomHeaderCode();
            if (!empty($code)) {
                echo "\n<!-- Custom Header Code -->\n" . $code . "\n<!-- /Custom Header Code -->\n";
            }
        } catch (\Throwable $e) {
            // SEO Service nicht verfügbar
        }
        // Customizer: eigener Head-Code (Tracking, Analytics etc.)
        try {
            $customHeadCode = \CMS\Services\ThemeCustomizer::instance()->get('advanced', 'custom_head_code', '');
            if (!empty(trim($customHeadCode))) {
                echo "\n<!-- Theme Custom Head Code -->\n" . $customHeadCode . "\n<!-- /Theme Custom Head Code -->\n";
            }
        } catch (\Throwable $e) {}
    }

    // ─── JavaScript ──────────────────────────────────────────────────────────

    public function enqueueScripts(): void
    {
        $themeUrl = MERIDIAN_THEME_URL;
        $version  = MERIDIAN_THEME_VERSION;
        $src = $themeUrl . '/js/theme.js?v=' . $version;
        if (class_exists('\\CMS\\Services\\AssetOptimizerService')) {
            $src = \CMS\Services\AssetOptimizerService::instance()->getAssetUrl(
                MERIDIAN_THEME_DIR . 'js/theme.js',
                $themeUrl . '/js/theme.js',
                'js',
                $version
            );
        }

        echo '<script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
        // Footer-Code aus Customizer
        try {
            $footerCode = \CMS\Services\ThemeCustomizer::instance()->get('advanced', 'custom_footer_code', '');
            if (!empty(trim($footerCode))) {
                echo "\n<!-- Theme Custom Footer Code -->\n" . $footerCode . "\n<!-- /Theme Custom Footer Code -->\n";
            }
        } catch (\Throwable $e) {}
    }

    // ─── Cookie Banner ────────────────────────────────────────────────────────

    public function outputCookieBanner(): void
    {
        // Der Core-Consent-Manager (Kategorien, Analytics-Gating) ersetzt den Legacy-Banner.
        if (class_exists('\\CMS\\Services\\CookieConsentService')
            && \CMS\Services\CookieConsentService::getInstance()->isManagedExternally()) {
            return;
        }

        try {
            if (meridian_option('cookie_consent_enabled', '0') !== '1') {
                return;
            }

            $keys = ['cookie_banner_text', 'cookie_accept_text', 'cookie_essential_text', 'cookie_policy_url'];
            // Gemeinsamer Options-Cache statt Einzelabfragen (vorher N+1 pro Seitenaufruf).
            $s = array_map('strval', meridian_options($keys, ''));
            $text    = htmlspecialchars($s['cookie_banner_text']    ?: 'Wir verwenden Cookies.', ENT_QUOTES, 'UTF-8');
            $accept  = htmlspecialchars($s['cookie_accept_text']    ?: 'Akzeptieren',             ENT_QUOTES, 'UTF-8');
            $essential = htmlspecialchars($s['cookie_essential_text'] ?: 'Nur Essenzielle',       ENT_QUOTES, 'UTF-8');
            $policyUrl = trim($s['cookie_policy_url']);
            $policy  = htmlspecialchars(preg_match('#^(https?://|/(?!/))#i', $policyUrl) === 1 ? $policyUrl : '#', ENT_QUOTES, 'UTF-8');
        } catch (\Throwable $e) {
            return;
        }

        $nonceAttr = \CMS\Security::instance()->nonceAttr();

        echo <<<HTML
<div id="cms-cookie-bar" role="region" aria-label="Cookie-Hinweis" style="display:none;position:fixed;bottom:0;left:0;width:100%;background:var(--ink);color:rgba(255,255,255,.7);padding:.9rem 1.5rem;z-index:9999;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;box-shadow:0 -2px 10px rgba(0,0,0,.2);">
    <p style="margin:0;font-size:.82rem;line-height:1.6;">{$text} <a href="{$policy}" style="color:var(--accent);text-decoration:underline;">Mehr erfahren</a></p>
    <div style="display:flex;gap:.6rem;flex-shrink:0;">
        <button type="button" data-cms-cookie-choice="essential" style="padding:.35rem .85rem;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:3px;color:rgba(255,255,255,.85);font-size:.78rem;cursor:pointer;">{$essential}</button>
        <button type="button" data-cms-cookie-choice="all" style="padding:.35rem .85rem;background:var(--accent-dark, #a06b18);border:1px solid var(--accent-dark, #a06b18);border-radius:3px;color:#fff;font-size:.78rem;font-weight:600;cursor:pointer;">{$accept}</button>
    </div>
</div>
<script {$nonceAttr}>
(function(){
    var b = document.getElementById('cms-cookie-bar');
    if (!b) { return; }
    var stored = null;
    try { stored = window.localStorage.getItem('cms_cookie_consent'); } catch (e) { stored = null; }
    b.style.display = stored ? 'none' : 'flex';
    function choose(value){ try { window.localStorage.setItem('cms_cookie_consent', value); } catch (e) {} b.style.display = 'none'; }
    window.cmsAcceptCookies  = function(){ choose('all'); };
    window.cmsDeclineCookies = function(){ choose('essential'); };
    b.querySelectorAll('[data-cms-cookie-choice]').forEach(function(button){
        button.addEventListener('click', function(){ choose(button.getAttribute('data-cms-cookie-choice') === 'all' ? 'all' : 'essential'); });
    });
})();
</script>
HTML;
    }

    // ─── Menüs ────────────────────────────────────────────────────────────────

    public function registerMenuLocations(array $locations): array
    {
        $locations[] = ['slug' => 'primary', 'label' => 'Hauptmenü (Header)'];
        $locations[] = ['slug' => 'secondary', 'label' => 'Sekundäres Menü (unter Header)'];
        $locations[] = ['slug' => 'mobile',  'label' => 'Mobiles Menü'];
        $locations[] = ['slug' => 'footer',  'label' => 'Footer-Navigation (allgemein)'];
        $locations[] = ['slug' => 'footer_topics', 'label' => 'Footer – Spalte Rubriken'];
        $locations[] = ['slug' => 'footer_resources', 'label' => 'Footer – Spalte Ressourcen'];
        $locations[] = ['slug' => 'footer_about', 'label' => 'Footer – Spalte Über'];
        $locations[] = ['slug' => 'footer_legal', 'label' => 'Footer – Legal-Leiste unten'];
        return $locations;
    }

    public function seedDefaultMenus(): void
    {
        $themeManager = \CMS\ThemeManager::instance();

        $defaults = [
            'primary' => [
                ['label' => 'Startseite',  'url' => '/',          'target' => '_self'],
                ['label' => 'Blog',        'url' => '/blog',      'target' => '_self'],
            ],
            'mobile'  => [
                ['label' => 'Startseite',  'url' => '/',          'target' => '_self'],
                ['label' => 'Blog',        'url' => '/blog',      'target' => '_self'],
                ['label' => 'Anmelden',    'url' => meridian_auth_path('login'), 'target' => '_self'],
            ],
            'footer'  => [
                ['label' => 'Impressum',   'url' => '/impressum',   'target' => '_self'],
                ['label' => 'Datenschutz', 'url' => '/datenschutz', 'target' => '_self'],
                ['label' => 'Kontakt',     'url' => '/contact',     'target' => '_self'],
            ],
            'footer_resources' => [
                ['label' => 'Script-Bibliothek', 'url' => '/blog', 'target' => '_self'],
                ['label' => 'Tutorials', 'url' => '/blog', 'target' => '_self'],
                ['label' => 'Suche', 'url' => '/search', 'target' => '_self'],
                ['label' => 'Newsletter', 'url' => meridian_auth_path('register'), 'target' => '_self'],
            ],
            'footer_about' => [
                ['label' => 'Über uns', 'url' => '/about', 'target' => '_self'],
                ['label' => 'Kontakt', 'url' => '/contact', 'target' => '_self'],
                ['label' => 'Impressum', 'url' => '/impressum', 'target' => '_self'],
                ['label' => 'Datenschutz', 'url' => '/datenschutz', 'target' => '_self'],
            ],
            'footer_legal' => [
                ['label' => 'Impressum', 'url' => '/impressum', 'target' => '_self'],
                ['label' => 'Datenschutz', 'url' => '/datenschutz', 'target' => '_self'],
            ],
        ];

        foreach ($defaults as $location => $items) {
            if (empty($themeManager->getMenu($location))) {
                $themeManager->saveMenu($location, $items);
            }
        }
    }
}

// Theme initialisieren
MeridianCMSDefaultTheme::instance();

/**
 * Globaler Wrapper – direkt in header.php o.ä. Templates aufrufbar.
 * Gibt den <style>-Block mit allen CSS-Variablen aus dem Customizer aus.
 */
if (!function_exists('meridian_output_custom_styles')) {
    function meridian_output_custom_styles(): void
    {
        MeridianCMSDefaultTheme::instance()->outputCustomStyles();
    }
}

/**
 * Globaler Wrapper für Preconnect + Fonts.
 * Berücksichtigt automatisch lokale Schriften (DSGVO) und Customizer-Einstellungen.
 */
if (!function_exists('meridian_output_fonts')) {
    function meridian_output_fonts(): void
    {
        MeridianCMSDefaultTheme::instance()->outputPreconnect();
        MeridianCMSDefaultTheme::instance()->outputLocalFonts();
    }
}

if (!function_exists('meridian_safe_public_url')) {
    /**
     * Normalisiert öffentliche Theme-URLs defensiv auf site-lokale oder valide absolute Ziele.
     */
    function meridian_safe_public_url(?string $value, ?string $siteUrl = null): string
    {
        $normalizedUrl = trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($normalizedUrl === '' || str_starts_with($normalizedUrl, 'data:') || str_contains($normalizedUrl, "\0")) {
            return '';
        }

        $siteBase = rtrim((string) ($siteUrl ?? (defined('SITE_URL') ? SITE_URL : '')), '/');

        if (preg_match('#^https?://#i', $normalizedUrl) === 1) {
            return filter_var($normalizedUrl, FILTER_VALIDATE_URL) ? $normalizedUrl : '';
        }

        if (str_starts_with($normalizedUrl, '/')) {
            return $siteBase !== '' ? $siteBase . $normalizedUrl : $normalizedUrl;
        }

        $relativePath = preg_replace('#^(?:\./)+#', '', $normalizedUrl) ?? '';
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return '';
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $relativePath) === 1) {
            return '';
        }

        return $siteBase !== '' ? $siteBase . '/' . $relativePath : '/' . $relativePath;
    }
}

if (!function_exists('meridian_safe_public_media_url')) {
    /**
     * Alias für öffentliche Medienpfade.
     */
    function meridian_safe_public_media_url(?string $value, ?string $siteUrl = null): string
    {
        return meridian_safe_public_url($value, $siteUrl);
    }
}

if (!function_exists('meridian_normalize_public_media_url')) {
    /**
     * Konvertiert Medienreferenzen in frontend-taugliche, kanonische URLs.
     */
    function meridian_normalize_public_media_url(?string $value, bool $preferInline = true, ?string $siteUrl = null): string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return '';
        }

        try {
            if (class_exists('\\CMS\\Services\\MediaDeliveryService')) {
                $url = \CMS\Services\MediaDeliveryService::getInstance()->normalizeUrl($url, $preferInline);
            }
        } catch (\Throwable) {
        }

        return meridian_safe_public_media_url($url, $siteUrl);
    }
}

if (!function_exists('meridian_is_image_lazy_loading_enabled')) {
    /**
     * Prüft, ob browserbasiertes Lazy Loading aktiviert ist.
     */
    function meridian_is_image_lazy_loading_enabled(): bool
    {
        static $lazyLoadingEnabled = null;

        if (is_bool($lazyLoadingEnabled)) {
            return $lazyLoadingEnabled;
        }

        $lazyLoadingEnabled = true;

        try {
            $setting = \CMS\Services\ThemeCustomizer::instance()->get('performance', 'lazyload_images', true);
            $lazyLoadingEnabled = filter_var($setting, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($lazyLoadingEnabled === null) {
                $lazyLoadingEnabled = true;
            }
        } catch (\Throwable) {
            $lazyLoadingEnabled = true;
        }

        return $lazyLoadingEnabled;
    }
}

if (!function_exists('meridian_image_loading_attributes')) {
    /**
     * Liefert standardisierte Loading-/Priority-Attribute für Theme-Bilder.
     */
    function meridian_image_loading_attributes(bool $aboveTheFold = false, bool $highPriority = true): string
    {
        if ($aboveTheFold) {
            return $highPriority
                ? 'loading="eager" fetchpriority="high" decoding="async"'
                : 'loading="eager" decoding="async"';
        }

        if (!meridian_is_image_lazy_loading_enabled()) {
            return 'decoding="async"';
        }

        return 'loading="lazy" decoding="async"';
    }
}

if (!function_exists('meridian_get_local_image_path')) {
    /**
     * Löst eine öffentliche Bildreferenz nach Möglichkeit auf einen lokalen Dateipfad auf.
     */
    function meridian_get_local_image_path(?string $reference): string
    {
        $value = trim(html_entity_decode((string) $reference, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '' || str_starts_with($value, 'data:') || str_starts_with($value, '//')) {
            return '';
        }

        $basePath = rtrim((string) ABSPATH, "\\/");
        $siteUrl = rtrim((string) (defined('SITE_URL') ? SITE_URL : ''), '/');
        $uploadUrl = rtrim((string) (defined('UPLOAD_URL') ? UPLOAD_URL : ''), '/');
        $uploadPath = rtrim((string) (defined('UPLOAD_PATH') ? UPLOAD_PATH : ''), "\\/");
        $themeUrl = rtrim((string) (defined('MERIDIAN_THEME_URL') ? MERIDIAN_THEME_URL : ''), '/');
        $themeDir = rtrim((string) (defined('MERIDIAN_THEME_DIR') ? MERIDIAN_THEME_DIR : ''), "\\/");
        $candidates = [];

        $appendAbsoluteCandidate = static function (array &$paths, string $candidate): void {
            $candidate = trim($candidate);
            if ($candidate === '') {
                return;
            }

            $paths[] = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
        };

        $appendRelativeCandidate = static function (array &$paths, string $root, string $relativePath): void {
            $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
            if ($root === '' || $relativePath === '' || str_contains($relativePath, '..')) {
                return;
            }

            $paths[] = rtrim($root, "\\/") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        };

        $appendFromSitePath = static function (array &$paths, string $path) use ($appendRelativeCandidate, $basePath): void {
            $path = trim((string) parse_url($path, PHP_URL_PATH));
            if ($path === '') {
                return;
            }

            $appendRelativeCandidate($paths, $basePath, $path);
        };

        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $value) === 1) {
            $appendAbsoluteCandidate($candidates, $value);
        } elseif (preg_match('#^https?://#i', $value) === 1) {
            if ($siteUrl !== '' && str_starts_with($value, $siteUrl . '/')) {
                $appendFromSitePath($candidates, $value);
            }

            if ($uploadUrl !== '' && str_starts_with($value, $uploadUrl . '/')) {
                $relativeUploadPath = ltrim(substr($value, strlen($uploadUrl)), '/');
                $appendRelativeCandidate($candidates, $uploadPath !== '' ? $uploadPath : $basePath, $relativeUploadPath);
            }

            if ($themeUrl !== '' && str_starts_with($value, $themeUrl . '/')) {
                $relativeThemePath = ltrim(substr($value, strlen($themeUrl)), '/');
                $appendRelativeCandidate($candidates, $themeDir, $relativeThemePath);
            }

            if (preg_match('#/media-file(?:$|\?)#', $value) === 1) {
                $query = (string) parse_url($value, PHP_URL_QUERY);
                parse_str($query, $params);
                $mediaPath = trim(str_replace('\\', '/', (string) ($params['path'] ?? '')), '/');
                $appendRelativeCandidate($candidates, $basePath, $mediaPath);
                $appendRelativeCandidate($candidates, $uploadPath !== '' ? $uploadPath : $basePath, $mediaPath);
            }
        } elseif (str_starts_with($value, '/media-file')) {
            $query = (string) parse_url($value, PHP_URL_QUERY);
            parse_str($query, $params);
            $mediaPath = trim(str_replace('\\', '/', (string) ($params['path'] ?? '')), '/');
            $appendRelativeCandidate($candidates, $basePath, $mediaPath);
            $appendRelativeCandidate($candidates, $uploadPath !== '' ? $uploadPath : $basePath, $mediaPath);
        } elseif (str_starts_with($value, '/')) {
            $appendFromSitePath($candidates, $value);
        } else {
            $appendRelativeCandidate($candidates, $uploadPath !== '' ? $uploadPath : $basePath, $value);
            $appendRelativeCandidate($candidates, $themeDir, $value);
            $appendRelativeCandidate($candidates, $basePath, $value);
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('meridian_get_image_dimensions')) {
    /**
     * Ermittelt Bilddimensionen für lokale Bilder mit kleinem Request-Cache.
     *
     * @return array{width:int,height:int}|null
     */
    function meridian_get_image_dimensions(?string $reference): ?array
    {
        static $dimensionCache = [];

        $cacheKey = trim((string) $reference);
        if ($cacheKey === '') {
            return null;
        }

        if (array_key_exists($cacheKey, $dimensionCache)) {
            return $dimensionCache[$cacheKey];
        }

        $filePath = meridian_get_local_image_path($cacheKey);
        if ($filePath === '') {
            $dimensionCache[$cacheKey] = null;
            return null;
        }

        $size = @getimagesize($filePath);
        if (!is_array($size) || empty($size[0]) || empty($size[1])) {
            $dimensionCache[$cacheKey] = null;
            return null;
        }

        $dimensionCache[$cacheKey] = [
            'width' => max(1, (int) $size[0]),
            'height' => max(1, (int) $size[1]),
        ];

        return $dimensionCache[$cacheKey];
    }
}

if (!function_exists('meridian_local_path_to_public_url')) {
    /**
     * Übersetzt einen lokalen Dateipfad zurück in eine öffentliche URL.
     */
    function meridian_local_path_to_public_url(?string $path): string
    {
        $resolvedPath = realpath(trim((string) $path));
        if ($resolvedPath === false || $resolvedPath === '') {
            return '';
        }

        $normalizedPath = str_replace('\\', '/', $resolvedPath);
        $roots = [
            [defined('UPLOAD_PATH') ? (realpath((string) UPLOAD_PATH) ?: '') : '', defined('UPLOAD_URL') ? rtrim((string) UPLOAD_URL, '/') : ''],
            [defined('MERIDIAN_THEME_DIR') ? (realpath((string) MERIDIAN_THEME_DIR) ?: '') : '', defined('MERIDIAN_THEME_URL') ? rtrim((string) MERIDIAN_THEME_URL, '/') : ''],
            [realpath((string) ABSPATH) ?: '', defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : ''],
        ];

        foreach ($roots as [$rootPath, $baseUrl]) {
            $normalizedRoot = str_replace('\\', '/', (string) $rootPath);
            if ($normalizedRoot === '' || $baseUrl === '') {
                continue;
            }

            if ($normalizedPath !== $normalizedRoot && !str_starts_with($normalizedPath, $normalizedRoot . '/')) {
                continue;
            }

            $relativePath = ltrim(substr($normalizedPath, strlen($normalizedRoot)), '/');
            if ($relativePath === '') {
                return $baseUrl;
            }

            $segments = array_map(static fn(string $segment): string => rawurlencode($segment), explode('/', $relativePath));

            return $baseUrl . '/' . implode('/', $segments);
        }

        return '';
    }
}

if (!function_exists('meridian_get_picture_sources')) {
    /**
     * Liefert bevorzugte Bildquellen inkl. optionalem lokal generiertem WebP-Fallback.
     *
     * @return array{url:string,webp_url:string,width:int,height:int}
     */
    function meridian_get_picture_sources(?string $reference, ?string $siteUrl = null, int $fallbackWidth = 0, int $fallbackHeight = 0): array
    {
        $normalizedUrl = meridian_normalize_public_media_url($reference, false, $siteUrl);
        $dimensions = meridian_get_image_dimensions($reference);

        $result = [
            'url' => $normalizedUrl,
            'webp_url' => '',
            'width' => max(0, (int) ($dimensions['width'] ?? $fallbackWidth)),
            'height' => max(0, (int) ($dimensions['height'] ?? $fallbackHeight)),
        ];

        if ($normalizedUrl === '') {
            return $result;
        }

        $sourcePath = meridian_get_local_image_path($reference);
        if ($sourcePath === '' || !is_file($sourcePath)) {
            return $result;
        }

        $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png'], true) || !class_exists('\\CMS\\Services\\ImageService')) {
            return $result;
        }

        try {
            $imageService = \CMS\Services\ImageService::getInstance();
            $imageInfo = $imageService->getInfo();

            if (!$imageService->isAvailable() || empty($imageInfo['webp_support'])) {
                return $result;
            }

            $webpPath = preg_replace('/\.[a-z0-9]+$/i', '.webp', $sourcePath);
            if (!is_string($webpPath) || $webpPath === '' || $webpPath === $sourcePath) {
                return $result;
            }

            if (!is_file($webpPath)) {
                $generatedPath = $imageService->convertToWebP($sourcePath, 78, false);
                if (!is_string($generatedPath) || !is_file($generatedPath)) {
                    return $result;
                }

                $webpPath = $generatedPath;
            }

            $sourceSize = (int) (filesize($sourcePath) ?: 0);
            $webpSize = (int) (filesize($webpPath) ?: 0);
            if ($sourceSize > 0 && $webpSize > 0 && $webpSize >= $sourceSize) {
                return $result;
            }

            $webpUrl = meridian_local_path_to_public_url($webpPath);
            if ($webpUrl === '') {
                return $result;
            }

            $result['webp_url'] = meridian_safe_public_media_url($webpUrl, $siteUrl);
        } catch (\Throwable) {
            return $result;
        }

        return $result;
    }
}

if (!function_exists('meridian_image_dimension_attributes')) {
    /**
     * Liefert width-/height-Attribute für Bilder.
     */
    function meridian_image_dimension_attributes(?string $reference, int $fallbackWidth = 0, int $fallbackHeight = 0): string
    {
        $dimensions = meridian_get_image_dimensions($reference);
        $width = max(0, (int) ($dimensions['width'] ?? $fallbackWidth));
        $height = max(0, (int) ($dimensions['height'] ?? $fallbackHeight));

        if ($width < 1 || $height < 1) {
            return '';
        }

        return 'width="' . $width . '" height="' . $height . '"';
    }
}

// ─── Template Helper-Funktionen ─────────────────────────────────────────────

/**
 * Lesezeit in Minuten berechnen (ca. 200 Wörter/Min)
 */
if (!function_exists('meridian_reading_time')) {
    function meridian_reading_time(string $content): int
    {
        $wordCount = str_word_count(strip_tags($content));
        return max(1, (int)ceil($wordCount / 200));
    }
}

/**
 * Autor-Initialen ermitteln (für Avatar-Fallback)
 */
if (!function_exists('meridian_author_initials')) {
    function meridian_author_initials(string $name): string
    {
        $parts = array_filter(explode(' ', trim($name)));
        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
        }
        return strtoupper(mb_substr($name, 0, 2));
    }
}

/**
 * Datum auf Deutsch formatieren (kurz)
 */
if (!function_exists('meridian_format_date')) {
    function meridian_format_date(string $dateStr, bool $short = true): string
    {
        if (empty($dateStr)) {
            return '';
        }
        $ts = strtotime($dateStr);
        if (!$ts) {
            return htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8');
        }
        $months = ['Jan.','Feb.','März','Apr.','Mai','Juni','Juli','Aug.','Sep.','Okt.','Nov.','Dez.'];
        $day    = (int)date('j', $ts);
        $month  = $months[(int)date('n', $ts) - 1];
        $year   = (int)date('Y', $ts);
        return $day . '. ' . $month . ' ' . $year;
    }
}

/**
 * Rendert das dezente Update-Badge für Beiträge mit manuell gepflegtem Aktualisierungsdatum.
 *
 * @param array<string,mixed>|object $post
 */
if (!function_exists('meridian_post_update_badge')) {
    function meridian_post_update_badge(array|object $post): string
    {
        $contentUpdatedAt = is_array($post)
            ? trim((string) ($post['content_updated_at'] ?? ''))
            : trim((string) ($post->content_updated_at ?? ''));

        return $contentUpdatedAt !== '' ? '<span class="meridian-update-badge">[UPDATE]</span>' : '';
    }
}

/**
 * Excerpt kürzen
 */
if (!function_exists('meridian_excerpt')) {
    function meridian_excerpt(string $content, int $maxChars = 160): string
    {
        $text = strip_tags($content);
        if (mb_strlen($text) <= $maxChars) {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
        $cut = mb_substr($text, 0, $maxChars);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }
        return htmlspecialchars($cut, ENT_QUOTES, 'UTF-8') . '…';
    }
}

/**
 * Post-Tags als Array liefern
 */
if (!function_exists('meridian_post_tags')) {
    function meridian_post_tags(string $tags): array
    {
        if (empty($tags)) {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $tags)));
    }
}

/**
 * Kategorie-Farb-Gradient (für Thumbnail-Hintergründe)
 */
if (!function_exists('meridian_cat_gradient')) {
    function meridian_cat_gradient(string $category = ''): string
    {
        $map = [
            'microsoft'    => 'linear-gradient(135deg,#1e2a3e,#1a1a18)',
            'exchange'     => 'linear-gradient(135deg,#1e2a2a,#1a1a18)',
            'powershell'   => 'linear-gradient(135deg,#1a2a1a,#1a1a18)',
            'security'     => 'linear-gradient(135deg,#2a1a1a,#1a1a18)',
            'azure'        => 'linear-gradient(135deg,#1a1a2a,#1a1a18)',
            'linux'        => 'linear-gradient(135deg,#2a2a1a,#1a1a18)',
            'guides'       => 'linear-gradient(135deg,#2a1a2a,#1a1a18)',
            'tutorials'    => 'linear-gradient(135deg,#1a2a2e,#1a1a18)',
        ];
        $key = strtolower($category);
        foreach ($map as $k => $v) {
            if (str_contains($key, $k)) {
                return $v;
            }
        }
        return 'linear-gradient(135deg,#2a2a26,#1a1a18)';
    }
}

/**
 * Gibt das aktive Menü für eine Position aus (HTML-Liste)
 * Unterstützt Dropdowns für Meridan-Layout
 */
if (!function_exists('meridian_nav_menu')) {
function meridian_nav_menu(string $location, string $currentPath = ''): void
{
    $items = [];
    try {
        $items = \CMS\ThemeManager::instance()->getMenu($location);
    } catch (\Throwable $e) {
        // Kein Menü vorhanden
    }

    if (empty($items)) {
        return;
    }

    $siteUrl = defined('SITE_URL') ? SITE_URL : '';
    
    // Helper für recursive items
    $renderItems = function(array $list) use (&$renderItems, $siteUrl, $currentPath) {
        foreach ($list as $item) {
            $label    = htmlspecialchars($item['label'] ?? '', ENT_QUOTES, 'UTF-8');
            $url      = $item['url'] ?? '#';
            $fullUrl  = str_starts_with($url, 'http') ? $url : rtrim($siteUrl, '/') . '/' . ltrim($url, '/');
            $target   = ($item['target'] ?? '_self') === '_blank' ? ' target="_blank" rel="noopener"' : '';
            
            // Active check
            $path     = parse_url($fullUrl, PHP_URL_PATH) ?: '/';
            $isActive = ($currentPath !== '' && rtrim($currentPath, '/') === rtrim($path, '/'));
            $activeClass = $isActive ? ' active' : '';
            
            // Children
            $children = $item['children'] ?? [];
            $hasChild = !empty($children);
            
            if ($hasChild) {
                echo '<div class="nav-group' . $activeClass . '">' . "\n";
                echo '  <a href="' . htmlspecialchars($fullUrl, ENT_QUOTES, 'UTF-8') . '"' . $target . '>' . $label . ' <svg viewBox="0 0 10 10"><polyline points="2,3 5,7 8,3"/></svg></a>' . "\n";
                echo '  <div class="nav-dropdown">' . "\n";
                $renderItems($children);
                echo '  </div>' . "\n";
                echo '</div>' . "\n";
            } else {
                echo '<a href="' . htmlspecialchars($fullUrl, ENT_QUOTES, 'UTF-8') . '" class="nav-link' . $activeClass . '"' . $target . '>' . $label . '</a>' . "\n";
            }
        }
    };

    $renderItems($items);
}
}

if (!function_exists('meridian_post_url')) {
/**
 * Kanonische Beitrags-URL gemäß Permalink-Struktur und Sprache. Fest verdrahtete
 * /blog/<slug>-Links liefen bei abweichender Struktur über eine 301-Weiterleitung.
 *
 * @param array<string, mixed>|object $post
 */
function meridian_post_url(array|object $post): string
{
    $data = is_object($post) ? get_object_vars($post) : $post;
    $slug = trim((string) ($data['slug'] ?? ''), '/');
    if ($slug === '') {
        return rtrim((string) SITE_URL, '/') . '/blog';
    }

    if (class_exists('\\CMS\\Services\\PermalinkService')) {
        try {
            $locale = function_exists('meridian_current_request_locale') ? meridian_current_request_locale() : 'de';
            return \CMS\Services\PermalinkService::getInstance()->buildPostUrl($data, $locale);
        } catch (\Throwable) {
        }
    }

    return rtrim((string) SITE_URL, '/') . '/blog/' . rawurlencode($slug);
}
}

if (!function_exists('meridian_archive_url')) {
/**
 * Kanonische Archiv-URL (z. B. /category/<slug>) statt der Filter-URL /blog?category=…,
 * die nur per 301 weiterleitet und interne Links verwässert.
 */
function meridian_archive_url(string $type, string $slug = ''): string
{
    $slug = trim($slug);
    if (function_exists('cms_get_archive_url')) {
        try {
            return cms_get_archive_url($type, $slug, function_exists('meridian_current_request_locale') ? meridian_current_request_locale() : null);
        } catch (\Throwable) {
        }
    }

    return rtrim((string) SITE_URL, '/') . '/blog' . ($slug !== '' ? '?' . $type . '=' . urlencode($slug) : '');
}
}

if (!function_exists('meridian_footer_fallback_links')) {
/**
 * Fallback-Links für die Footer-Spalte „Über uns“, wenn kein Menü gepflegt ist.
 * Seiten werden nur verlinkt, wenn sie veröffentlicht sind; `/contact` ist eine feste Route.
 *
 * @return list<array{label:string,url:string}>
 */
function meridian_footer_fallback_links(): array
{
    static $links = null;
    if ($links !== null) {
        return $links;
    }

    $pageExists = static function (string $slug): bool {
        try {
            $page = \CMS\PageManager::instance()->getPageBySlug($slug);
            return $page !== null && ($page['status'] ?? '') === 'published';
        } catch (\Throwable) {
            return false;
        }
    };

    $links = [];
    foreach (['about', 'ueber-uns', 'ueber'] as $slug) {
        if ($pageExists($slug)) {
            $links[] = ['label' => 'Über uns', 'url' => '/' . $slug];
            break;
        }
    }
    $links[] = ['label' => 'Kontakt', 'url' => '/contact'];
    foreach (['impressum' => 'Impressum', 'datenschutz' => 'Datenschutz'] as $slug => $label) {
        if ($pageExists($slug)) {
            $links[] = ['label' => $label, 'url' => '/' . $slug];
        }
    }

    return $links;
}
}

if (!function_exists('meridian_tag_link_data')) {
/**
 * Name und Archiv-Slug eines Tags. Akzeptiert Tag-Arrays (`name`/`title`, `slug`) und reine Namen;
 * ohne Slug wird der Name wie im Router normalisiert (Links über den Namen führten bei eigenem Slug ins 404).
 *
 * @return array{name:string,slug:string}
 */
function meridian_tag_link_data(mixed $tag): array
{
    if (is_object($tag)) {
        $tag = (array) $tag;
    }
    $name = is_array($tag) ? trim((string) ($tag['name'] ?? $tag['title'] ?? '')) : trim((string) $tag);
    $slug = is_array($tag) ? trim((string) ($tag['slug'] ?? '')) : '';
    if ($slug === '' && $name !== '') {
        $slug = (new \CMS\Routing\ThemeArchiveRepository())->normalizeArchiveSlug($name);
    }

    return ['name' => $name, 'slug' => $slug];
}
}

if (!function_exists('meridian_document_title')) {
/**
 * Eindeutiger Dokumenttitel je URL statt überall nur des Website-Namens.
 *
 * Beiträge und Seiten übernehmen den vom Core aufgelösten Meta-Titel (inkl. Titel-Template
 * und Redaktions-Override); Archive, Suche und Fehlerseiten erhalten einen sprechenden Präfix.
 *
 * @param array<string, mixed> $context Template-Variablen (category, tag, author, title, currentPage, error_code)
 */
function meridian_document_title(string $siteTitle, array $context = []): string
{
    $separator = '|';
    try {
        $seo = \CMS\Services\SEOService::getInstance();
        $separator = trim((string) $seo->getTitleSeparator()) ?: '|';
        $payload = method_exists($seo, 'getCurrentSeoPayload') ? $seo->getCurrentSeoPayload() : [];
        $statusCode = http_response_code();
        if ((!is_int($statusCode) || $statusCode < 400)
            && in_array((string) ($payload['content_type'] ?? ''), ['post', 'page', 'request', 'home'], true)
            && trim((string) ($payload['title'] ?? '')) !== ''
        ) {
            return trim((string) $payload['title']);
        }
    } catch (\Throwable) {
    }

    $readName = static function (mixed $value): string {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return '';
        }
        return trim((string) ($value['name'] ?? $value['display_name'] ?? $value['username'] ?? ''));
    };

    $label = '';
    $statusCode = http_response_code();
    $path = '/' . trim((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'), '/');
    if (is_int($statusCode) && $statusCode === 404) {
        $label = 'Seite nicht gefunden';
    } elseif (!empty($context['error_code'])) {
        $label = 'Fehler ' . (int) $context['error_code'];
    } elseif (($name = $readName($context['category'] ?? null)) !== '') {
        $label = $name;
    } elseif (($name = $readName($context['tag'] ?? null)) !== '') {
        $label = $name;
    } elseif (($name = $readName($context['author'] ?? null)) !== '') {
        $label = $name;
    } elseif (is_string($context['title'] ?? null) && trim((string) $context['title']) !== '') {
        $label = trim((string) $context['title']);
    } elseif (preg_match('#^(?:/en)?/(?:search|suche)$#', $path) === 1) {
        $query = is_string($_GET['q'] ?? null) ? trim((string) $_GET['q']) : '';
        $label = $query !== '' ? 'Suche: ' . mb_substr($query, 0, 80) : 'Suche';
    } elseif (preg_match('#^(?:/en)?/blog$#', $path) === 1) {
        $label = 'Blog';
    }

    if ($label === '') {
        return $siteTitle;
    }

    $pageNumber = (int) ($context['currentPage'] ?? 0);
    if ($pageNumber > 1) {
        $label .= ' – Seite ' . $pageNumber;
    }

    return $label . ' ' . $separator . ' ' . $siteTitle;
}
}

if (!function_exists('meridian_current_request_locale')) {
function meridian_current_request_locale(): string
{
    try {
        $requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        $context = \CMS\Services\ContentLocalizationService::getInstance()->resolveRequestContext($requestPath);

        return (string) ($context['locale'] ?? 'de');
    } catch (\Throwable $e) {
        return 'de';
    }
}

function meridian_auth_path(string $page = 'login', ?string $locale = null): string
{
    try {
        if (class_exists('\CMS\Services\CmsAuthPageService')) {
            return \CMS\Services\CmsAuthPageService::getInstance()->getPublicPath($page, $locale ?? meridian_current_request_locale());
        }
    } catch (\Throwable $e) {
    }

    return match (strtolower(trim($page))) {
        'register' => '/cms-register',
        'forgot-password' => '/cms-password-forgot',
        default => '/cms-login',
    };
}

function meridian_auth_url(string $page = 'login', array $query = [], ?string $locale = null): string
{
    $path = meridian_auth_path($page, $locale);
    if ($query !== []) {
        $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return rtrim((string) SITE_URL, '/') . $path;
}

/**
 * Helper: Eingeloggter Benutzer?
 */
function meridian_is_logged_in(): bool
{
    try {
        return \CMS\Auth::instance()->isLoggedIn();
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Helper: Flash-Message
 */
function meridian_get_flash(): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        return null;
    }
    // Check various keys
    foreach (['success', 'error', 'info', 'warning'] as $type) {
        if (isset($_SESSION[$type])) {
            $msg = $_SESSION[$type];
            unset($_SESSION[$type]);
            return ['type' => $type, 'message' => $msg];
        }
    }
    return null;
}

/**
 * Zielpfad für bereits eingeloggte Benutzer bestimmen.
 */
function meridian_logged_in_redirect_path(): string
{
    try {
        $user = \CMS\Auth::instance()->getCurrentUser();
        $role = strtolower((string)($user->role ?? $user['role'] ?? ''));

        if ($role === 'admin') {
            return '/admin';
        }
    } catch (\Throwable $e) {
    }

    return '/member';
}

/**
 * Account-Zielpfad für Header-/Navigation-Links bestimmen.
 */
function meridian_account_path(): string
{
    try {
        $user = \CMS\Auth::instance()->getCurrentUser();
        $role = strtolower((string)($user->role ?? $user['role'] ?? ''));

        if ($role === 'admin') {
            return '/admin';
        }
    } catch (\Throwable $e) {
    }

    return '/member/profile';
}


/**
 * ThemeCustomizer-Wert sicher auslesen
 */
function meridian_setting(string $section, string $key, mixed $default = null): mixed
{
    try {
        return \CMS\Services\ThemeCustomizer::instance()->get($section, $key, $default);
    } catch (\Throwable $e) {
        return $default;
    }
}

function meridian_build_base_post_content_expression(string $alias): string
{
    return "(CHAR_LENGTH(TRIM(COALESCE({$alias}.content, ''))) > 0"
        . " OR CHAR_LENGTH(TRIM(COALESCE({$alias}.excerpt, ''))) > 0"
        . " OR CHAR_LENGTH(TRIM(COALESCE({$alias}.title, ''))) > 0)";
}

function meridian_build_localized_post_content_expression(string $alias, string $locale): string
{
    return "(CHAR_LENGTH(TRIM(COALESCE({$alias}.content_{$locale}, ''))) > 0"
        . " OR CHAR_LENGTH(TRIM(COALESCE({$alias}.excerpt_{$locale}, ''))) > 0"
        . " OR CHAR_LENGTH(TRIM(COALESCE({$alias}.title_{$locale}, ''))) > 0)";
}

function meridian_build_legacy_english_only_post_expression(string $alias): string
{
    $englishContent = meridian_build_localized_post_content_expression($alias, 'en');

    return "(CHAR_LENGTH(TRIM(COALESCE({$alias}.slug_en, ''))) > 0 AND NOT {$englishContent})";
}

function meridian_post_locale_availability_expression(string $alias = 'p', ?string $locale = null): string
{
    try {
        $localization = \CMS\Services\ContentLocalizationService::getInstance();
        $resolvedLocale = $localization->normalizeLocale($locale ?? meridian_current_request_locale());

        // Core ab 3.5.0: indizierte Spalten has_locale_* (mit gleichwertigem Fallback).
        if (method_exists($localization, 'buildPostAvailabilitySql')) {
            return $localization->buildPostAvailabilitySql($alias, $resolvedLocale);
        }

        $baseContent = meridian_build_base_post_content_expression($alias);
        $englishLegacyOnly = meridian_build_legacy_english_only_post_expression($alias);

        if ($resolvedLocale === '' || $resolvedLocale === 'de') {
            return "{$baseContent} AND NOT {$englishLegacyOnly}";
        }

        if (!in_array($resolvedLocale, $localization->getContentLocales(), true)) {
            return '1=1';
        }

        $localizedContent = meridian_build_localized_post_content_expression($alias, $resolvedLocale);

        if ($resolvedLocale === 'en') {
            return "({$localizedContent} OR {$englishLegacyOnly})";
        }

        return $localizedContent;
    } catch (\Throwable $e) {
        return '1=1';
    }
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function meridian_build_category_tree_rows(array $rows): array
{
    $byId = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $row['id'] = $id;
        $row['parent_id'] = (int) ($row['parent_id'] ?? 0);
        $row['post_count_direct'] = (int) ($row['post_count_direct'] ?? $row['post_count'] ?? 0);
        $byId[$id] = $row;
    }

    $byParent = [];
    foreach ($byId as $id => $row) {
        $parentId = (int) ($row['parent_id'] ?? 0);
        if ($parentId > 0 && !isset($byId[$parentId])) {
            $parentId = 0;
        }

        $byParent[$parentId][] = $id;
    }

    $flat = [];
    $walker = function (int $parentId, int $depth) use (&$walker, &$flat, $byParent, $byId): int {
        $branchTotal = 0;

        foreach ($byParent[$parentId] ?? [] as $categoryId) {
            if (!isset($byId[$categoryId])) {
                continue;
            }

            $row = $byId[$categoryId];
            $row['depth'] = $depth;
            $row['name_plain'] = (string) ($row['name'] ?? '');
            $row['name'] = str_repeat('— ', $depth) . (string) ($row['name'] ?? '');
            $index = count($flat);
            $flat[] = $row;
            $childrenTotal = $walker($categoryId, $depth + 1);
            $row['post_count'] = (int) ($row['post_count_direct'] ?? 0) + $childrenTotal;
            $flat[$index] = $row;
            $branchTotal += (int) $row['post_count'];
        }

        return $branchTotal;
    };

    $walker(0, 0);

    return $flat;
}

/**
 * Kategorie-Leiste: Holt Kategorien aus der DB
 *
 * @param int $limit 0 = alle
 */
function meridian_get_categories(int $limit = 0): array
{
    // Header, Kategorie-Leiste, Sidebar und Footer fragen die Liste mehrfach pro Seite ab:
    // je Request (und Sprachfilter) nur einmal berechnen.
    static $cache = [];

    try {
        $localeAvailability = meridian_post_locale_availability_expression('p');
        $cacheKey = md5($localeAvailability);
        if (!array_key_exists($cacheKey, $cache)) {
            $cache[$cacheKey] = meridian_load_categories($localeAvailability);
        }

        $cats = $cache[$cacheKey];

        return $limit > 0 ? array_slice($cats, 0, $limit) : $cats;
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Lädt die Kategorien mit veröffentlichten Beiträgen (ohne Limit) für meridian_get_categories().
 */
function meridian_load_categories(string $localeAvailability): array
{
    try {
        $db = \CMS\Database::instance();
        $prefix = $db->getPrefix();
        $categories = $db->get_results(
            "SELECT id, name, slug, parent_id, sort_order
             FROM {$prefix}post_categories
             ORDER BY sort_order ASC, name ASC"
        ) ?: [];

        if ($categories === []) {
            return [];
        }

        $rows = array_map(static fn(object $category): array => (array) $category, $categories);
        $postIdsByCategory = [];
        foreach ($rows as $row) {
            $categoryId = (int) ($row['id'] ?? 0);
            if ($categoryId > 0) {
                $postIdsByCategory[$categoryId] = [];
            }
        }

        $primaryRows = $db->get_results(
            "SELECT p.id AS post_id, p.category_id AS category_id
             FROM {$prefix}posts p
             WHERE " . cms_post_publication_where('p') . "
               AND {$localeAvailability}
               AND p.category_id IS NOT NULL
               AND p.category_id > 0"
        ) ?: [];

        foreach ($primaryRows as $row) {
            $categoryId = (int) ($row->category_id ?? 0);
            $postId = (int) ($row->post_id ?? 0);
            if ($categoryId > 0 && $postId > 0) {
                $postIdsByCategory[$categoryId][$postId] = true;
            }
        }

        $relationRows = $db->get_results(
            "SELECT p.id AS post_id, pcr.category_id AS category_id
             FROM {$prefix}post_category_rel pcr
             INNER JOIN {$prefix}posts p ON p.id = pcr.post_id
             WHERE " . cms_post_publication_where('p') . "
               AND {$localeAvailability}"
        ) ?: [];

        foreach ($relationRows as $row) {
            $categoryId = (int) ($row->category_id ?? 0);
            $postId = (int) ($row->post_id ?? 0);
            if ($categoryId > 0 && $postId > 0) {
                $postIdsByCategory[$categoryId][$postId] = true;
            }
        }

        foreach ($rows as &$row) {
            $categoryId = (int) ($row['id'] ?? 0);
            $row['post_count_direct'] = count($postIdsByCategory[$categoryId] ?? []);
        }
        unset($row);

        $cats = array_values(array_filter(
            meridian_build_category_tree_rows($rows),
            static fn(array $category): bool => trim((string) ($category['slug'] ?? '')) !== ''
                && ((int) ($category['post_count'] ?? 0)) > 0
        ));

        return $cats;
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Tag-Cloud: Holt alle verwendeten Tags aus der DB
 *
 * @param int $limit 0 = alle
 */
function meridian_get_tags(int $limit = 30): array
{
    // Sidebar, Archiv-Templates und Startseite rufen die Tag-Übersicht mehrfach ab: je Request und Sprache einmal laden.
    static $overviewCache = [];

    try {
        $locale = meridian_current_request_locale();
        if (!isset($overviewCache[$locale])) {
            $repository = new \CMS\Routing\ThemeArchiveRepository();
            $overviewCache[$locale] = $repository->getPublishedTagOverview(
                $locale,
                meridian_post_locale_availability_expression('p')
            );
        }
        $items = $overviewCache[$locale];

        if ($limit > 0) {
            $items = array_slice($items, 0, $limit);
        }

        return array_values(array_map(static function (array $item): array {
            return [
                'name' => trim((string) ($item['title'] ?? '')),
                'slug' => trim((string) ($item['slug'] ?? '')),
                'count' => (int) ($item['count'] ?? 0),
            ];
        }, $items));
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Neueste Posts aus der DB
 */
function meridian_get_recent_posts(int $limit = 5, ?int $excludeId = null): array
{
    try {
        $db     = \CMS\Database::instance();
        $prefix = $db->getPrefix();
        $sql    = "SELECT p.id, p.title, p.slug, p.excerpt, p.featured_image, p.published_at, p.created_at, c.name AS category_name
                   FROM {$prefix}posts p
                   LEFT JOIN {$prefix}post_categories c ON c.id = p.category_id
                   WHERE " . (function_exists('cms_post_publication_where') ? cms_post_publication_where('p') : "p.status = 'published'");
        $params = [];
        if ($excludeId !== null) {
            $sql .= ' AND p.id != ?';
            $params[] = $excludeId;
        }
        $sql .= ' ORDER BY p.published_at DESC LIMIT ?';
        $params[] = $limit;
        $rows = $db->get_results($sql, $params);
        return $rows ? array_map(static fn(object $row): array => (array)$row, $rows) : [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Verwandte Posts (gleiche Kategorie)
 */
function meridian_get_related_posts(int $categoryId, int $excludeId, int $limit = 3): array
{
    if ($categoryId <= 0) {
        return meridian_get_recent_posts($limit, $excludeId);
    }
    try {
        $db     = \CMS\Database::instance();
        $prefix = $db->getPrefix();
        $rows   = $db->get_results(
            "SELECT p.id, p.title, p.slug, p.excerpt, p.featured_image, p.published_at, p.created_at, c.name AS category_name
             FROM {$prefix}posts p
             LEFT JOIN {$prefix}post_categories c ON c.id = p.category_id
             WHERE " . (function_exists('cms_post_publication_where') ? cms_post_publication_where('p') : "p.status = 'published'") . " AND p.category_id = ? AND p.id != ?
             ORDER BY p.published_at DESC LIMIT ?",
            [$categoryId, $excludeId, $limit]
        );
        return $rows ? array_map(static fn(object $row): array => (array)$row, $rows) : meridian_get_recent_posts($limit, $excludeId);
    } catch (\Throwable $e) {
        return meridian_get_recent_posts($limit, $excludeId);
    }
}

/**
 * Universelle Funktion zum Abrufen von Beiträgen (für index.php)
 */
function meridian_get_posts(array $args = []): array
{
    $defaults = [
        'limit'   => 5,
        'offset'  => 0,
        'sticky'  => false,
        'exclude' => [], // array of IDs
        'category'=> null,
        'orderby' => 'published_at',
        'order'   => 'DESC'
    ];
    $args = array_merge($defaults, $args);

    try {
        $db     = \CMS\Database::instance();
        $prefix = $db->getPrefix();
    
        $sql = "SELECT p.*, c.name AS category_name, c.slug AS category_slug, u.username AS author_name 
                FROM {$prefix}posts p 
                LEFT JOIN {$prefix}post_categories c ON c.id = p.category_id 
                LEFT JOIN {$prefix}users u ON u.id = p.author_id 
                WHERE " . (function_exists('cms_post_publication_where') ? cms_post_publication_where('p') : "p.status = 'published'");
        
        $params = [];

        // Exclude
        if (!empty($args['exclude'])) {
            $ids = array_map('intval', (array)$args['exclude']);
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sql .= " AND p.id NOT IN ($placeholders)";
                $params = array_merge($params, $ids);
            }
        }

        // Sticky: Wenn is_sticky-Spalte existiert, bevorzuge angeheftete Beiträge
        // (Kernschema hat keine is_sticky-Spalte; Prüfung einmal je Request statt fehlschlagender Abfrage.)
        static $hasStickyColumn = null;
        if ($args['sticky'] === true) {
            try {
                $hasStickyColumn ??= $db->columnExists($prefix . 'posts', 'is_sticky');
            } catch (\Throwable $e) {
                $hasStickyColumn = false;
            }
            if ($hasStickyColumn) {
                $sql .= " AND p.is_sticky = 1";
            }
        }

        // Order
        $validOrders = ['published_at', 'created_at', 'title', 'views'];
        $orderby = in_array($args['orderby'], $validOrders) ? $args['orderby'] : 'published_at';
        $order   = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $sql .= " ORDER BY p.{$orderby} {$order}";

        // Limit / Offset
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = (int)$args['limit'];
        $params[] = (int)$args['offset'];

        $rows = $db->get_results($sql, $params);
        return $rows ? array_map(static fn(object $row): array => (array)$row, $rows) : [];

    } catch (\Throwable $e) {
        // Fallback or log error
        return [];
    }
}

// ─── Template-Alias-Funktionen ───────────────────────────────────────────────
// Werden in header.php, login.php, register.php etc. genutzt.

/**
 * Alias: Eingeloggter Benutzer?
 */
function theme_is_logged_in(): bool
{
    return meridian_is_logged_in();
}

/**
 * Alias: Flash-Message aus der Session
 */
function theme_get_flash(): ?array
{
    return meridian_get_flash();
}

/**
 * Alias: Zielpfad für eingeloggte Benutzer.
 */
function theme_logged_in_redirect_path(): string
{
    return meridian_logged_in_redirect_path();
}

/**
 * Alias: Konto-/Bereichs-Link für eingeloggte Benutzer.
 */
function theme_account_path(): string
{
    return meridian_account_path();
}

/**
 * Alias: Aktives Menü für eine Position
 */
function theme_get_menu(string $location): array
{
    try {
        return \CMS\ThemeManager::instance()->getMenu($location) ?? [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Benutzer einloggen (delegiert an Auth-Service)
 * Gibt true zurück oder eine Fehlermeldung als String.
 */
function theme_login_user(string $email, string $password): bool|string
{
    try {
        $auth   = \CMS\Auth::instance();
        $result = $auth->login($email, $password);
        if ($result === true) {
            return true;
        }
        return is_string($result) ? $result : 'Ungültige Zugangsdaten.';
    } catch (\Throwable $e) {
        return 'Anmeldung fehlgeschlagen: ' . $e->getMessage();
    }
}

/**
 * Neues Konto registrieren (delegiert an Auth/User-Service)
 * Gibt true zurück oder eine Fehlermeldung als String.
 */
function theme_register_user(string $email, string $username, string $password): bool|string
{
    try {
        // Prüfen ob E-Mail oder Benutzername verfügbar
        $db     = \CMS\Database::instance();
        $prefix = $db->getPrefix();
        $exists = $db->execute(
            "SELECT id FROM {$prefix}users WHERE email = ? OR username = ? LIMIT 1",
            [$email, $username]
        )->fetch();
        if ($exists) {
            return 'E-Mail-Adresse oder Benutzername bereits vergeben.';
        }

        // Auth-Service verwenden wenn vorhanden, sonst direkt einfügen
        if (method_exists(\CMS\Auth::instance(), 'register')) {
            $result = \CMS\Auth::instance()->register([
                'email' => $email,
                'username' => $username,
                'password' => $password,
                'display_name' => $username,
            ]);
            return ($result === true) ? true : (is_string($result) ? $result : 'Registrierung fehlgeschlagen.');
        }

        // Fallback: Direkt in DB schreiben
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->execute(
            "INSERT INTO {$prefix}users (email, username, password, role, created_at) VALUES (?, ?, ?, 'member', NOW())",
            [$email, $username, $hash]
        );
        return true;
    } catch (\Throwable $e) {
        return 'Registrierung fehlgeschlagen: ' . $e->getMessage();
    }
}

/**
 * Gibt den Beitragszähler für eine gegebene Kategorie zurück
 */
function meridian_get_category_post_count(int $categoryId): int
{
    if ($categoryId <= 0) {
        return 0;
    }

    try {
        $db = \CMS\Database::instance();
        $prefix = $db->getPrefix();
        $categories = $db->get_results(
            "SELECT id, parent_id FROM {$prefix}post_categories",
            []
        ) ?: [];

        $byParent = [];
        foreach ($categories as $category) {
            $byParent[(int) ($category->parent_id ?? 0)][] = (int) ($category->id ?? 0);
        }

        $categoryIds = [];
        $walker = function (int $currentId) use (&$walker, &$categoryIds, $byParent): void {
            if ($currentId <= 0 || isset($categoryIds[$currentId])) {
                return;
            }

            $categoryIds[$currentId] = true;
            foreach ($byParent[$currentId] ?? [] as $childId) {
                $walker((int) $childId);
            }
        };

        $walker($categoryId);
        if ($categoryIds === []) {
            return 0;
        }

        $ids = array_map('intval', array_keys($categoryIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $localeAvailability = meridian_post_locale_availability_expression('p');
        $row = $db->execute(
            "SELECT COUNT(DISTINCT category_posts.post_id) AS cnt
             FROM (
                 SELECT p.id AS post_id
                 FROM {$prefix}posts p
                 WHERE " . cms_post_publication_where('p') . "
                   AND {$localeAvailability}
                   AND p.category_id IN ({$placeholders})

                 UNION

                 SELECT p.id AS post_id
                 FROM {$prefix}post_category_rel pcr
                 INNER JOIN {$prefix}posts p ON p.id = pcr.post_id
                 WHERE " . cms_post_publication_where('p') . "
                   AND {$localeAvailability}
                   AND pcr.category_id IN ({$placeholders})
             ) category_posts",
            array_merge($ids, $ids)
        )->fetch();

        return $row ? (int) ($row->cnt ?? 0) : 0;
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * Copyright-Text mit Platzhaltern auflösen
 * Unterstützte Platzhalter: {year}, {site_title}
 */
if (!function_exists('meridian_copyright')) {
    function meridian_copyright(string $template = ''): string
    {
        if ($template === '') {
            $template = meridian_setting('footer', 'copyright_text', '© {year} {site_title}. Alle Rechte vorbehalten.');
        }
        $siteName = defined('SITE_NAME') ? SITE_NAME : '365CMS';
        $text = str_replace(['{year}', '{site_title}'], [date('Y'), $siteName], $template);
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
}
