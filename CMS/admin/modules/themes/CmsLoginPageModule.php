<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Services\CmsAuthPageService;

final class CmsLoginPageModule
{
    public const ACTION_SAVE = 'save';
    public const ACTION_IMPORT_THEME_COLORS = 'import_theme_colors';

    private CmsAuthPageService $service;

    public function __construct()
    {
        $this->service = CmsAuthPageService::getInstance();
    }

    /**
     * @param array<string, string>|null $settingsOverride Eingaben nach einem abgelehnten Speichern (Formular erneut befüllen)
     */
    public function getData(?array $settingsOverride = null): array
    {
        $settings = $this->service->getSettings();
        if ($settingsOverride !== null) {
            $settings = array_merge($settings, $settingsOverride);
        }

        $loginPath = $this->service->getPublicPath('login', null, $settings);
        $registerPath = $this->service->getPublicPath('register', null, $settings);
        $showcasePagesMode = (string) ($settings['showcase_pages'] ?? 'login');

        return [
            'settings' => $settings,
            'page_options' => $this->service->getPageOptions(),
            'registration_enabled' => $this->service->isRegistrationEnabled($settings),
            'passkey_enabled' => $this->service->isPasskeyLoginEnabled($settings),
            'theme_colors' => $this->service->getThemeLoginColors(),
            'active_theme' => $this->service->getActiveThemeSlug(),
            'showcase_visible' => $this->service->buildShowcase($settings, 'login') !== null,
            'preview_urls' => [
                'login' => $loginPath,
                'register' => $registerPath,
                'forgot_password' => $this->service->getPublicPath('forgot-password', null, $settings),
                'showcase' => $loginPath . '#cms-auth-showcase',
                'showcase_register' => $showcasePagesMode === 'login_register' ? $registerPath . '#cms-auth-showcase' : '',
            ],
        ];
    }

    /** Verteilt die POST-Aktionen der Seite (Speichern oder Theme-Farben übernehmen). */
    public function handlePost(array $post): array
    {
        $action = (string) ($post['loginpage_action'] ?? self::ACTION_SAVE);

        return $action === self::ACTION_IMPORT_THEME_COLORS
            ? $this->importThemeColors()
            : $this->saveSettings($post);
    }

    public function saveSettings(array $post): array
    {
        $result = $this->service->saveSettings($post);

        if (!empty($result['success'])) {
            $changedKeys = is_array($result['changed_keys'] ?? null) ? $result['changed_keys'] : [];
            $this->audit(
                'cms_loginpage.saved',
                'CMS-Loginpage-Einstellungen gespeichert.',
                [
                    'changed_settings' => array_slice($changedKeys, 0, 80),
                    'changed_count' => count($changedKeys),
                    'showcase_changed' => $this->containsShowcaseKey($changedKeys),
                ]
            );

            return $result;
        }

        // Abgelehnte Eingaben (z. B. Showcase-Bild ohne Alternativtext) im Formular stehen lassen.
        if (is_array($result['values'] ?? null)) {
            return [
                'success' => false,
                'error' => 'CMS Loginpage wurde nicht gespeichert. Bitte die folgenden Angaben prüfen:',
                'details' => is_array($result['errors'] ?? null) ? array_values($result['errors']) : [],
                'render_inline' => true,
                'runtime_context' => [
                    'data' => $this->getData($result['values']),
                ],
            ];
        }

        return $result;
    }

    public function importThemeColors(): array
    {
        $result = $this->service->importThemeColors();

        if (!empty($result['success'])) {
            $this->audit(
                'cms_loginpage.theme_colors_imported',
                'Farben der CMS-Loginpage aus dem aktiven Theme übernommen.',
                [
                    'theme' => (string) ($result['theme'] ?? ''),
                    'changed_settings' => is_array($result['changed_keys'] ?? null) ? $result['changed_keys'] : [],
                ]
            );
        }

        return $result;
    }

    /** @param list<string> $keys */
    private function containsShowcaseKey(array $keys): bool
    {
        foreach ($keys as $key) {
            if (str_starts_with((string) $key, 'showcase_')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(string $action, string $description, array $metadata): void
    {
        if (!class_exists(AuditLogger::class)) {
            return;
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            $action,
            $description,
            'cms_loginpage',
            null,
            $metadata,
            'info'
        );
    }
}
