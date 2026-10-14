<?php
/**
 * Vertrauenswürdige Reverse-Proxys / CDNs und Ermittlung der echten Client-IP.
 *
 * Konfiguration (Kommaliste, Konstante hat Vorrang vor der Admin-Einstellung):
 *   - `CMS_TRUSTED_PROXIES` in config/app.php oder Option `security_trusted_proxies`
 *     (Admin → Sicherheit → Firewall → „Proxy / CDN & Client-IP“).
 *   - Einträge: einzelne IPs, CIDR-Bereiche (IPv4/IPv6) und Schlüsselwörter
 *       `cloudflare` = mitgelieferte Cloudflare-Netze (Standard, wenn nichts gesetzt ist;
 *                      Cloudflare überschreibt CF-Connecting-IP/X-Forwarded-For selbst)
 *       `private`    = Loopback, RFC1918, CGNAT (100.64.0.0/10), IPv6-ULA – nur setzen, wenn
 *                      der lokale Proxy X-Forwarded-For selbst ergänzt/überschreibt
 *       `auto`       = `private` + `cloudflare`
 *       `*`          = jedem direkten Gegenüber vertrauen (nur, wenn der Server
 *                      ausschließlich über das CDN erreichbar ist!)
 *       `off`        = Weiterleitungs-Header nie auswerten (nur REMOTE_ADDR)
 *
 * Hoster mit eigenem CDN (z. B. Hostinger) nennen ihre Edge-Adressen oft nicht. Erreicht
 * eine Anfrage den Server über eine nicht vertrauenswürdige öffentliche Adresse mit
 * Weiterleitungs-Headern, wird deren Netz (IPv4 /24, IPv6 /48) als Kandidat unter
 * cache/ protokolliert. Als verlässliches Signal gilt nur die eigene Admin-Anfrage: Kommt
 * sie über ein solches Netz, wird es in der Firewall-Ansicht hervorgehoben und kann mit
 * einem Klick übernommen werden. Header-Angaben (Clients, Beobachtungen) sind fälschbar;
 * eine automatische Übernahme gibt es deshalb bewusst nicht (sonst könnte jeder Angreifer
 * sich durch gefälschte Header selbst zum „Proxy“ machen und Rate-Limits umgehen).
 *
 * @package 365CMS
 */

declare(strict_types=1);

namespace CMS\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class TrustedProxyService
{
    public const OPTION_KEY = 'security_trusted_proxies';
    public const DEFAULT_SPEC = 'cloudflare';

    private const CANDIDATE_FILE = 'trusted_proxy_candidates.json';
    private const CANDIDATE_LIMIT = 30;
    private const CANDIDATE_SAMPLE_LIMIT = 25;
    private const CANDIDATE_WRITE_INTERVAL = 60;
    private const CANDIDATE_MAX_AGE = 1_209_600; // 14 Tage

    /** Header, die einen Weiterleitungsweg anzeigen (Reihenfolge = Priorität ohne X-Forwarded-For). */
    private const FORWARD_HEADERS = [
        'HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For',
        'HTTP_X_REAL_IP' => 'X-Real-IP',
        'HTTP_TRUE_CLIENT_IP' => 'True-Client-IP',
        'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP',
        'HTTP_FORWARDED' => 'Forwarded',
    ];

