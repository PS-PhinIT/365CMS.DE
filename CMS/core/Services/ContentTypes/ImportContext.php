<?php
/**
 * Laufzeitkontext eines Imports: Konfliktstrategie, Optionen, ID-Zuordnungen und Zugriff auf die
 * entpackten Dateien. Der Zustand ist serialisierbar (`toState()`/`fromState()`), damit ein Import in
 * Stapeln über mehrere Requests (`BatchJobService`) laufen kann.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\ContentTypes;

if (!defined('ABSPATH')) {
    exit;
}

final class ImportContext
{
    public const STRATEGY_SKIP = 'skip';
    public const STRATEGY_OVERWRITE = 'overwrite';
    public const STRATEGY_RENAME = 'rename';
    public const STRATEGIES = [self::STRATEGY_SKIP, self::STRATEGY_OVERWRITE, self::STRATEGY_RENAME];

    public readonly string $conflictStrategy;

    /** @var array<string, array<string, string|int>> Namensraum (z. B. `post`, `media`, `media_url`) => alt => neu */
    private array $maps = [];

    /**
     * @param array<int, int>      $authorMap  alte Autor-ID => Benutzer-ID dieser Installation
     * @param string               $filesDir   Verzeichnis mit den entpackten Dateien (`files/` des Archivs)
     * @param array<string, mixed> $manifest   geprüftes Manifest des Archivs
     */
    public function __construct(
        string $conflictStrategy,
        public readonly int $userId,
        public readonly bool $importAsDraft = false,
        public readonly array $authorMap = [],
        public readonly string $filesDir = '',
        public readonly array $manifest = [],
    ) {
        $this->conflictStrategy = in_array($conflictStrategy, self::STRATEGIES, true)
            ? $conflictStrategy
            : self::STRATEGY_SKIP;
    }

    public function map(string $namespace, string|int $oldValue, string|int $newValue): void
    {
        $this->maps[$namespace][(string) $oldValue] = $newValue;
    }

    public function resolve(string $namespace, string|int $oldValue): string|int|null
    {
        return $this->maps[$namespace][(string) $oldValue] ?? null;
    }

    /** @return array<string, string|int> */
    public function mapOf(string $namespace): array
    {
        return $this->maps[$namespace] ?? [];
    }

    /** Benutzer-ID für einen Autor aus dem Archiv; ohne Zuordnung der importierende Benutzer. */
    public function authorFor(int $oldAuthorId): int
    {
        $mapped = (int) ($this->authorMap[$oldAuthorId] ?? 0);
        return $mapped > 0 ? $mapped : $this->userId;
    }

    /**
     * Absoluter Pfad einer entpackten Datei, nur innerhalb von `$filesDir` (realpath-geprüft).
     */
    public function stagedFile(string $relativePath): ?string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($this->filesDir === '' || $relativePath === '' || str_contains($relativePath, "\0")) {
            return null;
        }

        $root = realpath($this->filesDir);
        $path = realpath($this->filesDir . DIRECTORY_SEPARATOR . $relativePath);
        if ($root === false || $path === false || !is_file($path)) {
            return null;
        }

        return str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) ? $path : null;
    }

    /** @return array<string, mixed> */
    public function toState(): array
    {
        return [
            'conflict_strategy' => $this->conflictStrategy,
            'user_id' => $this->userId,
            'import_as_draft' => $this->importAsDraft,
            'author_map' => $this->authorMap,
            'files_dir' => $this->filesDir,
            'manifest' => $this->manifest,
            'maps' => $this->maps,
        ];
    }

    /** @param array<string, mixed> $state */
    public static function fromState(array $state): self
    {
        $authorMap = [];
        foreach (is_array($state['author_map'] ?? null) ? $state['author_map'] : [] as $old => $new) {
            $authorMap[(int) $old] = (int) $new;
        }

        $context = new self(
            (string) ($state['conflict_strategy'] ?? self::STRATEGY_SKIP),
            (int) ($state['user_id'] ?? 0),
            !empty($state['import_as_draft']),
            $authorMap,
            (string) ($state['files_dir'] ?? ''),
            is_array($state['manifest'] ?? null) ? $state['manifest'] : []
        );

        foreach (is_array($state['maps'] ?? null) ? $state['maps'] : [] as $namespace => $entries) {
            if (!is_string($namespace) || !is_array($entries)) {
                continue;
            }
            foreach ($entries as $old => $new) {
                if (is_string($new) || is_int($new)) {
                    $context->map($namespace, (string) $old, $new);
                }
            }
        }

        return $context;
    }
}
