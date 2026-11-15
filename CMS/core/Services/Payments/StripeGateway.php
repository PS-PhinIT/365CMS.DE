<?php
/**
 * Stripe (4.1.0, Bereich 5a): Checkout Session und signierte Webhooks, ohne SDK.
 *
 * - Checkout: `POST /v1/checkout/sessions` (form-encoded), `mode=payment` bzw. `mode=subscription` mit
 *   `price_data[recurring][interval]`, wenn Abos automatisch verlängert werden. Betrag und Währung aus der Bestellung,
 *   `client_reference_id` und `metadata[order_id]` für die Zuordnung. Kartendaten sieht das CMS nie (SAQ A).
 * - Webhook: Kopfzeile `Stripe-Signature: t=…,v1=…[,v1=…]`, HMAC-SHA256 über `t + "." + Rohkörper` mit dem
 *   Webhook-Geheimnis, Vergleich zeitkonstant, mehrere `v1` (Schlüsselwechsel), Zeittoleranz 300 s.
 * - Ereignisse: `checkout.session.completed` (nur `payment_status=paid`), `checkout.session.async_payment_succeeded`,
 *   `…async_payment_failed`, `checkout.session.expired`, `invoice.paid` (nur `billing_reason=subscription_cycle` →
 *   Verlängerung mit Periodenende), `invoice.payment_failed` (Abo), `customer.subscription.deleted`.
 *
 * @package CMSv2\Core\Services\Payments
 */

declare(strict_types=1);

namespace CMS\Services\Payments;

if (!defined('ABSPATH')) {
    exit;
}

final class StripeGateway extends AbstractGateway
{
    public const API = 'https://api.stripe.com/v1/';
    public const TOLERANCE = 300;

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(PaymentSettings $settings, ?\Closure $transport = null, ?\Closure $clock = null)
    {
        parent::__construct($settings, $transport);
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function id(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Stripe';
    }

    public function isConfigured(): bool
    {
        return $this->settings->enabled('stripe') && $this->settings->missing('stripe') === [];
    }

    // ── Signatur ────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Prüft die Kopfzeile `Stripe-Signature` gegen den Rohkörper.
     *
     * @return array{valid: bool, error?: string}
     */
    public static function verifySignature(string $header, string $payload, string $secret, int $now, int $tolerance = self::TOLERANCE): array
    {
        if ($secret === '' || $header === '') {
            return ['valid' => false, 'error' => 'Signatur fehlt.'];
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || $signatures === []) {
            return ['valid' => false, 'error' => 'Signatur unvollständig.'];
        }
        if (abs($now - $timestamp) > $tolerance) {
            return ['valid' => false, 'error' => 'Signatur zu alt.'];
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return ['valid' => true];
            }
        }

        return ['valid' => false, 'error' => 'Signatur ungültig.'];
    }

    // ── Checkout ────────────────────────────────────────────────────────────────────────────────────────────────

