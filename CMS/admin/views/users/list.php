<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Users – Listenansicht
 *
 * Erwartet: $data, $alert, $csrfToken
 */

$users   = $data['users'] ?? [];
$stats   = $data['stats'] ?? [];
$availableRoles = $data['availableRoles'] ?? [];
$availableStatuses = $data['availableStatuses'] ?? [];
$filter  = $data['filter'] ?? [];
$total   = $data['total'] ?? 0;
$curPage = $data['page'] ?? 1;
$pages   = $data['pages'] ?? 1;
$usersAdminPath = '/admin/users';

$roleColors = [
    'admin' => 'red',
    'editor' => 'blue',
    'author' => 'green',
    'member' => 'secondary',
];

$getRoleColor = static function (string $role) use ($roleColors): string {
    return $roleColors[$role] ?? 'azure';
};

$formatGermanDate = static function (string $dateValue): string {
    if ($dateValue === '') {
        return '–';
    }

    $timestamp = strtotime($dateValue);
    if ($timestamp === false) {
        return htmlspecialchars($dateValue);
    }

    return date('d.m.Y', $timestamp);
};

$renderInlineToggleList = static function (array $items, string $emptyLabel, int $collapsedCount = 3): string {
    $cleanItems = array_values(array_filter(
        array_map(static fn (mixed $item): string => trim((string)$item), $items),
        static fn (string $item): bool => $item !== ''
    ));

    if ($cleanItems === []) {
        return htmlspecialchars($emptyLabel);
    }

    if (count($cleanItems) <= $collapsedCount) {
        return htmlspecialchars(implode(', ', $cleanItems));
    }

    $collapsedItems = array_slice($cleanItems, 0, $collapsedCount);
    $collapsedText = implode(', ', $collapsedItems);
    $fullText = implode(', ', $cleanItems);

    return sprintf(
        '<span class="js-inline-toggle-list" data-collapsed="%s" data-expanded="%s"><span class="js-inline-toggle-list-text">%s</span> <button type="button" class="btn btn-link p-0 align-baseline js-inline-toggle-list-button" data-expand-label="Alle anzeigen" data-collapse-label="Weniger anzeigen" aria-expanded="false">Alle anzeigen</button></span>',
        htmlspecialchars($collapsedText, ENT_QUOTES),
        htmlspecialchars($fullText, ENT_QUOTES),
        htmlspecialchars($collapsedText)
    );
};
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Benutzer & Gruppen</div>
                <h2 class="page-title mb-1">Benutzer</h2>
                <div class="content-listing-header__meta">
                    <span><?php echo (int)($stats['total_users'] ?? 0); ?> Gesamt</span>
                    <span><?php echo (int)($stats['active_users'] ?? 0); ?> aktiv</span>
                    <span><?php echo (int)($stats['inactive_users'] ?? 0); ?> inaktiv</span>
                    <span><?php echo (int)($stats['banned_users'] ?? 0); ?> gesperrt</span>
                </div>
            </div>
            <div>
                <a href="<?php echo htmlspecialchars($usersAdminPath); ?>?action=edit" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14"/><path d="M5 12l14 0"/></svg>
                    Neuer Benutzer
                </a>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">

        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-3'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <div class="cms-admin-info-box mb-3" role="note">
            <div class="cms-admin-info-box__head">
                <h3 class="cms-admin-info-box__title">Benutzerverwaltung</h3>
                <div class="cms-admin-info-box__actions">
                    <a href="/admin/groups" class="btn btn-sm btn-outline-secondary">Gruppen</a>
                    <a href="/admin/roles" class="btn btn-sm btn-outline-secondary">Rollen</a>
                    <a href="/admin/user-settings" class="btn btn-sm btn-outline-secondary">Einstellungen</a>
                </div>
            </div>
            <p class="cms-admin-info-box__text">
                Nutzer schnell finden, filtern und direkt im Alltag verwalten.
            </p>
        </div>

        <div class="card content-listing-card">
            <div class="card-header content-listing-toolbar">
                <div class="content-listing-toolbar__label">Filter &amp; Suche</div>
                <div class="content-listing-filters">
                    <div class="content-listing-filters__group">
                        <select class="form-select form-select-sm js-users-filter-role" aria-label="Nach Rolle filtern" data-users-base-url="<?php echo htmlspecialchars($usersAdminPath, ENT_QUOTES); ?>">
                            <option value="">Alle Rollen</option>
                            <?php foreach ($availableRoles as $role => $label): ?>
                                <option value="<?php echo htmlspecialchars((string)$role); ?>" <?php if (($filter['role'] ?? '') === $role) echo 'selected'; ?>><?php echo htmlspecialchars((string)$label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="content-listing-filters__group">
                        <select class="form-select form-select-sm js-users-filter-status" aria-label="Nach Status filtern" data-users-base-url="<?php echo htmlspecialchars($usersAdminPath, ENT_QUOTES); ?>">
                            <option value="">Alle Status</option>
                            <?php foreach ($availableStatuses as $status => $label): ?>
                                <option value="<?php echo htmlspecialchars((string)$status); ?>" <?php if (($filter['status'] ?? '') === $status) echo 'selected'; ?>><?php echo htmlspecialchars((string)$label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="content-listing-filters__search">
                        <form method="get" action="<?php echo htmlspecialchars($usersAdminPath); ?>" class="d-flex gap-2 js-users-search-form" data-users-base-url="<?php echo htmlspecialchars($usersAdminPath, ENT_QUOTES); ?>">
                            <input type="hidden" name="role" value="<?php echo htmlspecialchars($filter['role']); ?>">
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter['status']); ?>">
                            <input type="text" class="form-control form-control-sm js-users-search-input" name="q" aria-label="Benutzer suchen" value="<?php echo htmlspecialchars($filter['search']); ?>" placeholder="Suchen…">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Suchen</button>
                        </form>
                    </div>
                    <div class="content-listing-filters__actions">
                        <span class="text-secondary small"><?php echo $total; ?> Benutzer</span>
                    </div>
                </div>
            </div>

            <div class="card-body py-2 d-none" id="bulkBarUsers">
                <form method="post" id="bulkFormUsers" class="d-flex flex-wrap align-items-center gap-2">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="bulk">
                    <span class="text-secondary"><strong id="selectedCountUsers">0</strong> ausgewählt</span>
                    <select name="bulk_action" class="form-select form-select-sm w-auto" aria-label="Bulk-Aktion für ausgewählte Benutzer">
                        <option value="">Aktion wählen…</option>
                        <option value="activate">Aktivieren</option>
                        <option value="deactivate">Deaktivieren</option>
                        <option value="hard_delete">Dauerhaft löschen</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary" disabled aria-disabled="true">Aktion wählen…</button>
                </form>
            </div>

            <div class="table-responsive" id="usersListRoot">
                <table class="table table-vcenter card-table content-listing-table">
                    <thead>
                        <tr>
                            <th class="w-1">
                                <label class="form-check m-0">
                                    <input type="checkbox" class="form-check-input bulk-select-all" aria-label="Alle sichtbaren Benutzer auswählen">
                                </label>
                            </th>
                            <th>Benutzer</th>
                            <th>E-Mail</th>
                            <th>Rolle</th>
                            <th>Gruppen</th>
                            <th>Support-Kontext</th>
                            <th>Status</th>
                            <th>Registriert</th>
                            <th class="w-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($users)): ?>
                        <?php
                        $emptyStateColspan = 9;
                        $emptyStateMessage = 'Keine Benutzer gefunden.';
                        $emptyStateSubtitle = 'Prüfen Sie Filter oder Suche – die serverseitige Liste liefert aktuell keine Einträge.';
                        $emptyStateIcon = 'users';
                        require __DIR__ . '/../partials/empty-table-row.php';
                        ?>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <?php
                            $userId = (int)($user->id ?? 0);
                            $username = trim((string)($user->username ?? ''));
                            $displayName = trim((string)($user->display_name ?? ''));
                            $email = trim((string)($user->email ?? ''));
                            $role = trim((string)($user->role ?? 'member'));
                            $status = trim((string)($user->status ?? 'inactive'));
                            $createdAt = trim((string)($user->created_at ?? ''));
                            $roleLabel = (string)($availableRoles[$role] ?? ucfirst($role));
                            $groupCount = (int)($user->group_count ?? 0);
                            $supportContext = is_array($user->support_context ?? null) ? $user->support_context : [];
                            $directPackage = trim((string)($supportContext['direct_package'] ?? ''));
                            $groupPackages = is_array($supportContext['group_packages'] ?? null) ? $supportContext['group_packages'] : [];
                            $groupPackageCount = (int)($supportContext['group_package_count'] ?? count($groupPackages));
                            $memberModules = is_array($supportContext['member_modules'] ?? null) ? $supportContext['member_modules'] : [];
                            $memberModuleCount = (int)($supportContext['member_module_count'] ?? count($memberModules));
                            $contractLabel = trim((string)($supportContext['contract_label'] ?? 'Keine aktive Frist'));
                            $contractSeverity = trim((string)($supportContext['contract_severity'] ?? 'secondary'));
                            $contractBadgeClass = match ($contractSeverity) {
                                'danger' => 'bg-red-lt text-red',
                                'warning' => 'bg-yellow-lt text-yellow',
                                'info' => 'bg-blue-lt text-blue',
                                default => 'bg-secondary-lt text-secondary',
                            };
                            $statusLabel = match ($status) {
                                'active' => 'Aktiv',
                                'inactive' => 'Inaktiv',
                                'banned' => 'Gesperrt',
                                default => $status !== '' ? ucfirst($status) : '–',
                            };
                            $statusClass = match ($status) {
                                'active' => 'bg-green-lt text-green',
                                'inactive' => 'bg-yellow-lt text-yellow',
                                'banned' => 'bg-red-lt text-red',
                                default => 'bg-secondary-lt text-secondary',
                            };
                            $initials = strtoupper(substr($username !== '' ? $username : 'U', 0, 2));
                            ?>
                            <tr class="content-listing-table__row">
                                <td>
                                    <label class="form-check m-0">
                                        <input class="form-check-input bulk-row-check" type="checkbox" value="<?php echo $userId; ?>" aria-label="Benutzer <?php echo htmlspecialchars($username !== '' ? $username : 'Unbekannt', ENT_QUOTES); ?> auswählen">
                                    </label>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="avatar avatar-sm me-2 bg-<?php echo htmlspecialchars($getRoleColor($role)); ?> text-white"><?php echo htmlspecialchars($initials); ?></span>
                                        <div>
                                            <a href="<?php echo htmlspecialchars($usersAdminPath); ?>?action=edit&amp;id=<?php echo $userId; ?>" class="text-reset fw-medium"><?php echo htmlspecialchars($username !== '' ? $username : 'Unbekannt'); ?></a>
                                            <?php if ($displayName !== ''): ?>
                                                <div class="text-secondary small"><?php echo htmlspecialchars($displayName); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($email !== '' ? $email : '–'); ?></td>
                                <td><span class="badge bg-azure-lt"><?php echo htmlspecialchars($roleLabel); ?></span></td>
                                <td>
                                    <span class="badge <?php echo $groupCount > 0 ? 'bg-blue-lt text-blue' : 'bg-secondary-lt text-secondary'; ?>">
                                        <?php echo $groupCount > 0 ? (int)$groupCount . ' Gruppen' : 'Keine'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-1 small">
                                        <div class="d-flex flex-wrap gap-1 align-items-center">
                                            <span class="badge <?php echo $directPackage !== '' ? 'bg-purple-lt text-purple' : 'bg-secondary-lt text-secondary'; ?>">
                                                <?php echo htmlspecialchars($directPackage !== '' ? $directPackage : 'Kein Direktpaket'); ?>
                                            </span>
                                            <span class="badge <?php echo htmlspecialchars($contractBadgeClass); ?>">
                                                <?php echo htmlspecialchars($contractLabel !== '' ? $contractLabel : 'Keine aktive Frist'); ?>
                                            </span>
                                        </div>
                                        <div class="text-secondary">
                                            Gruppenpakete:
                                            <?php if ($groupPackages === []): ?>
                                                keine
                                            <?php else: ?>
                                                <?php echo $renderInlineToggleList($groupPackages, 'keine'); ?>
                                                <?php if ($groupPackageCount > count($groupPackages)): ?>
                                                    <span class="text-muted"> (insgesamt <?php echo $groupPackageCount; ?>)</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-secondary">
                                            Member-Module:
                                            <?php echo $renderInlineToggleList($memberModules, 'keine'); ?>
                                            <?php if ($memberModuleCount > count($memberModules)): ?>
                                                <span class="text-muted"> (insgesamt <?php echo $memberModuleCount; ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge <?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                <td class="text-secondary"><?php echo $formatGermanDate($createdAt); ?></td>
                                <td>
                                    <div class="d-flex gap-2 justify-content-end">
                                        <a href="<?php echo htmlspecialchars($usersAdminPath); ?>?action=edit&amp;id=<?php echo $userId; ?>" class="btn btn-sm btn-outline-primary">Bearbeiten</a>
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

