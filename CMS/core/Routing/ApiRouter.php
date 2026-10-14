<?php
/**
 * API Router Module
 *
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS\Routing;

use CMS\Api;
use CMS\Auth;
use CMS\Database;
use CMS\Router;
use CMS\Security;
use CMS\Services;
use CMS\Version;

if (!defined('ABSPATH')) {
    exit;
}

final class ApiRouter
{
    private const API_SEARCH_MAX_LENGTH = 120;
    private const WEB_VITALS_MAX_BODY_BYTES = 8192;
    private const WEB_VITALS_RATE_CACHE_MAX_BYTES = 262144;
    private const WEB_VITALS_RATE_LIMIT_WINDOW_SECONDS = 60;
    private const WEB_VITALS_RATE_LIMIT_MAX_REQUESTS = 40;
    private const WEB_VITALS_RATE_LIMIT_IP_FACTOR = 3;
    private const WEB_VITALS_ALLOWED_EFFECTIVE_TYPES = ['slow-2g', '2g', '3g', '4g', '5g', 'unknown', ''];
    private const WEB_VITALS_ALLOWED_NAVIGATION_TYPES = ['navigate', 'reload', 'back_forward', 'prerender', 'unknown', ''];

    public function __construct(private readonly Router $router)
    {
    }

    public function registerRoutes(): void
    {
        $this->router->addRoute('GET', '/api/v1/status', [$this, 'status']);
        $this->router->addRoute('GET', '/api/v1/pages', [$this, 'pages']);
        $this->router->addRoute('GET', '/api/v1/pages/:slug', [$this, 'pageBySlug']);
        $this->router->addRoute('POST', '/api/v1/analytics/web-vitals', [$this, 'captureWebVitals']);

        $this->router->addRoute('GET', '/api/v1/admin/posts', [$this, 'jsonAdminPosts']);
        $this->router->addRoute('GET', '/api/v1/admin/pages', [$this, 'jsonAdminPages']);
        $this->router->addRoute('GET', '/api/v1/admin/users', [$this, 'jsonAdminUsers']);
        $this->router->addRoute('GET', '/api/v1/admin/mail/logs', [$this, 'jsonAdminMailLogs']);
        $this->router->addRoute('POST', '/api/v1/admin/mail/test', [$this, 'testAdminMail']);
        $this->router->addRoute('POST', '/api/v1/admin/graph/test', [$this, 'testAdminGraph']);
        $this->router->addRoute('POST', '/api/upload', [$this, 'upload']);
        $this->router->addRoute('GET', '/api/media', [$this, 'media']);
        $this->router->addRoute('POST', '/api/media', [$this, 'media']);
        $this->router->addRoute('POST', '/api/v1/auth/token', [$this, 'issueApiToken']);
        $this->router->addRoute('POST', '/api/v1/auth/refresh', [$this, 'refreshApiToken']);
    }

    /**
     * Stellt für die angemeldete Session ein JWT-Paar aus (Access + Refresh).
     * Bewusst nur mit bestehender Session (Login inkl. MFA) und Same-Origin – ein reiner
     * Passwort-Login per API würde die Zwei-Faktor-Anmeldung umgehen.
     */
    public function issueApiToken(): void
    {
        $this->requireJwtEnabled();

        $auth = Auth::instance();
        if ($auth->isBearerAuthenticated() || !$auth->isLoggedIn()) {
            $this->jsonError(401, 'Anmeldung über die Weboberfläche erforderlich.');
        }

        if (!$this->isSameOriginRequest()) {
            $this->denyJson('Ungültige Herkunft der Anfrage.');
        }

        $clientIp = Security::getClientIp();
        if (!Security::checkDbRateLimit($clientIp, 'api_token', 10, 3600)) {
            $this->jsonError(429, 'Zu viele Token-Anfragen. Bitte später erneut versuchen.');
        }
        Security::recordDbRateLimitAttempt($clientIp, 'api_token');

        $user = $auth->getCurrentUser();
        $userId = (int) ($user->id ?? 0);
        $binding = $auth->apiTokenBinding($userId);
        if ($binding === null) {
            $this->jsonError(401, 'Anmeldung über die Weboberfläche erforderlich.');
        }
        $claims = ['pwf' => $binding];
        $jwt = Services\JwtService::getInstance();

        try {
            $payload = [
                'token_type' => 'Bearer',
                'access_token' => $jwt->generateToken($userId, $claims),
                'expires_in' => $jwt->getTtl(),
                'refresh_token' => $jwt->generateRefreshToken($userId, $claims),
            ];
        } catch (\Throwable) {
            $this->jsonError(503, 'JWT ist nicht konfiguriert.');
        }

        if (class_exists(\CMS\AuditLogger::class)) {
            \CMS\AuditLogger::instance()->log('security', 'api.token_issued', 'API-Token ausgestellt', 'user', $userId, [], 'info');
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload);
        exit;
    }

    /**
     * Tauscht ein Refresh-Token (Feld `refresh_token`, Form oder JSON) gegen ein neues Access-Token.
     */
    public function refreshApiToken(): void
    {
        $this->requireJwtEnabled();

        $clientIp = Security::getClientIp();
        if (!Security::checkDbRateLimit($clientIp, 'api_refresh', 30, 3600)) {
            $this->jsonError(429, 'Zu viele Anfragen. Bitte später erneut versuchen.');
        }
        Security::recordDbRateLimitAttempt($clientIp, 'api_refresh');

        $refreshToken = trim((string) ($_POST['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            $body = json_decode((string) file_get_contents('php://input', false, null, 0, 16384), true);
            $refreshToken = is_array($body) ? trim((string) ($body['refresh_token'] ?? '')) : '';
        }

        $jwt = Services\JwtService::getInstance();
        $payload = $refreshToken !== '' ? $jwt->validateToken($refreshToken) : null;
        if ($payload === null || ($payload->type ?? '') !== 'refresh' || !Auth::instance()->isValidApiTokenSubject($payload)) {
            $this->jsonError(401, 'Ungültiges oder abgelaufenes Refresh-Token.');
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'token_type' => 'Bearer',
            'access_token' => $jwt->generateToken((int) $payload->sub, ['pwf' => (string) $payload->pwf]),
            'expires_in' => $jwt->getTtl(),
        ]);
        exit;
    }

    private function requireJwtEnabled(): void
    {
        if (!Services\JwtService::isApiAuthEnabled()) {
            $this->jsonError(404, 'Nicht gefunden.');
        }
    }

    private function jsonError(int $status, string $message): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function status(): void
    {
        header('Content-Type: application/json');
        // Exakte Version nur für Admins: öffentlich erleichterte sie das Zuordnen bekannter Schwachstellen.
        $payload = ['status' => 'ok'];
        if (\CMS\Auth::instance()->isAdmin()) {
            $payload['version'] = defined('CMS_VERSION') ? CMS_VERSION : Version::CURRENT;
        }
        echo json_encode($payload);
        exit;
    }

    public function pages(): void
    {
        Api::instance()->handleRequest('pages');
    }

    public function pageBySlug(string $slug): void
    {
        Api::instance()->handleRequest('pages', $slug);
    }

    public function captureWebVitals(): void
    {
        if (!$this->isSameOriginRequest()) {
            http_response_code(403);
            exit;
        }

        $raw = file_get_contents('php://input', false, null, 0, self::WEB_VITALS_MAX_BODY_BYTES + 1);
        if (!is_string($raw) || trim($raw) === '') {
            http_response_code(422);
            exit;
        }
        if (strlen($raw) > self::WEB_VITALS_MAX_BODY_BYTES) {
            http_response_code(413);
            exit;
        }
        if (!$this->consumeWebVitalsRateLimitToken()) {
            http_response_code(429);
            exit;
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            http_response_code(422);
            exit;
        }

        if (!is_array($decoded)) {
            http_response_code(422);
            exit;
        }

        $payload = $this->normalizeWebVitalsPayload($decoded);
        if ($payload === null) {
            http_response_code(422);
            exit;
        }

        Services\CoreWebVitalsService::getInstance()->storeReport($payload);
        http_response_code(204);
        exit;
    }

    public function jsonAdminMailLogs(): void
    {
        $this->requireAdminCapability('manage_settings', 'Zugriff auf die Mailprotokolle verweigert');
        header('Content-Type: application/json; charset=utf-8');

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));
        $search = $this->normalizeSearchFilter($_GET['search'] ?? '');
        $status = $this->normalizeTextFilter($_GET['status'] ?? '', 32);

        $result = Services\MailLogService::getInstance()->getRecent($limit, $page, $search, $status);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function testAdminMail(): void
    {
        $this->requireAdmin();

        $token = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!Security::instance()->verifyToken($token, 'admin_mail_api')) {
            $this->denyJson('Sicherheitsüberprüfung fehlgeschlagen.');
        }

        $recipient = trim((string)($_POST['recipient'] ?? ''));
        $result = Services\MailService::getInstance()->sendBackendTestEmail($recipient, 'admin-mail-api');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function testAdminGraph(): void
    {
        $this->requireAdmin();

        $token = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!Security::instance()->verifyToken($token, 'admin_mail_api')) {
            $this->denyJson('Sicherheitsüberprüfung fehlgeschlagen.');
        }

        $result = Services\GraphApiService::getInstance()->testConnection(true);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function upload(): void
    {
        $result = Services\FileUploadService::getInstance()->handleUploadRequest();
        http_response_code((int)($result['status'] ?? 200));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result['data'] ?? ['error' => 'Unbekannter Fehler']);
        exit;
    }

    public function media(): void
    {
        Services\EditorJsService::getInstance()->handleMediaApiRequest();
    }

    public function jsonAdminPosts(): void
    {
        $this->requireAdminCapability('edit_all_posts', 'Zugriff auf die Beitragsliste verweigert');
        header('Content-Type: application/json; charset=utf-8');
        $db = Database::instance();
        $prefix = $db->getPrefix();
        $currentDateTime = date('Y-m-d H:i:s');

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(5, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $search = $this->normalizeSearchFilter($_GET['search'] ?? '');
        $status = trim((string)($_GET['status'] ?? 'all'));
        $category = max(0, (int)($_GET['category'] ?? 0));
        $sort = in_array($_GET['sort'] ?? '', ['title', 'status', 'published_at', 'views', 'updated_at', 'created_at'], true)
            ? (string)$_GET['sort']
            : 'created_at';
        $order = strtoupper((string)($_GET['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $where = [];
        $params = [];
        if ($status === 'all') {
            $where[] = "p.status != 'trash'";
        } elseif ($status === 'published') {
            $where[] = \cms_post_publication_where('p');
        } elseif ($status === 'scheduled') {
            $where[] = "p.status = 'published' AND p.published_at IS NOT NULL AND p.published_at > ?";
            $params[] = $currentDateTime;
        } elseif (in_array($status, ['draft', 'trash', 'private'], true)) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = '(p.title LIKE ? OR p.title_en LIKE ? OR p.slug LIKE ? OR p.slug_en LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        if ($category > 0) {
            $where[] = '(p.category_id = ? OR EXISTS (
                SELECT 1
                FROM {$prefix}post_category_rel pcr
                WHERE pcr.post_id = p.id AND pcr.category_id = ?
            ))';
            $params[] = $category;
            $params[] = $category;
        }
        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int)$db->get_var("SELECT COUNT(*) FROM {$prefix}posts p {$whereStr}", $params);
        $rows = $db->get_results(
                "SELECT p.id, p.title, p.title_en, p.slug, p.slug_en, p.status, p.views, p.featured_image,
                    p.published_at, p.updated_at, p.created_at,
                    COALESCE(NULLIF(p.author_display_name, ''), NULLIF(u.display_name, ''), NULLIF(u.username, ''), 'Autor') AS author_name,
                    u.role AS author_role,
                    c.name AS category_name,
                    CASE
                        WHEN p.status = 'draft' AND COALESCE(u.role, 'member') <> 'admin' THEN 1
                        ELSE 0
                    END AS is_member_submission,
                    CASE
                        WHEN CHAR_LENGTH(TRIM(COALESCE(p.title, ''))) = 0
                             AND CHAR_LENGTH(TRIM(COALESCE(p.content, ''))) = 0
                             AND CHAR_LENGTH(TRIM(COALESCE(p.excerpt, ''))) = 0
                             AND (
                                 CHAR_LENGTH(TRIM(COALESCE(p.title_en, ''))) > 0
                                 OR CHAR_LENGTH(TRIM(COALESCE(p.content_en, ''))) > 0
                                 OR CHAR_LENGTH(TRIM(COALESCE(p.excerpt_en, ''))) > 0
                                 OR CHAR_LENGTH(TRIM(COALESCE(p.slug_en, ''))) > 0
                             )
                        THEN 1
                        ELSE 0
                    END AS is_english_only,
                    CASE
                        WHEN CHAR_LENGTH(TRIM(COALESCE(p.title_en, ''))) > 0
                             OR CHAR_LENGTH(TRIM(COALESCE(p.content_en, ''))) > 0
                             OR CHAR_LENGTH(TRIM(COALESCE(p.excerpt_en, ''))) > 0
                             OR CHAR_LENGTH(TRIM(COALESCE(p.slug_en, ''))) > 0
                        THEN 1
                        ELSE 0
                    END AS has_english_variant
             FROM {$prefix}posts p
             LEFT JOIN {$prefix}users u ON u.id = p.author_id
             LEFT JOIN {$prefix}post_categories c ON c.id = p.category_id
             {$whereStr}
             ORDER BY p.{$sort} {$order}
             LIMIT {$limit} OFFSET {$offset}",
            $params
        ) ?: [];

        $rows = array_map(static function (object $row): object {
            $row->is_member_submission = !empty($row->is_member_submission);
            $row->submission_hint = $row->is_member_submission ? 'Member-Einreichung' : '';
            $row->is_scheduled = \cms_post_is_scheduled($row);
            $row->effective_status = $row->is_scheduled ? 'scheduled' : (string) ($row->status ?? 'draft');
            $row->is_english_only = !empty($row->is_english_only);
            $row->has_english_variant = !empty($row->has_english_variant);
            $row->display_title = trim((string)($row->title ?? '')) !== ''
                ? (string)$row->title
                : (string)($row->title_en ?? '');
            $row->display_slug = trim((string)($row->slug ?? '')) !== ''
                ? (string)$row->slug
                : (string)($row->slug_en ?? '');

            return $row;
        }, $rows);

        echo json_encode([
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
        exit;
    }

    public function jsonAdminPages(): void
    {
        $this->requireAdminCapability('manage_pages', 'Zugriff auf die Seitenliste verweigert');
        header('Content-Type: application/json; charset=utf-8');
        $db = Database::instance();
        $prefix = $db->getPrefix();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(5, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $search = $this->normalizeSearchFilter($_GET['search'] ?? '');
        $status = $this->normalizeTextFilter($_GET['status'] ?? '', 32);
        $category = max(0, (int)($_GET['category'] ?? 0));
        $sort = in_array($_GET['sort'] ?? '', ['title', 'slug', 'status', 'updated_at', 'created_at'], true)
            ? (string)$_GET['sort']
            : 'created_at';
        $order = strtoupper((string)($_GET['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $where = [];
        $params = [];
        if ($status !== '' && in_array($status, ['published', 'draft', 'private'], true)) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if ($category > 0) {
            $where[] = 'p.category_id = ?';
            $params[] = $category;
        }
        if ($search !== '') {
            $where[] = '(p.title LIKE ? OR p.title_en LIKE ? OR p.slug LIKE ? OR p.slug_en LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int)$db->get_var("SELECT COUNT(*) FROM {$prefix}pages p {$whereStr}", $params);
        $rows = $db->get_results(
                    "SELECT p.id, p.title, p.title_en, p.slug, p.slug_en, p.status, p.updated_at, p.created_at,
                        CASE
                            WHEN CHAR_LENGTH(TRIM(COALESCE(p.title, ''))) = 0
                                 AND CHAR_LENGTH(TRIM(COALESCE(p.content, ''))) = 0
                                 AND (
                                     CHAR_LENGTH(TRIM(COALESCE(p.title_en, ''))) > 0
                                     OR CHAR_LENGTH(TRIM(COALESCE(p.content_en, ''))) > 0
                                     OR CHAR_LENGTH(TRIM(COALESCE(p.slug_en, ''))) > 0
                                 )
                            THEN 1
                            ELSE 0
                        END AS is_english_only,
                        CASE
                            WHEN CHAR_LENGTH(TRIM(COALESCE(p.title_en, ''))) > 0
                                 OR CHAR_LENGTH(TRIM(COALESCE(p.content_en, ''))) > 0
                                 OR CHAR_LENGTH(TRIM(COALESCE(p.slug_en, ''))) > 0
                            THEN 1
                            ELSE 0
                        END AS has_english_variant,
                        u.display_name AS author_name,
                        c.name AS category_name
             FROM {$prefix}pages p
             LEFT JOIN {$prefix}users u ON u.id = p.author_id
             LEFT JOIN {$prefix}post_categories c ON c.id = p.category_id
             {$whereStr}
             ORDER BY p.{$sort} {$order}
             LIMIT {$limit} OFFSET {$offset}",
            $params
        ) ?: [];

        $rows = array_map(static function (object $row): object {
            $row->is_english_only = !empty($row->is_english_only);
            $row->has_english_variant = !empty($row->has_english_variant);
            $row->display_title = trim((string)($row->title ?? '')) !== ''
                ? (string)$row->title
                : (string)($row->title_en ?? '');
            $row->display_slug = trim((string)($row->slug ?? '')) !== ''
                ? (string)$row->slug
                : (string)($row->slug_en ?? '');

            return $row;
        }, $rows);

        echo json_encode([
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
        exit;
    }

    public function jsonAdminUsers(): void
    {
        $this->requireAdminCapability('manage_users', 'Zugriff auf die Benutzerliste verweigert');
        header('Content-Type: application/json; charset=utf-8');
        $db = Database::instance();
        $prefix = $db->getPrefix();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(5, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $search = $this->normalizeSearchFilter($_GET['search'] ?? '');
        $role = $this->normalizeTextFilter($_GET['role'] ?? 'all', 64);
        $status = $this->normalizeTextFilter($_GET['status'] ?? '', 32);
        $sort = in_array($_GET['sort'] ?? '', ['username', 'email', 'display_name', 'role', 'status', 'created_at'], true)
            ? (string)$_GET['sort']
            : 'created_at';
        $order = strtoupper((string)($_GET['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $where = [];
        $params = [];
        if ($role === 'banned') {
            $where[] = "u.status = 'banned'";
        } elseif ($role !== 'all' && $role !== '') {
            $where[] = 'u.role = ?';
            $params[] = $role;
        }
        if ($status !== '' && in_array($status, ['active', 'inactive', 'banned'], true)) {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.display_name LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int)$db->get_var("SELECT COUNT(*) FROM {$prefix}users u {$whereStr}", $params);
        $rows = $db->get_results(
            "SELECT u.id, u.username, u.email, u.display_name, u.role, u.status, u.created_at,
                    (SELECT COUNT(*) FROM {$prefix}user_group_members ugm WHERE ugm.user_id = u.id) AS group_count
             FROM {$prefix}users u
             {$whereStr}
             ORDER BY u.{$sort} {$order}
             LIMIT {$limit} OFFSET {$offset}",
            $params
        ) ?: [];

        echo json_encode([
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
        exit;
    }

    private function requireAdmin(): void
    {
        if (!Auth::instance()->isAdmin()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Zugriff verweigert']);
            exit;
        }
    }

    private function requireAdminCapability(string $capability, string $message = 'Zugriff verweigert'): void
    {
        if (!Auth::instance()->isAdmin() || !Auth::instance()->hasCapability($capability)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    private function denyJson(string $message): never
    {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }

    private function isSameOriginRequest(): bool
    {
        $siteUrl = (string) SITE_URL;
        $siteHost = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
        $siteScheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
        if ($siteHost === '' || $siteScheme === '') {
            return false;
        }

        $hasOriginSignals = false;
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
            $value = trim((string)($_SERVER[$header] ?? ''));
            if ($value === '') {
                continue;
            }
            $hasOriginSignals = true;

            $host = strtolower((string) parse_url($value, PHP_URL_HOST));
            $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
            if ($host === '' || $scheme === '' || $host !== $siteHost || $scheme !== $siteScheme) {
                return false;
            }
        }

        return $hasOriginSignals;
    }

    private function consumeWebVitalsRateLimitToken(): bool
    {
        $window = (int) floor(time() / self::WEB_VITALS_RATE_LIMIT_WINDOW_SECONDS);
        $ip = Security::getClientIp();
        $session = session_id();
        $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $fingerprint = hash('sha256', $ip . '|' . $session . '|' . mb_substr($userAgent, 0, 180));
        // Zusätzlich ein reines IP-Kontingent: Wechselnde User-Agents/Sessions dürfen das Limit
        // nicht aushebeln (sonst beliebig viele Telemetrie-INSERTs pro Client).
        $ipKey = $window . ':ip:' . hash('sha256', $ip);
        $key = $window . ':' . $fingerprint;
        $cacheFile = rtrim((string) sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . '365cms-web-vitals-rate-' . hash('sha256', ABSPATH) . '.json';

        $handle = @fopen($cacheFile, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            $hardLimit = self::WEB_VITALS_RATE_CACHE_MAX_BYTES * 2;
            $raw = stream_get_contents($handle, $hardLimit + 1);
            $buckets = [];
            if (is_string($raw) && $raw !== '' && strlen($raw) <= $hardLimit) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $buckets = $decoded;
                }
            }

            $minWindow = $window - 2;
            foreach (array_keys($buckets) as $bucketKey) {
                $parts = explode(':', (string) $bucketKey, 2);
                $bucketWindow = isset($parts[0]) ? (int) $parts[0] : 0;
                if ($bucketWindow < $minWindow) {
                    unset($buckets[$bucketKey]);
                }
            }

            $count = isset($buckets[$key]) ? (int) $buckets[$key] : 0;
            $ipCount = isset($buckets[$ipKey]) ? (int) $buckets[$ipKey] : 0;
            if ($count >= self::WEB_VITALS_RATE_LIMIT_MAX_REQUESTS
                || $ipCount >= self::WEB_VITALS_RATE_LIMIT_MAX_REQUESTS * self::WEB_VITALS_RATE_LIMIT_IP_FACTOR
            ) {
                return false;
            }

            $isKnownClient = isset($buckets[$key], $buckets[$ipKey]);
            $buckets[$key] = $count + 1;
            $buckets[$ipKey] = $ipCount + 1;

            $encoded = json_encode($buckets, JSON_UNESCAPED_SLASHES);
            if (is_string($encoded) && strlen($encoded) > self::WEB_VITALS_RATE_CACHE_MAX_BYTES) {
                // Tabelle voll: zuerst Vorfenster verwerfen. Bleibt sie voll, werden nur Clients
                // ohne Bucket im aktuellen Fenster abgewiesen – bekannte Clients zählen weiter
                // (bis zur harten Grenze), damit wenige Angreifer nicht alle Besucher sperren und
                // ein Flood das Limit nicht durch Überlauf aufhebt.
                foreach (array_keys($buckets) as $bucketKey) {
                    if ((int) explode(':', (string) $bucketKey, 2)[0] < $window) {
                        unset($buckets[$bucketKey]);
                    }
                }
                $encoded = json_encode($buckets, JSON_UNESCAPED_SLASHES);
                if (is_string($encoded) && strlen($encoded) > self::WEB_VITALS_RATE_CACHE_MAX_BYTES && !$isKnownClient) {
                    return false;
                }
            }

            if (!is_string($encoded) || strlen($encoded) > $hardLimit) {
                return false;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $encoded);
            fflush($handle);
            @chmod($cacheFile, 0600);

            return true;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function normalizeWebVitalsPayload(array $payload): ?array
    {
        $path = trim((string) ($payload['path'] ?? ''));
        if ($path === '' || !str_starts_with($path, '/')) {
            return null;
        }

        $parsedPath = parse_url($path, PHP_URL_PATH);
        if (!is_string($parsedPath)) {
            return null;
        }
        $parsedPath = preg_replace('/[\x00-\x1F\x7F]+/u', '', $parsedPath) ?? '';

        $normalized = [
            'path' => mb_substr($parsedPath, 0, 500),
            'title' => mb_substr(trim((string) ($payload['title'] ?? '')), 0, 255),
            'ttfb' => $this->normalizeMetricNumber($payload['ttfb'] ?? null, 60000),
            'lcp' => $this->normalizeMetricNumber($payload['lcp'] ?? null, 60000),
            'inp' => $this->normalizeMetricNumber($payload['inp'] ?? null, 60000),
            'cls' => $this->normalizeMetricFloat($payload['cls'] ?? null, 10.0),
            'effective_type' => $this->normalizeAllowedString($payload['effective_type'] ?? '', self::WEB_VITALS_ALLOWED_EFFECTIVE_TYPES, 32),
            'navigation_type' => $this->normalizeAllowedString($payload['navigation_type'] ?? '', self::WEB_VITALS_ALLOWED_NAVIGATION_TYPES, 32),
            'viewport_width' => $this->normalizeMetricNumber($payload['viewport_width'] ?? null, 10000),
            'viewport_height' => $this->normalizeMetricNumber($payload['viewport_height'] ?? null, 10000),
        ];

        if ($normalized['path'] === '') {
            return null;
        }
        if ($normalized['ttfb'] === null && $normalized['lcp'] === null && $normalized['inp'] === null && $normalized['cls'] === null) {
            return null;
        }

        return $normalized;
    }

    private function normalizeMetricNumber(mixed $value, int $max): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (int) round((float) $value);
        if ($number < 0 || $number > $max) {
            return null;
        }

        return $number;
    }

    private function normalizeMetricFloat(mixed $value, float $max): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = round((float) $value, 3);
        if ($number < 0 || $number > $max) {
            return null;
        }

        return $number;
    }

    private function normalizeAllowedString(mixed $value, array $allowed, int $maxLength): string
    {
        $normalized = mb_substr(strtolower(trim((string) $value)), 0, $maxLength);

        return in_array($normalized, $allowed, true) ? $normalized : '';
    }

    private function normalizeSearchFilter(mixed $value): string
    {
        return $this->normalizeTextFilter($value, self::API_SEARCH_MAX_LENGTH);
    }

    private function normalizeTextFilter(mixed $value, int $maxLength): string
    {
        $normalized = trim((string) $value);
        $normalized = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $normalized) ?? '';

        return mb_substr($normalized, 0, max(1, $maxLength));
    }
}
