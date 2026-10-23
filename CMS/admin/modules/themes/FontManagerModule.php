<?php
declare(strict_types=1);

/**
 * Font Manager Module – Schriftarten-Verwaltung
 *
 * @package CMSv2\Admin\Modules
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Database;
use CMS\ThemeManager;
use CMS\AuditLogger;
use CMS\Http\Client as HttpClient;
use CMS\Logger;
use CMS\Services\ErrorReportService;

class FontManagerModule
{
    private Database $db;
    private string $prefix;
    private Logger $logger;
    private const ALLOWED_REMOTE_FONT_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com'];
    private const SCAN_CACHE_TTL = 900;
    private const SCAN_CACHE_OPTION_PREFIX = 'font_scan_cache_';
    private const MAX_REMOTE_FONT_FILES = 20;
    private const MAX_REMOTE_FONT_TOTAL_BYTES = 15728640;
    private const MAX_REMOTE_FONT_FILE_BYTES = 5242880;
    private const MAX_FONT_FILENAME_LENGTH = 180;
    private const MAX_ERROR_CONTEXT_LENGTH = 180;
    private const MAX_SCANNED_THEME_FILES = 300;
    private const MAX_SCANNED_THEME_FILE_BYTES = 262144;
    private const MAX_SCANNED_TOTAL_BYTES = 10485760;
    private const SCAN_ALLOWED_EXTENSIONS = ['css', 'php', 'js', 'json', 'txt', 'md'];
    private const SCAN_SKIPPED_SEGMENTS = ['vendor', 'node_modules', 'cache', '.git'];
    private const MANAGED_FONT_FILE_EXTENSIONS = ['woff', 'woff2', 'ttf', 'otf'];
    private const TEMP_FONT_FILE_PREFIX = 'cmsfont_';
    private const LOCAL_FONT_ALLOWED_SUBSETS = ['latin', 'latin-ext'];
    private const GOOGLE_FONTS_WOFF2_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
    private const LOCAL_FONT_CSS_PROPERTIES = ['font-family', 'font-style', 'font-weight', 'font-stretch', 'font-display', 'src', 'unicode-range'];
    private const LEGACY_FONT_FORMATS = ['ttf', 'otf'];
    private const FONT_SLUG_ALIASES = [
        'exo 2' => 'exo2',
        'source code pro' => 'source-code',
        'source sans 3' => 'source-sans',
    ];

    private const SYSTEM_FONTS = [
        'system-ui'       => 'System UI (Standard)',
        'inter'           => 'Inter',
        'space-grotesk'   => 'Space Grotesk',
        'sora'            => 'Sora',
        'barlow'          => 'Barlow',
        'barlow-condensed'=> 'Barlow Condensed',
        'roboto'          => 'Roboto',
        'roboto-condensed'=> 'Roboto Condensed',
        'open-sans'       => 'Open Sans',
        'lato'            => 'Lato',
        'montserrat'      => 'Montserrat',
        'poppins'         => 'Poppins',
        'source-sans'     => 'Source Sans 3',
        'nunito'          => 'Nunito',
        'oswald'          => 'Oswald',
        'rajdhani'        => 'Rajdhani',
        'exo2'            => 'Exo 2',
        'dm-sans'         => 'DM Sans',
        'libre-baskerville' => 'Libre Baskerville',
        'playfair-display' => 'Playfair Display',
        'merriweather'    => 'Merriweather',
        'jetbrains-mono'  => 'JetBrains Mono',
        'fira-code'       => 'Fira Code',
        'source-code'     => 'Source Code Pro',
        'cascadia'        => 'Cascadia Code',
        'system-mono'     => 'System Mono',
        'arial'           => 'Arial',
        'georgia'         => 'Georgia',
        'times-new-roman' => 'Times New Roman',
        'courier-new'     => 'Courier New',
        'verdana'         => 'Verdana',
        'trebuchet-ms'    => 'Trebuchet MS',
    ];

    private const FONT_STACKS = [
        'system-ui'       => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
        'inter'           => "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
        'space-grotesk'   => "'Space Grotesk', 'Sora', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'sora'            => "'Sora', 'Space Grotesk', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'barlow'          => "'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'barlow-condensed'=> "'Barlow Condensed', 'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'roboto'          => "'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'roboto-condensed'=> "'Roboto Condensed', 'Roboto', Arial, sans-serif",
        'open-sans'       => "'Open Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'lato'            => "'Lato', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'montserrat'      => "'Montserrat', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'poppins'         => "'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'source-sans'     => "'Source Sans 3', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'nunito'          => "'Nunito', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'oswald'          => "'Oswald', 'Roboto Condensed', Arial, sans-serif",
        'rajdhani'        => "'Rajdhani', 'Barlow Condensed', 'Inter', sans-serif",
        'exo2'            => "'Exo 2', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'dm-sans'         => "'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        'libre-baskerville' => "'Libre Baskerville', Georgia, serif",
        'playfair-display'=> "'Playfair Display', Georgia, serif",
        'merriweather'    => "'Merriweather', Georgia, serif",
        'jetbrains-mono'  => "'JetBrains Mono', 'Fira Code', 'Cascadia Code', monospace",
        'fira-code'       => "'Fira Code', 'JetBrains Mono', 'Cascadia Code', monospace",
        'source-code'     => "'Source Code Pro', 'Fira Code', 'JetBrains Mono', monospace",
        'cascadia'        => "'Cascadia Code', 'JetBrains Mono', 'Fira Code', monospace",
        'system-mono'     => "'Cascadia Code', 'Consolas', 'Courier New', monospace",
        'arial'           => "Arial, 'Helvetica Neue', Helvetica, sans-serif",
        'georgia'         => "Georgia, 'Times New Roman', Times, serif",
        'times-new-roman' => "'Times New Roman', Times, serif",
        'courier-new'     => "'Courier New', Courier, monospace",
        'verdana'         => "Verdana, Geneva, sans-serif",
        'trebuchet-ms'    => "'Trebuchet MS', 'Lucida Grande', sans-serif",
    ];

    private const CURATED_FONT_LIBRARY = [
        'CMS Phinit / Editorial Tech' => [
            ['name' => 'Inter', 'style' => 'Sans-Serif', 'reason' => 'Empfohlene Body- und UI-Schrift für cms-phinit'],
            ['name' => 'Space Grotesk', 'style' => 'Sans-Serif', 'reason' => 'Empfohlene Display- und Heading-Schrift für cms-phinit'],
            ['name' => 'Sora', 'style' => 'Sans-Serif', 'reason' => 'Weichere Alternative für Headings im gleichen Tech-Editorial-Stil'],
            ['name' => 'Barlow', 'style' => 'Sans-Serif', 'reason' => 'Sachliche Alternative für UI, Meta und Navigation'],
            ['name' => 'Barlow Condensed', 'style' => 'Condensed', 'reason' => 'Kompakte Alternative für Header, Labels und enge Menüs'],
            ['name' => 'Rajdhani', 'style' => 'Sans-Serif', 'reason' => 'Technischer, leicht futuristischer Heading-Akzent'],
            ['name' => 'Exo 2', 'style' => 'Sans-Serif', 'reason' => 'Tech-orientierte Display-Alternative mit ruhiger Lesbarkeit'],
            ['name' => 'JetBrains Mono', 'style' => 'Monospace', 'reason' => 'Primäre Code-Schrift für Snippets, Terminal- und PowerShell-Inhalte'],
            ['name' => 'Fira Code', 'style' => 'Monospace', 'reason' => 'Code-Alternative mit Ligaturen für Entwickler-Demos'],
        ],
        'IT / Tech / SaaS / Dashboards' => [
            ['name' => 'Inter', 'style' => 'Sans-Serif', 'reason' => 'Standard für UI/Dashboards, sehr lesbar'],
            ['name' => 'Roboto', 'style' => 'Sans-Serif', 'reason' => 'Meistgenutzte Google Font weltweit'],
            ['name' => 'JetBrains Mono', 'style' => 'Monospace', 'reason' => 'Code-Blöcke, Terminal-Look'],
            ['name' => 'Fira Code', 'style' => 'Monospace', 'reason' => 'Ligatur-Support, ideal für Code'],
            ['name' => 'Source Code Pro', 'style' => 'Monospace', 'reason' => 'Adobe Open Source, klassisch'],
            ['name' => 'IBM Plex Mono', 'style' => 'Monospace', 'reason' => 'IBM-Design, tech & clean'],
            ['name' => 'Space Grotesk', 'style' => 'Sans-Serif', 'reason' => 'Modern, Tech-Startups'],
            ['name' => 'DM Sans', 'style' => 'Sans-Serif', 'reason' => 'SaaS, klare Geometrie'],
            ['name' => 'Manrope', 'style' => 'Sans-Serif', 'reason' => 'Modern, gut für UI'],
            ['name' => 'IBM Plex Sans', 'style' => 'Sans-Serif', 'reason' => 'Enterprise, professionell'],
        ],
        'Business / Corporate / Firmen' => [
            ['name' => 'Open Sans', 'style' => 'Sans-Serif', 'reason' => 'Universell, seriös, sehr beliebt'],
            ['name' => 'Lato', 'style' => 'Sans-Serif', 'reason' => 'Warm & professionell'],
            ['name' => 'Montserrat', 'style' => 'Sans-Serif', 'reason' => 'Modern, Headings, Logos'],
            ['name' => 'Source Sans 3', 'style' => 'Sans-Serif', 'reason' => 'Adobe Open Source, corporate'],
            ['name' => 'Raleway', 'style' => 'Sans-Serif', 'reason' => 'Elegant, gehobenes Business'],
            ['name' => 'Work Sans', 'style' => 'Sans-Serif', 'reason' => 'Büro-Feel, sehr lesbar'],
            ['name' => 'Nunito', 'style' => 'Sans-Serif', 'reason' => 'Freundlich & seriös'],
            ['name' => 'Poppins', 'style' => 'Sans-Serif', 'reason' => 'Runde Geometrie, modern'],
            ['name' => 'Barlow', 'style' => 'Sans-Serif', 'reason' => 'Sauber, vielseitig'],
            ['name' => 'Figtree', 'style' => 'Sans-Serif', 'reason' => 'Frisch, modern, 2023+ Trend'],
        ],
        'Blogs / Editorial / Longread' => [
            ['name' => 'Merriweather', 'style' => 'Serif', 'reason' => 'Blogklassiker, sehr lesbar'],
            ['name' => 'Playfair Display', 'style' => 'Serif', 'reason' => 'Editorial, Magazin-Look'],
            ['name' => 'Lora', 'style' => 'Serif', 'reason' => 'Warm, perfekt für Fließtext'],
            ['name' => 'Source Serif 4', 'style' => 'Serif', 'reason' => 'Lesbarkeit, langer Text'],
            ['name' => 'Crimson Pro', 'style' => 'Serif', 'reason' => 'Zeitungsanmutung'],
            ['name' => 'Libre Baskerville', 'style' => 'Serif', 'reason' => 'Klassisch, traditionell'],
            ['name' => 'EB Garamond', 'style' => 'Serif', 'reason' => 'Literarisch, elegant'],
            ['name' => 'Fraunces', 'style' => 'Serif', 'reason' => 'Ausdrucksstark, modern'],
            ['name' => 'Alegreya', 'style' => 'Serif', 'reason' => 'Buchcharakter, elegant'],
            ['name' => 'Noto Serif', 'style' => 'Serif', 'reason' => 'Viele Sprachen, neutral'],
        ],
        'Kreativ / Portfolio / Design' => [
            ['name' => 'Urbanist', 'style' => 'Sans-Serif', 'reason' => 'Minimalistisch, trendy'],
            ['name' => 'Outfit', 'style' => 'Sans-Serif', 'reason' => 'Clean, modern, 2022+'],
            ['name' => 'Plus Jakarta Sans', 'style' => 'Sans-Serif', 'reason' => 'Ausgewogen, vielseitig'],
            ['name' => 'Syne', 'style' => 'Sans-Serif', 'reason' => 'Design-Agenturen'],
            ['name' => 'Abril Fatface', 'style' => 'Display', 'reason' => 'Starkes Headline-Statement'],
            ['name' => 'Oswald', 'style' => 'Condensed', 'reason' => 'Kompakt, Header'],
            ['name' => 'Bebas Neue', 'style' => 'Display', 'reason' => 'Fett, Impact, Headlines'],
            ['name' => 'Righteous', 'style' => 'Display', 'reason' => 'Retro-modern'],
            ['name' => 'Exo 2', 'style' => 'Sans-Serif', 'reason' => 'Sci-Fi, Tech-Design'],
            ['name' => 'Orbitron', 'style' => 'Display', 'reason' => 'Futuristisch'],
        ],
        'Handschrift / Dekorativ / Akzente' => [
            ['name' => 'Dancing Script', 'style' => 'Script', 'reason' => 'Elegant, beliebt'],
            ['name' => 'Pacifico', 'style' => 'Script', 'reason' => 'Freundlich, casual'],
            ['name' => 'Caveat', 'style' => 'Handwriting', 'reason' => 'Handgeschrieben, locker'],
            ['name' => 'Satisfy', 'style' => 'Script', 'reason' => 'Elegant, akzentstark'],
            ['name' => 'Patrick Hand', 'style' => 'Handwriting', 'reason' => 'Natürlich, Blog-Akzent'],
        ],
        'Multilingual / Sonderzeichen' => [
            ['name' => 'Noto Sans', 'style' => 'Sans-Serif', 'reason' => '100+ Sprachen, Google Standard'],
            ['name' => 'Rubik', 'style' => 'Sans-Serif', 'reason' => 'Hebräisch + Latein'],
            ['name' => 'Cairo', 'style' => 'Sans-Serif', 'reason' => 'Arabisch + Latein'],
            ['name' => 'Karla', 'style' => 'Sans-Serif', 'reason' => 'Breite Sprachunterstützung'],
            ['name' => 'Ubuntu', 'style' => 'Sans-Serif', 'reason' => 'Linux-Community, breit'],
        ],
    ];

    public function __construct()
    {
        $this->db     = Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->logger = Logger::instance()->withChannel('admin.font-manager');
    }

    /**
     * Font-Daten laden
     */
    public function getData(): array
    {
        $settings = $this->getSettings();
        $customFonts = $this->getCustomFonts();
        $scanResults = $this->getThemeScanResults($customFonts);
        $useLocalFonts = $this->isLocalFontsEnabled();
        $customFontRows = $this->buildCustomFontRows($customFonts);

        // Custom Fonts in Auswahl-Optionen und Font-Stacks integrieren
        $allFonts  = self::SYSTEM_FONTS;
        $allStacks = self::FONT_STACKS;
        foreach ($customFonts as $font) {
            $key = $font->slug ?? '';
            if ($key !== '') {
                $allFonts[$key]  = ($font->name ?? $key) . ' (Lokal)';
                $allStacks[$key] = "'{$font->name}', sans-serif";
            }
        }

        $fontUsageAnalysis = $this->buildFontUsageAnalysis(
            $allFonts,
            $customFonts,
            $customFontRows,
            $scanResults,
            (string) ($settings['font_heading'] ?? 'system-ui'),
            (string) ($settings['font_body'] ?? 'system-ui'),
            $useLocalFonts
        );

        return [
            'systemFonts'   => $allFonts,
            'fontStacks'    => $allStacks,
            'customFonts'   => $customFonts,
            'customFontRows' => $customFontRows,
            'headingFont'   => $settings['font_heading'] ?? 'system-ui',
            'bodyFont'      => $settings['font_body'] ?? 'system-ui',
            'useLocalFonts' => $useLocalFonts,
            'fontSize'      => $settings['font_size_base'] ?? '16',
            'lineHeight'    => $settings['font_line_height'] ?? '1.6',
            'scanResults'   => $scanResults,
            'fontUsageAnalysis' => $fontUsageAnalysis,
            'fontCatalog'   => $this->getCuratedFontCatalog($customFonts),
            'activeThemeSlug' => ThemeManager::instance()->getActiveThemeSlug(),
            'scanSummary' => [
                'scannedFiles' => (int) ($scanResults['scannedFiles'] ?? 0),
                'skippedFiles' => (int) ($scanResults['skippedFiles'] ?? 0),
                'warnings' => is_array($scanResults['warnings'] ?? null) ? $scanResults['warnings'] : [],
                'source' => (string) ($scanResults['source'] ?? 'live'),
                'generatedAt' => (string) ($scanResults['generatedAt'] ?? ''),
            ],
            'constraints' => [
                'google_font_family_max_length' => 120,
                'font_size_min' => 12,
                'font_size_max' => 24,
                'line_height_min' => '1.0',
                'line_height_max' => '2.5',
                'scan_file_limit' => self::MAX_SCANNED_THEME_FILES,
                'scan_file_size_limit' => self::MAX_SCANNED_THEME_FILE_BYTES,
                'scan_file_size_limit_label' => $this->formatBytesLabel(self::MAX_SCANNED_THEME_FILE_BYTES),
                'scan_total_byte_limit' => self::MAX_SCANNED_TOTAL_BYTES,
                'scan_total_byte_limit_label' => $this->formatBytesLabel(self::MAX_SCANNED_TOTAL_BYTES),
                'scan_cache_ttl' => self::SCAN_CACHE_TTL,
                'scan_allowed_extensions' => self::SCAN_ALLOWED_EXTENSIONS,
                'scan_allowed_extensions_label' => implode(', ', self::SCAN_ALLOWED_EXTENSIONS),
                'scan_skipped_segments' => self::SCAN_SKIPPED_SEGMENTS,
                'scan_skipped_segments_label' => implode(', ', self::SCAN_SKIPPED_SEGMENTS),
                'download_remote_file_limit' => self::MAX_REMOTE_FONT_FILES,
                'download_remote_file_byte_limit' => self::MAX_REMOTE_FONT_FILE_BYTES,
                'download_remote_file_byte_limit_label' => $this->formatBytesLabel(self::MAX_REMOTE_FONT_FILE_BYTES),
                'download_total_byte_limit' => self::MAX_REMOTE_FONT_TOTAL_BYTES,
                'download_total_byte_limit_label' => $this->formatBytesLabel(self::MAX_REMOTE_FONT_TOTAL_BYTES),
                'allowed_remote_hosts' => self::ALLOWED_REMOTE_FONT_HOSTS,
                'allowed_remote_hosts_label' => implode(', ', self::ALLOWED_REMOTE_FONT_HOSTS),
            ],
        ];
    }

    public function scanThemeFonts(): array
    {
        $results = $this->getThemeScanResults($this->getCustomFonts(), true);
        $count   = count($results['detectedFonts']);

        // ADDED: Theme-Scans für spätere Diagnose im Audit-Log festhalten.
        AuditLogger::instance()->log(
            AuditLogger::CAT_THEME,
            'font.scan',
            'Theme-Schriften gescannt',
            'theme',
            null,
            ['theme' => (string)($results['theme'] ?? ''), 'detected_fonts' => $count, 'scanned_files' => (int)($results['scannedFiles'] ?? 0)],
            'info'
        );

        return [
            'success' => true,
            'message' => sprintf('Theme-Scan abgeschlossen: %d erkannte Schrift%s in %d Dateien geprüft.', $count, $count === 1 ? '' : 'en', (int)($results['scannedFiles'] ?? 0)),
            'details' => array_values(array_filter([
                'Geprüfte Dateien: ' . (int) ($results['scannedFiles'] ?? 0),
                !empty($results['skippedFiles']) ? 'Übersprungen: ' . (int) ($results['skippedFiles'] ?? 0) : '',
                !empty($results['source']) ? 'Quelle: ' . (string) $results['source'] : '',
                !empty($results['generatedAt']) ? 'Stand: ' . (string) $results['generatedAt'] : '',
                !empty($results['warnings']) && is_array($results['warnings']) ? 'Hinweise: ' . implode(' | ', array_slice(array_map(static fn (mixed $warning): string => (string) $warning, $results['warnings']), 0, 2)) : '',
            ])),
        ];
    }

    public function downloadDetectedFonts(): array
    {
        $customFonts = $this->getCustomFonts();
        $scanResults = $this->getThemeScanResults($customFonts);
        $detectedFonts = (array)($scanResults['detectedFonts'] ?? []);

        if ($detectedFonts === []) {
            return $this->buildFontFailureResult(
                'Es wurden keine externen Theme-Schriften erkannt.',
                'font_manager_no_detected_fonts',
                [
                    'Theme: ' . (string) ($scanResults['theme'] ?? ''),
                    'Scan-Quelle: ' . (string) ($scanResults['source'] ?? 'live'),
                ],
                ['theme' => (string) ($scanResults['theme'] ?? ''), 'source' => (string) ($scanResults['source'] ?? 'live')]
            );
        }

        $installed = $this->getInstalledFontNames($customFonts);
        $downloaded = 0;
        $skipped = 0;
        $errors = [];
        $reportPayload = [];

        foreach ($detectedFonts as $font) {
            $fontName = trim((string)($font['name'] ?? ''));
            if ($fontName === '') {
                continue;
            }

            $fontLookupKey = $this->normalizeFontLookupKey($fontName);

            if (in_array($fontLookupKey, $installed, true)) {
                $skipped++;
                continue;
            }

            $result = $this->downloadGoogleFont($fontName);
            if (!empty($result['success'])) {
                $downloaded++;
                $installed[] = $fontLookupKey;
                continue;
            }

            $message = (string)($result['error'] ?? 'Unbekannter Fehler');
            if (str_contains($message, 'bereits vorhanden')) {
                $skipped++;
                $installed[] = $fontLookupKey;
                continue;
            }

            $errors[] = $fontName . ': ' . $message;
            if ($reportPayload === [] && is_array($result['report_payload'] ?? null)) {
                $reportPayload = $result['report_payload'];
            }
        }

        if ($downloaded > 0) {
            $this->invalidateThemeScanCache();
        }

        if ($downloaded === 0 && $errors !== []) {
            $failure = $this->buildFontFailureResult(
                'Keine Schrift konnte lokal geladen werden.',
                'font_manager_detected_font_download_failed',
                array_merge([
                    'Erkannte Schriften: ' . count($detectedFonts),
                    'Bereits installiert: ' . $skipped,
                    'Scan-Quelle: ' . (string) ($scanResults['source'] ?? 'live'),
                ], array_slice($errors, 0, 3)),
                [
                    'detected_fonts' => count($detectedFonts),
                    'skipped_fonts' => $skipped,
                    'source' => (string) ($scanResults['source'] ?? 'live'),
                ]
            );

            if ($reportPayload !== []) {
                $failure['report_payload'] = $reportPayload;
            }

            return $failure;
        }

        $message = $downloaded . ' Schrift' . ($downloaded === 1 ? '' : 'en') . ' lokal geladen';
        if ($skipped > 0) {
            $message .= ', ' . $skipped . ' bereits vorhanden';
        }
        if ($errors !== []) {
            $message .= '. Hinweise: ' . implode(' | ', array_slice($errors, 0, 3));
        }

        // ADDED: Sammel-Download im Audit-Log dokumentieren.
        AuditLogger::instance()->log(
            AuditLogger::CAT_THEME,
            'font.download.detected',
            'Erkannte Theme-Schriften lokal gespeichert',
            'font',
            null,
            ['downloaded' => $downloaded, 'skipped' => $skipped, 'errors' => $errors],
            $errors === [] ? 'info' : 'warning'
        );

        return [
            'success' => true,
            'message' => $message . '.',
            'details' => array_values(array_filter([
                'Neu geladen: ' . $downloaded,
                $skipped > 0 ? 'Bereits vorhanden: ' . $skipped : '',
                !empty($scanResults['source']) ? 'Scan-Quelle: ' . (string) $scanResults['source'] : '',
                $errors !== [] ? 'Hinweise: ' . implode(' | ', array_slice($errors, 0, 2)) : '',
            ])),
            'report_payload' => $reportPayload,
        ];
    }

    /**
     * Font-Einstellungen speichern
     */
    public function saveSettings(array $post): array
    {
        try {
            $allowedFonts = $this->getSelectableFontKeys();
            $headingFont = $this->normalizeSelectableFontKey((string) ($post['heading_font'] ?? 'system-ui'), $allowedFonts);
            $bodyFont = $this->normalizeSelectableFontKey((string) ($post['body_font'] ?? 'system-ui'), $allowedFonts);

            $settings = [
                'font_heading'     => $headingFont,
                'font_body'        => $bodyFont,
                'font_size_base'   => max(12, min(24, (int)($post['font_size'] ?? 16))),
                'font_line_height' => max(1.0, min(2.5, (float)($post['line_height'] ?? 1.6))),
                'privacy_use_local_fonts' => (($post['use_local_fonts'] ?? '0') === '1') ? '1' : '0',
            ];

            $existingSettings = $this->loadExistingSettings(array_keys($settings));
            $changedKeys = $this->collectChangedSettingKeys($settings, $existingSettings);

            foreach ($settings as $key => $value) {
                $this->persistSetting($key, (string)$value, $existingSettings);
            }

            return [
                'success' => true,
                'message' => (($post['use_local_fonts'] ?? '0') === '1')
                    ? 'Schrifteinstellungen gespeichert. Lokale On-Prem-Fonts sind jetzt im Frontend aktiv.'
                    : 'Schrifteinstellungen gespeichert. Google-Fonts-Fallback bleibt aktiv.',
                'details' => array_values(array_filter([
                    'Heading: ' . $headingFont,
                    'Body: ' . $bodyFont,
                    'Geänderte Settings: ' . ($changedKeys !== [] ? implode(', ', $changedKeys) : 'keine Inhaltsänderung erkannt'),
                ])),
            ];
        } catch (\Throwable $e) {
            $useLocalFonts = (($post['use_local_fonts'] ?? '0') === '1');

            $this->logger->error('Schrifteinstellungen konnten nicht gespeichert werden.', [
                'exception' => $e,
                'heading_font' => (string) ($post['heading_font'] ?? ''),
                'body_font' => (string) ($post['body_font'] ?? ''),
                'use_local_fonts' => $useLocalFonts,
            ]);

            return $this->buildFontFailureResult(
                'Schrifteinstellungen konnten nicht gespeichert werden.',
                'font_manager_save_failed',
                [
                    'Heading: ' . (string) ($post['heading_font'] ?? 'system-ui'),
                    'Body: ' . (string) ($post['body_font'] ?? 'system-ui'),
                    'Lokale Fonts aktiv: ' . ($useLocalFonts ? 'ja' : 'nein'),
                ],
                [
                    'heading_font' => (string) ($post['heading_font'] ?? ''),
                    'body_font' => (string) ($post['body_font'] ?? ''),
                    'use_local_fonts' => $useLocalFonts,
                    'exception' => $this->sanitizeErrorContext($e->getMessage()),
                ]
            );
        }
    }

    private function isLocalFontsEnabled(): bool
    {
        try {
            $value = $this->db->get_var(
                "SELECT option_value FROM {$this->prefix}settings WHERE option_name = 'privacy_use_local_fonts' LIMIT 1"
            );
        } catch (\Throwable $e) {
            return false;
        }

        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Benutzerdefinierte Schriftart löschen
     */
    public function deleteCustomFont(int $fontId): array
    {
        if ($fontId <= 0) {
            return $this->buildFontFailureResult('Ungültige Font-ID.', 'font_manager_invalid_font_id', ['Font-ID: ' . $fontId], ['font_id' => $fontId]);
        }

        $warnings = [];

        try {
            // Font-Datei(en) löschen
            $font = $this->db->get_row(
                "SELECT name, slug, file_path, css_path FROM {$this->prefix}custom_fonts WHERE id = ?",
                [$fontId]
            );

            if (!$font) {
                return $this->buildFontFailureResult(
                    'Die angeforderte Schriftart wurde nicht gefunden.',
                    'font_manager_font_not_found',
                    ['Font-ID: ' . $fontId],
                    ['font_id' => $fontId]
                );
            }

            $linkedFontAssets = [];

            $fontFilePath = $this->resolveManagedFontPath((string) ($font->file_path ?? ''));
            if ($fontFilePath !== null) {
                $linkedFontAssets[$fontFilePath] = $fontFilePath;
            } elseif (!empty($font->file_path)) {
                $warnings[] = 'Dateipfad liegt außerhalb des verwalteten Fonts-Verzeichnisses.';
            }

            $cssFilePath = $this->resolveManagedFontPath((string) ($font->css_path ?? ''));
            if ($cssFilePath !== null) {
                foreach ($this->extractManagedFontAssetPathsFromCss($cssFilePath) as $assetPath) {
                    $linkedFontAssets[$assetPath] = $assetPath;
                }

                $this->deleteFileIfExists($cssFilePath, $warnings);
            } elseif (!empty($font->css_path)) {
                $warnings[] = 'CSS-Pfad liegt außerhalb des verwalteten Fonts-Verzeichnisses.';
            }

            foreach ($linkedFontAssets as $assetPath) {
                $this->deleteFileIfExists($assetPath, $warnings);
            }

            $fontSlug = $this->sanitizeFontKey((string) ($font->slug ?? ''));
            $runtimeCleanupDetails = [];

            $this->db->execute(
                "DELETE FROM {$this->prefix}custom_fonts WHERE id = ?",
                [$fontId]
            );

            if ($fontSlug !== '') {
                try {
                    $runtimeCleanupDetails = $this->cleanupDeletedFontRuntimeState($fontSlug);
                } catch (\Throwable $e) {
                    $warnings[] = 'Runtime-Zuordnungen konnten nicht vollständig bereinigt werden.';
                    $this->logger->warning('Runtime-Zuordnungen für gelöschte Schriftart konnten nicht vollständig bereinigt werden.', [
                        'font_id' => $fontId,
                        'font_slug' => $fontSlug,
                        'exception' => $e,
                    ]);
                }
            }

            // ADDED: Löschaktionen an zentralem Audit-Log spiegeln.
            AuditLogger::instance()->log(
                AuditLogger::CAT_THEME,
                'font.delete',
                'Lokale Schriftart gelöscht',
                'font',
                $fontId,
                [
                    'font_name' => (string) ($font->name ?? ''),
                    'font_slug' => $fontSlug,
                    'file_path' => (string)($font->file_path ?? ''),
                    'runtime_cleanup' => $runtimeCleanupDetails,
                ],
                'warning'
            );

            $message = 'Schriftart gelöscht.';
            if ($runtimeCleanupDetails !== []) {
                $message .= ' Aktive Font-Zuordnungen wurden auf sichere Defaults zurückgesetzt.';
            }
            if (!empty($warnings)) {
                $message .= ' Hinweise: ' . implode(' | ', $warnings);
            }

            $this->invalidateThemeScanCache();

            return [
                'success' => true,
                'message' => $message,
                'details' => array_values(array_filter([
                    'Font-ID: ' . $fontId,
                    !empty($font->slug) ? 'Slug: ' . (string) $font->slug : '',
                    !empty($font->file_path) ? 'Datei: ' . (string) $font->file_path : '',
                    !empty($font->css_path) ? 'CSS: ' . (string) $font->css_path : '',
                    $linkedFontAssets !== [] ? 'Verknüpfte Assets: ' . count($linkedFontAssets) : '',
                    $runtimeCleanupDetails !== [] ? 'Runtime-Bereinigung: ' . implode(', ', $runtimeCleanupDetails) : '',
                    !empty($warnings) ? 'Hinweise: ' . implode(' | ', array_slice($warnings, 0, 2)) : '',
                ])),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Lokale Schriftart konnte nicht gelöscht werden.', [
                'font_id' => $fontId,
                'exception' => $e,
            ]);

            return $this->buildFontFailureResult(
                'Schriftart konnte nicht gelöscht werden.',
                'font_manager_delete_failed',
                ['Font-ID: ' . $fontId],
                ['font_id' => $fontId, 'exception' => $this->sanitizeErrorContext($e->getMessage())]
            );
        }
    }

    /**
     * @return list<string>
     */
    private function cleanupDeletedFontRuntimeState(string $fontSlug): array
    {
        $fontSlug = $this->sanitizeFontKey($fontSlug);
        if ($fontSlug === '') {
            return [];
        }

        $cleanupDetails = [];

        foreach (['font_heading', 'font_body'] as $settingKey) {
            $currentValue = strtolower(trim((string) $this->db->get_var(
                "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1",
                [$settingKey]
            )));

            if ($currentValue !== $fontSlug) {
                continue;
            }

            $this->db->execute(
                "UPDATE {$this->prefix}settings SET option_value = ? WHERE option_name = ?",
                ['system-ui', $settingKey]
            );
            $cleanupDetails[] = $settingKey . ' → system-ui';
        }

        $stackKey = 'font_stack_' . $fontSlug;
        $stackExists = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}settings WHERE option_name = ?",
            [$stackKey]
        );

        if ($stackExists > 0) {
            $this->db->execute(
                "DELETE FROM {$this->prefix}settings WHERE option_name = ?",
                [$stackKey]
            );
            $cleanupDetails[] = $stackKey . ' entfernt';
        }

        return $cleanupDetails;
    }

    /**
     * Google Font DSGVO-konform lokal herunterladen
     */
    public function downloadGoogleFont(string $fontFamily): array
    {
        $result = $this->installGoogleFont(trim($fontFamily), null);
        unset($result['local_paths']);

        return $result;
    }

    /**
     * Lädt eine bereits lokal gespeicherte Google-Schrift erneut (z. B. TTF-Altbestand → WOFF2)
     * und entfernt danach nicht mehr referenzierte Altdateien.
     */
    public function refreshCustomFont(int $fontId): array
    {
        if ($fontId <= 0) {
            return $this->buildFontFailureResult('Ungültige Font-ID.', 'font_manager_invalid_font_id', ['Font-ID: ' . $fontId], ['font_id' => $fontId]);
        }

        $font = $this->db->get_row(
            "SELECT id, name, slug, file_path, css_path, source FROM {$this->prefix}custom_fonts WHERE id = ?",
            [$fontId]
        );

        if (!$font) {
            return $this->buildFontFailureResult('Die angeforderte Schriftart wurde nicht gefunden.', 'font_manager_font_not_found', ['Font-ID: ' . $fontId], ['font_id' => $fontId]);
        }

        if ((string) ($font->source ?? '') !== 'google-fonts-local') {
            return $this->buildFontFailureResult(
                'Nur lokal gespeicherte Google Fonts können neu geladen werden.',
                'font_manager_refresh_unsupported_source',
                ['Font-ID: ' . $fontId, 'Quelle: ' . (string) ($font->source ?? '')],
                ['font_id' => $fontId]
            );
        }

        $previousAssets = [];
        $previousFilePath = $this->resolveManagedFontPath((string) ($font->file_path ?? ''));
        if ($previousFilePath !== null) {
            $previousAssets[$previousFilePath] = $previousFilePath;
        }
        $previousCssPath = $this->resolveManagedFontPath((string) ($font->css_path ?? ''));
        if ($previousCssPath !== null) {
            foreach ($this->extractManagedFontAssetPathsFromCss($previousCssPath) as $assetPath) {
                $previousAssets[$assetPath] = $assetPath;
            }
        }

        $result = $this->installGoogleFont(trim((string) ($font->name ?? '')), $font);
        $newPaths = array_map(
            static fn (string $path): string => (string) (realpath($path) ?: $path),
            (array) ($result['local_paths'] ?? [])
        );
        unset($result['local_paths']);

        if (empty($result['success'])) {
            return $result;
        }

        $warnings = [];
        $removed = 0;
        foreach ($previousAssets as $assetPath) {
            if (in_array((string) (realpath($assetPath) ?: $assetPath), $newPaths, true)) {
                continue;
            }

            if ($this->deleteFileIfExists($assetPath, $warnings)) {
                $removed++;
            }
        }

        $result['details'] = array_values(array_filter(array_merge(
            (array) ($result['details'] ?? []),
            [
                'Entfernte Altdateien: ' . $removed,
                $warnings !== [] ? 'Hinweise: ' . implode(' | ', array_slice($warnings, 0, 2)) : '',
            ]
        )));

        return $result;
    }

    /**
     * @param object|null $existingFont Datensatz aus custom_fonts für Refresh, sonst Neuinstallation
     * @return array<string, mixed>
     */
    private function installGoogleFont(string $fontFamily, ?object $existingFont): array
    {
        if ($fontFamily === '' || !preg_match('/^[a-zA-Z0-9 ]+$/', $fontFamily)) {
            return $this->buildFontFailureResult(
                'Ungültiger Schriftname. Nur Buchstaben, Zahlen und Leerzeichen erlaubt.',
                'font_manager_invalid_font_family',
                ['Google Font: ' . ($fontFamily !== '' ? $fontFamily : '(leer)')],
                ['font_family' => $fontFamily]
            );
        }

        $fontsDir = $this->ensureManagedFontsDirectory();
        if ($fontsDir === null) {
            return $this->buildFontFailureResult('Schriften-Verzeichnis konnte nicht erstellt werden.', 'font_manager_fonts_dir_unavailable', [], ['font_family' => $fontFamily]);
        }

        $isRefresh = $existingFont !== null;
        $slug = $isRefresh
            ? $this->sanitizeFontKey((string) ($existingFont->slug ?? ''))
            : $this->normalizeFontSlug($fontFamily);
        if ($slug === '') {
            return $this->buildFontFailureResult('Für diese Schrift konnte kein gültiger lokaler Dateiname erzeugt werden.', 'font_manager_invalid_font_slug', ['Google Font: ' . $fontFamily], ['font_family' => $fontFamily]);
        }

        if (!$isRefresh) {
            $existing = $this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}custom_fonts WHERE name = ?",
                [$fontFamily]
            );
            if ((int) $existing > 0) {
                return $this->buildFontFailureResult("Schrift \"{$fontFamily}\" ist bereits vorhanden.", 'font_manager_font_exists', ['Google Font: ' . $fontFamily], ['font_family' => $fontFamily, 'slug' => $slug]);
            }
        }

        $familyParam = str_replace('%20', '+', rawurlencode($fontFamily));
        $cssUrl = 'https://fonts.googleapis.com/css2?family=' . $familyParam . ':wght@300;400;500;600;700&display=swap';
        $css = $this->fetchRemoteContent($cssUrl);
        if ($css === false) {
            // css2 antwortet mit 400, wenn ein angefragtes Gewicht fehlt (z. B. Lato ohne 500/600);
            // der Legacy-Endpunkt liefert dann die vorhandenen Gewichte.
            $css = $this->fetchRemoteContent('https://fonts.googleapis.com/css?family=' . $familyParam . ':300,400,500,600,700&display=swap');
        }
        if ($css === false) {
            $css = $this->fetchRemoteContent('https://fonts.googleapis.com/css2?family=' . $familyParam . '&display=swap');
        }
        if ($css === false) {
            return $this->buildFontFailureResult(
                "Google Fonts CSS konnte nicht geladen werden für \"{$fontFamily}\".",
                'font_manager_css_download_failed',
                ['Google Font: ' . $fontFamily, 'Quelle: ' . $cssUrl],
                ['font_family' => $fontFamily, 'css_url' => $cssUrl]
            );
        }

        $css = $this->optimizeGoogleFontCssForLocalDelivery($css);

        $urlMap = [];
        $downloadedPaths = [];
        $downloadWarnings = [];
        $downloadedBytes = 0;

        if (preg_match_all('/url\(([^)]+?\.(woff2?|ttf|otf)[^)]*)\)/i', $css, $matches)) {
            $remoteUrls = array_values(array_unique(array_map(
                static fn (string $url): string => trim($url, "'\" "),
                $matches[1]
            )));

            if (count($remoteUrls) > self::MAX_REMOTE_FONT_FILES) {
                return $this->buildFontFailureResult(
                    'Zu viele Font-Dateien im Remote-CSS erkannt.',
                    'font_manager_remote_file_limit_exceeded',
                    [
                        'Google Font: ' . $fontFamily,
                        'Remote-Dateien: ' . count($remoteUrls),
                        'Limit: ' . self::MAX_REMOTE_FONT_FILES,
                    ],
                    ['font_family' => $fontFamily, 'remote_file_count' => count($remoteUrls), 'limit' => self::MAX_REMOTE_FONT_FILES]
                );
            }

            foreach ($remoteUrls as $remoteUrl) {
                if (!$this->isAllowedFontUrl($remoteUrl)) {
                    $downloadWarnings[] = 'Remote-Datei liegt außerhalb der erlaubten Fonts-Hosts und wurde verworfen.';
                    continue;
                }

                $urlPath = (string) parse_url($remoteUrl, PHP_URL_PATH);
                $extension = strtolower((string) pathinfo($urlPath, PATHINFO_EXTENSION));
                if (!in_array($extension, self::MANAGED_FONT_FILE_EXTENSIONS, true)) {
                    continue;
                }

                $fileName = $this->buildManagedFontFileName($slug, $urlPath, $extension);
                if ($fileName === null) {
                    $downloadWarnings[] = 'Für eine Remote-Datei konnte kein sicherer lokaler Dateiname erzeugt werden.';
                    continue;
                }

                $fontData = $this->fetchRemoteContent($remoteUrl);
                if ($fontData === false) {
                    $downloadWarnings[] = 'Remote-Datei konnte nicht geladen werden: ' . basename($urlPath);
                    continue;
                }

                if (!$this->validateDownloadedFontBinary($fontData, $extension)) {
                    $downloadWarnings[] = 'Remote-Datei hat keinen gültigen ' . strtoupper($extension) . '-Header: ' . basename($urlPath);
                    continue;
                }

                $fileBytes = strlen($fontData);
                if (($downloadedBytes + $fileBytes) > self::MAX_REMOTE_FONT_TOTAL_BYTES) {
                    $downloadWarnings[] = 'Download-Limit von ' . $this->formatBytesLabel(self::MAX_REMOTE_FONT_TOTAL_BYTES) . ' für Font-Dateien erreicht.';
                    break;
                }

                $localPath = $fontsDir . DIRECTORY_SEPARATOR . $fileName;
                if (!$this->writeManagedFontFileAtomically($localPath, $fontData, 0644)) {
                    $downloadWarnings[] = 'Remote-Datei konnte nicht lokal gespeichert werden: ' . basename($localPath);
                    continue;
                }

                $downloadedBytes += $fileBytes;
                $urlMap[$remoteUrl] = $fileName;
                $downloadedPaths[$fileName] = $localPath;
            }
        }

        $builtCss = $urlMap !== [] ? $this->buildLocalFontCss($css, $urlMap) : ['css' => '', 'primary' => '', 'files' => []];

        // Dateien, deren @font-face-Block beim Sanitizing verworfen wurde, nicht verwaist liegen lassen.
        foreach ($downloadedPaths as $fileName => $localPath) {
            if (!in_array($fileName, $builtCss['files'], true)) {
                @unlink($localPath);
                unset($downloadedPaths[$fileName]);
            }
        }

        if ($builtCss['css'] === '' || $builtCss['primary'] === '') {
            $this->cleanupDownloadedFontFiles(array_values($downloadedPaths));

            return $this->buildFontFailureResult(
                "Keine Font-Dateien konnten heruntergeladen werden für \"{$fontFamily}\".",
                'font_manager_font_download_failed',
                array_slice($downloadWarnings, 0, 3),
                ['font_family' => $fontFamily, 'slug' => $slug]
            );
        }

        $primaryFile = $builtCss['primary'];
        $primaryFormat = strtolower((string) pathinfo($primaryFile, PATHINFO_EXTENSION));
        if ($primaryFormat !== 'woff2') {
            $downloadWarnings[] = 'Google Fonts lieferte kein WOFF2 (' . strtoupper($primaryFormat) . ') – Dateien sind deutlich größer.';
            $this->logger->warning('Google Fonts lieferte kein WOFF2-Format.', ['font_family' => $fontFamily, 'format' => $primaryFormat]);
        }

        $cssFile = $fontsDir . DIRECTORY_SEPARATOR . $slug . '.css';
        if (!$this->writeManagedFontFileAtomically($cssFile, $builtCss['css'], 0644)) {
            if (!$isRefresh) {
                $this->cleanupDownloadedFontFiles(array_values($downloadedPaths));
            }

            return $this->buildFontFailureResult(
                'Lokale CSS-Datei für die Schrift konnte nicht geschrieben werden.',
                'font_manager_css_write_failed',
                [$isRefresh ? 'Die bisherige Schrift bleibt unverändert aktiv.' : 'Bereits geladene Font-Dateien wurden wieder entfernt.'],
                ['font_family' => $fontFamily, 'slug' => $slug]
            );
        }

        $localPaths = array_values($downloadedPaths);
        $localPaths[] = $cssFile;

        try {
            if ($isRefresh) {
                $this->db->execute(
                    "UPDATE {$this->prefix}custom_fonts SET format = ?, file_path = ?, css_path = ? WHERE id = ?",
                    [$primaryFormat, 'uploads/fonts/' . $primaryFile, 'uploads/fonts/' . $slug . '.css', (int) ($existingFont->id ?? 0)]
                );
            } else {
                $this->db->execute(
                    "INSERT INTO {$this->prefix}custom_fonts (name, slug, format, file_path, css_path, source, created_at)
                     VALUES (?, ?, ?, ?, ?, 'google-fonts-local', NOW())",
                    [$fontFamily, $slug, $primaryFormat, 'uploads/fonts/' . $primaryFile, 'uploads/fonts/' . $slug . '.css']
                );
            }

            $this->db->execute(
                "INSERT INTO {$this->prefix}settings (option_name, option_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
                ['font_stack_' . $slug, "'{$fontFamily}', sans-serif"]
            );
        } catch (\Throwable $e) {
            if (!$isRefresh) {
                $this->cleanupDownloadedFontFiles($localPaths);
            }
            $this->logger->error('Google Font konnte nach dem Download nicht persistiert werden.', [
                'font_family' => $fontFamily,
                'slug' => $slug,
                'exception' => $e,
            ]);

            return $this->buildFontFailureResult(
                'Die heruntergeladene Schrift konnte nicht persistiert werden.',
                'font_manager_persist_failed',
                [$isRefresh ? 'Die neue CSS-Datei ist bereits aktiv; bitte Vorgang wiederholen.' : 'Temporär geladene Dateien wurden wieder entfernt.'],
                ['font_family' => $fontFamily, 'slug' => $slug, 'exception' => $this->sanitizeErrorContext($e->getMessage())]
            );
        }

        $downloadedFiles = array_keys($downloadedPaths);

        AuditLogger::instance()->log(
            AuditLogger::CAT_THEME,
            $isRefresh ? 'font.refresh.google' : 'font.download.google',
            $isRefresh ? 'Google Font lokal neu geladen' : 'Google Font lokal gespeichert',
            'font',
            $isRefresh ? (int) ($existingFont->id ?? 0) : null,
            ['font_family' => $fontFamily, 'downloaded_files' => $downloadedFiles, 'format' => $primaryFormat],
            'info'
        );

        $this->invalidateThemeScanCache();

        return [
            'success' => true,
            'message' => $isRefresh
                ? "Schrift \"{$fontFamily}\" neu geladen (" . count($downloadedFiles) . ' Dateien, ' . strtoupper($primaryFormat) . ').'
                : "Schrift \"{$fontFamily}\" DSGVO-konform heruntergeladen (" . count($downloadedFiles) . ' Dateien, ' . strtoupper($primaryFormat) . '). Kein externer CDN-Aufruf mehr nötig.',
            'details' => array_values(array_filter([
                'Dateien: ' . count($downloadedFiles),
                'Format: ' . strtoupper($primaryFormat),
                'Gesamtgröße: ' . $this->formatBytesLabel($downloadedBytes),
                'CSS: uploads/fonts/' . $slug . '.css',
                'Preload-Datei: ' . $primaryFile,
                'Erlaubte Hosts: ' . implode(', ', self::ALLOWED_REMOTE_FONT_HOSTS),
                $downloadWarnings !== [] ? 'Hinweise: ' . implode(' | ', array_slice($downloadWarnings, 0, 2)) : '',
            ])),
            'local_paths' => $localPaths,
        ];
    }

    /**
     * Baut das lokale @font-face-CSS aus dem Google-CSS neu auf: nur erlaubte Eigenschaften,
     * nur lokal gespeicherte Dateien in src, font-display: swap. Blöcke mit nicht lokal
     * verfügbaren URLs werden verworfen, damit keine externen Requests übrig bleiben.
     *
     * @param array<string, string> $urlMap Remote-URL => lokaler Dateiname
     * @return array{css: string, primary: string, files: list<string>}
     */
    private function buildLocalFontCss(string $css, array $urlMap): array
    {
        $empty = ['css' => '', 'primary' => '', 'files' => []];
        $pattern = '/(?:\/\*\s*(?<label>[a-z0-9 \-]{1,40}?)\s*\*\/\s*)?@font-face\s*\{(?<body>[^{}]*)\}/i';
        if (preg_match_all($pattern, $css, $matches, PREG_SET_ORDER) < 1) {
            return $empty;
        }

        $blocks = [];
        $files = [];
        $primary = '';
        $primaryScore = -1;

        foreach ($matches as $match) {
            $label = strtolower(trim((string) ($match['label'] ?? '')));
            $declarations = [];
            $blockFiles = [];
            $valid = true;

            foreach (explode(';', (string) $match['body']) as $declaration) {
                if (trim($declaration) === '') {
                    continue;
                }

                if (!str_contains($declaration, ':')) {
                    $valid = false;
                    break;
                }

                [$property, $value] = array_map('trim', explode(':', $declaration, 2));
                $property = strtolower($property);
                if (!in_array($property, self::LOCAL_FONT_CSS_PROPERTIES, true) || $value === '') {
                    continue;
                }

                if ($property === 'src') {
                    $value = (string) preg_replace_callback(
                        '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
                        static function (array $urlMatch) use ($urlMap, &$blockFiles, &$valid): string {
                            $fileName = $urlMap[trim($urlMatch[2])] ?? null;
                            if ($fileName === null) {
                                $valid = false;
                                return '';
                            }
                            $blockFiles[] = $fileName;
                            return "url('" . $fileName . "')";
                        },
                        $value
                    );

                    if ($blockFiles === [] || preg_match('/^[A-Za-z0-9 ,.\'"()_\-]+$/', $value) !== 1) {
                        $valid = false;
                    }
                } elseif ($property === 'font-family') {
                    if (preg_match('/^([\'"]?)[A-Za-z0-9 _\-]+\1$/', $value) !== 1) {
                        $valid = false;
                    }
                } elseif ($property === 'font-display') {
                    $value = 'swap';
                } elseif (preg_match('/^[A-Za-z0-9 ,.+%?\-]+$/', $value) !== 1) {
                    $valid = false;
                }

                if (!$valid) {
                    break;
                }

                $declarations[$property] = $value;
            }

            if (!$valid || !isset($declarations['font-family'], $declarations['src'])) {
                continue;
            }

            $declarations['font-display'] = 'swap';

            $lines = [];
            foreach ($declarations as $property => $value) {
                $lines[] = '  ' . $property . ': ' . $value . ';';
            }

            $blocks[] = ($label !== '' ? '/* ' . $label . " */\n" : '') . "@font-face {\n" . implode("\n", $lines) . "\n}";
            foreach ($blockFiles as $fileName) {
                $files[$fileName] = $fileName;
            }

            $score = $this->scorePreloadCandidate($label, $declarations);
            $candidate = $this->pickPreferredFontFile($blockFiles);
            if ($score > $primaryScore && $candidate !== '') {
                $primaryScore = $score;
                $primary = $candidate;
            }
        }

        if ($blocks === []) {
            return $empty;
        }

        return [
            'css' => implode("\n\n", $blocks) . "\n",
            'primary' => $primary,
            'files' => array_values($files),
        ];
    }

    /** @param array<string, string> $declarations */
    private function scorePreloadCandidate(string $label, array $declarations): int
    {
        $score = 0;
        if ($label === 'latin' || $label === '') {
            $score += 4;
        }

        if (strtolower(trim($declarations['font-style'] ?? 'normal')) === 'normal') {
            $score += 2;
        }

        $weights = array_map('intval', preg_split('/\s+/', trim($declarations['font-weight'] ?? '400')) ?: ['400']);
        $minWeight = min($weights);
        $maxWeight = max($weights);
        if ($minWeight <= 400 && $maxWeight >= 400) {
            $score += 1;
        }

        return $score;
    }

    /** @param list<string> $fileNames */
    private function pickPreferredFontFile(array $fileNames): string
    {
        foreach (['woff2', 'woff', 'ttf', 'otf'] as $extension) {
            foreach ($fileNames as $fileName) {
                if (strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION)) === $extension) {
                    return $fileName;
                }
            }
        }

        return '';
    }

    private function isAllowedFontUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($scheme !== 'https') {
            return false;
        }

        return in_array($host, self::ALLOWED_REMOTE_FONT_HOSTS, true);
    }

    private function fetchRemoteContent(string $url): string|false
    {
        if (!$this->isAllowedFontUrl($url)) {
            return false;
        }

        $acceptHeader = str_contains($url, 'fonts.gstatic.com')
            ? 'font/woff2,font/woff;q=0.9,*/*;q=0.1'
            : 'text/css,*/*;q=0.1';

        $headers = [
            'Accept: ' . $acceptHeader,
            'Accept-Language: de-DE,de;q=0.9,en;q=0.8',
        ];

        // Google Fonts wählt das Format anhand des User-Agents: Nur ein vollständiger,
        // moderner Browser-UA (genau EIN Header) liefert WOFF2 statt TTF.
        $response = HttpClient::getInstance()->get($url, [
            'userAgent' => self::GOOGLE_FONTS_WOFF2_USER_AGENT,
            'headers' => $headers,
            'timeout' => 20,
            'connectTimeout' => 10,
            'maxBytes' => str_contains($url, 'fonts.gstatic.com') ? self::MAX_REMOTE_FONT_FILE_BYTES : 512 * 1024,
            'allowedContentTypes' => str_contains($url, 'fonts.gstatic.com')
                ? ['font/', 'application/octet-stream', 'application/font-', 'application/x-font-']
                : ['text/css'],
        ]);

        $content = (string) ($response['body'] ?? '');

        return (($response['success'] ?? false) === true && $content !== '') ? $content : false;
    }

    /** @param list<string> $keys
     *  @return array<string, true>
     */
    private function loadExistingSettings(array $keys): array
    {
        $keys = array_values(array_filter(array_unique($keys), static fn (mixed $key): bool => is_string($key) && $key !== ''));
        if ($keys === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $rows = $this->db->get_results(
            "SELECT option_name FROM {$this->prefix}settings WHERE option_name IN ({$placeholders})",
            $keys
        ) ?: [];

        $existing = [];
        foreach ($rows as $row) {
            $optionName = trim((string) ($row->option_name ?? ''));
            if ($optionName !== '') {
                $existing[$optionName] = true;
            }
        }

        return $existing;
    }

    /** @param array<string, true> $existingSettings */
    private function persistSetting(string $key, string $value, array &$existingSettings): void
    {
        if (isset($existingSettings[$key])) {
            $this->db->execute(
                "UPDATE {$this->prefix}settings SET option_value = ? WHERE option_name = ?",
                [$value, $key]
            );

            return;
        }

        $this->db->execute(
            "INSERT INTO {$this->prefix}settings (option_name, option_value) VALUES (?, ?)",
            [$key, $value]
        );
        $existingSettings[$key] = true;
    }

    /** @param array<string, scalar> $settings
     *  @param array<string, true> $existingSettings
     *  @return list<string>
     */
    private function collectChangedSettingKeys(array $settings, array $existingSettings): array
    {
        $currentValues = [];
        $existingKeys = array_values(array_filter(array_keys($settings), static fn (string $key): bool => isset($existingSettings[$key])));
        if ($existingKeys !== []) {
            $placeholders = implode(',', array_fill(0, count($existingKeys), '?'));
            foreach ($this->db->get_results(
                "SELECT option_name, option_value FROM {$this->prefix}settings WHERE option_name IN ({$placeholders})",
                $existingKeys
            ) ?: [] as $row) {
                $currentValues[(string) $row->option_name] = (string) ($row->option_value ?? '');
            }
        }

        $changedKeys = [];

        foreach ($settings as $key => $value) {
            if (!isset($existingSettings[$key]) || ($currentValues[$key] ?? null) !== (string) $value) {
                $changedKeys[] = $key;
            }
        }

        return $changedKeys;
    }

    /**
     * Einstellungen aus der DB
     */
    private function getSettings(): array
    {
        $settings = [];
        try {
            $rows = $this->db->get_results(
                "SELECT option_name, option_value FROM {$this->prefix}settings WHERE option_name LIKE 'font_%'"
            ) ?: [];
            foreach ($rows as $row) {
                $settings[$row->option_name] = $row->option_value;
            }
        } catch (\Throwable $e) {
            // Defaults verwenden
        }
        return $settings;
    }

    /**
     * Benutzerdefinierte Schriftarten laden
     */
    private function getCustomFonts(): array
    {
        try {
            return $this->db->get_results(
                "SELECT * FROM {$this->prefix}custom_fonts ORDER BY name ASC"
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function scanActiveThemeFonts(array $customFonts): array
    {
        $themeManager = ThemeManager::instance();
        $themePath    = $themeManager->getThemePath();
        $themeSlug    = $themeManager->getActiveThemeSlug();
        $knownFonts   = $this->getKnownFontIndex();
        $installed    = $this->getInstalledFontNames($customFonts);
        $detected     = [];
        $scannedFiles = 0;
        $skippedFiles = 0;
        $warnings = [];
        $scannedBytes = 0;

        if (!is_dir($themePath)) {
            return [
                'theme' => $themeSlug,
                'scannedFiles' => 0,
                'skippedFiles' => 0,
                'detectedFonts' => [],
                'warnings' => ['Der aktive Theme-Pfad ist kein lesbares Verzeichnis.'],
            ];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($themePath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $pathName = $file->getPathname();
            if ($this->shouldSkipScanPath($pathName)) {
                $skippedFiles++;
                continue;
            }

            if ($scannedFiles >= self::MAX_SCANNED_THEME_FILES) {
                $warnings[] = 'Der Theme-Scan wurde nach ' . self::MAX_SCANNED_THEME_FILES . ' Dateien begrenzt.';
                break;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, self::SCAN_ALLOWED_EXTENSIONS, true)) {
                $skippedFiles++;
                continue;
            }

            $fileSize = $file->getSize();
            if ($fileSize > self::MAX_SCANNED_THEME_FILE_BYTES) {
                $skippedFiles++;
                continue;
            }

            if (($scannedBytes + $fileSize) > self::MAX_SCANNED_TOTAL_BYTES) {
                $warnings[] = 'Der Theme-Scan wurde nach insgesamt 10 MB Textinhalt begrenzt.';
                break;
            }

            $content = $this->readFileContents($pathName, self::MAX_SCANNED_THEME_FILE_BYTES);
            if (!is_string($content) || $content === '') {
                $skippedFiles++;
                continue;
            }

            $scannedFiles++;
            $scannedBytes += $fileSize;
            $relativePath = str_replace(['\\', THEME_PATH], ['/', ''], $pathName);

            foreach ($this->extractGoogleFontFamilies($content) as $family) {
                $fontKey = $this->normalizeFontLookupKey($family);
                $meta = $knownFonts[$fontKey] ?? ['style' => 'Google Font', 'reason' => 'Im Theme via Google Fonts importiert'];
                if (!isset($detected[$fontKey])) {
                    $detected[$fontKey] = [
                        'name' => $family,
                        'style' => $meta['style'],
                        'reason' => $meta['reason'],
                        'sources' => [],
                        'installed' => in_array($fontKey, $installed, true),
                    ];
                }
                $detected[$fontKey]['sources'][$relativePath] = 'Google Fonts Import';
            }

            foreach ($knownFonts as $fontKey => $meta) {
                if (stripos($content, $meta['name']) === false) {
                    continue;
                }

                if (!isset($detected[$fontKey])) {
                    $detected[$fontKey] = [
                        'name' => $meta['name'],
                        'style' => $meta['style'],
                        'reason' => $meta['reason'],
                        'sources' => [],
                        'installed' => in_array($fontKey, $installed, true),
                    ];
                }
                $detected[$fontKey]['sources'][$relativePath] = 'Theme-Datei';
            }
        }

        uasort($detected, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'theme' => $themeSlug,
            'scannedFiles' => $scannedFiles,
            'skippedFiles' => $skippedFiles,
            'detectedFonts' => array_values(array_map(static function (array $font): array {
                $font['sources'] = array_map(
                    static fn(string $type, string $file): array => ['file' => $file, 'type' => $type],
                    array_values($font['sources']),
                    array_keys($font['sources'])
                );
                return $font;
            }, $detected)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function getThemeScanResults(array $customFonts, bool $forceRefresh = false): array
    {
        $themeSlug = ThemeManager::instance()->getActiveThemeSlug();

        if (!$forceRefresh) {
            $cachedResults = $this->loadThemeScanCache($themeSlug, $customFonts);
            if ($cachedResults !== null) {
                return $cachedResults;
            }
        }

        $results = $this->scanActiveThemeFonts($customFonts);
        $results['source'] = 'live';
        $results['generatedAt'] = date('d.m.Y H:i:s');
        $this->persistThemeScanCache($themeSlug, $results);

        return $results;
    }

    /** @return array<int, string> */
    private function getSelectableFontKeys(): array
    {
        $keys = array_keys(self::SYSTEM_FONTS);

        foreach ($this->getCustomFonts() as $font) {
            $slug = trim((string) ($font->slug ?? ''));
            if ($slug !== '') {
                $keys[] = strtolower($slug);
            }
        }

        return array_values(array_unique($keys));
    }

    /** @param array<int, string> $allowedKeys */
    private function normalizeSelectableFontKey(string $value, array $allowedKeys): string
    {
        $normalized = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($value))) ?? '';

        return in_array($normalized, $allowedKeys, true) ? $normalized : 'system-ui';
    }

    private function getCuratedFontCatalog(array $customFonts): array
    {
        $installed = $this->getInstalledFontNames($customFonts);
        $catalog   = [];

        foreach (self::CURATED_FONT_LIBRARY as $category => $fonts) {
            $catalog[$category] = array_map(function (array $font) use ($installed): array {
                $font['installed'] = in_array($this->normalizeFontLookupKey((string) ($font['name'] ?? '')), $installed, true);
                return $font;
            }, $fonts);
        }

        return $catalog;
    }

    /**
     * @param array<string, string> $availableFonts
     * @param array<int, object> $customFonts
     * @param array<int, array<string, mixed>> $customFontRows
     * @param array<string, mixed> $scanResults
     * @return array<string, mixed>
     */
    private function buildFontUsageAnalysis(
        array $availableFonts,
        array $customFonts,
        array $customFontRows,
        array $scanResults,
        string $headingFont,
        string $bodyFont,
        bool $useLocalFonts
    ): array {
        $customFontsBySlug = [];
        foreach ($customFonts as $font) {
            $slug = $this->sanitizeFontKey((string) ($font->slug ?? ''));
            if ($slug === '') {
                continue;
            }

            $customFontsBySlug[$slug] = [
                'slug' => $slug,
                'name' => trim((string) ($font->name ?? $slug)),
                'source' => trim((string) ($font->source ?? '')),
            ];
        }

        $customFontRowsBySlug = [];
        foreach ($customFontRows as $row) {
            $slug = $this->sanitizeFontKey((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $customFontRowsBySlug[$slug] = $row;
        }

        $detectedFontsBySlug = [];
        foreach ((array) ($scanResults['detectedFonts'] ?? []) as $font) {
            if (!is_array($font)) {
                continue;
            }

            $name = trim((string) ($font['name'] ?? ''));
            $slug = $this->normalizeFontSlug($name);
            if ($name === '' || $slug === '') {
                continue;
            }

            $sources = [];
            foreach ((array) ($font['sources'] ?? []) as $source) {
                if (!is_array($source)) {
                    continue;
                }

                $file = trim((string) ($source['file'] ?? ''));
                $type = trim((string) ($source['type'] ?? 'Theme-Datei'));
                if ($file === '') {
                    continue;
                }

                $sources[$file] = [
                    'file' => $file,
                    'type' => $type !== '' ? $type : 'Theme-Datei',
                ];
            }

            $detectedFontsBySlug[$slug] = [
                'name' => $name,
                'style' => trim((string) ($font['style'] ?? 'Theme-Font')),
                'reason' => trim((string) ($font['reason'] ?? '')),
                'sources' => array_values($sources),
                'installed' => !empty($font['installed']),
            ];
        }

        $headingKey = $this->sanitizeFontKey($headingFont);
        $bodyKey = $this->sanitizeFontKey($bodyFont);
        $configuredKeys = array_values(array_unique(array_filter([
            $headingKey,
            $bodyKey,
        ], static fn (string $key): bool => $key !== '')));

        $configuredFonts = [
            $this->buildConfiguredFontUsageRow('Überschriften', $headingKey, $availableFonts, $customFontsBySlug, $customFontRowsBySlug, $detectedFontsBySlug, $useLocalFonts),
            $this->buildConfiguredFontUsageRow('Fließtext', $bodyKey, $availableFonts, $customFontsBySlug, $customFontRowsBySlug, $detectedFontsBySlug, $useLocalFonts),
        ];

        $analysisKeys = array_values(array_unique(array_merge(
            array_keys($customFontsBySlug),
            array_keys($detectedFontsBySlug),
            $configuredKeys
        )));

        $fontEntries = [];
        foreach ($analysisKeys as $fontKey) {
            if (!is_string($fontKey) || $fontKey === '') {
                continue;
            }

            $fontEntries[] = $this->buildFontUsageEntry(
                $fontKey,
                $availableFonts,
                $customFontsBySlug,
                $customFontRowsBySlug,
                $detectedFontsBySlug,
                $headingKey,
                $bodyKey,
                $useLocalFonts
            );
        }

        usort($fontEntries, function (array $left, array $right): int {
            $priorityCompare = ((int) ($left['priority'] ?? 999)) <=> ((int) ($right['priority'] ?? 999));
            if ($priorityCompare !== 0) {
                return $priorityCompare;
            }

            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        $remainingExternalFonts = count(array_filter($fontEntries, static fn (array $entry): bool => (string) ($entry['status_key'] ?? '') === 'external'));
        $unusedLocalFonts = count(array_filter($fontEntries, static fn (array $entry): bool => (string) ($entry['status_key'] ?? '') === 'unused-local'));
        $localPendingFonts = count(array_filter($fontEntries, static fn (array $entry): bool => (string) ($entry['status_key'] ?? '') === 'local-pending'));
        $fontsWithAssetWarnings = count(array_filter($fontEntries, static fn (array $entry): bool => !empty($entry['asset_warning'])));

        $warnings = [];
        if ($useLocalFonts && $remainingExternalFonts > 0) {
            $warnings[] = $remainingExternalFonts . ' erkannte Theme-Schrift' . ($remainingExternalFonts === 1 ? '' : 'en') . ' werden trotz aktivem lokalen Font-Modus noch nicht lokal bereitgestellt.';
        }

        if (!$useLocalFonts && $localPendingFonts > 0) {
            $warnings[] = $localPendingFonts . ' konfigurierte lokale Schrift' . ($localPendingFonts === 1 ? '' : 'en') . ' werden erst nach Aktivierung von „Lokale On-Prem-Fonts“ im Frontend eingebunden.';
        }

        if ($fontsWithAssetWarnings > 0) {
            $warnings[] = $fontsWithAssetWarnings . ' lokale Schrift' . ($fontsWithAssetWarnings === 1 ? '' : 'en') . ' haben unvollständige CSS- oder Asset-Verknüpfungen und sollten geprüft werden.';
        }

        $legacyFormatFonts = count(array_filter($customFontRows, static fn (array $row): bool => !empty($row['uses_legacy_format'])));
        if ($legacyFormatFonts > 0) {
            $warnings[] = $legacyFormatFonts . ' lokale Schrift' . ($legacyFormatFonts === 1 ? ' liegt' : 'en liegen') . ' noch als TTF/OTF vor. „Als WOFF2 neu laden“ reduziert die Dateigröße pro Schnitt um rund 80 %.';
        }

        return [
            'runtime_mode_label' => $useLocalFonts ? 'Lokaler Font-Modus aktiv' : 'Externer Fallback möglich',
            'runtime_mode_badge_class' => $useLocalFonts ? 'bg-green-lt' : 'bg-orange-lt',
            'runtime_mode_note' => $useLocalFonts
                ? 'Bootstrap und Theme laden CSS nur für konfigurierte lokale Fonts plus Systemstacks.'
                : 'Theme-Scan und Konfiguration bleiben read-only sichtbar; externe Imports können weiter aktiv sein.',
            'stats' => [
                [
                    'label' => 'Erkannte Theme-Fonts',
                    'value' => count($detectedFontsBySlug),
                    'hint' => 'Aus Theme-Scan und Import-Erkennung',
                    'badge_class' => 'bg-azure-lt',
                ],
                [
                    'label' => 'Noch extern',
                    'value' => $remainingExternalFonts,
                    'hint' => $remainingExternalFonts > 0 ? 'Self-Hosting oder Theme-Bereinigung empfohlen' : 'Keine offenen externen Scan-Treffer',
                    'badge_class' => $remainingExternalFonts > 0 ? 'bg-orange-lt' : 'bg-green-lt',
                ],
                [
                    'label' => 'Lokal gespeichert',
                    'value' => count($customFontsBySlug),
                    'hint' => $useLocalFonts ? 'Lokale Bereitstellung ist aktivierbar' : 'Lokale Bibliothek vorhanden',
                    'badge_class' => 'bg-green-lt',
                ],
                [
                    'label' => 'Ungenutzte lokale Fonts',
                    'value' => $unusedLocalFonts,
                    'hint' => $unusedLocalFonts > 0 ? 'Kandidaten für Aufräumen oder spätere Nutzung' : 'Keine lokalen Karteileichen erkannt',
                    'badge_class' => $unusedLocalFonts > 0 ? 'bg-secondary-lt' : 'bg-green-lt',
                ],
            ],
            'configured_fonts' => $configuredFonts,
            'font_entries' => $fontEntries,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, string> $availableFonts
     * @param array<string, array<string, string>> $customFontsBySlug
     * @param array<string, array<string, mixed>> $customFontRowsBySlug
     * @param array<string, array<string, mixed>> $detectedFontsBySlug
     * @return array<string, mixed>
     */
    private function buildConfiguredFontUsageRow(
        string $slotLabel,
        string $fontKey,
        array $availableFonts,
        array $customFontsBySlug,
        array $customFontRowsBySlug,
        array $detectedFontsBySlug,
        bool $useLocalFonts
    ): array {
        $fontLabel = trim((string) ($availableFonts[$fontKey] ?? ''));
        $customFont = $customFontsBySlug[$fontKey] ?? null;
        $customRow = $customFontRowsBySlug[$fontKey] ?? null;
        $detectedFont = $detectedFontsBySlug[$fontKey] ?? null;
        $isSystemFont = array_key_exists($fontKey, self::SYSTEM_FONTS);
        $assetWarning = (string) ($customRow['asset_status'] ?? '') === 'warning';

        $deliveryLabel = 'Nicht erkannt';
        $deliveryBadgeClass = 'bg-secondary-lt';
        $note = 'Für diese Zuweisung wurde keine bekannte Font-Quelle erkannt.';

        if ($isSystemFont) {
            $deliveryLabel = 'Systemschrift';
            $deliveryBadgeClass = 'bg-secondary-lt';
            $note = 'Browser- oder Betriebssystem-Font, keine zusätzliche Datei nötig.';
        } elseif ($customFont !== null && $useLocalFonts && !$assetWarning) {
            $deliveryLabel = 'Lokal aktiv';
            $deliveryBadgeClass = 'bg-green-lt';
            $note = 'Bootstrap bindet die lokale CSS-Datei für diese Zuweisung im Frontend ein.';
        } elseif ($customFont !== null && $useLocalFonts) {
            $deliveryLabel = 'Lokal aktiv · Assets prüfen';
            $deliveryBadgeClass = 'bg-warning-lt';
            $note = 'Die Font ist konfiguriert, aber lokale CSS- oder Asset-Dateien sind unvollständig.';
        } elseif ($customFont !== null) {
            $deliveryLabel = 'Lokal vorhanden';
            $deliveryBadgeClass = 'bg-orange-lt';
            $note = 'Die lokale Font ist gespeichert, wird aber erst nach Aktivierung von „Lokale On-Prem-Fonts“ sicher eingebunden.';
        } elseif ($detectedFont !== null) {
            $deliveryLabel = $useLocalFonts ? 'Extern erkannt' : 'Externer Fallback';
            $deliveryBadgeClass = 'bg-orange-lt';
            $note = 'Das Theme erkennt diese Schrift bereits, lokal liegt sie für die Font-Manager-Zuweisung aber noch nicht vor.';
        }

        if ($fontLabel === '') {
            $fontLabel = $customFont['name'] ?? $detectedFont['name'] ?? ($fontKey !== '' ? $fontKey : 'System UI');
        }

        return [
            'slot_label' => $slotLabel,
            'font_key' => $fontKey !== '' ? $fontKey : 'system-ui',
            'font_label' => $fontLabel,
            'delivery_label' => $deliveryLabel,
            'delivery_badge_class' => $deliveryBadgeClass,
            'note' => $note,
        ];
    }

    /**
     * @param array<string, string> $availableFonts
     * @param array<string, array<string, string>> $customFontsBySlug
     * @param array<string, array<string, mixed>> $customFontRowsBySlug
     * @param array<string, array<string, mixed>> $detectedFontsBySlug
     * @return array<string, mixed>
     */
    private function buildFontUsageEntry(
        string $fontKey,
        array $availableFonts,
        array $customFontsBySlug,
        array $customFontRowsBySlug,
        array $detectedFontsBySlug,
        string $headingKey,
        string $bodyKey,
        bool $useLocalFonts
    ): array {
        $customFont = $customFontsBySlug[$fontKey] ?? null;
        $customRow = $customFontRowsBySlug[$fontKey] ?? null;
        $detectedFont = $detectedFontsBySlug[$fontKey] ?? null;
        $isSystemFont = array_key_exists($fontKey, self::SYSTEM_FONTS);
        $isHeading = $fontKey !== '' && $fontKey === $headingKey;
        $isBody = $fontKey !== '' && $fontKey === $bodyKey;
        $isConfigured = $isHeading || $isBody;
        $isDetected = $detectedFont !== null;
        $assetWarning = (string) ($customRow['asset_status'] ?? '') === 'warning';
        $sourceCount = is_array($detectedFont['sources'] ?? null) ? count((array) $detectedFont['sources']) : 0;

        $name = trim((string) ($availableFonts[$fontKey] ?? ''));
        if ($name === '') {
            $name = trim((string) ($customFont['name'] ?? $detectedFont['name'] ?? $fontKey));
        }

        $typeLabel = 'Erkannt';
        $typeBadgeClass = 'bg-azure-lt';
        if ($isSystemFont) {
            $typeLabel = 'System';
            $typeBadgeClass = 'bg-secondary-lt';
        } elseif ($customFont !== null) {
            $typeLabel = 'Lokal gespeichert';
            $typeBadgeClass = 'bg-green-lt';
        }

        $roles = [];
        if ($isHeading) {
            $roles[] = 'Heading';
        }
        if ($isBody) {
            $roles[] = 'Body';
        }
        if ($isDetected) {
            $roles[] = 'Theme-Scan';
        }

        $statusKey = 'unknown';
        $statusLabel = 'Unbekannt';
        $statusBadgeClass = 'bg-secondary-lt';
        $note = 'Kein klarer Runtime-Hinweis verfügbar.';
        $recommendation = 'Keine direkte Aktion nötig.';

        if ($isSystemFont) {
            $statusKey = 'system';
            $statusLabel = 'Systemschrift';
            $statusBadgeClass = 'bg-secondary-lt';
            $note = 'Diese Schrift benötigt keine lokale Datei und keine externe CDN-Anfrage.';
            $recommendation = 'Keine Aktion nötig.';
        } elseif ($customFont !== null && $useLocalFonts && $isConfigured && !$assetWarning) {
            $statusKey = 'local-active';
            $statusLabel = 'Lokal aktiv';
            $statusBadgeClass = 'bg-green-lt';
            $note = 'Wird aus der lokalen Font-Bibliothek im Frontend vorgeladen.';
            $recommendation = 'Keine Aktion nötig.';
        } elseif ($customFont !== null && $useLocalFonts && $isConfigured) {
            $statusKey = 'local-warning';
            $statusLabel = 'Lokal aktiv · Assets prüfen';
            $statusBadgeClass = 'bg-warning-lt';
            $note = 'Die Font ist aktiv, aber Asset- oder CSS-Dateien sind unvollständig.';
            $recommendation = 'CSS- und Font-Dateien prüfen oder Font erneut lokal speichern.';
        } elseif ($customFont !== null && $isConfigured) {
            $statusKey = 'local-pending';
            $statusLabel = 'Konfiguriert, aber nicht aktiv';
            $statusBadgeClass = 'bg-orange-lt';
            $note = 'Die Font ist ausgewählt, wird aber erst bei aktiviertem lokalem Font-Modus sicher eingebunden.';
            $recommendation = '„Lokale On-Prem-Fonts“ aktivieren oder auf eine Systemschrift zurückstellen.';
        } elseif ($customFont !== null && !$isDetected) {
            $statusKey = 'unused-local';
            $statusLabel = 'Lokal gespeichert, aktuell ungenutzt';
            $statusBadgeClass = 'bg-secondary-lt';
            $note = 'Die Font ist lokal vorhanden, wird aber weder konfiguriert noch im Theme-Scan erkannt.';
            $recommendation = 'Bei Nichtgebrauch löschen oder bewusst zuweisen.';
        } elseif ($customFont !== null) {
            $statusKey = 'local-available';
            $statusLabel = 'Lokal vorhanden';
            $statusBadgeClass = 'bg-azure-lt';
            $note = 'Die Font liegt lokal vor und wird im Theme erkannt, ist aber nicht direkt als Heading/Body zugewiesen.';
            $recommendation = 'Bei Bedarf als Heading-/Body-Font zuweisen oder externe Imports im Theme bereinigen.';
        } elseif ($isDetected) {
            $statusKey = 'external';
            $statusLabel = $useLocalFonts ? 'Extern erkannt' : 'Externer Fallback';
            $statusBadgeClass = 'bg-orange-lt';
            $note = 'Der Theme-Scan erkennt diese Schrift, lokal ist sie noch nicht im Font Manager vorhanden.';
            $recommendation = 'Self-Hosting im Font Manager starten oder Theme-Import entfernen.';
        }

        $sourcePreview = [];
        foreach (array_slice((array) ($detectedFont['sources'] ?? []), 0, 3) as $source) {
            if (!is_array($source)) {
                continue;
            }

            $file = trim((string) ($source['file'] ?? ''));
            $type = trim((string) ($source['type'] ?? 'Theme-Datei'));
            if ($file === '') {
                continue;
            }

            $sourcePreview[] = [
                'file' => $file,
                'type' => $type !== '' ? $type : 'Theme-Datei',
            ];
        }

        return [
            'font_key' => $fontKey,
            'name' => $name !== '' ? $name : $fontKey,
            'type_label' => $typeLabel,
            'type_badge_class' => $typeBadgeClass,
            'roles' => $roles,
            'status_key' => $statusKey,
            'status_label' => $statusLabel,
            'status_badge_class' => $statusBadgeClass,
            'note' => $note,
            'recommendation' => $recommendation,
            'source_count' => $sourceCount,
            'source_preview' => $sourcePreview,
            'asset_warning' => $assetWarning,
            'priority' => $this->getFontUsagePriority($statusKey, $isConfigured),
        ];
    }

    private function getFontUsagePriority(string $statusKey, bool $isConfigured): int
    {
        if ($isConfigured) {
            return match ($statusKey) {
                'local-warning' => 10,
                'local-pending' => 20,
                'external' => 30,
                'local-active' => 40,
                'system' => 50,
                default => 60,
            };
        }

        return match ($statusKey) {
            'external' => 70,
            'local-available' => 80,
            'unused-local' => 90,
            'system' => 100,
            default => 110,
        };
    }

    private function getKnownFontIndex(): array
    {
        $fonts = [];
        foreach (self::CURATED_FONT_LIBRARY as $category => $items) {
            foreach ($items as $item) {
                $fonts[$this->normalizeFontLookupKey((string) ($item['name'] ?? ''))] = $item;
            }
        }

        return $fonts;
    }

    private function getInstalledFontNames(array $customFonts): array
    {
        $installed = [];
        foreach ($customFonts as $font) {
            $name = trim((string)($font->name ?? ''));
            if ($name !== '') {
                $installed[] = $this->normalizeFontLookupKey($name);
            }
        }

        return array_values(array_unique($installed));
    }

    private function extractGoogleFontFamilies(string $content): array
    {
        $families = [];

        if (preg_match_all('/fonts\.googleapis\.com\/css2?\?([^"\'\s)]+)/i', $content, $matches)) {
            foreach ($matches[1] as $queryString) {
                if (preg_match_all('/(?:^|&)family=([^&]+)/', $queryString, $familyMatches)) {
                    foreach ($familyMatches[1] as $rawFamily) {
                        $family = trim(str_replace(['+', ':'], [' ', ' '], urldecode($rawFamily)));
                        $family = preg_replace('/\s+wght\s+.*/i', '', $family) ?? $family;
                        $family = trim($family);
                        if ($family !== '') {
                            $families[] = $family;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($families));
    }

    private function optimizeGoogleFontCssForLocalDelivery(string $css): string
    {
        $normalizedCss = str_replace(["\r\n", "\r"], "\n", trim($css));
        if ($normalizedCss === '' || stripos($normalizedCss, '@font-face') === false) {
            return $normalizedCss;
        }

        if (preg_match_all('/(?:\/\*\s*(?<label>[^*]+?)\s*\*\/\s*)?(?<block>@font-face\s*\{.*?\})/is', $normalizedCss, $matches, PREG_SET_ORDER) < 1) {
            return $normalizedCss;
        }

        $keptBlocks = [];

        foreach ($matches as $match) {
            $label = strtolower(trim(preg_replace('/\s+/u', ' ', (string) ($match['label'] ?? '')) ?? ''));
            $block = trim((string) ($match['block'] ?? ''));

            if ($block === '') {
                continue;
            }

            if (!$this->shouldKeepOptimizedFontCssBlock($label, $block)) {
                continue;
            }

            $keptBlocks[] = ($label !== '' ? '/* ' . $label . " */\n" : '') . $block;
        }

        if ($keptBlocks === []) {
            return $normalizedCss;
        }

        return implode("\n\n", $keptBlocks) . "\n";
    }

    private function shouldKeepOptimizedFontCssBlock(string $label, string $block): bool
    {
        if ($label === '' || in_array($label, self::LOCAL_FONT_ALLOWED_SUBSETS, true)) {
            return true;
        }

        return preg_match('/unicode-range\s*:/i', $block) !== 1;
    }

    /**
     * @param array<int, string> $warnings
     */
    private function deleteFileIfExists(string $path, array &$warnings): bool
    {
        if (!is_file($path)) {
            return true;
        }

        if (!unlink($path)) {
            $warnings[] = 'Datei konnte nicht gelöscht werden: ' . basename($path);
            return false;
        }

        return true;
    }

    private function readFileContents(string $path, int $maxBytes = self::MAX_SCANNED_THEME_FILE_BYTES): string|false
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $fileSize = filesize($path);
        if ($fileSize === false || $fileSize > $maxBytes) {
            return false;
        }

        return file_get_contents($path);
    }

    private function shouldSkipScanPath(string $path): bool
    {
        $normalizedPath = strtolower(str_replace('\\', '/', $path));

        foreach (self::SCAN_SKIPPED_SEGMENTS as $segment) {
            if (str_contains($normalizedPath, '/' . strtolower($segment) . '/')) {
                return true;
            }
        }

        return preg_match('#/(?:\.[^/]+)(?:/|$)#', $normalizedPath) === 1;
    }

    private function buildManagedFontFileName(string $slug, string $urlPath, string $extension): ?string
    {
        $baseName = basename($urlPath);
        $baseName = preg_replace('/[^a-zA-Z0-9._-]/', '-', $baseName) ?? '';
        $baseName = preg_replace('/-+/', '-', $baseName) ?? '';
        $baseName = trim($baseName, '-_.');

        if ($baseName === '' || preg_match('/[\x00-\x1F\x7F]/', $baseName) === 1) {
            return null;
        }

        $expectedSuffix = '.' . strtolower($extension);
        if (!str_ends_with(strtolower($baseName), $expectedSuffix)) {
            $baseName .= $expectedSuffix;
        }

        $fileName = $slug . '-' . $baseName;
        if (strlen($fileName) > self::MAX_FONT_FILENAME_LENGTH) {
            $suffix = '-' . substr(hash('sha256', $fileName), 0, 12) . $expectedSuffix;
            $maxBaseLength = self::MAX_FONT_FILENAME_LENGTH - strlen($suffix);
            if ($maxBaseLength <= 0) {
                return null;
            }

            $fileName = substr($slug . '-' . pathinfo($baseName, PATHINFO_FILENAME), 0, $maxBaseLength) . $suffix;
        }

        return $fileName;
    }

    private function ensureManagedFontsDirectory(): ?string
    {
        $basePath = defined('ABSPATH') ? rtrim((string) ABSPATH, '/\\') : '';
        if ($basePath === '') {
            return null;
        }

        $uploadsDir = $basePath . DIRECTORY_SEPARATOR . 'uploads';
        $fontsDir = $uploadsDir . DIRECTORY_SEPARATOR . 'fonts';

        if (!is_dir($fontsDir) && !mkdir($fontsDir, 0755, true)) {
            return null;
        }

        $resolvedUploadsDir = realpath($uploadsDir);
        $resolvedFontsDir = realpath($fontsDir);
        if ($resolvedUploadsDir === false || $resolvedFontsDir === false) {
            return null;
        }

        $normalizedUploadsDir = rtrim(str_replace('\\', '/', $resolvedUploadsDir), '/');
        $normalizedFontsDir = rtrim(str_replace('\\', '/', $resolvedFontsDir), '/');

        if (!str_starts_with($normalizedFontsDir, $normalizedUploadsDir . '/')) {
            return null;
        }

        if (!is_writable($resolvedFontsDir)) {
            return null;
        }

        return $resolvedFontsDir;
    }

    private function writeManagedFontFileAtomically(string $path, string $content, int $chmod = 0644): bool
    {
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            return false;
        }

        $tempPath = tempnam($directory, self::TEMP_FONT_FILE_PREFIX);
        if ($tempPath === false) {
            return false;
        }

        if (file_put_contents($tempPath, $content, LOCK_EX) === false) {
            @unlink($tempPath);

            return false;
        }

        $backupPath = null;
        if (is_file($path)) {
            $backupPath = $path . '.swap.' . str_replace('.', '', uniqid('', true));
            if (!@rename($path, $backupPath)) {
                @unlink($tempPath);

                return false;
            }
        }

        if (!@rename($tempPath, $path)) {
            if ($backupPath !== null && is_file($backupPath)) {
                @rename($backupPath, $path);
            }

            @unlink($tempPath);

            return false;
        }

        @chmod($path, $chmod);

        $savedHash = @hash_file('sha512', $path);
        $expectedHash = hash('sha512', $content);
        if (!is_string($savedHash) || !hash_equals($expectedHash, $savedHash)) {
            @unlink($path);

            if ($backupPath !== null && is_file($backupPath)) {
                @rename($backupPath, $path);
                @chmod($path, $chmod);
            }

            return false;
        }

        if ($backupPath !== null && is_file($backupPath)) {
            @unlink($backupPath);
        }

        return true;
    }

    /** @return array<int, string> */
    private function extractManagedFontAssetPathsFromCss(string $cssFilePath): array
    {
        $content = $this->readFileContents($cssFilePath, self::MAX_SCANNED_THEME_FILE_BYTES);
        if (!is_string($content) || $content === '') {
            return [];
        }

        if (preg_match_all('/url\(([^)]+)\)/i', $content, $matches) < 1) {
            return [];
        }

        $assets = [];
        $cssDirectory = dirname($cssFilePath);

        foreach ($matches[1] as $rawAssetPath) {
            $resolvedPath = $this->resolveManagedFontCssAssetPath($cssDirectory, (string) $rawAssetPath);
            if ($resolvedPath !== null) {
                $assets[$resolvedPath] = $resolvedPath;
            }
        }

        return array_values($assets);
    }

    private function resolveManagedFontCssAssetPath(string $cssDirectory, string $rawAssetPath): ?string
    {
        $assetPath = trim($rawAssetPath, " \t\n\r\0\x0B'\"");
        if ($assetPath === '' || str_starts_with(strtolower($assetPath), 'data:') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $assetPath) === 1) {
            return null;
        }

        $assetPath = preg_replace('/[#?].*$/', '', str_replace('\\', '/', $assetPath)) ?? '';
        if ($assetPath === '' || str_contains($assetPath, '..')) {
            return null;
        }

        $extension = strtolower((string) pathinfo($assetPath, PATHINFO_EXTENSION));
        if (!in_array($extension, self::MANAGED_FONT_FILE_EXTENSIONS, true)) {
            return null;
        }

        $managedFontsDir = $this->getManagedFontsDirectory();
        if ($managedFontsDir === null) {
            return null;
        }

        $candidatePath = $cssDirectory . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $assetPath), DIRECTORY_SEPARATOR);
        $resolvedCandidate = realpath($candidatePath);
        $normalizedManagedDir = str_replace('\\', '/', rtrim($managedFontsDir, '/\\'));

        if ($resolvedCandidate !== false) {
            $normalizedResolvedPath = str_replace('\\', '/', $resolvedCandidate);

            return str_starts_with($normalizedResolvedPath, $normalizedManagedDir . '/') ? $resolvedCandidate : null;
        }

        $normalizedCandidatePath = str_replace('\\', '/', $candidatePath);

        return str_starts_with($normalizedCandidatePath, $normalizedManagedDir . '/') ? $candidatePath : null;
    }

    private function normalizeFontSlug(string $fontFamily): string
    {
        $lookupKey = $this->normalizeFontLookupKey($fontFamily);
        if (isset(self::FONT_SLUG_ALIASES[$lookupKey])) {
            return self::FONT_SLUG_ALIASES[$lookupKey];
        }

        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower($fontFamily)) ?? '');
        $slug = trim($slug, '-');

        if ($slug === '') {
            return '';
        }

        return strlen($slug) > 120 ? substr($slug, 0, 120) : $slug;
    }

    private function validateDownloadedFontBinary(string $content, string $extension): bool
    {
        $header = substr($content, 0, 4);

        return match (strtolower($extension)) {
            'woff' => $header === 'wOFF',
            'woff2' => $header === 'wOF2',
            'otf' => $header === 'OTTO',
            'ttf' => in_array($header, ["\x00\x01\x00\x00", 'true', 'typ1'], true),
            default => false,
        };
    }

    /** @param array<int, string> $paths */
    private function cleanupDownloadedFontFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function formatBytesLabel(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }

    /** @param array<int, object> $customFonts
     *  @return array<int, array<string, mixed>>
     */
    private function buildCustomFontRows(array $customFonts): array
    {
        $rows = [];

        foreach ($customFonts as $font) {
            $slug = $this->sanitizeFontKey((string) ($font->slug ?? ''));
            $filePath = (string) ($font->file_path ?? '');
            $cssPath = (string) ($font->css_path ?? '');
            $resolvedFilePath = $this->resolveManagedFontPath($filePath);
            $resolvedCssPath = $this->resolveManagedFontPath($cssPath);
            $fileExists = $resolvedFilePath !== null && is_file($resolvedFilePath);
            $cssExists = $resolvedCssPath !== null && is_file($resolvedCssPath);
            $fileSize = $fileExists ? (int) (filesize($resolvedFilePath) ?: 0) : 0;
            $linkedFontAssets = $cssExists && $resolvedCssPath !== null ? $this->extractManagedFontAssetPathsFromCss($resolvedCssPath) : [];
            $missingLinkedAssets = 0;
            foreach ($linkedFontAssets as $linkedAssetPath) {
                if (!is_file($linkedAssetPath)) {
                    $missingLinkedAssets++;
                }
            }
            $assetStatus = $fileExists && ($cssPath === '' || ($cssExists && $missingLinkedAssets === 0)) ? 'complete' : 'warning';

            $assetFormats = [];
            foreach ($linkedFontAssets !== [] ? $linkedFontAssets : ($resolvedFilePath !== null ? [$resolvedFilePath] : []) as $assetPath) {
                $assetExtension = strtolower((string) pathinfo($assetPath, PATHINFO_EXTENSION));
                if ($assetExtension !== '') {
                    $assetFormats[$assetExtension] = $assetExtension;
                }
            }
            $storedFormat = strtolower(trim((string) ($font->format ?? '')));
            $usesLegacyFormat = array_intersect(array_keys($assetFormats), self::LEGACY_FONT_FORMATS) !== []
                || ($assetFormats === [] && in_array($storedFormat, self::LEGACY_FONT_FORMATS, true));

            $rows[] = [
                'id' => (int) ($font->id ?? 0),
                'slug' => $slug,
                'name' => (string) ($font->name ?? ''),
                'format' => (string) ($font->format ?? ''),
                'source' => (string) ($font->source ?? ''),
                'file_path' => $filePath,
                'css_path' => $cssPath,
                'file_exists' => $fileExists,
                'css_exists' => $cssExists,
                'file_size_label' => $fileSize > 0 ? $this->formatBytesLabel($fileSize) : '',
                'linked_asset_count' => count($linkedFontAssets),
                'missing_linked_asset_count' => $missingLinkedAssets,
                'asset_status' => $assetStatus,
                'asset_formats' => array_values($assetFormats),
                'uses_legacy_format' => $usesLegacyFormat,
                'refreshable' => (string) ($font->source ?? '') === 'google-fonts-local',
            ];
        }

        return $rows;
    }

    private function buildFontFailureResult(string $message, string $errorCode, array $details = [], array $errorData = [], array $context = []): array
    {
        $context = array_merge([
            'source' => '/admin/font-manager',
            'title' => 'Font Manager',
        ], $context);

        return [
            'success' => false,
            'error' => $message,
            'details' => array_values(array_filter(array_map(static fn (mixed $detail): string => trim((string) $detail), $details), static fn (string $detail): bool => $detail !== '')),
            'error_details' => [
                'code' => $errorCode,
                'data' => $errorData,
                'context' => $context,
            ],
            'report_payload' => ErrorReportService::buildReportPayloadFromWpError(
                new \CMS\WP_Error($errorCode, $message, $errorData),
                $context
            ),
        ];
    }

    private function getManagedFontsDirectory(): ?string
    {
        $basePath = defined('ABSPATH') ? (string) ABSPATH : '';
        if ($basePath === '') {
            return null;
        }

        $fontsDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'fonts';

        if (!is_dir($fontsDir)) {
            return null;
        }

        $resolvedFontsDir = realpath($fontsDir);

        return $resolvedFontsDir !== false ? rtrim($resolvedFontsDir, '/\\') : null;
    }

    private function loadThemeScanCache(string $themeSlug, array $customFonts): ?array
    {
        $cacheKey = self::SCAN_CACHE_OPTION_PREFIX . preg_replace('/[^a-z0-9_-]/', '', strtolower($themeSlug));
        if ($cacheKey === self::SCAN_CACHE_OPTION_PREFIX) {
            return null;
        }

        $payload = $this->db->get_var(
            "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1",
            [$cacheKey]
        );
        $cacheData = \CMS\Json::decodeArray((string) $payload, []);
        if (!is_array($cacheData)) {
            return null;
        }

        $generatedAt = (int) ($cacheData['generated_at'] ?? 0);
        if ($generatedAt <= 0 || (time() - $generatedAt) > self::SCAN_CACHE_TTL) {
            return null;
        }

        $results = is_array($cacheData['results'] ?? null) ? $cacheData['results'] : [];
        if ($results === []) {
            return null;
        }

        $installed = $this->getInstalledFontNames($customFonts);
        $detectedFonts = is_array($results['detectedFonts'] ?? null) ? $results['detectedFonts'] : [];
        foreach ($detectedFonts as &$font) {
            $font['installed'] = in_array($this->normalizeFontLookupKey((string) ($font['name'] ?? '')), $installed, true);
        }
        unset($font);

        $results['detectedFonts'] = $detectedFonts;
        $results['source'] = 'cache';
        $results['generatedAt'] = date('d.m.Y H:i:s', $generatedAt);

        return $results;
    }

    private function persistThemeScanCache(string $themeSlug, array $results): void
    {
        $cacheKey = self::SCAN_CACHE_OPTION_PREFIX . preg_replace('/[^a-z0-9_-]/', '', strtolower($themeSlug));
        if ($cacheKey === self::SCAN_CACHE_OPTION_PREFIX) {
            return;
        }

        $payload = json_encode([
            'generated_at' => time(),
            'results' => $results,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload)) {
            return;
        }

        $this->upsertSetting($cacheKey, $payload);
    }

    private function invalidateThemeScanCache(): void
    {
        $themeSlug = ThemeManager::instance()->getActiveThemeSlug();
        $cacheKey = self::SCAN_CACHE_OPTION_PREFIX . preg_replace('/[^a-z0-9_-]/', '', strtolower($themeSlug));
        if ($cacheKey === self::SCAN_CACHE_OPTION_PREFIX) {
            return;
        }

        $this->db->execute(
            "DELETE FROM {$this->prefix}settings WHERE option_name = ?",
            [$cacheKey]
        );
    }

    private function resolveManagedFontPath(string $storedPath): ?string
    {
        $storedPath = trim(str_replace('\\', '/', $storedPath));
        if ($storedPath === '') {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $storedPath) === 1 || str_contains($storedPath, '..')) {
            return null;
        }

        $fontsDir = $this->getManagedFontsDirectory();
        if ($fontsDir === null) {
            return null;
        }

        $normalizedPrefix = 'uploads/fonts/';
        if (!str_starts_with(strtolower($storedPath), $normalizedPrefix)) {
            return null;
        }

        $relativePath = ltrim(substr($storedPath, strlen($normalizedPrefix)), '/');
        if ($relativePath === '') {
            return null;
        }

        $candidatePath = $fontsDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $resolvedCandidate = realpath($candidatePath);
        if ($resolvedCandidate === false) {
            return is_file($candidatePath) ? $candidatePath : null;
        }

        $normalizedFontsDir = str_replace('\\', '/', $fontsDir);
        $normalizedCandidate = str_replace('\\', '/', $resolvedCandidate);

        return str_starts_with($normalizedCandidate, $normalizedFontsDir . '/') || $normalizedCandidate === $normalizedFontsDir
            ? $resolvedCandidate
            : null;
    }

    private function upsertSetting(string $key, string $value): void
    {
        $this->db->execute(
            "INSERT INTO {$this->prefix}settings (option_name, option_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            [$key, $value]
        );
    }

    private function normalizeFontLookupKey(string $value): string
    {
        $value = trim($value);

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value);
        }

        return strtolower($value);
    }

    private function sanitizeFontKey(string $value): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($value))) ?? '';
    }

    private function sanitizeErrorContext(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, 0, self::MAX_ERROR_CONTEXT_LENGTH, 'UTF-8');
        }

        return substr($value, 0, self::MAX_ERROR_CONTEXT_LENGTH);
    }
}
