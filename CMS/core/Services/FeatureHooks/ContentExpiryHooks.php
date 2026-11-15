<?php
/**
 * Hooks für Ablauf- und Prüfdaten (4.1.0, Bereich 3c): Editor-Felder `expires_date`/`expires_time` und
 * `review_due_date` nach dem Speichern von Beiträgen und Seiten übernehmen (geprüft haben die Module), Checkbox
 * „Als geprüft markieren“, stündlich abgelaufene Inhalte verarbeiten, täglich fällige Wiedervorlagen melden.
 * Ohne die Felder im Formular (Sammelaktionen, Papierkorb, Freigabe) bleiben gespeicherte Werte unverändert.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Logger;
use CMS\Services\Content\ContentExpiryService;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentExpiryHooks
{
    public static function register(): void
    {
        Hooks::addAction('cms_after_post_save', static function (mixed $id = 0, mixed $payload = [], mixed $post = []): void {
            self::persist('post', (int) $id, $post);
        }, 20);
        Hooks::addAction('cms_after_page_save', static function (mixed $id = 0, mixed $payload = [], mixed $post = []): void {
            self::persist('page', (int) $id, $post);
        }, 20);
        Hooks::addAction('cms_cron_hourly', static function (): void {
            self::guard(static fn () => ContentExpiryService::instance()->processExpired());
        });
        Hooks::addAction('cms_cron_daily', static function (): void {
            self::guard(static fn () => ContentExpiryService::instance()->processDueReviews());
        });
    }

    /**
     * @param mixed $post Formulardaten des Editors
     */
    public static function persist(string $type, int $id, mixed $post): void
    {
        if ($id <= 0 || !is_array($post) || !array_key_exists('expires_date', $post)) {
            return;
        }
        $text = static fn (string $key): string => is_scalar($post[$key] ?? null) ? (string) $post[$key] : '';
        $expires = ContentExpiryService::normalizeInput($text('expires_date'), $text('expires_time'), 'Ablaufdatum');
        $review = ContentExpiryService::normalizeInput($text('review_due_date'), '', 'Wiedervorlage');
        if ($expires['error'] !== null || $review['error'] !== null) {
            return;
        }
        self::guard(static function () use ($type, $id, $expires, $review, $text): void {
            $service = ContentExpiryService::instance();
            $service->saveDates($type, $id, $expires['value'], $review['value']);
            if ($text('review_done') === '1') {
                $service->markReviewed($type, $id);
            }
        });
    }

    private static function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('content.expiry')->warning('Ablauf-/Prüfdaten: Aktion fehlgeschlagen.', ['exception' => $e->getMessage()]);
        }
    }
}
