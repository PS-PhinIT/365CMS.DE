<?php
/**
 * Navigationsliste der Admin-Seitenleiste (Ausgabe von AdminNavigationService::build() bzw. present()).
 *
 * Erwartet `$navModel`. Optional `$navPreview = true` für die Vorschau im Einstellungs-Tab:
 * ohne Toggle-Attribute für sidebar.js; der Container der Vorschau ist `inert`.
 *
 * @package CMSv2\Admin\Partials
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$navPreview = !empty($navPreview);
$navGroups = is_array($navModel['groups'] ?? null) ? $navModel['groups'] : [];
$navEsc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$navIcon = static function (array $icon) use ($navEsc): string {
    return match ($icon['type'] ?? '') {
        'image' => '<img class="cms-nav__icon-img" src="' . $navEsc($icon['src'] ?? '') . '" alt="" width="20" height="20" loading="lazy" decoding="async">',
        'text' => '<span class="cms-nav__icon-text">' . $navEsc($icon['text'] ?? '') . '</span>',
        default => '<i class="ti ti-' . $navEsc($icon['name'] ?? \CMS\Services\AdminNavigationService::DEFAULT_PLUGIN_ICON) . '"></i>',
    };
};

$navLabel = static function (array $entry) use ($navEsc): string {
    if (($entry['prefix'] ?? '') !== '') {
        return '<span class="cms-nav__prefix">' . $navEsc($entry['prefix']) . ' |</span> ' . $navEsc($entry['name'] ?? $entry['label']);
    }

    return $navEsc($entry['label']);
};

$navBadge = static function (?array $badge) use ($navEsc): string {
    if ($badge === null || trim((string) ($badge['label'] ?? '')) === '') {
        return '';
    }

    return '<span class="cms-nav__badge"><span aria-hidden="true">' . $navEsc($badge['label']) . '</span>'
        . '<span class="visually-hidden">' . $navEsc($badge['sr'] ?? $badge['label']) . '</span></span>';
};

$navToggleAttr = static fn (string $id): string => $navPreview ? '' : ' data-cms-toggle="' . $navEsc($id) . '"';

$navRenderEntry = static function (array $navEntry) use ($navEsc, $navIcon, $navLabel, $navBadge, $navToggleAttr): void {
    $navItemClass = 'cms-nav__item'
        . (!empty($navEntry['plugin']) ? ' cms-nav__item--plugin' : '')
        . (!empty($navEntry['tiles']) ? ' cms-nav__item--tiles' : '');

    if ($navEntry['children'] === []) {
        $navAria = (string) ($navEntry['aria_current'] ?? (!empty($navEntry['current']) ? 'page' : ''));
        ?>
        <li class="<?= $navItemClass ?>">
            <a class="cms-nav__link<?= !empty($navEntry['current']) ? ' is-current' : '' ?>" href="<?= $navEsc($navEntry['url']) ?>"
               data-cms-tip="<?= $navEsc($navEntry['label']) ?>"<?= $navAria !== '' ? ' aria-current="' . $navEsc($navAria) . '"' : '' ?>>
                <span class="cms-nav__icon" aria-hidden="true"><?= $navIcon($navEntry['icon']) ?></span>
                <span class="cms-nav__label"><?= $navLabel($navEntry) ?></span>
                <?= $navBadge($navEntry['badge'] ?? null) ?>
            </a>
        </li>
        <?php
        return;
    }
    ?>
    <li class="<?= $navItemClass ?> cms-nav__item--parent<?= !empty($navEntry['active']) ? ' is-active' : '' ?>" data-cms-entry="<?= $navEsc($navEntry['id']) ?>">
        <button type="button" class="cms-nav__link cms-nav__toggle" id="<?= $navEsc($navEntry['dom_id']) ?>-toggle"
                aria-expanded="<?= !empty($navEntry['expanded']) ? 'true' : 'false' ?>"
                aria-controls="<?= $navEsc($navEntry['dom_id']) ?>"<?= $navToggleAttr((string) $navEntry['id']) ?>
                data-cms-tip="<?= $navEsc($navEntry['label']) ?>">
            <span class="cms-nav__icon" aria-hidden="true"><?= $navIcon($navEntry['icon']) ?></span>
            <span class="cms-nav__label"><?= $navLabel($navEntry) ?></span>
            <?= $navBadge($navEntry['badge'] ?? null) ?>
            <i class="ti ti-chevron-down cms-nav__chevron" aria-hidden="true"></i>
        </button>
        <div class="cms-collapse cms-nav__sub<?= !empty($navEntry['expanded']) ? '' : ' is-collapsed' ?>" id="<?= $navEsc($navEntry['dom_id']) ?>" data-cms-collapse="entry">
            <div class="cms-collapse__inner">
                <p class="cms-nav__flyout-title" aria-hidden="true"><?= $navEsc($navEntry['label']) ?></p>
                <ul class="cms-nav__sublist" aria-labelledby="<?= $navEsc($navEntry['dom_id']) ?>-toggle">
                    <?php foreach ($navEntry['children'] as $navChild): ?>
                        <li>
                            <a class="cms-nav__sublink<?= !empty($navChild['current']) ? ' is-current' : '' ?>" href="<?= $navEsc($navChild['url']) ?>"<?= !empty($navChild['current']) ? ' aria-current="page"' : '' ?>>
                                <span class="cms-nav__sublabel"><?= $navEsc($navChild['label']) ?></span>
                                <?= $navBadge($navChild['badge'] ?? null) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </li>
    <?php
};
?>
<ul class="cms-nav<?= $navPreview ? ' cms-nav--preview' : '' ?>">
    <?php foreach ($navGroups as $navGroup): ?>
        <?php if (!empty($navGroup['plain'])): ?>
            <li class="cms-nav__group cms-nav__group--plain<?= !empty($navGroup['active']) ? ' is-active' : '' ?>" data-cms-group="<?= $navEsc($navGroup['id']) ?>">
                <ul class="cms-nav__list" id="<?= $navEsc($navGroup['dom_id']) ?>">
                    <?php foreach ($navGroup['entries'] as $navEntry): ?>
                        <?php $navRenderEntry($navEntry); ?>
                    <?php endforeach; ?>
                    <?php if (is_array($navGroup['hint'] ?? null)): ?>
                        <li class="cms-nav__hint">
                            <p><?= $navEsc($navGroup['hint']['text']) ?></p>
                            <a href="<?= $navEsc($navGroup['hint']['url']) ?>"><?= $navEsc($navGroup['hint']['link']) ?></a>
                        </li>
                    <?php endif; ?>
                </ul>
            </li>
            <?php continue; ?>
        <?php endif; ?>
        <li class="cms-nav__group<?= !empty($navGroup['active']) ? ' is-active' : '' ?>" data-cms-group="<?= $navEsc($navGroup['id']) ?>">
            <button type="button" class="cms-nav__group-toggle" id="<?= $navEsc($navGroup['dom_id']) ?>-toggle"
                    aria-expanded="<?= !empty($navGroup['expanded']) ? 'true' : 'false' ?>"
                    aria-controls="<?= $navEsc($navGroup['dom_id']) ?>"<?= $navToggleAttr((string) $navGroup['id']) ?>>
                <span class="cms-nav__group-label"><?= $navEsc($navGroup['label']) ?></span>
                <i class="ti ti-chevron-down cms-nav__chevron" aria-hidden="true"></i>
            </button>
            <div class="cms-collapse<?= !empty($navGroup['expanded']) ? '' : ' is-collapsed' ?>" id="<?= $navEsc($navGroup['dom_id']) ?>" data-cms-collapse="group">
                <ul class="cms-nav__list cms-collapse__inner" aria-labelledby="<?= $navEsc($navGroup['dom_id']) ?>-toggle">
                    <?php foreach ($navGroup['entries'] as $navEntry): ?>
                        <?php $navRenderEntry($navEntry); ?>
                    <?php endforeach; ?>
                    <?php if (is_array($navGroup['hint'] ?? null)): ?>
                        <li class="cms-nav__hint">
                            <p><?= $navEsc($navGroup['hint']['text']) ?></p>
                            <a href="<?= $navEsc($navGroup['hint']['url']) ?>"><?= $navEsc($navGroup['hint']['link']) ?></a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
