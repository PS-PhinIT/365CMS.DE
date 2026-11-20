<?php
/**
 * Community-Schutz (PR #2b; Vertrag DESIGN-2b §3.13, §4.7 Nr. 7, §4.9, §7.1; Entwurf schutz §6.1): `Support\IpHash`,
 * `Service\ContentGuard` (Wortfilter, Hosts, Vergleichsfassung `fold()`, Inhaltszählung, `screen()` mit Core-Antispam, Spam-Dublette,
 * Filter `cms_community_spam_check` und `ip_hash`, Request-Grenzen `throttle()`, Sicherheitsprotokoll) und
 * `Security::pruneDbRateLimitAttempts()`.
 *
 * Prüfkürzel SECURITY-PRIVACY §1 (Vertrag §7.1b): S1 Wortfilter `block`, S2 `review`/`replace`, S4 Spam-Dublette, S5 Core-Antispam,
 * S6 Filter, S7 Request-Grenzen. S3 (Link- und Bildgrenzen je Stufe) prüft `community-access`, S8 (`review` ⇒ `pending`) T über die Dienste.
 *
 * Abschnitte (Besitzer): diese Datei S (W2); die Dienste mit Schutzschichten ergänzt T in W3 (`part-services.php`, ohne „Wirkung:“).
 * DB-frei (SQLite-Double), Zone Europe/Berlin. Die Wettläufe der Request-Grenzen gegen MariaDB stehen in `TESTS/community-db`
 * (`sections-spam.php`, Abschnitt `race-throttle`).
 *
 *   php TESTS/community-spam/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-spam-' . getmypid() . '/');
define('SITE_URL', 'https://forum.example');
// Geheimnisse wie in config/app.php: NONCE_KEY ist der Platzhalter des Installers, SECURE_AUTH_KEY brauchbar, AUTH_KEY ein `YOUR_`-Wert.
// Standard von IpHash: die erste brauchbare Konstante in dieser Reihenfolge, hier also SECURE_AUTH_KEY.
define('NONCE_KEY', 'REPLACE_VIA_INSTALLER_run_install_php_to_generate_unique_key_nonce');
define('SECURE_AUTH_KEY', 'spam-suite-secure-auth-key-0123456789');
define('AUTH_KEY', 'YOUR_AUTH_KEY_0123456789abcdef');

// PHP-Zone der Suite: Europe/Berlin, damit jede Verwendung von date() statt gmdate() auffällt.
date_default_timezone_set('Europe/Berlin');
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

// Gegenprobe-Haken (TESTS/lib/Mutant.php): mit CMS_TEST_MUTANT=<name> lädt der Autoloader eine Mutante statt des Originals.
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
// CommonMark-Bündel und HTMLPurifier (Standard-Zählung über ContentRenderer) über den produktiven Assets-Autoloader.
require_once ABSPATH . 'assets/autoload.php';

require_once dirname(__DIR__) . '/lib/TestRunner.php';
require_once dirname(__DIR__) . '/lib/CommunityTestDatabase.php';

use CMS\Database;
use CMS\Hooks;
use CMS\SchemaManager;
use CMS\Security;
use CMS\Services\Community\Service\ContentGuard;
use CMS\Services\Community\Support\Codes;
use CMS\Services\Community\Support\ContentRenderer;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\IpHash;
use CMS\Services\Community\Support\Settings;

T::$verbose = in_array('-v', $argv ?? [], true);

register_shutdown_function(static function (): void {
    foreach (glob(LOG_PATH . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir(LOG_PATH);
});

Settings::setInstance(Settings::fromArray(['base_path' => 'forum']));

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Hilfen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Geheimnis der Testvektoren (Entwurf schutz §1.1). */
const SP_SECRET = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

/** Web-Client der Welt. */
const SP_WEB = ['web' => true, 'ip' => '203.0.113.77', 'user_agent' => 'Testbrowser/1.0'];

/** Kommandozeile (Cron, CLI): kein Antispam, kein ip_hash, kein Protokoll. */
const SP_CLI = ['web' => false, 'ip' => '', 'user_agent' => ''];

/** Text für die Spam-Dublette (über DUPLICATE_MIN_CHARS). */
const SP_LONG = 'Kaufen Sie jetzt die besten Uhren zum halben Preis bei uns im Laden';

/** Uhr der Suite: 2026-11-20 12:00:00 UTC (Berlin +01:00). */
function spNow(int $offset = 0): int
{
    return gmmktime(12, 0, 0, 11, 20, 2026) + $offset;
}

function spUtc(int $offset = 0): string
{
    return gmdate('Y-m-d H:i:s', spNow($offset));
}

/** Warnungen dieses Prozesses (Kanal community). */
function spLog(): string
{
    return implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), glob(LOG_PATH . 'community-*.log') ?: []));
}

function spLogReset(): void
{
    foreach (glob(LOG_PATH . 'community-*.log') ?: [] as $file) {
        @unlink($file);
    }
}

/** Setzt die Core-Verbindung (`Database::instance()`) und liefert die vorige. */
function spSwapDatabase(?Database $db): ?Database
{
    $property = new \ReflectionProperty(Database::class, 'instance');
    $previous = $property->getValue();
    $property->setValue(null, $db);

    return $previous;
}

/** Legt eine Core-Tabelle aus der echten DDL im SQLite-Double an (security_log, login_attempts). */
function spCoreTable(CommunityTestDatabase $db, string $table): void
{
    foreach (CommunityTestSchema::sqlite(SchemaManager::getSchemaQueries('cms_', 'utf8mb4')[$table]) as $statement) {
        $db->sqlite->exec($statement);
    }
}

/** SQL-Texte des Protokolls, die eine Tabelle nennen. */
function spStatements(CommunityTestDatabase $db, string $table): array
{
    return array_values(array_filter($db->log, static fn (string $sql): bool => str_contains($sql, 'cms_' . $table . ' ') || str_contains($sql, 'cms_' . $table . "\n")));
}

/**
 * Testwelt: SQLite-Double, ein Mitglied, Fakes für Zählung, Antispam, Client, Grenzen und Protokoll; IpHash mit festem Geheimnis.
 * Der Fake der Grenzen zählt wie die Standard-Closure (Vorprüfung ohne Eintrag, Eintrag, Nachzählung).
 */
final class SpWorld
{
    public CommunityTestDatabase $db;
    public Settings $settings;
    public ContentGuard $guard;
    public int $user;
    public int $forum;
    /** @var list<int> */
    public array $topics = [];
    /** @var array{web: bool, ip: string, user_agent: string} */
    public array $client = SP_WEB;
    public int $clientCalls = 0;
    /** @var list<array<string, mixed>> */
    public array $antispamCalls = [];
    /** @var array<string, mixed> */
    public array $antispamResult = ['rejected' => false, 'reason' => ''];
    public ?\Throwable $antispamThrows = null;
    /** @var list<array{0: string, 1: string, 2: int, 3: int}> */
    public array $limiterCalls = [];
    /** @var array<string, int> */
    public array $limiterRows = [];
    /** @var array<string, bool> Subjekt => feste Antwort */
    public array $limiterFixed = [];
    public ?\Throwable $limiterThrows = null;
    /** @var list<array<string, mixed>> */
    public array $securityLog = [];
    public ?\Throwable $securityLogThrows = null;
    /** @var list<string> */
    public array $inspectCalls = [];
    public mixed $inspectResult = ['external_links' => 0, 'images' => 0];
    public ?\Throwable $inspectThrows = null;

    /**
     * @param array<string, mixed> $settings Rohwerte für Settings::fromArray()
     * @param array{inspect?: bool, antispam?: bool, securityLog?: bool, limiter?: bool, secret?: \Closure|null} $defaults Standard-Closures statt Fakes
     */
    public function __construct(array $settings = [], array $defaults = [], ?CommunityTestDatabase $db = null)
    {
        $this->db = $db ?? CommunityTestDatabase::create();
        $this->settings = Settings::fromArray($settings);
        $this->user = $this->db->user(['username' => 'anna', 'display_name' => 'Anna A.', 'email' => 'anna@example.test']);
        $this->forum = $this->db->forum();
        $this->topics = [$this->db->topic(['forum' => $this->forum, 'user' => $this->user]), $this->db->topic(['forum' => $this->forum, 'user' => $this->user])];
        $secret = array_key_exists('secret', $defaults) ? $defaults['secret'] : static fn (): string => SP_SECRET;
        $this->guard = new ContentGuard(
            $this->db,
            $this->settings,
            !empty($defaults['inspect']) ? null : function (string $markdown): mixed {
                $this->inspectCalls[] = $markdown;
                if ($this->inspectThrows !== null) {
                    throw $this->inspectThrows;
                }

                return $this->inspectResult;
            },
            !empty($defaults['antispam']) ? null : function (array $context): array {
                $this->antispamCalls[] = $context;
                if ($this->antispamThrows !== null) {
                    throw $this->antispamThrows;
                }

                return $this->antispamResult;
            },
            function (): array {
                $this->clientCalls++;

                return $this->client;
            },
            !empty($defaults['limiter']) ? null : function (string $name, string $subject, int $max, int $window): bool {
                $this->limiterCalls[] = [$name, $subject, $max, $window];
                if ($this->limiterThrows !== null) {
                    throw $this->limiterThrows;
                }
                if (isset($this->limiterFixed[$subject])) {
                    return $this->limiterFixed[$subject];
                }
                $key = $name . '|' . $subject;
                if (($this->limiterRows[$key] ?? 0) >= $max) {
                    return false;
                }
                $this->limiterRows[$key] = ($this->limiterRows[$key] ?? 0) + 1;

                return $this->limiterRows[$key] <= $max;
            },
            !empty($defaults['securityLog']) ? null : function (array $entry): void {
                if ($this->securityLogThrows !== null) {
                    throw $this->securityLogThrows;
                }
                $this->securityLog[] = $entry;
            },
            new IpHash($secret)
        );
        $this->db->log = [];
    }

