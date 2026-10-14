<?php
declare(strict_types=1);

if (!defined('ABSPATH') || !defined('CMS_ADMIN_SYSTEM_VIEW')) {
    exit;
}

$logs = is_array($data['logs'] ?? null) ? $data['logs'] : [];
$securityAudit = is_array($data['security_audit'] ?? null) ? $data['security_audit'] : [];
$activityFeed = is_array($data['activity_feed'] ?? null) ? $data['activity_feed'] : [];

$logFiles = is_array($logs['files'] ?? null) ? $logs['files'] : [];
$operationalAuditEntries = is_array($logs['operational_audit_entries'] ?? null) ? $logs['operational_audit_entries'] : [];
$errorEntries = is_array($logs['error_log_entries'] ?? null) ? $logs['error_log_entries'] : [];
$updateEntries = is_array($logs['update_history_entries'] ?? null) ? $logs['update_history_entries'] : [];

$securityStats = is_array($securityAudit['stats'] ?? null) ? $securityAudit['stats'] : [];
$totalEntries = count($activityFeed);
$entries24h = 0;
$openErrors = 0;
$now = time();
foreach ($activityFeed as $item) {
    if (!is_array($item)) {
        continue;
    }
    $timestamp = strtotime((string) ($item['timestamp'] ?? ''));
    if (is_int($timestamp) && $timestamp > 0 && ($now - $timestamp) <= 86400) {
        $entries24h++;
    }
    $level = strtolower((string) ($item['level'] ?? 'info'));
    if (in_array($level, ['error', 'critical'], true)) {
        $openErrors++;
    }
}

$auditPassed = (int) ($securityStats['passed'] ?? 0);
$auditTotal = (int) ($securityStats['total'] ?? 19);
$auditWarnings = (int) ($securityStats['warning'] ?? 0);
$auditCritical = (int) ($securityStats['critical'] ?? 0);
$auditHealthy = $auditCritical === 0 && $auditWarnings === 0;

