<?php
/**
 * Ausgehende Webhooks (4.1.0, Bereich 4b).
 *
 * - Empfänger mit URL (nur HTTPS), Ereignisliste und Geheimnis (32 Byte, verschlüsselt über `CryptoService`,
 *   Bindung `webhook:{id}`; im Klartext nur direkt nach dem Anlegen bzw. Neuerzeugen sichtbar).
 * - `dispatch()` legt je passendem, aktivem Empfänger eine Zustellung in `webhook_deliveries` an – nie Zustellung
 *   im Request. Zugestellt wird per Cron (`cms_cron_mail_queue` minütlich, sonst `cms_cron_hourly`).
 * - Anfrage: `POST` JSON mit `X-365CMS-Event`, `X-365CMS-Delivery`, `X-365CMS-Timestamp` und
 *   `X-365CMS-Signature: v1=<hex HMAC-SHA256(secret, timestamp + "." + body)>`.
 * - Wiederholungen: 5 Versuche (nach 1, 5, 30, 120, 720 Minuten); 2xx = zugestellt; 410 = Empfänger sofort
 *   deaktivieren; 20 Fehlschläge in Folge = Empfänger deaktivieren.
 * - Zustellung über `Http\Client` (SSRF-Schutz), Timeout 10 s, Antwort höchstens 64 KB und nur der Status zählt.
 *
 * Datenbank, Verschlüsselung, Transport, Uhr und UUID-Erzeugung sind für Tests ersetzbar.
 *
 * @package CMSv2\Core\Services\Webhooks
 */

declare(strict_types=1);

namespace CMS\Services\Webhooks;

