<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
if (!defined('CMS_ADMIN_SYSTEM_VIEW')) exit;

$cron = $data['cron'] ?? [];
$hooks = $cron['hooks'] ?? [];
$commands = $cron['commands'] ?? [];
$mailQueue = $cron['mail_queue'] ?? [];
$runner = $cron['runner'] ?? [];
$lastRun = is_array($cron['last_run'] ?? null) ? $cron['last_run'] : [];
$runnerTasks = is_array($runner['tasks'] ?? null) ? $runner['tasks'] : ['all', 'mail-queue', 'hourly', 'daily'];
$runnerDefaultTask = (string) ($runner['default_task'] ?? 'all');
$runnerDefaultLimit = (int) ($runner['default_limit'] ?? ($mailQueue['batch_size'] ?? 10));
$loopbackUrl = (string) ($runner['loopback_url'] ?? '');
$cronRunnerEndpoint = isset($cronRunnerEndpoint) ? (string) $cronRunnerEndpoint : '/admin/monitor-cron-runner';
$cronRunnerToken = isset($cronRunnerToken) ? (string) $cronRunnerToken : '';
$trend = is_array($data['trend_history'] ?? null) ? $data['trend_history'] : [];
$trendRanges = is_array($trend['ranges'] ?? null) ? $trend['ranges'] : [];
$trendNote = (string)($trend['note'] ?? '');
$trendLastCapturedAt = (string)($trend['last_captured_at'] ?? '');
$trendUnit = (string)($trend['unit'] ?? ' min');
$trendColor = (string)($trend['sparkline_color'] ?? '#d63939');
$trendSignatures = [];
foreach ($trendRanges as $range) {
	if (!is_array($range)) {
		continue;
	}
	$trendSignatures[] = md5(json_encode([
		'avg' => (float) ($range['average'] ?? 0),
		'min' => (float) ($range['min'] ?? 0),
		'max' => (float) ($range['max'] ?? 0),
		'delta' => (float) ($range['delta'] ?? 0),
		'points' => is_array($range['points'] ?? null) ? array_map(static fn(array $point): float => (float) ($point['value'] ?? 0), (array) $range['points']) : [],
	]) ?: '');
}
$showNoHistoryOverlay = $trendRanges !== []
	&& str_contains($trendNote, 'Noch keine gespeicherten Monitoring-Snapshots vorhanden')
	&& count(array_unique($trendSignatures)) <= 1;

$deltaToneClasses = [
	'success' => 'bg-green-lt text-green',
	'danger' => 'bg-red-lt text-red',
	'warning' => 'bg-yellow-lt text-yellow',
	'info' => 'bg-blue-lt text-blue',
	'neutral' => 'bg-secondary-lt text-secondary',
];

$formatNumber = static function (float $value, int $decimals = 0): string {
	return number_format($value, $decimals, ',', '.');
};

$formatDelta = static function (float $delta, string $unit) use ($formatNumber): string {
	$prefix = $delta > 0 ? '+' : '';
	return $prefix . $formatNumber($delta, 0) . $unit;
};

$renderSparkline = static function (array $points, string $strokeColor, string $label): string {
	$values = array_map(static fn(array $point): float => (float)($point['value'] ?? 0.0), $points);
	if ($values === []) {
		return '<div class="text-secondary small">Keine Daten</div>';
	}

	$width = 100.0;
	$height = 32.0;
	$padding = 3.0;
	$min = min($values);
	$max = max($values);
	$range = max(0.01, $max - $min);
	$count = max(1, count($values) - 1);
	$path = [];

	foreach ($values as $index => $value) {
		$x = $padding + (($width - (2 * $padding)) * ($index / $count));
		$normalized = ($value - $min) / $range;
		$y = $height - $padding - (($height - (2 * $padding)) * $normalized);
		$path[] = sprintf('%s%.2F %.2F', $index === 0 ? 'M ' : 'L ', $x, $y);
	}

	$lastX = $padding + (($width - (2 * $padding)) * ((count($values) - 1) / $count));
	$lastValue = (float)$values[array_key_last($values)];
	$lastY = $height - $padding - (($height - (2 * $padding)) * (($lastValue - $min) / $range));

	return sprintf(
		'<svg viewBox="0 0 100 32" role="img" aria-label="%s"><path d="%s" fill="none" stroke="%s" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path><circle cx="%.2F" cy="%.2F" r="2.5" fill="%s"></circle></svg>',
		htmlspecialchars($label, ENT_QUOTES),
		htmlspecialchars(implode(' ', $path), ENT_QUOTES),
		htmlspecialchars($strokeColor, ENT_QUOTES),
		$lastX,
		$lastY,
		htmlspecialchars($strokeColor, ENT_QUOTES)
	);
};
?>
<div class="page-header d-print-none">
	<div class="container-xl">
		<div class="row g-2 align-items-center">
			<div class="col">
				<div class="page-pretitle">Diagnose</div>
				<h2 class="page-title">Cron-Job Status</h2>
				<div class="text-secondary mt-1">Erkennt registrierte Cron-Hooks im Codebestand, zeigt den letzten stündlichen Lauf und ergänzt jetzt eine Trendhistorie für den Cron-Lag.</div>
			</div>
		</div>
	</div>
