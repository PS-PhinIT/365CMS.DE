<?php
/**
 * Minimaler Test-Runner für die Suiten unter TESTS/ (ohne PHPUnit, ohne Datenbank).
 *
 * Nutzung in einer Suite:
 *   require_once dirname(__DIR__) . '/lib/TestRunner.php';
 *   T::$verbose = in_array('-v', $argv ?? [], true);
 *   T::run('Abschnitt', static function (): void { T::same(1, 1, 'Beschreibung'); });
 *   T::finish();
 */

declare(strict_types=1);

final class T
{
    public static bool $verbose = false;
    private static int $passed = 0;
    /** @var list<string> */
    private static array $failures = [];
    /** @var list<string> */
    private static array $infos = [];
    private static string $section = '';

    public static function run(string $title, callable $test): void
    {
        self::$section = $title;
        echo PHP_EOL . '== ' . $title . PHP_EOL;
        try {
            $test();
        } catch (\Throwable $e) {
            self::check(false, 'Ausnahme ' . $e::class . ': ' . $e->getMessage(), $e->getFile() . ':' . $e->getLine());
        }
    }

    public static function check(bool $condition, string $message, string $detail = ''): bool
    {
        if ($condition) {
            self::$passed++;
            if (self::$verbose) {
                echo '  ok    ' . $message . PHP_EOL;
            }

            return true;
        }

        self::$failures[] = '[' . self::$section . '] ' . $message . ($detail !== '' ? ' – ' . $detail : '');
        echo '  FAIL  ' . $message . ($detail !== '' ? PHP_EOL . '        ' . $detail : '') . PHP_EOL;

        return false;
    }

    public static function same(mixed $expected, mixed $actual, string $message): bool
    {
        $equal = $expected === $actual;

        return self::check($equal, $message, $equal ? '' : 'erwartet ' . self::export($expected) . ' | ist ' . self::export($actual));
    }

    public static function info(string $message): void
    {
        self::$infos[] = $message;
        if (self::$verbose) {
            echo '  info  ' . $message . PHP_EOL;
        }
    }

    public static function export(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return var_export($value, true);
        }

        return strlen($json) > 700 ? substr($json, 0, 700) . '…' : $json;
    }

    /**
     * PHP-Meldungen (Deprecated, Warning, Notice) aus eigenem Code gelten als Fehlschlag – so meldet die CI unter
     * PHP 8.4/8.5 jede neue Veraltung (Release-Prüfung 4.1.0, PR #4). Aus gebündelten Fremdbibliotheken
     * (vendor/, assets/ außer assets/js/) nur als Hinweis. Mit @ unterdrückte Meldungen bleiben unterdrückt.
     */
    public static function installErrorGuard(): void
    {
        set_error_handler(static function (int $errno, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $errno) === 0) {
                return false;
            }
            $kind = match ($errno) {
                E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
                E_WARNING, E_USER_WARNING => 'Warning',
                E_NOTICE, E_USER_NOTICE => 'Notice',
                default => null,
            };
            if ($kind === null) {
                return false;
            }
            $where = $file . ':' . $line;
            $thirdParty = str_contains($file, '/vendor/') || preg_match('#/CMS/assets/(?!js/)#', $file) === 1;
            if ($thirdParty) {
                self::info('PHP ' . $kind . ' (Fremdbibliothek): ' . $message . ' – ' . $where);
            } else {
                self::check(false, 'PHP ' . $kind . ': ' . $message, $where);
            }

            return true;
        });
    }

    public static function finish(): never
    {
        echo PHP_EOL . str_repeat('─', 72) . PHP_EOL;
        echo 'Bestanden: ' . self::$passed . ' | Fehlgeschlagen: ' . count(self::$failures) . ' | Hinweise: ' . count(self::$infos)
            . (self::$verbose || self::$infos === [] ? '' : ' (mit -v anzeigen)') . PHP_EOL;
        if (self::$failures !== []) {
            echo PHP_EOL . 'Fehler:' . PHP_EOL;
            foreach (self::$failures as $failure) {
                echo '  - ' . $failure . PHP_EOL;
            }
            exit(1);
        }
        exit(0);
    }
}

T::installErrorGuard();
