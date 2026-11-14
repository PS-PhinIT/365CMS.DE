<?php
/**
 * Dokumentationsverweise (Prüfbereich „Fehlende oder falsche Verweise“): Datei-Inventare, Tabellenzahl,
 * Veröffentlichungsdatum, PHP-Mindestversion und relative Markdown-Links der Übersichtsdokumente gegen den
 * tatsächlichen Dateibestand. Ohne Datenbank.
 * Wird von TESTS/functions/run.php eingebunden.
 */

declare(strict_types=1);

/** Liest eine Datei relativ zum Repository-Wurzelverzeichnis. */
function docRefRead(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
}

/**
 * Namen der direkten Einträge eines Verzeichnisses (ohne Punkt-Einträge).
 *
 * @return list<string>
 */
function docRefEntries(string $relative, bool $directories, string $suffix = ''): array
{
    $base = dirname(__DIR__, 2) . '/' . $relative;
    $names = [];
    foreach (scandir($base) ?: [] as $name) {
        if ($name[0] === '.' || is_dir($base . '/' . $name) !== $directories) {
            continue;
        }
        if ($suffix === '' || str_ends_with($name, $suffix)) {
            $names[] = $name;
        }
    }

    return $names;
}

/** Prüft, dass ein Pfad existiert und jedes Segment exakt so geschrieben ist (auch auf Dateisystemen ohne Groß-/Kleinschreibung). */
function docRefPathExists(string $absolute): bool
{
    $current = '';
    foreach (explode('/', trim($absolute, '/')) as $segment) {
        if (!in_array($segment, scandir($current === '' ? '/' : $current) ?: [], true)) {
            return false;
        }
        $current .= '/' . $segment;
    }

    return true;
}

T::run('Doku: Tabellenzahl, Veröffentlichungsdatum und PHP-Mindestversion', static function (): void {
    $tables = count(\CMS\SchemaManager::getSchemaQueries('cms_', 'utf8mb4'));
    $schemaDoc = docRefRead('DOC/core/DATABASE-SCHEMA.md');
    T::check(
        str_contains($schemaDoc, 'The base schema (' . $tables . ' tables)'),
        'DATABASE-SCHEMA.md nennt die Tabellenzahl aus SchemaManager::getSchemaQueries()',
        'erwartet: ' . $tables
    );
    $v25 = count(\CMS\SchemaManager::FEATURE_TABLES);
    T::check(
        str_contains($schemaDoc, 'the ' . $v25 . ' feature tables'),
        'DATABASE-SCHEMA.md nennt die Zahl der FEATURE_TABLES',
        'erwartet: ' . $v25
    );

    $manifest = json_decode(docRefRead('CMS/update.json'), true);
    $released = (string) ($manifest['released'] ?? '');
    $minPhp = (string) ($manifest['min_php'] ?? '');
    T::check($released !== '' && $minPhp !== '', 'CMS/update.json enthält released und min_php');

    // README.md: Datum mit oder ohne Code-Formatierung (`released `YYYY-MM-DD``), geprüft wird der Wert.
    $readme = docRefRead('README.md');
    T::check(
        str_contains($readme, 'released ' . $released) || str_contains($readme, 'released `' . $released . '`'),
        'README.md nennt das Veröffentlichungsdatum aus update.json',
        'released ' . $released
    );

    foreach (['DOC/README.md' => "RELEASE_DATE = '" . $released . "'",
        'DOC/core/STATUS.md' => 'Release `' . $released . '`', 'DOC/core/CORE-CLASSES.md' => "RELEASE_DATE = '" . $released . "'",
        'DOC/admin/system-settings/UPDATES.md' => '"released": "' . $released . '"'] as $file => $needle) {
        T::check(str_contains(docRefRead($file), $needle), $file . ' nennt das Veröffentlichungsdatum aus update.json', $needle);
    }

    foreach (['README.md' => 'PHP `' . $minPhp . '+`', 'DOC/README.md' => 'PHP ' . $minPhp . '+',
        'DOC/core/ARCHITECTURE.md' => 'PHP ≥ ' . $minPhp . ' (`CMS_MIN_PHP_VERSION`)',
        'DOC/workflow/UPDATE-DEPLOYMENT-WORKFLOW.md' => 'PHP **' . $minPhp . '+**'] as $file => $needle) {
        T::check(str_contains(docRefRead($file), $needle), $file . ' nennt die PHP-Mindestversion aus update.json', $needle);
    }
});

