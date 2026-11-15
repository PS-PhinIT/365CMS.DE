<?php
/**
 * Zahlungen (4.1.0, Bereich 5a): Stripe-Signatur (gültig, falsch, alt, mehrere v1), Ereignis-Abbildung Stripe/Mollie,
 * Mollie-Abrufpfad mit Fake-Transport, Checkout mit Betrag aus der Bestellung, Idempotenz, Betragsabweichung,
 * Zustandsautomat, Abo-Aktivierung und -Verlängerung, signierter Rücksprung, Einstellungen, Einbindung.
 * Ohne Datenbank und Netz: SQLite-Attrappe und Fake-Transport.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/payments/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');
define('SITE_URL', 'https://shop.example');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once dirname(__DIR__) . '/lib/TestRunner.php';

use CMS\Database;
use CMS\Hooks;
use CMS\Services\Payments\MollieGateway;
use CMS\Services\Payments\PaymentService as P;
use CMS\Services\Payments\PaymentSettings;
use CMS\Services\Payments\StripeGateway;

T::$verbose = in_array('-v', $argv ?? [], true);

const PY_WHSEC = 'whsec_testgeheimnis1234567890';
const PY_NOW = 1763200000; // 2025-11-15 09:46:40 UTC

function pySource(string $relative): string
{
    return (string) file_get_contents(ABSPATH . $relative);
}

function pyDatabase(): Database
{
    return new class () extends Database {
        public PDO $sqlite;

        public function __construct()
        {
            $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->sqlite->exec("CREATE TABLE cms_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, order_number TEXT UNIQUE, user_id INTEGER, plan_id INTEGER,
                customer_name TEXT, customer_email TEXT, amount REAL DEFAULT 0, tax_amount REAL DEFAULT 0, total_amount REAL DEFAULT 0, currency TEXT DEFAULT 'EUR',
                status TEXT DEFAULT 'pending', payment_method TEXT, billing_cycle TEXT DEFAULT 'monthly', payment_ref TEXT, notes TEXT, contact_data TEXT,
                forename TEXT, lastname TEXT, company TEXT, email TEXT, phone TEXT, street TEXT, zip TEXT, city TEXT, country TEXT,
                provider TEXT, provider_checkout_id TEXT, paid_at TEXT, created_at TEXT, updated_at TEXT)");
            $this->sqlite->exec("CREATE TABLE cms_user_subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, plan_id INTEGER, status TEXT DEFAULT 'active',
                billing_cycle TEXT DEFAULT 'monthly', start_date TEXT, end_date TEXT, next_billing_date TEXT, cancelled_at TEXT,
                provider TEXT, provider_subscription_id TEXT, current_period_end TEXT)");
            $this->sqlite->exec("CREATE TABLE cms_payment_events (id INTEGER PRIMARY KEY AUTOINCREMENT, provider TEXT NOT NULL, provider_event_id TEXT NOT NULL,
                type TEXT, order_id INTEGER, status TEXT DEFAULT 'received', payload_hash TEXT, error TEXT, received_at TEXT, processed_at TEXT,
                UNIQUE (provider, provider_event_id))");
        }

        public function execute(string $sql, array $params = []): \PDOStatement
        {
            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute(array_values($params));

            return $stmt;
        }

        public function insert(string $table, array $data): int|bool
        {
            $keys = array_keys($data);
            try {
                $this->execute('INSERT INTO cms_' . $table . ' (' . implode(', ', $keys) . ') VALUES (' . implode(', ', array_fill(0, count($keys), '?')) . ')', array_values($data));
            } catch (\PDOException) {
                return false;
            }

            return (int) $this->sqlite->lastInsertId();
        }

        public function update(string $table, array $data, array $where): bool
        {
            $set = implode(', ', array_map(static fn (string $k): string => $k . ' = ?', array_keys($data)));
            $cond = implode(' AND ', array_map(static fn (string $k): string => $k . ' = ?', array_keys($where)));
            $this->execute('UPDATE cms_' . $table . ' SET ' . $set . ' WHERE ' . $cond, [...array_values($data), ...array_values($where)]);

            return true;
        }

        public function get_var(string $query, array $params = []): mixed
        {
            $value = $this->execute($query, $params)->fetchColumn();

            return $value === false ? null : $value;
        }

        public function get_results(string $query, array $params = []): array
        {
            return $this->execute($query, $params)->fetchAll(PDO::FETCH_OBJ);
        }

        public function get_row(string $query, array $params = []): ?object
        {
            return $this->execute($query, $params)->fetch(PDO::FETCH_OBJ) ?: null;
        }

        public function getPrefix(): string
        {
            return 'cms_';
        }
    };
}

function pySettings(array $overrides = []): PaymentSettings
{
    return new PaymentSettings($overrides + [
        'stripe_enabled' => '1', 'mollie_enabled' => '1', 'test_mode' => '1', 'recurring' => '1',
        'stripe_secret_test' => 'sk_test_abcdefghijklmnop', 'stripe_webhook_secret_test' => PY_WHSEC,
        'mollie_key_test' => 'test_abcdefghijklmnopqrstuvwxyz', 'return_key' => str_repeat('k', 64),
    ]);
}

/**
 * @return array{db: Database, service: P, calls: \ArrayObject, responses: \ArrayObject, assigned: \ArrayObject, clock: \ArrayObject}
 */
