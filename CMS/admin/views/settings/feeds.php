<?php
/**
 * Einstellungen → Feeds: Rahmen mit Unternavigation des Feed-Aggregators und des RSS-Ausgangs.
 *
 * @var string                $feedsSection
 * @var bool                  $feedsReady
 * @var array<string,mixed>   $feedsData
 * @var array<string,mixed>   $feedsSettings
 * @var string                $feedsCsrfToken
 * @var array<string,string>|null $feedsAlert
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$feedsEscape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$feedsT = static fn (string $key, string|int ...$args): string => htmlspecialchars(FeedAggregatorModule::t($key, ...$args), ENT_QUOTES, 'UTF-8');
$feedsAlert = is_array($feedsAlert ?? null) ? $feedsAlert : null;
$feedsCsrfField = '<input type="hidden" name="csrf_token" value="' . $feedsEscape($feedsCsrfToken) . '">';
$feedsUrl = static fn (array $query = []): string => htmlspecialchars(FeedAggregatorModule::url($query), ENT_QUOTES, 'UTF-8');
$feedsNav = [
    'overview' => 'nav.overview',
    'sources' => 'nav.sources',
    'items' => 'nav.items',
    'categories' => 'nav.categories',
    'digests' => 'nav.digests',
    'subscriptions' => 'nav.subscriptions',
    'log' => 'nav.log',
    'import' => 'nav.import',
    'settings' => 'nav.settings',
];
$feedsDate = static function (mixed $value, bool $withTime = true): string {
    $time = is_string($value) && $value !== '' ? strtotime($value) : false;

    return $time !== false ? date($withTime ? 'd.m.Y H:i' : 'd.m.Y', $time) : '–';
};
?>
<div class="page-wrapper">
    <div class="page-header d-print-none">
        <div class="container-xl">
            <h1 class="page-title"><?= $feedsEscape((string) __('admin.feeds.title')) ?></h1>
        </div>
    </div>
    <div class="page-body">
        <div class="container-xl">
            <?php require __DIR__ . '/partials-tabs.php'; ?>

            <nav class="cms-fd-subnav mt-3" aria-label="<?= $feedsT('nav.label') ?>">
                <ul class="nav nav-pills flex-wrap gap-1">
                    <?php if ($feedsReady) : ?>
                        <?php foreach ($feedsNav as $feedsKey => $feedsLabel) : ?>
                            <li class="nav-item">
                                <a class="nav-link<?= $feedsSection === $feedsKey ? ' active' : '' ?>"<?= $feedsSection === $feedsKey ? ' aria-current="page"' : '' ?>
                                   href="<?= $feedsUrl(['section' => $feedsKey !== 'overview' ? $feedsKey : null]) ?>">
                                    <?= $feedsT($feedsLabel) ?>
                                    <?php if ($feedsKey === 'sources' && (int) ($feedsData['error_count'] ?? 0) > 0) : ?>
                                        <span class="badge bg-red text-red-fg ms-1"><?= (int) $feedsData['error_count'] ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $feedsSection === 'rss' ? ' active' : '' ?>"<?= $feedsSection === 'rss' ? ' aria-current="page"' : '' ?> href="<?= $feedsUrl(['section' => 'rss']) ?>"><?= $feedsT('nav.rss') ?></a>
                    </li>
                </ul>
            </nav>

            <?php if ($feedsAlert !== null) : ?>
                <div class="alert alert-<?= $feedsEscape((string) ($feedsAlert['type'] ?? 'info')) ?> mt-3" role="status">
                    <?= $feedsEscape((string) ($feedsAlert['message'] ?? '')) ?>
                </div>
            <?php endif; ?>

            <?php if (!$feedsReady && $feedsSection !== 'rss') : ?>
                <div class="alert alert-warning mt-3" role="status"><?= $feedsT('not_ready') ?></div>
            <?php else : ?>
                <?php if ($feedsSection !== 'rss' && $feedsSection !== 'settings' && empty($feedsData['enabled'])) : ?>
                    <div class="alert alert-info mt-3" role="status">
                        <?= $feedsT('disabled_notice') ?>
                        <a href="<?= $feedsUrl(['section' => 'settings']) ?>"><?= $feedsT('disabled_link') ?></a>
                    </div>
                <?php endif; ?>
                <?php require __DIR__ . '/feeds/' . $feedsSection . '.php'; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
