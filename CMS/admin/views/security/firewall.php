<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

/** @var array $data */
$d        = $data ?? [];
$rules    = $d['rules'] ?? [];
$stats    = $d['stats'] ?? [];
$settings = $d['settings'] ?? [];
$simulation = $d['simulation'] ?? [];
$recentBlocks = is_array($d['recent_blocks'] ?? null) ? $d['recent_blocks'] : [];
$baseline = is_array($d['baseline'] ?? null) ? $d['baseline'] : [];
$baselineProfiles = is_array($baseline['profiles'] ?? null) ? $baseline['profiles'] : [];
$firewallDiagnostics = is_array($baseline['diagnostics'] ?? null) ? $baseline['diagnostics'] : [];
$typeLabels = [
    'block_ip'      => 'IP blockieren',
    'block_range'   => 'IP-Bereich blockieren',
    'allow_ip'      => 'IP erlauben',
    'block_ua'      => 'User-Agent blockieren',
    'block_country' => 'Land blockieren',
];
$typeBadges = [
    'block_ip'      => 'bg-danger',
    'block_range'   => 'bg-danger',
    'allow_ip'      => 'bg-success',
    'block_ua'      => 'bg-warning',
    'block_country' => 'bg-orange',
];
$modeLabels = [
    'simulate' => 'Simulation',
    'enforce' => 'Scharf',
];
$modeBadges = [
    'simulate' => 'bg-warning text-dark',
    'enforce' => 'bg-danger',
];
$simulationWindowHours = (int)($simulation['window_hours'] ?? 24);
$recentSimulationHits = is_array($simulation['recent_hits'] ?? null) ? $simulation['recent_hits'] : [];
$showRuleCreateSimulatedSuccess = isset($alert['type'], $alert['message'])
    && (string)$alert['type'] === 'success'
    && stripos((string)$alert['message'], 'simulationsmodus') !== false;
?>

