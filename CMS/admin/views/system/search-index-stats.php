<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Suchindex – Tab „Suchbegriffe“: anonyme Statistik der Frontend-Suche
 *
 * @var array<string, mixed> $siData
 * @var string $csrfToken
 * @var callable $siE
 * @var callable $siT
 * @var callable $siDay
 * @var callable $siNumber
 */

$siStats = is_array($siData['stats'] ?? null) ? $siData['stats'] : [];
$siPeriods = is_array($siData['periods'] ?? null) ? $siData['periods'] : [];
$siLocales = is_array($siData['locales'] ?? null) ? $siData['locales'] : [];
$siSearches = (int) ($siStats['searches'] ?? 0);
$siZeroSearches = (int) ($siStats['zero_hit_searches'] ?? 0);
$siTop = is_array($siStats['top'] ?? null) ? $siStats['top'] : [];
$siZero = is_array($siStats['zero'] ?? null) ? $siStats['zero'] : [];
?>

<?php if (empty($siData['stats_available'])): ?>
    <div class="alert alert-warning" role="alert"><?= $siT('search_index.stats_unavailable') ?></div>
<?php else: ?>

    <?php if (empty($siData['logging_enabled'])): ?>
        <div class="alert alert-info" role="status">
            <?= $siT('search_index.logging_disabled') ?>
            <a href="/admin/search-index?tab=settings" class="alert-link"><?= $siT('search_index.tab_settings') ?></a>
        </div>
    <?php endif; ?>

    <form method="get" action="/admin/search-index" class="card mb-4">
        <input type="hidden" name="tab" value="stats">
        <div class="card-body d-flex flex-wrap align-items-end gap-3">
            <div>
                <label class="form-label" for="search-stats-days"><?= $siT('search_index.filter_period') ?></label>
                <select class="form-select" id="search-stats-days" name="days">
                    <?php foreach ($siPeriods as $siPeriod): ?>
                        <option value="<?= (int) $siPeriod ?>"<?= (int) $siPeriod === (int) ($siStats['days'] ?? 30) ? ' selected' : '' ?>><?= $siT('search_index.period_days', (int) $siPeriod) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (count($siLocales) > 1): ?>
                <div>
                    <label class="form-label" for="search-stats-locale"><?= $siT('search_index.filter_locale') ?></label>
                    <select class="form-select" id="search-stats-locale" name="locale">
                        <option value=""><?= $siT('search_index.all_locales') ?></option>
                        <?php foreach ($siLocales as $siLocale): ?>
                            <option value="<?= $siE($siLocale) ?>"<?= $siLocale === ($siStats['locale'] ?? '') ? ' selected' : '' ?>><?= $siE(strtoupper((string) $siLocale)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div>
                <button type="submit" class="btn btn-outline-primary"><?= $siT('search_index.filter_apply') ?></button>
            </div>
            <div class="ms-auto small text-secondary"><?= $siT('search_index.period_from', $siDay((string) ($siStats['from'] ?? ''))) ?></div>
        </div>
    </form>

    <?php if ($siSearches === 0): ?>
        <div class="alert alert-secondary" role="status"><?= $siT('search_index.stats_empty') ?></div>
    <?php else: ?>
        <div class="row row-deck row-cards mb-4">
            <div class="col-sm-4">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader"><?= $siT('search_index.kpi_searches') ?></div>
                        <div class="h1 mb-0"><?= $siNumber($siSearches) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader"><?= $siT('search_index.kpi_terms') ?></div>
                        <div class="h1 mb-0"><?= $siNumber((int) ($siStats['terms'] ?? 0)) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader"><?= $siT('search_index.kpi_zero_hits') ?></div>
                        <div class="h1 mb-0<?= $siZeroSearches > 0 ? ' text-warning' : '' ?>"><?= $siNumber($siZeroSearches) ?></div>
                        <div class="small text-secondary"><?= $siT('search_index.kpi_zero_share', $siNumber($siZeroSearches * 100 / $siSearches, 1)) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row row-cards mb-4">
            <div class="<?= $siZero !== [] ? 'col-lg-6' : 'col-12' ?>">
                <div class="card">
                    <div class="card-header"><h3 class="card-title"><?= $siT('search_index.top_terms') ?></h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th><?= $siT('search_index.col_term') ?></th>
                                    <th class="text-end"><?= $siT('search_index.col_searches') ?></th>
                                    <th class="text-end"><?= $siT('search_index.col_avg_results') ?></th>
                                    <th><?= $siT('search_index.col_last_day') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($siTop as $siRow): ?>
                                    <tr>
                                        <td class="text-break"><?= $siE($siRow['term']) ?></td>
                                        <td class="text-end"><?= $siNumber((int) $siRow['searches']) ?></td>
                                        <td class="text-end"><?= $siNumber((float) $siRow['avg_results'], 1) ?></td>
                                        <td><?= $siDay((string) $siRow['last_day']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php if ($siZero !== []): ?>
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><h3 class="card-title"><?= $siT('search_index.zero_terms') ?></h3></div>
                        <div class="table-responsive">
                            <table class="table table-vcenter card-table">
                                <thead>
                                    <tr>
                                        <th><?= $siT('search_index.col_term') ?></th>
                                        <th class="text-end"><?= $siT('search_index.col_zero_searches') ?></th>
                                        <th><?= $siT('search_index.col_last_day') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($siZero as $siRow): ?>
                                        <tr>
                                            <td class="text-break"><?= $siE($siRow['term']) ?></td>
                                            <td class="text-end"><?= $siNumber((int) $siRow['zero_hits']) ?></td>
                                            <td><?= $siDay((string) $siRow['last_day']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer small text-secondary"><?= $siT('search_index.zero_terms_hint') ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/search-index?tab=stats" class="card"
          data-confirm-title="<?= $siT('search_index.delete_stats') ?>"
          data-confirm-message="<?= $siT('search_index.delete_stats_confirm') ?>"
          data-confirm-text="<?= $siT('search_index.delete_stats') ?>"
          data-confirm-class="btn-danger"
          data-confirm-status-class="bg-danger">
        <input type="hidden" name="csrf_token" value="<?= $siE($csrfToken) ?>">
        <input type="hidden" name="action" value="delete_stats">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="text-secondary small"><?= $siT('search_index.privacy_hint') ?></div>
            <button type="submit" class="btn btn-outline-danger"><?= $siT('search_index.delete_stats') ?></button>
        </div>
    </form>
<?php endif; ?>
