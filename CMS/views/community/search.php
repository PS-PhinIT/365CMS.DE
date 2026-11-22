<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Community – Suche. Treffer kommen aus `CommunitySearch::search()` (ein Treffer je Thema, Rechtefilter im Dienst).
 * Variablen: $query, $forum_id, $forums (lesbare Foren), $result (Dienstergebnis oder null ohne Suchbegriff), $page, $locale, $viewer, $flash, $e.
 */

use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Urls;

$active = 'search';
$failed = is_array($result) && empty($result['success']);
$hits = is_array($result) && !empty($result['success']) ? (array) $result['results'] : [];
$pages = is_array($result) && !empty($result['success']) ? (int) ($result['pages'] ?? 1) : 1;
$link = static fn (int $n): string => Urls::search($locale) . '?q=' . rawurlencode($query) . ($forum_id > 0 ? '&forum=' . $forum_id : '') . ($n > 1 ? '&seite=' . $n : '');
?>
<div id="main" class="cms-community">
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <h1><?= $e(I18n::t('community.view.search.heading')) ?></h1>
    <form method="get" class="cms-community-form" action="<?= $e(Urls::search($locale)) ?>">
        <input type="hidden" name="q" value="<?= $e($query) ?>">
        <p><label for="cc-forum"><?= $e(I18n::t('community.view.search.only_forum')) ?></label>
        <select class="cms-community-input" id="cc-forum" name="forum">
            <option value=""><?= $e(I18n::t('community.view.search.all_forums')) ?></option>
            <?php foreach ($forums as $id => $forum): ?>
                <option value="<?= (int) $id ?>"<?= (int) $id === (int) $forum_id ? ' selected' : '' ?>><?= $e(str_repeat('– ', (int) ($forum['depth'] ?? 0)) . ($locale === 'en' && !empty($forum['title_en']) ? $forum['title_en'] : $forum['title'])) ?></option>
            <?php endforeach; ?>
        </select> <button class="cms-community-button" type="submit"><?= $e(I18n::t('community.view.search.apply')) ?></button></p>
    </form>

    <?php if ($result === null): ?>
        <p class="cms-community__empty"><?= $e(I18n::t('community.view.search.hint')) ?></p>
    <?php elseif ($failed): ?>
        <?php
        $code = (string) ($result['error'] ?? '');
        $texts = ['query_too_short' => I18n::t('community.view.search.too_short'), 'rate_limited' => I18n::t('community.view.search.rate_limited'), 'login_required' => I18n::t('community.view.search.login')];
        ?>
        <p class="cms-community-alert" role="alert"><?= $e($texts[$code] ?? I18n::t('community.view.search.unavailable')) ?></p>
    <?php elseif (($result['source'] ?? '') === 'unavailable'): ?>
        <p class="cms-community__empty"><?= $e(I18n::t('community.view.search.guest_unavailable')) ?></p>
    <?php elseif ($hits === []): ?>
        <p class="cms-community__empty"><?= $e(I18n::t('community.view.search.no_hits', ['query' => $query])) ?></p>
    <?php else: ?>
        <p class="cms-community__lead"><?= $e(I18n::t(!empty($result['truncated']) ? 'community.view.search.found_truncated' : 'community.view.search.found', ['count' => (int) ($result['total'] ?? count($hits))])) ?></p>
        <ul class="cms-community-topics">
        <?php foreach ($hits as $hit): ?>
            <li class="cms-community-topic">
                <div class="cms-community-topic__main">
                    <a class="cms-community-topic__title" href="<?= $e(Urls::post((int) $hit['post_id'], $locale)) ?>"><?= $e($hit['title']) ?></a>
                    <?php if (!empty($hit['solved'])): ?><span class="cms-community-badge cms-community-badge--ok"><?= $e(I18n::t('community.view.solved')) ?></span><?php endif; ?>
                    <p class="cms-community-topic__meta"><?= $e($hit['excerpt']) ?></p>
                </div>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php if ($pages > 1): ?>
            <nav class="cms-community-pager" aria-label="<?= $e(I18n::t('community.view.pages')) ?>">
                <?php if ($page > 1): ?><a rel="prev" href="<?= $e($link($page - 1)) ?>"><?= $e(I18n::t('community.view.prev')) ?></a><?php endif; ?>
                <span><?= $e(I18n::t('community.view.page_of', ['page' => (int) $page, 'pages' => (int) $pages])) ?></span>
                <?php if ($page < $pages): ?><a rel="next" href="<?= $e($link($page + 1)) ?>"><?= $e(I18n::t('community.view.next')) ?></a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