use CMS\Database;
use CMS\Hooks;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class WebhookService
{
    /** Ereignisse des Cores; weitere über den Filter `cms_webhook_event`. */
    public const EVENTS = [
        'backup.offsite_uploaded' => 'Offsite-Backup hochgeladen',
        'backup.offsite_failed' => 'Offsite-Backup fehlgeschlagen',
        'content.published' => 'Inhalt veröffentlicht',
        'content.submitted' => 'Inhalt zur Prüfung eingereicht',
        'user.registered' => 'Konto registriert',
        'order.paid' => 'Bestellung bezahlt',
        'order.failed' => 'Zahlung fehlgeschlagen',
        'subscription.renewed' => 'Abo verlängert',
    ];
    public const TEST_EVENT = 'webhook.test';
    public const RETRY_MINUTES = [1, 5, 30, 120, 720];
    public const MAX_CONSECUTIVE_FAILURES = 20;
    public const RESPONSE_LIMIT = 65536;
    public const TIMEOUT = 10;
    public const USER_AGENT = '365CMS-Webhooks/1.0';

    private static ?self $instance = null;

    private Database $db;
    private string $prefix;
    /** @var \Closure(string, int): string Klartext, Empfänger-ID → verschlüsselt */
    private \Closure $encrypt;
    /** @var \Closure(string, int): string verschlüsselt, Empfänger-ID → Klartext */
    private \Closure $decrypt;
    /** @var \Closure(string, array<string, string>, string): array{success: bool, status: int, error?: string} URL, Header, Body */
    private \Closure $transport;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(): string */
    private \Closure $uuid;

    public function __construct(
        ?Database $db = null,
        ?\Closure $encrypt = null,
        ?\Closure $decrypt = null,
        ?\Closure $transport = null,
        ?\Closure $clock = null,
        ?\Closure $uuid = null
    ) {
        $this->db = $db ?? Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->encrypt = $encrypt ?? static fn (string $plain, int $id): string => \CMS\Services\CryptoService::getInstance()->encrypt($plain, 'settings', 'webhook:' . $id);
        $this->decrypt = $decrypt ?? static fn (string $value, int $id): string => \CMS\Services\CryptoService::getInstance()->decrypt($value, 'settings', 'webhook:' . $id);
        $this->transport = $transport ?? \Closure::fromCallable([self::class, 'httpTransport']);
        $this->clock = $clock ?? static fn (): int => time();
        $this->uuid = $uuid ?? static function (): string {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
        };
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    // ── Reine Regeln ────────────────────────────────────────────────────────────────────────────────────────────

    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * Wann ist der nächste Versuch fällig? null = keine weiteren Versuche.
     */
    public static function nextAttemptDelay(int $attemptsDone): ?int
    {
        return isset(self::RETRY_MINUTES[$attemptsDone]) ? self::RETRY_MINUTES[$attemptsDone] * 60 : null;
    }

    /**
     * @return array<string, string> Ereignis → Bezeichnung (inkl. Erweiterungen über `cms_webhook_event`)
     */
    public static function events(): array
    {
        $events = self::EVENTS;
        try {
            $filtered = Hooks::applyFilters('cms_webhook_event', $events);
            if (is_array($filtered)) {
                foreach ($filtered as $key => $label) {
                    if (is_string($key) && preg_match('/^[a-z][a-z0-9_]{1,30}\.[a-z][a-z0-9_]{1,30}$/', $key) === 1) {
                        $events[$key] = mb_substr((string) $label, 0, 80);
                    }
                }
            }
        } catch (\Throwable) {
        }

        return $events;
    }

    /**
     * @return string|null Fehlermeldung oder null
     */
    public static function validateUrl(string $url): ?string
    {
        if (strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'Bitte eine gültige URL angeben.';
        }
        $parts = parse_url($url);
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return 'Webhooks werden nur über HTTPS zugestellt.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Zugangsdaten in der URL sind nicht erlaubt.';
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'Interne Ziele sind nicht erlaubt.';
        }
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false && \CMS\Http\Client::isNonPublicIp(trim($host, '[]'))) {
            return 'Private oder lokale IP-Adressen sind nicht erlaubt.';
        }

        return null;
    }

    // ── Empfänger ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param list<string> $events
     * @return array{success: bool, id?: int, secret?: string, error?: string}
     */
    public function createEndpoint(string $name, string $url, array $events, int $userId): array
    {
        $name = mb_substr(trim(strip_tags($name)), 0, 100);
        $url = trim($url);
        $events = $this->cleanEvents($events);
        if ($name === '') {
            return ['success' => false, 'error' => 'Bitte einen Namen angeben.'];
        }
        $urlError = self::validateUrl($url);
        if ($urlError !== null) {
            return ['success' => false, 'error' => $urlError];
        }
        if ($events === []) {
            return ['success' => false, 'error' => 'Bitte mindestens ein Ereignis wählen.'];
        }
        $now = $this->now();
        $this->db->execute(
            "INSERT INTO {$this->prefix}webhook_endpoints (name, url, events_json, secret_encrypted, is_active, consecutive_failures, created_by, created_at, updated_at)
             VALUES (?, ?, ?, '', 1, 0, ?, ?, ?)",
            [$name, $url, json_encode($events), $userId ?: null, $now, $now]
        );
        $id = (int) $this->db->lastInsertId();
        $secret = $this->storeNewSecret($id);

        return ['success' => true, 'id' => $id, 'secret' => $secret];
    }

    /**
     * @param list<string> $events
     * @return array{success: bool, error?: string}
     */
    public function updateEndpoint(int $id, string $name, string $url, array $events, bool $active): array
    {
        if ($this->endpoint($id) === null) {
            return ['success' => false, 'error' => 'Empfänger nicht gefunden.'];
        }
        $name = mb_substr(trim(strip_tags($name)), 0, 100);
        $urlError = self::validateUrl(trim($url));
        $events = $this->cleanEvents($events);
        if ($name === '' || $urlError !== null || $events === []) {
            return ['success' => false, 'error' => $urlError ?? 'Name und mindestens ein Ereignis sind Pflicht.'];
        }
        // Aktivieren setzt Grund und Fehlerzähler zurück.
        $reset = $active ? ', disabled_reason = NULL, consecutive_failures = 0' : '';
        $this->db->execute(
            "UPDATE {$this->prefix}webhook_endpoints SET name = ?, url = ?, events_json = ?, is_active = ?{$reset}, updated_at = ? WHERE id = ?",
            [$name, trim($url), json_encode($events), $active ? 1 : 0, $this->now(), $id]
        );

        return ['success' => true];
    }

    /**
     * @return array{success: bool, secret?: string, error?: string}
     */
    public function regenerateSecret(int $id): array
    {
        if ($this->endpoint($id) === null) {
            return ['success' => false, 'error' => 'Empfänger nicht gefunden.'];
        }

        return ['success' => true, 'secret' => $this->storeNewSecret($id)];
    }

    public function deleteEndpoint(int $id): bool
    {
        $this->db->execute("DELETE FROM {$this->prefix}webhook_deliveries WHERE endpoint_id = ?", [$id]);

        return $this->db->execute("DELETE FROM {$this->prefix}webhook_endpoints WHERE id = ?", [$id])->rowCount() > 0;
    }

    /**
     * @return list<array{id: int, name: string, url: string, events: list<string>, is_active: bool, consecutive_failures: int, disabled_reason: string}>
     */
    public function endpoints(): array
    {
        try {
            $rows = $this->db->get_results("SELECT * FROM {$this->prefix}webhook_endpoints ORDER BY name ASC, id ASC") ?: [];
        } catch (\Throwable) {
            return [];
        }

        return array_map(fn (object $row): array => $this->mapEndpoint($row), $rows);
    }

    /**
     * @return array{id: int, name: string, url: string, events: list<string>, is_active: bool, consecutive_failures: int, disabled_reason: string}|null
     */
    public function endpoint(int $id): ?array
    {
        $row = $id > 0 ? $this->db->get_row("SELECT * FROM {$this->prefix}webhook_endpoints WHERE id = ? LIMIT 1", [$id]) : null;

        return $row !== null ? $this->mapEndpoint($row) : null;
    }

    // ── Ereignisse und Zustellung ───────────────────────────────────────────────────────────────────────────────

    /**
     * Legt Zustellungen für alle aktiven Empfänger an, die das Ereignis abonniert haben (keine Zustellung im Request).
     *
     * @param array<string, mixed> $payload
     * @return int Zahl der angelegten Zustellungen
     */
    public function dispatch(string $event, array $payload, ?int $onlyEndpoint = null): int
    {
        if ($event !== self::TEST_EVENT && !isset(self::events()[$event])) {
            return 0;
        }
        $count = 0;
        try {
            $rows = $this->db->get_results("SELECT id, events_json FROM {$this->prefix}webhook_endpoints WHERE is_active = 1") ?: [];
        } catch (\Throwable $e) {
            $this->logWarning('Webhook-Empfänger konnten nicht gelesen werden.', $e);

            return 0;
        }
        $now = $this->now();
        foreach ($rows as $row) {
            $endpointId = (int) $row->id;
            if ($onlyEndpoint !== null && $endpointId !== $onlyEndpoint) {
                continue;
            }
            $events = json_decode((string) $row->events_json, true);
            if ($event !== self::TEST_EVENT && (!is_array($events) || !in_array($event, $events, true))) {
                continue;
            }
            $uuid = ($this->uuid)();
            $body = json_encode([
                'id' => $uuid,
                'event' => $event,
                'created_at' => gmdate('c', ($this->clock)()),
                'data' => $payload,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->db->execute(
                "INSERT INTO {$this->prefix}webhook_deliveries (endpoint_id, delivery_uuid, event, payload_json, status, attempts, next_attempt_at, created_at)
                 VALUES (?, ?, ?, ?, 'pending', 0, ?, ?)",
                [$endpointId, $uuid, $event, (string) $body, $now, $now]
            );
            $count++;
        }

        return $count;
    }

    /**
     * Fällige Zustellungen senden.
     *
     * @return array{delivered: int, failed: int, dead: int}
     */
    public function deliverDue(int $limit = 50): array
    {
        $result = ['delivered' => 0, 'failed' => 0, 'dead' => 0];
        $rows = $this->db->get_results(
            "SELECT d.id FROM {$this->prefix}webhook_deliveries d
             JOIN {$this->prefix}webhook_endpoints e ON e.id = d.endpoint_id AND e.is_active = 1
             WHERE d.status = 'pending' AND d.next_attempt_at <= ?
             ORDER BY d.next_attempt_at ASC, d.id ASC LIMIT " . max(1, min(500, $limit)),
            [$this->now()]
        ) ?: [];
        foreach ($rows as $row) {
            $outcome = $this->deliver((int) $row->id);
            $result[$outcome] = ($result[$outcome] ?? 0) + 1;
        }

        return $result;
    }

    /**
     * Eine Zustellung senden; Ergebnis: delivered | failed (erneuter Versuch geplant) | dead (aufgegeben).
     */
    public function deliver(int $deliveryId): string
    {
        $delivery = $this->db->get_row("SELECT * FROM {$this->prefix}webhook_deliveries WHERE id = ? LIMIT 1", [$deliveryId]);
        $endpoint = $delivery !== null ? $this->db->get_row("SELECT * FROM {$this->prefix}webhook_endpoints WHERE id = ? LIMIT 1", [(int) $delivery->endpoint_id]) : null;
        if ($delivery === null || $endpoint === null) {
            return 'dead';
        }
        $endpointId = (int) $endpoint->id;
        try {
            $secret = ($this->decrypt)((string) $endpoint->secret_encrypted, $endpointId);
        } catch (\Throwable) {
            $secret = '';
        }
        if ($secret === '') {
            return $this->finish($delivery, $endpoint, 0, 'Geheimnis nicht lesbar – bitte neu erzeugen.', true);
        }
        $timestamp = ($this->clock)();
        $body = (string) $delivery->payload_json;
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => self::USER_AGENT,
            'X-365CMS-Event' => (string) $delivery->event,
            'X-365CMS-Delivery' => (string) $delivery->delivery_uuid,
            'X-365CMS-Timestamp' => (string) $timestamp,
            'X-365CMS-Signature' => self::signature($secret, $timestamp, $body),
        ];
        $urlError = self::validateUrl((string) $endpoint->url);
        $response = $urlError === null
            ? ($this->transport)((string) $endpoint->url, $headers, $body)
            : ['success' => false, 'status' => 0, 'error' => $urlError];
        $status = (int) ($response['status'] ?? 0);

        if ($status >= 200 && $status < 300) {
            $this->db->execute(
                "UPDATE {$this->prefix}webhook_deliveries SET status = 'delivered', attempts = attempts + 1, response_status = ?, last_error = NULL, delivered_at = ?, next_attempt_at = NULL WHERE id = ?",
                [$status, $this->now(), (int) $delivery->id]
            );
            $this->db->execute("UPDATE {$this->prefix}webhook_endpoints SET consecutive_failures = 0 WHERE id = ?", [$endpointId]);

            return 'delivered';
        }
        if ($status === 410) {
            $this->disableEndpoint($endpointId, 'Empfänger meldet 410 Gone.');

            return $this->finish($delivery, $endpoint, $status, 'Empfänger meldet 410 Gone – deaktiviert.', true);
        }
        $error = $status > 0 ? 'HTTP ' . $status : self::cleanError((string) ($response['error'] ?? 'Keine Antwort.'));

        return $this->finish($delivery, $endpoint, $status, $error, false);
    }

    /**
     * Test-Ereignis an einen Empfänger, sofort zugestellt.
     *
     * @return array{success: bool, message?: string, error?: string}
     */
    public function sendTest(int $endpointId): array
    {
        $endpoint = $this->endpoint($endpointId);
        if ($endpoint === null) {
            return ['success' => false, 'error' => 'Empfänger nicht gefunden.'];
        }
        if (!$endpoint['is_active']) {
            return ['success' => false, 'error' => 'Der Empfänger ist deaktiviert.'];
        }
        if ($this->dispatch(self::TEST_EVENT, ['message' => 'Testereignis aus 365CMS'], $endpointId) < 1) {
            return ['success' => false, 'error' => 'Test konnte nicht angelegt werden.'];
        }
        $id = (int) $this->db->get_var(
            "SELECT id FROM {$this->prefix}webhook_deliveries WHERE endpoint_id = ? AND event = ? ORDER BY id DESC LIMIT 1",
            [$endpointId, self::TEST_EVENT]
        );
        $outcome = $this->deliver($id);
        $row = $this->db->get_row("SELECT response_status, last_error FROM {$this->prefix}webhook_deliveries WHERE id = ?", [$id]);

        return $outcome === 'delivered'
            ? ['success' => true, 'message' => 'Test zugestellt (HTTP ' . (int) ($row->response_status ?? 0) . ').']
            : ['success' => false, 'error' => 'Test nicht zugestellt: ' . (string) ($row->last_error ?? 'unbekannter Fehler')];
    }

    /**
     * Zustellprotokoll (ohne Geheimnis, ohne Antwortinhalt).
     *
     * @return list<array{id: int, endpoint_id: int, uuid: string, event: string, status: string, attempts: int, response_status: int, last_error: string, created_at: string, delivered_at: string}>
     */
    public function deliveries(int $endpointId = 0, int $limit = 50): array
    {
        $where = $endpointId > 0 ? 'WHERE endpoint_id = ?' : '';
        $rows = $this->db->get_results(
            "SELECT id, endpoint_id, delivery_uuid, event, status, attempts, response_status, last_error, created_at, delivered_at
             FROM {$this->prefix}webhook_deliveries {$where} ORDER BY id DESC LIMIT " . max(1, min(500, $limit)),
            $endpointId > 0 ? [$endpointId] : []
        ) ?: [];

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'endpoint_id' => (int) $row->endpoint_id,
            'uuid' => (string) $row->delivery_uuid,
            'event' => (string) $row->event,
            'status' => (string) $row->status,
            'attempts' => (int) $row->attempts,
            'response_status' => (int) ($row->response_status ?? 0),
            'last_error' => (string) ($row->last_error ?? ''),
            'created_at' => (string) $row->created_at,
            'delivered_at' => (string) ($row->delivered_at ?? ''),
        ], $rows);
    }

    /** Löschfrist: Zustellungen älter als $days Tage entfernen. */
    public function purgeDeliveries(int $days): int
    {
        return $this->db->execute(
            "DELETE FROM {$this->prefix}webhook_deliveries WHERE created_at < ? AND status IN ('delivered', 'dead')",
            [date('Y-m-d H:i:s', ($this->clock)() - max(1, $days) * 86400)]
        )->rowCount();
    }

    public function countPurgeable(int $days): int
    {
        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}webhook_deliveries WHERE created_at < ? AND status IN ('delivered', 'dead')",
            [date('Y-m-d H:i:s', ($this->clock)() - max(1, $days) * 86400)]
        );
    }

    // ── Intern ──────────────────────────────────────────────────────────────────────────────────────────────────

    private function finish(object $delivery, object $endpoint, int $status, string $error, bool $giveUp): string
    {
        $attempts = (int) $delivery->attempts + 1;
        // Nach dem n-ten Fehlschlag gilt der n-te Abstand (1, 5, 30, 120, 720 Minuten); danach aufgeben.
        $delay = $giveUp ? null : self::nextAttemptDelay($attempts - 1);
        $dead = $delay === null;
        $this->db->execute(
            "UPDATE {$this->prefix}webhook_deliveries SET status = ?, attempts = ?, response_status = ?, last_error = ?, next_attempt_at = ? WHERE id = ?",
            [$dead ? 'dead' : 'pending', $attempts, $status ?: null, mb_substr($error, 0, 500), $dead ? null : date('Y-m-d H:i:s', ($this->clock)() + $delay), (int) $delivery->id]
        );
        $endpointId = (int) $endpoint->id;
        $this->db->execute("UPDATE {$this->prefix}webhook_endpoints SET consecutive_failures = consecutive_failures + 1 WHERE id = ?", [$endpointId]);
        $failures = (int) $this->db->get_var("SELECT consecutive_failures FROM {$this->prefix}webhook_endpoints WHERE id = ?", [$endpointId]);
        if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
            $this->disableEndpoint($endpointId, sprintf('%d Fehlschläge in Folge.', $failures));
        }

        return $dead ? 'dead' : 'failed';
    }

    private function disableEndpoint(int $id, string $reason): void
    {
        $this->db->execute(
            "UPDATE {$this->prefix}webhook_endpoints SET is_active = 0, disabled_reason = ?, updated_at = ? WHERE id = ? AND is_active = 1",
            [$reason, $this->now(), $id]
        );
        try {
            Hooks::doAction('cms_webhook_endpoint_disabled', $id, $reason);
        } catch (\Throwable) {
        }
    }

    private function storeNewSecret(int $id): string
    {
        $secret = bin2hex(random_bytes(32));
        $this->db->execute(
            "UPDATE {$this->prefix}webhook_endpoints SET secret_encrypted = ?, updated_at = ? WHERE id = ?",
            [($this->encrypt)($secret, $id), $this->now(), $id]
        );

        return $secret;
    }

    /**
     * @param list<mixed> $events
     * @return list<string>
     */
    private function cleanEvents(array $events): array
    {
        $known = self::events();

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $event): string => is_string($event) ? $event : '', $events),
            static fn (string $event): bool => isset($known[$event])
        )));
    }

    /**
     * @return array{id: int, name: string, url: string, events: list<string>, is_active: bool, consecutive_failures: int, disabled_reason: string}
     */
    private function mapEndpoint(object $row): array
    {
        $events = json_decode((string) ($row->events_json ?? '[]'), true);

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'url' => (string) $row->url,
            'events' => is_array($events) ? array_values(array_filter($events, 'is_string')) : [],
            'is_active' => (int) $row->is_active === 1,
            'consecutive_failures' => (int) $row->consecutive_failures,
            'disabled_reason' => (string) ($row->disabled_reason ?? ''),
        ];
    }

    private static function cleanError(string $error): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $error)), 0, 300);
    }

    /**
     * @param array<string, string> $headers
     * @return array{success: bool, status: int, error?: string}
     */
    private static function httpTransport(string $url, array $headers, string $body): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $result = \CMS\Http\Client::getInstance()->post($url, $body, [
            'headers' => $lines,
            'timeout' => self::TIMEOUT,
            'connectTimeout' => 5,
            'maxBytes' => self::RESPONSE_LIMIT,
            'userAgent' => self::USER_AGENT,
        ]);

        return ['success' => !empty($result['success']), 'status' => (int) ($result['status'] ?? 0), 'error' => (string) ($result['error'] ?? '')];
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s', ($this->clock)());
    }

    private function logWarning(string $message, \Throwable $e): void
    {
        try {
            Logger::instance()->withChannel('webhooks')->warning($message, ['exception' => $e->getMessage()]);
        } catch (\Throwable) {
        }
    }
}
