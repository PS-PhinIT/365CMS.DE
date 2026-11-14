<?php
/**
 * Sicherheitsprüfungen für Anmeldung und Konto (ohne Datenbank): Limit je Konto
 * für den zweiten Faktor, Re-Auth-Limit bei der Passwortänderung, neutrale Registrierung und neutrale
 * Anrede in Bestätigungs-Mails. Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

use CMS\Auth;
use CMS\Auth\AuthManager;

T::run('Zweiter Faktor: Fehlversuche zählen je Konto', static function (): void {
    T::check(!AuthManager::mfaAccountLimitReached(9, 9), '9 Fehlversuche in 15 Minuten sind noch erlaubt');
    T::check(AuthManager::mfaAccountLimitReached(10, 10), '10 Fehlversuche in 15 Minuten sperren das Konto für Codes');
    T::check(!AuthManager::mfaAccountLimitReached(0, 29), '29 Fehlversuche am Tag sind noch erlaubt');
    T::check(AuthManager::mfaAccountLimitReached(0, 30), '30 Fehlversuche in 24 Stunden sperren unabhängig vom 15-Minuten-Fenster');

    $manager = (string) file_get_contents(ABSPATH . 'core/Auth/AuthManager.php');
    $start = (int) strpos($manager, 'public function verifyMfa(');
    $verifyMfa = substr($manager, $start, (int) strpos($manager, 'private function isMfaAccountThrottled(') - $start);
    $accountCheck = strpos($verifyMfa, '$this->isMfaAccountThrottled($accountKey)');
    $record = strpos($verifyMfa, "recordDbRateLimitAttempt(\$clientIp, 'mfa_verify', \$accountKey)");
    $totp = strpos($verifyMfa, '$this->totp()->verifyCode(');
    $backup = strpos($verifyMfa, '$this->backupCodes()->verify(');
    T::check(str_contains($verifyMfa, "\$accountKey = 'user:' . \$userId;"), 'Kennung des Kontolimits ist nur das Konto');
    T::check($accountCheck !== false && $totp !== false && $accountCheck < $totp && $accountCheck < $backup, 'Kontolimit steht vor TOTP- und Backup-Code-Prüfung');
    T::check($record !== false && $record < $totp, 'der Versuch wird vor der Code-Prüfung gezählt');
    T::check(substr_count($verifyMfa, 'releaseDbRateLimitAttempt(') === 4, 'nur Erfolg und Abweisung nehmen den eigenen Eintrag zurück');
    T::check(!str_contains($manager, "DELETE FROM"), 'frühere Fehlversuche werden nie gelöscht');
    T::check(str_contains($manager, "'mfa_lock_notice', 1, 86400"), 'Inhaber wird höchstens einmal in 24 Stunden benachrichtigt');

    $auth = (string) file_get_contents(ABSPATH . 'core/Auth.php');
    T::check(!str_contains($auth, "'mfa_verify'"), 'Passwort-Login setzt den MFA-Zähler nicht zurück');
});

T::run('Passwortänderung: aktuelles Passwort läuft über das Re-Auth-Limit', static function (): void {
    $service = (string) file_get_contents(ABSPATH . 'core/Services/MemberService.php');
    $start = (int) strpos($service, 'public function changePassword(');
    $method = substr($service, $start, (int) strpos($service, 'return true;', $start) - $start);
    $reauth = strpos($method, 'verifyCurrentPassword($userId, $current)');
    T::check($reauth !== false, 'changePassword() nutzt Auth::verifyCurrentPassword()');
    T::check(!str_contains($method, 'Security::verifyPassword('), 'keine eigene Passwortprüfung ohne Zähler');
    T::check($reauth !== false && $reauth < (int) strpos($method, 'UPDATE '), 'geprüft wird vor dem Setzen des neuen Passworts');

    $auth = (string) file_get_contents(ABSPATH . 'core/Auth.php');
    $start = (int) strpos($auth, 'public function verifyCurrentPassword(');
    $verify = substr($auth, $start, 1400);
    T::check(str_contains($verify, "checkDbRateLimit(\$clientIp, 'reauth', 10, 900)"), 'Re-Auth-Limit: 10 Fehlversuche je IP in 15 Minuten');
    T::check(str_contains($verify, "'reauth_failed'"), 'Fehlversuch wird im Audit-Log festgehalten');
});

T::run('Registrierung: Benutzernamen-Prüfung verrät keine registrierte Adresse', static function (): void {
    $auth = (string) file_get_contents(ABSPATH . 'core/Auth.php');
    $start = (int) strpos($auth, 'public function register(');
    $register = substr($auth, $start, (int) strpos($auth, 'public function isEmailVerificationRequired(') - $start);
    $nameCheck = strpos($register, '$this->isRegistrationUsernameReserved($username)');
    $existing = strpos($register, 'if ($existingUserId > 0)');
    $reserve = strpos($register, '$this->reserveRegistration($username, $email, $password)');
    T::check(
        substr_count($register, '$this->claimRegistrationSlot($email)') === 2
            && strpos($register, 'if ($this->claimRegistrationSlot($email))') < $reserve,
        'Reservierungen sind je Adresse begrenzt; die echte Registrierung zählt gleich mit'
    );
    T::check($nameCheck !== false && $existing !== false && $nameCheck < $existing, 'reservierte Namen gelten als vergeben');
    T::check(
        $reserve !== false && $reserve > $existing && $reserve < (int) strpos($register, 'return true;', (int) $existing),
        'bei bereits registrierter Adresse wird der gewählte Name reserviert'
    );

    $start = (int) strpos($auth, 'public function login(');
    $login = substr($auth, $start, (int) strpos($auth, 'public function hasCapability(') - $start);
    T::check(str_contains($login, '$this->isReservedRegistrationLogin($username, $password)'), 'Login erkennt die Zugangsdaten einer reservierten Registrierung');
    T::check(substr_count($login, 'return self::EMAIL_UNVERIFIED_MESSAGE;') === 3, 'unbestätigtes Konto und Reservierung antworten wortgleich');
    T::check(!str_contains($login, 'soeben einen neuen'), 'keine abweichende Meldung nach erneutem Versand');
    T::check(
        (bool) preg_match('/function registrationCredentialKey.*?hash_hmac\(/s', $auth) && !str_contains($auth, "'regpw:' . \$password"),
        'gemerkt wird nur ein HMAC der gewählten Zugangsdaten, nicht das Passwort'
    );
});

T::run('System-Mails an unbestätigte Adressen enthalten keinen frei wählbaren Namen', static function (): void {
    $router = (string) file_get_contents(ABSPATH . 'core/Routing/PublicRouter.php');
    T::check(!str_contains($router, '->register($_POST)'), 'Registrierung übergibt nicht mehr das gesamte $_POST');
    $start = (int) strpos($router, 'public function handleRegister(');
    $handler = substr($router, $start, (int) strpos($router, '$result === true', $start) - $start);
    T::check(!str_contains(substr($handler, (int) strpos($handler, '->register([')), 'display_name'), 'display_name wird aus dem Formular nicht übernommen');

    foreach ([
        'core/Services/EmailVerificationService.php' => 'function sendVerificationMail(',
        'core/Services/EmailChangeService.php' => 'function sendConfirmationMail(',
    ] as $file => $signature) {
        $source = (string) file_get_contents(ABSPATH . $file);
        $method = substr($source, (int) strpos($source, $signature), 1600);
        T::check(str_contains($method, '$body = "Hallo,\n\n"'), basename($file) . ': neutrale Anrede');
        T::check(!str_contains($method, 'display_name') && !str_contains($method, '{$name}'), basename($file) . ': Anzeigename wird nicht eingebettet');
    }
});
