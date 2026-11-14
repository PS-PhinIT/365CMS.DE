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

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pluginErrorStatus = (int) ($pluginErrorStatus ?? 404) === 403 ? 403 : 404;
$pluginErrorTitle = $pluginErrorStatus === 403 ? 'Sicherheitsüberprüfung fehlgeschlagen' : 'Plugin-Bereich nicht gefunden';
$pluginErrorText = $pluginErrorStatus === 403
    ? 'Das Formular war abgelaufen oder wurde bereits abgeschickt. Bitte lade die Seite neu und versuche es erneut.'
    : 'Dieser Bereich wurde nicht registriert oder das Plugin ist aktuell nicht verfügbar.';

$pageTitle = $pluginErrorTitle;
$pageKey = 'plugin_error';
$pageAssets = [];

include __DIR__ . '/header.php';
?>
<div class="empty" role="<?= $pluginErrorStatus === 403 ? 'alert' : 'status' ?>">
    <div class="empty-img"><img src="<?= htmlspecialchars(cms_asset_url('images/plugin-not-found.svg', false), ENT_QUOTES, 'UTF-8') ?>" height="128" alt=""></div>
    <p class="empty-title"><?= htmlspecialchars($pluginErrorTitle, ENT_QUOTES, 'UTF-8') ?></p>
    <p class="empty-subtitle text-secondary"><?= htmlspecialchars($pluginErrorText, ENT_QUOTES, 'UTF-8') ?></p>
    <div class="empty-action">
        <a href="/member/dashboard" class="btn btn-primary">Zurück zum Dashboard</a>
    </div>
</div>
<?php include __DIR__ . '/footer.php';
