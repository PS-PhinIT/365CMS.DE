<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * View: Menü Editor
 *
 * @var array  $data
 * @var string $csrfToken
 * @var array|null $alert
 */

$menus       = $data['menus'] ?? [];
$currentMenu = $data['currentMenu'] ?? null;
$locations   = $data['locations'] ?? [];
$locationOverview = $data['locationOverview'] ?? [];
$menuItems = $data['menuItems'] ?? [];
$pagePickerOptions = $data['pagePickerOptions'] ?? [];
$menuEditorConfigJson = (string) ($data['editorConfigJson'] ?? '{"items":[]}');
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$menuEditorBaseUrl = '/admin/menu-editor';
?>

<div class="page-header d-print-none text-start">
    <div class="container-xl">
        <div class="row g-2 align-items-center justify-content-between">
            <div class="col text-start">
                <div class="page-pretitle">Themes &amp; Design</div>
                <h2 class="page-title">Menü Editor</h2>
                <div class="text-muted mt-1">Navigationsmenüs verwalten</div>
            </div>
            <div class="col-auto ms-auto">
                <button type="button" class="btn btn-primary js-menu-modal-trigger"
                        data-menu-id="0"
                        data-menu-name=""
                        data-menu-location=""
                        data-menu-modal-title="Neues Menü">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14"/><path d="M5 12l14 0"/></svg>
                    Neues Menü
                </button>
            </div>
        </div>
    </div>
</div>

