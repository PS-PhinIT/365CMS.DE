<?php
/**
 * Newsletter im Mitgliederbereich: Status, An- und Abmeldung, Einstellungen und erneutes Senden der
 * Bestätigungsmail für das angemeldete Konto. Dashboard-Widget und Unterseite `/member/plugin/newsletter`
 * nutzen denselben Weg (NewsletterMemberSection ruft nur diesen Service auf).
 *
 * Regeln:
 * - Zuordnung über `user_id`, sonst über die bestätigte Konto-Adresse (EmailVerificationService::matchableEmail()).
 * - Double-Opt-In bei unbestätigter Konto-Adresse und bei unzustellbarer Adresse immer, sonst laut
 *   `require_double_opt_in`; `user_id` nur bei bestätigter Adresse.
 * - Anmeldung nur mit Einwilligung (`consent`); gespeichert wird der Einwilligungstext in der Sprache der Oberfläche.
 * - Namen aus `user_meta` (first_name, last_name) über MemberService::getUserMeta().
 * - Höchstens RATE_MAX Aktionen je Stunde und Konto (CacheManager), Bestätigungsmail höchstens alle 10 Minuten.
 * - Jede Aktion landet im Audit-Log (NewsletterConsentLog, Quelle `member`).
 *
 * Ergebnis aller Aktionen: array{success: bool, type: 'success'|'danger'|'warning'|'info', code: string, message: string}.
 *
 * @package CMS\Services\Newsletter
 */

declare(strict_types=1);

namespace CMS\Services\Newsletter;

use CMS\CacheManager;
use CMS\Services\ContentLocalizationService;
use CMS\Services\EmailVerificationService;
use CMS\Services\MemberService;

if (!defined('ABSPATH')) {
    exit;
}

final class NewsletterMemberService
{
    public const RATE_MAX = 10;
    public const RATE_WINDOW = 3600;
    public const ACTIONS = ['subscribe', 'update', 'resend', 'unsubscribe'];
    /** Erlaubte Rücksprungziele nach einem POST (`return_to`). */
    public const RETURN_TARGETS = [
        'dashboard' => '/member/dashboard#newsletter',
        'section' => '/member/plugin/newsletter',
    ];
    /** Sprachen, die der Newsletter versenden kann (Spalte `locale`). */
    public const SUPPORTED_LOCALES = ['de' => 'Deutsch', 'en' => 'English'];

    private static ?self $instance = null;

    private NewsletterRepository $repo;
    /** @var \Closure(object): string */
    private \Closure $verifiedEmail;
    /** @var \Closure(int): array<string, mixed> */
    private \Closure $userMeta;
    /** @var \Closure(array<string, mixed>): bool */
    private \Closure $sendConfirmation;
    /** @var \Closure(): list<string> */
    private \Closure $contentLocales;
    /** @var \Closure(): string */
    private \Closure $uiLocale;
    /** @var \Closure(): int */
    private \Closure $clock;
    private ?object $rateStore;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Abhängigkeiten sind für Tests austauschbar: `repository`, `verified_email`, `user_meta`, `send_confirmation`,
     * `content_locales`, `ui_locale`, `clock` (Closures) und `rate_store` (Objekt mit get()/set()).
     *
     * @param array<string, mixed> $deps
     */
    public function __construct(array $deps = [])
    {
        $this->repo = $deps['repository'] ?? NewsletterRepository::instance();
        $this->verifiedEmail = $deps['verified_email'] ?? static fn (object $user): string => EmailVerificationService::getInstance()
            ->matchableEmail((int) ($user->id ?? 0), (string) ($user->email ?? ''));
        $this->userMeta = $deps['user_meta'] ?? static fn (int $userId): array => $userId > 0 ? MemberService::getInstance()->getUserMeta($userId) : [];
        $this->sendConfirmation = $deps['send_confirmation'] ?? static fn (array $subscriber): bool => NewsletterMailer::sendConfirmation($subscriber);
        $this->contentLocales = $deps['content_locales'] ?? static fn (): array => ContentLocalizationService::getInstance()->getContentLocales();
        $this->uiLocale = $deps['ui_locale'] ?? static fn (): string => NewsletterI18n::requestLocale();
        $this->clock = $deps['clock'] ?? static fn (): int => time();
        $this->rateStore = array_key_exists('rate_store', $deps) ? $deps['rate_store'] : null;
    }

    // ── Status ──────────────────────────────────────────────────────────────

