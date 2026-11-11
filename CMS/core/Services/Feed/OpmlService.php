<?php
/**
 * OPML-Import und -Export der Feed-Quellen.
 *
 * Import: DOCTYPE (und damit Entitäten) wird abgelehnt, kein Netzwerkzugriff beim Parsen, höchstens 1 MB und 500
 * Einträge. Verschachtelte `<outline>`-Gruppen ohne `xmlUrl` liefern den Bereichsnamen der darin enthaltenen Feeds.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Feed;

use CMS\Services\FeedService;

if (!defined('ABSPATH')) {
    exit;
}

final class OpmlService
{
    public const MAX_BYTES = 1048576;
    public const MAX_ENTRIES = 500;

    /**
     * @return list<array{name:string,feed_url:string,site_url:string,description:string,group:string}>|null null = keine gültige OPML-Datei
     */
    public static function parse(string $xml): ?array
    {
        if ($xml === '' || strlen($xml) > self::MAX_BYTES || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $document->documentElement === null || strtolower($document->documentElement->nodeName) !== 'opml') {
            return null;
        }

        $entries = [];
        $body = $document->getElementsByTagName('body')->item(0);
        if ($body instanceof \DOMElement) {
            self::walk($body, '', $entries);
        }

        return $entries;
    }

    /**
     * @param list<array<string, string>> $entries
     */
    private static function walk(\DOMElement $parent, string $group, array &$entries): void
    {
        foreach ($parent->childNodes as $node) {
            if (!$node instanceof \DOMElement || strtolower($node->nodeName) !== 'outline' || count($entries) >= self::MAX_ENTRIES) {
                continue;
            }
            $title = trim($node->getAttribute('title') !== '' ? $node->getAttribute('title') : $node->getAttribute('text'));
            $feedUrl = trim($node->getAttribute('xmlUrl'));
            if ($feedUrl === '') {
                self::walk($node, $title !== '' ? $title : $group, $entries);
                continue;
            }
            $entries[] = [
                'name' => mb_substr($title !== '' ? $title : $feedUrl, 0, 255),
                'feed_url' => $feedUrl,
                'site_url' => trim($node->getAttribute('htmlUrl')),
                'description' => mb_substr(trim($node->getAttribute('description')), 0, 1000),
                'group' => mb_substr($group, 0, 150),
            ];
        }
    }

    /**
     * Legt Quellen aus OPML-Einträgen an. `$categoryId` > 0: alle in diesen Bereich; sonst je Gruppe ein Bereich
     * (vorhandene Bereiche gleichen Slugs werden genutzt), Einträge ohne Gruppe in `$fallbackName`.
     *
     * @param list<array{name:string,feed_url:string,site_url:string,description:string,group:string}> $entries
     * @return array{imported:int,skipped:int,invalid:int,categories:int}
     */
    public static function import(array $entries, int $categoryId, string $fallbackName = 'Import'): array
    {
        $repo = AggregatorRepository::getInstance();
        $feedService = FeedService::getInstance();
        $result = ['imported' => 0, 'skipped' => 0, 'invalid' => 0, 'categories' => 0];
        $categoryCache = [];

        foreach ($entries as $entry) {
            $url = $feedService->normalizeRemoteUrl($entry['feed_url']);
            if ($url === '') {
                $result['invalid']++;
                continue;
            }
            if ($repo->sourceUrlExists($url)) {
                $result['skipped']++;
                continue;
            }

            $target = $categoryId;
            if ($target <= 0) {
                $name = $entry['group'] !== '' ? $entry['group'] : $fallbackName;
                $slug = LegacyNormalizer::categorySlug('', $name);
                if (!isset($categoryCache[$slug])) {
                    $existing = $repo->categoryBySlug($slug);
                    if ($existing !== null) {
                        $categoryCache[$slug] = (int) $existing['id'];
                    } else {
                        $categoryCache[$slug] = $repo->saveCategory(['name' => mb_substr($name, 0, 150), 'slug' => $slug, 'is_public' => true]);
                        if ($categoryCache[$slug] > 0) {
                            $result['categories']++;
                        }
                    }
                }
                $target = $categoryCache[$slug];
            }
            if ($target <= 0) {
                $result['invalid']++;
                continue;
            }

            $id = $repo->saveSource([
                'category_id' => $target,
                'name' => $entry['name'],
                'feed_url' => $url,
                'site_url' => AggregatorFrontendController::safeUrl($entry['site_url']),
                'description' => $entry['description'],
                'is_active' => true,
            ]);
            $id > 0 ? $result['imported']++ : $result['invalid']++;
        }

        return $result;
    }

    /**
     * OPML 2.0 aller Quellen, gruppiert nach Bereich.
     */
    public static function export(): string
    {
        $repo = AggregatorRepository::getInstance();
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $opml = $document->appendChild($document->createElement('opml'));
        $opml->setAttribute('version', '2.0');
        $head = $opml->appendChild($document->createElement('head'));
        $head->appendChild($document->createElement('title'))->appendChild($document->createTextNode((defined('SITE_NAME') ? (string) SITE_NAME : '365CMS') . ' – Feeds'));
        $head->appendChild($document->createElement('dateCreated'))->appendChild($document->createTextNode(gmdate(DATE_RFC2822)));
        $body = $opml->appendChild($document->createElement('body'));

        $groups = [];
        foreach ($repo->sources() as $source) {
            $groups[(int) $source['category_id']][] = $source;
        }
        foreach ($repo->categories() as $category) {
            $sources = $groups[(int) $category['id']] ?? [];
            if ($sources === []) {
                continue;
            }
            $group = $body->appendChild($document->createElement('outline'));
            $group->setAttribute('text', (string) $category['name']);
            $group->setAttribute('title', (string) $category['name']);
            foreach ($sources as $source) {
                $outline = $group->appendChild($document->createElement('outline'));
                $outline->setAttribute('type', 'rss');
                $outline->setAttribute('text', (string) $source['name']);
                $outline->setAttribute('title', (string) $source['name']);
                $outline->setAttribute('xmlUrl', (string) $source['feed_url']);
                if ((string) ($source['site_url'] ?? '') !== '') {
                    $outline->setAttribute('htmlUrl', (string) $source['site_url']);
                }
                if ((string) ($source['description'] ?? '') !== '') {
                    $outline->setAttribute('description', (string) $source['description']);
                }
            }
        }

        return (string) $document->saveXML();
    }
}
