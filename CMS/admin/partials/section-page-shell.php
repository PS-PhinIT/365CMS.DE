<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Logger;
use CMS\Security;

function cms_admin_section_shell_normalize_alert_type(mixed $type, string $fallback = 'danger'): string
{
    $allowedTypes = ['success', 'danger', 'error', 'warning', 'info', 'secondary'];
    $normalizedFallback = strtolower(trim($fallback));
    if (!in_array($normalizedFallback, $allowedTypes, true)) {
        $normalizedFallback = 'danger';
    }

    $alertType = strtolower(trim((string) $type));

    return in_array($alertType, $allowedTypes, true) ? $alertType : $normalizedFallback;
}

function cms_admin_section_shell_flash(string $sessionKey, array $payload): void
{
    $_SESSION[$sessionKey] = [
        'type' => cms_admin_section_shell_normalize_alert_type($payload['type'] ?? 'danger'),
        'message' => trim((string) ($payload['message'] ?? '')),
        'details' => is_array($payload['details'] ?? null) ? $payload['details'] : [],
        'error_details' => is_array($payload['error_details'] ?? null) ? $payload['error_details'] : [],
        'report_payload' => is_array($payload['report_payload'] ?? null) ? $payload['report_payload'] : [],
    ];
}

function cms_admin_section_shell_pull_flash(string $sessionKey): ?array
{
    $alert = $_SESSION[$sessionKey] ?? null;
    unset($_SESSION[$sessionKey]);

    return is_array($alert) ? $alert : null;
}

function cms_admin_section_shell_normalize_route_path(mixed $routePath): string
{
    $path = trim((string) $routePath);
    if ($path === '') {
        return '/admin';
    }

    $parsedPath = (string) parse_url($path, PHP_URL_PATH);
    if ($parsedPath !== '') {
        $path = $parsedPath;
    }

    $path = '/' . ltrim($path, '/');

    return $path === '//' ? '/admin' : $path;
}

function cms_admin_section_shell_resolve_redirect_target(mixed $resolver, string $defaultRoutePath, mixed $module = null, string $section = '', mixed $result = null): string
{
    $resolvedTarget = is_callable($resolver)
        ? trim((string) $resolver($module, $section, $result))
        : '';

    if ($resolvedTarget === '') {
        return $defaultRoutePath;
    }

    $query = (string) parse_url($resolvedTarget, PHP_URL_QUERY);
    $path = cms_admin_section_shell_normalize_route_path($resolvedTarget);

    return $query !== '' ? $path . '?' . $query : $path;
}

function cms_admin_section_shell_require_view_file(mixed $viewFile): string
{
    $resolvedViewFile = (string) $viewFile;
    if ($resolvedViewFile === '' || !is_file($resolvedViewFile)) {
        throw new RuntimeException('Admin-Section-Shell erwartet eine gültige vorhandene view_file-Konfiguration.');
    }

    return $resolvedViewFile;
}

function cms_admin_section_shell_redirect(string $routePath, int $statusCode = 302): never
{
    header('Location: ' . $routePath, true, $statusCode);
    exit;
}

function cms_admin_section_shell_mark_csrf_verified(string $csrfAction): void
{
    if (!isset($GLOBALS['cms_admin_verified_csrf_actions']) || !is_array($GLOBALS['cms_admin_verified_csrf_actions'])) {
        $GLOBALS['cms_admin_verified_csrf_actions'] = [];
    }

    $GLOBALS['cms_admin_verified_csrf_actions'][$csrfAction] = true;
}

function cms_admin_section_shell_was_csrf_verified(string $csrfAction): bool
{
    return !empty($GLOBALS['cms_admin_verified_csrf_actions'][$csrfAction]);
}

