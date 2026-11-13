<?php
/**
 * Bestätigungslinks aus E-Mails (`/cms-verify-email`, `/cms-confirm-email-change`) ohne Datenbank: Der Abruf per
 * GET prüft nur lesend und zeigt eine Schaltfläche, erst deren POST löst ein. Mailfilter, die Links vorab abrufen,
 * dürfen keine Adresse bestätigen – auf diesem Nachweis beruhen Datenfreigaben (matchableEmail()).
 * Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

/** Quelltext einer Methode: von ihrer Signatur bis zur nächsten Methode der Klasse. */
function emailLinkMethod(string $source, string $name): string
{
    if (preg_match('/\n    (?:public|private) function ' . preg_quote($name, '/') . '\(/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
        return '';
    }
    $start = (int) $match[0][1] + 1;
    $next = preg_match('/\n    (?:\/\*\*|(?:public|private|protected) (?:static )?function )/', $source, $end, PREG_OFFSET_CAPTURE, $start + 10) === 1
        ? (int) $end[0][1]
        : strlen($source);

    return substr($source, $start, $next - $start);
}

T::run('Bestätigungslinks: GET prüft nur, erst der POST löst ein', static function (): void {
    $router = (string) file_get_contents(ABSPATH . 'core/Routing/PublicRouter.php');

    foreach ([
        'EmailVerificationService' => ['renderVerifyEmail', 'handleVerifyEmail', '->verify('],
        'EmailChangeService' => ['renderConfirmEmailChange', 'handleConfirmEmailChange', '->confirm('],
    ] as $service => [$getHandler, $postHandler, $redeem]) {
        T::check(str_contains($router, "addRoute('GET', Services\\{$service}::PATH, [\$this, '{$getHandler}'])"), "{$service}: GET zeigt nur die Bestätigungsseite");
        T::check(str_contains($router, "addRoute('POST', Services\\{$service}::PATH, [\$this, '{$postHandler}'])"), "{$service}: POST löst ein");
        T::check(!str_contains($router, "addRoute('GET', Services\\{$service}::PATH, [\$this, '{$postHandler}'])"), "{$service}: einlösender Handler hängt nicht an GET");

        $get = emailLinkMethod($router, $getHandler);
        T::check($get !== '' && str_contains($get, "Services\\{$service}::getInstance()->peek("), "{$getHandler}() prüft über peek()");
        T::check(!str_contains($get, $redeem) && !str_contains($get, '$_POST'), "{$getHandler}() ruft keine einlösende Methode auf");

        $post = emailLinkMethod($router, $postHandler);
        T::check($post !== '' && str_contains($post, $redeem), "{$postHandler}() löst ein");
        T::check(str_contains($post, 'emailConfirmationInput($_POST)') && !str_contains($post, '$_GET'), "{$postHandler}() liest uid/Token nur aus dem Formular");
    }

    // Keine weitere Stelle im Kern löst die Links ein (sonst gäbe es wieder einen GET-Weg an der Seite vorbei).
    $callers = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABSPATH, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', (string) $file);
        if (!str_ends_with($path, '.php') || str_contains($path, '/vendor/') || str_contains($path, '/assets/')) {
            continue;
        }
        if (preg_match('/Email(?:VerificationService::getInstance\(\)->verify|ChangeService::getInstance\(\)->confirm)\(/', (string) file_get_contents($path)) === 1) {
            $callers[] = substr($path, strlen(ABSPATH));
        }
    }
    T::same(['core/Routing/PublicRouter.php'], $callers, 'verify()/confirm() werden nur vom POST-Handler aufgerufen');
});

