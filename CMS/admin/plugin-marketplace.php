<?php
declare(strict_types=1);

/**
 * Plugin Marketplace – Entry Point
 * Route: /admin/plugin-marketplace
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;

const CMS_ADMIN_PLUGIN_MARKETPLACE_READ_CAPABILITY = 'manage_settings';
const CMS_ADMIN_PLUGIN_MARKETPLACE_WRITE_CAPABILITY = 'manage_settings';
const CMS_ADMIN_PLUGIN_MARKETPLACE_INSTALL_ACTION = 'install';
const CMS_ADMIN_PLUGIN_MARKETPLACE_MAX_SLUG_LENGTH = 120;
const CMS_ADMIN_PLUGIN_MARKETPLACE_ROUTE_PATH = '/admin/plugin-marketplace';

function cms_admin_plugin_marketplace_substring(string $value, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        return $length === null
            ? (string) mb_substr($value, $start, null, 'UTF-8')
            : (string) mb_substr($value, $start, $length, 'UTF-8');
    }

    return $length === null ? substr($value, $start) : substr($value, $start, $length);
}

function cms_admin_plugin_marketplace_length(string $value): int
{
    return function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
}

function cms_admin_plugin_marketplace_slug_was_truncated(array $post, string $normalizedSlug): bool
{
    $rawSlug = trim((string) ($post['slug'] ?? ''));
    if ($rawSlug === '') {
        return false;
    }

    $sanitizedRawSlug = (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($rawSlug));

    return cms_admin_plugin_marketplace_length($sanitizedRawSlug) > cms_admin_plugin_marketplace_length($normalizedSlug);
}

function cms_admin_plugin_marketplace_can_access(): bool
{
    return Auth::instance()->isAdmin()
        && Auth::instance()->hasCapability(CMS_ADMIN_PLUGIN_MARKETPLACE_READ_CAPABILITY);
}

function cms_admin_plugin_marketplace_can_install(): bool
{
    return cms_admin_plugin_marketplace_can_access()
        && Auth::instance()->hasCapability(CMS_ADMIN_PLUGIN_MARKETPLACE_WRITE_CAPABILITY);
}

function cms_admin_plugin_marketplace_normalize_slug(array $post): string
{
    $slug = (string) preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($post['slug'] ?? '')));

    return cms_admin_plugin_marketplace_substring($slug, 0, CMS_ADMIN_PLUGIN_MARKETPLACE_MAX_SLUG_LENGTH);
}

function cms_admin_plugin_marketplace_normalize_action(mixed $action): string
{
    $action = strtolower(trim((string) $action));

    return $action === CMS_ADMIN_PLUGIN_MARKETPLACE_INSTALL_ACTION ? $action : '';
}

/** @return array{action:string,slug:string,error:string,errorCode:string,details:list<string>,context:array<string,mixed>} */
function cms_admin_plugin_marketplace_normalize_payload(array $post): array
{
    $action = cms_admin_plugin_marketplace_normalize_action($post['action'] ?? null);
    $slug = cms_admin_plugin_marketplace_normalize_slug($post);

    $error = '';
    $errorCode = '';
    $details = [];
    $context = [
        'action' => $action,
        'slug' => $slug,
        'max_slug_length' => CMS_ADMIN_PLUGIN_MARKETPLACE_MAX_SLUG_LENGTH,
    ];

    if ($action === '') {
        $error = 'Unbekannte oder nicht erlaubte Aktion.';
        $errorCode = 'plugin_marketplace_invalid_action';
        $details[] = 'Aktion: (leer/ungültig)';
    } elseif ($action === CMS_ADMIN_PLUGIN_MARKETPLACE_INSTALL_ACTION && $slug === '') {
        $error = 'Ungültiger Plugin-Slug.';
        $errorCode = 'plugin_marketplace_invalid_slug';
        $details[] = 'Slug: (leer/ungültig)';
    } elseif ($action === CMS_ADMIN_PLUGIN_MARKETPLACE_INSTALL_ACTION && cms_admin_plugin_marketplace_slug_was_truncated($post, $slug)) {
        $error = 'Der Plugin-Slug überschreitet die erlaubte Länge.';
        $errorCode = 'plugin_marketplace_slug_too_long';
        $details[] = 'Slug: ' . $slug;
        $details[] = 'Max. Länge: ' . CMS_ADMIN_PLUGIN_MARKETPLACE_MAX_SLUG_LENGTH;
    }

    return [
        'action' => $action,
        'slug' => $slug,
        'error' => $error,
        'errorCode' => $errorCode,
        'details' => $details,
        'context' => $context,
    ];
}

function cms_admin_plugin_marketplace_view_data(PluginMarketplaceModule $module): array
{
    return $module->getData();
}

