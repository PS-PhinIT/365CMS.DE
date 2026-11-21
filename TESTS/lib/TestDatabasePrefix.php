<?php
/**
 * Präfix der Test-Datenbanken (PR #2b, Welle 0): Mit `CMS_TEST_DB_PREFIX=<präfix>` beginnen alle Datenbanken, die Suiten anlegen,
 * mit diesem Präfix statt mit dem Standard der Suite (`pr2a_` in `MariaDbTestDatabase`, `cmsup` in `schema-upgrade`, …). So
 * laufen mehrere Umsetzer gleichzeitig gegen denselben Server, und jeder räumt nur seine eigenen Datenbanken auf.
 *
 * Erlaubt sind 1 bis 12 Zeichen aus `[a-z0-9_]`; ein anderer Wert beendet den Prozess mit Meldung und Exit 2 (kein stiller
 * Rückfall auf den Standard). Ohne Variable oder mit leerem Wert gilt der Standard der Suite.
 *
 * Ohne Abhängigkeiten (auch `schema-upgrade` und `schema-v27/dump-golden.php` laden die Datei ohne Autoloader):
 *
 *   require_once dirname(__DIR__) . '/lib/TestDatabasePrefix.php';
 *   $name = TestDatabasePrefix::resolve('cmsup') . getmypid() . '_fresh';
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

final class TestDatabasePrefix
{
    /** Umgebungsvariable mit dem Präfix. */
    public const ENV = 'CMS_TEST_DB_PREFIX';

    /** Erlaubte Präfixe: 1 bis 12 Zeichen aus Kleinbuchstaben, Ziffern und Unterstrich. */
    public const PATTERN = '/^[a-z0-9_]{1,12}$/D';

    /** Exit-Code bei ungültigem Präfix. */
    public const EXIT_INVALID = 2;

    /** Ist `$prefix` als Präfix erlaubt? */
    public static function valid(string $prefix): bool
    {
        return preg_match(self::PATTERN, $prefix) === 1;
    }

    /**
     * Wirksames Präfix: der Wert von `CMS_TEST_DB_PREFIX`, ohne Variable `$default`. Ein ungültiger Wert beendet den Prozess
     * (Meldung auf STDERR, Exit 2).
     */
    public static function resolve(string $default): string
    {
        $value = getenv(self::ENV);
        if ($value === false || $value === '') {
            return $default;
        }
        if (!self::valid($value)) {
            fwrite(STDERR, self::ENV . ' ungültig: ' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                . ' (erlaubt: 1 bis 12 Zeichen aus [a-z0-9_])' . PHP_EOL);
            exit(self::EXIT_INVALID);
        }

        return $value;
    }
}
