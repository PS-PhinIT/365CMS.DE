<?php
/**
 * Abruf einer Aggregator-Quelle: lädt den Feed über `FeedService::fetch()` (SSRF-Schutz, Purifier,
 * RSS/RDF/Atom-Parser des Cores), normalisiert die Einträge und speichert neue Artikel.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Feed;

use CMS\Logger;
use CMS\Services\FeedService;

if (!defined('ABSPATH')) {
    exit;
}

final class SourceFetcher
{
    private const DESCRIPTION_LENGTH = 500;

    public function __construct(private readonly AggregatorRepository $repository)
    {
    }

    /**
     * Ruft eine Quelle ab und protokolliert Erfolg oder Fehler an der Quelle.
     *
     * @return array{success:bool,new_items:int,filtered:int,error:?string}
     */
    public function fetch(int $sourceId): array
    {
        $source = $this->repository->source($sourceId);
        if ($source === null) {
            return ['success' => false, 'new_items' => 0, 'filtered' => 0, 'error' => FeedI18n::admin('feeds.fetch_error.not_found')];
        }

        $feedService = FeedService::getInstance();
        try {
            $result = $feedService->fetch((string) $source['feed_url'], 0, true);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('feeds')->warning('Feed-Abruf fehlgeschlagen.', ['source_id' => $sourceId, 'error' => $e->getMessage()]);
            $result = ['success' => false];
        }

        if (empty($result['success'])) {
            $error = self::errorMessage($feedService->getLastError());
            $this->repository->recordFetchError($sourceId, $error);

            return ['success' => false, 'new_items' => 0, 'filtered' => 0, 'error' => $error];
        }

        return $this->import($source, $result);
    }

    /**
     * Speichert bereits geparste Feed-Daten (`FeedService::fetch()`/`parseFeedString()`) für eine Quelle.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $feed
     * @return array{success:bool,new_items:int,filtered:int,error:?string}
     */
    public function import(array $source, array $feed): array
    {
        $sourceId = (int) $source['id'];
        $filter = NoiseFilter::fromSettings(AggregatorSettings::all());
        $items = array_slice(is_array($feed['items'] ?? null) ? $feed['items'] : [], 0, LegacyNormalizer::maxItems($source['max_items'] ?? 50));
        $now = time();
        $new = 0;
        $filtered = 0;
        // Artikel außerhalb der Aufbewahrungsfrist nicht importieren (gleiche Grenze wie pruneItems(), 0 = keine Grenze).
        $retentionDays = (int) AggregatorSettings::get('item_retention_days');
        $cutoff = $retentionDays > 0 ? date('Y-m-d H:i:s', $now - $retentionDays * 86400) : null;

        foreach ($items as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }
            $item = self::normalizeItem($rawItem, $now);
            if ($cutoff !== null && $item['pub_date'] < $cutoff) {
                continue;
            }
            if ($filter->excludes($item)) {
                $filtered++;
                continue;
            }
            $item['source_id'] = $sourceId;
            $item['category_id'] = (int) $source['category_id'];
            if ($this->repository->insertItem($item)) {
                $new++;
            }
        }

        $siteUrl = (string) ($feed['link'] ?? '');
        $iconUrl = (string) ($feed['image'] ?? '');
        if ($iconUrl === '') {
            $parts = parse_url((string) $source['feed_url']);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $iconUrl = $parts['scheme'] . '://' . $parts['host'] . '/favicon.ico';
            }
        }
        $this->repository->recordFetchSuccess($sourceId, $siteUrl, $iconUrl);

        return ['success' => true, 'new_items' => $new, 'filtered' => $filtered, 'error' => null];
    }

    /**
     * Bringt einen Parser-Eintrag in die Form der Tabelle `feeds_items`.
     *
     * @param array<string, mixed> $item
     * @return array<string, string>
     */
    public static function normalizeItem(array $item, ?int $now = null): array
    {
        $now ??= time();
        $title = trim((string) ($item['title'] ?? ''));
        $link = (string) ($item['link'] ?? '');
        $content = (string) ($item['content'] ?? '');
        $descriptionSource = trim((string) ($item['description'] ?? '')) !== '' ? (string) $item['description'] : $content;

        $timestamp = isset($item['date_timestamp']) && is_int($item['date_timestamp']) ? $item['date_timestamp'] : null;
        if ($timestamp === null && !empty($item['date'])) {
            $timestamp = strtotime((string) $item['date']) ?: null;
        }
        if ($timestamp === null || $timestamp > $now) {
            $timestamp = $now;
        }

        $guid = trim((string) ($item['guid'] ?? ''));
        if ($guid === '') {
            $guid = $link !== '' ? $link : hash('sha256', $title . '|' . $timestamp);
        }

        $image = (string) ($item['thumbnail'] ?? '');
        if ($image === '') {
            $image = self::firstImage($content . ' ' . (string) ($item['description'] ?? ''));
        }

        return [
            'guid' => mb_substr($guid, 0, 500),
            'title' => mb_substr($title !== '' ? $title : 'Kein Titel', 0, 500),
            'link' => $link,
            'description' => self::plainExcerpt($descriptionSource, self::DESCRIPTION_LENGTH),
            'content' => $content,
            'author' => mb_substr(trim((string) ($item['author'] ?? '')), 0, 190),
            'image_url' => $image,
            'pub_date' => date('Y-m-d H:i:s', $timestamp),
        ];
    }

    public static function plainExcerpt(string $html, int $length): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br', '</p>'], [' <br', ' </p>'], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $length)) . '…';
    }

    /**
     * Lesbarer Fehlertext für das Abrufprotokoll.
     *
     * @param array{code?:string,detail?:string} $error
     */
    public static function errorMessage(array $error): string
    {
        $code = (string) ($error['code'] ?? '');
        $known = ['invalid_url', 'connection', 'http_status', 'redirect', 'too_large', 'empty', 'invalid_xml', 'unsupported_format', 'parser_unavailable', 'exception'];
        $message = FeedI18n::admin('feeds.fetch_error.' . (in_array($code, $known, true) ? $code : 'unknown'));
        $detail = trim((string) ($error['detail'] ?? ''));

        return mb_substr($detail !== '' ? $message . ' (' . $detail . ')' : $message, 0, 500);
    }

    private static function firstImage(string $html): string
    {
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $match) !== 1) {
            return '';
        }
        $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_match('#^https?://#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
    }
}