    /** Beitrag des Mitglieds mit Alter in Sekunden (UTC). */
    public function post(string $body, int $age, array $o = []): int
    {
        $id = $this->db->post(['topic' => $o['topic'] ?? $this->topics[0], 'user' => $o['user'] ?? $this->user, 'body' => $body, 'created_at' => spUtc(-$age)] + $o);
        $this->db->log = [];

        return $id;
    }

    /**
     * Eingabe für screen() mit Standardwerten.
     *
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    public function input(array $o = []): array
    {
        return $o + [
            'forum_id' => 0, 'topic_id' => $this->topics[1], 'post_id' => 0, 'title' => null, 'body' => 'Ein ganz gewöhnlicher Beitrag ohne Auffälligkeiten.',
            'verdict' => ContentGuard::ALLOW, 'content' => ['links' => 0, 'images' => 0, 'attachments' => 0, 'failed' => false], 'hosts' => [],
        ];
    }

    /** @param array<string, mixed> $o */
    public function screen(string $action = 'reply', array $o = [], ?int $now = null): array
    {
        return $this->guard->screen($this->user, $action, $this->input($o), $now ?? spNow());
    }
}

/** Ergebnis von screen() für einen erlaubten Beitrag. */
function spOk(array $signals = [], ?string $ipHash = null): array
{
    return ['ok' => true, 'review' => $signals !== [], 'signals' => $signals, 'ip_hash' => $ipHash];
}

/** Wortfilter-Einstellung. */
function spFilter(array $block = [], array $review = [], array $replace = [], string $replacement = '***'): array
{
    return ['word_filter' => ['block' => $block, 'review' => $review, 'replace' => $replace, 'replacement' => $replacement]];
}

/** Guard nur für die reinen Methoden (Wortfilter, Hosts, Zählung). */
function spPure(array $settings = []): ContentGuard
{
    return (new SpWorld($settings))->guard;
}

/** Erwarteter ip_hash (Testvektor, unabhängig von IpHash gerechnet). */
function spExpectedHash(string $network, string $secret = SP_SECRET): string
{
    return hash_hmac('sha256', $network, hash_hkdf('sha256', $secret, 32, '365cms:community:ip_hash:v1'));
}

