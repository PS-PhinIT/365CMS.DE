<?php
/**
 * Captcha in der Community (PR #5, E7): `CaptchaGate` (wann verlangt, Verfahren, Prüfung, Meldungen), Einbindung in
 * `ComposeController`, Formular und Kopf der Seite, Einstellungen im Admin. Der Dienst selbst und seine Verfahren: Suite `captcha`.
 * DB-frei (Profil des Mitglieds und Dienst sind Closures bzw. die Speicher-Ablage).
 *
 *   php TESTS/community-captcha/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-captcha-' . getmypid() . '/');
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

use CMS\Hooks;
use CMS\Services\Community\Http\ComposeController;
use CMS\Services\Community\Service\CaptchaGate;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Security\Captcha\CaptchaResult;
use CMS\Services\Security\Captcha\CaptchaStore;
use CMS\Services\Security\CaptchaService;

/**
 * Gate mit festen Einstellungen, festem Profil und Speicher-Ablage.
 *
 * @param array<string, mixed> $settings
 * @param array{topics?: int, posts?: int, level?: int, exempt?: bool}|\Closure $profile
 * @return array{0: CaptchaGate, 1: CaptchaService, 2: CaptchaStore}
 */
function gate(array $settings = [], array|\Closure $profile = [], ?array &$log = null): array
{
    $log ??= [];
    $store = CaptchaStore::memory();
    $service = new CaptchaService($store, static fn (): int => 1800000000, static fn (int $min, int $max): int => $min + 3 % ($max - $min + 1));
    $resolver = $profile instanceof \Closure ? $profile : static fn (int $userId): array => $profile + ['topics' => 0, 'posts' => 0, 'level' => 0, 'exempt' => false];

    $record = static function (int $userId) use (&$log): void {
        $log[] = $userId;
    };

    return [new CaptchaGate(Settings::fromArray($settings), static fn (): CaptchaService => $service, $resolver, $record), $service, $store];
}

T::run('Wirkung: captcha_mode', static function (): void {
    $new = ['topics' => 0, 'posts' => 0, 'level' => 0];
    $active = ['topics' => 1, 'posts' => 4, 'level' => 1];
    $started = ['topics' => 0, 'posts' => 1, 'level' => 0];
    $matrix = [
        // Modus => [neues Mitglied, Mitglied mit Beiträgen, Stufe 0 mit einem Beitrag, Moderation]
        'off' => [false, false, false, false],
        'first_post' => [true, false, false, false],
        'trust0' => [true, false, true, false],
        'always' => [true, true, true, false],
    ];
    foreach ($matrix as $mode => [$expectNew, $expectActive, $expectStarted, $expectExempt]) {
        [$gate] = gate(['captcha_mode' => $mode], $new);
        T::same($expectNew, $gate->required(7, 'create_topic'), $mode . ': neues Mitglied, neues Thema');
        T::same($expectNew, $gate->required(7, 'reply'), $mode . ': neues Mitglied, Antwort');
        [$gate] = gate(['captcha_mode' => $mode], $active);
        T::same($expectActive, $gate->required(7, 'reply'), $mode . ': Mitglied mit Beiträgen auf Stufe 1');
        [$gate] = gate(['captcha_mode' => $mode], $started);
        T::same($expectStarted, $gate->required(7, 'reply'), $mode . ': Stufe 0 mit einem veröffentlichten Beitrag');
        [$gate] = gate(['captcha_mode' => $mode], $new + ['exempt' => true]);
        T::same($expectExempt, $gate->required(7, 'reply'), $mode . ': globale Moderation ist ausgenommen');
    }
    [$gate] = gate([], $new);
    T::same('first_post', Settings::fromArray([])->string('captcha_mode'), 'Standard: bis zum ersten veröffentlichten Beitrag');
    T::same(true, $gate->required(7, 'create_topic'), 'Standard gilt: neues Mitglied löst ein Captcha');
    [$gate] = gate(['captcha_mode' => 'always'], $new);
    T::same([false, false, false, false], [$gate->required(0, 'reply'), $gate->required(-3, 'reply'), $gate->required(7, 'edit'), $gate->required(7, 'react')], 'Gast, ungültiges Mitglied, Bearbeiten und andere Aktionen: nie');

    // Datenbankfehler: fail-closed (Captcha), aber nie, wenn der Modus aus ist.
    $broken = static function (int $userId): array {
        throw new \RuntimeException('keine Datenbank');
    };
    [$gate] = gate(['captcha_mode' => 'trust0'], $broken);
    T::same(true, $gate->required(7, 'reply'), 'Profil nicht lesbar: Captcha verlangt (fail-closed)');
    [$gate] = gate(['captcha_mode' => 'off'], $broken);
    T::same(false, $gate->required(7, 'reply'), 'Modus aus: das Profil wird gar nicht gelesen');
    $reads = 0;
    [$gate] = gate(['captcha_mode' => 'off'], static function (int $userId) use (&$reads): array {
        $reads++;

        return ['topics' => 0, 'posts' => 0, 'level' => 0, 'exempt' => false];
    });
    $gate->required(7, 'reply');
    T::same(0, $reads, 'Modus aus: keine Datenbankabfrage');

    // Der Filter darf nur verschärfen.
    $tighten = static fn (mixed $value, int $userId, string $action): bool => $userId === 7 ? true : (bool) $value;
    Hooks::addFilter(CaptchaGate::FILTER, $tighten);
    [$gate] = gate(['captcha_mode' => 'off'], $new);
    T::same([true, false], [$gate->required(7, 'reply'), $gate->required(8, 'reply')], 'Filter true verlangt ein Captcha, auch bei aus; für andere Mitglieder bleibt es aus');
    Hooks::removeFilter(CaptchaGate::FILTER, $tighten);
    $loosen = static fn (mixed $value): bool => false;
    Hooks::addFilter(CaptchaGate::FILTER, $loosen);
    [$gate] = gate(['captcha_mode' => 'always'], $new);
    T::same(true, $gate->required(7, 'reply'), 'Filter false lockert nichts');
    Hooks::removeFilter(CaptchaGate::FILTER, $loosen);
    $throwing = static function (): bool {
        throw new \RuntimeException('Filter kaputt');
    };
    Hooks::addFilter(CaptchaGate::FILTER, $throwing);
    T::same(true, $gate->required(7, 'reply'), 'ein werfender Filter ändert nichts');
    Hooks::removeFilter(CaptchaGate::FILTER, $throwing);
});

