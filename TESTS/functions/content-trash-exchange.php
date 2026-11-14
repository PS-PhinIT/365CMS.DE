<?php
/**
 * Inhalte, Papierkorb, Import/Export (Prüfbereich „Fehlende Funktionen“): Beitragsstatus „private“, Statusrevision,
 * Kalender-Verschieben bei geändertem Zustand, automatisches Leeren hinter Einträgen mit Warnung, Export ohne
 * Arbeitsverzeichnis und nachgetragene Kategorie-Hierarchie beim Import. Ohne Datenbank.
 * Wird von TESTS/functions/run.php eingebunden.
 */

declare(strict_types=1);

use CMS\SchemaManager;
use CMS\Services\ContentTypes\TrashProvider;
use CMS\Services\ContentTypes\TrashResult;
use CMS\Services\EditorialCalendarService;
use CMS\Services\Exchange\ContentExchangeService;
use CMS\Services\Exchange\TaxonomyExchangeProvider;
use CMS\Services\PostRevisionService;
use CMS\Services\Trash\TrashService;

/**
 * Papierkorb-Attrappe: Einträge in Löschreihenfolge, `blocked` = Warnung (bleibt beim automatischen Leeren stehen).
 */
function contentTestTrashProvider(array $items, int $maxLimit = 1000): TrashProvider
{
    return new class ($items, $maxLimit) implements TrashProvider {
        public int $deleteCalls = 0;

        /** @param array<int, bool> $items ID => blockiert */
        public function __construct(public array $items, private int $maxLimit)
        {
        }

        public function type(): string
        {
            return 'test';
        }

        public function label(): string
        {
            return 'Test';
        }

        public function capability(): string
        {
            return 'manage_trash';
        }

        public function countTrashed(string $search = ''): int
        {
            return count($this->items);
        }

        public function listTrashed(int $offset, int $limit, string $search = ''): array
        {
            return [];
        }

        public function restore(int $id, int $userId): TrashResult
        {
            return TrashResult::success();
        }

        public function deletionWarnings(int $id): array
        {
            return !empty($this->items[$id]) ? ['Wird noch verwendet'] : [];
        }

        public function deletePermanently(int $id, int $userId, bool $confirmed = false): TrashResult
        {
            $this->deleteCalls++;
            if (!array_key_exists($id, $this->items)) {
                return TrashResult::failure(TrashResult::CODE_NOT_FOUND, 'fehlt');
            }
            if ($this->items[$id] && !$confirmed) {
                return TrashResult::confirmationRequired($this->deletionWarnings($id));
            }
            unset($this->items[$id]);

            return TrashResult::success();
        }

        public function expiredIds(\DateTimeImmutable $trashedBefore, int $limit): array
        {
            return array_slice(array_keys($this->items), 0, max(1, min($this->maxLimit, $limit)));
        }
    };
}

/** @return array{deleted:int,skipped:int,failed:int} */
function contentTestPurge(TrashProvider $provider): array
{
    $service = (new ReflectionClass(TrashService::class))->newInstanceWithoutConstructor();

    return (new ReflectionMethod(TrashService::class, 'purgeProvider'))->invoke($service, 'test', $provider, new DateTimeImmutable('2026-01-01'));
}

T::run('Beiträge: Status „private“ passt in die Spalte', static function (): void {
    T::same(true, SchemaManager::postStatusColumnNeedsWidening("enum('draft','published','trash')"), 'Altes ENUM ohne „private“ wird umgestellt');
    T::same(false, SchemaManager::postStatusColumnNeedsWidening("enum('draft','published','private','trash')"), 'ENUM mit „private“ bleibt');
    T::same(false, SchemaManager::postStatusColumnNeedsWidening('varchar(20)'), 'VARCHAR bleibt (Migration ist wiederholbar)');

    $posts = SchemaManager::getSchemaQueries('cms_', 'utf8mb4')['posts'] ?? '';
    T::check(str_contains($posts, "status VARCHAR(20) NOT NULL DEFAULT 'draft'"), 'Neuinstallation legt posts.status als VARCHAR(20) an');
});

T::run('Beitragsrevision: reiner Statuswechsel erzeugt eine Revision', static function (): void {
    T::same(true, PostRevisionService::isStatusOnlyChange(PostRevisionService::TYPE_MANUAL, 'draft', 'published'), 'Entwurf → Veröffentlicht bei gleichem Inhalt');
    T::same(false, PostRevisionService::isStatusOnlyChange(PostRevisionService::TYPE_MANUAL, 'published', 'published'), 'Gleicher Status bleibt „unverändert“');
    T::same(false, PostRevisionService::isStatusOnlyChange(PostRevisionService::TYPE_AUTOSAVE, 'draft', 'published'), 'Autosave legt keine Statusrevision an');
    T::same(false, PostRevisionService::isStatusOnlyChange(PostRevisionService::TYPE_MANUAL, null, 'published'), 'Ohne Vorgängerrevision kein Statusvergleich');
    T::same(false, PostRevisionService::isStatusOnlyChange(PostRevisionService::TYPE_MANUAL, '', 'published'), 'Altbestand ohne Status löst keine Revision aus');
});

