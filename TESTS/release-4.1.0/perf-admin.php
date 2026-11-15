<?php
/**
 * Release-Prüfung 4.1.0, Performance-Befunde im Admin (db:0, db:1, db:2, db:6).
 * Wird von TESTS/release-4.1.0/run.php eingebunden ($src, ABSPATH und Autoloader sind dort definiert).
 */

declare(strict_types=1);

T::run('PERF db:0: Menü-Editor schreibt den Spiegel nur bei Abweichung neu', static function () use ($src, $root): void {
    require_once $root . 'admin/modules/menus/MenuEditorModule.php';

    $theme = [
        ['label' => ' Start ', 'url' => '/', 'target' => '_self'],
        ['label' => 'Leistungen', 'url' => '#', 'children' => [
            ['label' => 'Beratung', 'url' => '/beratung', 'target' => '_blank', 'icon' => ' fa-user '],
            ['title' => '', 'url' => '/leer'],
            'kein-array',
            ['title' => 'Support', 'url' => '/support', 'target' => 'parent'],
        ]],
        ['label' => '', 'url' => '/ohne-titel', 'children' => [['label' => 'Kind ohne Eltern', 'url' => '/x']]],
        ['label' => 'Kontakt'],
    ];

    $flat = MenuEditorModule::flattenThemeItems($theme);
    T::same([
        ['parent' => -1, 'title' => 'Start', 'url' => '/', 'target' => '_self', 'icon' => ''],
        ['parent' => -1, 'title' => 'Leistungen', 'url' => '#', 'target' => '_self', 'icon' => ''],
        ['parent' => 1, 'title' => 'Beratung', 'url' => '/beratung', 'target' => '_blank', 'icon' => 'fa-user'],
        ['parent' => 1, 'title' => 'Support', 'url' => '/support', 'target' => '_self', 'icon' => ''],
        ['parent' => -1, 'title' => 'Kontakt', 'url' => '#', 'target' => '_self', 'icon' => ''],
    ], $flat, 'Sollliste folgt den Regeln von insertThemeItemsRecursive() (leere Titel samt Kindern übersprungen)');
    T::same([], MenuEditorModule::flattenThemeItems([]), 'leeres Theme-Menü ergibt leere Sollliste');

    // So legt insertThemeItemsRecursive() die Zeilen an (IDs beliebig, Positionen fortlaufend).
    $rows = [
        (object) ['id' => '41', 'parent_id' => '0', 'title' => 'Start', 'url' => '/', 'target' => '_self', 'icon' => '', 'position' => '0'],
        (object) ['id' => '42', 'parent_id' => '0', 'title' => 'Leistungen', 'url' => '#', 'target' => '_self', 'icon' => null, 'position' => '1'],
        (object) ['id' => '43', 'parent_id' => '42', 'title' => 'Beratung', 'url' => '/beratung', 'target' => '_blank', 'icon' => 'fa-user', 'position' => '2'],
        (object) ['id' => '44', 'parent_id' => '42', 'title' => 'Support', 'url' => '/support', 'target' => '_self', 'icon' => '', 'position' => '3'],
        (object) ['id' => '45', 'parent_id' => '0', 'title' => 'Kontakt', 'url' => '#', 'target' => '_self', 'icon' => '', 'position' => '4'],
    ];
    T::check(MenuEditorModule::mirrorMatchesThemeItems($flat, $rows), 'unveränderter Spiegel: kein Neuaufbau');
    T::check(MenuEditorModule::mirrorMatchesThemeItems([], []), 'leeres Menü und leerer Spiegel: kein Neuaufbau');

    $changed = static function (callable $mutate) use ($rows): array {
        $copy = array_map(static fn (object $row): object => clone $row, $rows);
        $mutate($copy);

        return $copy;
    };
    T::check(!MenuEditorModule::mirrorMatchesThemeItems($flat, array_slice($rows, 0, 4)), 'fehlender Eintrag erzwingt Neuaufbau');
    T::check(!MenuEditorModule::mirrorMatchesThemeItems($flat, $changed(static function (array $r): void { $r[2]->title = 'Beratung neu'; })), 'geänderter Titel erzwingt Neuaufbau');
    T::check(!MenuEditorModule::mirrorMatchesThemeItems($flat, $changed(static function (array $r): void { $r[3]->url = '/hilfe'; })), 'geänderte URL erzwingt Neuaufbau');
    T::check(!MenuEditorModule::mirrorMatchesThemeItems($flat, $changed(static function (array $r): void { $r[0]->target = '_blank'; })), 'geändertes Ziel erzwingt Neuaufbau');
    T::check(!MenuEditorModule::mirrorMatchesThemeItems($flat, $changed(static function (array $r): void { $r[4]->icon = 'fa-mail'; })), 'geändertes Icon erzwingt Neuaufbau');
    T::check(!MenuEditorModule::mirrorMatchesThemeItems($flat, $changed(static function (array $r): void { $r[3]->parent_id = '0'; })), 'geänderte Verschachtelung erzwingt Neuaufbau');
    T::check(!MenuEditorModule::mirrorMatchesThemeItems($flat, $changed(static function (array $r): void { $r[3]->parent_id = '999'; })), 'Elternverweis außerhalb des Menüs erzwingt Neuaufbau');
    T::check(!MenuEditorModule::mirrorMatchesThemeItems($flat, $changed(static function (array $r): void { $r[4]->position = '7'; })), 'Lücke in den Positionen erzwingt Neuaufbau');

    $module = $src('admin/modules/menus/MenuEditorModule.php');
    $sync = substr($module, (int) strpos($module, 'private function syncMenuItemsFromThemeSettings('), 2500);
    $matchPos = strpos($sync, 'self::mirrorMatchesThemeItems(self::flattenThemeItems($items), $existingRows)');
    $beginPos = strpos($sync, '$pdo->beginTransaction();');
    $deletePos = strpos($sync, "DELETE FROM {\$this->prefix}menu_items WHERE menu_id = ?");
    $commitPos = strpos($sync, '$pdo->commit();');
    T::check($matchPos !== false && $beginPos !== false && $deletePos !== false && $commitPos !== false
        && $matchPos < $beginPos && $beginPos < $deletePos && $deletePos < $commitPos, 'Vergleich vor dem Schreiben, Neuaufbau in einer Transaktion');
    T::check(str_contains($sync, '$pdo->rollBack();'), 'fehlgeschlagener Neuaufbau wird zurückgerollt');
    T::check(str_contains($module, "\$this->db->runSchemaOnce('menu_editor', __FILE__, fn (): bool => \$this->ensureTables());"), 'CREATE TABLE über runSchemaOnce()');
});

