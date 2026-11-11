<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Downloads – meistgenutzte Quellseiten (aus dem signierten Block-Parameter, nur Beitrag/Seite).
 *
 * @var list<array<string,mixed>> $dlSources
 * @var callable $dlE
 * @var callable $dlT
 * @var callable $dlNumber
 */
?>
<div class="card">
    <div class="card-header"><h3 class="card-title"><?= $dlT('downloads.admin.top_sources') ?></h3></div>
    <?php if ($dlSources === []): ?>
        <div class="card-body text-secondary"><?= $dlT('downloads.admin.no_sources') ?></div>
    <?php else: ?>
        <table class="table card-table">
            <thead><tr><th><?= $dlT('downloads.admin.col_source') ?></th><th class="text-end"><?= $dlT('downloads.admin.col_hits') ?></th></tr></thead>
            <tbody>
            <?php foreach ($dlSources as $dlSource): ?>
                <tr><td><a href="<?= $dlE($dlSource['url']) ?>"><?= $dlE($dlSource['title']) ?></a></td><td class="text-end"><?= $dlNumber((int) $dlSource['hits']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
