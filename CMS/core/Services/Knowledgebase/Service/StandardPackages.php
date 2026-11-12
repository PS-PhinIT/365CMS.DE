<?php

declare(strict_types=1);

namespace CMS\Services\Knowledgebase\Service;

use CMS\Services\Knowledgebase\Repository\EntryRepository;
use CMS\Services\Knowledgebase\Support\ContentSections;
use CMS\Services\Knowledgebase\Support\LoggerFactory;

if (!defined('ABSPATH')) {
    exit;
}

final class StandardPackages
{
    /**
     * Beispiel-CSV mit allen Feldern aus „Eintrag anlegen“. Endet bewusst nicht auf
     * `_Glossar.csv`, damit sie nicht als Standardpaket importiert wird.
     */
    public const TEMPLATE_FILE_NAME = 'Beispiel_Vorlage.csv';

    /**
     * CSV-Spaltenüberschrift (kompakt: klein, Umlaute ausgeschrieben, nur a-z0-9) => internes Feld.
     * Die internen Felder entsprechen den Formularfeldern aus „Eintrag anlegen“.
     */
    private const CSV_COLUMN_ALIASES = [
        // Basisdaten
        'eintrag' => 'title',
        'titel' => 'title',
        'title' => 'title',
        'begriff' => 'title',
        'term' => 'title',
        'fokusbegriff' => 'keyword',
        'keyword' => 'keyword',
        'suchbegriff' => 'keyword',
        'slug' => 'slug',
        'urlslug' => 'slug',
        'kategorie' => 'category',
        'category' => 'category',
        'prioritaet' => 'priority',
        'priority' => 'priority',
        'prio' => 'priority',
        'maxlinksproseite' => 'max_links_per_page',
        'maxlinksperpage' => 'max_links_per_page',
        'maxlinks' => 'max_links_per_page',
        // Kurzinhalte & Verlinkung
        'kurzbeschreibungexcerpt' => 'excerpt',
        'kurzbeschreibung' => 'excerpt',
        'excerpt' => 'excerpt',
        'shortdescription' => 'excerpt',
        'beschreibungkurz' => 'excerpt',
        'tooltiptext' => 'tooltip_text',
        'tooltip' => 'tooltip_text',
        'synonyme' => 'synonyms',
        'synonym' => 'synonyms',
        'synonyms' => 'synonyms',
        'keywords' => 'synonyms',
        'alias' => 'synonyms',
        'aliases' => 'synonyms',
        // Inhaltsbereiche
        'allgemeineinfos' => 'content',
        'langbeschreibung' => 'content',
        'beschreibung' => 'content',
        'longdescription' => 'content',
        'beschreibunglang' => 'content',
        'details' => 'content',
        'content' => 'content',
        'funktionsweisetechnischedetails' => 'content_details',
        'funktionsweisedetails' => 'content_details',
        'funktionsweise' => 'content_details',
        'technischedetails' => 'content_details',
        'contentdetails' => 'content_details',
        'vorteilenutzen' => 'content_benefits',
        'vorteile' => 'content_benefits',
        'nutzen' => 'content_benefits',
        'contentbenefits' => 'content_benefits',
        'praxisbeispieleinsatz' => 'content_practice',
        'praxisbeispiel' => 'content_practice',
        'contentpractice' => 'content_practice',
        'hinweisebestpractices' => 'content_notes',
        'hinweise' => 'content_notes',
        'bestpractices' => 'content_notes',
        'contentnotes' => 'content_notes',
        'lizenzverfuegbarkeit' => 'content_license',
        'lizenzinfo' => 'content_license',
        'licenseinfo' => 'content_license',
        'lizenz' => 'content_license',
        'contentlicense' => 'content_license',
        'fazit' => 'content_conclusion',
        'contentconclusion' => 'content_conclusion',
        'weiterfuehrendelinks' => 'content_links',
        'mslearnweblinks' => 'content_links',
        'weblinks' => 'content_links',
        'links' => 'content_links',
        'link' => 'content_links',
        'quellen' => 'content_links',
        'urls' => 'content_links',
        'url' => 'content_links',
        'contentlinks' => 'content_links',
        // Status & Verlinkung
        'aktiv' => 'is_active',
        'active' => 'is_active',
        'isactive' => 'is_active',
        'nurganzewoerterverlinken' => 'is_whole_word',
        'nurganzewoerter' => 'is_whole_word',
        'ganzewoerter' => 'is_whole_word',
        'wholeword' => 'is_whole_word',
        'iswholeword' => 'is_whole_word',
        'grosskleinschreibungbeachten' => 'is_case_sensitive',
        'grosskleinschreibung' => 'is_case_sensitive',
        'casesensitive' => 'is_case_sensitive',
        'iscasesensitive' => 'is_case_sensitive',
    ];

