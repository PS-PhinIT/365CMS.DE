<?php
declare(strict_types=1);

/**
 * Dashboard View – Admin-Startseite
 *
 * Erwartet:
 *   $data['kpis']       – array  KPI-Karten
 *   $data['activity']   – array  Letzte Aktivitäten
 *   $data['quickLinks'] – array  Schnellzugriffe
 *   $data['alerts']     – array  Hinweise/Warnungen
 *   $data['orders']     – array  Bestell-Statistiken
 *   $data['system']     – array  System-Infos
 *
 * @package CMSv2\Admin\Views
 */

if (!defined('ABSPATH')) {
    exit;
}
$welcome = is_array($data['welcome'] ?? null) ? $data['welcome'] : [];
$dashboardSections = is_array($data['dashboard_sections'] ?? null) ? $data['dashboard_sections'] : [];
$workOverviewWidgetDefinitions = is_array($data['dashboard_work_overview_widget_definitions'] ?? null) ? $data['dashboard_work_overview_widget_definitions'] : [];
$workOverviewWidgets = is_array($data['work_overview_widgets'] ?? null) ? $data['work_overview_widgets'] : [];
$favoriteShortcutDefinitions = is_array($data['favorite_shortcut_definitions'] ?? null) ? $data['favorite_shortcut_definitions'] : [];
$favoriteShortcuts = is_array($data['favorite_shortcuts'] ?? null) ? $data['favorite_shortcuts'] : [];
$dashboardPreferences = is_array($data['dashboard_preferences'] ?? null) ? $data['dashboard_preferences'] : [];
$visibleDashboardSections = is_array($dashboardPreferences['visible_sections'] ?? null) ? array_values($dashboardPreferences['visible_sections']) : array_keys($dashboardSections);
$visibleWorkOverviewWidgets = is_array($dashboardPreferences['visible_work_overview_widgets'] ?? null)
    ? array_values($dashboardPreferences['visible_work_overview_widgets'])
    : array_keys($workOverviewWidgetDefinitions);
$workOverviewWidgetOrder = is_array($dashboardPreferences['work_overview_widget_order'] ?? null)
    ? array_values($dashboardPreferences['work_overview_widget_order'])
    : array_keys($workOverviewWidgetDefinitions);
$selectedFavoriteShortcuts = is_array($dashboardPreferences['favorite_shortcuts'] ?? null)
    ? array_values($dashboardPreferences['favorite_shortcuts'])
    : array_keys($favoriteShortcutDefinitions);
$favoriteShortcutOrder = is_array($dashboardPreferences['favorite_shortcut_order'] ?? null)
    ? array_values($dashboardPreferences['favorite_shortcut_order'])
    : array_keys($favoriteShortcutDefinitions);
$usesRoleTemplate = !empty($dashboardPreferences['uses_role_template']);
$hasSavedDashboardPreferences = !empty($dashboardPreferences['has_saved_preferences']);
$roleTemplate = is_array($dashboardPreferences['role_template'] ?? null) ? $dashboardPreferences['role_template'] : [];
$activityEntries = is_array($data['activity'] ?? null) ? $data['activity'] : [];
$attentionItems = is_array($data['attention'] ?? null) ? $data['attention'] : [];
$recentOrders = is_array($data['recent_orders'] ?? null) ? $data['recent_orders'] : [];
$security = is_array($data['security'] ?? null) ? $data['security'] : [];
$performance = is_array($data['performance'] ?? null) ? $data['performance'] : [];
$system = is_array($data['system'] ?? null) ? $data['system'] : [];
$highlights = is_array($data['highlights'] ?? null) ? $data['highlights'] : [];
$failedLoginsCount = max(0, (int) ($security['failed_logins_24h'] ?? 0));
$hasFailedLogins = $failedLoginsCount > 0;
$subscriptionEnabled = (bool) ($data['subscription_enabled'] ?? true);
$quickLinks = is_array($data['quickLinks'] ?? null) ? $data['quickLinks'] : [];
$dashboardAlerts = array_values(array_filter(
    is_array($data['alerts'] ?? null) ? $data['alerts'] : [],
    static function ($alert): bool {
        if (!is_array($alert)) {
            return false;
        }

        return in_array((string) ($alert['type'] ?? ''), ['warning', 'danger'], true);
    }
));
$headerQuickLinks = array_values(array_filter($quickLinks, static function (array $link): bool {
    return !in_array((string) ($link['label'] ?? ''), ['Neue Seite', 'Neuer Beitrag'], true);
}));

