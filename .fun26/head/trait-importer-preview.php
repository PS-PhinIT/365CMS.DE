<?php
/**
 * CMS WordPress Importer – Preview-/Planungshilfen
 *
 * @package CMS_Importer
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('CMS_IMPORTER_SERVICE_PREVIEW_TRAIT_LOADED') || trait_exists('CMS_Importer_Service_Preview_Trait', false)) {
    return;
}

define('CMS_IMPORTER_SERVICE_PREVIEW_TRAIT_LOADED', true);

trait CMS_Importer_Service_Preview_Trait
{
    /**
     * @param array<string, mixed> $settingsBundle
     * @return array<string, mixed>
     */
    private function build_settings_preview(array $settingsBundle): array
    {
        $settings = is_array($settingsBundle['settings'] ?? null) ? $settingsBundle['settings'] : [];
        $mappedFields = is_array($settingsBundle['mapped_fields'] ?? null) ? $settingsBundle['mapped_fields'] : [];

        $labels = [];
        foreach ($mappedFields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $label = trim((string) ($field['label'] ?? ''));
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        if ($labels === []) {
            $labels = array_keys($settings);
        }

        return [
            'action' => $settings !== [] ? 'import' : 'skip',
            'reason' => $settings !== [] ? '' : 'Keine importierbaren SEO-Settings erkannt',
            'source_type' => 'rank_math_settings',
            'source_label' => 'SEO-Settings',
            'source_wp_id' => 0,
            'source_title' => 'Rank Math SEO-Defaults',
            'source_status' => 'bereit',
            'target_group' => 'settings',
            'target_type' => 'setting_bundle',
            'target_slug' => 'seo-settings',
            'target_url' => '',
            'target_hint' => 'Wird in globale 365CMS-SEO-Einstellungen geschrieben',
            'image_candidates' => 0,
            'featured_image' => '',
            'table_shortcodes_found' => 0,
            'table_shortcodes_resolved' => 0,
            'table_targets' => [],
            'unknown_meta_count' => 0,
            'category' => '',
            'tags' => [],
            'settings_keys_count' => count($settings),
            'settings_labels' => $labels,
        ];
    }

    /**
     * @param array<string, mixed> $preview
     * @param array<string, mixed> $context
     */
    private function collect_preview_item(array $preview, array &$context): void
    {
        $context['items_total']++;

        if (($preview['action'] ?? '') === 'import') {
            $context['would_import']++;
            $type = (string) ($preview['target_group'] ?? 'others');
            if (isset($context['breakdown'][$type])) {
                $context['breakdown'][$type]++;
            }
            $context['images_detected'] += (int) ($preview['image_candidates'] ?? 0);
            $context['table_shortcodes_found'] += (int) ($preview['table_shortcodes_found'] ?? 0);
            $context['table_shortcodes_resolved'] += (int) ($preview['table_shortcodes_resolved'] ?? 0);
        } else {
            $context['would_skip']++;
            $reason = $this->normalize_skip_reason((string) ($preview['reason'] ?? ''));
            $context['skip_reasons'][$reason] = (int) ($context['skip_reasons'][$reason] ?? 0) + 1;
        }

        $context['comments_total'] += (int) ($preview['comments_total'] ?? 0);
        $context['comments_would_import'] += (int) ($preview['comments_importable'] ?? 0);
        $context['comments_would_skip'] += (int) ($preview['comments_skipped'] ?? 0);

        if (count($context['preview_items']) < (int) ($context['preview_limit'] ?? 25)) {
            $context['preview_items'][] = $preview;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function prioritize_items_by_locale(array $items): array
    {
        $primary = [];
        $localized = [];

        foreach ($items as $item) {
            if ($this->resolve_item_locale($item) === 'de') {
                $primary[] = $item;
                continue;
            }

            $localized[] = $item;
        }

        return array_merge($primary, $localized);
    }

    private function resolve_item_locale(array $item): string
    {
        $locale = strtolower(trim((string) ($item['locale'] ?? '')));
        if ($locale !== '') {
            return $locale;
        }

        $translationPriority = strtolower(trim((string) ($item['translation_priority'] ?? '')));
        if ($translationPriority !== '' && str_ends_with($translationPriority, '-en')) {
            return 'en';
        }

        foreach ([(string) ($item['link'] ?? ''), (string) ($item['guid'] ?? '')] as $candidateUrl) {
            $path = filter_var($candidateUrl, FILTER_VALIDATE_URL) !== false
                ? (string) parse_url($candidateUrl, PHP_URL_PATH)
                : $candidateUrl;
            $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $segment): bool => trim($segment) !== ''));
            if ($segments === []) {
                continue;
            }

            $firstSegment = strtolower((string) ($segments[0] ?? ''));
            $lastSegment = strtolower((string) ($segments[count($segments) - 1] ?? ''));
            if ($firstSegment === 'en' || $lastSegment === 'en') {
                return 'en';
            }
        }

        return 'de';
    }

    private function should_skip_item_by_locale_filter(array $item): bool
    {
        if (empty($this->options['import_only_en'])) {
            return false;
        }

        return $this->resolve_item_locale($item) !== 'en';
    }

    private function get_locale_filter_skip_reason(array $item): string
    {
        if (!empty($this->options['import_only_en']) && $this->resolve_item_locale($item) !== 'en') {
            return 'Nur /en/-Inhalte ausgewählt';
        }

        return 'Element entspricht nicht dem aktiven Sprachfilter';
    }

    private function is_locale_filter_skip(string $action, string $reason, string $locale): bool
    {
        return $action === 'skip'
            && $reason === 'Nur /en/-Inhalte ausgewählt'
            && strtolower(trim($locale)) !== 'en';
    }

    private function build_locale_filter_skip_hint(string $locale): string
    {
        $locale = strtolower(trim($locale));
        $label = $locale !== '' ? strtoupper($locale) : 'DE';

        return 'Kein Importziel – vom /en/-Filter ausgeschlossen (erkannte Sprache: ' . $label . ')';
    }

    private function resolve_target_slug(array $item, string $fallbackTitle): string
    {
        return $this->resolve_import_slug($item, $fallbackTitle);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function build_post_preview(\CMS\Database $db, string $p, array $item, bool $isCustomType, array &$context): array
    {
        $status = self::STATUS_MAP[$item['post_status']] ?? 'draft';
        $sourceType = (string) ($item['post_type'] ?? 'post');
        $sourceReference = $this->resolve_content_source_reference($item);
        $locale = $this->resolve_item_locale($item);
        $reason = '';
        $action = 'import';
        $existingMapping = null;
        $localizedMerge = false;

        if ($this->should_skip_item_by_locale_filter($item)) {
            $action = 'skip';
            $reason = $this->get_locale_filter_skip_reason($item);
        } elseif ($isCustomType && !$this->options['import_custom_types']) {
            $action = 'skip';
            $reason = 'Custom Post Types deaktiviert';
        } elseif ($status === 'trash' && !$this->options['import_trashed']) {
            $action = 'skip';
            $reason = 'Papierkorb-Elemente deaktiviert';
        } elseif ($status === 'draft' && !$this->options['import_drafts']) {
            $action = 'skip';
            $reason = 'Entwürfe deaktiviert';
        }

        $baseSlug = $this->resolve_target_slug($item, (string) ($item['title'] ?? ''));

        if ($action === 'import' && $this->options['skip_duplicates']) {
            $existingMapping = $this->find_existing_mapping_by_wp_id($db, $p, $sourceType, (int) ($item['wp_id'] ?? 0), 'post');
            if ($this->should_ignore_existing_source_mapping($existingMapping, $baseSlug, $sourceReference)) {
                $existingMapping = null;
            }

            if ($existingMapping !== null) {
                $action = 'skip';
                $reason = 'Bereits per Import-Mapping vorhanden';
            }
        }

        if ($action === 'import' && $locale !== 'de') {
            $localizedTarget = $this->find_preferred_localized_content_target($db, $p, 'post', $sourceType, $sourceReference, $baseSlug);
            $localizedMerge = $localizedTarget !== null || !empty($context['reserved_slugs']['post'][$baseSlug]);
        }

        $targetSlug = $baseSlug;
        if ($action === 'import') {
            if ($localizedMerge) {
                $targetSlug = $baseSlug;
                $this->reserve_preview_slug('post', $targetSlug, $context);
            } elseif ($this->options['skip_duplicates']) {
                if ($this->preview_slug_exists($db, $p . 'posts', $baseSlug, 'post', $context)) {
                    $action = 'skip';
                    $reason = 'Slug bereits vorhanden';
                } else {
                    $this->reserve_preview_slug('post', $baseSlug, $context);
                    $targetSlug = $baseSlug;
                }
            } else {
                $targetSlug = $this->preview_unique_slug($db, $p . 'posts', $baseSlug, 'post', $context, !empty($item['slug']));
            }
        }

        $contentPreview = $this->preview_content_payload($db, $p, $item, $context);
        $commentPreview = $this->preview_comment_summary(
            $db,
            $p,
            $item,
            $action === 'import' || $existingMapping !== null || $reason === 'Slug bereits vorhanden',
            true
        );
        $categories = $this->normalize_tag_names($item['categories'] ?? []);
        $categoryName = trim((string) ($categories[0] ?? ''));
        $tagNames = $this->normalize_tag_names($item['tags'] ?? []);
        $authorPreview = $this->resolve_import_author_preview($db, $p, $item);
        $fallbackTaxonomies = $this->get_taxonomy_fallback_meta_entries($item, 'post');
        $targetHint = $isCustomType
            ? 'Wird als CMS-Beitrag importiert'
            : 'Wird in cms_posts geschrieben';
        if ($localizedMerge && $locale !== 'de') {
            $targetHint = 'Aktualisiert vorhandenen CMS-Beitrag als /' . $locale . '/-Variante';
        }
        if ($fallbackTaxonomies !== []) {
            $targetHint .= ' · Zusätzliche WordPress-Kategorien werden im Meta-Bericht gesichert';
        }
        $this->collect_unknown_meta($item);

        if ($action === 'skip') {
            $reason = $this->normalize_skip_reason($reason);
        }

        $targetUrl = $this->build_target_url('post', $targetSlug, 0, (string) ($item['date'] ?? ''), $locale);
        if ($this->is_locale_filter_skip($action, $reason, $locale)) {
            $targetSlug = '';
            $targetUrl = null;
            $targetHint = $this->build_locale_filter_skip_hint($locale);
        }

        return [
            'action' => $action,
            'reason' => $reason,
            'detected_locale' => $locale,
            'source_type' => $sourceType,
            'source_label' => $isCustomType ? 'Custom Type' : 'Beitrag',
            'source_wp_id' => (int) ($item['wp_id'] ?? 0),
            'source_title' => (string) ($item['title'] ?? ''),
            'source_status' => (string) ($item['post_status'] ?? ''),
            'target_group' => $isCustomType ? 'others' : 'posts',
            'target_type' => 'post',
            'target_slug' => $targetSlug,
            'target_url' => $targetUrl,
            'target_hint' => $targetHint,
            'category' => $categoryName,
            'tags' => $tagNames,
            'author_label' => (string) ($authorPreview['author_label'] ?? ''),
            'author_display_name' => (string) ($authorPreview['author_display_name'] ?? ''),
            'image_candidates' => (int) ($contentPreview['image_candidates'] ?? 0),
            'featured_image' => (string) ($contentPreview['featured_image'] ?? ''),
            'table_shortcodes_found' => (int) ($contentPreview['table_shortcodes_found'] ?? 0),
            'table_shortcodes_resolved' => (int) ($contentPreview['table_shortcodes_resolved'] ?? 0),
            'table_targets' => $contentPreview['table_targets'] ?? [],
            'comments_total' => (int) ($commentPreview['total'] ?? 0),
            'comments_importable' => (int) ($commentPreview['importable'] ?? 0),
            'comments_skipped' => (int) ($commentPreview['skipped'] ?? 0),
            'unknown_meta_count' => $this->count_unknown_meta_for_item($item, 'post'),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function build_page_preview(\CMS\Database $db, string $p, array $item, array &$context): array
    {
        $status = self::STATUS_MAP[$item['post_status']] ?? 'draft';
        $sourceReference = $this->resolve_content_source_reference($item);
        $locale = $this->resolve_item_locale($item);
        $reason = '';
        $action = 'import';
        $existingMapping = null;
        $localizedMerge = false;

        if ($this->should_skip_item_by_locale_filter($item)) {
            $action = 'skip';
            $reason = $this->get_locale_filter_skip_reason($item);
        } elseif ($status === 'trash' && !$this->options['import_trashed']) {
            $action = 'skip';
            $reason = 'Papierkorb-Elemente deaktiviert';
        } elseif ($status === 'draft' && !$this->options['import_drafts']) {
            $action = 'skip';
            $reason = 'Entwürfe deaktiviert';
        }

        $baseSlug = $this->resolve_target_slug($item, (string) ($item['title'] ?? ''));

        if ($action === 'import' && $this->options['skip_duplicates']) {
            $existingMapping = $this->find_existing_mapping_by_wp_id($db, $p, 'page', (int) ($item['wp_id'] ?? 0), 'page');
            if ($this->should_ignore_existing_source_mapping($existingMapping, $baseSlug, $sourceReference)) {
                $existingMapping = null;
            }

            if ($existingMapping !== null) {
                $action = 'skip';
                $reason = 'Bereits per Import-Mapping vorhanden';
            }
        }

        if ($action === 'import' && $locale !== 'de') {
            $localizedTarget = $this->find_preferred_localized_content_target($db, $p, 'page', 'page', $sourceReference, $baseSlug);
            $localizedMerge = $localizedTarget !== null || !empty($context['reserved_slugs']['page'][$baseSlug]);
        }

        $targetSlug = $baseSlug;
        if ($action === 'import') {
            if ($localizedMerge) {
                $targetSlug = $baseSlug;
                $this->reserve_preview_slug('page', $targetSlug, $context);
            } elseif ($this->options['skip_duplicates']) {
                if ($this->preview_slug_exists($db, $p . 'pages', $baseSlug, 'page', $context)) {
                    $action = 'skip';
                    $reason = 'Slug bereits vorhanden';
                } else {
                    $this->reserve_preview_slug('page', $baseSlug, $context);
                    $targetSlug = $baseSlug;
                }
            } else {
                $targetSlug = $this->preview_unique_slug($db, $p . 'pages', $baseSlug, 'page', $context, !empty($item['slug']));
            }
        }

        $contentPreview = $this->preview_content_payload($db, $p, $item, $context);
        $commentPreview = $this->preview_comment_summary($db, $p, $item, false, false);
        $pageCategories = $this->normalize_tag_names($item['categories'] ?? []);
        $pageTags = $this->normalize_tag_names($item['tags'] ?? []);
        $pageFallbackMeta = $this->get_taxonomy_fallback_meta_entries($item, 'page');
        $this->collect_unknown_meta($item);

        if ($action === 'skip') {
            $reason = $this->normalize_skip_reason($reason);
        }

        $targetHint = $localizedMerge && $locale !== 'de'
            ? ('Aktualisiert vorhandene CMS-Seite als /' . $locale . '/-Variante' . ($pageFallbackMeta !== [] ? ' · WordPress-Kategorien/Tags werden im Meta-Bericht gesichert' : ''))
            : ($pageFallbackMeta !== []
                ? 'Wird in cms_pages geschrieben · WordPress-Kategorien/Tags werden im Meta-Bericht gesichert'
                : 'Wird in cms_pages geschrieben');

        $targetUrl = $this->build_target_url('page', $targetSlug, 0, (string) ($item['date'] ?? ''), $locale);
        if ($this->is_locale_filter_skip($action, $reason, $locale)) {
            $targetSlug = '';
            $targetUrl = null;
            $targetHint = $this->build_locale_filter_skip_hint($locale);
        }

        return [
            'action' => $action,
            'reason' => $reason,
            'detected_locale' => $locale,
            'source_type' => 'page',
            'source_label' => 'Seite',
            'source_wp_id' => (int) ($item['wp_id'] ?? 0),
            'source_title' => (string) ($item['title'] ?? ''),
            'source_status' => (string) ($item['post_status'] ?? ''),
            'target_group' => 'pages',
            'target_type' => 'page',
            'target_slug' => $targetSlug,
            'target_url' => $targetUrl,
            'target_hint' => $targetHint,
            'category' => implode(', ', $pageCategories),
            'tags' => $pageTags,
            'image_candidates' => (int) ($contentPreview['image_candidates'] ?? 0),
            'featured_image' => (string) ($contentPreview['featured_image'] ?? ''),
            'table_shortcodes_found' => (int) ($contentPreview['table_shortcodes_found'] ?? 0),
            'table_shortcodes_resolved' => (int) ($contentPreview['table_shortcodes_resolved'] ?? 0),
            'table_targets' => $contentPreview['table_targets'] ?? [],
            'comments_total' => (int) ($commentPreview['total'] ?? 0),
            'comments_importable' => (int) ($commentPreview['importable'] ?? 0),
            'comments_skipped' => (int) ($commentPreview['skipped'] ?? 0),
            'unknown_meta_count' => $this->count_unknown_meta_for_item($item, 'page'),
        ];
    }

    /**
     * @return array{total:int,importable:int,skipped:int}
     */
    private function preview_comment_summary(\CMS\Database $db, string $p, array $item, bool $targetAvailable, bool $supportedTarget): array
    {
        $summary = [
            'total' => 0,
            'importable' => 0,
            'skipped' => 0,
        ];

        foreach ($this->get_comment_candidates($item) as $comment) {
            $summary['total']++;

            if (!$supportedTarget || !$targetAvailable) {
                $summary['skipped']++;
                continue;
            }

            $sourceReference = $this->build_comment_source_reference($item, $comment);
            $existingComment = $this->find_existing_mapping(
                $db,
                $p,
                'wp_comment',
                (int) ($comment['comment_id'] ?? 0),
                $sourceReference,
                'comment'
            );

            if ($existingComment !== null || $this->sanitize_imported_comment_content((string) ($comment['content'] ?? '')) === '') {
                $summary['skipped']++;
                continue;
            }

            $summary['importable']++;
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function build_table_preview(\CMS\Database $db, string $p, array $item, array &$context): array
    {
        $table = $item['table'] ?? null;
        $legacyTableId = trim((string) ($item['legacy_table_id'] ?? ''));
        if (!is_array($table) || empty($table['columns']) || !isset($table['rows'])) {
            return [
                'action' => 'skip',
                'reason' => 'Keine gültige Tabellenstruktur erkannt',
                'source_type' => 'tablepress_table',
                'source_label' => 'Tabelle',
                'source_wp_id' => (int) ($item['wp_id'] ?? 0),
                'source_title' => (string) ($item['title'] ?? ''),
                'source_status' => (string) ($item['post_status'] ?? ''),
                'target_group' => 'tables',
                'target_type' => 'site_table',
                'target_slug' => '',
                'target_url' => '',
                'target_hint' => '',
                'image_candidates' => 0,
                'featured_image' => '',
                'table_shortcodes_found' => 0,
                'table_shortcodes_resolved' => 0,
                'table_targets' => [],
                'unknown_meta_count' => $this->count_unknown_meta_for_item($item),
                'category' => '',
                'tags' => [],
            ];
        }

        $reason = '';
        $action = 'import';
        $existingMapping = null;

        if ($this->options['skip_duplicates']) {
            $existingMapping = $this->find_existing_mapping($db, $p, 'tablepress_table', (int) ($item['wp_id'] ?? 0), $legacyTableId !== '' ? $legacyTableId : null, 'site_table');
            if ($existingMapping !== null) {
                $action = 'skip';
                $reason = 'Bereits per Import-Mapping vorhanden';
            }
        }

        $baseSlug = $this->sanitize_slug((string) ($table['slug'] ?? $table['name'] ?? 'site-table'));
        $targetSlug = $baseSlug;

        if ($action === 'import') {
            if ($this->options['skip_duplicates']) {
                if ($this->preview_table_slug_exists($db, $p, $baseSlug, $context)) {
                    $action = 'skip';
                    $reason = 'Tabellenslug bereits vorhanden';
                } else {
                    $this->reserve_preview_slug('site_table', $baseSlug, $context);
                    $targetSlug = $baseSlug;
                }
            } else {
                $targetSlug = $this->preview_unique_table_slug($db, $p, $baseSlug, $context);
            }
        }

        $targetShortcode = '[site-table id="neu:' . $targetSlug . '"]';
        if ($action === 'import' && $legacyTableId !== '') {
            $context['table_preview_map'][$legacyTableId] = $targetShortcode;
        }

        $this->collect_unknown_meta($item);

        if ($action === 'skip') {
            $reason = $this->normalize_skip_reason($reason);
        }

        return [
            'action' => $action,
            'reason' => $reason,
            'source_type' => 'tablepress_table',
            'source_label' => 'Tabelle',
            'source_wp_id' => (int) ($item['wp_id'] ?? 0),
            'source_title' => (string) (($table['name'] ?? '') !== '' ? $table['name'] : ($item['title'] ?? '')),
            'source_status' => (string) ($item['post_status'] ?? ''),
            'target_group' => 'tables',
            'target_type' => 'site_table',
            'target_slug' => $existingMapping['target_slug'] ?? $targetSlug,
            'target_url' => $existingMapping !== null
                ? '[site-table id="' . (int) ($existingMapping['target_id'] ?? 0) . '"]'
                : $targetShortcode,
            'target_hint' => 'Wird in cms_site_tables geschrieben',
            'image_candidates' => 0,
            'featured_image' => '',
            'table_shortcodes_found' => 0,
            'table_shortcodes_resolved' => 0,
            'table_targets' => [],
            'unknown_meta_count' => $this->count_unknown_meta_for_item($item, 'table'),
            'category' => '',
            'tags' => [],
            'table_rows' => count($table['rows'] ?? []),
            'table_columns' => count($table['columns'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function build_redirect_preview(\CMS\Database $db, string $p, array $item, array &$context): array
    {
        $this->ensure_redirect_rule_tables($db, $p);

        $sourceReference = trim((string) ($item['source_reference'] ?? ''));
        $sourcePath = $this->normalize_import_redirect_source_path((string) ($item['redirect_source'] ?? ''));
        $targetUrl = $this->normalize_import_redirect_target((string) ($item['redirect_target'] ?? ''));
        $comparison = strtolower(trim((string) ($item['redirect_comparison'] ?? 'exact')));
        $status = strtolower(trim((string) ($item['status'] ?? 'active')));
        $redirectType = $this->normalize_import_redirect_type((int) ($item['redirect_type'] ?? 301));
        $action = 'import';
        $reason = '';

        if ($comparison !== 'exact') {
            $action = 'skip';
            $reason = 'Rank-Math-Regeln mit Vergleich "' . $comparison . '" werden nicht unterstützt';
        } elseif ($sourcePath === '' || $sourcePath === '/') {
            $action = 'skip';
            $reason = 'Ungültiger Redirect-Quellpfad';
        } elseif ($targetUrl === '') {
            $action = 'skip';
            $reason = 'Ungültiges Redirect-Ziel';
        }

        $existingMapping = $sourceReference !== ''
            ? $this->find_existing_mapping_by_reference($db, $p, 'rank_math_redirection', $sourceReference, 'redirect')
            : null;
        $existingRule = $db->get_row(
            "SELECT id FROM {$p}redirect_rules WHERE source_path = ? LIMIT 1",
            [$sourcePath]
        );

        if ($action === 'import' && empty($context['reserved_slugs']['redirect'][$sourcePath])) {
            $this->reserve_preview_slug('redirect', $sourcePath, $context);
        }

        if ($action === 'skip') {
            $reason = $this->normalize_skip_reason($reason);
        }

        return [
            'action' => $action,
            'reason' => $reason,
            'source_type' => 'rank_math_redirection',
            'source_label' => 'Weiterleitung',
            'source_wp_id' => (int) ($item['rank_math_id'] ?? 0),
            'source_title' => (string) (($item['title'] ?? '') !== '' ? $item['title'] : $sourcePath),
            'source_status' => $status !== '' ? $status : 'active',
            'target_group' => 'redirects',
            'target_type' => 'redirect',
            'target_slug' => $existingMapping['target_slug'] ?? $sourcePath,
            'target_url' => $targetUrl,
            'target_hint' => $existingRule !== null
                ? 'Bestehende 365CMS-Weiterleitung wird aktualisiert'
                : 'Wird in cms_redirect_rules angelegt',
            'image_candidates' => 0,
            'featured_image' => '',
            'table_shortcodes_found' => 0,
            'table_shortcodes_resolved' => 0,
            'table_targets' => [],
            'unknown_meta_count' => 0,
            'category' => '',
            'tags' => [],
            'source_comparison' => $comparison,
            'redirect_type' => $redirectType,
            'redirect_state' => $status === 'active' ? 'aktiv' : 'inaktiv',
            'redirect_hits' => max(0, (int) ($item['redirect_hits'] ?? 0)),
            'last_hit_at' => (string) ($item['last_accessed'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function preview_content_payload(\CMS\Database $db, string $p, array $item, array $context): array
    {
        $featuredImage = trim((string) ($item['featured_image'] ?? ''));
        $candidates = $this->collect_media_candidates($item, $featuredImage);

        if ($featuredImage === '' && !empty($item['image_urls'][0])) {
            $featuredImage = (string) $item['image_urls'][0];
        }

        $tablePreview = $this->preview_table_shortcodes(
            $db,
            $p,
            (string) ($item['content'] ?? ''),
            $context['table_preview_map'] ?? []
        );

        return [
            'featured_image' => $featuredImage,
            'image_candidates' => !empty($this->options['download_images']) ? count($candidates) : 0,
            'table_shortcodes_found' => (int) ($tablePreview['found'] ?? 0),
            'table_shortcodes_resolved' => !empty($this->options['convert_table_shortcodes']) ? (int) ($tablePreview['resolved'] ?? 0) : 0,
            'table_targets' => !empty($this->options['convert_table_shortcodes']) ? ($tablePreview['targets'] ?? []) : [],
        ];
    }

    /**
     * @param array<string, string> $previewTableMap
     * @return array{found:int,resolved:int,targets:array<int, string>}
     */
    private function preview_table_shortcodes(\CMS\Database $db, string $p, string $content, array $previewTableMap): array
    {
        if ($content === '' || !preg_match_all('/\[(?:table|tablepress)\s+id\s*=\s*["\']?(\d+)["\']?\s*\/?\]/i', $content, $matches)) {
            return ['found' => 0, 'resolved' => 0, 'targets' => []];
        }

        $found = 0;
        $resolved = 0;
        $targets = [];

        foreach ($matches[1] as $legacyId) {
            $found++;
            $target = $this->lookup_preview_table_target($db, $p, (string) $legacyId, $previewTableMap);
            if ($target === null) {
                continue;
            }

            $resolved++;
            if (!in_array($target, $targets, true) && count($targets) < 5) {
                $targets[] = $target;
            }
        }

        return ['found' => $found, 'resolved' => $resolved, 'targets' => $targets];
    }

    /**
     * @param array<string, string> $previewTableMap
     */
    private function lookup_preview_table_target(\CMS\Database $db, string $p, string $legacyId, array $previewTableMap): ?string
    {
        if (isset($previewTableMap[$legacyId])) {
            return $previewTableMap[$legacyId];
        }

        $targetId = $db->get_var(
            "SELECT target_id
             FROM {$p}import_items
             WHERE source_type = ?
               AND source_reference = ?
               AND target_type = ?
             ORDER BY id DESC
             LIMIT 1",
            ['tablepress_table', $legacyId, 'site_table']
        );

        if ($targetId === null) {
            $targetId = $db->get_var(
                "SELECT target_id
                 FROM {$p}import_items
                 WHERE source_type = ?
                   AND source_wp_id = ?
                   AND target_type = ?
                 ORDER BY id DESC
                 LIMIT 1",
                ['tablepress_table', (int) $legacyId, 'site_table']
            );
        }

        return $targetId !== null ? '[site-table id="' . (int) $targetId . '"]' : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function preview_slug_exists(\CMS\Database $db, string $table, string $slug, string $bucket, array $context): bool
    {
        if ($slug === '' || !empty($context['reserved_slugs'][$bucket][$slug])) {
            return true;
        }

        return (int) $db->get_var("SELECT COUNT(*) FROM {$table} WHERE slug = ?", [$slug]) > 0;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function reserve_preview_slug(string $bucket, string $slug, array &$context): void
    {
        $context['reserved_slugs'][$bucket][$slug] = true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function preview_unique_slug(\CMS\Database $db, string $table, string $base, string $bucket, array &$context, bool $preserveBase = false): string
    {
        $slug   = $preserveBase ? $this->preserve_source_slug($base) : $this->sanitize_slug($base);
        $try    = $slug;
        $suffix = 2;

        for ($i = 0; $i <= 25; $i++) {
            if (!$this->preview_slug_exists($db, $table, $try, $bucket, $context)) {
                $this->reserve_preview_slug($bucket, $try, $context);
                return $try;
            }
            $try = $slug . '-' . $suffix;
            $suffix++;
        }

        $fallback = $slug . '-preview-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $this->reserve_preview_slug($bucket, $fallback, $context);
        return $fallback;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function preview_table_slug_exists(\CMS\Database $db, string $p, string $slug, array $context): bool
    {
        if ($slug === '' || !empty($context['reserved_slugs']['site_table'][$slug])) {
            return true;
        }

        if (!$this->has_table_slug_column($db, $p)) {
            return false;
        }

        return $this->find_existing_table_id_by_slug($db, $p, $slug) > 0;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function preview_unique_table_slug(\CMS\Database $db, string $p, string $base, array &$context): string
    {
        $slug = $this->sanitize_slug($base !== '' ? $base : 'site-table');
        $try = $slug;
        $suffix = 2;

        for ($i = 0; $i <= 25; $i++) {
            if (!$this->preview_table_slug_exists($db, $p, $try, $context)) {
                $this->reserve_preview_slug('site_table', $try, $context);
                return $try;
            }
            $try = $slug . '-' . $suffix;
            $suffix++;
        }

        $fallback = $slug . '-preview-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $this->reserve_preview_slug('site_table', $fallback, $context);
        return $fallback;
    }
}
