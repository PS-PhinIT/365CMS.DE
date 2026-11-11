<?php
/**
 * Element-Rechte für Seiten und Beiträge außerhalb der Editoren (Redaktionskalender, Übersetzungsstatus).
 *
 * Gleiche Regeln wie die Editoren: Beiträge darf bearbeiten, wer `edit_all_posts` hat oder Autor des Beitrags
 * ist und `edit_own_posts` besitzt; Seiten verlangen `manage_pages`. Die Prüfung erhält die Capability-Abfrage
 * als Callback und ist dadurch ohne Datenbank testbar.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Auth;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentEditPermission
{
    public const TYPE_POST = 'post';
    public const TYPE_PAGE = 'page';

    /**
     * @param callable(string):bool $can Capability-Abfrage des aktuellen Benutzers
     */
    public static function canEdit(string $type, int $authorId, int $userId, callable $can): bool
    {
        if ($type === self::TYPE_POST) {
            if ($can('edit_all_posts')) {
                return true;
            }

            return $userId > 0 && $authorId === $userId && $can('edit_own_posts');
        }

        if ($type === self::TYPE_PAGE) {
            return $can('manage_pages');
        }

        return false;
    }

    /**
     * Darf der Benutzer überhaupt Inhalte dieses Typs bearbeiten (unabhängig vom einzelnen Element)?
     *
     * @param callable(string):bool $can
     */
    public static function canEditAnyOfType(string $type, callable $can): bool
    {
        return match ($type) {
            self::TYPE_POST => $can('edit_all_posts') || $can('edit_own_posts'),
            self::TYPE_PAGE => $can('manage_pages'),
            default => false,
        };
    }

    public static function currentUserCanEdit(string $type, int $authorId): bool
    {
        $auth = Auth::instance();

        return self::canEdit($type, $authorId, self::currentUserId(), static fn (string $capability): bool => $auth->hasCapability($capability));
    }

    public static function currentUserId(): int
    {
        $user = Auth::instance()->currentUser();

        return (int) ($user->id ?? 0);
    }
}
