<?php
declare(strict_types=1);

namespace CMS\Install;

use PDO;
use PDOException;

if (!defined('ABSPATH')) {
    exit;
}

final class InstallerController
{
    private const ALLOWED_REDIRECTS = ['?step=1', '?step=2', '?step=3', '?step=4', '?step=5'];

    private array|bool $existingConfig;
    private bool $isReinstall;
    /** @var list<string> */
    private array $errors = [];

    public function __construct(private readonly InstallerService $service)
    {
        $this->existingConfig = $this->service->parseExistingConfig();
        $this->isReinstall = $this->existingConfig !== false;
    }

    public function handle(): void
    {
        $this->guardInstalledInstaller();

        $step = (string) ($_POST['step'] ?? $_GET['step'] ?? '1');

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$this->verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
            http_response_code(403);
            $this->render('blocked', [
                'blockedTitle' => 'Sicherheitscheck fehlgeschlagen',
                'blockedMessage' => 'Der Installer hat die Anfrage verworfen, weil der Formular-Token fehlt oder abgelaufen ist.',
                'blockedHint' => 'Bitte die Installationsseite neu laden und den Schritt erneut absenden.',
            ]);
        }

        match ($step) {
            '1' => $this->renderWelcome(),
            'update' => $this->handleUpdate(),
            '2' => $this->handleDatabaseStep(),
            '3' => $this->handleSiteStep(),
            '4' => $this->handleAdminStep(),
            '5' => $this->renderSuccess(),
            default => $this->redirect('?step=1'),
        };
    }

    private function guardInstalledInstaller(): void
    {
        if ($this->existingConfig === false) {
            return;
        }

        if (!$this->service->hasInstallerLock()) {
            $this->service->writeInstallerLockFile($this->existingConfig);
        }

        if (!isset($_SESSION['install_success']) && !$this->service->canAccessInstalledInstaller()) {
            http_response_code(403);
            $this->render('blocked', []);
        }
    }

    private function renderWelcome(): void
    {
        if (!defined('CMS_MIN_PHP_VERSION')) {
            define('CMS_MIN_PHP_VERSION', '8.4.1');
        }

        $this->render('welcome', [
            'autoUrl' => $this->service->autoDetectUrl(),
            'phpVersion' => PHP_VERSION,
            'requiredPhpVersion' => CMS_MIN_PHP_VERSION,
            'phpCompatible' => version_compare(PHP_VERSION, CMS_MIN_PHP_VERSION, '>='),
            'mysqlAvailable' => extension_loaded('pdo_mysql'),
            'writePermission' => is_writable(ABSPATH),
            'existingConfig' => $this->existingConfig,
            'isReinstall' => $this->isReinstall,
        ]);
    }

    private function handleUpdate(): void
    {
        if ($this->existingConfig === false) {
            $this->redirect('?step=1');
        }

        $updateErrors = [];
        $tableResults = [];
        $updateStatus = $this->service->getInstalledDatabaseUpdateStatus();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_update'])) {
            $newSiteName = trim((string) ($_POST['site_name'] ?? $this->existingConfig['site_name'] ?? ''));
            $newSiteUrl = $this->normalizeSiteUrl((string) ($_POST['site_url'] ?? $this->existingConfig['site_url'] ?? ''));
            $newAdminEmail = trim((string) ($_POST['admin_email'] ?? $this->existingConfig['admin_email'] ?? ''));
            $newDebugMode = isset($_POST['debug_mode']) ? 'true' : 'false';

            if ($newSiteName === '') {
                $updateErrors[] = 'Site-Name darf nicht leer sein.';
            }

            if ($newSiteUrl === '') {
                $updateErrors[] = 'Site-URL muss eine gültige http(s)-URL ohne abschließenden Slash sein.';
            }

            if (!filter_var($newAdminEmail, FILTER_VALIDATE_EMAIL)) {
                $updateErrors[] = 'Admin E-Mail ist ungültig.';
            }

            $configResult = $updateErrors === [] ? $this->service->updateConfigFile($this->existingConfig, [
                'site_name' => $newSiteName,
                'site_url' => $newSiteUrl,
                'admin_email' => $newAdminEmail,
                'debug_mode' => $newDebugMode,
            ]) : 'validation_failed';

            if ($configResult !== true && $configResult !== 'validation_failed') {
                $updateErrors[] = 'Config-Update fehlgeschlagen: ' . $configResult;
            } else {
                try {
                    if ($updateErrors !== []) {
                        throw new \RuntimeException('Validierung fehlgeschlagen');
                    }

                    $upgradeResult = $this->service->runInstalledDatabaseUpdate();
                    if (empty($upgradeResult['success'])) {
                        throw new \RuntimeException((string) ($upgradeResult['message'] ?? 'Datenbank-Update konnte nicht abgeschlossen werden.'));
                    }

                    $dsn = "mysql:host={$this->existingConfig['db_host']};dbname={$this->existingConfig['db_name']};charset=utf8mb4";
                    $pdo = new PDO($dsn, $this->existingConfig['db_user'], $this->existingConfig['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $tableResults = ['schema_update' => true];

                    $prefix = $this->existingConfig['db_prefix'] ?? 'cms_';
                    $settingStmt = $pdo->prepare(
                        "INSERT INTO {$prefix}settings (option_name, option_value, autoload)
                         VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)"
                    );
                    foreach ([
                        'site_name' => $newSiteName,
                        'admin_email' => $newAdminEmail,
                        'site_title' => $newSiteName,
                    ] as $key => $value) {
                        try {
                            $settingStmt->execute([$key, $value]);
                        } catch (PDOException) {
                        }
                    }

                    $newTables = ['schema_update'];
                    $this->service->writeInstallerLockFile($this->existingConfig + ['site_url' => $newSiteUrl]);
                    $_SESSION['install_success'] = [
                        'username' => '',
                        'site_url' => $newSiteUrl,
                        'is_update' => true,
                        'tables_created' => $newTables,
                    ];
                    $this->redirect('?step=5');
                } catch (\RuntimeException $e) {
                    $updateErrors[] = $e->getMessage();
                } catch (PDOException $e) {
                    $updateErrors[] = 'Datenbankfehler: ' . $e->getMessage();
                }
            }
        }

        $this->render('update', [
            'updateErrors' => $updateErrors,
            'tableResults' => $tableResults,
            'existingConfig' => $this->existingConfig,
            'fSiteName' => (string) ($_POST['site_name'] ?? $this->existingConfig['site_name'] ?? 'IT Expert Network'),
            'fSiteUrl' => (string) ($_POST['site_url'] ?? $this->existingConfig['site_url'] ?? $this->service->autoDetectUrl()),
            'fAdminEmail' => (string) ($_POST['admin_email'] ?? $this->existingConfig['admin_email'] ?? ''),
            'fDebugMode' => (($this->existingConfig['debug_mode'] ?? 'false') === 'true'),
            'updateStatus' => $updateStatus,
        ]);
    }

    private function handleDatabaseStep(): void
    {
        $isReinstall = ((isset($_POST['reinstall']) && $_POST['reinstall'] === '1') || (!empty($_SESSION['is_reinstall'])));

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_db'])) {
            $dbHost = trim((string) ($_POST['db_host'] ?? ''));
            $dbName = trim((string) ($_POST['db_name'] ?? ''));
            $dbUser = trim((string) ($_POST['db_user'] ?? ''));
            $dbPass = (string) ($_POST['db_pass'] ?? '');
            $reinstallFlag = isset($_POST['reinstall_flag']) && $_POST['reinstall_flag'] === '1';
            $testResult = $this->service->testDatabaseConnection($dbHost, $dbName, $dbUser, $dbPass);
            $dbPrefix = trim((string) ($_POST['db_prefix'] ?? 'cms_'));

            if (!preg_match('/^[a-z][a-z0-9_]*_$/i', $dbPrefix)) {
                $this->errors[] = 'Tabellen-Präfix darf nur Buchstaben, Zahlen und _ enthalten und muss mit _ enden (z.B. cms_)';
            } elseif (strlen($dbPrefix) > 20) {
                $this->errors[] = 'Tabellen-Präfix darf maximal 20 Zeichen lang sein';
            } else {
                $dbPrefix = strtolower($dbPrefix);
            }

            if ($testResult === true && $this->errors === []) {
                $_SESSION['db_config'] = [
                    'db_host' => $dbHost,
                    'db_name' => $dbName,
                    'db_user' => $dbUser,
                    'db_pass' => $dbPass,
                    'db_prefix' => $dbPrefix,
                ];
                $_SESSION['is_reinstall'] = $reinstallFlag;
                $this->redirect('?step=3');
            } elseif ($testResult !== true) {
                $this->errors[] = (string) $testResult;
            }
        }

        $defaultValues = $_SESSION['db_config'] ?? ($this->existingConfig !== false ? [
            'db_host' => $this->existingConfig['db_host'] ?? 'localhost',
            'db_name' => $this->existingConfig['db_name'] ?? '',
            'db_user' => $this->existingConfig['db_user'] ?? '',
            'db_pass' => '',
            'db_prefix' => $this->existingConfig['db_prefix'] ?? 'cms_',
        ] : [
            'db_host' => 'localhost',
            'db_name' => '',
            'db_user' => '',
            'db_pass' => '',
            'db_prefix' => 'cms_',
        ]);

        $this->render('database', [
            'errors' => $this->errors,
            'isReinstall' => $isReinstall,
            'existingConfig' => $this->existingConfig,
            'defaultValues' => $defaultValues,
        ]);
    }

    private function handleSiteStep(): void
    {
        if (!isset($_SESSION['db_config'])) {
            $this->redirect('?step=2');
        }

        $defaultCoreModules = $_SESSION['site_config']['core_modules'] ?? $this->service->getDefaultInstallableCoreModuleStates();
        $availableCoreModules = $this->service->getInstallableCoreModules();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['site_config'])) {
            $siteName = trim((string) ($_POST['site_name'] ?? ''));
            $siteUrl = $this->normalizeSiteUrl((string) ($_POST['site_url'] ?? ''));
            $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));

            if ($siteName === '') {
                $this->errors[] = 'Website-Name darf nicht leer sein.';
            }

            if ($siteUrl === '') {
                $this->errors[] = 'Website-URL muss eine gültige http(s)-URL ohne abschließenden Slash sein.';
            }

            if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $this->errors[] = 'Administrator E-Mail ist ungültig.';
            }

            if ($this->errors === []) {
                $_SESSION['site_config'] = [
                    'site_name' => $siteName,
                    'site_url' => $siteUrl,
                    'admin_email' => $adminEmail,
                    'debug_mode' => isset($_POST['debug_mode']) ? 'true' : 'false',
                    'core_modules' => $this->service->normalizeInstallableCoreModuleStates($_POST['core_modules'] ?? []),
                ];
                $this->redirect('?step=4');
            }
        }

        $isReinstall = !empty($_SESSION['is_reinstall']);
        $defaultValues = $_SESSION['site_config'] ?? [
            'site_name' => ($isReinstall && $this->existingConfig !== false) ? ($this->existingConfig['site_name'] ?? 'IT Expert Network') : 'IT Expert Network',
            'site_url' => $this->service->autoDetectUrl(),
            'admin_email' => ($isReinstall && $this->existingConfig !== false) ? ($this->existingConfig['admin_email'] ?? '') : '',
            'debug_mode' => false,
            'core_modules' => $defaultCoreModules,
        ];

        $this->render('site', [
            'defaultValues' => $defaultValues,
            'dbCleaned' => $_SESSION['db_cleaned'] ?? null,
            'availableCoreModules' => $availableCoreModules,
        ]);
    }

    private function handleAdminStep(): void
    {
        if (!isset($_SESSION['db_config'], $_SESSION['site_config'])) {
            $this->redirect('?step=2');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install'])) {
            $adminUsername = trim((string) ($_POST['admin_username'] ?? ''));
            $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
            $adminPassword = (string) ($_POST['admin_password'] ?? '');
            $adminPasswordConfirm = (string) ($_POST['admin_password_confirm'] ?? '');

            if ($adminUsername === '' || $adminEmail === '' || $adminPassword === '') {
                $this->errors[] = 'Alle Felder sind erforderlich';
            } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $adminUsername)) {
                $this->errors[] = 'Benutzername darf nur Buchstaben, Zahlen, Punkt, Unterstrich und Bindestrich enthalten und muss 3–60 Zeichen lang sein';
            } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $this->errors[] = 'E-Mail-Adresse ist ungültig';
            } elseif ($adminPassword !== $adminPasswordConfirm) {
                $this->errors[] = 'Passwörter stimmen nicht überein';
            } elseif (mb_strlen($adminPassword) < 12) {
                $this->errors[] = 'Passwort muss mindestens 12 Zeichen lang sein';
            } else {
                $dbConfig = $_SESSION['db_config'];
                $siteConfig = $_SESSION['site_config'];
                $prefix = $dbConfig['db_prefix'] ?? 'cms_';

                $configResult = $this->service->createConfigFile([
                    'created_at' => date('Y-m-d H:i:s'),
                    'debug_mode' => $siteConfig['debug_mode'],
                    'db_host' => $dbConfig['db_host'],
                    'db_name' => $dbConfig['db_name'],
                    'db_user' => $dbConfig['db_user'],
                    'db_pass' => $dbConfig['db_pass'],
                    'db_prefix' => $prefix,
                    'auth_key' => $this->service->generateSecurityKey(),
                    'secure_auth_key' => $this->service->generateSecurityKey(),
                    'nonce_key' => $this->service->generateSecurityKey(),
                    'site_name' => $siteConfig['site_name'],
                    'site_url' => $siteConfig['site_url'],
                    'admin_email' => $siteConfig['admin_email'],
                ]);

                if ($configResult !== true) {
                    $this->errors[] = (string) $configResult;
                } else {
                    try {
                        $dsn = "mysql:host={$dbConfig['db_host']};dbname={$dbConfig['db_name']};charset=utf8mb4";
                        $pdo = new PDO($dsn, $dbConfig['db_user'], $dbConfig['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                        $_SESSION['db_cleaned'] = $this->service->cleanDatabase($pdo, $prefix);
                        $this->service->createDatabaseTables($pdo, $prefix);
                        $adminResult = $this->service->createAdminUser($pdo, $adminUsername, $adminEmail, $adminPassword, $prefix);
                        $this->service->createDefaultSettings(
                            $pdo,
                            $siteConfig['site_name'],
                            $siteConfig['admin_email'],
                            $prefix,
                            is_array($siteConfig['core_modules'] ?? null) ? $siteConfig['core_modules'] : []
                        );
                        $this->service->initializeLandingPageData($pdo, $prefix);

                        if ($adminResult === true) {
                            $this->service->clearSchemaManagerFlagFile();
                            $this->service->writeInstallerLockFile(['site_url' => $siteConfig['site_url']]);
                            $_SESSION['install_success'] = [
                                'username' => $adminUsername,
                                'site_url' => $siteConfig['site_url'],
                            ];
                            unset($_SESSION['is_reinstall']);
                            $this->redirect('?step=5');
                        }

                        $this->errors[] = (string) $adminResult;
                    } catch (PDOException $e) {
                        $this->errors[] = 'Datenbankfehler: ' . $e->getMessage();
                    }
                }
            }
        }

        $this->render('admin', [
            'errors' => $this->errors,
            'defaultEmail' => (string) ($_SESSION['site_config']['admin_email'] ?? ''),
        ]);
    }

    private function renderSuccess(): void
    {
        if (!isset($_SESSION['install_success'])) {
            $this->redirect('?step=1');
        }

        $success = $_SESSION['install_success'];
        $this->expireInstallerSessionCookies((string) ($success['site_url'] ?? ''));
        $_SESSION = [];
        session_destroy();

        $this->render('success', ['success' => $success]);
    }

    private function expireInstallerSessionCookies(string $siteUrl): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || in_array(strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')), ['https', 'wss'], true)
            || in_array(strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')), ['on', '1', 'true'], true)
            || in_array(strtolower((string) ($_SERVER['HTTP_FRONT_END_HTTPS'] ?? '')), ['on', '1'], true);

        $baseOptions = [
            'expires' => time() - 42000,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ];

        setcookie(session_name(), '', $baseOptions);
        setcookie('cms_device', '', $baseOptions);

        foreach ($this->resolveSessionCookieCleanupDomains($siteUrl) as $domain) {
            $domainOptions = $baseOptions;
            $domainOptions['domain'] = $domain;
            setcookie(session_name(), '', $domainOptions);
            setcookie('cms_device', '', $domainOptions);
        }

        unset($_COOKIE[session_name()], $_COOKIE['cms_device']);
    }

    /**
     * @return list<string>
     */
    private function resolveSessionCookieCleanupDomains(string $siteUrl): array
    {
        $hosts = [
            (string) ($_SERVER['HTTP_HOST'] ?? ''),
            (string) (parse_url($siteUrl, PHP_URL_HOST) ?? ''),
        ];
        $domains = [];

        foreach ($hosts as $host) {
            $host = strtolower(trim($host));
            if ($host === '') {
                continue;
            }

            if (str_contains($host, ':')) {
                $host = explode(':', $host, 2)[0];
            }

            $host = preg_replace('/^www\./i', '', $host) ?? '';
            if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
                continue;
            }

            $domains[] = $host;
        }

        return array_values(array_unique($domains));
    }

    private function render(string $view, array $data): never
    {
        $viewPath = ABSPATH . 'install/views/' . $view . '.php';
        if (!is_file($viewPath)) {
            throw new \RuntimeException('Installer-View nicht gefunden: ' . $view);
        }

        $data['csrfToken'] = $this->getCsrfToken();
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        extract($data, EXTR_SKIP);
        require $viewPath;
        exit;
    }

    private function redirect(string $location): never
    {
        if (!in_array($location, self::ALLOWED_REDIRECTS, true)) {
            $location = '?step=1';
        }

        header('Location: ' . $location);
        exit;
    }

    private function getCsrfToken(): string
    {
        if (empty($_SESSION['installer_csrf_token']) || !is_string($_SESSION['installer_csrf_token'])) {
            $_SESSION['installer_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['installer_csrf_token'];
    }

    private function verifyCsrfToken(string $token): bool
    {
        return isset($_SESSION['installer_csrf_token'])
            && is_string($_SESSION['installer_csrf_token'])
            && hash_equals($_SESSION['installer_csrf_token'], $token);
    }

    private function normalizeSiteUrl(string $url): string
    {
        $normalized = rtrim(trim($url), '/');
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        $scheme = strtolower((string) parse_url($normalized, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        return $normalized;
    }
}
