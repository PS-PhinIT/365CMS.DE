<?php
declare(strict_types=1);

/**
 * @var array<string, mixed> $data filters, types, users, result{items,total,page,pages}
 */

if (!defined('ABSPATH')) {
    exit;
}

$ccEsc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$ccT = static fn (string $key, string|int ...$args): string => htmlspecialchars($args !== [] ? vsprintf((string) __($key), $args) : (string) __($key), ENT_QUOTES, 'UTF-8');
$ccFilters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
$ccTypes = is_array($data['types'] ?? null) ? $data['types'] : [];
$ccResult = is_array($data['result'] ?? null) ? $data['result'] : ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$ccStatus = ['published' => 'Veröffentlicht', 'draft' => 'Entwurf', 'private' => 'Privat', 'scheduled' => 'Geplant', 'trash' => 'Papierkorb', 'pending' => 'Ausstehend'];
$ccStatusLabel = static fn (string $status): string => (string) __('content_changes.status.' . $status) !== 'content_changes.status.' . $status ? (string) __('content_changes.status.' . $status) : ($ccStatus[$status] ?? $status);
$ccQuery = array_filter([
    'type' => (string) ($ccFilters['type'] ?? ''),
    'user' => (int) ($ccFilters['user_id'] ?? 0) ?: null,
    'change' => (string) ($ccFilters['change'] ?? ''),
    'from' => (string) ($ccFilters['from'] ?? ''),
    'to' => (string) ($ccFilters['to'] ?? ''),
], static fn ($value): bool => $value !== '' && $value !== null);
$ccPageUrl = static fn (int $page): string => '/admin/content-changes?' . http_build_query($ccQuery + ($page > 1 ? ['page' => $page] : []));
$ccBadge = ['created' => 'bg-green-lt', 'updated' => 'bg-blue-lt', 'status' => 'bg-purple-lt', 'restored' => 'bg-orange-lt', 'autosave' => 'bg-secondary-lt'];
$ccDate = static function (string $value): string {
    $time = $value !== '' ? strtotime($value) : false;

    return $time !== false ? date('d.m.Y H:i', $time) : '–';
};
?>
<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle"><?php echo $ccT('content_changes.pretitle'); ?></div>
                <h2 class="page-title mb-1"><?php echo $ccT('content_changes.title'); ?></h2>
                <div class="content-listing-header__meta"><span><?php echo $ccT('content_changes.total', (int) $ccResult['total']); ?></span></div>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <div class="card content-listing-card">
            <div class="card-header content-listing-toolbar">
                <form method="get" action="/admin/content-changes" class="content-listing-filters flex-wrap">
                    <?php if (count($ccTypes) > 1): ?>
                        <div class="content-listing-filters__group">
                            <label class="form-label mb-0 small text-secondary" for="ccType"><?php echo $ccT('content_changes.filter.type'); ?></label>
                            <select class="form-select form-select-sm" id="ccType" name="type">
                                <option value=""><?php echo $ccT('content_changes.filter.all'); ?></option>
                                <?php foreach ($ccTypes as $ccTypeKey): ?>
                                    <option value="<?php echo $ccEsc($ccTypeKey); ?>"<?php echo ($ccFilters['type'] ?? '') === $ccTypeKey ? ' selected' : ''; ?>><?php echo $ccT('content_changes.type.' . $ccTypeKey); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="content-listing-filters__group">
                        <label class="form-label mb-0 small text-secondary" for="ccUser"><?php echo $ccT('content_changes.filter.user'); ?></label>
                        <select class="form-select form-select-sm" id="ccUser" name="user">
                            <option value=""><?php echo $ccT('content_changes.filter.all'); ?></option>
                            <?php foreach ($data['users'] ?? [] as $ccUser): ?>
                                <option value="<?php echo (int) $ccUser['id']; ?>"<?php echo (int) ($ccFilters['user_id'] ?? 0) === (int) $ccUser['id'] ? ' selected' : ''; ?>><?php echo $ccEsc($ccUser['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="content-listing-filters__group">
                        <label class="form-label mb-0 small text-secondary" for="ccChange"><?php echo $ccT('content_changes.filter.change'); ?></label>
                        <select class="form-select form-select-sm" id="ccChange" name="change">
                            <option value=""><?php echo $ccT('content_changes.filter.all'); ?></option>
                            <?php foreach (\CMS\Services\ContentChangesService::CHANGES as $ccChange): ?>
                                <option value="<?php echo $ccEsc($ccChange); ?>"<?php echo ($ccFilters['change'] ?? '') === $ccChange ? ' selected' : ''; ?>><?php echo $ccT('content_changes.change.' . $ccChange); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="content-listing-filters__group">
                        <label class="form-label mb-0 small text-secondary" for="ccFrom"><?php echo $ccT('content_changes.filter.from'); ?></label>
                        <input class="form-control form-control-sm" type="date" id="ccFrom" name="from" value="<?php echo $ccEsc($ccFilters['from'] ?? ''); ?>">
                    </div>
                    <div class="content-listing-filters__group">
                        <label class="form-label mb-0 small text-secondary" for="ccTo"><?php echo $ccT('content_changes.filter.to'); ?></label>
                        <input class="form-control form-control-sm" type="date" id="ccTo" name="to" value="<?php echo $ccEsc($ccFilters['to'] ?? ''); ?>">
                    </div>
                    <div class="content-listing-filters__group align-self-end">
                        <button class="btn btn-sm btn-primary" type="submit"><?php echo $ccT('content_changes.filter.apply'); ?></button>
                        <?php if ($ccQuery !== []): ?>
                            <a class="btn btn-sm btn-link" href="/admin/content-changes"><?php echo $ccT('content_changes.filter.reset'); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <?php if (($ccResult['items'] ?? []) === []): ?>
                <div class="card-body text-secondary"><?php echo $ccT('content_changes.empty'); ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th><?php echo $ccT('content_changes.col.date'); ?></th>
                                <th><?php echo $ccT('content_changes.col.content'); ?></th>
                                <th><?php echo $ccT('content_changes.col.change'); ?></th>
                                <th><?php echo $ccT('content_changes.col.user'); ?></th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ccResult['items'] as $ccItem): ?>
                                <tr>
                                    <td class="text-nowrap"><?php echo $ccEsc($ccDate((string) $ccItem['changed_at'])); ?></td>
                                    <td>
                                        <div class="fw-bold"><?php echo $ccEsc($ccItem['title'] !== '' ? $ccItem['title'] : '#' . $ccItem['id']); ?></div>
                                        <div class="small text-secondary">
                                            <?php echo $ccT('content_changes.type.' . $ccItem['type']); ?> · #<?php echo (int) $ccItem['id']; ?>
                                            <?php if (!empty($ccItem['trashed'])): ?> · <span class="text-danger"><?php echo $ccT('content_changes.trashed'); ?></span><?php endif; ?>
                                            <?php if (!empty($ccItem['deleted'])): ?> · <span class="text-danger"><?php echo $ccT('content_changes.deleted'); ?></span><?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $ccBadge[$ccItem['change']] ?? 'bg-secondary-lt'; ?>"><?php echo $ccT('content_changes.change.' . $ccItem['change']); ?></span>
                                        <?php if ($ccItem['change'] === 'status' && $ccItem['previous_status'] !== ''): ?>
                                            <div class="small text-secondary"><?php echo $ccEsc($ccStatusLabel((string) $ccItem['previous_status']) . ' → ' . $ccStatusLabel((string) $ccItem['status'])); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $ccItem['user'] !== '' ? $ccEsc($ccItem['user']) : '<span class="text-secondary">' . $ccT('content_changes.unknown_user') . '</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <?php if ($ccItem['edit_url'] !== ''): ?>
                                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo $ccEsc($ccItem['edit_url']); ?>"><?php echo !empty($ccItem['compare']) ? $ccT('content_changes.open_compare') : $ccT('content_changes.open_editor'); ?></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ((int) $ccResult['pages'] > 1): $ccPage = (int) $ccResult['page']; ?>
                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <?php if ($ccPage > 1): ?><a class="btn btn-sm" href="<?php echo $ccEsc($ccPageUrl($ccPage - 1)); ?>"><?php echo $ccT('content_changes.prev'); ?></a><?php else: ?><span></span><?php endif; ?>
                        <span class="small text-secondary"><?php echo $ccT('content_changes.page_of', $ccPage, (int) $ccResult['pages']); ?></span>
                        <?php if ($ccPage < (int) $ccResult['pages']): ?><a class="btn btn-sm" href="<?php echo $ccEsc($ccPageUrl($ccPage + 1)); ?>"><?php echo $ccT('content_changes.next'); ?></a><?php else: ?><span></span><?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
