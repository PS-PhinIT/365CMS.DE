<?php
/**
 * Hooks des Funktionsbereichs „Formulare“ (ehemals Plugin cms-contact).
 *
 * - JSON-Schnittstelle `POST /api/contact/<schlüssel>/submit` (kompatibel zum Plugin),
 * - Inhaltstyp-Provider für Papierkorb und Export/Import (Formulare) bzw. Papierkorb (Eingänge),
 * - Editor.js-Block „Formular“ (Tool-Skript, Sanitizer, Renderer),
 * - Badge „neue Eingänge“ im Admin-Menü,
 * - Aufbewahrungsregel `forms.submissions` (globaler Standard, je Formular überschreibbar),
 * - DSGVO: Auskunft und Löschung über `dsgvo_export_data` / `dsgvo_delete_data`, Aufräumen der Exportdateien,
 * - Mitgliederbereich „Kontaktanfragen“ (eigene Eingänge ansehen und löschen).
 *
 * Die Seiten `/contact`, `/kontakt` und `/contact/<schlüssel>` registriert der `ThemeRouter`.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Logger;
use CMS\Services\AdminMenu\BadgeProvider;
use CMS\Services\ContentTypes\ContentTypeRegistry;
use CMS\Services\DataRetentionService;
use CMS\Services\EditorJs\EditorJsBlockRegistry;
use CMS\Services\Forms\FormBlock;
use CMS\Services\Forms\FormContentProvider;
use CMS\Services\Forms\FormFrontendController;
use CMS\Services\Forms\FormMemberSection;
use CMS\Services\Forms\FormPrivacyService;
use CMS\Services\Forms\FormService;
use CMS\Services\Forms\FormSettings;
use CMS\Services\Forms\FormSubmissionContentProvider;
use CMS\Services\Forms\FormSubmissionService;
use CMS\Services\Retention\RetentionRule;

if (!defined('ABSPATH')) {
    exit;
}

final class FormHooks
{
    public static function register(): void
    {
        Hooks::addAction('register_routes', static function (mixed $router = null): void {
            if (!is_object($router) || !method_exists($router, 'addRoute')) {
                return;
            }
            $router->addRoute('POST', '/api/contact/:slug/submit', static function (string $slug): void {
                FormFrontendController::instance()->handleApi(rawurldecode($slug));
            });
        }, 20);

        Hooks::addAction(ContentTypeRegistry::REGISTER_ACTION, static function (ContentTypeRegistry $registry): void {
            $registry->register(new FormContentProvider());
            $registry->register(new FormSubmissionContentProvider());
        }, 20);

        EditorJsBlockRegistry::register(
            FormBlock::TYPE,
            'Formular',
            FormBlock::SCRIPT,
            [FormBlock::class, 'sanitize'],
            [FormBlock::class, 'render']
        );

        BadgeProvider::register(
            BadgeProvider::FORM_SUBMISSIONS,
            static fn (): int => FormService::getInstance()->isAvailable() ? FormSubmissionService::getInstance()->countNew() : 0
        );

        Hooks::addAction('cms_init', [self::class, 'registerRetentionRule'], 20);

        Hooks::addAction('dsgvo_export_data', static function (mixed $userId = null, mixed $email = null): void {
            self::guard('DSGVO-Auskunft', static fn () => FormPrivacyService::handleExport($userId, $email));
        }, 10);
        Hooks::addAction('dsgvo_delete_data', static function (mixed $userId = null, mixed $email = null): void {
            self::guard('DSGVO-Löschung', static fn () => FormPrivacyService::handleDelete($userId, $email));
        }, 10);

        Hooks::addAction('cms_cron_daily', static function (): void {
            self::guard('Aufräumen der DSGVO-Exporte', static fn () => FormPrivacyService::pruneExports());
        }, 45);

        Hooks::addAction('member_dashboard_init', static function (mixed $registry = null): void {
            if ($registry instanceof \CMS\Member\PluginDashboardRegistry) {
                FormMemberSection::register($registry);
            }
        }, 20);
    }

    /**
     * Aufbewahrung der Formulareingänge: globaler Standard, je Formular überschreibbar. Ist der Standard „nie“,
     * gilt die kürzeste Frist eines Formulars, damit die Regel weiterhin läuft.
     */
    public static function registerRetentionRule(): void
    {
        $translate = static fn (string $key): string => function_exists('__') ? (string) __($key) : $key;
        $submissions = FormSubmissionService::getInstance();
        $available = static fn (): bool => FormService::getInstance()->isAvailable();

        DataRetentionService::registerRule(new RetentionRule(
            FormSubmissionService::RETENTION_RULE,
            $translate('forms.retention.rule_label'),
            $translate('forms.retention.rule_description'),
            static function () use ($available, $submissions): int {
                $days = FormSettings::retentionDays();
                if ($days > 0 || !$available()) {
                    return $days;
                }
                $shortest = 0;
                foreach (FormService::getInstance()->list(['include_trash' => true], 0, 500) as $form) {
                    $own = FormSubmissionService::effectiveRetentionDays($form);
                    if ($own > 0 && ($shortest === 0 || $own < $shortest)) {
                        $shortest = $own;
                    }
                }

                return $shortest;
            },
            static fn (): int => $available() ? $submissions->countDue() : 0,
            static fn (): int => $available() ? $submissions->pruneDue() : 0,
            '/admin/forms?tab=settings',
        ));
    }

    private static function guard(string $task, \Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('forms')->warning('Formulare: ' . $task . ' fehlgeschlagen.', ['exception' => $e->getMessage()]);
        }
    }
}
