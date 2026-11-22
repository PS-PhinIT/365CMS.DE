<?php
/**
 * Captcha-Dienst des Core (E7): ein Einstieg für Community, Formulare und Registrierung, nur lokale Verfahren, keine Drittanbieter.
 *
 * - Verfahren: `pow` (Proof-of-Work, empfohlener Standard), `image` (Bild, GD) und `math` (Rechenaufgabe); jedes außer `math` bringt die
 *   Rechenaufgabe als gleichwertige Alternative mit (WCAG 1.1.1). Weitere Verfahren melden sich über den Filter `cms_captcha_providers`
 *   (Liste `CaptchaProvider` je Kennung); ein Verfahren, das Einwilligung braucht (`requiresConsent()`), wird nie automatisch gewählt.
 * - Auswahl: `provider($id)` liefert das gewünschte Verfahren, wenn es bekannt und `available()` ist, sonst „math“ (kein stilles
 *   Abschalten des Schutzes, kein Fehler für Besucher).
 * - `render($context, $id)` legt eine Aufgabe an (nur in der Sitzung, `CaptchaStore`) und liefert das HTML-Fragment; `verify($request,
 *   $context)` prüft die Antwort der Anfrage (Feld `cms_captcha_id` nennt die Aufgabe). Die Aufgabe ist danach verbraucht, auch bei einer
 *   falschen Antwort; sie gilt nur für den Kontext, für den sie angelegt wurde (z. B. `community.compose`), und höchstens TTL Sekunden.
 *   Das Verfahren steht in der Aufgabe (vom Server gewählt), nie in der Anfrage.
 * - Zufall und Uhr sind austauschbar (Tests); Standard `random_int()`, `random_bytes()`, `time()`.
 * - Skript und Stil: `assets/js/captcha/captcha.js` (ES-Modul, löst Proof-of-Work mit Web Crypto, blendet die Rechenaufgabe aus, solange
 *   es arbeitet) und `assets/css/captcha.css`; beide lädt die Seite, die das Fragment einbettet (`assetUrls()`).
 *
 * @package CMSv2\Core\Services\Security
 */

declare(strict_types=1);

namespace CMS\Services\Security;

use CMS\Hooks;
use CMS\Services\Security\Captcha\CaptchaProvider;
use CMS\Services\Security\Captcha\CaptchaResult;
use CMS\Services\Security\Captcha\CaptchaStore;
use CMS\Services\Security\Captcha\CaptchaText;
use CMS\Services\Security\Captcha\ImageProvider;
use CMS\Services\Security\Captcha\MathProvider;
use CMS\Services\Security\Captcha\ProofOfWorkProvider;

if (!defined('ABSPATH')) {
    exit;
}

final class CaptchaService
{
    public const FIELD_ID = 'cms_captcha_id';
    public const FIELD_POW = 'cms_captcha_pow';
    public const FIELD_ANSWER = 'cms_captcha_answer';
    public const FIELD_TEXT = 'cms_captcha_text';
    public const FILTER_PROVIDERS = 'cms_captcha_providers';
    public const FALLBACK = MathProvider::ID;
    /** Lebensdauer einer Aufgabe in Sekunden (ein langer Beitrag braucht Zeit). */
    public const TTL = 7200;

    private static ?self $instance = null;

    private CaptchaStore $store;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(int, int): int */
    private \Closure $int;
    /** @var \Closure(int): string */
    private \Closure $bytes;
    /** @var array<string, CaptchaProvider>|null */
    private ?array $providers;

    /**
     * @param \Closure(): int|null $clock Standard `time()`
     * @param \Closure(int, int): int|null $int Standard `random_int()`
     * @param \Closure(int): string|null $bytes Standard `random_bytes()`
     * @param array<string, CaptchaProvider>|null $providers feste Verfahren (Tests); null = Standardverfahren und Filter
     */
    public function __construct(?CaptchaStore $store = null, ?\Closure $clock = null, ?\Closure $int = null, ?\Closure $bytes = null, ?array $providers = null)
    {
        $this->store = $store ?? CaptchaStore::session();
        $this->clock = $clock ?? static fn (): int => time();
        $this->int = $int ?? static fn (int $min, int $max): int => random_int($min, $max);
        $this->bytes = $bytes ?? static fn (int $length): string => random_bytes($length);
        $this->providers = $providers === null ? null : self::validProviders($providers) + [self::FALLBACK => new MathProvider()];
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Alle Verfahren nach Kennung. Mit festen Verfahren (Tests) gelten genau diese; sonst die Standardverfahren, die der Filter
     * `cms_captcha_providers` ergänzen oder ersetzen darf (ungültige Einträge entfallen). „math“ ist immer dabei.
     *
     * @return array<string, CaptchaProvider>
     */
    public function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }
        $list = [];
        foreach ([new ProofOfWorkProvider(), new ImageProvider(), new MathProvider()] as $provider) {
            $list[$provider->id()] = $provider;
        }
        try {
            $filtered = Hooks::applyFilters(self::FILTER_PROVIDERS, $list);
            $list = is_array($filtered) ? self::validProviders($filtered) : $list;
        } catch (\Throwable) {
            // Fehler in einem Filter: Standardverfahren gelten.
        }
        $list[self::FALLBACK] ??= new MathProvider();

