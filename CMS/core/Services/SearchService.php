<?php
/**
 * Search Service – Volltextsuche via TNTSearch
 *
 * Nutzt die TNTSearch-Bibliothek (SQLite-Engine, GermanStemmer) für
 * performante Volltextsuche über Pages, Posts und Plugin-Inhalte.
 *
 * Zusätzlich verwaltet der Service Dokumentindizes je Inhaltstyp und Sprache
 * (`cache/search/doc-<typ>-<sprache>.index`), die die Suchindex-Verwaltung
 * (`SearchIndexService`) aus den Such-Providern der Inhaltstyp-Registry befüllt.
 * Ein Neuaufbau schreibt in `….building.index` und ersetzt den aktiven Index erst
 * am Ende per `rename()`; Titel, URL und Auszug liegen in der Zusatztabelle
 * `cms_documents` derselben SQLite-Datei.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Hooks;
use CMS\Logger;
use CMS\Services\ContentTypes\SearchDocument;
use TeamTNT\TNTSearch\TNTSearch;
use TeamTNT\TNTSearch\Stemmer\GermanStemmer;
use TeamTNT\TNTSearch\Stemmer\NoStemmer;
use TeamTNT\TNTSearch\Support\Highlighter;

if (!defined('ABSPATH')) {
    exit;
}

final class SearchService
{
    /** Dateinamen der Dokumentindizes: `doc-<typ>-<sprache>.index` bzw. `….building.index`. */
    private const DOCUMENT_INDEX_PATTERN = '/^doc-([a-z][a-z0-9_]{0,39})-([a-z]{2}(?:-[a-z]{2})?)(\.building)?\.index$/';
    private const DOCUMENT_BUSY_TIMEOUT = 5;
    private const DOCUMENT_TERM_LIMIT = 500;

    /** Stemmer je Sprachcode (erste zwei Buchstaben); unbekannte Sprachen ohne Stemming. */
    private const LOCALE_STEMMERS = [
        'de' => 'German',
        'en' => 'Porter',
        'fr' => 'French',
        'it' => 'Italian',
        'pt' => 'Portuguese',
        'ru' => 'Russian',
        'pl' => 'Polish',
        'hr' => 'Croatian',
        'lv' => 'Latvian',
        'uk' => 'Ukrainian',
        'ar' => 'Arabic',
    ];

    private static ?self $instance = null;

    private readonly Database $db;
    private readonly string $prefix;
    private readonly string $storagePath;
    private Logger $logger;
    private ?TNTSearch $tnt = null;
    private bool $available = false;
    private string $unavailableReason = '';

    /**
     * Konfiguration der Suchindizes.
     * Key = Index-Name, Value = SQL-Query + Felder.
     */
    private array $indexDefinitions = [];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db     = Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->logger = Logger::instance()->withChannel('search');

        // Storage-Pfad für SQLite-Indizes
        $this->storagePath = ABSPATH . '/cache/search/';

        if (!is_dir($this->storagePath) && !$this->ensureDirectoryExists($this->storagePath)) {
            $this->unavailableReason = 'Verzeichnis cache/search/ konnte nicht erstellt werden.';
        }

        $this->initTNTSearch();
        $this->registerDefaultIndices();
        $this->initHooks();
    }

    /**
     * TNTSearch-Instanz initialisieren.
     */
    private function initTNTSearch(): void
    {
        // SQLite-Extension prüfen (TNTSearch speichert Indizes als SQLite-DBs)
        if (!extension_loaded('pdo_sqlite')) {
            $this->unavailableReason = 'PHP-Extension pdo_sqlite ist nicht geladen. Bitte in der php.ini aktivieren.';
            $this->logger->warning('Search service is unavailable because pdo_sqlite is missing.', [
                'reason' => $this->unavailableReason,
            ]);
            return;
        }

        if (!class_exists(TNTSearch::class)) {
            $this->unavailableReason = 'TNTSearch-Klasse nicht verfügbar. CMS/assets/autoload.php prüfen.';
            $this->logger->warning('Search service is unavailable because TNTSearch is missing.', [
                'reason' => $this->unavailableReason,
            ]);
            return;
        }

        // Storage-Verzeichnis beschreibbar?
        if (!is_dir($this->storagePath) || !is_writable($this->storagePath)) {
            $this->unavailableReason = 'Verzeichnis cache/search/ existiert nicht oder ist nicht beschreibbar.';
            $this->logger->warning('Search service is unavailable because the storage path is not writable.', [
                'reason' => $this->unavailableReason,
                'storage_path' => $this->storagePath,
            ]);
            return;
        }

        try {
            $this->tnt = new TNTSearch();

            $this->tnt->loadConfig([
                'driver'  => 'mysql',
                'host'    => DB_HOST,
                'database' => DB_NAME,
                'username' => DB_USER,
                'password' => DB_PASS,
                'storage' => $this->storagePath,
                'stemmer' => GermanStemmer::class,
                'wal'     => true,
            ]);

            // MySQL-PDO aus CMS-Database nutzen (Connection-Reuse)
            $this->tnt->setDatabaseHandle($this->db->getPdo());

            $this->available = true;
        } catch (\Throwable $e) {
            // Ausnahmetexte können Verbindungsdetails enthalten – Details nur im Protokoll.
            $this->unavailableReason = 'TNTSearch konnte nicht initialisiert werden. Details stehen im Systemprotokoll (Kanal „search“).';
            $this->logger->error('TNTSearch could not be initialized.', [
                'storage_path' => $this->storagePath,
                'exception' => $e,
            ]);
            $this->available = false;
        }
    }

    private function ensureDirectoryExists(string $directory): bool
    {
        if (is_dir($directory)) {
            return is_writable($directory);
        }

        $parentDir = dirname(rtrim($directory, '/\\'));
        if ($parentDir === '' || $parentDir === $directory) {
            $this->logger->warning('Search-Storage-Verzeichnis hat keinen gültigen Elternpfad.', [
                'operation' => 'mkdir_preflight',
                'path' => $directory,
            ]);

            return false;
        }

        if (!is_dir($parentDir) && !$this->ensureDirectoryExists($parentDir)) {
            return false;
        }

        if (!is_writable($parentDir)) {
            $this->logger->warning('Search-Storage-Elternpfad ist nicht beschreibbar.', [
                'operation' => 'mkdir_preflight',
                'path' => $directory,
                'parent' => $parentDir,
            ]);

            return false;
        }

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });

        try {
            $created = mkdir($directory, 0755);
        } finally {
            restore_error_handler();
        }

        if ($created || is_dir($directory)) {
            return true;
        }

        $context = [
            'operation' => 'mkdir',
            'path' => $directory,
        ];

        if ($warning !== null) {
            $context['warning'] = $warning;
        }

        $this->logger->warning('Search-Storage-Verzeichnis konnte nicht erstellt werden.', $context);

        return false;
    }

    /**
     * Standard-Indizes registrieren (Pages + Posts).
     */
    private function registerDefaultIndices(): void
    {
        // Pages-Index
        $this->indexDefinitions['pages'] = [
            'query'      => "SELECT id, title, title_en, content, content_en, excerpt, slug, slug_en FROM {$this->prefix}pages WHERE status = 'published'",
            'primaryKey' => 'id',
        ];

        // Posts-Index (Blog)
        $this->indexDefinitions['posts'] = [
            'query'      => "SELECT id, title, title_en, content, content_en, excerpt, excerpt_en, slug, slug_en FROM {$this->prefix}posts WHERE " . \cms_post_publication_where(),
            'primaryKey' => 'id',
        ];
    }

    /**
     * Hooks für automatisches Index-Update registrieren.
     */
    private function initHooks(): void
    {
        // Index-Rebuild bei Page/Post-Speichern
        Hooks::addAction('cms_after_page_save', [$this, 'onPageSaved'], 50);
        Hooks::addAction('page_deleted', [$this, 'onPageDeleted'], 50);
        Hooks::addAction('cms_after_post_save', [$this, 'onPostSaved'], 50);
        Hooks::addAction('post_deleted', [$this, 'onPostDeleted'], 50);

        // Ermögliche Plugins, eigene Indizes zu registrieren
        Hooks::addAction('cms_init', function () {
            Hooks::doAction('search_register_indices', $this);
        }, 99);
    }

    // ──────────────────────────────────────────────────────────
    //  Öffentliche API
    // ──────────────────────────────────────────────────────────
    /**
     * Prüft, ob die Volltextsuche verfügbar ist.
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Gibt den Grund zurück, warum die Suche nicht verfügbar ist.
     */
    public function getUnavailableReason(): string
    {
        return $this->unavailableReason;
    }

    /**
     * Einen zusätzlichen Suchindex registrieren (für Plugins).
     *
     * @param string $name     Index-Name (z. B. 'experts', 'events')
     * @param string $query    SQL-Query zum Indexieren (muss id + Text-Felder liefern)
     * @param string $primaryKey  Primärschlüssel-Feld (Standard: 'id')
     */
    public function registerIndex(string $name, string $query, string $primaryKey = 'id'): void
    {
        $this->indexDefinitions[$name] = [
            'query'      => $query,
            'primaryKey' => $primaryKey,
        ];
    }

    /**
     * Einen einzelnen Index komplett neu erstellen.
     */
    public function buildIndex(string $name): bool
    {
        if (!$this->available || !isset($this->indexDefinitions[$name])) {
            return false;
        }

        $def = $this->indexDefinitions[$name];

        try {
            $indexer = $this->tnt->createIndex("{$name}.index", true);
            $indexer->setLanguage('german');
            $indexer->setPrimaryKey($def['primaryKey']);
            $indexer->query($def['query']);
            $indexer->run();

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Search index build failed.', [
                'index' => $name,
                'exception' => $e,
            ]);
            return false;
        } finally {
            $this->restoreBufferedQueries();
        }
    }

    /**
     * Der TNTSearch-Indexer schaltet die gemeinsam genutzte MySQL-Verbindung auf ungepufferte
     * Abfragen um. Danach scheitern weitere CMS-Abfragen im selben Request („Cannot execute queries
     * while other unbuffered queries are active“) – daher nach jeder Indexoperation zurückstellen.
     */
    private function restoreBufferedQueries(): void
    {
        try {
            $pdo = $this->db->getPdo();
            if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $pdo->setAttribute(\CMS\Database::mysqlAttribute('ATTR_USE_BUFFERED_QUERY'), true);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Search could not restore buffered MySQL queries.', [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Alle registrierten Indizes neu erstellen.
     */
    public function rebuildAllIndices(): array
    {
        $results = [];
        foreach (array_keys($this->indexDefinitions) as $name) {
            $results[$name] = $this->buildIndex($name);
        }
        return $results;
    }

    /**
     * Volltextsuche über einen bestimmten Index.
     *
     * @param string $query     Suchbegriff
     * @param string $indexName Index-Name (z. B. 'pages', 'posts')
     * @param int    $limit     Max. Ergebnisse
     * @param bool   $fuzzy     Fuzzy-Suche aktivieren
     *
     * @return array{ids: int[], hits: int, execution_time: string}
     */
    public function search(string $query, string $indexName = 'pages', int $limit = 50, bool $fuzzy = false): array
    {
        if (!$this->available) {
            return ['ids' => [], 'hits' => 0, 'execution_time' => '0 ms'];
        }

        $indexFile = "{$indexName}.index";
        $indexPath = $this->storagePath . $indexFile;

        // Falls Index nicht existiert → automatisch erstellen
        if (!file_exists($indexPath)) {
            $built = $this->buildIndex($indexName);
            if (!$built) {
                return ['ids' => [], 'hits' => 0, 'execution_time' => '0 ms'];
            }
        }

        try {
            $this->tnt->selectIndex($indexFile);
            $this->tnt->setFuzziness($fuzzy);

            $result = $this->tnt->search($query, $limit);

            return [
                'ids'            => $result['ids'] ?? [],
                'hits'           => $result['hits'] ?? 0,
                'execution_time' => $result['execution_time'] ?? '0 ms',
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Search query failed.', [
                'index' => $indexName,
                'query' => mb_substr($query, 0, 120),
                'limit' => $limit,
                'fuzzy' => $fuzzy,
                'exception' => $e,
            ]);
            return ['ids' => [], 'hits' => 0, 'execution_time' => '0 ms'];
        }
    }

    /**
     * Übergreifende Suche über Pages + Posts (+ registrierte Plugin-Indizes).
     *
     * @return array Array mit Ergebnis-Datensätzen (angereichert mit _type, _type_label, slug, title, meta_description)
     */
    public function searchAll(string $query, int $limit = 50, bool $fuzzy = false): array
    {
        $allResults = [];

        // Pages
        $pageResult = $this->search($query, 'pages', $limit, $fuzzy);
        if (!empty($pageResult['ids'])) {
            $rows = $this->fetchRowsByIds("{$this->prefix}pages", $pageResult['ids']);
            foreach ($rows as $row) {
                $row['_type']       = 'page';
                $row['_type_label'] = 'Seite';
                $allResults[] = $row;
            }
        }

        // Posts
        $postResult = $this->search($query, 'posts', $limit, $fuzzy);
        if (!empty($postResult['ids'])) {
            $rows = $this->fetchRowsByIds("{$this->prefix}posts", $postResult['ids']);
            foreach ($rows as $row) {
                $row['_type']       = 'post';
                $row['_type_label'] = 'Beitrag';
                $allResults[] = $row;
            }
        }

        // Plugins können eigene Ergebnisse hinzufügen
        $allResults = Hooks::applyFilters('search_results', $allResults, $query, $limit);

        return $allResults;
    }

    /**
     * Text-Highlighting für Suchergebnisse.
     */
    public function highlight(string $text, string $query, string $tag = 'mark'): string
    {
        if (!$this->available || empty($query)) {
            return $text;
        }

        try {
            $highlighter = new Highlighter();
            return $highlighter->highlight($text, $query, $tag);
        } catch (\Throwable $e) {
            return $text;
        }
    }

    /**
     * Snippet (Textauszug) erstellen, der den Suchbegriff enthält.
     */
    public function snippet(string $text, string $query, int $length = 200): string
    {
        // HTML entfernen
        $plain = strip_tags($text);

        if (empty($query)) {
            return mb_substr($plain, 0, $length) . (mb_strlen($plain) > $length ? '…' : '');
        }

        // Position des ersten Treffers finden
        $pos = mb_stripos($plain, $query);

        if ($pos === false) {
            // Kein Treffer → Anfang zurückgeben
            return mb_substr($plain, 0, $length) . (mb_strlen($plain) > $length ? '…' : '');
        }

        // Kontext um den Treffer herum
        $start = max(0, $pos - (int)($length / 3));
        $snippet = mb_substr($plain, $start, $length);

        // Am Wortanfang beginnen
        if ($start > 0) {
            $firstSpace = mb_strpos($snippet, ' ');
            if ($firstSpace !== false && $firstSpace < 30) {
                $snippet = mb_substr($snippet, $firstSpace + 1);
            }
            $snippet = '…' . $snippet;
        }

        if ($start + $length < mb_strlen($plain)) {
            $snippet .= '…';
        }

        return $snippet;
    }

    // ──────────────────────────────────────────────────────────
    //  Inkrementelle Index-Updates (Hook-Handler)
    // ──────────────────────────────────────────────────────────

    /**
     * Page gespeichert → Index aktualisieren.
     */
    public function onPageSaved(int $pageId, array $savePayload = [], array $originalPayload = []): void
    {
        $this->updateDocument('pages', $pageId, "{$this->prefix}pages", [
            'title', 'title_en', 'content', 'content_en', 'meta_description', 'slug', 'slug_en',
        ]);
    }

    /**
     * Page gelöscht → aus Index entfernen.
     */
    public function onPageDeleted(int $pageId): void
    {
        $this->removeDocument('pages', $pageId);
    }

    /**
     * Post gespeichert → Index aktualisieren.
     */
    public function onPostSaved(int $postId, array $savePayload = [], array $originalPayload = []): void
    {
        $this->updateDocument('posts', $postId, "{$this->prefix}posts", [
            'title', 'title_en', 'content', 'content_en', 'excerpt', 'excerpt_en', 'slug', 'slug_en',
        ]);
    }

    /**
     * Post gelöscht → aus Index entfernen.
     */
    public function onPostDeleted(int $postId): void
    {
        $this->removeDocument('posts', $postId);
    }

    // ──────────────────────────────────────────────────────────
    //  Interne Helfer
    // ──────────────────────────────────────────────────────────

    /**
     * Einzelnes Dokument im Index aktualisieren (Insert oder Update).
     */
    private function updateDocument(string $indexName, int $docId, string $table, array $fields): void
    {
        if (!$this->available) {
            return;
        }

        $indexFile = "{$indexName}.index";
        $indexPath = $this->storagePath . $indexFile;

        if (!file_exists($indexPath)) {
            // Noch kein Index: search() baut ihn beim ersten Aufruf vollständig auf. Ein Neuaufbau
            // mitten in einem Speichervorgang (oder Import-Stapel) würde den Request blockieren.
            return;
        }

        try {
            $this->tnt->selectIndex($indexFile);
            // Stemmer/Tokenizer des gewählten Index für das Schreiben übernehmen. createIndex() darf hier
            // nicht verwendet werden: Es löscht die vorhandene Indexdatei (danach enthielte sie nur noch
            // dieses eine Dokument).
            $this->prepareEngineForWrites($this->tnt);

            // Dokument aus DB holen
            $cols = implode(', ', array_merge(['id'], $fields));
            $where = $table === "{$this->prefix}posts"
                ? \cms_post_publication_where()
                : "status = 'published'";
            $stmt = $this->db->prepare("SELECT {$cols} FROM {$table} WHERE id = ? AND {$where}");
            $stmt->execute([$docId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                // Dokument existiert und ist published → Update/Insert
                $this->tnt->engine->update($docId, $row);
            } else {
                // Dokument nicht gefunden oder nicht published → aus Index löschen
                try {
                    $this->tnt->engine->delete($docId);
                } catch (\Throwable $ignore) {
                    // Dokument war ggf. nicht im Index
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Search index document update failed.', [
                'index' => $indexName,
                'document_id' => $docId,
                'table' => $table,
                'exception' => $e,
            ]);
        } finally {
            $this->restoreBufferedQueries();
        }
    }

    /**
     * Einzelnes Dokument aus dem Index entfernen.
     */
    private function removeDocument(string $indexName, int $docId): void
    {
        if (!$this->available) {
            return;
        }

        $indexFile = "{$indexName}.index";
        $indexPath = $this->storagePath . $indexFile;

        if (!file_exists($indexPath)) {
            return;
        }

        try {
            $this->tnt->selectIndex($indexFile);
            $this->tnt->engine->delete($docId);
        } catch (\Throwable $e) {
            $this->logger->warning('Search index document removal failed.', [
                'index' => $indexName,
                'document_id' => $docId,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Zeilen anhand einer ID-Liste aus der Datenbank laden (IN-Clause).
     */
    private function fetchRowsByIds(string $table, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        // Nur Integer-IDs zulassen
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // Ergebnis in der Reihenfolge der IDs sortieren (TNTSearch-Relevanz)
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int)$row['id']] = $row;
        }

        $sorted = [];
        foreach ($ids as $id) {
            if (isset($indexed[$id])) {
                $sorted[] = $indexed[$id];
            }
        }

        return $sorted;
    }

    /**
     * Alle registrierten Index-Definitionen abfragen.
     *
     * @return array<string, array{query: string, primaryKey: string}>
     */
    public function getIndexDefinitions(): array
    {
        return $this->indexDefinitions;
    }

    /**
     * Prüft, ob ein bestimmter Index bereits erstellt wurde.
     */
    public function indexExists(string $name): bool
    {
        return file_exists($this->storagePath . "{$name}.index");
    }

    /**
     * Gibt Infos über alle Indizes zurück (für Admin-Dashboard).
     *
     * @return array<string, array{exists: bool, size: int, modified: string|null}>
     */
    public function getIndexInfo(): array
    {
        $info = [];
        foreach (array_keys($this->indexDefinitions) as $name) {
            $path = $this->storagePath . "{$name}.index";
            $info[$name] = [
                'exists'   => file_exists($path),
                'size'     => file_exists($path) ? filesize($path) : 0,
                'modified' => file_exists($path) ? date('Y-m-d H:i:s', filemtime($path)) : null,
            ];
        }
        return $info;
    }

    // ──────────────────────────────────────────────────────────
    //  Dokumentindizes je Inhaltstyp und Sprache
    // ──────────────────────────────────────────────────────────

    public static function isValidDocumentIndexKey(string $type, string $locale): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,39}$/', $type) === 1
            && preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $locale) === 1;
    }

    /** Dateiname eines Dokumentindex in cache/search/ (ohne Verzeichnis). */
    public static function documentIndexFile(string $type, string $locale, bool $building = false): string
    {
        if (!self::isValidDocumentIndexKey($type, $locale)) {
            throw new \InvalidArgumentException('Ungültiger Inhaltstyp oder Sprachcode für den Suchindex.');
        }

        return 'doc-' . $type . '-' . $locale . ($building ? '.building' : '') . '.index';
    }

    /**
     * @return array{type:string, locale:string, building:bool}|null
     */
    public static function parseDocumentIndexFile(string $file): ?array
    {
        if (preg_match(self::DOCUMENT_INDEX_PATTERN, $file, $match) !== 1) {
            return null;
        }

        return ['type' => $match[1], 'locale' => $match[2], 'building' => ($match[3] ?? '') !== ''];
    }

    /** Stemmer-Klasse für einen Sprachcode; unbekannte Sprachen werden nicht gestemmt. */
    public static function stemmerForLocale(string $locale): string
    {
        $name = self::LOCALE_STEMMERS[strtolower(substr($locale, 0, 2))] ?? null;
        $class = $name !== null ? 'TeamTNT\\TNTSearch\\Stemmer\\' . $name . 'Stemmer' : NoStemmer::class;

        return class_exists($class) ? $class : NoStemmer::class;
    }

    public function hasDocumentIndex(string $type, string $locale, bool $building = false): bool
    {
        return self::isValidDocumentIndexKey($type, $locale)
            && is_file($this->storagePath . self::documentIndexFile($type, $locale, $building));
    }

    /**
     * Legt eine leere Aufbau-Datei (`….building.index`) an; eine vorhandene wird verworfen.
     */
    public function createDocumentIndex(string $type, string $locale): bool
    {
        if (!$this->available || !self::isValidDocumentIndexKey($type, $locale)) {
            return false;
        }

        try {
            $tnt = $this->newDocumentSearch($locale);
            $tnt->createIndex(self::documentIndexFile($type, $locale, true), true);
            $pdo = $tnt->engine->index;
            $pdo->exec('CREATE TABLE IF NOT EXISTS cms_documents (id INTEGER PRIMARY KEY, title TEXT NOT NULL, url TEXT NOT NULL, excerpt TEXT NOT NULL, updated_at TEXT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS cms_dirty (id INTEGER PRIMARY KEY)');
            self::writeInfo($pdo, ['cms_type' => $type, 'cms_locale' => $locale, 'cms_built_at' => '']);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Suchindex: Aufbau-Datei konnte nicht angelegt werden.', [
                'type' => $type,
                'locale' => $locale,
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * Schreibt Dokumente in einen Dokumentindex; vorhandene Fassungen derselben IDs werden ersetzt.
     * Dokumente eines anderen Typs oder einer anderen Sprache werden übersprungen.
     *
     * @param list<SearchDocument> $documents
     * @return int Anzahl geschriebener Dokumente, -1 bei Fehlern oder fehlendem Index
     */
    public function writeDocuments(string $type, string $locale, array $documents, bool $building = false): int
    {
        if ($documents === []) {
            return 0;
        }

        $tnt = $this->openDocumentIndex($type, $locale, $building, true);
        if ($tnt === null) {
            return -1;
        }

        $engine = $tnt->engine;
        $pdo = $engine->index;
        $written = 0;

        try {
            $pdo->beginTransaction();
            $store = $pdo->prepare('INSERT OR REPLACE INTO cms_documents (id, title, url, excerpt, updated_at) VALUES (?, ?, ?, ?, ?)');
            foreach ($documents as $document) {
                if (!$document instanceof SearchDocument || $document->id <= 0
                    || $document->type !== $type || $document->locale !== $locale) {
                    continue;
                }

                $engine->delete($document->id);
                $engine->insert([
                    'id' => $document->id,
                    'title' => $document->title,
                    'excerpt' => $document->excerpt,
                    'content' => $document->content,
                ]);
                $store->execute([
                    $document->id,
                    mb_substr($document->title, 0, 500),
                    mb_substr($document->url, 0, 2000),
                    mb_substr($document->excerpt, 0, 500),
                    mb_substr($document->updatedAt, 0, 40),
                ]);
                $written++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->logger->warning('Suchindex: Dokumente konnten nicht geschrieben werden.', [
                'type' => $type,
                'locale' => $locale,
                'building' => $building,
                'exception' => $e,
            ]);

            return -1;
        }

        return $written;
    }

    /**
     * @param list<int> $ids
     * @return bool false bei Schreibfehlern (ein fehlender Index gilt als Erfolg)
     */
    public function removeDocuments(string $type, string $locale, array $ids, bool $building = false): bool
    {
        $ids = self::normalizeDocumentIds($ids);
        if ($ids === [] || !$this->hasDocumentIndex($type, $locale, $building)) {
            return true;
        }

        $tnt = $this->openDocumentIndex($type, $locale, $building, true);
        if ($tnt === null) {
            return false;
        }

        $pdo = $tnt->engine->index;
        try {
            $pdo->beginTransaction();
            $forget = $pdo->prepare('DELETE FROM cms_documents WHERE id = ?');
            foreach ($ids as $id) {
                $tnt->engine->delete($id);
                $forget->execute([$id]);
            }
            $pdo->commit();

            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->logger->warning('Suchindex: Dokumente konnten nicht entfernt werden.', [
                'type' => $type,
                'locale' => $locale,
                'building' => $building,
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * Merkt IDs in einer laufenden Aufbau-Datei vor, damit der Neuaufbau sie vor dem Aktivieren mit
     * dem aktuellen Stand neu liest (Änderungen während des Neuaufbaus gehen so nicht verloren).
     *
     * @param list<int> $ids
     */
    public function markDocumentsDirty(string $type, string $locale, array $ids): bool
    {
        $ids = self::normalizeDocumentIds($ids);
        if ($ids === [] || !$this->hasDocumentIndex($type, $locale, true)) {
            return true;
        }

        try {
            $pdo = $this->openDocumentPdo($this->storagePath . self::documentIndexFile($type, $locale, true));
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO cms_dirty (id) VALUES (?)');
            foreach ($ids as $id) {
                $stmt->execute([$id]);
            }
            $pdo->commit();

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Suchindex: Änderung konnte für den laufenden Neuaufbau nicht vorgemerkt werden.', [
                'type' => $type,
                'locale' => $locale,
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * Liefert und leert die vorgemerkten IDs einer Aufbau-Datei.
     *
     * @return list<int>
     */
    public function pullDirtyDocuments(string $type, string $locale): array
    {
        if (!$this->hasDocumentIndex($type, $locale, true)) {
            return [];
        }

        try {
            $pdo = $this->openDocumentPdo($this->storagePath . self::documentIndexFile($type, $locale, true));
            $pdo->exec('BEGIN IMMEDIATE');
            $ids = array_map('intval', $pdo->query('SELECT id FROM cms_dirty ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN) ?: []);
            $pdo->exec('DELETE FROM cms_dirty');
            $pdo->exec('COMMIT');

            return $ids;
        } catch (\Throwable $e) {
            $this->logger->warning('Suchindex: vorgemerkte Änderungen konnten nicht gelesen werden.', [
                'type' => $type,
                'locale' => $locale,
                'exception' => $e,
            ]);

            return [];
        }
    }

    /**
     * Ersetzt den aktiven Index durch die fertige Aufbau-Datei (atomar per rename()).
     */
    public function activateDocumentIndex(string $type, string $locale): bool
    {
        if (!$this->hasDocumentIndex($type, $locale, true)) {
            return false;
        }

        $building = $this->storagePath . self::documentIndexFile($type, $locale, true);
        $live = $this->storagePath . self::documentIndexFile($type, $locale);

        try {
            $pdo = $this->openDocumentPdo($building);
            self::writeInfo($pdo, ['cms_built_at' => date('Y-m-d H:i:s')]);
            $pdo = null;
        } catch (\Throwable $e) {
            $this->logger->warning('Suchindex: Aufbauzeitpunkt konnte nicht gespeichert werden.', [
                'type' => $type,
                'locale' => $locale,
                'exception' => $e,
            ]);
        }

        if (!@rename($building, $live)) {
            $this->logger->error('Suchindex: Aufbau-Datei konnte nicht aktiviert werden.', [
                'type' => $type,
                'locale' => $locale,
            ]);

            return false;
        }
        clearstatcache(true, $live);

        return true;
    }

    public function dropDocumentIndex(string $type, string $locale, bool $building = false): bool
    {
        if (!self::isValidDocumentIndexKey($type, $locale)) {
            return false;
        }

        $path = $this->storagePath . self::documentIndexFile($type, $locale, $building);
        if (!is_file($path)) {
            return true;
        }

        return @unlink($path) || !is_file($path);
    }

    /**
     * @return array{exists:bool, documents:int, size:int, built_at:string}
     */
    public function documentIndexStats(string $type, string $locale): array
    {
        $stats = ['exists' => false, 'documents' => 0, 'size' => 0, 'built_at' => ''];
        if (!$this->hasDocumentIndex($type, $locale)) {
            return $stats;
        }

        $path = $this->storagePath . self::documentIndexFile($type, $locale);
        $stats['exists'] = true;
        $stats['size'] = (int) @filesize($path);

        try {
            $pdo = $this->openDocumentPdo($path);
            $stats['documents'] = (int) $pdo->query('SELECT COUNT(*) FROM cms_documents')->fetchColumn();
            $stats['built_at'] = (string) self::readInfo($pdo, 'cms_built_at');
        } catch (\Throwable $e) {
            $this->logger->warning('Suchindex: Kennzahlen konnten nicht gelesen werden.', [
                'type' => $type,
                'locale' => $locale,
                'exception' => $e,
            ]);
        }

        return $stats;
    }

    /**
     * Vorhandene Dokumentindex-Dateien (aktive und Aufbau-Dateien).
     *
     * @return list<array{type:string, locale:string, building:bool, file:string}>
     */
    public function listDocumentIndices(): array
    {
        $indices = [];
        foreach (glob($this->storagePath . 'doc-*.index') ?: [] as $path) {
            $parsed = self::parseDocumentIndexFile(basename($path));
            if ($parsed !== null) {
                $indices[] = $parsed + ['file' => basename($path)];
            }
        }

        return $indices;
    }

    /**
     * Dokument-IDs, die jedes Wort enthalten (nach Tokenizer und Stemmer der Indexsprache).
     *
     * @param list<string> $words
     * @return array<int, float> ID => Relevanz, absteigend sortiert
     */
    public function searchDocumentIndex(string $type, string $locale, array $words, int $limit = 200): array
    {
        $tnt = $this->openDocumentIndex($type, $locale, false, false);
        if ($tnt === null) {
            return [];
        }

        $scores = null;
        try {
            foreach ($words as $word) {
                $word = (string) $word;
                if ($tnt->breakIntoTokens($word) === []) {
                    continue;
                }

                $result = $tnt->search($word, self::DOCUMENT_TERM_LIMIT);
                $wordScores = is_array($result['docScores'] ?? null) ? $result['docScores'] : [];
                $next = [];
                if ($scores === null) {
                    foreach ($wordScores as $id => $score) {
                        $next[(int) $id] = (float) $score;
                    }
                } else {
                    foreach ($scores as $id => $score) {
                        if (isset($wordScores[$id])) {
                            $next[$id] = $score + (float) $wordScores[$id];
                        }
                    }
                }
                $scores = $next;
                if ($scores === []) {
                    return [];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Suchindex: Abfrage fehlgeschlagen.', [
                'type' => $type,
                'locale' => $locale,
                'exception' => $e,
            ]);

            return [];
        }

        if ($scores === null) {
            return [];
        }

        arsort($scores);

        return array_slice($scores, 0, max(1, $limit), true);
    }

    /**
     * Gespeicherte Anzeigedaten (Titel, URL, Auszug) indexierter Dokumente.
     *
     * @param list<int> $ids
     * @return array<int, array{id:int, title:string, url:string, excerpt:string, updated_at:string}>
     */
    public function getIndexedDocuments(string $type, string $locale, array $ids): array
    {
        $ids = self::normalizeDocumentIds($ids);
        if ($ids === [] || !$this->hasDocumentIndex($type, $locale)) {
            return [];
        }

        try {
            $pdo = $this->openDocumentPdo($this->storagePath . self::documentIndexFile($type, $locale));
            $stmt = $pdo->prepare('SELECT id, title, url, excerpt, updated_at FROM cms_documents WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute($ids);
            $documents = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $documents[(int) $row['id']] = [
                    'id' => (int) $row['id'],
                    'title' => (string) $row['title'],
                    'url' => (string) $row['url'],
                    'excerpt' => (string) $row['excerpt'],
                    'updated_at' => (string) $row['updated_at'],
                ];
            }

            return $documents;
        } catch (\Throwable $e) {
            $this->logger->warning('Suchindex: Dokumentdaten konnten nicht gelesen werden.', [
                'type' => $type,
                'locale' => $locale,
                'exception' => $e,
            ]);

            return [];
        }
    }

    private function newDocumentSearch(string $locale): TNTSearch
    {
        $tnt = new TNTSearch();
        // Treiber „filesystem“ baut keine Datenbankverbindung auf; Dokumente schreibt writeDocuments().
        // Ohne WAL besteht ein Index aus genau einer Datei – Voraussetzung für das Aktivieren per rename().
        $tnt->loadConfig([
            'driver' => 'filesystem',
            'storage' => $this->storagePath,
            'stemmer' => self::stemmerForLocale($locale),
            'wal' => false,
        ]);

        return $tnt;
    }

    private function openDocumentIndex(string $type, string $locale, bool $building, bool $forWriting): ?TNTSearch
    {
        if (!$this->available || !$this->hasDocumentIndex($type, $locale, $building)) {
            return null;
        }

        try {
            $tnt = $this->newDocumentSearch($locale);
            $tnt->selectIndex(self::documentIndexFile($type, $locale, $building));
            $tnt->engine->index->setAttribute(\PDO::ATTR_TIMEOUT, self::DOCUMENT_BUSY_TIMEOUT);
            if ($forWriting) {
                $this->prepareEngineForWrites($tnt);
            }

            return $tnt;
        } catch (\Throwable $e) {
            $this->logger->warning('Suchindex: Index konnte nicht geöffnet werden.', [
                'type' => $type,
                'locale' => $locale,
                'building' => $building,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * selectIndex() setzt Stemmer und Tokenizer nur am TNTSearch-Objekt; die Engine braucht sie zum
     * Schreiben. Ohne Term-Cache, weil Löschungen Wortlisten-Einträge entfernen und zwischengespeicherte
     * Term-IDs danach ins Leere zeigen würden.
     */
    private function prepareEngineForWrites(TNTSearch $tnt): void
    {
        $engine = $tnt->engine;
        if ($tnt->stemmer !== null) {
            $engine->stemmer = $tnt->stemmer;
        }
        if ($tnt->tokenizer !== null) {
            $engine->tokenizer = $tnt->tokenizer;
        }
        $engine->inMemory = false;
    }

    /** Öffnet eine vorhandene Indexdatei, ohne sie bei Fehlen neu anzulegen. */
    private function openDocumentPdo(string $path): \PDO
    {
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => self::DOCUMENT_BUSY_TIMEOUT,
        ];
        if (class_exists(\Pdo\Sqlite::class)) {
            $options[\Pdo\Sqlite::ATTR_OPEN_FLAGS] = \Pdo\Sqlite::OPEN_READWRITE;
        } elseif (defined('PDO::SQLITE_ATTR_OPEN_FLAGS')) {
            $options[\PDO::SQLITE_ATTR_OPEN_FLAGS] = \PDO::SQLITE_OPEN_READWRITE;
        }

        return new \PDO('sqlite:' . $path, null, null, $options);
    }

    /**
     * @param array<string, string> $values
     */
    private static function writeInfo(\PDO $pdo, array $values): void
    {
        $delete = $pdo->prepare('DELETE FROM info WHERE key = ?');
        $insert = $pdo->prepare('INSERT INTO info (key, value) VALUES (?, ?)');
        foreach ($values as $key => $value) {
            $delete->execute([$key]);
            $insert->execute([$key, $value]);
        }
    }

    private static function readInfo(\PDO $pdo, string $key): ?string
    {
        $stmt = $pdo->prepare('SELECT value FROM info WHERE key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * @param array<mixed> $ids
     * @return list<int>
     */
    private static function normalizeDocumentIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
    }
}
