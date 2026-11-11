<?php
/**
 * Rauschfilter des Feed-Aggregators: schließt Artikel nach Stichwort, Autor oder Domain aus.
 *
 * Listen sind zeilenweise gepflegt (Einstellungen `noise_exclude_*`), Vergleich ohne Groß-/Kleinschreibung.
 * Stichworte prüfen Titel, Beschreibung und Inhalt; Autoren als Teilstring; Domains exakt oder als Subdomain.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Feed;

if (!defined('ABSPATH')) {
    exit;
}

final class NoiseFilter
{
    /**
     * @param list<string> $keywords
     * @param list<string> $authors
     * @param list<string> $domains
     */
    public function __construct(
        private readonly array $keywords = [],
        private readonly array $authors = [],
        private readonly array $domains = [],
    ) {
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function fromSettings(array $settings): self
    {
        $domains = [];
        foreach (self::parseList((string) ($settings['noise_exclude_domains'] ?? '')) as $domain) {
            $domain = self::normalizeDomain($domain);
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return new self(
            self::parseList((string) ($settings['noise_exclude_keywords'] ?? '')),
            self::parseList((string) ($settings['noise_exclude_authors'] ?? '')),
            array_values(array_unique($domains)),
        );
    }

    /**
     * @return list<string>
     */
    public static function parseList(string $value): array
    {
        $result = [];
        foreach (preg_split('/\R/u', $value) ?: [] as $line) {
            $line = mb_strtolower(trim($line), 'UTF-8');
            if ($line !== '') {
                $result[] = $line;
            }
        }

        return array_values(array_unique($result));
    }

    public function isEmpty(): bool
    {
        return $this->keywords === [] && $this->authors === [] && $this->domains === [];
    }

    /**
     * @param array<string, mixed> $item Felder `title`, `description`, `content`, `author`, `link`
     */
    public function excludes(array $item): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        if ($this->keywords !== []) {
            $haystack = mb_strtolower(
                html_entity_decode(strip_tags(
                    (string) ($item['title'] ?? '') . ' ' . (string) ($item['description'] ?? '') . ' ' . (string) ($item['content'] ?? '')
                ), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'UTF-8'
            );
            foreach ($this->keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return true;
                }
            }
        }

        if ($this->authors !== []) {
            $author = mb_strtolower((string) ($item['author'] ?? ''), 'UTF-8');
            if ($author !== '') {
                foreach ($this->authors as $needle) {
                    if (str_contains($author, $needle)) {
                        return true;
                    }
                }
            }
        }

        if ($this->domains !== []) {
            $host = self::normalizeDomain((string) parse_url((string) ($item['link'] ?? ''), PHP_URL_HOST));
            if ($host !== '') {
                foreach ($this->domains as $domain) {
                    if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function normalizeDomain(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        if (str_contains($value, '://')) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        }
        $value = preg_replace('#[/?\#].*$#', '', $value) ?? '';
        $value = trim(preg_replace('/^www\./', '', $value) ?? '', '. ');

        return $value;
    }
}
