<?php
/**
 * AWS Signature Version 4 (rein funktional, ohne SDK) für S3-kompatible Offsite-Backups (4.1.0, Bereich 4a).
 *
 * Kanonische Anfrage nach AWS-Vorgabe: URI-Kodierung je Pfadsegment (RFC 3986, `/` bleibt), sortierte und kodierte
 * Query, Header klein geschrieben, Werte getrimmt und Leerraum zusammengefasst, sortiert. Geprüft mit den offiziellen
 * SigV4-Testvektoren und dem S3-Beispiel „GET Object“ (TESTS/s3-backup).
 *
 * @package CMSv2\Core\Services\Backup
 */

declare(strict_types=1);

namespace CMS\Services\Backup;

if (!defined('ABSPATH')) {
    exit;
}

final class SigV4Signer
{
    public const ALGORITHM = 'AWS4-HMAC-SHA256';
    public const EMPTY_PAYLOAD_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    public const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    /**
     * Signiert eine Anfrage und liefert die zu sendenden Header (inkl. `Authorization`, `x-amz-date`; für S3 auch
     * `x-amz-content-sha256`).
     *
     * @param array<string, string> $headers Header der Anfrage (ohne Authorization); `host` wird aus der URL ergänzt
     * @return array<string, string> Header-Name → Wert
     */
    public static function sign(
        string $method,
        string $url,
        array $headers,
        string $payloadHash,
        string $amzDate,
        string $region,
        string $service,
        string $accessKey,
        string $secretKey,
        bool $addContentSha = true
    ): array {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            $host .= ':' . (int) $parts['port'];
        }
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower(trim((string) $name))] = (string) $value;
        }
        $normalized['host'] = $normalized['host'] ?? $host;
        $normalized['x-amz-date'] = $amzDate;
        if ($addContentSha) {
            $normalized['x-amz-content-sha256'] = $payloadHash;
        }
        unset($normalized['authorization']);

        [$canonicalHeaders, $signedHeaders] = self::canonicalHeaders($normalized);
        $canonicalRequest = implode("\n", [
            strtoupper($method),
            self::canonicalUri((string) ($parts['path'] ?? '/'), $service),
            self::canonicalQuery((string) ($parts['query'] ?? '')),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);
        $date = substr($amzDate, 0, 8);
        $scope = $date . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = implode("\n", [self::ALGORITHM, $amzDate, $scope, hash('sha256', $canonicalRequest)]);
        $signature = hash_hmac('sha256', $stringToSign, self::signingKey($secretKey, $date, $region, $service));

        $normalized['authorization'] = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $accessKey,
            $scope,
            $signedHeaders,
            $signature
        );

        return $normalized;
    }

    public static function signingKey(string $secretKey, string $date, string $region, string $service): string
    {
        $key = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', $service, $key, true);

        return hash_hmac('sha256', 'aws4_request', $key, true);
    }

    /**
     * Pfad kodieren: je Segment RFC 3986. S3 normalisiert Pfade nicht (keine Auflösung von `.`/`..`, doppelte
     * Schrägstriche bleiben); andere Dienste nach Vorgabe ebenfalls nur kodiert.
     */
    public static function canonicalUri(string $path, string $service = 's3'): string
    {
        if ($path === '') {
            return '/';
        }
        $segments = explode('/', $path);
        $encoded = array_map(static fn (string $segment): string => rawurlencode(rawurldecode($segment)), $segments);

        return implode('/', $encoded);
    }

    public static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [rawurlencode(rawurldecode($key)), rawurlencode(rawurldecode($value))];
        }
        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(static fn (array $pair): string => $pair[0] . '=' . $pair[1], $pairs));
    }

    /**
     * @param array<string, string> $headers klein geschriebene Namen
     * @return array{0: string, 1: string} kanonische Header (mit abschließendem Zeilenumbruch), signierte Header
     */
    public static function canonicalHeaders(array $headers): array
    {
        ksort($headers, SORT_STRING);
        $lines = '';
        foreach ($headers as $name => $value) {
            $lines .= $name . ':' . trim((string) preg_replace('/\s+/', ' ', $value)) . "\n";
        }

        return [$lines, implode(';', array_keys($headers))];
    }
}