T::run('Redaktionskalender: Verschieben erkennt zwischenzeitliche Änderungen', static function (): void {
    T::same(false, EditorialCalendarService::moveTargetChanged('draft', 'draft'), 'Gleicher Termin (0 geänderte Zeilen) ist kein Konflikt');
    T::same(true, EditorialCalendarService::moveTargetChanged('draft', 'published'), 'Status inzwischen geändert');
    T::same(true, EditorialCalendarService::moveTargetChanged('trash', 'draft'), 'Inzwischen im Papierkorb');
    T::same(true, EditorialCalendarService::moveTargetChanged(null, 'draft'), 'Eintrag nicht mehr vorhanden');
});

T::run('Papierkorb: automatisches Leeren liest hinter Einträgen mit Warnung weiter', static function (): void {
    $limit = TrashService::PURGE_BATCH_LIMIT;

    // 250 blockierte Einträge vorn, dahinter 300 löschbare.
    $items = array_fill_keys(range(1, 250), true) + array_fill_keys(range(251, 550), false);
    $provider = contentTestTrashProvider($items);
    $report = contentTestPurge($provider);
    T::same($limit, $report['deleted'], 'Löschbare Einträge hinter den blockierten werden bis zum Limit gelöscht');
    T::same(250, $report['skipped'], 'Jeder blockierte Eintrag zählt genau einmal');
    T::same(0, $report['failed'], 'Keine Fehlschläge');
    T::same(250 + $limit, $provider->deleteCalls, 'Kein Eintrag wird im selben Lauf mehrfach geprüft');

    $provider = contentTestTrashProvider(array_fill_keys(range(1, 50), false));
    T::same(['deleted' => 50, 'skipped' => 0, 'failed' => 0], contentTestPurge($provider), 'Kleiner Papierkorb wird in einem Durchgang geleert');

    // Provider deckeln das Limit selbst: Der Lauf endet, statt dieselben Einträge endlos zu lesen.
    $provider = contentTestTrashProvider(array_fill_keys(range(1, 400), true) + [401 => false], 300);
    $report = contentTestPurge($provider);
    T::same(['deleted' => 0, 'skipped' => 300, 'failed' => 0], $report, 'Gedeckelter Provider beendet den Lauf');

    $provider = contentTestTrashProvider(array_fill_keys(range(1, 5000), true));
    $report = contentTestPurge($provider);
    T::check($report['skipped'] <= 2000 && $provider->deleteCalls === $report['skipped'], 'Obergrenze an Prüfungen je Lauf', (string) $report['skipped']);
});

T::run('Export: begonnener Export ohne Arbeitsverzeichnis wird erkannt', static function (): void {
    T::same(false, ContentExchangeService::exportHasProgress(['index' => 0, 'cursor' => 0]), 'Frischer Export darf sein Verzeichnis neu anlegen');
    T::same(true, ContentExchangeService::exportHasProgress(['index' => 0, 'cursor' => 50]), 'Erster Typ teilweise geschrieben');
    T::same(true, ContentExchangeService::exportHasProgress(['index' => 2, 'cursor' => 0]), 'Spätere Typen bzw. Abschlussschritt');
});

T::run('Import: Oberkategorie mit höherer ID wird nachgetragen', static function (): void {
    // Ziel-ID 31 („Azure“) wartet auf die alte Oberkategorie 9 („Cloud“), 32 ist bereits erledigt.
    $pending = ['31' => 9, '32' => 0, '33' => 12];
    T::same([31], TaxonomyExchangeProvider::pendingChildrenOf($pending, 9, 40), 'Wartende Unterkategorie wird gefunden');
    T::same([], TaxonomyExchangeProvider::pendingChildrenOf($pending, 5, 40), 'Andere Kategorien bleiben unberührt');
    T::same([], TaxonomyExchangeProvider::pendingChildrenOf(['31' => 9], 9, 31), 'Kein Selbstbezug, wenn Ober- und Unterkategorie auf denselben Eintrag fallen');
    T::same([], TaxonomyExchangeProvider::pendingChildrenOf(['31' => 0], 0, 40), 'Erledigte Einträge (0) passen auf keine Kategorie');
});

T::run('Sprachschlüssel der Korrekturen sind in beiden Sprachen vorhanden', static function (): void {
    foreach (['de', 'en'] as $locale) {
        $yaml = (string) file_get_contents(ABSPATH . 'lang/' . $locale . '.yaml');
        foreach (['calendar.move.error_state_changed', 'exchange.error.workdir_missing', 'exchange.export.resumed'] as $key) {
            T::check(str_contains($yaml, '"' . $key . '":'), $locale . '.yaml enthält ' . $key);
        }
    }
});
