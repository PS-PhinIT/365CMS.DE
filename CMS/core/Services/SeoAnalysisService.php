<?php
declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Json;

if (!defined('ABSPATH')) {
    exit;
}

final class SeoAnalysisService
{
    private static ?self $instance = null;
    private Database $db;
    private string $prefix;

    private const TRANSITION_WORDS = [
        'außerdem', 'zudem', 'darüber hinaus', 'deshalb', 'daher', 'allerdings', 'jedoch', 'dennoch',
        'somit', 'folglich', 'beispielsweise', 'zum beispiel', 'anschließend', 'danach', 'gleichzeitig',
        'insbesondere', 'letztlich', 'schließlich', 'trotzdem', 'weiterhin', 'zuerst', 'daneben', 'hingegen',
    ];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    public function getSettings(): array
    {
        return [
            'site_title_format' => $this->getSetting('site_title_format', '%%title%% %%sep%% %%sitename%%'),
            'title_separator' => $this->getSetting('title_separator', '|'),
            'analysis_min_words' => max(100, (int)$this->getSetting('analysis_min_words', '300')),
            'analysis_sentence_words' => max(12, (int)$this->getSetting('analysis_sentence_words', '24')),
            'analysis_paragraph_words' => max(40, (int)$this->getSetting('analysis_paragraph_words', '120')),
        ];
    }

    public function enrichAuditRows(array $rows): array
    {
        $enriched = [];
        foreach ($rows as $row) {
            $analysis = $this->analyze($row);
            $row['resolved_meta_title'] = $analysis['resolved_meta_title'];
            $row['resolved_meta_description'] = $analysis['resolved_meta_description'];
            $row['analysis'] = $analysis;
            $enriched[] = $row;
        }

        return $enriched;
    }

    public function resolveMetaTitle(array $context): string
    {
        $settings = $this->getSettings();
        $title = trim((string)($context['title'] ?? ''));
        $explicit = trim((string)($context['meta_title'] ?? ''));

        // Ein gepflegter Meta-Titel kann eine importierte Vorlage sein (Yoast/Rank Math: „%title% %sep% %sitename%“).
        $template = $explicit !== '' ? $explicit : (string)$settings['site_title_format'];
        $resolved = $this->resolveTemplateVariables($template, $context);

        return $resolved !== '' ? $resolved : $title;
    }

    /**
     * Ersetzt Titel-/Beschreibungs-Variablen in beiden Schreibweisen (%%var%% wie Yoast, %var% wie Rank Math)
     * und entfernt unbekannte Variablen samt übrig gebliebener Trennzeichen, damit nie Rohplatzhalter
     * im Dokumenttitel, in Open Graph oder in Suchergebnissen landen.
     *
     * @param array<string,mixed> $context
     */
    public function resolveTemplateVariables(string $template, array $context = []): string
    {
        if (!str_contains($template, '%')) {
            return trim($template);
        }

        $separator = (string)$this->getSettings()['title_separator'];
        $title = trim((string)($context['title'] ?? ''));
        $excerpt = trim((string)($context['excerpt'] ?? ''));
        $pageNumber = (int)($context['page_number'] ?? 0);
        $values = [
            'title' => $title,
            'pagetitle' => $title,
            'post_title' => $title,
            'sitename' => defined('SITE_NAME') ? (string)SITE_NAME : '365CMS',
            'sep' => $separator,
            'separator' => $separator,
            'excerpt' => $excerpt,
            'excerpt_only' => $excerpt,
            'seo_description' => $excerpt,
            'slug' => trim((string)($context['slug'] ?? '')),
            'category' => trim((string)($context['category_name'] ?? '')),
            'primary_category' => trim((string)($context['category_name'] ?? '')),
            'page' => $pageNumber > 1 ? 'Seite ' . $pageNumber : '',
            'pagenumber' => $pageNumber > 1 ? (string)$pageNumber : '',
            'currentyear' => date('Y'),
            'currentmonth' => date('m'),
            'currentday' => date('d'),
            'currentdate' => date('d.m.Y'),
            'sitedesc' => '',
            'tagline' => '',
        ];

        $escapedSeparator = preg_quote($separator, '/');
        $resolved = preg_replace_callback(
            '/%%?([a-z][a-z0-9_]*)(?:\([^)%]*\))?%%?/i',
            static fn(array $match): string => $values[strtolower($match[1])] ?? '',
            $template
        ) ?? $template;

        $resolved = preg_replace('/\s+/u', ' ', $resolved) ?? $resolved;
        if ($escapedSeparator !== '') {
            // Trennzeichen ohne Inhalt dazwischen bzw. am Rand entfernen („ | | Name“, „Titel |“).
            $resolved = preg_replace('/(?:\s*' . $escapedSeparator . ')+\s*$/u', '', $resolved) ?? $resolved;
            $resolved = preg_replace('/^\s*(?:' . $escapedSeparator . '\s*)+/u', '', $resolved) ?? $resolved;
            $resolved = preg_replace('/' . $escapedSeparator . '(?:\s*' . $escapedSeparator . ')+/u', $separator, $resolved) ?? $resolved;
        }

        return trim($resolved);
    }

