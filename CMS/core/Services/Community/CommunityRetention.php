<?php
/**
 * Aufbewahrungsregeln der Community (PR #5): Löschfristen für personenbezogene und betriebliche Daten, registriert bei
 * `DataRetentionService` (täglicher Cron, Übersicht *System → Datenaufbewahrung* mit Trockenlauf).
 *
 * Die Regeln gelten auch bei abgeschaltetem Modul: die Daten bleiben beim Abschalten erhalten, ihre Löschfristen laufen weiter.
 * Gelöscht wird blockweise (BLOCK Zeilen je Durchgang, höchstens BLOCKS Durchgänge je Lauf) über die Indizes der Tabellen, nie mit
 * `UPDATE/DELETE … LIMIT` (nicht portabel): erst die IDs, dann die Zeilen. Fristen in Tagen, 0 = nie; Filter
 * `cms_community_retention_days` ändert sie (mindestens 7 Tage, höchstens 3650; `orphan_uploads` nur 0 oder 1: der Dienst räumt fest nach 24 Stunden).
 *
 * | Regel | Daten | Standard | Aktion |
 * |---|---|---|---|
 * | `community.ip_hash` | `community_posts.ip_hash` | Einstellung `ip_hash_days` (30) | anonymisieren |
 * | `community.read_states` | `community_read_states` nach `updated_at` | 180 | löschen |
 * | `community.orphan_uploads` | Anhänge ohne Beitrag | 1 (24 Stunden) | löschen samt Datei |
 * | `community.drafts` | `community_drafts` nach `updated_at` | 30 | löschen |
 * | `community.reports` | erledigte Meldungen nach `handled_at` | 365 | löschen |
 * | `community.moderation_log` | Protokoll nach `created_at` | 365 | löschen |
 * | `community.warnings` | Verwarnungen: 730 Tage nach Ablauf, ohne Ablauf 1825 Tage nach Anlage | 730 | löschen |
 * | `community.bans` | Sperren: 365 Tage nach Aufheben/Ablauf; dauerhafte, nicht aufgehobene bleiben | 365 | löschen |
 * | `community.revisions` | Bearbeitungsverlauf nach `created_at`; die jüngste Fassung je Beitrag bleibt | 365 | löschen |
 *
 * Bewusst ohne Frist (mit dem Konto gelöscht, siehe `CommunityPrivacy`): Lesemarken der Foren, Abos, Reaktionen, Stimmen, Ignorierliste,
 * Abzeichen. Ohne Frist und ohne personenbezogene Zeilen: `community_stats_daily` (Verdichtung). Offen (kein Schema-Feld, v27): ein
 * Löschzeitpunkt für Beiträge im Status `deleted`/`rejected` (`community_posts.deleted_at`).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community;

use CMS\Database;
use CMS\Hooks;
use CMS\Logger;
use CMS\Services\Community\Service\AttachmentService;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Retention\RetentionRule;

if (!defined('ABSPATH')) {
    exit;
}

final class CommunityRetention
{
    public const PREFIX = 'community.';
    public const BLOCK = 500;
    public const BLOCKS = 20;
    public const MIN_DAYS = 7;
    public const MAX_DAYS = 3650;
    /** Verwarnungen ohne Ablauf: Tage nach der Anlage (Höchstdauer, §7 der Übergabe). */
    public const WARNING_OPEN_DAYS = 1825;

    /** Standardfristen in Tagen (`ip_hash` kommt aus der Einstellung `ip_hash_days`). */
    public const DEFAULTS = [
        'read_states' => 180,
        'orphan_uploads' => 1,
        'drafts' => 30,
        'reports' => 365,
        'moderation_log' => 365,
        'warnings' => 730,
        'bans' => 365,
        'revisions' => 365,
    ];

    /** Bezeichnung und Aktion je Regel (ohne Präfix). */
    private const META = [
        'ip_hash' => ['IP-Kennung an Beiträgen', 'HMAC der gekürzten IP, nur für die Moderation; die Frist stellt die Einstellung „IP-Kennung speichern“ ein.', RetentionRule::ACTION_ANONYMIZE],
        'read_states' => ['Community: Lesestatus', 'Letzter gelesener Beitrag je Mitglied und Thema.', RetentionRule::ACTION_DELETE],
        'orphan_uploads' => ['Community: verwaiste Uploads', 'Anhänge, die nie an einen Beitrag gehängt wurden (samt Datei).', RetentionRule::ACTION_DELETE],
        'drafts' => ['Community: Entwürfe', 'Auf dem Server gespeicherte Entwürfe.', RetentionRule::ACTION_DELETE],
        'reports' => ['Community: erledigte Meldungen', 'Meldungen, die ein Moderator abgeschlossen hat.', RetentionRule::ACTION_DELETE],
        'moderation_log' => ['Community: Moderationsprotokoll', 'Protokoll der Moderationsaktionen (ohne IP-Adressen).', RetentionRule::ACTION_DELETE],
        'warnings' => ['Community: Verwarnungen', 'Verwarnungen 2 Jahre nach Ablauf; ohne Ablauf spätestens 5 Jahre nach Anlage.', RetentionRule::ACTION_DELETE],
        'bans' => ['Community: Sperren', 'Aufgehobene oder abgelaufene Sperren samt Grund und interner Notiz; dauerhafte Sperren bleiben.', RetentionRule::ACTION_DELETE],
        'revisions' => ['Community: Bearbeitungsverlauf', 'Frühere Fassungen von Beiträgen; die jüngste bleibt.', RetentionRule::ACTION_DELETE],
    ];

    private string $prefix;

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(private readonly ?Database $injected = null, ?\Closure $clock = null, private readonly ?Settings $settings = null)
    {
        $this->prefix = $this->db()->getPrefix();
        $this->clock = $clock ?? static fn (): int => time();
    }

    private function db(): Database
    {
        return $this->injected ?? Database::instance();
    }

    /** Meldet alle Regeln bei `DataRetentionService` an (idempotent: gleiche Schlüssel ersetzen sich). */
    public static function registerAll(): void
    {
        $retention = new self();
        foreach ($retention->rules() as $rule) {
            \CMS\Services\DataRetentionService::registerRule($rule);
        }
    }

    // ── Fristen ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Wirksame Fristen je Regel (ohne Präfix), 0 = nie.
     *
     * @return array<string, int>
     */
    public function days(): array
    {
        $settings = $this->settings ?? Settings::getInstance();
        $defaults = ['ip_hash' => $settings->int('ip_hash_days')] + self::DEFAULTS;
        $filtered = Hooks::applyFilters('cms_community_retention_days', $defaults);

        return self::normalize(is_array($filtered) ? $filtered : [], $defaults);
    }

    /**
     * @param array<string, mixed> $configured
     * @param array<string, int> $defaults
     * @return array<string, int>
     */
    public static function normalize(array $configured, array $defaults): array
    {
        $days = [];
        foreach ($defaults as $key => $default) {
            $value = isset($configured[$key]) && is_numeric($configured[$key]) ? (int) $configured[$key] : (int) $default;
            if ($key === 'orphan_uploads') {
                $days[$key] = $value <= 0 ? 0 : 1; // fest 24 Stunden im Dienst: nur ein oder aus
                continue;
            }
            // `ip_hash`: die Einstellung begrenzt auf 0 bis 90; 0 = nie speichern, also nichts zu löschen.
            $days[$key] = $value <= 0 ? 0 : max(self::MIN_DAYS, min(self::MAX_DAYS, $value));
        }

        return $days;
    }

    // ── Regeln ──────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return list<RetentionRule> */
    public function rules(): array
    {
        $rules = [];
        foreach (self::META as $key => [$label, $description, $action]) {
            $rules[] = new RetentionRule(
                self::PREFIX . $key,
                $label,
                $description,
                fn (): int => $this->days()[$key] ?? 0,
                fn (): int => $this->countDue($key),
                fn (): int => $this->prune($key),
                $key === 'ip_hash' ? '/admin/community-settings' : '',
                $action
            );
        }

        return $rules;
    }

    private function cutoff(int $days): string
    {
        return gmdate('Y-m-d H:i:s', ($this->clock)() - $days * 86400);
    }

    /** Einträge, die die Regel jetzt erfassen würde (Trockenlauf). */
    public function countDue(string $key): int
    {
        $days = $this->days()[$key] ?? 0;
        if ($days <= 0) {
            return 0;
        }
        [$from, $where, $params] = $this->scope($key, $this->cutoff($days));

        return (int) $this->db()->execute("SELECT COUNT(*) FROM {$from} WHERE {$where}", $params)->fetchColumn();
    }

    /**
     * Löscht oder anonymisiert blockweise; liefert die Zahl der Zeilen.
     */
    public function prune(string $key): int
    {
        $days = $this->days()[$key] ?? 0;
        if ($days <= 0) {
            return 0;
        }
        $done = 0;
        try {
            if ($key === 'orphan_uploads') {
                // Zeile und Datei entfernt der Dienst (Frist fest 24 Stunden, `days` schaltet die Regel nur ein oder aus).
                for ($block = 0; $block < self::BLOCKS; $block++) {
                    $removed = AttachmentService::instance()->purgeOrphans(self::BLOCK);
                    $done += $removed;
                    if ($removed < self::BLOCK) {
                        break;
                    }
                }

                return $done;
            }
            for ($block = 0; $block < self::BLOCKS; $block++) {
                $ids = $this->dueIds($key, $this->cutoff($days));
                if ($ids === []) {
                    break;
                }
                $done += $this->apply($key, $ids);
                if (count($ids) < self::BLOCK) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('community')->warning('Aufbewahrung {rule} fehlgeschlagen: {exception}', ['rule' => $key, 'exception' => $e]);
        }

        return $done;
    }

    /**
     * Tabelle, Bedingung und Parameter einer Regel (alle Spalten über Indizes: `created_at`, `updated_at`, `handled_at`, `expires_at`).
     *
     * @return array{0: string, 1: string, 2: list<scalar>}
     */
    private function scope(string $key, string $cutoff): array
    {
        $p = $this->prefix;

        return match ($key) {
            'ip_hash' => ["{$p}community_posts", 'ip_hash IS NOT NULL AND created_at < ?', [$cutoff]],
            'read_states' => ["{$p}community_read_states", 'updated_at < ?', [$cutoff]],
            'orphan_uploads' => ["{$p}community_attachments", 'post_id IS NULL AND created_at < ?', [$cutoff]],
            'drafts' => ["{$p}community_drafts", 'updated_at < ?', [$cutoff]],
            'reports' => ["{$p}community_reports", "status <> 'open' AND handled_at IS NOT NULL AND handled_at < ?", [$cutoff]],
            'moderation_log' => ["{$p}community_moderation_log", 'created_at < ?', [$cutoff]],
            'warnings' => [
                "{$p}community_warnings",
                '((expires_at IS NOT NULL AND expires_at < ?) OR (expires_at IS NULL AND created_at < ?))',
                [$cutoff, $this->cutoff(self::WARNING_OPEN_DAYS)],
            ],
            'bans' => [
                "{$p}community_bans",
                '((lifted_at IS NOT NULL AND lifted_at < ?) OR (lifted_at IS NULL AND ends_at IS NOT NULL AND ends_at < ?))',
                [$cutoff, $cutoff],
            ],
            'revisions' => [
                "{$p}community_post_revisions r",
                "r.created_at < ? AND EXISTS (SELECT 1 FROM {$p}community_post_revisions n WHERE n.post_id = r.post_id AND n.id > r.id)",
                [$cutoff],
            ],
            default => throw new \InvalidArgumentException('Unbekannte Regel: ' . $key),
        };
    }

    /**
     * Schlüssel der fälligen Zeilen (Primärschlüssel oder Spaltenpaar), höchstens BLOCK.
     *
     * @return list<array<string, mixed>>
     */
    private function dueIds(string $key, string $cutoff): array
    {
        [$from, $where, $params] = $this->scope($key, $cutoff);
        $columns = match ($key) {
            'read_states' => 'user_id, topic_id',
            'drafts' => 'user_id, target_type, target_id',
            'revisions' => 'r.id AS id',
            default => 'id',
        };
        $order = match ($key) {
            'read_states', 'drafts' => 'updated_at ASC',
            'revisions' => 'r.id ASC',
            default => 'id ASC',
        };

        return $this->db()->execute("SELECT {$columns} FROM {$from} WHERE {$where} ORDER BY {$order} LIMIT ?", [...$params, self::BLOCK])->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<array<string, mixed>> $rows */
    private function apply(string $key, array $rows): int
    {
        $p = $this->prefix;
        $db = $this->db();
        if ($key === 'read_states') {
            foreach ($rows as $row) {
                $db->execute("DELETE FROM {$p}community_read_states WHERE user_id = ? AND topic_id = ?", [(int) $row['user_id'], (int) $row['topic_id']]);
            }

            return count($rows);
        }
        if ($key === 'drafts') {
            foreach ($rows as $row) {
                $db->execute("DELETE FROM {$p}community_drafts WHERE user_id = ? AND target_type = ? AND target_id = ?", [(int) $row['user_id'], (string) $row['target_type'], (int) $row['target_id']]);
            }

            return count($rows);
        }
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        if ($key === 'ip_hash') {
            $db->execute("UPDATE {$p}community_posts SET ip_hash = NULL WHERE id IN ({$marks})", $ids);

            return count($ids);
        }
        $table = match ($key) {
            'reports' => 'community_reports',
            'moderation_log' => 'community_moderation_log',
            'warnings' => 'community_warnings',
            'bans' => 'community_bans',
            'revisions' => 'community_post_revisions',
            default => throw new \InvalidArgumentException('Unbekannte Regel: ' . $key),
        };
        $db->execute("DELETE FROM {$p}{$table} WHERE id IN ({$marks})", $ids);

        return count($ids);
    }
}
