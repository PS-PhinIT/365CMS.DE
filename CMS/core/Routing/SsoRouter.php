<?php
/**
 * Routen der Entra-ID-Anmeldung (4.1.0, Bereich 1c).
 *
 * - `GET /cms-login/sso/entra/start?redirect=…` – startet den Fluss (state, nonce, PKCE in der Sitzung) und leitet zu
 *   Microsoft weiter. Das Rücksprungziel durchläuft dieselbe Prüfung wie der Login-`redirect` (nur lokal).
 * - `GET /cms-login/sso/entra/callback` – Rücksprung. Das Sitzungs-Cookie ist `SameSite=Strict` und wird bei der
 *   Weiterleitung von login.microsoftonline.com nicht mitgesendet. Der erste Aufruf liefert deshalb nur eine
 *   Zwischenseite, die per `<meta http-equiv="refresh">` dieselbe URL mit `hop=1` aufruft – diese Navigation geht
 *   von der eigenen Seite aus, das Cookie ist dabei. Erst dann wird `state` geprüft und der Code eingelöst. Der Code
 *   bleibt einmalig und an den PKCE-Verifier der Sitzung gebunden.
 *
 * Verknüpfen und Trennen laufen als Aktionen `sso_link`/`sso_unlink` auf `/member/security` (CSRF + Re-Auth).
 *
 * @package CMSv2\Core\Routing
 */

declare(strict_types=1);

namespace CMS\Routing;

