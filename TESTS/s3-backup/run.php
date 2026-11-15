<?php
/**
 * Offsite-Backups (4.1.0, Bereich 4a): SigV4 gegen die AWS-Testvektoren und das S3-Beispiel „GET Object“,
 * kanonische URI/Query, Path-Style vs. Virtual-Host, Präfix-Prüfung, S3-Client mit Fake-Speicher (PUT, Multipart mit
 * Teilgrößen, ETags, Abschluss-XML und Abbruch, HEAD, ListObjectsV2 mit Seiten, DELETE, XML ohne DOCTYPE),
 * Offsite-Dienst (nur verschlüsselte Backups, Reihenfolge, Status, Nachholen, Aufbewahrung behält das neueste
 * erfolgreiche Backup) und Quelltext-Prüfungen der Einbindung.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/s3-backup/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once dirname(__DIR__) . '/lib/TestRunner.php';

use CMS\Database;
use CMS\Services\Backup\OffsiteBackupService as O;
use CMS\Services\Backup\S3Client;
use CMS\Services\Backup\S3Config;
use CMS\Services\Backup\SigV4Signer as Sig;

T::$verbose = in_array('-v', $argv ?? [], true);

function s3Source(string $relative): string
{
    return (string) file_get_contents(ABSPATH . $relative);
}

/** Fake-S3 im Speicher; zeichnet alle Anfragen auf. */
final class FakeS3
{
    /** @var array<string, string> Pfad → Inhalt */
    public array $objects = [];
    /** @var list<array{method: string, url: string, headers: array<string, string>, size: int}> */
    public array $requests = [];
    /** @var array<string, array<int, string>> */
    public array $uploads = [];
    public int $failPart = 0;
    public int $pageSize = 1000;
    public bool $failDelete = false;

    public function transport(): \Closure
    {
        return function (string $method, string $url, array $headers, ?string $body): array {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'size' => strlen((string) $body)];
            $parts = parse_url($url);
            $path = rawurldecode((string) ($parts['path'] ?? '/'));
            parse_str((string) ($parts['query'] ?? ''), $query);
            $ok = static fn (string $body = '', array $h = []): array => ['success' => true, 'status' => 200, 'headers' => $h, 'body' => $body];
            $missing = ['success' => false, 'status' => 404, 'headers' => [], 'body' => '<Error><Code>NoSuchKey</Code></Error>'];

            if ($method === 'POST' && array_key_exists('uploads', $query)) {
                $id = 'up' . count($this->uploads);
                $this->uploads[$id] = [];

                return $ok('<InitiateMultipartUploadResult><UploadId>' . $id . '</UploadId></InitiateMultipartUploadResult>');
            }
            if ($method === 'PUT' && isset($query['partNumber'])) {
                if ((int) $query['partNumber'] === $this->failPart) {
                    return ['success' => false, 'status' => 500, 'headers' => [], 'body' => '<Error><Code>InternalError</Code></Error>'];
                }
                $this->uploads[$query['uploadId']][(int) $query['partNumber']] = (string) $body;

                return $ok('', ['etag' => '"etag-' . $query['partNumber'] . '"']);
            }
            if ($method === 'POST' && isset($query['uploadId'])) {
                ksort($this->uploads[$query['uploadId']]);
                $this->objects[$path] = implode('', $this->uploads[$query['uploadId']]);

                return $ok('<CompleteMultipartUploadResult><ETag>"final"</ETag></CompleteMultipartUploadResult>');
            }
            if ($method === 'DELETE' && isset($query['uploadId'])) {
                unset($this->uploads[$query['uploadId']]);

                return $ok();
            }
            if ($method === 'PUT') {
                $this->objects[$path] = (string) $body;

                return $ok('', ['etag' => '"' . md5((string) $body) . '"']);
            }
            if ($method === 'HEAD') {
                return isset($this->objects[$path]) ? $ok('', ['content-length' => (string) strlen($this->objects[$path]), 'etag' => '"x"']) : $missing;
            }
            if ($method === 'DELETE') {
                if ($this->failDelete) {
                    return ['success' => false, 'status' => 403, 'headers' => [], 'body' => ''];
                }
                unset($this->objects[$path]);

                return $ok();
            }
            if ($method === 'GET' && ($query['list-type'] ?? '') === '2') {
                $bucketPath = rtrim($path, '/') . '/';
                $keys = [];
                foreach ($this->objects as $objectPath => $content) {
                    $key = substr($objectPath, strlen($bucketPath));
                    if (str_starts_with($objectPath, $bucketPath) && str_starts_with($key, (string) ($query['prefix'] ?? ''))) {
                        $keys[] = $key;
                    }
                }
                sort($keys);
                $offset = (int) ($query['continuation-token'] ?? 0);
                $page = array_slice($keys, $offset, $this->pageSize);
                $xml = '<ListBucketResult>';
                foreach ($page as $key) {
                    $xml .= '<Contents><Key>' . htmlspecialchars($key, ENT_XML1) . '</Key><Size>1</Size><LastModified>' . ($this->modified[$key] ?? '2026-11-15T10:00:00.000Z') . '</LastModified></Contents>';
                }
                $more = $offset + $this->pageSize < count($keys);
                $xml .= '<IsTruncated>' . ($more ? 'true' : 'false') . '</IsTruncated>' . ($more ? '<NextContinuationToken>' . ($offset + $this->pageSize) . '</NextContinuationToken>' : '') . '</ListBucketResult>';

                return $ok($xml);
            }

            return $missing;
        };
    }

    /** @var array<string, string> Schlüssel → LastModified */
    public array $modified = [];
}

