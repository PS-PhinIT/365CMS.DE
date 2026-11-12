<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Knowledgebase – Einstellungen mit den Tabs Allgemein, Design, Import (CSV-Pakete) und System (Info, Hardreset).
 * Gespeichert werden je Formular nur die Felder des jeweiligen Tabs.
 *
 * @var array<string, mixed> $kbData
 * @var string $csrfToken
 * @var callable $kbE
 * @var callable $kbT
 * @var callable $kbDate
 * @var callable $kbNumber
 */

$kbTab = (string) ($kbData['tab'] ?? 'general');
$kbS = (array) ($kbData['settings'] ?? []);
$kbStats = (array) ($kbData['stats'] ?? []);
$kbV = static fn (string $key, string $default = ''): string => is_scalar($kbS[$key] ?? null) ? (string) $kbS[$key] : $default;
$kbSwitch = static function (string $key, string $labelKey) use ($kbE, $kbT, $kbV): string {
    return '<label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="' . $kbE($key) . '" value="1"'
        . ($kbV($key) === '1' ? ' checked' : '') . '><span class="form-check-label">' . $kbT($labelKey) . '</span></label>';
};
$kbNum = static function (string $key, string $labelKey, int $min, int $max, string $unit = '') use ($kbE, $kbT, $kbV): string {
    $id = 'kbSet_' . $key;

    return '<label class="form-label" for="' . $id . '">' . $kbT($labelKey) . '</label><div class="input-group">'
        . '<input type="number" class="form-control" id="' . $id . '" name="' . $kbE($key) . '" min="' . $min . '" max="' . $max . '" step="1" value="' . $kbE($kbV($key)) . '" data-kb-preview="' . $kbE($key) . '">'
        . ($unit !== '' ? '<span class="input-group-text">' . $kbE($unit) . '</span>' : '') . '</div>';
};
$kbText = static function (string $key, string $labelKey, int $max) use ($kbE, $kbT, $kbV): string {
    $id = 'kbSet_' . $key;

    return '<label class="form-label" for="' . $id . '">' . $kbT($labelKey) . '</label><input type="text" class="form-control" id="' . $id . '" name="' . $kbE($key) . '" maxlength="' . $max . '" value="' . $kbE($kbV($key)) . '">';
};
$kbArea = static function (string $key, string $labelKey) use ($kbE, $kbT, $kbV): string {
    $id = 'kbSet_' . $key;

    return '<label class="form-label" for="' . $id . '">' . $kbT($labelKey) . '</label><textarea class="form-control" id="' . $id . '" name="' . $kbE($key) . '" rows="3" maxlength="2000">' . $kbE($kbV($key)) . '</textarea>';
};
$kbColor = static function (string $key, string $labelKey, string $fallback) use ($kbE, $kbT, $kbV): string {
    $id = 'kbSet_' . $key;
    $value = strtoupper($kbV($key, $fallback));
    $value = preg_match('/^#[0-9A-F]{6}$/', $value) === 1 ? $value : $fallback;

    return '<label class="form-label" for="' . $id . '">' . $kbT($labelKey) . '</label><div class="input-group" data-color-sync>'
        . '<input type="color" class="form-control form-control-color" name="' . $kbE($key) . '" value="' . $kbE($value) . '" aria-label="' . $kbT($labelKey) . '" data-kb-preview="' . $kbE($key) . '">'
        . '<input type="text" class="form-control" id="' . $id . '" name="' . $kbE($key) . '_text" value="' . $kbE($value) . '" pattern="^#[0-9A-Fa-f]{6}$" maxlength="7" spellcheck="false">'
        . '</div>';
};
$kbChoices = (array) ($kbData['design_choices'] ?? []);
$kbSelect = static function (string $key, string $labelKey) use ($kbE, $kbT, $kbV, $kbChoices): string {
    $id = 'kbSet_' . $key;
    $html = '<label class="form-label" for="' . $id . '">' . $kbT($labelKey) . '</label><select class="form-select" id="' . $id . '" name="' . $kbE($key) . '">';
    foreach ((array) ($kbChoices[$key] ?? []) as $value => $fallbackLabel) {
        $labelKeyChoice = 'knowledgebase.choice.' . $key . '.' . $value;
        $label = (string) __($labelKeyChoice);
        $html .= '<option value="' . $kbE($value) . '"' . ((string) $value === $kbV($key) ? ' selected' : '') . '>' . $kbE($label !== $labelKeyChoice ? $label : $fallbackLabel) . '</option>';
    }

    return $html . '</select>';
};
$kbSubTabs = ['general' => 'knowledgebase.admin.settings_general', 'design' => 'knowledgebase.admin.settings_design', 'import' => 'knowledgebase.admin.settings_import', 'system' => 'knowledgebase.admin.settings_system'];
$kbFormAction = $kbE(KnowledgebaseModule::url(['section' => 'settings', 'tab' => $kbTab]));
$kbHidden = '<input type="hidden" name="csrf_token" value="' . $kbE($csrfToken) . '">';
?>
<ul class="nav nav-pills mb-4" aria-label="<?= $kbT('knowledgebase.admin.tab_settings') ?>">
    <?php foreach ($kbSubTabs as $kbKey => $kbLabel): ?>
        <li class="nav-item"><a class="nav-link<?= $kbTab === $kbKey ? ' active' : '' ?>"<?= $kbTab === $kbKey ? ' aria-current="page"' : '' ?> href="<?= $kbE(KnowledgebaseModule::url(['section' => 'settings', 'tab' => $kbKey !== 'general' ? $kbKey : null])) ?>"><?= $kbT($kbLabel) ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($kbTab === 'general'): ?>
