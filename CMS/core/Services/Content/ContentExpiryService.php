<?php
/**
 * Ablauf- und Prüfdaten für Beiträge, Seiten und Wissensdatenbank-Einträge (4.1.0, Bereich 3c).
 *
 * - `expires_at`: Ab diesem Zeitpunkt ist ein veröffentlichter Inhalt abgelaufen. Einstellung
 *   `setting_content_expiry_action`: `draft` (Standard, auf Entwurf zurückstufen → Frontend 404, Sitemap ohne Eintrag)
 *   oder `mark` (sichtbar lassen, nur Hinweis). Verarbeitung stündlich per Cron, je Lauf höchstens 100 Inhalte,
 *   idempotent über `status = 'published' AND expires_at <= jetzt` (zurückgestufte Inhalte fallen heraus; markierte
 *   über `expiry_notified_at`).
 * - `review_due_at`: Wiedervorlage ohne Statusänderung. Fällige Inhalte melden sich einmal (Hinweis an den Autor,
 *   Hook `cms_content_review_due`); „Geprüft“ setzt das nächste Datum (Intervall `setting_content_review_interval`
 *   in Monaten, Standard 12).
 *
 * Datenbank, Uhr, Einstellungen und Benachrichtigung lassen sich für Tests ersetzen.
 *
 * @package CMSv2\Core\Services\Content
 */

declare(strict_types=1);

namespace CMS\Services\Content;