T::run('Doku: Datei-Inventare nennen die vorhandenen Module, Services, Stylesheets und Skripte', static function (): void {
    $inventory = docRefRead('DOC/CMSFILESTRUCTUR.md');
    $fileList = docRefRead('DOC/FILELIST.md');
    $adminTree = docRefRead('DOC/admin/FILESTRUCTURE.md');
    $services = docRefRead('DOC/core/SERVICES.md');

    foreach (docRefEntries('CMS/admin/modules', true) as $module) {
        T::check(str_contains($inventory, '`' . $module . '`'), 'CMSFILESTRUCTUR.md nennt das Admin-Modul ' . $module);
        T::check(str_contains($fileList, '`' . $module . '`'), 'FILELIST.md nennt das Admin-Modul ' . $module);
        T::check(str_contains($adminTree, ' ' . $module . '/'), 'admin/FILESTRUCTURE.md nennt das Admin-Modul ' . $module);
    }

    foreach (docRefEntries('CMS/core/Services', true) as $namespace) {
        T::check(str_contains($inventory, '`' . $namespace . '/`'), 'CMSFILESTRUCTUR.md nennt den Service-Ordner ' . $namespace);
        T::check(str_contains($services, '`' . $namespace . '\\`'), 'SERVICES.md nennt den Namespace ' . $namespace);
    }
    foreach (docRefEntries('CMS/core/Services', false, '.php') as $file) {
        $class = substr($file, 0, -4);
        T::check(str_contains($inventory, '`' . $class . '`'), 'CMSFILESTRUCTUR.md nennt den Service ' . $class);
        T::check(str_contains($services, '`' . $class), 'SERVICES.md nennt den Service ' . $class);
    }

    $styles = docRefEntries('CMS/assets/css', false, '.css');
    $scripts = docRefEntries('CMS/assets/js', false, '.js');
    foreach (array_merge($styles, $scripts) as $asset) {
        T::check(str_contains($inventory, '- `' . $asset . '`'), 'CMSFILESTRUCTUR.md nennt ' . $asset);
    }
    $modules = 0;
    foreach (docRefEntries('CMS/assets/js', true) as $folder) {
        T::check(str_contains($inventory, '- `' . $folder . '/`:'), 'CMSFILESTRUCTUR.md nennt den ES-Modul-Ordner ' . $folder);
        $modules += count(docRefEntries('CMS/assets/js/' . $folder, false, '.js'));
    }
    T::check(
        str_contains($inventory, '| ' . count($styles) . ' stylesheets |')
            && str_contains($inventory, '| ' . count($scripts) . ' scripts + ' . $modules . ' ES modules'),
        'CMSFILESTRUCTUR.md nennt die Zahl der Stylesheets, Skripte und ES-Module',
        count($styles) . ' / ' . count($scripts) . ' + ' . $modules
    );

    // Im Inventar genannte Wurzeldateien und Stylesheets/Skripte müssen vorhanden sein.
    if (preg_match('/Verified files in `CMS\/`:\n\n((?:- `[^`]+`\n)+)/', $inventory, $match) === 1) {
        preg_match_all('/- `([^`]+)`/', $match[1], $rootFiles);
        foreach ($rootFiles[1] as $rootFile) {
            T::check(is_file(ABSPATH . $rootFile), 'CMSFILESTRUCTUR.md: Wurzeldatei ' . $rootFile . ' ist vorhanden');
        }
    } else {
        T::check(false, 'CMSFILESTRUCTUR.md enthält die Liste der Wurzeldateien');
    }
    T::check(!str_contains($inventory, 'CMS/db/') && !str_contains($fileList, 'CMS/db/'), 'Inventare nennen kein Verzeichnis CMS/db/');

    foreach (docRefEntries('TESTS', true) as $suite) {
        if (!is_file(dirname(__DIR__) . '/' . $suite . '/run.php')) {
            continue;
        }
        T::check(str_contains($fileList, '`TESTS/' . $suite . '/`'), 'FILELIST.md nennt die Test-Suite ' . $suite);
        T::check(str_contains(docRefRead('DOC/FILESTRUCTUR.md'), '`TESTS/' . $suite . '/`'), 'FILESTRUCTUR.md nennt die Test-Suite ' . $suite);
    }
});

T::run('Doku: relative Markdown-Links der Übersichtsdokumente', static function (): void {
    $root = dirname(__DIR__, 2);
    $files = ['README.md', 'DOC/README.md', 'DOC/INSTALLATION.md', 'DOC/FILELIST.md', 'DOC/FILESTRUCTUR.md', 'DOC/CMSFILESTRUCTUR.md',
        'DOC/admin/FILESTRUCTURE.md', 'DOC/core/SERVICES.md', 'DOC/core/DATABASE-SCHEMA.md', 'DOC/core/STATUS.md',
        'DOC/core/CORE-CLASSES.md', 'DOC/core/ARCHITECTURE.md', 'DOC/assets/README.md', 'DOC/assets/ASSET.md'];
    $checked = 0;
    foreach ($files as $file) {
        // Codeblöcke und Code-Spans enthalten keine Links.
        $text = (string) preg_replace('/```.*?```|`[^`\n]*`/s', '', docRefRead($file));
        preg_match_all('/\]\(([^)\s]+)\)/', $text, $links);
        foreach ($links[1] as $target) {
            if (preg_match('/^(?:[a-z][a-z0-9+.-]*:|#|\/\/)/i', $target) === 1) {
                continue;
            }
            $path = rawurldecode((string) preg_replace('/[#?].*$/', '', $target));
            $absolute = str_starts_with($path, '/') ? $root . $path : $root . '/' . dirname($file) . '/' . $path;
            $resolved = [];
            foreach (explode('/', $absolute) as $segment) {
                if ($segment === '..') {
                    array_pop($resolved);
                } elseif ($segment !== '' && $segment !== '.') {
                    $resolved[] = $segment;
                }
            }
            $checked++;
            T::check(docRefPathExists('/' . implode('/', $resolved)), $file . ': Linkziel ' . $target . ' ist vorhanden');
        }
    }
    T::check($checked > 50, 'Links wurden gefunden', (string) $checked);
});