function cms_admin_section_shell_normalize_page_assets(mixed $pageAssets): array
{
    $resolvedPageAssets = is_array($pageAssets) ? $pageAssets : [];
    $cssAssets = is_array($resolvedPageAssets['css'] ?? null) ? array_values($resolvedPageAssets['css']) : [];
    $jsAssets = is_array($resolvedPageAssets['js'] ?? null) ? array_values($resolvedPageAssets['js']) : [];

    return [
        'css' => array_values(array_filter($cssAssets, static fn (mixed $asset): bool => is_string($asset) && trim($asset) !== '')),
        'js' => array_values(array_filter($jsAssets, static fn (mixed $asset): bool => is_string($asset) && trim($asset) !== '')),
    ];
}

function cms_admin_section_shell_normalize_template_vars(mixed $templateVars): array
{
    if (!is_array($templateVars)) {
        return [];
    }

    $normalized = [];
    foreach ($templateVars as $key => $value) {
        $variableName = trim((string) $key);
        if ($variableName === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $variableName) !== 1) {
            continue;
        }

        $normalized[$variableName] = $value;
    }

    return $normalized;
}

/**
 * @return list<string>
 */
function cms_admin_section_shell_normalize_csrf_actions(mixed $actions, string $fallbackAction): array
{
    $normalized = [];
    $fallbackAction = trim($fallbackAction) !== '' ? trim($fallbackAction) : 'admin_section';
    $candidates = is_array($actions) ? $actions : [$actions];

    foreach ($candidates as $action) {
        $actionName = trim((string) $action);
        if ($actionName === '' || in_array($actionName, $normalized, true)) {
            continue;
        }

        $normalized[] = $actionName;
    }

    if ($normalized === []) {
        $normalized[] = $fallbackAction;
    }

    if (!in_array($fallbackAction, $normalized, true)) {
        array_unshift($normalized, $fallbackAction);
    }

    return $normalized;
}

function cms_admin_section_shell_verify_csrf_token(mixed $token, array $csrfActions, bool $persistentValidation): ?string
{
    $token = is_string($token) ? $token : '';
    if ($token === '') {
        return null;
    }

    $security = Security::instance();
    foreach ($csrfActions as $csrfAction) {
        $isValid = $persistentValidation
            ? $security->verifyPersistentToken($token, $csrfAction)
            : $security->verifyToken($token, $csrfAction);

        if ($isValid) {
            return $csrfAction;
        }
    }

    return null;
}

function cms_admin_section_shell_apply_runtime_context(array $runtimeContext, string &$section, string &$viewFile, string &$pageTitle, string &$activePage, array &$pageAssets, array &$templateVars, mixed &$resolvedData): void
{
    $section = (string) ($runtimeContext['section'] ?? $section);
    $viewFile = cms_admin_section_shell_require_view_file($runtimeContext['view_file'] ?? $viewFile);
    $pageTitle = (string) ($runtimeContext['page_title'] ?? $pageTitle);
    $activePage = (string) ($runtimeContext['active_page'] ?? $activePage);
    $pageAssets = cms_admin_section_shell_normalize_page_assets($runtimeContext['page_assets'] ?? $pageAssets);
    $templateVars = array_merge($templateVars, cms_admin_section_shell_normalize_template_vars($runtimeContext['template_vars'] ?? []));

    if (array_key_exists('data', $runtimeContext)) {
        $resolvedData = $runtimeContext['data'];
    }
}

function cms_admin_section_shell_has_valid_module_container(mixed $module): bool
{
    return is_object($module) || is_array($module);
}

function cms_admin_section_shell_render_bootstrap_failure(string $pageTitle, string $activePage, array $pageAssets, string $message): never
{
    if (!headers_sent()) {
        http_response_code(500);
    }

    $alert = [
        'type' => 'danger',
        'message' => $message,
        'details' => [],
        'error_details' => [],
        'report_payload' => [],
    ];
    $data = [];
    $csrfToken = '';

    require __DIR__ . '/header.php';
    require __DIR__ . '/sidebar.php';

    echo '<div class="page-body"><div class="container-xl">';
    echo '<div class="alert alert-danger" role="alert">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '</div></div>';

    require __DIR__ . '/footer.php';
    exit;
}