    public function resolveMetaDescription(array $context): string
    {
        $explicit = trim((string)($context['meta_description'] ?? ''));
        if ($explicit !== '') {
            $explicit = $this->resolveTemplateVariables($explicit, $context);
            if ($explicit !== '') {
                return $explicit;
            }
        }

        $excerpt = trim((string)($context['excerpt'] ?? ''));
        if ($excerpt !== '') {
            return $this->truncate($excerpt, 155);
        }

        $firstParagraph = $this->extractFirstParagraph((string)($context['content'] ?? ''));
        if ($firstParagraph !== '') {
            return $this->truncate($firstParagraph, 155);
        }

        return $this->truncate($this->extractPlainText((string)($context['content'] ?? '')), 155);
    }

    public function analyze(array $context): array
    {
        $settings = $this->getSettings();
        $title = trim((string)($context['title'] ?? ''));
        $slug = trim((string)($context['slug'] ?? ''));
        $content = (string)($context['content'] ?? '');
        $plainText = $this->extractPlainText($content);
        $wordCount = $this->countWords($plainText);
        $sentences = $this->splitSentences($plainText);
        $paragraphs = $this->splitParagraphs($plainText);
        $focusRaw = trim((string)($context['focus_keyphrase'] ?? ''));
        $keyphrases = $this->parseKeyphrases($focusRaw);
        $primaryKeyphrase = $keyphrases[0] ?? '';
        $resolvedTitle = $this->resolveMetaTitle($context);
        $resolvedDescription = $this->resolveMetaDescription($context);
        $links = $this->extractLinks($content);
        $images = $this->extractImages($content);
        $headingText = trim((string)($context['title'] ?? ''));
        $intro = $this->extractIntroText($plainText, 0.1);

        $rules = [];
        $addRule = static function (array &$rules, string $key, string $label, bool $passed, string $message, int $weight = 8): void {
            $rules[] = [
                'key' => $key,
                'label' => $label,
                'passed' => $passed,
                'message' => $message,
                'weight' => $weight,
            ];
        };

        $titleLength = $this->strlen($resolvedTitle);
        $descLength = $this->strlen($resolvedDescription);
        $density = $primaryKeyphrase !== '' ? $this->calculateKeyphraseDensity($plainText, $primaryKeyphrase, $wordCount) : 0.0;
        $introHasKeyphrase = $primaryKeyphrase !== '' && $this->containsPhrase($intro, $primaryKeyphrase);
        $headingHasKeyphrase = $primaryKeyphrase !== '' && $this->containsPhrase($headingText, $primaryKeyphrase);
        $slugHasKeyphrase = $primaryKeyphrase !== '' && $this->containsPhrase(str_replace('-', ' ', $slug), $primaryKeyphrase);
        $transitionHits = $this->countTransitionWords($plainText);
        $passiveHits = $this->countPassiveVoiceMatches($plainText);
        $longSentenceCount = $this->countLongSentences($sentences, (int)$settings['analysis_sentence_words']);
        $longParagraphCount = $this->countLongParagraphs($paragraphs, (int)$settings['analysis_paragraph_words']);
        $missingAltCount = $this->countMissingAltTexts($images);

        $addRule($rules, 'meta_title', 'Meta-Titel gepflegt', $titleLength >= 30 && $titleLength <= 60, $titleLength === 0 ? 'Meta-Titel fehlt' : 'Aktuell ' . $titleLength . ' Zeichen', 10);
        $addRule($rules, 'meta_description', 'Meta-Beschreibung gepflegt', $descLength >= 120 && $descLength <= 155, $descLength === 0 ? 'Meta-Beschreibung fehlt' : 'Aktuell ' . $descLength . ' Zeichen', 10);
        $addRule($rules, 'focus_keyphrase', 'Fokus-Keyphrase gesetzt', $primaryKeyphrase !== '', $primaryKeyphrase !== '' ? 'Keyphrase: ' . $primaryKeyphrase : 'Bitte Fokus-Keyphrase ergänzen', 10);
        $addRule($rules, 'keyphrase_title', 'Keyphrase im Titel/H1', $primaryKeyphrase !== '' && $headingHasKeyphrase, $headingHasKeyphrase ? 'Keyphrase im Titel gefunden' : 'Keyphrase fehlt im Titel', 8);
        $addRule($rules, 'keyphrase_slug', 'Keyphrase in URL/Slug', $primaryKeyphrase !== '' && $slugHasKeyphrase, $slugHasKeyphrase ? 'Keyphrase steckt im Slug' : 'Slug ohne Fokus-Keyphrase', 8);
        $addRule($rules, 'keyphrase_intro', 'Keyphrase in Einleitung', $primaryKeyphrase !== '' && $introHasKeyphrase, $introHasKeyphrase ? 'Keyphrase früh platziert' : 'Einleitung ohne Fokus-Keyphrase', 6);
        $addRule($rules, 'keyphrase_density', 'Keyphrase-Dichte', $primaryKeyphrase !== '' && $density >= 0.5 && $density <= 3.5, $primaryKeyphrase === '' ? 'Ohne Keyphrase keine Dichte-Berechnung' : number_format($density, 2, ',', '.') . '%', 8);
        $addRule($rules, 'text_length', 'Textlänge', $wordCount >= (int)$settings['analysis_min_words'], $wordCount . ' Wörter', 8);
        $addRule($rules, 'internal_links', 'Mindestens ein interner Link', $links['internal'] >= 1, $links['internal'] . ' interne Links', 6);
        $addRule($rules, 'external_links', 'Mindestens ein externer Link', $links['external'] >= 1, $links['external'] . ' externe Links', 6);
        $addRule($rules, 'image_alt', 'Bild-Alt-Texte', $images['count'] === 0 || $missingAltCount === 0, $images['count'] === 0 ? 'Keine Bilder im Inhalt' : $missingAltCount . ' Bilder ohne Alt-Text', 6);
        $addRule($rules, 'sentence_length', 'Satzlänge', $longSentenceCount <= 3, $longSentenceCount . ' lange Sätze', 6);
        $addRule($rules, 'paragraph_length', 'Absatzlänge', $longParagraphCount <= 2, $longParagraphCount . ' sehr lange Absätze', 6);
        $addRule($rules, 'passive_voice', 'Passive Formulierungen', $passiveHits <= 3, $passiveHits . ' passive Marker erkannt', 4);
        $addRule($rules, 'transition_words', 'Signalwörter', $transitionHits >= 2, $transitionHits . ' Transition-Words gefunden', 4);
        $addRule($rules, 'og_image', 'Social-Vorschaubild', trim((string)($context['og_image'] ?? $context['featured_image'] ?? '')) !== '', trim((string)($context['og_image'] ?? $context['featured_image'] ?? '')) !== '' ? 'Vorschaubild vorhanden' : 'OG-/Beitragsbild fehlt', 10);

        $weightTotal = array_sum(array_column($rules, 'weight')) ?: 1;
        $score = 0;
        foreach ($rules as $rule) {
            if ($rule['passed']) {
                $score += (int)$rule['weight'];
            }
        }
        $score = (int)round(($score / $weightTotal) * 100);

        return [
            'resolved_meta_title' => $resolvedTitle,
            'resolved_meta_description' => $resolvedDescription,
            'score' => $score,
            'status' => $score >= 80 ? 'good' : ($score >= 55 ? 'warning' : 'bad'),
            'rules' => $rules,
            'stats' => [
                'word_count' => $wordCount,
                'internal_links' => $links['internal'],
                'external_links' => $links['external'],
                'density' => $density,
                'transition_words' => $transitionHits,
                'passive_hits' => $passiveHits,
                'long_sentences' => $longSentenceCount,
                'long_paragraphs' => $longParagraphCount,
                'missing_alt_texts' => $missingAltCount,
            ],
            'required_fields' => [
                'meta_title' => trim((string)($context['meta_title'] ?? '')) !== '',
                'meta_description' => trim((string)($context['meta_description'] ?? '')) !== '',
                'og_image' => trim((string)($context['og_image'] ?? $context['featured_image'] ?? '')) !== '',
            ],
        ];
    }

