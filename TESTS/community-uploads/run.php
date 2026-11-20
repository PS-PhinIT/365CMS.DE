<?php
/**
 * Community-Anhänge (PR #2b; Vertrag DESIGN-2b §3.10, §4.6, §7.1; Entwurf medien §2.1, §2.4, §6.1): `Service\AttachmentService` und
 * `Repository\AttachmentRepository`.
 *
 * Abschnitte (Besitzer): diese Datei D (W2): A Namen/Typen, B Inhalt, C Bilder (GD), D Rechte/Grenzen, E Ablage, H Auflösung,
 * I Aufräumen/Verwerfen, J Listen, K Wirkung, L Zuordnungsbausteine, M Repository, N Konstanten und Grenzen. Die Zuordnung über die
 * Dienste (F, G: `create`/`reply`/`edit`, `clearRendered` bei reiner Anhangsänderung) ergänzt T in W3 (`part-services.php`, ohne
 * „Wirkung:“). Prüfkürzel SECURITY-PRIVACY U1 bis U12.
 *
 * DB-frei (SQLite-Double), Zone Europe/Berlin. Speicher im Temp-Ordner über die Closures `directory`/`locate`; Bilder, EXIF-APP1,
 * PNG-Chunks, animiertes GIF, Pixelbombe und ZIP entstehen zur Laufzeit. Ohne GD laufen die Bildabschnitte als Hinweis.
 * Der Wettlauf der Kontingente gegen MariaDB steht in `TESTS/community-db/sections-media.php` (`race-upload-quota`).
 *
 *   php TESTS/community-uploads/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-uploads-' . getmypid() . '/');
define('CMS_PRIVATE_PATH', sys_get_temp_dir() . '/cms-tests-private-community-uploads-' . getmypid());
define('SITE_URL', 'https://forum.example');

// PHP-Zone der Suite: Europe/Berlin, damit jede Verwendung von date() statt gmdate() auffällt.
date_default_timezone_set('Europe/Berlin');
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

// Gegenprobe-Haken (TESTS/lib/Mutant.php): mit CMS_TEST_MUTANT=<name> lädt der Autoloader eine Mutante statt des Originals.
require_once dirname(__DIR__) . '/lib/Mutant.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        Mutant::load($file);
    }
});
require_once ABSPATH . 'assets/autoload.php';

require_once dirname(__DIR__) . '/lib/TestRunner.php';
require_once dirname(__DIR__) . '/lib/CommunityTestDatabase.php';

use CMS\Services\Community\Repository\AttachmentRepository;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\AttachmentService;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Support\Settings;

T::$verbose = in_array('-v', $argv ?? [], true);

const CU_ROOT = CMS_PRIVATE_PATH . '-store';
const CU_INCOMING = CMS_PRIVATE_PATH . '-incoming';
const CU_TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const CU_TOKEN2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
/** Kurzer Text, den finfo als text/plain erkennt. */
const CU_TEXT = "Ein kurzer Text.\n";
const CU_ROLE_CAPS = [
    'member' => ['community.view', 'community.participate'],
    'moderator' => ['community.view', 'community.participate', 'community.moderate'],
];

register_shutdown_function(static function (): void {
    cuRemoveTree(CU_ROOT);
    cuRemoveTree(CU_INCOMING);
    cuRemoveTree(CMS_PRIVATE_PATH);
    foreach (glob(LOG_PATH . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir(LOG_PATH);
});

Settings::setInstance(Settings::fromArray(['base_path' => 'forum']));

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Hilfen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Uhr der Suite: 2026-11-20 12:00:00 UTC (Berlin +01:00). */
function cuNow(int $offset = 0): int
{
    return gmmktime(12, 0, 0, 11, 20, 2026) + $offset;
}

function cuUtc(int $offset = 0): string
{
    return gmdate('Y-m-d H:i:s', cuNow($offset));
}

function cuRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            cuRemoveTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

/** Dateien unter dem Speicher (relativ, sortiert). */
function cuStored(): array
{
    $files = [];
    if (!is_dir(CU_ROOT)) {
        return [];
    }
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(CU_ROOT, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $files[] = substr((string) $file, strlen(CU_ROOT) + 1);
    }
    sort($files);

    return $files;
}

/** Warnungen dieses Prozesses (Kanal community). */
function cuLog(): string
{
    return implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), glob(LOG_PATH . 'community-*.log') ?: []));
}

function cuLogReset(): void
{
    foreach (glob(LOG_PATH . 'community-*.log') ?: [] as $file) {
        @unlink($file);
    }
}

/**
 * Eintrag wie `$_FILES`: Datei im Eingangsordner mit dem Inhalt `$bytes`.
 *
 * @return array{name: string, tmp_name: string, error: int, size: int, type: string}
 */
function cuFile(string $name, string $bytes, int $error = UPLOAD_ERR_OK): array
{
    static $n = 0;
    if (!is_dir(CU_INCOMING)) {
        mkdir(CU_INCOMING, 0700, true);
    }
    $tmp = CU_INCOMING . '/php' . (++$n) . '.upload';
    file_put_contents($tmp, $bytes);

    return ['name' => $name, 'tmp_name' => $tmp, 'error' => $error, 'size' => strlen($bytes), 'type' => 'application/octet-stream'];
}

/** GD vorhanden (mit JPEG, PNG, GIF)? */
function cuGd(): bool
{
    return function_exists('imagecreatetruecolor') && function_exists('imagejpeg') && function_exists('imagepng') && function_exists('imagegif');
}

/** Bild mit vier Farbquadranten (oben links rot, oben rechts grün, unten links blau, unten rechts gelb). */
function cuQuadrants(int $width, int $height): \GdImage
{
    $image = imagecreatetruecolor($width, $height);
    $colors = [[255, 0, 0], [0, 255, 0], [0, 0, 255], [255, 255, 0]];
    $hw = intdiv($width, 2);
    $hh = intdiv($height, 2);
    foreach ([[0, 0, $hw - 1, $hh - 1], [$hw, 0, $width - 1, $hh - 1], [0, $hh, $hw - 1, $height - 1], [$hw, $hh, $width - 1, $height - 1]] as $i => [$x1, $y1, $x2, $y2]) {
        imagefilledrectangle($image, $x1, $y1, $x2, $y2, (int) imagecolorallocate($image, ...$colors[$i]));
    }

    return $image;
}

function cuJpeg(int $width = 40, int $height = 20, bool $progressive = false, int $quality = 95): string
{
    $image = cuQuadrants($width, $height);
    imageinterlace($image, $progressive);
    ob_start();
    imagejpeg($image, null, $quality);

    return (string) ob_get_clean();
}

function cuPng(int $width = 40, int $height = 20, bool $alpha = false): string
{
    $image = cuQuadrants($width, $height);
    if ($alpha) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagesetpixel($image, 1, 1, (int) imagecolorallocatealpha($image, 10, 20, 30, 127));
    }
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function cuWebp(int $width = 40, int $height = 20): string
{
    $image = cuQuadrants($width, $height);
    ob_start();
    imagewebp($image, null, 90);

    return (string) ob_get_clean();
}

function cuGif(int $width = 40, int $height = 20): string
{
    $image = cuQuadrants($width, $height);
    ob_start();
    imagegif($image);

    return (string) ob_get_clean();
}

/** Animiertes GIF 1 × 1 mit zwei Bildern (je Bild eine Grafiksteuerung `21 F9 04`). */
function cuAnimatedGif(): string
{
    $frame = hex2bin('21f90400' . '0a000000') . hex2bin('2c000000000100010000') . hex2bin('0202440100');

    return 'GIF89a' . hex2bin('0100010080000000000000ffffff') . $frame . $frame . ';';
}

/** PNG-Chunk mit Prüfsumme. */
function cuChunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

/** Pixelbombe: PNG-Kopf mit 40000 × 40000 und einem minimalen IDAT. */
function cuPixelBomb(): string
{
    return "\x89PNG\r\n\x1a\n" . cuChunk('IHDR', pack('NNCCCCC', 40000, 40000, 8, 2, 0, 0, 0)) . cuChunk('IDAT', (string) gzcompress("\0")) . cuChunk('IEND', '');
}

/** EXIF-APP1 (Big Endian) mit Orientierung, Hersteller und GPS-Block. */
function cuExif(int $orientation, string $make = 'TestKamera GmbH'): string
{
    $entries = 3;
    $ifdSize = 2 + $entries * 12 + 4;
    $makeOffset = 8 + $ifdSize;
    $makeData = $make . "\0";
    $gpsOffset = $makeOffset + strlen($makeData);
    $tiff = "MM\x00\x2A" . pack('N', 8) . pack('n', $entries)
        . pack('nnNN', 0x010F, 2, strlen($makeData), $makeOffset)
        . pack('nnN', 0x0112, 3, 1) . pack('n', $orientation) . "\0\0"
        . pack('nnNN', 0x8825, 4, 1, $gpsOffset)
        . pack('N', 0)
        . $makeData
        . pack('n', 1) . pack('nnN', 0x0000, 1, 4) . "\x02\x02\x00\x00" . pack('N', 0);
    $payload = "Exif\0\0" . $tiff;

    return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
}

/** JPEG mit eingefügten Segmenten direkt nach SOI. */
function cuWithSegments(string $jpeg, string ...$segments): string
{
    return substr($jpeg, 0, 2) . implode('', $segments) . substr($jpeg, 2);
}

/** COM-Segment. */
function cuComment(string $text): string
{
    return "\xFF\xFE" . pack('n', strlen($text) + 2) . $text;
}

/**
 * JPEG mit `$total` SOS-Segmenten: das letzte Scan-Segment (Kopf und Entropiedaten) eines progressiven JPEG wird verdoppelt.
 */
function cuManyScans(int $total): string
{
    $jpeg = cuJpeg(64, 48, true);
    $scans = [];
    $offset = 2;
    $length = strlen($jpeg);
    while ($offset + 4 <= $length) {
        if ($jpeg[$offset] !== "\xFF") {
            break;
        }
        $marker = ord($jpeg[$offset + 1]);
        if ($marker === 0xD9) {
            break;
        }
        $segment = unpack('n', substr($jpeg, $offset + 2, 2))[1];
        $end = $offset + 2 + $segment;
        if ($marker === 0xDA) {
            while ($end + 1 < $length && !($jpeg[$end] === "\xFF" && $jpeg[$end + 1] !== "\x00" && (ord($jpeg[$end + 1]) < 0xD0 || ord($jpeg[$end + 1]) > 0xD7))) {
                $end++;
            }
            $scans[] = [$offset, $end];
        }
        $offset = $end;
    }
    [$start, $end] = $scans[count($scans) - 1];
    $last = substr($jpeg, $start, $end - $start);
    $extra = $total - count($scans);

    return substr($jpeg, 0, $end) . str_repeat($last, max(0, $extra)) . substr($jpeg, $end);
}

/** Farbe eines Pixels als [r, g, b, alpha]. */
function cuPixel(\GdImage $image, int $x, int $y): array
{
    $c = imagecolorat($image, $x, $y);
    if (!imageistruecolor($image)) {
        $c = imagecolorsforindex($image, $c);

        return [$c['red'], $c['green'], $c['blue'], $c['alpha']];
    }

    return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF, ($c >> 24) & 0x7F];
}

/** Grobe Farbe eines Pixels: R, G, B oder Y (gelb). */
function cuColor(\GdImage $image, int $x, int $y): string
{
    [$r, $g, $b] = cuPixel($image, $x, $y);
    if ($r > 160 && $g > 160 && $b < 100) {
        return 'Y';
    }
    if ($r > 160 && $g < 100 && $b < 100) {
        return 'R';
    }
    if ($g > 160 && $r < 100 && $b < 100) {
        return 'G';
    }
    if ($b > 160 && $r < 100 && $g < 100) {
        return 'B';
    }

    return '?';
}

/** Ecken (oben links, oben rechts, unten links, unten rechts) als Farbbuchstaben. */
function cuCorners(\GdImage $image): string
{
    $w = imagesx($image) - 3;
    $h = imagesy($image) - 3;

    return cuColor($image, 2, 2) . cuColor($image, $w, 2) . cuColor($image, 2, $h) . cuColor($image, $w, $h);
}

/**
 * Testwelt: SQLite-Double, Uhr mit Zähler, Fakes für Rechte, Grenzen, Drossel, Speicher, Upload-Prüfung, Zufall, Slot und Speicherbudget.
 * `access` = echte `CommunityAccess` (Rechte aus Rollen); `canOverride` ersetzt einzelne Entscheidungen (Aktion => AccessDecision).
 */
final class CuWorld
{
    public CommunityTestDatabase $db;
    public Settings $settings;
    public \ArrayObject $time;
    public int $clockReads = 0;
    public CommunityAccess $access;
    public bool $module = true;
    /** @var array<string, AccessDecision> */
    public array $canOverride = [];
    /** @var list<array{user: ?int, action: string, context: array<string, mixed>}> */
    public array $canLog = [];
    /** @var array<string, int> */
    public array $limits = ['attachments_per_post' => 3, 'attachments_per_day' => 20];
    public bool $throttleAllowed = true;
    /** @var list<array{action: string, user: ?int}> */
    public array $throttleLog = [];
    /** @var list<string> */
    public array $months = [];
    public bool $uploaded = true;
    public bool $moveResult = true;
    public ?string $mimeOverride = null;
    public int $randomSeq = 0;
    /** @var list<string>|null feste Zufallswerte (nacheinander) */
    public ?array $randomValues = null;
    public bool $slotFree = true;
    public int $slotCalls = 0;
    public int $slotReleases = 0;
    public int $headroom = AttachmentService::DECODE_BUDGET;
    public int $headroomCalls = 0;
    public AttachmentService $service;
    public AttachmentRepository $repo;

