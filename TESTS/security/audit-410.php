<?php
/**
 * Regressionstests zur Release-Prüfung 4.1.0 (PR #1, Sicherheit): Befunde SEC-410-xx aus
 * `DOC/audit/RELEASE-AUDIT-4.1.0-2026-11-15.md`. Ohne Datenbank (reine Funktionen und Quelltext-Verträge).
 * Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

use CMS\Services\Content\ContentWorkflow;

$auditSource = static fn (string $file): string => (string) file_get_contents(ABSPATH . $file);

/** Ausschnitt einer Methode bis zur nächsten Methode. */
$auditMethod = static function (string $source, string $signature): string {
    $start = strpos($source, $signature);
    if ($start === false) {
        return '';
    }
    $next = preg_match('/\n    (?:public|private|protected)(?: static)? function /', $source, $m, PREG_OFFSET_CAPTURE, $start + strlen($signature))
        ? (int) $m[0][1]
        : strlen($source);

    return substr($source, $start, $next - $start);
};

T::run('SEC-410-09: API-Tokens unterliegen den Kontoregeln', static function () use ($auditSource, $auditMethod): void {
    $auth = $auditSource('core/Auth.php');
    $policies = $auditMethod($auth, 'private static function apiTokenPoliciesAllow(');
    T::check(str_contains($policies, 'EntraSettings::sessionMayContinue('), 'Sperre „Lokale Anmeldung“');
    T::check(str_contains($policies, '->isRequiredFor($user)') && str_contains($policies, '->hasRecognisedFactor($userId)'), 'MFA-Zwang ohne Faktor');
    T::check(str_contains($policies, 'PasswordPolicyService::getInstance()->isExpired($userId)'), 'abgelaufenes Passwort');
    T::check(str_contains($auditMethod($auth, 'public function authenticateBearerToken('), 'self::apiTokenPoliciesAllow($user, $payload)'), 'Bearer-Zugriff prüft die Regeln');
    T::check(str_contains($auditMethod($auth, 'public function isValidApiTokenSubject('), 'self::apiTokenPoliciesAllow($user, $payload)'), 'Refresh prüft die Regeln');
});

T::run('SEC-410-13/14: Freigaberecht für private und geplante Inhalte', static function () use ($auditSource): void {
    T::same('pending', ContentWorkflow::resolveSaveStatus('published', 'private', false)['status'], 'privat → veröffentlicht ohne Recht wird eingereicht');
    T::same('published', ContentWorkflow::resolveSaveStatus('published', 'private', true)['status'], 'mit Recht: veröffentlicht');
    T::same('private', ContentWorkflow::resolveSaveStatus('private', 'published', false)['status'], 'veröffentlicht → privat bleibt ohne neue Freigabe möglich');
    $calendar = $auditSource('core/Services/EditorialCalendarService.php');
    $guard = strpos($calendar, 'ContentWorkflow::canPublishWith($type, $userId');
    T::check($guard !== false && $guard < strpos($calendar, '$checklistError = $this->checkPublishChecklist($row, $newValue);'), 'Kalender: Termin ändern/sofort veröffentlichen erst nach Freigaberecht');
});

T::run('SEC-410-15/22: TOTP-Codes und Backup-Codes werden atomar verbraucht', static function () use ($auditSource, $auditMethod): void {
    $claim = $auditMethod($auditSource('core/Auth/AuthManager.php'), 'private function claimTotpCode(');
    T::check(str_contains($claim, "explode(';',") && str_contains($claim, 'array_slice($entries, -10)'), 'alle Codes im Prüffenster gemerkt (nicht nur der letzte)');
    T::check(str_contains($claim, 'AND meta_value = ?') && str_contains($claim, 'INSERT IGNORE'), 'Vergleich-und-Tausch statt Überschreiben');
    $backup = $auditMethod($auditSource('core/Auth/MFA/BackupCodesManager.php'), 'public function verify(');
    T::check(str_contains($backup, 'AND meta_value = ?') && str_contains($backup, '->rowCount() === 1'), 'Backup-Code: nur eine Anfrage verbraucht den Code');
});

