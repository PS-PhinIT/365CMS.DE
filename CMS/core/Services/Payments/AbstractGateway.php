<?php
/**
 * Gemeinsame Teile der Gateways (4.1.0, Bereich 5a): austauschbarer HTTP-Transport (Tests), JSON-Aufrufe, Fehlertexte.
 *
 * Transport: `fn(string $method, string $url, string $body, list<string> $headers): array{status: int, body: string, error?: string}`.
 * Standard ist `CMS\Http\Client` (SSRF-Schutz, Zeitlimits, Größenlimit 1 MB).
 *
 * @package CMSv2\Core\Services\Payments
 */

declare(strict_types=1);

namespace CMS\Services\Payments;

if (!defined('ABSPATH')) {
    exit;
}

abstract class AbstractGateway implements PaymentGatewayInterface
{
    protected const MAX_RESPONSE_BYTES = 1048576;
    protected const USER_AGENT = '365CMS-Payments/1.0';

    /** @var \Closure(string, string, string, list<string>): array{status: int, body: string, error?: string} */
    protected \Closure $transport;

    public function __construct(protected readonly PaymentSettings $settings, ?\Closure $transport = null)
    {
        $this->transport = $transport ?? self::defaultTransport();
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, data: array<string, mixed>, error: string}
     */
    protected function call(string $method, string $url, string $body, array $headers): array
    {
        try {
            $response = ($this->transport)($method, $url, $body, $headers);
        } catch (\Throwable $e) {
            return ['status' => 0, 'data' => [], 'error' => 'Anbieter nicht erreichbar.'];
        }
        $status = (int) ($response['status'] ?? 0);
        $data = json_decode((string) ($response['body'] ?? ''), true);
        $data = is_array($data) ? $data : [];
        $error = '';
        if ($status < 200 || $status >= 300) {
            $error = $this->errorMessage($data) ?: ($status > 0 ? 'Anbieter antwortete mit HTTP ' . $status . '.' : (string) ($response['error'] ?? 'Anbieter nicht erreichbar.'));
        }

        return ['status' => $status, 'data' => $data, 'error' => mb_substr($error, 0, 300)];
    }

    /**
     * @param array<string, mixed> $data
     */
    abstract protected function errorMessage(array $data): string;

    /**
     * Betrag der Bestellung in Cent (immer aus der eigenen Bestellung, nie aus dem Request).
     *
     * @param array<string, mixed> $order
     */
    public static function amountCents(array $order): int
    {
        $total = (float) ($order['total_amount'] ?? 0);
        if ($total <= 0) {
            $total = (float) ($order['amount'] ?? 0);
        }

        return (int) round($total * 100);
    }

    /**
     * @param array<string, mixed> $order
     */
    public static function currency(array $order): string
    {
        $currency = strtoupper(trim((string) ($order['currency'] ?? 'EUR')));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'EUR';
    }

    /**
     * @return array<string, mixed>
     */
    protected static function event(array $values): array
    {
        return $values + [
            'event_id' => '', 'type' => '', 'kind' => 'ignored', 'order_id' => 0, 'checkout_id' => '',
            'subscription_id' => '', 'customer_id' => '', 'amount_cents' => null, 'currency' => '', 'payment_ref' => '',
            'period_end' => null,
        ];
    }

    private static function defaultTransport(): \Closure
    {
        return static function (string $method, string $url, string $body, array $headers): array {
            $client = \CMS\Http\Client::getInstance();
            $options = [
                'headers' => $headers,
                'timeout' => 20,
                'connectTimeout' => 10,
                'maxBytes' => self::MAX_RESPONSE_BYTES,
                'userAgent' => self::USER_AGENT,
            ];
            $result = match ($method) {
                'GET' => $client->get($url, $options),
                'DELETE' => $client->delete($url, $options),
                default => $client->post($url, $body, $options),
            };

            return [
                'status' => (int) ($result['status'] ?? 0),
                'body' => (string) ($result['body'] ?? ''),
                'error' => (string) ($result['error'] ?? ''),
            ];
        };
    }
}
