<?php
/**
 * Hooks des Funktionsbereichs „System“: Aufbewahrungsregeln des Cores.
 *
 * Die Aufbewahrungsregel `comment_ips` (Kommentar-IP-Adressen kürzen oder entfernen) erscheint in der
 * Übersicht Recht → Löschfristen und läuft im täglichen Lauf von `DataRetentionService` mit. Registriert
 * wird sie erst im Admin (`cms_init_admin`) und vor dem täglichen Lauf (`cms_cron_daily`, Priorität 1 –
 * der Lauf selbst hängt an Priorität 40), damit Frontend- und API-Anfragen den Übersetzungsdienst nicht
 * allein für die Regelbezeichnung laden müssen.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Services\DataRetentionService;
use CMS\Services\Retention\CommentIpRetention;

if (!defined('ABSPATH')) {
    exit;
}

final class SystemFeatureHooks
{
    public static function register(): void
    {
        Hooks::addAction('cms_init_admin', [self::class, 'registerRetentionRules'], 5);
        Hooks::addAction('cms_cron_daily', [self::class, 'registerRetentionRules'], 1);
    }

    public static function registerRetentionRules(): void
    {
        DataRetentionService::registerRule(CommentIpRetention::rule());
    }
}
