<?php
/**
 * Site-Kontext der Admin-Oberfläche (MultiSites).
 *
 * Ein 365CMS-Admin kann mehrere Sites bedienen. Welche das sind, legt die Administration unter
 * Einstellungen › MultiSites fest (Name, URL, Akzentfarbe, weitere Hostnamen); es gibt keine fest
 * eingebauten Sites. Solange dort nichts gespeichert ist, gilt die Haupt-URL der Installation
 * (`SITE_URL`) als einzige Site.
 *
 * Der aktive Site-Kontext steuert Akzentfarbe (`<html data-site>`), den Site-Umschalter der
 * Seitenleiste und die Plugin-Zuweisung. Auflösung: Benutzer-Präferenz > Request-Host > erste Site
 * (Standard-Site).
 *
 * Speicherung: Option `admin_sites` als JSON-Liste aus Objekten mit `key`, `label`, `url`, `accent`,
 * `accent_soft`, `accent_strong` und `hosts`. Der Schlüssel wird beim Anlegen aus der Domain
 * abgeleitet und bleibt danach stabil, weil Plugin-Zuweisungen und Benutzer-Präferenzen auf ihn
 * verweisen.
 *
 * @package 365CMS
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Json;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminSiteContextService
{
    public const OPTION_SITES = 'admin_sites';
    public const ADMIN_PATH = '/admin/multisites';

    /** Standard-Akzent (entspricht `--cms-accent` in admin-tabler.css). */
    public const DEFAULT_ACCENT = '#4f46e5';
    private const DEFAULT_ACCENT_SOFT = '#eef2ff';
    private const DEFAULT_ACCENT_STRONG = '#3730a3';

    /**
     * Mindestkontrast der Akzentfarbe (WCAG 2.2 AA, normaler Text): weiße Schrift auf Schaltflächen
     * und Links in Akzentfarbe auf dem Seitenhintergrund.
     */
    public const MIN_ACCENT_CONTRAST = 4.5;

    /** Seitenhintergrund hinter den Karten (entspricht `--cms-page-bg` in admin-tabler.css). */
    public const PAGE_BACKGROUND = '#f1f0ee';

    public const MAX_LABEL_LENGTH = 60;

    private const KEY_PATTERN = '/^[a-z0-9][a-z0-9.-]{0,62}$/';

    private static ?self $instance = null;

    /** @var array<string, array{key:string,label:string,url:string,accent:string,accent_soft:string,accent_strong:string,hosts:list<string>}>|null */
    private ?array $sites = null;

    /** @var array<string, array{key:string,label:string,url:string,accent:string,accent_soft:string,accent_strong:string,hosts:list<string>}>|null */
    private ?array $configured = null;

    private ?string $currentKey = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * Wirksame Site-Liste: gespeicherte Sites oder – ohne Konfiguration – die Haupt-URL als einzige Site.
     *
     * @return array<string, array{key:string,label:string,url:string,accent:string,accent_soft:string,accent_strong:string,hosts:list<string>}>
     */
    public function getSites(): array
    {
        if ($this->sites !== null) {
            return $this->sites;
        }

        $sites = $this->getConfiguredSites();
        if ($sites === []) {
            $implicit = $this->implicitSite();
            $sites = [$implicit['key'] => $implicit];
        }

        return $this->sites = $sites;
    }

    /**
     * Unter Einstellungen › MultiSites gespeicherte Sites (leer, solange nichts gespeichert wurde).
     *
     * @return array<string, array{key:string,label:string,url:string,accent:string,accent_soft:string,accent_strong:string,hosts:list<string>}>
     */
    public function getConfiguredSites(): array
    {
        if ($this->configured !== null) {
            return $this->configured;
        }

        $configured = [];
        try {
            $raw = function_exists('get_option') ? get_option(self::OPTION_SITES, '') : '';
            $decoded = is_array($raw) ? $raw : (is_string($raw) && trim($raw) !== '' ? Json::decodeArray($raw, []) : []);
            foreach (is_array($decoded) ? $decoded : [] as $site) {
                $normalized = is_array($site) ? $this->normalizeSite($site) : null;
                if ($normalized !== null && !isset($configured[$normalized['key']])) {
                    $configured[$normalized['key']] = $normalized;
                }
            }
        } catch (\Throwable) {
            $configured = [];
        }

        return $this->configured = $configured;
    }

    public function hasConfiguredSites(): bool
    {
        return $this->getConfiguredSites() !== [];
    }

    public function isValidSiteKey(string $key): bool
    {
        return isset($this->getSites()[$key]);
    }

    /**
     * @return array{key:string,label:string,url:string,accent:string,accent_soft:string,accent_strong:string,hosts:list<string>}|null
     */
    public function getSite(string $key): ?array
    {
        return $this->getSites()[$key] ?? null;
    }

    public function getDefaultSiteKey(): string
    {
        return (string) array_key_first($this->getSites());
    }

    public function getCurrentSiteKey(): string
    {
        if ($this->currentKey !== null) {
            return $this->currentKey;
        }

        $sites = $this->getSites();
        $userId = $this->currentUserId();
        if ($userId > 0) {
            $preferred = AdminUiPreferenceService::getInstance()->getSite($userId);
            if ($preferred !== '' && isset($sites[$preferred])) {
                return $this->currentKey = $preferred;
            }
        }

        $host = $this->requestHostname();
        if ($host !== '') {
            foreach ($sites as $key => $site) {
                if (in_array($host, $site['hosts'], true)) {
                    return $this->currentKey = $key;
                }
            }
        }

        return $this->currentKey = (string) array_key_first($sites);
    }

    /**
     * @return array{key:string,label:string,url:string,accent:string,accent_soft:string,accent_strong:string,hosts:list<string>}
     */
    public function getCurrentSite(): array
    {
        $sites = $this->getSites();

        return $sites[$this->getCurrentSiteKey()] ?? reset($sites);
    }

    /**
     * Speichert den Site-Kontext als Benutzer-Präferenz (Umschalter in der Seitenleiste).
     */
    public function switchSite(int $userId, string $key): bool
    {
        if ($userId <= 0 || !$this->isValidSiteKey($key)) {
            return false;
        }

        $saved = AdminUiPreferenceService::getInstance()->update($userId, ['site' => $key]) !== null;
        if ($saved) {
            $this->currentKey = $key;
        }

        return $saved;
    }

    /**
     * Legt eine Site an (`$originalKey` = null) oder aktualisiert eine bestehende.
     *
     * Erwartet `label`, `url`, `accent` und optional `hosts` (Liste oder Text, getrennt durch
     * Zeilenumbruch, Komma oder Leerzeichen). Die Domain der URL samt www-Variante gehört immer zu
     * den Hostnamen. Wird die bisher implizite Haupt-Site bearbeitet oder eine weitere Site angelegt,
     * wird die komplette wirksame Liste gespeichert.
     *
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string,errors:array<string,string>,key:string}
     */
    public function saveSite(array $input, ?string $originalKey = null): array
    {
        $sites = $this->getSites();
        $originalKey = $originalKey !== null ? strtolower(trim($originalKey)) : null;
        if ($originalKey === '') {
            $originalKey = null;
        }
        if ($originalKey !== null && !isset($sites[$originalKey])) {
            return $this->result(false, 'Die Site wurde nicht gefunden – möglicherweise wurde sie inzwischen gelöscht.', [], '');
        }

        $errors = [];

        $label = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($input['label'] ?? ''))));
        if ($label === '') {
            $errors['label'] = 'Bitte einen Namen angeben.';
        } elseif (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            $errors['label'] = 'Der Name darf höchstens ' . self::MAX_LABEL_LENGTH . ' Zeichen lang sein.';
        }

        $url = $this->normalizeUrl((string) ($input['url'] ?? ''));
        if ($url === null) {
            $errors['url'] = 'Bitte eine gültige Adresse mit http:// oder https:// angeben, ohne Pfad (z. B. https://example.com).';
        }

        $accent = $this->normalizeHex((string) ($input['accent'] ?? ''));
        if ($accent === null) {
            $errors['accent'] = 'Bitte eine Akzentfarbe im Format #rrggbb wählen.';
        } else {
            $ratio = $this->accentContrast($accent);
            if ($ratio < self::MIN_ACCENT_CONTRAST) {
                $errors['accent'] = sprintf(
                    'Die Akzentfarbe %s ist zu hell: Weiße Schrift darauf bzw. Links auf dem grauen Seitenhintergrund erreichen nur %s:1 Kontrast, nötig sind mindestens 4,5:1. Vorschlag: %s.',
                    $accent,
                    number_format(floor($ratio * 100) / 100, 2, ',', ''),
                    $this->suggestAccent($accent)
                );
            }
        }

        $hosts = [];
        $invalidHosts = [];
        $rawHosts = $input['hosts'] ?? [];
        $hostTokens = is_array($rawHosts) ? $rawHosts : (preg_split('/[\s,;]+/', (string) $rawHosts) ?: []);
        foreach ($hostTokens as $token) {
            $token = strtolower(trim((string) $token, " \t\n\r\0\x0B."));
            if ($token === '') {
                continue;
            }
            if (str_contains($token, '://')) {
                $token = strtolower(trim((string) parse_url($token, PHP_URL_HOST), '.'));
            }
            $token = $this->toAsciiHost($token);
            if (!$this->isValidHostname($token)) {
                $invalidHosts[] = $token !== '' ? $token : '(leer)';
                continue;
            }
            $hosts[] = $token;
        }
        if ($invalidHosts !== []) {
            $errors['hosts'] = 'Ungültige Hostnamen: ' . implode(', ', array_unique($invalidHosts)) . '. Bitte nur Domains ohne Protokoll und Pfad angeben.';
        }

        $key = $originalKey ?? '';
        if ($url !== null) {
            $urlHost = $this->hostFromUrl($url);
            $hosts = array_values(array_unique(array_merge($this->hostVariants($urlHost), $hosts)));

            if ($originalKey === null) {
                $baseKey = $this->keyFromHost($urlHost);
                if ($baseKey === '') {
                    $errors['url'] = 'Die Domain ist zu lang, um daraus einen Site-Schlüssel zu bilden (höchstens 63 Zeichen).';
                } else {
                    $key = $this->uniqueKey($baseKey, $sites);
                }
            }

            if (!isset($errors['hosts'])) {
                foreach ($sites as $otherKey => $other) {
                    if ($otherKey === $originalKey) {
                        continue;
                    }
                    $shared = array_values(array_intersect($hosts, $other['hosts']));
                    if ($shared !== []) {
                        $field = in_array($shared[0], $this->hostVariants($urlHost), true) ? 'url' : 'hosts';
                        $errors[$field] = sprintf('Der Hostname %s gehört bereits zur Site „%s“.', $shared[0], $other['label']);
                        break;
                    }
                }
            }
        }

        if ($errors !== [] || $url === null || $accent === null || $key === '') {
            return $this->result(false, 'Die Site wurde nicht gespeichert. Bitte die markierten Angaben prüfen.', $errors, $originalKey ?? '');
        }

        $existing = $originalKey !== null ? $sites[$originalKey] : null;
        $keepPalette = $existing !== null && $existing['accent'] === $accent;
        $site = [
            'key' => $key,
            'label' => $label,
            'url' => $url,
            'accent' => $accent,
            'accent_soft' => $keepPalette ? $existing['accent_soft'] : $this->deriveSoft($accent),
            'accent_strong' => $keepPalette ? $existing['accent_strong'] : $this->deriveStrong($accent),
            'hosts' => $hosts,
        ];
        $sites[$key] = $site;

        if (!$this->persist($sites)) {
            return $this->result(false, 'Die Site konnte nicht gespeichert werden (Datenbankfehler).', [], $originalKey ?? '');
        }

        return $this->result(
            true,
            $originalKey === null
                ? sprintf('Site „%s“ angelegt.', $label)
                : sprintf('Site „%s“ gespeichert.', $label),
            [],
            $key
        );
    }

    /**
     * Entfernt eine Site. Die letzte verbleibende Site lässt sich nicht löschen.
     *
     * @return array{success:bool,message:string,errors:array<string,string>,key:string}
     */
    public function deleteSite(string $key): array
    {
        $sites = $this->getSites();
        $key = strtolower(trim($key));
        if (!isset($sites[$key])) {
            return $this->result(false, 'Die Site wurde nicht gefunden – möglicherweise wurde sie bereits gelöscht.', [], $key);
        }
        if (count($sites) <= 1) {
            return $this->result(false, 'Die letzte Site kann nicht gelöscht werden.', [], $key);
        }

        $label = $sites[$key]['label'];
        unset($sites[$key]);
        if (!$this->persist($sites)) {
            return $this->result(false, 'Die Site konnte nicht gelöscht werden (Datenbankfehler).', [], $key);
        }

        return $this->result(true, sprintf('Site „%s“ gelöscht.', $label), [], $key);
    }

    /**
     * Verschiebt eine Site in der Reihenfolge (`up`/`down`). Die erste Site ist die Standard-Site.
     *
     * @return array{success:bool,message:string,errors:array<string,string>,key:string}
     */
    public function moveSite(string $key, string $direction): array
    {
        $sites = $this->getSites();
        $key = strtolower(trim($key));
        $keys = array_keys($sites);
        $index = array_search($key, $keys, true);
        if ($index === false) {
            return $this->result(false, 'Die Site wurde nicht gefunden.', [], $key);
        }

        $target = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : -1);
        if ($target < 0 || $target >= count($keys)) {
            return $this->result(false, 'Die Site lässt sich nicht weiter verschieben.', [], $key);
        }

        [$keys[$index], $keys[$target]] = [$keys[$target], $keys[$index]];
        $ordered = [];
        foreach ($keys as $orderedKey) {
            $ordered[$orderedKey] = $sites[$orderedKey];
        }

        if (!$this->persist($ordered)) {
            return $this->result(false, 'Die Reihenfolge konnte nicht gespeichert werden (Datenbankfehler).', [], $key);
        }

        $label = $sites[$key]['label'];

        return $this->result(
            true,
            $target === 0
                ? sprintf('„%s“ ist jetzt die Standard-Site.', $label)
                : sprintf('Reihenfolge gespeichert: „%s“ steht jetzt an Position %d.', $label, $target + 1),
            [],
            $key
        );
    }

    /**
     * Zusätzliche Hostnamen einer Site (ohne die Domain der URL und deren www-Variante).
     *
     * @param array{url:string,hosts:list<string>} $site
     * @return list<string>
     */
    public function getExtraHosts(array $site): array
    {
        $variants = $this->hostVariants($this->hostFromUrl((string) $site['url']));

        return array_values(array_diff($site['hosts'], $variants));
    }

    /**
     * Kontrastverhältnis zweier Farben nach WCAG 2.x (1 bis 21).
     */
    public function contrastRatio(string $colorA, string $colorB): float
    {
        $lumA = $this->relativeLuminance($colorA);
        $lumB = $this->relativeLuminance($colorB);
        if ($lumA < $lumB) {
            [$lumA, $lumB] = [$lumB, $lumA];
        }

        return ($lumA + 0.05) / ($lumB + 0.05);
    }

    /**
     * Ungünstigster Kontrast der Akzentfarbe: weiße Schrift darauf oder sie selbst auf dem Seitenhintergrund.
     */
    public function accentContrast(string $accent): float
    {
        return min($this->contrastRatio($accent, '#ffffff'), $this->contrastRatio($accent, self::PAGE_BACKGROUND));
    }

    /**
     * Dunkelt eine zu helle Akzentfarbe schrittweise ab, bis sie den Mindestkontrast erreicht.
     */
    public function suggestAccent(string $accent): string
    {
        $accent = $this->normalizeHex($accent) ?? self::DEFAULT_ACCENT;
        for ($step = 0; $step <= 20; $step++) {
            $candidate = $this->mix($accent, '#000000', $step / 20);
            if ($this->accentContrast($candidate) >= self::MIN_ACCENT_CONTRAST) {
                return $candidate;
            }
        }

        return self::DEFAULT_ACCENT;
    }

    /**
     * Haupt-URL der Installation als einzige Site, solange keine Sites gespeichert sind.
     *
     * @return array{key:string,label:string,url:string,accent:string,accent_soft:string,accent_strong:string,hosts:list<string>}
     */
    private function implicitSite(): array
    {
        $siteUrl = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
        $host = $this->hostFromUrl($siteUrl);
        $key = $host !== '' ? $this->keyFromHost($host) : '';

        $label = '';
        try {
            $label = function_exists('cms_get_site_name') ? cms_get_site_name() : (defined('SITE_NAME') ? (string) SITE_NAME : '');
        } catch (\Throwable) {
            $label = '';
        }
        $label = trim(strip_tags($label));

        return [
            'key' => $key !== '' ? $key : 'site',
            'label' => $label !== '' ? mb_substr($label, 0, self::MAX_LABEL_LENGTH) : ($host !== '' ? $host : '365CMS'),
            'url' => $host !== '' ? $siteUrl : '',
            'accent' => self::DEFAULT_ACCENT,
            'accent_soft' => self::DEFAULT_ACCENT_SOFT,
            'accent_strong' => self::DEFAULT_ACCENT_STRONG,
            'hosts' => $host !== '' ? $this->hostVariants($host) : [],
        ];
    }

    /**
     * @param array<string, array{key:string,label:string,url:string,accent:string,accent_soft:string,accent_strong:string,hosts:list<string>}> $sites
     */
    private function persist(array $sites): bool
    {
        $list = [];
        foreach ($sites as $site) {
            $list[] = [
                'key' => $site['key'],
                'label' => $site['label'],
                'url' => $site['url'],
                'accent' => $site['accent'],
                'accent_soft' => $site['accent_soft'],
                'accent_strong' => $site['accent_strong'],
                'hosts' => array_values($site['hosts']),
            ];
        }

        try {
            $json = json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $saved = function_exists('update_option') && update_option(self::OPTION_SITES, $json);
        } catch (\Throwable) {
            $saved = false;
        }

        $this->sites = null;
        $this->configured = null;
        $this->currentKey = null;

        return $saved;
    }

    /**
     * @param array<string, string> $errors
     * @return array{success:bool,message:string,errors:array<string,string>,key:string}
     */
    private function result(bool $success, string $message, array $errors, string $key): array
    {
        return ['success' => $success, 'message' => $message, 'errors' => $errors, 'key' => $key];
    }

    /**
     * @param array<mixed> $site
     * @return array{key:string,label:string,url:string,accent:string,accent_soft:string,accent_strong:string,hosts:list<string>}|null
     */
    private function normalizeSite(array $site): ?array
    {
        $key = strtolower(trim((string) ($site['key'] ?? '')));
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            return null;
        }

        $label = trim(strip_tags((string) ($site['label'] ?? '')));
        $label = $label !== '' ? mb_substr($label, 0, self::MAX_LABEL_LENGTH) : $key;

        $url = $this->normalizeUrl((string) ($site['url'] ?? '')) ?? ('https://' . $key);

        $accent = $this->normalizeHex((string) ($site['accent'] ?? '')) ?? self::DEFAULT_ACCENT;
        $accentSoft = $this->normalizeHex((string) ($site['accent_soft'] ?? '')) ?? $this->deriveSoft($accent);
        $accentStrong = $this->normalizeHex((string) ($site['accent_strong'] ?? '')) ?? $this->deriveStrong($accent);

        $hosts = [];
        foreach ((array) ($site['hosts'] ?? []) as $host) {
            $host = strtolower(trim((string) $host, " \t\n\r\0\x0B."));
            if ($host !== '' && preg_match('/^[a-z0-9.-]{1,253}$/', $host) === 1) {
                $hosts[] = $host;
            }
        }
        if ($hosts === []) {
            $hosts = [$key, 'www.' . $key];
        }

        return [
            'key' => $key,
            'label' => $label,
            'url' => $url,
            'accent' => $accent,
            'accent_soft' => $accentSoft,
            'accent_strong' => $accentStrong,
            'hosts' => array_values(array_unique($hosts)),
        ];
    }

    /**
     * Normalisiert eine Site-Adresse auf `schema://host[:port]`; ohne Schema wird https:// ergänzt.
     */
    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $url) !== 1) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $path = (string) ($parts['path'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($path !== '' && $path !== '/')) {
            return null;
        }

        $host = $this->toAsciiHost(strtolower(trim((string) $parts['host'], '.')));
        if (!$this->isValidHostname($host)) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    private function hostFromUrl(string $url): string
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '.'));

        return $this->isValidHostname($host) ? $host : '';
    }

    /**
     * Site-Schlüssel aus der Domain: Kleinbuchstaben, ohne führendes `www.` und ohne Port.
     */
    private function keyFromHost(string $host): string
    {
        $host = strtolower($host);
        if (str_starts_with($host, 'www.') && substr_count($host, '.') >= 2) {
            $host = substr($host, 4);
        }

        return preg_match(self::KEY_PATTERN, $host) === 1 ? $host : '';
    }

    /**
     * @param array<string, mixed> $sites
     */
    private function uniqueKey(string $baseKey, array $sites): string
    {
        $key = $baseKey;
        for ($suffix = 2; isset($sites[$key]); $suffix++) {
            $tail = '-' . $suffix;
            $key = substr($baseKey, 0, 63 - strlen($tail)) . $tail;
        }

        return $key;
    }

    /**
     * Domain samt www-Variante (bzw. ohne www.); IP-Adressen und einteilige Hosts bleiben allein.
     *
     * @return list<string>
     */
    private function hostVariants(string $host): array
    {
        if ($host === '') {
            return [];
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || !str_contains($host, '.')) {
            return [$host];
        }

        $bare = str_starts_with($host, 'www.') && substr_count($host, '.') >= 2 ? substr($host, 4) : $host;

        return array_values(array_unique([$host, $bare === $host ? 'www.' . $host : $bare]));
    }

    private function isValidHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }

        return preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(?:\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/', $host) === 1;
    }

    private function toAsciiHost(string $host): string
    {
        if ($host === '' || preg_match('/[^\x20-\x7e]/', $host) !== 1 || !function_exists('idn_to_ascii')) {
            return $host;
        }

        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) ? strtolower($ascii) : $host;
    }

    private function deriveSoft(string $accent): string
    {
        return $accent === self::DEFAULT_ACCENT ? self::DEFAULT_ACCENT_SOFT : $this->mix($accent, '#ffffff', 0.92);
    }

    private function deriveStrong(string $accent): string
    {
        return $accent === self::DEFAULT_ACCENT ? self::DEFAULT_ACCENT_STRONG : $this->mix($accent, '#000000', 0.25);
    }

    private function relativeLuminance(string $color): float
    {
        $rgb = sscanf($this->normalizeHex($color) ?? '#000000', '#%02x%02x%02x');
        $channel = static function (int $value): float {
            $srgb = $value / 255;

            return $srgb <= 0.04045 ? $srgb / 12.92 : (($srgb + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel((int) $rgb[0]) + 0.7152 * $channel((int) $rgb[1]) + 0.0722 * $channel((int) $rgb[2]);
    }

    private function normalizeHex(string $value): ?string
    {
        $value = strtolower(trim($value));

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
    }

    private function mix(string $color, string $with, float $weight): string
    {
        $from = sscanf($color, '#%02x%02x%02x');
        $to = sscanf($with, '#%02x%02x%02x');
        $parts = [];
        for ($i = 0; $i < 3; $i++) {
            $parts[] = (int) round(((int) $from[$i]) * (1 - $weight) + ((int) $to[$i]) * $weight);
        }

        return sprintf('#%02x%02x%02x', ...$parts);
    }

    private function requestHostname(): string
    {
        $host = function_exists('cms_runtime_host') ? cms_runtime_host() : (string) ($_SERVER['HTTP_HOST'] ?? '');
        $host = strtolower(trim($host));
        if (str_starts_with($host, '[')) {
            return '';
        }

        return (string) preg_replace('/:\d+$/', '', $host);
    }

    private function currentUserId(): int
    {
        try {
            return (int) (\CMS\Auth::getCurrentUser()->id ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
