<?php
declare(strict_types=1);

/**
 * Admin Partial: Seitenleiste (Site-Umschalter, Navigation, Benutzerbereich)
 *
 * Erwartet vor dem include:
 *   $activePage – string  Aktuelle Seite (z. B. 'dashboard', 'pages', 'media')
 *
 * Reine Darstellungsschicht: Plugin-Menüs werden wie bisher über den Hook
 * `cms_admin_menu` registriert und per get_registered_admin_menus() gelesen;
 * AdminNavigationService gruppiert, filtert (Plugin-Zuweisung je Site) und markiert sie nur.
 * Öffnet .page-wrapper, Topbar und <main id="cms-main"> (geschlossen in footer.php).
 *
 * @package CMSv2\Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

$activePage = \CMS\Services\AdminNavigationService::normalizeActivePage((string) ($activePage ?? ''));
$siteUrl    = defined('SITE_URL') ? SITE_URL : '';
$logoutUrl  = rtrim((string) $siteUrl, '/') . '/logout?csrf_token=' . rawurlencode(\CMS\Security::instance()->generateToken('logout'));
$siteName   = function_exists('cms_get_site_name') ? cms_get_site_name() : (defined('SITE_NAME') ? SITE_NAME : '365CMS');

// Marketplace ein-/ausblenden (DB-Setting, Default: aktiviert)
$_marketplaceEnabled = true;
try {
    $_mpRow = \CMS\Database::instance()->get_row(
        "SELECT option_value FROM " . \CMS\Database::instance()->getPrefix() . "settings WHERE option_name = 'marketplace_enabled'"
    );
    if ($_mpRow !== null) {
        $_marketplaceEnabled = $_mpRow->option_value !== '0';
    }
} catch (\Throwable) {}

// Plugin-Menüs laden und für die Sidebar verfügbar machen
$registeredPluginMenus = [];
try {
    \CMS\Hooks::doAction('cms_admin_menu');
    if (function_exists('get_registered_admin_menus')) {
        $registeredPluginMenus = get_registered_admin_menus();
    }
} catch (\Throwable) {
    $registeredPluginMenus = [];
}

$currentAdminUser = null;
try {
    $currentAdminUser = \CMS\Auth::instance()->currentUser();
} catch (\Throwable) {
    $currentAdminUser = null;
}
$adminShellUserId = (int) ($currentAdminUser->id ?? 0);

$adminShellSites = [];
$adminShellSite = ['key' => '', 'label' => (string) $siteName, 'url' => (string) $siteUrl];
$adminShellGroupStates = [];
$adminShellCollapsed = false;
try {
    $adminShellSiteContext = \CMS\Services\AdminSiteContextService::getInstance();
    $adminShellSites = $adminShellSiteContext->getSites();
    $adminShellSite = $adminShellSiteContext->getCurrentSite();
    if ($adminShellUserId > 0) {
        $adminShellPrefs = \CMS\Services\AdminUiPreferenceService::getInstance()->get($adminShellUserId);
        $adminShellGroupStates = $adminShellPrefs['groups'];
        $adminShellCollapsed = $adminShellPrefs['sidebar_collapsed'];
    }
} catch (\Throwable) {
    $adminShellSites = [];
}

$adminShellNav = \CMS\Services\AdminNavigationService::getInstance()->build($activePage, is_array($registeredPluginMenus) ? $registeredPluginMenus : [], [
    'site_url' => (string) $siteUrl,
    'request_path' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
    'site_key' => (string) ($adminShellSite['key'] ?? ''),
    'site_label' => (string) ($adminShellSite['label'] ?? ''),
    'group_states' => $adminShellGroupStates,
    'marketplace_enabled' => $_marketplaceEnabled,
    'logs_badge' => max(0, (int) ($_SESSION['admin_unread_notifications'] ?? 0)),
    'page_title' => (string) ($pageTitle ?? ''),
]);

// Kontext für topbar.php
$topbarSiteName = (string) ($adminShellSite['label'] ?? $siteName);
$topbarSectionLabel = $adminShellNav['breadcrumb']['section'];
$topbarCurrentPageLabel = $adminShellNav['breadcrumb']['page'];

$currentAdminFirstName = trim((string) ($currentAdminUser->meta['first_name'] ?? ''));
$currentAdminLastName = trim((string) ($currentAdminUser->meta['last_name'] ?? ''));
$adminShellDisplayName = trim($currentAdminFirstName . ' ' . $currentAdminLastName);
if ($adminShellDisplayName === '') {
    $adminShellDisplayName = trim((string) ($currentAdminUser->display_name ?? ($_SESSION['user_display_name'] ?? '')));
}
if ($adminShellDisplayName === '') {
    $adminShellDisplayName = trim((string) ($currentAdminUser->username ?? 'Admin'));
}
$adminShellInitials = '';
foreach (preg_split('/\s+/u', $adminShellDisplayName) ?: [] as $adminShellNamePart) {
    if ($adminShellNamePart !== '') {
        $adminShellInitials .= mb_strtoupper(mb_substr($adminShellNamePart, 0, 1));
    }
    if (mb_strlen($adminShellInitials) >= 2) {
        break;
    }
}
$adminShellInitials = $adminShellInitials !== '' ? $adminShellInitials : 'AD';
$adminShellRole = (string) ($currentAdminUser->role ?? '');
$adminShellRoleLabel = match ($adminShellRole) {
    'admin', 'administrator' => 'Administrator',
    'editor' => 'Redaktion',
    'author' => 'Autor',
    'member' => 'Mitglied',
    '' => 'Administration',
    default => ucfirst($adminShellRole),
};

$adminShellCanSeeNotices = false;
try {
    $adminShellCanSeeNotices = \CMS\Auth::instance()->hasCapability('manage_system');
} catch (\Throwable) {
    $adminShellCanSeeNotices = false;
}

$adminShellUiToken = \CMS\Security::instance()->generateToken('admin_ui');

// Plugin-Seite für die aktuelle Site nicht zugewiesen → Hinweis (blockiert nicht).
$adminShellPluginBanner = null;
try {
    $adminShellAssignments = \CMS\Services\PluginSiteAssignmentService::getInstance();
    $adminShellRequestPlugin = $adminShellAssignments->resolveRequestPluginSlug();
    $adminShellSiteKey = (string) ($adminShellSite['key'] ?? '');
    if ($adminShellRequestPlugin !== null && $adminShellSiteKey !== '' && !$adminShellAssignments->isAssigned($adminShellRequestPlugin, $adminShellSiteKey)) {
        $adminShellPluginBanner = [
            'plugin' => $adminShellAssignments->getPluginName($adminShellRequestPlugin),
            'site' => (string) ($adminShellSite['label'] ?? $adminShellSiteKey),
            'url' => rtrim((string) $siteUrl, '/') . \CMS\Services\PluginSiteAssignmentService::ADMIN_PATH,
        ];
    }
} catch (\Throwable) {
    $adminShellPluginBanner = null;
}

$shellEsc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$renderNavIcon = static function (array $icon) use ($shellEsc): string {
    return match ($icon['type'] ?? '') {
        'image' => '<img class="cms-nav__icon-img" src="' . $shellEsc($icon['src'] ?? '') . '" alt="" width="20" height="20" loading="lazy" decoding="async">',
        'text' => '<span class="cms-nav__icon-text">' . $shellEsc($icon['text'] ?? '') . '</span>',
        default => '<i class="ti ti-' . $shellEsc($icon['name'] ?? \CMS\Services\AdminNavigationService::DEFAULT_PLUGIN_ICON) . '"></i>',
    };
};

$renderNavLabel = static function (array $entry) use ($shellEsc): string {
    if (($entry['prefix'] ?? '') !== '') {
        return '<span class="cms-nav__prefix">' . $shellEsc($entry['prefix']) . ' |</span> ' . $shellEsc($entry['name']);
    }

    return $shellEsc($entry['label']);
};

$renderNavBadge = static function (?array $badge) use ($shellEsc): string {
    if ($badge === null || trim((string) ($badge['label'] ?? '')) === '') {
        return '';
    }

    return '<span class="cms-nav__badge"><span aria-hidden="true">' . $shellEsc($badge['label']) . '</span>'
        . '<span class="visually-hidden">' . $shellEsc($badge['sr'] ?? $badge['label']) . '</span></span>';
};

$adminShellSiteLabel = (string) ($adminShellSite['label'] ?? $siteName);
$adminShellSiteMark = mb_strtoupper(mb_substr((string) preg_replace('/^(?:www\.)?[^a-z0-9]*/i', '', $adminShellSiteLabel), 0, 1)) ?: 'S';
$adminShellMultiSite = count($adminShellSites) > 1;
?>
<div class="cms-drawer-backdrop" data-cms-drawer-backdrop hidden></div>
<aside class="cms-sidebar" id="cms-sidebar" aria-label="Admin-Seitenleiste"
       data-cms-sidebar
       data-cms-ui-endpoint="<?= $shellEsc(rtrim((string) $siteUrl, '/') . '/admin/admin-ui') ?>"
       data-cms-ui-token="<?= $shellEsc($adminShellUiToken) ?>">
    <div class="cms-sidebar__header">
        <div class="cms-site-switcher" data-cms-site-switcher>
            <?php if ($adminShellMultiSite): ?>
                <button type="button" class="cms-site-switcher__toggle" id="cms-site-switcher-toggle"
                        aria-expanded="false" aria-controls="cms-site-switcher-menu"
                        data-cms-tip="<?= $shellEsc('Site: ' . $adminShellSiteLabel) ?>">
            <?php else: ?>
                <div class="cms-site-switcher__toggle cms-site-switcher__toggle--static" data-cms-tip="<?= $shellEsc('Site: ' . $adminShellSiteLabel) ?>">
            <?php endif; ?>
                    <span class="cms-site-switcher__mark" aria-hidden="true"><?= $shellEsc($adminShellSiteMark) ?></span>
                    <span class="cms-site-switcher__text">
                        <span class="cms-site-switcher__name"><?= $shellEsc($adminShellSiteLabel) ?></span>
                        <span class="cms-site-switcher__meta"><?= $adminShellMultiSite ? 'Site wechseln' : '365CMS Admin' ?></span>
                    </span>
            <?php if ($adminShellMultiSite): ?>
                    <i class="ti ti-selector cms-site-switcher__chevron" aria-hidden="true"></i>
                </button>
                <div class="cms-site-switcher__menu" id="cms-site-switcher-menu" hidden>
                    <p class="cms-site-switcher__title" id="cms-site-switcher-title">Site wechseln</p>
                    <ul class="cms-site-switcher__list" aria-labelledby="cms-site-switcher-title">
                        <?php foreach ($adminShellSites as $adminShellSiteOption): ?>
                            <?php $adminShellIsCurrentSite = $adminShellSiteOption['key'] === ($adminShellSite['key'] ?? ''); ?>
                            <li>
                                <button type="button" class="cms-site-switcher__option"
                                        data-cms-site-option="<?= $shellEsc($adminShellSiteOption['key']) ?>"
                                        <?= $adminShellIsCurrentSite ? 'aria-current="true"' : '' ?>>
                                    <span class="cms-site-swatch" data-site-swatch="<?= $shellEsc($adminShellSiteOption['key']) ?>" aria-hidden="true"></span>
                                    <span class="cms-site-switcher__option-label"><?= $shellEsc($adminShellSiteOption['label']) ?></span>
                                    <?php if ($adminShellIsCurrentSite): ?>
                                        <i class="ti ti-check" aria-hidden="true"></i>
                                        <span class="visually-hidden">(aktuelle Site)</span>
                                    <?php endif; ?>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="cms-site-switcher__status" data-cms-site-status role="status" aria-live="polite"></p>
                </div>
            <?php else: ?>
                </div>
            <?php endif; ?>
        </div>
        <button type="button" class="cms-sidebar__collapse" data-cms-sidebar-toggle
                aria-controls="cms-sidebar" aria-expanded="<?= $adminShellCollapsed ? 'false' : 'true' ?>"
                aria-label="<?= $adminShellCollapsed ? 'Seitenleiste ausklappen' : 'Seitenleiste einklappen' ?>">
            <i class="ti ti-layout-sidebar-left-collapse" aria-hidden="true"></i>
        </button>
        <button type="button" class="cms-sidebar__close" data-cms-drawer-close aria-label="Navigation schließen">
            <i class="ti ti-x" aria-hidden="true"></i>
        </button>
    </div>

    <nav class="cms-sidebar__nav" aria-label="Hauptnavigation" data-cms-nav data-recent-label="<?= $shellEsc($adminShellNav['recent_label']) ?>">
        <ul class="cms-nav">
            <?php foreach ($adminShellNav['groups'] as $navGroup): ?>
                <li class="cms-nav__group<?= $navGroup['active'] ? ' is-active' : '' ?>" data-cms-group="<?= $shellEsc($navGroup['id']) ?>">
                    <button type="button" class="cms-nav__group-toggle" id="<?= $shellEsc($navGroup['dom_id']) ?>-toggle"
                            aria-expanded="<?= $navGroup['expanded'] ? 'true' : 'false' ?>"
                            aria-controls="<?= $shellEsc($navGroup['dom_id']) ?>"
                            data-cms-toggle="<?= $shellEsc($navGroup['id']) ?>">
                        <span class="cms-nav__group-label"><?= $shellEsc($navGroup['label']) ?></span>
                        <i class="ti ti-chevron-down cms-nav__chevron" aria-hidden="true"></i>
                    </button>
                    <div class="cms-collapse<?= $navGroup['expanded'] ? '' : ' is-collapsed' ?>" id="<?= $shellEsc($navGroup['dom_id']) ?>" data-cms-collapse="group">
                        <ul class="cms-nav__list cms-collapse__inner" aria-labelledby="<?= $shellEsc($navGroup['dom_id']) ?>-toggle">
                            <?php foreach ($navGroup['entries'] as $navEntry): ?>
                                <?php if ($navEntry['children'] === []): ?>
                                    <li class="cms-nav__item<?= $navEntry['plugin'] ? ' cms-nav__item--plugin' : '' ?>">
                                        <a class="cms-nav__link<?= $navEntry['current'] ? ' is-current' : '' ?>" href="<?= $shellEsc($navEntry['url']) ?>"
                                           data-cms-tip="<?= $shellEsc($navEntry['label']) ?>"<?= $navEntry['current'] ? ' aria-current="page"' : '' ?>>
                                            <span class="cms-nav__icon" aria-hidden="true"><?= $renderNavIcon($navEntry['icon']) ?></span>
                                            <span class="cms-nav__label"><?= $renderNavLabel($navEntry) ?></span>
                                            <?= $renderNavBadge($navEntry['badge']) ?>
                                        </a>
                                    </li>
                                <?php else: ?>
                                    <li class="cms-nav__item cms-nav__item--parent<?= $navEntry['plugin'] ? ' cms-nav__item--plugin' : '' ?><?= $navEntry['active'] ? ' is-active' : '' ?>" data-cms-entry="<?= $shellEsc($navEntry['id']) ?>">
                                        <button type="button" class="cms-nav__link cms-nav__toggle" id="<?= $shellEsc($navEntry['dom_id']) ?>-toggle"
                                                aria-expanded="<?= $navEntry['expanded'] ? 'true' : 'false' ?>"
                                                aria-controls="<?= $shellEsc($navEntry['dom_id']) ?>"
                                                data-cms-toggle="<?= $shellEsc($navEntry['id']) ?>"
                                                data-cms-tip="<?= $shellEsc($navEntry['label']) ?>">
                                            <span class="cms-nav__icon" aria-hidden="true"><?= $renderNavIcon($navEntry['icon']) ?></span>
                                            <span class="cms-nav__label"><?= $renderNavLabel($navEntry) ?></span>
                                            <?= $renderNavBadge($navEntry['badge']) ?>
                                            <i class="ti ti-chevron-down cms-nav__chevron" aria-hidden="true"></i>
                                        </button>
                                        <div class="cms-collapse cms-nav__sub<?= $navEntry['expanded'] ? '' : ' is-collapsed' ?>" id="<?= $shellEsc($navEntry['dom_id']) ?>" data-cms-collapse="entry">
                                            <div class="cms-collapse__inner">
                                                <p class="cms-nav__flyout-title" aria-hidden="true"><?= $shellEsc($navEntry['label']) ?></p>
                                                <ul class="cms-nav__sublist" aria-labelledby="<?= $shellEsc($navEntry['dom_id']) ?>-toggle">
                                                    <?php foreach ($navEntry['children'] as $navChild): ?>
                                                        <li>
                                                            <a class="cms-nav__sublink<?= $navChild['current'] ? ' is-current' : '' ?>" href="<?= $shellEsc($navChild['url']) ?>"<?= $navChild['current'] ? ' aria-current="page"' : '' ?>>
                                                                <?= $shellEsc($navChild['label']) ?>
                                                            </a>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (is_array($navGroup['hint'] ?? null)): ?>
                                <li class="cms-nav__hint">
                                    <p><?= $shellEsc($navGroup['hint']['text']) ?></p>
                                    <a href="<?= $shellEsc($navGroup['hint']['url']) ?>"><?= $shellEsc($navGroup['hint']['link']) ?></a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="cms-sidebar__footer" data-cms-user>
        <button type="button" class="cms-user__toggle" id="cms-user-toggle"
                aria-expanded="false" aria-controls="cms-user-popover"
                data-cms-tip="<?= $shellEsc($adminShellDisplayName) ?>">
            <span class="cms-user__avatar" aria-hidden="true">
                <?= $shellEsc($adminShellInitials) ?>
                <span class="cms-user__dot" data-cms-notice-dot hidden></span>
            </span>
            <span class="cms-user__text">
                <span class="cms-user__name"><?= $shellEsc($adminShellDisplayName) ?></span>
                <span class="cms-user__role"><?= $shellEsc($adminShellRoleLabel) ?></span>
            </span>
            <span class="visually-hidden" data-cms-notice-sr></span>
            <i class="ti ti-dots-vertical cms-user__more" aria-hidden="true"></i>
        </button>
        <div class="cms-popover cms-user__popover" id="cms-user-popover" hidden
             <?= $adminShellCanSeeNotices ? 'data-cms-notices' : '' ?>>
            <?php if ($adminShellCanSeeNotices): ?>
                <section class="cms-popover__section" aria-labelledby="cms-notices-title">
                    <h2 class="cms-popover__title" id="cms-notices-title">Systemhinweise</h2>
                    <p class="cms-popover__status" data-cms-notices-status>Hinweise werden geladen …</p>
                    <ul class="cms-notices" data-cms-notices-list hidden></ul>
                </section>
            <?php endif; ?>
            <ul class="cms-popover__menu">
                <li>
                    <a class="cms-popover__link" href="<?= $shellEsc(rtrim((string) $siteUrl, '/') . '/member/profile') ?>">
                        <i class="ti ti-user" aria-hidden="true"></i><span>Mein Profil</span>
                    </a>
                </li>
                <li>
                    <a class="cms-popover__link" href="<?= $shellEsc(rtrim((string) $siteUrl, '/') . '/') ?>" target="_blank" rel="noopener">
                        <i class="ti ti-external-link" aria-hidden="true"></i><span>Website ansehen</span>
                        <span class="visually-hidden">(öffnet in neuem Tab)</span>
                    </a>
                </li>
                <li>
                    <a class="cms-popover__link cms-popover__link--danger" href="<?= $shellEsc($logoutUrl) ?>">
                        <i class="ti ti-logout" aria-hidden="true"></i><span>Abmelden</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
    <div class="cms-tooltip" data-cms-tooltip aria-hidden="true" hidden></div>
</aside>

<div class="page-wrapper">
    <?php require __DIR__ . '/topbar.php'; ?>
    <main id="cms-main" class="cms-main" tabindex="-1">
        <?php if ($adminShellPluginBanner !== null): ?>
            <div class="container-xl">
                <div class="cms-site-banner" role="status">
                    <i class="ti ti-info-circle cms-site-banner__icon" aria-hidden="true"></i>
                    <p class="cms-site-banner__text">
                        Dieses Plugin ist für <strong><?= $shellEsc($adminShellPluginBanner['site']) ?></strong> nicht zugewiesen.
                        <span class="cms-site-banner__detail">„<?= $shellEsc($adminShellPluginBanner['plugin']) ?>“ bleibt erreichbar, erscheint in dieser Site aber nicht in der Seitenleiste.</span>
                    </p>
                    <a class="cms-site-banner__link" href="<?= $shellEsc($adminShellPluginBanner['url']) ?>">Plugin-Zuweisung öffnen</a>
                </div>
            </div>
        <?php endif; ?>
