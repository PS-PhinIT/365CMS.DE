<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_MEMBER_VIEW')) {
    exit;
}

$settings = $data['settings'] ?? [];
$onboarding = $settings['onboarding'] ?? [];
$onboardingAnalytics = is_array($data['onboardingAnalytics'] ?? null) ? $data['onboardingAnalytics'] : [];
$stepsValue = implode("\n", $onboarding['steps'] ?? []);
$completionMode = (string)($onboardingAnalytics['completion_mode'] ?? 'profile');
$completionTitle = $completionMode === 'activity_fallback' ? 'Aktivitätsquote (Fallback)' : 'Onboarding-Abschlussrate';
?>
<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Mitglieder &amp; Zugriff</div>
                <h2 class="page-title">Member Dashboard – Mitglieder-Onboarding</h2>
                <div class="text-muted mt-1">Lege Checkliste, Einführungstext und Call-to-Action für neue Mitglieder fest.</div>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>

        <?php require __DIR__ . '/subnav.php'; ?>

        <div class="row row-cards mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-secondary small mb-1">Aktive Konten</div>
                        <div class="h1 mb-0"><?php echo (int)($onboardingAnalytics['total_active_accounts'] ?? 0); ?></div>
                        <div class="text-secondary small mt-2">Basis für die read-only Auswertung</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-secondary small mb-1"><?php echo htmlspecialchars($completionTitle); ?></div>
                        <div class="d-flex align-items-end gap-2">
                            <div class="h1 mb-0"><?php echo (int)($onboardingAnalytics['completion_rate'] ?? 0); ?>%</div>
                            <span class="text-secondary small mb-1"><?php echo (int)($onboardingAnalytics['completion_accounts'] ?? 0); ?> von <?php echo (int)($onboardingAnalytics['total_active_accounts'] ?? 0); ?></span>
                        </div>
                        <label class="form-label visually-hidden" for="member-onboarding-completion-rate"><?php echo htmlspecialchars($completionTitle); ?></label>
                        <progress id="member-onboarding-completion-rate" class="w-100 mt-3" max="100" value="<?php echo (int)($onboardingAnalytics['completion_rate'] ?? 0); ?>"><?php echo (int)($onboardingAnalytics['completion_rate'] ?? 0); ?>%</progress>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-secondary small mb-1">Security bereit</div>
                        <div class="d-flex align-items-end gap-2">
                            <div class="h1 mb-0"><?php echo (int)($onboardingAnalytics['security_ready_rate'] ?? 0); ?>%</div>
                            <span class="text-secondary small mb-1"><?php echo (int)($onboardingAnalytics['security_ready_accounts'] ?? 0); ?> Konten</span>
                        </div>
                        <div class="text-secondary small mt-2">MFA oder mindestens ein Passkey vorhanden</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-secondary small mb-1">Aktiv in 30 Tagen</div>
                        <div class="d-flex align-items-end gap-2">
                            <div class="h1 mb-0"><?php echo (int)($onboardingAnalytics['recently_active_rate'] ?? 0); ?>%</div>
                            <span class="text-secondary small mb-1"><?php echo (int)($onboardingAnalytics['recently_active_accounts'] ?? 0); ?> Konten</span>
                        </div>
                        <div class="text-secondary small mt-2">
                            <?php echo !empty($onboardingAnalytics['has_recent_login_signal']) ? 'Basierend auf erfolgreichen Logins im Activity-Log.' : 'Kein Login-Signal verfügbar – Kennzahl fail-soft ausgeblendet.'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex flex-wrap justify-content-between gap-2 align-items-center">
                <div>
                    <h3 class="card-title mb-0">Onboarding-Analytics</h3>
                    <div class="text-secondary small mt-1">Aggregierte Kennzahlen ohne personenbezogene Einzeldaten, Tokens oder neue Schreibroute.</div>
                </div>
                <span class="badge <?php echo !empty($onboardingAnalytics['require_profile_completion']) ? 'bg-primary-lt text-primary' : 'bg-secondary-lt text-secondary'; ?>">
                    <?php echo !empty($onboardingAnalytics['require_profile_completion']) ? 'Profil-Vervollständigung hervorgehoben' : 'Profil-Vervollständigung optional'; ?>
                </span>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between gap-2 mb-1">
                                <label class="form-label mb-0" for="member-onboarding-profile-rate">Profil vollständig</label>
                                <span class="text-secondary small"><?php echo (int)($onboardingAnalytics['profile_completed_accounts'] ?? 0); ?> / <?php echo (int)($onboardingAnalytics['total_active_accounts'] ?? 0); ?></span>
                            </div>
                            <progress id="member-onboarding-profile-rate" class="w-100" max="100" value="<?php echo (int)($onboardingAnalytics['profile_completion_rate'] ?? 0); ?>"><?php echo (int)($onboardingAnalytics['profile_completion_rate'] ?? 0); ?>%</progress>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between gap-2 mb-1">
                                <label class="form-label mb-0" for="member-onboarding-security-rate">Security-Setup</label>
                                <span class="text-secondary small"><?php echo (int)($onboardingAnalytics['security_ready_accounts'] ?? 0); ?> / <?php echo (int)($onboardingAnalytics['total_active_accounts'] ?? 0); ?></span>
                            </div>
                            <progress id="member-onboarding-security-rate" class="w-100" max="100" value="<?php echo (int)($onboardingAnalytics['security_ready_rate'] ?? 0); ?>"><?php echo (int)($onboardingAnalytics['security_ready_rate'] ?? 0); ?>%</progress>
                            <div class="text-secondary small mt-1">Davon MFA: <?php echo (int)($onboardingAnalytics['mfa_enabled_accounts'] ?? 0); ?> · Passkeys: <?php echo (int)($onboardingAnalytics['passkey_ready_accounts'] ?? 0); ?></div>
                        </div>

                        <div>
                            <div class="d-flex justify-content-between gap-2 mb-1">
                                <label class="form-label mb-0" for="member-onboarding-activity-rate">Aktive Nutzung</label>
                                <span class="text-secondary small"><?php echo (int)($onboardingAnalytics['recently_active_accounts'] ?? 0); ?> / <?php echo (int)($onboardingAnalytics['total_active_accounts'] ?? 0); ?></span>
                            </div>
                            <progress id="member-onboarding-activity-rate" class="w-100" max="100" value="<?php echo (int)($onboardingAnalytics['recently_active_rate'] ?? 0); ?>"><?php echo (int)($onboardingAnalytics['recently_active_rate'] ?? 0); ?>%</progress>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="list-group list-group-flush">
                            <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span>Konfigurierte Schritte</span>
                                <strong><?php echo (int)($onboardingAnalytics['steps_configured'] ?? 0); ?></strong>
                            </div>
                            <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span>Berücksichtigte Profilfelder</span>
                                <strong><?php echo (int)($onboardingAnalytics['profile_fields_considered'] ?? 0); ?></strong>
                            </div>
                            <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span>Konten mit CTA-Bedarf</span>
                                <strong><?php echo (int)($onboardingAnalytics['profile_incomplete_accounts'] ?? 0); ?></strong>
                            </div>
                        </div>
                        <div class="alert alert-info mt-3 mb-0" role="status">
                            <?php echo htmlspecialchars((string)($onboardingAnalytics['basis_note'] ?? 'Die Kennzahlen werden read-only aus bestehenden Signalen abgeleitet.')); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="settings_section" value="onboarding">

            <div class="row row-cards">
                <div class="col-lg-8">
                    <div class="card mb-4">
                        <div class="card-header"><h3 class="card-title">Onboarding-Inhalt</h3></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" name="onboarding_enabled" value="1" <?php echo !empty($onboarding['enabled']) ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Onboarding-Panel aktivieren</span>
                                </label>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="onboarding_title">Titel</label>
                                <input id="onboarding_title" name="onboarding_title" type="text" class="form-control" value="<?php echo htmlspecialchars((string)($onboarding['title'] ?? '')); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="onboarding_intro">Einleitung</label>
                                <textarea id="onboarding_intro" name="onboarding_intro" class="form-control" rows="4"><?php echo htmlspecialchars((string)($onboarding['intro'] ?? '')); ?></textarea>
                            </div>
                            <div>
                                <label class="form-label" for="onboarding_steps">Checkliste (eine Zeile = ein Schritt)</label>
                                <textarea id="onboarding_steps" name="onboarding_steps" class="form-control" rows="8"><?php echo htmlspecialchars($stepsValue); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card mb-4">
                        <div class="card-header"><h3 class="card-title">Aktion</h3></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label" for="onboarding_cta_label">CTA-Label</label>
                                <input id="onboarding_cta_label" name="onboarding_cta_label" type="text" class="form-control" value="<?php echo htmlspecialchars((string)($onboarding['cta_label'] ?? '')); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="onboarding_cta_url">CTA-URL</label>
                                <input id="onboarding_cta_url" name="onboarding_cta_url" type="text" class="form-control" value="<?php echo htmlspecialchars((string)($onboarding['cta_url'] ?? '')); ?>">
                            </div>
                            <label class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" name="onboarding_require_profile_completion" value="1" <?php echo !empty($onboarding['require_profile_completion']) ? 'checked' : ''; ?>>
                                <span class="form-check-label">Profil-Vervollständigung verlangen</span>
                                <span class="form-hint d-block">Bis alle Pflichtfelder ausgefüllt sind, zeigt der Mitgliederbereich einen Hinweis und sperrt Nachrichten und Dateien.</span>
                            </label>
                        </div>
                        <div class="card-footer">
                            <button type="submit" class="btn btn-primary w-100">Onboarding speichern</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
