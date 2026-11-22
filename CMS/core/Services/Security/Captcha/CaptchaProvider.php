<?php
/**
 * Schnittstelle eines Captcha-Verfahrens (E7: nur lokale Verfahren, keine Drittanbieter).
 *
 * Ein Anbieter erzeugt eine Aufgabe (`create()`: öffentlicher Teil für den Browser, privater Teil für die Sitzung), stellt sie als
 * HTML dar (`render()`) und prüft die Antwort gegen den privaten Teil (`verify()`). Jede Aufgabe enthält eine Rechenaufgabe als
 * gleichwertige Alternative (WCAG 1.1.1/2.2.1): Wer weder JavaScript noch das Bild nutzen kann, löst die Rechenaufgabe.
 *
 * @package CMSv2\Core\Services\Security
 */

declare(strict_types=1);

namespace CMS\Services\Security\Captcha;

if (!defined('ABSPATH')) {
    exit;
}

interface CaptchaProvider
{
    /** Stabile Kennung (`pow`, `math`, `image`), Muster `[a-z][a-z0-9_]{0,19}`. */
    public function id(): string;

    /** Läuft das Verfahren in dieser Installation (z. B. Bild: GD vorhanden)? Sonst gilt der Rückfall „math“. */
    public function available(): bool;

    /**
     * Neue Aufgabe.
     *
     * @param \Closure(int, int): int $int Zufallszahl im Bereich (min, max), einschließlich
     * @param \Closure(int): string $bytes Zufallsbytes
     * @return array{public: array<string, mixed>, private: array<string, mixed>}
     *   `public` geht an `render()` (und damit an den Browser), `private` bleibt in der Sitzung
     */
    public function create(\Closure $int, \Closure $bytes): array;

    /**
     * HTML-Fragment mit Feldern und Beschriftung (ohne `<form>`), alle Werte maskiert.
     *
     * @param array<string, mixed> $public Teil `public` aus `create()`
     */
    public function render(string $id, array $public): string;

    /**
     * Prüft die Antwort. `$private` ist der Teil `private` aus `create()`; `$request` sind die Roh-Felder der Anfrage.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $private
     */
    public function verify(array $request, array $private): bool;

    /** Braucht das Verfahren eine Einwilligung (Datenabfluss an Dritte)? Lokale Verfahren: nie. */
    public function requiresConsent(): bool;

    /**
     * Zusätzliche CSP-Quellen je Direktive (`img-src`, `script-src`, …): lokale Verfahren brauchen keine.
     *
     * @return array<string, list<string>>
     */
    public function cspSources(): array;
}