<div class="container-xl mt-4">

    <?php
    $alertData = is_array($alert ?? null) ? $alert : [];
    $alertMarginClass = 'mb-3';
    include __DIR__ . '/../partials/flash-alert.php';
    ?>

    <div class="row">
        <!-- Menu List -->
        <div class="col-md-3">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Menüs</h3>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($menus as $menu): ?>
                        <a href="<?php echo $menuEditorBaseUrl; ?>?menu=<?php echo (int)$menu->id; ?>"
                           class="list-group-item list-group-item-action d-flex justify-content-between align-items-center<?php echo ($currentMenu && (int)$currentMenu->id === (int)$menu->id) ? ' active' : ''; ?>">
                            <div>
                                <div><?php echo htmlspecialchars($menu->name); ?></div>
                                <small class="<?php echo ($currentMenu && (int)$currentMenu->id === (int)$menu->id) ? 'text-white-50' : 'text-muted'; ?>">
                                    <?php echo (int)$menu->item_count; ?> Items
                                    <?php if (!empty($menu->location)): ?>
                                        · <?php echo htmlspecialchars($locations[$menu->location] ?? $menu->location); ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </a>
                    <?php endforeach; ?>
                    <?php if (empty($menus)): ?>
                        <div class="list-group-item text-muted text-center py-3">Noch keine Menüs erstellt</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($locationOverview)): ?>
            <div class="card mt-3">
                <div class="card-header">
                    <h3 class="card-title">Theme-Positionen</h3>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($locationOverview as $locationInfo): ?>
                        <?php
                        $locationSlug = (string) ($locationInfo['slug'] ?? '');
                        $locationLabel = (string) ($locationInfo['label'] ?? $locationSlug);
                        $assignedMenu = $locationInfo['menu'] ?? null;
                        $assignedMenuId = (int) ($assignedMenu->id ?? 0);
                        $isActiveLocation = $currentMenu && (int) $currentMenu->id === $assignedMenuId;
                        ?>
                        <div class="list-group-item<?php echo $isActiveLocation ? ' active' : ''; ?>">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div>
                                    <div class="fw-medium"><?php echo htmlspecialchars($locationLabel); ?></div>
                                    <div class="small <?php echo $isActiveLocation ? 'text-white-50' : 'text-muted'; ?>">
                                        <code><?php echo htmlspecialchars($locationSlug); ?></code>
                                        <?php if ($assignedMenu): ?>
                                            · <?php echo htmlspecialchars((string) ($assignedMenu->name ?? '')); ?>
                                        <?php else: ?>
                                            · Noch kein Menü zugewiesen
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($assignedMenu): ?>
                                    <a href="<?php echo $menuEditorBaseUrl; ?>?menu=<?php echo $assignedMenuId; ?>"
                                       class="btn btn-sm <?php echo $isActiveLocation ? 'btn-light' : 'btn-outline-primary'; ?>">
                                        Bearbeiten
                                    </a>
                                <?php else: ?>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-primary js-menu-modal-trigger"
                                            data-menu-id="0"
                                            data-menu-name="<?php echo htmlspecialchars($locationLabel, ENT_QUOTES); ?>"
                                            data-menu-location="<?php echo htmlspecialchars($locationSlug, ENT_QUOTES); ?>"
                                            data-menu-modal-title="Menü für Position anlegen">
                                        Anlegen
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Menu Items Editor -->
        <div class="col-md-9">
            <?php if ($currentMenu): ?>
                <!-- Menu Settings -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h3 class="card-title mb-0"><?php echo htmlspecialchars($currentMenu->name); ?></h3>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm js-menu-modal-trigger"
                                    data-menu-id="<?php echo (int)$currentMenu->id; ?>"
                                    data-menu-name="<?php echo htmlspecialchars($currentMenu->name, ENT_QUOTES); ?>"
                                    data-menu-location="<?php echo htmlspecialchars((string) ($currentMenu->location ?? ''), ENT_QUOTES); ?>"
                                    data-menu-modal-title="Menü bearbeiten">
                                Einstellungen
                            </button>
                            <form method="post" class="d-inline" id="deleteMenuForm">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="delete_menu">
                                <input type="hidden" name="menu_id" value="<?php echo (int)$currentMenu->id; ?>">
                                <button type="button" class="btn btn-outline-danger btn-sm" id="btnDeleteMenu">Löschen</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Add Item Card -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h3 class="card-title">Item hinzufügen</h3>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <input type="text" id="newItemTitle" aria-label="Titel des Menüpunkts" class="form-control" placeholder="Titel" maxlength="255">
                            </div>
                            <div class="col-md-4">
                                <input type="text" id="newItemUrl" aria-label="URL oder Slug des Menüpunkts" class="form-control" placeholder="URL oder Slug (z.B. kontakt, /kontakt oder https://...)">
                                <div class="form-hint mt-1">Für reine Elternpunkte kann das Feld zunächst leer bleiben; beim Speichern wird daraus automatisch ein Platzhalter.</div>
                            </div>
                            <div class="col-md-2">
                                <select id="newItemParent" aria-label="Übergeordneter Menüpunkt" class="form-select">
                                    <option value="0">Hauptebene</option>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <select id="newItemTarget" aria-label="Linkziel" class="form-select">
                                    <option value="_self">Gleich</option>
                                    <option value="_blank">Neu</option>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <button type="button" class="btn btn-primary w-100" id="btnAddItem">Hinzufügen</button>
                            </div>
                        </div>
                        <?php if (!empty($pagePickerOptions)): ?>
                            <div class="mt-3">
                                <div class="menu-page-picker-highlight">
                                    <span class="menu-page-picker-highlight__label">Schnellauswahl</span>
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-8">
                                            <label class="form-label small text-muted" for="pagePickerSelect">Oder veröffentlichte Seite übernehmen:</label>
                                            <select id="pagePickerSelect" class="form-select form-select-sm">
                                                <option value="">Seite auswählen …</option>
                                                <?php foreach ($pagePickerOptions as $option): ?>
                                                    <option value="<?php echo $escape((string) ($option['url'] ?? '')); ?>"
                                                            data-title="<?php echo $escape((string) ($option['title'] ?? '')); ?>">
                                                        <?php echo $escape((string) ($option['title'] ?? '')); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <button type="button" class="btn btn-outline-secondary btn-sm w-100" id="btnAddPageItem">Seite übernehmen</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Menu Items List -->
                <form method="post" id="saveItemsForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="save_items">
                    <input type="hidden" name="menu_id" value="<?php echo (int)$currentMenu->id; ?>">
                    <input type="hidden" name="items" id="menuItemsJson" value="">

                    <div class="card">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <h3 class="card-title mb-0">Menü-Items</h3>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-device-floppy" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 4h10l4 4v10a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2"/><path d="M12 14m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"/><path d="M14 4l0 4l-6 0l0 -4"/></svg>
                                Speichern
                            </button>
                        </div>
                        <div class="card-body p-0">
                            <div id="menuItemsList" class="list-group list-group-flush">
                                <?php if (empty($menuItems)): ?>
                                    <div class="list-group-item text-center text-muted py-5" id="emptyState">
                                        Noch keine Items. Füge oben ein Item hinzu.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </form>

            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-menu-2 mb-3" width="48" height="48" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.3;"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 6l16 0"/><path d="M4 12l16 0"/><path d="M4 18l16 0"/></svg>
                        <h3>Wähle ein Menü aus</h3>
                        <p class="text-muted">Wähle ein Menü aus der Liste oder erstelle ein neues.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Menu Create/Edit Modal -->
<div class="modal modal-blur fade" id="menuModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="save_menu">
            <input type="hidden" name="menu_id" id="editMenuId" value="0">
            <div class="modal-header">
                <h5 class="modal-title" id="menuModalTitle">Menü erstellen / bearbeiten</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="editMenuName" class="form-label">Name</label>
                    <input type="text" name="menu_name" id="editMenuName" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="editMenuLocation" class="form-label">Position</label>
                    <select name="menu_location" id="editMenuLocation" class="form-select">
                        <option value="">– Keine –</option>
                        <?php foreach ($locations as $key => $label): ?>
                            <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn me-auto" data-bs-dismiss="modal">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>
<script type="application/json" id="menu-editor-config"><?php echo $menuEditorConfigJson; ?></script>
