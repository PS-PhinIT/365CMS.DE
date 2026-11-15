<?php
/**
 * Routen der Zahlungsanbieter (4.1.0, Bereich 5a).
 *
 * - `POST /payments/webhook/:provider` – keine Sitzung, kein CSRF (vom Router ausgenommen), nur Signatur bzw. Abruf
 *   beim Anbieter; Rate-Limit je IP, Körper höchstens 64 KB; Antwort ohne Inhalte.
 * - `GET /payments/return/:order?sig=…` – signierter Rücksprung, zeigt nur den Stand der Bestellung („wird bestätigt“,
 *   „bezahlt“, „abgebrochen“). Bezahlt wird ausschließlich über den Webhook.
 *
 * @package CMSv2\Core\Routing
 */

declare(strict_types=1);

namespace CMS\Routing;

use CMS\Router;
use CMS\Security;
use CMS\Services\Payments\PaymentService;

if (!defined('ABSPATH')) {
    exit;
}

final class PaymentRouter
{
    public const PATH_PREFIX = '/payments/';
    private const RATE_LIMIT = 300;

    public function __construct(private readonly Router $router)
    {
    }

    public function registerRoutes(): void
    {
        $this->router->addRoute('POST', PaymentService::WEBHOOK_PATH . ':provider', [$this, 'webhook']);
        $this->router->addRoute('GET', PaymentService::RETURN_PATH . ':order', [$this, 'returnPage']);
    }

    public function webhook(string $provider = ''): void
    {
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=utf-8');
        $ip = Security::getClientIp();
        if (!Security::checkDbRateLimit($ip, 'payment_webhook', self::RATE_LIMIT, 60)) {
            http_response_code(429);
            echo 'Zu viele Anfragen.';
            return;
        }
        Security::recordDbRateLimitAttempt($ip, 'payment_webhook');
        $raw = (string) file_get_contents('php://input', false, null, 0, PaymentService::MAX_WEBHOOK_BYTES + 1);
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }
        $result = PaymentService::getInstance()->handleWebhook(strtolower(preg_replace('/[^a-z]/i', '', $provider) ?? ''), $headers, $raw);
        http_response_code($result['status']);
        echo $result['message'];
    }

    public function returnPage(string $order = ''): void
    {
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex');
        $service = PaymentService::getInstance();
        $row = $service->orderForReturn(rawurldecode($order), is_string($_GET['sig'] ?? null) ? $_GET['sig'] : '');
        if ($row === null) {
            $this->router->renderError(404, 'Seite nicht gefunden', 'Dieser Link ist ungültig.');
            return;
        }
        $status = strtolower((string) $row['status']);
        $cancelled = !empty($_GET['cancelled']) && $status === 'pending';
        [$title, $text, $refresh] = match (true) {
            $status === 'paid' => ['Zahlung eingegangen', 'Vielen Dank! Ihre Zahlung ist bestätigt. Die Bestätigung erhalten Sie per E-Mail.', false],
            $cancelled => ['Zahlung abgebrochen', 'Die Zahlung wurde abgebrochen. Ihre Bestellung bleibt offen; Sie können sie erneut aufrufen oder uns kontaktieren.', false],
            $status === 'failed' => ['Zahlung fehlgeschlagen', 'Die Zahlung ist fehlgeschlagen. Bitte versuchen Sie es erneut oder wählen Sie eine andere Zahlart.', false],
            $status === 'cancelled' => ['Bestellung storniert', 'Diese Bestellung wurde storniert.', false],
            default => ['Zahlung wird bestätigt', 'Wir warten auf die Bestätigung des Zahlungsanbieters. Diese Seite aktualisiert sich automatisch.', true],
        };
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        if ($refresh) {
            \CMS\Hooks::addAction('head', static function (): void {
                echo '<meta http-equiv="refresh" content="5">' . "\n";
            }, 5);
        }
        $content = '<div class="cms-payment-return" style="max-width:640px;margin:40px auto;padding:24px;text-align:center">'
            . '<h1>' . $e($title) . '</h1>'
            . '<p>' . $e($text) . '</p>'
            . '<p class="text-muted">Bestellnummer: <strong>' . $e((string) $row['order_number']) . '</strong></p>'
            . '</div>';
        \CMS\ThemeManager::instance()->render('page', ['page' => ['id' => 0, 'title' => $title, 'content' => $content]]);
    }
}
