<?php
/**
 * SchemaManager – DB-Schema-Erstell-Klasse (H-10)
 *
 * Verantwortlich für:
 * - Erstellung aller CMS-Tabellen (CREATE TABLE IF NOT EXISTS)
 * - Anlegen des Standard-Admin-Accounts beim Erst-Setup
 *
 * Ausgelagert aus Database.php, um die God-Klasse aufzuteilen.
 * Wird von Database::__construct() und Database::repairTables() aufgerufen.
 *
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS;

use PDO;
use PDOException;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists(__NAMESPACE__ . '\\SchemaManager', false)) {
    if (!defined('CMS_SCHEMA_MANAGER_LOADED')) {
        define('CMS_SCHEMA_MANAGER_LOADED', true);
    }

class SchemaManager
{
    /** Flag-Datei-Version – erhöhen wenn Schema geändert wird */
    public const SCHEMA_VERSION = 'v23';

    private Database $db;
    private string $prefix;
    private string $charset;

    public function __construct(Database $db)
    {
        $this->db     = $db;
        $this->prefix = $db->getPrefix();
        $this->charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
    }

    public static function getSchemaQueries(string $prefix, string $charset): array
    {
        $p = $prefix;
        $c = $charset;

        return [
            'users' => "CREATE TABLE IF NOT EXISTS {$p}users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(60) NOT NULL UNIQUE,
                email VARCHAR(100) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                display_name VARCHAR(100) NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'member',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_login TIMESTAMP NULL,
                INDEX idx_username (username),
                INDEX idx_email (email),
                INDEX idx_role (role)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'user_meta' => "CREATE TABLE IF NOT EXISTS {$p}user_meta (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                meta_key VARCHAR(255) NOT NULL,
                meta_value LONGTEXT,
                INDEX idx_user_id (user_id),
                INDEX idx_meta_key (meta_key),
                FOREIGN KEY (user_id) REFERENCES {$p}users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'roles' => "CREATE TABLE IF NOT EXISTS {$p}roles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(50) NOT NULL UNIQUE,
                display_name VARCHAR(100) NOT NULL,
                description TEXT,
                capabilities TEXT COMMENT 'JSON-Array mit Berechtigungen',
                member_dashboard_access TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Zugriff auf Member-Dashboard',
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'settings' => "CREATE TABLE IF NOT EXISTS {$p}settings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                option_name VARCHAR(255) NOT NULL UNIQUE,
                option_value LONGTEXT,
                autoload TINYINT(1) DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'sessions' => "CREATE TABLE IF NOT EXISTS {$p}sessions (
                id VARCHAR(128) PRIMARY KEY,
                user_id INT UNSIGNED,
                ip_address VARCHAR(45),
                user_agent VARCHAR(255),
                payload TEXT,
                last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NULL,
                INDEX idx_user_id (user_id),
                INDEX idx_last_activity (last_activity),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'passkey_credentials' => "CREATE TABLE IF NOT EXISTS {$p}passkey_credentials (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                credential_id VARCHAR(512) NOT NULL,
                public_key TEXT NOT NULL,
                sign_count INT UNSIGNED NOT NULL DEFAULT 0,
                aaguid VARCHAR(64) DEFAULT NULL,
                attestation_fmt VARCHAR(32) DEFAULT NULL,
                name VARCHAR(128) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_used_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_user (user_id),
                UNIQUE INDEX idx_cred_id (credential_id(255))
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'password_resets' => "CREATE TABLE IF NOT EXISTS {$p}password_resets (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NOT NULL,
                token CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY idx_token (token),
                INDEX idx_email (email),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'pages' => "CREATE TABLE IF NOT EXISTS {$p}pages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(200) NOT NULL UNIQUE,
                slug_en VARCHAR(200) DEFAULT NULL,
                title VARCHAR(255) NOT NULL,
                content LONGTEXT,
                excerpt TEXT,
                status VARCHAR(20) DEFAULT 'draft',
                hide_title TINYINT(1) NOT NULL DEFAULT 0,
                show_title_toc TINYINT(1) NOT NULL DEFAULT 0,
                featured_image VARCHAR(500) DEFAULT NULL,
                meta_title VARCHAR(255) DEFAULT NULL,
                meta_description TEXT DEFAULT NULL,
                page_template VARCHAR(80) DEFAULT NULL,
                page_meta_json TEXT DEFAULT NULL,
                author_id INT UNSIGNED,
                category_id INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                published_at TIMESTAMP NULL,
                content_updated_at DATETIME DEFAULT NULL,
                INDEX idx_slug (slug),
                INDEX idx_slug_en (slug_en),
                INDEX idx_status (status),
                INDEX idx_author (author_id),
                INDEX idx_category (category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'page_revisions' => "CREATE TABLE IF NOT EXISTS {$p}page_revisions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                page_id INT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                title_en VARCHAR(255) DEFAULT NULL,
                slug VARCHAR(200) DEFAULT NULL,
                slug_en VARCHAR(200) DEFAULT NULL,
                content LONGTEXT,
                content_en LONGTEXT,
                excerpt TEXT,
                status VARCHAR(20) DEFAULT NULL,
                author_id INT UNSIGNED,
                    content_updated_at DATETIME DEFAULT NULL,
                page_template VARCHAR(80) DEFAULT NULL,
                page_meta_json TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_page_id (page_id),
                INDEX idx_author (author_id),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'landing_sections' => "CREATE TABLE IF NOT EXISTS {$p}landing_sections (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(50) NOT NULL,
                data TEXT,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_type (type),
                INDEX idx_order (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'activity_log' => "CREATE TABLE IF NOT EXISTS {$p}activity_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED,
                action VARCHAR(100) NOT NULL,
                entity_type VARCHAR(100),
                entity_id BIGINT UNSIGNED,
                description TEXT,
                ip_address VARCHAR(45),
                user_agent VARCHAR(500),
                metadata LONGTEXT COMMENT 'JSON-Daten',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_action (action),
                INDEX idx_entity_type (entity_type),
                INDEX idx_entity_id (entity_id),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'cache' => "CREATE TABLE IF NOT EXISTS {$p}cache (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cache_key VARCHAR(191) NOT NULL UNIQUE,
                cache_value LONGTEXT,
                expires_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_key (cache_key),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'login_attempts' => "CREATE TABLE IF NOT EXISTS {$p}login_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(60),
                ip_address VARCHAR(45),
                action VARCHAR(30) NOT NULL DEFAULT 'login',
                attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_username (username),
                INDEX idx_ip (ip_address),
                INDEX idx_action (action),
                INDEX idx_ip_action (ip_address, action),
                INDEX idx_time (attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'plugins' => "CREATE TABLE IF NOT EXISTS {$p}plugins (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                slug VARCHAR(100) NOT NULL UNIQUE,
                version VARCHAR(20) NOT NULL,
                author VARCHAR(100),
                description TEXT,
                plugin_path VARCHAR(255) NOT NULL,
                is_active TINYINT(1) DEFAULT 0,
                auto_update TINYINT(1) DEFAULT 0,
                settings LONGTEXT COMMENT 'JSON-Daten für Plugin-Einstellungen',
                installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                activated_at TIMESTAMP NULL,
                INDEX idx_slug (slug),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'plugin_meta' => "CREATE TABLE IF NOT EXISTS {$p}plugin_meta (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                plugin_id INT UNSIGNED NOT NULL,
                meta_key VARCHAR(255) NOT NULL,
                meta_value LONGTEXT,
                INDEX idx_plugin_id (plugin_id),
                INDEX idx_meta_key (meta_key),
                FOREIGN KEY (plugin_id) REFERENCES {$p}plugins(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'theme_customizations' => "CREATE TABLE IF NOT EXISTS {$p}theme_customizations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                theme_slug VARCHAR(100) NOT NULL,
                setting_category VARCHAR(100) NOT NULL COMMENT 'Kategorie aus theme.json',
                setting_key VARCHAR(255) NOT NULL COMMENT 'Einstellungs-Key aus theme.json',
                setting_value LONGTEXT COMMENT 'Wert der Einstellung',
                user_id INT UNSIGNED NULL COMMENT 'Optional: User-spezifische Anpassungen',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_theme_slug (theme_slug),
                INDEX idx_category (setting_category),
                INDEX idx_key (setting_key),
                INDEX idx_user_id (user_id),
                UNIQUE KEY unique_theme_setting (theme_slug, setting_category, setting_key, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'subscription_plans' => "CREATE TABLE IF NOT EXISTS {$p}subscription_plans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL UNIQUE,
                description TEXT,
                price_monthly DECIMAL(10,2) DEFAULT 0.00,
                price_yearly DECIMAL(10,2) DEFAULT 0.00,
                limit_experts INT DEFAULT -1,
                limit_companies INT DEFAULT -1,
                limit_events INT DEFAULT -1,
                limit_speakers INT DEFAULT -1,
                limit_storage_mb INT DEFAULT 1000,
                plugin_experts BOOLEAN DEFAULT 1,
                plugin_companies BOOLEAN DEFAULT 1,
                plugin_events BOOLEAN DEFAULT 1,
                plugin_speakers BOOLEAN DEFAULT 1,
                feature_analytics BOOLEAN DEFAULT 0,
                feature_advanced_search BOOLEAN DEFAULT 0,
                feature_api_access BOOLEAN DEFAULT 0,
                feature_custom_branding BOOLEAN DEFAULT 0,
                feature_priority_support BOOLEAN DEFAULT 0,
                feature_export_data BOOLEAN DEFAULT 0,
                feature_integrations BOOLEAN DEFAULT 0,
                feature_custom_domains BOOLEAN DEFAULT 0,
                is_active BOOLEAN DEFAULT 1,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_slug (slug),
                INDEX idx_active (is_active),
                INDEX idx_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'user_subscriptions' => "CREATE TABLE IF NOT EXISTS {$p}user_subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                plan_id INT NOT NULL,
                status ENUM('active','cancelled','expired','trial','suspended') DEFAULT 'active',
                billing_cycle ENUM('monthly','yearly','lifetime') DEFAULT 'monthly',
                start_date DATETIME NOT NULL,
                end_date DATETIME,
                next_billing_date DATETIME,
                cancelled_at DATETIME,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_plan_id (plan_id),
                INDEX idx_status (status),
                INDEX idx_dates (start_date, end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'user_groups' => "CREATE TABLE IF NOT EXISTS {$p}user_groups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL UNIQUE,
                description TEXT,
                role_id INT UNSIGNED NULL COMMENT 'Verknüpfte RBAC-Rolle',
                plan_id INT,
                is_active BOOLEAN DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_slug (slug),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'user_group_members' => "CREATE TABLE IF NOT EXISTS {$p}user_group_members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                group_id INT NOT NULL,
                joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_group (user_id, group_id),
                INDEX idx_user_id (user_id),
                INDEX idx_group_id (group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'subscription_usage' => "CREATE TABLE IF NOT EXISTS {$p}subscription_usage (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                resource_type VARCHAR(50) NOT NULL,
                current_count INT DEFAULT 0,
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_resource (user_id, resource_type),
                INDEX idx_user_id (user_id),
                INDEX idx_resource (resource_type)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'post_categories' => "CREATE TABLE IF NOT EXISTS {$p}post_categories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL UNIQUE,
                slug_en VARCHAR(100) DEFAULT NULL,
                description TEXT,
                parent_id INT UNSIGNED DEFAULT NULL,
                sort_order INT DEFAULT 0,
                replacement_category_id INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_slug (slug),
                INDEX idx_slug_en (slug_en),
                INDEX idx_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'posts' => "CREATE TABLE IF NOT EXISTS {$p}posts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                title_en VARCHAR(255) DEFAULT NULL,
                slug VARCHAR(255) NOT NULL UNIQUE,
                slug_en VARCHAR(255) DEFAULT NULL,
                content LONGTEXT,
                content_en LONGTEXT DEFAULT NULL,
                excerpt TEXT,
                excerpt_en TEXT DEFAULT NULL,
                featured_image VARCHAR(500),
                status ENUM('draft','published','trash') NOT NULL DEFAULT 'draft',
                author_id INT UNSIGNED NOT NULL,
                author_display_name VARCHAR(150) DEFAULT NULL,
                author_display_url VARCHAR(500) DEFAULT NULL,
                post_template VARCHAR(80) DEFAULT NULL,
                post_meta_json TEXT DEFAULT NULL,
                category_id INT UNSIGNED DEFAULT NULL,
                tags VARCHAR(500) COMMENT 'Kommagetrennte Tags',
                views INT UNSIGNED DEFAULT 0,
                allow_comments TINYINT(1) NOT NULL DEFAULT 1,
                meta_title VARCHAR(255),
                meta_description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                published_at TIMESTAMP NULL,
                content_updated_at DATETIME DEFAULT NULL,
                INDEX idx_slug (slug),
                INDEX idx_slug_en (slug_en),
                INDEX idx_status (status),
                INDEX idx_author (author_id),
                INDEX idx_category (category_id),
                INDEX idx_published (published_at),
                INDEX idx_status_published (status, published_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'post_revisions' => "CREATE TABLE IF NOT EXISTS {$p}post_revisions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                title_en VARCHAR(255) DEFAULT NULL,
                slug VARCHAR(255) DEFAULT NULL,
                slug_en VARCHAR(255) DEFAULT NULL,
                content LONGTEXT,
                content_en LONGTEXT,
                excerpt TEXT,
                excerpt_en TEXT,
                status VARCHAR(20) DEFAULT NULL,
                category_id INT UNSIGNED DEFAULT NULL,
                category_name VARCHAR(150) DEFAULT NULL,
                tags VARCHAR(500) DEFAULT NULL,
                author_id INT UNSIGNED DEFAULT NULL,
                author_display_name VARCHAR(150) DEFAULT NULL,
                author_display_url VARCHAR(500) DEFAULT NULL,
                published_at TIMESTAMP NULL,
                    content_updated_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_post_id (post_id),
                INDEX idx_author_id (author_id),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'orders' => "CREATE TABLE IF NOT EXISTS {$p}orders (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_number VARCHAR(64) NOT NULL UNIQUE,
                user_id INT UNSIGNED NULL,
                plan_id INT UNSIGNED NULL,
                customer_name VARCHAR(200) DEFAULT NULL,
                customer_email VARCHAR(200) DEFAULT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                payment_method VARCHAR(50) DEFAULT NULL,
                billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly',
                payment_ref VARCHAR(200) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                contact_data LONGTEXT DEFAULT NULL,
                forename VARCHAR(100) DEFAULT NULL,
                lastname VARCHAR(100) DEFAULT NULL,
                company VARCHAR(100) DEFAULT NULL,
                email VARCHAR(150) DEFAULT NULL,
                phone VARCHAR(50) DEFAULT NULL,
                street VARCHAR(255) DEFAULT NULL,
                zip VARCHAR(20) DEFAULT NULL,
                city VARCHAR(100) DEFAULT NULL,
                country VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_order_number (order_number),
                INDEX idx_user_id (user_id),
                INDEX idx_plan_id (plan_id),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'blocked_ips' => "CREATE TABLE IF NOT EXISTS {$p}blocked_ips (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL UNIQUE,
                reason VARCHAR(255),
                expires_at DATETIME,
                permanent TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_ip (ip_address),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'failed_logins' => "CREATE TABLE IF NOT EXISTS {$p}failed_logins (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(60),
                ip_address VARCHAR(45),
                attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                user_agent VARCHAR(255),
                INDEX idx_username (username),
                INDEX idx_ip (ip_address),
                INDEX idx_time (attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'media' => "CREATE TABLE IF NOT EXISTS {$p}media (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL,
                filepath VARCHAR(500) NOT NULL,
                filetype VARCHAR(50),
                filesize INT UNSIGNED,
                title VARCHAR(255),
                alt_text VARCHAR(255),
                caption TEXT,
                uploaded_by INT UNSIGNED,
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_type (filetype),
                INDEX idx_uploader (uploaded_by)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'page_views' => "CREATE TABLE IF NOT EXISTS {$p}page_views (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                page_id INT UNSIGNED NULL,
                page_slug VARCHAR(200),
                page_title VARCHAR(255),
                user_id INT UNSIGNED NULL,
                session_id VARCHAR(128),
                ip_address VARCHAR(45),
                user_agent VARCHAR(500),
                referrer VARCHAR(500),
                visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_page_id (page_id),
                INDEX idx_page_slug (page_slug),
                INDEX idx_user_id (user_id),
                INDEX idx_session_id (session_id),
                INDEX idx_visited_at (visited_at),
                INDEX idx_date (visited_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'core_web_vitals' => "CREATE TABLE IF NOT EXISTS {$p}core_web_vitals (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                page_path VARCHAR(500) NOT NULL,
                page_title VARCHAR(255) DEFAULT '',
                user_id INT UNSIGNED NULL,
                session_id VARCHAR(128) DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT '',
                user_agent VARCHAR(500) DEFAULT '',
                device_type VARCHAR(20) DEFAULT 'unknown',
                effective_connection VARCHAR(32) DEFAULT '',
                navigation_type VARCHAR(32) DEFAULT '',
                viewport_width SMALLINT UNSIGNED NULL,
                viewport_height SMALLINT UNSIGNED NULL,
                ttfb_ms INT UNSIGNED NULL,
                lcp_ms INT UNSIGNED NULL,
                inp_ms INT UNSIGNED NULL,
                cls DECIMAL(6,3) NULL,
                recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_page_path (page_path(191)),
                INDEX idx_user_id (user_id),
                INDEX idx_session_id (session_id),
                INDEX idx_recorded_at (recorded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'comments' => "CREATE TABLE IF NOT EXISTS {$p}comments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                user_id INT UNSIGNED NULL,
                author VARCHAR(100) NOT NULL DEFAULT '',
                author_email VARCHAR(150) NOT NULL DEFAULT '',
                author_ip VARCHAR(45) DEFAULT '',
                content TEXT NOT NULL,
                status ENUM('pending','approved','spam','trash') NOT NULL DEFAULT 'pending',
                post_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                modified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_post_id (post_id),
                INDEX idx_status (status),
                INDEX idx_post_date (post_date),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c} COMMENT='Kommentare'",

            'messages' => "CREATE TABLE IF NOT EXISTS {$p}messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sender_id INT UNSIGNED NOT NULL,
                recipient_id INT UNSIGNED NOT NULL,
                subject VARCHAR(255) NOT NULL DEFAULT '',
                body TEXT NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                read_at TIMESTAMP NULL,
                parent_id BIGINT UNSIGNED NULL COMMENT 'Antwort auf andere Nachricht (Thread)',
                deleted_by_sender TINYINT(1) NOT NULL DEFAULT 0,
                deleted_by_recipient TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sender (sender_id),
                INDEX idx_recipient (recipient_id),
                INDEX idx_parent (parent_id),
                INDEX idx_is_read (is_read),
                INDEX idx_created_at (created_at),
                FOREIGN KEY (sender_id) REFERENCES {$p}users(id) ON DELETE CASCADE,
                FOREIGN KEY (recipient_id) REFERENCES {$p}users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET={$c} COMMENT='Benutzer-Nachrichten (Member-Dashboard)'",

            'audit_log' => "CREATE TABLE IF NOT EXISTS {$p}audit_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                category VARCHAR(50) NOT NULL COMMENT 'auth|theme|plugin|user|setting|media|system|security',
                action VARCHAR(100) NOT NULL,
                entity_type VARCHAR(100),
                entity_id BIGINT UNSIGNED NULL,
                description TEXT,
                ip_address VARCHAR(45),
                user_agent VARCHAR(500),
                metadata LONGTEXT COMMENT 'JSON-Daten',
                severity ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_category (category),
                INDEX idx_action (action),
                INDEX idx_severity (severity),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'ai_quota_usage' => "CREATE TABLE IF NOT EXISTS {$p}ai_quota_usage (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                scope_name VARCHAR(40) NOT NULL,
                period_key VARCHAR(10) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                provider_id VARCHAR(80) NOT NULL DEFAULT '',
                request_count INT UNSIGNED NOT NULL DEFAULT 0,
                character_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_ai_quota_scope_period (scope_name, period_key, user_id, provider_id),
                INDEX idx_ai_quota_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'mail_log' => "CREATE TABLE IF NOT EXISTS {$p}mail_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                recipient VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
                transport VARCHAR(50) NOT NULL DEFAULT 'smtp',
                provider VARCHAR(50) NOT NULL DEFAULT 'default',
                message_id VARCHAR(255) DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                meta LONGTEXT COMMENT 'JSON-Daten',
                source VARCHAR(100) NOT NULL DEFAULT 'system',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_recipient (recipient),
                INDEX idx_created_at (created_at),
                INDEX idx_source (source)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'mail_queue' => "CREATE TABLE IF NOT EXISTS {$p}mail_queue (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                recipient VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body LONGTEXT,
                headers LONGTEXT COMMENT 'JSON-Daten',
                content_type VARCHAR(20) NOT NULL DEFAULT 'html',
                source VARCHAR(100) NOT NULL DEFAULT 'system',
                status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
                available_at DATETIME DEFAULT NULL,
                sent_at DATETIME DEFAULT NULL,
                locked_at DATETIME DEFAULT NULL,
                last_attempt_at DATETIME DEFAULT NULL,
                attachment_path VARCHAR(500) DEFAULT NULL,
                attachment_name VARCHAR(255) DEFAULT NULL,
                attachment_mime VARCHAR(150) DEFAULT NULL,
                error_category VARCHAR(50) DEFAULT NULL,
                last_error TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_status_available (status, available_at),
                INDEX idx_available_at (available_at),
                INDEX idx_locked_at (locked_at),
                INDEX idx_error_category (error_category),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'custom_fonts' => "CREATE TABLE IF NOT EXISTS {$p}custom_fonts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL,
                format VARCHAR(20) NOT NULL DEFAULT 'woff2',
                file_path VARCHAR(500) NOT NULL,
                css_path VARCHAR(500) DEFAULT NULL,
                source VARCHAR(50) NOT NULL DEFAULT 'upload' COMMENT 'upload|google-fonts-local',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY idx_slug (slug),
                INDEX idx_source (source)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c}",

            'site_tables' => "CREATE TABLE IF NOT EXISTS {$p}site_tables (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                table_name VARCHAR(255) NOT NULL,
                table_slug VARCHAR(191) DEFAULT NULL,
                description TEXT DEFAULT NULL,
                columns_json LONGTEXT DEFAULT NULL,
                rows_json LONGTEXT DEFAULT NULL,
                settings_json LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_table_slug (table_slug),
                INDEX idx_table_name (table_name),
                INDEX idx_updated_at (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c} COMMENT='Site-Tabellen und Hub-Inhalte'",

            'favorites' => "CREATE TABLE IF NOT EXISTS {$p}favorites (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                post_id BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_post (user_id, post_id),
                INDEX idx_user_id (user_id),
                INDEX idx_post_id (post_id),
                FOREIGN KEY (user_id) REFERENCES {$p}users(id) ON DELETE CASCADE,
                FOREIGN KEY (post_id) REFERENCES {$p}posts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET={$c} COMMENT='Benutzer-Merkliste'",

            'notifications' => "CREATE TABLE IF NOT EXISTS {$p}notifications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                type VARCHAR(50) NOT NULL DEFAULT 'system' COMMENT 'system|message|comment|event',
                title VARCHAR(255) DEFAULT NULL,
                message TEXT,
                url VARCHAR(500) DEFAULT NULL COMMENT 'Ziel-Link (optional)',
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                read_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_is_read (is_read),
                INDEX idx_type (type),
                INDEX idx_user_created (user_id, created_at),
                INDEX idx_created_at (created_at),
                FOREIGN KEY (user_id) REFERENCES {$p}users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET={$c} COMMENT='Member-Benachrichtigungen'",

            'security_log' => "CREATE TABLE IF NOT EXISTS {$p}security_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                action VARCHAR(50) NOT NULL DEFAULT 'blocked' COMMENT 'blocked|allowed|rate_limited|challenge|simulated',
                ip_address VARCHAR(45) DEFAULT NULL,
                request_uri VARCHAR(1000) DEFAULT NULL,
                user_agent VARCHAR(500) DEFAULT NULL,
                rule_matched VARCHAR(255) DEFAULT NULL,
                user_id INT UNSIGNED DEFAULT NULL,
                extra TEXT DEFAULT NULL COMMENT 'JSON-Zusatzdaten',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_action (action),
                INDEX idx_ip_address (ip_address),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c} COMMENT='Firewall-Sicherheitsprotokoll'",

            'post_tags' => "CREATE TABLE IF NOT EXISTS {$p}post_tags (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL UNIQUE,
                slug_en VARCHAR(100) DEFAULT NULL,
                description TEXT,
                post_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_slug (slug),
                INDEX idx_slug_en (slug_en)
            ) ENGINE=InnoDB DEFAULT CHARSET={$c} COMMENT='Blog-Schlagwörter'",

            'post_tag_rel' => "CREATE TABLE IF NOT EXISTS {$p}post_tag_rel (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id BIGINT UNSIGNED NOT NULL,
                tag_id INT UNSIGNED NOT NULL,
                UNIQUE KEY unique_post_tag (post_id, tag_id),
                INDEX idx_post_id (post_id),
                INDEX idx_tag_id (tag_id),
                FOREIGN KEY (post_id) REFERENCES {$p}posts(id) ON DELETE CASCADE,
                FOREIGN KEY (tag_id) REFERENCES {$p}post_tags(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET={$c} COMMENT='Post-Tag-Zuordnung'",

            'post_category_rel' => "CREATE TABLE IF NOT EXISTS {$p}post_category_rel (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id BIGINT UNSIGNED NOT NULL,
                category_id INT UNSIGNED NOT NULL,
                UNIQUE KEY unique_post_category (post_id, category_id),
                INDEX idx_post_id (post_id),
                INDEX idx_category_id (category_id),
                FOREIGN KEY (post_id) REFERENCES {$p}posts(id) ON DELETE CASCADE,
                FOREIGN KEY (category_id) REFERENCES {$p}post_categories(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET={$c} COMMENT='Post-Kategorie-Zuordnung'",
        ];
    }

    /**
     * Gibt den Pfad zur Flag-Datei zurück.
     */
    public function getFlagFile(): string
    {
        return ABSPATH . 'cache/db_schema_' . self::SCHEMA_VERSION . '.flag';
    }

    /**
     * Löscht die Schema-Flag-Datei, damit createTables() bei einer Reparatur
     * das vollständige Schema erneut durchläuft.
     */
    public function clearFlag(): void
    {
        $flagFile = $this->getFlagFile();

        if (!file_exists($flagFile)) {
            return;
        }

        if (!@unlink($flagFile) && file_exists($flagFile)) {
            error_log('SchemaManager::clearFlag() konnte Flag-Datei nicht löschen: ' . $flagFile);
        }
    }

    /**
     * Erstellt alle CMS-Tabellen (idempotent via CREATE TABLE IF NOT EXISTS).
     * Wird beim ersten Request und nach repairTables() ausgeführt.
     */
    public function createTables(): void
    {
        $flagFile = $this->getFlagFile();
        if (file_exists($flagFile)) {
            $this->ensureRuntimeSchema();
            return;
        }

        $p = $this->prefix;
        $c = $this->charset;
        $queries = array_values(self::getSchemaQueries($p, $c));

        $pdo = $this->db->getPdo();
        foreach ($queries as $query) {
            try {
                $pdo->exec($query);
            } catch (PDOException $e) {
                error_log('SchemaManager::createTables() failed: ' . $this->sanitizeDiagnosticText($e->getMessage()));
            }
        }

        // Migrations ausführen (fehlende Spalten ergänzen)
        (new MigrationManager($this->db))->run();
        $this->ensureContentColumns();
        $this->ensureRuntimeSchema();

        // Standard-Admin anlegen falls noch kein Admin existiert
        $this->createDefaultAdmin();

        // Flag-Datei schreiben
        $cacheDir = ABSPATH . 'cache/';
        if (!is_dir($cacheDir)) {
            if (!mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
                error_log('SchemaManager: Cache-Verzeichnis konnte nicht erstellt werden: ' . $cacheDir);
            }
        }
        if (file_put_contents($flagFile, date('Y-m-d H:i:s')) === false) {
            error_log('SchemaManager: Flag-Datei konnte nicht geschrieben werden: ' . $flagFile);
        }
    }

    /**
     * Legt den Standard-Admin-Account an, wenn kein Admin-User vorhanden ist.
     */
    private function createDefaultAdmin(): void
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS cnt FROM {$this->prefix}users WHERE role = 'admin'"
            );
            if (!$stmt) {
                return;
            }
            $stmt->execute();
            $result = $stmt->fetch();

            if ($result && (int) $result->cnt === 0) {
                // Generate a cryptographically secure random password
                $randomPassword = bin2hex(random_bytes(16)); // 32 chars hex

                $this->db->insert('users', [
                    'username'     => 'admin',
                    'email'        => defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'admin@localhost',
                    'password'     => password_hash($randomPassword, PASSWORD_BCRYPT),
                    'display_name' => 'Administrator',
                    'role'         => 'admin',
                    'status'       => 'active',
                ]);

                // Write to a one-time file for first login
                $credFile = dirname(__DIR__) . '/logs/admin-credentials.txt';
                $credentialWritten = @file_put_contents($credFile, "Default Admin Password: {$randomPassword}\nGenerated: " . date('Y-m-d H:i:s') . "\nDELETE THIS FILE AFTER FIRST LOGIN!\n") !== false;
                if ($credentialWritten) {
                    @chmod($credFile, 0600);
                    error_log('[365CMS] Default admin created. Temp credential written to logs/admin-credentials.txt.');
                } else {
                    error_log('[365CMS] Default admin created, but temp credential file could not be written.');
                }
            }
        } catch (\Exception $e) {
            error_log('SchemaManager::createDefaultAdmin() Error: ' . $this->sanitizeDiagnosticText($e->getMessage()));
        }
    }

    /**
     * Ergänzt fehlende Content-/SEO-Spalten in Altinstallationen.
     */
    private function ensureContentColumns(): void
    {
        $this->ensureColumnExists(
            $this->prefix . 'pages',
            'featured_image',
            "ALTER TABLE {$this->prefix}pages ADD COLUMN featured_image VARCHAR(500) DEFAULT NULL AFTER hide_title"
        );
        $this->ensureColumnExists(
            $this->prefix . 'pages',
            'meta_title',
            "ALTER TABLE {$this->prefix}pages ADD COLUMN meta_title VARCHAR(255) DEFAULT NULL AFTER featured_image"
        );
        $this->ensureColumnExists(
            $this->prefix . 'pages',
            'meta_description',
            "ALTER TABLE {$this->prefix}pages ADD COLUMN meta_description TEXT DEFAULT NULL AFTER meta_title"
        );
        $this->ensureColumnExists(
            $this->prefix . 'posts',
            'featured_image',
            "ALTER TABLE {$this->prefix}posts ADD COLUMN featured_image VARCHAR(500) DEFAULT NULL AFTER excerpt"
        );
        $this->ensureColumnExists(
            $this->prefix . 'posts',
            'meta_title',
            "ALTER TABLE {$this->prefix}posts ADD COLUMN meta_title VARCHAR(255) DEFAULT NULL AFTER allow_comments"
        );
        $this->ensureColumnExists(
            $this->prefix . 'posts',
            'meta_description',
            "ALTER TABLE {$this->prefix}posts ADD COLUMN meta_description TEXT DEFAULT NULL AFTER meta_title"
        );
        $this->ensureColumnExists(
            $this->prefix . 'posts',
            'author_display_name',
            "ALTER TABLE {$this->prefix}posts ADD COLUMN author_display_name VARCHAR(150) DEFAULT NULL AFTER author_id"
        );
        $this->ensureColumnExists(
            $this->prefix . 'posts',
            'author_display_url',
            "ALTER TABLE {$this->prefix}posts ADD COLUMN author_display_url VARCHAR(500) DEFAULT NULL AFTER author_display_name"
        );
        $this->ensureColumnExists(
            $this->prefix . 'posts',
            'content_updated_at',
            "ALTER TABLE {$this->prefix}posts ADD COLUMN content_updated_at DATETIME DEFAULT NULL AFTER published_at"
        );
        $this->ensureColumnExists(
            $this->prefix . 'pages',
            'content_updated_at',
            "ALTER TABLE {$this->prefix}pages ADD COLUMN content_updated_at DATETIME DEFAULT NULL AFTER published_at"
        );
        foreach (['pages', 'page_revisions'] as $table) {
            $this->ensureColumnExists(
                $this->prefix . $table,
                'page_template',
                "ALTER TABLE {$this->prefix}{$table} ADD COLUMN page_template VARCHAR(80) DEFAULT NULL"
            );
            $this->ensureColumnExists(
                $this->prefix . $table,
                'page_meta_json',
                "ALTER TABLE {$this->prefix}{$table} ADD COLUMN page_meta_json TEXT DEFAULT NULL"
            );
        }
    }

    private function ensureRuntimeSchema(): void
    {
        $passwordResetSql = self::getSchemaQueries($this->prefix, $this->charset)['password_resets'] ?? '';
        if ($passwordResetSql !== '') {
            try {
                $this->db->getPdo()->exec($passwordResetSql);
            } catch (\Throwable $e) {
                error_log('SchemaManager::ensureRuntimeSchema() password_resets failed: ' . $this->sanitizeDiagnosticText($e->getMessage()));
            }
        }

        $siteTablesSql = self::getSchemaQueries($this->prefix, $this->charset)['site_tables'] ?? '';
        if ($siteTablesSql !== '') {
            try {
                $this->db->getPdo()->exec($siteTablesSql);
            } catch (\Throwable $e) {
                error_log('SchemaManager::ensureRuntimeSchema() site_tables failed: ' . $this->sanitizeDiagnosticText($e->getMessage()));
            }
        }

        $aiQuotaSql = self::getSchemaQueries($this->prefix, $this->charset)['ai_quota_usage'] ?? '';
        if ($aiQuotaSql !== '') {
            try {
                $this->db->getPdo()->exec($aiQuotaSql);
            } catch (\Throwable $e) {
                error_log('SchemaManager::ensureRuntimeSchema() ai_quota_usage failed: ' . $this->sanitizeDiagnosticText($e->getMessage()));
            }
        }

        $this->ensureColumnExists(
            $this->prefix . 'site_tables',
            'table_slug',
            "ALTER TABLE {$this->prefix}site_tables ADD COLUMN table_slug VARCHAR(191) DEFAULT NULL AFTER table_name"
        );

        $this->ensureColumnExists(
            $this->prefix . 'post_categories',
            'slug_en',
            "ALTER TABLE {$this->prefix}post_categories ADD COLUMN slug_en VARCHAR(100) DEFAULT NULL AFTER slug"
        );

        $this->ensureColumnExists(
            $this->prefix . 'post_tags',
            'slug_en',
            "ALTER TABLE {$this->prefix}post_tags ADD COLUMN slug_en VARCHAR(100) DEFAULT NULL AFTER slug"
        );

        $ordersSql = self::getSchemaQueries($this->prefix, $this->charset)['orders'] ?? '';
        if ($ordersSql !== '') {
            try {
                $this->db->getPdo()->exec($ordersSql);
            } catch (\Throwable $e) {
                error_log('SchemaManager::ensureRuntimeSchema() orders failed: ' . $this->sanitizeDiagnosticText($e->getMessage()));
            }
        }

        $this->ensureOrdersRuntimeSchema();
        $this->ensureAuditLogRuntimeSchema();
    }

    private function ensureAuditLogRuntimeSchema(): void
    {
        $table = $this->prefix . 'audit_log';

        try {
            $this->db->query("ALTER TABLE {$table} MODIFY COLUMN severity ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info'");
        } catch (\Throwable $e) {
            error_log('SchemaManager::ensureAuditLogRuntimeSchema() severity failed: ' . $this->sanitizeDiagnosticText($e->getMessage()));
        }
    }

    private function ensureOrdersRuntimeSchema(): void
    {
        $table = $this->prefix . 'orders';

        $this->ensureColumnExists(
            $table,
            'plan_id',
            "ALTER TABLE {$table} ADD COLUMN plan_id INT UNSIGNED DEFAULT NULL AFTER user_id"
        );
        $this->ensureColumnExists(
            $table,
            'customer_name',
            "ALTER TABLE {$table} ADD COLUMN customer_name VARCHAR(200) DEFAULT NULL AFTER plan_id"
        );
        $this->ensureColumnExists(
            $table,
            'customer_email',
            "ALTER TABLE {$table} ADD COLUMN customer_email VARCHAR(200) DEFAULT NULL AFTER customer_name"
        );
        $this->ensureColumnExists(
            $table,
            'amount',
            "ALTER TABLE {$table} ADD COLUMN amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER customer_email"
        );
        $this->ensureColumnExists(
            $table,
            'tax_amount',
            "ALTER TABLE {$table} ADD COLUMN tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER amount"
        );
        $this->ensureColumnExists(
            $table,
            'total_amount',
            "ALTER TABLE {$table} ADD COLUMN total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tax_amount"
        );
        $this->ensureColumnExists(
            $table,
            'currency',
            "ALTER TABLE {$table} ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'EUR' AFTER total_amount"
        );
        $this->ensureColumnExists(
            $table,
            'payment_method',
            "ALTER TABLE {$table} ADD COLUMN payment_method VARCHAR(50) DEFAULT NULL AFTER status"
        );
        $this->ensureColumnExists(
            $table,
            'billing_cycle',
            "ALTER TABLE {$table} ADD COLUMN billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly' AFTER payment_method"
        );
        $this->ensureColumnExists(
            $table,
            'payment_ref',
            "ALTER TABLE {$table} ADD COLUMN payment_ref VARCHAR(200) DEFAULT NULL AFTER billing_cycle"
        );
        $this->ensureColumnExists(
            $table,
            'notes',
            "ALTER TABLE {$table} ADD COLUMN notes TEXT DEFAULT NULL AFTER payment_ref"
        );
        $this->ensureColumnExists(
            $table,
            'contact_data',
            "ALTER TABLE {$table} ADD COLUMN contact_data LONGTEXT DEFAULT NULL AFTER notes"
        );
        $this->ensureColumnExists(
            $table,
            'forename',
            "ALTER TABLE {$table} ADD COLUMN forename VARCHAR(100) DEFAULT NULL AFTER contact_data"
        );
        $this->ensureColumnExists(
            $table,
            'lastname',
            "ALTER TABLE {$table} ADD COLUMN lastname VARCHAR(100) DEFAULT NULL AFTER forename"
        );
        $this->ensureColumnExists(
            $table,
            'company',
            "ALTER TABLE {$table} ADD COLUMN company VARCHAR(100) DEFAULT NULL AFTER lastname"
        );
        $this->ensureColumnExists(
            $table,
            'email',
            "ALTER TABLE {$table} ADD COLUMN email VARCHAR(150) DEFAULT NULL AFTER company"
        );
        $this->ensureColumnExists(
            $table,
            'phone',
            "ALTER TABLE {$table} ADD COLUMN phone VARCHAR(50) DEFAULT NULL AFTER email"
        );
        $this->ensureColumnExists(
            $table,
            'street',
            "ALTER TABLE {$table} ADD COLUMN street VARCHAR(255) DEFAULT NULL AFTER phone"
        );
        $this->ensureColumnExists(
            $table,
            'zip',
            "ALTER TABLE {$table} ADD COLUMN zip VARCHAR(20) DEFAULT NULL AFTER street"
        );
        $this->ensureColumnExists(
            $table,
            'city',
            "ALTER TABLE {$table} ADD COLUMN city VARCHAR(100) DEFAULT NULL AFTER zip"
        );
        $this->ensureColumnExists(
            $table,
            'country',
            "ALTER TABLE {$table} ADD COLUMN country VARCHAR(100) DEFAULT NULL AFTER city"
        );

        try {
            $this->db->query("ALTER TABLE {$table} MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending'");
        } catch (\Throwable $e) {
            error_log('SchemaManager::ensureOrdersRuntimeSchema() status failed: ' . $this->sanitizeDiagnosticText($e->getMessage()));
        }
    }

    private function sanitizeDiagnosticText(string $value, int $maxLength = 500): string
    {
        $value = preg_replace('/([?&](?:token|csrf_token|nonce|key|secret|password|pass)=)[^&\s]+/i', '$1***', $value) ?? $value;
        $value = preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/-]+=*/i', '$1***', $value) ?? $value;
        $value = preg_replace('/(password|passwd|secret|token|credential|api[_-]?key)(\s*[=:]\s*)\S+/i', '$1$2***', $value) ?? $value;
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
    }

    /**
     * Führt ein ALTER TABLE nur aus, wenn die Spalte fehlt.
     */
    private function ensureColumnExists(string $table, string $column, string $alterSql): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            return;
        }

        $expectedPrefix = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ';
        if (!str_starts_with($alterSql, $expectedPrefix)) {
            return;
        }

        try {
            if (!$this->db->columnExists($table, $column)) {
                $this->db->query($alterSql);
            }
        } catch (\Throwable $e) {
            error_log(sprintf('SchemaManager::ensureColumnExists(%s.%s) failed: %s', $table, $column, $this->sanitizeDiagnosticText($e->getMessage())));
        }
    }
}
}