T::run('SEC-410-17/18: Passkey-Prüfung', static function () use ($auditSource, $auditMethod): void {
    $lib = $auditSource('assets/webauthn/WebAuthn.php');
    $origin = $auditMethod($lib, 'private function _checkOrigin(');
    T::check(str_contains($origin, "\\str_ends_with(\$host, '.' . \$rpId)") && !str_contains($origin, "preg_quote(\$this->_rpId) . '\$/i'"), 'RP-ID nur exakt oder als Subdomain mit Punktgrenze');

    $passkey = $auditMethod($auditSource('core/Auth/AuthManager.php'), 'public function authenticateViaPasskey(');
    T::check(str_contains($passkey, "checkDbRateLimit(\$clientIp, 'passkey_login'"), 'Rate-Limit je IP');
    T::check(!str_contains($passkey, "'Passkey nicht gefunden.'") && substr_count($passkey, 'return self::PASSKEY_FAILED_MESSAGE;') === 2, 'einheitliche Fehlermeldung (keine Credential-Aufzählung)');
    $public = $auditSource('core/Routing/PublicRouter.php');
    T::check(str_contains($public, "\$_SESSION['login_passkey_challenge_at'] = time();") && str_contains($public, '$challengeAt < time() - 300'), 'Login-Challenge höchstens 5 Minuten gültig');
});

T::run('SEC-410-16/19/23: Passwortvergabe', static function () use ($auditSource, $auditMethod): void {
    $users = $auditSource('core/Services/UserService.php');
    T::check(str_contains($users, "if (isset(\$data['password']) && (string)\$data['password'] !== '') {"), 'Admin-Bearbeitung prüft auch Passwörter nur aus Leerzeichen');
    $reset = $auditSource('core/Services/CmsAuthPageService.php');
    $claim = strpos($reset, "\"DELETE FROM {\$this->prefix}password_resets WHERE token = ?\"");
    $update = strpos($reset, "\"UPDATE {\$this->prefix}users SET password = ? WHERE email = ?\"");
    T::check($claim !== false && $update !== false && $claim < $update, 'Reset-Link wird vor dem Passwortwechsel atomar eingelöst');
    $installer = $auditSource('install/InstallerController.php');
    T::check(str_contains($installer, "preg_match('/\\p{Lu}/u', \$adminPassword)"), 'Installer: Zeichenklassen der Standard-Richtlinie');
    T::check(str_contains($auditSource('install/InstallerService.php'), 'password_history (user_id, password_hash, created_at)'), 'Installer: erster Historien-Eintrag und password_changed_at');
});

T::run('SEC-410-20/21/24 und JSON-Konfiguration', static function () use ($auditSource): void {
    $downloads = $auditSource('core/Services/Downloads/DownloadService.php');
    T::check(str_contains($downloads, '$knownLevel = in_array($level, DownloadSettings::ACCESS_LEVELS, true);') && str_contains($downloads, "if (!\$knownLevel) {\n            return self::ACCESS_ROLE;"), 'Downloads: unbekannte Zugriffsstufe sperrt');
    T::check(str_contains($auditSource('admin/modules/downloads/DownloadsModule.php'), "['=', '+', '-', '@', \"\\t\", \"\\r\"]"), 'Download-Statistik: CSV-Formel-Injection abgefangen');
    foreach (['admin/views/legal/cookies.php', 'admin/views/legal/data-requests.php', 'admin/views/plugins/marketplace.php'] as $view) {
        $source = $auditSource($view);
        preg_match_all('/<script type="application\/json"[^>]*><\?php echo json_encode\(([^;]+)\); \?><\/script>/', $source, $matches);
        T::check($matches[1] !== [] && array_filter($matches[1], static fn (string $args): bool => !str_contains($args, 'JSON_HEX_TAG')) === [], basename($view) . ': JSON-Block mit JSON_HEX_TAG');
    }
    $posts = $auditSource('admin/posts.php');
    T::check(str_contains($posts, "if (in_array(\$action, ['save_category', 'delete_category'], true)) {"), 'Kategorien nur mit edit_all_posts/manage_settings');
});

T::run('SEC-410-12: Backup-Verschlüsselung auf /admin/backup-settings', static function () use ($auditSource): void {
    $page = $auditSource('admin/backup-settings.php');
    $mark = strpos($page, "cms_admin_section_shell_mark_csrf_verified('admin_backups');");
    T::check($mark !== false && $mark < strpos($page, '->setEncryption('), 'eingelöstes Seiten-Token gilt auch für BackupsModule');
});
