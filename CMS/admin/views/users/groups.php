<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Users – Gruppen View
 *
 * Erwartet: $data, $alert, $csrfToken
 */

$groups  = $data['groups'] ?? [];
$userOptions = $data['userOptions'] ?? [];
$planOptions = $data['planOptions'] ?? [];

/** @param object|array<string,mixed> $group */
$groupField = static function (mixed $group, string $key, mixed $default = ''): mixed {
    if (is_array($group)) {
        return $group[$key] ?? $default;
    }

    if (is_object($group) && isset($group->{$key})) {
        return $group->{$key};
    }

    return $default;
};
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Benutzer & Gruppen</div>
                <h2 class="page-title mb-1">Gruppen</h2>
                <div class="content-listing-header__meta">
                    <span><?php echo count($groups); ?> Gruppen</span>
                    <span><?php echo count($userOptions); ?> verfügbare Benutzer</span>
                    <span><?php echo count($planOptions); ?> Paketoptionen</span>
                </div>
            </div>
            <div>
                <button type="button" class="btn btn-primary js-group-modal-trigger" data-bs-toggle="modal" data-bs-target="#groupModal" data-group-mode="create" data-group-id="0" data-group-name="" data-group-description="" data-group-modal-title="Neue Gruppe">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14"/><path d="M5 12l14 0"/></svg>
                    Neue Gruppe
                </button>
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
                <h3 class="cms-admin-info-box__title">Gruppen und Mitgliedschaften</h3>
                <div class="cms-admin-info-box__actions">
                    <a href="/admin/users" class="btn btn-sm btn-outline-secondary">Benutzer</a>
                    <a href="/admin/roles" class="btn btn-sm btn-outline-secondary">Rollen</a>
                    <a href="/admin/user-settings" class="btn btn-sm btn-outline-secondary">Einstellungen</a>
                </div>
            </div>
            <p class="cms-admin-info-box__text">
                Gruppen zentral pflegen und Mitglieder übersichtlich organisieren.
            </p>
        </div>

        <?php if (!empty($groups)): ?>
            <div class="card content-listing-card mb-4">
                <div class="card-header content-listing-toolbar">
                    <div class="content-listing-toolbar__label">Sammelaktionen</div>
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <label class="form-check m-0">
                            <input type="checkbox" class="form-check-input js-groups-bulk-all" aria-label="Alle sichtbaren Gruppen auswählen">
                            <span class="form-check-label">Alle auswählen</span>
                        </label>

                        <button type="button" class="btn btn-sm btn-outline-secondary js-groups-bulk-clear" disabled aria-disabled="true">Auswahl leeren</button>

                        <form method="post" id="bulkFormGroups" class="d-flex flex-wrap align-items-center gap-2 ms-lg-auto">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="bulk">

                            <span class="text-secondary"><strong id="selectedCountGroups">0</strong> ausgewählt</span>

                            <select name="bulk_action" class="form-select form-select-sm w-auto" id="bulkActionGroups" aria-label="Bulk-Aktion für ausgewählte Gruppen">
                                <option value="">Aktion wählen…</option>
                                <option value="activate">Gruppen aktivieren</option>
                                <option value="deactivate">Gruppen deaktivieren</option>
                                <?php if (!empty($planOptions)): ?>
                                    <option value="set_plan">Paket zuweisen</option>
                                <?php endif; ?>
                                <option value="clear_plan">Paket entfernen</option>
                                <option value="delete">Gruppen löschen</option>
                            </select>

                            <div class="d-none" id="bulkGroupsPlanWrap">
                                <select name="bulk_plan_id" class="form-select form-select-sm w-auto" id="bulkGroupsPlanSelect" aria-label="Paket für Sammelaktion auswählen">
                                    <option value="0">Paket wählen…</option>
                                    <?php foreach ($planOptions as $planOption): ?>
                                        <?php
                                        $planOptionId = (int)($planOption['id'] ?? 0);
                                        $planOptionName = trim((string)($planOption['name'] ?? ''));
                                        $planOptionActive = (int)($planOption['is_active'] ?? 0) === 1;
                                        ?>
                                        <option value="<?php echo $planOptionId; ?>">
                                            <?php echo htmlspecialchars($planOptionName !== '' ? $planOptionName : ('Plan #' . $planOptionId)); ?><?php echo $planOptionActive ? '' : ' (inaktiv)'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-sm btn-primary" disabled aria-disabled="true">Aktion wählen…</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="groups-grid" id="groupsListRoot">
            <?php if (empty($groups)): ?>
                <div class="groups-grid__empty">
                    <div class="card">
                        <div class="card-body">
                            <div class="empty">
                                <p class="empty-title">Keine Gruppen vorhanden</p>
                                <p class="empty-subtitle text-secondary">Erstellen Sie eine neue Gruppe, um Benutzer zu organisieren.</p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($groups as $group): ?>
                    <?php
                    $groupId = (int) $groupField($group, 'id', 0);
                    $groupName = (string) $groupField($group, 'name', '');
                    $groupSlug = (string) $groupField($group, 'slug', '');
                    $groupDescription = (string) $groupField($group, 'description', '');
                    $groupMemberCount = (int) $groupField($group, 'member_count', 0);
                    $groupIsActive = (int) $groupField($group, 'is_active', 1) === 1;
                    $groupPlanId = (int) $groupField($group, 'plan_id', 0);
                    $groupPlanName = (string) $groupField($group, 'plan_name', '');
                    $groupMembers = $groupField($group, 'members', []);
                    $groupMemberIds = $groupField($group, 'member_ids', []);
                    $supportContext = $groupField($group, 'support_context', []);
                    $supportContext = is_array($supportContext) ? $supportContext : [];
                    $planModules = is_array($supportContext['plan_modules'] ?? null) ? $supportContext['plan_modules'] : [];
                    $planModuleCount = (int)($supportContext['plan_module_count'] ?? count($planModules));
                    $memberModules = is_array($supportContext['member_modules'] ?? null) ? $supportContext['member_modules'] : [];
                    $memberModuleCount = (int)($supportContext['member_module_count'] ?? count($memberModules));
                    $expiringContracts = is_array($supportContext['expiring_contracts'] ?? null) ? $supportContext['expiring_contracts'] : [];
                    $expiringContractCount = (int)($supportContext['expiring_contract_count'] ?? count($expiringContracts));
                    $overdueContractCount = (int)($supportContext['overdue_contract_count'] ?? 0);
                    ?>
                    <div class="groups-grid__item">
                        <div class="card h-100 group-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <span class="avatar bg-blue-lt me-3">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 13a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/><path d="M8 21v-1a2 2 0 0 1 2 -2h4a2 2 0 0 1 2 2v1"/><path d="M15 5a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/><path d="M17 10h2a2 2 0 0 1 2 2v1"/><path d="M5 5a2 2 0 1 0 4 0a2 4 0 0 0 -4 0"/><path d="M3 13v-1a2 2 0 0 1 2 -2h2"/></svg>
                                    </span>
                                    <div>
                                        <h3 class="card-title mb-0"><?php echo htmlspecialchars($groupName); ?></h3>
                                        <div class="text-secondary small d-flex flex-wrap gap-2 align-items-center">
                                            <span><?php echo $groupMemberCount; ?> Mitglieder</span>
                                            <span class="badge <?php echo $groupIsActive ? 'bg-green-lt text-green' : 'bg-secondary-lt text-secondary'; ?>"><?php echo $groupIsActive ? 'Aktiv' : 'Inaktiv'; ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($groupSlug !== ''): ?>
                                    <div class="mb-2 text-secondary small">Slug: <code><?php echo htmlspecialchars($groupSlug); ?></code></div>
                                <?php endif; ?>
                                <?php if ($groupPlanId > 0 && $groupPlanName !== ''): ?>
                                    <div class="mb-2"><span class="badge bg-purple-lt text-purple">Paket: <?php echo htmlspecialchars($groupPlanName); ?></span></div>
                                <?php endif; ?>
                                <?php if ($groupDescription !== ''): ?>
                                    <p class="text-secondary mb-3"><?php echo htmlspecialchars($groupDescription); ?></p>
                                <?php endif; ?>
                                <div class="border rounded p-2 mb-3 bg-light-subtle">
                                    <div class="small fw-semibold mb-2">Support-Kontext</div>
                                    <div class="d-flex flex-wrap gap-1 mb-2">
                                        <span class="badge <?php echo $groupPlanId > 0 ? 'bg-purple-lt text-purple' : 'bg-secondary-lt text-secondary'; ?>">
                                            <?php echo htmlspecialchars($groupPlanId > 0 && $groupPlanName !== '' ? 'Paket: ' . $groupPlanName : 'Kein Gruppenpaket'); ?>
                                        </span>
                                        <span class="badge <?php echo $expiringContractCount > 0 ? ($overdueContractCount > 0 ? 'bg-red-lt text-red' : 'bg-yellow-lt text-yellow') : 'bg-secondary-lt text-secondary'; ?>">
                                            <?php if ($expiringContractCount > 0): ?>
                                                <?php echo $expiringContractCount; ?> fällige Verträge<?php echo $overdueContractCount > 0 ? ' · ' . $overdueContractCount . ' überfällig' : ''; ?>
                                            <?php else: ?>
                                                Keine fälligen Verträge
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="small text-secondary mb-1">
                                        Paketmodule:
                                        <?php echo htmlspecialchars($planModules !== [] ? implode(', ', array_map('strval', $planModules)) : 'keine Paketmodule'); ?><?php if ($planModuleCount > count($planModules)): ?>, +<?php echo $planModuleCount - count($planModules); ?> weitere<?php endif; ?>
                                    </div>
                                    <div class="small text-secondary mb-2">
                                        Member-Module:
                                        <?php echo htmlspecialchars($memberModules !== [] ? implode(', ', array_map('strval', $memberModules)) : 'keine'); ?><?php if ($memberModuleCount > count($memberModules)): ?>, +<?php echo $memberModuleCount - count($memberModules); ?> weitere<?php endif; ?>
                                    </div>
                                    <?php if ($expiringContracts !== []): ?>
                                        <div class="small text-secondary">Nächste Vertragsfristen:</div>
                                        <ul class="small ps-3 mb-0">
                                            <?php foreach ($expiringContracts as $contract): ?>
                                                <?php
                                                $contractSeverity = (string)($contract['severity'] ?? 'warning');
                                                $contractClass = $contractSeverity === 'danger' ? 'text-red' : 'text-yellow';
                                                ?>
                                                <li class="<?php echo htmlspecialchars($contractClass); ?>">
                                                    <?php echo htmlspecialchars((string)($contract['user_label'] ?? 'Benutzer')); ?> · <?php echo htmlspecialchars((string)($contract['plan_label'] ?? 'Paket')); ?> · <?php echo htmlspecialchars((string)($contract['label'] ?? 'fällig')); ?>
                                                </li>
                                            <?php endforeach; ?>
                                            <?php if ($expiringContractCount > count($expiringContracts)): ?>
                                                <li class="text-secondary">+<?php echo $expiringContractCount - count($expiringContracts); ?> weitere fällige Verträge</li>
                                            <?php endif; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($groupMembers) && is_array($groupMembers)): ?>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach (array_slice($groupMembers, 0, 4) as $member): ?>
                                            <?php
                                            $memberName = trim((string)($member['display_name'] ?? ''));
                                            if ($memberName === '') {
                                                $memberName = trim((string)($member['username'] ?? ''));
                                            }
                                            ?>
                                            <span class="badge bg-blue-lt text-blue"><?php echo htmlspecialchars($memberName !== '' ? $memberName : 'Benutzer'); ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($groupMembers) > 4): ?>
                                            <span class="badge bg-secondary-lt text-secondary">+<?php echo count($groupMembers) - 4; ?> weitere</span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-secondary mb-0 small">Aktuell sind noch keine Mitglieder zugeordnet.</p>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                <label class="form-check m-0">
                                    <input type="checkbox" class="form-check-input js-groups-bulk-row" value="<?php echo $groupId; ?>" aria-label="Gruppe <?php echo htmlspecialchars($groupName, ENT_QUOTES); ?> auswählen">
                                    <span class="form-check-label small">Auswählen</span>
                                </label>

                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm js-group-modal-trigger"
                                        data-bs-toggle="modal"
                                        data-bs-target="#groupModal"
                                            data-group-mode="edit"
                                            data-group-id="<?php echo $groupId; ?>"
                                            data-group-name="<?php echo htmlspecialchars($groupName, ENT_QUOTES); ?>"
                                            data-group-slug="<?php echo htmlspecialchars($groupSlug, ENT_QUOTES); ?>"
                                            data-group-description="<?php echo htmlspecialchars($groupDescription, ENT_QUOTES); ?>"
                                            data-group-plan-id="<?php echo $groupPlanId; ?>"
                                            data-group-is-active="<?php echo $groupIsActive ? '1' : '0'; ?>"
                                            data-group-member-ids="<?php echo htmlspecialchars((string)json_encode($groupMemberIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?>"
                                            data-group-modal-title="Gruppe bearbeiten">
                                        Bearbeiten
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm js-delete-group group-card__delete-btn"
                                            data-group-id="<?php echo $groupId; ?>"
                                            data-group-name="<?php echo htmlspecialchars($groupName, ENT_QUOTES); ?>">
                                        Löschen
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Gruppe erstellen/bearbeiten Modal -->
<div class="modal modal-blur fade" id="groupModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="post" class="modal-content" id="groupForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="groupId">
            <div class="modal-header">
                <h5 class="modal-title" id="groupModalTitle">Neue Gruppe</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label required" for="groupName">Name</label>
                    <input type="text" class="form-control" id="groupName" name="name" required maxlength="120" autocomplete="organization-title">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="groupSlug">Slug</label>
                    <input type="text" class="form-control" id="groupSlug" name="slug" maxlength="100" pattern="[a-z0-9-]*" autocomplete="off">
                    <div class="form-hint">Leer lassen, um aus dem Namen automatisch einen eindeutigen Slug zu erzeugen.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="groupDesc">Beschreibung</label>
                    <textarea class="form-control" id="groupDesc" name="description" rows="3" maxlength="500"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="groupPlan">Paket / Plan</label>
                    <select class="form-select" id="groupPlan" name="plan_id">
                        <option value="0">Kein Paket verknüpfen</option>
                        <?php foreach ($planOptions as $planOption): ?>
                            <?php
                            $planOptionId = (int)($planOption['id'] ?? 0);
                            $planOptionName = trim((string)($planOption['name'] ?? ''));
                            $planOptionActive = (int)($planOption['is_active'] ?? 0) === 1;
                            ?>
                            <option value="<?php echo $planOptionId; ?>">
                                <?php echo htmlspecialchars($planOptionName !== '' ? $planOptionName : ('Plan #' . $planOptionId)); ?><?php echo $planOptionActive ? '' : ' (inaktiv)'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint">Verknüpfte Gruppenpakete können in der Abo-Runtime als Benutzergruppen-Subscription ausgewertet werden.</div>
                </div>
                <div class="mb-3">
                    <label class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="groupIsActive" name="is_active" value="1" checked>
                        <span class="form-check-label">Gruppe aktiv schalten</span>
                    </label>
                </div>
                <div>
                    <label class="form-label">Mitglieder</label>
                    <div class="border rounded p-3" style="max-height: 18rem; overflow:auto;">
                        <?php if (empty($userOptions)): ?>
                            <p class="text-secondary mb-0 small">Es stehen aktuell keine Benutzer zur Auswahl.</p>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-2">
                                <?php foreach ($userOptions as $userOption): ?>
                                    <?php
                                    $optionId = (int)($userOption['id'] ?? 0);
                                    $optionUsername = trim((string)($userOption['username'] ?? ''));
                                    $optionDisplayName = trim((string)($userOption['display_name'] ?? ''));
                                    $optionEmail = trim((string)($userOption['email'] ?? ''));
                                    $optionStatus = trim((string)($userOption['status'] ?? 'inactive'));
                                    $optionLabel = $optionDisplayName !== '' ? $optionDisplayName : $optionUsername;
                                    ?>
                                    <label class="form-check mb-0 js-group-member-option" data-member-id="<?php echo $optionId; ?>">
                                        <input class="form-check-input" type="checkbox" name="member_ids[]" value="<?php echo $optionId; ?>">
                                        <span class="form-check-label d-flex flex-column">
                                            <span><?php echo htmlspecialchars($optionLabel !== '' ? $optionLabel : 'Benutzer'); ?></span>
                                            <span class="text-secondary small"><?php echo htmlspecialchars($optionEmail !== '' ? $optionEmail : $optionUsername); ?> · <?php echo htmlspecialchars($optionStatus); ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-hint">Mitglieder werden direkt mit der Gruppe synchronisiert. Nicht markierte Benutzer werden aus dieser Gruppe entfernt.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Form -->
<form id="deleteGroupForm" method="post" class="d-none">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteGroupId">
</form>
