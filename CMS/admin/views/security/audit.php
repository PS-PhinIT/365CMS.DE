<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

/** @var array $data */
$d        = $data ?? [];
$checks   = $d['checks'] ?? [];
$stats    = $d['stats'] ?? [];
$auditLog = $d['audit_log'] ?? [];

$statusIcon = [
    'ok'       => '<svg xmlns="http://www.w3.org/2000/svg" class="icon text-success" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10"/></svg>',
    'warning'  => '<svg xmlns="http://www.w3.org/2000/svg" class="icon text-warning" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v2m0 4v.01"/><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75"/></svg>',
    'critical' => '<svg xmlns="http://www.w3.org/2000/svg" class="icon text-danger" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M12 9v4"/><path d="M12 16v.01"/></svg>',
];
$statusBadge = ['ok' => 'bg-success', 'warning' => 'bg-warning', 'critical' => 'bg-danger'];
?>

<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Sicherheit</div>
                <h2 class="page-title">Sicherheits-Audit</h2>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">

<?php if (!empty($alert)): ?>
    <?php
    $alertData = is_array($alert ?? null) ? $alert : [];
    require dirname(__DIR__) . '/partials/flash-alert.php';
    ?>
<?php endif; ?>

<div class="card admin-redesign-toolbar-card">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div class="admin-redesign-info-box flex-fill">
            <p class="admin-redesign-info-box__title">Audit-Fokus</p>
            <p class="admin-redesign-info-box__text">Prüfungen priorisieren, kritische Punkte zuerst lösen, Änderungen im Audit-Log anschließend nachvollziehen.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="/admin/firewall" class="btn btn-outline-secondary btn-sm">Firewall</a>
            <a href="/admin/antispam" class="btn btn-outline-primary btn-sm">AntiSpam</a>
        </div>
    </div>
</div>

<div class="security-page-intro">
    <div class="security-page-intro__text">
        <p class="security-page-intro__title">Sicherheitszustand schnell erfassen und sauber nachverfolgen</p>
        <p class="security-page-intro__copy">Das Sicherheits-Audit bündelt die wichtigsten Prüfungen in einer kompakten Übersicht. Darunter bleibt das Audit-Log read-only und nachvollziehbar, ohne Rohdaten unnötig aufzublähen.</p>
    </div>
</div>

<!-- KPI-Karten -->
<div class="row row-deck row-cards mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card security-kpi-card">
            <div class="card-body">
                <div class="subheader">Prüfungen gesamt</div>
                <div class="h1 mb-0"><?php echo (int)($stats['total'] ?? 0); ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card security-kpi-card is-success">
            <div class="card-body">
                <div class="subheader">Bestanden</div>
                <div class="h1 mb-0 text-success"><?php echo (int)($stats['passed'] ?? 0); ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card security-kpi-card is-warning">
            <div class="card-body">
                <div class="subheader">Warnungen</div>
                <div class="h1 mb-0 text-warning"><?php echo (int)($stats['warning'] ?? 0); ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card security-kpi-card is-danger">
            <div class="card-body">
                <div class="subheader">Kritisch</div>
                <div class="h1 mb-0 text-danger"><?php echo (int)($stats['critical'] ?? 0); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Aktionen -->
<div class="security-action-bar">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
        <input type="hidden" name="action" value="run_audit">
        <button type="submit" class="btn btn-primary">Audit erneut ausführen</button>
    </form>
    <form method="post" data-cms-confirm="Sicherheits-Logs älter als 30 Tage endgültig löschen?">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
        <input type="hidden" name="action" value="clear_log">
        <button type="submit" class="btn btn-outline-secondary">Alte Logs bereinigen</button>
    </form>
</div>

