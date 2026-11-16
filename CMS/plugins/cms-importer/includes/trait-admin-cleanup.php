<?php
/**
 * CMS WordPress Importer – Admin-Cleanup-Helfer
 *
 * @package CMS_Importer
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('CMS_IMPORTER_ADMIN_CLEANUP_TRAIT_LOADED') || trait_exists('CMS_Importer_Admin_Cleanup_Trait', false)) {
    return;
}

define('CMS_IMPORTER_ADMIN_CLEANUP_TRAIT_LOADED', true);

trait CMS_Importer_Admin_Cleanup_Trait
{
    /**
     * @return array{posts:int,pages:int,tables:int,seo_total:int,seo_settings:int,seo_meta:int,logs:int,mappings:int,meta:int,reports:int}
     */
    private function get_cleanup_stats(): array
    {
        if (!class_exists('CMS\Database')) {
            return [
                'posts' => 0,
                'pages' => 0,
                'tables' => 0,
                'seo_total' => 0,
                'seo_settings' => 0,
                'seo_meta' => 0,
                'logs' => 0,
                'mappings' => 0,
                'meta' => 0,
                'reports' => $this->count_report_files(),
            ];
        }

        $db = CMS\Database::instance();
        $p = $db->getPrefix();

        $seoSettings = $this->count_setting_rows_like($db, $p . 'settings', 'seo\\_%');
        $seoMeta = $this->count_table_rows($db, $p . 'seo_meta');

        return [
            'posts' => $this->count_table_rows($db, $p . 'posts'),
            'pages' => $this->count_table_rows($db, $p . 'pages'),
            'tables' => $this->count_table_rows($db, $p . 'site_tables'),
            'seo_total' => $seoSettings + $seoMeta,
            'seo_settings' => $seoSettings,
            'seo_meta' => $seoMeta,
            'logs' => $this->count_table_rows($db, $p . 'import_log'),
            'mappings' => $this->count_table_rows($db, $p . 'import_items'),
            'meta' => $this->count_table_rows($db, $p . 'import_meta'),
            'reports' => $this->count_report_files(),
        ];
    }

    /**
     * @return array{0:string,1:string,2:array<string,int>|null}
     */
    private function cleanup_content_entries(bool $resetSequences = false): array
    {
        if (!class_exists('CMS\Database')) {
            return ['CMS\\Database nicht verfügbar.', 'error', null];
        }

        $db = CMS\Database::instance();
        $p = $db->getPrefix();
        $plannedPosts = $this->count_table_rows($db, $p . 'posts');
        $plannedPages = $this->count_table_rows($db, $p . 'pages');
        $removedMappings = 0;
        $removedSeo = 0;
        $removedTagRelations = 0;

        if ($plannedPosts === 0 && $plannedPages === 0) {
            return ['Es sind aktuell keine Beiträge oder Seiten vorhanden. Es wurden keine Inhalte gelöscht.', 'warning', [
                'planned_posts' => 0,
                'planned_pages' => 0,
                'deleted_posts' => 0,
                'deleted_pages' => 0,
                'mappings' => 0,
                'seo_meta' => 0,
                'tag_relations' => 0,
                'remaining_posts' => 0,
                'remaining_pages' => 0,
            ]];
        }

        try {
            if ($this->has_table($db, $p . 'post_tag_rel')) {
                $removedTagRelations = (int) ($db->execute("DELETE FROM {$p}post_tag_rel")?->rowCount() ?? 0);
            }

            if ($this->has_table($db, $p . 'posts')) {
                $db->execute("DELETE FROM {$p}posts");
            }

            if ($this->has_table($db, $p . 'pages')) {
                $db->execute("DELETE FROM {$p}pages");
            }

            if ($this->has_table($db, $p . 'post_tags')) {
                $db->execute("UPDATE {$p}post_tags SET post_count = 0");
            }

            if ($this->has_table($db, $p . 'seo_meta')) {
                $removedSeo += (int) ($db->execute("DELETE FROM {$p}seo_meta WHERE content_type IN ('post', 'page')")?->rowCount() ?? 0);
            }

            if ($this->has_table($db, $p . 'import_items')) {
                $removedMappings = (int) ($db->execute("DELETE FROM {$p}import_items WHERE target_type IN ('post', 'page')")?->rowCount() ?? 0);
            }

            // Release-Prüfung 4.1.0 (FUN-410-33): Das Zurücksetzen löscht ohne die Lösch-Hooks einzelner Inhalte. Damit
            // keine verwaisten Verweise bleiben (Verwendungsnachweis, Block-Vorlagen, Freigabeverlauf, Vorschau-Links,
            // Kategorien, Revisionen, Kommentare), werden die abhängigen Zeilen hier mitgelöscht.
            foreach (['media_usage', 'reusable_block_usage', 'content_workflow_events', 'content_preview_links'] as $dependent) {
                if ($this->has_table($db, $p . $dependent)) {
                    $db->execute("DELETE FROM {$p}{$dependent} WHERE content_type IN ('post', 'page')");
                }
            }
            foreach (['post_category_rel', 'post_revisions', 'page_revisions', 'comments'] as $dependent) {
                if ($this->has_table($db, $p . $dependent)) {
                    $db->execute("DELETE FROM {$p}{$dependent}");
                }
            }
        } catch (\Throwable $e) {
            return ['Bereinigung von Beiträgen/Seiten fehlgeschlagen: ' . $e->getMessage(), 'error', null];
        }

        // FUN-410-33: Caches und Sitemaps verwerfen; Erweiterungen können über den Hook eigene Daten bereinigen.
        try {
            if (class_exists('CMS\\CacheManager')) {
                \CMS\CacheManager::instance()->clear();
            }
            if (class_exists('CMS\\Services\\SEOService')) {
                \CMS\Services\SEOService::getInstance()->invalidateSavedSitemaps();
            }
            if (class_exists('CMS\\Hooks')) {
                \CMS\Hooks::doAction('cms_importer_content_reset');
            }
        } catch (\Throwable) {
        }

        $remainingPosts = $this->count_table_rows($db, $p . 'posts');
        $remainingPages = $this->count_table_rows($db, $p . 'pages');
        $deletedPosts = max(0, $plannedPosts - $remainingPosts);
        $deletedPages = max(0, $plannedPages - $remainingPages);

        $statusType = ($remainingPosts === 0 && $remainingPages === 0) ? 'success' : 'warning';

        $message = sprintf(
            'Globale Bereinigung abgeschlossen: %d/%d Beiträge und %d/%d Seiten entfernt.',
            $deletedPosts,
            $plannedPosts,
            $deletedPages,
            $plannedPages
        );

        $extras = [];
        if ($removedMappings > 0) {
            $extras[] = $removedMappings . ' Import-Mappings';
        }
        if ($removedSeo > 0) {
            $extras[] = $removedSeo . ' SEO-Metadaten';
        }
        if ($removedTagRelations > 0) {
            $extras[] = $removedTagRelations . ' Tag-Zuordnungen';
        }
        if ($extras !== []) {
            $message .= ' Zusätzlich bereinigt: ' . implode(', ', $extras) . '.';
        }

        if ($remainingPosts > 0 || $remainingPages > 0) {
            $message .= sprintf(
                ' Achtung: %d Beiträge und %d Seiten sind weiterhin vorhanden.',
                $remainingPosts,
                $remainingPages
            );
        }

        if ($resetSequences) {
            $sequenceNotice = $this->buildSequenceResetNotice($db, [
                $p . 'import_items' => 'Import-Mappings',
            ]);
            if ($sequenceNotice !== '') {
                $message .= ' ' . $sequenceNotice;
            }
        }

        return [$message, $statusType, [
            'planned_posts' => $plannedPosts,
            'planned_pages' => $plannedPages,
            'deleted_posts' => $deletedPosts,
            'deleted_pages' => $deletedPages,
            'mappings' => $removedMappings,
            'seo_meta' => $removedSeo,
            'tag_relations' => $removedTagRelations,
            'remaining_posts' => $remainingPosts,
            'remaining_pages' => $remainingPages,
        ]];
    }

    /**
     * @return array{0:string,1:string,2:array<string,int>|null}
     */
    private function cleanup_posts_entries(bool $resetSequences = false): array
    {
        if (!class_exists('CMS\Database')) {
            return ['CMS\\Database nicht verfügbar.', 'error', null];
        }

        $db = CMS\Database::instance();
        $p = $db->getPrefix();

        $plannedPosts = $this->count_table_rows($db, $p . 'posts');
        if ($plannedPosts === 0) {
            return ['Es sind aktuell keine Beiträge vorhanden. Es wurden keine Beiträge bereinigt.', 'warning', [
                'planned_posts' => 0,
                'deleted_posts' => 0,
                'comments' => 0,
                'tag_relations' => 0,
                'seo_meta' => 0,
                'mappings' => 0,
                'remaining_posts' => 0,
            ]];
        }

        $deletedComments = 0;
        $deletedTagRelations = 0;
        $deletedSeoMeta = 0;
        $deletedMappings = 0;

        try {
            if ($this->has_table($db, $p . 'comments') && $this->has_table($db, $p . 'posts')) {
                $deletedComments = (int) ($db->execute(
                    "DELETE c FROM {$p}comments c INNER JOIN {$p}posts p ON c.post_id = p.id"
                )?->rowCount() ?? 0);
            }

            if ($this->has_table($db, $p . 'post_tag_rel')) {
                $deletedTagRelations = (int) ($db->execute("DELETE FROM {$p}post_tag_rel")?->rowCount() ?? 0);
            }

            if ($this->has_table($db, $p . 'posts')) {
                $db->execute("DELETE FROM {$p}posts");
            }

            if ($this->has_table($db, $p . 'post_tags')) {
                $db->execute("UPDATE {$p}post_tags SET post_count = 0");
            }

            if ($this->has_table($db, $p . 'seo_meta')) {
                $deletedSeoMeta = (int) ($db->execute("DELETE FROM {$p}seo_meta WHERE content_type = ?", ['post'])?->rowCount() ?? 0);
            }

            if ($this->has_table($db, $p . 'import_items')) {
                $deletedMappings += (int) ($db->execute("DELETE FROM {$p}import_items WHERE target_type = ?", ['post'])?->rowCount() ?? 0);
                $deletedMappings += (int) ($db->execute("DELETE FROM {$p}import_items WHERE target_type = ?", ['comment'])?->rowCount() ?? 0);
            }
        } catch (\Throwable $e) {
            return ['Bereinigung der Beiträge fehlgeschlagen: ' . $e->getMessage(), 'error', null];
        }

        $remainingPosts = $this->count_table_rows($db, $p . 'posts');
        $deletedPosts = max(0, $plannedPosts - $remainingPosts);
        $message = sprintf('Beitrags-Bereinigung abgeschlossen: %d/%d Beiträge entfernt.', $deletedPosts, $plannedPosts);

        $extras = [];
        if ($deletedComments > 0) {
            $extras[] = $deletedComments . ' Kommentare';
        }
        if ($deletedTagRelations > 0) {
            $extras[] = $deletedTagRelations . ' Tag-Zuordnungen';
        }
        if ($deletedSeoMeta > 0) {
            $extras[] = $deletedSeoMeta . ' SEO-Metadaten';
        }
        if ($deletedMappings > 0) {
            $extras[] = $deletedMappings . ' Import-Mappings';
        }
        if ($extras !== []) {
            $message .= ' Zusätzlich bereinigt: ' . implode(', ', $extras) . '.';
        }

        if ($remainingPosts > 0) {
            $message .= ' Achtung: Es sind weiterhin ' . $remainingPosts . ' Beiträge vorhanden.';
        }

        if ($resetSequences) {
            $sequenceNotice = $this->buildSequenceResetNotice($db, [
                $p . 'import_items' => 'Import-Mappings',
            ]);
            if ($sequenceNotice !== '') {
                $message .= ' ' . $sequenceNotice;
            }
        }

        return [$message, $remainingPosts === 0 ? 'success' : 'warning', [
            'planned_posts' => $plannedPosts,
            'deleted_posts' => $deletedPosts,
            'comments' => $deletedComments,
            'tag_relations' => $deletedTagRelations,
            'seo_meta' => $deletedSeoMeta,
            'mappings' => $deletedMappings,
            'remaining_posts' => $remainingPosts,
        ]];
    }

    /**
     * @return array{0:string,1:string,2:array<string,int>|null}
     */
    private function cleanup_pages_entries(bool $resetSequences = false): array
    {
        if (!class_exists('CMS\Database')) {
            return ['CMS\\Database nicht verfügbar.', 'error', null];
        }

        $db = CMS\Database::instance();
        $p = $db->getPrefix();

        $plannedPages = $this->count_table_rows($db, $p . 'pages');
        if ($plannedPages === 0) {
            return ['Es sind aktuell keine Seiten vorhanden. Es wurden keine Seiten bereinigt.', 'warning', [
                'planned_pages' => 0,
                'deleted_pages' => 0,
                'seo_meta' => 0,
                'mappings' => 0,
                'remaining_pages' => 0,
            ]];
        }

        $deletedSeoMeta = 0;
        $deletedMappings = 0;

        try {
            if ($this->has_table($db, $p . 'pages')) {
                $db->execute("DELETE FROM {$p}pages");
            }

            if ($this->has_table($db, $p . 'seo_meta')) {
                $deletedSeoMeta = (int) ($db->execute("DELETE FROM {$p}seo_meta WHERE content_type = ?", ['page'])?->rowCount() ?? 0);
            }

            if ($this->has_table($db, $p . 'import_items')) {
                $deletedMappings = (int) ($db->execute("DELETE FROM {$p}import_items WHERE target_type = ?", ['page'])?->rowCount() ?? 0);
            }
        } catch (\Throwable $e) {
            return ['Bereinigung der Seiten fehlgeschlagen: ' . $e->getMessage(), 'error', null];
        }

        $remainingPages = $this->count_table_rows($db, $p . 'pages');
        $deletedPages = max(0, $plannedPages - $remainingPages);
        $message = sprintf('Seiten-Bereinigung abgeschlossen: %d/%d Seiten entfernt.', $deletedPages, $plannedPages);

        $extras = [];
        if ($deletedSeoMeta > 0) {
            $extras[] = $deletedSeoMeta . ' SEO-Metadaten';
        }
        if ($deletedMappings > 0) {
            $extras[] = $deletedMappings . ' Import-Mappings';
        }
        if ($extras !== []) {
            $message .= ' Zusätzlich bereinigt: ' . implode(', ', $extras) . '.';
        }

        if ($remainingPages > 0) {
            $message .= ' Achtung: Es sind weiterhin ' . $remainingPages . ' Seiten vorhanden.';
        }

        if ($resetSequences) {
            $sequenceNotice = $this->buildSequenceResetNotice($db, [
                $p . 'import_items' => 'Import-Mappings',
            ]);
            if ($sequenceNotice !== '') {
                $message .= ' ' . $sequenceNotice;
            }
        }

        return [$message, $remainingPages === 0 ? 'success' : 'warning', [
            'planned_pages' => $plannedPages,
            'deleted_pages' => $deletedPages,
            'seo_meta' => $deletedSeoMeta,
            'mappings' => $deletedMappings,
            'remaining_pages' => $remainingPages,
        ]];
    }

    /**
     * @return array{0:string,1:string,2:array<string,int>|null}
     */
    private function cleanup_tables_entries(bool $resetSequences = false): array
    {
        if (!class_exists('CMS\Database')) {
            return ['CMS\\Database nicht verfügbar.', 'error', null];
        }

        $db = CMS\Database::instance();
        $p = $db->getPrefix();

        $plannedTables = $this->count_table_rows($db, $p . 'site_tables');
        if ($plannedTables === 0) {
            return ['Es sind aktuell keine Tabellen vorhanden. Es wurden keine Tabellen bereinigt.', 'warning', [
                'planned_tables' => 0,
                'deleted_tables' => 0,
                'mappings' => 0,
                'remaining_tables' => 0,
            ]];
        }

        $deletedMappings = 0;

        try {
            if ($this->has_table($db, $p . 'site_tables')) {
                $db->execute("DELETE FROM {$p}site_tables");
                $this->reset_auto_increment($db, $p . 'site_tables');
            }

            if ($this->has_table($db, $p . 'import_items')) {
                $deletedMappings = (int) ($db->execute("DELETE FROM {$p}import_items WHERE target_type = ?", ['site_table'])?->rowCount() ?? 0);
            }
        } catch (\Throwable $e) {
            return ['Bereinigung der Tabellen fehlgeschlagen: ' . $e->getMessage(), 'error', null];
        }

        $remainingTables = $this->count_table_rows($db, $p . 'site_tables');
        $deletedTables = max(0, $plannedTables - $remainingTables);
        $message = sprintf('Tabellen-Bereinigung abgeschlossen: %d/%d Tabellen entfernt.', $deletedTables, $plannedTables);
        if ($deletedMappings > 0) {
            $message .= ' Zusätzlich bereinigt: ' . $deletedMappings . ' Import-Mappings.';
        }
        if ($remainingTables > 0) {
            $message .= ' Achtung: Es sind weiterhin ' . $remainingTables . ' Tabellen vorhanden.';
        }

        if ($resetSequences) {
            $sequenceNotice = $this->buildSequenceResetNotice($db, [
                $p . 'import_items' => 'Import-Mappings',
            ]);
            if ($sequenceNotice !== '') {
                $message .= ' ' . $sequenceNotice;
            }
        }

        return [$message, $remainingTables === 0 ? 'success' : 'warning', [
            'planned_tables' => $plannedTables,
            'deleted_tables' => $deletedTables,
            'mappings' => $deletedMappings,
            'remaining_tables' => $remainingTables,
        ]];
    }

    /**
     * @return array{0:string,1:string,2:array<string,int>|null}
     */
    private function cleanup_seo_entries(bool $resetSequences = false): array
    {
        if (!class_exists('CMS\Database')) {
            return ['CMS\\Database nicht verfügbar.', 'error', null];
        }

        $db = CMS\Database::instance();
        $p = $db->getPrefix();

        $plannedSettings = $this->count_setting_rows_like($db, $p . 'settings', 'seo\\_%');
        $plannedSeoMeta = $this->count_table_rows($db, $p . 'seo_meta');
        $plannedMappings = $this->count_target_type_rows($db, $p . 'import_items', 'setting_bundle');

        if ($plannedSettings === 0 && $plannedSeoMeta === 0 && $plannedMappings === 0) {
            return ['Es sind aktuell keine SEO-Datensätze vorhanden. Es wurden keine SEO-Daten bereinigt.', 'warning', [
                'planned_settings' => 0,
                'planned_seo_meta' => 0,
                'planned_mappings' => 0,
                'deleted_settings' => 0,
                'deleted_seo_meta' => 0,
                'deleted_mappings' => 0,
            ]];
        }

        $deletedSettings = 0;
        $deletedSeoMeta = 0;
        $deletedMappings = 0;

        try {
            if ($this->has_table($db, $p . 'settings')) {
                $deletedSettings = (int) ($db->execute("DELETE FROM {$p}settings WHERE option_name LIKE ? ESCAPE '\\\\'", ['seo\\_%'])?->rowCount() ?? 0);
            }

            if ($this->has_table($db, $p . 'seo_meta')) {
                $deletedSeoMeta = (int) ($db->execute("DELETE FROM {$p}seo_meta")?->rowCount() ?? 0);
            }

            if ($this->has_table($db, $p . 'import_items')) {
                $deletedMappings = (int) ($db->execute("DELETE FROM {$p}import_items WHERE target_type = ?", ['setting_bundle'])?->rowCount() ?? 0);
            }
        } catch (\Throwable $e) {
            return ['Bereinigung der SEO-Daten fehlgeschlagen: ' . $e->getMessage(), 'error', null];
        }

        $message = 'SEO-Bereinigung abgeschlossen.';
        $details = [];
        if ($deletedSettings > 0) {
            $details[] = $deletedSettings . ' globale SEO-Settings';
        }
        if ($deletedSeoMeta > 0) {
            $details[] = $deletedSeoMeta . ' SEO-Metadaten';
        }
        if ($deletedMappings > 0) {
            $details[] = $deletedMappings . ' Import-Mappings';
        }
        if ($details !== []) {
            $message .= ' Entfernt: ' . implode(', ', $details) . '.';
        }
        $message .= ' Redirect-Regeln bleiben dabei unberührt.';

        if ($resetSequences) {
            $sequenceNotice = $this->buildSequenceResetNotice($db, [
                $p . 'import_items' => 'Import-Mappings',
            ]);
            if ($sequenceNotice !== '') {
                $message .= ' ' . $sequenceNotice;
            }
        }

        return [$message, 'success', [
            'planned_settings' => $plannedSettings,
            'planned_seo_meta' => $plannedSeoMeta,
            'planned_mappings' => $plannedMappings,
            'deleted_settings' => $deletedSettings,
            'deleted_seo_meta' => $deletedSeoMeta,
            'deleted_mappings' => $deletedMappings,
        ]];
    }

    /**
     * @return array{0:string,1:string,2:array<string,int>|null}
     */
    private function cleanup_import_history(bool $resetSequences = false): array
    {
        if (!class_exists('CMS\Database')) {
            return ['CMS\\Database nicht verfügbar.', 'error', null];
        }

        $db = CMS\Database::instance();
        $p = $db->getPrefix();

        $removedLogs = $this->count_table_rows($db, $p . 'import_log');
        $removedMappings = $this->count_table_rows($db, $p . 'import_items');
        $removedMeta = $this->count_table_rows($db, $p . 'import_meta');
        $removedReports = 0;

        try {
            $removedReports = $this->cleanup_report_files($db, $p);

            if ($this->has_table($db, $p . 'import_meta')) {
                $db->execute("DELETE FROM {$p}import_meta");
            }

            if ($this->has_table($db, $p . 'import_items')) {
                $db->execute("DELETE FROM {$p}import_items");
            }

            if ($this->has_table($db, $p . 'import_log')) {
                $db->execute("DELETE FROM {$p}import_log");
            }
        } catch (\Throwable $e) {
            return ['Bereinigung des Import-Verlaufs fehlgeschlagen: ' . $e->getMessage(), 'error', null];
        }

        $message = sprintf(
            'Importer-Verlauf gelöscht: %d Protokolle, %d Mappings und %d Meta-Einträge entfernt.',
            $removedLogs,
            $removedMappings,
            $removedMeta
        );
        if ($removedReports > 0) {
            $message .= ' Zusätzlich wurden ' . $removedReports . ' Bericht-Dateien entfernt.';
        }

        if ($resetSequences) {
            $sequenceNotice = $this->buildSequenceResetNotice($db, [
                $p . 'import_log' => 'Import-Logs',
                $p . 'import_items' => 'Import-Mappings',
                $p . 'import_meta' => 'Import-Meta',
            ]);
            if ($sequenceNotice !== '') {
                $message .= ' ' . $sequenceNotice;
            }
        }

        return [$message, 'success', [
            'logs' => $removedLogs,
            'mappings' => $removedMappings,
            'meta' => $removedMeta,
            'reports' => $removedReports,
        ]];
    }

    private function has_table(\CMS\Database $db, string $tableName): bool
    {
        try {
            return (int) ($db->get_var(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [defined('DB_NAME') ? DB_NAME : '', $tableName]
            ) ?? 0) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function count_table_rows(\CMS\Database $db, string $tableName): int
    {
        if (!$this->has_table($db, $tableName)) {
            return 0;
        }

        try {
            return (int) ($db->get_var("SELECT COUNT(*) FROM {$tableName}") ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function count_setting_rows_like(\CMS\Database $db, string $tableName, string $pattern): int
    {
        if (!$this->has_table($db, $tableName)) {
            return 0;
        }

        try {
            return (int) ($db->get_var("SELECT COUNT(*) FROM {$tableName} WHERE option_name LIKE ? ESCAPE '\\\\'", [$pattern]) ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function count_target_type_rows(\CMS\Database $db, string $tableName, string $targetType): int
    {
        if (!$this->has_table($db, $tableName)) {
            return 0;
        }

        try {
            return (int) ($db->get_var("SELECT COUNT(*) FROM {$tableName} WHERE target_type = ?", [$targetType]) ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function reset_auto_increment(\CMS\Database $db, string $tableName, int $nextValue = 1): bool
    {
        if (!$this->has_table($db, $tableName)) {
            return false;
        }

        try {
            $db->execute("ALTER TABLE {$tableName} AUTO_INCREMENT = " . max(1, $nextValue));
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,string> $tableLabels
     */
    private function buildSequenceResetNotice(\CMS\Database $db, array $tableLabels): string
    {
        $reset = [];
        $skipped = [];

        foreach ($tableLabels as $tableName => $label) {
            if (!$this->has_table($db, $tableName)) {
                continue;
            }

            if ($this->count_table_rows($db, $tableName) === 0) {
                if ($this->reset_auto_increment($db, $tableName)) {
                    $reset[] = $label;
                }
            } else {
                $skipped[] = $label;
            }
        }

        $parts = [];
        if ($reset !== []) {
            $parts[] = 'ID-Zähler zurückgesetzt für: ' . implode(', ', $reset) . '.';
        }
        if ($skipped !== []) {
            $parts[] = 'Nicht zurückgesetzt (noch Einträge vorhanden): ' . implode(', ', $skipped) . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * @return array{post_ids:array<int,int>,page_ids:array<int,int>,stale_mappings:int}
     */
    private function get_imported_content_targets(\CMS\Database $db, string $p): array
    {
        $postIds = [];
        $pageIds = [];
        $staleMappings = 0;

        if (!$this->has_table($db, $p . 'import_items')) {
            return [
                'post_ids' => [],
                'page_ids' => [],
                'stale_mappings' => 0,
            ];
        }

        $hasTargetCreatedColumn = $this->has_column($db, $p . 'import_items', 'target_created');
        $targetCreatedFilter = $hasTargetCreatedColumn ? ' AND target_created = 1' : '';

        $rows = $db->get_results(
            "SELECT target_type, target_id FROM {$p}import_items WHERE target_type IN ('post', 'page') AND target_id IS NOT NULL{$targetCreatedFilter}"
        ) ?? [];

        if ($rows === [] && $hasTargetCreatedColumn) {
            $legacyRows = $db->get_results(
                "SELECT target_type, target_id FROM {$p}import_items WHERE target_type IN ('post', 'page') AND target_id IS NOT NULL"
            ) ?? [];

            if ($legacyRows !== []) {
                $rows = $legacyRows;
                $targetCreatedFilter = '';
            }
        }

        if ($rows === []) {
            $this->backfill_import_mappings_from_sources($db, $p);

            $rows = $db->get_results(
                "SELECT target_type, target_id FROM {$p}import_items WHERE target_type IN ('post', 'page') AND target_id IS NOT NULL"
            ) ?? [];

            if ($rows !== []) {
                $targetCreatedFilter = '';
            }
        }

        foreach ($rows as $row) {
            $targetType = (string) ($row->target_type ?? '');
            $targetId = (int) ($row->target_id ?? 0);
            if ($targetId <= 0) {
                continue;
            }

            if ($targetType === 'post') {
                $postIds[$targetId] = $targetId;
            } elseif ($targetType === 'page') {
                $pageIds[$targetId] = $targetId;
            }
        }

        $postIds = $this->filter_existing_ids($db, $p . 'posts', array_values($postIds));
        $pageIds = $this->filter_existing_ids($db, $p . 'pages', array_values($pageIds));

        $postMappingCount = (int) ($db->get_var("SELECT COUNT(*) FROM {$p}import_items WHERE target_type = 'post' AND target_id IS NOT NULL{$targetCreatedFilter}") ?? 0);
        $pageMappingCount = (int) ($db->get_var("SELECT COUNT(*) FROM {$p}import_items WHERE target_type = 'page' AND target_id IS NOT NULL{$targetCreatedFilter}") ?? 0);

        $existingPostMappingCount = $postIds === [] ? 0 : (int) ($db->get_var(
            'SELECT COUNT(*) FROM ' . $p . 'import_items WHERE target_type = ? AND target_id IN (' . implode(',', array_fill(0, count($postIds), '?')) . ')',
            array_merge(['post'], $postIds)
        ) ?? 0);

        $existingPageMappingCount = $pageIds === [] ? 0 : (int) ($db->get_var(
            'SELECT COUNT(*) FROM ' . $p . 'import_items WHERE target_type = ? AND target_id IN (' . implode(',', array_fill(0, count($pageIds), '?')) . ')',
            array_merge(['page'], $pageIds)
        ) ?? 0);

        $staleMappings = max(0, ($postMappingCount - $existingPostMappingCount) + ($pageMappingCount - $existingPageMappingCount));

        return [
            'post_ids' => $postIds,
            'page_ids' => $pageIds,
            'stale_mappings' => $staleMappings,
        ];
    }

    private function backfill_import_mappings_from_sources(\CMS\Database $db, string $p): void
    {
        if (!$this->has_table($db, $p . 'import_log') || !$this->has_table($db, $p . 'import_items')) {
            return;
        }

        $parser = $this->resolve_importer_xml_parser();
        if ($parser === null || !method_exists($parser, 'parse')) {
            return;
        }

        $logs = $db->get_results("SELECT id, filename FROM {$p}import_log ORDER BY id DESC") ?? [];
        if ($logs === []) {
            return;
        }

        $sourceFiles = $this->get_importer_source_files();
        if ($sourceFiles === []) {
            return;
        }

        $processed = [];
        foreach ($logs as $log) {
            $filename = trim((string) ($log->filename ?? ''));
            if ($filename === '' || isset($processed[$filename]) || !isset($sourceFiles[$filename])) {
                continue;
            }

            $processed[$filename] = true;
            $parsed = $parser->parse($sourceFiles[$filename]);
            if (!is_array($parsed) || !empty($parsed['errors'])) {
                continue;
            }

            foreach (($parsed['posts'] ?? []) as $item) {
                if (is_array($item)) {
                    $this->backfill_imported_content_mapping($db, $p, $item, 'post', 'post', (int) ($log->id ?? 0));
                }
            }

            foreach (($parsed['pages'] ?? []) as $item) {
                if (is_array($item)) {
                    $this->backfill_imported_content_mapping($db, $p, $item, 'page', 'page', (int) ($log->id ?? 0));
                }
            }
        }
    }

    private function resolve_importer_xml_parser(): ?object
    {
        if (class_exists('CMS_Importer_XML_Parser')) {
            return new \CMS_Importer_XML_Parser();
        }

        $pluginDir = $this->resolve_importer_plugin_dir();
        if ($pluginDir === '' || !is_file($pluginDir . 'includes/class-xml-parser.php')) {
            return null;
        }

        require_once $pluginDir . 'includes/class-xml-parser.php';
        return class_exists('CMS_Importer_XML_Parser') ? new \CMS_Importer_XML_Parser() : null;
    }

    private function resolve_importer_plugin_dir(): string
    {
        $candidates = [];

        if (defined('CMS_IMPORTER_PLUGIN_DIR')) {
            $candidates[] = (string) CMS_IMPORTER_PLUGIN_DIR;
        }

        if (defined('PLUGIN_PATH')) {
            $candidates[] = rtrim((string) PLUGIN_PATH, '/\\') . '/cms-importer/';
        }

        $candidates[] = dirname(__DIR__) . '/cms-importer/';

        foreach ($candidates as $candidate) {
            $normalized = rtrim(str_replace('\\', '/', $candidate), '/') . '/';
            if (is_dir($normalized)) {
                return $normalized;
            }
        }

        return '';
    }

    /**
     * @return array<string,string>
     */
    private function get_importer_source_files(): array
    {
        $files = [];

        $pluginDir = $this->resolve_importer_plugin_dir();
        if ($pluginDir !== '' && is_dir($pluginDir . 'wp_import_files/')) {
            foreach (['*.xml', '*.json'] as $pattern) {
                foreach (glob($pluginDir . 'wp_import_files/' . $pattern) ?: [] as $path) {
                    $files[basename($path)] = str_replace('\\', '/', $path);
                }
            }
        }

        if ($pluginDir !== '' && is_dir($pluginDir . 'wp_import/')) {
            foreach (['*.xml', '*.json'] as $pattern) {
                foreach (glob($pluginDir . 'wp_import/' . $pattern) ?: [] as $path) {
                    $files[basename($path)] = str_replace('\\', '/', $path);
                }
            }
        }

        $importDir = $this->get_import_dir();
        if ($importDir !== '' && is_dir($importDir)) {
            foreach (['*.xml', '*.json'] as $pattern) {
                foreach (glob($importDir . $pattern) ?: [] as $path) {
                    $files[basename($path)] = str_replace('\\', '/', $path);
                }
            }
        }

        return $files;
    }

    private function backfill_imported_content_mapping(\CMS\Database $db, string $p, array $item, string $sourceType, string $targetType, int $logId): bool
    {
        $sourceWpId = (int) ($item['wp_id'] ?? 0);
        if ($sourceWpId <= 0) {
            return false;
        }

        $existing = (int) ($db->get_var(
            "SELECT id FROM {$p}import_items WHERE source_type = ? AND source_wp_id = ? AND target_type = ? LIMIT 1",
            [$sourceType, $sourceWpId, $targetType]
        ) ?? 0);
        if ($existing > 0) {
            return true;
        }

        $sourceReference = $this->resolve_cleanup_source_reference($item);
        $desiredSlug = $this->resolve_cleanup_desired_slug($item, $sourceReference);

        $match = $this->find_existing_content_match($db, $p, $targetType, $desiredSlug, (string) ($item['title'] ?? ''), (string) ($item['date'] ?? ''));
        if ($match === null) {
            return false;
        }

        $targetUrl = $targetType === 'post'
            ? (class_exists('\\CMS\\Services\\PermalinkService')
                ? \CMS\Services\PermalinkService::getInstance()->buildPostUrlFromValues((string) ($match['slug'] ?? ''), (string) ($match['published_at'] ?? ''), (string) ($match['created_at'] ?? ''))
                : (defined('SITE_URL') ? rtrim((string) SITE_URL, '/') . '/blog/' . ltrim((string) ($match['slug'] ?? ''), '/') : null))
            : (defined('SITE_URL') ? rtrim((string) SITE_URL, '/') . '/' . ltrim((string) ($match['slug'] ?? ''), '/') : null);

        $inserted = $db->insert('import_items', [
            'log_id' => $logId > 0 ? $logId : null,
            'source_type' => $sourceType,
            'source_wp_id' => $sourceWpId,
            'source_reference' => $sourceReference !== '' ? $sourceReference : null,
            'source_slug' => (string) ($item['slug'] ?? ''),
            'source_url' => (string) ($item['link'] ?? ''),
            'target_type' => $targetType,
            'target_id' => (int) ($match['id'] ?? 0),
            'target_created' => 1,
            'target_slug' => (string) ($match['slug'] ?? ''),
            'target_url' => $targetUrl,
        ]);

        return $inserted !== false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function find_existing_content_match(\CMS\Database $db, string $p, string $targetType, string $desiredSlug, string $title, string $sourceDate): ?array
    {
        $table = $targetType === 'post' ? 'posts' : 'pages';
        $hasTitleEn = $this->has_column($db, $p . $table, 'title_en');

        if ($desiredSlug !== '' && $this->has_table($db, $p . $table)) {
            $slugCondition = 'slug = ?';
            $params = [$desiredSlug];

            if ($this->has_column($db, $p . $table, 'slug_en')) {
                $slugCondition .= ' OR slug_en = ?';
                $params[] = $desiredSlug;
            }

            $row = $db->get_row(
                "SELECT id, slug, created_at, published_at FROM {$p}{$table} WHERE {$slugCondition} LIMIT 1",
                $params
            );
            if ($row !== null) {
                return (array) $row;
            }
        }

        $title = trim($title);
        if ($title === '' || !$this->has_table($db, $p . $table)) {
            return null;
        }

        if ($hasTitleEn) {
            $rows = $db->get_results(
                "SELECT id, slug, created_at, published_at FROM {$p}{$table} WHERE title = ? OR title_en = ? ORDER BY id ASC LIMIT 10",
                [$title, $title]
            ) ?: [];
        } else {
            $rows = $db->get_results(
                "SELECT id, slug, created_at, published_at FROM {$p}{$table} WHERE title = ? ORDER BY id ASC LIMIT 10",
                [$title]
            ) ?: [];
        }

        if (count($rows) === 1) {
            return (array) $rows[0];
        }

        if ($sourceDate !== '') {
            $sourceDay = substr($sourceDate, 0, 10);
            $sameDay = [];
            foreach ($rows as $row) {
                $candidateDate = $targetType === 'post'
                    ? (string) ($row->published_at ?? $row->created_at ?? '')
                    : (string) ($row->created_at ?? '');
                if ($candidateDate !== '' && substr($candidateDate, 0, 10) === $sourceDay) {
                    $sameDay[] = (array) $row;
                }
            }

            if (count($sameDay) === 1) {
                return $sameDay[0];
            }
        }

        return null;
    }

    private function resolve_cleanup_desired_slug(array $item, string $sourceReference): string
    {
        $sourceReferenceSlug = $this->extract_cleanup_reference_slug($sourceReference);
        if ($sourceReferenceSlug !== '') {
            return $sourceReferenceSlug;
        }

        if (class_exists('\\CMS\\Services\\PermalinkService')) {
            return \CMS\Services\PermalinkService::resolveImportedSourceSlug(
                (string) ($item['slug'] ?? ''),
                (string) ($item['link'] ?? '')
            );
        }

        return trim((string) ($item['slug'] ?? ''));
    }

    private function resolve_cleanup_source_reference(array $item): string
    {
        foreach ([(string) ($item['guid'] ?? ''), (string) ($item['link'] ?? '')] as $candidate) {
            $reference = $this->normalize_cleanup_reference_from_url($candidate);
            if ($reference !== '') {
                return $reference;
            }
        }

        return $this->normalize_cleanup_reference_path((string) ($item['slug'] ?? ''));
    }

    private function normalize_cleanup_reference_from_url(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return '';
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $this->normalize_cleanup_reference_path($url);
        }

        return $this->normalize_cleanup_reference_path((string) parse_url($url, PHP_URL_PATH));
    }

    private function normalize_cleanup_reference_path(string $path): string
    {
        $path = trim(html_entity_decode($path, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $path = trim($path, "/ \t\n\r\0\x0B");
        if ($path === '') {
            return '';
        }

        $segments = array_values(array_filter(
            explode('/', $path),
            static fn(string $segment): bool => trim($segment) !== ''
        ));

        while ($segments !== [] && strtolower((string) ($segments[0] ?? '')) === 'en') {
            array_shift($segments);
        }

        while ($segments !== [] && strtolower((string) ($segments[count($segments) - 1] ?? '')) === 'en') {
            array_pop($segments);
        }

        $normalized = [];
        foreach ($segments as $segment) {
            $segment = trim(rawurldecode((string) $segment));
            if ($segment !== '') {
                $normalized[] = $segment;
            }
        }

        return implode('/', $normalized);
    }

    private function extract_cleanup_reference_slug(string $reference): string
    {
        $reference = trim($reference, '/');
        if ($reference === '') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $reference), static fn(string $segment): bool => trim($segment) !== ''));
        if ($segments === []) {
            return '';
        }

        return (string) end($segments);
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,int>
     */
    private function filter_existing_ids(\CMS\Database $db, string $tableName, array $ids): array
    {
        if ($ids === [] || !$this->has_table($db, $tableName)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $results = $db->get_col("SELECT id FROM {$tableName} WHERE id IN ({$placeholders})", $ids);
        return array_map('intval', $results);
    }

    private function has_column(\CMS\Database $db, string $tableName, string $columnName): bool
    {
        if (!$this->has_table($db, $tableName)) {
            return false;
        }

        try {
            $stmt = $db->query("SHOW COLUMNS FROM {$tableName} LIKE '{$columnName}'");
            return $stmt instanceof \PDOStatement && (bool) $stmt->fetch();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<int,int> $ids
     */
    private function delete_rows_by_ids(\CMS\Database $db, string $tableName, string $column, array $ids): int
    {
        if ($ids === [] || !$this->has_table($db, $tableName)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM {$tableName} WHERE {$column} IN ({$placeholders})";
        $stmt = $db->execute($sql, $ids);
        return $stmt->rowCount();
    }

    /**
     * @param array<int,int> $ids
     */
    private function delete_rows_by_content_ids(\CMS\Database $db, string $tableName, string $contentType, array $ids): int
    {
        if ($ids === [] || !$this->has_table($db, $tableName)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$contentType], $ids);
        $stmt = $db->execute(
            "DELETE FROM {$tableName} WHERE content_type = ? AND content_id IN ({$placeholders})",
            $params
        );
        return $stmt->rowCount();
    }

    /**
     * @param array<int,int> $ids
     */
    private function delete_rows_by_target_ids(\CMS\Database $db, string $tableName, string $targetType, array $ids): int
    {
        if ($ids === [] || !$this->has_table($db, $tableName)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$targetType], $ids);
        $stmt = $db->execute(
            "DELETE FROM {$tableName} WHERE target_type = ? AND target_id IN ({$placeholders})",
            $params
        );
        return $stmt->rowCount();
    }

    private function delete_stale_import_mappings(\CMS\Database $db, string $p): int
    {
        if (!$this->has_table($db, $p . 'import_items')) {
            return 0;
        }

        $stmt = $db->execute(
            "DELETE ii FROM {$p}import_items ii
             LEFT JOIN {$p}posts p ON ii.target_type = 'post' AND ii.target_id = p.id
             LEFT JOIN {$p}pages pg ON ii.target_type = 'page' AND ii.target_id = pg.id
             WHERE ii.target_type IN ('post', 'page')
               AND ((ii.target_type = 'post' AND p.id IS NULL) OR (ii.target_type = 'page' AND pg.id IS NULL))"
        );

        return $stmt->rowCount();
    }

    /**
     * @param array<int,int> $ids
     */
    private function count_existing_ids(\CMS\Database $db, string $tableName, array $ids): int
    {
        if ($ids === [] || !$this->has_table($db, $tableName)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return (int) ($db->get_var("SELECT COUNT(*) FROM {$tableName} WHERE id IN ({$placeholders})", $ids) ?? 0);
    }

    private function count_report_files(): int
    {
        $reportDir = CMS_IMPORTER_PLUGIN_DIR . 'reports/';
        if (!is_dir($reportDir)) {
            return 0;
        }

        $files = glob($reportDir . '*.{md,html}', GLOB_BRACE);
        return is_array($files) ? count($files) : 0;
    }

    private function cleanup_report_files(\CMS\Database $db, string $p): int
    {
        $deleted = 0;
        $paths = [];

        if ($this->has_table($db, $p . 'import_log')) {
            $rows = $db->get_results("SELECT meta_report_path FROM {$p}import_log WHERE meta_report_path IS NOT NULL AND meta_report_path != ''") ?? [];
            foreach ($rows as $row) {
                $path = trim((string) ($row->meta_report_path ?? ''));
                if ($path === '') {
                    continue;
                }
                $paths[$path] = $path;
                $htmlPath = preg_replace('/\.md$/i', '.html', $path) ?? '';
                if ($htmlPath !== '') {
                    $paths[$htmlPath] = $htmlPath;
                }
            }
        }

        $reportDir = CMS_IMPORTER_PLUGIN_DIR . 'reports/';
        if (is_dir($reportDir)) {
            $globbed = glob($reportDir . '*.{md,html}', GLOB_BRACE);
            if (is_array($globbed)) {
                foreach ($globbed as $file) {
                    $paths[$file] = $file;
                }
            }
        }

        foreach ($paths as $path) {
            if (is_file($path) && @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
