<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @var array{attempts:array{value:int,source:string,constant:?int,setting:?int},timeout:array{value:int,source:string,constant:?int,setting:?int}} $securityThresholds
 * @var string $securityCsrfToken
 * @var array<string,string>|null $securityAlert
 */

use CMS\Services\LoginThrottleSettings;

$secEsc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$secT = static fn (string $key, string|int ...$args): string => htmlspecialchars($args !== [] ? vsprintf((string) __($key), $args) : (string) __($key), ENT_QUOTES, 'UTF-8');
$secAlert = is_array($securityAlert ?? null) ? $securityAlert : null;
$secSource = static fn (string $source): string => match ($source) {
    LoginThrottleSettings::SOURCE_SETTING => (string) __('security_settings.source.setting'),
    LoginThrottleSettings::SOURCE_CONSTANT => (string) __('security_settings.source.constant'),
    default => (string) __('security_settings.source.default'),
};
$secAttempts = $securityThresholds['attempts'];
$secTimeout = $securityThresholds['timeout'];
?>
<div class="page-wrapper">
    <div class="page-header d-print-none">
        <div class="container-xl">
            <h1 class="page-title"><?= $secT('security_settings.title') ?></h1>
        </div>
    </div>
    <div class="page-body">
        <div class="container-xl">
            <?php require __DIR__ . '/partials-tabs.php'; ?>
            <?php if ($secAlert !== null): ?>
                <div class="alert alert-<?= $secEsc($secAlert['type'] ?? 'info') ?> mt-3" role="status"><?= $secEsc($secAlert['message'] ?? '') ?></div>
            <?php endif; ?>

            <div class="row row-cards mt-1">
                <div class="col-md-6">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="text-secondary small"><?= $secT('security_settings.effective_attempts') ?></div>
                            <div class="h2 mb-1"><?= (int) $secAttempts['value'] ?></div>
                            <span class="badge bg-blue-lt"><?= $secEsc($secSource($secAttempts['source'])) ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="text-secondary small"><?= $secT('security_settings.effective_timeout') ?></div>
                            <div class="h2 mb-1"><?= $secT('security_settings.seconds_minutes', (int) $secTimeout['value'], (string) round($secTimeout['value'] / 60, 1)) ?></div>
                            <span class="badge bg-blue-lt"><?= $secEsc($secSource($secTimeout['source'])) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <form method="post" action="/admin/settings?tab=security" class="card mt-3">
                <input type="hidden" name="csrf_token" value="<?= $secEsc($securityCsrfToken) ?>">
                <div class="card-header">
                    <h2 class="card-title"><?= $secT('security_settings.heading') ?></h2>
                </div>
                <div class="card-body">
                    <p class="text-secondary"><?= $secT('security_settings.intro') ?></p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="sec-attempts"><?= $secT('security_settings.attempts') ?></label>
                            <input class="form-control" type="number" id="sec-attempts" name="max_login_attempts"
                                   min="<?= LoginThrottleSettings::MIN_ATTEMPTS ?>" max="<?= LoginThrottleSettings::MAX_ATTEMPTS ?>"
                                   value="<?= $secAttempts['setting'] !== null ? (int) $secAttempts['setting'] : '' ?>"
                                   placeholder="<?= $secAttempts['constant'] !== null ? (int) $secAttempts['constant'] : LoginThrottleSettings::DEFAULT_ATTEMPTS ?>">
                            <div class="form-hint"><?= $secT('security_settings.attempts_hint', LoginThrottleSettings::MIN_ATTEMPTS, LoginThrottleSettings::MAX_ATTEMPTS, $secAttempts['constant'] !== null ? (string) $secAttempts['constant'] : '–') ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="sec-timeout"><?= $secT('security_settings.timeout') ?></label>
                            <div class="input-group">
                                <input class="form-control" type="number" id="sec-timeout" name="login_timeout"
                                       min="<?= LoginThrottleSettings::MIN_TIMEOUT ?>" max="<?= LoginThrottleSettings::MAX_TIMEOUT ?>" step="1"
                                       value="<?= $secTimeout['setting'] !== null ? (int) $secTimeout['setting'] : '' ?>"
                                       placeholder="<?= $secTimeout['constant'] !== null ? (int) $secTimeout['constant'] : LoginThrottleSettings::DEFAULT_TIMEOUT ?>">
                                <span class="input-group-text"><?= $secT('security_settings.seconds') ?></span>
                            </div>
                            <div class="form-hint"><?= $secT('security_settings.timeout_hint', LoginThrottleSettings::MIN_TIMEOUT, LoginThrottleSettings::MAX_TIMEOUT, $secTimeout['constant'] !== null ? (string) $secTimeout['constant'] : '–') ?></div>
                        </div>
                    </div>
                    <p class="form-hint mt-3 mb-0"><?= $secT('security_settings.scope_hint') ?></p>
                </div>
                <div class="card-footer d-flex justify-content-between">
                    <?php if ($secAttempts['setting'] !== null || $secTimeout['setting'] !== null): ?>
                        <button class="btn btn-link" type="submit" name="use_constants" value="1"><?= $secT('security_settings.use_constants') ?></button>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="submit"><?= $secEsc((string) __('Save Changes')) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
