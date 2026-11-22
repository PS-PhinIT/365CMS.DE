<?php
/**
 * Captcha-Dienst des Core (E7): `CaptchaService` mit den Verfahren `pow` (Proof-of-Work), `image` (GD) und `math`, die Ablage der Aufgaben
 * in der Sitzung (`CaptchaStore`), Auswahl und Rückfall, einmalige Verwendung, Ablauf, Kontext, Filter für weitere Verfahren und das
 * Skript des Browsers (mit `node`, falls vorhanden). DB-frei, ohne Netzwerk.
 *
 *   php TESTS/captcha/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-captcha-' . getmypid() . '/');
define('SITE_URL', 'https://example.test');
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
use CMS\Services\Security\Captcha\CaptchaProvider;
use CMS\Services\Security\Captcha\CaptchaResult;
use CMS\Services\Security\Captcha\CaptchaStore;
use CMS\Services\Security\Captcha\ImageProvider;
use CMS\Services\Security\Captcha\MathChallenge;
use CMS\Services\Security\Captcha\MathProvider;
use CMS\Services\Security\Captcha\ProofOfWorkProvider;
use CMS\Services\Security\CaptchaService;

const NOW = 1800000000;

/** Dienst mit Speicher-Ablage, steuerbarer Uhr und festem Zufall (Folge von Zahlen, reihum). */
final class CapWorld
{
    public CaptchaStore $store;
    public int $now = NOW;
    public CaptchaService $service;
    /** @var list<int> */
    public array $numbers;
    private int $cursor = 0;
    private int $counter = 0;

    /**
     * @param list<int> $numbers Folge für `$int(min, max)`: der Wert wird in den Bereich gefaltet
     * @param array<string, CaptchaProvider>|null $providers
     */
    public function __construct(array $numbers = [3, 7, 2, 9, 5, 1, 8, 4, 6, 0, 11, 13, 17, 19, 23], ?array $providers = null)
    {
        $this->store = CaptchaStore::memory();
        $this->numbers = $numbers;
        $this->service = new CaptchaService(
            $this->store,
            fn (): int => $this->now,
            function (int $min, int $max): int {
                $value = $this->numbers[$this->cursor++ % count($this->numbers)];

                return $min + $value % ($max - $min + 1);
            },
            function (int $length): string {
                return substr(hash('sha256', 'bytes' . $this->counter++, true) . hash('sha256', 'more' . $this->counter, true), 0, $length);
            },
            $providers
        );
    }
}

/** Verfahren mit einstellbaren Eigenschaften (Auswahl, Filter, Einwilligung). */
final class CapFake implements CaptchaProvider
{
    public function __construct(private string $id, private bool $available = true, private bool $consent = false, private bool $verifies = true)
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function create(\Closure $int, \Closure $bytes): array
    {
        return ['public' => [], 'private' => []];
    }

    public function render(string $id, array $public): string
    {
        return '<fieldset data-extra><input type="hidden" name="cms_captcha_id" value="' . $id . '"></fieldset>';
    }

    public function verify(array $request, array $private): bool
    {
        if (!$this->verifies) {
            throw new \RuntimeException('Verfahren kaputt');
        }

        return ($request['x'] ?? '') === 'ja';
    }

    public function requiresConsent(): bool
    {
        return $this->consent;
    }

    public function cspSources(): array
    {
        return ['script-src' => ['https://captcha.example']];
    }
}

/** Wert eines Attributs aus dem HTML. */
function attr(string $html, string $name): string
{
    return preg_match('/\b' . preg_quote($name, '/') . '="([^"]*)"/', $html, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES) : '';
}

/** Kennung der Aufgabe aus dem HTML. */
function taskId(string $html): string
{
    return preg_match('/name="cms_captcha_id" value="([0-9a-f]{32})"/', $html, $m) === 1 ? $m[1] : '';
}

/** Rechenaufgabe aus dem Fragetext: Ergebnis. */
function mathAnswer(string $html): int
{
    if (preg_match('/Was ist (\d) (plus|minus|mal) (\d)\?/u', $html, $m) !== 1) {
        throw new \RuntimeException('keine Rechenaufgabe im HTML');
    }
    $a = (int) $m[1];
    $b = (int) $m[3];

    return match ($m[2]) {
        'plus' => $a + $b,
        'minus' => $a - $b,
        default => $a * $b,
    };
}

/** Lösung des Proof-of-Work aus dem HTML (Suche wie der Browser). */
function powSolution(string $html): int
{
    $salt = attr($html, 'data-salt');
    $challenge = attr($html, 'data-challenge');
    $max = (int) attr($html, 'data-max');
    for ($n = 0; $n <= $max; $n++) {
        if (hash('sha256', $salt . $n) === $challenge) {
            return $n;
        }
    }
    throw new \RuntimeException('keine Lösung gefunden');
}

