<?php
/**
 * Code- und Konfigurationsverweise (Prüfbereich „Fehlende oder falsche Verweise“): Spalten, die der Code abfragt,
 * stehen im Schema und in der Laufzeitprüfung; Slug-Freigabe im Papierkorb (Verschieben, Wiederherstellen mit und
 * ohne Slug-Konflikt, endgültiges Löschen); Klassen-, Hook-, Dokument-, Icon- und Blocklisten zeigen auf vorhandene
 * Ziele; Manifeste und .gitignore sind in sich stimmig. Ohne MySQL (Papierkorb gegen SQLite im Speicher).
 * Wird von TESTS/functions/run.php eingebunden.
 */

declare(strict_types=1);

use CMS\Database;
use CMS\SchemaManager;
use CMS\Services\EditorJs\EditorJsBlockRegistry;
use CMS\Services\FeatureHooks\Registry;
use CMS\Services\IntegratedPluginService;
use CMS\Services\Trash\AbstractContentTrashProvider;
use CMS\Services\Trash\TrashSlug;

function referenceTestRoot(): string
{
    return dirname(__DIR__, 2);
}

function referenceTestSource(string $relativePath): string
{
    return (string) file_get_contents(referenceTestRoot() . '/' . $relativePath);
}

/** Datenbank-Attrappe: SQLite im Speicher, die MySQL-Eigenheiten der Papierkorb-Abfragen übersetzt. */
function referenceTestTrashDatabase(bool $withTrashSlugColumns = true): Database
{
    return new class ($withTrashSlugColumns) extends Database {
        public PDO $sqlite;

        public function __construct(bool $withTrashSlugColumns)
        {
            $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->sqlite->exec('CREATE TABLE cms_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, title_en TEXT, slug TEXT NOT NULL UNIQUE,
                slug_en TEXT, status TEXT, previous_status TEXT, trashed_at TEXT, trashed_by INTEGER, updated_at TEXT'
                . ($withTrashSlugColumns ? ', trash_slug TEXT, trash_slug_en TEXT' : '') . ')');
            $this->sqlite->exec('CREATE TABLE cms_users (id INTEGER PRIMARY KEY, display_name TEXT, username TEXT)');
            $this->sqlite->exec('CREATE TABLE cms_settings (option_name TEXT, option_value TEXT)');
            // Inhalts-Cache nicht leeren (TrashSupport::clearContentCache).
            $this->sqlite->exec("INSERT INTO cms_settings VALUES ('perf_auto_clear_content_cache', '0')");
        }

        public function execute(string $sql, array $params = []): \PDOStatement
        {
            $sql = str_replace('<=>', 'IS', $sql);
            if (preg_match('/^\s*(UPDATE|DELETE)\b/i', $sql) === 1) {
                $sql = (string) preg_replace('/\s+LIMIT 1\s*$/', '', $sql);
            }
            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute(array_values($params));

            return $stmt;
        }

        public function columnExists(string $table, string $column): bool
        {
            foreach ($this->sqlite->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $info) {
                if ($info['name'] === $column) {
                    return true;
                }
            }

            return false;
        }

        public function getPdo(): PDO
        {
            return $this->sqlite;
        }

        public function getPrefix(): string
        {
            return 'cms_';
        }
    };
}

/** Papierkorb-Provider über `cms_posts`, ohne Audit-Log und Folge-Hooks (beide brauchen die echte Datenbank). */
function referenceTestTrashProvider(Database $db): AbstractContentTrashProvider
{
    return new class ($db) extends AbstractContentTrashProvider {
        public function type(): string
        {
            return 'post';
        }

        public function label(): string
        {
            return 'Beitrag';
        }

        public function capability(): string
        {
            return 'edit_all_posts';
        }

        protected function table(): string
        {
            return 'posts';
        }

        protected function slugMaxLength(): int
        {
            return 255;
        }

        protected function restorableStatuses(): array
        {
            return ['draft', 'published', 'private'];
        }

        protected function saveHook(): string
        {
            return 'reference_test_saved';
        }

        protected function deletedHook(): string
        {
            return 'reference_test_deleted';
        }

        protected function auditEntity(): string
        {
            return 'posts';
        }

        protected function manageUrl(int $id): string
        {
            return '/admin/posts?status=trash';
        }

        protected function auditNoun(): string
        {
            return 'Beitrag';
        }

        protected function deleteDependentData(int $id): array
        {
            return [];
        }

        protected function afterVisibilityChange(int $id, string $reason): void
        {
        }

        protected function audit(string $action, string $description, int $id, array $metadata, string $severity = 'info'): void
        {
        }
    };
}

