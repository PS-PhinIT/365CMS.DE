<?php
/**
 * Abos, Präferenzen, Auto-Abo und Abmeldelink der Community (PR #2b; Vertrag DESIGN-2b §3.6, §3.10, §4.5; Entwurf interaktion §1.3,
 * §2.1 bis §2.4; Entscheidung A2 des Auftraggebers: Auto-Abo für eigene Themen und Antworten, abschaltbar).
 *
 * Grundsätze:
 * - Abonnieren nur mit `can('subscribe')` (Login, E-Mail, Schreibsperre, Sichtbarkeit, archiviert) und Drossel `subscribe`;
 *   Abbestellen ohne `can()` (auch gesperrte Mitglieder dürfen abbestellen). P1 kennt die Modi `instant` und `none`.
 * - Präferenzen in `community_user_stats.settings_json` (JSON in PHP): `auto_subscribe`, `notify_mail`, `notify_types`, `locale`;
 *   fremde Schlüssel (`mail_quota` des Dispatchers, spätere Profilschalter) bleiben erhalten. Schreiben unter der Statistiksperre
 *   des Mitglieds (Zeile vorher im Autocommit angelegt).
 * - Abmeldelink: `{uid}.{t<id>|f<id>|a}.{sig}`, `sig` = 128 Bit HMAC-SHA256 mit einem Schlüssel aus HKDF (Info TOKEN_INFO, getrennt
 *   von IpHash und Indexnamen); kein Ablauf, keine Speicherung. Ohne brauchbares Geheimnis gibt es kein Token und keine Community-Mails.
 *   Thema und Forum setzen den Modus auf `none` (In-App bleibt), `a` schaltet `notify_mail` ab.
 * - Hooks nach dem Commit, nur bei Änderung. Datenbankfehler: Rücknahme, Warnung, `db_error`. Listener werfen nie.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Community\Repository\SubscriptionRepository;
use CMS\Services\Community\Repository\UserStatsRepository;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\IpHash;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Transaction;

if (!defined('ABSPATH')) {
    exit;
}

final class SubscriptionService
{
    public const TARGET_TYPES = SubscriptionRepository::TARGET_TYPES;
    /** Modi in P1; `daily`/`weekly` (Zusammenfassung) folgen in P2, bis dahin `invalid_input`. */
    public const P1_MODES = ['instant', 'none'];
    /** `created` = eigene Themen, `all` = eigene Themen und Antworten. */
    public const AUTO_SUBSCRIBE = ['off', 'created', 'all'];
    public const DEFAULT_AUTO_SUBSCRIBE = 'all';
    /** HKDF-Info des Abmeldeschlüssels (Domänentrennung zu IpHash und FormSpamGuard). */
    public const TOKEN_INFO = '365cms:community:unsubscribe:v1';
    public const TOKEN_PATTERN = '/^([1-9][0-9]{0,9})\.(t[1-9][0-9]{0,9}|f[1-9][0-9]{0,9}|a)\.([0-9a-f]{32})$/D';
    /** Geheimnisse in dieser Reihenfolge; das erste brauchbare (`IpHash::usableSecret()`) gilt. */
    public const SECRETS = ['NONCE_KEY', 'SECURE_AUTH_KEY', 'AUTH_KEY'];
    /** Standard und Obergrenze von `subscriptions()`. */
    public const LIST_LIMIT = 50;
    public const LIST_MAX = 100;

    /** Präferenzschlüssel, die `savePreferences()` annimmt. */
    private const PREFERENCE_KEYS = ['auto_subscribe', 'notify_mail', 'notify_types', 'locale'];
    private const MAX_UINT = 4294967295;

    private static ?self $instance = null;
    private static bool $warnedSecret = false;

    private Database $db;
    private Settings $settings;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(?int, string, array<string, mixed>): AccessDecision */
    private \Closure $can;
    /** @var \Closure(string, mixed...): void */
    private \Closure $dispatch;
    /** @var \Closure(string, ?int): array{allowed: bool, retry_after: int} */
    private \Closure $throttle;
    /** @var \Closure(): ?string */
    private \Closure $secret;
    /** @var \Closure(): string */
    private \Closure $locale;
    private SubscriptionRepository $subscriptions;
    private UserStatsRepository $stats;
    /** HKDF-Schlüssel des Abmeldelinks (einmal je Instanz), `false` = kein brauchbares Geheimnis. */
    private string|false|null $key = null;

    /**
     * @param \Closure(): int|null $clock Unix-Zeit (Standard `time()`)
     * @param \Closure(?int, string, array<string, mixed>): AccessDecision|null $can Standard `CommunityAccess::instance()->can()`
     * @param \Closure(string, mixed...): void|null $dispatch Standard `Events::fire()`
     * @param \Closure(string, ?int): array{allowed: bool, retry_after: int}|null $throttle Standard `ContentGuard::instance()->throttle()`
     * @param \Closure(): ?string|null $secret Schlüsselmaterial; Standard die erste brauchbare Konstante aus SECRETS
     * @param \Closure(): string|null $locale Sprache der Anfrage; Standard `I18n::requestLocale()`
     */
    public function __construct(
        ?Database $db = null,
        ?Settings $settings = null,
        ?\Closure $clock = null,
        ?\Closure $can = null,
        ?\Closure $dispatch = null,
        ?\Closure $throttle = null,
        ?\Closure $secret = null,
        ?\Closure $locale = null
    ) {
        $this->db = $db ?? Database::instance();
        $this->settings = $settings ?? Settings::getInstance();
        $this->clock = $clock ?? static fn (): int => time();
        $this->can = $can ?? static fn (?int $userId, string $action, array $context): AccessDecision => CommunityAccess::instance()->can($userId, $action, $context);
        $this->dispatch = $dispatch ?? static function (string $hook, mixed ...$args): void {
            Events::fire($hook, ...$args);
        };
        // Ohne ContentGuard (Einheit S) bleibt Abonnieren zu (fail-closed).
        $this->throttle = $throttle ?? static function (string $action, ?int $userId): array {
            return class_exists(ContentGuard::class)
                ? ContentGuard::instance()->throttle($action, $userId)
                : ['allowed' => false, 'retry_after' => 60];
        };
        $this->secret = $secret ?? static function (): ?string {
            foreach (self::SECRETS as $name) {
                $value = defined($name) ? constant($name) : null;
                if (IpHash::usableSecret($value)) {
                    return (string) $value;
                }
            }

            return null;
        };
        $this->locale = $locale ?? static fn (): string => I18n::requestLocale();
        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->stats = new UserStatsRepository($this->db);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    // ── Abonnieren ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Abonniert ein Thema oder Forum (oder wechselt den Modus).
     *
     * @return array{success: bool, changed?: bool, mode?: string, error?: string, retry_after?: int}
     */
    public function subscribe(int $userId, string $targetType, int $targetId, string $mode = 'instant'): array
    {
        if (!self::validTarget($userId, $targetType, $targetId) || !in_array($mode, self::P1_MODES, true)) {
            return self::failure('invalid_input');
        }
        $nowTs = ($this->clock)();
        $now = self::utc($nowTs);
        try {
            $decision = ($this->can)($userId, 'subscribe', [$targetType . '_id' => $targetId, 'now' => $nowTs]);
            if (!$decision->allowed) {
                return self::denied($decision);
            }
            $gate = ($this->throttle)('subscribe', $userId);
            if (!$gate['allowed']) {
                return ['success' => false, 'error' => 'rate_limited', 'retry_after' => (int) $gate['retry_after']];
            }
            $this->stats->ensure($userId, $now);
            $changed = $this->subscriptions->insert($userId, $targetType, $targetId, $mode, $now)
                || $this->subscriptions->setMode($userId, $targetType, $targetId, $mode);
            $this->rememberLocale($userId, $now);
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('subscribe', $e, ['user_id' => $userId]);
        }
        if ($changed) {
            $this->fire(Events::SUBSCRIPTION_CHANGED, $userId, $targetType, $targetId, $mode);
        }

        return ['success' => true, 'changed' => $changed, 'mode' => $mode];
    }

    /**
     * Bestellt ab (ohne `can()`: auch gesperrte oder ausgeschlossene Mitglieder dürfen). Idempotent.
     *
     * @return array{success: bool, changed?: bool, error?: string}
     */
    public function unsubscribe(int $userId, string $targetType, int $targetId): array
    {
        if (!self::validTarget($userId, $targetType, $targetId)) {
            return self::failure('invalid_input');
        }
        try {
            $changed = $this->subscriptions->delete($userId, $targetType, $targetId);
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('unsubscribe', $e, ['user_id' => $userId]);
        }
        if ($changed) {
            $this->fire(Events::SUBSCRIPTION_CHANGED, $userId, $targetType, $targetId, '');
        }

        return ['success' => true, 'changed' => $changed];
    }

    /** @return array{mode: string, notified_at: ?string}|null */
    public function status(int $userId, string $targetType, int $targetId): ?array
    {
        if (!self::validTarget($userId, $targetType, $targetId)) {
            return null;
        }
        try {
            $row = $this->subscriptions->find($userId, $targetType, $targetId);
        } catch (\PDOException | \RuntimeException $e) {
            $this->warn('Community-Dienst fehlgeschlagen ({operation}): {exception}', ['operation' => 'subscription.status', 'exception' => $e]);

            return null;
        }

        return $row === null ? null : ['mode' => $row['mode'], 'notified_at' => $row['notified_at']];
    }

    /**
     * Abos eines Mitglieds, Seiten zu `$limit` (1 bis LIST_MAX), Cursor `'topic:123'`.
     *
     * @return array{success: bool, items?: list<array<string, mixed>>, next?: ?string, error?: string}
     */
    public function subscriptions(int $userId, ?string $after = null, int $limit = self::LIST_LIMIT): array
    {
        $cursor = null;
        if ($after !== null) {
            if (preg_match('/^(topic|forum):(0|[1-9][0-9]{0,9})$/D', $after, $m) !== 1 || (int) $m[2] > self::MAX_UINT) {
                return self::failure('invalid_input');
            }
            $cursor = [$m[1], (int) $m[2]];
        }
        if ($userId < 1 || $limit < 1 || $limit > self::LIST_MAX) {
            return self::failure('invalid_input');
        }
        try {
            $rows = $this->subscriptions->forUser($userId, $cursor, $limit + 1);
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('subscription.list', $e, ['user_id' => $userId]);
        }
        $next = null;
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $last = $rows[$limit - 1];
            $next = $last['target_type'] . ':' . $last['target_id'];
        }

        return ['success' => true, 'items' => $rows, 'next' => $next];
    }

    // ── Präferenzen ──────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Präferenzen eines Mitglieds (Standards, wenn nichts gespeichert ist oder die Datenbank ausfällt).
     *
     * @return array{auto_subscribe: string, notify_mail: bool, notify_types: array<string, bool>, locale: ?string}
     */
    public function preferences(int $userId): array
    {
        $raw = [];
        if ($userId > 0) {
            try {
                $raw = $this->stats->settingsMany([$userId])[$userId]['settings'] ?? [];
            } catch (\PDOException | \RuntimeException $e) {
                $this->warn('Community-Dienst fehlgeschlagen ({operation}): {exception}', ['operation' => 'subscription.preferences', 'exception' => $e]);
            }
        }

        return self::normalizePreferences($raw, self::switchableTypes());
    }

    /**
     * Speichert Präferenzen (nur die genannten Schlüssel; ein ungültiger Wert ⇒ `invalid_input`, nichts geschrieben).
     *
     * @param array<string, mixed> $changes
     * @return array{success: bool, preferences?: array<string, mixed>, changed?: bool, error?: string}
     */
    public function savePreferences(int $userId, array $changes): array
    {
        $switchable = self::switchableTypes();
        if ($userId < 1 || $changes === [] || array_diff(array_map('strval', array_keys($changes)), self::PREFERENCE_KEYS) !== []
            || !self::validChanges($changes, $switchable)
        ) {
            return self::failure('invalid_input');
        }
        $nowTs = ($this->clock)();
        $now = self::utc($nowTs);
        try {
            $this->stats->ensure($userId, $now);
            [$preferences, $changed] = Transaction::run($this->db, function () use ($userId, $changes, $switchable, $now): array {
                $stored = $this->stats->settingsForUpdate($userId);
                $merged = self::merge($stored, $changes);
                if ($merged !== $stored) {
                    $this->stats->saveSettings($userId, $merged, $now);
                }

                return [self::normalizePreferences($merged, $switchable), $merged !== $stored];
            });
        } catch (\PDOException | \RuntimeException $e) {
            return $this->databaseError('subscription.preferences', $e, ['user_id' => $userId]);
        }

        return ['success' => true, 'preferences' => $preferences, 'changed' => $changed];
    }

    /**
     * Präferenzen aus `settings_json` mit Standards: `auto_subscribe` (Standard `all`), `notify_mail` (an), `notify_types` je
     * schaltbarem Typ (an), `locale` (`de`/`en` oder `null`).
     *
     * @param array<string, mixed> $raw
     * @param list<string> $switchableTypes
     * @return array{auto_subscribe: string, notify_mail: bool, notify_types: array<string, bool>, locale: ?string}
     */
    public static function normalizePreferences(array $raw, array $switchableTypes): array
    {
        $auto = $raw['auto_subscribe'] ?? null;
        $types = is_array($raw['notify_types'] ?? null) ? $raw['notify_types'] : [];
        $flags = [];
        foreach ($switchableTypes as $type) {
            $flags[$type] = !array_key_exists($type, $types) || $types[$type] === true;
        }
        $locale = $raw['locale'] ?? null;

        return [
            'auto_subscribe' => is_string($auto) && in_array($auto, self::AUTO_SUBSCRIBE, true) ? $auto : self::DEFAULT_AUTO_SUBSCRIBE,
            'notify_mail' => !array_key_exists('notify_mail', $raw) || $raw['notify_mail'] === true,
            'notify_types' => $flags,
            'locale' => is_string($locale) && in_array($locale, I18n::LOCALES, true) ? $locale : null,
        ];
    }

    // ── Abmeldelink ──────────────────────────────────────────────────────────────────────────────────────────────

    /** Token für `$scope` (`'topic:<id>'`, `'forum:<id>'`, `'all'`); `null` ohne Geheimnis oder bei ungültigem Ziel. */
    public function token(int $userId, string $scope): ?string
    {
        $short = self::shortScope($scope);
        $key = $this->key();
        if ($userId < 1 || $userId > self::MAX_UINT || $short === null || $key === null) {
            return null;
        }

        return $userId . '.' . $short . '.' . self::signature($key, $userId, $short);
    }

    /** Gibt es ein brauchbares Geheimnis (sonst keine Community-Mails)? */
    public function canSign(): bool
    {
        return $this->key() !== null;
    }

    /** @return array{user_id: int, scope: string, target_id: int}|null */
    public function verifyToken(string $token): ?array
    {
        $key = $this->key();
        if ($key === null || strlen($token) > 64 || preg_match(self::TOKEN_PATTERN, $token, $m) !== 1) {
            return null;
        }
        $userId = (int) $m[1];
        $target = $m[2] === 'a' ? 0 : (int) substr($m[2], 1);
        if ($userId > self::MAX_UINT || $target > self::MAX_UINT || !hash_equals(self::signature($key, $userId, $m[2]), $m[3])) {
            return null;
        }

        return ['user_id' => $userId, 'scope' => $m[2] === 'a' ? 'all' : ($m[2][0] === 't' ? 'topic' : 'forum'), 'target_id' => $target];
    }

    /**
     * Abbestellen über den Link (kein `can()`, kein Login, auch gesperrt). Thema/Forum ⇒ Modus `none` (In-App bleibt), fehlendes Abo ⇒
     * Erfolg ohne Änderung; `all` ⇒ `notify_mail = false`. Idempotent.
     *
     * @return array{success: bool, user_id?: int, scope?: string, target_id?: int, changed?: bool, error?: string}
     */
    public function unsubscribeByToken(string $token): array
    {
        $verified = $this->verifyToken($token);
        if ($verified === null) {
            return self::failure('invalid_token');
        }
        ['user_id' => $userId, 'scope' => $scope, 'target_id' => $targetId] = $verified;
        if ($scope === 'all') {
            $saved = $this->savePreferences($userId, ['notify_mail' => false]);
            if (empty($saved['success'])) {
                return $saved;
            }
            $changed = (bool) $saved['changed'];
        } else {
            $type = $scope;
            try {
                $changed = $this->subscriptions->setMode($userId, $type, $targetId, 'none');
            } catch (\PDOException | \RuntimeException $e) {
                return $this->databaseError('unsubscribe.token', $e, ['user_id' => $userId]);
            }
            if ($changed) {
                $this->fire(Events::SUBSCRIPTION_CHANGED, $userId, $type, $targetId, 'none');
            }
        }

        return ['success' => true, 'user_id' => $userId, 'scope' => $scope, 'target_id' => $targetId, 'changed' => $changed];
    }

    // ── Auto-Abo (Listener nach dem Commit des Beitrags, werfen nie) ─────────────────────────────────────────────

    public function onTopicCreated(int $topicId, int $forumId, int $userId, string $status, int $firstPostId): void
    {
        $this->autoSubscribe($userId, $topicId, ['created', 'all']);
    }

    public function onPostCreated(int $postId, int $topicId, int $userId, string $status): void
    {
        $this->autoSubscribe($userId, $topicId, ['all']);
    }

    /** @param list<string> $modes Präferenzen `auto_subscribe`, bei denen abonniert wird */
    private function autoSubscribe(int $userId, int $topicId, array $modes): void
    {
        if ($userId < 1 || $topicId < 1 || $topicId > self::MAX_UINT) {
            return;
        }
        try {
            if (!in_array($this->preferences($userId)['auto_subscribe'], $modes, true)) {
                return;
            }
            $nowTs = ($this->clock)();
            $now = self::utc($nowTs);
            if (!($this->can)($userId, 'subscribe', ['topic_id' => $topicId, 'now' => $nowTs])->allowed) {
                return;
            }
            $this->stats->ensure($userId, $now);
            $created = $this->subscriptions->insert($userId, 'topic', $topicId, 'instant', $now);
            $this->rememberLocale($userId, $now);
        } catch (\Throwable $e) {
            $this->warn('Community-Auto-Abo fehlgeschlagen: {exception}', ['exception' => $e, 'user_id' => $userId]);

            return;
        }
        if ($created) {
            $this->fire(Events::SUBSCRIPTION_CHANGED, $userId, 'topic', $topicId, 'instant');
        }
    }

    // ── Hilfen ───────────────────────────────────────────────────────────────────────────────────────────────────

    /** Sprache der Anfrage merken, wenn `settings_json.locale` fehlt (Sprache der Mails an dieses Mitglied). */
    private function rememberLocale(int $userId, string $now): void
    {
        $known = $this->stats->settingsMany([$userId])[$userId]['settings']['locale'] ?? null;
        if (is_string($known) && in_array($known, I18n::LOCALES, true)) {
            return;
        }
        $locale = I18n::normalizeLocale(($this->locale)());
        Transaction::run($this->db, function () use ($userId, $locale, $now): void {
            $stored = $this->stats->settingsForUpdate($userId);
            if (!isset($stored['locale']) || !in_array($stored['locale'], I18n::LOCALES, true)) {
                $stored['locale'] = $locale;
                $this->stats->saveSettings($userId, $stored, $now);
            }
        });
    }

    /**
     * @param array<string, mixed> $changes
     * @param list<string> $switchable
     */
    private static function validChanges(array $changes, array $switchable): bool
    {
        if (array_key_exists('auto_subscribe', $changes) && !in_array($changes['auto_subscribe'], self::AUTO_SUBSCRIBE, true)) {
            return false;
        }
        if (array_key_exists('notify_mail', $changes) && !is_bool($changes['notify_mail'])) {
            return false;
        }
        if (array_key_exists('locale', $changes) && $changes['locale'] !== null && !in_array($changes['locale'], I18n::LOCALES, true)) {
            return false;
        }
        if (array_key_exists('notify_types', $changes)) {
            $types = $changes['notify_types'];
            if (!is_array($types) || $types === []) {
                return false;
            }
            foreach ($types as $type => $on) {
                if (!in_array((string) $type, $switchable, true) || !is_bool($on)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Änderungen in die gespeicherte Zuordnung übernehmen; fremde Schlüssel bleiben, `locale = null` entfernt die Sprache.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function merge(array $stored, array $changes): array
    {
        foreach ($changes as $key => $value) {
            if ($key === 'notify_types') {
                $stored['notify_types'] = array_merge(is_array($stored['notify_types'] ?? null) ? $stored['notify_types'] : [], $value);
            } elseif ($key === 'locale' && $value === null) {
                unset($stored['locale']);
            } else {
                $stored[$key] = $value;
            }
        }

        return $stored;
    }

    /** @return list<string> schaltbare Typen aus `NotificationDispatcher::typeCatalog()` (Filter angewandt) */
    private static function switchableTypes(): array
    {
        return array_keys(array_filter(NotificationDispatcher::typeCatalog(), static fn (array $type): bool => $type['switchable']));
    }

    private function key(): ?string
    {
        if ($this->key === null) {
            $secret = ($this->secret)();
            $this->key = IpHash::usableSecret($secret) ? hash_hkdf('sha256', (string) $secret, 32, self::TOKEN_INFO) : false;
            if ($this->key === false && !self::$warnedSecret) {
                self::$warnedSecret = true;
                $this->warn('Community: kein brauchbares Geheimnis für den Abmeldelink, Community-Mails bleiben aus', []);
            }
        }

        return $this->key === false ? null : $this->key;
    }

    private static function signature(string $key, int $userId, string $scope): string
    {
        return substr(hash_hmac('sha256', "v1|{$userId}|{$scope}", $key), 0, 32);
    }

    /** `'topic:12'` ⇒ `t12`, `'forum:3'` ⇒ `f3`, `'all'` ⇒ `a`, sonst `null`. */
    private static function shortScope(string $scope): ?string
    {
        if ($scope === 'all') {
            return 'a';
        }
        if (preg_match('/^(topic|forum):([1-9][0-9]{0,9})$/D', $scope, $m) !== 1 || (int) $m[2] > self::MAX_UINT) {
            return null;
        }

        return $m[1][0] . $m[2];
    }

    private static function validTarget(int $userId, string $targetType, int $targetId): bool
    {
        return $userId >= 1 && $userId <= self::MAX_UINT && in_array($targetType, self::TARGET_TYPES, true) && $targetId >= 1 && $targetId <= self::MAX_UINT;
    }

    private static function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /** @return array<string, mixed> */
    private static function denied(AccessDecision $decision): array
    {
        $result = self::failure($decision->reason !== '' ? $decision->reason : 'no_permission');
        if ($decision->retryAfter !== null) {
            $result['retry_after'] = $decision->retryAfter;
        }

        return $result;
    }

    /** @return array{success: false, error: string} */
    private static function failure(string $error): array
    {
        return ['success' => false, 'error' => $error];
    }

    /**
     * @param array<string, int|string> $context
     * @return array{success: false, error: string}
     */
    private function databaseError(string $operation, \Throwable $e, array $context): array
    {
        $this->warn('Community-Dienst fehlgeschlagen ({operation}): {exception}', $context + ['operation' => $operation, 'exception' => $e]);

        return self::failure('db_error');
    }

    /** @param array<string, mixed> $context */
    private function warn(string $message, array $context): void
    {
        Logger::instance()->withChannel('community')->warning($message, $context);
    }

    private function fire(string $hook, mixed ...$args): void
    {
        try {
            ($this->dispatch)($hook, ...$args);
        } catch (\Throwable $e) {
            $this->warn('Community-Hook {hook} fehlgeschlagen: {exception}', ['hook' => $hook, 'exception' => $e]);
        }
    }
}
