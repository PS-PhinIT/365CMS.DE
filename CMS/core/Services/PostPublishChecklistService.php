<?php
/**
 * Veröffentlichungs-Checkliste für Beiträge.
 *
 * Prüft die drei Pflichtpunkte vor dem Veröffentlichen oder Planen eines Beitrags:
 * Beitragsbild gesetzt, SEO-Meta-Beschreibung mit 120–160 Zeichen und Kategorie gesetzt.
 * Der Editor (assets/js/post-editor/checklist.js) verwendet dieselben Regeln live;
 * serverseitig erzwingen PostsModule::save(), die Bulk-Aktion „Veröffentlichen“ und die
 * Revisions-Wiederherstellung geplanter Beiträge diese Prüfung.
 *
 * Zusätzlicher Punkt „Downloads“ (nur wenn der Inhalt Download-Blöcke enthält): Beitrag verweist auf
 * nicht verfügbare Downloads. Der Inhalt kommt aus `$state['content']` (String oder Liste) oder – beim
 * Speichern – aus {@see setPendingContents()} (gesetzt von DownloadHooks über `cms_prepare_post_save_payload`).
 *
 * @package CMS\Services
 */

declare(strict_types=1);

namespace CMS\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class PostPublishChecklistService
{
    public const META_DESCRIPTION_MIN = 120;
    public const META_DESCRIPTION_MAX = 160;

    private static ?self $instance = null;

