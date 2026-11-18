<?php
/**
 * Vertrauensstufen für PR #2a ohne nächtliche Neuberechnung (Karte §2.2, Entscheidung D10).
 *
 * - Festgesetzte Stufe (`trust_level_locked`): gilt unverändert, auch Stufe 3 und 4.
 * - Sonst Stufe 1 bei bestätigter E-Mail und mindestens `trust_level1_posts` veröffentlichten Beiträgen, Stufe 2 bei
 *   Stufe 1 und mindestens `trust_level2_posts` Beiträgen. Eine gespeicherte Stufe wird nie unterschritten
 *   (Stufe 3 und 4 entstehen nur manuell oder durch eine spätere Neuberechnung).
 * - Die Freigabepflicht neuer Mitglieder hängt nicht von der Stufe ab (`new_member_approval_posts`, `CommunityAccess`).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Services\Community\Support\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class BasicTrustPolicy implements TrustLevelPolicy
{
    private ?Settings $settings;

    public function __construct(?Settings $settings = null)
    {
        $this->settings = $settings;
    }

    public function levelFor(int $userId, bool $emailVerified, int $postCount, int $storedLevel, bool $storedLocked): int
    {
        $stored = max(self::MIN_LEVEL, min(self::MAX_LEVEL, $storedLevel));
        if ($storedLocked) {
            return $stored;
        }

        $settings = $this->settings ?? Settings::getInstance();
        $postCount = max(0, $postCount);
        $level = 0;
        if ($emailVerified && $postCount >= $settings->int('trust_level1_posts')) {
            $level = 1;
        }
        if ($level === 1 && $postCount >= $settings->int('trust_level2_posts')) {
            $level = 2;
        }

        return max($stored, $level);
    }
}