/** @return array<string, mixed> */
function referenceTestPost(Database $db, int $id): array
{
    return $db->execute('SELECT * FROM cms_posts WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC) ?: [];
}

T::run('Schema: abgefragte Spalten stehen in Neuinstallation, Laufzeitprüfung und Migration', static function (): void {
    $schema = SchemaManager::getSchemaQueries('cms_', 'utf8mb4');
    $schemaSource = referenceTestSource('CMS/core/SchemaManager.php');
    $migrationSource = referenceTestSource('CMS/core/MigrationManager.php');

    foreach (['pages' => 200, 'posts' => 255] as $table => $length) {
        T::check(preg_match('/\bslug VARCHAR\(' . $length . '\)/', $schema[$table]) === 1, "{$table}.slug hat die Länge {$length}");
        foreach (['trash_slug', 'trash_slug_en'] as $column) {
            T::check(
                str_contains($schema[$table], "{$column} VARCHAR({$length}) DEFAULT NULL"),
                "Neuinstallation: {$table}.{$column} VARCHAR({$length})"
            );
            T::check(
                str_contains($migrationSource, "ALTER TABLE `{\$p}{$table}` ADD COLUMN `{$column}` VARCHAR({$length}) DEFAULT NULL"),
                "Migration v25: {$table}.{$column}"
            );
        }
    }
    T::check(
        str_contains($schemaSource, 'ADD COLUMN {$column} VARCHAR({$slugLength}) DEFAULT NULL')
            && str_contains($schemaSource, "foreach (['trash_slug', 'trash_slug_en'] as \$column)"),
        'Laufzeitprüfung ensureTrashColumns() legt trash_slug/trash_slug_en an'
    );

    T::check(str_contains($schema['sessions'], 'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP'), 'Neuinstallation: sessions.created_at');
    T::check(str_contains($schemaSource, 'sessions ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP'), 'Laufzeitprüfung: sessions.created_at');
    T::check(
        !str_contains(referenceTestSource('CMS/core/Auth.php'), 'REPLACE INTO {$db->getPrefix()}sessions'),
        'Sitzungsregister schreibt ohne REPLACE (created_at bleibt erhalten)'
    );
    T::check(str_contains($schema['subscription_plans'], 'is_featured TINYINT(1) NOT NULL DEFAULT 0'), 'Neuinstallation: subscription_plans.is_featured');
    T::check(
        str_contains($schemaSource, 'subscription_plans ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active'),
        'Laufzeitprüfung: subscription_plans.is_featured'
    );
});

T::run('Papierkorb: Slug wird freigegeben und beim Wiederherstellen zurückgegeben', static function (): void {
    $db = referenceTestTrashDatabase();
    $provider = referenceTestTrashProvider($db);
    $db->execute("INSERT INTO cms_posts (title, slug, slug_en, status, updated_at) VALUES ('Erster', 'hallo-welt', 'hello-world', 'published', '2026-01-01 00:00:00')");

    T::check($provider->supportsSlugParking(), 'Slug-Freigabe ist mit den Spalten trash_slug/trash_slug_en aktiv');
    T::check($provider->trash(1, 7)->ok, 'Beitrag in den Papierkorb verschoben');
    $row = referenceTestPost($db, 1);
    T::same('trash', $row['status'], 'Status ist trash');
    T::same('published', $row['previous_status'], 'vorheriger Status gemerkt');
    T::same(TrashSlug::parked(1), $row['slug'], 'Slug ist geparkt');
    T::same(null, $row['slug_en'], 'englischer Slug ist freigegeben');
    T::same('hallo-welt', $row['trash_slug'], 'Original-Slug in trash_slug');
    T::same('hello-world', $row['trash_slug_en'], 'englischer Original-Slug in trash_slug_en');
    T::same('2026-01-01 00:00:00', $row['updated_at'], 'updated_at bleibt unverändert');

    $items = $provider->listTrashed(0, 20, 'hallo');
    T::same(1, count($items), 'Papierkorb-Suche findet den Eintrag über den Original-Slug');
    T::same('hallo-welt', $items[0]->meta['slug'] ?? null, 'Papierkorb-Liste zeigt den Original-Slug');
    T::same(1, $provider->countTrashed(), 'ein Eintrag im Papierkorb');

    // Ohne Konflikt: Original-Slugs und vorheriger Status kommen zurück.
    $restored = $provider->restore(1, 7);
    T::check($restored->ok, 'Wiederherstellen ohne Konflikt');
    T::same(false, $restored->data['slug_changed'] ?? null, 'kein Slug-Wechsel gemeldet');
    $row = referenceTestPost($db, 1);
    T::same(['published', 'hallo-welt', 'hello-world', null, null, null], [
        $row['status'], $row['slug'], $row['slug_en'], $row['trash_slug'], $row['trash_slug_en'], $row['trashed_at'],
    ], 'Status, Slugs und Papierkorb-Spalten sind zurückgesetzt');
});

T::run('Papierkorb: freigegebener Slug ist neu vergebbar, Wiederherstellen weicht bei Konflikt aus', static function (): void {
    $db = referenceTestTrashDatabase();
    $provider = referenceTestTrashProvider($db);
    $db->execute("INSERT INTO cms_posts (title, slug, slug_en, status) VALUES ('Alt', 'angebot', 'offer', 'draft')");
    T::check($provider->trash(1, 1)->ok, 'Beitrag in den Papierkorb verschoben');

    // Der Slug ist frei: ein neuer Inhalt darf ihn belegen (UNIQUE auf slug).
    T::same(0, (int) $db->get_var("SELECT COUNT(*) FROM cms_posts WHERE slug = 'angebot'"), 'Slug ist nach dem Verschieben nicht mehr belegt');
    $db->execute("INSERT INTO cms_posts (title, slug, slug_en, status) VALUES ('Neu', 'angebot', 'offer', 'published')");

    $restored = $provider->restore(1, 1);
    T::check($restored->ok, 'Wiederherstellen trotz Slug-Konflikt');
    T::same(true, $restored->data['slug_changed'] ?? null, 'Slug-Wechsel wird gemeldet');
    $row = referenceTestPost($db, 1);
    T::same(['draft', 'angebot-2', 'offer-2'], [$row['status'], $row['slug'], $row['slug_en']], 'erster freier Kandidat für beide Slugs');
    T::same('angebot', referenceTestPost($db, 2)['slug'], 'der neue Inhalt behält seinen Slug');

    // Zwei Papierkorb-Einträge parken kollisionsfrei.
    T::check($provider->trash(1, 1)->ok && $provider->trash(2, 1)->ok, 'beide Beiträge liegen im Papierkorb');
    T::same([TrashSlug::parked(1), TrashSlug::parked(2)], [referenceTestPost($db, 1)['slug'], referenceTestPost($db, 2)['slug']], 'Platzhalter sind je Eintrag eindeutig');
});

T::run('Papierkorb: endgültiges Löschen und Einträge aus der Zeit vor der Slug-Freigabe', static function (): void {
    $db = referenceTestTrashDatabase();
    $provider = referenceTestTrashProvider($db);
    $db->execute("INSERT INTO cms_posts (title, slug, status) VALUES ('Weg', 'weg', 'published')");
    T::same('invalid_state', $provider->deletePermanently(1, 1)->code, 'endgültiges Löschen nur aus dem Papierkorb');
    $provider->trash(1, 1);
    T::check($provider->deletePermanently(1, 1)->ok, 'Eintrag endgültig gelöscht');
    T::same([], referenceTestPost($db, 1), 'Zeile ist entfernt');

    // Bestand: vor dem Update in den Papierkorb gelegt, Slug nicht geparkt.
    $db->execute("INSERT INTO cms_posts (title, slug, slug_en, status, previous_status) VALUES ('Bestand', 'bestand', 'legacy', 'trash', 'private')");
    $legacyId = (int) $db->getPdo()->lastInsertId();
    T::same('bestand', $provider->listTrashed(0, 20)[0]->meta['slug'] ?? null, 'Liste fällt auf slug zurück');
    $restored = $provider->restore($legacyId, 1);
    T::check($restored->ok && ($restored->data['slug_changed'] ?? null) === false, 'Bestandseintrag wird ohne Slug-Wechsel wiederhergestellt');
    $row = referenceTestPost($db, $legacyId);
    T::same(['private', 'bestand', 'legacy'], [$row['status'], $row['slug'], $row['slug_en']], 'Status und Slugs des Bestandseintrags bleiben');

    // Ohne die Spalten (Laufzeitprüfung noch nicht gelaufen) bleibt alles lauffähig, der Slug bleibt belegt.
    $oldDb = referenceTestTrashDatabase(false);
    $oldProvider = referenceTestTrashProvider($oldDb);
    $oldDb->execute("INSERT INTO cms_posts (title, slug, status) VALUES ('Ohne', 'ohne', 'draft')");
    T::check(!$oldProvider->supportsSlugParking() && $oldProvider->trash(1, 1)->ok, 'ohne Spalten: Verschieben funktioniert');
    T::same('ohne', referenceTestPost($oldDb, 1)['slug'], 'ohne Spalten: Slug bleibt stehen');
    T::check($oldProvider->restore(1, 1)->ok, 'ohne Spalten: Wiederherstellen funktioniert');
});

T::run('Verweise: Funktionsbereiche, Hooks, Dokumente und Icons zeigen auf vorhandene Ziele', static function (): void {
    $features = (new ReflectionClassConstant(Registry::class, 'FEATURES'))->getValue();
    T::check(count($features) >= 10, 'FeatureHooks-Registry führt Funktionsbereiche');
    foreach ($features as $class) {
        T::check(class_exists($class) && method_exists($class, 'register'), 'Funktionsbereich vorhanden: ' . $class);
    }

    // Jeder Hook, auf den die Suche hört, wird im eigenen Code auch ausgelöst.
    $searchSource = referenceTestSource('CMS/core/Services/SearchService.php');
    preg_match_all("/Hooks::addAction\\('([a-z_]+)'/", $searchSource, $matches);
    $firedSources = '';
    foreach (['CMS/admin/modules/pages/PagesModule.php', 'CMS/admin/modules/posts/PostsModule.php', 'CMS/core/Services/Trash/PageTrashProvider.php',
        'CMS/core/Services/Trash/PostTrashProvider.php', 'CMS/core/Bootstrap.php'] as $file) {
        $firedSources .= referenceTestSource($file);
    }
    foreach (array_unique($matches[1]) as $hook) {
        T::check(str_contains($firedSources, "'" . $hook . "'"), 'SearchService hört auf ausgelösten Hook: ' . $hook);
    }

    // Hervorgehobene Dokumente der Admin-Dokumentation existieren in exakter Schreibweise.
    $catalogSource = referenceTestSource('CMS/admin/modules/system/DocumentationCatalog.php');
    T::check(preg_match('/function getFeaturedDocuments.*?\$paths = \[(.*?)\];/s', $catalogSource, $pathsMatch) === 1, 'Liste der hervorgehobenen Dokumente gefunden');
    preg_match_all("/'([^']+)'/", $pathsMatch[1] ?? '', $pathMatches);
    foreach ($pathMatches[1] as $path) {
        $directory = dirname(referenceTestRoot() . '/DOC/' . $path);
        T::check(in_array(basename($path), is_dir($directory) ? (scandir($directory) ?: []) : [], true), 'Dokument vorhanden: DOC/' . $path);
    }

    // Leerzustand: jeder verwendete Icon-Schlüssel steht in der Icon-Tabelle.
    preg_match('/\$iconMap = \[(.*?)\n\];/s', referenceTestSource('CMS/admin/views/partials/empty-table-row.php'), $mapMatch);
    preg_match_all("/^\\s*'([a-z-]+)' =>/m", $mapMatch[1] ?? '', $iconKeys);
    $usedIcons = [];
    $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(referenceTestRoot() . '/CMS/admin/views', FilesystemIterator::SKIP_DOTS));
    foreach ($views as $view) {
        if ($view->getExtension() === 'php' && preg_match_all("/\\\$emptyStateIcon\\s*=\\s*'([^']*)'/", (string) file_get_contents($view->getPathname()), $used)) {
            $usedIcons = array_merge($usedIcons, $used[1]);
        }
    }
    T::check($usedIcons !== [], 'Views setzen Icon-Schlüssel für den Leerzustand');
    T::same([], array_values(array_diff(array_unique($usedIcons), $iconKeys[1])), 'alle Icon-Schlüssel sind in empty-table-row.php definiert');

    // Topbar „Hochladen“: der Parameter wird in der Medienbibliothek ausgewertet.
    T::check(
        !str_contains(referenceTestSource('CMS/admin/partials/topbar.php'), '/admin/media?action=upload')
            || preg_match("/params\\.get\\('action'\\) !== 'upload'/", referenceTestSource('CMS/assets/js/admin-media-integrations.js')) === 1,
        'admin-media-integrations.js wertet ?action=upload aus'
    );
    T::check(str_contains(referenceTestSource('CMS/admin/views/media/library.php'), 'id="uploadModal"'), 'Upload-Dialog #uploadModal ist vorhanden');
});