T::run('Wirkung: captcha_provider', static function (): void {
    foreach (['pow' => 'data-cms-captcha="pow"', 'math' => 'data-cms-captcha="math"'] as $provider => $marker) {
        [$gate] = gate(['captcha_provider' => $provider]);
        T::check(str_contains($gate->render(), $marker), $provider . ': die Aufgabe kommt von diesem Verfahren');
    }
    [$gate] = gate(['captcha_provider' => 'image']);
    T::check(str_contains($gate->render(), function_exists('imagepng') ? 'data-cms-captcha="image"' : 'data-cms-captcha="math"'), 'image: Bild (ohne GD der Rückfall math)');
    [$gate] = gate([]);
    T::check(str_contains($gate->render(), 'data-cms-captcha="pow"'), 'Standard: Proof-of-Work');
    [$gate] = gate(['captcha_provider' => 'turnstile']);
    T::check(str_contains($gate->render(), 'data-cms-captcha="pow"'), 'ein Drittanbieter ist kein gültiger Wert: Standard pow');
});

T::run('Gate: Aufgabe stellen, Antwort prüfen, Kontext, Meldungen', static function (): void {
    $log = [];
    [$gate, $service] = gate(['captcha_provider' => 'math'], [], $log);
    $html = $gate->render();
    $id = preg_match('/name="cms_captcha_id" value="([0-9a-f]{32})"/', $html, $m) === 1 ? $m[1] : '';
    preg_match('/Was ist (\d) (plus|minus|mal) (\d)\?/u', $html, $q);
    $answer = match ($q[2]) {
        'plus' => (int) $q[1] + (int) $q[3],
        'minus' => (int) $q[1] - (int) $q[3],
        default => (int) $q[1] * (int) $q[3],
    };
    $ok = $gate->verify(['cms_captcha_id' => $id, 'cms_captcha_answer' => (string) $answer]);
    T::same([true, 'math'], [$ok->ok, $ok->provider], 'richtige Antwort besteht');
    $again = $gate->verify(['cms_captcha_id' => $id, 'cms_captcha_answer' => (string) $answer], 7);
    T::same([false, CaptchaResult::MISSING], [$again->ok, $again->code], 'dieselbe Aufgabe ein zweites Mal: missing');
    T::same([7], $log, 'Fehlschlag mit Mitglied: ein Eintrag captcha_failed für das Mitglied');
    $gate->verify([], 0);
    T::same([7], $log, 'Fehlschlag ohne Mitglied: kein Eintrag');
    $html2 = $gate->render();
    preg_match('/name="cms_captcha_id" value="([0-9a-f]{32})"/', $html2, $m3);
    preg_match('/Was ist (\d) (plus|minus|mal) (\d)\?/u', $html2, $q3);
    $answer3 = match ($q3[2]) {
        'plus' => (int) $q3[1] + (int) $q3[3],
        'minus' => (int) $q3[1] - (int) $q3[3],
        default => (int) $q3[1] * (int) $q3[3],
    };
    $gate->verify(['cms_captcha_id' => $m3[1], 'cms_captcha_answer' => (string) $answer3], 7);
    T::same([7], $log, 'Erfolg: kein Eintrag');
    $throwingLog = new CaptchaGate(Settings::fromArray([]), static fn (): CaptchaService => $service, null, static function (int $userId): void {
        throw new \RuntimeException('Protokoll kaputt');
    });
    T::same(false, $throwingLog->verify([], 9)->ok, 'ein Fehler im Protokoll verhindert die Antwort nicht');
    T::same('community.compose', CaptchaGate::CONTEXT, 'Kontext community.compose');
    // Eine Aufgabe eines anderen Formulars gilt hier nicht.
    $foreign = $service->render('forms.contact', 'math');
    $foreignId = preg_match('/name="cms_captcha_id" value="([0-9a-f]{32})"/', $foreign, $m2) === 1 ? $m2[1] : '';
    $result = $gate->verify(['cms_captcha_id' => $foreignId, 'cms_captcha_answer' => '1']);
    T::same(CaptchaResult::CONTEXT, $result->code, 'Aufgabe aus einem anderen Formular: context');
    T::same(['ACTIONS' => ['create_topic', 'reply']], ['ACTIONS' => CaptchaGate::ACTIONS], 'nur neues Thema und Antwort');

    $texts = [];
    foreach ([CaptchaResult::EXPIRED, CaptchaResult::UNAVAILABLE, CaptchaResult::INVALID, CaptchaResult::MISSING, CaptchaResult::CONTEXT] as $code) {
        $texts[$code] = CaptchaGate::errorText(CaptchaResult::failed($code));
    }
    T::check(count(array_unique([$texts['expired'], $texts['unavailable'], $texts['invalid']])) === 3, 'abgelaufen, nicht verfügbar und falsch haben je einen eigenen Text');
    T::same([$texts['invalid'], $texts['invalid']], [$texts['missing'], $texts['context']], 'fehlende und fremde Aufgabe: derselbe Text wie „falsch“');
    foreach ($texts as $code => $text) {
        T::check($text !== '' && !str_contains($text, 'captcha.error.'), $code . ': der Text ist übersetzt (kein Schlüssel)');
    }
});

