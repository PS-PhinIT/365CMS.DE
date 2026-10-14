<?php
/**
 * Theme Manager
 * 
 * Handles theme loading and rendering
 * 
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS;

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists(__NAMESPACE__ . '\\ThemeManager', false)) {
    return;
}

class ThemeManager
{
    private static ?self $instance = null;
    private string $activeTheme;
    private string $themePath;
    private ?string $loadedThemeSlug = null;
    /** @var array<string,string>|null  null = noch nicht geladen (H-22 Lazy Loading) */
    private ?array $settings = null;
    /** @var array<string, array<string, mixed>>|null */
    private ?array $availableThemesCache = null;

    private function sanitizeThemeSlug(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', basename(trim($value))) ?? '';
    }

    private function getThemeRootPath(): string
    {
        return rtrim((string) THEME_PATH, '/\\');
    }

    private function buildThemeMutationLockPath(string $slug): string
    {
        return $this->getThemeRootPath() . DIRECTORY_SEPARATOR . '.cms-theme-lock-' . $this->sanitizeThemeSlug($slug);
    }

    private function acquireThemeMutationLock(string $slug): string|false
    {
        $lockPath = $this->buildThemeMutationLockPath($slug);

        if (@mkdir($lockPath, 0700)) {
            return $lockPath;
        }

        return false;
    }

    private function releaseThemeMutationLock(string|false $lockPath): void
    {
        if (!is_string($lockPath) || $lockPath === '' || !is_dir($lockPath)) {
            return;
        }

        @rmdir($lockPath);
    }

    private function resolveManagedThemeDirectory(string $folder, bool $requireStyleSheet = true): ?string
    {
        $slug = $this->sanitizeThemeSlug($folder);
        if ($slug === '') {
            return null;
        }

        $themeRoot = realpath($this->getThemeRootPath());
        if ($themeRoot === false) {
            return null;
        }

        $candidate = $this->getThemeRootPath() . DIRECTORY_SEPARATOR . $slug;
        if (!is_dir($candidate) || is_link($candidate)) {
            return null;
        }

        $resolvedPath = realpath($candidate);
        if ($resolvedPath === false) {
            return null;
        }

        $normalizedThemeRoot = rtrim(str_replace('\\', '/', $themeRoot), '/') . '/';
        $normalizedResolvedPath = rtrim(str_replace('\\', '/', $resolvedPath), '/') . '/';

        if (!str_starts_with($normalizedResolvedPath, $normalizedThemeRoot)) {
            return null;
        }

        if ($requireStyleSheet && !is_file($resolvedPath . DIRECTORY_SEPARATOR . 'style.css')) {
            return null;
        }

        return rtrim($resolvedPath, '/\\');
    }

    private function isPathInsideThemeRoot(string $path): bool
    {
        $themeRoot = realpath($this->getThemeRootPath());
        $resolvedPath = realpath($path);

        if ($themeRoot === false || $resolvedPath === false) {
            return false;
        }

        $normalizedThemeRoot = rtrim(str_replace('\\', '/', $themeRoot), '/') . '/';
        $normalizedResolvedPath = rtrim(str_replace('\\', '/', $resolvedPath), '/') . '/';

        return str_starts_with($normalizedResolvedPath, $normalizedThemeRoot);
    }
    
    /**
     * Singleton instance
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor
     */
    private function __construct()
    {
        $this->activeTheme = $this->getActiveTheme();
        $this->themePath   = THEME_PATH . $this->activeTheme . '/';

        // H-22: DB-Zugriff auf Lazy Loading verschoben → kein DB-Query bei jeder Instanziierung.
        // Settings werden erst in loadSettings() beim ersten echten Zugriff geladen.

        // Hooks
        Hooks::addAction('head', [$this, 'renderCustomStyles']);
        Hooks::addAction('head', [$this, 'renderSiteFavicon'], 5);
    }

    /**
     * H-22: Lädt Theme-Settings aus der DB beim ersten Zugriff (Lazy Loading).
     * Wird intern immer aufgerufen bevor $this->settings gelesen wird.
     */
    private function loadSettings(): void
    {
        if ($this->settings !== null) {
            return; // bereits geladen
        }

        $this->settings = [];

        try {
            $db   = Database::instance();
            $stmt = $db->query(
                "SELECT option_name, option_value FROM {$db->getPrefix()}settings"
                . " WHERE option_name LIKE 'color_%' OR option_name LIKE 'font_%' OR option_name LIKE 'site_%'"
            );
            while ($row = $stmt->fetch(\PDO::FETCH_OBJ)) {
                $this->settings[$row->option_name] = $row->option_value;
            }
        } catch (\Exception $e) {
            Logger::instance()->withChannel('theme')->warning('Theme settings could not be loaded.', [
                'exception' => $e,
            ]);
        }
    }
    
    /**
     * Load theme
     *
     * C-14: realpath()-Guard stellt sicher, dass das Theme-Verzeichnis
     * wirklich innerhalb von THEME_PATH liegt (Path-Traversal-Schutz).
     * H-21: Rollback auf DEFAULT_THEME bei Ladefehler.
     */
    public function loadTheme(): void
    {
        if ($this->loadedThemeSlug === $this->activeTheme) {
            return;
        }

        // C-14: Pfad-Validierung
        if (!$this->validateThemePath($this->themePath)) {
            Logger::instance()->withChannel('theme')->warning('Invalid theme path was rejected.', [
                'active_theme' => $this->activeTheme,
                'theme_path' => $this->themePath,
            ]);
            $this->rollbackToDefaultTheme('Ungültiger Theme-Pfad');
            return;
        }

        // Sync ThemeCustomizer to the active theme (before loading functions.php)
        if (class_exists(\CMS\Services\ThemeCustomizer::class)) {
            \CMS\Services\ThemeCustomizer::instance()->setTheme($this->activeTheme);
        }

        // Load theme functions
        $functionsFile = $this->themePath . 'functions.php';
        if (file_exists($functionsFile)) {
            // C-14: Gefährliche Funktionsaufrufe im Theme scannen
            if ($this->hasUnsafeCode($functionsFile)) {
                Logger::instance()->withChannel('theme')->warning('Theme functions file contains unsafe calls and was not loaded.', [
                    'active_theme' => $this->activeTheme,
                    'functions_file' => $functionsFile,
                ]);
                $this->rollbackToDefaultTheme('Unsicherer Code in functions.php');
                return;
            }

            try {
                require_once $functionsFile;
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('theme')->error('Theme functions file caused a runtime error.', [
                    'active_theme' => $this->activeTheme,
                    'functions_file' => $functionsFile,
                    'exception' => $e,
                ]);
                $this->rollbackToDefaultTheme('Laufzeitfehler in functions.php: ' . $e->getMessage());
                return;
            }
        }

        $this->loadedThemeSlug = $this->activeTheme;

        Hooks::doAction('theme_loaded', $this->activeTheme);
    }

    /**
     * C-14: Verifiziert, dass $path wirklich innerhalb von THEME_PATH liegt.
     * Verhindert Path-Traversal-Angriffe (z. B. ../../etc/).
     */
    private function validateThemePath(string $path): bool
    {
        $realThemePath = realpath(THEME_PATH);
        $realPath      = realpath($path);

        if ($realThemePath === false || $realPath === false) {
            return false;
        }

        // Pfad muss mit dem erlaubten Basisverzeichnis beginnen
        return str_starts_with($realPath . DIRECTORY_SEPARATOR, $realThemePath . DIRECTORY_SEPARATOR);
    }

    /**
     * C-14: Einfacher Scan auf gefährliche PHP-Funktionen in einer Theme-Datei.
     * Gibt true zurück wenn unsicherer Code gefunden wurde.
     */
    private function hasUnsafeCode(string $file): bool
    {
        $dangerousFunctions = [
            'eval', 'exec', 'system', 'shell_exec',
            'passthru', 'proc_open', 'popen',
            'base64_decode', // häufig für verschleierten Code genutzt
        ];

        $content = is_file($file) ? file_get_contents($file) : false; // M-03: kein @
        if ($content === false) {
            return false;
        }

        // Ergebnis je Dateiinhalt merken: token_get_all() über die ~90 KB functions.php lief sonst bei jedem Request.
        $cacheDir = defined('ABSPATH') ? ABSPATH . 'cache' : '';
        $cleanFlag = $cacheDir !== '' ? $cacheDir . '/theme_scan_' . hash('sha256', $file . "\0" . $content) . '.ok' : '';
        if ($cleanFlag !== '' && is_file($cleanFlag)) {
            return false;
        }

        try {
            $tokens = token_get_all($content, TOKEN_PARSE);
        } catch (\ParseError $e) {
            Logger::instance()->withChannel('theme')->warning('Theme PHP file contains a parse error and was rejected.', [
                'file' => $file,
                'exception' => $e,
            ]);
            return true; // Syntaxfehler = trotzdem ablehnen
        }

        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_STRING) {
                if (in_array(strtolower($token[1]), $dangerousFunctions, true)) {
                    Logger::instance()->withChannel('theme')->warning('Dangerous PHP function detected in theme file.', [
                        'file' => $file,
                        'function' => (string) $token[1],
                    ]);
                    return true;
                }
            }
        }

        if ($cleanFlag !== '' && (is_dir($cacheDir) || @mkdir($cacheDir, 0755, true))) {
            $existingFlags = glob($cacheDir . '/theme_scan_*.ok') ?: [];
            if (count($existingFlags) >= 32) {
                array_map('unlink', array_filter($existingFlags, 'is_file'));
            }
            @file_put_contents($cleanFlag, '', LOCK_EX);
        }

        return false;
    }

    /**
     * H-21: Rollback auf DEFAULT_THEME bei einem Ladefehler des aktiven Themes.
     *
     * Wird von loadTheme() aufgerufen wenn das Theme nicht sicher geladen werden kann.
     * Schreibt DEFAULT_THEME in die DB und setzt $this->activeTheme + $this->themePath
     * zurück, sodass nachfolgende Render-Aufrufe noch funktionieren.
     */
    private function rollbackToDefaultTheme(string $reason = ''): void
    {
        $default = DEFAULT_THEME;

        // Nicht in eine Endlosschleife laufen wenn das Default-Theme selbst defekt ist
        if ($this->activeTheme === $default) {
            Logger::instance()->withChannel('theme')->error('Theme rollback was aborted because the default theme is also broken.', [
                'default_theme' => $default,
                'reason' => $reason,
            ]);
            return;
        }

        Logger::instance()->withChannel('theme')->warning('Theme rollback to default theme was triggered.', [
            'active_theme' => $this->activeTheme,
            'default_theme' => $default,
            'reason' => $reason,
        ]);

        // Audit-Log
        if (class_exists(AuditLogger::class)) {
            AuditLogger::instance()->log(
                'theme',
                'theme.rollback',
                sprintf('Theme "%s" automatisch auf DEFAULT_THEME "%s" zurückgesetzt. Grund: %s', $this->activeTheme, $default, $reason),
                'theme',
                null,
                ['from' => $this->activeTheme, 'to' => $default, 'reason' => $reason],
                'warning'
            );
        }

        // DB-Eintrag aktualisieren
        try {
            $db    = Database::instance();
            $check = $db->prepare("SELECT COUNT(*) AS cnt FROM {$db->getPrefix()}settings WHERE option_name = 'active_theme'");
            $check->execute();
            $result = $check->fetch();

            if ($result && (int)$result->cnt > 0) {
                $db->execute(
                    "UPDATE {$db->getPrefix()}settings SET option_value = ? WHERE option_name = 'active_theme'",
                    [$default]
                );
            } else {
                $db->execute(
                    "INSERT INTO {$db->getPrefix()}settings (option_name, option_value) VALUES ('active_theme', ?)",
                    [$default]
                );
            }
        } catch (\Exception $e) {
            Logger::instance()->withChannel('theme')->error('Theme rollback could not be persisted to settings.', [
                'default_theme' => $default,
                'active_theme' => $this->activeTheme,
                'exception' => $e,
            ]);
        }

        // Runtime-Zustand aktualisieren
        $this->activeTheme = $default;
        $this->themePath   = THEME_PATH . $default . '/';
        $this->loadedThemeSlug = null;
        $this->resetAvailableThemesCache();
    }

    /**
     * Rendert eine Theme-Datei mit validiertem lokalen Scope statt globalem extract().
     *
     * @param array<string,mixed> $data
     */
    private function renderScopedFile(string $file, array $data = []): void
    {
        $render = static function (string $__cmsFile, array $__cmsVars): void {
            foreach ($__cmsVars as $__cmsKey => $__cmsValue) {
                if (!is_string($__cmsKey) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $__cmsKey) !== 1) {
                    continue;
                }

                if (in_array($__cmsKey, ['__cmsFile', '__cmsVars', 'render'], true)) {
                    continue;
                }

                ${$__cmsKey} = $__cmsValue;
            }

            include $__cmsFile;
        };

        $render($file, $data);
    }

    /**
     * Render template
     */
    public function render(string $template, array $data = []): void
    {
        // Allow plugins to modify template
        $template = Hooks::applyFilters('template_name', $template);
        $this->loadTheme();

        // Seitenvorlagen aus theme.json (`page_templates`): gewählte Vorlage → Theme-Datei,
        // gespeicherte Zusatzfelder → `$page['meta']`.
        if ($template === 'page' && (is_array($data['page'] ?? null) || is_object($data['page'] ?? null))) {
            try {
                $prepared = (new Services\PageTemplateService($this->themePath))->prepareForRender((array) $data['page']);
                if (is_file($this->themePath . $prepared['template'] . '.php')) {
                    $template = $prepared['template'];
                }
                $data['page'] = is_object($data['page']) ? (object) $prepared['page'] : $prepared['page'];
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('theme')->warning('Seitenvorlage konnte nicht aufgelöst werden; Standardlayout wird verwendet.', [
                    'exception' => $e->getMessage(),
                ]);
            }
        }
        
        // Template hierarchy
        $templates = [
            $this->themePath . $template . '.php',
            $this->themePath . 'index.php'
        ];
        
        foreach ($templates as $file) {
            if (file_exists($file)) {
                $this->publishCurrentContent($template, $data);

                // Pre-render hook
                Hooks::doAction('before_render', $template);
                
                // Include header
                $this->getHeader($data);
                
                // Include template
                $this->renderScopedFile($file, $data);
                
                // Include footer
                $this->getFooter($data);
                
                // Track page view (for analytics)
                $this->trackPageView($template, $data);
                
                // Post-render hook
                Hooks::doAction('after_render', $template);
                
                return;
            }
        }
        
        // Template not found
        echo '<h1>Template nicht gefunden</h1>';
    }
    
    /**
     * Stellt Beitrag bzw. Seite der aktuellen Anfrage global bereit.
     *
     * Header-Hooks laufen außerhalb des Template-Scopes. SEO-Ausgabe (Canonical, Robots,
     * Open Graph, JSON-LD), Hub-Erkennung und Themes lesen den Inhalt aus `$GLOBALS['post']`
     * bzw. `$GLOBALS['page']`; ohne diese Zuordnung fielen sie auf generische Startseiten-Daten
     * zurück oder fragten denselben Datensatz erneut aus der Datenbank ab.
     *
     * @param array<string, mixed> $data
     */
    private function publishCurrentContent(string $template, array $data): void
    {
        if (in_array($template, ['404', 'error'], true) || preg_match('/^[45]\d\d$/', $template) === 1) {
            unset($GLOBALS['post'], $GLOBALS['page']);
            return;
        }

        foreach (['post', 'page'] as $key) {
            if (isset($data[$key]) && (is_array($data[$key]) || is_object($data[$key]))) {
                $GLOBALS[$key] = $data[$key];
            }
        }
    }

    /**
     * Track page view for analytics
     */
    private function trackPageView(string $template, array $data): void
    {
        try {
            // Only track if TrackingService exists
            if (!class_exists(\CMS\Services\TrackingService::class)) {
                return;
            }
            
            $tracking = \CMS\Services\TrackingService::getInstance();
            
            // Determine page info
            $pageId = null;
            $pageSlug = $template;
            $pageTitle = $template;
            
            // Check if we have page data
            if (isset($data['page']) && is_array($data['page'])) {
                $pageId = $data['page']['id'] ?? null;
                $pageSlug = $data['page']['slug'] ?? $pageSlug;
                $pageTitle = $data['page']['title'] ?? $pageTitle;
            }
            
            // Get user ID if logged in
            $userId = null;
            if (isset($_SESSION['user_id'])) {
                $userId = (int)$_SESSION['user_id'];
            }
            
            // Track the view
            $tracking->trackPageView($pageId, $pageSlug, $pageTitle, $userId);
        } catch (\Throwable $e) {
            // Silently fail - tracking should never break the site
            Logger::instance()->withChannel('theme')->warning('Theme page view tracking failed.', [
                'template' => $template,
                'exception' => $e,
            ]);
        }
    }
    
    /**
     * Get header
     */
    public function getHeader(array $data = []): void
    {
        $this->loadTheme();
        $headerFile = $this->themePath . 'header.php';
        if (file_exists($headerFile)) {
            Hooks::doAction('before_header');
            $this->renderScopedFile($headerFile, $data);
            Hooks::doAction('after_header');
        }
    }
    
    /**
     * Get footer
     */
    public function getFooter(array $data = []): void
    {
        $this->loadTheme();
        $footerFile = $this->themePath . 'footer.php';
        if (file_exists($footerFile)) {
            Hooks::doAction('before_footer');
            $this->renderScopedFile($footerFile, $data);
            Hooks::doAction('body_end');
            Hooks::doAction('after_footer');
        }
    }
    
    /**
     * Get active theme slug
     */
    public function getActiveThemeSlug(): string
    {
        return $this->activeTheme;
    }

    /**
     * Get active theme from database, with folder-existence fallback
     */
    private function getActiveTheme(): string
    {
        $slug = DEFAULT_THEME;

        try {
            $activeTheme = (string) Services\OptionStore::getInstance()->get('active_theme', '');
            if ($activeTheme !== '') {
                // Defense-in-depth: Slug darf nur sichere Zeichen enthalten
                $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $activeTheme);
            }
        } catch (\Exception $e) {
            Logger::instance()->withChannel('theme')->warning('Active theme could not be loaded from settings.', [
                'exception' => $e,
            ]);
        }

        // Verify theme folder exists; if not, use first folder that has a style.css
        if (!is_dir(THEME_PATH . $slug)) {
            foreach (scandir(THEME_PATH) ?: [] as $dir) {
                if ($dir === '.' || $dir === '..') {
                    continue;
                }
                if (file_exists(THEME_PATH . $dir . '/style.css')) {
                    $slug = $dir;
                    break;
                }
            }
        }

        return $slug;
    }
    
    /**
     * Get available themes
     */
    public function getAvailableThemes(): array
    {
        if ($this->availableThemesCache !== null) {
            return $this->availableThemesCache;
        }

        $themes = [];
        $themeDir = THEME_PATH;
        
        if (!is_dir($themeDir)) {
            return $themes;
        }
        
        $directories = scandir($themeDir);
        
        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $resolvedThemeDirectory = $this->resolveManagedThemeDirectory((string) $dir);
            if ($resolvedThemeDirectory === null) {
                continue;
            }

            $folder = basename($resolvedThemeDirectory);
            $themeFile = $resolvedThemeDirectory . DIRECTORY_SEPARATOR . 'style.css';

            $data = $this->getThemeData($themeFile);
            if ($data) {
                $data = $this->enrichAvailableThemeData($folder, $data);
                $data['folder'] = $folder;
                $data['active'] = ($folder === $this->activeTheme);
                $themes[$folder] = $data;
            }
        }
        
        return $this->availableThemesCache = $themes;
    }
    
    /**
     * Get current theme data
     */
    public function getCurrentTheme(): array
    {
        $availableThemes = $this->getAvailableThemes();
        if (isset($availableThemes[$this->activeTheme])) {
            return $availableThemes[$this->activeTheme];
        }

        $themeFile = $this->themePath . 'style.css';
        
        if (file_exists($themeFile)) {
            $data = $this->getThemeData($themeFile);
            if ($data) {
                $data['folder'] = $this->activeTheme;
                $data['active'] = true;
                return $data;
            }
        }
        
        // Fallback if theme data cannot be read
        return [
            'name' => $this->activeTheme,
            'folder' => $this->activeTheme,
            'active' => true
        ];
    }
    
    /**
     * Get theme data from style.css headers
     */
    private function getThemeData(string $file): array|false
    {
        $content = file_get_contents($file);
        if (!$content) {
            return false;
        }
        
        $data = [];
        $headers = [
            'name' => 'Theme Name',
            'description' => 'Description',
            'version' => 'Version',
            'author' => 'Author'
        ];
        
        foreach ($headers as $key => $header) {
            if (preg_match('/' . $header . ':\s*(.+)/i', $content, $matches)) {
                $data[$key] = trim($matches[1]);
            }
        }
        
        return $data ?: false;
    }
    
    /**
     * Switch theme
     *
     * C-15: Audit-Log für Theme-Wechsel
     * H-20: Gesundheitscheck vor dem Aktivieren
     */
    public function switchTheme(string $theme): bool|string
    {
        $resolvedThemeDirectory = $this->resolveManagedThemeDirectory($theme);
        if ($resolvedThemeDirectory === null) {
            return 'Theme nicht gefunden.';
        }

        $themeFolder = basename($resolvedThemeDirectory);
        $lockPath = $this->acquireThemeMutationLock($themeFolder);
        if ($lockPath === false) {
            return 'Für dieses Theme läuft bereits eine andere Verwaltungsaktion.';
        }

        try {
            if ($this->resolveManagedThemeDirectory($themeFolder) === null) {
                return 'Theme ist nicht mehr verfügbar.';
            }

            // H-20: Gesundheitscheck (Pflichtdateien + Syntax aller PHP-Dateien)
            $healthResult = $this->healthCheckTheme($themeFolder);
            if ($healthResult !== true) {
                Logger::instance()->withChannel('theme')->warning('Theme health check failed before activation.', [
                    'theme' => $themeFolder,
                    'health_result' => $healthResult,
                ]);
                return 'Theme-Gesundheitscheck fehlgeschlagen: ' . $healthResult;
            }

            $db = Database::instance();

            $check = $db->prepare("SELECT COUNT(*) AS cnt FROM {$db->getPrefix()}settings WHERE option_name = 'active_theme'");
            $check->execute();
            $result = $check->fetch();

            if ($result && (int)$result->cnt > 0) {
                $db->execute(
                    "UPDATE {$db->getPrefix()}settings SET option_value = ? WHERE option_name = 'active_theme'",
                    [$themeFolder]
                );
            } else {
                $db->execute(
                    "INSERT INTO {$db->getPrefix()}settings (option_name, option_value) VALUES ('active_theme', ?)",
                    [$themeFolder]
                );
            }

            // C-15: Theme-Wechsel protokollieren
            AuditLogger::instance()->themeSwitch($this->activeTheme, $themeFolder);

            $this->activeTheme = $themeFolder;
            $this->themePath = $resolvedThemeDirectory . '/';
            $this->loadedThemeSlug = null;
            $this->resetAvailableThemesCache();

            return true;
        } catch (\Exception $e) {
            Logger::instance()->withChannel('theme')->error('Theme switch failed.', [
                'theme' => $themeFolder,
                'exception' => $e,
            ]);
            return 'Fehler beim Wechseln des Themes.';
        } finally {
            $this->releaseThemeMutationLock($lockPath);
        }
    }

    /**
     * H-20: Gesundheitscheck für ein Theme vor der Aktivierung.
     *
     * Prüft:
     *   1. style.css vorhanden (Pflicht)
     *   2. Mindestens eine Template-Datei (index.php oder functions.php)
     *   3. PHP-Syntaxprüfung aller .php-Dateien via token_get_all()
     *
     * @return true|string  true = gesund, string = Fehlerbeschreibung
     */
    public function healthCheckTheme(string $theme): bool|string
    {
        $resolvedThemeDirectory = $this->resolveManagedThemeDirectory($theme);
        if ($resolvedThemeDirectory === null) {
            return 'Theme-Verzeichnis nicht gefunden.';
        }

        $themeDir = rtrim($resolvedThemeDirectory, '/\\') . '/';

        // 1. style.css Pflicht
        if (!file_exists($themeDir . 'style.css')) {
            return 'Pflichtdatei style.css fehlt.';
        }

        // 2. Mindestens eine Template-Datei
        $hasTemplate = file_exists($themeDir . 'index.php') || file_exists($themeDir . 'functions.php');
        if (!$hasTemplate) {
            return 'Keine Template-Datei gefunden (index.php oder functions.php erforderlich).';
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($themeDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // M-03: is_readable statt @ für lesbare Dateien prüfen
            $content = (is_file($file->getPathname()) && is_readable($file->getPathname()))
                ? file_get_contents($file->getPathname())
                : false;
            if ($content === false) {
                continue;
            }

            try {
                token_get_all($content, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $relPath = str_replace($themeDir, '', $file->getPathname());
                return sprintf('PHP-Syntaxfehler in %s: %s', $relPath, $e->getMessage());
            }
        }

        return true;
    }
    
    /**
     * Get theme path
     */
    public function getThemePath(): string
    {
        return $this->themePath;
    }

    /**
     * Delete a theme (only non-active, only if at least 2 themes exist)
     *
     * C-15: Audit-Log für Theme-Löschung
     */
    public function deleteTheme(string $folder): bool|string
    {
        $folder = $this->sanitizeThemeSlug($folder);
        if ($folder === '') {
            return 'Theme nicht gefunden.';
        }

        if ($folder === $this->activeTheme) {
            return 'Das aktive Theme kann nicht gelöscht werden.';
        }

        $themeDir = $this->resolveManagedThemeDirectory($folder);
        if ($themeDir === null) {
            return 'Theme nicht gefunden.';
        }

        $availableThemes = $this->getAvailableThemes();
        if (!isset($availableThemes[$folder])) {
            return 'Theme ist nicht mehr verfügbar.';
        }

        if (count($availableThemes) <= 1) {
            return 'Das letzte verfügbare Theme kann nicht gelöscht werden.';
        }

        $lockPath = $this->acquireThemeMutationLock($folder);
        if ($lockPath === false) {
            return 'Für dieses Theme läuft bereits eine andere Verwaltungsaktion.';
        }

        try {
            clearstatcache(true, $themeDir);
            if ($this->resolveManagedThemeDirectory($folder) === null) {
                return 'Theme ist nicht mehr verfügbar.';
            }

            $this->deleteDirectory($themeDir);
            $this->resetAvailableThemesCache();

            // C-15: Theme-Löschung protokollieren
            AuditLogger::instance()->themeDelete($folder);

            return true;
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('theme')->error('Theme deletion failed.', [
                'theme' => $folder,
                'exception' => $e,
            ]);
            return 'Fehler beim Löschen: ' . $e->getMessage();
        } finally {
            $this->releaseThemeMutationLock($lockPath);
        }
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    private function enrichAvailableThemeData(string $folder, array $data): array
    {
        $themeDir = rtrim(THEME_PATH, '/\\') . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR;
        $themeJson = $this->getThemeJsonData($themeDir);
        if ($themeJson !== []) {
            $data['json'] = $themeJson;
            $data['description'] = (string) ($themeJson['description'] ?? $data['description'] ?? '');
            $data['version'] = (string) ($themeJson['version'] ?? $data['version'] ?? '');
            $data['author'] = (string) ($themeJson['author'] ?? $data['author'] ?? '');
            $data['slug'] = (string) ($themeJson['slug'] ?? $folder);
        }

        $screenshotPath = $themeDir . 'screenshot.png';
        if (is_file($screenshotPath)) {
            $data['screenshot'] = '/themes/' . $folder . '/screenshot.png';
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function getThemeJsonData(string $themeDir): array
    {
        $jsonPath = $themeDir . 'theme.json';
        if (!is_file($jsonPath) || !is_readable($jsonPath)) {
            return [];
        }

        $content = file_get_contents($jsonPath);
        if ($content === false || $content === '') {
            return [];
        }

        return Json::decodeArray($content, []);
    }

    private function resetAvailableThemesCache(): void
    {
        $this->availableThemesCache = null;
    }

    /**
     * Recursively delete a directory
     */
    private function deleteDirectory(string $dir): void
    {
        $resolvedDirectory = realpath($dir);
        if ($resolvedDirectory === false || !is_dir($resolvedDirectory) || is_link($resolvedDirectory) || !$this->isPathInsideThemeRoot($resolvedDirectory)) {
            return;
        }

        foreach (scandir($resolvedDirectory) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $resolvedDirectory . DIRECTORY_SEPARATOR . $item;

            if (is_link($path)) {
                unlink($path);
                continue;
            }

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($resolvedDirectory);
    }
    
    /**
     * Get theme URL
     */
    public function getThemeUrl(): string
    {
        if (function_exists('cms_runtime_base_url')) {
            return \cms_runtime_base_url('themes/' . $this->activeTheme);
        }

        return SITE_URL . '/themes/' . $this->activeTheme;
    }

    /**
     * Get Site Title
     */
    public function getSiteTitle(): string
    {
        $this->loadSettings(); // H-22
        return $this->settings['site_title']
            ?? $this->settings['site_name']
            ?? (function_exists('cms_get_site_title') ? cms_get_site_title() : SITE_NAME);
    }

    /**
     * Get Site Description
     */
    public function getSiteDescription(): string
    {
        $this->loadSettings(); // H-22
        return $this->settings['site_description'] ?? '';
    }

    /**
     * Get Site Menu Items (legacy – liest 'site_menu' aus Settings)
     */
    public function getSiteMenu(): array
    {
        $this->loadSettings(); // H-22
        return Json::decodeArray($this->settings['site_menu'] ?? null, []);
    }

    /**
     * Get menu items for a named location.
     * Falls back to legacy 'site_menu' for the 'primary' location.
     */
    public function getMenu(string $location): array
    {
        try {
            $menuJson = Services\OptionStore::getInstance()->get('menu_' . $location);
            if ($menuJson !== null && $menuJson !== '') {
                return Json::decodeArray($menuJson, []);
            }
        } catch (\Exception $e) {
            Logger::instance()->withChannel('theme')->warning('Theme menu could not be loaded.', [
                'location' => $location,
                'exception' => $e,
            ]);
        }

        // Legacy fallback for primary nav
        if ($location === 'primary') {
            return $this->getSiteMenu();
        }

        return [];
    }

    /**
     * Save menu items for a named location.
     */
    public function saveMenu(string $location, array $items): bool
    {
        $key   = 'menu_' . $location;
        $value = json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        try {
            $db      = Database::instance();
            $check   = $db->prepare("SELECT id FROM {$db->getPrefix()}settings WHERE option_name = ? LIMIT 1");
            $check->execute([$key]);

            if ($check->fetch()) {
                $db->execute(
                    "UPDATE {$db->getPrefix()}settings SET option_value = ? WHERE option_name = ?",
                    [$value, $key]
                );
            } else {
                $db->execute(
                    "INSERT INTO {$db->getPrefix()}settings (option_name, option_value) VALUES (?, ?)",
                    [$key, $value]
                );
            }
            return true;
        } catch (\Exception $e) {
            Logger::instance()->withChannel('theme')->warning('Theme menu could not be saved.', [
                'location' => $location,
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * Get all registered menu locations.
     * Themes register their locations via the 'register_menu_locations' filter.
     * Additional custom locations are saved as 'menu_custom_locations' in settings.
     */
    public function getMenuLocations(): array
    {
        // Built-in defaults from active theme (via filter – registered by functions.php in frontend)
        $locations = Hooks::applyFilters('register_menu_locations', []);

        // M-NEW: Fallback – Menüpositionen direkt aus theme.json lesen,
        // damit sie auch im Admin-Bereich ohne geladene functions.php verfügbar sind.
        $themeJsonFile = $this->themePath . 'theme.json';
        if (file_exists($themeJsonFile)) {
            try {
                $json = Json::decodeArray(file_get_contents($themeJsonFile), []);
                if (isset($json['menus']) && is_array($json['menus'])) {
                    $existingSlugs = array_column($locations, 'slug');
                    foreach ($json['menus'] as $slug => $label) {
                        if (!in_array($slug, $existingSlugs, true)) {
                            $locations[] = ['slug' => (string)$slug, 'label' => (string)$label];
                            $existingSlugs[] = (string)$slug;
                        }
                    }
                }
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('theme')->warning('Theme menu locations from theme.json could not be loaded.', [
                    'theme_path' => $themeJsonFile,
                    'exception' => $e,
                ]);
            }
        }

        // Additional user-created locations
        try {
            $customJson = Services\OptionStore::getInstance()->get('menu_custom_locations');

            if ($customJson !== null && $customJson !== '') {
                $custom = Json::decodeArray($customJson, []);
                foreach ($custom as $loc) {
                    // Defensive: nur valide Array-Einträge mit 'slug'-Schlüssel verarbeiten
                    if (!is_array($loc) || !isset($loc['slug'], $loc['label'])) {
                        continue;
                    }
                    // Avoid duplicates from theme-registered locations
                    $slugs = array_column($locations, 'slug');
                    if (!in_array($loc['slug'], $slugs, true)) {
                        $locations[] = $loc;
                    }
                }
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('theme')->warning('Custom theme menu locations could not be loaded.', [
                'exception' => $e,
            ]);
        }

        return $locations;
    }

    /**
     * Save the list of custom (user-created) menu locations.
     */
    public function saveCustomMenuLocations(array $locations): bool
    {
        $value = json_encode(array_values($locations), JSON_UNESCAPED_UNICODE);

        try {
            $db    = Database::instance();
            $check = $db->prepare("SELECT id FROM {$db->getPrefix()}settings WHERE option_name = 'menu_custom_locations' LIMIT 1");
            $check->execute();

            if ($check->fetch()) {
                $db->execute(
                    "UPDATE {$db->getPrefix()}settings SET option_value = ? WHERE option_name = 'menu_custom_locations'",
                    [$value]
                );
            } else {
                $db->execute(
                    "INSERT INTO {$db->getPrefix()}settings (option_name, option_value) VALUES ('menu_custom_locations', ?)",
                    [$value]
                );
            }
            return true;
        } catch (\Exception $e) {
            Logger::instance()->withChannel('theme')->warning('Custom theme menu locations could not be saved.', [
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * Render Customizer Styles
     */
    public function renderCustomStyles(): void
    {
        $this->loadSettings(); // H-22

        if (empty($this->settings)) {
            return;
        }

        echo "<style id='cms-customizer' " . \CMS\Security::instance()->nonceAttr() . ">\n:root {\n";
        
        $s = $this->settings;
        if (isset($s['color_primary'])) echo "    --primary-color: " . htmlspecialchars($s['color_primary']) . ";\n";
        if (isset($s['color_secondary'])) echo "    --secondary-color: " . htmlspecialchars($s['color_secondary']) . ";\n";
        if (isset($s['color_bg'])) echo "    --bg-secondary: " . htmlspecialchars($s['color_bg']) . ";\n";
        if (isset($s['color_text'])) echo "    --text-primary: " . htmlspecialchars($s['color_text']) . ";\n";

        $bodyFontStack = $this->resolveConfiguredFontStack((string) ($s['font_body'] ?? ''));
        $headingFontStack = $this->resolveConfiguredFontStack((string) ($s['font_heading'] ?? ''));
        $bodyFontSize = $this->normalizeFontSizeCssValue($s['font_size_base'] ?? $s['font_size'] ?? null);
        $bodyLineHeight = $this->normalizeLineHeightCssValue($s['font_line_height'] ?? null);

        if ($bodyFontStack !== '') echo "    --font-body: " . htmlspecialchars($bodyFontStack, ENT_QUOTES, 'UTF-8') . ";\n";
        if ($bodyFontStack !== '') echo "    --font-sans: " . htmlspecialchars($bodyFontStack, ENT_QUOTES, 'UTF-8') . ";\n";
        if ($bodyFontStack !== '') echo "    --font-family-base: " . htmlspecialchars($bodyFontStack, ENT_QUOTES, 'UTF-8') . ";\n";
        if ($headingFontStack !== '') echo "    --font-heading: " . htmlspecialchars($headingFontStack, ENT_QUOTES, 'UTF-8') . ";\n";
        if ($headingFontStack !== '') echo "    --font-serif: " . htmlspecialchars($headingFontStack, ENT_QUOTES, 'UTF-8') . ";\n";
        if ($headingFontStack !== '') echo "    --font-family-heading: " . htmlspecialchars($headingFontStack, ENT_QUOTES, 'UTF-8') . ";\n";
        if ($bodyFontSize !== '') echo "    --font-size-base: " . htmlspecialchars($bodyFontSize, ENT_QUOTES, 'UTF-8') . ";\n";
        if ($bodyLineHeight !== '') echo "    --line-height-base: " . htmlspecialchars($bodyLineHeight, ENT_QUOTES, 'UTF-8') . ";\n";
        
        echo "}\n";
        
        if ($bodyFontStack !== '' || isset($s['font_family']) || $bodyFontSize !== '' || $bodyLineHeight !== '') {
            echo "body {\n";
            if ($bodyFontStack !== '') echo "    font-family: " . htmlspecialchars($bodyFontStack, ENT_QUOTES, 'UTF-8') . ";\n";
            if (isset($s['font_family'])) echo "    font-family: " . htmlspecialchars($s['font_family'], ENT_QUOTES, 'UTF-8') . ";\n";
            if ($bodyFontSize !== '') echo "    font-size: " . htmlspecialchars($bodyFontSize, ENT_QUOTES, 'UTF-8') . ";\n";
            if ($bodyLineHeight !== '') echo "    line-height: " . htmlspecialchars($bodyLineHeight, ENT_QUOTES, 'UTF-8') . ";\n";
            if (isset($s['font_size']) && $bodyFontSize === '') echo "    font-size: " . htmlspecialchars($s['font_size']) . ";\n";
            echo "}\n";
        }

        if ($headingFontStack !== '') {
            echo "h1, h2, h3, h4, h5, h6 {\n";
            echo "    font-family: " . htmlspecialchars($headingFontStack, ENT_QUOTES, 'UTF-8') . ";\n";
            echo "}\n";
        }
        
        echo "</style>\n";
    }

    private function resolveConfiguredFontStack(string $fontKey): string
    {
        $fontKey = strtolower(trim($fontKey));
        if ($fontKey === '') {
            return '';
        }

        $customStackKey = 'font_stack_' . $fontKey;
        $customStack = trim((string) ($this->settings[$customStackKey] ?? ''));
        if ($customStack !== '') {
            return $customStack;
        }

        return match ($fontKey) {
            'system-ui' => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
            'inter' => '"Inter", system-ui, sans-serif',
            'space-grotesk' => '"Space Grotesk", "Sora", "Inter", system-ui, sans-serif',
            'sora' => '"Sora", "Space Grotesk", "Inter", system-ui, sans-serif',
            'barlow' => '"Barlow", system-ui, sans-serif',
            'barlow-condensed' => '"Barlow Condensed", "Barlow", system-ui, sans-serif',
            'roboto' => '"Roboto", system-ui, sans-serif',
            'roboto-condensed' => '"Roboto Condensed", "Roboto", Arial, sans-serif',
            'open-sans' => '"Open Sans", system-ui, sans-serif',
            'lato' => '"Lato", system-ui, sans-serif',
            'montserrat' => '"Montserrat", system-ui, sans-serif',
            'poppins' => '"Poppins", system-ui, sans-serif',
            'source-sans' => '"Source Sans 3", "Inter", system-ui, sans-serif',
            'nunito' => '"Nunito", system-ui, sans-serif',
            'oswald' => '"Oswald", "Roboto Condensed", Arial, sans-serif',
            'rajdhani' => '"Rajdhani", "Barlow Condensed", "Inter", sans-serif',
            'exo2' => '"Exo 2", "Inter", system-ui, sans-serif',
            'dm-sans' => '"DM Sans", system-ui, sans-serif',
            'libre-baskerville' => '"Libre Baskerville", Georgia, serif',
            'playfair-display' => '"Playfair Display", Georgia, serif',
            'merriweather' => '"Merriweather", Georgia, serif',
            'jetbrains-mono' => '"JetBrains Mono", "Fira Code", "Cascadia Code", monospace',
            'fira-code' => '"Fira Code", "JetBrains Mono", "Cascadia Code", monospace',
            'source-code' => '"Source Code Pro", "Fira Code", monospace',
            'cascadia' => '"Cascadia Code", "JetBrains Mono", monospace',
            'system-mono' => '"Cascadia Code", "Consolas", "Courier New", monospace',
            'arial' => 'Arial, "Helvetica Neue", Helvetica, sans-serif',
            'georgia' => 'Georgia, "Times New Roman", Times, serif',
            'times-new-roman' => '"Times New Roman", Times, serif',
            'courier-new' => '"Courier New", Courier, monospace',
            'verdana' => 'Verdana, Geneva, sans-serif',
            'trebuchet-ms' => '"Trebuchet MS", "Lucida Grande", sans-serif',
            default => '',
        };
    }

    private function normalizeFontSizeCssValue(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        if (is_numeric($normalized)) {
            $size = max(10, min(72, (int) round((float) $normalized)));

            return $size . 'px';
        }

        return $normalized;
    }

    private function normalizeLineHeightCssValue(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $normalized = trim(str_replace(',', '.', (string) $value));
        if ($normalized === '' || !is_numeric($normalized)) {
            return '';
        }

        $lineHeight = max(1.0, min(3.0, (float) $normalized));

        return number_format($lineHeight, 1, '.', '');
    }

    /**
     * Rendert globale Favicon-Tags auf Basis der Core-Einstellung `site_favicon`.
     */
    public function renderSiteFavicon(): void
    {
        $this->loadSettings();

        $favicon = trim((string) ($this->settings['site_favicon'] ?? ''));
        $faviconUrl = $favicon !== '' ? $this->normalizeSiteAssetUrl($favicon) : '';

        if ($faviconUrl === '') {
            // Ohne eigenes Favicon das mitgelieferte 365CMS-Signet verwenden; der
            // Member-Bereich überschreibt den Default per Filter mit seinem Dashboard-Icon.
            $defaultIcon = (string) Hooks::applyFilters('site_favicon_default', 'images/LOGO_365CMS-wo_Text-50px.png');
            $faviconUrl = function_exists('cms_asset_url')
                ? \cms_asset_url($defaultIcon, false)
                : rtrim((string) SITE_URL, '/') . '/assets/' . ltrim($defaultIcon, '/');
        }

        if ($faviconUrl === '') {
            return;
        }

        $mimeType = $this->detectIconMimeType($faviconUrl);
        $escapedUrl = htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8');
        $escapedMime = htmlspecialchars($mimeType, ENT_QUOTES, 'UTF-8');

        echo '<link rel="icon" href="' . $escapedUrl . '" type="' . $escapedMime . '">' . "\n";
        echo '<link rel="shortcut icon" href="' . $escapedUrl . '" type="' . $escapedMime . '">' . "\n";
        echo '<link rel="apple-touch-icon" href="' . $escapedUrl . '">' . "\n";
    }

    private function normalizeSiteAssetUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (filter_var($path, FILTER_VALIDATE_URL) !== false) {
            return $path;
        }

        if (function_exists('cms_runtime_base_url')) {
            return \cms_runtime_base_url(ltrim($path, '/'));
        }

        return rtrim((string) SITE_URL, '/') . '/' . ltrim($path, '/');
    }

    private function detectIconMimeType(string $url): string
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return match (true) {
            str_ends_with($path, '.svg') => 'image/svg+xml',
            str_ends_with($path, '.png') => 'image/png',
            str_ends_with($path, '.jpg'), str_ends_with($path, '.jpeg') => 'image/jpeg',
            str_ends_with($path, '.webp') => 'image/webp',
            default => 'image/x-icon',
        };
    }
}