    public int $forum;
    public int $topic;
    public int $member;
    public int $other;
    public int $firstPost;

    /** @param array<string, mixed> $settings */
    public function __construct(array $settings = [])
    {
        cuRemoveTree(CU_ROOT);
        mkdir(CU_ROOT, 0700, true);
        $this->db = CommunityTestDatabase::create();
        $this->settings = Settings::fromArray($settings + ['base_path' => 'forum']);
        $this->time = new \ArrayObject(['now' => cuNow()]);
        $this->rebuild();
        $this->forum = $this->db->forum(['title' => 'Allgemein', 'slug' => 'allgemein']);
        $this->member = $this->db->user(['username' => 'mia']);
        $this->other = $this->db->user(['username' => 'otto']);
        $this->topic = $this->db->topic(['forum' => $this->forum, 'user' => $this->member, 'title' => 'Thema', 'created_at' => cuUtc(-60)]);
        $this->firstPost = $this->db->post(['topic' => $this->topic, 'user' => $this->member, 'first' => true, 'body' => 'Erster Beitrag', 'created_at' => cuUtc(-60)]);
        $this->db->execute('UPDATE cms_community_topics SET first_post_id = ? WHERE id = ?', [$this->firstPost, $this->topic]);
    }

    public function rebuild(): void
    {
        $this->access = new CommunityAccess(
            $this->db,
            $this->settings,
            static fn (string $role, string $capability): bool => $role === 'admin' || in_array($capability, CU_ROLE_CAPS[$role] ?? [], true),
            fn (): bool => $this->module,
            fn (): int => (int) $this->time['now'],
            static fn (int $userId): bool => true
        );
        $this->repo = new AttachmentRepository($this->db);
        $this->service = new AttachmentService(
            $this->db,
            $this->settings,
            function (): int {
                $this->clockReads++;

                return (int) $this->time['now'];
            },
            function (?int $userId, string $action, array $context): AccessDecision {
                $this->canLog[] = ['user' => $userId, 'action' => $action, 'context' => $context];

                return $this->canOverride[$action] ?? $this->access->can($userId, $action, $context);
            },
            fn (int $userId): array => $this->limits,
            function (string $action, ?int $userId): array {
                $this->throttleLog[] = ['action' => $action, 'user' => $userId];

                return ['allowed' => $this->throttleAllowed, 'retry_after' => $this->throttleAllowed ? 0 : 1234];
            },
            function (string $relDir): string {
                $this->months[] = $relDir;
                $dir = CU_ROOT . '/' . $relDir;
                if (!is_dir($dir)) {
                    mkdir($dir, 0700, true);
                }

                return $dir . '/';
            },
            static function (string $relPath): ?string {
                $base = realpath(CU_ROOT);
                $path = realpath(CU_ROOT . '/' . ltrim($relPath, '/'));

                return $base !== false && $path !== false && str_starts_with($path, $base . '/') && is_file($path) ? $path : null;
            },
            fn (string $tmp): bool => $this->uploaded,
            fn (string $tmp, string $dest): bool => $this->moveResult && rename($tmp, $dest),
            function (string $path): string {
                if ($this->mimeOverride !== null) {
                    return $this->mimeOverride;
                }
                $type = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

                return is_string($type) ? $type : '';
            },
            function (): string {
                if ($this->randomValues !== null) {
                    return (string) array_shift($this->randomValues);
                }

                return sprintf('%032x', ++$this->randomSeq + 0xabc000);
            },
            function (): ?\Closure {
                $this->slotCalls++;

                return $this->slotFree ? function (): void {
                    $this->slotReleases++;
                } : null;
            },
            function (): int {
                $this->headroomCalls++;

                return $this->headroom;
            }
        );
    }

    /**
     * Upload einer Datei; ohne `$token` je Aufruf eine neue Formular-Kennung (die Grenze je Formular prüfen eigene Fälle).
     *
     * @return array<string, mixed>
     */
    public function upload(string $name, string $bytes, array $target = [], ?string $token = null, ?int $user = null): array
    {
        static $tokens = 0;
        $token ??= sprintf('%032x', 0x51000 + (++$tokens));

        return $this->service->upload($user ?? $this->member, cuFile($name, $bytes), $target === [] ? ['topic_id' => $this->topic] : $target, $token);
    }

    /** @return array<string, mixed>|null */
    public function row(int $id): ?array
    {
        return $this->db->row('community_attachments', ['id' => $id]);
    }

    /** Absoluter Pfad einer gespeicherten Datei (`storage_path` oder Vorschau). */
    public function path(int $id, bool $preview = false): string
    {
        $rel = (string) ($this->row($id)['storage_path'] ?? '');
        if ($preview) {
            $rel = (string) preg_replace('/\.([a-z0-9]+)$/', '.preview.$1', $rel);
        }

        return CU_ROOT . '/' . $rel;
    }
}

/** Index der ersten Anweisung im Protokoll, die auf das Muster passt (-1 = keine). */
function cuFind(CommunityTestDatabase $db, string $pattern, int $from = 0): int
{
    foreach ($db->log as $i => $sql) {
        if ($i >= $from && preg_match($pattern, $sql) === 1) {
            return $i;
        }
    }

    return -1;
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// A Namen und Typen (rein)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('A Namen/Typen: Endungen, Aliasse, Innenendungen, Pfade und Steuerzeichen (U1)', static function (): void {
    $w = new CuWorld(['attachment_types' => ['txt' => 1]]);
    $text = "Hallo Welt\n";
    foreach (['notiz.TXT' => 'txt', 'Notiz.txt' => 'txt'] as $name => $extension) {
        $r = $w->upload($name, $text);
        T::same([true, $extension], [$r['success'] ?? null, $r['attachment']['extension'] ?? null], $name . ': angenommen, Endung ' . $extension);
    }
    foreach (['shell.php.txt', 'a.phtml.txt', 'x.svg.txt', 'b.htaccess.txt', 'c.html.txt', 'd.js.txt', 'e.PHP5.txt'] as $name) {
        T::same('attachment_type', $w->upload($name, $text)['error'] ?? null, $name . ': gefährliche Innenendung abgelehnt');
    }
    foreach (['.htaccess', 'x.svg', 'x.svgz', 'x.html', 'x.xml', 'x.php', 'x.exe', 'ohne-endung', 'x.bmp', 'x.'] as $name) {
        T::same('attachment_type', $w->upload($name, $text)['error'] ?? null, $name . ': Endung nicht erlaubt');
    }
    $r = $w->upload('../../etc/pass' . "\x07" . 'wd.txt', $text);
    T::same([true, 'pass_wd.txt'], [$r['success'] ?? null, $w->row((int) ($r['attachment']['id'] ?? 0))['original_name'] ?? null], 'Pfadteile entfernt, Steuerzeichen ersetzt');
    $r = $w->upload("foto\u{202E}gnp\u{200B}.txt\u{2028}", $text);
    T::same([true, 'fotognp.txt'], [$r['success'] ?? null, $w->row((int) ($r['attachment']['id'] ?? 0))['original_name'] ?? null], 'Bidi- und Formatzeichen (U+202E, U+200B) und U+2028 entfernt (Vertrag §4.6 Nr. 1)');
    T::same('fotognp.txt', $r['attachment']['name'] ?? null, 'Ergebnis nennt den bereinigten Namen');
    T::same('attachment_type', $w->upload("rechnung\u{202E}gnp.exe\u{200B}.txt", $text)['error'] ?? null, 'nach dem Entfernen der Formatzeichen greift die Innenendung (exe)');
    $long = str_repeat('ä', 300) . '.txt';
    $r = $w->upload($long, $text);
    T::same([false, 'attachment_type'], [$r['success'] ?? null, $r['error'] ?? null], 'Name über 200 Zeichen: gekürzt, die Endung fällt weg ⇒ abgelehnt');
});

T::run('A Namen/Typen: Aliasse jpeg/JPG/jpe und Abgleich mit Settings::ATTACHMENT_TYPES', static function (): void {
    T::same(Settings::ATTACHMENT_TYPES, array_keys(AttachmentService::TYPES), 'TYPES in der Reihenfolge von Settings::ATTACHMENT_TYPES');
    T::same(['jpeg' => 'jpg', 'jpe' => 'jpg'], AttachmentService::ALIASES, 'Aliasse');
    if (!cuGd()) {
        T::info('GD fehlt: Aliasse für Bilder nicht geprüft');

        return;
    }
    $w = new CuWorld();
    foreach (['bild.jpeg', 'BILD.JPG', 'foto.jpe'] as $name) {
        $r = $w->upload($name, cuJpeg());
        T::same([true, 'jpg'], [$r['success'] ?? null, $r['attachment']['extension'] ?? null], $name . ' ⇒ jpg');
    }
});

T::run('A available(): Bilder nur mit GD-Lesen und -Schreiben des Formats', static function (): void {
    $all = AttachmentService::available(static fn (string $f): bool => true);
    T::same(['jpg', 'png', 'webp', 'gif', 'pdf', 'zip', 'txt'], $all, 'alle Funktionen vorhanden: alle Typen');
    T::same(['jpg', 'png', 'gif', 'pdf', 'zip', 'txt'], AttachmentService::available(static fn (string $f): bool => $f !== 'imagewebp'), 'ohne WebP-Encoder kein webp');
    T::same(['pdf', 'zip', 'txt'], AttachmentService::available(static fn (string $f): bool => !str_starts_with($f, 'image')), 'ohne GD nur Nicht-Bilder');
    $w = new CuWorld(['attachment_types' => ['txt' => 1]]);
    T::check(in_array('txt', AttachmentService::available(), true), 'Standard: function_exists');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// B Inhalt (fail-closed)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('B Inhalt: finfo fail-closed, Inhalt passt zur Endung, Kopfprüfung, ZIP und PDF (U2, U3, U8)', static function (): void {
    $w = new CuWorld(['attachment_types' => ['txt' => 1]]);
    $w->mimeOverride = '';
    T::same('attachment_type', $w->upload('a.txt', 'Hallo')['error'] ?? null, 'finfo liefert nichts ⇒ abgelehnt');
    $w->mimeOverride = 'text/html';
    T::same('attachment_type', $w->upload('a.txt', 'Hallo')['error'] ?? null, 'gefährlicher Inhaltstyp ⇒ abgelehnt');
    $w->mimeOverride = null;
    T::same('attachment_type', $w->upload('a.jpg', '<html><body>x</body></html>')['error'] ?? null, 'HTML als .jpg ⇒ abgelehnt');
    T::same('attachment_type', $w->upload('a.jpg', "<?php echo 'x';")['error'] ?? null, 'PHP als .jpg ⇒ abgelehnt');
    T::same('attachment_type', $w->upload('a.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')['error'] ?? null, 'SVG als .png ⇒ abgelehnt');
    T::same('attachment_type', $w->upload('a.txt', "Hallo\n<?php system('id');")['error'] ?? null, 'Text mit <?php ⇒ abgelehnt');
    T::same('attachment_type', $w->upload('a.txt', "Vorwort\n<script>alert(1)</script>")['error'] ?? null, 'Text mit <script> ⇒ abgelehnt');
    T::same(true, $w->upload('a.txt', "Sauberer Text\nZeile 2\n")['success'] ?? null, 'sauberer Text ⇒ angenommen (txt freigegeben)');
    $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    T::same(true, $w->upload('doc.pdf', $pdf)['success'] ?? null, 'PDF ⇒ angenommen');
    $zipFile = CU_INCOMING . '/probe.zip';
    @mkdir(CU_INCOMING, 0700, true);
    $zip = new \ZipArchive();
    $zipOk = class_exists(\ZipArchive::class) && $zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true;
    if ($zipOk) {
        $zip->addFromString('innen/shell.php', str_repeat("<?php echo 'x'; ?>\n", 200));
        $zip->setCompressionName('innen/shell.php', \ZipArchive::CM_DEFLATE);
        $zip->close();
        $bytes = (string) file_get_contents($zipFile);
        T::check(!str_contains(substr($bytes, 0, 1024), '<?php'), 'ZIP: PHP-Datei komprimiert (kein <?php im Kopf)');
        $r = $w->upload('archiv.zip', $bytes);
        T::same(true, $r['success'] ?? null, 'ZIP mit komprimierter PHP-Datei innen ⇒ angenommen (nie entpackt)');
        T::same([], array_values(array_filter(cuStored(), static fn (string $f): bool => str_ends_with($f, '.php'))), 'kein entpackter Inhalt im Speicher');
        $plain = new \ZipArchive();
        $plain->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $plain->addFromString('shell.php', '<?php echo 1;');
        $plain->setCompressionName('shell.php', \ZipArchive::CM_STORE);
        $plain->close();
        T::same('attachment_type', $w->upload('archiv2.zip', (string) file_get_contents($zipFile))['error'] ?? null, 'ZIP mit unkomprimiertem <?php im Kopf ⇒ attachment_type (Kopfprüfung gilt für alle Nicht-Bilder)');
    } else {
        T::info('ZipArchive fehlt: ZIP-Fall nicht geprüft');
    }
    if (cuGd()) {
        T::same('attachment_type', $w->upload('bild.jpg', cuPng())['error'] ?? null, 'PNG-Inhalt als .jpg ⇒ abgelehnt');
        T::same('attachment_type', $w->upload('bild.gif', cuJpeg())['error'] ?? null, 'Endung freigegeben, Datei ist ein Bild eines anderen Typs ⇒ abgelehnt');
    }
    T::same([], array_values(array_filter(cuStored(), static fn (string $f): bool => str_ends_with($f, '.tmp'))), 'keine Zwischendateien nach Ablehnungen');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// C Bilder (GD)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('C Bilder: Pixelbombe, Schein-GIF, Polyglot, EXIF entfernt, Neukodierung (U6, U7)', static function (): void {
    if (!cuGd()) {
        T::info('GD fehlt: Bildabschnitt übersprungen');

        return;
    }
    $w = new CuWorld();
    $bomb = cuPixelBomb();
    T::check(strlen($bomb) < 100, 'Pixelbombe ist klein (' . strlen($bomb) . ' Byte)');
    $r = $w->upload('bombe.png', $bomb);
    T::same('attachment_dimensions', $r['error'] ?? null, 'Pixelbombe 40000 × 40000 ⇒ attachment_dimensions');
    T::same([0, 0], [$w->headroomCalls, $w->slotCalls], 'vor Speicherschätzung und Slot abgelehnt (kein imagecreatefrom*)');
    T::same([], cuStored(), 'keine Datei');

    $r = $w->upload('schein.gif', "GIF89a<?php system('id'); ?>" . str_repeat("\0", 40));
    T::same(false, $r['success'] ?? null, 'Schein-GIF (GIF89a + PHP) ⇒ abgelehnt');
    T::check(in_array($r['error'] ?? null, ['attachment_image', 'attachment_type', 'attachment_dimensions'], true), 'Schein-GIF: vor dem Dekodieren abgelehnt (Maße aus dem Kopf)', (string) ($r['error'] ?? ''));
    T::same([], cuStored(), 'keine Datei nach dem Schein-GIF');

    $polyglot = cuWithSegments(cuJpeg(), cuComment('<?php system($_GET["c"]); ?>'), cuExif(1));
    T::check(str_contains($polyglot, '<?php') && str_contains($polyglot, 'TestKamera'), 'Polyglot enthält PHP im COM und EXIF mit Hersteller');
    $r = $w->upload('poly.jpg', $polyglot);
    T::same(true, $r['success'] ?? null, 'Polyglot-JPEG angenommen (neu kodiert)');
    $stored = (string) file_get_contents($w->path((int) $r['attachment']['id']));
    T::check(!str_contains($stored, '<?php'), 'gespeicherte Datei ohne <?php');
    T::check(!str_contains($stored, 'TestKamera') && !str_contains($stored, 'Exif'), 'EXIF, Hersteller und GPS entfernt');
    $row = $w->row((int) $r['attachment']['id']);
    T::same([hash_file('sha256', $w->path((int) $r['attachment']['id'])), filesize($w->path((int) $r['attachment']['id']))], [$row['sha256'], (int) $row['file_size']], 'sha256 und file_size = gespeicherte Datei');
    T::same([40, 20], [(int) $row['width'], (int) $row['height']], 'width/height = Ergebnisbild');
    T::same('image/jpeg', $row['mime_type'], 'mime_type aus TYPES');
    T::same([1, 1], [$w->slotCalls, $w->slotReleases], 'ein Slot belegt und im finally freigegeben');
    T::check($w->headroomCalls === 1, 'Speicherschätzung einmal geprüft');
});

