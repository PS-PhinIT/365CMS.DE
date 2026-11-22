<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Community – öffentliches Profil eines Mitglieds (`ProfileService::profile()`): Name, Zähler, Rang, Signatur, jüngste Themen aus lesbaren Foren.
 * Variablen: $profile (id, name, stats, rank, signature_html – bereits maskiert –, topics), $locale, $viewer, $e.
 */

use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Urls;

$active = 'member';
$stats = $profile['stats'];
?>
<div id="main" class="cms-community">
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <h1><?= $e($profile['name']) ?></h1>
    <?php if (!empty($profile['rank'])): ?><p class="cms-community-topic__meta"><span class="cms-community-badge"><?= $e($profile['rank']['title']) ?></span></p><?php endif; ?>
    <dl class="cms-community-topic__stats">
        <div><dt><?= $e(I18n::t('community.member.topics')) ?></dt><dd><?= (int) $stats['topic_count'] ?></dd></div>
        <div><dt><?= $e(I18n::t('community.member.posts')) ?></dt><dd><?= (int) $stats['post_count'] ?></dd></div>
        <div><dt><?= $e(I18n::t('community.member.reactions')) ?></dt><dd><?= (int) $stats['reactions_received'] ?></dd></div>
        <div><dt><?= $e(I18n::t('community.member.solutions')) ?></dt><dd><?= (int) $stats['solutions_count'] ?></dd></div>
        <div><dt><?= $e(I18n::t('community.member.trust_level')) ?></dt><dd><?= (int) $stats['trust_level'] ?></dd></div>
        <div><dt><?= $e(I18n::t('community.member.last_post')) ?></dt><dd><?= !empty($stats['last_post_at']) ? '<time datetime="' . $e(str_replace(' ', 'T', (string) $stats['last_post_at'])) . 'Z">' . $e(I18n::date((string) $stats['last_post_at'], 'd.m.Y H:i')) . '</time>' : '–' ?></dd></div>
    </dl>
    <?php if ($profile['signature_html'] !== ''): ?>
        <h2><?= $e(I18n::t('community.member.signature')) ?></h2>
        <div class="cms-community-post__signature"><?= (string) $profile['signature_html'] ?></div>
    <?php endif; ?>
    <h2><?= $e(I18n::t('community.member.recent_topics')) ?></h2>
    <?php if ($profile['topics'] === []): ?>
        <p class="cms-community__empty"><?= $e(I18n::t('community.member.no_topics')) ?></p>
    <?php else: ?>
        <ul class="cms-community-topics">
        <?php foreach ($profile['topics'] as $topic): ?>
            <li class="cms-community-topic">
                <div class="cms-community-topic__main">
                    <a class="cms-community-topic__title" href="<?= $e(Urls::topic((int) $topic['id'], (string) ($topic['slug'] ?? ''), $locale)) ?>"><?= $e($topic['title']) ?></a>
                    <p class="cms-community-topic__meta"><time datetime="<?= $e(str_replace(' ', 'T', (string) $topic['created_at'])) ?>Z"><?= $e(I18n::date((string) $topic['created_at'], 'd.m.Y H:i')) ?></time> · <?= (int) ($topic['reply_count'] ?? 0) ?> <?= $e(I18n::t('community.member.replies')) ?></p>
                </div>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
