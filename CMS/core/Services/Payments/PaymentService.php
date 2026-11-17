<?php
/**
 * Zahlungen (4.1.0, Bereich 5a): Bestellung → gehosteter Checkout, Webhook → Zustandswechsel und Abo-Laufzeit.
 *
 * - **Webhooks sind die Wahrheit:** Der Rücksprung des Browsers zeigt nur den Stand; bezahlt wird eine Bestellung
 *   ausschließlich durch ein verifiziertes Anbieter-Ereignis.
 * - **Idempotenz:** Jedes Ereignis landet genau einmal in `payment_events` (eindeutig je Anbieter und Ereignis-ID);
 *   eine Wiederholung wird mit 200 beantwortet und nicht erneut verarbeitet.
 * - **Beträge aus der eigenen Bestellung:** Weicht der gemeldete Betrag oder die Währung ab, bleibt die Bestellung
 *   unbezahlt und das Ereignis steht auf `failed`.
 * - **Zustandsautomat:** pending → paid | failed | cancelled, failed → paid | cancelled, paid → refunded; keine Rücksprünge.
 * - Abo: Nach der Erstzahlung `SubscriptionManager::assignSubscription()` und Laufzeit (`current_period_end`) setzen,
 *   bei automatischer Verlängerung die Abo-ID des Anbieters speichern (Mollie: Abo hier anlegen). Verlängerungen
 *   verschieben das Periodenende und legen eine bezahlte Folgebestellung an (Grundlage der Rechnung).
 * - Ereignisse: `cms_payment_succeeded` (orderId, provider, event), `cms_payment_failed` (orderId|0, provider, event),
 *   `cms_subscription_renewed` (subscriptionRowId, userId, renewalOrderId, periodEnd), `cms_subscription_ended`.
 *
 * @package CMSv2\Core\Services\Payments
 */

declare(strict_types=1);

namespace CMS\Services\Payments;

