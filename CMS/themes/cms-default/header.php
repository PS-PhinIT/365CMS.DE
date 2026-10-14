<?php
/**
 * Meridian CMS Default – Header Template
 *
 * @package CMSv2\Themes\CmsDefault
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$logoUrl       = (string) meridian_setting('header', 'logo_url', '');
if ($logoUrl === '' && function_exists('get_option')) {
    // Kein Theme-Logo gesetzt: auf das globale Website-Logo (Einstellungen → Allgemein) zurückfallen.
    try {
        $logoUrl = trim((string) get_option('site_logo', ''));
    } catch (\Throwable) {
        $logoUrl = '';
    }
}
$logoText      = meridian_setting('header', 'logo_text', defined('SITE_NAME') ? SITE_NAME : '365CMS');
$logoType      = meridian_setting('header', 'logo_type', 'text');
$logoTagline   = meridian_setting('header', 'logo_tagline', '');
$logoHeight    = max(20, (int)meridian_setting('header', 'logo_height', 40));
$headerTitle   = meridian_setting('header', 'header_title', '');
$showSearch    = (bool)meridian_setting('header', 'show_search_btn', true);
$showLoginBtn  = (bool)meridian_setting('header', 'show_login_btn', true);
$showRegBtn    = (bool)meridian_setting('header', 'show_register_btn', true);
$headerBarMode = meridian_setting('navigation', 'header_bar_mode', 'categories');
$stickyHeader  = (bool)meridian_setting('layout', 'sticky_header', true);

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$currentLocale = function_exists('meridian_current_request_locale') ? meridian_current_request_locale() : 'de';
$isLoggedIn  = meridian_is_logged_in();
$flashMsg    = meridian_get_flash();
$accountPath = function_exists('meridian_account_path') ? meridian_account_path() : '/member/profile';

// Logout per POST mit eigenem `logout`-CSRF-Token: PublicRouter::handleLogout() meldet
// eingeloggte Benutzer ohne gültiges Token nicht ab, sondern leitet nur auf / um.
// Desktop- und Mobil-Formular teilen sich ein Token pro Seitenaufruf.
$logoutAction = rtrim((string) SITE_URL, '/') . '/logout';
$logoutToken  = '';
if ($isLoggedIn && class_exists('\CMS\Security')) {
    $logoutToken = \CMS\Security::instance()->generateToken('logout');
}

$logoImageUrl = $logoUrl !== '' && function_exists('meridian_normalize_public_media_url')
  ? meridian_normalize_public_media_url($logoUrl, false)
  : $logoUrl;

if ($logoImageUrl === '' && $logoUrl !== '') {
  $logoImageUrl = $logoUrl;
}

$logoDimensionAttributes = $logoUrl !== '' && function_exists('meridian_image_dimension_attributes')
  ? meridian_image_dimension_attributes($logoUrl)
  : '';
$logoLoadingAttributes = function_exists('meridian_image_loading_attributes')
  ? meridian_image_loading_attributes(true)
  : 'loading="eager" decoding="async"';

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <?= function_exists('cms_csp_runtime_tags') ? cms_csp_runtime_tags() : '' ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    // Seitentitel ermitteln
    $siteTitle = defined('SITE_NAME') ? SITE_NAME : '365CMS';
    try {
        if (class_exists('\CMS\ThemeManager')) {
            $tm = \CMS\ThemeManager::instance()->getSiteTitle();
            if (!empty($tm)) { $siteTitle = $tm; }
        }
    } catch (\Throwable $e) {}
    if (function_exists('meridian_document_title')) {
        $siteTitle = meridian_document_title($siteTitle, [
            'title' => $title ?? null,
            'category' => $category ?? null,
            'tag' => $tag ?? null,
            'author' => $author ?? null,
            'currentPage' => $currentPage ?? null,
            'error_code' => $error_code ?? null,
        ]);
    }
    ?>
    <title><?php echo htmlspecialchars(\CMS\Hooks::applyFilters('page_title', $siteTitle), ENT_QUOTES, 'UTF-8'); ?></title>
    <?php
    if (class_exists('\CMS\Hooks')) {
        \CMS\Hooks::doAction('head');
    }
    ?>
</head>
<body class="meridian-theme">

  <a class="skip-link" href="#main-content">Zum Inhalt springen</a>
  <?php if (class_exists('\CMS\Hooks')) { \CMS\Hooks::doAction('body_start'); } ?>

<?php if ($flashMsg): ?>
<div class="alert alert-<?php echo htmlspecialchars($flashMsg['type'] ?? 'info'); ?>" role="<?php echo in_array(($flashMsg['type'] ?? ''), ['error', 'danger'], true) ? 'alert' : 'status'; ?>" style="border-radius:0;margin:0;">
    <?php echo htmlspecialchars($flashMsg['message'] ?? ''); ?>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════
     HEADER
════════════════════════════════════════ -->
<header class="site-header<?php echo !$stickyHeader ? ' site-header--static' : ''; ?>">
  <div class="header-inner">

    <!-- Logo + optionaler Titel daneben -->
    <div class="site-logo-group">
      <a href="<?php echo SITE_URL; ?>/" class="site-logo" aria-label="<?php echo htmlspecialchars($logoText); ?> – Startseite">
        <?php if ($logoType === 'image' && $logoImageUrl): ?>
            <img src="<?php echo htmlspecialchars($logoImageUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($logoText); ?>" <?php echo $logoDimensionAttributes; ?> <?php echo $logoLoadingAttributes; ?> style="max-height:<?php echo $logoHeight; ?>px;display:block;width:auto;">
        <?php else: ?>
            <span class="logo-word"><?php echo htmlspecialchars($logoText); ?></span>
            <span class="logo-dot"></span>
            <?php if ($logoTagline): ?>
            <span class="logo-tagline"><?php echo htmlspecialchars($logoTagline); ?></span>
            <?php endif; ?>
        <?php endif; ?>
      </a>
      <?php if ($headerTitle): ?>
      <span class="header-site-title"><?php echo htmlspecialchars($headerTitle); ?></span>
      <?php endif; ?>
    </div>

    <!-- Primary Nav -->
    <nav class="primary-nav" aria-label="Hauptnavigation">
        <?php
        $navItems = [
            ['label' => 'Startseite', 'href' => SITE_URL . '/'],
            ['label' => 'Blog',        'href' => SITE_URL . '/blog'],
        ];
        $mobileNavItems = [];
        if (class_exists('\CMS\ThemeManager')) {
            $primaryMenu = \CMS\ThemeManager::instance()->getMenu('primary');
            if (!empty($primaryMenu)) {
                $navItems = $primaryMenu;
            }

          $mobileMenu = \CMS\ThemeManager::instance()->getMenu('mobile');
          if (!empty($mobileMenu)) {
            $mobileNavItems = $mobileMenu;
          }
        }

        if (empty($mobileNavItems)) {
          $mobileNavItems = $navItems;
        }

        foreach ($navItems as $item):
            $href    = is_array($item) ? ($item['href'] ?? $item['url'] ?? '#') : '#';
            $label   = is_array($item) ? ($item['label'] ?? $item['title'] ?? '') : $item;
            $isActive = rtrim($currentPath, '/') === rtrim(parse_url($href, PHP_URL_PATH) ?? '/', '/');
            $children = is_array($item) ? ($item['children'] ?? []) : [];

            if ($children):
        ?>
            <div class="nav-group">
                <a href="<?php echo htmlspecialchars($href); ?>">
                    <?php echo htmlspecialchars($label); ?>
                    <svg viewBox="0 0 10 10" aria-hidden="true" focusable="false"><polyline points="2,3 5,7 8,3"/></svg>
                </a>
                <div class="nav-dropdown">
                    <?php foreach ($children as $child):
                        $childHref  = is_array($child) ? ($child['href'] ?? $child['url'] ?? '#') : '#';
                        $childLabel = is_array($child) ? ($child['label'] ?? $child['title'] ?? '') : $child;
                    ?>
                    <a href="<?php echo htmlspecialchars($childHref); ?>"><?php echo htmlspecialchars($childLabel); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <a href="<?php echo htmlspecialchars($href); ?>" class="<?php echo $isActive ? 'active' : ''; ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>>
                <?php echo htmlspecialchars($label); ?>
            </a>
        <?php endif; endforeach; ?>

        <div class="nav-spacer"></div>
    </nav>

    <!-- Header Actions -->
    <div class="header-actions">
      <?php if ($showSearch): ?>
      <button type="button" class="btn-icon" id="searchToggle" aria-label="Suche öffnen" aria-expanded="false" aria-controls="headerSearch">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </button>
      <?php endif; ?>

      <?php if ($isLoggedIn): ?>
          <a href="<?php echo htmlspecialchars(rtrim((string) SITE_URL, '/') . $accountPath, ENT_QUOTES, 'UTF-8'); ?>" class="btn-ghost">Mein Bereich</a>
          <form method="POST" action="<?php echo htmlspecialchars($logoutAction, ENT_QUOTES, 'UTF-8'); ?>" class="logout-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($logoutToken, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="btn-ghost btn-ghost--button">Logout</button>
          </form>
      <?php else: ?>
          <?php if ($showLoginBtn): ?>
          <a href="<?php echo htmlspecialchars(meridian_auth_url('login'), ENT_QUOTES, 'UTF-8'); ?>" class="btn-ghost">Anmelden</a>
          <?php endif; ?>
          <?php if ($showRegBtn): ?>
          <a href="<?php echo htmlspecialchars(meridian_auth_url('register'), ENT_QUOTES, 'UTF-8'); ?>" class="btn-solid">Registrieren</a>
          <?php endif; ?>
      <?php endif; ?>

      <!-- Hamburger (nur mobil sichtbar via CSS) -->
      <button type="button" class="nav-toggle" id="navToggle" aria-label="Menü öffnen" aria-expanded="false" aria-controls="mobileNavPanel">
        <span class="nav-toggle-bar"></span>
        <span class="nav-toggle-bar"></span>
        <span class="nav-toggle-bar"></span>
      </button>
    </div>
  </div>
  <?php if ($showSearch): ?>
  <div class="header-search" id="headerSearch" aria-hidden="true">
    <form class="header-search-form" action="<?php echo htmlspecialchars(rtrim((string) SITE_URL, '/') . '/search', ENT_QUOTES, 'UTF-8'); ?>" method="GET" role="search">
      <label for="headerSearchInput" class="sr-only">Website durchsuchen</label>
      <input type="search" id="headerSearchInput" name="q" class="form-control header-search-input" placeholder="Suchen…" maxlength="200" autocomplete="off">
      <button type="submit" class="btn-submit">Suchen</button>
      <button type="button" class="btn-icon" id="searchClose" aria-label="Suche schließen">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" aria-hidden="true" focusable="false"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </form>
  </div>
  <?php endif; ?>
</header>

<!-- Kategorie/Menü-Leiste -->
<?php if ($headerBarMode !== 'none'): ?>
<nav class="category-bar" aria-label="<?php echo $headerBarMode === 'categories' ? 'Kategorien' : 'Sekundärnavigation'; ?>">
  <div class="category-bar-inner">
    
    <?php if ($headerBarMode === 'categories'): ?>
        <span class="cat-label" aria-hidden="true">Kategorien</span>
        <a href="<?php echo SITE_URL; ?>/blog" class="<?php echo $currentPath === '/blog' ? 'active' : ''; ?>"<?php echo $currentPath === '/blog' ? ' aria-current="page"' : ''; ?>>Alle</a>
        <?php
        $cats = function_exists('meridian_get_categories') ? meridian_get_categories(8) : [];
        if (!empty($cats)):
            foreach ($cats as $cat):
                $catSlug = $cat['slug'] ?? '';
                $catName = $cat['name'] ?? '';
                $catHref = meridian_archive_url('category', (string) $catSlug);
                $isActive = rtrim($currentPath, '/') === rtrim((string) (parse_url($catHref, PHP_URL_PATH) ?: ''), '/');
        ?>
        <a href="<?php echo htmlspecialchars($catHref, ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $isActive ? 'active' : ''; ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>><?php echo htmlspecialchars($catName); ?></a>
        <?php endforeach; endif; ?>

    <?php elseif ($headerBarMode === 'menu'): ?>
        <?php
        $secMenu = [];
        try {
            $secMenu = \CMS\ThemeManager::instance()->getMenu('secondary') ?? [];
        } catch (\Throwable $e) {}
        if (!empty($secMenu)):
            foreach ($secMenu as $item):
                $mUrl   = $item['url'] ?? '#';
                $mLabel = $item['label'] ?? '';
        ?>
            <a href="<?php echo htmlspecialchars($mUrl); ?>"><?php echo htmlspecialchars($mLabel); ?></a>
        <?php endforeach; else: ?>
            <span style="font-size:0.8rem;color:var(--ink-muted);">Kein Menü für Position "Sekundär" zugewiesen.</span>
        <?php endif; ?>
    <?php endif; ?>

  </div>
</nav>
<?php endif; ?>

<!-- Mobile Nav Overlay -->
<div id="mobileNavOverlay" class="mobile-nav-overlay" aria-hidden="true"></div>

<!-- Mobile Nav Panel (Slide-in von rechts) -->
<nav id="mobileNavPanel" class="mobile-nav-panel" aria-hidden="true" inert aria-label="Mobile Navigation">
  <div class="mobile-nav-header">
    <a href="<?php echo SITE_URL; ?>/" class="site-logo" aria-label="<?php echo htmlspecialchars($logoText); ?> – Startseite" style="text-decoration:none;">
      <?php if ($logoType === 'image' && $logoImageUrl): ?>
        <img src="<?php echo htmlspecialchars($logoImageUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($logoText); ?>" <?php echo $logoDimensionAttributes; ?> <?php echo $logoLoadingAttributes; ?> style="max-height:32px;width:auto;">
      <?php else: ?>
        <span class="logo-word" style="font-size:1rem;"><?php echo htmlspecialchars($logoText); ?></span>
        <span class="logo-dot"></span>
      <?php endif; ?>
    </a>
    <button type="button" id="mobileNavClose" class="btn-icon" aria-label="Menü schließen" style="margin-left:auto;">
      <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>

  <div class="mobile-nav-body">
    <?php foreach ($mobileNavItems as $item):
        $mHref    = is_array($item) ? ($item['href'] ?? $item['url'] ?? '#') : '#';
        $mLabel   = is_array($item) ? ($item['label'] ?? $item['title'] ?? '') : $item;
        $mActive  = rtrim($currentPath, '/') === rtrim(parse_url($mHref, PHP_URL_PATH) ?? '/', '/');
        $mChildren = is_array($item) ? ($item['children'] ?? []) : [];
    ?>
    <a href="<?php echo htmlspecialchars($mHref); ?>"
       class="mobile-nav-link<?php echo $mActive ? ' active' : ''; ?>"<?php echo $mActive ? ' aria-current="page"' : ''; ?>>
      <?php echo htmlspecialchars($mLabel); ?>
    </a>
    <?php foreach ($mChildren as $child):
        $cHref  = is_array($child) ? ($child['href'] ?? $child['url'] ?? '#') : '#';
        $cLabel = is_array($child) ? ($child['label'] ?? $child['title'] ?? '') : $child;
    ?>
    <a href="<?php echo htmlspecialchars($cHref); ?>"
       class="mobile-nav-link"
       style="padding-left:2.5rem;font-size:.83rem;opacity:.8;">
      <?php echo htmlspecialchars($cLabel); ?>
    </a>
    <?php endforeach; ?>
    <?php endforeach; ?>

    <div style="height:1px;background:var(--rule);margin:.5rem 1.25rem;"></div>

    <?php if ($isLoggedIn): ?>
      <a href="<?php echo htmlspecialchars(rtrim((string) SITE_URL, '/') . $accountPath, ENT_QUOTES, 'UTF-8'); ?>" class="mobile-nav-link"><span aria-hidden="true">👤</span> Mein Bereich</a>
      <form method="POST" action="<?php echo htmlspecialchars($logoutAction, ENT_QUOTES, 'UTF-8'); ?>" class="logout-form">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($logoutToken, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="mobile-nav-link mobile-nav-link--button"><span aria-hidden="true">⬡</span> Abmelden</button>
      </form>
    <?php else: ?>
      <?php if ($showLoginBtn): ?>
      <a href="<?php echo htmlspecialchars(meridian_auth_url('login'), ENT_QUOTES, 'UTF-8'); ?>" class="mobile-nav-link"><span aria-hidden="true">🔑</span> Anmelden</a>
      <?php endif; ?>
      <?php if ($showRegBtn): ?>
      <a href="<?php echo htmlspecialchars(meridian_auth_url('register'), ENT_QUOTES, 'UTF-8'); ?>" class="mobile-nav-link" style="color:var(--accent);font-weight:600;"><span aria-hidden="true">✨</span> Registrieren</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($showSearch): ?>
  <div class="mobile-nav-search">
    <form action="<?php echo SITE_URL; ?>/search" method="GET" role="search" style="display:flex;gap:.5rem;">
      <input type="search" name="q" placeholder="Suchen…" class="form-control form-control--sm" aria-label="Suche" style="flex:1;">
      <button type="submit" class="btn-submit" aria-label="Suche absenden" style="padding:.35rem .85rem;font-size:.82rem;"><span aria-hidden="true">→</span></button>
    </form>
  </div>
  <?php endif; ?>
</nav>

<!-- Main Content Wrapper startet hier -->
<main class="site-main" id="main-content" tabindex="-1">