<form method="post" action="<?= $kbFormAction ?>">
    <?= $kbHidden ?>
    <input type="hidden" name="action" value="save_settings">
    <input type="hidden" name="settings_tab" value="general">
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title"><?= $kbT('knowledgebase.admin.set_group_linking') ?></h3></div>
                <div class="card-body">
                    <?= $kbSwitch('enable_autolink', 'knowledgebase.admin.set_autolink') ?>
                    <?= $kbSwitch('enable_output_buffer', 'knowledgebase.admin.set_output_buffer') ?>
                    <div class="form-hint mb-2"><?= $kbT('knowledgebase.admin.set_output_buffer_hint') ?></div>
                    <?= $kbSwitch('nofollow_links', 'knowledgebase.admin.set_nofollow') ?>
                    <?= $kbSwitch('open_links_new_tab', 'knowledgebase.admin.set_new_tab') ?>
                    <?= $kbSwitch('enable_tooltips', 'knowledgebase.admin.set_tooltips') ?>
                    <div class="mt-3 col-sm-6 px-0"><?= $kbNum('max_links_per_page', 'knowledgebase.admin.set_max_links', 1, 25) ?></div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title"><?= $kbT('knowledgebase.admin.set_group_navigation') ?></h3></div>
                <div class="card-body row g-3">
                    <div class="col-12"><?= $kbSwitch('show_nav_link', 'knowledgebase.admin.set_nav_link') ?></div>
                    <div class="col-sm-6"><?= $kbText('nav_label', 'knowledgebase.admin.set_nav_label', 40) ?></div>
                    <div class="col-sm-6"><?= $kbText('nav_label_en', 'knowledgebase.admin.set_nav_label_en', 40) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h3 class="card-title"><?= $kbT('knowledgebase.admin.set_group_archive') ?></h3></div>
                <div class="card-body row g-3">
                    <div class="col-md-6"><?= $kbText('archive_title', 'knowledgebase.admin.set_archive_title', 120) ?></div>
                    <div class="col-md-6"><?= $kbText('archive_title_en', 'knowledgebase.admin.set_archive_title_en', 120) ?></div>
                    <div class="col-md-6"><?= $kbArea('archive_intro', 'knowledgebase.admin.set_archive_intro') ?></div>
                    <div class="col-md-6"><?= $kbArea('archive_intro_en', 'knowledgebase.admin.set_archive_intro_en') ?></div>
                    <div class="col-md-6">
                        <?= $kbSwitch('show_search', 'knowledgebase.admin.set_show_search') ?>
                        <?= $kbSwitch('show_category_sidebar', 'knowledgebase.admin.set_show_categories') ?>
                        <?= $kbSwitch('show_keyword_badges', 'knowledgebase.admin.set_show_keywords') ?>
                        <?= $kbSwitch('show_related_entries', 'knowledgebase.admin.set_show_related') ?>
                    </div>
                    <div class="col-md-6 col-lg-3"><?= $kbNum('related_posts_limit', 'knowledgebase.admin.set_related_posts', 3, 6) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h3 class="card-title"><?= $kbT('knowledgebase.admin.set_group_glossary') ?></h3></div>
                <div class="card-body row g-3">
                    <div class="col-md-6"><?= $kbText('glossary_title', 'knowledgebase.admin.set_glossary_title', 120) ?></div>
                    <div class="col-md-6"><?= $kbText('glossary_title_en', 'knowledgebase.admin.set_glossary_title_en', 120) ?></div>
                    <div class="col-md-6"><?= $kbArea('glossary_intro', 'knowledgebase.admin.set_glossary_intro') ?></div>
                    <div class="col-md-6"><?= $kbArea('glossary_intro_en', 'knowledgebase.admin.set_glossary_intro_en') ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex mt-4"><button type="submit" class="btn btn-primary ms-auto"><i class="ti ti-device-floppy me-1" aria-hidden="true"></i><?= $kbT('knowledgebase.admin.save_settings') ?></button></div>
