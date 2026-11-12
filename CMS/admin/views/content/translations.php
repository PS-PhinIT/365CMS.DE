<?php
declare(strict_types=1);

/**
 * @var array<string, mixed> $data available, filters, types, locales, ai, result{items,counts,total,page,pages}
 * @var string $csrfToken
 */

if (!defined('ABSPATH')) {
    exit;
}

$trsEsc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$trsT = static fn (string $key, string|int ...$args): string => htmlspecialchars($args !== [] ? vsprintf((string) __($key), $args) : (string) __($key), ENT_QUOTES, 'UTF-8');
$trsFilters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
$trsTypes = is_array($data['types'] ?? null) ? $data['types'] : [];
$trsLocales = is_array($data['locales'] ?? null) ? $data['locales'] : ['en'];
$trsResult = is_array($data['result'] ?? null) ? $data['result'] : ['items' => [], 'counts' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$trsCounts = is_array($trsResult['counts'] ?? null) ? $trsResult['counts'] : [];
$trsQuery = array_filter([
    'type' => (string) ($trsFilters['type'] ?? ''),
    'locale' => (string) ($trsFilters['locale'] ?? ''),
    'status' => (string) ($trsFilters['status'] ?? ''),
    'q' => (string) ($trsFilters['search'] ?? ''),
], static fn (string $value): bool => $value !== '');
$trsUrl = static fn (array $extra = []): string => '/admin/translations' . (($query = http_build_query(array_filter($trsQuery + $extra, static fn ($v) => $v !== '' && $v !== null))) !== '' ? '?' . $query : '');
$trsReturn = $trsUrl(['page' => (int) $trsResult['page'] > 1 ? (int) $trsResult['page'] : null]);
$trsBadge = ['missing' => 'bg-red-lt', 'current' => 'bg-green-lt', 'outdated' => 'bg-yellow-lt'];
$trsCsrf = '<input type="hidden" name="csrf_token" value="' . $trsEsc($csrfToken) . '">';
?>
<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle"><?php echo $trsT('translations_status.pretitle'); ?></div>
                <h2 class="page-title mb-1"><?php echo $trsT('translations_status.title'); ?></h2>
                <div class="content-listing-header__meta">
                    <?php foreach (['missing', 'outdated', 'current'] as $trsStatus): ?>
                        <a class="<?php echo ($trsFilters['status'] ?? '') === $trsStatus ? 'fw-bold' : 'text-reset'; ?>" href="<?php echo $trsEsc('/admin/translations?' . http_build_query(['status' => $trsStatus] + array_diff_key($trsQuery, ['status' => 1]))); ?>">
                            <?php echo $trsT('translations_status.count.' . $trsStatus, (int) ($trsCounts[$trsStatus] ?? 0)); ?>
                        </a>
                    <?php endforeach; ?>
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

        <?php if (empty($data['available'])): ?>
            <div class="alert alert-warning"><?php echo $trsT('translations_status.unavailable'); ?></div>
        <?php else: ?>
        <div class="card content-listing-card">
            <div class="card-header content-listing-toolbar">
                <form method="get" action="/admin/translations" class="content-listing-filters flex-wrap">
                    <?php if (count($trsTypes) > 1): ?>
                        <div class="content-listing-filters__group">
                            <label class="form-label mb-0 small text-secondary" for="trsType"><?php echo $trsT('translations_status.filter.type'); ?></label>
                            <select class="form-select form-select-sm" id="trsType" name="type">
                                <option value=""><?php echo $trsT('translations_status.filter.all'); ?></option>
                                <?php foreach ($trsTypes as $trsType): ?>
                                    <option value="<?php echo $trsEsc($trsType); ?>"<?php echo ($trsFilters['type'] ?? '') === $trsType ? ' selected' : ''; ?>><?php echo $trsT('content_changes.type.' . $trsType); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if (count($trsLocales) > 1): ?>
                        <div class="content-listing-filters__group">
                            <label class="form-label mb-0 small text-secondary" for="trsLocale"><?php echo $trsT('translations_status.filter.locale'); ?></label>
                            <select class="form-select form-select-sm" id="trsLocale" name="locale">
                                <option value=""><?php echo $trsT('translations_status.filter.all'); ?></option>
                                <?php foreach ($trsLocales as $trsLocale): ?>
                                    <option value="<?php echo $trsEsc($trsLocale); ?>"<?php echo ($trsFilters['locale'] ?? '') === $trsLocale ? ' selected' : ''; ?>><?php echo $trsEsc(strtoupper($trsLocale)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="content-listing-filters__group">
                        <label class="form-label mb-0 small text-secondary" for="trsStatus"><?php echo $trsT('translations_status.filter.status'); ?></label>
                        <select class="form-select form-select-sm" id="trsStatus" name="status">
                            <option value=""><?php echo $trsT('translations_status.filter.all'); ?></option>
                            <?php foreach (\CMS\Services\TranslationStatusService::STATUSES as $trsStatus): ?>
                                <option value="<?php echo $trsEsc($trsStatus); ?>"<?php echo ($trsFilters['status'] ?? '') === $trsStatus ? ' selected' : ''; ?>><?php echo $trsT('translations_status.status.' . $trsStatus); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="content-listing-filters__group">
                        <label class="form-label mb-0 small text-secondary" for="trsSearch"><?php echo $trsT('translations_status.filter.search'); ?></label>
                        <input class="form-control form-control-sm" type="search" id="trsSearch" name="q" maxlength="100" value="<?php echo $trsEsc($trsFilters['search'] ?? ''); ?>">
                    </div>
                    <div class="content-listing-filters__group align-self-end">
                        <button class="btn btn-sm btn-primary" type="submit"><?php echo $trsT('translations_status.filter.apply'); ?></button>
                    </div>
                </form>
            </div>

            <?php if (($trsResult['items'] ?? []) === []): ?>
                <div class="card-body text-secondary"><?php echo $trsT('translations_status.empty'); ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th><?php echo $trsT('translations_status.col.content'); ?></th>
                                <th>DE</th>
                                <?php foreach ($trsLocales as $trsLocale): ?>
                                    <th><?php echo $trsEsc(strtoupper($trsLocale)); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trsResult['items'] as $trsItem):
                                $trsEditBase = $trsItem['type'] === 'post' ? '/admin/posts?action=edit&id=' : '/admin/pages?action=edit&id='; ?>
                                <tr>
                                    <td>
                                        <a class="fw-bold" href="<?php echo $trsEsc($trsEditBase . (int) $trsItem['id']); ?>"><?php echo $trsEsc($trsItem['title'] !== '' ? $trsItem['title'] : '#' . $trsItem['id']); ?></a>
                                        <div class="small text-secondary"><?php echo $trsT('content_changes.type.' . $trsItem['type']); ?> · <?php echo $trsT('content_changes.status.' . $trsItem['status']); ?></div>
                                    </td>
                                    <td><span class="badge bg-blue-lt"><?php echo $trsT('translations_status.source'); ?></span></td>
                                    <?php foreach ($trsLocales as $trsLocale):
                                        $trsCell = $trsItem['cells'][$trsLocale] ?? ['status' => 'missing'];
                                        $trsStatus = (string) $trsCell['status']; ?>
                                        <td>
                                            <span class="badge <?php echo $trsBadge[$trsStatus] ?? 'bg-secondary-lt'; ?>"><?php echo $trsT('translations_status.status.' . $trsStatus); ?></span>
                                            <div class="d-flex flex-wrap gap-1 mt-1">
                                                <?php if ($trsStatus === 'missing'): ?>
                                                    <form method="post" action="/admin/translations" class="d-inline">
                                                        <?php echo $trsCsrf; ?>
                                                        <input type="hidden" name="action" value="create">
                                                        <input type="hidden" name="type" value="<?php echo $trsEsc($trsItem['type']); ?>">
                                                        <input type="hidden" name="id" value="<?php echo (int) $trsItem['id']; ?>">
                                                        <input type="hidden" name="locale" value="<?php echo $trsEsc($trsLocale); ?>">
                                                        <input type="hidden" name="return" value="<?php echo $trsEsc($trsReturn); ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo $trsT('translations_status.action.create'); ?></button>
                                                    </form>
                                                <?php else: ?>
                                                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo $trsEsc($trsEditBase . (int) $trsItem['id'] . '&lang=' . $trsLocale); ?>"><?php echo $trsT('translations_status.action.edit'); ?></a>
                                                <?php endif; ?>
                                                <?php if (!empty($data['ai']) && $trsStatus !== 'current'): ?>
                                                    <a class="btn btn-sm btn-outline-primary" href="<?php echo $trsEsc($trsEditBase . (int) $trsItem['id'] . '&lang=' . $trsLocale . '&ai_translate=1'); ?>"><?php echo $trsT('translations_status.action.ai'); ?></a>
                                                <?php endif; ?>
                                                <?php if ($trsStatus === 'outdated'): ?>
                                                    <form method="post" action="/admin/translations" class="d-inline">
                                                        <?php echo $trsCsrf; ?>
                                                        <input type="hidden" name="action" value="mark_current">
                                                        <input type="hidden" name="type" value="<?php echo $trsEsc($trsItem['type']); ?>">
                                                        <input type="hidden" name="id" value="<?php echo (int) $trsItem['id']; ?>">
                                                        <input type="hidden" name="locale" value="<?php echo $trsEsc($trsLocale); ?>">
                                                        <input type="hidden" name="return" value="<?php echo $trsEsc($trsReturn); ?>">
                                                        <button type="submit" class="btn btn-sm btn-ghost-success"><?php echo $trsT('translations_status.action.mark'); ?></button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ((int) $trsResult['pages'] > 1): $trsPage = (int) $trsResult['page']; ?>
                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <?php if ($trsPage > 1): ?><a class="btn btn-sm" href="<?php echo $trsEsc($trsUrl(['page' => $trsPage - 1 > 1 ? $trsPage - 1 : null])); ?>"><?php echo $trsT('content_changes.prev'); ?></a><?php else: ?><span></span><?php endif; ?>
                        <span class="small text-secondary"><?php echo $trsT('content_changes.page_of', $trsPage, (int) $trsResult['pages']); ?></span>
                        <?php if ($trsPage < (int) $trsResult['pages']): ?><a class="btn btn-sm" href="<?php echo $trsEsc($trsUrl(['page' => $trsPage + 1])); ?>"><?php echo $trsT('content_changes.next'); ?></a><?php else: ?><span></span><?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <p class="text-secondary small mt-3"><?php echo $trsT('translations_status.hint'); ?></p>
        <?php endif; ?>
    </div>
</div>
