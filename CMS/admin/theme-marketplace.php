<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Theme Marketplace – Entry Point
 * Route: /admin/theme-marketplace
 */

use CMS\Auth;

const CMS_ADMIN_THEME_MARKETPLACE_READ_CAPABILITY = 'manage_settings';
const CMS_ADMIN_THEME_MARKETPLACE_WRITE_CAPABILITY = 'manage_settings';
const CMS_ADMIN_THEME_MARKETPLACE_INSTALL_ACTION = 'install';
const CMS_ADMIN_THEME_MARKETPLACE_MAX_SLUG_LENGTH = 120;
const CMS_ADMIN_THEME_MARKETPLACE_ROUTE_PATH = '/admin/theme-marketplace';

function cms_admin_theme_marketplace_substring(string $value, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        return $length === null
            ? (string) mb_substr($value, $start, null, 'UTF-8')
            : (string) mb_substr($value, $start, $length, 'UTF-8');
    }

    return $length === null ? substr($value, $start) : substr($value, $start, $length);
}

function cms_admin_theme_marketplace_length(string $value): int
{
    return function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
}

function cms_admin_theme_marketplace_slug_was_truncated(array $post, string $normalizedSlug): bool
{
    $rawSlug = trim((string) ($post['theme'] ?? ''));
    if ($rawSlug === '') {
        return false;
    }

    $sanitizedRawSlug = (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($rawSlug));

    return cms_admin_theme_marketplace_length($sanitizedRawSlug) > cms_admin_theme_marketplace_length($normalizedSlug);
}

function cms_admin_theme_marketplace_can_access(): bool
{
    return Auth::instance()->isAdmin()
        && Auth::instance()->hasCapability(CMS_ADMIN_THEME_MARKETPLACE_READ_CAPABILITY);
}

function cms_admin_theme_marketplace_can_install(): bool
{
    return cms_admin_theme_marketplace_can_access()
        && Auth::instance()->hasCapability(CMS_ADMIN_THEME_MARKETPLACE_WRITE_CAPABILITY);
}

function cms_admin_theme_marketplace_normalize_slug(array $post): string
{
    $slug = (string) preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($post['theme'] ?? '')));

    return cms_admin_theme_marketplace_substring($slug, 0, CMS_ADMIN_THEME_MARKETPLACE_MAX_SLUG_LENGTH);
}

function cms_admin_theme_marketplace_normalize_action(mixed $action): string
{
    $action = strtolower(trim((string) $action));

    return $action === CMS_ADMIN_THEME_MARKETPLACE_INSTALL_ACTION ? $action : '';
}

/** @return array{action:string,theme:string,error:string,errorCode:string,details:list<string>,context:array<string,mixed>} */
function cms_admin_theme_marketplace_normalize_payload(array $post): array
{
    $action = cms_admin_theme_marketplace_normalize_action($post['action'] ?? null);
    $theme = cms_admin_theme_marketplace_normalize_slug($post);

    $error = '';
    $errorCode = '';
    $details = [];
    $context = [
        'action' => $action,
        'theme' => $theme,
        'max_slug_length' => CMS_ADMIN_THEME_MARKETPLACE_MAX_SLUG_LENGTH,
    ];

    if ($action === '') {
        $error = 'Unbekannte oder nicht erlaubte Aktion.';
        $errorCode = 'theme_marketplace_invalid_action';
        $details[] = 'Aktion: (leer/ungültig)';
    } elseif ($action === CMS_ADMIN_THEME_MARKETPLACE_INSTALL_ACTION && $theme === '') {
        $error = 'Ungültiger Theme-Slug.';
        $errorCode = 'theme_marketplace_invalid_slug';
        $details[] = 'Theme-Slug: (leer/ungültig)';
    } elseif ($action === CMS_ADMIN_THEME_MARKETPLACE_INSTALL_ACTION && cms_admin_theme_marketplace_slug_was_truncated($post, $theme)) {
        $error = 'Der Theme-Slug überschreitet die erlaubte Länge.';
        $errorCode = 'theme_marketplace_slug_too_long';
        $details[] = 'Theme-Slug: ' . $theme;
        $details[] = 'Max. Länge: ' . CMS_ADMIN_THEME_MARKETPLACE_MAX_SLUG_LENGTH;
    }

    return [
        'action' => $action,
        'theme' => $theme,
        'error' => $error,
        'errorCode' => $errorCode,
        'details' => $details,
        'context' => $context,
    ];
}

