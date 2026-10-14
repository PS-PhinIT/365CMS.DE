<?php
declare(strict_types=1);

namespace CMS\MemberArea;

use CMS\Auth;
use CMS\Database;
use CMS\Hooks;
use CMS\Json;
use CMS\Security;
use CMS\Auth\AuthManager;
use CMS\Auth\MFA\BackupCodesManager;
use CMS\Auth\MFA\TotpAdapter;
use CMS\Auth\Passkey\WebAuthnAdapter;
use CMS\Member\PluginDashboardRegistry;
use CMS\Services\CoreModuleService;
use CMS\Services\MediaService;
use CMS\Services\MemberService;
use CMS\Services\MessageService;

if (!defined('ABSPATH')) {
    exit;
}

final class MemberController
{
    private const MEMBER_MEDIA_MOVE_TARGET_MAX_NODES = 500;
    private const DEFAULT_PROFILE_FIELDS = ['first_name', 'last_name', 'username', 'birth_date', 'email', 'website', 'social'];
    private const REQUIRED_PROFILE_FIELDS = ['username', 'email'];

    private static ?self $instance = null;

    private Auth $auth;
    private Database $db;
    private string $prefix;
    private MemberService $memberService;
    private MessageService $messageService;
    private AuthManager $authManager;
    private PluginDashboardRegistry $registry;

    /** @var array<string,mixed>|null */
    private ?array $settings = null;

