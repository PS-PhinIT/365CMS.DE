<?php
/**
 * Schlanker S3-Client ohne SDK (4.1.0, Bereich 4a): PUT, Multipart-Upload, HEAD, ListObjectsV2, DELETE.
 *
 * Alle Anfragen laufen über `CMS\Http\Client` (SSRF-Schutz, DNS-Pinning); ein privater Host ist nur erlaubt, wenn die
 * Konfiguration ihn ausdrücklich freigibt (MinIO im eigenen Netz). Teile werden einzeln von der Platte gelesen; bei
 * einem Fehler im Multipart-Upload wird der Upload abgebrochen (`abort`). XML-Antworten werden ohne Netzwerkzugriff
 * und mit Größenlimit gelesen.
 *
 * Transport (HTTP) und Uhr sind für Tests ersetzbar.
 *
 * @package CMSv2\Core\Services\Backup
 */

declare(strict_types=1);

namespace CMS\Services\Backup;

if (!defined('ABSPATH')) {
    exit;
}

final class S3Client
{
    public const SINGLE_PUT_LIMIT = 64 * 1024 * 1024;
    private const MAX_XML_BYTES = 5 * 1024 * 1024;

    /** @var \Closure(string, string, array<string, string>, ?string): array{success: bool, status: int, headers: array<string, string>, body: string, error?: string} */
    private \Closure $transport;
    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(private readonly S3Config $config, ?\Closure $transport = null, ?\Closure $clock = null)
    {
        $this->transport = $transport ?? $this->defaultTransport();
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function config(): S3Config
    {
        return $this->config;
    }

    /**
     * Einzel-PUT (Datei in einem Stück, höchstens 64 MB).
     *
     * @return array{success: bool, etag?: string, error?: string}
     */
    public function putObject(string $key, string $localFile, string $contentType = 'application/octet-stream'): array
    {
        $size = @filesize($localFile);
        if (!is_int($size) || $size > self::SINGLE_PUT_LIMIT) {
            return ['success' => false, 'error' => 'Datei fehlt oder ist für einen Einzel-Upload zu groß.'];
        }
        $body = (string) @file_get_contents($localFile);
        $headers = ['content-type' => $contentType];
        if ($this->config->storageClass !== '') {
            $headers['x-amz-storage-class'] = $this->config->storageClass;
        }
        $response = $this->send('PUT', $this->config->objectUrl($key), $headers, $body);
        if (!$response['success']) {
            return ['success' => false, 'error' => $this->errorFrom($response)];
        }

        return ['success' => true, 'etag' => trim((string) ($response['headers']['etag'] ?? ''), '"')];
    }

    /**
     * PUT bis 64 MB bzw. bis zur Teilgröße, darüber Multipart.
     *
     * @return array{success: bool, etag?: string, error?: string, parts?: int}
     */
    public function uploadFile(string $key, string $localFile, string $contentType = 'application/octet-stream'): array
    {
        $size = @filesize($localFile);
        if (!is_int($size)) {
            return ['success' => false, 'error' => 'Datei nicht lesbar.'];
        }
        $partSize = $this->config->partSizeMb * 1024 * 1024;

        return $size <= min(self::SINGLE_PUT_LIMIT, max($partSize, 5 * 1024 * 1024))
            ? $this->putObject($key, $localFile, $contentType)
            : $this->multipartUpload($key, $localFile, $contentType, $partSize);
    }

    /**
     * @return array{success: bool, etag?: string, error?: string, parts?: int}
     */
    public function multipartUpload(string $key, string $localFile, string $contentType, int $partSize): array
    {
        $uploadId = $this->createMultipartUpload($key, $contentType);
        if ($uploadId === null) {
            return ['success' => false, 'error' => 'Multipart-Upload konnte nicht gestartet werden.'];
        }
        $handle = @fopen($localFile, 'rb');
        if ($handle === false) {
            $this->abortMultipartUpload($key, $uploadId);

            return ['success' => false, 'error' => 'Datei nicht lesbar.'];
        }
        $etags = [];
        $partNumber = 0;
        try {
            while (!feof($handle)) {
                $chunk = (string) fread($handle, $partSize);
                if ($chunk === '' && $partNumber > 0) {
                    break;
                }
                $partNumber++;
                $etag = $this->uploadPart($key, $uploadId, $partNumber, $chunk);
                if ($etag === null) {
                    $this->abortMultipartUpload($key, $uploadId);

                    return ['success' => false, 'error' => sprintf('Teil %d konnte nicht hochgeladen werden; Upload abgebrochen.', $partNumber)];
                }
                $etags[$partNumber] = $etag;
            }
        } finally {
            fclose($handle);
        }
        $final = $this->completeMultipartUpload($key, $uploadId, $etags);
        if ($final === null) {
            $this->abortMultipartUpload($key, $uploadId);

            return ['success' => false, 'error' => 'Multipart-Upload konnte nicht abgeschlossen werden; Upload abgebrochen.'];
        }

        return ['success' => true, 'etag' => $final, 'parts' => count($etags)];
    }

    public function createMultipartUpload(string $key, string $contentType): ?string
    {
        $headers = ['content-type' => $contentType];
        if ($this->config->storageClass !== '') {
            $headers['x-amz-storage-class'] = $this->config->storageClass;
        }
        $response = $this->send('POST', $this->config->objectUrl($key, 'uploads='), $headers, '');
        $xml = $response['success'] ? self::parseXml($response['body']) : null;
        $uploadId = $xml !== null ? trim((string) ($xml->UploadId ?? '')) : '';

        return $uploadId !== '' ? $uploadId : null;
    }

    public function uploadPart(string $key, string $uploadId, int $partNumber, string $data): ?string
    {
        $response = $this->send(
            'PUT',
            $this->config->objectUrl($key, 'partNumber=' . $partNumber . '&uploadId=' . rawurlencode($uploadId)),
            [],
            $data
        );
        $etag = trim((string) ($response['headers']['etag'] ?? ''));

        return $response['success'] && $etag !== '' ? $etag : null;
    }

    /**
     * @param array<int, string> $etags Teilnummer → ETag (mit Anführungszeichen, wie geliefert)
     */
    public function completeMultipartUpload(string $key, string $uploadId, array $etags): ?string
    {
        ksort($etags);
        $body = '<CompleteMultipartUpload>';
        foreach ($etags as $number => $etag) {
            $body .= '<Part><PartNumber>' . (int) $number . '</PartNumber><ETag>' . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part>';
        }
        $body .= '</CompleteMultipartUpload>';
        $response = $this->send('POST', $this->config->objectUrl($key, 'uploadId=' . rawurlencode($uploadId)), ['content-type' => 'application/xml'], $body);
        if (!$response['success']) {
            return null;
        }
        // S3 kann trotz Status 200 einen Fehler im Body melden.
        $xml = self::parseXml($response['body']);
        if ($xml === null || $xml->getName() === 'Error') {
            return null;
        }

        return trim((string) ($xml->ETag ?? ''), '"') ?: 'ok';
    }

    public function abortMultipartUpload(string $key, string $uploadId): bool
    {
        return $this->send('DELETE', $this->config->objectUrl($key, 'uploadId=' . rawurlencode($uploadId)), [], null)['success'];
    }

    /**
     * @return array{exists: bool, size: int, etag: string, error?: string}
     */
    public function headObject(string $key): array
    {
        $response = $this->send('HEAD', $this->config->objectUrl($key), [], null);
        if (!$response['success']) {
            return ['exists' => false, 'size' => 0, 'etag' => '', 'error' => $this->errorFrom($response)];
        }

        return [
            'exists' => true,
            'size' => (int) ($response['headers']['content-length'] ?? 0),
            'etag' => trim((string) ($response['headers']['etag'] ?? ''), '"'),
        ];
    }

    /**
     * ListObjectsV2 (eine Seite).
     *
     * @return array{success: bool, keys: list<array{key: string, size: int, modified: string}>, next: ?string, error?: string}
     */
    public function listObjects(string $prefix, ?string $continuationToken = null): array
    {
        $query = 'list-type=2&prefix=' . rawurlencode($prefix);
        if ($continuationToken !== null && $continuationToken !== '') {
            $query .= '&continuation-token=' . rawurlencode($continuationToken);
        }
        $response = $this->send('GET', $this->config->objectUrl('', $query), [], null);
        if (!$response['success']) {
            return ['success' => false, 'keys' => [], 'next' => null, 'error' => $this->errorFrom($response)];
        }
        $xml = self::parseXml($response['body']);
        if ($xml === null) {
            return ['success' => false, 'keys' => [], 'next' => null, 'error' => 'Antwort des Speichers ist kein gültiges XML.'];
        }
        $keys = [];
        foreach ($xml->Contents ?? [] as $item) {
            $keys[] = ['key' => (string) $item->Key, 'size' => (int) $item->Size, 'modified' => (string) $item->LastModified];
        }
        $truncated = strtolower((string) ($xml->IsTruncated ?? 'false')) === 'true';

        return ['success' => true, 'keys' => $keys, 'next' => $truncated ? (string) ($xml->NextContinuationToken ?? '') : null];
    }

    /**
     * Alle Objekte unter einem Präfix (Seiten nacheinander, höchstens $maxPages).
     *
     * @return array{success: bool, keys: list<array{key: string, size: int, modified: string}>, error?: string}
     */
    public function listAll(string $prefix, int $maxPages = 50): array
    {
        $all = [];
        $token = null;
        for ($page = 0; $page < $maxPages; $page++) {
            $result = $this->listObjects($prefix, $token);
            if (!$result['success']) {
                return ['success' => false, 'keys' => $all, 'error' => $result['error'] ?? 'Auflisten fehlgeschlagen.'];
            }
            array_push($all, ...$result['keys']);
            $token = $result['next'];
            if ($token === null || $token === '') {
                break;
            }
        }

        return ['success' => true, 'keys' => $all];
    }

    public function deleteObject(string $key): bool
    {
        return $this->send('DELETE', $this->config->objectUrl($key), [], null)['success'];
    }

    public static function parseXml(string $body): ?\SimpleXMLElement
    {
        $body = trim($body);
        if ($body === '' || strlen($body) > self::MAX_XML_BYTES || stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $xml instanceof \SimpleXMLElement ? $xml : null;
    }

    // ── Intern ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $headers
     * @return array{success: bool, status: int, headers: array<string, string>, body: string, error?: string}
     */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        $payloadHash = $body === null || $body === '' ? SigV4Signer::EMPTY_PAYLOAD_HASH : hash('sha256', $body);
        $signed = SigV4Signer::sign(
            $method,
            $url,
            $headers,
            $payloadHash,
            gmdate('Ymd\THis\Z', ($this->clock)()),
            $this->config->region,
            's3',
            $this->config->accessKey,
            $this->config->secretKey
        );

        return ($this->transport)($method, $url, $signed, $body);
    }

    /**
     * Fehlertext ohne Zugangsdaten: S3-Fehlercode aus dem XML, sonst HTTP-Status.
     *
     * @param array{status: int, body: string, error?: string} $response
     */
    private function errorFrom(array $response): string
    {
        $xml = self::parseXml((string) ($response['body'] ?? ''));
        $code = $xml !== null ? trim((string) ($xml->Code ?? '')) : '';
        if ($code !== '') {
            return 'Speicher meldet ' . mb_substr($code, 0, 60) . ' (HTTP ' . (int) $response['status'] . ').';
        }

        return (int) $response['status'] > 0 ? 'Speicher antwortet mit HTTP ' . (int) $response['status'] . '.' : 'Speicher nicht erreichbar.';
    }

    private function defaultTransport(): \Closure
    {
        $config = $this->config;

        return static function (string $method, string $url, array $headers, ?string $body) use ($config): array {
            $lines = [];
            foreach ($headers as $name => $value) {
                if ($name === 'host') {
                    continue;
                }
                $lines[] = $name . ': ' . $value;
            }
            $options = ['headers' => $lines, 'timeout' => 300, 'connectTimeout' => 10, 'maxBytes' => self::MAX_XML_BYTES];
            if ($config->allowPrivateHost) {
                $options['allowedPrivateHosts'] = [$config->hostname()];
            }
            if ($body !== null) {
                $options['body'] = $body;
            }
            $client = \CMS\Http\Client::getInstance();
            $result = match ($method) {
                'GET' => $client->get($url, $options),
                'HEAD' => $client->head($url, $options),
                'DELETE' => $client->delete($url, $options),
                'PUT' => $client->put($url, (string) $body, $options),
                default => $client->post($url, (string) $body, $options),
            };

            return [
                'success' => !empty($result['success']),
                'status' => (int) ($result['status'] ?? 0),
                'headers' => array_change_key_case((array) ($result['headers'] ?? []), CASE_LOWER),
                'body' => (string) ($result['body'] ?? ''),
                'error' => (string) ($result['error'] ?? ''),
            ];
        };
    }
}