<?php $logRetention = is_array($data['log_retention'] ?? null) ? $data['log_retention'] : ['activity_log' => 0, 'audit_log' => 0, 'minimum' => 90]; ?>
<div class="card security-panel-card mb-4" id="log-retention">
    <div class="card-header">
        <div class="card-title-wrap">
            <p class="card-eyebrow">Datenschutz</p>
            <h3 class="card-title">Aufbewahrung der Protokolle</h3>
            <div class="card-title-meta">Aktivitäts- und Audit-Protokoll enthalten IP-Adressen und Nutzerbezug. Standard: keine automatische Löschung (Nachweispflicht). Die Frist gehört ins Verarbeitungsverzeichnis.</div>
        </div>
    </div>
    <div class="card-body">
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
            <input type="hidden" name="action" value="save_log_retention">
            <div class="col-md-4">
                <label class="form-label" for="retention-activity-log">Aktivitätsprotokoll (Tage)</label>
                <input type="number" class="form-control" id="retention-activity-log" name="activity_log_days" min="0" max="3650" step="1" value="<?php echo (int)($logRetention['activity_log'] ?? 0); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="retention-audit-log">Audit-Protokoll (Tage)</label>
                <input type="number" class="form-control" id="retention-audit-log" name="audit_log_days" min="0" max="3650" step="1" value="<?php echo (int)($logRetention['audit_log'] ?? 0); ?>">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-primary">Fristen speichern</button>
            </div>
            <div class="col-12 form-hint">0 = nie automatisch löschen. Sonst mindestens <?php echo (int)($logRetention['minimum'] ?? 90); ?> Tage, höchstens 3650. Gelöscht wird beim täglichen Cron; der Filter <code>cms_data_retention_days</code> hat Vorrang.</div>
        </form>
    </div>
</div>

<!-- Sicherheitsprüfungen -->
<div class="card security-panel-card mb-4">
    <div class="card-header">
        <div class="card-title-wrap">
            <p class="card-eyebrow">Checks</p>
            <h3 class="card-title">Sicherheitsprüfungen</h3>
            <div class="card-title-meta">Kritische, warnende und bestandene Prüfungen in einer kompakten Matrix.</div>
        </div>
    </div>
    <div class="table-responsive security-table-wrap">
        <table class="table table-vcenter card-table security-table-modern">
            <thead>
                <tr>
                    <th style="width:40px"></th>
                    <th>Prüfung</th>
                    <th>Status</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checks as $check): ?>
                <tr>
                    <td><?php echo $statusIcon[$check['status']] ?? ''; ?></td>
                    <td class="fw-bold"><?php echo htmlspecialchars($check['name']); ?></td>
                    <td><span class="badge <?php echo $statusBadge[$check['status']] ?? 'bg-secondary'; ?>"><?php echo htmlspecialchars(ucfirst($check['status'])); ?></span></td>
                    <td class="text-muted"><?php echo htmlspecialchars($check['detail']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Audit-Log -->
<?php if (!empty($auditLog)): ?>
<div class="card security-panel-card">
    <div class="card-header">
        <div class="card-title-wrap">
            <p class="card-eyebrow">Historie</p>
            <h3 class="card-title">Audit-Log (letzte 50 Einträge)</h3>
            <div class="card-title-meta">Read-only Verlauf sicherheitsrelevanter Änderungen und Ereignisse.</div>
        </div>
    </div>
    <div class="table-responsive security-table-wrap">
        <table class="table table-vcenter card-table table-striped security-table-modern">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Aktion</th>
                    <th>Benutzer</th>
                    <th>Details</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($auditLog as $entry): ?>
                <tr>
                    <td class="text-nowrap"><?php echo htmlspecialchars($entry['created_at'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($entry['action'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars((string)($entry['user_id'] ?? '-')); ?></td>
                    <td>
                        <div><?php echo htmlspecialchars((string)($entry['details'] ?? ($entry['description'] ?? ''))); ?></div>
                        <?php if (!empty($entry['category']) || !empty($entry['severity'])): ?>
                            <div class="text-secondary small">
                                <?php echo htmlspecialchars((string)($entry['category'] ?? '')); ?>
                                <?php if (!empty($entry['severity'])): ?>· <?php echo htmlspecialchars((string)$entry['severity']); ?><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo htmlspecialchars($entry['ip_address'] ?? '-'); ?></code></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="card security-panel-card">
    <div class="card-body text-center text-muted">
        <p>Kein Audit-Log vorhanden. Log-Einträge werden automatisch bei sicherheitsrelevanten Ereignissen erstellt.</p>
    </div>
</div>
<?php endif; ?>

    </div>
</div>
