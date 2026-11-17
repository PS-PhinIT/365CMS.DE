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

T::run('PR #4: PHP 8.5 in gebündelten Bibliotheken (FUN-410-40)', static function () use ($src): void {
    foreach (['assets/melbahja-seo/src/Utils/HttpClient.php', 'assets/webauthn/WebAuthn.php'] as $file) {
        T::check(!preg_match('/\bcurl_close\s*\(\s*\$/', $src($file)), "{$file}: kein curl_close() (seit PHP 8.5 veraltet)");
    }
});

T::run('PR #4: Beitrags-Editor und Freigabe (SEC-410-27/28, FUN-410-42/43/44)', static function () use ($src): void {
    $posts = $src('admin/modules/posts/PostsModule.php');
    $copy = strpos($posts, 'public function copyGermanToEnglish(');
    T::check($copy !== false && strpos($posts, '$this->canAccessPost($id)', $copy) < strpos($posts, 'buildSnapshotFromPost', $copy), 'DE→EN-Kopie prüft den Zugriff auf den Beitrag');
    T::check(str_contains($src('admin/posts.php'), "if (\$action === 'copy_de_to_en') {"), 'DE→EN-Kopie verlangt Schreibrecht');
    T::check(str_contains($posts, "\$publishedAtInput['value'] = (string) \$existing->published_at;"), 'geplanter Termin bleibt ohne Veröffentlichungsrecht unverändert');
    T::check(str_contains($posts, 'if (!$isAutosave && $wasLive) {'), 'Slug-Weiterleitung nur für öffentliche Beiträge und nicht beim Autosave');
    T::check(substr_count($posts, "'content' =>") >= 2 && str_contains($posts, 'p.content, p.content_en'), 'Checkliste erhält den Inhalt (Prüfpunkt Downloads)');
    T::check(str_contains($src('core/Services/EditorialCalendarService.php'), 'p.content, p.content_en'), 'Redaktionskalender: Checkliste mit Inhalt');
    $schema = $src('core/SchemaManager.php');
    T::check(str_contains($schema, 'private function ensureTagsTextColumns(') && str_contains($schema, "tags TEXT COMMENT 'Kommagetrennte Tags'"), 'Schlagwörter als TEXT (Neuinstallation und Upgrade)');
    T::check(!preg_match('/tags[^\n]{0,80}500/', $src('core/Services/PostRevisionService.php')), 'Revisionen schneiden Schlagwörter nicht mehr ab');
});

T::run('PR #4: Seiten-Editor (FUN-410-41/45)', static function () use ($src): void {
    T::check(str_contains($src('admin/views/pages/edit.php'), 'style="max-width: <?= (int) $pageEditorWidth ?>px;"'), 'Einstellung „Editor-Breite (Seiten)“ wirkt');
    $pages = $src('admin/modules/pages/PagesModule.php');
    T::check(substr_count($pages, "'Seitenvorlage', \$currentPage['page_template']") === 1 && substr_count($pages, "'Vorlagen-Zusatzfelder', \$currentPage['page_meta_json']") === 1, 'Revisionsvergleich listet Vorlage und Zusatzfelder einmal');
});

T::run('PR #4: SEO-Schalter mit Wirkung (FUN-410-46/47/49/51/52/53)', static function () use ($src, $root): void {
    $head = $src('core/Services/SEO/SeoHeadRenderer.php');
    T::check(str_contains($head, "getSetting('self_referencing_canonical', '1') === '0'"), 'Self-referencing Canonical abschaltbar');
    T::check(str_contains($head, "'technical_noindex_archives', 'tag' => 'technical_noindex_tags'"), 'Archive/Tags noindex');
    T::check(str_contains($head, "technical_pagination_rel"), 'Pagination-SEO-Schalter');
    T::check(str_contains($src('core/Services/SEO/SeoSchemaRenderer.php'), "getSetting('schema_person_enabled', '1') !== '0'"), 'Schema „Person / Author“ abschaltbar');
    foreach (['admin/modules/posts/PostsModule.php', 'admin/modules/pages/PagesModule.php'] as $file) {
        T::check(str_contains($src($file), "get_option('seo_technical_auto_redirect_slug', '1')"), "{$file}: automatische Slug-Weiterleitung abschaltbar");
    }
    $suite = $src('admin/modules/seo/SeoSuiteModule.php');
    foreach (['seo_schema_faq_enabled', 'seo_schema_howto_enabled', 'seo_schema_review_enabled', 'seo_schema_event_enabled', 'seo_social_pinterest_rich_pins', 'seo_technical_breadcrumbs_enabled', 'seo_technical_image_alt_required'] as $key) {
        T::check(!str_contains($suite, "'{$key}'"), "{$key}: Schalter ohne Wirkung entfernt");
    }
    foreach (glob($root . 'admin/views/seo/*.php') ?: [] as $view) {
        $html = (string) file_get_contents($view);
        foreach (['faq_enabled', 'howto_enabled', 'review_enabled', 'event_enabled', 'pinterest_rich_pins', 'breadcrumbs_enabled"', 'image_alt_required'] as $field) {
            T::check(!str_contains($html, 'name="' . rtrim($field, '"') . '"'), basename($view) . ": kein Feld {$field}");
        }
    }
    T::check(str_contains($suite, 'SeoSchemaRenderer::SUPPORTED_TYPES'), 'Schema-Übersicht nennt nur ausgegebene Typen');
    $sitemap = $src('core/Services/SEO/SeoSitemapService.php');
    T::check(substr_count($sitemap, 'isIndexableSitemapEntry(') >= 4, 'EN-Sitemaps prüfen noindex/Canonical');
    T::check(str_contains($suite, '$this->seoService->invalidateSavedSitemaps();'), 'Sitemap-Einstellungen verwerfen gespeicherte Sitemaps');
    T::check(str_contains($src('core/Bootstrap.php'), "get_option('sitemap_scheduled_check_at', '')"), 'geplante Beiträge erscheinen nach Veröffentlichung in der Sitemap');
});

