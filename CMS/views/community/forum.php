<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Community – Forum: Unterforen und Themenliste (Keyset: „Weiter“ über ?nach=…).
 * Variablen: $can_create, $new_topic_url (Schaltfläche „Neues Thema“), $flash (Hinweis eines Schreibvorgangs oder null), $forum, $children, $topics (Themenzeilen), $accounts (Mitglieds-ID => Konto), $next (Cursor oder null), $locale, $viewer, $canonical, $e.
 */

use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Urls;

$ftitle = $locale === 'en' && !empty($forum['title_en']) ? (string) $forum['title_en'] : (string) $forum['title'];
$name = static function (?array $account): string {
    return $account === null ? I18n::t('community.notify.deleted_member') : (string) ($account['display_name'] ?: $account['username']);
};
?>
<div id="main" class="cms-community"<?php if (!empty($api)): ?> data-cc-api="<?= $e($api['url']) ?>" data-cc-token="<?= $e($api['token']) ?>" data-cc-messages="<?= $e($api['messages'] ?? '{}') ?>"<?php endif; ?>>
    <?php if (!empty($api)): ?><p id="cc-status" class="cms-community-flash" hidden></p><?php endif; ?>
    <?php if (!empty($flash)): ?><p class="cms-community-flash" role="status"><?= $e($flash) ?></p><?php endif; ?>
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <nav aria-label="<?= $e(I18n::t('community.view.crumbs')) ?>" class="cms-community-crumbs"><a href="<?= $e(Urls::index($locale)) ?>">Community</a> › <span aria-current="page"><?= $e($ftitle) ?></span></nav>
    <h1><?= $e($ftitle) ?></h1>
    <?php if (!empty($forum['description'])): ?><p class="cms-community__lead"><?= $e($forum['description']) ?></p><?php endif; ?>

    <?php if (!empty($can_create) || !empty($api)): ?>
        <p class="cms-community-actions">
            <?php if (!empty($can_create)): ?><a class="cms-community-button" href="<?= $e($new_topic_url) ?>"><?= $e(I18n::t('community.compose.new_heading')) ?></a><?php endif; ?>
            <?php if (!empty($api)): ?><button type="button" data-cc-action="mark-read" data-forum-id="<?= (int) $forum['id'] ?>" data-done="<?= $e(I18n::t('community.read.marked_forum')) ?>"><?= $e(I18n::t('community.read.mark_forum')) ?></button><?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if ($children !== []): ?>
        <h2 class="visually-hidden"><?= $e(I18n::t('community.view.subforums')) ?></h2>
        <ul class="cms-community-forums">
        <?php foreach ($children as $child): $slug = Urls::forumSlugFor((string) $child['slug'], $child['slug_en'] ?? null, $locale); ?>
            <li class="cms-community-forum"><a class="cms-community-forum__title" href="<?= $e(Urls::forum($slug, $locale)) ?>"><?= $e($locale === 'en' && !empty($child['title_en']) ? $child['title_en'] : $child['title']) ?></a></li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($topics === []): ?>
        <p class="cms-community__empty"><?= $e(I18n::t('community.view.forum_empty')) ?></p>
    <?php else: ?>
        <ul class="cms-community-topics">
        <?php foreach ($topics as $topic): ?>
            <li class="cms-community-topic<?= !empty($topic['is_pinned']) ? ' is-pinned' : '' ?>">
                <div class="cms-community-topic__main">
                    <a class="cms-community-topic__title" href="<?= $e(Urls::topic((int) $topic['id'], (string) ($topic['slug'] ?? ''), $locale)) ?>"><?= $e($topic['title']) ?></a>
                    <p class="cms-community-topic__meta">
                        <?php if (!empty($topic['is_pinned'])): ?><span class="cms-community-badge"><?= $e(I18n::t('community.view.pinned')) ?></span> <?php endif; ?>
                        <?php if (!empty($topic['is_locked'])): ?><span class="cms-community-badge"><?= $e(I18n::t('community.view.locked')) ?></span> <?php endif; ?>
                        <?php if (!empty($topic['solution_post_id'])): ?><span class="cms-community-badge cms-community-badge--ok"><?= $e(I18n::t('community.view.solved')) ?></span> <?php endif; ?>
                        <?= $e(I18n::t('community.view.by', ['name' => $name($accounts[(int) ($topic['user_id'] ?? 0)] ?? null)])) ?>
                    </p>
                </div>
                <dl class="cms-community-topic__stats">
                    <div><dt><?= $e(I18n::t('community.view.stat_replies')) ?></dt><dd><?= (int) ($topic['reply_count'] ?? 0) ?></dd></div>
                    <div><dt><?= $e(I18n::t('community.view.activity')) ?></dt><dd><?= !empty($topic['last_post_at']) ? '<time datetime="' . $e(str_replace(' ', 'T', (string) $topic['last_post_at'])) . 'Z">' . $e(I18n::date((string) $topic['last_post_at'], 'd.m.Y H:i')) . '</time>' : '–' ?></dd></div>
                </dl>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php if ($next !== null): ?>
            <p><a class="cms-community-button" rel="next" href="<?= $e($canonical . '?nach=' . rawurlencode((string) $next)) ?>"><?= $e(I18n::t('community.view.more_topics')) ?></a></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