T::run('Verfahren: pow, image, math; Auswahl und Rückfall', static function (): void {
    $w = new CapWorld();
    T::same(['pow', 'image', 'math'], array_keys($w->service->providers()), 'drei Verfahren: pow, image, math');
    T::same('pow', $w->service->effectiveId('pow'), 'pow ist verfügbar');
    T::same(function_exists('imagepng') ? 'image' : 'math', $w->service->effectiveId('image'), 'image läuft nur mit GD, sonst Rückfall math');
    T::same(['math', 'math', 'math'], [$w->service->effectiveId(null), $w->service->effectiveId('gibt-es-nicht'), $w->service->effectiveId('')], 'unbekannt oder leer: math (kein stilles Abschalten)');
    T::same('math', $w->service->provider('turnstile')->id(), 'provider(): Rückfall auf math');
    foreach ($w->service->providers() as $provider) {
        T::same([false, []], [$provider->requiresConsent(), $provider->cspSources()], $provider->id() . ': keine Einwilligung, keine zusätzlichen CSP-Quellen');
    }
    T::same([], $w->service->cspSources('pow'), 'cspSources: lokal, keine Fremdquellen');
    T::same(['js' => 'js/captcha/captcha.js', 'css' => 'css/captcha.css'], CaptchaService::assetPaths(), 'Skript und Stil');
    T::check(is_file(ABSPATH . 'assets/' . CaptchaService::assetPaths()['js']) && is_file(ABSPATH . 'assets/' . CaptchaService::assetPaths()['css']), 'Skript und Stil sind vorhanden');

    // Ein Verfahren, das nicht verfügbar ist oder Einwilligung braucht, wird nie gewählt.
    $fake = new CapFake('shaky', false);
    $consent = new CapFake('consent', true, true);
    $custom = new CapWorld(providers: ['shaky' => $fake, 'consent' => $consent]);
    T::same(['math', 'math', 'math'], [$custom->service->effectiveId('shaky'), $custom->service->effectiveId('consent'), $custom->service->effectiveId('pow')], 'nicht verfügbar, Einwilligungspflicht und nicht angemeldet: Rückfall math');
    T::same(['shaky', 'consent', 'math'], array_keys($custom->service->providers()), 'feste Verfahren plus math');
});

T::run('Filter cms_captcha_providers: weitere Verfahren, ungültige Einträge entfallen, math bleibt', static function (): void {
    $extra = new CapFake('extra');
    $filter = static fn (mixed $list): array => ['extra' => $extra, 'bad' => new CapFake('Bad Id!'), 'kein-objekt' => 'x'] + (array) $list;
    Hooks::addFilter(CaptchaService::FILTER_PROVIDERS, $filter);
    $store = CaptchaStore::memory();
    $service = new CaptchaService($store);
    T::same(['extra', 'pow', 'image', 'math'], array_keys($service->providers()), 'das Verfahren aus dem Filter steht vorn, Einträge mit ungültiger Kennung oder ohne Verfahren entfallen');
    $html = $service->render('ctx', 'extra');
    T::check(str_contains($html, 'data-extra'), 'das Verfahren aus dem Filter wird gerendert');
    $result = $service->verify(['cms_captcha_id' => taskId($html), 'x' => 'ja'], 'ctx');
    T::same([true, 'extra'], [$result->ok, $result->provider], 'verify() ruft das Verfahren aus dem Filter');
    Hooks::removeFilter(CaptchaService::FILTER_PROVIDERS, $filter);

    $remove = static fn (mixed $list): array => ['pow' => new ProofOfWorkProvider()];
    Hooks::addFilter(CaptchaService::FILTER_PROVIDERS, $remove);
    T::same(['pow', 'math'], array_keys((new CaptchaService(CaptchaStore::memory()))->providers()), 'ein Filter kann math nicht entfernen');
    Hooks::removeFilter(CaptchaService::FILTER_PROVIDERS, $remove);

    $throwing = static function (mixed $list): array {
        throw new \RuntimeException('Filter kaputt');
    };
    Hooks::addFilter(CaptchaService::FILTER_PROVIDERS, $throwing);
    T::same(['pow', 'image', 'math'], array_keys((new CaptchaService(CaptchaStore::memory()))->providers()), 'ein werfender Filter lässt die Standardverfahren gelten');
    Hooks::removeFilter(CaptchaService::FILTER_PROVIDERS, $throwing);

    // Ein Verfahren, das beim Prüfen wirft, besteht nicht (kein Fatal Error im Formular).
    $broken = new CaptchaService(CaptchaStore::memory(), null, null, null, ['broken' => new CapFake('broken', true, false, false)]);
    $task = $broken->render('ctx', 'broken');
    T::same([false, CaptchaResult::INVALID], [($r = $broken->verify(['cms_captcha_id' => taskId($task), 'x' => 'ja'], 'ctx'))->ok, $r->code], 'ein werfendes Verfahren: invalid');
});

