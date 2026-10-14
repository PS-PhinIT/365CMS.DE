<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tables – Listenansicht
 *
 * Erwartet: $data (aus TablesModule::getListData())
 *           $alert, $csrfToken
 */

$tables = $data['tables'] ?? [];
$total  = $data['total'] ?? 0;
$search = $data['search'] ?? '';
$siteTablesBaseUrl = '/admin/site-tables';
$siteTablesSettingsUrl = $siteTablesBaseUrl . '?action=settings';
$siteTablesCreateUrl = $siteTablesBaseUrl . '?action=edit';
$buildSiteTablesEditUrl = static fn (int $id): string => $siteTablesBaseUrl . '?action=edit&id=' . $id;
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Seiten &amp; Beiträge</div>
                <h2 class="page-title mb-1">Tabellen</h2>
                <div class="content-listing-header__meta">
                    <span><?php echo (int)$total; ?> Tabellen</span>
                    <span>Wiederverwendbare Site Tables mit Suche/Sortierung/Paginierung</span>
                </div>
            </div>
            <div class="btn-list">
                <a href="<?php echo htmlspecialchars($siteTablesSettingsUrl); ?>" class="btn btn-outline-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.757.426 1.757 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.757-2.924 1.757-3.35 0a1.724 1.724 0 0 0 -2.573-1.066c-1.543 .94-3.31-.826-2.37-2.37a1.724 1.724 0 0 0 -1.065-2.572c-1.757-.426-1.757-2.924 0-3.35a1.724 1.724 0 0 0 1.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path d="M12 12m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0"/></svg>
                    Einstellungen
                </a>
                <a href="<?php echo htmlspecialchars($siteTablesCreateUrl); ?>" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14"/><path d="M5 12l14 0"/></svg>
                    Neue Tabelle
                </a>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">

        <?php
        $alertData = $alert ?? [];
        $alertDismissible = true;
        $alertMarginClass = 'mb-3';
        require __DIR__ . '/../partials/flash-alert.php';
        ?>

        <div class="card content-listing-card">
            <div class="card-header content-listing-toolbar">
                <div class="content-listing-toolbar__label">Filter &amp; Suche</div>
                <div class="content-listing-filters">
                    <div class="content-listing-filters__search">
                        <label class="form-label mb-0 small text-secondary" for="searchInput">Schnellsuche</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="10" cy="10" r="7"/><path d="M21 21l-6 -6"/></svg>
                            </span>
                            <input type="text" class="form-control form-control-sm js-site-tables-search-input" id="searchInput" placeholder="Tabellenname suchen"
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   data-search-url="<?php echo htmlspecialchars($siteTablesBaseUrl, ENT_QUOTES); ?>">
                        </div>
                    </div>
                    <div class="content-listing-filters__actions">
                        <span class="text-secondary small">Shortcodes: <code>[site-table id="X"]</code> oder <code>[table id=X /]</code></span>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-vcenter card-table content-listing-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Beschreibung</th>
                            <th>Spalten</th>
                            <th>Zeilen</th>
                            <th>Aktualisiert</th>
                            <th class="w-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($tables)): ?>
                        <?php
                        $emptyStateColspan = 7;
                        $emptyStateMessage = 'Keine Tabellen vorhanden.';
                        $emptyStateSubtitle = 'Legen Sie Ihre erste Tabelle an oder passen Sie die Suche an.';
                        $emptyStateIcon = 'table';
                        require __DIR__ . '/../partials/empty-table-row.php';
                        ?>
                    <?php else: ?>
                        <?php foreach ($tables as $t): ?>
                            <tr class="content-listing-table__row">
                                <td class="text-secondary"><?php echo (int)$t['id']; ?></td>
                                <td>
                                    <a href="<?php echo htmlspecialchars($buildSiteTablesEditUrl((int)$t['id'])); ?>" class="text-reset fw-medium">
                                        <?php echo htmlspecialchars($t['table_name']); ?>
                                    </a>
                                    <div class="content-shortcode-chip-wrap mt-1">
                                        <button type="button" class="content-shortcode-chip js-copy-table-shortcode" data-copy-text='[site-table id="<?php echo (int) $t['id']; ?>"]'>[site-table]</button>
                                        <button type="button" class="content-shortcode-chip js-copy-table-shortcode" data-copy-text='[table id=<?php echo (int) $t['id']; ?> /]'>[table]</button>
                                    </div>
                                </td>
                                <td class="text-secondary text-truncate" style="max-width:200px;"><?php echo htmlspecialchars((string)($t['description_excerpt'] ?? '')); ?></td>
                                <td><?php echo (int)($t['col_count'] ?? 0); ?></td>
                                <td><?php echo (int)($t['row_count'] ?? 0); ?></td>
                                <td class="text-secondary"><?php echo htmlspecialchars((string)($t['updated_label'] ?? '–')); ?></td>
                                <td class="table-actions content-listing-table__actions-cell">
                                    <div class="dropdown">
                                        <button type="button" class="btn btn-ghost-secondary btn-icon btn-sm" data-bs-toggle="dropdown" aria-label="Aktionen öffnen">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/><circle cx="12" cy="5" r="1"/></svg>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-end">
                                            <a class="dropdown-item" href="<?php echo htmlspecialchars($buildSiteTablesEditUrl((int)$t['id'])); ?>">
                                                Bearbeiten
                                            </a>
                                            <button type="button" class="dropdown-item js-site-table-duplicate" data-table-id="<?php echo (int)$t['id']; ?>">Duplizieren</button>
                                            <div class="dropdown-divider"></div>
                                            <button type="button" class="dropdown-item text-danger js-site-table-delete"
                                                    data-table-id="<?php echo (int)$t['id']; ?>"
                                                    data-table-name="<?php echo htmlspecialchars((string) $t['table_name'], ENT_QUOTES); ?>">
                                                Löschen
                                            </button>
                                        </div>
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
</div>

<!-- Hidden Forms -->
<form id="deleteForm" method="post" class="d-none">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>
<form id="duplicateForm" method="post" class="d-none">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <input type="hidden" name="action" value="duplicate">
    <input type="hidden" name="id" id="duplicateId">
</form>
