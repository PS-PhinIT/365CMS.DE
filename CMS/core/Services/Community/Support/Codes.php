<?php
/**
 * Alle maschinenlesbaren Codes des Community-Moduls an einer Stelle: Ablehnungsgründe von `CommunityAccess::can()` und
 * Fehlercodes der Dienste. Die Oberfläche übersetzt sie über die Sprachschlüssel `community.access.<code>` und
 * `community.error.<code>` (CMS/lang/de.yaml und en.yaml); `TESTS/community-settings` prüft, dass jeder Code dort
 * einen Eintrag hat. Neue Codes gehören hierher und in beide Sprachdateien.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class Codes
{
    /**
     * Ablehnungsgründe (`AccessDecision::$reason`) mit der HTTP-Zuordnung für die Oberfläche (PR #4).
     * 404 steht für „gibt es nicht oder darfst du nicht sehen“ (identische Antwort, SECURITY-PRIVACY T8).
     *
     * @var array<string, int>
     */
    public const ACCESS_REASONS = [
        'unknown_action' => 500,
        'module_disabled' => 404,
        'login_required' => 401,
        'email_unverified' => 403,
        'banned_full' => 403,
        'banned_write' => 403,
        'no_capability' => 403,
        'not_found' => 404,
        'no_permission' => 403,
        'trust_limit' => 403,
        'pending_limit' => 403,
        'forum_readonly' => 403,
        'forum_archived' => 403,
        'forum_link' => 403,
        'topic_locked' => 403,
        'topic_pending' => 403,
        'topic_deleted' => 403,
        'topic_moved' => 403,
        'post_not_editable' => 403,
        'edit_window' => 403,
        'slow_mode' => 429,
        'rate_limited' => 429,
        'filter_denied' => 403,
    ];

    /**
     * Fehlercodes der Dienste (`error` im Ergebnis). Ablehnungen von `can()` geben den Grund aus ACCESS_REASONS zurück.
     *
     * @var list<string>
     */
    public const ERRORS = [
        'invalid_input',
        'title_too_short',
        'title_too_long',
        'body_too_short',
        'body_too_long',
        'forum_not_found',
        'topic_not_found',
        'post_not_found',
        'first_post',
        'has_replies',
        'edit_conflict',
        'new_posts',
        'quote_unavailable',
        'slug_taken',
        'forum_depth',
        'db_error',
    ];

    /** Sprachschlüssel eines Ablehnungsgrundes. */
    public static function accessKey(string $reason): string
    {
        return 'community.access.' . $reason;
    }

    /** Sprachschlüssel eines Dienstfehlers. */
    public static function errorKey(string $code): string
    {
        return 'community.error.' . $code;
    }
}
