<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SYSTEM_VIEW')) {
    exit;
}

$database    = $data['database'] ?? [];
$tables      = $data['tables'] ?? [];
$permissions = $data['permissions'] ?? [];
$runtime     = $data['runtime'] ?? [];
$errorReports = $data['error_reports'] ?? [];
$bootstrapProfile = is_array($runtime['bootstrap'] ?? null) ? $runtime['bootstrap'] : [];

$missingCount = 0;
$errorCount = 0;
// Release-Prüfung 4.1.0 (PERF, db:5): Die Ansicht führt kein CHECK TABLE mehr aus, Zeilen sind Schätzwerte.
$tablesChecked = false;
foreach ($tables as $tableInfo) {
    if (!is_array($tableInfo)) {
        continue;
    }

    if (empty($tableInfo['exists'])) {
        $missingCount++;
    }
    if (!array_key_exists('checked', $tableInfo) || !empty($tableInfo['checked'])) {
        $tablesChecked = true;
    }

    $status = (string)($tableInfo['status'] ?? 'OK');
    if (!in_array(strtolower($status), ['ok', 'missing'], true)) {
        $errorCount++;
    }
}
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Diagnose</div>
                <h2 class="page-title">Datenbank</h2>
                <div class="text-secondary mt-1">Wartungsaktionen, Tabellenprüfung und technische Datenbank-/Runtime-Diagnose für das System.</div>
            </div>
            <div class="col-auto d-flex gap-2 flex-wrap">
                <a href="<?php echo htmlspecialchars('/admin/monitor-warnings', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-warning">Warnzentrale</a>
                <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                    <input type="hidden" name="action" value="clear_cache">
                    <button type="submit" class="btn btn-outline-warning">Cache leeren</button>
                </form>
                <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                    <input type="hidden" name="action" value="optimize_db">
                    <button type="submit" class="btn btn-outline-primary">DB optimieren</button>
                </form>
                <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                    <input type="hidden" name="action" value="export_diagnostic_report">
                    <button type="submit" class="btn btn-outline-success">Diagnosebericht exportieren</button>
                </form>
                <a href="<?php echo htmlspecialchars('/admin/monitor-assets', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Assets öffnen</a>
                <a href="<?php echo htmlspecialchars('/admin/logs', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Logs &amp; Audit</a>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>

        <?php require __DIR__ . '/subnav.php'; ?>

        <div class="row row-deck row-cards mb-4">
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">DB-Status</div><div class="h1 mb-0 <?php echo !empty($database['connected']) ? 'text-success' : 'text-danger'; ?>"><?php echo !empty($database['connected']) ? 'Online' : 'Offline'; ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Fehlende Tabellen</div><div class="h1 mb-0 text-danger"><?php echo $missingCount; ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Tabellen mit Problemen</div><div class="h1 mb-0 text-warning"><?php echo $errorCount; ?></div><?php if (!$tablesChecked && $tables !== []): ?><div class="text-secondary small mt-1">ohne CHECK TABLE, Prüfung über „Tabellen reparieren“</div><?php endif; ?></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">CMS-Tabellen</div><div class="h1 mb-0"><?php echo htmlspecialchars((string)($database['cms_tables'] ?? '-')); ?></div></div></div></div>
        </div>

        <div class="row row-deck row-cards mb-4">
            <?php
            $runtimeActive = !empty($runtime['enabled']) || !empty($runtime['profile_active']) || !empty($bootstrapProfile['active']);
            $runtimeLabel = !empty($runtime['enabled']) ? 'Debug aktiv' : ($runtimeActive ? 'Profil aktiv' : 'Inaktiv');
            ?>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Runtime-Telemetrie</div><div class="h1 mb-0 <?php echo $runtimeActive ? 'text-success' : 'text-muted'; ?>"><?php echo htmlspecialchars($runtimeLabel); ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Request-Laufzeit</div><div class="h1 mb-0"><?php echo htmlspecialchars((string)($runtime['elapsed_time_ms'] ?? '0')); ?> <span class="fs-5 text-secondary">ms</span></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">SQL-Queries</div><div class="h1 mb-0"><?php echo htmlspecialchars((string)($runtime['query']['count'] ?? 0)); ?></div><div class="text-secondary small mt-1"><?php echo htmlspecialchars((string)($runtime['query']['total_time_ms'] ?? 0)); ?> ms gesamt</div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Bootstrap-Modus</div><div class="h1 mb-0"><?php echo htmlspecialchars((string)($bootstrapProfile['mode'] ?? '—')); ?></div><div class="text-secondary small mt-1"><?php echo htmlspecialchars((string)($bootstrapProfile['bootstrap_ready_ms'] ?? 0)); ?> ms bis ready</div></div></div></div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
                <div>
                    <h3 class="card-title mb-0">Fehlerreports</h3>
                    <div class="text-secondary small">Reports aus CMS_Error-Alerts und Debug-Ausgaben</div>
                </div>
                <form method="post" class="d-inline" data-confirm-message="Wirklich alle gespeicherten Fehlerreports löschen?" data-confirm-title="Fehlerreports löschen" data-confirm-text="Löschen" data-confirm-class="btn-danger" data-confirm-status-class="bg-danger">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="clear_error_reports">
                    <button type="submit" class="btn btn-outline-danger btn-sm"<?php echo empty($errorReports) ? ' disabled' : ''; ?>>Fehlerreports löschen</button>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table table-striped">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Titel</th>
                            <th>Code</th>
                            <th>Quelle</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($errorReports)): ?>
                            <tr><td colspan="5" class="text-center text-secondary py-4">Noch keine Fehlerreports vorhanden.</td></tr>
                        <?php else: ?>
                            <?php foreach ($errorReports as $report): ?>
                                <tr>
                                    <td class="text-nowrap"><?php echo !empty($report['created_at']) ? htmlspecialchars(date('d.m.Y H:i', strtotime((string)$report['created_at']))) : '–'; ?></td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars((string)($report['title'] ?? 'Fehlerreport')); ?></div>
                                        <div class="text-secondary small"><?php echo htmlspecialchars((string)($report['message'] ?? '')); ?></div>
                                    </td>
                                    <td><?php echo !empty($report['error_code']) ? '<code>' . htmlspecialchars((string)$report['error_code']) . '</code>' : '<span class="text-secondary">–</span>'; ?></td>
                                    <td class="text-secondary small"><?php echo !empty($report['source_url']) ? htmlspecialchars((string)$report['source_url']) : '–'; ?></td>
                                    <td>
                                        <?php $reportHasDetails = !empty($report['error_data']) || !empty($report['context']); ?>
                                        <?php if ($reportHasDetails): ?>
                                            <details>
                                                <summary class="cursor-pointer">Anzeigen</summary>
                                                <?php if (!empty($report['error_data'])): ?>
                                                    <div class="mt-2 small text-secondary">Fehlerdaten</div>
                                                    <pre class="bg-body-tertiary border rounded p-2 mb-2"><?php echo htmlspecialchars((string)json_encode($report['error_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
                                                <?php endif; ?>
                                                <?php if (!empty($report['context'])): ?>
                                                    <div class="small text-secondary">Kontext</div>
                                                    <pre class="bg-body-tertiary border rounded p-2 mb-0"><?php echo htmlspecialchars((string)json_encode($report['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
                                                <?php endif; ?>
                                            </details>
                                        <?php else: ?>
                                            <span class="text-secondary">–</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row row-cards">
            <div class="col-12 col-xl-4">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Datenbank-Tools</h3></div>
                    <div class="card-body d-flex flex-column gap-3">
                        <div>
                            <div class="fw-semibold">Schema prüfen</div>
                            <div class="text-secondary small">Erkennt fehlende Tabellen und stößt bei Bedarf die Schema-Erstellung erneut an. „Tabellen reparieren“ prüft jede Tabelle mit CHECK TABLE und repariert nur auffällige; das kann bei großen Tabellen dauern und sie kurz sperren.</div>
                        </div>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                            <input type="hidden" name="action" value="create_tables">
                            <button type="submit" class="btn btn-outline-success w-100" <?php echo $missingCount === 0 ? 'disabled' : ''; ?>>Fehlende Tabellen erstellen</button>
                        </form>
                        <form method="post" data-cms-confirm="Tabellen-Reparatur starten?">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
                            <input type="hidden" name="action" value="repair_tables">
                            <button type="submit" class="btn btn-outline-warning w-100">Tabellen reparieren</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-8">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Dateiberechtigungen</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead><tr><th>Pfad</th><th>Lesbar</th><th>Schreibbar</th></tr></thead>
                            <tbody>
                                <?php if (empty($permissions)): ?>
                                    <tr><td colspan="3" class="text-center text-secondary py-4">Keine Berechtigungsdaten verfügbar.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($permissions as $permission): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)($permission['path'] ?? $permission['full_path'] ?? '')); ?></td>
                                            <td><span class="badge bg-<?php echo !empty($permission['readable']) ? 'success' : 'danger'; ?>-lt"><?php echo !empty($permission['readable']) ? 'Ja' : 'Nein'; ?></span></td>
                                            <td><span class="badge bg-<?php echo !empty($permission['writable']) ? 'success' : 'danger'; ?>-lt"><?php echo !empty($permission['writable']) ? 'Ja' : 'Nein'; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-header"><h3 class="card-title">Runtime- &amp; Query-Telemetrie</h3></div>
                    <div class="card-body">
                        <?php if (empty($runtime['enabled']) && empty($bootstrapProfile['active'])): ?>
                            <div class="text-secondary">Debug-Modus ist derzeit nicht aktiv. Ohne Profil- oder Debug-Laufzeitdaten können hier keine Bootstrap- oder Query-Messwerte gezeigt werden.</div>
                        <?php else: ?>
                            <div class="card border-0 shadow-none mb-4">
                                <div class="card-header px-0 pt-0"><h3 class="card-title">Bootstrap-Profil</h3></div>
                                <div class="card-body px-0 pb-0">
                                    <div class="row row-deck row-cards mb-3">
                                        <div class="col-md-3"><div class="card"><div class="card-body"><div class="subheader">Kaltstart bis Ready</div><div class="h2 mb-0"><?php echo htmlspecialchars((string)($bootstrapProfile['bootstrap_ready_ms'] ?? '0')); ?> <span class="fs-5 text-secondary">ms</span></div></div></div></div>
                                        <div class="col-md-3"><div class="card"><div class="card-body"><div class="subheader">Nach Bootstrap</div><div class="h2 mb-0"><?php echo htmlspecialchars((string)($bootstrapProfile['post_bootstrap_ms'] ?? '0')); ?> <span class="fs-5 text-secondary">ms</span></div></div></div></div>
                                        <div class="col-md-3"><div class="card"><div class="card-body"><div class="subheader">Cold-Path-Anteil</div><div class="h2 mb-0"><?php echo htmlspecialchars((string)($bootstrapProfile['cold_path_share_percent'] ?? '0')); ?> <span class="fs-5 text-secondary">%</span></div></div></div></div>
                                        <div class="col-md-3"><div class="card"><div class="card-body"><div class="subheader">Langsame Phasen</div><div class="h2 mb-0"><?php echo htmlspecialchars((string)($bootstrapProfile['slow_phase_count'] ?? 0)); ?></div><div class="text-secondary small mt-1">ab <?php echo htmlspecialchars((string)($bootstrapProfile['slow_phase_threshold_ms'] ?? 0)); ?> ms</div></div></div></div>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-lg-4">
                                            <div class="border rounded-3 h-100 p-3">
                                                <div class="fw-semibold mb-2">Profil-Kontext</div>
                                                <div class="small text-secondary mb-2">Getrennte Sicht auf CLI/API/Admin/Web statt reiner Bauchgefühl-Optimierung.</div>
                                                <dl class="row mb-0">
                                                    <dt class="col-5 text-secondary">Modus</dt><dd class="col-7 mb-2"><?php echo htmlspecialchars((string)($bootstrapProfile['mode'] ?? '—')); ?></dd>
                                                    <dt class="col-5 text-secondary">Methode</dt><dd class="col-7 mb-2"><?php echo htmlspecialchars((string)($bootstrapProfile['request_method'] ?? '—')); ?></dd>
                                                    <dt class="col-5 text-secondary">SAPI</dt><dd class="col-7 mb-2"><?php echo htmlspecialchars((string)($bootstrapProfile['sapi'] ?? '—')); ?></dd>
                                                    <dt class="col-5 text-secondary">Pfad</dt><dd class="col-7 mb-0 text-break"><code><?php echo htmlspecialchars((string)($bootstrapProfile['request_uri'] ?? '—')); ?></code></dd>
                                                </dl>
                                            </div>
                                        </div>
                                        <div class="col-lg-8">
                                            <div class="border rounded-3 h-100 p-3">
                                                <div class="fw-semibold mb-2">Teuerste Bootstrap-Phasen</div>
                                                <div class="text-secondary small mb-3">Zeigt die größten Segmente zwischen den Bootstrap-Checkpoints und macht Cold-Path-Kosten transparent.</div>
                                                <div class="table-responsive">
                                                    <table class="table table-sm table-vcenter mb-0">
                                                        <thead>
                                                            <tr>
                                                                <th>Von</th>
                                                                <th>Nach</th>
                                                                <th>Dauer</th>
                                                                <th>RAM</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php if (empty($bootstrapProfile['top_phases'])): ?>
                                                                <tr><td colspan="4" class="text-center text-secondary py-3">Noch keine Bootstrap-Phasen gemessen.</td></tr>
                                                            <?php else: ?>
                                                                <?php foreach (($bootstrapProfile['top_phases'] ?? []) as $phase): ?>
                                                                    <tr>
                                                                        <td><code><?php echo htmlspecialchars((string)($phase['from'] ?? '')); ?></code></td>
                                                                        <td><code><?php echo htmlspecialchars((string)($phase['to'] ?? '')); ?></code></td>
                                                                        <td><?php echo htmlspecialchars((string)($phase['delta_ms'] ?? '0')); ?> ms</td>
                                                                        <td><?php echo htmlspecialchars((string)($phase['memory_mb'] ?? '0')); ?> MB</td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="table-responsive mb-4">
                                        <table class="table table-vcenter card-table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Von</th>
                                                    <th>Nach</th>
                                                    <th>Dauer</th>
                                                    <th>Gesamtzeit</th>
                                                    <th>RAM</th>
                                                    <th>Kontext</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($bootstrapProfile['phases'])): ?>
                                                    <tr><td colspan="6" class="text-center text-secondary py-4">Keine Bootstrap-Phasen vorhanden.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach (($bootstrapProfile['phases'] ?? []) as $phase): ?>
                                                        <tr>
                                                            <td><code><?php echo htmlspecialchars((string)($phase['from'] ?? '')); ?></code></td>
                                                            <td><code><?php echo htmlspecialchars((string)($phase['to'] ?? '')); ?></code></td>
                                                            <td><?php echo htmlspecialchars((string)($phase['delta_ms'] ?? '0')); ?> ms</td>
                                                            <td><?php echo htmlspecialchars((string)($phase['time_ms'] ?? '0')); ?> ms</td>
                                                            <td><?php echo htmlspecialchars((string)($phase['memory_mb'] ?? '0')); ?> MB</td>
                                                            <td>
                                                                <?php if (!empty($phase['context']) && is_array($phase['context'])): ?>
                                                                    <code><?php echo htmlspecialchars(json_encode($phase['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', ENT_QUOTES); ?></code>
                                                                <?php else: ?>
                                                                    <span class="text-secondary">—</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php if (empty($runtime['enabled'])): ?>
                                        <?php
                                        $alertData = [
                                            'type' => 'info',
                                            'message' => 'Debug ist aktuell aus. Das Bootstrap-Profil läuft leichtgewichtig weiter, detaillierte SQL-Queries und Debug-Logs erscheinen jedoch nur mit aktiviertem CMS_DEBUG.',
                                        ];
                                        $alertDismissible = false;
                                        $alertMarginClass = 'mb-0';
                                        require __DIR__ . '/../partials/flash-alert.php';
                                        ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($runtime['enabled'])): ?>
                            <div class="row g-3">
                                <div class="col-lg-6">
                                    <div class="table-responsive">
                                        <table class="table table-vcenter card-table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Messpunkt</th>
                                                    <th>Zeit</th>
                                                    <th>RAM</th>
                                                    <th>Kontext</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (($runtime['checkpoints'] ?? []) as $checkpoint): ?>
                                                    <tr>
                                                        <td><code><?php echo htmlspecialchars((string)($checkpoint['label'] ?? '')); ?></code></td>
                                                        <td><?php echo htmlspecialchars((string)($checkpoint['time_ms'] ?? '0')); ?> ms</td>
                                                        <td><?php echo htmlspecialchars((string)($checkpoint['memory_mb'] ?? '0')); ?> MB</td>
                                                        <td>
                                                            <?php if (!empty($checkpoint['context']) && is_array($checkpoint['context'])): ?>
                                                                <code><?php echo htmlspecialchars(json_encode($checkpoint['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', ENT_QUOTES); ?></code>
                                                            <?php else: ?>
                                                                <span class="text-secondary">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="mb-3 text-secondary small">
                                        Langsame Queries ab <?php echo htmlspecialchars((string)($runtime['query']['slow_threshold_ms'] ?? 0)); ?> ms: <?php echo htmlspecialchars((string)($runtime['query']['slow_count'] ?? 0)); ?>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-vcenter card-table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Dauer</th>
                                                    <th>SQL</th>
                                                    <th>Parameter</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (($runtime['query']['queries'] ?? []) as $queryInfo): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars((string)($queryInfo['execution_time_ms'] ?? '0')); ?> ms</td>
                                                        <td><code><?php echo htmlspecialchars((string)($queryInfo['sql'] ?? '')); ?></code></td>
                                                        <td>
                                                            <?php if (array_key_exists('params', $queryInfo) && $queryInfo['params'] !== null): ?>
                                                                <code><?php echo htmlspecialchars(json_encode($queryInfo['params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', ENT_QUOTES); ?></code>
                                                            <?php else: ?>
                                                                <span class="text-secondary">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3 class="card-title">Datenbank-Tabellen</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table table-striped">
                            <thead>
                                <tr>
                                    <th>Tabelle</th>
                                    <th>Existiert</th>
                                    <th>Zeilen</th>
                                    <th>Größe</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($tables)): ?>
                                    <tr><td colspan="5" class="text-center text-secondary py-4">Keine Tabelleninformationen verfügbar.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($tables as $name => $info): ?>
                                        <?php if (!is_array($info)) continue; ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars(is_string($name) ? $name : (string)($info['name'] ?? '')); ?></code></td>
                                            <td><span class="badge bg-<?php echo !empty($info['exists']) ? 'success' : 'danger'; ?>-lt"><?php echo !empty($info['exists']) ? 'Ja' : 'Nein'; ?></span></td>
                                            <td><?php echo (!empty($info['rows_approximate']) && !empty($info['exists']) ? '<span class="text-secondary" title="Schätzwert aus information_schema">≈ </span>' : '') . htmlspecialchars((string)($info['rows'] ?? '-')); ?></td>
                                            <td><?php echo htmlspecialchars((string)($info['size'] ?? '-')); ?></td>
                                            <td>
                                                <?php $status = (string)($info['status'] ?? 'unknown'); ?>
                                                <?php if (array_key_exists('checked', $info) && empty($info['checked']) && strtolower($status) === 'ok'): ?>
                                                    <span class="badge bg-secondary-lt" title="CHECK TABLE läuft nur über „Tabellen reparieren“">Nicht geprüft</span>
                                                <?php else: ?>
                                                    <span class="badge bg-<?php echo strtolower($status) === 'ok' ? 'success' : 'warning'; ?>-lt"><?php echo htmlspecialchars($status); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
