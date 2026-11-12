<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Knowledgebase – Rahmen mit Bereichs-Tabs (Übersicht, Einträge, Kategorien, Einstellungen) und Editor.
 *
 * @var array<string, mixed> $data
 * @var string $csrfToken
 * @var array|null $alert
 */

$kbData = is_array($data ?? null) ? $data : [];
$kbView = in_array($kbData['view'] ?? '', ['dashboard', 'entries', 'edit', 'categories', 'settings'], true) ? (string) $kbData['view'] : 'dashboard';
$kbE = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$kbT = static function (string $key, mixed ...$args) use ($kbE): string {
    $text = (string) __($key);

    return $kbE($args !== [] ? vsprintf($text, $args) : $text);
};
$kbDate = static function (mixed $value): string {
    $ts = is_string($value) && $value !== '' ? strtotime($value) : false;

    return $ts !== false ? date('d.m.Y H:i', $ts) : '–';
};
$kbNumber = static fn (int|float $value): string => number_format((float) $value, 0, ',', '.');
$kbTabs = [
    'dashboard' => ['knowledgebase.admin.tab_dashboard', 'layout-dashboard'],
    'entries' => ['knowledgebase.admin.tab_entries', 'list'],
    'categories' => ['knowledgebase.admin.tab_categories', 'category'],
    'settings' => ['knowledgebase.admin.tab_settings', 'settings'],
];
$kbActiveTab = $kbView === 'edit' ? 'entries' : $kbView;
$kbEntry = (array) ($kbData['entry'] ?? []);
?>

<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle"><?= $kbT('knowledgebase.admin.pretitle') ?></div>
                <h2 class="page-title">
                    <?php if ($kbView === 'edit'): ?>
                        <?= !empty($kbData['is_new']) ? $kbT('knowledgebase.admin.new_title') : $kbE((string) ($kbEntry['title'] ?? '')) ?>
                    <?php else: ?>
                        <?= $kbT('knowledgebase.admin.page_title') ?>
                    <?php endif; ?>
                </h2>
            </div>
            <div class="col-auto ms-auto d-flex flex-wrap gap-2">
                <?php if ($kbView === 'edit'): ?>
                    <a class="btn" href="<?= $kbE(KnowledgebaseModule::url(['section' => 'entries'])) ?>"><i class="ti ti-arrow-left me-1" aria-hidden="true"></i><?= $kbT('knowledgebase.admin.back_to_list') ?></a>
                    <?php if (empty($kbData['is_new']) && (string) ($kbEntry['slug'] ?? '') !== '' && empty($kbData['trashed'])): ?>
                        <a class="btn" href="/kb/<?= $kbE(rawurlencode((string) $kbEntry['slug'])) ?>" target="_blank" rel="noopener"><i class="ti ti-external-link me-1" aria-hidden="true"></i><?= $kbT('knowledgebase.admin.view_public') ?></a>
                    <?php endif; ?>
                <?php else: ?>
                    <a class="btn" href="/kb" target="_blank" rel="noopener"><i class="ti ti-external-link me-1" aria-hidden="true"></i><?= $kbT('knowledgebase.admin.open_kb') ?></a>
                <?php endif; ?>
                <?php if ($kbView !== 'edit' || empty($kbData['is_new'])): ?>
                    <a class="btn btn-primary" href="<?= $kbE(KnowledgebaseModule::url(['section' => 'edit'])) ?>"><i class="ti ti-plus me-1" aria-hidden="true"></i><?= $kbT('knowledgebase.admin.new_entry') ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page admin-knowledgebase-page">
    <div class="container-xl admin-redesign-shell">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <?php if ($kbView !== 'edit'): ?>
            <ul class="nav nav-tabs mb-4">
                <?php foreach ($kbTabs as $kbTabKey => [$kbTabLabel, $kbTabIcon]): ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $kbActiveTab === $kbTabKey ? ' active' : '' ?>"<?= $kbActiveTab === $kbTabKey ? ' aria-current="page"' : '' ?>
                           href="<?= $kbE(KnowledgebaseModule::url(['section' => $kbTabKey !== 'dashboard' ? $kbTabKey : null])) ?>"><i class="ti ti-<?= $kbE($kbTabIcon) ?> me-1" aria-hidden="true"></i><?= $kbT($kbTabLabel) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php require __DIR__ . '/' . $kbView . '.php'; ?>
    </div>
</div>
