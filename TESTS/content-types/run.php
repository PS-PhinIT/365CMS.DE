<?php
/**
 * Tests für die Inhaltstyp-Provider-Registry (KLARSTELLUNGEN §5) ohne Datenbank.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/content-types/run.php [-v]
 */

declare(strict_types=1);

use CMS\Hooks;
use CMS\Services\ContentTypes\ContentTypeRegistry;
use CMS\Services\ContentTypes\ExchangeFilter;
use CMS\Services\ContentTypes\ExchangeProvider;
use CMS\Services\ContentTypes\ExportBatch;
use CMS\Services\ContentTypes\ImportContext;
use CMS\Services\ContentTypes\ImportPreview;
use CMS\Services\ContentTypes\ImportResult;
use CMS\Services\ContentTypes\SearchDocument;
use CMS\Services\ContentTypes\SearchDocumentProvider;
use CMS\Services\ContentTypes\TrashItem;
use CMS\Services\ContentTypes\TrashProvider;
use CMS\Services\ContentTypes\TrashResult;

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once dirname(__DIR__) . '/lib/TestRunner.php';

T::$verbose = in_array('-v', $argv ?? [], true);

abstract class FakeProvider
{
    public function __construct(private readonly string $type, private readonly int $priority = 50)
    {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function label(): string
    {
        return ucfirst($this->type);
    }

    public function capability(): string
    {
        return 'manage_' . $this->type;
    }

    public function importPriority(): int
    {
        return $this->priority;
    }
}

final class FakeTrashProvider extends FakeProvider implements TrashProvider
{
    public function countTrashed(string $search = ''): int
    {
        return 0;
    }

    public function listTrashed(int $offset, int $limit, string $search = ''): array
    {
        return [new TrashItem($this->type(), 1, 'Titel')];
    }

    public function restore(int $id, int $userId): TrashResult
    {
        return TrashResult::success('', ['slug' => 'neu-2']);
    }

    public function deletionWarnings(int $id): array
    {
        return ['in Verwendung'];
    }

    public function deletePermanently(int $id, int $userId, bool $confirmed = false): TrashResult
    {
        return $confirmed ? TrashResult::success() : TrashResult::confirmationRequired($this->deletionWarnings($id));
    }

    public function expiredIds(\DateTimeImmutable $trashedBefore, int $limit): array
    {
        return [];
    }
}

final class FakeExchangeProvider extends FakeProvider implements ExchangeProvider
{
    public function countExport(ExchangeFilter $filter): int
    {
        return 0;
    }

    public function exportBatch(ExchangeFilter $filter, int $cursor, int $limit): ExportBatch
    {
        return new ExportBatch([], [], $cursor, true);
    }

    public function preview(array $record, ImportContext $context): ImportPreview
    {
        return new ImportPreview(ImportPreview::ACTION_CREATE, 'x');
    }

    public function import(array $record, ImportContext $context): ImportResult
    {
        return new ImportResult(ImportResult::CREATED, 1);
    }
}

/** Implementiert zwei Fähigkeiten auf einmal (wie ein Provider für Papierkorb und Suche). */
final class FakeTrashAndSearchProvider extends FakeProvider implements TrashProvider, SearchDocumentProvider
{
    public function countTrashed(string $search = ''): int
    {
        return 0;
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
        return [];
    }

    public function deletePermanently(int $id, int $userId, bool $confirmed = false): TrashResult
    {
        return TrashResult::success();
    }

    public function expiredIds(\DateTimeImmutable $trashedBefore, int $limit): array
    {
        return [];
    }

    public function countDocuments(string $locale): int
    {
        return 1;
    }

    public function documents(string $locale, int $cursor, int $limit): array
    {
        return [new SearchDocument($this->type(), 1, $locale, 'Titel', 'Text', '/x')];
    }