    /** @var list<string>|null Inhalte des laufenden Speichervorgangs (content/content_en). */
    private ?array $pendingContents = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * Zeichenzahl einer Meta-Beschreibung so, wie der Editor sie live zählt
     * (getrimmt, Mehrfach-Leerzeichen zusammengefasst).
     */
    public function metaDescriptionLength(mixed $value): int
    {
        $text = is_scalar($value) ? (string) $value : '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }

    /**
     * @param array<string,mixed> $state Erwartet featured_image, meta_description und category_id.
     * @return array{passed:bool,items:list<array{id:string,label:string,passed:bool,hint:string,field:string}>,missing:list<string>}
     */
    public function evaluate(array $state): array
    {
        $featuredImage = trim(is_scalar($state['featured_image'] ?? null) ? (string) $state['featured_image'] : '');
        $metaLength = $this->metaDescriptionLength($state['meta_description'] ?? '');
        $categoryId = is_numeric($state['category_id'] ?? null) ? (int) $state['category_id'] : 0;

        $metaPassed = $metaLength >= self::META_DESCRIPTION_MIN && $metaLength <= self::META_DESCRIPTION_MAX;
        if ($metaLength === 0) {
            $metaHint = 'Meta-Beschreibung fehlt (120–160 Zeichen).';
        } elseif ($metaLength < self::META_DESCRIPTION_MIN) {
            $metaHint = sprintf('Meta-Beschreibung zu kurz: %d von mindestens 120 Zeichen.', $metaLength);
        } elseif ($metaLength > self::META_DESCRIPTION_MAX) {
            $metaHint = sprintf('Meta-Beschreibung zu lang: %d von höchstens 160 Zeichen.', $metaLength);
        } else {
            $metaHint = sprintf('Meta-Beschreibung hat %d Zeichen.', $metaLength);
        }

        $items = [
            [
                'id' => 'featured_image',
                'label' => 'Beitragsbild',
                'passed' => $featuredImage !== '',
                'hint' => $featuredImage !== '' ? 'Beitragsbild ist gesetzt.' : 'Beitragsbild fehlt.',
                'field' => 'featured_image',
            ],
            [
                'id' => 'meta_description',
                'label' => 'SEO-Beschreibung',
                'passed' => $metaPassed,
                'hint' => $metaHint,
                'field' => 'meta_description',
            ],
            [
                'id' => 'category',
                'label' => 'Kategorie',
                'passed' => $categoryId > 0,
                'hint' => $categoryId > 0 ? 'Kategorie ist gesetzt.' : 'Kategorie fehlt.',
                'field' => 'category_id',
            ],
        ];

        $downloadsItem = $this->evaluateDownloadReferences($state);
        if ($downloadsItem !== null) {
            $items[] = $downloadsItem;
        }

        $missing = [];
        foreach ($items as $item) {
            if (!$item['passed']) {
                $missing[] = $item['hint'];
            }
        }

        return [
            'passed' => $missing === [],
            'items' => $items,
            'missing' => $missing,
        ];
    }

    /**
     * Inhalte des laufenden Speichervorgangs für inhaltsbezogene Prüfpunkte setzen.
     *
     * @param list<mixed> $contents
     */
    public function setPendingContents(array $contents): void
    {
        $this->pendingContents = $this->normalizeContents($contents);
    }

    public function clearPendingContents(): void
    {
        $this->pendingContents = null;
    }

    /**
     * Entscheidet, ob ein Speichervorgang die Checkliste erzwingen muss.
     *
     * Erzwungen wird beim Planen (Ergebnis: veröffentlicht mit Datum in der Zukunft) und beim
     * Veröffentlichen eines Beitrags, der vorher nicht live war. Aktualisierungen bereits
     * veröffentlichter Beiträge, Entwürfe und private Beiträge bleiben ausgenommen, damit
     * Bestandsbeiträge nach dem Update weiter bearbeitet werden können.
     *
     * @param array{status?:mixed,published_at?:mixed}|null $existing Bisheriger Zustand oder null für neue Beiträge.
     */
    public function requiresChecklist(string $targetStatus, ?string $targetPublishedAt, ?array $existing, ?int $now = null): bool
    {
        if ($targetStatus !== 'published') {
            return false;
        }

        $now ??= time();
        if ($this->isFutureTimestamp($targetPublishedAt, $now)) {
            return true;
        }

        return !$this->isLive($existing, $now);
    }

    /**
     * @param array{status?:mixed,published_at?:mixed}|null $post
     */
    public function isLive(?array $post, ?int $now = null): bool
    {
        if ($post === null || (string) ($post['status'] ?? '') !== 'published') {
            return false;
        }

        $publishedAt = is_scalar($post['published_at'] ?? null) ? (string) $post['published_at'] : '';

        return !$this->isFutureTimestamp($publishedAt, $now ?? time());
    }

    /**
     * @param array{status?:mixed,published_at?:mixed}|null $post
     */
    public function isScheduled(?array $post, ?int $now = null): bool
    {
        if ($post === null || (string) ($post['status'] ?? '') !== 'published') {
            return false;
        }

        $publishedAt = is_scalar($post['published_at'] ?? null) ? (string) $post['published_at'] : '';

        return $this->isFutureTimestamp($publishedAt, $now ?? time());
    }

    /**
     * Fehlermeldung für serverseitig abgelehnte Veröffentlichungen.
     *
     * Mit $includeDetails = false entsteht nur die Kurzmeldung; die fehlenden Punkte
     * ($result['missing']) werden dann separat als Detail-Liste der Admin-Meldung übergeben.
     *
     * @param array{missing:list<string>} $result
     */
    public function buildErrorMessage(array $result, string $action = 'Veröffentlichen', bool $includeDetails = true): string
    {
        $message = $action . ' nicht möglich – die Veröffentlichungs-Checkliste ist unvollständig';
        if (!$includeDetails) {
            return $message . '.';
        }

        return $message . ': ' . implode(' ', $result['missing'] ?? []);
    }

    /**
     * Prüfpunkt „Downloads“: nur aktiv, wenn der Inhalt Download-Blöcke enthält.
     *
     * @param array<string,mixed> $state
     * @return array{id:string,label:string,passed:bool,hint:string,field:string}|null
     */
    private function evaluateDownloadReferences(array $state): ?array
    {
        $usageClass = \CMS\Services\Downloads\DownloadUsageService::class;
        $contents = array_key_exists('content', $state)
            ? $this->normalizeContents(is_array($state['content']) ? $state['content'] : [$state['content']])
            : ($this->pendingContents ?? []);
        if ($contents === [] || !class_exists($usageClass)) {
            return null;
        }

        $referenced = false;
        foreach ($contents as $content) {
            if ($usageClass::extractIds($content) !== []) {
                $referenced = true;
                break;
            }
        }
        if (!$referenced) {
            return null;
        }

        $unavailable = $usageClass::getInstance()->unavailableReferences($contents);
        $labels = array_map(static fn (array $entry): string => (string) $entry['label'], $unavailable);

        return [
            'id' => 'downloads',
            'label' => __('downloads.checklist.label'),
            'passed' => $unavailable === [],
            'hint' => $unavailable === []
                ? __('downloads.checklist.ok')
                : sprintf(__('downloads.checklist.missing'), implode(', ', $labels)),
            'field' => 'content',
        ];
    }

    /**
     * @param array<mixed> $contents
     * @return list<string>
     */
    private function normalizeContents(array $contents): array
    {
        $result = [];
        foreach ($contents as $content) {
            if (is_scalar($content) && trim((string) $content) !== '') {
                $result[] = (string) $content;
            }
        }

        return $result;
    }

    private function isFutureTimestamp(?string $value, int $now): bool
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return false;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false && $timestamp > $now;
    }
}
