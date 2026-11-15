<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

/** @var array $data */
$d       = $data ?? [];
$plugins = $d['plugins'] ?? [];
$stats   = $d['stats'] ?? [];
$activeCount = (int)($stats['active'] ?? 0);
$pluginMarketplaceUrl = '/admin/plugin-marketplace';
$marketplaceEnabled = \CMS\Services\AdminMenu\AdminMenuService::getInstance()->isMarketplaceEnabled();
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Plugins</div>
                <h2 class="page-title mb-1">Plugin-Verwaltung</h2>
                <div class="content-listing-header__meta">
                    <span>Installiert: <?php echo (int)($stats['total'] ?? 0); ?></span>
                    <span>Aktiv: <?php echo (int)($stats['active'] ?? 0); ?></span>
                </div>
            </div>
            <div class="admin-section-toolbar__actions">
                <?php if ($marketplaceEnabled): ?>
                <a href="<?php echo htmlspecialchars($pluginMarketplaceUrl); ?>" class="btn btn-primary btn-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14"/><path d="M5 12l14 0"/></svg>
                    Plugin installieren
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-plugins-page">
    <div class="container-xl">

<?php
$alertData = $alert ?? [];
$alertDismissible = true;
$alertMarginClass = 'mb-4';
require __DIR__ . '/../partials/flash-alert.php';
?>

<div class="row row-deck row-cards mb-4 admin-metric-grid">
    <div class="col-sm-6 col-lg-4">
        <div class="card">
            <div class="card-body">
                <div class="subheader">Plugins gesamt</div>
                <div class="h1 mb-0"><?php echo (int)($stats['total'] ?? 0); ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="card <?php echo $activeCount === 0 ? 'admin-plugin-kpi-active--empty' : ''; ?>">
            <div class="card-body">
                <div class="subheader">Aktiv</div>
                <div class="h1 mb-0 <?php echo $activeCount === 0 ? 'text-secondary' : 'text-success'; ?>"><?php echo $activeCount; ?></div>
                <?php if ($activeCount === 0): ?>
                <div class="admin-plugin-kpi-note">Plugins über den Schieberegler aktivieren</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="card">
            <div class="card-body">
                <div class="subheader">Inaktiv</div>
                <div class="h1 mb-0 text-secondary"><?php echo (int)($stats['inactive'] ?? 0); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row row-cards admin-plugins-workspace">
    <div class="col-12 col-xxl-9">
        <div class="card admin-content-card">
            <div class="card-header">
                <h3 class="card-title">Installierte Plugins</h3>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Plugin</th>
                            <th>Version</th>
                            <th>Autor</th>
                            <th>Status</th>
                            <th class="w-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($plugins)): ?>
                        <?php
                        $emptyStateColspan = 5;
                        $emptyStateMessage = 'Keine Plugins installiert.';
                        $emptyStateSubtitle = 'Installieren Sie Plugins über den Marktplatz oder laden Sie Pakete manuell nach.';
                        $emptyStateIcon = 'plugin';
                        require __DIR__ . '/../partials/empty-table-row.php';
                        ?>
                        <?php else: ?>
                        <?php foreach ($plugins as $p): ?>
                        <?php
                        $isActive = !empty($p['active']);
                        $isSystem = !empty($p['is_system']) || !empty($p['protected']);
                        $pluginName = (string)($p['name'] ?? '');
                        $deleteConfirmMessage = 'Plugin [' . $pluginName . '] und alle zugehörigen Daten werden entfernt. Diese Aktion kann nicht rückgängig gemacht werden.';
                        ?>
                        <tr class="admin-plugin-row">
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($p['name']); ?></div>
                                <?php if (!empty($p['description'])): ?>
                                <div class="text-secondary small admin-plugin-description js-plugin-description">
                                    <span class="admin-plugin-description__text"><?php echo htmlspecialchars($p['description']); ?></span>
                                    <button type="button" class="btn btn-link btn-sm p-0 admin-plugin-description__toggle js-plugin-description-toggle d-none">... mehr</button>
                                </div>
                                <?php endif; ?>
                                <div class="text-secondary small"><code><?php echo htmlspecialchars($p['slug']); ?></code></div>
                            </td>
                            <td><?php echo htmlspecialchars($p['version'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($p['author'] ?? '-'); ?></td>
                            <td>
                                <div class="d-flex flex-column gap-1 align-items-start">
                                    <span class="badge <?php echo $isActive ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $isActive ? 'Aktiv' : 'Inaktiv'; ?></span>
                                    <?php if ($isSystem): ?>
                                    <span class="badge bg-blue-lt text-primary">Mitgeliefert</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2 justify-content-end flex-wrap">
                                    <form method="post" class="m-0">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                                        <input type="hidden" name="action" value="<?php echo $isActive ? 'deactivate' : 'activate'; ?>">
                                        <input type="hidden" name="slug" value="<?php echo htmlspecialchars($p['slug']); ?>">
                                        <label class="form-check form-switch m-0" title="<?php echo $isActive ? 'Plugin deaktivieren' : 'Plugin aktivieren'; ?>">
                                            <input class="form-check-input js-plugin-toggle-submit" type="checkbox" <?php echo $isActive ? 'checked' : ''; ?>>
                                        </label>
                                    </form>
                                    <span class="admin-plugin-toggle-status js-plugin-toggle-status <?php echo $isActive ? 'is-active' : 'is-inactive'; ?>">
                                        <?php echo $isActive ? 'Aktiv' : 'Inaktiv'; ?>
                                    </span>
                                    <?php if (!$isActive && !$isSystem): ?>
                                        <form method="post" class="m-0 admin-plugin-delete-form"
                                              data-confirm-message="<?php echo htmlspecialchars($deleteConfirmMessage); ?>"
                                              data-confirm-title="Plugin deinstallieren?"
                                              data-confirm-text="Deinstallieren"
                                              data-confirm-cancel-class="btn-outline-secondary"
                                              data-confirm-class="btn-danger"
                                              data-confirm-status-class="bg-danger">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="slug" value="<?php echo htmlspecialchars($p['slug']); ?>">
                                            <button class="btn btn-ghost-danger btn-sm" type="submit">Löschen</button>
                                        </form>
                                    <?php elseif ($isSystem): ?>
                                        <span class="admin-plugin-system-lock text-secondary">
                                            <i class="ti ti-lock" aria-hidden="true"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-12 col-xxl-3">
        <div class="card h-100 admin-content-card">
            <div class="card-header">
                <h3 class="card-title">Schnellzugriff</h3>
            </div>
            <div class="card-body d-flex flex-column gap-3">
                <?php if ($marketplaceEnabled): ?>
                <div class="text-secondary small">
                    Installiere neue Erweiterungen im Marktplatz und verwalte hier den aktiven Laufzeitstatus.
                </div>
                <a href="<?php echo htmlspecialchars($pluginMarketplaceUrl); ?>" class="btn btn-outline-primary btn-sm w-100">
                    Zum Plugin-Marktplatz
                </a>
                <?php endif; ?>
                <div class="alert alert-info mb-0">
                    <div class="fw-semibold mb-1">Hinweis</div>
                    <div class="small">Mitgelieferte System-Plugins sind deaktivierbar, aber nicht löschbar.</div>
                </div>
            </div>
        </div>
    </div>
</div>

    </div>
</div>
