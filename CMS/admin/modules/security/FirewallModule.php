<?php
declare(strict_types=1);

/**
 * FirewallModule – IP-Blocking, Rate-Limiting, Regelverwaltung
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Logger;
use CMS\Services\TrustedProxyService;

class FirewallModule
{
    private const RULE_MODE_ENFORCE = 'enforce';
    private const RULE_MODE_SIMULATE = 'simulate';
    private const DEFAULT_SIMULATION_PREVIEW_HOURS = 24;

    private const SETTING_KEYS = [
        'firewall_enabled',
        'firewall_rate_limit',
        'firewall_rate_window',
        'firewall_block_duration',
        'firewall_log_enabled',
        'firewall_simulation_preview_hours',
    ];

    private const SUPPORTED_ACTIONS = [
        'save_settings',
        'apply_baseline_profile',
        'add_rule',
        'delete_rule',
        'toggle_rule',
        'set_rule_mode',
        'save_proxy_settings',
        'trust_proxy_candidate',
        'dismiss_proxy_candidate',
        'clear_proxy_candidates',
    ];

    private const BASELINE_PROFILES = [
        'development' => [
            'label' => 'Entwicklung',
            'description' => 'Lockerere Rate-Limits, aktives Logging und kurze Sperren für lokale Tests ohne harte Blockade-Spiralen.',
            'settings' => [
                'firewall_enabled' => '1',
                'firewall_rate_limit' => '300',
                'firewall_rate_window' => '60',
                'firewall_block_duration' => '300',
                'firewall_log_enabled' => '1',
                'firewall_simulation_preview_hours' => '12',
            ],
        ],
        'staging' => [
            'label' => 'Staging',
            'description' => 'Produktionsnahes Verhalten mit moderaten Limits, aktivem Logging und ausreichend langer Simulationsauswertung.',
            'settings' => [
                'firewall_enabled' => '1',
                'firewall_rate_limit' => '120',
                'firewall_rate_window' => '60',
                'firewall_block_duration' => '1800',
                'firewall_log_enabled' => '1',
                'firewall_simulation_preview_hours' => '24',
            ],
        ],
        'production' => [
            'label' => 'Produktion',
            'description' => 'Strengeres Betriebsprofil mit aktiver Firewall, Logging, längerer Sperrdauer und erweitertem Simulationsfenster.',
            'settings' => [
                'firewall_enabled' => '1',
                'firewall_rate_limit' => '60',
                'firewall_rate_window' => '60',
                'firewall_block_duration' => '3600',
                'firewall_log_enabled' => '1',
                'firewall_simulation_preview_hours' => '48',
            ],
        ],
    ];

    private const BASELINE_SETTING_LABELS = [
        'firewall_enabled' => 'Firewall aktiv',
        'firewall_rate_limit' => 'Rate Limit',
        'firewall_rate_window' => 'Zeitfenster',
        'firewall_block_duration' => 'Sperrdauer',
        'firewall_log_enabled' => 'Zugriffs-Logging',
        'firewall_simulation_preview_hours' => 'Simulationsvorschau',
    ];

    private readonly \CMS\Database $db;
    private readonly string $prefix;

    public function __construct()
    {
        $this->db     = \CMS\Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->ensureTable();
    }

    public function isSupportedAction(string $action): bool
    {
        return in_array(trim($action), self::SUPPORTED_ACTIONS, true);
    }

    private function ensureTable(): void
    {
        $this->db->getPdo()->exec(
            "CREATE TABLE IF NOT EXISTS {$this->prefix}firewall_rules (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rule_type   VARCHAR(20) NOT NULL DEFAULT 'block_ip',
                rule_mode   VARCHAR(20) NOT NULL DEFAULT 'enforce',
                value       VARCHAR(255) NOT NULL,
                reason      VARCHAR(255) DEFAULT NULL,
                is_active   TINYINT(1) NOT NULL DEFAULT 1,
                expires_at  DATETIME DEFAULT NULL,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_type  (rule_type),
                INDEX idx_mode  (rule_mode),
                INDEX idx_value (value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->ensureColumn(
            $this->prefix . 'firewall_rules',
            'rule_mode',
            "ALTER TABLE {$this->prefix}firewall_rules ADD COLUMN rule_mode VARCHAR(20) NOT NULL DEFAULT 'enforce' AFTER rule_type"
        );
    }

    public function getData(): array
    {
        $rules = $this->db->get_results(
            "SELECT * FROM {$this->prefix}firewall_rules ORDER BY created_at DESC"
        ) ?: [];
        $normalizedRules = array_map(fn($r) => $this->normalizeRuleRow($r), $rules);
        $settings = $this->loadSettings();
        $previewHours = max(1, min(168, (int)($settings['firewall_simulation_preview_hours'] ?? self::DEFAULT_SIMULATION_PREVIEW_HOURS)));
        $simulationPreview = $this->buildSimulationPreview($normalizedRules, $previewHours);

        foreach ($normalizedRules as &$rule) {
            $preview = $simulationPreview['rule_hits'][$rule['rule_match_key']] ?? null;
            $rule['simulation_hits'] = (int)($preview['hits'] ?? 0);
            $rule['simulation_unique_ips'] = (int)($preview['unique_ips'] ?? 0);
            $rule['simulation_last_hit'] = (string)($preview['last_hit'] ?? '');
        }
        unset($rule);

        // Stats
        $total   = count($normalizedRules);
        $active  = 0;
        $enforced = 0;
        $simulated = 0;
        $allowed = 0;
        foreach ($normalizedRules as $r) {
            if ((int)$r['is_active']) {
                $active++;
            }
            if ($r['rule_type'] === 'allow_ip') {
                $allowed++;
                continue;
            }
            if ($r['rule_mode'] === self::RULE_MODE_SIMULATE) {
                $simulated++;
            } else {
                $enforced++;
            }
        }

        // Letzte blockierte Zugriffe (aus Logs, wenn vorhanden)
        $recentBlocks = [];
        try {
            $recentBlocks = $this->db->get_results(
                "SELECT * FROM {$this->prefix}security_log WHERE action IN ('blocked', 'rate_limited') ORDER BY created_at DESC LIMIT 10"
            ) ?: [];
        } catch (\Exception $e) {}

        return [
            'rules'         => $normalizedRules,
            'stats'         => [
                'total' => $total,
                'active' => $active,
                'enforced_rules' => $enforced,
                'simulated_rules' => $simulated,
                'allowed_ips' => $allowed,
            ],
            'settings'      => $settings,
            'recent_blocks' => array_map(fn($r) => (array)$r, $recentBlocks),
            'simulation'    => $simulationPreview,
            'baseline'      => $this->buildBaselineData($settings, $normalizedRules, $simulationPreview, $recentBlocks),
            'proxy'         => $this->buildProxyData(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildProxyData(): array
    {
        TrustedProxyService::reset();
        $config = TrustedProxyService::config();
        $resolved = TrustedProxyService::resolve();
        $stored = (string)($this->db->get_var(
            "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1",
            [TrustedProxyService::OPTION_KEY]
        ) ?? '');

        return [
            'spec' => (string)$config['spec'],
            'source' => (string)$config['source'],
            'stored' => $stored,
            'constant_locked' => defined('CMS_TRUSTED_PROXIES'),
            'trust_any' => (bool)$config['any'],
            'remote_addr' => (string)$resolved['remote'],
            'client_ip' => (string)$resolved['ip'],
            'via_proxy' => (bool)$resolved['via_proxy'],
            'header' => (string)$resolved['header'],
            'request_headers' => TrustedProxyService::currentForwardHeaders(),
            'candidates' => TrustedProxyService::getCandidates(),
        ];
    }

    public function saveProxySettings(array $post): array
    {
        if (defined('CMS_TRUSTED_PROXIES')) {
            return ['success' => false, 'error' => 'CMS_TRUSTED_PROXIES ist in config/app.php gesetzt und hat Vorrang. Bitte dort ändern.'];
        }

        $spec = TrustedProxyService::normalizeSpec((string)($post['trusted_proxies'] ?? ''));
        if ($spec === null) {
            return ['success' => false, 'error' => 'Ungültiger Eintrag. Erlaubt sind IPs, CIDR-Bereiche (IPv4 /8–/32, IPv6 /16–/128) sowie auto, private, cloudflare, * und off.'];
        }

        return $this->storeProxySpec($spec, 'firewall.proxy.save', 'Proxy-/CDN-Einstellung gespeichert');
    }

    public function trustProxyCandidate(string $network): array
    {
        if (defined('CMS_TRUSTED_PROXIES')) {
            return ['success' => false, 'error' => 'CMS_TRUSTED_PROXIES ist in config/app.php gesetzt. Netz dort ergänzen: ' . $this->sanitizeText($network, 64)];
        }

        $network = trim($network);
        $known = array_column(TrustedProxyService::getCandidates(), 'network');
        if (!in_array($network, $known, true) || !TrustedProxyService::isValidRange($network)) {
            return ['success' => false, 'error' => 'Unbekanntes oder ungültiges Proxy-Netz.'];
        }

        $current = trim((string)($this->db->get_var(
            "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1",
            [TrustedProxyService::OPTION_KEY]
        ) ?? ''));
        if ($current === '') {
            $current = TrustedProxyService::DEFAULT_SPEC;
        }

        $spec = TrustedProxyService::normalizeSpec($current . ', ' . $network);
        if ($spec === null) {
            return ['success' => false, 'error' => 'Die bestehende Proxy-Einstellung ist ungültig. Bitte zuerst korrigieren.'];
        }

        $result = $this->storeProxySpec($spec, 'firewall.proxy.trust_candidate', 'Proxy-Netz als vertrauenswürdig übernommen: ' . $network);
        if (!empty($result['success'])) {
            TrustedProxyService::removeCandidate($network);
            $result['message'] = 'Proxy-Netz ' . $network . ' wird jetzt als vertrauenswürdig behandelt.';
        }

        return $result;
    }

    public function dismissProxyCandidate(string $network): array
    {
        TrustedProxyService::removeCandidate(trim($network));

        return ['success' => true, 'message' => 'Eintrag aus der Erkennungsliste entfernt.'];
    }

    public function clearProxyCandidates(): array
    {
        TrustedProxyService::clearCandidates();

        return ['success' => true, 'message' => 'Erkennungsliste geleert.'];
    }

    private function storeProxySpec(string $spec, string $auditAction, string $auditMessage): array
    {
        try {
            $key = TrustedProxyService::OPTION_KEY;
            $exists = $this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}settings WHERE option_name = ?", [$key]);
            if ($exists) {
                $this->db->update('settings', ['option_value' => $spec], ['option_name' => $key]);
            } else {
                $this->db->insert('settings', ['option_name' => $key, 'option_value' => $spec]);
            }
            TrustedProxyService::reset();

            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                $auditAction,
                $auditMessage,
                'setting',
                null,
                [$key => $spec],
                'warning'
            );

            return ['success' => true, 'message' => 'Proxy-/CDN-Einstellung gespeichert.'];
        } catch (\Throwable $e) {
            $this->logFailure('firewall.proxy.save_failed', 'Proxy-/CDN-Einstellung konnte nicht gespeichert werden.', [
                'exception' => $e::class,
            ]);

            return ['success' => false, 'error' => 'Proxy-/CDN-Einstellung konnte nicht gespeichert werden.'];
        }
    }

    public function saveSettings(array $post): array
    {
        $keys = [
            'firewall_enabled'        => isset($post['firewall_enabled']) ? '1' : '0',
            'firewall_rate_limit'     => (string)max(10, min(1000, (int)($post['firewall_rate_limit'] ?? 60))),
            'firewall_rate_window'    => (string)max(60, min(3600, (int)($post['firewall_rate_window'] ?? 60))),
            'firewall_block_duration' => (string)max(60, min(86400, (int)($post['firewall_block_duration'] ?? 3600))),
            'firewall_log_enabled'    => isset($post['firewall_log_enabled']) ? '1' : '0',
            'firewall_simulation_preview_hours' => (string)max(1, min(168, (int)($post['firewall_simulation_preview_hours'] ?? self::DEFAULT_SIMULATION_PREVIEW_HOURS))),
        ];

        try {
            $this->persistSettings($keys);
            // ADDED: Änderungen an Security-Einstellungen zentral protokollieren.
            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'firewall.settings.save',
                'Firewall-Einstellungen gespeichert',
                'setting',
                null,
                $keys,
                'warning'
            );

            return ['success' => true, 'message' => 'Firewall-Einstellungen gespeichert.'];
        } catch (\Throwable $e) {
            $this->logFailure('firewall.settings.save_failed', 'Firewall-Einstellungen konnten nicht gespeichert werden.', [
                'exception' => $e::class,
            ]);

            return ['success' => false, 'error' => 'Firewall-Einstellungen konnten nicht gespeichert werden.'];
        }
    }

    public function applyBaselineProfile(string $profileKey): array
    {
        $profileKey = $this->normalizeBaselineProfileKey($profileKey);
        if ($profileKey === '') {
            return ['success' => false, 'error' => 'Unbekanntes Härtungsprofil.'];
        }

        $profile = self::BASELINE_PROFILES[$profileKey];
        $settings = $profile['settings'] ?? [];
        if (!is_array($settings) || $settings === []) {
            return ['success' => false, 'error' => 'Härtungsprofil enthält keine anwendbaren Einstellungen.'];
        }

        try {
            $this->persistSettings($settings);
            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'firewall.baseline.apply',
                'Firewall-Härtungsprofil angewendet',
                'setting',
                null,
                [
                    'profile' => $profileKey,
                    'label' => (string)($profile['label'] ?? $profileKey),
                    'settings' => array_keys($settings),
                ],
                'warning'
            );

            return ['success' => true, 'message' => 'Härtungsprofil „' . (string)($profile['label'] ?? $profileKey) . '“ wurde angewendet.'];
        } catch (\Throwable $e) {
            $this->logFailure('firewall.baseline.apply_failed', 'Firewall-Härtungsprofil konnte nicht angewendet werden.', [
                'profile' => $profileKey,
                'exception' => $e::class,
            ]);

            return ['success' => false, 'error' => 'Härtungsprofil konnte nicht angewendet werden.'];
        }
    }

    public function addRule(array $post): array
    {
        $type  = in_array($post['rule_type'] ?? '', ['block_ip', 'block_range', 'allow_ip', 'block_ua', 'block_country'], true)
            ? (string)$post['rule_type'] : 'block_ip';
        $ruleMode = $this->normalizeRuleMode((string)($post['rule_mode'] ?? self::RULE_MODE_SIMULATE), $type);
        $value = $this->sanitizeRuleValue((string)($post['rule_value'] ?? ''));
        if ($value === '') {
            return ['success' => false, 'error' => 'Wert ist erforderlich.'];
        }

        if (in_array($type, ['block_ip', 'allow_ip'], true) && !filter_var($value, FILTER_VALIDATE_IP)) {
            return ['success' => false, 'error' => 'Ungültige IP-Adresse.'];
        }
        if ($type === 'block_range' && !$this->isValidCidrRange($value)) {
            return ['success' => false, 'error' => 'Ungültiger IP-Bereich. Erwartet wird z. B. 192.168.0.0/24.'];
        }
        if ($type === 'block_country') {
            $value = strtoupper($value);
            if (preg_match('/^[A-Z]{2}$/', $value) !== 1) {
                return ['success' => false, 'error' => 'Ungültiger Ländercode. Erwartet wird ein ISO-3166-Code wie DE oder AT.'];
            }
        }
        if ($type === 'block_ua' && (function_exists('mb_strlen') ? mb_strlen($value) : strlen($value)) < 3) {
            return ['success' => false, 'error' => 'User-Agent-Regeln müssen mindestens 3 Zeichen lang sein.'];
        }

        if ($this->ruleExists($type, $value)) {
            return ['success' => false, 'error' => 'Diese Firewall-Regel existiert bereits.'];
        }

        $reason = $this->sanitizeText((string)($post['rule_reason'] ?? ''), 255);
        $expiresAt = $this->normalizeExpiration((string)($post['expires_at'] ?? ''));
        if (($post['expires_at'] ?? '') !== '' && $expiresAt === null) {
            return ['success' => false, 'error' => 'Ungültiges Ablaufdatum.'];
        }

        try {
            $insertId = $this->db->insert('firewall_rules', [
                'rule_type'  => $type,
                'rule_mode'  => $ruleMode,
                'value'      => $value,
                'reason'     => $reason,
                'is_active'  => 1,
                'expires_at' => $expiresAt,
            ]);

            if ($insertId === false) {
                return ['success' => false, 'error' => 'Regel konnte nicht gespeichert werden.'];
            }

            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'firewall.rule.add',
                'Firewall-Regel hinzugefügt',
                'firewall_rule',
                (int)$insertId,
                ['type' => $type, 'rule_mode' => $ruleMode, 'value' => $value, 'reason' => $reason, 'expires_at' => $expiresAt],
                'warning'
            );

            return [
                'success' => true,
                'message' => $ruleMode === self::RULE_MODE_SIMULATE
                    ? 'Regel im Simulationsmodus hinzugefügt. Treffer werden nur protokolliert.'
                    : 'Regel hinzugefügt und sofort scharfgeschaltet.',
            ];
        } catch (\Throwable $e) {
            $this->logFailure('firewall.rule.add_failed', 'Firewall-Regel konnte nicht hinzugefügt werden.', [
                'type' => $type,
                'exception' => $e::class,
            ]);

            return ['success' => false, 'error' => 'Firewall-Regel konnte nicht hinzugefügt werden.'];
        }
    }

    public function deleteRule(int $id): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Ungültige ID.'];
        }

        $rule = $this->getRuleById($id);
        if ($rule === null) {
            return ['success' => false, 'error' => 'Regel nicht gefunden.'];
        }

        try {
            $deleted = $this->db->delete('firewall_rules', ['id' => $id]);
            if (!$deleted) {
                return ['success' => false, 'error' => 'Regel konnte nicht gelöscht werden.'];
            }
        } catch (\Throwable $e) {
            $this->logFailure('firewall.rule.delete_failed', 'Firewall-Regel konnte nicht gelöscht werden.', [
                'rule_id' => $id,
                'exception' => $e::class,
            ]);

            return ['success' => false, 'error' => 'Regel konnte nicht gelöscht werden.'];
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            'firewall.rule.delete',
            'Firewall-Regel gelöscht',
            'firewall_rule',
            $id,
            ['type' => (string)($rule['rule_type'] ?? ''), 'value' => (string)($rule['value'] ?? '')],
            'warning'
        );

        return ['success' => true, 'message' => 'Regel gelöscht.'];
    }

    public function toggleRule(int $id): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Ungültige ID.'];
        }

        $rule = $this->db->get_row(
            "SELECT id, rule_type, value, is_active FROM {$this->prefix}firewall_rules WHERE id = ?",
            [$id]
        );
        if (!$rule) {
            return ['success' => false, 'error' => 'Regel nicht gefunden.'];
        }

        $newStatus = (int)$rule->is_active ? 0 : 1;

        try {
            $updated = $this->db->update('firewall_rules', ['is_active' => $newStatus], ['id' => $id]);
            if (!$updated) {
                return ['success' => false, 'error' => 'Regelstatus konnte nicht geändert werden.'];
            }
        } catch (\Throwable $e) {
            $this->logFailure('firewall.rule.toggle_failed', 'Firewall-Regel konnte nicht umgeschaltet werden.', [
                'rule_id' => $id,
                'exception' => $e::class,
            ]);

            return ['success' => false, 'error' => 'Regelstatus konnte nicht geändert werden.'];
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            'firewall.rule.toggle',
            $newStatus ? 'Firewall-Regel aktiviert' : 'Firewall-Regel deaktiviert',
            'firewall_rule',
            $id,
            ['is_active' => $newStatus, 'type' => (string)$rule->rule_type, 'value' => (string)$rule->value],
            'warning'
        );

        return ['success' => true, 'message' => $newStatus ? 'Regel aktiviert.' : 'Regel deaktiviert.'];
    }

    public function setRuleMode(int $id, string $targetMode): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Ungültige ID.'];
        }

        $rule = $this->db->get_row(
            "SELECT id, rule_type, rule_mode, value, reason FROM {$this->prefix}firewall_rules WHERE id = ? LIMIT 1",
            [$id]
        );
        if (!$rule) {
            return ['success' => false, 'error' => 'Regel nicht gefunden.'];
        }

        $normalizedTargetMode = $this->normalizeRuleMode($targetMode, (string)$rule->rule_type);
        if ((string)$rule->rule_type === 'allow_ip' && $normalizedTargetMode === self::RULE_MODE_SIMULATE) {
            return ['success' => false, 'error' => 'Erlaubnisregeln unterstützen keinen Simulationsmodus.'];
        }

        if ($this->isRuntimeManagedRule((array)$rule) && $normalizedTargetMode === self::RULE_MODE_SIMULATE) {
            return ['success' => false, 'error' => 'Automatische Rate-Limit-Sperren können nicht in den Simulationsmodus geschaltet werden.'];
        }

        if ((string)$rule->rule_mode === $normalizedTargetMode) {
            return ['success' => true, 'message' => $normalizedTargetMode === self::RULE_MODE_SIMULATE ? 'Regel läuft bereits im Simulationsmodus.' : 'Regel ist bereits scharfgeschaltet.'];
        }

        try {
            $updated = $this->db->update('firewall_rules', ['rule_mode' => $normalizedTargetMode], ['id' => $id]);
            if (!$updated) {
                return ['success' => false, 'error' => 'Regelmodus konnte nicht geändert werden.'];
            }
        } catch (\Throwable $e) {
            $this->logFailure('firewall.rule.mode_failed', 'Firewall-Regelmodus konnte nicht geändert werden.', [
                'rule_id' => $id,
                'target_mode' => $normalizedTargetMode,
                'exception' => $e::class,
            ]);

            return ['success' => false, 'error' => 'Regelmodus konnte nicht geändert werden.'];
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            'firewall.rule.mode',
            $normalizedTargetMode === self::RULE_MODE_SIMULATE ? 'Firewall-Regel auf Simulation gestellt' : 'Firewall-Regel scharfgeschaltet',
            'firewall_rule',
            $id,
            ['rule_mode' => $normalizedTargetMode, 'type' => (string)$rule->rule_type, 'value' => (string)$rule->value],
            'warning'
        );

        return [
            'success' => true,
            'message' => $normalizedTargetMode === self::RULE_MODE_SIMULATE
                ? 'Regel läuft jetzt im Simulationsmodus. Treffer werden nur protokolliert.'
                : 'Regel ist jetzt scharfgeschaltet und blockiert Treffer aktiv.',
        ];
    }

    private function loadSettings(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::SETTING_KEYS), '?'));
        $rows = $this->db->get_results(
            "SELECT option_name, option_value FROM {$this->prefix}settings WHERE option_name IN ({$placeholders})",
            self::SETTING_KEYS
        ) ?: [];

        $settings = [
            'firewall_enabled' => '0',
            'firewall_rate_limit' => '60',
            'firewall_rate_window' => '60',
            'firewall_block_duration' => '3600',
            'firewall_log_enabled' => '0',
            'firewall_simulation_preview_hours' => (string)self::DEFAULT_SIMULATION_PREVIEW_HOURS,
        ];
        foreach ($rows as $row) {
            $settings[(string)$row->option_name] = (string)$row->option_value;
        }

        return $settings;
    }

    /** @param array<string,string> $settings */
    private function persistSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            if (!in_array($key, self::SETTING_KEYS, true)) {
                continue;
            }

            $exists = $this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}settings WHERE option_name = ?", [$key]);
            if ($exists) {
                $this->db->update('settings', ['option_value' => (string)$value], ['option_name' => $key]);
            } else {
                $this->db->insert('settings', ['option_name' => $key, 'option_value' => (string)$value]);
            }
        }
    }

    /**
     * @param array<string,string> $settings
     * @param array<int,array<string,mixed>> $rules
     * @param array<string,mixed> $simulation
     * @param array<int,mixed> $recentBlocks
     * @return array<string,mixed>
     */
    private function buildBaselineData(array $settings, array $rules, array $simulation, array $recentBlocks): array
    {
        $profiles = [];
        foreach (self::BASELINE_PROFILES as $key => $profile) {
            $profileSettings = is_array($profile['settings'] ?? null) ? $profile['settings'] : [];
            $diff = $this->buildProfileDiff($settings, $profileSettings);
            $profiles[$key] = [
                'key' => $key,
                'label' => (string)($profile['label'] ?? $key),
                'description' => (string)($profile['description'] ?? ''),
                'settings' => $profileSettings,
                'diff' => $diff,
                'match_count' => count(array_filter($diff, static fn(array $item): bool => !empty($item['matches']))),
                'diff_count' => count(array_filter($diff, static fn(array $item): bool => empty($item['matches']))),
            ];
        }

        return [
            'profiles' => $profiles,
            'diagnostics' => $this->buildFirewallDiagnostics($settings, $rules, $simulation, $recentBlocks),
        ];
    }

    /**
     * @param array<string,string> $current
     * @param array<string,string> $recommended
     * @return array<int,array<string,mixed>>
     */
    private function buildProfileDiff(array $current, array $recommended): array
    {
        $diff = [];
        foreach ($recommended as $key => $recommendedValue) {
            $currentValue = (string)($current[$key] ?? '');
            $recommendedValue = (string)$recommendedValue;
            $diff[] = [
                'key' => $key,
                'label' => self::BASELINE_SETTING_LABELS[$key] ?? $key,
                'current' => $this->formatBaselineValue($key, $currentValue),
                'recommended' => $this->formatBaselineValue($key, $recommendedValue),
                'matches' => $currentValue === $recommendedValue,
            ];
        }

        return $diff;
    }

    /**
     * @param array<string,string> $settings
     * @param array<int,array<string,mixed>> $rules
     * @param array<string,mixed> $simulation
     * @param array<int,mixed> $recentBlocks
     * @return array<int,array<string,string>>
     */
    private function buildFirewallDiagnostics(array $settings, array $rules, array $simulation, array $recentBlocks): array
    {
        $runtimeActive = class_exists('\CMS\Services\SecurityRuntimeService')
            && defined('ABSPATH')
            && $this->fileContains((string)ABSPATH . 'core/Bootstrap.php', 'SecurityRuntimeService::getInstance()->handleRequest()');
        $activeRules = count(array_filter($rules, static fn(array $rule): bool => (int)($rule['is_active'] ?? 0) === 1));
        $enforcedRules = count(array_filter($rules, static fn(array $rule): bool => (int)($rule['is_active'] ?? 0) === 1 && ($rule['rule_mode'] ?? '') === self::RULE_MODE_ENFORCE));

        return [
            [
                'label' => 'Runtime-Verdrahtung',
                'status' => $runtimeActive ? 'ok' : 'critical',
                'detail' => $runtimeActive ? 'SecurityRuntimeService ist im Bootstrap-Pfad aktiv.' : 'SecurityRuntimeService wurde im Bootstrap-Pfad nicht erkannt.',
            ],
            [
                'label' => 'Firewall-Schalter',
                'status' => ($settings['firewall_enabled'] ?? '0') === '1' ? 'ok' : 'warning',
                'detail' => ($settings['firewall_enabled'] ?? '0') === '1' ? 'Firewall ist aktiviert.' : 'Firewall ist aktuell deaktiviert.',
            ],
            [
                'label' => 'Logging',
                'status' => ($settings['firewall_log_enabled'] ?? '0') === '1' ? 'ok' : 'warning',
                'detail' => ($settings['firewall_log_enabled'] ?? '0') === '1' ? 'Firewall-Logging ist aktiviert.' : 'Ohne Logging bleiben Diagnose und Alerting eingeschränkt.',
            ],
            [
                'label' => 'Aktive Regeln',
                'status' => $activeRules > 0 ? 'ok' : 'warning',
                'detail' => $activeRules . ' aktive Regeln, davon ' . $enforcedRules . ' scharfgeschaltet.',
            ],
            [
                'label' => 'Simulationsvorschau',
                'status' => ($simulation['available'] ?? true) === true ? 'ok' : 'warning',
                'detail' => 'Treffer im Fenster: ' . (int)($simulation['total_hits'] ?? 0) . ', Regeln mit Treffern: ' . (int)($simulation['rules_with_hits'] ?? 0) . '.',
            ],
            [
                'label' => 'Block-Log',
                'status' => count($recentBlocks) > 0 ? 'ok' : 'warning',
                'detail' => count($recentBlocks) > 0 ? 'Aktuelle Block-/Rate-Limit-Ereignisse vorhanden.' : 'Noch keine aktuellen Block- oder Rate-Limit-Ereignisse protokolliert.',
            ],
        ];
    }

    private function normalizeBaselineProfileKey(string $profileKey): string
    {
        $profileKey = strtolower(trim($profileKey));

        return array_key_exists($profileKey, self::BASELINE_PROFILES) ? $profileKey : '';
    }

    private function formatBaselineValue(string $key, string $value): string
    {
        if (in_array($key, ['firewall_enabled', 'firewall_log_enabled'], true)) {
            return $value === '1' ? 'Aktiv' : 'Inaktiv';
        }

        return match ($key) {
            'firewall_rate_limit' => $value . ' Anfragen',
            'firewall_rate_window' => $value . ' Sekunden',
            'firewall_block_duration' => $value . ' Sekunden',
            'firewall_simulation_preview_hours' => $value . ' Stunden',
            default => $value,
        };
    }

    private function fileContains(string $path, string $needle): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $content = file_get_contents($path, false, null, 0, 131072);

        return is_string($content) && str_contains($content, $needle);
    }

    private function sanitizeText(string $value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim(strip_tags($value))) ?? '';

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function sanitizeRuleValue(string $value): string
    {
        return $this->sanitizeText($value, 255);
    }

    private function isValidCidrRange(string $value): bool
    {
        if (preg_match('/^([0-9a-f:.]+)\/(\d{1,3})$/i', $value, $matches) !== 1) {
            return false;
        }

        $ip = (string)$matches[1];
        $mask = (int)$matches[2];

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $mask >= 0 && $mask <= 32;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $mask >= 0 && $mask <= 128;
        }

        return false;
    }

    private function normalizeExpiration(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false || $timestamp <= time()) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function ruleExists(string $type, string $value): bool
    {
        return (int)$this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}firewall_rules WHERE rule_type = ? AND value = ?",
            [$type, $value]
        ) > 0;
    }

    private function getRuleById(int $id): ?array
    {
        $row = $this->db->get_row(
            "SELECT id, rule_type, rule_mode, value, reason FROM {$this->prefix}firewall_rules WHERE id = ? LIMIT 1",
            [$id]
        );

        return $row ? (array)$row : null;
    }

    private function normalizeRuleRow(object|array $rule): array
    {
        $row = (array)$rule;

        return [
            'id' => (int)($row['id'] ?? 0),
            'rule_type' => (string)($row['rule_type'] ?? ''),
            'rule_mode' => $this->normalizeRuleMode((string)($row['rule_mode'] ?? self::RULE_MODE_ENFORCE), (string)($row['rule_type'] ?? '')),
            'value' => (string)($row['value'] ?? ''),
            'reason' => (string)($row['reason'] ?? ''),
            'is_active' => (int)($row['is_active'] ?? 0),
            'expires_at' => (string)($row['expires_at'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'rule_match_key' => $this->buildRuleMatchKey((int)($row['id'] ?? 0)),
        ];
    }

    /** @param array<int,array<string,mixed>> $rules @return array<string,mixed> */
    private function buildSimulationPreview(array $rules, int $hours): array
    {
        $ruleHits = [];
        foreach ($rules as $rule) {
            if (($rule['rule_mode'] ?? self::RULE_MODE_ENFORCE) !== self::RULE_MODE_SIMULATE || ($rule['rule_type'] ?? '') === 'allow_ip') {
                continue;
            }

            $matchKey = (string)($rule['rule_match_key'] ?? '');
            if ($matchKey === '') {
                continue;
            }

            $ruleHits[$matchKey] = [
                'rule_id' => (int)($rule['id'] ?? 0),
                'label' => $this->getRuleTypeLabel((string)($rule['rule_type'] ?? '')),
                'value' => (string)($rule['value'] ?? ''),
                'hits' => 0,
                'unique_ips' => 0,
                'last_hit' => '',
                '_ips' => [],
            ];
        }

        $preview = [
            'window_hours' => $hours,
            'total_hits' => 0,
            'rules_with_hits' => 0,
            'recent_hits' => [],
            'rule_hits' => $ruleHits,
            'available' => true,
        ];

        if ($ruleHits === []) {
            return $preview;
        }

        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));

        try {
            $rows = $this->db->get_results(
                "SELECT rule_matched, ip_address, request_uri, created_at
                 FROM {$this->prefix}security_log
                 WHERE action = 'simulated'
                   AND created_at >= ?
                 ORDER BY created_at DESC
                 LIMIT 500",
                [$since]
            ) ?: [];
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.security')->warning('Firewall-Simulationsvorschau konnte nicht geladen werden.', [
                'exception' => $e::class,
            ]);

            $preview['available'] = false;
            return $preview;
        }

        foreach ($rows as $row) {
            $matchKey = (string)($row->rule_matched ?? '');
            if ($matchKey === '' || !isset($ruleHits[$matchKey])) {
                continue;
            }

            $ruleHits[$matchKey]['hits']++;
            $preview['total_hits']++;

            $ip = (string)($row->ip_address ?? '');
            if ($ip !== '') {
                $ruleHits[$matchKey]['_ips'][$ip] = true;
            }

            if ($ruleHits[$matchKey]['last_hit'] === '') {
                $ruleHits[$matchKey]['last_hit'] = (string)($row->created_at ?? '');
            }

            if (count($preview['recent_hits']) < 10) {
                $preview['recent_hits'][] = [
                    'rule_id' => $ruleHits[$matchKey]['rule_id'],
                    'rule_label' => $ruleHits[$matchKey]['label'],
                    'rule_value' => $ruleHits[$matchKey]['value'],
                    'ip_address' => $ip,
                    'request_uri' => $this->sanitizeText((string)($row->request_uri ?? ''), 180),
                    'created_at' => (string)($row->created_at ?? ''),
                ];
            }
        }

        foreach ($ruleHits as $matchKey => $summary) {
            $ruleHits[$matchKey]['unique_ips'] = count($summary['_ips']);
            unset($ruleHits[$matchKey]['_ips']);

            if ($ruleHits[$matchKey]['hits'] > 0) {
                $preview['rules_with_hits']++;
            }
        }

        $preview['rule_hits'] = $ruleHits;

        return $preview;
    }

    private function getRuleTypeLabel(string $type): string
    {
        return match ($type) {
            'block_ip' => 'IP blockieren',
            'block_range' => 'IP-Bereich blockieren',
            'allow_ip' => 'IP erlauben',
            'block_ua' => 'User-Agent blockieren',
            'block_country' => 'Land blockieren',
            default => $type,
        };
    }

    private function buildRuleMatchKey(int $id): string
    {
        return $id > 0 ? 'rule#' . $id : '';
    }

    private function normalizeRuleMode(string $mode, string $type): string
    {
        if ($type === 'allow_ip') {
            return self::RULE_MODE_ENFORCE;
        }

        return $mode === self::RULE_MODE_SIMULATE ? self::RULE_MODE_SIMULATE : self::RULE_MODE_ENFORCE;
    }

    /** @param array<string,mixed> $rule */
    private function isRuntimeManagedRule(array $rule): bool
    {
        return ((string)($rule['rule_type'] ?? '')) === 'block_ip'
            && ((string)($rule['reason'] ?? '')) === 'Automatisches Rate-Limit';
    }

    private function ensureColumn(string $table, string $column, string $alterSql): void
    {
        $exists = $this->db->get_var("SHOW COLUMNS FROM {$table} LIKE '{$column}'") !== null;
        if ($exists) {
            return;
        }

        $this->db->getPdo()->exec($alterSql);
    }

    /** @param array<string, mixed> $context */
    private function logFailure(string $action, string $message, array $context = []): void
    {
        Logger::instance()->withChannel('admin.security')->warning($message, $context);
        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            $action,
            $message,
            'firewall_rule',
            isset($context['rule_id']) ? (int)$context['rule_id'] : null,
            $context,
            'error'
        );
    }
}