T::run('PERF db:1: Beitrags- und Seitenmodul ohne Schemaprüfung je Anfrage', static function () use ($src): void {
    $posts = $src('admin/modules/posts/PostsModule.php');
    $constructor = substr($posts, (int) strpos($posts, 'public function __construct()'), 1200);
    T::check(str_contains($constructor, "\$this->db->runSchemaOnce('posts_module_schema', __FILE__, function (): bool {"), 'PostsModule: ensure*-Schritte über runSchemaOnce()');
    foreach (['ensureColumns', 'ensureCategoryColumns', 'ensureTagColumns', 'ensurePostCategoryRelationTable', 'ensurePostRevisionTable'] as $step) {
        T::check(str_contains($constructor, "\$this->{$step}()") && str_contains($posts, "private function {$step}(): bool"), "PostsModule: {$step}() meldet Erfolg an runSchemaOnce()");
    }
    T::check(substr_count($constructor, '$this->ensureDefaultCategories();') === 1
        && strpos($constructor, '$this->ensureDefaultCategories();') > strpos($constructor, '});'), 'PostsModule: Standardkategorien werden weiter je Anfrage geprüft');

    $pages = $src('admin/modules/pages/PagesModule.php');
    T::check(str_contains($pages, "\$this->db->runSchemaOnce('pages_module_schema', __FILE__, fn (): bool => \$this->ensureCategoryColumns());"), 'PagesModule: SHOW COLUMNS über runSchemaOnce()');
    T::check(str_contains($pages, 'private function ensureCategoryColumns(): bool'), 'PagesModule: ensureCategoryColumns() meldet Erfolg');

    foreach (['PostsModule' => $posts, 'PagesModule' => $pages] as $name => $source) {
        T::check(!str_contains($source, 'FROM {$this->prefix}post_categories WHERE slug = ? LIMIT 1') && !str_contains($source, 'getDefaultCategoryRow('), "{$name}: keine Slug-Abfrage je Standardkategorie mehr");
        T::check(str_contains($source, 'FROM {$this->prefix}post_categories WHERE slug IN ('), "{$name}: alle Standard-Slugs in einer Abfrage");
        T::check(substr_count($source, '$this->getDefaultCategoryRows(') === 1, "{$name}: Standardkategorien einmal je Anfrage geladen");
        T::check(str_contains($source, "return strtolower(rtrim(\$slug, ' '));"), "{$name}: Slug-Vergleich wie die Kollation (ohne Groß/Klein)");
    }
});

