<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Community – Forum: Unterforen und Themenliste (Keyset: „Weiter“ über ?nach=…).
 * Variablen: $forum, $children, $topics (Themenzeilen), $accounts (Mitglieds-ID => Konto), $next (Cursor oder null), $locale, $viewer, $canonical, $e.
 */

use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Urls;

$ftitle = $locale === 'en' && !empty($forum['title_en']) ? (string) $forum['title_en'] : (string) $forum['title'];
$name = static function (?array $account): string {
    return $account === null ? 'Gelöschtes Mitglied' : (string) ($account['display_name'] ?: $account['username']);
};
?>
<main id="main" class="cms-community">
    <nav aria-label="Brotkrumen" class="cms-community-crumbs"><a href="<?= $e(Urls::index($locale)) ?>">Community</a> › <span aria-current="page"><?= $e($ftitle) ?></span></nav>
    <h1><?= $e($ftitle) ?></h1>
    <?php if (!empty($forum['description'])): ?><p class="cms-community__lead"><?= $e($forum['description']) ?></p><?php endif; ?>

    <?php if ($children !== []): ?>
        <h2 class="visually-hidden">Unterforen</h2>
        <ul class="cms-community-forums">
        <?php foreach ($children as $child): $slug = Urls::forumSlugFor((string) $child['slug'], $child['slug_en'] ?? null, $locale); ?>
            <li class="cms-community-forum"><a class="cms-community-forum__title" href="<?= $e(Urls::forum($slug, $locale)) ?>"><?= $e($locale === 'en' && !empty($child['title_en']) ? $child['title_en'] : $child['title']) ?></a></li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($topics === []): ?>
        <p class="cms-community__empty">In diesem Forum gibt es noch keine Themen.</p>
    <?php else: ?>
        <ul class="cms-community-topics">
        <?php foreach ($topics as $topic): ?>
            <li class="cms-community-topic<?= !empty($topic['is_pinned']) ? ' is-pinned' : '' ?>">
                <div class="cms-community-topic__main">
                    <a class="cms-community-topic__title" href="<?= $e(Urls::topic((int) $topic['id'], (string) ($topic['slug'] ?? ''), $locale)) ?>"><?= $e($topic['title']) ?></a>
                    <p class="cms-community-topic__meta">
                        <?php if (!empty($topic['is_pinned'])): ?><span class="cms-community-badge">angeheftet</span> <?php endif; ?>
                        <?php if (!empty($topic['is_locked'])): ?><span class="cms-community-badge">geschlossen</span> <?php endif; ?>
                        <?php if (!empty($topic['solution_post_id'])): ?><span class="cms-community-badge cms-community-badge--ok">gelöst</span> <?php endif; ?>
                        von <?= $e($name($accounts[(int) ($topic['user_id'] ?? 0)] ?? null)) ?>
                    </p>
                </div>
                <dl class="cms-community-topic__stats">
                    <div><dt>Antworten</dt><dd><?= (int) ($topic['reply_count'] ?? 0) ?></dd></div>
                    <div><dt>Aktivität</dt><dd><?= !empty($topic['last_post_at']) ? '<time datetime="' . $e(str_replace(' ', 'T', (string) $topic['last_post_at'])) . 'Z">' . $e(I18n::date((string) $topic['last_post_at'], 'd.m.Y H:i')) . '</time>' : '–' ?></dd></div>
                </dl>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php if ($next !== null): ?>
            <p><a class="cms-community-button" rel="next" href="<?= $e($canonical . '?nach=' . rawurlencode((string) $next)) ?>">Weitere Themen</a></p>
        <?php endif; ?>
    <?php endif; ?>
</main>
