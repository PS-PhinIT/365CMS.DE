<?php
declare(strict_types=1);

if (!defined('ABSPATH') || !defined('CMS_ADMIN_SYSTEM_VIEW')) {
    exit;
}

$logs = is_array($data['logs'] ?? null) ? $data['logs'] : [];
$entries = is_array($logs['error_log_entries'] ?? null) ? $logs['error_log_entries'] : [];
$entryCount = count($entries);
$previewEntries = array_slice($entries, 0, 10);
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Logs &amp; Audit</div>
                <h2 class="page-title">PHP-Fehlerlog</h2>
                <div class="text-secondary mt-1">Laufzeitfehler, Notices und Stack Traces aus der PHP-Runtime.</div>
            </div>
            <div class="col-auto">
                <form method="post" data-confirm-message="PHP-Fehlerlog wirklich leeren?" data-confirm-title="Log leeren" data-confirm-text="Leeren" data-confirm-class="btn-danger" data-confirm-status-class="bg-danger">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="clear_logs">
                    <button type="submit" class="btn btn-outline-danger">Log leeren</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">
        <?php $alertData = $alert ?? []; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>

        <div class="card">
            <div class="card-body pb-0">
                <?php if ($entryCount > 10): ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="phpErrorToggle" data-total="<?php echo $entryCount; ?>">Alle <?php echo $entryCount; ?> Einträge anzeigen ▾</button>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table table-striped">
                    <thead><tr><th>Zeitstempel</th><th>Schweregrad</th><th>Datei + Zeile</th><th>Meldung</th></tr></thead>
                    <tbody id="phpErrorBody" data-expanded="0">
                    <?php if ($entries === []): ?>
                        <tr><td colspan="4" class="text-center text-secondary py-4">Keine lesbaren Einträge im PHP-Fehlerlog gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($entries as $index => $entry): ?>
                            <?php
                            $severity = strtoupper((string) ($entry['type'] ?? 'UNKNOWN'));
                            $severityClass = str_contains($severity, 'FATAL') ? 'bg-danger-lt text-danger'
                                : (str_contains($severity, 'ERROR') || str_contains($severity, 'WARNING') ? 'bg-warning-lt text-warning'
                                : (str_contains($severity, 'NOTICE') ? 'bg-secondary-lt text-secondary' : 'bg-secondary-lt text-secondary'));
                            $message = (string) ($entry['message'] ?? '');
                            $fileLine = '—';
                            if (preg_match('/ in (.+?) on line (\d+)/i', $message, $matches) === 1) {
                                $fileLine = $matches[1] . ':' . $matches[2];
                            }
                            ?>
                            <tr class="php-error-row<?php echo $index >= 10 ? ' d-none php-error-row-extra' : ''; ?>">
                                <td class="text-nowrap"><?php echo htmlspecialchars((string) ($entry['timestamp'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="badge <?php echo $severityClass; ?>"><?php echo htmlspecialchars($severity, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><code class="small" style="font-size:12px;"><?php echo htmlspecialchars($fileLine, ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td class="small"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></td>
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
    const toggle = document.getElementById('phpErrorToggle');
    if (!toggle) return;
    const extraRows = Array.from(document.querySelectorAll('.php-error-row-extra'));
    const total = parseInt(toggle.dataset.total || '0', 10) || (extraRows.length + 10);
    let expanded = false;
    toggle.addEventListener('click', function () {
        expanded = !expanded;
        extraRows.forEach((row) => {
            row.classList.toggle('d-none', !expanded);
        });
        toggle.textContent = expanded ? 'Nur letzte 10 Einträge anzeigen ▴' : ('Alle ' + total + ' Einträge anzeigen ▾');
    });
})();
</script>
