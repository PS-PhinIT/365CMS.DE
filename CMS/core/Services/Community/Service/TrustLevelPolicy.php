<?php
/**
 * Regel, die aus den Daten eines Mitglieds seine Vertrauensstufe (0 bis 4) bestimmt.
 *
 * `CommunityAccess` kennt nur diese Schnittstelle; in PR #2a liefert `BasicTrustPolicy` die Stufen 0 bis 2 aus
 * E-Mail-Bestätigung und Beitragszahl. Eine spätere Umsetzung (Tage seit Registrierung, gelesene Themen, Reaktionen,
 * Verwarnungen) ersetzt die Klasse, ohne `CommunityAccess` zu ändern. Die Implementierung ist rein: sie liest keine
 * Datenbank, die Daten bringt der Aufrufer mit.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

if (!defined('ABSPATH')) {
    exit;
}

interface TrustLevelPolicy
{
    /** Kleinste und größte Vertrauensstufe. */
    public const MIN_LEVEL = 0;
    public const MAX_LEVEL = 4;

    /**
     * Wirksame Vertrauensstufe eines Mitglieds.
     *
     * @param int  $userId       Konto-ID (für Umsetzungen, die weitere Daten nachladen; `BasicTrustPolicy` liest sie nicht)
     * @param bool $emailVerified E-Mail-Adresse bestätigt
     * @param int  $postCount     veröffentlichte Beiträge inkl. Themenstarts (`community_user_stats.post_count`)
     * @param int  $storedLevel   gespeicherte Stufe (`trust_level`; 0, wenn die Statistik-Zeile fehlt)
     * @param bool $storedLocked  von der Moderation festgesetzt (`trust_level_locked`)
     * @return int Stufe von MIN_LEVEL bis MAX_LEVEL
     */
    public function levelFor(int $userId, bool $emailVerified, int $postCount, int $storedLevel, bool $storedLocked): int;
}
