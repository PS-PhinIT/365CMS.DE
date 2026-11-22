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
    <h1>Nicht gefunden</h1>
    <p>Diese Seite gibt es nicht oder sie ist für dich nicht sichtbar.</p>
    <p><a href="<?= $e(\CMS\Services\Community\Support\Urls::index()) ?>">Zur Community-Startseite</a></p>
</div>
