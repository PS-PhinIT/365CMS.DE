<?php
declare(strict_types=1);

/**
 * Seiten – Entry Point
 *
 * Route: /admin/pages
 * Actions: list (default), edit, save, delete, bulk
 *
 * @package CMSv2\Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Logger;
use CMS\Security;
use CMS\Services\AI\AiSettingsService;
use CMS\Services\CoreModuleService;
use CMS\Services\EditorJsService;

const CMS_ADMIN_PAGES_ALLOWED_ACTIONS = ['save', 'delete', 'bulk', 'switch_locale', 'copy_de_to_en'];
const CMS_ADMIN_PAGES_ALLOWED_VIEWS = ['list', 'edit'];
const CMS_ADMIN_PAGES_ALLOWED_BULK_ACTIONS = ['delete', 'publish', 'draft', 'set_category', 'clear_category'];
const CMS_ADMIN_PAGES_WRITE_CAPABILITY = 'manage_pages';

function cms_admin_pages_can_access(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability(CMS_ADMIN_PAGES_WRITE_CAPABILITY);
}

/**
 * `reason` is only filled when the SEO AI feature is switched on but the active provider is not ready
 * for the current editor locale, so the editor can explain why the AI button is missing.
 *
 * @return array{available:bool,reason:string}
 */
function cms_admin_pages_ai_seo_metadata_state(string $editorLocale): array
{
    $editorLocale = cms_admin_pages_normalize_editor_locale($editorLocale);

    try {
        if (class_exists(CoreModuleService::class) && !CoreModuleService::getInstance()->isModuleEnabled('ai_services')) {
            return ['available' => false, 'reason' => ''];
        }

        $configuration = AiSettingsService::getInstance()->getConfiguration();
        $features = is_array($configuration['features'] ?? null) ? $configuration['features'] : [];
        if (empty($features['ai_services_enabled']) || empty($features['ai_seo_meta_enabled']) || empty($features['ai_editorjs_enabled'])) {
            return ['available' => false, 'reason' => ''];
        }

        $providers = is_array($configuration['providers'] ?? null) ? $configuration['providers'] : [];
        $activeProviderId = trim((string) ($providers['active_provider_id'] ?? ''));
        foreach ((array) ($providers['entries'] ?? []) as $provider) {
            if (!is_array($provider) || (string) ($provider['id'] ?? '') !== $activeProviderId) {
                continue;
            }

            $readiness = AiSettingsService::getInstance()->getProviderReadiness($configuration, $provider, 'seo_metadata', $editorLocale);
            if (!empty($readiness['ready'])) {
                return ['available' => true, 'reason' => ''];
            }

            $readinessWithoutLocale = AiSettingsService::getInstance()->getProviderReadiness($configuration, $provider, 'seo_metadata', '');
            if (!empty($readinessWithoutLocale['ready'])) {
                return [
                    'available' => false,
                    'reason' => sprintf(
                        'Die Sprache „%s“ ist für den aktiven AI-Provider nicht freigegeben. Unter AI-Services → Einstellungen → Provider bei „Erlaubte Sprachen“ „%s“ ergänzen.',
                        strtoupper($editorLocale),
                        $editorLocale
                    ),
                ];
            }

            return ['available' => false, 'reason' => (string) ($readiness['reason'] ?? '')];
        }
    } catch (\Throwable) {
        return ['available' => false, 'reason' => ''];
    }

    return ['available' => false, 'reason' => ''];
}

/** Link to the AI provider settings, only for users who may change them and only if the admin page is enabled. */
function cms_admin_pages_ai_settings_url(): string
{
    try {
        if (!Auth::instance()->hasCapability('manage_settings')) {
            return '';
        }

        if (class_exists(CoreModuleService::class) && !CoreModuleService::getInstance()->isAdminPageEnabled('ai-settings')) {
            return '';
        }
    } catch (\Throwable) {
        return '';
    }

    return '/admin/ai-settings';
}

function cms_admin_pages_normalize_editor_locale(mixed $locale): string
{
    $normalizedLocale = strtolower(trim(cms_admin_pages_scalar_string($locale)));

    return in_array($normalizedLocale, ['de', 'en'], true) ? $normalizedLocale : 'de';
}

