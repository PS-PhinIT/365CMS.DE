<?php
/**
 * Feeds → Abrufprotokoll: Queue-Einträge (Cron, manuell, Massenabruf) mit Ergebnis und Fehlertext.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$fdFilters = $feedsData['filters'] ?? [];
$fdQueue = $feedsData['queue'] ?? [];
$fdQuery = ['section' => 'log', 'status' => (string) ($fdFilters['status'] ?? ''), 'source' => (int) ($fdFilters['source_id'] ?? 0) ?: null];
$fdBadge = ['pending' => 'bg-blue-lt', 'processing' => 'bg-yellow-lt', 'done' => 'bg-green-lt', 'failed' => 'bg-red-lt'];
?>
<div class="card mt-3">
    <div class="card-header flex-wrap gap-2">
        <form method="get" action="/admin/settings" class="d-flex flex-wrap gap-2 me-auto">
            <input type="hidden" name="tab" value="feeds">
            <input type="hidden" name="section" value="log">
            <select class="form-select form-select-sm w-auto" name="status" aria-label="<?= $feedsT('filter.status') ?>">
                <option value=""><?= $feedsT('filter.all_status') ?></option>
                <?php foreach (['pending', 'processing', 'done', 'failed'] as $fdStatus) : ?>
                    <option value="<?= $fdStatus ?>"<?= ($fdFilters['status'] ?? '') === $fdStatus ? ' selected' : '' ?>><?= $feedsT('log.status_' . $fdStatus) ?> (<?= (int) ($fdQueue[$fdStatus] ?? 0) ?>)</option>
                <?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm w-auto" name="source" aria-label="<?= $feedsT('items.col_source') ?>">
                <option value=""><?= $feedsT('filter.all_sources') ?></option>
                <?php foreach ($feedsData['sources'] ?? [] as $fdSource) : ?>
                    <option value="<?= (int) $fdSource['id'] ?>"<?= (int) ($fdFilters['source_id'] ?? 0) === (int) $fdSource['id'] ? ' selected' : '' ?>><?= $feedsEscape((string) $fdSource['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm" type="submit"><?= $feedsT('filter.apply') ?></button>
        </form>
        <?php foreach (['retry_failed' => 'log.retry', 'clear_pending' => 'log.clear', 'cleanup_log' => 'log.cleanup'] as $fdAction => $fdLabel) : ?>
            <form method="post" action="<?= $feedsUrl(['section' => 'log']) ?>">
                <?= $feedsCsrfField ?>
                <button class="btn btn-sm btn-outline-secondary" type="submit" name="action" value="<?= $fdAction ?>"><?= $feedsT($fdLabel) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
    <?php if (($feedsData['entries'] ?? []) === []) : ?>
        <div class="card-body text-secondary"><?= $feedsT('log.empty') ?></div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th><?= $feedsT('log.col_time') ?></th>
                        <th><?= $feedsT('items.col_source') ?></th>
                        <th><?= $feedsT('filter.status') ?></th>
                        <th><?= $feedsT('log.col_trigger') ?></th>
                        <th><?= $feedsT('log.col_new') ?></th>
                        <th><?= $feedsT('log.col_error') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feedsData['entries'] as $fdRow) :
                        $fdStatus = (string) $fdRow['status']; ?>
                        <tr>
                            <td class="text-nowrap"><?= $feedsEscape($feedsDate($fdRow['processed_at'] ?? $fdRow['created_at'] ?? '')) ?></td>
                            <td><?= $feedsEscape((string) ($fdRow['source_name'] ?? '#' . (int) $fdRow['source_id'])) ?></td>
                            <td><span class="badge <?= $fdBadge[$fdStatus] ?? 'bg-secondary-lt' ?>"><?= $feedsT('log.status_' . (isset($fdBadge[$fdStatus]) ? $fdStatus : 'pending')) ?></span></td>
                            <td><?= $feedsT('log.trigger_' . (in_array($fdRow['trigger_type'], ['cron', 'manual', 'batch', 'import'], true) ? $fdRow['trigger_type'] : 'cron')) ?></td>
                            <td><?= (int) $fdRow['items_new'] ?></td>
                            <td class="small text-danger"><?= $feedsEscape((string) ($fdRow['error'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ((int) ($feedsData['pages'] ?? 1) > 1) :
            $fdPage = (int) $feedsData['page']; ?>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <?php if ($fdPage > 1) : ?><a class="btn btn-sm" href="<?= $feedsUrl($fdQuery + ['page' => $fdPage - 1]) ?>"><?= $feedsT('prev') ?></a><?php else : ?><span></span><?php endif; ?>
                <span class="text-secondary small"><?= $feedsT('page_of', $fdPage, (int) $feedsData['pages']) ?></span>
                <?php if ($fdPage < (int) $feedsData['pages']) : ?><a class="btn btn-sm" href="<?= $feedsUrl($fdQuery + ['page' => $fdPage + 1]) ?>"><?= $feedsT('next') ?></a><?php else : ?><span></span><?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
