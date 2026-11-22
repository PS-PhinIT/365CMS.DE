<?php
/**
 * Admin „SEO → Weiterleitungen“: höchstens 250 Regeln je Seite (Blättern per Link), keine Selbstaktualisierung im Hintergrund,
 * stabile Reihenfolge (ein Treffer der Weiterleitung zählt nicht als Bearbeitung). DB-frei: reine Seitenrechnung, die Vorlage mit
 * Testdaten und Quelltextprüfungen von Dienst, Eingang und Skript.
 *
 *   php TESTS/admin-redirects/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-admin-redirects-' . getmypid() . '/');
date_default_timezone_set('Europe/Berlin');
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once dirname(__DIR__) . '/lib/TestRunner.php';

use CMS\Services\RedirectService;

function read(string $relative): string
{
    return (string) file_get_contents(ABSPATH . $relative);
}

/** Quelltext ohne Kommentare (Zeilen bleiben erhalten). */
function code(string $source): string
{
    return (string) preg_replace_callback('~/\*.*?\*/|(?<![:\'"])//[^\n]*~s', static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $source);
}

/**
 * Vorlage mit Testdaten rendern.
 *
 * @param array<string, mixed> $pagination
 * @param list<array<string, mixed>> $rows
 */
function render(array $rows, array $pagination): string
{
    if (!defined('CMS_ADMIN_SEO_VIEW')) {
        define('CMS_ADMIN_SEO_VIEW', true);
    }
    $data = [
        'redirects' => $rows,
        'stats' => ['redirects_total' => $pagination['total'], 'redirects_active' => $pagination['total'], 'not_found_total' => 0, 'not_found_hits' => 0],
        'pagination' => $pagination,
        'targets' => ['pages' => [], 'posts' => [], 'hubs' => []],
        'sites' => [['value' => '', 'label' => 'Global / alle Sites']],
    ];
    $alert = null;
    $csrfToken = 'testtoken';
    $activePage = 'redirect-manager';
    $render = static function () use ($data, $alert, $csrfToken, $activePage): string {
        ob_start();
        try {
            require ABSPATH . 'admin/views/seo/redirects.php';
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    };

    return $render();
}

/** @return list<array<string, mixed>> */
function rows(int $count, int $start = 1): array
{
    $rows = [];
    for ($i = $start; $i < $start + $count; $i++) {
        $rows[] = ['id' => $i, 'source_path' => '/alt-' . $i, 'site_scope' => '', 'site_scope_label' => 'Global / alle Sites', 'target_url' => '/neu-' . $i, 'redirect_type' => 301, 'hits' => $i, 'is_active' => 1, 'notes' => ''];
    }

    return $rows;
}

T::run('Seitenrechnung: höchstens 250 je Seite, ungültige und zu große Seiten werden begrenzt', static function (): void {
    T::same(250, RedirectService::REDIRECTS_PER_PAGE, 'Standard: 250 Regeln je Seite');
    T::same(['page' => 1, 'pages' => 1, 'per_page' => 250, 'total' => 0, 'offset' => 0, 'from' => 0, 'to' => 0], RedirectService::paginate(0, 1), 'keine Regeln: eine leere Seite');
    T::same([1, 1, 0, 250], [RedirectService::paginate(250, 1)['pages'], RedirectService::paginate(250, 1)['page'], RedirectService::paginate(250, 1)['offset'], RedirectService::paginate(250, 1)['to']], '250 Regeln: eine Seite');
    $second = RedirectService::paginate(251, 2);
    T::same([2, 2, 250, 251, 251], [$second['page'], $second['pages'], $second['offset'], $second['from'], $second['to']], '251 Regeln: die zweite Seite hat genau eine (Nummer 251)');
    T::same(2, RedirectService::paginate(251, 99)['page'], 'zu große Seite: die letzte');
    T::same(1, RedirectService::paginate(251, 0)['page'], 'Seite 0: die erste');
    T::same(1, RedirectService::paginate(251, -5)['page'], 'negative Seite: die erste');
    T::same([4, 750, 751, 1000], [RedirectService::paginate(1000, 4)['pages'], RedirectService::paginate(1000, 4)['offset'], RedirectService::paginate(1000, 4)['from'], RedirectService::paginate(1000, 4)['to']], '1000 Regeln: vier Seiten, Seite 4 beginnt bei 751');
    T::same(1, RedirectService::paginate(10, 1, 0)['per_page'], 'je Seite mindestens 1');
    T::same(0, RedirectService::paginate(-3, 1)['total'], 'negative Anzahl: 0');
});

T::run('Vorlage: Bereich, Blätterleiste, Rückkehrseite der Zeilenaktionen', static function (): void {
    $first = render(rows(3), ['page' => 1, 'pages' => 3, 'per_page' => 250, 'total' => 600, 'offset' => 0, 'from' => 1, 'to' => 250]);
    T::check(str_contains($first, 'Einträge 1–250 von 600'), 'Seite 1: Bereich „Einträge 1–250 von 600“');
    T::check(str_contains($first, 'Seite 1 von 3') && str_contains($first, 'höchstens 250 je Seite'), 'Seite 1: Seitenzahl und Grenze stehen da');
    T::check(str_contains($first, 'href="/admin/redirect-manager?seite=2"') && str_contains($first, 'rel="next"'), 'Seite 1: „Weiter“ führt auf ?seite=2');
    T::check(preg_match('~page-item disabled">\s*<a class="page-link" href="/admin/redirect-manager"[^>]*aria-disabled="true"[^>]*rel="prev"~', $first) === 1, 'Seite 1: „Zurück“ ist gesperrt');
    T::same(3, substr_count($first, 'name="return_page" value="1"') / 2, 'Seite 1: Umschalten und Löschen merken die Seite (je Zeile zwei Formulare)');

    $middle = render(rows(3, 251), ['page' => 2, 'pages' => 3, 'per_page' => 250, 'total' => 600, 'offset' => 250, 'from' => 251, 'to' => 500]);
    T::check(str_contains($middle, 'Einträge 251–500 von 600'), 'Seite 2: Bereich 251–500');
    T::check(str_contains($middle, 'href="/admin/redirect-manager"') && str_contains($middle, 'rel="prev"') && str_contains($middle, 'href="/admin/redirect-manager?seite=3"'), 'Seite 2: „Zurück“ führt auf die erste Seite (ohne Query), „Weiter“ auf Seite 3');
    T::check(str_contains($middle, 'name="return_page" value="2"'), 'Seite 2: die Formulare tragen return_page=2');

    $last = render(rows(1, 501), ['page' => 3, 'pages' => 3, 'per_page' => 250, 'total' => 501, 'offset' => 500, 'from' => 501, 'to' => 501]);
    T::check(preg_match('~page-item disabled">\s*<a class="page-link" href="/admin/redirect-manager\?seite=3"[^>]*rel="next"~', $last) === 1, 'letzte Seite: „Weiter“ ist gesperrt');

    $single = render(rows(2), ['page' => 1, 'pages' => 1, 'per_page' => 250, 'total' => 2, 'offset' => 0, 'from' => 1, 'to' => 2]);
    T::check(str_contains($single, 'Einträge 1–2 von 2') && !str_contains($single, 'aria-label="Seiten der Weiterleitungen"'), 'eine Seite: Bereich, aber keine Blätterleiste');

    $empty = render([], ['page' => 1, 'pages' => 1, 'per_page' => 250, 'total' => 0, 'offset' => 0, 'from' => 0, 'to' => 0]);
    T::check(str_contains($empty, 'Noch keine Weiterleitungen angelegt.') && !str_contains($empty, 'Einträge 0'), 'ohne Regeln: Hinweis statt Bereich');
    T::same(0, substr_count($empty, 'js-edit-redirect'), 'ohne Regeln: keine Zeilenaktionen');
});

T::run('Keine Selbstaktualisierung: weder Zeitgeber noch Nachladen noch Meta-Refresh auf der Seite', static function (): void {
    $view = code(read('admin/views/seo/redirects.php'));
    $script = code(read('assets/js/admin-seo-redirects.js'));
    $entry = code(read('admin/redirect-manager.php'));
    foreach (['setInterval', 'location.reload', 'EventSource', 'fetch(', 'XMLHttpRequest', 'WebSocket', 'requestAnimationFrame', 'http-equiv', 'Refresh:'] as $needle) {
        T::check(!str_contains($view . $script . $entry, $needle), 'Vorlage, Skript und Eingang enthalten kein „' . $needle . '“');
    }
    T::same(2, substr_count($script, 'setTimeout('), 'das Skript benutzt setTimeout nur für den Fokus der Eingabefelder (zwei Stellen)');
    T::check(preg_match_all('~setTimeout\(\(\) => sourceField\.focus\(\), \d+\)~', $script) === 2, 'beide setTimeout-Aufrufe setzen nur den Fokus');
    T::check(!str_contains($view, '<meta'), 'die Vorlage setzt keine Meta-Angabe');
    T::check(preg_match('~header\(\s*[\'"]Refresh~i', $entry) === 0, 'der Eingang sendet keinen Refresh-Header');
});

T::run('Dienst und Eingang (Quelltext): Seite per LIMIT/OFFSET, keine 404-Zeilen, stabile Reihenfolge, Trefferzähler ohne updated_at', static function (): void {
    $service = code(read('core/Services/RedirectService.php'));
    $start = strpos($service, 'public function getRedirectManagerData');
    $end = strpos($service, 'public function getNotFoundMonitorData');
    $body = substr($service, (int) $start, (int) $end - (int) $start);
    T::check($start !== false && $end !== false && $end > $start, 'getRedirectManagerData() gefunden');
    T::check(str_contains($body, 'getRedirectRuleRowsPage(') && !str_contains($body, 'getRedirectRuleRows()') && !str_contains($body, 'getNotFoundLogRows('), 'die Seite liest eine Seite Regeln und keine 404-Protokollzeilen');
    T::check(str_contains($service, 'ORDER BY is_active DESC, updated_at DESC, source_path ASC, id ASC LIMIT ? OFFSET ?'), 'feste Reihenfolge mit ID als letztem Schlüssel, LIMIT und OFFSET gebunden');
    T::check(str_contains($service, 'SET hits = hits + 1, last_hit_at = NOW(), updated_at = updated_at WHERE id = ?'), 'ein Treffer erhöht hits und last_hit_at, aber nicht updated_at (die Reihenfolge der Liste bleibt stehen)');
    T::check(str_contains(read('admin/modules/seo/RedirectManagerModule.php'), 'getRedirectManagerData(int $page = 1)'), 'das Modul reicht die Seite durch');
    $entry = read('admin/redirect-manager.php');
    T::check(str_contains($entry, "\$_GET['seite']") && str_contains($entry, "\$_POST['return_page']"), 'der Eingang liest ?seite= und return_page');
    T::check(str_contains($entry, "'max_range' => 1000000"), 'die Seitennummer ist begrenzt');
    T::check(str_contains($entry, "in_array(\$action, ['delete_redirect', 'toggle_redirect'], true) ? cms_admin_redirect_manager_normalize_page"), 'nur Löschen und Umschalten kehren auf die Seite zurück, alle anderen Aktionen auf Seite 1');
    T::check(str_contains(read('admin/views/seo/redirects.php'), 'name="return_page"'), 'die Zeilenformulare tragen return_page');
});

T::finish();
