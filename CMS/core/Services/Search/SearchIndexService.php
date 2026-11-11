<?php
/**
 * Suchindex-Verwaltung: Status je Sprache, Neuaufbau in Stapeln, inkrementelle Aktualisierung.
 *
 * Gespeist wird der Index ausschließlich aus den Such-Providern der Inhaltstyp-Registry
 * (`ContentTypeRegistry::searchProviders()`); je Inhaltstyp und Sprache entsteht eine TNTSearch-Datei
 * (siehe `SearchService`). Der Neuaufbau läuft als `BatchJobService`-Auftrag (`search_index_rebuild`,
 * Scope `index`): je Schritt ein Stapel von höchstens 50 Dokumenten in eine Aufbau-Datei, die erst nach
 * dem letzten Stapel den aktiven Index ersetzt. Bis dahin sucht das Frontend im bisherigen Index.
 *
 * Änderungen während eines Neuaufbaus gehen nicht verloren: `refresh()`/`remove()` aktualisieren den
 * aktiven Index und merken die ID in der Aufbau-Datei vor; der Neuaufbau liest vorgemerkte IDs vor dem
 * Aktivieren erneut. Änderungen ohne Inhalts-Hooks (Sammelaktionen, erreichte Veröffentlichungszeitpunkte)
 * gleicht `reconcile()` stündlich über `ReportsChangedDocuments` ab.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Search;

use CMS\Logger;
use CMS\Services\BatchJobService;
use CMS\Services\ContentLocalizationService;
use CMS\Services\ContentTypes\ContentTypeRegistry;
use CMS\Services\ContentTypes\SearchDocument;
use CMS\Services\ContentTypes\SearchDocumentProvider;
use CMS\Services\SearchService;
use CMS\Services\SettingsService;

if (!defined('ABSPATH')) {
    exit;
}

final class SearchIndexService
{
    public const JOB_TYPE = 'search_index_rebuild';
    public const JOB_SCOPE = 'index';
    public const BATCH_SIZE = 50;
    public const SETTINGS_GROUP = 'search_index';

    /** Höchstzahl geänderter Einträge je Typ und stündlichem Abgleich. */
    private const RECONCILE_LIMIT = 500;
    /** Überlappung des Abgleichsfensters, damit Änderungen um den Laufzeitpunkt nicht fehlen. */
    private const RECONCILE_OVERLAP = 120;

    private static ?self $instance = null;

    private ?SearchService $search = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    public function isAvailable(): bool
    {
        return $this->search()->isAvailable();
    }

    public function unavailableReason(): string
    {
        return $this->search()->getUnavailableReason();
    }

    /**
     * Indexierte Sprachen: Deutsch und alle Inhaltssprachen.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        $locales = ['de'];
        try {
            foreach (ContentLocalizationService::getInstance()->getContentLocales() as $locale) {
                if (SearchService::isValidDocumentIndexKey('page', $locale)) {
                    $locales[] = $locale;
                }
            }
        } catch (\Throwable $e) {
            $this->log('warning', 'Inhaltssprachen konnten nicht ermittelt werden.', ['exception' => $e]);
        }

        return array_values(array_unique($locales));
    }

    /**
     * @return array<string, SearchDocumentProvider>
     */
    public function providers(): array
    {
        $providers = [];
        foreach (ContentTypeRegistry::getInstance()->searchProviders() as $type => $provider) {
            if ($provider instanceof SearchDocumentProvider && SearchService::isValidDocumentIndexKey((string) $type, 'de')) {
                $providers[(string) $type] = $provider;
            }
        }

        return $providers;
    }

    /**
     * Status je Sprache mit Aufschlüsselung nach Inhaltstyp.
     *
     * @return list<array{locale:string, documents:int, expected:int, size:int, built_at:string, missing:list<string>, types:list<array<string, mixed>>}>
     */
    public function status(): array
    {
        $providers = $this->providers();
        $status = [];
        foreach ($this->locales() as $locale) {
            $row = [
                'locale' => $locale,
                'documents' => 0,
                'expected' => 0,
                'size' => 0,
                'built_at' => '',
                'missing' => [],
                'types' => [],
            ];
            foreach ($providers as $type => $provider) {
                $stats = $this->search()->documentIndexStats($type, $locale);
                $expected = $this->safeCount($provider, $locale);
                $row['documents'] += $stats['documents'];
                $row['expected'] += $expected;
                $row['size'] += $stats['size'];
                if ($stats['built_at'] !== '' && $stats['built_at'] > $row['built_at']) {
                    $row['built_at'] = $stats['built_at'];
                }
                if (!$stats['exists']) {
                    $row['missing'][] = $provider->label();
                }
                $row['types'][] = [
                    'type' => $type,
                    'label' => $provider->label(),
                    'exists' => $stats['exists'],
                    'documents' => $stats['documents'],
                    'expected' => $expected,
                    'size' => $stats['size'],
                    'built_at' => $stats['built_at'],
                ];
            }
            $status[] = $row;
        }

        return $status;
    }

    public function rebuildRecommended(): bool
    {
        return SettingsService::getInstance()->getBool(self::SETTINGS_GROUP, 'rebuild_recommended', false);
    }

    // ──────────────────────────────────────────────────────────
    //  Neuaufbau
    // ──────────────────────────────────────────────────────────

    /**
     * Startet den Neuaufbau oder liefert den laufenden Auftrag (`resumed` = true) – nie zwei parallel.
     *
     * @return array{job: array<string, mixed>, resumed: bool}
     */
    public function startRebuild(?int $userId): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Suchindex nicht verfügbar.');
        }

        $jobs = BatchJobService::getInstance();
        $active = $jobs->findActive(self::JOB_TYPE, self::JOB_SCOPE);
        if ($active !== null) {
            return ['job' => $active, 'resumed' => true];
        }

        $queue = [];
        $total = 0;
        foreach ($this->providers() as $type => $provider) {
            foreach ($this->locales() as $locale) {
                $queue[] = [$type, $locale];
                $total += $this->safeCount($provider, $locale);
            }
        }

        // Reste abgebrochener Läufe verwerfen; der neue Lauf legt seine Aufbau-Dateien neu an.
        foreach ($this->search()->listDocumentIndices() as $index) {
            if ($index['building']) {
                $this->search()->dropDocumentIndex($index['type'], $index['locale'], true);
            }
        }

        return $jobs->start(self::JOB_TYPE, self::JOB_SCOPE, $total, [
            'queue' => $queue,
            'pos' => 0,
            'cursor' => 0,
            'created' => false,
            'started_at' => date('Y-m-d H:i:s'),
        ], $userId);
    }

    /**
     * Ein Schritt des Neuaufbaus (ein Stapel bzw. das Aktivieren eines fertigen Teilindex).
     *
     * @return array<string, mixed> Job; `busy` = true, wenn gerade ein anderer Schritt läuft
     */
    public function step(int $jobId): array
    {
        $jobs = BatchJobService::getInstance();
        $job = $jobs->find($jobId);
        if ($job === null || $job['type'] !== self::JOB_TYPE) {
            throw new \InvalidArgumentException('Unbekannter Auftrag.');
        }

        return $jobs->step($jobId, fn (array $job): array => $this->runStep($job));
    }

    /**
     * Bricht den Neuaufbau ab. Bereits aktivierte Teilindizes bleiben, Aufbau-Dateien werden verworfen.
     *
     * @return array<string, mixed>|null Job nach dem Abbruch
     */
    public function cancel(int $jobId): ?array
    {
        $jobs = BatchJobService::getInstance();
        $job = $jobs->find($jobId);
        if ($job === null || $job['type'] !== self::JOB_TYPE) {
            return null;
        }

        $jobs->cancel($jobId);
        foreach ($this->queueOf($job) as [$type, $locale]) {
            $this->search()->dropDocumentIndex($type, $locale, true);
        }

        return $jobs->find($jobId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeJob(): ?array
    {
        return BatchJobService::getInstance()->findActive(self::JOB_TYPE, self::JOB_SCOPE);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestJob(): ?array
    {
        return BatchJobService::getInstance()->latest(self::JOB_TYPE, self::JOB_SCOPE);
    }

    // ──────────────────────────────────────────────────────────
    //  Inkrementelle Aktualisierung
    // ──────────────────────────────────────────────────────────

    /**
     * Übernimmt den aktuellen Stand eines Eintrags in alle vorhandenen Indizes seines Typs
     * (nicht mehr öffentlich → entfernen). Fehlt ein Index, legt erst der Neuaufbau ihn an.
     */
    public function refresh(string $type, int $id): void
    {
        $this->refreshMany($type, [$id]);
    }

    /**
     * @param list<int> $ids
     */
    public function refreshMany(string $type, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === [] || !SearchService::isValidDocumentIndexKey($type, 'de') || !$this->isAvailable()) {
            return;
        }

        $provider = $this->providers()[$type] ?? null;
        if ($provider === null) {
            return;
        }

        foreach ($this->locales() as $locale) {
            $hasLive = $this->search()->hasDocumentIndex($type, $locale);
            if ($this->search()->hasDocumentIndex($type, $locale, true)) {
                $this->search()->markDocumentsDirty($type, $locale, $ids);
            }
            if (!$hasLive) {
                continue;
            }

            [$documents, $removed] = $this->collectDocuments($provider, $type, $locale, $ids);
            if ($documents !== []) {
                $this->search()->writeDocuments($type, $locale, $documents);
            }
            if ($removed !== []) {
                $this->search()->removeDocuments($type, $locale, $removed);
            }
        }
    }

    /**
     * Entfernt einen Eintrag aus allen Indizes seines Typs (auch ohne angemeldeten Provider).
     */
    public function remove(string $type, int $id): void
    {
        if ($id <= 0 || !SearchService::isValidDocumentIndexKey($type, 'de') || !$this->isAvailable()) {
            return;
        }

        foreach ($this->search()->listDocumentIndices() as $index) {
            if ($index['type'] !== $type) {
                continue;
            }
            if ($index['building']) {
                $this->search()->markDocumentsDirty($type, $index['locale'], [$id]);
            } else {
                $this->search()->removeDocuments($type, $index['locale'], [$id]);
            }
        }
    }

    /**
     * Stündlicher Abgleich für Änderungen ohne Inhalts-Hooks.
     *
     * @return array<string, int> Anzahl abgeglichener Einträge je Typ
     */
    public function reconcile(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $settings = SettingsService::getInstance();
        $now = date('Y-m-d H:i:s');
        $last = $settings->getString(self::SETTINGS_GROUP, 'reconciled_at', '');
        $settings->set(self::SETTINGS_GROUP, 'reconciled_at', $now);
        if ($last === '' || strtotime($last) === false) {
            return [];
        }

        $since = date('Y-m-d H:i:s', (int) strtotime($last) - self::RECONCILE_OVERLAP);
        $result = [];
        foreach ($this->providers() as $type => $provider) {
            if (!$provider instanceof ReportsChangedDocuments || !$this->hasAnyIndex($type)) {
                continue;
            }

            try {
                $ids = $provider->changedDocumentIds($since, $now, self::RECONCILE_LIMIT);
            } catch (\Throwable $e) {
                $this->log('warning', 'Abgleich: geänderte Einträge konnten nicht ermittelt werden.', ['type' => $type, 'exception' => $e]);
                continue;
            }

            if (count($ids) >= self::RECONCILE_LIMIT) {
                // Mehr Änderungen als ein Lauf abgleicht (z. B. große Sammelaktion): Neuaufbau empfehlen.
                $settings->set(self::SETTINGS_GROUP, 'rebuild_recommended', '1');
            }
            $this->refreshMany($type, $ids);
            $result[$type] = count($ids);
        }

        return $result;
    }

    // ──────────────────────────────────────────────────────────
    //  Intern
    // ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function runStep(array $job): array
    {
        $state = is_array($job['state'] ?? null) ? $job['state'] : [];
        $queue = $this->queueOf($job);
        $pos = max(0, (int) ($state['pos'] ?? 0));
        $cursor = max(0, (int) ($state['cursor'] ?? 0));
        $created = !empty($state['created']);

        if ($pos >= count($queue)) {
            return $this->finish($job, $queue, $state);
        }

        [$type, $locale] = $queue[$pos];
        $provider = $this->providers()[$type] ?? null;
        $label = $provider !== null ? $provider->label() : $type;
        $entry = sprintf('%s (%s)', $label, strtoupper($locale));

        if ($provider === null) {
            $this->search()->dropDocumentIndex($type, $locale, true);
            $state = $this->advance($state, $pos);

            return [
                'state' => $state,
                'errors' => [sprintf(self::t('search_index.job_type_missing', 'Inhaltstyp „%s“ ist nicht mehr angemeldet und wurde übersprungen.'), $type)],
                'message' => $entry,
            ] + ($state['pos'] >= count($queue) ? $this->finish($job, $queue, $state) : []);
        }

        if (!$created || !$this->search()->hasDocumentIndex($type, $locale, true)) {
            if (!$this->search()->createDocumentIndex($type, $locale)) {
                throw new \RuntimeException('Aufbau-Datei für ' . $type . '/' . $locale . ' konnte nicht angelegt werden.');
            }
            $state['created'] = true;
            $state['entry_done'] = 0;
            $cursor = 0;
        }

        $documents = $provider->documents($locale, $cursor, self::BATCH_SIZE);
        $maxId = $cursor;
        foreach ($documents as $document) {
            if ($document instanceof SearchDocument && $document->id > $maxId) {
                $maxId = $document->id;
            }
        }

        if ($documents !== [] && $maxId > $cursor) {
            $written = $this->search()->writeDocuments($type, $locale, $documents, true);
            if ($written < 0) {
                throw new \RuntimeException('Dokumente für ' . $type . '/' . $locale . ' konnten nicht geschrieben werden.');
            }

            $skipped = count($documents) - $written;
            $state['cursor'] = $maxId;
            $state['entry_done'] = (int) ($state['entry_done'] ?? 0) + $written;
            $processed = (int) ($job['processed'] ?? 0) + $written;

            return [
                'processed' => $written,
                'failed' => $skipped,
                'errors' => $skipped > 0
                    ? [sprintf(self::t('search_index.job_skipped', '%1$s: %2$d ungültige Dokumente übersprungen.'), $entry, $skipped)]
                    : [],
                'state' => $state,
                'total' => max((int) ($job['total'] ?? 0), $processed),
                'message' => sprintf(self::t('search_index.job_progress', '%1$s: %2$d Dokumente indexiert'), $entry, $state['entry_done']),
            ];
        }

        if ($documents !== []) {
            // Provider liefert keine größeren IDs mehr (Vertragsverletzung) – Eintrag abschließen statt Endlosschleife.
            $this->log('warning', 'Such-Provider lieferte keine IDs größer als der Cursor.', ['type' => $type, 'locale' => $locale, 'cursor' => $cursor]);
        }

        $this->applyPendingChanges($provider, $type, $locale);
        if (!$this->search()->activateDocumentIndex($type, $locale)) {
            throw new \RuntimeException('Suchindex ' . $type . '/' . $locale . ' konnte nicht aktiviert werden.');
        }

        $state = $this->advance($state, $pos);
        $result = [
            'state' => $state,
            'message' => sprintf(self::t('search_index.job_activated', '%s: Index aktiviert'), $entry),
        ];

        return $state['pos'] >= count($queue) ? $this->finish($job, $queue, $state) + $result : $result;
    }

    /**
     * Abschluss: veraltete Indizes (Typ ohne Provider, Sprache nicht mehr angeboten) entfernen.
     *
     * @param array<string, mixed> $job
     * @param list<array{0:string, 1:string}> $queue
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function finish(array $job, array $queue, array $state): array
    {
        $current = [];
        foreach ($queue as [$type, $locale]) {
            $current[$type . '|' . $locale] = true;
        }
        foreach ($this->search()->listDocumentIndices() as $index) {
            if (!isset($current[$index['type'] . '|' . $index['locale']])) {
                $this->search()->dropDocumentIndex($index['type'], $index['locale'], $index['building']);
            }
        }

        $settings = SettingsService::getInstance();
        $settings->set(self::SETTINGS_GROUP, 'rebuild_recommended', '0');
        $startedAt = (string) ($state['started_at'] ?? '');
        if ($startedAt !== '') {
            // Änderungen ohne Hooks seit Beginn des Laufs gleicht der nächste stündliche Abgleich ab.
            $settings->set(self::SETTINGS_GROUP, 'reconciled_at', $startedAt);
        }

        $this->log('info', 'Suchindex neu aufgebaut.', [
            'job_id' => (int) ($job['id'] ?? 0),
            'documents' => (int) ($job['processed'] ?? 0),
            'indices' => count($queue),
        ]);

        return [
            'done' => true,
            'state' => $state,
            'total' => max((int) ($job['total'] ?? 0), (int) ($job['processed'] ?? 0)),
            'message' => self::t('search_index.job_done', 'Neuaufbau abgeschlossen.'),
        ];
    }

    /**
     * IDs, die sich während des Neuaufbaus geändert haben, mit dem aktuellen Stand in die Aufbau-Datei schreiben.
     */
    private function applyPendingChanges(SearchDocumentProvider $provider, string $type, string $locale): void
    {
        $ids = $this->search()->pullDirtyDocuments($type, $locale);
        if ($ids === []) {
            return;
        }

        [$documents, $removed] = $this->collectDocuments($provider, $type, $locale, $ids);
        if ($documents !== []) {
            $this->search()->writeDocuments($type, $locale, $documents, true);
        }
        if ($removed !== []) {
            $this->search()->removeDocuments($type, $locale, $removed, true);
        }
    }

    /**
     * @param list<int> $ids
     * @return array{0: list<SearchDocument>, 1: list<int>} zu schreibende Dokumente, zu entfernende IDs
     */
    private function collectDocuments(SearchDocumentProvider $provider, string $type, string $locale, array $ids): array
    {
        $documents = [];
        $removed = [];
        foreach ($ids as $id) {
            try {
                $document = $provider->document($id, $locale);
            } catch (\Throwable $e) {
                $this->log('warning', 'Dokument konnte nicht gelesen werden.', ['type' => $type, 'id' => $id, 'locale' => $locale, 'exception' => $e]);
                continue;
            }

            if ($document instanceof SearchDocument && $document->type === $type && $document->locale === $locale && $document->id === $id) {
                $documents[] = $document;
            } else {
                $removed[] = $id;
            }
        }

        return [$documents, $removed];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function advance(array $state, int $pos): array
    {
        $state['pos'] = $pos + 1;
        $state['cursor'] = 0;
        $state['created'] = false;
        $state['entry_done'] = 0;

        return $state;
    }

    /**
     * @param array<string, mixed> $job
     * @return list<array{0:string, 1:string}>
     */
    private function queueOf(array $job): array
    {
        $queue = [];
        foreach ((array) (($job['state'] ?? [])['queue'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry[0], $entry[1])
                && SearchService::isValidDocumentIndexKey((string) $entry[0], (string) $entry[1])) {
                $queue[] = [(string) $entry[0], (string) $entry[1]];
            }
        }

        return $queue;
    }

    private function hasAnyIndex(string $type): bool
    {
        foreach ($this->search()->listDocumentIndices() as $index) {
            if ($index['type'] === $type) {
                return true;
            }
        }

        return false;
    }

    private function safeCount(SearchDocumentProvider $provider, string $locale): int
    {
        try {
            return max(0, $provider->countDocuments($locale));
        } catch (\Throwable $e) {
            $this->log('warning', 'Dokumente konnten nicht gezählt werden.', ['type' => $provider->type(), 'locale' => $locale, 'exception' => $e]);

            return 0;
        }
    }

    private function search(): SearchService
    {
        return $this->search ??= SearchService::getInstance();
    }

    private static function t(string $key, string $fallback): string
    {
        if (!function_exists('__')) {
            return $fallback;
        }

        $translated = (string) __($key);

        return $translated !== '' && $translated !== $key ? $translated : $fallback;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        Logger::instance()->withChannel('search')->{$level}('Suchindex: ' . $message, $context);
    }
}
