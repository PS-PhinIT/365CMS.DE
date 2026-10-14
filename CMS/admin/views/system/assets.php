<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SYSTEM_VIEW')) {
    exit;
}

$directories = is_array($data['directories'] ?? null) ? $data['directories'] : [];
$permissions = is_array($data['permissions'] ?? null) ? $data['permissions'] : [];
$vendorRegistry = $data['vendor_registry'] ?? [];
$registrySummary = is_array($vendorRegistry['summary'] ?? null) ? $vendorRegistry['summary'] : [];
$autoloadDiagnostics = is_array($vendorRegistry['autoload'] ?? null) ? $vendorRegistry['autoload'] : [];
$managedPackages = is_array($vendorRegistry['packages'] ?? null) ? $vendorRegistry['packages'] : [];
$bundledLibraries = is_array($vendorRegistry['bundles'] ?? null) ? $vendorRegistry['bundles'] : [];
$platformDiagnostics = is_array($vendorRegistry['platform'] ?? null) ? $vendorRegistry['platform'] : [];
$moduleAssets = is_array($vendorRegistry['module_assets'] ?? null) ? $vendorRegistry['module_assets'] : [];
$assetDirectory = is_array($directories['assets'] ?? null) ? $directories['assets'] : [];
$assetPermissions = array_values(array_filter($permissions, static function ($permission): bool {
    if (!is_array($permission)) {
        return false;
    }

    $path = (string) ($permission['path'] ?? '');
    $fullPath = str_replace('\\', '/', (string) ($permission['full_path'] ?? ''));

    return str_starts_with($path, 'assets') || str_contains($fullPath, '/assets/');
}));
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Diagnose</div>
                <h2 class="page-title">Assets</h2>
                <div class="text-secondary mt-1">Autoloader, Runtime-Bundles, Plattformprüfung und Asset-Pfade getrennt von der Datenbankdiagnose.</div>
            </div>
            <div class="col-auto d-flex gap-2 flex-wrap">
                <a href="<?php echo htmlspecialchars('/admin/diagnose', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Datenbank öffnen</a>
                <a href="<?php echo htmlspecialchars('/admin/logs', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Logs &amp; Audit</a>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <?php $alertData = $alert ?? []; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>

        <?php require __DIR__ . '/subnav.php'; ?>

        <?php if (!empty($vendorRegistry['error'])): ?>
            <?php
            $alertData = [
                'type' => 'warning',
                'message' => (string) $vendorRegistry['error'],
            ];
            $alertDismissible = false;
            $alertMarginClass = 'mb-4';
            require __DIR__ . '/../partials/flash-alert.php';
            ?>
        <?php endif; ?>

        <div class="row row-deck row-cards mb-4">
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Assets-Verzeichnis</div><div class="h1 mb-0"><?php echo htmlspecialchars((string) ($assetDirectory['formatted'] ?? '0 B')); ?></div><div class="text-secondary small mt-1"><code>/assets</code> gesamt</div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Assets-Autoloader</div><div class="h1 mb-0 <?php echo !empty($autoloadDiagnostics['loaded']) ? 'text-success' : 'text-danger'; ?>"><?php echo !empty($autoloadDiagnostics['loaded']) ? 'Aktiv' : 'Inaktiv'; ?></div><div class="text-secondary small mt-1 text-break"><?php echo htmlspecialchars((string) ($autoloadDiagnostics['active_path'] ?? 'Kein aktiver Pfad')); ?></div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Registry-Pakete</div><div class="h1 mb-0"><?php echo htmlspecialchars((string) ($registrySummary['managed_loaded'] ?? 0)); ?><span class="fs-5 text-secondary"> / <?php echo htmlspecialchars((string) ($registrySummary['managed_total'] ?? 0)); ?></span></div><div class="text-secondary small mt-1">produktiv geladene Pakete</div></div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Runtime-Bundles</div><div class="h1 mb-0"><?php echo htmlspecialchars((string) ($registrySummary['bundle_ready'] ?? 0)); ?><span class="fs-5 text-secondary"> / <?php echo htmlspecialchars((string) ($registrySummary['bundle_total'] ?? 0)); ?></span></div><div class="text-secondary small mt-1"><?php echo htmlspecialchars((string) ($registrySummary['platform_warning_count'] ?? 0)); ?> Plattform-Hinweise</div></div></div></div>
        </div>

        <div class="row row-cards mb-4">
            <div class="col-12 col-xl-4">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Pfad &amp; Berechtigungen</h3></div>
                    <div class="card-body">
                        <dl class="row mb-4">
                            <dt class="col-sm-4 text-secondary">Assets-Größe</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars((string) ($assetDirectory['formatted'] ?? '0 B')); ?></dd>
                            <dt class="col-sm-4 text-secondary">Pfad</dt>
                            <dd class="col-sm-8"><code><?php echo htmlspecialchars((string) ($assetDirectory['path'] ?? 'assets')); ?></code></dd>
                            <dt class="col-sm-4 text-secondary">Autoloader</dt>
                            <dd class="col-sm-8"><?php echo !empty($autoloadDiagnostics['loaded']) ? 'Aktiv' : 'Inaktiv'; ?></dd>
                        </dl>

                        <div class="table-responsive">
                            <table class="table table-sm table-vcenter mb-0">
                                <thead>
                                    <tr>
                                        <th>Pfad</th>
                                        <th>Lesbar</th>
                                        <th>Schreibbar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($assetPermissions === []): ?>
                                        <tr><td colspan="3" class="text-center text-secondary py-3">Keine spezifischen Asset-Berechtigungen gefunden.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($assetPermissions as $permission): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string) ($permission['path'] ?? $permission['full_path'] ?? '')); ?></td>
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
            </div>

            <div class="col-12 col-xl-8">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Registrierte Produktivpakete</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table table-striped">
                            <thead>
                                <tr>
                                    <th>Paket</th>
                                    <th>Pfad</th>
                                    <th>Verfügbar</th>
                                    <th>Geladen</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($managedPackages === []): ?>
                                    <tr><td colspan="4" class="text-center text-secondary py-4">Keine Registry-Pakete vorhanden.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($managedPackages as $package): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string) ($package['label'] ?? $package['package'] ?? '')); ?></div>
                                                <?php if (!empty($package['source_url'])): ?>
                                                    <div class="small mt-1"><a href="<?php echo htmlspecialchars((string) $package['source_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars((string) ($package['source_label'] ?? 'Quelle')); ?></a></div>
                                                <?php endif; ?>
                                                <?php if (!empty($package['runtime_error'])): ?>
                                                    <div class="text-warning small mt-1"><?php echo htmlspecialchars((string) $package['runtime_error']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><code class="text-break"><?php echo htmlspecialchars((string) ($package['path'] ?? '')); ?></code></td>
                                            <td><span class="badge bg-<?php echo !empty($package['available']) ? 'success' : 'danger'; ?>-lt"><?php echo !empty($package['available']) ? 'Ja' : 'Nein'; ?></span></td>
                                            <td><span class="badge bg-<?php echo !empty($package['loaded']) ? 'success' : 'secondary'; ?>-lt"><?php echo !empty($package['loaded']) ? 'Ja' : 'Nein'; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row row-cards mb-4">
            <div class="col-12 col-xl-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Asset-Libraries</h3></div>
                    <div class="card-body border-bottom pb-2">
                        <input type="search" class="form-control" id="assetLibrarySearch" aria-label="Library suchen" placeholder="Library suchen...">
                        <div class="text-secondary small mt-2" id="assetLibraryCounter"><?php echo count($bundledLibraries); ?> von <?php echo count($bundledLibraries); ?> Libraries</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table table-striped">
                            <thead>
                                <tr>
                                    <th>Library</th>
                                    <th>Pfad / Bestand</th>
                                    <th>Runtime</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($bundledLibraries === []): ?>
                                    <tr><td colspan="3" class="text-center text-secondary py-4">Keine Asset-Libraries erkannt.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($bundledLibraries as $library): ?>
                                        <tr data-library-name="<?php echo htmlspecialchars(strtolower((string) ($library['label'] ?? $library['package'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string) ($library['label'] ?? $library['package'] ?? '')); ?></div>
                                                <div class="text-secondary small"><?php echo htmlspecialchars((string) ($library['notes'] ?? '')); ?></div>
                                                <?php if (!empty($library['source_url'])): ?>
                                                    <div class="small mt-1"><a href="<?php echo htmlspecialchars((string) $library['source_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars((string) ($library['source_label'] ?? 'Quelle')); ?></a></div>
                                                <?php endif; ?>
                                                <?php if (!empty($library['runtime_error'])): ?>
                                                    <div class="text-warning small mt-1"><?php echo htmlspecialchars((string) $library['runtime_error']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php $libraryPaths = is_array($library['paths'] ?? null) ? $library['paths'] : []; ?>
                                                <?php if ($libraryPaths === []): ?>
                                                    <span class="text-secondary">—</span>
                                                <?php else: ?>
                                                    <div class="d-flex flex-column gap-1">
                                                        <?php foreach ($libraryPaths as $libraryPath): ?>
                                                            <code class="text-break"><?php echo htmlspecialchars((string) $libraryPath); ?></code>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge bg-<?php echo htmlspecialchars((string) ($library['runtime_class'] ?? (!empty($library['runtime_ready']) ? 'success' : 'secondary'))); ?>-lt"><?php echo htmlspecialchars((string) ($library['runtime_label'] ?? (!empty($library['runtime_ready']) ? 'auflösbar' : 'nicht aufgelöst'))); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Bundle-Plattformprüfung</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table table-striped">
                            <thead>
                                <tr>
                                    <th>Paket</th>
                                    <th>Manifest</th>
                                    <th>Runtime</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($platformDiagnostics === []): ?>
                                    <tr><td colspan="4" class="text-center text-secondary py-4">Keine Manifestdaten vorhanden.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($platformDiagnostics as $entry): ?>
                                        <?php
                                        $cmsCompatible = $entry['cms_compatible'] ?? null;
                                        $runtimeCompatible = $entry['runtime_compatible'] ?? null;
                                        $statusClass = ($cmsCompatible === false || $runtimeCompatible === false) ? 'warning' : 'success';
                                        $statusLabel = ($cmsCompatible === false || $runtimeCompatible === false) ? 'Prüfen' : 'OK';
                                        if (empty($entry['exists'])) {
                                            $statusClass = 'secondary';
                                            $statusLabel = 'Fehlt';
                                        }
                                        ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars((string) ($entry['package'] ?? '')); ?></code></td>
                                            <td>
                                                <code><?php echo htmlspecialchars((string) ($entry['manifest'] ?? '')); ?></code>
                                                <div class="text-secondary small mt-1">PHP <?php echo htmlspecialchars((string) ($entry['required_php'] ?? '—')); ?> · CMS-Minimum <?php echo htmlspecialchars((string) ($entry['cms_required_php'] ?? '—')); ?></div>
                                                <?php if (!empty($entry['source_url'])): ?>
                                                    <div class="small mt-1"><a href="<?php echo htmlspecialchars((string) $entry['source_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars((string) ($entry['source_label'] ?? 'Quelle')); ?></a></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars((string) ($entry['runtime_php'] ?? '—')); ?></td>
                                            <td><span class="badge bg-<?php echo $statusClass; ?>-lt"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row row-cards mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Modulgebundene Assets</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table table-striped">
                            <thead>
                                <tr>
                                    <th>Asset</th>
                                    <th>Modul</th>
                                    <th>Pfad / Bestand</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($moduleAssets === []): ?>
                                    <tr><td colspan="4" class="text-center text-secondary py-4">Keine modulgebundenen Assets registriert.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($moduleAssets as $asset): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string) ($asset['asset'] ?? '')); ?></div>
                                                <div class="text-secondary small"><?php echo htmlspecialchars((string) ($asset['notes'] ?? '')); ?></div>
                                                <?php if (!empty($asset['source_url'])): ?>
                                                    <div class="small mt-1"><a href="<?php echo htmlspecialchars((string) $asset['source_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars((string) ($asset['source_label'] ?? 'Quelle')); ?></a></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string) ($asset['module_label'] ?? $asset['module_slug'] ?? '')); ?></div>
                                                <code><?php echo htmlspecialchars((string) ($asset['module_slug'] ?? '')); ?></code>
                                            </td>
                                            <td>
                                                <?php $assetPaths = is_array($asset['paths'] ?? null) ? $asset['paths'] : []; ?>
                                                <?php if ($assetPaths === []): ?>
                                                    <span class="text-secondary">—</span>
                                                <?php else: ?>
                                                    <div class="d-flex flex-column gap-1">
                                                        <?php foreach ($assetPaths as $assetPath): ?>
                                                            <code class="text-break"><?php echo htmlspecialchars((string) $assetPath); ?></code>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-wrap gap-2">
                                                    <span class="badge bg-<?php echo !empty($asset['available']) ? 'success' : 'danger'; ?>-lt"><?php echo !empty($asset['available']) ? 'Datei vorhanden' : 'Datei fehlt'; ?></span>
                                                    <span class="badge bg-<?php echo htmlspecialchars((string) ($asset['activation_class'] ?? 'secondary')); ?>-lt"><?php echo htmlspecialchars((string) ($asset['activation_label'] ?? 'Unbekannt')); ?></span>
                                                </div>
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
<script <?= \CMS\Security::instance()->nonceAttr() ?>>
(function () {
    const input = document.getElementById('assetLibrarySearch');
    const counter = document.getElementById('assetLibraryCounter');
    if (!input || !counter) {
        return;
    }
    const rows = Array.from(document.querySelectorAll('tr[data-library-name]'));
    const total = rows.length;

    function applyFilter() {
        const term = (input.value || '').trim().toLowerCase();
        let visible = 0;
        rows.forEach((row) => {
            const name = row.dataset.libraryName || '';
            const matches = term === '' || name.includes(term);
            row.style.display = matches ? '' : 'none';
            if (matches) {
                visible++;
            }
        });
        counter.textContent = visible + ' von ' + total + ' Libraries';
    }

    input.addEventListener('keyup', applyFilter);
    input.addEventListener('input', applyFilter);
})();
</script>