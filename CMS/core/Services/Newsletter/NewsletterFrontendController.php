<?php
/**
 * Öffentliche Newsletter-Seiten (jeweils auch unter `/en/…`):
 *
 * - `GET  /newsletter`                     Anmeldung, Hinweise, Archiv versendeter (öffentlicher) Ausgaben
 * - `POST /newsletter/subscribe`           Anmeldung (CSRF, Honeypot, Einwilligung, Rate-Limit)
 * - `GET  /newsletter/confirm/<token>`     Bestätigungsseite mit Knopf; `POST` bestätigt
 * - `GET  /newsletter/unsubscribe/<token>` Abmeldeseite mit Knopf; `POST` meldet ab (auch RFC-8058-One-Click)
 * - `GET  /newsletter/issue/<id>`          Webversion einer Ausgabe (öffentlich oder mit Empfänger-Token `?t=`)
 *
 * Bestätigen und Abmelden passieren bewusst erst per POST: Mailfilter (z. B. Microsoft Safe Links) rufen Links
 * vorab per GET auf und würden sonst Anmeldungen bestätigen oder Abonnenten abmelden.
 * Die Rückmeldung nach der Anmeldung ist für neue, bestehende und abgemeldete Adressen gleich, damit sich
 * nicht ausforschen lässt, wer den Newsletter bezieht.
 *
 * @package CMSv2\Core\Services\Newsletter
 */

declare(strict_types=1);

namespace CMS\Services\Newsletter;

use CMS\CacheManager;
use CMS\Security;
use CMS\ThemeManager;

if (!defined('ABSPATH')) {
    exit;
}

final class NewsletterFrontendController
{
    private const CSRF_ACTION = 'newsletter_subscribe';
    private const NOTICES = ['check-inbox', 'subscribed', 'confirmed', 'unsubscribed', 'invalid', 'consent', 'rate-limit', 'error', 'expired'];
    private const RATE_WINDOW = 3600;
    private const RATE_MAX = 6;

    /** @param object $router */
    public static function registerRoutes(object $router): void
    {
        foreach (['', '/en'] as $prefix) {
            $router->addRoute('GET', $prefix . '/newsletter', [self::class, 'page']);
            $router->addRoute('POST', $prefix . '/newsletter/subscribe', [self::class, 'subscribe']);
            $router->addRoute('GET', $prefix . '/newsletter/confirm/:token', [self::class, 'confirmPage']);
            $router->addRoute('POST', $prefix . '/newsletter/confirm/:token', [self::class, 'confirm']);
            $router->addRoute('GET', $prefix . '/newsletter/unsubscribe/:token', [self::class, 'unsubscribePage']);
            $router->addRoute('POST', $prefix . '/newsletter/unsubscribe/:token', [self::class, 'unsubscribe']);
            $router->addRoute('GET', $prefix . '/newsletter/issue/:id', [self::class, 'issue']);
        }
    }

    public static function page(): void
    {
        $locale = NewsletterI18n::requestLocale();
        $notice = in_array($_GET['notice'] ?? '', self::NOTICES, true) ? (string) $_GET['notice'] : '';
        $archive = NewsletterSettings::flag('public_archive') ? NewsletterRepository::instance()->publicCampaigns() : [];
        self::render('page', NewsletterSettings::localized('archive_title', $locale) ?: 'Newsletter', [
            'locale' => $locale,
            'notice' => $notice,
            'form' => self::form($locale, 'page'),
            'archive' => $archive,
        ]);
    }

