<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
if (!defined('CMS_ADMIN_PERFORMANCE_VIEW')) exit;

$sessions = $data['sessions'] ?? [];
$recentSessions = $sessions['recent_sessions'] ?? [];
$settings = $data['settings'] ?? [];
$formatDuration = static function (int $seconds): string {
    if ($seconds >= 86400) return number_format($seconds / 86400, 1, ',', '.') . ' Tage';
    if ($seconds >= 3600) return number_format($seconds / 3600, 1, ',', '.') . ' Stunden';
    if ($seconds >= 60) return number_format($seconds / 60, 1, ',', '.') . ' Minuten';
    return $seconds . ' Sek.';
};
$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2, ',', '.') . ' KB';
    return $bytes . ' B';
};
?>
<div class="page-header d-print-none admin-redesign-header"><div class="container-xl"><div class="row g-2 align-items-center"><div class="col"><div class="page-pretitle">Performance</div><h2 class="page-title">Session-Verwaltung</h2><div class="text-secondary mt-1">Aktive Sitzungen, abgelaufene Sessions und Timeout-Konfiguration an einem Ort.</div></div></div></div></div>
<div class="page-body admin-redesign-page"><div class="container-xl admin-redesign-shell">
    <?php if (!empty($alert)): ?><div class="alert alert-<?php echo htmlspecialchars($alert['type'] ?? 'info'); ?> mb-4"><?php echo htmlspecialchars($alert['message'] ?? ''); ?></div><?php endif; ?>
    <?php require __DIR__ . '/subnav.php'; ?>

    <div class="row row-deck row-cards mb-4">
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="subheader">Aktive Sessions</div><div class="h1 mb-0"><?php echo (int)($sessions['active_sessions'] ?? 0); ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="subheader">Abgelaufene Sessions</div><div class="h1 mb-0 text-warning"><?php echo (int)($sessions['expired_sessions'] ?? 0); ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="subheader">Session-Dateien</div><div class="h1 mb-0"><?php echo (int)($sessions['session_dir_files'] ?? 0); ?></div><div class="text-secondary"><?php echo htmlspecialchars($formatBytes((int)($sessions['session_dir_size'] ?? 0))); ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="subheader">Admin Timeout</div><div class="h1 mb-0"><?php echo htmlspecialchars($formatDuration((int)($settings['perf_session_timeout_admin'] ?? 28800))); ?></div></div></div></div>
    </div>

    <div class="row row-cards mb-4">
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">Pflege</h3></div>
                <div class="card-body d-flex flex-column gap-3">
                    <form method="post" data-confirm-title="Abgelaufene Sessions bereinigen" data-confirm-message="Abgelaufene Sessions wirklich bereinigen? Veraltete DB- und Dateisessions werden sofort entfernt." data-confirm-text="Sessions bereinigen" data-confirm-class="btn-primary" data-confirm-status-class="bg-primary">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                        <input type="hidden" name="action" value="clear_expired_sessions">
                        <button type="submit" class="btn btn-primary w-100">Abgelaufene Sessions bereinigen</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title">Timeout-Konfiguration</h3></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                        <input type="hidden" name="action" value="save_session_settings">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="fld-perf_session_timeout_admin" class="form-label">Admin Timeout (Sek.)</label>
                                    <input id="fld-perf_session_timeout_admin" class="form-control" type="number" min="300" max="604800" step="1" name="perf_session_timeout_admin" value="<?php echo htmlspecialchars((string)($settings['perf_session_timeout_admin'] ?? '28800')); ?>" data-timeout-human>
                                    <div class="performance-timeout-hint" data-timeout-hint-for="perf_session_timeout_admin"></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="fld-perf_session_timeout_member" class="form-label">Member Timeout (Sek.)</label>
                                    <input id="fld-perf_session_timeout_member" class="form-control" type="number" min="300" max="31536000" step="1" name="perf_session_timeout_member" value="<?php echo htmlspecialchars((string)($settings['perf_session_timeout_member'] ?? '2592000')); ?>" data-timeout-human>
                                    <div class="performance-timeout-hint" data-timeout-hint-for="perf_session_timeout_member"></div>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Timeouts speichern</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card"><div class="card-header"><h3 class="card-title">Zuletzt aktive Sessions</h3></div><div class="table-responsive"><table class="table table-vcenter card-table table-striped"><thead><tr><th>User ID</th><th>IP</th><th>User Agent</th><th>Letzte Aktivität</th><th>Läuft ab</th></tr></thead><tbody><?php if (empty($recentSessions)): ?><tr><td colspan="5" class="text-center text-secondary py-4">Keine Session-Daten verfügbar.</td></tr><?php else: ?><?php foreach ($recentSessions as $session): ?><tr><td><?php echo (int)$session['user_id']; ?></td><td><?php echo htmlspecialchars((string)$session['ip_address']); ?></td><td class="text-break"><?php echo htmlspecialchars(cms_truncate_text((string)$session['user_agent'], 90)); ?></td><td><?php echo htmlspecialchars((string)$session['last_activity']); ?></td><td><?php echo htmlspecialchars((string)$session['expires_at']); ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div>
</div></div>
<script <?= \CMS\Security::instance()->nonceAttr() ?>>
(() => {
    const formatTimeoutHint = (secondsValue) => {
        if (typeof window.formatSeconds === 'function') {
            return `= ${window.formatSeconds(secondsValue)}`;
        }

        const seconds = Math.max(0, Number.parseInt(String(secondsValue), 10) || 0);
        if (seconds >= 86400) return `= ${seconds / 86400} Tage`;
        if (seconds >= 3600) return `= ${seconds / 3600} Stunden`;
        if (seconds >= 60) return `= ${seconds / 60} Minuten`;
        return `= ${seconds} Sekunden`;
    };

    document.querySelectorAll('input[data-timeout-human]').forEach((input) => {
        const hint = document.querySelector(`[data-timeout-hint-for="${input.name}"]`);
        if (!hint) {
            return;
        }

        const refresh = () => {
            hint.textContent = formatTimeoutHint(Number.parseInt(input.value, 10) || 0);
        };

        input.addEventListener('input', refresh);
        input.addEventListener('change', refresh);
        refresh();
    });
})();
</script>
