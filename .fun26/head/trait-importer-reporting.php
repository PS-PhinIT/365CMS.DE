<?php
/**
 * CMS WordPress Importer – Reporting-/Meta-Helfer
 *
 * @package CMS_Importer
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('CMS_IMPORTER_SERVICE_REPORTING_TRAIT_LOADED') || trait_exists('CMS_Importer_Service_Reporting_Trait', false)) {
    return;
}

define('CMS_IMPORTER_SERVICE_REPORTING_TRAIT_LOADED', true);

trait CMS_Importer_Service_Reporting_Trait
{
    private function collect_unknown_meta(array $item): void
    {
        if (empty($item['meta'])) {
            return;
        }

        $mappedKeys = array_fill_keys($item['mapped_meta_keys'] ?? [], true);
        foreach ($item['meta'] as $key => $value) {
            if (isset($mappedKeys[$key])) {
                continue;
            }
            $this->unknown_meta[] = [
                'source_id'  => (string) $item['wp_id'],
                'post_title' => $this->safe_substr($item['title'], 0, 255),
                'post_type'  => $item['post_type'],
                'meta_key'   => $key,
                'meta_value' => $value,
            ];
        }
    }

    private function store_unknown_meta(\CMS\Database $db, string $p): void
    {
        if (empty($this->unknown_meta) || $this->log_id === 0) {
            return;
        }
        foreach ($this->unknown_meta as $row) {
            try {
                $db->insert('import_meta', array_merge(['log_id' => $this->log_id], $row));
            } catch (\Exception $e) {
                error_log('CMS_Importer: Meta-Speicherung fehlgeschlagen: ' . $e->getMessage());
            }
        }
    }

    private function generate_meta_report(array $site_info): string
    {
        if (empty($this->unknown_meta)) {
            return '';
        }

        $report_dir = CMS_IMPORTER_PLUGIN_DIR . 'reports/';
        if (!is_dir($report_dir)) {
            mkdir($report_dir, 0755, true);
        }

        $safe_name   = preg_replace('/[^a-z0-9_-]/', '_', strtolower(pathinfo($this->filename, PATHINFO_FILENAME)));
        $report_base = $report_dir . date('Y-m-d_His') . '_' . $safe_name . '_meta-report';
        $report_file = $report_base . '.md';
        $html_file   = $report_base . '.html';

        $grouped = [];
        foreach ($this->unknown_meta as $row) {
            $key = $row['meta_key'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['count' => 0, 'examples' => []];
            }
            $grouped[$key]['count']++;
            if (count($grouped[$key]['examples']) < 3) {
                $grouped[$key]['examples'][] = [
                    'source_id'  => $row['source_id'],
                    'post_title' => $row['post_title'],
                    'post_type'  => $row['post_type'],
                    'value'      => $this->safe_substr((string) $row['meta_value'], 0, 200),
                ];
            }
        }

        ksort($grouped);

        $md  = "# WordPress-Import – Unbekannte Meta-Felder\n\n";
        $md .= "> **Import-Datei:** `{$this->filename}`  \n";
        $md .= "> **Erstellt:** " . date('d.m.Y H:i:s') . "  \n";
        $md .= "> **Quelle:** " . htmlspecialchars($site_info['title'] ?? 'Unbekannt') . " (`" . ($site_info['base_site_url'] ?? '') . "`)  \n";
        $md .= "> **Anzahl unbekannter Keys:** " . count($grouped) . "  \n";
        $md .= "> **Gesamte Meta-Einträge:** " . count($this->unknown_meta) . "  \n\n";
        $md .= "---\n\n";
        $md .= "## Hinweis\n\n";
        $md .= "Die folgenden Meta-Keys aus dem WordPress-Export konnten **nicht automatisch** auf ein CMS-Datenbankfeld gemappt werden. ";
        $md .= "Sie wurden trotzdem in der Tabelle `cms_import_meta` gespeichert und können manuell nachverarbeitet werden.\n\n";
        $md .= "---\n\n";
        $md .= "## Übersicht aller unbekannten Meta-Keys\n\n";
        $md .= "| # | Meta-Key | Anzahl | Hinweis |\n";
        $md .= "|---|----------|--------|---------|\n";

        $i = 1;
        foreach ($grouped as $key => $info) {
            $hint = $this->get_meta_hint($key);
            $md  .= "| {$i} | `{$key}` | {$info['count']} | {$hint} |\n";
            $i++;
        }

        $md .= "\n---\n\n";
        $md .= "## Details der unbekannten Meta-Keys\n\n";

        foreach ($grouped as $key => $info) {
            $hint = $this->get_meta_hint($key);
            $md  .= "### `{$key}`\n\n";
            $md  .= "- **Vorkommen:** {$info['count']}\n";
            $md  .= "- **Hinweis:** {$hint}\n";
            $md  .= "- **Beispielwerte:**\n\n";
            foreach ($info['examples'] as $ex) {
                $value = str_replace(['|', "\n", "\r"], [' &#124; ', ' ', ''], $ex['value']);
                $md   .= "  - Post `{$ex['source_id']}` (**{$ex['post_title']}**, Typ: `{$ex['post_type']}`):  \n";
                $md   .= "    `{$value}`\n";
            }
            $md .= "\n";
        }

        $md .= "---\n\n";
        $md .= "*Automatisch generiert vom CMS WordPress Importer v" . CMS_IMPORTER_VERSION . "*\n";

        file_put_contents($report_file, $md);
        file_put_contents($html_file, $this->build_meta_report_html($site_info, $grouped, $report_file));
        return $report_file;
    }

    private function build_meta_report_html(array $site_info, array $grouped, string $markdownPath): string
    {
        $title = htmlspecialchars((string) ($site_info['title'] ?? 'Unbekannt'), ENT_QUOTES, 'UTF-8');
        $siteUrl = htmlspecialchars((string) ($site_info['base_site_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $generated = htmlspecialchars(date('d.m.Y H:i:s'), ENT_QUOTES, 'UTF-8');
        $markdownName = htmlspecialchars(basename($markdownPath), ENT_QUOTES, 'UTF-8');

        $rows = '';
        foreach ($grouped as $key => $info) {
            $rows .= '<tr>'
                . '<td><code>' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '</code></td>'
                . '<td>' . (int) ($info['count'] ?? 0) . '</td>'
                . '<td>' . htmlspecialchars($this->get_meta_hint((string) $key), ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }

        $details = '';
        foreach ($grouped as $key => $info) {
            $details .= '<section class="report-section">';
            $details .= '<h2><code>' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '</code></h2>';
            $details .= '<p><strong>Vorkommen:</strong> ' . (int) ($info['count'] ?? 0) . '<br>';
            $details .= '<strong>Hinweis:</strong> ' . htmlspecialchars($this->get_meta_hint((string) $key), ENT_QUOTES, 'UTF-8') . '</p>';
            $details .= '<ul>';

            foreach (($info['examples'] ?? []) as $example) {
                $details .= '<li><strong>Post ' . htmlspecialchars((string) ($example['source_id'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong>'
                    . ' (' . htmlspecialchars((string) ($example['post_title'] ?? ''), ENT_QUOTES, 'UTF-8')
                    . ', Typ: ' . htmlspecialchars((string) ($example['post_type'] ?? ''), ENT_QUOTES, 'UTF-8') . ')<br>'
                    . '<code>' . htmlspecialchars((string) ($example['value'] ?? ''), ENT_QUOTES, 'UTF-8') . '</code></li>';
            }

            $details .= '</ul></section>';
        }

        return '<!DOCTYPE html>'
            . '<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>WordPress-Import Meta-Bericht</title>'
            . '<style>'
            . 'body{font-family:Segoe UI,Arial,sans-serif;background:#f5f7fb;color:#1f2937;margin:0;padding:32px;line-height:1.5}'
            . '.wrap{max-width:1100px;margin:0 auto;background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(15,23,42,.08);padding:32px}'
            . 'h1,h2{margin:0 0 12px}h1{font-size:28px}h2{font-size:20px;margin-top:28px}'
            . '.meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:24px 0}'
            . '.meta div{background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px}'
            . 'table{width:100%;border-collapse:collapse;margin-top:16px}th,td{padding:12px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}'
            . 'th{background:#f8fafc}code{background:#f3f4f6;padding:2px 6px;border-radius:6px}'
            . '.report-section{border-top:1px solid #e5e7eb;padding-top:20px;margin-top:20px}.footer{margin-top:32px;color:#6b7280;font-size:14px}'
            . '</style></head><body><div class="wrap">'
            . '<h1>WordPress-Import – Meta-Bericht</h1>'
            . '<p>Lesbare HTML-Version des Import-Berichts. Die Rohfassung liegt zusätzlich als <code>' . $markdownName . '</code> vor.</p>'
            . '<div class="meta">'
            . '<div><strong>Quelle</strong><br>' . $title . '</div>'
            . '<div><strong>Website</strong><br>' . $siteUrl . '</div>'
            . '<div><strong>Erstellt</strong><br>' . $generated . '</div>'
            . '<div><strong>Unbekannte Keys</strong><br>' . count($grouped) . '</div>'
            . '</div>'
            . '<table><thead><tr><th>Meta-Key</th><th>Anzahl</th><th>Hinweis</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . $details
            . '<div class="footer">Automatisch generiert vom CMS WordPress Importer v' . htmlspecialchars((string) CMS_IMPORTER_VERSION, ENT_QUOTES, 'UTF-8') . '</div>'
            . '</div></body></html>';
    }

    private function get_meta_hint(string $key): string
    {
        $hints = [
            'rank_math_seo_score'                => 'Rank Math SEO-Score (0–100)',
            'rank_math_focus_keyword'            => 'Rank Math Fokus-Keyword',
            'rank_math_canonical_url'            => 'Rank Math Canonical-URL',
            'rank_math_og_content_image'         => 'Rank Math Open-Graph-Bild',
            'rank_math_internal_links_processed' => 'Rank Math interne Verlinkung (Technik)',
            'rank_math_analytic_object_id'       => 'Rank Math Analytics-ID (Technik)',
            '_yoast_wpseo_focuskw'               => 'Yoast SEO Fokus-Keyword',
            '_yoast_wpseo_canonical'             => 'Yoast SEO Canonical-URL',
            '_yoast_wpseo_opengraph-image'       => 'Yoast SEO Open-Graph-Bild',
            '_yoast_wpseo_schema_page_type'      => 'Yoast SEO Schema-Seitentyp',
            '_yoast_wpseo_schema_article_type'   => 'Yoast SEO Schema-Artikeltyp',
            '_wp_page_template'                  => 'WordPress Seitentemplate-Zuweisung',
            'cmplz_hide_cookiebanner'            => 'Complianz – Cookie-Banner ausblenden',
            'litespeed_vpi_list'                 => 'LiteSpeed Cache VPI-Liste (Technik)',
            '_lwpgls_synonyms'                   => 'Lightweight Glossary – Synonyme',
            '_wpml_word_count'                   => 'WPML Wortanzahl (Technik)',
            '_wpml_media_featured'               => 'WPML Medien Featured (Technik)',
        ];

        if (str_starts_with($key, '_yoast_')) {
            return $hints[$key] ?? 'Yoast SEO Plugin – kein direktes CMS-Äquivalent';
        }
        if (str_starts_with($key, 'rank_math_')) {
            return $hints[$key] ?? 'Rank Math SEO Plugin – kein direktes CMS-Äquivalent';
        }
        if (str_starts_with($key, '_wpml_')) {
            return $hints[$key] ?? 'WPML Mehrsprachigkeit – kein direktes CMS-Äquivalent';
        }
        if (str_starts_with($key, 'litespeed_')) {
            return $hints[$key] ?? 'LiteSpeed Cache – Technik-Metadaten (kann ignoriert werden)';
        }

        return $hints[$key] ?? '—';
    }

    private function count_unknown_meta_for_item(array $item, string $targetType = ''): int
    {
        if (empty($item['meta'])) {
            return count($this->get_taxonomy_fallback_meta_entries($item, $targetType));
        }

        $mappedKeys = array_fill_keys($item['mapped_meta_keys'] ?? [], true);
        $count = 0;
        foreach ($item['meta'] as $key => $value) {
            if (!isset($mappedKeys[$key])) {
                $count++;
            }
        }

        return $count + count($this->get_taxonomy_fallback_meta_entries($item, $targetType));
    }

    private function collect_taxonomy_fallback_meta(array $item, string $targetType): void
    {
        foreach ($this->get_taxonomy_fallback_meta_entries($item, $targetType) as $entry) {
            $this->unknown_meta[] = $entry;
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function get_taxonomy_fallback_meta_entries(array $item, string $targetType): array
    {
        $entries = [];
        $categories = $this->normalize_tag_names($item['categories'] ?? []);
        $tags = $this->normalize_tag_names($item['tags'] ?? []);

        if ($targetType === 'post') {
            $secondaryCategories = array_values(array_slice($categories, 1));
            if ($secondaryCategories !== []) {
                $entries[] = $this->build_import_meta_entry($item, '_wp_import_additional_categories', implode(' | ', $secondaryCategories));
            }

            return $entries;
        }

        if ($targetType === 'page') {
            if ($categories !== []) {
                $entries[] = $this->build_import_meta_entry($item, '_wp_import_page_categories', implode(' | ', $categories));
            }
            if ($tags !== []) {
                $entries[] = $this->build_import_meta_entry($item, '_wp_import_page_tags', implode(' | ', $tags));
            }
        }

        return $entries;
    }

    private function build_import_meta_entry(array $item, string $metaKey, string $metaValue): array
    {
        return [
            'source_id'  => (string) ($item['wp_id'] ?? ''),
            'post_title' => $this->safe_substr((string) ($item['title'] ?? ''), 0, 255),
            'post_type'  => (string) ($item['post_type'] ?? ''),
            'meta_key'   => $metaKey,
            'meta_value' => $metaValue,
        ];
    }
}
