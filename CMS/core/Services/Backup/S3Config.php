<?php
/**
 * Konfiguration eines S3-kompatiblen Offsite-Ziels (AWS S3, Cloudflare R2, Hetzner Object Storage, MinIO …).
 *
 * `endpoint` ist der Host ohne Schema (z. B. `s3.eu-central-1.amazonaws.com`, `fsn1.your-objectstorage.com`,
 * `<account>.r2.cloudflarestorage.com`). HTTPS ist Pflicht; `http` nur zusammen mit `allow_private_host` (MinIO im
 * eigenen Netz). `path_style` adressiert `https://endpoint/bucket/key` statt `https://bucket.endpoint/key`.
 *
 * @package CMSv2\Core\Services\Backup
 */

declare(strict_types=1);

namespace CMS\Services\Backup;

if (!defined('ABSPATH')) {
    exit;
}

final class S3Config
{
    public const MIN_PART_MB = 5;
    public const MAX_PART_MB = 64;

    public function __construct(
        public readonly string $endpoint,
        public readonly string $region,
        public readonly string $bucket,
        public readonly string $prefix,
        public readonly string $accessKey,
        public readonly string $secretKey,
        public readonly bool $pathStyle = true,
        public readonly int $partSizeMb = 16,
        public readonly string $storageClass = '',
        public readonly bool $allowPrivateHost = false,
        public readonly bool $insecureHttp = false
    ) {
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $text = static fn (string $key): string => trim(is_scalar($values[$key] ?? null) ? (string) $values[$key] : '');

        return new self(
            strtolower((string) preg_replace('#^https?://#i', '', rtrim($text('endpoint'), '/'))),
            $text('region') !== '' ? $text('region') : 'us-east-1',
            $text('bucket'),
            self::normalizePrefix($text('prefix')),
            $text('access_key'),
            $text('secret_key'),
            !empty($values['path_style']),
            max(self::MIN_PART_MB, min(self::MAX_PART_MB, (int) ($values['part_size_mb'] ?? 16) ?: 16)),
            preg_match('/^[A-Z_]{0,40}$/', $text('storage_class')) === 1 ? $text('storage_class') : '',
            !empty($values['allow_private_host']),
            !empty($values['insecure_http'])
        );
    }

    /** Präfix: nur `[A-Za-z0-9._/-]`, ohne `..`, mit abschließendem `/` (leer erlaubt). */
    public static function normalizePrefix(string $prefix): string
    {
        $prefix = trim(str_replace('\\', '/', $prefix), '/ ');
        if ($prefix === '') {
            return '';
        }
        if (preg_match('#^[A-Za-z0-9._/-]{1,200}$#', $prefix) !== 1 || str_contains($prefix, '..') || str_contains($prefix, '//')) {
            return '';
        }

        return $prefix . '/';
    }

    /**
     * @return list<string> Fehlermeldungen (leer = gültig)
     */
    public function validate(): array
    {
        $errors = [];
        if (preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?(:\d{2,5})?$/', $this->endpoint) !== 1) {
            $errors[] = 'Endpunkt: bitte nur den Hostnamen angeben (z. B. s3.eu-central-1.amazonaws.com).';
        }
        if (preg_match('/^[a-z0-9-]{2,40}$/', $this->region) !== 1) {
            $errors[] = 'Region ist ungültig.';
        }
        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $this->bucket) !== 1) {
            $errors[] = 'Bucket-Name ist ungültig (3–63 Zeichen: Kleinbuchstaben, Ziffern, Punkt, Bindestrich).';
        }
        if ($this->accessKey === '' || $this->secretKey === '') {
            $errors[] = 'Zugangsschlüssel und geheimer Schlüssel sind Pflicht.';
        }
        if ($this->insecureHttp && !$this->allowPrivateHost) {
            $errors[] = 'HTTP ohne TLS ist nur für einen freigegebenen privaten Host (MinIO im eigenen Netz) erlaubt.';
        }

        return $errors;
    }

    public function host(): string
    {
        return $this->pathStyle ? $this->endpoint : $this->bucket . '.' . $this->endpoint;
    }

    /** URL eines Objekts (Schlüssel wird je Segment kodiert). */
    public function objectUrl(string $key, string $query = ''): string
    {
        $path = implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
        $base = ($this->insecureHttp ? 'http://' : 'https://') . $this->host() . '/' . ($this->pathStyle ? $this->bucket . '/' : '');

        return $base . $path . ($query !== '' ? '?' . $query : '');
    }

    /** Hostname ohne Port (für die SSRF-Freigabe). */
    public function hostname(): string
    {
        return (string) preg_replace('/:\d+$/', '', $this->host());
    }
}