function s3Config(array $overrides = []): S3Config
{
    return S3Config::fromArray($overrides + [
        'endpoint' => 's3.example.test', 'region' => 'eu-central-1', 'bucket' => 'backups', 'prefix' => 'site-a',
        'access_key' => 'AKIDEXAMPLE', 'secret_key' => 'secret', 'path_style' => '1', 'part_size_mb' => 5,
    ]);
}

function s3Database(): Database
{
    return new class () extends Database {
        public PDO $sqlite;

        public function __construct()
        {
            $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->sqlite->exec('CREATE TABLE cms_backup_offsite_uploads (id INTEGER PRIMARY KEY AUTOINCREMENT, backup_name TEXT, target TEXT, object_prefix TEXT,
                status TEXT, bytes INTEGER, files INTEGER, attempts INTEGER, last_error TEXT, uploaded_at TEXT, created_at TEXT, updated_at TEXT, UNIQUE (backup_name, target))');
        }

        public function execute(string $sql, array $params = []): \PDOStatement
        {
            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute(array_values($params));

            return $stmt;
        }

        public function get_var(string $query, array $params = []): mixed
        {
            $value = $this->execute($query, $params)->fetchColumn();

            return $value === false ? null : $value;
        }

        public function get_results(string $query, array $params = []): array
        {
            return $this->execute($query, $params)->fetchAll(PDO::FETCH_OBJ);
        }

        public function getPrefix(): string
        {
            return 'cms_';
        }
    };
}

/** Legt ein Backup-Verzeichnis an; $encrypted = Dateien mit CMSENC2-Kopf und Manifest mit encryption. */
function s3MakeBackup(string $root, string $name, bool $encrypted = true, int $bigBytes = 0): void
{
    @mkdir($root . $name, 0700, true);
    $magic = $encrypted ? "CMSENC2\0" : '';
    file_put_contents($root . $name . '/database.sql.gz.enc', $magic . 'db-daten');
    file_put_contents($root . $name . '/files.zip.enc', $magic . ($bigBytes > 0 ? str_repeat('x', $bigBytes) : 'zip-daten'));
    file_put_contents($root . $name . '/manifest.json', json_encode(['type' => 'full'] + ($encrypted ? ['encryption' => ['key_id' => 'k1']] : [])));
}

/**
 * @return array{service: O, fake: FakeS3, db: Database, root: string, settings: \ArrayObject, hooks: \ArrayObject}
 */
