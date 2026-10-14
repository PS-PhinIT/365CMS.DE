<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_SYSTEM_VIEW')) {
    exit;
}

$transport = $data['transport'] ?? [];
$azure = $data['azure'] ?? [];
$graph = $data['graph'] ?? [];
$transportInfo = $data['transport_info'] ?? [];
$mailLogs = $data['mail_logs']['rows'] ?? [];
$mailStats = $data['mail_stats'] ?? [];
$queue = $data['queue'] ?? [];
$queueStats = $data['queue_stats'] ?? [];
$queueConfig = $queue['config'] ?? [];
$queueRecentJobs = $queue['recent_jobs'] ?? [];
$queueLastRun = $queue['last_run'] ?? [];
$currentTab = $currentTab ?? 'transport';
$mailBaseUrl = '/admin/mail-settings';
$mailTabs = [
    'transport' => 'Transport',
    'azure' => 'Azure OAuth2',
    'graph' => 'Microsoft Graph',
    'logs' => 'Logs',
    'queue' => 'Queue',
];
$alertData = is_array($alert ?? null) ? $alert : [];
$mailLogsApiUrl = '/api/v1/admin/mail/logs';
$isCurrentTab = static fn (string $tab): bool => $currentTab === $tab;
$isSelected = static fn (string $value, string $expected): string => $value === $expected ? 'selected' : '';
$isChecked = static fn (bool $condition): string => $condition ? 'checked' : '';
$statusBadge = static fn (bool $condition): string => $condition ? 'success' : 'warning';
$statusLabel = static fn (bool $condition, string $success, string $fallback): string => $condition ? $success : $fallback;
$queueJobStatusBadge = static function (string $status): string {
    return match ($status) {
        'sent' => 'success',
        'processing' => 'primary',
        'failed' => 'danger',
        default => 'warning',
    };
};
$renderStatusBadge = static function (string $badgeClass, string $label): void {
    ?>
    <span class="badge bg-<?php echo htmlspecialchars($badgeClass, ENT_QUOTES); ?>-lt"><?php echo htmlspecialchars($label); ?></span>
    <?php
};
$renderMetricCard = static function (string $label, int $value, string $valueClass = ''): void {
    ?>
    <div class="cms-settings-metric">
        <div class="cms-settings-metric__label"><?php echo htmlspecialchars($label); ?></div>
        <div class="cms-settings-metric__value<?php echo $valueClass !== '' ? ' ' . htmlspecialchars($valueClass, ENT_QUOTES) : ''; ?>"><?php echo $value; ?></div>
    </div>
    <?php
};
$renderMetricCardsRow = static function (array $cards) use ($renderMetricCard): void {
    ?>
    <div class="cms-settings-metrics">
        <?php foreach ($cards as $card): ?>
            <?php $renderMetricCard((string) ($card['label'] ?? ''), (int) ($card['value'] ?? 0), (string) ($card['class'] ?? '')); ?>
        <?php endforeach; ?>
    </div>
    <?php
};
$renderEmptyTableRow = static function (int $colspan, string $message): void {
    ?>
    <tr>
        <td colspan="<?php echo $colspan; ?>" class="text-center text-secondary py-4"><?php echo htmlspecialchars($message); ?></td>
    </tr>
    <?php
};
$renderInfoCard = static function (string $title, callable $contentRenderer): void {
    ?>
    <div class="card h-100">
        <div class="card-header"><h3 class="card-title"><?php echo htmlspecialchars($title); ?></h3></div>
        <div class="card-body">
            <?php $contentRenderer(); ?>
        </div>
    </div>
    <?php
};
$renderCardHeaderTitle = static function (string $title): void {
    ?>
    <div class="card-header"><h3 class="card-title"><?php echo htmlspecialchars($title); ?></h3></div>
    <?php
};
$renderStatusCardHeader = static function (string $title, bool $configured) use ($renderStatusBadge, $statusBadge, $statusLabel): void {
    ?>
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0"><?php echo htmlspecialchars($title); ?></h3>
        <?php $renderStatusBadge($statusBadge($configured), $statusLabel($configured, 'Konfiguriert', 'Unvollständig')); ?>
    </div>
    <?php
};
$renderReadonlyField = static function (string $label, string $value): void {
    ?>
    <div class="mb-3">
        <label class="form-label"><?php echo htmlspecialchars($label); ?></label>
        <input type="text" class="form-control" value="<?php echo htmlspecialchars($value); ?>" readonly>
    </div>
    <?php
};
$renderSecretStatusField = static function (bool $configured, string $checkboxName, string $checkboxLabel) use ($statusLabel): void {
    ?>
    <div class="form-hint">Aktuell gespeichert: <?php echo $statusLabel($configured, 'Ja', 'Nein'); ?></div>
    <label class="form-check mt-2">
        <input class="form-check-input" type="checkbox" name="<?php echo htmlspecialchars($checkboxName, ENT_QUOTES); ?>" value="1">
        <span class="form-check-label"><?php echo htmlspecialchars($checkboxLabel); ?></span>
    </label>
    <?php
};
$renderActionButton = static function (string $action, string $label, string $class = 'btn btn-primary', array $attributes = []): void {
    ?>
    <button type="submit" name="action" value="<?php echo htmlspecialchars($action, ENT_QUOTES); ?>" class="<?php echo htmlspecialchars($class, ENT_QUOTES); ?>"
        <?php foreach ($attributes as $attribute => $value): ?>
            <?php echo ' ' . htmlspecialchars((string) $attribute, ENT_QUOTES) . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"'; ?>
        <?php endforeach; ?>><?php echo htmlspecialchars($label); ?></button>
    <?php
};
$renderFormContext = static function (string $tab) use ($csrfToken): void {
    ?>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $csrfToken); ?>">
    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab, ENT_QUOTES); ?>">
    <?php
};
$logMetricCards = [
    ['label' => 'Versendet', 'value' => (int) ($mailStats['sent'] ?? 0), 'class' => 'text-success'],
    ['label' => 'Fehlgeschlagen', 'value' => (int) ($mailStats['failed'] ?? 0), 'class' => 'text-danger'],
    ['label' => 'Queue offen', 'value' => (int) ($queueStats['pending'] ?? 0), 'class' => ''],
    ['label' => 'Queue fehlgeschlagen', 'value' => (int) ($queueStats['failed'] ?? 0), 'class' => 'text-warning'],
];
$queueMetricCards = [
    ['label' => 'Pending', 'value' => (int) ($queueStats['pending'] ?? 0), 'class' => ''],
    ['label' => 'Processing', 'value' => (int) ($queueStats['processing'] ?? 0), 'class' => 'text-primary'],
    ['label' => 'Versendet', 'value' => (int) ($queueStats['sent'] ?? 0), 'class' => 'text-success'],
    ['label' => 'Final fehlgeschlagen', 'value' => (int) ($queueStats['failed'] ?? 0), 'class' => 'text-danger'],
];
$workerReadonlyFields = [
    ['label' => 'Webhook-/Cron-URL (Header-Token empfohlen)', 'value' => (string) ($queueConfig['cron_url'] ?? '')],
    ['label' => 'HTTP-Header', 'value' => (string) ($queueConfig['cron_header_name'] ?? 'X-CMS-Cron-Token') . ': ' . (string) ($queueConfig['cron_header_value'] ?? '')],
    ['label' => 'Legacy-URL mit Token', 'value' => (string) ($queueConfig['cron_url_with_token'] ?? '')],
    ['label' => 'CLI-Beispiel', 'value' => (string) ($queueConfig['cli_command'] ?? '')],
];
$queueLastRunText = !empty($queueLastRun['executed_at'])
    ? sprintf(
        '%s · Worker: %s · verarbeitet: %d · versendet: %d · retried: %d · final fehlgeschlagen: %d',
        (string) $queueLastRun['executed_at'],
        (string) ($queueLastRun['worker'] ?? '—'),
        (int) ($queueLastRun['processed'] ?? 0),
        (int) ($queueLastRun['sent'] ?? 0),
        (int) ($queueLastRun['retried'] ?? 0),
        (int) ($queueLastRun['failed_final'] ?? 0)
    )
    : 'Noch kein Worker-Lauf protokolliert.';
