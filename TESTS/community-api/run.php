<?php
/**
 * Community-API (PR #4, Teil 3): `Http\ApiController` – Aktionen und Eingabeprüfung vor jedem Dienstaufruf, Statusabbildung, Fehlertexte,
 * CSRF-Filter für das dauerhafte Token, Routenanmeldung, JS-Modul ohne Inline-Skript. Die Entscheidungen selbst (Reagieren, Abonnieren,
 * Melden, Moderieren, Lösung) prüfen `community-notify`, `community-sanctions` und `community-moderation`.
 *
 *   php TESTS/community-api/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-api-' . getmypid() . '/');
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

require_once dirname(__DIR__) . '/lib/TestRunner.php';

use CMS\Services\Community\Http\ApiController;
use CMS\Services\Community\Service\ModerationService;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Urls;

$api = ApiController::instance();

T::run('Aktionen: Eingabeprüfung vor dem Dienst', static function () use ($api): void {
    [$status, $body] = $api->dispatch('gibtsnicht', [], 5);
    T::same([404, 'unknown_action'], [$status, $body['error'] ?? null], 'unbekannte Aktion: 404');
    foreach (ApiController::ACTIONS as $action) {
        [$status, $body] = $api->dispatch($action, [], 0);
        T::same([401, false], [$status, $body['success'] ?? null], $action . ': ohne Anmeldung 401, kein Dienstaufruf');
    }
    $invalid = [
        'reaktion ohne Beitrag' => ['reaktion', ['reaction' => 'like']],
        'reaktion mit Text als ID' => ['reaktion', ['post_id' => '1; DROP', 'reaction' => 'like']],
        'reaktion mit Exponent als ID' => ['reaktion', ['post_id' => '1e3', 'reaction' => 'like']],
        'abo mit falscher Art' => ['abo', ['type' => 'user', 'id' => '3']],
        'abo ohne ID' => ['abo', ['type' => 'topic']],
        'abo mit Modus daily' => ['abo', ['type' => 'topic', 'id' => '3', 'mode' => 'daily']],
        'melden mit falschem Grund' => ['melden', ['type' => 'post', 'id' => '3', 'reason' => 'weil']],
        'melden mit falscher Zielart' => ['melden', ['type' => 'forum', 'id' => '3', 'reason' => 'spam']],
        'moderation mit Löschen' => ['moderation', ['op' => 'topic.delete', 'id' => '3']],
        'moderation mit Beitragslöschen' => ['moderation', ['op' => 'post.delete', 'id' => '3']],
        'moderation ohne Ziel' => ['moderation', ['op' => 'topic.lock']],
        'loesung ohne Thema' => ['loesung', ['post_id' => '4']],
        'moderation mit Array als ID' => ['moderation', ['op' => 'topic.lock', 'id' => ['1', '2']]],
    ];
    foreach ($invalid as $label => [$action, $post]) {
        [$status, $body] = $api->dispatch($action, $post, 5);
        T::same([422, 'invalid_input'], [$status, $body['error'] ?? null], $label . ': invalid_input, 422');
    }
    T::check(array_diff(ApiController::MODERATION_OPS, ModerationService::BULK_OPERATIONS) === [], 'Moderationsaktionen der API sind Teilmenge der Sammelaktionen des Dienstes');
    T::check(array_filter(ApiController::MODERATION_OPS, static fn (string $op): bool => str_ends_with($op, '.delete')) === [], 'die API löscht nichts (Löschen gehört in die Verwaltung)');
});

T::run('Status und Fehlertexte', static function (): void {
    T::same([200, 401, 429, 404, 422, 500], [
        ApiController::statusOf(['success' => true]),
        ApiController::statusOf(['success' => false, 'error' => 'login_required']),
        ApiController::statusOf(['success' => false, 'error' => 'rate_limited']),
        ApiController::statusOf(['success' => false, 'error' => 'module_disabled']),
        ApiController::statusOf(['success' => false, 'error' => 'body_too_short']),
        ApiController::statusOf(['success' => false, 'error' => 'db_error']),
    ], 'Statusabbildung');
    $ok = ['success' => true, 'html' => '<p>x</p>'];
    T::same($ok, ApiController::present($ok), 'Erfolg bleibt unverändert');
    $failed = ApiController::present(['success' => false, 'error' => 'zzz_unbekannt']);
    T::check(str_contains($failed['message'], 'zzz_unbekannt') && $failed['error'] === 'zzz_unbekannt', 'unbekannter Code: allgemeine Meldung mit Code');
});

T::run('CSRF-Filter und Adressen', static function () use ($api): void {
    Settings::setInstance(Settings::fromArray([]));
    try {
        T::same('/forum/api/:action', Urls::apiPattern('de'), 'Muster de');
        T::same('/en/forum/api/:action', Urls::apiPattern('en'), 'Muster en');
        T::same('/forum/api/', Urls::apiBase('de'), 'Basisadresse');
        T::check(Urls::isApiPath('/forum/api/reaktion') && Urls::isApiPath('/en/forum/api/abo?x=1'), 'isApiPath: de und en');
        T::check(!Urls::isApiPath('/forum/apix') && !Urls::isApiPath('/forum/thema/1') && !Urls::isApiPath('/api/forum/api/'), 'isApiPath: andere Adressen nicht');

        $_POST = ['csrf_token' => 'aus-formular'];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'aus-kopf';
        T::same(['action' => 'community_api', 'token' => 'aus-kopf'], ApiController::tokenFilter(null, '/forum/api/melden', 'POST'), 'Filter: Kopf vor Formularfeld, Aktion community_api');
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        T::same(['action' => 'community_api', 'token' => 'aus-formular'], ApiController::tokenFilter(null, '/en/forum/api/melden', 'POST'), 'Filter: Formularfeld');
        T::same(null, ApiController::tokenFilter(null, '/forum/allgemein/neues-thema', 'POST'), 'Filter: andere Adressen unberührt (null)');
        T::same(['action' => 'x', 'token' => 'y'], ApiController::tokenFilter(['action' => 'x', 'token' => 'y'], '/forum/api/abo', 'POST'), 'Filter: ein bereits gesetztes Ergebnis anderer Verbraucher bleibt');
        $_POST = ['csrf_token' => ['a']];
        T::same(['action' => 'community_api', 'token' => ''], ApiController::tokenFilter(null, '/forum/api/abo', 'POST'), 'Filter: Nicht-String ergibt leeres Token (der Router lehnt ab)');
    } finally {
        $_POST = [];
        Settings::setInstance(null);
    }

    $router = new class {
        /** @var list<array{0: string, 1: string}> */
        public array $routes = [];

        public function addRoute(string $method, string $path, callable $callback): void
        {
            $this->routes[] = [$method, $path];
        }
    };
    Settings::setInstance(Settings::fromArray([]));
    try {
        $api->registerRoutes($router);
    } finally {
        Settings::setInstance(null);
    }
    T::same([['POST', '/forum/api/:action'], ['POST', '/en/forum/api/:action']], $router->routes, 'nur POST, beide Sprachen');
});

