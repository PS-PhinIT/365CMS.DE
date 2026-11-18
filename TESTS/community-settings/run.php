<?php
/**
 * Community-Fundament, Teil Einstellungen und Support (4.2.0, PR #2a): Definitionen und Normalisierung von
 * `Support\Settings`, Lesen/Speichern über Closures und über `SettingsService`, `Slugger`, `Urls`, `Cursor`, `Page`,
 * `I18n`, `Events`, `CommunityModule`, `CommunityHooks`, Codes ↔ Sprachschlüssel (de/en zeilenparallel), Modul-Eintrag,
 * Registry-Eintrag, Hook-Konstanten ↔ HOOKS-REFERENCE.md, Zeitwächter über `Services/Community/**` und die Prüfung, dass
 * jede Einstellung einen Leser und einen Wirkungstest hat (fehlende Dateien der Dienste: Hinweis statt Fehler).
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
    ];
    T::same(array_keys($expected), array_keys(Settings::DEFINITIONS), 'Schlüsselliste und Reihenfolge stimmen mit Vertrag §3 überein');

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
    T::same(['posts_per_day', 'topics_per_hour'], Settings::LIMIT_FIELDS, 'Grenzen je Stufe in #2a: Beiträge/Tag und Themen/Stunde');
    T::same(
        [0 => [5, 1], 1 => [30, 3], 2 => [100, 5], 3 => [300, 10], 4 => [300, 10]],
        array_map(static fn (array $row): array => [$row['posts_per_day'], $row['topics_per_hour']], $defaults['trust_limits']),
        'trust_limits: Standards je Stufe (Beiträge/Tag 5/30/100/300/300, Themen/Stunde 1/3/5/10/10)'
    );
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
    T::same(['posts_per_day' => 7, 'topics_per_hour' => 5], $partial[2], 'trust_limits: fehlendes Feld wird aus dem Standard aufgefüllt');
    T::same($defaults['trust_limits'][3], $partial[3], 'trust_limits: nicht genannte Stufe bleibt Standard');
    T::same([0, 1, 2, 3, 4], array_keys($partial), 'trust_limits: unbekannte Stufe 9 entfällt, Stufen 0 bis 4 in Reihenfolge');

    $clamped = $limits(['1' => ['posts_per_day' => '-5', 'topics_per_hour' => 'abc', 'links' => 4], 3 => ['posts_per_day' => 100001, 'topics_per_hour' => 0]]);
    T::same(['posts_per_day' => 0, 'topics_per_hour' => 3], $clamped[1], 'trust_limits: negativ → 0, Text → Standard, unbekanntes Feld entfällt');
    T::same(['posts_per_day' => 100000, 'topics_per_hour' => 0], $clamped[3], 'trust_limits: Maximum 100000, 0 = unbegrenzt bleibt');
    T::same(['posts_per_day' => 5, 'topics_per_hour' => 1], $limits([0 => 5])[0], 'trust_limits: Zeile kein Array → Standard');

    $fromJson = $limits('{"3":{"posts_per_day":12}}');
    T::same(['posts_per_day' => 12, 'topics_per_hour' => 10], $fromJson[3], 'trust_limits: JSON-Text wird gelesen');
    $fromPrefixed = $limits('json:{"3":{"topics_per_hour":2}}');
    T::same(['posts_per_day' => 300, 'topics_per_hour' => 2], $fromPrefixed[3], 'trust_limits: JSON-Text mit json:-Präfix wird gelesen');
    $fromList = $limits([['posts_per_day' => 1, 'topics_per_hour' => 2]]);
    T::same(['posts_per_day' => 1, 'topics_per_hour' => 2], $fromList[0], 'trust_limits: als JSON-Liste gespeicherte Stufen (Index 0 bis 4)');

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
                default => is_array($value),
            };
            if (!$valid) {
                $violations[] = $key . '#' . $index . ': ungültiger Wert ' . T::export($value);
            }
        }
    }
    T::same([], $violations, 'Fuzz: jeder Schlüssel × 27 Eingaben ergibt gültige, idempotente Werte');
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
    T::same(['posts_per_day' => 7, 'topics_per_hour' => 5], $stored[2], 'Strukturen werden vollständig aufgefüllt gespeichert');
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
    $settings = Settings::fromArray(['trust_limits' => [1 => ['posts_per_day' => 11, 'topics_per_hour' => 2], 4 => ['posts_per_day' => 0]]]);
    T::same(['posts_per_day' => 5, 'topics_per_hour' => 1], $settings->limitsForLevel(0), 'limitsForLevel(0): Standard');
    T::same(['posts_per_day' => 11, 'topics_per_hour' => 2], $settings->limitsForLevel(1), 'limitsForLevel(1): gespeicherter Wert');
    T::same(['posts_per_day' => 0, 'topics_per_hour' => 10], $settings->limitsForLevel(4), 'limitsForLevel(4): 0 = unbegrenzt, Rest Standard');
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

T::run('Events', static function (): void {
    $fired = [
        'TOPIC_CREATED' => 'cms_community_topic_created', 'TOPIC_UPDATED' => 'cms_community_topic_updated', 'TOPIC_DELETED' => 'cms_community_topic_deleted',
        'POST_CREATED' => 'cms_community_post_created', 'POST_UPDATED' => 'cms_community_post_updated', 'POST_DELETED' => 'cms_community_post_deleted',
        'CAN' => 'cms_community_can',
    ];
    foreach ($fired as $constant => $name) {
        T::same($name, constant(Events::class . '::' . $constant), 'Events::' . $constant . ' = ' . $name);
    }
    $planned = ['cms_community_post_approved', 'cms_community_post_rejected', 'cms_community_topic_moved', 'cms_community_topic_merged', 'cms_community_render_post',
        'cms_community_report_created', 'cms_community_report_resolved', 'cms_community_user_warned', 'cms_community_user_banned', 'cms_community_user_unbanned', 'cms_community_solution_marked'];
    $values = array_values((new ReflectionClass(Events::class))->getConstants());
    T::same(array_merge(array_values($fired), $planned), $values, 'Events enthält genau die Hook-Namen aus Vertrag §5 (ausgelöste, dann für #2b vorgesehene)');
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
    T::same(['Community', [], [], [], null, null], [$entry['label'] ?? null, $entry['dependencies'] ?? null, $entry['admin_pages'] ?? null, $entry['admin_labels'] ?? null, $entry['sidebar_group'] ?? null, $entry['legacy_setting'] ?? null], 'Bezeichnung; keine Abhängigkeiten, Admin-Seiten oder Legacy-Einstellung');
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
    T::check(!Hooks::hasAction('cms_cron_daily') && !Hooks::hasAction('register_routes') && !Hooks::hasAction('dsgvo_export_data'), 'in #2a keine Routen-, Cron- oder DSGVO-Hooks');

    $hooksSource = csRead(ABSPATH . 'core/Services/FeatureHooks/CommunityHooks.php');
    T::check(
        (int) strpos($hooksSource, 'CommunityModule::isEnabled()') > 0 && strpos($hooksSource, 'CommunityModule::isEnabled()') < strpos($hooksSource, 'Hooks::addAction'),
        'Quelltext: Modul-Gate steht vor der ersten Hook-Anmeldung'
    );
    T::check(str_contains($hooksSource, 'class_exists(CommunityAccess::class)') && str_contains($hooksSource, 'CommunityAccess::instance()->forgetUser($userId)'), 'Quelltext: forgetUser() nur bei vorhandener Klasse');
    T::same(2, substr_count($hooksSource, 'Hooks::addAction('), 'Quelltext: genau zwei Listener');
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
    $communityKeys = array_values(array_filter(array_column($de, 0), static fn (string $key): bool => str_starts_with($key, 'community.')));
    T::same($expected, $communityKeys, 'jeder Code hat genau einen Schlüssel (Reihenfolge ACCESS_REASONS, dann ERRORS), keine überzähligen community.*-Schlüssel');
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
        Events::POST_REJECTED => ['Action', ['int $postId', 'int $topicId', 'int $moderatorId', 'string $reason']],
        Events::RENDER_POST => ['Filter', ['string $html', 'int $postId']],
    ];
    foreach ($signatures as $hook => [$type, $args]) {
        $row = $byHook[$hook] ?? ['type' => '', 'args' => ''];
        T::same($type, $row['type'], $hook . ': Typ ' . $type);
        $documentedArgs = array_map(static fn (string $arg): string => trim($arg, '` '), explode('`, `', trim($row['args'])));
        T::same($args, $documentedArgs, $hook . ': Argumente laut Vertrag §5');
    }
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
    // Schlüssel → [erwartete Dateien (Vertrag §3, Spalte Leser), Hilfsmethode, die den Schlüssel in Settings liest]
    $readers = [
        'base_path' => [['Support/Urls.php'], null],
        'guests_can_read' => [[$access], null],
        'require_verified_email' => [[$access], null],
        'topics_per_page' => [[$topic], null],
        'posts_per_page' => [[$post], null],
        'title_min' => [[$topic, $post], null],
        'title_max' => [[$topic, $post], null],
        'post_min' => [[$topic, $post], null],
        'post_max' => [[$topic, $post], null],
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
    ];
    T::same(array_keys(Settings::DEFINITIONS), array_keys($readers), 'die Lesertabelle deckt genau die Schlüssel aus DEFINITIONS ab');

    foreach ($readers as $key => [$expectedFiles, $helper]) {
        foreach ($expectedFiles as $relative) {
            $path = CS_ROOT . $relative;
            if (!is_file($path)) {
                T::info('folgt in Welle 2: Leser von ' . $key . ' (' . $relative . ' fehlt noch)');
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
    ];
    $tableKeys = array_keys($suites);
    $definedKeys = array_keys(Settings::DEFINITIONS);
    sort($tableKeys);
    sort($definedKeys);
    T::same($definedKeys, $tableKeys, 'die Tabelle deckt genau die Schlüssel aus DEFINITIONS ab');

    foreach ($suites as $key => $suite) {
        $path = dirname(__DIR__) . '/' . $suite . '/run.php';
        if (!is_file($path)) {
            T::info('folgt in Welle 2: Wirkungstest von ' . $key . ' (TESTS/' . $suite . '/run.php fehlt noch)');
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