    private static ?self $instance = null;

    private EntryRepository $repository;

    private $logger;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->repository = EntryRepository::instance();
        $this->logger = LoggerFactory::create();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPackages(): array
    {
        $packages = [];

        foreach ($this->packageDefinitions() as $key => $package) {
            $entryTitles = array_map(
                static fn(array $entry): string => (string) ($entry['title'] ?? ''),
                $package['entries']
            );

            $packages[] = [
                'key' => $key,
                'label' => (string) $package['label'],
                'description' => (string) $package['description'],
                'accent' => (string) $package['accent'],
                'file_name' => (string) $package['file_name'],
                'entry_count' => count($package['entries']),
                'sample_terms' => array_values(array_filter(array_slice($entryTitles, 0, 4))),
            ];
        }

        return $packages;
    }

    /**
     * @return array<int, string>
     */
    public function getCsvFilenameWarnings(): array
    {
        $files = $this->listCsvFiles('csv');
        if ($files === []) {
            return [];
        }
        $warnings = [];

        foreach ($files as $filePath) {
            $fileName = basename($filePath);
            if (preg_match('/_Glossar\.csv$/i', $fileName) === 1) {
                continue;
            }

            if (stripos($fileName, 'Glossar') === false) {
                continue;
            }

            $warnings[] = sprintf(
                'Dateiname „%s“ wird ignoriert. Erwartet wird das Format „Kategorie_Glossar.csv“, z. B. „Cloud_Security_Glossar.csv“.',
                $fileName
            );
        }

        return $warnings;
    }