use CMS\Database;
use CMS\Hooks;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentExpiryService
{
    public const SETTING_ACTION = 'setting_content_expiry_action';
    public const SETTING_REVIEW_INTERVAL = 'setting_content_review_interval';
    /** Antwort für zurückgestufte, abgelaufene Inhalte im Frontend: `404` (Standard) oder `410` (Gone). */
    public const SETTING_RESPONSE = 'setting_content_expiry_response';
    public const ACTION_DRAFT = 'draft';
    public const ACTION_MARK = 'mark';
    public const BATCH = 100;
    public const DEFAULT_REVIEW_MONTHS = 12;

    /**
     * Je Inhaltsart: Tabelle, Bedingung „veröffentlicht“, Zurückstufen, „nicht im Papierkorb“, Autor-Spalte,
     * Speicher-Hook (Suchindex, Sitemap …), Editor-Link und Bezeichnung.
     */
    private const TYPES = [
        'post' => ['table' => 'posts', 'live' => "status = 'published'", 'unpublish' => "status = 'draft'", 'active' => "status <> 'trash'",
            'author' => 'author_id', 'hook' => 'cms_after_post_save', 'edit' => '/admin/posts?action=edit&id=', 'label' => 'Beitrag'],
        'page' => ['table' => 'pages', 'live' => "status = 'published'", 'unpublish' => "status = 'draft'", 'active' => "status <> 'trash'",
            'author' => 'author_id', 'hook' => 'cms_after_page_save', 'edit' => '/admin/pages?action=edit&id=', 'label' => 'Seite'],
        'kb' => ['table' => 'kb_entries', 'live' => 'is_active = 1 AND trashed_at IS NULL', 'unpublish' => 'is_active = 0', 'active' => 'trashed_at IS NULL',
            'author' => '', 'hook' => 'cms_knowledgebase_entry_saved', 'edit' => '/admin/knowledgebase?section=edit&id=', 'label' => 'Wissensdatenbank-Eintrag'],
    ];

    private static ?self $instance = null;

    private Database $db;
    private string $prefix;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(string, string): string Einstellung, Standard */
    private \Closure $setting;
    /** @var \Closure(int, string, string, string): void Benutzer, Betreff, Text, Link */
    private \Closure $notifier;

    public function __construct(?Database $db = null, ?\Closure $clock = null, ?\Closure $setting = null, ?\Closure $notifier = null)
    {
        $this->db = $db ?? Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->clock = $clock ?? static fn (): int => time();
        $this->setting = $setting ?? static fn (string $key, string $default): string => function_exists('get_option')
            ? (string) get_option($key, $default)
            : $default;
        $this->notifier = $notifier ?? static function (int $userId, string $subject, string $message, string $url): void {
            try {
                \CMS\Services\NotificationService::getInstance()->create($userId, 'system', $subject, $message, $url);
            } catch (\Throwable) {
            }
        };
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    // ── Reine Regeln ────────────────────────────────────────────────────────────────────────────────────────────

    public static function normalizeAction(string $value): string
    {
        return $value === self::ACTION_MARK ? self::ACTION_MARK : self::ACTION_DRAFT;
    }

    public static function normalizeInterval(mixed $value): int
    {
        $months = is_numeric($value) ? (int) $value : self::DEFAULT_REVIEW_MONTHS;

        return max(1, min(60, $months));
    }

    /**
     * Ist ein Inhalt mit diesem Status und Ablaufdatum zum Zeitpunkt $now abgelaufen?
     */
    public static function isExpired(string $status, ?string $expiresAt, string $now): bool
    {
        $expiresAt = trim((string) $expiresAt);

        return $status === ContentStatus::PUBLISHED && $expiresAt !== '' && !str_starts_with($expiresAt, '0000') && strcmp($expiresAt, $now) <= 0;
    }

    /**
     * Nächstes Prüfdatum: $months Monate nach $from (Monatsende bleibt Monatsende, z. B. 31.01. → 28./29.02.).
     */
    public static function nextReviewDate(string $from, int $months): string
    {
        $date = new \DateTimeImmutable($from);
        $day = (int) $date->format('d');
        $target = $date->modify('first day of this month')->modify('+' . max(1, $months) . ' months');
        $day = min($day, (int) $target->format('t'));

        return $target->setDate((int) $target->format('Y'), (int) $target->format('m'), $day)->format('Y-m-d H:i:s');
    }

    /**
     * Datum/Uhrzeit aus dem Editor → `Y-m-d H:i:s` (ohne Uhrzeit 00:00). Leer → null.
     *
     * @return array{value: ?string, error: ?string}
     */
    public static function normalizeInput(string $date, string $time, string $label): array
    {
        $date = trim($date);
        $time = trim($time);
        if ($date === '') {
            return ['value' => null, 'error' => $time !== '' ? sprintf('%s: Bitte auch ein Datum angeben.', $label) : null];
        }
        $time = $time === '' ? '00:00' : substr($time, 0, 5);
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($value === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || (int) $value->format('Y') < 1970 || (int) $value->format('Y') > 2999) {
            return ['value' => null, 'error' => sprintf('%s: Das Datum ist ungültig.', $label)];
        }

        return ['value' => $value->format('Y-m-d H:i:s'), 'error' => null];
    }

    /**
     * Wurde der Inhalt wegen Ablaufs zurückgestuft (Entwurf bzw. inaktiv, Ablauf gemeldet)?
     */
    public static function wasExpiredAndUnpublished(array|object $row): bool
    {
        $value = static fn (string $key): string => (string) (is_array($row) ? ($row[$key] ?? '') : ($row->{$key} ?? ''));

        // Wissensdatenbank (FUN-410-09): kein Status, sondern is_active.
        $unpublished = $value('status') === ContentStatus::DRAFT
            || ($value('status') === '' && $value('is_active') === '0');

        return $value('expiry_notified_at') !== '' && $unpublished;
    }

    /**
     * HTTP-Status für einen nicht mehr öffentlichen Inhalt: 410, wenn er abgelaufen ist und die Einstellung es will.
     */
    public function frontendStatusFor(array|object $row): int
    {
        return self::wasExpiredAndUnpublished($row) && ($this->setting)(self::SETTING_RESPONSE, '404') === '410' ? 410 : 404;
    }

    // ── Speichern aus dem Editor ────────────────────────────────────────────────────────────────────────────────

    public function saveDates(string $type, int $id, ?string $expiresAt, ?string $reviewDueAt): bool
    {
        if (!isset(self::TYPES[$type]) || $id <= 0) {
            return false;
        }
        try {
            // Ein neues Ablaufdatum setzt die Markierung zurück, damit ein späterer Ablauf erneut meldet. FUN-410-08:
            // ebenso, wenn der Inhalt wieder veröffentlicht ist – sonst liefe ein erneut veröffentlichter, abgelaufener
            // Inhalt mit unverändertem Datum nie wieder ab.
            // Nur im Modus „zurückstufen“: Dort ist ein sichtbarer Inhalt mit Markierung immer wieder veröffentlicht; im
            // Modus „nur markieren“ bleibt er sichtbar, und die Markierung muss stehen bleiben.
            $keepWhenLive = self::normalizeAction(($this->setting)(self::SETTING_ACTION, self::ACTION_DRAFT)) === self::ACTION_DRAFT
                ? 'NOT (' . self::TYPES[$type]['live'] . ')'
                : '1 = 1';
            $this->db->execute(
                "UPDATE {$this->prefix}" . self::TYPES[$type]['table'] . "
                 SET expiry_notified_at = CASE WHEN expires_at <=> ? AND {$keepWhenLive} THEN expiry_notified_at ELSE NULL END,
                     review_notified_at = CASE WHEN review_due_at <=> ? THEN review_notified_at ELSE NULL END,
                     expires_at = ?, review_due_at = ?, updated_at = updated_at
                 WHERE id = ?",
                [$expiresAt, $reviewDueAt, $expiresAt, $reviewDueAt, $id]
            );

            return true;
        } catch (\Throwable $e) {
            $this->logWarning('Ablauf-/Prüfdatum konnte nicht gespeichert werden.', $e);

            return false;
        }
    }

    // ── Cron ────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Abgelaufene Inhalte verarbeiten (stündlich).
     *
     * @return array{expired: int, marked: int}
     */
    public function processExpired(): array
    {
        $now = $this->now();
        $action = self::normalizeAction(($this->setting)(self::SETTING_ACTION, self::ACTION_DRAFT));
        $result = ['expired' => 0, 'marked' => 0];
        foreach (self::TYPES as $type => $config) {
            $table = $config['table'];
            $author = $config['author'] !== '' ? $config['author'] : '0';
            try {
                $rows = $this->db->get_results(
                    "SELECT id, title, {$author} AS author_id, expires_at FROM {$this->prefix}{$table}
                     WHERE {$config['live']} AND expires_at IS NOT NULL AND expires_at <= ? AND expiry_notified_at IS NULL
                     ORDER BY expires_at ASC LIMIT " . self::BATCH,
                    [$now]
                ) ?: [];
            } catch (\Throwable $e) {
                $this->logWarning('Abgelaufene Inhalte konnten nicht gelesen werden.', $e);
                continue;
            }
            foreach ($rows as $row) {
                $id = (int) $row->id;
                if ($action === self::ACTION_DRAFT) {
                    $changed = $this->db->execute(
                        "UPDATE {$this->prefix}{$table} SET {$config['unpublish']}, expiry_notified_at = ?, updated_at = ? WHERE id = ? AND {$config['live']}",
                        [$now, $now, $id]
                    )->rowCount();
                    if ($changed < 1) {
                        continue;
                    }
                    $result['expired']++;
                    $this->runHook($config['hook'], $id, ['status' => ContentStatus::DRAFT, 'expired' => true]);
                    // FUN-410-13: Statuswechsel wie bei jeder anderen Zurückstufung melden (Webhooks, Verlauf).
                    if ($type !== 'kb') {
                        $this->runHook('cms_content_status_changed', $type, $id, ContentStatus::PUBLISHED, ContentStatus::DRAFT);
                    }
                } else {
                    $this->db->execute("UPDATE {$this->prefix}{$table} SET expiry_notified_at = ? WHERE id = ?", [$now, $id]);
                    $result['marked']++;
                }
                $this->runHook('cms_content_expired', $type, $id, $action);
                $this->notify((int) ($row->author_id ?? 0), $type, $id, (string) ($row->title ?? ''), $action === self::ACTION_DRAFT
                    ? 'ist abgelaufen und wurde auf Entwurf zurückgestuft.'
                    : 'ist abgelaufen (weiterhin sichtbar).');
            }
        }
        if ($result['expired'] > 0) {
            try {
                \CMS\CacheManager::instance()->clear();
            } catch (\Throwable) {
            }
        }

        return $result;
    }

    /**
     * Fällige Wiedervorlagen einmal melden (täglich).
     */
    public function processDueReviews(): int
    {
        $now = $this->now();
        $count = 0;
        foreach (self::TYPES as $type => $config) {
            $table = $config['table'];
            $author = $config['author'] !== '' ? $config['author'] : '0';
            try {
                $rows = $this->db->get_results(
                    "SELECT id, title, {$author} AS author_id FROM {$this->prefix}{$table}
                     WHERE {$config['active']} AND review_due_at IS NOT NULL AND review_due_at <= ? AND review_notified_at IS NULL
                     ORDER BY review_due_at ASC LIMIT " . self::BATCH,
                    [$now]
                ) ?: [];
            } catch (\Throwable $e) {
                $this->logWarning('Fällige Wiedervorlagen konnten nicht gelesen werden.', $e);
                continue;
            }
            foreach ($rows as $row) {
                $id = (int) $row->id;
                $this->db->execute("UPDATE {$this->prefix}{$table} SET review_notified_at = ? WHERE id = ?", [$now, $id]);
                $this->runHook('cms_content_review_due', $type, $id);
                $this->notify((int) ($row->author_id ?? 0), $type, $id, (string) ($row->title ?? ''), 'ist zur Prüfung fällig (Wiedervorlage).');
                $count++;
            }
        }

        return $count;
    }

    /**
     * „Geprüft“: nächstes Prüfdatum = jetzt + Intervall.
     *
     * @return array{success: bool, review_due_at?: string, error?: string}
     */
    public function markReviewed(string $type, int $id): array
    {
        if (!isset(self::TYPES[$type]) || $id <= 0) {
            return ['success' => false, 'error' => 'Ungültiger Inhalt.'];
        }
        $next = self::nextReviewDate($this->now(), self::normalizeInterval(($this->setting)(self::SETTING_REVIEW_INTERVAL, (string) self::DEFAULT_REVIEW_MONTHS)));
        $this->db->execute(
            "UPDATE {$this->prefix}" . self::TYPES[$type]['table'] . " SET review_due_at = ?, review_notified_at = NULL, updated_at = updated_at WHERE id = ?",
            [$next, $id]
        );

        return ['success' => true, 'review_due_at' => $next];
    }

    /**
     * Fällige Wiedervorlagen für Listen/Widgets.
     *
     * @return list<array{type: string, id: int, title: string, review_due_at: string, label: string, edit_url: string}>
     */
    public function dueReviews(int $limit = 10): array
    {
        $items = [];
        foreach (self::TYPES as $type => $config) {
            try {
                $rows = $this->db->get_results(
                    "SELECT id, title, review_due_at FROM {$this->prefix}{$config['table']}
                     WHERE {$config['active']} AND review_due_at IS NOT NULL AND review_due_at <= ?
                     ORDER BY review_due_at ASC LIMIT " . max(1, min(100, $limit)),
                    [$this->now()]
                ) ?: [];
            } catch (\Throwable) {
                continue;
            }
            foreach ($rows as $row) {
                $items[] = [
                    'type' => $type,
                    'id' => (int) $row->id,
                    'title' => (string) ($row->title ?? ''),
                    'review_due_at' => (string) ($row->review_due_at ?? ''),
                    'label' => $config['label'],
                    'edit_url' => $config['edit'] . (int) $row->id,
                ];
            }
        }
        usort($items, static fn (array $a, array $b): int => strcmp($a['review_due_at'], $b['review_due_at']));

        return array_slice($items, 0, max(1, $limit));
    }

    // ── Intern ──────────────────────────────────────────────────────────────────────────────────────────────────

    private function notify(int $userId, string $type, int $id, string $title, string $text): void
    {
        if ($userId <= 0) {
            return;
        }
        $label = self::TYPES[$type]['label'];
        try {
            ($this->notifier)($userId, sprintf('%s „%s“', $label, $title !== '' ? $title : '#' . $id), sprintf('%s „%s“ %s', $label, $title, $text), self::TYPES[$type]['edit'] . $id);
        } catch (\Throwable $e) {
            $this->logWarning('Hinweis zu Ablauf/Wiedervorlage fehlgeschlagen.', $e);
        }
    }

    private function runHook(string $hook, mixed ...$args): void
    {
        try {
            Hooks::doAction($hook, ...$args);
        } catch (\Throwable $e) {
            $this->logWarning('Folgeaktion zu Ablauf/Wiedervorlage fehlgeschlagen: ' . $hook, $e);
        }
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s', ($this->clock)());
    }

    private function logWarning(string $message, \Throwable $e): void
    {
        try {
            Logger::instance()->withChannel('content.expiry')->warning($message, ['exception' => $e->getMessage()]);
        } catch (\Throwable) {
        }
    }
}
