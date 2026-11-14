<?php
declare(strict_types=1);

namespace CMS\MemberArea;

use CMS\Auth;
use CMS\Hooks;
use CMS\Json;
use CMS\Router;
use CMS\Security;
use CMS\Auth\AuthManager;
use CMS\Auth\MFA\BackupCodesManager;
use CMS\Auth\MFA\TotpAdapter;
use CMS\Auth\Passkey\WebAuthnAdapter;
use CMS\Auth\SSO\EntraSsoService;
use CMS\Auth\SSO\OidcFlowState;
use CMS\Member\MemberI18n;
use CMS\Member\MemberSanitizer;
use CMS\Member\PluginDashboardRegistry;
use CMS\Routing\SsoRouter;
use CMS\Services\CmsAuthPageService;
use CMS\Services\CoreModuleService;
use CMS\Services\MediaService;
use CMS\Services\MemberContentService;
use CMS\Services\MemberService;
use CMS\Services\MessageService;
use CMS\Services\Security\MfaEnforcementService;
use CMS\Services\NotificationService;

if (!defined('ABSPATH')) {
    exit;
}

final class MemberController
{
    private const MEMBER_MEDIA_MOVE_TARGET_MAX_NODES = 500;
    private const DEFAULT_PROFILE_FIELDS = ['first_name', 'last_name', 'username', 'birth_date', 'email', 'website', 'social'];
    private const REQUIRED_PROFILE_FIELDS = ['username', 'email'];

    /** Dashboard-Bereiche, deren Reihenfolge `section_order` bestimmt. */
    public const DASHBOARD_SECTIONS = ['quick_start', 'stats', 'widgets', 'plugins'];

    /** Kern-Widgets, die über die Admin-Allowlist `widgets` ein- und ausgeblendet werden. */
    public const CORE_WIDGETS = ['profile', 'activity', 'messages', 'bookmarks', 'notifications', 'quick_links', 'statistics'];

    /** Seiten, die bei `onboarding.require_profile_completion` erst nach vollständigen Pflichtfeldern öffnen (MD-23). */
    public const PROFILE_GATED_PAGES = ['messages', 'media'];

    /** Reihenfolge der Menügruppen in der Sidebar (MD-21). */
    private const MENU_CATEGORY_ORDER = ['core' => 0, 'content' => 10, 'account' => 20, 'plugins' => 30, 'admin' => 90];

    /** Längenbegrenzungen für freie Profilfelder (MD-09). */
    private const PROFILE_TEXT_LIMITS = ['company' => 120, 'position' => 120, 'location' => 120];

    private static ?self $instance = null;

    private Auth $auth;
    private MemberService $memberService;
    private MessageService $messageService;
    private AuthManager $authManager;
    private PluginDashboardRegistry $registry;

    /** @var array<string,mixed>|null */
    private ?array $settings = null;

    /** @var object|null */
    private ?object $currentUser = null;

