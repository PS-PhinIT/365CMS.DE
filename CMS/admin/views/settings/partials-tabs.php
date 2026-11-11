<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gemeinsame Tab-Leiste der Einstellungsbereiche (Allgemein, Plugins, MultiSites, Beiträge & Sites
 * sowie die Erweiterungs-Tabs unter `admin/settings-tabs/`).
 *
 * Erwartet: $settingsTabsActive – Schlüssel des aktiven Tabs.
 * Tabs ohne Datei oder ohne Berechtigung werden ausgeblendet.
 */

$settingsTabsActive = isset($settingsTabsActive) ? (string) $settingsTabsActive : '';
$settingsTabsAuth = \CMS\Auth::instance();
$settingsTabsTranslate = static fn (string $text): string => function_exists('__') ? (string) __($text) : $text;
$settingsTabsDefinitions = [
    'general' => ['label' => 'Allgemein', 'url' => '/admin/settings', 'capability' => 'manage_settings'],
    'plugins' => ['label' => 'Plugins', 'url' => \CMS\Services\PluginSiteAssignmentService::ADMIN_PATH, 'capability' => 'manage_settings'],
    'multisites' => ['label' => 'MultiSites', 'url' => \CMS\Services\AdminSiteContextService::ADMIN_PATH, 'capability' => 'manage_settings'],
    'feeds' => ['label' => 'Feeds', 'url' => '/admin/settings?tab=feeds', 'capability' => 'manage_feeds', 'file' => 'feeds.php'],
    'security' => ['label' => 'Sicherheit', 'url' => '/admin/settings?tab=security', 'capability' => 'manage_settings', 'file' => 'security.php'],
    'admin-menu' => ['label' => 'Admin-Menü', 'url' => '/admin/settings?tab=admin-menu', 'capability' => 'manage_admin_menu', 'file' => 'admin-menu.php'],
    'content' => ['label' => 'Beiträge & Sites', 'url' => '/admin/settings?tab=content', 'capability' => 'manage_settings', 'end' => true],
];
?>
<ul class="nav nav-tabs">
    <?php foreach ($settingsTabsDefinitions as $settingsTabKey => $settingsTab):
        if (isset($settingsTab['file']) && !is_file(ABSPATH . 'admin/settings-tabs/' . $settingsTab['file'])) {
            continue;
        }
        if (!$settingsTabsAuth->hasCapability($settingsTab['capability'])) {
            continue;
        }
        $settingsTabIsActive = $settingsTabKey === $settingsTabsActive;
        ?>
        <li class="nav-item<?= !empty($settingsTab['end']) ? ' ms-auto' : '' ?>">
            <a class="nav-link<?= $settingsTabIsActive ? ' active' : '' ?>" href="<?= htmlspecialchars($settingsTab['url'], ENT_QUOTES, 'UTF-8') ?>"<?= $settingsTabIsActive ? ' aria-current="page"' : '' ?>>
                <?= htmlspecialchars($settingsTabsTranslate($settingsTab['label']), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
