<?php
/**
 * Ausgehende Webhooks (4.1.0, Bereich 4b): Signatur (bekannter Wert, Zeitstempel im Header = signierter
 * Zeitstempel), Ereignisfilter, Warteschlange statt Sofortzustellung, Wiederholungsplan, 410 → deaktiviert,
 * 20 Fehlschläge → deaktiviert, private/unsichere Ziele abgelehnt, Geheimnis nie im Protokoll, Löschfrist und
 * Quelltext-Prüfungen der Einbindung. Ohne Datenbank: SQLite-Attrappe im Speicher.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/webhooks/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');

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
use CMS\Services\Webhooks\WebhookService as W;

T::$verbose = in_array('-v', $argv ?? [], true);

function whSource(string $relative): string
{
    return (string) file_get_contents(ABSPATH . $relative);
}

function whDatabase(): Database
{
    return new class () extends Database {
        public PDO $sqlite;

        public function __construct()
        {
            $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->sqlite->exec('CREATE TABLE cms_webhook_endpoints (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, url TEXT, events_json TEXT, secret_encrypted TEXT,
                is_active INTEGER DEFAULT 1, consecutive_failures INTEGER DEFAULT 0, disabled_reason TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT)');
            $this->sqlite->exec("CREATE TABLE cms_webhook_deliveries (id INTEGER PRIMARY KEY AUTOINCREMENT, endpoint_id INTEGER, delivery_uuid TEXT UNIQUE, event TEXT,
                payload_json TEXT, status TEXT DEFAULT 'pending', attempts INTEGER DEFAULT 0, next_attempt_at TEXT, response_status INTEGER, last_error TEXT,
                created_at TEXT, delivered_at TEXT)");
        }

        public function execute(string $sql, array $params = []): \PDOStatement
        {
            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute(array_values($params));

            return $stmt;
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

        public function lastInsertId(): int
        {
            return (int) $this->sqlite->lastInsertId();
        }

        public function getPrefix(): string
        {
            return 'cms_';
        }
    };
}

/**
 * @return array{db: Database, service: W, sent: \ArrayObject, clock: \ArrayObject, responses: \ArrayObject}
 */
