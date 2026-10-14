<?php
declare(strict_types=1);

if (!defined('ABSPATH') || !defined('CMS_ADMIN_SYSTEM_VIEW')) {
    exit;
}

$logs = is_array($data['logs'] ?? null) ? $data['logs'] : [];
$files = is_array($logs['files'] ?? null) ? $logs['files'] : [];
$selectedFile = (string) ($logs['selected_file'] ?? '');
$selectedEntries = is_array($logs['selected_entries'] ?? null) ? $logs['selected_entries'] : [];
$updateEntries = is_array($logs['update_history_entries'] ?? null) ? $logs['update_history_entries'] : [];

$viewerLines = [];
foreach ($selectedEntries as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $viewerLines[] = '[' . (string) ($entry['timestamp'] ?? '') . ']'
        . ' [' . (string) ($entry['level'] ?? '') . ']'
        . ' [' . (string) ($entry['channel'] ?? '') . '] '
        . (string) ($entry['message'] ?? '');
}
$viewerContent = $viewerLines === [] ? 'Keine lesbaren Einträge für die gewählte Datei.' : implode(PHP_EOL, $viewerLines);
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Logs &amp; Audit</div>
                <h2 class="page-title">Kanal-Logs &amp; Update-Historie.</h2>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">
        <?php $alertData = $alert ?? []; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>

        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title mb-0">Kanal-Logs</h3></div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table table-striped">
                    <thead><tr><th>Kanalname</th><th>Datei</th><th>Größe</th><th>Zuletzt geändert</th></tr></thead>
                    <tbody>
                    <?php if ($files === []): ?>
                        <tr><td colspan="4" class="text-center text-secondary py-4">Keine Kanal-Logdateien gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($files as $file): ?>
                            <?php $filename = (string) ($file['filename'] ?? ''); ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars((string) ($file['channel'] ?? 'unbekannt'), ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td>
                                    <a href="<?php echo htmlspecialchars('/admin/logs/channels?log_file=' . rawurlencode($filename), ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $selectedFile === $filename ? 'fw-semibold text-primary' : ''; ?>">
                                        <?php echo htmlspecialchars($filename, ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars((string) ($file['formatted_size'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="small text-secondary"><?php echo htmlspecialchars((string) ($file['modified_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body border-top">
                <label for="logChannelViewerContent" class="form-label">Log-Inhalt (read-only)</label>
                <textarea id="logChannelViewerContent" class="form-control font-monospace" rows="12" readonly><?php echo htmlspecialchars($viewerContent, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title mb-0">Update-Historie</h3></div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table table-striped">
                    <thead><tr><th>Zeit</th><th>Typ</th><th>Komponente</th><th>Version</th><th>Resultat</th></tr></thead>
                    <tbody>
                    <?php if ($updateEntries === []): ?>
                        <tr><td colspan="5" class="text-center text-secondary py-4">Noch keine Update-Historie gespeichert.</td></tr>
                    <?php else: ?>
                        <?php foreach ($updateEntries as $entry): ?>
                            <?php
                            $type = (string) ($entry['type'] ?? 'update');
                            $typeLabel = match ($type) {
                                'core' => 'Core',
                                'plugin' => 'Plugin',
                                'theme' => 'Theme',
                                default => ucfirst($type),
                            };
                            ?>
                            <tr>
                                <td class="text-nowrap"><?php echo htmlspecialchars((string) ($entry['timestamp'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="badge bg-azure-lt"><?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($entry['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><code><?php echo htmlspecialchars((string) ($entry['version'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td><span class="badge bg-secondary-lt text-secondary">Abgeschlossen</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
