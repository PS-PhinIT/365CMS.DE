<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formulareingänge – Rahmen (Eingänge, DSGVO-Exporte) mit Liste, Detailansicht und Exportdateien.
 *
 * @var array<string, mixed> $data
 * @var string $csrfToken
 * @var array|null $alert
 */

$fsData = is_array($data ?? null) ? $data : [];
$fsTab = (string) ($fsData['tab'] ?? 'inbox');
$fsView = in_array($fsData['view'] ?? '', ['list', 'detail', 'privacy'], true) ? (string) $fsData['view'] : 'list';
$fsCanManage = !empty($fsData['can_manage']);
$fsE = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$fsT = static function (string $key, mixed ...$args) use ($fsE): string {
    $text = (string) __($key);

    return $fsE($args !== [] ? vsprintf($text, $args) : $text);
};
$fsDate = static function (?string $value): string {
    $time = $value !== null && $value !== '' ? strtotime($value) : false;

    return $time !== false ? date('d.m.Y H:i', $time) : '–';
};
$fsStatusBadge = ['new' => 'bg-blue-lt', 'read' => 'bg-secondary-lt', 'done' => 'bg-green-lt', 'trash' => 'bg-red-lt'];
$fsTabs = ['inbox' => 'forms.inbox.tab_inbox', 'privacy' => 'forms.inbox.tab_privacy'];
$fsFormsUrl = is_file(ABSPATH . 'admin/forms.php') && \CMS\Auth::instance()->hasCapability('manage_forms') ? '/admin/forms' : '';
$fsShowPrivacyTab = $fsTab === 'privacy' || (int) ($fsData['privacy_exports'] ?? 0) > 0;
?>

<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle"><?= $fsT('forms.admin.pretitle') ?></div>
                <h2 class="page-title"><?= $fsT('forms.inbox.page_title') ?></h2>
            </div>
            <div class="col-auto ms-auto d-flex gap-2">
                <?php if ($fsView === 'detail'): ?>
                    <a class="btn" href="/admin/form-submissions"><i class="ti ti-arrow-left me-1" aria-hidden="true"></i><?= $fsT('forms.inbox.back') ?></a>
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

        <?php if (empty($fsData['available'])): ?>
            <div class="alert alert-warning" role="alert"><?= $fsT('forms.admin.unavailable') ?></div>
        <?php else: ?>
            <?php if ($fsView !== 'detail' && ($fsShowPrivacyTab || $fsFormsUrl !== '')): ?>
                <ul class="nav nav-tabs mb-4">
                    <?php if ($fsFormsUrl !== ''): ?>
                        <li class="nav-item"><a class="nav-link" href="<?= $fsE($fsFormsUrl) ?>"><?= $fsT('forms.admin.tab_forms') ?></a></li>
                    <?php endif; ?>
                    <?php foreach ($fsTabs as $fsTabKey => $fsTabLabel): ?>
                        <?php if ($fsTabKey === 'privacy' && !$fsShowPrivacyTab) { continue; } ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $fsTab === $fsTabKey ? ' active' : '' ?>"<?= $fsTab === $fsTabKey ? ' aria-current="page"' : '' ?>
                               href="<?= $fsE($fsTabKey === 'inbox' ? '/admin/form-submissions' : '/admin/form-submissions?tab=' . $fsTabKey) ?>"><?= $fsT($fsTabLabel) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php require __DIR__ . '/submissions-' . $fsView . '.php'; ?>
        <?php endif; ?>
    </div>
</div>
