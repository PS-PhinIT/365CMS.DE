<?php
/**
 * Notification Service
 *
 * In-App-Benachrichtigungen für Mitglieder (Tabelle `notifications`): anlegen, als gelesen markieren,
 * zählen und auflisten (MD-17). Beim Anlegen werden die Benachrichtigungs-Einstellungen des Mitglieds
 * (`user_meta['notification_preferences']`) ausgewertet (MD-18). Ein E-Mail-Versand ist nicht Teil dieses
 * Services; die E-Mail-Optionen des Admin-Bereichs werden gespeichert, aber noch nicht versendet.
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

    private const TITLE_MAX = 255;
    private const MESSAGE_MAX = 2000;

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
        if ($userId <= 0 || !in_array($type, self::TYPES, true)) {
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
     * @return list<object{id:int,user_id:int,type:string,title:string,message:string,url:string,is_read:bool,read_at:?string,created_at:string}>
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
                "SELECT id, user_id, type, title, message, url, is_read, read_at, created_at
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
                'type' => in_array((string) ($row->type ?? ''), self::TYPES, true) ? (string) $row->type : 'system',
                'title' => (string) ($row->title ?? ''),
                'message' => (string) ($row->message ?? ''),
                'url' => MemberSanitizer::notificationUrl($row->url ?? ''),
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