        return $this->providers = $list;
    }

    /** Kennung des Verfahrens, das für `$id` tatsächlich läuft (bekannt, verfügbar, ohne Einwilligungspflicht, sonst „math“). */
    public function effectiveId(?string $id): string
    {
        $providers = $this->providers();
        $provider = $id !== null ? ($providers[$id] ?? null) : null;
        if ($provider === null || !$provider->available() || $provider->requiresConsent()) {
            return self::FALLBACK;
        }

        return $provider->id();
    }

    public function provider(?string $id): CaptchaProvider
    {
        return $this->providers()[$this->effectiveId($id)];
    }

    /** Kann dieser Dienst Aufgaben speichern und prüfen (Sitzung vorhanden)? */
    public function available(): bool
    {
        return $this->store->available();
    }

    /**
     * Neue Aufgabe als HTML-Fragment (ohne `<form>`). Ohne Sitzung ein Hinweis statt der Felder (die Anfrage ließe sich nicht prüfen).
     */
    public function render(string $context, ?string $providerId = null): string
    {
        if (!$this->store->available()) {
            return '<p class="cms-captcha cms-captcha--unavailable" role="alert">'
                . CaptchaText::e(CaptchaText::t('captcha.unavailable', 'Die Sicherheitsprüfung ist gerade nicht verfügbar. Bitte lade die Seite neu.')) . '</p>';
        }
        $provider = $this->provider($providerId);
        $now = ($this->clock)();
        $task = $provider->create($this->int, $this->bytes);
        $id = bin2hex(($this->bytes)(16));
        $this->store->put($id, [
            'provider' => $provider->id(),
            'context' => $context,
            'private' => $task['private'],
            'created' => $now,
            'expires' => $now + self::TTL,
        ], $now);

        return $provider->render($id, $task['public']);
    }

    /**
     * Antwort einer Anfrage prüfen (verbraucht die Aufgabe).
     *
     * @param array<string, mixed> $request Roh-Felder (`$_POST`)
     */
    public function verify(array $request, string $context): CaptchaResult
    {
        if (!$this->store->available()) {
            return CaptchaResult::failed(CaptchaResult::UNAVAILABLE);
        }
        $id = $request[self::FIELD_ID] ?? null;
        if (!is_string($id) || preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
            return CaptchaResult::failed(CaptchaResult::MISSING);
        }
        $now = ($this->clock)();
        $record = $this->store->take($id, $now);
        if ($record === null) {
            return CaptchaResult::failed(CaptchaResult::MISSING);
        }
        $providerId = (string) ($record['provider'] ?? '');
        if (!empty($record['expired'])) {
            return CaptchaResult::failed(CaptchaResult::EXPIRED, $providerId);
        }
        if ((string) ($record['context'] ?? '') !== $context) {
            return CaptchaResult::failed(CaptchaResult::CONTEXT, $providerId);
        }
        $provider = $this->providers()[$providerId] ?? null;
        if ($provider === null) {
            return CaptchaResult::failed(CaptchaResult::UNAVAILABLE, $providerId);
        }
        try {
            $ok = $provider->verify($request, is_array($record['private'] ?? null) ? $record['private'] : []);
        } catch (\Throwable) {
            $ok = false;
        }

        return $ok ? CaptchaResult::ok($providerId) : CaptchaResult::failed(CaptchaResult::INVALID, $providerId);
    }

    /**
     * Zusätzliche CSP-Quellen des Verfahrens (lokale Verfahren: keine).
     *
     * @return array<string, list<string>>
     */
    public function cspSources(?string $providerId = null): array
    {
        return $this->provider($providerId)->cspSources();
    }

    /**
     * Skript und Stil der Ausgabe (relativ zu `CMS/assets/`), die die einbettende Seite lädt.
     *
     * @return array{js: string, css: string}
     */
    public static function assetPaths(): array
    {
        return ['js' => 'js/captcha/captcha.js', 'css' => 'css/captcha.css'];
    }

    /**
     * @param array<mixed> $providers
     * @return array<string, CaptchaProvider>
     */
    private static function validProviders(array $providers): array
    {
        $valid = [];
        foreach ($providers as $provider) {
            if ($provider instanceof CaptchaProvider && preg_match('/^[a-z][a-z0-9_]{0,19}$/', $provider->id()) === 1) {
                $valid[$provider->id()] = $provider;
            }
        }

        return $valid;
    }
}
