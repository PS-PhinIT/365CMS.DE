<?php
/**
 * Sicherheitsprüfungen für das Formularmodul (ohne Datenbank): signierte Startzeit, Rate-Limit-Reihenfolge
 * und entschärfte Eingangsbestätigung. Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

use CMS\Services\Forms\FormMailer;
use CMS\Services\Forms\FormSpamGuard;

T::run('Formulare: signierte Startzeit ist an Formular und Zeitfenster gebunden', static function (): void {
    $now = time();
    $fresh = FormSpamGuard::timestampToken('kontakt', $now - 10);
    T::same($now - 10, FormSpamGuard::verifyTimestamp($fresh, 'kontakt'), 'frisches Token wird akzeptiert');
    T::same(null, FormSpamGuard::verifyTimestamp($fresh, 'bewerbung'), 'Token eines anderen Formulars wird abgelehnt');
    T::same(
        null,
        FormSpamGuard::verifyTimestamp(FormSpamGuard::timestampToken('kontakt', $now - FormSpamGuard::MAX_AGE - 5), 'kontakt'),
        'Token älter als 24 Stunden wird abgelehnt'
    );
    T::same(
        $now - FormSpamGuard::MAX_AGE + 60,
        FormSpamGuard::verifyTimestamp(FormSpamGuard::timestampToken('kontakt', $now - FormSpamGuard::MAX_AGE + 60), 'kontakt'),
        'Token knapp innerhalb des Fensters gilt'
    );
    T::same(null, FormSpamGuard::verifyTimestamp(FormSpamGuard::timestampToken('kontakt', $now + 600), 'kontakt'), 'Token aus der Zukunft wird abgelehnt');
    T::same(null, FormSpamGuard::verifyTimestamp(($now - 10) . '.' . str_repeat('0', 32), 'kontakt'), 'gefälschte Signatur wird abgelehnt');
    T::same(null, FormSpamGuard::verifyTimestamp('', 'kontakt'), 'leeres Token wird abgelehnt');
});

T::run('Formulare: Rate-Limit zählt vor der Verarbeitung', static function (): void {
    $guard = (string) file_get_contents(ABSPATH . 'core/Services/Forms/FormSpamGuard.php');
    $start = strpos($guard, 'public static function check(');
    $check = substr($guard, (int) $start, (int) strpos($guard, 'public static function claimConfirmation(') - (int) $start);
    $record = strpos($check, 'Security::recordDbRateLimitAttempt($ip, $action)');
    $recheck = strpos($check, '$limit + 1');
    T::check($record !== false, 'check() zählt den Versuch selbst');
    T::check($record !== false && $recheck !== false && $record < $recheck, 'nach dem Zählen wird erneut geprüft (eigener Eintrag zählt mit)');
    T::check(
        $record !== false && $record < (int) strpos($check, 'AntispamService::getInstance()'),
        'gezählt wird vor AntiSpam-Prüfung, Speichern und Mailversand'
    );
    T::check(!str_contains($guard, 'function recordSubmission'), 'nachträgliches Zählen (recordSubmission) ist entfernt');

    $controller = (string) file_get_contents(ABSPATH . 'core/Services/Forms/FormFrontendController.php');
    T::check(!str_contains($controller, 'recordSubmission('), 'Controller zählt nicht ein zweites Mal');
    T::check(str_contains($controller, "'ts' => FormSpamGuard::timestampToken("), '422-Antwort liefert eine frische Startzeit');
    T::check(
        str_contains((string) file_get_contents(ABSPATH . 'core/Services/Forms/FormRenderer.php'), 'FormSpamGuard::timestampToken($key)'),
        'Formular rendert die Startzeit mit Formularschlüssel'
    );
    T::check(str_contains($guard, "'form_rej_'") && str_contains($guard, 'REJECTION_LOG_LIMIT'), 'protokollierte Abweisungen sind je IP begrenzt');
});

T::run('Formulare: Eingangsbestätigung spiegelt keine Links und keine langen Freitexte', static function (): void {
    $spam = 'Gewinn! Jetzt klicken: https://evil.example/x?a=1 oder www.evil.example oder evil-shop.com/pay oder hxxp://evil.example';
    $vars = FormMailer::confirmationVars([
        '{form_title}' => 'Kontakt',
        '{site_name}' => 'Meine Seite',
        '{name}' => 'Besuche evil.example',
        '{email}' => 'opfer@example.org',
        '{subject}' => "Betreff\r\nBcc: x@example.org http://evil.example",
        '{message}' => $spam . ' ' . str_repeat('A ', 2000),
        '{fields}' => "Name: Max\nE-Mail: opfer@example.org\nNachricht: " . $spam,
        '{field:message}' => $spam,
        '{field:company}' => 'Muster GmbH',
    ]);

    T::same('', $vars['{name}'], 'Name mit Domain-Muster wird geleert');
    T::same('Kontakt', $vars['{subject}'], 'Betreff mit Link fällt auf den Formulartitel zurück');
    T::same('opfer@example.org', $vars['{email}'], 'eigene Adresse bleibt unverändert');
    T::same('Muster GmbH', $vars['{field:company}'], 'harmlose Feldwerte bleiben unverändert');
    T::same('Meine Seite', $vars['{site_name}'], 'Betreiber-Platzhalter bleiben unverändert');
    foreach (['{message}', '{fields}', '{field:message}'] as $key) {
        T::check(
            preg_match('~https?://|hxxp://|www\.|evil\.example|evil-shop\.com~i', $vars[$key]) !== 1,
            $key . ' enthält keine verlinkbare Adresse mehr',
            $vars[$key]
        );
    }
    T::check(str_contains($vars['{fields}'], 'opfer@example.org'), 'E-Mail-Adressen in der Feldliste bleiben lesbar');
    T::check(mb_strlen($vars['{message}']) <= 502, 'Nachricht wird auf 500 Zeichen gekürzt', (string) mb_strlen($vars['{message}']));

    $plain = FormMailer::confirmationVars(['{form_title}' => 'Kontakt', '{name}' => 'Dr. Erika Müller-Lüdenscheidt', '{subject}' => '', '{message}' => "Hallo,\nz. B. morgen um 10.30 Uhr."]);
    T::same('Dr. Erika Müller-Lüdenscheidt', $plain['{name}'], 'gewöhnlicher Name bleibt erhalten');
    T::same("Hallo,\nz. B. morgen um 10.30 Uhr.", $plain['{message}'], 'gewöhnlicher Text bleibt unverändert');
    T::same(60, mb_strlen(rtrim(FormMailer::confirmationVars(['{name}' => str_repeat('a', 300)])['{name}'], ' …')), 'langer Name wird auf 60 Zeichen gekürzt');

    $mailer = (string) file_get_contents(ABSPATH . 'core/Services/Forms/FormMailer.php');
    $start = (int) strpos($mailer, 'public static function sendConfirmation(');
    $send = substr($mailer, $start, (int) strpos($mailer, 'public static function placeholders(') - $start);
    $claim = strpos($send, 'FormSpamGuard::claimConfirmation(');
    T::check($claim !== false && $claim < (int) strpos($send, 'self::dispatch('), 'Limit je Empfängeradresse greift vor dem Versand');
    T::check(str_contains($send, 'self::confirmationVars($vars)'), 'Bestätigung nutzt die entschärften Platzhalter');

    foreach (['de', 'en'] as $locale) {
        $yaml = (string) file_get_contents(ABSPATH . 'lang/' . $locale . '.yaml');
        T::check(
            preg_match('/^\s*"forms\.mail\.confirmation_body":\s*(.+)$/m', $yaml, $match) === 1
                && preg_match('/\{(?:name|fields|message|subject|field:)/', $match[1]) !== 1,
            'Standardvorlage (' . $locale . ') enthält keine Eingabe-Platzhalter'
        );
        T::check(str_contains($yaml, '"forms.admin.confirmation_hint"'), 'Hinweistext (' . $locale . ') vorhanden');
    }

    $migrator = (string) file_get_contents(ABSPATH . 'core/Services/PluginMigration/ContactPluginMigrator.php');
    T::check(
        str_contains($migrator, "\$level = \$level === 'low' ? 'standard' : \$level;") && str_contains($migrator, '$rateLimit = $rateLimit > 0 ? $rateLimit : 3;'),
        'Migration übernimmt Bestätigung nie ohne Mindestzeit und Rate-Limit'
    );
});
