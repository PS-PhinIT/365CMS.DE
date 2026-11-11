<?php
/**
 * Hooks des Funktionsbereichs „Suchindex“ (Roadmap #7).
 *
 * - meldet die Such-Provider für Seiten und Beiträge an der Inhaltstyp-Registry an,
 * - hält den Suchindex über die Inhalts-Hooks aktuell (speichern, kopieren, löschen; Papierkorb und
 *   Wiederherstellen lösen die Speicher-Hooks aus) und gleicht stündlich Änderungen ohne Hooks ab,
 * - ergänzt die Suchseite um Treffer aus Providern ohne eigene Ergebnisdarstellung (Filter `search_results`),
 * - protokolliert Frontend-Suchen anonym (Action `cms_after_site_search`), verdichtet sie täglich und
 *   meldet die Aufbewahrungsregel `search_terms` an.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Logger;
use CMS\Services\ContentTypes\ContentTypeRegistry;
use CMS\Services\ContentTypes\SearchDocument;
use CMS\Services\DataRetentionService;
use CMS\Services\Search\PageSearchProvider;
use CMS\Services\Search\PostSearchProvider;
use CMS\Services\Search\RendersOwnSearchResults;
use CMS\Services\Search\SearchIndexService;
use CMS\Services\Search\SearchTermStatsService;
use CMS\Services\SearchService;
use CMS\Services\SiteSearchService;

if (!defined('ABSPATH')) {
    exit;
}

final class SearchIndexHooks
{
    /** Höchstzahl zusätzlicher Treffer je Provider auf der Suchseite. */
    private const MAX_RESULTS_PER_PROVIDER = 20;

    public static function register(): void
    {
        Hooks::addAction(ContentTypeRegistry::REGISTER_ACTION, static function (ContentTypeRegistry $registry): void {
            $registry->register(new PageSearchProvider());
            $registry->register(new PostSearchProvider());
        }, 10);

        $hooks = [
            'cms_after_post_save' => ['post', false],
            'cms_after_post_copy_de_to_en' => ['post', false],
            'post_deleted' => ['post', true],
            'cms_after_page_save' => ['page', false],
            'cms_after_page_copy_de_to_en' => ['page', false],
            'page_deleted' => ['page', true],
        ];
        foreach ($hooks as $hook => [$type, $deleted]) {
            Hooks::addAction($hook, static function (mixed $id = 0) use ($type, $deleted): void {
                self::guard('Index-Aktualisierung', static function () use ($type, $deleted, $id): void {
                    $service = SearchIndexService::getInstance();
                    $deleted ? $service->remove($type, (int) $id) : $service->refresh($type, (int) $id);
                });
            }, 60);
        }

        Hooks::addFilter('search_results', [self::class, 'appendIndexResults'], 90);

        Hooks::addAction('cms_after_site_search', static function (mixed $parsed = [], mixed $hits = 0, mixed $locale = 'de'): void {
            if (!is_array($parsed) || !SearchTermStatsService::isCountableRequest($_SERVER)) {
                return;
            }

            self::guard('Suchbegriff-Protokoll', static function () use ($parsed, $hits, $locale): void {
                SearchTermStatsService::getInstance()->record((string) ($parsed['query'] ?? ''), (int) $hits, (string) $locale);
            });
        }, 10);

        Hooks::addAction('cms_init', static function (): void {
            DataRetentionService::registerRule(SearchTermStatsService::getInstance()->retentionRule());
        }, 10);

        // Vor der Datenaufbewahrung (Priorität 40): erst verdichten, dann alte Tageswerte löschen.
        Hooks::addAction('cms_cron_daily', static function (): void {
            self::guard('Statistik-Verdichtung', static function (): void {
                SearchTermStatsService::getInstance()->aggregate();
            });
        }, 30);

        Hooks::addAction('cms_cron_hourly', static function (): void {
            self::guard('Abgleich', static function (): void {
                SearchIndexService::getInstance()->reconcile();
            });
        }, 50);
    }

    /**
     * Filter `search_results` (Suchseite): Treffer aus Such-Providern ohne eigene Ergebnisdarstellung.
     *
     * Jeder Indextreffer wird beim Provider neu geladen (nur öffentlich sichtbare Inhalte) und muss alle
     * Suchbegriffe enthalten – exakt oder in der Grundform der Indexsprache.
     */
    public static function appendIndexResults(mixed $results, mixed $query = '', mixed $limit = 20, mixed $context = []): mixed
    {
        if (!is_array($results) || !is_array($context) || ($context['source'] ?? '') !== 'search_page') {
            return $results;
        }

        try {
            $parsed = SiteSearchService::parseQuery(is_string($query) ? $query : '');
            if ($parsed['terms'] === [] || $parsed['words'] === []) {
                return $results;
            }

            $scope = strtolower(trim((string) ($context['type'] ?? '')));
            $locale = SearchTermStatsService::normalizeLocale((string) ($context['locale'] ?? 'de'));
            $max = max(1, min(self::MAX_RESULTS_PER_PROVIDER, is_numeric($limit) ? (int) $limit : 20));
            $seen = [];
            foreach ($results as $row) {
                if (is_array($row) && isset($row['url'])) {
                    $seen[(string) $row['url']] = true;
                }
            }

            foreach (SearchIndexService::getInstance()->providers() as $type => $provider) {
                if ($provider instanceof RendersOwnSearchResults || ($scope !== '' && $scope !== $type)) {
                    continue;
                }

                $added = 0;
                foreach (SearchService::getInstance()->searchDocumentIndex($type, $locale, $parsed['words'], $max * 3) as $id => $score) {
                    if ($added >= $max) {
                        break;
                    }

                    $document = $provider->document((int) $id, $locale);
                    if (!$document instanceof SearchDocument || $document->url === '' || isset($seen[$document->url])) {
                        continue;
                    }

                    $text = SiteSearchService::fold($document->title . ' ' . $document->excerpt . ' ' . $document->content);
                    if (SiteSearchService::matchingTerms($text, $parsed['terms'], $locale) === null) {
                        continue;
                    }

                    $seen[$document->url] = true;
                    $results[] = [
                        'title' => $document->title,
                        'url' => $document->url,
                        'excerpt' => $document->excerpt,
                        '_type' => $type,
                        '_type_label' => $provider->label(),
                        'date' => $document->updatedAt,
                    ];
                    $added++;
                }
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('search')->warning('Suchindex: Treffer für die Suchseite konnten nicht geladen werden.', ['exception' => $e]);
        }

        return $results;
    }

    private static function guard(string $task, \Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('search')->warning('Suchindex: ' . $task . ' fehlgeschlagen.', ['exception' => $e]);
        }
    }
}
