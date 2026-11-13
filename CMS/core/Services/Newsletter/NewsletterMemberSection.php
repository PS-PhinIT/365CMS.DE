<?php
/**
 * Mitgliederbereich „Newsletter“ (`/member/plugin/newsletter`): Abonnement mit der Konto-Adresse an- und abmelden,
 * Thema (Segment) und Sprache wählen. Mit aktiviertem Double-Opt-In kommt auch hier eine Bestätigungsmail.
 * Ein Abonnement ohne Kontobezug wird nur über eine bestätigte Konto-Adresse zugeordnet
 * (EmailVerificationService::matchableEmail()). Konten ohne bestätigte Adresse erhalten hier nur den Hinweis,
 * die Adresse über einen Passwort-Reset zu bestätigen; danach steht alles zur Verfügung.
 *
 * @package CMSv2\Core\Services\Newsletter
 */

declare(strict_types=1);

namespace CMS\Services\Newsletter;

use CMS\Member\PluginDashboardRegistry;
use CMS\Services\EmailVerificationService;

if (!defined('ABSPATH')) {
    exit;
}

final class NewsletterMemberSection
{
    public const SLUG = 'newsletter';

    public static function register(PluginDashboardRegistry $registry): void
    {
        if (!NewsletterRepository::instance()->isAvailable()) {
            return;
        }
        $registry->register([
            'plugin' => 'cms-newsletter',
            'slug' => self::SLUG,
            'label' => (string) __('newsletter.member.title'),
            'icon' => '✉️',
            'category' => 'plugins',
            'priority' => 85,
            'csrf' => 'core',
            'dashboard_widget' => [
                'title' => (string) __('newsletter.member.title'),
                'description' => (string) __('newsletter.member.widget_description'),
                'color' => '#0d9488',
                'stats_callback' => [self::class, 'stats'],
            ],
            'render_callback' => [self::class, 'render'],
            'post_callback' => [self::class, 'handlePost'],
        ]);
    }

    /** @return array{count: int, label: string} */
    public static function stats(object $user): array
    {
        $status = (string) (self::find($user)['status'] ?? '');

        return ['count' => $status === 'active' ? 1 : 0, 'label' => (string) __($status === 'active' ? 'newsletter.member.status_active' : 'newsletter.member.status_none')];
    }

    /** @param array<string, mixed> $params */
    public static function handlePost(object $user, array $params = []): void
    {
        $repo = NewsletterRepository::instance();
        $action = (string) ($_POST['newsletter_action'] ?? '');
        $current = self::find($user);
        $notice = null;
        $email = NewsletterRepository::normalizeEmail((string) ($user->email ?? ''));

        if ($action === 'subscribe' && $email !== '') {
            $locale = ($_POST['locale'] ?? '') === 'en' ? 'en' : 'de';
            $verified = self::hasVerifiedEmail($user);
            if ($current !== null && $current['status'] === 'active') {
                // Bereits aktiv: nur Thema und Sprache ändern.
                // Ohne Themenauswahl (nur ein Thema definiert) bleibt das bisherige Thema erhalten.
                $segment = trim((string) ($_POST['segment'] ?? '')) ?: (string) ($current['segment_slug'] ?? '');
                $repo->saveSubscriber(['segment_slug' => $segment, 'locale' => $locale, 'user_id' => (int) $user->id] + $current, (int) $current['id']);
                $notice = ['success', (string) __('newsletter.member.saved')];
            } else {
                // Unbestätigte Konto-Adresse: wie eine öffentliche Anmeldung behandeln – immer Double-Opt-In,
                // keine Bindung an das Konto und dieselbe Meldung, egal ob die Adresse schon eingetragen ist.
                $doubleOptIn = !$verified || NewsletterSettings::flag('require_double_opt_in');
                $input = [
                    'email' => $email,
                    'first_name' => (string) ($user->first_name ?? ''),
                    'last_name' => (string) ($user->last_name ?? ''),
                    'segment' => (string) ($_POST['segment'] ?? ''),
                    'locale' => $locale,
                    'source' => 'member',
                    'consent_text' => NewsletterSettings::localized('consent_text', $locale),
                ];
                if ($verified) {
                    $input['user_id'] = (int) $user->id;
                }
                $result = $repo->subscribe($input, $doubleOptIn);
                if (!empty($result['success'])) {
                    if ($verified) {
                        $repo->saveSubscriber(['user_id' => (int) $user->id] + ($repo->subscriber((int) $result['id']) ?? []), (int) $result['id']);
                    }
                    if (!empty($result['send_confirmation']) && ($row = $repo->subscriber((int) $result['id'])) !== null) {
                        NewsletterMailer::sendConfirmation($row);
                    }
                    $notice = ['success', (string) __($doubleOptIn ? 'newsletter.member.check_inbox' : 'newsletter.member.subscribed')];
                } else {
                    $notice = ['danger', (string) __('newsletter.member.error')];
                }
            }
        } elseif ($action === 'unsubscribe' && $current !== null) {
            $repo->unsubscribeById((int) $current['id']);
            $notice = ['success', (string) __('newsletter.member.unsubscribed')];
        }

        self::output($user, $notice);
    }

