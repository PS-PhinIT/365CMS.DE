<?php
/**
 * MessageService – Nachrichten-Verwaltung für Member-Dashboard
 *
 * Verantwortlich für:
 * - Senden, Lesen, Löschen von Benutzer-Nachrichten
 * - Thread-basierte Konversationen (parent_id)
 * - Ungelesen-Zähler für Dashboard-Badge
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;

if (!defined('ABSPATH')) {
    exit;
}

class MessageService
{
    /** Max. Nachrichten je Absender im Zeitfenster. */
    public const SEND_LIMIT_MAX = 30;
    public const SEND_LIMIT_WINDOW = 600;

    private static ?self $instance = null;
    private Database $db;
    private string $prefix;

    // ── Singleton ─────────────────────────────────────────────────────────────

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db     = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    // ── Nachrichten abrufen ───────────────────────────────────────────────────

    /**
     * Posteingang eines Benutzers (neueste zuerst).
     *
     * @param int $userId  Empfänger-ID
     * @param int $limit   Max. Anzahl
     * @param int $offset  Offset für Paginierung
     * @return array<object>
     */
    public function getInbox(int $userId, int $limit = 20, int $offset = 0): array
    {
        $sql = "SELECT m.*, u.username AS sender_name, u.display_name AS sender_display_name
                FROM {$this->prefix}messages m
                JOIN {$this->prefix}users u ON u.id = m.sender_id
                WHERE m.recipient_id = ? AND m.deleted_by_recipient = 0
                ORDER BY m.created_at DESC
                LIMIT ? OFFSET ?";

        return $this->db->get_results($sql, [$userId, $limit, $offset]) ?: [];
    }

    /**
     * Gesendete Nachrichten eines Benutzers.
     *
     * @param int $userId  Sender-ID
     * @param int $limit
     * @param int $offset
     * @return array<object>
     */
    public function getSent(int $userId, int $limit = 20, int $offset = 0): array
    {
        $sql = "SELECT m.*, u.username AS recipient_name, u.display_name AS recipient_display_name
                FROM {$this->prefix}messages m
                JOIN {$this->prefix}users u ON u.id = m.recipient_id
                WHERE m.sender_id = ? AND m.deleted_by_sender = 0
                ORDER BY m.created_at DESC
                LIMIT ? OFFSET ?";

        return $this->db->get_results($sql, [$userId, $limit, $offset]) ?: [];
    }

    /**
     * Einzelne Nachricht lesen.
     *
     * @param int $messageId
     * @param int $userId  Aktueller User (Zugriffsprüfung)
     * @return object|null
     */
    public function getMessage(int $messageId, int $userId): ?object
    {
        $sql = "SELECT m.*,
                       s.username AS sender_name, s.display_name AS sender_display_name,
                       r.username AS recipient_name, r.display_name AS recipient_display_name
                FROM {$this->prefix}messages m
                JOIN {$this->prefix}users s ON s.id = m.sender_id
                JOIN {$this->prefix}users r ON r.id = m.recipient_id
                WHERE m.id = ? AND (m.sender_id = ? OR m.recipient_id = ?)";

        $msg = $this->db->get_row($sql, [$messageId, $userId, $userId]);

        if ($msg && (int) $msg->recipient_id === $userId && !$msg->is_read) {
            $this->markAsRead($messageId);
            $msg->is_read = 1;
        }

        return $msg ?: null;
    }

    /**
     * Thread (alle Nachrichten einer Konversation).
     *
     * @param int $rootId  ID der Ursprungsnachricht (parent_id = NULL) oder parent_id
     * @param int $userId  Zugriffsprüfung
     * @return array<object>
     */
    public function getThread(int $rootId, int $userId): array
    {
        $sql = "SELECT m.*,
                       s.username AS sender_name, s.display_name AS sender_display_name
                FROM {$this->prefix}messages m
                JOIN {$this->prefix}users s ON s.id = m.sender_id
                WHERE (m.id = ? OR m.parent_id = ?)
                  AND ((m.sender_id = ? AND m.deleted_by_sender = 0)
                    OR (m.recipient_id = ? AND m.deleted_by_recipient = 0))
                ORDER BY m.created_at ASC";

        return $this->db->get_results($sql, [$rootId, $rootId, $userId, $userId]) ?: [];
    }

    // ── Nachrichten senden ────────────────────────────────────────────────────

    /**
     * Neue Nachricht senden.
     *
     * @param int    $senderId
     * @param int    $recipientId
     * @param string $subject
     * @param string $body
     * @param int|null $parentId  Bei Antwort: ID der Ursprungsnachricht
     * @return int|false  Neue Nachrichten-ID oder false bei Fehler
     */
    public function send(int $senderId, int $recipientId, string $subject, string $body, ?int $parentId = null): int|false
    {
        if ($senderId === $recipientId) {
            return false;
        }

        if (empty(trim($body))) {
            return false;
        }

        // Empfänger muss existieren
        $exists = $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}users WHERE id = ? AND status = 'active'",
            [$recipientId]
        );
        if (!$exists) {
            return false;
        }

        if ($this->isSendRateLimited($senderId)) {
            return false;
        }

        // Antworten nur in Threads, an denen Absender und Empfänger beteiligt sind –
        // sonst ließen sich Nachrichten in fremde Konversationen einschleusen.
        if ($parentId !== null && $parentId > 0) {
            $parent = $this->db->get_row(
                "SELECT id, parent_id, sender_id, recipient_id FROM {$this->prefix}messages WHERE id = ? LIMIT 1",
                [$parentId]
            );
            if (!$parent) {
                return false;
            }

            $participants = [(int) $parent->sender_id, (int) $parent->recipient_id];
            if (!in_array($senderId, $participants, true) || !in_array($recipientId, $participants, true)) {
                return false;
            }

            $parentId = (int) ($parent->parent_id ?: $parent->id);
        } else {
            $parentId = null;
        }

        $this->db->insert('messages', [
            'sender_id'    => $senderId,
            'recipient_id' => $recipientId,
            'subject'      => $subject,
            'body'         => $body,
            'parent_id'    => $parentId,
        ]);

        $newId = $this->db->lastInsertId();
        return $newId ? (int) $newId : false;
    }

    /**
     * Versandlimit je Absender (Spam-/Flood-Schutz für das Mitglieder-Postfach).
     */
    public function isSendRateLimited(int $senderId): bool
    {
        $recent = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}messages WHERE sender_id = ? AND created_at > (NOW() - INTERVAL ? SECOND)",
            [$senderId, self::SEND_LIMIT_WINDOW]
        );

        return $recent >= self::SEND_LIMIT_MAX;
    }

    // ── Status-Aktionen ───────────────────────────────────────────────────────

    /**
     * Nachricht als gelesen markieren.
     */
    public function markAsRead(int $messageId): void
    {
        $this->db->execute(
            "UPDATE {$this->prefix}messages SET is_read = 1, read_at = NOW() WHERE id = ?",
            [$messageId]
        );
    }

    /**
     * Nachricht (soft-)löschen für einen Benutzer.
     *
     * @param int $messageId
     * @param int $userId  Wer löscht (Sender oder Empfänger)
     * @return bool
     */
    public function delete(int $messageId, int $userId): bool
    {
        $msg = $this->db->get_row(
            "SELECT sender_id, recipient_id FROM {$this->prefix}messages WHERE id = ?",
            [$messageId]
        );

        if (!$msg) {
            return false;
        }

        if ((int) $msg->sender_id === $userId) {
            $this->db->execute(
                "UPDATE {$this->prefix}messages SET deleted_by_sender = 1 WHERE id = ?",
                [$messageId]
            );
        } elseif ((int) $msg->recipient_id === $userId) {
            $this->db->execute(
                "UPDATE {$this->prefix}messages SET deleted_by_recipient = 1 WHERE id = ?",
                [$messageId]
            );
        } else {
            return false;
        }

        // Wenn beide gelöscht haben → physisch löschen
        $updated = $this->db->get_row(
            "SELECT deleted_by_sender, deleted_by_recipient FROM {$this->prefix}messages WHERE id = ?",
            [$messageId]
        );
        if ($updated && $updated->deleted_by_sender && $updated->deleted_by_recipient) {
            $this->db->execute(
                "DELETE FROM {$this->prefix}messages WHERE id = ?",
                [$messageId]
            );
        }

        return true;
    }

    // ── Zähler & Statistiken ──────────────────────────────────────────────────

    /**
     * Anzahl der ungelesenen Nachrichten eines Benutzers.
     */
    public function getUnreadCount(int $userId): int
    {
        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}messages WHERE recipient_id = ? AND is_read = 0 AND deleted_by_recipient = 0",
            [$userId]
        );
    }

    /**
     * Gesamtanzahl der Nachrichten im Posteingang.
     */
    public function getInboxCount(int $userId): int
    {
        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}messages WHERE recipient_id = ? AND deleted_by_recipient = 0",
            [$userId]
        );
    }

    /**
     * Konversations-Übersicht: Gruppiert nach Thread (nur neueste Nachricht je Thread).
     *
     * @param int $userId
     * @param int $limit
     * @param int $offset
     * @return array<object>
     */
    public function getConversations(int $userId, int $limit = 20, int $offset = 0): array
    {
        // Alle Nachrichten in denen der User beteiligt ist, gruppiert nach Root-Thread
        $sql = "SELECT m.*,
                       s.username AS sender_name, s.display_name AS sender_display_name,
                       r.username AS recipient_name, r.display_name AS recipient_display_name,
                       COALESCE(m.parent_id, m.id) AS thread_id
                FROM {$this->prefix}messages m
                JOIN {$this->prefix}users s ON s.id = m.sender_id
                JOIN {$this->prefix}users r ON r.id = m.recipient_id
                WHERE (m.recipient_id = ? AND m.deleted_by_recipient = 0)
                   OR (m.sender_id = ? AND m.deleted_by_sender = 0)
                ORDER BY m.created_at DESC
                LIMIT ? OFFSET ?";

        $all = $this->db->get_results($sql, [$userId, $userId, $limit * 3, $offset]) ?: [];

        // Gruppieren: pro Thread nur die neueste Nachricht behalten
        $threads = [];
        foreach ($all as $msg) {
            $tid = (int) ($msg->parent_id ?: $msg->id);
            if (!isset($threads[$tid])) {
                $msg->thread_id = $tid;
                $threads[$tid] = $msg;
            }
        }

        return array_slice(array_values($threads), 0, $limit);
    }

    /**
     * Benutzer für Empfänger-Auswahl suchen (Autocomplete).
     *
     * @param string $query  Suchbegriff (Username oder Display-Name)
     * @param int    $excludeUserId  Aktuellen User ausschließen
     * @param int    $limit
     * @return array<object>
     */
    public function searchRecipients(string $query, int $excludeUserId, int $limit = 10): array
    {
        $sql = "SELECT id, username, display_name
                FROM {$this->prefix}users
                WHERE id != ? AND status = 'active'
                  AND (username LIKE ? OR display_name LIKE ?)
                ORDER BY display_name ASC
                LIMIT ?";

        $like = '%' . \cms_escape_like($query) . '%';
        return $this->db->get_results($sql, [$excludeUserId, $like, $like, $limit]) ?: [];
    }
}