function cms_admin_pages_scalar_string(mixed $value, string $fallback = ''): string
{
    return is_scalar($value) || $value === null ? (string) ($value ?? $fallback) : $fallback;
}

/** @param array<string,mixed> $post @return array<string,mixed> */
function cms_admin_pages_sanitize_inline_post(array $post): array
{
    foreach ($post as $key => $value) {
        if ($key === 'page_meta' && is_array($value)) {
            $post[$key] = array_map(static fn(mixed $field): string => is_scalar($field) || $field === null
                ? (string) $field
                : json_encode($field, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $value);
            continue;
        }
        if (!is_scalar($value) && $value !== null) {
            $post[$key] = '';
        }
    }

    return $post;
}

function cms_admin_pages_target_url(?int $id = null, string $editorLocale = 'de', bool $forceEditView = false): string
{
    $editorLocale = cms_admin_pages_normalize_editor_locale($editorLocale);

    if ($id !== null && $id > 0) {
        $target = '/admin/pages?action=edit&id=' . $id;

        if ($editorLocale === 'en') {
            $target .= '&lang=en';
        }

        return $target;
    }

    if ($forceEditView) {
        $target = '/admin/pages?action=edit';

        if ($editorLocale === 'en') {
            $target .= '&lang=en';
        }

        return $target;
    }

    return '/admin/pages';
}

function cms_admin_pages_normalize_action(mixed $action): string
{
    $normalizedAction = trim(cms_admin_pages_scalar_string($action));

    if (str_starts_with($normalizedAction, 'switch_locale:')) {
        $normalizedAction = 'switch_locale';
    }

    return in_array($normalizedAction, CMS_ADMIN_PAGES_ALLOWED_ACTIONS, true) ? $normalizedAction : '';
}

function cms_admin_pages_extract_action_value(array $post): string
{
    return trim(cms_admin_pages_scalar_string($post['_action'] ?? $post['action'] ?? ''));
}

function cms_admin_pages_resolve_switch_target_locale(mixed $actionValue, mixed $fallbackLocale = 'de'): string
{
    $fallbackLocale = cms_admin_pages_normalize_editor_locale($fallbackLocale);
    $normalizedActionValue = trim(cms_admin_pages_scalar_string($actionValue));

    if (preg_match('/^switch_locale:(de|en)$/', $normalizedActionValue, $matches) === 1) {
        return cms_admin_pages_normalize_editor_locale($matches[1] ?? $fallbackLocale);
    }

    return $fallbackLocale;
}

function cms_admin_pages_normalize_view(mixed $view): string
{
    $normalizedView = trim(cms_admin_pages_scalar_string($view));

    return in_array($normalizedView, CMS_ADMIN_PAGES_ALLOWED_VIEWS, true) ? $normalizedView : 'list';
}

function cms_admin_pages_normalize_positive_id(mixed $id): int
{
    $normalizedId = filter_var(cms_admin_pages_scalar_string($id), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return $normalizedId === false ? 0 : (int) $normalizedId;
}

function cms_admin_pages_normalize_bulk_action(mixed $bulkAction): string
{
    $normalizedBulkAction = trim(cms_admin_pages_scalar_string($bulkAction));

    return in_array($normalizedBulkAction, CMS_ADMIN_PAGES_ALLOWED_BULK_ACTIONS, true) ? $normalizedBulkAction : '';
}

/**
 * @return array<int,int>
 */
function cms_admin_pages_normalize_bulk_ids(mixed $ids, mixed $csvIds = ''): array
{
    $candidates = (array) $ids;
    $csvIds = cms_admin_pages_scalar_string($csvIds);
    if ($candidates === [] && trim($csvIds) !== '') {
        $candidates = explode(',', $csvIds);
    }

    $normalizedIds = [];
    foreach ($candidates as $id) {
        $normalizedId = cms_admin_pages_normalize_positive_id($id);
        if ($normalizedId > 0) {
            $normalizedIds[$normalizedId] = $normalizedId;
        }

        if (count($normalizedIds) >= 200) {
            break;
        }
    }

    return array_values($normalizedIds);
}

function cms_admin_pages_build_inline_edit_data(PagesModule $module, array $post): array
{
    // Vorlagenfelder vor der Skalar-Bereinigung sichern: nach einem Validierungsfehler oder
    // Sprachwechsel sollen Auswahl und eingegebene Rohwerte (auch fehlerhaftes JSON) erhalten bleiben.
    $hasTemplateSelection = array_key_exists('page_template', $post);
    $rawTemplateMeta = is_array($post['page_meta'] ?? null) ? $post['page_meta'] : [];
    $post = cms_admin_pages_sanitize_inline_post($post);
    $id = cms_admin_pages_normalize_positive_id($post['id'] ?? 0);
    $editData = $module->getEditData($id > 0 ? $id : null);
    $existingPage = is_object($editData['page'] ?? null) ? (array) $editData['page'] : [];
    $editorLocale = cms_admin_pages_normalize_editor_locale($post['editor_locale'] ?? ($_GET['lang'] ?? 'de'));

    $contentUpdatedAt = trim((string) ($existingPage['content_updated_at'] ?? ''));
    $contentUpdatedDate = trim((string) ($post['content_updated_date'] ?? ''));
    $contentUpdatedTime = trim((string) ($post['content_updated_time'] ?? ''));

    if ($contentUpdatedDate !== '') {
        $contentUpdatedAt = $contentUpdatedDate . ' ' . ($contentUpdatedTime !== '' ? $contentUpdatedTime : '00:00') . ':00';
    }

    $draftPage = array_merge($existingPage, [
        'id' => $id > 0 ? $id : (int) ($existingPage['id'] ?? 0),
        'title' => (string) ($post['title'] ?? ($existingPage['title'] ?? '')),
        'title_en' => (string) ($post['title_en'] ?? ($existingPage['title_en'] ?? '')),
        'slug' => (string) ($post['slug'] ?? ($existingPage['slug'] ?? '')),
        'slug_en' => (string) ($post['slug_en'] ?? ($existingPage['slug_en'] ?? '')),
        'status' => (string) ($post['status'] ?? ($existingPage['status'] ?? 'draft')),
        'content' => $post['content'] ?? ($existingPage['content'] ?? ''),
        'content_en' => $post['content_en'] ?? ($existingPage['content_en'] ?? ''),
        'hide_title' => !empty($post['hide_title']) ? 1 : 0,
        'show_title_toc' => !empty($post['show_title_toc']) ? 1 : 0,
        'category_id' => cms_admin_pages_normalize_positive_id($post['category_id'] ?? ($existingPage['category_id'] ?? 0)),
        'featured_image' => (string) ($post['featured_image'] ?? ($existingPage['featured_image'] ?? '')),
        'meta_title' => (string) ($post['meta_title'] ?? ($existingPage['meta_title'] ?? '')),
        'meta_description' => (string) ($post['meta_description'] ?? ($existingPage['meta_description'] ?? '')),
        'content_updated_at' => $contentUpdatedAt,
        'page_template' => (string) ($post['page_template'] ?? $existingPage['page_template'] ?? 'default'),
        'page_meta_json' => is_array($post['page_meta'] ?? null)
            ? json_encode($post['page_meta'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : ($existingPage['page_meta_json'] ?? null),
    ]);

    if ($editorLocale === 'en') {
        $draftPage['title'] = (string) ($existingPage['title'] ?? '');
        $draftPage['slug'] = (string) ($existingPage['slug'] ?? '');
        $draftPage['content'] = $existingPage['content'] ?? '';
    } else {
        $draftPage['title_en'] = (string) ($existingPage['title_en'] ?? $draftPage['title_en'] ?? '');
        $draftPage['slug_en'] = (string) ($existingPage['slug_en'] ?? $draftPage['slug_en'] ?? '');
        $draftPage['content_en'] = $existingPage['content_en'] ?? ($draftPage['content_en'] ?? '');
    }

    if ($hasTemplateSelection) {
        $templateValue = preg_replace('/[^a-z0-9_-]/i', '', cms_admin_pages_scalar_string($post['page_template'] ?? '')) ?? '';
        $templateMeta = [];
        foreach ($rawTemplateMeta as $metaKey => $metaValue) {
            $metaKey = preg_replace('/[^a-z0-9_-]/i', '', (string) $metaKey) ?? '';
            if ($metaKey === '') {
                continue;
            }
            $templateMeta[$metaKey] = is_scalar($metaValue) ? (string) $metaValue : (is_array($metaValue) ? $metaValue : '');
        }
        $draftPage['page_template'] = $templateValue !== '' ? $templateValue : 'default';
        $draftPage['page_meta_json'] = $templateMeta !== []
            ? (json_encode($templateMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: null)
            : null;
    }

    $editData['page'] = (object) $draftPage;
    $editData['seoMeta'] = array_merge(is_array($editData['seoMeta'] ?? null) ? $editData['seoMeta'] : [], [
        'focus_keyphrase' => (string) ($post['focus_keyphrase'] ?? ''),
        'keywords' => (string) ($post['keywords'] ?? ''),
        'canonical_url' => (string) ($post['canonical_url'] ?? ''),
        'robots_index' => !empty($post['robots_index']),
        'robots_follow' => !empty($post['robots_follow']),
        'og_title' => (string) ($post['og_title'] ?? ''),
        'og_description' => (string) ($post['og_description'] ?? ''),
        'og_image' => (string) ($post['og_image'] ?? ''),
        'twitter_title' => (string) ($post['twitter_title'] ?? ''),
        'twitter_description' => (string) ($post['twitter_description'] ?? ''),
        'twitter_image' => (string) ($post['twitter_image'] ?? ''),
        'twitter_card' => (string) ($post['twitter_card'] ?? 'summary_large_image'),
        'schema_type' => (string) ($post['schema_type'] ?? 'WebPage'),
        'sitemap_priority' => (string) ($post['sitemap_priority'] ?? ''),
        'sitemap_changefreq' => (string) ($post['sitemap_changefreq'] ?? 'weekly'),
        'hreflang_group' => (string) ($post['hreflang_group'] ?? ''),
    ]);

    return $editData;
}

function cms_admin_pages_view_config(PagesModule $module, string $view, ?array $overrideEditData = null, string $editorLocale = 'de'): array
{
    $normalizedView = cms_admin_pages_normalize_view($view);
    $editorLocale = cms_admin_pages_normalize_editor_locale($editorLocale);
    $aiTranslationEnabled = !class_exists(CoreModuleService::class)
        || CoreModuleService::getInstance()->isModuleEnabled('ai_services');
    $aiSeoMetadataState = cms_admin_pages_ai_seo_metadata_state($editorLocale);
    $aiSeoMetadataEnabled = $aiSeoMetadataState['available'];
    $baseTemplateVars = [
        'editorMediaToken' => Security::instance()->generateToken('editorjs_media'),
        'aiTranslationEnabled' => $aiTranslationEnabled,
        'aiTranslationToken' => $aiTranslationEnabled ? Security::instance()->generateToken('admin_ai_editorjs_translation') : '',
        'aiTranslationUrl' => $aiTranslationEnabled ? '/admin/ai-translate-editorjs' : '',
        'aiSeoMetadataEnabled' => $aiSeoMetadataEnabled,
        'aiSeoMetadataUnavailableReason' => $aiSeoMetadataState['reason'],
        'aiSettingsUrl' => cms_admin_pages_ai_settings_url(),
        'aiSeoMetadataToken' => $aiSeoMetadataEnabled ? Security::instance()->generateToken('admin_ai_seo_metadata') : '',
        'aiSeoMetadataUrl' => $aiSeoMetadataEnabled ? '/admin/ai-generate-seo-metadata' : '',
        'editorLocale' => $editorLocale,
        'useEditorJs' => false,
    ];

    if ($normalizedView === 'edit') {
        // Page/Post edit routes use the local EditorJS runtime by default; fallback is handled client-side only after init failure.
        $useEditorJs = true;
        $pageAssets = [];

        $pageAssets = EditorJsService::getInstance()->getPageAssets();

        $pageAssets['css'] = $pageAssets['css'] ?? [];
        $pageAssets['js'] = $pageAssets['js'] ?? [];
        if (!class_exists(CoreModuleService::class) || CoreModuleService::getInstance()->isModuleEnabled('seo')) {
            $pageAssets['js'][] = cms_asset_url('js/admin-seo-editor.js');
        }
        $pageAssets['js'][] = cms_asset_url('js/admin-content-editor.js');

        $id = cms_admin_pages_normalize_positive_id($_GET['id'] ?? 0);
        $editData = is_array($overrideEditData) ? $overrideEditData : $module->getEditData($id);

        if ($id > 0 && !empty($editData['isNew'])) {
            cms_admin_section_shell_flash('admin_alert', [
                'type' => 'danger',
                'message' => 'Die angeforderte Seite existiert nicht mehr. Bitte Liste neu laden.',
            ]);
            cms_admin_section_shell_redirect(cms_admin_pages_target_url());
        }

        return [
            'section' => 'edit',
            'view_file' => __DIR__ . '/views/pages/edit.php',
            'page_title' => ($editData['isNew'] ? 'Neue Seite' : 'Seite bearbeiten') . ($editorLocale === 'en' ? ' · EN' : ''),
            'active_page' => 'pages',
            'page_assets' => $pageAssets,
            'template_vars' => array_replace($baseTemplateVars, [
                'useEditorJs' => $useEditorJs,
                'editData' => $editData,
            ]),
            'data' => $editData,
        ];
    }

    $listData = $module->getListData();

    return [
        'section' => 'list',
        'view_file' => __DIR__ . '/views/pages/list.php',
        'page_title' => 'Seiten',
        'active_page' => 'pages',
        'page_assets' => [
            'css' => [],
            'js' => [],
        ],
        'template_vars' => array_replace($baseTemplateVars, [
            'listData' => $listData,
        ]),
        'data' => $listData,
    ];
}

function cms_admin_pages_resolve_module_class(): string
{
    $globalClass = 'PagesModule';
    $candidates = [
        $globalClass,
        'CMS\\Admin\\Modules\\PagesModule',
        'CMSv2\\Admin\\Modules\\PagesModule',
    ];

    foreach ($candidates as $candidate) {
        if (!class_exists($candidate, false)) {
            continue;
        }

        if ($candidate !== $globalClass && !class_exists($globalClass, false)) {
            class_alias($candidate, $globalClass);
        }

        return $globalClass;
    }

    throw new RuntimeException('PagesModule konnte nach dem Laden der Moduldatei nicht aufgelöst werden.');
}

// ─── Auth-Check ────────────────────────────────────────────────────────────
if (!cms_admin_pages_can_access()) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/modules/pages/PagesModule.php';
$pagesModuleClass = cms_admin_pages_resolve_module_class();

$sectionPageConfig = [
    'route_path' => '/admin/pages',
    'view_file' => __DIR__ . '/views/pages/list.php',
    'page_title' => 'Seiten',
    'active_page' => 'pages',
    'csrf_action' => 'admin_pages',
    'module_file' => __DIR__ . '/modules/pages/PagesModule.php',
    'module_factory' => static fn (): PagesModule => new $pagesModuleClass(),
    'access_checker' => static fn (): bool => cms_admin_pages_can_access(),
    'request_context_resolver' => static function (PagesModule $module): array {
        $view = cms_admin_pages_normalize_view($_GET['action'] ?? 'list');
        $editorLocale = cms_admin_pages_normalize_editor_locale($_GET['lang'] ?? 'de');

        return cms_admin_pages_view_config($module, $view, null, $editorLocale);
    },
    'redirect_path_resolver' => static function (PagesModule $module, string $section, mixed $result): string {
        if (is_array($result) && isset($result['redirect_path']) && is_string($result['redirect_path'])) {
            return $result['redirect_path'];
        }

        $editorLocale = cms_admin_pages_normalize_editor_locale(
            is_array($result) && isset($result['editor_locale'])
                ? $result['editor_locale']
                : ($_POST['editor_locale'] ?? ($_GET['lang'] ?? 'de'))
        );

        if ($section === 'edit') {
            return cms_admin_pages_target_url(cms_admin_pages_normalize_positive_id($_GET['id'] ?? 0), $editorLocale, true);
        }

        return cms_admin_pages_target_url();
    },
    'post_handler' => static function (PagesModule $module, string $section, array $post): array {
        $rawPostAction = cms_admin_pages_extract_action_value($post);
        $postAction = cms_admin_pages_normalize_action($rawPostAction);
        $editorLocale = cms_admin_pages_normalize_editor_locale($post['editor_locale'] ?? ($_GET['lang'] ?? 'de'));

        if ($postAction === '') {
            return ['success' => false, 'error' => 'Unbekannte Seiten-Aktion.'];
        }

        switch ($postAction) {
            case 'switch_locale':
                $targetLocale = cms_admin_pages_resolve_switch_target_locale($rawPostAction, $editorLocale === 'en' ? 'de' : 'en');

                return [
                    'success' => true,
                    'message' => '',
                    'render_inline' => true,
                    'editor_locale' => $targetLocale,
                    'runtime_context' => cms_admin_pages_view_config(
                        $module,
                        'edit',
                        cms_admin_pages_build_inline_edit_data($module, $post),
                        $targetLocale
                    ),
                ];

            case 'save':
                $userId = Auth::instance()->getCurrentUser()->id ?? 0;
                try {
                    $result = $module->save($post, (int) $userId);
                } catch (\Throwable $e) {
                    // Unerwartete Fehler nicht als HTTP 500 enden lassen: Eingaben bleiben im Editor erhalten.
                    Logger::instance()->withChannel('admin.pages')->error('Seite konnte nicht gespeichert werden.', [
                        'page_id' => cms_admin_pages_normalize_positive_id($post['id'] ?? 0),
                        'exception_class' => get_debug_type($e),
                        'exception' => $e->getMessage(),
                    ]);
                    $result = ['success' => false, 'error' => 'Seite konnte nicht gespeichert werden. Bitte Logs prüfen.'];
                }
                if (!empty($result['success'])) {
                    $result['editor_locale'] = $editorLocale;
                    $result['redirect_path'] = cms_admin_pages_target_url(cms_admin_pages_normalize_positive_id($result['id'] ?? 0), $editorLocale);
                    return $result;
                }

                return [
                    'success' => false,
                    'error' => (string) ($result['error'] ?? 'Seite konnte nicht gespeichert werden.'),
                    'details' => is_array($result['details'] ?? null) ? $result['details'] : [],
                    'render_inline' => true,
                    'editor_locale' => $editorLocale,
                    'runtime_context' => cms_admin_pages_view_config($module, 'edit', cms_admin_pages_build_inline_edit_data($module, $post), $editorLocale),
                ];

            case 'copy_de_to_en':
                $id = cms_admin_pages_normalize_positive_id($post['id'] ?? ($_GET['id'] ?? 0));
                if ($id < 1) {
                    return ['success' => false, 'error' => 'Bitte die Seite zuerst speichern, bevor Inhalte nach EN kopiert werden.'];
                }

                $userId = Auth::instance()->getCurrentUser()->id ?? 0;
                $result = $module->copyGermanToEnglish($id, (int) $userId);
                if (!empty($result['success'])) {
                    $result['editor_locale'] = 'en';
                    $result['redirect_path'] = cms_admin_pages_target_url($id, 'en');
                }

                return $result;

            case 'delete':
                $id = cms_admin_pages_normalize_positive_id($post['id'] ?? 0);
                if ($id < 1) {
                    return ['success' => false, 'error' => 'Ungültige Seiten-ID.'];
                }

                $result = $module->delete($id);
                if (!empty($result['success'])) {
                    $result['redirect_path'] = cms_admin_pages_target_url();
                }

                return $result;

            case 'bulk':
                $bulkAction = cms_admin_pages_normalize_bulk_action($post['bulk_action'] ?? '');
                $bulkIds = cms_admin_pages_normalize_bulk_ids($post['ids'] ?? [], $post['bulk_ids'] ?? '');
                if ($bulkAction === '') {
                    return ['success' => false, 'error' => 'Unbekannte Bulk-Aktion für Seiten.'];
                }

                if ($bulkIds === []) {
                    return ['success' => false, 'error' => 'Bitte mindestens eine gültige Seite auswählen.'];
                }

                $result = $module->bulkAction($bulkAction, $bulkIds, $post);
                if (!empty($result['success'])) {
                    $result['redirect_path'] = cms_admin_pages_target_url();
                }

                return $result;

            default:
                return ['success' => false, 'error' => 'Unbekannte Seiten-Aktion.'];
        }
    },
];

require __DIR__ . '/partials/section-page-shell.php';
