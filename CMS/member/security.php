<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';

$controller->handleSecurityRequest();

$pageTitle = 'Sicherheit';
$pageKey = 'security';
$pageAssets = [];
$securityData = $controller->getSecurityPageData();
$security = is_array($securityData['security'] ?? null) ? $securityData['security'] : [];
$sessions = is_array($securityData['sessions'] ?? null) ? $securityData['sessions'] : [];
$credentials = is_array($securityData['credentials'] ?? null) ? $securityData['credentials'] : [];
$passkeyPayload = is_array($securityData['passkey_payload'] ?? null) ? $securityData['passkey_payload'] : ['available' => false, 'options_json' => '{}'];
$totpSetup = is_array($securityData['totp_setup'] ?? null) ? $securityData['totp_setup'] : null;
$totpQrUrl = trim((string)($totpSetup['qr_data_uri'] ?? $totpSetup['qr_url'] ?? ''));
$totpSecret = trim((string)($totpSetup['secret'] ?? ''));
$totpOtpUri = trim((string)($totpSetup['otp_uri'] ?? ''));

include __DIR__ . '/partials/header.php';
?>
<div class="row g-4">
    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">Security Score</h3>
            </div>
            <div class="card-body text-center">
                <div class="display-4 fw-bold text-primary mb-2"><?= (int)($security['score'] ?? 0) ?></div>
                <p class="text-secondary mb-3"><?= htmlspecialchars((string)($security['score_message'] ?? '')) ?></p>
                <div class="progress progress-sm">
                    <div class="progress-bar bg-primary" style="width: <?= (int)($security['score'] ?? 0) ?>%"></div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Empfehlungen</h3></div>
            <div class="list-group list-group-flush">
                <?php foreach ((array)($security['recommendations'] ?? []) as $recommendation): ?>
                    <div class="list-group-item d-flex gap-3">
                        <span class="badge <?= !empty($recommendation['done']) ? 'bg-green-lt text-green' : 'bg-amber-lt text-amber' ?> mt-1">
                            <?= !empty($recommendation['done']) ? '✓' : '!' ?>
                        </span>
                        <div><?= htmlspecialchars((string)($recommendation['text'] ?? '')) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="row g-4">
            <div class="col-12">
                <form class="card" method="post" action="">
                    <input type="hidden" name="action" value="password_change">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($controller->csrfToken('security_password'), ENT_QUOTES) ?>">
                    <div class="card-header"><h3 class="card-title">Passwort ändern</h3></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="current_password">Aktuelles Passwort</label>
                                <input class="form-control" id="current_password" name="current_password" type="password" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="new_password">Neues Passwort</label>
                                <input class="form-control" id="new_password" name="new_password" type="password" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="confirm_password">Wiederholen</label>
                                <input class="form-control" id="confirm_password" name="confirm_password" type="password" required>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer text-end">
                        <button type="submit" class="btn btn-primary">Passwort speichern</button>
                    </div>
                </form>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title">Zwei-Faktor-Authentifizierung</h3>
                        <span class="badge <?= !empty($securityData['totp_enabled']) ? 'bg-green-lt text-green' : 'bg-secondary-lt text-secondary' ?>">
                            <?= !empty($securityData['totp_enabled']) ? 'Aktiv' : 'Nicht aktiv' ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if ($totpSetup !== null): ?>
                            <div class="row g-4 align-items-center">
                                <div class="col-md-4 text-center">
                                    <?php if ($totpQrUrl !== ''): ?>
                                        <img class="img-fluid rounded border" src="<?= htmlspecialchars($totpQrUrl, ENT_QUOTES) ?>" alt="TOTP QR-Code">
                                    <?php else: ?>
                                        <div class="border rounded p-4 bg-light text-secondary">QR-Code derzeit nicht verfügbar</div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-8">
                                    <p class="text-secondary">Scanne den QR-Code mit deiner Authenticator-App und bestätige anschließend den 6-stelligen Code.</p>
                                    <?php if ($totpSecret !== ''): ?>
                                        <div class="mb-3">
                                            <div class="small text-secondary mb-1">Manuelle Einrichtung</div>
                                            <code><?= htmlspecialchars($totpSecret) ?></code>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($totpOtpUri !== ''): ?>
                                        <div class="mb-3 small text-secondary text-break">
                                            OTP-URI: <a href="<?= htmlspecialchars($totpOtpUri, ENT_QUOTES) ?>"><?= htmlspecialchars($totpOtpUri) ?></a>
                                        </div>
                                    <?php endif; ?>
                                    <form method="post" action="" class="d-flex flex-wrap gap-2 align-items-end">
                                        <input type="hidden" name="action" value="totp_confirm">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($controller->csrfToken('security_mfa'), ENT_QUOTES) ?>">
                                        <div>
                                            <label class="form-label" for="totp_code">TOTP-Code</label>
                                            <input class="form-control" id="totp_code" name="totp_code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
                                        </div>
                                        <button type="submit" class="btn btn-primary">MFA aktivieren</button>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="text-secondary">Schütze dein Konto zusätzlich mit einem Authenticator und Backup-Codes.</p>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if (empty($securityData['totp_enabled'])): ?>
                                    <form method="post" action="">
                                        <input type="hidden" name="action" value="totp_start">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($controller->csrfToken('security_mfa'), ENT_QUOTES) ?>">
                                        <button type="submit" class="btn btn-primary">TOTP einrichten</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="" class="d-flex flex-wrap gap-2 align-items-end" data-cms-confirm="Neue Backup-Codes erzeugen? Die bisherigen Codes werden ungültig.">
                                        <input type="hidden" name="action" value="backup_generate">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($controller->csrfToken('security_mfa'), ENT_QUOTES) ?>">
                                        <div>
                                            <label class="form-label" for="backup_current_password">Aktuelles Passwort</label>
                                            <input class="form-control" id="backup_current_password" name="current_password" type="password" autocomplete="current-password" required>
                                        </div>
                                        <button type="submit" class="btn btn-outline-primary">Backup-Codes erneuern</button>
                                    </form>
                                    <form method="post" action="" class="d-flex flex-wrap gap-2 align-items-end" data-cms-confirm="Zwei-Faktor-Authentifizierung wirklich deaktivieren?">
                                        <input type="hidden" name="action" value="totp_disable">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($controller->csrfToken('security_mfa'), ENT_QUOTES) ?>">
                                        <div>
                                            <label class="form-label" for="mfa_disable_current_password">Aktuelles Passwort</label>
                                            <input class="form-control" id="mfa_disable_current_password" name="current_password" type="password" autocomplete="current-password" required>
                                        </div>
                                        <button type="submit" class="btn btn-outline-danger">MFA deaktivieren</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer text-secondary small">
                        Verfügbare Backup-Codes: <?= (int)($securityData['backup_count'] ?? 0) ?>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title">Passkeys</h3>
                        <span class="badge <?= !empty($passkeyPayload['available']) ? 'bg-blue-lt text-blue' : 'bg-secondary-lt text-secondary' ?>">
                            <?= !empty($passkeyPayload['available']) ? 'Verfügbar' : 'Nicht verfügbar' ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <p class="text-secondary">Registriere einen Hardware-Key oder Plattform-Passkey für schnellere und sicherere Logins.</p>
                        <?php if (!empty($passkeyPayload['available'])): ?>
                            <form method="post" action="" data-passkey-form data-passkey-options='<?= htmlspecialchars((string)($passkeyPayload['options_json'] ?? '{}'), ENT_QUOTES) ?>'>
                                <input type="hidden" name="action" value="passkey_register">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($controller->csrfToken('security_passkey'), ENT_QUOTES) ?>">
                                <input type="hidden" name="client_data_json" value="">
                                <input type="hidden" name="attestation_object" value="">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-4">
                                        <label class="form-label" for="credential_name">Bezeichnung</label>
                                        <input class="form-control" id="credential_name" name="credential_name" type="text" value="Mein Gerät">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="passkey_current_password">Aktuelles Passwort</label>
                                        <input class="form-control" id="passkey_current_password" name="current_password" type="password" autocomplete="current-password" required>
                                    </div>
                                    <div class="col-md-4">
                                        <button type="button" class="btn btn-primary" data-passkey-register>Passkey registrieren</button>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="table-responsive">
                        <table class="table card-table table-vcenter">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Erstellt</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($credentials === []): ?>
                                    <tr><td colspan="3" class="text-secondary">Noch keine Passkeys registriert.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($credentials as $credential): ?>
                                        <?php
                                        $credentialName = is_array($credential)
                                            ? (string)($credential['name'] ?? 'Passkey')
                                            : (string)($credential->name ?? 'Passkey');
                                        $credentialCreatedAt = is_array($credential)
                                            ? (string)($credential['created_at'] ?? '')
                                            : (string)($credential->created_at ?? '');
                                        $credentialRecordId = is_array($credential)
                                            ? (int)($credential['id'] ?? 0)
                                            : (int)($credential->id ?? 0);
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($credentialName) ?></td>
                                            <td><?= htmlspecialchars($credentialCreatedAt) ?></td>
                                            <td>
                                                <form method="post" action="" data-cms-confirm="Passkey wirklich entfernen?">
                                                    <input type="hidden" name="action" value="passkey_delete">
                                                    <input type="hidden" name="credential_id" value="<?= $credentialRecordId ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($controller->csrfToken('security_passkey'), ENT_QUOTES) ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Entfernen</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Aktive Sessions</h3></div>
                    <div class="table-responsive">
                        <table class="table card-table table-vcenter">
                            <thead>
                                <tr>
                                    <th>Session</th>
                                    <th>IP / User Agent</th>
                                    <th>Zuletzt aktiv</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($sessions === []): ?>
                                    <tr><td colspan="3" class="text-secondary">Keine Sessions gefunden.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($sessions as $session): ?>
                                        <tr>
                                            <?php $sessionKey = (string) ($session->id ?? ''); ?>
                                            <td>
                                                <code><?= htmlspecialchars(substr($sessionKey, 0, 8), ENT_QUOTES, 'UTF-8') ?></code>
                                                <?php if ($sessionKey !== '' && session_id() !== '' && hash_equals($sessionKey, hash('sha256', session_id()))): ?>
                                                    <span class="badge bg-green-lt ms-1">Diese Sitzung</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div><?= htmlspecialchars((string)($session->ip_address ?? 'Unbekannt')) ?></div>
                                                <div class="text-secondary small"><?= htmlspecialchars((string)($session->user_agent ?? '')) ?></div>
                                            </td>
                                            <td><?= htmlspecialchars((string)($session->last_activity ?? '')) ?></td>
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
<?php include __DIR__ . '/partials/footer.php';
