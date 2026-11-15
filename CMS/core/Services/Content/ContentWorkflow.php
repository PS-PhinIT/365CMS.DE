<?php
/**
 * Freigabe-Ablauf für Beiträge und Seiten (4.1.0, Bereich 3a – Vier-Augen-Prinzip).
 *
 * Regeln:
 * - Veröffentlichen (Status `published` – auch geplant – oder `private`) verlangt `posts.publish` bzw. `pages.publish`.
 * - Ist die Einstellung „Veröffentlichung braucht eine zweite Person“ an, darf zusätzlich niemand eigene Inhalte
 *   freigeben: Autor und Einreichende Person sind von der Freigabe ausgeschlossen – das gilt auch für Admins.
 * - Wer nicht veröffentlichen darf, speichert einen gewünschten Veröffentlichungsstatus als `pending` (Zur Prüfung).
 *   Bereits veröffentlichte Inhalte bleiben beim Bearbeiten veröffentlicht (die Freigabe gilt für die
 *   Veröffentlichung, nicht für jede spätere Änderung).
 * - Freigeben setzt `published` (ein Datum in der Zukunft bleibt erhalten → geplant), Zurückgeben setzt `draft` und
 *   verlangt eine Begründung. Jeder Schritt landet in `content_workflow_events` und benachrichtigt die Beteiligten.
 *
 * Vorschau-Links: `/preview/<token>`; gespeichert wird nur der SHA-256-Hash des Tokens, Ablauf 7 Tage, widerrufbar.
 *
 * Die Regeln selbst sind reine statische Funktionen (ohne Datenbank testbar); Datenbank, Einstellung, Rechte der
 * Rollen, Uhr und Versand der Benachrichtigungen lassen sich über den Konstruktor ersetzen.
 *
 * @package CMSv2\Core\Services\Content
 */

declare(strict_types=1);

namespace CMS\Services\Content;

