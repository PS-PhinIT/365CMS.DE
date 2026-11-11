<?php
/**
 * Hooks des Funktionsbereichs „Redaktionskalender“.
 *
 * Speichert das Editor-Feld „Geplantes Datum“ (`planned_date`/`planned_time` → `planned_at`) nach dem Speichern
 * von Beiträgen und Seiten. Die Eingabe wurde im jeweiligen Modul bereits validiert; ohne die Felder im
 * Formular (Wiederherstellen, Papierkorb, Massenaktionen) bleibt der gespeicherte Wert unverändert.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Logger;
use CMS\Services\EditorialCalendarService;

if (!defined('ABSPATH')) {
    exit;
}

final class EditorialCalendarHooks
{
    public static function register(): void
    {
        Hooks::addAction('cms_after_post_save', static function (mixed $id = 0, mixed $savePayload = [], mixed $post = []): void {
            self::persistPlannedAt('post', $id, $post);
        }, 20);

        Hooks::addAction('cms_after_page_save', static function (mixed $id = 0, mixed $savePayload = [], mixed $post = []): void {
            self::persistPlannedAt('page', $id, $post);
        }, 20);
    }

    private static function persistPlannedAt(string $type, mixed $id, mixed $post): void
    {
        $id = (int) $id;
        if ($id <= 0 || !is_array($post) || !array_key_exists('planned_date', $post)) {
            return;
        }

        $date = is_scalar($post['planned_date']) ? (string) $post['planned_date'] : '';
        $time = is_scalar($post['planned_time'] ?? null) ? (string) $post['planned_time'] : '';
        $input = EditorialCalendarService::normalizePlannedAtInput($date, $time);
        if ($input['error'] !== null) {
            Logger::instance()->withChannel('content.calendar')->warning('Ungültiges geplantes Datum wurde nicht gespeichert.', [
                'type' => $type,
                'id' => $id,
            ]);

            return;
        }

        EditorialCalendarService::getInstance()->savePlannedAt($type, $id, $input['value']);
    }
}