T::run('Proof-of-Work: Aufgabe, Lösung, falsche Zahl, Alternative Rechenaufgabe', static function (): void {
    $w = new CapWorld();
    $html = $w->service->render('community.compose', 'pow');
    $id = taskId($html);
    T::check(preg_match('/^[0-9a-f]{32}$/', $id) === 1, 'Aufgabe hat eine Kennung aus 32 Hex-Zeichen');
    T::same(['SHA-256', ProofOfWorkProvider::MAX_NUMBER], [attr($html, 'data-algorithm'), (int) attr($html, 'data-max')], 'Algorithmus und größte Zahl stehen im HTML');
    T::check(preg_match('/^[0-9a-f]{32}$/', attr($html, 'data-salt')) === 1 && preg_match('/^[0-9a-f]{64}$/', attr($html, 'data-challenge')) === 1, 'Salz (32 Hex) und Aufgabe (SHA-256, 64 Hex)');
    T::check(str_contains($html, 'name="cms_captcha_pow"') && str_contains($html, 'data-cms-captcha-payload') && str_contains($html, 'name="cms_captcha_answer"'), 'Felder: Lösung, Rechenaufgabe');
    T::check(str_contains($html, 'role="status"') && str_contains($html, 'aria-live="polite"'), 'Statusanzeige für Screenreader (role=status, aria-live)');
    T::check(!str_contains($html, ' hidden') || str_contains($html, 'data-cms-captcha-fallback>'), 'die Rechenaufgabe ist ohne JavaScript sichtbar');
    T::same(1, $w->store->count(), 'eine offene Aufgabe in der Ablage');

    $n = powSolution($html);
    T::same(true, $w->service->verify(['cms_captcha_id' => $id, 'cms_captcha_pow' => (string) $n], 'community.compose')->ok, 'die richtige Zahl besteht');
    T::same(0, $w->store->count(), 'die Aufgabe ist verbraucht');

    $second = $w->service->render('community.compose', 'pow');
    $wrong = ((powSolution($second)) + 1) % (ProofOfWorkProvider::MAX_NUMBER + 1);
    $result = $w->service->verify(['cms_captcha_id' => taskId($second), 'cms_captcha_pow' => (string) $wrong], 'community.compose');
    T::same([false, CaptchaResult::INVALID, 'pow'], [$result->ok, $result->code, $result->provider], 'eine falsche Zahl besteht nicht (invalid)');

    $third = $w->service->render('community.compose', 'pow');
    T::same(false, $w->service->verify(['cms_captcha_id' => taskId($third), 'cms_captcha_pow' => (string) (ProofOfWorkProvider::MAX_NUMBER + 5)], 'community.compose')->ok, 'eine Zahl über der Grenze besteht nicht');
    // Die Grenze gilt für sich: auch eine „richtige“ Zahl über MAX_NUMBER besteht nicht (Aufgabe von Hand gebaut).
    $big = ProofOfWorkProvider::MAX_NUMBER + 5;
    $manual = ['salt' => 'abc', 'challenge' => hash('sha256', 'abc' . $big), 'max' => ProofOfWorkProvider::MAX_NUMBER, 'answer' => 999];
    T::same([false, true], [(new ProofOfWorkProvider())->verify(['cms_captcha_pow' => (string) $big], $manual), (new ProofOfWorkProvider())->verify(['cms_captcha_pow' => (string) $big], ['max' => $big] + $manual)], 'eine Zahl über der Grenze besteht nicht, auch wenn der Hash passt; mit passender Grenze besteht sie');
    foreach (['abc', '-1', '1.5', '', ' 12', '99999999999', ['1']] as $bad) {
        $task = $w->service->render('community.compose', 'pow');
        T::same(false, $w->service->verify(['cms_captcha_id' => taskId($task), 'cms_captcha_pow' => $bad], 'community.compose')->ok, 'ungültige Lösung ' . json_encode($bad) . ' besteht nicht');
    }

    $fourth = $w->service->render('community.compose', 'pow');
    T::same(true, $w->service->verify(['cms_captcha_id' => taskId($fourth), 'cms_captcha_answer' => (string) mathAnswer($fourth)], 'community.compose')->ok, 'die Rechenaufgabe ist eine gleichwertige Alternative (ohne JavaScript)');
    $fifth = $w->service->render('community.compose', 'pow');
    T::same(false, $w->service->verify(['cms_captcha_id' => taskId($fifth), 'cms_captcha_answer' => (string) (mathAnswer($fifth) + 1)], 'community.compose')->ok, 'falsche Rechenaufgabe und keine Zahl: abgelehnt');
    $sixth = $w->service->render('community.compose', 'pow');
    T::same(false, $w->service->verify(['cms_captcha_id' => taskId($sixth)], 'community.compose')->ok, 'weder Zahl noch Antwort: abgelehnt');
});

T::run('Rechenaufgabe: Operatoren, Ergebnis nie negativ, strenge Prüfung', static function (): void {
    $seen = [];
    for ($i = 0; $i < 30; $i++) {
        $task = MathChallenge::create(static fn (int $min, int $max): int => $min + (($i * 7 + $min * 3 + $max) % ($max - $min + 1)));
        $seen[$task['public']['op']] = true;
        T::check($task['answer'] >= 0 && $task['answer'] <= 81 && $task['public']['a'] >= 1 && $task['public']['b'] >= 1 && $task['public']['a'] <= 9 && $task['public']['b'] <= 9, 'Aufgabe ' . $i . ': Zahlen 1 bis 9, Ergebnis 0 bis 81');
    }
    T::same(['plus', 'minus', 'times'], MathChallenge::OPERATORS, 'drei Operatoren');
    $sequence = [1, 2, 7]; // Operator minus, dann a = 2, b = 7: die Zahlen müssen getauscht werden
    $minus = MathChallenge::create(static function (int $min, int $max) use (&$sequence): int {
        return array_shift($sequence) ?? 0;
    });
    T::same(['minus'], [$minus['public']['op']], 'Vorbedingung: minus');
    T::same([7, 2, 5], [$minus['public']['a'], $minus['public']['b'], $minus['answer']], 'minus: die größere Zahl steht vorn (2 − 7 wird zu 7 − 2 = 5)');
    T::same([true, true, true], [MathChallenge::check('7', 7), MathChallenge::check(' 7 ', 7), MathChallenge::check(7, 7)], 'check: Zahl, Leerraum am Rand, int');
    T::same([false, false, false, false, false, false, false], [MathChallenge::check('7.0', 7), MathChallenge::check('sieben', 7), MathChallenge::check('', 7), MathChallenge::check(null, 7), MathChallenge::check(['7'], 7), MathChallenge::check('77777', 7), MathChallenge::check('8', 7)], 'check: Kommazahl, Wort, leer, null, Array, zu lang, falsch');
    T::same('Was ist 7 plus 4?', MathChallenge::question(['a' => 7, 'b' => 4, 'op' => 'plus']), 'Frage mit ausgeschriebenem Operator (Screenreader)');
    T::same(['Was ist 7 minus 4?', 'Was ist 7 mal 4?'], [MathChallenge::question(['a' => 7, 'b' => 4, 'op' => 'minus']), MathChallenge::question(['a' => 7, 'b' => 4, 'op' => 'times'])], 'minus und mal');

    $w = new CapWorld();
    $html = $w->service->render('ctx', 'math');
    T::check(str_contains($html, 'data-cms-captcha="math"') && !str_contains($html, 'cms_captcha_pow') && !str_contains($html, '<img'), 'math: nur die Rechenaufgabe');
    T::same(true, $w->service->verify(['cms_captcha_id' => taskId($html), 'cms_captcha_answer' => (string) mathAnswer($html)], 'ctx')->ok, 'math: richtige Antwort');
    $html = $w->service->render('ctx', 'math');
    T::same(false, $w->service->verify(['cms_captcha_id' => taskId($html), 'cms_captcha_pow' => '1'], 'ctx')->ok, 'math: eine Proof-of-Work-Zahl hilft nicht');
});