T::run('PR #4: verwaiste Einstellungen und Verweise (FUN-410-48/50/54–60)', static function () use ($src): void {
    T::check(str_contains($src('core/Services/Forms/FormSpamGuard.php'), 'isSessionDeferred()'), 'Captcha bei verzögertem Sitzungsstart');
    $router = $src('core/Routing/ThemeRouter.php');
    T::check(substr_count($router, '$perPage = $this->configuredPostsPerPage(') === 4, '„Beiträge pro Seite“ in Blog, Kategorie, Tag, Autor');
    T::check(str_contains($src('core/Services/OpcacheWarmupService.php'), '$this->toRelativePath($normalizedPath)'), 'OPcache-Ausschlüsse relativ zu ABSPATH');
    $feed = $src('core/Services/FeatureHooks/FeedHooks.php');
    T::check(str_contains($feed, "addAction('dsgvo_delete_data'") && str_contains($feed, 'deleteDigestsByEmail($email)'), 'DSGVO-Löschung entfernt Feed-Digests');
    T::check(!str_contains($src('admin/modules/settings/SettingsModule.php'), 'google_analytics') && !str_contains($src('admin/views/settings/general.php'), 'name="google_analytics"'), 'Feld „google_analytics“ ohne Ausgabe entfernt');
    T::check(str_contains($src('admin/modules/seo/AnalyticsModule.php'), "option_name = 'seo_analytics_ga4_id'"), 'Analytics-Übersicht liest die GA4-ID aus SEO › Analytics');
    T::check(!str_contains($src('admin/views/themes/list.php'), 'href="/admin/theme-settings"'), 'Design-Einstellungen führen zum Theme-Editor');
    foreach (['admin/plugin-marketplace.php', 'admin/theme-marketplace.php', 'admin/views/plugins/list.php', 'admin/views/themes/list.php'] as $file) {
        T::check(str_contains($src($file), 'isMarketplaceEnabled()'), "{$file}: Schalter „Marketplace anzeigen“");
    }
    foreach (['payments', 'invoices'] as $page) {
        T::check(str_contains($src("admin/{$page}.php"), "isAdminPageEnabled('{$page}')"), "/admin/{$page}: Kernmodul-Prüfung");
    }
    T::check(str_contains($src('admin/modules/settings/SettingsModule.php'), "PLUGIN_PATH, '/\\\\') . '/' : ABSPATH . 'plugins/') . 'cms-importer/'"), 'Importer-Pfad unter CMS/plugins/');
    $pm = $src('core/PageManager.php');
    T::check(str_contains($pm, 'PageTrashProvider($this->db))->trash($id, $userId)->ok') && !str_contains($pm, 'DELETE FROM {$this->prefix}pages WHERE id = ?'), 'PageManager::deletePage() verschiebt in den Papierkorb');
});

// Release-Prüfung 4.1.0, PR #5 (Performance): Regressionen je Umsetzungs-Cluster.
foreach (['admin', 'core', 'cache', 'blocking', 'frontend', 'batch'] as $perfCluster) {
    $perfFile = __DIR__ . '/perf-' . $perfCluster . '.php';
    if (is_file($perfFile)) {
        require $perfFile;
    }
}

require __DIR__ . '/final-410.php';
require __DIR__ . '/closure-410.php';

T::finish();