$areaRows = [
    [
        'key' => 'operational',
        'name' => 'Operativer Log',
        'icon' => 'ti-activity',
        'chip' => 'bg-blue-lt text-blue',
        'description' => 'System-, Backup-, Cron- und Performance-Ereignisse.',
        'last' => (string) (($operationalAuditEntries[0]['created_at'] ?? '—')),
        'status' => count($operationalAuditEntries) . ' Einträge',
    ],
    [
        'key' => 'security',
        'name' => 'Sicherheits-Audit',
        'icon' => 'ti-shield-check',
        'chip' => $auditCritical > 0 ? 'bg-danger-lt text-danger' : 'bg-success-lt text-success',
        'description' => '19 Prüfpunkte für Härtung und Sicherheits-Baseline.',
        'last' => (string) (($securityAudit['audit_log'][0]['created_at'] ?? '—')),
        'status' => $auditPassed . '/' . $auditTotal . ' bestanden',
    ],
    [
        'key' => 'php',
        'name' => 'PHP-Fehlerlog',
        'icon' => 'ti-code',
        'chip' => $errorEntries !== [] ? 'bg-warning-lt text-warning' : 'bg-success-lt text-success',
        'description' => 'Runtime-Fehler, Notices und Warnungen.',
        'last' => (string) (($errorEntries[0]['timestamp'] ?? '—')),
        'status' => count($errorEntries) . ' Einträge',
    ],
    [
        'key' => 'channels',
        'name' => 'Kanal-Logs',
        'icon' => 'ti-messages',
        'chip' => 'bg-secondary-lt text-secondary',
        'description' => 'Monolog-Kanäle und ausgewählte Dateilogs.',
        'last' => (string) (($logFiles[0]['modified_at'] ?? '—')),
        'status' => count($logFiles) . ' Dateien',
    ],
    [
        'key' => 'updates',
        'name' => 'Update-Historie',
        'icon' => 'ti-refresh',
        'chip' => 'bg-success-lt text-success',
        'description' => 'Update-Verlauf aus Core, Plugin und Theme.',
        'last' => (string) (($updateEntries[0]['timestamp'] ?? '—')),
        'status' => count($updateEntries) . ' Vorgänge',
    ],
];
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Logs &amp; Audit</div>
                <h2 class="page-title">Logs &amp; Audit</h2>
                <div class="text-secondary mt-1">Zentrales Log-Center — Betrieb, Sicherheit, Fehler und Updates an einem Ort.</div>
            </div>
            <div class="col-auto d-flex flex-wrap gap-2">
                <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="export_diagnostic_report">
                    <button type="submit" class="btn btn-outline-secondary">Diagnosebericht exportieren</button>
                </form>
                <form method="post" class="d-inline" data-confirm-message="Wirklich alle CMS-Logs bereinigen?" data-confirm-title="Alle Logs bereinigen" data-confirm-text="Bereinigen" data-confirm-class="btn-danger" data-confirm-status-class="bg-danger">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="clear_all_cms_logs">
                    <button type="submit" class="btn btn-outline-secondary">Alle Logs bereinigen</button>
                </form>
                <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($securityCsrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="run_audit">
                    <button type="submit" class="btn btn-primary">Audit ausführen</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">
        <?php $alertData = $alert ?? []; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>

        <div class="row row-deck row-cards mb-4">
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Einträge gesamt</div><div class="h1 mb-0"><?php echo $totalEntries; ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Letzte 24h</div><div class="h1 mb-0 text-blue"><?php echo $entries24h; ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Fehler offen</div><div class="h1 mb-0 <?php echo $openErrors > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $openErrors; ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3">
                <div class="card">
                    <div class="card-body">
                        <div class="subheader">Audit-Status</div>
                        <div class="h2 mb-1 <?php echo $auditHealthy ? 'text-success' : 'text-danger'; ?>"><?php echo $auditPassed; ?>/<?php echo $auditTotal; ?> checks passed</div>
                        <a class="small" href="<?php echo htmlspecialchars('/admin/logs/security-audit', ENT_QUOTES, 'UTF-8'); ?>">Zum Sicherheits-Audit</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title mb-0">Schnellübersicht — Log-Bereiche</h3>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table table-striped">
                    <thead>
                    <tr><th>Bereich</th><th>Beschreibung</th><th>Zuletzt</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($areaRows as $row): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <i class="ti <?php echo htmlspecialchars((string) $row['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
                                    <span class="fw-semibold"><?php echo htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            </td>
                            <td class="text-secondary"><?php echo htmlspecialchars((string) $row['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="small"><?php echo htmlspecialchars((string) $row['last'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge <?php echo htmlspecialchars((string) $row['chip'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $row['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title mb-0">Letzte Aktivitäten</h3></div>
            <div class="card-body border-bottom">
                <div class="row g-2">
                    <div class="col-md-4"><input type="search" class="form-control" id="logsActivitySearch" placeholder="Suche in Aktionen oder Meldungen..."></div>
                    <div class="col-md-3">
                        <select class="form-select" id="logsActivityArea">
                            <option value="">Bereich (alle)</option>
                            <option value="system">System</option>
                            <option value="auth">Auth</option>
                            <option value="backup">Backup</option>
                            <option value="firewall">Firewall</option>
                            <option value="cron">Cron</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" id="logsActivityLevel">
                            <option value="">Level (alle)</option>
                            <option value="info">Info</option>
                            <option value="warning">Warning</option>
                            <option value="error">Error</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" id="logsActivityRange">
                            <option value="1">Heute</option>
                            <option value="7" selected>7 Tage</option>
                            <option value="30">30 Tage</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table table-striped">
                    <thead>
                    <tr><th>Zeitpunkt</th><th>Bereich</th><th>Aktion</th><th>Level</th><th>Meldung</th></tr>
                    </thead>
                    <tbody id="logsActivityBody">
                    <?php foreach ($activityFeed as $row): ?>
                        <?php
                        $area = strtolower((string) ($row['area'] ?? 'system'));
                        $level = strtolower((string) ($row['level'] ?? 'info'));
                        $areaChip = match ($area) {
                            'auth' => 'bg-secondary-lt text-secondary',
                            'backup' => 'bg-success-lt text-success',
                            'firewall', 'system' => 'bg-blue-lt text-blue',
                            'cron' => 'bg-warning-lt text-warning',
                            default => 'bg-secondary-lt text-secondary',
                        };
                        $levelChip = match ($level) {
                            'error', 'critical' => 'bg-danger-lt text-danger',
                            'warning' => 'bg-warning-lt text-warning',
                            default => 'bg-success-lt text-success',
                        };
                        ?>
                        <tr data-ts="<?php echo htmlspecialchars((string) ($row['timestamp'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-area="<?php echo htmlspecialchars($area, ENT_QUOTES, 'UTF-8'); ?>" data-level="<?php echo htmlspecialchars($level, ENT_QUOTES, 'UTF-8'); ?>">
                            <td class="text-nowrap"><?php echo htmlspecialchars((string) ($row['timestamp'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge <?php echo $areaChip; ?>"><?php echo htmlspecialchars((string) ($row['area'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><code><?php echo htmlspecialchars((string) ($row['action'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><span class="badge <?php echo $levelChip; ?>"><?php echo htmlspecialchars($level, ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td class="small"><?php echo htmlspecialchars((string) ($row['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <div class="text-secondary small" id="logsActivityCounter">0 Einträge</div>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" id="logsActivityPrev">Zurück</button>
                    <button type="button" class="btn btn-outline-secondary" id="logsActivityNext">Weiter</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script <?= \CMS\Security::instance()->nonceAttr() ?>>
(function () {
    const body = document.getElementById('logsActivityBody');
    if (!body) return;
    const rows = Array.from(body.querySelectorAll('tr'));
    const search = document.getElementById('logsActivitySearch');
    const area = document.getElementById('logsActivityArea');
    const level = document.getElementById('logsActivityLevel');
    const range = document.getElementById('logsActivityRange');
    const counter = document.getElementById('logsActivityCounter');
    const prev = document.getElementById('logsActivityPrev');
    const next = document.getElementById('logsActivityNext');
    const pageSize = 25;
    let page = 1;

    function apply() {
        const term = (search.value || '').toLowerCase().trim();
        const areaValue = area.value;
        const levelValue = level.value;
        const days = parseInt(range.value || '7', 10);
        const now = Date.now();

        const filtered = rows.filter((row) => {
            const text = row.textContent.toLowerCase();
            if (term && !text.includes(term)) return false;
            if (areaValue && row.dataset.area !== areaValue) return false;
            if (levelValue && row.dataset.level !== levelValue) return false;
            const ts = Date.parse(row.dataset.ts || '');
            if (!Number.isNaN(ts) && days > 0 && (now - ts) > (days * 86400000)) return false;
            return true;
        });

        const maxPage = Math.max(1, Math.ceil(filtered.length / pageSize));
        page = Math.min(page, maxPage);
        const start = (page - 1) * pageSize;
        const visibleRows = new Set(filtered.slice(start, start + pageSize));

        rows.forEach((row) => {
            row.style.display = visibleRows.has(row) ? '' : 'none';
        });

        counter.textContent = filtered.length + ' Einträge';
        prev.disabled = page <= 1;
        next.disabled = page >= maxPage;
    }

    [search, area, level, range].forEach((el) => el && el.addEventListener('input', () => {
        page = 1;
        apply();
    }));
    prev && prev.addEventListener('click', () => {
        if (page > 1) {
            page--;
            apply();
        }
    });
    next && next.addEventListener('click', () => {
        page++;
        apply();
    });
    apply();
})();
</script>