T::run('PERF db:2: Profilfeld-Seite lädt die Einstellungen nicht je Feld neu', static function () use ($src): void {
    $module = $src('admin/modules/member/MemberDashboardModule.php');
    $normalize = substr($module, (int) strpos($module, 'private function normalizeProfileFieldKey('), 900);
    T::check(str_contains($normalize, 'if ($this->validProfileFieldKeys === null) {'), 'gültige Schlüssel werden je Anfrage einmal ermittelt');
    T::check(substr_count($normalize, '$this->loadSettingsMap()') === 1 && strpos($normalize, '$this->loadSettingsMap()') > strpos($normalize, '=== null'), 'loadSettingsMap() nur beim ersten Aufruf');
    $upsert = substr($module, (int) strpos($module, 'private function upsertSetting('), 400);
    T::check(str_contains($upsert, '$this->validProfileFieldKeys = null;'), 'Schreiben einer Einstellung verwirft den Zwischenspeicher');

    $compat = substr($module, (int) strpos($module, 'private function buildProfileFieldCompatibility('), 3500);
    T::check(str_contains($compat, '$missingCounts = $this->getProfileFieldMissingCounts('), 'Fehlzahlen aller Felder in einer Abfrage');
    T::check(str_contains($compat, "\$this->getProfileFieldMissingSummary((string)\$fieldKey, \$missingCounts[(string)\$fieldKey] ?? null)"), 'Rückfall auf die Einzelzählung bei einem Fehler');
    $counts = substr($module, (int) strpos($module, 'private function getProfileFieldMissingCounts('), 2500);
    T::check(str_contains($counts, "FROM {\$this->prefix}users u WHERE u.status = 'active'") && str_contains($counts, "AND TRIM(um.meta_value) <> ''"), 'gleiche Bedeutung wie die Einzelzählung (aktive Benutzer ohne nicht leeren Wert)');
});

T::run('PERF db:6: Kategorienliste zählt Beiträge vorab gruppiert', static function () use ($src): void {
    $posts = $src('admin/modules/posts/PostsModule.php');
    $method = substr($posts, (int) strpos($posts, 'public function getCategoryAdminData()'), 2500);
    T::check(!str_contains($method, 'WHERE p2.category_id = c.id OR pcr2.category_id = c.id'), 'keine korrelierte Unterabfrage mit OR je Kategorie');
    T::check(!str_contains($method, 'LEFT JOIN {$this->prefix}posts p ON p.category_id = c.id'), 'kein überflüssiger Join auf posts mit GROUP BY');
    T::check(str_contains($method, 'COUNT(DISTINCT u.post_id) AS assigned_post_count') && str_contains($method, 'UNION') && str_contains($method, 'GROUP BY u.category_id'), 'Zählung über die Vereinigung aus Hauptkategorie und Zuordnungstabelle');
    T::check(str_contains($method, 'INNER JOIN {$this->prefix}posts p3 ON p3.id = pcr.post_id'), 'Zuordnungen zählen nur für vorhandene Beiträge (wie bisher)');
    T::check(str_contains($method, 'COALESCE(x.assigned_post_count, 0) AS assigned_post_count'), 'Kategorien ohne Beiträge zeigen 0');
});
