<?php
/**
 * Mollie (4.1.0, Bereich 5a): Payments API (Hosted Payment Page) und Subscriptions API, ohne SDK.
 *
 * - Checkout: `POST /v2/payments` (JSON) mit Betrag als Zeichenkette (`"12.00"`), `redirectUrl`, `cancelUrl`,
 *   `webhookUrl`, `metadata.order_id`. PayPal läuft über Mollie (`method=paypal`). Für automatische Verlängerung wird
 *   zuerst ein Kunde angelegt und die erste Zahlung mit `sequenceType=first` ausgelöst (Mandat).
 * - Webhook: Mollie sendet nur `id=tr_…` und signiert nicht. Echt ist, was der Abruf `GET /v2/payments/{id}` mit dem
 *   eigenen API-Key liefert – der Körper selbst wird nie als Wahrheit genommen.
 * - Ereignis-ID: `{payment-id}:{status}` (Mollie ruft bei jedem Statuswechsel erneut auf).
 * - Nach der ersten bezahlten Zahlung: `POST /v2/customers/{id}/subscriptions` (Start = nächste Periode); Folgezahlungen
 *   tragen `subscriptionId` und werden als Verlängerung verbucht.
 *
 * @package CMSv2\Core\Services\Payments
 */

declare(strict_types=1);

namespace CMS\Services\Payments;

if (!defined('ABSPATH')) {
    exit;
}

final class MollieGateway extends AbstractGateway
{
    public const API = 'https://api.mollie.com/v2/';
    private const PAYMENT_ID = '/^tr_[A-Za-z0-9]{4,40}$/';
    private const CUSTOMER_ID = '/^cst_[A-Za-z0-9]{4,40}$/';

    public function id(): string
    {
        return 'mollie';
    }

    public function label(): string
    {
        return 'Mollie';
    }

    public function isConfigured(): bool
    {
        return $this->settings->enabled('mollie') && $this->settings->missing('mollie') === [];
    }

    public static function formatAmount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function parseAmount(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^\d+\.\d{2}$/', $value) === 1 ? (int) round(((float) $value) * 100) : null;
    }

    public static function interval(string $interval): string
    {
        return $interval === 'year' ? '12 months' : '1 month';
    }

