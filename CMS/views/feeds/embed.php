<?php
/**
 * Feed-Aggregator – schlanke Seite für die Einbettung per iframe (/<archiv-slug>/embed).
 *
 * Ohne Theme-Kopf und -Fuß; die Kopf-Hooks laden Stylesheet, Design-Token und Einwilligungsbanner.
 * Theme-Override: themes/<slug>/feeds/embed.php
 *
 * @var string $content fertig gerenderte Übersicht bzw. Einwilligungshinweis
 * @var string $title
 * @var string $lang
 */

declare(strict_types=1);

use CMS\Hooks;

if (!defined('ABSPATH')) {
    exit;
}

$fdSiteName = defined('SITE_NAME') ? (string) SITE_NAME : '365CMS';
?><!DOCTYPE html>
<html lang="<?php echo esc_attr($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($title . ' – ' . $fdSiteName); ?></title>
    <?php Hooks::doAction('head'); ?>
</head>
<body class="fd-body">
<?php Hooks::doAction('body_start'); ?>
<?php echo $content; ?>
<?php Hooks::doAction('body_end'); ?>
</body>
</html>
