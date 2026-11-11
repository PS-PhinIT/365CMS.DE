<?php
/**
 * Feeds → Übersicht: Kennzahlen, Abrufzustand, Quellen mit Handlungsbedarf, neueste Artikel.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$fdStats = is_array($feedsData['stats'] ?? null) ? $feedsData['stats'] : [];
$fdHealth = is_array($feedsData['health'] ?? null) ? $feedsData['health'] : [];
$fdQueue = is_array($feedsData['queue'] ?? null) ? $feedsData['queue'] : [];
$fdCards = [
    'categories' => (int) ($fdStats['categories'] ?? 0),
    'sources' => (int) ($fdStats['sources_active'] ?? 0) . ' / ' . (int) ($fdStats['sources'] ?? 0),
    'items' => (int) ($fdStats['items'] ?? 0),
    'items_today' => (int) ($fdStats['items_today'] ?? 0),
    'digests' => (int) ($fdStats['digests'] ?? 0),
    'subscriptions' => (int) ($fdStats['subscriptions'] ?? 0),
];
?>
<div class="row row-cards mt-1">
    <?php foreach ($fdCards as $fdKey => $fdValue) : ?>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="text-secondary small"><?= $feedsT('overview.stat_' . $fdKey) ?></div>
                    <div class="h2 mb-0"><?= $feedsEscape((string) $fdValue) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row row-cards mt-1">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h2 class="card-title"><?= $feedsT('overview.health') ?></h2></div>
            <div class="card-body">
                <dl class="row mb-3">
                    <dt class="col-7"><?= $feedsT('overview.health_errors') ?></dt><dd class="col-5"><?= (int) ($fdHealth['with_errors'] ?? 0) ?></dd>
                    <dt class="col-7"><?= $feedsT('overview.health_never') ?></dt><dd class="col-5"><?= (int) ($fdHealth['never_fetched'] ?? 0) ?></dd>
                    <dt class="col-7"><?= $feedsT('overview.health_overdue') ?></dt><dd class="col-5"><?= (int) ($fdHealth['overdue'] ?? 0) ?></dd>
                    <dt class="col-7"><?= $feedsT('overview.queue') ?></dt>
                    <dd class="col-5"><?= $feedsT('overview.queue_value', (int) ($fdQueue['pending'] ?? 0), (int) ($fdQueue['processing'] ?? 0), (int) ($fdQueue['failed'] ?? 0)) ?></dd>
                </dl>
                <div class="d-flex flex-wrap gap-2">
                    <form method="post" action="<?= $feedsUrl() ?>">
                        <?= $feedsCsrfField ?>
                        <button class="btn btn-primary" type="submit" name="action" value="run_queue"><?= $feedsT('overview.run_due') ?></button>
                    </form>
                    <form method="post" action="<?= $feedsUrl() ?>" data-confirm-title="<?= $feedsT('overview.fetch_all') ?>" data-confirm-message="<?= $feedsT('overview.fetch_all_confirm') ?>" data-confirm-text="<?= $feedsT('overview.fetch_all') ?>">
                        <?= $feedsCsrfField ?>
                        <button class="btn btn-outline-secondary" type="submit" name="action" value="fetch_all"><?= $feedsT('overview.fetch_all') ?></button>
                    </form>
                    <?php if (!empty($feedsData['enabled'])) : ?>
                        <a class="btn btn-outline-secondary" href="<?= $feedsEscape((string) $feedsData['archive_url']) ?>" target="_blank" rel="noopener"><?= $feedsT('overview.view_public') ?></a>
                    <?php endif; ?>
                </div>
                <p class="form-hint mt-3 mb-0"><?= $feedsT('overview.cron_hint') ?></p>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h2 class="card-title"><?= $feedsT('overview.attention') ?></h2></div>
            <?php if (($feedsData['attention'] ?? []) === []) : ?>
                <div class="card-body text-secondary"><?= $feedsT('overview.attention_none') ?></div>
            <?php else : ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($feedsData['attention'] as $fdSource) : ?>
                        <a class="list-group-item list-group-item-action" href="<?= $feedsUrl(['section' => 'sources', 'edit' => (int) $fdSource['id']]) ?>">
                            <div class="fw-bold"><?= $feedsEscape((string) $fdSource['name']) ?></div>
                            <div class="small text-secondary">
                                <?php if (trim((string) ($fdSource['last_error'] ?? '')) !== '') : ?>
                                    <span class="text-danger"><?= $feedsEscape((string) $fdSource['last_error']) ?></span>
                                <?php elseif (empty($fdSource['last_fetched_at'])) : ?>
                                    <?= $feedsT('overview.health_never') ?>
                                <?php else : ?>
                                    <?= $feedsT('overview.last_fetch', $feedsDate($fdSource['last_fetched_at'])) ?>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><h2 class="card-title"><?= $feedsT('overview.latest') ?></h2></div>
    <?php if (($feedsData['latest'] ?? []) === []) : ?>
        <div class="card-body text-secondary"><?= $feedsT('items.empty') ?></div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <tbody>
                    <?php foreach ($feedsData['latest'] as $fdItem) : ?>
                        <tr>
                            <td><?= $feedsEscape((string) $fdItem['title']) ?></td>
                            <td class="text-secondary"><?= $feedsEscape((string) $fdItem['source_name']) ?></td>
                            <td class="text-secondary text-nowrap"><?= $feedsEscape($feedsDate($fdItem['pub_date'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
