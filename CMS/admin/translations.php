<?php
declare(strict_types=1);

/**
 * Übersetzungen – Status je Inhalt und Sprache (fehlt / aktuell / veraltet)
 * Route: /admin/translations[?type=post|page][&locale=en][&status=…][&q=…][&page=n]
 *
 * Aktionen (POST, CSRF): `create` (DE → EN kopieren über die bestehenden Modul-Methoden), `mark_current`.
 * „Mit KI übersetzen“ öffnet den Editor in der Zielsprache und startet die vorhandene KI-Übersetzung; das Ergebnis
 * erscheint als Vorschau und wird nie automatisch veröffentlicht.
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Services\TranslationStatusService;

const CMS_ADMIN_TRANSLATIONS_CAPABILITY = 'manage_translations';

function cms_admin_translations_can_access(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability(CMS_ADMIN_TRANSLATIONS_CAPABILITY);
}

/** @return list<string> */
function cms_admin_translations_types(): array
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

if (!cms_admin_translations_can_access()) {
    header('Location: /');
    exit;
}

function cms_admin_translations_ai_enabled(): bool
{
    try {
        return \CMS\Services\CoreModuleService::getInstance()->isModuleEnabled('ai_services')
            && (Auth::instance()->hasCapability('manage_ai_services') || Auth::instance()->hasCapability('use_ai_translation')
                || Auth::instance()->hasCapability('edit_all_posts') || Auth::instance()->hasCapability('manage_pages'));
    } catch (\Throwable) {
        return false;
    }
}

$sectionPageConfig = [
    'section' => 'translations',
    'route_path' => '/admin/translations',
    'view_file' => __DIR__ . '/views/content/translations.php',
    'page_title' => __('translations_status.title'),
    'active_page' => 'translations',
    'csrf_action' => 'admin_translations',
    'module_factory' => static fn (): TranslationStatusService => TranslationStatusService::getInstance(),
    'data_loader' => static function (TranslationStatusService $service): array {
        $types = cms_admin_translations_types();
        $scalar = static fn (string $key): string => is_scalar($_GET[$key] ?? null) ? trim((string) $_GET[$key]) : '';
        $filters = [
            'types' => $types,
            'type' => in_array($scalar('type'), $types, true) ? $scalar('type') : '',
            'locale' => in_array($scalar('locale'), $service->locales(), true) ? $scalar('locale') : '',
            'status' => in_array($scalar('status'), TranslationStatusService::STATUSES, true) ? $scalar('status') : '',
            'search' => mb_substr(strip_tags($scalar('q')), 0, 100),
        ];

        return [
            'available' => $service->isAvailable(),
            'filters' => $filters,
            'types' => $types,
            'locales' => $service->locales(),
            'ai' => cms_admin_translations_ai_enabled(),
            'result' => $service->matrix($filters, max(1, (int) $scalar('page'))),
        ];
    },
    'access_checker' => static fn (): bool => cms_admin_translations_can_access(),
    'access_denied_route' => '/',
    'redirect_path_resolver' => static function ($module, string $section, mixed $result = null): string {
        $return = is_array($result) && is_string($result['__redirect'] ?? null) ? (string) $result['__redirect'] : '';

        return str_starts_with($return, '/admin/translations') && !preg_match('/[\r\n\\\\]/', $return) ? $return : '/admin/translations';
    },
    'invalid_token_message' => __('translations_status.error.token'),
    'unknown_action_message' => __('translations_status.error.action'),
    'post_handler' => static function (TranslationStatusService $service, string $section, array $post): array {
        $type = is_scalar($post['type'] ?? null) ? (string) $post['type'] : '';
        $id = (int) ($post['id'] ?? 0);
        $locale = is_scalar($post['locale'] ?? null) ? (string) $post['locale'] : 'en';
        $return = is_scalar($post['return'] ?? null) ? (string) $post['return'] : '/admin/translations';
        $userId = (int) (Auth::instance()->currentUser()->id ?? 0);
        if (!in_array($type, cms_admin_translations_types(), true) || $id <= 0 || !in_array($locale, $service->locales(), true)) {
            return ['success' => false, 'error' => __('translations_status.error.not_found'), '__redirect' => $return];
        }

        $action = is_scalar($post['action'] ?? null) ? (string) $post['action'] : '';
        if ($action === 'mark_current') {
            return $service->markCurrent($type, $id, $locale, $userId)
                ? ['success' => true, 'message' => __('translations_status.marked'), '__redirect' => $return]
                : ['success' => false, 'error' => __('translations_status.error.mark'), '__redirect' => $return];
        }
        if ($action === 'create' && $locale === 'en') {
            if ($type === 'post') {
                require_once __DIR__ . '/modules/posts/PostsModule.php';
                $result = (new PostsModule())->copyGermanToEnglish($id, $userId);
            } else {
                require_once __DIR__ . '/modules/pages/PagesModule.php';
                $result = (new PagesModule())->copyGermanToEnglish($id, $userId);
            }
            $result['__redirect'] = $return;
            if (!empty($result['success'])) {
                $result['message'] = __('translations_status.created');
            }

            return $result;
        }

        return ['success' => false, 'error' => __('translations_status.error.action'), '__redirect' => $return];
    },
];

require __DIR__ . '/partials/section-page-shell.php';
