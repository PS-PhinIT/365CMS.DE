<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// ── Landing Page Daten laden ──────────────────────────────
$lpHeader      = [];
$lpFeatures    = [];
$lpFooter      = [];
$lpColors      = [];
$lpSettings    = ['show_footer_section' => true];
$lpContentSets = ['content_type' => 'features', 'posts_count' => 5];
$lpPluginHtml  = ['header' => '', 'content' => '', 'footer' => ''];

try {
    $landingSvc    = \CMS\Services\LandingPageService::getInstance();
    $landingSvc->ensureDefaults();
    $lpHeader      = $landingSvc->getHeader();
    $lpFeatures    = $landingSvc->getFeatures();
    $lpFooter      = $landingSvc->getFooter();
    $lpColors      = $lpHeader['colors'] ?? [];
    $lpContentSets = $landingSvc->getContentSettings();
    $lpSettings    = $landingSvc->getSettings();
    $lpDesign      = $landingSvc->getDesign();
} catch (\Throwable $lpEx) {
    error_log('cms-default home.php LandingPageService: ' . $lpEx->getMessage());
    $lpDesign = [];
}

// ── Titel: Customizer-Override → LP-Titel → SITE_NAME ────
$runtimeSiteName = function_exists('cms_get_site_name') ? cms_get_site_name() : (defined('SITE_NAME') ? SITE_NAME : '');
$lpTitle    = $heroTitleOverride
            ?: ($lpHeader['title'] ?? $runtimeSiteName);
$lpSubtitle = $lpHeader['subtitle']    ?? '';
$lpDesc     = $lpHeader['description'] ?? '';
$lpLogo     = $lpHeader['logo']        ?? '';

// ── CTA: LP-Buttons aus Admin ──────────────────────────
$lpBtns = $lpHeader['header_buttons'] ?? [];
if (is_string($lpBtns)) {
    $lpBtns = \CMS\Json::decodeArray($lpBtns, []);
}
if (!is_array($lpBtns)) {
    $lpBtns = [];
}
// Customizer-Override: falls gesetzt, Slot 0 überschreiben
if (!empty($ctaText) && !empty($ctaUrl)) {
    $lpBtns = array_merge(
        [['text' => $ctaText, 'url' => $ctaUrl, 'icon' => '', 'target' => '_self', 'outline' => false]],
        array_slice($lpBtns, 0)
    );
}
// Buttons filtern: nur vollständige Einträge (text + url)
$lpBtns = array_values(array_filter($lpBtns, fn($b) => !empty($b['text']) && !empty($b['url'])));

// ── Farben mit Defaults ───────────────────────────────────
$safe = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$resolveLandingAssetUrl = static function (string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $path) === 1) {
        return function_exists('esc_url') ? esc_url($path) : $path;
    }

    return '/' . ltrim($path, '/');
};
$renderLandingHtml = static function (string $html): string {
    $allowedTags = '<p><a><strong><em><ul><ol><li><h2><h3><h4><br><hr><img><blockquote><code>';
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if ($html === '') {
        return '';
    }

    if (function_exists('sanitize_html')) {
        return (string)sanitize_html($html, 'default');
    }

    return (string)strip_tags($html, $allowedTags);
};

$heroGradStart  = $safe($lpColors['hero_gradient_start'] ?? '#1a2744');
$heroGradEnd    = $safe($lpColors['hero_gradient_end']   ?? '#0c1526');
$heroBorderCol  = $safe($lpColors['hero_border']          ?? '#3b82f6');
$heroTextColor  = $safe($lpColors['hero_text']            ?? '#ffffff');
$featuresBgCol  = $safe($lpColors['features_bg']          ?? '#f8fafc');
$featCardBg     = $safe($lpColors['feature_card_bg']      ?? '#ffffff');
$featCardHover  = $safe($lpColors['feature_card_hover']   ?? '#3b82f6');
$btnPrimary     = $safe($lpColors['primary_button']       ?? '#3b82f6');
$lpLogoUrl      = $resolveLandingAssetUrl((string)$lpLogo);
$lpBgImageUrl   = $resolveLandingAssetUrl((string)($lpHeader['bg_image'] ?? ''));
$lpLogoUrlEscaped = function_exists('esc_url') ? esc_url($lpLogoUrl) : $safe($lpLogoUrl);
$lpBgImageUrlEscaped = function_exists('esc_url') ? esc_url($lpBgImageUrl) : $safe($lpBgImageUrl);
$lpHasLogoImage = $lpLogoUrlEscaped !== '';
$lpHasBgImage = $lpBgImageUrlEscaped !== '';