T::run('C Bilder: EXIF-Orientierung 1 bis 8 pixelgenau, Maße 5 bis 8 getauscht (U7)', static function (): void {
    if (!cuGd() || !function_exists('exif_read_data')) {
        T::info('GD oder EXIF fehlt: Orientierung nicht geprüft');

        return;
    }
    // Ecken (oben links, oben rechts, unten links, unten rechts) des Originals: R G B Y. Erwartung nach EXIF-Norm.
    $expected = [1 => 'RGBY', 2 => 'GRYB', 3 => 'YBGR', 4 => 'BYRG', 5 => 'RBGY', 6 => 'BRYG', 7 => 'YGBR', 8 => 'GYRB'];
    $w = new CuWorld();
    foreach ($expected as $orientation => $corners) {
        $r = $w->upload('foto-' . $orientation . '.jpg', cuWithSegments(cuJpeg(60, 30, false, 100), cuExif($orientation)));
        $id = (int) ($r['attachment']['id'] ?? 0);
        $image = @imagecreatefromjpeg($w->path($id));
        if (!$image instanceof \GdImage) {
            T::check(false, 'Orientierung ' . $orientation . ': Datei lesbar', json_encode($r));
            continue;
        }
        $swap = $orientation >= 5;
        T::same($swap ? [30, 60] : [60, 30], [imagesx($image), imagesy($image)], 'Orientierung ' . $orientation . ': Maße ' . ($swap ? 'getauscht' : 'gleich'));
        T::same($corners, cuCorners($image), 'Orientierung ' . $orientation . ': Ecken');
        T::same($swap ? [30, 60] : [60, 30], [(int) $w->row($id)['width'], (int) $w->row($id)['height']], 'Orientierung ' . $orientation . ': Zeile mit den Maßen des Ergebnisbildes');
    }
    // EXIF 6 mit Verkleinern: Drehen nach dem Verkleinern ergibt dasselbe Bild
    $r = $w->upload('gross.jpg', cuWithSegments(cuJpeg(3000, 1000, false, 90), cuExif(6)));
    $image = @imagecreatefromjpeg($w->path((int) ($r['attachment']['id'] ?? 0)));
    T::same([853, 2560], $image instanceof \GdImage ? [imagesx($image), imagesy($image)] : null, 'EXIF 6 und 3000 × 1000: verkleinert auf 2560 × 853, dann gedreht ⇒ 853 × 2560');
    T::same('BRYG', $image instanceof \GdImage ? cuCorners($image) : null, 'EXIF 6 nach dem Verkleinern: Ecken wie bisher gedreht');
});

T::run('C Bilder: Alpha, Verkleinern, Vorschau, GIF, WebP (U7)', static function (): void {
    if (!cuGd()) {
        T::info('GD fehlt: Bildabschnitt übersprungen');

        return;
    }
    $w = new CuWorld();
    $r = $w->upload('alpha.png', cuPng(40, 20, true));
    $image = imagecreatefrompng($w->path((int) $r['attachment']['id']));
    T::same(127, cuPixel($image, 1, 1)[3], 'PNG-Alpha (α 127) bleibt erhalten');
    T::same(0, cuPixel($image, 30, 15)[3], 'deckende Pixel bleiben deckend');

    $r = $w->upload('lang.png', cuPng(5000, 100));
    T::same([2560, 51], [$r['attachment']['width'] ?? null, $r['attachment']['height'] ?? null], 'Verkleinerung 5000 × 100 ⇒ 2560 × 51');
    $size = getimagesize($w->path((int) $r['attachment']['id']));
    T::same([2560, 51], [$size[0] ?? null, $size[1] ?? null], 'gespeicherte Datei 2560 × 51');
    T::same(true, $r['attachment']['has_preview'] ?? null, 'Vorschau bei langer Kante über 480');
    $preview = getimagesize($w->path((int) $r['attachment']['id'], true));
    T::same([480, 10, IMAGETYPE_PNG], [$preview[0] ?? null, $preview[1] ?? null, $preview[2] ?? null], 'Vorschau 480 px im selben Format');

    $r = $w->upload('klein.png', cuPng(480, 300));
    T::same([false, 0], [$r['attachment']['has_preview'] ?? null, (int) $w->row((int) $r['attachment']['id'])['has_preview']], 'keine Vorschau bei langer Kante 480');
    T::check(!is_file($w->path((int) $r['attachment']['id'], true)), 'keine Vorschaudatei');

    $r = $w->upload('anim.gif', cuAnimatedGif());
    T::same([true, true], [$r['success'] ?? null, $r['attachment']['animated'] ?? null], 'animiertes GIF: angenommen, animated = true');
    T::same(1, substr_count((string) file_get_contents($w->path((int) $r['attachment']['id'])), "\x2C\x00\x00\x00\x00"), 'gespeichert wird nur das erste Bild');
    $r = $w->upload('still.gif', cuGif());
    T::same([true, false], [$r['success'] ?? null, $r['attachment']['animated'] ?? null], 'Einzelbild-GIF: animated = false');
    T::same(IMAGETYPE_GIF, getimagesize($w->path((int) $r['attachment']['id']))[2] ?? null, 'GIF bleibt GIF (A3)');

    if (function_exists('imagewebp') && function_exists('imagecreatefromwebp')) {
        $r = $w->upload('bild.webp', cuWebp());
        T::same([true, IMAGETYPE_WEBP], [$r['success'] ?? null, getimagesize($w->path((int) ($r['attachment']['id'] ?? 0)))[2] ?? null], 'WebP bleibt WebP (A3)');
    } else {
        T::info('WebP-Encoder fehlt: WebP nicht geprüft');
    }
    $r = $w->upload('foto.jpg', cuJpeg(800, 600));
    T::same([IMAGETYPE_JPEG, IMAGETYPE_JPEG], [getimagesize($w->path((int) $r['attachment']['id']))[2] ?? null, getimagesize($w->path((int) $r['attachment']['id'], true))[2] ?? null], 'JPEG und Vorschau bleiben JPEG (A3)');
});

T::run('C Bilder (Fassung 3/3.1): Scan-Grenze, Speicherschätzung je Format, Slot (Vertrag §4.6 Nr. 3)', static function (): void {
    if (!cuGd()) {
        T::info('GD fehlt: Bildabschnitt übersprungen');

        return;
    }
    $w = new CuWorld();
    $many = cuManyScans(40);
    T::same(40, substr_count($many, "\xFF\xDA"), 'erzeugtes JPEG mit 40 SOS-Markern (Segmentverdopplung)');
    $r = $w->upload('scans.jpg', $many);
    T::same('attachment_image', $r['error'] ?? null, '40 Scans ⇒ attachment_image');
    T::same([0, 0], [$w->headroomCalls, $w->slotCalls], '40 Scans: ohne Speicherschätzung und ohne Slot (kein imagecreatefrom*)');
    $ten = cuJpeg(64, 48, true);
    T::check(substr_count($ten, "\xFF\xDA") >= 2 && substr_count($ten, "\xFF\xDA") <= AttachmentService::MAX_JPEG_SCANS, 'progressives JPEG aus GD: ' . substr_count($ten, "\xFF\xDA") . ' Scans');
    T::same(true, $w->upload('zehn.jpg', $ten)['success'] ?? null, 'progressives JPEG mit wenigen Scans ⇒ angenommen');
    $comScans = cuWithSegments(cuJpeg(), cuComment(str_repeat("\xFF\xDA", 40)));
    T::same('attachment_image', $w->upload('com.jpg', $comScans)['error'] ?? null, 'SOS-Marker im COM-Segment zählen mit (strenger)');

    // Speicherschätzung: Grund-JPEG und progressives JPEG gleicher Maße, Budget knapp unter dem Bedarf des progressiven
    $baseline = cuJpeg(1000, 800);
    $progressive = cuJpeg(1000, 800, true);
    T::check(str_contains($progressive, "\xFF\xC2") && !str_contains($baseline, "\xFF\xC2"), 'imageinterlace() erzeugt SOF2 (progressiv), das Grund-JPEG nicht');
    $need = AttachmentService::decodeNeed(1000, 800, 'jpg', true, 3);
    T::same(AttachmentService::decodeNeed(1000, 800, 'jpg', false, 3) + 1000 * 800 * 6, $need, 'Koeffizientenzuschlag 2 · 3 Kanäle je Pixel');
    $w->headroom = $need - 1;
    $w->slotCalls = 0;
    $r = $w->upload('prog.jpg', $progressive);
    T::same('attachment_dimensions', $r['error'] ?? null, 'progressives JPEG, Budget 1 Byte unter der Schätzung ⇒ attachment_dimensions');
    T::same(0, $w->slotCalls, 'vor dem Slot abgelehnt');
    T::same(true, $w->upload('grund.jpg', $baseline)['success'] ?? null, 'dasselbe Bild als Grund-JPEG ⇒ angenommen');
    $w->headroom = AttachmentService::decodeNeed(1000, 800, 'png', false, 4) - 1;
    T::same('attachment_dimensions', $w->upload('rgba.png', cuPng(1000, 800, true))['error'] ?? null, 'RGBA-PNG, Budget 1 Byte unter der Schätzung ⇒ attachment_dimensions');
    $w->headroom = AttachmentService::decodeNeed(1000, 800, 'png', false, 4);
    T::same(true, $w->upload('rgba2.png', cuPng(1000, 800, true))['success'] ?? null, 'RGBA-PNG, Budget gleich der Schätzung ⇒ angenommen');

    // Slot
    $w->headroom = AttachmentService::DECODE_BUDGET;
    $w->slotFree = false;
    $before = cuStored();
    $r = $w->upload('slot.png', cuPng());
    T::same(['rate_limited', 5], [$r['error'] ?? null, $r['retry_after'] ?? null], 'beide Slots belegt ⇒ rate_limited mit retry_after 5');
    T::same($before, cuStored(), 'ohne Slot keine Datei');
    $w->slotFree = true;
    $releases = $w->slotReleases;
    $w->mimeOverride = null;
    $broken = substr(cuJpeg(200, 200), 0, 400);
    $r = $w->upload('kaputt.jpg', $broken);
    T::same('attachment_image', $r['error'] ?? null, 'abgeschnittenes JPEG ⇒ attachment_image');
    T::same($releases + 1, $w->slotReleases, 'Slot auch nach einem Dekodierfehler freigegeben');
});