    /** @var object|null */
    private ?object $currentUser = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->auth = Auth::instance();
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->memberService = MemberService::getInstance();
        $this->messageService = MessageService::getInstance();
        $this->authManager = AuthManager::instance();
        $this->registry = PluginDashboardRegistry::instance();
        $this->registry->init();
    }

    public function requireAuth(): void
    {
        if (!$this->auth->isLoggedIn()) {
            $redirect = urlencode((string)($_SERVER['REQUEST_URI'] ?? '/member/dashboard'));
            header('Location: /login?redirect=' . $redirect);
            exit;
        }

        $this->currentUser = $this->auth->getCurrentUser();
    }

    public function getCurrentUser(): object
    {
        $this->requireAuth();
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
            'widgets' => ['profile', 'activity', 'notifications', 'quick_links'],
            'profile_fields' => self::DEFAULT_PROFILE_FIELDS,
            'required_profile_fields' => self::REQUIRED_PROFILE_FIELDS,
            'custom_profile_fields' => [],
            'profile_field_definitions' => $this->getProfileFieldDefinitions(),
            'dashboard_columns' => 3,
            'section_order' => 'quick_start,stats,widgets,plugins',
            'dashboard_logo' => '',
            'dashboard_greeting' => 'Willkommen zurück, {name}!',
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
                'empty_text' => 'Aktuell gibt es keine neuen Meldungen.',
                'types' => ['system', 'messages'],
            ],
            'onboarding' => [
                'enabled' => true,
                'title' => 'Dein Start im Member-Bereich',
                'intro' => 'Konfiguriere dein Profil, sichere deinen Zugang und entdecke die wichtigsten Bereiche.',
                'steps' => ['Profil vervollständigen', 'Sicherheit aktivieren', 'Dateien hochladen'],
                'cta_label' => 'Profil öffnen',
                'cta_url' => '/member/profile',
                'require_profile_completion' => false,
            ],
            'plugin_widget_order' => [],
        ];

        $this->settings = $this->normalizeProfileSettings($this->settings);

        return $this->settings;
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
            'first_name' => ['label' => 'Vorname', 'type' => 'text'],
            'last_name' => ['label' => 'Nachname', 'type' => 'text'],
            'username' => ['label' => 'Benutzername', 'type' => 'text', 'required' => true, 'locked' => true],
            'birth_date' => ['label' => 'Geburtsdatum', 'type' => 'date'],
            'email' => ['label' => 'Mailadresse', 'type' => 'email', 'required' => true, 'locked' => true],
            'website' => ['label' => 'Website', 'type' => 'url'],
            'social' => ['label' => 'SocialMedia', 'type' => 'url'],
            'bio' => ['label' => 'Biografie', 'type' => 'wysiwyg'],
            'phone' => ['label' => 'Telefon', 'type' => 'text'],
            'company' => ['label' => 'Firma', 'type' => 'text'],
            'position' => ['label' => 'Position', 'type' => 'text'],
            'location' => ['label' => 'Standort', 'type' => 'text'],
            'avatar' => ['label' => 'Profilbild', 'type' => 'url'],
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

    public function csrfToken(string $action): string
    {
        return Security::instance()->generateToken('member_' . $action);
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

    public function redirect(string $path): void
    {
        if (!preg_match('#^https?://#i', $path)) {
            $path = '/' . ltrim($path, '/');
        }

        header('Location: ' . $path);
        exit;
    }

    /**
     * @return array<string,mixed>
     */
    public function getNotificationCenterConfig(): array
    {
        $settings = $this->getSettings();
        $notifications = is_array($settings['notifications'] ?? null) ? $settings['notifications'] : [];
        $types = array_values(array_filter(array_map([$this, 'normalizeNotificationType'], (array)($notifications['types'] ?? []))));

        return [
            'center_enabled' => !array_key_exists('center_enabled', $notifications) || !empty($notifications['center_enabled']),
            'empty_text' => trim((string)($notifications['empty_text'] ?? 'Aktuell gibt es keine neuen Meldungen.')),
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
        $notifications = $this->memberService->getRecentNotifications($this->getUserId(), $fetchLimit);

        return array_slice($this->filterNotificationFeed($notifications, (array)($config['types'] ?? [])), 0, $limit);
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

        return (string)($user->username ?? $user->email ?? 'Mitglied');
    }

    public function getInitials(): string
    {
        $name = $this->getDisplayName();
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
        }

        return strtoupper($initials !== '' ? $initials : 'M');
    }

    public function getAvatarUrl(): string
    {
        $meta = $this->memberService->getUserMeta($this->getUserId());
        $avatar = $this->normalizeProfileMediaUrl((string)($meta['avatar'] ?? ''));

        if ($avatar !== '') {
            return $avatar;
        }

        return '';
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
     * @return array<int,array<string,mixed>>
     */
    public function getMenuItems(string $activePage): array
    {
        $settings = $this->getSettings();
        $items = [
            ['slug' => 'profile', 'label' => 'Profil', 'icon' => '👤', 'url' => '/member/profile', 'category' => 'core'],
            ['slug' => 'security', 'label' => 'Sicherheit', 'icon' => '🛡️', 'url' => '/member/security', 'category' => 'core'],
            ['slug' => 'notifications', 'label' => 'Benachrichtigungen', 'icon' => '🔔', 'url' => '/member/notifications', 'category' => 'core'],
            ['slug' => 'messages', 'label' => 'Nachrichten', 'icon' => '✉️', 'url' => '/member/messages', 'category' => 'core'],
            ['slug' => 'media', 'label' => 'Dateien', 'icon' => '🗂️', 'url' => '/member/media', 'category' => 'content'],
            ['slug' => 'favorites', 'label' => 'Favoriten', 'icon' => '⭐', 'url' => '/member/favorites', 'category' => 'content'],
            ['slug' => 'privacy', 'label' => 'Datenschutz', 'icon' => '🔐', 'url' => '/member/privacy', 'category' => 'account'],
        ];

        if (!empty($settings['dashboard_enabled'])) {
            array_unshift($items, ['slug' => 'dashboard', 'label' => 'Dashboard', 'icon' => '🏠', 'url' => '/member/dashboard', 'category' => 'core']);
        }

        if (!empty($settings['subscription_visible']) && CoreModuleService::getInstance()->isModuleEnabled('subscription_member_area')) {
            $items[] = ['slug' => 'subscription', 'label' => 'Abo & Bestellungen', 'icon' => '💳', 'url' => '/member/subscription', 'category' => 'account'];
        }

        if ($this->canAccessAdminPortal()) {
            $items[] = ['slug' => 'admin', 'label' => 'Adminmenü', 'icon' => '⚙️', 'url' => $this->getAdminPortalUrl(), 'category' => 'admin'];
        }

        $items = Hooks::applyFilters('member_menu_items', $items);

        foreach ($items as &$item) {
            $item['active'] = ($item['slug'] ?? '') === $activePage;
        }
        unset($item);

        usort($items, static function (array $a, array $b): int {
            return strcmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
        });

        $coreOrder = ['dashboard', 'profile', 'security', 'notifications', 'messages', 'media', 'favorites', 'privacy', 'subscription', 'admin'];
        usort($items, static function (array $a, array $b) use ($coreOrder): int {
            $aIndex = array_search((string)($a['slug'] ?? ''), $coreOrder, true);
            $bIndex = array_search((string)($b['slug'] ?? ''), $coreOrder, true);
            $aIndex = $aIndex === false ? 999 : $aIndex;
            $bIndex = $bIndex === false ? 999 : $bIndex;
            return $aIndex <=> $bIndex;
        });

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    public function getDashboardData(): array
    {
        $userId = $this->getUserId();
        $settings = $this->getSettings();
        $data = $this->memberService->getMemberDashboardData($userId);
        $data['profile_completion'] = $this->getProfileCompletion();
        $data['recent_activity'] = $this->getRecentActivity();
        $data['unread_messages'] = $this->messageService->getUnreadCount($userId);
        $data['favorites'] = $this->getFavorites();
        $data['plugin_widgets'] = $this->getPluginWidgets();
        $data['custom_widgets'] = $this->getCustomWidgets();
        $data['recent_notifications'] = $this->getNotificationCenterNotifications(5);
        $data['notification_center'] = $this->getNotificationCenterConfig();
        $data['settings'] = $settings;
        return $data;
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
        $completed = 0;
        $missing = [];
        $total = max(1, count($selectedFields));

        foreach ($selectedFields as $field) {
            $value = match ($field) {
                'username' => $user->username ?? '',
                'email' => $user->email ?? '',
                'avatar' => $meta['avatar'] ?? '',
                'social' => $meta['social'] ?? '',
                default => $meta[$field] ?? '',
            };

            if (trim((string)$value) !== '') {
                $completed++;
            } else {
                $missing[] = (string)$field;
            }
        }

        return [
            'completed' => $completed,
            'total' => $total,
            'percentage' => (int)round(($completed / $total) * 100),
            'missing' => $missing,
        ];
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

            $normalizedWidgets[] = $this->sanitizeDashboardWidget($widget);
        }

        return $normalizedWidgets;
    }

    /**
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
            if (trim((string)($widget['title'] ?? '')) === '' && trim((string)($widget['content'] ?? '')) === '') {
                continue;
            }
            $widgets[] = [
                'title' => $this->sanitizeDashboardText((string)($widget['title'] ?? ''), 120),
                'content' => $this->sanitizeDashboardText((string)($widget['content'] ?? ''), 2000),
                'icon' => $this->sanitizeDashboardText((string)($widget['icon'] ?? '✨'), 16, '✨'),
            ];
        }

        return $widgets;
    }

    /**
     * @return array<int,object>
     */
    public function getRecentActivity(int $limit = 8): array
    {
        try {
            return $this->db->get_results(
                "SELECT action, description, created_at FROM {$this->prefix}activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT ?",
                [$this->getUserId(), $limit]
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int,object>
     */
    public function getOrders(): array
    {
        if (!CoreModuleService::getInstance()->isModuleEnabled('subscriptions')) {
            return [];
        }

        try {
            $email = trim((string) ($this->getCurrentUser()->email ?? ''));
            $emailLike = $email !== '' ? '%"email":"' . $this->escapeLikeValue($email) . '"%' : '';

            $rows = $this->db->get_results(
                "SELECT o.*, p.name AS plan_name
                 FROM {$this->prefix}orders o
                 LEFT JOIN {$this->prefix}subscription_plans p ON p.id = o.plan_id
                 WHERE o.user_id = ?
                    OR (
                        o.user_id IS NULL
                        AND (
                            o.customer_email = ?
                            OR o.email = ?
                            OR o.contact_data LIKE ?
                        )
                    )
                 ORDER BY o.created_at DESC
                 LIMIT 25",
                [$this->getUserId(), $email, $email, $emailLike]
            ) ?: [];

            return array_map(fn ($row) => $this->normalizeMemberOrderRow($row), $rows);
        } catch (\Throwable) {
            return [];
        }
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

    private function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getFavorites(): array
    {
        $favorites = [];

        try {
            $rows = $this->db->get_results(
                "SELECT * FROM {$this->prefix}favorites WHERE user_id = ? ORDER BY created_at DESC LIMIT 20",
                [$this->getUserId()]
            ) ?: [];

            foreach ($rows as $row) {
                $favoriteUrl = $this->sanitizeProfileUrl((string)($row->url ?? '#'));
                $favorites[] = [
                    'title' => (string)($row->title ?? $row->name ?? ('Eintrag #' . (int)($row->id ?? 0))),
                    'url' => $favoriteUrl !== '' ? $favoriteUrl : '#',
                    'type' => (string)($row->type ?? 'Favorit'),
                    'created_at' => (string)($row->created_at ?? ''),
                ];
            }
        } catch (\Throwable) {
            $meta = $this->memberService->getUserMeta($this->getUserId());
            $saved = Json::decodeArray($meta['favorites'] ?? null, []);
            if (is_array($saved)) {
                foreach ($saved as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $favoriteUrl = $this->sanitizeProfileUrl((string)($item['url'] ?? '#'));
                    $favorites[] = [
                        'title' => (string)($item['title'] ?? 'Gespeicherter Eintrag'),
                        'url' => $favoriteUrl !== '' ? $favoriteUrl : '#',
                        'type' => (string)($item['type'] ?? 'Favorit'),
                        'created_at' => (string)($item['created_at'] ?? ''),
                    ];
                }
            }
        }

        return $favorites;
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
            'label' => 'Meine Dateien',
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
        if ($name === '' || preg_match('/[\\\/\:\*\?"<>\|]/', $name) === 1) {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120);
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
            'label' => 'Meine Dateien',
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
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (string)($_POST['action'] ?? '') !== 'profile_save') {
            return;
        }

        if (!$this->verifyCsrf('profile_save')) {
            $this->flash('danger', 'Sicherheitsüberprüfung fehlgeschlagen.');
            $this->redirect('/member/profile');
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

        $data = [
            'display_name' => trim((string)($_POST['display_name'] ?? ($currentUser->display_name ?? ''))),
            'username' => trim((string)($_POST['username'] ?? ($currentUser->username ?? ''))),
            'first_name' => trim((string)($_POST['first_name'] ?? ($currentMeta['first_name'] ?? ''))),
            'last_name' => trim((string)($_POST['last_name'] ?? ($currentMeta['last_name'] ?? ''))),
            'email' => trim((string)($_POST['email'] ?? ($currentUser->email ?? ''))),
            'bio' => $this->sanitizeWysiwygProfileFieldValue((string)($_POST['bio'] ?? ($currentMeta['bio'] ?? ''))),
            'website' => $this->sanitizeProfileUrl((string)($_POST['website'] ?? ($currentMeta['website'] ?? ''))),
            'phone' => trim((string)($_POST['phone'] ?? ($currentMeta['phone'] ?? ''))),
            'company' => trim((string)($_POST['company'] ?? ($currentMeta['company'] ?? ''))),
            'position' => trim((string)($_POST['position'] ?? ($currentMeta['position'] ?? ''))),
            'birth_date' => trim((string)($_POST['birth_date'] ?? ($currentMeta['birth_date'] ?? ''))),
        ];

        if (trim((string)($_POST['website'] ?? '')) !== '' && $data['website'] === '') {
            $this->flash('danger', 'Bitte eine gültige Website-URL mit http:// oder https:// angeben.');
            $this->redirect('/member/profile');
        }

        $social = $this->sanitizeProfileUrl((string)($_POST['social'] ?? ($currentMeta['social'] ?? '')));
        if (trim((string)($_POST['social'] ?? '')) !== '' && $social === '') {
            $this->flash('danger', 'Bitte einen gültigen Social-/Profil-Link mit http:// oder https:// angeben.');
            $this->redirect('/member/profile');
        }

        $avatar = $this->normalizeProfileMediaUrl((string)($_POST['avatar'] ?? ($currentMeta['avatar'] ?? '')));
        if (trim((string)($_POST['avatar'] ?? '')) !== '' && $avatar === '') {
            $this->flash('danger', 'Bitte eine gültige Avatar-URL oder einen erlaubten relativen Medienpfad angeben.');
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
                $this->flash('danger', 'Bitte eine gültige URL für „' . (string)($definition['label'] ?? $fieldKey) . '“ mit http:// oder https:// angeben.');
                $this->redirect('/member/profile');
            }
        }

        $submittedValues = array_merge($data, [
            'location' => trim((string)($_POST['location'] ?? '')),
            'social' => $social,
            'avatar' => $avatar,
        ], $customValues);
        foreach ($requiredFields as $requiredField) {
            if (trim((string)($submittedValues[$requiredField] ?? '')) === '') {
                $label = (string)($profileDefinitions[$requiredField]['label'] ?? $requiredField);
                $this->flash('danger', 'Bitte das Pflichtfeld „' . $label . '“ ausfüllen.');
                $this->redirect('/member/profile');
            }
        }

        // Die E-Mail-Adresse ist der Kanal für Passwort-Resets: Änderung nur mit aktuellem Passwort.
        if (strcasecmp($data['email'], (string)($currentUser->email ?? '')) !== 0) {
            $reauth = \CMS\Auth::instance()->verifyCurrentPassword($this->getUserId(), (string)($_POST['current_password'] ?? ''));
            if ($reauth !== true) {
                $this->flash('danger', 'E-Mail-Adresse nicht geändert: ' . (string)$reauth);
                $this->redirect('/member/profile');
            }
        }

        $result = $this->memberService->updateProfile($this->getUserId(), $data);
        $this->upsertUserMeta('location', trim((string)($_POST['location'] ?? '')));
        $this->upsertUserMeta('social', $social);
        $this->upsertUserMeta('avatar', $avatar);
        foreach ($customValues as $fieldKey => $fieldValue) {
            $this->upsertUserMeta($fieldKey, $fieldValue);
        }

        if ($result === true) {
            $this->flash('success', 'Dein Profil wurde aktualisiert.');
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
            'textarea' => trim(strip_tags($value)),
            'url' => $this->sanitizeProfileUrl($value),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '',
            default => trim(strip_tags($value)),
        };
    }

    public function handleNotificationsRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (string)($_POST['action'] ?? '') !== 'notifications_save') {
            return;
        }

        if (!$this->verifyCsrf('notifications_save')) {
            $this->flash('danger', 'Sicherheitsüberprüfung fehlgeschlagen.');
            $this->redirect('/member/notifications');
        }

        $preferences = [
            'email_notifications' => !empty($_POST['email_notifications']),
            'email_marketing' => !empty($_POST['email_marketing']),
            'email_updates' => !empty($_POST['email_updates']),
            'email_security' => !empty($_POST['email_security']),
            'browser_notifications' => !empty($_POST['browser_notifications']),
            'desktop_notifications' => !empty($_POST['desktop_notifications']),
            'mobile_notifications' => !empty($_POST['mobile_notifications']),
            'notify_new_features' => !empty($_POST['notify_new_features']),
            'notify_promotions' => !empty($_POST['notify_promotions']),
            'notification_frequency' => (string)($_POST['notification_frequency'] ?? 'immediate'),
        ];

        $this->memberService->updateNotificationPreferences($this->getUserId(), $preferences);
        $this->flash('success', 'Deine Benachrichtigungseinstellungen wurden gespeichert.');
        $this->redirect('/member/notifications');
    }

    public function handlePrivacyRequest(): void
    {
        $action = (string)($_POST['action'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($action, ['privacy_save', 'privacy_export', 'privacy_delete_request'], true)) {
            return;
        }

        if (!$this->verifyCsrf('privacy_action')) {
            $this->flash('danger', 'Sicherheitsüberprüfung fehlgeschlagen.');
            $this->redirect('/member/privacy');
        }

        if ($action === 'privacy_save') {
            $settings = [
                'profile_visibility' => (string)($_POST['profile_visibility'] ?? 'members'),
                'show_email' => !empty($_POST['show_email']),
                'show_activity' => !empty($_POST['show_activity']),
                'public_profile_fields' => array_values(array_filter(array_map('strval', (array)($_POST['public_profile_fields'] ?? [])))),
            ];
            $this->memberService->updatePrivacySettings($this->getUserId(), $settings);
            $this->flash('success', 'Datenschutzeinstellungen gespeichert.');
            $this->redirect('/member/privacy');
        }

        if ($action === 'privacy_delete_request') {
            $this->memberService->requestAccountDeletion($this->getUserId());
            $this->flash('warning', 'Dein Account wurde zur Löschung vorgemerkt. Die Frist beträgt 30 Tage.');
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
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($action, ['message_send', 'message_delete'], true)) {
            return;
        }

        if (!$this->verifyCsrf('messages_action')) {
            $this->flash('danger', 'Sicherheitsüberprüfung fehlgeschlagen.');
            $this->redirect('/member/messages');
        }

        if ($action === 'message_delete') {
            $messageId = (int)($_POST['message_id'] ?? 0);
            $deleted = $this->messageService->delete($messageId, $this->getUserId());
            $this->flash($deleted ? 'success' : 'danger', $deleted ? 'Nachricht gelöscht.' : 'Nachricht konnte nicht gelöscht werden.');
            $this->redirect('/member/messages');
        }

        $recipientInput = trim((string)($_POST['recipient'] ?? ''));
        $recipientId = ctype_digit($recipientInput) ? (int)$recipientInput : $this->resolveRecipientId($recipientInput);
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $parentId = (int)($_POST['parent_id'] ?? 0);

        $result = $recipientId > 0
            ? $this->messageService->send($this->getUserId(), $recipientId, $subject, $body, $parentId > 0 ? $parentId : null)
            : false;

        if ($result !== false) {
            $this->flash('success', 'Nachricht wurde gesendet.');
        } else {
            $this->flash('danger', 'Nachricht konnte nicht gesendet werden. Bitte Empfänger und Inhalt prüfen.');
        }

        $this->redirect('/member/messages');
    }

    public function handleMediaRequest(): void
    {
        $action = (string)($_POST['action'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($action, ['media_delete', 'media_folder_create', 'media_folder_delete', 'media_rename', 'media_move'], true)) {
            return;
        }

        if (!$this->verifyCsrf('media_action')) {
            $this->flash('danger', 'Sicherheitsüberprüfung fehlgeschlagen.');
            $this->redirect('/member/media');
        }

        $mediaService = MediaService::getInstance();
        $memberRoot = $this->getMemberMediaPath();
        $currentPath = $this->normalizeMemberMediaPath((string)($_POST['current_path'] ?? ''), $this->getUserId());
        $settings = $mediaService->getSettings();

        if (!$this->ensureMemberMediaRootExists()) {
            $this->flash('danger', 'Das persönliche Upload-Verzeichnis konnte nicht vorbereitet werden.');
            $this->redirect('/member/media');
        }

        if ($action === 'media_folder_create') {
            $folderName = trim((string)($_POST['folder_name'] ?? ''));
            $result = $mediaService->createFolder($folderName, $currentPath);
            $ok = !($result instanceof \CMS\WP_Error);
            $this->flash($ok ? 'success' : 'danger', $ok ? 'Ordner wurde erstellt.' : $result->get_error_message());
            $this->redirect($this->buildMemberMediaUrl($currentPath));
        }

        $path = $this->normalizeMemberMediaPath((string)($_POST['path'] ?? ''), $this->getUserId());
        if ($path === $memberRoot || (!str_starts_with($path, $memberRoot . '/') && $path !== $memberRoot)) {
            $this->flash('danger', 'Ungültiger Medienpfad.');
            $this->redirect($this->buildMemberMediaUrl($currentPath));
        }

        if ($action === 'media_rename') {
            $newName = $this->sanitizeMemberMediaItemName((string)($_POST['new_name'] ?? ''));
            if ($newName === '') {
                $this->flash('danger', 'Bitte einen gültigen neuen Namen angeben.');
                $this->redirect($this->buildMemberMediaUrl($currentPath));
            }

            $result = $mediaService->renameItem($path, $newName);
            $ok = !($result instanceof \CMS\WP_Error);
            $this->flash($ok ? 'success' : 'danger', $ok ? 'Element wurde umbenannt.' : $result->get_error_message());
            $this->redirect($this->buildMemberMediaUrl($currentPath));
        }

        if ($action === 'media_move') {
            $targetParentPath = $this->normalizeMemberMediaPath((string)($_POST['target_parent_path'] ?? ''), $this->getUserId());
            if ($targetParentPath === $path || str_starts_with($targetParentPath, $path . '/')) {
                $this->flash('danger', 'Ein Ordner kann nicht in sich selbst oder einen Unterordner verschoben werden.');
                $this->redirect($this->buildMemberMediaUrl($currentPath));
            }

            $targetPath = trim(($targetParentPath !== '' ? $targetParentPath . '/' : '') . basename($path), '/');
            if ($targetPath === $path) {
                $this->flash('info', 'Element befindet sich bereits im gewünschten Ordner.');
                $this->redirect($this->buildMemberMediaUrl($currentPath));
            }

            $result = $mediaService->moveFile($path, $targetPath);
            $ok = !($result instanceof \CMS\WP_Error);
            $this->flash($ok ? 'success' : 'danger', $ok ? 'Element wurde verschoben.' : $result->get_error_message());
            $this->redirect($this->buildMemberMediaUrl($currentPath));
        }

        if (empty($settings['member_delete_own'])) {
            $this->flash('warning', 'Das Löschen eigener Dateien ist derzeit deaktiviert.');
            $this->redirect($this->buildMemberMediaUrl($currentPath));
        }

        $result = $mediaService->deleteItem($path);
        $ok = !($result instanceof \CMS\WP_Error);
        $successMessage = $action === 'media_folder_delete' ? 'Ordner wurde gelöscht.' : 'Datei wurde gelöscht.';
        $this->flash($ok ? 'success' : 'danger', $ok ? $successMessage : $result->get_error_message());
        $this->redirect($this->buildMemberMediaUrl($currentPath));
    }

    public function handleSecurityRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $action = (string)($_POST['action'] ?? '');
        if (!in_array($action, ['password_change', 'totp_start', 'totp_confirm', 'totp_disable', 'backup_generate', 'passkey_register', 'passkey_delete'], true)) {
            return;
        }

        $csrfAction = match ($action) {
            'password_change' => 'security_password',
            'passkey_register', 'passkey_delete' => 'security_passkey',
            default => 'security_mfa',
        };

        if (!$this->verifyCsrf($csrfAction)) {
            $this->flash('danger', 'Sicherheitsüberprüfung fehlgeschlagen.');
            $this->redirect('/member/security');
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
                $this->upsertUserMeta('password_changed_at', date('Y-m-d H:i:s'));
                $this->flash('success', 'Passwort erfolgreich geändert.');
            } else {
                $this->flash('danger', (string)$result);
            }

            $this->redirect('/member/security');
        }

        // Sicherheitskritische Änderungen erfordern das aktuelle Passwort: Eine übernommene
        // Sitzung soll MFA nicht abschalten oder eigene Passkeys/Backup-Codes anlegen können.
        if (in_array($action, ['totp_disable', 'backup_generate', 'passkey_register'], true)) {
            $reauth = \CMS\Auth::instance()->verifyCurrentPassword($userId, (string)($_POST['current_password'] ?? ''));
            if ($reauth !== true) {
                $this->flash('danger', (string)$reauth);
                $this->redirect('/member/security');
            }
        }

        if ($action === 'totp_start') {
            $setup = TotpAdapter::instance()->startSetup($userId, $this->getDisplayName());
            $_SESSION['member_totp_setup'] = $setup;
            $this->flash('info', 'Authenticator-Setup wurde vorbereitet. Bestätige jetzt den 6-stelligen Code.', $setup);
            $this->redirect('/member/security');
        }

        if ($action === 'totp_confirm') {
            $code = trim((string)($_POST['totp_code'] ?? ''));
            $confirmed = TotpAdapter::instance()->confirmSetup($userId, $code);
            if ($confirmed) {
                unset($_SESSION['member_totp_setup']);
                $codes = BackupCodesManager::instance()->generate($userId);
                $this->flash('success', 'Zwei-Faktor-Authentifizierung wurde aktiviert.', ['backup_codes' => $codes]);
            } else {
                $this->flash('danger', 'Der eingegebene TOTP-Code war ungültig.');
            }
            $this->redirect('/member/security');
        }

        if ($action === 'totp_disable') {
            TotpAdapter::instance()->disable($userId);
            BackupCodesManager::instance()->deleteAll($userId);
            unset($_SESSION['member_totp_setup']);
            $this->flash('warning', 'Zwei-Faktor-Authentifizierung wurde deaktiviert.');
            $this->redirect('/member/security');
        }

        if ($action === 'backup_generate') {
            $codes = BackupCodesManager::instance()->regenerate($userId);
            $this->flash('success', 'Neue Backup-Codes wurden erzeugt.', ['backup_codes' => $codes]);
            $this->redirect('/member/security');
        }

        if ($action === 'passkey_delete') {
            $credentialId = (int)($_POST['credential_id'] ?? 0);
            $deleted = WebAuthnAdapter::instance()->deleteCredential($credentialId, $userId);
            $this->flash($deleted ? 'success' : 'danger', $deleted ? 'Passkey wurde entfernt.' : 'Passkey konnte nicht entfernt werden.');
            $this->redirect('/member/security');
        }

        $challenge = (string)($_SESSION['member_passkey_challenge'] ?? '');
        if ($challenge === '') {
            $this->flash('danger', 'Die Passkey-Challenge ist abgelaufen. Bitte erneut versuchen.');
            $this->redirect('/member/security');
        }

        $clientData = $this->base64UrlDecode((string)($_POST['client_data_json'] ?? ''));
        $attestation = $this->base64UrlDecode((string)($_POST['attestation_object'] ?? ''));
        $credentialName = trim((string)($_POST['credential_name'] ?? 'Neuer Passkey'));

        $result = WebAuthnAdapter::instance()->processRegistration($userId, $clientData, $attestation, $challenge, $credentialName);
        unset($_SESSION['member_passkey_challenge']);

        if ($result === true) {
            $this->flash('success', 'Passkey erfolgreich registriert.');
        } else {
            $this->flash('danger', (string)$result);
        }

        $this->redirect('/member/security');
    }

    /**
     * @return array<string,mixed>
     */
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
            'totp_setup' => is_array($setup) ? $setup : null,
            'passkey_payload' => $passkeyPayload,
        ];
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

    private function resolveRecipientId(string $input): int
    {
        if ($input === '') {
            return 0;
        }

        $row = $this->db->get_row(
            "SELECT id FROM {$this->prefix}users WHERE status = 'active' AND (username = ? OR display_name = ? OR email = ?) LIMIT 1",
            [$input, $input, $input]
        );

        return (int)($row->id ?? 0);
    }

    private function upsertUserMeta(string $key, string $value): void
    {
        $this->db->execute(
            "INSERT INTO {$this->prefix}user_meta (user_id, meta_key, meta_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            [$this->getUserId(), $key, $value]
        );
    }

    private function sanitizeProfileUrl(string $value, array $allowedSchemes = ['http', 'https']): string
    {
        $url = trim($value);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? '' : $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme === '' || !in_array($scheme, $allowedSchemes, true)) {
            return '';
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
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
     * @param array<string,mixed> $widget
     * @return array<string,mixed>
     */
    private function sanitizeDashboardWidget(array $widget): array
    {
        $stats = is_array($widget['stats'] ?? null) ? $widget['stats'] : null;

        return [
            'plugin' => $this->sanitizeDashboardText((string)($widget['plugin'] ?? ''), 120),
            'slug' => $this->sanitizeDashboardText((string)($widget['slug'] ?? ''), 120),
            'icon' => $this->sanitizeDashboardText((string)($widget['icon'] ?? '🔌'), 16, '🔌'),
            'title' => $this->sanitizeDashboardText((string)($widget['title'] ?? 'Plugin'), 160, 'Plugin'),
            'description' => $this->sanitizeDashboardText((string)($widget['description'] ?? ''), 400),
            'color' => $this->sanitizeDashboardColor((string)($widget['color'] ?? '#4f46e5')),
            'link' => $this->sanitizeDashboardLink($widget['link'] ?? '/member/dashboard', '/member/dashboard'),
            'link_label' => $this->sanitizeDashboardText((string)($widget['link_label'] ?? 'Öffnen'), 80, 'Öffnen'),
            'admin_link' => $this->sanitizeDashboardNullableLink($widget['admin_link'] ?? null),
            'admin_label' => $this->sanitizeDashboardText((string)($widget['admin_label'] ?? '⚙️ Verwalten'), 80, '⚙️ Verwalten'),
            'badge' => $this->sanitizeDashboardNullableText($widget['badge'] ?? null, 40),
            'stats' => $stats === null ? null : [
                'count' => (int)($stats['count'] ?? 0),
                'label' => $this->sanitizeDashboardText((string)($stats['label'] ?? 'Einträge'), 80, 'Einträge'),
            ],
        ];
    }

    private function sanitizeDashboardColor(string $value, string $fallback = '#4f46e5'): string
    {
        $color = trim($value);
        return preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? $color : $fallback;
    }

    private function sanitizeDashboardLink(mixed $value, string $fallback = '/member/dashboard'): string
    {
        $href = trim((string) $value);
        if ($href === '') {
            return $fallback;
        }

        if (str_starts_with($href, '/')) {
            return str_starts_with($href, '//') ? $fallback : $href;
        }

        if (preg_match('#^https?://#i', $href) === 1) {
            return filter_var($href, FILTER_VALIDATE_URL) ? $href : $fallback;
        }

        return $fallback;
    }

    private function sanitizeDashboardNullableLink(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $href = trim((string) $value);
        if ($href === '') {
            return null;
        }

        $sanitized = $this->sanitizeDashboardLink($href, '');
        return $sanitized !== '' ? $sanitized : null;
    }

    private function sanitizeDashboardText(string $value, int $maxLength = 160, string $fallback = ''): string
    {
        $text = trim(strip_tags($value));
        if ($text === '') {
            return $fallback;
        }

        return function_exists('mb_substr') ? mb_substr($text, 0, $maxLength) : substr($text, 0, $maxLength);
    }

    private function sanitizeDashboardNullableText(mixed $value, int $maxLength = 80): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = $this->sanitizeDashboardText((string) $value, $maxLength);
        return $text !== '' ? $text : null;
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

    private function normalizeNotificationType(mixed $type): string
    {
        $normalizedType = strtolower(trim((string) $type));

        return match ($normalizedType) {
            'message', 'messages' => 'messages',
            'billing' => 'billing',
            'security' => 'security',
            'community' => 'community',
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