?>
<div class="container-xl cms-settings-page" data-mail-api-token="<?php echo htmlspecialchars((string) ($apiCsrfToken ?? '')); ?>">
    <div class="page-header d-print-none mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <div class="page-pretitle">System</div>
                <h2 class="page-title">Mail &amp; Azure OAuth2</h2>
                <div class="text-secondary mt-1">Zentraler Mailversand, Microsoft 365 SMTP-XOAUTH2, Graph-Zugang und Versandprotokolle.</div>
            </div>
            <div class="col-auto">
                <span class="text-secondary small"><?php echo htmlspecialchars((string) ($transportInfo['transport_label'] ?? 'Mailversand')); ?></span>
            </div>
        </div>
    </div>

    <?php
    $alertMarginClass = 'mb-4';
    include __DIR__ . '/../partials/flash-alert.php';
    ?>

    <div class="cms-settings-toolbar">
        <ul class="nav nav-tabs">
            <?php foreach ($mailTabs as $tab => $label): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $isCurrentTab($tab) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($mailBaseUrl . '?tab=' . rawurlencode($tab)); ?>">
                        <?php echo htmlspecialchars($label); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="cms-settings-actions">
            <span class="text-secondary small me-auto">Konfiguration, Protokolle und Queue laufen in einem zentralen Mail-Backend.</span>
            <a href="/admin/settings" class="btn btn-outline-secondary">Allgemeine Einstellungen</a>
        </div>
    </div>

    <?php if ($isCurrentTab('transport')): ?>
        <h3 class="cms-settings-section-heading">Transport und Laufzeit</h3>
        <div class="row row-cards">
            <div class="col-12">
                <form method="post" class="card">
                    <?php $renderFormContext('transport'); ?>
                    <?php $renderCardHeaderTitle('Transport-Konfiguration'); ?>
                    <div class="card-body border-bottom">
                        <div class="cms-settings-actions mb-0">
                            <?php $renderActionButton('save_transport', 'Transport speichern'); ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="fld-driver" class="form-label">Versandmodus</label>
                                <select id="fld-driver" name="driver" class="form-select">
                                    <option value="mail" <?php echo $isSelected((string) ($transport['driver'] ?? 'mail'), 'mail'); ?>>PHP mail() Fallback</option>
                                    <option value="smtp" <?php echo $isSelected((string) ($transport['driver'] ?? 'mail'), 'smtp'); ?>>SMTP via Symfony Mailer</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="fld-auth_mode" class="form-label">Authentifizierung</label>
                                <select id="fld-auth_mode" name="auth_mode" class="form-select">
                                    <option value="credentials" <?php echo $isSelected((string) ($transport['auth_mode'] ?? 'credentials'), 'credentials'); ?>>Benutzername + Passwort</option>
                                    <option value="oauth2" <?php echo $isSelected((string) ($transport['auth_mode'] ?? 'credentials'), 'oauth2'); ?>>Azure OAuth2 / XOAUTH2</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="fld-test_recipient" class="form-label">Test-E-Mail an</label>
                                <input id="fld-test_recipient" type="email" name="test_recipient" class="form-control" value="<?php echo htmlspecialchars((string) ($transport['test_recipient'] ?? '')); ?>" placeholder="admin@example.com">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-smtp_host" class="form-label">SMTP-Host</label>
                                <input id="fld-smtp_host" type="text" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars((string) ($transport['smtp_host'] ?? '')); ?>" placeholder="smtp.office365.com">
                            </div>
                            <div class="col-md-3">
                                <label for="fld-smtp_port" class="form-label">Port</label>
                                <input id="fld-smtp_port" type="number" name="smtp_port" min="1" max="65535" class="form-control" value="<?php echo (int) ($transport['smtp_port'] ?? 587); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="fld-smtp_encryption" class="form-label">Verschlüsselung</label>
                                <select id="fld-smtp_encryption" name="smtp_encryption" class="form-select">
                                    <option value="tls" <?php echo $isSelected((string) ($transport['smtp_encryption'] ?? 'tls'), 'tls'); ?>>TLS / STARTTLS</option>
                                    <option value="ssl" <?php echo $isSelected((string) ($transport['smtp_encryption'] ?? ''), 'ssl'); ?>>SSL</option>
                                    <option value="" <?php echo $isSelected((string) ($transport['smtp_encryption'] ?? ''), ''); ?>>Keine</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="fld-smtp_username" class="form-label">SMTP-Benutzername</label>
                                <input id="fld-smtp_username" type="text" name="smtp_username" class="form-control" value="<?php echo htmlspecialchars((string) ($transport['smtp_username'] ?? '')); ?>" placeholder="mailbox@tenant.tld">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-smtp_password" class="form-label">SMTP-Passwort</label>
                                <input id="fld-smtp_password" type="password" name="smtp_password" class="form-control" value="" placeholder="Leer lassen = vorhandenes Secret behalten">
                                <?php $renderSecretStatusField(!empty($transport['secret_configured']), 'clear_smtp_password', 'Gespeichertes Passwort löschen'); ?>
                            </div>
                            <div class="col-md-6">
                                <label for="fld-from_email" class="form-label">Absender-E-Mail</label>
                                <input id="fld-from_email" type="email" name="from_email" class="form-control" value="<?php echo htmlspecialchars((string) ($transport['from_email'] ?? '')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-from_name" class="form-label">Absender-Name</label>
                                <input id="fld-from_name" type="text" name="from_name" class="form-control" value="<?php echo htmlspecialchars((string) ($transport['from_name'] ?? '')); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="card-footer d-flex gap-2 justify-content-between flex-wrap">
                        <?php $renderActionButton('save_transport', 'Transport speichern'); ?>
                        <?php $renderActionButton('send_test_email', 'Test-E-Mail senden', 'btn btn-outline-primary', ['formaction' => $mailBaseUrl . '?tab=transport']); ?>
                    </div>
                </form>
            </div>
            <div class="col-12">
                <div class="card h-100">
                    <?php $renderCardHeaderTitle('Aktive Laufzeit'); ?>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-5">Transport</dt><dd class="col-7"><?php echo htmlspecialchars((string) ($transportInfo['transport_label'] ?? '—')); ?></dd>
                            <dt class="col-5">Modus</dt><dd class="col-7"><?php echo htmlspecialchars((string) ($transportInfo['auth_mode_label'] ?? '—')); ?></dd>
                            <dt class="col-5">Host</dt><dd class="col-7"><?php echo htmlspecialchars((string) ($transportInfo['host'] ?? '—')); ?></dd>
                            <dt class="col-5">Port</dt><dd class="col-7"><?php echo htmlspecialchars((string) ($transportInfo['port'] ?? '—')); ?></dd>
                            <dt class="col-5">Username</dt><dd class="col-7 text-break"><?php echo htmlspecialchars((string) ($transportInfo['username'] ?? '—')); ?></dd>
                            <dt class="col-5">Absender</dt><dd class="col-7 text-break"><?php echo htmlspecialchars((string) ($transportInfo['from_email'] ?? '—')); ?></dd>
                        </dl>
                        <hr>
                        <div class="small text-secondary">
                            Für Microsoft 365 sollte in der Regel <code>smtp.office365.com</code>, Port <code>587</code> und <code>TLS</code> verwendet werden.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($isCurrentTab('azure')): ?>
        <h3 class="cms-settings-section-heading">Azure OAuth2</h3>
        <div class="row row-cards">
            <div class="col-12">
                <form method="post" class="card">
                    <?php $renderFormContext('azure'); ?>
                    <?php $renderStatusCardHeader('Azure SMTP OAuth2', !empty($azure['configured'])); ?>
                    <div class="card-body border-bottom">
                        <div class="cms-settings-actions mb-0">
                            <?php $renderActionButton('save_azure', 'Azure speichern'); ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="fld-azure_tenant_id" class="form-label">Tenant-ID</label>
                                <input id="fld-azure_tenant_id" type="text" name="azure_tenant_id" class="form-control" value="<?php echo htmlspecialchars((string) ($azure['tenant_id'] ?? '')); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-azure_client_id" class="form-label">Client-ID</label>
                                <input id="fld-azure_client_id" type="text" name="azure_client_id" class="form-control" value="<?php echo htmlspecialchars((string) ($azure['client_id'] ?? '')); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-azure_client_secret" class="form-label">Client-Secret</label>
                                <input id="fld-azure_client_secret" type="password" name="azure_client_secret" class="form-control" value="" placeholder="Leer lassen = vorhandenes Secret behalten">
                                <?php $renderSecretStatusField(!empty($azure['client_secret_configured']), 'clear_azure_client_secret', 'Gespeichertes Client-Secret löschen'); ?>
                            </div>
                            <div class="col-md-6">
                                <label for="fld-azure_mailbox" class="form-label">Mailbox / SMTP-User</label>
                                <input id="fld-azure_mailbox" type="email" name="azure_mailbox" class="form-control" value="<?php echo htmlspecialchars((string) ($azure['mailbox'] ?? '')); ?>" placeholder="noreply@example.com">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-azure_scope" class="form-label">Scope</label>
                                <input id="fld-azure_scope" type="text" name="azure_scope" class="form-control" value="<?php echo htmlspecialchars((string) ($azure['scope'] ?? 'https://outlook.office365.com/.default')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-azure_token_endpoint" class="form-label">Token-Endpoint (optional)</label>
                                <input id="fld-azure_token_endpoint" type="url" name="azure_token_endpoint" class="form-control" value="<?php echo htmlspecialchars((string) ($azure['token_endpoint'] ?? '')); ?>" placeholder="https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token">
                            </div>
                        </div>
                    </div>
                    <div class="card-footer d-flex gap-2 flex-wrap">
                        <?php $renderActionButton('save_azure', 'Azure speichern'); ?>
                        <?php $renderActionButton('clear_azure_cache', 'Token-Cache leeren', 'btn btn-outline-secondary'); ?>
                    </div>
                </form>
            </div>
            <div class="col-12">
                <?php $renderInfoCard('Hinweise', static function (): void { ?>
                    <ul class="text-secondary small ps-3 mb-0">
                        <li class="mb-2">SMTP-Auth-Modus im Transport auf <strong>Azure OAuth2 / XOAUTH2</strong> stellen.</li>
                        <li class="mb-2">SMTP-Scope für App-only ist in der Regel <code>https://outlook.office365.com/.default</code>.</li>
                        <li class="mb-2">Exchange Online benötigt Admin-Consent und in vielen Setups einen Exchange-Service-Principal.</li>
                        <li>Die Access-Tokens werden verschlüsselt in der bestehenden <code>cms_settings</code>-Tabelle zwischengespeichert.</li>
                    </ul>
                <?php }); ?>
            </div>
        </div>
    <?php elseif ($isCurrentTab('graph')): ?>
        <h3 class="cms-settings-section-heading">Microsoft Graph</h3>
        <div class="row row-cards">
            <div class="col-12">
                <form method="post" class="card">
                    <?php $renderFormContext('graph'); ?>
                    <?php $renderStatusCardHeader('Microsoft Graph', !empty($graph['configured'])); ?>
                    <div class="card-body border-bottom">
                        <div class="cms-settings-actions mb-0">
                            <?php $renderActionButton('save_graph', 'Graph speichern'); ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="fld-graph_tenant_id" class="form-label">Tenant-ID</label>
                                <input id="fld-graph_tenant_id" type="text" name="graph_tenant_id" class="form-control" value="<?php echo htmlspecialchars((string) ($graph['tenant_id'] ?? '')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-graph_client_id" class="form-label">Client-ID</label>
                                <input id="fld-graph_client_id" type="text" name="graph_client_id" class="form-control" value="<?php echo htmlspecialchars((string) ($graph['client_id'] ?? '')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-graph_client_secret" class="form-label">Client-Secret</label>
                                <input id="fld-graph_client_secret" type="password" name="graph_client_secret" class="form-control" value="" placeholder="Leer lassen = vorhandenes Secret behalten">
                                <?php $renderSecretStatusField(!empty($graph['client_secret_configured']), 'clear_graph_client_secret', 'Gespeichertes Client-Secret löschen'); ?>
                            </div>
                            <div class="col-md-6">
                                <label for="fld-graph_scope" class="form-label">Scope</label>
                                <input id="fld-graph_scope" type="text" name="graph_scope" class="form-control" value="<?php echo htmlspecialchars((string) ($graph['scope'] ?? 'https://graph.microsoft.com/.default')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-graph_base_url" class="form-label">Graph Base URL</label>
                                <input id="fld-graph_base_url" type="url" name="graph_base_url" class="form-control" value="<?php echo htmlspecialchars((string) ($graph['base_url'] ?? 'https://graph.microsoft.com/v1.0')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-graph_token_endpoint" class="form-label">Token-Endpoint (optional)</label>
                                <input id="fld-graph_token_endpoint" type="url" name="graph_token_endpoint" class="form-control" value="<?php echo htmlspecialchars((string) ($graph['token_endpoint'] ?? '')); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="card-footer d-flex gap-2 flex-wrap">
                        <?php $renderActionButton('save_graph', 'Graph speichern'); ?>
                        <?php $renderActionButton('test_graph_connection', 'Graph testen', 'btn btn-outline-primary'); ?>
                        <?php $renderActionButton('clear_graph_cache', 'Token-Cache leeren', 'btn btn-outline-secondary'); ?>
                    </div>
                </form>
            </div>
            <div class="col-12">
                <?php $renderInfoCard('Lokal integrierte Basis', static function (): void { ?>
                    <p class="text-secondary small mb-3">Die Graph-Anbindung läuft bewusst mit schlankem cURL-Client, damit die Deployment-Struktur von 365CMS ohne zusätzliche Composer-Abhängigkeiten stabil bleibt.</p>
                    <div class="small text-secondary">Empfohlenes Standard-Scope: <code>https://graph.microsoft.com/.default</code></div>
                <?php }); ?>
            </div>
        </div>
    <?php elseif ($isCurrentTab('logs')): ?>
        <h3 class="cms-settings-section-heading">Mail-Logs und Kennzahlen</h3>
        <?php $renderMetricCardsRow($logMetricCards); ?>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Mail-Log</h3>
                <form method="post" class="m-0">
                    <?php $renderFormContext('logs'); ?>
                    <?php $renderActionButton('clear_logs', 'Logs leeren', 'btn btn-outline-danger btn-sm'); ?>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table table-striped">
                    <thead>
                        <tr>
                            <th>Zeit</th>
                            <th>Status</th>
                            <th>Empfänger</th>
                            <th>Betreff</th>
                            <th>Provider</th>
                            <th>Quelle</th>
                            <th>Fehler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($mailLogs)): ?>
                            <?php $renderEmptyTableRow(7, 'Noch keine Mail-Logs vorhanden.'); ?>
                        <?php else: ?>
                            <?php foreach ($mailLogs as $row): ?>
                                <tr>
                                    <td class="text-nowrap"><?php echo htmlspecialchars((string) ($row->created_at ?? '')); ?></td>
                                    <td><?php $renderStatusBadge($statusBadge(($row->status ?? '') === 'sent'), (string) ($row->status ?? '')); ?></td>
                                    <td class="text-break"><?php echo htmlspecialchars((string) ($row->recipient ?? '')); ?></td>
                                    <td class="text-break"><?php echo htmlspecialchars((string) ($row->subject ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($row->provider ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($row->source ?? '')); ?></td>
                                    <td class="text-break text-secondary small"><?php echo htmlspecialchars((string) ($row->error_message ?? '—')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer text-secondary small">
                API: <code><?php echo htmlspecialchars($mailLogsApiUrl); ?></code>
            </div>
        </div>
    <?php else: ?>
        <h3 class="cms-settings-section-heading">Queue-Betrieb</h3>
        <?php $renderMetricCardsRow($queueMetricCards); ?>

        <div class="row row-cards mb-4">
            <div class="col-12">
                <form method="post" class="card h-100">
                    <?php $renderFormContext('queue'); ?>
                    <?php $renderCardHeaderTitle('Queue-Konfiguration'); ?>
                    <div class="card-body border-bottom">
                        <div class="cms-settings-actions mb-0">
                            <?php $renderActionButton('save_queue', 'Queue speichern'); ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="queue_enabled" value="1" <?php echo $isChecked(!empty($queueConfig['enabled'])); ?>>
                                    <span class="form-check-label">Asynchronen Mailversand per Queue aktivieren</span>
                                </label>
                            </div>
                            <div class="col-md-6">
                                <label for="fld-queue_batch_size" class="form-label">Batch-Größe pro Lauf</label>
                                <input id="fld-queue_batch_size" type="number" class="form-control" name="queue_batch_size" min="1" max="100" value="<?php echo (int) ($queueConfig['batch_size'] ?? 10); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-queue_rate_limit_per_minute" class="form-label">Versandlimit pro Minute</label>
                                <input id="fld-queue_rate_limit_per_minute" type="number" class="form-control" name="queue_rate_limit_per_minute" min="1" max="600" value="<?php echo (int) ($queueConfig['rate_limit_per_minute'] ?? 8); ?>">
                                <div class="form-hint">Standard 8/min schützt vor SMTP-Antworten wie „Sender rate overlimit“.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="fld-queue_max_attempts" class="form-label">Maximale Versuche pro Job</label>
                                <input id="fld-queue_max_attempts" type="number" class="form-control" name="queue_max_attempts" min="1" max="20" value="<?php echo (int) ($queueConfig['max_attempts'] ?? 5); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-queue_retry_delay_seconds" class="form-label">Retry-Delay (Sekunden)</label>
                                <input id="fld-queue_retry_delay_seconds" type="number" class="form-control" name="queue_retry_delay_seconds" min="60" max="86400" value="<?php echo (int) ($queueConfig['retry_delay_seconds'] ?? 300); ?>">
                                <div class="form-hint">Für Netzwerk- und OAuth2-Transientfehler.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="fld-queue_throttle_delay_seconds" class="form-label">Throttle-Delay (Sekunden)</label>
                                <input id="fld-queue_throttle_delay_seconds" type="number" class="form-control" name="queue_throttle_delay_seconds" min="60" max="86400" value="<?php echo (int) ($queueConfig['throttle_delay_seconds'] ?? 900); ?>">
                                <div class="form-hint">Für 429/4.7.x/Rate-Limit-Situationen.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="fld-queue_lock_timeout_seconds" class="form-label">Lock-Timeout (Sekunden)</label>
                                <input id="fld-queue_lock_timeout_seconds" type="number" class="form-control" name="queue_lock_timeout_seconds" min="60" max="86400" value="<?php echo (int) ($queueConfig['lock_timeout_seconds'] ?? 900); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Cron-Token</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars((string) ($queueConfig['cron_token'] ?? '')); ?>" readonly>
                                <label class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="regenerate_queue_cron_token" value="1">
                                    <span class="form-check-label">Cron-Token beim Speichern neu erzeugen</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-between gap-2 flex-wrap">
                        <?php $renderActionButton('save_queue', 'Queue speichern'); ?>
                        <span class="text-secondary small align-self-center">Konfiguration gilt für Cron und manuellen Worker-Lauf.</span>
                    </div>
                </form>
            </div>
            <div class="col-12">
                <div class="card h-100">
                    <?php $renderCardHeaderTitle('Worker & Cron'); ?>
                    <div class="card-body">
                        <?php foreach ($workerReadonlyFields as $field): ?>
                            <?php $renderReadonlyField($field['label'], $field['value']); ?>
                        <?php endforeach; ?>
                        <div class="mb-3 small text-secondary">
                            Der Worker verarbeitet <code>cms_cron_mail_queue</code> über die zentrale Datei <code>cron.php</code> im CMS-Webroot. Wenn der Cron-Dienst HTTP-Header unterstützt, sollte der Token über <code>X-CMS-Cron-Token</code> gesendet werden; die Token-URL bleibt nur als kompatibler Fallback für einfache Shared-Hosting-Cronjobs erhalten.
                        </div>
                        <hr>
                        <form method="post" class="row g-3 align-items-end">
                            <?php $renderFormContext('queue'); ?>
                            <div class="col-md-6">
                                <label for="fld-queue_test_recipient" class="form-label">Queue-Testempfänger</label>
                                <input id="fld-queue_test_recipient" type="email" name="queue_test_recipient" class="form-control" value="<?php echo htmlspecialchars((string) ($transport['test_recipient'] ?? '')); ?>" placeholder="admin@example.com">
                            </div>
                            <div class="col-md-3">
                                <label for="fld-queue_run_limit" class="form-label">Run-Limit</label>
                                <input id="fld-queue_run_limit" type="number" name="queue_run_limit" class="form-control" min="1" max="100" value="<?php echo (int) ($queueConfig['batch_size'] ?? 10); ?>">
                            </div>
                            <div class="col-md-3 d-grid">
                                <?php $renderActionButton('run_queue_now', 'Worker jetzt ausführen'); ?>
                            </div>
                            <div class="col-md-6 d-grid">
                                <?php $renderActionButton('enqueue_queue_test', 'Test-E-Mail in Queue legen', 'btn btn-outline-primary'); ?>
                            </div>
                            <div class="col-md-6 d-grid">
                                <?php $renderActionButton('release_queue_stale', 'Verwaiste Processing-Jobs freigeben', 'btn btn-outline-secondary'); ?>
                            </div>
                        </form>

                        <hr>
                        <div class="small text-secondary">
                            <strong>Letzter Lauf:</strong>
                            <?php echo htmlspecialchars($queueLastRunText); ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="card">
                    <?php $renderCardHeaderTitle('Letzte Queue-Jobs'); ?>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Erstellt</th>
                                    <th>Status</th>
                                    <th>Empfänger</th>
                                    <th>Betreff</th>
                                    <th>Versuche</th>
                                    <th>Quelle</th>
                                    <th>Nächster Lauf</th>
                                    <th>Fehler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($queueRecentJobs)): ?>
                                    <?php $renderEmptyTableRow(9, 'Noch keine Queue-Jobs vorhanden.'); ?>
                                <?php else: ?>
                                    <?php foreach ($queueRecentJobs as $job): ?>
                                        <tr>
                                            <td><?php echo (int) ($job->id ?? 0); ?></td>
                                            <td class="text-nowrap"><?php echo htmlspecialchars((string) ($job->created_at ?? '')); ?></td>
                                            <td><?php $renderStatusBadge($queueJobStatusBadge((string) ($job->status ?? 'pending')), (string) ($job->status ?? 'pending')); ?></td>
                                            <td class="text-break"><?php echo htmlspecialchars((string) ($job->recipient ?? '')); ?></td>
                                            <td class="text-break"><?php echo htmlspecialchars((string) ($job->subject ?? '')); ?></td>
                                            <td><?php echo (int) ($job->attempts ?? 0); ?> / <?php echo (int) ($job->max_attempts ?? 0); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($job->source ?? 'system')); ?></td>
                                            <td class="text-nowrap"><?php echo htmlspecialchars((string) ($job->available_at ?? $job->sent_at ?? '—')); ?></td>
                                            <td class="text-break text-secondary small"><?php echo htmlspecialchars((string) ($job->last_error ?? '—')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