</div>
<div class="page-body">
	<div class="container-xl">
		<?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
		<?php require __DIR__ . '/subnav.php'; ?>

		<div class="row row-deck row-cards mb-4">
			<div class="col-sm-6 col-lg-3">
				<div class="card">
					<div class="card-body">
						<div class="subheader">Cron-Datei</div>
						<div class="h1 mb-0 <?php echo !empty($cron['cron_file_exists']) ? 'text-success' : 'text-warning'; ?>">
							<?php echo !empty($cron['cron_file_exists']) ? 'Vorhanden' : 'Nicht gefunden'; ?>
						</div>
						<?php if (!empty($cron['cron_file_path'])): ?>
							<div class="text-secondary mt-2 small text-break"><code><?php echo htmlspecialchars((string) $cron['cron_file_path']); ?></code></div>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<div class="col-sm-6 col-lg-3">
				<div class="card">
					<div class="card-body">
						<div class="subheader">Registrierte Hooks</div>
						<div class="h1 mb-0"><?php echo (int)($cron['hook_count'] ?? 0); ?></div>
					</div>
				</div>
			</div>
			<div class="col-sm-6 col-lg-3">
				<div class="card">
					<div class="card-body">
						<div class="subheader">Letzter stündlicher Lauf</div>
						<div class="h1 mb-0 <?php echo !empty($lastRun['is_due']) ? 'text-danger' : 'text-success'; ?>"><?php echo htmlspecialchars((string)($lastRun['status_label'] ?? 'Unbekannt')); ?></div>
						<?php if (!empty($lastRun['timestamp'])): ?>
							<div class="text-secondary mt-2 small"><?php echo htmlspecialchars((string)$lastRun['timestamp']); ?></div>
						<?php endif; ?>
						<div class="text-secondary mt-1 small">
							Schedule: <code><?php echo htmlspecialchars((string) ($lastRun['schedule_expression'] ?? '0 * * * *')); ?></code>
							· <?php echo htmlspecialchars((string) ($lastRun['scheduler'] ?? 'fallback-interval')); ?>
						</div>
					</div>
				</div>
			</div>
			<div class="col-sm-6 col-lg-3">
				<div class="card">
					<div class="card-body">
						<div class="subheader">Mail-Queue Batch</div>
						<div class="h1 mb-0"><?php echo (int)($mailQueue['batch_size'] ?? 0); ?></div>
						<div class="text-secondary mt-2 small">
							<?php echo !empty($mailQueue['enabled']) ? 'Mail-Queue aktiv' : 'Mail-Queue deaktiviert'; ?>
						</div>
					</div>
				</div>
			</div>
		</div>

		<?php if ($trendRanges !== []): ?>
			<div class="card mb-4">
				<div class="card-header">
					<div>
						<h3 class="card-title mb-1">Trendhistorie</h3>
						<div class="text-secondary small">
							<?php echo htmlspecialchars($trendNote); ?>
							<?php if ($trendLastCapturedAt !== ''): ?>
								· Letzter Snapshot: <?php echo htmlspecialchars($trendLastCapturedAt); ?>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<div class="card-body">
					<div class="row row-deck row-cards">
						<?php foreach ($trendRanges as $range): ?>
							<?php
							$deltaClass = $deltaToneClasses[(string)($range['delta_tone'] ?? 'neutral')] ?? $deltaToneClasses['neutral'];
							$points = is_array($range['points'] ?? null) ? $range['points'] : [];
							?>
							<div class="col-md-4">
								<div class="card h-100 border-0 bg-body-lt admin-monitor-trend-card">
									<div class="card-body d-flex flex-column gap-3">
										<div class="d-flex justify-content-between align-items-start gap-3">
											<div>
												<div class="subheader"><?php echo htmlspecialchars((string)($range['label'] ?? 'Verlauf')); ?></div>
												<div class="h2 mb-1">Ø <?php echo $formatNumber((float)($range['average'] ?? 0), 0); ?><?php echo htmlspecialchars($trendUnit); ?></div>
												<div class="text-secondary small">Min <?php echo $formatNumber((float)($range['min'] ?? 0), 0); ?><?php echo htmlspecialchars($trendUnit); ?> · Max <?php echo $formatNumber((float)($range['max'] ?? 0), 0); ?><?php echo htmlspecialchars($trendUnit); ?></div>
											</div>
											<div class="chart-sparkline chart-sparkline-wide" aria-hidden="true">
												<?php echo $renderSparkline($points, $trendColor, 'Cron-Lag ' . (string)($range['label'] ?? '')); ?>
											</div>
										</div>
										<div class="d-flex justify-content-between align-items-center gap-2">
											<span class="badge <?php echo htmlspecialchars($deltaClass); ?>"><?php echo htmlspecialchars($formatDelta((float)($range['delta'] ?? 0.0), $trendUnit)); ?> vs. letzter Punkt</span>
											<span class="text-secondary small"><?php echo (int)($range['point_count'] ?? 0); ?> Punkte</span>
										</div>
									</div>
									<?php if ($showNoHistoryOverlay): ?>
										<div class="admin-monitor-trend-overlay">Noch keine Verlaufsdaten</div>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<div class="card mb-4">
			<div class="card-header">
				<h3 class="card-title">Zentrale Cron-Schnittstelle</h3>
			</div>
			<div class="card-body">
				<p class="text-secondary mb-3">Die zentrale Cron-Datei ist <code>cron.php</code> im CMS-Webroot, läuft standardmäßig still und eignet sich für Webhosting-Cronjobs ohne störenden Output. Verwende bevorzugt <code>task=all</code>.</p>
				<div class="row g-3">
					<div class="col-lg-6">
						<label class="form-label">CLI – kompletter Lauf</label>
						<pre class="bg-light border rounded p-3 small text-break mb-0"><code><?php echo htmlspecialchars((string)($commands['cli_all'] ?? '')); ?></code></pre>
					</div>
					<div class="col-lg-6">
						<label class="form-label">CLI – nur Mail-Queue</label>
						<pre class="bg-light border rounded p-3 small text-break mb-0"><code><?php echo htmlspecialchars((string)($commands['cli_mail_queue'] ?? '')); ?></code></pre>
					</div>
					<div class="col-lg-6">
						<label class="form-label">CLI – nur Feeds aktualisieren</label>
						<pre class="bg-light border rounded p-3 small text-break mb-0"><code><?php echo htmlspecialchars((string)($commands['cli_feeds'] ?? '')); ?></code></pre>
					</div>
					<div class="col-lg-6">
						<label class="form-label">URL – kompletter Lauf</label>
						<pre class="bg-light border rounded p-3 small text-break mb-0"><code><?php echo htmlspecialchars((string)($commands['web_all'] ?? '')); ?></code></pre>
					</div>
					<div class="col-lg-6">
						<label class="form-label">URL – nur Mail-Queue</label>
						<pre class="bg-light border rounded p-3 small text-break mb-0"><code><?php echo htmlspecialchars((string)($commands['web_mail_queue'] ?? '')); ?></code></pre>
					</div>
					<div class="col-lg-6">
						<label class="form-label">URL – nur Feeds aktualisieren</label>
						<pre class="bg-light border rounded p-3 small text-break mb-0"><code><?php echo htmlspecialchars((string)($commands['web_feeds'] ?? '')); ?></code></pre>
					</div>
					<div class="col-lg-6">
						<label class="form-label">cURL – JSON-Ausgabe</label>
						<pre class="bg-light border rounded p-3 small text-break mb-0"><code><?php echo htmlspecialchars((string)($commands['curl_all'] ?? '')); ?></code></pre>
					</div>
					<div class="col-lg-6">
						<label class="form-label">PowerShell – JSON-Ausgabe</label>
						<pre class="bg-light border rounded p-3 small text-break mb-0"><code><?php echo htmlspecialchars((string)($commands['powershell_all'] ?? '')); ?></code></pre>
					</div>
				</div>
			</div>
		</div>

		<div class="row row-cards mb-4">
			<div class="col-12 col-xl-7">
				<form method="post" class="card" data-cron-runner-form data-cron-runner-endpoint="<?php echo htmlspecialchars($cronRunnerEndpoint, ENT_QUOTES); ?>" data-cron-runner-token="<?php echo htmlspecialchars($cronRunnerToken, ENT_QUOTES); ?>">
					<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $csrfToken, ENT_QUOTES); ?>">
					<div class="card-header">
						<h3 class="card-title">Cron im CMS ausführen</h3>
					</div>
					<div class="card-body">
						<p class="text-secondary mb-3">Der Systembereich kann den vorhandenen Cron jetzt auf mehreren Wegen starten: direkt im Core ohne HTTP/TLS, per Loopback gegen <code>/cron.php</code> oder via Admin-Ajax ohne Seitenreload.</p>
						<div class="row g-3">
							<div class="col-md-4">
								<label for="fld-cron_task" class="form-label">Task</label>
								<select id="fld-cron_task" name="cron_task" class="form-select">
									<?php foreach ($runnerTasks as $task): ?>
										<option value="<?php echo htmlspecialchars((string) $task, ENT_QUOTES); ?>" <?php echo $runnerDefaultTask === (string) $task ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $task); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-4">
								<label for="fld-cron_limit" class="form-label">Limit</label>
								<input id="fld-cron_limit" type="number" name="cron_limit" class="form-control" min="1" max="100" value="<?php echo (int) $runnerDefaultLimit; ?>">
							</div>
							<div class="col-md-4">
								<label class="form-label d-block">Optionen</label>
								<label class="form-check form-switch mt-2">
									<input class="form-check-input" type="checkbox" name="cron_force" value="1">
									<span class="form-check-label">Zeitgesteuerte Hooks / Feed-Task erzwingen</span>
								</label>
							</div>
						</div>
						<div class="mt-3 small text-secondary">
							<strong>Direkt:</strong> läuft komplett im aktuellen Admin-Request und umgeht HTTP/TLS-Probleme. <strong>Loopback:</strong> testet den echten Web-Endpunkt <code><?php echo htmlspecialchars($loopbackUrl !== '' ? $loopbackUrl : '/cron.php'); ?></code> inkl. Token und Hosting-Setup.
						</div>
					</div>
					<div class="card-footer d-flex flex-column align-items-start gap-2">
						<div class="admin-cron-hint"><i class="ti ti-info-circle" aria-hidden="true"></i> Empfohlen für Shared Hosting: Direkt im CMS ausführen</div>
						<div class="d-flex gap-2 flex-wrap">
						<button type="submit" class="btn btn-primary" name="action" value="run_cron_direct">Direkt im CMS ausführen</button>
						<button type="submit" class="btn btn-outline-primary" name="action" value="run_cron_loopback">HTTP-Loopback auf /cron.php</button>
						<button type="button" class="btn btn-outline-secondary" data-cron-runner-trigger="direct">Ajax: direkt testen</button>
						<button type="button" class="btn btn-outline-secondary" data-cron-runner-trigger="loopback">Ajax: Loopback testen</button>
						</div>
					</div>
				</form>
			</div>
			<div class="col-12 col-xl-5">
				<div class="card h-100">
					<div class="card-header">
						<h3 class="card-title">Ajax-Ergebnis</h3>
					</div>
					<div class="card-body">
						<p class="text-secondary small mb-3">Der Ajax-Runner ruft den Cron-Mechanismus aus dem Systembereich heraus an und zeigt die strukturierte Antwort direkt hier an.</p>
						<pre class="bg-light border rounded p-3 small mb-0" data-cron-runner-output>Bereit – wähle einen Ajax-Button, um einen Lauf ohne Seitenreload zu starten.</pre>
					</div>
				</div>
			</div>
		</div>

		<div class="card">
			<div class="card-header">
				<h3 class="card-title">Gefundene Cron-Hooks</h3>
			</div>
			<div class="table-responsive">
				<table class="table table-vcenter card-table table-striped">
					<thead>
					<tr>
						<th>Hook</th>
						<th>Vorkommen</th>
						<th>Quelldateien</th>
					</tr>
					</thead>
					<tbody>
					<?php if (empty($hooks)): ?>
						<tr>
							<td colspan="3" class="text-center text-secondary py-4">Keine registrierten Cron-Hooks gefunden.</td>
						</tr>
					<?php else: ?>
						<?php foreach ($hooks as $hook): ?>
							<tr>
								<td><code><?php echo htmlspecialchars((string)$hook['hook']); ?></code></td>
								<td><?php echo (int)$hook['occurrences']; ?></td>
								<td class="text-break"><?php echo htmlspecialchars(implode(', ', (array)$hook['files'])); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>