    public function document(int $id, string $locale): ?SearchDocument
    {
        return null;
    }
}

/** Frische Registry je Test, ohne das Singleton zu verändern. */
function freshRegistry(): ContentTypeRegistry
{
    return (new ReflectionClass(ContentTypeRegistry::class))->newInstanceWithoutConstructor();
}

T::run('Registry: Anmeldung je Fähigkeit', static function (): void {
    $registry = freshRegistry();
    T::check($registry->register(new FakeTrashProvider('page')), 'Papierkorb-Provider wird angemeldet');
    T::check($registry->register(new FakeExchangeProvider('page')), 'derselbe Typ mit anderer Fähigkeit wird angemeldet');
    T::check(!$registry->register(new FakeTrashProvider('page')), 'Doppelanmeldung derselben Fähigkeit wird ignoriert');
    T::check($registry->register(new FakeTrashAndSearchProvider('hub_site')), 'Provider mit zwei Fähigkeiten wird angemeldet');
    T::same(['page', 'hub_site'], array_keys($registry->trashProviders()), 'Papierkorb-Provider in Anmeldereihenfolge');
    T::same(['hub_site'], array_keys($registry->searchProviders()), 'Such-Provider nur für implementierte Fähigkeit');
    T::check($registry->trashProvider('hub_site') instanceof SearchDocumentProvider, 'gleiches Objekt unter beiden Fähigkeiten');
    T::same(null, $registry->exchangeProvider('hub_site'), 'keine Export-Fähigkeit ohne Schnittstelle');
    T::same(null, $registry->trashProvider('unbekannt'), 'unbekannter Typ liefert null');
});

T::run('Registry: ungültige Typen und Sortierung', static function (): void {
    $registry = freshRegistry();
    foreach (['', 'Page', '1page', 'page-type', str_repeat('a', 41)] as $type) {
        T::check(!$registry->register(new FakeTrashProvider($type)), 'ungültiger Typ abgelehnt: ' . T::export($type));
    }
    $registry->register(new FakeExchangeProvider('post', 50));
    $registry->register(new FakeExchangeProvider('taxonomy', 10));
    $registry->register(new FakeExchangeProvider('media', 20));
    T::same(['taxonomy', 'media', 'post'], array_keys($registry->exchangeProviders()), 'Export/Import nach importPriority sortiert');
});

T::run('Registry: Anmelde-Hook wird einmal beim ersten Zugriff ausgelöst', static function (): void {
    $calls = 0;
    Hooks::addAction(ContentTypeRegistry::REGISTER_ACTION, static function (ContentTypeRegistry $registry) use (&$calls): void {
        $calls++;
        $registry->register(new FakeTrashProvider('form_submission'));
    });
    $registry = freshRegistry();
    T::same(0, $calls, 'kein Auslösen vor dem ersten Zugriff');
    T::same(['form_submission'], array_keys($registry->trashProviders()), 'per Hook angemeldeter Provider ist sichtbar');
    $registry->exchangeProviders();
    $registry->searchProviders();
    T::same(1, $calls, 'Hook wird nur einmal ausgelöst');
});

T::run('TrashResult und ImportResult', static function (): void {
    $result = (new FakeTrashProvider('media'))->deletePermanently(1, 1);
    T::same(
        [false, TrashResult::CODE_CONFIRMATION_REQUIRED, ['in Verwendung']],
        [$result->ok, $result->code, $result->data['warnings'] ?? null],
        'Bestätigung nötig mit Warnungen'
    );
    T::same(TrashResult::CODE_ERROR, TrashResult::failure(TrashResult::CODE_OK, 'x')->code, 'failure() erzwingt Fehlercode');
    T::check((new ImportResult(ImportResult::RENAMED, 3))->succeeded(), 'umbenannt zählt als Erfolg');
    T::check(!(new ImportResult(ImportResult::SKIPPED))->succeeded(), 'übersprungen zählt nicht als Erfolg');
});

T::run('ImportContext: Strategie, Zuordnungen, Zustand', static function (): void {
    T::same(ImportContext::STRATEGY_SKIP, (new ImportContext('drop-table', 1))->conflictStrategy, 'unbekannte Strategie fällt auf skip zurück');
    $context = new ImportContext(ImportContext::STRATEGY_RENAME, 7, true, [3 => 9]);
    $context->map('post', 12, 40);
    $context->map('media_url', '/uploads/a.jpg', '/uploads/b.jpg');
    T::same(9, $context->authorFor(3), 'zugeordneter Autor');
    T::same(7, $context->authorFor(4), 'ohne Zuordnung importierender Benutzer');
    $restored = ImportContext::fromState(json_decode((string) json_encode($context->toState()), true));
    T::same(
        [ImportContext::STRATEGY_RENAME, 7, true, 40, '/uploads/b.jpg', 9],
        [$restored->conflictStrategy, $restored->userId, $restored->importAsDraft, $restored->resolve('post', '12'), $restored->resolve('media_url', '/uploads/a.jpg'), $restored->authorFor(3)],
        'Zustand übersteht JSON-Rundreise (Batch-Jobs)'
    );
    T::same(null, $restored->resolve('post', 13), 'fehlende Zuordnung liefert null');
});

T::run('ImportContext: Dateien nur innerhalb des Staging-Verzeichnisses', static function (): void {
    $root = sys_get_temp_dir() . '/cms-ct-' . bin2hex(random_bytes(4));
    mkdir($root . '/files/sub', 0777, true);
    file_put_contents($root . '/files/sub/a.txt', 'a');
    file_put_contents($root . '/outside.txt', 'x');
    @symlink($root . '/outside.txt', $root . '/files/link.txt');
    $context = new ImportContext(ImportContext::STRATEGY_SKIP, 1, false, [], $root . '/files');
    T::same(realpath($root . '/files/sub/a.txt'), $context->stagedFile('sub/a.txt'), 'Datei im Staging-Verzeichnis');
    T::same(null, $context->stagedFile('../outside.txt'), 'Pfad-Traversal abgelehnt');
    T::same(null, $context->stagedFile('sub'), 'Verzeichnis abgelehnt');
    T::same(null, $context->stagedFile("sub/a.txt\0.jpg"), 'Nullbyte abgelehnt');
    if (is_link($root . '/files/link.txt')) {
        T::same(null, $context->stagedFile('link.txt'), 'Symlink nach außen abgelehnt');
    }
    T::same(null, (new ImportContext(ImportContext::STRATEGY_SKIP, 1))->stagedFile('sub/a.txt'), 'ohne Staging-Verzeichnis keine Datei');
    foreach ([$root . '/files/link.txt', $root . '/files/sub/a.txt', $root . '/outside.txt'] as $file) {
        if (is_link($file) || is_file($file)) {
            unlink($file);
        }
    }
    @rmdir($root . '/files/sub');
    @rmdir($root . '/files');
    @rmdir($root);
});

T::run('ExchangeFilter: Zustand', static function (): void {
    $filter = new ExchangeFilter(['published', 'draft'], '2026-01-01', '2026-12-31', true, ['locale' => 'de']);
    $restored = ExchangeFilter::fromArray(json_decode((string) json_encode($filter->toArray()), true));
    T::same($filter->toArray(), $restored->toArray(), 'Filter übersteht JSON-Rundreise');
    T::same([], ExchangeFilter::fromArray(['statuses' => [1, '', null]])->statuses, 'ungültige Status werden verworfen');
});

T::finish();