    /**
     * Status des Kontos für Widget und Unterseite.
     *
     * `status`: none | unverified (kein Abo, Konto-Adresse unbestätigt) | pending | active | unsubscribed | bounced.
     *
     * @return array{status: string, email: string, segment: string, segment_label: string, locale: string,
     *   confirmed_at: ?string, optin_sent_at: ?string, can_resend: bool, resend_wait: int, verified: bool,
     *   double_opt_in: bool, subscriber_id: int}
     */
    public function statusFor(object $user): array
    {
        $subscriber = $this->findSubscriber($user);
        $verified = $this->hasVerifiedEmail($user);
        $status = (string) ($subscriber['status'] ?? '');
        if (!in_array($status, NewsletterRepository::SUBSCRIBER_STATUSES, true)) {
            $status = $verified ? 'none' : 'unverified';
        }
        $locale = $this->normalizeLocale((string) ($subscriber['locale'] ?? ''), $this->uiLocale());
        $segment = (string) ($subscriber['segment_slug'] ?? '') ?: $this->defaultSegment();
        $segments = NewsletterSettings::segments();
        $wait = $status === 'pending' && $subscriber !== null
            ? NewsletterRepository::confirmationResendWait($subscriber, ($this->clock)())
            : 0;

        return [
            'status' => $status,
            'email' => NewsletterRepository::normalizeEmail((string) ($user->email ?? '')) ?: (string) ($user->email ?? ''),
            'segment' => $segment,
            'segment_label' => (string) ($segments[$segment][$locale] ?? $segments[$segment]['de'] ?? $segment),
            'locale' => $locale,
            'confirmed_at' => self::nullableString($subscriber['confirmed_at'] ?? null),
            'optin_sent_at' => self::nullableString($subscriber['optin_sent_at'] ?? null) ?? ($status === 'pending' ? self::nullableString($subscriber['created_at'] ?? null) : null),
            'can_resend' => $status === 'pending' && $wait === 0,
            'resend_wait' => $wait,
            'verified' => $verified,
            'double_opt_in' => $this->requiresDoubleOptIn($verified, $status),
            'subscriber_id' => (int) ($subscriber['id'] ?? 0),
        ];
    }

    /**
     * Abonnement des Kontos: über die user_id, über die Konto-Adresse nur, wenn diese nachweislich bestätigt ist.
     * Sonst könnte ein Konto, das auf eine fremde Adresse registriert wurde, deren Abonnement einsehen,
     * abmelden oder an sich binden.
     *
     * @return array<string, mixed>|null
     */
    public function findSubscriber(object $user): ?array
    {
        $own = $this->repo->subscriberByUser((int) ($user->id ?? 0));
        if ($own !== null) {
            return $own;
        }
        $email = ($this->verifiedEmail)($user);

        return $email !== '' ? $this->repo->subscriberByEmail($email) : null;
    }

    public function hasVerifiedEmail(object $user): bool
    {
        return ($this->verifiedEmail)($user) !== '';
    }

    // ── Aktionen ────────────────────────────────────────────────────────────

