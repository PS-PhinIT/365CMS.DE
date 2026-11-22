<?php
/**
 * Community-Fundament, Teil Einstellungen und Support (4.2.0, PR #2a und Register aus PR #2b): Definitionen und Normalisierung
 * von `Support\Settings` (33 Schlüssel, Schemas `trust_limits`, `permissions`, `word_filter`, `reaction_set`, `attachment_types`),
 * Lesen/Speichern über Closures und über `SettingsService`, `Slugger`, `Urls`, `Cursor`, `Page`, `I18n`, `Events`,
 * `CommunityModule`, `CommunityHooks`, Codes ↔ Sprachschlüssel (de/en zeilenparallel, danach die feste Liste `CS_EXTRA_KEYS`),
 * Modul-Eintrag, Registry-Eintrag, Hook-Konstanten ↔ HOOKS-REFERENCE.md, Zeitwächter über `Services/Community/**` und die
 * Prüfung, dass jede Einstellung einen Leser und einen Wirkungstest hat (fehlende Dateien der Dienste: Hinweis statt Fehler).
 *
 * Ohne Datenbank (SettingsService läuft über eine Attrappe im Speicher). Aufruf aus dem Repository-Wurzelverzeichnis:
 * php TESTS/community-settings/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');

date_default_timezone_set('Europe/Berlin');

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
require_once dirname(__DIR__) . '/lib/MariaDbTestDatabase.php';

use CMS\Database;
use CMS\Hooks;
use CMS\Services\Community\Support\Codes;
use CMS\Services\Community\Support\CommunityCapabilities;
use CMS\Services\Community\Support\CommunityModule;
use CMS\Services\Community\Support\Cursor;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Page;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Slugger;
use CMS\Services\Community\Support\Urls;
use CMS\Services\CoreModuleService;
use CMS\Services\FeatureHooks\CommunityHooks;
use CMS\Services\FeatureHooks\Registry;
use CMS\Services\SettingsService;
use CMS\Services\OptionStore;

T::$verbose = in_array('-v', $argv ?? [], true);

const CS_ROOT = ABSPATH . 'core/Services/Community/';
const CS_DOC_HOOKS = __DIR__ . '/../../DOC/core/HOOKS-REFERENCE.md';

function csRead(string $path): string
{
    return (string) file_get_contents($path);
}

/** @return list<string> */
function csPhpFiles(string $directory): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/**
 * Zeichenketten-Literale und Namen (Methoden, Konstanten) des Codes ohne Kommentare.
 *
 * @return array{strings: array<string, true>, names: array<string, true>}
 */
function csCodeTokens(string $php): array
{
    $strings = [];
    $names = [];
    foreach (token_get_all($php) as $token) {
        if (!is_array($token)) {
            continue;
        }
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $strings[substr($token[1], 1, -1)] = true;
        } elseif ($token[0] === T_STRING) {
            $names[$token[1]] = true;
        }
    }

    return ['strings' => $strings, 'names' => $names];
}

/**
 * Verbotene Zeitmuster aus Vertrag §1 (Zeit nur über gmdate() mit gebundenem Parameter, keine OFFSET-Paginierung).
 *
 * @return list<string> Namen der getroffenen Muster
 */
function csTimeGuardHits(string $source): array
{
    $patterns = [
        'NOW()' => '/\bNOW\s*\(/i',
        'CURRENT_TIMESTAMP' => '/\bCURRENT_TIMESTAMP\b/i',
        'CURDATE()' => '/\bCURDATE\s*\(/i',
        'UTC_TIMESTAMP()' => '/\bUTC_TIMESTAMP\s*\(/i',
        'date(' => '/(?<![\w$>:])(?<!function )date\s*\(/',
        'OFFSET' => '/\bOFFSET\b/i',
    ];
    $hits = [];
    foreach ($patterns as $name => $pattern) {
        if (preg_match($pattern, $source) === 1) {
            $hits[] = $name;
        }
    }

    return $hits;
}

/**
 * Schlüssel und Werte einer Sprachdatei in Dateireihenfolge (Zeilen `  "schluessel": "Wert"`).
 *
 * @return list<array{0: string, 1: string}>
 */