use CMS\Auth;
use CMS\Database;
use CMS\Hooks;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentWorkflow
{
    public const TYPE_POST = 'post';
    public const TYPE_PAGE = 'page';

    public const SETTING_SECOND_PERSON = 'setting_content_review_second_person';

    public const ACTION_SUBMITTED = 'submitted';
    public const ACTION_APPROVED = 'approved';
    public const ACTION_RETURNED = 'returned';
    public const ACTION_WITHDRAWN = 'withdrawn';
    public const ACTION_COMMENT = 'comment';
    public const ACTION_PREVIEW_CREATED = 'preview_created';
    public const ACTION_PREVIEW_REVOKED = 'preview_revoked';

    public const PREVIEW_TTL_SECONDS = 7 * 86400;
    public const COMMENT_MAX = 2000;

    private const TABLES = [self::TYPE_POST => 'posts', self::TYPE_PAGE => 'pages'];
    private const CAPABILITIES = [self::TYPE_POST => 'posts.publish', self::TYPE_PAGE => 'pages.publish'];
    private const SAVE_HOOKS = [self::TYPE_POST => 'cms_after_post_save', self::TYPE_PAGE => 'cms_after_page_save'];
    private const EDIT_URLS = [self::TYPE_POST => '/admin/posts?action=edit&id=', self::TYPE_PAGE => '/admin/pages?action=edit&id='];

    private static ?self $instance = null;

    private Database $db;
    private string $prefix;
    /** @var \Closure(): bool */
    private \Closure $secondPersonSetting;
    /** @var \Closure(string, string): bool Rolle, Capability */
    private \Closure $roleCan;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(list<object>, string, string, string): void Empfänger (id, email, display_name), Betreff, Text, Link */
    private \Closure $notifier;

    public function __construct(
        ?Database $db = null,
        ?\Closure $secondPersonSetting = null,
        ?\Closure $roleCan = null,
        ?\Closure $clock = null,
        ?\Closure $notifier = null
    ) {
        $this->db = $db ?? Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->secondPersonSetting = $secondPersonSetting ?? static fn (): bool => function_exists('get_option')
            && (string) get_option(self::SETTING_SECOND_PERSON, '0') === '1';
        $this->roleCan = $roleCan ?? static function (string $role, string $capability): bool {
            if ($role === 'admin') {
                return true;
            }

            return function_exists('cms_load_role_capabilities') && !empty(cms_load_role_capabilities($role)[$capability]);
        };
        $this->clock = $clock ?? static fn (): int => time();
        $this->notifier = $notifier ?? \Closure::fromCallable([self::class, 'deliverNotifications']);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    // ── Reine Regeln ────────────────────────────────────────────────────────────────────────────────────────────

    public static function isSupportedType(string $type): bool
    {
        return isset(self::TABLES[$type]);
    }

    public static function capabilityFor(string $type): string
    {
        return self::CAPABILITIES[$type] ?? '';
    }

    /**
     * Darf $userId Inhalte von $authorId veröffentlichen bzw. freigeben?
     *
     * @param callable(string): bool $can Capability-Abfrage des handelnden Kontos
     * @param int $submitterId wer den Inhalt zur Prüfung eingereicht hat (0 = unbekannt/nicht eingereicht)
     */
    public static function canPublishWith(string $type, int $userId, int $authorId, callable $can, bool $secondPerson, int $submitterId = 0): bool
    {
        $capability = self::capabilityFor($type);
        if ($capability === '' || $userId <= 0 || !$can($capability)) {
            return false;
        }
        if (!$secondPerson) {
            return true;
        }

        return $authorId !== $userId && $submitterId !== $userId;
    }

    /**
     * Status, der beim Speichern tatsächlich geschrieben wird.
     *
     * @return array{status: string, downgraded: bool}
     */
    public static function resolveSaveStatus(string $requested, string $current, bool $canPublish): array
    {
        // SEC-410-13: Nur ein bereits öffentlicher Inhalt (bzw. unveränderter Status) braucht keine neue Freigabe.
        // privat → veröffentlicht macht den Inhalt erstmals öffentlich und verlangt das Freigaberecht.
        if (!ContentStatus::isPublishing($requested) || $current === ContentStatus::PUBLISHED || $requested === $current || $canPublish) {
            return ['status' => $requested, 'downgraded' => false];
        }

        return ['status' => ContentStatus::PENDING, 'downgraded' => true];
    }

    /**
     * Welcher Schritt des Freigabe-Ablaufs ist ein Statuswechsel? null = keiner.
     */
    public static function transitionAction(string $from, string $to, bool $actorIsAuthor): ?string
    {
        if ($from === $to) {
            return null;
        }
        if ($to === ContentStatus::PENDING) {
            return self::ACTION_SUBMITTED;
        }
        if ($from !== ContentStatus::PENDING) {
            return null;
        }
        if (ContentStatus::isPublishing($to)) {
            return self::ACTION_APPROVED;
        }
        if ($to === ContentStatus::DRAFT) {
            return $actorIsAuthor ? self::ACTION_WITHDRAWN : self::ACTION_RETURNED;
        }

        return null;
    }

    /**
     * Wer erhält eine Benachrichtigung? Eingereicht → alle, die freigeben dürften (ohne Autor und Handelnden);
     * Freigegeben/Zurückgegeben → Autor und einreichende Person (ohne Handelnden).
     *
     * @param list<object> $users aktive Konten mit id und role
     * @param callable(string, string): bool $roleCan
     * @return list<int>
     */
    public static function recipientIds(string $action, string $type, array $users, callable $roleCan, int $actorId, int $authorId, int $submitterId): array
    {
        $ids = [];
        if ($action === self::ACTION_SUBMITTED) {
            $capability = self::capabilityFor($type);
            foreach ($users as $user) {
                $id = (int) ($user->id ?? 0);
                if ($id > 0 && $id !== $actorId && $id !== $authorId && $roleCan((string) ($user->role ?? ''), $capability)) {
                    $ids[$id] = true;
                }
            }
        } elseif (in_array($action, [self::ACTION_APPROVED, self::ACTION_RETURNED], true)) {
            foreach ([$authorId, $submitterId] as $id) {
                if ($id > 0 && $id !== $actorId) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    public static function hashPreviewToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormedPreviewToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
    }

    public static function editUrl(string $type, int $id): string
    {
        return (self::EDIT_URLS[$type] ?? '/admin') . $id;
    }

    // ── Rechte des aktuellen Kontos ─────────────────────────────────────────────────────────────────────────────

    public function isSecondPersonRequired(): bool
    {
        return ($this->secondPersonSetting)();
    }

    /**
     * @param callable(string): bool|null $can Standard: Capability-Abfrage des angemeldeten Kontos
     */
    public function canPublish(string $type, int $userId, int $authorId, ?callable $can = null, int $submitterId = 0): bool
    {
        if ($can === null) {
            $auth = Auth::instance();
            $can = static fn (string $capability): bool => $auth->hasCapability($capability);
        }

        return self::canPublishWith($type, $userId, $authorId, $can, $this->isSecondPersonRequired(), $submitterId);
    }

    /**
     * Darf $userId den eingereichten Inhalt freigeben bzw. zurückgeben (berücksichtigt die einreichende Person)?
     *
     * @param callable(string): bool|null $can
     */
    public function canReview(string $type, int $id, int $userId, ?callable $can = null): bool
    {
        $row = $this->loadContent($type, $id);
        if ($row === null) {
            return false;
        }

        return $this->canPublish($type, $userId, (int) ($row->author_id ?? 0), $can, $this->lastSubmitterId($type, $id));
    }

    /**
     * Sammelaktion „Veröffentlichen“: Welche Einträge darf $userId veröffentlichen, welche Entwürfe kann es nur
     * einreichen? Bereits veröffentlichte/private Einträge zählen als erlaubt (keine Freigabe nötig); eingereichte
     * Einträge ohne Recht fallen aus beiden Listen.
     *
     * @param list<int> $ids
     * @param callable(string): bool|null $can
     * @return array{0: list<int>, 1: list<int>}
     */
    public function partitionByPublishRight(string $type, array $ids, int $userId, ?callable $can = null): array
    {
        $allowed = [];
        $submit = [];
        foreach ($this->loadContentRows($type, $ids) as $row) {
            $id = (int) $row->id;
            $status = (string) ($row->status ?? '');
            $submitter = $status === ContentStatus::PENDING ? $this->lastSubmitterId($type, $id) : 0;
            // SEC-410-13: „Veröffentlichen“ eines privaten Inhalts ist eine neue Freigabe.
            if ($status === ContentStatus::PUBLISHED || $this->canPublish($type, $userId, (int) ($row->author_id ?? 0), $can, $submitter)) {
                $allowed[] = $id;
            } elseif ($status === ContentStatus::DRAFT) {
                $submit[] = $id;
            }
        }

        return [$allowed, $submit];
    }

    /**
     * @param list<int> $ids
     * @return array<int, string> id → Status
     */
    public function statusMap(string $type, array $ids): array
    {
        $map = [];
        foreach ($this->loadContentRows($type, $ids) as $row) {
            $map[(int) $row->id] = (string) ($row->status ?? '');
        }

        return $map;
    }

    /**
     * Sammelaktion: Statuswechsel in den Verlauf übernehmen (Freigabe, Rückgabe, Einreichung).
     *
     * @param array<int, string> $previousStatuses id → Status vor der Aktion
     */
    public function recordBulkTransitions(string $type, array $previousStatuses, string $to, int $userId): void
    {
        foreach ($previousStatuses as $id => $from) {
            // FUN-410-04: Eine Rückgabe verlangt eine Begründung; die Sammelaktion hat keine – das steht im Verlauf.
            $comment = $from === ContentStatus::PENDING && $to === ContentStatus::DRAFT
                ? 'Über die Sammelaktion „Entwurf“ zurückgesetzt (ohne Begründung).'
                : '';
            $this->recordTransition($type, (int) $id, $from, $to, $userId, $comment);
        }
    }

    /**
     * @param list<int> $ids Entwürfe
     */
    public function submitMany(string $type, array $ids, int $userId): int
    {
        $count = 0;
        foreach ($ids as $id) {
            if (!empty($this->submit($type, (int) $id, $userId)['success'])) {
                $count++;
            }
        }

        return $count;
    }

    // ── Schritte ────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Nach einem Speichern mit Statuswechsel: Verlauf schreiben, Hook feuern, Beteiligte benachrichtigen.
     * Gibt die erkannte Aktion zurück (oder null, wenn der Wechsel kein Schritt des Ablaufs ist).
     */
    public function recordTransition(string $type, int $id, string $from, string $to, int $userId, string $comment = ''): ?string
    {
        if (!self::isSupportedType($type) || $id <= 0) {
            return null;
        }
        $row = $this->loadContent($type, $id);
        $authorId = (int) ($row->author_id ?? 0);
        if ($from !== $to) {
            // Allgemeiner Statuswechsel (Webhooks `content.published` u. a.), seit 4.1.0.
            try {
                Hooks::doAction('cms_content_status_changed', $type, $id, $from, $to, $userId);
            } catch (\Throwable) {
            }
        }
        $action = self::transitionAction($from, $to, $authorId > 0 && $authorId === $userId);
        if ($action === null) {
            return null;
        }
        $submitterId = $action === self::ACTION_SUBMITTED ? $userId : $this->lastSubmitterId($type, $id);
        $this->addEvent($type, $id, $action, $from, $to, $userId, $comment);

        $hook = match ($action) {
            self::ACTION_SUBMITTED => 'cms_content_submitted',
            self::ACTION_APPROVED => 'cms_content_approved',
            self::ACTION_RETURNED => 'cms_content_returned',
            default => '',
        };
        if ($hook !== '') {
            Hooks::doAction($hook, $type, $id, $userId, $comment);
        }
        $this->notify($action, $type, $id, (string) ($row->title ?? ''), $userId, $authorId, $submitterId, $comment);

        return $action;
    }

    /**
     * Freigeben: `pending` → `published` (ein künftiges Datum bleibt erhalten, sonst jetzt).
     *
     * @return array{success: bool, error?: string, message?: string}
     */
    public function approve(string $type, int $id, int $userId, string $comment = '', ?callable $can = null): array
    {
        $row = $this->loadContent($type, $id);
        if ($row === null) {
            return ['success' => false, 'error' => 'Der Inhalt existiert nicht mehr.'];
        }
        if ((string) ($row->status ?? '') !== ContentStatus::PENDING) {
            return ['success' => false, 'error' => 'Der Inhalt wartet nicht auf eine Prüfung.'];
        }
        if (!$this->canPublish($type, $userId, (int) ($row->author_id ?? 0), $can, $this->lastSubmitterId($type, $id))) {
            return ['success' => false, 'error' => $this->isSecondPersonRequired()
                ? 'Eigene oder selbst eingereichte Inhalte muss eine zweite Person freigeben.'
                : 'Ihnen fehlt das Recht zum Veröffentlichen.'];
        }

        $table = $this->prefix . self::TABLES[$type];
        $now = $this->now();
        $statement = $this->db->execute(
            "UPDATE {$table} SET status = ?, published_at = COALESCE(published_at, ?), updated_at = ? WHERE id = ? AND status = ?",
            [ContentStatus::PUBLISHED, $now, $now, $id, ContentStatus::PENDING]
        );
        if ($statement->rowCount() < 1) {
            return ['success' => false, 'error' => 'Der Inhalt wurde zwischenzeitlich geändert. Bitte neu laden.'];
        }

        $this->recordTransition($type, $id, ContentStatus::PENDING, ContentStatus::PUBLISHED, $userId, $comment);
        $this->runSaveHook($type, $id, ContentStatus::PUBLISHED);

        $publishedAt = strtotime((string) ($row->published_at ?? ''));

        return ['success' => true, 'message' => $publishedAt !== false && $publishedAt > ($this->clock)()
            ? 'Freigegeben – der Inhalt erscheint zum geplanten Termin.'
            : 'Freigegeben und veröffentlicht.'];
    }

    /**
     * Zurückgeben: `pending` → `draft`, Begründung ist Pflicht.
     *
     * @return array{success: bool, error?: string, message?: string}
     */
    public function returnToAuthor(string $type, int $id, int $userId, string $comment, ?callable $can = null): array
    {
        $comment = self::cleanComment($comment);
        if ($comment === '') {
            return ['success' => false, 'error' => 'Bitte eine Begründung für die Rückgabe angeben.'];
        }
        $row = $this->loadContent($type, $id);
        if ($row === null) {
            return ['success' => false, 'error' => 'Der Inhalt existiert nicht mehr.'];
        }
        if ((string) ($row->status ?? '') !== ContentStatus::PENDING) {
            return ['success' => false, 'error' => 'Der Inhalt wartet nicht auf eine Prüfung.'];
        }
        if (!$this->canPublish($type, $userId, (int) ($row->author_id ?? 0), $can, $this->lastSubmitterId($type, $id))) {
            return ['success' => false, 'error' => 'Zurückgeben darf nur, wer den Inhalt auch freigeben dürfte.'];
        }

        $table = $this->prefix . self::TABLES[$type];
        $statement = $this->db->execute(
            "UPDATE {$table} SET status = ?, updated_at = ? WHERE id = ? AND status = ?",
            [ContentStatus::DRAFT, $this->now(), $id, ContentStatus::PENDING]
        );
        if ($statement->rowCount() < 1) {
            return ['success' => false, 'error' => 'Der Inhalt wurde zwischenzeitlich geändert. Bitte neu laden.'];
        }

        $this->recordTransition($type, $id, ContentStatus::PENDING, ContentStatus::DRAFT, $userId, $comment);
        $this->runSaveHook($type, $id, ContentStatus::DRAFT);

        return ['success' => true, 'message' => 'Zurückgegeben – der Inhalt ist wieder ein Entwurf.'];
    }

    /**
     * Einreichen ohne Speichern (z. B. aus der Liste): `draft` → `pending`.
     *
     * @return array{success: bool, error?: string, message?: string}
     */
    public function submit(string $type, int $id, int $userId, string $comment = ''): array
    {
        $row = $this->loadContent($type, $id);
        if ($row === null) {
            return ['success' => false, 'error' => 'Der Inhalt existiert nicht mehr.'];
        }
        $from = (string) ($row->status ?? '');
        if ($from !== ContentStatus::DRAFT) {
            return ['success' => false, 'error' => 'Nur Entwürfe können zur Prüfung eingereicht werden.'];
        }
        $table = $this->prefix . self::TABLES[$type];
        $changed = $this->db->execute(
            "UPDATE {$table} SET status = ?, updated_at = ? WHERE id = ? AND status = ?",
            [ContentStatus::PENDING, $this->now(), $id, ContentStatus::DRAFT]
        )->rowCount();
        // FUN-410-11: Status inzwischen geändert (parallele Bearbeitung) → kein Verlauf, keine Benachrichtigung.
        if ($changed < 1) {
            return ['success' => false, 'error' => 'Der Status wurde inzwischen geändert. Bitte die Seite neu laden.'];
        }
        $this->recordTransition($type, $id, $from, ContentStatus::PENDING, $userId, $comment);

        return ['success' => true, 'message' => 'Zur Prüfung eingereicht.'];
    }

    /**
     * @return array{success: bool, error?: string, message?: string}
     */
    public function addComment(string $type, int $id, int $userId, string $comment): array
    {
        $comment = self::cleanComment($comment);
        if ($comment === '') {
            return ['success' => false, 'error' => 'Der Kommentar ist leer.'];
        }
        $row = $this->loadContent($type, $id);
        if ($row === null) {
            return ['success' => false, 'error' => 'Der Inhalt existiert nicht mehr.'];
        }
        $status = (string) ($row->status ?? '');
        $this->addEvent($type, $id, self::ACTION_COMMENT, $status, $status, $userId, $comment);

        return ['success' => true, 'message' => 'Kommentar gespeichert.'];
    }

    // ── Lesen ───────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Verlauf eines Inhalts, neueste zuerst.
     *
     * @return list<array{action: string, from_status: string, to_status: string, user_id: int, user_name: string, comment: string, created_at: string}>
     */
    public function history(string $type, int $id, int $limit = 50): array
    {
        if (!self::isSupportedType($type) || $id <= 0) {
            return [];
        }
        try {
            $rows = $this->db->get_results(
                "SELECT e.action, e.from_status, e.to_status, e.user_id, e.comment, e.created_at,
                        COALESCE(NULLIF(u.display_name, ''), u.username, '') AS user_name
                 FROM {$this->prefix}content_workflow_events e
                 LEFT JOIN {$this->prefix}users u ON u.id = e.user_id
                 WHERE e.content_type = ? AND e.content_id = ?
                 ORDER BY e.created_at DESC, e.id DESC
                 LIMIT " . max(1, min(200, $limit)),
                [$type, $id]
            ) ?: [];
        } catch (\Throwable $e) {
            $this->logWarning('Freigabe-Verlauf konnte nicht gelesen werden.', $e);

            return [];
        }

        return array_map(static fn (object $row): array => [
            'action' => (string) ($row->action ?? ''),
            'from_status' => (string) ($row->from_status ?? ''),
            'to_status' => (string) ($row->to_status ?? ''),
            'user_id' => (int) ($row->user_id ?? 0),
            'user_name' => (string) ($row->user_name ?? ''),
            'comment' => (string) ($row->comment ?? ''),
            'created_at' => (string) ($row->created_at ?? ''),
        ], $rows);
    }

    public static function actionLabel(string $action): string
    {
        return match ($action) {
            self::ACTION_SUBMITTED => 'Zur Prüfung eingereicht',
            self::ACTION_APPROVED => 'Freigegeben',
            self::ACTION_RETURNED => 'Zurückgegeben',
            self::ACTION_WITHDRAWN => 'Einreichung zurückgezogen',
            self::ACTION_COMMENT => 'Kommentar',
            self::ACTION_PREVIEW_CREATED => 'Vorschau-Link erstellt',
            self::ACTION_PREVIEW_REVOKED => 'Vorschau-Links widerrufen',
            default => $action,
        };
    }

    /**
     * Wartende Inhalte beider Typen, älteste Einreichung zuerst. Mit $reviewerId nur solche, die dieses Konto
     * freigeben dürfte (Einstellung „zweite Person“: keine eigenen und keine selbst eingereichten).
     *
     * @param callable(string): bool|null $can
     * @return list<array{type: string, id: int, title: string, author_id: int, author_name: string, submitted_at: string, submitted_by: int, edit_url: string}>
     */
    public function pendingItems(int $limit = 10, int $reviewerId = 0, ?callable $can = null, string $onlyType = ''): array
    {
        $items = [];
        foreach (self::TABLES as $type => $table) {
            if ($onlyType !== '' && $onlyType !== $type) {
                continue;
            }
            try {
                $rows = $this->db->get_results(
                    "SELECT c.id, c.title, c.author_id, c.updated_at,
                            COALESCE(NULLIF(u.display_name, ''), u.username, '') AS author_name
                     FROM {$this->prefix}{$table} c
                     LEFT JOIN {$this->prefix}users u ON u.id = c.author_id
                     WHERE c.status = ?
                     ORDER BY c.updated_at ASC
                     LIMIT 200",
                    [ContentStatus::PENDING]
                ) ?: [];
            } catch (\Throwable $e) {
                $this->logWarning('Wartende Inhalte konnten nicht gelesen werden.', $e);
                continue;
            }
            foreach ($rows as $row) {
                $id = (int) ($row->id ?? 0);
                $submission = $this->lastSubmission($type, $id);
                $item = [
                    'type' => $type,
                    'id' => $id,
                    'title' => (string) ($row->title ?? ''),
                    'author_id' => (int) ($row->author_id ?? 0),
                    'author_name' => (string) ($row->author_name ?? ''),
                    'submitted_at' => $submission['created_at'] !== '' ? $submission['created_at'] : (string) ($row->updated_at ?? ''),
                    'submitted_by' => $submission['user_id'],
                    'edit_url' => self::editUrl($type, $id),
                ];
                if ($reviewerId > 0 && !$this->canPublish($type, $reviewerId, $item['author_id'], $can, $item['submitted_by'])) {
                    continue;
                }
                $items[] = $item;
            }
        }
        usort($items, static fn (array $a, array $b): int => strcmp($a['submitted_at'], $b['submitted_at']));

        return array_slice($items, 0, max(1, $limit));
    }

    /**
     * Anzahl wartender Inhalte, die $reviewerId freigeben dürfte (0 = alle).
     *
     * @param callable(string): bool|null $can
     */
    public function pendingCount(int $reviewerId = 0, ?callable $can = null, string $onlyType = ''): int
    {
        return count($this->pendingItems(500, $reviewerId, $can, $onlyType));
    }

    // ── Vorschau-Links ──────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @return array{success: bool, error?: string, token?: string, url?: string, expires_at?: string}
     */
    public function createPreviewLink(string $type, int $id, int $userId): array
    {
        $row = $this->loadContent($type, $id);
        if ($row === null || (string) ($row->status ?? '') === ContentStatus::TRASH) {
            return ['success' => false, 'error' => 'Für diesen Inhalt kann kein Vorschau-Link erstellt werden.'];
        }
        $now = ($this->clock)();
        // FUN-410-12: Bereits öffentliche Inhalte brauchen keinen Vorschau-Link (REVIEW.md); geplante schon.
        $publishedAt = (string) ($row->published_at ?? '');
        if ((string) ($row->status ?? '') === ContentStatus::PUBLISHED && ($publishedAt === '' || strtotime($publishedAt) <= $now)) {
            return ['success' => false, 'error' => 'Der Inhalt ist bereits öffentlich – ein Vorschau-Link ist nicht nötig.'];
        }
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', $now + self::PREVIEW_TTL_SECONDS);
        $this->db->execute(
            "INSERT INTO {$this->prefix}content_preview_links (content_type, content_id, token_hash, expires_at, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$type, $id, self::hashPreviewToken($token), $expiresAt, $userId > 0 ? $userId : null, date('Y-m-d H:i:s', $now)]
        );
        $status = (string) ($row->status ?? '');
        $this->addEvent($type, $id, self::ACTION_PREVIEW_CREATED, $status, $status, $userId, 'gültig bis ' . $expiresAt);

        return ['success' => true, 'token' => $token, 'url' => self::previewPath($token), 'expires_at' => $expiresAt];
    }

    public static function previewPath(string $token): string
    {
        $base = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';

        return $base . '/preview/' . $token;
    }

    /**
     * Gültiger Token → Inhalt; abgelaufen, widerrufen, unbekannt oder Inhalt im Papierkorb → null.
     *
     * @return array{type: string, id: int}|null
     */
    public function resolvePreviewToken(string $token): ?array
    {
        if (!self::isWellFormedPreviewToken($token)) {
            return null;
        }
        try {
            $link = $this->db->get_row(
                "SELECT content_type, content_id FROM {$this->prefix}content_preview_links WHERE token_hash = ? AND expires_at > ? LIMIT 1",
                [self::hashPreviewToken($token), $this->now()]
            );
        } catch (\Throwable $e) {
            $this->logWarning('Vorschau-Link konnte nicht geprüft werden.', $e);

            return null;
        }
        if ($link === null) {
            return null;
        }
        $type = (string) ($link->content_type ?? '');
        $id = (int) ($link->content_id ?? 0);
        $row = $this->loadContent($type, $id);
        if ($row === null || (string) ($row->status ?? '') === ContentStatus::TRASH) {
            return null;
        }

        return ['type' => $type, 'id' => $id];
    }

    /**
     * Aktive Vorschau-Links eines Inhalts (ohne Token – der ist nur beim Erstellen bekannt).
     *
     * @return list<array{id: int, expires_at: string, created_at: string, created_by_name: string}>
     */
    public function activePreviewLinks(string $type, int $id): array
    {
        if (!self::isSupportedType($type) || $id <= 0) {
            return [];
        }
        try {
            $rows = $this->db->get_results(
                "SELECT l.id, l.expires_at, l.created_at, COALESCE(NULLIF(u.display_name, ''), u.username, '') AS created_by_name
                 FROM {$this->prefix}content_preview_links l
                 LEFT JOIN {$this->prefix}users u ON u.id = l.created_by
                 WHERE l.content_type = ? AND l.content_id = ? AND l.expires_at > ?
                 ORDER BY l.created_at DESC",
                [$type, $id, $this->now()]
            ) ?: [];
        } catch (\Throwable $e) {
            $this->logWarning('Vorschau-Links konnten nicht gelesen werden.', $e);

            return [];
        }

        return array_map(static fn (object $row): array => [
            'id' => (int) ($row->id ?? 0),
            'expires_at' => (string) ($row->expires_at ?? ''),
            'created_at' => (string) ($row->created_at ?? ''),
            'created_by_name' => (string) ($row->created_by_name ?? ''),
        ], $rows);
    }

    /**
     * Widerruft alle Vorschau-Links eines Inhalts; gibt die Zahl der widerrufenen Links zurück.
     */
    public function revokePreviewLinks(string $type, int $id, int $userId): int
    {
        if (!self::isSupportedType($type) || $id <= 0) {
            return 0;
        }
        $count = $this->db->execute(
            "DELETE FROM {$this->prefix}content_preview_links WHERE content_type = ? AND content_id = ?",
            [$type, $id]
        )->rowCount();
        if ($count > 0) {
            $row = $this->loadContent($type, $id);
            $status = (string) ($row->status ?? '');
            $this->addEvent($type, $id, self::ACTION_PREVIEW_REVOKED, $status, $status, $userId, (string) $count);
        }

        return $count;
    }

    /** Abgelaufene Vorschau-Links entfernen (Cron). */
    public function purgeExpiredPreviewLinks(): int
    {
        try {
            return $this->db->execute(
                "DELETE FROM {$this->prefix}content_preview_links WHERE expires_at <= ?",
                [$this->now()]
            )->rowCount();
        } catch (\Throwable $e) {
            $this->logWarning('Abgelaufene Vorschau-Links konnten nicht entfernt werden.', $e);

            return 0;
        }
    }

    // ── Rechte-Migration ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Update-Schritt v26: `posts.publish` und `pages.publish` werden ab 4.1.0 geprüft. Damit Rollen, die heute
     * veröffentlichen können, das auch weiter dürfen, erhalten sie das Recht – nur dort, wo die Rolle dazu noch
     * keinen gespeicherten Eintrag hat (ein bewusst entzogenes Recht bleibt entzogen).
     *
     * @return list<array{0: string, 1: string}> die neu vergebenen [Rolle, Capability]
     */
    public static function migrateCapabilities(Database $db): array
    {
        $grants = [['editor', 'posts.publish'], ['editor', 'pages.publish'], ['author', 'posts.publish']];
        $table = $db->getPrefix() . 'role_permissions';
        $granted = [];
        foreach ($grants as [$role, $capability]) {
            $existing = $db->get_var("SELECT COUNT(*) FROM {$table} WHERE role = ? AND capability = ?", [$role, $capability]);
            if ((int) $existing > 0) {
                continue;
            }
            $db->execute("INSERT INTO {$table} (role, capability, granted) VALUES (?, ?, 1)", [$role, $capability]);
            $granted[] = [$role, $capability];
        }

        return $granted;
    }

    // ── Intern ──────────────────────────────────────────────────────────────────────────────────────────────────

    private function loadContent(string $type, int $id): ?object
    {
        if (!self::isSupportedType($type) || $id <= 0) {
            return null;
        }
        try {
            return $this->db->get_row(
                "SELECT id, title, status, author_id, published_at FROM {$this->prefix}" . self::TABLES[$type] . ' WHERE id = ? LIMIT 1',
                [$id]
            );
        } catch (\Throwable $e) {
            $this->logWarning('Inhalt konnte nicht geladen werden.', $e);

            return null;
        }
    }

    /**
     * @param list<int> $ids
     * @return list<object>
     */
    private function loadContentRows(string $type, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if (!self::isSupportedType($type) || $ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return $this->db->get_results(
            "SELECT id, status, author_id FROM {$this->prefix}" . self::TABLES[$type] . " WHERE id IN ({$placeholders})",
            $ids
        ) ?: [];
    }

    /**
     * @return array{user_id: int, created_at: string}
     */
    private function lastSubmission(string $type, int $id): array
    {
        try {
            $row = $this->db->get_row(
                "SELECT user_id, created_at FROM {$this->prefix}content_workflow_events
                 WHERE content_type = ? AND content_id = ? AND action = ?
                 ORDER BY created_at DESC, id DESC LIMIT 1",
                [$type, $id, self::ACTION_SUBMITTED]
            );
        } catch (\Throwable) {
            $row = null;
        }

        return ['user_id' => (int) ($row->user_id ?? 0), 'created_at' => (string) ($row->created_at ?? '')];
    }

    /** Wer hat den Inhalt zuletzt zur Prüfung eingereicht? 0 = nie eingereicht. */
    public function lastSubmitterId(string $type, int $id): int
    {
        return $this->lastSubmission($type, $id)['user_id'];
    }

    private function addEvent(string $type, int $id, string $action, string $from, string $to, int $userId, string $comment): void
    {
        try {
            $this->db->execute(
                "INSERT INTO {$this->prefix}content_workflow_events
                 (content_type, content_id, locale, action, from_status, to_status, user_id, comment, created_at)
                 VALUES (?, ?, '', ?, ?, ?, ?, ?, ?)",
                [$type, $id, $action, $from !== '' ? $from : null, $to !== '' ? $to : null, $userId > 0 ? $userId : null, self::cleanComment($comment) ?: null, $this->now()]
            );
        } catch (\Throwable $e) {
            $this->logWarning('Freigabe-Schritt konnte nicht gespeichert werden.', $e);
        }
    }

    private function notify(string $action, string $type, int $id, string $title, int $actorId, int $authorId, int $submitterId, string $comment): void
    {
        if (!in_array($action, [self::ACTION_SUBMITTED, self::ACTION_APPROVED, self::ACTION_RETURNED], true)) {
            return;
        }
        try {
            $users = $this->db->get_results(
                "SELECT id, role, email, display_name, username FROM {$this->prefix}users WHERE status = 'active'"
            ) ?: [];
            $ids = self::recipientIds($action, $type, $users, $this->roleCan, $actorId, $authorId, $submitterId);
            if ($ids === []) {
                return;
            }
            $recipients = array_values(array_filter($users, static fn (object $user): bool => in_array((int) ($user->id ?? 0), $ids, true)));
            $label = $type === self::TYPE_PAGE ? 'Seite' : 'Beitrag';
            $title = $title !== '' ? $title : '#' . $id;
            $subject = match ($action) {
                self::ACTION_SUBMITTED => sprintf('Zur Prüfung: %s „%s“', $label, $title),
                self::ACTION_APPROVED => sprintf('Freigegeben: %s „%s“', $label, $title),
                default => sprintf('Zurückgegeben: %s „%s“', $label, $title),
            };
            $message = match ($action) {
                self::ACTION_SUBMITTED => sprintf('%s „%s“ wartet auf eine Prüfung.', $label, $title),
                self::ACTION_APPROVED => sprintf('%s „%s“ wurde freigegeben.', $label, $title),
                default => sprintf('%s „%s“ wurde zur Überarbeitung zurückgegeben.', $label, $title),
            };
            $comment = self::cleanComment($comment);
            if ($comment !== '') {
                $message .= "\n\nKommentar: " . $comment;
            }
            ($this->notifier)($recipients, $subject, $message, self::editUrl($type, $id));
        } catch (\Throwable $e) {
            $this->logWarning('Benachrichtigung zum Freigabe-Ablauf fehlgeschlagen.', $e);
        }
    }

    /**
     * Standard-Versand: In-App-Hinweis (NotificationService) und Mail über die Mail-Warteschlange.
     *
     * @param list<object> $recipients
     */
    private static function deliverNotifications(array $recipients, string $subject, string $message, string $url): void
    {
        $siteUrl = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
        foreach ($recipients as $recipient) {
            $userId = (int) ($recipient->id ?? 0);
            try {
                \CMS\Services\NotificationService::getInstance()->create($userId, 'system', $subject, $message, $url);
            } catch (\Throwable) {
            }
            $email = trim((string) ($recipient->email ?? ''));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            try {
                \CMS\Services\MailQueueService::getInstance()->enqueuePlain(
                    $email,
                    $subject,
                    $message . "\n\nZum Inhalt: " . $siteUrl . $url,
                    [],
                    null,
                    'content_workflow'
                );
            } catch (\Throwable) {
            }
        }
    }

    private function runSaveHook(string $type, int $id, string $status): void
    {
        try {
            Hooks::doAction(self::SAVE_HOOKS[$type], $id, ['status' => $status, 'workflow' => true], []);
        } catch (\Throwable $e) {
            $this->logWarning('Folgeaktionen nach Freigabe-Schritt fehlgeschlagen.', $e);
        }
    }

    public static function cleanComment(string $comment): string
    {
        $comment = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $comment));

        return function_exists('mb_substr') ? mb_substr($comment, 0, self::COMMENT_MAX, 'UTF-8') : substr($comment, 0, self::COMMENT_MAX);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s', ($this->clock)());
    }

    private function logWarning(string $message, \Throwable $e): void
    {
        try {
            Logger::instance()->withChannel('content.workflow')->warning($message, ['exception' => $e->getMessage()]);
        } catch (\Throwable) {
        }
    }
}
