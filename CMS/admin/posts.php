<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Posts – Entry Point
 * Route: /admin/posts
 */

use CMS\Auth;
use CMS\Logger;
use CMS\Security;
use CMS\Services\AI\AiSettingsService;
use CMS\Services\CoreModuleService;
use CMS\Services\EditorJsService;

const CMS_ADMIN_POSTS_ALLOWED_ACTIONS = ['save', 'delete', 'bulk', 'save_category', 'delete_category', 'switch_locale', 'copy_de_to_en'];
const CMS_ADMIN_POSTS_ALLOWED_VIEWS = ['list', 'edit'];
const CMS_ADMIN_POSTS_ALLOWED_BULK_ACTIONS = [
    'delete',
    'publish',
    'draft',
    'set_category',
    'clear_category',
    'set_author_display_name',
    'clear_author_display_name',
];
const CMS_ADMIN_POSTS_WRITE_CAPABILITY = 'edit_all_posts';

function cms_admin_posts_can_access(): bool
{
    return Auth::instance()->isAdmin()
        && (
            Auth::instance()->hasCapability(CMS_ADMIN_POSTS_WRITE_CAPABILITY)
            || Auth::instance()->hasCapability('edit_own_posts')
            || Auth::instance()->hasCapability('posts.view')
        );
}

/**
 * `reason` is only filled when the SEO AI feature is switched on but the active provider is not ready
 * for the current editor locale, so the editor can explain why the AI button is missing.
 *
 * @return array{available:bool,reason:string}
 */
