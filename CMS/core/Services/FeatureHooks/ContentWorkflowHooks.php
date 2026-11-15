<?php
/**
 * Hooks des Freigabe-Ablaufs (Vier-Augen-Prinzip, 4.1.0, Bereich 3a): Badges „Zur Prüfung“ an Beiträgen und Seiten
 * im Admin-Menü und tägliches Aufräumen abgelaufener Vorschau-Links.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Logger;
use CMS\Services\AdminMenu\BadgeProvider;
use CMS\Services\Content\ContentWorkflow;
use CMS\Services\ContentEditPermission;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentWorkflowHooks
{
    public static function register(): void
    {
        foreach ([BadgeProvider::CONTENT_PENDING_POSTS => ContentWorkflow::TYPE_POST, BadgeProvider::CONTENT_PENDING_PAGES => ContentWorkflow::TYPE_PAGE] as $key => $type) {
            // Nur Inhalte, die das angemeldete Konto selbst freigeben dürfte – eigene Einreichungen zählen nicht.
            BadgeProvider::register(
                $key,
                static fn (): int => ContentWorkflow::instance()->pendingCount(ContentEditPermission::currentUserId(), null, $type)
            );
        }

        Hooks::addAction('cms_cron_daily', static function (): void {
            try {
                ContentWorkflow::instance()->purgeExpiredPreviewLinks();
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('content.workflow')->warning('Abgelaufene Vorschau-Links konnten nicht entfernt werden.', [
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }
}