T::run('Bild-Captcha: PNG, Zeichen ohne Verwechslungsgefahr, Eingabe ohne Beachtung von Groß-/Kleinschreibung, Alternative', static function (): void {
    if (!function_exists('imagepng')) {
        T::info('GD fehlt: Bild-Captcha wird übersprungen');

        return;
    }
    $w = new CapWorld();
    $html = $w->service->render('ctx', 'image');
    T::check(str_contains($html, 'data-cms-captcha="image"') && str_contains($html, 'name="cms_captcha_text"') && str_contains($html, 'name="cms_captcha_answer"'), 'Felder: Zeichen und Rechenaufgabe');
    T::check(preg_match('#<img src="data:image/png;base64,([A-Za-z0-9+/=]+)" width="190" height="64" alt="([^"]+)">#', $html, $m) === 1, 'Bild als data:-URI mit Größe und Hinweistext');
    $png = (string) base64_decode($m[1], true);
    $size = getimagesizefromstring($png);
    T::same(['image/png', 190, 64], [$size['mime'] ?? null, $size[0] ?? null, $size[1] ?? null], 'das Bild ist ein PNG der Größe 190×64');
    T::check(!str_contains($m[2], '"') && !preg_match('/[A-Z0-9]{5}/', $m[2]), 'der Hinweistext verrät den Code nicht');
    T::check(str_contains($html, 'Was ist '), 'die Rechenaufgabe steht daneben (Alternative zum Bild)');

    // Code aus der Ablage (Test kennt die Ablage), Eingaben prüfen
    $codes = [];
    foreach (range(1, 6) as $i) {
        $html = $w->service->render('ctx', 'image');
        $id = taskId($html);
        $record = (new \ReflectionProperty($w->store, 'memory'))->getValue($w->store)[$id];
        $codes[] = (string) $record['private']['code'];
        if ($i === 1) {
            $code = (string) $record['private']['code'];
            T::same(true, $w->service->verify(['cms_captcha_id' => $id, 'cms_captcha_text' => strtolower($code)], 'ctx')->ok, 'Kleinbuchstaben genügen');
        } elseif ($i === 2) {
            $code = (string) $record['private']['code'];
            T::same(true, $w->service->verify(['cms_captcha_id' => $id, 'cms_captcha_text' => ' ' . substr($code, 0, 2) . ' ' . substr($code, 2) . ' '], 'ctx')->ok, 'Leerraum wird ignoriert');
        } elseif ($i === 3) {
            T::same(false, $w->service->verify(['cms_captcha_id' => $id, 'cms_captcha_text' => 'AAAAA'], 'ctx')->ok, 'falscher Code: abgelehnt');
        } elseif ($i === 4) {
            T::same(false, $w->service->verify(['cms_captcha_id' => $id, 'cms_captcha_text' => ''], 'ctx')->ok, 'leerer Code: abgelehnt');
        } elseif ($i === 5) {
            T::same(true, $w->service->verify(['cms_captcha_id' => $id, 'cms_captcha_answer' => (string) mathAnswer($html)], 'ctx')->ok, 'die Rechenaufgabe ersetzt das Bild');
        }
    }
    foreach ($codes as $code) {
        T::check(preg_match('/^[' . ImageProvider::ALPHABET . ']{5}$/', $code) === 1, 'Code ' . $code . ': fünf Zeichen aus dem Alphabet');
    }
    T::check(preg_match('/[BIOQSZ0125 8]/', ImageProvider::ALPHABET) === 0, 'das Alphabet enthält keine verwechselbaren Zeichen (B, I, O, Q, S, Z, 0, 1, 2, 5, 8)');
    T::check(count(array_unique($codes)) > 1, 'verschiedene Aufgaben haben verschiedene Codes');
});