/** Führt $test mit gesetzten PCRE-Grenzen aus (Backtracking 1, ohne JIT) und stellt die Werte danach wieder her. */
function spWithPcreLimit(\Closure $test): mixed
{
    $previous = [ini_get('pcre.backtrack_limit'), ini_get('pcre.jit')];
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');
    try {
        return $test();
    } finally {
        ini_set('pcre.backtrack_limit', (string) $previous[0]);
        ini_set('pcre.jit', (string) $previous[1]);
    }
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// IpHash
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

// Zuerst: die Warnung „kein Geheimnis“ kommt einmal je Prozess (statischer Merker), also bevor ein anderer Abschnitt sie auslöst.
T::run('IpHash: ohne brauchbares Geheimnis kein ip_hash und keine Rate-Kennung, genau eine Warnung je Prozess', static function (): void {
    spLogReset();
    $secrets = [
        'null' => null, 'leer' => '', '15 Zeichen' => 'abcdefghijklmno', 'Platzhalter' => NONCE_KEY, 'YOUR_' => AUTH_KEY, 'Zahl' => 1234567890123456789,
    ];
    foreach ($secrets as $label => $secret) {
        $hash = new IpHash(static fn (): mixed => $secret);
        T::same([null, null, null], [$hash->hash('203.0.113.77'), $hash->rateKey('203.0.113.77'), $hash->hash('2001:db8::1')], 'Geheimnis ' . $label . ': hash() und rateKey() liefern null (kein Rückfallschlüssel)');
    }
    $throws = new IpHash(static function (): string {
        throw new \RuntimeException('Konfiguration nicht lesbar');
    });
    T::same(null, $throws->hash('203.0.113.77'), 'eine Ausnahme beim Lesen des Geheimnisses ergibt null');
    T::same(1, substr_count(spLog(), 'IpHash: kein brauchbares Geheimnis'), 'genau eine Warnung für alle Instanzen dieses Prozesses (kein ValueError aus hash_hkdf() mit leerem Schlüssel)');
});

T::run('IpHash: Testvektoren, /24 und /48, gemappte IPv4, Rate-Kennung je IPv4 und /64, Domänentrennung', static function (): void {
    $hash = new IpHash(static fn (): string => SP_SECRET);
    $v4 = spExpectedHash('203.0.113.0/24');
    T::same('8b5ea672', substr($v4, 0, 8), 'Voraussetzung: Testvektor des Entwurfs (HMAC-SHA256 über 203.0.113.0/24 mit HKDF-Schlüssel)');
    foreach (['203.0.113.77', '203.0.113.5', '::ffff:203.0.113.5', '::FFFF:203.0.113.9', ' 203.0.113.77 '] as $ip) {
        T::same($v4, $hash->hash($ip), 'hash(' . $ip . ') = Testvektor des Netzes 203.0.113.0/24');
    }
    T::check($hash->hash('203.0.114.1') !== $v4 && $hash->hash('203.0.114.1') !== null, 'anderes /24 ergibt einen anderen Wert');
    $v6 = spExpectedHash('2001:db8:abcd::/48');
    T::same([$v6, $v6], [$hash->hash('2001:db8:abcd:12::1'), $hash->hash('2001:db8:abcd:ffff::9')], 'IPv6: dasselbe /48 ergibt denselben Wert');
    T::check($hash->hash('2001:db8:abce::1') !== $v6, 'IPv6: anderes /48 ergibt einen anderen Wert');
    foreach (['', '0.0.0.0', '::', '::ffff:0.0.0.0', 'fe80::1%eth0', 'abc', '203.0.113', '203.0.113.256', '1.2.3.4/24'] as $ip) {
        T::same(null, $hash->hash($ip), 'hash(' . json_encode($ip) . ') = null (leer, Nulladresse, Zonen-ID, ungültig)');
    }
    T::check(preg_match('/^[0-9a-f]{64}$/', (string) $hash->hash('198.51.100.1')) === 1 && IpHash::LENGTH === 64, 'Wert: 64 Hex-Zeichen (CHAR(64), IpHash::LENGTH)');
    T::check($v4 !== hash_hmac('sha256', '203.0.113.0/24', SP_SECRET), 'Domänentrennung: nicht der HMAC mit dem rohen Geheimnis (HKDF mit eigener Info)');

    T::same(
        ['203.0.113.0/24', '203.0.113.0/24', '203.0.113.0/24', '2001:db8:abcd::/48', '2001:db8::/48', null, null],
        [IpHash::network('203.0.113.77'), IpHash::network('::ffff:203.0.113.5'), IpHash::network(' 203.0.113.9'), IpHash::network('2001:db8:abcd:12::1'), IpHash::network('2001:db8::1'), IpHash::network('0.0.0.0'), IpHash::network('fe80::1%eth0')],
        'network(): IPv4 /24, gemappte IPv4 als IPv4, IPv6 /48, Nulladresse und Zonen-ID null'
    );
    T::same(
        ['203.0.113.77', '203.0.113.5', '2001:db8:abcd:12::/64', null, null],
        [IpHash::rateSubject('203.0.113.77'), IpHash::rateSubject('::ffff:203.0.113.5'), IpHash::rateSubject('2001:db8:abcd:12::ff'), IpHash::rateSubject('::'), IpHash::rateSubject('kein-ip')],
        'rateSubject(): IPv4 voll, gemappte IPv4 als IPv4, IPv6 /64'
    );

    $k2 = hash_hkdf('sha256', SP_SECRET, 32, '365cms:community:rate:v1');
    T::same(substr(hash_hmac('sha256', '203.0.113.77', $k2), 0, 40), $hash->rateKey('203.0.113.77'), 'rateKey(): 40 Hex-Zeichen des HMAC über die volle IPv4 (eigener HKDF-Schlüssel)');
    T::check($hash->rateKey('203.0.113.77') !== $hash->rateKey('203.0.113.5'), 'rateKey(): zwei IPv4 desselben /24 ergeben zwei Eimer');
    T::same($hash->rateKey('2001:db8:abcd:12::1'), $hash->rateKey('2001:db8:abcd:12::ffff'), 'rateKey(): dasselbe IPv6-/64 ergibt denselben Eimer');
    T::check($hash->rateKey('2001:db8:abcd:12::1') !== $hash->rateKey('2001:db8:abcd:13::1'), 'rateKey(): anderes /64 ergibt einen anderen Eimer');
    T::check(!str_starts_with((string) $hash->hash('203.0.113.77'), (string) $hash->rateKey('203.0.113.77')), 'Domänentrennung: Rate-Kennung ist kein Teil von ip_hash');
    T::same(null, $hash->rateKey('0.0.0.0'), 'rateKey(0.0.0.0) = null (CLI-Wert von TrustedProxyService)');
});

T::run('IpHash: Geheimnis (usableSecret, Standard aus NONCE_KEY, SECURE_AUTH_KEY, AUTH_KEY)', static function (): void {
    T::same(
        [false, false, false, true, false, false, false, true],
        [
            IpHash::usableSecret(null), IpHash::usableSecret(1234567890123456789), IpHash::usableSecret('abcdefghijklmno'), IpHash::usableSecret('abcdefghijklmnop'),
            IpHash::usableSecret('REPLACE_VIA_INSTALLER_run_install_php_to_generate_unique_key_nonce'), IpHash::usableSecret('YOUR_SECRET_KEY_0123456789'),
            IpHash::usableSecret('x-REPLACE_VIA_INSTALLER-0123456789'), IpHash::usableSecret('my-YOUR_-secret-0123456789'),
        ],
        'usableSecret(): Text mit mindestens 16 Zeichen, ohne Platzhalter des Installers und ohne Präfix YOUR_'
    );
    $default = new IpHash();
    T::same((new IpHash(static fn (): string => SECURE_AUTH_KEY))->hash('203.0.113.77'), $default->hash('203.0.113.77'), 'Standard: erste brauchbare Konstante (NONCE_KEY ist Platzhalter, also SECURE_AUTH_KEY)');
    T::same(null, (new IpHash(static fn (): string => NONCE_KEY))->hash('203.0.113.77'), 'der Platzhalter des Installers gilt nicht als Geheimnis');
    T::same(spExpectedHash('203.0.113.0/24', 'abcdefghijklmnop'), (new IpHash(static fn (): string => 'abcdefghijklmnop'))->hash('203.0.113.1'), 'Geheimnis mit genau 16 Zeichen gilt');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Wortfilter: Einstellung, Vergleichsfassung, filterText, filterHosts
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Einstellung word_filter: Normalisierung, höchstens 500 Einträge in Vorrangreihenfolge, Ersatztext ohne Buchstaben und Ziffern', static function (): void {
    T::same(Settings::DEFAULT_WORD_FILTER, Settings::normalize([])['word_filter'], 'ohne Wert: Standard');
    foreach (['Text' => 'spam', 'Zahl' => 7, 'kaputtes JSON' => '{"block": [', 'Liste' => '["spam"]'] as $label => $raw) {
        T::same(Settings::DEFAULT_WORD_FILTER, Settings::normalize(['word_filter' => $raw])['word_filter'], $label . ': Standard');
    }
    T::same(['spam'], Settings::normalize(['word_filter' => 'json:{"block": ["Spam"]}'])['word_filter']['block'], 'json:-Präfix, Einträge klein');

    $raw = ['block' => [], 'review' => [], 'replace' => []];
    for ($i = 0; $i < 300; $i++) {
        $raw['block'][] = 'bw' . $i;
        $raw['review'][] = 'rw' . $i;
        $raw['replace'][] = 'pw' . $i;
    }
    $cut = Settings::normalize(['word_filter' => $raw])['word_filter'];
    T::same([300, 200, 0], [count($cut['block']), count($cut['review']), count($cut['replace'])], '900 Einträge: höchstens WORD_FILTER_MAX = 500, in Vorrangreihenfolge block, review, replace');
    T::same(['rw0', 'rw199'], [$cut['review'][0], $cut['review'][199]], 'gekürzt wird am Ende (die ersten 200 der review-Liste bleiben)');

    $mixed = Settings::normalize(['word_filter' => [
        'block' => ['Spam', 'a', '**', 'a*b', "ab\x01", 'xy', ['x']],
        'review' => ['SPAM', 'prüf  wort', 'gut*'],
        'replace' => ['spam', 'mist'],
        'replacement' => '#!',
    ]])['word_filter'];
    T::same(['block' => ['spam', 'xy'], 'review' => ['prüf wort', 'gut*'], 'replace' => ['mist'], 'replacement' => '#!'], $mixed, 'ungültige Einträge entfallen, Doppelte nur in der Liste mit Vorrang, Leerraum zu einem Leerzeichen');

    $replacement = static fn (mixed $value): string => Settings::normalize(['word_filter' => ['replacement' => $value]])['word_filter']['replacement'];
    T::same(
        ['***', '***', '***', '***', '***', '#', '***', '-_-'],
        [$replacement('xx'), $replacement('a1'), $replacement(''), $replacement(str_repeat('#', 21)), $replacement("\x07"), $replacement('#'), $replacement('[gelöscht]'), $replacement('-_-')],
        'Ersatztext: 1 bis 20 Zeichen ohne Buchstaben, Ziffern und Steuerzeichen, sonst ***'
    );
});

T::run('Vergleichsfassung fold(): unsichtbare Default-Ignorable-Zeichen, NFKC, Groß/klein (Vertrag §4.9)', static function (): void {
    T::same(
        ['strasse', 'strasse', 'spam', 'spam', 'spam', 'spam', 'spam', "sp\u{200B}am", 'ab'],
        [
            ContentGuard::fold('STRASSE'), ContentGuard::fold('Straße'), ContentGuard::fold("\u{FF53}\u{FF50}\u{FF41}\u{FF4D}"), ContentGuard::fold("sp\u{034F}am"),
            ContentGuard::fold("sp\u{FE0F}am"), ContentGuard::fold("s\u{FE00}p\u{FE00}a\u{FE00}m"), ContentGuard::fold("SP\u{3164}AM"), ContentGuard::fold("sp\u{200B}am"),
            ContentGuard::fold("a\u{E0100}b"),
        ],
        'fold(): STRASSE/Straße, Vollbreite, CGJ, VS16, VS1, Hangul-Füller, VS17 entfernt; ZWSP (Cf) bleibt (entfernt normalizeBody)'
    );
    T::same('', ContentGuard::fold("\xFF"), 'ungültiges UTF-8 ergibt eine leere Vergleichsfassung (keine Ausnahme)');

    // Die Klasse IGNORABLE gegen die Unicode-Eigenschaft Default_Ignorable_Code_Point (PCRE2 ab 10.40 kennt \p{DI}).
    if (@preg_match('/\p{DI}/u', 'a') === false) {
        T::info('PCRE ' . PCRE_VERSION . ' kennt \p{DI} nicht: Vergleich über alle Codepunkte übersprungen');

        return;
    }
    $all = '';
    for ($cp = 0; $cp <= 0x10FFFF; $cp++) {
        if ($cp < 0xD800 || $cp > 0xDFFF) {
            $all .= mb_chr($cp, 'UTF-8');
        }
    }
    $set = static function (string $pattern) use ($all): array {
        preg_match_all($pattern, $all, $found);

        return array_map(static fn (string $char): int => (int) mb_ord($char, 'UTF-8'), $found[0]);
    };
    $class = (string) (new \ReflectionClassConstant(ContentGuard::class, 'IGNORABLE'))->getValue();
    $di = $set('/\p{DI}/u');
    $in = $set($class);
    T::check(count($di) > 4000 && count($in) > 4000, 'Voraussetzung: beide Mengen sind gefüllt (' . count($di) . ' Zeichen mit \p{DI}, ' . count($in) . ' in IGNORABLE)');
    $format = static fn (array $cps): array => array_map(static fn (int $cp): string => sprintf('U+%04X', $cp), array_slice(array_values($cps), 0, 20));
    T::same([], $format(array_diff(array_diff($di, $set('/\p{Cf}/u')), $in)), 'IGNORABLE enthält jedes Default-Ignorable-Zeichen außer Cf (alle Codepunkte, PCRE ' . PCRE_VERSION . ')');
    T::same([], $format(array_diff($in, $di)), 'IGNORABLE enthält kein Zeichen außerhalb von Default_Ignorable_Code_Point');
    T::same('ab', ContentGuard::fold("a\u{2065}\u{FFF0}\u{E0FFF}\u{180F}b"), 'fold() entfernt auch nicht vergebene Codepunkte der Eigenschaft');
});

T::run('filterText: Ganzwort, Groß/klein, Umlaute, gefaltete Zweitprüfung, Phrase, Präfix (S1)', static function (): void {
    $g = spPure(spFilter(['spam', 'ass', 'cunt', 'straße', 'strasse2', 'böse wort', 'viagra*']));
    $verdict = static fn (string $body, ?string $title = null): string => $g->filterText($title, $body)['verdict'];
    foreach (['Das ist SPAM!', '«spam»', 'Ass.', 'STRASSE', 'Straße', "\u{FF53}\u{FF50}\u{FF41}\u{FF4D} kaufen", 'ein BÖSE   Wort hier', "ein böse\nwort", 'Viagraaa kaufen', 'viagra'] as $body) {
        T::same(ContentGuard::BLOCK, $verdict($body), 'block: ' . json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    foreach (['Scunthorpe und class und passage', 'Spamming', 'x_spam', 'spam_x', "\u{00C4}SPAM", 'xviagra', 'bösewort', 'Straßen'] as $body) {
        T::same(ContentGuard::ALLOW, $verdict($body), 'allow (Ganzwort, Scunthorpe-fest): ' . json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    T::same(ContentGuard::BLOCK, $verdict('Gewöhnlicher Text', 'Kauf SPAM jetzt'), 'block im Titel');
    T::same(['title' => 'Kauf SPAM', 'body' => 'Text', 'verdict' => ContentGuard::BLOCK, 'replaced' => false], $g->filterText('Kauf SPAM', 'Text'), 'block: Titel und Text unverändert');
    T::same(['title' => null, 'body' => 'harmlos', 'verdict' => ContentGuard::ALLOW, 'replaced' => false], $g->filterText(null, 'harmlos'), 'ohne Titel bleibt title null');
    T::same(ContentGuard::BLOCK, $verdict('Straße2 ist gesperrt'), 'gefaltet: Eintrag strasse2 trifft Straße2 (nur in der gefalteten Fassung gleich)');
    $empty = spPure();
    T::same(['title' => 'Spam', 'body' => 'spam', 'verdict' => ContentGuard::ALLOW, 'replaced' => false], $empty->filterText('Spam', 'spam'), 'leere Listen: allow, Text unverändert');
});

T::run('filterText: unsichtbare Zeichen (CGJ, VS16, VS1) umgehen den Filter nicht, auch im Titel; U+FE0F bleibt gespeichert (Vertrag §4.9)', static function (): void {
    $g = spPure(spFilter(['spam'], [], ['mist']));
    foreach (['CGJ' => "kauf sp\u{034F}am hier", 'VS16' => "kauf sp\u{FE0F}am hier", 'VS1' => "kauf s\u{FE00}p\u{FE00}a\u{FE00}m hier", 'Hangul-Füller' => "kauf sp\u{3164}am hier"] as $label => $text) {
        T::same(ContentGuard::BLOCK, $g->filterText(null, $text)['verdict'], $label . ' im Text: block');
        T::same(ContentGuard::BLOCK, $g->filterText($text, 'harmlos')['verdict'], $label . ' im Titel: block');
    }
    $heart = $g->filterText(null, "Ich \u{2764}\u{FE0F} das, Mist!");
    T::same(["Ich \u{2764}\u{FE0F} das, ***!", true, ContentGuard::ALLOW], [$heart['body'], $heart['replaced'], $heart['verdict']], 'Emoji mit VS16: Ersatz im Original, U+FE0F bleibt im gespeicherten Text');
});

T::run('filterText: replace (einmal je Treffer, über Blockgrenzen), review, Vorrang block, gefaltete Treffer von replace ⇒ review (S2)', static function (): void {
    $g = spPure(spFilter(['spam'], ['prüfwort', 'straße'], ['mist', 'mist*', 'dreck'], '#'));
    T::same(['title' => 'Ein # Titel', 'body' => 'So ein #! Und #? Nein: #, # und #.', 'verdict' => ContentGuard::ALLOW, 'replaced' => true], $g->filterText('Ein Mist Titel', 'So ein Mist! Und Mistkerl? Nein: Dreck, MIST und dreck.'), 'replace: jeder Treffer im Titel und Text wird zum Ersatztext (Präfix mist* ersetzt das ganze Wort), replaced = true');
    T::same(['title' => null, 'body' => 'spam und mist', 'verdict' => ContentGuard::BLOCK, 'replaced' => false], $g->filterText(null, 'spam und mist'), 'block geht vor: Text unverändert');
    T::same(['title' => null, 'body' => 'Ein PRÜFWORT hier', 'verdict' => ContentGuard::REVIEW, 'replaced' => false], $g->filterText(null, 'Ein PRÜFWORT hier'), 'review: Urteil review, Text unverändert');
    T::same(ContentGuard::REVIEW, $g->filterText(null, 'Die STRASSE')['verdict'], 'review gefaltet: Eintrag straße trifft STRASSE');
    $both = $g->filterText(null, 'Mist, ein Prüfwort');
    T::same(['#, ein Prüfwort', ContentGuard::REVIEW, true], [$both['body'], $both['verdict'], $both['replaced']], 'replace und review zusammen: ersetzt und review');

    $folded = spPure(spFilter([], [], ['straße']));
    T::same(['title' => null, 'body' => 'STRASSE gesperrt', 'verdict' => ContentGuard::REVIEW, 'replaced' => false], $folded->filterText(null, 'STRASSE gesperrt'), 'replace-Eintrag nur in der gefalteten Fassung: Text unverändert, review');
    T::same(['title' => null, 'body' => 'Die *** ist frei', 'verdict' => ContentGuard::ALLOW, 'replaced' => true], $folded->filterText(null, 'Die Straße ist frei'), 'replace-Eintrag im Original: ersetzt, allow');

    $entries = [];
    for ($i = 0; $i < 100; $i++) {
        $entries[] = 'qa' . $i;
    }
    $entries[] = 'zzende';
    $blocks = spPure(spFilter([], [], $entries, '##'));
    T::same(['body' => '## und ## und ##', 'replaced' => true], array_intersect_key($blocks->filterText(null, 'qa0 und qa99 und zzende'), ['body' => 1, 'replaced' => 1]), '101 Einträge (zwei Blöcke zu 100): Treffer beider Blöcke je einmal ersetzt');
    $grow = spPure(spFilter([], [], ['ab'], '#####'))->filterText(null, 'ab ab');
    T::same(['##### #####', true], [$grow['body'], $grow['replaced']], 'Ersatz kann den Text verlängern: replaced = true (der Dienst prüft die Längen erneut)');
});

T::run('filterText und filterHosts: PCRE-Fehler ergibt review, nie stilles Durchlassen', static function (): void {
    // Die Einstellungen werden vor der PCRE-Grenze gelesen (Settings::normalize() prüft die Einträge selbst mit PCRE); die Muster des
    // Filters entstehen erst unter der Grenze und werden dann ohne JIT übersetzt (JIT beachtet pcre.backtrack_limit nicht).
    $w = new SpWorld(spFilter(['pcrefehlerwort'], [], ['pcreersatzwort']));
    $w->settings->all();
    $result = spWithPcreLimit(static fn (): array => $w->guard->filterText('Titel', 'Ein Text mit pcrefehlerwort darin'));
    T::same(['title' => 'Titel', 'body' => 'Ein Text mit pcrefehlerwort darin', 'verdict' => ContentGuard::REVIEW, 'replaced' => false], $result, 'Backtracking-Grenze überschritten: review, Text unverändert');
    T::same(ContentGuard::BLOCK, $w->guard->filterText(null, 'Ein Text mit pcrefehlerwort darin')['verdict'], 'Gegenprobe ohne Grenze: block');
    T::same(ContentGuard::REVIEW, $w->guard->filterText(null, "ung\xFCltig")['verdict'], 'ungültiges UTF-8: review');
    $hosts = new SpWorld(spFilter(['pcrehostwort']));
    $hosts->settings->all();
    T::same(ContentGuard::REVIEW, spWithPcreLimit(static fn (): string => $hosts->guard->filterHosts(['pcrehostwort.example'])), 'filterHosts mit PCRE-Fehler: review');
    T::same(ContentGuard::BLOCK, $hosts->guard->filterHosts(['pcrehostwort.example']), 'Gegenprobe ohne Grenze: block');
});

T::run('filterHosts: dieselben Muster wie filterText; block, review, replace ⇒ review; Prozent-Kodierung über normalizeHost (S12)', static function (): void {
    $g = spPure(spFilter(['evil.com', 'böse'], ['shop.example'], ['casino']));
    $hosts = ContentRenderer::instance()->analyze('[a](https://evil%2Ecom/x) und [b](https://%65vil.com/y)')['stats']['hosts'];
    T::same(['evil.com'], $hosts, 'Voraussetzung: der Renderer liefert evil%2Ecom und %65vil.com als evil.com (normalizeHost)');
    T::same(ContentGuard::BLOCK, $g->filterHosts($hosts), 'block: evil.com aus prozentkodierten Links');
    T::same(
        [ContentGuard::BLOCK, ContentGuard::BLOCK, ContentGuard::REVIEW, ContentGuard::REVIEW, ContentGuard::ALLOW, ContentGuard::ALLOW, ContentGuard::ALLOW, ContentGuard::BLOCK, ContentGuard::ALLOW, ContentGuard::ALLOW],
        [
            $g->filterHosts(['good.example', 'sub.evil.com']), $g->filterHosts(["ev\u{034F}il.com"]), $g->filterHosts(['shop.example']), $g->filterHosts(['casino.example']),
            $g->filterHosts(['good.example', 'devil.com']), $g->filterHosts([]), $g->filterHosts(['xn--bse-sna.example']), $g->filterHosts(['böse.example']),
            $g->filterHosts([123, null]), spPure()->filterHosts(['evil.com']),
        ],
        'filterHosts: Subdomain trifft, CGJ im Host trifft, review und replace ⇒ review, Ganzwort (devil.com), leer, Punycode bleibt Punycode, Nicht-ASCII trifft, Nicht-Texte entfallen, leerer Filter'
    );
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Zählung
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('content(): fertige Zählung des Renderers, Kurzform, Standard-Zählung, Fehler ⇒ failed', static function (): void {
    $w = new SpWorld();
    $stats = ['links' => 5, 'external_links' => 2, 'images' => 3, 'external_images' => 1, 'attachments' => [], 'hosts' => ['a.example'], 'mentions' => 0, 'quotes' => []];
    T::same(['links' => 2, 'images' => 3, 'attachments' => 1, 'failed' => false], $w->guard->content('x', 1, $stats), 'Stats: links = external_links (fremde Links und mailto), images = alle Bilder');
    T::same([], $w->inspectCalls, 'mit fertiger Zählung wird nicht erneut gezählt');
    T::same(['links' => 4, 'images' => 0, 'attachments' => 0, 'failed' => false], $w->guard->content('x', 0, ['links' => 4, 'images' => 0]), 'Kurzform {links, images}');
    $w->inspectResult = ['external_links' => 1, 'images' => 0];
    T::same(['links' => 1, 'images' => 0, 'attachments' => 3, 'failed' => false], $w->guard->content('Text', 3), 'ohne Zählung: Closure inspect');
    T::same(['Text'], $w->inspectCalls, 'inspect erhält den Text');
    T::same(0, $w->guard->content('x', -2, $stats)['attachments'], 'negative Anhangszahl zählt 0');
    foreach (['Text statt Zahl' => ['links' => '3', 'images' => 0], 'negativ' => ['external_links' => -1, 'images' => 0], 'unvollständig' => ['images' => 1], 'kein Array' => 'x'] as $label => $result) {
        $w->inspectResult = $result;
        T::same(['links' => 0, 'images' => 0, 'attachments' => 0, 'failed' => true], $w->guard->content('Text'), 'inspect liefert ' . $label . ': failed, Zahlen 0');
    }
    spLogReset();
    $w->inspectThrows = new \RuntimeException('Renderer kaputt');
    T::same(['links' => 0, 'images' => 0, 'attachments' => 2, 'failed' => true], $w->guard->content('Text', 2), 'inspect wirft: failed');
    T::check(str_contains(spLog(), 'Renderer kaputt'), 'Warnung im Kanal community');

    $real = new SpWorld([], ['inspect' => true]);
    T::same(['links' => 2, 'images' => 1, 'attachments' => 0, 'failed' => false], $real->guard->content('[a](https://evil.example/x) und [b](mailto:x@evil.example) ![c](https://img.example/i.png) [intern](https://forum.example/y)'), 'Standard-Zählung (ContentRenderer): fremder Link und mailto zählen, eigene Adresse nicht, Bild zählt');
    T::same(true, $real->guard->content("ung\xFCltig")['failed'], 'Standard-Zählung mit ungültigem UTF-8: failed');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// screen()
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('screen(): Wortfilter block und Hosts zuerst, ohne Datenbank; review-Signale; unbekannte Aktion wirft', static function (): void {
    $w = new SpWorld(spFilter(['evil.com'], ['shop.example'], ['casino']) + ['use_core_antispam' => true]);
    $calls = 0;
    $filter = static function (string $verdict) use (&$calls): string {
        $calls++;

        return $verdict;
    };
    Hooks::addFilter(Events::SPAM_CHECK, $filter);
    try {
        T::same(['ok' => false, 'error' => 'word_blocked'], $w->screen('reply', ['verdict' => ContentGuard::BLOCK]), 'Urteil block aus filterText: word_blocked');
        T::same([[], [], 0, 0], [$w->db->log, $w->antispamCalls, $calls, $w->clientCalls], 'block: keine Anweisung, kein Antispam, kein Filter, kein Client');
        T::same(['ok' => false, 'error' => 'word_blocked'], $w->screen('create_topic', ['hosts' => ['good.example', 'evil.com']]), 'gesperrter Host: word_blocked');
        T::same([[], [], 0], [$w->db->log, $w->antispamCalls, $calls], 'gesperrter Host: keine Anweisung, kein Antispam, kein Filter');
    } finally {
        Hooks::removeFilter(Events::SPAM_CHECK, $filter);
    }
    $w = new SpWorld(spFilter(['evil.com'], ['shop.example'], ['casino']));
    T::same(spOk(['word_review'], spExpectedHash('203.0.113.0/24')), $w->screen('reply', ['hosts' => ['shop.example']]), 'Host auf der review-Liste: Signal word_review');
    T::same(spOk(['word_review'], spExpectedHash('203.0.113.0/24')), $w->screen('reply', ['hosts' => ['casino.example'], 'verdict' => ContentGuard::REVIEW]), 'replace-Treffer im Host und Urteil review: ein Signal word_review');
    T::same(spOk(['inspect_failed'], spExpectedHash('203.0.113.0/24')), $w->screen('reply', ['content' => ['links' => 0, 'images' => 0, 'attachments' => 0, 'failed' => true]]), 'Zählung fehlgeschlagen: Signal inspect_failed');
    T::same(spOk(['inspect_failed'], spExpectedHash('203.0.113.0/24')), $w->screen('reply', ['content' => null]), 'Zählung fehlt: inspect_failed (fail-closed)');
    T::same(spOk([], spExpectedHash('203.0.113.0/24')), $w->screen('reply'), 'sauberer Beitrag: ok, kein Signal, ip_hash');
    T::same(spOk(['word_review'], null), $w->screen('edit', ['post_id' => 1, 'verdict' => ContentGuard::REVIEW]), 'Bearbeiten: review, kein ip_hash');
    foreach (['report', 'delete', ''] as $action) {
        try {
            $w->screen($action);
            T::check(false, 'unbekannte Aktion ' . json_encode($action) . ' wirft');
        } catch (\InvalidArgumentException) {
            T::check(true, 'unbekannte Aktion ' . json_encode($action) . ' wirft InvalidArgumentException');
        }
    }
});

T::run('screen(): Core-Antispam (S5): Kontext ohne Schemata, mit Hosts und Kontodaten; nur Web; Ausnahme fail-open', static function (): void {
    $w = new SpWorld(['use_core_antispam' => true]);
    $body = 'Besuche https://evil.example/a und HTTPS://www.x.example oder ftp://files.example/f jetzt';
    $w->screen('create_topic', ['title' => 'Mein Titel', 'body' => $body, 'hosts' => ['evil.example', 'www.x.example']]);
    T::same([[
        'content' => "Mein Titel\nBesuche evil.example/a und www.x.example oder files.example/f jetzt\nevil.example\nwww.x.example",
        'email' => 'anna@example.test', 'author_name' => 'Anna A.', 'ip_address' => '203.0.113.77', 'user_agent' => 'Testbrowser/1.0',
        'source' => 'community', 'honeypot_value' => '', 'started_at' => 0,
    ]], $w->antispamCalls, 'Kontext: Titel, Text ohne http(s)/ftp-Schemata, Hosts, E-Mail, Anzeigename, IP, User-Agent, source = community');

    $w->db->execute('UPDATE cms_users SET display_name = ? WHERE id = ?', ['', $w->user]);
    $w->antispamCalls = [];
    $w->screen('reply', ['body' => "jetzt kau\u{034F}fen"]);
    T::same(['jetzt kaufen', 'anna'], [$w->antispamCalls[0]['content'] ?? null, $w->antispamCalls[0]['author_name'] ?? null], 'Antwort ohne Titel; unsichtbare Zeichen entfernt (ohne Faltung); ohne Anzeigename der Benutzername');

    $w->antispamResult = ['rejected' => true, 'reason' => 'blacklist'];
    $w->db->log = [];
    T::same(['ok' => false, 'error' => 'spam_rejected'], $w->screen('reply', ['body' => SP_LONG]), 'abgewiesen: spam_rejected');
    T::same([], spStatements($w->db, 'community_posts'), 'abgewiesen: keine Abfrage der Spam-Dublette');

    $w->client = SP_CLI;
    $w->antispamCalls = [];
    T::same(spOk(), $w->screen('reply'), 'CLI: kein Antispam (abgewiesen wäre der Beitrag sonst)');
    T::same([], $w->antispamCalls, 'CLI: der Core-Antispam wird nicht gerufen');

    $w->client = SP_WEB;
    $w->antispamThrows = new \RuntimeException('Antispam kaputt');
    spLogReset();
    T::same(spOk([], spExpectedHash('203.0.113.0/24')), $w->screen('reply'), 'Ausnahme im Antispam: erlaubt (fail-open)');
    T::check(str_contains(spLog(), 'Antispam kaputt'), 'Ausnahme im Antispam: Warnung');
    $w->antispamThrows = null;
    $w->antispamResult = ['rejected' => 'ja'];
    T::same(true, $w->screen('reply')['ok'], 'nur rejected === true weist ab');
});

T::run('screen(): Core-Antispam mit dem echten AntispamService (Sperrliste, Linkgrenze, Prozent-Kodierung, CGJ)', static function (): void {
    $db = CommunityTestDatabase::create();
    $db->sqlite->exec("CREATE TABLE cms_spam_blacklist (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL DEFAULT 'word', value TEXT NOT NULL)");
    spCoreTable($db, 'security_log');
    $previous = spSwapDatabase($db);
    try {
        $db->execute('INSERT INTO cms_settings (option_name, option_value) VALUES (?, ?)', ['antispam_enabled', '1']);
        foreach (['evil.com', 'kaufen'] as $word) {
            $db->execute('INSERT INTO cms_spam_blacklist (type, value) VALUES (?, ?)', ['word', $word]);
        }
        $w = new SpWorld(['use_core_antispam' => true], ['antispam' => true], $db);
        $hosts = ContentRenderer::instance()->analyze('[a](https://evil%2Ecom/x)')['stats']['hosts'];
        T::same(['ok' => false, 'error' => 'spam_rejected'], $w->screen('reply', ['body' => '[a](https://evil%2Ecom/x)', 'hosts' => $hosts]), 'Sperrwort evil.com trifft evil%2Ecom über die normalisierten Hosts');
        T::same(['ok' => false, 'error' => 'spam_rejected'], $w->screen('reply', ['body' => "Jetzt kau\u{034F}fen!"]), 'Sperrwort mit CGJ dazwischen: spam_rejected');
        $links = 'Links: https://a.example/1 https://b.example/2 https://c.example/3 https://d.example/4 https://e.example/5';
        T::same(true, $w->screen('reply', ['body' => $links])['ok'], 'fünf Links: die Core-Linkgrenze (antispam_max_links 3) greift nicht, weil die Schemata entfernt sind');
        T::same(true, $w->screen('reply', ['body' => 'Ein harmloser Text'])['ok'], 'harmloser Text: erlaubt');
    } finally {
        spSwapDatabase($previous);
    }
});

T::run('screen(): Spam-Dublette über Themen (S4): Fenster, Mindestlänge, Faltung, Status, Bearbeiten, Grenze der Abfrage', static function (): void {
    $w = new SpWorld();
    $hash = spExpectedHash('203.0.113.0/24');
    $w->post(SP_LONG, 23 * 3600 + 1800);
    T::same(spOk(['duplicate'], $hash), $w->screen('reply', ['body' => SP_LONG]), 'gleicher Text vor 23:30 h in anderem Thema: Signal duplicate');
    $sql = spStatements($w->db, 'community_posts');
    T::same(1, count($sql), 'eine Abfrage');
    $index = array_search($sql[0], $w->db->log, true);
    T::same([$w->user, ...['published', 'pending', 'hidden', 'deleted', 'rejected'], spUtc(-86400), ContentGuard::DUPLICATE_SCAN], $w->db->paramsAt((int) $index), 'Parameter: Mitglied, alle Status, seit = jetzt − 24 h (UTC), höchstens DUPLICATE_SCAN');
    T::same(false, $w->db->txAt((int) $index), 'die Abfrage läuft außerhalb einer Transaktion');
    T::same(spOk(['duplicate'], $hash), $w->screen('reply', ['body' => "  KAUFEN Sie jetzt   die besten Uhren zum\nhalben Preis bei uns im Laden  "]), 'Leerraum und Groß/klein ändern den Fingerabdruck nicht');
    T::same(spOk(['duplicate'], $hash), $w->screen('reply', ['body' => str_replace(' ', " \u{FE00}", SP_LONG)]), 'eingestreutes U+FE00 ändert den Fingerabdruck nicht (Vertrag §4.9)');
    T::same(spOk([], $hash), $w->screen('reply', ['body' => SP_LONG . ' Anders.']), 'anderer Text: keine Dublette');

    $old = new SpWorld();
    $old->post(SP_LONG, 24 * 3600 + 1800);
    T::same(spOk([], $hash), $old->screen('reply', ['body' => SP_LONG]), 'gleicher Text vor 24:30 h: keine Dublette');
    $edge = new SpWorld();
    $edge->post(SP_LONG, 24 * 3600);
    T::same(spOk(['duplicate'], $hash), $edge->screen('reply', ['body' => SP_LONG]), 'genau 24 h alt: im Fenster (created_at >= seit)');

    $short = new SpWorld();
    $forty = str_repeat('a', 39) . 'b';
    $short->post('Danke!', 60);
    $short->post($forty, 60);
    $short->post(substr($forty, 1), 60);
    T::same(spOk([], $hash), $short->screen('reply', ['body' => 'Danke!']), 'kurzer Text („Danke!“): keine Prüfung');
    T::same(spOk([], $hash), $short->screen('reply', ['body' => substr($forty, 1)]), '39 Zeichen: keine Prüfung');
    T::same(spOk(['duplicate'], $hash), $short->screen('reply', ['body' => $forty]), 'genau DUPLICATE_MIN_CHARS = 40 Zeichen: geprüft');

    $states = new SpWorld();
    $states->post(SP_LONG . ' A', 60, ['status' => 'deleted', 'status_reason' => 'author']);
    $states->post(SP_LONG . ' B', 60, ['status' => 'deleted', 'status_reason' => 'moderator']);
    $states->post(SP_LONG . ' C', 60, ['status' => 'rejected']);
    $states->post(SP_LONG . ' D', 60, ['status' => 'hidden']);
    $states->post(SP_LONG . ' E', 60, ['status' => 'pending']);
    $states->post(SP_LONG . ' F', 60, ['status' => 'deleted', 'status_reason' => null]);
    T::same(spOk([], $hash), $states->screen('reply', ['body' => SP_LONG . ' A']), 'vom Autor gelöschter Beitrag zählt nicht');
    T::same(spOk([], $hash), $states->screen('reply', ['body' => SP_LONG . ' F']), 'gelöschter Beitrag ohne Grund zählt wie vom Autor gelöscht');
    foreach (['B' => 'von der Moderation gelöscht', 'C' => 'abgelehnt', 'D' => 'ausgeblendet', 'E' => 'wartend'] as $suffix => $label) {
        T::same(spOk(['duplicate'], $hash), $states->screen('reply', ['body' => SP_LONG . ' ' . $suffix]), $label . ': zählt (erneutes Posten entfernten Spams)');
    }

    $edit = new SpWorld();
    $self = $edit->post(SP_LONG, 60);
    T::same(spOk(), $edit->screen('edit', ['post_id' => $self, 'body' => SP_LONG]), 'Bearbeiten vergleicht nicht mit dem Beitrag selbst');
    $edit->post(SP_LONG, 120);
    T::same(spOk(['duplicate']), $edit->screen('edit', ['post_id' => $self, 'body' => SP_LONG]), 'Bearbeiten: ein anderer gleicher Beitrag ist eine Dublette (Köder und Tausch)');

    $scan = new SpWorld();
    $scan->post(SP_LONG, 3600);
    for ($i = 0; $i < ContentGuard::DUPLICATE_SCAN; $i++) {
        $scan->post(SP_LONG . ' Nummer ' . $i, 60);
    }
    T::same(spOk([], $hash), $scan->screen('reply', ['body' => SP_LONG]), 'nur die DUPLICATE_SCAN = 20 jüngsten Beiträge werden verglichen');

    $other = new SpWorld();
    $other->post(SP_LONG, 60, ['user' => $other->db->user()]);
    T::same(spOk([], $hash), $other->screen('reply', ['body' => SP_LONG]), 'gleicher Text eines anderen Mitglieds: keine Dublette');

    $failing = new SpWorld();
    $failing->db->failOn = ['/FROM cms_community_posts/'];
    try {
        $failing->screen('reply', ['body' => SP_LONG]);
        T::check(false, 'Datenbankfehler der Dublettenabfrage geht an den Dienst (db_error)');
    } catch (\PDOException) {
        T::check(true, 'Datenbankfehler der Dublettenabfrage geht als PDOException an den Dienst (db_error)');
    }
});

T::run('screen(): Filter cms_community_spam_check (S6): verschärft, lockern verworfen, Ausnahme ⇒ review, Eingabe, außerhalb der Transaktion', static function (): void {
    $w = new SpWorld();
    $hash = spExpectedHash('203.0.113.0/24');
    $seen = [];
    $answer = 'allow';
    $filter = static function (string $verdict, int $userId, string $action, array $input) use (&$seen, &$answer, $w): mixed {
        $seen[] = [$verdict, $userId, $action, $input, $w->db->getPdo()->inTransaction()];
        if ($answer === 'throw') {
            throw new \RuntimeException('Plugin kaputt');
        }

        return $answer;
    };
    Hooks::addFilter(Events::SPAM_CHECK, $filter);
    try {
        $content = ['links' => 2, 'images' => 1, 'attachments' => 0, 'failed' => false];
        T::same(spOk([], $hash), $w->screen('create_topic', ['forum_id' => 3, 'topic_id' => 0, 'title' => 'T', 'body' => 'B', 'content' => $content]), 'Filter lässt zu: allow');
        T::same([['allow', $w->user, 'create_topic', ['forum_id' => 3, 'topic_id' => 0, 'post_id' => 0, 'title' => 'T', 'body' => 'B', 'links' => 2, 'images' => 1, 'signals' => []], false]], $seen, 'Filter erhält Urteil, Mitglied, Aktion und die Eingabe (mit Titel und Text), außerhalb einer Transaktion');

        $answer = 'review';
        T::same(spOk(['filter'], $hash), $w->screen('reply'), 'Filter verschärft: review mit Signal filter');

        $answer = 'allow';
        $seen = [];
        spLogReset();
        T::same(spOk(['word_review'], $hash), $w->screen('reply', ['verdict' => ContentGuard::REVIEW]), 'allow nach review (lockern): verworfen');
        T::same('review', $seen[0][0] ?? null, 'der Filter sieht das Urteil review');
        T::same(['word_review'], $seen[0][3]['signals'] ?? null, 'der Filter sieht die Signale');
        T::check(spLog() !== '', 'lockern: Warnung');

        $answer = 'block';
        spLogReset();
        T::same(spOk([], $hash), $w->screen('reply'), 'unbekanntes Ergebnis (block): verworfen');
        T::check(spLog() !== '', 'unbekanntes Ergebnis: Warnung');

        $answer = 'throw';
        spLogReset();
        T::same(spOk(['filter'], $hash), $w->screen('reply'), 'Ausnahme im Filter: review (fail-closed)');
        T::check(str_contains(spLog(), 'Plugin kaputt'), 'Ausnahme im Filter: Warnung');

        $answer = 'allow';
        $seen = [];
        $w->screen('reply', ['verdict' => ContentGuard::BLOCK]);
        $spam = new SpWorld(['use_core_antispam' => true]);
        $spam->antispamResult = ['rejected' => true];
        $spam->screen('reply');
        T::same([], $seen, 'nach word_blocked und spam_rejected läuft der Filter nicht');
    } finally {
        Hooks::removeFilter(Events::SPAM_CHECK, $filter);
    }

    $first = static fn (string $verdict): string => 'review';
    $second = static fn (string $verdict): string => 'allow';
    Hooks::addFilter(Events::SPAM_CHECK, $first, 5);
    Hooks::addFilter(Events::SPAM_CHECK, $second, 20);
    try {
        T::same(spOk(['filter'], $hash), $w->screen('reply'), 'zwei Filter: das Verschärfen des ersten hebt der zweite nicht auf');
    } finally {
        Hooks::removeFilter(Events::SPAM_CHECK, $first, 5);
        Hooks::removeFilter(Events::SPAM_CHECK, $second, 20);
    }
});

T::run('screen(): ip_hash nur beim Anlegen und Antworten, nur mit Web-Anfrage und Geheimnis', static function (): void {
    $w = new SpWorld();
    $hash = spExpectedHash('203.0.113.0/24');
    T::same([$hash, $hash, null], [$w->screen('create_topic', ['forum_id' => $w->forum])['ip_hash'], $w->screen('reply')['ip_hash'], $w->screen('edit', ['post_id' => 1])['ip_hash']], 'create_topic und reply: IpHash::hash(Client-IP); edit: null');
    $w->client = ['web' => true, 'ip' => '2001:db8:abcd:12::1', 'user_agent' => 'x'];
    T::same(spExpectedHash('2001:db8:abcd::/48'), $w->screen('reply')['ip_hash'], 'IPv6: Hash des /48');
    foreach (['CLI' => SP_CLI, 'ohne IP' => ['web' => true, 'ip' => '', 'user_agent' => 'x'], 'ungültige IP' => ['web' => true, 'ip' => 'kein-ip', 'user_agent' => 'x']] as $label => $client) {
        $w->client = $client;
        T::same(null, $w->screen('reply')['ip_hash'], $label . ': ip_hash null');
    }
    $none = new SpWorld([], ['secret' => static fn (): ?string => null]);
    T::same(null, $none->screen('reply')['ip_hash'], 'ohne Geheimnis: ip_hash null');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Request-Grenzen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('throttle(): Tabelle laut Vertrag §3.13, Kennungen ohne Klar-IP, Spaltenbreiten von login_attempts', static function (): void {
    T::same([
        'search' => [10, 30, 60], 'preview' => [0, 10, 60], 'react' => [30, 30, 60], 'react_notify' => [0, 10, 3600],
        'subscribe' => [30, 30, 60], 'upload' => [0, 30, 3600], 'edit' => [0, 20, 600], 'reject_log' => [30, 30, 3600],
    ], ContentGuard::THROTTLE, 'THROTTLE = Vertrag §3.13 (report entfällt: die Meldegrenze setzt ReportService unter Sperre durch)');
    T::same(120, ContentGuard::SEARCH_GUESTS_TOTAL, 'SEARCH_GUESTS_TOTAL = 120 je 60 s');
    foreach (array_keys(ContentGuard::THROTTLE) as $action) {
        T::check(strlen('community_' . $action) <= Security::RATE_LIMIT_ACTION_MAX_LENGTH, 'Aktionsname community_' . $action . ' passt in login_attempts.action (30)');
    }
    T::check(strlen('community:g:' . str_repeat('f', 40)) <= Security::RATE_LIMIT_IDENTIFIER_MAX_LENGTH && strlen('community:u:4294967295') <= 60, 'Kennungen passen in login_attempts.username (60)');
    T::same(7200, 2 * max(array_column(ContentGuard::THROTTLE, 2)), 'Aufräumen nach 7.200 s = doppelte größte Fensterlänge');
});

T::run('throttle(): Mitglied je Konto, Gast je rateKey, Gäste der Suche zusätzlich gemeinsam, retry_after = Fenster (S7)', static function (): void {
    $w = new SpWorld();
    $hash = new IpHash(static fn (): string => SP_SECRET);
    T::same(['allowed' => true, 'retry_after' => 0], $w->guard->throttle('preview', 7), 'Mitglied: erlaubt');
    T::same([['community_preview', 'community:u:7', 10, 60]], $w->limiterCalls, 'Mitglied: Kennung community:u:<id>, Mitgliedergrenze, Fenster');
    for ($i = 2; $i <= 10; $i++) {
        $w->guard->throttle('preview', 7);
    }
    T::same(['allowed' => false, 'retry_after' => 60], $w->guard->throttle('preview', 7), 'Mitglied: die 11. Vorschau in 60 s wird abgelehnt, retry_after = Fenster');
    T::same(['allowed' => true, 'retry_after' => 0], $w->guard->throttle('preview', 8), 'anderes Mitglied: eigener Eimer');
    T::same(0, $w->clientCalls, 'Mitglieder: kein Client gelesen');

    $w->limiterCalls = [];
    T::same(['allowed' => true, 'retry_after' => 0], $w->guard->throttle('search', null), 'Gast: Suche erlaubt');
    $key = 'community:g:' . $hash->rateKey('203.0.113.77');
    T::same([['community_search', $key, 10, 60], ['community_search', 'community:g:all', 120, 60]], $w->limiterCalls, 'Gast der Suche: Eimer je rateKey (Gästegrenze), dann der gemeinsame Eimer community:g:all (120 je 60 s)');
    T::check(!str_contains(json_encode($w->limiterCalls), '203.0.113'), 'keine Klar-IP in der Kennung');

    $w->limiterCalls = [];
    $w->client = ['web' => true, 'ip' => '203.0.113.5', 'user_agent' => 'x'];
    $w->guard->throttle('react', 0);
    T::same([['community_react', 'community:g:' . $hash->rateKey('203.0.113.5'), 30, 60]], $w->limiterCalls, 'Gast (Mitglied 0) mit anderer IP: anderer Eimer, kein gemeinsamer Eimer außer bei der Suche');

    $w->limiterCalls = [];
    $w->limiterFixed = [$key => false];
    $w->client = SP_WEB;
    T::same(['allowed' => false, 'retry_after' => 60], $w->guard->throttle('search', null), 'Eimer je IP voll: abgelehnt');
    T::same(1, count($w->limiterCalls), 'eine Ablehnung genügt: der gemeinsame Eimer wird nicht gezählt');

    $w->limiterCalls = [];
    $w->limiterFixed = ['community:g:all' => false];
    T::same(['allowed' => false, 'retry_after' => 60], $w->guard->throttle('search', null), 'gemeinsamer Eimer der Gäste voll: abgelehnt (rotierende Adressen umgehen nur die Grenze je IP)');

    $w->limiterFixed = [];
    $w->limiterCalls = [];
    T::same(['allowed' => false, 'retry_after' => 60], $w->guard->throttle('preview', null), 'Gast und Vorschau (Gästegrenze 0): abgelehnt');
    T::same(['community_preview', 0], [$w->limiterCalls[0][0] ?? null, $w->limiterCalls[0][2] ?? null], 'Gästegrenze 0 geht an den Limiter');
    $w->limiterCalls = [];
    $w->guard->throttle('edit', 7);
    $w->guard->throttle('react_notify', 7);
    $w->guard->throttle('upload', 7);
    T::same([['community_edit', 'community:u:7', 20, 600], ['community_react_notify', 'community:u:7', 10, 3600], ['community_upload', 'community:u:7', 30, 3600]], $w->limiterCalls, 'Mitgliedergrenzen edit 20/600 s, react_notify 10/3600 s, upload 30/3600 s');
});

T::run('throttle(): Gast ohne IP oder ohne Geheimnis im gemeinsamen Eimer community:g:unknown; unbekannte Aktion wirft; Limiter-Fehler fail-open', static function (): void {
    $w = new SpWorld();
    $w->client = ['web' => true, 'ip' => '', 'user_agent' => 'x'];
    spLogReset();
    $w->guard->throttle('react', null);
    $w->guard->throttle('react', null);
    T::same([['community_react', 'community:g:unknown', 30, 60], ['community_react', 'community:g:unknown', 30, 60]], $w->limiterCalls, 'ohne IP: community:g:unknown');
    T::same(1, substr_count(spLog(), 'community:g:unknown'), 'eine Warnung je Prozess');
    $none = new SpWorld([], ['secret' => static fn (): ?string => null]);
    $none->guard->throttle('subscribe', null);
    T::same([['community_subscribe', 'community:g:unknown', 30, 60]], $none->limiterCalls, 'ohne Geheimnis: community:g:unknown');
    foreach (['report', 'foo', ''] as $action) {
        try {
            $w->guard->throttle($action, 7);
            T::check(false, 'unbekannte Aktion ' . json_encode($action) . ' wirft');
        } catch (\InvalidArgumentException) {
            T::check(true, 'unbekannte Aktion ' . json_encode($action) . ' wirft InvalidArgumentException');
        }
    }
    $w->limiterThrows = new \RuntimeException('Tabelle fehlt');
    spLogReset();
    T::same(['allowed' => true, 'retry_after' => 0], $w->guard->throttle('preview', 7), 'Limiter wirft: erlaubt (fail-open wie der Core, Vertrag §3.13)');
    T::check(str_contains(spLog(), 'Tabelle fehlt'), 'Limiter wirft: Warnung');
});

T::run('throttle(): Standard-Limiter über Security und login_attempts: Vorprüfung ohne Eintrag, Pseudonym, Ortszeit des Cores', static function (): void {
    $db = CommunityTestDatabase::create();
    spCoreTable($db, 'login_attempts');
    $previous = spSwapDatabase($db);
    try {
        for ($i = 0; $i < 10; $i++) {
            $db->execute('INSERT INTO cms_login_attempts (username, ip_address, action, attempted_at) VALUES (?, ?, ?, ?)', ['community:u:9', '0.0.0.0', 'community_preview', date('Y-m-d H:i:s')]);
        }
        $w = new SpWorld([], ['limiter' => true], $db);
        $db->log = [];
        T::same(['allowed' => false, 'retry_after' => 60], $w->guard->throttle('preview', 9), 'Grenze erreicht (10 Einträge im Fenster): abgelehnt');
        $statements = spStatements($db, 'login_attempts');
        T::same(['SELECT COUNT(*) FROM cms_login_attempts WHERE username = ? AND action = ? AND attempted_at >= ?'], $statements, 'abgelehnt ohne Eintrag: eine Zählung, kein INSERT (Dauerbeschuss lässt die Tabelle nicht wachsen)');
        $params = $db->paramsAt((int) array_search($statements[0], $db->log, true));
        T::same(['community:u:9', 'community_preview'], array_slice($params, 0, 2), 'Kennung und Aktionsname');
        T::check(abs((int) strtotime((string) $params[2]) - (time() - 60)) <= 5, 'Fenster in Ortszeit des Cores (date() wie countDbIdentifierAttempts)', (string) $params[2]);
        T::same(10, $db->count('login_attempts', ['username' => 'community:u:9']), 'keine neue Zeile');
        $db->log = [];
        T::same(true, $w->guard->throttle('preview', 10)['allowed'], 'leerer Eimer: erlaubt');
        T::same(2, count(spStatements($db, 'login_attempts')), 'erlaubt: Vorprüfung und Nachzählung (der Eintrag läuft über Security::recordDbRateLimitAttempt)');

        // Gleichzeitige Anfragen, nachgestellt ohne Prozesse: zwischen Vorprüfung und Nachzählung erscheinen 11 Einträge anderer Anfragen
        // desselben Eimers (alle bestanden die Vorprüfung zur selben Zeit). Die Nachzählung liegt über der Grenze.
        $count = '/^SELECT COUNT\(\*\) FROM cms_login_attempts/';
        $db->beforeExecute[$count] = static function () use ($db, $count): void {
            $db->beforeExecute[$count] = static function () use ($db): void {
                for ($i = 0; $i < 11; $i++) {
                    $db->sqlite->exec("INSERT INTO cms_login_attempts (username, ip_address, action, attempted_at) VALUES ('community:u:11', '0.0.0.0', 'community_preview', '" . date('Y-m-d H:i:s') . "')");
                }
            };
        };
        $db->log = [];
        T::same(['allowed' => false, 'retry_after' => 60], $w->guard->throttle('preview', 11), 'Vorprüfung bestanden, Nachzählung über der Grenze (gleichzeitige Anfragen): abgelehnt');
        T::same([2, []], [count(spStatements($db, 'login_attempts')), $db->beforeExecute], 'Vorprüfung und Nachzählung, dazwischen die Einträge der anderen Anfragen');
    } finally {
        spSwapDatabase($previous);
    }
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Sicherheitsprotokoll und Aufräumen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('logRejection(): nur LOGGED_CODES, nur Web, gedrosselt über reject_log (31. Ablehnung je Stunde ohne Eintrag)', static function (): void {
    T::same(['word_blocked', 'too_many_links', 'too_many_images', 'too_many_attachments', 'content_review'], ContentGuard::LOGGED_CODES, 'LOGGED_CODES (spam_rejected protokolliert AntispamService selbst)');
    $w = new SpWorld();
    foreach (['spam_rejected', 'trust_limit', 'rate_limited', 'gibt_es_nicht'] as $code) {
        $w->guard->logRejection($code, $w->user);
    }
    T::same([[], []], [$w->securityLog, $w->limiterCalls], 'andere Codes: kein Eintrag, keine Drossel');
    $w->client = SP_CLI;
    $w->guard->logRejection('word_blocked', $w->user);
    T::same([[], []], [$w->securityLog, $w->limiterCalls], 'CLI: kein Eintrag, keine Drossel');
    $w->client = SP_WEB;
    $w->guard->logRejection('too_many_links', $w->user);
    T::same([['code' => 'too_many_links', 'user_id' => $w->user, 'ip' => '203.0.113.77', 'user_agent' => 'Testbrowser/1.0']], $w->securityLog, 'Eintrag: Code, Mitglied, IP, User-Agent (ohne Inhalt)');
    T::same([['community_reject_log', 'community:u:' . $w->user, 30, 3600]], $w->limiterCalls, 'Drossel reject_log je Konto: 30 je Stunde');
    for ($i = 0; $i < 35; $i++) {
        $w->guard->logRejection('word_blocked', $w->user);
    }
    T::same(30, count($w->securityLog), 'höchstens 30 Einträge je Stunde und Konto');
    $w->securityLogThrows = new \RuntimeException('Protokoll voll');
    $w->limiterRows = [];
    spLogReset();
    $w->guard->logRejection('content_review', $w->user);
    T::check(str_contains(spLog(), 'Protokoll voll'), 'Schreibfehler: keine Ausnahme, Warnung');
});

T::run('Standard-Schreiber des Sicherheitsprotokolls: security_log ohne created_at, Pfad ohne Query, IP oder NULL', static function (): void {
    $db = CommunityTestDatabase::create();
    spCoreTable($db, 'security_log');
    $w = new SpWorld([], ['securityLog' => true], $db);
    $_SERVER['REQUEST_URI'] = '/forum/t/1-x?token=geheim';
    try {
        $w->guard->logRejection('word_blocked', $w->user);
        $w->client = ['web' => true, 'ip' => '', 'user_agent' => "Bot\x01/2"];
        $w->guard->logRejection('too_many_images', $w->user);
    } finally {
        unset($_SERVER['REQUEST_URI']);
    }
    $rows = $db->rows('security_log', [], 'id');
    T::same(
        [
            ['antispam_rejected', '203.0.113.77', '/forum/t/1-x', 'Testbrowser/1.0', 'community:word_blocked', $w->user, '{"reason":"word_blocked","source":"community"}'],
            ['antispam_rejected', null, '/forum/t/1-x', 'Bot /2', 'community:too_many_images', $w->user, '{"reason":"too_many_images","source":"community"}'],
        ],
        array_map(static fn (array $r): array => [$r['action'], $r['ip_address'], $r['request_uri'], $r['user_agent'], $r['rule_matched'], (int) $r['user_id'], $r['extra']], $rows),
        'Zeilen: antispam_rejected, IP (leer ⇒ NULL), Pfad ohne Query, User-Agent ohne Steuerzeichen, community:<code>, Mitglied, JSON ohne Inhalt'
    );
    $insert = array_values(array_filter(spStatements($db, 'security_log'), static fn (string $sql): bool => str_starts_with($sql, 'INSERT')));
    T::check(count($insert) === 2 && !str_contains($insert[0], 'created_at'), 'INSERT ohne created_at (Sitzungszeit des Cores wie alle Einträge)', $insert[0] ?? '');
});

T::run('pruneThrottle() und Security::pruneDbRateLimitAttempts(): nur Präfix community_, älter als 7.200 s, wirft nie', static function (): void {
    $db = CommunityTestDatabase::create();
    spCoreTable($db, 'login_attempts');
    $previous = spSwapDatabase($db);
    try {
        $w = new SpWorld([], [], $db);
        $db->log = [];
        $w->guard->pruneThrottle();
        $statements = spStatements($db, 'login_attempts');
        T::same(['DELETE FROM cms_login_attempts WHERE action LIKE ? AND attempted_at < ?'], $statements, 'eine Anweisung (idx_action)');
        $params = $db->paramsAt((int) array_search($statements[0], $db->log, true));
        T::same('community\\_%', $params[0] ?? null, 'Präfix mit maskiertem _ (addcslashes), dann %');
        T::check(abs((int) strtotime((string) ($params[1] ?? '')) - (time() - 7200)) <= 5, 'älter als 7.200 s, Ortszeit wie countDbIdentifierAttempts()', (string) ($params[1] ?? ''));
        $db->log = [];
        T::same(0, Security::pruneDbRateLimitAttempts('', 100), 'leeres Präfix: 0 ohne Anweisung (löschte sonst alle Anmeldeversuche)');
        T::same([], $db->log, 'leeres Präfix: keine Anweisung');
        $db->failOn = ['/DELETE FROM cms_login_attempts/'];
        T::same(0, Security::pruneDbRateLimitAttempts('community_', 7200), 'Datenbankfehler: 0');
        $w->guard->pruneThrottle();
        T::check(true, 'pruneThrottle() wirft bei Datenbankfehlern nicht');
    } finally {
        spSwapDatabase($previous);
    }
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Register, Quelltext
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Register: Codes, Sprachschlüssel, Hook; Quelltext ohne Uhr und ohne Transaktion', static function (): void {
    T::same([true, true], [in_array('word_blocked', Codes::ERRORS, true), in_array('spam_rejected', Codes::ERRORS, true)], 'word_blocked und spam_rejected in Codes::ERRORS');
    try {
        foreach (['word_blocked', 'spam_rejected'] as $code) {
            $de = I18n::t('community.error.' . $code, [], 'de');
            $en = I18n::t('community.error.' . $code, [], 'en');
            T::check($de !== 'community.error.' . $code && $en !== 'community.error.' . $code && $de !== $en, 'community.error.' . $code . ' in DE und EN', $de . ' | ' . $en);
        }
    } finally {
        I18n::reset();
    }
    T::same('cms_community_spam_check', Events::SPAM_CHECK, 'Filter Events::SPAM_CHECK');
    T::same(['allow', 'review', 'block', 20, 40], [ContentGuard::ALLOW, ContentGuard::REVIEW, ContentGuard::BLOCK, ContentGuard::DUPLICATE_SCAN, ContentGuard::DUPLICATE_MIN_CHARS], 'Konstanten');
    foreach (['Service/ContentGuard.php', 'Support/IpHash.php'] as $file) {
        $code = (string) file_get_contents(ABSPATH . 'core/Services/Community/' . $file);
        T::same([0, 0, 0], [preg_match('/(?<![\w>:$])time\s*\(/', $code), substr_count($code, 'Transaction::run'), preg_match('/\bFOR\s+UPDATE\b/', $code)], $file . ': keine Uhr, keine Transaktion, keine Sperre');
    }
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Wirkung der Einstellungen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Wirkung: word_filter', static function (): void {
    $default = new SpWorld();
    T::same(['body' => 'spam und mist', 'verdict' => ContentGuard::ALLOW], array_intersect_key($default->guard->filterText(null, 'spam und mist'), ['body' => 1, 'verdict' => 1]), 'Standard (leer): nichts gefiltert');
    T::same(spOk([], spExpectedHash('203.0.113.0/24')), $default->screen('reply', ['hosts' => ['spam.example']]), 'Standard: Hosts frei');
    $w = new SpWorld(spFilter(['spam'], ['prüf'], ['mist'], '%%'));
    T::same(['body' => 'spam und mist', 'verdict' => ContentGuard::BLOCK], array_intersect_key($w->guard->filterText(null, 'spam und mist'), ['body' => 1, 'verdict' => 1]), 'block-Liste: block');
    T::same(['body' => '%% und prüf', 'verdict' => ContentGuard::REVIEW], array_intersect_key($w->guard->filterText(null, 'mist und prüf'), ['body' => 1, 'verdict' => 1]), 'replace-Liste mit eigenem Ersatztext und review-Liste');
    T::same(['ok' => false, 'error' => 'word_blocked'], $w->screen('reply', ['hosts' => ['spam.example']]), 'block-Liste wirkt auch auf Hosts');
});

T::run('Wirkung: use_core_antispam', static function (): void {
    $off = new SpWorld();
    $off->antispamResult = ['rejected' => true];
    T::same([true, []], [$off->screen('reply')['ok'], $off->antispamCalls], 'Standard (aus): kein Core-Antispam');
    $on = new SpWorld(['use_core_antispam' => true]);
    $on->antispamResult = ['rejected' => true];
    T::same(['ok' => false, 'error' => 'spam_rejected'], $on->screen('reply'), 'an: Core-Antispam weist ab');
    T::same(1, count($on->antispamCalls), 'an: ein Aufruf');
});

T::run('Wirkung: spam_duplicate_hours', static function (): void {
    $hash = spExpectedHash('203.0.113.0/24');
    $default = new SpWorld();
    $default->post(SP_LONG, 23 * 3600);
    T::same(['duplicate'], $default->screen('reply', ['body' => SP_LONG])['signals'], 'Standard 24 h: Beitrag von vor 23 h ist eine Dublette');
    $off = new SpWorld(['spam_duplicate_hours' => 0]);
    $off->post(SP_LONG, 60);
    T::same(spOk([], $hash), $off->screen('reply', ['body' => SP_LONG]), '0: aus');
    T::same([], spStatements($off->db, 'community_posts'), '0: keine Abfrage');
    $one = new SpWorld(['spam_duplicate_hours' => 1]);
    $one->post(SP_LONG, 5400);
    T::same(spOk([], $hash), $one->screen('reply', ['body' => SP_LONG]), '1 h: Beitrag von vor 1:30 h zählt nicht');
    $one->post(SP_LONG, 1800);
    T::same(spOk(['duplicate'], $hash), $one->screen('reply', ['body' => SP_LONG]), '1 h: Beitrag von vor 0:30 h zählt');
    $statement = spStatements($one->db, 'community_posts')[0] ?? '';
    T::same(spUtc(-3600), $one->db->paramsAt((int) array_search($statement, $one->db->log, true))[6] ?? null, '1 h: seit = jetzt − 3600 s (UTC)');
});

T::run('Wirkung: ip_hash_days', static function (): void {
    $default = new SpWorld();
    T::same(spExpectedHash('203.0.113.0/24'), $default->screen('reply')['ip_hash'], 'Standard 30: ip_hash gespeichert');
    $off = new SpWorld(['ip_hash_days' => 0]);
    T::same([null, null], [$off->screen('reply')['ip_hash'], $off->screen('create_topic', ['forum_id' => $off->forum])['ip_hash']], '0: kein ip_hash');
});

// Teildateien weiterer Besitzer (T: part-services.php in W3; Vertrag §1 Nr. 6).
foreach (glob(__DIR__ . '/part-*.php') ?: [] as $part) {
    require $part;
}

T::finish();