// ── Design-Tokens aus LP-Admin ────────────────────────────
$dCardBr     = max(0, min(48, (int)($lpDesign['card_border_radius']   ?? 12)));
$dBtnBr      = max(0, min(50, (int)($lpDesign['button_border_radius'] ?? 8)));
$dIconLayout = $lpDesign['card_icon_layout']  ?? 'top';   // 'top' | 'left'
$dBorderCol  = $safe($lpDesign['card_border_color']  ?? '#e2e8f0');
$dBorderW    = $safe($lpDesign['card_border_width']  ?? '1px');
$dShadow     = $lpDesign['card_shadow']       ?? 'sm';   // none|sm|md|lg
$dColumns    = $lpDesign['feature_columns']   ?? 'auto'; // auto|2|3|4
$dContentBg  = $safe($lpDesign['content_section_bg'] ?? '#ffffff');
$dFooterBg   = $safe($lpDesign['footer_bg'] ?? '#0f172a');
$dFooterText = $safe($lpDesign['footer_text_color'] ?? '#cbd5e1');

$shadowMap = [
    'none' => 'none',
    'sm'   => '0 1px 4px rgba(0,0,0,.08)',
    'md'   => '0 4px 12px rgba(0,0,0,.12)',
    'lg'   => '0 8px 24px rgba(0,0,0,.18)',
];
$dShadowVal = $shadowMap[$dShadow] ?? $shadowMap['sm'];

$columnMap = [
    'auto' => 'repeat(auto-fill,minmax(240px,1fr))',
    '2'    => 'repeat(2,1fr)',
    '3'    => 'repeat(3,1fr)',
    '4'    => 'repeat(4,1fr)',
];
$dColumnsVal = $columnMap[$dColumns] ?? $columnMap['auto'];

$lpFooterContent    = (string)($lpFooter['content'] ?? '');
$lpFooterButtonText = (string)($lpFooter['button_text'] ?? '');
$lpFooterButtonUrl  = (string)($lpFooter['button_url'] ?? '');
$lpFooterCopyright  = (string)($lpFooter['copyright'] ?? '');
$showLandingFooter  = !empty($lpSettings['show_footer_section']) && !empty($lpFooter['show_footer']);
$lpDescHtml         = $renderLandingHtml((string)$lpDesc);
$lpFooterContentHtml = $renderLandingHtml($lpFooterContent);

if (($landingSvc ?? null) instanceof \CMS\Services\LandingPageService) {
    $lpPluginContext = [
        'header' => $lpHeader,
        'features' => $lpFeatures,
        'footer' => $lpFooter,
        'colors' => $lpColors,
        'content' => $lpContentSets,
        'settings' => $lpSettings,
        'design' => $lpDesign,
    ];

    $lpPluginHtml['header'] = $landingSvc->renderPluginOverride('header', $lpPluginContext);
    $lpPluginHtml['content'] = $landingSvc->renderPluginOverride('content', $lpPluginContext);
    $lpPluginHtml['footer'] = $landingSvc->renderPluginOverride('footer', $lpPluginContext);
}

// ── Layout: compact vs. standard ─────────────────────────
$lpLayout    = $lpHeader['header_layout'] ?? 'standard';
$lpBadgeText = trim((string)($lpHeader['badge_text'] ?? ''));
$lpIsCompact = ($lpLayout === 'compact');
$heroPadding = $lpIsCompact ? '1.75rem 1.5rem' : '5rem 2rem';
$heroTitleSz = $lpIsCompact ? '1.75rem'        : 'clamp(1.8rem,4vw,3rem)';
$logoMaxH    = $lpIsCompact ? '50px'           : '90px';
$logoMarginB = $lpIsCompact ? '0.75rem'        : '1.75rem';
$subFontSz   = $lpIsCompact ? '1rem'           : '1.25rem';
?>
<style <?= \CMS\Security::instance()->nonceAttr() ?>>
/* Landing Page: Abstände reset */
.category-bar        { display: none !important; }
footer, .site-footer { margin-top: 0 !important; }
main.site-main       { padding: 0 !important; margin: 0 !important; }
#lp-content          { margin: 0; }

