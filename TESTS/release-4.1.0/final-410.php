<?php
/**
 * Release-Prüfung 4.1.0, Entscheidungen in 4.1.6: DOC-410-01, FUN-410-17/18/19, SEC-410-26, PERF-410-47/48.
 * Wird von run.php eingebunden ($src, $root, ABSPATH, Autoloader).
 */

declare(strict_types=1);

T::run('FUN-410-18: AVIF-Neuaufbau bleibt nicht an einem zu großen Bild hängen', static function () use ($src): void {
    $png = sys_get_temp_dir() . '/cms-final410-' . getmypid() . '.png';
    // PNG-Kopf 4000 x 3000 Pixel (getimagesize liest nur den Kopf).
    $ihdr = pack('NNCCCCC', 4000, 3000, 8, 6, 0, 0, 0);
    file_put_contents($png, "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)));
    $needed = 4000 * 3000 * 4 * 2;
    T::check(\CMS\Services\Media\AvifRegenerationService::exceedsMemoryBudget($png, memory_get_usage(true) + $needed - 1) !== null, 'Bild über dem freien Speicher wird übersprungen');
    T::check(\CMS\Services\Media\AvifRegenerationService::exceedsMemoryBudget($png, memory_get_usage(true) + $needed * 2) === null, 'Bild im Speicherrahmen wird verarbeitet');
    @unlink($png);
    $service = $src('core/Services/Media/AvifRegenerationService.php');
    T::check(str_contains($service, '$this->writeCrashMarker($jobId, $cursor);') && str_contains($service, "self::translate('media_tools.avif.aborted')"), 'Absturzmarke: das abgebrochene Bild wird im Folgelauf übersprungen');
    foreach (['de', 'en'] as $lang) {
        T::check(str_contains($src("lang/{$lang}.yaml"), '"media_tools.avif.aborted":'), "{$lang}: Meldung für abgebrochene Bilder");
    }
});

T::run('SEC-410-26: delegierbare Admin-Seiten', static function () use ($src): void {
    T::same(\CMS\Services\AdminMenu\DelegatedAdminAccess::PAGES, ['payments' => 'payments.manage', 'invoices' => 'invoices.view', 'reusable-blocks' => 'blocks.reusable', 'webhooks' => 'webhooks.manage'], 'nur die vier 4.1.0-Seiten mit eigener Capability');
    T::check(\CMS\Services\AdminMenu\DelegatedAdminAccess::capabilityFor('settings') === null && \CMS\Services\AdminMenu\DelegatedAdminAccess::capabilityFor('backup-settings') === null, 'Einstellungen und Backups bleiben Admins vorbehalten');
    T::check(str_contains($src('core/Routing/AdminRouter.php'), '!Auth::instance()->isAdmin() && !\CMS\Services\AdminMenu\DelegatedAdminAccess::routerAllows($page)'), 'Router lässt nur delegierte Seiten ohne Admin-Rolle durch');
    foreach (['payments' => "'payments.manage'", 'invoices' => "'invoices.view'", 'reusable-blocks' => 'CMS_ADMIN_REUSABLE_BLOCKS_CAPABILITY', 'webhooks' => "'webhooks.manage'"] as $page => $cap) {
        T::check(str_contains($src("admin/{$page}.php"), "DelegatedAdminAccess::canAccess('{$page}', {$cap})"), "/admin/{$page}: Zugriff über die Capability");
    }
});

T::run('FUN-410-19: Wissensdatenbank und Theme im Verwendungsnachweis', static function () use ($src): void {
    T::same((new \CMS\Services\Media\Usage\KnowledgebaseReferenceExtractor())->contentType(), 'kb_entry', 'eigene Inhaltsart kb_entry');
    T::check(str_contains($src('core/Services/Media/MediaUsageIndex.php'), 'new Usage\KnowledgebaseReferenceExtractor(),'), 'Extraktor ist registriert');
    $hooks = $src('core/Services/FeatureHooks/MediaFeatureHooks.php');
    T::check(str_contains($hooks, "'cms_knowledgebase_entry_saved' => ['kb_entry', false],") && str_contains($hooks, "'cms_knowledgebase_entry_deleted' => ['kb_entry', true],"), 'Speichern und Löschen indizieren neu');
    T::check(substr_count($src('core/Services/ThemeCustomizer.php'), "Hooks::doAction('cms_theme_customizer_saved', \$this->currentTheme, 'reset', '');") === 2, 'Theme-Reset (einzeln und komplett) indiziert neu');
    T::check(str_contains($src('core/ThemeManager.php'), "Hooks::doAction('cms_theme_customizer_saved', \$theme, 'switch', '');"), 'Theme-Wechsel indiziert neu');
});

T::run('FUN-410-17: Umbenennen und Verschieben', static function () use ($src): void {
    $upload = $src('core/Services/Media/UploadHandler.php');
    T::check(substr_count($upload, '$this->moveCompanionFiles(') === 2, 'Größen, WebP und AVIF wandern beim Verschieben und Umbenennen mit');
    $module = $src('admin/modules/media/MediaModule.php');
    T::check(substr_count($module, '$inUse = $this->mediaUsageBlocker(') === 3, 'verwendete Dateien werden weder umbenannt noch verschoben (Einzel- und Sammelaktion)');
});

T::run('PERF-410-47/48: Importer-Bilder, AVIF nach der Antwort', static function () use ($src): void {
    $importer = $src('plugins/cms-importer/includes/class-importer.php');
    T::check(str_contains($importer, "'timeout' => 10,") && str_contains($importer, "applyFilters('cms_importer_image_budget_seconds', \$budget)"), 'Importer: 10 s je Bild, Zeitbudget je Lauf');
    T::check(str_contains($importer, '($this->image_host_failures[$host] ?? 0) >= 2'), 'Importer: gestörte Hosts werden übersprungen');
    $media = $src('core/Services/MediaService.php');
    T::check(str_contains($media, 'self::$deferredAvifPaths[$absolutePath] = true;') && str_contains($media, 'session_write_close();'), 'Upload: AVIF nach der Antwort, Session vorher frei');
});

T::run('DOC-410-01: Anzahl der Test-Suiten im Changelog', static function () use ($root): void {
    $changelog = (string) file_get_contents(dirname($root) . '/Changelog.md');
    T::check(str_contains($changelog, '`php TESTS/run-all.php` führt 32 Suiten aus (in 4.1.6 korrigiert'), '4.1.0 nennt 32 Suiten');
    $suites = count(glob(dirname($root) . '/TESTS/*/run.php') ?: []);
    T::check(str_contains($changelog, "`php TESTS/run-all.php` führt {$suites} Suiten aus: zu den 32 aus 4.1.0"), "4.1.5 nennt die tatsächliche Zahl ({$suites})");
});

T::run('FUN-410-34/35: Wissensdatenbank-Import, Feed-Aufbewahrung', static function () use ($src): void {
    $repo = $src('core/Services/Knowledgebase/Repository/EntryRepository.php');
    $import = strpos($repo, 'public function importPresetEntries(');
    T::check($import !== false && strpos($repo, "if (!empty(\$existingEntry['trashed_at'])) {", $import) !== false, 'Import (einzeln und alle Pakete) überspringt Einträge im Papierkorb');
    $settings = $src('core/Services/Feed/AggregatorSettings.php');
    T::check(str_contains($settings, "if (\$key === 'item_retention_days' && \$value > 0 && \$value < 7) {"), 'Aufbewahrung 1–6 Tage wird auf 7 angehoben (0 = nie löschen)');
    $method = new \ReflectionMethod(\CMS\Services\Feed\AggregatorSettings::class, 'intInRange');
    T::same([0, 7, 7, 30], [$method->invoke(null, 'item_retention_days', 0, 30), $method->invoke(null, 'item_retention_days', 1, 30), $method->invoke(null, 'item_retention_days', 6, 30), $method->invoke(null, 'item_retention_days', 30, 30)], 'Werte 0, 1, 6, 30 ergeben 0, 7, 7, 30');
});

T::run('FUN-410-10: Zurückziehen benachrichtigt die Prüfenden', static function () use ($src): void {
    $users = [(object) ['id' => 1, 'role' => 'admin'], (object) ['id' => 2, 'role' => 'editor'], (object) ['id' => 3, 'role' => 'author']];
    $can = static fn (string $role, string $cap): bool => in_array($role, ['admin', 'editor'], true);
    $ids = \CMS\Services\Content\ContentWorkflow::recipientIds(\CMS\Services\Content\ContentWorkflow::ACTION_WITHDRAWN, \CMS\Services\Content\ContentWorkflow::TYPE_POST, $users, $can, 3, 3, 3);
    sort($ids);
    T::same([1, 2], $ids, 'Prüfende mit Freigaberecht, nicht die zurückziehende Person');
    $wf = $src('core/Services/Content/ContentWorkflow.php');
    T::check(str_contains($wf, "self::ACTION_WITHDRAWN => 'cms_content_withdrawn',") && str_contains($wf, "Zurückgezogen: %s „%s“"), 'eigener Hook und Benachrichtigung');
});