function cms_admin_posts_ai_seo_metadata_state(string $editorLocale): array
{
    $editorLocale = cms_admin_posts_normalize_editor_locale($editorLocale);

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
function cms_admin_posts_ai_settings_url(): string
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

function cms_admin_posts_normalize_editor_locale(mixed $locale): string
{
    $normalizedLocale = strtolower(trim(cms_admin_posts_scalar_string($locale)));

    return in_array($normalizedLocale, ['de', 'en'], true) ? $normalizedLocale : 'de';
}

function cms_admin_posts_scalar_string(mixed $value, string $fallback = ''): string
{
    return is_scalar($value) || $value === null ? (string) ($value ?? $fallback) : $fallback;
}

/** @param array<string,mixed> $post @return array<string,mixed> */
function cms_admin_posts_sanitize_inline_post(array $post): array
{
    foreach ($post as $key => $value) {
        if (in_array((string) $key, ['additional_category_ids', 'post_meta'], true)) {
            if (is_array($value)) {
                foreach ($value as $nestedKey => $nestedValue) {
                    if (!is_scalar($nestedValue) && $nestedValue !== null) {
                        $post[$key][$nestedKey] = '';
                    }
                }
            }
            continue;
        }

        if (!is_scalar($value) && $value !== null) {
            $post[$key] = '';
        }
    }

    return $post;
}

function cms_admin_posts_target_url(?int $id = null, string $editorLocale = 'de', bool $forceEditView = false): string
{
    $editorLocale = cms_admin_posts_normalize_editor_locale($editorLocale);

    if ($id !== null && $id > 0) {
        $target = '/admin/posts?action=edit&id=' . $id;

        if ($editorLocale === 'en') {
            $target .= '&lang=en';
        }

        return $target;
    }

    if ($forceEditView) {
        $target = '/admin/posts?action=edit';

        if ($editorLocale === 'en') {
            $target .= '&lang=en';
        }

        return $target;
    }

    return '/admin/posts';
}

function cms_admin_posts_flash(string $type, string $message): void
{
    $_SESSION['admin_alert'] = [
        'type' => $type === 'success' ? 'success' : 'danger',
        'message' => trim($message),
    ];
}

function cms_admin_posts_redirect(?int $id = null, string $editorLocale = 'de', bool $forceEditView = false): never
{
    header('Location: ' . cms_admin_posts_target_url($id, $editorLocale, $forceEditView));
    exit;
}

function cms_admin_posts_normalize_action(mixed $action): string
{
    $normalizedAction = trim(cms_admin_posts_scalar_string($action));

    if (str_starts_with($normalizedAction, 'switch_locale:')) {
        $normalizedAction = 'switch_locale';
    }

    return in_array($normalizedAction, CMS_ADMIN_POSTS_ALLOWED_ACTIONS, true) ? $normalizedAction : '';
}

function cms_admin_posts_extract_action_value(array $post): string
{
    return trim(cms_admin_posts_scalar_string($post['_action'] ?? $post['action'] ?? ''));
}

function cms_admin_posts_resolve_switch_target_locale(mixed $actionValue, mixed $fallbackLocale = 'de'): string
{
    $fallbackLocale = cms_admin_posts_normalize_editor_locale($fallbackLocale);
    $normalizedActionValue = trim(cms_admin_posts_scalar_string($actionValue));

    if (preg_match('/^switch_locale:(de|en)$/', $normalizedActionValue, $matches) === 1) {
        return cms_admin_posts_normalize_editor_locale($matches[1] ?? $fallbackLocale);
    }

    return $fallbackLocale;
}

function cms_admin_posts_normalize_view(mixed $view): string
{
    $normalizedView = trim(cms_admin_posts_scalar_string($view));

    return in_array($normalizedView, CMS_ADMIN_POSTS_ALLOWED_VIEWS, true) ? $normalizedView : 'list';
}

function cms_admin_posts_normalize_positive_id(mixed $id): int
{
    $normalizedId = filter_var(cms_admin_posts_scalar_string($id), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return $normalizedId === false ? 0 : (int) $normalizedId;
}

function cms_admin_posts_normalize_bulk_action(mixed $bulkAction): string
{
    $normalizedBulkAction = trim(cms_admin_posts_scalar_string($bulkAction));

    return in_array($normalizedBulkAction, CMS_ADMIN_POSTS_ALLOWED_BULK_ACTIONS, true) ? $normalizedBulkAction : '';
}

/**
 * @return array<int,int>
 */
function cms_admin_posts_normalize_bulk_ids(mixed $ids): array
{
    $normalizedIds = [];

    foreach ((array) $ids as $id) {
        $normalizedId = cms_admin_posts_normalize_positive_id($id);
        if ($normalizedId > 0) {
            $normalizedIds[$normalizedId] = $normalizedId;
        }

        if (count($normalizedIds) >= 200) {
            break;
        }
    }

    return array_values($normalizedIds);
}

function cms_admin_posts_can_run_action(string $action): bool
{
    return $action !== ''
        && (
            Auth::instance()->hasCapability(CMS_ADMIN_POSTS_WRITE_CAPABILITY)
            || Auth::instance()->hasCapability('edit_own_posts')
            || Auth::instance()->hasCapability('posts.view')
        );
}

/**
 * @return list<string>
 */
function cms_admin_posts_module_contract_methods(): array
{
    return [
        'getListData',
        'getEditData',
        'save',
        'copyGermanToEnglish',
        'delete',
        'bulkAction',
        'saveCategory',
        'deleteCategory',
    ];
}

function cms_admin_posts_module_matches_contract(mixed $module): bool
{
    if (!is_object($module)) {
        return false;
    }

    foreach (cms_admin_posts_module_contract_methods() as $method) {
        if (!method_exists($module, $method)) {
            return false;
        }
    }

    return true;
}

function cms_admin_posts_reflection_matches_contract(\ReflectionClass $reflection): bool
{
    if (!$reflection->isInstantiable()) {
        return false;
    }

    foreach (cms_admin_posts_module_contract_methods() as $method) {
        if (!$reflection->hasMethod($method) || !$reflection->getMethod($method)->isPublic()) {
            return false;
        }
    }

    return true;
}

function cms_admin_posts_build_inline_edit_data(object $module, array $post): array
{
    $post = cms_admin_posts_sanitize_inline_post($post);
    $id = cms_admin_posts_normalize_positive_id($post['id'] ?? 0);
    $editData = $module->getEditData($id > 0 ? $id : null);
    $existingPost = is_array($editData['post'] ?? null) ? $editData['post'] : [];
    $editorLocale = cms_admin_posts_normalize_editor_locale($post['editor_locale'] ?? ($_GET['lang'] ?? 'de'));
    $publishedAt = trim((string) ($existingPost['published_at'] ?? ''));
    $publishDate = trim((string) ($post['publish_date'] ?? ''));
    $publishTime = trim((string) ($post['publish_time'] ?? ''));

    if ($publishDate !== '') {
        $publishedAt = $publishDate . ' ' . ($publishTime !== '' ? $publishTime : '00:00') . ':00';
    }

    $contentUpdatedAt = trim((string) ($existingPost['content_updated_at'] ?? ''));
    $contentUpdatedDate = trim((string) ($post['content_updated_date'] ?? ''));
    $contentUpdatedTime = trim((string) ($post['content_updated_time'] ?? ''));

    if ($contentUpdatedDate !== '') {
        $contentUpdatedAt = $contentUpdatedDate . ' ' . ($contentUpdatedTime !== '' ? $contentUpdatedTime : '00:00') . ':00';
    }

    $draftPost = array_merge($existingPost, [
        'id' => $id > 0 ? $id : (int) ($existingPost['id'] ?? 0),
        'title' => (string) ($post['title'] ?? ($existingPost['title'] ?? '')),
        'title_en' => (string) ($post['title_en'] ?? ($existingPost['title_en'] ?? '')),
        'slug' => (string) ($post['slug'] ?? ($existingPost['slug'] ?? '')),
        'slug_en' => (string) ($post['slug_en'] ?? ($existingPost['slug_en'] ?? '')),
        'content' => $post['content'] ?? ($existingPost['content'] ?? ''),
        'content_en' => $post['content_en'] ?? ($existingPost['content_en'] ?? ''),
        'excerpt' => (string) ($post['excerpt'] ?? ($existingPost['excerpt'] ?? '')),
        'excerpt_en' => (string) ($post['excerpt_en'] ?? ($existingPost['excerpt_en'] ?? '')),
        'status' => (string) ($post['status'] ?? ($existingPost['status'] ?? 'draft')),
        'category_id' => cms_admin_posts_normalize_positive_id($post['category_id'] ?? ($existingPost['category_id'] ?? 0)),
        'featured_image' => (string) ($post['featured_image'] ?? ($existingPost['featured_image'] ?? '')),
        'meta_title' => (string) ($post['meta_title'] ?? ($existingPost['meta_title'] ?? '')),
        'meta_description' => (string) ($post['meta_description'] ?? ($existingPost['meta_description'] ?? '')),
        'author_display_name' => (string) ($post['author_display_name'] ?? ($existingPost['author_display_name'] ?? '')),
        'author_display_url' => (string) ($post['author_display_url'] ?? ($existingPost['author_display_url'] ?? '')),
        'post_template' => (string) ($post['post_template'] ?? ($existingPost['post_template'] ?? 'default')),
        'post_meta_json' => is_array($post['post_meta'] ?? null)
            ? (string) json_encode((array) $post['post_meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) ($existingPost['post_meta_json'] ?? ''),
        'published_at' => $publishedAt,
        'content_updated_at' => $contentUpdatedAt,
        'treat_update_as_new' => !empty($post['treat_update_as_new']),
    ]);

    if ($editorLocale === 'en') {
        $draftPost['title'] = (string) ($existingPost['title'] ?? '');
        $draftPost['slug'] = (string) ($existingPost['slug'] ?? '');
        $draftPost['content'] = $existingPost['content'] ?? '';
        $draftPost['excerpt'] = (string) ($existingPost['excerpt'] ?? '');
    } else {
        $draftPost['title_en'] = (string) ($existingPost['title_en'] ?? $draftPost['title_en'] ?? '');
        $draftPost['slug_en'] = (string) ($existingPost['slug_en'] ?? $draftPost['slug_en'] ?? '');
        $draftPost['content_en'] = $existingPost['content_en'] ?? ($draftPost['content_en'] ?? '');
        $draftPost['excerpt_en'] = (string) ($existingPost['excerpt_en'] ?? $draftPost['excerpt_en'] ?? '');
    }

    $tagNames = array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        preg_split('/[\r\n,]+/', (string) ($post['tags'] ?? '')) ?: []
    ), static fn (string $value): bool => $value !== ''));

    $editData['post'] = $draftPost;
    $editData['assignedCategoryIds'] = cms_admin_posts_normalize_bulk_ids(array_merge(
        [cms_admin_posts_normalize_positive_id($post['category_id'] ?? 0)],
        array_map('intval', (array) ($post['additional_category_ids'] ?? []))
    ));
    $editData['postTags'] = $tagNames !== []
        ? array_map(static fn (string $name): array => ['name' => $name, 'slug' => ''], $tagNames)
        : ($editData['postTags'] ?? []);
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
        'schema_type' => (string) ($post['schema_type'] ?? 'Article'),
        'sitemap_priority' => (string) ($post['sitemap_priority'] ?? ''),
        'sitemap_changefreq' => (string) ($post['sitemap_changefreq'] ?? 'monthly'),
        'hreflang_group' => (string) ($post['hreflang_group'] ?? ''),
    ]);

    return $editData;
}

