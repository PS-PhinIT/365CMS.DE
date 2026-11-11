<?php
/**
 * Downloads – Einzelseite (/downloads/<slug>): Release-Box, Beschreibung, alle Versionen mit Changelog,
 * Eigenschaften und Schema.org-Auszeichnung.
 *
 * Theme-Override: themes/<slug>/downloads/single.php
 *
 * @var array<string,mixed> $data item, download (View-Modell oder null), versions, tags, description_html,
 *                                archive_url, schema, is_draft, lang
 */

declare(strict_types=1);

use CMS\Services\Downloads\DownloadRenderer;

if (!defined('ABSPATH')) {
    exit;
}

$dlModel = is_array($data['download'] ?? null) ? $data['download'] : null;
$dlVersions = is_array($data['versions'] ?? null) ? $data['versions'] : [];
$dlTags = is_array($data['tags'] ?? null) ? $data['tags'] : [];
$dlSchema = is_array($data['schema'] ?? null) ? $data['schema'] : null;
$dlNonce = '';
try {
    $dlNonce = (string) \CMS\Security::instance()->getNonce();
} catch (\Throwable) {
}
?>
<div class="cms-dl-single">
    <p class="cms-dl-single__back"><a class="cms-dl__link" href="<?php echo esc_attr(esc_url((string) $data['archive_url'])); ?>">← <?php echo esc_html(__('downloads.single.back')); ?></a></p>

    <?php if (!empty($data['is_draft'])) : ?>
        <p class="cms-dl-notice cms-dl-notice--warning" role="note"><?php echo esc_html(__('downloads.single.draft_notice')); ?></p>
    <?php endif; ?>

    <?php if ($dlModel !== null) : ?>
        <?php
        $dlOptions = DownloadRenderer::DEFAULT_OPTIONS;
        $dlOptions['showProperties'] = array_column($dlModel['properties'], 'key');
        echo DownloadRenderer::getInstance()->renderPartial('featured', [$dlModel], $dlOptions, 'page');
        ?>
    <?php else : ?>
        <p class="cms-dl-notice cms-dl-notice--info" role="note"><?php echo esc_html(__('downloads.single.no_version')); ?></p>
    <?php endif; ?>

    <?php if (trim((string) ($data['description_html'] ?? '')) !== '') : ?>
        <section class="cms-dl-single__description" aria-label="<?php echo esc_attr(__('downloads.single.description')); ?>">
            <?php echo (string) $data['description_html']; // beim Speichern mit PurifierService bereinigt ?>
        </section>
    <?php endif; ?>

    <?php if ($dlTags !== []) : ?>
        <ul class="cms-dl-single__tags" role="list" aria-label="<?php echo esc_attr(__('downloads.single.tags')); ?>">
            <?php foreach ($dlTags as $dlTag) : ?>
                <li><?php echo esc_html((string) $dlTag['name']); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($dlVersions !== []) : ?>
        <section class="cms-dl-single__versions" id="versions" aria-labelledby="cms-dl-versions-title">
            <h2 id="cms-dl-versions-title"><?php echo esc_html(__('downloads.single.versions')); ?></h2>
            <span id="changelog"></span>
            <ol class="cms-dl-versions" role="list">
                <?php foreach ($dlVersions as $dlVersion) : ?>
                    <li class="cms-dl-version<?php echo $dlVersion['is_current'] ? ' is-current' : ''; ?>">
                        <div class="cms-dl-version__head">
                            <h3 class="cms-dl-version__title">
                                <?php echo esc_html(sprintf(__('downloads.meta.version'), (string) $dlVersion['version'])); ?>
                                <?php if ($dlVersion['is_current']) : ?><span class="cms-dl__badge cms-dl__badge--version"><?php echo esc_html(__('downloads.single.current')); ?></span><?php endif; ?>
                            </h3>
                            <ul class="cms-dl__meta" role="list">
                                <?php if ($dlVersion['release_date_label'] !== '') : ?><li class="cms-dl__meta-item"><?php echo esc_html((string) $dlVersion['release_date_label']); ?></li><?php endif; ?>
                                <?php if ($dlVersion['size_label'] !== '') : ?><li class="cms-dl__meta-item"><?php echo esc_html((string) $dlVersion['size_label']); ?></li><?php endif; ?>
                            </ul>
                            <?php if ($dlVersion['url'] !== '') : ?>
                                <a class="cms-dl__button cms-dl__button--small" href="<?php echo esc_attr(esc_url((string) $dlVersion['url'])); ?>" rel="nofollow"
                                   aria-label="<?php echo esc_attr(sprintf(__('downloads.single.download_version'), (string) $dlVersion['version'])); ?>"><?php echo esc_html(__($dlVersion['is_external'] ? 'downloads.button.external' : 'downloads.button.download')); ?></a>
                            <?php endif; ?>
                        </div>
                        <?php if ($dlVersion['changelog'] !== '') : ?>
                            <p class="cms-dl-version__changelog"><?php echo nl2br(esc_html((string) $dlVersion['changelog'])); ?></p>
                        <?php endif; ?>
                        <?php if ($dlVersion['url'] !== '' && preg_match('/^[a-f0-9]{64}$/', (string) $dlVersion['sha256']) === 1) : ?>
                            <?php echo DownloadRenderer::hashBox(['sha256' => $dlVersion['sha256'], 'file_name' => $dlVersion['file_name'], 'title' => (string) ($data['item']['title'] ?? '')]); ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>

    <?php if ($dlSchema !== null) : ?>
        <script type="application/ld+json"<?php echo $dlNonce !== '' ? ' nonce="' . esc_attr($dlNonce) . '"' : ''; ?>><?php echo json_encode($dlSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE); ?></script>
    <?php endif; ?>
</div>
