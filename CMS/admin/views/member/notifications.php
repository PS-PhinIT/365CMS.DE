<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_MEMBER_VIEW')) {
    exit;
}

$settings = $data['settings'] ?? [];
$notifications = $settings['notifications'] ?? [];
$types = $data['notificationTypes'] ?? [];
$frequencies = $data['digestFrequencies'] ?? [];
?>
<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Mitglieder &amp; Zugriff</div>
                <h2 class="page-title">Member Dashboard – Benachrichtigungen</h2>
                <div class="text-muted mt-1">Definiere, welche Meldungen sichtbar sind, wie oft E-Mail-Digests versendet werden und wie der Notification-Hub wirkt.</div>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>

        <?php require __DIR__ . '/subnav.php'; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="settings_section" value="notifications">

            <div class="row row-cards">
                <div class="col-lg-8">
                    <div class="card mb-4">
                        <div class="card-header"><h3 class="card-title">Zentrale Steuerung</h3></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-check form-switch">
                                    <input type="checkbox" name="notification_center_enabled" class="form-check-input" value="1" <?php echo !empty($notifications['center_enabled']) ? 'checked' : ''; ?>>
                                    <span class="form-check-label">In-App Notification Center aktivieren</span>
                                </label>
                            </div>
                            <div class="mb-3">
                                <label class="form-check form-switch">
                                    <input type="checkbox" name="notification_email_enabled" class="form-check-input" value="1" <?php echo !empty($notifications['email_enabled']) ? 'checked' : ''; ?>>
                                    <span class="form-check-label">E-Mail-Digests erlauben</span>
                                </label>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="notification_digest_frequency">Digest-Frequenz</label>
                                    <select id="notification_digest_frequency" name="notification_digest_frequency" class="form-select">
                                        <?php foreach ($frequencies as $value => $label): ?>
                                            <option value="<?php echo htmlspecialchars((string)$value); ?>" <?php echo ($notifications['digest_frequency'] ?? 'daily') === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="notification_sender_name">Absendername</label>
                                    <input id="notification_sender_name" name="notification_sender_name" type="text" class="form-control" value="<?php echo htmlspecialchars((string)($notifications['sender_name'] ?? '')); ?>">
                                </div>
                            </div>
                            <div class="form-hint mt-3" id="notification_email_hint">
                                Hinweis: Benachrichtigungen erscheinen im Mitgliederbereich (NotificationService). Ein E-Mail-Versand
                                ist in 4.0.0 noch nicht angebunden – E-Mail-Schalter, Digest-Frequenz und Absendername werden gespeichert
                                und greifen erst, wenn der Versand ergänzt ist.
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><h3 class="card-title">Benachrichtigungstypen</h3></div>
                        <div class="card-body">
                            <div class="row row-cards">
                                <?php foreach ($types as $key => $label): ?>
                                    <div class="col-md-6">
                                        <label class="card card-sm h-100 cursor-pointer">
                                            <div class="card-body">
                                                <div class="form-check mb-0">
                                                    <input type="checkbox" class="form-check-input" name="notification_types[<?php echo htmlspecialchars((string)$key); ?>]" value="1" <?php echo in_array($key, $notifications['types'] ?? [], true) ? 'checked' : ''; ?>>
                                                    <span class="form-check-label fw-semibold"><?php echo htmlspecialchars((string)$label); ?></span>
                                                </div>
                                            </div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-3">
                                <label class="form-label" for="notification_empty_text">Fallback-Text ohne neue Meldungen</label>
                                <textarea id="notification_empty_text" name="notification_empty_text" class="form-control" rows="3"><?php echo htmlspecialchars((string)($notifications['empty_text'] ?? '')); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card sticky-top" style="top: 1rem;">
                        <div class="card-header"><h3 class="card-title">Speichern</h3></div>
                        <div class="card-body">
                            <p class="text-muted small">Die Einstellungen steuern sowohl das Notification-Panel im Dashboard als auch spätere Mail-Digests.</p>
                            <button type="submit" class="btn btn-primary w-100">Benachrichtigungen speichern</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
