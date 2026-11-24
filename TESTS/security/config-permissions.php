<?php
/**
 * Audit 4.2.0 (AP-07, Punkt 07.5): Dateirechte der vom Installer geschriebenen Konfiguration. Ohne Datenbank.
 * Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

T::run('AP-07 / 07.5: config/app.php und ihre Sicherungskopie sind nicht für alle lesbar', static function (): void {
    require_once ABSPATH . 'install/InstallerService.php';

    $dir = sys_get_temp_dir() . '/cms-ap07-' . getmypid();
    @mkdir($dir, 0777, true);
    $oldUmask = umask(022); // übliche Voreinstellung: neue Dateien wären sonst 0644

    try {
        $data = array_fill_keys([
            'admin_email', 'auth_key', 'cms_backup_path', 'cms_encryption_key', 'cms_encryption_keys_previous', 'cms_hsts_max_age',
            'cms_hsts_mode', 'cms_https_redirect_strategy', 'cms_private_path', 'created_at', 'db_charset', 'db_host', 'db_name',
            'db_pass', 'db_prefix', 'db_user', 'debug_mode', 'default_theme', 'jwt_issuer', 'jwt_secret', 'jwt_ttl', 'ldap_base_dn',
            'ldap_default_role', 'ldap_filter', 'ldap_host', 'ldap_password', 'ldap_port', 'ldap_use_ssl', 'ldap_use_tls',
            'ldap_username', 'login_timeout', 'max_login_attempts', 'nonce_key', 'secure_auth_key', 'site_name', 'site_url',
            'smtp_encryption', 'smtp_from_email', 'smtp_from_name', 'smtp_host', 'smtp_pass', 'smtp_port', 'smtp_user',
        ], 'x');
        foreach (['cms_hsts_max_age', 'jwt_ttl', 'login_timeout', 'max_login_attempts', 'smtp_port', 'ldap_port'] as $numeric) {
            $data[$numeric] = '10';
        }

        $service = new \CMS\Install\InstallerService($dir);
        T::same(true, $service->createConfigFile($data), 'erste Konfiguration geschrieben');
        T::same('0640', sprintf('%04o', fileperms($dir . '/config/app.php') & 0777), 'config/app.php: 0640');

        T::same(true, $service->createConfigFile($data), 'zweiter Lauf legt eine Sicherungskopie an');
        $backups = glob($dir . '/config/app.backup-*.php') ?: [];
        T::check(count($backups) === 1, 'genau eine Sicherungskopie');
        foreach ($backups as $backup) {
            T::same('0640', sprintf('%04o', fileperms($backup) & 0777), 'Sicherungskopie: 0640');
        }
        T::same('0640', sprintf('%04o', fileperms($dir . '/config/app.php') & 0777), 'config/app.php nach dem zweiten Lauf: 0640');
    } finally {
        umask($oldUmask);
        foreach (glob($dir . '/{,config/}{*,.htaccess}', GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir . '/config');
        @rmdir($dir);
    }
});