function cms_admin_section_shell_sanitize_error_detail(string $value, int $maxLength = 260): string
{
    $value = preg_replace('/([?&](?:token|csrf_token|nonce|key|secret|password|pass)=)[^&\s]+/i', '$1***', $value) ?? $value;
    $value = preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/-]+=*/i', '$1***', $value) ?? $value;
    $value = preg_replace('/(password|passwd|secret|token|credential|api[_-]?key)(\s*[=:]\s*)\S+/i', '$1$2***', $value) ?? $value;
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

    return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
}

function cms_admin_section_shell_render_view_failure(\Throwable $exception, string $routePath, string $viewFile, string $pageTitle): void
{
    Logger::instance()->withChannel('admin.section_shell')->error('Admin-Section-View konnte nicht gerendert werden.', [
        'route_path' => $routePath,
        'view_file' => $viewFile,
        'page_title' => $pageTitle,
        'exception_class' => get_debug_type($exception),
        'exception' => $exception->getMessage(),
    ]);

    $message = 'Die Admin-Seite konnte nicht vollständig geladen werden. Bitte Logs prüfen und Cache/OPcache leeren.';
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeRoute = htmlspecialchars($routePath, ENT_QUOTES, 'UTF-8');
    $safeView = htmlspecialchars(basename($viewFile), ENT_QUOTES, 'UTF-8');
    $safeException = htmlspecialchars(
        cms_admin_section_shell_sanitize_error_detail(get_debug_type($exception) . ': ' . $exception->getMessage()),
        ENT_QUOTES,
        'UTF-8'
    );
    $safeLocation = htmlspecialchars(
        basename($exception->getFile()) . ':' . (int) $exception->getLine(),
        ENT_QUOTES,
        'UTF-8'
    );

    echo '<div class="page-body"><div class="container-xl">';
    echo '<div class="alert alert-danger" role="alert">';
    echo '<div class="d-flex"><div>';
    echo '<h4 class="alert-title">Admin-Ansicht konnte nicht geladen werden</h4>';
    echo '<div class="text-secondary">' . $safeMessage . '</div>';
    echo '<div class="small text-muted mt-2">Route: <code>' . $safeRoute . '</code> · View: <code>' . $safeView . '</code></div>';
    echo '<div class="small text-muted mt-1">Fehler: <code>' . $safeException . '</code> · Quelle: <code>' . $safeLocation . '</code></div>';
    echo '</div></div>';
    echo '</div>';
    echo '</div></div>';
}

$sectionPageConfig = is_array($sectionPageConfig ?? null) ? $sectionPageConfig : [];
$routePath = cms_admin_section_shell_normalize_route_path($sectionPageConfig['route_path'] ?? '/admin');
$viewFile = cms_admin_section_shell_require_view_file($sectionPageConfig['view_file'] ?? '');
$pageTitle = (string)($sectionPageConfig['page_title'] ?? 'Admin');
$activePage = (string)($sectionPageConfig['active_page'] ?? 'dashboard');
$pageAssets = cms_admin_section_shell_normalize_page_assets($sectionPageConfig['page_assets'] ?? []);
$section = (string)($sectionPageConfig['section'] ?? 'overview');
$csrfAction = trim((string)($sectionPageConfig['csrf_action'] ?? 'admin_section'));
$csrfAction = $csrfAction !== '' ? $csrfAction : 'admin_section';
$csrfActions = cms_admin_section_shell_normalize_csrf_actions($sectionPageConfig['csrf_actions'] ?? [$csrfAction], $csrfAction);
$csrfPersistentValidation = !empty($sectionPageConfig['csrf_persistent_validation']);
$guardConstant = (string)($sectionPageConfig['guard_constant'] ?? '');
$moduleFile = (string)($sectionPageConfig['module_file'] ?? '');
$moduleFactory = $sectionPageConfig['module_factory'] ?? null;
$postHandler = $sectionPageConfig['post_handler'] ?? null;
$dataLoader = $sectionPageConfig['data_loader'] ?? null;
$accessChecker = $sectionPageConfig['access_checker'] ?? null;
$redirectPathResolver = $sectionPageConfig['redirect_path_resolver'] ?? null;
$requestContextResolver = $sectionPageConfig['request_context_resolver'] ?? null;
$accessDeniedRoute = cms_admin_section_shell_normalize_route_path($sectionPageConfig['access_denied_route'] ?? '/');
$alertSessionKey = (string)($sectionPageConfig['alert_session_key'] ?? 'admin_alert');
$invalidTokenMessage = (string)($sectionPageConfig['invalid_token_message'] ?? 'Sicherheitstoken ungültig.');
$unknownActionMessage = (string)($sectionPageConfig['unknown_action_message'] ?? 'Unbekannte Antwort.');
$templateVars = cms_admin_section_shell_normalize_template_vars($sectionPageConfig['template_vars'] ?? []);

