<?php
/**
 * Theme Archive Repository
 *
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS\Routing;

use CMS\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class ThemeArchiveRepository
{
    /**
     * Release-Prüfung 4.1.0 (PERF, db:8): Cache-Schlüssel der Kategorie- und Schlagwort-Übersichten.
     * Je Schlüssel liegt eine Map „Locale|Ausdruck-Hash“ => {until, items}; die URLs werden beim Lesen gebaut.
     */
    public const OVERVIEW_CACHE_KEYS = [
        'category' => 'theme_archive_overview_category',
        'tag' => 'theme_archive_overview_tag',
    ];

    /** Release-Prüfung 4.1.0 (PERF, db:8): Höchstalter eines Eintrags in Sekunden (Schreibzugriffe über rohes PDO). */
    public const OVERVIEW_CACHE_TTL = 600;

    /**
     * Release-Prüfung 4.1.0 (PERF, db:8): verwirft die gecachten Übersichten. Database::noteWrite() ruft das bei
     * Schreibzugriffen auf posts/post_categories/post_category_rel/post_tags/post_tag_rel auf.
     */
    public static function invalidateOverviewCache(): void
    {
        if (!defined('ABSPATH')) {
            return;
        }

        try {
            $cache = \CMS\CacheManager::instance();
            foreach (self::OVERVIEW_CACHE_KEYS as $key) {
                $cache->delete($key);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Release-Prüfung 4.1.0 (PERF, db:8): liefert die Übersicht aus dem Cache oder berechnet sie neu.
     * Ein Eintrag gilt höchstens OVERVIEW_CACHE_TTL Sekunden und nie über die nächste geplante Veröffentlichung
     * hinaus, damit zeitgesteuerte Beiträge wie bisher pünktlich mitzählen.
     *
     * @param \Closure(): array<int,array<string,mixed>> $compute Übersicht ohne `url`
     * @return array<int,array<string,mixed>>
     */
    private function cachedOverview(string $kind, string $locale, string $localeAvailabilityExpression, \Closure $compute): array
    {
        $cacheKey = self::OVERVIEW_CACHE_KEYS[$kind];
        $entryKey = $locale . '|' . md5($localeAvailabilityExpression);
        $now = time();
        $cache = null;
        $stored = [];

        try {
            $cache = \CMS\CacheManager::instance();
            $stored = $cache->get($cacheKey, []);
            $stored = is_array($stored) ? $stored : [];
        } catch (\Throwable) {
            $cache = null;
        }

        $entry = $stored[$entryKey] ?? null;
        if (is_array($entry) && (int) ($entry['until'] ?? 0) > $now && is_array($entry['items'] ?? null)) {
            $items = $entry['items'];
        } else {
            $items = $compute();
            if ($cache !== null) {
                $until = $now + self::OVERVIEW_CACHE_TTL;
                $nextPublication = $this->nextScheduledPublication();
                if ($nextPublication !== null) {
                    $until = min($until, $nextPublication);
                }
                if ($until > $now) {
                    foreach ($stored as $key => $value) {
                        if (!is_array($value) || (int) ($value['until'] ?? 0) <= $now) {
                            unset($stored[$key]);
                        }
                    }
                    $stored[$entryKey] = ['until' => $until, 'items' => $items];
                    try {
                        $cache->set($cacheKey, $stored, max(1, max(array_map(static fn (array $value): int => (int) $value['until'], $stored)) - $now));
                    } catch (\Throwable) {
                    }
                }
            }
        }

        $type = $kind === 'category' ? 'category' : 'tag';
        foreach ($items as &$item) {
            $item['url'] = \cms_get_archive_url($type, (string) ($item['slug'] ?? ''), $locale);
        }
        unset($item);

        return array_values($items);
    }

    /**
     * Release-Prüfung 4.1.0 (PERF, db:8): Zeitpunkt der nächsten geplanten Veröffentlichung (Unix-Zeit) oder null.
     */
    private function nextScheduledPublication(): ?int
    {
        try {
            $db = Database::instance();
            $next = $db->get_var(
                "SELECT MIN(published_at) FROM {$db->getPrefix()}posts WHERE status = 'published' AND published_at > ?",
                [date('Y-m-d H:i:s')]
            );
        } catch (\Throwable) {
            return null;
        }

        $timestamp = is_string($next) && $next !== '' ? strtotime($next) : false;

        return $timestamp !== false ? (int) $timestamp : null;
    }
    /**
     * @return array<int,int>
     */
    public function getCategoryArchiveIds(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        $db = Database::instance();
        $prefix = $db->getPrefix();
        $rows = $db->get_results(
            "SELECT id, parent_id FROM {$prefix}post_categories",
            []
        ) ?: [];

        $byParent = [];
        foreach ($rows as $row) {
            $parentId = (int) ($row->parent_id ?? 0);
            $byParent[$parentId][] = (int) ($row->id ?? 0);
        }

        $collected = [];
        $walker = function (int $currentId) use (&$walker, &$collected, $byParent): void {
            if ($currentId <= 0 || isset($collected[$currentId])) {
                return;
            }

            $collected[$currentId] = true;
            foreach ($byParent[$currentId] ?? [] as $childId) {
                $walker((int) $childId);
            }
        };

        $walker($categoryId);

        return array_map('intval', array_keys($collected));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getPublishedCategoryOverview(string $locale, string $localeAvailabilityExpression): array
    {
        // Release-Prüfung 4.1.0 (PERF, db:8): nicht bei jedem Sidebar-Aufruf alle veröffentlichten Beiträge lesen.
        return $this->cachedOverview('category', $locale, $localeAvailabilityExpression,
            fn (): array => $this->computeCategoryOverview($locale, $localeAvailabilityExpression));
    }

    /**
     * @return array<int,array<string,mixed>> Übersicht ohne `url`
     */
    private function computeCategoryOverview(string $locale, string $localeAvailabilityExpression): array
    {
        $db = Database::instance();
        $prefix = $db->getPrefix();
        $categories = $db->get_results(
            "SELECT id, name, slug, slug_en, description, parent_id
             FROM {$prefix}post_categories
             ORDER BY name ASC"
        ) ?: [];

        $postIdsByCategory = [];
        foreach ($categories as $category) {
            $categoryId = (int) ($category->id ?? 0);
            if ($categoryId > 0) {
                $postIdsByCategory[$categoryId] = [];
            }
        }

        $primaryRows = $db->get_results(
            "SELECT p.id AS post_id, p.category_id AS category_id
             FROM {$prefix}posts p
             WHERE " . \cms_post_publication_where('p') . "
               AND {$localeAvailabilityExpression}
               AND p.category_id IS NOT NULL
               AND p.category_id > 0"
        ) ?: [];

        foreach ($primaryRows as $row) {
            $categoryId = (int) ($row->category_id ?? 0);
            $postId = (int) ($row->post_id ?? 0);
            if ($categoryId > 0 && $postId > 0) {
                $postIdsByCategory[$categoryId][$postId] = true;
            }
        }

        $relationRows = $db->get_results(
            "SELECT p.id AS post_id, pcr.category_id AS category_id
             FROM {$prefix}post_category_rel pcr
             INNER JOIN {$prefix}posts p ON p.id = pcr.post_id
             WHERE " . \cms_post_publication_where('p') . "
               AND {$localeAvailabilityExpression}"
        ) ?: [];

        foreach ($relationRows as $row) {
            $categoryId = (int) ($row->category_id ?? 0);
            $postId = (int) ($row->post_id ?? 0);
            if ($categoryId > 0 && $postId > 0) {
                $postIdsByCategory[$categoryId][$postId] = true;
            }
        }

        $items = [];
        foreach ($categories as $category) {
            $categoryId = (int) ($category->id ?? 0);
            $count = count($postIdsByCategory[$categoryId] ?? []);
            $slug = trim((string) ($category->slug ?? ''));
            $slugEn = trim((string) ($category->slug_en ?? ''));

            if ($count <= 0 || $slug === '') {
                continue;
            }

            $localizedSlug = $locale === 'en' && $slugEn !== '' ? $slugEn : $slug;

            $items[] = [
                'title' => trim((string) ($category->name ?? 'Kategorie')),
                'slug' => $localizedSlug,
                'description' => trim((string) ($category->description ?? '')),
                'count' => $count,
            ];
        }

        usort($items, static function (array $left, array $right): int {
            $countCompare = ((int) ($right['count'] ?? 0)) <=> ((int) ($left['count'] ?? 0));
            if ($countCompare !== 0) {
                return $countCompare;
            }

            return strnatcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        });

        return $items;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getPublishedTagOverview(string $locale, string $localeAvailabilityExpression): array
    {
        // Release-Prüfung 4.1.0 (PERF, db:8): Schlagwortwolke aus dem Cache statt Vollscan je Seitenaufruf.
        return $this->cachedOverview('tag', $locale, $localeAvailabilityExpression,
            fn (): array => $this->computeTagOverview($locale, $localeAvailabilityExpression));
    }

    /**
     * @return array<int,array<string,mixed>> Übersicht ohne `url`
     */
    private function computeTagOverview(string $locale, string $localeAvailabilityExpression): array
    {
        $db = Database::instance();
        $prefix = $db->getPrefix();
        $tagPostMap = [];
        $tagLabels = [];
        $tagLocalizedSlugs = [];

        $relationRows = $db->get_results(
            "SELECT p.id AS post_id, t.name, t.slug, t.slug_en
             FROM {$prefix}post_tags t
             INNER JOIN {$prefix}post_tag_rel ptr ON ptr.tag_id = t.id
             INNER JOIN {$prefix}posts p ON p.id = ptr.post_id
             WHERE " . \cms_post_publication_where('p') . "
               AND {$localeAvailabilityExpression}"
        ) ?: [];

        foreach ($relationRows as $row) {
            $slug = trim((string) ($row->slug ?? ''));
            $slugEn = trim((string) ($row->slug_en ?? ''));
            $postId = (int) ($row->post_id ?? 0);
            if ($slug === '' || $postId <= 0) {
                continue;
            }

            $tagPostMap[$slug][$postId] = true;
            $tagLabels[$slug] = trim((string) ($row->name ?? $slug));
            $tagLocalizedSlugs[$slug] = $locale === 'en' && $slugEn !== '' ? $slugEn : $slug;
        }

        $legacyRows = $db->get_results(
            "SELECT p.id, p.tags
             FROM {$prefix}posts p
             WHERE " . \cms_post_publication_where('p') . "
               AND {$localeAvailabilityExpression}
               AND p.tags IS NOT NULL
               AND p.tags != ''"
        ) ?: [];

        foreach ($legacyRows as $row) {
            $postId = (int) ($row->id ?? 0);
            if ($postId <= 0) {
                continue;
            }

            foreach ($this->parsePostTags((string) ($row->tags ?? '')) as $tag) {
                $slug = trim((string) ($tag['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }

                $tagPostMap[$slug][$postId] = true;
                if (!isset($tagLabels[$slug]) || trim((string) $tagLabels[$slug]) === '') {
                    $tagLabels[$slug] = trim((string) ($tag['name'] ?? $slug));
                }
            }
        }

        $items = [];
        foreach ($tagPostMap as $slug => $postMap) {
            $count = count($postMap);
            if ($count <= 0) {
                continue;
            }

            $title = trim((string) ($tagLabels[$slug] ?? $slug));
            $localizedSlug = (string) ($tagLocalizedSlugs[$slug] ?? $slug);
            $items[] = [
                'title' => $title !== '' ? $title : $slug,
                'slug' => $localizedSlug,
                'description' => '',
                'count' => $count,
            ];
        }

        usort($items, static function (array $left, array $right): int {
            $countCompare = ((int) ($right['count'] ?? 0)) <=> ((int) ($left['count'] ?? 0));
            if ($countCompare !== 0) {
                return $countCompare;
            }

            return strnatcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        });

        return $items;
    }

    /**
     * @return array<int,array{name:string,slug:string}>
     */
    public function getPostTagRows(int $postId, string $locale = 'de'): array
    {
        if ($postId <= 0) {
            return [];
        }

        $db = Database::instance();
        $prefix = $db->getPrefix();
        $rows = $db->get_results(
            "SELECT t.name, t.slug, t.slug_en
             FROM {$prefix}post_tags t
             INNER JOIN {$prefix}post_tag_rel ptr ON ptr.tag_id = t.id
             WHERE ptr.post_id = ?
             ORDER BY t.name ASC",
            [$postId]
        ) ?: [];

        return array_values(array_filter(array_map(static function (object $row) use ($locale): array {
            $name = trim((string) ($row->name ?? ''));
            $slugDe = trim((string) ($row->slug ?? ''));
            $slugEn = trim((string) ($row->slug_en ?? ''));
            $slug = $locale === 'en' && $slugEn !== '' ? $slugEn : $slugDe;

            return $name !== '' && $slug !== ''
                ? ['name' => $name, 'slug' => $slug]
                : [];
        }, $rows)));
    }

    public function normalizeArchiveSlug(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        if ($value === '') {
            return '';
        }

        $value = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $value);
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';

        return trim($value, '-');
    }

    /**
     * @return array<int,array{name:string,slug:string}>
     */
    public function parsePostTags(string $rawTags): array
    {
        $tags = [];

        foreach (array_filter(array_map('trim', explode(',', $rawTags))) as $tagName) {
            $tags[] = [
                'name' => $tagName,
                'slug' => $this->normalizeArchiveSlug($tagName),
            ];
        }

        return $tags;
    }
}
