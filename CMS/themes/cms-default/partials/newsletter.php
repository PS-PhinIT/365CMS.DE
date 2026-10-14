<?php
/**
 * Meridian CMS Default – Newsletter-Widget Partial
 *
 * Kann in Sidebar, Footer oder eigenständig eingebunden werden.
 *
 * @package CMSv2\Themes\CmsDefault\Partials
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$nlTitle  = meridian_setting('newsletter', 'widget_title',    'Kein Artikel verpassen');
$nlDesc   = meridian_setting('newsletter', 'widget_desc',     'Praxiswissen direkt ins Postfach – kein Spam, jederzeit abbestellbar.');
$nlBtn    = meridian_setting('newsletter', 'widget_btn_text', 'Jetzt abonnieren →');
// Es gibt keine Route /newsletter/subscribe – wie in home.php zur Registrierung weiterleiten (E-Mail wird dort vorbelegt).
$nlAction = meridian_auth_url('register');
?>
<div class="newsletter-widget sidebar-widget">
    <div class="widget-title">Newsletter</div>
    <h3 style="font-family:var(--font-serif);font-size:1rem;margin:.25rem 0 .5rem;"><?php echo htmlspecialchars($nlTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
    <?php if ($nlDesc): ?>
    <p style="font-size:.82rem;color:rgba(255,255,255,.75);margin:0 0 .9rem;line-height:1.5;"><?php echo htmlspecialchars($nlDesc, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <form action="<?php echo htmlspecialchars($nlAction, ENT_QUOTES, 'UTF-8'); ?>" method="GET" class="newsletter-form">
        <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
            <input type="email" name="email"
                   placeholder="deine@email.de"
                   required
                   autocomplete="email"
                   aria-label="E-Mail-Adresse für Newsletter"
                   style="flex:1;min-width:140px;padding:.5rem .75rem;border:1.5px solid var(--rule);border-radius:var(--r);font-size:.85rem;background:var(--surface);color:var(--ink);outline:none;">
            <button type="submit"
                    style="padding:.5rem 1rem;background:var(--accent-dark, #a06b18);color:#fff;border:none;border-radius:var(--r);font-size:.82rem;font-weight:600;cursor:pointer;white-space:nowrap;transition:background .2s;">
                <?php echo htmlspecialchars($nlBtn, ENT_QUOTES, 'UTF-8'); ?>
            </button>
        </div>
    </form>
</div>