$canAccess = is_callable($accessChecker)
    ? (bool) $accessChecker($sectionPageConfig, $section)
    : Auth::instance()->isAdmin();

if (!$canAccess) {
    cms_admin_section_shell_redirect($accessDeniedRoute);
}

if ($moduleFile !== '') {
    require_once $moduleFile;
}

if (!is_callable($moduleFactory)) {
    throw new RuntimeException('Admin-Section-Shell erwartet eine callable module_factory-Konfiguration.');
}

try {
    $module = $moduleFactory();
} catch (\Throwable $e) {
    Logger::instance()->withChannel('admin.section_shell')->error('Admin-Sektion konnte nicht initialisiert werden.', [
        'route_path' => $routePath,
        'module_file' => $moduleFile,
        'page_title' => $pageTitle,
        'exception' => $e->getMessage(),
    ]);

    $bootstrapFailureMessage = 'Die Admin-Sektion konnte nicht geladen werden. Bitte Deployment, Dateistand und Cache/OPcache prüfen.';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        cms_admin_section_shell_flash($alertSessionKey, ['type' => 'danger', 'message' => $bootstrapFailureMessage]);
        cms_admin_section_shell_redirect($routePath, 303);
    }

    cms_admin_section_shell_render_bootstrap_failure($pageTitle, $activePage, $pageAssets, $bootstrapFailureMessage);
}

if (!cms_admin_section_shell_has_valid_module_container($module)) {
    Logger::instance()->withChannel('admin.section_shell')->error('Admin-Sektion lieferte kein gültiges Modul-Container-Ergebnis.', [
        'route_path' => $routePath,
        'module_file' => $moduleFile,
        'page_title' => $pageTitle,
        'module_type' => get_debug_type($module),
    ]);

    cms_admin_section_shell_render_bootstrap_failure(
        $pageTitle,
        $activePage,
        $pageAssets,
        'Die Admin-Sektion konnte nicht korrekt initialisiert werden. Bitte Deployment und Cache/OPcache prüfen.'
    );
}
$runtimeContext = is_callable($requestContextResolver)
    ? $requestContextResolver($module, $section, $sectionPageConfig)
    : [];
$runtimeContext = is_array($runtimeContext) ? $runtimeContext : [];
$resolvedData = array_key_exists('data', $runtimeContext) ? $runtimeContext['data'] : null;
cms_admin_section_shell_apply_runtime_context($runtimeContext, $section, $viewFile, $pageTitle, $activePage, $pageAssets, $templateVars, $resolvedData);