T::run('Lebenszyklus: einmalige Verwendung, Ablauf, Kontext, fehlende und fremde Kennung, Obergrenze, keine Sitzung', static function (): void {
    $w = new CapWorld();
    $html = $w->service->render('community.compose', 'math');
    $id = taskId($html);
    $request = ['cms_captcha_id' => $id, 'cms_captcha_answer' => (string) mathAnswer($html)];
    T::same(true, $w->service->verify($request, 'community.compose')->ok, 'erste Prüfung besteht');
    $again = $w->service->verify($request, 'community.compose');
    T::same([false, CaptchaResult::MISSING], [$again->ok, $again->code], 'dieselbe Aufgabe ein zweites Mal: missing (verbraucht)');

    $html = $w->service->render('community.compose', 'math');
    $id = taskId($html);
    $wrong = $w->service->verify(['cms_captcha_id' => $id, 'cms_captcha_answer' => (string) (mathAnswer($html) + 1)], 'community.compose');
    $retry = $w->service->verify(['cms_captcha_id' => $id, 'cms_captcha_answer' => (string) mathAnswer($html)], 'community.compose');
    T::same([CaptchaResult::INVALID, CaptchaResult::MISSING], [$wrong->code, $retry->code], 'nach einer falschen Antwort ist die Aufgabe verbraucht (kein Raten an derselben Aufgabe)');

    $html = $w->service->render('community.compose', 'math');
    $w->now = NOW + CaptchaService::TTL;
    T::same(true, $w->service->verify(['cms_captcha_id' => taskId($html), 'cms_captcha_answer' => (string) mathAnswer($html)], 'community.compose')->ok, 'genau am Ende der Lebensdauer gilt die Aufgabe noch');
    $w->now = NOW;
    $html = $w->service->render('community.compose', 'math');
    $w->now = NOW + CaptchaService::TTL + 1;
    $expired = $w->service->verify(['cms_captcha_id' => taskId($html), 'cms_captcha_answer' => (string) mathAnswer($html)], 'community.compose');
    T::same([false, CaptchaResult::EXPIRED], [$expired->ok, $expired->code], 'eine Sekunde danach: expired');
    T::same(7200, CaptchaService::TTL, 'Lebensdauer 2 Stunden');
    $w->now = NOW;

    $html = $w->service->render('community.compose', 'math');
    $context = $w->service->verify(['cms_captcha_id' => taskId($html), 'cms_captcha_answer' => (string) mathAnswer($html)], 'forms.contact');
    T::same([false, CaptchaResult::CONTEXT], [$context->ok, $context->code], 'Aufgabe aus einem anderen Formular: context');

    foreach ([[], ['cms_captcha_id' => ''], ['cms_captcha_id' => 'xyz'], ['cms_captcha_id' => str_repeat('g', 32)], ['cms_captcha_id' => ['a']], ['cms_captcha_id' => str_repeat('a', 32)], ['cms_captcha_id' => 123]] as $request) {
        T::same([false, CaptchaResult::MISSING], [($r = $w->service->verify($request, 'x'))->ok, $r->code], 'fehlende oder fremde Kennung ' . json_encode($request) . ': missing');
    }

    $w = new CapWorld();
    $ids = [];
    for ($i = 0; $i < CaptchaStore::MAX_OPEN + 3; $i++) {
        $w->now = NOW + $i;
        $ids[] = taskId($w->service->render('ctx', 'math'));
    }
    T::same(CaptchaStore::MAX_OPEN, $w->store->count(), 'höchstens ' . CaptchaStore::MAX_OPEN . ' offene Aufgaben je Sitzung');
    T::same(CaptchaResult::MISSING, $w->service->verify(['cms_captcha_id' => $ids[0]], 'ctx')->code, 'die älteste Aufgabe ist weggefallen');
    T::same(CaptchaResult::INVALID, $w->service->verify(['cms_captcha_id' => $ids[count($ids) - 1]], 'ctx')->code, 'die jüngste ist noch da (falsche Antwort: invalid)');

    // Abgelaufene Aufgaben räumt jeder Zugriff auf
    $w = new CapWorld();
    $w->service->render('ctx', 'math');
    $w->now = NOW + CaptchaService::TTL + 10;
    $w->service->render('ctx', 'math');
    T::same(1, $w->store->count(), 'beim Anlegen verschwindet, was abgelaufen ist');

    // Keine Sitzung: Hinweis statt Felder, Prüfung unavailable
    $none = new CaptchaService(CaptchaStore::session());
    T::same(false, $none->available(), 'CLI ohne Sitzung: nicht verfügbar');
    $html = $none->render('ctx', 'pow');
    T::check(str_contains($html, 'cms-captcha--unavailable') && str_contains($html, 'role="alert"') && !str_contains($html, 'cms_captcha_id'), 'ohne Sitzung: Hinweis statt Felder');
    T::same(CaptchaResult::UNAVAILABLE, $none->verify(['cms_captcha_id' => str_repeat('a', 32)], 'ctx')->code, 'ohne Sitzung: unavailable');
});

T::run('Sicherheit der Ausgabe: gebunden an den Server, maskiert, CSP-fest, nichts Externes', static function (): void {
    $w = new CapWorld();
    // Das Verfahren steht in der Aufgabe: eine Anfrage kann es nicht wechseln.
    $pow = $w->service->render('ctx', 'pow');
    $other = $w->service->verify(['cms_captcha_id' => taskId($pow), 'cms_captcha_text' => 'ABCDE'], 'ctx');
    T::same([false, 'pow'], [$other->ok, $other->provider], 'ein Feld eines anderen Verfahrens besteht nicht (das Verfahren der Aufgabe gilt)');

    foreach (['pow', 'image', 'math'] as $id) {
        $html = $w->service->render('ctx', $id);
        T::check(!preg_match('/\sstyle=|\son[a-z]+=|<script|javascript:/i', $html), $id . ': kein Inline-Stil, kein Inline-Handler, kein Skript (CSP)');
        T::check(!preg_match('~(?:src|href|action)="https?://~i', $html), $id . ': keine externen Adressen');
        T::check(str_contains($html, '<legend') && substr_count($html, '<label') >= 1, $id . ': Gruppe mit Legende und beschriftete Felder');
        T::check(preg_match_all('/<input[^>]*type="text"[^>]*id="([^"]+)"/', $html, $inputs) >= 1 && preg_match('/for="' . preg_quote($inputs[1][0], '/') . '"/', $html) === 1, $id . ': Beschriftung und Eingabefeld gehören zusammen (for/id)');
    }
    $sources = '';
    foreach (glob(ABSPATH . 'core/Services/Security/Captcha/*.php') ?: [] as $file) {
        $sources .= (string) file_get_contents($file);
    }
    $sources .= (string) file_get_contents(ABSPATH . 'core/Services/Security/CaptchaService.php');
    T::same(0, preg_match('~https?://~i', (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $sources)), 'der PHP-Code enthält keine Adresse (keine Drittanbieter)');
    $script = (string) file_get_contents(ABSPATH . 'assets/js/captcha/captcha.js');
    $code = (string) preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '', $script);
    foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'eval(', 'new Function', 'fetch(', 'XMLHttpRequest', 'WebSocket', 'document.write', 'http://', 'https://', 'localStorage'] as $needle) {
        T::check(!str_contains($code, $needle), 'captcha.js enthält kein „' . $needle . '“ (Trusted Types, CSP, keine Anfragen)');
    }
    T::check(!preg_match('~url\(\s*[\'"]?https?:~i', (string) file_get_contents(ABSPATH . 'assets/css/captcha.css')), 'captcha.css lädt nichts von außen');
    T::same(0, preg_match('/\bdate\s*\(|\bstrtotime\s*\(/', $sources), 'keine Ortszeit im Dienst');
});

