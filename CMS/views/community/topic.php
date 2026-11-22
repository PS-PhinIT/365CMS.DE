<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Community – Thema mit Beiträgen. Der HTML-Text eines Beitrags (`html`) kommt aus `RenderService::present()` und ist bereits gereinigt.
 * Variablen: $api (JSON-API oder null), $reactions, $subscribed, $can_moderate, $can_solve, $can_reply, $editable (Beitrags-ID => true: bearbeitbar), $flash (Hinweis eines Schreibvorgangs oder null), $topic, $forum, $posts, $accounts (Mitglieds-ID => Konto), $page, $pages, $locale, $viewer, $canonical, $e.
 */

use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Urls;

$name = static function (?array $account): string {
    return $account === null ? 'Gelöschtes Mitglied' : (string) ($account['display_name'] ?: $account['username']);
};
// Namen der Reaktionen für Screenreader und Sprachsteuerung (aria-label der Schaltfläche: Name, Zähler; WCAG 2.5.3 „Name enthält sichtbaren Text“)
$reactionNames = ['like' => 'Gefällt mir', 'love' => 'Liebe', 'laugh' => 'Lustig', 'idea' => 'Gute Idee', 'thanks' => 'Danke', 'wow' => 'Beeindruckend', 'sad' => 'Traurig', 'celebrate' => 'Glückwunsch'];
$forumSlug = $forum !== null ? Urls::forumSlugFor((string) $forum['slug'], $forum['slug_en'] ?? null, $locale) : '';
$pageUrl = static fn (int $n): string => Urls::topic((int) $topic['id'], (string) ($topic['slug'] ?? ''), $locale, $n);
?>
<div id="main" class="cms-community"<?php if (!empty($api)): ?> data-cc-api="<?= $e($api['url']) ?>" data-cc-token="<?= $e($api['token']) ?>" data-cc-reasons="<?= $e($api['reasons']) ?>" data-cc-reason-required="<?= !empty($api['reason_required']) ? '1' : '0' ?>"<?php endif; ?>>
    <p id="cc-status" class="cms-community-flash" hidden></p>
    <?php if (!empty($flash)): ?><p class="cms-community-flash" role="status"><?= $e($flash) ?></p><?php endif; ?>
    <nav aria-label="Brotkrumen" class="cms-community-crumbs">
        <a href="<?= $e(Urls::index($locale)) ?>">Community</a>
        <?php if ($forum !== null): ?> › <a href="<?= $e(Urls::forum($forumSlug, $locale)) ?>"><?= $e($locale === 'en' && !empty($forum['title_en']) ? $forum['title_en'] : $forum['title']) ?></a><?php endif; ?>
    </nav>
    <h1><?= $e($topic['title']) ?></h1>
    <p class="cms-community-topic__meta">
        <?php if (!empty($topic['is_locked'])): ?><span class="cms-community-badge">geschlossen</span> <?php endif; ?>
        <?php if (!empty($topic['solution_post_id'])): ?><span class="cms-community-badge cms-community-badge--ok">gelöst</span> <?php endif; ?>
        <?= (int) $topic['reply_count'] ?> Antworten
    </p>

    <?php if (!empty($api)): ?>
        <div class="cms-community-toolbar" role="group" aria-label="Thema">
            <button type="button" data-cc-action="subscribe" data-type="topic" data-id="<?= (int) $topic['id'] ?>" data-label-on="Abo beenden" data-label-off="Thema abonnieren" aria-pressed="<?= !empty($subscribed) ? 'true' : 'false' ?>"><?= !empty($subscribed) ? 'Abo beenden' : 'Thema abonnieren' ?></button>
            <?php if (!empty($can_moderate)): ?>
                <?php foreach ((!empty($topic['is_locked']) ? ['topic.unlock' => 'Öffnen'] : ['topic.lock' => 'Schließen']) + (!empty($topic['is_pinned']) ? ['topic.unpin' => 'Lösen'] : ['topic.pin' => 'Anheften']) + ['topic.hide' => 'Ausblenden'] as $op => $label): ?>
                    <button type="button" data-cc-action="moderate" data-op="<?= $e($op) ?>" data-id="<?= (int) $topic['id'] ?>"><?= $e($label) ?></button>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($can_solve) && !empty($topic['solution_post_id'])): ?>
                <button type="button" data-cc-action="solution" data-topic-id="<?= (int) $topic['id'] ?>">Lösung aufheben</button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="cms-community-posts">
    <?php foreach ($posts as $post): $postId = (int) $post['id']; ?>
        <?php if (($post['view'] ?? 'full') === 'placeholder'): ?>
            <article class="cms-community-post cms-community-post--placeholder" id="post-<?= $postId ?>">
                <p><?= ($post['status'] ?? '') === 'deleted' ? 'Dieser Beitrag wurde gelöscht.' : 'Dieser Beitrag wurde ausgeblendet.' ?></p>
            </article>
            <?php continue; ?>
        <?php endif; ?>
        <article class="cms-community-post" id="post-<?= $postId ?>" aria-labelledby="post-<?= $postId ?>-author">
            <header class="cms-community-post__head">
                <strong id="post-<?= $postId ?>-author"><?= $e($name($accounts[(int) ($post['user_id'] ?? 0)] ?? null)) ?></strong>
                <a class="cms-community-post__date" href="<?= $e(Urls::post($postId, $locale)) ?>"><time datetime="<?= $e(str_replace(' ', 'T', (string) $post['created_at'])) ?>Z"><?= $e(I18n::date((string) $post['created_at'], 'd.m.Y H:i')) ?></time></a>
                <?php if (!empty($post['edited_at'])): ?><span class="cms-community-post__edited">bearbeitet</span><?php endif; ?>
                <?php if (($post['status'] ?? '') === 'pending'): ?><span class="cms-community-badge">wartet auf Freigabe</span><?php endif; ?>
                <?php if ((int) ($topic['solution_post_id'] ?? 0) === $postId): ?><span class="cms-community-badge cms-community-badge--ok">Lösung</span><?php endif; ?>
            </header>
            <div class="cms-community-post__body"><?= (string) ($post['html'] ?? '') ?></div>
            <?php if (!empty($api) && !empty($reactions['available'])): $mine = $reactions['summaries'][$postId]['mine'] ?? null; $counts = $reactions['summaries'][$postId]['counts'] ?? []; ?>
                <div class="cms-community-reactions" data-cc-reactions role="group" aria-label="Reaktionen">
                    <?php foreach ($reactions['available'] as $key): ?>
                        <button type="button" data-cc-action="react" data-post-id="<?= $postId ?>" data-reaction="<?= $e($key) ?>" aria-pressed="<?= ($mine === $key) ? 'true' : 'false' ?>" aria-label="<?= $e(($reactionNames[$key] ?? $key) . ', ' . (int) ($counts[$key] ?? 0)) ?>"><span aria-hidden="true"><?= $e($reactions['emoji'][$key] ?? $key) ?></span> <?= (int) ($counts[$key] ?? 0) ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($can_reply) || isset($editable[$postId]) || !empty($api)): ?>
                <footer class="cms-community-post__actions">
                    <?php if (!empty($can_reply)): ?><a href="<?= $e(Urls::reply((int) $topic['id'], $locale, $postId)) ?>">Zitieren</a><?php endif; ?>
                    <?php if (isset($editable[$postId])): ?><a href="<?= $e(Urls::edit($postId, $locale)) ?>">Bearbeiten</a><?php endif; ?>
                    <?php if (!empty($api) && (int) ($post['user_id'] ?? 0) !== (int) $viewer): ?><button type="button" class="cms-community-link" data-cc-action="report" data-type="post" data-id="<?= $postId ?>">Melden</button><?php endif; ?>
                    <?php if (!empty($api) && !empty($can_solve) && (int) ($topic['solution_post_id'] ?? 0) !== $postId && empty($post['is_first_post'])): ?><button type="button" class="cms-community-link" data-cc-action="solution" data-topic-id="<?= (int) $topic['id'] ?>" data-post-id="<?= $postId ?>">Als Lösung markieren</button><?php endif; ?>
                    <?php if (!empty($api) && !empty($can_moderate)): ?><button type="button" class="cms-community-link" data-cc-action="moderate" data-op="<?= ($post['status'] ?? '') === 'hidden' ? 'post.restore' : 'post.hide' ?>" data-id="<?= $postId ?>"><?= ($post['status'] ?? '') === 'hidden' ? 'Einblenden' : 'Ausblenden' ?></button><?php endif; ?>
                </footer>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>

    <?php if (!empty($can_reply)): ?>
        <p class="cms-community-actions"><a class="cms-community-button" href="<?= $e(Urls::reply((int) $topic['id'], $locale)) ?>">Antworten</a></p>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
        <nav class="cms-community-pager" aria-label="Seiten">
            <?php if ($page > 1): ?><a rel="prev" href="<?= $e($pageUrl($page - 1)) ?>">Zurück</a><?php endif; ?>
            <span>Seite <?= (int) $page ?> von <?= (int) $pages ?></span>
            <?php if ($page < $pages): ?><a rel="next" href="<?= $e($pageUrl($page + 1)) ?>">Weiter</a><?php endif; ?>
        </nav>
    <?php endif; ?>
    <?php if (!empty($api)): ?>
        <dialog id="cc-dialog" class="cms-community-dialog">
            <form method="dialog">
                <h2 data-cc-title></h2>
                <div data-cc-fields></div>
                <menu><button value="cancel" formnovalidate>Abbrechen</button><button value="ok">Senden</button></menu>
            </form>
        </dialog>
    <?php endif; ?>
</div>
