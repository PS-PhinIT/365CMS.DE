<?php
/**
 * Download-Template „Hervorgehoben“: große Release-Box mit Titelbild oder Dateityp-Symbol, Versions-Badge
 * („Neu“ / „Aktualisiert am …“), Changelog-Auszug der aktuellen Version, Voraussetzungen, Lizenz,
 * Signaturhinweis, SHA-256 mit Kopier-Button, Hauptbutton und Sekundäraktionen
 * (Changelog, ältere Versionen, externe Links wie GitHub).
 *
 * Theme-Override: themes/<slug>/partials/download/featured.php
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
$details = [];
if ($dl['requirements'] !== '') {
    $details[] = [__('downloads.field.requirements'), (string) $dl['requirements']];
}
if ($dl['license'] !== '') {
    $details[] = [__('downloads.field.license'), (string) $dl['license']];
}
if ($dl['is_signed']) {
    $details[] = [__('downloads.field.signature'), $dl['signature_info'] !== '' ? sprintf(__('downloads.signature.signed_by'), (string) $dl['signature_info']) : __('downloads.signature.signed')];
}
foreach ($dl['properties'] as $property) {
    $details[] = [(string) $property['label'], (string) $property['value']];
}
?>
<section class="cms-dl cms-dl--featured<?php echo $dl['locked'] ? ' is-locked' : ''; ?>" aria-label="<?php echo esc_attr((string) $dl['title']); ?>">
    <div class="cms-dl-featured__media">
        <?php if ($dl['cover_url'] !== '') : ?>
            <img class="cms-dl-featured__cover" src="<?php echo esc_attr(esc_url((string) $dl['cover_url'])); ?>" alt="" loading="lazy" decoding="async">
        <?php else : ?>
            <span class="cms-dl__icon cms-dl__icon--large cms-dl__icon--<?php echo esc_attr((string) $dl['file_group']); ?>"><?php echo DownloadRenderer::iconSvg((string) $dl['file_group']); ?><span class="cms-dl__type"><?php echo esc_html((string) $dl['ext_label']); ?></span></span>
        <?php endif; ?>
    </div>
    <div class="cms-dl-featured__body">
        <p class="cms-dl-featured__badges">
            <?php if (!empty($options['showVersion']) && $dl['version'] !== '') : ?>
                <span class="cms-dl__badge cms-dl__badge--version"><?php echo esc_html(sprintf(__('downloads.meta.version'), (string) $dl['version'])); ?></span>
            <?php endif; ?>
            <?php if (!empty($options['showDate'])) : ?>
                <span class="cms-dl__badge cms-dl__badge--<?php echo esc_attr((string) $dl['badge']); ?>"><?php echo esc_html((string) $dl['badge_label']); ?></span>
            <?php endif; ?>
        </p>
        <h3 class="cms-dl__title"><?php echo esc_html((string) $dl['title']); ?></h3>
        <?php if (!empty($options['showDescription']) && $dl['summary'] !== '') : ?>
            <p class="cms-dl__summary"><?php echo nl2br(esc_html((string) $dl['summary'])); ?></p>
        <?php endif; ?>
        <?php if (!empty($options['showChangelog']) && $dl['changelog_excerpt'] !== '') : ?>
            <div class="cms-dl-featured__changelog">
                <p class="cms-dl__label"><?php echo esc_html(__('downloads.field.changelog')); ?></p>
                <p><?php echo esc_html((string) $dl['changelog_excerpt']); ?></p>
            </div>
        <?php endif; ?>
        <?php if ($details !== []) : ?>
            <dl class="cms-dl__props">
                <?php foreach ($details as [$label, $value]) : ?>
                    <div class="cms-dl__prop"><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html($value); ?></dd></div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
        <?php if (!empty($options['showHash']) && !$dl['locked']) : ?>
            <?php echo DownloadRenderer::hashBox($dl); ?>
        <?php endif; ?>
        <div class="cms-dl__actions">
            <?php if ($dl['locked']) : ?>
                <?php echo DownloadRenderer::lockNotice($dl); ?>
            <?php else : ?>
                <a class="cms-dl__button cms-dl__button--large" href="<?php echo esc_attr(esc_url((string) $dl['url'])); ?>" rel="nofollow"<?php echo $dl['track_source'] ? ' data-cms-dl-src' : ''; ?> aria-label="<?php echo esc_attr((string) $dl['aria_label']); ?>"><?php echo esc_html((string) $dl['button_label']); ?><?php if (!empty($options['showSize']) && $dl['size_label'] !== '') : ?> <span class="cms-dl__button-meta"><?php echo esc_html((string) $dl['size_label']); ?></span><?php endif; ?></a>
            <?php endif; ?>
            <?php if (!empty($options['showChangelog'])) : ?>
                <a class="cms-dl__link" href="<?php echo esc_attr(esc_url((string) $dl['single_url'] . '#changelog')); ?>"><?php echo esc_html(__('downloads.link.changelog')); ?></a>
            <?php endif; ?>
            <a class="cms-dl__link" href="<?php echo esc_attr(esc_url((string) $dl['single_url'] . '#versions')); ?>"><?php echo esc_html(__('downloads.link.older_versions')); ?></a>
            <?php foreach ($dl['external_links'] as $link) : ?>
                <a class="cms-dl__link cms-dl__link--external" href="<?php echo esc_attr(esc_url((string) $link['url'])); ?>" rel="noopener nofollow" target="_blank"><?php echo esc_html((string) $link['label']); ?><span class="cms-dl__sr"> <?php echo esc_html(__('downloads.link.new_window')); ?></span></a>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($options['showCount'])) : ?>
            <p class="cms-dl__count"><?php echo esc_html(sprintf(__('downloads.meta.count'), (string) $dl['count_label'])); ?></p>
        <?php endif; ?>
    </div>
</section>
