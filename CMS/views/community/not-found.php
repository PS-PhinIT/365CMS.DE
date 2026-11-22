<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Community – nicht gefunden (404, auch für nicht lesbare Foren und Themen).
 * Variablen: $e (Maskierung).
 */
?>
<div id="main" class="cms-community cms-community--empty">
    <h1><?= $e(\CMS\Services\Community\Support\I18n::t('community.view.not_found.heading')) ?></h1>
    <p><?= $e(\CMS\Services\Community\Support\I18n::t('community.view.not_found.text')) ?></p>
    <p><a href="<?= $e(\CMS\Services\Community\Support\Urls::index()) ?>"><?= $e(\CMS\Services\Community\Support\I18n::t('community.view.not_found.back')) ?></a></p>
</div>
