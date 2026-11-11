<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * View: Rollen & Rechte – Berechtigungsmatrix
 *
 * @var array $data  Rollen, Capabilities, Permissions, RoleCounts
 * @var string $csrfToken
 */

$roles        = $data['roles'] ?? [];
$roleLabels   = $data['roleLabels'] ?? [];
$capabilities = $data['capabilities'] ?? [];
$permissions  = $data['permissions'] ?? [];
$roleCounts   = $data['roleCounts'] ?? [];
$customRoles  = $data['customRoles'] ?? [];
$customCapabilities = $data['customCapabilities'] ?? [];
$roleComparison = is_array($data['roleComparison'] ?? null) ? $data['roleComparison'] : [];

$groupLabels = [
    'pages'    => 'Seiten',
    'posts'    => 'Beiträge',
    'media'    => 'Medien',
    'users'    => 'Benutzer',
    'themes'   => 'Themes',
    'plugins'  => 'Plugins',
    'settings' => 'Einstellungen',
    'comments' => 'Kommentare',
    'content'  => 'Inhalte & Redaktion',
    'forms'    => 'Formulare',
    'downloads'=> 'Downloads',
    'system'   => 'System & Werkzeuge',
];

$capLabels = [
    'view'      => 'Anzeigen',
    'create'    => 'Erstellen',
    'edit'      => 'Bearbeiten',
    'delete'    => 'Löschen',
    'publish'   => 'Veröffentlichen',
    'upload'    => 'Hochladen',
    'moderate'  => 'Moderieren',
    'activate'  => 'Aktivieren',
    'customize' => 'Anpassen',
    'install'   => 'Installieren',
    'settings'  => 'Einstellungen',
    'system'    => 'System',
    'roles'     => 'Rollen',
];

$formatLabel = static function (string $value): string {
    return ucwords(str_replace(['_', '-', '.'], ' ', strtolower($value)));
};