T::run('C decodeNeed() und budget(): reine Funktionen mit den Werten aus Vertrag §4.6 Nr. 3', static function (): void {
    $mib = static fn (int $bytes): float => round($bytes / 1048576, 1);
    $cases = [
        ['PNG RGBA 25 MP', 5000, 5000, 'png', false, 4, 270127616, 257.6],
        ['GIF 25 MP', 5000, 5000, 'gif', false, 1, 195127616, 186.1],
        ['WebP 25 MP', 5000, 5000, 'webp', false, 3, 370127616, 353.0],
        ['JPEG Grund 25 MP', 5000, 5000, 'jpg', false, 3, 170127616, 162.2],
        ['JPEG progressiv 4:4:4 25 MP', 5000, 5000, 'jpg', true, 3, 320127616, 305.3],
        ['JPEG progressiv CMYK 25 MP', 5000, 5000, 'jpg', true, 4, 370127616, 353.0],
        ['Smartphone Grund 5712 × 4284', 5712, 4284, 'jpg', false, 3, 154670848, 147.5],
        ['Smartphone progressiv 5712 × 4284', 5712, 4284, 'jpg', true, 3, 301492096, 287.5],
    ];
    foreach ($cases as [$label, $width, $height, $extension, $coefficients, $channels, $bytes, $mibValue]) {
        $need = AttachmentService::decodeNeed($width, $height, $extension, $coefficients, $channels);
        T::same([$bytes, $mibValue], [$need, $mib($need)], $label . ': ' . $mibValue . ' MiB');
    }
    T::check(AttachmentService::decodeNeed(5000, 5000, 'jpg', true, 4) > AttachmentService::DECODE_BUDGET, 'progressives CMYK-JPEG 25 MP über dem Budget');
    T::check(AttachmentService::decodeNeed(5000, 5000, 'webp', false, 3) > AttachmentService::DECODE_BUDGET, 'WebP 25 MP über dem Budget');
    T::check(AttachmentService::decodeNeed(5000, 5000, 'png', false, 4) <= AttachmentService::DECODE_BUDGET, 'RGBA-PNG 25 MP im Budget');
    T::check(AttachmentService::decodeNeed(5000, 5000, 'jpg', true, 3) <= AttachmentService::DECODE_BUDGET, 'progressives 4:4:4-JPEG 25 MP im Budget');
    T::same(AttachmentService::decodeNeed(10, 10, 'jpg', false, 3) + 10 * 10 * 2, AttachmentService::decodeNeed(10, 10, 'jpg', true, 0), 'Koeffizienten mit 0 Kanälen zählen wie 1 Kanal');
    T::same(335544320, AttachmentService::DECODE_BUDGET, 'Budget 320 MiB je Slot');
    T::same(['jpg' => 0, 'png' => 4, 'gif' => 1, 'webp' => 8], AttachmentService::DECODE_EXTRA, 'Decoderpuffer je Format');
    T::same([2, 5000, 16777216, 32], [AttachmentService::DECODE_SLOTS, AttachmentService::DECODE_WAIT_MS, AttachmentService::DECODE_RESERVE, AttachmentService::MAX_JPEG_SCANS], 'Slots, Wartezeit, Reserve, Scans');

    T::same(AttachmentService::DECODE_BUDGET, AttachmentService::budget('2.3.3', 134217728, 20000000), 'System-libgd ⇒ DECODE_BUDGET (Puffer außerhalb von memory_limit)');
    T::same(134217728 - 20000000, AttachmentService::budget('bundled (2.1.0 compatible)', 134217728, 20000000), 'gebündelte GD ⇒ Limit − Verbrauch');
    T::same(AttachmentService::DECODE_BUDGET, AttachmentService::budget('bundled (2.1.0 compatible)', 4294967296, 20000000), 'gebündelte GD, großes Limit ⇒ höchstens DECODE_BUDGET');
    T::same(AttachmentService::DECODE_BUDGET, AttachmentService::budget('bundled (2.1.0 compatible)', -1, 20000000), 'memory_limit -1 ⇒ DECODE_BUDGET');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// D Rechte und Grenzen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('D Rechte: Ablehnung aus can() ohne Datenbank und ohne Datei, Ziel post_id verlangt edit, Drossel (U10)', static function (): void {
    $w = new CuWorld(['attachment_types' => ['txt' => 1]]);
    $w->canOverride['upload'] = AccessDecision::deny('banned_write', 3600);
    $w->db->log = [];
    $r = $w->upload('a.txt', 'Hallo');
    T::same(['banned_write', 3600], [$r['error'] ?? null, $r['retry_after'] ?? null], 'Ablehnung aus can() ⇒ Grund mit retry_after');
    T::same([], $w->db->log, 'keine Datenbankanweisung');
    T::same([], cuStored(), 'keine Datei');
    T::same([], $w->throttleLog, 'Drossel nicht gezählt');
    unset($w->canOverride['upload']);

    $w->canLog = [];
    $r = $w->upload('a.txt', 'Hallo', ['post_id' => $w->firstPost], CU_TOKEN, $w->other);
    T::same(false, $r['success'] ?? null, 'Ziel post_id eines fremden Beitrags ⇒ abgelehnt');
    T::same(['upload', 'edit'], array_column($w->canLog, 'action'), 'beim Ziel post_id prüft upload() zusätzlich edit');
    T::same(['post_id' => $w->firstPost], array_diff_key($w->canLog[1]['context'] ?? [], ['now' => 1]), 'edit mit dem Beitrag als Ziel');
    $r = $w->upload('a.txt', 'Hallo', ['post_id' => $w->firstPost]);
    T::same(true, $r['success'] ?? null, 'eigener Beitrag ⇒ angenommen');

    $w->throttleAllowed = false;
    $w->throttleLog = [];
    $r = $w->upload('a.txt', 'Hallo');
    T::same(['rate_limited', 1234], [$r['error'] ?? null, $r['retry_after'] ?? null], 'Drossel upload ⇒ rate_limited mit retry_after');
    T::same([['action' => 'upload', 'user' => $w->member]], $w->throttleLog, 'Drossel mit Aktion upload und Mitglied');
    $w->throttleAllowed = true;

    $guest = $w->service->upload(0, cuFile('a.txt', 'x'), ['topic_id' => $w->topic], CU_TOKEN);
    T::same('invalid_input', $guest['error'] ?? null, 'Konto 0 ⇒ invalid_input');
    $w->module = false;
    $w->rebuild();
    T::same('module_disabled', $w->upload('a.txt', 'Hallo')['error'] ?? null, 'Modul aus ⇒ module_disabled');
});

T::run('D Eingaben: Ziel, Formular-Kennung, $_FILES-Form, UPLOAD_ERR-Zuordnung, is_uploaded_file', static function (): void {
    $w = new CuWorld(['attachment_types' => ['txt' => 1]]);
    $w->db->log = [];
    $file = cuFile('a.txt', 'Hallo');
    foreach ([
        'kein Ziel' => [[], CU_TOKEN],
        'zwei Ziele' => [['topic_id' => $w->topic, 'forum_id' => $w->forum], CU_TOKEN],
        'Ziel 0' => [['topic_id' => 0], CU_TOKEN],
        'Ziel als Text' => [['topic_id' => (string) $w->topic], CU_TOKEN],
        'unbekannter Zielschlüssel' => [['user_id' => 1], CU_TOKEN],
        'Kennung zu kurz' => [['topic_id' => $w->topic], 'abc'],
        'Kennung mit Großbuchstaben' => [['topic_id' => $w->topic], strtoupper(CU_TOKEN)],
    ] as $label => [$target, $token]) {
        T::same('invalid_input', $w->service->upload($w->member, $file, $target, $token)['error'] ?? null, $label . ' ⇒ invalid_input');
    }
    $multi = ['name' => ['a.txt'], 'tmp_name' => [$file['tmp_name']], 'error' => [0], 'size' => [5]];
    T::same('invalid_input', $w->service->upload($w->member, $multi, ['topic_id' => $w->topic], CU_TOKEN)['error'] ?? null, '$_FILES mit Listen (name[]) ⇒ invalid_input');
    T::same('invalid_input', $w->service->upload($w->member, ['name' => 'a.txt'], ['topic_id' => $w->topic], CU_TOKEN)['error'] ?? null, 'unvollständiger Eintrag ⇒ invalid_input');
    T::same([], $w->db->log, 'Eingabefehler ohne Datenbank');
    foreach ([UPLOAD_ERR_INI_SIZE => 'attachment_too_large', UPLOAD_ERR_FORM_SIZE => 'attachment_too_large', UPLOAD_ERR_NO_TMP_DIR => 'storage_error', UPLOAD_ERR_CANT_WRITE => 'storage_error', UPLOAD_ERR_PARTIAL => 'attachment_invalid', UPLOAD_ERR_NO_FILE => 'attachment_invalid', UPLOAD_ERR_EXTENSION => 'attachment_invalid'] as $code => $expected) {
        T::same($expected, $w->service->upload($w->member, cuFile('a.txt', 'Hallo', $code), ['topic_id' => $w->topic], CU_TOKEN)['error'] ?? null, 'UPLOAD_ERR ' . $code . ' ⇒ ' . $expected);
    }
    $w->uploaded = false;
    T::same('attachment_invalid', $w->upload('a.txt', 'Hallo')['error'] ?? null, 'is_uploaded_file false ⇒ attachment_invalid');
    $w->uploaded = true;
    T::same('attachment_invalid', $w->upload('leer.txt', '')['error'] ?? null, 'leere Datei ⇒ attachment_invalid');
});

T::run('D Kontingente: Stufe ohne Anhänge, Formular voll, Tageskontingent (-1 unbegrenzt), verwaiste je Tag (U10)', static function (): void {
    $w = new CuWorld(['attachment_types' => ['txt' => 1]]);
    $w->limits = ['attachments_per_post' => 0, 'attachments_per_day' => 20];
    T::same('too_many_attachments', $w->upload('a.txt', CU_TEXT)['error'] ?? null, 'attachments_per_post 0 ⇒ too_many_attachments');
    $w->limits = ['attachments_per_post' => 3, 'attachments_per_day' => 0];
    T::same('too_many_attachments', $w->upload('a.txt', CU_TEXT)['error'] ?? null, 'attachments_per_day 0 ⇒ too_many_attachments');
    $w->limits = ['attachments_per_post' => 3, 'attachments_per_day' => 20];
    for ($i = 0; $i < 3; $i++) {
        T::same(true, $w->upload('f' . $i . '.txt', CU_TEXT, [], CU_TOKEN)['success'] ?? null, 'Formular: Anhang ' . ($i + 1) . ' von 3');
    }
    T::same('too_many_attachments', $w->upload('f4.txt', CU_TEXT, [], CU_TOKEN)['error'] ?? null, 'Formular voll (3 verwaiste dieser Kennung) ⇒ too_many_attachments');
    T::same(true, $w->upload('g.txt', CU_TEXT, [], CU_TOKEN2)['success'] ?? null, 'anderes Formular ⇒ angenommen');
    $w->limits = ['attachments_per_post' => -1, 'attachments_per_day' => 20];
    for ($i = 0; $i < 9; $i++) {
        $w->upload('h' . $i . '.txt', CU_TEXT, [], CU_TOKEN2);
    }
    T::same(10, $w->db->count('community_attachments', ['upload_token' => CU_TOKEN2]), 'attachments_per_post -1: MAX_PER_POST = 10 je Formular');
    T::same('too_many_attachments', $w->upload('h.txt', CU_TEXT, [], CU_TOKEN2)['error'] ?? null, '11. Anhang eines Formulars ⇒ too_many_attachments');

    $w2 = new CuWorld(['attachment_types' => ['txt' => 1]]);
    $w2->limits = ['attachments_per_post' => -1, 'attachments_per_day' => 2];
    $w2->db->attachment(['user' => $w2->member, 'post' => $w2->firstPost, 'created_at' => cuUtc(-3600)]);
    T::same(true, $w2->upload('a.txt', CU_TEXT)['success'] ?? null, 'Tageskontingent 2: zweiter Anhang in 24 h (einer zugeordnet) angenommen');
    T::same('trust_limit', $w2->upload('b.txt', CU_TEXT)['error'] ?? null, 'Tageskontingent erreicht ⇒ trust_limit (zugeordnete zählen mit)');
    $w2->db->execute('UPDATE cms_community_attachments SET created_at = ? WHERE id = (SELECT MIN(id) FROM cms_community_attachments)', [cuUtc(-86401)]);
    T::same(true, $w2->upload('c.txt', CU_TEXT)['success'] ?? null, 'Anhang älter als 24 h zählt nicht (UTC-Fenster)');
    $w2->limits = ['attachments_per_post' => -1, 'attachments_per_day' => -1];
    T::same(true, $w2->upload('d.txt', CU_TEXT)['success'] ?? null, 'attachments_per_day -1 ⇒ unbegrenzt');

    $w3 = new CuWorld(['attachment_types' => ['txt' => 1]]);
    $w3->limits = ['attachments_per_post' => -1, 'attachments_per_day' => -1];
    for ($i = 0; $i < AttachmentService::MAX_PENDING_PER_DAY; $i++) {
        $w3->db->attachment(['user' => $w3->member, 'token' => sprintf('%032x', $i + 1), 'created_at' => cuUtc(-60)]);
    }
    T::same('attachment_limit', $w3->upload('a.txt', CU_TEXT)['error'] ?? null, '30 verwaiste Uploads in 24 h ⇒ attachment_limit');
    T::same([], array_values(array_filter(cuStored(), static fn (string $f): bool => str_ends_with($f, '.txt') || str_ends_with($f, '.tmp'))), 'Ablehnungen hinterlassen keine Datei');
});

