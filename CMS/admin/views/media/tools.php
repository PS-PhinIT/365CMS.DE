<?php
declare(strict_types=1);

/**
 * Medien-Werkzeuge: Vorschaubilder neu erzeugen (#13) und Ausgabe der Urheberangaben (#11).
 *
 * @var array<string, mixed> $data sizes, image_count, max_images, active_job, latest_job, latest_webp, image_library, avif, license, api
 * @var string $csrfToken
 */

if (!defined('ABSPATH')) {
    exit;
}

$mtE = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$mtT = static fn (string $key, string|int ...$args): string => htmlspecialchars($args !== [] ? vsprintf((string) __($key), $args) : (string) __($key), ENT_QUOTES, 'UTF-8');
$mtNumber = static fn (int $value): string => number_format($value, 0, ',', '.');
$mtDate = static function (string $value): string {
    $ts = $value !== '' ? strtotime($value) : false;

    return $ts !== false ? date('d.m.Y H:i', $ts) : '–';
};
$mtSizes = is_array($data['sizes'] ?? null) ? $data['sizes'] : [];
$mtActive = is_array($data['active_job'] ?? null) ? $data['active_job'] : null;
$mtLatest = is_array($data['latest_job'] ?? null) ? $data['latest_job'] : null;
$mtLicense = is_array($data['license'] ?? null) ? $data['license'] : [];
$mtCount = (int) ($data['image_count'] ?? 0);
$mtLibrary = !empty($data['image_library']);
$mtStatusKeys = ['pending' => 'search_index.job_status_pending', 'running' => 'search_index.job_status_running', 'done' => 'search_index.job_status_done', 'failed' => 'search_index.job_status_failed', 'cancelled' => 'search_index.job_status_cancelled'];

