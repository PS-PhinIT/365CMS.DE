<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CMS_Role_Stub')) {
    class CMS_Role_Stub {
        public string $name;
        public array $capabilities = [];
        public string $display_name;

        public function __construct(string $name, array $caps = [], ?string $displayName = null) {
            $this->name = $name;
            $this->capabilities = $caps;
            $this->display_name = $displayName ?? cms_humanize_role_slug($name);
        }

        public function add_cap($cap, $grant = true): void {
            $capability = cms_normalize_role_capability((string) $cap);
            if ($capability === '') {
                return;
            }

            $this->capabilities[$capability] = (bool) $grant;
            cms_store_role_capability($this->name, $capability, (bool) $grant);
        }

        public function remove_cap($cap): void {
            $capability = cms_normalize_role_capability((string) $cap);
            if ($capability === '') {
                return;
            }

            $this->capabilities[$capability] = false;
            cms_store_role_capability($this->name, $capability, false);
        }

        public function has_cap($cap): bool {
            $capability = cms_normalize_role_capability((string) $cap);
            return $capability !== '' && !empty($this->capabilities[$capability]);
        }
    }
}

/**
 * WordPress-Kompatibilitäts-Registry für Rollen-Metadaten.
 */
function cms_get_wp_role_registry(): array {
    $raw = get_option('wp_compat_role_registry', '');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = \CMS\Json::decodeArray($raw, []);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Persistiert die WP-Kompatibilitäts-Registry.
 */
function cms_save_wp_role_registry(array $registry): bool {
    return update_option('wp_compat_role_registry', $registry);
}

/**
 * Alias-/Slug-Normalisierung für Rollen.
 */
function cms_normalize_role_slug(string $role): string {
    $role = strtolower(trim($role));
    if ($role === 'administrator') {
        $role = 'admin';
    }

    $role = preg_replace('/[^a-z0-9_-]+/', '-', $role) ?? '';
    return trim($role, '-_');
}

/**
 * Normalisiert Capability-Namen für die Kompatibilitätsschicht.
 */
function cms_normalize_role_capability(string $capability): string {
    $capability = strtolower(trim($capability));
    $capability = str_replace(['\\', '/', ':'], '.', $capability);
    $capability = preg_replace('/\s+/', '.', $capability) ?? '';
    $capability = preg_replace('/[^a-z0-9._-]+/', '-', $capability) ?? '';
    $capability = preg_replace('/\.{2,}/', '.', $capability) ?? '';
    return trim($capability, '.-_');
}

/**
 * Menschlich lesbares Rollenlabel.
 */
function cms_humanize_role_slug(string $role): string {
    $role = str_replace(['_', '-', '.'], ' ', cms_normalize_role_slug($role));
    return ucwords(trim($role));
}

/**
 * Default-Rollen der CMS-/WP-Kompatibilität.
 */
function cms_get_default_role_definitions(): array {
    return [
        'admin' => [
            'display_name' => 'Administrator',
            'capabilities' => [
                'read' => true,
                'edit_profile' => true,
                'view_content' => true,
                'manage_users' => true,
                'manage_settings' => true,
                'manage_system' => true,
                'manage_pages' => true,
                'edit_all_posts' => true,
                'delete_all_posts' => true,
                'manage_media' => true,
                'view_analytics' => true,
                'pages.view' => true,
                'pages.create' => true,
                'pages.edit' => true,
                'pages.delete' => true,
                'pages.publish' => true,
                'posts.view' => true,
                'posts.create' => true,
                'posts.edit' => true,
                'posts.delete' => true,
                'posts.publish' => true,
                'media.view' => true,
                'media.upload' => true,
                'media.delete' => true,
                'media.settings' => true,
                'users.view' => true,
                'users.create' => true,
                'users.edit' => true,
                'users.delete' => true,
                'users.roles' => true,
                'themes.view' => true,
                'themes.activate' => true,
                'themes.customize' => true,
                'themes.install' => true,
                'plugins.view' => true,
                'plugins.activate' => true,
                'plugins.install' => true,
                'plugins.settings' => true,
                'settings.view' => true,
                'settings.edit' => true,
                'settings.system' => true,
                'manage_ai_services' => true,
                'use_ai_translation' => true,
                'use_ai_rewrite' => true,
                'use_ai_summary' => true,
                'use_ai_seo_meta' => true,
                'comments.view' => true,
                'comments.moderate' => true,
                'comments.delete' => true,
                'manage_admin_menu' => true,
                'manage_trash' => true,
                'view_editorial_calendar' => true,
                'manage_translations' => true,
                'manage_search_index' => true,
                'import_export' => true,
                'manage_forms' => true,
                'view_form_submissions' => true,
                'manage_downloads' => true,
                'upload_downloads' => true,
                'view_download_stats' => true,
                'manage_feeds' => true,
            ],
        ],
        'editor' => [
            'display_name' => 'Editor',
            'capabilities' => [
                'read' => true,
                'edit_profile' => true,
                'view_content' => true,
                'manage_pages' => true,
                'edit_all_posts' => true,
                'delete_all_posts' => true,
                'manage_media' => true,
                'pages.view' => true,
                'pages.create' => true,
                'pages.edit' => true,
                'pages.delete' => true,
                'pages.publish' => true,
                'posts.view' => true,
                'posts.create' => true,
                'posts.edit' => true,
                'posts.delete' => true,
                'posts.publish' => true,
                'media.view' => true,
                'media.upload' => true,
                'media.delete' => true,
                'media.settings' => true,
                'use_ai_translation' => true,
                'use_ai_rewrite' => true,
                'use_ai_summary' => true,
                'use_ai_seo_meta' => true,
                'comments.view' => true,
                'comments.moderate' => true,
                'comments.delete' => true,
                'manage_trash' => true,
                'view_editorial_calendar' => true,
                'manage_translations' => true,
                'upload_downloads' => true,
                'view_form_submissions' => true,
            ],
        ],
        'author' => [
            'display_name' => 'Autor',
            'capabilities' => [
                'read' => true,
                'edit_profile' => true,
                'view_content' => true,
                'edit_own_posts' => true,
                'pages.view' => true,
                'pages.create' => true,
                'pages.edit' => true,
                'posts.view' => true,
                'posts.create' => true,
                'posts.edit' => true,
                'media.view' => true,
                'media.upload' => true,
                'comments.view' => true,
            ],
        ],
        'member' => [
            'display_name' => 'Mitglied',
            'capabilities' => [
                'read' => true,
                'edit_profile' => true,
                'view_content' => true,
                'pages.view' => true,
                'posts.view' => true,
                'media.view' => true,
                'comments.view' => true,
            ],
        ],
        'subscriber' => [
            'display_name' => 'Subscriber',
            'capabilities' => [
                'read' => true,
            ],
        ],
        'contributor' => [
            'display_name' => 'Contributor',
            'capabilities' => [
                'read' => true,
                'posts.view' => true,
                'posts.create' => true,
                'posts.edit' => true,
            ],
        ],
    ];
}

/**
 * Stellt sicher, dass die Rollen-Tabelle für persistente Caps existiert.
 */
function cms_ensure_role_permissions_table(): void {
    $db = \CMS\Database::instance();
    $db->getPdo()->exec("CREATE TABLE IF NOT EXISTS {$db->prefix()}role_permissions (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        role       VARCHAR(50) NOT NULL,
        capability VARCHAR(100) NOT NULL,
        granted    TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uk_role_cap (role, capability)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Lädt die Capability-Matrix einer Rolle aus DB + Default-Fallbacks.
 */
function cms_load_role_capabilities(string $role): array {
    $role = cms_normalize_role_slug($role);
    if ($role === '') {
        return [];
    }

    $definitions = cms_get_default_role_definitions();
    $capabilities = $definitions[$role]['capabilities'] ?? [];

    try {
        cms_ensure_role_permissions_table();
        $db = \CMS\Database::instance();
        $rows = $db->get_results(
            "SELECT capability, granted FROM {$db->prefix()}role_permissions WHERE role = ?",
            [$role]
        ) ?: [];

        foreach ($rows as $row) {
            $capability = cms_normalize_role_capability((string) ($row->capability ?? ''));
            if ($capability === '') {
                continue;
            }

            $capabilities[$capability] = !empty($row->granted);
        }
    } catch (\Throwable) {
    }

    return $capabilities;
}

/**
 * Prüft, ob die Rolle im CMS bekannt ist.
 */
function cms_role_exists(string $role): bool {
    $role = cms_normalize_role_slug($role);
    if ($role === '') {
        return false;
    }

    $definitions = cms_get_default_role_definitions();
    if (isset($definitions[$role])) {
        return true;
    }

    $registry = cms_get_wp_role_registry();
    if (isset($registry[$role])) {
        return true;
    }

    try {
        $db = \CMS\Database::instance();

        $dbRole = $db->get_var(
            "SELECT role FROM {$db->prefix()}role_permissions WHERE role = ? LIMIT 1",
            [$role]
        );
        if (is_string($dbRole) && $dbRole !== '') {
            return true;
        }

        $userRole = $db->get_var(
            "SELECT role FROM {$db->prefix()}users WHERE role = ? LIMIT 1",
            [$role]
        );
        return is_string($userRole) && $userRole !== '';
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Persistiert eine einzelne Capability.
 */
function cms_store_role_capability(string $role, string $capability, bool $granted): void {
    $role = cms_normalize_role_slug($role);
    $capability = cms_normalize_role_capability($capability);

    if ($role === '' || $capability === '') {
        return;
    }

    try {
        cms_ensure_role_permissions_table();
        $db = \CMS\Database::instance();
        $db->execute(
            "INSERT INTO {$db->prefix()}role_permissions (role, capability, granted)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE granted = VALUES(granted)",
            [$role, $capability, $granted ? 1 : 0]
        );
    } catch (\Throwable) {
    }
}

/**
 * Überschreibt die Capability-Matrix einer Rolle.
 */
function cms_replace_role_capabilities(string $role, array $capabilities): void {
    $role = cms_normalize_role_slug($role);
    if ($role === '') {
        return;
    }

    try {
        cms_ensure_role_permissions_table();
        $db = \CMS\Database::instance();
        $db->execute(
            "DELETE FROM {$db->prefix()}role_permissions WHERE role = ?",
            [$role]
        );

        foreach ($capabilities as $capability => $granted) {
            cms_store_role_capability($role, (string) $capability, (bool) $granted);
        }
    } catch (\Throwable) {
    }
}

/**
 * Get role object (persistente WP-Kompatibilitätsschicht).
 */
function get_role(string $role): ?object {
    $role = cms_normalize_role_slug($role);
    if ($role === '' || !cms_role_exists($role)) {
        return null;
    }

    $definitions = cms_get_default_role_definitions();
    $registry = cms_get_wp_role_registry();
    $displayName = (string) ($registry[$role]['display_name'] ?? $definitions[$role]['display_name'] ?? cms_humanize_role_slug($role));

    return new CMS_Role_Stub($role, cms_load_role_capabilities($role), $displayName);
}

/**
 * Add role (persistiert Metadaten + Capabilities)
 */
function add_role(string $role, string $display_name, array $capabilities = []): ?object {
    $role = cms_normalize_role_slug($role);
    if ($role === '') {
        return null;
    }

    $normalizedCaps = [];
    foreach ($capabilities as $capability => $granted) {
        $capability = cms_normalize_role_capability((string) $capability);
        if ($capability === '') {
            continue;
        }

        $normalizedCaps[$capability] = (bool) $granted;
    }

    $registry = cms_get_wp_role_registry();
    $registry[$role] = [
        'display_name' => trim($display_name) !== '' ? trim($display_name) : cms_humanize_role_slug($role),
        'managed_by' => 'wp_compat',
    ];
    cms_save_wp_role_registry($registry);
    cms_replace_role_capabilities($role, $normalizedCaps);

    return new CMS_Role_Stub($role, cms_load_role_capabilities($role), $registry[$role]['display_name']);
}

/**
 * Remove role (persistiert Löschung in Registry + Role-Matrix)
 */
function remove_role(string $role): void {
    $role = cms_normalize_role_slug($role);
    if ($role === '' || in_array($role, ['admin', 'editor', 'author', 'member'], true)) {
        return;
    }

    $registry = cms_get_wp_role_registry();
    if (isset($registry[$role])) {
        unset($registry[$role]);
        cms_save_wp_role_registry($registry);
    }

    try {
        cms_ensure_role_permissions_table();
        $db = \CMS\Database::instance();
        $db->execute(
            "DELETE FROM {$db->prefix()}role_permissions WHERE role = ?",
            [$role]
        );
    } catch (\Throwable) {
    }
}