function pyFixture(array $settings = []): array
{
    $db = pyDatabase();
    $calls = new \ArrayObject();
    $responses = new \ArrayObject();
    $assigned = new \ArrayObject();
    $clock = new \ArrayObject(['now' => PY_NOW]);
    $transport = static function (string $method, string $url, string $body, array $headers) use ($calls, $responses): array {
        $calls[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];
        foreach ($responses as $prefix => $response) {
            if (str_starts_with($method . ' ' . $url, (string) $prefix)) {
                return $response;
            }
        }

        return ['status' => 404, 'body' => '{}'];
    };
    $config = pySettings($settings);
    $clockFn = static fn (): int => (int) $clock['now'];
    $service = new P(
        $db,
        $config,
        ['stripe' => new StripeGateway($config, $transport, $clockFn), 'mollie' => new MollieGateway($config, $transport)],
        $clockFn,
        static function (int $userId, int $planId, string $cycle) use ($db, $assigned): bool {
            $assigned[] = [$userId, $planId, $cycle];
            $db->insert('user_subscriptions', ['user_id' => $userId, 'plan_id' => $planId, 'status' => 'active', 'billing_cycle' => $cycle, 'start_date' => '2025-11-15 00:00:00']);

            return true;
        }
    );

    return ['db' => $db, 'service' => $service, 'calls' => $calls, 'responses' => $responses, 'assigned' => $assigned, 'clock' => $clock];
}

function pyOrder(Database $db, array $values = []): int
{
    return (int) $db->insert('orders', $values + [
        'order_number' => 'BST202511-0001', 'user_id' => 7, 'plan_id' => 3, 'customer_name' => 'Erika Muster', 'customer_email' => 'erika@example.com',
        'amount' => 10.08, 'tax_amount' => 1.91, 'total_amount' => 11.99, 'currency' => 'EUR', 'status' => 'pending', 'billing_cycle' => 'monthly',
        'created_at' => '2025-11-15 09:00:00',
    ]);
}

function pyStripeBody(string $id, string $type, array $object): string
{
    return (string) json_encode(['id' => $id, 'type' => $type, 'data' => ['object' => $object]]);
}

function pyStripeHeaders(string $body, int $ts = PY_NOW, string $secret = PY_WHSEC): array
{
    return ['stripe-signature' => 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $body, $secret)];
}

function pyOrderRow(Database $db, int $id): array
{
    return (array) $db->get_row('SELECT * FROM cms_orders WHERE id = ?', [$id]);
}

// ─────────────────────────────────────────────────────────────────────────────

