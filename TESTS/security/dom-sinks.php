<?php
/**
 * Audit 4.2.0 AP-04 (04.5): DOM-Senken im eigenen Browser-Code, ohne Browser (Quelltextprüfung).
 * Text, der aus der Seite gelesen wird, darf nicht als HTML zurückgeschrieben werden.
 * Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

T::run('Audit 4.2.0 AP-04: admin.js setzt Text aus Tabellenzellen nicht als HTML', static function (): void {
    $js = (string) file_get_contents(ABSPATH . 'assets/js/admin.js');
    $start = strpos($js, 'function initGlobalEmptyTablePattern()');
    $end = $start === false ? false : strpos($js, 'function initAdminSearchFallbacks()', $start);
    T::check($start !== false && $end !== false, 'initGlobalEmptyTablePattern() gefunden');
    $body = $start !== false && $end !== false ? substr($js, $start, $end - $start) : '';

    // Die Beschriftung stammt aus dem Text der Zelle (`textContent`, also entmaskiert) und kann `<`/`>` aus Suchbegriffen enthalten.
    T::check(preg_match('/innerHTML\s*=[^;]*\bentity\b/s', $body) !== 1, '`entity` fließt nicht in einen innerHTML-Ausdruck');
    T::check(str_contains($body, "emptyTitle.textContent = 'Keine ' + entity + ' vorhanden.'"), 'Beschriftung wird per textContent gesetzt');
});
