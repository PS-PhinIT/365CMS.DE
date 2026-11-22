<?php
/**
 * DSGVO-Anbindung der Community (PR #5): Auskunft (`dsgvo_export_data`, Selbstauskunft `cms_member_export_data`) und Löschung
 * (`dsgvo_delete_data`, `cms_user_deleted`) der Daten eines Mitglieds. Die Hooks gelten auch bei abgeschaltetem Modul (die Daten bleiben
 * beim Abschalten erhalten).
 *
 * Auskunft: alle Inhalte und Zuordnungen des Mitglieds als JSON in der privaten Ablage (`community/privacy-exports/`, Download über die
 * Dateiliste des Admins, automatische Entfernung nach EXPORT_KEEP_DAYS Tagen) und in der Selbstauskunft des Mitgliederbereichs. Je Tabelle
 * höchstens MAX_ROWS Zeilen (`truncated` nennt gekürzte Bereiche). Die IP-Kennung (`ip_hash`, HMAC einer gekürzten IP) steht nicht im
 * Export: sie ist ein Pseudonym nur für die Moderation; der Export nennt die Zahl der Beiträge mit gespeicherter Kennung.
 *
 * Löschung (Standard: Anonymisieren, §7 der Übergabe; ein Modus „Beiträge löschen“ gibt es noch nicht):
 * - Beiträge, Themen, Anhänge, Verlauf: der Bezug zum Konto entfällt (`user_id`/`edited_by` NULL, `ip_hash` NULL), der Inhalt bleibt unter
 *   „Gelöschtes Mitglied“ stehen. Die Dateien bleiben am Beitrag.
 * - Reaktionen: gelöscht, die Zähler (Beitrag, Thema, Statistik des Autors) werden zurückgebucht.
 * - Abos, Lesestatus, Lesemarken, Entwürfe, Stimmen, Ignorierliste (auch wer ihn ignoriert), Abzeichen, Statistik samt Signatur: gelöscht.
 * - Verwarnungen und Sperren des Mitglieds: gelöscht (die Spalten sind NOT NULL und lassen sich nicht anonymisieren); wo das Mitglied
 *   Aussteller ist, entfällt der Bezug.
 * - Meldungen und Moderationsprotokoll: Bezug zum Mitglied (Melder, Bearbeiter, Akteur) entfällt, der Eintrag bleibt.
 * - Wartende Benachrichtigungen mit dem Mitglied als Auslöser: gelöscht.
 * `$userId` leer (reiner E-Mail-Antrag ohne Konto): die Community speichert keine Daten zu E-Mail-Adressen, nichts zu tun.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community;

use CMS\AuditLogger;
use CMS\Database;
use CMS\Logger;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\PrivateStorageService;

if (!defined('ABSPATH')) {
    exit;
}

final class CommunityPrivacy
{
    public const EXPORT_AREA = 'community/privacy-exports';
    public const EXPORT_KEEP_DAYS = 30;
    public const MAX_ROWS = 20000;
    private const REACTION_BLOCK = 200;
    private const FILE_PATTERN = '/^community-\d{8}-\d{6}-[a-f0-9]{12}\.json$/';

    private string $prefix;

    public function __construct(private readonly ?Database $injected = null)
    {
        $this->prefix = $this->db()->getPrefix();
    }

    private function db(): Database
    {
        return $this->injected ?? Database::instance();
    }

    // ── Auskunft ────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Daten des Mitglieds nach Bereichen; leere Bereiche fehlen. `$maxRows` kürzt je Bereich (Selbstauskunft kleiner als die Datei).
     *
     * @return array<string, mixed>
     */
    public function collect(int $userId, int $maxRows = self::MAX_ROWS): array
    {
        if ($userId < 1) {
            return [];
        }
        $p = $this->prefix;
        $maxRows = max(1, min(self::MAX_ROWS, $maxRows));
        $truncated = [];
        $sections = [
            'topics' => "SELECT id, forum_id, title, status, created_at, reply_count FROM {$p}community_topics WHERE user_id = ? ORDER BY id ASC",
            'posts' => "SELECT id, topic_id, forum_id, status, body, created_at, edited_at, edit_count, reaction_count FROM {$p}community_posts WHERE user_id = ? ORDER BY id ASC",
            'revisions' => "SELECT post_id, title, body, edit_reason, created_at FROM {$p}community_post_revisions WHERE edited_by = ? ORDER BY id ASC",
            'attachments' => "SELECT id, post_id, original_name, mime_type, file_size, created_at FROM {$p}community_attachments WHERE user_id = ? ORDER BY id ASC",
            'drafts' => "SELECT target_type, target_id, title, body, updated_at FROM {$p}community_drafts WHERE user_id = ? ORDER BY updated_at ASC",
            'reactions' => "SELECT post_id, reaction, created_at FROM {$p}community_reactions WHERE user_id = ? ORDER BY post_id ASC",
            'subscriptions' => "SELECT target_type, target_id, mode, created_at FROM {$p}community_subscriptions WHERE user_id = ? ORDER BY target_type ASC, target_id ASC",
            'read_states' => "SELECT topic_id, last_read_post_id, updated_at FROM {$p}community_read_states WHERE user_id = ? ORDER BY topic_id ASC",
            'forum_read_marks' => "SELECT forum_id, marked_at FROM {$p}community_forum_read_marks WHERE user_id = ? ORDER BY forum_id ASC",
            'poll_votes' => "SELECT poll_id, option_id, created_at FROM {$p}community_poll_votes WHERE user_id = ? ORDER BY poll_id ASC",
            'ignored_members' => "SELECT ignored_user_id, created_at FROM {$p}community_ignores WHERE user_id = ? ORDER BY ignored_user_id ASC",
            'badges' => "SELECT badge_id, reason, granted_at FROM {$p}community_user_badges WHERE user_id = ? ORDER BY badge_id ASC",
            'reports_filed' => "SELECT target_type, target_id, reason, message, status, created_at FROM {$p}community_reports WHERE reporter_id = ? ORDER BY id ASC",
            'warnings' => "SELECT reason, points, expires_at, revoked_at, created_at FROM {$p}community_warnings WHERE user_id = ? ORDER BY id ASC",
            'bans' => "SELECT scope, reason, internal_note, starts_at, ends_at, lifted_at, created_at FROM {$p}community_bans WHERE user_id = ? ORDER BY id ASC",
            'moderation_actions' => "SELECT action, target_type, target_id, reason, created_at FROM {$p}community_moderation_log WHERE actor_id = ? ORDER BY id ASC",
        ];
        $result = ['user_id' => $userId];
        foreach ($sections as $name => $sql) {
            $rows = $this->db()->execute($sql . ' LIMIT ?', [$userId, $maxRows + 1])->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            if (count($rows) > $maxRows) {
                $rows = array_slice($rows, 0, $maxRows);
                $truncated[] = $name;
            }
            if ($rows !== []) {
                $result[$name] = $rows;
            }
        }
        $stats = $this->db()->execute(
            "SELECT topic_count, post_count, reactions_received, reactions_given, solutions_count, reputation, trust_level, rank_id, custom_title, signature, settings_json, last_post_at, last_seen_at FROM {$p}community_user_stats WHERE user_id = ?",
            [$userId]
        )->fetch(\PDO::FETCH_ASSOC);
        if (is_array($stats)) {
            $settings = json_decode((string) ($stats['settings_json'] ?? ''), true);
            $stats['settings'] = is_array($settings) ? $settings : [];
            unset($stats['settings_json']);
            $result['profile'] = $stats;
        }
        $withHash = (int) $this->db()->execute("SELECT COUNT(*) FROM {$p}community_posts WHERE user_id = ? AND ip_hash IS NOT NULL", [$userId])->fetchColumn();
        if ($withHash > 0) {
            $result['ip_hash_posts'] = $withHash;
        }
        if ($truncated !== []) {
            $result['truncated'] = $truncated;
        }

        return count($result) > 1 ? $result : [];
    }

    /** Hook `dsgvo_export_data`. Rückgabe: Dateiname oder '' (nichts zu exportieren oder Fehler). */
    public static function handleExport(mixed $userId = null, mixed $email = null): string
    {
        $userId = is_numeric($userId) ? (int) $userId : 0;
        try {
            $data = (new self())->collect($userId);
            if ($data === []) {
                return '';
            }
            $name = 'community-' . gmdate('Ymd-His') . '-' . substr(hash('sha256', $userId . '|' . microtime(true) . '|' . random_int(0, PHP_INT_MAX)), 0, 12) . '.json';
            $json = json_encode([
                'type' => 'community',
                'generated_at' => gmdate('c'),
                'subject' => ['user_id' => $userId],
                'data' => $data,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $dir = PrivateStorageService::getInstance()->path(self::EXPORT_AREA);
            if (!is_string($json) || file_put_contents($dir . $name, $json, LOCK_EX) === false) {
                throw new \RuntimeException('Export-Datei konnte nicht geschrieben werden.');
            }
            @chmod($dir . $name, 0640);
            AuditLogger::instance()->log(AuditLogger::CAT_SETTING, 'community.privacy.export', 'DSGVO-Auskunft: Community-Daten exportiert', 'community_member', $userId, ['file' => $name], 'info');

            return $name;
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('community')->error('DSGVO-Export der Community fehlgeschlagen: {exception}', ['exception' => $e]);

            return '';
        }
    }

    /** Selbstauskunft im Mitgliederbereich (Filter `cms_member_export_data`): Schlüssel `community`, je Bereich höchstens 500 Zeilen. */
    public static function memberExport(mixed $export = [], mixed $userId = 0): mixed
    {
        if (!is_array($export)) {
            return $export;
        }
        try {
            $data = (new self())->collect((int) $userId, 500);
            if ($data !== []) {
                $export['community'] = $data;
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('community')->warning('Selbstauskunft: Community nicht ergänzt: {exception}', ['exception' => $e]);
        }

        return $export;
    }

    /** @return list<array{name: string, size: int, created_at: string}> */
    public static function listExports(): array
    {
        $files = [];
        foreach (glob(PrivateStorageService::getInstance()->path(self::EXPORT_AREA) . 'community-*.json') ?: [] as $path) {
            $name = basename($path);
            if (preg_match(self::FILE_PATTERN, $name) === 1) {
                $files[] = ['name' => $name, 'size' => (int) filesize($path), 'created_at' => gmdate('Y-m-d H:i:s', (int) filemtime($path))];
            }
        }
        usort($files, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $files;
    }

    /** Entfernt Exportdateien, die älter als `$days` Tage sind (täglicher Cron); liefert die Zahl. */
    public static function pruneExports(int $days = self::EXPORT_KEEP_DAYS): int
    {
        $deleted = 0;
        $cutoff = time() - max(1, $days) * 86400;
        foreach (self::listExports() as $file) {
            $path = PrivateStorageService::getInstance()->resolve(self::EXPORT_AREA, $file['name']);
            if ($path !== null && (int) filemtime($path) < $cutoff && @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    // ── Löschung ────────────────────────────────────────────────────────────────────────────────────────────────

    /** Hook `dsgvo_delete_data` und `cms_user_deleted`: Zahl der anonymisierten und gelöschten Zeilen insgesamt. */
    public static function handleDelete(mixed $userId = null, mixed $email = null): int
    {
        $userId = is_numeric($userId) ? (int) $userId : 0;
        if ($userId < 1) {
            return 0;
        }
        try {
            $counts = (new self())->erase($userId);
            $total = array_sum($counts);
            if ($total > 0) {
                AuditLogger::instance()->log(AuditLogger::CAT_SETTING, 'community.privacy.delete', 'DSGVO-Löschung: Community-Daten anonymisiert und gelöscht', 'community_member', $userId, $counts, 'warning');
            }

            return $total;
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('community')->error('DSGVO-Löschung der Community fehlgeschlagen: {exception}', ['exception' => $e]);

            return 0;
        }
    }

    /**
     * Anonymisiert und löscht die Daten eines Mitglieds (idempotent; ein zweiter Lauf ändert nichts).
     *
     * @return array<string, int> betroffene Zeilen je Schritt
     */
    public function erase(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }
        $db = $this->db();
        $p = $this->prefix;
        $counts = [];
        $now = gmdate('Y-m-d H:i:s');

        // 1. Reaktionen samt Zählern, blockweise (je Reaktion: Beitrag, Thema, Statistik des Autors).
        $counts['reactions'] = 0;
        while (true) {
            $rows = $db->execute(
                "SELECT r.post_id, p.topic_id, p.user_id AS author_id FROM {$p}community_reactions r INNER JOIN {$p}community_posts p ON p.id = r.post_id WHERE r.user_id = ? ORDER BY r.post_id ASC LIMIT ?",
                [$userId, self::REACTION_BLOCK]
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $down = 'CASE WHEN reaction_count > 0 THEN reaction_count - 1 ELSE 0 END';
                $db->execute("UPDATE {$p}community_posts SET reaction_count = {$down} WHERE id = ?", [(int) $row['post_id']]);
                $db->execute("UPDATE {$p}community_topics SET reaction_count = {$down} WHERE id = ?", [(int) $row['topic_id']]);
                if ($row['author_id'] !== null && (int) $row['author_id'] !== $userId) {
                    $db->execute("UPDATE {$p}community_user_stats SET reactions_received = CASE WHEN reactions_received > 0 THEN reactions_received - 1 ELSE 0 END, updated_at = ? WHERE user_id = ?", [$now, (int) $row['author_id']]);
                }
                $counts['reactions'] += $db->execute("DELETE FROM {$p}community_reactions WHERE post_id = ? AND user_id = ?", [(int) $row['post_id'], $userId])->rowCount();
            }
            if (count($rows) < self::REACTION_BLOCK) {
                break;
            }
        }

        // 2. Löschen (Zeilen, die ohne das Mitglied keinen Sinn haben).
        $deletes = [
            'subscriptions' => ["DELETE FROM {$p}community_subscriptions WHERE user_id = ?", [$userId]],
            'read_states' => ["DELETE FROM {$p}community_read_states WHERE user_id = ?", [$userId]],
            'forum_read_marks' => ["DELETE FROM {$p}community_forum_read_marks WHERE user_id = ?", [$userId]],
            'drafts' => ["DELETE FROM {$p}community_drafts WHERE user_id = ?", [$userId]],
            'poll_votes' => ["DELETE FROM {$p}community_poll_votes WHERE user_id = ?", [$userId]],
            'ignores' => ["DELETE FROM {$p}community_ignores WHERE user_id = ? OR ignored_user_id = ?", [$userId, $userId]],
            'badges' => ["DELETE FROM {$p}community_user_badges WHERE user_id = ?", [$userId]],
            'warnings' => ["DELETE FROM {$p}community_warnings WHERE user_id = ?", [$userId]],
            'bans' => ["DELETE FROM {$p}community_bans WHERE user_id = ?", [$userId]],
            'notify_queue' => ["DELETE FROM {$p}community_notify_queue WHERE actor_id = ?", [$userId]],
            'stats' => ["DELETE FROM {$p}community_user_stats WHERE user_id = ?", [$userId]],
        ];
        foreach ($deletes as $name => [$sql, $params]) {
            $counts[$name] = $db->execute($sql, $params)->rowCount();
        }

        // 3. Anonymisieren (Inhalt und Eintrag bleiben, der Bezug zum Konto entfällt).
        $nulls = [
            'topics' => ["UPDATE {$p}community_topics SET user_id = NULL WHERE user_id = ?", [$userId]],
            'topics_solved_by' => ["UPDATE {$p}community_topics SET solved_by = NULL WHERE solved_by = ?", [$userId]],
            'topics_last_poster' => ["UPDATE {$p}community_topics SET last_poster_id = NULL WHERE last_poster_id = ?", [$userId]],
            'topics_trashed_by' => ["UPDATE {$p}community_topics SET trashed_by = NULL WHERE trashed_by = ?", [$userId]],
            'forums_last_poster' => ["UPDATE {$p}community_forums SET last_poster_id = NULL WHERE last_poster_id = ?", [$userId]],
            'posts' => ["UPDATE {$p}community_posts SET user_id = NULL, ip_hash = NULL WHERE user_id = ?", [$userId]],
            'posts_edited_by' => ["UPDATE {$p}community_posts SET edited_by = NULL WHERE edited_by = ?", [$userId]],
            'revisions' => ["UPDATE {$p}community_post_revisions SET edited_by = NULL WHERE edited_by = ?", [$userId]],
            'attachments' => ["UPDATE {$p}community_attachments SET user_id = NULL WHERE user_id = ?", [$userId]],
            'reports_reporter' => ["UPDATE {$p}community_reports SET reporter_id = NULL WHERE reporter_id = ?", [$userId]],
            'reports_handled_by' => ["UPDATE {$p}community_reports SET handled_by = NULL WHERE handled_by = ?", [$userId]],
            'warnings_issued_by' => ["UPDATE {$p}community_warnings SET issued_by = NULL WHERE issued_by = ?", [$userId]],
            'warnings_revoked_by' => ["UPDATE {$p}community_warnings SET revoked_by = NULL WHERE revoked_by = ?", [$userId]],
            'bans_issued_by' => ["UPDATE {$p}community_bans SET issued_by = NULL WHERE issued_by = ?", [$userId]],
            'bans_lifted_by' => ["UPDATE {$p}community_bans SET lifted_by = NULL WHERE lifted_by = ?", [$userId]],
            'badges_granted_by' => ["UPDATE {$p}community_user_badges SET granted_by = NULL WHERE granted_by = ?", [$userId]],
            'moderation_log' => ["UPDATE {$p}community_moderation_log SET actor_id = NULL WHERE actor_id = ?", [$userId]],
        ];
        foreach ($nulls as $name => [$sql, $params]) {
            $counts[$name] = $db->execute($sql, $params)->rowCount();
        }

        try {
            CommunityAccess::instance()->forgetUser($userId);
        } catch (\Throwable) {
        }

        return $counts;
    }
}
