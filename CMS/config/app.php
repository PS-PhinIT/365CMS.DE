<?php
/**
 * CMS Application Configuration – TEMPLATE
 *
 * WICHTIG: Diese Datei ist ein Template mit Platzhalter-Werten!
 * Für die echte Konfiguration install.php ausführen – der Installer
 * generiert einzigartige Security-Keys und überschreibt diese Datei.
 *
 * NIEMALS echte Credentials oder Security-Keys in VCS einchecken!
 *
 * C-01: Security-Keys via random_bytes() generiert (durch install.php)
 * C-02: Konfiguration in config/ isoliert (via .htaccess geschützt)
 *
 * @package 365CMS
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    // __DIR__ = CMS/config → dirname(__DIR__) = CMS/
    define('ABSPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

// ─── Debug-Modus (in Produktion auf false setzen!) ─────────────────────────
// Wird durch install.php gesetzt. Für gehärtete Security-Header produktiv deaktiviert.
define('CMS_DEBUG', false);

// ─── Datenbank-Konfiguration ───────────────────────────────────────────────
// Diese Platzhalter werden durch install.php mit den korrekten Werten ersetzt.
define('DB_HOST',    'localhost');
define('DB_NAME',    'YOUR_DATABASE_NAME');
define('DB_USER',    'YOUR_DATABASE_USER');
define('DB_PASS',    'YOUR_DATABASE_PASSWORD');
define('DB_CHARSET', 'utf8mb4');
define('DB_PREFIX',  'cms_');

// ─── Security-Keys (via random_bytes – NICHT manuell setzen!) ─────────────
// WICHTIG: Diese Datei enthält Platzhalter-Keys!
// Vor dem ersten Betrieb install.php ausführen – der Installer generiert
// kryptografisch sichere, einzigartige Keys via random_bytes(32).
// NIEMALS diese Platzhalter in Produktion verwenden!
define('AUTH_KEY',        'REPLACE_VIA_INSTALLER_run_install_php_to_generate_unique_key_auth');
define('SECURE_AUTH_KEY', 'REPLACE_VIA_INSTALLER_run_install_php_to_generate_unique_key_secure');
define('NONCE_KEY',       'REPLACE_VIA_INSTALLER_run_install_php_to_generate_unique_key_nonce');

// ─── Site-Konfiguration ────────────────────────────────────────────────────
// Platzhalter – werden durch install.php gesetzt.
define('SITE_NAME',    'YOUR_SITE_NAME');
define('SITE_URL',     'https://YOUR-DOMAIN.example.com'); // NIEMALS mit Unterverzeichnis!
define('ADMIN_EMAIL',  'admin@example.com');

require_once ABSPATH . 'core/Version.php';
define('CMS_VERSION',  \CMS\Version::CURRENT);

// ─── Pfade ─────────────────────────────────────────────────────────────────
define('CORE_PATH',   ABSPATH . 'core/');
define('THEME_PATH',  ABSPATH . 'themes/');
define('PLUGIN_PATH', ABSPATH . 'plugins/');
define('UPLOAD_PATH', ABSPATH . 'uploads/');
define('ASSETS_PATH', ABSPATH . 'assets/');

$cmsLogDir = ABSPATH . 'logs' . DIRECTORY_SEPARATOR;
if (!is_dir($cmsLogDir)) {
    @mkdir($cmsLogDir, 0755, true);
}

defined('LOG_PATH') || define('LOG_PATH', $cmsLogDir);
defined('CMS_ERROR_LOG') || define('CMS_ERROR_LOG', rtrim(LOG_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'error.log');

// ─── URLs ──────────────────────────────────────────────────────────────────
define('SITE_URL_PATH', '/');
define('ASSETS_URL',    SITE_URL . '/assets');
define('UPLOAD_URL',    SITE_URL . '/uploads');

// ─── System-Einstellungen ──────────────────────────────────────────────────
define('DEFAULT_THEME',      'cms-default');
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_TIMEOUT',      300); // 5 Minuten
defined('CMS_HTTPS_REDIRECT_STRATEGY') || define('CMS_HTTPS_REDIRECT_STRATEGY', 'upstream');
defined('CMS_HSTS_MODE') || define('CMS_HSTS_MODE', 'https-only');
defined('CMS_HSTS_MAX_AGE') || define('CMS_HSTS_MAX_AGE', 31536000);

// ─── Fehler-Reporting ──────────────────────────────────────────────────────
if (CMS_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
    ini_set('error_log', CMS_ERROR_LOG);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', CMS_ERROR_LOG);
}

// ─── Zeitzone ──────────────────────────────────────────────────────────────
date_default_timezone_set('Europe/Berlin');
// ─── LDAP-Konfiguration (optional) ─────────────────────────────────────
// Für LDAP/Active-Directory-Authentifizierung.
// Leer lassen, wenn LDAP nicht verwendet wird.
defined('LDAP_HOST')         || define('LDAP_HOST',         '');       // z. B. 'ldap.example.com'
defined('LDAP_PORT')         || define('LDAP_PORT',         389);
defined('LDAP_BASE_DN')      || define('LDAP_BASE_DN',      '');       // z. B. 'dc=example,dc=com'
defined('LDAP_USERNAME')     || define('LDAP_USERNAME',      '');       // Service-Account DN
defined('LDAP_PASSWORD')     || define('LDAP_PASSWORD',      '');       // Service-Account Passwort
defined('LDAP_USE_SSL')      || define('LDAP_USE_SSL',      false);
defined('LDAP_USE_TLS')      || define('LDAP_USE_TLS',      true);
defined('LDAP_FILTER')       || define('LDAP_FILTER',       '');       // z. B. '(sAMAccountName={username})'
defined('LDAP_DEFAULT_ROLE') || define('LDAP_DEFAULT_ROLE', 'member'); // Rolle für neue LDAP-User

// ─── JWT-Konfiguration (API-Authentifizierung) ────────────────────────
// Wird für Bearer-Token-basierte API-Requests verwendet.
// Wenn JWT_SECRET leer ist, wird AUTH_KEY als Fallback verwendet.
defined('JWT_SECRET')        || define('JWT_SECRET',  '');     // Eigener HMAC-Key (bevorzugt)
defined('JWT_TTL')           || define('JWT_TTL',     3600);   // Token-Lebensdauer in Sekunden
defined('JWT_ISSUER')        || define('JWT_ISSUER',  SITE_URL);
// ─── E-Mail / SMTP-Konfiguration ──────────────────────────────────────────
// Wird durch den Admin-Bereich (Einstellungen › E-Mail) konfiguriert.
// Wenn SMTP_HOST leer ist, wird mail() als Fallback verwendet.
defined('SMTP_HOST')       || define('SMTP_HOST',       '');
defined('SMTP_PORT')       || define('SMTP_PORT',       587);
defined('SMTP_USER')       || define('SMTP_USER',       '');
defined('SMTP_PASS')       || define('SMTP_PASS',       '');
defined('SMTP_ENCRYPTION') || define('SMTP_ENCRYPTION', 'tls'); // 'tls', 'ssl', ''
defined('SMTP_FROM_EMAIL') || define('SMTP_FROM_EMAIL', ADMIN_EMAIL);
defined('SMTP_FROM_NAME')  || define('SMTP_FROM_NAME',  SITE_NAME);

// ─── Verschlüsselung gespeicherter Geheimnisse ─────────────────────────────
// Schlüssel für Einstellungs-Secrets, TOTP-Secrets und verschlüsselte Backups (wird durch install.php
// erzeugt; leer = Ableitung aus AUTH_KEY/SECURE_AUTH_KEY). Getrennt von Datenbank und Backups sichern!
// Rotation: neuen Schlüssel setzen und den bisherigen in CMS_ENCRYPTION_KEYS_PREVIOUS eintragen
// (mehrere durch Komma getrennt); gespeicherte Werte werden danach automatisch neu verschlüsselt.
defined('CMS_ENCRYPTION_KEY')           || define('CMS_ENCRYPTION_KEY',           '');
defined('CMS_ENCRYPTION_KEYS_PREVIOUS') || define('CMS_ENCRYPTION_KEYS_PREVIOUS', '');

// ─── Backups ───────────────────────────────────────────────────────────────
// Leer: CMS/backups/ (nur per .htaccess geschützt). Empfohlen: absoluter Pfad außerhalb des Webroots.
defined('CMS_BACKUP_PATH') || define('CMS_BACKUP_PATH', '');

// ─── Privater Speicher (geschützte Downloads, Formular-Uploads, Import/Export) ─
// Leer oder relativ: CMS/private/ (nur per .htaccess geschützt; Nginx: /private/ sperren).
// Empfohlen: absoluter Pfad außerhalb des Webroots.
defined('CMS_PRIVATE_PATH') || define('CMS_PRIVATE_PATH', '');
