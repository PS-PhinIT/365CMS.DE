<?php
/**
 * Meridian CMS Default – Footer Template
 *
 * @package CMSv2\Themes\CmsDefault
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$logoText       = meridian_setting('header', 'logo_text', defined('SITE_NAME') ? SITE_NAME : '365CMS');
$footerDesc     = meridian_setting('footer', 'footer_description', 'Aktuelle Themen, fundierte Analysen und persönliche Geschichten – täglich neu.');
$showFooterMenuArea = (bool) meridian_setting('footer', 'show_footer_menu_area', true);
$showSocial     = (bool) meridian_setting('footer', 'show_social_icons', true);
$col1Title      = meridian_setting('footer', 'col1_title', 'Rubriken');
$col2Title      = meridian_setting('footer', 'col2_title', 'Ressourcen');
$col3Title      = meridian_setting('footer', 'col3_title', 'Über');
$copyrightText  = meridian_copyright(meridian_setting('footer', 'copyright_text', ''));
$showBackToTop  = (bool) meridian_setting('layout', 'show_back_to_top', true);

$socialLinks = [
    'twitter'   => meridian_setting('footer', 'social_twitter', ''),
    'instagram' => meridian_setting('footer', 'social_instagram', ''),
    'linkedin'  => meridian_setting('footer', 'social_linkedin', ''),
    'rss'       => SITE_URL . '/feed',
];

$footerTopicsMenu = function_exists('theme_get_menu') ? theme_get_menu('footer_topics') : [];
$footerResourcesMenu = function_exists('theme_get_menu') ? theme_get_menu('footer_resources') : [];
$footerAboutMenu = function_exists('theme_get_menu') ? theme_get_menu('footer_about') : [];
$footerLegalMenu = function_exists('theme_get_menu') ? theme_get_menu('footer_legal') : [];
$footerGenericMenu = function_exists('theme_get_menu') ? theme_get_menu('footer') : [];

if (empty($footerTopicsMenu) && !empty($footerGenericMenu)) {
  $footerTopicsMenu = $footerGenericMenu;
}

$renderFooterMenuLinks = static function (array $items): void {
  foreach ($items as $item) {
    if (!is_array($item)) {
      continue;
    }

    $href = (string) ($item['href'] ?? $item['url'] ?? '#');
    $label = trim((string) ($item['label'] ?? $item['title'] ?? ''));
    if ($label === '') {
      continue;
    }

    $target = ((string) ($item['target'] ?? '_self')) === '_blank'
      ? ' target="_blank" rel="noopener noreferrer"'
      : '';

    echo '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $target . '>'
      . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
  }
};
?>

</main><!-- Main Content Wrapper endet hier -->

<footer>
  <?php if ($showFooterMenuArea): ?>
  <div class="footer-top-rule"></div>
  <div class="footer-main">

    <!-- Brand Column -->
    <div class="ft-brand">
      <a href="<?php echo SITE_URL; ?>/" class="site-logo-ft" aria-label="<?php echo htmlspecialchars($logoText); ?> – Startseite">
        <span class="lw"><?php echo htmlspecialchars($logoText); ?></span>
        <span class="ld"></span>
      </a>
      <?php if ($footerDesc): ?>
      <p><?php echo htmlspecialchars($footerDesc); ?></p>
      <?php endif; ?>

      <?php if ($showSocial): ?>
      <div class="ft-socials">
        <?php if ($socialLinks['twitter']): ?>
        <a href="<?php echo htmlspecialchars($socialLinks['twitter']); ?>" target="_blank" rel="noopener" aria-label="Twitter">
             <svg viewBox="0 0 24 24"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/></svg>
        </a>
        <?php endif; ?>
        <?php if ($socialLinks['instagram']): ?>
        <a href="<?php echo htmlspecialchars($socialLinks['instagram']); ?>" target="_blank" rel="noopener" aria-label="Instagram">
             <svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
        </a>
        <?php endif; ?>
        <?php if ($socialLinks['linkedin']): ?>
        <a href="<?php echo htmlspecialchars($socialLinks['linkedin']); ?>" target="_blank" rel="noopener" aria-label="LinkedIn">
             <svg viewBox="0 0 24 24"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>
        </a>
        <?php endif; // linkedin
        $youtubeUrl = meridian_setting('footer', 'social_youtube', '');
        if ($youtubeUrl): ?>
        <a href="<?php echo htmlspecialchars($youtubeUrl); ?>" target="_blank" rel="noopener" aria-label="YouTube">
             <svg viewBox="0 0 24 24"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46a2.78 2.78 0 0 0-1.95 1.96A29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58A2.78 2.78 0 0 0 3.41 19.54C5.12 20 12 20 12 20s6.88 0 8.59-.46a2.78 2.78 0 0 0 1.95-1.96A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58z"/><polygon points="9.75 15.02 15.5 12 9.75 8.98 9.75 15.02"/></svg>
        </a>
        <?php endif; // youtube ?>
        <a href="<?php echo htmlspecialchars($socialLinks['rss']); ?>" target="_blank" rel="noopener" aria-label="RSS">
             <svg viewBox="0 0 24 24"><path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/></svg>
        </a>
      </div>
      <?php endif; ?>
    </div>

    <!-- Themes Column -->
    <div class="ft-col">
      <h2 class="ft-col-title"><?php echo htmlspecialchars($col1Title); ?></h2>
      <?php if (!empty($footerTopicsMenu)): ?>
        <?php $renderFooterMenuLinks($footerTopicsMenu); ?>
      <?php else: ?>
        <?php
          $cats = function_exists('meridian_get_categories') ? meridian_get_categories(6) : [];
          if (!empty($cats)) {
              foreach ($cats as $cat) {
                   echo '<a href="'. htmlspecialchars(meridian_archive_url('category', (string) ($cat['slug'] ?? '')), ENT_QUOTES, 'UTF-8') .'">'. htmlspecialchars($cat['name'] ?? '') .'</a>';
              }
          } else {
              echo '<a href="'. SITE_URL .'/blog">Alle Artikel</a>';
          }
        ?>
      <?php endif; ?>
    </div>

    <!-- Resources Column -->
    <div class="ft-col">
      <h2 class="ft-col-title"><?php echo htmlspecialchars($col2Title); ?></h2>
      <?php if (!empty($footerResourcesMenu)): ?>
        <?php $renderFooterMenuLinks($footerResourcesMenu); ?>
      <?php else: ?>
        <a href="<?php echo SITE_URL; ?>/blog">Script-Bibliothek</a>
        <a href="<?php echo SITE_URL; ?>/blog">Tutorials</a>
        <a href="<?php echo SITE_URL; ?>/search">Suche</a>
        <a href="<?php echo htmlspecialchars(meridian_auth_url('register'), ENT_QUOTES, 'UTF-8'); ?>">Newsletter</a>
      <?php endif; ?>
    </div>

    <!-- About Column -->
    <div class="ft-col">
      <h2 class="ft-col-title"><?php echo htmlspecialchars($col3Title); ?></h2>
      <?php if (!empty($footerAboutMenu)): ?>
        <?php $renderFooterMenuLinks($footerAboutMenu); ?>
      <?php else: ?>
        <?php // Nur vorhandene Seiten verlinken (vorher feste Links auf /about, /impressum … auch ohne Seite → 404). ?>
        <?php foreach (meridian_footer_fallback_links() as $aboutLink): ?>
        <a href="<?php echo htmlspecialchars(rtrim((string) SITE_URL, '/') . (string) $aboutLink['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $aboutLink['label'], ENT_QUOTES, 'UTF-8'); ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>
  <?php endif; ?>

  <div class="footer-bottom">
    <span class="copy"><?php echo $copyrightText; ?></span>
    <div class="footer-legal">
      <?php if (!empty($footerLegalMenu)): ?>
        <?php $renderFooterMenuLinks($footerLegalMenu); ?>
      <?php else: ?>
        <a href="<?php echo SITE_URL; ?>/impressum">Impressum</a>
        <a href="<?php echo SITE_URL; ?>/datenschutz">Datenschutz</a>
      <?php endif; ?>
    </div>
  </div>
</footer>

<?php if ($showBackToTop): ?>
<button type="button" id="scrollTop" class="scroll-top" aria-label="Zurück nach oben" title="Zurück nach oben">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 15l-6-6-6 6"/></svg>
</button>
<?php endif; ?>

<?php
// theme.js und Cookie-Banner (before_footer) gibt der Core bereits vor footer.php aus;
// body_end (Core-Cookie-Consent, Web-Vitals, PhotoSwipe) wird hier vor </body> ausgelöst.
// Beide Hooks laufen pro Request nur einmal.
if (class_exists('\\CMS\\Hooks')) {
    \CMS\Hooks::doAction('before_footer');
    \CMS\Hooks::doAction('body_end');
}
?>

</body>
</html>
