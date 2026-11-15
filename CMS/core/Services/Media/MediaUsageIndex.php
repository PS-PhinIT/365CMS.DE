<?php
/**
 * Dauerhafter Verwendungsnachweis für Medien (4.1.0, Bereich 2b): „Datei X wird verwendet in …“ über alle
 * Inhaltsarten (Tabelle `media_usage`).
 *
 * - `reindexContent()` nach jedem Speichern (Inhalts-Hooks in `MediaFeatureHooks`), `removeContent()` beim Löschen.
 * - `rebuildStep()` baut den Index in Schritten neu auf (Auftrag `media_usage` unter Medien-Werkzeuge); am Ende werden
 *   Zeilen entfernt, die der Lauf nicht berührt hat (Inhalte, die es nicht mehr gibt).
 * - `usageMap()` liefert Verwendungen im Format von `MediaUsageService::buildUsageMap()` – Mediathek, Filter
 *   „ungenutzt“ und die Löschwarnung im Papierkorb nutzen den Index, sobald er einmal vollständig aufgebaut ist.
 * - Extraktoren je Inhaltsart: `MediaReferenceExtractor`, Registrierung über `cms_register_media_reference_extractors`.
 *
 * Speicher, Normalisierer, Uhr und die Merker-Option sind für `TESTS/media-usage` austauschbar.
 *
 * @package CMSv2\Core\Services\Media
 */

declare(strict_types=1);

namespace CMS\Services\Media;

use CMS\Hooks;

if (!defined('ABSPATH')) {
    exit;
}

final class MediaUsageIndex
{
    public const REGISTER_ACTION = 'cms_register_media_reference_extractors';
    public const OPTION_BUILT_AT = 'media_usage_index_built_at';
    public const CONTEXTS = ['content', 'content_en', 'featured', 'gallery', 'attachment', 'setting'];

    /** Editor.js-Blocktypen mit eigenem Kontext. */
    private const BLOCK_CONTEXTS = [
        'imageGallery' => 'gallery',
        'carousel' => 'gallery',
        'gallery' => 'gallery',
        'attaches' => 'attachment',
    ];

    private const FIELD_LABELS = [
        'featured' => 'Titelbild',
        'content' => 'Inhalt',
        'content_en' => 'Inhalt EN',
        'gallery' => 'Galerie',
        'attachment' => 'Anhang',
        'setting' => 'Einstellung',
    ];

    private static ?self $instance = null;

    /** @var array<string, MediaReferenceExtractor>|null */
    private ?array $extractors = null;

    private MediaUsageStoreInterface $store;
    private MediaPathNormalizer $normalizer;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(): string */
    private \Closure $builtReader;
    /** @var \Closure(string): void */
    private \Closure $builtWriter;
    private bool $registerCore;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @param array<string, mixed> $deps store, normalizer, clock, builtGet, builtSet, registerCore (bool)
     */
    public function __construct(array $deps = [])
    {
        $this->store = $deps['store'] ?? new DatabaseMediaUsageStore();
        $this->normalizer = $deps['normalizer'] ?? MediaPathNormalizer::forSite();
        $this->clock = $deps['clock'] ?? static fn (): int => time();
        $this->builtReader = $deps['builtGet'] ?? static function (): string {
            try {
                return (string) (\CMS\Services\OptionStore::getInstance()->get(self::OPTION_BUILT_AT) ?? '');
            } catch (\Throwable) {
                return '';
            }
        };
        $this->builtWriter = $deps['builtSet'] ?? static function (string $value): void {
            if (function_exists('update_option')) {
                \update_option(self::OPTION_BUILT_AT, $value);
            }
        };
        $this->registerCore = $deps['registerCore'] ?? true;
    }

    // ── Extraktoren ─────────────────────────────────────────────────────────

    public function register(MediaReferenceExtractor $extractor): void
    {
        $type = $extractor->contentType();
        if (preg_match('/^[a-z0-9_]{1,30}$/', $type) !== 1) {
            throw new \InvalidArgumentException('Ungültige Inhaltsart: ' . $type);
        }
        $this->extractors ??= [];
        $this->extractors[$type] = $extractor;
    }

