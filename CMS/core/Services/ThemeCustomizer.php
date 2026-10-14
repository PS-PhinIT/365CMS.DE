<?php
/**
 * Theme Customizer Service
 * 
 * Verwaltet Theme-Anpassungen und -Einstellungen
 * 
 * @package CMSv2\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Json;
use CMS\Logger;
use PDO;

if (!defined('ABSPATH')) {
    exit;
}

class ThemeCustomizer
{
    private static ?ThemeCustomizer $instance = null;
    /** @var array<string, string>|null */
    private ?array $availableThemeConfigMap = null;
    private Database $db;
    private string $currentTheme = 'default';
    private ?array $themeConfig = null;
    private array $customizations = [];
    private bool $tableChecked = false;
    
    /**
     * Get singleton instance
     */
    public static function instance(): ThemeCustomizer
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct()
    {
        $this->db = Database::instance();
        $this->currentTheme = $this->detectActiveTheme();
        $this->loadThemeConfig();
        $this->loadCustomizations();
    }

    /**
     * Auto-detect active theme from database, with folder-existence fallback
     */
    private function detectActiveTheme(): string
    {
        $slug = defined('DEFAULT_THEME') ? DEFAULT_THEME : 'cms-default';

        try {
            $activeTheme = OptionStore::getInstance()->get('active_theme');
            if ($activeTheme !== null && $activeTheme !== '') {
                $slug = $activeTheme;
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('theme-customizer')->warning('Active theme could not be detected for the customizer.', [
                'exception' => $e,
            ]);
        }

        $availableThemes = $this->getAvailableThemeConfigMap();
        if (!isset($availableThemes[$slug])) {
            $fallbackSlug = array_key_first($availableThemes);
            if (is_string($fallbackSlug) && $fallbackSlug !== '') {
                $slug = $fallbackSlug;
            }
        }

        return $slug;
    }

    /**
     * @return array<string, string>
     */
    private function getAvailableThemeConfigMap(): array
    {
        if ($this->availableThemeConfigMap !== null) {
            return $this->availableThemeConfigMap;
        }

        $map = [];
        $themesBase = defined('ABSPATH') ? ABSPATH . 'themes' . DIRECTORY_SEPARATOR : '';
        $realThemesBase = $themesBase !== '' ? realpath($themesBase) : false;
        if ($realThemesBase === false || !is_dir($realThemesBase)) {
            return $this->availableThemeConfigMap = [];
        }

        $entries = scandir($realThemesBase);
        if (!is_array($entries)) {
            return $this->availableThemeConfigMap = [];
        }

        $normalizedBase = rtrim($realThemesBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        foreach ($entries as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            if (preg_match('/^[a-z0-9][a-z0-9\-]*$/i', $entry) !== 1) {
                continue;
            }

            $themeDir = realpath($realThemesBase . DIRECTORY_SEPARATOR . $entry);
            if ($themeDir === false || !is_dir($themeDir) || !str_starts_with($themeDir, $normalizedBase)) {
                continue;
            }

            $configPath = realpath($themeDir . DIRECTORY_SEPARATOR . 'theme.json');
            if ($configPath === false || !is_file($configPath) || !str_starts_with($configPath, $normalizedBase)) {
                continue;
            }

            $map[$entry] = $configPath;
        }

        return $this->availableThemeConfigMap = $map;
    }
    
    /**
     * Set current theme
     */
    public function setTheme(string $themeSlug): void
    {
        // Konstruktor hat das aktive Theme bereits geladen – kein zweites Lesen von theme.json + Customizing-Abfrage.
        if ($themeSlug === $this->currentTheme && $this->themeConfig !== null) {
            return;
        }

        $this->currentTheme = $themeSlug;
        $this->loadThemeConfig();
        $this->loadCustomizations();
    }
    
    /**
     * Get current theme slug
     */
    public function getTheme(): string
    {
        return $this->currentTheme;
    }

    private function readThemeConfigJson(string $realConfigPath, string $realThemesBase): string
    {
        $resolvedConfigPath = realpath($realConfigPath);
        $normalizedBase = rtrim($realThemesBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($resolvedConfigPath === false
            || !str_starts_with($resolvedConfigPath, $normalizedBase)
            || !is_file($resolvedConfigPath)
            || !is_readable($resolvedConfigPath)
            || strtolower((string) pathinfo($resolvedConfigPath, PATHINFO_EXTENSION)) !== 'json'
            || basename($resolvedConfigPath) !== 'theme.json'
        ) {
            return '';
        }

        $size = filesize($resolvedConfigPath);
        if ($size === false || $size < 0 || $size > 1024 * 1024) {
            return '';
        }

        $handle = fopen($resolvedConfigPath, 'rb');
        if (!is_resource($handle)) {
            return '';
        }

        try {
            $content = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        return is_string($content) ? $content : '';
    }
    
    /**
     * Load theme configuration from theme.json
     */
    private function loadThemeConfig(): void
    {
        $themeSlug = trim((string) $this->currentTheme);
        if ($themeSlug === '' || preg_match('/^[a-z0-9][a-z0-9\-]*$/i', $themeSlug) !== 1) {
            Logger::instance()->withChannel('theme-customizer')->warning('Theme customizer received an invalid theme slug.', [
                'theme_slug' => $themeSlug,
            ]);
            $this->themeConfig = [];
            return;
        }

        $themesBase = defined('ABSPATH') ? ABSPATH . 'themes' . DIRECTORY_SEPARATOR : '';
        $realThemesBase = $themesBase !== '' ? realpath($themesBase) : false;
        if ($realThemesBase === false || !is_dir($realThemesBase)) {
            Logger::instance()->withChannel('theme-customizer')->warning('Theme base path is unavailable for the customizer.', [
                'themes_base' => $themesBase,
            ]);
            $this->themeConfig = [];
            return;
        }

        $availableThemes = $this->getAvailableThemeConfigMap();
        $realConfigPath = $availableThemes[$themeSlug] ?? '';

        if ($realConfigPath === '') {
            Logger::instance()->withChannel('theme-customizer')->warning('Theme config was not found for the selected theme.', [
                'theme_slug' => $themeSlug,
            ]);
            $this->themeConfig = [];
            return;
        }

        $json = $this->readThemeConfigJson($realConfigPath, $realThemesBase);
        if ($json === '') {
            Logger::instance()->withChannel('theme-customizer')->warning('Theme config is unreadable or blocked.', [
                'theme_slug' => $themeSlug,
                'config_path' => $realConfigPath,
            ]);
            $this->themeConfig = [];
            return;
        }

        $config = Json::decodeArray($json, []);

        if ($config === []) {
            Logger::instance()->withChannel('theme-customizer')->warning('Theme config JSON is invalid or empty.', [
                'theme_slug' => $themeSlug,
                'config_path' => $realConfigPath,
            ]);
            $this->themeConfig = [];
            return;
        }
        
        $this->themeConfig = $config;
    }
    
    /**
     * Get complete theme configuration
     */
    public function getThemeConfig(): array
    {
        return $this->themeConfig ?? [];
    }
    
    /**
     * Get theme metadata
     */
    public function getThemeMetadata(): array
    {
        $config = $this->getThemeConfig();
        
        return [
            'name' => $config['name'] ?? '',
            'slug' => $config['slug'] ?? $this->currentTheme,
            'version' => $config['version'] ?? '1.0.0',
            'author' => $config['author'] ?? '',
            'description' => $config['description'] ?? '',
            'tags' => $config['tags'] ?? [],
            'supports' => $config['supports'] ?? []
        ];
    }
    
    /**
     * Get customization options from theme.json
     */
    public function getCustomizationOptions(): array
    {
        $config = $this->getThemeConfig();
        return $config['customization'] ?? [];
    }

    /**
     * @return array{sql:string, params:array<int, mixed>}
     */
    private function buildUserScopeClause(?int $userId, bool $includeGlobalFallback = false): array
    {
        if ($userId !== null) {
            if ($includeGlobalFallback) {
                return [
                    'sql' => ' AND (user_id = ? OR user_id IS NULL)',
                    'params' => [$userId],
                ];
            }

            return [
                'sql' => ' AND user_id = ?',
                'params' => [$userId],
            ];
        }

        return [
            'sql' => ' AND user_id IS NULL',
            'params' => [],
        ];
    }

    private function cleanupDuplicateSettingRows(string $category, string $key, ?int $userId = null): void
    {
        try {
            $scope = $this->buildUserScopeClause($userId);
            $params = array_merge([$this->currentTheme, $category, $key], $scope['params']);

            $sql = "SELECT id
                    FROM {$this->db->getPrefix()}theme_customizations
                    WHERE theme_slug = ? AND setting_category = ? AND setting_key = ?" . $scope['sql'] . "
                    ORDER BY updated_at DESC, id DESC";

            $stmt = $this->db->execute($sql, $params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) <= 1) {
                return;
            }

            $duplicateIds = array_values(array_filter(array_map(
                static fn(array $row): int => (int) ($row['id'] ?? 0),
                array_slice($rows, 1)
            )));

            if ($duplicateIds === []) {
                return;
            }

            $placeholders = implode(', ', array_fill(0, count($duplicateIds), '?'));
            $this->db->execute(
                "DELETE FROM {$this->db->getPrefix()}theme_customizations WHERE id IN ({$placeholders})",
                $duplicateIds
            );
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('theme-customizer')->warning('Duplicate theme customization rows could not be cleaned up.', [
                'theme' => $this->currentTheme,
                'category' => $category,
                'key' => $key,
                'user_id' => $userId,
                'exception' => $e,
            ]);
        }
    }
    
    /**
     * Load customizations from database
     */
    private function loadCustomizations(?int $userId = null): void
    {
        try {
            $sql = "SELECT setting_category, setting_key, setting_value 
                    FROM {$this->db->getPrefix()}theme_customizations 
                    WHERE theme_slug = ?";
            $params = [$this->currentTheme];
            $scope = $this->buildUserScopeClause($userId, $userId !== null);
            $sql .= $scope['sql'];
            $params = array_merge($params, $scope['params']);
            $sql .= " ORDER BY updated_at DESC, id DESC";
            
            $stmt    = $this->db->execute($sql, $params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->customizations = [];
            foreach ($results as $row) {
                $category = (string) ($row['setting_category'] ?? '');
                $settingKey = (string) ($row['setting_key'] ?? '');
                if ($category === '' || $settingKey === '') {
                    continue;
                }

                if (isset($this->customizations[$category][$settingKey])) {
                    continue;
                }

                if (!isset($this->customizations[$category])) {
                    $this->customizations[$category] = [];
                }
                $this->customizations[$category][$settingKey] = $row['setting_value'];
            }
            
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('theme-customizer')->warning('Theme customizations could not be loaded.', [
                'theme' => $this->currentTheme,
                'user_id' => $userId,
                'exception' => $e,
            ]);
            $this->customizations = [];
        }
    }
    
    /**
     * Get customization value with fallback to default
     */
    public function get(string $category, string $key, $default = null)
    {
        // Check if custom value exists
        if (isset($this->customizations[$category][$key])) {
            return $this->customizations[$category][$key];
        }
        
        // Fallback to theme.json default
        $options = $this->getCustomizationOptions();
        if (isset($options[$category]['settings'][$key]['default'])) {
            return $options[$category]['settings'][$key]['default'];
        }
        
        // Fallback to provided default
        return $default;
    }
    
    /**
     * Get all customizations for a category
     */
    public function getCategory(string $category): array
    {
        $options = $this->getCustomizationOptions();
        $categorySettings = $options[$category]['settings'] ?? [];
        
        $result = [];
        foreach ($categorySettings as $key => $config) {
            $result[$key] = $this->get($category, $key, $config['default'] ?? null);
        }
        
        return $result;
    }
    
    /**
     * Save customization value
     */
    /**
     * Ensure the theme_customizations table exists (auto-repair for missing migrations)
     */
    private function ensureTable(): void
    {
        if ($this->tableChecked) {
            return;
        }
        $this->tableChecked = true;
        try {
            $p = $this->db->getPrefix();
            // DDL-Statements via query() ausführen – nicht über prepare(),
            // da CREATE TABLE als Server-Side Prepared Statement in manchen
            // MySQL/MariaDB-Versionen mit ATTR_EMULATE_PREPARES=false nicht
            // unterstützt wird und Database::execute() intern prepare() nutzt.
            $this->db->query("CREATE TABLE IF NOT EXISTS {$p}theme_customizations (
                id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                theme_slug      VARCHAR(100)     NOT NULL,
                setting_category VARCHAR(100)    NOT NULL,
                setting_key     VARCHAR(200)     NOT NULL,
                setting_value   MEDIUMTEXT,
                user_id         INT UNSIGNED     DEFAULT NULL,
                created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_theme_setting (theme_slug, setting_category, setting_key, user_id),
                KEY idx_user_id      (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('theme-customizer')->warning('Theme customization table could not be ensured.', [
                'theme' => $this->currentTheme,
                'exception' => $e,
            ]);
        }
    }

    public function set(string $category, string $key, $value, ?int $userId = null): bool
    {
        try {
            // Soft-validate against theme.json: log a warning but do NOT block the save.
            // A hard-fail here would silently swallow all settings if theme.json is unreachable.
            $options = $this->getCustomizationOptions();
            if (!empty($options) && !isset($options[$category]['settings'][$key])) {
                Logger::instance()->withChannel('theme-customizer')->warning('Unknown theme customization setting encountered; save continues.', [
                    'theme' => $this->currentTheme,
                    'category' => $category,
                    'key' => $key,
                ]);
                // Continue – the key may have been added in the PHP config but not yet in theme.json
            }

            // Ensure the table exists (idempotent, runs once per request)
            $this->ensureTable();
            $this->cleanupDuplicateSettingRows($category, $key, $userId);

            // Check if record exists
            // Database::execute() nutzen statt prepare()+execute() direkt –
            // nur so werden NULL-Werte korrekt als PDO::PARAM_NULL gebunden
            // (direktes $stmt->execute($array) kann null als '' binden, was
            // im strikten SQL-Modus ein INSERT auf INT-Spalten bricht).
            $sql = "SELECT id FROM {$this->db->getPrefix()}theme_customizations 
                    WHERE theme_slug = ? AND setting_category = ? AND setting_key = ?";
            $params = [$this->currentTheme, $category, $key];
            $scope = $this->buildUserScopeClause($userId);
            $sql .= $scope['sql'];
            $params = array_merge($params, $scope['params']);
            $sql .= " ORDER BY updated_at DESC, id DESC LIMIT 1";
            
            $stmt    = $this->db->execute($sql, $params);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Update existing
                $updateSql = "UPDATE {$this->db->getPrefix()}theme_customizations 
                              SET setting_value = ?, updated_at = NOW() 
                              WHERE id = ?";
                $this->db->execute($updateSql, [$value, (int)$existing['id']]);
            } else {
                // Insert new
                $insertSql = "INSERT INTO {$this->db->getPrefix()}theme_customizations 
                              (theme_slug, setting_category, setting_key, setting_value, user_id) 
                              VALUES (?, ?, ?, ?, ?)";
                $this->db->execute($insertSql, [
                    $this->currentTheme,
                    $category,
                    $key,
                    $value,
                    $userId
                ]);
            }

            $this->cleanupDuplicateSettingRows($category, $key, $userId);
            
            // Reload customizations
            $this->loadCustomizations($userId);
            
            return true;
            
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('theme-customizer')->warning('Theme customization value could not be saved.', [
                'theme' => $this->currentTheme,
                'category' => $category,
                'key' => $key,
                'user_id' => $userId,
                'exception' => $e,
            ]);
            return false;
        }
    }
    
    /**
     * Save multiple customizations at once
     */
    public function setMultiple(array $settings, ?int $userId = null): bool
    {
        $success = true;
        
        foreach ($settings as $category => $categorySettings) {
            if (!is_array($categorySettings)) {
                continue;
            }
            
            foreach ($categorySettings as $key => $value) {
                if (!$this->set($category, $key, $value, $userId)) {
                    $success = false;
                }
            }
        }
        
        return $success;
    }
    
    /**
     * Reset customization to default
     */
    public function reset(string $category, string $key, ?int $userId = null): bool
    {
        try {
            $sql = "DELETE FROM {$this->db->getPrefix()}theme_customizations 
                    WHERE theme_slug = ? AND setting_category = ? AND setting_key = ?";
            $params = [$this->currentTheme, $category, $key];
            
            if ($userId !== null) {
                $sql .= " AND user_id = ?";
                $params[] = $userId;
            } else {
                $sql .= " AND user_id IS NULL";
            }
            
            $this->db->execute($sql, $params);
            $this->loadCustomizations($userId);
            return true;
            
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('theme-customizer')->warning('Theme customization value could not be reset.', [
                'theme' => $this->currentTheme,
                'category' => $category,
                'key' => $key,
                'user_id' => $userId,
                'exception' => $e,
            ]);
            return false;
        }
    }
    
    /**
     * Reset all customizations for theme
     */
    public function resetAll(?int $userId = null): bool
    {
        try {
            $sql = "DELETE FROM {$this->db->getPrefix()}theme_customizations 
                    WHERE theme_slug = ?";
            $params = [$this->currentTheme];
            
            if ($userId !== null) {
                $sql .= " AND user_id = ?";
                $params[] = $userId;
            } else {
                $sql .= " AND user_id IS NULL";
            }
            
            $this->db->execute($sql, $params);
            $this->loadCustomizations($userId);
            return true;
            
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('theme-customizer')->warning('All theme customization values could not be reset.', [
                'theme' => $this->currentTheme,
                'user_id' => $userId,
                'exception' => $e,
            ]);
            return false;
        }
    }
    
    /**
     * Generate CSS from customizations
     * Variablennamen entsprechen exakt den CSS-Variablen in themes/default/style.css
     */
    public function generateCSS(): string
    {
        $css = "/* Theme Customizations - Auto-generated */\n\n";
        $css .= ":root {\n";

        // ── Farben ──────────────────────────────────────────────────────────
        $colors = $this->getCategory('colors');
        if (!empty($colors)) {
            $css .= "    /* Farben */\n";
            $map = [
                'primary_color'    => ['--primary-color'],
                'secondary_color'  => ['--secondary-color', '--primary-hover'],
                'accent_color'     => ['--accent-color'],
                'text_color'       => ['--text-color', '--text-primary'],
                'bg_color'         => ['--background-color', '--bg-secondary', '--light-bg'],
                'link_color'       => ['--link-color'],
                'link_hover_color' => ['--link-hover-color'],
                'muted_color'      => ['--muted-color'],
                'border_color'     => ['--border-color'],
                'success_color'    => ['--success-color'],
                'error_color'      => ['--error-color'],
            ];
            foreach ($map as $key => $vars) {
                if (!empty($colors[$key])) {
                    foreach ($vars as $var) {
                        $css .= "    {$var}: {$colors[$key]};\n";
                    }
                }
            }
            $css .= "\n";
        }

        // ── Header ──────────────────────────────────────────────────────────
        $header = $this->getCategory('header');
        if (!empty($header)) {
            $css .= "    /* Header */\n";
            if (!empty($header['header_bg_color'])) {
                // style.css erwartet --header-bg (kein -color Suffix)
                $css .= "    --header-bg: {$header['header_bg_color']};\n";
                $css .= "    --header-bg-color: {$header['header_bg_color']};\n";
            }
            if (!empty($header['header_text_color'])) {
                $css .= "    --header-text: {$header['header_text_color']};\n";
                $css .= "    --header-text-color: {$header['header_text_color']};\n";
            }
            if (!empty($header['header_height'])) {
                $css .= "    --header-height: {$header['header_height']}px;\n";
            }
            if (!empty($header['logo_max_height'])) {
                $css .= "    --logo-max-height: {$header['logo_max_height']}px;\n";
            }
            $css .= "\n";
        }

        // ── Footer ──────────────────────────────────────────────────────────
        $footer = $this->getCategory('footer');
        if (!empty($footer)) {
            $css .= "    /* Footer */\n";
            if (!empty($footer['footer_bg_color'])) {
                $css .= "    --footer-bg: {$footer['footer_bg_color']};\n";
                $css .= "    --footer-bg-color: {$footer['footer_bg_color']};\n";
            }
            if (!empty($footer['footer_text_color'])) {
                $css .= "    --footer-text: {$footer['footer_text_color']};\n";
                $css .= "    --footer-text-color: {$footer['footer_text_color']};\n";
            }
            if (!empty($footer['footer_link_color'])) {
                $css .= "    --footer-link: {$footer['footer_link_color']};\n";
                $css .= "    --footer-link-color: {$footer['footer_link_color']};\n";
            }
            $css .= "\n";
        }

        // ── Typografie ──────────────────────────────────────────────────────
        $typography = $this->getCategory('typography');
        if (!empty($typography)) {
            $css .= "    /* Typografie */\n";
            if (!empty($typography['font_family_base']) && $typography['font_family_base'] !== 'system') {
                $fontFamily = $this->getFontFamilyStack($typography['font_family_base']);
                // style.css verwendet --font-body und --font-menu
                $css .= "    --font-body: {$fontFamily};\n";
                $css .= "    --font-menu: {$fontFamily};\n";
                $css .= "    --font-family-base: {$fontFamily};\n";
            }
            if (!empty($typography['font_family_heading']) && $typography['font_family_heading'] !== 'system') {
                $fontFamily = $this->getFontFamilyStack($typography['font_family_heading']);
                // style.css verwendet --font-heading
                $css .= "    --font-heading: {$fontFamily};\n";
                $css .= "    --font-family-heading: {$fontFamily};\n";
            }
            if (!empty($typography['font_size_base'])) {
                $css .= "    --font-size-base: {$typography['font_size_base']}px;\n";
            }
            if (!empty($typography['line_height_base'])) {
                $css .= "    --line-height-base: {$typography['line_height_base']};\n";
            }
            if (!empty($typography['font_weight_heading'])) {
                $css .= "    --font-weight-heading: {$typography['font_weight_heading']};\n";
            }
            $css .= "\n";
        }

        // ── Layout ──────────────────────────────────────────────────────────
        $layout = $this->getCategory('layout');
        if (!empty($layout)) {
            $css .= "    /* Layout */\n";
            if (!empty($layout['container_width'])) {
                // style.css verwendet --container-max-width
                $css .= "    --container-max-width: {$layout['container_width']}px;\n";
                $css .= "    --container-width: {$layout['container_width']}px;\n";
            }
            if (!empty($layout['content_padding'])) {
                // style.css verwendet --container-padding
                $css .= "    --container-padding: {$layout['content_padding']}rem;\n";
                $css .= "    --content-padding: {$layout['content_padding']}rem;\n";
            }
            if (!empty($layout['border_radius'])) {
                $r = (int)$layout['border_radius'];
                // style.css verwendet --radius-sm / -md / -lg Abstufungen
                $css .= "    --border-radius: {$r}px;\n";
                $css .= "    --radius-sm: " . max(2, intval($r / 2)) . "px;\n";
                $css .= "    --radius-md: {$r}px;\n";
                $css .= "    --radius-lg: " . ($r * 2) . "px;\n";
            }
            if (!empty($layout['section_spacing'])) {
                $css .= "    --section-spacing: {$layout['section_spacing']}rem;\n";
            }
            $css .= "\n";
        }

        // ── Buttons ─────────────────────────────────────────────────────────
        $buttons = $this->getCategory('buttons');
        if (!empty($buttons)) {
            $css .= "    /* Buttons */\n";
            if (!empty($buttons['button_border_radius'])) {
                $css .= "    --button-border-radius: {$buttons['button_border_radius']}px;\n";
            }
            if (!empty($buttons['button_padding_x'])) {
                $css .= "    --button-padding-x: {$buttons['button_padding_x']}rem;\n";
            }
            if (!empty($buttons['button_padding_y'])) {
                $css .= "    --button-padding-y: {$buttons['button_padding_y']}rem;\n";
            }
            if (!empty($buttons['button_font_weight'])) {
                $css .= "    --button-font-weight: {$buttons['button_font_weight']};\n";
            }
            if (!empty($buttons['button_transform'])) {
                $css .= "    --button-text-transform: {$buttons['button_transform']};\n";
            }
            $css .= "\n";
        }

        $css .= "}\n\n";

        // ── Elemente-CSS ────────────────────────────────────────────────────

        // Body-Schrift (:where() → Spezifität 0, damit Plugin-Styles nicht überschrieben werden)
        if (!empty($typography['font_family_base']) && $typography['font_family_base'] !== 'system') {
            $css .= ":where(body) {\n";
            $css .= "    font-family: var(--font-body);\n";
            if (!empty($typography['font_size_base'])) {
                $css .= "    font-size: var(--font-size-base, 16px);\n";
            }
            if (!empty($typography['line_height_base'])) {
                $css .= "    line-height: var(--line-height-base, 1.6);\n";
            }
            $css .= "}\n\n";
        }

        // Überschriften-Schrift (:where() → Plugin-Headings bleiben unberührt)
        if (!empty($typography['font_family_heading']) && $typography['font_family_heading'] !== 'system') {
            $css .= ":where(h1, h2, h3, h4, h5, h6) {\n";
            $css .= "    font-family: var(--font-heading, inherit);\n";
            if (!empty($typography['font_weight_heading'])) {
                $css .= "    font-weight: var(--font-weight-heading, 700);\n";
            }
            $css .= "}\n\n";
        }

        // Container (:where() → Plugin-Container bleiben unberührt)
        if (!empty($layout['container_width'])) {
            $css .= ":where(.container) {\n";
            $css .= "    max-width: var(--container-max-width, 1200px);\n";
            if (!empty($layout['content_padding'])) {
                $css .= "    padding-left: var(--container-padding, 2rem);\n";
                $css .= "    padding-right: var(--container-padding, 2rem);\n";
            }
            $css .= "}\n\n";
        }

        // Sticky Header
        if (!empty($layout['enable_sticky_header'])) {
            $css .= ".site-header {\n";
            $css .= "    position: sticky;\n";
            $css .= "    top: 0;\n";
            $css .= "    z-index: 1000;\n";
            $css .= "}\n\n";
        }

        // Header Styling
        if (!empty($header['header_bg_color'])) {
            $borderBottom = !empty($header['show_header_shadow'])
                ? "    box-shadow: 0 2px 8px rgba(0,0,0,0.15);\n" : '';
            $css .= ".site-header {\n";
            $css .= "    background: var(--header-bg);\n";
            if (!empty($header['header_text_color'])) {
                $css .= "    color: var(--header-text);\n";
            }
            if (!empty($header['header_height'])) {
                $css .= "    min-height: var(--header-height);\n";
            }
            $css .= $borderBottom;
            $css .= "}\n\n";

            // Nav-Link Farbe
            if (!empty($header['header_text_color'])) {
                $css .= ".main-nav a, .site-header .nav a {\n";
                $css .= "    color: var(--header-text);\n";
                $css .= "}\n\n";
            }
        }

        // Logo Höhe
        if (!empty($header['logo_max_height'])) {
            $css .= ".site-logo img, .site-logo svg {\n";
            $css .= "    max-height: var(--logo-max-height, 48px);\n";
            $css .= "}\n\n";
        }

        // Footer Styling
        if (!empty($footer['footer_bg_color'])) {
            $css .= ".site-footer {\n";
            $css .= "    background: var(--footer-bg);\n";
            if (!empty($footer['footer_text_color'])) {
                $css .= "    color: var(--footer-text);\n";
            }
            $css .= "}\n\n";
            if (!empty($footer['footer_link_color'])) {
                $css .= ".site-footer a {\n";
                $css .= "    color: var(--footer-link);\n";
                $css .= "}\n\n";
            }
        }

        // Buttons (:where() → Plugin-eigene Button-Styles bleiben unberührt)
        if (!empty($buttons['button_border_radius']) || !empty($buttons['button_padding_x'])) {
            $css .= ":where(.btn, button.btn, a.btn) {\n";
            if (!empty($buttons['button_border_radius'])) {
                $css .= "    border-radius: var(--button-border-radius);\n";
            }
            if (!empty($buttons['button_padding_x']) && !empty($buttons['button_padding_y'])) {
                $css .= "    padding: var(--button-padding-y) var(--button-padding-x);\n";
            }
            if (!empty($buttons['button_font_weight'])) {
                $css .= "    font-weight: var(--button-font-weight);\n";
            }
            if (!empty($buttons['button_transform'])) {
                $css .= "    text-transform: var(--button-text-transform);\n";
            }
            $css .= "}\n\n";
        }

        // Custom CSS
        $advanced = $this->getCategory('advanced');
        if (!empty($advanced['custom_css'])) {
            $css .= "/* Custom CSS */\n";
            $css .= $advanced['custom_css'] . "\n";
        }

        return $css;
    }
    
    /**
     * Get font family stack
     */
    private function getFontFamilyStack(string $font): string
    {
        $stacks = [
            'inter' => "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'roboto' => "'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'open-sans' => "'Open Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'lato' => "'Lato', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'montserrat' => "'Montserrat', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'poppins' => "'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'raleway' => "'Raleway', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
        ];
        
        return $stacks[$font] ?? $stacks['inter'];
    }
    
    /**
     * Export all customizations
     */
    public function export(?int $userId = null): array
    {
        $export = [
            'theme' => $this->currentTheme,
            'exported_at' => date('Y-m-d H:i:s'),
            'customizations' => []
        ];
        
        $options = $this->getCustomizationOptions();
        foreach ($options as $category => $categoryData) {
            $export['customizations'][$category] = $this->getCategory($category);
        }
        
        return $export;
    }
    
    /**
     * Import customizations
     */
    public function import(array $data, ?int $userId = null): bool
    {
        if (empty($data['customizations']) || !is_array($data['customizations'])) {
            return false;
        }
        
        return $this->setMultiple($data['customizations'], $userId);
    }
}
