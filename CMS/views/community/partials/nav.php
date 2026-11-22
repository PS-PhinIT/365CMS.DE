<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Community – Navigation (Start, Listen, Suche). Variablen: $locale, $e; optional $query (Suchbegriff), $active (index|new|active|unanswered|search).
 */

use CMS\Services\Community\Support\Urls;

$ccActive = $active ?? '';
$ccLinks = [
    'index' => ['Start', Urls::index($locale)],
    'new' => ['Neu', Urls::listing('new', $locale)],
    'active' => ['Aktiv', Urls::listing('active', $locale)],
    'unanswered' => ['Unbeantwortet', Urls::listing('unanswered', $locale)],
];
?>
<nav class="cms-community-nav" aria-label="Community">
    <ul>
        <?php foreach ($ccLinks as $key => [$label, $url]): ?>
            <li><a href="<?= $e($url) ?>"<?= $ccActive === $key ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <form class="cms-community-nav__search" method="get" action="<?= $e(Urls::search($locale)) ?>" role="search">
        <label class="visually-hidden" for="cc-q">Forum durchsuchen</label>
        <input class="cms-community-input" id="cc-q" type="search" name="q" value="<?= $e($query ?? '') ?>" minlength="2" maxlength="100" placeholder="Forum durchsuchen">
        <button class="cms-community-button" type="submit">Suchen</button>
    </form>
</nav>