use CMS\Database;
use CMS\Hooks;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class PaymentService
{
    public const MAX_WEBHOOK_BYTES = 65536;
    public const WEBHOOK_PATH = '/payments/webhook/';
    public const RETURN_PATH = '/payments/return/';
    /** Zahlart im Checkout → Anbieter (PayPal läuft über Mollie). */
    public const METHOD_PROVIDER = ['stripe' => 'stripe', 'mollie' => 'mollie', 'paypal' => 'mollie'];
    private const TRANSITIONS = [
        'pending' => ['paid', 'failed', 'cancelled'],
        'failed' => ['paid', 'cancelled'],
        'paid' => ['refunded'],
    ];

    private static ?self $instance = null;

    /** Release-Prüfung 4.1.0 (FUN-410-37): Anbieter-Abo, das nach dem Commit angelegt wird. @var array<int, mixed>|null */
    private ?array $pendingRecurring = null;

    /** Release-Prüfung 4.1.0 (FUN-410-21): Anbieter-Abo, das gerade zugewiesen wird (nicht beenden). */
    public static string $assigningProviderSubscriptionId = '';

    private Database $db;
    private string $prefix;
    private PaymentSettings $settings;
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(int, int, string): bool */
    private \Closure $assigner;

    /**
     * @param array<string, PaymentGatewayInterface>|null $gateways
     */
    public function __construct(
        ?Database $db = null,
        ?PaymentSettings $settings = null,
        ?array $gateways = null,
        ?\Closure $clock = null,
        ?\Closure $assigner = null
    ) {
        $this->db = $db ?? Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->settings = $settings ?? PaymentSettings::load();
        $this->gateways = $gateways ?? [
            'stripe' => new StripeGateway($this->settings),
            'mollie' => new MollieGateway($this->settings),
        ];
        $this->clock = $clock ?? static fn (): int => time();
        $this->assigner = $assigner ?? static fn (int $userId, int $planId, string $cycle): bool
            => \CMS\SubscriptionManager::instance()->assignSubscription($userId, $planId, $cycle);
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function settings(): PaymentSettings
    {
        return $this->settings;
    }

    public function gateway(string $provider): ?PaymentGatewayInterface
    {
        $gateway = $this->gateways[$provider] ?? null;

        return $gateway !== null && $gateway->isConfigured() ? $gateway : null;
    }

    /**
     * Online-Zahlarten, die gerade wirklich funktionieren (Anbieter eingeschaltet und vollständig).
     *
     * @param list<string> $wanted aus der Abo-Einstellung (z. B. ['invoice', 'stripe', 'paypal'])
     * @return list<string>
     */
    public function filterMethods(array $wanted): array
    {
        return array_values(array_filter($wanted, function (string $method): bool {
            if ($method === 'invoice') {
                return true;
            }
            $provider = self::METHOD_PROVIDER[$method] ?? null;

            return $provider !== null && $this->gateway($provider) !== null;
        }));
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[strtolower(trim($from))] ?? [], true);
    }

    /**
     * Ende der nächsten Periode ab `$base` (lifetime → null).
     */
    public static function periodEnd(int $base, string $cycle): ?int
    {
        $modifier = match ($cycle) {
            'monthly' => '+1 month',
            'yearly' => '+1 year',
            default => null,
        };

        return $modifier === null ? null : (int) (new \DateTimeImmutable('@' . $base))->modify($modifier)->getTimestamp();
    }

    public static function interval(string $cycle): string
    {
        return match ($cycle) {
            'monthly' => 'month',
            'yearly' => 'year',
            default => '',
        };
    }

    // ── Rücksprung ──────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $order
     */
    public function returnPath(array $order): string
    {
        $number = (string) ($order['order_number'] ?? '');

        return self::RETURN_PATH . rawurlencode($number) . '?sig=' . $this->returnSignature($order);
    }

    /**
     * @param array<string, mixed> $order
     */
    public function returnSignature(array $order): string
    {
        return substr(hash_hmac('sha256', (int) ($order['id'] ?? 0) . '|' . (string) ($order['order_number'] ?? ''), $this->settings->returnKey()), 0, 32);
    }

    /**
     * Bestellung zum signierten Rücksprung (nur Anzeige, ändert nichts).
     *
     * @return array<string, mixed>|null
     */
    public function orderForReturn(string $orderNumber, string $signature): ?array
    {
        $order = $this->findOrder('order_number = ?', [$orderNumber]);
        if ($order === null || $signature === '' || !hash_equals($this->returnSignature($order), $signature)) {
            return null;
        }

        return $order;
    }

    // ── Checkout ────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Legt den Checkout für eine offene Bestellung an und merkt sich die Checkout-ID.
     *
     * @return array{success: bool, redirect_url?: string, error?: string}
     */
    public function startCheckout(int $orderId, string $method, string $baseUrl, string $description = ''): array
    {
        $provider = self::METHOD_PROVIDER[$method] ?? '';
        $gateway = $this->gateway($provider);
        $order = $this->findOrder('id = ?', [$orderId]);
        if ($gateway === null) {
            return ['success' => false, 'error' => 'Diese Zahlart ist gerade nicht verfügbar.'];
        }
        if ($order === null || strtolower((string) $order['status']) !== 'pending') {
            return ['success' => false, 'error' => 'Die Bestellung kann nicht mehr bezahlt werden.'];
        }
        if (AbstractGateway::amountCents($order) <= 0) {
            return ['success' => false, 'error' => 'Die Bestellung hat keinen zahlbaren Betrag.'];
        }
        $baseUrl = rtrim($baseUrl, '/');
        $returnUrl = $baseUrl . $this->returnPath($order);
        $cycle = (string) ($order['billing_cycle'] ?? '');
        $result = $gateway->createCheckout($order, [
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl . '&cancelled=1',
            'webhook_url' => $baseUrl . self::WEBHOOK_PATH . $provider,
            'description' => $description !== '' ? $description : 'Bestellung ' . (string) $order['order_number'],
            'interval' => $this->settings->recurring() && (int) ($order['user_id'] ?? 0) > 0 ? self::interval($cycle) : '',
            'method' => $method,
        ]);
        if (!$result['success']) {
            Logger::instance()->withChannel('payments')->warning('Checkout konnte nicht angelegt werden.', [
                'provider' => $provider, 'order_id' => $orderId, 'error' => (string) ($result['error'] ?? ''),
            ]);

            return ['success' => false, 'error' => 'Die Zahlung konnte gerade nicht gestartet werden. Bitte später erneut versuchen.'];
        }
        $this->db->update('orders', [
            'provider' => $provider,
            'provider_checkout_id' => (string) $result['checkout_id'],
            'payment_method' => $method,
        ], ['id' => $orderId]);

        return ['success' => true, 'redirect_url' => (string) $result['redirect_url']];
    }

    // ── Webhook ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $headers Schlüssel in Kleinbuchstaben
     * @return array{status: int, message: string}
     */
    public function handleWebhook(string $provider, array $headers, string $rawBody): array
    {
        if (strlen($rawBody) > self::MAX_WEBHOOK_BYTES) {
            return ['status' => 413, 'message' => 'Zu groß.'];
        }
        $gateway = $this->gateway($provider);
        if ($gateway === null) {
            return ['status' => 404, 'message' => 'Unbekannter Anbieter.'];
        }
        $parsed = $gateway->parseWebhook($headers, $rawBody);
        if (!$parsed['valid']) {
            Logger::instance()->withChannel('payments')->warning('Webhook abgelehnt.', ['provider' => $provider, 'error' => (string) ($parsed['error'] ?? '')]);

            return ['status' => 400, 'message' => 'Ungültig.'];
        }
        $retry = false;
        foreach ($parsed['events'] ?? [] as $event) {
            $retry = $this->receive($provider, $event, hash('sha256', $rawBody)) === 'error' || $retry;
        }

        // 500 = Anbieter wiederholt die Zustellung (Stripe/Mollie mit Backoff).
        return $retry ? ['status' => 500, 'message' => 'Später erneut.'] : ['status' => 200, 'message' => 'OK'];
    }

    /**
     * Ein Ereignis einmalig aufnehmen und verarbeiten.
     *
     * @param array<string, mixed> $event
     * @return string received-Status: duplicate | processed | ignored | failed | error (interner Fehler, nicht gespeichert)
     */
    public function receive(string $provider, array $event, string $payloadHash = ''): string
    {
        $eventId = mb_substr((string) ($event['event_id'] ?? ''), 0, 255);
        if ($eventId === '') {
            return 'failed';
        }
        $exists = $this->db->get_var(
            "SELECT id FROM {$this->prefix}payment_events WHERE provider = ? AND provider_event_id = ? LIMIT 1",
            [$provider, $eventId]
        );
        if ($exists !== null && $exists !== false) {
            return 'duplicate';
        }
        $rowId = $this->db->insert('payment_events', [
            'provider' => $provider,
            'provider_event_id' => $eventId,
            'type' => mb_substr((string) ($event['type'] ?? ''), 0, 80),
            'order_id' => (int) ($event['order_id'] ?? 0) ?: null,
            'status' => 'received',
            'payload_hash' => $payloadHash !== '' ? $payloadHash : str_repeat('0', 64),
            'received_at' => $this->now(),
        ]);
        if (!$rowId) {
            // Eindeutiger Schlüssel griff (parallele Zustellung) – schon in Arbeit.
            return 'duplicate';
        }
        // SEC-410-04: Bestellstatus, Abo-Zuweisung und Folgeschritte (Rechnung, Webhooks) in einer Transaktion – bei einem
        // internen Fehler bleibt nichts halb verbucht, und die Wiederholung des Anbieters verarbeitet das Ereignis neu.
        $pdo = $this->transactionPdo();
        $ownTransaction = $pdo !== null && !$pdo->inTransaction() && $pdo->beginTransaction();
        $this->pendingRecurring = null;
        try {
            $outcome = $this->process($provider, $event);
            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            // FUN-410-37: Nach einem Rollback wird kein Anbieter-Abo angelegt (die Wiederholung legt es dann an).
            $this->pendingRecurring = null;
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::instance()->withChannel('payments')->error('Zahlungsereignis fehlgeschlagen.', ['provider' => $provider, 'event' => $eventId, 'exception' => $e::class]);
            // SEC-410-04: Interner Fehler (z. B. Datenbank kurz weg) ist kein endgültiges Ergebnis. Den Eintrag wieder
            // entfernen, damit die Wiederholung des Anbieters (Antwort 500) erneut verarbeitet statt als Duplikat
            // verworfen wird – sonst bliebe eine bezahlte Bestellung dauerhaft offen.
            try {
                $this->db->execute("DELETE FROM {$this->prefix}payment_events WHERE id = ?", [(int) $rowId]);
            } catch (\Throwable) {
            }

            return 'error';
        }
        $recurringError = $this->runPendingRecurring();
        if ($recurringError !== '') {
            $outcome['error'] = trim((string) ($outcome['error'] ?? '') . ' ' . $recurringError);
        }
        $this->db->update('payment_events', [
            'status' => $outcome['status'],
            'error' => isset($outcome['error']) ? mb_substr($outcome['error'], 0, 500) : null,
            'order_id' => (int) ($outcome['order_id'] ?? $event['order_id'] ?? 0) ?: null,
            'processed_at' => $this->now(),
        ], ['id' => (int) $rowId]);

        return $outcome['status'];
    }

    /**
     * @param array<string, mixed> $event
     * @return array{status: string, error?: string, order_id?: int}
     */
    private function process(string $provider, array $event): array
    {
        return match ((string) ($event['kind'] ?? 'ignored')) {
            'paid' => $this->markPaid($provider, $event),
            'failed' => $this->markOrder($provider, $event, 'failed'),
            'cancelled' => $this->markOrder($provider, $event, 'cancelled'),
            'renewed' => $this->renew($provider, $event),
            'renewal_failed' => $this->renewalFailed($provider, $event),
            'subscription_ended' => $this->endSubscription($provider, $event),
            'refunded' => $this->refund($provider, $event),
            default => ['status' => 'ignored'],
        };
    }

    /**
     * @param array<string, mixed> $event
     * @return array{order: ?array<string, mixed>, error?: string}
     */
    private function orderForEvent(string $provider, array $event): array
    {
        $orderId = (int) ($event['order_id'] ?? 0);
        $order = $orderId > 0 ? $this->findOrder('id = ?', [$orderId]) : null;
        if ($order === null && (string) ($event['checkout_id'] ?? '') !== '') {
            $order = $this->findOrder('provider = ? AND provider_checkout_id = ?', [$provider, (string) $event['checkout_id']]);
        }
        if ($order === null) {
            return ['order' => null, 'error' => 'Bestellung unbekannt.'];
        }
        if ((string) ($order['provider'] ?? '') !== $provider) {
            return ['order' => null, 'error' => 'Bestellung gehört zu einem anderen Anbieter.'];
        }
        $known = (string) ($order['provider_checkout_id'] ?? '');
        if ($known !== '' && (string) ($event['checkout_id'] ?? '') !== '' && !hash_equals($known, (string) $event['checkout_id'])) {
            return ['order' => null, 'error' => 'Checkout passt nicht zur Bestellung.'];
        }

        return ['order' => $order];
    }

    /**
     * @param array<string, mixed> $event
     * @return array{status: string, error?: string, order_id?: int}
     */
    private function markPaid(string $provider, array $event): array
    {
        $found = $this->orderForEvent($provider, $event);
        $order = $found['order'];
        if ($order === null) {
            return ['status' => 'failed', 'error' => (string) ($found['error'] ?? '')];
        }
        $orderId = (int) $order['id'];
        $expected = AbstractGateway::amountCents($order);
        if ($event['amount_cents'] === null || (int) $event['amount_cents'] !== $expected
            || strtoupper((string) $event['currency']) !== AbstractGateway::currency($order)) {
            return ['status' => 'failed', 'order_id' => $orderId, 'error' => sprintf(
                'Betrag passt nicht: erwartet %d %s, gemeldet %s %s.',
                $expected,
                AbstractGateway::currency($order),
                $event['amount_cents'] === null ? '–' : (string) (int) $event['amount_cents'],
                (string) $event['currency']
            )];
        }
        if (!self::canTransition((string) $order['status'], 'paid')) {
            return ['status' => 'ignored', 'order_id' => $orderId];
        }
        $now = ($this->clock)();
        // SEC-410-03: Zustandswechsel atomar – zwei gleichzeitige „bezahlt“-Ereignisse (z. B. `completed` und
        // `async_payment_succeeded`) aktivieren das Abo sonst doppelt. Nur die Anfrage, die die Zeile umstellt, fährt fort.
        $changed = $this->db->execute(
            "UPDATE {$this->prefix}orders SET status = 'paid', paid_at = ?, payment_ref = ? WHERE id = ? AND status IN ('pending', 'failed')",
            [gmdate('Y-m-d H:i:s', $now), mb_substr((string) $event['payment_ref'], 0, 200) ?: null, $orderId]
        )->rowCount();
        if ($changed !== 1) {
            return ['status' => 'ignored', 'order_id' => $orderId];
        }

        $error = $this->activateSubscription($provider, $event, $order, $now);
        Hooks::doAction('cms_payment_succeeded', $orderId, $provider, $event);

        return ['status' => 'processed', 'order_id' => $orderId] + ($error !== '' ? ['error' => $error] : []);
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $order
     */
    private function activateSubscription(string $provider, array $event, array $order, int $now): string
    {
        $userId = (int) ($order['user_id'] ?? 0);
        $planId = (int) ($order['plan_id'] ?? 0);
        if ($userId <= 0 || $planId <= 0) {
            return '';
        }
        $cycle = (string) ($order['billing_cycle'] ?? 'monthly');
        // FUN-410-21: Beim Zuweisen beendet SubscriptionManager die Anbieter-Abos des alten Pakets, nicht dieses.
        self::$assigningProviderSubscriptionId = (string) ($event['subscription_id'] ?? '');
        try {
            $assigned = ($this->assigner)($userId, $planId, $cycle);
        } finally {
            self::$assigningProviderSubscriptionId = '';
        }
        if (!$assigned) {
            return 'Abo konnte nicht zugewiesen werden.';
        }
        $periodEnd = self::periodEnd($now, $cycle);
        $subscriptionId = (string) ($event['subscription_id'] ?? '');
        $error = '';
        $interval = self::interval($cycle);
        $deferRecurring = null;
        if ($subscriptionId === '' && $interval !== '' && $this->settings->recurring() && ($gateway = $this->gateway($provider)) !== null) {
            // Release-Prüfung 4.1.0 (FUN-410-37): Das Anbieter-Abo (Mollie) entsteht erst nach dem Commit. Innerhalb
            // der Transaktion angelegt, blieb es nach einem Rollback bestehen und die Wiederholung legte ein zweites an.
            $deferRecurring = [$gateway, $event, $order, $this->recurringContext($provider) + [
                'interval' => $interval,
                'start_date' => $periodEnd !== null ? gmdate('Y-m-d', $periodEnd) : '',
                'webhook_url' => '',
                'description' => 'Abo',
            ]];
        }
        $rowId = (int) $this->db->get_var(
            "SELECT id FROM {$this->prefix}user_subscriptions WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
            [$userId]
        );
        if ($rowId > 0) {
            $this->db->update('user_subscriptions', [
                'provider' => $provider,
                'provider_subscription_id' => $subscriptionId !== '' ? mb_substr($subscriptionId, 0, 255) : null,
                'current_period_end' => $periodEnd !== null ? gmdate('Y-m-d H:i:s', $periodEnd) : null,
            ], ['id' => $rowId]);
        }
        if ($deferRecurring !== null) {
            $this->pendingRecurring = [...$deferRecurring, $rowId];
        }

        return $error;
    }

    /**
     * Basis-URL für Webhooks wiederkehrender Zahlungen (Mollie braucht sie je Abo).
     *
     * @return array<string, string>
     */
    private function recurringContext(string $provider): array
    {
        $base = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';

        return $base !== '' ? ['webhook_url' => $base . self::WEBHOOK_PATH . $provider] : [];
    }

    /**
     * @param array<string, mixed> $event
     * @return array{status: string, error?: string, order_id?: int}
     */
    private function markOrder(string $provider, array $event, string $status): array
    {
        $found = $this->orderForEvent($provider, $event);
        $order = $found['order'];
        if ($order === null) {
            return ['status' => 'failed', 'error' => (string) ($found['error'] ?? '')];
        }
        $orderId = (int) $order['id'];
        if (!self::canTransition((string) $order['status'], $status)) {
            return ['status' => 'ignored', 'order_id' => $orderId];
        }
        $this->db->update('orders', ['status' => $status], ['id' => $orderId]);
        if ($status === 'failed') {
            Hooks::doAction('cms_payment_failed', $orderId, $provider, $event);
        }

        return ['status' => 'processed', 'order_id' => $orderId];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function subscriptionFor(string $provider, string $subscriptionId): ?array
    {
        if ($subscriptionId === '') {
            return null;
        }
        $row = $this->db->get_row(
            "SELECT * FROM {$this->prefix}user_subscriptions WHERE provider = ? AND provider_subscription_id = ? ORDER BY id DESC LIMIT 1",
            [$provider, $subscriptionId]
        );

        return $row !== null ? (array) $row : null;
    }

    /**
     * @param array<string, mixed> $event
     * @return array{status: string, error?: string, order_id?: int}
     */
    private function renew(string $provider, array $event): array
    {
        $subscription = $this->subscriptionFor($provider, (string) $event['subscription_id']);
        if ($subscription === null) {
            return ['status' => 'failed', 'error' => 'Abo unbekannt.'];
        }
        // FUN-410-20: Ein gekündigtes oder durch einen Tarifwechsel ersetztes Abo wird nie wieder aktiv; die Abbuchung
        // beim Anbieter steht am Ereignis, damit sie dort beendet und erstattet werden kann.
        if (strtolower((string) ($subscription['status'] ?? '')) === 'cancelled') {
            return ['status' => 'failed', 'error' => 'Verlängerung für ein gekündigtes Abo – das Abo beim Anbieter beenden und die Zahlung prüfen.'];
        }
        $now = ($this->clock)();
        $current = strtotime((string) ($subscription['current_period_end'] ?? '') . ' UTC') ?: 0;
        $cycle = (string) ($subscription['billing_cycle'] ?? 'monthly');
        $periodEnd = $event['period_end'] !== null && (int) $event['period_end'] > $now
            ? (int) $event['period_end']
            : (self::periodEnd(max($current, $now), $cycle) ?? $now);
        $end = gmdate('Y-m-d H:i:s', $periodEnd);
        $this->db->update('user_subscriptions', [
            'status' => 'active',
            'current_period_end' => $end,
            'end_date' => $end,
            'next_billing_date' => $end,
        ], ['id' => (int) $subscription['id']]);

        $mismatch = '';
        $renewalOrderId = $this->createRenewalOrder($provider, $event, $subscription, $now, $mismatch);
        Hooks::doAction('cms_subscription_renewed', (int) $subscription['id'], (int) $subscription['user_id'], $renewalOrderId, $periodEnd);

        // SEC-410-05: Der Anbieter hat abgebucht, die Laufzeit gilt. Weicht Betrag oder Währung von der Vorlage ab,
        // steht das sichtbar am Ereignis (Zahlungen → Ereignisse), damit die Rechnung geprüft wird.
        return ['status' => 'processed', 'order_id' => $renewalOrderId] + ($mismatch !== '' ? ['error' => $mismatch] : []);
    }

    /**
     * Release-Prüfung 4.1.0 (FUN-410-38): Vorlage für Folgebestellungen. Zuerst die letzte bezahlte Bestellung beim
     * Anbieter, sonst die letzte bezahlte oder erstattete Bestellung des Pakets (z. B. nach Anbieterwechsel).
     *
     * @param array<string, mixed> $subscription
     * @return array<string, mixed>|null
     */
    private function renewalTemplate(string $provider, array $subscription): ?array
    {
        $userId = (int) $subscription['user_id'];
        $planId = (int) $subscription['plan_id'];

        return $this->findOrder("user_id = ? AND plan_id = ? AND provider = ? AND status = 'paid' ORDER BY id DESC", [$userId, $planId, $provider])
            ?? $this->findOrder("user_id = ? AND plan_id = ? AND status IN ('paid', 'refunded') ORDER BY id DESC", [$userId, $planId]);
    }

    /**
     * Bezahlte Folgebestellung nach dem Muster der Vorlage dieses Abos (Grundlage der Rechnung).
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $subscription
     */
    private function createRenewalOrder(string $provider, array $event, array $subscription, int $now, string &$mismatch = ''): int
    {
        $template = $this->renewalTemplate($provider, $subscription);
        if ($template === null) {
            // FUN-410-38: ohne Vorlage keine Folgebestellung und keine Rechnung; sichtbar machen statt still.
            Logger::instance()->withChannel('payments')->warning('Verlängerung ohne Vorlagebestellung: keine Folgebestellung/Rechnung erzeugt.', [
                'subscription' => (int) $subscription['id'], 'provider' => $provider, 'event' => (string) ($event['event_id'] ?? ''),
            ]);
            Hooks::doAction('cms_subscription_renewal_unbilled', (int) $subscription['id'], (int) $subscription['user_id'], $provider, $event);

            return 0;
        }
        $copy = array_intersect_key($template, array_flip([
            'user_id', 'plan_id', 'customer_name', 'customer_email', 'amount', 'tax_amount', 'total_amount', 'currency',
            'payment_method', 'billing_cycle', 'contact_data', 'forename', 'lastname', 'company', 'email', 'phone',
            'street', 'zip', 'city', 'country',
        ]));
        $expected = AbstractGateway::amountCents($template);
        $currency = strtoupper((string) ($event['currency'] ?? ''));
        if (($event['amount_cents'] !== null && (int) $event['amount_cents'] !== $expected)
            || ($currency !== '' && $currency !== AbstractGateway::currency($template))) {
            $mismatch = sprintf(
                'Verlängerung mit abweichendem Betrag: erwartet %d %s, gemeldet %s %s – Rechnung prüfen.',
                $expected,
                AbstractGateway::currency($template),
                $event['amount_cents'] === null ? '–' : (string) (int) $event['amount_cents'],
                $currency !== '' ? $currency : '–'
            );
            Logger::instance()->withChannel('payments')->warning('Verlängerung mit abweichendem Betrag.', ['subscription' => (int) $subscription['id']]);
        }
        $baseNumber = preg_replace('/-V[0-9a-f]{6}$/', '', (string) $template['order_number']);
        $id = $this->db->insert('orders', $copy + [
            'order_number' => mb_substr($baseNumber, 0, 56) . '-V' . substr(hash('sha256', (string) $event['event_id']), 0, 6),
            'status' => 'paid',
            'provider' => $provider,
            'provider_checkout_id' => mb_substr((string) $event['payment_ref'], 0, 255) ?: null,
            'payment_ref' => mb_substr((string) $event['payment_ref'], 0, 200) ?: null,
            'paid_at' => gmdate('Y-m-d H:i:s', $now),
            'notes' => 'Automatische Verlängerung',
            'created_at' => gmdate('Y-m-d H:i:s', $now),
        ]);

        return (int) $id;
    }

    /**
     * @param array<string, mixed> $event
     * @return array{status: string, error?: string}
     */
    private function renewalFailed(string $provider, array $event): array
    {
        $subscription = $this->subscriptionFor($provider, (string) $event['subscription_id']);
        if ($subscription === null) {
            return ['status' => 'failed', 'error' => 'Abo unbekannt.'];
        }
        // Release-Prüfung 4.1.0 (FUN-410-38): Hook mit der letzten Bestellung des Abos statt order_id 0, damit
        // Empfänger (Mail, Webhooks) Kunde und Paket zuordnen können; `renewal` kennzeichnet die Verlängerung.
        $template = $this->renewalTemplate($provider, $subscription);
        $orderId = $template !== null ? (int) $template['id'] : 0;
        Hooks::doAction('cms_payment_failed', $orderId, $provider, $event + ['renewal' => true, 'subscription_row_id' => (int) $subscription['id']]);

        return ['status' => 'processed', 'order_id' => $orderId];
    }

    /**
     * @param array<string, mixed> $event
     * @return array{status: string, error?: string}
     */
    private function endSubscription(string $provider, array $event): array
    {
        $subscription = $this->subscriptionFor($provider, (string) $event['subscription_id']);
        if ($subscription === null) {
            return ['status' => 'ignored'];
        }
        $this->db->update('user_subscriptions', [
            'status' => 'cancelled',
            'cancelled_at' => $this->now(),
        ], ['id' => (int) $subscription['id']]);
        Hooks::doAction('cms_subscription_ended', (int) $subscription['id'], (int) $subscription['user_id'], $provider);

        return ['status' => 'processed'];
    }

    /** FUN-410-37: das vorgemerkte Anbieter-Abo nach dem Commit anlegen; Rückgabe: Fehlertext oder ''. */
    private function runPendingRecurring(): string
    {
        $pending = $this->pendingRecurring;
        $this->pendingRecurring = null;
        if ($pending === null) {
            return '';
        }
        [$gateway, $event, $order, $context, $rowId] = $pending;
        try {
            $result = $gateway->activateRecurring($event, $order, $context);
        } catch (\Throwable $e) {
            $result = ['success' => false, 'error' => $e->getMessage()];
        }
        $subscriptionId = (string) ($result['subscription_id'] ?? '');
        if (!empty($result['success']) && $subscriptionId !== '' && (int) $rowId > 0) {
            $this->db->update('user_subscriptions', ['provider_subscription_id' => mb_substr($subscriptionId, 0, 255)], ['id' => (int) $rowId]);
        }

        return !empty($result['success']) ? '' : 'Automatische Verlängerung nicht eingerichtet: ' . (string) ($result['error'] ?? '');
    }

    /**
     * Release-Prüfung 4.1.0 (FUN-410-27): vollständige Erstattung oder Rückbuchung. Bestellung → `refunded`,
     * Rechnung → Stornorechnung, zugehöriges Abo endet sofort (auch beim Anbieter, damit nicht weiter abgebucht wird).
     *
     * @param array<string, mixed> $event
     * @return array{status: string, error?: string, order_id?: int}
     */
    private function refund(string $provider, array $event): array
    {
        $order = null;
        $paymentRef = (string) ($event['payment_ref'] ?? '');
        if ($paymentRef !== '') {
            $order = $this->findOrder('provider = ? AND (payment_ref = ? OR provider_checkout_id = ?)', [$provider, $paymentRef, $paymentRef]);
        }
        if ($order === null) {
            $found = $this->orderForEvent($provider, $event);
            $order = $found['order'];
            if ($order === null) {
                return ['status' => 'failed', 'error' => (string) ($found['error'] ?? 'Bestellung unbekannt.')];
            }
        }
        $orderId = (int) $order['id'];
        if (!self::canTransition((string) $order['status'], 'refunded')) {
            return ['status' => 'ignored', 'order_id' => $orderId];
        }
        $this->db->update('orders', ['status' => 'refunded'], ['id' => $orderId]);

        $reason = ($event['reason'] ?? '') === 'chargeback' ? 'Rückbuchung beim Zahlungsanbieter' : 'Erstattung beim Zahlungsanbieter';
        try {
            InvoiceService::getInstance()->cancelForOrder($orderId, $reason);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('payments')->warning('Stornorechnung nach Erstattung fehlgeschlagen.', ['order_id' => $orderId, 'exception' => $e->getMessage()]);
        }

        $userId = (int) ($order['user_id'] ?? 0);
        $subscription = $userId > 0 ? $this->db->get_row(
            "SELECT * FROM {$this->prefix}user_subscriptions WHERE user_id = ? AND status = 'active' AND plan_id = ? ORDER BY id DESC LIMIT 1",
            [$userId, (int) ($order['plan_id'] ?? 0)]
        ) : null;
        if ($subscription !== null) {
            $subscription = (array) $subscription;
            if ((string) ($subscription['provider_subscription_id'] ?? '') !== '') {
                $this->cancelRecurring($subscription, false);
            }
            $this->db->update('user_subscriptions', [
                'status' => 'cancelled',
                'cancelled_at' => $this->now(),
                'end_date' => $this->now(),
                'next_billing_date' => null,
            ], ['id' => (int) $subscription['id']]);
            Hooks::doAction('cms_subscription_ended', (int) $subscription['id'], $userId, $provider);
        }
        Hooks::doAction('cms_payment_refunded', $orderId, $provider, $event);

        return ['status' => 'processed', 'order_id' => $orderId];
    }

    /**
     * Release-Prüfung 4.1.0 (FUN-410-21): wiederkehrendes Abo beim Anbieter beenden (Tarifwechsel, Kündigung,
     * Erstattung). Ohne Anbieter-Abo gibt es nichts zu tun.
     *
     * @param array<string, mixed> $subscription Zeile aus user_subscriptions
     * @return array{success: bool, error?: string}
     */
    public function cancelRecurring(array $subscription, bool $atPeriodEnd): array
    {
        $provider = (string) ($subscription['provider'] ?? '');
        $providerId = (string) ($subscription['provider_subscription_id'] ?? '');
        if ($provider === '' || $providerId === '') {
            return ['success' => true];
        }
        $gateway = $this->gateway($provider);
        if ($gateway === null || !method_exists($gateway, 'cancelRecurring')) {
            return ['success' => false, 'error' => 'Zahlungsanbieter nicht konfiguriert.'];
        }
        $context = ['at_period_end' => $atPeriodEnd];
        // Mollie braucht die Kunden-ID; sie steckt in der letzten Zahlung des Kontos bei diesem Anbieter.
        $paymentId = $this->db->get_var(
            "SELECT COALESCE(NULLIF(payment_ref, ''), provider_checkout_id) FROM {$this->prefix}orders
              WHERE user_id = ? AND provider = ? AND status IN ('paid', 'refunded') ORDER BY id DESC LIMIT 1",
            [(int) ($subscription['user_id'] ?? 0), $provider]
        );
        if (is_string($paymentId) && $paymentId !== '') {
            $context['payment_id'] = $paymentId;
        }
        $result = $gateway->cancelRecurring($providerId, $context);
        if (empty($result['success'])) {
            Logger::instance()->withChannel('payments')->warning('Abo beim Anbieter nicht beendet.', [
                'subscription_id' => (int) ($subscription['id'] ?? 0), 'provider' => $provider, 'error' => (string) ($result['error'] ?? ''),
            ]);
        }

        return $result;
    }

    /**
     * Release-Prüfung 4.1.0 (FUN-410-21): Kündigung durch das Mitglied zum Ende der bezahlten Periode. Das Abo
     * bleibt bis `end_date` aktiv; beim Anbieter wird nicht mehr verlängert.
     *
     * @return array{success: bool, message?: string, error?: string}
     */
    public function cancelMemberSubscription(int $userId): array
    {
        $row = $this->db->get_row(
            "SELECT * FROM {$this->prefix}user_subscriptions WHERE user_id = ? AND status = 'active' AND (end_date IS NULL OR end_date > ?) ORDER BY id DESC LIMIT 1",
            [$userId, $this->now()]
        );
        if ($row === null) {
            return ['success' => false, 'error' => 'Kein aktives Abo.'];
        }
        $subscription = (array) $row;
        if (!empty($subscription['cancelled_at'])) {
            return ['success' => true, 'message' => 'Das Abo ist bereits gekündigt.'];
        }
        if (empty($subscription['end_date'])) {
            return ['success' => false, 'error' => 'Dieses Paket hat keine Laufzeit und muss nicht gekündigt werden.'];
        }
        $result = $this->cancelRecurring($subscription, true);
        if (empty($result['success'])) {
            return ['success' => false, 'error' => 'Die Kündigung beim Zahlungsanbieter ist fehlgeschlagen. Bitte versuche es später erneut oder wende dich an uns.'];
        }
        $this->db->update('user_subscriptions', [
            'cancelled_at' => $this->now(),
            'next_billing_date' => null,
        ], ['id' => (int) $subscription['id']]);
        Hooks::doAction('cms_subscription_cancelled_by_member', (int) $subscription['id'], $userId);

        return ['success' => true, 'message' => 'Das Abo ist gekündigt und läuft bis zum Ende der bezahlten Periode.'];
    }

    // ── Admin ───────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    public function recentEvents(int $limit = 50): array
    {
        $rows = $this->db->get_results(
            "SELECT id, provider, provider_event_id, type, order_id, status, error, received_at, processed_at
             FROM {$this->prefix}payment_events ORDER BY id DESC LIMIT " . max(1, min(200, $limit))
        );

        return array_map(static fn (mixed $row): array => (array) $row, $rows);
    }

    // ── intern ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function findOrder(string $where, array $params): ?array
    {
        $row = $this->db->get_row("SELECT * FROM {$this->prefix}orders WHERE {$where} LIMIT 1", $params);

        return $row !== null ? (array) $row : null;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s', ($this->clock)());
    }

    /**
     * PDO der Datenbank für die Transaktion; null, wenn keine verfügbar ist (Test-Doubles).
     */
    private function transactionPdo(): ?\PDO
    {
        try {
            $pdo = $this->db->getPdo();
        } catch (\Throwable) {
            return null;
        }

        return $pdo instanceof \PDO ? $pdo : null;
    }
}
