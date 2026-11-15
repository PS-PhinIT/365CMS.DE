<?php
/**
 * Rechnungen (4.1.0, Bereich 5b): Rechnung bei Zahlungseingang und Abo-Verlängerung, Mitgliederrouten
 * (`/member/subscription/invoices`, `GET /member/invoices/{id}` mit Eigentumsprüfung), Menüpunkt im
 * Mitgliederbereich, Sperrvermerk statt Löschung bei DSGVO-Löschungen und Aufbewahrungsregel (nur Anzeige).
 *
 * Vertrag mit Bereich 5a (Zahlungen):
 * - `cms_payment_succeeded(int $orderId, string $provider, array $event)` nach einer bezahlten Erstbestellung,
 * - `cms_subscription_renewed(int $subscriptionRowId, int $userId, int $renewalOrderId, int $periodEndTimestamp)`
 *   nach einer Verlängerung (eigene bezahlte Zeile in `orders`).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Auth;
use CMS\Hooks;
use CMS\Logger;
use CMS\Services\DataRetentionService;
use CMS\Services\Payments\InvoiceService;
use CMS\Services\Retention\RetentionRule;

if (!defined('ABSPATH')) {
    exit;
}

final class InvoiceHooks
{
    public const RETENTION_RULE = 'invoices.archive';
    public const MEMBER_LIST_ROUTE = '/member/subscription/invoices';
    public const MEMBER_DOWNLOAD_ROUTE = '/member/invoices/:id';

    public static function register(): void
    {
        Hooks::addAction('cms_payment_succeeded', [self::class, 'onPaymentSucceeded'], 20);
        Hooks::addAction('cms_subscription_renewed', [self::class, 'onSubscriptionRenewed'], 20);

        Hooks::addAction('register_routes', static function (mixed $router = null): void {
            if (!is_object($router) || !method_exists($router, 'addRoute')) {
                return;
            }
            $router->addRoute('GET', self::MEMBER_LIST_ROUTE, static function (): void {
                require ABSPATH . 'member/invoices.php';
            });
            $router->addRoute('GET', self::MEMBER_DOWNLOAD_ROUTE, static function (string $id = '') use ($router): void {
                self::serveMemberDownload($router, $id);
            });
        }, 20);

        Hooks::addFilter('member_menu_items', [self::class, 'addMemberMenuItem'], 20);

        // Rechnungen bleiben erhalten (Aufbewahrungspflicht); Löschungen setzen nur den Sperrvermerk.
        Hooks::addAction('dsgvo_delete_data', static function (mixed $userId = null, mixed $email = null): void {
            self::guard(static function () use ($userId): void {
                $count = InvoiceService::getInstance()->restrictForUser(is_numeric($userId) ? (int) $userId : 0);
                if ($count > 0) {
                    \CMS\AuditLogger::instance()->log(\CMS\AuditLogger::CAT_USER, 'invoices.privacy.restrict', 'DSGVO-Löschung: Rechnungen gesperrt statt gelöscht (Aufbewahrungspflicht)', 'invoice', null, ['count' => $count, 'user_id' => (int) $userId], 'warning');
                }
            });
        });
        Hooks::addAction('cms_user_deleted', static function (mixed $userId = null): void {
            self::guard(static fn () => InvoiceService::getInstance()->restrictForUser(is_numeric($userId) ? (int) $userId : 0));
        });

        // DSGVO-Auskunft (Art. 15): Rechnungen als JSON-Datei; Selbstauskunft im Mitgliederbereich.
        Hooks::addAction('dsgvo_export_data', static function (mixed $userId = null, mixed $email = null): void {
            self::guard(static fn () => \CMS\Services\Payments\InvoicePrivacy::handleExport($userId, $email));
        }, 10);
        Hooks::addFilter('cms_member_export_data', static function (mixed $export = [], mixed $userId = 0, mixed $email = ''): mixed {
            if (!is_array($export)) {
                return $export;
            }
            try {
                $invoices = InvoiceService::getInstance()->exportForPrivacy((int) $userId, is_string($email) ? $email : '');
                if ($invoices !== []) {
                    $export['invoices'] = \CMS\Services\Payments\InvoicePrivacy::document((int) $userId, (string) $email, $invoices);
                }
            } catch (\Throwable $e) {
                error_log('365CMS Rechnungen (Selbstauskunft): ' . $e->getMessage());
            }

            return $export;
        }, 20);

        Hooks::addAction('cms_init', [self::class, 'registerRetentionRule'], 20);
    }

    public static function onPaymentSucceeded(mixed $orderId = 0, mixed $provider = '', mixed $event = []): void
    {
        self::guard(static function () use ($orderId): void {
            $service = InvoiceService::getInstance();
            if ($service->settings()['auto_create'] !== '1') {
                return;
            }
            $result = $service->createForOrder((int) $orderId, ['source' => 'payment']);
            if (empty($result['success'])) {
                Logger::instance()->withChannel('invoices')->warning('Automatische Rechnung nicht erstellt.', ['order' => (int) $orderId, 'error' => (string) ($result['error'] ?? '')]);
            }
        });
    }

    public static function onSubscriptionRenewed(mixed $subscriptionRowId = 0, mixed $userId = 0, mixed $renewalOrderId = 0, mixed $periodEnd = 0): void
    {
        self::guard(static function () use ($renewalOrderId, $periodEnd): void {
            $service = InvoiceService::getInstance();
            if ($service->settings()['auto_create'] !== '1') {
                return;
            }
            $options = ['source' => 'renewal'];
            if ((int) $periodEnd > 0) {
                $options['period_end'] = (int) $periodEnd;
            }
            $result = $service->createForOrder((int) $renewalOrderId, $options);
            if (empty($result['success'])) {
                Logger::instance()->withChannel('invoices')->warning('Rechnung zur Verlängerung nicht erstellt.', ['order' => (int) $renewalOrderId, 'error' => (string) ($result['error'] ?? '')]);
            }
        });
    }

    /**
     * Unterpunkt „Rechnungen“ unter „Abo & Bestellungen“ (nur wenn der Abo-Bereich sichtbar ist).
     */
    public static function addMemberMenuItem(mixed $items = []): mixed
    {
        if (!is_array($items)) {
            return $items;
        }
        foreach ($items as $item) {
            if (is_array($item) && ($item['slug'] ?? '') === 'subscription') {
                $items[] = [
                    'slug' => 'invoices',
                    'label' => \CMS\Member\MemberI18n::t('member.nav.invoices'),
                    'icon' => '🧾',
                    'url' => self::MEMBER_LIST_ROUTE,
                    'category' => 'account',
                    'priority' => 21,
                    'parent_slug' => 'subscription',
                ];
                break;
            }
        }

        return $items;
    }

    public static function registerRetentionRule(): void
    {
        DataRetentionService::registerRule(new RetentionRule(
            self::RETENTION_RULE,
            'Rechnungen',
            'Aufbewahrung 10 Jahre ab Ende des Ausstellungsjahres (§ 147 AO, § 14b UStG). Keine automatische Löschung; DSGVO-Löschungen setzen einen Sperrvermerk.',
            static fn (): int => 0,
            static fn (): int => 0,
            static fn (): int => 0,
            '/admin/invoices',
        ));
    }

    /**
     * Rechnungs-PDF ausliefern (Mitgliederbereich). Fremde oder unbekannte Rechnungen → 404, ohne die Existenz zu verraten.
     */
    public static function serveMemberDownload(object $router, string $id): void
    {
        $auth = Auth::instance();
        if (!$auth->isLoggedIn()) {
            $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? self::MEMBER_LIST_ROUTE);
            require_once ABSPATH . 'member/includes/class-member-controller.php';
            $router->redirect(\CMS\MemberArea\MemberController::loginRedirectPath($requestUri));

            return;
        }
        $userId = (int) ($auth->getCurrentUser()->id ?? 0);
        $canViewAll = $auth->isAdmin() && $auth->hasCapability('invoices.view');
        $file = ctype_digit($id) ? InvoiceService::getInstance()->downloadFor((int) $id, $userId, $canViewAll) : null;
        if ($file === null) {
            $router->render404();

            return;
        }
        self::sendPdf($file['path'], $file['filename']);
    }

    public static function sendPdf(string $path, string $filename): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', '\\', "\r", "\n"], '', $filename) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        readfile($path);
        exit;
    }

    private static function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            try {
                Logger::instance()->withChannel('invoices')->warning('Rechnungs-Aktion fehlgeschlagen.', ['exception' => $e->getMessage()]);
            } catch (\Throwable) {
            }
        }
    }
}
