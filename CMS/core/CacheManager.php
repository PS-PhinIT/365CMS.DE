<?php
/**
 * Cache Manager
 * 
 * Handles File-based caching and LiteSpeed integration
 * 
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS;

use CMS\Contracts\CacheInterface;

if (!defined('ABSPATH')) {
    exit;
}

class CacheManager implements CacheInterface
{
    private static ?self $instance = null;
    private string $cacheDir;
    private bool $useLiteSpeed;

    /**
     * M-05: APCu vorhanden und aktiviert?
     * L1-Cache (In-Memory, sub-Millisekunde) vor File-Cache (L2).
     */
    private bool $useApcu;
    
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function sanitizeDiagnosticText(string $value, int $maxLength = 500): string
    {
        $value = preg_replace('/([?&](?:token|csrf_token|nonce|key|secret|password|pass)=)[^&\s]+/i', '$1***', $value) ?? $value;
        $value = preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/-]+=*/i', '$1***', $value) ?? $value;
        $value = preg_replace('/(password|passwd|secret|token|credential|api[_-]?key)(\s*[=:]\s*)\S+/i', '$1$2***', $value) ?? $value;
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
    }
    
    private function __construct()
    {
        $this->cacheDir = ABSPATH . 'cache/';
        $this->useLiteSpeed = isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'LiteSpeed') !== false;

        // M-05: APCu als L1-Cache aktivieren (schneller In-Memory-Layer)
        $this->useApcu = function_exists('apcu_fetch') &&
                         function_exists('apcu_store') &&
                         function_exists('apcu_delete') &&
                         function_exists('apcu_clear_cache') &&
                         ini_get('apc.enabled') &&
                         PHP_SAPI !== 'cli'; // APCu deaktiviert im CLI ohne apc.enable_cli

        if (!file_exists($this->cacheDir)) {
            // M-03: Race-Condition-sicher, kein @ – is_dir als zweite Prüfung
            if (!mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
                error_log('CacheManager: Cache-Verzeichnis konnte nicht erstellt werden: ' . $this->cacheDir);
            }
        }
    }

    /**
     * HMAC-Key aus Konfigurations-Konstante lesen
     */
    private function getHmacKey(): string
    {
        foreach (['NONCE_KEY', 'SECURE_AUTH_KEY', 'AUTH_KEY', 'JWT_SECRET'] as $constant) {
            if (!defined($constant)) {
                continue;
            }

            $value = trim((string) constant($constant));
            if ($value === '' || str_contains($value, 'REPLACE_VIA_INSTALLER')) {
                continue;
            }

            return $value;
        }

        return hash('sha256', ABSPATH . '|' . __FILE__ . '|' . PHP_VERSION);
    }

    /**
     * Get item from cache.
     * M-05: Liest zuerst aus APCu (L1), danach File-Cache (L2).
     *       Bei L2-Treffer wird L1 nachgefüllt (Read-Through).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // L1: APCu (In-Memory)
        if ($this->useApcu) {
            $success = false;
            $val = apcu_fetch($this->apcuKey($key), $success);
            if ($success) {
                return $val;
            }
        }

        // L2: File-Cache
        $file = $this->getCacheFile($key);
        if (!file_exists($file)) {
            return $default;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return $default;
        }

        // Format: <hmac>:<base64-encoded-json>
        $sep = strpos($raw, ':');
        if ($sep === false) {
            // Altes Format (serialize) – Datei ungültig löschen
            if (file_exists($file)) { unlink($file); }
            return $default;
        }

        $storedHmac = substr($raw, 0, $sep);
        $payload    = base64_decode(substr($raw, $sep + 1), true);

        if ($payload === false) {
            if (file_exists($file)) { unlink($file); }
            return $default;
        }

        // HMAC-Integritätsprüfung (verhindert PHP Object Injection)
        $expectedHmac = hash_hmac('sha256', $payload, $this->getHmacKey());
        if (!hash_equals($expectedHmac, $storedHmac)) {
            if (file_exists($file)) { unlink($file); }
            error_log('CacheManager: HMAC-Fehlschlag für Cache-Schlüssel ' . $this->sanitizeDiagnosticText($key, 120) . ' – mögliche Manipulation!');
            return $default;
        }

        $data = Json::decodeArray($payload, []);
        if (!is_array($data) || !array_key_exists('v', $data) || !isset($data['e'])) {
            if (file_exists($file)) { unlink($file); }
            return $default;
        }

        // Ablaufzeit prüfen
        if ($data['e'] < time()) {
            if (file_exists($file)) { unlink($file); }
            return $default;
        }

        // M-05: Read-Through – L2-Treffer in L1 zurückschreiben (restliche TTL)
        if ($this->useApcu) {
            $remainingTtl = $data['e'] - time();
            if ($remainingTtl > 0) {
                apcu_store($this->apcuKey($key), $data['v'], $remainingTtl);
            }
        }

        return $data['v'];
    }

    /**
     * Set item in cache.
     * M-05: Schreibt in APCu (L1) UND File-Cache (L2) gleichzeitig (Write-Through).
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl  = $ttl ?? 3600;
        $file = $this->getCacheFile($key);

        // L1: APCu
        if ($this->useApcu) {
            apcu_store($this->apcuKey($key), $value, $ttl);
        }

        // L2: File-Cache (JSON + HMAC)
        try {
            $payload = json_encode(['v' => $value, 'e' => time() + $ttl], JSON_THROW_ON_ERROR);
            $hmac    = hash_hmac('sha256', $payload, $this->getHmacKey());
            return (bool) file_put_contents($file, $hmac . ':' . base64_encode($payload), LOCK_EX);
        } catch (\JsonException $e) {
            error_log('CacheManager::set() JSON error: ' . $this->sanitizeDiagnosticText($e->getMessage()));
            return false;
        }
    }

    /**
     * Delete item from cache.
     * M-05: Entfernt aus APCu (L1) und File-Cache (L2).
     */
    public function delete(string $key): bool
    {
        // L1: APCu
        if ($this->useApcu) {
            apcu_delete($this->apcuKey($key));
        }

        // L2: File-Cache
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            return unlink($file); // M-03: kein @, file_exists geprüft
        }
        return true;
    }

    /**
     * Prüft ob ein gültiger Cache-Eintrag existiert (CacheInterface::has)
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Flush entire cache (Alias: clear() für CacheInterface)
     */
    public function flush(): bool
    {
        return $this->clear();
    }

    /**
     * Leert den gesamten Cache (L1 APCu + L2 File).
     * M-05: APCu wird ebenfalls vollständig geleert.
     */
    public function clear(): bool
    {
        // L1: APCu löschen
        if ($this->useApcu) {
            apcu_clear_cache();
        }

        // L2: File-Cache löschen
        $files   = glob($this->cacheDir . '*') ?: [];
        $success = true;
        foreach ($files as $file) {
            if (is_file($file)) {
                if (!unlink($file)) { // M-03: kein @, is_file vorher geprüft
                    $success = false;
                }
            }
        }

        // LiteSpeed Purge
        if ($this->useLiteSpeed) {
            header('X-LiteSpeed-Purge: *');
        }
        return $success;
    }

    // ── CacheInterface: Batch-Methoden ────────────────────────────────────────

    /**
     * Liest mehrere Werte in einem Aufruf (CacheInterface::getMultiple)
     *
     * @param  string[] $keys
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys, mixed $default = null): array
    {
        $result = [];
        foreach ($keys as $key) {
            $val = $this->get($key);
            $result[$key] = ($val !== null) ? $val : $default;
        }
        return $result;
    }

    /**
     * Speichert mehrere Werte (CacheInterface::setMultiple)
     *
     * @param  array<string, mixed> $values
     */
    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            $success = $this->set((string) $key, $value, $ttl) && $success;
        }
        return $success;
    }

    /**
     * Löscht mehrere Einträge (CacheInterface::deleteMultiple)
     *
     * @param  string[] $keys
     */
    public function deleteMultiple(array $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            $success = $this->delete($key) && $success;
        }
        return $success;
    }
    
    /**
     * Clear ALL caches (OPcache, APCu, File-Cache, Realpath, etc.)
     * 
     * @return array Status-Report mit Details zu jedem Cache-Typ
     */
    public function clearAll(): array
    {
        $report = [
            'file_cache' => false,
            'opcache' => false,
            'apcu' => false,
            'realpath_cache' => false,
            'stat_cache' => false,
            'litespeed' => false,
            'details' => []
        ];
        
        // 1. File-based Cache
        try {
            $files = glob($this->cacheDir . '*') ?: [];
            $count = 0;
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                    $count++;
                }
            }
            $report['file_cache'] = true;
            $report['details']['file_cache'] = "$count Dateien gelöscht";
        } catch (\Exception $e) {
            $report['details']['file_cache'] = 'Fehler: ' . $e->getMessage();
        }
        
        // 2. OPcache (Bytecode-Cache)
        if (function_exists('opcache_reset')) {
            try {
                opcache_reset();
                $report['opcache'] = true;
                $report['details']['opcache'] = 'Erfolgreich geleert';
            } catch (\Exception $e) {
                $report['details']['opcache'] = 'Fehler: ' . $e->getMessage();
            }
        } else {
            $report['details']['opcache'] = 'Nicht installiert oder deaktiviert';
        }
        
        // 3. APCu (User-Cache)
        if (function_exists('apcu_clear_cache')) {
            try {
                apcu_clear_cache();
                $report['apcu'] = true;
                $report['details']['apcu'] = 'Erfolgreich geleert';
            } catch (\Exception $e) {
                $report['details']['apcu'] = 'Fehler: ' . $e->getMessage();
            }
        } else {
            $report['details']['apcu'] = 'Nicht installiert oder deaktiviert';
        }
        
        // 4. Realpath Cache (PHP Pfad-Cache)
        if (function_exists('clearstatcache')) {
            clearstatcache(true); // true = auch Realpath-Cache leeren
            $report['realpath_cache'] = true;
            $report['details']['realpath_cache'] = 'Erfolgreich geleert';
        }
        
        // 5. Stat Cache (Datei-Status-Cache)
        clearstatcache();
        $report['stat_cache'] = true;
        $report['details']['stat_cache'] = 'Erfolgreich geleert';
        
        // 6. LiteSpeed Cache
        if ($this->useLiteSpeed) {
            header('X-LiteSpeed-Purge: *');
            $report['litespeed'] = true;
            $report['details']['litespeed'] = 'Purge-Header gesendet';
        } else {
            $report['details']['litespeed'] = 'Nicht verfügbar (kein LiteSpeed)';
        }
        
        return $report;
    }
    
    /**
     * Cache-Status abrufen
     * 
     * @return array Informationen über alle Cache-Typen
     */
    public function getStatus(): array
    {
        $status = [
            'file_cache' => [
                'enabled' => true,
                'directory' => $this->cacheDir,
                'writable' => is_writable($this->cacheDir),
                'files' => count(glob($this->cacheDir . '*.cache') ?: []),
                'size' => $this->getCacheDirSize()
            ],
            'opcache' => [
                'enabled' => function_exists('opcache_get_status') && opcache_get_status(false) !== false,
                'status' => function_exists('opcache_get_status') ? opcache_get_status(false) : null
            ],
            'apcu' => [
                'enabled' => function_exists('apcu_cache_info') && function_exists('apcu_enabled') && apcu_enabled(),
                'info' => (function_exists('apcu_cache_info') && function_exists('apcu_enabled') && apcu_enabled()) ? @apcu_cache_info(true) : null
            ],
            'litespeed' => [
                'enabled' => $this->useLiteSpeed,
                'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
            ]
        ];
        
        return $status;
    }
    
    /**
     * Berechne Größe des Cache-Verzeichnisses
     */
    private function getCacheDirSize(): string
    {
        $bytes = 0;
        $files = glob($this->cacheDir . '*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                $size = filesize($file);
                $bytes += $size === false ? 0 : $size;
            }
        }
        
        // Formatieren (B, KB, MB, GB)
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    /**
     * Set LiteSpeed Cache Control Header
     */
    public function setCacheHeaders(int $ttl = 300, bool $private = false): void
    {
        if ($this->useLiteSpeed) {
            $type = $private ? 'private' : 'public';
            header("X-LiteSpeed-Cache-Control: {$type}, max-age={$ttl}");
        } else {
            // Fallback for standard browsers/proxies
            $ts = gmdate("D, d M Y H:i:s", time() + $ttl) . " GMT";
            header("Expires: $ts");
            header("Pragma: cache");
            header("Cache-Control: max-age=$ttl");
        }
    }

    /**
     * Sendet zentrale Response-Header für öffentliche und private Antworten.
     */
    public function sendResponseHeaders(string $profile = 'public', int $ttl = 300): void
    {
        if (headers_sent()) {
            return;
        }

        $this->applyResponseHeaders($this->buildResponseHeaders($profile, $ttl));
    }

    /**
     * @return array<string, string|array<int, string>|null>
     */
    private function buildResponseHeaders(string $profile = 'public', int $ttl = 300): array
    {
        if ($profile === 'private') {
            return [
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'Surrogate-Control' => 'no-store',
                'Vary' => ['Accept-Encoding', 'Cookie'],
                'X-LiteSpeed-Cache-Control' => $this->useLiteSpeed ? 'no-cache, no-store' : null,
            ];
        }

        if ($profile === 'public_no_cache') {
            return [
                'Cache-Control' => 'public, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'Surrogate-Control' => 'no-store',
                'Vary' => ['Accept-Encoding'],
                'X-LiteSpeed-Cache-Control' => $this->useLiteSpeed ? 'no-cache' : null,
            ];
        }

        $ttl = max(60, $ttl);
        $expires = gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT';

        return [
            'Cache-Control' => 'public, max-age=' . $ttl . ', s-maxage=' . $ttl . ', stale-while-revalidate=60, stale-if-error=300',
            'Expires' => $expires,
            'Surrogate-Control' => 'max-age=' . $ttl . ', stale-while-revalidate=60, stale-if-error=300',
            'Vary' => ['Accept-Encoding', 'Cookie'],
            'X-LiteSpeed-Cache-Control' => $this->useLiteSpeed ? 'public, max-age=' . $ttl : null,
        ];
    }

    /**
     * @param array<string, string|array<int, string>|null> $headers
     */
    private function applyResponseHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            if ($value === null) {
                continue;
            }

            if ($name === 'Vary' && is_array($value)) {
                $this->appendTokenHeader($name, $value);
                continue;
            }

            if (is_string($value) && $value !== '') {
                header($name . ': ' . $value);
            }
        }
    }

    /**
     * @param array<int, string> $tokens
     */
    private function appendTokenHeader(string $name, array $tokens): void
    {
        $merged = $this->mergeHeaderTokenList(
            $this->getCurrentHeaderValues($name),
            $tokens
        );

        if ($merged === []) {
            return;
        }

        header_remove($name);
        header($name . ': ' . implode(', ', $merged));
    }

    /**
     * @return array<int, string>
     */
    private function getCurrentHeaderValues(string $name): array
    {
        $values = [];

        foreach (headers_list() as $headerLine) {
            if (stripos($headerLine, $name . ':') !== 0) {
                continue;
            }

            $rawValue = trim(substr($headerLine, strlen($name) + 1));
            if ($rawValue === '') {
                continue;
            }

            foreach (explode(',', $rawValue) as $token) {
                $token = trim($token);
                if ($token !== '') {
                    $values[] = $token;
                }
            }
        }

        return $values;
    }

    /**
     * @param array<int, string> ...$lists
     * @return array<int, string>
     */
    private function mergeHeaderTokenList(array ...$lists): array
    {
        $merged = [];
        $seen = [];

        foreach ($lists as $list) {
            foreach ($list as $token) {
                $normalized = strtolower(trim($token));
                if ($normalized === '' || isset($seen[$normalized])) {
                    continue;
                }

                $seen[$normalized] = true;
                $merged[] = trim($token);
            }
        }

        return $merged;
    }

    /**
     * Sendet ETag-/Last-Modified-Header für öffentliche Ressourcen und
     * beantwortet bedingte GET-/HEAD-Requests bei unverändertem Stand mit 304.
     *
     * @return bool true = Response kann normal weiterlaufen, false = 304 gesendet
     */
    public function sendConditionalHeaders(string $resourceKey, int|string|null $lastModified): bool
    {
        if (headers_sent()) {
            return true;
        }

        $timestamp = $this->normalizeLastModifiedTimestamp($lastModified);
        if ($timestamp === null) {
            return true;
        }

        $etag = '"' . substr(hash('sha512', $resourceKey . '|' . $timestamp), 0, 48) . '"';
        $lastModifiedHeader = gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';

        header('ETag: ' . $etag);
        header('Last-Modified: ' . $lastModifiedHeader);

        $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            $this->sendNotModified();
            return false;
        }

        $ifModifiedSince = trim((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if ($ifModifiedSince !== '') {
            $ifModifiedSinceTs = strtotime($ifModifiedSince);
            if ($ifModifiedSinceTs !== false && $ifModifiedSinceTs >= $timestamp) {
                $this->sendNotModified();
                return false;
            }
        }

        return true;
    }

    /**
     * 304-Antworten dürfen keine neue CSP mitsenden: Browser übernehmen Header aus
     * dem 304 in die gecachte Antwort, deren Inline-Nonces dann nicht mehr passen würden.
     */
    private function sendNotModified(): void
    {
        http_response_code(304);
        header_remove('Content-Security-Policy');
        header_remove('Content-Security-Policy-Report-Only');
    }

    private function normalizeLastModifiedTimestamp(int|string|null $lastModified): ?int
    {
        if (is_int($lastModified)) {
            return $lastModified > 0 ? $lastModified : null;
        }

        if (!is_string($lastModified) || trim($lastModified) === '') {
            return null;
        }

        $timestamp = strtotime($lastModified);
        return $timestamp !== false && $timestamp > 0 ? $timestamp : null;
    }

    private function getCacheFile(string $key): string
    {
        return $this->cacheDir . hash('sha512', $key) . '.cache';
    }

    /**
     * M-05: Erzeugt einen präfixierten APCu-Schlüssel um Namespace-Konflikte
     * mit anderen Anwendungen auf demselben Server zu vermeiden.
     */
    private function apcuKey(string $key): string
    {
        return '365cms:' . hash('sha256', $key);
    }
}