/* Farb-Custom-Properties aus LP-Admin */
.lp-hero {
    --lp-grad-start: <?php echo $heroGradStart; ?>;
    --lp-grad-end:   <?php echo $heroGradEnd; ?>;
    --lp-border:     <?php echo $heroBorderCol; ?>;
    --lp-text:       <?php echo $heroTextColor; ?>;
    --lp-btn:        <?php echo $btnPrimary; ?>;
}
.lp-features {
    --lp-features-bg:  <?php echo $featuresBgCol; ?>;
    --lp-card-bg:      <?php echo $featCardBg; ?>;
    --lp-card-hover:   <?php echo $featCardHover; ?>;
    --lp-card-br:      <?php echo $dCardBr; ?>px;
    --lp-card-border:  <?php echo $dBorderW; ?> solid <?php echo $dBorderCol; ?>;
    --lp-card-shadow:  <?php echo $dShadowVal; ?>;
    --lp-columns:      <?php echo $dColumnsVal; ?>;
    --lp-content-bg:   <?php echo $dContentBg; ?>;
}
.lp-features .lp-card-grid {
    display: grid;
    grid-template-columns: var(--lp-columns);
    gap: 1.5rem;
}
.lp-features .lp-feat-card {
    background:    var(--lp-card-bg);
    border:        var(--lp-card-border);
    border-radius: var(--lp-card-br);
    box-shadow:    var(--lp-card-shadow);
    padding:       1.5rem;
    transition:    border-color .2s, box-shadow .2s;
}
.lp-features .lp-feat-card:hover {
    border-color: var(--lp-card-hover) !important;
    box-shadow: 0 6px 20px rgba(0,0,0,.13);
}
.lp-feat-card:not(.lp-feat-card--icon-left) {
    text-align: center;
}
.lp-feat-card:not(.lp-feat-card--icon-left) .lp-feat-card__icon {
    display: block;
    margin: 0 auto .75rem;
}
.lp-feat-card--icon-left {
    display: flex;
    gap: 1rem;
    align-items: flex-start;
}
.lp-feat-card__icon  { font-size: 2rem; line-height: 1; flex-shrink: 0; }
.lp-feat-card__body  { flex: 1; }
.lp-feat-card__title { font-size: 1rem; font-weight: 700; margin: 0 0 .4rem; color: #1e293b; }
.lp-feat-card__desc  { font-size: .9rem; color: #64748b; margin: 0; line-height: 1.5; }
.lp-footer-callout {
    background: <?php echo $dFooterBg; ?>;
    color: <?php echo $dFooterText; ?>;
    padding: 3rem 1.5rem;
}
.lp-footer-callout__inner {
    max-width: 1140px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 1.5rem;
    align-items: center;
}
.lp-footer-callout__content {
    font-size: 1rem;
    line-height: 1.7;
}
.lp-footer-callout__content p {
    margin: 0 0 .75rem;
}
.lp-footer-callout__content p:last-child {
    margin-bottom: 0;
}
.lp-footer-callout__copyright {
    margin-top: 1rem;
    font-size: .875rem;
    opacity: .8;
}
@media (max-width: 768px) {
    .lp-features {
        --lp-columns: 1fr;
    }

    .lp-features .lp-card-grid {
        grid-template-columns: 1fr;
    }

    .lp-feat-card--icon-left {
        flex-direction: column;
        gap: .75rem;
    }

    .lp-feat-card--icon-left .lp-feat-card__icon {
        margin: 0;
    }

    .lp-footer-callout__inner {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="landing-page">
<div id="lp-content">

    <!-- ── Hero ──────────────────────────────────────────── -->
    <?php if ($lpPluginHtml['header'] !== ''): ?>
    <div class="lp-plugin-area lp-plugin-area--header">
        <?php echo $lpPluginHtml['header']; ?>
    </div>
    <?php else: ?>
    <section class="lp-hero" style="
        margin-top:0;
        padding:<?php echo $heroPadding;?>;
        background-color:var(--lp-grad-end);
        background-image:linear-gradient(135deg,var(--lp-grad-start) 0%,var(--lp-grad-end) 100%);
        background-position:center;
        background-repeat:no-repeat;
        background-size:cover;
        border-bottom:3px solid var(--lp-border);
        position:relative;
        overflow:hidden;">
        <?php if ($lpHasBgImage): ?>
        <div aria-hidden="true" style="position:absolute;inset:0;opacity:.32;pointer-events:none;">
            <img src="<?php echo $lpBgImageUrlEscaped; ?>"
                 alt=""
                 loading="eager"
                 style="width:100%;height:100%;object-fit:cover;display:block;">
        </div>
        <?php endif; ?>
        <div class="lp-hero__inner" style="position:relative;z-index:1;">

            <?php if ($lpHasLogoImage): ?>
            <img src="<?php echo $lpLogoUrlEscaped; ?>"
                 alt="<?php echo $safe($lpTitle); ?>"
                 style="max-height:<?php echo $logoMaxH;?>;margin:0 auto <?php echo $logoMarginB;?>;display:block;">
            <?php endif; ?>

            <div style="position:relative;display:inline-block;max-width:100%;">
                <h1 class="lp-hero__title" style="color:var(--lp-text);font-size:<?php echo $heroTitleSz;?>;">
                    <?php echo $safe($lpTitle); ?>
                </h1>
                <?php if ($lpBadgeText !== '' && $lpIsCompact): ?>
                <span style="
                    position:absolute;top:-.35rem;right:-4rem;
                    background:<?php echo $btnPrimary;?>;color:#fff;
                    border-radius:4px;padding:.15rem .55rem;
                    font-size:.75rem;font-weight:700;line-height:1.2;
                    white-space:nowrap;letter-spacing:.02em;
                    box-shadow:0 1px 4px rgba(0,0,0,.25);"><?php echo $safe($lpBadgeText); ?></span>
                <?php elseif ($lpBadgeText !== ''): ?>
                <div style="margin:.6rem 0 .25rem;">
                    <span style="
                        display:inline-block;
                        background:<?php echo $btnPrimary;?>;color:#fff;
                        border-radius:6px;padding:.3rem 1rem;
                        font-size:.85rem;font-weight:700;
                        letter-spacing:.03em;
                        box-shadow:0 1px 6px rgba(0,0,0,.2);"><?php echo $safe($lpBadgeText); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($lpSubtitle): ?>
            <p style="color:var(--lp-text);opacity:.85;font-size:<?php echo $subFontSz;?>;max-width:600px;margin:<?php echo $lpIsCompact ? '.5rem' : '1rem';?> auto <?php echo $lpIsCompact ? '.75rem' : '1rem';?>;">
                <?php echo $safe($lpSubtitle); ?>
            </p>
            <?php endif; ?>

            <?php if ($lpDesc && $lpDesc !== $lpTitle && $lpDesc !== $lpSubtitle): ?>
            <div style="color:var(--lp-text);opacity:.75;max-width:680px;margin:0 auto <?php echo $lpIsCompact ? '1rem' : '1.5rem';?>;font-size:<?php echo $lpIsCompact ? '.95rem' : '1rem';?>;">
                <?php echo $lpDescHtml; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($lpBtns)): ?>
            <div class="lp-hero__actions" style="margin-top:<?php echo $lpIsCompact ? '1rem' : '2rem';?>;display:flex;flex-wrap:wrap;gap:.75rem;justify-content:center;align-items:center;">
                <?php foreach ($lpBtns as $btn):
                    $btnText    = $safe($btn['text']   ?? '');
                    $btnHref    = $safe($btn['url']    ?? '#');
                    $btnIcon    = $safe($btn['icon']   ?? '');
                    $btnTarget  = in_array($btn['target'] ?? '', ['_blank','_self']) ? $btn['target'] : '_self';
                    $btnOutline = !empty($btn['outline']);
                ?>
                <a href="<?php echo $btnHref; ?>"
                   class="btn-hero<?php echo $btnOutline ? ' btn-hero--outline' : ''; ?>"
                   target="<?php echo $btnTarget; ?>"
                   <?php echo $btnTarget === '_blank' ? 'rel="noopener noreferrer"' : ''; ?>
                   style="<?php echo !$btnOutline ? 'background:var(--lp-btn);' : ''; ?>">
                    <?php if ($btnIcon): ?><span style="margin-right:.35em;"><?php echo $btnIcon; ?></span><?php endif; ?><?php echo $btnText; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div>
    </section>
    <?php endif; ?>

    <!-- ── Hauptinhalt (Features / Artikel / Text) ────────── -->
    <?php
    $lpContentType = (string)($lpContentSets['content_type'] ?? 'features');
    if (!in_array($lpContentType, ['features', 'text', 'posts'], true)) {
        $lpContentType = 'features';
    }
    $lpPostsCount  = max(1, (int)($lpContentSets['posts_count'] ?? 5));
    ?>

    <?php if ($lpPluginHtml['content'] !== ''): ?>
    <div class="lp-plugin-area lp-plugin-area--content">
        <?php echo $lpPluginHtml['content']; ?>
    </div>

    <?php elseif ($lpContentType === 'features' && !empty($lpFeatures)): ?>
    <!-- Feature-Karten aus dem Landing-Page-Admin -->
    <section class="lp-features" style="padding:3rem 0;background:var(--lp-features-bg);">
        <div style="max-width:1140px;margin:0 auto;padding:0 1.5rem;">
            <div class="lp-card-grid">
                <?php foreach ($lpFeatures as $feat):
                    $fTitle = $feat['title']       ?? '';
                    $fText  = $feat['description'] ?? '';
                    $fIcon  = $feat['icon']        ?? '';
                    if (empty($fTitle) && empty($fText)) continue;
                    $isIconLeft = ($dIconLayout === 'left');
                ?>
                <div class="lp-feat-card<?php echo $isIconLeft ? ' lp-feat-card--icon-left' : ''; ?>">
                    <?php if ($fIcon): ?>
                    <div class="lp-feat-card__icon"><?php echo $safe($fIcon); ?></div>
                    <?php endif; ?>
                    <div class="lp-feat-card__body">
                        <?php if ($fTitle): ?>
                        <p class="lp-feat-card__title"><?php echo $safe($fTitle); ?></p>
                        <?php endif; ?>
                        <?php if ($fText): ?>
                        <div class="lp-feat-card__desc"><?php echo $renderLandingHtml((string)$fText); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <?php elseif ($lpContentType === 'posts'): ?>
    <!-- Neueste Artikel (Anzahl aus Landing-Page-Admin) -->
    <?php
    $lpPosts = [];
    $prefix = \CMS\Database::instance()->getPrefix();
    $pdo = \CMS\Database::instance()->getConnection();
    try {
        $stmtLp = $pdo->prepare(
            "SELECT p.*, COALESCE(NULLIF(p.author_display_name, ''), NULLIF(u.display_name, ''), NULLIF(u.username, ''), 'Autor') AS author_name, c.name AS category_name, c.slug AS category_slug
             FROM {$prefix}posts p
             LEFT JOIN {$prefix}users u ON p.author_id = u.id
             LEFT JOIN {$prefix}post_categories c ON p.category_id = c.id
             WHERE " . cms_post_publication_where('p') . "
             ORDER BY p.published_at DESC
             LIMIT " . $lpPostsCount
        );
        $stmtLp->execute();
        $lpPosts = $stmtLp->fetchAll(\PDO::FETCH_OBJ);
    } catch (\Throwable $e) { $lpPosts = []; }
    ?>
    <?php if (!empty($lpPosts)): ?>
    <div class="lp-posts-section">
        <div class="section-label"><h2>Aktuelle Beiträge</h2></div>
        <div class="card-grid">
            <?php foreach ($lpPosts as $post): ?>
            <div class="card">
                <div class="card-thumb" style="background:<?php echo meridian_cat_gradient($post->category_name ?? ''); ?>">
                    <?php if ($post->featured_image): ?>
                    <img src="<?php echo htmlspecialchars($post->featured_image); ?>"
                         alt="<?php echo htmlspecialchars($post->title); ?>"
                         loading="lazy">
                    <?php endif; ?>
                    <?php if ($post->category_name): ?>
                    <span class="card-cat"><?php echo htmlspecialchars($post->category_name); ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <h3><a href="<?php echo htmlspecialchars(meridian_post_url($post), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($post->title); ?></a></h3>
                    <p><?php echo meridian_excerpt((string)($post->excerpt ?: $post->content), 100); ?></p>
                    <div class="card-footer">
                        <time><?php echo meridian_format_date($post->published_at ?? $post->created_at, true); ?></time>
                        <?php echo meridian_post_update_badge($post); ?>
                        <a href="<?php echo htmlspecialchars(meridian_post_url($post), ENT_QUOTES, 'UTF-8'); ?>" class="read-link">Lesen →</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="empty-state" style="padding:3rem 0;">
        <p style="font-size:3rem;margin:0">📰</p>
        <p><strong>Noch keine Artikel veröffentlicht</strong></p>
    </div>
    <?php endif; ?>

    <?php elseif ($lpContentType === 'text'): ?>
    <!-- Freitext aus der Landing Page -->
    <?php $lpContentText = $lpContentSets['content_text'] ?? ''; ?>
    <?php if ($lpContentText): ?>
    <section style="max-width:860px;margin:3rem auto;padding:0 1.5rem;background:var(--lp-content-bg,#fff);">
        <div style="font-size:1.05rem;line-height:1.75;color:#1e293b;">
            <?php echo $renderLandingHtml((string)$lpContentText); ?>
        </div>
    </section>
    <?php else: ?>
    <div class="empty-state" style="padding:3rem 0;">
        <p style="font-size:3rem;margin:0">📝</p>
        <p><strong>Freitext-Bereich ist aktiv</strong></p>
        <p class="text-muted">Trage im Admin unter <a href="/admin/landing-page?tab=content">Landing Page → Content</a> einen Text ein, damit dieser Bereich im Frontend ausgegeben wird.</p>
    </div>
    <?php endif; ?>

    <?php elseif (empty($lpFeatures)): ?>
    <!-- Fallback: Keine LP-Inhalte konfiguriert -->
    <div class="empty-state" style="padding:3rem 0;">
        <p style="font-size:3rem;margin:0">🏗️</p>
        <p><strong>Startseite noch nicht konfiguriert</strong></p>
        <p class="text-muted">Richte die Landing Page unter <a href="/admin/landing-page">Admin → Landing Page</a> ein.</p>
    </div>
    <?php endif; ?>

    <?php if ($lpPluginHtml['footer'] !== ''): ?>
    <div class="lp-plugin-area lp-plugin-area--footer">
        <?php echo $lpPluginHtml['footer']; ?>
    </div>
    <?php elseif ($showLandingFooter && ($lpFooterContent !== '' || $lpFooterButtonText !== '' || $lpFooterCopyright !== '')): ?>
    <section class="lp-footer-callout">
        <div class="lp-footer-callout__inner">
            <div>
                <?php if ($lpFooterContent !== ''): ?>
                <div class="lp-footer-callout__content"><?php echo $lpFooterContentHtml; ?></div>
                <?php endif; ?>
                <?php if ($lpFooterCopyright !== ''): ?>
                <div class="lp-footer-callout__copyright"><?php echo $lpFooterCopyright; ?></div>
                <?php endif; ?>
            </div>

            <?php if ($lpFooterButtonText !== '' && $lpFooterButtonUrl !== ''): ?>
            <div>
                <a href="<?php echo $safe($lpFooterButtonUrl); ?>"
                   class="btn-hero"
                   style="background:var(--lp-btn);border-radius:<?php echo $dBtnBr; ?>px;">
                    <?php echo $safe($lpFooterButtonText); ?>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

</div><!-- /#lp-content -->
</div><!-- /.landing-page -->
