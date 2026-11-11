<?php
/**
 * Privater Dateispeicher für Core-Features (geschützte Downloads, Formular-Uploads,
 * Import-Staging, Exporte).
 *
 * Speicherort: CMS_PRIVATE_PATH (config/app.php, empfohlen außerhalb des Webroots), sonst CMS/private/.
 * Jedes Verzeichnis erhält automatisch `.htaccess` (Require all denied) und `index.html`;
 * Nginx muss `/private/` explizit sperren (DOC/INSTALLATION.md).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class PrivateStorageService
{
    private const HTACCESS = "# Verweigert jeden direkten HTTP-Zugriff auf private Dateien.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order Deny,Allow\n    Deny from all\n</IfModule>\n";

    private static ?self $instance = null;

    private ?string $root = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Absoluter Pfad des Speicherorts (mit abschließendem Slash), legt ihn bei Bedarf an.
     */
    public function root(): string
    {
        if ($this->root !== null) {
            return $this->root;
        }

        $configured = defined('CMS_PRIVATE_PATH') ? trim((string) CMS_PRIVATE_PATH) : '';
        $root = $configured !== '' && $this->isAbsolutePath($configured)
            ? rtrim($configured, '/\\') . DIRECTORY_SEPARATOR
            : rtrim(ABSPATH, '/\\') . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR;

        $this->ensureDirectory($root);

        return $this->root = $root;
    }

    /**
     * Absoluter Pfad eines Bereichs (z. B. "downloads", "forms", "import") mit abschließendem Slash.
     */
    public function path(string $area): string
    {
        $area = $this->normalizeArea($area);
        $path = $this->root() . $area . DIRECTORY_SEPARATOR;
        $this->ensureDirectory($path);

        return $path;
    }

    /**
     * Löst einen relativen Pfad innerhalb eines Bereichs sicher auf (realpath-Prüfung gegen
     * Traversal/Symlinks). Liefert null, wenn die Datei fehlt oder außerhalb des Bereichs liegt.
     */
    public function resolve(string $area, string $relativePath): ?string
    {
        $base = realpath($this->path($area));
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($base === false || $relativePath === '' || str_contains($relativePath, "\0")) {
            return null;
        }

        $candidate = realpath($base . DIRECTORY_SEPARATOR . $relativePath);
        if ($candidate === false || !str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $candidate;
    }

    /**
     * Erzeugt einen zufälligen, nicht erratbaren Dateinamen mit (bereinigter) Endung.
     */
    public function randomFilename(string $extension = ''): string
    {
        $extension = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $extension));

        return bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . substr($extension, 0, 10) : '');
    }

    public function isOutsideWebroot(): bool
    {
        $root = realpath($this->root());
        $webroot = realpath(ABSPATH);

        return $root !== false && $webroot !== false && !str_starts_with($root . DIRECTORY_SEPARATOR, rtrim($webroot, '/\\') . DIRECTORY_SEPARATOR);
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
            throw new \RuntimeException('Privates Verzeichnis konnte nicht angelegt werden (CMS_PRIVATE_PATH prüfen).');
        }

        if (!is_file($path . '.htaccess')) {
            @file_put_contents($path . '.htaccess', self::HTACCESS, LOCK_EX);
        }
        if (!is_file($path . 'index.html')) {
            @file_put_contents($path . 'index.html', '', LOCK_EX);
        }
    }

    private function normalizeArea(string $area): string
    {
        $area = strtolower(trim($area));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,40}(?:\/[a-z0-9][a-z0-9_-]{0,40}){0,2}$/', $area) !== 1) {
            throw new \InvalidArgumentException('Ungültiger privater Speicherbereich.');
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $area);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
