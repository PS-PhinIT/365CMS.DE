<?php
/**
 * Regressionen der Release-Prüfung 4.1.0 (DOC/audit/RELEASE-AUDIT-4.1.0-2026-11-15.md), ohne Datenbank:
 * reine Funktionen und Quelltext-Verträge für Befunde, die keine eigene Suite haben (ab PR #3, FUN-410-xx).
 *
 * Aufruf: php TESTS/release-4.1.0/run.php [-v]
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/TestRunner.php';
T::$verbose = in_array('-v', $argv ?? [], true);

$root = dirname(__DIR__, 2) . '/CMS/';
define('ABSPATH', $root);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'CMS\\')) {
        $file = $root . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

$src = static fn (string $file): string => (string) file_get_contents($root . $file);

T::run('FUN-410-01: Projekt-Repository PS-PhinIT', static function () use ($src, $root): void {
    $update = $src('core/Services/UpdateService.php');
    T::check(str_contains($update, "DEFAULT_GITHUB_REPO = 'PS-PhinIT/365CMS.DE'"), 'Update-Rückfall fragt das aktuelle Repository ab');
    T::check(str_contains($update, "strcasecmp(\$this->githubRepo, self::LEGACY_GITHUB_REPO) === 0"), 'gespeicherter Altwert wird umgestellt (Http\\Client folgt keinen Weiterleitungen)');
    foreach (['admin/modules/system/DocumentationModule.php', 'update.json', 'marketplace/core/365cms/update.json', 'core/VendorRegistry.php', 'admin/views/system/support.php'] as $file) {
        T::check(!str_contains($src($file), 'PS-easyIT/365CMS.DE'), "{$file}: keine Adresse des alten Repositorys");
    }
});

T::run('FUN-410-14: AVIF-Fassungen wandern mit in den Papierkorb', static function (): void {
    $suffixes = \CMS\Services\Trash\MediaTrashProvider::companionSuffixes('foto.jpg');
    foreach (['.webp', '.avif', '-small.avif', '-banner.avif', '-medium.jpg'] as $expected) {
        T::check(in_array($expected, $suffixes, true), "foto.jpg: {$expected}");
    }
    T::check(!in_array('.avif', \CMS\Services\Trash\MediaTrashProvider::companionSuffixes('datei.pdf'), true), 'PDF hat keine AVIF-Fassung');
});

T::run('FUN-410-02/03/04/11/12: Freigabe-Ablauf', static function () use ($src): void {
    $settings = $src('admin/modules/settings/SettingsModule.php');
    T::check(str_contains($settings, '$this->countPublishers() < 2') && str_contains($settings, "rp.capability = 'posts.publish'"), 'Warnung bei Vier-Augen-Prinzip mit weniger als zwei Freigabeberechtigten');
    $posts = $src('admin/modules/posts/PostsModule.php');
    $check = strpos($posts, '[, $skipped] = $this->partitionPostsByPublishChecklist([$id]);');
    T::check($check !== false && $check < strpos($posts, "'approve' => \$workflow->approve(ContentWorkflow::TYPE_POST"), 'Freigabe prüft die Veröffentlichungs-Checkliste');
    $workflow = $src('core/Services/Content/ContentWorkflow.php');
    T::check(str_contains($workflow, "Über die Sammelaktion „Entwurf“ zurückgesetzt (ohne Begründung)."), 'Sammelaktion „Entwurf“ für eingereichte Inhalte ist im Verlauf erkennbar');
    T::check(str_contains($workflow, "if (\$changed < 1) {\n            return ['success' => false, 'error' => 'Der Status wurde inzwischen geändert."), 'Einreichen prüft den Zustandswechsel');
    T::check(str_contains($workflow, 'ein Vorschau-Link ist nicht nötig'), 'kein Vorschau-Link für bereits öffentliche Inhalte');
});

T::run('FUN-410-13/29/30/31/32: Löschen, Ablauf, Zuordnungen', static function () use ($src): void {
    T::check(str_contains($src('core/Services/Content/ContentExpiryService.php'), "\$this->runHook('cms_content_status_changed', \$type, \$id, ContentStatus::PUBLISHED, ContentStatus::DRAFT);"), 'Ablauf meldet den Statuswechsel (Webhooks)');
    T::check(str_contains($src('core/Services/FeatureHooks/MediaFeatureHooks.php'), "'hub_deleted' => ['hub_site', true],"), 'endgültiges Löschen einer Hub-Site entfernt ihre Medienverwendungen');
    T::check(str_contains($src('core/Services/Trash/PageTrashProvider.php'), "'accessibility_page_id' => 'trash.assignment.accessibility',"), 'Barrierefreiheitserklärung ist eine geschützte Seitenzuordnung');
    foreach (['de', 'en'] as $lang) {
        T::check(str_contains($src("lang/{$lang}.yaml"), '"trash.assignment.accessibility":'), "{$lang}: Bezeichnung der Zuordnung");
    }
    foreach (['PostTrashProvider', 'PageTrashProvider'] as $provider) {
        $source = $src("core/Services/Trash/{$provider}.php");
        T::check(str_contains($source, "'content_workflow_events' => \$this->deleteFrom(") && str_contains($source, "'content_preview_links' => \$this->deleteFrom("), "{$provider}: Freigabeverlauf und Vorschau-Links werden mitgelöscht");
    }
    $tables = $src('admin/modules/tables/TablesModule.php');
    T::check(substr_count($tables, 'self::TABLE_SCOPE') >= 7, 'Seite „Tabellen“ arbeitet nur mit Tabellen (keine Hub-Sites, nicht im Papierkorb)');
    T::check(str_contains($tables, "\$where  = 'WHERE ' . self::TABLE_SCOPE;"), 'Liste filtert Hub-Sites vor dem LIMIT');
});

T::run('FUN-410-24/25: Offsite-Upload', static function () use ($src): void {
    T::check(str_contains($src('core/Services/Backup/OffsiteBackupService.php'), '@set_time_limit(0);'), 'kein Abbruch am max_execution_time');
    $client = $src('core/Services/Backup/S3Client.php');
    T::check(str_contains($client, '$partSize = max($partSize, (int) ceil($size / self::MAX_PARTS));'), 'höchstens 10 000 Teile');
    T::check(str_contains($client, 'for ($attempt = 1; $attempt <= self::PART_ATTEMPTS && $etag === null; $attempt++) {'), 'Teil wird bis zu dreimal versucht');
});

T::finish();