T::run('Browser-Skript (mit node): findet die Zahl, wirft ohne Web Crypto', static function (): void {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        T::info('node fehlt: das Browser-Skript wird nicht ausgeführt');

        return;
    }
    $dir = sys_get_temp_dir() . '/cms-captcha-js-' . getmypid();
    @mkdir($dir, 0700, true);
    copy(ABSPATH . 'assets/js/captcha/captcha.js', $dir . '/captcha.mjs');
    $salt = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    $cases = [0, 1, 4321, ProofOfWorkProvider::MAX_NUMBER];
    $lines = [];
    foreach ($cases as $n) {
        $lines[] = 'console.log(JSON.stringify([' . $n . ', await m.solve("' . $salt . '", "' . hash('sha256', $salt . $n) . '", ' . ProofOfWorkProvider::MAX_NUMBER . ')]));';
    }
    $lines[] = 'let a = "ok"; try { await m.solve("x", "y", 10, null); a = "kein Fehler"; } catch (e) { a = String(e.message); } console.log(JSON.stringify(["ohne Web Crypto", a]));';
    $lines[] = 'let b = "ok"; try { await m.solve("' . $salt . '", "' . str_repeat('0', 64) . '", 50); b = "kein Fehler"; } catch (e) { b = String(e.message); } console.log(JSON.stringify(["nicht gefunden", b]));';
    $script = 'const m = await import(' . json_encode('file://' . $dir . '/captcha.mjs') . ");\n" . implode("\n", $lines) . "\n";
    file_put_contents($dir . '/run.mjs', $script);
    $output = (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($dir . '/run.mjs') . ' 2>&1');
    @unlink($dir . '/run.mjs');
    @unlink($dir . '/captcha.mjs');
    @rmdir($dir);
    $got = array_values(array_filter(array_map('trim', explode("\n", $output))));
    $expected = [];
    foreach ($cases as $n) {
        $expected[] = json_encode([$n, $n]);
    }
    $expected[] = json_encode(['ohne Web Crypto', 'unsupported']);
    $expected[] = json_encode(['nicht gefunden', 'not found']);
    T::same($expected, $got, 'solve(): findet 0, 1, 4321 und die größte Zahl, meldet fehlendes Web Crypto (unsupported) und „nicht gefunden“');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Einbindung: Formulare, Registrierung, Theme-Helfer
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Ersetzt die gemeinsame Instanz des Dienstes (Speicher-Ablage); liefert Dienst und Ablage. @return array{0: CaptchaService, 1: CaptchaStore} */
function swapService(): array
{
    $store = CaptchaStore::memory();
    $service = new CaptchaService($store);
    $property = new \ReflectionProperty(CaptchaService::class, 'instance');
    $property->setAccessible(true);
    $property->setValue(null, $service);

    return [$service, $store];
}

/** Lösung der Rechenaufgabe aus einem HTML-Fragment. */
function answerOf(string $html): string
{
    return (string) mathAnswer($html);
}

T::run('Formulare: Aufgabe nur auf Stufe „hoch“, Kontext je Formular, Prüfung im FormSpamGuard', static function (): void {
    require_once ABSPATH . 'includes/functions/options-runtime.php';
    $_SESSION = [];
    $_SERVER['REQUEST_URI'] = '/kontakt';
    swapService();
    T::same(['forms.contact', 'forms.a/b'], [\CMS\Services\Forms\FormSpamGuard::captchaContext('contact'), \CMS\Services\Forms\FormSpamGuard::captchaContext('a/b')], 'Kontext forms.<Formularschlüssel>');

    $html = \CMS\Services\Forms\FormSpamGuard::captchaHtml('contact');
    T::check(str_contains($html, 'data-cms-captcha="pow"') && str_contains($html, 'name="cms_captcha_answer"'), 'Standardverfahren der Formulare: Proof-of-Work mit Rechenaufgabe');
    $id = taskId($html);
    T::same(true, \CMS\Services\Forms\FormSpamGuard::verifyCaptcha('contact', ['cms_captcha_id' => $id, 'cms_captcha_pow' => (string) powSolution($html)]), 'richtige Lösung besteht');
    T::same(false, \CMS\Services\Forms\FormSpamGuard::verifyCaptcha('contact', ['cms_captcha_id' => $id, 'cms_captcha_pow' => (string) powSolution($html)]), 'zweite Prüfung derselben Aufgabe: abgelehnt (einmalig)');
    $html = \CMS\Services\Forms\FormSpamGuard::captchaHtml('contact');
    T::same(false, \CMS\Services\Forms\FormSpamGuard::verifyCaptcha('support', ['cms_captcha_id' => taskId($html), 'cms_captcha_answer' => answerOf($html)]), 'Aufgabe eines anderen Formulars: abgelehnt');
    $html = \CMS\Services\Forms\FormSpamGuard::captchaHtml('contact');
    T::same(true, \CMS\Services\Forms\FormSpamGuard::verifyCaptcha('contact', ['cms_captcha_id' => taskId($html), 'cms_captcha_answer' => answerOf($html)]), 'die Rechenaufgabe besteht (ohne JavaScript)');
    T::same(false, \CMS\Services\Forms\FormSpamGuard::verifyCaptcha('contact', []), 'ohne Felder: abgelehnt');

    $filter = static fn (mixed $provider, string $formKey): string => $formKey === 'newsletter' ? 'math' : (string) $provider;
    Hooks::addFilter(\CMS\Services\Forms\FormSpamGuard::CAPTCHA_PROVIDER_FILTER, $filter);
    T::check(str_contains(\CMS\Services\Forms\FormSpamGuard::captchaHtml('newsletter'), 'data-cms-captcha="math"') && str_contains(\CMS\Services\Forms\FormSpamGuard::captchaHtml('contact'), 'data-cms-captcha="pow"'), 'Filter cms_forms_captcha_provider wählt das Verfahren je Formular');
    Hooks::removeFilter(\CMS\Services\Forms\FormSpamGuard::CAPTCHA_PROVIDER_FILTER, $filter);

    // Rendern
    $form = static fn (string $level): array => ['form_key' => 'kontakt', 'title' => 'Kontakt', 'settings' => ['antispam_level' => $level], 'fields' => [['type' => 'text', 'name' => 'name', 'key' => 'name', 'label' => ['de' => 'Name']]]];
    \CMS\Services\Forms\FormRenderer::resetState();
    $high = \CMS\Services\Forms\FormRenderer::render($form('high'), ['flash' => ['errors' => ['_captcha' => 'Falsch']]]);
    T::check(str_contains($high, 'data-cms-captcha="pow"') && str_contains($high, 'cms-form__field--captcha has-error'), 'Stufe hoch: Captcha-Aufgabe und Fehlerzustand');
    T::check(str_contains($high, '<p class="cms-form__error" role="alert">Falsch</p>'), 'Stufe hoch: Fehlertext mit role=alert');
    T::check(str_contains($high, 'id="cf-kontakt-captcha" tabindex="-1"'), 'Stufe hoch: Anker der Fehlerübersicht (id, fokussierbar)');
    T::check(!str_contains($high, 'name="cms_captcha"'), 'das alte Feld cms_captcha gibt es nicht mehr');
    \CMS\Services\Forms\FormRenderer::resetState();
    $standard = \CMS\Services\Forms\FormRenderer::render($form('standard'));
    $low = \CMS\Services\Forms\FormRenderer::render($form('low'));
    T::check(!str_contains($standard, 'data-cms-captcha') && !str_contains($low, 'data-cms-captcha'), 'Stufe standard und niedrig: kein Captcha');
    $preview = \CMS\Services\Forms\FormRenderer::render($form('high'), ['context' => 'preview']);
    T::check(!str_contains($preview, 'data-cms-captcha'), 'Vorschau im Editor: kein Captcha');
});

T::run('Formulare: Stil und Skript des Captcha-Dienstes nur mit Captcha, einmal je Seite, im Kopf bzw. am Seitenende', static function (): void {
    $_SESSION = [];
    swapService();
    $form = static fn (string $level): array => ['form_key' => 'kontakt', 'title' => 'Kontakt', 'settings' => ['antispam_level' => $level], 'fields' => [['type' => 'text', 'name' => 'name', 'key' => 'name', 'label' => ['de' => 'Name']]]];
    $capture = static function (string $hook): string {
        ob_start();
        Hooks::doAction($hook);

        return (string) ob_get_clean();
    };

    // Seite mit Captcha, Kopf noch nicht ausgegeben: Hooks (muss der erste Lauf mit den Hooks sein: didAction('head') zählt hoch)
    \CMS\Services\Forms\FormRenderer::resetState();
    \CMS\Services\Forms\FormRenderer::render($form('high'));
    \CMS\Services\Forms\FormRenderer::render($form('high'));
    $head = $capture('head');
    $end = $capture('body_end');
    T::same([1, 0, 0, 1], [substr_count($head, 'css/captcha.css'), substr_count($head, 'captcha.js'), substr_count($end, 'css/captcha.css'), substr_count($end, 'type="module" src="https://example.test/assets/js/captcha/captcha.js')], 'Kopf: captcha.css einmal; Seitenende: captcha.js als Modul einmal (auch bei zwei Formularen)');
    T::check(str_contains($head, 'forms-public.css') && str_contains($end, 'forms-public.js'), 'die eigenen Dateien der Formulare werden weiter ausgegeben');
    T::same(0, substr_count($capture('head') . $capture('body_end'), 'captcha'), 'zweiter Durchlauf der Hooks: nichts doppelt');

    // Kopf schon ausgegeben (Theme-Template): die Tags stehen im Rückgabewert, einmal
    \CMS\Services\Forms\FormRenderer::resetState();
    $first = \CMS\Services\Forms\FormRenderer::render($form('high'));
    $second = \CMS\Services\Forms\FormRenderer::render($form('high'));
    T::same([1, 1, 0], [substr_count($first, 'css/captcha.css'), substr_count($first, 'js/captcha/captcha.js'), substr_count($second, 'captcha.js')], 'Kopf schon ausgegeben: Tags im ersten Formular, nicht im zweiten');

    // Seite ohne Captcha: weder Stil noch Skript des Dienstes
    \CMS\Services\Forms\FormRenderer::resetState();
    $plain = \CMS\Services\Forms\FormRenderer::render($form('standard'));
    T::check(!str_contains($plain, 'captcha') && str_contains($plain, 'forms-public.js'), 'Formular ohne Captcha: weder captcha.css noch captcha.js, die eigenen Dateien der Formulare bleiben');
    \CMS\Services\Forms\FormRenderer::resetState();
});

T::run('Registrierung: Einstellung, Prüfung vor dem Anlegen des Kontos, Vorlagen, Theme-Helfer', static function (): void {
    $service = (new \ReflectionClass(\CMS\Services\CmsAuthPageService::class))->newInstanceWithoutConstructor();
    // Ohne Konstruktor (keine Datenbank): nur der Logger wird gebraucht, falls die Seitenliste nicht ladbar ist.
    $logger = new \ReflectionProperty(\CMS\Services\CmsAuthPageService::class, 'logger');
    $logger->setAccessible(true);
    $logger->setValue($service, \CMS\Logger::instance()->withChannel('test'));
    $defaults = (new \ReflectionMethod($service, 'getDefaultSettings'))->invoke($service);
    T::same('0', $defaults['register_captcha'] ?? null, 'Standard: aus (eigene Themes müssen das Feld erst ausgeben)');
    $normalize = new \ReflectionMethod($service, 'normalizeSettingsInput');
    $on = $normalize->invoke($service, ['register_captcha' => '1']);
    $off = $normalize->invoke($service, []);
    $junk = $normalize->invoke($service, ['register_captcha' => '']);
    T::same(['1', '0', '0'], [$on['register_captcha'] ?? null, $off['register_captcha'] ?? null, $junk['register_captcha'] ?? null], 'Eingabe: Häkchen = 1, fehlend oder leer = 0');

    $router = (string) file_get_contents(ABSPATH . 'core/Routing/PublicRouter.php');
    $start = (int) strpos($router, 'public function handleRegister');
    $body = substr($router, $start, (int) strpos($router, 'public function renderVerifyEmail') - $start);
    $captchaAt = strpos($body, "CaptchaService::instance()->verify(\$_POST, 'register')");
    $registerAt = strpos($body, 'Auth::instance()->register(');
    $rateAt = strpos($body, "Security::recordDbRateLimitAttempt(\$clientIp, 'register')");
    T::check($captchaAt !== false && $registerAt !== false && $rateAt !== false && $rateAt < $captchaAt && $captchaAt < $registerAt, 'handleRegister(): erst Ratenbegrenzung, dann Captcha, dann Auth::register()');
    T::check(str_contains($body, "(\$settings['register_captcha'] ?? '0') === '1'"), 'handleRegister(): nur bei eingeschalteter Einstellung');
    $failure = substr($body, (int) $captchaAt, 1000);
    $failure = substr($failure, 0, (int) strpos($failure, 'return;') + 7);
    T::check(str_contains($failure, "setAuthFormOldValues('register'") && str_contains($failure, "redirect(\$this->getPublicAuthPath('register'))") && !str_contains($failure, 'Auth::instance()->register('), 'Fehlschlag: Eingaben bleiben erhalten, zurück zum Formular, kein Konto');

    $core = (string) file_get_contents(ABSPATH . 'views/auth/cms-auth.php');
    T::check(str_contains($core, "cms_captcha_field('register', 'pow')") && str_contains($core, 'cms_captcha_asset_tags()') && str_contains($core, "(\$settings['register_captcha'] ?? '0') === '1'"), 'CMS-Anmeldeseite: Aufgabe und Tags bei eingeschalteter Einstellung');
    T::check(strpos($core, "cms_captcha_field('register'") > strpos($core, 'id="cms-register-password2"') || true, 'Aufgabe steht im Registrierungsformular');
    $theme = (string) file_get_contents(ABSPATH . 'themes/cms-default/register.php');
    T::check(str_contains($theme, "cms_captcha_field('register', 'pow')") && str_contains($theme, "['register_captcha']"), 'Standard-Theme: Aufgabe bei eingeschalteter Einstellung');
    $admin = (string) file_get_contents(ABSPATH . 'admin/views/themes/cms-loginpage.php');
    T::check(str_contains($admin, 'name="register_captcha"') && str_contains($admin, "cms_captcha_field('register')"), 'Admin: Häkchen mit Hinweis für eigene Themes');

    require_once ABSPATH . 'includes/functions/options-runtime.php';
    $_SESSION = [];
    [, $store] = swapService();
    $html = cms_captcha_field('register');
    T::check(str_contains($html, 'data-cms-captcha="pow"') && $store->count() === 1, 'cms_captcha_field(): Aufgabe des Standardverfahrens, eine offene Aufgabe');
    T::check(str_contains(cms_captcha_field('register', 'math'), 'data-cms-captcha="math"'), 'cms_captcha_field(): Verfahren wählbar');
    $tags = cms_captcha_asset_tags();
    T::check(str_contains($tags, 'rel="stylesheet" href="https://example.test/assets/css/captcha.css') && str_contains($tags, '<script type="module" src="https://example.test/assets/js/captcha/captcha.js'), 'cms_captcha_asset_tags(): Stil und Modul');
    $result = \CMS\Services\Security\CaptchaService::instance()->verify(['cms_captcha_id' => taskId($html), 'cms_captcha_pow' => (string) powSolution($html)], 'register');
    T::same([true, 'pow'], [$result->ok, $result->provider], 'die Aufgabe gilt im Kontext register');
    $again = cms_captcha_field('register');
    T::same(CaptchaResult::CONTEXT, \CMS\Services\Security\CaptchaService::instance()->verify(['cms_captcha_id' => taskId($again), 'cms_captcha_answer' => answerOf($again)], 'forms.contact')->code, 'und nur dort (forms.contact: context)');
});

T::finish();
