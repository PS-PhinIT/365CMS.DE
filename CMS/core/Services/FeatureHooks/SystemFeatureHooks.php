<?php
/**
 * Hooks des Funktionsbereichs „System“: Aufbewahrungsregeln des Cores und Systemhinweise.
 *
 * Die Aufbewahrungsregeln `comment_ips` (Kommentar-IP-Adressen kürzen oder entfernen) sowie `mail_queue` und
 * `mail_log` (erledigte Mail-Jobs und Mail-Protokoll, siehe Retention\MailRetention) erscheinen in der
 * Übersicht Recht → Löschfristen und laufen im täglichen Lauf von `DataRetentionService` mit. Registriert
 * werden sie erst im Admin (`cms_init_admin`) und vor dem täglichen Lauf (`cms_cron_daily`, Priorität 1 –
 * der Lauf selbst hängt an Priorität 40), damit Frontend- und API-Anfragen den Übersetzungsdienst nicht
 * allein für die Regelbezeichnung laden müssen.
 *
 * Die jährliche Prüf-Erinnerung der Erklärung zur Barrierefreiheit (Recht → Legal Sites) hängt als Prüfung an
 * `AdminNoticeService`; sie wird erst ausgewertet, wenn das Admin-Dashboard die Systemhinweise abruft.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Services\AdminNoticeService;
use CMS\Services\DataRetentionService;
use CMS\Services\Legal\AccessibilityStatementService;
use CMS\Services\Retention\CommentIpRetention;
use CMS\Services\Retention\MailRetention;

if (!defined('ABSPATH')) {
    exit;
}

final class SystemFeatureHooks
{
    public static function register(): void
    {
        Hooks::addAction('cms_init_admin', [self::class, 'registerRetentionRules'], 5);
        Hooks::addAction('cms_cron_daily', [self::class, 'registerRetentionRules'], 1);
        AdminNoticeService::registerCheck(
            'Barrierefreiheitserklärung',
            static fn (): ?array => AccessibilityStatementService::getInstance()->reviewNotice()
        );
        // 4.1.0 (1c): Client-Secret der Entra-ID-Anmeldung läuft in 30 Tagen ab (oder ist abgelaufen).
        AdminNoticeService::registerCheck(
            'Entra-ID-Anmeldung',
            static fn (): ?array => \CMS\Auth\SSO\EntraSettings::secretExpiryNotice(\CMS\Auth\SSO\EntraSettings::load())
        );
    }

    public static function registerRetentionRules(): void
    {
        DataRetentionService::registerRule(CommentIpRetention::rule());
        // Release-Prüfung 4.1.0 (PERF, batch:0): erledigte Mail-Queue-Jobs (30 Tage) und Mail-Protokoll (90 Tage).
        foreach (MailRetention::rules() as $rule) {
            DataRetentionService::registerRule($rule);
        }
    }
}