$mtRunnerLabels = [];
foreach (['pending', 'running', 'done', 'failed', 'cancelled', 'paused', 'busy', 'pause', 'resume', 'cancel', 'networkError', 'errors', 'progress'] as $mtLabel) {
    $mtRunnerLabels[$mtLabel] = (string) __('search_index.runner_' . strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $mtLabel)));
}
$mtRunnerLabels['cancelTitle'] = (string) __('media_tools.thumbs.cancel_title');
$mtRunnerLabels['cancelMessage'] = (string) __('media_tools.thumbs.cancel_message');
$mtConfig = [
    'endpoint' => (string) ($data['api']['endpoint'] ?? ''),
    'token' => (string) ($data['api']['token'] ?? ''),
    'activeJob' => $mtActive,
    'runnerLabels' => $mtRunnerLabels,
    'labels' => [
        'done' => (string) __('media_tools.thumbs.done'),
        'resumed' => (string) __('media_tools.thumbs.already_running'),
        'error' => (string) __('media_tools.error.internal'),
        'cancelTitle' => $mtRunnerLabels['cancelTitle'],
        'cancelMessage' => $mtRunnerLabels['cancelMessage'],
        'cancel' => $mtRunnerLabels['cancel'],
    ],
];
$mtSizeLabels = ['small' => 'media_tools.size.small', 'medium' => 'media_tools.size.medium', 'large' => 'media_tools.size.large', 'banner' => 'media_tools.size.banner'];
?>
<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle"><?php echo $mtT('media_tools.pretitle'); ?></div>
                <h2 class="page-title mb-1"><?php echo $mtT('media_tools.title'); ?></h2>
                <div class="content-listing-header__meta"><span><?php echo $mtT('media_tools.intro'); ?></span></div>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <div class="row row-cards">
            <div class="col-lg-7">
                <div class="card" id="thumbnails" data-media-tools="<?php echo $mtE(json_encode($mtConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>">
                    <div class="card-header"><h3 class="card-title"><?php echo $mtT('media_tools.thumbs.title'); ?></h3></div>
                    <div class="card-body">
                        <p class="text-secondary"><?php echo $mtT('media_tools.thumbs.intro'); ?></p>

                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-vcenter mb-0">
                                <thead><tr><th><?php echo $mtT('media_tools.thumbs.col_size'); ?></th><th class="text-end"><?php echo $mtT('media_tools.thumbs.col_dimensions'); ?></th></tr></thead>
                                <tbody>
                                    <?php foreach ($mtSizes as $mtName => [$mtW, $mtH]): ?>
                                        <tr>
                                            <td><?php echo $mtT($mtSizeLabels[$mtName] ?? 'media_tools.size.small'); ?> <code class="small">-<?php echo $mtE($mtName); ?></code></td>
                                            <td class="text-end"><?php echo $mtE((int) $mtW . ' × ' . (int) $mtH . ' px'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="small text-secondary">
                            <?php echo $mtT('media_tools.thumbs.sizes_hint'); ?>
                            <a href="/admin/media?tab=settings"><?php echo $mtT('media_tools.thumbs.sizes_link'); ?></a>
                        </p>

                        <?php if (!$mtLibrary): ?>
                            <div class="alert alert-warning" role="status"><?php echo $mtT('media_tools.thumbs.no_library'); ?></div>
                        <?php elseif ($mtActive !== null): ?>
                            <div class="alert alert-warning" role="status" data-media-tools-notice>
                                <?php echo $mtT('media_tools.thumbs.interrupted', $mtNumber((int) $mtActive['processed']), $mtNumber((int) $mtActive['total']), $mtDate((string) $mtActive['created_at'])); ?>
                            </div>
                        <?php else: ?>
                            <p data-media-tools-notice><?php echo $mtT('media_tools.thumbs.count', $mtNumber($mtCount)); ?>
                                <?php if ($mtCount >= (int) ($data['max_images'] ?? 0)): ?><span class="text-warning"><?php echo $mtT('media_tools.thumbs.max_hint', $mtNumber((int) $data['max_images'])); ?></span><?php endif; ?>
                            </p>
                        <?php endif; ?>

                        <?php if ($mtLibrary): ?>
                            <?php if ($mtActive === null): ?>
                                <label class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" value="1" data-media-tools-webp<?php echo !empty($data['latest_webp']) ? ' checked' : ''; ?>>
                                    <span class="form-check-label"><?php echo $mtT('media_tools.thumbs.webp'); ?></span>
                                </label>
                            <?php endif; ?>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-primary" data-media-tools-start<?php echo $mtActive === null && $mtCount === 0 ? ' disabled' : ''; ?>>
                                    <?php echo $mtT($mtActive !== null ? 'media_tools.thumbs.resume' : 'media_tools.thumbs.start'); ?>
                                </button>
                                <?php if ($mtActive !== null): ?>
                                    <button type="button" class="btn btn-outline-danger" data-media-tools-cancel><?php echo $mtT('search_index.runner_cancel'); ?></button>
                                <?php endif; ?>
                            </div>
                            <p class="small text-secondary mt-2 mb-0"><?php echo $mtT('media_tools.thumbs.cron_hint'); ?></p>
                        <?php endif; ?>

                        <div class="mt-3" data-media-tools-progress hidden></div>

                        <?php if ($mtLatest !== null && $mtActive === null): ?>
                            <p class="small text-secondary mt-3 mb-0" data-media-tools-latest>
                                <?php echo $mtT(
                                    'media_tools.thumbs.last_run',
                                    (string) __($mtStatusKeys[(string) $mtLatest['status']] ?? 'search_index.job_status_pending'),
                                    $mtDate((string) ($mtLatest['finished_at'] !== '' ? $mtLatest['finished_at'] : $mtLatest['created_at'])),
                                    $mtNumber((int) $mtLatest['processed']),
                                    $mtNumber((int) $mtLatest['total'])
                                ); ?>
                                <?php if ((string) $mtLatest['message'] !== ''): ?> · <?php echo $mtE($mtLatest['message']); ?><?php endif; ?>
                            </p>
                            <?php if ((int) $mtLatest['failed'] > 0 && !empty($mtLatest['errors'])): ?>
                                <details class="mt-2">
                                    <summary class="small text-danger"><?php echo $mtT('media_tools.thumbs.errors', (int) $mtLatest['failed']); ?></summary>
                                    <ul class="small mb-0 mt-1">
                                        <?php foreach (array_slice((array) $mtLatest['errors'], -20) as $mtError): ?>
                                            <li><?php echo $mtE($mtError); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                // 4.1.0 (2a): AVIF nachgenerieren – eigener Auftrag (job=avif), gleiche Runner-Logik.
                $mtAvif = is_array($data['avif'] ?? null) ? $data['avif'] : [];
                $mtAvifActive = is_array($mtAvif['active_job'] ?? null) ? $mtAvif['active_job'] : null;
                $mtAvifLatest = is_array($mtAvif['latest_job'] ?? null) ? $mtAvif['latest_job'] : null;
                $mtAvifCount = (int) ($mtAvif['image_count'] ?? 0);
                $mtAvifConfig = $mtConfig;
                $mtAvifConfig['job'] = 'avif';
                $mtAvifConfig['activeJob'] = $mtAvifActive;
                $mtAvifConfig['labels']['done'] = (string) __('media_tools.avif.done');
                $mtAvifConfig['runnerLabels']['cancelTitle'] = (string) __('media_tools.avif.cancel_title');
                $mtAvifConfig['runnerLabels']['cancelMessage'] = (string) __('media_tools.avif.cancel_message');
                $mtAvifConfig['labels']['cancelTitle'] = $mtAvifConfig['runnerLabels']['cancelTitle'];
                $mtAvifConfig['labels']['cancelMessage'] = $mtAvifConfig['runnerLabels']['cancelMessage'];
                ?>
                <div class="card mt-3" id="avif" data-media-tools="<?php echo $mtE(json_encode($mtAvifConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>">
                    <div class="card-header"><h3 class="card-title"><?php echo $mtT('media_tools.avif.title'); ?></h3></div>
                    <div class="card-body">
                        <p class="text-secondary"><?php echo $mtT('media_tools.avif.intro'); ?></p>
                        <?php if (empty($mtAvif['supported'])): ?>
                            <div class="alert alert-warning mb-0" role="status"><?php echo $mtT('media_tools.avif.unsupported'); ?></div>
                        <?php else: ?>
                            <?php if (empty($mtAvif['enabled'])): ?>
                                <p class="small text-secondary"><?php echo $mtT('media_tools.avif.disabled_hint'); ?> <a href="/admin/media?tab=settings"><?php echo $mtT('media_tools.thumbs.sizes_link'); ?></a></p>
                            <?php endif; ?>
                            <?php if ($mtAvifActive !== null): ?>
                                <div class="alert alert-warning" role="status" data-media-tools-notice>
                                    <?php echo $mtT('media_tools.thumbs.interrupted', $mtNumber((int) $mtAvifActive['processed']), $mtNumber((int) $mtAvifActive['total']), $mtDate((string) $mtAvifActive['created_at'])); ?>
                                </div>
                            <?php else: ?>
                                <p data-media-tools-notice><?php echo $mtT('media_tools.thumbs.count', $mtNumber($mtAvifCount)); ?></p>
                            <?php endif; ?>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <button type="button" class="btn btn-primary" data-media-tools-start<?php echo $mtAvifActive === null && $mtAvifCount === 0 ? ' disabled' : ''; ?>>
                                    <?php echo $mtT($mtAvifActive !== null ? 'media_tools.thumbs.resume' : 'media_tools.avif.start'); ?>
                                </button>
                                <?php if ($mtAvifActive !== null): ?>
                                    <button type="button" class="btn btn-outline-danger" data-media-tools-cancel><?php echo $mtT('search_index.runner_cancel'); ?></button>
                                <?php endif; ?>
                            </div>
                            <p class="small text-secondary mt-2 mb-0"><?php echo $mtT('media_tools.thumbs.cron_hint'); ?></p>
                            <div class="mt-3" data-media-tools-progress hidden></div>
                            <?php if ($mtAvifLatest !== null && $mtAvifActive === null): ?>
                                <p class="small text-secondary mt-3 mb-0" data-media-tools-latest>
                                    <?php echo $mtT(
                                        'media_tools.thumbs.last_run',
                                        (string) __($mtStatusKeys[(string) $mtAvifLatest['status']] ?? 'search_index.job_status_pending'),
                                        $mtDate((string) ($mtAvifLatest['finished_at'] !== '' ? $mtAvifLatest['finished_at'] : $mtAvifLatest['created_at'])),
                                        $mtNumber((int) $mtAvifLatest['processed']),
                                        $mtNumber((int) $mtAvifLatest['total'])
                                    ); ?>
                                    <?php if ((string) $mtAvifLatest['message'] !== ''): ?> · <?php echo $mtE($mtAvifLatest['message']); ?><?php endif; ?>
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <form class="card" method="post" action="/admin/media-tools" id="credits">
                    <input type="hidden" name="csrf_token" value="<?php echo $mtE($csrfToken); ?>">
                    <input type="hidden" name="action" value="save_license_output">
                    <div class="card-header"><h3 class="card-title"><?php echo $mtT('media_tools.license.title'); ?></h3></div>
                    <div class="card-body">
                        <p class="text-secondary"><?php echo $mtT('media_tools.license.intro'); ?></p>
                        <?php foreach (['credit_line', 'schema', 'prefill'] as $mtKey): ?>
                            <label class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="<?php echo $mtE($mtKey); ?>" value="1"<?php echo !empty($mtLicense[$mtKey]) ? ' checked' : ''; ?>>
                                <span class="form-check-label"><?php echo $mtT('media_tools.license.' . $mtKey); ?></span>
                                <span class="form-hint"><?php echo $mtT('media_tools.license.' . $mtKey . '_hint'); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="card-footer text-end">
                        <button type="submit" class="btn btn-primary"><?php echo $mtT('media_tools.license.save'); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