T::run('D Kontingente unter der Statistiksperre: FOR UPDATE vor den Zählungen, INSERT danach, Ablehnung löscht die Dateien (R16)', static function (): void {
    $w = new CuWorld(['attachment_types' => ['txt' => 1]]);
    $w->limits = ['attachments_per_post' => -1, 'attachments_per_day' => 1];
    $w->db->log = [];
    $r = $w->upload('a.txt', 'Hallo');
    T::same(true, $r['success'] ?? null, 'Upload angenommen');
    $log = $w->db->log;
    $ensure = cuFind($w->db, '/^INSERT (?:IGNORE |OR IGNORE )?INTO cms_community_user_stats/');
    $lock = cuFind($w->db, '/FROM cms_community_user_stats WHERE user_id = \? LIMIT 1 FOR UPDATE$/');
    $insert = cuFind($w->db, '/^INSERT INTO cms_community_attachments/');
    T::check($ensure >= 0 && !$w->db->txAt($ensure), 'ensure() im Autocommit vor der Transaktion');
    T::check($lock > $ensure && $w->db->txAt($lock), 'Statistikzeile des Uploaders gesperrt (in der Transaktion)');
    $countsInTx = [];
    foreach ($log as $i => $sql) {
        if ($i > $lock && $i < $insert && str_contains($sql, 'SELECT COUNT(*) FROM cms_community_attachments')) {
            $countsInTx[] = $w->db->txAt($i);
        }
    }
    T::same([true, true, true], $countsInTx, 'Formular-, Tages- und Pending-Zählung erneut nach der Sperre (in der Transaktion)');
    T::check($insert > $lock && $w->db->txAt($insert), 'INSERT nach der Sperre in der Transaktion');
    $firstTx = -1;
    foreach ($log as $i => $sql) {
        if ($w->db->txAt($i)) {
            $firstTx = $i;
            break;
        }
    }
    T::same($lock, $firstTx, 'die Sperre ist die erste Anweisung der Transaktion');

    // Wettlauf nachgestellt: zwischen Vorprüfung und Sperre legt ein zweiter Upload desselben Mitglieds eine Zeile an
    $w2 = new CuWorld(['attachment_types' => ['txt' => 1]]);
    $w2->limits = ['attachments_per_post' => -1, 'attachments_per_day' => 1];
    $w2->db->beforeExecute['/FROM cms_community_user_stats WHERE user_id = \? LIMIT 1 FOR UPDATE$/'] = static function () use ($w2): void {
        $w2->db->attachment(['user' => $w2->member, 'token' => CU_TOKEN2, 'created_at' => cuUtc()]);
    };
    $r = $w2->upload('b.txt', 'Hallo');
    T::same('trust_limit', $r['error'] ?? null, 'gleichzeitiger Upload am Tageslimit ⇒ trust_limit unter der Sperre');
    T::same(1, $w2->db->count('community_attachments'), 'nur die Zeile des gleichzeitigen Uploads');
    T::same([], array_values(array_filter(cuStored(), static fn (string $f): bool => !str_ends_with($f, '/') && !str_ends_with($f, '.htaccess'))), 'Ablehnung unter der Sperre löscht die Zwischendateien');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// E Ablage
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('E Ablage: storage_path, Monat nach UTC, Modus 0640, Uhr einmal, Zufallsname (U9)', static function (): void {
    $w = new CuWorld(['attachment_types' => ['txt' => 1]]);
    $w->time['now'] = gmmktime(23, 30, 0, 11, 30, 2026);   // Berlin: 1.12. 00:30
    $w->clockReads = 0;
    $r = $w->upload('a.txt', 'Hallo', [], CU_TOKEN);
    $row = $w->row((int) $r['attachment']['id']);
    T::same(1, $w->clockReads, 'Uhr genau einmal je Upload');
    T::check(preg_match(AttachmentService::STORAGE_PATTERN, (string) $row['storage_path']) === 1, 'storage_path YYYY/MM/<32 hex>.<ext>', (string) $row['storage_path']);
    T::same('2026/11/', substr((string) $row['storage_path'], 0, 8), 'Monat nach UTC (2026-11-30 23:30 UTC = Berlin 1.12. 00:30)');
    T::same(['2026/11'], $w->months, 'Ordner über die Closure directory');
    T::same('2026-11-30 23:30:00', $row['created_at'], 'created_at in UTC');
    T::same([null, $w->member, CU_TOKEN], [$row['post_id'], (int) $row['user_id'], $row['upload_token']], 'verwaist, Uploader, Formular-Kennung');
    T::same('0640', substr(sprintf('%o', fileperms($w->path((int) $row['id']))), -4), 'Modus 0640');
    $w->randomValues = ['nicht-hex'];
    T::same('storage_error', $w->upload('b.txt', 'Hallo')['error'] ?? null, 'Zufallswert ohne 32 Hex ⇒ storage_error');
});

T::run('E Ablage: Zwischennamen bis nach dem Commit, INSERT scheitert, move_uploaded_file scheitert (Vertrag §4.6 Nr. 4 bis 6)', static function (): void {
    $w = new CuWorld(['attachment_types' => ['txt' => 1]]);
    $seen = null;
    $w->db->beforeExecute['/^INSERT INTO cms_community_attachments/'] = static function () use (&$seen): void {
        $seen = cuStored();
    };
    $w->db->log = [];
    $r = $w->upload('a.txt', 'Hallo');
    $id = (int) ($r['attachment']['id'] ?? 0);
    $row = $w->row($id);
    $hex = substr(basename((string) $row['storage_path']), 0, 32);
    T::same(['2026/11/' . $hex . '.tmp'], $seen, 'beim INSERT liegt nur die Zwischendatei <hex>.tmp (kein endgültiger Name)');
    T::check($w->db->txAt(cuFind($w->db, '/^INSERT INTO cms_community_attachments/')), 'INSERT in der Transaktion');
    T::same(['2026/11/' . $hex . '.txt'], cuStored(), 'nach dem Commit: endgültiger Name, keine Zwischendatei');
    T::same('2026/11/' . $hex . '.txt', $row['storage_path'], 'storage_path trägt schon den endgültigen Namen');

    if (cuGd()) {
        $seen = null;
        $w->db->beforeExecute['/^INSERT INTO cms_community_attachments/'] = static function () use (&$seen): void {
            $seen = cuStored();
        };
        $r = $w->upload('b.png', cuPng(800, 600));
        $hex2 = substr(basename((string) $w->row((int) $r['attachment']['id'])['storage_path']), 0, 32);
        T::same(['2026/11/' . $hex . '.txt', '2026/11/' . $hex2 . '.preview.tmp', '2026/11/' . $hex2 . '.tmp'], $seen, 'Bild: beim INSERT nur <hex>.tmp und <hex>.preview.tmp');
        T::check(is_file($w->path((int) $r['attachment']['id'])) && is_file($w->path((int) $r['attachment']['id'], true)), 'nach dem Commit: Bild und Vorschau unter den endgültigen Namen');
    }

    $before = cuStored();
    $w->db->failOn = ['/^INSERT INTO cms_community_attachments/'];
    cuLogReset();
    $r = $w->upload('c.txt', 'Hallo');
    T::same('db_error', $r['error'] ?? null, 'INSERT scheitert ⇒ db_error');
    T::same($before, cuStored(), 'keine Datei mit endgültigem Namen, Zwischendateien gelöscht');
    T::check(str_contains(cuLog(), 'Community-Anhänge'), 'Fehler protokolliert');
    $w->db->failOn = [];
    if (cuGd()) {
        $w->db->failOn = ['/^INSERT INTO cms_community_attachments/'];
        $w->upload('d.png', cuPng(800, 600));
        T::same($before, cuStored(), 'Bild: INSERT scheitert ⇒ Bild- und Vorschau-Zwischendatei gelöscht');
        $w->db->failOn = [];
    }
    $w->moveResult = false;
    T::same('storage_error', $w->upload('e.txt', 'Hallo')['error'] ?? null, 'move_uploaded_file scheitert ⇒ storage_error');
    T::same($before, cuStored(), 'keine Datei');
});

T::run('E Ablage: rename nach dem Commit scheitert ⇒ Zeile gelöscht, Dateien weg, storage_error', static function (): void {
    $w = new CuWorld(['attachment_types' => ['txt' => 1]]);
    $hex = sprintf('%032x', 0xabc001);
    // Ein Ordner unter dem endgültigen Namen lässt rename() scheitern.
    mkdir(CU_ROOT . '/2026/11/' . $hex . '.txt', 0700, true);
    $r = $w->upload('a.txt', 'Hallo');
    T::same('storage_error', $r['error'] ?? null, 'rename() scheitert ⇒ storage_error');
    T::same(0, $w->db->count('community_attachments'), 'Zeile wieder gelöscht (deletePending)');
    T::same([], array_values(array_filter(cuStored(), static fn (string $f): bool => str_ends_with($f, '.tmp'))), 'Zwischendatei gelöscht');
    rmdir(CU_ROOT . '/2026/11/' . $hex . '.txt');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// H Auflösung
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('H Auflösung: Eigentümer, Rechte des Beitrags, Platzhalter, Vorschau, Pfadprüfung, inline, public (U11, E2)', static function (): void {
    $w = new CuWorld(['attachment_types' => ['txt' => 1], 'guests_can_read' => true]);
    $own = (int) $w->upload('mein.txt', 'Hallo')['attachment']['id'];
    $w->clockReads = 0;
    $r = $w->service->resolve($w->member, $own);
    T::same(1, $w->clockReads, 'Uhr einmal je Aufruf');
    T::same([true, false, false, null], [$r['success'] ?? null, $r['public'] ?? null, $r['inline'] ?? null, array_key_exists('post_id', $r) ? $r['post_id'] : 'fehlt'], 'Eigentümer sieht den verwaisten Upload (nicht öffentlich, kein inline)');
    T::same([realpath($w->path($own)), 'text/plain', 'mein.txt', 5, hash('sha256', 'Hallo'), false], [$r['path'] ?? null, $r['mime'] ?? null, $r['filename'] ?? null, $r['size'] ?? null, $r['sha256'] ?? null, $r['preview'] ?? null], 'Pfad, Typ, Name, Größe, Prüfsumme');
    T::same('not_found', $w->service->resolve($w->other, $own)['error'] ?? null, 'fremdes Mitglied ⇒ not_found');
    T::same('not_found', $w->service->resolve(null, $own)['error'] ?? null, 'Gast ⇒ not_found');

    // zugeordnet an einen veröffentlichten Beitrag im offenen Forum
    $w->db->execute('UPDATE cms_community_attachments SET post_id = ?, upload_token = NULL WHERE id = ?', [$w->firstPost, $own]);
    $r = $w->service->resolve(null, $own);
    T::same([true, true], [$r['success'] ?? null, $r['public'] ?? null], 'veröffentlicht und Gast-Tor offen ⇒ Gast sieht ihn, public = true');
    T::same(true, $w->service->resolve($w->other, $own)['public'] ?? null, 'public auch für Mitglieder (Gastsicht)');

    // ausgeblendeter Beitrag: Dritte not_found, Autor ok
    $w->db->execute("UPDATE cms_community_posts SET status = 'hidden' WHERE id = ?", [$w->firstPost]);
    $w->rebuild();
    T::same('not_found', $w->service->resolve($w->other, $own)['error'] ?? null, 'ausgeblendeter Beitrag: Dritte ⇒ not_found (Platzhalter)');
    T::same('not_found', $w->service->resolve(null, $own)['error'] ?? null, 'ausgeblendeter Beitrag: Gast ⇒ not_found');
    $r = $w->service->resolve($w->member, $own);
    T::same([true, false], [$r['success'] ?? null, $r['public'] ?? null], 'ausgeblendeter Beitrag: Autor sieht ihn, nicht öffentlich');
    $w->db->execute("UPDATE cms_community_posts SET status = 'pending' WHERE id = ?", [$w->firstPost]);
    $w->rebuild();
    T::same([true, 'not_found'], [$w->service->resolve($w->member, $own)['success'] ?? null, $w->service->resolve($w->other, $own)['error'] ?? null], 'wartender Beitrag: Autor ok, Dritte not_found');
    $w->db->execute("UPDATE cms_community_posts SET status = 'published' WHERE id = ?", [$w->firstPost]);

    // internes Forum (nur Gruppe)
    $group = $w->db->group('Team');
    $insider = $w->db->user(['username' => 'ines']);
    $w->db->join($insider, $group);
    $internal = $w->db->forum(['title' => 'Intern', 'slug' => 'intern']);
    (new \CMS\Services\Community\Repository\ForumRepository($w->db))->savePermissions($internal, [
        ['subject_type' => 'group', 'subject_key' => (string) $group, 'permissions' => ['read' => 1, 'reply' => 1, 'create_topic' => 1, 'upload' => 1]],
        ['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 0]],
        ['subject_type' => 'guest', 'subject_key' => '', 'permissions' => ['read' => 0]],
    ], cuUtc());
    $topic = $w->db->topic(['forum' => $internal, 'user' => $insider]);
    $post = $w->db->post(['topic' => $topic, 'user' => $insider, 'first' => true]);
    $secret = $w->db->attachment(['user' => $insider, 'post' => $post, 'storage_path' => '2026/11/' . str_repeat('c', 32) . '.txt', 'extension' => 'txt']);
    @mkdir(CU_ROOT . '/2026/11', 0700, true);
    file_put_contents(CU_ROOT . '/2026/11/' . str_repeat('c', 32) . '.txt', 'geheim');
    $w->rebuild();
    T::same([true, false], [$w->service->resolve($insider, $secret)['success'] ?? null, $w->service->resolve($insider, $secret)['public'] ?? null], 'internes Forum: Gruppenmitglied sieht ihn, nicht öffentlich');
    T::same('not_found', $w->service->resolve($w->other, $secret)['error'] ?? null, 'internes Forum: Fremde ⇒ not_found');
    T::same('not_found', $w->service->resolve($w->other, 999999)['error'] ?? null, 'unbekannte ID ⇒ dasselbe not_found');
    T::same('not_found', $w->service->resolve($w->other, 0)['error'] ?? null, 'ID 0 ⇒ not_found');

    // manipulierter Pfad, fehlende Datei
    cuLogReset();
    foreach (['../../etc/passwd', '2026/11/../../x.txt', 'abc.txt', '2026/11/' . str_repeat('d', 32) . '.php'] as $bad) {
        $id = $w->db->attachment(['user' => $w->member, 'post' => $w->firstPost, 'storage_path' => $bad, 'extension' => 'txt']);
        T::same('not_found', $w->service->resolve($w->member, $id)['error'] ?? null, 'storage_path ' . $bad . ' ⇒ not_found');
    }
    T::check(str_contains(cuLog(), 'ungültiger Ablagepfad'), 'manipulierter Pfad protokolliert');
    $missing = $w->db->attachment(['user' => $w->member, 'post' => $w->firstPost, 'storage_path' => '2026/11/' . str_repeat('e', 32) . '.txt', 'extension' => 'txt']);
    T::same('not_found', $w->service->resolve($w->member, $missing)['error'] ?? null, 'Datei fehlt ⇒ not_found');
    T::check(str_contains(cuLog(), 'Datei fehlt'), 'fehlende Datei protokolliert');
    T::same('not_found', $w->service->resolve($w->member, $own, true)['error'] ?? null, 'Vorschau eines Nicht-Bildes ⇒ not_found');

    if (cuGd()) {
        $image = (int) $w->upload('bild.png', cuPng(800, 600))['attachment']['id'];
        $small = (int) $w->upload('mini.png', cuPng(40, 20))['attachment']['id'];
        $r = $w->service->resolve($w->member, $image, true);
        T::same([true, true, true, 'image/png'], [$r['success'] ?? null, $r['preview'] ?? null, $r['inline'] ?? null, $r['mime'] ?? null], 'Vorschau eines Bildes: preview = true, inline');
        T::same([realpath($w->path($image, true)), filesize($w->path($image, true)), hash_file('sha256', $w->path($image, true))], [$r['path'] ?? null, $r['size'] ?? null, $r['sha256'] ?? null], 'Vorschau: Pfad, Größe und Prüfsumme der Vorschaudatei');
        $r = $w->service->resolve($w->member, $small, true);
        T::same([true, false, realpath($w->path($small))], [$r['success'] ?? null, $r['preview'] ?? null, $r['path'] ?? null], 'Vorschau fehlt (≤ 480) ⇒ Original, preview = false');
    }

    $w->module = false;
    $w->rebuild();
    $w->db->execute('UPDATE cms_community_attachments SET post_id = NULL WHERE id = ?', [$own]);
    T::same('not_found', $w->service->resolve($w->member, $own)['error'] ?? null, 'Modul aus ⇒ not_found (auch für den Eigentümer)');
});

T::run('H countDownload(): zählt je Aufruf, unbekannte ID false, Fehler false mit Warnung', static function (): void {
    $w = new CuWorld();
    $id = $w->db->attachment(['user' => $w->member, 'post' => $w->firstPost]);
    T::same([true, true], [$w->service->countDownload($id), $w->service->countDownload($id)], 'zwei Abrufe gezählt');
    T::same(2, (int) $w->row($id)['download_count'], 'download_count = 2');
    T::same(false, $w->service->countDownload(999999), 'unbekannte ID ⇒ false');
    $w->db->failOn = ['/download_count = download_count \+ 1/'];
    cuLogReset();
    T::same(false, $w->service->countDownload($id), 'Datenbankfehler ⇒ false');
    T::check(str_contains(cuLog(), 'countDownload'), 'Fehler protokolliert');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// I Aufräumen und Verwerfen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('I purgeOrphans(): UTC-Grenze, Zeile vor Datei, zugeordnete nie, Grenze $limit, Zwischendateien (U12, Vertrag §4.6 Nr. 7)', static function (): void {
    $w = new CuWorld(['attachment_types' => ['txt' => 1]]);
    $make = static function (CuWorld $w, int $age, ?int $post = null) {
        static $n = 0;
        $hex = sprintf('%032x', 0xfeed00 + (++$n));
        $rel = '2026/11/' . $hex . '.txt';
        @mkdir(CU_ROOT . '/2026/11', 0700, true);
        file_put_contents(CU_ROOT . '/' . $rel, 'x');
        $id = $w->db->attachment(['user' => $w->member, 'post' => $post, 'storage_path' => $rel, 'extension' => 'txt', 'created_at' => cuUtc(-$age)]);

        return [$id, CU_ROOT . '/' . $rel];
    };
    [$keep, $keepFile] = $make($w, 86399);
    [$gone, $goneFile] = $make($w, 86401);
    [$assigned, $assignedFile] = $make($w, 900000, $w->firstPost);
    $w->clockReads = 0;
    T::same(1, $w->service->purgeOrphans(), 'ein verwaister Anhang älter als 24 h entfernt');
    T::same(1, $w->clockReads, 'Uhr einmal');
    T::same([true, false, true], [$w->row($keep) !== null, $w->row($gone) !== null, $w->row($assigned) !== null], 'Zone Berlin: 24 h − 1 s bleibt, 24 h + 1 s geht, zugeordnete nie');
    T::same([true, false, true], [is_file($keepFile), is_file($goneFile), is_file($assignedFile)], 'Dateien entsprechend');

    // Zeile vor Datei: scheitert das DELETE, bleibt die Datei
    [$failing, $failingFile] = $make($w, 90000);
    $w->db->failOn = ['/^DELETE FROM cms_community_attachments/'];
    cuLogReset();
    T::same(0, $w->service->purgeOrphans(), 'DELETE scheitert ⇒ nichts entfernt');
    T::same([true, true], [$w->row($failing) !== null, is_file($failingFile)], 'Zeile und Datei bleiben');
    T::check(str_contains(cuLog(), 'purgeOrphans'), 'Fehler protokolliert');
    $w->db->failOn = [];

    // gleichzeitig zugeordnet (zwischen Lesen und Löschen): Zeile bleibt, Datei bleibt
    $w->db->beforeExecute['/^DELETE FROM cms_community_attachments WHERE id = \? AND post_id IS NULL$/'] = static function () use ($w, $failing): void {
        $w->db->execute('UPDATE cms_community_attachments SET post_id = ? WHERE id = ?', [$w->firstPost, $failing]);
    };
    T::same(0, $w->service->purgeOrphans(), 'inzwischen zugeordnet ⇒ nicht entfernt');
    T::same([true, true], [$w->row($failing) !== null, is_file($failingFile)], 'zugeordneter Anhang behält Zeile und Datei');

    // Grenze $limit, älteste zuerst
    $old = [];
    for ($i = 0; $i < 4; $i++) {
        $old[] = $make($w, 200000 - $i * 1000)[0];
    }
    T::same(2, $w->service->purgeOrphans(2), 'höchstens $limit = 2');
    T::same([false, false, true, true], array_map(static fn (int $id): bool => $w->row($id) !== null, $old), 'die ältesten zuerst');

    // Zwischendateien ohne Datenbank
    $dirNow = CU_ROOT . '/2026/11';
    $dirPrev = CU_ROOT . '/2026/10';
    @mkdir($dirPrev, 0700, true);
    $tmpOld = $dirNow . '/' . str_repeat('a', 32) . '.tmp';
    $tmpOldPreview = $dirPrev . '/' . str_repeat('b', 32) . '.preview.tmp';
    $tmpYoung = $dirNow . '/' . str_repeat('c', 32) . '.tmp';
    $finalOld = $dirNow . '/' . str_repeat('d', 32) . '.txt';
    $otherOld = $dirNow . '/notiz.tmp';
    foreach ([$tmpOld, $tmpOldPreview, $tmpYoung, $finalOld, $otherOld] as $file) {
        file_put_contents($file, 'x');
    }
    foreach ([$tmpOld, $tmpOldPreview, $finalOld, $otherOld] as $file) {
        touch($file, cuNow(-86401));
    }
    touch($tmpYoung, cuNow(-86399));
    $w->db->log = [];
    $w->service->purgeOrphans();
    T::same([false, false, true, true, true], [is_file($tmpOld), is_file($tmpOldPreview), is_file($tmpYoung), is_file($finalOld), is_file($otherOld)], 'Zwischendateien älter als 24 h (laufender und voriger Monat) gelöscht, jüngere, endgültige und fremde Namen bleiben');
    T::same(['2026/11', '2026/10'], array_slice($w->months, -2), 'Ordner des laufenden und des vorigen Monats (UTC)');

    // Grenze auch für Zwischendateien
    for ($i = 0; $i < 3; $i++) {
        $file = $dirNow . '/' . sprintf('%032x', 0x77 + $i) . '.tmp';
        file_put_contents($file, 'x');
        touch($file, cuNow(-90000));
    }
    $w->service->purgeOrphans(2);
    $hexTmp = array_filter(glob($dirNow . '/*.tmp') ?: [], static fn (string $f): bool => preg_match('/^[a-f0-9]{32}\.tmp$/', basename($f)) === 1);
    T::same(2, count($hexTmp), 'Zwischendateien: höchstens $limit = 2 je Lauf (eine alte und die junge bleiben)');
});

T::run('I discard(): eigene verwaiste ja, zugeordnete und fremde attachment_not_found, Dateien nach der Zeile', static function (): void {
    $w = new CuWorld(['attachment_types' => ['txt' => 1]]);
    $id = (int) $w->upload('a.txt', 'Hallo')['attachment']['id'];
    $file = $w->path($id);
    T::same('attachment_not_found', $w->service->discard($w->other, $id)['error'] ?? null, 'fremder Upload ⇒ attachment_not_found');
    T::same(true, is_file($file), 'Datei bleibt');
    T::same(['success' => true, 'id' => $id], $w->service->discard($w->member, $id), 'eigener verwaister Upload verworfen');
    T::same([null, false], [$w->row($id), is_file($file)], 'Zeile und Datei weg');
    T::same('attachment_not_found', $w->service->discard($w->member, $id)['error'] ?? null, 'zweites Verwerfen ⇒ attachment_not_found');
    $assigned = $w->db->attachment(['user' => $w->member, 'post' => $w->firstPost]);
    T::same('attachment_not_found', $w->service->discard($w->member, $assigned)['error'] ?? null, 'zugeordneter Anhang ⇒ attachment_not_found');
    T::same('invalid_input', $w->service->discard($w->member, 0)['error'] ?? null, 'ID 0 ⇒ invalid_input');
    $w->db->failOn = ['/^SELECT .* FROM cms_community_attachments WHERE id = \?/'];
    T::same('db_error', $w->service->discard($w->member, $assigned)['error'] ?? null, 'Datenbankfehler ⇒ db_error');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// J Listen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('J Listen: listFor() nur view = full, eine Abfrage je 100 Beiträge; pendingForForm() nur eigene des Formulars', static function (): void {
    $w = new CuWorld(['attachment_types' => ['txt' => 1]]);
    $posts = [];
    for ($i = 0; $i < 150; $i++) {
        $posts[] = $w->db->post(['topic' => $w->topic, 'user' => $w->member]);
    }
    $a1 = $w->db->attachment(['user' => $w->member, 'post' => $posts[0], 'original_name' => 'eins.txt']);
    $a2 = $w->db->attachment(['user' => $w->member, 'post' => $posts[0], 'original_name' => 'zwei.png', 'extension' => 'png', 'mime_type' => 'image/png', 'width' => 10, 'height' => 5, 'preview' => true]);
    $a3 = $w->db->attachment(['user' => $w->member, 'post' => $posts[149]]);
    $hidden = $w->db->attachment(['user' => $w->member, 'post' => $posts[5]]);
    $items = array_map(static fn (int $id): array => ['view' => 'full', 'id' => $id], $posts);
    $items[5] = ['view' => 'placeholder', 'id' => $posts[5]];
    $w->db->log = [];
    $list = $w->service->listFor($items);
    T::same(2, count(array_filter($w->db->log, static fn (string $sql): bool => str_contains($sql, 'FROM cms_community_attachments'))), '150 Beiträge ⇒ zwei Abfragen');
    T::same([$posts[0], $posts[149]], array_keys($list), 'nur Beiträge mit Anhang, Platzhalter ohne');
    T::same([$a1, $a2], array_column($list[$posts[0]], 'id'), 'aufsteigend nach ID');
    T::same(['id' => $a2, 'name' => 'zwei.png', 'size' => 100, 'mime' => 'image/png', 'extension' => 'png', 'is_image' => true, 'width' => 10, 'height' => 5, 'has_preview' => true, 'animated' => false], $list[$posts[0]][1], 'Info-Form ohne Ablagepfad, Prüfsumme und Kennung');
    T::check(!isset($list[$posts[5]]) && $hidden > 0 && $a3 > 0, 'Anhang eines Platzhalters fehlt');
    T::same([], $w->service->listFor([]), 'leer ohne Abfrage');
    $w->db->failOn = ['/FROM cms_community_attachments/'];
    T::same([], $w->service->listFor($items), 'Fehler ⇒ []');
    $w->db->failOn = [];

    $mine = (int) $w->upload('a.txt', CU_TEXT, [], CU_TOKEN)['attachment']['id'];
    $w->upload('b.txt', CU_TEXT, [], CU_TOKEN2);
    $w->upload('c.txt', CU_TEXT, [], CU_TOKEN, $w->other);
    T::same([$mine], array_column($w->service->pendingForForm($w->member, CU_TOKEN), 'id'), 'pendingForForm(): nur eigene, verwaiste dieses Formulars');
    T::same([], $w->service->pendingForForm($w->member, 'kaputt'), 'ungültige Kennung ⇒ []');
    T::same(32, strlen($w->service->newToken()), 'newToken(): 32 Hex-Zeichen');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// K Wirkung
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Wirkung: attachment_types (txt aus ⇒ abgelehnt, an ⇒ angenommen; jpg aus ⇒ abgelehnt)', static function (): void {
    $off = new CuWorld();
    T::same('attachment_type', $off->upload('a.txt', 'Hallo')['error'] ?? null, 'Standard: txt aus ⇒ attachment_type');
    $on = new CuWorld(['attachment_types' => ['txt' => 1]]);
    T::same(true, $on->upload('a.txt', 'Hallo')['success'] ?? null, 'txt an ⇒ angenommen');
    $pdf = "%PDF-1.4\n%%EOF\n";
    $noPdf = new CuWorld(['attachment_types' => ['pdf' => 0]]);
    T::same('attachment_type', $noPdf->upload('a.pdf', $pdf)['error'] ?? null, 'pdf aus ⇒ attachment_type');
    if (cuGd()) {
        $noJpg = new CuWorld(['attachment_types' => ['jpg' => 0]]);
        T::same('attachment_type', $noJpg->upload('a.jpg', cuJpeg())['error'] ?? null, 'jpg aus ⇒ attachment_type');
        T::same('attachment_type', $noJpg->upload('a.jpeg', cuJpeg())['error'] ?? null, 'jpg aus gilt auch für den Alias jpeg');
        T::same(true, $noJpg->upload('a.png', cuPng())['success'] ?? null, 'png bleibt an');
    }
});

T::run('Wirkung: attachment_max_kb (64 KB: 64 KB angenommen, 65 KB attachment_too_large)', static function (): void {
    $w = new CuWorld(['attachment_types' => ['txt' => 1], 'attachment_max_kb' => 64]);
    T::same(64 * 1024, AttachmentService::effectiveMaxBytes(64), 'effectiveMaxBytes(64) = 65.536 (unter upload_max_filesize)');
    T::same(true, $w->upload('a.txt', str_repeat('a', 64 * 1024))['success'] ?? null, '64 KB ⇒ angenommen');
    T::same('attachment_too_large', $w->upload('b.txt', str_repeat('a', 65 * 1024))['error'] ?? null, '65 KB ⇒ attachment_too_large');
    $big = new CuWorld(['attachment_types' => ['txt' => 1], 'attachment_max_kb' => 512]);
    T::same(true, $big->upload('c.txt', str_repeat('a', 65 * 1024))['success'] ?? null, '512 KB: 65 KB ⇒ angenommen');
    $ini = static function (string $key): int {
        $value = trim((string) ini_get($key));
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    };
    $limits = array_filter([$ini('upload_max_filesize'), $ini('post_max_size')], static fn (int $v): bool => $v > 0);
    T::same(min([51200 * 1024, ...$limits]), AttachmentService::effectiveMaxBytes(51200), 'effectiveMaxBytes(51200) begrenzt durch upload_max_filesize und post_max_size');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// L Zuordnungsbausteine (claimIds, prepareClaim, lock, verify, apply, syncTopicFlag, afterCommit)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('L claimIds() und emptyPlan()', static function (): void {
    T::same([2, 5, 9], AttachmentService::claimIds([9, '5', 2, 5]), 'eindeutig, aufsteigend, Ziffern-Texte erlaubt');
    T::same([], AttachmentService::claimIds([]), 'leere Liste');
    foreach (['kein Array' => '5', 'Null' => [0], 'negativ' => [-1], 'Text' => ['abc'], 'Fließkomma' => [1.5], 'zu groß' => [4294967296], 'führende Null' => ['05'], 'null' => [null]] as $label => $value) {
        T::same(null, AttachmentService::claimIds($value), $label . ' ⇒ null');
    }
    T::same(['ids' => [], 'remove' => [], 'lock' => [], 'existing' => 0, 'after' => 0, 'files' => []], AttachmentService::emptyPlan(), 'leerer Plan');
});

T::run('L prepareClaim(): aufgeführte, eingebettete und entfernte IDs; Obergrenze; leerer Plan ohne Abfrage', static function (): void {
    $w = new CuWorld();
    $mine = $w->db->attachment(['user' => $w->member, 'token' => CU_TOKEN]);
    $mine2 = $w->db->attachment(['user' => $w->member, 'token' => CU_TOKEN]);
    $foreign = $w->db->attachment(['user' => $w->other, 'token' => CU_TOKEN]);
    $assignedElsewhere = $w->db->attachment(['user' => $w->member, 'post' => 999]);
    $inPost = $w->db->attachment(['user' => $w->member, 'post' => $w->firstPost, 'storage_path' => '2026/11/' . str_repeat('f', 32) . '.txt']);

    $w->db->log = [];
    $p = $w->service->prepareClaim($w->member, [], []);
    T::same([true, AttachmentService::emptyPlan(), []], [$p['ok'], $p['plan'], $w->db->log], 'leerer Plan ohne Abfrage');

    $w->db->log = [];
    $p = $w->service->prepareClaim($w->member, [$mine2, $mine], [$foreign]);
    T::same(true, $p['ok'] ?? null, 'eigene aufgeführte IDs ⇒ ok');
    T::same([[$mine, $mine2], [], [$mine, $mine2], 0, 2], [$p['plan']['ids'], $p['plan']['remove'], $p['plan']['lock'], $p['plan']['existing'], $p['plan']['after']], 'Plan: ids und lock aufsteigend, after = 2');
    T::same(1, count($w->db->log), 'eine findMany-Abfrage');
    T::same(['attachment_not_found'], [$w->service->prepareClaim($w->member, [$foreign], [])['error'] ?? null], 'fremder aufgeführter Upload ⇒ attachment_not_found');
    T::same('attachment_not_found', $w->service->prepareClaim($w->member, [987654], [])['error'] ?? null, 'unbekannte ID ⇒ attachment_not_found');
    T::same([true, [$assignedElsewhere]], [$w->service->prepareClaim($w->member, [$assignedElsewhere], [])['ok'] ?? null, $w->service->prepareClaim($w->member, [$assignedElsewhere], [])['plan']['ids'] ?? null], 'post_id wird hier nicht geprüft (doppeltes Absenden erreicht die Dublette)');
    $p = $w->service->prepareClaim($w->member, [], [$mine, $foreign, $assignedElsewhere, 424242]);
    T::same([$mine], $p['plan']['ids'] ?? null, 'eingebettete IDs: nur eigene, noch nicht zugeordnete');

    // Bearbeiten
    $p = $w->service->prepareClaim($w->member, [$inPost, $mine], [$inPost], $w->firstPost, []);
    T::same([[$mine], 1, 2], [$p['plan']['ids'] ?? null, $p['plan']['existing'] ?? null, $p['plan']['after'] ?? null], 'Bearbeiten: schon zugeordneter Anhang wird übergangen, existing = countForPost');
    $p = $w->service->prepareClaim($w->other, [$inPost], [], $w->firstPost, []);
    T::same([true, []], [$p['ok'] ?? null, $p['plan']['ids'] ?? null], 'Moderator bearbeitet: Anhang des Beitrags (fremder Uploader) wird übergangen');
    $p = $w->service->prepareClaim($w->member, [], [], $w->firstPost, [$inPost]);
    T::same([[], [$inPost], [$inPost], 1, 0], [$p['plan']['ids'], $p['plan']['remove'], $p['plan']['lock'], $p['plan']['existing'], $p['plan']['after']], 'Entfernen: remove, lock, after = 0');
    T::same([['storage_path' => '2026/11/' . str_repeat('f', 32) . '.txt', 'extension' => 'txt', 'has_preview' => 0]], $p['plan']['files'], 'Dateien der entfernten Anhänge für afterCommit()');
    T::same('attachment_not_found', $w->service->prepareClaim($w->member, [], [], $w->firstPost, [$mine])['error'] ?? null, 'Entfernen eines nicht zugeordneten Anhangs ⇒ attachment_not_found');
    T::same('attachment_not_found', $w->service->prepareClaim($w->member, [], [], null, [$inPost])['error'] ?? null, 'Entfernen ohne Beitrag ⇒ attachment_not_found');

    // Obergrenze
    $many = [];
    for ($i = 0; $i < 11; $i++) {
        $many[] = $w->db->attachment(['user' => $w->member]);
    }
    T::same('attachment_limit', $w->service->prepareClaim($w->member, $many, [])['error'] ?? null, '11 Anhänge ⇒ attachment_limit');
    T::same(true, $w->service->prepareClaim($w->member, array_slice($many, 0, 10), [])['ok'] ?? null, '10 Anhänge ⇒ ok');
    for ($i = 0; $i < 9; $i++) {
        $w->db->attachment(['user' => $w->member, 'post' => $w->firstPost]);
    }
    T::same('attachment_limit', $w->service->prepareClaim($w->member, array_slice($many, 0, 1), [], $w->firstPost)['error'] ?? null, 'Bearbeiten: 10 vorhandene + 1 ⇒ attachment_limit');
    T::same(true, $w->service->prepareClaim($w->member, array_slice($many, 0, 1), [], $w->firstPost, [$inPost])['ok'] ?? null, 'Bearbeiten: 10 − 1 + 1 ⇒ ok');
    $w->db->failOn = ['/FROM cms_community_attachments/'];
    T::same(['ok' => false, 'error' => 'db_error'], $w->service->prepareClaim($w->member, [$mine], []), 'Datenbankfehler ⇒ db_error');
});

T::run('L lock(), verify(), apply(): Sperre über PRIMARY aufsteigend, Prüfung unter der Sperre, Zuordnung nur verwaister eigener', static function (): void {
    $w = new CuWorld();
    $a = $w->db->attachment(['user' => $w->member, 'token' => CU_TOKEN]);
    $b = $w->db->attachment(['user' => $w->member, 'token' => CU_TOKEN]);
    $plan = $w->service->prepareClaim($w->member, [$b, $a], [])['plan'];
    $w->db->log = [];
    $locked = $w->service->lock($plan);
    T::same(1, count($w->db->log), 'eine Anweisung');
    T::check(str_ends_with($w->db->log[0], ' ORDER BY id ASC FOR UPDATE') && str_contains($w->db->log[0], 'WHERE id IN (?, ?)'), 'Sperre über PRIMARY: id IN (…) ORDER BY id ASC FOR UPDATE', $w->db->log[0] ?? '');
    T::same([$a, $b], $w->db->paramsAt(0), 'IDs aufsteigend');
    T::same([$a, $b], array_keys($locked), 'gesperrte Zeilen');
    T::same([], $w->service->lock(AttachmentService::emptyPlan()), 'leerer Plan: keine Sperre');
    T::same(null, AttachmentService::verify($plan, $locked, $w->member, null), 'verify: in Ordnung');
    T::same('attachment_not_found', AttachmentService::verify($plan, [$a => $locked[$a]], $w->member, null), 'verify: Zeile fehlt (aufgeräumt) ⇒ attachment_not_found');
    T::same('attachment_not_found', AttachmentService::verify($plan, [$a => $locked[$a], $b => ['post_id' => 77] + $locked[$b]], $w->member, null), 'verify: inzwischen zugeordnet ⇒ attachment_not_found');
    T::same('attachment_not_found', AttachmentService::verify($plan, $locked, $w->other, null), 'verify: fremder Uploader ⇒ attachment_not_found');
    T::same('attachment_limit', AttachmentService::verify(['after' => 11] + $plan, $locked, $w->member, null), 'verify: after über MAX_PER_POST ⇒ attachment_limit');

    $w->service->apply($plan, $w->firstPost, $w->member);
    T::same([[$w->firstPost, null], [$w->firstPost, null]], [[(int) $w->row($a)['post_id'], $w->row($a)['upload_token']], [(int) $w->row($b)['post_id'], $w->row($b)['upload_token']]], 'apply: post_id gesetzt, Kennung geleert');
    $thrown = null;
    try {
        $w->service->apply($plan, $w->firstPost, $w->member);
    } catch (\RuntimeException $e) {
        $thrown = $e->getMessage();
    }
    T::check($thrown !== null, 'apply auf schon zugeordnete Zeilen ⇒ RuntimeException (Rücknahme)', (string) $thrown);
    T::same(0, $w->repo->claim([$a], 4242, $w->member), 'claim() ändert keine zugeordnete Zeile (post_id IS NULL in derselben Anweisung)');
    $c = $w->db->attachment(['user' => $w->other]);
    T::same(0, $w->repo->claim([$c], $w->firstPost, $w->member), 'claim() ändert keine fremde Zeile');

    $remove = $w->service->prepareClaim($w->member, [], [], $w->firstPost, [$a])['plan'];
    T::same(null, AttachmentService::verify($remove, $w->service->lock($remove), $w->member, $w->firstPost), 'verify: zu entfernender Anhang gehört zum Beitrag');
    T::same('attachment_not_found', AttachmentService::verify($remove, $w->service->lock($remove), $w->member, 31337), 'verify: zu entfernender Anhang eines anderen Beitrags ⇒ attachment_not_found');
    $w->service->apply($remove, $w->firstPost, $w->member);
    T::same(null, $w->row($a), 'apply: entfernter Anhang gelöscht');
    try {
        $w->service->apply($remove, $w->firstPost, $w->member);
        T::check(false, 'zweites Entfernen wirft');
    } catch (\RuntimeException) {
        T::check(true, 'zweites Entfernen wirft RuntimeException');
    }
});

T::run('L syncTopicFlag() und afterCommit(): has_attachments nur für veröffentlichte Beiträge, Neuberechnung beim Entfernen', static function (): void {
    $w = new CuWorld();
    $a = $w->db->attachment(['user' => $w->member]);
    $plan = $w->service->prepareClaim($w->member, [$a], [])['plan'];
    $topic = $w->db->row('community_topics', ['id' => $w->topic]);
    $version = (int) $topic['version'];
    $w->service->syncTopicFlag($topic, false, $plan, cuUtc());
    T::same([0, $version], [(int) $w->db->row('community_topics', ['id' => $w->topic])['has_attachments'], (int) $w->db->row('community_topics', ['id' => $w->topic])['version']], 'wartender Beitrag mit Anhang: Kennzeichen bleibt 0');
    $w->service->syncTopicFlag($topic, true, $plan, cuUtc(5));
    $after = $w->db->row('community_topics', ['id' => $w->topic]);
    T::same([1, $version + 1, cuUtc(5)], [(int) $after['has_attachments'], (int) $after['version'], $after['updated_at']], 'veröffentlichter Beitrag mit Anhang: has_attachments = 1, version + 1, updated_at');
    $w->db->log = [];
    $w->service->syncTopicFlag($after, true, $plan, cuUtc(6));
    T::same([], $w->db->log, 'schon gesetzt: keine Anweisung');

    // Entfernen des letzten Anhangs: Neuberechnung
    $w->service->apply($plan, $w->firstPost, $w->member);
    @mkdir(CU_ROOT . '/2026/11', 0700, true);
    $rel = '2026/11/' . str_repeat('9', 32) . '.png';
    $w->db->execute('UPDATE cms_community_attachments SET storage_path = ?, extension = ?, has_preview = 1 WHERE id = ?', [$rel, 'png', $a]);
    file_put_contents(CU_ROOT . '/' . $rel, 'x');
    file_put_contents(CU_ROOT . '/2026/11/' . str_repeat('9', 32) . '.preview.png', 'x');
    $remove = $w->service->prepareClaim($w->member, [], [], $w->firstPost, [$a])['plan'];
    $w->service->apply($remove, $w->firstPost, $w->member);
    $w->service->syncTopicFlag($w->db->row('community_topics', ['id' => $w->topic]), true, $remove, cuUtc(7));
    T::same(0, (int) $w->db->row('community_topics', ['id' => $w->topic])['has_attachments'], 'letzter Anhang entfernt ⇒ has_attachments = 0');
    T::same([true, true], [is_file(CU_ROOT . '/' . $rel), is_file(CU_ROOT . '/2026/11/' . str_repeat('9', 32) . '.preview.png')], 'Dateien bleiben bis nach dem Commit');
    $w->service->afterCommit($remove);
    T::same([false, false], [is_file(CU_ROOT . '/' . $rel), is_file(CU_ROOT . '/2026/11/' . str_repeat('9', 32) . '.preview.png')], 'afterCommit(): Datei und Vorschau gelöscht');

    // Entfernen, aber ein anderer veröffentlichter Beitrag hat noch einen Anhang
    $other = $w->db->post(['topic' => $w->topic, 'user' => $w->member]);
    $keep = $w->db->attachment(['user' => $w->member, 'post' => $other]);
    $b = $w->db->attachment(['user' => $w->member, 'post' => $w->firstPost]);
    $w->db->execute('UPDATE cms_community_topics SET has_attachments = 1 WHERE id = ?', [$w->topic]);
    $remove = $w->service->prepareClaim($w->member, [], [], $w->firstPost, [$b])['plan'];
    $w->service->apply($remove, $w->firstPost, $w->member);
    $w->db->log = [];
    $w->service->syncTopicFlag($w->db->row('community_topics', ['id' => $w->topic]), true, $remove, cuUtc(8));
    T::same(1, (int) $w->db->row('community_topics', ['id' => $w->topic])['has_attachments'], 'anderer veröffentlichter Beitrag mit Anhang ⇒ bleibt 1');
    T::check($keep > 0 && !array_filter($w->db->log, static fn (string $s): bool => str_starts_with($s, 'UPDATE')), 'keine Schreibanweisung ohne Abweichung');
    cuLogReset();
    $w->service->afterCommit(['files' => [['storage_path' => '../../etc/passwd', 'extension' => 'txt', 'has_preview' => 0]]]);
    T::check(str_contains(cuLog(), 'ungültiger Ablagepfad'), 'afterCommit(): ungültiger Pfad nur protokolliert');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// M Repository
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('M AttachmentRepository: Spaltengrenzen, Stücke zu 100, Formen, Reihenfolge der Aufräumliste', static function (): void {
    $w = new CuWorld();
    $base = ['user_id' => $w->member, 'storage_path' => '2026/11/' . str_repeat('a', 32) . '.txt', 'original_name' => 'a.txt', 'mime_type' => 'text/plain', 'extension' => 'txt', 'file_size' => 5, 'sha256' => str_repeat('0', 64), 'width' => null, 'height' => null, 'has_preview' => 0, 'upload_token' => CU_TOKEN, 'created_at' => cuUtc()];
    $id = $w->repo->insert($base);
    T::check($id > 0, 'insert() liefert die ID');
    foreach ([
        'unbekannte Spalte' => ['download_count' => 3],
        'Name zu lang' => ['original_name' => str_repeat('n', 256)],
        'Breite über SMALLINT' => ['width' => 70000],
        'Breite als Text' => ['width' => '10'],
        'negative Größe' => ['file_size' => -1],
        'Kennung zu lang' => ['upload_token' => str_repeat('a', 33)],
        'Endung zu lang' => ['extension' => str_repeat('x', 11)],
        'user_id 0' => ['user_id' => 0],
    ] as $label => $change) {
        $thrown = false;
        try {
            $w->repo->insert($change + $base);
        } catch (\InvalidArgumentException) {
            $thrown = true;
        }
        T::same(true, $thrown, $label . ' ⇒ InvalidArgumentException');
    }
    $found = $w->repo->find($id);
    T::same([$id, null, $w->member, 5, 0, 0], [$found['id'], $found['post_id'], $found['user_id'], $found['file_size'], $found['has_preview'], $found['download_count']], 'find(): Ganzzahlen als int');
    $ids = [];
    for ($i = 0; $i < 230; $i++) {
        $ids[] = $w->db->attachment(['user' => $w->member]);
    }
    $w->db->log = [];
    $many = $w->repo->findMany(array_reverse($ids));
    T::same([3, $ids], [count($w->db->log), array_keys($many)], 'findMany(): 230 IDs in drei Stücken, Schlüssel aufsteigend');
    T::same(['id', 'post_id', 'user_id', 'storage_path', 'mime_type', 'extension', 'width', 'height', 'has_preview'], array_keys($many[$ids[0]]), 'findMany(): Form für Renderer und Plan');
    $w->db->log = [];
    $w->repo->lockMany($ids);
    T::same(3, count(array_filter($w->db->log, static fn (string $sql): bool => str_ends_with($sql, 'FOR UPDATE'))), 'lockMany(): drei sperrende Stücke');
    T::same([], $w->repo->findMany([]), 'leere Liste ohne Abfrage');
    $o1 = $w->db->attachment(['user' => $w->member, 'created_at' => '2026-11-01 10:00:00']);
    $o2 = $w->db->attachment(['user' => $w->member, 'created_at' => '2026-10-01 10:00:00']);
    $o3 = $w->db->attachment(['user' => $w->member, 'created_at' => '2026-10-01 10:00:00']);
    $orphans = $w->repo->orphansBefore('2026-11-05 00:00:00', 3);
    T::same([$o2, $o3, $o1], array_column($orphans, 'id'), 'orphansBefore(): älteste zuerst, bei gleicher Zeit nach ID');
    T::same(['id', 'user_id', 'storage_path', 'extension', 'has_preview'], array_keys($orphans[0]), 'orphansBefore(): Form');
    T::same([1, 0], [$w->repo->deletePending($o1, $w->member), $w->repo->deletePending($o1)], 'deletePending(): einmal');
    T::same(0, $w->repo->deletePending($o2, $w->other), 'deletePending() mit fremdem Mitglied ⇒ 0');
    T::same(true, $w->repo->bumpDownloads($o2), 'bumpDownloads(): true');
    T::same(false, $w->repo->bumpDownloads(987654), 'bumpDownloads() unbekannt: false');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// N Konstanten und Grenzen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('N Konstanten: Grenzen, Speichermuster, Orientierung, gefährliche Typen', static function (): void {
    T::same([10, 30, 2560, 25000000, 480, 86400], [AttachmentService::MAX_PER_POST, AttachmentService::MAX_PENDING_PER_DAY, AttachmentService::MAX_EDGE, AttachmentService::MAX_PIXELS, AttachmentService::PREVIEW_EDGE, AttachmentService::ORPHAN_SECONDS], 'Grenzen');
    T::same([85, 82, 6], [AttachmentService::JPEG_QUALITY, AttachmentService::WEBP_QUALITY, AttachmentService::PNG_LEVEL], 'Qualität');
    foreach (['2026/11/' . str_repeat('a', 32) . '.jpg' => true, '2026/11/' . str_repeat('a', 32) . '.preview.jpg' => false, '../2026/11/x.jpg' => false, '2026/11/' . str_repeat('A', 32) . '.jpg' => false] as $path => $ok) {
        T::same($ok, preg_match(AttachmentService::STORAGE_PATTERN, $path) === 1, 'STORAGE_PATTERN ' . $path);
    }
    T::same(8, count(AttachmentService::ORIENTATION), 'Orientierung 1 bis 8');
    foreach (['image/svg+xml', 'text/html', 'application/x-php', 'application/xml'] as $mime) {
        T::check(in_array($mime, AttachmentService::DANGEROUS_MIME, true), 'DANGEROUS_MIME enthält ' . $mime);
    }
    foreach (['php', 'phtml', 'phar', 'htaccess', 'svg', 'html', 'js', 'exe'] as $extension) {
        T::check(in_array($extension, AttachmentService::DANGEROUS_EXTENSIONS, true), 'DANGEROUS_EXTENSIONS enthält ' . $extension);
    }
    $source = (string) file_get_contents(ABSPATH . 'core/Services/Community/Service/AttachmentService.php');
    T::check(!str_contains($source, 'imagedestroy('), 'unset() statt imagedestroy() (PHP 8.5)');
    T::same(1, substr_count($source, 'imagesavealpha($image, true);'), 'eine Stelle für den Alphakanal');
});

foreach (glob(__DIR__ . '/part-*.php') ?: [] as $part) {
    require $part;
}

T::finish();