function cms_admin_theme_marketplace_build_failure_result(string $message, string $errorCode, array $details = [], array $context = []): array
{
    $normalizedContext = array_merge([
        'source' => CMS_ADMIN_THEME_MARKETPLACE_ROUTE_PATH,
        'route' => CMS_ADMIN_THEME_MARKETPLACE_ROUTE_PATH,
    ], $context);

    return [
        'success' => false,
        'error' => $message,
        'details' => array_values(array_filter(array_map(static fn (mixed $detail): string => trim((string) $detail), $details), static fn (string $detail): bool => $detail !== '')),
        'error_details' => [
            'code' => $errorCode,
            'data' => [
                'route' => CMS_ADMIN_THEME_MARKETPLACE_ROUTE_PATH,
            ],
            'context' => $normalizedContext,
        ],
        'report_payload' => [
            'title' => 'Theme Marketplace · ' . $errorCode,
            'message' => $message,
            'error_code' => $errorCode,
            'error_data' => [
                'route' => CMS_ADMIN_THEME_MARKETPLACE_ROUTE_PATH,
            ],
            'context' => $normalizedContext,
            'source_url' => CMS_ADMIN_THEME_MARKETPLACE_ROUTE_PATH,
        ],
    ];
}

function cms_admin_theme_marketplace_view_data(ThemeMarketplaceModule $module): array
{
    return $module->getData();
}

// Release-Prüfung 4.1.0 (FUN-410-58): „Marketplace anzeigen“ aus = Seite gesperrt (bisher nur aus dem Menü entfernt,
// Katalogabruf und Installation blieben per URL erreichbar).
if (!\CMS\Services\AdminMenu\AdminMenuService::getInstance()->isMarketplaceEnabled()) {
    header('Location: /admin/themes', true, 303);
    exit;
}

$sectionPageConfig = [
    'route_path' => CMS_ADMIN_THEME_MARKETPLACE_ROUTE_PATH,
    'view_file' => __DIR__ . '/views/themes/marketplace.php',
    'page_title' => 'Theme Marketplace',
    'active_page' => 'theme-marketplace',
    'page_assets' => [
        'js' => [
            cms_asset_url('js/admin-theme-marketplace.js'),
        ],
    ],
    'csrf_action' => 'admin_theme_marketplace',
    'module_file' => __DIR__ . '/modules/themes/ThemeMarketplaceModule.php',
    'module_factory' => static fn (): ThemeMarketplaceModule => new ThemeMarketplaceModule(),
    'access_checker' => static fn (): bool => cms_admin_theme_marketplace_can_access(),
    'access_denied_route' => '/',
    'unknown_action_message' => 'Unbekannte oder nicht erlaubte Aktion.',
    'data_loader' => static fn (ThemeMarketplaceModule $module): array => cms_admin_theme_marketplace_view_data($module),
    'post_handler' => static function (ThemeMarketplaceModule $module, string $section, array $post): array {
        if (!cms_admin_theme_marketplace_can_install()) {
            return cms_admin_theme_marketplace_build_failure_result(
                'Keine Berechtigung für Marketplace-Installationen.',
                'theme_marketplace_permission_denied',
                ['Capability: ' . CMS_ADMIN_THEME_MARKETPLACE_WRITE_CAPABILITY]
            );
        }

        $payload = cms_admin_theme_marketplace_normalize_payload($post);
        if ($payload['error'] !== '') {
            return cms_admin_theme_marketplace_build_failure_result(
                $payload['error'],
                (string) ($payload['errorCode'] !== '' ? $payload['errorCode'] : 'theme_marketplace_invalid_payload'),
                $payload['details'] !== [] ? (array) $payload['details'] : ['Aktion: ' . (string) ($payload['action'] ?? '(leer)'), 'Theme-Slug: ' . (string) ($payload['theme'] ?? '(leer)')],
                is_array($payload['context'] ?? null) ? (array) $payload['context'] : ['action' => (string) ($payload['action'] ?? ''), 'theme' => (string) ($payload['theme'] ?? '')]
            );
        }

        if ($payload['action'] === CMS_ADMIN_THEME_MARKETPLACE_INSTALL_ACTION && !$module->hasCatalogThemeSlug($payload['theme'])) {
            return cms_admin_theme_marketplace_build_failure_result(
                'Theme-Slug ist im aktuellen Marketplace-Katalog nicht vorhanden. Bitte Ansicht aktualisieren.',
                'theme_marketplace_catalog_slug_missing',
                ['Aktion: install', 'Theme-Slug: ' . $payload['theme']],
                ['action' => 'install', 'theme' => $payload['theme']]
            );
        }

        return match ($payload['action']) {
            CMS_ADMIN_THEME_MARKETPLACE_INSTALL_ACTION => $module->installTheme($payload['theme']),
            default => cms_admin_theme_marketplace_build_failure_result(
                'Unbekannte oder nicht erlaubte Aktion.',
                'theme_marketplace_invalid_action_dispatch'
            ),
        };
    },
];

require __DIR__ . '/partials/section-page-shell.php';