/** Tabler-Icon-SVG als Inline-Helfer */
if (!function_exists('dashIcon')) {
    function dashIcon(string $name): string {
        $icons = [
            'users'         => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"/><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M21 21v-2a4 4 0 0 0 -3 -3.85"/>',
            'file-text'     => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M9 9l1 0"/><path d="M9 13l6 0"/><path d="M9 17l6 0"/>',
            'article'       => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M16 4h2a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h2"/><path d="M9 4h6v4h-6z"/><path d="M8 12h8"/><path d="M8 16h5"/>',
            'photo'         => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M15 8h.01"/><path d="M3 6a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v12a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3v-12z"/><path d="M3 16l5 -5c.928 -.893 2.072 -.893 3 0l5 5"/><path d="M14 14l1 -1c.928 -.893 2.072 -.893 3 0l3 3"/>',
            'currency-euro' => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M17.2 7a6 7 0 1 0 0 10"/><path d="M13 10h-8m0 4h8"/>',
            'file-plus'     => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M12 11l0 6"/><path d="M9 14l6 0"/>',
            'pencil-plus'   => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 20h4l10.5 -10.5a2.828 2.828 0 1 0 -4 -4l-10.5 10.5v4"/><path d="M13.5 6.5l4 4"/>',
            'upload'        => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path d="M7 9l5 -5l5 5"/><path d="M12 4l0 12"/>',
            'settings'      => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"/><path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0"/>',
            'activity'      => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12h4l3 8l4 -16l3 8h4"/>',
            'alert-triangle' => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4"/><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z"/><path d="M12 16h.01"/>',
            'message-circle' => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 7a4 4 0 0 1 4 -4h10a4 4 0 0 1 4 4v6a4 4 0 0 1 -4 4h-8l-4 4v-4a4 4 0 0 1 -4 -4z"/>',
            'shield-check'  => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3l7 4v5c0 5 -3.5 7.5 -7 9c-3.5 -1.5 -7 -4 -7 -9v-5l7 -4"/><path d="M9 12l2 2l4 -4"/>',
            'server'        => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 4m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z"/><path d="M3 14m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v2a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z"/><path d="M7 8l0 .01"/><path d="M7 17l0 .01"/>',
            'shopping-cart' => '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 19a1 1 0 0 0 1 1h14a1 1 0 0 0 0 -2h-14a1 1 0 0 0 -1 1z"/><path d="M6 17l1.2 -7h11.6l1.2 7"/><path d="M6 10l-1 -5h-2"/><path d="M9 21a1 1 0 1 0 0 -2a1 1 0 0 0 0 2z"/><path d="M17 21a1 1 0 1 0 0 -2a1 1 0 0 0 0 2z"/>',
        ];
        $path = $icons[$name] ?? $icons['activity'];
        return '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
    }
}

if (!function_exists('dashboardStatusBadge')) {
    function dashboardStatusBadge(string $status): string {
        return match ($status) {
            'good' => 'success',
            'warning' => 'warning',
            'critical' => 'danger',
            default => 'secondary',
        };
    }
}

if (!function_exists('dashboardUrl')) {
    function dashboardUrl(string $url, string $fallback = '/admin'): string {
        $normalized = trim($url);

        if ($normalized === '') {
            $normalized = $fallback;
        }

        if ($normalized[0] !== '/' || str_starts_with($normalized, '//') || preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1) {
            $normalized = $fallback;
        }

        // Interne Pfade relativ zur Installationsbasis auflösen (Unterverzeichnis-Installationen).
        $siteUrl = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';

        return $siteUrl . $normalized;
    }
}

if (!function_exists('dashboardSectionVisible')) {
    function dashboardSectionVisible(array $visibleSections, string $section): bool {
        return in_array($section, $visibleSections, true);
    }
}

if (!function_exists('dashboardWorkOverviewWidgetVisible')) {
    function dashboardWorkOverviewWidgetVisible(array $visibleWidgets, string $widget): bool {
        return in_array($widget, $visibleWidgets, true);
    }
}

$activeWorkOverviewWidgets = [];
foreach ($workOverviewWidgets as $widgetKey => $widget) {
    if (dashboardWorkOverviewWidgetVisible($visibleWorkOverviewWidgets, (string) $widgetKey)) {
        $activeWorkOverviewWidgets[(string) $widgetKey] = is_array($widget) ? $widget : [];
    }
}
?>