function cms_admin_plugin_marketplace_build_failure_result(string $message, string $errorCode, array $details = [], array $context = []): array
{
    $normalizedContext = array_merge([
        'source' => CMS_ADMIN_PLUGIN_MARKETPLACE_ROUTE_PATH,
        'route' => CMS_ADMIN_PLUGIN_MARKETPLACE_ROUTE_PATH,
    ], $context);

    return [
        'success' => false,
        'error' => $message,
        'details' => array_values(array_filter(array_map(static fn (mixed $detail): string => trim((string) $detail), $details), static fn (string $detail): bool => $detail !== '')),
        'error_details' => [
            'code' => $errorCode,
            'data' => [
                'route' => CMS_ADMIN_PLUGIN_MARKETPLACE_ROUTE_PATH,
            ],
            'context' => $normalizedContext,
        ],
        'report_payload' => [
            'title' => 'Plugin Marketplace · ' . $errorCode,
            'message' => $message,
            'error_code' => $errorCode,
            'error_data' => [
                'route' => CMS_ADMIN_PLUGIN_MARKETPLACE_ROUTE_PATH,
            ],
            'context' => $normalizedContext,
            'source_url' => CMS_ADMIN_PLUGIN_MARKETPLACE_ROUTE_PATH,
        ],
    ];
}

// Release-Prüfung 4.1.0 (FUN-410-58): „Marketplace anzeigen“ aus = Seite gesperrt (bisher nur aus dem Menü entfernt,
// Katalogabruf und Installation blieben per URL erreichbar).
if (!\CMS\Services\AdminMenu\AdminMenuService::getInstance()->isMarketplaceEnabled()) {
    header('Location: /admin/plugins', true, 303);
    exit;
}

$sectionPageConfig = [
    'route_path' => CMS_ADMIN_PLUGIN_MARKETPLACE_ROUTE_PATH,
    'view_file' => __DIR__ . '/views/plugins/marketplace.php',
    'page_title' => 'Plugin Marketplace',
    'active_page' => 'plugin-marketplace',
    'page_assets' => [
        'js' => [
            cms_asset_url('js/admin-plugin-marketplace.js'),
        ],
    ],
    'csrf_action' => 'admin_plugin_mp',
    'module_file' => __DIR__ . '/modules/plugins/PluginMarketplaceModule.php',
    'module_factory' => static fn (): PluginMarketplaceModule => new PluginMarketplaceModule(),
    'access_checker' => static fn (): bool => cms_admin_plugin_marketplace_can_access(),
    'access_denied_route' => '/',
    'unknown_action_message' => 'Unbekannte oder nicht erlaubte Aktion.',
    'data_loader' => static fn (PluginMarketplaceModule $module): array => cms_admin_plugin_marketplace_view_data($module),
    'post_handler' => static function (PluginMarketplaceModule $module, string $section, array $post): array {
        if (!cms_admin_plugin_marketplace_can_install()) {
            return cms_admin_plugin_marketplace_build_failure_result(
                'Keine Berechtigung für Marketplace-Installationen.',
                'plugin_marketplace_permission_denied',
                ['Capability: ' . CMS_ADMIN_PLUGIN_MARKETPLACE_WRITE_CAPABILITY]
            );
        }

        $payload = cms_admin_plugin_marketplace_normalize_payload($post);
        if ($payload['error'] !== '') {
            return cms_admin_plugin_marketplace_build_failure_result(
                $payload['error'],
                (string) ($payload['errorCode'] !== '' ? $payload['errorCode'] : 'plugin_marketplace_invalid_payload'),
                $payload['details'] !== [] ? (array) $payload['details'] : ['Aktion: ' . (string) ($payload['action'] ?? '(leer)'), 'Slug: ' . (string) ($payload['slug'] ?? '(leer)')],
                is_array($payload['context'] ?? null) ? (array) $payload['context'] : ['action' => (string) ($payload['action'] ?? ''), 'slug' => (string) ($payload['slug'] ?? '')]
            );
        }

        if ($payload['action'] === CMS_ADMIN_PLUGIN_MARKETPLACE_INSTALL_ACTION && !$module->hasCatalogPluginSlug($payload['slug'])) {
            return cms_admin_plugin_marketplace_build_failure_result(
                'Plugin-Slug ist im aktuellen Marketplace-Katalog nicht vorhanden. Bitte Ansicht aktualisieren.',
                'plugin_marketplace_catalog_slug_missing',
                ['Aktion: install', 'Slug: ' . $payload['slug']],
                ['action' => 'install', 'slug' => $payload['slug']]
            );
        }

        return match ($payload['action']) {
            CMS_ADMIN_PLUGIN_MARKETPLACE_INSTALL_ACTION => $module->installPlugin($payload['slug']),
            default => ['success' => false, 'error' => 'Unbekannte oder nicht erlaubte Aktion.'],
        };
    },
];

require __DIR__ . '/partials/section-page-shell.php';
