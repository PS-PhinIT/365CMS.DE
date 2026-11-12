<?php
declare(strict_types=1);

/**
 * Letzte Änderungen – Revisionen und Anlage aller Beiträge und Seiten
 * Route: /admin/content-changes[?type=post|page][&user=<id>][&change=…][&from=Y-m-d][&to=Y-m-d][&page=n]
 *
 * Beiträge mit `edit_all_posts`, Seiten mit `manage_pages`. Der Link einer Beitragsrevision öffnet den Editor mit dem
 * Vergleich dieser Revision (`?revision=<id>`).
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Services\ContentChangesService;

function cms_admin_content_changes_types(): array
{
    $types = [];
    if (Auth::instance()->hasCapability('edit_all_posts')) {
        $types[] = 'post';
    }
    if (Auth::instance()->hasCapability('manage_pages')) {
        $types[] = 'page';
    }

    return $types;
}

function cms_admin_content_changes_can_access(): bool
{
    return Auth::instance()->isAdmin()
        && (Auth::instance()->hasCapability('edit_all_posts') || Auth::instance()->hasCapability('manage_pages'));
}

if (!cms_admin_content_changes_can_access()) {
    header('Location: /');
    exit;
}

$sectionPageConfig = [
    'section' => 'content-changes',
    'route_path' => '/admin/content-changes',
    'view_file' => __DIR__ . '/views/content/changes.php',
    'page_title' => __('content_changes.title'),
    'active_page' => 'content-changes',
    'csrf_action' => 'admin_content_changes',
    'module_factory' => static fn (): ContentChangesService => ContentChangesService::getInstance(),
    'data_loader' => static function (ContentChangesService $service): array {
        $types = cms_admin_content_changes_types();
        $scalar = static fn (string $key): string => is_scalar($_GET[$key] ?? null) ? trim((string) $_GET[$key]) : '';
        $filters = [
            'types' => $types,
            'type' => in_array($scalar('type'), $types, true) ? $scalar('type') : '',
            'user_id' => max(0, (int) $scalar('user')),
            'change' => in_array($scalar('change'), ContentChangesService::CHANGES, true) ? $scalar('change') : '',
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $scalar('from')) === 1 ? $scalar('from') : '',
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $scalar('to')) === 1 ? $scalar('to') : '',
        ];
        return [
            'filters' => $filters,
            'types' => $types,
            'users' => $service->users($types),
            'result' => $service->list($filters, max(1, (int) $scalar('page'))),
        ];
    },
    'access_checker' => static fn (): bool => cms_admin_content_changes_can_access(),
    'access_denied_route' => '/',
];

require __DIR__ . '/partials/section-page-shell.php';
