<?php
/**
 * Downloads – Archivseite (/downloads, /downloads/category/<slug>).
 *
 * Theme-Override: themes/<slug>/downloads/archive.php
 *
 * @var array<string,mixed> $data title, description, downloads (View-Modelle), categories, category, show_search,
 *                                show_categories, search, archive_url, form_action, total, page, pages,
 *                                prev_url, next_url, lang
 */

declare(strict_types=1);

use CMS\Services\Downloads\DownloadRenderer;

if (!defined('ABSPATH')) {
    exit;
}

$dlDownloads = is_array($data['downloads'] ?? null) ? $data['downloads'] : [];
$dlCategories = is_array($data['categories'] ?? null) ? $data['categories'] : [];
$dlSearch = (string) ($data['search'] ?? '');
$dlCategory = is_array($data['category'] ?? null) ? $data['category'] : null;
?>
<div class="cms-dl-archive">
    <?php if ((string) ($data['description'] ?? '') !== '') : ?>
        <p class="cms-dl-archive__intro"><?php echo nl2br(esc_html((string) $data['description'])); ?></p>
    <?php endif; ?>

    <?php if (!empty($data['show_search'])) : ?>
        <form class="cms-dl-archive__search" method="get" action="<?php echo esc_attr(esc_url((string) $data['form_action'])); ?>" role="search">
            <label class="cms-dl__sr" for="cms-dl-search"><?php echo esc_html(__('downloads.archive.search_label')); ?></label>
            <input type="search" id="cms-dl-search" name="q" value="<?php echo esc_attr($dlSearch); ?>" maxlength="100" placeholder="<?php echo esc_attr(__('downloads.archive.search_label')); ?>">
            <button type="submit" class="cms-dl__button cms-dl__button--small"><?php echo esc_html(__('downloads.archive.search_submit')); ?></button>
        </form>
    <?php endif; ?>

    <?php if (!empty($data['show_categories']) && $dlCategories !== []) : ?>
        <nav class="cms-dl-archive__categories" aria-label="<?php echo esc_attr(__('downloads.archive.categories')); ?>">
            <ul role="list">
                <li><a href="<?php echo esc_attr(esc_url((string) $data['archive_url'])); ?>"<?php echo $dlCategory === null ? ' aria-current="page"' : ''; ?>><?php echo esc_html(__('downloads.archive.all')); ?></a></li>
                <?php foreach ($dlCategories as $dlTerm) : ?>
                    <li><a href="<?php echo esc_attr(esc_url((string) $dlTerm['url'])); ?>"<?php echo !empty($dlTerm['active']) ? ' aria-current="page"' : ''; ?>><?php echo esc_html((string) $dlTerm['name']); ?> <span class="cms-dl-archive__count"><?php echo (int) $dlTerm['count']; ?></span></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>

    <?php if ($dlDownloads === []) : ?>
        <p class="cms-dl-notice cms-dl-notice--info" role="status"><?php echo esc_html(__($dlSearch !== '' ? 'downloads.archive.empty_search' : 'downloads.archive.empty')); ?></p>
    <?php else : ?>
        <p class="cms-dl__sr" role="status"><?php echo esc_html(sprintf(__('downloads.archive.total'), (int) ($data['total'] ?? 0))); ?></p>
        <ul class="cms-dl-archive__list" role="list">
            <?php foreach ($dlDownloads as $dl) : ?>
                <li class="cms-dl cms-dl--teaser<?php echo $dl['locked'] ? ' is-locked' : ''; ?>">
                    <span class="cms-dl__icon cms-dl__icon--<?php echo esc_attr((string) $dl['file_group']); ?>"><?php echo DownloadRenderer::iconSvg((string) $dl['file_group']); ?><span class="cms-dl__type"><?php echo esc_html((string) $dl['ext_label']); ?></span></span>
                    <div class="cms-dl-teaser__body">
                        <h2 class="cms-dl__title"><a href="<?php echo esc_attr(esc_url((string) $dl['single_url'])); ?>"><?php echo esc_html((string) $dl['title']); ?></a></h2>
                        <?php if ($dl['summary'] !== '') : ?>
                            <p class="cms-dl__summary"><?php echo esc_html((string) $dl['summary']); ?></p>
                        <?php endif; ?>
                        <ul class="cms-dl__meta" role="list">
                            <?php if ($dl['category'] !== '') : ?><li class="cms-dl__meta-item"><?php echo esc_html((string) $dl['category']); ?></li><?php endif; ?>
                            <?php if ($dl['version'] !== '') : ?><li class="cms-dl__meta-item"><?php echo esc_html(sprintf(__('downloads.meta.version'), (string) $dl['version'])); ?></li><?php endif; ?>
                            <?php if ($dl['release_date_label'] !== '') : ?><li class="cms-dl__meta-item"><?php echo esc_html((string) $dl['release_date_label']); ?></li><?php endif; ?>
                            <?php if ($dl['size_label'] !== '') : ?><li class="cms-dl__meta-item"><?php echo esc_html((string) $dl['size_label']); ?></li><?php endif; ?>
                        </ul>
                    </div>
                    <div class="cms-dl__actions">
                        <?php if ($dl['locked']) : ?>
                            <?php echo DownloadRenderer::lockNotice($dl); ?>
                        <?php else : ?>
                            <a class="cms-dl__button" href="<?php echo esc_attr(esc_url((string) $dl['url'])); ?>" rel="nofollow" aria-label="<?php echo esc_attr((string) $dl['aria_label']); ?>"><?php echo esc_html((string) $dl['button_label']); ?></a>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ((int) ($data['pages'] ?? 1) > 1) : ?>
            <nav class="cms-dl-archive__pager" aria-label="<?php echo esc_attr(__('downloads.archive.pager')); ?>">
                <?php if ((string) $data['prev_url'] !== '') : ?><a class="cms-dl__link" rel="prev" href="<?php echo esc_attr(esc_url((string) $data['prev_url'])); ?>"><?php echo esc_html(__('downloads.archive.prev')); ?></a><?php endif; ?>
                <span><?php echo esc_html(sprintf(__('downloads.archive.page_of'), (int) $data['page'], (int) $data['pages'])); ?></span>
                <?php if ((string) $data['next_url'] !== '') : ?><a class="cms-dl__link" rel="next" href="<?php echo esc_attr(esc_url((string) $data['next_url'])); ?>"><?php echo esc_html(__('downloads.archive.next')); ?></a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
