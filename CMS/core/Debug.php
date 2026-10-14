<?php
declare(strict_types=1);

namespace CMS;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Debug-System für Admin-Bereiche
 * Zeigt detaillierte Fehler und Ablauf-Informationen
 */
class Debug {
    
    private static bool $enabled = false; // enable via Debug::enable(CMS_DEBUG)
    private static bool $runtimeProfileActive = false;
    private static array $logs = [];
    private static array $queries = [];
    private static array $checkpoints = [];
    private static array $runtimeMeta = [];
    private static float $start_time;
    private const MAX_STORED_QUERIES = 100;
    private const SLOW_QUERY_THRESHOLD_MS = 75.0;
    private const SLOW_PHASE_THRESHOLD_MS = 75.0;
    
    /**
     * Debug-Modus aktivieren/deaktivieren
     */
    public static function enable(bool $enable = true): void {
        self::$enabled = $enable;

        if ($enable) {
            self::startTimer();
        }
    }
    
    /**
     * Ist Debug-Modus aktiv?
     */
    public static function isEnabled(): bool {
        return self::$enabled;
    }
    
    /**
     * Timer starten
     */
    public static function startTimer(): void {
        self::$start_time = microtime(true);
    }

    /**
     * Diagnostische Laufzeitdaten zurücksetzen.
     */
    public static function resetRuntimeProfile(array $meta = []): void {
        self::$logs = [];
        self::$queries = [];
        self::$checkpoints = [];
        self::$runtimeMeta = $meta;
        self::$runtimeProfileActive = true;
        self::startTimer();
    }
    
    /**
     * Verstrichene Zeit seit Timer-Start
     */
    public static function getElapsedTime(): float {
        if (!isset(self::$start_time)) {
            self::startTimer();
            return 0.0;
        }
        return microtime(true) - self::$start_time;
    }
    
    /**
     * Debug-Log hinzufügen
     */
    public static function log(string $message, string $type = 'info', mixed $data = null): void {
        if (!self::$enabled) {
            return;
        }

        $message = self::sanitizeDiagnosticText($message);
        $data = self::redactDiagnosticValue($data);
        
        $entry = [
            'time' => microtime(true),
            'type' => $type,
            'message' => $message,
            'data' => $data,
            'memory' => memory_get_usage(true),
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)
        ];
        
        self::$logs[] = $entry;
        
