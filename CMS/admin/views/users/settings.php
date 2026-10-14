<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$settings = $data['settings'] ?? [];
$roles = $data['roles'] ?? [];
$stats = $data['stats'] ?? [];
$providers = $data['providers'] ?? [];
$ldap = $data['ldap'] ?? [];
$jwt = $data['jwt'] ?? [];
$passkey = $data['passkey'] ?? [];
$security = $data['security'] ?? [];
$passwordPolicy = is_array($security['password_policy'] ?? null) ? $security['password_policy'] : ['min_length' => 12, 'requirements' => [], 'definition' => ['patterns' => []]];
$passwordPolicyRequirements = is_array($passwordPolicy['requirements'] ?? null) ? $passwordPolicy['requirements'] : [];
$memberDashboardGeneralUrl = '/admin/member-dashboard-general';

$providerLabels = [
    'session' => 'Session-Login',
    'passkey' => 'Passkeys / WebAuthn',
    'ldap' => 'LDAP',
    'totp' => 'TOTP / MFA',
    'backup' => 'Backup-Codes',
];

$renderStatusBadge = static function (bool $enabled, string $enabledLabel = 'Aktiv', string $disabledLabel = 'Inaktiv'): string {
    $class = $enabled ? 'success' : 'secondary';
    $label = $enabled ? $enabledLabel : $disabledLabel;

    return '<span class="badge bg-' . $class . '-lt">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
};

