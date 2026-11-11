<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Suchindex – Tab „Einstellungen“: Protokollierung der Suchbegriffe und Aufbewahrung
 *
 * @var array<string, mixed> $siData
 * @var string $csrfToken
 * @var callable $siE
 * @var callable $siT
 */

$siRetention = is_array($siData['retention'] ?? null) ? $siData['retention'] : ['min' => 7, 'max' => 3650, 'default' => 365];
$siRetentionUrl = (string) ($siData['data_retention_url'] ?? '');
?>

<form method="post" action="/admin/search-index?tab=settings" class="card">
    <input type="hidden" name="csrf_token" value="<?= $siE($csrfToken) ?>">
    <input type="hidden" name="action" value="save_settings">
    <div class="card-header">
        <h3 class="card-title"><?= $siT('search_index.settings_title') ?></h3>
    </div>
    <div class="card-body">
        <?php if (empty($siData['stats_available'])): ?>
            <div class="alert alert-warning" role="alert"><?= $siT('search_index.stats_unavailable') ?></div>
        <?php endif; ?>

        <div class="mb-4">
            <label class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="log_terms" value="1"<?= !empty($siData['logging_enabled']) ? ' checked' : '' ?>>
                <span class="form-check-label"><?= $siT('search_index.setting_log_terms') ?></span>
            </label>
            <div class="form-hint"><?= $siT('search_index.setting_log_terms_hint') ?></div>
        </div>

        <div class="row">
        <div class="col-md-8 col-lg-6 mb-3">
            <label class="form-label" for="search-stats-retention"><?= $siT('search_index.setting_retention') ?></label>
            <div class="input-group">
                <input class="form-control" type="number" id="search-stats-retention" name="stats_retention_days"
                       min="0" max="<?= (int) $siRetention['max'] ?>" step="1"
                       value="<?= (int) ($siData['retention_days'] ?? $siRetention['default']) ?>">
                <span class="input-group-text"><?= $siT('search_index.days') ?></span>
            </div>
            <div class="form-hint">
                <?= $siT('search_index.setting_retention_hint', (int) $siRetention['min'], (int) $siRetention['max']) ?>
                <?php if ($siRetentionUrl !== ''): ?>
                    <a href="<?= $siE($siRetentionUrl) ?>"><?= $siT('search_index.retention_overview') ?></a>
                <?php endif; ?>
            </div>
        </div>
        </div>
    </div>
    <div class="card-footer text-end">
        <button type="submit" class="btn btn-primary"><?= $siT('search_index.save') ?></button>
    </div>
</form>