    /** @var array<string,string> Ein CSRF-Token je Aktion und Request (MD-04) */
    private array $csrfTokens = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->auth = Auth::instance();
        $this->memberService = MemberService::getInstance();
        $this->messageService = MessageService::getInstance();
        $this->authManager = AuthManager::instance();
        $this->registry = PluginDashboardRegistry::instance();
        $this->registry->init();
    }

    /**
     * Leitet nicht angemeldete Besucher auf die (lokalisierte) Login-Seite und merkt sich den Benutzer
     * für den restlichen Request (MD-01, MD-02).
     */
    public function requireAuth(): void
    {
        if ($this->currentUser !== null) {
            return;
        }

        $user = $this->auth->isLoggedIn() ? $this->auth->getCurrentUser() : null;
        if (!is_object($user)) {
            $this->redirect(self::loginRedirectPath((string)($_SERVER['REQUEST_URI'] ?? '/member/dashboard')));
        }

        $this->currentUser = $user;
    }

    /**
     * Login-Pfad mit Rücksprung; nutzt `auth_slug_mode` und die Sprache der Anfrage wie der MemberRouter.
     */
    public static function loginRedirectPath(string $requestUri): string
    {
        $returnTo = Security::safeLocalPath($requestUri, '/member/dashboard');

        try {
            $loginPath = CmsAuthPageService::getInstance()->getPublicPath('login', Router::instance()->getRequestLocale());
        } catch (\Throwable) {
            $loginPath = '/login';
        }

        return $loginPath . '?redirect=' . rawurlencode($returnTo);
    }

    public function getCurrentUser(): object
    {
        if ($this->currentUser === null) {
            $this->requireAuth();
        }

        return $this->currentUser;
    }

    public function getUserId(): int
    {
        return (int)($this->getCurrentUser()->id ?? 0);
    }

    /**
     * @return array<string,mixed>
     */
    public function getSettings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $memberDashboardModuleEnabled = !class_exists(CoreModuleService::class)
            || CoreModuleService::getInstance()->isModuleEnabled('member_dashboard');

        $moduleFile = ABSPATH . 'admin/modules/member/MemberDashboardModule.php';
        if (!class_exists('MemberDashboardModule') && file_exists($moduleFile)) {
            require_once $moduleFile;
        }

        if (class_exists('MemberDashboardModule')) {
            try {
                $module = new \MemberDashboardModule();
                if (method_exists($module, 'getRuntimeSettings')) {
                    $runtimeSettings = $module->getRuntimeSettings();
                    if (is_array($runtimeSettings)) {
                        $this->settings = $runtimeSettings;
                    }
                } else {
                    $data = $module->getData();
                    if (is_array($data['settings'] ?? null) && $data['settings'] !== []) {
                        $this->settings = $data['settings'];
                    }
                }

                if (is_array($this->settings)) {
                    $this->settings = $this->normalizeProfileSettings($this->settings);
                    $this->settings['dashboard_enabled'] = $memberDashboardModuleEnabled
                        && !empty($this->settings['dashboard_enabled']);
                    return $this->settings;
                }
            } catch (\Throwable) {
            }
        }

        $this->settings = [
            'dashboard_enabled' => $memberDashboardModuleEnabled,
            'registration_enabled' => true,
            'email_verification' => false,
            'welcome_message' => '',
            'default_role' => 'member',
            'widgets' => self::CORE_WIDGETS,
            'profile_fields' => self::DEFAULT_PROFILE_FIELDS,
            'required_profile_fields' => self::REQUIRED_PROFILE_FIELDS,
            'custom_profile_fields' => [],
            'profile_field_definitions' => $this->getProfileFieldDefinitions(),
            'dashboard_columns' => 3,
            'section_order' => 'quick_start,stats,widgets,plugins',
            'dashboard_logo' => '',
            'dashboard_greeting' => MemberI18n::t('member.dashboard.greeting_default'),
            'dashboard_welcome_text' => '',
            'show_welcome' => true,
            'subscription_visible' => true,
            'custom_widgets' => [1 => ['title' => '', 'content' => '', 'icon' => ''], 2 => ['title' => '', 'content' => '', 'icon' => ''], 3 => ['title' => '', 'content' => '', 'icon' => ''], 4 => ['title' => '', 'content' => '', 'icon' => '']],
            'custom_widget_order' => ['1', '2', '3', '4'],
            'design' => [
                'primary' => '#6366f1',
                'accent' => '#8b5cf6',
                'bg' => '#f1f5f9',
                'card_bg' => '#ffffff',
                'text' => '#1e293b',
                'border' => '#e2e8f0',
            ],
            'frontend_modules' => [
                'show_quickstart' => true,
                'show_stats' => true,
                'show_custom_widgets' => true,
                'show_plugin_widgets' => true,
                'show_notifications_panel' => true,
                'show_onboarding_panel' => true,
            ],
            'notifications' => [
                'center_enabled' => true,
                'email_enabled' => false,
                'digest_frequency' => 'daily',
                'sender_name' => '365CMS Member Hub',
                'empty_text' => '',
                'types' => ['system', 'messages'],
            ],
            'onboarding' => [
                'enabled' => true,
                'title' => MemberI18n::t('member.onboarding.title_default'),
                'intro' => MemberI18n::t('member.onboarding.intro_default'),
                'steps' => [],
                'cta_label' => MemberI18n::t('member.onboarding.cta_default'),
                'cta_url' => '/member/profile',
                'require_profile_completion' => false,
            ],
            'plugin_widget_order' => [],
        ];

        $this->settings = $this->normalizeProfileSettings($this->settings);

        return $this->settings;
    }

    /**
     * Startseite des Mitgliederbereichs: Dashboard, bei abgeschaltetem Dashboard das Profil (MD-03).
     */
    public function getHomePath(): string
    {
        return !empty($this->getSettings()['dashboard_enabled']) ? '/member/dashboard' : '/member/profile';
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getProfileFieldDefinitions(): array
    {
        $settings = $this->settings ?? [];
        if (is_array($settings['profile_field_definitions'] ?? null) && $settings['profile_field_definitions'] !== []) {
            return $settings['profile_field_definitions'];
        }

        $definitions = [
            'first_name' => ['label' => MemberI18n::t('member.profile.field.first_name'), 'type' => 'text'],
            'last_name' => ['label' => MemberI18n::t('member.profile.field.last_name'), 'type' => 'text'],
            'username' => ['label' => MemberI18n::t('member.profile.field.username'), 'type' => 'text', 'required' => true, 'locked' => true],
            'birth_date' => ['label' => MemberI18n::t('member.profile.field.birth_date'), 'type' => 'date'],
            'email' => ['label' => MemberI18n::t('member.profile.field.email'), 'type' => 'email', 'required' => true, 'locked' => true],
            'website' => ['label' => MemberI18n::t('member.profile.field.website'), 'type' => 'url'],
            'social' => ['label' => MemberI18n::t('member.profile.field.social'), 'type' => 'url'],
            'bio' => ['label' => MemberI18n::t('member.profile.field.bio'), 'type' => 'wysiwyg'],
            'phone' => ['label' => MemberI18n::t('member.profile.field.phone'), 'type' => 'tel'],
            'company' => ['label' => MemberI18n::t('member.profile.field.company'), 'type' => 'text'],
            'position' => ['label' => MemberI18n::t('member.profile.field.position'), 'type' => 'text'],
            'location' => ['label' => MemberI18n::t('member.profile.field.location'), 'type' => 'text'],
            'avatar' => ['label' => MemberI18n::t('member.profile.field.avatar'), 'type' => 'url'],
        ];

        foreach ((array)($settings['custom_profile_fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = (string)($field['key'] ?? '');
            if ($key === '' || isset($definitions[$key])) {
                continue;
            }
            $definitions[$key] = [
                'label' => (string)($field['label'] ?? $key),
                'type' => (string)($field['type'] ?? 'text'),
                'required' => !empty($field['required']),
                'custom' => true,
            ];
        }

        return $definitions;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function normalizeProfileSettings(array $settings): array
    {
        $definitions = $this->getProfileFieldDefinitionsFromSettings($settings);
        $profileFields = array_values(array_intersect(
            array_keys($definitions),
            array_map('strval', (array)($settings['profile_fields'] ?? self::DEFAULT_PROFILE_FIELDS))
        ));
        if ($profileFields === []) {
            $profileFields = self::DEFAULT_PROFILE_FIELDS;
        }
        $settings['profile_fields'] = array_values(array_unique(array_merge($profileFields, self::REQUIRED_PROFILE_FIELDS)));
        $settings['required_profile_fields'] = array_values(array_unique(array_merge(
            array_intersect($settings['profile_fields'], array_map('strval', (array)($settings['required_profile_fields'] ?? self::REQUIRED_PROFILE_FIELDS))),
            self::REQUIRED_PROFILE_FIELDS
        )));
        $settings['custom_profile_fields'] = is_array($settings['custom_profile_fields'] ?? null) ? $settings['custom_profile_fields'] : [];
        $settings['profile_field_definitions'] = $definitions;

        return $settings;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,array<string,mixed>>
     */
    private function getProfileFieldDefinitionsFromSettings(array $settings): array
    {
        $currentSettings = $this->settings;
        $this->settings = $settings;
        $definitions = $this->getProfileFieldDefinitions();
        $this->settings = $currentSettings;

        return $definitions;
    }

    /**
     * CSRF-Token für Member-Formulare. Je Aktion wird pro Request genau ein Token erzeugt und an alle Formulare
     * der Seite gegeben. So verdrängen viele Zeilen (z. B. in der Medienverwaltung) nicht die Tokens der ersten
     * Formulare aus dem 20er-Puffer der Session (MD-04).
     */
    public function csrfToken(string $action): string
    {
        return $this->csrfTokens[$action] ??= Security::instance()->generateToken('member_' . $action);
    }

    public function verifyCsrf(string $action, ?string $token = null): bool
    {
        $token ??= (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        return Security::instance()->verifyToken($token, 'member_' . $action);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function flash(string $type, string $message, array $payload = []): void
    {
        $_SESSION['member_alert'] = [
            'type' => $type,
            'message' => $message,
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function consumeFlash(): ?array
    {
        if (empty($_SESSION['member_alert']) || !is_array($_SESSION['member_alert'])) {
            return null;
        }

        $flash = $_SESSION['member_alert'];
        unset($_SESSION['member_alert']);
        return $flash;
    }

    /**
     * Weiterleitung nach POST (PRG). Für POST-Anfragen wird 303 gesendet, damit der Browser per GET lädt.
     */
    public function redirect(string $path): never
    {
        if (!preg_match('#^https?://#i', $path)) {
            $path = '/' . ltrim($path, '/');
        }

        $isPost = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
        header('Location: ' . $path, true, $isPost ? 303 : 302);
        exit;
    }

    private function securityFailed(string $path): never
    {
        $this->flash('danger', MemberI18n::t('member.flash.csrf_failed'));
        $this->redirect($path);
    }

    /**
     * @return array<string,mixed>
     */
    public function getNotificationCenterConfig(): array
    {
        $settings = $this->getSettings();
        $notifications = is_array($settings['notifications'] ?? null) ? $settings['notifications'] : [];
        $types = array_values(array_filter(array_map([$this, 'normalizeNotificationType'], (array)($notifications['types'] ?? []))));
        $emptyText = trim((string)($notifications['empty_text'] ?? ''));

        return [
            'center_enabled' => !array_key_exists('center_enabled', $notifications) || !empty($notifications['center_enabled']),
            'empty_text' => $emptyText !== '' ? $emptyText : MemberI18n::t('member.notifications.empty'),
            'types' => array_values(array_unique($types)),
        ];
    }

    /**
     * @return array<int,object>
     */
    public function getNotificationCenterNotifications(int $limit = 20): array
    {
        $config = $this->getNotificationCenterConfig();
        if (empty($config['center_enabled'])) {
            return [];
        }

        $limit = max(1, $limit);
        $fetchLimit = max($limit * 4, $limit);
        $notifications = NotificationService::getInstance()->getRecent($this->getUserId(), $fetchLimit);

        return array_slice($this->filterNotificationFeed($notifications, (array)($config['types'] ?? [])), 0, $limit);
    }

    public function getUnreadNotificationCount(): int
    {
        $config = $this->getNotificationCenterConfig();

        return empty($config['center_enabled']) ? 0 : NotificationService::getInstance()->countUnread($this->getUserId());
    }

    public function getDisplayName(): string
    {
        $user = $this->getCurrentUser();
        $meta = $this->memberService->getUserMeta($this->getUserId());
        $firstName = trim((string)($meta['first_name'] ?? ''));
        $lastName = trim((string)($meta['last_name'] ?? ''));
        $fullName = trim($firstName . ' ' . $lastName);

        if ($fullName !== '') {
            return $fullName;
        }

        if (!empty($user->display_name)) {
            return (string)$user->display_name;
        }

        return (string)($user->username ?? $user->email ?? MemberI18n::t('member.common.member'));
    }

    public function getInitials(): string
    {
        $name = $this->getDisplayName();
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_substr($part, 0, 1, 'UTF-8');
        }

        return mb_strtoupper($initials !== '' ? $initials : 'M', 'UTF-8');
    }

    public function getAvatarUrl(): string
    {
        $meta = $this->memberService->getUserMeta($this->getUserId());

        return $this->normalizeProfileMediaUrl((string)($meta['avatar'] ?? ''));
    }

    public function canAccessAdminPortal(): bool
    {
        if ($this->auth->isAdmin()) {
            return true;
        }

        foreach ($this->getAdminPortalCapabilities() as $capability) {
            if ($this->auth->hasCapability($capability)) {
                return true;
            }
        }

        return false;
    }

    public function getAdminPortalUrl(): string
    {
        return '/admin';
    }

    /**
     * @return array<int,string>
     */
    private function getAdminPortalCapabilities(): array
    {
        return ['adminportal', 'admin.portal', 'admin-portal'];
    }

    /**
     * Sidebar-Menü: gruppiert nach `category`, innerhalb der Gruppe nach `priority` und Bezeichnung sortiert;
     * Einträge mit `parent_slug` erscheinen als `children` ihres Elterneintrags (MD-21).
     *
     * @return array<int,array<string,mixed>>
     */
    public function getMenuItems(string $activePage): array
    {
        $settings = $this->getSettings();
        $items = [
            ['slug' => 'profile', 'label' => MemberI18n::t('member.nav.profile'), 'icon' => '👤', 'url' => '/member/profile', 'category' => 'core', 'priority' => 10],
            ['slug' => 'security', 'label' => MemberI18n::t('member.nav.security'), 'icon' => '🛡️', 'url' => '/member/security', 'category' => 'core', 'priority' => 20],
            ['slug' => 'notifications', 'label' => MemberI18n::t('member.nav.notifications'), 'icon' => '🔔', 'url' => '/member/notifications', 'category' => 'core', 'priority' => 30],
            ['slug' => 'messages', 'label' => MemberI18n::t('member.nav.messages'), 'icon' => '✉️', 'url' => '/member/messages', 'category' => 'core', 'priority' => 40],
            ['slug' => 'media', 'label' => MemberI18n::t('member.nav.media'), 'icon' => '🗂️', 'url' => '/member/media', 'category' => 'content', 'priority' => 10],
            ['slug' => 'favorites', 'label' => MemberI18n::t('member.nav.favorites'), 'icon' => '⭐', 'url' => '/member/favorites', 'category' => 'content', 'priority' => 20],
            ['slug' => 'privacy', 'label' => MemberI18n::t('member.nav.privacy'), 'icon' => '🔐', 'url' => '/member/privacy', 'category' => 'account', 'priority' => 10],
        ];

        if (!empty($settings['dashboard_enabled'])) {
            array_unshift($items, ['slug' => 'dashboard', 'label' => MemberI18n::t('member.nav.dashboard'), 'icon' => '🏠', 'url' => '/member/dashboard', 'category' => 'core', 'priority' => 0]);
        }

        if (!empty($settings['subscription_visible']) && CoreModuleService::getInstance()->isModuleEnabled('subscription_member_area')) {
            $items[] = ['slug' => 'subscription', 'label' => MemberI18n::t('member.nav.subscription'), 'icon' => '💳', 'url' => '/member/subscription', 'category' => 'account', 'priority' => 20];
        }

        // Einziger Admin-Link im Mitgliederbereich (UI-04).
        if ($this->canAccessAdminPortal()) {
            $items[] = ['slug' => 'admin', 'label' => MemberI18n::t('member.nav.admin'), 'icon' => '⚙️', 'url' => $this->getAdminPortalUrl(), 'category' => 'admin', 'priority' => 0];
        }

        $items = Hooks::applyFilters('member_menu_items', $items);
        if (!is_array($items)) {
            return [];
        }

        return $this->buildMenuTree($items, $activePage);
    }

    /**
     * @param array<int,mixed> $items
     * @return array<int,array<string,mixed>>
     */
    public function buildMenuTree(array $items, string $activePage): array
    {
        $normalized = [];
        foreach ($items as $index => $item) {
            if (!is_array($item) || trim((string)($item['slug'] ?? '')) === '') {
                continue;
            }

            $slug = (string)$item['slug'];
            $section = str_starts_with($slug, 'plugin_') ? $this->registry->getSection(substr($slug, 7)) : null;
            $category = MemberSanitizer::text($item['category'] ?? ($section['category'] ?? 'plugins'), 40, 'plugins');
            $priority = isset($item['priority']) ? (int)$item['priority'] : (int)($section['priority'] ?? 50);
            $parent = trim((string)($item['parent_slug'] ?? ($section['parent_slug'] ?? '')));

            $normalized[$slug] = [
                'slug' => $slug,
                'label' => MemberSanitizer::text($item['label'] ?? $slug, 120, $slug),
                'icon' => MemberSanitizer::text($item['icon'] ?? '•', 16, '•'),
                'url' => MemberSanitizer::link($item['url'] ?? '#', '#'),
                'category' => $category,
                'priority' => $priority,
                'parent_slug' => $parent !== '' ? $parent : null,
                'active' => $slug === $activePage || !empty($item['active']),
                'children' => [],
                '_index' => (int)$index,
            ];
        }

        $compare = static function (array $a, array $b): int {
            $categoryA = self::MENU_CATEGORY_ORDER[$a['category']] ?? 50;
            $categoryB = self::MENU_CATEGORY_ORDER[$b['category']] ?? 50;

            return [$categoryA, $a['category'], $a['priority'], $a['_index']] <=> [$categoryB, $b['category'], $b['priority'], $b['_index']];
        };

        $topLevel = [];
        $children = [];
        foreach ($normalized as $slug => $item) {
            $parent = $item['parent_slug'];
            $parentKey = $parent === null ? null : (isset($normalized[$parent]) ? $parent : (isset($normalized['plugin_' . $parent]) ? 'plugin_' . $parent : null));
            if ($parentKey !== null && $parentKey !== $slug) {
                $children[$parentKey][] = $item;
                continue;
            }
            $topLevel[$slug] = $item;
        }

        foreach ($topLevel as $slug => &$item) {
            $itemChildren = $children[$slug] ?? [];
            usort($itemChildren, $compare);
            $item['children'] = array_map(static function (array $child): array {
                unset($child['_index'], $child['children']);
                return $child;
            }, $itemChildren);
            if (!$item['active']) {
                foreach ($item['children'] as $child) {
                    if (!empty($child['active'])) {
                        $item['expanded'] = true;
                    }
                }
            }
        }
        unset($item);

        $topLevel = array_values($topLevel);
        usort($topLevel, $compare);

        return array_map(static function (array $item): array {
            unset($item['_index']);
            return $item;
        }, $topLevel);
    }

    /**
     * @return array<string,mixed>
     */
    public function getDashboardData(): array
    {
        $userId = $this->getUserId();
        $settings = $this->getSettings();
        $contentService = MemberContentService::getInstance();
        $data = $this->memberService->getMemberDashboardData($userId);
        $data['profile_completion'] = $this->getProfileCompletion();
        $data['profile_completion_required'] = $this->isProfileCompletionRequired();
        $data['recent_activity'] = $this->getRecentActivity();
        $data['unread_messages'] = $this->messageService->getUnreadCount($userId);
        $data['favorites'] = $this->getFavorites(5);
        $data['content_stats'] = $contentService->getContentStats($userId);
        $data['plugin_widgets'] = $this->getPluginWidgets();
        $data['custom_widgets'] = $this->getCustomWidgets();
        $data['recent_notifications'] = $this->getNotificationCenterNotifications(5);
        $data['unread_notifications'] = $this->getUnreadNotificationCount();
        $data['notification_center'] = $this->getNotificationCenterConfig();
        $data['layout'] = $this->getDashboardLayout();
        $data['settings'] = $settings;
        return $data;
    }

    /**
     * Wirksame Layout-Einstellungen des Dashboards (MD-19): Bereichsreihenfolge, Spaltenzahl und die
     * Allowlist der Kern-Widgets.
     *
     * @return array{section_order: list<string>, columns: int, widgets: list<string>}
     */
    public function getDashboardLayout(): array
    {
        $settings = $this->getSettings();

        return [
            'section_order' => self::normalizeSectionOrder($settings['section_order'] ?? ''),
            'columns' => max(1, min(4, (int)($settings['dashboard_columns'] ?? 3))),
            'widgets' => array_values(array_intersect(
                array_map('strval', is_array($settings['widgets'] ?? null) ? $settings['widgets'] : self::CORE_WIDGETS),
                self::CORE_WIDGETS
            )),
        ];
    }

    /**
     * `quick_start,stats,widgets,plugins` → Liste; unbekannte Werte entfallen, fehlende Bereiche werden ergänzt
     * (`quick_start` vorne wie im bisherigen Layout, die übrigen hinten).
     *
     * @return list<string>
     */
    public static function normalizeSectionOrder(mixed $value): array
    {
        $order = [];
        foreach (explode(',', (string)$value) as $section) {
            $section = trim($section);
            if (in_array($section, self::DASHBOARD_SECTIONS, true) && !in_array($section, $order, true)) {
                $order[] = $section;
            }
        }

        if (!in_array('quick_start', $order, true)) {
            array_unshift($order, 'quick_start');
        }
        foreach (self::DASHBOARD_SECTIONS as $section) {
            if (!in_array($section, $order, true)) {
                $order[] = $section;
            }
        }

        return $order;
    }

    /**
     * Bootstrap-Spaltenklassen für das Widget-Raster (`dashboard_columns` 1–4, mobil immer einspaltig).
     */
    public static function widgetColumnClass(int $columns): string
    {
        return match (max(1, min(4, $columns))) {
            1 => 'col-12',
            2 => 'col-12 col-md-6',
            4 => 'col-12 col-sm-6 col-xl-3',
            default => 'col-12 col-md-6 col-xl-4',
        };
    }

    /**
     * @return array<string,mixed>
     */
    public function getProfileCompletion(): array
    {
        $settings = $this->getSettings();
        $meta = $this->memberService->getUserMeta($this->getUserId());
        $user = $this->getCurrentUser();
        $selectedFields = (array)($settings['profile_fields'] ?? []);
        $requiredFields = array_map('strval', (array)($settings['required_profile_fields'] ?? self::REQUIRED_PROFILE_FIELDS));
        $definitions = is_array($settings['profile_field_definitions'] ?? null) ? $settings['profile_field_definitions'] : [];
        $completed = 0;
        $missing = [];
        $missingRequired = [];
        $total = max(1, count($selectedFields));

        foreach ($selectedFields as $field) {
            $field = (string)$field;
            $value = match ($field) {
                'username' => $user->username ?? '',
                'email' => $user->email ?? '',
                default => $meta[$field] ?? '',
            };

            if (trim((string)$value) !== '') {
                $completed++;
                continue;
            }

            $missing[] = $field;
            if (in_array($field, $requiredFields, true) || !empty($definitions[$field]['required'])) {
                $missingRequired[] = $field;
            }
        }

        return [
            'completed' => $completed,
            'total' => $total,
            'percentage' => (int)round(($completed / $total) * 100),
            'missing' => $missing,
            'missing_required' => $missingRequired,
            'missing_labels' => array_map(
                static fn (string $field): string => (string)($definitions[$field]['label'] ?? ucwords(str_replace('_', ' ', $field))),
                $missing
            ),
        ];
    }

    /**
     * `onboarding.require_profile_completion` aktiv und mindestens ein Pflichtfeld leer (MD-23).
     */
    public function isProfileCompletionRequired(): bool
    {
        $onboarding = is_array($this->getSettings()['onboarding'] ?? null) ? $this->getSettings()['onboarding'] : [];
        if (empty($onboarding['require_profile_completion'])) {
            return false;
        }

        return $this->getProfileCompletion()['missing_required'] !== [];
    }

    /**
     * Sperrt Bereiche aus PROFILE_GATED_PAGES, bis alle Pflichtfelder ausgefüllt sind.
     */
    public function enforceProfileCompletion(string $pageKey): void
    {
        if (!in_array($pageKey, self::PROFILE_GATED_PAGES, true) || !$this->isProfileCompletionRequired()) {
            return;
        }

        $this->flash('warning', MemberI18n::t('member.onboarding.gate_notice'));
        $this->redirect('/member/profile');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getPluginWidgets(): array
    {
        $widgets = $this->registry->getDashboardWidgets($this->getCurrentUser());
        $settings = $this->getSettings();
        $order = array_values(array_filter(array_map('strval', (array)($settings['plugin_widget_order'] ?? []))));

        usort($widgets, static function (array $a, array $b) use ($order): int {
            $aPos = array_search((string)($a['plugin'] ?? $a['slug'] ?? ''), $order, true);
            $bPos = array_search((string)($b['plugin'] ?? $b['slug'] ?? ''), $order, true);
            $aPos = $aPos === false ? 999 : $aPos;
            $bPos = $bPos === false ? 999 : $bPos;
            return $aPos <=> $bPos;
        });

        $widgets = Hooks::applyFilters('member_dashboard_widgets', $widgets, $this->getCurrentUser(), $settings);
        if (!is_array($widgets)) {
            return [];
        }

        $normalizedWidgets = [];
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }

            $normalizedWidgets[] = self::sanitizeDashboardWidget($widget);
        }

        return $normalizedWidgets;
    }

    /**
     * Normalisiert eine Plugin-Widget-Kachel. `html` (bereits escaptes Markup eines vertrauenswürdigen
     * render-Callbacks der Registry) wird unverändert übernommen, `anchor` auf eine gültige ID reduziert.
     *
     * @param array<string,mixed> $widget
     * @return array<string,mixed>
     */
    public static function sanitizeDashboardWidget(array $widget): array
    {
        $stats = is_array($widget['stats'] ?? null) ? $widget['stats'] : null;
        $defaultLinkLabel = MemberI18n::t('member.widget.open');
        $defaultAdminLabel = MemberI18n::t('member.widget.manage');

        $sanitized = [
            'plugin' => MemberSanitizer::text($widget['plugin'] ?? '', 120),
            'slug' => MemberSanitizer::text($widget['slug'] ?? '', 120),
            'icon' => MemberSanitizer::text($widget['icon'] ?? '🔌', 16, '🔌'),
            'title' => MemberSanitizer::text($widget['title'] ?? 'Plugin', 160, 'Plugin'),
            'description' => MemberSanitizer::text($widget['description'] ?? '', 400),
            'color' => MemberSanitizer::color($widget['color'] ?? MemberSanitizer::DEFAULT_COLOR),
            'link' => MemberSanitizer::link($widget['link'] ?? '/member/dashboard', '/member/dashboard'),
            'link_label' => MemberSanitizer::text($widget['link_label'] ?? $defaultLinkLabel, 80, $defaultLinkLabel),
            'admin_link' => MemberSanitizer::nullableLink($widget['admin_link'] ?? null),
            'admin_label' => MemberSanitizer::text($widget['admin_label'] ?? $defaultAdminLabel, 80, $defaultAdminLabel),
            'badge' => MemberSanitizer::nullableText($widget['badge'] ?? null, 40),
            'stats' => $stats === null ? null : [
                'count' => (int)($stats['count'] ?? 0),
                'label' => MemberSanitizer::text($stats['label'] ?? '', 80, MemberI18n::t('member.widget.entries')),
            ],
            'anchor' => MemberSanitizer::anchor($widget['anchor'] ?? ''),
        ];

        if (is_string($widget['html'] ?? null) && trim($widget['html']) !== '') {
            $sanitized['html'] = $widget['html'];
        }

        return $sanitized;
    }

    /**
     * Eigene Info-Widgets. Der Inhalt wird mit dem Profil `strict` des PurifierService ausgegeben, damit das im
     * Admin erlaubte HTML (Absätze, Links, Listen) wirkt statt als Text zu erscheinen.
     *
     * @return array<int,array<string,string>>
     */
    public function getCustomWidgets(): array
    {
        $settings = $this->getSettings();
        $defaultOrder = ['1', '2', '3', '4'];
        $customWidgetOrder = array_values(array_filter(array_map('strval', (array)($settings['custom_widget_order'] ?? $defaultOrder)), static function (string $value): bool {
            return in_array($value, ['1', '2', '3', '4'], true);
        }));

        foreach ($defaultOrder as $defaultValue) {
            if (!in_array($defaultValue, $customWidgetOrder, true)) {
                $customWidgetOrder[] = $defaultValue;
            }
        }

        $widgets = [];
        foreach ($customWidgetOrder as $widgetPosition) {
            $widget = (array)($settings['custom_widgets'][(int)$widgetPosition] ?? []);
            if (trim((string)($widget['title'] ?? '')) === '' && trim(strip_tags((string)($widget['content'] ?? ''))) === '') {
                continue;
            }
            $widgets[] = [
                'title' => MemberSanitizer::text($widget['title'] ?? '', 120),
                'content' => MemberSanitizer::multiline($widget['content'] ?? '', 2000),
                'content_html' => self::purifyStrict(mb_substr((string)($widget['content'] ?? ''), 0, 4000, 'UTF-8')),
                'icon' => MemberSanitizer::text($widget['icon'] ?? '✨', 16, '✨'),
            ];
        }

        return $widgets;
    }

    /**
     * Begrüßungstext des Dashboards als bereinigtes HTML (MD-22).
     */
    public function getWelcomeHtml(): string
    {
        $text = trim((string)($this->getSettings()['dashboard_welcome_text'] ?? ''));
        if ($text === '') {
            return '<p>' . htmlspecialchars(MemberI18n::t('member.dashboard.welcome_default'), ENT_QUOTES, 'UTF-8') . '</p>';
        }

        return self::purifyStrict($text);
    }

    private static function purifyStrict(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        try {
            return \CMS\Services\PurifierService::getInstance()->purify($html, 'strict');
        } catch (\Throwable) {
            return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8'));
        }
    }

    /**
     * @return array<int,object>
     */
    public function getRecentActivity(int $limit = 8): array
    {
        return $this->memberService->getRecentActivity($this->getUserId(), $limit);
    }

    /**
     * @return array<int,object>
     */
    public function getOrders(): array
    {
        return array_map(fn ($row) => $this->normalizeMemberOrderRow($row), $this->memberService->getMemberOrders($this->getUserId()));
    }

    private function normalizeMemberOrderRow(object $order): object
    {
        $data = (array) $order;
        $contactData = $this->decodeMemberOrderContactData((string) ($data['contact_data'] ?? ''));
        $status = strtolower(trim((string) ($data['status'] ?? 'pending')));

        if (in_array($status, ['confirmed', 'completed'], true)) {
            $status = 'paid';
        }

        $resolvedEmail = $this->firstNonEmptyValue([
            (string) ($data['customer_email'] ?? ''),
            (string) ($data['email'] ?? ''),
            (string) ($contactData['email'] ?? ''),
        ]);
        $displayAmount = isset($data['total_amount']) ? (float) $data['total_amount'] : 0.0;
        if ($displayAmount <= 0.0 && isset($data['amount'])) {
            $displayAmount = (float) $data['amount'];
        }

        $data['normalized_status'] = $status;
        $data['resolved_customer_email'] = $resolvedEmail;
        $data['display_amount'] = $displayAmount;

        return (object) $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMemberOrderContactData(string $contactData): array
    {
        if ($contactData === '') {
            return [];
        }

        $decoded = Json::decodeArray($contactData, []);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, string> $values
     */
    private function firstNonEmptyValue(array $values): string
    {
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Gemerkte Beiträge mit Titel und Permalink (MD-16).
     *
     * @return array<int,array<string,mixed>>
     */
    public function getFavorites(int $limit = 50): array
    {
        return MemberContentService::getInstance()->getFavoritePosts($this->getUserId(), $limit);
    }

    /**
     * POST auf /member/favorites: `favorite_remove` (Merkliste) und `favorite_toggle` (z. B. von Beitragsseiten,
     * mit lokalem `return_to`).
     */
    public function handleFavoritesRequest(): void
    {
        $action = (string)($_POST['action'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !in_array($action, ['favorite_remove', 'favorite_toggle'], true)) {
            return;
        }

        $returnTo = Security::safeLocalPath((string)($_POST['return_to'] ?? ''), '/member/favorites');
        if (!$this->verifyCsrf('favorites_action')) {
            $this->securityFailed($returnTo);
        }

        $postId = (int)($_POST['post_id'] ?? 0);
        $service = MemberContentService::getInstance();

        if ($action === 'favorite_remove') {
            $removed = $service->removeFavorite($this->getUserId(), $postId);
            $this->flash($removed ? 'success' : 'info', MemberI18n::t($removed ? 'member.favorites.removed' : 'member.favorites.not_found'));
            $this->redirect($returnTo);
        }

        $isFavorite = $service->toggleFavorite($this->getUserId(), $postId);
        $this->flash('success', MemberI18n::t($isFavorite ? 'member.favorites.added' : 'member.favorites.removed'));
        $this->redirect($returnTo);
    }

    public function getMemberMediaPath(?int $userId = null): string
    {
        $userId ??= $this->getUserId();
        return 'member/user-' . $userId;
    }

    private function normalizeMemberMediaPath(string $path, ?int $userId = null): string
    {
        $memberRoot = $this->getMemberMediaPath($userId);
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('#/+#', '/', $path) ?? '';
        $path = trim($path, '/');

        if ($path === '' || $path === $memberRoot) {
            return $memberRoot;
        }

        if (str_contains($path, '..') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return $memberRoot;
        }

        if (str_starts_with($path, $memberRoot . '/')) {
            return $path;
        }

        if (preg_match('#^[A-Za-z0-9._/-]+$#', $path) !== 1) {
            return $memberRoot;
        }

        return trim($memberRoot . '/' . ltrim($path, '/'), '/');
    }

    private function buildMemberMediaUrl(string $path = ''): string
    {
        $memberRoot = $this->getMemberMediaPath();
        $normalizedPath = $this->normalizeMemberMediaPath($path);

        if ($normalizedPath === $memberRoot) {
            return '/member/media';
        }

        return '/member/media?path=' . rawurlencode($normalizedPath);
    }

    /**
     * @return array<int,array{label:string,url:string,active:bool}>
     */
    private function buildMemberMediaBreadcrumbs(string $path): array
    {
        $memberRoot = $this->getMemberMediaPath();
        $normalizedPath = $this->normalizeMemberMediaPath($path);
        $breadcrumbs = [[
            'label' => MemberI18n::t('member.media.root'),
            'url' => $this->buildMemberMediaUrl($memberRoot),
            'active' => $normalizedPath === $memberRoot,
        ]];

        if ($normalizedPath === $memberRoot) {
            return $breadcrumbs;
        }

        $relativePath = ltrim(substr($normalizedPath, strlen($memberRoot)), '/');
        $segments = array_values(array_filter(explode('/', $relativePath), static fn (string $segment): bool => $segment !== ''));
        $currentPath = $memberRoot;

        foreach ($segments as $index => $segment) {
            $currentPath .= '/' . $segment;
            $isLast = $index === count($segments) - 1;
            $breadcrumbs[] = [
                'label' => $segment,
                'url' => $isLast ? '' : $this->buildMemberMediaUrl($currentPath),
                'active' => $isLast,
            ];
        }

        return $breadcrumbs;
    }

    private function ensureMemberMediaRootExists(): bool
    {
        $memberRoot = rtrim((string)UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . 'member';
        $userRoot = $memberRoot . DIRECTORY_SEPARATOR . 'user-' . $this->getUserId();

        if (is_dir($userRoot)) {
            return true;
        }

        if (!mkdir($userRoot, 0755, true) && !is_dir($userRoot)) {
            error_log('MemberController::ensureMemberMediaRootExists() failed for ' . $userRoot);
            return false;
        }

        return true;
    }

    private function sanitizeMemberMediaItemName(string $name): string
    {
        $name = trim(strip_tags($name));
        if ($name === '' || preg_match('#[\\\\/:*?"<>|\x00-\x1F]#', $name) === 1) {
            return '';
        }

        return mb_substr($name, 0, 120, 'UTF-8');
    }

    /**
     * @return array<string,mixed>
     */
    public function getMediaOverview(): array
    {
        $mediaService = MediaService::getInstance();
        $memberRoot = $this->getMemberMediaPath();
        $path = $this->normalizeMemberMediaPath((string)($_GET['path'] ?? ''), $this->getUserId());
        $items = $this->ensureMemberMediaRootExists() ? $mediaService->getItems($path) : ['folders' => [], 'files' => []];
        if (!is_array($items)) {
            $items = ['folders' => [], 'files' => []];
        }

        return [
            'path' => $path,
            'root_path' => $memberRoot,
            'breadcrumbs' => $this->buildMemberMediaBreadcrumbs($path),
            'items' => $items,
            'settings' => $mediaService->getSettings(),
            'move_targets' => $this->buildMemberMediaMoveTargets(),
        ];
    }

    /**
     * @return array<int, array{path:string,label:string,depth:int}>
     */
    private function buildMemberMediaMoveTargets(): array
    {
        $memberRoot = $this->getMemberMediaPath();
        $options = [[
            'path' => $memberRoot,
            'label' => MemberI18n::t('member.media.root'),
            'depth' => 0,
        ]];

        $visited = [$memberRoot => true];
        $this->appendMemberMediaMoveTargets($options, $memberRoot, 0, $visited);

        return $options;
    }

    /**
     * @param array<int, array{path:string,label:string,depth:int}> $options
     * @param array<string, bool> $visited
     */
    private function appendMemberMediaMoveTargets(array &$options, string $path, int $depth, array &$visited): void
    {
        if (count($visited) >= self::MEMBER_MEDIA_MOVE_TARGET_MAX_NODES) {
            return;
        }

        $items = MediaService::getInstance()->getItems($path);
        if (!is_array($items)) {
            return;
        }

        $folders = is_array($items['folders'] ?? null) ? $items['folders'] : [];
        usort($folders, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        foreach ($folders as $folder) {
            $folderPath = $this->normalizeMemberMediaPath((string) ($folder['path'] ?? ''), $this->getUserId());
            if ($folderPath === '' || isset($visited[$folderPath])) {
                continue;
            }

            $visited[$folderPath] = true;
            $options[] = [
                'path' => $folderPath,
                'label' => str_repeat('— ', min($depth + 1, 8)) . (string) ($folder['name'] ?? basename($folderPath)),
                'depth' => $depth + 1,
            ];

            $this->appendMemberMediaMoveTargets($options, $folderPath, $depth + 1, $visited);
        }
    }

    public function handleProfileRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        if ((string)($_POST['action'] ?? '') === 'email_change_cancel') {
            if (!$this->verifyCsrf('email_change_cancel')) {
                $this->securityFailed('/member/profile');
            }

            $cancelled = \CMS\Services\EmailChangeService::getInstance()->cancel($this->getUserId());
            $this->flash(
                $cancelled ? 'success' : 'info',
                MemberI18n::t($cancelled ? 'member.profile.email_change_cancelled' : 'member.profile.email_change_none')
            );
            $this->redirect('/member/profile');
        }

        if ((string)($_POST['action'] ?? '') !== 'profile_save') {
            return;
        }

        if (!$this->verifyCsrf('profile_save')) {
            $this->securityFailed('/member/profile');
        }

        $settings = $this->getSettings();
        $profileDefinitions = is_array($settings['profile_field_definitions'] ?? null)
            ? $settings['profile_field_definitions']
            : $this->getProfileFieldDefinitions();
        $requiredFields = array_values(array_unique(array_merge(
            ['username', 'email'],
            array_map('strval', (array)($settings['required_profile_fields'] ?? []))
        )));
        $currentUser = $this->getCurrentUser();
        $currentMeta = $this->memberService->getUserMeta($this->getUserId());
        $labelOf = static fn (string $field): string => (string)($profileDefinitions[$field]['label'] ?? $field);

        $data = [
            'display_name' => trim((string)($_POST['display_name'] ?? ($currentUser->display_name ?? ''))),
            'username' => trim((string)($_POST['username'] ?? ($currentUser->username ?? ''))),
            'first_name' => MemberSanitizer::text($_POST['first_name'] ?? ($currentMeta['first_name'] ?? ''), 100),
            'last_name' => MemberSanitizer::text($_POST['last_name'] ?? ($currentMeta['last_name'] ?? ''), 100),
            'email' => trim((string)($_POST['email'] ?? ($currentUser->email ?? ''))),
            'bio' => $this->sanitizeWysiwygProfileFieldValue((string)($_POST['bio'] ?? ($currentMeta['bio'] ?? ''))),
            'website' => MemberSanitizer::profileUrl((string)($_POST['website'] ?? ($currentMeta['website'] ?? ''))),
            'phone' => MemberSanitizer::phone($_POST['phone'] ?? ($currentMeta['phone'] ?? '')),
            'company' => MemberSanitizer::text($_POST['company'] ?? ($currentMeta['company'] ?? ''), self::PROFILE_TEXT_LIMITS['company']),
            'position' => MemberSanitizer::text($_POST['position'] ?? ($currentMeta['position'] ?? ''), self::PROFILE_TEXT_LIMITS['position']),
            'birth_date' => MemberSanitizer::birthDate($_POST['birth_date'] ?? ($currentMeta['birth_date'] ?? '')),
        ];
        $location = MemberSanitizer::text($_POST['location'] ?? ($currentMeta['location'] ?? ''), self::PROFILE_TEXT_LIMITS['location']);

        // Ungültige Eingaben werden nicht still verworfen, sondern gemeldet (MD-09).
        if (trim((string)($_POST['website'] ?? '')) !== '' && $data['website'] === '') {
            $this->flash('danger', MemberI18n::t('member.profile.invalid_website'));
            $this->redirect('/member/profile');
        }
        if (trim((string)($_POST['phone'] ?? '')) !== '' && $data['phone'] === '') {
            $this->flash('danger', MemberI18n::t('member.profile.invalid_phone'));
            $this->redirect('/member/profile');
        }
        if (trim((string)($_POST['birth_date'] ?? '')) !== '' && $data['birth_date'] === '') {
            $this->flash('danger', MemberI18n::t('member.profile.invalid_birth_date'));
            $this->redirect('/member/profile');
        }
        foreach (self::PROFILE_TEXT_LIMITS as $field => $limit) {
            if (mb_strlen(trim((string)($_POST[$field] ?? '')), 'UTF-8') > $limit) {
                $this->flash('danger', MemberI18n::t('member.profile.too_long', ['field' => $labelOf($field), 'max' => $limit]));
                $this->redirect('/member/profile');
            }
        }

        $social = MemberSanitizer::profileUrl((string)($_POST['social'] ?? ($currentMeta['social'] ?? '')));
        if (trim((string)($_POST['social'] ?? '')) !== '' && $social === '') {
            $this->flash('danger', MemberI18n::t('member.profile.invalid_social'));
            $this->redirect('/member/profile');
        }

        $avatar = $this->normalizeProfileMediaUrl((string)($_POST['avatar'] ?? ($currentMeta['avatar'] ?? '')));
        if (trim((string)($_POST['avatar'] ?? '')) !== '' && $avatar === '') {
            $this->flash('danger', MemberI18n::t('member.profile.invalid_avatar'));
            $this->redirect('/member/profile');
        }

        $customValues = [];
        foreach ($profileDefinitions as $fieldKey => $definition) {
            if (empty($definition['custom'])) {
                continue;
            }

            $customValues[(string)$fieldKey] = $this->sanitizeDynamicProfileFieldValue(
                (string)($_POST[(string)$fieldKey] ?? ($currentMeta[(string)$fieldKey] ?? '')),
                (string)($definition['type'] ?? 'text')
            );

            if (trim((string)($_POST[(string)$fieldKey] ?? '')) !== '' && (string)($definition['type'] ?? 'text') === 'url' && $customValues[(string)$fieldKey] === '') {
                $this->flash('danger', MemberI18n::t('member.profile.invalid_url_field', ['field' => (string)($definition['label'] ?? $fieldKey)]));
                $this->redirect('/member/profile');
            }
        }

        $submittedValues = array_merge($data, [
            'location' => $location,
            'social' => $social,
            'avatar' => $avatar,
        ], $customValues);
        foreach ($requiredFields as $requiredField) {
            if (trim((string)($submittedValues[$requiredField] ?? '')) === '') {
                $this->flash('danger', MemberI18n::t('member.profile.required_missing', ['field' => $labelOf($requiredField)]));
                $this->redirect('/member/profile');
            }
        }

        // Die E-Mail-Adresse ist der Kanal für Passwort-Resets: Änderung nur mit aktuellem Passwort.
        if (strcasecmp($data['email'], (string)($currentUser->email ?? '')) !== 0) {
            $reauth = $this->auth->verifyCurrentPassword($this->getUserId(), (string)($_POST['current_password'] ?? ''));
            if ($reauth !== true) {
                $this->flash('danger', MemberI18n::t('member.profile.email_not_changed', ['reason' => (string)$reauth]));
                $this->redirect('/member/profile');
            }
        }

        $emailNotice = '';
        $userId = $this->getUserId();
        $result = $this->memberService->updateProfile($userId, $data, $emailNotice);
        $this->memberService->setUserMeta($userId, 'location', $location);
        $this->memberService->setUserMeta($userId, 'social', $social);
        $this->memberService->setUserMeta($userId, 'avatar', $avatar);
        foreach ($customValues as $fieldKey => $fieldValue) {
            $this->memberService->setUserMeta($userId, $fieldKey, $fieldValue);
        }

        if ($result === true) {
            $this->flash('success', trim(MemberI18n::t('member.profile.saved') . ' ' . (string)$emailNotice));
        } else {
            $this->flash('danger', (string)$result);
        }

        $this->redirect('/member/profile');
    }

    /**
     * Sanitiert den Block-Editor-Wert für Felder vom Typ „wysiwyg“ (z. B. Biografie).
     * Speichert einen leeren String, wenn der Editor keine Blöcke enthält, damit
     * Pflichtfeld-/Profilvollständigkeits-Prüfungen weiterhin korrekt greifen.
     */
    private function sanitizeWysiwygProfileFieldValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $sanitized = \CMS\Services\EditorService::getInstance()->sanitize($value);
        if (trim($sanitized) === '') {
            return '';
        }

        if (\CMS\Services\EditorService::isEditorJs()) {
            $decoded = Json::decodeArray($sanitized, []);
            if (empty($decoded['blocks'])) {
                return '';
            }
        }

        return $sanitized;
    }

    private function sanitizeDynamicProfileFieldValue(string $value, string $type): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return match ($type) {
            'textarea' => MemberSanitizer::multiline($value, 2000),
            'url' => MemberSanitizer::profileUrl($value),
            'date' => MemberSanitizer::date($value),
            default => MemberSanitizer::text($value, 255),
        };
    }

    /**
     * Ist der Core-Newsletter-Bereich registriert, ersetzt dessen Widget den Schalter `email_marketing` (NL-09).
     */
    public function getNewsletterSectionPath(): ?string
    {
        $section = $this->registry->getSection('newsletter');
        if (!is_array($section)) {
            return null;
        }

        return !empty($this->getSettings()['dashboard_enabled']) ? '/member/dashboard#newsletter' : '/member/plugin/newsletter';
    }

    public function handleNotificationsRequest(): void
    {
        $action = (string)($_POST['action'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
            || !in_array($action, ['notifications_save', 'notification_mark_read', 'notifications_mark_all_read'], true)) {
            return;
        }

        if ($action !== 'notifications_save') {
            if (!$this->verifyCsrf('notifications_read')) {
                $this->securityFailed('/member/notifications');
            }

            $service = NotificationService::getInstance();
            if ($action === 'notification_mark_read') {
                $marked = $service->markRead($this->getUserId(), (int)($_POST['notification_id'] ?? 0));
                $this->flash($marked ? 'success' : 'info', MemberI18n::t($marked ? 'member.notifications.marked_read' : 'member.notifications.already_read'));
            } else {
                $count = $service->markAllRead($this->getUserId());
                $this->flash($count > 0 ? 'success' : 'info', $count > 0
                    ? MemberI18n::t('member.notifications.marked_all_read', ['count' => $count])
                    : MemberI18n::t('member.notifications.none_unread'));
            }
            $this->redirect('/member/notifications');
        }

        if (!$this->verifyCsrf('notifications_save')) {
            $this->securityFailed('/member/notifications');
        }

        $current = $this->memberService->getNotificationPreferences($this->getUserId());
        $preferences = [];
        foreach (array_keys(MemberService::NOTIFICATION_PREFERENCE_DEFAULTS) as $key) {
            $preferences[$key] = !empty($_POST[$key]);
        }
        // Wird der Newsletter über sein Widget verwaltet, fehlt der Schalter im Formular: Wert beibehalten.
        if ($this->getNewsletterSectionPath() !== null) {
            $preferences['email_marketing'] = !empty($current['email_marketing']);
        }
        $preferences['notification_frequency'] = (string)($_POST['notification_frequency'] ?? 'immediate');

        $this->memberService->updateNotificationPreferences($this->getUserId(), $preferences);
        $this->flash('success', MemberI18n::t('member.notifications.saved'));
        $this->redirect('/member/notifications');
    }

    public function handlePrivacyRequest(): void
    {
        $action = (string)($_POST['action'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !in_array($action, ['privacy_save', 'privacy_export', 'privacy_delete_request'], true)) {
            return;
        }

        if (!$this->verifyCsrf('privacy_action')) {
            $this->securityFailed('/member/privacy');
        }

        if ($action === 'privacy_save') {
            $settings = [
                'profile_visibility' => (string)($_POST['profile_visibility'] ?? 'members'),
                'show_email' => !empty($_POST['show_email']),
                'show_activity' => !empty($_POST['show_activity']),
                'public_profile_fields' => array_values(array_filter(array_map('strval', (array)($_POST['public_profile_fields'] ?? [])))),
            ];
            $this->memberService->updatePrivacySettings($this->getUserId(), $settings);
            $this->flash('success', MemberI18n::t('member.privacy.saved'));
            $this->redirect('/member/privacy');
        }

        if ($action === 'privacy_delete_request') {
            // Wie bei „Überall abmelden“: Eine übernommene Sitzung soll das Konto nicht zur Löschung vormerken (MD-10).
            $reauth = $this->auth->verifyCurrentPassword($this->getUserId(), (string)($_POST['current_password'] ?? ''));
            if ($reauth !== true) {
                $this->flash('danger', (string)$reauth);
                $this->redirect('/member/privacy');
            }

            $this->memberService->requestAccountDeletion($this->getUserId());
            $this->flash('warning', MemberI18n::t('member.privacy.delete_requested'));
            $this->redirect('/member/privacy');
        }

        $export = $this->memberService->exportUserData($this->getUserId());
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="member-export-' . $this->getUserId() . '.json"');
        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function handleMessagesRequest(): void
    {
        $action = (string)($_POST['action'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !in_array($action, ['message_send', 'message_delete'], true)) {
            return;
        }

        if (!$this->verifyCsrf('messages_action')) {
            $this->securityFailed('/member/messages');
        }

        if ($action === 'message_delete') {
            $messageId = (int)($_POST['message_id'] ?? 0);
            $deleted = $this->messageService->delete($messageId, $this->getUserId());
            $this->flash($deleted ? 'success' : 'danger', MemberI18n::t($deleted ? 'member.messages.deleted' : 'member.messages.delete_failed'));
            $this->redirect('/member/messages');
        }

        $recipientInput = trim((string)($_POST['recipient'] ?? ''));
        $recipientId = ctype_digit($recipientInput) ? (int)$recipientInput : $this->memberService->resolveMessageRecipientId($recipientInput);
        $subject = MemberSanitizer::text($_POST['subject'] ?? '', 255);
        $body = trim((string)($_POST['body'] ?? ''));
        $parentId = (int)($_POST['parent_id'] ?? 0);

        if ($this->messageService->isSendRateLimited($this->getUserId())) {
            $this->flash('danger', MemberI18n::t('member.messages.rate_limited'));
            $this->redirect('/member/messages');
        }

        $result = $recipientId > 0
            ? $this->messageService->send($this->getUserId(), $recipientId, $subject, $body, $parentId > 0 ? $parentId : null)
            : false;

        if ($result !== false) {
            $this->flash('success', MemberI18n::t('member.messages.sent'));
        } else {
            $this->flash('danger', MemberI18n::t('member.messages.send_failed'));
        }

        $this->redirect($parentId > 0 ? '/member/messages?thread=' . $parentId : '/member/messages');
    }

    public function handleMediaRequest(): void
    {
        $action = (string)($_POST['action'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !in_array($action, ['media_delete', 'media_folder_create', 'media_folder_delete', 'media_rename', 'media_move'], true)) {
            return;
        }

        $mediaService = MediaService::getInstance();
        $memberRoot = $this->getMemberMediaPath();
        $currentPath = $this->normalizeMemberMediaPath((string)($_POST['current_path'] ?? ''), $this->getUserId());

        if (!$this->verifyCsrf('media_action')) {
            $this->securityFailed($this->buildMemberMediaUrl($currentPath));
        }

        $settings = $mediaService->getSettings();

        if (!$this->ensureMemberMediaRootExists()) {
            $this->flash('danger', MemberI18n::t('member.media.root_failed'));
            $this->redirect('/member/media');
        }

        if ($action === 'media_folder_create') {
            $folderName = trim((string)($_POST['folder_name'] ?? ''));
            $result = $mediaService->createFolder($folderName, $currentPath);
            $ok = !($result instanceof \CMS\WP_Error);
            $this->flash($ok ? 'success' : 'danger', $ok ? MemberI18n::t('member.media.folder_created') : $result->get_error_message());
            $this->redirect($this->buildMemberMediaUrl($currentPath));
        }

        $path = $this->normalizeMemberMediaPath((string)($_POST['path'] ?? ''), $this->getUserId());
        if ($path === $memberRoot || (!str_starts_with($path, $memberRoot . '/') && $path !== $memberRoot)) {
            $this->flash('danger', MemberI18n::t('member.media.invalid_path'));
            $this->redirect($this->buildMemberMediaUrl($currentPath));
        }

        if ($action === 'media_rename') {
            $newName = $this->sanitizeMemberMediaItemName((string)($_POST['new_name'] ?? ''));
            if ($newName === '') {
                $this->flash('danger', MemberI18n::t('member.media.invalid_name'));
                $this->redirect($this->buildMemberMediaUrl($currentPath));
            }

            $result = $mediaService->renameItem($path, $newName);
            $ok = !($result instanceof \CMS\WP_Error);
            $this->flash($ok ? 'success' : 'danger', $ok ? MemberI18n::t('member.media.renamed') : $result->get_error_message());
            $this->redirect($this->buildMemberMediaUrl($currentPath));
        }

        if ($action === 'media_move') {
            $targetParentPath = $this->normalizeMemberMediaPath((string)($_POST['target_parent_path'] ?? ''), $this->getUserId());
            if ($targetParentPath === $path || str_starts_with($targetParentPath, $path . '/')) {
                $this->flash('danger', MemberI18n::t('member.media.move_into_self'));
                $this->redirect($this->buildMemberMediaUrl($currentPath));
            }

            $targetPath = trim(($targetParentPath !== '' ? $targetParentPath . '/' : '') . basename($path), '/');
            if ($targetPath === $path) {
                $this->flash('info', MemberI18n::t('member.media.already_there'));
                $this->redirect($this->buildMemberMediaUrl($currentPath));
            }

            $result = $mediaService->moveFile($path, $targetPath);
            $ok = !($result instanceof \CMS\WP_Error);
            $this->flash($ok ? 'success' : 'danger', $ok ? MemberI18n::t('member.media.moved') : $result->get_error_message());
            $this->redirect($this->buildMemberMediaUrl($currentPath));
        }

        if (empty($settings['member_delete_own'])) {
            $this->flash('warning', MemberI18n::t('member.media.delete_disabled'));
            $this->redirect($this->buildMemberMediaUrl($currentPath));
        }

        $result = $mediaService->deleteItem($path);
        $ok = !($result instanceof \CMS\WP_Error);
        $successMessage = MemberI18n::t($action === 'media_folder_delete' ? 'member.media.folder_deleted' : 'member.media.file_deleted');
        $this->flash($ok ? 'success' : 'danger', $ok ? $successMessage : $result->get_error_message());
        $this->redirect($this->buildMemberMediaUrl($currentPath));
    }

    public function handleSecurityRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $action = (string)($_POST['action'] ?? '');
        if (!in_array($action, ['password_change', 'totp_start', 'totp_confirm', 'totp_disable', 'backup_generate', 'passkey_register', 'passkey_delete', 'sessions_revoke_all', 'sso_link', 'sso_unlink'], true)) {
            return;
        }

        $csrfAction = match ($action) {
            'password_change' => 'security_password',
            'passkey_register', 'passkey_delete' => 'security_passkey',
            'sessions_revoke_all' => 'security_sessions',
            'sso_link', 'sso_unlink' => 'security_sso',
            default => 'security_mfa',
        };

        if (!$this->verifyCsrf($csrfAction)) {
            $this->securityFailed('/member/security');
        }

        $userId = $this->getUserId();

        if ($action === 'password_change') {
            $result = $this->memberService->changePassword(
                $userId,
                (string)($_POST['current_password'] ?? ''),
                (string)($_POST['new_password'] ?? ''),
                (string)($_POST['confirm_password'] ?? '')
            );

            if ($result === true) {
                $this->memberService->setUserMeta($userId, 'password_changed_at', date('Y-m-d H:i:s'));
                $this->flash('success', MemberI18n::t('member.security.password_changed'));
            } else {
                $this->flash('danger', (string)$result);
            }

            $this->redirect('/member/security');
        }

        // Sicherheitskritische Änderungen erfordern das aktuelle Passwort: Eine übernommene
        // Sitzung soll MFA nicht abschalten, eigene Passkeys/Backup-Codes anlegen oder Passkeys
        // des Kontoinhabers entfernen können (MD-11). `totp_confirm` ist an das per Passwort
        // gestartete Setup dieser Sitzung gebunden.
        // 4.1.0 (1c): Einen Faktor *hinzufügen* darf eine frische Microsoft-Anmeldung (≤ 10 min) statt des Passworts
        // bestätigen (SSO-Konten ohne bekanntes Passwort); Entfernen, Verknüpfen und Trennen verlangen immer das Passwort.
        if (in_array($action, ['totp_start', 'totp_disable', 'backup_generate', 'passkey_register', 'passkey_delete', 'sessions_revoke_all', 'sso_link', 'sso_unlink'], true)) {
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $reauth = $currentPassword === '' && in_array($action, ['totp_start', 'passkey_register'], true) && EntraSsoService::isFreshSsoSession($userId)
                ? true
                : $this->auth->verifyCurrentPassword($userId, $currentPassword);
            if ($reauth !== true) {
                $this->flash('danger', (string)$reauth);
                $this->redirect('/member/security');
            }
        }

        if ($action === 'sso_link') {
            $sso = EntraSsoService::getInstance();
            if (!$sso->isAvailable()) {
                $this->flash('danger', EntraSsoService::MESSAGES['unavailable']);
                $this->redirect('/member/security');
            }
            if ($sso->isLinked($userId)) {
                $this->flash('info', MemberI18n::t('member.security.sso_already_linked'));
                $this->redirect('/member/security');
            }
            SsoRouter::redirectToProvider($sso->startUrl(OidcFlowState::PURPOSE_LINK, '/member/security', $userId));
        }

        if ($action === 'sso_unlink') {
            $unlinked = EntraSsoService::getInstance()->unlink($userId);
            $this->flash($unlinked ? 'success' : 'danger', MemberI18n::t($unlinked ? 'member.security.sso_unlinked' : 'member.security.sso_unlink_failed'));
            $this->redirect('/member/security');
        }

        if ($action === 'sessions_revoke_all') {
            $revoked = $this->auth->revokeAllSessions($userId, 'member');
            $this->flash(
                $revoked ? 'success' : 'danger',
                MemberI18n::t($revoked ? 'member.security.sessions_revoked' : 'member.security.sessions_revoke_failed')
            );
            $this->redirect('/member/security');
        }

        // Ein aktiver Authenticator wird nie still ersetzt – Wechsel nur über „MFA deaktivieren“ (mit Passwort).
        if (in_array($action, ['totp_start', 'totp_confirm'], true) && TotpAdapter::instance()->isEnabled($userId)) {
            unset($_SESSION['member_totp_setup']);
            $this->flash('warning', MemberI18n::t('member.security.totp_already_active'));
            $this->redirect('/member/security');
        }

        if ($action === 'totp_start') {
            $setup = TotpAdapter::instance()->startSetup($userId, $this->getDisplayName());
            $_SESSION['member_totp_setup'] = $setup;
            $this->flash('info', MemberI18n::t('member.security.totp_prepared'), $setup);
            $this->redirect('/member/security');
        }

        if ($action === 'totp_confirm') {
            $code = trim((string)($_POST['totp_code'] ?? ''));
            // Nur das in dieser Sitzung per Passwort gestartete Setup darf bestätigt werden.
            $sessionSecret = (string)($_SESSION['member_totp_setup']['secret'] ?? '');
            $confirmed = $sessionSecret !== '' && TotpAdapter::instance()->confirmSetup($userId, $code, $sessionSecret);
            if ($confirmed) {
                unset($_SESSION['member_totp_setup']);
                $codes = BackupCodesManager::instance()->generate($userId);
                $this->flash('success', MemberI18n::t('member.security.totp_enabled'), ['backup_codes' => $codes]);
            } else {
                $this->flash('danger', MemberI18n::t('member.security.totp_invalid'));
            }
            $this->redirect('/member/security');
        }

        // 4.1.0: Bei MFA-Zwang darf der letzte anerkannte Faktor nicht entfernt werden.
        if (in_array($action, ['totp_disable', 'passkey_delete'], true)) {
            $currentUser = $this->auth->currentUser();
            $factor = $action === 'totp_disable' ? 'totp' : 'passkey';
            if ($currentUser !== null
                && !MfaEnforcementService::getInstance()->canRemoveFactor($currentUser, $factor, (int) ($_POST['credential_id'] ?? 0))) {
                $this->flash('danger', MemberI18n::t('member.security.mfa_enforced_last_factor'));
                $this->redirect('/member/security');
            }
        }

        if ($action === 'totp_disable') {
            // Entfernt auch die Backup-Codes und schreibt den Audit-Eintrag.
            TotpAdapter::instance()->disable($userId);
            unset($_SESSION['member_totp_setup']);
            $this->flash('warning', MemberI18n::t('member.security.totp_disabled'));
            $this->redirect('/member/security');
        }

        if ($action === 'backup_generate') {
            $codes = BackupCodesManager::instance()->regenerate($userId);
            $this->flash('success', MemberI18n::t('member.security.backup_generated'), ['backup_codes' => $codes]);
            $this->redirect('/member/security');
        }

        if ($action === 'passkey_delete') {
            $credentialId = (int)($_POST['credential_id'] ?? 0);
            $deleted = WebAuthnAdapter::instance()->deleteCredential($credentialId, $userId);
            $this->flash($deleted ? 'success' : 'danger', MemberI18n::t($deleted ? 'member.security.passkey_removed' : 'member.security.passkey_remove_failed'));
            $this->redirect('/member/security');
        }

        $challenge = (string)($_SESSION['member_passkey_challenge'] ?? '');
        if ($challenge === '') {
            $this->flash('danger', MemberI18n::t('member.security.passkey_challenge_expired'));
            $this->redirect('/member/security');
        }

        $clientData = $this->base64UrlDecode((string)($_POST['client_data_json'] ?? ''));
        $attestation = $this->base64UrlDecode((string)($_POST['attestation_object'] ?? ''));
        $defaultName = MemberI18n::t('member.security.passkey_default_name');
        $credentialName = MemberSanitizer::text($_POST['credential_name'] ?? $defaultName, 100, $defaultName);

        $result = WebAuthnAdapter::instance()->processRegistration($userId, $clientData, $attestation, $challenge, $credentialName);
        unset($_SESSION['member_passkey_challenge']);

        if ($result === true) {
            $this->flash('success', MemberI18n::t('member.security.passkey_registered'));
        } else {
            $this->flash('danger', (string)$result);
        }

        $this->redirect('/member/security');
    }

    /**
     * @return array<string,mixed>
     */
    private function isMfaSetupPending(int $userId): bool
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            return false;
        }
        $service = MfaEnforcementService::getInstance();

        return $service->isRequiredFor($user) && !$service->hasRecognisedFactor($userId);
    }

    public function getSecurityPageData(): array
    {
        $userId = $this->getUserId();
        $setup = $_SESSION['member_totp_setup'] ?? null;
        $credentials = WebAuthnAdapter::instance()->getCredentialsForUser($userId);
        $backupCount = BackupCodesManager::instance()->getRemainingCount($userId);
        $security = $this->memberService->getSecurityData($userId);
        $sessions = $this->memberService->getActiveSessions($userId);
        $passkeyPayload = $this->buildPasskeyRegistrationPayload();

        return [
            'security' => $security,
            'sessions' => $sessions,
            'credentials' => $credentials,
            'backup_count' => $backupCount,
            'totp_enabled' => TotpAdapter::instance()->isEnabled($userId),
            // 4.1.0: MFA-Zwang für die Rolle, aber noch kein anerkannter Faktor → Hinweis oben auf der Seite.
            'mfa_enforced_pending' => $this->isMfaSetupPending($userId),
            'totp_setup' => is_array($setup) ? $setup : null,
            'passkey_payload' => $passkeyPayload,
            // 4.1.0 (1c): Entra-ID-Verknüpfung
            'sso' => $this->buildSsoSecurityData($userId),
        ];
    }

    /**
     * @return array{available:bool, identity:?array<string,mixed>, fresh:bool}
     */
    private function buildSsoSecurityData(int $userId): array
    {
        try {
            $sso = EntraSsoService::getInstance();

            return [
                'available' => $sso->isAvailable(),
                'identity' => $sso->identityForUser($userId),
                'fresh' => EntraSsoService::isFreshSsoSession($userId),
            ];
        } catch (\Throwable) {
            return ['available' => false, 'identity' => null, 'fresh' => false];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function buildPasskeyRegistrationPayload(): array
    {
        if (!$this->authManager->isPasskeyAvailable()) {
            return ['available' => false, 'options_json' => '{}'];
        }

        $options = WebAuthnAdapter::instance()->getRegistrationOptions(
            $this->getUserId(),
            (string)($this->getCurrentUser()->username ?? ('user-' . $this->getUserId())),
            $this->getDisplayName()
        );
        $_SESSION['member_passkey_challenge'] = (string)($options['challenge'] ?? '');

        return [
            'available' => true,
            'options_json' => json_encode(
                $options['options'] ?? new \stdClass(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ) ?: '{}',
        ];
    }

    /**
     * @return array<int,object>
     */
    public function getInbox(): array
    {
        return $this->messageService->getInbox($this->getUserId(), 30, 0);
    }

    /**
     * @return array<int,object>
     */
    public function getSent(): array
    {
        return $this->messageService->getSent($this->getUserId(), 30, 0);
    }

    /**
     * @return array<int,object>
     */
    public function getThread(int $rootId): array
    {
        return $rootId > 0 ? $this->messageService->getThread($rootId, $this->getUserId()) : [];
    }

    private function normalizeProfileMediaUrl(string $value): string
    {
        $url = trim($value);
        if ($url === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $url) === 1) {
            return '';
        }

        $siteBase = rtrim((string) SITE_URL, '/');
        $normalizedUrl = str_replace('\\', '/', $url);

        if (str_starts_with($normalizedUrl, '//')) {
            return '';
        }

        if (preg_match('#^https?://#i', $normalizedUrl) === 1) {
            return filter_var($normalizedUrl, FILTER_VALIDATE_URL) ? $normalizedUrl : '';
        }

        if (str_starts_with($normalizedUrl, '/')) {
            return $siteBase !== '' ? $siteBase . $normalizedUrl : $normalizedUrl;
        }

        $relativePath = preg_replace('#^(?:\./)+#', '', $normalizedUrl) ?? '';
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return '';
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $relativePath) === 1) {
            return '';
        }

        return $siteBase !== '' ? $siteBase . '/' . $relativePath : '/' . $relativePath;
    }

    /**
     * @param array<int, object> $notifications
     * @param array<int, mixed> $allowedTypes
     * @return array<int, object>
     */
    private function filterNotificationFeed(array $notifications, array $allowedTypes): array
    {
        $normalizedTypes = array_values(array_unique(array_filter(array_map([$this, 'normalizeNotificationType'], $allowedTypes))));
        if ($normalizedTypes === []) {
            return [];
        }

        $allowedLookup = array_fill_keys($normalizedTypes, true);

        return array_values(array_filter($notifications, function (object $notification) use ($allowedLookup): bool {
            $type = $this->normalizeNotificationType($notification->type ?? 'system');
            return isset($allowedLookup[$type]);
        }));
    }

    /**
     * Bildet Schema-Typen (`system|message|comment|event`) auf die Filtergruppen des Admin-Bereichs ab.
     */
    public function normalizeNotificationType(mixed $type): string
    {
        $normalizedType = strtolower(trim((string) $type));

        return match ($normalizedType) {
            'message', 'messages' => 'messages',
            'billing' => 'billing',
            'security' => 'security',
            'community', 'comment', 'event' => 'community',
            default => 'system',
        };
    }

    private function base64UrlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return (string)base64_decode($value, true);
    }
}