    private function getSetting(string $key, string $default = ''): string
    {
        try {
            // Über den Options-Cache: vorher eine eigene Abfrage je Schlüssel (20 je Beitragsseite,
            // ~15.000 beim SEO-Audit mit 1.000 Zeilen).
            return (string) (OptionStore::getInstance()->get('seo_' . $key, $default) ?? $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    private function parseKeyphrases(string $value): array
    {
        $phrases = array_filter(array_map(static fn(string $part): string => trim(mb_strtolower($part)), preg_split('/[,;\n]+/', $value) ?: []));
        return array_values(array_unique($phrases));
    }

    private function extractPlainText(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        $decoded = Json::decodeArray($content, []);
        if (is_array($decoded)) {
            $parts = [];
            $this->collectTextParts($decoded, $parts);
            return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
        }

        return trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
    }

    private function collectTextParts(mixed $value, array &$parts): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->collectTextParts($item, $parts);
            }
            return;
        }

        if (is_string($value)) {
            $clean = trim(strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($clean !== '') {
                $parts[] = $clean;
            }
        }
    }

    private function extractFirstParagraph(string $content): string
    {
        if (preg_match('/<p\b[^>]*>(.*?)<\/p>/is', $content, $matches) === 1) {
            return trim(strip_tags(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }

        $plain = $this->extractPlainText($content);
        $paragraphs = $this->splitParagraphs($plain);
        return $paragraphs[0] ?? '';
    }

    private function extractIntroText(string $plainText, float $ratio): string
    {
        $length = $this->strlen($plainText);
        if ($length === 0) {
            return '';
        }

        $take = max(120, (int)round($length * $ratio));
        return $this->substr($plainText, 0, $take);
    }

    private function extractLinks(string $content): array
    {
        $internal = 0;
        $external = 0;
        if (preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\']/i', $content, $matches) === 1 || !empty($matches[1])) {
            foreach ($matches[1] as $href) {
                $href = trim((string)$href);
                if ($href === '') {
                    continue;
                }
                if (str_starts_with($href, '/') || (defined('SITE_URL') && str_starts_with($href, (string)SITE_URL))) {
                    $internal++;
                } else {
                    $external++;
                }
            }
        }

        return ['internal' => $internal, 'external' => $external];
    }

    private function extractImages(string $content): array
    {
        $images = [];
        if (preg_match_all('/<img\b[^>]*>/i', $content, $matches) !== 1 && empty($matches[0])) {
            return ['count' => 0, 'images' => []];
        }

        foreach ($matches[0] as $tag) {
            preg_match('/alt=["\']([^"\']*)["\']/i', $tag, $altMatch);
            $images[] = ['alt' => trim((string)($altMatch[1] ?? ''))];
        }

        return ['count' => count($images), 'images' => $images];
    }

    private function countMissingAltTexts(array $images): int
    {
        $missing = 0;
        foreach ($images['images'] ?? [] as $image) {
            if (trim((string)($image['alt'] ?? '')) === '') {
                $missing++;
            }
        }

        return $missing;
    }

    private function countWords(string $plainText): int
    {
        preg_match_all('/[\p{L}\p{N}\-]+/u', $plainText, $matches);
        return count($matches[0]);
    }

    private function splitSentences(string $plainText): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', $plainText) ?: [];
        return array_values(array_filter(array_map('trim', $parts)));
    }

    private function splitParagraphs(string $plainText): array
    {
        $parts = preg_split('/\n\s*\n|\r\n\s*\r\n/u', $plainText) ?: [];
        if ($parts === [] || count($parts) === 1) {
            $parts = preg_split('/(?<=[.!?])\s{2,}/u', $plainText) ?: [];
        }
        return array_values(array_filter(array_map('trim', $parts)));
    }

    private function countLongSentences(array $sentences, int $maxWords): int
    {
        $count = 0;
        foreach ($sentences as $sentence) {
            if ($this->countWords($sentence) > $maxWords) {
                $count++;
            }
        }
        return $count;
    }

    private function countLongParagraphs(array $paragraphs, int $maxWords): int
    {
        $count = 0;
        foreach ($paragraphs as $paragraph) {
            if ($this->countWords($paragraph) > $maxWords) {
                $count++;
            }
        }
        return $count;
    }

    private function countTransitionWords(string $plainText): int
    {
        $count = 0;
        $haystack = mb_strtolower($plainText);
        foreach (self::TRANSITION_WORDS as $word) {
            $count += preg_match_all('/\b' . preg_quote($word, '/') . '\b/u', $haystack);
        }
        return $count;
    }

    private function countPassiveVoiceMatches(string $plainText): int
    {
        $patterns = [
            '/\bwird\b[^.!?]{0,40}\b(?:von|durch|worden)\b/ui',
            '/\bwurden\b/ui',
            '/\bworden\b/ui',
            '/\bist\b[^.!?]{0,30}\bworden\b/ui',
        ];

        $count = 0;
        foreach ($patterns as $pattern) {
            $count += preg_match_all($pattern, $plainText);
        }
        return $count;
    }

    private function calculateKeyphraseDensity(string $plainText, string $phrase, int $wordCount): float
    {
        if ($phrase === '' || $wordCount === 0) {
            return 0.0;
        }

        $normalizedText = mb_strtolower($plainText);
        $hits = preg_match_all('/\b' . preg_quote($phrase, '/') . '\b/u', $normalizedText);
        return $hits > 0 ? ($hits / $wordCount) * 100 : 0.0;
    }

    private function containsPhrase(string $haystack, string $phrase): bool
    {
        return $phrase !== '' && mb_stripos(mb_strtolower($haystack), mb_strtolower($phrase)) !== false;
    }

    private function truncate(string $value, int $maxLength): string
    {
        $value = trim($value);
        if ($this->strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim($this->substr($value, 0, $maxLength - 1)) . '…';
    }

    private function strlen(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function substr(string $value, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null ? mb_substr($value, $start) : mb_substr($value, $start, $length);
        }

        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}
