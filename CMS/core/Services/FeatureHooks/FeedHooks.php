<?php
/**
 * Hooks des Funktionsbereichs „Feeds“.
 *
 * Verwirft den Cache der öffentlichen RSS-Feeds über die bestehenden Inhalts-Hooks, sobald Beiträge
 * gespeichert, veröffentlicht, kopiert oder gelöscht werden. Massenänderungen und Taxonomie-Pflege ohne
 * eigenen Hook fängt zusätzlich `Database::noteWrite()` ab.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Services\FeedService;

if (!defined('ABSPATH')) {
    exit;
}

final class FeedHooks
{
    public static function register(): void
    {
        $invalidate = static function (): void {
            FeedService::invalidatePublicFeedCache();
        };

        foreach (['cms_after_post_save', 'cms_after_post_copy_de_to_en', 'post_deleted'] as $hook) {
            Hooks::addAction($hook, $invalidate, 90);
        }
    }
}