T::run('Stripe-Signatur', static function (): void {
    $body = '{"id":"evt_1"}';
    $good = 't=' . PY_NOW . ',v1=' . hash_hmac('sha256', PY_NOW . '.' . $body, PY_WHSEC);
    T::check(StripeGateway::verifySignature($good, $body, PY_WHSEC, PY_NOW)['valid'], 'gültige Signatur');
    T::check(!StripeGateway::verifySignature($good, $body . ' ', PY_WHSEC, PY_NOW)['valid'], 'veränderter Körper → ungültig');
    T::check(!StripeGateway::verifySignature($good, $body, 'whsec_anderesgeheimnis99', PY_NOW)['valid'], 'falsches Geheimnis → ungültig');
    T::same('Signatur zu alt.', StripeGateway::verifySignature($good, $body, PY_WHSEC, PY_NOW + 301)['error'] ?? '', 'älter als 300 s → abgelehnt');
    T::check(StripeGateway::verifySignature($good, $body, PY_WHSEC, PY_NOW + 300)['valid'], 'genau 300 s → noch gültig');
    T::check(!StripeGateway::verifySignature($good, $body, PY_WHSEC, PY_NOW - 301)['valid'], 'Zeitstempel aus der Zukunft → abgelehnt');
    $multi = 't=' . PY_NOW . ',v1=' . str_repeat('0', 64) . ',v1=' . hash_hmac('sha256', PY_NOW . '.' . $body, PY_WHSEC) . ',v0=abc';
    T::check(StripeGateway::verifySignature($multi, $body, PY_WHSEC, PY_NOW)['valid'], 'mehrere v1 (Schlüsselwechsel): eine passt');
    T::check(!StripeGateway::verifySignature('v1=' . hash_hmac('sha256', PY_NOW . '.' . $body, PY_WHSEC), $body, PY_WHSEC, PY_NOW)['valid'], 'ohne t= → ungültig');
    T::check(!StripeGateway::verifySignature($good, $body, '', PY_NOW)['valid'], 'ohne Geheimnis → nie gültig');
    T::check(str_contains(pySource('core/Services/Payments/StripeGateway.php'), 'hash_equals($expected, $signature)'), 'Vergleich zeitkonstant');
});