    /** @param array<string, mixed> $params */
    public static function render(object $user, array $params = []): void
    {
        self::output($user, null);
    }

    /**
     * Abonnement des Kontos: über die user_id, über die Konto-Adresse nur, wenn diese nachweislich bestätigt ist.
     * Sonst könnte ein Konto, das auf eine fremde Adresse registriert wurde, deren Abonnement einsehen,
     * abmelden oder an sich binden.
     *
     * @return array<string, mixed>|null
     */
    private static function find(object $user): ?array
    {
        $repo = NewsletterRepository::instance();
        $own = $repo->subscriberByUser((int) ($user->id ?? 0));
        if ($own !== null) {
            return $own;
        }
        $email = EmailVerificationService::getInstance()->matchableEmail((int) ($user->id ?? 0), (string) ($user->email ?? ''));

        return $email !== '' ? $repo->subscriberByEmail($email) : null;
    }

    private static function hasVerifiedEmail(object $user): bool
    {
        return EmailVerificationService::getInstance()->matchableEmail((int) ($user->id ?? 0), (string) ($user->email ?? '')) !== '';
    }

    /** @param array{0: string, 1: string}|null $notice */
    private static function output(object $user, ?array $notice): void
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $subscriber = self::find($user);
        $status = (string) ($subscriber['status'] ?? '');
        $locale = (string) ($subscriber['locale'] ?? NewsletterI18n::requestLocale());
        $segment = (string) ($subscriber['segment_slug'] ?? NewsletterSettings::get('default_segment'));
        $csrf = PluginDashboardRegistry::instance()->csrfField(self::SLUG);
        $action = '/member/plugin/' . self::SLUG;

        if ($notice !== null) {
            echo '<div class="alert alert-' . $e($notice[0]) . '" role="status">' . $e($notice[1]) . '</div>';
        }
        $statusKey = match ($status) {
            'active' => 'newsletter.member.state_active',
            'pending' => 'newsletter.member.state_pending',
            default => 'newsletter.member.state_none',
        };
        if ($subscriber === null && !self::hasVerifiedEmail($user)) {
            // Ohne bestätigte Adresse keine Aussage darüber, ob die Adresse den Newsletter bezieht.
            // Anmelden und Verwalten erst mit bestätigter Adresse: Ein hier per Double-Opt-In angelegtes
            // Abonnement bliebe ohne Kontobezug und wäre im Mitgliederbereich nicht sichtbar.
            echo '<p>' . $e(sprintf((string) __('newsletter.member.state_unverified'), (string) ($user->email ?? ''))) . '</p>';

            return;
        } else {
            echo '<p>' . $e(sprintf((string) __($statusKey), (string) ($user->email ?? ''))) . '</p>';
        }

        echo '<form method="post" action="' . $e($action) . '" class="mb-3">' . $csrf . '<input type="hidden" name="newsletter_action" value="subscribe">';
        $segments = NewsletterSettings::segments();
        if (count($segments) > 1) {
            echo '<div class="mb-3"><label class="form-label" for="memberNlSegment">' . $e(__('newsletter.public.segment')) . '</label><select class="form-select" id="memberNlSegment" name="segment">';
            foreach ($segments as $slug => $labels) {
                echo '<option value="' . $e($slug) . '"' . ($slug === $segment ? ' selected' : '') . '>' . $e($labels[$locale] ?? $labels['de']) . '</option>';
            }
            echo '</select></div>';
        }
        echo '<div class="mb-3"><label class="form-label" for="memberNlLocale">' . $e(__('newsletter.member.language')) . '</label><select class="form-select" id="memberNlLocale" name="locale">'
            . '<option value="de"' . ($locale === 'de' ? ' selected' : '') . '>Deutsch</option><option value="en"' . ($locale === 'en' ? ' selected' : '') . '>English</option></select></div>';
        if ($status !== 'active') {
            echo '<p class="small text-secondary">' . $e(NewsletterSettings::localized('consent_text', $locale)) . '</p>';
        }
        echo '<button type="submit" class="btn btn-primary">' . $e(__($status === 'active' ? 'newsletter.member.save' : 'newsletter.member.subscribe')) . '</button></form>';

        if (in_array($status, ['active', 'pending'], true)) {
            echo '<form method="post" action="' . $e($action) . '">' . $csrf . '<input type="hidden" name="newsletter_action" value="unsubscribe">'
                . '<button type="submit" class="btn btn-outline-danger">' . $e(__('newsletter.member.unsubscribe')) . '</button></form>';
        }
    }
}
