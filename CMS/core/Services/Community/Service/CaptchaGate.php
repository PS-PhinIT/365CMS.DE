<?php
/**
 * Captcha vor dem Schreiben (PR #5, E7; SECURITY-PRIVACY §2 Schicht 3): entscheidet, ob ein Mitglied für ein neues Thema oder eine
 * Antwort ein Captcha lösen muss, erzeugt die Aufgabe (`Security\CaptchaService`, Kontext `community.compose`) und prüft die Antwort.
 *
 * - `required()` nach Einstellung `captcha_mode`: `off` nie; `first_post` bis zum ersten veröffentlichten Thema oder Beitrag (wartende
 *   zählen nicht: wer nur Wartendes hat, löst weiter); `trust0` solange die Vertrauensstufe 0 ist; `always` immer. Gäste schreiben nicht
 *   (kein Captcha nötig), Bearbeiten braucht keins; globale Moderation (`admin`, `community.moderate`) ist ausgenommen.
 *   Der Filter `cms_community_captcha_required` (Wert, Mitglied, Aktion) darf nur verschärfen: `true` verlangt ein Captcha, `false` ändert nichts.
 * - Das Verfahren steht in `captcha_provider` (`pow`, `image`, `math`); ein nicht verfügbares fällt im Dienst auf die Rechenaufgabe zurück.
 * - Fehlerfall: Fehlschlag verbraucht die Aufgabe; der Controller zeigt das Formular mit neuer Aufgabe und dem Text aus `errorText()` (Core-Schlüssel
 *   `captcha.error.*`).
 *
 * Keine Datenbank beim Konstruieren; die Statistik wird nur gelesen, wenn der Modus sie braucht. Keine Uhr.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Hooks;
use CMS\Services\Community\Repository\UserStatsRepository;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Security\Captcha\CaptchaResult;
use CMS\Services\Security\Captcha\CaptchaText;
use CMS\Services\Security\CaptchaService;

if (!defined('ABSPATH')) {
    exit;
}

final class CaptchaGate
{
    public const CONTEXT = 'community.compose';
    public const FILTER = 'cms_community_captcha_required';
    /** Aktionen, die ein Captcha verlangen können. */
    public const ACTIONS = ['create_topic', 'reply'];

    private static ?self $instance = null;

    private Settings $settings;
    /** @var \Closure(): CaptchaService */
    private \Closure $captcha;
    /** @var \Closure(int): array{topics: int, posts: int, level: int, exempt: bool} */
    private \Closure $profile;
    /** @var \Closure(int): void Eintrag im Sicherheitsprotokoll nach einem Fehlschlag */
    private \Closure $logRejection;

    /**
     * @param \Closure(): CaptchaService|null $captcha Standard `CaptchaService::instance()`
     * @param \Closure(int): array{topics: int, posts: int, level: int, exempt: bool}|null $profile Standard: Statistik, Vertrauensstufe und globale Moderation über `CommunityAccess`
     * @param \Closure(int): void|null $logRejection Standard: `ContentGuard::logRejection('captcha_failed', …)`
     */
    public function __construct(?Settings $settings = null, ?\Closure $captcha = null, ?\Closure $profile = null, ?\Closure $logRejection = null)
    {
        $this->logRejection = $logRejection ?? static function (int $userId): void {
            ContentGuard::instance()->logRejection('captcha_failed', $userId);
        };
        $this->settings = $settings ?? Settings::getInstance();
        $this->captcha = $captcha ?? static fn (): CaptchaService => CaptchaService::instance();
        $this->profile = $profile ?? static function (int $userId): array {
            $stats = (new UserStatsRepository())->get($userId) ?? [];
            $access = CommunityAccess::instance();

            return [
                'topics' => (int) ($stats['topic_count'] ?? 0),
                'posts' => (int) ($stats['post_count'] ?? 0),
                'level' => (int) ($access->limitsFor($userId)['level'] ?? 0),
                'exempt' => $access->can($userId, 'moderate', [])->allowed,
            ];
        };
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Muss `$userId` für `$action` (`create_topic`, `reply`) ein Captcha lösen? Datenbankfehler lesen sich als „ja“ (fail-closed). */
    public function required(int $userId, string $action): bool
    {
        if ($userId < 1 || !in_array($action, self::ACTIONS, true)) {
            return false;
        }
        $mode = $this->settings->string('captcha_mode');
        $required = false;
        if ($mode !== 'off') {
            try {
                $profile = ($this->profile)($userId);
                $required = !$profile['exempt'] && match ($mode) {
                    'always' => true,
                    'first_post' => $profile['topics'] + $profile['posts'] === 0,
                    'trust0' => $profile['level'] === 0,
                    default => false,
                };
            } catch (\Throwable) {
                $required = true;
            }
        }
        try {
            if (Hooks::applyFilters(self::FILTER, $required, $userId, $action) === true) {
                $required = true;
            }
        } catch (\Throwable) {
            // Ein fehlerhafter Filter verschärft und lockert nichts.
        }

        return $required;
    }

    /** HTML-Fragment der neuen Aufgabe (Felder, Beschriftung; Skript und Stil lädt die Seite). */
    public function render(): string
    {
        return ($this->captcha)()->render(self::CONTEXT, $this->settings->string('captcha_provider'));
    }

    /**
     * Antwort aus den Formularfeldern prüfen (verbraucht die Aufgabe). Ein Fehlschlag kommt mit `captcha_failed` ins Sicherheitsprotokoll
     * (ohne Inhalt, gedrosselt wie die übrigen Ablehnungen); ein Fehler dabei bleibt ohne Folgen.
     *
     * @param array<string, mixed> $post
     */
    public function verify(array $post, int $userId = 0): CaptchaResult
    {
        $result = ($this->captcha)()->verify($post, self::CONTEXT);
        if (!$result->ok && $userId > 0) {
            try {
                ($this->logRejection)($userId);
            } catch (\Throwable) {
                // Das Protokoll darf die Antwort an das Mitglied nie verhindern.
            }
        }

        return $result;
    }

    /** Meldung für die Anzeige im Formular. */
    public static function errorText(CaptchaResult $result): string
    {
        return match ($result->code) {
            CaptchaResult::EXPIRED => CaptchaText::t('captcha.error.expired', 'Die Sicherheitsprüfung ist abgelaufen. Bitte löse die neue Aufgabe und sende noch einmal ab.'),
            CaptchaResult::UNAVAILABLE => CaptchaText::t('captcha.error.unavailable', 'Die Sicherheitsprüfung ist gerade nicht verfügbar. Bitte lade die Seite neu.'),
            default => CaptchaText::t('captcha.error.failed', 'Die Sicherheitsprüfung wurde nicht bestanden. Bitte löse die neue Aufgabe und sende noch einmal ab.'),
        };
    }
}