$passwordPolicyTesterConfig = [
    'minLength' => (int) ($passwordPolicy['min_length'] ?? ($security['password_min_length'] ?? 12)),
    'patterns' => is_array($passwordPolicy['definition']['patterns'] ?? null) ? $passwordPolicy['definition']['patterns'] : [],
    'requirements' => array_map(static function (array $requirement): array {
        return [
            'key' => (string) ($requirement['key'] ?? ''),
            'label' => (string) ($requirement['label'] ?? ''),
            'message' => (string) ($requirement['message'] ?? ''),
        ];
    }, is_array($passwordPolicy['requirements'] ?? null) ? $passwordPolicy['requirements'] : []),
];
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Benutzer &amp; Gruppen</div>
                <h2 class="page-title mb-1">Einstellungen</h2>
                <div class="content-listing-header__meta">
                    <span><?php echo (int)($stats['total_users'] ?? 0); ?> Benutzer gesamt</span>
                    <span><?php echo (int)($stats['mfa_users'] ?? 0); ?> mit MFA</span>
                    <span><?php echo (int)($stats['passkey_credentials'] ?? 0); ?> Passkeys</span>
                    <span><?php echo (int)($stats['backup_code_users'] ?? 0); ?> Backup-Code-Sets</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl cms-settings-page">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>

        <div class="cms-admin-info-box mb-4" role="note">
            <div class="cms-admin-info-box__head">
                <h3 class="cms-admin-info-box__title">Auth- und Registrierungssteuerung</h3>
                <div class="cms-admin-info-box__actions">
                    <a href="/admin/users" class="btn btn-sm btn-outline-secondary">Benutzer</a>
                    <a href="/admin/groups" class="btn btn-sm btn-outline-secondary">Gruppen</a>
                    <a href="/admin/roles" class="btn btn-sm btn-outline-secondary">Rollen</a>
                </div>
            </div>
            <p class="cms-admin-info-box__text">
                Dieser Bereich bündelt Registrierung, Passwort-Policy und Provider-Status. Technische Runtime-Werte bleiben bewusst read-only.
            </p>
        </div>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="save">
            <div class="cms-settings-actions">
                <span class="text-secondary small me-auto">Registrierung und Authentifizierung zentral steuern; technische Provider bleiben schreibgeschützt.</span>
                <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
            </div>

            <div class="row row-cards">
                <div class="col-12">
                    <h3 class="cms-settings-section-heading">Kernkonfiguration</h3>
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title mb-0">Registrierung &amp; Konten</h3>
                            <span class="badge bg-primary-lt">DB-gestützt</span>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-check form-switch">
                                    <input type="checkbox" name="registration_enabled" class="form-check-input" value="1" <?php echo !empty($settings['registration_enabled']) ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Globale Benutzerregistrierung aktivieren</span>
                                </label>
                                <div class="form-hint">Früher unter <code>/admin/settings</code>. Der Schalter bleibt erhalten, ist jetzt aber logisch bei Benutzer &amp; Auth verortet.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-check form-switch">
                                    <input type="checkbox" name="member_registration_enabled" class="form-check-input" value="1" <?php echo !empty($settings['member_registration_enabled']) ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Registrierung im Member-Bereich erlauben</span>
                                </label>
                                <div class="form-hint">Steuert den registrierungsbezogenen Einstieg im Member-Dashboard-Kontext.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-check form-switch">
                                    <input type="checkbox" name="member_email_verification" class="form-check-input" value="1" <?php echo !empty($settings['member_email_verification']) ? 'checked' : ''; ?>>
                                    <span class="form-check-label">E-Mail-Verifizierung für neue Mitglieder erzwingen</span>
                                </label>
                                <div class="form-hint">Sinnvoll für Community-, Netzwerk- und Portal-Setups mit sensiblen Profilen.</div>
                            </div>

                            <div>
                                <label class="form-label" for="member_default_role">Standardrolle für neue Registrierungen</label>
                                <select id="member_default_role" name="member_default_role" class="form-select">
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo htmlspecialchars((string)$role); ?>" <?php echo (($settings['member_default_role'] ?? 'member') === $role) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(ucfirst(str_replace(['-', '_'], ' ', (string)$role))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint">Die Auswahl wirkt jetzt direkt auf öffentliche Registrierungen. Aus Sicherheitsgründen werden hier nur registrierungsgeeignete, nicht-administrative Rollen angeboten. Willkommens- und Dashboard-Texte bleiben weiterhin unter <a href="<?php echo htmlspecialchars($memberDashboardGeneralUrl, ENT_QUOTES, 'UTF-8'); ?>">Member Dashboard → Allgemein</a>.</div>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title mb-0">Login-Schutz &amp; Passwort-Policy</h3>
                            <span class="badge bg-secondary-lt">config/app.php</span>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="usersSecurityMaxLoginAttempts" class="form-label">Max. Login-Versuche</label>
                                    <input id="usersSecurityMaxLoginAttempts" type="text" class="form-control" value="<?php echo htmlspecialchars((string)($security['max_login_attempts'] ?? 5)); ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label for="usersSecurityLockout" class="form-label">Sperrzeit</label>
                                    <input id="usersSecurityLockout" type="text" class="form-control" value="<?php echo htmlspecialchars((string)round(((int)($security['login_timeout_seconds'] ?? 300)) / 60)); ?> Minuten" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label for="usersSecurityAdminSession" class="form-label">Admin-Session</label>
                                    <input id="usersSecurityAdminSession" type="text" class="form-control" value="<?php echo htmlspecialchars((string)($security['admin_session_hours'] ?? 8)); ?> Stunden" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label for="usersSecurityMemberSession" class="form-label">Member-Session</label>
                                    <input id="usersSecurityMemberSession" type="text" class="form-control" value="<?php echo htmlspecialchars((string)($security['member_session_days'] ?? 30)); ?> Tage" readonly>
                                </div>
                            </div>

                            <hr>

                            <div class="mb-0">
                                <div class="form-label">Passwortanforderungen</div>
                                <ul class="mb-0 text-secondary ps-3">
                                    <?php foreach ($passwordPolicyRequirements as $requirement): ?>
                                        <li><?php echo htmlspecialchars((string) ($requirement['label'] ?? 'Anforderung')); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <div class="card bg-light mt-4 border-0" data-password-policy-tester data-password-policy-config="<?php echo htmlspecialchars((string) json_encode($passwordPolicyTesterConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                                        <div>
                                            <div class="form-label mb-1">Passwort-Policy-Tester</div>
                                            <div class="text-secondary small">Lokaler Live-Check für den aktuellen Runtime-Vertrag. Die Eingabe wird nicht gespeichert und nicht mitgesendet.</div>
                                        </div>
                                        <span class="badge bg-azure-lt text-azure" data-password-policy-length-badge>0 Zeichen</span>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label" for="passwordPolicyTesterInput">Test-Passwort</label>
                                        <input type="password" id="passwordPolicyTesterInput" class="form-control" autocomplete="new-password" spellcheck="false" placeholder="Passwort lokal gegen die Policy prüfen …" data-password-policy-input>
                                    </div>

                                    <div class="alert alert-secondary mb-3" role="status" aria-live="polite" aria-atomic="true" data-password-policy-status>
                                        Noch kein Passwort geprüft.
                                    </div>

                                    <div class="row g-2" data-password-policy-requirements>
                                        <?php foreach ((array) ($passwordPolicy['requirements'] ?? []) as $requirement): ?>
                                            <div class="col-md-6">
                                                <div class="border rounded px-3 py-2 d-flex align-items-center gap-2 text-secondary" data-password-policy-requirement data-requirement-key="<?php echo htmlspecialchars((string) ($requirement['key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <span aria-hidden="true" data-password-policy-icon>○</span>
                                                    <span><?php echo htmlspecialchars((string) ($requirement['label'] ?? 'Anforderung')); ?></span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title mb-0">Auth-Provider</h3>
                            <span class="badge bg-secondary-lt">Statusübersicht</span>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <?php foreach ($providerLabels as $key => $label): ?>
                                    <div class="col-12">
                                        <div class="py-2 d-flex justify-content-between align-items-center gap-3 border-bottom">
                                            <div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($label); ?></div>
                                                <div class="text-secondary small">Provider: <?php echo htmlspecialchars($key); ?></div>
                                            </div>
                                            <?php echo $renderStatusBadge(!empty($providers[$key])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="row row-cards">
                        <div class="col-12">
                            <h3 class="cms-settings-section-heading">Technischer Status</h3>
                        </div>
                        <div class="col-12">
                            <div class="card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h3 class="card-title mb-0">LDAP</h3>
                                    <?php echo $renderStatusBadge(!empty($ldap['enabled']), 'Aktiv', 'Nicht aktiv'); ?>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="usersLdapHost" class="form-label">Host</label>
                                        <input id="usersLdapHost" type="text" class="form-control" value="<?php echo htmlspecialchars((string)($ldap['host'] ?? '')); ?>" readonly>
                                    </div>
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <label for="usersLdapPort" class="form-label">Port</label>
                                            <input id="usersLdapPort" type="text" class="form-control" value="<?php echo htmlspecialchars((string)($ldap['port'] ?? 389)); ?>" readonly>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="usersLdapDefaultRole" class="form-label">Standardrolle</label>
                                            <input id="usersLdapDefaultRole" type="text" class="form-control" value="<?php echo htmlspecialchars((string)($ldap['default_role'] ?? 'member')); ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="usersLdapBaseDn" class="form-label">Base DN</label>
                                        <input id="usersLdapBaseDn" type="text" class="form-control" value="<?php echo htmlspecialchars((string)($ldap['base_dn'] ?? '')); ?>" readonly>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Erstsynchronisierung</label>
                                        <div class="d-grid gap-2">
                                            <button type="submit" name="action" value="sync_ldap" class="btn btn-outline-primary" <?php echo (empty($ldap['configured']) || empty($ldap['extension_loaded'])) ? 'disabled' : ''; ?>>LDAP-Initial-Sync starten</button>
                                        </div>
                                        <div class="form-hint">Importiert bis zu <?php echo (int)($ldap['sync_limit'] ?? 250); ?> LDAP-Einträge in lokale CMS-Benutzerkonten oder aktualisiert bestehende Zuordnungen.</div>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php echo $renderStatusBadge(!empty($ldap['configured']), 'LDAP konfiguriert', 'LDAP unvollständig'); ?>
                                        <?php echo $renderStatusBadge(!empty($ldap['extension_loaded']), 'PHP-LDAP geladen', 'PHP-LDAP fehlt'); ?>
                                        <?php echo $renderStatusBadge(!empty($ldap['use_ssl']), 'SSL', 'SSL aus'); ?>
                                        <?php echo $renderStatusBadge(!empty($ldap['use_tls']), 'TLS', 'TLS aus'); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h3 class="card-title mb-0">JWT &amp; API</h3>
                                    <?php echo $renderStatusBadge(!empty($jwt['configured']), 'Konfiguriert', 'Fallback aktiv'); ?>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="usersJwtSecretSource" class="form-label">Secret-Quelle</label>
                                        <input id="usersJwtSecretSource" type="text" class="form-control" value="<?php echo htmlspecialchars((string)($jwt['secret_source'] ?? 'AUTH_KEY (Fallback)')); ?>" readonly>
                                    </div>
                                    <div class="mb-3">
                                        <label for="usersJwtIssuer" class="form-label">Issuer</label>
                                        <input id="usersJwtIssuer" type="text" class="form-control" value="<?php echo htmlspecialchars((string)($jwt['issuer'] ?? '')); ?>" readonly>
                                    </div>
                                    <div class="mb-0">
                                        <label for="usersJwtTtl" class="form-label">TTL</label>
                                        <input id="usersJwtTtl" type="text" class="form-control" value="<?php echo htmlspecialchars((string)($jwt['ttl'] ?? 3600)); ?> Sekunden" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Speichern &amp; Hinweise</h3>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="small text-uppercase text-secondary mb-1">Passkeys</div>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php echo $renderStatusBadge(!empty($passkey['available']), 'Verfügbar', 'Nicht verfügbar'); ?>
                                    <?php echo $renderStatusBadge(!empty($passkey['openssl_available']), 'OpenSSL ok', 'OpenSSL fehlt'); ?>
                                    <?php echo $renderStatusBadge(!empty($passkey['site_url_configured']), 'SITE_URL ok', 'SITE_URL fehlt'); ?>
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="small text-uppercase text-secondary mb-1">Aktive Konten</div>
                                <div class="fs-3 fw-bold"><?php echo (int)($stats['active_users'] ?? 0); ?></div>
                                <div class="text-secondary small">von <?php echo (int)($stats['total_users'] ?? 0); ?> Benutzerkonten sind aktiv.</div>
                            </div>

                            <div class="alert alert-secondary" role="alert">
                                Technische Provider-Parameter wie LDAP-, JWT- oder Rate-Limit-Werte werden aktuell aus <code>CMS/config/app.php</code> gelesen und hier bewusst nur angezeigt.
                            </div>

                            <div class="text-secondary small mb-3">Der LDAP-Erstsync nutzt die aktuelle LDAP-Konfiguration und legt fehlende lokale Konten automatisch mit der konfigurierten Standardrolle an.</div>

                            <a href="<?php echo htmlspecialchars($memberDashboardGeneralUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary w-100">Member-Dashboard öffnen</a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="cms-settings-actions cms-settings-actions-bottom">
                <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
            </div>
        </form>
    </div>
</div>

<script <?= \CMS\Security::instance()->nonceAttr() ?>>
document.addEventListener('DOMContentLoaded', function () {
    var testerRoot = document.querySelector('[data-password-policy-tester]');
    if (!testerRoot) {
        return;
    }

    var configRaw = testerRoot.getAttribute('data-password-policy-config') || '{}';
    var config = {};
    try {
        config = JSON.parse(configRaw);
    } catch (error) {
        console.error('Password policy tester config error:', error);
        return;
    }

    var inputEl = testerRoot.querySelector('[data-password-policy-input]');
    var statusEl = testerRoot.querySelector('[data-password-policy-status]');
    var lengthBadgeEl = testerRoot.querySelector('[data-password-policy-length-badge]');
    var requirementEls = testerRoot.querySelectorAll('[data-password-policy-requirement]');
    var minLength = Number(config.minLength || 12);
    var patterns = config.patterns || {};

    if (!inputEl || !statusEl || !lengthBadgeEl || requirementEls.length === 0) {
        return;
    }

    var createPattern = function (source, fallback) {
        try {
            return new RegExp(source || fallback);
        } catch (error) {
            return new RegExp(fallback);
        }
    };

    var countCharacters = function (value) {
        return Array.from(value || '').length;
    };

    var compiledPatterns = {
        uppercase: createPattern(patterns.uppercase, '[A-Z]'),
        lowercase: createPattern(patterns.lowercase, '[a-z]'),
        digit: createPattern(patterns.digit, '\\d'),
        special: createPattern(patterns.special, '[^a-zA-Z0-9]')
    };

    var updateRequirementEl = function (element, passed) {
        element.classList.remove('text-secondary', 'text-success', 'text-danger', 'border-success', 'border-danger');
        var iconEl = element.querySelector('[data-password-policy-icon]');

        if (passed === null) {
            element.classList.add('text-secondary');
            if (iconEl) {
                iconEl.textContent = '○';
            }
            return;
        }

        if (passed) {
            element.classList.add('text-success', 'border-success');
            if (iconEl) {
                iconEl.textContent = '✓';
            }
            return;
        }

        element.classList.add('text-danger', 'border-danger');
        if (iconEl) {
            iconEl.textContent = '✕';
        }
    };

    var evaluate = function (password) {
        var characterCount = countCharacters(password);

        return {
            min_length: characterCount >= minLength,
            uppercase: compiledPatterns.uppercase.test(password),
            lowercase: compiledPatterns.lowercase.test(password),
            digit: compiledPatterns.digit.test(password),
            special: compiledPatterns.special.test(password)
        };
    };

    var updateStatus = function () {
        var password = inputEl.value || '';
        var length = countCharacters(password);
        lengthBadgeEl.textContent = length + ' Zeichen';

        if (password === '') {
            statusEl.className = 'alert alert-secondary mb-3';
            statusEl.textContent = 'Noch kein Passwort geprüft.';
            requirementEls.forEach(function (element) {
                updateRequirementEl(element, null);
            });
            return;
        }

        var results = evaluate(password);
        var firstFailedMessage = '';

        requirementEls.forEach(function (element) {
            var key = element.getAttribute('data-requirement-key') || '';
            var passed = Object.prototype.hasOwnProperty.call(results, key) ? !!results[key] : false;
            updateRequirementEl(element, passed);

            if (!passed && firstFailedMessage === '') {
                var matchedRequirement = (config.requirements || []).find(function (requirement) {
                    return (requirement.key || '') === key;
                });
                firstFailedMessage = matchedRequirement && matchedRequirement.message
                    ? matchedRequirement.message
                    : 'Die Passwort-Policy ist noch nicht vollständig erfüllt.';
            }
        });

        var isValid = Object.keys(results).every(function (key) {
            return results[key];
        });

        if (isValid) {
            statusEl.className = 'alert alert-success mb-3';
            statusEl.textContent = 'Die aktuelle Passwort-Policy ist erfüllt.';
            return;
        }

        statusEl.className = 'alert alert-warning mb-3';
        statusEl.textContent = firstFailedMessage || 'Die Passwort-Policy ist noch nicht vollständig erfüllt.';
    };

    inputEl.addEventListener('input', updateStatus, { passive: true });
    updateStatus();
});
</script>