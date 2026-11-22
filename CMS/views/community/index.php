<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Community – Startseite: Kategorien mit ihren lesbaren Foren.
 * Variablen: $groups (Kategorie-ID => ['category' => Zeile|null, 'forums' => Foren]), $locale, $viewer (Mitglieds-ID oder null), $e.
 */

use CMS\Services\Community\Support\Urls;

$title = static fn (array $row): string => $locale === 'en' && !empty($row['title_en']) ? (string) $row['title_en'] : (string) $row['title'];
$desc = static fn (array $row): string => $locale === 'en' && !empty($row['description_en']) ? (string) $row['description_en'] : (string) ($row['description'] ?? '');
?>
<div id="main" class="cms-community">
    <?php $active = 'index'; include __DIR__ . '/partials/nav.php'; ?>
    <h1>Community</h1>
    <?php if ($groups === []): ?>
        <p class="cms-community__empty">Noch keine Foren.</p>
    <?php endif; ?>
    <?php foreach ($groups as $group): ?>
        <section class="cms-community-category" aria-labelledby="cat-<?= (int) ($group['category']['id'] ?? 0) ?>">
            <?php if ($group['category'] !== null): ?>
                <h2 id="cat-<?= (int) $group['category']['id'] ?>"><?= $e($title($group['category'])) ?></h2>
            <?php endif; ?>
            <ul class="cms-community-forums">
            <?php foreach ($group['forums'] as $forum): $slug = Urls::forumSlugFor((string) $forum['slug'], $forum['slug_en'] ?? null, $locale); ?>
                <li class="cms-community-forum">
                    <div class="cms-community-forum__main">
                        <a class="cms-community-forum__title" href="<?= $e(Urls::forum($slug, $locale)) ?>"><?= $e(str_repeat('— ', (int) $forum['depth']) . $title($forum)) ?></a>
                        <?php if ($desc($forum) !== ''): ?><p class="cms-community-forum__desc"><?= $e($desc($forum)) ?></p><?php endif; ?>
                    </div>
                    <dl class="cms-community-forum__stats">
                        <div><dt>Themen</dt><dd><?= (int) ($forum['topic_count'] ?? 0) ?></dd></div>
                        <div><dt>Beiträge</dt><dd><?= (int) ($forum['post_count'] ?? 0) ?></dd></div>
                        <div><dt>Letzter Beitrag</dt><dd><?= !empty($forum['last_post_at']) ? '<time datetime="' . $e(str_replace(' ', 'T', (string) $forum['last_post_at'])) . 'Z">' . $e(\CMS\Services\Community\Support\I18n::date((string) $forum['last_post_at'], 'd.m.Y H:i')) . '</time>' : '–' ?></dd></div>
                    </dl>
                </li>
            <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</div>
