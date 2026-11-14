<?php
/**
 * Mitgliederbereich „Newsletter“: Unterseite `/member/plugin/newsletter` und Dashboard-Widget (Anker `#newsletter`).
 *
 * Beide nutzen dieselbe Vorlage `views/member/widgets/newsletter.php` (im Theme überschreibbar als
 * `<theme>/member/widgets/newsletter.php`) und denselben Service (NewsletterMemberService). Formulare posten an
 * `/member/plugin/newsletter`; die Registry verarbeitet den POST vor jeder Ausgabe, setzt die Rückmeldung als
 * Flash und leitet mit 303 auf `return_to` (dashboard → `/member/dashboard#newsletter`, sonst die Unterseite).
 *
 * Ein Abonnement ohne Kontobezug wird nur über eine bestätigte Konto-Adresse zugeordnet
 * (EmailVerificationService::matchableEmail()); unbestätigte Konto-Adressen melden sich immer per Double-Opt-In an.
 *
 * @package CMS\Services\Newsletter
 */

declare(strict_types=1);

namespace CMS\Services\Newsletter;

use CMS\Member\PluginDashboardRegistry;
use CMS\ThemeManager;

if (!defined('ABSPATH')) {
    exit;
}

final class NewsletterMemberSection
{
    public const SLUG = 'newsletter';
    public const ANCHOR = 'newsletter';
    public const ACTION_URL = '/member/plugin/' . self::SLUG;
    /** Kennung der Flash-Meldungen dieses Bereichs (`payload.source`). */
    public const FLASH_SOURCE = 'newsletter';

    public static function register(PluginDashboardRegistry $registry): void
    {
        if (!NewsletterRepository::instance()->isAvailable()) {
            return;
        }
        $widget = [
            'title' => (string) __('newsletter.member.title'),
            'description' => (string) __('newsletter.member.widget_description'),
            'color' => '#0d9488',
            'stats_callback' => [self::class, 'stats'],
            'anchor' => self::ANCHOR,
        ];
        if (NewsletterSettings::flag('member_widget_enabled')) {
            $widget['render_callback'] = [self::class, 'renderWidget'];
        }
        $registry->register([
            'plugin' => 'cms-newsletter',
            'slug' => self::SLUG,
            'label' => (string) __('newsletter.member.title'),
            'icon' => '✉️',
            'category' => 'plugins',
            'priority' => 85,
            'csrf' => 'core',
            'dashboard_widget' => $widget,
            'render_callback' => [self::class, 'render'],
            'post_callback' => [self::class, 'handlePost'],
        ]);
    }

    /** Zählerkachel (Rückfall ohne Widget): 1 = aktiv abonniert. @return array{count: int, label: string} */
    public static function stats(object $user): array
    {
        $status = NewsletterMemberService::instance()->statusFor($user)['status'];

        return ['count' => $status === 'active' ? 1 : 0, 'label' => (string) __($status === 'active' ? 'newsletter.member.status_active' : 'newsletter.member.status_none')];
    }

    /**
     * POST an `/member/plugin/newsletter` (vor jeder Ausgabe aufgerufen). Rückgabe an die Registry:
     * Flash-Meldung, Ziel der 303-Weiterleitung und Kennung für das Widget.
     *
     * @param array<string, mixed> $params
     * @return array{type: string, message: string, redirect: string, payload: array<string, mixed>}
     */
    public static function handlePost(object $user, array $params = []): array
    {
        $action = is_scalar($_POST['newsletter_action'] ?? null) ? (string) $_POST['newsletter_action'] : '';
        $returnKey = ($_POST['return_to'] ?? '') === 'dashboard' ? 'dashboard' : 'section';
        $result = NewsletterMemberService::instance()->handle($user, $action, [
            'consent' => $_POST['newsletter_consent'] ?? null,
            'segment' => $_POST['segment'] ?? null,
            'locale' => $_POST['locale'] ?? null,
        ]);

        return [
            'type' => $result['type'],
            'message' => $result['message'],
            'redirect' => NewsletterMemberService::returnTarget($returnKey),
            'payload' => ['source' => self::FLASH_SOURCE, 'code' => $result['code'], 'return_to' => $returnKey],
        ];
    }

