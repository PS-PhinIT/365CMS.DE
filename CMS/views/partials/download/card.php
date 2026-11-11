<?php
/**
 * Download-Template „Karte“ (Standard): Titel, Kurzbeschreibung, Meta-Zeile (Version, Datum, Größe, Zähler),
 * SHA-256 mit Kopier-Button, gewählte Eigenschaftsfelder, Hauptbutton und Link „Alle Versionen“.
 *
 * Theme-Override: themes/<slug>/partials/download/card.php
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
$meta = [];
if (!empty($options['showVersion']) && $dl['version'] !== '') {
    $meta[] = ['version', sprintf(__('downloads.meta.version'), (string) $dl['version'])];
}
if (!empty($options['showDate']) && $dl['release_date_label'] !== '') {
    $meta[] = ['date', (string) $dl['release_date_label']];
}
if (!empty($options['showSize']) && $dl['size_label'] !== '') {
    $meta[] = ['size', (string) $dl['size_label']];
}
if (!empty($options['showCount'])) {
    $meta[] = ['count', sprintf(__('downloads.meta.count'), (string) $dl['count_label'])];
}
?>
<article class="cms-dl cms-dl--card<?php echo $dl['locked'] ? ' is-locked' : ''; ?>">
    <div class="cms-dl-card__head">
        <span class="cms-dl__icon cms-dl__icon--<?php echo esc_attr((string) $dl['file_group']); ?>"><?php echo DownloadRenderer::iconSvg((string) $dl['file_group']); ?><span class="cms-dl__type"><?php echo esc_html((string) $dl['ext_label']); ?></span></span>
        <h3 class="cms-dl__title"><?php echo esc_html((string) $dl['title']); ?></h3>
    </div>
    <?php if (!empty($options['showDescription']) && $dl['summary'] !== '') : ?>
        <p class="cms-dl__summary"><?php echo nl2br(esc_html((string) $dl['summary'])); ?></p>
    <?php endif; ?>
    <?php if ($meta !== []) : ?>
        <ul class="cms-dl__meta" role="list">
            <?php foreach ($meta as [$key, $label]) : ?>
                <li class="cms-dl__meta-item cms-dl__meta-item--<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if (!empty($options['showHash']) && !$dl['locked']) : ?>
        <?php echo DownloadRenderer::hashBox($dl); ?>
    <?php endif; ?>
    <?php if ($dl['properties'] !== []) : ?>
        <dl class="cms-dl__props">
            <?php foreach ($dl['properties'] as $property) : ?>
                <div class="cms-dl__prop">
                    <dt><?php echo esc_html((string) $property['label']); ?></dt>
                    <dd><?php if ($property['url'] !== '') : ?><a href="<?php echo esc_attr(esc_url((string) $property['url'])); ?>" rel="nofollow noopener"><?php echo esc_html((string) $property['value']); ?></a><?php else : ?><?php echo esc_html((string) $property['value']); ?><?php endif; ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>
    <div class="cms-dl__actions">
        <?php if ($dl['locked']) : ?>
            <?php echo DownloadRenderer::lockNotice($dl); ?>
        <?php else : ?>
            <a class="cms-dl__button" href="<?php echo esc_attr(esc_url((string) $dl['url'])); ?>" rel="nofollow"<?php echo $dl['track_source'] ? ' data-cms-dl-src' : ''; ?> aria-label="<?php echo esc_attr((string) $dl['aria_label']); ?>"><?php echo esc_html((string) $dl['button_label']); ?></a>
        <?php endif; ?>
        <a class="cms-dl__link" href="<?php echo esc_attr(esc_url((string) $dl['single_url'] . '#versions')); ?>"><?php echo esc_html(__('downloads.link.all_versions')); ?></a>
    </div>
</article>
