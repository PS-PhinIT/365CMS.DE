<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Community – Navigation (Start, Listen, Suche). Variablen: $locale, $e; optional $query (Suchbegriff), $active (index|new|active|unanswered|search).
 */

use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Urls;

$ccActive = $active ?? '';
$ccLinks = [
    'index' => [I18n::t('community.view.nav.home'), Urls::index($locale)],
    'new' => [I18n::t('community.view.nav.new'), Urls::listing('new', $locale)],
    'active' => [I18n::t('community.view.nav.active'), Urls::listing('active', $locale)],
    'unanswered' => [I18n::t('community.view.nav.unanswered'), Urls::listing('unanswered', $locale)],
];
?>
<nav class="cms-community-nav" aria-label="Community">
    <ul>
        <?php foreach ($ccLinks as $key => [$label, $url]): ?>
            <li><a href="<?= $e($url) ?>"<?= $ccActive === $key ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <form class="cms-community-nav__search" method="get" action="<?= $e(Urls::search($locale)) ?>" role="search">
        <label class="visually-hidden" for="cc-q"><?= $e(I18n::t('community.view.nav.search_label')) ?></label>
        <input class="cms-community-input" id="cc-q" type="search" name="q" value="<?= $e($query ?? '') ?>" minlength="2" maxlength="100" placeholder="<?= $e(I18n::t('community.view.nav.search_label')) ?>">
        <button class="cms-community-button" type="submit"><?= $e(I18n::t('community.view.nav.search_submit')) ?></button>
    </form>
</nav>