function whFixture(): array
{
    $db = whDatabase();
    $sent = new \ArrayObject();
    $clock = new \ArrayObject(['now' => strtotime('2026-11-15 10:00:00')]);
    $responses = new \ArrayObject(['status' => 200]);
    $counter = new \ArrayObject(['n' => 0]);
    $service = new W(
        $db,
        static fn (string $plain, int $id): string => 'ENC[' . $id . ']' . strrev($plain),
        static fn (string $value, int $id): string => str_starts_with($value, 'ENC[' . $id . ']') ? strrev(substr($value, strlen('ENC[' . $id . ']'))) : '',
        static function (string $url, array $headers, string $body) use ($sent, $responses): array {
            $sent[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
            $status = (int) $responses['status'];

            return ['success' => $status >= 200 && $status < 300, 'status' => $status, 'error' => $status === 0 ? 'Timeout' : ''];
        },
        static fn (): int => (int) $clock['now'],
        static function () use ($counter): string {
            $counter['n']++;

            return sprintf('00000000-0000-4000-8000-%012d', $counter['n']);
        }
    );

    return ['db' => $db, 'service' => $service, 'sent' => $sent, 'clock' => $clock, 'responses' => $responses];
}

T::run('Signatur und Ziele', static function (): void {
    T::same('v1=bed691a05e3d0c3bb319b86d07d7281c8cbf4abe7fe56baee292339445d4cf62', W::signature('geheim', 1700000000, '{"event":"x"}'), 'bekannter Wert: v1=HMAC-SHA256(secret, ts.body)');
    T::check(W::signature('geheim', 1700000001, '{"event":"x"}') !== W::signature('geheim', 1700000000, '{"event":"x"}'), 'Zeitstempel ist Teil der Signatur');
    foreach (['http://hooks.example.com/x' => 'HTTPS', 'https://127.0.0.1/x' => 'IP', 'https://10.0.0.5/x' => 'IP', 'https://[::1]/x' => 'IP',
        'https://localhost/x' => 'Intern', 'https://nas.local/x' => 'Intern', 'https://user:pw@hooks.example.com/' => 'Zugangsdaten', 'ftp://x.example.com' => 'HTTPS', 'kein-url' => 'gültige'] as $url => $reason) {
        T::check(W::validateUrl($url) !== null, 'abgelehnt (' . $reason . '): ' . $url);
    }
    T::same(null, W::validateUrl('https://hooks.example.com/365cms?x=1'), 'öffentliches HTTPS-Ziel erlaubt');
    T::same([60, 300, 1800, 7200, 43200, null], array_map([W::class, 'nextAttemptDelay'], [0, 1, 2, 3, 4, 5]), 'Wiederholungsplan 1, 5, 30, 120, 720 Minuten, dann Schluss');
});

T::run('Empfänger anlegen, Geheimnis', static function (): void {
    $f = whFixture();
    $s = $f['service'];
    T::check(empty($s->createEndpoint('', 'https://a.example.com', ['user.registered'], 1)['success']), 'Name Pflicht');
    T::check(empty($s->createEndpoint('A', 'https://a.example.com', ['gibt.esnicht'], 1)['success']), 'unbekannte Ereignisse zählen nicht – mindestens eines nötig');
    T::check(empty($s->createEndpoint('A', 'http://127.0.0.1/hook', ['user.registered'], 1)['success']), 'Ziel http://127.0.0.1 abgelehnt');
    $created = $s->createEndpoint('Zapier', 'https://hooks.example.com/a', ['content.published', 'user.registered', 'erfunden'], 1);
    T::check(!empty($created['success']) && strlen((string) $created['secret']) === 64, 'Geheimnis: 32 Byte hex, einmal zurückgegeben');
    $stored = (string) $f['db']->get_var('SELECT secret_encrypted FROM cms_webhook_endpoints');
    T::check($stored !== $created['secret'] && !str_contains($stored, (string) $created['secret']), 'Geheimnis verschlüsselt gespeichert');
    T::same(['content.published', 'user.registered'], $s->endpoint((int) $created['id'])['events'], 'nur bekannte Ereignisse gespeichert');
    T::check(!array_key_exists('secret_encrypted', $s->endpoint((int) $created['id'])) && !array_key_exists('secret', $s->endpoints()[0]), 'Geheimnis nie in Listen');
    $new = $s->regenerateSecret((int) $created['id']);
    T::check($new['success'] && $new['secret'] !== $created['secret'], 'Geheimnis neu erzeugen');
});

T::run('Warteschlange und Zustellung', static function (): void {
    $f = whFixture();
    $s = $f['service'];
    $endpoint = $s->createEndpoint('A', 'https://hooks.example.com/a', ['content.published'], 1);
    $s->createEndpoint('B', 'https://hooks.example.com/b', ['user.registered'], 1);
    T::same(0, $s->dispatch('nicht.vorhanden', []), 'unbekanntes Ereignis wird ignoriert');
    T::same(1, $s->dispatch('content.published', ['type' => 'post', 'id' => 7]), 'nur der Empfänger mit dem Ereignis erhält eine Zustellung');
    T::same(0, count($f['sent']), 'keine Zustellung im Request (nur Warteschlange)');
    T::same(['delivered' => 1, 'failed' => 0, 'dead' => 0], $s->deliverDue(), 'Cron stellt zu');
    $request = $f['sent'][0];
    $body = $request['body'];
    T::same($request['headers']['X-365CMS-Signature'], W::signature((string) $endpoint['secret'], (int) $request['headers']['X-365CMS-Timestamp'], $body), 'Signatur passt zum Zeitstempel im Header und zum Body');
    T::same('content.published', $request['headers']['X-365CMS-Event'], 'Ereignis-Header');
    T::same('00000000-0000-4000-8000-000000000001', $request['headers']['X-365CMS-Delivery'], 'Zustell-ID-Header');
    T::same('application/json', $request['headers']['Content-Type'], 'JSON');
    $decoded = json_decode($body, true);
    T::same(['type' => 'post', 'id' => 7], $decoded['data'], 'Nutzdaten');
    T::same('delivered', (string) $f['db']->get_var('SELECT status FROM cms_webhook_deliveries'), 'Status zugestellt');
    T::check(!str_contains((string) $f['db']->get_var('SELECT group_concat(payload_json || coalesce(last_error, \'\')) FROM cms_webhook_deliveries'), (string) $endpoint['secret']), 'Geheimnis nie im Protokoll');
    T::same(['delivered' => 0, 'failed' => 0, 'dead' => 0], $s->deliverDue(), 'nichts doppelt zugestellt');

    \CMS\Hooks::addFilter('cms_webhook_event', static fn (array $events): array => $events + ['shop.coupon_used' => 'Gutschein eingelöst', 'Böse Taste' => 'x']);
    $s->updateEndpoint((int) $endpoint['id'], 'A', 'https://hooks.example.com/a', ['content.published', 'shop.coupon_used', 'Böse Taste'], true);
    T::same(['content.published', 'shop.coupon_used'], $s->endpoint((int) $endpoint['id'])['events'], 'Filter cms_webhook_event erweitert die Liste (nur gültige Namen)');
});

T::run('Wiederholungen und Deaktivieren', static function (): void {
    $f = whFixture();
    $s = $f['service'];
    $endpoint = $s->createEndpoint('A', 'https://hooks.example.com/a', ['user.registered'], 1);
    $f['responses']['status'] = 500;
    $s->dispatch('user.registered', ['user_id' => 3]);
    T::same(['delivered' => 0, 'failed' => 1, 'dead' => 0], $s->deliverDue(), 'HTTP 500: fehlgeschlagen, erneut geplant');
    $row = $f['db']->get_row('SELECT * FROM cms_webhook_deliveries');
    T::same(['pending', 1, '2026-11-15 10:01:00', 'HTTP 500'], [$row->status, (int) $row->attempts, $row->next_attempt_at, $row->last_error], 'nächster Versuch nach 1 Minute');
    T::same(['delivered' => 0, 'failed' => 0, 'dead' => 0], $s->deliverDue(), 'vor dem Termin kein Versuch');
    foreach ([60, 300, 1800, 7200] as $wait) {
        $f['clock']['now'] += $wait;
        $s->deliverDue();
    }
    T::same('2026-11-16 00:36:00', (string) $f['db']->get_var('SELECT next_attempt_at FROM cms_webhook_deliveries'), 'Plan 1, 5, 30, 120, 720 Minuten (10:00 + 1 + 5 + 30 + 120 + 720 min)');
    $f['clock']['now'] += 43200;
    T::same(['delivered' => 0, 'failed' => 0, 'dead' => 1], $s->deliverDue(), 'nach 5 Versuchen aufgegeben… ');
    T::same(['dead', 6], [(string) $f['db']->get_var('SELECT status FROM cms_webhook_deliveries'), (int) $f['db']->get_var('SELECT attempts FROM cms_webhook_deliveries')], '…mit 6 Versuchen insgesamt (1 + 5 Wiederholungen)');

    $f['responses']['status'] = 410;
    $s->dispatch('user.registered', ['user_id' => 4]);
    T::same(['delivered' => 0, 'failed' => 0, 'dead' => 1], $s->deliverDue(), '410: sofort aufgegeben');
    $after = $s->endpoint((int) $endpoint['id']);
    T::check(!$after['is_active'] && str_contains($after['disabled_reason'], '410'), '410: Empfänger deaktiviert');
    T::same(0, $s->dispatch('user.registered', ['user_id' => 5]), 'deaktivierte Empfänger erhalten nichts');

    $g = whFixture();
    $e2 = $g['service']->createEndpoint('B', 'https://hooks.example.com/b', ['user.registered'], 1);
    $g['responses']['status'] = 0;
    for ($i = 0; $i < 20; $i++) {
        $g['service']->dispatch('user.registered', ['user_id' => $i]);
        $g['service']->deliverDue();
    }
    $state = $g['service']->endpoint((int) $e2['id']);
    T::check(!$state['is_active'] && str_contains($state['disabled_reason'], '20 Fehlschläge'), '20 Fehlschläge in Folge: deaktiviert');
    T::check(str_contains((string) $g['db']->get_var('SELECT last_error FROM cms_webhook_deliveries ORDER BY id LIMIT 1'), 'Timeout'), 'Fehler ohne Antwort: Grund gespeichert');
    $g['responses']['status'] = 204;
    $g['service']->updateEndpoint((int) $e2['id'], 'B', 'https://hooks.example.com/b', ['user.registered'], true);
    T::same(0, $g['service']->endpoint((int) $e2['id'])['consecutive_failures'], 'Reaktivieren setzt den Fehlerzähler zurück');
    T::check($g['service']->sendTest((int) $e2['id'])['success'], 'Test-Ereignis wird sofort zugestellt');
    T::same('webhook.test', $g['sent'][count($g['sent']) - 1]['headers']['X-365CMS-Event'], 'Test-Ereignis');
});

T::run('Löschfrist', static function (): void {
    $f = whFixture();
    $s = $f['service'];
    $s->createEndpoint('A', 'https://hooks.example.com/a', ['user.registered'], 1);
    $s->dispatch('user.registered', ['user_id' => 1]);
    $s->deliverDue();
    $s->dispatch('user.registered', ['user_id' => 2]);
    $f['clock']['now'] += 31 * 86400;
    T::same(1, $s->countPurgeable(30), 'nur abgeschlossene, ältere Zustellungen');
    T::same(1, $s->purgeDeliveries(30), 'gelöscht');
    T::same('pending', (string) $f['db']->get_var('SELECT status FROM cms_webhook_deliveries'), 'ausstehende bleiben');
    T::check($s->deleteEndpoint(1) && (int) $f['db']->get_var('SELECT COUNT(*) FROM cms_webhook_deliveries') === 0, 'Löschen des Empfängers entfernt seine Zustellungen');
});

T::run('Einbindung (Quelltext)', static function (): void {
    $hooks = whSource('core/Services/FeatureHooks/WebhookHooks.php');
    foreach (["'cms_backup_offsite_uploaded'", "'cms_backup_offsite_failed'", "'cms_content_submitted'", "'cms_content_status_changed'", "'user_registered'", "'cms_cron_mail_queue'", "'cms_cron_hourly'"] as $hook) {
        T::check(str_contains($hooks, $hook), 'Hook ' . $hook);
    }
    T::check(str_contains($hooks, "['user_id' => (int) \$userId]") && !str_contains($hooks, 'email'), 'user.registered nur mit Benutzer-ID (keine E-Mail)');
    T::check(str_contains($hooks, 'DataRetentionService::registerRule('), 'Löschfrist registriert');
    T::check(str_contains(whSource('core/Services/FeatureHooks/Registry.php'), 'WebhookHooks::class'), 'Hooks registriert');
    T::check(str_contains(whSource('core/Services/Content/ContentWorkflow.php'), "Hooks::doAction('cms_content_status_changed'"), 'Statuswechsel-Hook im Freigabe-Ablauf');
    $service = whSource('core/Services/Webhooks/WebhookService.php');
    T::check(str_contains($service, "'maxBytes' => self::RESPONSE_LIMIT") && str_contains($service, 'RESPONSE_LIMIT = 65536') && str_contains($service, "'timeout' => self::TIMEOUT"), 'Antwort auf 64 KB begrenzt, Timeout 10 s');
    T::check(str_contains($service, '\\CMS\\Http\\Client::getInstance()->post('), 'Zustellung über Http\\Client (SSRF-Schutz)');
    T::check(str_contains(whSource('admin/webhooks.php'), "hasCapability('webhooks.manage')") && str_contains(whSource('admin/modules/users/RolesModule.php'), "'webhooks.manage'"), 'Recht webhooks.manage');
    $view = whSource('admin/views/system/webhooks.php');
    T::same(substr_count($view, '<form method="post"'), substr_count($view, 'name="csrf_token"'), 'jedes POST-Formular trägt ein CSRF-Token');
    T::check(!preg_match('/<script|\son[a-z]+=/i', $view), 'keine Inline-Skripte (CSP)');
    T::check(!str_contains($view, 'secret'), 'Formulare zeigen kein Geheimnis');
});

T::finish();
