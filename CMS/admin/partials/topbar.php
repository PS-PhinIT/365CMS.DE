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
