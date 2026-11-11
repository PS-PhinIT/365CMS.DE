<?php
declare(strict_types=1);

/**
 * Zentraler Papierkorb: Typ-Reiter mit Anzahl, Liste mit Wiederherstellen/Endgültig löschen, Leeren je Typ und
 * Frist für das automatische Leeren.
 *
 * @var array<string, mixed> $data
 * @var string $csrfToken
 * @var array|null $alert
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Services\Trash\TrashSupport;

$trEsc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$trT = static fn (string $key, string|int ...$args): string => htmlspecialchars(TrashSupport::t($key, ...$args), ENT_QUOTES, 'UTF-8');
$trTypes = is_array($data['types'] ?? null) ? $data['types'] : [];
$trCounts = is_array($data['counts'] ?? null) ? $data['counts'] : [];
$trType = (string) ($data['type'] ?? '');
$trItems = is_array($data['items'] ?? null) ? $data['items'] : [];
$trSearch = (string) ($data['search'] ?? '');
$trRetention = (int) ($data['retention_days'] ?? 0);
$trTotalAll = array_sum(array_map('intval', $trCounts));
$trDate = static function (mixed $value): string {
    $time = is_string($value) && $value !== '' ? strtotime($value) : false;

    return $time !== false ? date('d.m.Y H:i', $time) : '–';
};
$trUrl = static fn (array $query = []): string => htmlspecialchars(TrashModule::url($query), ENT_QUOTES, 'UTF-8');
$trCsrf = '<input type="hidden" name="csrf_token" value="' . $trEsc($csrfToken) . '">';
?>
<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle"><?php echo $trT('trash.central.pretitle'); ?></div>
                <h2 class="page-title mb-1"><?php echo $trT('trash.central.page_title'); ?></h2>
                <div class="content-listing-header__meta">
                    <span><?php echo $trT('trash.list.bar.count', $trTotalAll); ?></span>
                    <span><?php echo $trRetention > 0 ? $trT('trash.list.bar.retention', $trRetention) : $trT('trash.list.bar.retention_off'); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <?php if (is_array($data['confirmation'] ?? null)) {
            $trashConfirmation = $data['confirmation'];
            $trashConfirmationForm = [
                'action' => '/admin/trash',
                'csrf_token' => (string) $csrfToken,
                'fields' => ['action' => 'delete_permanently', 'type' => (string) ($trashConfirmation['type'] ?? ''), 'confirmed' => '1'],
                'id_field' => 'ids[]',
                'dismiss_url' => TrashModule::url(['type' => (string) ($trashConfirmation['type'] ?? '')]),
            ];
            require __DIR__ . '/../partials/trash-confirmation.php';
        } ?>

        <?php if ($trTypes === []): ?>
            <div class="alert alert-info" role="status"><?php echo $trT('trash.central.no_types'); ?></div>
        <?php else: ?>
            <ul class="nav nav-tabs mb-0" role="list">
                <?php foreach ($trTypes as $trKey => $trLabel): ?>
                    <li class="nav-item">
                        <a class="nav-link<?php echo $trKey === $trType ? ' active' : ''; ?>"<?php echo $trKey === $trType ? ' aria-current="page"' : ''; ?> href="<?php echo $trUrl(['type' => (string) $trKey]); ?>">
                            <?php echo $trEsc($trLabel); ?>
                            <span class="badge <?php echo (int) ($trCounts[$trKey] ?? 0) > 0 ? 'bg-red-lt' : 'bg-secondary-lt'; ?> ms-1"><?php echo (int) ($trCounts[$trKey] ?? 0); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="card content-listing-card mb-4">
                <div class="card-header content-listing-toolbar flex-wrap gap-2">
                    <form method="get" action="/admin/trash" class="d-flex gap-2 me-auto" role="search">
                        <input type="hidden" name="type" value="<?php echo $trEsc($trType); ?>">
                        <label class="visually-hidden" for="trashSearch"><?php echo $trT('trash.central.search'); ?></label>
                        <input type="search" class="form-control form-control-sm" id="trashSearch" name="q" maxlength="100" value="<?php echo $trEsc($trSearch); ?>" placeholder="<?php echo $trT('trash.central.search'); ?>">
                        <button type="submit" class="btn btn-sm"><?php echo $trT('trash.central.search_submit'); ?></button>
                    </form>
                    <?php if ((int) ($trCounts[$trType] ?? 0) > 0): ?>
                        <form method="post" action="/admin/trash"
                              data-confirm-title="<?php echo $trT('trash.action.empty_confirm_title'); ?>"
                              data-confirm-message="<?php echo $trT('trash.central.empty_type_confirm', (string) ($trTypes[$trType] ?? $trType)); ?>"
                              data-confirm-text="<?php echo $trT('trash.action.empty'); ?>" data-confirm-class="btn-danger">
                            <?php echo $trCsrf; ?>
                            <input type="hidden" name="action" value="empty">
                            <input type="hidden" name="type" value="<?php echo $trEsc($trType); ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo $trT('trash.action.empty'); ?></button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if ($trItems === []): ?>
                    <div class="card-body text-secondary">
                        <?php echo $trSearch !== '' ? $trT('trash.central.no_results') : $trT('trash.list.empty'); ?>
                    </div>
                <?php else: ?>
                    <form method="post" action="/admin/trash" id="trashBulkForm" class="card-body border-bottom py-2 d-flex flex-wrap align-items-center gap-2" data-trash-bulk
                          data-delete-title="<?php echo $trT('trash.confirm.bulk_delete_title'); ?>"
                          data-delete-message="<?php echo $trT('trash.confirm.bulk_delete_message'); ?>"
                          data-delete-text="<?php echo $trT('trash.action.delete_permanently'); ?>">
                        <?php echo $trCsrf; ?>
                        <input type="hidden" name="type" value="<?php echo $trEsc($trType); ?>">
                        <label class="visually-hidden" for="trashBulkAction"><?php echo $trT('trash.list.bulk_label'); ?></label>
                        <select class="form-select form-select-sm w-auto" id="trashBulkAction" name="action" required>
                            <option value=""><?php echo $trT('trash.list.bulk_placeholder'); ?></option>
                            <option value="restore"><?php echo $trT('trash.action.restore'); ?></option>
                            <option value="delete_permanently"><?php echo $trT('trash.action.delete_permanently'); ?></option>
                        </select>
                        <button type="submit" class="btn btn-sm"><?php echo $trT('trash.central.apply'); ?></button>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th class="w-1"><input class="form-check-input" type="checkbox" data-trash-check-all aria-label="<?php echo $trT('trash.central.select_all'); ?>"></th>
                                    <th><?php echo $trT('trash.central.col_title'); ?></th>
                                    <th><?php echo $trT('trash.list.column_trashed'); ?></th>
                                    <th class="w-1"><span class="visually-hidden"><?php echo $trT('trash.central.col_actions'); ?></span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($trItems as $trItem): ?>
                                    <tr>
                                        <td><input class="form-check-input" type="checkbox" name="ids[]" value="<?php echo (int) $trItem['id']; ?>" form="trashBulkForm" data-trash-check aria-label="<?php echo $trEsc($trItem['title']); ?>"></td>
                                        <td>
                                            <div class="fw-bold"><?php echo $trEsc($trItem['title']); ?></div>
                                            <?php if ((string) ($trItem['meta']['original_path'] ?? '') !== ''): ?>
                                                <div class="small text-secondary"><?php echo $trEsc($trItem['meta']['original_path']); ?></div>
                                            <?php endif; ?>
                                            <?php if ((string) $trItem['previous_status'] !== ''): ?>
                                                <div class="small text-secondary"><?php echo $trT('trash.list.previous_status', (string) $trItem['previous_status']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-nowrap">
                                            <?php echo $trEsc($trDate($trItem['trashed_at'])); ?>
                                            <?php if ((string) $trItem['trashed_by'] !== ''): ?>
                                                <div class="small text-secondary"><?php echo $trT('trash.list.trashed_by', (string) $trItem['trashed_by']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-nowrap">
                                            <form method="post" action="/admin/trash" class="d-inline">
                                                <?php echo $trCsrf; ?>
                                                <input type="hidden" name="action" value="restore">
                                                <input type="hidden" name="type" value="<?php echo $trEsc($trType); ?>">
                                                <input type="hidden" name="id" value="<?php echo (int) $trItem['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success"><?php echo $trT('trash.action.restore'); ?></button>
                                            </form>
                                            <form method="post" action="/admin/trash" class="d-inline"
                                                  data-confirm-title="<?php echo $trT('trash.confirm.delete_title'); ?>"
                                                  data-confirm-message="<?php echo $trT('trash.confirm.delete_message'); ?>"
                                                  data-confirm-text="<?php echo $trT('trash.action.delete_permanently'); ?>" data-confirm-class="btn-danger">
                                                <?php echo $trCsrf; ?>
                                                <input type="hidden" name="action" value="delete_permanently">
                                                <input type="hidden" name="type" value="<?php echo $trEsc($trType); ?>">
                                                <input type="hidden" name="id" value="<?php echo (int) $trItem['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-ghost-danger"><?php echo $trT('trash.action.delete_permanently'); ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ((int) ($data['pages'] ?? 1) > 1):
                        $trPage = (int) $data['page']; ?>
                        <div class="card-footer d-flex justify-content-between align-items-center">
                            <?php if ($trPage > 1): ?><a class="btn btn-sm" href="<?php echo $trUrl(['type' => $trType, 'q' => $trSearch, 'page' => $trPage - 1]); ?>"><?php echo $trT('trash.central.prev'); ?></a><?php else: ?><span></span><?php endif; ?>
                            <span class="small text-secondary"><?php echo $trT('trash.central.page_of', $trPage, (int) $data['pages']); ?></span>
                            <?php if ($trPage < (int) $data['pages']): ?><a class="btn btn-sm" href="<?php echo $trUrl(['type' => $trType, 'q' => $trSearch, 'page' => $trPage + 1]); ?>"><?php echo $trT('trash.central.next'); ?></a><?php else: ?><span></span><?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/admin/trash" class="card" id="trash-retention">
            <?php echo $trCsrf; ?>
            <input type="hidden" name="action" value="save_retention">
            <input type="hidden" name="type" value="<?php echo $trEsc($trType); ?>">
            <div class="card-header"><h3 class="card-title"><?php echo $trT('trash.central.retention_title'); ?></h3></div>
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-sm-4 col-lg-3">
                        <label class="form-label" for="trashRetentionDays"><?php echo $trT('trash.central.retention_label'); ?></label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="trashRetentionDays" name="retention_days" min="0" max="<?php echo (int) ($data['retention_max'] ?? 3650); ?>" value="<?php echo $trRetention; ?>" required>
                            <span class="input-group-text"><?php echo $trT('trash.central.days'); ?></span>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-hint"><?php echo $trT('trash.central.retention_hint'); ?></div>
                        <?php if ($trRetention > 0 && (int) ($data['expired'] ?? 0) > 0): ?>
                            <div class="small text-warning mt-1"><?php echo $trT('trash.central.expired', (int) $data['expired']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary"><?php echo $trT('trash.central.retention_save'); ?></button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
