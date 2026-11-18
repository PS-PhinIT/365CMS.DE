<?php
/**
 * Hook-Namen des Community-Moduls an einer Stelle und der gekapselte Aufruf.
 *
 * Aktionen werden nach dem Commit über `fire()` ausgelöst; Argumente sind IDs und Statuswerte, nie Inhalte. Ein Fehler
 * in einem Callback wird protokolliert (Kanal `community`) und bricht den Vorgang nicht ab. Filter laufen über
 * `applyFilter()`; bei einer Ausnahme gilt der Eingangswert. Das Ergebnis eines Filters prüft der Aufrufer selbst
 * (`cms_community_can` darf nur ablehnen).
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
    /** Thema samt erstem Beitrag angelegt: `int $topicId, int $forumId, int $userId, string $status, int $firstPostId`. */
    public const TOPIC_CREATED = 'cms_community_topic_created';
    /** Thema geändert: `int $topicId, int $forumId, int $actorId, array $changed`. */
    public const TOPIC_UPDATED = 'cms_community_topic_updated';
    /** Thema gelöscht: `int $topicId, int $forumId, int $actorId, string $mode`. */
    public const TOPIC_DELETED = 'cms_community_topic_deleted';
    /** Antwort angelegt (nicht der Themenstart): `int $postId, int $topicId, int $userId, string $status`. */
    public const POST_CREATED = 'cms_community_post_created';
    /** Beitrag bearbeitet: `int $postId, int $topicId, int $editorId, int $revisionId`. */
    public const POST_UPDATED = 'cms_community_post_updated';
    /** Beitrag gelöscht: `int $postId, int $topicId, int $actorId, string $mode`. */
    public const POST_DELETED = 'cms_community_post_deleted';
    /** Filter der Zugriffsentscheidung: `AccessDecision $decision, int $userId, string $action, array $context`. */
    public const CAN = 'cms_community_can';

    // Namen für PR #2b und später: dokumentiert, in #2a nicht ausgelöst.
    public const POST_APPROVED = 'cms_community_post_approved';
    public const POST_REJECTED = 'cms_community_post_rejected';
    public const TOPIC_MOVED = 'cms_community_topic_moved';
    public const TOPIC_MERGED = 'cms_community_topic_merged';
    public const RENDER_POST = 'cms_community_render_post';
    public const REPORT_CREATED = 'cms_community_report_created';
    public const REPORT_RESOLVED = 'cms_community_report_resolved';
    public const USER_WARNED = 'cms_community_user_warned';
    public const USER_BANNED = 'cms_community_user_banned';
    public const USER_UNBANNED = 'cms_community_user_unbanned';
    public const SOLUTION_MARKED = 'cms_community_solution_marked';

    /** Löst eine Aktion aus; wirft nie. */
    public static function fire(string $hook, mixed ...$args): void
    {
        try {
            Hooks::doAction($hook, ...$args);
        } catch (\Throwable $e) {
            self::log($hook, $e);
        }
    }

    /** Wendet einen Filter an; bei einer Ausnahme gilt der Eingangswert. */
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
