<?php
/**
 * Download-Template „Kompakt“: eine Zeile je Download (Dateityp-Symbol, Titel, Version, Größe, Button).
 * Für Fließtext und Listen mehrerer Downloads.
 *
 * Theme-Override: themes/<slug>/partials/download/compact.php
 *
 * @var list<array<string,mixed>> $downloads View-Modelle (siehe DownloadRenderer::viewModel())
 * @var array<string,mixed>       $options   Blockoptionen
 * @var string                    $mode      frontend | preview | page
 */

declare(strict_types=1);

use CMS\Services\Downloads\DownloadRenderer;

if (!defined('ABSPATH')) {
    exit;
}
?>
<ul class="cms-dl cms-dl--compact" role="list">
<?php foreach ($downloads as $dl) : ?>
    <li class="cms-dl-row<?php echo $dl['locked'] ? ' is-locked' : ''; ?>">
        <span class="cms-dl__icon cms-dl__icon--<?php echo esc_attr((string) $dl['file_group']); ?>"><?php echo DownloadRenderer::iconSvg((string) $dl['file_group']); ?></span>
        <span class="cms-dl-row__main">
            <span class="cms-dl-row__title"><?php echo esc_html((string) $dl['title']); ?></span>
            <span class="cms-dl-row__meta">
                <span class="cms-dl__type"><?php echo esc_html((string) $dl['ext_label']); ?></span>
                <?php if (!empty($options['showVersion']) && $dl['version'] !== '') : ?>
                    <span class="cms-dl__version"><?php echo esc_html(sprintf(__('downloads.meta.version_short'), (string) $dl['version'])); ?></span>
                <?php endif; ?>
                <?php if (!empty($options['showSize']) && $dl['size_label'] !== '') : ?>
                    <span class="cms-dl__size"><?php echo esc_html((string) $dl['size_label']); ?></span>
                <?php endif; ?>
            </span>
        </span>
        <?php if ($dl['locked']) : ?>
            <?php echo DownloadRenderer::lockNotice($dl); ?>
        <?php else : ?>
            <a class="cms-dl__button cms-dl__button--small" href="<?php echo esc_attr(esc_url((string) $dl['url'])); ?>" rel="nofollow"<?php echo $dl['track_source'] ? ' data-cms-dl-src' : ''; ?> aria-label="<?php echo esc_attr((string) $dl['aria_label']); ?>"><?php echo esc_html((string) $dl['button_label']); ?></a>
        <?php endif; ?>
    </li>
<?php endforeach; ?>
</ul>