    /** @return array<string, MediaReferenceExtractor> */
    public function extractors(): array
    {
        if ($this->extractors === null || ($this->registerCore && !isset($this->extractors['post']))) {
            $this->extractors ??= [];
            if ($this->registerCore) {
                foreach ([
                    Usage\ContentTableReferenceExtractor::posts(),
                    Usage\ContentTableReferenceExtractor::pages(),
                    new Usage\HubSiteReferenceExtractor(),
                    new Usage\DownloadReferenceExtractor(),
                    new Usage\FormReferenceExtractor(),
                    new Usage\ThemeReferenceExtractor(),
                ] as $core) {
                    $this->extractors[$core->contentType()] ??= $core;
                }
                Hooks::doAction(self::REGISTER_ACTION, $this);
            }
        }

        return $this->extractors;
    }

    // ── Indizieren ──────────────────────────────────────────────────────────

    /**
     * Medienpfade (Originale) mit Kontext aus den Werten eines Inhalts.
     *
     * @param list<array{context:string, value:mixed}> $values
     * @return list<array{path:string, context:string}>
     */
    public function referencesFrom(array $values): array
    {
        $found = [];
        foreach ($values as $item) {
            $context = in_array($item['context'] ?? '', self::CONTEXTS, true) ? (string) $item['context'] : 'content';
            foreach ($this->splitByBlocks($item['value'] ?? null, $context) as [$blockContext, $blockValue]) {
                foreach ($this->normalizer->extract($blockValue) as $path) {
                    if ($path !== '') {
                        $found[$path . "\0" . $blockContext] = ['path' => $path, 'context' => $blockContext];
                    }
                }
            }
        }

        return array_values($found);
    }

    /**
     * Editor.js-Inhalt in Blöcke zerlegen (Galerie/Karussell → `gallery`, Anhang → `attachment`), sonst ein Stück.
     *
     * @return list<array{0:string, 1:mixed}>
     */
    private function splitByBlocks(mixed $value, string $context): array
    {
        $decoded = is_string($value) && str_starts_with(ltrim($value), '{') ? json_decode($value, true) : $value;
        if (!is_array($decoded) || !is_array($decoded['blocks'] ?? null)) {
            return [[$context, $value]];
        }
        $parts = [];
        foreach ($decoded['blocks'] as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            $blockContext = $context === 'content' || $context === 'content_en'
                ? (self::BLOCK_CONTEXTS[$type] ?? $context)
                : $context;
            $parts[] = [$blockContext, $block['data'] ?? $block];
        }

        return $parts;
    }

    /**
     * Index eines Inhalts neu schreiben. Gibt die Zahl der Verweise zurück; ein fehlender Inhalt wird entfernt.
     */
    public function reindexContent(string $contentType, int $contentId): int
    {
        $extractor = $this->extractors()[$contentType] ?? null;
        if ($extractor === null) {
            return 0;
        }
        $loaded = $extractor->load($contentId);
        if ($loaded === null) {
            $this->store->delete($contentType, $contentId);

            return 0;
        }
        $references = $this->referencesFrom($loaded['values']);
        $this->store->replace($contentType, $contentId, $references, $this->now());

        return count($references);
    }

    public function removeContent(string $contentType, int $contentId): void
    {
        $this->store->delete($contentType, $contentId);
    }

    /**
     * Ein Schritt des Neuaufbaus: Inhaltsarten der Reihe nach, je Schritt höchstens `$limit` Inhalte.
     *
     * @param array<string, mixed> $state types, type_index, after_id, started_at
     * @return array{state:array<string, mixed>, processed:int, done:bool}
     */
    public function rebuildStep(array $state, int $limit): array
    {
        $types = array_values(array_map('strval', (array) ($state['types'] ?? array_keys($this->extractors()))));
        $typeIndex = max(0, (int) ($state['type_index'] ?? 0));
        $afterId = (int) ($state['after_id'] ?? -1);
        $startedAt = (string) ($state['started_at'] ?? $this->now());
        $processed = 0;
        $limit = max(1, $limit);

        while ($typeIndex < count($types) && $processed < $limit) {
            $extractor = $this->extractors()[$types[$typeIndex]] ?? null;
            $ids = $extractor !== null ? $extractor->ids($afterId, $limit - $processed) : [];
            if ($ids === []) {
                $typeIndex++;
                $afterId = -1;
                continue;
            }
            foreach ($ids as $id) {
                $this->reindexContent($types[$typeIndex], (int) $id);
                $afterId = (int) $id;
                $processed++;
            }
        }

        $done = $typeIndex >= count($types);
        if ($done) {
            // Zeilen, die der Lauf nicht neu geschrieben hat, gehören zu Inhalten, die es nicht mehr gibt.
            $this->store->pruneBefore($startedAt);
            ($this->builtWriter)($this->now());
        }

        return [
            'state' => ['types' => $types, 'type_index' => $typeIndex, 'after_id' => $afterId, 'started_at' => $startedAt],
            'processed' => $processed,
            'done' => $done,
        ];
    }

