<?php
/**
 * PSR-3 kompatibles Logging-System
 *
 * Ersetzt alle direkten `error_log()`-Aufrufe durch strukturiertes,
 * level-basiertes Logging mit optionaler AuditLogger-Integration.
 *
 * Log-Levels (PSR-3):
 *   emergency | alert | critical | error | warning | notice | info | debug
 *
 * Konfiguration (Konstanten aus config/app.php):
 *   LOG_PATH   – Verzeichnis für Log-Dateien (Standard: ABSPATH . 'logs/')
 *   LOG_LEVEL  – Minimaler Level der geloggt wird (Standard: 'warning')
 *   CMS_DEBUG  – true → debug-Level aktiv, Ausgabe auch auf STDERR
 *
 * @package CMSv2\Core
 * @see     https://www.php-fig.org/psr/psr-3/
 */

declare(strict_types=1);

namespace CMS;

use CMS\Contracts\LoggerInterface;

if (!defined('ABSPATH')) {
    exit;
}

class Logger implements LoggerInterface
{
    /**
     * Muster für Kontext-/Metadatenschlüssel mit Zugangsdaten (Eingabe: normalisierter snake_case-Schlüssel, siehe isSensitiveKey()).
     * Eindeutige Wörter wirken als Teilstring, mehrdeutige (auth, session, pass, pwd, mfa, otp, jwt, …) nur als ganzer Namensteil.
     * Jeder Schlüssel auf `_key` gilt als Zugangsdatum, außer bekannte Bezeichner-Felder (cache_key, setting_key, form_key, …).
     */
    public const SENSITIVE_KEY_PATTERN = '/password|passwd|passphrase|secret|token|nonce|csrf|credential|cookie|credit_card|authorization|apikey|privatekey|(?:^|_)(?:pass|pwd|mfa|otp|hmac|jwt|bearer|signature)(?:_|$)|(?:^|_)(?!(?:cache|setting|form|template|meta|count|font|feature|status|source|scope|usage|assignment|option|field|config|lang|translation|route|sort|group|section|item|state|type|name|id)_key$)[a-z0-9]+_key$|(?:^|_)(?:session_id|sessionid)$|^(?:key|auth|session)$/';

    // ── PSR-3 Log-Level-Konstanten (aufsteigend nach Gewicht) ──────────────

    public const EMERGENCY = 'emergency'; // System ist unbrauchbar
    public const ALERT     = 'alert';     // Sofortige Maßnahme erforderlich
    public const CRITICAL  = 'critical';  // Kritische Bedingung
    public const ERROR     = 'error';     // Laufzeitfehler
    public const WARNING   = 'warning';   // Warnung (kein Fehler, aber ungewöhnlich)
    public const NOTICE    = 'notice';    // Normale, aber beachtenswerte Ereignisse
    public const INFO      = 'info';      // Informationsmeldungen
    public const DEBUG     = 'debug';     // Detaillierte Debugging-Informationen

    /** Gewichtung der Log-Level für Schwellenwert-Vergleiche */
    private const LEVEL_WEIGHT = [
        self::DEBUG     => 0,
        self::INFO      => 1,
        self::NOTICE    => 2,
        self::WARNING   => 3,
        self::ERROR     => 4,
        self::CRITICAL  => 5,
        self::ALERT     => 6,
        self::EMERGENCY => 7,
    ];

    /** Level ab dem in den AuditLogger gespiegelt wird (CRITICAL+) */
    private const AUDIT_MIN_LEVEL = self::CRITICAL;

    // ── Singleton ────────────────────────────────────────────────────────────

    private static ?self $instance = null;

    /** Absoluter Pfad zum Log-Verzeichnis */
    private string $logPath;

    /** Minimaler Log-Level (alles darunter wird ignoriert) */
    private string $minLevel;

    /** Channel-Name für Kontext-Identifikation */
    private string $channel;