T::run('Verweise: Editor-Blocklisten decken die registrierten Tools ab', static function (): void {
    preg_match('/var BLOCK_TOOL_NAMES = \[(.*?)\];/s', referenceTestSource('CMS/assets/js/editor-init.js'), $toolMatch);
    preg_match_all("/'([A-Za-z]+)'/", $toolMatch[1] ?? '', $tools);
    $registered = array_merge($tools[1], ['download', 'form']);

    preg_match('/function getEditorBlockGroups\(\) \{(.*?)\n        \}/s', referenceTestSource('CMS/assets/js/admin-content-editor.js'), $groupMatch);
    preg_match_all("/block: '([A-Za-z]+)'/", $groupMatch[1] ?? '', $listed);
    T::same([], array_values(array_diff(array_unique($listed[1]), $registered)), 'Blockliste des Inhaltseditors nennt nur registrierte Tools');
    T::same([], array_values(array_diff($registered, $listed[1])), 'jedes registrierte Tool steht in der Blockliste des Inhaltseditors');

    $assetSource = referenceTestSource('CMS/core/Services/EditorJs/EditorJsAssetService.php');
    preg_match_all('/data-block="([A-Za-z]+)"/', $assetSource, $fixed);
    T::same([], array_values(array_diff($tools[1], $fixed[1])), 'feste Schnellleiste enthält alle fest eingebauten Tools');
    T::check(str_contains($assetSource, 'EditorJsBlockRegistry::types()'), 'feste Schnellleiste gibt die Blöcke der EditorJsBlockRegistry aus');

    // Registry nach der Probe zurücksetzen, damit andere Prüfungen nur die echten Blöcke sehen.
    $blocks = new ReflectionProperty(EditorJsBlockRegistry::class, 'blocks');
    $registeredBlocks = $blocks->getValue();
    EditorJsBlockRegistry::register('referenceProbe', 'Probe', '/js/editor-blocks/probe.js', static fn (array $data): array => $data, static fn (): string => '');
    T::same('js/editor-blocks/probe.js', EditorJsBlockRegistry::script('referenceProbe'), 'Registry liefert das Tool-Skript eines Blocks');
    T::same('', EditorJsBlockRegistry::script('unbekannt'), 'unbekannter Block hat kein Tool-Skript');
    $blocks->setValue(null, $registeredBlocks);
});

