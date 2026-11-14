<?php
declare(strict_types=1);

namespace CMS\Services;

use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class OpcacheWarmupService
{
    private const DEFAULT_LIMIT = 30;
    private const STATE_FILE = ABSPATH . 'cache/opcache-warmup.json';
    private const CHECK_MARKER_FILE = ABSPATH . 'cache/opcache-warmup.checked';
    /** Mindestabstand der Deploy-Prüfung: Der Signatur-Scan läuft über den gesamten Code-Baum. */
    private const CHECK_INTERVAL_SECONDS = 600;
    private const ALLOWED_TOP_LEVEL_DIRECTORIES = [
        'admin',
        'assets',
        'config',
        'core',
        'db',
        'includes',
        'install',
        'lang',
        'member',
        'plugins',
        'themes',
        'views',
    ];
    private const EXCLUDED_SEGMENTS = [
        DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . 'BACKUP' . DIRECTORY_SEPARATOR,
        // Test-, Fixture- und Beispielcode deklariert häufig Stub-Klassen mit denselben Namen wie
        // der Laufzeitcode (z. B. themes/<theme>/tests/*.php). Ein Vorkompilieren solcher Dateien
        // führt zu "Cannot redeclare class …", sobald die echte Klasse geladen wird.
        DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . 'test' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . 'examples' . DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR,
    ];

    private static ?self $instance = null;

    /** @var array<string, mixed>|null */
    private ?array $cachedStatus = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * Führt einmalig pro Dateisignatur einen OPcache-Warmup der größten PHP-Dateien aus.
     * Gedacht als Nach-Deploy-Schutznetz für den ersten Laufzeitkontakt.
     *
     * @return array<string, mixed>
     */
    public function maybeWarmAfterDeploy(int $limit = self::DEFAULT_LIMIT): array
    {
        // Der Signatur-Scan durchläuft bei jedem Aufruf alle PHP-Dateien (auf Shared Hosting spürbar
        // in der Antwortzeit). Deploys werden deshalb höchstens alle 10 Minuten erkannt.
        $lastCheck = is_file(self::CHECK_MARKER_FILE) ? (int) @filemtime(self::CHECK_MARKER_FILE) : 0;
        if ($lastCheck > 0 && (time() - $lastCheck) < self::CHECK_INTERVAL_SECONDS) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => 'OPcache-Warmup-Prüfung kürzlich erfolgt.',
                'compiled' => 0,
                'failed' => [],
            ];
        }

        $markerDir = dirname(self::CHECK_MARKER_FILE);
        if (is_dir($markerDir) || @mkdir($markerDir, 0755, true)) {
            @touch(self::CHECK_MARKER_FILE);
        }

        $status = $this->getStatus($limit);
        if (!($status['available'] ?? false)) {
            return [
                'success' => false,
                'skipped' => true,
                'message' => (string)($status['message'] ?? 'OPcache-Warmup ist nicht verfügbar.'),
                'compiled' => 0,
                'failed' => [],
            ];
        }

        if (($status['is_current'] ?? false) === true) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => 'OPcache-Warmup bereits aktuell.',
                'compiled' => 0,
                'failed' => [],
            ];
        }

        return $this->warmTopFiles($limit, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function warmTopFiles(int $limit = self::DEFAULT_LIMIT, bool $force = true): array
    {
        $limit = max(1, $limit);
        if (!$this->isOpcacheAvailable()) {
            return [
                'success' => false,
                'compiled' => 0,
                'failed' => [],
                'message' => 'OPcache oder opcache_compile_file() ist auf diesem Laufzeitpfad nicht verfügbar.',
            ];
        }

        $files = $this->discoverLargestPhpFiles($limit);
        if ($files === []) {
            return [
                'success' => false,
                'compiled' => 0,
                'failed' => [],
                'message' => 'Keine geeigneten PHP-Dateien für den Warmup gefunden.',
            ];
        }

        $signature = $this->buildSignature($files);
        $state = $this->readState();
        if (!$force && is_array($state) && ($state['signature'] ?? null) === $signature) {
            return [
                'success' => true,
                'compiled' => 0,
                'failed' => [],
                'message' => 'OPcache-Warmup bereits mit aktueller Dateisignatur vorhanden.',
            ];
        }

        $compiled = 0;
        $skipped = 0;
        $failed = [];

        // Signatur vorab festschreiben: Bricht ein Compile den Request trotz aller Schutzmaßnahmen
        // hart ab (E_COMPILE_ERROR ist nicht abfangbar), darf der Warmup nicht bei jedem weiteren
        // Request erneut anlaufen und die Seite dauerhaft lahmlegen.
        $this->writeState([
            'signature' => $signature,
            'limit' => $limit,
            'generated_at' => date('c'),
            'in_progress' => true,
            'compiled' => 0,
            'failed' => [],
        ]);

        // Bereits in diesem Request eingebundene Dateien liegen ohnehin im OPcache. Ein erneutes
        // opcache_compile_file() auf sie deklariert ihre Klassen ein zweites Mal und endet in
        // "Cannot redeclare class …" (z. B. bei bereits geladenen Plugin-Klassen).
        $includedFiles = [];
        foreach (get_included_files() as $includedFile) {
            $includedFiles[(string)(realpath($includedFile) ?: $includedFile)] = true;
        }

        /** @var array<string, list<string>> $declaredBy */
        $declaredBy = [];
        /** @var array<string, int> $declarationCount */
        $declarationCount = [];
        foreach ($files as $file) {
            $candidate = (string)($file['path'] ?? '');
            if ($candidate === '' || !is_file($candidate)) {
                continue;
            }

            $candidate = (string)(realpath($candidate) ?: $candidate);
            $names = $this->extractDeclaredClassLikes($candidate);
            $declaredBy[$candidate] = $names;
            foreach ($names as $name) {
                $declarationCount[$name] = ($declarationCount[$name] ?? 0) + 1;
            }
        }

        foreach ($files as $file) {
            $realPath = (string)($file['path'] ?? '');
            if ($realPath === '' || !is_file($realPath)) {
                continue;
            }

            $realPath = (string)(realpath($realPath) ?: $realPath);
            if (isset($includedFiles[$realPath])) {
                $skipped++;
                continue;
            }

            // Klassen, die schon deklariert sind oder von mehreren Kandidaten deklariert werden,
            // würden beim Kompilieren bzw. beim späteren Laden der jeweils anderen Datei einen
            // nicht abfangbaren "Cannot redeclare class"-Fehler auslösen.
            $conflict = $this->findDeclarationConflict($declaredBy[$realPath] ?? [], $declarationCount);
            if ($conflict !== null) {
                $failed[] = [
                    'file' => $this->toRelativePath($realPath),
                    'reason' => 'Übersprungen: Klasse ' . $conflict . ' ist bereits deklariert oder mehrfach vorhanden.',
                ];
                continue;
            }

            set_error_handler(static function (): bool {
                return true;
            });

            try {
                $result = opcache_compile_file($realPath);
            } catch (\Throwable $e) {
                $result = false;
                $failed[] = [
                    'file' => $this->toRelativePath($realPath),
                    'reason' => $e->getMessage(),
                ];
            } finally {
                restore_error_handler();
            }

            if ($result === true) {
                $compiled++;
                continue;
            }

            $lastFailed = $failed === [] ? null : $failed[array_key_last($failed)];
            if (!is_array($lastFailed) || ($lastFailed['file'] ?? null) !== $this->toRelativePath($realPath)) {
                $failed[] = [
                    'file' => $this->toRelativePath($realPath),
                    'reason' => 'opcache_compile_file() lieferte false zurück.',
                ];
            }
        }

        $statePayload = [
            'signature' => $signature,
            'limit' => $limit,
            'generated_at' => date('c'),
            'compiled' => $compiled,
            'skipped_already_loaded' => $skipped,
            'failed' => $failed,
            'files' => array_map(function (array $file): array {
                return [
                    'path' => $this->toRelativePath((string)$file['path']),
                    'size' => (int)($file['size'] ?? 0),
                    'mtime' => (int)($file['mtime'] ?? 0),
                ];
            }, $files),
        ];
        $this->writeState($statePayload);
        $this->cachedStatus = null;

        $message = sprintf(
            'OPcache-Warmup abgeschlossen: %d von %d Datei(en) kompiliert.',
            $compiled,
            count($files)
        );

        if ($skipped > 0) {
            $message .= ' Bereits geladen: ' . $skipped . '.';
        }

        if ($failed !== []) {
            $message .= ' Fehlgeschlagen: ' . count($failed) . '.';
        }

        Logger::instance()->info($message, [
            'component' => 'opcache_warmup',
            'compiled' => $compiled,
            'skipped_already_loaded' => $skipped,
            'failed' => $failed,
            'limit' => $limit,
        ]);

        return [
            'success' => $compiled > 0 || ($skipped > 0 && $failed === []),
            'compiled' => $compiled,
            'failed' => $failed,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatus(int $limit = self::DEFAULT_LIMIT): array
    {
        if ($this->cachedStatus !== null && (int)($this->cachedStatus['limit'] ?? 0) === $limit) {
            return $this->cachedStatus;
        }

        $files = $this->discoverLargestPhpFiles(max(1, $limit));
        $signature = $this->buildSignature($files);
        $state = $this->readState();
        $available = $this->isOpcacheAvailable();
        $isCurrent = is_array($state) && ($state['signature'] ?? null) === $signature;

        $status = [
            'available' => $available,
            'limit' => $limit,
            'message' => $available
                ? 'OPcache-Warmup verfügbar.'
                : 'OPcache oder opcache_compile_file() ist auf diesem Laufzeitpfad nicht verfügbar.',
            'candidate_count' => count($files),
            'is_current' => $isCurrent,
            'last_generated_at' => is_array($state) ? (string)($state['generated_at'] ?? '') : '',
            'last_compiled' => is_array($state) ? (int)($state['compiled'] ?? 0) : 0,
            'last_failed_count' => is_array($state) && isset($state['failed']) && is_array($state['failed']) ? count($state['failed']) : 0,
            'top_files' => array_map(function (array $file): array {
                return [
                    'path' => $this->toRelativePath((string)$file['path']),
                    'size' => (int)($file['size'] ?? 0),
                ];
            }, array_slice($files, 0, 5)),
        ];

        $this->cachedStatus = $status;

        return $status;
    }

    private function isOpcacheAvailable(): bool
    {
        if (!function_exists('opcache_compile_file') || !function_exists('opcache_get_status')) {
            return false;
        }

        if (PHP_SAPI === 'cli' && !filter_var((string)ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $status = opcache_get_status(false);
        return is_array($status) && !empty($status['opcache_enabled']);
    }

    /**
     * @return array<int, array{path:string,size:int,mtime:int}>
     */
    private function discoverLargestPhpFiles(int $limit): array
    {
        $root = rtrim(ABSPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current): bool {
                    $normalizedPath = $this->normalizePath($current->getPathname());
                    $relativePath = $this->toRelativePath($normalizedPath);

                    if (!$this->isManagedWarmupCandidate($relativePath, $current->isDir())) {
                        return false;
                    }

                    if ($current->isDir()) {
                        return !$this->isExcludedPath($normalizedPath . DIRECTORY_SEPARATOR);
                    }

                    return str_ends_with(strtolower($normalizedPath), '.php');
                }
            )
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $normalizedPath = $this->normalizePath($path);
            $relativePath = $this->toRelativePath($normalizedPath);

            if (!$this->isManagedWarmupCandidate($relativePath, false)) {
                continue;
            }

            if ($this->isExcludedPath($normalizedPath)) {
                continue;
            }

            $files[] = [
                'path' => $path,
                'size' => (int)$file->getSize(),
                'mtime' => (int)$file->getMTime(),
            ];
        }

        usort($files, static function (array $a, array $b): int {
            $sizeCompare = (int)$b['size'] <=> (int)$a['size'];
            if ($sizeCompare !== 0) {
                return $sizeCompare;
            }

            return strcmp((string)$a['path'], (string)$b['path']);
        });

        return array_slice($files, 0, $limit);
    }

    /**
     * @param array<int, array{path:string,size:int,mtime:int}> $files
     */
    private function buildSignature(array $files): string
    {
        return hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readState(): ?array
    {
        if (!is_file(self::STATE_FILE) || !is_readable(self::STATE_FILE)) {
            return null;
        }

        $content = file_get_contents(self::STATE_FILE);
        if (!is_string($content) || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        $dir = dirname(self::STATE_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            self::STATE_FILE,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function normalizePath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function isManagedWarmupCandidate(string $relativePath, bool $isDirectory): bool
    {
        $relativePath = ltrim(str_replace(['/', '\\'], '/', $relativePath), '/');
        if ($relativePath === '') {
            return true;
        }

        $segments = explode('/', $relativePath);
        $topLevel = strtolower((string) ($segments[0] ?? ''));
        if ($topLevel === '') {
            return true;
        }

        if (count($segments) === 1 && !$isDirectory) {
            return str_ends_with($topLevel, '.php');
        }

        return in_array($topLevel, self::ALLOWED_TOP_LEVEL_DIRECTORIES, true);
    }

    private function isExcludedPath(string $normalizedPath): bool
    {
        $haystack = strtolower($normalizedPath);
        foreach (self::EXCLUDED_SEGMENTS as $segment) {
            if (str_contains($haystack, strtolower($this->normalizePath($segment)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Liefert die vollqualifizierten Namen (lowercase) aller in der Datei deklarierten
     * Klassen, Interfaces, Traits und Enums.
     *
     * @return list<string>
     */
    private function extractDeclaredClassLikes(string $path): array
    {
        $code = @file_get_contents($path);
        if (!is_string($code) || $code === '') {
            return [];
        }

        try {
            $tokens = token_get_all($code);
        } catch (\Throwable) {
            return [];
        }

        $names = [];
        $namespace = '';
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $next = $tokens[$j];
                    if (is_array($next) && in_array($next[0], [T_NAME_QUALIFIED, T_STRING], true)) {
                        $namespace = $next[1];
                        break;
                    }
                    if (!is_array($next) && ($next === ';' || $next === '{')) {
                        break;
                    }
                }
                continue;
            }

            $isEnum = defined('T_ENUM') && $token[0] === T_ENUM;
            if (!in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT], true) && !$isEnum) {
                continue;
            }

            // "Foo::class" und "new class" (anonyme Klassen) ausschließen.
            $prev = null;
            for ($k = $i - 1; $k >= 0; $k--) {
                $prev = $tokens[$k];
                if (is_array($prev) && in_array($prev[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                break;
            }
            if (is_array($prev) && in_array($prev[0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && $next[0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($next) && $next[0] === T_STRING) {
                    $names[] = strtolower(ltrim(($namespace !== '' ? $namespace . '\\' : '') . $next[1], '\\'));
                }
                break;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param list<string> $names
     * @param array<string, int> $declarationCount
     */
    private function findDeclarationConflict(array $names, array $declarationCount): ?string
    {
        foreach ($names as $name) {
            if (($declarationCount[$name] ?? 0) > 1
                || class_exists($name, false)
                || interface_exists($name, false)
                || trait_exists($name, false)
                || (function_exists('enum_exists') && enum_exists($name, false))
            ) {
                return $name;
            }
        }

        return null;
    }

    private function toRelativePath(string $path): string
    {
        $normalizedBase = rtrim($this->normalizePath(ABSPATH), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $normalizedPath = $this->normalizePath($path);

        if (str_starts_with($normalizedPath, $normalizedBase)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($normalizedPath, strlen($normalizedBase)));
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $normalizedPath);
    }
}