    /** Ist der Index mindestens einmal vollständig aufgebaut? Erst dann ersetzt er die Laufzeitsuche. */
    public function isBuilt(): bool
    {
        return trim(($this->builtReader)()) !== '';
    }

    public function builtAt(): string
    {
        return trim(($this->builtReader)());
    }

    // ── Abfragen ────────────────────────────────────────────────────────────

    /**
     * Verwendungen je Medienpfad im Format von `MediaUsageService::buildUsageMap()`. Für erzeugte Größen und
     * WebP/AVIF-Fassungen zählen die Verwendungen des Originals mit.
     *
     * @param list<string> $paths relative Pfade in uploads/
     * @return array<string, list<array<string, mixed>>>
     */
    public function usageMap(array $paths): array
    {
        $lookup = [];
        $map = [];
        foreach ($paths as $path) {
            $clean = MediaPathNormalizer::cleanRelative((string) $path);
            if ($clean === '') {
                continue;
            }
            $map[$clean] = [];
            foreach ($this->normalizer->lookupPaths($clean) as $candidate) {
                $lookup[$candidate][] = $clean;
            }
        }
        if ($lookup === []) {
            return $map;
        }

        $extractors = $this->extractors();
        $described = [];
        foreach ($this->store->rowsForPaths(array_keys($lookup)) as $row) {
            $type = $row['content_type'];
            $id = $row['content_id'];
            $key = $type . ':' . $id;
            if (!array_key_exists($key, $described)) {
                $loaded = isset($extractors[$type]) ? $extractors[$type]->load($id) : null;
                $described[$key] = $loaded === null ? null : [
                    'label' => $extractors[$type]->label(),
                    'title' => (string) $loaded['title'],
                    'edit_url' => (string) $loaded['edit_url'],
                ];
            }
            if ($described[$key] === null) {
                continue;
            }
            $item = [
                'usage_key' => $key . ':' . $row['context'],
                'content_type' => $type,
                'content_type_label' => $described[$key]['label'],
                'field' => $row['context'] === 'featured' ? 'featured_image' : $row['context'],
                'field_label' => self::fieldLabel($type, $row['context']),
                'content_id' => $id,
                'title' => $described[$key]['title'],
                'edit_url' => $described[$key]['edit_url'],
            ];
            foreach (array_unique($lookup[$row['media_path']] ?? []) as $target) {
                $map[$target][$item['usage_key']] = $item;
            }
        }

        foreach ($map as $target => $items) {
            $items = array_values($items);
            usort($items, static fn (array $a, array $b): int => [$a['content_type_label'], $a['title'], $a['field_label']] <=> [$b['content_type_label'], $b['title'], $b['field_label']]);
            $map[$target] = $items;
        }

        return $map;
    }

    /**
     * Ungenutzte Medien aus einer Liste (z. B. allen Dateien der Mediathek), mit Blättern.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    public function unusedMedia(array $paths, int $offset = 0, int $limit = 100): array
    {
        $unused = [];
        foreach ($this->usageMap($paths) as $path => $items) {
            if ($items === []) {
                $unused[] = $path;
            }
        }

        return array_slice($unused, max(0, $offset), max(1, $limit));
    }

    public static function fieldLabel(string $contentType, string $context): string
    {
        if ($context === 'featured') {
            return match ($contentType) {
                'post' => 'Beitragsbild',
                'page' => 'Seitenbild',
                'download' => 'Titelbild',
                default => self::FIELD_LABELS['featured'],
            };
        }

        return self::FIELD_LABELS[$context] ?? 'Verwendung';
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s', ($this->clock)());
    }
}