    public static function instance(string $channel = 'cms'): self
    {
        if (self::$instance === null) {
            self::$instance = new self($channel);
        }
        return self::$instance;
    }

    private function __construct(string $channel = 'cms')
    {
        $this->channel  = $this->sanitizeChannel($channel);
        $this->logPath  = defined('LOG_PATH')  ? LOG_PATH  : (ABSPATH . 'logs/');
        $this->minLevel = defined('LOG_LEVEL') ? LOG_LEVEL : (CMS_DEBUG ? self::DEBUG : self::WARNING);

        // Sicherstellen dass das Log-Verzeichnis existiert
        if (!is_dir($this->logPath)) {
            // Race-Condition-sicher: mkdir kann scheitern wenn Verzeichnis parallel angelegt wurde
            if (!mkdir($this->logPath, 0750, true) && !is_dir($this->logPath)) {
                error_log('Logger: Log-Verzeichnis konnte nicht erstellt werden: ' . $this->logPath);
            }
        }
    }

    // ── PSR-3 convenience methods ─────────────────────────────────────────

    public function emergency(string $message, array $context = []): void
    {
        $this->log(self::EMERGENCY, $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log(self::ALERT, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log(self::NOTICE, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    // ── Kern-Log-Methode ──────────────────────────────────────────────────

    /**
     * Schreibt einen Log-Eintrag.
     *
     * @param string               $level   PSR-3-Level (siehe Konstanten)
     * @param string               $message Nachrichtentext; {key} wird durch $context[$key] ersetzt
     * @param array<string, mixed> $context Kontextdaten; 'exception' wird als Throwable ausgewertet
     */
    public function log(string $level, string $message, array $context = []): void
    {
        // Level-Filter
        if (!$this->isLevelEnabled($level)) {
            return;
        }

        // Message interpolation (PSR-3 §1.2)
        $message = $this->interpolate($message, $context);
        $message = $this->sanitizeLogLine($this->maskInlineSecrets($message));

        // Exception anhängen – ebenfalls bereinigt (keine Zeilenumbrüche/Log-Injection, keine Geheimnisse)
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $e       = $context['exception'];
            $message .= ' | Exception: ' . get_class($e) . ': ' . $this->sanitizeLogLine($this->maskInlineSecrets($e->getMessage()))
                . ' in ' . $this->sanitizeLogLine($e->getFile()) . ':' . $e->getLine();
        }

        // Formatierte Log-Zeile
        $line = sprintf(
            '[%s] [%s] [%s] %s%s',
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $this->channel,
            $message,
            !empty($context) ? ' ' . $this->formatContext($context) : ''
        );

        // Datei-Log (tagesweise, z. B. logs/cms-2026-02-22.log)
        $file = $this->logPath . $this->channel . '-' . date('Y-m-d') . '.log';
        // M-03: Kein @ – explizites Fehler-Handling statt stiller Unterdrückung
        $written = file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            // Fallback auf error_log damit der Log-Eintrag nicht verloren geht
            error_log('[Logger-Fallback] ' . $line);
        }

        // Im Debug-Modus zusätzlich auf STDERR ausgeben
        if (defined('CMS_DEBUG') && CMS_DEBUG) {
            file_put_contents('php://stderr', $line . PHP_EOL);
        }

        // Kritische+ Level auch in AuditLogger spiegeln (wenn verfügbar)
        if ($this->isAboveOrEqual($level, self::AUDIT_MIN_LEVEL) && class_exists(AuditLogger::class)) {
            try {
                AuditLogger::instance()->log(
                    AuditLogger::CAT_SYSTEM,
                    'log_' . $level,
                    $message,
                    '',
                    null,
                    $this->sanitizeContext($context),
                    $level
                );
            } catch (\Throwable) {
                // AuditLogger-Fehler darf den Hauptablauf nicht unterbrechen
            }
        }
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────

    /**
     * Prüft ob ein Level aktuell aktiv ist (über dem Schwellenwert).
     */
    public function isLevelEnabled(string $level): bool
    {
        return $this->isAboveOrEqual($level, $this->minLevel);
    }

    /**
     * Erzeugt einen neuen Logger mit eigenem Channel.
     * Teilt denselben Singleton-Pfad, aber eigenen Channel-Namen.
     */
    public function withChannel(string $channel): self
    {
        $clone          = clone $this;
        $clone->channel = $this->sanitizeChannel($channel);
        return $clone;
    }

    // ── Private Helfer ────────────────────────────────────────────────────

    private function isAboveOrEqual(string $level, string $minLevel): bool
    {
        $weight    = self::LEVEL_WEIGHT[$level]    ?? 0;
        $minWeight = self::LEVEL_WEIGHT[$minLevel] ?? 0;
        return $weight >= $minWeight;
    }

    /**
     * PSR-3-konforme Message-Interpolation: {key} → context[key].
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_string($val) || is_numeric($val)) {
                $replace['{' . $key . '}'] = (string)$val;
            }
        }
        return strtr($message, $replace);
    }

    /**
     * Kontext-Array in lesbares Format umwandeln (ohne sensible Daten).
     */
    private function formatContext(array $context): string
    {
        $safe = $this->sanitizeContext($context);
        return json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    /**
     * Sensible Schlüssel aus dem Kontext entfernen vor Ausgabe/Speicher.
     */
    private function sanitizeContext(array $context): array
    {
        foreach ($context as $k => $v) {
            if (self::isSensitiveKey($k)) {
                $context[$k] = '***';
            } elseif ($v instanceof \Throwable) {
                // Exceptions in serialisierbares Format umwandeln
                $context[$k] = get_class($v) . ': ' . $this->sanitizeLogLine($this->maskInlineSecrets($v->getMessage()));
            } elseif (is_string($v)) {
                $context[$k] = $this->sanitizeLogLine($this->maskInlineSecrets($v));
            } elseif (is_array($v)) {
                $context[$k] = $this->sanitizeContext($v);
            }
        }
        return $context;
    }

    /**
     * Schlüssel mit Zugangsdaten (auch für AuditLogger-Metadaten). Geprüft werden ganze Namensteile
     * (snake_case, camelCase, Bindestriche), damit z. B. `cache_key`, `author_id`, `keyword` oder
     * `session_timeout` lesbar bleiben; `password`, `api_key`, `csrfToken`, `session_id`, `key` bleiben maskiert.
     */
    public static function isSensitiveKey(int|string $key): bool
    {
        $normalized = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string) $key) ?? (string) $key);
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? $normalized;

        return preg_match(self::SENSITIVE_KEY_PATTERN, trim($normalized, '_')) === 1;
    }

    private function sanitizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        $channel = preg_replace('/[^a-z0-9_.-]/', '-', $channel) ?? '';
        $channel = trim($channel, '.-');

        return $channel !== '' ? substr($channel, 0, 80) : 'cms';
    }

    private function sanitizeLogLine(string $value): string
    {
        // Ungültiges UTF-8 (z. B. Latin-1-Dateinamen) ersetzen statt die ganze Meldung zu verlieren.
        if (function_exists('mb_scrub')) {
            $value = mb_scrub($value, 'UTF-8');
        }
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? (preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? $value);

        return trim($value);
    }

    private function maskInlineSecrets(string $value): string
    {
        $value = preg_replace('/([?&](?:token|csrf_token|nonce|key|secret|password|pass)=)[^&\s]+/i', '$1***', $value) ?? $value;
        $value = preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/-]+=*/i', '$1***', $value) ?? $value;
        $value = preg_replace('/\b(password|passwd|secret|token|credential|api[_-]?key)(\s*[=:]\s*)[^\s&]+/i', '$1$2***', $value) ?? $value;

        return $value;
    }
}
