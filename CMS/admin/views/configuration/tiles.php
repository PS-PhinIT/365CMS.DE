<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * View: Kachelseite des Admin-Menüs (/admin/configuration[?group=<id>])
 *
 * @var array{
 *     id:string, label:string, icon:array<string, mixed>, route:string, badge:array{label:string,sr:string,count:int}|null,
 *     categories:list<array{id:string, label:string, tiles:list<array{id:string,label:string,description:string,url:string,icon:array<string,mixed>,badge:array{label:string,sr:string,count:int}|null,plugin:bool,external:bool}>}>
 * } $configurationPage
 */

$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$tileIcon = static function (array $icon) use ($esc): string {
    return match ($icon['type'] ?? '') {
        'image' => '<img class="cms-tile__icon-img" src="' . $esc($icon['src'] ?? '') . '" alt="" width="24" height="24" loading="lazy" decoding="async">',
        'text' => '<span class="cms-tile__icon-text">' . $esc($icon['text'] ?? '') . '</span>',
        default => '<i class="ti ti-' . $esc($icon['name'] ?? \CMS\Services\AdminNavigationService::DEFAULT_PLUGIN_ICON) . '"></i>',
    };
};
$tileBadge = static function (?array $badge) use ($esc): string {
    if ($badge === null || trim((string) ($badge['label'] ?? '')) === '') {
        return '';
    }

    return '<span class="cms-tile__badge"><span aria-hidden="true">' . $esc($badge['label']) . '</span>'
        . '<span class="visually-hidden">' . $esc($badge['sr'] ?? $badge['label']) . '</span></span>';
};

$tileCategories = [];
foreach ($configurationPage['categories'] as $tileIndex => $tileCategory) {
    $tileCategory['dom_id'] = 'cms-tiles-' . $tileIndex . '-' . trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($tileCategory['id'])), '-');
    $tileCategories[] = $tileCategory;
}
$tileTotal = array_sum(array_map(static fn (array $category): int => count($category['tiles']), $tileCategories));
?>

<div class="container-xl cms-configuration-page" data-tiles-group="<?= $esc($configurationPage['id']) ?>">
    <div class="page-header d-print-none mb-4">
        <div class="row align-items-center">
            <div class="col">
                <div class="page-pretitle">Übersicht · <?= $esc($tileTotal === 1 ? '1 Bereich' : $tileTotal . ' Bereiche') ?></div>
                <h2 class="page-title">
                    <span class="cms-configuration-page__icon me-2" aria-hidden="true"><?= $tileIcon($configurationPage['icon']) ?></span>
                    <?= $esc($configurationPage['label']) ?>
                    <?= $tileBadge($configurationPage['badge']) ?>
                </h2>
            </div>
        </div>
    </div>

    <?php if (count($tileCategories) > 1): ?>
        <nav class="cms-tiles-jump" aria-label="Kategorien dieser Seite">
            <ul class="cms-tiles-jump__list">
                <?php foreach ($tileCategories as $tileCategory): ?>
                    <li><a class="cms-tiles-jump__link" href="#<?= $esc($tileCategory['dom_id']) ?>"><?= $esc($tileCategory['label']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>

    <?php foreach ($tileCategories as $tileCategory): ?>
        <section class="cms-tiles" aria-labelledby="<?= $esc($tileCategory['dom_id']) ?>">
            <h3 class="cms-tiles__heading" id="<?= $esc($tileCategory['dom_id']) ?>"><?= $esc($tileCategory['label']) ?></h3>
            <ul class="cms-tiles__grid">
                <?php foreach ($tileCategory['tiles'] as $tile): ?>
                    <li class="cms-tiles__cell">
                        <a class="cms-tile<?= $tile['plugin'] ? ' cms-tile--plugin' : '' ?>" href="<?= $esc($tile['url']) ?>" data-tile-id="<?= $esc($tile['id']) ?>">
                            <span class="cms-tile__icon" aria-hidden="true"><?= $tileIcon($tile['icon']) ?></span>
                            <span class="cms-tile__body">
                                <span class="cms-tile__title"><?= $esc($tile['label']) ?></span>
                                <?php if ($tile['description'] !== ''): ?>
                                    <span class="cms-tile__text"><?= $esc($tile['description']) ?></span>
                                <?php endif; ?>
                            </span>
                            <?= $tileBadge($tile['badge']) ?>
                            <?php if ($tile['external']): ?>
                                <i class="ti ti-arrow-up-right cms-tile__external" aria-hidden="true"></i>
                                <span class="visually-hidden">(außerhalb der Administration)</span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</div>
