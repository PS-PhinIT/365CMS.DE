<?php
/**
 * Ergänzender Katalog der Mutanten für die Gegenprobe: CMS\Hooks (Filterkette getCallbacks, isolierte Verbraucher doActionIsolated), Nachlauf zu PR #2a.
 *
 * Gleiches Format wie `catalog.php` (der Treiber lädt `catalog.php` und alle `catalog-*.php`, Namen eindeutig):
 *
 *   Name => [Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env]
 *
 * Enthält die Mutanten r1 bis r36 der unabhängigen Prüfung (Befunde B4, B5, B19: r13, r14, r15, r25, r27 und r36 überlebten) mit den
 * Namen ohne Nummer, dazu je einen Mutanten für jede Regel der Nachlauf-Änderungen (B1/B8/B11 Filterkette, B2/B12 Ziel,
 * B3/B29 Kontingent, B9/B18/B31 Abfragen der Lesepfade, B13 fail-closed, B20 Verbraucher einzeln, Rechte-Subjekte).
 * Jeder Eintrag muss von der Suite `community-access` erkannt werden (Exit 1); ein Mutant, den keine Prüfung erkennt, ist eine
 * Lücke der Suite.
 *
 * Erzeugt aus einer Liste; Änderungen am Quelltext, die einen Suchtext treffen, wie bei `catalog.php` in der betroffenen Zeile nachziehen.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

return [
    'hooks-callbacks-wrong-order' => [
        'core/Hooks.php',
        '        ksort($registry);' . "\n" . '        $callbacks = [];',
        '        krsort($registry);' . "\n" . '        $callbacks = [];',
        'community-access', false, 'Hooks::getCallbacks(): Priorität aufsteigend (die Filterkette läuft in der Ausführungsreihenfolge)', false, [],
    ],
    'hooks-callbacks-mixed-types' => [
        'core/Hooks.php',
        '\'action\' => self::$actions[$tag] ?? [],',
        '\'action\' => self::$filters[$tag] ?? [],',
        'community-access', false, 'Hooks::getCallbacks(): Aktionen und Filter sind getrennte Listen', false, [],
    ],
    'hooks-isolated-rethrows' => [
        'core/Hooks.php',
        '                if ($onError === null) {' . "\n" . '                    continue;' . "\n" . '                }',
        '                throw $e;',
        'community-access', false, 'Hooks::doActionIsolated(): ein werfender Verbraucher hält die übrigen nicht auf (B20)', true, [],
    ],
    'hooks-isolated-handler-failure-escapes' => [
        'core/Hooks.php',
        '} catch (\\Throwable) {' . "\n" . '                    // Die Fehlerbehandlung selbst darf die übrigen Callbacks nicht verhindern.' . "\n" . '                }',
        '} catch (\\ArgumentCountError) {' . "\n" . '                }',
        'community-access', false, 'auch eine werfende Fehlerbehandlung hält die übrigen Verbraucher nicht auf (B20)', false, [],
    ],
    'hooks-isolated-not-counted' => [
        'core/Hooks.php',
        '        self::$firedActions[$tag] = (self::$firedActions[$tag] ?? 0) + 1;' . "\n" . '' . "\n" . '        foreach (self::getCallbacks($tag, \'action\') as $callback) {',
        '        foreach (self::getCallbacks($tag, \'action\') as $callback) {',
        'community-access', false, 'doActionIsolated() zählt für didAction() wie doAction()', false, [],
    ],
    'hooks-isolated-ignores-once-per-request' => [
        'core/Hooks.php',
        '    public static function doActionIsolated(string $tag, ?callable $onError, ...$args): void' . "\n" . '    {' . "\n" . '        if (in_array($tag, self::ONCE_PER_REQUEST_ACTIONS, true) && isset(self::$firedActions[$tag])) {' . "\n" . '            return;' . "\n" . '        }' . "\n" . '',
        '    public static function doActionIsolated(string $tag, ?callable $onError, ...$args): void' . "\n" . '    {' . "\n" . '',
        'community-access', false, 'doActionIsolated() beachtet die einmaligen Dokument-Abschluss-Hooks (before_footer, body_end)', false, [],
    ],
];