    /**
     * Einstieg für POST-Anfragen (Widget und Unterseite): Rate-Limit, dann die Aktion.
     *
     * @param array<string, mixed> $input `consent`, `segment`, `locale`
     * @return array{success: bool, type: string, code: string, message: string}
     */
    public function handle(object $user, string $action, array $input): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return $this->result(false, 'danger', 'invalid_action');
        }
        if ($this->rateLimited((int) ($user->id ?? 0))) {
            return $this->result(false, 'warning', 'rate_limited');
        }

        return match ($action) {
            'subscribe' => $this->subscribeMember($user, $input),
            'update' => $this->updatePreferences($user, $input),
            'resend' => $this->resendConfirmation($user),
            default => $this->unsubscribeMember($user),
        };
    }

    /**
     * Anmelden bzw. erneut anmelden; bei aktivem Abonnement werden nur Thema und Sprache gespeichert.
     *
     * @param array<string, mixed> $input
     * @return array{success: bool, type: string, code: string, message: string}
     */
    public function subscribeMember(object $user, array $input): array
    {
        $email = NewsletterRepository::normalizeEmail((string) ($user->email ?? ''));
        if ($email === '') {
            return $this->result(false, 'danger', 'no_email');
        }
        $current = $this->findSubscriber($user);
        if ($current !== null && ($current['status'] ?? '') === 'active') {
            return $this->updatePreferences($user, $input);
        }
        if (!self::truthy($input['consent'] ?? null)) {
            return $this->result(false, 'danger', 'consent_required');
        }

        $verified = $this->hasVerifiedEmail($user);
        $doubleOptIn = $this->requiresDoubleOptIn($verified, (string) ($current['status'] ?? ''));
        $uiLocale = $this->uiLocale();
        $locale = $this->normalizeLocale(is_scalar($input['locale'] ?? null) ? (string) $input['locale'] : '', (string) ($current['locale'] ?? '') ?: $uiLocale);
        $meta = ($this->userMeta)((int) ($user->id ?? 0));
        $payload = [
            'email' => $email,
            'first_name' => (string) ($meta['first_name'] ?? ''),
            'last_name' => (string) ($meta['last_name'] ?? ''),
            'segment' => $this->chosenSegment($input, (string) ($current['segment_slug'] ?? '')),
            'locale' => $locale,
            'source' => 'member',
            // Wortlaut, den das Mitglied gesehen hat: Sprache der Oberfläche.
            'consent_text' => $this->consentText($uiLocale),
        ];
        if ($verified) {
            $payload['user_id'] = (int) ($user->id ?? 0);
        }

        $result = $this->repo->subscribe($payload, $doubleOptIn);
        if (empty($result['success'])) {
            return $this->result(false, 'danger', 'error');
        }
        $id = (int) ($result['id'] ?? 0);
        if ($verified && ($row = $this->repo->subscriber($id)) !== null && (int) ($row['user_id'] ?? 0) !== (int) $user->id) {
            $this->repo->saveSubscriber(['user_id' => (int) $user->id] + $row, $id);
        }
        $row = $this->repo->subscriber($id);
        if (!empty($result['send_confirmation']) && $row !== null) {
            ($this->sendConfirmation)($row);
        }
        if ($row !== null) {
            NewsletterConsentLog::record('subscribe', $row, 'member', ['double_opt_in' => $doubleOptIn, 'verified_account_email' => $verified]);
        }

        // Unbestätigte Konto-Adresse: dieselbe Meldung, egal ob die Adresse schon eingetragen ist.
        return $this->result(true, 'success', $doubleOptIn ? 'check_inbox' : 'subscribed');
    }

    /**
     * Thema und Sprache eines aktiven Abonnements ändern.
     *
     * @param array<string, mixed> $input
     * @return array{success: bool, type: string, code: string, message: string}
     */
    public function updatePreferences(object $user, array $input): array
    {
        $current = $this->findSubscriber($user);
        if ($current === null || ($current['status'] ?? '') !== 'active') {
            return $this->result(false, 'danger', 'not_subscribed');
        }
        $segment = $this->chosenSegment($input, (string) ($current['segment_slug'] ?? ''));
        $locale = $this->normalizeLocale(is_scalar($input['locale'] ?? null) ? (string) $input['locale'] : '', (string) ($current['locale'] ?? 'de'));
        $saved = $this->repo->saveSubscriber(['segment_slug' => $segment, 'locale' => $locale, 'user_id' => (int) ($user->id ?? 0)] + $current, (int) $current['id']);
        if (empty($saved['success'])) {
            return $this->result(false, 'danger', 'error');
        }
        if ($segment !== (string) ($current['segment_slug'] ?? '') || $locale !== (string) ($current['locale'] ?? '')) {
            NewsletterConsentLog::record('preferences', ['segment_slug' => $segment, 'locale' => $locale] + $current, 'member');
        }

        return $this->result(true, 'success', 'saved');
    }

    /**
     * Bestätigungsmail für eine unbestätigte Anmeldung erneut senden (höchstens alle 10 Minuten).
     *
     * @return array{success: bool, type: string, code: string, message: string}
     */
    public function resendConfirmation(object $user): array
    {
        $current = $this->findSubscriber($user);
        if ($current === null || ($current['status'] ?? '') !== 'pending') {
            return $this->result(false, 'danger', 'not_pending');
        }
        $wait = NewsletterRepository::confirmationResendWait($current, ($this->clock)());
        if ($wait > 0) {
            return $this->result(false, 'warning', 'resend_locked', (int) ceil($wait / 60));
        }
        if (!($this->sendConfirmation)($current)) {
            return $this->result(false, 'danger', 'resend_failed');
        }
        NewsletterConsentLog::record('resend', $current, 'member');

        return $this->result(true, 'success', 'resent');
    }

    /**
     * Abmelden (aktiv) bzw. Anmeldung zurückziehen (unbestätigt).
     *
     * @return array{success: bool, type: string, code: string, message: string}
     */
    public function unsubscribeMember(object $user): array
    {
        $current = $this->findSubscriber($user);
        $previous = (string) ($current['status'] ?? '');
        if ($current === null || !in_array($previous, ['active', 'pending'], true)) {
            return $this->result(false, 'danger', 'not_subscribed');
        }
        $this->repo->unsubscribeById((int) $current['id']);
        NewsletterConsentLog::record('unsubscribe', ['status' => 'unsubscribed'] + $current, 'member', ['previous_status' => $previous]);

        return $this->result(true, 'success', $previous === 'pending' ? 'withdrawn' : 'unsubscribed');
    }

    // ── Rate-Limit ──────────────────────────────────────────────────────────

    /** Zählt die Aktion und meldet, ob das Konto das Limit (RATE_MAX je RATE_WINDOW) überschritten hat. */
    public function rateLimited(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            $store = $this->rateStore ?? CacheManager::instance();
            $key = 'newsletter_member_rl_' . $userId;
            $now = ($this->clock)();
            $hits = array_values(array_filter((array) $store->get($key, []), static fn (mixed $ts): bool => is_int($ts) && $ts > $now - self::RATE_WINDOW));
            if (count($hits) >= self::RATE_MAX) {
                return true;
            }
            $hits[] = $now;
            $store->set($key, $hits, self::RATE_WINDOW);
        } catch (\Throwable) {
            // Ohne Cache kein Limit – die Sperre für Bestätigungsmails gilt weiter.
        }

        return false;
    }

    // ── Auswahl und Texte ───────────────────────────────────────────────────

    /** Rücksprungziel aus `return_to` (nur Werte aus RETURN_TARGETS, Standard: Unterseite). */
    public static function returnTarget(mixed $value): string
    {
        $key = is_string($value) && isset(self::RETURN_TARGETS[$value]) ? $value : 'section';

        return self::RETURN_TARGETS[$key];
    }

    /**
     * Themen zur Auswahl (Schlüssel => Name in `$locale`); leer, wenn `show_segment_choice` aus ist oder nur ein
     * Thema existiert.
     *
     * @return array<string, string>
     */
    public function segmentChoices(string $locale): array
    {
        $segments = NewsletterSettings::segments();
        if (!NewsletterSettings::flag('show_segment_choice') || count($segments) < 2) {
            return [];
        }
        $choices = [];
        foreach ($segments as $slug => $labels) {
            $choices[$slug] = (string) ($labels[$locale] ?? $labels['de']);
        }

        return $choices;
    }

    /**
     * Sprachen zur Auswahl: Deutsch plus die aktiven Inhaltssprachen, soweit der Newsletter sie versenden kann.
     *
     * @return array<string, string>
     */
    public function localeChoices(): array
    {
        $choices = ['de' => self::SUPPORTED_LOCALES['de']];
        try {
            foreach (($this->contentLocales)() as $locale) {
                $locale = strtolower(trim((string) $locale));
                if (isset(self::SUPPORTED_LOCALES[$locale])) {
                    $choices[$locale] = self::SUPPORTED_LOCALES[$locale];
                }
            }
        } catch (\Throwable) {
        }

        return $choices;
    }

    public function consentText(string $locale): string
    {
        return NewsletterSettings::localized('consent_text', $locale);
    }

    public function uiLocale(): string
    {
        try {
            return NewsletterI18n::locale(($this->uiLocale)());
        } catch (\Throwable) {
            return 'de';
        }
    }

    // ── intern ──────────────────────────────────────────────────────────────

    private function requiresDoubleOptIn(bool $verified, string $status): bool
    {
        return !$verified || $status === 'bounced' || NewsletterSettings::flag('require_double_opt_in');
    }

    private function normalizeLocale(string $locale, string $fallback): string
    {
        $choices = $this->localeChoices();
        if (isset($choices[$locale])) {
            return $locale;
        }

        return isset(self::SUPPORTED_LOCALES[$fallback]) ? $fallback : 'de';
    }

    /** @param array<string, mixed> $input */
    private function chosenSegment(array $input, string $current): string
    {
        $choices = $this->segmentChoices('de');
        $wanted = is_scalar($input['segment'] ?? null) ? NewsletterSettings::slug((string) $input['segment']) : '';
        if ($wanted !== '' && isset($choices[$wanted])) {
            return $wanted;
        }

        return $current !== '' ? $current : $this->defaultSegment();
    }

    private function defaultSegment(): string
    {
        return NewsletterSettings::slug(NewsletterSettings::get('default_segment')) ?: 'general';
    }

    private static function truthy(mixed $value): bool
    {
        return is_scalar($value) && in_array((string) $value, ['1', 'on', 'yes', 'true'], true);
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * Ergebnis mit Meldung `newsletter.member.<code>` in der Sprache der Oberfläche.
     *
     * @return array{success: bool, type: string, code: string, message: string}
     */
    private function result(bool $success, string $type, string $code, int|string ...$args): array
    {
        return ['success' => $success, 'type' => $type, 'code' => $code, 'message' => NewsletterI18n::t('newsletter.member.' . $code, $this->uiLocale(), ...$args)];
    }
}
