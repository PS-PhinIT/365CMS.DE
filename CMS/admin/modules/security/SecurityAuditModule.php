<?php
declare(strict_types=1);

/**
 * SecurityAuditModule – Sicherheitsprüfungen & Audit-Log
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Logger;

class SecurityAuditModule
{
    private const MAX_AUDIT_LOG_ROWS = 50;
    private const MAX_CHECK_NAME_LENGTH = 120;
    private const MAX_CHECK_DETAIL_LENGTH = 320;
    private const MAX_AUDIT_DETAIL_LENGTH = 240;
    private const MAX_HTACCESS_BYTES = 131072;
    private const AUDIT_LOG_RETENTION_DAYS = 30;

    private readonly \CMS\Database $db;
    private readonly string $prefix;

    public function __construct()
    {
        $this->db     = \CMS\Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    public function getData(): array
    {
        if (!$this->canAccess()) {
            return [
                'checks' => [],
                'stats' => ['passed' => 0, 'warning' => 0, 'critical' => 0, 'total' => 0],
                'audit_log' => [],
                'error' => 'Zugriff verweigert.',
            ];
        }

        $checks = $this->performChecks();

        return [
            'checks'  => $checks,
            'stats'   => $this->summarizeChecks($checks),
            'audit_log' => $this->fetchRecentAuditLog(),
            'log_retention' => $this->getLogRetention(),
        ];
    }

    /**
     * @return array{activity_log:int, audit_log:int, minimum:int}
     */
    private function getLogRetention(): array
    {
        $days = ['activity_log' => 0, 'audit_log' => 0];
        try {
            $configured = \CMS\Services\DataRetentionService::getInstance()->getRetentionDays();
            $days['activity_log'] = (int) ($configured['activity_log'] ?? 0);
            $days['audit_log'] = (int) ($configured['audit_log'] ?? 0);
        } catch (\Throwable) {
        }

        return $days + ['minimum' => \CMS\Services\DataRetentionService::MIN_LOG_RETENTION_DAYS];
    }

    public function saveLogRetention(array $post): array
    {
        if (!$this->canAccess()) {
            return ['success' => false, 'error' => 'Zugriff verweigert.'];
        }

        try {
            $saved = \CMS\Services\DataRetentionService::getInstance()->saveLogRetentionDays([
                'activity_log' => (int) ($post['activity_log_days'] ?? 0),
                'audit_log' => (int) ($post['audit_log_days'] ?? 0),
            ]);

            AuditLogger::instance()->log(
                AuditLogger::CAT_SETTING,
                'security.audit.log_retention',
                'Aufbewahrungsfristen für Aktivitäts-/Audit-Protokoll gespeichert',
                'setting',
                null,
                $saved,
                'warning'
            );

            return ['success' => true, 'message' => 'Aufbewahrungsfristen gespeichert. Die Löschung läuft mit dem täglichen Cron.'];
        } catch (\Throwable $e) {
            $this->logFailure('security.audit.log_retention_failed', 'Aufbewahrungsfristen konnten nicht gespeichert werden.', [
                'exception' => $e::class,
            ]);

            return ['success' => false, 'error' => 'Aufbewahrungsfristen konnten nicht gespeichert werden.'];
        }
    }

    public function runAudit(): array
    {
        if (!$this->canAccess()) {
            return ['success' => false, 'error' => 'Sie dürfen dieses Audit nicht ausführen.'];
        }

        $checks = $this->performChecks();
        $stats = $this->summarizeChecks($checks);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            'security.audit.run',
            'Sicherheits-Audit manuell ausgeführt',
            'security_audit',
            null,
            [
                'critical' => $stats['critical'],
                'warning' => $stats['warning'],
                'total' => $stats['total'],
            ],
            $stats['critical'] > 0 ? 'warning' : 'info'
        );

        return ['success' => true, 'message' => "Audit abgeschlossen. {$stats['critical']} kritische Probleme gefunden."];
    }

    public function clearLog(): array
    {
        if (!$this->canAccess()) {
            return ['success' => false, 'error' => 'Sie dürfen Audit-Logs nicht bereinigen.'];
        }

        try {
            $auditCategories = $this->getAuditLogCategories();
            $olderEntries = (int) ($this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}audit_log WHERE category IN (?, ?) AND created_at < DATE_SUB(NOW(), INTERVAL " . self::AUDIT_LOG_RETENTION_DAYS . " DAY)",
                $auditCategories
            ) ?? 0);

            $this->db->execute(
                "DELETE FROM {$this->prefix}audit_log WHERE category IN (?, ?) AND created_at < DATE_SUB(NOW(), INTERVAL " . self::AUDIT_LOG_RETENTION_DAYS . " DAY)",
                $auditCategories
            );

            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'security.audit.clear_log',
                'Alte Sicherheits-Audit-Logs bereinigt',
                'security_audit',
                null,
                [
                    'deleted_entries' => $olderEntries,
                    'retention_days' => self::AUDIT_LOG_RETENTION_DAYS,
                    'categories' => $auditCategories,
                ],
                'warning'
            );

            return ['success' => true, 'message' => 'Alte Sicherheits-Audit-Einträge (> 30 Tage) gelöscht.'];
        } catch (\Throwable $e) {
            $this->logFailure('security.audit.clear_log_failed', 'Audit-Log-Bereinigung fehlgeschlagen.', [
                'exception' => $e::class,
            ]);

            return ['success' => false, 'error' => 'Audit-Log-Bereinigung fehlgeschlagen.'];
        }
    }

    private function performChecks(): array
    {
        $checks = [];
        $abspath = defined('ABSPATH') ? ABSPATH : '';
        $headerProfile = class_exists('\CMS\Security') ? \CMS\Security::instance()->getSecurityHeaderProfile() : [];
        $htaccessStatus = $this->inspectHtaccessHeaders($abspath);
        $isHttps = (bool) ($headerProfile['https'] ?? false);
        $debugOn = defined('CMS_DEBUG') && CMS_DEBUG;

        $checks[] = $this->buildCheck(
            'HTTPS aktiv',
            $isHttps ? 'ok' : 'critical',
            $isHttps ? 'Verbindung ist verschlüsselt.' : 'HTTPS ist nicht aktiv! Alle Daten werden unverschlüsselt übertragen.'
        );

        $phpVersion    = PHP_VERSION;
        $minPhpVersion = \CMS\Version::minimumPhp();
        $phpStatus     = match (true) {
            version_compare($phpVersion, $minPhpVersion, '>=') => 'ok',
            version_compare($phpVersion, '8.2.0', '>=')        => 'warning',
            default                                             => 'critical',
        };
        $checks[] = $this->buildCheck(
            'PHP-Version (' . $phpVersion . ')',
            $phpStatus,
            match ($phpStatus) {
                'ok'       => 'PHP-Version erfüllt die Mindestanforderung (' . $minPhpVersion . '+).',
                'warning'  => 'PHP-Version liegt unter der Mindestanforderung – Update auf ' . $minPhpVersion . '+ erforderlich.',
                'critical' => 'PHP-Version ist veraltet! Mindestens ' . $minPhpVersion . ' erforderlich.',
            }
        );

        $checks = array_merge($checks, $this->buildConfigPermissionChecks($abspath));

        // install.php wird mit jedem Update ausgeliefert; nach der Installation sperrt config/install.lock
        // den Installer für alle außer angemeldeten Admins (InstallerController::guardInstalledInstaller()).
        $installExists = is_file($abspath . 'install.php');
        $installLocked = $installExists && is_file($abspath . 'config/install.lock');
        $checks[] = $this->buildCheck(
            $installExists ? 'install.php gesperrt' : 'install.php entfernt',
            (!$installExists || $installLocked) ? 'ok' : 'warning',
            !$installExists
                ? 'install.php wurde entfernt.'
                : ($installLocked
                    ? 'install.php ist vorhanden, aber über config/install.lock gesperrt (nur angemeldete Admins). Optional nach jedem Update löschen.'
                    : 'install.php existiert ohne Sperrdatei config/install.lock – Datei löschen oder den Installer einmal als Admin aufrufen, damit die Sperre geschrieben wird.')
        );

        $checks[] = $this->buildCheck(
            'Debug-Modus',
            $debugOn ? 'warning' : 'ok',
            $debugOn ? 'Debug-Modus ist aktiv. Sollte in Produktion deaktiviert sein.' : 'Debug-Modus ist deaktiviert.'
        );

        $uploadsDir = $abspath . 'uploads/';
        if (is_dir($uploadsDir)) {
            $htaccess = is_file($uploadsDir . '.htaccess');
            $checks[] = $this->buildCheck(
                'Uploads-Schutz (.htaccess)',
                $htaccess ? 'ok' : 'warning',
                $htaccess ? 'Upload-Verzeichnis ist geschützt.' : 'Kein .htaccess im Upload-Verzeichnis. PHP-Ausführung sollte blockiert sein.'
            );
        }

        $checks[] = $this->buildCheck(
            'Passwort-Policy',
            method_exists('\CMS\Auth', 'validatePasswordPolicy') ? 'ok' : 'critical',
            method_exists('\CMS\Auth', 'validatePasswordPolicy')
                ? 'Passwort-Policy ist implementiert.'
                : 'Keine Passwort-Policy-Validierung gefunden.'
        );

        $checks[] = $this->buildCheck(
            'CSRF-Token-System',
            class_exists('\CMS\Security') ? 'ok' : 'critical',
            class_exists('\CMS\Security') ? 'CSRF-Token-System ist aktiv.' : 'Security-Klasse nicht gefunden.'
        );

        $cspStatus = (($headerProfile['csp_mode'] ?? '') === 'enforced' && !empty($headerProfile['csp_uses_nonce']))
            ? 'ok'
            : 'warning';
        $checks[] = $this->buildCheck(
            'Content-Security-Policy (CSP)',
            $cspStatus,
            !empty($headerProfile['csp_uses_nonce'])
                ? ('Nonce-basierte CSP aktiv; Modus: ' . (($headerProfile['csp_mode'] ?? 'report-only') === 'enforced' ? 'enforced' : 'report-only') . (($debugOn && ($headerProfile['csp_mode'] ?? '') !== 'enforced') ? ' (Debug-Modus)' : '') . '.')
                : 'Keine nonce-basierte CSP erkannt.'
        );

        $trustedTypesStatus = !empty($headerProfile['trusted_types_enforced'])
            ? 'ok'
            : (!empty($headerProfile['trusted_types_report_only']) ? 'warning' : 'critical');
        $checks[] = $this->buildCheck(
            'Trusted Types',
            $trustedTypesStatus,
            !empty($headerProfile['trusted_types_enforced'])
                ? 'Trusted Types werden erzwungen.'
                : (!empty($headerProfile['trusted_types_report_only'])
                    ? 'Trusted Types laufen aktuell im Report-Only-Modus zur Kompatibilitätsprüfung.'
                    : 'Trusted Types sind nicht konfiguriert.')
        );

        $hstsRuntimeOk = !empty($headerProfile['hsts_enabled']) && !empty($headerProfile['hsts_include_subdomains']) && !empty($headerProfile['hsts_preload']);
        $hstsFallbackOk = !empty($htaccessStatus['hsts_include_subdomains']) && !empty($htaccessStatus['hsts_preload']) && !empty($htaccessStatus['proxy_https_detection']);
        $hstsStatus = !$isHttps
            ? 'warning'
            : (($hstsRuntimeOk || $hstsFallbackOk) ? 'ok' : 'critical');
        $checks[] = $this->buildCheck(
            'Strict-Transport-Security (HSTS)',
            $hstsStatus,
            !$isHttps
                ? 'HSTS kann erst bei aktivem HTTPS vollständig bewertet werden.'
                : ($hstsRuntimeOk
                    ? 'HSTS aktiv mit includeSubDomains und preload.'
                    : ($hstsFallbackOk
                        ? 'HSTS wird über den Apache-/Proxy-Fallback mit includeSubDomains und preload gesetzt.'
                    : ($debugOn
                        ? 'HSTS ist im Debug-Modus absichtlich nicht erzwungen.'
                        : 'HSTS ist für HTTPS-Anfragen nicht vollständig aktiv.')))
        );

        $fallbackStatus = !$htaccessStatus['exists']
            ? 'warning'
            : (($htaccessStatus['csp_setifempty']
                && !$htaccessStatus['csp_has_unsafe_inline']
                && !$htaccessStatus['csp_has_unsafe_eval']
                && !$htaccessStatus['report_only_present']
                && $htaccessStatus['trusted_types_present']
                && $htaccessStatus['hsts_include_subdomains']
                && $htaccessStatus['hsts_preload']
                && $htaccessStatus['proxy_https_detection']) ? 'ok' : 'warning');
        $checks[] = $this->buildCheck(
            '.htaccess Sicherheits-Fallback',
            $fallbackStatus,
            !$htaccessStatus['exists']
                ? 'Keine .htaccess-Datei gefunden – Apache-Fallback kann nicht geprüft werden.'
                : ($fallbackStatus === 'ok'
                    ? 'Apache-Fallback für CSP/HSTS ist konsistent gehärtet.'
                    : 'Apache-Fallback prüfen: kein Report-Only-Header im Produktivpfad, CSP ohne unsafe-inline/unsafe-eval, Trusted Types sowie HSTS inklusive Proxy-HTTPS-Erkennung sollten aktiv sein.')
        );

        $checks[] = $this->buildBackupCheck();
        $checks[] = $this->buildBackupStorageCheck();

        try {
            $weakAdmins = $this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}users WHERE role = 'admin' AND LENGTH(password) < 50"
            );
            $checks[] = $this->buildCheck(
                'Admin-Passwort-Hashes',
                ((int) $weakAdmins === 0) ? 'ok' : 'critical',
                ((int) $weakAdmins === 0)
                    ? 'Alle Admin-Passwörter sind korrekt gehasht.'
                    : "{$weakAdmins} Admin-Accounts haben möglicherweise schwache oder ungehashte Passwörter."
            );
        } catch (\Throwable $e) {
            $this->logFailure('security.audit.weak_admins_failed', 'Admin-Passwort-Hash-Prüfung konnte nicht ausgeführt werden.', [
                'exception' => $e::class,
            ]);
        }

        $checks[] = $this->buildFirewallCheck($abspath);
        $checks[] = $this->buildAntispamCheck($abspath);

        $externalRuntimeHits = [];
        foreach ([
            'themes/cms-default/functions.php',
            'core/Security.php',
            '.htaccess',
        ] as $relativePath) {
            $path = $abspath . $relativePath;
            if ($this->fileContainsAny($path, ['img-src \'self\' data: https:', 'script-src \'self\' https:', 'frame-src https:', 'child-src https:'])) {
                $externalRuntimeHits[] = $relativePath;
            }
        }
        $checks[] = $this->buildCheck(
            'Unerwartete Runtime-Fremdassets',
            $externalRuntimeHits === [] ? 'ok' : 'warning',
            $externalRuntimeHits === []
                ? 'Default-Theme und CSP erlauben keine ungeplanten Remote-Embeds; genehmigte Google-Font-Fallbacks bleiben optional.'
                : 'Runtime-Fremdasset-Verweise prüfen: ' . implode(', ', $externalRuntimeHits)
        );

        $editorAssetOk = is_file($abspath . 'core/Services/EditorJs/EditorJsAssetService.php')
            && !$this->fileContainsAny($abspath . 'core/Services/EditorJs/EditorJsAssetService.php', ['columns.umd.js', 'drawing-tool.umd.js', 'cropper-tune.umd.js'])
            && !$this->fileContainsAny($abspath . 'assets/js/editor-init.js', ['createEmbedConfig', 'createColumnsConfig']);
        $checks[] = $this->buildCheck(
            'Editor.js Alt-Embeds gehärtet',
            $editorAssetOk ? 'ok' : 'warning',
            $editorAssetOk
                ? 'Editor.js lädt keine nicht registrierten Columns-/Drawing-/Cropper-Bundles; aktive Embed-Blöcke laufen über die lokale, sanitizte Tool-Kette.'
                : 'Editor.js Alt-Bundles oder riskante Tool-Konfigurationen sind weiterhin aktiv.'
        );

        // Hoster-CDNs (z. B. Hostinger) mit unbekannten Edge-Adressen: ohne vertrauenswürdigen Proxy
        // teilen sich alle Besucher hinter derselben Edge-Adresse Rate-Limits und Sperren.
        // Nur die eigene Admin-Anfrage ist ein verlässliches Signal; Header-Statistiken fremder
        // Anfragen sind fälschbar und lösen deshalb keine Übernahme-Empfehlung aus.
        $currentProxyNetwork = \CMS\Services\TrustedProxyService::currentRequestCandidate();
        $loggedProxyNetworks = count(array_filter(
            \CMS\Services\TrustedProxyService::getCandidates(),
            static fn(array $candidate): bool => empty($candidate['trusted'])
        ));
        $checks[] = $this->buildCheck(
            'Proxy/CDN vor dem Server',
            $currentProxyNetwork === '' ? 'ok' : 'warning',
            $currentProxyNetwork !== ''
                ? 'Ihre eigene Anfrage kam über das nicht vertrauenswürdige Weiterleitungs-Netz ' . $currentProxyNetwork . '. Ist das das CDN bzw. der Proxy Ihres Hosters (z. B. Hostinger), unter Firewall → „Proxy / CDN & Client-IP“ übernehmen – sonst teilen sich Besucher Rate-Limits und Sperren.'
                : ($loggedProxyNetworks > 0
                    ? 'Die eigene Anfrage kam direkt bzw. über einen vertrauenswürdigen Proxy. ' . $loggedProxyNetworks . ' weitere Netz(e) mit Weiterleitungs-Headern protokolliert (unbestätigt, Header können gefälscht sein).'
                    : 'Die eigene Anfrage kam direkt bzw. über einen vertrauenswürdigen Proxy; Client-IPs werden korrekt ermittelt.')
        );

        return $checks;
    }

    /**
     * @return array{name:string, status:string, detail:string}
     */
    private function buildBackupCheck(): array
    {
        try {
            $overview = \CMS\Services\BackupService::getInstance()->getBackupOverview();
        } catch (\Throwable $e) {
            $this->logFailure('security.audit.backup_check_failed', 'Backup-Status konnte für das Sicherheits-Audit nicht ermittelt werden.', [
                'exception' => $e::class,
            ]);

            return $this->buildCheck(
                'Letztes Backup',
                'warning',
                'Backup-Status konnte nicht ermittelt werden – Backup-Verzeichnis (backups/ bzw. CMS_BACKUP_PATH) und dessen Schreib-/Leserechte prüfen.'
            );
        }

        $incompleteHint = $overview['incomplete'] > 0
            ? ' ' . $overview['incomplete'] . ' unvollständige(s) Backup(s) unter System → Backup & Restore prüfen bzw. löschen.'
            : '';
        $latest = $overview['latest'];

        if ($latest === null) {
            return $this->buildCheck(
                'Letztes Backup',
                'critical',
                'Kein vollständiges lokales Backup im Backup-Verzeichnis gefunden. Unter System → Backup & Restore ein Backup erstellen (externe Hoster-Backups kann das Audit nicht erkennen).' . $incompleteHint
            );
        }

        $timestamp = (int) $latest['timestamp'];
        $ageSeconds = $timestamp > 0 ? max(0, time() - $timestamp) : PHP_INT_MAX;
        $ageDays = intdiv($ageSeconds, 86400);
        $status = $ageSeconds <= 7 * 86400 ? 'ok' : ($ageSeconds <= 30 * 86400 ? 'warning' : 'critical');
        $ageLabel = match (true) {
            $timestamp <= 0 => 'mit unbekanntem Datum',
            $ageDays === 0 => 'vor weniger als 24 Stunden',
            $ageDays === 1 => 'vor 1 Tag',
            default => 'vor ' . $ageDays . ' Tagen',
        };

        return $this->buildCheck(
            'Letztes Backup',
            $status,
            sprintf(
                'Letztes Backup %s (%s%s); %d vollständige(s) lokale(s) Backup(s).%s',
                $ageLabel,
                $latest['type'] === 'full' ? 'Vollbackup' : 'Datenbank-Backup',
                $timestamp > 0 ? ', ' . date('d.m.Y H:i', $timestamp) : '',
                $overview['count'],
                $incompleteHint
            ) . ($status === 'ok' ? '' : ' Empfehlung: mindestens wöchentlich sichern.')
        );
    }

    /**
     * Backups im Webroot schützt nur .htaccess (Apache); nginx/IIS liefern sie ohne eigene Regel aus.
     *
     * @return array{name:string, status:string, detail:string}
     */
    private function buildBackupStorageCheck(): array
    {
        $name = 'Backup-Speicherort & Verschlüsselung';

        try {
            $storage = \CMS\Services\BackupService::getInstance()->getStorageStatus();
        } catch (\Throwable $e) {
            $this->logFailure('security.audit.backup_storage_check_failed', 'Backup-Speicherort konnte für das Sicherheits-Audit nicht ermittelt werden.', [
                'exception' => $e::class,
            ]);

            return $this->buildCheck($name, 'warning', 'Backup-Verzeichnis ist nicht nutzbar – CMS_BACKUP_PATH in config/app.php prüfen.');
        }

        $legacyHint = $storage['legacy_webroot_backups'] > 0
            ? ' ' . $storage['legacy_webroot_backups'] . ' Alt-Backup(s) liegen noch im bisherigen Verzeichnis backups/ des CMS – verschieben oder löschen.'
            : '';

        if ($storage['encryption_enabled'] && $storage['weak_key']) {
            return $this->buildCheck($name, 'warning', 'Backup-Verschlüsselung ist aktiv, aber ohne sicheren Schlüssel (Platzhalter in config/app.php) – CMS_ENCRYPTION_KEY setzen.' . $legacyHint);
        }

        if ($storage['inside_webroot'] && !$storage['encryption_enabled']) {
            return $this->buildCheck($name, 'warning', 'Backups liegen unverschlüsselt im Webroot und sind nur per .htaccess geschützt (nginx/IIS ignorieren sie). CMS_BACKUP_PATH außerhalb des Webroots setzen oder unter System → Backup & Restore die Verschlüsselung aktivieren.' . $legacyHint);
        }

        if ($storage['inside_webroot'] && $storage['unencrypted_backups'] > 0) {
            return $this->buildCheck($name, 'warning', 'Neue Backups werden verschlüsselt, ' . $storage['unencrypted_backups'] . ' ältere(s) Backup(s) liegen aber unverschlüsselt im Webroot – unter System → Backup & Restore löschen oder außerhalb des Webroots verschieben.' . $legacyHint);
        }

        $keyHint = $storage['encryption_enabled'] ? ' (Schlüssel ' . $storage['key_id'] . ' getrennt von den Backups sichern)' : '';
        $detail = ($storage['inside_webroot']
                ? 'Backups liegen im Webroot, werden aber verschlüsselt' . $keyHint . '.'
                : 'Backups liegen außerhalb des Webroots' . ($storage['encryption_enabled'] ? ' und werden verschlüsselt' . $keyHint : '') . '.')
            . $legacyHint;

        return $this->buildCheck($name, $legacyHint === '' ? 'ok' : 'warning', $detail);
    }

    /**
     * Die Verdrahtung allein genügt nicht: Ohne `firewall_enabled = 1` kehrt
     * SecurityRuntimeService::handleRequest() vor Regeln und Request-Rate-Limit zurück.
     *
     * @return array{name:string, status:string, detail:string}
     */
    private function buildFirewallCheck(string $abspath): array
    {
        $name = 'Firewall Runtime aktiv';
        $wired = class_exists('\CMS\Services\SecurityRuntimeService')
            && $this->fileContains($abspath . 'core/Bootstrap.php', 'SecurityRuntimeService::getInstance()->handleRequest()');
        if (!$wired) {
            return $this->buildCheck($name, 'critical', 'Firewall ist nicht vollständig im Runtime-Pfad verdrahtet.');
        }

        try {
            $enabled = (\CMS\Services\OptionStore::getInstance()->get('firewall_enabled') ?? '0') === '1';
        } catch (\Throwable $e) {
            $this->logFailure('security.audit.firewall_state_failed', 'Firewall-Schalter konnte für das Sicherheits-Audit nicht gelesen werden.', [
                'exception' => $e::class,
            ]);

            return $this->buildCheck($name, 'warning', 'Firewall-Schalter konnte nicht gelesen werden – Einstellungen unter Sicherheit → Firewall prüfen.');
        }

        return $enabled
            ? $this->buildCheck($name, 'ok', 'Firewall ist aktiviert; Regeln und Request-Rate-Limit greifen im Bootstrap-Runtime-Pfad.')
            : $this->buildCheck($name, 'warning', 'Firewall ist deaktiviert: Firewall-Regeln und das Request-Rate-Limit greifen nicht. Unter Sicherheit → Firewall aktivieren; Login-Sperren bleiben unabhängig davon aktiv.');
    }

    /**
     * Ohne `antispam_enabled = 1` lässt AntispamService::evaluate() jede Einsendung durch;
     * aufgeführt werden nur die tatsächlich aktiven Einzelprüfungen.
     *
     * @return array{name:string, status:string, detail:string}
     */
    private function buildAntispamCheck(string $abspath): array
    {
        $name = 'AntiSpam Runtime aktiv';
        $contactRuntime = $this->inspectContactAntispamRuntime();
        $commentWired = class_exists('\CMS\Services\AntispamService')
            && $this->fileContains($abspath . 'core/Services/CommentService.php', 'AntispamService::getInstance()->evaluate')
            && $this->fileContains($abspath . 'themes/cms-default/blog-single.php', 'comment_hp')
            && $this->fileContains($abspath . 'themes/cms-default/blog-single.php', 'comment_started_at');
        $contactDetail = $contactRuntime['detail'] !== '' ? ' ' . $contactRuntime['detail'] : '';

        if (!$commentWired || !$contactRuntime['ok']) {
            return $this->buildCheck(
                $name,
                'critical',
                ($commentWired
                    ? 'Kommentar-Runtime nutzt die zentrale AntiSpam-Auswertung.'
                    : 'Kommentar-Runtime ist nicht vollständig mit der zentralen AntiSpam-Auswertung verbunden.') . $contactDetail
            );
        }

        try {
            $settings = \CMS\Services\AntispamService::getInstance()->getSettings();
            $commentsEnabled = class_exists('\CMS\Services\CommentService')
                ? \CMS\Services\CommentService::getInstance()->areCommentsEnabledGlobally()
                : true;
        } catch (\Throwable $e) {
            $this->logFailure('security.audit.antispam_state_failed', 'AntiSpam-Einstellungen konnten für das Sicherheits-Audit nicht gelesen werden.', [
                'exception' => $e::class,
            ]);

            return $this->buildCheck($name, 'warning', 'AntiSpam-Einstellungen konnten nicht gelesen werden – Einstellungen unter Sicherheit → Anti-Spam prüfen.');
        }

        if (($settings['antispam_enabled'] ?? '0') !== '1') {
            $targets = array_values(array_filter([
                $commentsEnabled ? 'Kommentare' : '',
                $contactRuntime['active'] ? 'Kontaktformulare' : '',
            ]));

            if ($targets === []) {
                return $this->buildCheck($name, 'ok', 'AntiSpam ist deaktiviert; Kommentare sind global abgeschaltet und kein Kontaktformular-Plugin ist aktiv.');
            }

            return $this->buildCheck(
                $name,
                'warning',
                'AntiSpam ist deaktiviert: ' . implode(' und ', $targets) . ' werden ohne Honeypot-, Mindestzeit-, User-Agent-, Link- und Blacklist-Prüfung angenommen. Unter Sicherheit → Anti-Spam aktivieren.'
            );
        }

        $minimumSeconds = max(0, min(60, (int) ($settings['antispam_min_time'] ?? 0)));
        $maxLinks = max(0, min(50, (int) ($settings['antispam_max_links'] ?? 0)));
        $activeRules = array_values(array_filter([
            ($settings['antispam_honeypot'] ?? '0') === '1' ? 'Honeypot' : '',
            $minimumSeconds > 0 ? 'Mindestzeit ' . $minimumSeconds . ' s' : '',
            ($settings['antispam_block_empty_ua'] ?? '0') === '1' ? 'User-Agent-Prüfung' : '',
            $maxLinks > 0 ? 'höchstens ' . $maxLinks . ' Links' : '',
            'Blacklist',
        ]));

        return $this->buildCheck(
            $name,
            'ok',
            'Kommentar-Runtime nutzt die zentrale AntiSpam-Auswertung (aktiv: ' . implode(', ', $activeRules) . ').' . $contactDetail
        );
    }

    private function fileContains(string $path, string $needle): bool
    {
        return $this->fileContainsAny($path, [$needle]);
    }

    /**
     * @return array{ok:bool,active:bool,detail:string}
     */
    private function inspectContactAntispamRuntime(): array
    {
        if (!class_exists('CMS_Contact_Frontend', false) || !defined('CMS_CONTACT_PLUGIN_DIR')) {
            return [
                'ok' => true,
                'active' => false,
                'detail' => 'Kein aktives Kontaktformular-Plugin erkannt; zusätzlicher Formular-Check nicht erforderlich.',
            ];
        }

        try {
            $reflection = new \ReflectionClass('CMS_Contact_Frontend');
            $frontendFile = $reflection->getFileName();
            $templateFiles = glob(rtrim((string) CMS_CONTACT_PLUGIN_DIR, '/\\') . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'template-*.php') ?: [];
        } catch (\Throwable $e) {
            $this->logFailure('security.audit.contact_antispam_inspect_failed', 'Kontaktformular-Runtime konnte für das Sicherheits-Audit nicht geprüft werden.', [
                'exception' => $e::class,
            ]);

            return [
                'ok' => false,
                'active' => true,
                'detail' => 'Aktives Kontaktformular-Plugin konnte nicht zuverlässig auf zentrale AntiSpam-Verdrahtung geprüft werden.',
            ];
        }

        $frontendUsesCentralService = is_string($frontendFile)
            && $frontendFile !== ''
            && $this->fileContains($frontendFile, 'AntispamService::getInstance()->evaluate');

        $templatesContainTimestamp = $templateFiles !== [];
        foreach ($templateFiles as $templateFile) {
            if (!$this->fileContains($templateFile, 'contact_started_at')) {
                $templatesContainTimestamp = false;
                break;
            }
        }

        if ($frontendUsesCentralService && $templatesContainTimestamp) {
            return [
                'ok' => true,
                'active' => true,
                'detail' => 'Aktive Kontaktformulare nutzen ebenfalls die zentrale AntiSpam-Auswertung inklusive Formular-Timestamp für `antispam_min_time`.',
            ];
        }

        return [
            'ok' => false,
            'active' => true,
            'detail' => 'Aktive Kontaktformulare sind nicht vollständig an die zentrale AntiSpam-Auswertung oder den Formular-Timestamp angebunden.',
        ];
    }

    /** @param array<int,string> $needles */
    private function fileContainsAny(string $path, array $needles): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $content = file_get_contents($path, false, null, 0, self::MAX_HTACCESS_BYTES);
        if (!is_string($content) || $content === '') {
            return false;
        }

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($content, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function inspectHtaccessHeaders(string $abspath): array
    {
        $path = $abspath . '.htaccess';
        $content = '';

        if (is_file($path) && is_readable($path)) {
            $warning = null;
            set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
                $warning = $message;
                return true;
            });

            try {
                $rawContent = file_get_contents($path, false, null, 0, self::MAX_HTACCESS_BYTES + 1);
            } finally {
                restore_error_handler();
            }

            if (is_string($rawContent)) {
                if (strlen($rawContent) > self::MAX_HTACCESS_BYTES) {
                    $this->logFailure('security.audit.htaccess_limit', '.htaccess-Prüfung auf maximale Dateigröße begrenzt.', [
                        'path' => '.htaccess',
                        'max_bytes' => self::MAX_HTACCESS_BYTES,
                    ]);
                    $rawContent = substr($rawContent, 0, self::MAX_HTACCESS_BYTES);
                }

                $content = $rawContent;
            } elseif ($warning !== null) {
                $this->logFailure('security.audit.htaccess_read_failed', '.htaccess konnte für das Sicherheits-Audit nicht gelesen werden.', [
                    'path' => '.htaccess',
                ]);
            }
        }

        $normalizedContent = preg_replace('/^\s*#.*$/m', '', $content) ?: $content;

        return [
            'exists' => $content !== '',
            'csp_setifempty' => preg_match('/Header\s+always\s+setifempty\s+Content-Security-Policy\b/i', $normalizedContent) === 1,
            'csp_has_unsafe_inline' => str_contains($normalizedContent, "'unsafe-inline'"),
            'csp_has_unsafe_eval' => str_contains($normalizedContent, "'unsafe-eval'"),
            'report_only_present' => preg_match('/Header\s+always\s+set(?:ifempty)?\s+Content-Security-Policy-Report-Only\b/i', $normalizedContent) === 1,
            'trusted_types_present' => stripos($normalizedContent, 'trusted-types') !== false,
            'hsts_include_subdomains' => stripos($normalizedContent, 'includeSubDomains') !== false,
            'hsts_preload' => stripos($normalizedContent, 'preload') !== false,
            'proxy_https_detection' => stripos($normalizedContent, 'X-Forwarded-Proto') !== false,
        ];
    }

    private function canAccess(): bool
    {
        return class_exists('\CMS\Auth')
            && \CMS\Auth::instance()->isAdmin()
            && \CMS\Auth::instance()->hasCapability('manage_settings');
    }

    /**
     * @return array{passed:int, warning:int, critical:int, total:int}
     */
    private function summarizeChecks(array $checks): array
    {
        $summary = ['passed' => 0, 'warning' => 0, 'critical' => 0, 'total' => count($checks)];

        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? 'warning');
            if ($status === 'ok') {
                $summary['passed']++;
            } elseif ($status === 'critical') {
                $summary['critical']++;
            } else {
                $summary['warning']++;
            }
        }

        return $summary;
    }

    /**
     * @return array<int, array<string, scalar|null>>
     */
    private function fetchRecentAuditLog(): array
    {
        try {
            $rows = $this->db->get_results(
                "SELECT created_at, action, user_id, description, category, severity, ip_address, metadata
                 FROM {$this->prefix}audit_log
                 WHERE category IN (?, ?)
                 ORDER BY created_at DESC
                 LIMIT " . self::MAX_AUDIT_LOG_ROWS,
                $this->getAuditLogCategories()
            ) ?: [];

            return array_map(fn (mixed $row): array => $this->normalizeAuditLogRow($row), $rows);
        } catch (\Throwable $e) {
            $this->logFailure('security.audit.log_fetch_failed', 'Audit-Log konnte für das Sicherheits-Audit nicht geladen werden.', [
                'exception' => $e::class,
            ]);

            return [];
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private function normalizeAuditLogRow(mixed $row): array
    {
        $data = is_object($row) ? get_object_vars($row) : (is_array($row) ? $row : []);
        $metadataPreview = '';

        if (!empty($data['metadata']) && is_string($data['metadata'])) {
            $metadataPreview = $this->truncateText($this->sanitizeAuditText($data['metadata']), self::MAX_AUDIT_DETAIL_LENGTH);
        }

        $details = $this->sanitizeAuditText((string) ($data['description'] ?? ''));
        if ($details === '' && $metadataPreview !== '') {
            $details = $metadataPreview;
        }

        return [
            'created_at' => (string) ($data['created_at'] ?? ''),
            'action' => $this->truncateText((string) ($data['action'] ?? ''), 120),
            'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
            'details' => $this->truncateText($details, self::MAX_AUDIT_DETAIL_LENGTH),
            'category' => $this->truncateText((string) ($data['category'] ?? ''), 40),
            'severity' => $this->truncateText((string) ($data['severity'] ?? ''), 20),
            'ip_address' => $this->maskIpAddress((string) ($data['ip_address'] ?? '')),
        ];
    }

    /**
     * @return array{name:string, status:string, detail:string}
     */
    private function buildCheck(string $name, string $status, string $detail): array
    {
        if (!in_array($status, ['ok', 'warning', 'critical'], true)) {
            $status = 'warning';
        }

        return [
            'name' => $this->truncateText($name, self::MAX_CHECK_NAME_LENGTH),
            'status' => $status,
            'detail' => $this->truncateText($detail, self::MAX_CHECK_DETAIL_LENGTH),
        ];
    }

    private function truncateText(string $text, int $maxLength): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return $text;
        }

        return cms_truncate_text($text, $maxLength);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logFailure(string $action, string $message, array $context = []): void
    {
        $sanitizedContext = $this->sanitizeAuditContext($context);

        Logger::instance()->withChannel('admin.security-audit')->warning($message, $sanitizedContext);
        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            $action,
            $message,
            'security_audit',
            null,
            $sanitizedContext,
            'warning'
        );
    }

    /**
     * @return array<int,string>
     */
    private function getAuditLogCategories(): array
    {
        return [AuditLogger::CAT_SECURITY, AuditLogger::CAT_AUTH];
    }

    /**
     * @return array<int, array{name:string,status:string,detail:string}>
     */
    private function buildConfigPermissionChecks(string $abspath): array
    {
        $checks = [];

        $configFiles = [
            ['label' => 'config.php', 'path' => $abspath . 'config.php', 'required' => true],
            ['label' => 'config/app.php', 'path' => $abspath . 'config/app.php', 'required' => false],
        ];

        foreach ($configFiles as $configFile) {
            $path = (string) ($configFile['path'] ?? '');
            $label = (string) ($configFile['label'] ?? basename($path));
            $required = !empty($configFile['required']);

            if (!is_file($path)) {
                if ($required) {
                    $checks[] = $this->buildCheck(
                        $label . ' vorhanden',
                        'critical',
                        $label . ' fehlt oder ist nicht lesbar.'
                    );
                }

                continue;
            }

            $mode = @fileperms($path);
            if (!is_int($mode)) {
                $checks[] = $this->buildCheck($label . ' Berechtigungen', 'warning', 'Dateiberechtigungen von ' . $label . ' konnten nicht gelesen werden.');
                continue;
            }

            // Entscheidend ist, dass Gruppe/Welt nicht schreiben dürfen – schreibgeschützte Modi (0444/0440/0400) sind ebenfalls sicher.
            $perms = sprintf('%04o', $mode & 0o7777);
            $configOk = ($mode & 0o022) === 0;
            $checks[] = $this->buildCheck(
                $label . ' Berechtigungen (' . $perms . ')',
                $configOk ? 'ok' : 'warning',
                $configOk
                    ? 'Dateiberechtigungen sind korrekt.'
                    : ($label . ' ist für Gruppe oder alle beschreibbar (empfohlen: 640, 600 oder 644).')
            );
        }

        return $checks;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function sanitizeAuditContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = $this->sanitizeAuditText($value);
            } elseif (is_array($value)) {
                $context[$key] = $this->sanitizeAuditContext($value);
            }
        }

        return $context;
    }

    private function sanitizeAuditText(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/([A-Z0-9._%+-])[A-Z0-9._%+-]*@([A-Z0-9.-]+\.[A-Z]{2,})/iu', '$1***@$2', $value) ?? $value;
        $value = preg_replace('/\b(\d{1,3}\.\d{1,3}\.)(\d{1,3})\.(\d{1,3})\b/', '$1***.***', $value) ?? $value;

        return $this->truncateText($value, self::MAX_AUDIT_DETAIL_LENGTH);
    }

    private function maskIpAddress(string $ipAddress): string
    {
        $ipAddress = trim($ipAddress);
        if ($ipAddress === '') {
            return '';
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ipAddress);
            if (count($parts) === 4) {
                return $parts[0] . '.' . $parts[1] . '.***.***';
            }
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $segments = explode(':', $ipAddress);
            if (count($segments) > 2) {
                return implode(':', array_slice($segments, 0, 2)) . ':****:****';
            }
        }

        return $this->truncateText($ipAddress, 45);
    }
}
