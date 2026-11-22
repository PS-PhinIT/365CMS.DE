<?php
/**
 * Community – Auslieferung von Anhängen `/forum/anhang/{id}` und `/forum/anhang/{id}/vorschau` (PR #4).
 *
 * Der Dienst `AttachmentService::resolve()` entscheidet über Sichtbarkeit; alles, was der Betrachter nicht sehen darf, ist `not_found`
 * (404 ohne Unterschied zu „gibt es nicht“). Die Dateien liegen außerhalb des Webroots und werden hier mit festen Kopfzeilen gestreamt:
 * `Content-Type` nur aus der Allowlist des Dienstes, `nosniff`, ein CSP ohne jede Fähigkeit, `Cache-Control` immer `private`
 * (`no-store`, wenn Gäste den Anhang nicht sehen dürfen), ETag aus dem SHA-256, Bilder inline, alles andere als Download.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Http;

use CMS\Auth;
use CMS\Services\Community\Service\AttachmentService;
use CMS\Services\Community\Support\Urls;

if (!defined('ABSPATH')) {
    exit;
}

final class AttachmentController
{
    private static ?self $instance = null;

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
            $router->addRoute('GET', $p['attachment_preview'], fn (string $id) => $this->serve((int) $id, true));
            $router->addRoute('GET', $p['attachment'], fn (string $id) => $this->serve((int) $id, false));
        }
    }

    private function serve(int $id, bool $preview): void
    {
        $viewer = null;
        try {
            $uid = (int) (Auth::instance()->currentUser()->id ?? 0);
            $viewer = $uid > 0 ? $uid : null;
        } catch (\Throwable) {
        }
        $file = AttachmentService::instance()->resolve($viewer, $id, $preview);
        if (empty($file['success'])) {
            PublicController::instance()->showNotFound();

            return;
        }
        $this->send($file, $id);
    }

    /**
     * @param array<string, mixed> $file Ergebnis von `AttachmentService::resolve()`
     */
    private function send(array $file, int $id): never
    {
        $path = (string) $file['path'];
        $etag = '"' . (string) $file['sha256'] . '"';
        $match = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header("Content-Security-Policy: default-src 'none'; sandbox");
            header('Referrer-Policy: no-referrer');
            header('Cache-Control: ' . (!empty($file['public']) ? 'private, max-age=3600' : 'private, no-store'));
            header('ETag: ' . $etag);
        }
        if ($match !== '' && !empty($file['public']) && $match === $etag) {
            http_response_code(304);
            exit;
        }
        if (!headers_sent()) {
            header('Content-Type: ' . (string) $file['mime']);
            header('Content-Length: ' . (int) $file['size']);
            header('Content-Disposition: ' . self::disposition(!empty($file['inline']), (string) $file['filename']));
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            // Ein Download zählt einmal je Abruf; Bilder in Beiträgen zählen nicht mit.
            if (empty($file['inline'])) {
                AttachmentService::instance()->countDownload($id);
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            readfile($path);
        }
        exit;
    }

    /**
     * `Content-Disposition` mit sicherem Dateinamen: ASCII-Rückfall und `filename*` (RFC 5987); Steuerzeichen, Anführungszeichen,
     * Pfadtrenner und Kopfzeilen-Umbrüche entfallen.
     */
    public static function disposition(bool $inline, string $name): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|;]+/u', '_', $name);
        $clean = trim($clean, " ._");
        if ($clean === '') {
            $clean = 'anhang';
        }
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean));
        if ($ascii === '' || $ascii === '_') {
            $ascii = 'anhang';
        }

        return ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($clean);
    }
}
