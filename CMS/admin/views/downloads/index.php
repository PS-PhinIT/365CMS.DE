<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Downloads – Rahmen mit Tabs (Downloads, Statistik, Kategorien, Einstellungen).
 *
 * @var array<string, mixed> $data
 * @var string $csrfToken
 * @var array|null $alert
 */

$dlData = is_array($data ?? null) ? $data : [];
$dlTab = (string) ($dlData['tab'] ?? 'list');
$dlView = in_array($dlData['view'] ?? '', ['list', 'new', 'edit', 'stats', 'categories', 'settings'], true) ? (string) $dlData['view'] : 'list';
$dlCanManage = !empty($dlData['can_manage']);
$dlE = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$dlT = static function (string $key, mixed ...$args) use ($dlE): string {
    $text = (string) __($key);

    return $dlE($args !== [] ? vsprintf($text, $args) : $text);
};
$dlDate = static function (?string $value, bool $time = true): string {
    $ts = $value !== null && $value !== '' ? strtotime($value) : false;

    return $ts !== false ? date($time ? 'd.m.Y H:i' : 'd.m.Y', $ts) : '–';
};
$dlNumber = static fn (int|float $value): string => number_format((float) $value, 0, ',', '.');
$dlStatusBadge = ['published' => 'bg-green-lt', 'draft' => 'bg-secondary-lt', 'archived' => 'bg-yellow-lt', 'trash' => 'bg-red-lt'];
$dlTabs = $dlCanManage
    ? ['list' => 'downloads.admin.tab_list', 'stats' => 'downloads.admin.tab_stats', 'categories' => 'downloads.admin.tab_categories', 'settings' => 'downloads.admin.tab_settings']
    : ['stats' => 'downloads.admin.tab_stats'];
?>

<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle"><?= $dlT('downloads.admin.pretitle') ?></div>
                <h2 class="page-title"><?= $dlView === 'edit' ? $dlE((string) ($dlData['item']['title'] ?? '')) : $dlT($dlView === 'new' ? 'downloads.admin.new_title' : 'downloads.admin.page_title') ?></h2>
            </div>
            <div class="col-auto ms-auto d-flex gap-2">
                <?php if ($dlView === 'edit' || $dlView === 'new'): ?>
                    <a class="btn" href="/admin/downloads"><i class="ti ti-arrow-left me-1" aria-hidden="true"></i><?= $dlT('downloads.admin.back') ?></a>
                <?php endif; ?>
                <?php if ($dlCanManage && $dlView !== 'new' && !empty($dlData['available'])): ?>
                    <a class="btn btn-primary" href="/admin/downloads?action=new"><i class="ti ti-plus me-1" aria-hidden="true"></i><?= $dlT('downloads.admin.new') ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page admin-downloads-page">
    <div class="container-xl admin-redesign-shell">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <?php if (empty($dlData['available']) && $dlView !== 'settings'): ?>
            <div class="alert alert-warning" role="alert"><?= $dlT('downloads.error.unavailable') ?></div>
        <?php else: ?>
            <?php if ($dlView !== 'edit' && $dlView !== 'new' && count($dlTabs) > 1): ?>
                <ul class="nav nav-tabs mb-4">
                    <?php foreach ($dlTabs as $dlTabKey => $dlTabLabel): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $dlTab === $dlTabKey ? ' active' : '' ?>"<?= $dlTab === $dlTabKey ? ' aria-current="page"' : '' ?>
                               href="<?= $dlE($dlTabKey === 'list' ? '/admin/downloads' : '/admin/downloads?tab=' . $dlTabKey) ?>"><?= $dlT($dlTabLabel) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php require __DIR__ . '/' . $dlView . '.php'; ?>
        <?php endif; ?>
    </div>
</div>
