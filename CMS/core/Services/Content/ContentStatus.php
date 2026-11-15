<?php
/**
 * Statuswerte von Beiträgen und Seiten an einer Stelle (4.1.0, Bereich 3a).
 *
 * Gespeichert werden `draft`, `pending` (Zur Prüfung), `published`, `private` und `trash`. „Geplant“ ist kein eigener
 * Wert, sondern `published` mit einem Veröffentlichungsdatum in der Zukunft. `private` (nur für angemeldete
 * Mitglieder sichtbar) zählt als Veröffentlichung: Wer nicht veröffentlichen darf, darf auch nicht privat schalten.
 *
 * Frontend-Abfragen bleiben bei `status = 'published'` – `pending` ist nie öffentlich.
 *
 * @package CMSv2\Core\Services\Content
 */

declare(strict_types=1);

namespace CMS\Services\Content;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentStatus
{
    public const DRAFT = 'draft';
    public const PENDING = 'pending';
    public const PUBLISHED = 'published';
    public const PRIVATE = 'private';
    public const TRASH = 'trash';
    /** Anzeige-Status (kein gespeicherter Wert): `published` mit Datum in der Zukunft. */
    public const SCHEDULED = 'scheduled';

    /** Werte, die der Editor speichern kann. */
    public const EDITABLE = [self::DRAFT, self::PENDING, self::PUBLISHED, self::PRIVATE];

    /** Werte, die eine Veröffentlichung bedeuten (Recht `posts.publish`/`pages.publish` nötig). */
    public const PUBLISHING = [self::PUBLISHED, self::PRIVATE];

    private const LABELS = [
        self::DRAFT => ['Entwurf', 'bg-yellow-lt text-yellow'],
        self::PENDING => ['Zur Prüfung', 'bg-orange-lt text-orange'],
        self::PUBLISHED => ['Veröffentlicht', 'bg-green-lt text-green'],
        self::SCHEDULED => ['Geplant', 'bg-azure-lt text-azure'],
        self::PRIVATE => ['Privat', 'bg-purple-lt text-purple'],
        self::TRASH => ['Papierkorb', 'bg-red-lt text-red'],
    ];

    public static function isEditable(string $status): bool
    {
        return in_array($status, self::EDITABLE, true);
    }

    public static function isPublishing(string $status): bool
    {
        return in_array($status, self::PUBLISHING, true);
    }

    public static function isPublic(string $status): bool
    {
        return $status === self::PUBLISHED;
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status][0] ?? $status;
    }

    public static function badgeClass(string $status): string
    {
        return self::LABELS[$status][1] ?? 'bg-secondary-lt';
    }

    /**
     * Für die Status-Badges der Editoren (`statusMap` im Editor-Skript).
     *
     * @return array<string, array{label: string, className: string}>
     */
    public static function badgeMap(): array
    {
        $map = [];
        foreach ([self::DRAFT, self::PENDING, self::PUBLISHED, self::PRIVATE, self::SCHEDULED] as $status) {
            $map[$status] = ['label' => self::label($status), 'className' => 'badge ' . self::badgeClass($status)];
        }

        return $map;
    }
}
