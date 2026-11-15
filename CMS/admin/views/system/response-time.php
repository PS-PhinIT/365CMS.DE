<?php
declare(strict_types=1);

if (!defined('ABSPATH')) exit;
if (!defined('CMS_ADMIN_SYSTEM_VIEW')) exit;

$response = is_array($data['monitoring']['response_time'] ?? null) ? $data['monitoring']['response_time'] : [];
$settings = is_array($data['email_alerts'] ?? null) ? $data['email_alerts'] : [];
$trend = is_array($data['trend_history'] ?? null) ? $data['trend_history'] : [];
$trendRanges = is_array($trend['ranges'] ?? null) ? $trend['ranges'] : [];
$trendNote = (string)($trend['note'] ?? '');
$trendLastCapturedAt = (string)($trend['last_captured_at'] ?? '');
$trendUnit = (string)($trend['unit'] ?? 'ms');
$trendColor = (string)($trend['sparkline_color'] ?? '#206bc4');
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
				<h2 class="page-title">Response-Time Monitoring</h2>
				<div class="text-secondary mt-1">Misst die aktuelle Antwortzeit der Hauptseite gegen die gespeicherte Schwelle und zeigt zusätzlich eine stündlich verdichtete Trendhistorie.</div>
				<?php /* Release-Prüfung 4.1.0 (PERF, blocking:0): Herkunft der Messung und explizite Live-Messung. */ ?>
				<?php if ((string)($response['note'] ?? '') !== ''): ?>
					<div class="text-secondary small mt-1"><?php echo htmlspecialchars((string)$response['note']); ?></div>
				<?php endif; ?>
			</div>
			<?php if (empty($response['skipped'])): ?>
				<div class="col-auto ms-auto d-print-none">
					<a href="?measure=now" class="btn btn-outline-primary">Jetzt messen</a>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
<div class="page-body">
	<div class="container-xl">
		<?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
		<?php require __DIR__ . '/subnav.php'; ?>

		<div class="row row-deck row-cards mb-4">
			<div class="col-md-4">
				<div class="card">
					<div class="card-body">
						<div class="subheader">Antwortzeit</div>
						<div class="h1 mb-0"><?php echo (int)($response['duration_ms'] ?? 0); ?> ms</div>
						<div class="text-secondary">Schwelle: <?php echo (int)($settings['monitor_response_threshold_ms'] ?? 800); ?> ms</div>
					</div>
				</div>
			</div>
			<div class="col-md-4">
				<div class="card">
					<div class="card-body">
						<div class="subheader">HTTP-Status</div>
						<div class="h1 mb-0"><?php echo (int)($response['status_code'] ?? 0); ?></div>
					</div>
				</div>
			</div>
			<div class="col-md-4">
				<div class="card">
					<div class="card-body">
						<div class="subheader">Ergebnis</div>
						<?php if (!empty($response['skipped'])): ?>
							<div class="h1 mb-0 text-secondary">Nicht messbar</div>
						<?php else: ?>
							<div class="h1 mb-0 <?php echo empty($response['error']) ? 'text-success' : 'text-danger'; ?>"><?php echo empty($response['error']) ? 'OK' : 'Fehler'; ?></div>
						<?php endif; ?>
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
												<?php echo $renderSparkline($points, $trendColor, 'Response-Time-Verlauf ' . (string)($range['label'] ?? '')); ?>
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

		<div class="card">
			<div class="card-header"><h3 class="card-title">Messdetails</h3></div>
			<div class="table-responsive">
				<table class="table table-vcenter card-table">
					<tbody>
					<tr>
						<td class="text-muted w-25">URL</td>
						<td class="text-break"><?php echo htmlspecialchars((string)($response['url'] ?? '')); ?></td>
					</tr>
					<tr>
						<td class="text-muted">Messzeitpunkt</td>
						<td><?php echo htmlspecialchars((string)($response['measured_at'] ?? '') !== '' ? (string)$response['measured_at'] : '–'); ?></td>
					</tr>
					<tr>
						<td class="text-muted">Fehler</td>
						<td><?php echo htmlspecialchars((string)($response['error'] ?? 'Keiner')); ?></td>
					</tr>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>
