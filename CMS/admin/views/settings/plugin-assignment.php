<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * View: Einstellungen › Plugins › Plugin-Zuweisung
 *
 * @var array<string, array<string, mixed>> $assignmentSites   Site-Key => Site-Daten
 * @var array<string, array<string, mixed>> $assignmentPlugins Plugin-Slug => Header-Daten
 * @var string $assignmentCsrfToken
 * @var array|null $alert
 */

$assignmentService = \CMS\Services\PluginSiteAssignmentService::getInstance();
$assignmentCurrentSite = \CMS\Services\AdminSiteContextService::getInstance()->getCurrentSiteKey();
// Die Seitenleiste hat `cms_admin_menu` bereits ausgelöst – hier wird nur gelesen.
$assignmentMenuCounts = $assignmentService->countRegisteredMenuEntries();
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>

<div class="container-xl cms-settings-page cms-assignment-page">
    <div class="page-header d-print-none mb-4">
        <div class="row align-items-center">
            <div class="col-auto">
                <div class="page-pretitle">Einstellungen › Plugins</div>
                <h2 class="page-title">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon me-2" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7h3a1 1 0 0 0 1 -1v-1a2 2 0 0 1 4 0v1a1 1 0 0 0 1 1h3a1 1 0 0 1 1 1v3a1 1 0 0 0 1 1h1a2 2 0 0 1 0 4h-1a1 1 0 0 0 -1 1v3a1 1 0 0 1 -1 1h-3a1 1 0 0 1 -1 -1v-1a2 2 0 0 0 -4 0v1a1 1 0 0 1 -1 1h-3a1 1 0 0 1 -1 -1v-3a1 1 0 0 1 1 -1h1a2 2 0 0 0 0 -4h-1a1 1 0 0 1 -1 -1v-3a1 1 0 0 1 1 -1"/></svg>
                    Plugin-Zuweisung
                </h2>
            </div>
        </div>
    </div>

    <div id="settings-alert-region">
        <?php
        $alertData = is_array($alert ?? null) ? $alert : [];
        $alertMarginClass = 'mb-4';
        require dirname(__DIR__) . '/partials/flash-alert.php';
        ?>
    </div>

    <div class="cms-settings-toolbar">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link" href="/admin/settings">Allgemein</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="<?= $esc(\CMS\Services\PluginSiteAssignmentService::ADMIN_PATH) ?>" aria-current="page">Plugins</a>
            </li>
            <li class="nav-item ms-auto">
                <a class="nav-link" href="/admin/settings?tab=content">Beiträge &amp; Sites</a>
            </li>
        </ul>
    </div>

    <?php if ($assignmentPlugins === []): ?>
        <div class="card">
            <div class="card-body cms-assignment-empty">
                <h3 class="card-title">Keine Plugins installiert</h3>
                <p class="text-secondary">Sobald Plugins installiert sind, lässt sich hier festlegen, in welcher Site-Administration ihre Menüeinträge erscheinen.</p>
                <a class="btn btn-outline-primary" href="/admin/plugins">Zur Plugin-Verwaltung</a>
            </div>
        </div>
    <?php else: ?>
        <form method="post" class="card cms-assignment" data-plugin-assignment>
            <input type="hidden" name="csrf_token" value="<?= $esc($assignmentCsrfToken) ?>">

            <div class="card-header">
                <h3 class="card-title" id="cms-assignment-title">Plugin-Menüs je Site</h3>
            </div>
            <div class="card-body cms-assignment__intro">
                <p class="mb-2">Legt fest, in welcher Site-Administration die Menüeinträge eines Plugins in der Seitenleiste erscheinen. Plugins ohne gespeicherte Zuweisung sind allen Sites zugewiesen.</p>
                <p class="text-secondary mb-0">Die Registrierung der Plugins bleibt unverändert und ihre Seiten bleiben erreichbar – wird eine Plugin-Seite in einer nicht zugewiesenen Site direkt aufgerufen, erscheint lediglich ein Hinweis.</p>
            </div>

            <div class="table-responsive">
                <table class="table card-table table-vcenter cms-assignment-table" aria-labelledby="cms-assignment-title">
                    <caption class="visually-hidden">Zuweisung der installierten Plugins zu den Sites. Je Site-Spalte lassen sich alle oder keine Plugins auswählen.</caption>
                    <thead>
                        <tr>
                            <th scope="col">Plugin</th>
                            <th scope="col">Status</th>
                            <th scope="col">Admin-Menü</th>
                            <?php foreach ($assignmentSites as $site): ?>
                                <?php $siteKey = (string) $site['key']; $siteLabel = (string) $site['label']; ?>
                                <th scope="col" class="cms-assignment-table__site">
                                    <span class="cms-assignment-table__site-head">
                                        <span class="cms-site-swatch" data-site-swatch="<?= $esc($siteKey) ?>" aria-hidden="true"></span>
                                        <span><?= $esc($siteLabel) ?></span>
                                        <?php if ($siteKey === $assignmentCurrentSite): ?>
                                            <span class="cms-assignment-table__current">aktuell</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="cms-assignment-table__bulk">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-assignment-bulk="all" data-site="<?= $esc($siteKey) ?>" aria-label="Alle Plugins in <?= $esc($siteLabel) ?> anzeigen">Alle</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-assignment-bulk="none" data-site="<?= $esc($siteKey) ?>" aria-label="Kein Plugin in <?= $esc($siteLabel) ?> anzeigen">Keine</button>
                                    </span>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignmentPlugins as $slug => $plugin): ?>
                            <?php
                            $slug = (string) $slug;
                            $pluginName = trim((string) ($plugin['name'] ?? '')) ?: $slug;
                            $pluginVersion = trim((string) ($plugin['version'] ?? ''));
                            $pluginActive = !empty($plugin['active']);
                            $assignedSites = $assignmentService->getAssignedSites($slug);
                            $menuCount = (int) ($assignmentMenuCounts[$slug] ?? 0);
                            ?>
                            <tr data-assignment-row="<?= $esc($slug) ?>">
                                <th scope="row" class="cms-assignment-table__plugin">
                                    <span class="cms-assignment-table__name"><?= $esc($pluginName) ?></span>
                                    <span class="cms-assignment-table__meta"><?= $esc($slug) ?><?= $pluginVersion !== '' ? ' · v' . $esc($pluginVersion) : '' ?></span>
                                    <input type="hidden" name="plugins[]" value="<?= $esc($slug) ?>">
                                    <span class="cms-assignment-table__warning" data-assignment-warning<?= $assignedSites === [] ? '' : ' hidden' ?>>In keiner Site zugewiesen – das Menü erscheint nirgends.</span>
                                </th>
                                <td>
                                    <span class="cms-assignment-pill <?= $pluginActive ? 'cms-assignment-pill--active' : '' ?>"><?= $pluginActive ? 'Aktiv' : 'Inaktiv' ?></span>
                                </td>
                                <td class="cms-assignment-table__menu">
                                    <?php if (!$pluginActive): ?>
                                        <span class="text-secondary">– (inaktiv)</span>
                                    <?php elseif ($menuCount === 0): ?>
                                        <span class="text-secondary">Kein Menü</span>
                                    <?php else: ?>
                                        <?= $esc($menuCount === 1 ? '1 Eintrag' : $menuCount . ' Einträge') ?>
                                    <?php endif; ?>
                                </td>
                                <?php foreach ($assignmentSites as $site): ?>
                                    <?php $siteKey = (string) $site['key']; ?>
                                    <td class="cms-assignment-table__cell">
                                        <label class="cms-assignment__check">
                                            <input class="form-check-input" type="checkbox" name="assign[<?= $esc($slug) ?>][]" value="<?= $esc($siteKey) ?>" data-site="<?= $esc($siteKey) ?>"<?= in_array($siteKey, $assignedSites, true) ? ' checked' : '' ?>>
                                            <span class="visually-hidden"><?= $esc($pluginName) ?> in <?= $esc((string) $site['label']) ?> anzeigen</span>
                                        </label>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card-footer cms-assignment__footer">
                <p class="cms-assignment__status" data-assignment-status role="status" aria-live="polite"></p>
                <button type="submit" class="btn btn-primary">Zuweisung speichern</button>
            </div>
        </form>
    <?php endif; ?>
</div>