T::run('ComposeController: Captcha vor dem Speichern, Fehlschlag mit neuer Aufgabe und Protokoll, Bearbeiten ohne', static function (): void {
    $source = (string) file_get_contents(ABSPATH . 'core/Services/Community/Http/ComposeController.php');
    $code = (string) preg_replace('~/\*.*?\*/|(?<![:\'"])//[^\n]*~s', '', $source);
    $topicCall = strpos($code, 'TopicService::instance()->create(');
    $replyCall = strpos($code, 'PostService::instance()->reply(');
    $editCall = strpos($code, 'PostService::instance()->edit(');
    $topicGuard = strpos($code, "captchaFailure(\$viewer, 'create_topic')");
    $replyGuard = strpos($code, "captchaFailure(\$viewer, 'reply')");
    T::check($topicGuard !== false && $topicCall !== false && $topicGuard < $topicCall, 'neues Thema: das Captcha wird vor TopicService::create() geprüft');
    T::check($replyGuard !== false && $replyCall !== false && $replyGuard < $replyCall, 'Antwort: das Captcha wird vor PostService::reply() geprüft');
    T::check($editCall !== false && substr_count($code, 'captchaFailure(') === 3, 'Bearbeiten prüft kein Captcha (zwei Aufrufe plus die Methode)');
    T::check(str_contains($code, '$this->show($form, $locale, 422)'), 'Fehlschlag: das Formular erscheint wieder (Status 422), mit neuer Aufgabe');
    T::check(str_contains($code, 'verify($_POST, $viewer)'), 'Fehlschlag: der Controller übergibt das Mitglied (Eintrag captcha_failed im Sicherheitsprotokoll durch das Gate)');
    $gateCode = (string) file_get_contents(ABSPATH . 'core/Services/Community/Service/CaptchaGate.php');
    T::check(str_contains($gateCode, "logRejection('captcha_failed', \$userId)"), 'Gate: Fehlschlag wird als captcha_failed protokolliert');
    T::check(str_contains($code, "\$form['captcha'] = \$this->captchaHtml(\$form)"), 'show() legt eine neue Aufgabe in das Formular');
    T::check(preg_match("~'create_topic'.*'reply'~s", $code) === 1 && !preg_match("~captchaHtml.*'edit'~s", (string) substr($code, (int) strpos($code, 'function captchaHtml'), 700)), 'captchaHtml(): nur neues Thema und Antwort');

    $view = (string) file_get_contents(ABSPATH . 'views/community/compose.php');
    T::check(preg_match('~<\?php if \(!empty\(\$captcha\)\): \?>\s*<div class="cms-community-captcha"><\?= \$captcha~', $view) === 1, 'Formular gibt die Aufgabe innerhalb des <form> aus');
    $at = (int) strpos($view, '<?= $captcha');
    T::check($at > (int) strpos($view, '<form ') && $at < (int) strpos($view, '</form>'), 'die Aufgabe steht zwischen <form> und </form>');
    $public = (string) file_get_contents(ABSPATH . 'core/Services/Community/Http/PublicController.php');
    T::check(str_contains($public, "\$this->meta['captcha'] = \$view === 'compose' && !empty(\$vars['captcha'])"), 'Seite lädt Skript und Stil nur mit Aufgabe im Schreibformular');
    T::check(str_contains($public, "CaptchaService::assetPaths()['css']") && str_contains($public, "CaptchaService::assetPaths()['js']"), 'Kopf der Seite: captcha.css und captcha.js');

    $admin = (string) file_get_contents(ABSPATH . 'admin/views/community/index.php');
    T::check(str_contains($admin, "'captcha_mode', 'captcha_provider'") && str_contains($admin, "\$def['type'] === 'enum'"), 'Admin: beide Einstellungen als Auswahlfelder in „Schutz vor Spam“');
    T::check(str_contains((string) file_get_contents(ABSPATH . 'admin/modules/community/CommunityAdminModule.php'), "\$type === 'slug' || \$type === 'enum'"), 'Admin: Speichern liest Auswahlfelder');
    T::check(str_contains($admin, '<select class="form-select"'), 'Admin: <select> vorhanden');

    // Ablauf an der Methode: der Controller prüft nur, wenn das Gate es verlangt, und nimmt die Felder aus $_POST.
    $reflection = new \ReflectionClass(CaptchaGate::class);
    $property = $reflection->getProperty('instance');
    $property->setAccessible(true);
    $previous = $property->getValue(null);
    $controller = (new \ReflectionClass(ComposeController::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(ComposeController::class, 'captchaFailure');
    $method->setAccessible(true);
    try {
        [$gate] = gate(['captcha_mode' => 'first_post', 'captcha_provider' => 'math'], ['topics' => 0, 'posts' => 0, 'level' => 0]);
        $property->setValue(null, $gate);
        $_POST = [];
        $failure = $method->invoke($controller, 7, 'reply');
        T::check(is_string($failure) && $failure !== '', 'ohne Antwort: Text für das Formular');
        $html = $gate->render();
        preg_match('/name="cms_captcha_id" value="([0-9a-f]{32})"/', $html, $m);
        preg_match('/Was ist (\d) (plus|minus|mal) (\d)\?/u', $html, $q);
        $answer = match ($q[2]) {
            'plus' => (int) $q[1] + (int) $q[3],
            'minus' => (int) $q[1] - (int) $q[3],
            default => (int) $q[1] * (int) $q[3],
        };
        $_POST = ['cms_captcha_id' => $m[1], 'cms_captcha_answer' => (string) $answer];
        T::same(null, $method->invoke($controller, 7, 'reply'), 'mit richtiger Antwort aus $_POST: kein Fehler');
        $_POST = [];
        [$gate] = gate(['captcha_mode' => 'off']);
        $property->setValue(null, $gate);
        T::same(null, $method->invoke($controller, 7, 'reply'), 'Modus aus: nichts wird geprüft');
    } finally {
        $_POST = [];
        $property->setValue(null, $previous);
    }
});

T::finish();