use CMS\Auth;
use CMS\Auth\SSO\EntraIdProvider;
use CMS\Auth\SSO\EntraSettings;
use CMS\Auth\SSO\EntraSsoService;
use CMS\Auth\SSO\OidcFlowState;
use CMS\Router;
use CMS\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class SsoRouter
{
    public const PATH_PREFIX = '/cms-login/sso/';

    public function __construct(private readonly Router $router)
    {
    }

    public function registerRoutes(): void
    {
        $this->router->addRoute('GET', EntraSettings::START_PATH, [$this, 'start']);
        $this->router->addRoute('GET', EntraSettings::CALLBACK_PATH, [$this, 'callback']);
    }

    public function start(): void
    {
        $this->sendNoStoreHeaders();
        $service = EntraSsoService::getInstance();
        $publicRouter = new PublicRouter($this->router);
        $target = $publicRouter->safeRedirectTarget($_GET['redirect'] ?? null);

        if (Auth::instance()->isLoggedIn()) {
            $this->router->redirect($publicRouter->successfulLoginTarget($target), 303);
            return;
        }
        if (!$service->isAvailable()) {
            $_SESSION['error'] = EntraSsoService::MESSAGES['unavailable'];
            $this->router->redirect($this->loginPath(), 303);
            return;
        }

        try {
            $url = $service->startUrl(OidcFlowState::PURPOSE_LOGIN, $target);
        } catch (\Throwable) {
            $_SESSION['error'] = EntraSsoService::MESSAGES['generic'];
            $this->router->redirect($this->loginPath(), 303);
            return;
        }

        self::redirectToProvider($url);
    }

    public function callback(): void
    {
        $this->sendNoStoreHeaders();
        if ((string) ($_GET['hop'] ?? '') !== '1') {
            $this->renderHop();
            return;
        }

        $query = [];
        foreach (['code', 'state', 'error'] as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key])) {
                $query[$key] = $_GET[$key];
            }
        }

        $result = EntraSsoService::getInstance()->handleCallback($query);
        $publicRouter = new PublicRouter($this->router);

        if ($result['type'] === 'login') {
            $this->router->redirect($publicRouter->successfulLoginTarget($publicRouter->safeRedirectTarget($result['target'])), 303);
            return;
        }
        if ($result['type'] === 'mfa') {
            $this->router->redirect('/mfa-challenge', 303);
            return;
        }
        if ($result['type'] === 'linked') {
            $this->memberAlert((string) ($result['alert'] ?? 'success'), $result['message']);
            $this->router->redirect('/member/security', 303);
            return;
        }

        $status = (int) ($result['status'] ?? 400);
        // Ungültiger/fehlender state (400) und Rate-Limit (429) als Fehlerseite, alles andere zurück zum Formular.
        if (in_array((string) ($result['reason'] ?? ''), ['state', 'rate_limited'], true)) {
            $this->router->renderError($status, 'Anmeldung mit Microsoft', $result['message']);
            return;
        }
        if ($result['purpose'] === 'link') {
            $this->memberAlert('danger', $result['message']);
            $this->router->redirect('/member/security', 303);
            return;
        }

        $_SESSION['error'] = $result['message'];
        $this->router->redirect($this->loginPath(), 303);
    }

    /**
     * Weiterleitung zu Microsoft – nur auf den festen Anmeldehost.
     */
    public static function redirectToProvider(string $url): never
    {
        if (!str_starts_with($url, 'https://' . EntraIdProvider::HOST . '/') || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            http_response_code(500);
            exit;
        }
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('Location: ' . $url, true, 303);
        }
        exit;
    }

    /**
     * Rücksprung-Parameter für die Zwischenseite (nur bekannte Schlüssel, Zeichen geprüft).
     *
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    public static function hopParameters(array $query): array
    {
        $patterns = [
            'code' => '/^[A-Za-z0-9._~\-]{1,4096}$/',
            'state' => '/^[A-Za-z0-9_-]{1,128}$/',
            'error' => '/^[a-z_]{1,60}$/',
        ];
        $out = [];
        foreach ($patterns as $key => $pattern) {
            $value = $query[$key] ?? null;
            if (is_string($value) && preg_match($pattern, $value) === 1) {
                $out[$key] = $value;
            }
        }
        $out['hop'] = '1';

        return $out;
    }

    private function renderHop(): void
    {
        // Relativ zur Installation (SITE_URL_PATH), damit die Navigation auf derselben Site bleibt.
        $basePath = defined('SITE_URL_PATH') ? rtrim((string) SITE_URL_PATH, '/') : '';
        $target = $basePath . EntraSettings::CALLBACK_PATH . '?' . http_build_query(self::hopParameters($_GET), '', '&', PHP_QUERY_RFC3986);
        $siteName = htmlspecialchars(defined('SITE_NAME') ? (string) SITE_NAME : '365CMS', ENT_QUOTES, 'UTF-8');
        $href = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
        $css = htmlspecialchars(function_exists('cms_asset_url') ? (string) \cms_asset_url('css/cms-mfa.css') : '/assets/css/cms-mfa.css', ENT_QUOTES, 'UTF-8');

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow');
        }
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<meta name="referrer" content="no-referrer">'
            . '<meta http-equiv="refresh" content="0;url=' . $href . '">'
            . '<title>Anmeldung wird abgeschlossen – ' . $siteName . '</title>'
            . '<link rel="stylesheet" href="' . $css . '">'
            . '</head><body class="cms-mfa-body"><div class="cms-mfa-card">'
            . '<h2 class="cms-mfa-title">Anmeldung mit Microsoft</h2>'
            . '<p class="cms-mfa-lead">Die Anmeldung wird abgeschlossen …</p>'
            . '<p class="cms-mfa-back"><a href="' . $href . '">Weiter</a></p>'
            . '</div></body></html>';
    }

    private function sendNoStoreHeaders(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('Referrer-Policy: no-referrer');
        }
    }

    private function memberAlert(string $type, string $message): void
    {
        $_SESSION['member_alert'] = ['type' => $type, 'message' => $message, 'payload' => []];
    }

    private function loginPath(): string
    {
        try {
            return Services\CmsAuthPageService::getInstance()->getPublicPath('login', $this->router->getRequestLocale());
        } catch (\Throwable) {
            return '/cms-login';
        }
    }
}
