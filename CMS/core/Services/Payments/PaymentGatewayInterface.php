<?php
/**
 * Schnittstelle eines Zahlungsanbieters (4.1.0, Bereich 5a).
 *
 * Ein Gateway kennt nur die Anbieter-API: Checkout anlegen, eingehende Webhooks prüfen und in **normalisierte
 * Ereignisse** übersetzen, nach der ersten Zahlung ggf. die wiederkehrende Zahlung einrichten. Zustandswechsel der
 * Bestellung, Idempotenz und Abo-Laufzeiten liegen im `PaymentService`.
 *
 * Normalisiertes Ereignis:
 * ```
 * [
 *   'event_id'        => string,       // eindeutig je Anbieter (Idempotenz)
 *   'type'            => string,       // Anbieter-Typ, z. B. checkout.session.completed
 *   'kind'            => string,       // paid | failed | cancelled | renewed | renewal_failed | subscription_ended | refunded | ignored
 *   'order_id'        => int,          // aus den Metadaten (0 = unbekannt)
 *   'checkout_id'     => string,       // Checkout-Session bzw. Zahlung
 *   'subscription_id' => string,       // wiederkehrende Zahlung beim Anbieter ('' = keine)
 *   'customer_id'     => string,
 *   'amount_cents'    => int|null,     // vom Anbieter gemeldeter Betrag
 *   'currency'        => string,       // ISO 4217, Großbuchstaben
 *   'payment_ref'     => string,       // Zahlungs-/Rechnungs-ID beim Anbieter
 *   'period_end'      => int|null,     // Ende der bezahlten Periode (Unix), falls gemeldet
 * ]
 * ```
 *
 * @package CMSv2\Core\Services\Payments
 */

declare(strict_types=1);

namespace CMS\Services\Payments;

if (!defined('ABSPATH')) {
    exit;
}

interface PaymentGatewayInterface
{
    // Release-Prüfung 4.1.0 (FUN-410-27): `refunded` = vollständige Erstattung oder Rückbuchung (Chargeback).
    public const KINDS = ['paid', 'failed', 'cancelled', 'renewed', 'renewal_failed', 'subscription_ended', 'refunded', 'ignored'];

    public function id(): string;

    public function label(): string;

    public function isConfigured(): bool;

    /**
     * Legt beim Anbieter einen gehosteten Checkout an. Beträge stammen ausschließlich aus `$order`.
     *
     * @param array<string, mixed> $order    Zeile aus `orders` (id, order_number, total_amount, currency, customer_email, …)
     * @param array<string, mixed> $context  success_url, cancel_url, webhook_url, description, interval (''|month|year), method
     * @return array{success: bool, checkout_id?: string, redirect_url?: string, customer_id?: string, error?: string}
     */
    public function createCheckout(array $order, array $context): array;

    /**
     * Prüft einen Webhook (Signatur bzw. Abruf beim Anbieter) und übersetzt ihn.
     *
     * @param array<string, string> $headers Kopfzeilen, Schlüssel in Kleinbuchstaben
     * @return array{valid: bool, events?: list<array<string, mixed>>, error?: string}
     */
    public function parseWebhook(array $headers, string $rawBody): array;

    /**
     * Nach der ersten bezahlten Zahlung einer Abo-Bestellung: wiederkehrende Zahlung beim Anbieter einrichten, sofern
     * der Checkout das nicht schon getan hat (Stripe: nichts zu tun, Mollie: Subscription anlegen).
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $order
     * @param array<string, mixed> $context interval, webhook_url, description, start_date (Y-m-d)
     * @return array{success: bool, subscription_id?: string, error?: string}
     */
    public function activateRecurring(array $event, array $order, array $context): array;
}
