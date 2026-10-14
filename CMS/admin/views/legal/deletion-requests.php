<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

/** @var array $data */
$d        = $data ?? [];
$requests = $d['requests'] ?? [];
$stats    = $d['stats'] ?? [];
$statusLabels = ['pending' => 'Wartend', 'processing' => 'In Prüfung', 'completed' => 'Gelöscht', 'rejected' => 'Abgelehnt'];
$statusBadges = ['pending' => 'bg-warning', 'processing' => 'bg-blue', 'completed' => 'bg-success', 'rejected' => 'bg-danger'];
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Recht</div>
                <h2 class="page-title mb-1">Löschanträge</h2>
                <div class="content-listing-header__meta">
                    <span><?php echo (int)($stats['total'] ?? 0); ?> gesamt</span>
                    <span><?php echo (int)($stats['pending'] ?? 0); ?> wartend</span>
                    <span><?php echo (int)($stats['processing'] ?? 0); ?> in Prüfung</span>
                    <span><?php echo (int)($stats['completed'] ?? 0); ?> durchgeführt</span>
                </div>
            </div>
            <div class="admin-section-toolbar__actions">
                <a href="/admin/data-requests" class="btn btn-outline-secondary btn-sm">Kombinierte Anfrageansicht</a>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <?php
        $alertData = $alert ?? [];
        $alertDismissible = false;
        $alertMarginClass = 'mb-3';
        require __DIR__ . '/../partials/flash-alert.php';
        ?>

        <div class="cms-admin-info-box cms-admin-info-box--warning mb-3" role="note">
            <div class="cms-admin-info-box__head">
                <h3 class="cms-admin-info-box__title">DSGVO Art. 17 Löschprozess</h3>
            </div>
            <p class="cms-admin-info-box__text">
                Löschanträge bleiben im gleichen Ablauf: eingegangen, in Prüfung, durchgeführt oder abgelehnt.
                Durchgeführte Löschungen anonymisieren personenbezogene Daten unwiderruflich.
            </p>
        </div>

        <div class="row row-cards mb-3 admin-request-kpi-grid">
            <div class="col-sm-6 col-lg-3">
                <div class="card admin-request-kpi">
                    <div class="card-body">
                        <div class="subheader">Löschanträge gesamt</div>
                        <div class="h2 mb-0"><?php echo (int)($stats['total'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card admin-request-kpi">
                    <div class="card-body">
                        <div class="subheader">Wartend</div>
                        <div class="h2 mb-0 text-warning"><?php echo (int)($stats['pending'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card admin-request-kpi">
                    <div class="card-body">
                        <div class="subheader">In Prüfung</div>
                        <div class="h2 mb-0 text-primary"><?php echo (int)($stats['processing'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card admin-request-kpi">
                    <div class="card-body">
                        <div class="subheader">Durchgeführt</div>
                        <div class="h2 mb-0 text-success"><?php echo (int)($stats['completed'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card content-listing-card">
            <div class="card-header content-listing-toolbar admin-request-toolbar">
                <div class="content-listing-toolbar__label">Löschanträge</div>
                <div class="content-listing-filters">
                    <div class="content-listing-filters__actions">
                        <span class="text-secondary small">Arbeitsweise: Wartend → In Prüfung → Durchführen/Ablehnen</span>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table content-listing-table admin-request-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name / E-Mail</th>
                            <th>Benutzer</th>
                            <th>Status</th>
                            <th>Erstellt</th>
                            <th>Abgeschlossen</th>
                            <th class="w-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests)): ?>
                            <?php
                            $emptyStateColspan = 7;
                            $emptyStateMessage = 'Keine Löschanträge vorhanden.';
                            $emptyStateSubtitle = 'Neue DSGVO-Löschanträge erscheinen hier automatisch zur Prüfung.';
                            $emptyStateIcon = 'default';
                            require __DIR__ . '/../partials/empty-table-row.php';
                            ?>
                        <?php else: ?>
                            <?php foreach ($requests as $r): ?>
                                <tr class="content-listing-table__row">
                                    <td class="text-secondary"><?php echo (int)$r['id']; ?></td>
                                    <td>
                                        <div class="fw-medium"><?php echo htmlspecialchars($r['name'] ?? '-'); ?></div>
                                        <div class="text-secondary small"><?php echo htmlspecialchars($r['email']); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($r['username'] ?? '-'); ?></td>
                                    <td><span class="badge <?php echo $statusBadges[$r['status']] ?? 'bg-secondary'; ?>"><?php echo htmlspecialchars($statusLabels[$r['status']] ?? $r['status']); ?></span></td>
                                    <td class="text-secondary"><?php echo htmlspecialchars($r['created_at'] ?? ''); ?></td>
                                    <td class="text-secondary"><?php echo htmlspecialchars($r['completed_at'] ?? '-'); ?></td>
                                    <td class="table-actions content-listing-table__actions-cell">
                                        <div class="dropdown">
                                            <button type="button" class="btn btn-ghost-secondary btn-icon btn-sm" data-bs-toggle="dropdown" aria-label="Aktionen öffnen">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12 19m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/></svg>
                                            </button>
                                            <div class="dropdown-menu dropdown-menu-end">
                                                <?php if ($r['status'] === 'pending'): ?>
                                                    <form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>"><input type="hidden" name="action" value="process"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button class="dropdown-item">Prüfung starten</button></form>
                                                <?php endif; ?>
                                                <?php if ($r['status'] === 'processing'): ?>
                                                    <form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>"><input type="hidden" name="action" value="execute"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button class="dropdown-item text-danger" data-cms-confirm="ACHTUNG: Benutzerdaten werden unwiderruflich anonymisiert. Fortfahren?">Löschung durchführen</button></form>
                                                <?php endif; ?>
                                                <?php if (in_array($r['status'], ['pending', 'processing'], true)): ?>
                                                    <a href="#" class="dropdown-item text-warning" data-cms-call="rejectDeletion" data-cms-call-args="[<?php echo (int)$r['id']; ?>]">Ablehnen</a>
                                                <?php endif; ?>
                                                <?php if (in_array($r['status'], ['completed', 'rejected'], true)): ?>
                                                    <form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button class="dropdown-item text-danger" data-cms-confirm="Antrag endgültig entfernen?">Antrag löschen</button></form>
                                                <?php endif; ?>
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

        <!-- Ablehnen-Modal -->
        <div class="modal modal-blur fade" id="rejectDeletionModal" tabindex="-1">
            <div class="modal-dialog modal-sm">
                <div class="modal-content">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="id" id="rejectDelId" value="0">
                        <div class="modal-header">
                            <h5 class="modal-title">Löschantrag ablehnen</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="fld-reject_reason" class="form-label">Begründung (DSGVO Art. 17 Abs. 3)</label>
                                <textarea id="fld-reject_reason" name="reject_reason" class="form-control" rows="3" required placeholder="z.B. gesetzliche Aufbewahrungspflicht"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn me-auto" data-bs-dismiss="modal">Abbrechen</button>
                            <button type="submit" class="btn btn-warning">Ablehnen</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script <?= \CMS\Security::instance()->nonceAttr() ?>>
        function rejectDeletion(id) {
            document.getElementById('rejectDelId').value = id;
            new bootstrap.Modal(document.getElementById('rejectDeletionModal')).show();
        }
        </script>

    </div>
</div>