</form>

<?php elseif ($kbTab === 'design'): ?>
<form method="post" action="<?= $kbFormAction ?>" data-kb-design-form>
    <?= $kbHidden ?>
    <input type="hidden" name="action" value="save_settings">
    <input type="hidden" name="settings_tab" value="design">
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title"><?= $kbT('knowledgebase.admin.set_group_layout') ?></h3></div>
                <div class="card-body row g-3">
                    <div class="col-sm-4"><?= $kbNum('content_max_width', 'knowledgebase.admin.set_content_width', 720, 1600, 'px') ?></div>
                    <div class="col-sm-4"><?= $kbNum('sidebar_width', 'knowledgebase.admin.set_sidebar_width', 220, 420, 'px') ?></div>
                    <div class="col-sm-4"><?= $kbNum('design_border_radius', 'knowledgebase.admin.set_radius', 0, 32, 'px') ?></div>
                </div>
            </div>
            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title"><?= $kbT('knowledgebase.admin.set_group_colors') ?></h3></div>
                <div class="card-body row g-3">
                    <div class="col-sm-6 col-lg-4"><?= $kbColor('design_accent_color', 'knowledgebase.admin.set_color_accent', '#0D9488') ?></div>
                    <div class="col-sm-6 col-lg-4"><?= $kbColor('design_accent_hover_color', 'knowledgebase.admin.set_color_accent_hover', '#0F766E') ?></div>
                    <div class="col-sm-6 col-lg-4"><?= $kbColor('design_surface_color', 'knowledgebase.admin.set_color_surface', '#FFFFFF') ?></div>
                    <div class="col-sm-6 col-lg-4"><?= $kbColor('design_border_color', 'knowledgebase.admin.set_color_border', '#DBE1EA') ?></div>
                    <div class="col-sm-6 col-lg-4"><?= $kbColor('tooltip_background_color', 'knowledgebase.admin.set_color_tooltip_bg', '#111827') ?></div>
                    <div class="col-sm-6 col-lg-4"><?= $kbColor('tooltip_text_color', 'knowledgebase.admin.set_color_tooltip_text', '#F8FAFC') ?></div>
                </div>
            </div>
            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title"><?= $kbT('knowledgebase.admin.set_group_overview') ?></h3></div>
                <div class="card-body row g-3">
                    <div class="col-sm-6 col-lg-4"><?= $kbSelect('archive_hero_style', 'knowledgebase.admin.set_hero_style') ?></div>
                    <div class="col-sm-6 col-lg-4"><?= $kbSelect('archive_columns', 'knowledgebase.admin.set_columns') ?></div>
                    <div class="col-sm-6 col-lg-4"><?= $kbSelect('archive_card_style', 'knowledgebase.admin.set_card_style') ?></div>
                    <div class="col-sm-6 col-lg-4"><?= $kbNum('archive_excerpt_lines', 'knowledgebase.admin.set_excerpt_lines', 0, 8) ?></div>
                    <div class="col-sm-6 col-lg-4"><?= $kbColor('archive_card_bg_color', 'knowledgebase.admin.set_card_bg', '#FFFFFF') ?></div>
                    <div class="col-12"><?= $kbSwitch('archive_card_accent_bar', 'knowledgebase.admin.set_card_accent_bar') ?></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h3 class="card-title"><?= $kbT('knowledgebase.admin.set_group_single') ?></h3></div>
                <div class="card-body row g-3">
                    <div class="col-sm-6 col-lg-4"><?= $kbSelect('single_hero_style', 'knowledgebase.admin.set_hero_style') ?></div>
                    <div class="col-sm-6 col-lg-4"><?= $kbSelect('single_section_style', 'knowledgebase.admin.set_section_style') ?></div>
                    <div class="col-sm-6 col-lg-4"><?= $kbColor('single_section_bg_color', 'knowledgebase.admin.set_section_bg', '#FFFFFF') ?></div>
                    <div class="col-sm-6 col-lg-4"><?= $kbSelect('single_sidebar_style', 'knowledgebase.admin.set_sidebar_style') ?></div>
                    <div class="col-sm-6 col-lg-4"><?= $kbSelect('single_sidebar_position', 'knowledgebase.admin.set_sidebar_position') ?></div>
                    <div class="col-sm-6 col-lg-4"><?= $kbColor('single_sidebar_bg_color', 'knowledgebase.admin.set_sidebar_bg', '#F8FAFC') ?></div>
                    <div class="col-12">
                        <?= $kbSwitch('single_section_tints', 'knowledgebase.admin.set_section_tints') ?>
                        <?= $kbSwitch('single_section_icons', 'knowledgebase.admin.set_section_icons') ?>
                        <?= $kbSwitch('single_sidebar_sticky', 'knowledgebase.admin.set_sidebar_sticky') ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card admin-knowledgebase-sticky">
                <div class="card-header"><h3 class="card-title"><?= $kbT('knowledgebase.admin.preview_title') ?></h3></div>
                <div class="card-body">
                    <div class="admin-kb-preview" data-kb-preview-box>
                        <div class="admin-kb-preview__card">
                            <p class="admin-kb-preview__eyebrow"><?= $kbT('knowledgebase.admin.page_title') ?></p>
                            <h4><?= $kbT('knowledgebase.admin.preview_entry') ?></h4>
                            <p><?= $kbT('knowledgebase.admin.preview_text') ?></p>
                            <span class="admin-kb-preview__link"><?= $kbT('knowledgebase.admin.preview_link') ?></span>
                        </div>
                        <div class="admin-kb-preview__tooltip">
                            <strong><?= $kbT('knowledgebase.admin.preview_tooltip') ?></strong>
                            <span><?= $kbT('knowledgebase.admin.preview_tooltip_text') ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex mt-4"><button type="submit" class="btn btn-primary ms-auto"><i class="ti ti-device-floppy me-1" aria-hidden="true"></i><?= $kbT('knowledgebase.admin.save_settings') ?></button></div>