T::run('Ereignis-Abbildung', static function (): void {
    $paid = StripeGateway::mapEvent(['id' => 'evt_a', 'type' => 'checkout.session.completed', 'data' => ['object' => [
        'id' => 'cs_1', 'payment_status' => 'paid', 'amount_total' => 1199, 'currency' => 'eur', 'metadata' => ['order_id' => '5'], 'subscription' => 'sub_9', 'payment_intent' => 'pi_1',
    ]]]);
    T::same(['paid', 5, 'cs_1', 'sub_9', 1199, 'EUR', 'pi_1'], [$paid['kind'], $paid['order_id'], $paid['checkout_id'], $paid['subscription_id'], $paid['amount_cents'], $paid['currency'], $paid['payment_ref']], 'Stripe: Checkout bezahlt');
    $unpaid = StripeGateway::mapEvent(['id' => 'evt_b', 'type' => 'checkout.session.completed', 'data' => ['object' => ['payment_status' => 'unpaid']]]);
    T::same('ignored', $unpaid['kind'], 'Stripe: Checkout abgeschlossen, aber noch unbezahlt (z. B. SEPA) → abwarten');
    T::same('failed', StripeGateway::mapEvent(['id' => 'e', 'type' => 'checkout.session.async_payment_failed', 'data' => ['object' => []]])['kind'], 'Stripe: asynchron fehlgeschlagen');
    T::same('cancelled', StripeGateway::mapEvent(['id' => 'e', 'type' => 'checkout.session.expired', 'data' => ['object' => []]])['kind'], 'Stripe: abgelaufen');
    $cycle = StripeGateway::mapEvent(['id' => 'evt_c', 'type' => 'invoice.paid', 'data' => ['object' => [
        'id' => 'in_1', 'billing_reason' => 'subscription_cycle', 'parent' => ['subscription_details' => ['subscription' => 'sub_9']],
        'amount_paid' => 1199, 'currency' => 'eur', 'lines' => ['data' => [['period' => ['end' => PY_NOW + 86400 * 60]]]],
    ]]]);
    T::same(['renewed', 'sub_9', PY_NOW + 86400 * 60], [$cycle['kind'], $cycle['subscription_id'], $cycle['period_end']], 'Stripe: Verlängerung mit Periodenende (neues Feld parent.subscription_details)');
    T::same('ignored', StripeGateway::mapEvent(['id' => 'e', 'type' => 'invoice.paid', 'data' => ['object' => ['billing_reason' => 'subscription_create', 'subscription' => 'sub_9']]])['kind'], 'Stripe: erste Abo-Rechnung kommt über den Checkout');
    T::same('subscription_ended', StripeGateway::mapEvent(['id' => 'e', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_9']]])['kind'], 'Stripe: Abo beendet');
    T::same('ignored', StripeGateway::mapEvent(['id' => 'e', 'type' => 'charge.refunded', 'data' => ['object' => []]])['kind'], 'Stripe: andere Ereignisse ignoriert');

    $mollie = MollieGateway::mapPayment(['id' => 'tr_abc1', 'status' => 'paid', 'amount' => ['value' => '11.99', 'currency' => 'EUR'], 'metadata' => ['order_id' => 5], 'customerId' => 'cst_x1']);
    T::same(['tr_abc1:paid', 'paid', 5, 1199, 'cst_x1'], [$mollie['event_id'], $mollie['kind'], $mollie['order_id'], $mollie['amount_cents'], $mollie['customer_id']], 'Mollie: Ereignis-ID je Status, Betrag aus Zeichenkette');
    T::same('renewed', MollieGateway::mapPayment(['id' => 'tr_abc2', 'status' => 'paid', 'subscriptionId' => 'sub_m1', 'amount' => ['value' => '11.99', 'currency' => 'EUR']])['kind'], 'Mollie: Folgezahlung eines Abos = Verlängerung');
    T::same(['cancelled', 'cancelled', 'ignored'], [
        MollieGateway::mapPayment(['id' => 'tr_a', 'status' => 'canceled'])['kind'],
        MollieGateway::mapPayment(['id' => 'tr_a', 'status' => 'expired'])['kind'],
        MollieGateway::mapPayment(['id' => 'tr_a', 'status' => 'open'])['kind'],
    ], 'Mollie: abgebrochen/abgelaufen, offen wird abgewartet');
    T::same([null, 1199, '12.00'], [MollieGateway::parseAmount('11.9'), MollieGateway::parseAmount('11.99'), MollieGateway::formatAmount(1200)], 'Mollie: Betragsformat streng');
});

T::run('Checkout', static function (): void {
    $f = pyFixture();
    $id = pyOrder($f['db']);
    $f['responses']['POST https://api.stripe.com/v1/checkout/sessions'] = ['status' => 200, 'body' => '{"id":"cs_test_1","url":"https://checkout.stripe.com/c/pay/cs_test_1"}'];
    $result = $f['service']->startCheckout($id, 'stripe', 'https://shop.example/', 'Pro (monatlich)');
    T::same(['success' => true, 'redirect_url' => 'https://checkout.stripe.com/c/pay/cs_test_1'], $result, 'Stripe: Weiterleitung zur Checkout-URL');
    parse_str((string) $f['calls'][0]['body'], $sent);
    T::same(['1199', 'eur', 'subscription', 'month', (string) $id], [
        $sent['line_items'][0]['price_data']['unit_amount'] ?? '', $sent['line_items'][0]['price_data']['currency'] ?? '', $sent['mode'] ?? '',
        $sent['line_items'][0]['price_data']['recurring']['interval'] ?? '', $sent['metadata']['order_id'] ?? '',
    ], 'Betrag, Währung, Abo-Modus und Zuordnung aus der Bestellung');
    T::check(str_starts_with((string) $sent['success_url'], 'https://shop.example/payments/return/BST202511-0001?sig='), 'Rücksprung signiert');
    T::check(in_array('Authorization: Bearer sk_test_abcdefghijklmnop', $f['calls'][0]['headers'], true), 'Test-Schlüssel im Testmodus');
    $row = pyOrderRow($f['db'], $id);
    T::same(['stripe', 'cs_test_1', 'stripe'], [$row['provider'], $row['provider_checkout_id'], $row['payment_method']], 'Checkout-ID gemerkt');

    $guest = pyOrder($f['db'], ['order_number' => 'BST-G', 'user_id' => null]);
    $f['service']->startCheckout($guest, 'stripe', 'https://shop.example');
    parse_str((string) $f['calls'][1]['body'], $sentGuest);
    T::same('payment', $sentGuest['mode'] ?? '', 'Gastbestellung: Einmalzahlung, keine automatische Verlängerung');

    $paidOrder = pyOrder($f['db'], ['order_number' => 'BST-P', 'status' => 'paid']);
    T::check(!$f['service']->startCheckout($paidOrder, 'stripe', 'https://shop.example')['success'], 'bezahlte Bestellung lässt sich nicht erneut bezahlen');
    T::check(!$f['service']->startCheckout($id, 'invoice', 'https://shop.example')['success'], 'Rechnung ist keine Online-Zahlart');

    $m = pyFixture();
    $mid = pyOrder($m['db']);
    $m['responses']['POST https://api.mollie.com/v2/customers'] = ['status' => 201, 'body' => '{"id":"cst_test1"}'];
    $m['responses']['POST https://api.mollie.com/v2/payments'] = ['status' => 201, 'body' => '{"id":"tr_test1","_links":{"checkout":{"href":"https://www.mollie.com/checkout/tr_test1"}}}'];
    T::check($m['service']->startCheckout($mid, 'paypal', 'https://shop.example')['success'], 'PayPal über Mollie');
    $payment = json_decode((string) $m['calls'][1]['body'], true);
    T::same(['11.99', 'EUR', 'paypal', 'first', 'cst_test1', 'https://shop.example/payments/webhook/mollie'], [
        $payment['amount']['value'], $payment['amount']['currency'], $payment['method'], $payment['sequenceType'], $payment['customerId'], $payment['webhookUrl'],
    ], 'Mollie: Betrag als Zeichenkette, Erstzahlung mit Kunde (Mandat), Webhook-URL');

    $off = pyFixture(['stripe_enabled' => '0', 'mollie_key_test' => '']);
    T::same(['invoice'], $off['service']->filterMethods(['invoice', 'stripe', 'mollie', 'paypal']), 'Checkout bietet nur eingerichtete Anbieter an');
});

T::run('Webhook: Prüfung, Idempotenz, Betrag', static function (): void {
    $f = pyFixture();
    $id = pyOrder($f['db'], ['provider' => 'stripe', 'provider_checkout_id' => 'cs_1']);
    $object = ['id' => 'cs_1', 'payment_status' => 'paid', 'amount_total' => 1199, 'currency' => 'eur', 'metadata' => ['order_id' => (string) $id], 'subscription' => 'sub_1', 'payment_intent' => 'pi_1'];
    $body = pyStripeBody('evt_paid', 'checkout.session.completed', $object);
    $fired = new \ArrayObject();
    Hooks::addAction('cms_payment_succeeded', static function (int $orderId) use ($fired): void {
        $fired[] = $orderId;
    });

    T::same(400, $f['service']->handleWebhook('stripe', ['stripe-signature' => 't=' . PY_NOW . ',v1=' . str_repeat('a', 64)], $body)['status'], 'falsche Signatur → 400, nichts verarbeitet');
    T::same('pending', pyOrderRow($f['db'], $id)['status'], 'Bestellung bleibt offen');
    T::same(413, $f['service']->handleWebhook('stripe', [], str_repeat('x', P::MAX_WEBHOOK_BYTES + 1))['status'], 'zu großer Körper → 413');
    T::same(404, $f['service']->handleWebhook('paypal', [], '{}')['status'], 'unbekannter Anbieter → 404');

    T::same(200, $f['service']->handleWebhook('stripe', pyStripeHeaders($body), $body)['status'], 'gültiges Ereignis → 200');
    $row = pyOrderRow($f['db'], $id);
    T::same(['paid', 'pi_1'], [$row['status'], $row['payment_ref']], 'Bestellung bezahlt');
    T::same([[7, 3, 'monthly']], $f['assigned']->getArrayCopy(), 'Abo zugewiesen');
    $sub = (array) $f['db']->get_row('SELECT * FROM cms_user_subscriptions WHERE user_id = 7');
    T::same(['stripe', 'sub_1', gmdate('Y-m-d H:i:s', (int) P::periodEnd(PY_NOW, 'monthly'))], [$sub['provider'], $sub['provider_subscription_id'], $sub['current_period_end']], 'Anbieter-Abo und Periodenende gespeichert');

    T::same(200, $f['service']->handleWebhook('stripe', pyStripeHeaders($body), $body)['status'], 'Wiederholung → 200');
    T::same(1, (int) $f['db']->get_var('SELECT COUNT(*) FROM cms_payment_events'), 'Ereignis nur einmal gespeichert');
    T::same(1, count($f['assigned']), 'keine zweite Zuweisung');
    T::same([$id], $fired->getArrayCopy(), 'cms_payment_succeeded genau einmal');

    $other = pyOrder($f['db'], ['order_number' => 'BST-2', 'provider' => 'stripe', 'provider_checkout_id' => 'cs_2']);
    $cheap = pyStripeBody('evt_cheap', 'checkout.session.completed', ['id' => 'cs_2', 'payment_status' => 'paid', 'amount_total' => 100, 'currency' => 'eur', 'metadata' => ['order_id' => (string) $other]]);
    $f['service']->handleWebhook('stripe', pyStripeHeaders($cheap), $cheap);
    $event = (array) $f['db']->get_row("SELECT * FROM cms_payment_events WHERE provider_event_id = 'evt_cheap'");
    T::same(['failed', 'pending'], [$event['status'], pyOrderRow($f['db'], $other)['status']], 'Betragsabweichung → Ereignis failed, Bestellung bleibt offen');
    T::check(str_contains((string) $event['error'], 'erwartet 1199 EUR'), 'Fehler nennt den erwarteten Betrag');
    $usd = pyStripeBody('evt_usd', 'checkout.session.completed', ['id' => 'cs_2', 'payment_status' => 'paid', 'amount_total' => 1199, 'currency' => 'usd', 'metadata' => ['order_id' => (string) $other]]);
    $f['service']->handleWebhook('stripe', pyStripeHeaders($usd), $usd);
    T::same('pending', pyOrderRow($f['db'], $other)['status'], 'falsche Währung → nicht bezahlt');
    $wrongSession = pyStripeBody('evt_ws', 'checkout.session.completed', ['id' => 'cs_fremd', 'payment_status' => 'paid', 'amount_total' => 1199, 'currency' => 'eur', 'metadata' => ['order_id' => (string) $other]]);
    $f['service']->handleWebhook('stripe', pyStripeHeaders($wrongSession), $wrongSession);
    T::same('pending', pyOrderRow($f['db'], $other)['status'], 'fremde Checkout-Session → nicht bezahlt');

    $late = pyStripeBody('evt_late', 'checkout.session.expired', ['id' => 'cs_1', 'metadata' => ['order_id' => (string) $id]]);
    $f['service']->handleWebhook('stripe', pyStripeHeaders($late), $late);
    T::same('paid', pyOrderRow($f['db'], $id)['status'], 'kein Rücksprung von paid zu cancelled');
});

T::run('Mollie-Abrufpfad', static function (): void {
    $f = pyFixture();
    $id = pyOrder($f['db'], ['provider' => 'mollie', 'provider_checkout_id' => 'tr_WDqYK6vllg', 'user_id' => 9]);
    T::same(400, $f['service']->handleWebhook('mollie', [], 'id=evil')['status'], 'ungültige ID → 400 ohne Abruf');
    T::same(0, count($f['calls']), 'kein Abruf für ungültige IDs');
    $f['responses']['GET https://api.mollie.com/v2/payments/tr_WDqYK6vllg'] = ['status' => 200, 'body' => (string) json_encode([
        'id' => 'tr_WDqYK6vllg', 'status' => 'paid', 'amount' => ['value' => '11.99', 'currency' => 'EUR'], 'metadata' => ['order_id' => $id],
        'sequenceType' => 'first', 'customerId' => 'cst_8wmqcHMN4U',
    ])];
    $f['responses']['POST https://api.mollie.com/v2/customers/cst_8wmqcHMN4U/subscriptions'] = ['status' => 201, 'body' => '{"id":"sub_m1"}'];
    T::same(200, $f['service']->handleWebhook('mollie', [], 'id=tr_WDqYK6vllg')['status'], 'Webhook mit ID → Abruf → 200');
    T::same('GET https://api.mollie.com/v2/payments/tr_WDqYK6vllg', $f['calls'][0]['method'] . ' ' . $f['calls'][0]['url'], 'Wahrheit kommt aus dem Abruf beim Anbieter');
    T::same('paid', pyOrderRow($f['db'], $id)['status'], 'bezahlt');
    $subscription = json_decode((string) $f['calls'][1]['body'], true);
    T::same(['1 month', gmdate('Y-m-d', (int) P::periodEnd(PY_NOW, 'monthly')), 'https://shop.example/payments/webhook/mollie'], [$subscription['interval'], $subscription['startDate'], $subscription['webhookUrl']], 'Mollie-Abo ab der nächsten Periode');
    T::same('sub_m1', (string) $f['db']->get_var('SELECT provider_subscription_id FROM cms_user_subscriptions WHERE user_id = 9'), 'Mollie-Abo-ID gespeichert');
    $f['responses']['GET https://api.mollie.com/v2/payments/tr_404notfound'] = ['status' => 404, 'body' => '{"detail":"Not found"}'];
    T::same(400, $f['service']->handleWebhook('mollie', [], 'id=tr_404notfound')['status'], 'unbekannte Zahlung beim Anbieter → 400');
});

T::run('Verlängerung und Ende', static function (): void {
    $f = pyFixture();
    $id = pyOrder($f['db'], ['provider' => 'stripe', 'provider_checkout_id' => 'cs_1']);
    $paid = pyStripeBody('evt_p', 'checkout.session.completed', ['id' => 'cs_1', 'payment_status' => 'paid', 'amount_total' => 1199, 'currency' => 'eur', 'metadata' => ['order_id' => (string) $id], 'subscription' => 'sub_1']);
    $f['service']->handleWebhook('stripe', pyStripeHeaders($paid), $paid);
    $renewed = new \ArrayObject();
    Hooks::addAction('cms_subscription_renewed', static function (int $subId, int $userId, int $orderId, int $end) use ($renewed): void {
        $renewed[] = [$userId, $orderId, $end];
    });
    $f['clock']['now'] = PY_NOW + 86400 * 30;
    $end = PY_NOW + 86400 * 61;
    $cycle = pyStripeBody('evt_r', 'invoice.paid', ['id' => 'in_2', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_1', 'amount_paid' => 1199, 'currency' => 'eur',
        'lines' => ['data' => [['period' => ['end' => $end]]]]]);
    $f['service']->handleWebhook('stripe', pyStripeHeaders($cycle, PY_NOW + 86400 * 30), $cycle);
    $sub = (array) $f['db']->get_row('SELECT * FROM cms_user_subscriptions WHERE provider_subscription_id = ?', ['sub_1']);
    T::same([gmdate('Y-m-d H:i:s', $end), gmdate('Y-m-d H:i:s', $end), 'active'], [$sub['current_period_end'], $sub['end_date'], $sub['status']], 'Verlängerung setzt current_period_end und end_date');
    $copy = (array) $f['db']->get_row("SELECT * FROM cms_orders WHERE order_number LIKE 'BST202511-0001-V%'");
    T::same(['paid', 'in_2', '11.99'], [$copy['status'] ?? '', $copy['payment_ref'] ?? '', number_format((float) ($copy['total_amount'] ?? 0), 2, '.', '')], 'bezahlte Folgebestellung für die Rechnung');
    T::same([[7, (int) ($copy['id'] ?? 0), $end]], $renewed->getArrayCopy(), 'cms_subscription_renewed mit Folgebestellung und Periodenende');

    $unknown = pyStripeBody('evt_u', 'invoice.paid', ['id' => 'in_3', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_fremd', 'amount_paid' => 1199, 'currency' => 'eur']);
    $f['service']->handleWebhook('stripe', pyStripeHeaders($unknown, PY_NOW + 86400 * 30), $unknown);
    T::same('failed', (string) $f['db']->get_var("SELECT status FROM cms_payment_events WHERE provider_event_id = 'evt_u'"), 'Verlängerung eines unbekannten Abos → failed');

    $deleted = pyStripeBody('evt_d', 'customer.subscription.deleted', ['id' => 'sub_1']);
    $f['service']->handleWebhook('stripe', pyStripeHeaders($deleted, PY_NOW + 86400 * 30), $deleted);
    T::same('cancelled', (string) $f['db']->get_var('SELECT status FROM cms_user_subscriptions WHERE provider_subscription_id = ?', ['sub_1']), 'Abo beim Anbieter beendet → gekündigt');
});

T::run('Zustandsautomat, Laufzeit, Rücksprung', static function (): void {
    T::same([true, true, true, false, true, false, false, false], [
        P::canTransition('pending', 'paid'), P::canTransition('pending', 'failed'), P::canTransition('failed', 'paid'),
        P::canTransition('paid', 'pending'), P::canTransition('paid', 'refunded'), P::canTransition('paid', 'cancelled'),
        P::canTransition('refunded', 'paid'), P::canTransition('cancelled', 'paid'),
    ], 'pending → paid|failed|cancelled, failed → paid, paid → refunded, keine Rücksprünge');
    T::same([gmdate('Y-m-d', strtotime('2026-02-28 12:00:00 UTC')), null], [gmdate('Y-m-d', (int) P::periodEnd(strtotime('2026-01-28 12:00:00 UTC'), 'monthly')), P::periodEnd(PY_NOW, 'lifetime')], 'Periodenende monatlich, lifetime ohne Ende');
    $f = pyFixture();
    pyOrder($f['db']);
    $order = (array) $f['db']->get_row('SELECT * FROM cms_orders LIMIT 1');
    $sig = $f['service']->returnSignature($order);
    T::check($f['service']->orderForReturn('BST202511-0001', $sig) !== null, 'signierter Rücksprung zeigt die Bestellung');
    T::same(null, $f['service']->orderForReturn('BST202511-0001', substr($sig, 0, -1) . (substr($sig, -1) === 'a' ? 'b' : 'a')), 'veränderte Signatur → nichts');
    T::same(null, $f['service']->orderForReturn('BST202511-0001', ''), 'ohne Signatur → nichts');
});

T::run('Einstellungen', static function (): void {
    $writes = [];
    $writer = static function (string $key, string $value, bool $secret) use (&$writes): void {
        $writes[$key] = [$value, $secret];
    };
    $empty = new PaymentSettings([]);
    T::check($empty->testMode(), 'ohne Einstellung gilt der Testmodus');
    $bad = PaymentSettings::save(['stripe_enabled' => '1', 'test_mode' => '1', 'stripe_secret_test' => 'sk_live_abcdefghijklmnop'], $empty, $writer);
    T::check(!$bad['success'] && $writes === [], 'Live-Schlüssel im Test-Feld → abgelehnt, nichts gespeichert');
    $missing = PaymentSettings::save(['stripe_enabled' => '1', 'test_mode' => '1', 'stripe_secret_test' => 'sk_test_abcdefghijklmnop'], $empty, $writer);
    T::check(!$missing['success'] && str_contains(implode(' ', $missing['details'] ?? []), 'Webhook-Geheimnis (Test)'), 'eingeschaltet ohne Webhook-Geheimnis → abgelehnt');
    $ok = PaymentSettings::save(['stripe_enabled' => '1', 'test_mode' => '1', 'stripe_secret_test' => '', 'stripe_webhook_secret_test' => PY_WHSEC], pySettings(), $writer);
    T::check($ok['success'], 'leeres Schlüsselfeld behält den gespeicherten Wert');
    T::same([PY_WHSEC, true], $writes['stripe_webhook_secret_test'] ?? null, 'Geheimnisse verschlüsselt gespeichert');
    T::check(!isset($writes['stripe_secret_test']), 'leeres Feld überschreibt nichts');
    T::same(['1', false], $writes['stripe_enabled'] ?? null, 'Schalter im Klartext');
});

T::run('Einbindung', static function (): void {
    $router = pySource('core/Router.php');
    T::check(str_contains($router, "'/payments/webhook/']") && str_contains($router, "loadRouteModule('PaymentRouter')"), 'Router: Webhook ohne CSRF, Modul geladen');
    T::check(str_contains($router, "str_starts_with(\$routingUri, '/payments/webhook/')"), 'Webhook auch im Wartungsmodus');
    $paymentRouter = pySource('core/Routing/PaymentRouter.php');
    T::check(str_contains($paymentRouter, "checkDbRateLimit(\$ip, 'payment_webhook'") && str_contains($paymentRouter, 'MAX_WEBHOOK_BYTES + 1'), 'Rate-Limit und Größenlimit an der Route');
    $orders = pySource('orders.php');
    T::check(str_contains($orders, 'filterMethods($paymentMethods)') && str_contains($orders, '->startCheckout('), 'Checkout nutzt den Zahlungsdienst');
    T::check(!str_contains($orders, "\$_POST['amount']") && !str_contains($orders, "\$_POST['total"), 'keine Beträge aus dem Formular');
    $schema = pySource('core/SchemaManager.php');
    T::check(str_contains($schema, 'UNIQUE KEY uq_payment_events_event (provider, provider_event_id)'), 'Schema: Ereignis eindeutig');
    T::check(str_contains($schema, "'provider_checkout_id' => 'VARCHAR(255) DEFAULT NULL'") && str_contains($schema, "'current_period_end' => 'DATETIME DEFAULT NULL'"), 'Schema: Spalten für bestehende Installationen');
    T::check(str_contains(pySource('core/Services/AdminMenu/CoreCatalog.php'), "'id' => 'subscriptions.payments'"), 'Menüeintrag Zahlungen');
    T::check(str_contains(pySource('core/Services/FeatureHooks/WebhookHooks.php'), "self::dispatch('order.paid'"), 'Webhook-Ereignis order.paid');
    $view = pySource('admin/views/subscriptions/payments.php');
    T::check(str_contains($view, 'name="csrf_token"') && !preg_match('/\son[a-z]+=|<script/i', $view), 'Admin: CSRF, keine Inline-Skripte');
    T::check(!preg_match('/value="<\?= \$pyE\(\$pySecrets/', $view) && str_contains($view, 'type="password"'), 'Admin: Schlüssel werden nie ausgegeben');
});

T::finish();