    public function createCheckout(array $order, array $context): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mollie ist nicht eingerichtet.'];
        }
        $orderId = (int) ($order['id'] ?? 0);
        $recurring = in_array((string) ($context['interval'] ?? ''), ['month', 'year'], true);
        $body = [
            'amount' => ['currency' => self::currency($order), 'value' => self::formatAmount(self::amountCents($order))],
            'description' => mb_substr((string) ($context['description'] ?? 'Bestellung'), 0, 255),
            'redirectUrl' => (string) ($context['success_url'] ?? ''),
            'cancelUrl' => (string) ($context['cancel_url'] ?? ''),
            'webhookUrl' => (string) ($context['webhook_url'] ?? ''),
            'metadata' => ['order_id' => $orderId, 'order_number' => (string) ($order['order_number'] ?? '')],
        ];
        if (($context['method'] ?? '') === 'paypal') {
            $body['method'] = 'paypal';
        }
        $customerId = '';
        if ($recurring) {
            $customer = $this->call('POST', self::API . 'customers', (string) json_encode([
                'name' => mb_substr((string) ($order['customer_name'] ?? ''), 0, 255),
                'email' => (string) ($order['customer_email'] ?? $order['email'] ?? ''),
                'metadata' => ['order_id' => $orderId],
            ]), $this->headers());
            $customerId = (string) ($customer['data']['id'] ?? '');
            if ($customer['error'] !== '' || preg_match(self::CUSTOMER_ID, $customerId) !== 1) {
                return ['success' => false, 'error' => $customer['error'] !== '' ? $customer['error'] : 'Mollie lieferte keinen Kunden.'];
            }
            $body['customerId'] = $customerId;
            $body['sequenceType'] = 'first';
        }
        $result = $this->call('POST', self::API . 'payments', (string) json_encode($body), $this->headers());
        $id = (string) ($result['data']['id'] ?? '');
        $url = (string) ($result['data']['_links']['checkout']['href'] ?? '');
        if ($result['error'] !== '' || preg_match(self::PAYMENT_ID, $id) !== 1 || !str_starts_with($url, 'https://')) {
            return ['success' => false, 'error' => $result['error'] !== '' ? $result['error'] : 'Mollie lieferte keine Checkout-URL.'];
        }

        return ['success' => true, 'checkout_id' => $id, 'redirect_url' => $url, 'customer_id' => $customerId];
    }

    public function parseWebhook(array $headers, string $rawBody): array
    {
        parse_str($rawBody, $form);
        $id = is_string($form['id'] ?? null) ? $form['id'] : '';
        if (preg_match(self::PAYMENT_ID, $id) !== 1) {
            return ['valid' => false, 'error' => 'Unbekannte Zahlungs-ID.'];
        }
        $result = $this->call('GET', self::API . 'payments/' . rawurlencode($id), '', $this->headers());
        if ($result['error'] !== '' || ($result['data']['id'] ?? '') !== $id) {
            return ['valid' => false, 'error' => $result['error'] !== '' ? $result['error'] : 'Zahlung beim Anbieter nicht gefunden.'];
        }

        return ['valid' => true, 'events' => [self::mapPayment($result['data'])]];
    }

    /**
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    public static function mapPayment(array $payment): array
    {
        $status = (string) ($payment['status'] ?? '');
        $subscription = is_string($payment['subscriptionId'] ?? null) ? $payment['subscriptionId'] : '';
        $kind = match ($status) {
            'paid' => $subscription !== '' ? 'renewed' : 'paid',
            'failed' => $subscription !== '' ? 'renewal_failed' : 'failed',
            'canceled', 'expired' => $subscription !== '' ? 'ignored' : 'cancelled',
            default => 'ignored',
        };

        return self::event([
            'event_id' => (string) $payment['id'] . ':' . $status,
            'type' => 'payment.' . ($status !== '' ? $status : 'unknown'),
            'kind' => $kind,
            'order_id' => (int) ($payment['metadata']['order_id'] ?? 0),
            'checkout_id' => (string) $payment['id'],
            'subscription_id' => $subscription,
            'customer_id' => is_string($payment['customerId'] ?? null) ? $payment['customerId'] : '',
            'amount_cents' => self::parseAmount($payment['amount']['value'] ?? null),
            'currency' => strtoupper((string) ($payment['amount']['currency'] ?? '')),
            'payment_ref' => (string) $payment['id'],
        ]);
    }

    public function activateRecurring(array $event, array $order, array $context): array
    {
        $customerId = (string) ($event['customer_id'] ?? '');
        if (preg_match(self::CUSTOMER_ID, $customerId) !== 1) {
            return ['success' => false, 'error' => 'Kein Mollie-Kunde für die Verlängerung.'];
        }
        $result = $this->call('POST', self::API . 'customers/' . rawurlencode($customerId) . '/subscriptions', (string) json_encode([
            'amount' => ['currency' => self::currency($order), 'value' => self::formatAmount(self::amountCents($order))],
            'interval' => self::interval((string) ($context['interval'] ?? 'month')),
            'startDate' => (string) ($context['start_date'] ?? ''),
            'description' => mb_substr((string) ($context['description'] ?? 'Abo') . ' (' . (string) ($order['order_number'] ?? '') . ')', 0, 255),
            'webhookUrl' => (string) ($context['webhook_url'] ?? ''),
            'metadata' => ['order_id' => (int) ($order['id'] ?? 0)],
        ]), $this->headers());
        $id = (string) ($result['data']['id'] ?? '');
        if ($result['error'] !== '' || !str_starts_with($id, 'sub_')) {
            return ['success' => false, 'error' => $result['error'] !== '' ? $result['error'] : 'Mollie lieferte kein Abo.'];
        }

        return ['success' => true, 'subscription_id' => $id];
    }

    protected function errorMessage(array $data): string
    {
        return is_string($data['detail'] ?? null) ? 'Mollie: ' . $data['detail'] : '';
    }

    /**
     * @return list<string>
     */
    private function headers(): array
    {
        return ['Authorization: Bearer ' . $this->settings->mollieKey(), 'Content-Type: application/json'];
    }
}