$redirectTarget = cms_admin_section_shell_resolve_redirect_target($redirectPathResolver, $routePath, $module, $section);
$alert = cms_admin_section_shell_pull_flash($alertSessionKey);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postToken = $_POST['csrf_token'] ?? '';
    $verifiedCsrfAction = cms_admin_section_shell_verify_csrf_token($postToken, $csrfActions, $csrfPersistentValidation);
    if ($verifiedCsrfAction === null) {
        cms_admin_section_shell_flash($alertSessionKey, ['type' => 'danger', 'message' => $invalidTokenMessage]);
        cms_admin_section_shell_redirect($redirectTarget, 303);
    }

    cms_admin_section_shell_mark_csrf_verified($verifiedCsrfAction);

    try {
        $result = is_callable($postHandler)
            ? $postHandler($module, $section, $_POST)
            : ['success' => false, 'error' => $unknownActionMessage];
    } catch (\Throwable $e) {
        // Unerwartete Fehler einer Admin-Aktion nicht als HTTP 500 enden lassen.
        Logger::instance()->withChannel('admin.section_shell')->error('Admin-Aktion konnte nicht ausgeführt werden.', [
            'route_path' => $routePath,
            'page_title' => $pageTitle,
            'exception_class' => get_debug_type($e),
            'exception' => $e->getMessage(),
            'location' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);
        $result = ['success' => false, 'error' => 'Die Aktion konnte nicht ausgeführt werden. Bitte Logs prüfen.'];
    }

    $redirectTarget = cms_admin_section_shell_resolve_redirect_target($redirectPathResolver, $routePath, $module, $section, $result);

    if (!empty($result['render_inline'])) {
        $alert = [
            'type' => cms_admin_section_shell_normalize_alert_type(
                $result['type'] ?? (!empty($result['success']) ? 'success' : 'danger'),
                !empty($result['success']) ? 'success' : 'danger'
            ),
            'message' => (string) ($result['message'] ?? $result['error'] ?? $unknownActionMessage),
            'details' => is_array($result['details'] ?? null) ? $result['details'] : [],
            'error_details' => is_array($result['error_details'] ?? null) ? $result['error_details'] : [],
            'report_payload' => is_array($result['report_payload'] ?? null) ? $result['report_payload'] : [],
        ];

        $inlineRuntimeContext = is_array($result['runtime_context'] ?? null) ? $result['runtime_context'] : [];
        if ($inlineRuntimeContext !== []) {
            cms_admin_section_shell_apply_runtime_context($inlineRuntimeContext, $section, $viewFile, $pageTitle, $activePage, $pageAssets, $templateVars, $resolvedData);
        }
    } else {
        cms_admin_section_shell_flash($alertSessionKey, [
            'type' => cms_admin_section_shell_normalize_alert_type(
                $result['type'] ?? (!empty($result['success']) ? 'success' : 'danger'),
                !empty($result['success']) ? 'success' : 'danger'
            ),
            'message' => $result['message'] ?? $result['error'] ?? $unknownActionMessage,
            'details' => $result['details'] ?? [],
            'error_details' => $result['error_details'] ?? [],
            'report_payload' => $result['report_payload'] ?? [],
        ]);

        cms_admin_section_shell_redirect($redirectTarget, 303);
    }
}

$csrfToken = Security::instance()->generateToken($csrfAction);
$data = $resolvedData;
if ($data === null) {
    $data = is_callable($dataLoader)
        ? $dataLoader($module)
        : (method_exists($module, 'getData') ? $module->getData() : []);
}

if ($guardConstant !== '' && !defined($guardConstant)) {
    define($guardConstant, true);
}

if ($templateVars !== []) {
    extract($templateVars, EXTR_SKIP);
}

require __DIR__ . '/header.php';
require __DIR__ . '/sidebar.php';
try {
    require $viewFile;
} catch (\Throwable $exception) {
    cms_admin_section_shell_render_view_failure($exception, $routePath, $viewFile, $pageTitle);
}
try {
    require __DIR__ . '/footer.php';
} catch (\Throwable $exception) {
    Logger::instance()->withChannel('admin.section_shell')->error('Admin-Section-Footer konnte nicht gerendert werden.', [
        'route_path' => $routePath,
        'view_file' => $viewFile,
        'page_title' => $pageTitle,
        'exception_class' => get_debug_type($exception),
        'exception' => $exception->getMessage(),
    ]);

    echo '</div><!-- /.page-wrapper -->';
    echo '</div><!-- /.page -->';
    echo '</body></html>';
}