    /** Anmeldeformular (Seite und Editor-Block). */
    public static function form(string $locale, string $context = 'page', string $returnTo = ''): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $t = static fn (string $k): string => $e(NewsletterI18n::t($k, $locale));
        $segments = NewsletterSettings::segments();
        $id = 'nl-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $html = '<form class="cms-newsletter-form" method="post" action="' . $e(NewsletterI18n::path('/newsletter/subscribe', $locale)) . '">'
            . '<input type="hidden" name="csrf_token" value="' . $e(Security::instance()->generateToken(self::CSRF_ACTION)) . '">'
            . ($returnTo !== '' ? '<input type="hidden" name="return_to" value="' . $e($returnTo) . '">' : '')
            . '<div class="cms-newsletter-form__hp" aria-hidden="true"><label>Website <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label></div>';
        if (NewsletterSettings::flag('show_name_fields')) {
            $html .= '<div class="cms-newsletter-form__row">'
                . '<p class="cms-newsletter-form__field"><label for="' . $id . '-fn">' . $t('newsletter.public.first_name') . ' <span class="cms-newsletter-form__optional">' . $t('newsletter.public.optional') . '</span></label>'
                . '<input id="' . $id . '-fn" type="text" name="first_name" maxlength="120" autocomplete="given-name"></p>'
                . '<p class="cms-newsletter-form__field"><label for="' . $id . '-ln">' . $t('newsletter.public.last_name') . ' <span class="cms-newsletter-form__optional">' . $t('newsletter.public.optional') . '</span></label>'
                . '<input id="' . $id . '-ln" type="text" name="last_name" maxlength="120" autocomplete="family-name"></p></div>';
        }
        $html .= '<p class="cms-newsletter-form__field"><label for="' . $id . '-em">' . $t('newsletter.public.email') . '</label>'
            . '<input id="' . $id . '-em" type="email" name="email" maxlength="190" required autocomplete="email"></p>';
        if (NewsletterSettings::flag('show_segment_choice') && count($segments) > 1) {
            $html .= '<p class="cms-newsletter-form__field"><label for="' . $id . '-sg">' . $t('newsletter.public.segment') . '</label><select id="' . $id . '-sg" name="segment">';
            foreach ($segments as $slug => $labels) {
                $html .= '<option value="' . $e($slug) . '"' . ($slug === NewsletterSettings::get('default_segment') ? ' selected' : '') . '>' . $e($labels[$locale] ?? $labels['de']) . '</option>';
            }
            $html .= '</select></p>';
        }
        $consent = NewsletterSettings::localized('consent_text', $locale);
        $privacy = self::privacyUrl($locale);
        $html .= '<p class="cms-newsletter-form__consent"><label><input type="checkbox" name="consent" value="1" required> ' . $e($consent)
            . ($privacy !== '' ? ' <a href="' . $e($privacy) . '">' . $t('newsletter.public.privacy') . '</a>' : '') . '</label></p>'
            . '<p><button type="submit" class="cms-newsletter-form__submit">' . $t('newsletter.public.submit') . '</button></p>';
        $note = NewsletterSettings::localized('footer_note', $locale);
        if ($note !== '') {
            $html .= '<p class="cms-newsletter-form__note">' . $e($note) . '</p>';
        }

