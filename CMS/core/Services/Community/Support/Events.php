<?php
/**
 * Hook-Namen des Community-Moduls an einer Stelle und der gekapselte Aufruf.
 *
 * Aktionen werden nach dem Commit über `fire()` ausgelöst, nur bei einer Änderung; Systemaktionen tragen den Akteur 0.
 * Argumente sind IDs und Statuswerte, nie Inhalte (Ausnahme: der Filter `SPAM_CHECK` bekommt Titel und Text). Jeder
 * Verbraucher läuft einzeln abgesichert: ein Fehler wird protokolliert (Kanal `community`), bricht den Vorgang nicht ab und
 * hält die übrigen Verbraucher desselben Hooks nicht auf. Filter für Werte, die ein Plugin nur verändern darf (Ansicht, Text), laufen
 * über `applyFilter()`; bei einer Ausnahme gilt der Eingangswert. Entscheidungsfilter mit Vetorecht (`cms_community_can`) laufen
 * nicht darüber: `filters()` liefert die Kette, der Aufrufer durchläuft sie selbst, prüft jedes Zwischenergebnis und wertet eine
 * Ausnahme als Ablehnung (siehe `CommunityAccess`).
 *
 * Signaturen: DOC/core/HOOKS-REFERENCE.md, Abschnitt „Community (4.2.0)“.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Support;

use CMS\Hooks;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class Events
{
    // ── Inhalte (#2a) ──────────────────────────────────────────────────────────────────────────────────────────

    /** Thema samt erstem Beitrag angelegt: `int $topicId, int $forumId, int $userId, string $status, int $firstPostId`. */
    public const TOPIC_CREATED = 'cms_community_topic_created';
    /** Thema geändert (Titel, Schließen, Anheften, Ankündigung, Umbenennen): `int $topicId, int $forumId, int $actorId, array $changed`. */
    public const TOPIC_UPDATED = 'cms_community_topic_updated';
    /** Thema gelöscht: `int $topicId, int $forumId, int $actorId, string $mode`. */
    public const TOPIC_DELETED = 'cms_community_topic_deleted';
    /** Antwort angelegt (nicht der Themenstart): `int $postId, int $topicId, int $userId, string $status`. */
    public const POST_CREATED = 'cms_community_post_created';
    /** Beitrag bearbeitet: `int $postId, int $topicId, int $editorId, int $revisionId` (`revisionId` 0: nur die Anhänge geändert). */
    public const POST_UPDATED = 'cms_community_post_updated';
    /** Beitrag gelöscht: `int $postId, int $topicId, int $actorId, string $mode`. */
    public const POST_DELETED = 'cms_community_post_deleted';
    /** Filter der Zugriffsentscheidung: `AccessDecision $decision, int $userId, string $action, array $context`. */
    public const CAN = 'cms_community_can';

    // ── Moderation (#2b; Akteur 0 = automatisch) ──────────────────────────────────────────────────────────────

    /** Antwort freigegeben (erster Beitrag: TOPIC_APPROVED): `int $postId, int $topicId, int $moderatorId`. */
    public const POST_APPROVED = 'cms_community_post_approved';
    /** Beitrag abgelehnt, den Grund liefert das Protokoll: `int $postId, int $topicId, int $moderatorId, int $logId`. */
    public const POST_REJECTED = 'cms_community_post_rejected';
    /** Beitrag ausgeblendet: `int $postId, int $topicId, int $actorId, int $logId`. */
    public const POST_HIDDEN = 'cms_community_post_hidden';
    /** Beitrag wiederhergestellt: `int $postId, int $topicId, int $actorId, string $fromStatus`. */
    public const POST_RESTORED = 'cms_community_post_restored';
    /** Beitrag in ein anderes Thema verschoben (je Beitrag, aufsteigend): `int $postId, int $fromTopicId, int $toTopicId, int $actorId`. */
    public const POST_MOVED = 'cms_community_post_moved';
    /** Thema freigegeben: `int $topicId, int $forumId, int $moderatorId, int $firstPostId`. */
    public const TOPIC_APPROVED = 'cms_community_topic_approved';
    /** Thema abgelehnt (danach `deleted`): `int $topicId, int $forumId, int $moderatorId, int $logId`. */
    public const TOPIC_REJECTED = 'cms_community_topic_rejected';
    /** Thema ausgeblendet: `int $topicId, int $forumId, int $actorId, int $logId`. */
    public const TOPIC_HIDDEN = 'cms_community_topic_hidden';
    /** Thema wiederhergestellt: `int $topicId, int $forumId, int $actorId, string $status`. */
    public const TOPIC_RESTORED = 'cms_community_topic_restored';
    /** Thema verschoben (`stubTopicId` 0: kein Verweis): `int $topicId, int $fromForumId, int $toForumId, int $actorId, int $stubTopicId`. */
    public const TOPIC_MOVED = 'cms_community_topic_moved';
    /** P2, in 4.2.0 nicht ausgelöst: `int $sourceTopicId, int $targetTopicId, int $actorId, int $logId`. */
    public const TOPIC_MERGED = 'cms_community_topic_merged';
    /** P2, in 4.2.0 nicht ausgelöst: `int $sourceTopicId, int $newTopicId, int $actorId, int $logId`. */
    public const TOPIC_SPLIT = 'cms_community_topic_split';
    /** Antwort als Lösung markiert: `int $topicId, int $postId, int $actorId`. */
    public const SOLUTION_MARKED = 'cms_community_solution_marked';
    /** Lösung aufgehoben (auch implizit beim Ausblenden, Verschieben, Löschen): `int $topicId, int $postId, int $actorId`. */
    public const SOLUTION_UNMARKED = 'cms_community_solution_unmarked';

    // ── Meldungen und Sperren (#2b) ───────────────────────────────────────────────────────────────────────────

    /** Meldung angelegt (nicht beim Zusammenführen): `int $reportId, string $targetType, int $targetId, int $reporterId`. */
    public const REPORT_CREATED = 'cms_community_report_created';
    /** Meldung erledigt (je geschlossene Meldung): `int $reportId, string $status, int $moderatorId`. */
    public const REPORT_RESOLVED = 'cms_community_report_resolved';
    /** Sperre verhängt (nicht bei `duplicate`): `int $userId, int $banId, string $scope, int $actorId`. */
    public const USER_BANNED = 'cms_community_user_banned';
    /** Sperre aufgehoben: `int $userId, int $banId, int $actorId`. */
    public const USER_UNBANNED = 'cms_community_user_unbanned';
    /** P2, in 4.2.0 nicht ausgelöst: `int $userId, int $warningId, int $actorId`. */
    public const USER_WARNED = 'cms_community_user_warned';

    // ── Interaktion, Darstellung, Schutz (#2b) ────────────────────────────────────────────────────────────────

    /** Reaktion gesetzt, geändert oder entfernt (`''` = keine): `int $postId, int $userId, string $reaction, string $previous`. */
    public const REACTION_CHANGED = 'cms_community_reaction_changed';
    /** Abo geändert (`mode` `''` = abbestellt): `int $userId, string $targetType, int $targetId, string $mode`. */
    public const SUBSCRIPTION_CHANGED = 'cms_community_subscription_changed';
    /** Filter des angezeigten HTML (nicht gespeichert, nicht erneut gereinigt; Vorschau mit `postId` 0): `string $html, int $postId`. */
    public const RENDER_POST = 'cms_community_render_post';
    /**
     * Filter des Spamschutzes, einzige Ausnahme von „nur IDs und Status“ (`$input` enthält Titel und Text); darf nur verschärfen
     * (`allow` → `review`), eine Ausnahme ergibt `review`: `string $verdict, int $userId, string $action, array $input`.
     */
    public const SPAM_CHECK = 'cms_community_spam_check';
    /** Filter der Benachrichtigungstypen `community.*`: `array $types`. */
    public const NOTIFICATION_TYPES = 'cms_community_notification_types';

    /**
     * Löst eine Aktion aus; wirft nie. Jeder Verbraucher läuft einzeln abgesichert (`Hooks::doActionIsolated()`): wirft einer,
     * wird der Fehler protokolliert und die übrigen Verbraucher des Hooks laufen trotzdem.
     */
    public static function fire(string $hook, mixed ...$args): void
    {
        try {
            Hooks::doActionIsolated($hook, static function (\Throwable $e) use ($hook): void {
                self::log($hook, $e);
            }, ...$args);
        } catch (\Throwable $e) {
            self::log($hook, $e);
        }
    }

    /**
     * Die Filter eines Hooks in Ausführungsreihenfolge (Priorität aufsteigend), für Entscheidungsfilter: der Aufrufer ruft jeden
     * einzeln auf, prüft das Ergebnis gegen den laufenden Wert und fängt Ausnahmen selbst ab. Wirft nie.
     *
     * @return list<callable>
     */
    public static function filters(string $hook): array
    {
        try {
            return Hooks::getCallbacks($hook, 'filter');
        } catch (\Throwable $e) {
            self::log($hook, $e);

            return [];
        }
    }

    /**
     * Wendet einen Filter an; bei einer Ausnahme gilt der Eingangswert (die Kette bricht dann ab). Nicht für Entscheidungen,
     * die ein Plugin nur ablehnen darf: dort würde eine Ausnahme die Ablehnung eines früheren Filters aufheben, siehe `filters()`.
     */
    public static function applyFilter(string $hook, mixed $value, mixed ...$args): mixed
    {
        try {
            return Hooks::applyFilters($hook, $value, ...$args);
        } catch (\Throwable $e) {
            self::log($hook, $e);

            return $value;
        }
    }

    private static function log(string $hook, \Throwable $e): void
    {
        Logger::instance()->withChannel('community')->warning('Community-Hook {hook} fehlgeschlagen: {exception}', [
            'hook' => $hook,
            'exception' => $e,
        ]);
    }
}