<!-- Page Header -->
<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <h2 class="page-title dashboard-page-title mb-0">Dashboard</h2>
                <div class="text-secondary dashboard-header-line">
                    <?php echo htmlspecialchars((string) ($welcome['greeting'] ?? 'Willkommen')); ?>,
                    <?php echo htmlspecialchars((string) ($welcome['display_name'] ?? 'im Admin')); ?> ·
                    <?php echo htmlspecialchars((string) ($welcome['date_label'] ?? date('d.m.Y'))); ?> ·
                    <?php echo htmlspecialchars((string) ($welcome['time_label'] ?? date('H:i'))); ?> Uhr
                </div>
            </div>
            <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                    <?php foreach ($headerQuickLinks as $link): ?>
                        <a href="<?= htmlspecialchars(dashboardUrl((string) ($link['url'] ?? ''), '/admin')) ?>" class="btn btn-outline-secondary d-none d-xl-inline-flex">
                            <?= dashIcon((string) ($link['icon'] ?? 'settings')) ?>
                            <?= htmlspecialchars((string) ($link['label'] ?? 'Aktion')) ?>
                        </a>
                    <?php endforeach; ?>
                    <a href="<?= htmlspecialchars(dashboardUrl('/admin/pages?action=new')) ?>" class="btn btn-primary d-none d-sm-inline-block">
                        <?= dashIcon('file-plus') ?>
                        Neue Seite
                    </a>
                    <a href="<?= htmlspecialchars(dashboardUrl('/admin/posts?action=new')) ?>" class="btn btn-outline-primary d-none d-sm-inline-block">
                        <?= dashIcon('pencil-plus') ?>
                        Neuer Beitrag
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Page Body -->
<div class="page-body">
    <div class="container-xl">

        <?php
        // ─── Warnungen / Hinweise ─────────────────────────────────
        if ($dashboardAlerts !== []):
            foreach ($dashboardAlerts as $alert): ?>
                <div class="alert alert-<?= htmlspecialchars((string) ($alert['type'] ?? 'info')) ?> alert-dismissible" role="alert">
                    <div class="d-flex">
                        <div><?= dashIcon('alert-triangle') ?></div>
                        <div class="ms-2">
                            <?= htmlspecialchars((string) ($alert['message'] ?? 'Hinweis')) ?>
                            <?php if (!empty($alert['url'])): ?>
                                <a href="<?= htmlspecialchars(dashboardUrl((string) ($alert['url'] ?? ''), '/admin')) ?>" class="alert-link ms-1">Anzeigen →</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></a>
                </div>
            <?php endforeach;
        endif;
        ?>

        <?php if ($highlights !== []): ?>
        <section class="dashboard-section dashboard-section--kpi">
        <div class="card">
            <div class="card-header">
                <div class="dashboard-section-row">
                    <div class="dashboard-section-label mb-0">Kernkennzahlen</div>
                    <?php if ($dashboardSections !== []): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#dashboardPersonalizePanel" aria-expanded="false" aria-controls="dashboardPersonalizePanel">Dashboard personalisieren</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="dashboard-primary-metrics">
                    <?php foreach ($highlights as $highlight): ?>
                        <?php
                        $highlightValue = trim((string) ($highlight['value'] ?? '0'));
                        $isZeroHighlight = preg_match('/^\s*0(?:[.,]0+)?\s*$/', $highlightValue) === 1;
                        ?>
                        <a href="<?= htmlspecialchars(dashboardUrl((string) ($highlight['url'] ?? ''), '/admin')) ?>" class="dashboard-primary-metric text-reset text-decoration-none">
                            <div class="dashboard-primary-metric-head">
                                <span class="dashboard-primary-metric-label"><?= htmlspecialchars((string) ($highlight['label'] ?? 'Kennzahl')) ?></span>
                                <span class="dashboard-primary-metric-icon"><?= dashIcon((string) ($highlight['icon'] ?? 'activity')) ?></span>
                            </div>
                            <div class="dashboard-primary-metric-value<?= $isZeroHighlight ? ' is-zero' : ' is-positive' ?>"><?= htmlspecialchars($highlightValue === '' ? '0' : $highlightValue) ?></div>
                            <?php if ($isZeroHighlight): ?>
                                <p class="dashboard-primary-metric-empty mb-0"><?= htmlspecialchars((string) ($highlight['empty_hint'] ?? 'Keine neuen Eintraege')) ?></p>
                            <?php endif; ?>
                            <p class="dashboard-primary-metric-hint mb-0"><?= htmlspecialchars((string) ($highlight['hint'] ?? '')) ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        </section>
        <?php endif; ?>

        <?php if ($dashboardSections !== []): ?>
            <div class="collapse dashboard-personalize-collapse mb-3" id="dashboardPersonalizePanel">
            <div class="card">
                <form method="post" class="card-body" aria-describedby="dashboard-preferences-help">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <p id="dashboard-preferences-help" class="text-secondary small mb-3">
                        Kritische Alerts bleiben immer sichtbar. Ausgeblendete Bereiche können hier jederzeit wieder aktiviert werden.
                    </p>
                    <?php if ($roleTemplate !== []): ?>
                        <div class="dashboard-template-callout mb-3" role="status" aria-live="polite">
                            <div>
                                <div class="dashboard-template-title">Aktive Rollen-Vorlage: <?php echo htmlspecialchars((string) ($roleTemplate['label'] ?? 'Standard'), ENT_QUOTES, 'UTF-8'); ?></div>
                                <p class="dashboard-template-copy mb-0">
                                    <?php echo htmlspecialchars((string) ($roleTemplate['description'] ?? 'Die Standardansicht orientiert sich an deiner Rolle und bleibt durch persönliche Anpassungen überschreibbar.'), ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <div class="dashboard-template-meta text-secondary small mt-2">
                                    Basisrolle: <strong><?php echo htmlspecialchars((string) ($roleTemplate['role_label'] ?? 'Administrator'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if (empty($roleTemplate['exact_match'])): ?>
                                        · abgeleitet über vorhandene Capabilities
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="dashboard-template-actions">
                                <?php if ($usesRoleTemplate): ?>
                                    <span class="badge bg-success-lt">Standard aktiv</span>
                                <?php else: ?>
                                    <span class="badge bg-warning-lt">Persönlich angepasst</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="dashboard-preferences-grid" aria-live="polite">
                        <?php foreach ($dashboardSections as $sectionKey => $sectionDefinition): ?>
                            <?php
                            $sectionKey = (string) $sectionKey;
                            $isRequired = !empty($sectionDefinition['required']);
                            $inputId = 'dashboard-section-' . preg_replace('/[^a-z0-9_-]+/i', '-', $sectionKey);
                            ?>
                            <label class="form-check" for="<?php echo htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8'); ?>">
                                <input class="form-check-input" type="checkbox" id="<?php echo htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8'); ?>" name="dashboard_sections[]" value="<?php echo htmlspecialchars($sectionKey, ENT_QUOTES, 'UTF-8'); ?>"<?php echo dashboardSectionVisible($visibleDashboardSections, $sectionKey) ? ' checked' : ''; ?><?php echo $isRequired ? ' disabled' : ''; ?>>
                                <?php if ($isRequired): ?>
                                    <input type="hidden" name="dashboard_sections[]" value="<?php echo htmlspecialchars($sectionKey, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php endif; ?>
                                <span class="form-check-label fw-semibold"><?php echo htmlspecialchars((string) ($sectionDefinition['label'] ?? $sectionKey), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="d-block small text-secondary"><?php echo htmlspecialchars((string) ($sectionDefinition['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($workOverviewWidgetDefinitions !== []): ?>
                        <div class="border-top mt-4 pt-4">
                            <input type="hidden" name="work_overview_widget_order" id="dashboard-work-widget-order" value="<?php echo htmlspecialchars(implode(',', array_map('strval', $workOverviewWidgetOrder)), ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="dashboard-preferences-group-title">
                                <div>
                                    <h3 class="card-title mb-1">Widgets in „Zentrale Arbeitsübersicht“</h3>
                                    <p class="text-secondary small mb-0">Hier steuerst du einzelne Infokarten innerhalb der Hauptübersicht – unabhängig von den übrigen Dashboard-Bereichen.</p>
                                </div>
                                <span class="badge bg-primary-lt"><?php echo count($visibleWorkOverviewWidgets); ?> aktiv</span>
                            </div>
                            <p class="text-secondary small mb-3">Sortiere die Karten per Drag &amp; Drop oder über die Pfeilbuttons. Die Reihenfolge wirkt direkt in deiner Arbeitsübersicht.</p>
                            <div class="dashboard-sortable-list" data-dashboard-sortable-list="1" data-order-input="dashboard-work-widget-order" aria-live="polite">
                                <?php foreach ($workOverviewWidgetDefinitions as $widgetKey => $widgetDefinition): ?>
                                    <?php $widgetInputId = 'dashboard-work-widget-' . preg_replace('/[^a-z0-9_-]+/i', '-', (string) $widgetKey); ?>
                                    <div class="dashboard-sortable-item" data-sort-key="<?php echo htmlspecialchars((string) $widgetKey, ENT_QUOTES, 'UTF-8'); ?>" draggable="true">
                                        <div class="dashboard-sortable-main">
                                            <span class="badge bg-secondary-lt dashboard-sortable-handle" aria-hidden="true">⇅</span>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="<?php echo htmlspecialchars($widgetInputId, ENT_QUOTES, 'UTF-8'); ?>" name="work_overview_widgets[]" value="<?php echo htmlspecialchars((string) $widgetKey, ENT_QUOTES, 'UTF-8'); ?>"<?php echo dashboardWorkOverviewWidgetVisible($visibleWorkOverviewWidgets, (string) $widgetKey) ? ' checked' : ''; ?>>
                                                <label class="form-check-label fw-semibold" for="<?php echo htmlspecialchars($widgetInputId, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) ($widgetDefinition['label'] ?? $widgetKey), ENT_QUOTES, 'UTF-8'); ?></label>
                                                <span class="dashboard-sortable-meta small text-secondary"><?php echo htmlspecialchars((string) ($widgetDefinition['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                        </div>
                                        <div class="dashboard-sortable-actions" aria-label="Reihenfolge für <?php echo htmlspecialchars((string) ($widgetDefinition['label'] ?? $widgetKey), ENT_QUOTES, 'UTF-8'); ?> anpassen">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-sort-move="up" title="Nach oben verschieben" aria-label="<?php echo htmlspecialchars((string) ($widgetDefinition['label'] ?? $widgetKey), ENT_QUOTES, 'UTF-8'); ?> nach oben verschieben">↑</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-sort-move="down" title="Nach unten verschieben" aria-label="<?php echo htmlspecialchars((string) ($widgetDefinition['label'] ?? $widgetKey), ENT_QUOTES, 'UTF-8'); ?> nach unten verschieben">↓</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($favoriteShortcutDefinitions !== []): ?>
                        <div class="border-top mt-4 pt-4">
                            <input type="hidden" name="favorite_shortcut_order" id="dashboard-favorite-shortcut-order" value="<?php echo htmlspecialchars(implode(',', array_map('strval', $favoriteShortcutOrder)), ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="dashboard-preferences-group-title">
                                <div>
                                    <h3 class="card-title mb-1">Favoriten-Schnellzugriffe</h3>
                                    <p class="text-secondary small mb-0">Lege fest, welche Admin-Ziele im Favoritenbereich deines Dashboards direkt als Buttons erscheinen.</p>
                                </div>
                                <span class="badge bg-primary-lt"><?php echo count($selectedFavoriteShortcuts); ?> gewählt</span>
                            </div>
                            <p class="text-secondary small mb-3">Die obersten aktivierten Einträge bestimmen die spätere Button-Reihenfolge im Favoritenbereich.</p>
                            <div class="dashboard-sortable-list" data-dashboard-sortable-list="1" data-order-input="dashboard-favorite-shortcut-order" aria-live="polite">
                                <?php foreach ($favoriteShortcutDefinitions as $shortcutKey => $shortcutDefinition): ?>
                                    <?php $shortcutInputId = 'dashboard-favorite-shortcut-' . preg_replace('/[^a-z0-9_-]+/i', '-', (string) $shortcutKey); ?>
                                    <div class="dashboard-sortable-item" data-sort-key="<?php echo htmlspecialchars((string) $shortcutKey, ENT_QUOTES, 'UTF-8'); ?>" draggable="true">
                                        <div class="dashboard-sortable-main">
                                            <span class="badge bg-secondary-lt dashboard-sortable-handle" aria-hidden="true">⇅</span>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="<?php echo htmlspecialchars($shortcutInputId, ENT_QUOTES, 'UTF-8'); ?>" name="favorite_shortcuts[]" value="<?php echo htmlspecialchars((string) $shortcutKey, ENT_QUOTES, 'UTF-8'); ?>"<?php echo in_array((string) $shortcutKey, $selectedFavoriteShortcuts, true) ? ' checked' : ''; ?>>
                                                <label class="form-check-label fw-semibold" for="<?php echo htmlspecialchars($shortcutInputId, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) ($shortcutDefinition['label'] ?? $shortcutKey), ENT_QUOTES, 'UTF-8'); ?></label>
                                                <span class="dashboard-sortable-meta small text-secondary"><?php echo htmlspecialchars((string) ($shortcutDefinition['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                        </div>
                                        <div class="dashboard-sortable-actions" aria-label="Reihenfolge für <?php echo htmlspecialchars((string) ($shortcutDefinition['label'] ?? $shortcutKey), ENT_QUOTES, 'UTF-8'); ?> anpassen">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-sort-move="up" title="Nach oben verschieben" aria-label="<?php echo htmlspecialchars((string) ($shortcutDefinition['label'] ?? $shortcutKey), ENT_QUOTES, 'UTF-8'); ?> nach oben verschieben">↑</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-sort-move="down" title="Nach unten verschieben" aria-label="<?php echo htmlspecialchars((string) ($shortcutDefinition['label'] ?? $shortcutKey), ENT_QUOTES, 'UTF-8'); ?> nach unten verschieben">↓</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="mt-3 d-flex gap-2 flex-wrap align-items-center">
                        <button type="submit" name="action" value="save_dashboard_preferences" class="btn btn-primary">Ansicht speichern</button>
                        <?php if ($hasSavedDashboardPreferences): ?>
                            <button type="submit" name="action" value="reset_dashboard_preferences" class="btn btn-outline-secondary">Rollen-Vorlage wiederherstellen</button>
                        <?php endif; ?>
                        <span class="text-secondary small" role="status" aria-live="polite">Speicherung erfolgt per CSRF-geschütztem POST.</span>
                    </div>
                </form>
            </div>
            </div>
        <?php endif; ?>

        <?php if (dashboardSectionVisible($visibleDashboardSections, 'work_overview')): ?>
        <section class="dashboard-section dashboard-section--work">
        <div class="card card-lg dashboard-work-card">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 text-secondary mb-2">
                    <?= dashIcon('activity') ?>
                    <span class="dashboard-section-label">ZENTRALE ARBEITSUEBERSICHT</span>
                </div>
                <h3 class="mb-2">Aktuelle Prioritaeten</h3>
                <p class="text-secondary mb-0">
                    Schnellaktionen und Aktivitätszusammenfassung statt doppelter Kennzahlen.
                </p>

                <div class="dashboard-work-grid mt-4">
                    <div class="dashboard-context-tile">
                        <div class="dashboard-context-header">
                            <div class="dashboard-section-label mb-0 dashboard-context-label">Schnellaktionen</div>
                            <span class="dashboard-context-icon"><?= dashIcon('file-plus') ?></span>
                        </div>
                        <p class="dashboard-context-sub">Direkte Einstiege für wiederkehrende Aufgaben.</p>
                        <?php if ($favoriteShortcuts !== []): ?>
                            <div class="dashboard-work-action-list">
                                <?php foreach (array_slice($favoriteShortcuts, 0, 4) as $shortcut): ?>
                                    <a href="<?= htmlspecialchars(dashboardUrl((string) ($shortcut['url'] ?? ''), '/admin')) ?>" class="dashboard-work-action">
                                        <?= dashIcon((string) ($shortcut['icon'] ?? 'settings')) ?>
                                        <span><?= htmlspecialchars((string) ($shortcut['label'] ?? 'Aktion')) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="dashboard-context-sub">Keine Schnellaktionen gespeichert.</div>
                            <div class="dashboard-empty-hint">Noch keine Favoriten – sie können jederzeit hinzugefügt werden.</div>
                        <?php endif; ?>
                    </div>
                    <div class="dashboard-context-tile">
                        <div class="dashboard-context-header">
                            <div class="dashboard-section-label mb-0 dashboard-context-label">Letzte Aktivität</div>
                            <span class="dashboard-context-icon"><?= dashIcon('activity') ?></span>
                        </div>
                        <p class="dashboard-context-sub">Zuletzt ausgeführte Aktionen im System.</p>
                        <?php if ($activityEntries !== []): ?>
                            <ul class="dashboard-context-detail-list">
                                <?php foreach (array_slice($activityEntries, 0, 4) as $entry): ?>
                                    <li>
                                        <strong><?= htmlspecialchars((string) ($entry->action ?? 'Aktion')) ?></strong>
                                        <span class="text-secondary"> · <?= htmlspecialchars((string) ($entry->created_at ?? 'soeben')) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="dashboard-context-sub">Keine neuen Aktivitäten vorhanden.</div>
                            <div class="dashboard-empty-hint">Neue Ereignisse erscheinen hier automatisch.</div>
                        <?php endif; ?>
                    </div>
                    <div class="dashboard-context-tile">
                        <div class="dashboard-context-header">
                            <div class="dashboard-section-label mb-0 dashboard-context-label">Offene Prioritäten</div>
                            <span class="dashboard-context-icon"><?= dashIcon('alert-triangle') ?></span>
                        </div>
                        <p class="dashboard-context-sub">Themen mit nötigem Handlungsbedarf.</p>
                        <?php if ($attentionItems !== []): ?>
                            <ul class="dashboard-context-detail-list">
                                <?php foreach (array_slice($attentionItems, 0, 4) as $item): ?>
                                    <li>
                                        <a href="<?= htmlspecialchars(dashboardUrl((string) ($item['url'] ?? ''), '/admin')) ?>" class="text-reset text-decoration-none">
                                            <?= htmlspecialchars((string) ($item['label'] ?? 'Hinweis')) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="dashboard-context-sub">0 offene Prioritäten.</div>
                            <div class="dashboard-empty-hint">Aktuell ist kein Eingreifen nötig.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        </section>
        <?php endif; ?>

        <?php if (dashboardSectionVisible($visibleDashboardSections, 'favorites_recent')): ?>
        <section class="dashboard-section dashboard-section--favorites">
        <div class="dashboard-overview-grid">
            <div class="card dashboard-compact-card">
                <div class="card-header">
                    <h3 class="card-title">Favoriten</h3>
                </div>
                <div class="card-body">
                    <?php if ($favoriteShortcuts !== []): ?>
                        <div class="dashboard-favorite-grid">
                            <?php foreach ($favoriteShortcuts as $shortcut): ?>
                                <a href="<?= htmlspecialchars(dashboardUrl((string) ($shortcut['url'] ?? ''), '/admin')) ?>" class="btn btn-outline-primary">
                                    <?= dashIcon((string) ($shortcut['icon'] ?? 'settings')) ?>
                                    <?= htmlspecialchars((string) ($shortcut['label'] ?? 'Favorit')) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="dashboard-empty-state">
                            <p class="text-secondary mb-1">Noch keine Favoriten gespeichert.</p>
                            <p class="dashboard-empty-hint mb-0">Alles ruhig - du kannst Favoriten oben in "Dashboard personalisieren" auswaehlen.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card dashboard-compact-card">
                <div class="card-header">
                    <h3 class="card-title">Zuletzt genutzt</h3>
                </div>
                <div class="card-body dashboard-recent-list" id="dashboard-recent-links" aria-live="polite" data-empty-text="Noch keine zuletzt genutzten Admin-Ziele gespeichert.">
                    <div class="text-secondary">Zuletzt genutzte Ziele werden geladen ...</div>
                </div>
            </div>
        </div>
        </section>
        <?php endif; ?>

        <section class="dashboard-section dashboard-section--lower">
        <div class="dashboard-overview-grid">
            <?php if (dashboardSectionVisible($visibleDashboardSections, 'attention')): ?>
            <div class="dashboard-overview-card">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Nächste Aufmerksamkeit</h3>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php if ($attentionItems !== []): ?>
                            <?php foreach ($attentionItems as $item): ?>
                                <a href="<?= htmlspecialchars(dashboardUrl((string) ($item['url'] ?? ''), '/admin')) ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex align-items-start justify-content-between gap-3">
                                        <div>
                                            <div class="fw-semibold mb-1"><?= htmlspecialchars((string) ($item['label'] ?? 'Hinweis')) ?></div>
                                            <div class="small text-secondary"><?= htmlspecialchars((string) ($item['hint'] ?? '')) ?></div>
                                        </div>
                                        <span class="badge bg-<?= htmlspecialchars((string) ($item['type'] ?? 'secondary')) ?>-lt">
                                            <?= htmlspecialchars((string) ($item['value'] ?? '')) ?>
                                        </span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="list-group-item dashboard-empty-state">
                                <div class="text-secondary">Aktuell gibt es keine offenen Prioritaeten.</div>
                                <div class="dashboard-empty-hint">Alles ruhig – derzeit ist kein Eingreifen nötig.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($subscriptionEnabled && dashboardSectionVisible($visibleDashboardSections, 'recent_orders')): ?>
                <div class="dashboard-overview-card">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Neueste Bestellungen</h3>
                        </div>
                        <?php if ($recentOrders !== []): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($recentOrders as $order): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex align-items-start justify-content-between gap-3">
                                            <div>
                                                <div class="fw-semibold"><?= htmlspecialchars((string) ($order->order_number ?? 'Bestellung')) ?></div>
                                                <div class="small text-secondary"><?= htmlspecialchars((string) ($order->customer_name ?? 'Gast')) ?></div>
                                                <div class="small text-secondary"><?= htmlspecialchars((string) ($order->created_at ?? '')) ?></div>
                                            </div>
                                            <div class="text-end">
                                                <div class="fw-semibold">
                                                    <?= htmlspecialchars(number_format((float) ($order->total_amount ?? 0), 2, ',', '.')) ?>
                                                    <?= htmlspecialchars((string) ($order->currency ?? 'EUR')) ?>
                                                </div>
                                                <span class="badge bg-azure-lt"><?= htmlspecialchars((string) ($order->status ?? 'offen')) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="card-footer">
                                <a href="<?= htmlspecialchars(dashboardUrl('/admin/orders')) ?>" class="btn btn-outline-primary w-100">
                                    <?= dashIcon('shopping-cart') ?>
                                    Bestellungen öffnen
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="card-body dashboard-empty-state">
                                <div class="text-secondary">Noch keine Bestellungen gefunden.</div>
                                <div class="dashboard-empty-hint">Alles ruhig - neue Bestellungen erscheinen hier automatisch.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (dashboardSectionVisible($visibleDashboardSections, 'system_status')): ?>
            <div class="dashboard-overview-card">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Systemstatus</h3>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-vcenter card-table mb-0">
                            <tbody>
                                <?php if (!empty($system['php_version'])): ?>
                                    <tr>
                                        <td class="text-secondary">PHP</td>
                                        <td><?= htmlspecialchars((string) $system['php_version']) ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if (!empty($system['cms_version'])): ?>
                                    <tr>
                                        <td class="text-secondary">CMS</td>
                                        <td><?= htmlspecialchars((string) $system['cms_version']) ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if (!empty($system['mysql_version'])): ?>
                                    <tr>
                                        <td class="text-secondary">MySQL</td>
                                        <td><?= htmlspecialchars((string) $system['mysql_version']) ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if (!empty($performance['disk_free_formatted'])): ?>
                                    <tr>
                                        <td class="text-secondary">Speicher frei</td>
                                        <td><?= htmlspecialchars((string) $performance['disk_free_formatted']) ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if (!empty($system['upload_max_filesize'])): ?>
                                    <tr>
                                        <td class="text-secondary">Upload-Limit</td>
                                        <td><?= htmlspecialchars((string) $system['upload_max_filesize']) ?></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (dashboardSectionVisible($visibleDashboardSections, 'security_performance')): ?>
            <div class="dashboard-overview-card">
                <div class="card dashboard-security-card<?= $hasFailedLogins ? ' is-danger' : '' ?>">
                    <div class="card-header">
                        <h3 class="card-title">Sicherheit & Performance</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div class="text-secondary small">Security-Score</div>
                                <div class="h2 mb-0"><?= htmlspecialchars((string) ($security['security_score'] ?? 0)) ?>/100</div>
                            </div>
                            <span class="badge bg-<?= htmlspecialchars(dashboardStatusBadge((string) ($security['status'] ?? 'secondary'))) ?>-lt">
                                <?= htmlspecialchars((string) ($security['status'] ?? 'unbekannt')) ?>
                            </span>
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="dashboard-mini-stat h-100">
                                    <div class="text-secondary small mb-1">HTTPS</div>
                                    <div class="fw-semibold"><?= !empty($security['https_enabled']) ? 'Aktiv' : 'Prüfen' ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="dashboard-mini-stat h-100">
                                    <div class="text-secondary small mb-1">Failed Logins</div>
                                    <div class="dashboard-failed-login-line">
                                        <span class="fw-semibold"><?= htmlspecialchars((string) $failedLoginsCount) ?> / 24h</span>
                                        <span class="dashboard-failed-login-badge<?= $hasFailedLogins ? ' is-active' : '' ?>" aria-label="Fehlgeschlagene Logins in den letzten 24 Stunden">
                                            <?= htmlspecialchars((string) $failedLoginsCount) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="dashboard-mini-stat h-100">
                                    <div class="text-secondary small mb-1">Performance-Score</div>
                                    <div class="fw-semibold"><?= htmlspecialchars((string) ($performance['performance_score'] ?? 0)) ?>/50</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="dashboard-mini-stat h-100">
                                    <div class="text-secondary small mb-1">RAM-Auslastung</div>
                                    <div class="fw-semibold"><?= htmlspecialchars((string) ($performance['memory_percent'] ?? 0)) ?>%</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        </section>

        <?php if (dashboardSectionVisible($visibleDashboardSections, 'recent_activity')): ?>
        <!-- ─── Letzte Aktivitäten ─────────────────────────────── -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Letzte Aktivitäten</h3>
            </div>
            <?php if ($activityEntries !== []): ?>
                <div class="card-body p-0">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Aktion</th>
                                <th>Details</th>
                                <th>Zeitpunkt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($activityEntries, 0, 6) as $entry): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-blue-lt"><?= htmlspecialchars((string) ($entry->action ?? '')) ?></span>
                                    </td>
                                    <td class="text-secondary"><?= htmlspecialchars(cms_truncate_text((string) ($entry->details ?? ''), 60)) ?></td>
                                    <td class="text-secondary">
                                        <?= htmlspecialchars((string) ($entry->created_at ?? '')) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer py-2">
                    <a href="<?= htmlspecialchars(dashboardUrl('/admin/logs')) ?>" class="btn btn-sm btn-outline-secondary">Alle Aktivitäten anzeigen</a>
                </div>
            <?php else: ?>
                <div class="card-body">
                    <div class="empty">
                        <div class="empty-icon"><?= dashIcon('activity') ?></div>
                        <p class="empty-title">Keine Aktivitäten</p>
                        <p class="empty-subtitle text-secondary">
                            Aktivitäten werden hier angezeigt, sobald Aktionen im System stattfinden.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div><!-- /.container-xl -->
</div><!-- /.page-body -->

