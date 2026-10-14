<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$sites = $data['sites'] ?? [];
$total = (int)($data['total'] ?? 0);
$search = (string)($data['search'] ?? '');
$templateOptions = $data['templateOptions'] ?? [];
$hubBaseUrl = '/admin/hub-sites';
$hubTemplatesUrl = $hubBaseUrl . '?action=templates';
$hubCreateUrl = $hubBaseUrl . '?action=edit';
$buildHubEditUrl = static fn (int $id): string => $hubBaseUrl . '?action=edit&id=' . $id;
$buildHubPublicPath = static fn (string $slug): string => '/' . ltrim($slug, '/');
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Seiten &amp; Beiträge</div>
                <h2 class="page-title mb-1">Hub-Sites</h2>
                <div class="content-listing-header__meta">
                    <span><?php echo $total; ?> Hub-Sites</span>
                    <span>Routing- und Sammelseiten mit zentralen Templates</span>
                </div>
            </div>
            <div>
                <a href="<?php echo htmlspecialchars($hubCreateUrl); ?>" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14"/><path d="M5 12l14 0"/></svg>
                    Neue Hub Site
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
                <ul class="nav nav-tabs card-header-tabs">
                    <li class="nav-item"><a class="nav-link active" href="<?php echo htmlspecialchars($hubBaseUrl); ?>">Content</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars($hubTemplatesUrl); ?>">Templates</a></li>
                </ul>
            </div>
            <div class="card-body border-bottom">
                <div class="content-listing-toolbar__label">Filter &amp; Suche</div>
                <div class="content-listing-filters">
                    <div class="content-listing-filters__search">
                        <label class="form-label mb-0 small text-secondary" for="hubSitesSearchInput">Schnellsuche</label>
                        <input type="text" class="form-control form-control-sm js-hub-sites-search-input" id="hubSitesSearchInput" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name oder Slug suchen"
                               data-search-url="<?php echo htmlspecialchars($hubBaseUrl, ENT_QUOTES); ?>">
                    </div>
                    <div class="content-listing-filters__actions">
                        <span class="text-secondary small">Öffentliche Hub-Sites laufen direkt über ihren Slug im Frontend.</span>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table content-listing-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Template</th>
                            <th>Einträge</th>
                            <th>Aktualisiert</th>
                            <th class="w-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($sites === []): ?>
                        <?php
                        $emptyStateColspan = 6;
                        $emptyStateMessage = 'Noch keine Hub-Sites vorhanden.';
                        $emptyStateSubtitle = 'Erstellen Sie eine Hub-Site, um Sammelseiten mit eigenem Slug bereitzustellen.';
                        $emptyStateIcon = 'hub';
                        require __DIR__ . '/../partials/empty-table-row.php';
                        ?>
                    <?php else: ?>
                        <?php foreach ($sites as $site): ?>
                            <?php $publicPath = !empty($site['hub_slug']) ? $buildHubPublicPath((string)$site['hub_slug']) : ''; ?>
                            <tr class="content-listing-table__row">
                                <td class="text-secondary"><?php echo (int)$site['id']; ?></td>
                                <td>
                                    <a href="<?php echo htmlspecialchars($buildHubEditUrl((int)$site['id'])); ?>" class="text-reset fw-medium">
                                        <?php echo htmlspecialchars((string)$site['table_name']); ?>
                                    </a>
                                    <div class="text-secondary small">
                                        <code>/<?php echo htmlspecialchars((string)($site['hub_slug'] ?? '')); ?></code>
                                    </div>
                                    <?php if ($publicPath !== ''): ?>
                                        <div class="content-shortcode-chip-wrap mt-2">
                                            <button type="button"
                                                    class="content-shortcode-chip js-copy-hub-url"
                                                    data-hub-public-path="<?php echo htmlspecialchars($publicPath, ENT_QUOTES); ?>">
                                                Pfad kopieren
                                            </button>
                                            <a href="<?php echo htmlspecialchars($publicPath); ?>" target="_blank" rel="noopener noreferrer" class="content-shortcode-chip">
                                                Öffnen
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-azure-lt"><?php echo htmlspecialchars((string)($templateOptions[$site['template']] ?? $site['template'])); ?></span></td>
                                <td><?php echo (int)$site['card_count']; ?></td>
                                <td class="text-secondary"><?php echo htmlspecialchars(date('d.m.Y · H:i', strtotime((string) $site['updated_at']))); ?></td>
                                <td class="table-actions content-listing-table__actions-cell">
                                    <div class="dropdown">
                                        <button type="button" class="btn btn-ghost-secondary btn-icon btn-sm" data-bs-toggle="dropdown" aria-label="Aktionen öffnen">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/><circle cx="12" cy="5" r="1"/></svg>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-end">
                                            <a class="dropdown-item" href="<?php echo htmlspecialchars($buildHubEditUrl((int)$site['id'])); ?>">Bearbeiten</a>
                                            <?php if ($publicPath !== ''): ?>
                                                <a class="dropdown-item" href="<?php echo htmlspecialchars($publicPath); ?>" target="_blank" rel="noopener noreferrer">Public Site öffnen</a>
                                            <?php endif; ?>
                                            <button type="button" class="dropdown-item js-duplicate-hub-site" data-hub-site-id="<?php echo (int)$site['id']; ?>">Duplizieren</button>
                                            <div class="dropdown-divider"></div>
                                            <button type="button" class="dropdown-item text-danger js-delete-hub-site"
                                                    data-hub-site-id="<?php echo (int)$site['id']; ?>"
                                                    data-hub-site-name="<?php echo htmlspecialchars((string)$site['table_name'], ENT_QUOTES); ?>">
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
