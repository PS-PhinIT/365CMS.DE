<?php
/**
 * Notification Service
 *
 * In-App-Benachrichtigungen für Mitglieder (Tabelle `notifications`): anlegen, als gelesen markieren,
 * zählen und auflisten (MD-17). Beim Anlegen werden die Benachrichtigungs-Einstellungen des Mitglieds
 * (`user_meta['notification_preferences']`) ausgewertet (MD-18). Ein E-Mail-Versand ist nicht Teil dieses
 * Services; die E-Mail-Optionen des Admin-Bereichs werden gespeichert, aber noch nicht versendet.
 *
 * Community (PR #2b, Vertrag DESIGN-2b §3.8): Typen `community.*` (COMMUNITY_TYPE_PATTERN) und Bündelung über `group_key` und
 * `event_count` (`notifyGrouped()`). `notifyGrouped()` läuft in der Empfänger-Transaktion des NotificationDispatcher: Es reicht
 * Datenbankfehler durch (damit `Transaction::run()` Deadlocks wiederholt) und löst keinen Hook aus. Den Hook
 * `cms_member_notification_created` feuert der Aufrufer nach dem Commit über `announceCreated()`, nie für zurückgenommene Zeilen.
 * Zeiten in `notifications` bleiben Ortszeit der Sitzung (TIMESTAMP, wie der übrige Core).
 *
 * @package CMS\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Hooks;
use CMS\Member\MemberSanitizer;

if (!defined('ABSPATH')) {
    exit;
}

final class NotificationService
{
    /** Erlaubte Typen laut Schema (`notifications.type`). */
    public const TYPES = ['system', 'message', 'comment', 'event'];

    /**
     * Präferenzschlüssel je Typ; `system` lässt sich nicht abbestellen (Sicherheits- und Kontohinweise).
     */
    public const PREFERENCE_KEYS = [
        'message' => 'notify_messages',
        'comment' => 'notify_comments',
        'event' => 'notify_events',
    ];

    /** Typen der Community (`community.reply`, `community.moderation` …); höchstens 40 Zeichen (`notifications.type` VARCHAR(50)). */
    public const COMMUNITY_TYPE_PATTERN = '/^community\.[a-z_]{1,30}$/D';

    private const TITLE_MAX = 255;
    private const MESSAGE_MAX = 2000;
    /** Länge von `notifications.group_key`. */
    private const GROUP_KEY_MAX = 120;
    /** Versuche des bedingten Erhöhens (CAS) eines gebündelten Hinweises, bevor ein neuer angelegt wird. */
    private const GROUP_ATTEMPTS = 2;

    private static ?self $instance = null;

    private Database $db;
    private string $prefix;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    /**
     * Legt eine Benachrichtigung an.
     *
     * @return int ID des neuen Eintrags; 0, wenn der Typ unbekannt ist, Titel und Text leer sind,
     *             das Mitglied diesen Typ abbestellt hat oder das Schreiben fehlschlägt.
     */
    public function create(int $userId, string $type, string $title, string $message = '', string $url = ''): int
    {
        $type = strtolower(trim($type));
        if ($userId <= 0 || !self::isKnownType($type)) {
            return 0;
        }

        $title = MemberSanitizer::text($title, self::TITLE_MAX);
        $message = MemberSanitizer::multiline($message, self::MESSAGE_MAX);
        if ($title === '' && $message === '') {
            return 0;
        }

        if (!$this->isTypeEnabledForUser($userId, $type)) {
            return 0;
        }

        $url = MemberSanitizer::notificationUrl($url);

        try {
            $this->db->execute(
                "INSERT INTO {$this->prefix}notifications (user_id, type, title, message, url, is_read) VALUES (?, ?, ?, ?, ?, 0)",
                [$userId, $type, $title, $message, $url !== '' ? $url : null]
            );
            $id = (int) $this->db->lastInsertId();
        } catch (\Throwable) {
            return 0;
        }

        if ($id > 0 && class_exists(Hooks::class)) {
            Hooks::doAction('cms_member_notification_created', $id, $userId, $type);
        }

        return $id;
    }

    /** Bekannter Typ: einer aus TYPES oder ein Community-Typ (`community.<name>`). */
    public static function isKnownType(string $type): bool
    {
        return in_array($type, self::TYPES, true) || preg_match(self::COMMUNITY_TYPE_PATTERN, $type) === 1;
    }

    /**
     * Gebündelter Hinweis (Community, Vertrag DESIGN-2b §3.8): Gibt es zum Gruppenschlüssel einen ungelesenen Hinweis, steigt sein
     * `event_count` per bedingtem UPDATE (CAS auf den gelesenen Zähler, höchstens GROUP_ATTEMPTS Versuche) und Titel, Text und Link
     * wechseln auf die Mehrzahl (`title_many`, `message_many`, `url_many`, `{count}` = neuer Zähler). Sonst entsteht eine neue Zeile
     * mit `event_count = 1`. Ohne Gruppenschlüssel entsteht immer eine neue Zeile.
     *
     * Datenbankfehler werden nicht gefangen, und es feuert kein Hook (der Aufrufer ruft nach dem Commit `announceCreated()`).
     * Ungültige Eingaben (Mitglied, Typ, leerer Titel und Text) ergeben `{id: 0, inserted: false}` ohne Anweisung.
     *
     * @param array{title?: string, message?: string, url?: string, title_many?: string, message_many?: string, url_many?: string} $texts
     * @return array{id: int, inserted: bool}
     */
    public function notifyGrouped(int $userId, string $type, ?string $groupKey, array $texts): array
    {
        $type = strtolower(trim($type));
        $title = MemberSanitizer::text((string) ($texts['title'] ?? ''), self::TITLE_MAX);
        $message = MemberSanitizer::multiline((string) ($texts['message'] ?? ''), self::MESSAGE_MAX);
        $url = MemberSanitizer::notificationUrl((string) ($texts['url'] ?? ''));
        $groupKey = $groupKey !== null ? MemberSanitizer::text($groupKey, self::GROUP_KEY_MAX) : null;
        if ($userId <= 0 || !self::isKnownType($type) || ($title === '' && $message === '') || $groupKey === '') {
            return ['id' => 0, 'inserted' => false];
        }
        $now = date('Y-m-d H:i:s');

        if ($groupKey !== null) {
            for ($attempt = 0; $attempt < self::GROUP_ATTEMPTS; $attempt++) {
                $row = $this->db->execute(
                    "SELECT id, event_count FROM {$this->prefix}notifications WHERE user_id = ? AND group_key = ? AND is_read = 0 ORDER BY id DESC LIMIT 1",
                    [$userId, $groupKey]
                )->fetch(\PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    break;
                }
                $count = (int) $row['event_count'];
                $vars = ['{count}' => (string) ($count + 1)];
                $manyTitle = MemberSanitizer::text(strtr((string) ($texts['title_many'] ?? $texts['title'] ?? ''), $vars), self::TITLE_MAX);
                $manyMessage = MemberSanitizer::multiline(strtr((string) ($texts['message_many'] ?? $texts['message'] ?? ''), $vars), self::MESSAGE_MAX);
                $manyUrl = MemberSanitizer::notificationUrl((string) ($texts['url_many'] ?? $texts['url'] ?? ''));
                $updated = $this->db->execute(
                    "UPDATE {$this->prefix}notifications SET event_count = event_count + 1, title = ?, message = ?, url = ?, created_at = ? WHERE id = ? AND is_read = 0 AND event_count = ?",
                    [$manyTitle !== '' ? $manyTitle : $title, $manyMessage, $manyUrl !== '' ? $manyUrl : null, $now, (int) $row['id'], $count]
                )->rowCount();
                if ($updated === 1) {
                    return ['id' => (int) $row['id'], 'inserted' => false];
                }
            }
        }

        $this->db->execute(
            "INSERT INTO {$this->prefix}notifications (user_id, type, title, message, url, group_key, event_count, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?)",
            [$userId, $type, $title, $message, $url !== '' ? $url : null, $groupKey, $now]
        );
        $id = (int) $this->db->lastInsertId();

        return ['id' => $id, 'inserted' => true];
    }

    /**
     * Hook `cms_member_notification_created` für einen neu angelegten Hinweis (nach dem Commit der Transaktion, die ihn geschrieben
     * hat; Vertrag DESIGN-2b §4.5). Jeder Verbraucher läuft einzeln abgesichert; wirft nie.
     */
    public function announceCreated(int $id, int $userId, string $type): void
    {
        if ($id <= 0 || $userId <= 0 || !class_exists(Hooks::class)) {
            return;
        }
        try {
            Hooks::doActionIsolated('cms_member_notification_created', static function (\Throwable $e): void {
                error_log('NotificationService::announceCreated: ' . $e->getMessage());
            }, $id, $userId, $type);
        } catch (\Throwable $e) {
            error_log('NotificationService::announceCreated: ' . $e->getMessage());
        }
    }

    /** Alle ungelesenen Hinweise einer Gruppe als gelesen markieren (Themenbesuch, PR #4); Anzahl der geänderten Zeilen, bei Fehler 0. */
    public function markGroupRead(int $userId, string $groupKey): int
    {
        if ($userId <= 0 || trim($groupKey) === '') {
            return 0;
        }

        try {
            return $this->db->execute(
                "UPDATE {$this->prefix}notifications SET is_read = 1, read_at = ? WHERE user_id = ? AND group_key = ? AND is_read = 0",
                [date('Y-m-d H:i:s'), $userId, $groupKey]
            )->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Ungelesene Hinweise je Typ (Badge je Gruppe im Mitgliederbereich, PR #4); bei Fehler leer.
     *
     * @return array<string, int>
     */
    public function countUnreadByType(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            $rows = $this->db->execute(
                "SELECT type, COUNT(*) AS unread FROM {$this->prefix}notifications WHERE user_id = ? AND is_read = 0 GROUP BY type ORDER BY type ASC",
                [$userId]
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['type']] = (int) $row['unread'];
        }

        return $counts;
    }

    public function markRead(int $userId, int $id): bool
    {
        if ($userId <= 0 || $id <= 0) {
            return false;
        }

        try {
            $statement = $this->db->execute(
                "UPDATE {$this->prefix}notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ? AND is_read = 0",
                [$id, $userId]
            );

            return $statement->rowCount() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return int Anzahl der neu als gelesen markierten Einträge */
    public function markAllRead(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        try {
            $statement = $this->db->execute(
                "UPDATE {$this->prefix}notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0",
                [$userId]
            );

            return $statement->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function countUnread(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        try {
            return (int) ($this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}notifications WHERE user_id = ? AND is_read = 0",
                [$userId]
            ) ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Letzte Benachrichtigungen, neueste zuerst.
     *
     * Community-Typen (`community.*`) kommen unverändert zurück, dazu `group_key` und `event_count` (Bündelung).
     *
     * @return list<object{id:int,user_id:int,type:string,title:string,message:string,url:string,group_key:?string,event_count:int,is_read:bool,read_at:?string,created_at:string}>
     */
    public function getRecent(int $userId, int $limit = 20, bool $unreadOnly = false): array
    {
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $where = $unreadOnly ? ' AND is_read = 0' : '';

        try {
            $rows = $this->db->get_results(
                "SELECT id, user_id, type, title, message, url, group_key, event_count, is_read, read_at, created_at
                 FROM {$this->prefix}notifications
                 WHERE user_id = ?{$where}
                 ORDER BY created_at DESC, id DESC
                 LIMIT ?",
                [$userId, $limit]
            );
        } catch (\Throwable) {
            return [];
        }

        $notifications = [];
        foreach ($rows as $row) {
            $notifications[] = (object) [
                'id' => (int) ($row->id ?? 0),
                'user_id' => (int) ($row->user_id ?? 0),
                'type' => self::isKnownType((string) ($row->type ?? '')) ? (string) $row->type : 'system',
                'title' => (string) ($row->title ?? ''),
                'message' => (string) ($row->message ?? ''),
                'url' => MemberSanitizer::notificationUrl($row->url ?? ''),
                'group_key' => isset($row->group_key) && $row->group_key !== '' ? (string) $row->group_key : null,
                'event_count' => max(1, (int) ($row->event_count ?? 1)),
                'is_read' => (int) ($row->is_read ?? 0) === 1,
                'read_at' => isset($row->read_at) && $row->read_at !== '' ? (string) $row->read_at : null,
                'created_at' => (string) ($row->created_at ?? ''),
            ];
        }

        return $notifications;
    }

    /**
     * Prüft die Benachrichtigungs-Einstellungen des Mitglieds für einen Typ.
     */
    public function isTypeEnabledForUser(int $userId, string $type): bool
    {
        $preferenceKey = self::PREFERENCE_KEYS[$type] ?? null;
        if ($preferenceKey === null) {
            return true;
        }

        try {
            $preferences = MemberService::getInstance()->getNotificationPreferences($userId);
        } catch (\Throwable) {
            return true;
        }

        return !array_key_exists($preferenceKey, $preferences) || !empty($preferences[$preferenceKey]);
    }
}
