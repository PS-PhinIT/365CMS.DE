<?php
/**
 * Member Service
 *
 * Business-Logik für den eingeloggten Member-Bereich:
 * Profil, Passwort, 2FA, Benachrichtigungen, Datenschutz,
 * Subscription und Dashboard-Daten.
 *
 * @package CMS\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Auth\MFA\BackupCodesManager;
use CMS\Auth\Passkey\WebAuthnAdapter;
use CMS\Database;
use CMS\Json;
use CMS\Security;

if (!defined('ABSPATH')) {
    exit;
}

class MemberService
{
    private static ?self $instance = null;
    private Database $db;
    private string $prefix;

    private const PUBLIC_PROFILE_FIELDS = [
        'avatar' => ['label' => 'Profilbild', 'type' => 'image'],
        'bio' => ['label' => 'Über mich', 'type' => 'multiline'],
        'company' => ['label' => 'Unternehmen', 'type' => 'text'],
        'position' => ['label' => 'Position', 'type' => 'text'],
        'website' => ['label' => 'Website', 'type' => 'url'],
        'location' => ['label' => 'Ort', 'type' => 'text'],
        'social' => ['label' => 'Social / Profil-Link', 'type' => 'url'],
        'phone' => ['label' => 'Telefon', 'type' => 'text'],
        'first_name' => ['label' => 'Vorname', 'type' => 'text'],
        'last_name' => ['label' => 'Nachname', 'type' => 'text'],
    ];

    // ── Singleton ─────────────────────────────────────────────────────────────

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->db     = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    // ── Profil ────────────────────────────────────────────────────────────────

    /**
     * Informiert die bisherige Adresse über eine E-Mail-Änderung (Kontoübernahme-Warnung).
     * Die neue Adresse wird nur maskiert genannt.
     */
    private function notifyPreviousEmailAddress(int $userId, string $previousEmail, string $newEmail): void
    {
        $siteName = defined('SITE_NAME') ? (string) SITE_NAME : '365CMS';
        $siteUrl = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
        $at = strrpos($newEmail, '@');
        $maskedNew = $at === false
            ? '***'
            : mb_substr($newEmail, 0, 1) . '***' . substr($newEmail, $at);

        $body = "Hallo,\n\n"
            . "die E-Mail-Adresse deines Kontos bei {$siteName} wurde soeben von dieser Adresse auf {$maskedNew} geändert.\n"
            . 'Zeitpunkt: ' . date('d.m.Y H:i') . "\n"
            . 'IP-Adresse: ' . Security::getClientIp() . "\n\n"
            . "Warst du das nicht, antworte bitte umgehend auf diese Nachricht oder wende dich an den Betreiber"
            . ($siteUrl !== '' ? " ({$siteUrl})" : '') . ". Ändere außerdem dein Passwort.\n\n"
            . "Diese Nachricht wurde automatisch an deine bisherige Adresse gesendet.\n";

        $sent = false;
        try {
            $sent = MailService::getInstance()->sendPlain($previousEmail, '[' . $siteName . '] E-Mail-Adresse deines Kontos geändert', $body);
        } catch (\Throwable $e) {
            \CMS\Logger::instance()->withChannel('member.profile')->warning('Hinweis an bisherige E-Mail-Adresse konnte nicht versendet werden.', [
                'user_id' => $userId,
                'exception' => $e->getMessage(),
            ]);
        }

        try {
            \CMS\AuditLogger::instance()->log(
                \CMS\AuditLogger::CAT_USER,
                'user.email_changed',
                $sent
                    ? 'E-Mail-Adresse im Mitgliederbereich geändert; bisherige Adresse benachrichtigt.'
                    : 'E-Mail-Adresse im Mitgliederbereich geändert; Hinweis an bisherige Adresse konnte nicht versendet werden.',
                'user',
                $userId,
                ['notice_sent' => $sent],
                'warning'
            );
        } catch (\Throwable) {
        }
    }

    /**
     * Profildaten aktualisieren.
     *
     * @param int   $userId
    * @param array $data  Keys: display_name, username, first_name, last_name, email, bio, website, phone, company, position, birth_date
     * @return true|string  true bei Erfolg, Fehlermeldung als String
     */
    public function updateProfile(int $userId, array $data): bool|string
    {
        if (array_key_exists('username', $data)) {
            $username = trim((string)($data['username'] ?? ''));
            if ($username === '') {
                return 'Benutzername darf nicht leer sein.';
            }
            if (strlen($username) < 3 || strlen($username) > 50 || preg_match('/^[a-zA-Z0-9_]+$/', $username) !== 1) {
                return 'Benutzername muss 3–50 Zeichen lang sein und darf nur Buchstaben, Zahlen und Unterstriche enthalten.';
            }
            $conflict = $this->db->get_var(
                "SELECT id FROM {$this->prefix}users WHERE username = ? AND id != ?",
                [$username, $userId]
            );
            if ($conflict) {
                return 'Benutzername wird bereits verwendet.';
            }
            $this->db->execute(
                "UPDATE {$this->prefix}users SET username = ? WHERE id = ?",
                [$username, $userId]
            );
        }

        // E-Mail-Uniqueness prüfen
        if (!empty($data['email'])) {
            $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
            if (!$email) {
                return 'Ungültige E-Mail-Adresse.';
            }
            $conflict = $this->db->get_var(
                "SELECT id FROM {$this->prefix}users WHERE email = ? AND id != ?",
                [$email, $userId]
            );
            if ($conflict) {
                return 'E-Mail-Adresse wird bereits verwendet.';
            }
            $previousEmail = (string) ($this->db->get_var(
                "SELECT email FROM {$this->prefix}users WHERE id = ?",
                [$userId]
            ) ?? '');
            $this->db->execute(
                "UPDATE {$this->prefix}users SET email = ? WHERE id = ?",
                [$email, $userId]
            );
            if ($previousEmail !== '' && strcasecmp($previousEmail, (string) $email) !== 0) {
                $this->notifyPreviousEmailAddress($userId, $previousEmail, (string) $email);
            }
        }

        if (array_key_exists('display_name', $data)) {
            $displayName = trim((string)($data['display_name'] ?? ''));
            $this->db->execute(
                "UPDATE {$this->prefix}users SET display_name = ? WHERE id = ?",
                [$displayName, $userId]
            );
        }

        if (array_key_exists('website', $data)) {
            $website = $this->sanitizePublicUrl((string) ($data['website'] ?? ''));
            if (trim((string) ($data['website'] ?? '')) !== '' && $website === '') {
                return 'Ungültige Website-URL.';
            }

            $data['website'] = $website;
        }

        // Meta-Felder
        $metaFields = ['first_name', 'last_name', 'bio', 'website', 'phone', 'position', 'company', 'birth_date'];
        foreach ($metaFields as $field) {
            if (array_key_exists($field, $data)) {
                $this->setUserMeta($userId, $field, (string)($data[$field] ?? ''));
            }
        }

        return true;
    }

    /**
     * User-Meta als Array zurückgeben.
     *
     * @param int $userId
     * @return array<string, string>
     */
    public function getUserMeta(int $userId): array
    {
        $rows = $this->db->get_results(
            "SELECT meta_key, meta_value FROM {$this->prefix}user_meta WHERE user_id = ?",
            [$userId]
        );

        $meta = [];
        foreach ((array)$rows as $row) {
            $meta[$row->meta_key] = $row->meta_value;
        }
        return $meta;
    }

    // ── Passwort & 2FA ────────────────────────────────────────────────────────

    /**
     * Passwort ändern.
     *
     * @return true|string  true bei Erfolg, Fehlermeldung als String
     */
    public function changePassword(int $userId, string $current, string $new, string $confirm): bool|string
    {
        if (empty($current) || empty($new) || empty($confirm)) {
            return 'Alle Felder müssen ausgefüllt sein.';
        }
        if ($new !== $confirm) {
            return 'Neues Passwort und Bestätigung stimmen nicht überein.';
        }
        $policyResult = \CMS\Auth::validatePasswordPolicy($new);
        if ($policyResult !== true) {
            return $policyResult;
        }

        $hash = $this->db->get_var(
            "SELECT password FROM {$this->prefix}users WHERE id = ?",
            [$userId]
        );

        if (!$hash || !Security::verifyPassword($current, $hash)) {
            return 'Das aktuelle Passwort ist nicht korrekt.';
        }

        $this->db->execute(
            "UPDATE {$this->prefix}users SET password = ? WHERE id = ?",
            [Security::hashPassword($new), $userId]
        );
        \CMS\Auth::instance()->refreshPasswordFingerprint($userId);

        return true;
    }

    /**
     * Zwei-Faktor-Authentifizierung aktivieren / deaktivieren.
     *
     * @return true|string
     */
    public function toggle2FA(int $userId, bool $enable): bool|string
    {
        $this->setUserMeta($userId, '2fa_enabled', $enable ? '1' : '0');
        return true;
    }

    // ── Benachrichtigungen ────────────────────────────────────────────────────

    /**
     * Gespeicherte Benachrichtigungs-Präferenzen laden.
     *
     * @return array<string, bool|string>
     */
    public function getNotificationPreferences(int $userId): array
    {
        $meta = $this->getUserMeta($userId);
        $raw  = $meta['notification_preferences'] ?? null;
        $defaults = [
            'email_notifications'    => true,
            'email_marketing'        => false,
            'email_updates'          => true,
            'email_security'         => true,
            'browser_notifications'  => false,
            'desktop_notifications'  => false,
            'mobile_notifications'   => false,
            'notify_new_features'    => true,
            'notify_promotions'      => false,
            'notification_frequency' => 'immediate',
        ];

        if ($raw) {
            $saved = Json::decodeArray($raw, []);
            if (is_array($saved)) {
                return array_merge($defaults, $saved);
            }
        }

        return $defaults;
    }

    /**
     * Benachrichtigungs-Präferenzen speichern.
     */
    public function updateNotificationPreferences(int $userId, array $preferences): bool
    {
        $this->setUserMeta($userId, 'notification_preferences', json_encode($preferences));
        return true;
    }

    /**
     * Letzte N Benachrichtigungen abrufen.
     *
     * @return array<int, object>
     */
    public function getRecentNotifications(int $userId, int $limit = 10): array
    {
        $table = $this->prefix . 'notifications';

        // Tabelle kann optional fehlen
        try {
            $rows = $this->db->get_results(
                "SELECT id, user_id, type, title, message, url, is_read, read_at, created_at FROM {$table} WHERE user_id = ? ORDER BY created_at DESC LIMIT ?",
                [$userId, $limit]
            );
            return (array)$rows;
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Datenschutz ───────────────────────────────────────────────────────────

    /**
     * Datenschutz-Einstellungen laden.
     *
     * @return array<string, mixed>
     */
    public function getPrivacySettings(int $userId): array
    {
        $meta = $this->getUserMeta($userId);
        $raw  = $meta['privacy_settings'] ?? null;
        $defaults = [
            'profile_visibility' => 'members',
            'show_email'         => false,
            'show_activity'      => true,
            'public_profile_fields' => ['avatar', 'bio', 'company', 'position', 'website', 'location', 'social'],
        ];

        if ($raw) {
            $saved = Json::decodeArray($raw, []);
            if (is_array($saved)) {
                return array_merge($defaults, $saved);
            }
        }

        return $defaults;
    }

    /**
     * Datenschutz-Einstellungen speichern.
     */
    public function updatePrivacySettings(int $userId, array $settings): bool
    {
        $visibility = (string)($settings['profile_visibility'] ?? 'members');
        if (!in_array($visibility, ['public', 'members', 'private'], true)) {
            $visibility = 'members';
        }

        $requestedFields = $settings['public_profile_fields'] ?? [];
        if (!is_array($requestedFields)) {
            $requestedFields = [];
        }

        $allowedFields = array_keys(self::PUBLIC_PROFILE_FIELDS);
        $publicFields = array_values(array_intersect($allowedFields, array_map('strval', $requestedFields)));

        $clean = [
            'profile_visibility' => $visibility,
            'show_email' => !empty($settings['show_email']),
            'show_activity' => !empty($settings['show_activity']),
            'public_profile_fields' => $publicFields,
        ];

        $this->setUserMeta($userId, 'privacy_settings', json_encode($clean));
        return true;
    }

    /**
     * @return array<string,array{label:string,type:string}>
     */
    public function getPublicProfileFieldDefinitions(): array
    {
        return self::PUBLIC_PROFILE_FIELDS;
    }

    public function buildPublicAuthorPath(int $userId): string
    {
        return '/author/user-' . $userId;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getPublicAuthorProfile(string $identifier, bool $viewerIsLoggedIn = false): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $userId = 0;
        if (preg_match('/^user-(\d+)$/', $identifier, $matches) === 1) {
            $userId = (int)($matches[1] ?? 0);
        } elseif (ctype_digit($identifier)) {
            $userId = (int)$identifier;
        }

        $user = $userId > 0
            ? $this->db->get_row(
                "SELECT id, username, email, display_name, role, status, created_at
                 FROM {$this->prefix}users
                 WHERE id = ?
                 LIMIT 1",
                [$userId]
            )
            : $this->db->get_row(
                "SELECT id, username, email, display_name, role, status, created_at
                 FROM {$this->prefix}users
                 WHERE username = ?
                 LIMIT 1",
                [$identifier]
            );

        if (!$user || (string)($user->status ?? 'active') === 'banned') {
            return null;
        }

        $userId = (int)($user->id ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $privacy = $this->getPrivacySettings($userId);
        $visibility = (string)($privacy['profile_visibility'] ?? 'members');
        if ($visibility === 'private' || ($visibility === 'members' && !$viewerIsLoggedIn)) {
            return null;
        }

        $meta = $this->getUserMeta($userId);
        $displayName = trim((string)($user->display_name ?? ''));
        if ($displayName === '') {
            $displayName = trim((string)($user->username ?? 'Autor'));
        }

        $allowedFields = $privacy['public_profile_fields'] ?? [];
        if (!is_array($allowedFields)) {
            $allowedFields = [];
        }

        $fieldDefinitions = $this->getPublicProfileFieldDefinitions();
        $details = [];
        foreach ($allowedFields as $fieldKey) {
            $fieldKey = (string)$fieldKey;
            if (!isset($fieldDefinitions[$fieldKey]) || in_array($fieldKey, ['avatar', 'bio'], true)) {
                continue;
            }

            $value = trim((string)($meta[$fieldKey] ?? ''));
            if ($value === '') {
                continue;
            }

            if (($fieldDefinitions[$fieldKey]['type'] ?? 'text') === 'url') {
                $value = $this->sanitizePublicUrl($value);
                if ($value === '') {
                    continue;
                }
            }

            $details[] = [
                'key' => $fieldKey,
                'label' => $fieldDefinitions[$fieldKey]['label'],
                'type' => $fieldDefinitions[$fieldKey]['type'],
                'value' => $value,
            ];
        }

        if (!empty($privacy['show_email']) && !empty($user->email)) {
            $details[] = [
                'key' => 'email',
                'label' => 'E-Mail',
                'type' => 'email',
                'value' => trim((string)$user->email),
            ];
        }

        $avatarUrl = in_array('avatar', $allowedFields, true)
            ? $this->normalizePublicMediaUrl((string)($meta['avatar'] ?? ''))
            : '';
        $bio = in_array('bio', $allowedFields, true) ? trim((string)($meta['bio'] ?? '')) : '';

        return [
            'id' => $userId,
            'slug' => 'user-' . $userId,
            'username' => (string)($user->username ?? ''),
            'display_name' => $displayName !== '' ? $displayName : 'Autor',
            'bio' => $bio,
            'avatar_url' => $avatarUrl,
            'details' => $details,
            'profile_visibility' => $visibility,
            'show_activity' => !empty($privacy['show_activity']),
            'profile_url' => $this->buildPublicAuthorPath($userId),
        ];
    }

    /**
     * Daten-Übersicht für den User (für Privacy-Seite).
     *
     * @return array<string, int>
     */
    public function getDataOverview(int $userId): array
    {
        return [
            'stored_meta_fields' => count($this->getUserMeta($userId)),
            'sessions'           => count($this->getActiveSessions($userId)),
            'notifications'      => count($this->getRecentNotifications($userId, 999)),
        ];
    }

    /**
     * User-Daten als Array exportieren (JSON-Download).
     *
     * @return array<string, mixed>|null
     */
    public function exportUserData(int $userId): ?array
    {
        $user = $this->db->get_row(
            "SELECT id, username, email, role, status, created_at FROM {$this->prefix}users WHERE id = ?",
            [$userId]
        );

        if (!$user) {
            return null;
        }

        return [
            'account'           => (array)$user,
            'meta'              => $this->getUserMeta($userId),
            'privacy_settings'  => $this->getPrivacySettings($userId),
            'notifications'     => $this->getNotificationPreferences($userId),
            'exported_at'       => date('c'),
        ];
    }

    /**
     * Account zur Löschung markieren (Soft-Delete, 30-Tage-Frist).
     */
    public function requestAccountDeletion(int $userId): bool
    {
        $user = $this->db->get_row(
            "SELECT id, username, email, display_name FROM {$this->prefix}users WHERE id = ? LIMIT 1",
            [$userId]
        );

        if (!$user) {
            return false;
        }

        $this->ensurePrivacyRequestSchema();

        $deletionAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        $this->setUserMeta($userId, 'deletion_requested_at', date('c'));
        $this->setUserMeta($userId, 'deletion_scheduled_at', $deletionAt);
        $this->db->execute(
            "UPDATE {$this->prefix}users SET status = 'pending_deletion' WHERE id = ?",
            [$userId]
        );

        $displayName = trim((string)($user->display_name ?? ''));
        if ($displayName === '') {
            $displayName = trim((string)($user->username ?? ''));
        }

        $existingRequest = $this->db->get_row(
            "SELECT id, status
             FROM {$this->prefix}privacy_requests
             WHERE type = 'deletion' AND user_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$userId]
        );

        $requestPayload = [
            'email' => (string)($user->email ?? ''),
            'name' => $displayName !== '' ? $displayName : (string)($user->username ?? ''),
            'status' => 'pending',
            'reject_reason' => null,
            'processed_at' => null,
            'completed_at' => null,
            'execute_after' => $deletionAt,
        ];

        if (is_object($existingRequest) && (int)($existingRequest->id ?? 0) > 0) {
            $this->db->update('privacy_requests', $requestPayload, ['id' => (int)$existingRequest->id]);
        } else {
            $this->db->insert('privacy_requests', array_merge($requestPayload, [
                'type' => 'deletion',
                'user_id' => $userId,
            ]));
        }

        return true;
    }

    // ── Sicherheit ────────────────────────────────────────────────────────────

    /**
     * Sicherheits-Übersicht laden.
     * Berechnet Score (0-100) + Empfehlungen auf Basis der gespeicherten Meta-Daten.
     *
     * @return array<string, mixed>
     */
    public function getSecurityData(int $userId): array
    {
        $meta        = $this->getUserMeta($userId);
        // H-02: mfa_enabled (TOTP-basiert, gesetzt von Auth::confirmMfaSetup) hat Vorrang vor
        // dem alten Flag-Schlüssel 2fa_enabled für Abwärtskompatibilität.
        $twoFA       = ($meta['mfa_enabled'] ?? $meta['2fa_enabled'] ?? '0') === '1';
        $pwChanged   = $meta['password_changed_at'] ?? null;
        $lastLogin   = $meta['last_login'] ?? null;
        $lastLoginIp = $meta['last_login_ip'] ?? null;
        $backupCount = BackupCodesManager::instance()->getRemainingCount($userId);
        $hasBackupCodes = $backupCount > 0;
        $passkeysAvailable = WebAuthnAdapter::instance()->isAvailable();
        $passkeyCount = $passkeysAvailable
            ? count(WebAuthnAdapter::instance()->getCredentialsForUser($userId))
            : 0;
        $hasPasskeys = $passkeyCount > 0;

        // ── Score berechnen ───────────────────────────────────────────
        // Gewichtet so, dass typische Sicherheitsstufen sichtbar staffeln:
        // Basis 25 + MFA 30 + Backup-Codes 10 + Passkeys 15 + frisches Passwort 20 = 100.
        $score = 25; // Basis
        $recs  = [];

        if ($twoFA) {
            $score += 30;
            $recs[] = ['done' => true,  'text' => 'Zwei-Faktor-Authentifizierung ist aktiv'];
        } else {
            $recs[] = ['done' => false, 'text' => 'Zwei-Faktor-Authentifizierung aktivieren (+30 Punkte)'];
        }

        if ($twoFA && $hasBackupCodes) {
            $score += 10;
            $recs[] = ['done' => true, 'text' => 'Backup-Codes sind hinterlegt (' . $backupCount . ' verfügbar)'];
        } elseif ($twoFA) {
            $recs[] = ['done' => false, 'text' => 'Backup-Codes erzeugen und sicher ablegen (+10 Punkte)'];
        } else {
            $recs[] = ['done' => false, 'text' => 'Nach Aktivierung von MFA Backup-Codes erzeugen (+10 Punkte)'];
        }

        if ($passkeysAvailable && $hasPasskeys) {
            $score += 15;
            $recs[] = ['done' => true, 'text' => 'Passkeys sind registriert (' . $passkeyCount . ' Gerät' . ($passkeyCount === 1 ? '' : 'e') . ')'];
        } elseif ($passkeysAvailable) {
            $recs[] = ['done' => false, 'text' => 'Mindestens einen Passkey registrieren (+15 Punkte)'];
        }

        if ($pwChanged) {
            $daysSinceChange = (int) floor((time() - strtotime($pwChanged)) / 86400);
            if ($daysSinceChange <= 90) {
                $score += 20;
                $recs[] = ['done' => true,  'text' => 'Passwort kürzlich geändert (vor ' . $daysSinceChange . ' Tagen)'];
            } elseif ($daysSinceChange <= 180) {
                $score += 10;
                $recs[] = ['done' => false, 'text' => 'Passwort ist ' . $daysSinceChange . ' Tage alt – bald ändern empfohlen'];
            } else {
                $recs[] = ['done' => false, 'text' => 'Passwort ist älter als 180 Tage – bitte ändern (+20 Punkte)'];
            }
        } else {
            $recs[] = ['done' => false, 'text' => 'Passwort noch nie manuell geändert (+20 Punkte)'];
        }

        $score = min(100, $score);

        if ($score >= 85) {
            $scoreMsg = 'Sehr gut! Dein Account ist gut geschützt.';
        } elseif ($score >= 50) {
            $scoreMsg = 'Guter Start – ein paar Maßnahmen können die Sicherheit erhöhen.';
        } else {
            $scoreMsg = 'Handlungsbedarf: Bitte aktiviere weitere Schutzmaßnahmen.';
        }

        return [
            '2fa_enabled'      => $twoFA,
            'backup_count'     => $backupCount,
            'last_login'       => $lastLogin,
            'last_login_ip'    => $lastLoginIp,
            'passkey_count'    => $passkeyCount,
            'passkeys_available' => $passkeysAvailable,
            'password_changed' => $pwChanged,
            'score'            => $score,
            'score_message'    => $scoreMsg,
            'recommendations'  => $recs,
        ];
    }

    /**
     * Aktive Sessions abrufen.
     *
     * @return array<int, object>
     */
    public function getActiveSessions(int $userId): array
    {
        $table = $this->prefix . 'sessions';
        try {
            $rows = $this->db->get_results(
                "SELECT id, user_id, ip_address, user_agent, last_activity, expires_at FROM {$table} WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY last_activity DESC LIMIT 50",
                [$userId]
            );
            return (array)$rows;
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Subscription ──────────────────────────────────────────────────────────

    /**
     * Aktives Subscription-Paket des Users laden.
     */
    public function getUserSubscription(int $userId): ?object
    {
        if (!CoreModuleService::getInstance()->isModuleEnabled('subscriptions')) {
            return null;
        }

        if (class_exists('\\CMS\\SubscriptionManager')) {
            $sub = \CMS\SubscriptionManager::instance()->getUserSubscription($userId);
            // Map fields if necessary to match old structure expecting 'package_name'
            if ($sub && !isset($sub->package_name) && isset($sub->name)) {
                $sub->package_name = $sub->name;
            }
            return $sub;
        }

        // Fallback or legacy code removed
        return null;
    }

    /**
     * Alle buchbaren Pakete laden.
     *
     * @return array<int, object>
     */
    public function getAvailablePackages(): array
    {
        if (!CoreModuleService::getInstance()->isModuleEnabled('subscriptions')
            || !CoreModuleService::getInstance()->isModuleEnabled('subscription_public_pricing')
            || !CoreModuleService::getInstance()->isModuleEnabled('subscription_ordering')) {
            return [];
        }

        try {
            return (array)$this->db->get_results(
                "SELECT id, name, slug, description, price_monthly, price_yearly, is_active, sort_order
                 FROM {$this->prefix}subscription_plans
                 WHERE is_active = 1
                 ORDER BY sort_order ASC, price_monthly ASC"
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Read-only Renewal-/Ablaufhinweis für den aktiven Member-Vertrag.
     *
     * @return array<string, mixed>
     */
    public function getSubscriptionRenewalNotice(int $userId): array
    {
        $defaults = [
            'has_notice' => false,
            'is_highlighted' => false,
            'is_overdue' => false,
            'is_due_today' => false,
            'kind' => 'none',
            'severity' => 'info',
            'title' => '',
            'message' => '',
            'due_at' => '',
            'due_label' => '',
            'days_until_due' => null,
            'warning_days' => 0,
            'auto_renewal_enabled' => false,
            'grace_period_days' => 0,
            'is_auto_renewal' => false,
            'status' => '',
        ];

        if (!CoreModuleService::getInstance()->isModuleEnabled('subscriptions') || !class_exists('\\CMS\\SubscriptionManager')) {
            return $defaults;
        }

        try {
            $subscription = $this->getUserSubscription($userId);
            if ($subscription === null) {
                return $defaults;
            }

            $notice = \CMS\SubscriptionManager::instance()->getSubscriptionRenewalNotice($subscription);

            return is_array($notice) ? array_merge($defaults, $notice) : $defaults;
        } catch (\Throwable) {
            return $defaults;
        }
    }

    /**
     * Berechtigungen des Users auf Basis seines Subscription-Pakets.
     *
     * @return array<string, bool>
     */
    public function getUserPermissions(int $userId): array
    {
        $sub = $this->getUserSubscription($userId);
        $defaults = [
            'can_post'           => true,
            'can_message'        => true,
            'can_view_profiles'  => true,
            'premium_features'   => false,
        ];

        if ($sub && !empty($sub->features)) {
            $features = Json::decodeArray($sub->features ?? null, []);
            if (is_array($features)) {
                return array_merge($defaults, $features);
            }
        }

        return $defaults;
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    /**
     * Alle Dashboard-Daten für den Member auf einmal laden.
     *
     * @return array<string, mixed>
     */
    public function getMemberDashboardData(int $userId): array
    {
        $user = $this->db->get_row(
            "SELECT id, username, email, role, status, created_at FROM {$this->prefix}users WHERE id = ?",
            [$userId]
        );

        $security     = $this->getSecurityData($userId);
        $subscription = $this->getUserSubscription($userId);
        $subscriptionModuleEnabled = CoreModuleService::getInstance()->isModuleEnabled('subscription_member_area');

        // ── Letzter Login formatiert ──────────────────────────────────
        $lastLoginRaw  = $security['last_login'] ?? null;
        $lastLoginTs   = $lastLoginRaw ? strtotime($lastLoginRaw) : null;

        if ($lastLoginTs) {
            $lastLoginFormatted = date('d.m.Y H:i', $lastLoginTs);
            $diffSec = time() - $lastLoginTs;
            if ($diffSec < 60) {
                $lastLoginRelative = 'gerade eben';
            } elseif ($diffSec < 3600) {
                $lastLoginRelative = 'vor ' . (int)($diffSec / 60) . ' Min.';
            } elseif ($diffSec < 86400) {
                $lastLoginRelative = 'vor ' . (int)($diffSec / 3600) . ' Std.';
            } else {
                $lastLoginRelative = 'vor ' . (int)($diffSec / 86400) . ' Tagen';
            }
        } else {
            $lastLoginFormatted = 'Noch nicht erfasst';
            $lastLoginRelative  = '–';
        }

        // ── Account-Alter ─────────────────────────────────────────────
        $createdAt      = $user->created_at ?? null;
        $accountAgeDays = $createdAt
            ? (int) floor((time() - strtotime($createdAt)) / 86400)
            : 0;

        // ── Login-Zähler (30 Tage, optional aus Activity-Log) ─────────
        $loginCount30d = 0;
        try {
            $loginCount30d = (int) $this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}activity_log
                  WHERE user_id = ? AND action = 'login'
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                [$userId]
            );
        } catch (\Throwable) { /* Tabelle optional */ }

        // ── Passwort-Änderung relativ ──────────────────────────────────
        $pwChangedRaw      = $security['password_changed'] ?? null;
        $pwChangedRelative = '–';
        if ($pwChangedRaw) {
            $pwTs    = strtotime($pwChangedRaw);
            $diffSec = time() - $pwTs;
            if ($diffSec < 86400) {
                $pwChangedRelative = 'vor ' . (int)($diffSec / 3600) . ' Std.';
            } elseif ($diffSec < 30 * 86400) {
                $pwChangedRelative = 'vor ' . (int)($diffSec / 86400) . ' Tagen';
            } else {
                $pwChangedRelative = date('d.m.Y', $pwTs);
            }
        }

        // ── Aktive Sessions (nur Anzahl) ───────────────────────────────
        $activeSessions = count($this->getActiveSessions($userId));

        return [
            'user'                      => $user,
            'meta'                      => $this->getUserMeta($userId),
            'subscription'              => $subscription,
            'subscription_visible'      => $subscriptionModuleEnabled && $subscription !== null,
            'subscription_module_enabled' => $subscriptionModuleEnabled,
            'permissions'               => $this->getUserPermissions($userId),
            'recent_notifications'      => $this->getRecentNotifications($userId, 5),
            'security'                  => $security,
            // Formatierte Werte für Views
            'last_login_formatted'      => $lastLoginFormatted,
            'last_login_relative'       => $lastLoginRelative,
            'account_age_days'          => $accountAgeDays,
            'login_count_30d'           => $loginCount30d,
            'two_factor_enabled'        => $security['2fa_enabled'],
            'password_changed_relative' => $pwChangedRelative,
            'active_sessions'           => $activeSessions,
        ];
    }

    // ── Interne Helfer ────────────────────────────────────────────────────────

    /**
     * User-Meta-Feld schreiben (upsert).
     * Nutzt ON DUPLICATE KEY UPDATE für atomische Operation (erfordert UNIQUE KEY uq_user_meta).
     */
    private function setUserMeta(int $userId, string $key, string $value): void
    {
        $this->db->execute(
            "INSERT INTO {$this->prefix}user_meta (user_id, meta_key, meta_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            [$userId, $key, $value]
        );
    }

    private function ensurePrivacyRequestSchema(): void
    {
        $this->db->getPdo()->exec(
            "CREATE TABLE IF NOT EXISTS {$this->prefix}privacy_requests (
                id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type          VARCHAR(20) NOT NULL DEFAULT 'export',
                user_id       INT UNSIGNED DEFAULT NULL,
                email         VARCHAR(255) NOT NULL,
                name          VARCHAR(255) DEFAULT NULL,
                status        VARCHAR(20) NOT NULL DEFAULT 'pending',
                reject_reason TEXT DEFAULT NULL,
                processed_at  DATETIME DEFAULT NULL,
                completed_at  DATETIME DEFAULT NULL,
                execute_after DATETIME DEFAULT NULL,
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_type   (type),
                INDEX idx_execute_after (execute_after)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        try {
            $column = $this->db->get_row("SHOW COLUMNS FROM {$this->prefix}privacy_requests LIKE 'execute_after'");

            if (!$column) {
                $this->db->getPdo()->exec(
                    "ALTER TABLE {$this->prefix}privacy_requests ADD COLUMN execute_after DATETIME DEFAULT NULL AFTER completed_at"
                );
                $this->db->getPdo()->exec(
                    "ALTER TABLE {$this->prefix}privacy_requests ADD INDEX idx_execute_after (execute_after)"
                );
            }
        } catch (\Throwable) {
            // Best effort – die Tabelle wird in Altinstallationen ansonsten durch die Admin-Module ergänzt.
        }
    }

    private function sanitizePublicUrl(string $value, array $allowedSchemes = ['http', 'https']): string
    {
        $url = trim($value);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? '' : $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme === '' || !in_array($scheme, $allowedSchemes, true)) {
            return '';
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    private function normalizePublicMediaUrl(string $value): string
    {
        $url = trim($value);
        if ($url === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $url) === 1) {
            return '';
        }

        $siteBase = rtrim((string) SITE_URL, '/');
        $normalizedUrl = str_replace('\\', '/', $url);

        if (str_starts_with($normalizedUrl, '//')) {
            return '';
        }

        if (preg_match('#^https?://#i', $normalizedUrl) === 1) {
            return filter_var($normalizedUrl, FILTER_VALIDATE_URL) ? $normalizedUrl : '';
        }

        if (str_starts_with($normalizedUrl, '/')) {
            return $siteBase !== '' ? $siteBase . $normalizedUrl : $normalizedUrl;
        }

        $relativePath = preg_replace('#^(?:\./)+#', '', $normalizedUrl) ?? '';
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return '';
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $relativePath) === 1) {
            return '';
        }

        return $siteBase !== '' ? $siteBase . '/' . $relativePath : '/' . $relativePath;
    }
}
