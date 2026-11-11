<?php
/**
 * Feeds → Einstellungen des Aggregators: Allgemein (Aktivierung, Archiv, Anzeige, Filter, Aufbewahrung),
 * Design (Farben, Radius, Spalten) und Digest (Absender, Betreff, Umfang).
 */

declare(strict_types=1);

use CMS\Services\Feed\AggregatorSettings;

if (!defined('ABSPATH')) {
    exit;
}

$fdSettings = $feedsData['settings'] ?? AggregatorSettings::all();
$fdTab = (string) ($feedsData['settings_tab'] ?? 'general');
$fdBool = static function (string $key) use ($fdSettings, $feedsT): string {
    return '<label class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="' . $key . '" value="1"' . (!empty($fdSettings[$key]) ? ' checked' : '') . '>'
        . ' <span class="form-check-label">' . $feedsT('settings.' . $key) . '</span></label>';
};
$fdInput = static function (string $key, string $type = 'text', array $attrs = []) use ($fdSettings, $feedsT, $feedsEscape): string {
    $extra = '';
    foreach ($attrs as $name => $value) {
        $extra .= ' ' . $name . '="' . $feedsEscape((string) $value) . '"';
    }
    $hintKey = 'settings.' . $key . '_hint';
    $hint = \CMS\Services\Feed\FeedI18n::admin('feeds.admin.' . $hintKey);
    $class = $type === 'color' ? 'form-control form-control-color' : 'form-control';

    return '<label class="form-label" for="fd-set-' . $key . '">' . $feedsT('settings.' . $key) . '</label>'
        . '<input class="' . $class . '" type="' . $type . '" id="fd-set-' . $key . '" name="' . $key . '" value="' . $feedsEscape((string) ($fdSettings[$key] ?? '')) . '"' . $extra . '>'
        . ($hint !== 'feeds.admin.' . $hintKey ? '<div class="form-hint">' . $feedsEscape($hint) . '</div>' : '');
};
$fdTextarea = static function (string $key) use ($fdSettings, $feedsT, $feedsEscape): string {
    return '<label class="form-label" for="fd-set-' . $key . '">' . $feedsT('settings.' . $key) . '</label>'
        . '<textarea class="form-control" id="fd-set-' . $key . '" name="' . $key . '" rows="4">' . $feedsEscape((string) ($fdSettings[$key] ?? '')) . '</textarea>';
};
?>
<ul class="nav nav-tabs mt-3">
    <?php foreach (FeedAggregatorModule::SETTINGS_TABS as $fdKey) : ?>
        <li class="nav-item"><a class="nav-link<?= $fdTab === $fdKey ? ' active' : '' ?>" href="<?= $feedsUrl(['section' => 'settings', 'stab' => $fdKey]) ?>"><?= $feedsT('settings.tab_' . $fdKey) ?></a></li>
    <?php endforeach; ?>
</ul>
<form method="post" action="<?= $feedsUrl(['section' => 'settings', 'stab' => $fdTab]) ?>" class="card cms-fd-settings">
    <?= $feedsCsrfField ?>
    <input type="hidden" name="action" value="save_settings">
    <input type="hidden" name="stab" value="<?= $feedsEscape($fdTab) ?>">
    <div class="card-body">
        <?php if ($fdTab === 'general') : ?>
            <?= $fdBool('aggregator_enabled') ?>
            <?= $fdBool('require_consent') ?>
            <div class="row g-3 mt-1">
                <div class="col-md-6"><?= $fdInput('archive_title', 'text', ['maxlength' => 190]) ?></div>
                <div class="col-md-6"><?= $fdInput('archive_title_en', 'text', ['maxlength' => 190]) ?></div>
                <div class="col-md-6"><?= $fdInput('archive_description', 'text', ['maxlength' => 500]) ?></div>
                <div class="col-md-6"><?= $fdInput('archive_description_en', 'text', ['maxlength' => 500]) ?></div>
                <div class="col-md-4"><?= $fdInput('archive_slug', 'text', ['maxlength' => 60, 'pattern' => '[a-z0-9-]+']) ?></div>
                <div class="col-md-4"><?= $fdInput('per_page', 'number', ['min' => 4, 'max' => 100]) ?></div>
                <div class="col-md-4"><?= $fdInput('excerpt_length', 'number', ['min' => 50, 'max' => 500]) ?></div>
            </div>
            <h3 class="mt-4"><?= $feedsT('settings.display') ?></h3>
            <?php foreach (['show_source', 'show_date', 'show_image', 'show_excerpt', 'open_in_new_tab'] as $fdKey) : ?>
                <?= $fdBool($fdKey) ?>
            <?php endforeach; ?>
            <h3 class="mt-4"><?= $feedsT('settings.noise') ?></h3>
            <p class="text-secondary small"><?= $feedsT('settings.noise_hint') ?></p>
            <div class="row g-3">
                <div class="col-md-4"><?= $fdTextarea('noise_exclude_keywords') ?></div>
                <div class="col-md-4"><?= $fdTextarea('noise_exclude_authors') ?></div>
                <div class="col-md-4"><?= $fdTextarea('noise_exclude_domains') ?></div>
            </div>
            <h3 class="mt-4"><?= $feedsT('settings.system') ?></h3>
            <div class="row g-3">
                <div class="col-md-4"><?= $fdInput('item_retention_days', 'number', ['min' => 0, 'max' => 365]) ?></div>
                <div class="col-md-4"><?= $fdInput('log_retention_days', 'number', ['min' => 1, 'max' => 365]) ?></div>
                <div class="col-md-4"><?= $fdInput('cron_batch_size', 'number', ['min' => 1, 'max' => 25]) ?></div>
            </div>
        <?php elseif ($fdTab === 'design') : ?>
            <div class="row g-3">
                <?php foreach (['color_primary', 'color_accent', 'color_hdr_from', 'color_hdr_to', 'color_hdr_title', 'color_card_bg', 'color_card_border'] as $fdKey) : ?>
                    <div class="col-6 col-md-3"><?= $fdInput($fdKey, 'color') ?></div>
                <?php endforeach; ?>
                <div class="col-6 col-md-3"><?= $fdInput('border_radius', 'number', ['min' => 0, 'max' => 24]) ?></div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="fd-set-grid_columns"><?= $feedsT('settings.grid_columns') ?></label>
                    <select class="form-select" id="fd-set-grid_columns" name="grid_columns">
                        <?php foreach (AggregatorSettings::GRID_COLUMNS as $fdColumns) : ?>
                            <option value="<?= $fdColumns ?>"<?= (string) $fdSettings['grid_columns'] === $fdColumns ? ' selected' : '' ?>><?= $fdColumns === 'auto' ? $feedsT('settings.grid_auto') : $feedsEscape($fdColumns) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php else : ?>
            <div class="row g-3">
                <div class="col-md-6"><?= $fdInput('digest_from_name', 'text', ['maxlength' => 120]) ?></div>
                <div class="col-md-6"><?= $fdInput('digest_from_email', 'email', ['maxlength' => 190]) ?></div>
                <div class="col-md-6"><?= $fdInput('digest_subject', 'text', ['maxlength' => 190]) ?></div>
                <div class="col-md-6"><?= $fdInput('digest_subject_en', 'text', ['maxlength' => 190]) ?></div>
                <div class="col-md-4"><?= $fdInput('digest_max_items', 'number', ['min' => 5, 'max' => 100]) ?></div>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-footer text-end">
        <button class="btn btn-primary" type="submit"><?= $feedsEscape((string) __('Save Changes')) ?></button>
    </div>
</form>