</form>

<?php elseif ($kbTab === 'import'): ?>
    <?php $kbPackages = (array) ($kbData['packages'] ?? []); ?>
    <?php if (!empty($kbData['csv_warnings'])): ?>
        <div class="alert alert-warning" role="alert">
            <ul class="mb-0"><?php foreach ((array) $kbData['csv_warnings'] as $kbWarning): ?><li><?= $kbE($kbWarning) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <div class="card mb-4">
        <div class="card-header">
            <div>
                <h3 class="card-title"><?= $kbT('knowledgebase.admin.packages_title') ?></h3>
                <p class="card-subtitle mb-0"><?= $kbT('knowledgebase.admin.packages_hint') ?></p>
            </div>
            <?php if ($kbPackages !== []): ?>
                <div class="card-actions">
                    <form method="post" action="<?= $kbFormAction ?>">
                        <?= $kbHidden ?>
                        <input type="hidden" name="action" value="import_all_packages">
                        <button type="submit" class="btn btn-primary"><i class="ti ti-file-import me-1" aria-hidden="true"></i><?= $kbT('knowledgebase.admin.import_all') ?></button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($kbPackages === []): ?>
            <div class="card-body text-secondary"><?= $kbT('knowledgebase.admin.packages_empty') ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead><tr><th><?= $kbT('knowledgebase.admin.package_col_name') ?></th><th class="text-end"><?= $kbT('knowledgebase.admin.package_col_entries') ?></th><th><?= $kbT('knowledgebase.admin.package_col_samples') ?></th><th class="w-1"><span class="visually-hidden"><?= $kbT('knowledgebase.admin.col_actions') ?></span></th></tr></thead>
                    <tbody>
                    <?php foreach ($kbPackages as $kbPackage): ?>
                        <tr>
                            <td><span class="fw-medium"><?= $kbE($kbPackage['label'] ?? '') ?></span><div class="small text-secondary"><code><?= $kbE($kbPackage['file_name'] ?? '') ?></code></div></td>
                            <td class="text-end"><?= (int) ($kbPackage['entry_count'] ?? 0) ?></td>
                            <td class="text-secondary small"><?= $kbE(implode(', ', array_map('strval', (array) ($kbPackage['sample_terms'] ?? [])))) ?></td>
                            <td>
                                <form method="post" action="<?= $kbFormAction ?>">
                                    <?= $kbHidden ?>
                                    <input type="hidden" name="action" value="import_package">
                                    <input type="hidden" name="package_key" value="<?= $kbE($kbPackage['key'] ?? '') ?>">
                                    <button type="submit" class="btn btn-sm text-nowrap"><?= $kbT('knowledgebase.admin.import_package') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><?= $kbT('knowledgebase.admin.template_title') ?></h3>
            <?php if (!empty($kbData['template_available'])): ?>
                <div class="card-actions"><a class="btn" href="<?= $kbE(KnowledgebaseModule::url(['download' => 'csv-template'])) ?>"><i class="ti ti-download me-1" aria-hidden="true"></i><?= $kbT('knowledgebase.admin.template_download') ?></a></div>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($kbData['template_available'])): ?>
                <div class="alert alert-warning mb-3" role="alert"><?= $kbT('knowledgebase.admin.error_template_missing') ?></div>
            <?php endif; ?>
            <p><?= $kbT('knowledgebase.admin.template_hint') ?></p>
            <?php if (!empty($kbData['template_columns'])): ?>
                <div class="d-flex flex-wrap gap-1 mb-3"><?php foreach ((array) $kbData['template_columns'] as $kbColumn): ?><span class="badge bg-secondary-lt"><?= $kbE($kbColumn) ?></span><?php endforeach; ?></div>
            <?php endif; ?>
            <ul class="mb-0">
                <?php foreach (['rule_required', 'rule_empty', 'rule_flags', 'rule_sections', 'rule_links', 'rule_match'] as $kbRule): ?>
                    <li><?= $kbT('knowledgebase.admin.template_' . $kbRule) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

