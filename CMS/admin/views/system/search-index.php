<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Suchindex verwalten – Rahmen mit Tabs (Status, Suchbegriffe, Einstellungen)
 *
 * @var array<string, mixed> $data
 * @var string $csrfToken
 * @var array|null $alert
 */

$siData = is_array($data ?? null) ? $data : [];
$siTab = (string) ($siData['tab'] ?? 'status');
$siE = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$siT = static function (string $key, mixed ...$args) use ($siE): string {
    $text = (string) __($key);

    return $siE($args !== [] ? vsprintf($text, $args) : $text);
};
$siFormatTime = static function (string $value, string $formatKey, string $fallback): string {
    $time = $value !== '' ? strtotime($value) : false;
    if ($time === false) {
        return '–';
    }
    $format = (string) __($formatKey);

    return date($format !== '' && $format !== $formatKey ? $format : $fallback, $time);
};
$siDate = static fn (string $value): string => $siFormatTime($value, 'search_index.datetime_format', 'd.m.Y H:i');
$siDay = static fn (string $value): string => $siFormatTime($value, 'search_index.date_format', 'd.m.Y');
$siBytes = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB'];
    $value = $bytes / 1024;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return number_format($value, 1, ',', '.') . ' ' . $units[$unit];
};
$siNumber = static fn (int|float $value, int $decimals = 0): string => number_format((float) $value, $decimals, ',', '.');
$siTabs = [
    'status' => 'search_index.tab_status',
    'stats' => 'search_index.tab_stats',
    'settings' => 'search_index.tab_settings',
];
?>

<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle"><?= $siT('search_index.pretitle') ?></div>
                <h2 class="page-title"><?= $siT('search_index.page_title') ?></h2>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">

        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-4">
            <?php foreach ($siTabs as $siTabKey => $siTabLabel): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $siTab === $siTabKey ? ' active' : '' ?>"<?= $siTab === $siTabKey ? ' aria-current="page"' : '' ?>
                       href="<?= $siE($siTabKey === 'status' ? '/admin/search-index' : '/admin/search-index?tab=' . $siTabKey) ?>"><?= $siT($siTabLabel) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php require __DIR__ . '/search-index-' . (array_key_exists($siTab, $siTabs) ? $siTab : 'status') . '.php'; ?>
    </div>
</div>
