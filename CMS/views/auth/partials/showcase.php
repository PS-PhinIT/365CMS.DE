<?php
/**
 * Login-Showcase (Bild und Text unter dem Anmeldebereich).
 *
 * Eingebunden über CmsAuthPageService::renderShowcase(). Themes können dieses Partial mit
 * `<theme>/auth/showcase.php` ersetzen; verfügbar sind dieselben Variablen:
 *
 * @var array{position:string,eyebrow:string,headline:string,paragraphs:list<string>,features:list<string>,cta:?array{label:string,url:string},image:?array{src:string,webp:?string,alt:string,width:?int,height:?int}} $showcase Rohwerte (nicht escaped)
 * @var string $pageType     login | register
 * @var string $showcaseMode cms (Core-Loginseite) | legacy (Theme-Vorlage)
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$showcaseEscape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$showcaseUrl = static function (string $url) use ($showcaseEscape): string {
    return $showcaseEscape(function_exists('esc_url') ? esc_url($url) : $url);
};

$showcasePosition = ($showcase['position'] ?? 'left') === 'right' ? 'right' : 'left';
$showcaseEyebrow = (string) ($showcase['eyebrow'] ?? '');
$showcaseHeadline = (string) ($showcase['headline'] ?? '');
$showcaseParagraphs = is_array($showcase['paragraphs'] ?? null) ? $showcase['paragraphs'] : [];
$showcaseFeatures = is_array($showcase['features'] ?? null) ? $showcase['features'] : [];
$showcaseCta = is_array($showcase['cta'] ?? null) ? $showcase['cta'] : null;
$showcaseImage = is_array($showcase['image'] ?? null) ? $showcase['image'] : null;
$showcaseImageAlt = $showcaseImage !== null ? trim((string) ($showcaseImage['alt'] ?? '')) : '';
$showcaseImageSrc = $showcaseImage !== null ? (string) ($showcaseImage['src'] ?? '') : '';
if ($showcaseImageAlt === '' || $showcaseImageSrc === '') {
    $showcaseImage = null;
}
$showcaseLabelId = $showcaseHeadline !== '' ? 'cms-auth-showcase-title' : '';
$showcaseClasses = 'cms-auth-showcase cms-auth-showcase--image-' . $showcasePosition
    . ($showcaseImage === null ? ' cms-auth-showcase--no-image' : '');
?>
<section id="cms-auth-showcase" class="<?php echo $showcaseEscape($showcaseClasses); ?>"<?php if ($showcaseLabelId !== ''): ?> aria-labelledby="<?php echo $showcaseEscape($showcaseLabelId); ?>"<?php endif; ?>>
    <div class="cms-auth-showcase__content">
        <?php if ($showcaseEyebrow !== ''): ?>
            <p class="cms-auth-showcase__eyebrow"><?php echo $showcaseEscape($showcaseEyebrow); ?></p>
        <?php endif; ?>
        <?php if ($showcaseHeadline !== ''): ?>
            <h2 id="cms-auth-showcase-title" class="cms-auth-showcase__title"><?php echo $showcaseEscape($showcaseHeadline); ?></h2>
        <?php endif; ?>
        <?php foreach ($showcaseParagraphs as $showcaseParagraph): ?>
            <p class="cms-auth-showcase__text"><?php echo $showcaseEscape($showcaseParagraph); ?></p>
        <?php endforeach; ?>
        <?php if ($showcaseFeatures !== []): ?>
            <ul class="cms-auth-showcase__features">
                <?php foreach ($showcaseFeatures as $showcaseFeature): ?>
                    <li class="cms-auth-showcase__feature">
                        <svg class="cms-auth-showcase__check" aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"/></svg>
                        <span><?php echo $showcaseEscape($showcaseFeature); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($showcaseCta !== null && trim((string) ($showcaseCta['label'] ?? '')) !== '' && trim((string) ($showcaseCta['url'] ?? '')) !== ''): ?>
            <p class="cms-auth-showcase__cta-row">
                <a class="cms-auth-showcase__cta" href="<?php echo $showcaseUrl((string) $showcaseCta['url']); ?>"><?php echo $showcaseEscape($showcaseCta['label']); ?></a>
            </p>
        <?php endif; ?>
    </div>
    <?php if ($showcaseImage !== null): ?>
        <?php
        $showcaseWidth = (int) ($showcaseImage['width'] ?? 0);
        $showcaseHeight = (int) ($showcaseImage['height'] ?? 0);
        $showcaseSizeAttributes = $showcaseWidth > 0 && $showcaseHeight > 0
            ? ' width="' . $showcaseWidth . '" height="' . $showcaseHeight . '"'
            : '';
        $showcaseWebp = trim((string) ($showcaseImage['webp'] ?? ''));
        ?>
        <figure class="cms-auth-showcase__media">
            <?php if ($showcaseWebp !== ''): ?>
                <picture>
                    <source type="image/webp" srcset="<?php echo $showcaseUrl($showcaseWebp); ?>">
                    <img class="cms-auth-showcase__image" src="<?php echo $showcaseUrl($showcaseImageSrc); ?>" alt="<?php echo $showcaseEscape($showcaseImageAlt); ?>"<?php echo $showcaseSizeAttributes; ?> loading="lazy" decoding="async">
                </picture>
            <?php else: ?>
                <img class="cms-auth-showcase__image" src="<?php echo $showcaseUrl($showcaseImageSrc); ?>" alt="<?php echo $showcaseEscape($showcaseImageAlt); ?>"<?php echo $showcaseSizeAttributes; ?> loading="lazy" decoding="async">
            <?php endif; ?>
        </figure>
    <?php endif; ?>
</section>
