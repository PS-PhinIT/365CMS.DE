<?php
/**
 * Fehlerseite für Plugin-Bereiche (404 = nicht registriert/keine Berechtigung, 403 = Sicherheitsprüfung
 * fehlgeschlagen) – mit Kopf und Fuß des Mitgliederbereichs.
 *
 * Eingebunden von PluginDashboardRegistry::renderError(); der Statuscode ist bereits gesetzt.
 * Im Theme überschreibbar als `<theme>/member/partials/plugin-not-found.php`.
 *
 * @var int $pluginErrorStatus 403 oder 404
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';

$pluginErrorStatus = (int) ($pluginErrorStatus ?? 404) === 403 ? 403 : 404;
$pluginErrorTitle = $t($pluginErrorStatus === 403 ? 'member.plugin.forbidden_title' : 'member.plugin.not_found_title');
$pluginErrorText = $t($pluginErrorStatus === 403 ? 'member.plugin.forbidden_text' : 'member.plugin.not_found_text');

$pageTitle = $pluginErrorTitle;
$pageKey = 'plugin_error';
$pageAssets = [];

include __DIR__ . '/header.php';
?>
<div class="empty" role="<?= $pluginErrorStatus === 403 ? 'alert' : 'status' ?>">
    <div class="empty-img"><img src="<?= htmlspecialchars(cms_asset_url('images/plugin-not-found.svg', false), ENT_QUOTES, 'UTF-8') ?>" height="128" alt=""></div>
    <p class="empty-title"><?= $e($pluginErrorTitle) ?></p>
    <p class="empty-subtitle text-secondary"><?= $e($pluginErrorText) ?></p>
    <div class="empty-action">
        <a href="<?= $e($controller->getHomePath()) ?>" class="btn btn-primary"><?= $e($t('member.plugin.back_to_dashboard')) ?></a>
    </div>
</div>
<?php include __DIR__ . '/footer.php';
