<?php
/**
 * Hooks der Inhaltsübersichten „Letzte Änderungen“ und „Übersetzungen“.
 *
 * - Übersetzungsstatus: Abgleich nach dem Speichern (geänderte Übersetzung = aktuell) und nach „DE → EN kopieren“
 *   (`TranslationStatusService::register()`).
 * - „Letzte Änderungen“ liest nur vorhandene Revisionen und braucht keine eigenen Hooks.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Services\TranslationStatusService;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentOverviewHooks
{
    public static function register(): void
    {
        TranslationStatusService::register();
    }
}
