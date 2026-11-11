<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Database;
use CMS\Auth\AuthManager;
use CMS\Auth\LDAP\LdapAuthProvider;
use CMS\Services\UserService;

class UserSettingsModule
{
    private Database $db;
    private string $prefix;
    private UserService $userService;

    private const SETTINGS_KEYS = [
        'registration_enabled',
        'member_registration_enabled',
        'member_email_verification',
        'member_default_role',
    ];

    public function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->userService = UserService::getInstance();
    }

    public function getData(): array
    {
        $settings = $this->loadSettings();
        $authManager = AuthManager::instance();
        $passwordPolicy = \CMS\Auth::evaluatePasswordPolicy('');
        $passwordRequirements = [];

        foreach ((array) ($passwordPolicy['requirements'] ?? []) as $requirement) {
            $key = trim((string) ($requirement['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $passwordRequirements[$key] = true;
        }

        return [
            'settings' => [
                'registration_enabled' => ($settings['registration_enabled'] ?? '0') === '1',
                'member_registration_enabled' => ($settings['member_registration_enabled'] ?? '1') === '1',
                'member_email_verification' => ($settings['member_email_verification'] ?? '0') === '1',
                'member_default_role' => $this->userService->resolveRegistrationRole((string)($settings['member_default_role'] ?? 'member')),
            ],
            'roles' => $this->getAvailableRoles(),
            'stats' => $this->getAuthStats(),
            'providers' => $authManager->getAvailableProviders(),
            'passkey' => [
                'available' => $authManager->isPasskeyAvailable(),
                'site_url_configured' => defined('SITE_URL') && (string)SITE_URL !== '',
                'openssl_available' => function_exists('openssl_open'),
            ],
            'ldap' => [
                'enabled' => $authManager->isLdapEnabled(),
                'extension_loaded' => extension_loaded('ldap'),
                'configured' => LdapAuthProvider::instance()->isConfigured(),
                'host' => defined('LDAP_HOST') ? (string)LDAP_HOST : '',
                'port' => defined('LDAP_PORT') ? (int)LDAP_PORT : 389,
                'base_dn' => defined('LDAP_BASE_DN') ? (string)LDAP_BASE_DN : '',
                'use_ssl' => defined('LDAP_USE_SSL') && (bool)LDAP_USE_SSL,
                'use_tls' => defined('LDAP_USE_TLS') && (bool)LDAP_USE_TLS,
                'default_role' => defined('LDAP_DEFAULT_ROLE') ? (string)LDAP_DEFAULT_ROLE : 'member',
                'sync_limit' => 250,
            ],
            'jwt' => [
                'configured' => defined('JWT_SECRET') && (string)JWT_SECRET !== '',
                'ttl' => defined('JWT_TTL') ? (int)JWT_TTL : 3600,
                'issuer' => defined('JWT_ISSUER') ? (string)JWT_ISSUER : '',
                'secret_source' => defined('JWT_SECRET') && (string)JWT_SECRET !== '' ? 'JWT_SECRET' : 'AUTH_KEY (Fallback)',
            ],
            'security' => [
                'max_login_attempts' => \CMS\Services\LoginThrottleSettings::maxAttempts(),
                'login_timeout_seconds' => \CMS\Services\LoginThrottleSettings::lockoutSeconds(),
                'password_min_length' => (int) ($passwordPolicy['min_length'] ?? 12),
                'password_requires_uppercase' => !empty($passwordRequirements['uppercase']),
                'password_requires_lowercase' => !empty($passwordRequirements['lowercase']),
                'password_requires_digit' => !empty($passwordRequirements['digit']),
                'password_requires_special' => !empty($passwordRequirements['special']),
                'password_policy' => $passwordPolicy,
                'admin_session_hours' => 8,
                'member_session_days' => 30,
            ],
        ];
    }

    public function saveSettings(array $post): array
    {
        try {
            $values = [
                'registration_enabled' => !empty($post['registration_enabled']) ? '1' : '0',
                'member_registration_enabled' => !empty($post['member_registration_enabled']) ? '1' : '0',
                'member_email_verification' => !empty($post['member_email_verification']) ? '1' : '0',
                'member_default_role' => $this->userService->resolveRegistrationRole((string)($post['member_default_role'] ?? 'member')),
            ];

            foreach ($values as $key => $value) {
                $this->upsertSetting($key, $value);
            }

            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'user_settings.auth.updated',
                'Benutzer- und Authentifizierungseinstellungen aktualisiert.',
                'setting',
                null,
                [
                    'registration_enabled' => $values['registration_enabled'],
                    'member_registration_enabled' => $values['member_registration_enabled'],
                    'member_email_verification' => $values['member_email_verification'],
                    'member_default_role' => $values['member_default_role'],
                ],
                'info'
            );

            return ['success' => true, 'message' => 'Benutzer- und Authentifizierungseinstellungen gespeichert.'];
        } catch (\Throwable $e) {
            \CMS\Logger::instance()->withChannel('user-settings')->error(
                'Benutzer- und Authentifizierungseinstellungen konnten nicht gespeichert werden.',
                [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]
            );

            return ['success' => false, 'error' => 'Die Benutzer- und Authentifizierungseinstellungen konnten nicht gespeichert werden.'];
        }
    }

    public function syncLdapUsers(): array
    {
        $result = LdapAuthProvider::instance()->syncDirectoryUsers(250);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            'user_settings.ldap.sync',
            !empty($result['success']) ? 'LDAP-Erstsynchronisierung ausgeführt.' : 'LDAP-Erstsynchronisierung fehlgeschlagen.',
            'setting',
            null,
            [
                'success' => !empty($result['success']),
                'processed' => (int)($result['processed'] ?? 0),
                'created' => (int)($result['created'] ?? 0),
                'updated' => (int)($result['updated'] ?? 0),
                'skipped' => (int)($result['skipped'] ?? 0),
                'errors' => (int)($result['errors'] ?? 0),
            ],
            !empty($result['success']) ? 'info' : 'warning'
        );

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function loadSettings(): array
    {
        $settings = [];

        try {
            $rows = $this->db->get_results(
                "SELECT option_name, option_value FROM {$this->prefix}settings WHERE option_name IN ('" . implode("','", self::SETTINGS_KEYS) . "')"
            ) ?: [];

            foreach ($rows as $row) {
                $settings[(string)$row->option_name] = (string)$row->option_value;
            }
        } catch (\Throwable $e) {
            // Fallbacks in getData()
        }

        return $settings;
    }

    /**
     * @return array<string, int>
     */
    private function getAuthStats(): array
    {
        $stats = [
            'total_users' => 0,
            'active_users' => 0,
            'mfa_users' => 0,
            'backup_code_users' => 0,
            'passkey_credentials' => 0,
        ];

        try {
            $stats['total_users'] = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}users");
            $stats['active_users'] = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}users WHERE status = 'active'");
            $stats['mfa_users'] = (int)$this->db->get_var(
                "SELECT COUNT(DISTINCT user_id) FROM {$this->prefix}user_meta WHERE meta_key = 'mfa_enabled' AND meta_value = '1'"
            );
            $stats['backup_code_users'] = (int)$this->db->get_var(
                "SELECT COUNT(DISTINCT user_id) FROM {$this->prefix}user_meta WHERE meta_key = 'mfa_backup_codes' AND meta_value <> '' AND meta_value <> '[]'"
            );
        } catch (\Throwable $e) {
            // Defaults beibehalten
        }

        try {
            $stats['passkey_credentials'] = (int)$this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}passkey_credentials"
            );
        } catch (\Throwable $e) {
            $stats['passkey_credentials'] = 0;
        }

        return $stats;
    }

    /**
     * @return array<int, string>
     */
    private function getAvailableRoles(): array
    {
        return array_keys($this->userService->getRegistrationRoleOptions());
    }

    private function upsertSetting(string $key, string $value): void
    {
        $existing = $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}settings WHERE option_name = ?",
            [$key]
        );

        if ((int)$existing > 0) {
            $this->db->execute(
                "UPDATE {$this->prefix}settings SET option_value = ? WHERE option_name = ?",
                [$value, $key]
            );
            return;
        }

        $this->db->execute(
            "INSERT INTO {$this->prefix}settings (option_name, option_value) VALUES (?, ?)",
            [$key, $value]
        );
    }
}