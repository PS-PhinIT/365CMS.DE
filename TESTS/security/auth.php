<?php
/**
 * Sicherheitsprüfungen für Anmeldung und Konto (ohne Datenbank): Konto-Bremse ohne sleep, Limit je Konto
 * für den zweiten Faktor, Re-Auth-Limit bei der Passwortänderung, neutrale Registrierung und neutrale
 * Anrede in Bestätigungs-Mails. Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

use CMS\Auth;
use CMS\Auth\AuthManager;

T::run('Anmeldung: Konto-Bremse weist ab, statt den Worker schlafen zu lassen', static function (): void {
    T::same(0, Auth::accountRetryAfterSeconds(4, 0), 'die ersten fünf Versuche sind frei');
    T::same(1, Auth::accountRetryAfterSeconds(5, 0), 'nach fünf Fehlversuchen 1 s Wartezeit');
    T::same(16, Auth::accountRetryAfterSeconds(9, 0), 'Staffel bleibt unverändert (9 Fehlversuche: 16 s)');
    T::same(30, Auth::accountRetryAfterSeconds(10, 0), 'Obergrenze 30 s');
    T::same(30, Auth::accountRetryAfterSeconds(500, 0), 'Obergrenze gilt unabhängig von der Zahl der Fehlversuche');
    T::same(18, Auth::accountRetryAfterSeconds(10, 12), 'vergangene Zeit seit dem letzten Fehlversuch wird abgezogen');
    T::same(0, Auth::accountRetryAfterSeconds(10, 30), 'nach Ablauf ist der nächste Versuch erlaubt');
    T::same(0, Auth::accountRetryAfterSeconds(10, 3600), 'keine negative Wartezeit');
    T::same(0, Auth::accountRetryAfterSeconds(10, null), 'ohne Zeitpunkt (DB-Fehler) keine Sperre');
    T::same(30, Auth::accountRetryAfterSeconds(10, -5), 'Uhrendifferenz verlängert die Wartezeit nicht über die Obergrenze');

    $auth = (string) file_get_contents(ABSPATH . 'core/Auth.php');
    T::check(preg_match('/\b(?:sleep|usleep|time_nanosleep)\s*\(/', $auth) !== 1, 'Auth.php ruft kein sleep() mehr auf');

    $start = (int) strpos($auth, 'public function login(');
    $login = substr($auth, $start, (int) strpos($auth, 'public function hasCapability(') - $start);
    $reject = strpos($login, 'rejectEarlyAccountLogin($retryAfter');
    $reserve = strpos($login, '$this->logLoginAttempt($username)');
    $recheck = strpos($login, '$maxLoginAttempts + 1');
    $verify = strpos($login, '$security->verifyPassword(');
    T::check($reject !== false && $verify !== false && $reject < $verify, 'zu frühe Versuche werden vor der Passwortprüfung abgewiesen');
    T::check(
        $reserve !== false && $recheck !== false && $reserve < $recheck && $recheck < $verify,
        'der Versuch wird vor der Passwortprüfung gezählt und das IP-Limit danach erneut geprüft'
    );
    T::check($reject !== false && $reserve !== false && $reject < $reserve, 'abgewiesene Versuche schreiben keinen Eintrag (Wartezeit nicht verlängerbar)');
    T::check(substr_count($login, 'recordAccountLoginFailure(') === 1, 'Konto-Fehlversuch entsteht nur nach einer echten Passwortprüfung');
    T::check(str_contains($login, "releaseDbRateLimitAttempt(\$clientIp, 'login', \$username)"), 'erfolgreiche Anmeldung nimmt den eigenen Eintrag zurück');
    $busy = strpos($login, 'return self::LOGIN_BUSY_MESSAGE;');
    T::check(
        $busy !== false && $busy < $reject && str_contains(substr($login, 0, $busy), "\$this->logLoginAttempt(\$username, 'login')"),
        'belegte Kontosperre: neutrale Meldung, Anfrage zählt am IP-Limit'
    );
    T::check(str_contains($auth, "'SELECT GET_LOCK(?, ' . self::ACCOUNT_LOGIN_LOCK_WAIT . ')'"), 'Kontosperre wartet kurz auf eine laufende Prüfung');
    T::check(str_contains($auth, 'Passwort vergessen“ zurück'), 'Meldung der Konto-Bremse nennt den Passwort-Reset als Ausweg');
    T::check(
        str_contains((string) file_get_contents(ABSPATH . 'core/Routing/PublicRouter.php'), "header('Retry-After: ' . \$retryAfter)"),
        'Login-Antwort trägt Retry-After'
    );
});

T::run('Zweiter Faktor: Fehlversuche zählen je Konto', static function (): void {
    T::check(!AuthManager::mfaAccountLimitReached(9, 9), '9 Fehlversuche in 15 Minuten sind noch erlaubt');
    T::check(AuthManager::mfaAccountLimitReached(10, 10), '10 Fehlversuche in 15 Minuten sperren das Konto für Codes');
    T::check(!AuthManager::mfaAccountLimitReached(0, 29), '29 Fehlversuche am Tag sind noch erlaubt');
    T::check(AuthManager::mfaAccountLimitReached(0, 30), '30 Fehlversuche in 24 Stunden sperren unabhängig vom 15-Minuten-Fenster');
    T::check(!AuthManager::mfaAccountLimitReached(0, 0, 59), '59 Fehlversuche über alle Geräte-Zähler sind noch erlaubt');
    T::check(AuthManager::mfaAccountLimitReached(0, 0, 60), 'frischer Geräte-Zähler (nach Passwort-Reset) hilft nicht über die Obergrenze je Konto');
    T::check(
        str_contains((string) file_get_contents(ABSPATH . 'core/Auth/AuthManager.php'), "countDbIdentifierFamilyAttempts('user:' . \$userId, 'mfa_verify'"),
        'Obergrenze zählt alle Kennungen des Kontos'
    );

    $manager = (string) file_get_contents(ABSPATH . 'core/Auth/AuthManager.php');
    $start = (int) strpos($manager, 'public function verifyMfa(');
    $verifyMfa = substr($manager, $start, (int) strpos($manager, 'private function isMfaAccountThrottled(') - $start);
    $accountCheck = strpos($verifyMfa, '$this->isMfaAccountThrottled($accountKey, $userId)');
    $record = strpos($verifyMfa, "recordDbRateLimitAttempt(\$clientIp, 'mfa_verify', \$accountKey)");
    $totp = strpos($verifyMfa, '$this->totp()->verifyCode(');
    $backup = strpos($verifyMfa, '$this->backupCodes()->verify(');
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
    T::check(substr_count($login, 'return self::EMAIL_UNVERIFIED_MESSAGE;') === 2, 'unbestätigtes Konto und Reservierung antworten wortgleich');
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

T::run('Konto-Bremse und 2FA-Limit: bekanntes Gerät zählt für sich (keine Aussperrung durch Dritte)', static function (): void {
    $key = 'server-key|fingerprint-a';
    $now = 1_800_000_000;
    $token = Auth::buildKnownDeviceToken(7, '0123456789abcdef', $now + 3600, $key);

    T::same('0123456789abcdef', Auth::parseKnownDeviceToken($token, 7, $key, $now), 'gültiges Cookie liefert die Geräte-Kennung');
    T::same(null, Auth::parseKnownDeviceToken($token, 8, $key, $now), 'Cookie eines anderen Kontos zählt nicht');
    T::same(null, Auth::parseKnownDeviceToken($token, 7, 'server-key|fingerprint-b', $now), 'nach Passwortwechsel (neuer Fingerprint) ungültig');
    T::same(null, Auth::parseKnownDeviceToken($token, 7, $key, $now + 3601), 'abgelaufenes Cookie zählt nicht');
    T::same(null, Auth::parseKnownDeviceToken('', 7, $key, $now), 'ohne Cookie kein bekanntes Gerät');
    T::same(null, Auth::parseKnownDeviceToken('nicht-base64!', 7, $key, $now), 'kaputter Wert wird abgelehnt');
    // Selbst gebautes Cookie ohne Kenntnis des Schlüssels.
    $forged = rtrim(strtr(base64_encode('7|0123456789abcdef|' . ($now + 3600) . '|' . hash_hmac('sha256', '7|0123456789abcdef|' . ($now + 3600), 'geraten')), '+/', '-_'), '=');
    T::same(null, Auth::parseKnownDeviceToken($forged, 7, $key, $now), 'gefälschte Signatur wird abgelehnt');
    T::same(null, Auth::parseKnownDeviceToken(Auth::buildKnownDeviceToken(7, 'x|y', $now + 3600, $key), 7, $key, $now), 'Kennung mit Trennzeichen wird abgelehnt');

    $auth = (string) file_get_contents(ABSPATH . 'core/Auth.php');
    T::check(str_contains($auth, "'uid:' . (int) \$user->id . \$this->knownDeviceKeySuffix((int) \$user->id)"), 'Login: Zähler und Prüfsperre je bekanntem Gerät');
    T::check(
        (bool) preg_match('/function completeAuthenticatedSession.*?rememberKnownDevice\(/s', $auth),
        'Cookie entsteht erst nach vollständiger Anmeldung (inkl. 2FA)'
    );
    $manager = (string) file_get_contents(ABSPATH . 'core/Auth/AuthManager.php');
    T::check(str_contains($manager, "'user:' . \$userId . \$this->sessionAuth->knownDeviceKeySuffix(\$userId)"), '2FA-Limit je Konto: bekanntes Gerät zählt für sich');
    $reset = (string) file_get_contents(ABSPATH . 'core/Services/CmsAuthPageService.php');
    T::check(str_contains($reset, 'Auth::instance()->trustCurrentDevice('), 'abgeschlossener Passwort-Reset macht das Gerät bekannt (Ausweg für den Inhaber)');
});