T::run('Bestätigungslinks: peek() ändert keinen Zustand und verbraucht den Token nicht', static function (): void {
    $writes = ['setMeta(', 'deleteMeta(', 'clearPending(', 'activate(', 'applyEmail(', 'assignGuestOrders(', 'markVerifiedByEmail(',
        'confirmActiveAddress(', '->execute(', 'AuditLogger', '$this->audit(', 'Hooks::', 'META_VERIFIED_AT'];
    foreach (['EmailVerificationService', 'EmailChangeService'] as $service) {
        $peek = emailLinkMethod((string) file_get_contents(ABSPATH . 'core/Services/' . $service . '.php'), 'peek');
        T::check($peek !== '' && str_contains($peek, "'pending' => true"), "{$service}::peek() meldet einen gültigen Link als wartend");
        foreach ($writes as $write) {
            T::check(!str_contains($peek, $write), "{$service}::peek() enthält kein {$write}");
        }
        T::check(str_contains($peek, 'hash_equals(') && str_contains($peek, 'checkDbRateLimit('), "{$service}::peek() vergleicht zeitkonstant und beachtet das IP-Limit");
    }
});

T::run('Bestätigungslinks: Seite mit Schaltfläche, Token im Formular, POST ohne Sitzung möglich', static function (): void {
    $router = (string) file_get_contents(ABSPATH . 'core/Routing/PublicRouter.php');
    $input = emailLinkMethod($router, 'emailConfirmationInput');
    T::check(str_contains($input, "header('Referrer-Policy: no-referrer', true)"), 'Seite und POST-Antwort geben keinen Referrer weiter');
    $page = emailLinkMethod($router, 'renderEmailConfirmationPage');
    T::check(str_contains($page, "'action' => Services\\Feed\\FeedI18n::path(\$path, \$locale),") && !str_contains($page, 'query'), 'Formularziel ist der Pfad ohne Query (Token nicht in der POST-Adresse)');

    $view = (string) file_get_contents(ABSPATH . 'views/auth/cms-auth.php');
    $start = (int) strpos($view, "<?php elseif (\$pageType === 'confirm-email'): ?>");
    $form = substr($view, $start, (int) strpos($view, '</form>', $start) - $start);
    T::check($start > 0 && str_contains($form, 'method="POST"') && str_contains($form, '<button class="cms-auth-button" type="submit">'), 'Bestätigung ist ein POST-Formular mit echter Schaltfläche');
    T::check(str_contains($form, 'name="uid"') && str_contains($form, 'name="token"'), 'uid und Token stehen als Formularfelder');
    T::check(substr_count($form, '<?php echo ') === substr_count($form, '<?php echo htmlspecialchars('), 'alle Ausgaben des Formulars sind escaped');

    // Der Aufruf kommt aus dem Mailprogramm ohne Sitzung (SameSite=Strict): Geheimnis ist der Bestätigungs-Token.
    $core = (string) file_get_contents(ABSPATH . 'core/Router.php');
    preg_match('/\$csrfBypassExact = \[[^\]]*\]/', $core, $bypass);
    preg_match('/\$exempt = \[[^\]]*\]/', $core, $maintenance);
    foreach (['/cms-verify-email', '/cms-confirm-email-change'] as $path) {
        T::check(str_contains($bypass[0] ?? '', "'{$path}'"), "{$path}: POST läuft nicht in den globalen form_guard-Schutz");
        T::check(str_contains($maintenance[0] ?? '', "'{$path}'"), "{$path}: im Wartungsmodus erreichbar");
        T::check(preg_match("/'" . preg_quote($path, '/') . "'[^\\]]*\\], true\\)\\) \\{\\s*\\\$cache->sendResponseHeaders\\('private'\\)/", $core) === 1, "{$path}: Antwort ist privat/nicht cachebar");
    }

    foreach (['de', 'en'] as $locale) {
        $yaml = (string) file_get_contents(ABSPATH . 'lang/' . $locale . '.yaml');
        foreach (['title', 'text_verify', 'text_change', 'button', 'hint'] as $key) {
            T::check(str_contains($yaml, '"auth.confirm_email.' . $key . '": "'), "Sprachschlüssel auth.confirm_email.{$key} ({$locale})");
        }
    }
});
