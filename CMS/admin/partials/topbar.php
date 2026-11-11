<?php
declare(strict_types=1);

/**
 * Admin Partial: Topbar (Navigation öffnen < 992px, Brotkrümel, Hilfe, Kontextaktion)
 *
 * Wird von sidebar.php eingebunden und nutzt dessen Kontext
 * ($topbarSiteName, $topbarSectionLabel, $topbarCurrentPageLabel, $activePage).
 *
 * @package CMSv2\Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

$topbarSiteName = trim((string) ($topbarSiteName ?? $siteName ?? (defined('SITE_NAME') ? SITE_NAME : '365CMS')));
$topbarSectionLabel = trim((string) ($topbarSectionLabel ?? 'Dashboard'));
$topbarCurrentPageLabel = trim((string) ($topbarCurrentPageLabel ?? $pageTitle ?? 'Übersicht'));
$topbarBaseUrl = rtrim(defined('SITE_URL') ? (string) SITE_URL : '', '/');

$topbarCtaLabel = 'Neuer Beitrag';
$topbarCtaHref = '/admin/posts?action=edit';
$pagesSlugs = ['pages', 'landing-page', 'hub-sites', 'site-tables', 'table-of-contents'];
$mediaSlugs = ['media', 'media-featured', 'media-check', 'media-categories', 'media-settings'];
$userSlugs = ['users', 'groups', 'roles', 'user-settings'];
if (in_array((string) ($activePage ?? ''), $pagesSlugs, true)) {
    $topbarCtaLabel = 'Neue Seite';
    $topbarCtaHref = '/admin/pages?action=edit';
} elseif (in_array((string) ($activePage ?? ''), $mediaSlugs, true)) {
    $topbarCtaLabel = 'Hochladen';
    $topbarCtaHref = '/admin/media?action=upload';
} elseif (in_array((string) ($activePage ?? ''), $userSlugs, true)) {
    $topbarCtaLabel = 'Neuer Benutzer';
    $topbarCtaHref = '/admin/users?action=edit';
}

// Toolbar „Cache & Wartung“ (nur mit manage_system); der Hinweis auf aktive Wartung gilt für alle Admins.
$topbarCanSystem = \CMS\Auth::instance()->hasCapability(\CMS\Services\AdminToolbarService::CAPABILITY);
$topbarMaintenance = \CMS\Services\AdminToolbarService::maintenanceActive();
$topbarOpcache = $topbarCanSystem && \CMS\Services\AdminToolbarService::opcacheAvailable();
$topbarT = static fn (string $key): string => htmlspecialchars((string) __($key), ENT_QUOTES, 'UTF-8');

$topbarCrumbs = [$topbarSiteName];
if ($topbarSectionLabel !== '' && $topbarSectionLabel !== $topbarCurrentPageLabel) {
    $topbarCrumbs[] = $topbarSectionLabel;
}
?>
<header class="cms-topbar">
    <button type="button" class="cms-topbar__menu" data-cms-drawer-open
            aria-controls="cms-sidebar" aria-expanded="false" aria-label="Navigation öffnen">
        <i class="ti ti-menu-2" aria-hidden="true"></i>
    </button>
    <nav class="cms-topbar__breadcrumb" aria-label="Brotkrümelnavigation">
        <ol>
            <?php foreach ($topbarCrumbs as $topbarCrumbIndex => $topbarCrumb): ?>
                <li class="<?= $topbarCrumbIndex === 0 ? 'cms-topbar__crumb cms-topbar__crumb--site' : 'cms-topbar__crumb' ?>">
                    <?php if ($topbarCrumbIndex === 0): ?><span class="cms-topbar__site-dot" aria-hidden="true"></span><?php endif; ?>
                    <?= htmlspecialchars($topbarCrumb, ENT_QUOTES, 'UTF-8') ?>
                </li>
            <?php endforeach; ?>
            <li class="cms-topbar__crumb cms-topbar__crumb--current" aria-current="page"><?= htmlspecialchars($topbarCurrentPageLabel, ENT_QUOTES, 'UTF-8') ?></li>
        </ol>
    </nav>
    <div class="cms-topbar__actions">
        <?php if ($topbarCanSystem): ?>
            <div class="dropdown cms-topbar__tool" data-cms-toolbar
                 data-label-maintenance-on-title="<?= $topbarT('toolbar.maintenance.confirm_on_title') ?>"
                 data-label-maintenance-on="<?= $topbarT('toolbar.maintenance.confirm_on') ?>"
                 data-label-maintenance-on-button="<?= $topbarT('toolbar.maintenance.confirm_on_button') ?>"
                 data-label-maintenance-off-title="<?= $topbarT('toolbar.maintenance.confirm_off_title') ?>"
                 data-label-maintenance-off="<?= $topbarT('toolbar.maintenance.confirm_off') ?>"
                 data-label-maintenance-off-button="<?= $topbarT('toolbar.maintenance.confirm_off_button') ?>"
                 data-label-maintenance-switch-on="<?= $topbarT('toolbar.maintenance.switch_on') ?>"
                 data-label-maintenance-switch-off="<?= $topbarT('toolbar.maintenance.switch_off') ?>"
                 data-label-busy="<?= $topbarT('toolbar.busy') ?>">
                <button type="button" class="cms-topbar__help" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="true">
                    <i class="ti ti-bolt" aria-hidden="true"></i>
                    <span class="cms-topbar__action-label"><?= $topbarT('toolbar.label') ?></span>
                    <span class="cms-topbar__badge<?= $topbarMaintenance ? '' : ' d-none' ?>" data-cms-maintenance-badge><?= $topbarT('toolbar.maintenance.badge') ?></span>
                </button>
                <div class="dropdown-menu dropdown-menu-end cms-topbar__menu-list">
                    <h6 class="dropdown-header"><?= $topbarT('toolbar.cache.heading') ?></h6>
                    <button type="button" class="dropdown-item" data-cms-cache-clear="all"><i class="ti ti-refresh me-2" aria-hidden="true"></i><?= $topbarT('toolbar.cache.all') ?></button>
                    <button type="button" class="dropdown-item" data-cms-cache-clear="pages"><i class="ti ti-file-text me-2" aria-hidden="true"></i><?= $topbarT('toolbar.cache.pages') ?></button>
                    <button type="button" class="dropdown-item" data-cms-cache-clear="assets"><i class="ti ti-brand-css3 me-2" aria-hidden="true"></i><?= $topbarT('toolbar.cache.assets') ?></button>
                    <?php if ($topbarOpcache): ?>
                        <button type="button" class="dropdown-item" data-cms-cache-clear="opcache"><i class="ti ti-cpu me-2" aria-hidden="true"></i><?= $topbarT('toolbar.cache.opcache') ?></button>
                    <?php endif; ?>
                    <div class="dropdown-divider"></div>
                    <h6 class="dropdown-header"><?= $topbarT('toolbar.maintenance.heading') ?></h6>
                    <button type="button" class="dropdown-item<?= $topbarMaintenance ? ' text-danger' : '' ?>" data-cms-maintenance-toggle data-active="<?= $topbarMaintenance ? '1' : '0' ?>">
                        <i class="ti ti-tool me-2" aria-hidden="true"></i>
                        <span data-cms-maintenance-label><?= $topbarMaintenance ? $topbarT('toolbar.maintenance.switch_off') : $topbarT('toolbar.maintenance.switch_on') ?></span>
                    </button>
                </div>
            </div>
        <?php endif; ?>
        <a class="cms-topbar__help" href="<?= htmlspecialchars($topbarBaseUrl . '/admin/documentation', ENT_QUOTES, 'UTF-8') ?>">
            <i class="ti ti-help-circle" aria-hidden="true"></i>
            <span class="cms-topbar__action-label">Hilfe</span>
        </a>
        <a class="btn btn-primary cms-topbar__cta" href="<?= htmlspecialchars($topbarBaseUrl . $topbarCtaHref, ENT_QUOTES, 'UTF-8') ?>">
            <i class="ti ti-plus" aria-hidden="true"></i>
            <span class="cms-topbar__action-label"><?= htmlspecialchars($topbarCtaLabel, ENT_QUOTES, 'UTF-8') ?></span>
        </a>
    </div>
</header>
<div class="cms-maintenance-banner<?= $topbarMaintenance ? '' : ' d-none' ?>" role="status" data-cms-maintenance-banner>
    <i class="ti ti-alert-triangle" aria-hidden="true"></i>
    <span><?= $topbarT('toolbar.maintenance.banner') ?></span>
    <a href="<?= htmlspecialchars($topbarBaseUrl . '/', ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= $topbarT('toolbar.maintenance.banner_link') ?></a>
</div>