<?php else: ?>
    <?php
    $kbLastImport = $kbV('csv_last_import_at');
    $kbScope = $kbV('csv_last_import_scope');
    $kbYesNo = static fn (string $key): string => (string) __($kbV($key) === '1' ? 'knowledgebase.admin.on' : 'knowledgebase.admin.off');
    $kbInfo = [
        'knowledgebase.admin.info_version' => (string) \CMS\Services\Knowledgebase\Database\Installer::SCHEMA_VERSION,
        'knowledgebase.admin.info_routes' => '/kb · /glossar · /en/kb',
        'knowledgebase.admin.info_active' => $kbNumber((int) ($kbStats['active_entries'] ?? 0)) . ' / ' . $kbNumber((int) ($kbStats['entries'] ?? 0)),
        'knowledgebase.admin.set_autolink' => $kbYesNo('enable_autolink'),
        'knowledgebase.admin.set_output_buffer' => $kbYesNo('enable_output_buffer'),
        'knowledgebase.admin.info_last_import' => $kbLastImport !== '' ? $kbDate($kbLastImport) : (string) __('knowledgebase.admin.info_never'),
        'knowledgebase.admin.info_last_import_count' => $kbNumber((int) $kbV('csv_last_import_count', '0')),
        'knowledgebase.admin.info_last_import_scope' => $kbScope === 'all' ? (string) __('knowledgebase.admin.info_scope_all') : ($kbScope !== '' ? $kbScope : '–'),
    ];
    ?>
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title"><?= $kbT('knowledgebase.admin.info_title') ?></h3></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <?php foreach ($kbInfo as $kbLabel => $kbValue): ?>
                            <dt class="col-sm-6 fw-normal text-secondary"><?= $kbT($kbLabel) ?></dt>
                            <dd class="col-sm-6"><?= $kbE($kbValue) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                </div>
                <div class="card-footer"><a href="/glossar-sitemap.xml" target="_blank" rel="noopener"><i class="ti ti-sitemap me-1" aria-hidden="true"></i><?= $kbT('knowledgebase.admin.info_sitemap') ?></a></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-danger">
                <div class="card-header"><h3 class="card-title text-danger"><?= $kbT('knowledgebase.admin.reset_title') ?></h3></div>
                <div class="card-body">
                    <p><?= $kbT('knowledgebase.admin.reset_hint') ?></p>
                    <div class="d-flex flex-wrap gap-2">
                        <form method="post" action="<?= $kbFormAction ?>"
                              data-confirm-title="<?= $kbT('knowledgebase.admin.reset_title') ?>" data-confirm-message="<?= $kbT('knowledgebase.admin.reset_confirm') ?>"
                              data-confirm-text="<?= $kbT('knowledgebase.admin.reset_button') ?>" data-confirm-class="btn-danger">
                            <?= $kbHidden ?>
                            <input type="hidden" name="action" value="hard_reset">
                            <button type="submit" class="btn btn-outline-danger"><?= $kbT('knowledgebase.admin.reset_button') ?></button>
                        </form>
                        <form method="post" action="<?= $kbFormAction ?>"
                              data-confirm-title="<?= $kbT('knowledgebase.admin.reset_title') ?>" data-confirm-message="<?= $kbT('knowledgebase.admin.reset_import_confirm') ?>"
                              data-confirm-text="<?= $kbT('knowledgebase.admin.reset_import_button') ?>" data-confirm-class="btn-danger">
                            <?= $kbHidden ?>
                            <input type="hidden" name="action" value="hard_reset_import">
                            <button type="submit" class="btn btn-danger"><?= $kbT('knowledgebase.admin.reset_import_button') ?></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