function cms_admin_posts_view_config(object $module, string $view, ?array $overrideEditData = null, string $editorLocale = 'de'): array
{
    $normalizedView = cms_admin_posts_normalize_view($view);
    $editorLocale = cms_admin_posts_normalize_editor_locale($editorLocale);
    $aiTranslationEnabled = !class_exists(CoreModuleService::class)
        || CoreModuleService::getInstance()->isModuleEnabled('ai_services');
    $aiSeoMetadataState = cms_admin_posts_ai_seo_metadata_state($editorLocale);
    $aiSeoMetadataEnabled = $aiSeoMetadataState['available'];
    $baseTemplateVars = [
        'editorMediaToken' => Security::instance()->generateToken('editorjs_media'),
        'aiTranslationEnabled' => $aiTranslationEnabled,
        'aiTranslationToken' => $aiTranslationEnabled ? Security::instance()->generateToken('admin_ai_editorjs_translation') : '',
        'aiTranslationUrl' => $aiTranslationEnabled ? '/admin/ai-translate-editorjs' : '',
        'aiSeoMetadataEnabled' => $aiSeoMetadataEnabled,
        'aiSeoMetadataUnavailableReason' => $aiSeoMetadataState['reason'],
        'aiSettingsUrl' => cms_admin_posts_ai_settings_url(),
        'aiSeoMetadataToken' => $aiSeoMetadataEnabled ? Security::instance()->generateToken('admin_ai_seo_metadata') : '',
        'aiSeoMetadataUrl' => $aiSeoMetadataEnabled ? '/admin/ai-generate-seo-metadata' : '',
        'editorLocale' => $editorLocale,
        'useEditorJs' => false,
    ];

    if ($normalizedView === 'edit') {
        $id = cms_admin_posts_normalize_positive_id($_GET['id'] ?? 0);
        $editData = is_array($overrideEditData) ? $overrideEditData : $module->getEditData($id);

        if ($id > 0 && !empty($editData['isNew'])) {
            cms_admin_posts_flash('danger', 'Der angeforderte Beitrag existiert nicht mehr. Bitte Liste neu laden.');
            cms_admin_posts_redirect();
        }

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

        return [
            'section' => 'edit',
            'view_file' => __DIR__ . '/views/posts/edit.php',
            'page_title' => (!empty($editData['isNew']) ? 'Neuer Beitrag' : 'Beitrag bearbeiten') . ($editorLocale === 'en' ? ' · EN' : ''),
            'active_page' => 'posts',
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
        'view_file' => __DIR__ . '/views/posts/list.php',
        'page_title' => 'Beiträge',
        'active_page' => 'posts',
        'page_assets' => [],
        'template_vars' => array_replace($baseTemplateVars, [
            'listData' => $listData,
        ]),
        'data' => $listData,
    ];
}

function cms_admin_posts_resolve_module_class(string $moduleFile = __DIR__ . '/modules/posts/PostsModule.php'): string
{
    $moduleFile = realpath($moduleFile) ?: $moduleFile;
    $candidates = [
        'PostsModule',
        'CMS\\Admin\\Modules\\PostsModule',
        'CMSv2\\Admin\\Modules\\PostsModule',
    ];

    foreach ($candidates as $candidate) {
        if (!class_exists($candidate, false)) {
            continue;
        }

        try {
            $reflection = new \ReflectionClass($candidate);
        } catch (\ReflectionException) {
            continue;
        }

        if (!cms_admin_posts_reflection_matches_contract($reflection)) {
            continue;
        }

        return $candidate;
    }

    foreach (get_declared_classes() as $declaredClass) {
        try {
            $reflection = new \ReflectionClass($declaredClass);
        } catch (\ReflectionException) {
            continue;
        }

        $reflectionFile = $reflection->getFileName();
        if ($reflectionFile === false) {
            continue;
        }

        $reflectionFile = realpath($reflectionFile) ?: $reflectionFile;
        if ($reflectionFile !== $moduleFile) {
            continue;
        }

        if (!cms_admin_posts_reflection_matches_contract($reflection)) {
            continue;
        }

        return $declaredClass;
    }

    return '';
}

function cms_admin_posts_create_module(string $moduleFile = __DIR__ . '/modules/posts/PostsModule.php'): object
{
    $resolvedModuleFile = realpath($moduleFile) ?: $moduleFile;
    if (!is_file($resolvedModuleFile)) {
        throw new RuntimeException('PostsModule-Datei wurde nicht gefunden.');
    }

    $moduleClass = cms_admin_posts_resolve_module_class($resolvedModuleFile);
    if ($moduleClass === '') {
        require $resolvedModuleFile;
        $moduleClass = cms_admin_posts_resolve_module_class($resolvedModuleFile);
    }

    if ($moduleClass === '') {
        throw new RuntimeException('PostsModule konnte nach dem Laden der Moduldatei nicht aufgelöst werden.');
    }

    $module = new $moduleClass();
    if (!cms_admin_posts_module_matches_contract($module)) {
        throw new RuntimeException('PostsModule-Vertrag ist unvollständig oder inkompatibel.');
    }

    return $module;
}

if (!cms_admin_posts_can_access()) {
    header('Location: /');
    exit;
}

$postsModuleFile = __DIR__ . '/modules/posts/PostsModule.php';

$sectionPageConfig = [
    'route_path' => '/admin/posts',
    'view_file' => __DIR__ . '/views/posts/list.php',
    'page_title' => 'Beiträge',
    'active_page' => 'posts',
    'csrf_action' => 'admin_posts',
    'module_file' => $postsModuleFile,
    'module_factory' => static fn (): object => cms_admin_posts_create_module($postsModuleFile),
    'access_checker' => static fn (): bool => cms_admin_posts_can_access(),
    'request_context_resolver' => static function (object $module): array {
        $view = cms_admin_posts_normalize_view($_GET['action'] ?? 'list');
        $editorLocale = cms_admin_posts_normalize_editor_locale($_GET['lang'] ?? 'de');

        return cms_admin_posts_view_config($module, $view, null, $editorLocale);
    },
    'redirect_path_resolver' => static function (object $module, string $section, mixed $result): string {
        if (is_array($result) && isset($result['redirect_path']) && is_string($result['redirect_path'])) {
            return $result['redirect_path'];
        }

        $editorLocale = cms_admin_posts_normalize_editor_locale(
            is_array($result) && isset($result['editor_locale'])
                ? $result['editor_locale']
                : ($_POST['editor_locale'] ?? ($_GET['lang'] ?? 'de'))
        );

        if ($section === 'edit') {
            return cms_admin_posts_target_url(cms_admin_posts_normalize_positive_id($_GET['id'] ?? 0), $editorLocale, true);
        }

        return cms_admin_posts_target_url();
    },
    'post_handler' => static function (object $module, string $section, array $post): array {
        $rawPostAction = cms_admin_posts_extract_action_value($post);
        $postAction = cms_admin_posts_normalize_action($rawPostAction);
        $editorLocale = cms_admin_posts_normalize_editor_locale($post['editor_locale'] ?? ($_GET['lang'] ?? 'de'));

        if ($postAction === '') {
            return ['success' => false, 'error' => 'Unbekannte Beitrags-Aktion.'];
        }

        if (!cms_admin_posts_can_run_action($postAction)) {
            return ['success' => false, 'error' => 'Keine Berechtigung für diese Beitrags-Aktion.'];
        }

        switch ($postAction) {
            case 'switch_locale':
                $targetLocale = cms_admin_posts_resolve_switch_target_locale($rawPostAction, $editorLocale === 'en' ? 'de' : 'en');

                return [
                    'success' => true,
                    'message' => '',
                    'render_inline' => true,
                    'editor_locale' => $targetLocale,
                    'runtime_context' => cms_admin_posts_view_config(
                        $module,
                        'edit',
                        cms_admin_posts_build_inline_edit_data($module, $post),
                        $targetLocale
                    ),
                ];

            case 'save':
                $userId = Auth::instance()->getCurrentUser()->id ?? 0;
                try {
                    $result = $module->save($post, (int) $userId);
                } catch (\Throwable $e) {
                    // Unerwartete Fehler nicht als HTTP 500 enden lassen: Eingaben bleiben im Editor erhalten.
                    Logger::instance()->withChannel('admin.posts')->error('Beitrag konnte nicht gespeichert werden.', [
                        'post_id' => cms_admin_posts_normalize_positive_id($post['id'] ?? 0),
                        'exception_class' => get_debug_type($e),
                        'exception' => $e->getMessage(),
                    ]);
                    $result = ['success' => false, 'error' => 'Beitrag konnte nicht gespeichert werden. Bitte Logs prüfen.'];
                }
                if (!empty($result['success'])) {
                    $result['editor_locale'] = $editorLocale;
                    $result['redirect_path'] = cms_admin_posts_target_url(cms_admin_posts_normalize_positive_id($result['id'] ?? 0), $editorLocale);
                    return $result;
                }

                return [
                    'success' => false,
                    'error' => (string) ($result['error'] ?? 'Beitrag konnte nicht gespeichert werden.'),
                    'details' => is_array($result['details'] ?? null) ? $result['details'] : [],
                    'render_inline' => true,
                    'editor_locale' => $editorLocale,
                    'runtime_context' => cms_admin_posts_view_config($module, 'edit', cms_admin_posts_build_inline_edit_data($module, $post), $editorLocale),
                ];

            case 'copy_de_to_en':
                $id = cms_admin_posts_normalize_positive_id($post['id'] ?? ($_GET['id'] ?? 0));
                if ($id < 1) {
                    return ['success' => false, 'error' => 'Bitte den Beitrag zuerst speichern, bevor Inhalte nach EN kopiert werden.'];
                }

                $userId = Auth::instance()->getCurrentUser()->id ?? 0;
                $result = $module->copyGermanToEnglish($id, (int) $userId);
                if (!empty($result['success'])) {
                    $result['editor_locale'] = 'en';
                    $result['redirect_path'] = cms_admin_posts_target_url($id, 'en');
                }

                return $result;

            case 'delete':
                $id = cms_admin_posts_normalize_positive_id($post['id'] ?? 0);
                if ($id < 1) {
                    return ['success' => false, 'error' => 'Ungültige Beitrags-ID.'];
                }

                $result = $module->delete($id);
                if (!empty($result['success'])) {
                    $result['redirect_path'] = cms_admin_posts_target_url();
                }

                return $result;

            case 'bulk':
                $bulkAction = cms_admin_posts_normalize_bulk_action($post['bulk_action'] ?? '');
                $ids = cms_admin_posts_normalize_bulk_ids($post['ids'] ?? []);
                if ($bulkAction === '') {
                    return ['success' => false, 'error' => 'Unbekannte Bulk-Aktion für Beiträge.'];
                }

                if ($ids === []) {
                    return ['success' => false, 'error' => 'Bitte mindestens einen gültigen Beitrag auswählen.'];
                }

                $result = $module->bulkAction($bulkAction, $ids, $post);
                if (!empty($result['success'])) {
                    $result['redirect_path'] = cms_admin_posts_target_url();
                }

                return $result;

            case 'save_category':
                return $module->saveCategory($post);

            case 'delete_category':
                $catId = cms_admin_posts_normalize_positive_id($post['cat_id'] ?? 0);
                $replacementCategoryId = cms_admin_posts_normalize_positive_id($post['replacement_category_id'] ?? 0);
                if ($catId < 1) {
                    return ['success' => false, 'error' => 'Ungültige Kategorie-ID.'];
                }

                return $module->deleteCategory($catId, $replacementCategoryId);

            default:
                return ['success' => false, 'error' => 'Unbekannte Beitrags-Aktion.'];
        }
    },
];

require __DIR__ . '/partials/section-page-shell.php';
