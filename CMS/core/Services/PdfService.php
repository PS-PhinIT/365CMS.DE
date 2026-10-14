<?php
/**
 * PDF Service – Dompdf-basierte PDF-Generierung
 *
 * Erstellt PDF-Dokumente aus HTML-Content. Nutzt den bundled
 * Dompdf-Vendor unter CMS/vendor/dompdf/ und ist damit
 * unabhängig vom ASSETS/-Verzeichnis (Shared-Hosting-kompatibel).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\VendorRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class PdfService
{
    private static ?self $instance = null;

    /** Dompdf-Autoloader geladen? */
    private bool $loaded = false;

    /** Standard-Papierformat */
    private string $paperSize = 'A4';

    /** Standard-Orientierung */
    private string $orientation = 'portrait';

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->loadDompdf();
    }

    /**
     * Prüft ob Dompdf verfügbar ist.
     */
    public function isAvailable(): bool
    {
        return $this->loaded && class_exists(\Dompdf\Dompdf::class);
    }

    /**
     * Erzeugt ein PDF aus einem HTML-String und sendet es als Download.
     *
     * @param string $html     Vollständiges HTML (inkl. DOCTYPE)
     * @param string $filename Dateiname für den Download (z. B. "rechnung.pdf")
     * @param bool   $inline   true = im Browser anzeigen, false = Download erzwingen
     */
    public function streamFromHtml(string $html, string $filename = 'document.pdf', bool $inline = false): void
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Dompdf ist nicht verfügbar.');
        }

        $html = $this->localizeSameOriginImages($this->sanitizeHtml($html));

        $dompdf = $this->createInstance();
        $dompdf->loadHtml($html);
        $dompdf->setPaper($this->paperSize, $this->orientation);
        $dompdf->render();

        $disposition = $inline ? 'inline' : 'attachment';
        $dompdf->stream($filename, ['Attachment' => !$inline]);
    }

    /**
     * Erzeugt ein PDF aus HTML und gibt den Binär-String zurück.
     *
     * @param string $html Vollständiges HTML (inkl. DOCTYPE)
     * @return string PDF-Binärdaten
     */
    public function generateFromHtml(string $html): string
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Dompdf ist nicht verfügbar.');
        }

        $html = $this->localizeSameOriginImages($this->sanitizeHtml($html));

        $dompdf = $this->createInstance();
        $dompdf->loadHtml($html);
        $dompdf->setPaper($this->paperSize, $this->orientation);
        $dompdf->render();

        return $dompdf->output() ?: '';
    }

    /**
     * Erzeugt ein PDF und speichert es als Datei.
     *
     * @param string $html     HTML-Inhalt
     * @param string $filePath Absoluter Zielpfad
     * @return bool Erfolg
     */
    public function saveFromHtml(string $html, string $filePath): bool
    {
        $pdf = $this->generateFromHtml($html);

        $dir = dirname($filePath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            return false;
        }

        return file_put_contents($filePath, $pdf) !== false;
    }

    /**
     * Setzt ein einfaches HTML-Template mit CSS zusammen.
     *
     * @param string $title   Dokumenttitel
     * @param string $body    HTML-Body-Content
     * @param string $css     Optionales Inline-CSS
     * @return string Vollständiges HTML-Dokument
     */
    public function wrapTemplate(string $title, string $body, string $css = ''): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES);
        $defaultCss = '
            body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #1e293b; line-height: 1.6; margin: 2cm; }
            h1 { font-size: 18pt; color: #1e3a5f; margin-bottom: 10pt; }
            h2 { font-size: 14pt; color: #1e3a5f; margin-bottom: 8pt; }
            h3 { font-size: 12pt; margin-bottom: 6pt; }
            table { width: 100%; border-collapse: collapse; margin: 10pt 0; }
            th, td { border: 1px solid #e2e8f0; padding: 6pt 8pt; text-align: left; font-size: 10pt; }
            th { background: #f1f5f9; font-weight: 600; }
            .text-muted { color: #64748b; }
            .text-right { text-align: right; }
            .footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 8pt; color: #94a3b8; }
        ';

        return '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>' . $safeTitle . '</title>
    <style>' . $defaultCss . $css . '</style>
</head>
<body>
' . $body . '
<div class="footer">' . $safeTitle . ' – Erstellt am ' . date('d.m.Y H:i') . '</div>
</body>
</html>';
    }

    /**
     * Papierformat setzen.
     *
     * @param string $size        z. B. 'A4', 'A3', 'letter'
     * @param string $orientation 'portrait' oder 'landscape'
     */
    public function setPaper(string $size = 'A4', string $orientation = 'portrait'): self
    {
        $this->paperSize   = $size;
        $this->orientation = $orientation;
        return $this;
    }

    // ── Private ────────────────────────────────────────────────────────────

    /**
     * Dompdf-Autoloader laden.
     */
    private function loadDompdf(): void
    {
        $this->loaded = VendorRegistry::instance()->loadPackage('dompdf');
    }

    /**
     * Erstellt eine konfigurierte Dompdf-Instanz.
     */
    private function createInstance(): \Dompdf\Dompdf
    {
        $options = new \Dompdf\Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot(ABSPATH);

        // Font-Cache im per .htaccess gesperrten cache/-Verzeichnis (data/ ist öffentlich erreichbar)
        $fontCache = ABSPATH . 'cache/dompdf';
        if (!is_dir($fontCache)) {
            @mkdir($fontCache, 0750, true);
        }
        if (is_dir($fontCache) && is_writable($fontCache)) {
            $options->setFontCache($fontCache);
        }
        $options->setTempDir(sys_get_temp_dir());

        return new \Dompdf\Dompdf($options);
    }

    /**
     * Dompdf läuft mit isRemoteEnabled=false (kein SSRF). Bilder der eigenen
     * Installation (SITE_URL/…, /uploads/…, /assets/…) werden deshalb auf lokale
     * Dateipfade innerhalb von uploads/ bzw. assets/ umgeschrieben; alles andere
     * bleibt unangetastet und erscheint wie bisher als Platzhalter.
     */
    private function localizeSameOriginImages(string $html): string
    {
        $root = realpath(ABSPATH);
        if ($root === false) {
            return $html;
        }

        $siteHost = strtolower((string) parse_url(defined('SITE_URL') ? (string) SITE_URL : '', PHP_URL_HOST));
        $allowedDirs = [];
        foreach (['uploads', 'assets'] as $dir) {
            $real = realpath($root . DIRECTORY_SEPARATOR . $dir);
            if ($real !== false) {
                $allowedDirs[] = $real . DIRECTORY_SEPARATOR;
            }
        }

        $html = preg_replace('/\ssrcset\s*=\s*(["\']).*?\1/is', '', $html) ?? $html;

        return preg_replace_callback(
            '/(<img\b[^>]*?\ssrc\s*=\s*)(["\'])(.*?)\2/is',
            function (array $match) use ($siteHost, $allowedDirs): string {
                $src = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $localPath = $this->resolveLocalImagePath($src, $siteHost, $allowedDirs);

                return $localPath === null
                    ? $match[0]
                    : $match[1] . $match[2] . htmlspecialchars($localPath, ENT_QUOTES, 'UTF-8') . $match[2];
            },
            $html
        ) ?? $html;
    }

    /**
     * @param string[] $allowedDirs
     */
    private function resolveLocalImagePath(string $src, string $siteHost, array $allowedDirs): ?string
    {
        $src = trim($src);
        if ($src === '' || str_starts_with($src, 'data:') || $allowedDirs === []) {
            return null;
        }

        $parts = parse_url($src);
        if ($parts === false) {
            return null;
        }

        if (isset($parts['host'])) {
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if ($siteHost === '' || strtolower((string) $parts['host']) !== $siteHost || !in_array($scheme, ['http', 'https', ''], true)) {
                return null;
            }
        } elseif (isset($parts['scheme'])) {
            return null;
        }

        $path = rawurldecode((string) ($parts['path'] ?? ''));
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $sitePath = rtrim((string) parse_url(defined('SITE_URL') ? (string) SITE_URL : '', PHP_URL_PATH), '/');
        if ($sitePath !== '' && str_starts_with($path, $sitePath . '/')) {
            $path = substr($path, strlen($sitePath));
        }

        $candidate = realpath(ABSPATH . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR));
        if ($candidate === false || !is_file($candidate)) {
            return null;
        }

        if (!preg_match('/\.(?:png|jpe?g|gif|webp|svg|bmp)$/i', $candidate)) {
            return null;
        }

        // Dompdf braucht ext-gd für PNG/GIF/WebP/BMP und bricht sonst das gesamte
        // Rendering ab; ohne GD bleiben diese Bilder beim bisherigen Platzhalter.
        if (!extension_loaded('gd') && !preg_match('/\.(?:jpe?g|svg)$/i', $candidate)) {
            return null;
        }

        foreach ($allowedDirs as $dir) {
            if (str_starts_with($candidate, $dir)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * HTML-Sanitierung über PurifierService, falls vorhanden.
     */
    private function sanitizeHtml(string $html): string
    {
        if (class_exists(PurifierService::class)) {
            try {
                $purifier = PurifierService::getInstance();
                // Nur Body-Content sanitieren, nicht das gesamte Dokument
                if (preg_match('/<body[^>]*>(.*)<\/body>/si', $html, $matches)) {
                    $cleanBody = $purifier->purify($matches[1], 'hub');
                    return str_replace($matches[1], $cleanBody, $html);
                }
            } catch (\Throwable) {
                // Fallthrough — HTML unverändert nutzen
            }
        }

        return $html;
    }
}
