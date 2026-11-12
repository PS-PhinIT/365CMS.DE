<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Newsletter – Entry Point (ehemals Plugin cms-newsletter, seit 4.0.0 im Core)
 * Route: /admin/newsletter
 *
 * Bereiche (?section=…): dashboard, subscribers (Liste, Import, DSGVO-Exporte), subscriber (&id=…),
 * campaigns (&status=…), campaign (&id=… – Bearbeiten, Vorschau, Test, Senden, Planen), templates, template (&id=…),
 * settings (&tab=general|content|delivery).
 * ?preview=<id> liefert die Mail-Vorschau einer Kampagne (für das iframe der Kampagnenseite),
 * ?export=csv[&q=…&status=…&segment=…] liefert die Abonnenten als CSV, ?privacy_export=<datei> eine DSGVO-Auskunft.
 */

use CMS\Auth;

const CMS_ADMIN_NEWSLETTER_CAPABILITY = 'manage_newsletter';

function cms_admin_newsletter_can_access(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability(CMS_ADMIN_NEWSLETTER_CAPABILITY);
}

if (!cms_admin_newsletter_can_access()) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/modules/newsletter/NewsletterModule.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (($_GET['export'] ?? '') === 'csv') {
        (new NewsletterModule())->streamCsv($_GET);
    }
    if (is_string($_GET['preview'] ?? null) && ctype_digit($_GET['preview'])) {
        (new NewsletterModule())->streamPreview((int) $_GET['preview']);
    }
    if (is_string($_GET['privacy_export'] ?? null) && !(new NewsletterModule())->streamExport($_GET['privacy_export'])) {
        $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('newsletter.error.not_found')];
        header('Location: ' . NewsletterModule::url(['section' => 'subscribers']), true, 303);
        exit;
    }
}

/**
 * @return array<string, mixed>
 */
function cms_admin_newsletter_request_context(NewsletterModule $module): array
{
    $section = is_string($_GET['section'] ?? null) && in_array($_GET['section'], NewsletterModule::SECTIONS, true) ? (string) $_GET['section'] : 'dashboard';
    $assets = ['css' => [cms_asset_url('css/admin-newsletter.css')], 'js' => [cms_asset_url('js/admin-newsletter.js')]];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        return ['section' => $section, 'data' => []];
    }
    $id = (int) ($_GET['id'] ?? 0);
    $notFound = static function (string $back): never {
        $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('newsletter.error.not_found')];
        header('Location: ' . NewsletterModule::url(['section' => $back]), true, 303);
        exit;
    };

    $data = match ($section) {
        'subscribers' => $module->getSubscribersData($_GET),
        'subscriber' => $module->getSubscriberData($id) ?? $notFound('subscribers'),
        'campaigns' => $module->getCampaignsData(is_string($_GET['status'] ?? null) ? $_GET['status'] : ''),
        'campaign' => $module->getCampaignData($id) ?? $notFound('campaigns'),
        'templates' => $module->getTemplatesData(),
        'template' => $module->getTemplateData($id) ?? $notFound('templates'),
        'settings' => $module->getSettingsData(is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'general'),
        default => $module->getDashboardData(),
    };

    return ['section' => $section, 'page_assets' => $assets, 'data' => $data];
}

$sectionPageConfig = [
    'section' => 'dashboard',
    'route_path' => '/admin/newsletter',
    'view_file' => __DIR__ . '/views/newsletter/index.php',
    'page_title' => __('newsletter.admin.page_title'),
    'active_page' => 'newsletter',
    'csrf_action' => 'admin_newsletter',
    'module_factory' => static fn (): NewsletterModule => new NewsletterModule(),
    'access_checker' => static fn (): bool => cms_admin_newsletter_can_access(),
    'access_denied_route' => '/',
    'request_context_resolver' => static fn (NewsletterModule $module): array => cms_admin_newsletter_request_context($module),
    'redirect_path_resolver' => static function ($module, string $section, mixed $result = null): string {
        if (is_array($result) && is_string($result['__redirect'] ?? null)) {
            return (string) $result['__redirect'];
        }

        return NewsletterModule::url(['section' => $section !== 'dashboard' ? $section : null]);
    },
    'post_handler' => static function (NewsletterModule $module, string $section, array $post): array {
        $user = Auth::instance()->currentUser();

        return $module->handlePost($post, (int) ($user->id ?? 0), (string) ($user->email ?? ''));
    },
    'invalid_token_message' => __('newsletter.admin.error_token'),
    'unknown_action_message' => __('newsletter.admin.error_unknown_action'),
];

require __DIR__ . '/partials/section-page-shell.php';
