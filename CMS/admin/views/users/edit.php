<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Users – Edit / Create View
 *
 * Erwartet: $data, $csrfToken, $alert
 */

$user  = $data['user'] ?? null;
$isNew = $data['isNew'] ?? true;
$availableRoles = $data['availableRoles'] ?? [];
$availableStatuses = $data['availableStatuses'] ?? [];
$securityEvents = is_array($data['securityEvents'] ?? null) ? $data['securityEvents'] : [];
$securityEventsUnavailable = !empty($data['securityEventsUnavailable']);
$pendingEmail = is_array($data['pendingEmail'] ?? null) ? $data['pendingEmail'] : null;
$roleImpactPreview = is_array($data['roleImpactPreview'] ?? null) ? $data['roleImpactPreview'] : [];
$roleImpactAvailable = !empty($roleImpactPreview['available']) && is_array($roleImpactPreview['roles'] ?? null);
$selectedRole = (string)($user->role ?? ($roleImpactPreview['current_role'] ?? 'member'));
$selectedRoleImpact = $roleImpactAvailable && isset($roleImpactPreview['roles'][$selectedRole]) && is_array($roleImpactPreview['roles'][$selectedRole])
    ? $roleImpactPreview['roles'][$selectedRole]
    : null;
$usersAdminPath = '/admin/users';
$userId = (int)($user->id ?? 0);
$userName = trim((string)($user->username ?? ''));
$requestUriRaw = (string) ($_SERVER['REQUEST_URI'] ?? '');
$requestPath = (string) parse_url($requestUriRaw, PHP_URL_PATH);
$requestQuery = (string) parse_url($requestUriRaw, PHP_URL_QUERY);
if ($requestPath === '') {
    $requestPath = $usersAdminPath;
}
$currentRequestUri = $requestPath . ($requestQuery !== '' ? '?' . $requestQuery : '');