    private const PRIVATE_RANGES = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '100.64.0.0/10',
        '::1/128',
        'fc00::/7',
    ];

    /** Stand: https://www.cloudflare.com/ips/ – bei Bedarf per Konstante/Einstellung ergänzen. */
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /** @var array{spec:string,source:string,any:bool,cloudflare:bool,ranges:list<string>}|null */
    private static ?array $config = null;

    /** @var array{ip:string,remote:string,via_proxy:bool,header:string}|null */
    private static ?array $resolved = null;

    private static bool $candidateNoted = false;

    /** Netz des nicht vertrauenswürdigen Weiterleiters der aktuellen Anfrage ('' = keiner). */
    private static string $currentCandidate = '';

    /**
     * Echte Client-IP der aktuellen Anfrage (pro Request zwischengespeichert).
     */
    public static function clientIp(): string
    {
        return self::resolve()['ip'];
    }

    /**
     * true, wenn die Anfrage über einen vertrauenswürdigen Proxy/CDN kam (z. B. für Länder-Header).
     */
    public static function requestViaTrustedProxy(): bool
    {
        return self::resolve()['via_proxy'];
    }

    /**
     * ISO-Ländercode aus CDN-Headern – nur, wenn die Anfrage über einen vertrauenswürdigen
     * Proxy kam. Direkt gesendete Header sind beliebig fälschbar.
     */
    public static function countryCode(): string
    {
        if (!self::requestViaTrustedProxy()) {
            return '';
        }

        foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_APPENGINE_COUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY'] as $header) {
            $value = strtoupper(trim((string) ($_SERVER[$header] ?? '')));
            if (preg_match('/^[A-Z]{2}$/', $value) === 1) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Setzt Request-Caches zurück (Tests, nach dem Speichern der Einstellung).
     */
    public static function reset(): void
    {
        self::$config = null;
        self::$resolved = null;
        self::$candidateNoted = false;
        self::$currentCandidate = '';
    }

    /**
     * Netz (/24 bzw. /48), über das die aktuelle Anfrage mit Weiterleitungs-Headern von einer
     * nicht vertrauenswürdigen Adresse kam. Für Admin-Anfragen ist das ein nicht fälschbarer
     * Hinweis auf ein vorgelagertes Hoster-CDN – anders als Header-Inhalte fremder Anfragen.
     */
    public static function currentRequestCandidate(): string
    {
        self::resolve();

        return self::$currentCandidate;
    }

    /**
     * @return array{ip:string,remote:string,via_proxy:bool,header:string}
     */
    public static function resolve(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return self::$resolved = ['ip' => '0.0.0.0', 'remote' => '0.0.0.0', 'via_proxy' => false, 'header' => ''];
        }

        $result = ['ip' => $remote, 'remote' => $remote, 'via_proxy' => false, 'header' => ''];
        $config = self::config();
        $forwardHeaders = self::presentForwardHeaders();

        if (!self::isTrusted($remote, $config)) {
            if ($forwardHeaders !== []) {
                self::noteCandidate($remote, $forwardHeaders, self::firstForwardedClient());
            }

            return self::$resolved = $result;
        }

        if ($forwardHeaders === []) {
            return self::$resolved = $result;
        }

        // Cloudflare überschreibt CF-Connecting-IP selbst – nur aus Cloudflare-Netzen übernehmen.
        if ($config['cloudflare'] && self::ipMatchesAny($remote, self::CLOUDFLARE_RANGES)) {
            $cfIp = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
            if (filter_var($cfIp, FILTER_VALIDATE_IP) !== false) {
                return self::$resolved = ['ip' => $cfIp, 'remote' => $remote, 'via_proxy' => true, 'header' => 'CF-Connecting-IP'];
            }
        }

        $chain = self::forwardedChain();
        if ($chain !== []) {
            // Von rechts nach links: erste Adresse, die selbst kein vertrauenswürdiger Proxy ist.
            $hops = array_reverse($chain['hops']);
            $last = $remote;
            foreach ($hops as $index => $hop) {
                if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                    break;
                }

                $last = $hop;
                if (!self::isTrusted($hop, $config, false)) {
                    // Weitere Adressen links davon → $hop leitet selbst weiter (z. B. CDN-Edge hinter Load Balancer).
                    if (isset($hops[$index + 1])) {
                        self::noteCandidate($hop, [$chain['header']], (string) $hops[$index + 1]);
                    }

                    return self::$resolved = ['ip' => $hop, 'remote' => $remote, 'via_proxy' => true, 'header' => $chain['header']];
                }
            }

            return self::$resolved = ['ip' => $last, 'remote' => $remote, 'via_proxy' => true, 'header' => $chain['header']];
        }

        foreach (['HTTP_X_REAL_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_CF_CONNECTING_IP'] as $header) {
            $value = trim((string) ($_SERVER[$header] ?? ''));
            if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
                return self::$resolved = ['ip' => $value, 'remote' => $remote, 'via_proxy' => true, 'header' => self::FORWARD_HEADERS[$header]];
            }
        }

        return self::$resolved = $result;
    }

    /**
     * Wirksame Konfiguration inkl. Herkunft (constant|option|default).
     *
     * @return array{spec:string,source:string,any:bool,cloudflare:bool,ranges:list<string>}
     */
    public static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $source = 'default';
        $spec = self::DEFAULT_SPEC;

        if (defined('CMS_TRUSTED_PROXIES')) {
            $source = 'constant';
            $spec = trim((string) constant('CMS_TRUSTED_PROXIES'));
            // Leere Konstante = bisheriges Verhalten (nur REMOTE_ADDR).
            if ($spec === '') {
                $spec = 'off';
            }
        } else {
            $option = self::readOption();
            if ($option !== null && trim($option) !== '') {
                $source = 'option';
                $spec = trim($option);
            }
        }

        return self::$config = self::parse($spec) + ['spec' => $spec, 'source' => $source];
    }

    /**
     * @return array{any:bool,cloudflare:bool,ranges:list<string>,invalid:list<string>}
     */
    public static function parse(string $spec): array
    {
        $any = false;
        $cloudflare = false;
        $ranges = [];
        $invalid = [];

        foreach (preg_split('/[\s,;]+/', strtolower($spec)) ?: [] as $token) {
            $token = trim($token);
            if ($token === '' || $token === 'off' || $token === 'none') {
                continue;
            }

            switch ($token) {
                case '*':
                case 'all':
                    $any = true;
                    break;
                case 'auto':
                    $cloudflare = true;
                    array_push($ranges, ...self::PRIVATE_RANGES, ...self::CLOUDFLARE_RANGES);
                    break;
                case 'private':
                    array_push($ranges, ...self::PRIVATE_RANGES);
                    break;
                case 'cloudflare':
                    $cloudflare = true;
                    array_push($ranges, ...self::CLOUDFLARE_RANGES);
                    break;
                default:
                    if (self::isValidRange($token)) {
                        $ranges[] = $token;
                    } else {
                        $invalid[] = $token;
                    }
            }
        }

        return [
            'any' => $any,
            'cloudflare' => $cloudflare,
            'ranges' => array_values(array_unique($ranges)),
            'invalid' => array_values(array_unique($invalid)),
        ];
    }

    /**
     * Normalisiert eine Eingabe aus dem Admin-Formular; liefert null bei ungültigen Einträgen.
     */
    public static function normalizeSpec(string $spec): ?string
    {
        $tokens = array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[\s,;]+/', strtolower($spec)) ?: []),
            static fn(string $token): bool => $token !== ''
        )));

        if ($tokens === []) {
            return '';
        }

        if (self::parse(implode(',', $tokens))['invalid'] !== []) {
            return null;
        }

        return implode(', ', array_slice($tokens, 0, 100));
    }

    public static function isValidRange(string $value): bool
    {
        $value = trim($value);
        [$address, $bits] = array_pad(explode('/', $value, 2), 2, null);
        $packed = @inet_pton((string) $address);
        if ($packed === false) {
            return false;
        }

        if ($bits === null) {
            return true;
        }

        if (!ctype_digit($bits)) {
            return false;
        }

        $max = strlen($packed) * 8;
        $bitCount = (int) $bits;

        // Zu breite Netze würden praktisch das ganze Internet vertrauenswürdig machen.
        return $bitCount <= $max && $bitCount >= ($max === 32 ? 8 : 16);
    }

    /**
     * Netz für die Kandidatenliste: IPv4 /24, IPv6 /48.
     */
    public static function networkFor(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return '';
        }

        if (strlen($packed) === 4) {
            $bytes = substr($packed, 0, 3) . "\0";
            return (string) inet_ntop($bytes) . '/24';
        }

        $bytes = substr($packed, 0, 6) . str_repeat("\0", 10);
        return (string) inet_ntop($bytes) . '/48';
    }

    /**
     * @param list<string> $ranges Einzel-IPs oder CIDR-Bereiche (IPv4/IPv6)
     */
    public static function ipMatchesAny(string $ip, array $ranges): bool
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        foreach ($ranges as $range) {
            [$subnet, $bits] = array_pad(explode('/', (string) $range, 2), 2, null);
            $subnetBin = @inet_pton((string) $subnet);
            if ($subnetBin === false || strlen($subnetBin) !== strlen($ipBin)) {
                continue;
            }

            $maxBits = strlen($ipBin) * 8;
            $bits = $bits === null ? $maxBits : (int) $bits;
            if ($bits < 0 || $bits > $maxBits) {
                continue;
            }

            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }

            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Erkannte, noch nicht vertrauenswürdige Proxy-Netze (neueste zuerst).
     *
     * `current_request` = die aktuelle (Admin-)Anfrage kam selbst über dieses Netz.
     *
     * @return list<array{network:string,first_seen:int,last_seen:int,observations:int,distinct_clients:int,headers:list<string>,trusted:bool,current_request:bool}>
     */
    public static function getCandidates(): array
    {
        $config = self::config();
        $current = self::currentRequestCandidate();
        $candidates = self::loadCandidates();
        if ($current !== '' && !isset($candidates[$current])) {
            $now = time();
            $candidates[$current] = ['first_seen' => $now, 'last_seen' => $now, 'observations' => 1, 'headers' => self::presentForwardHeaders(), 'samples' => []];
        }

        $result = [];
        foreach ($candidates as $network => $entry) {
            $address = explode('/', (string) $network, 2)[0];
            $result[] = [
                'network' => (string) $network,
                'first_seen' => (int) ($entry['first_seen'] ?? 0),
                'last_seen' => (int) ($entry['last_seen'] ?? 0),
                'observations' => (int) ($entry['observations'] ?? 0),
                'distinct_clients' => count((array) ($entry['samples'] ?? [])),
                'headers' => array_values(array_map('strval', (array) ($entry['headers'] ?? []))),
                'trusted' => self::isTrusted($address, $config, false),
                'current_request' => $current !== '' && $current === (string) $network,
            ];
        }

        usort($result, static fn(array $a, array $b): int => [$b['current_request'], $b['last_seen']] <=> [$a['current_request'], $a['last_seen']]);

        return $result;
    }

    public static function removeCandidate(string $network): void
    {
        $candidates = self::loadCandidates();
        unset($candidates[$network]);
        self::storeCandidates($candidates);
    }

    public static function clearCandidates(): void
    {
        $file = self::candidateFile();
        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Header-Diagnose der aktuellen Anfrage für die Admin-Ansicht.
     *
     * @return array<string,string>
     */
    public static function currentForwardHeaders(): array
    {
        $headers = [];
        foreach (self::FORWARD_HEADERS as $serverKey => $label) {
            $value = trim((string) ($_SERVER[$serverKey] ?? ''));
            if ($value !== '') {
                $headers[$label] = function_exists('mb_substr') ? mb_substr($value, 0, 200) : substr($value, 0, 200);
            }
        }

        return $headers;
    }

    /**
     * @param array{any:bool,ranges:list<string>} $config
     */
    private static function isTrusted(string $ip, array $config, bool $allowAny = true): bool
    {
        if ($allowAny && $config['any']) {
            return true;
        }

        return $config['ranges'] !== [] && self::ipMatchesAny($ip, $config['ranges']);
    }

    /**
     * @return list<string>
     */
    private static function presentForwardHeaders(): array
    {
        $present = [];
        foreach (self::FORWARD_HEADERS as $serverKey => $label) {
            if (trim((string) ($_SERVER[$serverKey] ?? '')) !== '') {
                $present[] = $label;
            }
        }

        return $present;
    }

    /**
     * @return array{header:string,hops:list<string>}|array{}
     */
    private static function forwardedChain(): array
    {
        $xff = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($xff !== '') {
            $hops = array_values(array_filter(array_map(
                static fn(string $hop): string => self::stripPort(trim($hop)),
                explode(',', $xff)
            ), static fn(string $hop): bool => $hop !== ''));
            if ($hops !== []) {
                return ['header' => 'X-Forwarded-For', 'hops' => array_slice($hops, -20)];
            }
        }

        // RFC 7239: Forwarded: for=192.0.2.60;proto=http, for="[2001:db8::1]:4711"
        $forwarded = trim((string) ($_SERVER['HTTP_FORWARDED'] ?? ''));
        if ($forwarded !== '' && preg_match_all('/for=(?:"([^"]+)"|([^;,\s]+))/i', $forwarded, $matches, PREG_SET_ORDER) > 0) {
            $hops = [];
            foreach ($matches as $match) {
                $hop = self::stripPort(trim((string) (($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''))));
                if ($hop !== '') {
                    $hops[] = $hop;
                }
            }
            if ($hops !== []) {
                return ['header' => 'Forwarded', 'hops' => array_slice($hops, -20)];
            }
        }

        return [];
    }

    private static function stripPort(string $hop): string
    {
        if ($hop === '') {
            return '';
        }

        if (preg_match('/^\[([0-9a-f:.]+)\](?::\d+)?$/i', $hop, $match) === 1) {
            return $match[1];
        }

        if (substr_count($hop, ':') === 1 && preg_match('/^([0-9.]+):\d+$/', $hop, $match) === 1) {
            return $match[1];
        }

        return $hop;
    }

    private static function firstForwardedClient(): string
    {
        $chain = self::forwardedChain();
        if ($chain !== []) {
            return (string) end($chain['hops']);
        }

        foreach (['HTTP_X_REAL_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_CF_CONNECTING_IP'] as $header) {
            $value = trim((string) ($_SERVER[$header] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Merkt ein unbekanntes Proxy-Netz vor. Gespeichert werden nur Netz, Header-Namen,
     * Zeitstempel und gekürzte Hashes anonymisierter Client-Adressen. Die Client-Anzahl stammt
     * aus Request-Headern und ist daher nur ein Diagnosewert, kein Vertrauensmerkmal.
     *
     * @param list<string> $headers
     */
    private static function noteCandidate(string $proxyIp, array $headers, string $forwardedClient): void
    {
        if (self::$candidateNoted) {
            return;
        }
        self::$candidateNoted = true;

        // Auch private Adressen (lokaler nginx/Load Balancer) werden erfasst, seit `private`
        // nicht mehr standardmäßig vertraut wird.
        // IPv6-Loopback nicht auf ::/48 erweitern (würde IPv4-gemappte Adressen einschließen).
        $network = $proxyIp === '::1' ? '::1/128' : self::networkFor($proxyIp);
        if ($network === '' || !self::isValidRange($network)) {
            return;
        }
        self::$currentCandidate = $network;

        $file = self::candidateFile();
        if ($file === '') {
            return;
        }

        try {
            $now = time();
            $candidates = self::loadCandidates();
            $entry = is_array($candidates[$network] ?? null) ? $candidates[$network] : [
                'first_seen' => $now,
                'last_seen' => 0,
                'observations' => 0,
                'headers' => [],
                'samples' => [],
            ];

            $sample = '';
            $client = self::anonymizeIp($forwardedClient);
            if ($client !== '') {
                $sample = substr(hash('sha256', $network . '|' . $client), 0, 12);
            }

            $samples = array_values(array_map('strval', (array) ($entry['samples'] ?? [])));
            $newSample = $sample !== '' && !in_array($sample, $samples, true) && count($samples) < self::CANDIDATE_SAMPLE_LIMIT;
            $newHeaders = array_diff($headers, (array) ($entry['headers'] ?? []));

            if (!$newSample && $newHeaders === [] && ($now - (int) ($entry['last_seen'] ?? 0)) < self::CANDIDATE_WRITE_INTERVAL) {
                return;
            }

            if ($newSample) {
                $samples[] = $sample;
            }

            $entry['last_seen'] = $now;
            $entry['observations'] = (int) ($entry['observations'] ?? 0) + 1;
            $entry['headers'] = array_values(array_unique(array_merge((array) ($entry['headers'] ?? []), $headers)));
            $entry['samples'] = $samples;
            $candidates[$network] = $entry;

            self::storeCandidates($candidates);
        } catch (\Throwable) {
            // Diagnose darf Anfragen nie stören.
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function loadCandidates(): array
    {
        $file = self::candidateFile();
        if ($file === '' || !is_file($file)) {
            return [];
        }

        $raw = @file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return [];
        }

        $cutoff = time() - self::CANDIDATE_MAX_AGE;
        $result = [];
        foreach ($data as $network => $entry) {
            if (!is_string($network) || !is_array($entry) || !self::isValidRange($network)) {
                continue;
            }
            if ((int) ($entry['last_seen'] ?? 0) < $cutoff) {
                continue;
            }
            $result[$network] = $entry;
        }

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $candidates
     */
    private static function storeCandidates(array $candidates): void
    {
        $file = self::candidateFile();
        if ($file === '') {
            return;
        }

        uasort($candidates, static fn(array $a, array $b): int => (int) ($b['last_seen'] ?? 0) <=> (int) ($a['last_seen'] ?? 0));
        $candidates = array_slice($candidates, 0, self::CANDIDATE_LIMIT, true);

        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, (string) json_encode($candidates, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            @unlink($tmp);
            return;
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    private static function candidateFile(): string
    {
        if (!defined('ABSPATH')) {
            return '';
        }

        return rtrim((string) ABSPATH, '/\\') . '/cache/' . self::CANDIDATE_FILE;
    }

    private static function anonymizeIp(string $ip): string
    {
        $packed = @inet_pton(trim($ip));
        if ($packed === false) {
            return '';
        }

        if (strlen($packed) === 4) {
            return (string) inet_ntop(substr($packed, 0, 3) . "\0");
        }

        return (string) inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10));
    }

    private static function readOption(): ?string
    {
        // Vor dem Datenbank-Bootstrap (Installer, frühe Fehlerseiten) keine Verbindung erzwingen.
        if (!class_exists(\CMS\Database::class, false) || !class_exists(OptionStore::class)) {
            return null;
        }

        try {
            return OptionStore::getInstance()->get(self::OPTION_KEY);
        } catch (\Throwable) {
            return null;
        }
    }
}
