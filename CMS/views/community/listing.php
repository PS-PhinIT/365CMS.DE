<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Community – globale Liste (Neu, Aktiv, Unbeantwortet) über alle lesbaren Foren.
 * Variablen: $kind, $heading, $topics, $forums (Forum-ID => Zeile), $accounts, $next (Cursor oder null), $locale, $viewer, $canonical, $flash, $e.
 */

use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Urls;

$active = $kind;
$name = static fn (?array $account): string => $account === null ? I18n::t('community.notify.deleted_member') : (string) ($account['display_name'] ?: $account['username']);
?>
<div id="main" class="cms-community">
    <?php if (!empty($flash)): ?><p class="cms-community-flash" role="status"><?= $e($flash) ?></p><?php endif; ?>
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <h1><?= $e($heading) ?></h1>
    <?php if ($topics === []): ?>
        <p class="cms-community__empty"><?= $e($kind === 'unanswered' ? I18n::t('community.view.all_answered') : I18n::t('community.view.no_topics')) ?></p>
    <?php else: ?>
        <ul class="cms-community-topics">
        <?php foreach ($topics as $topic): $forum = $forums[(int) $topic['forum_id']] ?? null; ?>
            <li class="cms-community-topic">
                <div class="cms-community-topic__main">
                    <a class="cms-community-topic__title" href="<?= $e(Urls::topic((int) $topic['id'], (string) ($topic['slug'] ?? ''), $locale)) ?>"><?= $e($topic['title']) ?></a>
                    <p class="cms-community-topic__meta">
                        <?php if (!empty($topic['solution_post_id'])): ?><span class="cms-community-badge cms-community-badge--ok"><?= $e(I18n::t('community.view.solved')) ?></span> <?php endif; ?>
                        <?php if ($forum !== null): ?>in <a href="<?= $e(Urls::forum(Urls::forumSlugFor((string) $forum['slug'], $forum['slug_en'] ?? null, $locale), $locale)) ?>"><?= $e($locale === 'en' && !empty($forum['title_en']) ? $forum['title_en'] : $forum['title']) ?></a> · <?php endif; ?>
                        <?= $e(I18n::t('community.view.by', ['name' => $name($accounts[(int) ($topic['user_id'] ?? 0)] ?? null)])) ?>
                    </p>
                </div>
                <dl class="cms-community-topic__stats">
                    <div><dt><?= $e(I18n::t('community.view.stat_replies')) ?></dt><dd><?= (int) ($topic['reply_count'] ?? 0) ?></dd></div>
                    <div><dt><?= $e($kind === 'active' ? I18n::t('community.view.activity') : I18n::t('community.view.created')) ?></dt><dd><?php $at = (string) ($kind === 'active' ? ($topic['last_post_at'] ?? '') : ($topic['created_at'] ?? '')); ?><?= $at !== '' ? '<time datetime="' . $e(str_replace(' ', 'T', $at)) . 'Z">' . $e(I18n::date($at, 'd.m.Y H:i')) . '</time>' : '–' ?></dd></div>
                </dl>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php if ($next !== null): ?>
            <p><a class="cms-community-button" rel="next" href="<?= $e($canonical . '?nach=' . rawurlencode((string) $next)) ?>"><?= $e(I18n::t('community.view.more_topics')) ?></a></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