T::run('Einbindung (Quelltext)', static function (): void {
    $hooks = (string) file_get_contents(ABSPATH . 'core/Services/FeatureHooks/CommunityHooks.php');
    $gate = strpos($hooks, 'if (!CommunityModule::isEnabled())');
    T::check($gate !== false && strpos($hooks, 'PUBLIC_FORM_TOKEN_FILTER') > $gate, 'CSRF-Filter nur bei aktivem Modul');
    T::check(strpos($hooks, 'ApiController::instance()->registerRoutes') < strpos($hooks, 'ComposeController::instance()->registerRoutes'), 'API-Routen vor den Schreib- und Leseseiten');

    $js = (string) file_get_contents(ABSPATH . 'assets/js/community/community.js');
    T::check(!str_contains($js, 'eval(') && !str_contains($js, 'document.write') && substr_count($js, 'innerHTML') === 1, 'JS: kein eval/document.write, innerHTML nur für die Vorschau (serverseitig gereinigt)');
    T::check(str_contains($js, "'X-CSRF-Token': token") && str_contains($js, "credentials: 'same-origin'"), 'JS: Token im Kopf, Cookies nur gleicher Herkunft');
    T::check(filesize(ABSPATH . 'assets/js/community/community.js') < 30 * 1024, 'JS unter 30 KB');
    $topic = (string) file_get_contents(ABSPATH . 'views/community/topic.php');
    $compose = (string) file_get_contents(ABSPATH . 'views/community/compose.php');
    foreach ([$topic, $compose] as $view) {
        T::check(stripos($view, '<script') === false && preg_match('/\sstyle\s*=/i', $view) !== 1, 'Ansicht: kein Inline-Skript oder -Style');
    }
    T::check(str_contains($topic, 'data-cc-api=') && str_contains($compose, 'data-cc-api='), 'Seiten tragen die API-Adresse nur für angemeldete Mitglieder (Bedingung $api)');
    T::check(preg_match('/<\?php if \(!empty\(\$api\)\): \?> data-cc-api=/', $topic) === 1, 'topic: data-cc-api steht hinter der Bedingung');
    $controller = (string) file_get_contents(ABSPATH . 'core/Services/Community/Http/PublicController.php');
    T::check(str_contains($controller, "generateToken(ApiController::TOKEN_ACTION)") && str_contains($controller, "if (\$this->viewerId() === null) {\n            return null;"), 'Token nur für angemeldete Mitglieder');
});

T::finish();
