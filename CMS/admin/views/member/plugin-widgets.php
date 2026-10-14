<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_MEMBER_VIEW')) {
    exit;
}

$pageAssets = $pageAssets ?? [];
$pageAssets['js'] = is_array($pageAssets['js'] ?? null) ? $pageAssets['js'] : [];
$pageAssets['js'][] = cms_asset_url('js/admin-member-dashboard.js');

$settings = $data['settings'] ?? [];
$pluginWidgets = $data['pluginWidgets'] ?? [];
$order = $settings['plugin_widget_order'] ?? [];

if ($order !== []) {
    usort($pluginWidgets, static function (array $a, array $b) use ($order): int {
        $aPos = array_search($a['plugin'], $order, true);
        $bPos = array_search($b['plugin'], $order, true);
        $aPos = $aPos === false ? 999 : (int)$aPos;
        $bPos = $bPos === false ? 999 : (int)$bPos;
        return $aPos <=> $bPos;
    });
}
?>
<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Mitglieder &amp; Zugriff</div>
                <h2 class="page-title">Member Dashboard – Plugin-Widgets</h2>
                <div class="text-muted mt-1">Sortiere Plugin-Kacheln per Drag &amp; Drop und steuere ihre Sichtbarkeit im Frontend-Dashboard.</div>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>

        <?php require __DIR__ . '/subnav.php'; ?>

        <form method="post" id="pluginWidgetForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="settings_section" value="plugin-widgets">
            <input type="hidden" name="plugin_widget_order" id="plugin_widget_order" value="<?php echo htmlspecialchars(implode(',', $order)); ?>">

            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h3 class="card-title">Reihenfolge &amp; Sichtbarkeit</h3>
                    <button type="submit" class="btn btn-primary btn-sm">Plugin-Widgets speichern</button>
                </div>
                <div class="card-body">
                    <?php if ($pluginWidgets === []): ?>
                        <div class="text-muted">Aktuell sind keine registrierten Plugin-Widgets vorhanden.</div>
                    <?php else: ?>
                        <p class="text-secondary small mb-3">Sortiere per Drag &amp; Drop oder über die Pfeilbuttons. Die gespeicherte Reihenfolge wird serverseitig allowlist-basiert normalisiert.</p>
                        <div class="dashboard-sortable-list d-flex flex-column gap-3" id="pluginWidgetList" data-member-sortable-list="1" data-order-input="plugin_widget_order" aria-live="polite">
                            <?php foreach ($pluginWidgets as $widget):
                                $pluginSlug = (string)($widget['plugin'] ?? '');
                                $visible = ($settings['member_dashboard_plugin_' . $pluginSlug] ?? '1') === '1';
                                $supportsFrontendWidget = !empty($widget['supports_frontend_widget']);
                            ?>
                                <div class="dashboard-sortable-item card card-sm border" data-sort-key="<?php echo htmlspecialchars($pluginSlug, ENT_QUOTES); ?>">
                                    <div class="card-body">
                                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                                            <div class="d-flex align-items-start gap-3">
                                                <span class="badge bg-secondary-lt dashboard-sortable-handle mt-1" data-sort-handle="1" aria-hidden="true">⇅</span>
                                                <div style="width:44px;height:44px;border-radius:2px;background:<?php echo htmlspecialchars((string)($widget['color'] ?? '#4f46e5')); ?>20;display:flex;align-items:center;justify-content:center;font-size:1.25rem;">
                                                    <?php echo htmlspecialchars((string)($widget['icon'] ?? '🔌')); ?>
                                                </div>
                                                <div class="flex-fill">
                                                    <div class="fw-semibold"><?php echo htmlspecialchars((string)($widget['label'] ?? $pluginSlug)); ?></div>
                                                    <div class="text-muted small"><?php echo htmlspecialchars((string)($widget['description'] ?? '')); ?></div>
                                                    <?php if (!empty($widget['admin_note'])): ?>
                                                        <div class="text-azure small mt-1"><?php echo htmlspecialchars((string)($widget['admin_note'] ?? '')); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="dashboard-sortable-actions d-flex gap-2" aria-label="Reihenfolge für <?php echo htmlspecialchars((string)($widget['label'] ?? $pluginSlug), ENT_QUOTES); ?> anpassen">
                                                <button type="button" class="btn btn-outline-secondary btn-sm" data-sort-move="up" aria-label="<?php echo htmlspecialchars((string)($widget['label'] ?? $pluginSlug), ENT_QUOTES); ?> nach oben">↑</button>
                                                <button type="button" class="btn btn-outline-secondary btn-sm" data-sort-move="down" aria-label="<?php echo htmlspecialchars((string)($widget['label'] ?? $pluginSlug), ENT_QUOTES); ?> nach unten">↓</button>
                                            </div>
                                        </div>

                                        <div class="row g-2 mb-3">
                                            <div class="col-sm-8">
                                                <label for="plugin-widget-<?php echo htmlspecialchars($pluginSlug, ENT_QUOTES); ?>-title" class="form-label small mb-1">Titel</label>
                                                <input id="plugin-widget-<?php echo htmlspecialchars($pluginSlug, ENT_QUOTES); ?>-title" type="text" class="form-control form-control-sm" name="plugin_meta[<?php echo htmlspecialchars($pluginSlug); ?>][title]" maxlength="120" value="<?php echo htmlspecialchars((string)($widget['label'] ?? '')); ?>">
                                            </div>
                                            <div class="col-sm-4">
                                                <label for="plugin-widget-<?php echo htmlspecialchars($pluginSlug, ENT_QUOTES); ?>-icon" class="form-label small mb-1">Icon</label>
                                                <input id="plugin-widget-<?php echo htmlspecialchars($pluginSlug, ENT_QUOTES); ?>-icon" type="text" class="form-control form-control-sm" name="plugin_meta[<?php echo htmlspecialchars($pluginSlug); ?>][icon]" maxlength="16" value="<?php echo htmlspecialchars((string)($widget['icon'] ?? '🔌')); ?>">
                                            </div>
                                            <div class="col-12">
                                                <label for="plugin-widget-<?php echo htmlspecialchars($pluginSlug, ENT_QUOTES); ?>-description" class="form-label small mb-1">Beschreibung</label>
                                                <textarea id="plugin-widget-<?php echo htmlspecialchars($pluginSlug, ENT_QUOTES); ?>-description" class="form-control form-control-sm" name="plugin_meta[<?php echo htmlspecialchars($pluginSlug); ?>][description]" rows="3" maxlength="255"><?php echo htmlspecialchars((string)($widget['description'] ?? '')); ?></textarea>
                                            </div>
                                            <div class="col-sm-5">
                                                <label for="plugin-widget-<?php echo htmlspecialchars($pluginSlug, ENT_QUOTES); ?>-color" class="form-label small mb-1">Akzentfarbe</label>
                                                <input id="plugin-widget-<?php echo htmlspecialchars($pluginSlug, ENT_QUOTES); ?>-color" type="color" class="form-control form-control-color form-control-sm" name="plugin_meta[<?php echo htmlspecialchars($pluginSlug); ?>][color]" value="<?php echo htmlspecialchars((string)($widget['color'] ?? '#4f46e5')); ?>">
                                            </div>
                                        </div>

                                        <?php if ($supportsFrontendWidget): ?>
                                            <label class="form-check form-switch mb-0">
                                                <input type="checkbox" class="form-check-input" name="plugin_visible[<?php echo htmlspecialchars($pluginSlug); ?>]" value="1" <?php echo $visible ? 'checked' : ''; ?>>
                                                <span class="form-check-label">Im Frontend anzeigen</span>
                                            </label>
                                        <?php else: ?>
                                            <div class="text-muted small">Diese Konfiguration steuert die Darstellung in Theme-/Plugin-spezifischen Member-Bereichen.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>
