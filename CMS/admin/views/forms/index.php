<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formulare – Rahmen mit Tabs (Formulare, Einstellungen); der Baukasten ersetzt die Liste.
 *
 * @var array<string, mixed> $data
 * @var string $csrfToken
 * @var array|null $alert
 */

$fmData = is_array($data ?? null) ? $data : [];
$fmTab = (string) ($fmData['tab'] ?? 'forms');
$fmView = (string) ($fmData['view'] ?? 'list');
$fmE = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$fmT = static function (string $key, mixed ...$args) use ($fmE): string {
    $text = (string) __($key);

    return $fmE($args !== [] ? vsprintf($text, $args) : $text);
};
$fmDate = static function (string $value): string {
    $time = $value !== '' ? strtotime($value) : false;

    return $time !== false ? date('d.m.Y H:i', $time) : '–';
};
$fmDays = static function (int $days): string {
    if ($days <= 0) {
        return (string) __('forms.admin.retention_never');
    }

    return sprintf((string) __('forms.admin.retention_days'), $days);
};
$fmTabs = ['forms' => 'forms.admin.tab_forms', 'settings' => 'forms.admin.tab_settings'];
$fmViewFile = in_array($fmView, ['list', 'edit', 'settings'], true) ? $fmView : 'list';
$fmSubmissionsUrl = (string) ($fmData['submissions_url'] ?? '');
?>

<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle"><?= $fmT('forms.admin.pretitle') ?></div>
                <h2 class="page-title">
                    <?= $fmView === 'edit' ? $fmE((string) ($fmData['form']['title'] ?? '')) : $fmT('forms.admin.page_title') ?>
                </h2>
            </div>
            <div class="col-auto ms-auto d-flex gap-2">
                <?php if ($fmView === 'edit'): ?>
                    <a class="btn" href="/admin/forms"><i class="ti ti-arrow-left me-1" aria-hidden="true"></i><?= $fmT('forms.admin.back_to_list') ?></a>
                <?php endif; ?>
                <?php if ($fmSubmissionsUrl !== ''): ?>
                    <a class="btn" href="<?= $fmE($fmSubmissionsUrl) ?>"><i class="ti ti-inbox me-1" aria-hidden="true"></i><?= $fmT('forms.admin.open_submissions') ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page admin-forms-page">
    <div class="container-xl admin-redesign-shell">

        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <?php if (empty($fmData['available'])): ?>
            <div class="alert alert-warning" role="alert"><?= $fmT('forms.admin.unavailable') ?></div>
        <?php else: ?>
            <?php if ($fmView !== 'edit'): ?>
                <ul class="nav nav-tabs mb-4">
                    <?php foreach ($fmTabs as $fmTabKey => $fmTabLabel): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $fmTab === $fmTabKey ? ' active' : '' ?>"<?= $fmTab === $fmTabKey ? ' aria-current="page"' : '' ?>
                               href="<?= $fmE($fmTabKey === 'forms' ? '/admin/forms' : '/admin/forms?tab=' . $fmTabKey) ?>"><?= $fmT($fmTabLabel) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php require __DIR__ . '/' . $fmViewFile . '.php'; ?>
        <?php endif; ?>
    </div>
</div>
