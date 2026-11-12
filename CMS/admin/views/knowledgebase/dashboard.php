<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Knowledgebase – Übersicht: Kennzahlen, Status der Auto-Verlinkung, zuletzt geänderte Einträge.
 *
 * @var array<string, mixed> $kbData
 * @var callable $kbE
 * @var callable $kbT
 * @var callable $kbDate
 * @var callable $kbNumber
 */

$kbStats = (array) ($kbData['stats'] ?? []);
$kbSettings = (array) ($kbData['settings'] ?? []);
$kbOn = static fn (string $key): bool => ($kbSettings[$key] ?? '0') === '1';
$kbCards = [
    ['knowledgebase.admin.stat_entries', (int) ($kbStats['entries'] ?? 0), 'book-2', KnowledgebaseModule::url(['section' => 'entries'])],
    ['knowledgebase.admin.stat_active', (int) ($kbStats['active_entries'] ?? 0), 'circle-check', KnowledgebaseModule::url(['section' => 'entries', 'status' => 'active'])],
    ['knowledgebase.admin.stat_categories', (int) ($kbStats['categories'] ?? 0), 'category', KnowledgebaseModule::url(['section' => 'categories'])],
    ['knowledgebase.admin.stat_tooltips', (int) ($kbStats['tooltip_entries'] ?? 0), 'message-circle', ''],
];
$kbLatest = (array) ($kbData['latest'] ?? []);
$kbTrashed = (int) ($kbData['trashed'] ?? 0);
?>
<div class="row row-cards mb-4">
    <?php foreach ($kbCards as [$kbLabel, $kbValue, $kbIcon, $kbHref]): ?>
        <div class="col-sm-6 col-xl-3">
            <div class="card card-sm">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="avatar bg-primary-lt"><i class="ti ti-<?= $kbE($kbIcon) ?>" aria-hidden="true"></i></span>
                    <div>
                        <div class="h2 mb-0"><?= $kbE($kbNumber($kbValue)) ?></div>
                        <div class="text-secondary">
                            <?php if ($kbHref !== ''): ?><a class="text-reset" href="<?= $kbE($kbHref) ?>"><?= $kbT($kbLabel) ?></a><?php else: ?><?= $kbT($kbLabel) ?><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><?= $kbT('knowledgebase.admin.latest_title') ?></h3>
                <div class="card-actions"><a href="<?= $kbE(KnowledgebaseModule::url(['section' => 'entries'])) ?>"><?= $kbT('knowledgebase.admin.show_all') ?></a></div>
            </div>
            <?php if ($kbLatest === []): ?>
                <div class="card-body">
                    <p class="text-secondary mb-3"><?= $kbT('knowledgebase.admin.empty_entries') ?></p>
                    <a class="btn btn-primary" href="<?= $kbE(KnowledgebaseModule::url(['section' => 'settings', 'tab' => 'import'])) ?>"><i class="ti ti-file-import me-1" aria-hidden="true"></i><?= $kbT('knowledgebase.admin.go_import') ?></a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead><tr><th><?= $kbT('knowledgebase.admin.col_title') ?></th><th><?= $kbT('knowledgebase.admin.col_category') ?></th><th><?= $kbT('knowledgebase.admin.col_status') ?></th><th><?= $kbT('knowledgebase.admin.col_updated') ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($kbLatest as $kbRow): ?>
                            <tr>
                                <td><a href="<?= $kbE(KnowledgebaseModule::url(['section' => 'edit', 'id' => (int) $kbRow['id']])) ?>"><?= $kbE($kbRow['title'] ?? '') ?></a></td>
                                <td class="text-secondary"><?= $kbE(($kbRow['category'] ?? '') !== '' ? $kbRow['category'] : '–') ?></td>
                                <td><span class="badge <?= (int) ($kbRow['is_active'] ?? 0) === 1 ? 'bg-green-lt' : 'bg-secondary-lt' ?>"><?= $kbT((int) ($kbRow['is_active'] ?? 0) === 1 ? 'knowledgebase.admin.status_active' : 'knowledgebase.admin.status_inactive') ?></span></td>
                                <td class="text-secondary text-nowrap"><?= $kbE($kbDate($kbRow['updated_at'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title"><?= $kbT('knowledgebase.admin.status_title') ?></h3></div>
            <div class="list-group list-group-flush">
                <?php foreach (['enable_autolink' => 'knowledgebase.admin.set_autolink', 'enable_tooltips' => 'knowledgebase.admin.set_tooltips', 'show_nav_link' => 'knowledgebase.admin.set_nav_link', 'enable_output_buffer' => 'knowledgebase.admin.set_output_buffer'] as $kbKey => $kbLabel): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <span><?= $kbT($kbLabel) ?></span>
                        <span class="badge <?= $kbOn($kbKey) ? 'bg-green-lt' : 'bg-secondary-lt' ?>"><?= $kbT($kbOn($kbKey) ? 'knowledgebase.admin.on' : 'knowledgebase.admin.off') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="card-footer"><a href="<?= $kbE(KnowledgebaseModule::url(['section' => 'settings'])) ?>"><?= $kbT('knowledgebase.admin.edit_settings') ?></a></div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?= $kbT('knowledgebase.admin.links_title') ?></h3></div>
            <div class="list-group list-group-flush">
                <a class="list-group-item list-group-item-action" href="/kb" target="_blank" rel="noopener"><i class="ti ti-book me-2" aria-hidden="true"></i><code>/kb</code></a>
                <a class="list-group-item list-group-item-action" href="/glossar" target="_blank" rel="noopener"><i class="ti ti-abc me-2" aria-hidden="true"></i><code>/glossar</code></a>
                <a class="list-group-item list-group-item-action" href="/glossar-sitemap.xml" target="_blank" rel="noopener"><i class="ti ti-sitemap me-2" aria-hidden="true"></i><code>/glossar-sitemap.xml</code></a>
                <?php if ($kbTrashed > 0): ?>
                    <a class="list-group-item list-group-item-action" href="/admin/trash?type=kb_entry"><i class="ti ti-trash me-2" aria-hidden="true"></i><?= $kbT('knowledgebase.admin.trashed_count', $kbTrashed) ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
