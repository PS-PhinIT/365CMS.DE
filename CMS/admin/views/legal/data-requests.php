<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

$privacyData   = $data['privacy'] ?? [];
$deletionData  = $data['deletion'] ?? [];
$privacyStats  = $privacyData['stats'] ?? [];
$deletionStats = $deletionData['stats'] ?? [];
$privacyRows   = $privacyData['requests'] ?? [];
$deletionRows  = $deletionData['requests'] ?? [];
$deadlineDays = (int)($privacyStats['deadline_days'] ?? $deletionStats['deadline_days'] ?? 30);
$warningBeforeDays = (int)($privacyStats['warning_before_days'] ?? $deletionStats['warning_before_days'] ?? 7);
$adminEscalationEmail = (string)($privacyStats['admin_email'] ?? $deletionStats['admin_email'] ?? '');

$statusLabels = [
    'pending' => 'Eingegangen',
    'processing' => 'In Bearbeitung',
    'completed' => 'Erledigt',
    'rejected' => 'Abgelehnt',
];

$statusBadges = [
    'pending' => 'bg-warning',
    'processing' => 'bg-blue',
    'completed' => 'bg-success',
    'rejected' => 'bg-danger',
];

$deadlineBadges = [
    'ok' => 'bg-green-lt text-green',
    'due_soon' => 'bg-warning-lt text-warning',
    'overdue' => 'bg-danger-lt text-danger',
    'closed' => 'bg-secondary-lt text-secondary',
    'unknown' => 'bg-secondary-lt text-secondary',
];

$dataRequestsConfig = [
    'rejectModalId' => 'rejectDataRequestModal',
    'defaultRejectTitle' => 'Anfrage ablehnen',
];
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Recht</div>
                <h2 class="page-title mb-1">Auskunft &amp; Löschen</h2>
                <div class="content-listing-header__meta">
                    <span>Offen: <?php echo (int)(($privacyStats['pending'] ?? 0) + ($deletionStats['pending'] ?? 0)); ?></span>
                    <span>In Bearbeitung: <?php echo (int)(($privacyStats['processing'] ?? 0) + ($deletionStats['processing'] ?? 0)); ?></span>
                </div>
            </div>
            <div class="admin-section-toolbar__actions">
                <a href="/admin/legal-sites" class="btn btn-outline-secondary btn-sm">Legal Sites</a>
            </div>
        </div>
    </div>
</div>

