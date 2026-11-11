<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Downloads – Statistik über alle Downloads (Zeitraum 30/90/365 Tage, Top-Downloads, Quellseiten, CSV).
 *
 * @var array<string, mixed> $dlData
 * @var callable $dlE
 * @var callable $dlT
 * @var callable $dlNumber
 */

$dlPeriod = (int) ($dlData['period'] ?? 30);
$dlSummary = (array) ($dlData['summary'] ?? []);
$dlSeries = (array) ($dlData['series'] ?? []);
$dlTop = (array) ($dlData['top'] ?? []);
$dlSources = (array) ($dlData['sources'] ?? []);
?>
<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title"><?= $dlT('downloads.admin.stats_period', $dlPeriod) ?></h3>
        <div class="card-actions btn-group">
            <?php foreach (\CMS\Services\Downloads\DownloadStatsService::PERIODS as $dlP): ?>
                <a class="btn btn-sm<?= $dlP === $dlPeriod ? ' active' : '' ?>"<?= $dlP === $dlPeriod ? ' aria-current="true"' : '' ?> href="<?= $dlE(DownloadsModule::url(['tab' => 'stats', 'period' => $dlP])) ?>"><?= $dlT('downloads.admin.days', $dlP) ?></a>
            <?php endforeach; ?>
            <a class="btn btn-sm" href="<?= $dlE(DownloadsModule::url(['export' => 'csv', 'period' => $dlPeriod])) ?>"><i class="ti ti-download me-1" aria-hidden="true"></i>CSV</a>
        </div>
    </div>
    <div class="card-body">
        <div class="cms-dl-kpis mb-3">
            <div><div class="text-secondary small"><?= $dlT('downloads.admin.kpi_period') ?></div><div class="cms-dl-kpi__value"><?= $dlNumber((int) ($dlSummary['period_hits'] ?? 0)) ?></div></div>
            <div><div class="text-secondary small"><?= $dlT('downloads.admin.kpi_total') ?></div><div class="cms-dl-kpi__value"><?= $dlNumber((int) ($dlSummary['total_hits'] ?? 0)) ?></div></div>
            <div><div class="text-secondary small"><?= $dlT('downloads.admin.kpi_published') ?></div><div class="cms-dl-kpi__value"><?= $dlNumber((int) ($dlSummary['published'] ?? 0)) ?> <span class="text-secondary fs-5">/ <?= $dlNumber((int) ($dlSummary['downloads'] ?? 0)) ?></span></div></div>
        </div>
        <?php require __DIR__ . '/chart.php'; ?>
        <p class="form-hint mb-0"><?= $dlT('downloads.admin.stats_privacy', (int) ($dlData['retention_days'] ?? 0)) ?></p>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?= $dlT('downloads.admin.top_downloads') ?></h3></div>
            <?php if ($dlTop === []): ?>
                <div class="card-body text-secondary"><?= $dlT('downloads.admin.no_hits') ?></div>
            <?php else: ?>
                <table class="table card-table">
                    <thead><tr><th><?= $dlT('downloads.admin.col_title') ?></th><th class="text-end"><?= $dlT('downloads.admin.kpi_period') ?></th><th class="text-end"><?= $dlT('downloads.admin.kpi_total') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($dlTop as $dlRow): ?>
                        <tr>
                            <td><a href="<?= $dlE(DownloadsModule::url(['action' => 'edit', 'id' => (int) $dlRow['download_id'], 'section' => 'stats', 'period' => $dlPeriod])) ?>"><?= $dlE($dlRow['title']) ?></a></td>
                            <td class="text-end"><?= $dlNumber((int) $dlRow['hits']) ?></td>
                            <td class="text-end"><?= $dlNumber((int) $dlRow['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6"><?php require __DIR__ . '/sources.php'; ?></div>
</div>
