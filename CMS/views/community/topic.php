<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Community – Thema mit Beiträgen. Der HTML-Text eines Beitrags (`html`) kommt aus `RenderService::present()` und ist bereits gereinigt.
 * Variablen: $api (JSON-API oder null), $reactions, $subscribed, $can_moderate, $can_solve, $can_reply, $editable (Beitrags-ID => true: bearbeitbar), $flash (Hinweis eines Schreibvorgangs oder null), $topic, $forum, $posts, $accounts (Mitglieds-ID => Konto), $signatures (Mitglieds-ID => Signatur-HTML aus `ProfileService::signatureHtml()`, bereits maskiert), $page, $pages, $locale, $viewer, $canonical, $e.
 */

use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Urls;

$name = static function (?array $account): string {
    return $account === null ? I18n::t('community.notify.deleted_member') : (string) ($account['display_name'] ?: $account['username']);
};
// Namen der Reaktionen für Screenreader und Sprachsteuerung (aria-label der Schaltfläche: Name, Zähler; WCAG 2.5.3 „Name enthält sichtbaren Text“)
$reactionNames = [];
foreach (['like', 'love', 'laugh', 'idea', 'thanks', 'wow', 'sad', 'celebrate'] as $reactionKey) {
    $reactionNames[$reactionKey] = I18n::t('community.view.reaction.' . $reactionKey);
}
$forumSlug = $forum !== null ? Urls::forumSlugFor((string) $forum['slug'], $forum['slug_en'] ?? null, $locale) : '';
$pageUrl = static fn (int $n): string => Urls::topic((int) $topic['id'], (string) ($topic['slug'] ?? ''), $locale, $n);
?>
<div id="main" class="cms-community"<?php if (!empty($api)): ?> data-cc-api="<?= $e($api['url']) ?>" data-cc-token="<?= $e($api['token']) ?>" data-cc-messages="<?= $e($api['messages'] ?? '{}') ?>" data-cc-reasons="<?= $e($api['reasons']) ?>" data-cc-reason-required="<?= !empty($api['reason_required']) ? '1' : '0' ?>"<?php endif; ?>>
    <p id="cc-status" class="cms-community-flash" hidden></p>
    <?php if (!empty($flash)): ?><p class="cms-community-flash" role="status"><?= $e($flash) ?></p><?php endif; ?>
    <nav aria-label="<?= $e(I18n::t('community.view.crumbs')) ?>" class="cms-community-crumbs">
        <a href="<?= $e(Urls::index($locale)) ?>">Community</a>
        <?php if ($forum !== null): ?> › <a href="<?= $e(Urls::forum($forumSlug, $locale)) ?>"><?= $e($locale === 'en' && !empty($forum['title_en']) ? $forum['title_en'] : $forum['title']) ?></a><?php endif; ?>
    </nav>
    <h1><?= $e($topic['title']) ?></h1>
    <p class="cms-community-topic__meta">
        <?php if (!empty($topic['is_locked'])): ?><span class="cms-community-badge"><?= $e(I18n::t('community.view.locked')) ?></span> <?php endif; ?>
        <?php if (!empty($topic['solution_post_id'])): ?><span class="cms-community-badge cms-community-badge--ok"><?= $e(I18n::t('community.view.solved')) ?></span> <?php endif; ?>
        <?= $e(I18n::t('community.view.reply_count', ['count' => (int) $topic['reply_count']])) ?>
    </p>

    <?php if (!empty($api)): ?>
        <div class="cms-community-toolbar" role="group" aria-label="<?= $e(I18n::t('community.view.topic')) ?>">
            <button type="button" data-cc-action="subscribe" data-type="topic" data-id="<?= (int) $topic['id'] ?>" data-label-on="<?= $e(I18n::t('community.view.unsubscribe')) ?>" data-label-off="<?= $e(I18n::t('community.view.subscribe')) ?>" aria-pressed="<?= !empty($subscribed) ? 'true' : 'false' ?>"><?= $e(!empty($subscribed) ? I18n::t('community.view.unsubscribe') : I18n::t('community.view.subscribe')) ?></button>
            <?php if (!empty($can_moderate)): ?>
                <?php foreach ((!empty($topic['is_locked']) ? ['topic.unlock' => I18n::t('community.view.mod.unlock')] : ['topic.lock' => I18n::t('community.view.mod.lock')]) + (!empty($topic['is_pinned']) ? ['topic.unpin' => I18n::t('community.view.mod.unpin')] : ['topic.pin' => I18n::t('community.view.mod.pin')]) + ['topic.hide' => I18n::t('community.view.mod.hide')] as $op => $label): ?>
                    <button type="button" data-cc-action="moderate" data-op="<?= $e($op) ?>" data-id="<?= (int) $topic['id'] ?>"><?= $e($label) ?></button>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($can_solve) && !empty($topic['solution_post_id'])): ?>
                <button type="button" data-cc-action="solution" data-topic-id="<?= (int) $topic['id'] ?>"><?= $e(I18n::t('community.view.unsolve')) ?></button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="cms-community-posts">
    <?php foreach ($posts as $post): $postId = (int) $post['id']; ?>
        <?php if (($post['view'] ?? 'full') === 'placeholder'): ?>
            <article class="cms-community-post cms-community-post--placeholder" id="post-<?= $postId ?>">
                <p><?= $e(($post['status'] ?? '') === 'deleted' ? I18n::t('community.view.post_deleted') : I18n::t('community.view.post_hidden')) ?></p>
            </article>
            <?php continue; ?>
        <?php endif; ?>
        <article class="cms-community-post" id="post-<?= $postId ?>" aria-labelledby="post-<?= $postId ?>-author">
            <header class="cms-community-post__head">
                <?php $authorId = (int) ($post['user_id'] ?? 0); ?>
                <strong id="post-<?= $postId ?>-author"><?php if (isset($accounts[$authorId])): ?><a href="<?= $e(Urls::member($authorId, $locale)) ?>"><?= $e($name($accounts[$authorId])) ?></a><?php else: ?><?= $e($name(null)) ?><?php endif; ?></strong>
                <a class="cms-community-post__date" href="<?= $e(Urls::post($postId, $locale)) ?>"><time datetime="<?= $e(str_replace(' ', 'T', (string) $post['created_at'])) ?>Z"><?= $e(I18n::date((string) $post['created_at'], 'd.m.Y H:i')) ?></time></a>
                <?php if (!empty($post['edited_at'])): ?><span class="cms-community-post__edited"><?= $e(I18n::t('community.view.edited')) ?></span><?php endif; ?>
                <?php if (($post['status'] ?? '') === 'pending'): ?><span class="cms-community-badge"><?= $e(I18n::t('community.view.pending')) ?></span><?php endif; ?>
                <?php if ((int) ($topic['solution_post_id'] ?? 0) === $postId): ?><span class="cms-community-badge cms-community-badge--ok"><?= $e(I18n::t('community.view.solution')) ?></span><?php endif; ?>
            </header>
            <?php if (!empty($post['deleted_at'])): ?>
            <div class="cms-community-post__body"><p><em><?= $e(I18n::t('community.view.erased')) ?></em></p></div>
            <?php else: ?>
            <div class="cms-community-post__body"><?= (string) ($post['html'] ?? '') ?></div>
            <?php endif; ?>
            <?php if (!empty($signatures[$authorId])): ?><div class="cms-community-post__signature"><?= (string) $signatures[$authorId] ?></div><?php endif; ?>
            <?php if (!empty($api) && !empty($reactions['available'])): $mine = $reactions['summaries'][$postId]['mine'] ?? null; $counts = $reactions['summaries'][$postId]['counts'] ?? []; ?>
                <div class="cms-community-reactions" data-cc-reactions role="group" aria-label="<?= $e(I18n::t('community.view.reactions')) ?>">
                    <?php foreach ($reactions['available'] as $key): ?>
                        <button type="button" data-cc-action="react" data-post-id="<?= $postId ?>" data-reaction="<?= $e($key) ?>" aria-pressed="<?= ($mine === $key) ? 'true' : 'false' ?>" aria-label="<?= $e(($reactionNames[$key] ?? $key) . ', ' . (int) ($counts[$key] ?? 0)) ?>"><span aria-hidden="true"><?= $e($reactions['emoji'][$key] ?? $key) ?></span> <?= (int) ($counts[$key] ?? 0) ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($can_reply) || isset($editable[$postId]) || !empty($api)): ?>
                <footer class="cms-community-post__actions">
                    <?php if (!empty($can_reply)): ?><a href="<?= $e(Urls::reply((int) $topic['id'], $locale, $postId)) ?>"><?= $e(I18n::t('community.view.quote')) ?></a><?php endif; ?>
                    <?php if (isset($editable[$postId])): ?><a href="<?= $e(Urls::edit($postId, $locale)) ?>"><?= $e(I18n::t('community.view.edit')) ?></a><?php endif; ?>
                    <?php if (!empty($api) && (int) ($post['user_id'] ?? 0) !== (int) $viewer): ?><button type="button" class="cms-community-link" data-cc-action="report" data-type="post" data-id="<?= $postId ?>"><?= $e(I18n::t('community.view.report')) ?></button><?php endif; ?>
                    <?php if (!empty($api) && !empty($can_solve) && (int) ($topic['solution_post_id'] ?? 0) !== $postId && empty($post['is_first_post'])): ?><button type="button" class="cms-community-link" data-cc-action="solution" data-topic-id="<?= (int) $topic['id'] ?>" data-post-id="<?= $postId ?>"><?= $e(I18n::t('community.view.mark_solution')) ?></button><?php endif; ?>
                    <?php if (!empty($api) && !empty($can_moderate)): ?><button type="button" class="cms-community-link" data-cc-action="moderate" data-op="<?= ($post['status'] ?? '') === 'hidden' ? 'post.restore' : 'post.hide' ?>" data-id="<?= $postId ?>"><?= $e(($post['status'] ?? '') === 'hidden' ? I18n::t('community.view.mod.show') : I18n::t('community.view.mod.hide')) ?></button><?php endif; ?>
                </footer>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>

    <?php if (!empty($can_reply)): ?>
        <p class="cms-community-actions"><a class="cms-community-button" href="<?= $e(Urls::reply((int) $topic['id'], $locale)) ?>"><?= $e(I18n::t('community.view.reply')) ?></a></p>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
        <nav class="cms-community-pager" aria-label="<?= $e(I18n::t('community.view.pages')) ?>">
            <?php if ($page > 1): ?><a rel="prev" href="<?= $e($pageUrl($page - 1)) ?>"><?= $e(I18n::t('community.view.prev')) ?></a><?php endif; ?>
            <span><?= $e(I18n::t('community.view.page_of', ['page' => (int) $page, 'pages' => (int) $pages])) ?></span>
            <?php if ($page < $pages): ?><a rel="next" href="<?= $e($pageUrl($page + 1)) ?>"><?= $e(I18n::t('community.view.next')) ?></a><?php endif; ?>
        </nav>
    <?php endif; ?>
    <?php if (!empty($api)): ?>
        <dialog id="cc-dialog" class="cms-community-dialog">
            <form method="dialog">
                <h2 data-cc-title></h2>
                <div data-cc-fields></div>
                <menu><button value="cancel" formnovalidate><?= $e(I18n::t('community.view.cancel')) ?></button><button value="ok"><?= $e(I18n::t('community.view.send')) ?></button></menu>
            </form>
        </dialog>
    <?php endif; ?>
</div>