    /**
     * @return array{success: bool, message?: string, error?: string}
     */
    public function importPackage(string $packageKey): array
    {
        $definitions = $this->packageDefinitions();
        if (!isset($definitions[$packageKey])) {
            return ['success' => false, 'error' => 'Unbekanntes Standardpaket.'];
        }

        $package = $definitions[$packageKey];
        if ($package['entries'] === []) {
            return [
                'success' => false,
                'error' => sprintf('Die CSV-Datei „%s“ enthält aktuell keine importierbaren Einträge.', (string) $package['file_name']),
            ];
        }

        $result = $this->repository->importPresetEntries($this->buildEntries($package), $packageKey);

        $this->logger->info('Knowledgebase-CSV-Paket importiert.', [
            'package' => $packageKey,
            'file_name' => (string) $package['file_name'],
            'created' => (int) ($result['created'] ?? 0),
            'updated' => (int) ($result['updated'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'errors' => (int) ($result['errors'] ?? 0),
        ]);

        $this->repository->saveCsvImportStatus(
            (int) ($result['created'] ?? 0) + (int) ($result['updated'] ?? 0),
            (int) ($result['created'] ?? 0),
            (int) ($result['updated'] ?? 0),
            (int) ($result['skipped'] ?? 0),
            (string) ($package['label'] ?? $packageKey)
        );

        return [
            'success' => ((int) ($result['created'] ?? 0) + (int) ($result['updated'] ?? 0) + (int) ($result['skipped'] ?? 0)) > 0,
            'message' => sprintf(
                'CSV-Paket „%s“ verarbeitet: %d neu angelegt, %d aktualisiert, %d übersprungen%s.',
                (string) $package['label'],
                (int) ($result['created'] ?? 0),
                (int) ($result['updated'] ?? 0),
                (int) ($result['skipped'] ?? 0),
                (int) ($result['errors'] ?? 0) > 0 ? sprintf(', %d mit Fehlern', (int) ($result['errors'] ?? 0)) : ''
            ),
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function importAllPackages(): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($this->packageDefinitions() as $packageKey => $package) {
            if ($package['entries'] === []) {
                continue;
            }

            $result = $this->repository->importPresetEntries($this->buildEntries($package), $packageKey);
            $created += (int) ($result['created'] ?? 0);
            $updated += (int) ($result['updated'] ?? 0);
            $skipped += (int) ($result['skipped'] ?? 0);
            $errors += (int) ($result['errors'] ?? 0);
        }

        $this->repository->saveCsvImportStatus($created + $updated, $created, $updated, $skipped, 'Alle CSV-Pakete');

        return [
            'success' => ($created + $updated + $skipped) > 0,
            'message' => sprintf(
                'Alle CSV-Pakete verarbeitet: %d neu angelegt, %d aktualisiert, %d übersprungen%s.',
                $created,
                $updated,
                $skipped,
                $errors > 0 ? sprintf(', %d mit Fehlern', $errors) : ''
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function packageDefinitions(): array
    {
        $definitions = [];

        foreach ($this->discoverCsvPackages() as $packageKey => $csvPackage) {
            $entries = $this->loadCsvEntries((string) $csvPackage['file_name']);

            $definitions[$packageKey] = [
                'label' => (string) $csvPackage['label'],
                'description' => sprintf('Glossar-Import aus %s.', (string) $csvPackage['file_name']),
                'accent' => $this->defaultAccentForPackageKey($packageKey),
                'file_name' => (string) $csvPackage['file_name'],
                'entries' => $entries,
            ];
        }

        return $definitions;
    }

    /**
     * @return array<string, array{file_name: string, label: string}>
     */
    private function discoverCsvPackages(): array
    {
        $files = $this->listCsvFiles('_Glossar.csv');
        if ($files === []) {
            return [];
        }
        $packages = [];

        foreach ($files as $filePath) {
            $fileName = basename($filePath);
            $baseName = preg_replace('/_Glossar\.csv$/i', '', $fileName) ?? '';
            $baseName = trim($baseName);
            if ($baseName === '') {
                continue;
            }

            $packageKey = $this->buildPackageKeyFromCsvBaseName($baseName);
            if ($packageKey === '') {
                continue;
            }

            $packages[$packageKey] = [
                'file_name' => $fileName,
                'label' => $this->buildCategoryLabelFromCsvBaseName($baseName),
            ];
        }

        return $packages;
    }

    /**
     * Absoluter Pfad der Beispiel-CSV oder leerer String, falls sie fehlt.
     */
    public function templateCsvPath(): string
    {
        return $this->resolveCsvPath(self::TEMPLATE_FILE_NAME);
    }

    /**
     * Spaltenüberschriften der Beispiel-CSV (für die Übersicht im Import-Tab).
     *
     * @return array<int, string>
     */
    public function templateColumns(): array
    {
        $path = $this->templateCsvPath();
        if ($path === '') {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle, 0, $this->detectDelimiter($path), '"', '\\');
        fclose($handle);
        if (!is_array($header)) {
            return [];
        }

        // Steht das BOM vor dem ersten Anführungszeichen, liefert fgetcsv die Quotes mit.
        return array_values(array_filter(array_map(
            static fn(mixed $column): string => trim(str_replace("\xEF\xBB\xBF", '', (string) $column), " \t\n\r\0\x0B\""),
            $header
        )));
    }

    /**
     * Baut aus den CSV-Zeilen eines Pakets die Datensätze für EntryRepository::importPresetEntries().
     *
     * `import_fields` listet die Felder, für die die CSV einen Wert liefert. Bestehende Einträge
     * behalten beim Re-Import alle übrigen Werte (z. B. manuell gepflegte Inhaltsbereiche).
     *
     * @param array<string, mixed> $package
     * @return array<int, array<string, mixed>>
     */
    private function buildEntries(array $package): array
    {
        $entries = [];

        foreach ($package['entries'] as $index => $entry) {
            $title = trim((string) ($entry['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $row = is_array($entry['row'] ?? null) ? $entry['row'] : [];
            $value = static fn(string $field): string => trim((string) ($row[$field] ?? ''));

            $keyword = $value('keyword');
            $slug = $value('slug');
            $category = $value('category');
            $tooltip = $value('tooltip_text');
            $shortDescription = $value('excerpt');
            $longDescription = $value('content');
            $synonyms = $this->parseSynonyms($value('synonyms'));

            // Leere Zellen gelten als „nicht angegeben“: Neue Einträge erhalten den Standardwert,
            // bestehende behalten ihren Wert. Die Kategorie kommt notfalls aus dem Dateinamen.
            $importFields = ['category'];
            if ($keyword !== '') {
                $importFields[] = 'keyword';
            }
            if ($shortDescription !== '' || $longDescription !== '') {
                $importFields[] = 'excerpt';
            }
            if ($tooltip !== '' || $shortDescription !== '' || $longDescription !== '') {
                $importFields[] = 'tooltip_text';
            }
            if ($synonyms !== []) {
                $importFields[] = 'synonyms';
            }

            $data = [
                'title' => $title,
                'keyword' => $keyword !== '' ? $keyword : $title,
                'slug' => $slug !== '' ? $slug : $title,
                'excerpt' => $this->buildCsvExcerpt($title, $shortDescription, $longDescription),
                'tooltip_text' => $tooltip !== '' ? $tooltip : $this->buildCsvTooltip($title, $shortDescription, $longDescription),
                'synonyms' => implode("\n", $synonyms),
                'category' => $category !== '' ? $category : (string) $package['label'],
            ];

            // Ungültige Werte (z. B. „vielleicht“ als Ja/Nein) werden wie leere Zellen behandelt.
            $settings = [
                'priority' => [$this->parseCsvInteger($value('priority')), (string) (100 + ((int) $index * 10))],
                'max_links_per_page' => [$this->parseCsvInteger($value('max_links_per_page')), '1'],
                'is_active' => [$this->parseCsvFlag($value('is_active')), '1'],
                'is_whole_word' => [$this->parseCsvFlag($value('is_whole_word')), '1'],
                'is_case_sensitive' => [$this->parseCsvFlag($value('is_case_sensitive')), '0'],
            ];
            foreach ($settings as $field => [$parsed, $default]) {
                $data[$field] = $parsed ?? $default;
                if ($parsed !== null) {
                    $importFields[] = $field;
                }
            }

            foreach ($this->buildCsvSections($row) as $column => $html) {
                $data[$column] = $html;
                $importFields[] = $column;
            }

            $data['import_fields'] = $importFields;
            $entries[] = $data;
        }

        return $entries;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadCsvEntries(string $fileName): array
    {
        $path = $this->resolveCsvPath($fileName);
        if (!is_file($path)) {
            $this->logger->warning('CSV-Datei für Standardpaket konnte nicht sicher aufgelöst werden.', ['file_name' => $fileName]);
            return [];
        }

        $delimiter = $this->detectDelimiter($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if (!is_array($header)) {
            fclose($handle);
            return [];
        }

        $headerMap = array_map([$this, 'normalizeCsvColumnName'], $header);
        $entries = [];

        while (($rowData = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if (!is_array($rowData) || $this->isEmptyCsvRow($rowData)) {
                continue;
            }

            $row = [];
            foreach ($headerMap as $index => $column) {
                if ($column === '') {
                    continue;
                }

                $value = trim((string) ($rowData[$index] ?? ''));
                // Doppelte Spalten (z. B. „Langbeschreibung“ und „Allgemeine Infos“): der erste gefüllte Wert gewinnt.
                if (!isset($row[$column]) || $row[$column] === '') {
                    $row[$column] = $value;
                }
            }

            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $entries[] = [
                'title' => $title,
                'row' => $row,
            ];
        }

        fclose($handle);

        return $entries;
    }

    /**
     * @return array<int, string>
     */
    private function listCsvFiles(string $suffix = 'csv'): array
    {
        $directory = $this->csvDirectory();
        if ($directory === '') {
            return [];
        }

        $files = [];
        $iterator = new \DirectoryIterator($directory);
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $fileName = $item->getFilename();
            if (!$this->isAllowedCsvFileName($fileName)) {
                continue;
            }

            if ($suffix !== '' && !str_ends_with(mb_strtolower($fileName, 'UTF-8'), mb_strtolower($suffix, 'UTF-8'))) {
                continue;
            }

            $path = $this->resolveCsvPath($fileName);
            if ($path === '') {
                continue;
            }

            $files[] = $path;
        }

        natcasesort($files);

        return array_values($files);
    }

    private function csvDirectory(): string
    {
        $baseDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'csv';
        if (!is_dir($baseDirectory)) {
            return '';
        }

        $realDirectory = realpath($baseDirectory);

        return is_string($realDirectory) ? $realDirectory : '';
    }

    private function resolveCsvPath(string $fileName): string
    {
        $fileName = trim($fileName);
        if (!$this->isAllowedCsvFileName($fileName)) {
            return '';
        }

        $directory = $this->csvDirectory();
        if ($directory === '') {
            return '';
        }

        $candidatePath = $directory . DIRECTORY_SEPARATOR . $fileName;
        if (!is_file($candidatePath)) {
            return '';
        }

        $realPath = realpath($candidatePath);
        if (!is_string($realPath) || $realPath === '') {
            return '';
        }

        if (!str_starts_with($realPath, $directory . DIRECTORY_SEPARATOR)) {
            return '';
        }

        return $realPath;
    }

    private function isAllowedCsvFileName(string $fileName): bool
    {
        return preg_match('/^[A-Za-z0-9._-]+\.csv$/', $fileName) === 1;
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ';';
        }

        $firstLine = fgets($handle);
        fclose($handle);

        if (!is_string($firstLine) || trim($firstLine) === '') {
            return ';';
        }

        $candidates = [
            ';' => substr_count($firstLine, ';'),
            ',' => substr_count($firstLine, ','),
            "\t" => substr_count($firstLine, "\t"),
        ];
        arsort($candidates);
        $delimiter = array_key_first($candidates);

        return is_string($delimiter) ? $delimiter : ';';
    }

    /**
     * @param array<int, mixed> $rowData
     */
    private function isEmptyCsvRow(array $rowData): bool
    {
        foreach ($rowData as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Ordnet eine Spaltenüberschrift einem internen Feld zu. Groß-/Kleinschreibung, Umlaute,
     * Leer- und Sonderzeichen spielen keine Rolle („Max. Links pro Seite“ = „max_links_per_page“).
     */
    private function normalizeCsvColumnName(string $value): string
    {
        $value = str_replace("\xEF\xBB\xBF", '', $value);
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $value);
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';

        return self::CSV_COLUMN_ALIASES[$value] ?? '';
    }

    /**
     * Liest Ja/Nein-Werte. Leere oder unbekannte Werte liefern `null` (= Standardwert verwenden).
     */
    private function parseCsvFlag(string $value): ?string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        if ($value === '') {
            return null;
        }

        if (in_array($value, ['1', 'ja', 'j', 'yes', 'y', 'true', 'wahr', 'x', 'on', 'an', 'aktiv', 'active'], true)) {
            return '1';
        }

        if (in_array($value, ['0', 'nein', 'n', 'no', 'false', 'falsch', 'off', 'aus', 'inaktiv', 'inactive', '-'], true)) {
            return '0';
        }

        return null;
    }

    /**
     * Liest ganze Zahlen. Leere oder ungültige Werte liefern `null` (= Standardwert verwenden);
     * die Wertebereiche begrenzt EntryRepository::saveEntry().
     */
    private function parseCsvInteger(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^\d{1,5}$/', $value) === 1 ? (string) (int) $value : null;
    }

    /**
     * @return array<int, string>
     */
    private function parseSynonyms(string $rawSynonyms): array
    {
        $rawSynonyms = trim($rawSynonyms);
        if ($rawSynonyms === '') {
            return [];
        }

        $parts = preg_split('/\r\n|\r|\n|\s*\|\s*|\s*;\s*|\s*,\s*/', $rawSynonyms) ?: [];
        $synonyms = [];
        $seen = [];

        foreach ($parts as $part) {
            $synonym = trim($part);
            if ($synonym === '') {
                continue;
            }

            $normalized = mb_strtolower($synonym, 'UTF-8');
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $synonyms[] = $synonym;
        }

        return $synonyms;
    }

    private function buildPackageKeyFromCsvBaseName(string $baseName): string
    {
        $key = mb_strtolower(trim($baseName), 'UTF-8');
        $key = str_replace('_', '-', $key);
        $key = preg_replace('/[^a-z0-9\-]+/', '-', $key) ?? '';

        return trim($key, '-');
    }

    private function buildCategoryLabelFromCsvBaseName(string $baseName): string
    {
        $label = str_replace('_', ' & ', trim($baseName));
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;

        return trim($label);
    }

    private function defaultAccentForPackageKey(string $packageKey): string
    {
        $palette = [
            '#0EA5E9',
            '#2563EB',
            '#DC2626',
            '#7C3AED',
            '#0F766E',
            '#F59E0B',
            '#4F46E5',
            '#BE123C',
        ];

        $hash = abs(crc32($packageKey));

        return $palette[$hash % count($palette)];
    }

    private function buildCsvExcerpt(string $title, string $shortDescription, string $longDescription): string
    {
        // Eine gepflegte Kurzbeschreibung wird unverändert übernommen, nur der Fallback wird gekürzt.
        if ($shortDescription !== '') {
            return $shortDescription;
        }

        $excerpt = $longDescription !== '' ? $longDescription : $title;

        return mb_substr(trim($excerpt), 0, 280, 'UTF-8');
    }

    private function buildCsvTooltip(string $title, string $shortDescription, string $longDescription): string
    {
        $tooltip = $shortDescription !== '' ? $shortDescription : $longDescription;
        if ($tooltip === '') {
            $tooltip = $title;
        }

        return mb_substr(trim($tooltip), 0, 220, 'UTF-8');
    }

    /**
     * Baut die strukturierten Inhaltsbereiche (Spaltenname => HTML) aus einer CSV-Zeile.
     * Es werden nur befüllte Bereiche geliefert.
     * Die HTML-Fragmente werden beim Speichern in EditorJS-JSON umgewandelt.
     *
     * @param array<string, string> $row
     * @return array<string, string>
     */
    private function buildCsvSections(array $row): array
    {
        $sections = [];

        foreach (ContentSections::columns() as $column) {
            $raw = trim((string) ($row[$column] ?? ''));

            // „Allgemeine Infos“ fällt wie bisher auf die Kurzbeschreibung zurück.
            if ($column === 'content' && $raw === '') {
                $raw = trim((string) ($row['excerpt'] ?? ''));
            }

            if ($column === 'content_links') {
                $linksHtml = $this->buildLinksList($this->parseCsvLinks($raw));
                $html = $linksHtml !== '' ? '<ul>' . $linksHtml . '</ul>' : '';
            } else {
                $html = $this->buildCsvTextHtml($raw);
            }

            if ($html !== '') {
                $sections[$column] = $html;
            }
        }

        return $sections;
    }

    /**
     * Wandelt Klartext aus einer CSV-Zelle in HTML um: Jede Zeile wird ein Absatz,
     * Zeilen mit „- “, „* “ oder „• “ eine Aufzählung, Zeilen mit „1. “ eine nummerierte Liste.
     */
    private function buildCsvTextHtml(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return '';
        }

        $html = '';
        $listTag = '';
        $listItems = [];

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            $isBullet = preg_match('/^[-*•]\s+(.+)$/u', $line, $bulletMatch) === 1;
            $isNumbered = !$isBullet && preg_match('/^\d{1,3}[.)]\s+(.+)$/u', $line, $numberMatch) === 1;
            $tag = $isBullet ? 'ul' : ($isNumbered ? 'ol' : '');

            if ($listItems !== [] && $tag !== $listTag) {
                $html .= $this->buildCsvListHtml($listTag, $listItems);
                $listItems = [];
            }

            if ($line === '') {
                continue;
            }

            if ($tag !== '') {
                $listTag = $tag;
                $listItems[] = trim($isBullet ? $bulletMatch[1] : $numberMatch[1]);
                continue;
            }

            $html .= '<p>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        if ($listItems !== []) {
            $html .= $this->buildCsvListHtml($listTag, $listItems);
        }

        return $html;
    }

    /**
     * @param array<int, string> $items
     */
    private function buildCsvListHtml(string $tag, array $items): string
    {
        $tag = $tag === 'ol' ? 'ol' : 'ul';
        $html = '';
        foreach ($items as $item) {
            $html .= '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
        }

        return '<' . $tag . '>' . $html . '</' . $tag . '>';
    }

    /**
     * Liest Weblinks. Mehrere Links per Zeilenumbruch oder „ | “ trennen. Text vor der URL
     * wird als Linktext genutzt („Microsoft Learn: https://…“), sonst wird er aus der Domain abgeleitet.
     *
     * @return array<int, array{label: string, url: string}>
     */
    private function parseCsvLinks(string $rawLinks): array
    {
        $rawLinks = str_replace(["\r\n", "\r"], "\n", trim($rawLinks));
        if ($rawLinks === '') {
            return [];
        }

        $parts = preg_split('/\n+|\s*\|\s*|[,;]\s*(?=https?:\/\/)/', $rawLinks) ?: [];
        $links = [];
        $seen = [];

        foreach ($parts as $part) {
            if (preg_match_all('~https?://[^\s<>"]+~i', $part, $matches, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            $cursor = 0;
            foreach ($matches[0] as [$match, $offset]) {
                $labelText = substr($part, $cursor, $offset - $cursor);
                $cursor = $offset + strlen($match);

                $url = rtrim($match, '.,;:');
                // Schließende Klammer gehört nur zur URL, wenn sie auch eine öffnende enthält (z. B. Wikipedia).
                if (str_ends_with($url, ')') && !str_contains($url, '(')) {
                    $url = rtrim($url, ')');
                }

                if (filter_var($url, FILTER_VALIDATE_URL) === false || isset($seen[$url])) {
                    continue;
                }

                $label = trim((string) preg_replace('/^[\s:;,\-–—"\'\[\]()]+|[\s:;,\-–—"\'\[\]()]+$/u', '', $labelText));
                $seen[$url] = true;
                $links[] = [
                    'label' => $label !== '' ? mb_substr($label, 0, 190, 'UTF-8') : $this->labelForCsvLink($url),
                    'url' => $url,
                ];
            }
        }

        return $links;
    }

    private function labelForCsvLink(string $url): string
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST), 'UTF-8');

        return match (true) {
            str_contains($host, 'learn.microsoft.com') => 'Microsoft Learn',
            str_contains($host, 'support.microsoft.com') => 'Microsoft Support',
            str_contains($host, 'microsoft.com') => 'Microsoft',
            str_contains($host, 'wikipedia.org') => 'Wikipedia',
            str_contains($host, 'google.com') => 'Google',
            $host !== '' => preg_replace('/^www\./', '', $host) ?? $host,
            default => 'Weblink',
        };
    }

    /**
     * @param array<int, array{label: string, url: string}> $items
     */
    private function buildLinksList(array $items): string
    {
        $html = [];

        foreach ($items as $item) {
            $label = trim((string) ($item['label'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));
            if ($label === '' || $url === '') {
                continue;
            }

            $html[] = sprintf(
                '<li><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
                htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            );
        }

        return implode('', $html);
    }
}
