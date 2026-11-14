<?php
/**
 * Generischer Wrapper für Plugin-Bereiche (`/member/plugin/:slug`).
 *
 * Wird von PluginDashboardRegistry::handleRoute() eingebunden, nachdem POST-Anfragen bereits vor jeder Ausgabe
 * verarbeitet und per 303 weitergeleitet wurden (PRG). Hier wird nur noch dargestellt.
 *
 * @var array<string,mixed>  $section Registrierter Bereich
 * @var object               $user    Angemeldeter Benutzer
 * @var array<string,string> $params  Route-Parameter (`action`, `id`)
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';

$params = is_array($params ?? null) ? $params : [];
$pageTitle = (string)($section['label'] ?? 'Plugin-Bereich');
$pageKey = 'plugin_' . (string)($section['slug'] ?? 'section');
$pageAssets = [];

include __DIR__ . '/partials/header.php';
?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title"><?= htmlspecialchars((string)($section['label'] ?? 'Plugin-Bereich'), ENT_QUOTES, 'UTF-8') ?></h3>
        <span class="badge bg-primary-lt"><?= htmlspecialchars((string)($section['plugin'] ?? 'plugin'), ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="card-body">
        <?php \CMS\Hooks::doAction('member_plugin_section_head', $section, $user, $params); ?>
        <?php
        $pluginCallback = $section['render_callback'] ?? null;
        if (is_callable($pluginCallback)) {
            call_user_func($pluginCallback, $user, $params);
        }
        ?>
    </div>
</div>
<?php include __DIR__ . '/partials/footer.php';