T::run('Konfiguration: Manifeste und .gitignore sind in sich stimmig', static function (): void {
    $root = referenceTestRoot();
    $read = static fn (string $path): array => (array) json_decode((string) file_get_contents($root . '/' . $path), true, 512, JSON_THROW_ON_ERROR);

    $core = $read('CMS/update.json');
    T::same($core, $read('CMS/marketplace/core/365cms/update.json'), 'Core-Manifest und Marketplace-Kopie sind inhaltsgleich');
    $manifests = ['CMS/update.json' => $core];
    if (is_file($root . '/RELEASE/365CMS-' . $core['version'] . '-update.json')) {
        $manifests['RELEASE'] = $read('RELEASE/365CMS-' . $core['version'] . '-update.json');
        T::same($core['notes'], $manifests['RELEASE']['notes'], 'Release-Manifest trägt denselben Hinweistext');
        T::same($core['checksum_sha256'], $manifests['RELEASE']['checksum_sha256'], 'Release-Manifest trägt dieselbe Prüfsumme');
    }
    foreach (array_keys(IntegratedPluginService::PLUGINS) as $plugin) {
        T::check(preg_match('/\b' . preg_quote($plugin, '/') . '\b/', (string) $core['notes']) === 1, 'Hinweistext nennt übernommenes Plugin ' . $plugin);
    }

    $theme = $read('CMS/themes/cms-default/update.json');
    $version = (string) $theme['version'];
    $catalog = $read('CMS/marketplace/themes/index.json')['themes'][0] ?? [];
    T::same($version, $read('CMS/themes/cms-default/theme.json')['version'] ?? null, 'theme.json trägt die Theme-Version');
    T::same($version, $catalog['version'] ?? null, 'Theme-Katalog trägt die Theme-Version');
    T::same($theme['min_cms_version'], $catalog['requires_cms'] ?? null, 'Theme-Katalog trägt dieselbe Mindest-CMS-Version');
    T::check(str_contains(referenceTestSource('CMS/themes/cms-default/style.css'), 'Version: ' . $version), 'style.css trägt die Theme-Version');
    T::check(
        str_contains(referenceTestSource('CMS/themes/cms-default/functions.php'), "define('MERIDIAN_THEME_VERSION', '" . $version . "')"),
        'MERIDIAN_THEME_VERSION trägt die Theme-Version'
    );
    T::check(isset($theme['changelog'][$version]), 'Theme-Changelog beschreibt die aktuelle Version');
    // Die Kontaktseite braucht das Formular des Core (ab 4.0.0).
    T::check(version_compare((string) $theme['min_cms_version'], '4.0.0', '>='), 'Theme setzt mindestens 365CMS 4.0.0 voraus');

    // Regeln für /tests träfen ohne Groß-/Kleinschreibung auch TESTS/ (Windows, macOS).
    $ignoreLines = array_map('trim', explode("\n", referenceTestSource('.gitignore')));
    $testRules = array_values(array_filter($ignoreLines, static fn (string $line): bool => preg_match('#^/?tests(/\*?)?$#i', $line) === 1));
    T::same([], $testRules, '.gitignore ignoriert TESTS/ in keiner Schreibweise');
    T::check(!in_array('.github/workflows/', $ignoreLines, true), '.gitignore ignoriert .github/workflows/ nicht');
    T::check(is_file($root . '/.github/SECURITY.md'), 'Sicherheitsrichtlinie liegt als .github/SECURITY.md vor');
});
