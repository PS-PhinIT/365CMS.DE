<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Downloads – Abrufe je Tag als Balken (reines HTML/CSS, mit Tabelle für Screenreader).
 *
 * @var list<array{date:string,hits:int}> $dlSeries
 * @var callable $dlE
 * @var callable $dlT
 */

$dlSeries = is_array($dlSeries ?? null) ? $dlSeries : [];
$dlMax = max(1, ...array_map(static fn (array $row): int => (int) $row['hits'], $dlSeries ?: [['hits' => 0]]));
?>
<figure class="cms-dl-chart" aria-labelledby="cmsDlChartCaption">
    <div class="cms-dl-chart__bars" aria-hidden="true">
        <?php foreach ($dlSeries as $dlPoint): ?>
            <span class="cms-dl-chart__bar cms-dl-chart__bar--<?= (int) $dlPoint['hits'] > 0 ? max(1, (int) round(((int) $dlPoint['hits'] / $dlMax) * 20)) : 0 ?>" title="<?= $dlE(date('d.m.Y', (int) strtotime((string) $dlPoint['date'])) . ': ' . (int) $dlPoint['hits']) ?>"></span>
        <?php endforeach; ?>
    </div>
    <figcaption id="cmsDlChartCaption" class="text-secondary small mt-2"><?= $dlT('downloads.admin.chart_caption', count($dlSeries), $dlMax) ?></figcaption>
    <table class="visually-hidden">
        <caption><?= $dlT('downloads.admin.chart_caption', count($dlSeries), $dlMax) ?></caption>
        <thead><tr><th scope="col"><?= $dlT('downloads.admin.col_date') ?></th><th scope="col"><?= $dlT('downloads.admin.col_hits') ?></th></tr></thead>
        <tbody>
        <?php foreach ($dlSeries as $dlPoint): ?>
            <tr><td><?= $dlE((string) $dlPoint['date']) ?></td><td><?= (int) $dlPoint['hits'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</figure>