        // In /logs/debug-YYYY-MM-DD.log schreiben (nur wenn DEBUG aktiv)
        self::writeToFile($type, $message, $data);
    }
    
    /**
     * Schreibt einen Log-Eintrag in /logs/debug-YYYY-MM-DD.log
     */
    private static function writeToFile(string $type, string $message, mixed $data = null): void {
        if (!self::$enabled) {
            return;
        }
        
        $logDir = defined('ABSPATH') ? ABSPATH . 'logs' : sys_get_temp_dir();
        
        // Verzeichnis anlegen falls nicht vorhanden
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0750, true) && !is_dir($logDir)) {
                // Kein error_log hier um Rekursion zu vermeiden
                return;
            }
        }
        
        $logFile = $logDir . '/debug-' . date('Y-m-d') . '.log';
        
        $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($type) . '] ' . $message;
        
        if ($data !== null) {
            $line .= ' | ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        
        $line .= PHP_EOL;
        
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    private static function sanitizeDiagnosticText(string $value, int $maxLength = 1000): string {
        $value = preg_replace('/([?&](?:token|csrf_token|nonce|key|secret|password|pass)=)[^&\s]+/i', '$1***', $value) ?? $value;
        $value = preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/-]+=*/i', '$1***', $value) ?? $value;
        $value = preg_replace('/(password|passwd|secret|token|credential|api[_-]?key)(\s*[=:]\s*)\S+/i', '$1$2***', $value) ?? $value;
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
    }

    private static function redactDiagnosticValue(mixed $value, string $key = ''): mixed {
        if (preg_match('/(password|passwd|secret|token|nonce|csrf|auth|mfa|key|credential|cookie|session)/i', $key) === 1) {
            return '***';
        }

        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $childKey => $childValue) {
                $redacted[$childKey] = self::redactDiagnosticValue($childValue, (string)$childKey);
            }

            return $redacted;
        }

        if (is_string($value)) {
            return self::sanitizeDiagnosticText($value, 2000);
        }

        if ($value instanceof \Throwable) {
            return $value::class;
        }

        return $value;
    }
    
    /**
     * Erfolg loggen
     */
    public static function success(string $message, mixed $data = null): void {
        self::log($message, 'success', $data);
    }
    
    /**
     * Warnung loggen
     */
    public static function warning(string $message, mixed $data = null): void {
        self::log($message, 'warning', $data);
    }
    
    /**
     * Fehler loggen
     */
    public static function error(string $message, mixed $data = null): void {
        self::log($message, 'error', $data);
    }
    
    /**
     * Exception loggen
     */
    public static function exception(\Throwable $e, string $context = ''): void {
        $message = $context ? "$context: {$e->getMessage()}" : $e->getMessage();
        
        self::log($message, 'exception', [
            'class' => get_class($e),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }
    
    /**
     * Alle Logs abrufen
     */
    public static function getLogs(): array {
        return self::$logs;
    }
    
    /**
     * Logs für JSON-Response vorbereiten
     */
    public static function getLogsForJson(): array {
        return array_map(function($entry) {
            return [
                'time' => round($entry['time'] - (self::$start_time ?? $entry['time']), 4),
                'type' => $entry['type'],
                'message' => $entry['message'],
                'data' => $entry['data'],
                'memory_mb' => round($entry['memory'] / 1024 / 1024, 2)
            ];
        }, self::$logs);
    }

    /**
     * Runtime-Messpunkt setzen.
     */
    public static function checkpoint(string $label, ?array $context = null): void {
        if (!self::$enabled && !self::$runtimeProfileActive) {
            return;
        }

        self::$checkpoints[] = [
            'label' => $label,
            'time_ms' => round(self::getElapsedTime() * 1000, 2),
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'context' => $context,
        ];
    }

    /**
     * Query-Telemetrie für Diagnoseansichten liefern.
     */
    public static function getQueryTelemetry(): array {
        $queries = self::$queries;
        usort($queries, static function (array $left, array $right): int {
            return ($right['execution_time_ms'] ?? 0) <=> ($left['execution_time_ms'] ?? 0);
        });

        $totalTime = 0.0;
        $slowCount = 0;
        foreach (self::$queries as $query) {
            $duration = (float)($query['execution_time_ms'] ?? 0.0);
            $totalTime += $duration;
            if ($duration >= self::SLOW_QUERY_THRESHOLD_MS) {
                $slowCount++;
            }
        }

        return [
            'count' => count(self::$queries),
            'total_time_ms' => round($totalTime, 2),
            'slow_count' => $slowCount,
            'slow_threshold_ms' => self::SLOW_QUERY_THRESHOLD_MS,
            'queries' => $queries,
        ];
    }

    /**
     * Snapshot der aktuellen Debug-Runtime.
     */
    public static function getRuntimeTelemetry(): array {
        $queryTelemetry = self::getQueryTelemetry();

        return [
            'enabled' => self::$enabled,
            'profile_active' => self::$runtimeProfileActive,
            'profile_meta' => self::$runtimeMeta,
            'elapsed_time_ms' => round(self::getElapsedTime() * 1000, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'memory_current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'log_count' => count(self::$logs),
            'checkpoints' => self::$checkpoints,
            'query' => $queryTelemetry,
            'bootstrap' => self::getBootstrapTelemetry(),
        ];
    }

    /**
     * Verdichtetes Bootstrap-Profil für Cold-Path-Analyse liefern.
     */
    private static function getBootstrapTelemetry(): array {
        $checkpoints = array_values(array_filter(
            self::$checkpoints,
            static fn(array $checkpoint): bool => str_starts_with((string)($checkpoint['label'] ?? ''), 'bootstrap.')
        ));

        $mode = is_string(self::$runtimeMeta['mode'] ?? null) ? (string)self::$runtimeMeta['mode'] : 'unknown';
        foreach ($checkpoints as $checkpoint) {
            $contextMode = $checkpoint['context']['mode'] ?? null;
            if ($mode === 'unknown' && is_string($contextMode) && $contextMode !== '') {
                $mode = $contextMode;
                break;
            }
        }

        $readyMs = null;
        $lastBootstrapMs = null;
        foreach ($checkpoints as $checkpoint) {
            $timeMs = (float)($checkpoint['time_ms'] ?? 0.0);
            $lastBootstrapMs = $timeMs;
            if (($checkpoint['label'] ?? '') === 'bootstrap.ready') {
                $readyMs = $timeMs;
            }
        }

        $phases = [];
        for ($index = 1, $count = count($checkpoints); $index < $count; $index++) {
            $previous = $checkpoints[$index - 1];
            $current = $checkpoints[$index];
            $deltaMs = round((float)($current['time_ms'] ?? 0.0) - (float)($previous['time_ms'] ?? 0.0), 2);

            $phases[] = [
                'from' => (string)($previous['label'] ?? ''),
                'to' => (string)($current['label'] ?? ''),
                'delta_ms' => max(0.0, $deltaMs),
                'time_ms' => (float)($current['time_ms'] ?? 0.0),
                'memory_mb' => (float)($current['memory_mb'] ?? 0.0),
                'context' => is_array($current['context'] ?? null) ? $current['context'] : [],
            ];
        }

        $topPhases = $phases;
        usort($topPhases, static fn(array $left, array $right): int => ($right['delta_ms'] ?? 0) <=> ($left['delta_ms'] ?? 0));
        $topPhases = array_slice($topPhases, 0, 5);

        $slowPhaseCount = 0;
        foreach ($phases as $phase) {
            if ((float)($phase['delta_ms'] ?? 0.0) >= self::SLOW_PHASE_THRESHOLD_MS) {
                $slowPhaseCount++;
            }
        }

        $elapsedMs = round(self::getElapsedTime() * 1000, 2);
        $bootstrapEndMs = $readyMs ?? $lastBootstrapMs;
        $postBootstrapMs = $bootstrapEndMs !== null ? round(max(0.0, $elapsedMs - $bootstrapEndMs), 2) : null;
        $coldPathShare = ($bootstrapEndMs !== null && $elapsedMs > 0.0)
            ? round(($bootstrapEndMs / $elapsedMs) * 100, 1)
            : null;

        return [
            'active' => self::$runtimeProfileActive,
            'mode' => $mode,
            'request_uri' => is_string(self::$runtimeMeta['request_uri'] ?? null) ? self::$runtimeMeta['request_uri'] : null,
            'request_method' => is_string(self::$runtimeMeta['request_method'] ?? null) ? self::$runtimeMeta['request_method'] : null,
            'sapi' => is_string(self::$runtimeMeta['sapi'] ?? null) ? self::$runtimeMeta['sapi'] : PHP_SAPI,
            'checkpoint_count' => count($checkpoints),
            'phase_count' => count($phases),
            'bootstrap_ready_ms' => $readyMs,
            'bootstrap_last_checkpoint_ms' => $lastBootstrapMs,
            'total_elapsed_ms' => $elapsedMs,
            'post_bootstrap_ms' => $postBootstrapMs,
            'cold_path_share_percent' => $coldPathShare,
            'slow_phase_threshold_ms' => self::SLOW_PHASE_THRESHOLD_MS,
            'slow_phase_count' => $slowPhaseCount,
            'timeline' => $checkpoints,
            'phases' => $phases,
            'top_phases' => $topPhases,
        ];
    }
    
    /**
     * Debug-Info als HTML ausgeben
     */
    public static function renderHtml(bool $collapsed = true): string {
        if (!self::$enabled || empty(self::$logs)) {
            return '';
        }

        $reportTarget = (defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '') . '/admin/error-report';
        $reportToken = class_exists('\\CMS\\Security') ? \CMS\Security::instance()->generateToken('admin_error_report') : '';
        $backTo = (string)($_SERVER['REQUEST_URI'] ?? '/admin/diagnose');
        
        // <details>/<summary> statt Inline-onclick: funktioniert ohne JavaScript und unter der CSP.
        $html = '<details class="debug-panel"' . ($collapsed ? '' : ' open') . '>';
        $html .= '<summary class="debug-header" style="background: #1e293b; color: white; padding: 0.75rem; cursor: pointer; font-weight: 600;">';
        $html .= '<i class="fas fa-bug" aria-hidden="true"></i> Debug-Informationen (' . count(self::$logs) . ' Einträge) – Klick zum Ausklappen';
        $html .= '</summary>';
        $html .= '<div class="debug-content" style="background: #f8fafc; border: 2px solid #1e293b; padding: 1rem; font-family: monospace; font-size: 0.875rem;">';
        
        foreach (self::$logs as $entry) {
            $color = match($entry['type']) {
                'success' => '#10b981',
                'warning' => '#f59e0b',
                'error' => '#ef4444',
                'exception' => '#dc2626',
                default => '#64748b'
            };
            
            $html .= '<div style="margin-bottom: 0.5rem; padding: 0.5rem; background: white; border-left: 4px solid ' . $color . ';">';
            $html .= '<strong style="color: ' . $color . ';">[' . strtoupper($entry['type']) . ']</strong> ';
            $html .= htmlspecialchars($entry['message']);
            
            if ($entry['data'] !== null) {
                if (is_array($entry['data'])) {
                    $metaParts = [];
                    if (isset($entry['data']['code']) && $entry['data']['code'] !== '') {
                        $metaParts[] = '<div><strong>Code:</strong> <code>' . htmlspecialchars((string)$entry['data']['code']) . '</code></div>';
                    }
                    if (isset($entry['data']['file']) && isset($entry['data']['line'])) {
                        $metaParts[] = '<div><strong>Datei:</strong> ' . htmlspecialchars((string)$entry['data']['file']) . ':' . htmlspecialchars((string)$entry['data']['line']) . '</div>';
                    }
                    if ($metaParts !== []) {
                        $html .= '<div style="margin-top: 0.5rem; font-size: 0.8125rem;">' . implode('', $metaParts) . '</div>';
                    }
                }
                $html .= '<pre style="margin: 0.5rem 0 0 0; padding: 0.5rem; background: #f1f5f9; overflow-x: auto;">';
                $debugDataJson = json_encode($entry['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $html .= htmlspecialchars(is_string($debugDataJson) ? $debugDataJson : '[nicht serialisierbar]');
                $html .= '</pre>';
            }

            if ($reportToken !== '' && in_array($entry['type'], ['error', 'exception'], true)) {
                $errorCode = is_array($entry['data']) && isset($entry['data']['code']) ? (string)$entry['data']['code'] : '';
                $errorDataJson = htmlspecialchars((string)json_encode(is_array($entry['data']) ? $entry['data'] : ['data' => $entry['data']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
                $contextJson = htmlspecialchars((string)json_encode([
                    'type' => $entry['type'],
                    'memory' => $entry['memory'] ?? null,
                    'backtrace' => $entry['backtrace'] ?? [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);

                $html .= '<form method="post" action="' . htmlspecialchars($reportTarget, ENT_QUOTES) . '" style="margin-top:0.75rem; display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">';
                $html .= '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($reportToken, ENT_QUOTES) . '">';
                $html .= '<input type="hidden" name="back_to" value="' . htmlspecialchars($backTo, ENT_QUOTES) . '">';
                $html .= '<input type="hidden" name="title" value="' . htmlspecialchars('Debug-Fehlerreport · ' . strtoupper($entry['type']), ENT_QUOTES) . '">';
                $html .= '<input type="hidden" name="message" value="' . htmlspecialchars((string)$entry['message'], ENT_QUOTES) . '">';
                $html .= '<input type="hidden" name="error_code" value="' . htmlspecialchars($errorCode, ENT_QUOTES) . '">';
                $html .= '<input type="hidden" name="source_url" value="' . htmlspecialchars($backTo, ENT_QUOTES) . '">';
                $html .= '<input type="hidden" name="error_data_json" value="' . $errorDataJson . '">';
                $html .= '<input type="hidden" name="context_json" value="' . $contextJson . '">';
                $html .= '<button type="submit" style="border:1px solid #94a3b8; background:#fff; color:#0f172a; padding:0.4rem 0.7rem; border-radius:0.45rem; cursor:pointer;">Report erstellen</button>';
                $html .= '<span style="font-size:0.8rem; color:#475569;">Speichert den Fehler als Report im Adminbereich.</span>';
                $html .= '</form>';
            }
            
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</details>';
        
        return $html;
    }
    
    /**
     * AJAX-Response mit Debug-Infos erweitern
     */
    public static function enhanceAjaxResponse(array $response): array {
        if (!self::$enabled) {
            return $response;
        }
        
        $response['_debug'] = [
            'enabled' => true,
            'logs' => self::getLogsForJson(),
            'elapsed_time' => round(self::getElapsedTime(), 4),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        return $response;
    }
    
    /**
     * Datenbank-Query loggen
     */
    public static function query(string $sql, ?array $params = null, float $execution_time = 0): void {
        $executionTimeMs = round($execution_time * 1000, 2);
        $payload = [
            'sql' => $sql,
            'params' => $params,
            'execution_time_ms' => $executionTimeMs
        ];

        if (self::$enabled) {
            self::$queries[] = [
                'sql' => preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql),
                'params' => $params,
                'execution_time_ms' => (float)($payload['execution_time_ms'] ?? 0.0),
            ];

            if (count(self::$queries) > self::MAX_STORED_QUERIES) {
                self::$queries = array_slice(self::$queries, -self::MAX_STORED_QUERIES);
            }
        }

        $logAllQueries = defined('CMS_DEBUG_LOG_ALL_QUERIES') && CMS_DEBUG_LOG_ALL_QUERIES;
        if ($logAllQueries || $executionTimeMs >= self::SLOW_QUERY_THRESHOLD_MS) {
            self::log('SQL Query', $executionTimeMs >= self::SLOW_QUERY_THRESHOLD_MS ? 'warning' : 'info', $payload);
        }
    }
    
    /**
     * Service-Initialisierung loggen
     */
    public static function serviceInit(string $service_name): void {
        self::success("Service initialisiert: $service_name");
    }
    
    /**
     * Service-Methoden-Aufruf loggen
     */
    public static function serviceCall(string $service_name, string $method, ?array $params = null): void {
        self::log("Service-Call: $service_name::$method", 'info', $params);
    }
    
    /**
     * Logs löschen
     */
    public static function clear(): void {
        self::resetRuntimeProfile();
    }
}
