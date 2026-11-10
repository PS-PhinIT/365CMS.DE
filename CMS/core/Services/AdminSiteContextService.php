<?php
/**
 * Site-Kontext der Admin-Oberfläche.
 *
 * Ein 365CMS-Admin bedient mehrere Sites (Standard: phinit.de und 365network.de). Der aktive
 * Site-Kontext steuert Akzentfarbe (`<html data-site>`), den Site-Umschalter der Seitenleiste und
 * die Plugin-Zuweisung. Auflösung: Benutzer-Präferenz > Request-Host > erste konfigurierte Site.
 *
 * Die Site-Liste ist über die Option `admin_sites` (JSON-Liste aus Objekten mit `key`, `label`,
 * optional `url`, `accent`, `hosts`) überschreibbar; ohne Option gelten die beiden Standard-Sites.
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

    /** @var list<array{key:string,label:string,url:string,accent:string,accent_soft:string,accent_strong:string,hosts:list<string>}> */
    private const DEFAULT_SITES = [
        [
            'key' => 'phinit.de',
            'label' => 'phinit.de',
            'url' => 'https://phinit.de',
            'accent' => '#4f46e5',
            'accent_soft' => '#eef2ff',
            'accent_strong' => '#3730a3',
            'hosts' => ['phinit.de', 'www.phinit.de'],
        ],
        [
            'key' => '365network.de',
            'label' => '365network.de',
            'url' => 'https://365network.de',
            'accent' => '#0f766e',
            'accent_soft' => '#e6f6f3',
            'accent_strong' => '#115e59',
            'hosts' => ['365network.de', 'www.365network.de'],
        ],
    ];

    private static ?self $instance = null;

    /** @var array<string, array{key:string,label:string,url:string,accent:string,accent_soft:string,accent_strong:string,hosts:list<string>}>|null */
    private ?array $sites = null;

    private ?string $currentKey = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * @return array<string, array{key:string,label:string,url:string,accent:string,accent_soft:string,accent_strong:string,hosts:list<string>}>
     */
    public function getSites(): array
    {
        if ($this->sites !== null) {
            return $this->sites;
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

        if ($configured === []) {
            foreach (self::DEFAULT_SITES as $site) {
                $configured[$site['key']] = $site;
            }
        }

        return $this->sites = $configured;
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
     * Sites mit fest hinterlegten Akzentfarben in admin-shell.css (`html[data-site="…"]`).
     */
    public function hasStaticAccent(string $key): bool
    {
        foreach (self::DEFAULT_SITES as $site) {
            if ($site['key'] === $key) {
                $current = $this->getSite($key);

                return $current !== null && strtolower($current['accent']) === $site['accent'];
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $site
     * @return array{key:string,label:string,url:string,accent:string,accent_soft:string,accent_strong:string,hosts:list<string>}|null
     */
    private function normalizeSite(array $site): ?array
    {
        $key = strtolower(trim((string) ($site['key'] ?? '')));
        if (preg_match('/^[a-z0-9][a-z0-9.-]{0,62}$/', $key) !== 1) {
            return null;
        }

        $default = null;
        foreach (self::DEFAULT_SITES as $candidate) {
            if ($candidate['key'] === $key) {
                $default = $candidate;
                break;
            }
        }

        $label = trim(strip_tags((string) ($site['label'] ?? '')));
        $label = $label !== '' ? mb_substr($label, 0, 60) : ($default['label'] ?? $key);

        $url = trim((string) ($site['url'] ?? ''));
        if ($url === '' || preg_match('~^https?://[^\s/]+~i', $url) !== 1) {
            $url = $default['url'] ?? ('https://' . $key);
        }

        $accent = $this->normalizeHex((string) ($site['accent'] ?? '')) ?? ($default['accent'] ?? '#4f46e5');
        $accentSoft = $this->normalizeHex((string) ($site['accent_soft'] ?? ''))
            ?? (($default !== null && $default['accent'] === $accent) ? $default['accent_soft'] : $this->mix($accent, '#ffffff', 0.92));
        $accentStrong = $this->normalizeHex((string) ($site['accent_strong'] ?? ''))
            ?? (($default !== null && $default['accent'] === $accent) ? $default['accent_strong'] : $this->mix($accent, '#000000', 0.25));

        $hosts = [];
        foreach ((array) ($site['hosts'] ?? []) as $host) {
            $host = strtolower(trim((string) $host, " \t\n\r\0\x0B."));
            if ($host !== '' && preg_match('/^[a-z0-9.-]{1,253}$/', $host) === 1) {
                $hosts[] = $host;
            }
        }
        if ($hosts === []) {
            $hosts = $default['hosts'] ?? [$key, 'www.' . $key];
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