$renderCapabilityDiffList = static function (array $groupedCapabilities) use ($groupLabels, $formatLabel): string {
    if ($groupedCapabilities === []) {
        return '<p class="text-secondary mb-0">Keine abweichenden Rechte.</p>';
    }

    $html = '<div class="d-flex flex-column gap-2">';
    foreach ($groupedCapabilities as $group => $items) {
        if (!is_array($items) || $items === []) {
            continue;
        }

        $html .= '<div>';
        $html .= '<div class="fw-semibold small text-secondary mb-1">' . htmlspecialchars($groupLabels[$group] ?? $formatLabel((string)$group)) . '</div>';
        $html .= '<div class="d-flex flex-wrap gap-1">';
        foreach ($items as $capability) {
            $html .= '<code class="badge bg-secondary-lt text-secondary">' . htmlspecialchars((string)$capability) . '</code>';
        }
        $html .= '</div></div>';
    }

    return $html . '</div>';
};
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Benutzer &amp; Gruppen</div>
                <h2 class="page-title mb-1">Rollen &amp; Rechte</h2>
                <div class="content-listing-header__meta">
                    <span><?php echo count($roles); ?> Rollen</span>
                    <span><?php echo count($customRoles); ?> eigene Rollen</span>
                    <span><?php echo count($customCapabilities); ?> eigene Rechte</span>
                </div>
            </div>
            <div class="d-print-none">
                <div class="btn-list">
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addRoleModal">
                        Neue Rolle
                    </button>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCapabilityModal">
                        Neues Recht
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <div class="cms-admin-info-box mb-4" role="note">
            <div class="cms-admin-info-box__head">
                <h3 class="cms-admin-info-box__title">Rollenmatrix und Custom-Rechte</h3>
                <div class="cms-admin-info-box__actions">
                    <a href="/admin/users" class="btn btn-sm btn-outline-secondary">Benutzer</a>
                    <a href="/admin/groups" class="btn btn-sm btn-outline-secondary">Gruppen</a>
                    <a href="/admin/user-settings" class="btn btn-sm btn-outline-secondary">Einstellungen</a>
                </div>
            </div>
            <p class="cms-admin-info-box__text">
                Rechte klar steuern und Rollen sicher im Team zuweisen.
            </p>
        </div>

        <div class="row row-cards mb-4">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h3 class="card-title">Eigene Rollen</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th>Rolle</th>
                                    <th>Benutzer</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($customRoles)): ?>
                                    <tr><td colspan="3" class="text-center text-secondary py-4">Noch keine eigenen Rollen angelegt.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($customRoles as $role): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($roleLabels[$role] ?? $formatLabel($role)); ?></div>
                                                <div class="text-secondary small"><?php echo htmlspecialchars($role); ?></div>
                                            </td>
                                            <td><?php echo (int)($roleCounts[$role] ?? 0); ?></td>
                                            <td>
                                                <div class="btn-list flex-nowrap">
                                                    <button type="button" class="btn btn-sm btn-outline-primary js-edit-role" data-role="<?php echo htmlspecialchars($role); ?>">Bearbeiten</button>
                                                    <form method="post" class="m-0 js-delete-role-form" data-role-label="<?php echo htmlspecialchars($roleLabels[$role] ?? $formatLabel($role), ENT_QUOTES); ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="delete_role">
                                                        <input type="hidden" name="role_slug" value="<?php echo htmlspecialchars($role); ?>">
                                                        <input type="hidden" name="fallback_role" value="member">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                                                    </form>
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
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h3 class="card-title">Eigene Rechte</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th>Bereich</th>
                                    <th>Recht</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($customCapabilities)): ?>
                                    <tr><td colspan="3" class="text-center text-secondary py-4">Noch keine eigenen Rechte angelegt.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($customCapabilities as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($groupLabels[$item['group']] ?? $formatLabel((string)$item['group'])); ?></td>
                                            <td><code><?php echo htmlspecialchars((string)$item['capability']); ?></code></td>
                                            <td>
                                                <div class="btn-list flex-nowrap">
                                                    <button type="button" class="btn btn-sm btn-outline-primary js-edit-capability" data-capability="<?php echo htmlspecialchars((string)$item['capability']); ?>">Bearbeiten</button>
                                                    <form method="post" class="m-0 js-delete-capability-form" data-capability-label="<?php echo htmlspecialchars((string)$item['capability'], ENT_QUOTES); ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="delete_capability">
                                                        <input type="hidden" name="capability_slug" value="<?php echo htmlspecialchars((string)$item['capability']); ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                                                    </form>
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

        <?php if (!empty($roleComparison['available'])): ?>
            <?php
            $comparisonFromRole = (string)($roleComparison['from_role'] ?? '');
            $comparisonToRole = (string)($roleComparison['to_role'] ?? '');
            $comparisonFromLabel = $roleLabels[$comparisonFromRole] ?? $formatLabel($comparisonFromRole);
            $comparisonToLabel = $roleLabels[$comparisonToRole] ?? $formatLabel($comparisonToRole);
            ?>
            <div class="card mb-4">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h3 class="card-title mb-0">Rollenvergleich / Capability-Diff</h3>
                        <div class="text-secondary small">Read-only Vergleich der aktuell geladenen Rechte-Matrix – ohne Speichern oder Tokenwechsel.</div>
                    </div>
                    <form method="get" action="/admin/roles" class="d-flex flex-wrap align-items-end gap-2" aria-label="Rollenvergleich auswählen">
                        <div>
                            <label class="form-label small mb-1" for="compare_from">Basisrolle</label>
                            <select class="form-select form-select-sm" id="compare_from" name="compare_from">
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role, ENT_QUOTES); ?>" <?php echo $role === $comparisonFromRole ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($roleLabels[$role] ?? $formatLabel($role)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label small mb-1" for="compare_to">Vergleichsrolle</label>
                            <select class="form-select form-select-sm" id="compare_to" name="compare_to">
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role, ENT_QUOTES); ?>" <?php echo $role === $comparisonToRole ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($roleLabels[$role] ?? $formatLabel($role)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-primary">Vergleichen</button>
                    </form>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-sm-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">Gemeinsame Rechte</div>
                                <div class="h2 mb-0"><?php echo (int)($roleComparison['shared_count'] ?? 0); ?></div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">Nur <?php echo htmlspecialchars($comparisonFromLabel); ?></div>
                                <div class="h2 mb-0"><?php echo (int)($roleComparison['from_only_count'] ?? 0); ?></div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">Nur <?php echo htmlspecialchars($comparisonToLabel); ?></div>
                                <div class="h2 mb-0"><?php echo (int)($roleComparison['to_only_count'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="border rounded p-3 h-100">
                                <h4 class="mb-2">Zusätzliche Rechte in <?php echo htmlspecialchars($comparisonFromLabel); ?></h4>
                                <?php echo $renderCapabilityDiffList(is_array($roleComparison['from_only_by_group'] ?? null) ? $roleComparison['from_only_by_group'] : []); ?>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="border rounded p-3 h-100">
                                <h4 class="mb-2">Zusätzliche Rechte in <?php echo htmlspecialchars($comparisonToLabel); ?></h4>
                                <?php echo $renderCapabilityDiffList(is_array($roleComparison['to_only_by_group'] ?? null) ? $roleComparison['to_only_by_group'] : []); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Permissions Matrix -->
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="save_permissions">

            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h3 class="card-title mb-1">Berechtigungsmatrix</h3>
                        <div class="text-secondary small">
                            Rechte der Rollen können per Klick ausgewählt werden. Administratoren besitzen immer alle Rechte.
                        </div>
                    </div>
                    <div class="btn-list">
                        <button type="submit" name="action" value="reset_permissions" class="btn btn-outline-secondary"
                                data-cms-confirm="Alle individuellen Rechte werden durch die Standardrechte ersetzt. Fortfahren?">
                            Standardrechte wiederherstellen
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-device-floppy" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 4h10l4 4v10a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2"/><path d="M12 14m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"/><path d="M14 4l0 4l-6 0l0 -4"/></svg>
                            Speichern
                        </button>
                    </div>
                </div>
                <div class="table-responsive roles-permissions-matrix-wrap">
                    <table class="table table-vcenter card-table table-striped roles-permissions-matrix">
                        <thead>
                            <tr>
                                <th style="min-width: 200px;">Berechtigung</th>
                                <?php foreach ($roles as $role): ?>
                                    <th class="text-center" style="min-width: 120px;">
                                        <?php echo htmlspecialchars($roleLabels[$role] ?? $formatLabel($role)); ?>
                                        <div class="small text-muted"><?php echo (int)($roleCounts[$role] ?? 0); ?> Benutzer</div>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($capabilities as $group => $caps): ?>
                                <!-- Group Header -->
                                <tr class="bg-light">
                                    <td colspan="<?php echo count($roles) + 1; ?>">
                                        <strong><?php echo htmlspecialchars($groupLabels[$group] ?? $formatLabel($group)); ?></strong>
                                        <button type="button" class="btn btn-sm btn-ghost-secondary ms-2 toggle-group" data-group="<?php echo htmlspecialchars($group); ?>">
                                            Alle umschalten
                                        </button>
                                    </td>
                                </tr>
                                <?php foreach ($caps as $cap):
                                    $capParts = explode('.', $cap);
                                    $shortCap = end($capParts);
                                ?>
                                    <tr>
                                        <td>
                                            <span class="text-muted me-1"><?php echo htmlspecialchars($groupLabels[$group] ?? $formatLabel($group)); ?> →</span>
                                            <?php echo htmlspecialchars($capLabels[$shortCap] ?? $formatLabel($shortCap)); ?>
                                            <div class="small text-muted"><?php echo htmlspecialchars($cap); ?></div>
                                        </td>
                                        <?php foreach ($roles as $role): ?>
                                            <td class="text-center">
                                                <?php if ($role === 'admin'): ?>
                                                    <input type="hidden" name="permissions[admin][<?php echo htmlspecialchars($cap); ?>]" value="1">
                                                    <input type="checkbox" class="form-check-input" checked disabled aria-disabled="true" title="Admin hat immer alle Rechte">
                                                <?php else: ?>
                                                    <?php $isGranted = !empty($permissions[$role][$cap]); ?>
                                                    <label class="roles-permission-toggle"
                                                           for="permission-<?php echo htmlspecialchars($role . '-' . $cap, ENT_QUOTES); ?>">
                                                        <input type="checkbox"
                                                               id="permission-<?php echo htmlspecialchars($role . '-' . $cap, ENT_QUOTES); ?>"
                                                               class="form-check-input cap-checkbox"
                                                               style="display:block!important; opacity:1!important; visibility:visible!important; pointer-events:auto!important;"
                                                               name="permissions[<?php echo htmlspecialchars($role); ?>][<?php echo htmlspecialchars($cap); ?>]"
                                                               value="1"
                                                               data-group="<?php echo htmlspecialchars($group); ?>"
                                                               data-role="<?php echo htmlspecialchars($role); ?>"
                                                               <?php echo $isGranted ? 'checked' : ''; ?>>
                                                        <span class="roles-permission-toggle__text">
                                                            <?php echo $isGranted ? 'Erlaubt' : 'Nicht erlaubt'; ?>
                                                        </span>
                                                    </label>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer text-end">
                    <button type="submit" class="btn btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-device-floppy" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 4h10l4 4v10a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2"/><path d="M12 14m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"/><path d="M14 4l0 4l-6 0l0 -4"/></svg>
                        Berechtigungen speichern
                    </button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary roles-floating-save" aria-label="Berechtigungen speichern">
                Speichern
            </button>
        </form>
    </div>
</div>

<div class="modal modal-blur fade" id="addRoleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Neue Rolle anlegen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="add_role">
                    <div class="mb-3">
                        <label class="form-label" for="role_slug">Rollen-Slug</label>
                        <input type="text" class="form-control" id="role_slug" name="role_slug" placeholder="z. B. moderator" required>
                        <div class="form-hint">Nur Kleinbuchstaben, Zahlen, Bindestriche und Unterstriche.</div>
                    </div>
                    <div>
                        <label class="form-label" for="copy_role">Rechte übernehmen von</label>
                        <select class="form-select" id="copy_role" name="copy_role">
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo htmlspecialchars($role); ?>" <?php echo $role === 'member' ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($roleLabels[$role] ?? $formatLabel($role)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Rolle anlegen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal modal-blur fade" id="addCapabilityModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Neues Recht anlegen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="add_capability">
                    <div class="mb-0">
                        <label class="form-label" for="capability_slug">Recht</label>
                        <input type="text" class="form-control" id="capability_slug" name="capability_slug" placeholder="z. B. shop.orders.view" required>
                        <div class="form-hint">Format: <code>bereich.aktion</code> oder <code>bereich.unterbereich.aktion</code>.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Recht anlegen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal modal-blur fade" id="editRoleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Rolle bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="update_role">
                    <input type="hidden" name="current_role" id="edit_role_current" value="">
                    <div class="mb-0">
                        <label class="form-label" for="edit_role_slug">Rollen-Slug</label>
                        <input type="text" class="form-control" id="edit_role_slug" name="role_slug" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Rolle speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal modal-blur fade" id="deleteRoleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Rolle löschen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="delete_role">
                    <input type="hidden" name="role_slug" id="delete_role_slug" value="">
                    <p class="text-secondary">Benutzer dieser Rolle werden auf die gewählte Ersatzrolle umgestellt.</p>
                    <div>
                        <label class="form-label" for="fallback_role">Ersatzrolle</label>
                        <select class="form-select" id="fallback_role" name="fallback_role">
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo htmlspecialchars($role); ?>" <?php echo $role === 'member' ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($roleLabels[$role] ?? $formatLabel($role)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-danger">Rolle löschen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal modal-blur fade" id="editCapabilityModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Recht bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="update_capability">
                    <input type="hidden" name="current_capability" id="edit_capability_current" value="">
                    <div class="mb-0">
                        <label class="form-label" for="edit_capability_slug">Recht</label>
                        <input type="text" class="form-control" id="edit_capability_slug" name="capability_slug" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Recht speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal modal-blur fade" id="deleteCapabilityModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Recht löschen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="delete_capability">
                    <input type="hidden" name="capability_slug" id="delete_capability_slug" value="">
                    <p class="text-secondary mb-0">Das ausgewählte eigene Recht wird aus allen Rollen entfernt.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-danger">Recht löschen</button>
                </div>
            </form>
        </div>
    </div>
</div>
