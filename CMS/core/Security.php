<?php
/**
 * Security Class
 * 
 * Handles CSRF protection, XSS prevention, input sanitization
 * 
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS;

if (!defined('ABSPATH')) {
    exit;
}

class Security
{
    private static ?self $instance = null;
    private const int CSRF_TOKEN_TTL = 3600;
    private const int CSRF_TOKEN_HISTORY_LIMIT = 20;

    /**
     * Per-Request CSP-Nonce (H-03)
     * Wird in init() einmalig generiert und in allen Templates genutzt.
     */
    private string $cspNonce = '';

    /** @var array<string, array<string, bool>> Direktive => zusätzliche Quellen */
    private array $extraCspSources = [];

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

    private static function sanitizeDiagnosticText(string $value, int $maxLength = 500): string
    {
        $value = preg_replace('/([?&](?:token|csrf_token|nonce|key|secret|password|pass)=)[^&\s]+/i', '$1***', $value) ?? $value;
        $value = preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/-]+=*/i', '$1***', $value) ?? $value;
        $value = preg_replace('/(password|passwd|secret|token|credential|api[_-]?key)(\s*[=:]\s*)\S+/i', '$1$2***', $value) ?? $value;
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
    }

    /**
     * CSP-Nonce für inline <script nonce="..."> und <style nonce="..."> zurückgeben (H-03)
     */
    public function getNonce(): string
    {
        return $this->cspNonce;
    }

    /**
     * Fertiges nonce-Attribut zurückgeben, z. B.: nonce="abc123"
     * Nutzung in Templates: <script <?= Security::instance()->nonceAttr() ?>>
     */
    public function nonceAttr(): string
    {
        return 'nonce="' . htmlspecialchars($this->cspNonce, ENT_QUOTES, 'UTF-8') . '"';
    }
    
    /**
     * Initialize security measures
     * Nonce wird VOR den Headers generiert, damit er in den CSP-Header eingebettet werden kann.
     */
    public function init(): void
    {
        // H-03: Nonce einmalig pro Request generieren
        $this->cspNonce = base64_encode(random_bytes(18)); // 24 Zeichen base64
        $this->setSecurityHeaders();
        $this->startSession();
    }

    private function isHttpsRequest(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if (in_array($forwardedProto, ['https', 'wss'], true)) {
            return true;
        }

        $forwardedSsl = strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
        if (in_array($forwardedSsl, ['on', '1', 'true'], true)) {
            return true;
        }

        $frontEndHttps = strtolower((string)($_SERVER['HTTP_FRONT_END_HTTPS'] ?? ''));
        return in_array($frontEndHttps, ['on', '1'], true);
    }

    /**
     * @return array<int, string>
     */
    private function getBaseCspDirectives(string $nonce, bool $includeUpgradeInsecureRequests = true): array
    {
        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'" . $this->getExtraCspSources('script-src'),
            "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com",
            "img-src 'self' data: blob:" . $this->getSiteOriginCspSource() . $this->getExtraCspSources('img-src'),
            "font-src 'self' data: https://fonts.gstatic.com",
            "connect-src 'self'" . $this->getExtraCspSources('connect-src'),
            "media-src 'self' data: blob:" . $this->getSiteOriginCspSource(),
            "object-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "manifest-src 'self'",
        ];

        if ($includeUpgradeInsecureRequests && $this->isHttpsRequest()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return $directives;
    }

    private function buildCspPolicy(array $directives): string
    {
        return implode('; ', $directives);
    }

    /**
     * Erlaubt zusätzliche HTTPS-Quellen für einzelne CSP-Direktiven (z. B. einen
     * explizit konfigurierten Analytics-Anbieter) und sendet die CSP neu.
     * Nur script-src, connect-src und img-src; nur https-Hosts ohne Pfad.
     *
     * @param array<string, list<string>> $sourcesByDirective
     */
    public function allowCspSources(array $sourcesByDirective): bool
    {
        if (headers_sent()) {
            return false;
        }

        $added = false;
        foreach ($sourcesByDirective as $directive => $sources) {
            if (!in_array($directive, ['script-src', 'connect-src', 'img-src'], true)) {
                continue;
            }

            foreach ((array) $sources as $source) {
                $source = strtolower(trim((string) $source));
                if (preg_match('#^https://(?:\*\.)?[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+(?::\d{2,5})?$#', $source) !== 1) {
                    continue;
                }
                $this->extraCspSources[$directive][$source] = true;
                $added = true;
            }
        }

        if ($added) {
            $this->sendCspHeader();
        }

        return true;
    }

    /**
     * Zusätzlich freigegebene Script-Origins (für die Trusted-Types-Policy der CSP-Runtime).
     *
     * @return list<string>
     */
    public function getAllowedScriptOrigins(): array
    {
        return array_keys($this->extraCspSources['script-src'] ?? []);
    }

    /**
     * Medien-URLs werden aus SITE_URL/UPLOAD_URL gebaut. Läuft der Request über einen anderen Host
     * (www-Alias, Hub-Alias-Domain), deckt 'self' diese Bilder nicht ab – daher den SITE_URL-Origin ergänzen.
     */
    private function getSiteOriginCspSource(): string
    {
        $parts = parse_url(defined('SITE_URL') ? (string) SITE_URL : '');
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return '';
        }

        $requestHost = strtolower(trim(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''), 2)[0], '.'));
        if ($requestHost === $host) {
            return '';
        }

        return ' ' . $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    private function getExtraCspSources(string $directive): string
    {
        $sources = array_keys($this->extraCspSources[$directive] ?? []);

        return $sources === [] ? '' : ' ' . implode(' ', $sources);
    }

    private function getHttpsRedirectStrategy(): string
    {
        $strategy = strtolower(trim((string) (defined('CMS_HTTPS_REDIRECT_STRATEGY') ? CMS_HTTPS_REDIRECT_STRATEGY : 'upstream')));

        return in_array($strategy, ['upstream', 'apache', 'core', 'disabled'], true) ? $strategy : 'upstream';
    }

    private function getHstsValue(): string
    {
        $mode = strtolower(trim((string) (defined('CMS_HSTS_MODE') ? CMS_HSTS_MODE : 'https-only')));
        $isHttps = $this->isHttpsRequest();

        if (CMS_DEBUG || $mode === 'disabled' || !$isHttps) {
            return '';
        }

        $maxAge = defined('CMS_HSTS_MAX_AGE') ? max(0, (int) CMS_HSTS_MAX_AGE) : 31536000;
        if ($maxAge <= 0) {
            return '';
        }

        return 'max-age=' . $maxAge . '; includeSubDomains; preload';
    }

    public function getSecurityHeaderProfile(): array
    {
        $isHttps = $this->isHttpsRequest();
        $hstsValue = $this->getHstsValue();
        $hstsEnabled = $hstsValue !== '';
        $trustedTypesEnforced = !CMS_DEBUG;
        $redirectStrategy = $this->getHttpsRedirectStrategy();

        return [
            'https' => $isHttps,
            'https_redirect_strategy' => $redirectStrategy,
            'https_redirect_managed_by' => match ($redirectStrategy) {
                'apache' => '.htaccess / Apache',
                'core' => 'Core-PHP',
                'disabled' => 'deaktiviert',
                default => 'Reverse-Proxy / Webserver',
            },
            'csp_mode' => CMS_DEBUG ? 'report-only' : 'enforced',
            'csp_uses_nonce' => true,
            'trusted_types_enforced' => $trustedTypesEnforced,
            'trusted_types_report_only' => CMS_DEBUG,
            'hsts_enabled' => $hstsEnabled,
            'hsts_include_subdomains' => $hstsEnabled,
            'hsts_preload' => $hstsEnabled,
            'hsts_mode' => defined('CMS_HSTS_MODE') ? (string) CMS_HSTS_MODE : 'https-only',
            'hsts_value' => $hstsValue,
        ];
    }
    
    /**
     * Set security headers
     *
     * H-03: Nonce-basierte CSP (kein unsafe-inline mehr)
     * H-04: HSTS mit includeSubDomains
     */
    private function setSecurityHeaders(): void
    {
        if (!headers_sent()) {
            $xFrameOptions = 'DENY';
            if (!in_array($xFrameOptions, ['DENY', 'SAMEORIGIN'], true)) {
                $xFrameOptions = 'DENY';
            }

            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: ' . $xFrameOptions);
            header('X-XSS-Protection: 0');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cross-Origin-Resource-Policy: same-site');

            if (defined('CMS_MODE') && in_array(CMS_MODE, ['admin', 'api'], true)) {
                header('Cache-Control: no-store, max-age=0');
                header('Pragma: no-cache');
                header('X-Robots-Tag: noindex, nofollow');
            }

            // H-04: HSTS – nur über HTTPS senden, nicht im Debug-Modus
            $hstsValue = $this->getHstsValue();
            if ($hstsValue !== '') {
                header('Strict-Transport-Security: ' . $hstsValue);
            }

            $this->sendCspHeader();
        }
    }
    
    private function sendCspHeader(): void
    {
        if (headers_sent()) {
            return;
        }

        $nonce = $this->cspNonce;
        $enforcedCsp = $this->buildCspPolicy(array_merge(
            $this->getBaseCspDirectives($nonce, true),
            [
                "trusted-types cms365 default sanitize-html dompurify",
                "require-trusted-types-for 'script'",
            ]
        ));
        $reportOnlyCsp = $this->buildCspPolicy(array_merge(
            $this->getBaseCspDirectives($nonce, false),
            [
                "trusted-types cms365 default sanitize-html dompurify",
                "require-trusted-types-for 'script'",
            ]
        ));

        if (CMS_DEBUG) {
            header('Content-Security-Policy-Report-Only: ' . $reportOnlyCsp);
        } else {
            header('Content-Security-Policy: ' . $enforcedCsp);
        }
    }

    /**
     * Start secure session
     */
    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Start session with secure settings
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', $this->isHttpsRequest() ? '1' : '0');
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_strict_mode', '1');
            session_start();
            
            // Regenerate session ID for security (prevent session fixation)
            if (!isset($_SESSION['initialized'])) {
                session_regenerate_id(true);
                $_SESSION['initialized'] = true;
            }
        }
    }
    
    /**
     * Generate CSRF token field (WP style compatibility)
     */
    public function generateNonceField(string $action = 'default'): void
    {
        $token = $this->generateToken($action);
        echo '<input type="hidden" name="_wpnonce" value="' . $this->escape($token) . '">';
    }

    /**
     * Verify CSRF token (WP style compatibility)
     */
    public function verifyNonce(string $token, string $action = 'default'): bool
    {
        return $this->verifyToken($token, $action);
    }

    /**
     * Generate CSRF token (Alias for generateToken)
     */
    public function createNonce(string $action = 'default'): string
    {
        return $this->generateToken($action);
    }

    /**
     * Generate CSRF token
     */
    public function generateToken(string $action = 'default'): string
    {
        $token = bin2hex(random_bytes(32));
        $tokens = $this->normalizeCsrfTokenBucket($action);
        $tokens[] = [
            'token' => $token,
            'time' => time(),
        ];

        $_SESSION['csrf_tokens'][$action] = [
            'tokens' => array_slice($tokens, -self::CSRF_TOKEN_HISTORY_LIMIT),
        ];
        
        return $token;
    }
    
    /**
     * Verify CSRF token
     */
    public function verifyToken(string $token, string $action = 'default'): bool
    {
        $token = trim($token);
        if ($token === '' || !isset($_SESSION['csrf_tokens'][$action])) {
            return false;
        }

        $tokens = $this->normalizeCsrfTokenBucket($action);
        if ($tokens === []) {
            unset($_SESSION['csrf_tokens'][$action]);
            return false;
        }

        $valid = false;
        $remaining = [];
        foreach ($tokens as $stored) {
            $storedToken = (string) ($stored['token'] ?? '');
            if ($storedToken !== '' && !$valid && hash_equals($storedToken, $token)) {
                $valid = true;
                continue;
            }

            $remaining[] = $stored;
        }

        // Token nach erfolgreicher Prüfung invalidieren (verhindert Replay-Angriffe)
        if ($valid) {
            if ($remaining === []) {
                unset($_SESSION['csrf_tokens'][$action]);
            } else {
                $_SESSION['csrf_tokens'][$action] = ['tokens' => array_slice($remaining, -self::CSRF_TOKEN_HISTORY_LIMIT)];
            }
        }

        return $valid;
    }

    /**
     * Verify CSRF token without invalidating it.
     *
     * Nützlich für UI-Komponenten mit vielen Folge-Requests innerhalb derselben
     * Session (z. B. Dateimanager/Connectoren), bei denen ein Einmal-Token für
     * jeden einzelnen Request unpraktisch wäre.
     */
    public function verifyPersistentToken(string $token, string $action = 'default'): bool
    {
        $token = trim($token);
        if ($token === '' || !isset($_SESSION['csrf_tokens'][$action])) {
            return false;
        }

        $tokens = $this->normalizeCsrfTokenBucket($action);
        if ($tokens === []) {
            unset($_SESSION['csrf_tokens'][$action]);
            return false;
        }

        $_SESSION['csrf_tokens'][$action] = ['tokens' => array_slice($tokens, -self::CSRF_TOKEN_HISTORY_LIMIT)];

        foreach ($tokens as $stored) {
            $storedToken = (string) ($stored['token'] ?? '');
            if ($storedToken !== '' && hash_equals($storedToken, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{token:string,time:int}>
     */
    private function normalizeCsrfTokenBucket(string $action): array
    {
        $stored = $_SESSION['csrf_tokens'][$action] ?? null;
        $candidates = [];

        if (is_array($stored) && isset($stored['token'], $stored['time'])) {
            $candidates[] = $stored;
        } elseif (is_array($stored) && is_array($stored['tokens'] ?? null)) {
            $candidates = $stored['tokens'];
        }

        $now = time();
        $tokens = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate) || !is_string($candidate['token'] ?? null)) {
                continue;
            }

            $createdAt = (int) ($candidate['time'] ?? 0);
            if ($createdAt <= 0 || ($now - $createdAt) > self::CSRF_TOKEN_TTL) {
                continue;
            }

            $tokens[] = [
                'token' => $candidate['token'],
                'time' => $createdAt,
            ];
        }

        return array_slice($tokens, -self::CSRF_TOKEN_HISTORY_LIMIT);
    }
    
    /**
     * Sanitize input string (static wrapper)
     */
    public static function sanitize(string $input, string $type = 'text'): string
    {
        switch ($type) {
            case 'email':
                return filter_var($input, FILTER_SANITIZE_EMAIL);
            
            case 'url':
                return filter_var($input, FILTER_SANITIZE_URL);
            
            case 'int':
                return (string) filter_var($input, FILTER_SANITIZE_NUMBER_INT);
            
            case 'html':
                return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
            
            case 'username':
                // Username: alphanumeric + underscore only
                return preg_replace('/[^a-zA-Z0-9_]/', '', $input);
            
            case 'text':
            default:
                return strip_tags(trim($input));
        }
    }
    
    /**
     * Escape output for HTML (static wrapper)
     */
    public static function escape(string|int $output): string
    {
        return htmlspecialchars((string)$output, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Validate email (static wrapper)
     */
    public static function validateEmail(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
    
    /**
     * Validate URL (static wrapper)
     */
    public static function validateUrl(string $url): bool
    {
        return (bool) filter_var($url, FILTER_VALIDATE_URL);
    }
    
    /**
     * Hash password (static wrapper)
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
    
    /**
     * Verify password (static wrapper)
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
    
    /**
     * Rate limiting check (static wrapper with session)
     * Fallback für Kontexte ohne DB. Primär: checkDbRateLimit() verwenden.
     */
    public static function checkRateLimit(string $identifier, int $maxAttempts = 5, int $timeWindow = 300): bool
    {
        $key = self::buildRateLimitSessionKey($identifier);
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'attempts' => 1,
                'first_attempt' => time()
            ];
            return true;
        }
        
        $data = $_SESSION[$key];
        
        // Reset if time window passed
        if (time() - $data['first_attempt'] > $timeWindow) {
            $_SESSION[$key] = [
                'attempts' => 1,
                'first_attempt' => time()
            ];
            return true;
        }
        
        // Check if limit exceeded
        if ($data['attempts'] >= $maxAttempts) {
            return false;
        }
        
        // Increment attempts
        $_SESSION[$key]['attempts']++;
        return true;
    }

    /**
     * H-05: Rate-Limiting auf Datenbankbasis (IP + Action)
     *
     * Zählt Login-Versuche aus der DB-Tabelle `login_attempts` – erkennt so auch
     * verteilte Brute-Force-Angriffe über mehrere Sessions/Browser/IPs-Ranges.
     *
     * @param  string $ip          Client-IP (aus getClientIp())
     * @param  string $action      Aktion-Bezeichner, z. B. 'login'
     * @param  int    $maxAttempts Maximale Versuche im Zeitfenster
     * @param  int    $timeWindow  Zeitfenster in Sekunden
     * @return bool   true = Versuch erlaubt, false = gesperrt
     */
    public static function checkDbRateLimit(
        string $ip,
        string $action,
        int $maxAttempts = 5,
        int $timeWindow = 300
    ): bool {
        try {
            $db = Database::instance();
            $prefix = $db->getPrefix();
            $since  = date('Y-m-d H:i:s', time() - $timeWindow);
            // H-05: Datenbankbasiertes Zählen – IP + Action-Feld berücksichtigen
            // (schützt auch gegen verteilte Brute-Force über mehrere Sessions)
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS attempt_count
                   FROM {$prefix}login_attempts
                  WHERE ip_address = ?
                    AND action = ?
                    AND attempted_at >= ?"
            );
            $stmt->execute([$ip, self::normalizeRateLimitAction($action), $since]);
            $row = $stmt->fetch(\PDO::FETCH_OBJ);

            $count = (int) ($row->attempt_count ?? 0);

            // Gelegentlich alte Einträge bereinigen (1:20 Chance pro Request)
            if (random_int(1, 20) === 1) {
                $cutoff = date('Y-m-d H:i:s', time() - max($timeWindow * 4, 86400));
                $del = $db->prepare("DELETE FROM {$prefix}login_attempts WHERE attempted_at < ?");
                $del->execute([$cutoff]);
            }

            return $count < $maxAttempts;

        } catch (\Throwable $e) {
            error_log('Security::checkDbRateLimit() Fehler (Fallback auf Session): ' . self::sanitizeDiagnosticText($e->getMessage()));
            // Fallback auf Session-basiertes Rate-Limiting wenn DB nicht verfügbar
            return self::checkRateLimit('db_rl_' . $action . '_' . $ip, $maxAttempts, $timeWindow);
        }
    }

    /**
     * Spaltenbreiten von `login_attempts` (action VARCHAR(30), username VARCHAR(60)).
     * Längere Werte ließen das INSERT im SQL-Strict-Mode scheitern – die betroffenen Limits
     * (Passwort-Reset je Konto und je Token) zählten dadurch nie.
     */
    public const RATE_LIMIT_ACTION_MAX_LENGTH = 30;
    public const RATE_LIMIT_IDENTIFIER_MAX_LENGTH = 60;

    public static function normalizeRateLimitAction(string $action): string
    {
        $action = preg_replace('/[^a-z0-9_.:-]/i', '_', trim($action)) ?? '';

        return substr($action, 0, self::RATE_LIMIT_ACTION_MAX_LENGTH);
    }

    public static function normalizeRateLimitIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if (strlen($identifier) <= self::RATE_LIMIT_IDENTIFIER_MAX_LENGTH) {
            return $identifier;
        }

        // Lange Kennungen (E-Mail-Adressen, Token-Hashes) eindeutig kürzen.
        return substr(hash('sha256', $identifier), 0, self::RATE_LIMIT_IDENTIFIER_MAX_LENGTH);
    }

    public static function recordDbRateLimitAttempt(string $ip, string $action, string $username = ''): void
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return;
        }

        $action = self::normalizeRateLimitAction($action);
        if ($action === '') {
            return;
        }
        $username = self::normalizeRateLimitIdentifier($username);

        try {
            $db = Database::instance();
            $prefix = $db->getPrefix();
            $stmt = $db->prepare("INSERT INTO {$prefix}login_attempts (username, ip_address, action, attempted_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$username, $ip, $action]);
        } catch (\Throwable $e) {
            error_log('Security::recordDbRateLimitAttempt() Fehler: ' . self::sanitizeDiagnosticText($e->getMessage()));
        }
    }

    private static function buildRateLimitSessionKey(string $identifier): string
    {
        return 'rate_limit_' . hash('sha256', $identifier);
    }
    
    /**
     * Echte Client-IP (pro Request zwischengespeichert).
     *
     * Weiterleitungs-Header (X-Forwarded-For, Forwarded, X-Real-IP, CF-Connecting-IP …) werden nur
     * ausgewertet, wenn REMOTE_ADDR ein vertrauenswürdiger Proxy ist: `CMS_TRUSTED_PROXIES` bzw. die
     * Firewall-Einstellung „Proxy / CDN“, Standard `auto` (private Netze + Cloudflare). Details und
     * Hoster-CDNs mit unbekannten Adressen: siehe Services\TrustedProxyService.
     */
    public static function getClientIp(): string
    {
        return Services\TrustedProxyService::clientIp();
    }
}
