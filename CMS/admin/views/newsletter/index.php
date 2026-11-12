<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Newsletter – Rahmen mit Bereichs-Tabs.
 *
 * @var array<string, mixed> $data
 * @var string $csrfToken
 * @var array|null $alert
 */

$nlData = is_array($data ?? null) ? $data : [];
$nlView = in_array($nlData['view'] ?? '', NewsletterModule::SECTIONS, true) ? (string) $nlData['view'] : 'dashboard';
$nlE = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$nlT = static function (string $key, mixed ...$args) use ($nlE): string {
    $text = (string) __($key);

    return $nlE($args !== [] ? vsprintf($text, $args) : $text);
};
$nlDate = static function (mixed $value, bool $time = true): string {
    $ts = is_string($value) && $value !== '' ? strtotime($value) : false;

    return $ts !== false ? date($time ? 'd.m.Y H:i' : 'd.m.Y', $ts) : '–';
};
$nlNumber = static fn (int|float $v): string => number_format((float) $v, 0, ',', '.');
$nlCsrf = '<input type="hidden" name="csrf_token" value="' . $nlE($csrfToken) . '">';
$nlStatusBadge = [
    'active' => 'bg-green-lt', 'pending' => 'bg-yellow-lt', 'unsubscribed' => 'bg-secondary-lt', 'bounced' => 'bg-red-lt',
    'draft' => 'bg-secondary-lt', 'scheduled' => 'bg-blue-lt', 'sending' => 'bg-yellow-lt', 'sent' => 'bg-green-lt', 'cancelled' => 'bg-red-lt',
];
$nlTabs = [
    'dashboard' => ['newsletter.admin.tab_dashboard', 'layout-dashboard', ['dashboard']],
    'subscribers' => ['newsletter.admin.tab_subscribers', 'users', ['subscribers', 'subscriber']],
    'campaigns' => ['newsletter.admin.tab_campaigns', 'send', ['campaigns', 'campaign']],
    'templates' => ['newsletter.admin.tab_templates', 'template', ['templates', 'template']],
    'settings' => ['newsletter.admin.tab_settings', 'settings', ['settings']],
];
?>

<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle"><?= $nlT('newsletter.admin.pretitle') ?></div>
                <h2 class="page-title"><?= $nlT('newsletter.admin.page_title') ?></h2>
            </div>
            <div class="col-auto ms-auto d-flex flex-wrap gap-2">
                <a class="btn" href="/newsletter" target="_blank" rel="noopener"><i class="ti ti-external-link me-1" aria-hidden="true"></i><?= $nlT('newsletter.admin.open_public') ?></a>
                <a class="btn btn-primary" href="<?= $nlE(NewsletterModule::url(['section' => 'campaign'])) ?>"><i class="ti ti-plus me-1" aria-hidden="true"></i><?= $nlT('newsletter.admin.new_campaign') ?></a>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page admin-newsletter-page">
    <div class="container-xl admin-redesign-shell">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-4">
            <?php foreach ($nlTabs as $nlKey => [$nlLabel, $nlIcon, $nlViews]): ?>
                <?php $nlActive = in_array($nlView, $nlViews, true); ?>
                <li class="nav-item">
                    <a class="nav-link<?= $nlActive ? ' active' : '' ?>"<?= $nlActive ? ' aria-current="page"' : '' ?> href="<?= $nlE(NewsletterModule::url(['section' => $nlKey !== 'dashboard' ? $nlKey : null])) ?>"><i class="ti ti-<?= $nlE($nlIcon) ?> me-1" aria-hidden="true"></i><?= $nlT($nlLabel) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php require __DIR__ . '/' . $nlView . '.php'; ?>
    </div>
</div>
