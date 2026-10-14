<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

if (!defined('CMS_ADMIN_SYSTEM_VIEW')) {
	exit;
}

$settings = $data['email_alerts'] ?? [];
$securityAlerts = is_array($data['security_alerts'] ?? null) ? $data['security_alerts'] : [];
$securitySnapshot = is_array($securityAlerts['snapshot'] ?? null) ? $securityAlerts['snapshot'] : [];
$securityLastRun = is_array($securityAlerts['last_run'] ?? null) ? $securityAlerts['last_run'] : [];
$securityLastSent = is_array($securityAlerts['last_sent'] ?? null) ? $securityAlerts['last_sent'] : [];
?>
<div class="page-header d-print-none">
	<div class="container-xl">
		<div class="row g-2 align-items-center">
			<div class="col">
				<div class="page-pretitle">Diagnose</div>
				<h2 class="page-title">E-Mail-Benachrichtigungen</h2>
				<div class="text-secondary mt-1">Konfiguriert Empfängeradresse, Monitoring-Schwellenwerte und Security-Alarmierung über die zentrale Mail-Pipeline.</div>
			</div>
		</div>
	</div>
</div>

<div class="page-body">
	<div class="container-xl">
		<?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>

		<?php require __DIR__ . '/subnav.php'; ?>

		<div class="row row-cards">
			<div class="col-12 col-xl-8">
				<div class="card h-100">
					<div class="card-header"><h3 class="card-title">Alert-Konfiguration</h3></div>
					<div class="card-body">
						<div class="text-secondary small mb-4">Monitoring- und Security-Mails verwenden dieselbe Zieladresse und dieselbe bestehende Queue-/Mail-Infrastruktur. Die Auslösung bleibt read-only über den stündlichen Cron-Pfad; es werden keine Tokens in URLs eingebaut.</div>
						<form method="post">
							<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
							<input type="hidden" name="action" value="save_monitoring_alerts">

							<label class="form-check form-switch mb-4">
								<input class="form-check-input" type="checkbox" name="monitor_email_notifications_enabled" value="1" <?php echo ($settings['monitor_email_notifications_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
								<span class="form-check-label">E-Mail-Benachrichtigungen aktivieren</span>
							</label>

							<div class="row">
								<div class="col-md-6">
									<div class="mb-3">
										<label for="fld-monitor_alert_email" class="form-label">Empfänger-E-Mail</label>
										<input id="fld-monitor_alert_email" class="form-control" type="email" name="monitor_alert_email" value="<?php echo htmlspecialchars((string)($settings['monitor_alert_email'] ?? '')); ?>">
									</div>
								</div>
								<div class="col-md-3">
									<div class="mb-3">
										<label for="fld-monitor_response_threshold_ms" class="form-label">Antwortzeit-Schwelle (ms)</label>
										<input id="fld-monitor_response_threshold_ms" class="form-control" type="number" min="100" name="monitor_response_threshold_ms" value="<?php echo htmlspecialchars((string)($settings['monitor_response_threshold_ms'] ?? '800')); ?>">
									</div>
								</div>
								<div class="col-md-3">
									<div class="mb-3">
										<label for="fld-monitor_disk_threshold_percent" class="form-label">Disk-Warnung ab (%)</label>
										<input id="fld-monitor_disk_threshold_percent" class="form-control" type="number" min="1" max="99" name="monitor_disk_threshold_percent" value="<?php echo htmlspecialchars((string)($settings['monitor_disk_threshold_percent'] ?? '85')); ?>">
									</div>
								</div>
							</div>

							<label class="form-check form-switch mb-3">
								<input class="form-check-input" type="checkbox" name="monitor_health_endpoint_enabled" value="1" <?php echo ($settings['monitor_health_endpoint_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
								<span class="form-check-label">Lokalen Health-Endpunkt aktiv prüfen</span>
							</label>

							<div class="mb-4">
								<label for="fld-monitor_health_endpoint_path" class="form-label">Health-Endpunkt-Pfad</label>
								<div class="form-hint mb-2">Nur lokale Pfade wie <code>/health</code> oder <code>/status/health</code>. Der Health-Check ruft diesen Pfad real gegen die eigene Installation auf.</div>
								<input id="fld-monitor_health_endpoint_path" class="form-control" type="text" name="monitor_health_endpoint_path" value="<?php echo htmlspecialchars((string)($settings['monitor_health_endpoint_path'] ?? '/health')); ?>">
							</div>

							<hr class="my-4">

							<div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
								<div>
									<h3 class="card-title mb-1">Security-Alarmierung</h3>
									<div class="text-secondary small">Schwellenwert-basierte Mails für Login-Brute-Force, AntiSpam-Spitzen und Firewall-Blocks. Der Lauf verwendet die vorhandene Monitoring-Mail-Pipeline und den stündlichen Core-Cron.</div>
								</div>
								<span class="badge <?php echo !empty($securityAlerts['enabled']) ? 'bg-green-lt text-green' : 'bg-secondary-lt text-secondary'; ?>">
									<?php echo !empty($securityAlerts['enabled']) ? 'Aktiv' : 'Inaktiv'; ?>
								</span>
							</div>

							<label class="form-check form-switch mb-4">
								<input class="form-check-input" type="checkbox" name="security_email_notifications_enabled" value="1" <?php echo ($settings['security_email_notifications_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
								<span class="form-check-label">Security-Alarmierung aktivieren</span>
							</label>

							<div class="row">
								<div class="col-md-4">
									<div class="mb-3">
										<label for="fld-security_alert_window_minutes" class="form-label">Betrachtungsfenster (Min.)</label>
										<input id="fld-security_alert_window_minutes" class="form-control" type="number" min="5" max="1440" name="security_alert_window_minutes" value="<?php echo htmlspecialchars((string)($settings['security_alert_window_minutes'] ?? '60')); ?>">
									</div>
								</div>
								<div class="col-md-4">
									<div class="mb-3">
										<label for="fld-security_alert_cooldown_minutes" class="form-label">Cooldown (Min.)</label>
										<input id="fld-security_alert_cooldown_minutes" class="form-control" type="number" min="15" max="10080" name="security_alert_cooldown_minutes" value="<?php echo htmlspecialchars((string)($settings['security_alert_cooldown_minutes'] ?? '180')); ?>">
									</div>
								</div>
							</div>

							<div class="row">
								<div class="col-md-4">
									<div class="mb-3">
										<label for="fld-security_alert_bruteforce_threshold" class="form-label">Login-Brute-Force ab</label>
										<input id="fld-security_alert_bruteforce_threshold" class="form-control" type="number" min="1" max="10000" name="security_alert_bruteforce_threshold" value="<?php echo htmlspecialchars((string)($settings['security_alert_bruteforce_threshold'] ?? '15')); ?>">
									</div>
								</div>
								<div class="col-md-4">
									<div class="mb-3">
										<label for="fld-security_alert_antispam_threshold" class="form-label">AntiSpam-Spitze ab</label>
										<input id="fld-security_alert_antispam_threshold" class="form-control" type="number" min="1" max="10000" name="security_alert_antispam_threshold" value="<?php echo htmlspecialchars((string)($settings['security_alert_antispam_threshold'] ?? '10')); ?>">
									</div>
								</div>
								<div class="col-md-4">
									<div class="mb-3">
										<label for="fld-security_alert_firewall_threshold" class="form-label">Firewall-Blocks ab</label>
										<input id="fld-security_alert_firewall_threshold" class="form-control" type="number" min="1" max="10000" name="security_alert_firewall_threshold" value="<?php echo htmlspecialchars((string)($settings['security_alert_firewall_threshold'] ?? '10')); ?>">
									</div>
								</div>
							</div>

							<button type="submit" class="btn btn-primary">Einstellungen speichern</button>
						</form>
					</div>
				</div>
			</div>

			<div class="col-12 col-xl-4">
				<div class="card mb-4">
					<div class="card-header"><h3 class="card-title">Test-E-Mail</h3></div>
					<div class="card-body">
						<p class="text-secondary small">Prüft direkt im Backend, ob die zentrale Mail-Implementierung des CMS Nachrichten an die konfigurierte Monitoring-Adresse versenden kann.</p>

						<form method="post" class="d-flex flex-column gap-3">
							<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
							<input type="hidden" name="action" value="send_monitoring_test_email">

							<div>
								<label for="fld-test_email_recipient" class="form-label">Test-E-Mail an</label>
								<input id="fld-test_email_recipient" class="form-control" type="email" name="test_email_recipient" value="<?php echo htmlspecialchars((string)($settings['monitor_alert_email'] ?? '')); ?>" placeholder="monitoring@example.com">
							</div>

							<button type="submit" class="btn btn-outline-primary">Test-E-Mail senden</button>
						</form>
					</div>
				</div>

				<div class="card h-100">
					<div class="card-header"><h3 class="card-title">Security-Alert-Status</h3></div>
					<div class="card-body">
						<div class="small text-secondary mb-3">Aktuelles Fenster: <?php echo (int)($securityAlerts['window_minutes'] ?? 60); ?> Minuten</div>
						<div class="list-group list-group-flush">
							<div class="list-group-item px-0 d-flex justify-content-between">
								<span>Login-Brute-Force</span>
								<strong><?php echo (int)($securitySnapshot['bruteforce']['count'] ?? 0); ?></strong>
							</div>
							<div class="list-group-item px-0 d-flex justify-content-between">
								<span>AntiSpam-Rejections</span>
								<strong><?php echo (int)($securitySnapshot['antispam']['count'] ?? 0); ?></strong>
							</div>
							<div class="list-group-item px-0 d-flex justify-content-between">
								<span>Firewall-Blocks</span>
								<strong><?php echo (int)($securitySnapshot['firewall']['count'] ?? 0); ?></strong>
							</div>
						</div>

						<div class="mt-4 small text-secondary">
							<div><strong>Letzter Scan:</strong> <?php echo htmlspecialchars((string)($securityLastRun['executed_at'] ?? 'Noch keiner')); ?></div>
							<div class="mt-1"><strong>Letzte Mail:</strong>
								<?php
								$lastSentTimes = [];
								foreach ($securityLastSent as $type => $entry) {
									if (!is_array($entry) || empty($entry['sent_at'])) {
										continue;
									}
									$lastSentTimes[] = (string)$entry['sent_at'] . ' (' . (string)$type . ')';
								}
								echo htmlspecialchars($lastSentTimes !== [] ? implode(', ', $lastSentTimes) : 'Noch keine', ENT_QUOTES, 'UTF-8');
								?>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