function s3Fixture(): array
{
    $root = sys_get_temp_dir() . '/cms-s3-test-' . bin2hex(random_bytes(4)) . '/';
    mkdir($root, 0700, true);
    $fake = new FakeS3();
    $db = s3Database();
    $settings = new \ArrayObject([
        'enabled' => '1', 'endpoint' => 's3.example.test', 'region' => 'eu-central-1', 'bucket' => 'backups', 'prefix' => 'site-a',
        'access_key' => 'AKID', 'secret_key' => 'secret', 'path_style' => '1', 'part_size_mb' => '5', 'keep_last' => '2', 'keep_days' => '0',
    ]);
    $service = new O(
        $db,
        $root,
        static fn (): array => $settings->getArrayCopy(),
        static fn (S3Config $config): S3Client => new S3Client($config, $fake->transport(), static fn (): int => strtotime('2026-11-15 10:00:00')),
        static fn (): int => strtotime('2026-11-15 10:00:00')
    );

    return ['service' => $service, 'fake' => $fake, 'db' => $db, 'root' => $root, 'settings' => $settings];
}

T::run('SigV4: AWS-Testvektoren', static function (): void {
    $ak = 'AKIDEXAMPLE';
    $sk = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
    $sig = static fn (array $headers): string => (string) preg_replace('/.*Signature=/', '', $headers['authorization']);
    $vector = static fn (string $method, string $url, array $headers = [], string $body = ''): string => $sig(
        Sig::sign($method, $url, $headers, hash('sha256', $body), '20150830T123600Z', 'us-east-1', 'service', $ak, $sk, false)
    );
    T::same('5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31', $vector('GET', 'https://example.amazonaws.com/'), 'get-vanilla');
    T::same('b97d918cfa904a5beff61c982a1b6f458b799221646efd99d3219ec94cdf2500', $vector('GET', 'https://example.amazonaws.com/?Param2=value2&Param1=value1'), 'get-vanilla-query-order-key-case');
    T::same('ff11897932ad3f4e8b18135d722051e5ac45fc38421b1da7b9d196a0fe09473a', $vector('POST', 'https://example.amazonaws.com/', ['Content-Type' => 'application/x-www-form-urlencoded'], 'Param1=value1'), 'post-x-www-form-urlencoded');
    T::same('8318018e0b0f223aa2bbf98705b62bb787dc9c0e678f255a891fd03141be5d85', $vector('GET', 'https://example.amazonaws.com/' . rawurlencode('ሴ')), 'get-utf8');
    T::same('652487583200325589f1fba4c7e578f72c47cb61beeca81406b39ddec1366741', $vector('GET', 'https://example.amazonaws.com/example%20space/'), 'get-space');
    $s3 = Sig::sign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', ['Range' => 'bytes=0-9'], Sig::EMPTY_PAYLOAD_HASH, '20130524T000000Z', 'us-east-1', 's3', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
    T::same('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $s3['authorization'], 'S3-Beispiel „GET Object“ aus der AWS-Doku');
    T::same('/backups/site-a/full%20backup/%C3%A4%2B%26.enc', Sig::canonicalUri('/backups/site-a/full backup/ä+&.enc'), 'kanonische URI kodiert je Segment');
    T::same('/a%20b/c', Sig::canonicalUri('/a%20b/c'), 'bereits kodierte Segmente werden nicht doppelt kodiert');
    T::same('a=1&b=&prefix=x%2Fy', Sig::canonicalQuery('prefix=x/y&b&a=1'), 'Query sortiert, leere Werte, „/“ kodiert');
});

T::run('S3Config: Adressen und Prüfung', static function (): void {
    $path = s3Config();
    T::same('https://s3.example.test/backups/site-a/full_backup_x/manifest.json', $path->objectUrl('site-a/full_backup_x/manifest.json'), 'Path-Style');
    $virtual = s3Config(['path_style' => '0']);
    T::same('https://backups.s3.example.test/site-a/a%20b.enc', $virtual->objectUrl('site-a/a b.enc'), 'Virtual-Host mit kodiertem Schlüssel');
    T::same('site-a/', $path->prefix, 'Präfix bekommt abschließenden Schrägstrich');
    T::same('', S3Config::normalizePrefix('../etc'), 'Präfix mit „..“ abgelehnt');
    T::same('', S3Config::normalizePrefix('a b'), 'Präfix mit Leerzeichen abgelehnt');
    T::same('', S3Config::normalizePrefix('a//b'), 'Präfix mit doppeltem Schrägstrich abgelehnt');
    T::same('s3.example.test', S3Config::fromArray(['endpoint' => 'https://S3.Example.test/'])->endpoint, 'Schema und Schrägstrich werden entfernt');
    T::same([], $path->validate(), 'gültige Konfiguration');
    T::check(count(s3Config(['bucket' => 'X_y', 'secret_key' => ''])->validate()) === 2, 'ungültiger Bucket und fehlender Schlüssel');
    T::check(s3Config(['insecure_http' => '1'])->validate() !== [], 'HTTP ohne freigegebenen privaten Host abgelehnt');
    T::same([], s3Config(['insecure_http' => '1', 'allow_private_host' => '1'])->validate(), 'HTTP für freigegebenen privaten Host erlaubt');
    T::same(64, s3Config(['part_size_mb' => 500])->partSizeMb, 'Teilgröße höchstens 64 MB');
});

T::run('S3-Client: PUT, Multipart, Abbruch, HEAD, List, DELETE', static function (): void {
    $fake = new FakeS3();
    $client = new S3Client(s3Config(), $fake->transport(), static fn (): int => strtotime('2026-11-15 10:00:00'));
    $file = tempnam(sys_get_temp_dir(), 's3t');
    file_put_contents($file, 'hallo');
    T::check($client->putObject('site-a/x.txt', $file, 'text/plain')['success'], 'PUT');
    $put = $fake->requests[0];
    T::same(hash('sha256', 'hallo'), $put['headers']['x-amz-content-sha256'], 'PUT signiert die SHA-256 des Inhalts');
    T::check(str_starts_with($put['headers']['authorization'], 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20261115/eu-central-1/s3/aws4_request'), 'Authorization mit Scope');
    T::same('20261115T100000Z', $put['headers']['x-amz-date'], 'x-amz-date aus der Uhr (UTC)');
    T::check($client->headObject('site-a/x.txt')['exists'] && !$client->headObject('site-a/fehlt')['exists'], 'HEAD');

    $big = tempnam(sys_get_temp_dir(), 's3t');
    file_put_contents($big, str_repeat('a', 5 * 1024 * 1024) . str_repeat('b', 5 * 1024 * 1024) . 'cc');
    $fake->requests = [];
    $multi = $client->uploadFile('site-a/big.enc', $big);
    T::check($multi['success'] && $multi['parts'] === 3, 'über der Teilgröße: Multipart in 3 Teilen');
    $partSizes = array_values(array_map(static fn (array $r): int => $r['size'], array_filter($fake->requests, static fn (array $r): bool => str_contains($r['url'], 'partNumber='))));
    T::same([5242880, 5242880, 2], $partSizes, 'Teile zu 5 MB, Rest im letzten Teil');
    T::same(filesize($big), strlen($fake->objects['/backups/site-a/big.enc']), 'zusammengesetzt wie das Original');
    $complete = array_values(array_filter($fake->requests, static fn (array $r): bool => $r['method'] === 'POST' && str_contains($r['url'], 'uploadId=')));
    T::same(1, count($complete), 'ein Abschluss-Aufruf');

    $fake->failPart = 2;
    $fake->requests = [];
    $failed = $client->uploadFile('site-a/big2.enc', $big);
    T::check(!$failed['success'] && str_contains((string) $failed['error'], 'Teil 2'), 'Fehler in Teil 2 meldet den Teil');
    T::check((bool) array_filter($fake->requests, static fn (array $r): bool => $r['method'] === 'DELETE' && str_contains($r['url'], 'uploadId=')), 'fehlgeschlagener Multipart-Upload wird abgebrochen (abort)');
    T::check(!isset($fake->objects['/backups/site-a/big2.enc']), 'kein halbes Objekt im Speicher');
    $fake->failPart = 0;

    $fake->pageSize = 2;
    $all = $client->listAll('site-a/');
    T::same(['site-a/big.enc', 'site-a/x.txt'], array_column($all['keys'], 'key'), 'ListObjectsV2 über mehrere Seiten');
    T::check($client->deleteObject('site-a/x.txt') && !isset($fake->objects['/backups/site-a/x.txt']), 'DELETE');
    T::same(null, S3Client::parseXml('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><a>&e;</a>'), 'XML mit DOCTYPE/Entitäten wird abgelehnt');
    @unlink($file);
    @unlink($big);
});

T::run('Offsite-Dienst: nur verschlüsselte Backups', static function (): void {
    $f = s3Fixture();
    s3MakeBackup($f['root'], 'full_backup_2026-11-15_10-00-00');
    // Dateiname, der alphabetisch nach manifest.json kommt: Reihenfolge darf nicht vom Sortieren abhängen.
    file_put_contents($f['root'] . 'full_backup_2026-11-15_10-00-00/uploads.zip.enc', "CMSENC2\0uploads");
    $result = $f['service']->uploadBackup('full_backup_2026-11-15_10-00-00');
    T::check(!empty($result['success']) && $result['files'] === 4, 'verschlüsseltes Backup hochgeladen');
    $puts = array_values(array_map(static fn (array $r): string => basename(parse_url($r['url'], PHP_URL_PATH)), array_filter($f['fake']->requests, static fn (array $r): bool => $r['method'] === 'PUT')));
    T::same('manifest.json', end($puts), 'manifest.json zuletzt');
    T::check(isset($f['fake']->objects['/backups/site-a/full_backup_2026-11-15_10-00-00/database.sql.gz.enc']), 'Schlüssel {prefix}{backup}/{datei}');
    T::same('uploaded', (string) $f['db']->get_var('SELECT status FROM cms_backup_offsite_uploads'), 'Status uploaded');

    s3MakeBackup($f['root'], 'full_backup_klartext', false);
    $plain = $f['service']->uploadBackup('full_backup_klartext');
    T::check(empty($plain['success']) && str_contains((string) $plain['error'], 'Verschlüsselung'), 'Klartext-Backup abgelehnt: „Verschlüsselung aktivieren“');
    T::same(O::MAX_ATTEMPTS, (int) $f['db']->get_var("SELECT attempts FROM cms_backup_offsite_uploads WHERE backup_name = 'full_backup_klartext'"), 'Klartext-Backup wird nicht wiederholt');
    T::check(!isset($f['fake']->objects['/backups/site-a/full_backup_klartext/manifest.json']), 'nichts vom Klartext-Backup hochgeladen');

    @mkdir($f['root'] . 'full_backup_halb', 0700);
    file_put_contents($f['root'] . 'full_backup_halb/manifest.json', json_encode(['encryption' => ['key_id' => 'k']]));
    file_put_contents($f['root'] . 'full_backup_halb/files.zip', 'PK-klartext');
    T::check(empty($f['service']->uploadBackup('full_backup_halb')['success']), 'Manifest „verschlüsselt“, Datei aber ohne CMSENC2-Kopf: abgelehnt');
    T::check(empty($f['service']->uploadBackup('../../etc')['success']), 'Pfad-Traversal im Namen abgelehnt');
    T::check(empty($f['service']->uploadBackup('gibt_es_nicht')['success']), 'unbekanntes Backup abgelehnt');
    $f['settings']['secret_key'] = '';
    T::check(str_contains((string) ($f['service']->uploadBackup('full_backup_2026-11-15_10-00-00')['error'] ?? ''), 'unvollständig'), 'unvollständiges Ziel');
});

T::run('Offsite-Dienst: Hook, Nachholen, Fehler', static function (): void {
    $f = s3Fixture();
    s3MakeBackup($f['root'], 'full_backup_a');
    $f['settings']['enabled'] = '0';
    T::check(!empty($f['service']->handleBackupCreated('full_backup_a')['skipped']), 'abgeschaltet: nichts passiert');
    $f['settings']['enabled'] = '1';
    $f['fake']->failPart = 0;
    $broken = new O($f['db'], $f['root'], static fn (): array => $f['settings']->getArrayCopy(),
        static fn (S3Config $config): S3Client => new S3Client($config, static fn (): array => ['success' => false, 'status' => 503, 'headers' => [], 'body' => '']),
        static fn (): int => strtotime('2026-11-15 10:00:00'));
    T::check(empty($broken->handleBackupCreated('full_backup_a')['success']), 'Speicher nicht erreichbar: fehlgeschlagen');
    T::same('failed', (string) $f['db']->get_var('SELECT status FROM cms_backup_offsite_uploads'), 'Status failed');
    T::check(str_contains((string) $f['db']->get_var('SELECT last_error FROM cms_backup_offsite_uploads'), 'HTTP 503'), 'Fehlergrund ohne Zugangsdaten gespeichert');
    T::check(!str_contains((string) $f['db']->get_var('SELECT last_error FROM cms_backup_offsite_uploads'), 'secret'), 'geheimer Schlüssel nicht im Fehler');
    T::same(['retried' => 1, 'uploaded' => 1], $f['service']->retryPending(), 'Cron holt den Upload nach');
    T::same('uploaded', (string) $f['db']->get_var('SELECT status FROM cms_backup_offsite_uploads'), 'danach uploaded');
    $f['db']->execute("UPDATE cms_backup_offsite_uploads SET status = 'failed', attempts = 3");
    T::same(['retried' => 0, 'uploaded' => 0], $f['service']->retryPending(), 'nach 3 Versuchen kein weiterer Versuch');
});

T::run('Aufbewahrung', static function (): void {
    $now = strtotime('2026-11-15 10:00:00');
    $backups = ['b1' => $now - 5 * 86400, 'b2' => $now - 3 * 86400, 'b3' => $now - 86400, 'b4' => $now];
    T::same(['b2', 'b1'], O::retentionVictims($backups, 2, 0, $now, ''), 'letzte 2 behalten');
    T::same(['b1'], O::retentionVictims($backups, 0, 4, $now, ''), 'älter als 4 Tage löschen');
    T::same(['b1'], O::retentionVictims($backups, 3, 4, $now, ''), 'beide Grenzen: b1 ist zu alt und zu viel');
    T::same(['b3', 'b2', 'b1'], O::retentionVictims($backups, 1, 0, $now, 'b4'), 'neuestes bleibt');
    T::same(['b2', 'b1'], O::retentionVictims($backups, 1, 0, $now, 'b3'), 'geschütztes (zuletzt erfolgreich hochgeladenes) Backup wird nie gelöscht');
    T::same([], O::retentionVictims($backups, 0, 0, $now, ''), 'ohne Grenzen nichts');

    $f = s3Fixture();
    foreach (['full_backup_1', 'full_backup_2', 'full_backup_3'] as $index => $name) {
        s3MakeBackup($f['root'], $name);
        $f['service']->uploadBackup($name);
        foreach (['database.sql.gz.enc', 'files.zip.enc', 'manifest.json'] as $file) {
            $f['fake']->modified['site-a/' . $name . '/' . $file] = '2026-11-1' . ($index + 1) . 'T10:00:00.000Z';
        }
    }
    foreach ([1, 2, 3] as $index) {
        $f['db']->execute('UPDATE cms_backup_offsite_uploads SET uploaded_at = ? WHERE backup_name = ?', ['2026-11-1' . $index . ' 10:00:00', 'full_backup_' . $index]);
    }
    // SEC-410-07: fremde Objekte unter demselben Präfix (sehen aus wie ein Backup-Name) bleiben unangetastet.
    $f['fake']->objects['/backups/site-a/fremde-daten/report.pdf'] = 'x';
    $f['fake']->modified['site-a/fremde-daten/report.pdf'] = '2020-01-01T00:00:00.000Z';
    $retention = $f['service']->applyRetention();
    T::same(['full_backup_1'], $retention['deleted'], 'letzte 2 Backups bleiben offsite');
    T::check(isset($f['fake']->objects['/backups/site-a/fremde-daten/report.pdf']), 'SEC-410-07: nur selbst hochgeladene Backups werden gelöscht, fremde Objekte bleiben');
    T::check(!isset($f['fake']->objects['/backups/site-a/full_backup_1/manifest.json']) && isset($f['fake']->objects['/backups/site-a/full_backup_3/manifest.json']), 'alle Objekte des ältesten Backups gelöscht');
    T::same('deleted', (string) $f['db']->get_var("SELECT status FROM cms_backup_offsite_uploads WHERE backup_name = 'full_backup_1'"), 'Status deleted');
    // Backup 2 ist das zuletzt erfolgreich hochgeladene; beide verbliebenen sind älter als 1 Tag.
    $f['settings']['keep_last'] = '1';
    $f['settings']['keep_days'] = '1';
    $f['db']->execute("UPDATE cms_backup_offsite_uploads SET uploaded_at = '2026-11-20 00:00:00' WHERE backup_name = 'full_backup_2'");
    $second = $f['service']->applyRetention();
    T::same(['full_backup_3'], $second['deleted'], 'zuletzt erfolgreich hochgeladenes Backup bleibt, obwohl es zu alt ist');
    T::check(isset($f['fake']->objects['/backups/site-a/full_backup_2/manifest.json']), 'Objekte des geschützten Backups vorhanden');
});

T::run('Verbindungstest', static function (): void {
    $f = s3Fixture();
    T::check(!empty($f['service']->testConnection()['success']), 'schreibt, liest und löscht ein Testobjekt');
    T::same([], array_keys($f['fake']->objects), 'Testobjekt ist wieder weg');
    $f['fake']->failDelete = true;
    $result = $f['service']->testConnection();
    T::check(empty($result['success']) && str_contains((string) $result['error'], 'Löschrecht'), 'fehlendes Löschrecht wird gemeldet');
});

T::run('Einbindung (Quelltext)', static function (): void {
    $backup = s3Source('core/Services/BackupService.php');
    T::same(2, substr_count($backup, '$this->notifyBackupCreated($backupName);'), 'cms_backup_created nach vollem und Datenbank-Backup');
    T::check(str_contains(s3Source('core/Services/FeatureHooks/Registry.php'), 'BackupOffsiteHooks::class'), 'Hooks registriert');
    $hooks = s3Source('core/Services/FeatureHooks/BackupOffsiteHooks.php');
    T::check(str_contains($hooks, "'cms_backup_created'") && str_contains($hooks, "'cms_cron_daily'"), 'nach Backup und täglich');
    T::check(str_contains(s3Source('core/Services/Backup/OffsiteBackupService.php'), "->set(self::SETTINGS_GROUP, 'secret_key', \$secret, true)"), 'geheimer Schlüssel verschlüsselt gespeichert');
    $client = s3Source('core/Services/Backup/S3Client.php');
    T::check(str_contains($client, '\\CMS\\Http\\Client::getInstance()') && str_contains($client, "'allowedPrivateHosts'"), 'Anfragen über Http\\Client, privater Host nur gezielt freigegeben');
    $http = s3Source('core/Http/Client.php');
    T::check(str_contains($http, 'public function head(') && str_contains($http, 'public function delete(') && str_contains($http, 'CURLOPT_NOBODY'), 'Http\\Client: head() und delete()');
    T::check(str_contains(s3Source('admin/modules/users/RolesModule.php'), "'backups.offsite'"), 'Recht backups.offsite');
    $page = s3Source('admin/backup-settings.php');
    T::check(str_contains($page, "hasCapability('backups.offsite')"), 'Offsite-Aktionen verlangen backups.offsite');
    $view = s3Source('admin/views/system/backup-settings.php');
    T::same(substr_count($view, '<form method="post"'), substr_count($view, 'name="csrf_token"'), 'jedes POST-Formular trägt ein CSRF-Token');
    T::check(!preg_match('/<script|\son[a-z]+=/i', $view), 'keine Inline-Skripte (CSP)');
    T::check(preg_match('/name="secret_key"[^>]*value=/', $view) !== 1, 'geheimer Schlüssel wird nie ins Formular geschrieben');
    T::check(str_contains(s3Source('core/Services/AdminMenu/CoreCatalog.php'), "'route' => '/admin/backup-settings'"), 'Menüpunkt Backup-Einstellungen');
});

T::finish();