function csLang(string $locale): array
{
    $rows = [];
    foreach (file(ABSPATH . 'lang/' . $locale . '.yaml', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s+"([^"]+)":\s*"((?:[^"\\\\]|\\\\.)*)"\s*$/', $line, $m) === 1) {
            $rows[] = [$m[1], $m[2]];
        }
    }

    return $rows;
}

/** @return list<string> */
function csPlaceholders(string $text): array
{
    preg_match_all('/\{[a-z_]+\}/', $text, $m);
    $found = array_unique($m[0]);
    sort($found);

    return $found;
}

/** Attrappe der Tabelle `settings` im Speicher; SettingsService liest und schreibt dagegen. */
function csSettingsDatabase(array &$rows, array &$autoload): Database
{
    return new class ($rows, $autoload) extends Database {
        public function __construct(private array &$rows, private array &$autoload)
        {
        }

        public function execute(string $sql, array $params = []): \PDOStatement
        {
            if (str_contains($sql, 'INSERT INTO') && str_contains($sql, 'settings')) {
                $this->rows[(string) $params[0]] = (string) $params[1];
                $this->autoload[(string) $params[0]] = (int) $params[2];
            }

            return (new PDO('sqlite::memory:'))->query('SELECT 1');
        }

        public function get_results(string $query, array $params = []): array
        {
            $prefix = rtrim((string) ($params[0] ?? ''), '%');
            $found = [];
            foreach ($this->rows as $name => $value) {
                if (str_starts_with((string) $name, $prefix)) {
                    $found[] = (object) ['option_name' => $name, 'option_value' => $value];
                }
            }

            return $found;
        }
    };
}

// ---------------------------------------------------------------------------------------------------------------------

T::run('Dateikonventionen', static function (): void {
    $files = [
        'Support/Settings.php' => 'Settings', 'Support/Slugger.php' => 'Slugger', 'Support/Urls.php' => 'Urls',
        'Support/I18n.php' => 'I18n', 'Support/Events.php' => 'Events', 'Support/CommunityModule.php' => 'CommunityModule',
    ];
    foreach ($files as $relative => $class) {
        $source = csRead(CS_ROOT . $relative);
        T::check(str_contains($source, 'declare(strict_types=1);'), $relative . ': strict_types');
        T::check(str_contains($source, 'namespace CMS\\Services\\Community\\Support;'), $relative . ': Namespace entspricht dem Ordner');
        T::check(str_contains($source, "final class {$class}\n"), $relative . ': final class ' . $class);
        T::check(str_contains($source, "if (!defined('ABSPATH')) {\n    exit;\n}"), $relative . ': ABSPATH-Schutz');
        T::check(str_contains($source, '@package CMSv2\\Core\\Services\\Community'), $relative . ': Paket im Kopfkommentar');
        T::check(class_exists('CMS\\Services\\Community\\Support\\' . $class), $relative . ': Klasse über den Autoloader ladbar');
    }
    $hooks = csRead(ABSPATH . 'core/Services/FeatureHooks/CommunityHooks.php');
    T::check(str_contains($hooks, 'declare(strict_types=1);') && str_contains($hooks, "final class CommunityHooks\n"), 'CommunityHooks: strict_types, final class');
    T::same('community', Settings::GROUP, 'Einstellungsgruppe heißt community');
    T::same('community', CommunityModule::SLUG, 'Modul-Slug heißt community');
});

T::run('Definitionen: Typen, Standards und Grenzen laut Vertrag', static function (): void {
    // [Typ, Standard, min, max] aus Vertrag §3, unabhängig von der Klasse eingetragen
    $expected = [
        'base_path' => ['slug', 'forum'],
        'guests_can_read' => ['bool', false],
        'require_verified_email' => ['bool', true],
        'topics_per_page' => ['int', 20, 5, 100],
        'posts_per_page' => ['int', 15, 5, 100],
        'title_min' => ['int', 5, 1, 100],
        'title_max' => ['int', 200, 10, 200],
        'post_min' => ['int', 2, 1, 1000],
        'post_max' => ['int', 50000, 100, 200000],
        'edit_window_minutes' => ['int', 30, 0, 10080],
        'edit_window_minutes_trusted' => ['int', 1440, 0, 10080],
        'rate_post_seconds' => ['int', 30, 0, 3600],
        'rate_topic_seconds' => ['int', 120, 0, 86400],
        'new_member_approval_posts' => ['int', 2, 0, 50],
        'max_pending_per_user' => ['int', 3, 1, 20],
        'duplicate_window_seconds' => ['int', 120, 10, 3600],
        'trust_level1_posts' => ['int', 1, 0, 1000],
        'trust_level2_posts' => ['int', 20, 1, 10000],
        'trust_limits' => ['map'],
        'default_permissions' => ['map'],
        'unread_horizon_days' => ['int', 90, 1, 180],
        // PR #2b (Vertrag DESIGN-2b §3.1, Nr. 22 bis 33)
        'word_filter' => ['map'],
        'use_core_antispam' => ['bool', false],
        'spam_duplicate_hours' => ['int', 24, 0, 168],
        'ip_hash_days' => ['int', 30, 0, 90],
        'moved_stub_days' => ['int', 7, 1, 365],
        'moderation_reason_required' => ['bool', true],
        'report_hide_threshold' => ['int', 3, 0, 20],
        'moderator_ban_max_days' => ['int', 30, 1, 365],
        'notify_mail' => ['bool', true],
        'reaction_set' => ['map'],
        'attachment_types' => ['map'],
        'attachment_max_kb' => ['int', 5120, 64, 51200],
        // PR #5 (Captcha, E7)
        'captcha_mode' => ['enum', 'first_post'],
        'captcha_provider' => ['enum', 'pow'],
    ];
    T::same(array_keys($expected), array_keys(Settings::DEFINITIONS), 'Schlüsselliste und Reihenfolge stimmen mit Vertrag §3 überein (#2a, #2b, dann PR #5)');
    T::same(35, count(Settings::DEFINITIONS), 'insgesamt 35 Schlüssel (33 bis PR #4, dazu captcha_mode und captcha_provider)');
    T::same(
        ['captcha_mode' => ['off', 'first_post', 'trust0', 'always'], 'captcha_provider' => ['pow', 'image', 'math']],
        ['captcha_mode' => Settings::DEFINITIONS['captcha_mode']['values'], 'captcha_provider' => Settings::DEFINITIONS['captcha_provider']['values']],
        'enum: die Werte der beiden Captcha-Einstellungen'
    );
    T::same(
        ['trust_limits' => 'trust_limits', 'default_permissions' => 'permissions', 'word_filter' => 'word_filter', 'reaction_set' => 'reaction_set', 'attachment_types' => 'attachment_types'],
        array_map(static fn (array $d): string => (string) $d['schema'], array_filter(Settings::DEFINITIONS, static fn (array $d): bool => $d['type'] === 'map')),
        'Strukturen und ihre Schemas'
    );

    $defaults = Settings::normalize([]);
    foreach ($expected as $key => $row) {
        $definition = Settings::DEFINITIONS[$key];
        T::same($row[0], $definition['type'], $key . ': Typ');
        T::check(preg_match('/^[a-z][a-z0-9_]*$/', $key) === 1, $key . ': snake_case übersteht SettingsService::sanitizeSegment()');
        if ($row[0] === 'map') {
            continue;
        }
        T::same($row[1], $definition['default'], $key . ': Standard');
        T::same($row[1], $defaults[$key], $key . ': normalize([]) liefert den Standard');
        if ($row[0] === 'int') {
            T::same([$row[2], $row[3]], [$definition['min'], $definition['max']], $key . ': Grenzen');
            T::check($row[1] >= $row[2] && $row[1] <= $row[3], $key . ': Standard liegt in den Grenzen');
        }
    }

    T::same([0, 1, 2, 3, 4], Settings::TRUST_LEVELS, 'Vertrauensstufen 0 bis 4');
    T::same(
        ['posts_per_day', 'topics_per_hour', 'links_per_post', 'images_per_post', 'attachments_per_post', 'attachments_per_day'],
        Settings::LIMIT_FIELDS,
        'Grenzen je Stufe: sechs Felder in Tabellenreihenfolge (Vertrag DESIGN-2b §3.1)'
    );
    T::same(-1, Settings::UNLIMITED, 'UNLIMITED = -1 (0 heißt „keine“)');
    T::same(
        [0 => [5, 1, 1, 0, 0, 0], 1 => [30, 3, 5, 10, 3, 20], 2 => [100, 5, -1, -1, 3, 50], 3 => [300, 10, -1, -1, 3, 100], 4 => [300, 10, -1, -1, 3, 100]],
        array_map(static fn (array $row): array => array_values($row), $defaults['trust_limits']),
        'trust_limits: Standards je Stufe (Beiträge/Tag, Themen/Stunde, Links, Bilder, Anhänge je Beitrag, Anhänge je Tag)'
    );
    T::same($defaults['trust_limits'], Settings::DEFAULT_TRUST_LIMITS, 'DEFAULT_TRUST_LIMITS ist der Standard von trust_limits');
    foreach (Settings::DEFAULT_TRUST_LIMITS as $level => $row) {
        T::same(Settings::LIMIT_FIELDS, array_keys($row), 'DEFAULT_TRUST_LIMITS Stufe ' . $level . ': alle sechs Felder in Reihenfolge');
        T::check($row['posts_per_day'] !== 0 && $row['topics_per_hour'] !== 0, 'DEFAULT_TRUST_LIMITS Stufe ' . $level . ': Beiträge/Tag und Themen/Stunde nie 0 (vor W1 deutet CommunityAccess 0 noch als unbegrenzt)');
    }
    T::same(['block' => [], 'review' => [], 'replace' => [], 'replacement' => '***'], Settings::DEFAULT_WORD_FILTER, 'DEFAULT_WORD_FILTER: drei leere Listen und Ersatz ***');
    T::same(Settings::DEFAULT_WORD_FILTER, $defaults['word_filter'], 'word_filter: Standard');
    T::same(500, Settings::WORD_FILTER_MAX, 'WORD_FILTER_MAX = 500 Einträge zusammen');
    T::same(['like', 'love', 'laugh', 'idea', 'thanks', 'wow', 'sad', 'celebrate'], Settings::REACTION_KEYS, 'REACTION_KEYS: acht Reaktionen');
    T::same(['like', 'love', 'laugh', 'idea'], Settings::DEFAULT_REACTION_SET, 'DEFAULT_REACTION_SET');
    T::same(Settings::DEFAULT_REACTION_SET, $defaults['reaction_set'], 'reaction_set: Standard');
    T::same(['jpg', 'png', 'webp', 'gif', 'pdf', 'zip', 'txt'], Settings::ATTACHMENT_TYPES, 'ATTACHMENT_TYPES: sieben Endungen');
    T::same(['jpg' => 1, 'png' => 1, 'webp' => 1, 'gif' => 1, 'pdf' => 1, 'zip' => 1, 'txt' => 0], Settings::DEFAULT_ATTACHMENT_TYPES, 'DEFAULT_ATTACHMENT_TYPES: alles außer txt');
    T::same(Settings::DEFAULT_ATTACHMENT_TYPES, $defaults['attachment_types'], 'attachment_types: Standard');
    // Seit J2 Pflicht (AttachmentService existiert): kein Rückfall auf einen Hinweis.
    T::same(Settings::ATTACHMENT_TYPES, array_keys(\CMS\Services\Community\Service\AttachmentService::TYPES), 'ATTACHMENT_TYPES = array_keys(AttachmentService::TYPES)');
    T::same(
        ['read', 'create_topic', 'reply', 'edit_own', 'delete_own', 'upload', 'poll', 'vote', 'react', 'mark_solution', 'bypass_approval', 'bypass_slow_mode'],
        Settings::PERMISSION_KEYS,
        'Rechtematrix: die zwölf Schlüssel in Planreihenfolge'
    );
    T::same(['guest', 'member'], array_keys($defaults['default_permissions']), 'default_permissions: Subjekte guest und member');
    $member = $defaults['default_permissions']['member'];
    T::same(
        ['read' => 1, 'create_topic' => 1, 'reply' => 1, 'edit_own' => 1, 'delete_own' => 0, 'upload' => 1, 'poll' => 0, 'vote' => 1, 'react' => 1, 'mark_solution' => 1, 'bypass_approval' => 0, 'bypass_slow_mode' => 0],
        $member,
        'Standard Mitglied: lesen, Thema, antworten, eigene bearbeiten, Anhänge, abstimmen, reagieren, Lösung markieren; nicht eigene löschen, Umfragen, Umgehungen'
    );
    T::same(array_merge(array_fill_keys(Settings::PERMISSION_KEYS, 0), ['read' => 1]), $defaults['default_permissions']['guest'], 'Standard Gast: nur lesen (hinter dem Tor guests_can_read)');
    T::check(!Settings::DEFINITIONS['guests_can_read']['default'], 'Gäste dürfen standardmäßig nicht lesen');
    T::check(Settings::DEFINITIONS['require_verified_email']['default'], 'E-Mail-Bestätigung ist standardmäßig Pflicht');
});

T::run('normalize(): Eingabe → Erwartung', static function (): void {
    // [Schlüssel, Rohwert, Erwartung, Beschreibung]
    $cases = [
        ['topics_per_page', 3, 5, 'int: unter dem Minimum wird auf das Minimum geklemmt'],
        ['topics_per_page', 500, 100, 'int: über dem Maximum wird auf das Maximum geklemmt'],
        ['topics_per_page', '50', 50, 'int: numerischer Text'],
        ['topics_per_page', ' 50 ', 50, 'int: Text mit Leerraum'],
        ['topics_per_page', '+30', 30, 'int: Vorzeichen +'],
        ['topics_per_page', '007', 7, 'int: führende Nullen'],
        ['topics_per_page', '-7', 5, 'int: negativ → Minimum'],
        ['topics_per_page', '', 20, 'int: Leerstring → Standard'],
        ['topics_per_page', null, 20, 'int: null → Standard'],
        ['topics_per_page', 'abc', 20, 'int: Text → Standard'],
        ['topics_per_page', '1e3', 20, 'int: Exponentenschreibweise → Standard'],
        ['topics_per_page', 12.0, 12, 'int: ganze Kommazahl'],
        ['topics_per_page', 12.5, 20, 'int: gebrochene Kommazahl → Standard'],
        ['topics_per_page', true, 20, 'int: bool → Standard'],
        ['topics_per_page', [], 20, 'int: Liste → Standard'],
        ['topics_per_page', '99999999999999999999', 100, 'int: riesiger Text → Maximum (kein Überlauf)'],
        ['topics_per_page', '-99999999999999999999', 5, 'int: riesiger negativer Text → Minimum'],
        ['topics_per_page', 1.0E+30, 100, 'int: riesige Kommazahl → Maximum'],
        ['topics_per_page', NAN, 20, 'int: NAN → Standard'],
        ['edit_window_minutes', 0, 0, 'edit_window_minutes: 0 (unbegrenzt) ist gültig'],
        ['edit_window_minutes', '0', 0, 'edit_window_minutes: Text 0 ist gültig, kein Standard'],
        ['edit_window_minutes', -1, 0, 'edit_window_minutes: negativ → 0'],
        ['edit_window_minutes', 20000, 10080, 'edit_window_minutes: Maximum eine Woche'],
        ['edit_window_minutes_trusted', 99999, 10080, 'edit_window_minutes_trusted: Maximum'],
        ['rate_post_seconds', 0, 0, 'rate_post_seconds: 0 = aus'],
        ['rate_topic_seconds', 100000, 86400, 'rate_topic_seconds: Maximum ein Tag'],
        ['new_member_approval_posts', 0, 0, 'new_member_approval_posts: 0 = aus'],
        ['new_member_approval_posts', 51, 50, 'new_member_approval_posts: Maximum 50'],
        ['max_pending_per_user', 0, 1, 'max_pending_per_user: Minimum 1'],
        ['max_pending_per_user', 99, 20, 'max_pending_per_user: Maximum 20'],
        ['trust_level1_posts', 0, 0, 'trust_level1_posts: 0 erlaubt'],
        ['trust_level2_posts', 0, 1, 'trust_level2_posts: Minimum 1'],
        ['title_max', 5, 10, 'title_max: Minimum 10'],
        ['title_max', 300, 200, 'title_max: Maximum 200 (Spaltenbreite)'],
        ['title_min', 0, 1, 'title_min: Minimum 1'],
        ['title_min', 500, 100, 'title_min: Maximum 100'],
        ['post_max', 50, 100, 'post_max: Minimum 100'],
        ['post_min', 5000, 1000, 'post_min: Maximum 1000'],
        ['duplicate_window_seconds', 5, 10, 'duplicate_window_seconds: Minimum 10'],
        ['unread_horizon_days', 365, 180, 'unread_horizon_days: höchstens 180'],
        ['unread_horizon_days', 0, 1, 'unread_horizon_days: Minimum 1'],
        ['spam_duplicate_hours', 0, 0, 'spam_duplicate_hours: 0 = aus'],
        ['spam_duplicate_hours', 169, 168, 'spam_duplicate_hours: höchstens 168 (eine Woche)'],
        ['ip_hash_days', 0, 0, 'ip_hash_days: 0 = kein ip_hash'],
        ['ip_hash_days', 91, 90, 'ip_hash_days: höchstens 90'],
        ['moved_stub_days', 0, 1, 'moved_stub_days: Minimum 1'],
        ['moved_stub_days', 366, 365, 'moved_stub_days: höchstens 365'],
        ['report_hide_threshold', 0, 0, 'report_hide_threshold: 0 = kein automatisches Ausblenden'],
        ['report_hide_threshold', 21, 20, 'report_hide_threshold: höchstens 20'],
        ['moderator_ban_max_days', 0, 1, 'moderator_ban_max_days: Minimum 1'],
        ['moderator_ban_max_days', 400, 365, 'moderator_ban_max_days: höchstens 365'],
        ['attachment_max_kb', 10, 64, 'attachment_max_kb: Minimum 64'],
        ['attachment_max_kb', 99999, 51200, 'attachment_max_kb: höchstens 51200'],
        ['captcha_mode', 'always', 'always', 'captcha_mode: always'],
        ['captcha_mode', ' TRUST0 ', 'trust0', 'captcha_mode: Groß-/Kleinschreibung und Leerraum egal'],
        ['captcha_mode', 'immer', 'first_post', 'captcha_mode: unbekannter Wert → Standard (erster Beitrag)'],
        ['captcha_mode', 1, 'first_post', 'captcha_mode: Zahl → Standard'],
        ['captcha_provider', 'image', 'image', 'captcha_provider: image'],
        ['captcha_provider', 'turnstile', 'pow', 'captcha_provider: Drittanbieter gibt es nicht → Standard (pow)'],
        ['captcha_provider', ['math'], 'pow', 'captcha_provider: Liste → Standard'],
        ['use_core_antispam', 'on', true, 'use_core_antispam: an'],
        ['use_core_antispam', 'maybe', false, 'use_core_antispam: unbekannter Text → Standard (aus)'],
        ['moderation_reason_required', 'off', false, 'moderation_reason_required: aus'],
        ['moderation_reason_required', '', true, 'moderation_reason_required: Leerstring → Standard (an)'],
        ['notify_mail', '0', false, 'notify_mail: aus'],
        ['notify_mail', null, true, 'notify_mail: null → Standard (an)'],
        ['guests_can_read', true, true, 'bool: true'],
        ['guests_can_read', 'yes', true, 'bool: yes'],
        ['guests_can_read', 'ON', true, 'bool: ON (Groß-/Kleinschreibung egal)'],
        ['guests_can_read', '1', true, 'bool: Text 1'],
        ['guests_can_read', 1, true, 'bool: Zahl 1'],
        ['guests_can_read', 'true', true, 'bool: Text true'],
        ['guests_can_read', 'off', false, 'bool: off'],
        ['guests_can_read', '', false, 'bool: Leerstring → Standard (aus)'],
        ['guests_can_read', 'maybe', false, 'bool: unbekannter Text → Standard (aus)'],
        ['guests_can_read', 2, false, 'bool: Zahl 2 → Standard (aus)'],
        ['guests_can_read', [], false, 'bool: Liste → Standard (aus)'],
        ['require_verified_email', false, false, 'bool: false schaltet die Pflicht ab'],
        ['require_verified_email', '0', false, 'bool: Text 0 schaltet ab'],
        ['require_verified_email', 'no', false, 'bool: no schaltet ab'],
        ['require_verified_email', '', true, 'bool: Leerstring → Standard (an)'],
        ['require_verified_email', 'maybe', true, 'bool: unbekannter Text → Standard (an)'],
        ['require_verified_email', null, true, 'bool: null → Standard (an)'],
        ['base_path', 'community', 'community', 'Slug: gültiger Pfad'],
        ['base_path', 'Community', 'community', 'Slug: Großbuchstaben werden kleingeschrieben'],
        ['base_path', '/hilfe/', 'hilfe', 'Slug: Schrägstriche am Rand entfallen'],
        ['base_path', ' hilfe-forum ', 'hilfe-forum', 'Slug: Leerraum entfällt'],
        ['base_path', str_repeat('a', 40), str_repeat('a', 40), 'Slug: 40 Zeichen sind erlaubt'],
        ['base_path', str_repeat('a', 41), 'forum', 'Slug: 41 Zeichen → Standard'],
        ['base_path', '', 'forum', 'Slug: Leerstring → Standard'],
        ['base_path', 'a/b', 'forum', 'Slug: mehrere Segmente → Standard'],
        ['base_path', '-forum', 'forum', 'Slug: Strich am Anfang → Standard'],
        ['base_path', 'forum_1', 'forum', 'Slug: Unterstrich → Standard'],
        ['base_path', 'für', 'forum', 'Slug: Umlaut → Standard'],
        ['base_path', 'admin', 'forum', 'Slug: admin ist gesperrt'],
        ['base_path', 'Member', 'forum', 'Slug: member ist gesperrt (auch groß geschrieben)'],
        ['base_path', 'api', 'forum', 'Slug: api ist gesperrt'],
        ['base_path', 'en', 'forum', 'Slug: en ist gesperrt'],
        ['base_path', 'assets', 'forum', 'Slug: assets ist gesperrt'],
        ['base_path', 'uploads', 'forum', 'Slug: uploads ist gesperrt'],
        ['base_path', 'themes', 'forum', 'Slug: themes ist gesperrt'],
        ['base_path', 'plugins', 'forum', 'Slug: plugins ist gesperrt'],
        ['base_path', 'cms-login', 'forum', 'Slug: cms-… ist gesperrt'],
        ['base_path', 'cms', 'cms', 'Slug: nur das Präfix cms- ist gesperrt'],
        ['base_path', 'json:x', 'forum', 'Text mit json:-Präfix wird nie übernommen'],
        ['base_path', 'JSON:{"a":1}', 'forum', 'Text mit JSON:-Präfix (groß) wird nie übernommen'],
        ['base_path', 'enc:v2:abc', 'forum', 'Text mit enc:-Präfix wird nie übernommen'],
        ['base_path', 5, 'forum', 'Slug: Zahl → Standard'],
        ['base_path', null, 'forum', 'Slug: null → Standard'],
    ];
    foreach ($cases as [$key, $raw, $expected, $label]) {
        T::same($expected, Settings::normalize([$key => $raw])[$key], $label);
    }

    $all = Settings::normalize(['unbekannt' => 1, 'topics_per_page' => 7]);
    T::check(!array_key_exists('unbekannt', $all), 'unbekannte Schlüssel entfallen');
    T::same(array_keys(Settings::DEFINITIONS), array_keys($all), 'das Ergebnis enthält immer alle Schlüssel in Definitionsreihenfolge');

    $pair = Settings::normalize(['title_min' => 100, 'title_max' => 10]);
    T::same([10, 10], [$pair['title_min'], $pair['title_max']], 'title_min über title_max wird auf title_max gesenkt');
    $pair = Settings::normalize(['post_min' => 1000, 'post_max' => 100]);
    T::same([100, 100], [$pair['post_min'], $pair['post_max']], 'post_min über post_max wird auf post_max gesenkt');
    $pair = Settings::normalize(['title_min' => 5, 'title_max' => 10]);
    T::same([5, 10], [$pair['title_min'], $pair['title_max']], 'gültiges Paar bleibt unverändert');
});

T::run('normalize(): JSON-Strukturen trust_limits und default_permissions', static function (): void {
    $defaults = Settings::normalize([]);
    $limits = static fn (mixed $raw): array => Settings::normalize(['trust_limits' => $raw])['trust_limits'];
    $permissions = static fn (mixed $raw): array => Settings::normalize(['default_permissions' => $raw])['default_permissions'];

    T::same($defaults['trust_limits'], $limits(null), 'trust_limits: null → Standards');
    T::same($defaults['trust_limits'], $limits('kein JSON'), 'trust_limits: Text → Standards');
    T::same($defaults['trust_limits'], $limits('{oops'), 'trust_limits: ungültiges JSON → Standards');
    T::same($defaults['trust_limits'], $limits(5), 'trust_limits: Zahl → Standards');
    T::same($defaults['trust_limits'], $limits([]), 'trust_limits: leere Struktur → Standards');

    $partial = $limits([2 => ['posts_per_day' => 7], 9 => ['posts_per_day' => 1, 'topics_per_hour' => 1]]);
    T::same(['posts_per_day' => 7, 'topics_per_hour' => 5, 'links_per_post' => -1, 'images_per_post' => -1, 'attachments_per_post' => 3, 'attachments_per_day' => 50], $partial[2], 'trust_limits: fehlende Felder werden aus dem Standard aufgefüllt');
    T::same($defaults['trust_limits'][3], $partial[3], 'trust_limits: nicht genannte Stufe bleibt Standard');
    T::same([0, 1, 2, 3, 4], array_keys($partial), 'trust_limits: unbekannte Stufe 9 entfällt, Stufen 0 bis 4 in Reihenfolge');

    $two = static fn (array $row): array => [$row['posts_per_day'], $row['topics_per_hour']];
    $clamped = $limits(['1' => ['posts_per_day' => '-5', 'topics_per_hour' => 'abc', 'links' => 4], 3 => ['posts_per_day' => 100001, 'topics_per_hour' => 0]]);
    T::same([30, 3], $two($clamped[1]), 'trust_limits: unter -1 → Standard des Feldes (fail-safe), Text → Standard');
    T::check(!array_key_exists('links', $clamped[1]), 'trust_limits: unbekanntes Feld entfällt');
    T::same([100000, 0], $two($clamped[3]), 'trust_limits: Maximum 100000, 0 = keine bleibt 0');
    T::same([5, 1], $two($limits([0 => 5])[0]), 'trust_limits: Zeile kein Array → Standard');

    // -1 = unbegrenzt, 0 = keine (Vertrag DESIGN-2b §3.1); alle sechs Felder
    $signed = $limits([2 => ['posts_per_day' => -1, 'topics_per_hour' => '-1', 'links_per_post' => 0, 'images_per_post' => -2, 'attachments_per_post' => '-99999999999999999999', 'attachments_per_day' => -1.0]]);
    T::same(
        ['posts_per_day' => -1, 'topics_per_hour' => -1, 'links_per_post' => 0, 'images_per_post' => -1, 'attachments_per_post' => 3, 'attachments_per_day' => -1],
        $signed[2],
        'trust_limits: -1 bleibt (unbegrenzt, auch als Text und Kommazahl), 0 bleibt (keine), -2 und riesige negative Werte → Standard des Feldes'
    );
    T::same(-1, $limits([0 => ['links_per_post' => -1]])[0]['links_per_post'], 'trust_limits: -1 auch dort, wo der Standard eine Zahl ist');
    T::same(1, $limits([0 => ['links_per_post' => -7]])[0]['links_per_post'], 'trust_limits: -7 → Standard des Feldes (1), nicht unbegrenzt');
    T::same(100000, $limits([4 => ['attachments_per_day' => '99999999999999999999']])[4]['attachments_per_day'], 'trust_limits: riesiger Text → 100000');
    foreach ($limits(null) as $level => $row) {
        T::same(Settings::LIMIT_FIELDS, array_keys($row), 'trust_limits Stufe ' . $level . ': sechs Felder in Reihenfolge');
    }

    $fromJson = $limits('{"3":{"posts_per_day":12}}');
    T::same([12, 10], $two($fromJson[3]), 'trust_limits: JSON-Text wird gelesen');
    $fromPrefixed = $limits('json:{"3":{"topics_per_hour":2}}');
    T::same([300, 2], $two($fromPrefixed[3]), 'trust_limits: JSON-Text mit json:-Präfix wird gelesen');
    $fromList = $limits([['posts_per_day' => 1, 'topics_per_hour' => 2]]);
    T::same([1, 2], $two($fromList[0]), 'trust_limits: als JSON-Liste gespeicherte Stufen (Index 0 bis 4)');

    T::same($defaults['default_permissions'], $permissions(null), 'default_permissions: null → Standards');
    T::same($defaults['default_permissions'], $permissions('{oops'), 'default_permissions: ungültiges JSON → Standards');
    T::same($defaults['default_permissions'], $permissions('x'), 'default_permissions: Text → Standards');

    $changed = $permissions([
        'guest' => ['read' => 0, 'reply' => '1', 'vote' => 2, 'poll' => 'x', 'react' => null, 'upload' => []],
        'member' => ['delete_own' => true, 'poll' => 'true', 'vote' => '0', 'evil' => 1],
        'role:editor' => ['read' => 1],
    ]);
    T::same(['guest', 'member'], array_keys($changed), 'default_permissions: fremde Subjekte entfallen');
    T::same(Settings::PERMISSION_KEYS, array_keys($changed['member']), 'default_permissions: fremder Schlüssel entfällt, Reihenfolge der zwölf Schlüssel');
    T::same(
        ['read' => 0, 'reply' => 1, 'upload' => 0, 'poll' => 0, 'vote' => 0, 'react' => 0],
        array_intersect_key($changed['guest'], array_flip(['read', 'reply', 'vote', 'poll', 'react', 'upload'])),
        'default_permissions: 0/1/Text akzeptiert, ungültige Werte (2, Text, null, Liste) → Standard des Schlüssels'
    );
    T::same(
        ['delete_own' => 1, 'poll' => 1, 'vote' => 0],
        array_intersect_key($changed['member'], array_flip(['delete_own', 'poll', 'vote'])),
        'default_permissions: bool, true-Text und 0-Text'
    );
    T::same($defaults['default_permissions']['member']['reply'], $changed['member']['reply'], 'default_permissions: nicht genannter Schlüssel bleibt Standard');
    $fromJson = $permissions('{"guest":{"create_topic":1}}');
    T::same(1, $fromJson['guest']['create_topic'], 'default_permissions: JSON-Text wird gelesen');
    T::same($defaults['default_permissions']['member'], $fromJson['member'], 'default_permissions: fehlendes Subjekt → Standards');

    // Fuzz: normalize() ist idempotent und liefert nur gültige Typen und Grenzen.
    $inputs = [null, true, false, 0, 1, -1, 7, 99999999999, 1.5, 12.0, '', ' ', 'abc', '12', ' 12 ', '-3', 'Forum', 'cms-x', 'json:{"0":{"posts_per_day":3}}',
        '{"guest":{"read":0}}', [], [1, 2], ['0' => ['posts_per_day' => '8']], ['guest' => ['read' => '0']], ['member' => 5], 'ä', "\0"];
    $violations = [];
    foreach (Settings::DEFINITIONS as $key => $definition) {
        foreach ($inputs as $index => $input) {
            $once = Settings::normalize([$key => $input]);
            if (Settings::normalize($once) !== $once) {
                $violations[] = $key . '#' . $index . ': nicht idempotent';
            }
            $value = $once[$key];
            $valid = match ($definition['type']) {
                'int' => is_int($value) && $value >= $definition['min'] && $value <= $definition['max'],
                'bool' => is_bool($value),
                'slug' => is_string($value) && preg_match(Settings::BASE_PATH_PATTERN, $value) === 1,
                'enum' => is_string($value) && in_array($value, $definition['values'], true),
                default => is_array($value),
            };
            if (!$valid) {
                $violations[] = $key . '#' . $index . ': ungültiger Wert ' . T::export($value);
            }
        }
    }
    T::same([], $violations, 'Fuzz: jeder Schlüssel × 27 Eingaben ergibt gültige, idempotente Werte');
});

T::run('normalize(): Schemas word_filter, reaction_set und attachment_types (PR #2b)', static function (): void {
    $filter = static fn (mixed $raw): array => Settings::normalize(['word_filter' => $raw])['word_filter'];
    $default = Settings::DEFAULT_WORD_FILTER;
    foreach ([null, 'kein JSON', '{oops', 5, true, [], '[]'] as $raw) {
        T::same($default, $filter($raw), 'word_filter: ' . T::export($raw) . ' → Standard');
    }
    T::same(['block', 'review', 'replace', 'replacement'], array_keys($filter(['evil' => ['x'], 'replacement' => '#', 'block' => ['ab']])), 'word_filter: Schlüssel block, review, replace, replacement in dieser Reihenfolge, fremde entfallen');
    T::same(['spam', 'viel werbung', 'ärger'], $filter(['block' => ['Spam', 'spam', "  viel \t  WERBUNG ", 'ÄRGER']])['block'], 'word_filter: klein (mb_strtolower), Leerraum zu einem Leerzeichen, doppelt einmal');
    T::same(['ab', str_repeat('a', 100)], $filter(['block' => ['a', 'ab', str_repeat('a', 100), str_repeat('a', 101)]])['block'], 'word_filter: Einträge 2 bis 100 Zeichen');
    T::same(['ü1', 'öö'], $filter(['block' => ['ü1', 'öö', '**', '!!', '- -', '…']])['block'], 'word_filter: mindestens ein Buchstabe oder eine Ziffer (Zeichen, nicht Bytes)');
    T::same(['spam*', 'a*'], $filter(['block' => ['spam*', 'sp*m', '*spam', 'spam**', 'a*']])['block'], 'word_filter: * nur einmal und nur am Ende');
    T::same(['ok1'], $filter(['block' => ["spa\x00m", "sp\x7Fam", "\xFFxy", 5, null, ['x'], 'ok1']])['block'], 'word_filter: Steuerzeichen, ungültiges UTF-8 und Nicht-Texte entfallen');
    T::same([], $filter(['block' => 'spam'])['block'], 'word_filter: Liste als Text → leer');
    $numeric = $filter(['block' => ['12', '007'], 'review' => ['12', '42*'], 'replace' => ['99']]);
    T::same([['12', '007'], ['42*'], ['99']], [$numeric['block'], $numeric['review'], $numeric['replace']], 'word_filter: Einträge nur aus Ziffern bleiben Texte (kein Umnummerieren), Vorrang gilt auch für sie');
    if (class_exists(\Normalizer::class)) {
        T::same(["caf\u{00E9}"], $filter(['block' => ["cafe\u{0301}"]])['block'], 'word_filter: NFC (e + Akut → é)');
    } else {
        T::info('intl fehlt: NFC-Fall übersprungen');
    }
    $precedence = $filter(['block' => ['x1'], 'review' => ['X1', 'y1'], 'replace' => ['y1', 'z1', 'x1']]);
    T::same([['x1'], ['y1'], ['z1']], [$precedence['block'], $precedence['review'], $precedence['replace']], 'word_filter: doppelt über Listen → Vorrang block > review > replace');
    $many = static fn (string $p, int $n): array => array_map(static fn (int $i): string => $p . $i, range(1, $n));
    $capped = $filter(['block' => $many('b', 300), 'review' => $many('r', 300), 'replace' => $many('e', 50)]);
    T::same([300, 200, 0], [count($capped['block']), count($capped['review']), count($capped['replace'])], 'word_filter: zusammen höchstens 500 Einträge, in Vorrangreihenfolge');
    T::same(['r1', 'r200'], [$capped['review'][0], $capped['review'][199]], 'word_filter: gekürzt wird am Ende (Reihenfolge erhalten)');
    T::same(['spam'], $filter('{"block":["Spam"],"review":[],"replace":[],"replacement":"###"}')['block'], 'word_filter: JSON-Text wird gelesen');
    T::same(['spam'], $filter('json:{"review":["spam"]}')['review'], 'word_filter: JSON-Text mit json:-Präfix');
    foreach (['###' => '###', '•••' => '•••', str_repeat('#', 20) => str_repeat('#', 20), '' => '***', str_repeat('#', 21) => '***', 'xx' => '***', '#1' => '***', '[zensiert]' => '***', "**\x01" => '***', "\xFF" => '***'] as $raw => $expected) {
        T::same($expected, $filter(['replacement' => (string) $raw])['replacement'], 'word_filter: Ersatz ' . T::export((string) $raw) . ' → ' . T::export($expected));
    }
    T::same('***', $filter(['replacement' => 5])['replacement'], 'word_filter: Ersatz kein Text → ***');
    $once = $filter(['block' => ['Spam', 'Ärger*'], 'review' => ['x  y'], 'replacement' => '#']);
    T::same($once, $filter($once), 'word_filter: idempotent');

    $reactions = static fn (mixed $raw): array => Settings::normalize(['reaction_set' => $raw])['reaction_set'];
    foreach ([null, 'like', 5, true, '[oops', '{"a":"like"}', ['a' => 'like']] as $raw) {
        T::same(Settings::DEFAULT_REACTION_SET, $reactions($raw), 'reaction_set: ' . T::export($raw) . ' → Standard');
    }
    T::same([], $reactions([]), 'reaction_set: [] = Reaktionen aus');
    T::same([], $reactions('[]'), 'reaction_set: [] als JSON-Text = aus');
    T::same(['love', 'like'], $reactions(['love', 'like', 'love']), 'reaction_set: eindeutig, Reihenfolge erhalten');
    T::same(['like', 'wow'], $reactions(['like', 'bogus', 5, null, 'LIKE', 'wow']), 'reaction_set: nur Texte aus REACTION_KEYS');
    T::same(['thanks', 'wow'], $reactions('["thanks","wow"]'), 'reaction_set: JSON-Liste wird gelesen');
    T::same(['sad'], $reactions('json:["sad"]'), 'reaction_set: JSON-Liste mit json:-Präfix');
    T::same(Settings::REACTION_KEYS, $reactions(array_reverse(array_reverse(Settings::REACTION_KEYS))), 'reaction_set: alle acht erlaubt');

    $types = static fn (mixed $raw): array => Settings::normalize(['attachment_types' => $raw])['attachment_types'];
    foreach ([null, 'x', 5, '{oops', []] as $raw) {
        T::same(Settings::DEFAULT_ATTACHMENT_TYPES, $types($raw), 'attachment_types: ' . T::export($raw) . ' → Standard');
    }
    $changed = $types(['txt' => 1, 'zip' => '0', 'exe' => 1, 'svg' => 1, 'jpg' => 'x', 'pdf' => false]);
    T::same(['jpg' => 1, 'png' => 1, 'webp' => 1, 'gif' => 1, 'pdf' => 0, 'zip' => 0, 'txt' => 1], $changed, 'attachment_types: nur Schlüssel aus ATTACHMENT_TYPES (exe, svg entfallen), Werte 0/1, ungültig → Standard, Reihenfolge der Liste');
    T::same(0, $types('{"png":0}')['png'], 'attachment_types: JSON-Text wird gelesen');
});

T::run('Lesen und Speichern über Closures', static function (): void {
    $reads = 0;
    $settings = new Settings(static function () use (&$reads): array {
        $reads++;

        return ['topics_per_page' => '12', 'guests_can_read' => 'yes', 'base_path' => 'hilfe'];
    });
    T::same(12, $settings->int('topics_per_page'), 'int(): Rohwert wird normalisiert gelesen');
    T::same(true, $settings->bool('guests_can_read'), 'bool()');
    T::same('hilfe', $settings->string('base_path'), 'string()');
    T::same(15, $settings->get('posts_per_page'), 'nicht gespeicherter Schlüssel → Standard');
    T::same(['guest', 'member'], array_keys($settings->map('default_permissions')), 'map()');
    T::same(1, $reads, 'die Rohwerte werden einmal je Instanz gelesen (einmal je Request)');
    $settings->invalidate();
    $settings->int('topics_per_page');
    T::same(2, $reads, 'invalidate() liest neu');

    foreach ([
        'get unbekannt' => static fn () => $settings->get('gibt_es_nicht'),
        'int auf bool' => static fn () => $settings->int('guests_can_read'),
        'bool auf int' => static fn () => $settings->bool('topics_per_page'),
        'string auf int' => static fn () => $settings->string('topics_per_page'),
        'map auf int' => static fn () => $settings->map('topics_per_page'),
    ] as $label => $call) {
        $thrown = false;
        try {
            $call();
        } catch (\InvalidArgumentException) {
            $thrown = true;
        }
        T::check($thrown, 'InvalidArgumentException: ' . $label);
    }

    $broken = new Settings(static function (): array {
        throw new \RuntimeException('Datenbank weg');
    });
    T::same(20, $broken->int('topics_per_page'), 'Leser wirft: Standards gelten, keine Ausnahme');
    T::same(false, $broken->bool('guests_can_read'), 'Leser wirft: Gäste dürfen nicht lesen');
    T::same(true, $broken->bool('require_verified_email'), 'Leser wirft: E-Mail-Bestätigung bleibt Pflicht');

    // Speichern: nur Abweichungen vom wirksamen Wert, nie ein Standard.
    $store = [];
    $writes = [];
    $make = static function () use (&$store, &$writes): Settings {
        return new Settings(
            static function () use (&$store): array {
                return $store;
            },
            static function (array $values) use (&$store, &$writes): bool {
                $writes[] = $values;
                $store = array_merge($store, $values);

                return true;
            }
        );
    };
    $saver = $make();
    T::same(true, $saver->save(['topics_per_page' => 20]), 'save() mit dem Standardwert ist erfolgreich');
    T::same([], $writes, 'ein Standardwert wird nicht geschrieben');
    T::same(true, $saver->save([]), 'save() ohne Werte ist erfolgreich');
    T::same([], $writes, 'ohne Werte wird nichts geschrieben');
    $saver->save(['topics_per_page' => 10, 'unbekannt' => 5, 'posts_per_page' => 15, 'guests_can_read' => false]);
    T::same([['topics_per_page' => 10]], $writes, 'geschrieben wird nur die Abweichung; unbekannte Schlüssel und Standards entfallen');
    T::same(10, $saver->int('topics_per_page'), 'nach save() liest die Instanz den neuen Wert (Cache verworfen)');
    $saver->save(['topics_per_page' => 10]);
    T::same(1, count($writes), 'unveränderter Wert wird nicht erneut geschrieben');
    $saver->save(['topics_per_page' => 20]);
    T::same(['topics_per_page' => 20], $writes[1], 'ein früher gespeicherter Wert lässt sich auf den Standard zurücksetzen');
    $saver->save(['topics_per_page' => 'abc']);
    T::same(2, count($writes), 'ungültiger Wert bei Standard-Stand: nichts zu schreiben');
    $saver->save(['topics_per_page' => 8]);
    $saver->save(['topics_per_page' => 'abc']);
    T::same(['topics_per_page' => 20], end($writes), 'ungültiger Wert ergibt den Standard (wie beim Lesen)');
    $saver->save(['topics_per_page' => 1000, 'guests_can_read' => 'on', 'base_path' => 'Hilfe']);
    T::same(['topics_per_page' => 100, 'guests_can_read' => true, 'base_path' => 'hilfe'], end($writes), 'gespeichert wird der normalisierte Wert (geklemmt, typisiert)');
    $saver->save(['trust_limits' => [2 => ['posts_per_day' => 7]]]);
    $stored = end($writes)['trust_limits'];
    T::same(['posts_per_day' => 7, 'topics_per_hour' => 5, 'links_per_post' => -1, 'images_per_post' => -1, 'attachments_per_post' => 3, 'attachments_per_day' => 50], $stored[2], 'Strukturen werden vollständig aufgefüllt gespeichert (sechs Felder)');
    T::same(5, count($stored), 'trust_limits: alle fünf Stufen');
    $pairSaver = $make();
    $pairSaver->save(['title_min' => 100, 'title_max' => 10]);
    T::same(['title_min' => 10, 'title_max' => 10], end($writes), 'Paar-Regel gilt auch beim Speichern');

    $failing = new Settings(static fn (): array => [], static fn (array $values): bool => false);
    T::same(false, $failing->save(['topics_per_page' => 9]), 'Schreiber meldet false → save() false');
    $throwing = new Settings(static fn (): array => [], static function (array $values): bool {
        throw new \RuntimeException('Schreibfehler');
    });
    T::same(false, $throwing->save(['topics_per_page' => 9]), 'Schreiber wirft → save() false, keine Ausnahme');
    $untyped = new Settings(static fn (): array => [], static fn (array $values): bool => (bool) count($values));
    T::same(true, $untyped->save(['topics_per_page' => 9]), 'Rückgabe true des Schreibers → true');

    $fixed = Settings::fromArray(['posts_per_page' => 40]);
    T::same(40, $fixed->int('posts_per_page'), 'fromArray(): feste Rohwerte');
    Settings::setInstance($fixed);
    T::same(true, Settings::getInstance() === $fixed, 'getInstance() liefert die gesetzte Instanz');
    Settings::setInstance(null);
    T::check(Settings::getInstance() !== $fixed, 'setInstance(null) baut eine neue gemeinsame Instanz');
    Settings::setInstance(null);

    $source = csRead(CS_ROOT . 'Support/Settings.php');
    T::check(str_contains($source, 'getGroup(self::GROUP)') && str_contains($source, 'setMany(self::GROUP'), 'Standard-Leser: eine Abfrage über SettingsService::getGroup(), Schreiber über setMany()');
    T::check(!str_contains($source, 'autoload'), 'Settings setzt kein Autoload (Standard 0, eine Gruppenabfrage je Request)');
});

T::run('limitsForLevel() und permissionDefaults()', static function (): void {
    $settings = Settings::fromArray(['trust_limits' => [1 => ['posts_per_day' => 11, 'topics_per_hour' => 2, 'images_per_post' => -1], 4 => ['posts_per_day' => 0, 'links_per_post' => 0]]]);
    T::same(['posts_per_day' => 5, 'topics_per_hour' => 1, 'links_per_post' => 1, 'images_per_post' => 0, 'attachments_per_post' => 0, 'attachments_per_day' => 0], $settings->limitsForLevel(0), 'limitsForLevel(0): Standard, alle sechs Felder');
    T::same(['posts_per_day' => 11, 'topics_per_hour' => 2, 'links_per_post' => 5, 'images_per_post' => -1, 'attachments_per_post' => 3, 'attachments_per_day' => 20], $settings->limitsForLevel(1), 'limitsForLevel(1): gespeicherte Werte, -1 = unbegrenzt');
    T::same(['posts_per_day' => 0, 'topics_per_hour' => 10, 'links_per_post' => 0, 'images_per_post' => -1, 'attachments_per_post' => 3, 'attachments_per_day' => 100], $settings->limitsForLevel(4), 'limitsForLevel(4): 0 = keine, Rest Standard');
    T::same(Settings::LIMIT_FIELDS, array_keys($settings->limitsForLevel(2)), 'limitsForLevel(): genau die Felder aus LIMIT_FIELDS in Reihenfolge');
    T::same(true, array_reduce($settings->limitsForLevel(3), static fn (bool $all, mixed $v): bool => $all && is_int($v), true), 'limitsForLevel(): nur ganze Zahlen');
    T::same($settings->limitsForLevel(0), $settings->limitsForLevel(-3), 'limitsForLevel(-3) wird an Stufe 0 gelegt');
    T::same($settings->limitsForLevel(4), $settings->limitsForLevel(99), 'limitsForLevel(99) wird an Stufe 4 gelegt');

    $closed = Settings::fromArray([]);
    $guest = $closed->permissionDefaults('guest');
    T::same(Settings::PERMISSION_KEYS, array_keys($guest), 'permissionDefaults(guest): zwölf Schlüssel');
    T::same([], array_filter($guest), 'Gast ohne offenes Tor: nichts gewährt (auch nicht read)');
    $open = Settings::fromArray(['guests_can_read' => true]);
    T::same(['read'], array_keys(array_filter($open->permissionDefaults('guest'))), 'Gast bei offenem Tor: nur read');
    T::same(true, $open->permissionDefaults('guest')['read'], 'Werte sind bool');
    $closedByMatrix = Settings::fromArray(['guests_can_read' => true, 'default_permissions' => ['guest' => ['read' => 0]]]);
    T::same(false, $closedByMatrix->permissionDefaults('guest')['read'], 'offenes Tor, aber guest.read = 0 in der Matrix → nicht gewährt');
    $member = $closed->permissionDefaults('member');
    T::same(true, $member['reply'] && $member['create_topic'] && $member['read'], 'Mitglied: lesen, Thema, antworten');
    T::same(false, $member['delete_own'] || $member['bypass_approval'] || $member['bypass_slow_mode'] || $member['poll'], 'Mitglied: nicht eigene löschen, keine Umgehungen, keine Umfragen');
    T::same(true, array_reduce($member, static fn (bool $all, mixed $flag): bool => $all && is_bool($flag), true), 'Mitglied: nur bool-Werte');
    $unknown = $closed->permissionDefaults('role:editor');
    T::same([array_fill_keys(Settings::PERMISSION_KEYS, false), 12], [$unknown, count($unknown)], 'unbekanntes Subjekt: alles verweigert');
});

T::run('Produktiver Weg über SettingsService (Attrappe der Tabelle settings)', static function (): void {
    $rows = [
        'community.topics_per_page' => '9999',
        'community.posts_per_page' => 'json:25',
        'community.guests_can_read' => 'json:true',
        'community.require_verified_email' => 'json:false',
        'community.trust_limits' => 'json:{"2":{"posts_per_day":77}}',
        'community.base_path' => 'hilfe',
        'community.rate_post_seconds' => '',
        'downloads.limit' => 'json:5',
    ];
    $autoload = [];
    $db = csSettingsDatabase($rows, $autoload);

    $class = new ReflectionClass(SettingsService::class);
    $instanceProperty = $class->getProperty('instance');
    $previous = $instanceProperty->getValue();
    $service = $class->newInstanceWithoutConstructor();
    $class->getProperty('db')->setValue($service, $db);
    $class->getProperty('prefix')->setValue($service, 'cms_');
    $class->getProperty('logger')->setValue($service, \CMS\Logger::instance()->withChannel('settings'));

    try {
        $instanceProperty->setValue(null, $service);
        Settings::setInstance(null);
        $settings = Settings::getInstance();
        T::same(100, $settings->int('topics_per_page'), 'verschmutzter Rohwert 9999 wird auf 100 geklemmt');
        T::same(25, $settings->int('posts_per_page'), 'json:25 wird zu int');
        T::same(true, $settings->bool('guests_can_read'), 'json:true wird zu bool');
        T::same(false, $settings->bool('require_verified_email'), 'json:false wird zu bool');
        T::same(77, $settings->limitsForLevel(2)['posts_per_day'], 'json-Struktur wird gelesen');
        T::same('hilfe', $settings->string('base_path'), 'Text wird roh gelesen');
        T::same(30, $settings->int('rate_post_seconds'), 'gespeicherter Leerstring bei Zahlen → Standard');
        T::same(120, $settings->int('rate_topic_seconds'), 'nicht gespeicherter Schlüssel → Standard');
        T::check(!array_key_exists('limit', $settings->all()), 'fremde Gruppe (downloads.*) wird nicht gelesen');

        $before = $rows;
        T::same(true, $settings->save(['topics_per_page' => 100, 'posts_per_page' => 25, 'rate_topic_seconds' => 120]), 'save() ohne Abweichung');
        T::same($before, $rows, 'ohne Abweichung wird nichts in die Tabelle geschrieben (auch kein Standard)');
        T::same(true, $settings->save(['topics_per_page' => 10, 'guests_can_read' => false, 'trust_limits' => [3 => ['posts_per_day' => 1]]]), 'save() mit Änderungen');
        T::same('json:10', $rows['community.topics_per_page'], 'Zahl wird als json:10 gespeichert');
        T::same('json:false', $rows['community.guests_can_read'], 'bool wird als json:false gespeichert');
        T::check(str_starts_with($rows['community.trust_limits'], 'json:{') || str_starts_with($rows['community.trust_limits'], 'json:['), 'Struktur wird als json: gespeichert');
        T::same(['community.topics_per_page' => 0, 'community.guests_can_read' => 0, 'community.trust_limits' => 0], $autoload, 'autoload = 0 (kein Dauerballast für jeden Request)');
        T::same(10, $settings->int('topics_per_page'), 'nach dem Speichern liest die Instanz den neuen Wert');
        T::same(1, $settings->limitsForLevel(3)['posts_per_day'], 'Struktur wird nach dem Speichern gelesen');
        T::same(10, (new Settings())->int('topics_per_page'), 'eine neue Instanz liest denselben Wert über SettingsService');
        T::same(
            ['json:5', '', 'json:25', 'hilfe'],
            [$rows['downloads.limit'], $rows['community.rate_post_seconds'], $rows['community.posts_per_page'], $rows['community.base_path']],
            'fremde Gruppe und unberührte Zeilen bleiben unverändert'
        );
        T::same(['community.topics_per_page', 'community.guests_can_read', 'community.trust_limits'], array_keys(array_diff_assoc($rows, $before)), 'geschrieben wurden genau die drei geänderten Schlüssel');
    } finally {
        $instanceProperty->setValue(null, $previous);
        Settings::setInstance(null);
    }
});

T::run('Wirkung: base_path', static function (): void {
    Settings::setInstance(Settings::fromArray([]));
    T::same('/forum', Urls::index('de'), 'Standardpfad /forum');
    T::same('/en/forum', Urls::index('en'), 'Standardpfad /en/forum');
    Settings::setInstance(Settings::fromArray(['base_path' => 'community']));
    T::same('/community', Urls::index('de'), 'base_path ändert die Startseite (de)');
    T::same('/en/community', Urls::index('en'), 'base_path ändert die Startseite (en)');
    T::same('/community/thema/5-x', Urls::topic(5, 'x', 'de'), 'base_path ändert Themenadressen');
    T::same('/en/community/topic/5-x', Urls::topic(5, 'x', 'en'), 'base_path ändert englische Themenadressen');
    T::same('/community/hilfe', Urls::forum('hilfe', 'de'), 'base_path ändert Forenadressen');
    T::same('/community/beitrag/9', Urls::post(9), 'base_path ändert Beitragslinks');
    T::same('/community/anhang/4', Urls::attachment(4), 'base_path ändert Anhangsadressen (Leser über Urls::attachment())');
    T::same('/en/community/attachment/4/preview', Urls::attachment(4, true, 'en'), 'base_path ändert englische Vorschauadressen');
    T::same('/community/thema/5/ungelesen', Urls::topicUnread(5), 'base_path ändert „erster ungelesener Beitrag“');
    T::same('/cms-community-unsubscribe/1.a.x', Urls::unsubscribe('1.a.x'), 'Abmeldelink liegt außerhalb von base_path');
    Settings::setInstance(Settings::fromArray(['base_path' => 'admin']));
    T::same('/forum', Urls::index('de'), 'gesperrter base_path fällt auf den Standard zurück');
    Settings::setInstance(null);
});

T::run('Slugger', static function (): void {
    $table = [
        ['Über uns', 'ueber-uns'], ['Größe & Maße!', 'groesse-masse'], ['Straße', 'strasse'], ['ÄÖÜ äöü', 'aeoeue-aeoeue'],
        ['Crème brûlée', 'creme-brulee'], ['  --Hallo   Welt--  ', 'hallo-welt'], ['Łódź Ñandú', 'lodz-nandu'], ['C++ & C#', 'c-c'],
        ['Søren Ørsted', 'soren-orsted'], ['Æble & Œuvre', 'aeble-oeuvre'], ['Zażółć gęślą jaźń', 'zazolc-gesla-jazn'], ['Đorđe Þórr', 'dorde-thorr'],
        ['Version 2.0', 'version-2-0'], ['Привет', ''], ['🙂🙂', ''], ['', ''], ['---', ''], ['ABC', 'abc'],
    ];
    foreach ($table as [$text, $expected]) {
        T::same($expected, Slugger::slugify($text), 'slugify(' . T::export($text) . ')');
    }
    T::same(100, strlen(Slugger::slugify(str_repeat('a', 300))), 'slugify(): Standardlänge 100');
    T::same('abc', Slugger::slugify('abc def', 4), 'slugify(): kein Trennstrich am Ende nach dem Kürzen');
    T::same('abc-d', Slugger::slugify('abc def', 5), 'slugify(): Kürzen auf $max');
    T::same('', Slugger::slugify('abc', 0), 'slugify(): $max = 0');

    T::same('thema', Slugger::topicSlug(''), 'topicSlug(): leerer Titel → thema');
    T::same('thema', Slugger::topicSlug('???'), 'topicSlug(): nur Sonderzeichen → thema');
    T::same('thema', Slugger::topicSlug('Привет'), 'topicSlug(): nur Fremdschrift → thema');
    T::same('wie-geht-das', Slugger::topicSlug('Wie geht das?'), 'topicSlug(): normaler Titel');
    T::check(strlen(Slugger::topicSlug(str_repeat('wort ', 100))) <= 100, 'topicSlug(): höchstens 100 Zeichen (Spalte varchar 210)');

    $taken = [];
    $exists = static function (string $slug, ?int $ignoreId) use (&$taken): bool {
        return isset($taken[$slug]) && $taken[$slug] !== $ignoreId;
    };
    T::same('hilfe', Slugger::forumSlug('Hilfe', $exists), 'forumSlug(): freier Slug bleibt');
    $taken = ['hilfe' => 1];
    T::same('hilfe-2', Slugger::forumSlug('Hilfe', $exists), 'forumSlug(): belegt → -2');
    $taken = ['hilfe' => 1, 'hilfe-2' => 2];
    T::same('hilfe-3', Slugger::forumSlug('Hilfe', $exists), 'forumSlug(): belegt → -3');
    T::same('hilfe', Slugger::forumSlug('Hilfe', $exists, 1), 'forumSlug(): das Forum selbst (ignoreId) zählt nicht als Kollision');
    T::same('hilfe-3', Slugger::forumSlug('Hilfe', $exists, 7), 'forumSlug(): fremde ignoreId ändert nichts');
    $taken = [];
    foreach (['Neu' => 'neu-2', 'Suche' => 'suche-2', 'Topic' => 'topic-2', 'API' => 'api-2', 'Mitglieder' => 'mitglieder-2', 'Seite 2' => 'seite-2-2', 'Page 7' => 'page-7-2', 'EN' => 'en-2'] as $title => $expected) {
        T::same($expected, Slugger::forumSlug($title, $exists), 'forumSlug(): reserviert „' . $title . '“ → ' . $expected);
    }
    T::same('forum', Slugger::forumSlug('Привет', $exists), 'forumSlug(): leerer Titel → forum');
    $thrown = false;
    try {
        Slugger::forumSlug('Hilfe', static fn (string $slug, ?int $ignoreId): bool => true);
    } catch (\RuntimeException) {
        $thrown = true;
    }
    T::check($thrown, 'forumSlug(): endlose Belegung wirft RuntimeException statt zu hängen');

    foreach (['neu', 'aktiv', 'unbeantwortet', 'suche', 'feed', 'thema', 'beitrag', 'anhang', 'api', 'mitglieder', 'new', 'active', 'unanswered', 'search', 'topic', 'post', 'attachment', 'members', 'en', 'seite-1', 'seite-250', 'page-2'] as $reserved) {
        T::check(Slugger::isReserved($reserved), 'reserviert: ' . $reserved);
    }
    foreach (['forum', 'hilfe', 'seite', 'seite-x', 'page', 'neuigkeiten', 'topics'] as $free) {
        T::check(!Slugger::isReserved($free), 'nicht reserviert: ' . $free);
    }
    foreach (['thema', 'topic', 'beitrag', 'post'] as $segment) {
        T::check(Slugger::isReserved($segment), 'jedes feste Pfadsegment von Urls ist reserviert: ' . $segment);
    }
});

T::run('Urls', static function (): void {
    Settings::setInstance(Settings::fromArray([]));
    T::same('/forum/thema/12-mein-thema', Urls::topic(12, 'mein-thema', 'de'), 'Thema de');
    T::same('/en/forum/topic/12-mein-thema', Urls::topic(12, 'mein-thema', 'en'), 'Thema en');
    T::same('/forum/thema/12-mein-thema/seite-3', Urls::topic(12, 'mein-thema', 'de', 3), 'Thema, Seite 3 (de)');
    T::same('/en/forum/topic/12-mein-thema/page-3', Urls::topic(12, 'mein-thema', 'en', 3), 'Thema, Seite 3 (en)');
    T::same('/forum/thema/12-mein-thema', Urls::topic(12, 'mein-thema', 'de', 1), 'Seite 1 ohne Zusatz');
    T::same('/forum/thema/12-mein-thema', Urls::topic(12, 'mein-thema', 'de', 0), 'Seite 0 ohne Zusatz');
    T::same('/forum/thema/12', Urls::topic(12, '', 'de'), 'leerer Slug: nur die ID');
    T::same('/en/forum/topic/12-x', Urls::topic(12, 'x', 'en_US'), 'en_US zählt als Englisch');
    T::same('/forum/thema/12-x', Urls::topic(12, 'x', 'fr'), 'andere Sprachen fallen auf Deutsch');
    T::same('/forum/thema/12-x', Urls::topic(12, 'x'), 'Standardsprache Deutsch');
    T::same('/forum/thema/1-a%20b', Urls::topic(1, 'a b', 'de'), 'Slug wird URL-kodiert');
    T::same('/forum/hilfe', Urls::forum('hilfe', 'de'), 'Forum de');
    T::same('/en/forum/help', Urls::forum('help', 'en'), 'Forum en');
    T::same('/forum/hilfe/seite-2', Urls::forum('hilfe', 'de', 2), 'Forum, Seite 2 (de)');
    T::same('/en/forum/help/page-2', Urls::forum('help', 'en', 2), 'Forum, Seite 2 (en)');
    T::same('/forum/beitrag/7', Urls::post(7), 'Beitrag de');
    T::same('/en/forum/post/7', Urls::post(7, 'en'), 'Beitrag en');
    T::same('/forum', Urls::index(), 'Startseite de');
    T::same('/en/forum', Urls::index('en'), 'Startseite en');
    T::same(\CMS\Services\Feed\FeedI18n::path('/forum/thema/1-x', 'en'), '/en/forum/thema/1-x', 'Sprachpräfix wie Feed\\FeedI18n::path()');

    // PR #2b: Anhänge, „ungelesen“, Abmeldelink, absolute Adressen, Mitgliederbereich
    T::same('/forum/anhang/12', Urls::attachment(12), 'attachment(): Standard deutsch, ohne Vorschau');
    T::same('/forum/anhang/12/vorschau', Urls::attachment(12, true), 'attachment(): Vorschau de');
    T::same('/en/forum/attachment/12', Urls::attachment(12, false, 'en'), 'attachment(): en');
    T::same('/en/forum/attachment/12/preview', Urls::attachment(12, true, 'en_GB'), 'attachment(): Vorschau en (en_GB zählt als Englisch)');
    T::same('/forum/thema/7/ungelesen', Urls::topicUnread(7), 'topicUnread(): de');
    T::same('/en/forum/topic/7/unread', Urls::topicUnread(7, 'en'), 'topicUnread(): en');
    T::same('/cms-community-unsubscribe/5.t12.abcdef0123', Urls::unsubscribe('5.t12.abcdef0123'), 'unsubscribe(): ohne Sprachpräfix und ohne base_path');
    T::same('/cms-community-unsubscribe/a%2Fb%20c', Urls::unsubscribe('a/b c'), 'unsubscribe(): Token wird URL-kodiert');
    T::same('/member/plugin/community', Urls::MEMBER_SECTION, 'MEMBER_SECTION: Bereich im Mitgliederbereich');
    $site = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
    T::same($site . '/forum/thema/7-x', Urls::absolute('/forum/thema/7-x'), 'absolute(): SITE_URL + Pfad');
    T::same($site . '/forum', Urls::absolute('forum'), 'absolute(): Pfad ohne führenden Schrägstrich');
    T::same('https://andere.example/x', Urls::absolute('https://andere.example/x'), 'absolute(): absolute Adresse bleibt unverändert');
    T::same($site . '/member/plugin/community?rejected=9', Urls::absolute(Urls::MEMBER_SECTION . '?rejected=9'), 'absolute(): Abfrageteil bleibt');

    T::same('help', Urls::forumSlugFor('hilfe', 'help', 'en'), 'forumSlugFor(): englischer Slug für en');
    T::same('hilfe', Urls::forumSlugFor('hilfe', 'help', 'de'), 'forumSlugFor(): deutscher Slug für de');
    T::same('hilfe', Urls::forumSlugFor('hilfe', null, 'en'), 'forumSlugFor(): slug_en fehlt → slug');
    T::same('hilfe', Urls::forumSlugFor('hilfe', '', 'en'), 'forumSlugFor(): slug_en leer → slug');

    foreach ([
        ['12-mein-thema', ['id' => 12, 'slug' => 'mein-thema']], ['12', ['id' => 12, 'slug' => '']], ['12-', ['id' => 12, 'slug' => '']],
        ['007-x', ['id' => 7, 'slug' => 'x']], ['5-a-b-c', ['id' => 5, 'slug' => 'a-b-c']], ['999999999999999999-x', ['id' => 999999999999999999, 'slug' => 'x']],
    ] as [$segment, $expected]) {
        T::same($expected, Urls::parseSegment($segment), 'parseSegment(' . T::export($segment) . ')');
    }
    foreach (['', 'abc', '-5', '12abc', '0-x', '0', '9999999999999999999-x', "12\n", ' 12', '12 -x'] as $bad) {
        T::same(null, Urls::parseSegment($bad), 'parseSegment(' . T::export($bad) . ') → null');
    }
    Settings::setInstance(null);
});

T::run('Cursor und Page', static function (): void {
    $cursor = new Cursor('2026-11-15 10:00:00', 42);
    $decoded = Cursor::decode($cursor->encode());
    T::same(['2026-11-15 10:00:00', 42], [$decoded?->at, $decoded?->id], 'Cursor: encode/decode');
    T::check(preg_match('/^[A-Za-z0-9_-]+$/', $cursor->encode()) === 1, 'Cursor: URL-sicher (base64url ohne Auffüllung)');
    foreach ([null, '', 'x y', '!!!', str_repeat('a', 65), base64_encode('garbage'), rtrim(strtr(base64_encode('2026-11-15 10:00:00|0'), '+/', '-_'), '='),
        rtrim(strtr(base64_encode('2026-13-15 10:00:00x|5'), '+/', '-_'), '='), rtrim(strtr(base64_encode('2026-11-15 10:00:00|5|6'), '+/', '-_'), '=')] as $bad) {
        T::same(null, Cursor::decode($bad), 'Cursor::decode(' . T::export($bad) . ') → null (erste Seite)');
    }
    $page = new Page([['id' => 1], ['id' => 2]], 'abc');
    T::same([2, 'abc'], [count($page->items), $page->next], 'Page: Einträge und Cursor der nächsten Seite');
    T::same(null, (new Page([], null))->next, 'Page: letzte Seite hat keinen Cursor');
});

T::run('I18n', static function (): void {
    I18n::reset('de', 'de', 'Europe/Berlin');
    $de = [];
    $en = [];
    foreach (csLang('de') as [$key, $value]) {
        $de[$key] = strtr($value, ['\\"' => '"', '\\\\' => '\\']);
    }
    foreach (csLang('en') as [$key, $value]) {
        $en[$key] = strtr($value, ['\\"' => '"', '\\\\' => '\\']);
    }
    T::same($de['community.access.login_required'], I18n::t('community.access.login_required'), 't(): deutscher Text aus der Sprachdatei');
    T::same($en['community.access.login_required'], I18n::t('community.access.login_required', [], 'en'), 't(): englischer Text bei ausdrücklicher Sprache');
    T::same($en['community.access.login_required'], I18n::t('community.access.login_required', [], 'en_US'), 't(): en_US zählt als Englisch');
    T::same($de['community.access.login_required'], I18n::t('community.access.login_required', [], 'fr'), 't(): unbekannte Sprache → Deutsch');
    T::check($de['community.access.login_required'] !== $en['community.access.login_required'], 'Deutsch und Englisch unterscheiden sich');
    T::same('community.nicht.vorhanden', I18n::t('community.nicht.vorhanden'), 't(): unbekannter Schlüssel → der Schlüssel selbst');
    T::same('community.nicht.vorhanden', I18n::t('community.nicht.vorhanden', ['name' => 'x'], 'en'), 't(): unbekannter Schlüssel in en → der Schlüssel selbst');
    I18n::reset('de', 'en', 'Europe/Berlin');
    T::same($en['community.error.db_error'], I18n::t('community.error.db_error'), 't(): ohne Sprachangabe gilt die Sprache der Anfrage');
    T::same(['de', 'en'], [I18n::siteLocale(), I18n::requestLocale()], 'siteLocale()/requestLocale() sind öffentlich (Sprache je Empfänger, Stemmer der Suche)');
    I18n::reset('en_US', null, 'Europe/Berlin');
    T::same(['en', 'en'], [I18n::siteLocale(), I18n::requestLocale()], 'requestLocale(): ohne Anfrage gilt die Websitesprache');
    foreach (['siteLocale', 'requestLocale'] as $method) {
        $reflection = new ReflectionMethod(I18n::class, $method);
        T::check($reflection->isPublic() && $reflection->isStatic() && (string) $reflection->getReturnType() === 'string', 'I18n::' . $method . '(): public static string');
    }
    I18n::reset('de', 'en', 'Europe/Berlin');

    // Platzhalter: über __() (Websitesprache), danach über den Katalog.
    if (!function_exists('__')) {
        eval('function __(string $key): string { return ["community.test.hello" => "Hallo {name}, {count} neue Beiträge ({name})"][$key] ?? $key; }');
    }
    I18n::reset('de', 'de');
    T::same('Hallo Eva, 3 neue Beiträge (Eva)', I18n::t('community.test.hello', ['name' => 'Eva', 'count' => 3]), 't(): {name}-Platzhalter in Websitesprache über __()');
    T::same('Hallo {name}, {count} neue Beiträge ({name})', I18n::t('community.test.hello'), 't(): ohne Werte bleiben Platzhalter stehen');
    T::same('Hallo <b>&, 1.5 neue Beiträge (<b>&)', I18n::t('community.test.hello', ['name' => '<b>&', 'count' => 1.5]), 't(): Werte werden nicht escaped (Aufrufer escapt)');
    T::same('community.test.hello', I18n::t('community.test.hello', ['name' => 'Eva'], 'en'), 't(): andere Sprache als die Websitesprache liest den Katalog statt __()');

    // Zeit: UTC → Zeitzone der Website.
    I18n::reset(null, null, 'Europe/Berlin');
    T::same('2026-07-02 00:30', I18n::date('2026-07-01 22:30:00', 'Y-m-d H:i'), 'date(): Sommerzeit (UTC+2)');
    T::same('2026-01-02 00:30', I18n::date('2026-01-01 23:30:00', 'Y-m-d H:i'), 'date(): Winterzeit (UTC+1)');
    T::same('02.07.2026', I18n::date('2026-07-01 22:30:00', 'd.m.Y'), 'date(): PHP-Format');
    I18n::reset(null, null, 'America/New_York');
    T::same('2026-07-01 08:00', I18n::date('2026-07-01 12:00:00', 'Y-m-d H:i'), 'date(): andere Zeitzone der Website');
    I18n::reset(null, null, 'Nowhere/Land');
    T::same('2026-07-02 00:30', I18n::date('2026-07-01 22:30:00', 'Y-m-d H:i'), 'date(): unbekannte Zeitzone → PHP-Standardzone');
    I18n::reset(null, null, null);
    T::same('2026-07-02 00:30', I18n::date('2026-07-01 22:30:00', 'Y-m-d H:i'), 'date(): ohne Einstellung gilt die PHP-Standardzone (Europe/Berlin)');
    foreach (['', '0000-00-00 00:00:00', 'garbage', '2026-02-30 10:00:00', '2026-07-01', '2026-07-01 25:00:00'] as $bad) {
        T::same('', I18n::date($bad, 'Y-m-d'), 'date(' . T::export($bad) . '): ungültige Zeit → leerer Text');
    }
    T::same('2026-07-02', I18n::date('  2026-07-01 22:30:00 ', 'Y-m-d'), 'date(): Leerraum um die Zeit wird toleriert');
    I18n::reset();
});

/** Hook-Konstanten in der Reihenfolge des Registers (Vertrag DESIGN-2b §3.3): Konstante => Hook. */
const CS_EVENTS = [
    'TOPIC_CREATED' => 'cms_community_topic_created', 'TOPIC_UPDATED' => 'cms_community_topic_updated', 'TOPIC_DELETED' => 'cms_community_topic_deleted',
    'POST_CREATED' => 'cms_community_post_created', 'POST_UPDATED' => 'cms_community_post_updated', 'POST_DELETED' => 'cms_community_post_deleted',
    'CAN' => 'cms_community_can',
    'POST_APPROVED' => 'cms_community_post_approved', 'POST_REJECTED' => 'cms_community_post_rejected', 'POST_HIDDEN' => 'cms_community_post_hidden',
    'POST_RESTORED' => 'cms_community_post_restored', 'POST_MOVED' => 'cms_community_post_moved',
    'TOPIC_APPROVED' => 'cms_community_topic_approved', 'TOPIC_REJECTED' => 'cms_community_topic_rejected', 'TOPIC_HIDDEN' => 'cms_community_topic_hidden',
    'TOPIC_RESTORED' => 'cms_community_topic_restored', 'TOPIC_MOVED' => 'cms_community_topic_moved', 'TOPIC_MERGED' => 'cms_community_topic_merged',
    'TOPIC_SPLIT' => 'cms_community_topic_split',
    'SOLUTION_MARKED' => 'cms_community_solution_marked', 'SOLUTION_UNMARKED' => 'cms_community_solution_unmarked',
    'REPORT_CREATED' => 'cms_community_report_created', 'REPORT_RESOLVED' => 'cms_community_report_resolved',
    'USER_BANNED' => 'cms_community_user_banned', 'USER_UNBANNED' => 'cms_community_user_unbanned', 'USER_WARNED' => 'cms_community_user_warned',
    'REACTION_CHANGED' => 'cms_community_reaction_changed', 'SUBSCRIPTION_CHANGED' => 'cms_community_subscription_changed',
    'RENDER_POST' => 'cms_community_render_post', 'SPAM_CHECK' => 'cms_community_spam_check', 'NOTIFICATION_TYPES' => 'cms_community_notification_types',
];

/** In 4.2.0 nicht ausgelöst (P2): stehen in HOOKS-REFERENCE unter „Vorgesehen“. */
const CS_EVENTS_PLANNED = ['cms_community_topic_merged', 'cms_community_topic_split', 'cms_community_user_warned'];

T::run('Events', static function (): void {
    foreach (CS_EVENTS as $constant => $name) {
        T::same($name, constant(Events::class . '::' . $constant), 'Events::' . $constant . ' = ' . $name);
    }
    $constants = (new ReflectionClass(Events::class))->getConstants();
    T::same(CS_EVENTS, $constants, 'Events enthält genau die 31 Hook-Konstanten des Registers (Vertrag DESIGN-2b §3.3) in dieser Reihenfolge');
    T::same(31, count($constants), '18 aus #2a, 13 neu');
    $values = array_values($constants);
    T::same(count($values), count(array_unique($values)), 'Hook-Namen sind eindeutig');
    T::same(true, array_reduce($values, static fn (bool $all, string $name): bool => $all && preg_match('/^cms_community_[a-z_]+$/', $name) === 1, true), 'Namensschema cms_community_*');

    // fire(): Argumente kommen an, Ausnahmen werden verschluckt (und protokolliert).
    $seen = [];
    Hooks::addAction(Events::POST_CREATED, static function (mixed ...$args) use (&$seen): void {
        $seen[] = $args;
    });
    Events::fire(Events::POST_CREATED, 5, 3, 7, 'published');
    T::same([[5, 3, 7, 'published']], $seen, 'fire(): Listener erhält die Argumente unverändert');
    Hooks::addAction(Events::TOPIC_DELETED, static function (): void {
        throw new \RuntimeException('Listener kaputt');
    });
    $thrown = false;
    try {
        Events::fire(Events::TOPIC_DELETED, 1, 2, 3, 'deleted');
    } catch (\Throwable) {
        $thrown = true;
    }
    T::check(!$thrown, 'fire(): wirft nie, auch wenn ein Listener wirft');
    Events::fire('cms_community_ohne_listener', 1);
    T::check(true, 'fire(): Hook ohne Listener ist kein Fehler');

    // applyFilter(): Wert und Zusatzargumente, Eingangswert bei Fehler.
    Hooks::addFilter(Events::CAN, static fn (mixed $value, mixed $userId = 0, mixed $action = ''): string => $value . '|' . $userId . '|' . $action);
    T::same('x|9|read', Events::applyFilter(Events::CAN, 'x', 9, 'read'), 'applyFilter(): Filter erhält Wert und Zusatzargumente');
    T::same('unveraendert', Events::applyFilter('cms_community_ohne_filter', 'unveraendert'), 'applyFilter(): ohne Filter gilt der Eingangswert');
    Hooks::addFilter(Events::RENDER_POST, static function (mixed $value): string {
        throw new \RuntimeException('Filter kaputt');
    });
    T::same('<p>x</p>', Events::applyFilter(Events::RENDER_POST, '<p>x</p>', 5), 'applyFilter(): Filter wirft → Eingangswert');
});

T::run('Modul community: Modul-Eintrag, Gate, Registry, CommunityHooks', static function (): void {
    $modules = (new ReflectionClassConstant(CoreModuleService::class, 'MODULES'))->getValue();
    T::check(isset($modules[CommunityModule::SLUG]), 'community steht in CoreModuleService::MODULES (isModuleEnabled() liefert für unbekannte Slugs true)');
    $entry = $modules['community'] ?? [];
    T::same(false, $entry['default_enabled'] ?? null, 'community ist standardmäßig aus');
    T::same(true, $entry['toggleable'] ?? null, 'community ist schaltbar');
    T::same(['content', 'Inhalte & Community', 50], [$entry['category'] ?? null, $entry['category_label'] ?? null, $entry['order'] ?? null], 'Kategorie Inhalte & Community, Reihenfolge 50');
    T::same(['Community', [], ['community', 'community-structure', 'community-topics', 'community-queue', 'community-reports', 'community-sanctions', 'community-log', 'community-permissions', 'community-ranks', 'community-settings'], ['Community'], null, null], [$entry['label'] ?? null, $entry['dependencies'] ?? null, $entry['admin_pages'] ?? null, $entry['admin_labels'] ?? null, $entry['sidebar_group'] ?? null, $entry['legacy_setting'] ?? null], 'Bezeichnung; keine Abhängigkeiten oder Legacy-Einstellung, Admin-Seiten (PR #3)');
    T::check(is_string($entry['description'] ?? null) && $entry['description'] !== '', 'Beschreibung vorhanden');
    $others = array_filter($modules, static fn (array $module, string $slug): bool => $slug !== 'community' && $module['default_enabled'] === false, ARRAY_FILTER_USE_BOTH);
    T::same([], array_keys($others), 'community ist das einzige Modul mit default_enabled = false');

    $source = csRead(ABSPATH . 'core/Services/CoreModuleService.php');
    T::same(1, preg_match('/^ {8}\'community\'\s*=>\s*\[(.*?)^ {8}\],/ms', $source, $match), 'Eintrag mit 8 Leerzeichen Einrückung und Schlussklammer allein in der Zeile (Regex von TESTS/admin-menu)');
    T::check(!str_contains($match[1] ?? '', '::class') && !str_contains($match[1] ?? '', '\\'), 'Eintrag enthält keinen Klassenbezug (Installer-Vorschau läuft ohne Autoloader)');
    T::check(!str_contains($source, 'CommunityModule'), 'CoreModuleService kennt CommunityModule nicht');

    // Gate
    CommunityModule::setGate(static fn (): bool => true);
    T::same(true, CommunityModule::isEnabled(), 'Gate offen → aktiv');
    CommunityModule::setGate(static fn (): bool => false);
    T::same(false, CommunityModule::isEnabled(), 'Gate zu → aus');
    CommunityModule::setGate(static function (): bool {
        throw new \RuntimeException('Datenbank nicht erreichbar');
    });
    T::same(false, CommunityModule::isEnabled(), 'Ausnahme bei der Abfrage → aus (fail-closed)');
    CommunityModule::setGate(static fn (): bool => (bool) 'ja');
    T::same(true, CommunityModule::isEnabled(), 'wahrer Wert → aktiv');
    CommunityModule::setGate(static fn (): mixed => 1);
    T::same(false, CommunityModule::isEnabled(), 'nur true schaltet frei (1 genügt nicht)');
    $moduleSource = csRead(CS_ROOT . 'Support/CommunityModule.php');
    T::check(str_contains($moduleSource, 'catch (\\Throwable)') && str_contains($moduleSource, 'return false;'), 'CommunityModule: Ausnahme → false im Quelltext');

    // Registry
    $features = (new ReflectionClassConstant(Registry::class, 'FEATURES'))->getValue();
    T::check(in_array(CommunityHooks::class, $features, true), 'Registry::FEATURES enthält CommunityHooks');
    T::same(CommunityHooks::class, end($features), 'CommunityHooks steht am Ende der Registrierungsreihenfolge');
    T::check(method_exists(CommunityHooks::class, 'register'), 'CommunityHooks::register() vorhanden');
    T::check(str_contains(csRead(ABSPATH . 'core/Services/FeatureHooks/Registry.php'), 'CommunityHooks::class'), 'Registry nennt CommunityHooks::class im Quelltext');

    // CommunityHooks: Gate zu → nichts angemeldet.
    T::check(!Hooks::hasAction('user_role_changed') && !Hooks::hasAction('user_email_verified'), 'Vorbedingung: noch keine Listener');
    CommunityModule::setGate(static fn (): bool => false);
    CommunityHooks::register();
    T::check(!Hooks::hasAction('user_role_changed') && !Hooks::hasAction('user_email_verified'), 'Modul aus: keine Listener angemeldet');
    CommunityModule::setGate(static function (): bool {
        throw new \RuntimeException('kaputt');
    });
    CommunityHooks::register();
    T::check(!Hooks::hasAction('user_role_changed') && !Hooks::hasAction('user_email_verified'), 'Modul nicht prüfbar: keine Listener angemeldet (fail-closed)');

    // Modul an: genau die zwei Listener; Aufruf von CommunityAccess::forgetUser() (Stellvertreter, solange die Klasse fehlt).
    $real = class_exists(\CMS\Services\Community\Service\CommunityAccess::class);
    if (!$real) {
        eval('namespace CMS\\Services\\Community\\Service; final class CommunityAccess {
            public static array $forgotten = [];
            public static bool $fail = false;
            public static function instance(): self { return new self(); }
            public function forgetUser(int $userId): void { if (self::$fail) { throw new \\RuntimeException("kaputt"); } self::$forgotten[] = $userId; }
        }');
    }
    CommunityModule::setGate(static fn (): bool => true);
    CommunityHooks::register();
    T::check(Hooks::hasAction('user_role_changed') && Hooks::hasAction('user_email_verified'), 'Modul an: Listener für user_role_changed und user_email_verified');
    T::check(Hooks::hasAction('cms_cron_daily') && Hooks::hasAction('dsgvo_export_data') && Hooks::hasAction('dsgvo_delete_data'), 'Löschfristen (Cron) und DSGVO-Hooks sind angemeldet (PR #5)');
    T::check(Hooks::hasAction('register_routes'), 'Modul an: Leseseiten melden ihre Routen an (PR #4)');

    $hooksSource = csRead(ABSPATH . 'core/Services/FeatureHooks/CommunityHooks.php');
    T::check(
        (int) strpos($hooksSource, 'CommunityModule::isEnabled()') > 0 && strpos($hooksSource, 'CommunityModule::isEnabled()') < strpos($hooksSource, "Hooks::addAction('user_role_changed'"),
        'Quelltext: Modul-Gate steht vor den Listenern für user_role_changed und user_email_verified (die Rollen-Listener cms_role_* laufen bewusst immer, PR #3)'
    );
    T::check(str_contains($hooksSource, 'class_exists(CommunityAccess::class)') && str_contains($hooksSource, 'CommunityAccess::instance()->forgetUser($userId)'), 'Quelltext: forgetUser() nur bei vorhandener Klasse');
    T::same(13, substr_count($hooksSource, 'Hooks::addAction('), 'Quelltext: dreizehn Listener (vier hinter dem Modul-Gate: Routen, Mitgliederbereich, user_role_changed, user_email_verified; davor immer: cms_role_renamed, cms_role_deleted, dsgvo_export_data, dsgvo_delete_data, cms_user_deleted, cms_init_admin, cms_cron_daily zweimal, Papierkorb-Provider (cms_register_content_type_providers))');
    T::check(!preg_match('/Database|get_var|get_row|get_results|execute\(/', $hooksSource), 'keine Datenbankzugriffe zur Registrierungszeit');

    if (!$real) {
        $access = \CMS\Services\Community\Service\CommunityAccess::class;
        Hooks::doAction('user_role_changed', 5, 'member', 'editor', 'admin_update');
        Hooks::doAction('user_email_verified', '7');
        Hooks::doAction('user_role_changed', 0, 'member', 'editor', 'sso');
        Hooks::doAction('user_role_changed', 'x', 'member', 'editor', 'sso');
        Hooks::doAction('user_email_verified');
        T::same([5, 7], $access::$forgotten, 'Listener verwerfen den Rechte-Cache der Benutzer 5 und 7 (ungültige IDs werden übergangen)');
        $access::$fail = true;
        $thrown = false;
        try {
            Hooks::doAction('user_role_changed', 9, 'member', 'editor', 'sso');
        } catch (\Throwable) {
            $thrown = true;
        }
        T::check(!$thrown, 'Fehler in forgetUser() bricht den Auslöser (Speichern der Rolle) nicht ab');
        $access::$fail = false;
    } else {
        $method = new ReflectionMethod(\CMS\Services\Community\Service\CommunityAccess::class, 'forgetUser');
        T::check($method->isPublic() && !$method->isStatic() && $method->getNumberOfParameters() === 1 && (string) $method->getParameters()[0]->getType() === 'int', 'CommunityAccess::forgetUser(int) ist öffentlich');
        T::check((new ReflectionMethod(\CMS\Services\Community\Service\CommunityAccess::class, 'instance'))->isStatic(), 'CommunityAccess::instance() ist statisch');
        T::info('CommunityAccess vorhanden: der Listener-Aufruf wird nur über Quelltext und Signatur geprüft');
    }
    CommunityModule::setGate(null);
});

/**
 * Der echte Weg des Modul-Schalters (Befund B44): `CoreModuleService::isModuleEnabled()` → `SettingsService` → `OptionStore` → Zeile
 * `core_modules.community` der Tabelle `settings` einer Datenbank. Setzt die Singletons auf die Datenbank `$db`, ruft `$body` auf und
 * stellt sie danach wieder her.
 *
 * @param \Closure(Database): void $body
 */
function csWithModuleStack(Database $db, \Closure $body): void
{
    $swap = static function (string $class, string $property, mixed $value): mixed {
        $reflection = new ReflectionProperty($class, $property);
        $previous = $reflection->getValue();
        $reflection->setValue(null, $value);

        return $previous;
    };
    $settings = (new ReflectionClass(SettingsService::class))->newInstanceWithoutConstructor();
    foreach (['db' => $db, 'prefix' => $db->getPrefix(), 'logger' => \CMS\Logger::instance()->withChannel('settings')] as $property => $value) {
        (new ReflectionProperty(SettingsService::class, $property))->setValue($settings, $value);
    }
    $modules = (new ReflectionClass(CoreModuleService::class))->newInstanceWithoutConstructor();
    foreach (['settings' => $settings, 'db' => $db, 'prefix' => $db->getPrefix()] as $property => $value) {
        (new ReflectionProperty(CoreModuleService::class, $property))->setValue($modules, $value);
    }
    $previous = [
        $swap(Database::class, 'instance', $db),
        $swap(OptionStore::class, 'instance', null),
        $swap(SettingsService::class, 'instance', $settings),
        $swap(CoreModuleService::class, 'instance', $modules),
    ];
    try {
        $body($db);
    } finally {
        $swap(CoreModuleService::class, 'instance', $previous[3]);
        $swap(SettingsService::class, 'instance', $previous[2]);
        $swap(OptionStore::class, 'instance', $previous[1]);
        $swap(Database::class, 'instance', $previous[0]);
    }
}

/** Zahl der angemeldeten Listener der beiden Hooks, die `CommunityHooks::register()` setzt. */
function csCommunityListeners(): int
{
    return count(Hooks::getCallbacks('user_role_changed', 'action')) + count(Hooks::getCallbacks('user_email_verified', 'action'));
}

T::run('Modul-Schalter über CoreModuleService und die Tabelle settings (SQLite-Double und, wenn konfiguriert, MariaDB)', static function (): void {
    $backends = ['SQLite-Double' => static fn (): Database => CommunityTestDatabase::create()];
    if (MariaDbTestDatabase::configured()) {
        $backends['MariaDB'] = static fn (): Database => MariaDbTestDatabase::create();
    }
    $gate = CommunityModule::SLUG;
    // gespeicherter Wert => erwarteter Zustand (Modul community: Standard aus; nur ein erkennbares „an“ schaltet ein)
    $states = [
        '1' => true, 'true' => true, 'TRUE' => true, ' 1 ' => true, 'yes' => true, 'on' => true, 'ON' => true, 'json:true' => true, 'json:1' => true,
        '0' => false, 'false' => false, 'off' => false, 'no' => false, '' => false, '   ' => false, 'json:false' => false, 'json:0' => false,
        'ja' => false, 'enabled' => false, '2' => false, 'json:null' => false, 'json:"on"' => true, 'json:[1]' => false,
    ];

    foreach ($backends as $label => $make) {
        $db = $make();
        try {
            csWithModuleStack($db, static function (Database $db) use ($label, $states, $gate): void {
                $service = CoreModuleService::getInstance();
                $prefix = $db->getPrefix();
                $write = static function (?string $value, int $autoload) use ($db, $prefix): void {
                    $db->execute("DELETE FROM {$prefix}settings WHERE option_name = ?", ['core_modules.community']);
                    if ($value !== null) {
                        $db->execute("INSERT INTO {$prefix}settings (option_name, option_value, autoload) VALUES (?, ?, ?)", ['core_modules.community', $value, $autoload]);
                    }
                    OptionStore::getInstance()->flush();
                    SettingsService::flushGroupCache();
                };

                T::same(true, $service === CoreModuleService::getInstance(), "[{$label}] der Dienst ist die vorbereitete Instanz");
                T::same(false, $service->getModuleDefinition($gate)['default_enabled'] ?? null, "[{$label}] Standard laut Modul-Eintrag: aus");

                $write(null, 1);
                T::same([false, false], [$service->isModuleEnabled($gate), CommunityModule::isEnabled()], "[{$label}] keine Zeile: Modul aus (Standard)");
                foreach ([1, 0] as $autoload) {
                    foreach ($states as $stored => $expected) {
                        $write((string) $stored, $autoload);
                        T::same(
                            [$expected, $expected],
                            [$service->isModuleEnabled($gate), CommunityModule::isEnabled()],
                            "[{$label}] gespeichert " . T::export((string) $stored) . " (autoload {$autoload}): Modul " . ($expected ? 'an' : 'aus')
                        );
                    }
                }
                // Der Zustand folgt dem Schreiben auch ohne neuen Prozess (Zwischenspeicher wird nach dem Schreiben verworfen)
                $write('1', 0);
                T::same(true, CommunityModule::isEnabled(), "[{$label}] Zeile mit 1 → an");
                $write('0', 0);
                T::same(false, CommunityModule::isEnabled(), "[{$label}] danach 0 → aus");
                $write(null, 0);
                T::same(false, CommunityModule::isEnabled(), "[{$label}] Zeile gelöscht → aus");

                // Listener: nur bei eingeschaltetem Modul
                $write('0', 1);
                $before = csCommunityListeners();
                CommunityHooks::register();
                T::same($before, csCommunityListeners(), "[{$label}] Modul aus (Zeile 0): CommunityHooks meldet nichts an");
                $write(null, 1);
                CommunityHooks::register();
                T::same($before, csCommunityListeners(), "[{$label}] keine Zeile: CommunityHooks meldet nichts an");
                $write('1', 1);
                CommunityHooks::register();
                T::same($before + 2, csCommunityListeners(), "[{$label}] Modul an: CommunityHooks meldet beide Listener an");
            });

            // Datenbank nicht erreichbar: aus (fail-closed), auch wenn der Standard für andere Module „an“ wäre
            $broken = new class () extends Database {
                public function __construct()
                {
                }

                public function getPrefix(): string
                {
                    return 'cms_';
                }

                public function get_results(string $query, array $params = []): array
                {
                    throw new \PDOException('Datenbank nicht erreichbar');
                }

                public function get_var(string $query, array $params = []): mixed
                {
                    throw new \PDOException('Datenbank nicht erreichbar');
                }
            };
            csWithModuleStack($broken, static function () use ($label, $gate): void {
                T::same([false, false], [CoreModuleService::getInstance()->isModuleEnabled($gate), CommunityModule::isEnabled()], "[{$label}] Datenbank nicht erreichbar: Modul aus");
                $before = csCommunityListeners();
                CommunityHooks::register();
                T::same($before, csCommunityListeners(), "[{$label}] Datenbank nicht erreichbar: CommunityHooks meldet nichts an");
            });
        } finally {
            if ($db instanceof MariaDbTestDatabase) {
                $db->dispose();
            }
        }
    }
    CommunityModule::setGate(null);
});

/**
 * Zusatzschlüssel nach `community.access.*` und `community.error.*` in dieser Reihenfolge (Vertrag DESIGN-2b §3.4 Nr. 3): 3 Zitat,
 * 5 Meldegründe, 4 Meldestatus, 2 Sperrumfang, 35 Hinweise, 14 Mails (63). Die drei Gruppen der Sanktionen stammen aus
 * `ReportService::REASONS`/`::STATUSES` und `BanService::SCOPES` (J2): ein neuer Grund, Status oder Umfang verlangt seinen Sprachschlüssel.
 */
define('CS_EXTRA_KEYS', [
    'community.quote.hidden', 'community.quote.removed', 'community.quote.source',
    ...array_map(static fn (string $reason): string => 'community.report.reason.' . $reason, \CMS\Services\Community\Service\ReportService::REASONS),
    ...array_map(static fn (string $status): string => 'community.report.status.' . $status, \CMS\Services\Community\Service\ReportService::STATUSES),
    ...array_map(static fn (string $scope): string => 'community.ban.scope.' . $scope, \CMS\Services\Community\Service\BanService::SCOPES),
    'community.notify.deleted_member',
    'community.notify.reply.title', 'community.notify.reply.title_many', 'community.notify.reply.message', 'community.notify.reply.message_many',
    'community.notify.new_topic.title', 'community.notify.new_topic.title_many', 'community.notify.new_topic.message', 'community.notify.new_topic.message_many',
    'community.notify.quote.title', 'community.notify.quote.title_many', 'community.notify.quote.message', 'community.notify.quote.message_many',
    'community.notify.reaction.title', 'community.notify.reaction.title_many', 'community.notify.reaction.message', 'community.notify.reaction.message_many',
    'community.notify.solution.title', 'community.notify.solution.message',
    'community.notify.moderation.approved', 'community.notify.moderation.approved_topic', 'community.notify.moderation.rejected', 'community.notify.moderation.rejected_topic',
    'community.notify.moderation.hidden', 'community.notify.moderation.hidden_topic', 'community.notify.moderation.reason', 'community.notify.moderation.no_reason',
    'community.notify.moderation.generic',
    'community.notify.moderation.moved', 'community.notify.report.title', 'community.notify.report.title_many', 'community.notify.report.message',
    'community.notify.ban.title', 'community.notify.ban.message', 'community.notify.ban.permanent',
    'community.mail.greeting', 'community.mail.reply.subject', 'community.mail.reply.body', 'community.mail.reply.gate',
    'community.mail.new_topic.subject', 'community.mail.new_topic.body', 'community.mail.new_topic.gate', 'community.mail.link',
    'community.mail.unsubscribe_topic', 'community.mail.unsubscribe_forum', 'community.mail.unsubscribe_all', 'community.mail.settings',
    'community.mail.ban.subject', 'community.mail.ban.contact',
    // PR #5: Papierkorb (CommunityContentProvider)
    'community.trash.label', 'community.trash.restored', 'community.trash.deleted', 'community.trash.purged', 'community.trash.confirm',
    'community.trash.warning.reports', 'community.trash.warning.unknown',
    'community.trash.error.not_found', 'community.trash.error.forum_missing', 'community.trash.error.rejected', 'community.trash.error.state', 'community.trash.error.failed',
]);

T::run('Zusatzschlüssel CS_EXTRA_KEYS: Zahlen und Ableitung aus den Diensten', static function (): void {
    $group = static fn (string $prefix): array => array_values(array_filter(CS_EXTRA_KEYS, static fn (string $key): bool => str_starts_with($key, $prefix)));
    T::same(75, count(CS_EXTRA_KEYS), '75 Zusatzschlüssel (63 aus PR #2b, 12 für den Papierkorb aus PR #5)');
    T::same(count(CS_EXTRA_KEYS), count(array_unique(CS_EXTRA_KEYS)), 'Zusatzschlüssel eindeutig');
    T::same([3, 5, 4, 2, 35, 14, 12], [count($group('community.quote.')), count($group('community.report.reason.')), count($group('community.report.status.')), count($group('community.ban.scope.')), count($group('community.notify.')), count($group('community.mail.')), count($group('community.trash.'))], 'Gruppen: Zitat 3, Gründe 5, Status 4, Umfang 2, Hinweise 35, Mails 14, Papierkorb 12');
    T::same(95, count(Codes::ACCESS_REASONS) - 23 + count(Codes::ERRORS) - 16 + count(CS_EXTRA_KEYS) - count($group('community.trash.')), '95 neue Schlüssel je Sprache in PR #2b (5 Gründe, 27 Codes, 63 Zusatzschlüssel)');
    // Abgeleitete Gruppen: Werte und Reihenfolge der Dienste (= Repository-Konstanten), Sprachschlüssel in derselben Folge.
    $derived = [
        'community.report.reason.' => [\CMS\Services\Community\Service\ReportService::REASONS, \CMS\Services\Community\Repository\ReportRepository::REASONS, 'ReportService::REASONS'],
        'community.report.status.' => [\CMS\Services\Community\Service\ReportService::STATUSES, \CMS\Services\Community\Repository\ReportRepository::STATUSES, 'ReportService::STATUSES'],
        'community.ban.scope.' => [\CMS\Services\Community\Service\BanService::SCOPES, \CMS\Services\Community\Repository\BanRepository::SCOPES, 'BanService::SCOPES'],
    ];
    foreach ($derived as $prefix => [$service, $repository, $label]) {
        $keys = array_map(static fn (string $key): string => substr($key, strlen($prefix)), $group($prefix));
        T::same(array_values($service), $keys, $prefix . '*: abgeleitet aus ' . $label);
        T::same(array_values($repository), array_values($service), $label . ' = Konstante des Repositorys');
    }
    T::same(
        [['spam', 'abuse', 'offtopic', 'illegal', 'other'], ['open', 'valid', 'invalid', 'resolved'], ['write', 'full']],
        [\CMS\Services\Community\Service\ReportService::REASONS, \CMS\Services\Community\Service\ReportService::STATUSES, \CMS\Services\Community\Service\BanService::SCOPES],
        'Register §3.4: Meldegründe, Meldestatus und Sperrumfang wie im Vertrag'
    );
});

T::run('Codes ↔ Sprachschlüssel (de und en, zeilenparallel)', static function (): void {
    $de = csLang('de');
    $en = csLang('en');
    T::same(
        count(file(ABSPATH . 'lang/de.yaml')),
        count(file(ABSPATH . 'lang/en.yaml')),
        'de.yaml und en.yaml haben gleich viele Zeilen'
    );
    T::same(array_column($de, 0), array_column($en, 0), 'Schlüssel in beiden Dateien identisch und in gleicher Reihenfolge');
    $deText = array_column($de, 1, 0);
    $enText = array_column($en, 1, 0);

    $expected = [];
    foreach (array_keys(Codes::ACCESS_REASONS) as $code) {
        $expected[] = 'community.access.' . $code;
        T::same('community.access.' . $code, Codes::accessKey($code), 'Codes::accessKey(' . $code . ')');
    }
    foreach (Codes::ERRORS as $code) {
        $expected[] = 'community.error.' . $code;
        T::same('community.error.' . $code, Codes::errorKey($code), 'Codes::errorKey(' . $code . ')');
    }
    $expected = [...$expected, ...CS_EXTRA_KEYS];
    $communityKeys = array_values(array_filter(array_column($de, 0), static fn (string $key): bool => str_starts_with($key, 'community.')));
    T::same($expected, $communityKeys, 'jeder Code hat genau einen Schlüssel (Reihenfolge ACCESS_REASONS, dann ERRORS, dann CS_EXTRA_KEYS), keine überzähligen community.*-Schlüssel');
    T::same([\CMS\Services\Community\Service\QuoteService::HIDDEN_NOTICE, \CMS\Services\Community\Service\QuoteService::REMOVED_NOTICE], [$deText['community.quote.hidden'] ?? '', $deText['community.quote.removed'] ?? ''], 'community.quote.hidden/removed (de) = QuoteService::HIDDEN_NOTICE/REMOVED_NOTICE');
    T::same(
        ['Zu viele Anfragen in kurzer Zeit. Bitte warte einen Moment.', 'Too many requests in a short time. Please wait a moment.'],
        [$deText['community.access.rate_limited'] ?? '', $enText['community.access.rate_limited'] ?? ''],
        'community.access.rate_limited ist neutral (Suche, Vorschau, Reaktionen, Uploads)'
    );
    T::same(count($expected), count(array_unique($communityKeys)), 'keine doppelten community.*-Schlüssel');

    foreach ($expected as $key) {
        T::check(preg_match('/^community\.[a-z0-9_]+\.[a-z0-9_.]*[a-z0-9_]$/', $key) === 1, $key . ': mindestens drei Segmente, Kleinbuchstaben');
        T::check(isset($deText[$key]) && trim($deText[$key]) !== '', $key . ': deutscher Text vorhanden');
        T::check(isset($enText[$key]) && trim($enText[$key]) !== '', $key . ': englischer Text vorhanden');
        T::check(($deText[$key] ?? '') !== ($enText[$key] ?? ''), $key . ': deutscher und englischer Text unterscheiden sich');
        T::same(csPlaceholders($deText[$key] ?? ''), csPlaceholders($enText[$key] ?? ''), $key . ': gleiche Platzhalter in beiden Sprachen');
    }
    foreach (['de' => $deText, 'en' => $enText] as $locale => $texts) {
        $empty = array_keys(array_filter($texts, static fn (string $text): bool => trim($text) === ''));
        T::same([], $empty, $locale . '.yaml: keine leeren Werte');
    }

    I18n::reset('de', 'de');
    foreach ($expected as $key) {
        T::check(I18n::t($key) !== $key && I18n::t($key, [], 'en') !== $key, $key . ': I18n::t() findet den Schlüssel in beiden Sprachen');
    }
    I18n::reset();

    $yamlClass = 'Symfony\\Component\\Yaml\\Yaml';
    if (!class_exists($yamlClass) && is_file(ABSPATH . 'assets/autoload.php')) {
        require_once ABSPATH . 'assets/autoload.php';
    }
    if (class_exists($yamlClass)) {
        foreach (['de', 'en'] as $locale) {
            $parsed = $yamlClass::parseFile(ABSPATH . 'lang/' . $locale . '.yaml');
            $flat = $parsed['default'] ?? [];
            $mismatch = [];
            foreach ($expected as $key) {
                if (!isset($flat[$key]) || $flat[$key] !== stripcslashes($locale === 'de' ? $deText[$key] : $enText[$key])) {
                    $mismatch[] = $key;
                }
            }
            T::same([], $mismatch, $locale . '.yaml: der Symfony-YAML-Parser liest alle Community-Schlüssel wie der Zeilenparser');
        }
    } else {
        T::info('Symfony\\Yaml nicht ladbar: vollständiges YAML-Parsen übersprungen');
    }
});

T::run('Hook-Konstanten ↔ HOOKS-REFERENCE.md', static function (): void {
    $doc = csRead(CS_DOC_HOOKS);
    T::same(1, preg_match('/^### Community \(4\.2\.0\)\n(.*?)(?=^### )/ms', $doc, $m), 'Abschnitt „Community (4.2.0)“ vorhanden');
    $section = $m[1] ?? '';
    preg_match_all('/^\| `(cms_community_[a-z_]+)` \| (Action|Filter) \| ([^|]*) \| /m', $section, $rows, PREG_SET_ORDER);
    $byHook = [];
    foreach ($rows as $row) {
        $byHook[$row[1]] = ['type' => $row[2], 'args' => $row[3]];
    }
    $constants = array_values((new ReflectionClass(Events::class))->getConstants());
    sort($constants);
    $documented = array_keys($byHook);
    sort($documented);
    T::same($constants, $documented, 'jede Events-Konstante hat genau eine Zeile im Abschnitt und umgekehrt');

    $signatures = [
        Events::TOPIC_CREATED => ['Action', ['int $topicId', 'int $forumId', 'int $userId', 'string $status', 'int $firstPostId']],
        Events::TOPIC_UPDATED => ['Action', ['int $topicId', 'int $forumId', 'int $actorId', 'array $changed']],
        Events::TOPIC_DELETED => ['Action', ['int $topicId', 'int $forumId', 'int $actorId', 'string $mode']],
        Events::POST_CREATED => ['Action', ['int $postId', 'int $topicId', 'int $userId', 'string $status']],
        Events::POST_UPDATED => ['Action', ['int $postId', 'int $topicId', 'int $editorId', 'int $revisionId']],
        Events::POST_DELETED => ['Action', ['int $postId', 'int $topicId', 'int $actorId', 'string $mode']],
        Events::CAN => ['Filter', ['AccessDecision $decision', 'int $userId', 'string $action', 'array $context']],
        Events::POST_APPROVED => ['Action', ['int $postId', 'int $topicId', 'int $moderatorId']],
        // Vertragsänderung eines bisher nie ausgelösten Hooks: den Grund liefert das Protokoll (Vertrag DESIGN-2b §3.3)
        Events::POST_REJECTED => ['Action', ['int $postId', 'int $topicId', 'int $moderatorId', 'int $logId']],
        Events::POST_HIDDEN => ['Action', ['int $postId', 'int $topicId', 'int $actorId', 'int $logId']],
        Events::POST_RESTORED => ['Action', ['int $postId', 'int $topicId', 'int $actorId', 'string $fromStatus']],
        Events::POST_MOVED => ['Action', ['int $postId', 'int $fromTopicId', 'int $toTopicId', 'int $actorId']],
        Events::TOPIC_APPROVED => ['Action', ['int $topicId', 'int $forumId', 'int $moderatorId', 'int $firstPostId']],
        Events::TOPIC_REJECTED => ['Action', ['int $topicId', 'int $forumId', 'int $moderatorId', 'int $logId']],
        Events::TOPIC_HIDDEN => ['Action', ['int $topicId', 'int $forumId', 'int $actorId', 'int $logId']],
        Events::TOPIC_RESTORED => ['Action', ['int $topicId', 'int $forumId', 'int $actorId', 'string $status']],
        Events::TOPIC_MOVED => ['Action', ['int $topicId', 'int $fromForumId', 'int $toForumId', 'int $actorId', 'int $stubTopicId']],
        Events::TOPIC_MERGED => ['Action', ['int $sourceTopicId', 'int $targetTopicId', 'int $actorId', 'int $logId']],
        Events::TOPIC_SPLIT => ['Action', ['int $sourceTopicId', 'int $newTopicId', 'int $actorId', 'int $logId']],
        Events::SOLUTION_MARKED => ['Action', ['int $topicId', 'int $postId', 'int $actorId']],
        Events::SOLUTION_UNMARKED => ['Action', ['int $topicId', 'int $postId', 'int $actorId']],
        Events::REPORT_CREATED => ['Action', ['int $reportId', 'string $targetType', 'int $targetId', 'int $reporterId']],
        Events::REPORT_RESOLVED => ['Action', ['int $reportId', 'string $status', 'int $moderatorId']],
        Events::USER_BANNED => ['Action', ['int $userId', 'int $banId', 'string $scope', 'int $actorId']],
        Events::USER_UNBANNED => ['Action', ['int $userId', 'int $banId', 'int $actorId']],
        Events::USER_WARNED => ['Action', ['int $userId', 'int $warningId', 'int $actorId']],
        Events::REACTION_CHANGED => ['Action', ['int $postId', 'int $userId', 'string $reaction', 'string $previous']],
        Events::SUBSCRIPTION_CHANGED => ['Action', ['int $userId', 'string $targetType', 'int $targetId', 'string $mode']],
        Events::RENDER_POST => ['Filter', ['string $html', 'int $postId']],
        Events::SPAM_CHECK => ['Filter', ['string $verdict', 'int $userId', 'string $action', 'array $input']],
        Events::NOTIFICATION_TYPES => ['Filter', ['array $types']],
    ];
    $signatureKeys = array_keys($signatures);
    sort($signatureKeys);
    T::same($constants, $signatureKeys, 'jede Konstante hat eine feste Signatur im Test (Vertrag DESIGN-2b §3.3)');
    foreach ($signatures as $hook => [$type, $args]) {
        $row = $byHook[$hook] ?? ['type' => '', 'args' => ''];
        T::same($type, $row['type'], $hook . ': Typ ' . $type);
        $documentedArgs = array_map(static fn (string $arg): string => trim($arg, '` '), explode('`, `', trim($row['args'])));
        T::same($args, $documentedArgs, $hook . ': Argumente laut Vertrag (Argumentspalte ohne Zusatz)');
        $constant = (string) array_search($hook, CS_EVENTS, true);
        $docblock = (string) (new ReflectionClassConstant(Events::class, $constant))->getDocComment();
        T::check(str_contains($docblock, implode(', ', $args)), 'Events::' . $constant . ': Docblock nennt die Signatur ' . implode(', ', $args));
    }

    // Haupttabelle: alle in 4.2.0 ausgelösten Hooks; „Vorgesehen“: nur die drei P2-Hooks
    $split = preg_split('/^Vorgesehen\b[^\n]*$/m', $section, 2);
    T::same(2, count($split), 'Abschnitt hat einen Absatz „Vorgesehen …“ mit eigener Tabelle');
    preg_match_all('/^\| `(cms_community_[a-z_]+)` \|/m', (string) ($split[0] ?? ''), $main);
    preg_match_all('/^\| `(cms_community_[a-z_]+)` \|/m', (string) ($split[1] ?? ''), $planned);
    T::same(CS_EVENTS_PLANNED, $planned[1], 'unter „Vorgesehen“ stehen nur TOPIC_MERGED, TOPIC_SPLIT und USER_WARNED');
    T::same(array_values(array_diff(array_values(CS_EVENTS), CS_EVENTS_PLANNED)), $main[1], 'Haupttabelle: alle übrigen Hooks in Registerreihenfolge');
    T::check(str_contains($section, 'Argumente sind IDs und Statuswerte, nie Inhalte. Ausnahme: der Filter `cms_community_spam_check`'), 'Kopfsatz nennt die Ausnahme cms_community_spam_check');
    T::check(!str_contains($section, 'noch ohne Konstante'), 'kein Absatz „geplant, aber noch ohne Konstante“ mehr (beide Filter haben Konstanten)');
    T::check(str_contains($section, 'nach dem Commit') && str_contains($section, 'user_role_changed') && str_contains($section, 'user_email_verified'), 'Abschnitt beschreibt Auslösung nach dem Commit und die Listener des Moduls');
    T::check(preg_match('/^## English \(summary\)\n\n[^\n]*cms_community_\*[^\n]*\n/m', $doc) === 1, 'englischer Kopf nennt die cms_community_*-Hooks');
});

T::run('Zeitwächter: keine Ortszeit und kein OFFSET in Services/Community/**', static function (): void {
    $bad = "SELECT NOW(), CURRENT_TIMESTAMP, CURDATE(), UTC_TIMESTAMP() FROM t WHERE x < ? LIMIT 5 OFFSET 10;\n\$d = date('Y-m-d H:i:s');";
    T::same(['NOW()', 'CURRENT_TIMESTAMP', 'CURDATE()', 'UTC_TIMESTAMP()', 'date(', 'OFFSET'], csTimeGuardHits($bad), 'Gegenprobe: ein schlechtes Beispiel trifft alle sechs Muster');
    foreach (['NOW()' => 'WHERE a < NOW()', 'CURRENT_TIMESTAMP' => 'DEFAULT CURRENT_TIMESTAMP', 'CURDATE()' => 'CURDATE()', 'UTC_TIMESTAMP()' => 'UTC_TIMESTAMP()', 'date(' => '$x = date("Y");', 'OFFSET' => 'LIMIT 1 offset 5'] as $name => $sample) {
        T::same([$name], csTimeGuardHits($sample), 'Gegenprobe: Muster ' . $name . ' schlägt einzeln an');
    }
    $good = "gmdate('Y-m-d H:i:s', \$ts); I18n::date(\$utc, 'd.m.'); \$this->date(1); function date(string \$x) {} update(1); validate(2); date_default_timezone_get(); getOffset();";
    T::same([], csTimeGuardHits($good), 'Gegenprobe: erlaubte Schreibweisen (gmdate, Methoden, update, validate) schlagen nicht an');

    $files = csPhpFiles(CS_ROOT);
    T::check(count($files) >= 11, 'Services/Community/** enthält mindestens die elf Dateien von Wellen 0 und 1 (' . count($files) . ' gefunden)');
    $offenders = [];
    foreach ($files as $file) {
        $hits = csTimeGuardHits(csRead($file));
        if ($hits !== []) {
            $offenders[] = substr($file, strlen(CS_ROOT)) . ' (' . implode(', ', $hits) . ')';
        }
    }
    T::same([], $offenders, 'keine verbotenen Zeitmuster in Services/Community/**');
});

T::run('Leser vorhanden: jeder Schlüssel in Settings::DEFINITIONS wird außerhalb von Settings.php gelesen', static function (): void {
    $topic = 'Service/TopicService.php';
    $post = 'Service/PostService.php';
    $access = 'Service/CommunityAccess.php';
    $trust = 'Service/BasicTrustPolicy.php';
    $render = 'Service/RenderService.php';
    $moderation = 'Service/ModerationService.php';
    $guard = 'Service/ContentGuard.php';
    // Schlüssel → [erwartete Dateien (Vertrag §3 und DESIGN-2b §3.1, Spalte Leser), Hilfsmethode, die den Schlüssel in Settings liest]
    $readers = [
        'base_path' => [['Support/Urls.php'], null],
        'guests_can_read' => [[$access], null],
        'require_verified_email' => [[$access], null],
        'topics_per_page' => [[$topic], null],
        'posts_per_page' => [[$post], null],
        'title_min' => [[$topic, $post, $moderation], null],
        'title_max' => [[$topic, $post, $moderation], null],
        'post_min' => [[$topic, $post, $render], null],
        'post_max' => [[$topic, $post, $render], null],
        'edit_window_minutes' => [[$access], null],
        'edit_window_minutes_trusted' => [[$access], null],
        'rate_post_seconds' => [[$access], null],
        'rate_topic_seconds' => [[$access], null],
        'new_member_approval_posts' => [[$access], null],
        'max_pending_per_user' => [[$access], null],
        'duplicate_window_seconds' => [[$topic, $post], null],
        'trust_level1_posts' => [[$trust], null],
        'trust_level2_posts' => [[$trust], null],
        'trust_limits' => [[$access], 'limitsForLevel'],
        'default_permissions' => [[$access], 'permissionDefaults'],
        'unread_horizon_days' => [['Service/ReadStateService.php'], null],
        'word_filter' => [[$guard], null],
        'use_core_antispam' => [[$guard], null],
        'spam_duplicate_hours' => [[$guard], null],
        'ip_hash_days' => [[$guard], null],
        'moved_stub_days' => [[$moderation], null],
        'moderation_reason_required' => [[$moderation], null],
        'report_hide_threshold' => [['Service/ReportService.php'], null],
        'moderator_ban_max_days' => [['Service/BanService.php'], null],
        'notify_mail' => [['Service/NotificationDispatcher.php'], null],
        'reaction_set' => [['Service/ReactionService.php'], null],
        'attachment_types' => [['Service/AttachmentService.php'], null],
        'attachment_max_kb' => [['Service/AttachmentService.php'], null],
        'captcha_mode' => [['Service/CaptchaGate.php'], null],
        'captcha_provider' => [['Service/CaptchaGate.php'], null],
    ];
    T::same(array_keys(Settings::DEFINITIONS), array_keys($readers), 'die Lesertabelle deckt genau die Schlüssel aus DEFINITIONS ab');

    foreach ($readers as $key => [$expectedFiles, $helper]) {
        foreach ($expectedFiles as $relative) {
            $path = CS_ROOT . $relative;
            // Seit J2 Pflicht: alle Dienste aus W2 existieren.
            if (!is_file($path)) {
                T::check(false, 'Leser von ' . $key . ': ' . $relative . ' vorhanden');
                continue;
            }
            $tokens = csCodeTokens(csRead($path));
            $reads = isset($tokens['strings'][$key]) || ($helper !== null && isset($tokens['names'][$helper]));
            T::check($reads, $key . ': ' . $relative . ' liest den Schlüssel' . ($helper !== null ? ' (oder ruft ' . $helper . '())' : ''));
        }
    }

    // Gegenprobe der Erkennung: Kommentare zählen nicht, Literale und Hilfsmethoden schon.
    $sample = csCodeTokens("<?php\n// 'topics_per_page'\n/** 'posts_per_page' */\n\$s->int('title_min'); \$s->limitsForLevel(1);");
    T::same([false, false, true, true], [isset($sample['strings']['topics_per_page']), isset($sample['strings']['posts_per_page']), isset($sample['strings']['title_min']), isset($sample['names']['limitsForLevel'])], 'Gegenprobe: Schlüssel in Kommentaren gelten nicht als Leser');
});

T::run('Wirkungstest vorhanden: Abschnitt „Wirkung: <schlüssel>“ je Einstellung', static function (): void {
    $suites = [
        'base_path' => 'community-settings',
        'guests_can_read' => 'community-access', 'require_verified_email' => 'community-access', 'edit_window_minutes' => 'community-access',
        'edit_window_minutes_trusted' => 'community-access', 'rate_post_seconds' => 'community-access', 'rate_topic_seconds' => 'community-access',
        'new_member_approval_posts' => 'community-access', 'max_pending_per_user' => 'community-access', 'trust_level1_posts' => 'community-access',
        'trust_level2_posts' => 'community-access', 'trust_limits' => 'community-access', 'default_permissions' => 'community-access',
        'topics_per_page' => 'community-content', 'posts_per_page' => 'community-content', 'title_min' => 'community-content', 'title_max' => 'community-content',
        'post_min' => 'community-content', 'post_max' => 'community-content', 'duplicate_window_seconds' => 'community-content', 'unread_horizon_days' => 'community-content',
        // PR #2b (Vertrag DESIGN-2b §3.1, Spalte Wirkungstest)
        'word_filter' => 'community-spam', 'use_core_antispam' => 'community-spam', 'spam_duplicate_hours' => 'community-spam', 'ip_hash_days' => 'community-spam',
        'moved_stub_days' => 'community-moderation', 'moderation_reason_required' => 'community-moderation',
        'report_hide_threshold' => 'community-sanctions', 'moderator_ban_max_days' => 'community-sanctions',
        'notify_mail' => 'community-notify', 'reaction_set' => 'community-notify',
        'attachment_types' => 'community-uploads', 'attachment_max_kb' => 'community-uploads',
        // PR #5 (Captcha)
        'captcha_mode' => 'community-captcha', 'captcha_provider' => 'community-captcha',
    ];
    $tableKeys = array_keys($suites);
    $definedKeys = array_keys(Settings::DEFINITIONS);
    sort($tableKeys);
    sort($definedKeys);
    T::same($definedKeys, $tableKeys, 'die Tabelle deckt genau die Schlüssel aus DEFINITIONS ab');

    foreach ($suites as $key => $suite) {
        $path = dirname(__DIR__) . '/' . $suite . '/run.php';
        // Seit J2 Pflicht: alle Suiten aus W2 existieren.
        if (!is_file($path)) {
            T::check(false, 'Wirkungstest von ' . $key . ': TESTS/' . $suite . '/run.php vorhanden');
            continue;
        }
        $found = preg_match('/^[^\n]*Wirkung:[^\n]*\b' . preg_quote($key, '/') . '\b/m', csRead($path)) === 1;
        T::check($found, $key . ': TESTS/' . $suite . '/run.php hat einen Abschnitt „Wirkung: ' . $key . '“');
    }

    // Gegenprobe der Erkennung: ein Schlüssel mit gleichem Anfang zählt nicht.
    $sample = "T::run('Wirkung: edit_window_minutes_trusted', fn() => 1);\nT::run('Anderes', fn() => 'edit_window_minutes');";
    T::same(
        [false, true],
        [
            preg_match('/^[^\n]*Wirkung:[^\n]*\bedit_window_minutes\b/m', $sample) === 1,
            preg_match('/^[^\n]*Wirkung:[^\n]*\bedit_window_minutes_trusted\b/m', $sample) === 1,
        ],
        'Gegenprobe: „Wirkung: edit_window_minutes_trusted“ gilt nicht für edit_window_minutes'
    );
});

T::finish();
