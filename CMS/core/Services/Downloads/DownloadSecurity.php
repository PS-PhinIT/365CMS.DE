<?php
/**
 * Reine Hilfsfunktionen der Downloads (ohne Datenbank): HMAC-Signaturen, Prüfung externer Ziele,
 * Dateinamen, Range-Header, Bot-Erkennung, Slugs und Versionsnummern.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Downloads;

if (!defined('ABSPATH')) {
    exit;
}

final class DownloadSecurity
{
    public const EXTERNAL_TOKEN_TTL = 900;
    public const SOURCE_TYPES = ['post', 'page'];
    public const VERSION_PATTERN = '[0-9A-Za-z][0-9A-Za-z._-]{0,49}';
    public const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    private const BOT_PATTERN = '/bot\b|bot\/|crawl|spider|slurp|scrape|fetch|monitor|preview|headless|lighthouse|pingdom|uptime|curl\/|wget\/|python-requests|python-urllib|go-http-client|okhttp|java\/|libwww|httpclient|facebookexternalhit|embedly|whatsapp|telegram|discord|skype|bingpreview|yandex|baidu|semrush|ahrefs|mj12|petalbot|dataforseo/i';

    private static ?string $secret = null;

    /**
     * Geheimnis für alle HMAC-Signaturen der Downloads (aus der Konfiguration, nie im Klartext ausgegeben).
     */
    public static function secret(): string
    {
        if (self::$secret !== null) {
            return self::$secret;
        }

        foreach (['NONCE_KEY', 'SECURE_AUTH_KEY', 'AUTH_KEY', 'JWT_SECRET'] as $constant) {
            if (!defined($constant)) {
                continue;
            }
            $value = (string) constant($constant);
            if (strlen($value) >= 16 && !str_contains($value, 'REPLACE_VIA_INSTALLER')) {
                return self::$secret = hash('sha256', 'cms-downloads|' . $value);
            }
        }

        return self::$secret = hash('sha256', 'cms-downloads|' . (defined('ABSPATH') ? ABSPATH : '') . '|' . __FILE__ . '|' . PHP_VERSION);
    }

    public static function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, self::secret());
    }

    /**
     * Signierter Quellseiten-Parameter (nur Typ und ID des Beitrags/der Seite, keine Personendaten).
     */
    public static function signSource(string $type, int $id): string
    {
        if (!in_array($type, self::SOURCE_TYPES, true) || $id <= 0) {
            return '';
        }

        return $type . '-' . $id . '-' . substr(self::hmac('src|' . $type . '|' . $id), 0, 16);
    }

    /**
     * @return array{type:string,id:int}|null
     */
    public static function verifySource(string $token): ?array
    {
        if (preg_match('/^(post|page)-([1-9]\d{0,9})-([a-f0-9]{16})$/', $token, $m) !== 1) {
            return null;
        }
        $expected = substr(self::hmac('src|' . $m[1] . '|' . $m[2]), 0, 16);

        return hash_equals($expected, $m[3]) ? ['type' => $m[1], 'id' => (int) $m[2]] : null;
    }

    public static function externalToken(int $downloadId, int $versionId, int $expires): string
    {
        return self::hmac('ext|' . $downloadId . '|' . $versionId . '|' . $expires);
    }

    public static function verifyExternalToken(int $downloadId, int $versionId, int $expires, string $token, ?int $now = null): bool
    {
        $now ??= time();
        if ($token === '' || $expires < $now || $expires > $now + self::EXTERNAL_TOKEN_TTL + 60) {
            return false;
        }

        return hash_equals(self::externalToken($downloadId, $versionId, $expires), $token);
    }

    /**
     * Externes Ziel erlaubt? http/https, ohne Zugangsdaten, Port 80/443, öffentlicher Host, optional Allowlist.
     */
    public static function isAllowedExternalUrl(string $url, string $allowlist = ''): bool
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return false;
        }
        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            return false;
        }

        $host = trim(strtolower((string) $parts['host']), '[]');
        if (!self::isPublicHost($host)) {
            return false;
        }

        $domains = DownloadSettings::parseDomainList($allowlist);
        if ($domains === []) {
            return true;
        }
        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    public static function isPublicHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            return false;
        }
        foreach (['.localhost', '.local', '.internal', '.intranet', '.lan', '.home', '.corp'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return preg_match('/^(?=.{1,253}$)(?!-)(?:[a-z0-9-]{1,63}\.)+[a-z]{2,63}$/', $host) === 1;
    }

    /**
     * Anzeigename einer Datei für Content-Disposition (UTF-8, ohne Steuerzeichen/Pfadtrenner, max. 180 Zeichen).
     */
    public static function safeFilename(string $filename): string
    {
        $filename = str_replace('\\', '/', $filename);
        $filename = basename($filename);
        $filename = (string) preg_replace('~[\x00-\x1F\x7F"\\\\/]+~u', '-', $filename);
        $filename = trim($filename, " .\t\n\r\0\x0B-");
        if ($filename === '') {
            return 'download.bin';
        }

        return function_exists('mb_substr') ? mb_substr($filename, 0, 180, 'UTF-8') : substr($filename, 0, 180);
    }

    public static function asciiFilename(string $filename): string
    {
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', self::transliterate($filename)), '.-_');

        return $ascii !== '' ? substr($ascii, 0, 180) : 'download.bin';
    }

    public static function contentDisposition(string $filename): string
    {
        $filename = self::safeFilename($filename);

        return 'attachment; filename="' . self::asciiFilename($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }

    public static function isBot(string $userAgent): bool
    {
        $userAgent = trim($userAgent);
        if ($userAgent === '') {
            return true;
        }

        return preg_match(self::BOT_PATTERN, $userAgent) === 1;
    }

    /**
     * Wertet einen Range-Header aus (nur ein Bereich, auch Suffix-Bereiche).
     *
     * @return array{0:int,1:int}|false|null Bereich [Start, Ende], null = ganze Datei, false = nicht erfüllbar (416)
     */
    public static function parseRange(string $header, int $size): array|false|null
    {
        $header = trim($header);
        if ($header === '' || $size <= 0) {
            return null;
        }
        if (preg_match('/^bytes=(\d*)-(\d*)$/i', $header, $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
            // Mehrfach- oder ungültige Bereiche: ganze Datei liefern (RFC 9110 erlaubt das Ignorieren).
            return null;
        }

        if ($m[1] === '') {
            $length = (int) $m[2];
            if ($length <= 0) {
                return false;
            }

            return [max(0, $size - $length), $size - 1];
        }

        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        if ($start >= $size || $start > $end) {
            return false;
        }

        return [$start, $end];
    }

    public static function transliterate(string $text): string
    {
        $map = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'å' => 'a',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ø' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae',
        ];
        $text = strtr($text, $map);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($converted)) {
                $text = $converted;
            }
        }

        return $text;
    }

    public static function slugify(string $text): string
    {
        $slug = strtolower(self::transliterate(trim($text)));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');

        return substr($slug, 0, 180) !== '' ? rtrim(substr($slug, 0, 180), '-') : '';
    }

    public static function isValidSlug(string $slug): bool
    {
        return strlen($slug) <= 190 && preg_match('/^' . self::SLUG_PATTERN . '$/', $slug) === 1;
    }

    /**
     * Versionsnummer bereinigen: erlaubt Ziffern, Buchstaben, Punkt, Unterstrich, Bindestrich (max. 50 Zeichen).
     */
    public static function normalizeVersion(string $version): string
    {
        $version = trim($version);
        $version = (string) preg_replace('/^v(?=\d)/i', '', $version);
        $version = (string) preg_replace('/\s+/', '-', $version);
        $version = (string) preg_replace('/[^0-9A-Za-z._-]+/', '', $version);
        $version = ltrim($version, '._-');

        return substr($version, 0, 50);
    }

    public static function isValidVersion(string $version): bool
    {
        return preg_match('/^' . self::VERSION_PATTERN . '$/', $version) === 1;
    }

    /**
     * Schlüssel für Grenzwerte und Deduplizierung: HMAC über Sitzung bzw. IP+User-Agent (nie im Klartext gespeichert).
     */
    public static function visitorKey(string $sessionId, string $ip, string $userAgent): string
    {
        $basis = $sessionId !== '' ? 'sid|' . $sessionId : 'ip|' . $ip . '|' . substr($userAgent, 0, 300);

        return self::hmac('visitor|' . $basis);
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return number_format($value, $value >= 10 ? 0 : 1, ',', '.') . ' ' . $units[$unit];
    }
}