        return $html . '</form>';
    }

    public static function subscribe(): void
    {
        $locale = NewsletterI18n::requestLocale();
        $returnTo = self::safeReturn((string) ($_POST['return_to'] ?? ''));
        // Honeypot: so tun, als hätte es geklappt.
        if (trim((string) ($_POST['website'] ?? '')) !== '') {
            self::redirect($locale, 'check-inbox', $returnTo);
        }
        if (!Security::instance()->verifyToken((string) ($_POST['csrf_token'] ?? ''), self::CSRF_ACTION)) {
            self::redirect($locale, 'error', $returnTo);
        }
        if (self::rateLimited()) {
            self::redirect($locale, 'rate-limit', $returnTo);
        }
        $email = NewsletterRepository::normalizeEmail((string) ($_POST['email'] ?? ''));
        if ($email === '') {
            self::redirect($locale, 'invalid', $returnTo);
        }
        if (empty($_POST['consent'])) {
            self::redirect($locale, 'consent', $returnTo);
        }

        $doubleOptIn = NewsletterSettings::flag('require_double_opt_in');
        $repo = NewsletterRepository::instance();
        $result = $repo->subscribe([
            'email' => $email,
            'first_name' => (string) ($_POST['first_name'] ?? ''),
            'last_name' => (string) ($_POST['last_name'] ?? ''),
            'segment' => (string) ($_POST['segment'] ?? ''),
            'locale' => $locale,
            'source' => $returnTo !== '' ? 'block' : 'public',
            'consent_text' => NewsletterSettings::localized('consent_text', $locale),
        ], $doubleOptIn);
        if (empty($result['success'])) {
            self::redirect($locale, 'error', $returnTo);
        }
        if (!empty($result['send_confirmation'])) {
            $subscriber = $repo->subscriber((int) $result['id']);
            if ($subscriber !== null) {
                NewsletterMailer::sendConfirmation($subscriber);
            }
        }
        self::redirect($locale, $doubleOptIn ? 'check-inbox' : 'subscribed', $returnTo);
    }

    public static function confirmPage(string $token): void
    {
        $locale = NewsletterI18n::requestLocale();
        $subscriber = NewsletterRepository::instance()->subscriberByToken($token);
        if ($subscriber !== null && $subscriber['status'] === 'active') {
            self::redirect($locale, 'confirmed');
        }
        self::actionPage($locale, $subscriber !== null && $subscriber['status'] === 'pending', 'confirm', $token);
    }

    public static function confirm(string $token): void
    {
        $locale = NewsletterI18n::requestLocale();
        $subscriber = NewsletterRepository::instance()->confirm($token);
        self::redirect($locale, $subscriber !== null ? 'confirmed' : 'expired');
    }

    public static function unsubscribePage(string $token): void
    {
        $locale = NewsletterI18n::requestLocale();
        $subscriber = NewsletterRepository::instance()->subscriberByToken($token);
        if ($subscriber !== null && $subscriber['status'] === 'unsubscribed') {
            self::redirect($locale, 'unsubscribed');
        }
        self::actionPage($locale, $subscriber !== null, 'unsubscribe', $token);
    }

    /** Abmeldung per POST – vom Formular der Abmeldeseite oder als One-Click-Abmeldung des Postfachanbieters. */
    public static function unsubscribe(string $token): void
    {
        $subscriber = NewsletterRepository::instance()->unsubscribe($token);
        $oneClick = str_contains((string) ($_POST['List-Unsubscribe'] ?? ''), 'One-Click');
        if ($oneClick) {
            http_response_code($subscriber !== null ? 200 : 404);
            header('Content-Type: text/plain; charset=utf-8');
            echo $subscriber !== null ? 'Unsubscribed' : 'Unknown token';
            exit;
        }
        self::redirect(NewsletterI18n::requestLocale(), $subscriber !== null ? 'unsubscribed' : 'expired');
    }

    public static function issue(string $id): void
    {
        $locale = NewsletterI18n::requestLocale();
        $repo = NewsletterRepository::instance();
        $campaign = ctype_digit($id) ? $repo->campaign((int) $id) : null;
        $subscriber = is_string($_GET['t'] ?? null) ? $repo->subscriberByToken($_GET['t']) : null;
        $allowed = $campaign !== null && $campaign['status'] === 'sent'
            && ((int) $campaign['is_public'] === 1 || ($subscriber !== null && self::received((int) $campaign['id'], (int) $subscriber['id'])));
        if (!$allowed) {
            http_response_code(404);
            self::render('issue', NewsletterI18n::t('newsletter.public.not_found', $locale), ['locale' => $locale, 'campaign' => null, 'html' => '']);

            return;
        }
        header('X-Robots-Tag: ' . ((int) $campaign['is_public'] === 1 ? 'index, follow' : 'noindex, nofollow'));
        self::render('issue', (string) $campaign['subject'], [
            'locale' => $locale,
            'campaign' => $campaign,
            'html' => NewsletterMailer::renderWebVersion($campaign, $subscriber, $locale),
        ]);
    }

    public static function renderNavItem(string $context = 'desktop'): void
    {
        if (!NewsletterSettings::flag('show_nav_link')) {
            return;
        }
        $locale = NewsletterI18n::requestLocale();
        $href = htmlspecialchars(NewsletterI18n::path('/newsletter', $locale), ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars(NewsletterSettings::localized('nav_label', $locale) ?: 'Newsletter', ENT_QUOTES, 'UTF-8');
        echo $context === 'mobile'
            ? '<a href="' . $href . '" class="mobile-menu__newsletter-link">' . $label . '</a>'
            : '<a href="' . $href . '" class="main-nav__link">' . $label . '</a>';
    }

    /** Liegt eine öffentliche Newsletter-Seite vor (Stylesheet laden)? */
    public static function isNewsletterRequest(): bool
    {
        $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');

        return (bool) preg_match('#^(en/)?newsletter(/|$)#', $path);
    }

    // ── intern ──────────────────────────────────────────────────────────────

    private static function actionPage(string $locale, bool $valid, string $action, string $token): void
    {
        $title = NewsletterI18n::t('newsletter.public.' . $action . '_title', $locale);
        self::render('action', $title, [
            'locale' => $locale,
            'valid' => $valid,
            'action' => $action,
            'url' => NewsletterI18n::path('/newsletter/' . $action . '/' . rawurlencode(NewsletterRepository::normalizeToken($token)), $locale),
        ]);
    }

    /** @param array<string, mixed> $vars */
    private static function render(string $view, string $title, array $vars): void
    {
        header('Cache-Control: no-store, private');
        $theme = ThemeManager::instance();
        $theme->getHeader(['title' => $title]);
        $newsletter = $vars + ['title' => $title];
        include self::viewPath($view);
        $theme->getFooter();
    }

    private static function viewPath(string $view): string
    {
        try {
            $themeFile = rtrim(ThemeManager::instance()->getThemePath(), '/') . '/newsletter/' . $view . '.php';
            if (is_file($themeFile)) {
                return $themeFile;
            }
        } catch (\Throwable) {
        }

        return ABSPATH . 'views/newsletter/' . $view . '.php';
    }

    private static function redirect(string $locale, string $notice, string $returnTo = ''): never
    {
        $base = $returnTo !== '' ? $returnTo : NewsletterI18n::path('/newsletter', $locale);
        $url = $base . (str_contains($base, '?') ? '&' : '?') . 'notice=' . rawurlencode($notice) . ($returnTo !== '' ? '#newsletter' : '');
        header('Location: ' . $url, true, 303);
        exit;
    }

    /** Nur Pfade dieser Website (für den Editor-Block). */
    private static function safeReturn(string $path): string
    {
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\r\n]/', $path) === 1) {
            return '';
        }

        return (string) strtok($path, '?#');
    }

    private static function rateLimited(): bool
    {
        try {
            $ip = Security::getClientIp();
            if ($ip === '') {
                return false;
            }
            $cache = CacheManager::instance();
            $key = 'newsletter_rl_' . hash('sha256', $ip);
            $now = time();
            $hits = array_values(array_filter((array) $cache->get($key, []), static fn (mixed $ts): bool => is_int($ts) && $ts > $now - self::RATE_WINDOW));
            if (count($hits) >= self::RATE_MAX) {
                return true;
            }
            $hits[] = $now;
            $cache->set($key, $hits, self::RATE_WINDOW);
        } catch (\Throwable) {
        }

        return false;
    }

    private static function received(int $campaignId, int $subscriberId): bool
    {
        $db = \CMS\Database::instance();

        return (int) $db->get_var("SELECT COUNT(*) FROM {$db->getPrefix()}newsletter_sends WHERE campaign_id = ? AND subscriber_id = ? AND status = 'sent'", [$campaignId, $subscriberId]) > 0;
    }

    /** Gleiche Datenschutz-Adresse wie bei den Formularen (Einstellungen → Formulare). */
    private static function privacyUrl(string $locale): string
    {
        try {
            return \CMS\Services\Forms\FormSettings::privacyUrl();
        } catch (\Throwable) {
            return NewsletterI18n::path('/datenschutz', $locale);
        }
    }
}