    public function createCheckout(array $order, array $context): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Stripe ist nicht eingerichtet.'];
        }
        $orderId = (int) ($order['id'] ?? 0);
        $interval = (string) ($context['interval'] ?? '');
        $recurring = in_array($interval, ['month', 'year'], true);
        $params = [
            'mode' => $recurring ? 'subscription' : 'payment',
            'success_url' => (string) ($context['success_url'] ?? ''),
            'cancel_url' => (string) ($context['cancel_url'] ?? ''),
            'client_reference_id' => (string) $orderId,
            'metadata[order_id]' => (string) $orderId,
            'metadata[order_number]' => (string) ($order['order_number'] ?? ''),
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => strtolower(self::currency($order)),
            'line_items[0][price_data][unit_amount]' => (string) self::amountCents($order),
            'line_items[0][price_data][product_data][name]' => mb_substr((string) ($context['description'] ?? 'Bestellung'), 0, 250),
        ];
        $email = (string) ($order['customer_email'] ?? $order['email'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $params['customer_email'] = $email;
        }
        if ($recurring) {
            $params['line_items[0][price_data][recurring][interval]'] = $interval;
            $params['subscription_data[metadata][order_id]'] = (string) $orderId;
        } else {
            $params['payment_intent_data[metadata][order_id]'] = (string) $orderId;
        }
        $result = $this->call('POST', self::API . 'checkout/sessions', http_build_query($params, '', '&', PHP_QUERY_RFC3986), $this->headers());
        $id = (string) ($result['data']['id'] ?? '');
        $url = (string) ($result['data']['url'] ?? '');
        if ($result['error'] !== '' || $id === '' || !str_starts_with($url, 'https://')) {
            return ['success' => false, 'error' => $result['error'] !== '' ? $result['error'] : 'Stripe lieferte keine Checkout-URL.'];
        }

        return ['success' => true, 'checkout_id' => $id, 'redirect_url' => $url];
    }

    public function activateRecurring(array $event, array $order, array $context): array
    {
        // Stripe legt das Abo im Checkout selbst an; die ID kommt mit dem Ereignis.
        return ['success' => true, 'subscription_id' => (string) ($event['subscription_id'] ?? '')];
    }

    // ── Webhook ─────────────────────────────────────────────────────────────────────────────────────────────────

    public function parseWebhook(array $headers, string $rawBody): array
    {
        $check = self::verifySignature((string) ($headers['stripe-signature'] ?? ''), $rawBody, $this->settings->stripeWebhookSecret(), ($this->clock)());
        if (!$check['valid']) {
            return ['valid' => false, 'error' => (string) ($check['error'] ?? 'Signatur ungültig.')];
        }
        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !is_string($payload['id'] ?? null) || !is_string($payload['type'] ?? null)) {
            return ['valid' => false, 'error' => 'Ereignis nicht lesbar.'];
        }

        return ['valid' => true, 'events' => [self::mapEvent($payload)]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function mapEvent(array $payload): array
    {
        $type = (string) $payload['type'];
        $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];
        $base = [
            'event_id' => (string) $payload['id'],
            'type' => $type,
            'currency' => strtoupper((string) ($object['currency'] ?? '')),
            'customer_id' => is_string($object['customer'] ?? null) ? $object['customer'] : '',
        ];
        $orderId = (int) ($object['metadata']['order_id'] ?? $object['client_reference_id'] ?? 0);

        if (str_starts_with($type, 'checkout.session.')) {
            $kind = match ($type) {
                'checkout.session.completed' => ($object['payment_status'] ?? '') === 'paid' ? 'paid' : 'ignored',
                'checkout.session.async_payment_succeeded' => 'paid',
                'checkout.session.async_payment_failed' => 'failed',
                'checkout.session.expired' => 'cancelled',
                default => 'ignored',
            };

            return self::event($base + [
                'kind' => $kind,
                'order_id' => $orderId,
                'checkout_id' => (string) ($object['id'] ?? ''),
                'subscription_id' => is_string($object['subscription'] ?? null) ? $object['subscription'] : '',
                'amount_cents' => isset($object['amount_total']) ? (int) $object['amount_total'] : null,
                'payment_ref' => is_string($object['payment_intent'] ?? null) ? $object['payment_intent'] : (string) ($object['invoice'] ?? ''),
            ]);
        }

        if ($type === 'invoice.paid' || $type === 'invoice.payment_failed') {
            $subscription = is_string($object['subscription'] ?? null)
                ? $object['subscription']
                : (string) ($object['parent']['subscription_details']['subscription'] ?? '');
            $line = $object['lines']['data'][0] ?? [];
            $periodEnd = isset($line['period']['end']) ? (int) $line['period']['end'] : null;
            $kind = 'ignored';
            if ($subscription !== '') {
                $kind = $type === 'invoice.paid'
                    ? (($object['billing_reason'] ?? '') === 'subscription_cycle' ? 'renewed' : 'ignored')
                    : 'renewal_failed';
            }

            return self::event($base + [
                'kind' => $kind,
                'subscription_id' => $subscription,
                'amount_cents' => isset($object['amount_paid']) ? (int) ($type === 'invoice.paid' ? $object['amount_paid'] : ($object['amount_due'] ?? 0)) : null,
                'payment_ref' => (string) ($object['id'] ?? ''),
                'period_end' => $periodEnd,
            ]);
        }

        // Release-Prüfung 4.1.0 (FUN-410-27): vollständige Erstattung und Rückbuchung (Dispute). Teilerstattungen
        // ändern den Bestellstatus nicht; sie bleiben als Ereignis im Protokoll.
        if ($type === 'charge.refunded' || $type === 'charge.dispute.created') {
            $full = $type === 'charge.dispute.created' || !empty($object['refunded']);

            return self::event($base + [
                'kind' => $full ? 'refunded' : 'ignored',
                'payment_ref' => is_string($object['payment_intent'] ?? null) ? $object['payment_intent'] : '',
                'amount_cents' => isset($object['amount_refunded']) ? (int) $object['amount_refunded'] : (isset($object['amount']) ? (int) $object['amount'] : null),
                'reason' => $type === 'charge.dispute.created' ? 'chargeback' : 'refund',
            ]);
        }

        if ($type === 'customer.subscription.deleted') {
            return self::event($base + ['kind' => 'subscription_ended', 'subscription_id' => (string) ($object['id'] ?? '')]);
        }

        return self::event($base + ['kind' => 'ignored']);
    }

    /**
     * Release-Prüfung 4.1.0 (FUN-410-21): wiederkehrendes Abo beim Anbieter beenden. `at_period_end` lässt die
     * bezahlte Periode laufen (Kündigung durch Mitglieder), sonst sofort (Tarifwechsel, Erstattung).
     *
     * @param array<string, mixed> $context
     * @return array{success: bool, error?: string}
     */
    public function cancelRecurring(string $subscriptionId, array $context = []): array
    {
        if (preg_match('/^sub_[A-Za-z0-9]{4,80}$/', $subscriptionId) !== 1) {
            return ['success' => false, 'error' => 'Unbekannte Stripe-Abo-ID.'];
        }
        $url = self::API . 'subscriptions/' . rawurlencode($subscriptionId);
        $result = !empty($context['at_period_end'])
            ? $this->call('POST', $url, http_build_query(['cancel_at_period_end' => 'true']), $this->headers())
            : $this->call('DELETE', $url, '', $this->headers());
        // Bereits beendet oder gelöscht: Ziel erreicht.
        if ($result['status'] === 404 || ($result['data']['status'] ?? '') === 'canceled') {
            return ['success' => true];
        }

        return $result['error'] === '' ? ['success' => true] : ['success' => false, 'error' => $result['error']];
    }

    protected function errorMessage(array $data): string
    {
        return is_string($data['error']['message'] ?? null) ? 'Stripe: ' . $data['error']['message'] : '';
    }

    /**
     * @return list<string>
     */
    private function headers(): array
    {
        return [
            'Authorization: Bearer ' . $this->settings->stripeSecret(),
            'Content-Type: application/x-www-form-urlencoded',
        ];
    }
}