<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">Sicherheit</div>
                <h2 class="page-title">Firewall</h2>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page">
    <div class="container-xl admin-redesign-shell">

        <?php
        $alertData = $alert ?? [];
        $alertDismissible = true;
        $alertMarginClass = 'mb-3';
        require __DIR__ . '/../partials/flash-alert.php';
        ?>

        <div class="card admin-redesign-toolbar-card">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div class="admin-redesign-info-box flex-fill">
                    <p class="admin-redesign-info-box__title">Rollout-Logik</p>
                    <p class="admin-redesign-info-box__text">Neue Regeln erst simulieren, Treffer prüfen, danach gezielt scharfschalten und im Runtime-Log kontrollieren.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="/admin/antispam" class="btn btn-outline-secondary btn-sm">AntiSpam</a>
                    <a href="/admin/security-audit" class="btn btn-outline-primary btn-sm">Audit</a>
                </div>
            </div>
        </div>

        <div class="security-page-intro">
            <div class="security-page-intro__text">
                <p class="security-page-intro__title">Regeln kontrolliert ausrollen und nachvollziehbar scharfschalten</p>
                <p class="security-page-intro__copy">Die Firewall kombiniert klassische Blockregeln, Rate-Limit-Schutz, Härtungsprofile und einen Simulationsmodus für sichere Rollouts. Tabellen, Vorschauen und Diagnosewerte sind read-only; Änderungen laufen ausschließlich POST-/CSRF-geschützt.</p>
            </div>
        </div>

        <div class="row row-deck row-cards mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="card security-kpi-card h-100">
                    <div class="card-body">
                        <div class="subheader">Regeln gesamt</div>
                        <div class="h1 mb-0"><?php echo (int)($stats['total'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card security-kpi-card is-success h-100">
                    <div class="card-body">
                        <div class="subheader">Aktive Regeln</div>
                        <div class="h1 mb-0 text-primary"><?php echo (int)($stats['active'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card security-kpi-card is-danger h-100">
                    <div class="card-body">
                        <div class="subheader">Scharfe Regeln</div>
                        <div class="h1 mb-0 text-danger"><?php echo (int)($stats['enforced_rules'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card security-kpi-card is-warning h-100">
                    <div class="card-body">
                        <div class="subheader">Simulationsregeln</div>
                        <div class="h1 mb-0 text-warning"><?php echo (int)($stats['simulated_rules'] ?? 0); ?></div>
                        <div class="security-kpi-note">Allow-Regeln: <?php echo (int)($stats['allowed_ips'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row row-deck row-cards mb-4">
            <div class="col-12">
                <div class="card security-panel-card">
                    <div class="card-header">
                        <div class="card-title-wrap">
                            <p class="card-eyebrow">Härtung</p>
                            <h3 class="card-title">Sicherheitsbaseline & Firewall-Diagnose</h3>
                            <div class="card-title-meta">Profile für Entwicklung, Staging und Produktion mit read-only Diff gegen den aktuellen Zustand. Anwendung ist optional und POST-geschützt.</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 mb-4">
                            <?php foreach ($firewallDiagnostics as $diagnostic): ?>
                                <?php
                                $status = (string)($diagnostic['status'] ?? 'warning');
                                $badgeClass = match ($status) {
                                    'ok' => 'bg-success',
                                    'critical' => 'bg-danger',
                                    default => 'bg-warning text-dark',
                                };
                                ?>
                                <div class="col-md-6 col-xl-4">
                                    <div class="security-mini-stat h-100">
                                        <div class="d-flex justify-content-between gap-2 align-items-start mb-2">
                                            <div class="security-mini-stat__label"><?php echo htmlspecialchars((string)($diagnostic['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                        <div class="text-secondary small"><?php echo htmlspecialchars((string)($diagnostic['detail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (empty($baselineProfiles)): ?>
                            <div class="alert alert-warning mb-0" role="alert">Keine Härtungsprofile verfügbar. Die Firewall-Regeln bleiben davon unberührt.</div>
                        <?php else: ?>
                            <div class="accordion" id="firewallBaselineProfiles">
                                <?php foreach ($baselineProfiles as $profileKey => $profile): ?>
                                    <?php
                                    $profileId = 'baseline-profile-' . preg_replace('/[^a-z0-9_-]/i', '-', (string)$profileKey);
                                    $diffRows = is_array($profile['diff'] ?? null) ? $profile['diff'] : [];
                                    $diffCount = (int)($profile['diff_count'] ?? 0);
                                    ?>
                                    <div class="accordion-item">
                                        <h2 class="accordion-header" id="<?php echo htmlspecialchars($profileId, ENT_QUOTES, 'UTF-8'); ?>-header">
                                            <button class="accordion-button <?php echo $profileKey === 'production' ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo htmlspecialchars($profileId, ENT_QUOTES, 'UTF-8'); ?>" aria-expanded="<?php echo $profileKey === 'production' ? 'true' : 'false'; ?>" aria-controls="<?php echo htmlspecialchars($profileId, ENT_QUOTES, 'UTF-8'); ?>">
                                                <span class="fw-semibold me-2"><?php echo htmlspecialchars((string)($profile['label'] ?? $profileKey), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="badge <?php echo $diffCount === 0 ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo $diffCount === 0 ? 'konform' : $diffCount . ' Abweichungen'; ?></span>
                                            </button>
                                        </h2>
                                        <div id="<?php echo htmlspecialchars($profileId, ENT_QUOTES, 'UTF-8'); ?>" class="accordion-collapse collapse <?php echo $profileKey === 'production' ? 'show' : ''; ?>" aria-labelledby="<?php echo htmlspecialchars($profileId, ENT_QUOTES, 'UTF-8'); ?>-header" data-bs-parent="#firewallBaselineProfiles">
                                            <div class="accordion-body">
                                                <p class="text-secondary small mb-3"><?php echo htmlspecialchars((string)($profile['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>

                                                <div class="table-responsive security-table-wrap mb-3">
                                                    <table class="table table-sm table-vcenter security-table-modern mb-0">
                                                        <thead>
                                                            <tr>
                                                                <th>Einstellung</th>
                                                                <th>Aktuell</th>
                                                                <th>Profil</th>
                                                                <th>Status</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($diffRows as $row): ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars((string)($row['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                                    <td><?php echo htmlspecialchars((string)($row['current'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                                    <td><?php echo htmlspecialchars((string)($row['recommended'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                                    <td>
                                                                        <?php if (!empty($row['matches'])): ?>
                                                                            <span class="badge bg-success">OK</span>
                                                                        <?php else: ?>
                                                                            <span class="badge bg-warning text-dark">Abweichend</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>

                                                <form method="post" class="d-flex justify-content-end">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="apply_baseline_profile">
                                                    <input type="hidden" name="baseline_profile" value="<?php echo htmlspecialchars((string)$profileKey, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button type="button" class="btn btn-outline-primary btn-sm" data-cms-call="applyFirewallBaseline" data-cms-call-context="form" data-cms-call-args="<?php echo htmlspecialchars((string) json_encode([(string)($profile['label'] ?? $profileKey)], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">Profil anwenden</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row row-deck row-cards mb-4">
            <div class="col-lg-6">
                <div class="card security-panel-card h-100">
                    <div class="card-header">
                        <div class="card-title-wrap">
                            <p class="card-eyebrow">Runtime</p>
                            <h3 class="card-title">Firewall-Einstellungen</h3>
                            <div class="card-title-meta">Globale Limits, Logging und das Vorschaufenster für Simulationsregeln.</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="save_settings">

                            <label class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="firewall_enabled" value="1" <?php echo ($settings['firewall_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <span class="form-check-label">Firewall aktiv</span>
                            </label>

                            <div class="mb-3">
                                <label class="form-label">Rate Limit (Anfragen)</label>
                                <input type="number" name="firewall_rate_limit" class="form-control" min="10" max="1000" value="<?php echo (int)($settings['firewall_rate_limit'] ?? 60); ?>" data-firewall-rate-limit>
                                <small class="form-hint">Max. Anfragen pro Zeitfenster</small>
                                <div class="firewall-seconds-hint" data-firewall-rate-limit-hint></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Zeitfenster (Sekunden)</label>
                                <input type="number" name="firewall_rate_window" class="form-control" min="60" max="3600" value="<?php echo (int)($settings['firewall_rate_window'] ?? 60); ?>" data-firewall-seconds>
                                <div class="firewall-seconds-hint" data-firewall-seconds-hint-for="firewall_rate_window"></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Sperrdauer (Sekunden)</label>
                                <input type="number" name="firewall_block_duration" class="form-control" min="60" max="86400" value="<?php echo (int)($settings['firewall_block_duration'] ?? 3600); ?>" data-firewall-seconds>
                                <div class="firewall-seconds-hint" data-firewall-seconds-hint-for="firewall_block_duration"></div>
                            </div>

                            <label class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="firewall_log_enabled" value="1" <?php echo ($settings['firewall_log_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <span class="form-check-label">Zugriffs-Logging aktiv</span>
                            </label>

                            <div class="mb-3">
                                <label class="form-label">Vorschaufenster für Simulation (Stunden)</label>
                                <input type="number" name="firewall_simulation_preview_hours" class="form-control" min="1" max="168" value="<?php echo (int)($settings['firewall_simulation_preview_hours'] ?? 24); ?>">
                                <small class="form-hint">Read-only Auswertung der simulierten Treffer vor dem Scharfschalten.</small>
                            </div>

                            <div class="alert alert-warning" role="alert">
                                Neue Block-Regeln können zuerst im <strong>Simulationsmodus</strong> laufen. Treffer werden dann protokolliert, aber nicht geblockt.
                            </div>

                            <button type="submit" class="btn btn-primary w-100">Einstellungen speichern</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card security-panel-card h-100">
                    <div class="card-header">
                        <div class="card-title-wrap">
                            <p class="card-eyebrow">Regelpflege</p>
                            <h3 class="card-title">Neue Regel hinzufügen</h3>
                            <div class="card-title-meta">Block- und Allow-Regeln mit sauber getrenntem Modus für Simulation oder Enforce.</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="firewall-create-rule-card">
                            <form method="post" id="firewallRuleCreateForm">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="add_rule">
                                <input type="hidden" name="expires_at" value="" data-firewall-expires-at>
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Typ</label>
                                        <select name="rule_type" class="form-select">
                                            <?php foreach ($typeLabels as $value => $label): ?>
                                                <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Wert (IP / Pattern)</label>
                                        <input type="text" name="rule_value" class="form-control" required placeholder="192.168.1.1">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Modus</label>
                                        <select name="rule_mode" class="form-select">
                                            <option value="simulate" selected>Simulation</option>
                                            <option value="enforce">Scharf</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Grund</label>
                                        <input type="text" name="rule_reason" class="form-control" placeholder="Optional">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Ablauf</label>
                                        <select class="form-select" data-firewall-expiration-preset>
                                            <option value="0" selected>Kein Ablauf</option>
                                            <option value="3600">1 Stunde</option>
                                            <option value="14400">4 Stunden</option>
                                            <option value="28800">8 Stunden</option>
                                            <option value="86400">24 Stunden</option>
                                            <option value="172800">48 Stunden</option>
                                            <option value="259200">3 Tage</option>
                                            <option value="604800">1 Woche</option>
                                            <option value="1209600">2 Wochen</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mt-3 d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                    <div class="firewall-create-success<?php echo $showRuleCreateSimulatedSuccess ? ' is-visible' : ''; ?>" data-firewall-rule-success>
                                        Regel wurde im Simulationsmodus angelegt.
                                    </div>
                                    <button type="submit" class="btn btn-primary">Regel anlegen</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php
        $proxy = is_array($d['proxy'] ?? null) ? $d['proxy'] : [];
        $proxyCandidates = is_array($proxy['candidates'] ?? null) ? $proxy['candidates'] : [];
        $proxyRequestHeaders = is_array($proxy['request_headers'] ?? null) ? $proxy['request_headers'] : [];
        $proxySourceLabels = [
            'constant' => 'Konstante CMS_TRUSTED_PROXIES (config/app.php)',
            'option' => 'Admin-Einstellung',
            'default' => 'Standard (auto)',
        ];
        $proxyLocked = !empty($proxy['constant_locked']);
        ?>
        <div class="row row-cards mb-4" id="proxy-cdn">
            <div class="col-12">
                <div class="card security-panel-card">
                    <div class="card-header">
                        <div class="card-title-wrap">
                            <p class="card-eyebrow">Client-IP</p>
                            <h3 class="card-title">Proxy / CDN & Client-IP</h3>
                            <div class="card-title-meta">Hinter einem CDN oder Reverse Proxy (Cloudflare, Hoster-CDN wie Hostinger, Load Balancer) sieht der Server nur die Proxy-Adresse. Ohne vertrauenswürdigen Proxy teilen sich Besucher Rate-Limits und Sperren.</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <div class="col-lg-5">
                                <dl class="row mb-3 small">
                                    <dt class="col-sm-5">Quelle</dt>
                                    <dd class="col-sm-7"><?php echo htmlspecialchars($proxySourceLabels[(string)($proxy['source'] ?? 'default')] ?? 'Standard', ENT_QUOTES, 'UTF-8'); ?></dd>
                                    <dt class="col-sm-5">Wirksam</dt>
                                    <dd class="col-sm-7"><code><?php echo htmlspecialchars((string)($proxy['spec'] ?? 'auto'), ENT_QUOTES, 'UTF-8'); ?></code></dd>
                                    <dt class="col-sm-5">REMOTE_ADDR (diese Anfrage)</dt>
                                    <dd class="col-sm-7"><code><?php echo htmlspecialchars((string)($proxy['remote_addr'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></dd>
                                    <dt class="col-sm-5">Ermittelte Client-IP</dt>
                                    <dd class="col-sm-7"><code><?php echo htmlspecialchars((string)($proxy['client_ip'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code>
                                        <?php if (!empty($proxy['via_proxy'])): ?>
                                            <span class="badge bg-success-lt">über <?php echo htmlspecialchars((string)($proxy['header'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                    </dd>
                                    <dt class="col-sm-5">Weiterleitungs-Header</dt>
                                    <dd class="col-sm-7">
                                        <?php if ($proxyRequestHeaders === []): ?>
                                            <span class="text-secondary">keine</span>
                                        <?php else: ?>
                                            <?php foreach ($proxyRequestHeaders as $headerName => $headerValue): ?>
                                                <div><code><?php echo htmlspecialchars((string)$headerName, ENT_QUOTES, 'UTF-8'); ?>: <?php echo htmlspecialchars((string)$headerValue, ENT_QUOTES, 'UTF-8'); ?></code></div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </dd>
                                </dl>

                                <?php if (!empty($proxy['trust_any'])): ?>
                                    <div class="alert alert-warning" role="alert">
                                        <strong>*</strong> vertraut jedem direkten Gegenüber. Das ist nur sicher, wenn der Server ausschließlich über das CDN erreichbar ist – sonst können Angreifer ihre IP per Header frei wählen und Rate-Limits umgehen.
                                    </div>
                                <?php endif; ?>

                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="save_proxy_settings">
                                    <div class="mb-3">
                                        <label class="form-label" for="firewallTrustedProxies">Vertrauenswürdige Proxys / CDN-Netze</label>
                                        <input type="text" id="firewallTrustedProxies" name="trusted_proxies" class="form-control" maxlength="4000" placeholder="auto" value="<?php echo htmlspecialchars((string)($proxyLocked ? ($proxy['spec'] ?? '') : ($proxy['stored'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" aria-describedby="firewallTrustedProxiesHint" <?php echo $proxyLocked ? 'disabled' : ''; ?>>
                                        <small class="form-hint" id="firewallTrustedProxiesHint">
                                            Kommagetrennt: IPs, CIDR-Bereiche sowie <code>auto</code> (private Netze + Cloudflare, Standard), <code>private</code>, <code>cloudflare</code>, <code>*</code> (allen direkten Gegenstellen vertrauen) und <code>off</code> (nur REMOTE_ADDR).
                                            <?php if ($proxyLocked): ?><br>Gesperrt: Die Konstante <code>CMS_TRUSTED_PROXIES</code> in <code>config/app.php</code> hat Vorrang.<?php endif; ?>
                                        </small>
                                    </div>
                                    <button type="submit" class="btn btn-primary" <?php echo $proxyLocked ? 'disabled' : ''; ?>>Proxy-Einstellung speichern</button>
                                </form>
                            </div>
                            <div class="col-lg-7">
                                <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                    <h4 class="mb-0">Erkannte, nicht vertrauenswürdige Proxys</h4>
                                    <?php if ($proxyCandidates !== []): ?>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="clear_proxy_candidates">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Liste leeren</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <p class="text-secondary small">Anfragen mit Weiterleitungs-Headern von unbekannten öffentlichen Adressen werden hier gesammelt (Netz /24 bzw. /48, max. 14 Tage, keine Besucher-IPs). Ein Hoster-CDN zeigt viele Beobachtungen und viele verschiedene Clients; einzelne Firmen-Proxys oder gefälschte Header nur wenige. Nur Netze übernehmen, die nachweislich zum CDN/Hoster gehören (z. B. per WHOIS prüfen).</p>
                                <?php if ($proxyCandidates === []): ?>
                                    <div class="text-secondary">Keine unbekannten Proxys erkannt.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-vcenter card-table table-sm">
                                            <thead>
                                                <tr>
                                                    <th scope="col">Netz</th>
                                                    <th scope="col">Header</th>
                                                    <th scope="col" class="text-end">Beobachtungen</th>
                                                    <th scope="col" class="text-end">Clients</th>
                                                    <th scope="col">Zuletzt</th>
                                                    <th scope="col"><span class="visually-hidden">Aktionen</span></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($proxyCandidates as $candidate): ?>
                                                    <?php $candidateNetwork = (string)($candidate['network'] ?? ''); ?>
                                                    <tr>
                                                        <td><code><?php echo htmlspecialchars($candidateNetwork, ENT_QUOTES, 'UTF-8'); ?></code><?php if (!empty($candidate['trusted'])): ?> <span class="badge bg-success-lt">vertraut</span><?php endif; ?></td>
                                                        <td class="small"><?php echo htmlspecialchars(implode(', ', (array)($candidate['headers'] ?? [])), ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td class="text-end"><?php echo (int)($candidate['observations'] ?? 0); ?></td>
                                                        <td class="text-end"><?php $distinctClients = (int)($candidate['distinct_clients'] ?? 0); echo $distinctClients >= 25 ? '25+' : $distinctClients; ?></td>
                                                        <td class="small"><?php echo htmlspecialchars(date('d.m.Y H:i', (int)($candidate['last_seen'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td class="text-end">
                                                            <div class="d-flex justify-content-end gap-1">
                                                                <?php if (!$proxyLocked && empty($candidate['trusted'])): ?>
                                                                    <form method="post">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                                        <input type="hidden" name="action" value="trust_proxy_candidate">
                                                                        <input type="hidden" name="network" value="<?php echo htmlspecialchars($candidateNetwork, ENT_QUOTES, 'UTF-8'); ?>">
                                                                        <button type="submit" class="btn btn-sm btn-success">Vertrauen</button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                <form method="post">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                                    <input type="hidden" name="action" value="dismiss_proxy_candidate">
                                                                    <input type="hidden" name="network" value="<?php echo htmlspecialchars($candidateNetwork, ENT_QUOTES, 'UTF-8'); ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Verwerfen</button>
                                                                </form>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row row-deck row-cards mb-4">
            <div class="col-lg-6">
                <div class="card security-panel-card h-100">
                    <div class="card-header">
                        <div class="card-title-wrap">
                            <p class="card-eyebrow">Vorschau</p>
                            <h3 class="card-title mb-0">Treffervorschau für Simulationsregeln</h3>
                            <div class="card-title-meta">Read-only Auswertung der letzten <?php echo $simulationWindowHours; ?> Stunden.</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (($simulation['available'] ?? true) !== true): ?>
                            <div class="alert alert-warning mb-0" role="alert">
                                Die Simulationsvorschau konnte gerade nicht geladen werden. Die Firewall-Regeln bleiben davon unberührt.
                            </div>
                        <?php else: ?>
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <div class="security-mini-stat">
                                        <div class="security-mini-stat__label">Treffer gesamt</div>
                                        <div class="security-mini-stat__value"><?php echo (int)($simulation['total_hits'] ?? 0); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="security-mini-stat">
                                        <div class="security-mini-stat__label">Regeln mit Treffern</div>
                                        <div class="security-mini-stat__value"><?php echo (int)($simulation['rules_with_hits'] ?? 0); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="security-mini-stat">
                                        <div class="security-mini-stat__label">Simulationsregeln aktiv</div>
                                        <div class="security-mini-stat__value"><?php echo (int)($stats['simulated_rules'] ?? 0); ?></div>
                                    </div>
                                </div>
                            </div>

                            <?php if (empty($recentSimulationHits)): ?>
                                <div class="text-secondary">Im gewählten Vorschaufenster wurden noch keine simulierten Treffer protokolliert.</div>
                            <?php else: ?>
                                <div class="table-responsive security-table-wrap">
                                    <table class="table table-sm table-vcenter security-table-modern">
                                        <thead>
                                            <tr>
                                                <th>Zeitpunkt</th>
                                                <th>Regel</th>
                                                <th>IP</th>
                                                <th>Pfad</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentSimulationHits as $hit): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars((string)($hit['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td>
                                                        <div><?php echo htmlspecialchars((string)($hit['rule_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                        <div class="text-secondary small"><code><?php echo htmlspecialchars((string)($hit['rule_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></div>
                                                    </td>
                                                    <td><code><?php echo htmlspecialchars((string)($hit['ip_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                                                    <td><code><?php echo htmlspecialchars((string)($hit['request_uri'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card security-panel-card h-100">
                    <div class="card-header">
                        <div class="card-title-wrap">
                            <p class="card-eyebrow">Runtime-Log</p>
                            <h3 class="card-title">Letzte geblockte Requests</h3>
                            <div class="card-title-meta">Geblockte oder rate-limitierte Requests als schnelle Betriebsübersicht.</div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recentBlocks)): ?>
                            <div class="p-3 text-secondary">Noch keine geblockten oder rate-limitierten Requests protokolliert.</div>
                        <?php else: ?>
                            <div class="table-responsive security-table-wrap">
                                <table class="table table-sm table-vcenter mb-0 security-table-modern">
                                    <thead>
                                        <tr>
                                            <th>Zeitpunkt</th>
                                            <th>Aktion</th>
                                            <th>IP</th>
                                            <th>Regel</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentBlocks as $entry): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)($entry['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><span class="badge <?php echo (($entry['action'] ?? '') === 'rate_limited') ? 'bg-warning text-dark' : 'bg-danger'; ?>"><?php echo htmlspecialchars((string)($entry['action'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                                <td><code><?php echo htmlspecialchars((string)($entry['ip_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                                                <td><code><?php echo htmlspecialchars((string)($entry['rule_matched'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row row-deck row-cards mb-4">
            <div class="col-12">
                <div class="card security-panel-card">
                    <div class="card-header">
                        <div class="card-title-wrap">
                            <p class="card-eyebrow">Regelbestand</p>
                            <h3 class="card-title">Aktive Regeln</h3>
                            <div class="card-title-meta">Modus, Treffer, Ablauf und direkte Aktionen pro Firewall-Regel.</div>
                        </div>
                    </div>
                    <div class="table-responsive security-table-wrap">
                        <table class="table table-vcenter card-table security-table-modern">
                            <thead>
                                <tr>
                                    <th>Typ</th>
                                    <th>Wert</th>
                                    <th>Modus</th>
                                    <th>Treffer (<?php echo $simulationWindowHours; ?>h)</th>
                                    <th>Grund</th>
                                    <th>Ablauf</th>
                                    <th>Status</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rules)): ?>
                                    <?php
                                    $emptyStateColspan = 8;
                                    $emptyStateMessage = 'Keine Regeln vorhanden.';
                                    $emptyStateSubtitle = 'Legen Sie eine neue Firewall-Regel an oder aktivieren Sie vorhandene Schutzpfade.';
                                    $emptyStateIcon = 'shield';
                                    require __DIR__ . '/../partials/empty-table-row.php';
                                    ?>
                                <?php else: ?>
                                    <?php foreach ($rules as $rule): ?>
                                        <tr>
                                            <td><span class="badge <?php echo $typeBadges[$rule['rule_type']] ?? 'bg-secondary'; ?>"><?php echo htmlspecialchars($typeLabels[$rule['rule_type']] ?? $rule['rule_type'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <td><code><?php echo htmlspecialchars((string)$rule['value'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                            <td>
                                                <span class="badge <?php echo $modeBadges[$rule['rule_mode']] ?? 'bg-secondary'; ?>"><?php echo htmlspecialchars($modeLabels[$rule['rule_mode']] ?? $rule['rule_mode'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php if (($rule['rule_type'] ?? '') === 'allow_ip'): ?>
                                                    <div class="text-secondary small mt-1">Allow-Regeln wirken immer direkt.</div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (($rule['rule_mode'] ?? '') === 'simulate'): ?>
                                                    <div class="fw-semibold"><?php echo (int)($rule['simulation_hits'] ?? 0); ?></div>
                                                    <div class="text-secondary small">
                                                        <?php if (!empty($rule['simulation_last_hit'])): ?>
                                                            Letzter Treffer: <?php echo htmlspecialchars((string)$rule['simulation_last_hit'], ENT_QUOTES, 'UTF-8'); ?>
                                                        <?php else: ?>
                                                            Noch keine Treffer
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-secondary">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars((string)($rule['reason'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <?php if (!empty($rule['expires_at'])): ?>
                                                    <?php echo htmlspecialchars((string)$rule['expires_at'], ENT_QUOTES, 'UTF-8'); ?>
                                                <?php else: ?>
                                                    <span class="text-secondary">Permanent</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ((int)$rule['is_active']): ?>
                                                    <span class="badge bg-success">Aktiv</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inaktiv</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="table-actions">
                                                <div class="security-table-actions">
                                                    <?php if (($rule['rule_type'] ?? '') !== 'allow_ip' && ($rule['reason'] ?? '') !== 'Automatisches Rate-Limit'): ?>
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input type="hidden" name="action" value="set_rule_mode">
                                                            <input type="hidden" name="id" value="<?php echo (int)$rule['id']; ?>">
                                                            <input type="hidden" name="target_mode" value="<?php echo ($rule['rule_mode'] ?? '') === 'simulate' ? 'enforce' : 'simulate'; ?>">
                                                            <?php if (($rule['rule_mode'] ?? '') === 'simulate'): ?>
                                                                <button type="button" class="btn btn-warning btn-sm" data-cms-call="changeFirewallRuleMode" data-cms-call-context="form" data-cms-call-args='["enforce"]'>Scharfschalten</button>
                                                            <?php else: ?>
                                                                <button type="button" class="btn btn-ghost-secondary btn-sm" data-cms-call="changeFirewallRuleMode" data-cms-call-context="form" data-cms-call-args='["simulate"]'>Nur simulieren</button>
                                                            <?php endif; ?>
                                                        </form>
                                                    <?php endif; ?>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="action" value="toggle_rule">
                                                        <input type="hidden" name="id" value="<?php echo (int)$rule['id']; ?>">
                                                        <button type="submit" class="btn btn-ghost-secondary btn-sm"><?php echo (int)$rule['is_active'] ? 'Deaktivieren' : 'Aktivieren'; ?></button>
                                                    </form>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="action" value="delete_rule">
                                                        <input type="hidden" name="id" value="<?php echo (int)$rule['id']; ?>">
                                                        <button type="button" class="btn btn-ghost-danger btn-sm" data-cms-call="deleteFirewallRule" data-cms-call-context="form">Löschen</button>
                                                    </form>
                                                </div>
                                            </td>
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

<script <?= \CMS\Security::instance()->nonceAttr() ?>>
function submitWithFallback(form) {
    if (window.cmsSubmitFormSafely) {
        window.cmsSubmitFormSafely(form);
        return;
    }

    if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
        return;
    }

    var fallbackSubmitter = document.createElement('button');
    fallbackSubmitter.type = 'submit';
    fallbackSubmitter.hidden = true;
    form.appendChild(fallbackSubmitter);
    fallbackSubmitter.click();
    fallbackSubmitter.remove();
}

function deleteFirewallRule(form) {
    cmsConfirm({
        title: 'Firewall-Regel löschen',
        message: 'Diese Firewall-Regel wirklich löschen?',
        confirmText: 'Löschen',
        confirmClass: 'btn-danger',
        onConfirm: function() {
            submitWithFallback(form);
        }
    });
}

function changeFirewallRuleMode(form, targetMode) {
    if (targetMode !== 'enforce') {
        submitWithFallback(form);
        return;
    }

    cmsConfirm({
        title: 'Firewall-Regel scharfschalten',
        message: 'Diese Regel soll Treffer künftig aktiv blockieren. Wirklich scharfschalten?',
        confirmText: 'Scharfschalten',
        confirmClass: 'btn-danger',
        onConfirm: function() {
            submitWithFallback(form);
        }
    });
}

function applyFirewallBaseline(form, profileLabel) {
    cmsConfirm({
        title: 'Härtungsprofil anwenden',
        message: 'Das Profil „' + profileLabel + '“ überschreibt die Firewall-Basiseinstellungen. Regeln werden nicht gelöscht oder neu angelegt. Fortfahren?',
        confirmText: 'Profil anwenden',
        confirmClass: 'btn-primary',
        onConfirm: function() {
            submitWithFallback(form);
        }
    });
}

(function initFirewallUiHelpers() {
    var formatSeconds = typeof window.formatSeconds === 'function'
        ? window.formatSeconds
        : function fallbackFormatSeconds(value) {
            var numericValue = Number(value);
            if (!Number.isFinite(numericValue)) {
                numericValue = 0;
            }

            var seconds = Math.max(0, Math.floor(numericValue));
            if (seconds >= 86400) {
                return String(seconds / 86400) + ' Tage';
            }
            if (seconds >= 3600) {
                return String(seconds / 3600) + ' Stunden';
            }
            if (seconds >= 60) {
                return String(seconds / 60) + ' Minuten';
            }
            return String(seconds) + ' Sekunden';
        };

    var rateWindowInput = document.querySelector('input[name="firewall_rate_window"]');
    var rateLimitInput = document.querySelector('input[name="firewall_rate_limit"]');
    var rateLimitHint = document.querySelector('[data-firewall-rate-limit-hint]');

    document.querySelectorAll('input[data-firewall-seconds]').forEach(function(input) {
        var hint = document.querySelector('[data-firewall-seconds-hint-for="' + input.name + '"]');
        if (!hint) {
            return;
        }

        var refreshSecondsHint = function() {
            var secondsValue = Number.parseInt(input.value, 10) || 0;
            hint.textContent = '= ' + formatSeconds(secondsValue);
        };

        input.addEventListener('input', refreshSecondsHint);
        input.addEventListener('change', refreshSecondsHint);
        refreshSecondsHint();
    });

    var refreshRateLimitHint = function() {
        if (!rateLimitHint || !rateLimitInput || !rateWindowInput) {
            return;
        }

        var requests = Number.parseInt(rateLimitInput.value, 10) || 0;
        var seconds = Number.parseInt(rateWindowInput.value, 10) || 0;
        rateLimitHint.textContent = '= ' + requests + ' Anfragen je ' + formatSeconds(seconds);
    };

    if (rateLimitInput && rateWindowInput && rateLimitHint) {
        rateLimitInput.addEventListener('input', refreshRateLimitHint);
        rateLimitInput.addEventListener('change', refreshRateLimitHint);
        rateWindowInput.addEventListener('input', refreshRateLimitHint);
        rateWindowInput.addEventListener('change', refreshRateLimitHint);
        refreshRateLimitHint();
    }

    var createRuleForm = document.getElementById('firewallRuleCreateForm');
    var expirationPreset = createRuleForm ? createRuleForm.querySelector('[data-firewall-expiration-preset]') : null;
    var expiresAtInput = createRuleForm ? createRuleForm.querySelector('input[data-firewall-expires-at]') : null;
    var successMessage = document.querySelector('[data-firewall-rule-success]');

    if (createRuleForm && expirationPreset && expiresAtInput) {
        createRuleForm.addEventListener('submit', function() {
            var selectedSeconds = Number.parseInt(expirationPreset.value, 10) || 0;
            if (selectedSeconds <= 0) {
                expiresAtInput.value = '';
                return;
            }

            var expirationDate = new Date(Date.now() + (selectedSeconds * 1000));
            var year = expirationDate.getFullYear();
            var month = String(expirationDate.getMonth() + 1).padStart(2, '0');
            var day = String(expirationDate.getDate()).padStart(2, '0');
            var hours = String(expirationDate.getHours()).padStart(2, '0');
            var minutes = String(expirationDate.getMinutes()).padStart(2, '0');
            expiresAtInput.value = year + '-' + month + '-' + day + 'T' + hours + ':' + minutes;
        });
    }

    if (successMessage && successMessage.classList.contains('is-visible')) {
        window.setTimeout(function() {
            successMessage.classList.add('is-fading');
        }, 3000);
        window.setTimeout(function() {
            successMessage.classList.remove('is-visible');
            successMessage.classList.remove('is-fading');
        }, 3400);
    }
})();
</script>
