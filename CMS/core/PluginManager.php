<?php
/**
 * Plugin Manager
 *
 * Handles plugin loading and management
 *
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS;

if (!defined('ABSPATH')) {
    exit;
}

class PluginManager
{
    private const PROTECTED_PLUGINS = ['cms-importer'];
    private const PLUGIN_SLUG_ALIASES = [
        'cms-companies' => 'cms-365netcompanies',
        'cms-events' => 'cms-365neteventsandspeaker',
        'cms-365netevents' => 'cms-365neteventsandspeaker',
        'cms-experts' => 'cms-365netexperts',
        'cms-speakers' => 'cms-365netspeakers',
        'cms-365netbooking' => 'cms-booking',
        'cms-365netcontact' => 'cms-contact',
        'cms-365netdownloads' => 'cms-downloads',
        'cms-365netfeed' => 'cms-feed',
        'cms-365netforum' => 'cms-forum',
        'cms-365netjobprofile-generator' => 'cms-jobprofile-generator',
        'cms-365netknowledgebase' => 'cms-knowledgebase',
        'cms-365netmarketplace' => 'cms-marketplace',
        'cms-365netnewsletter' => 'cms-newsletter',
        'cms-365netprojects' => 'cms-projects',
        'cms-365netpromos' => 'cms-promos',
        'cms-365netm365adminsites' => 'cms-m365adminsites',
        'cms-365netm365azure' => 'cms-m365azure',
        'cms-365netm365copilot' => 'cms-m365copilot',
        'cms-365netm365landing' => 'cms-m365landing',
        'cms-365netm365linkcollection' => 'cms-m365linkcollection',
        'cms-365netm365matrices' => 'cms-m365matrices',
        'cms-365netm365messagecenter' => 'cms-m365messagecenter',
        'cms-365netm365price-tracker' => 'cms-m365price-tracker',
        'cms-365netm365tools' => 'cms-m365tools',
        'cms-365netimporter' => 'cms-importer',
    ];

    private static ?self $instance = null;
    private array $plugins = [];
    private array $activePlugins = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Prüft ob ein Plugin aktiv ist.
     *
     * @param string $slug Plugin-Slug (z. B. 'cms-companies')
     */
    public function isPluginActive(string $slug): bool
    {
        $slug = $this->canonicalPluginSlug($slug);
        return in_array($slug, $this->activePlugins, true);
    }

    public function isProtectedPlugin(string $slug): bool
    {
        $slug = strtolower(trim($slug));

        return in_array($slug, self::PROTECTED_PLUGINS, true);
    }

    /**
     * Load all active plugins (C-07)
     */
    public function loadPlugins(): void
    {
        $this->activePlugins = $this->getActivePlugins();

        // In den Core integrierte Plugins (cms-feed, cms-downloads, cms-contact) nie mehr laden.
        $integratedPlugins = Services\IntegratedPluginService::getInstance();
        [$this->activePlugins, $retiredPlugins] = $integratedPlugins->filterActivePlugins($this->activePlugins);
        if ($retiredPlugins !== []) {
            $this->saveActivePlugins();
            $integratedPlugins->markRetired($retiredPlugins);
        }

        $availableBootstrapFiles = $this->getPluginBootstrapMap();
        $disabledPlugins = array_values(array_diff($this->activePlugins, array_keys($availableBootstrapFiles)));

        foreach ($disabledPlugins as $pluginSlug) {
            $context = [
                'plugin' => $pluginSlug,
                'expected_file' => (string) PLUGIN_PATH . $pluginSlug . '/' . $pluginSlug . '.php',
                'plugin_path' => defined('PLUGIN_PATH') ? (string) PLUGIN_PATH : null,
            ];
            Logger::instance()->withChannel('plugins')->critical('Active plugin file is missing and will be disabled.', $context);
            if (class_exists(AuditLogger::class)) {
                AuditLogger::instance()->log(
                    'plugin',
                    'plugin.missing_file',
                    sprintf('Aktives Plugin "%s" fehlt im Laufzeitordner und wurde automatisch deaktiviert.', $pluginSlug),
                    'plugin',
                    null,
                    $context,
                    'critical'
                );
            }
        }

        foreach ($availableBootstrapFiles as $pluginSlug => $pluginFile) {
            if (!in_array($pluginSlug, $this->activePlugins, true)) {
                continue;
            }

            try {
                require_once $pluginFile;
                Hooks::doAction('plugin_loaded', $pluginSlug);
            } catch (\Throwable $e) {
                $context = [
                    'plugin'    => $pluginSlug,
                    'error'     => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                    'exception' => get_class($e),
                ];
                Logger::instance()->withChannel('plugins')->critical('Plugin caused a fatal error during load and was disabled.', $context);
                if (class_exists(AuditLogger::class)) {
                    AuditLogger::instance()->log(
                        'plugin', 'plugin.load_error',
                        sprintf('Plugin "%s" verursachte einen Fatal-Error und wurde automatisch deaktiviert.', $pluginSlug),
                        'plugin', null, $context, 'critical'
                    );
                }
                $disabledPlugins[] = $pluginSlug;
            }
        }

        if (!empty($disabledPlugins)) {
            $this->activePlugins = array_values(array_diff($this->activePlugins, $disabledPlugins));
            $this->saveActivePlugins();
        }

        Hooks::doAction('plugins_loaded');
    }

    /**
     * Get all available plugins
     */
    public function getAvailablePlugins(): array
    {
        $plugins   = [];
        $pluginDir = PLUGIN_PATH;

        if (!is_dir($pluginDir)) {
            return $plugins;
        }

        $directories = scandir($pluginDir);
        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..' || $dir === '.gitkeep') {
                continue;
            }
            $slug = strtolower(trim((string) $dir));
            if (!$this->isValidPluginSlug($slug)) {
                continue;
            }

            $pluginFile = $this->resolvePluginBootstrapFile($slug);
            if ($pluginFile !== '' && file_exists($pluginFile)) {
                $data = $this->getPluginData($pluginFile);
                if ($data) {
                    $data['folder'] = $dir;
                    $data['active'] = in_array($slug, $this->activePlugins, true);
                    $plugins[$slug] = $data;
                }
            }
        }

        return $plugins;
    }

    /**
     * Get plugin data from file headers
     */
    private function getPluginData(string $file): array|false
    {
        $content = file_get_contents($file);
        if (!$content) {
            return false;
        }

        $data    = [];
        $headers = [
            'name'        => 'Plugin Name',
            'description' => 'Description',
            'version'     => 'Version',
            'author'      => 'Author',
            'requires'    => 'Requires',
            'requires_plugins' => 'Requires Plugins',
            'requires_cms' => 'Requires CMS',
        ];

        foreach ($headers as $key => $header) {
            if (preg_match('/' . preg_quote($header, '/') . ':\s*(.+)/i', $content, $matches)) {
                $data[$key] = trim($matches[1]);
            }
        }

        return $data ?: false;
    }

    /**
     * Get active plugins from database
     */
    public function getActivePlugins(): array
    {
        try {
            $activePluginsJson = Services\OptionStore::getInstance()->get('active_plugins');
            $plugins = [];
            if ($activePluginsJson !== null && $activePluginsJson !== '') {
                $plugins = Json::decodeArray($activePluginsJson, []);
            }

            if (!is_array($plugins)) {
                $plugins = [];
            }

            $plugins = array_values(array_unique(array_filter(array_map(
                fn($plugin): string => $this->canonicalPluginSlug((string) $plugin),
                $plugins
            ))));

            return $plugins;
        } catch (\Exception $e) {
            Logger::instance()->withChannel('plugins')->warning('Active plugin list could not be loaded.', [
                'exception' => $e,
            ]);
            return [];
        }
    }

    /**
     * Activate plugin (C-08)
     */
    public function activatePlugin(string $plugin): bool|string
    {
        $plugin = $this->canonicalPluginSlug($plugin);
        if (Services\IntegratedPluginService::isIntegrated($plugin)) {
            return sprintf(
                'Dieses Plugin ist seit 365CMS %s Teil des Cores und kann nicht mehr aktiviert werden.',
                Services\IntegratedPluginService::SINCE_VERSION
            );
        }

        $pluginFile = $this->resolvePluginBootstrapFile($plugin);
        if ($pluginFile === '' || !file_exists($pluginFile)) {
            return 'Plugin-Datei nicht gefunden.';
        }

        if (in_array($plugin, $this->activePlugins)) {
            return 'Plugin ist bereits aktiviert.';
        }

        $depCheck = $this->checkDependencies($plugin);
        if ($depCheck !== true) {
            return $depCheck;
        }

        $scanResult = $this->securityScanPlugin($plugin);
        if ($scanResult !== true) {
            return $scanResult;
        }

        $this->activePlugins[] = $plugin;
        $this->saveActivePlugins();

        require_once $pluginFile;
        $this->runLifecycleCallback($plugin, 'activate');
        Hooks::doAction('plugin_activated', $plugin);
        AuditLogger::instance()->pluginAction('activate', $plugin);

        return true;
    }

    /**
     * Check plugin dependencies (H-18)
     */
    private function checkDependencies(string $plugin): bool|string
    {
        $pluginFile = $this->resolvePluginBootstrapFile($plugin);
        if ($pluginFile === '' || !file_exists($pluginFile)) {
            return true;
        }

        $data       = $this->getPluginData($pluginFile);
        $updateData = $this->getPluginUpdateData($plugin);

        $cmsRequirement = $this->resolveCmsRequirement($data ?: [], $updateData);
        if ($cmsRequirement !== null && defined('CMS_VERSION') && version_compare(CMS_VERSION, $cmsRequirement, '<')) {
            return sprintf(
                'Benötigt 365CMS %s oder höher. Aktuell installiert: %s.',
                $cmsRequirement,
                CMS_VERSION
            );
        }

        if (!$data) {
            return true;
        }

        $required = $this->resolvePluginDependencies($data);
        if ($required === []) {
            return true;
        }

        $missing  = [];

        foreach ($required as $dep) {
            if (!in_array($dep, $this->activePlugins, true)) {
                $missing[] = $dep;
            }
        }

        if (!empty($missing)) {
            return sprintf(
                'Fehlende Abhängigkeit(en): %s. Bitte zuerst aktivieren.',
                implode(', ', $missing)
            );
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function getPluginUpdateData(string $plugin): array
    {
        $pluginDir = $this->resolvePluginDirectory($plugin);
        if ($pluginDir === '') {
            return [];
        }

        $updateFile = $pluginDir . DIRECTORY_SEPARATOR . 'update.json';
        if (!file_exists($updateFile)) {
            return [];
        }

        $raw = file_get_contents($updateFile);
        if ($raw === false || $raw === '') {
            return [];
        }

        return Json::decodeArray($raw, []);
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function resolvePluginDependencies(array $data): array
    {
        $raw = (string) ($data['requires_plugins'] ?? $data['requires'] ?? '');
        if ($raw === '') {
            return [];
        }

        $required = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $token) {
            $slug = $this->normalizeDependencySlug($token);
            if ($slug === '') {
                continue;
            }
            $required[] = $slug;
        }

        return array_values(array_unique($required));
    }

    /**
     * @param array<string, mixed> $headerData
     * @param array<string, mixed> $updateData
     */
    private function resolveCmsRequirement(array $headerData, array $updateData): ?string
    {
        $candidates = [
            (string) ($headerData['requires_cms'] ?? ''),
            (string) ($updateData['requires_cms'] ?? ''),
            (string) ($updateData['min_cms_version'] ?? ''),
        ];

        if (!empty($headerData['requires']) && empty($headerData['requires_plugins']) && $this->looksLikeVersionConstraint((string) $headerData['requires'])) {
            $candidates[] = (string) $headerData['requires'];
        }

        foreach ($candidates as $candidate) {
            $version = $this->extractVersionNumber($candidate);
            if ($version !== null) {
                return $version;
            }
        }

        return null;
    }

    private function normalizeDependencySlug(string $dependency): string
    {
        $dependency = trim($dependency);
        if ($dependency === '' || $this->looksLikeVersionConstraint($dependency)) {
            return '';
        }

        if (preg_match('/^([a-z0-9][a-z0-9_-]*)/i', $dependency, $matches)) {
            return $this->canonicalPluginSlug((string) $matches[1]);
        }

        return '';
    }

    private function looksLikeVersionConstraint(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return preg_match('/^(?:365cms\s*)?(?:[><=~^]+\s*)?v?\d+(?:\.\d+)*(?:\+)?$/i', $value) === 1;
    }

    private function extractVersionNumber(string $value): ?string
    {
        if (preg_match('/\d+(?:\.\d+)+/', $value, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    /**
     * Security scan before activation (C-08).
     *
     * Uses regex with lookbehind to distinguish PHP built-in functions
     * (exec, system …) from harmless OOP method calls ($pdo->exec(), etc.).
     *
     * @return true|string  true = clean, string = error message
     */
    private function securityScanPlugin(string $plugin): bool|string
    {
        $pluginDir = $this->resolvePluginDirectory($plugin);
        if ($pluginDir === '' || !is_dir($pluginDir)) {
            return 'Plugin-Verzeichnis nicht gefunden.';
        }

        return $this->securityScanPluginDirectory($plugin, $pluginDir);
    }

    private function securityScanPluginDirectory(string $plugin, string $pluginDir): bool|string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pluginDir, \FilesystemIterator::SKIP_DOTS)
        );

        $dangerousFunctions = [
            'eval'       => '/(?<!->)(?<!::)\beval\s*\(/i',
            'exec'       => '/(?<!->)(?<!::)\bexec\s*\(/i',
            'shell_exec' => '/(?<!->)(?<!::)\bshell_exec\s*\(/i',
            'system'     => '/(?<!->)(?<!::)\bsystem\s*\(/i',
            'passthru'   => '/(?<!->)(?<!::)\bpassthru\s*\(/i',
            'popen'      => '/(?<!->)(?<!::)\bpopen\s*\(/i',
            'proc_open'  => '/(?<!->)(?<!::)\bproc_open\s*\(/i',
            'pcntl_exec' => '/(?<!->)(?<!::)\bpcntl_exec\s*\(/i',
        ];

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            // 1. Syntax check
            try {
                token_get_all($content, TOKEN_PARSE);
            } catch (\ParseError $e) {
                return sprintf(
                    'PHP-Syntaxfehler in %s: %s',
                    str_replace($pluginDir, '', $file->getPathname()),
                    $e->getMessage()
                );
            }

            // 2. Dangerous function scan (regex, method-call-aware)
            foreach ($dangerousFunctions as $funcName => $pattern) {
                if (preg_match($pattern, $content) === 1) {
                    Logger::instance()->withChannel('plugins')->warning('Plugin activation was blocked because dangerous code was detected.', [
                        'plugin' => $plugin,
                        'function' => $funcName,
                        'file' => str_replace($pluginDir, '', $file->getPathname()),
                    ]);
                    return sprintf(
                        'Sicherheitswarnung: Plugin enthält potenziell gefährliche Funktion „%s" in %s. Aktivierung abgebrochen.',
                        $funcName,
                        str_replace($pluginDir, '', $file->getPathname())
                    );
                }
            }
        }

        return true;
    }

    /**
     * Deactivate plugin
     */
    public function deactivatePlugin(string $plugin): bool|string
    {
        $plugin = $this->canonicalPluginSlug($plugin);
        if (!in_array($plugin, $this->activePlugins)) {
            return 'Plugin ist nicht aktiviert.';
        }

        $this->activePlugins = array_diff($this->activePlugins, [$plugin]);
        $this->saveActivePlugins();
        $this->runLifecycleCallback($plugin, 'deactivate');
        Hooks::doAction('plugin_deactivated', $plugin);
        AuditLogger::instance()->pluginAction('deactivate', $plugin);

        return true;
    }

    /**
     * Delete plugin
     */
    public function deletePlugin(string $plugin): bool|string
    {
        $plugin = $this->canonicalPluginSlug($plugin);
        if ($this->isProtectedPlugin($plugin)) {
            return 'Das mitgelieferte Kern-Plugin „cms-importer“ kann nicht gelöscht werden.';
        }

        if (in_array($plugin, $this->activePlugins)) {
            return 'Plugin muss zuerst deaktiviert werden.';
        }

        $pluginDir  = $this->resolvePluginDirectory($plugin);
        if ($pluginDir === '' || !is_dir($pluginDir)) {
            return 'Plugin-Verzeichnis nicht gefunden.';
        }

        $pluginFile = $pluginDir . '/' . $plugin . '.php';
        if (file_exists($pluginFile)) {
            if (!in_array(realpath($pluginFile), get_included_files(), true)) {
                try {
                    include_once $pluginFile;
                } catch (\Throwable $e) {
                    Logger::instance()->withChannel('plugins')->warning('Plugin uninstall bootstrap could not be loaded.', [
                        'plugin' => $plugin,
                        'plugin_file' => $pluginFile,
                        'exception' => $e,
                    ]);
                }
            }
            $this->runLifecycleCallback($plugin, 'uninstall');
        }

        Hooks::doAction('plugin_before_delete', $plugin);

        if (!$this->deleteDirectory($pluginDir)) {
            return 'Plugin-Verzeichnis konnte nicht gelöscht werden.';
        }

        Hooks::doAction('plugin_deleted', $plugin);

        return true;
    }

    /**
     * Run plugin lifecycle callback (M-13)
     */
    private function runLifecycleCallback(string $plugin, string $lifecycle): void
    {
        $callbackName = str_replace(['-', ' '], '_', $plugin) . '_' . $lifecycle;

        if (!function_exists($callbackName)) {
            return;
        }

        try {
            $callbackName();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('plugins')->warning('Plugin lifecycle callback failed.', [
                'callback' => $callbackName,
                'plugin' => $plugin,
                'lifecycle' => $lifecycle,
                'exception' => $e,
            ]);
            if (class_exists(AuditLogger::class)) {
                AuditLogger::instance()->log(
                    'plugin', 'plugin.lifecycle_error',
                    sprintf('Lifecycle-Callback "%s" für Plugin "%s" fehlgeschlagen.', $lifecycle, $plugin),
                    'plugin', null,
                    ['plugin' => $plugin, 'lifecycle' => $lifecycle, 'error' => $e->getMessage()],
                    'warning'
                );
            }
        }
    }

    /**
     * Recursively delete directory
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }

    /**
     * Install plugin from uploaded ZIP file
     */
    public function installPlugin(array $file): bool|string
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return 'Ungültige Datei.';
        }

        $fileType = mime_content_type($file['tmp_name']);
        if (!in_array($fileType, ['application/zip', 'application/x-zip-compressed'])) {
            return 'Datei muss eine ZIP-Datei sein.';
        }

        if ($file['size'] > 50 * 1024 * 1024) {
            return 'Datei ist zu groß (max. 50MB).';
        }

        $zip = new \ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            return 'ZIP-Datei konnte nicht geöffnet werden.';
        }

        $realPluginPath = realpath(PLUGIN_PATH);
        if ($realPluginPath === false) {
            $zip->close();
            return 'Plugin-Verzeichnis nicht gefunden.';
        }

        $validation = $this->validateUploadedPluginArchive($zip);
        if (!$validation['success']) {
            $zip->close();
            return $validation['error'];
        }

        $pluginSlug = $validation['slug'];
        if ($this->isProtectedPlugin($pluginSlug)) {
            $zip->close();
            return 'Dieses Kern-Plugin kann nicht per Upload überschrieben werden.';
        }

        $targetDir = rtrim($realPluginPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $pluginSlug;
        if (file_exists($targetDir)) {
            $zip->close();
            return 'Plugin ist bereits installiert. Bitte zuerst deinstallieren oder den Update-Workflow verwenden.';
        }

        $stagingRoot = rtrim($realPluginPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.plugin-install-' . bin2hex(random_bytes(8));
        if (!mkdir($stagingRoot, 0750, true) && !is_dir($stagingRoot)) {
            $zip->close();
            return 'Temporäres Plugin-Verzeichnis konnte nicht erstellt werden.';
        }

        try {
            if (!$zip->extractTo($stagingRoot)) {
                $this->removeInstallDirectory($stagingRoot);
                return 'Plugin konnte nicht entpackt werden.';
            }
        } finally {
            $zip->close();
        }

        $stagedPluginDir = $stagingRoot . DIRECTORY_SEPARATOR . $pluginSlug;
        $realStagedPluginDir = realpath($stagedPluginDir);
        $normalizedStagingRoot = rtrim((string) realpath($stagingRoot), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if ($realStagedPluginDir === false || !str_starts_with($realStagedPluginDir . DIRECTORY_SEPARATOR, $normalizedStagingRoot)) {
            $this->removeInstallDirectory($stagingRoot);
            return 'Plugin-Archiv enthält keine sichere Plugin-Struktur.';
        }

        $bootstrapFile = $realStagedPluginDir . DIRECTORY_SEPARATOR . $pluginSlug . '.php';
        if (!is_file($bootstrapFile)) {
            $this->removeInstallDirectory($stagingRoot);
            return 'Plugin-Archiv enthält keine passende Hauptdatei.';
        }

        $scanResult = $this->securityScanPluginDirectory($pluginSlug, $realStagedPluginDir);
        if ($scanResult !== true) {
            $this->removeInstallDirectory($stagingRoot);
            return $scanResult;
        }

        if (!rename($realStagedPluginDir, $targetDir)) {
            $this->removeInstallDirectory($stagingRoot);
            return 'Plugin konnte nicht in das Zielverzeichnis verschoben werden.';
        }

        $this->removeInstallDirectory($stagingRoot);
        Hooks::doAction('plugin_installed');

        return true;
    }

    /** @return array{success: bool, slug: string, error: string} */
    private function validateUploadedPluginArchive(\ZipArchive $zip): array
    {
        $topLevelSlug = '';
        $hasBootstrap = false;
        $totalUncompressedSize = 0;

        if ($zip->numFiles <= 0 || $zip->numFiles > 2000) {
            return ['success' => false, 'slug' => '', 'error' => 'ZIP-Archiv enthält keine gültige Plugin-Struktur.'];
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if (!is_string($entryName) || $entryName === '') {
                return ['success' => false, 'slug' => '', 'error' => 'ZIP-Archiv enthält ungültige Einträge.'];
            }

            $normalized = str_replace('\\', '/', $entryName);
            $normalized = ltrim($normalized, '/');
            $parts = array_values(array_filter(explode('/', $normalized), static fn(string $part): bool => $part !== ''));

            if ($parts === []
                || $normalized !== $entryName
                || str_contains($normalized, '../')
                || in_array('..', $parts, true)
                || preg_match('~^[A-Za-z]:/~', $normalized) === 1
                || preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1
            ) {
                return ['success' => false, 'slug' => '', 'error' => 'ZIP-Archiv enthält unsichere Pfade.'];
            }

            $slug = strtolower((string) ($parts[0] ?? ''));
            if (!$this->isValidPluginSlug($slug)) {
                return ['success' => false, 'slug' => '', 'error' => 'ZIP-Archiv enthält keinen gültigen Plugin-Ordner.'];
            }

            if ($topLevelSlug === '') {
                $topLevelSlug = $slug;
            } elseif ($topLevelSlug !== $slug) {
                return ['success' => false, 'slug' => '', 'error' => 'ZIP-Archiv darf nur ein Plugin-Verzeichnis enthalten.'];
            }

            $stat = $zip->statIndex($i);
            if (is_array($stat)) {
                $size = max(0, (int) ($stat['size'] ?? 0));
                if ($size > 100 * 1024 * 1024) {
                    return ['success' => false, 'slug' => '', 'error' => 'ZIP-Archiv enthält zu große Einzeldateien.'];
                }
                $totalUncompressedSize += $size;
                if ($totalUncompressedSize > 200 * 1024 * 1024) {
                    return ['success' => false, 'slug' => '', 'error' => 'ZIP-Archiv ist entpackt zu groß.'];
                }
            }

            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && $opsys === \ZipArchive::OPSYS_UNIX) {
                $mode = ($attributes >> 16) & 0xF000;
                if ($mode === 0xA000) {
                    return ['success' => false, 'slug' => '', 'error' => 'ZIP-Archiv enthält symbolische Links.'];
                }
            }

            if (count($parts) === 2 && strtolower($parts[1]) === $slug . '.php') {
                $hasBootstrap = true;
            }
        }

        if ($topLevelSlug === '' || !$hasBootstrap) {
            return ['success' => false, 'slug' => '', 'error' => 'ZIP-Archiv enthält keine passende Plugin-Hauptdatei.'];
        }

        return ['success' => true, 'slug' => $topLevelSlug, 'error' => ''];
    }

    private function removeInstallDirectory(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }

        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);
            return;
        }

        $entries = scandir($dir);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeInstallDirectory($dir . DIRECTORY_SEPARATOR . $entry);
        }

        @rmdir($dir);
    }

    /**
     * Save active plugins to database
     */
    private function saveActivePlugins(): void
    {
        try {
            $db    = Database::instance();
            $value = json_encode(array_values($this->activePlugins));
            $stmt  = $db->prepare(
                "SELECT COUNT(*) as count FROM {$db->getPrefix()}settings WHERE option_name = 'active_plugins'"
            );
            if (!$stmt) {
                return;
            }
            $stmt->execute();
            $result = $stmt->fetch();

            if ($result && $result->count > 0) {
                $db->update('settings', ['option_value' => $value], ['option_name' => 'active_plugins']);
            } else {
                $db->insert('settings', ['option_name' => 'active_plugins', 'option_value' => $value]);
            }
        } catch (\Exception $e) {
            Logger::instance()->withChannel('plugins')->error('Active plugin list could not be saved.', [
                'exception' => $e,
            ]);
        }
    }

    private function isValidPluginSlug(string $plugin): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]*$/', strtolower(trim($plugin))) === 1;
    }

    private function resolvePluginDirectory(string $plugin): string
    {
        $plugin = $this->canonicalPluginSlug($plugin);
        if (!$this->isValidPluginSlug($plugin)) {
            return '';
        }

        $pluginRoot = realpath((string) PLUGIN_PATH);
        if ($pluginRoot === false || !is_dir($pluginRoot)) {
            return '';
        }

        $normalizedRoot = rtrim($pluginRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $pluginDir = $pluginRoot . DIRECTORY_SEPARATOR . $plugin;
        $candidates = [$pluginDir];

        if (!is_dir($pluginDir)) {
            $entries = scandir($pluginRoot);
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }

                    if (strtolower($entry) !== $plugin) {
                        continue;
                    }

                    $candidate = $pluginRoot . DIRECTORY_SEPARATOR . $entry;
                    if (is_dir($candidate)) {
                        $candidates[] = $candidate;
                        break;
                    }
                }
            }
        }

        foreach ($candidates as $candidateDir) {
            $realPluginDir = realpath($candidateDir);
            if ($realPluginDir === false || !is_dir($realPluginDir)) {
                continue;
            }

            if (!str_starts_with($realPluginDir . DIRECTORY_SEPARATOR, $normalizedRoot)) {
                continue;
            }

            return $realPluginDir;
        }

        return '';
    }

    private function resolvePluginBootstrapFile(string $plugin): string
    {
        $plugin = $this->canonicalPluginSlug($plugin);
        if (!$this->isValidPluginSlug($plugin)) {
            return '';
        }

        $pluginDir = $this->resolvePluginDirectory($plugin);
        if ($pluginDir === '') {
            return '';
        }

        $pluginFile = $pluginDir . DIRECTORY_SEPARATOR . $plugin . '.php';
        $pluginRoot = realpath((string) PLUGIN_PATH);
        if ($pluginRoot === false) {
            return '';
        }

        $normalizedRoot = rtrim($pluginRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        // 1) Exakter Treffer <slug>.php
        $realPluginFile = realpath($pluginFile);
        if ($realPluginFile !== false && is_file($realPluginFile) && str_starts_with($realPluginFile, $normalizedRoot)) {
            return $realPluginFile;
        }

        // 2) Case-insensitive Treffer für <slug>.php
        $phpFiles = glob($pluginDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        foreach ($phpFiles as $candidate) {
            $basename = strtolower((string) basename($candidate));
            if ($basename !== $plugin . '.php') {
                continue;
            }

            $realCandidate = realpath($candidate);
            if ($realCandidate !== false && is_file($realCandidate) && str_starts_with($realCandidate, $normalizedRoot)) {
                return $realCandidate;
            }
        }

        // 3) Header-basierte Erkennung (Plugin Name: ...)
        $headerCandidates = [];
        foreach ($phpFiles as $candidate) {
            if (!is_file($candidate) || !is_readable($candidate)) {
                continue;
            }

            $content = file_get_contents($candidate, false, null, 0, 4096);
            if (!is_string($content) || $content === '') {
                continue;
            }

            if (preg_match('/Plugin\s*Name\s*:/i', $content) === 1) {
                $headerCandidates[] = $candidate;
            }
        }

        if (count($headerCandidates) === 1) {
            $realCandidate = realpath($headerCandidates[0]);
            if ($realCandidate !== false && is_file($realCandidate) && str_starts_with($realCandidate, $normalizedRoot)) {
                return $realCandidate;
            }
        }

        foreach ($headerCandidates as $candidate) {
            $basename = strtolower((string) basename($candidate));
            if (!str_starts_with($basename, $plugin)) {
                continue;
            }

            $realCandidate = realpath($candidate);
            if ($realCandidate !== false && is_file($realCandidate) && str_starts_with($realCandidate, $normalizedRoot)) {
                return $realCandidate;
            }
        }

        // 4) Wenn nur eine PHP-Datei im Root liegt, diese als Bootstrap nutzen
        if (count($phpFiles) === 1) {
            $realCandidate = realpath($phpFiles[0]);
            if ($realCandidate !== false && is_file($realCandidate) && str_starts_with($realCandidate, $normalizedRoot)) {
                return $realCandidate;
            }
        }

        return $pluginFile;
    }

    /**
     * @return array<string, string>
     */
    private function getPluginBootstrapMap(): array
    {
        $pluginRoot = realpath((string) PLUGIN_PATH);
        if ($pluginRoot === false || !is_dir($pluginRoot)) {
            return [];
        }

        $map = [];
        $entries = scandir($pluginRoot);
        if (!is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            $slug = strtolower(trim((string) $entry));
            if ($slug === '.' || $slug === '..' || $slug === '.gitkeep' || !$this->isValidPluginSlug($slug)) {
                continue;
            }

            $bootstrap = $this->resolvePluginBootstrapFile($slug);
            if ($bootstrap !== '' && is_file($bootstrap)) {
                $map[$slug] = $bootstrap;
            }
        }

        return $map;
    }

    private function canonicalPluginSlug(string $plugin): string
    {
        $plugin = strtolower(trim($plugin));
        if ($plugin === '') {
            return '';
        }

        return self::PLUGIN_SLUG_ALIASES[$plugin] ?? $plugin;
    }
}