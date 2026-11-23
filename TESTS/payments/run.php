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

// Gegenprobe-Haken (TESTS/lib/Mutant.php, Audit 4.2.0): mit CMS_TEST_MUTANT=<name> lädt der Autoloader eine Mutante.
require_once dirname(__DIR__) . '/lib/Mutant.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        Mutant::load($file);
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

        public function getPdo(): PDO
        {
            return $this->sqlite;
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
    // FUN-410-20: Eine spätere Verlängerung reaktiviert das gekündigte Abo nicht.
    $late = pyStripeBody('evt_late_r', 'invoice.paid', ['id' => 'in_9', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_1', 'amount_paid' => 1199, 'currency' => 'eur']);
    $f['service']->handleWebhook('stripe', pyStripeHeaders($late, PY_NOW + 86400 * 30), $late);
    T::same('cancelled', (string) $f['db']->get_var('SELECT status FROM cms_user_subscriptions WHERE provider_subscription_id = ?', ['sub_1']), 'Verlängerung nach Kündigung bleibt gekündigt');
    T::same('failed', (string) $f['db']->get_var("SELECT status FROM cms_payment_events WHERE provider_event_id = 'evt_late_r'"), 'und steht als failed am Ereignis');
});

T::run('Audit 4.1.0: atomarer Zustandswechsel, Wiederholung nach Fehler, Verlängerungsbetrag', static function (): void {
    // SEC-410-03: zweites „bezahlt“-Ereignis (andere Ereignis-ID) aktiviert das Abo nicht erneut.
    $f = pyFixture();
    $id = pyOrder($f['db'], ['provider' => 'stripe', 'provider_checkout_id' => 'cs_1']);
    $object = ['id' => 'cs_1', 'payment_status' => 'paid', 'amount_total' => 1199, 'currency' => 'eur', 'metadata' => ['order_id' => (string) $id], 'subscription' => 'sub_1'];
    $first = pyStripeBody('evt_a', 'checkout.session.completed', $object);
    $second = pyStripeBody('evt_b', 'checkout.session.async_payment_succeeded', $object);
    $f['service']->handleWebhook('stripe', pyStripeHeaders($first), $first);
    $f['service']->handleWebhook('stripe', pyStripeHeaders($second), $second);
    T::same(1, count($f['assigned']), 'SEC-410-03: zweites Bezahlt-Ereignis weist kein zweites Abo zu');
    T::same('ignored', (string) $f['db']->get_var("SELECT status FROM cms_payment_events WHERE provider_event_id = 'evt_b'"), 'zweites Ereignis steht auf ignored');
    T::check(str_contains(pySource('core/Services/Payments/PaymentService.php'), "WHERE id = ? AND status IN ('pending', 'failed')"), 'Statuswechsel nur aus pending/failed (bedingtes UPDATE)');

    // SEC-410-04: interner Fehler → 500, nichts verbucht, Wiederholung verarbeitet neu.
    $db = pyDatabase();
    $config = pySettings();
    $fail = new \ArrayObject(['on' => true]);
    $assigned = new \ArrayObject();
    $service = new P($db, $config, ['stripe' => new StripeGateway($config, static fn (): array => ['status' => 404, 'body' => '{}'], static fn (): int => PY_NOW)], static fn (): int => PY_NOW,
        static function (int $userId, int $planId, string $cycle) use ($fail, $assigned, $db): bool {
            if ($fail['on']) {
                throw new \RuntimeException('DB weg');
            }
            $assigned[] = $userId;
            $db->insert('user_subscriptions', ['user_id' => $userId, 'plan_id' => $planId, 'status' => 'active', 'billing_cycle' => $cycle]);

            return true;
        });
    $orderId = pyOrder($db, ['provider' => 'stripe', 'provider_checkout_id' => 'cs_9']);
    $body = pyStripeBody('evt_retry', 'checkout.session.completed', ['id' => 'cs_9', 'payment_status' => 'paid', 'amount_total' => 1199, 'currency' => 'eur', 'metadata' => ['order_id' => (string) $orderId]]);
    T::same(500, $service->handleWebhook('stripe', pyStripeHeaders($body), $body)['status'], 'SEC-410-04: interner Fehler → 500 (Anbieter wiederholt)');
    T::same('pending', pyOrderRow($db, $orderId)['status'], 'Transaktion zurückgerollt: Bestellung bleibt offen');
    T::same(0, (int) $db->get_var('SELECT COUNT(*) FROM cms_payment_events'), 'Ereignis nicht als erledigt gespeichert');
    $fail['on'] = false;
    T::same(200, $service->handleWebhook('stripe', pyStripeHeaders($body), $body)['status'], 'Wiederholung → 200');
    T::same(['paid', 1], [pyOrderRow($db, $orderId)['status'], count($assigned)], 'Wiederholung bezahlt die Bestellung und weist das Abo zu');

    // SEC-410-05: Verlängerung mit abweichender Währung steht sichtbar am Ereignis.
    $f['clock']['now'] = PY_NOW + 86400 * 30;
    $usd = pyStripeBody('evt_usd_r', 'invoice.paid', ['id' => 'in_9', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_1', 'amount_paid' => 1199, 'currency' => 'usd']);
    $f['service']->handleWebhook('stripe', pyStripeHeaders($usd, PY_NOW + 86400 * 30), $usd);
    $event = (array) $f['db']->get_row("SELECT status, error FROM cms_payment_events WHERE provider_event_id = 'evt_usd_r'");
    T::check(($event['status'] ?? '') === 'processed' && str_contains((string) ($event['error'] ?? ''), 'abweichendem Betrag'), 'SEC-410-05: Verlängerung verbucht, Abweichung am Ereignis vermerkt');
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

T::run('Release-Prüfung 4.1.0: Erstattung, Rückbuchung, Kündigung beim Anbieter (FUN-410-21, FUN-410-27)', static function (): void {
    // Abbildung der Ereignisse
    $full = StripeGateway::mapEvent(['id' => 'evt_rf', 'type' => 'charge.refunded', 'data' => ['object' => ['refunded' => true, 'amount_refunded' => 1199, 'payment_intent' => 'pi_1', 'currency' => 'eur']]]);
    $partial = StripeGateway::mapEvent(['id' => 'evt_rp', 'type' => 'charge.refunded', 'data' => ['object' => ['refunded' => false, 'amount_refunded' => 500, 'payment_intent' => 'pi_1']]]);
    $dispute = StripeGateway::mapEvent(['id' => 'evt_dp', 'type' => 'charge.dispute.created', 'data' => ['object' => ['amount' => 1199, 'payment_intent' => 'pi_1']]]);
    T::same(['refunded', 'ignored', 'refunded', 'chargeback'], [$full['kind'], $partial['kind'], $dispute['kind'], $dispute['reason'] ?? ''], 'Stripe: volle Erstattung und Dispute → refunded, Teilerstattung ignoriert');
    $mPaid = MollieGateway::mapPayment(['id' => 'tr_abcd1', 'status' => 'paid', 'amount' => ['value' => '11.99', 'currency' => 'EUR']]);
    $mRefund = MollieGateway::mapPayment(['id' => 'tr_abcd1', 'status' => 'paid', 'amount' => ['value' => '11.99', 'currency' => 'EUR'], 'amountRefunded' => ['value' => '11.99', 'currency' => 'EUR']]);
    $mBack = MollieGateway::mapPayment(['id' => 'tr_abcd1', 'status' => 'paid', 'amount' => ['value' => '11.99', 'currency' => 'EUR'], 'amountChargedBack' => ['value' => '11.99', 'currency' => 'EUR']]);
    T::same(['paid', 'refunded', 'refunded'], [$mPaid['kind'], $mRefund['kind'], $mBack['kind']], 'Mollie: amountRefunded/amountChargedBack → refunded');
    T::check($mPaid['event_id'] !== $mRefund['event_id'] && $mRefund['event_id'] !== $mBack['event_id'], 'Mollie: eigene Ereignis-IDs, keine Verwechslung mit dem Zahlungseingang');

    // Erstattung über den Webhook: Bestellung refunded, Abo beendet, Anbieter-Abo gelöscht
    $f = pyFixture();
    $id = pyOrder($f['db'], ['provider' => 'stripe', 'provider_checkout_id' => 'cs_r']);
    $paid = pyStripeBody('evt_pr', 'checkout.session.completed', ['id' => 'cs_r', 'payment_status' => 'paid', 'amount_total' => 1199, 'currency' => 'eur', 'metadata' => ['order_id' => (string) $id], 'subscription' => 'sub_rfund1', 'payment_intent' => 'pi_r1']);
    $f['service']->handleWebhook('stripe', pyStripeHeaders($paid), $paid);
    T::same('paid', pyOrderRow($f['db'], $id)['status'], 'Ausgangslage: bezahlt');
    $refunded = new \ArrayObject();
    Hooks::addAction('cms_payment_refunded', static function (int $orderId) use ($refunded): void {
        $refunded[] = $orderId;
    });
    $f['responses']['DELETE https://api.stripe.com/v1/subscriptions/sub_rfund1'] = ['status' => 200, 'body' => '{"id":"sub_rfund1","status":"canceled"}'];
    $rf = pyStripeBody('evt_rf1', 'charge.refunded', ['refunded' => true, 'amount_refunded' => 1199, 'payment_intent' => 'pi_r1', 'currency' => 'eur']);
    $f['service']->handleWebhook('stripe', pyStripeHeaders($rf), $rf);
    T::same('refunded', pyOrderRow($f['db'], $id)['status'], 'Erstattung → Bestellung refunded');
    $sub = (array) $f['db']->get_row('SELECT * FROM cms_user_subscriptions WHERE user_id = 7 ORDER BY id DESC LIMIT 1');
    T::same('cancelled', $sub['status'] ?? '', 'Erstattung beendet das Abo');
    $deletes = array_values(array_filter($f['calls']->getArrayCopy(), static fn (array $c): bool => $c['method'] === 'DELETE'));
    T::same(['https://api.stripe.com/v1/subscriptions/sub_rfund1'], array_column($deletes, 'url'), 'Anbieter-Abo wird gelöscht (keine weitere Abbuchung)');
    T::same([$id], $refunded->getArrayCopy(), 'Hook cms_payment_refunded');
    $f['service']->handleWebhook('stripe', pyStripeHeaders($rf), $rf);
    T::same(1, count(array_filter($f['calls']->getArrayCopy(), static fn (array $c): bool => $c['method'] === 'DELETE')), 'Wiederholtes Ereignis wirkt nicht doppelt');

    // Kündigung durch das Mitglied: Stripe zum Periodenende, Mollie über die Kunden-ID aus der Zahlung
    $g = pyFixture();
    $g['db']->insert('user_subscriptions', ['user_id' => 9, 'plan_id' => 3, 'status' => 'active', 'end_date' => '2099-01-01 00:00:00', 'provider' => 'stripe', 'provider_subscription_id' => 'sub_mem1']);
    $g['responses']['POST https://api.stripe.com/v1/subscriptions/sub_mem1'] = ['status' => 200, 'body' => '{"id":"sub_mem1","cancel_at_period_end":true}'];
    $result = $g['service']->cancelMemberSubscription(9);
    $row = (array) $g['db']->get_row('SELECT * FROM cms_user_subscriptions WHERE user_id = 9');
    T::check(!empty($result['success']) && !empty($row['cancelled_at']) && $row['status'] === 'active', 'Mitglied kündigt: bleibt bis end_date aktiv, cancelled_at gesetzt');
    $last = $g['calls'][count($g['calls']) - 1];
    T::same(['POST', 'cancel_at_period_end=true'], [$last['method'], $last['body']], 'Stripe: cancel_at_period_end');
    T::same('Das Abo ist bereits gekündigt.', (string) ($g['service']->cancelMemberSubscription(9)['message'] ?? ''), 'zweite Kündigung ohne erneuten Anbieteraufruf');

    $g['db']->insert('user_subscriptions', ['user_id' => 11, 'plan_id' => 3, 'status' => 'active', 'end_date' => '2099-01-01 00:00:00', 'provider' => 'mollie', 'provider_subscription_id' => 'sub_moll1']);
    pyOrder($g['db'], ['order_number' => 'BST-M1', 'user_id' => 11, 'provider' => 'mollie', 'status' => 'paid', 'payment_ref' => 'tr_pay11']);
    $g['responses']['GET https://api.mollie.com/v2/payments/tr_pay11'] = ['status' => 200, 'body' => '{"id":"tr_pay11","customerId":"cst_kunde1"}'];
    $g['responses']['DELETE https://api.mollie.com/v2/customers/cst_kunde1/subscriptions/sub_moll1'] = ['status' => 200, 'body' => '{"id":"sub_moll1","status":"canceled"}'];
    T::check(!empty($g['service']->cancelMemberSubscription(11)['success']), 'Mollie: Kündigung über die Kunden-ID der letzten Zahlung');
    $failed = new MollieGateway(pySettings(), static fn (): array => ['status' => 500, 'body' => '{"detail":"Fehler"}']);
    T::check(empty($failed->cancelRecurring('sub_moll1', ['customer_id' => 'cst_kunde1'])['success']), 'Fehler beim Anbieter wird gemeldet');
    T::check(!empty((new StripeGateway(pySettings(), static fn (): array => ['status' => 404, 'body' => '{}'], static fn (): int => PY_NOW))->cancelRecurring('sub_weg1')['success']), 'bereits gelöschtes Stripe-Abo gilt als beendet');

    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/CMS/core/SubscriptionManager.php');
    T::check(str_contains($src, 'PaymentService::getInstance()->cancelRecurring($row, false);') && str_contains($src, 'PaymentService::$assigningProviderSubscriptionId'), 'Tarifwechsel beendet das alte Anbieter-Abo, nicht das neue');
    $member = (string) file_get_contents(dirname(__DIR__, 2) . '/CMS/member/subscription.php');
    T::check(str_contains($member, "verifyCsrf('subscription_cancel')") && str_contains($member, 'name="confirm_cancel"') && !str_contains($member, 'onsubmit='), 'Mitglieder-Kündigung: CSRF, Bestätigung, ohne Inline-Skript (CSP)');
});

T::run('FUN-410-38: fehlgeschlagene Verlängerung nennt die Bestellung', static function (): void {
    $f = pyFixture();
    $id = pyOrder($f['db'], ['provider' => 'stripe', 'provider_checkout_id' => 'cs_rf38']);
    $paid = pyStripeBody('evt_p38', 'checkout.session.completed', ['id' => 'cs_rf38', 'payment_status' => 'paid', 'amount_total' => 1199, 'currency' => 'eur', 'metadata' => ['order_id' => (string) $id], 'subscription' => 'sub_fail38']);
    $f['service']->handleWebhook('stripe', pyStripeHeaders($paid), $paid);
    $seen = new \ArrayObject();
    Hooks::addAction('cms_payment_failed', static function (int $orderId, string $provider, array $event) use ($seen): void {
        $seen[] = [$orderId, !empty($event['renewal'])];
    });
    $fail = pyStripeBody('evt_f38', 'invoice.payment_failed', ['id' => 'in_f38', 'subscription' => 'sub_fail38', 'amount_due' => 1199, 'amount_paid' => 0, 'currency' => 'eur']);
    $f['service']->handleWebhook('stripe', pyStripeHeaders($fail), $fail);
    T::same([[$id, true]], $seen->getArrayCopy(), 'cms_payment_failed mit der Bestellung des Abos und renewal=true');
});

T::run('Audit 4.2.0: Bestellnummer aus der echten ID (DB-420-01)', static function (): void {
    $ts = 1763200000; // 2025-11-15
    $fixed = static fn (int $n): string => str_repeat("\xAB", $n);
    T::same('BST' . date('Ym', $ts) . '-0042', P::formatOrderNumber('BST{Y}{M}-{ID}', 42, $ts, $fixed), 'Standardformat mit der echten ID');
    T::same('BST' . date('Ym', $ts) . '-123456', P::formatOrderNumber('BST{Y}{M}-{ID}', 123456, $ts, $fixed), 'lange IDs werden nicht gekürzt');
    T::same('X' . date('d', $ts) . 'ABAB-0042', P::formatOrderNumber('X{D}{R}', 42, $ts, $fixed), 'ohne {ID} wird -{ID} angehängt; {R} aus random_bytes');
    T::check(P::formatOrderNumber('BST{Y}{M}-{ID}', 7, $ts) !== P::formatOrderNumber('BST{Y}{M}-{ID}', 8, $ts), 'verschiedene IDs ergeben verschiedene Nummern');
    T::same('BST' . date('Ym', $ts) . '-0042', P::formatOrderNumber("<b>{ID}\n", 42, $ts, $fixed), 'unzulässige Zeichen: Rückfall auf das Standardformat');
    T::same('BST' . date('Ym', $ts) . '-0042', P::formatOrderNumber(str_repeat('A', 60) . '{ID}', 42, $ts, $fixed), 'zu lang (Spalte 64, Verlängerung -Vxxxxxx): Rückfall');
    T::same('BST' . date('Ym', $ts) . '-0042', P::formatOrderNumber('   ', 42, $ts, $fixed), 'leeres Format: Rückfall');
    $tmp = P::provisionalOrderNumber($fixed);
    T::same('TMP-' . str_repeat('ab', 16), $tmp, 'vorläufige Nummer: TMP- und 32 Hex-Zeichen');
    T::check(P::provisionalOrderNumber() !== P::provisionalOrderNumber(), 'vorläufige Nummern sind zufällig');

    // Ablauf wie orders.php gegen eine Tabelle mit eindeutiger order_number: zwei Bestellungen hintereinander.
    $db = pyDatabase();
    $numbers = [];
    foreach ([1, 2] as $unused) {
        $id = $db->insert('orders', ['order_number' => P::provisionalOrderNumber(), 'plan_id' => 3, 'status' => 'pending']);
        T::check(is_int($id) && $id > 0, 'Einfügen mit vorläufiger Nummer gelingt');
        $final = P::formatOrderNumber('BST{Y}{M}-{ID}', (int) $id, $ts);
        $db->update('orders', ['order_number' => $final], ['id' => (int) $id]);
        $numbers[] = (string) $db->get_var('SELECT order_number FROM cms_orders WHERE id = ?', [(int) $id]);
    }
    T::same(['BST' . date('Ym', $ts) . '-0001', 'BST' . date('Ym', $ts) . '-0002'], $numbers, 'zweite Bestellung scheitert nicht an der eindeutigen Nummer');

    $orders = pySource('orders.php');
    T::check(!str_contains($orders, 'FROM information_schema') && !str_contains($orders, 'uniqid('), 'orders.php: keine Nummer aus information_schema oder uniqid()');
    T::check(str_contains($orders, 'PaymentService::provisionalOrderNumber()') && str_contains($orders, 'PaymentService::formatOrderNumber($format, (int) $orderId, time())'), 'orders.php: vorläufige Nummer, dann endgültige aus der ID');
    T::check(preg_match('/if \(!\$success\) \{\s*\/\/[^\n]*\n\s*\$error = /', $orders) === 1, 'orders.php: gescheitertes Einfügen zeigt eine Fehlermeldung');
});

T::run('Audit 4.2.0: Formularfelder des Checkouts (SEC-420-01)', static function (): void {
    $orders = pySource('orders.php');
    T::check(!preg_match('/trim\(\$_POST\[/', $orders) && !preg_match('/htmlspecialchars\(\$_POST\[/', $orders), 'kein trim()/htmlspecialchars() direkt auf $_POST (Array → TypeError)');
    T::check(str_contains($orders, "function cms_checkout_post_string(string \$key, int \$maxLength = 190): string") && str_contains($orders, 'if (!is_string($value)) {'), 'Helfer nimmt nur Zeichenketten an');
    T::check(str_contains($orders, "cms_checkout_post_string('zip', 20)") && str_contains($orders, "cms_checkout_post_string('email', 190)"), 'Längengrenzen je Feld');
    T::check(str_contains($orders, "verifyToken(is_string(\$_POST['csrf_token'] ?? null) ? \$_POST['csrf_token'] : '', 'checkout_process')"), 'CSRF-Token nur als Zeichenkette');
});

T::run('Audit 4.2.0: Zeitzone der Abo-Spalten (DB-420-02)', static function (): void {
    $previous = date_default_timezone_get();
    date_default_timezone_set('Europe/Berlin');
    try {
        $f = pyFixture();
        $id = pyOrder($f['db'], ['provider' => 'stripe', 'provider_checkout_id' => 'cs_tz']);
        $paid = pyStripeBody('evt_tz', 'checkout.session.completed', ['id' => 'cs_tz', 'payment_status' => 'paid', 'amount_total' => 1199, 'currency' => 'eur', 'metadata' => ['order_id' => (string) $id], 'subscription' => 'sub_tz']);
        $f['service']->handleWebhook('stripe', pyStripeHeaders($paid), $paid);
        T::same(date('Y-m-d H:i:s', PY_NOW), (string) pyOrderRow($f['db'], $id)['paid_at'], 'paid_at in Ortszeit wie created_at aus orders.php');
        $f['clock']['now'] = PY_NOW + 86400 * 30;
        $end = PY_NOW + 86400 * 61;
        $cycle = pyStripeBody('evt_tz2', 'invoice.paid', ['id' => 'in_tz', 'billing_reason' => 'subscription_cycle', 'subscription' => 'sub_tz', 'amount_paid' => 1199, 'currency' => 'eur',
            'lines' => ['data' => [['period' => ['end' => $end]]]]]);
        $f['service']->handleWebhook('stripe', pyStripeHeaders($cycle, PY_NOW + 86400 * 30), $cycle);
        $sub = (array) $f['db']->get_row('SELECT * FROM cms_user_subscriptions WHERE provider_subscription_id = ?', ['sub_tz']);
        T::same(gmdate('Y-m-d H:i:s', $end), (string) $sub['current_period_end'], 'current_period_end bleibt UTC (Anbieter-Periode)');
        T::same([date('Y-m-d H:i:s', $end), date('Y-m-d H:i:s', $end)], [(string) $sub['end_date'], (string) $sub['next_billing_date']], 'end_date und next_billing_date in Ortszeit (Leser vergleichen mit NOW())');
        T::check((string) $sub['end_date'] !== (string) $sub['current_period_end'], 'in Europe/Berlin weichen Ortszeit und UTC ab (Test greift)');
        $copy = (array) $f['db']->get_row("SELECT * FROM cms_orders WHERE notes = 'Automatische Verlängerung'");
        T::same([date('Y-m-d H:i:s', PY_NOW + 86400 * 30), date('Y-m-d H:i:s', PY_NOW + 86400 * 30)], [(string) ($copy['paid_at'] ?? ''), (string) ($copy['created_at'] ?? '')], 'Folgebestellung in Ortszeit');
    } finally {
        date_default_timezone_set($previous);
    }
});

T::finish();
