<?php
/**
 * Download-Template „Schaltfläche“: nur der Download-Button mit Dateiname und Größe – für Fließtext.
 *
 * Theme-Override: themes/<slug>/partials/download/button.php
 *
 * @var array<string,mixed> $download View-Modell (siehe DownloadRenderer::viewModel())
 * @var array<string,mixed> $options  Blockoptionen
 * @var string              $mode     frontend | preview | page
 */

declare(strict_types=1);

use CMS\Services\Downloads\DownloadRenderer;

if (!defined('ABSPATH')) {
    exit;
}

$dl = $download;
$meta = array_values(array_filter([
    (string) $dl['file_name'],
    !empty($options['showSize']) ? (string) $dl['size_label'] : '',
], static fn (string $part): bool => $part !== ''));
?>
<div class="cms-dl cms-dl--button<?php echo $dl['locked'] ? ' is-locked' : ''; ?>">
    <?php if ($dl['locked']) : ?>
        <span class="cms-dl-button__title"><?php echo esc_html((string) $dl['title']); ?></span>
        <?php echo DownloadRenderer::lockNotice($dl); ?>
    <?php else : ?>
        <a class="cms-dl__button" href="<?php echo esc_attr(esc_url((string) $dl['url'])); ?>" rel="nofollow"<?php echo $dl['track_source'] ? ' data-cms-dl-src' : ''; ?> aria-label="<?php echo esc_attr((string) $dl['aria_label']); ?>">
            <?php echo DownloadRenderer::iconSvg('download'); ?>
            <span class="cms-dl-button__label"><?php echo esc_html((string) $dl['button_label']); ?></span>
            <?php if ($meta !== []) : ?>
                <span class="cms-dl__button-meta"><?php echo esc_html(implode(' · ', $meta)); ?></span>
            <?php endif; ?>
        </a>
    <?php endif; ?>
</div>
