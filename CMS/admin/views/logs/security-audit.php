<?php
declare(strict_types=1);

if (!defined('ABSPATH') || !defined('CMS_ADMIN_SYSTEM_VIEW')) {
    exit;
}

$securityAudit = is_array($data['security_audit'] ?? null) ? $data['security_audit'] : [];
$checks = is_array($securityAudit['checks'] ?? null) ? $securityAudit['checks'] : [];
$stats = is_array($securityAudit['stats'] ?? null) ? $securityAudit['stats'] : [];
$auditLog = is_array($securityAudit['audit_log'] ?? null) ? $securityAudit['audit_log'] : [];

usort($checks, static function (array $left, array $right): int {
    $order = ['critical' => 0, 'warning' => 1, 'ok' => 2];
    $leftRank = $order[(string) ($left['status'] ?? 'warning')] ?? 1;
    $rightRank = $order[(string) ($right['status'] ?? 'warning')] ?? 1;
    if ($leftRank !== $rightRank) {
        return $leftRank <=> $rightRank;
    }
    return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
});
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Logs &amp; Audit</div>
                <h2 class="page-title">Sicherheits-Audit</h2>
                <div class="text-secondary mt-1">19 Prüfpunkte für Sicherheits-Baseline und Systemhärtung.</div>
            </div>
            <div class="col-auto d-flex flex-wrap gap-2">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($securityCsrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="run_audit">
                    <button type="submit" class="btn btn-primary">Audit erneut ausführen</button>
                </form>
                <form method="post" data-confirm-message="Alte Sicherheits-Audit-Logs wirklich bereinigen?" data-confirm-title="Sicherheits-Audit-Logs bereinigen" data-confirm-text="Bereinigen" data-confirm-class="btn-danger" data-confirm-status-class="bg-danger">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($securityCsrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="clear_log">
                    <button type="submit" class="btn btn-outline-secondary">Alle Logs bereinigen</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">
        <?php $alertData = $alert ?? []; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>

        <div class="row row-deck row-cards mb-4">
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Geprüft</div><div class="h1 mb-0"><?php echo (int) ($stats['total'] ?? 0); ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Bestanden</div><div class="h1 mb-0 text-success"><?php echo (int) ($stats['passed'] ?? 0); ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Warnungen</div><div class="h1 mb-0 text-warning"><?php echo (int) ($stats['warning'] ?? 0); ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Kritisch</div><div class="h1 mb-0 text-danger"><?php echo (int) ($stats['critical'] ?? 0); ?></div></div></div></div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title mb-0">Sicherheitsprüfungen</h3></div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table security-table-modern">
                    <thead><tr><th>Prüfung</th><th>Status</th><th>Details</th></tr></thead>
                    <tbody>
                    <?php foreach ($checks as $check): ?>
                        <?php $status = (string) ($check['status'] ?? 'warning'); ?>
                        <tr>
                            <td class="fw-semibold"><?php echo htmlspecialchars((string) ($check['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <span class="badge <?php echo $status === 'ok' ? 'bg-success' : ($status === 'critical' ? 'bg-danger' : 'bg-warning'); ?>">
                                    <?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td class="text-secondary"><?php echo htmlspecialchars((string) ($check['detail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h3 class="card-title mb-0">Audit-Log (letzte 50 Einträge)</h3>
                <div class="d-flex gap-2 flex-wrap">
                    <input type="search" class="form-control form-control-sm" id="securityAuditSearch" aria-label="Audit-Log durchsuchen" placeholder="Suche..." style="min-width: 180px;">
                    <select class="form-select form-select-sm" id="securityAuditType" aria-label="Nach Aktionstyp filtern">
                        <option value="">Aktionstyp: Alle</option>
                        <option value="login">Login</option>
                        <option value="error">Fehler</option>
                        <option value="firewall">Firewall</option>
                        <option value="system">System</option>
                    </select>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table table-striped">
                    <thead><tr><th>Datum</th><th>Aktion</th><th>Benutzer</th><th>Details</th><th>IP</th></tr></thead>
                    <tbody id="securityAuditLogBody">
                    <?php if ($auditLog === []): ?>
                        <tr><td colspan="5" class="text-center text-secondary py-4">Kein Audit-Log vorhanden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($auditLog as $entry): ?>
                            <?php
                            $action = (string) ($entry['action'] ?? '');
                            $type = str_contains($action, 'login') ? 'login'
                                : (str_contains($action, 'failed') || str_contains($action, 'error') ? 'error'
                                : (str_starts_with($action, 'firewall.') ? 'firewall' : 'system'));
                            $chip = str_starts_with($action, 'auth.login') && !str_contains($action, 'failed') ? 'bg-success-lt text-success'
                                : (str_starts_with($action, 'auth.login.failed') ? 'bg-warning-lt text-warning'
                                : (str_starts_with($action, 'firewall.') || str_starts_with($action, 'security.audit.') ? 'bg-blue-lt text-blue'
                                : (str_ends_with($action, '.save') ? 'bg-secondary-lt text-secondary' : 'bg-secondary-lt text-secondary')));
                            ?>
                            <tr data-action-type="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>">
                                <td class="text-nowrap"><?php echo htmlspecialchars((string) ($entry['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="badge <?php echo $chip; ?>"><code><?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?></code></span></td>
                                <td><?php echo htmlspecialchars((string) ($entry['user_id'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="small"><?php echo htmlspecialchars((string) ($entry['details'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><code><?php echo htmlspecialchars((string) ($entry['ip_address'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script <?= \CMS\Security::instance()->nonceAttr() ?>>
(function () {
    const body = document.getElementById('securityAuditLogBody');
    if (!body) return;
    const rows = Array.from(body.querySelectorAll('tr[data-action-type]'));
    const search = document.getElementById('securityAuditSearch');
    const type = document.getElementById('securityAuditType');

    function apply() {
        const term = (search.value || '').toLowerCase().trim();
        const typeValue = type.value;
        rows.forEach((row) => {
            const matchesSearch = term === '' || row.textContent.toLowerCase().includes(term);
            const matchesType = typeValue === '' || row.dataset.actionType === typeValue;
            row.style.display = matchesSearch && matchesType ? '' : 'none';
        });
    }

    search && search.addEventListener('input', apply);
    type && type.addEventListener('change', apply);
    apply();
})();
</script>
