<?php
declare(strict_types=1);

/**
 * SubscriptionSettingsModule – Abo-System Konfiguration
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\AuditLogger;
use CMS\Logger;

final class SubscriptionSettingsViewData
{
    public function __construct(
        private array $settings,
        private array $plans = [],
        private array $pages = [],
        private ?string $error = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'settings' => $this->settings,
            'plans' => $this->plans,
            'pages' => $this->pages,
            'error' => $this->error,
        ];
    }
}

final class SubscriptionSettingsActionResult
{
    private function __construct(
        private bool $success,
        private string $message,
    ) {
    }

    public static function success(string $message): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }

    public function toArray(): array
    {
        return $this->success
            ? ['success' => true, 'message' => $this->message]
            : ['success' => false, 'error' => $this->message];
    }
}

class SubscriptionSettingsModule
{
    private const string REQUIRED_CAPABILITY = 'manage_settings';
    private readonly \CMS\Database $db;
    private readonly string $prefix;
    /** @var array<string, true> */
    private array $existingSettingNamesCache = [];

    private const int MAX_NOTICE_LENGTH = 2000;
    private const int MAX_EMAIL_LENGTH = 190;
    private const int MAX_PREFIX_LENGTH = 20;

    /** @var array<string, string> */
    private const array PACKAGE_SETTINGS_KEYS = [
        'subscription_enabled'       => '1',
        'trial_enabled'              => '0',
        'trial_days'                 => '14',
        'auto_renewal'               => '1',
        'grace_period_days'          => '3',
        'cancellation_period_days'   => '0',
        'payment_methods'            => 'invoice',
        'invoice_prefix'             => 'INV-',
        'invoice_next_number'        => '1001',
        'tax_rate'                   => '19',
        'tax_included'               => '1',
        'notification_before_expiry' => '7',
        'notification_email'         => '',
        'terms_page_id'              => '0',
        'cancellation_page_id'       => '0',
    ];

    /** @var array<string, string> */
    private const array GENERAL_SETTINGS_KEYS = [
        'subscription_limits_enabled'       => '1',
        'subscription_default_plan_id'      => '0',
        'subscription_member_area_enabled'  => '1',
        'subscription_ordering_enabled'     => '1',
        'subscription_public_pricing_enabled' => '1',
        'subscription_disabled_notice'      => 'Die Aboverwaltung ist derzeit deaktiviert. Es gelten aktuell keine Limits.',
    ];

    public function __construct()
    {
        $this->db     = \CMS\Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    public function getData(): SubscriptionSettingsViewData
    {
        if (!$this->canAccess()) {
            return new SubscriptionSettingsViewData([], [], [], 'Zugriff verweigert.');
        }

        return new SubscriptionSettingsViewData(
            $this->getSettingsMap(self::GENERAL_SETTINGS_KEYS),
            $this->mapRows($this->fetchPlans())
        );
    }

    public function getPackageData(): SubscriptionSettingsViewData
    {
        if (!$this->canAccess()) {
            return new SubscriptionSettingsViewData([], [], [], 'Zugriff verweigert.');
        }

        return new SubscriptionSettingsViewData(
            $this->getSettingsMap(self::PACKAGE_SETTINGS_KEYS),
            [],
            $this->mapRows($this->fetchPublishedPages())
        );
    }

    public function saveSettings(array $post): SubscriptionSettingsActionResult
    {
        if (!$this->canAccess()) {
            return $this->denyResult();
        }

        try {
            $settings = $this->buildGeneralSettingsPayload($post);

            return $this->storeSettingsWithAudit(
                $settings,
                'subscriptions.settings.save',
                'Aboverwaltung-Einstellungen gespeichert',
                [
                    'limits_enabled' => $settings['subscription_limits_enabled'],
                    'member_area_enabled' => $settings['subscription_member_area_enabled'],
                    'ordering_enabled' => $settings['subscription_ordering_enabled'],
                    'default_plan_id' => $settings['subscription_default_plan_id'],
                ],
                'Aboverwaltung-Einstellungen gespeichert.'
            );
        } catch (\Throwable $e) {
            return $this->failResult('subscriptions.settings.save_failed', 'Aboverwaltung-Einstellungen konnten nicht gespeichert werden.', $e);
        }
    }

    public function savePackageSettings(array $post): SubscriptionSettingsActionResult
    {
        if (!$this->canAccess()) {
            return $this->denyResult();
        }

        try {
            $settings = $this->buildPackageSettingsPayload($post);

            return $this->storeSettingsWithAudit(
                $settings,
                'subscriptions.package_settings.save',
                'Paket- und Abo-Einstellungen gespeichert',
                [
                    'subscription_enabled' => $settings['subscription_enabled'],
                    'trial_enabled' => $settings['trial_enabled'],
                    'payment_methods' => $settings['payment_methods'],
                    'tax_included' => $settings['tax_included'],
                    'terms_page_id' => $settings['terms_page_id'],
                    'cancellation_page_id' => $settings['cancellation_page_id'],
                    'notification_email' => $settings['notification_email'] !== '' ? 'configured' : 'empty',
                ],
                'Paket- und Abo-Einstellungen gespeichert.'
            );
        } catch (\Throwable $e) {
            return $this->failResult('subscriptions.package_settings.save_failed', 'Paket- und Abo-Einstellungen konnten nicht gespeichert werden.', $e);
        }
    }

    private function buildGeneralSettingsPayload(array $post): array
    {
        $planIds = $this->getExistingIds($this->fetchPlanIds());

        return [
            'subscription_limits_enabled' => isset($post['subscription_limits_enabled']) ? '1' : '0',
            'subscription_member_area_enabled' => isset($post['subscription_member_area_enabled']) ? '1' : '0',
            'subscription_ordering_enabled' => isset($post['subscription_ordering_enabled']) ? '1' : '0',
            'subscription_public_pricing_enabled' => isset($post['subscription_public_pricing_enabled']) ? '1' : '0',
            'subscription_default_plan_id' => $this->sanitizeExistingId($post['subscription_default_plan_id'] ?? 0, $planIds),
            'subscription_disabled_notice' => $this->sanitizeText((string)($post['subscription_disabled_notice'] ?? self::GENERAL_SETTINGS_KEYS['subscription_disabled_notice']), self::MAX_NOTICE_LENGTH, self::GENERAL_SETTINGS_KEYS['subscription_disabled_notice']),
        ];
    }

    private function buildPackageSettingsPayload(array $post): array
    {
        $pageIds = $this->getExistingIds($this->fetchPublishedPageIds());

        return [
            'subscription_enabled' => isset($post['subscription_enabled']) ? '1' : '0',
            'trial_enabled' => isset($post['trial_enabled']) ? '1' : '0',
            'trial_days' => $this->sanitizeIntRange($post['trial_days'] ?? self::PACKAGE_SETTINGS_KEYS['trial_days'], 1, 365),
            'auto_renewal' => isset($post['auto_renewal']) ? '1' : '0',
            'grace_period_days' => $this->sanitizeIntRange($post['grace_period_days'] ?? self::PACKAGE_SETTINGS_KEYS['grace_period_days'], 0, 365),
            'cancellation_period_days' => $this->sanitizeIntRange($post['cancellation_period_days'] ?? self::PACKAGE_SETTINGS_KEYS['cancellation_period_days'], 0, 365),
            'payment_methods' => $this->sanitizeAllowedValue((string)($post['payment_methods'] ?? self::PACKAGE_SETTINGS_KEYS['payment_methods']), ['invoice', 'stripe', 'paypal', 'all'], self::PACKAGE_SETTINGS_KEYS['payment_methods']),
            'invoice_prefix' => $this->sanitizeText((string)($post['invoice_prefix'] ?? self::PACKAGE_SETTINGS_KEYS['invoice_prefix']), self::MAX_PREFIX_LENGTH, self::PACKAGE_SETTINGS_KEYS['invoice_prefix']),
            'invoice_next_number' => $this->sanitizeIntRange($post['invoice_next_number'] ?? self::PACKAGE_SETTINGS_KEYS['invoice_next_number'], 1, 99999999),
            'tax_rate' => $this->sanitizeIntRange($post['tax_rate'] ?? self::PACKAGE_SETTINGS_KEYS['tax_rate'], 0, 100),
            'tax_included' => isset($post['tax_included']) ? '1' : '0',
            'notification_before_expiry' => $this->sanitizeIntRange($post['notification_before_expiry'] ?? self::PACKAGE_SETTINGS_KEYS['notification_before_expiry'], 0, 365),
            'notification_email' => $this->sanitizeOptionalEmail((string)($post['notification_email'] ?? self::PACKAGE_SETTINGS_KEYS['notification_email'])),
            'terms_page_id' => $this->sanitizeExistingId($post['terms_page_id'] ?? 0, $pageIds),
            'cancellation_page_id' => $this->sanitizeExistingId($post['cancellation_page_id'] ?? 0, $pageIds),
        ];
    }

    /** @param array<string, string> $defaults
     *  @return array<string, string> */
    private function getSettingsMap(array $defaults): array
    {
        if ($defaults === []) {
            return [];
        }

        $keys = array_keys($defaults);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $rows = $this->db->get_results(
            "SELECT option_name, option_value FROM {$this->prefix}settings WHERE option_name IN ({$placeholders})",
            $keys
        ) ?: [];

        $settings = $defaults;
        foreach ($rows as $row) {
            $settings[(string)$row->option_name] = (string)$row->option_value;
        }

        return $settings;
    }

    /** @param array<string, string> $values */
    private function storeSettings(array $values): void
    {
        if ($values === []) {
            return;
        }

        $this->warmSettingNamesCache(array_keys($values));
        foreach ($values as $key => $value) {
            if (isset($this->existingSettingNamesCache[$key])) {
                $updated = $this->db->update('settings', ['option_value' => $value], ['option_name' => $key]);
                if ($updated !== true) {
                    throw new \RuntimeException('Setting update failed for key: ' . $key);
                }

                continue;
            }

            $insertId = $this->db->insert('settings', ['option_name' => $key, 'option_value' => $value]);
            if ($insertId === false) {
                throw new \RuntimeException('Setting insert failed for key: ' . $key);
            }

            $this->existingSettingNamesCache[$key] = true;
        }
    }

    /** @param list<string> $keys */
    private function warmSettingNamesCache(array $keys): void
    {
        $missing = [];
        foreach ($keys as $key) {
            if ($key !== '' && !isset($this->existingSettingNamesCache[$key])) {
                $missing[] = $key;
            }
        }

        if ($missing === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $rows = $this->db->get_results(
            "SELECT option_name FROM {$this->prefix}settings WHERE option_name IN ({$placeholders})",
            $missing
        ) ?: [];

        foreach ($rows as $row) {
            $key = (string)($row->option_name ?? '');
            if ($key !== '') {
                $this->existingSettingNamesCache[$key] = true;
            }
        }
    }

    /** @return list<object> */
    private function fetchPlans(): array
    {
        return $this->db->get_results(
            "SELECT id, name, slug, price_monthly, is_active FROM {$this->prefix}subscription_plans ORDER BY sort_order ASC, price_monthly ASC"
        ) ?: [];
    }

    /** @return list<object> */
    private function fetchPublishedPages(): array
    {
        return $this->db->get_results("SELECT id, title FROM {$this->prefix}pages WHERE status = 'published' ORDER BY title ASC") ?: [];
    }

    /** @return list<object> */
    private function fetchPlanIds(): array
    {
        return $this->db->get_results("SELECT id FROM {$this->prefix}subscription_plans") ?: [];
    }

    /** @return list<object> */
    private function fetchPublishedPageIds(): array
    {
        return $this->db->get_results("SELECT id FROM {$this->prefix}pages WHERE status = 'published'") ?: [];
    }

    /**
     * @param list<object> $rows
     * @return array<int, true>
     */
    private function getExistingIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int)($row->id ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    private function sanitizeIntRange(mixed $value, int $min, int $max): string
    {
        return (string) max($min, min($max, (int) $value));
    }

    /** @param array<int, true> $allowedIds */
    private function sanitizeExistingId(mixed $value, array $allowedIds): string
    {
        $id = max(0, (int)$value);
        if ($id === 0) {
            return '0';
        }

        return isset($allowedIds[$id]) ? (string)$id : '0';
    }

    /** @param list<string> $allowed */
    private function sanitizeAllowedValue(string $value, array $allowed, string $default): string
    {
        $value = trim(strtolower($value));
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function sanitizeOptionalEmail(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = function_exists('mb_substr') ? mb_substr($value, 0, self::MAX_EMAIL_LENGTH) : substr($value, 0, self::MAX_EMAIL_LENGTH);
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    private function sanitizeText(string $value, int $maxLength, string $fallback = ''): string
    {
        $value = trim(strip_tags($value));
        if ($value === '') {
            return $fallback;
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    /**
     * @param list<object> $rows
     * @return list<array<string, mixed>>
     */
    private function mapRows(array $rows): array
    {
        return array_map(static fn($row) => (array) $row, $rows);
    }

    private function canAccess(): bool
    {
        return Auth::instance()->isAdmin() && Auth::instance()->hasCapability(self::REQUIRED_CAPABILITY);
    }

    private function denyResult(): SubscriptionSettingsActionResult
    {
        return SubscriptionSettingsActionResult::failure('Zugriff verweigert.');
    }

    /**
     * @param array<string, string> $settings
     * @param array<string, scalar> $auditContext
     */
    private function storeSettingsWithAudit(
        array $settings,
        string $auditAction,
        string $auditMessage,
        array $auditContext,
        string $successMessage
    ): SubscriptionSettingsActionResult {
        $this->storeSettings($settings);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            $auditAction,
            $auditMessage,
            'subscription_settings',
            null,
            $auditContext,
            'info'
        );

        return SubscriptionSettingsActionResult::success($successMessage);
    }

    private function failResult(string $action, string $message, \Throwable $e): SubscriptionSettingsActionResult
    {
        Logger::instance()->error($message, [
            'module' => 'SubscriptionSettingsModule',
            'action' => $action,
            'exception' => $e::class,
        ]);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            $action,
            $message,
            'subscription_settings',
            null,
            ['exception' => $e::class],
            'error'
        );

        return SubscriptionSettingsActionResult::failure($message . ' Bitte Logs prüfen.');
    }
}
