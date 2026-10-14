<?php
/**
 * Site Search Service – Seitensuche für /search
 *
 * Treffer müssen alle Suchbegriffe im sichtbaren Text enthalten (Titel, Auszug, Inhalt).
 * Editor.js-JSON und HTML werden vor dem Abgleich in reinen Text umgewandelt, damit
 * JSON-Schlüssel, Blocktypen, CSS-Klassen oder Link-Adressen („text“, „version“, „https“ …)
 * keine Treffer erzeugen. Sortierung nach Relevanz oder Datum.
 *
 * Ablauf je Inhaltstyp: Die Datenbank liefert per LIKE eine Vorauswahl (jedes Wort in einer der
 * Spalten, neueste zuerst), anschließend prüft PHP den reinen Text und berechnet die Relevanz.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class SiteSearchService
{
    public const SORT_RELEVANCE = 'relevance';
    public const SORT_DATE = 'date';

    /** Höchstzahl an Treffern je Typ auf der Suchseite. */
    public const RESULT_LIMITS = [
        'post' => 60,
        'page' => 30,
        'category' => 20,
        'tag' => 20,
    ];

    private const MAX_QUERY_LENGTH = 200;
    private const MAX_TERMS = 8;

    /** Vorauswahl je Inhaltstyp aus der Datenbank (neueste zuerst), bevor der Textabgleich greift. */
    private const CANDIDATE_LIMIT = 400;

    /** Kurze Begriffe (≤ 3 Zeichen mit Buchstaben) müssen am Wortanfang stehen: „EWS“ trifft nicht „News“. */
    private const WORD_START_MAX_LENGTH = 3;

    private const SORT_ALIASES = [
        'relevance' => self::SORT_RELEVANCE,
        'relevanz' => self::SORT_RELEVANCE,
        'score' => self::SORT_RELEVANCE,
        'date' => self::SORT_DATE,
        'datum' => self::SORT_DATE,
        'newest' => self::SORT_DATE,
        'neueste' => self::SORT_DATE,
        'neu' => self::SORT_DATE,
        'date_desc' => self::SORT_DATE,
    ];

    /** Editor.js-Datenfelder ohne sichtbaren Text (Adressen, Layout, Technik). */
    private const NON_TEXT_KEYS = [
        'url', 'file', 'link', 'href', 'src', 'image', 'icon', 'id', 'type', 'style', 'level',
        'alignment', 'align', 'stretched', 'withborder', 'withbackground', 'withheadings',
        'service', 'source', 'embed', 'width', 'height', 'color', 'background', 'checked',
        'language', 'lang', 'mime', 'extension', 'size', 'target', 'rel', 'class', 'tunes',
    ];

    private const FOLD_MAP = [
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'à' => 'a', 'á' => 'a', 'â' => 'a',
        'ã' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e',
        'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe', 'ù' => 'u', 'ú' => 'u',
        'û' => 'u', 'ý' => 'y', 'ÿ' => 'y',
    ];

    /** Satzzeichen, die am Rand eines Suchworts entfernt werden. */
    private const TRIM_CHARACTERS = " \t\n\r\0\x0B.,;:!?()[]{}<>\"'`´„“”‚‘’«»…*";

    private static ?self $instance = null;

    private readonly Database $db;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
    }

    // ──────────────────────────────────────────────────────────
    //  Suchbegriff und Sortierung
    // ──────────────────────────────────────────────────────────

    /**
     * Zerlegt die Eingabe in Suchbegriffe.
     *
     * `terms` sind gefaltet (klein, ohne Akzente, Bindestriche als Leerzeichen) und werden im reinen
     * Text geprüft; Phrasen in Anführungszeichen bleiben zusammen. `words` sind die einzelnen Wörter
     * in Kleinschreibung für die LIKE-Vorauswahl (Groß-/Kleinschreibung und Akzente regelt die
     * Kollation der Datenbank).
     *
     * @return array{query:string, terms:list<string>, words:list<string>}
     */
    public static function parseQuery(string $query): array
    {
        $query = trim((string) preg_replace('/\s+/u', ' ', strip_tags($query)));
        $query = mb_substr($query, 0, self::MAX_QUERY_LENGTH);

        $chunks = [];
        $rest = (string) preg_replace_callback(
            '/["„“”]([^"„“”]+)["„“”]/u',
            static function (array $match) use (&$chunks): string {
                $chunks[] = $match[1];
                return ' ';
            },
            $query
        );
        foreach (preg_split('/\s+/u', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $chunks[] = $word;
        }

        $terms = [];
        $words = [];
        foreach ($chunks as $chunk) {
            $term = trim(self::fold($chunk), self::TRIM_CHARACTERS);
            if (mb_strlen($term) < 2 || isset($terms[$term])) {
                continue;
            }

            $terms[$term] = true;
            foreach (preg_split('/[\s\p{Pd}_]+/u', mb_strtolower($chunk, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $word = trim($word, self::TRIM_CHARACTERS);
                if (mb_strlen($word) >= 2) {
                    $words[$word] = true;
                }
            }

            if (count($terms) >= self::MAX_TERMS) {
                break;
            }
        }

        // array_keys() liefert Zahlen wie „365“ als int – Begriffe bleiben Strings.
        return [
            'query' => $query,
            'terms' => array_map('strval', array_keys($terms)),
            'words' => array_map('strval', array_slice(array_keys($words), 0, self::MAX_TERMS * 2)),
        ];
    }

    public static function normalizeSort(mixed $sort): string
    {
        $sort = is_string($sort) ? strtolower(trim($sort)) : '';

        return self::SORT_ALIASES[$sort] ?? self::SORT_RELEVANCE;
    }

    /** Kleinschreibung, Akzente entfernen, Bindestriche/Unterstriche als Worttrenner. */
    public static function fold(string $text): string
    {
        $text = strtr(mb_strtolower($text, 'UTF-8'), self::FOLD_MAP);
        $text = (string) preg_replace('/[\s\p{Z}\p{Pd}_]+/u', ' ', $text);

        return trim($text);
    }

    // ──────────────────────────────────────────────────────────
    //  Text und Abgleich
    // ──────────────────────────────────────────────────────────

    /** Sichtbarer Text aus Editor.js-JSON oder HTML. */
    public static function plainText(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        if ($content[0] === '{' || $content[0] === '[') {
            $decoded = json_decode($content, true, 512);
            if (is_array($decoded)) {
                $parts = [];
                $blocks = $decoded['blocks'] ?? null;
                if (is_array($blocks)) {
                    foreach ($blocks as $block) {
                        if (is_array($block) && isset($block['data'])) {
                            self::collectText($block['data'], $parts);
                        }
                    }
                } else {
                    self::collectText($decoded, $parts);
                }

                // Blöcke als eigene Absätze zusammenfügen, HTML aus Inline-Formatierungen einmal entfernen.
                return self::htmlToText(implode('</p>', $parts));
            }
        }

        return self::htmlToText($content);
    }

    /**
     * @param list<string> $terms gefaltete Begriffe aus parseQuery()
     */
    public static function matchesAll(string $foldedText, array $terms): bool
    {
        if ($terms === []) {
            return false;
        }

        foreach ($terms as $term) {
            if (!self::containsTerm($foldedText, $term)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Relevanz aus Titel, Auszug und Inhalt (reiner Text).
     *
     * @param list<string> $terms
     */
    public static function score(string $title, string $excerpt, string $body, array $terms): float
    {
        return self::scoreFolded(self::fold($title), self::fold($excerpt), self::fold($body), $terms);
    }

    // ──────────────────────────────────────────────────────────
    //  Datenbank
    // ──────────────────────────────────────────────────────────

    /**
     * Durchsucht eine Inhaltstabelle und liefert nur Zeilen, deren reiner Text alle Begriffe enthält.
     *
     * `title`, `excerpt`, `content` und `date` sind Spaltennamen in Prioritätsreihenfolge – der erste
     * nicht leere Wert gilt (z. B. `['title_en', 'title']` für englische Inhalte mit deutschem Fallback).
     * `where` schränkt die Tabelle ein (Status, Sprache). Jede Zeile erhält `_search_score` und
     * `_search_date`.
     *
     * @param array{query:string, terms:list<string>, words:list<string>} $parsed
     * @param array{table:string, alias:string, where:string, params?:list<mixed>, title:list<string>, excerpt:list<string>, content:list<string>, date:list<string>} $source
     * @return list<array<string, mixed>>
     */
    public function searchTable(array $parsed, array $source): array
    {
        if ($parsed['terms'] === [] || $parsed['words'] === []) {
            return [];
        }

        $alias = $source['alias'];
        $searchable = array_values(array_filter([
            self::columnExpression($alias, $source['title']),
            self::columnExpression($alias, $source['excerpt']),
            self::columnExpression($alias, $source['content']),
        ], static fn(string $expression): bool => $expression !== ''));

        $conditions = [];
        $params = $source['params'] ?? [];
        foreach ($parsed['words'] as $word) {
            $like = '%' . self::escapeLike($word) . '%';
            $alternatives = [];
            foreach ($searchable as $expression) {
                $alternatives[] = "{$expression} LIKE ? ESCAPE '!'";
                $params[] = $like;
            }
            $conditions[] = '(' . implode(' OR ', $alternatives) . ')';
        }

        $dateExpression = $source['date'] !== []
            ? 'COALESCE(' . implode(', ', array_map(static fn(string $column): string => "{$alias}.{$column}", $source['date'])) . ')'
            : "{$alias}.id";
        // Projektion: Bei Quellen mit LONGTEXT-Inhalt nur ID und die Suchspalten laden (nicht z. B. die
        // jeweils andere Sprachfassung oder JSON-Metadaten aller 400 Kandidaten). Vollständige Zeilen
        // lädt hydrate() später nur für die nach rank() verbleibenden Treffer.
        $projected = $source['content'] !== [];
        if ($projected) {
            $columns = array_values(array_unique(array_merge(
                ['id'],
                $source['title'],
                $source['excerpt'],
                $source['content'],
                $source['date']
            )));
            $selectList = implode(', ', array_map(static fn(string $column): string => "{$alias}.{$column}", $columns));
        } else {
            $selectList = "{$alias}.*";
        }

        $sql = "SELECT {$selectList} FROM {$source['table']} {$alias}"
            . " WHERE ({$source['where']}) AND " . implode(' AND ', $conditions)
            . " ORDER BY {$dateExpression} DESC LIMIT " . self::CANDIDATE_LIMIT;

        try {
            $stmt = $this->db->prepare($sql);
            $rows = $stmt !== false && $stmt->execute($params) ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('search')->warning('Seitensuche: Abfrage fehlgeschlagen.', [
                'table' => $source['table'],
                'exception' => $e,
            ]);
            return [];
        }

        $hits = [];
        foreach ($rows as $row) {
            $title = self::fold(self::firstValue($row, $source['title']));
            $excerpt = self::fold(self::htmlToText(self::firstValue($row, $source['excerpt'])));
            $body = self::fold(self::plainText(self::firstValue($row, $source['content'])));
            if (!self::matchesAll($title . ' ' . $excerpt . ' ' . $body, $parsed['terms'])) {
                continue;
            }

            $row['_search_score'] = self::scoreFolded($title, $excerpt, $body, $parsed['terms']);
            $row['_search_date'] = self::firstValue($row, $source['date']);
            if ($projected) {
                // Inhalt wird nur zum Abgleich gebraucht; hydrate() lädt ihn für die Endtreffer neu.
                foreach ($source['content'] as $column) {
                    unset($row[$column]);
                }
                $row['_search_table'] = $source['table'];
            }
            $hits[] = $row;
        }

        return $hits;
    }

    /**
     * Lädt für projizierte Treffer aus searchTable() (Marker `_search_table`) die vollständigen Zeilen –
     * nach rank(), also nur für die tatsächlich angezeigten Treffer. Felder des Treffers (z. B. `_type`,
     * `_search_score`) bleiben erhalten; inzwischen gelöschte Zeilen entfallen.
     *
     * @param list<array<string, mixed>> $results
     * @return list<array<string, mixed>>
     */
    public function hydrate(array $results): array
    {
        $idsByTable = [];
        foreach ($results as $result) {
            $table = (string) ($result['_search_table'] ?? '');
            $id = (int) ($result['id'] ?? 0);
            if ($table !== '' && $id > 0 && preg_match('/^[A-Za-z0-9_]+$/', $table) === 1) {
                $idsByTable[$table][$id] = $id;
            }
        }

        if ($idsByTable === []) {
            return $results;
        }

        $rowsByTable = [];
        foreach ($idsByTable as $table => $ids) {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            try {
                $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE id IN ({$placeholders})");
                $rows = $stmt !== false && $stmt->execute(array_values($ids)) ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('search')->warning('Seitensuche: Treffer konnten nicht geladen werden.', [
                    'table' => $table,
                    'exception' => $e,
                ]);
                $rows = [];
            }

            foreach ($rows as $row) {
                $rowsByTable[$table][(int) ($row['id'] ?? 0)] = $row;
            }
        }

        $hydrated = [];
        foreach ($results as $result) {
            $table = (string) ($result['_search_table'] ?? '');
            if ($table === '') {
                $hydrated[] = $result;
                continue;
            }

            $full = $rowsByTable[$table][(int) ($result['id'] ?? 0)] ?? null;
            if ($full === null) {
                continue;
            }

            unset($result['_search_table']);
            $hydrated[] = array_replace($full, $result);
        }

        return $hydrated;
    }

    // ──────────────────────────────────────────────────────────
    //  Sortierung
    // ──────────────────────────────────────────────────────────

    /**
     * Sortiert Treffer nach Relevanz (Standard) oder Datum (neueste zuerst) und begrenzt je Typ.
     *
     * Treffer ohne `_search_score` (z. B. von Plugins) werden über Titel und Auszug bewertet;
     * Treffer ohne `_search_date` stehen bei der Datumssortierung hinter datierten Treffern.
     *
     * @param list<array<string, mixed>> $results
     * @param list<string> $terms
     * @return list<array<string, mixed>>
     */
    public function rank(array $results, array $terms, string $sort): array
    {
        $sort = self::normalizeSort($sort);
        $entries = [];
        foreach (array_values($results) as $position => $result) {
            if (!isset($result['_search_score'])) {
                $result['_search_score'] = self::score(
                    (string) ($result['title'] ?? $result['name'] ?? ''),
                    self::htmlToText((string) ($result['excerpt'] ?? $result['meta_description'] ?? '')),
                    '',
                    $terms
                );
            }
            $date = trim((string) ($result['_search_date'] ?? ''));
            $timestamp = $date !== '' ? strtotime($date) : false;
            $entries[] = [
                'result' => $result,
                'score' => (float) $result['_search_score'],
                'time' => $timestamp !== false ? $timestamp : null,
                'position' => $position,
            ];
        }

        usort($entries, static function (array $a, array $b) use ($sort): int {
            if ($sort === self::SORT_DATE && $a['time'] !== $b['time']) {
                if ($a['time'] === null) {
                    return 1;
                }
                if ($b['time'] === null) {
                    return -1;
                }

                return $b['time'] <=> $a['time'];
            }

            return [$b['score'], $b['time'] ?? 0, $a['position']] <=> [$a['score'], $a['time'] ?? 0, $b['position']];
        });

        $counts = [];
        $ranked = [];
        foreach ($entries as $entry) {
            $type = (string) ($entry['result']['_type'] ?? '');
            $limit = self::RESULT_LIMITS[$type] ?? null;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
            if ($limit !== null && $counts[$type] > $limit) {
                continue;
            }
            $ranked[] = $entry['result'];
        }

        return $ranked;
    }

    // ──────────────────────────────────────────────────────────
    //  Hilfen
    // ──────────────────────────────────────────────────────────

    /**
     * @param list<string> $terms
     */
    private static function scoreFolded(string $title, string $excerpt, string $body, array $terms): float
    {
        if ($terms === []) {
            return 0.0;
        }

        $phrase = implode(' ', $terms);
        $score = 0.0;

        if ($title === $phrase) {
            $score += 100;
        } elseif (str_starts_with($title, $phrase)) {
            $score += 60;
        } elseif (count($terms) > 1 && str_contains($title, $phrase)) {
            $score += 40;
        }

        $allInTitle = true;
        foreach ($terms as $term) {
            if (self::containsTerm($title, $term)) {
                $score += 20;
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u', $title) === 1) {
                    $score += 10;
                }
            } else {
                $allInTitle = false;
            }

            if ($excerpt !== '' && self::containsTerm($excerpt, $term)) {
                $score += 6;
            }

            if ($body !== '') {
                $score += min(substr_count($body, $term), 10);
            }
        }

        if ($allInTitle) {
            $score += 25;
        }

        return $score;
    }

    private static function containsTerm(string $foldedText, string $term): bool
    {
        if (mb_strlen($term) <= self::WORD_START_MAX_LENGTH && preg_match('/\p{L}/u', $term) === 1) {
            return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '/u', $foldedText) === 1;
        }

        return str_contains($foldedText, $term);
    }

    /**
     * SQL-Ausdruck für Spalten in Prioritätsreihenfolge (erste nicht leere gewinnt).
     *
     * @param list<string> $columns
     */
    private static function columnExpression(string $alias, array $columns): string
    {
        if ($columns === []) {
            return '';
        }

        $last = array_pop($columns);
        $parts = array_map(static fn(string $column): string => "NULLIF({$alias}.{$column}, '')", $columns);
        $parts[] = "{$alias}.{$last}";

        return count($parts) === 1 ? $parts[0] : 'COALESCE(' . implode(', ', $parts) . ')';
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $columns
     */
    private static function firstValue(array $row, array $columns): string
    {
        foreach ($columns as $column) {
            $value = (string) ($row[$column] ?? '');
            if (trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<int|string, mixed> $parts
     */
    private static function collectText(mixed $value, array &$parts, string $key = ''): void
    {
        if ($key !== '' && in_array(strtolower($key), self::NON_TEXT_KEYS, true)) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                self::collectText($child, $parts, is_string($childKey) ? $childKey : '');
            }
            return;
        }

        if (!is_string($value)) {
            return;
        }

        $value = trim($value);
        if ($value !== '' && preg_match('#^(?:https?:|mailto:|tel:|data:|//|www\.)#i', $value) !== 1) {
            $parts[] = $value;
        }
    }

    private static function htmlToText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $html = (string) preg_replace('#<(script|style|template|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
        // Block-Elemente trennen Wörter, Inline-Elemente (<b>, <a>) nicht.
        $html = (string) preg_replace('#<(?:br|hr)\b[^>]*>|</(?:p|div|li|h[1-6]|td|th|tr|blockquote|pre|ul|ol|table|section|article|figcaption|dd|dt)\s*>#i', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text));
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