<div class="page-body data-requests-page">
    <div class="container-xl">
        <?php
        $alertData = $alert ?? [];
        $alertDismissible = false;
        $alertMarginClass = 'mb-4';
        require __DIR__ . '/../partials/flash-alert.php';
        ?>

        <div class="card content-listing-card mb-3">
            <div class="card-header content-listing-toolbar">
                <div class="content-listing-toolbar__label">Status-Überblick</div>
                <div class="content-listing-filters">
                    <div class="content-listing-filters__actions">
                        <span class="text-secondary small">Auskunft gesamt: <strong><?php echo (int)($privacyStats['total'] ?? 0); ?></strong></span>
                        <span class="text-secondary small">Löschanträge gesamt: <strong><?php echo (int)($deletionStats['total'] ?? 0); ?></strong></span>
                        <span class="text-secondary small">Offen: <strong><?php echo (int)(($privacyStats['pending'] ?? 0) + ($deletionStats['pending'] ?? 0)); ?></strong></span>
                        <span class="text-secondary small">In Bearbeitung: <strong><?php echo (int)(($privacyStats['processing'] ?? 0) + ($deletionStats['processing'] ?? 0)); ?></strong></span>
                    </div>
                </div>
            </div>
            <div class="card-body py-3">
                <div class="row row-cards admin-request-kpi-grid mb-0">
                    <div class="col-sm-6 col-lg-3"><div class="card admin-request-kpi"><div class="card-body"><div class="subheader">Pflichtfrist</div><div class="h2 mb-0"><?php echo $deadlineDays; ?> Tage</div><div class="text-secondary small">Warnfenster ab <?php echo $warningBeforeDays; ?> Tagen Restlaufzeit.</div></div></div></div>
                    <div class="col-sm-6 col-lg-3"><div class="card admin-request-kpi"><div class="card-body"><div class="subheader">Frist läuft ab</div><div class="h2 mb-0 text-warning"><?php echo (int)(($privacyStats['due_soon'] ?? 0) + ($deletionStats['due_soon'] ?? 0)); ?></div><div class="text-secondary small">Offene Vorgänge im Warnfenster.</div></div></div></div>
                    <div class="col-sm-6 col-lg-3"><div class="card admin-request-kpi"><div class="card-body"><div class="subheader">Überfällig</div><div class="h2 mb-0 text-danger"><?php echo (int)(($privacyStats['overdue'] ?? 0) + ($deletionStats['overdue'] ?? 0)); ?></div><div class="text-secondary small">Sofort prüfen und dokumentieren.</div></div></div></div>
                    <div class="col-sm-6 col-lg-3"><div class="card admin-request-kpi"><div class="card-body"><div class="subheader">Admin-Eskalation</div><div class="text-secondary small"><?php echo $adminEscalationEmail !== '' ? htmlspecialchars($adminEscalationEmail) : 'Keine gültige Admin-Mail konfiguriert'; ?></div><div class="small text-muted mt-2">Überfällige Anfragen lassen sich direkt an die konfigurierte Admin-Adresse eskalieren.</div></div></div></div>
                </div>
            </div>
        </div>

        <div class="cms-admin-info-box mb-4" role="note">
            <div class="cms-admin-info-box__head">
                <h3 class="cms-admin-info-box__title">Fristen-Workflow</h3>
            </div>
            <p class="cms-admin-info-box__text">
                Eingegangen → In Bearbeitung → Erledigt oder abgelehnt. Nach Art. 12 DSGVO ist grundsätzlich ohne unangemessene Verzögerung und spätestens innerhalb eines Monats zu reagieren; 365CMS bewertet dies konservativ als <?php echo $deadlineDays; ?>-Tage-Frist und markiert offene Anfragen ab <?php echo $warningBeforeDays; ?> Tagen Restlaufzeit.
            </p>
            <div class="admin-request-status-filters">
                <button type="button" class="btn btn-sm btn-outline-primary js-request-status-chip" data-request-status="pending">Eingegangen</button>
                <button type="button" class="btn btn-sm btn-outline-primary js-request-status-chip" data-request-status="processing">In Bearbeitung</button>
                <button type="button" class="btn btn-sm btn-outline-primary js-request-status-chip" data-request-status="completed">Erledigt</button>
                <a href="#" class="small js-request-status-reset">Alle anzeigen</a>
            </div>
        </div>

        <div class="row row-cards">
            <div class="col-12 col-xl-6">
                <div class="card admin-content-card">
                    <div class="card-header">
                        <h3 class="card-title">Datenschutz-Auskunft</h3>
                    </div>
                    <div class="card-body border-bottom">
                        <div class="alert alert-info mb-0"><strong>DSGVO Art. 15:</strong> Betroffene Personen haben das Recht auf Auskunft über die verarbeiteten personenbezogenen Daten. Ziel: Bearbeitung innerhalb eines Monats.</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table content-listing-table admin-request-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name / E-Mail</th>
                                    <th>Status</th>
                                    <th>Frist</th>
                                    <th>Erstellt</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($privacyRows)): ?>
                                    <?php
                                    $emptyStateColspan = 6;
                                    $emptyStateMessage = 'Keine Auskunftsanfragen vorhanden.';
                                    $emptyStateSubtitle = 'Sobald Anfragen eingehen, werden sie hier zentral zur Bearbeitung aufgelistet.';
                                    $emptyStateIcon = 'default';
                                    require __DIR__ . '/../partials/empty-table-row.php';
                                    ?>
                                <?php else: ?>
                                    <?php foreach ($privacyRows as $row): ?>
                                        <?php $deadline = is_array($row['deadline'] ?? null) ? $row['deadline'] : []; ?>
                                        <tr class="js-request-filter-row" data-request-status="<?php echo htmlspecialchars((string)($row['status'] ?? '')); ?>">
                                            <td><?php echo (int)($row['id'] ?? 0); ?></td>
                                            <td>
                                                <div><?php echo htmlspecialchars((string)($row['name'] ?? '-')); ?></div>
                                                <div class="text-secondary small"><?php echo htmlspecialchars((string)($row['email'] ?? '')); ?></div>
                                                <?php if (!empty($row['execute_after'])): ?>
                                                    <div class="text-warning small">Früheste Löschung: <?php echo htmlspecialchars((string)$row['execute_after']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge <?php echo htmlspecialchars($statusBadges[$row['status'] ?? ''] ?? 'bg-secondary'); ?>"><?php echo htmlspecialchars($statusLabels[$row['status'] ?? ''] ?? (string)($row['status'] ?? '')); ?></span></td>
                                            <td>
                                                <span class="badge <?php echo htmlspecialchars($deadlineBadges[$deadline['state'] ?? 'unknown'] ?? 'bg-secondary-lt text-secondary'); ?>"><?php echo htmlspecialchars((string)($deadline['label'] ?? 'Frist unbekannt')); ?></span>
                                                <?php if (!empty($deadline['due_at'])): ?>
                                                    <div class="text-secondary small"><?php echo htmlspecialchars((string)$deadline['due_at']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-secondary small"><?php echo htmlspecialchars((string)($row['created_at'] ?? '')); ?></td>
                                            <td>
                                                <div class="dropdown">
                                                    <button type="button" class="btn btn-ghost-secondary btn-icon btn-sm" data-bs-toggle="dropdown" aria-label="Aktionen öffnen"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12 19m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/></svg></button>
                                                    <div class="dropdown-menu dropdown-menu-end">
                                                        <?php if (($row['status'] ?? '') === 'pending'): ?>
                                                            <form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>"><input type="hidden" name="scope" value="privacy"><input type="hidden" name="action" value="process"><input type="hidden" name="id" value="<?php echo (int)($row['id'] ?? 0); ?>"><button class="dropdown-item">Bearbeitung starten</button></form>
                                                        <?php endif; ?>
                                                        <?php if (($row['status'] ?? '') === 'processing'): ?>
                                                            <form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>"><input type="hidden" name="scope" value="privacy"><input type="hidden" name="action" value="complete"><input type="hidden" name="id" value="<?php echo (int)($row['id'] ?? 0); ?>"><button class="dropdown-item text-success">Abschließen &amp; exportieren</button></form>
                                                        <?php endif; ?>
                                                        <?php if (in_array(($row['status'] ?? ''), ['pending', 'processing'], true)): ?>
                                                            <button type="button" class="dropdown-item text-warning js-open-data-request-reject-modal" data-request-scope="privacy" data-request-id="<?php echo (int)($row['id'] ?? 0); ?>" data-request-title="Auskunftsanfrage ablehnen">Ablehnen</button>
                                                        <?php endif; ?>
                                                        <?php if (!empty($deadline['needs_escalation']) && in_array(($row['status'] ?? ''), ['pending', 'processing'], true)): ?>
                                                            <form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>"><input type="hidden" name="scope" value="privacy"><input type="hidden" name="action" value="escalate"><input type="hidden" name="id" value="<?php echo (int)($row['id'] ?? 0); ?>"><button class="dropdown-item text-danger">An Admin-Mail eskalieren</button></form>
                                                        <?php endif; ?>
                                                        <?php if (in_array(($row['status'] ?? ''), ['completed', 'rejected'], true)): ?>
                                                            <form method="post" data-confirm-message="Anfrage endgültig löschen?" data-confirm-title="Anfrage löschen" data-confirm-text="Löschen" data-confirm-class="btn-danger" data-confirm-status-class="bg-danger"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>"><input type="hidden" name="scope" value="privacy"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)($row['id'] ?? 0); ?>"><button class="dropdown-item text-danger">Löschen</button></form>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <tr class="d-none js-request-filter-empty">
                                    <td colspan="6" class="py-4">
                                        <div class="admin-empty-table-state text-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="icon text-secondary mb-2" width="32" height="32" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                                <path d="M9 6l11 0"/>
                                                <path d="M9 12l11 0"/>
                                                <path d="M9 18l11 0"/>
                                                <path d="M5 6l0 .01"/>
                                                <path d="M5 12l0 .01"/>
                                                <path d="M5 18l0 .01"/>
                                            </svg>
                                            <div class="fw-bold">Keine Auskunftsanfragen vorhanden.</div>
                                            <div class="text-secondary small">Sobald passende Vorgänge den gewählten Status haben, erscheinen sie hier.</div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="card admin-content-card">
                    <div class="card-header">
                        <h3 class="card-title">Löschanträge</h3>
                    </div>
                    <div class="card-body border-bottom">
                        <div class="alert alert-warning mb-0"><strong>DSGVO Art. 17:</strong> Löschungen müssen geprüft, dokumentiert und – sofern zulässig – fristgerecht umgesetzt werden.</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table content-listing-table admin-request-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name / E-Mail</th>
                                    <th>Status</th>
                                    <th>Frist</th>
                                    <th>Erstellt</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($deletionRows)): ?>
                                    <?php
                                    $emptyStateColspan = 6;
                                    $emptyStateMessage = 'Keine Löschanträge vorhanden.';
                                    $emptyStateSubtitle = 'Offene Löschanträge werden hier mit Status und Bearbeitungsaktionen angezeigt.';
                                    $emptyStateIcon = 'default';
                                    require __DIR__ . '/../partials/empty-table-row.php';
                                    ?>
                                <?php else: ?>
                                    <?php foreach ($deletionRows as $row): ?>
                                        <?php $deadline = is_array($row['deadline'] ?? null) ? $row['deadline'] : []; ?>
                                        <tr class="js-request-filter-row" data-request-status="<?php echo htmlspecialchars((string)($row['status'] ?? '')); ?>">
                                            <td><?php echo (int)($row['id'] ?? 0); ?></td>
                                            <td>
                                                <div><?php echo htmlspecialchars((string)($row['name'] ?? '-')); ?></div>
                                                <div class="text-secondary small"><?php echo htmlspecialchars((string)($row['email'] ?? '')); ?></div>
                                            </td>
                                            <td><span class="badge <?php echo htmlspecialchars($statusBadges[$row['status'] ?? ''] ?? 'bg-secondary'); ?>"><?php echo htmlspecialchars($statusLabels[$row['status'] ?? ''] ?? (string)($row['status'] ?? '')); ?></span></td>
                                            <td>
                                                <span class="badge <?php echo htmlspecialchars($deadlineBadges[$deadline['state'] ?? 'unknown'] ?? 'bg-secondary-lt text-secondary'); ?>"><?php echo htmlspecialchars((string)($deadline['label'] ?? 'Frist unbekannt')); ?></span>
                                                <?php if (!empty($deadline['due_at'])): ?>
                                                    <div class="text-secondary small"><?php echo htmlspecialchars((string)$deadline['due_at']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-secondary small"><?php echo htmlspecialchars((string)($row['created_at'] ?? '')); ?></td>
                                            <td>
                                                <div class="dropdown">
                                                    <button type="button" class="btn btn-ghost-secondary btn-icon btn-sm" data-bs-toggle="dropdown" aria-label="Aktionen öffnen"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12 19m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/></svg></button>
                                                    <div class="dropdown-menu dropdown-menu-end">
                                                        <?php if (($row['status'] ?? '') === 'pending'): ?>
                                                            <form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>"><input type="hidden" name="scope" value="deletion"><input type="hidden" name="action" value="process"><input type="hidden" name="id" value="<?php echo (int)($row['id'] ?? 0); ?>"><button class="dropdown-item">Prüfung starten</button></form>
                                                        <?php endif; ?>
                                                        <?php if (($row['status'] ?? '') === 'processing'): ?>
                                                            <form method="post" data-confirm-message="Das Benutzerkonto wird endgültig gelöscht, sobald die Frist abgelaufen ist. Fortfahren?" data-confirm-title="Löschung durchführen" data-confirm-text="Endgültig löschen" data-confirm-class="btn-danger" data-confirm-status-class="bg-danger"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>"><input type="hidden" name="scope" value="deletion"><input type="hidden" name="action" value="execute"><input type="hidden" name="id" value="<?php echo (int)($row['id'] ?? 0); ?>"><button class="dropdown-item text-danger">Löschung durchführen</button></form>
                                                        <?php endif; ?>
                                                        <?php if (in_array(($row['status'] ?? ''), ['pending', 'processing'], true)): ?>
                                                            <button type="button" class="dropdown-item text-warning js-open-data-request-reject-modal" data-request-scope="deletion" data-request-id="<?php echo (int)($row['id'] ?? 0); ?>" data-request-title="Löschantrag ablehnen">Ablehnen</button>
                                                        <?php endif; ?>
                                                        <?php if (!empty($deadline['needs_escalation']) && in_array(($row['status'] ?? ''), ['pending', 'processing'], true)): ?>
                                                            <form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>"><input type="hidden" name="scope" value="deletion"><input type="hidden" name="action" value="escalate"><input type="hidden" name="id" value="<?php echo (int)($row['id'] ?? 0); ?>"><button class="dropdown-item text-danger">An Admin-Mail eskalieren</button></form>
                                                        <?php endif; ?>
                                                        <?php if (in_array(($row['status'] ?? ''), ['completed', 'rejected'], true)): ?>
                                                            <form method="post" data-confirm-message="Antrag endgültig löschen?" data-confirm-title="Antrag löschen" data-confirm-text="Löschen" data-confirm-class="btn-danger" data-confirm-status-class="bg-danger"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>"><input type="hidden" name="scope" value="deletion"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)($row['id'] ?? 0); ?>"><button class="dropdown-item text-danger">Löschen</button></form>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <tr class="d-none js-request-filter-empty">
                                    <td colspan="6" class="py-4">
                                        <div class="admin-empty-table-state text-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="icon text-secondary mb-2" width="32" height="32" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                                <path d="M9 6l11 0"/>
                                                <path d="M9 12l11 0"/>
                                                <path d="M9 18l11 0"/>
                                                <path d="M5 6l0 .01"/>
                                                <path d="M5 12l0 .01"/>
                                                <path d="M5 18l0 .01"/>
                                            </svg>
                                            <div class="fw-bold">Keine Löschanträge vorhanden.</div>
                                            <div class="text-secondary small">Sobald passende Vorgänge den gewählten Status haben, erscheinen sie hier.</div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal modal-blur fade" id="rejectDataRequestModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                <input type="hidden" name="scope" id="rejectScope" value="privacy">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="id" id="rejectRequestId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="rejectModalTitle">Anfrage ablehnen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <label for="fld-reject_reason" class="form-label">Begründung</label>
                    <textarea id="fld-reject_reason" name="reject_reason" class="form-control" rows="3" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn me-auto" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-warning">Ablehnen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script type="application/json" id="data-requests-config"><?php echo json_encode($dataRequestsConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
