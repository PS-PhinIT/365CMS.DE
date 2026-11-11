<?php
/**
 * Kuratierter Feed-Katalog: Vorlagen (Daten in `catalog-data.php`) als Bereich mit Quellen übernehmen.
 *
 * Vorhandene Feed-URLs werden übersprungen; jede URL läuft durch die SSRF-Prüfung des `FeedService`.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Feed;

use CMS\Services\FeedService;

if (!defined('ABSPATH')) {
    exit;
}

final class FeedCatalog
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $data = null;

    /** @return array<string, array{name:string,icon:string,slug:string,description:string,feeds:list<array<string,string>>}> */
    public static function all(): array
    {
        if (self::$data === null) {
            $data = require __DIR__ . '/catalog-data.php';
            self::$data = is_array($data) ? $data : [];
        }

        return self::$data;
    }

    /** @return array<string, mixed>|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Übernimmt Feeds einer Vorlage in einen Bereich (0 = neuen Bereich aus der Vorlage anlegen).
     *
     * @param list<int> $indexes leer = alle Feeds der Vorlage
     * @return array{category_id:int,imported:int,skipped:int,errors:list<string>}
     */
    public static function import(string $key, int $categoryId, array $indexes = []): array
    {
        $result = ['category_id' => $categoryId, 'imported' => 0, 'skipped' => 0, 'errors' => []];
        $template = self::get($key);
        $repo = AggregatorRepository::getInstance();
        if ($template === null) {
            $result['errors'][] = FeedI18n::admin('feeds.admin.catalog.unknown');

            return $result;
        }

        if ($categoryId <= 0 || $repo->category($categoryId) === null) {
            $slug = LegacyNormalizer::categorySlug($template['slug'] ?? '', (string) $template['name']);
            $base = $slug;
            for ($n = 2; $repo->categorySlugExists($slug); $n++) {
                $slug = $base . '-' . $n;
            }
            $categoryId = $repo->saveCategory([
                'name' => (string) $template['name'],
                'slug' => $slug,
                'description' => (string) ($template['description'] ?? ''),
                'icon' => LegacyNormalizer::icon($template['icon'] ?? ''),
                'is_public' => true,
            ]);
            $result['category_id'] = $categoryId;
            if ($categoryId <= 0) {
                $result['errors'][] = FeedI18n::admin('feeds.admin.error.save');

                return $result;
            }
        }

        $feedService = FeedService::getInstance();
        foreach ((array) ($template['feeds'] ?? []) as $index => $feed) {
            if ($indexes !== [] && !in_array((int) $index, $indexes, true)) {
                continue;
            }
            $url = $feedService->normalizeRemoteUrl((string) ($feed['feed_url'] ?? ''));
            if ($url === '') {
                $result['skipped']++;
                $result['errors'][] = (string) ($feed['name'] ?? '') . ': ' . FeedI18n::admin('feeds.admin.error.url');
                continue;
            }
            if ($repo->sourceUrlExists($url)) {
                $result['skipped']++;
                continue;
            }
            $id = $repo->saveSource([
                'category_id' => $categoryId,
                'name' => mb_substr((string) ($feed['name'] ?? $url), 0, 255),
                'feed_url' => $url,
                'site_url' => AggregatorFrontendController::safeUrl($feed['site_url'] ?? ''),
                'description' => (string) ($feed['description'] ?? ''),
                'is_active' => true,
            ]);
            $id > 0 ? $result['imported']++ : $result['skipped']++;
        }

        return $result;
    }
}