$roleColors = [
    'admin' => 'red',
    'editor' => 'blue',
    'author' => 'green',
    'member' => 'secondary',
];
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div class="d-flex align-items-start gap-2">
                <a href="<?php echo htmlspecialchars($usersAdminPath); ?>" class="btn btn-ghost-secondary btn-sm me-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M15 6l-6 6l6 6"/></svg>
                    Zurück
                </a>
                <div>
                    <div class="page-pretitle">Benutzer & Gruppen</div>
                    <h2 class="page-title mb-1"><?php echo $isNew ? 'Neuer Benutzer' : 'Benutzer bearbeiten'; ?></h2>
                    <?php if (!$isNew): ?>
                        <div class="content-listing-header__meta">
                            <span>ID <?php echo $userId; ?></span>
                            <span>Benutzer: <?php echo htmlspecialchars($userName !== '' ? $userName : 'Unbekannt', ENT_QUOTES); ?></span>
                            <span>Status: <?php echo htmlspecialchars((string)($user->status ?? '—'), ENT_QUOTES); ?></span>
                        </div>
                    <?php endif; ?>
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

        <div class="cms-admin-info-box mb-3" role="note">
            <div class="cms-admin-info-box__head">
                <h3 class="cms-admin-info-box__title"><?php echo $isNew ? 'Neues Benutzerkonto' : 'Benutzerprofil bearbeiten'; ?></h3>
                <div class="cms-admin-info-box__actions">
                    <a href="/admin/groups" class="btn btn-sm btn-outline-secondary">Gruppen</a>
                    <a href="/admin/roles" class="btn btn-sm btn-outline-secondary">Rollen</a>
                </div>
            </div>
            <p class="cms-admin-info-box__text">
                Rollenwechsel und Statusänderungen bleiben serverseitig validiert; die Wirkungsvorschau dient nur der Orientierung vor dem Speichern.
            </p>
        </div>

        <form method="post" action="<?php echo htmlspecialchars($currentRequestUri, ENT_QUOTES); ?>" id="userForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <?php if (!$isNew): ?>
                <input type="hidden" name="id" value="<?php echo $userId; ?>">
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-8">
                    <!-- Account-Daten -->
                    <div class="card mb-3">
                        <div class="card-header"><h3 class="card-title">Kontodaten</h3></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label required" for="username">Benutzername</label>
                                    <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($user->username ?? ''); ?>" required minlength="3" maxlength="50" pattern="[A-Za-z0-9_]+" autocomplete="username">
                                    <span class="form-hint">3–50 Zeichen, nur Buchstaben, Zahlen und Unterstrich.</span>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label required" for="email">E-Mail</label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user->email ?? ''); ?>" required maxlength="100" autocomplete="email">
                                    <?php if (!$isNew): ?>
                                        <span class="form-hint">Eine neue Adresse wird erst übernommen, wenn der Benutzer den Link in der Bestätigungs-E-Mail öffnet. Die bisherige Adresse erhält einen Hinweis.</span>
                                        <label class="form-check mt-2 mb-0">
                                            <input class="form-check-input" type="checkbox" name="email_skip_confirmation" value="1">
                                            <span class="form-check-label">Neue Adresse ohne Bestätigung übernehmen</span>
                                        </label>
                                        <?php if ($pendingEmail !== null): ?>
                                            <div class="alert alert-info mt-2 mb-0 py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2" role="status">
                                                <span>Ausstehend: <strong><?php echo htmlspecialchars((string) $pendingEmail['email'], ENT_QUOTES); ?></strong> (Link gültig bis <?php echo htmlspecialchars(date('d.m.Y H:i', (int) $pendingEmail['expires_at']), ENT_QUOTES); ?> Uhr)</span>
                                                <button type="submit" form="cancelEmailChangeForm" class="btn btn-sm btn-outline-secondary">Änderung verwerfen</button>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label <?php echo $isNew ? 'required' : ''; ?>" for="password">Passwort</label>
                                    <input type="password" class="form-control" id="password" name="password" <?php echo $isNew ? 'required' : ''; ?> minlength="12" autocomplete="new-password">
                                    <span class="form-hint">Mindestens 12 Zeichen sowie Groß-/Kleinbuchstaben, Ziffer und Sonderzeichen.</span>
                                    <?php if (!$isNew): ?>
                                        <span class="form-hint">Leer lassen, um das Passwort nicht zu ändern.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Persönliche Daten -->
                    <div class="card mb-3">
                        <div class="card-header"><h3 class="card-title">Persönliche Daten</h3></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="first_name">Vorname</label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user->meta['first_name'] ?? ''); ?>" maxlength="120" autocomplete="given-name">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="last_name">Nachname</label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user->meta['last_name'] ?? ''); ?>" maxlength="120" autocomplete="family-name">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <!-- Rolle & Status -->
                    <div class="card mb-3">
                        <div class="card-header"><h3 class="card-title">Rolle & Status</h3></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label" for="role">Rolle</label>
                                <select class="form-select" id="role" name="role">
                                    <?php foreach ($availableRoles as $role => $label): ?>
                                        <option value="<?php echo htmlspecialchars((string)$role); ?>" <?php if (($user->role ?? 'member') === $role) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars((string)$label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="status">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <?php foreach ($availableStatuses as $status => $label): ?>
                                        <option value="<?php echo htmlspecialchars((string)$status); ?>" <?php if (($user->status ?? 'active') === $status) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars((string)$label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="border rounded p-3 bg-light-subtle" id="userRoleImpactPreview" aria-live="polite">
                                <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                    <div>
                                        <div class="fw-semibold">Wirkungsvorschau</div>
                                        <div class="text-secondary small">Read-only Diff vor dem Speichern – keine Änderung ohne Submit.</div>
                                    </div>
                                    <span class="badge bg-blue-lt" id="roleImpactTargetLabel">
                                        <?php echo htmlspecialchars((string)($selectedRoleImpact['label'] ?? ($availableRoles[$selectedRole] ?? $selectedRole))); ?>
                                    </span>
                                </div>

                                <?php if ($roleImpactAvailable): ?>
                                    <script type="application/json" id="userRoleImpactPreviewData"><?php echo json_encode($roleImpactPreview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
                                    <div class="row g-2 mb-3">
                                        <div class="col-6">
                                            <div class="small text-secondary">Neue Rechte</div>
                                            <div class="h3 mb-0 text-success" id="roleImpactGainedCount"><?php echo (int)($selectedRoleImpact['gained_count'] ?? 0); ?></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="small text-secondary">Entfallende Rechte</div>
                                            <div class="h3 mb-0 text-warning" id="roleImpactLostCount"><?php echo (int)($selectedRoleImpact['lost_count'] ?? 0); ?></div>
                                        </div>
                                    </div>

                                    <div class="small fw-semibold mb-1">Member-Bereiche</div>
                                    <div class="d-flex flex-wrap gap-1 mb-2" id="roleImpactMemberAreas">
                                        <?php foreach ((array)($selectedRoleImpact['member_areas'] ?? []) as $areaLabel): ?>
                                            <span class="badge bg-secondary-lt text-secondary"><?php echo htmlspecialchars((string)$areaLabel); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="small text-secondary mb-3" id="roleImpactMemberDiff">Änderungen werden beim Rollenwechsel hier markiert.</div>

                                    <div class="small fw-semibold mb-1">Plugin-Widgets</div>
                                    <div class="d-flex flex-wrap gap-1 mb-2" id="roleImpactPluginWidgets">
                                        <?php foreach ((array)($selectedRoleImpact['plugin_widgets'] ?? []) as $widgetLabel): ?>
                                            <span class="badge bg-indigo-lt text-indigo"><?php echo htmlspecialchars((string)$widgetLabel); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="small text-secondary mb-3" id="roleImpactPluginDiff">Plugin-Widget-Unterschiede werden hier angezeigt.</div>

                                    <div class="alert alert-secondary mb-0 py-2 px-3" id="roleImpactPackageBox">
                                        <div class="fw-semibold" id="roleImpactPackageTitle"><?php echo htmlspecialchars((string)($selectedRoleImpact['package_summary']['title'] ?? 'Paketwirkung')); ?></div>
                                        <div class="small" id="roleImpactPackageMessage"><?php echo htmlspecialchars((string)($selectedRoleImpact['package_summary']['message'] ?? 'Keine automatische Paketänderung.')); ?></div>
                                        <div class="small text-secondary mt-1" id="roleImpactPackageCurrent">
                                            Aktuell: <?php echo htmlspecialchars((string)($selectedRoleImpact['package_summary']['current_package'] ?? '–')); ?>
                                        </div>
                                    </div>

                                    <details class="mt-3">
                                        <summary class="small text-secondary">Capability-Details anzeigen</summary>
                                        <div class="row g-2 mt-2">
                                            <div class="col-12">
                                                <div class="small fw-semibold text-success">Hinzu</div>
                                                <div class="d-flex flex-wrap gap-1" id="roleImpactGainedCaps"></div>
                                            </div>
                                            <div class="col-12">
                                                <div class="small fw-semibold text-warning">Wegfall</div>
                                                <div class="d-flex flex-wrap gap-1" id="roleImpactLostCaps"></div>
                                            </div>
                                        </div>
                                    </details>
                                <?php else: ?>
                                    <div class="text-secondary small">
                                        Die Wirkungsvorschau ist aktuell nicht verfügbar. Die Benutzerbearbeitung bleibt davon unberührt.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-footer">
                            <div class="d-grid gap-2">
                                <button type="submit" name="action" value="save" class="btn btn-primary w-100">
                                    <?php echo $isNew ? 'Benutzer erstellen' : 'Speichern'; ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <?php if (!$isNew): ?>
                    <!-- Info -->
                    <div class="card">
                        <div class="card-header"><h3 class="card-title">Info</h3></div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-5">Registriert</dt>
                                <dd class="col-7"><?php echo !empty($user->created_at) ? date('d.m.Y H:i', strtotime($user->created_at)) : '–'; ?></dd>
                                <dt class="col-5">Letzter Login</dt>
                                <dd class="col-7"><?php echo !empty($user->last_login) ? date('d.m.Y H:i', strtotime($user->last_login)) : '–'; ?></dd>
                            </dl>
                        </div>
                    </div>

                    <div class="card mt-3">
                        <div class="card-header">
                            <h3 class="card-title">Sicherheitsereignisse</h3>
                        </div>
                        <div class="card-body p-0">
                            <?php if ($securityEventsUnavailable): ?>
                                <div class="p-3 text-secondary">
                                    Sicherheitsereignisse sind aktuell nicht verfügbar. Die Benutzerbearbeitung bleibt davon unberührt.
                                </div>
                            <?php elseif ($securityEvents === []): ?>
                                <div class="p-3 text-secondary">
                                    Keine sicherheitsrelevanten Ereignisse für dieses Profil gefunden.
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($securityEvents as $event): ?>
                                        <?php
                                        $eventSeverity = (string)($event->severity ?? 'info');
                                        $severityColor = match ($eventSeverity) {
                                            'critical', 'error' => 'danger',
                                            'warning' => 'warning',
                                            default => 'blue',
                                        };
                                        $eventCreatedAt = !empty($event->created_at) ? date('d.m.Y H:i', strtotime((string)$event->created_at)) : '–';
                                        $eventAction = (string)($event->action ?? '');
                                        $eventCategory = (string)($event->category ?? '');
                                        $eventDescription = trim((string)($event->description ?? ''));
                                        $eventIp = trim((string)($event->ip_address ?? ''));
                                        ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 align-items-start gap-2">
                                                <span class="badge bg-<?php echo htmlspecialchars($severityColor, ENT_QUOTES); ?>-lt mt-1"><?php echo htmlspecialchars($eventSeverity); ?></span>
                                                <div class="flex-fill">
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($eventDescription !== '' ? $eventDescription : $eventAction); ?></div>
                                                    <div class="small text-secondary">
                                                        <?php echo htmlspecialchars($eventCreatedAt); ?> · <?php echo htmlspecialchars($eventCategory); ?><?php if ($eventAction !== ''): ?> · <?php echo htmlspecialchars($eventAction); ?><?php endif; ?><?php if ($eventIp !== ''): ?> · IP <?php echo htmlspecialchars($eventIp); ?><?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer text-secondary small">
                            Es werden nur die letzten begrenzten Audit-Einträge ohne Roh-Metadaten, Tokens oder Session-Daten angezeigt.
                        </div>
                    </div>

                    <div class="card mt-3">
                        <div class="card-header"><h3 class="card-title mb-0">Sitzungen &amp; API-Tokens</h3></div>
                        <div class="card-body">
                            <p class="text-secondary mb-3">Meldet den Benutzer auf allen Geräten ab und macht ausgestellte API-Tokens ungültig, ohne das Passwort zu ändern – etwa bei Verdacht auf eine übernommene Sitzung.</p>
                            <button type="submit" form="revokeSessionsForm" class="btn btn-outline-warning w-100">Überall abmelden</button>
                        </div>
                    </div>

                    <div class="card border-danger-subtle mt-3">
                        <div class="card-header"><h3 class="card-title text-danger mb-0">Benutzer dauerhaft löschen</h3></div>
                        <div class="card-body">
                            <p class="text-secondary mb-3">Der Benutzer wird <strong>vollständig entfernt</strong>. Diese Aktion ist dauerhaft und kann nicht rückgängig gemacht werden.</p>
                            <button
                                type="button"
                                class="btn btn-danger w-100"
                                data-user-delete-id="<?php echo $userId; ?>"
                                data-user-delete-name="<?php echo htmlspecialchars($userName !== '' ? $userName : 'Benutzer', ENT_QUOTES); ?>"
                            >Benutzer löschen</button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <?php if (!$isNew): ?>
            <form id="deleteUserForm" method="post" action="<?php echo htmlspecialchars($usersAdminPath, ENT_QUOTES); ?>" class="d-none">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo $userId; ?>">
            </form>
            <form id="revokeSessionsForm" method="post" action="<?php echo htmlspecialchars($usersAdminPath, ENT_QUOTES); ?>" class="d-none"
                  data-confirm-message="Alle Sitzungen und API-Tokens dieses Benutzers beenden?" data-confirm-title="Überall abmelden" data-confirm-text="Abmelden" data-confirm-class="btn-warning" data-confirm-status-class="bg-warning">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="revoke_sessions">
                <input type="hidden" name="id" value="<?php echo $userId; ?>">
            </form>
            <?php if ($pendingEmail !== null): ?>
                <form id="cancelEmailChangeForm" method="post" action="<?php echo htmlspecialchars($usersAdminPath, ENT_QUOTES); ?>" class="d-none"
                      data-confirm-message="Ausstehende Änderung der E-Mail-Adresse verwerfen?" data-confirm-title="Änderung verwerfen" data-confirm-text="Verwerfen" data-confirm-class="btn-secondary" data-confirm-status-class="bg-secondary">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="cancel_email_change">
                    <input type="hidden" name="id" value="<?php echo $userId; ?>">
                </form>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>