    /** Unterseite: Meldungen zeigt der Kopf des Mitgliederbereichs (alerts.php). @param array<string, mixed> $params */
    public static function render(object $user, array $params = []): void
    {
        echo self::renderTemplate($user, 'section', null);
    }

    /**
     * `dashboard_widget.render_callback`: Markup des Widgets. Eine Flash-Meldung dieses Bereichs mit
     * `return_to = dashboard` wird hier (statt oben im Dashboard) angezeigt, damit sie am Rücksprung-Anker steht.
     *
     * @param array<string, mixed> $context
     */
    public static function renderWidget(object $user, array $context = []): string
    {
        return self::renderTemplate($user, 'dashboard', self::takeWidgetNotice());
    }

    /**
     * Rendert die Widget-Vorlage (Theme-Override vor Core-Vorlage).
     *
     * @param array{type: string, message: string}|null $notice
     */
    public static function renderTemplate(object $user, string $returnTo, ?array $notice): string
    {
        $service = NewsletterMemberService::instance();
        $uiLocale = $service->uiLocale();
        $vars = [
            'status' => $service->statusFor($user),
            'csrfField' => PluginDashboardRegistry::instance()->csrfField(self::SLUG),
            'actionUrl' => self::ACTION_URL,
            'segments' => $service->segmentChoices($uiLocale),
            'locales' => $service->localeChoices(),
            'consentText' => $service->consentText($uiLocale),
            'notice' => $notice,
            'returnTo' => $returnTo === 'dashboard' ? 'dashboard' : 'section',
        ];

        return self::includeTemplate(self::templatePath(), $vars);
    }

    /** Pfad der Vorlage: `<theme>/member/widgets/newsletter.php`, sonst `views/member/widgets/newsletter.php`. */
    public static function templatePath(): string
    {
        try {
            $themeFile = ThemeManager::instance()->getThemePath() . 'member/widgets/newsletter.php';
            if (is_file($themeFile)) {
                return $themeFile;
            }
        } catch (\Throwable) {
        }

        return ABSPATH . 'views/member/widgets/newsletter.php';
    }

    /**
     * Bindet die Vorlage mit genau den Variablen des Vertrags ein und liefert die Ausgabe.
     *
     * @param array<string, mixed> $vars
     */
    public static function includeTemplate(string $file, array $vars): string
    {
        if (!is_file($file)) {
            return '';
        }
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                $status = $__vars['status'];
                $csrfField = $__vars['csrfField'];
                $actionUrl = $__vars['actionUrl'];
                $segments = $__vars['segments'];
                $locales = $__vars['locales'];
                $consentText = $__vars['consentText'];
                $notice = $__vars['notice'];
                $returnTo = $__vars['returnTo'];
                include $__file;
            })($file, $vars);
        } finally {
            $html = '';
            while (ob_get_level() > $level) {
                $html = (string) ob_get_clean() . $html;
            }
        }

        return $html;
    }

    /**
     * Holt eine Flash-Meldung dieses Bereichs für das Dashboard-Widget aus der Sitzung (und verbraucht sie).
     *
     * @return array{type: string, message: string}|null
     */
    private static function takeWidgetNotice(): ?array
    {
        $flash = $_SESSION['member_alert'] ?? null;
        if (!is_array($flash) || ($flash['payload']['source'] ?? '') !== self::FLASH_SOURCE || ($flash['payload']['return_to'] ?? '') !== 'dashboard') {
            return null;
        }
        if (class_exists(\CMS\MemberArea\MemberController::class, false)) {
            $flash = \CMS\MemberArea\MemberController::instance()->consumeFlash() ?? $flash;
        } else {
            unset($_SESSION['member_alert']);
        }
        $type = in_array($flash['type'] ?? '', PluginDashboardRegistry::FLASH_TYPES, true) ? (string) $flash['type'] : 'info';

        return ['type' => $type, 'message' => (string) ($flash['message'] ?? '')];
    }
}
