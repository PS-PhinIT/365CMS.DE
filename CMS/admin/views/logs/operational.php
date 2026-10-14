<?php
declare(strict_types=1);

if (!defined('ABSPATH') || !defined('CMS_ADMIN_SYSTEM_VIEW')) {
    exit;
}

$logs = is_array($data['logs'] ?? null) ? $data['logs'] : [];
$entries = is_array($logs['operational_audit_entries'] ?? null) ? $logs['operational_audit_entries'] : [];
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Logs &amp; Audit</div>
                <h2 class="page-title">Operativer Log</h2>
                <div class="text-secondary mt-1">System-, Backup-, Cron- und Performance-Ereignisse chronologisch.</div>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">
        <?php $alertData = $alert ?? []; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>

        <div class="card">
            <div class="card-body border-bottom">
                <div class="row g-2">
                    <div class="col-md-5"><input type="search" class="form-control" id="opLogSearch" aria-label="Betriebsprotokoll durchsuchen" placeholder="Suche in Aktion oder Meldung..."></div>
                    <div class="col-md-3">
                        <select class="form-select" id="opLogArea" aria-label="Nach Bereich filtern">
                            <option value="">Bereich (alle)</option>
                            <option value="system">System</option>
                            <option value="backup">Backup</option>
                            <option value="performance">Performance</option>
                            <option value="cron">Cron</option>
                            <option value="logs">Logs</option>
                            <option value="update">Update</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" id="opLogLevel" aria-label="Nach Level filtern">
                            <option value="">Level (alle)</option>
                            <option value="info">Info</option>
                            <option value="warning">Warning</option>
                            <option value="error">Error</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" id="opLogRange" aria-label="Zeitraum wählen">
                            <option value="1">Heute</option>
                            <option value="7" selected>7 Tage</option>
                            <option value="30">30 Tage</option>
                            <option value="0">Alles</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table table-striped">
                    <thead><tr><th>Zeitpunkt</th><th>Bereich</th><th>Aktion</th><th>Level</th><th>Meldung</th></tr></thead>
                    <tbody id="opLogBody">
                    <?php if ($entries === []): ?>
                        <tr><td colspan="5" class="text-center text-secondary py-4">Keine operativen Audit-Einträge gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($entries as $entry): ?>
                            <?php
                            $group = strtolower((string) ($entry['group'] ?? 'system'));
                            $severity = strtolower((string) ($entry['severity'] ?? 'info'));
                            $areaChip = in_array($group, ['system', 'monitoring'], true) ? 'bg-blue-lt text-blue'
                                : ($group === 'backup' ? 'bg-success-lt text-success'
                                : ($group === 'cron' ? 'bg-warning-lt text-warning' : 'bg-secondary-lt text-secondary'));
                            $levelChip = in_array($severity, ['critical', 'error'], true) ? 'bg-danger-lt text-danger'
                                : ($severity === 'warning' ? 'bg-warning-lt text-warning' : 'bg-success-lt text-success');
                            ?>
                            <tr data-ts="<?php echo htmlspecialchars((string) ($entry['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-group="<?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?>" data-level="<?php echo htmlspecialchars($severity, ENT_QUOTES, 'UTF-8'); ?>">
                                <td class="text-nowrap"><?php echo htmlspecialchars((string) ($entry['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="badge <?php echo $areaChip; ?>"><?php echo htmlspecialchars((string) ($entry['group_label'] ?? $group), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><code><?php echo htmlspecialchars((string) ($entry['action'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td><span class="badge <?php echo $levelChip; ?>"><?php echo htmlspecialchars($severity, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td class="small"><?php echo htmlspecialchars((string) ($entry['details'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <div class="text-secondary small" id="opLogCounter">0 Einträge</div>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" id="opLogPrev">Zurück</button>
                    <button type="button" class="btn btn-outline-secondary" id="opLogNext">Weiter</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script <?= \CMS\Security::instance()->nonceAttr() ?>>
(function () {
    const body = document.getElementById('opLogBody');
    if (!body) return;
    const rows = Array.from(body.querySelectorAll('tr[data-ts]'));
    const search = document.getElementById('opLogSearch');
    const area = document.getElementById('opLogArea');
    const level = document.getElementById('opLogLevel');
    const range = document.getElementById('opLogRange');
    const counter = document.getElementById('opLogCounter');
    const prev = document.getElementById('opLogPrev');
    const next = document.getElementById('opLogNext');
    const pageSize = 25;
    let page = 1;

    function filterRows() {
        const term = (search.value || '').toLowerCase().trim();
        const areaValue = area.value;
        const levelValue = level.value;
        const days = parseInt(range.value || '0', 10);
        const now = Date.now();

        return rows.filter((row) => {
            if (term && !row.textContent.toLowerCase().includes(term)) return false;
            if (areaValue && row.dataset.group !== areaValue) return false;
            if (levelValue && row.dataset.level !== levelValue) return false;
            if (days > 0) {
                const ts = Date.parse(row.dataset.ts || '');
                if (!Number.isNaN(ts) && (now - ts) > (days * 86400000)) return false;
            }
            return true;
        });
    }

    function render() {
        const filtered = filterRows();
        const maxPage = Math.max(1, Math.ceil(filtered.length / pageSize));
        page = Math.min(page, maxPage);
        const start = (page - 1) * pageSize;
        const visible = new Set(filtered.slice(start, start + pageSize));
        rows.forEach((row) => {
            row.style.display = visible.has(row) ? '' : 'none';
        });
        counter.textContent = filtered.length + ' Einträge';
        prev.disabled = page <= 1;
        next.disabled = page >= maxPage;
    }

    [search, area, level, range].forEach((el) => el && el.addEventListener('input', () => {
        page = 1;
        render();
    }));
    prev && prev.addEventListener('click', () => {
        if (page > 1) {
            page--;
            render();
        }
    });
    next && next.addEventListener('click', () => {
        page++;
        render();
    });

    render();
})();
</script>
