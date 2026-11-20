<?php
/**
 * Anhänge der Community (PR #2b; Vertrag DESIGN-2b §3.6, §3.10, §4.6, Entwurf medien §1.3, §2.1 bis §2.4): der einzige Weg für
 * Dateien des Moduls. Prüfen, Bereinigen, Ablegen, Zuordnen, Auflösen für die Auslieferung, Entfernen, Aufräumen.
 *
 * - Prüfung fail-closed: Endung aus der Allowlist (`TYPES`, Einstellung `attachment_types`), keine gefährliche Innenendung, Inhaltstyp
 *   aus `finfo` (leer = abgelehnt), Kopfprüfung bei Nicht-Bildern, ZIP wird nie entpackt. Dateinamen ohne Pfad, Steuer-, Bidi- und
 *   Formatzeichen.
 * - Bilder (A3: Format bleibt): vor dem ersten Dekodieren Pixelgrenze, Scan-Grenze (JPEG), Speicherschätzung je Format gegen das
 *   Budget und ein Dekodier-Slot (`flock`, zwei je Server). Danach neu kodiert, höchstens MAX_EDGE, EXIF-Drehung auf der verkleinerten
 *   Leinwand, Metadaten entfernt, Vorschau PREVIEW_EDGE im selben Format.
 * - Ablage zweistufig: Zwischendateien `<hex>.tmp`/`<hex>.preview.tmp`, Zeile unter der Statistiksperre des Uploaders (Kontingente
 *   exakt), `rename()` auf den endgültigen Namen erst nach dem Commit. Ein Abbruch hinterlässt nur Zwischendateien ohne Zeile oder eine
 *   Zeile ohne endgültige Datei; `purgeOrphans()` räumt beides auf.
 * - Zuordnung beim Absenden (`prepareClaim`, `lock`, `verify`, `apply`, `syncTopicFlag`, `afterCommit`) rufen TopicService und
 *   PostService; Sperrebene 5 (Anhänge) nach der Statistik, nur vorher gefundene IDs.
 * - Uhr genau einmal je öffentlichem Aufruf; Dateisystem nie in einer Transaktion. Keine Hooks.
 *
 * Ergebnisse sind Arrays `{success, error?, retry_after?, …}`; `error` ist ein Code aus `Support\Codes` oder ein Grund von `can()`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Community\Repository\AttachmentRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Repository\UserStatsRepository;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Transaction;
use CMS\Services\Forms\FormUploadService;
use CMS\Services\PrivateStorageService;

if (!defined('ABSPATH')) {
    exit;
}

final class AttachmentService
{
    /** Bereich im privaten Speicher. */
    public const AREA = 'community';

    /** Erlaubte Typen; Reihenfolge = `Settings::ATTACHMENT_TYPES`. `image` = erwarteter `IMAGETYPE_*`, `null` = kein Bild. */
    public const TYPES = [
        'jpg' => ['mime' => ['image/jpeg'], 'store' => 'image/jpeg', 'image' => IMAGETYPE_JPEG],
        'png' => ['mime' => ['image/png'], 'store' => 'image/png', 'image' => IMAGETYPE_PNG],
        'webp' => ['mime' => ['image/webp'], 'store' => 'image/webp', 'image' => IMAGETYPE_WEBP],
        'gif' => ['mime' => ['image/gif'], 'store' => 'image/gif', 'image' => IMAGETYPE_GIF],
        'pdf' => ['mime' => ['application/pdf'], 'store' => 'application/pdf', 'image' => null],
        'zip' => ['mime' => ['application/zip', 'application/x-zip-compressed'], 'store' => 'application/zip', 'image' => null],
        'txt' => ['mime' => ['text/plain'], 'store' => 'text/plain', 'image' => null],
    ];

    /** Andere Schreibweisen einer Endung. */
    public const ALIASES = ['jpeg' => 'jpg', 'jpe' => 'jpg'];

    /** Inhaltstypen, die nie angenommen werden (wie `FormUploadService`). */
    public const DANGEROUS_MIME = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/javascript', 'text/javascript',
        'application/x-php', 'text/x-php', 'application/x-httpd-php', 'application/x-msdownload',
        'application/x-dosexec', 'application/x-executable', 'application/x-sharedlib', 'application/x-sh',
        'text/x-shellscript', 'application/java-archive', 'application/x-mach-binary', 'text/xml', 'application/xml',
    ];

    /** Endungen, die auch als Innenteil eines Namens abgelehnt werden (`shell.php.jpg`). */
    public const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'shtml', 'htaccess', 'htpasswd', 'html',
        'htm', 'xhtml', 'svg', 'svgz', 'xml', 'js', 'mjs', 'exe', 'dll', 'msi', 'bat', 'cmd', 'com', 'scr', 'sh', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp',
        'jar', 'vbs', 'wsf', 'hta',
    ];

    /** Technische Obergrenze je Beitrag (zusätzlich `trust_limits.attachments_per_post`). */
    public const MAX_PER_POST = 10;
    /** Verwaiste Uploads je Mitglied in 24 h. */
    public const MAX_PENDING_PER_DAY = 30;
    /** Längste Kante nach dem Verkleinern. */
    public const MAX_EDGE = 2560;
    /** Breite × Höhe vor dem Dekodieren. */
    public const MAX_PIXELS = 25000000;
    /** Längste Kante der Vorschau. */
    public const PREVIEW_EDGE = 480;
    /** Alter verwaister Anhänge und Zwischendateien, ab dem `purgeOrphans()` sie entfernt. */
    public const ORPHAN_SECONDS = 86400;
    public const JPEG_QUALITY = 85;
    public const WEBP_QUALITY = 82;
    public const PNG_LEVEL = 6;
    /** Form von `storage_path` (`YYYY/MM/<32 hex>.<ext>`). */
    public const STORAGE_PATTERN = '#^\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9]{1,10}$#';

    /**
     * EXIF-Orientierung → GD-Schritte auf der Leinwand (`imagerotate` dreht gegen den Uhrzeigersinn; `h`/`v` → `IMG_FLIP_*` erst zur
     * Laufzeit, weil die GD-Konstanten ohne ext-gd fehlen). 5 bis 8 tauschen Breite und Höhe.
     */
    public const ORIENTATION = [
        1 => [],
        2 => [['flip', 'h']],
        3 => [['rotate', 180]],
        4 => [['flip', 'v']],
        5 => [['rotate', 270], ['flip', 'h']],
        6 => [['rotate', 270]],
        7 => [['rotate', 90], ['flip', 'h']],
        8 => [['rotate', 90]],
    ];

    /** Dekodier-Slots je Server (Sperrdateien `community/.gd-1.lock`, `.gd-2.lock`). */
    public const DECODE_SLOTS = 2;
    /** Wartezeit auf einen Slot in ms (neuer Versuch alle 100 ms). */
    public const DECODE_WAIT_MS = 5000;
    /** Zuschlag der Speicherschätzung in Byte (16 MiB). */
    public const DECODE_RESERVE = 16777216;
    /** Speicher je Slot in Byte (320 MiB); Server: DECODE_SLOTS × DECODE_BUDGET. */
    public const DECODE_BUDGET = 335544320;
    /** Höchstzahl der SOS-Marker eines JPEG (libjpeg schreibt höchstens 18 bei CMYK, 10 bei YCbCr). */
    public const MAX_JPEG_SCANS = 32;
    /** Decoderpuffer in Byte je Pixel (gemessen; Vertrag §4.6 Nr. 3). */
    public const DECODE_EXTRA = ['jpg' => 0, 'png' => 4, 'gif' => 1, 'webp' => 8];

    /** Kopf eines Nicht-Bildes (1024 Byte), der abgelehnt wird. */
    private const HEAD_PATTERN = '/<\?php|<script|<html|<svg|<!doctype/i';
    /** Formular-Kennung und Dateiname: 32 Hex-Zeichen. */
    private const HEX_PATTERN = '/^[a-f0-9]{32}$/';
    /** Zwischendateien im Monatsordner. */
    private const TEMP_PATTERN = '/^[a-f0-9]{32}(\.preview)?\.tmp$/';
    /** Lesestück der Dateiprüfung (1 MiB). */
    private const SCAN_CHUNK = 1048576;
    /** Abstand der Versuche auf einen Dekodier-Slot in ms. */
    private const SLOT_RETRY_MS = 100;
    /** Wartezeit bis zum nächsten Versuch bei belegten Slots (Sekunden). */
    private const SLOT_RETRY_AFTER = 5;
    private const MAX_ID = 4294967295;
    private const FILE_MODE = 0640;
    /** Größte Zahl der Zuordnungen in einer `IN`-Liste der Planung (aufgeführt + eingebettet + entfernt). */
    private const PLAN_MAX_IDS = 100;

    private static ?self $instance = null;

    private Database $db;
    private Settings $settings;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(?int, string, array<string, mixed>): AccessDecision */
    private \Closure $can;
    /** @var \Closure(int): array<string, int> */
    private \Closure $limits;
    /** @var \Closure(string, ?int): array{allowed: bool, retry_after: int} */
    private \Closure $throttle;
    /** @var \Closure(string): string */
    private \Closure $directory;
    /** @var \Closure(string): ?string */
    private \Closure $locate;
    /** @var \Closure(string): bool */
    private \Closure $isUploaded;
    /** @var \Closure(string, string): bool */
    private \Closure $moveUploaded;
    /** @var \Closure(string): string */
    private \Closure $mime;
    /** @var \Closure(): string */
    private \Closure $random;
    /** @var \Closure(): ?\Closure */
    private \Closure $slot;
    /** @var \Closure(): int */
    private \Closure $headroom;
    private AttachmentRepository $repo;
    private UserStatsRepository $stats;
    private PostRepository $posts;
    private TopicRepository $topics;

    /**
     * @param \Closure(): int|null $clock Unix-Zeit (Standard `time()`)
     * @param \Closure(?int, string, array<string, mixed>): AccessDecision|null $can Standard `CommunityAccess::instance()->can()`; ohne Klasse abgelehnt
     * @param \Closure(int): array<string, int>|null $limits Standard `CommunityAccess::instance()->limitsFor()` (`-1` unbegrenzt, `0` keine)
     * @param \Closure(string, ?int): array{allowed: bool, retry_after: int}|null $throttle Standard `ContentGuard::instance()->throttle()`
     * @param \Closure(string): string|null $directory Monatsordner `YYYY/MM` → absoluter Ordner (Standard `PrivateStorageService::path()`)
     * @param \Closure(string): ?string|null $locate relativer Pfad → absoluter Pfad oder null (Standard `PrivateStorageService::resolve()`)
     * @param \Closure(string): bool|null $isUploaded Standard `is_uploaded_file()`
     * @param \Closure(string, string): bool|null $moveUploaded Standard `move_uploaded_file()`
     * @param \Closure(string): string|null $mime Inhaltstyp aus `finfo`; '' = unbekannt (abgelehnt)
     * @param \Closure(): string|null $random 32 Hex-Zeichen (Dateiname, Formular-Kennung)
     * @param \Closure(): ?\Closure|null $slot Dekodier-Slot: Freigabe oder null (kein Slot frei)
     * @param \Closure(): int|null $headroom Bytes für eine Dekodierung (Standard `budget()` aus GD-Version, `memory_limit`, Verbrauch)
     */
    public function __construct(
        ?Database $db = null,
        ?Settings $settings = null,
        ?\Closure $clock = null,
        ?\Closure $can = null,
        ?\Closure $limits = null,
        ?\Closure $throttle = null,
        ?\Closure $directory = null,
        ?\Closure $locate = null,
        ?\Closure $isUploaded = null,
        ?\Closure $moveUploaded = null,
        ?\Closure $mime = null,
        ?\Closure $random = null,
        ?\Closure $slot = null,
        ?\Closure $headroom = null
    ) {
        $this->db = $db ?? Database::instance();
        $this->settings = $settings ?? Settings::getInstance();
        $this->clock = $clock ?? static fn (): int => time();
        $this->can = $can ?? static function (?int $userId, string $action, array $context): AccessDecision {
            return class_exists(CommunityAccess::class)
                ? CommunityAccess::instance()->can($userId, $action, $context)
                : AccessDecision::deny('no_permission');
        };
        $this->limits = $limits ?? static fn (int $userId): array => CommunityAccess::instance()->limitsFor($userId);
        // Ohne ContentGuard (Einheit S) bleibt der Upload zu (fail-closed).
        $this->throttle = $throttle ?? static function (string $action, ?int $userId): array {
            return class_exists(ContentGuard::class)
                ? ContentGuard::instance()->throttle($action, $userId)
                : ['allowed' => false, 'retry_after' => 60];
        };
        $this->directory = $directory ?? static fn (string $relDir): string => PrivateStorageService::getInstance()->path(self::AREA . '/' . $relDir);
        $this->locate = $locate ?? static fn (string $relPath): ?string => PrivateStorageService::getInstance()->resolve(self::AREA, $relPath);
        $this->isUploaded = $isUploaded ?? static fn (string $tmp): bool => is_uploaded_file($tmp);
        $this->moveUploaded = $moveUploaded ?? static fn (string $tmp, string $dest): bool => move_uploaded_file($tmp, $dest);
        $this->mime = $mime ?? static function (string $path): string {
            if (!class_exists(\finfo::class)) {
                return '';
            }
            $type = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

            return is_string($type) ? strtolower($type) : '';
        };
        $this->random = $random ?? static fn (): string => bin2hex(random_bytes(16));
        $this->slot = $slot ?? static fn (): ?\Closure => self::acquireSlot(PrivateStorageService::getInstance()->path(self::AREA));
        $this->headroom = $headroom ?? static fn (): int => self::budget(
            function_exists('gd_info') ? (string) (gd_info()['GD Version'] ?? '') : '',
            self::iniBytes((string) ini_get('memory_limit')),
            memory_get_usage()
        );
        $this->repo = new AttachmentRepository($this->db);
        $this->stats = new UserStatsRepository($this->db);
        $this->posts = new PostRepository($this->db);
        $this->topics = new TopicRepository($this->db);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Neue Formular-Kennung (32 Hex-Zeichen; PR #4). */
    public function newToken(): string
    {
        $token = (string) ($this->random)();

        return preg_match(self::HEX_PATTERN, $token) === 1 ? $token : bin2hex(random_bytes(16));
    }

    // ── Hochladen ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Eine Datei hochladen (ein Aufruf je Datei, Autocommit bis auf die Zeile). `$target`: genau einer der Schlüssel `forum_id`,
     * `topic_id`, `post_id` (int ≥ 1); `$token`: Formular-Kennung (32 Hex). Ergebnis `{success: true, attachment: Info}` mit
     * `Info` = `{id, name, size, mime, extension, is_image, width, height, has_preview, animated}`.
     *
     * @param array<string, mixed> $file Eintrag aus `$_FILES` (Strings und Ganzzahlen, keine Listen)
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    public function upload(int $userId, array $file, array $target, string $token): array
    {
        // 1. Eingaben (ohne Datenbank)
        $targetKey = self::targetKey($target);
        if ($userId < 1 || $targetKey === null || preg_match(self::HEX_PATTERN, $token) !== 1
            || !is_string($file['name'] ?? null) || !is_string($file['tmp_name'] ?? null) || !is_int($file['error'] ?? null) || !is_int($file['size'] ?? null)) {
            return self::failure('invalid_input');
        }
        $uploadError = $file['error'];
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            return self::failure('attachment_too_large');
        }
        if ($uploadError === UPLOAD_ERR_NO_TMP_DIR || $uploadError === UPLOAD_ERR_CANT_WRITE) {
            return self::failure('storage_error');
        }
        if ($uploadError !== UPLOAD_ERR_OK) {
            return self::failure('attachment_invalid');
        }

        // 2. Name und Endung (rein)
        $named = $this->checkName($file['name']);
        if (is_string($named)) {
            return self::failure($named);
        }
        [$name, $extension] = $named;
        $imageType = self::TYPES[$extension]['image'];

        // 3. Uhr
        $nowTs = ($this->clock)();
        $now = gmdate('Y-m-d H:i:s', $nowTs);

        // 4. Rechte (beim Bearbeiten zusätzlich Urheberschaft, Fenster, Zustand)
        $decision = ($this->can)($userId, 'upload', $target + ['now' => $nowTs]);
        if (!$decision->allowed) {
            return self::denied($decision);
        }
        if ($targetKey === 'post_id') {
            $postId = (int) $target['post_id'];
            $edit = ($this->can)($userId, 'edit', ['post_id' => $postId, 'now' => $nowTs]);
            if (!$edit->allowed) {
                return self::denied($edit);
            }
        }

        // 5. Request-Grenze
        $gate = ($this->throttle)('upload', $userId);
        if (!($gate['allowed'] ?? false)) {
            return self::failure('rate_limited', ['retry_after' => max(1, (int) ($gate['retry_after'] ?? 60))]);
        }

        // 6. Datei
        $tmp = $file['tmp_name'];
        if ($tmp === '' || !($this->isUploaded)($tmp)) {
            return self::failure('attachment_invalid');
        }
        clearstatcache(true, $tmp);
        $size = @filesize($tmp);
        if ($size === false || $size < 1) {
            return self::failure('attachment_invalid');
        }
        if ($size > self::effectiveMaxBytes($this->settings->int('attachment_max_kb'))) {
            return self::failure('attachment_too_large');
        }

        // 7. Inhalt (fail-closed)
        try {
            $mime = strtolower((string) ($this->mime)($tmp));
        } catch (\Throwable) {
            $mime = '';
        }
        if ($mime === '' || in_array($mime, self::DANGEROUS_MIME, true) || !in_array($mime, self::TYPES[$extension]['mime'], true)) {
            return self::failure('attachment_type');
        }
        if ($imageType === null) {
            $head = @file_get_contents($tmp, false, null, 0, 1024);
            if (!is_string($head)) {
                return self::failure('attachment_invalid');
            }
            if (preg_match(self::HEAD_PATTERN, $head) === 1) {
                return self::failure('attachment_type');
            }
        }

        // 8. Kontingente (Vorprüfung ohne Sperre; verbindlich unter der Statistiksperre in Schritt 11)
        try {
            $limits = ($this->limits)($userId);
            $quota = $this->quotaError($userId, $token, $limits, $nowTs);
        } catch (\Throwable $e) {
            self::warn('upload', $e);

            return self::failure('db_error');
        }
        if ($quota !== null) {
            return self::failure($quota);
        }

        // 9. Ablage als Zwischendateien
        $hex = (string) ($this->random)();
        if (preg_match(self::HEX_PATTERN, $hex) !== 1) {
            return self::failure('storage_error');
        }
        $relDir = gmdate('Y/m', $nowTs);
        try {
            $dir = self::dirPath(($this->directory)(gmdate('Y/m', $nowTs)));
        } catch (\Throwable $e) {
            self::warn('upload', $e);

            return self::failure('storage_error');
        }
        $files = [
            'tmp' => $dir . $hex . '.tmp',
            'tmp_preview' => $dir . $hex . '.preview.tmp',
            'final' => $dir . $hex . '.' . $extension,
            'final_preview' => $dir . $hex . '.preview.' . $extension,
        ];
        $committed = false;
        try {
            if ($imageType !== null) {
                $image = $this->processImage($tmp, $extension, $imageType, $files['tmp'], $files['tmp_preview']);
                if (isset($image['error'])) {
                    return self::failure((string) $image['error'], isset($image['retry_after']) ? ['retry_after' => (int) $image['retry_after']] : []);
                }
            } else {
                if (!($this->moveUploaded)($tmp, $files['tmp'])) {
                    return self::failure('storage_error');
                }
                $image = ['width' => null, 'height' => null, 'has_preview' => 0, 'animated' => false];
            }
            foreach (['tmp', 'tmp_preview'] as $key) {
                if (is_file($files[$key])) {
                    @chmod($files[$key], self::FILE_MODE);
                }
            }

            // 10. Prüfsumme, Größe und Maße aus den Zwischendateien
            clearstatcache(true, $files['tmp']);
            $sha = @hash_file('sha256', $files['tmp']);
            $fileSize = @filesize($files['tmp']);
            if (!is_string($sha) || $fileSize === false || $fileSize < 1) {
                return self::failure('storage_error');
            }

            // 11. Zeile unter der Statistiksperre des Uploaders (Kontingente exakt)
            $row = [
                'post_id' => null,
                'user_id' => $userId,
                'storage_path' => $relDir . '/' . $hex . '.' . $extension,
                'original_name' => $name,
                'mime_type' => self::TYPES[$extension]['store'],
                'extension' => $extension,
                'file_size' => $fileSize,
                'sha256' => $sha,
                'width' => $image['width'],
                'height' => $image['height'],
                'has_preview' => (int) $image['has_preview'],
                'upload_token' => $token,
                'created_at' => $now,
            ];
            try {
                $this->stats->ensure($userId, $now);
                $stored = Transaction::run($this->db, function () use ($userId, $token, $limits, $nowTs, $now, $row): array {
                    $this->stats->lockForUpdate($userId, $now);
                    $quota = $this->quotaError($userId, $token, $limits, $nowTs);
                    if ($quota !== null) {
                        return ['error' => $quota];
                    }

                    return ['id' => $this->repo->insert($row)];
                });
            } catch (\Throwable $e) {
                self::warn('upload', $e);

                return self::failure('db_error');
            }
            if (isset($stored['error'])) {
                return self::failure((string) $stored['error']);
            }
            $committed = true;
            $id = (int) $stored['id'];

            // 12. Nach dem Commit: endgültige Namen
            $moved = @rename($files['tmp'], $files['final'])
                && ((int) $image['has_preview'] !== 1 || @rename($files['tmp_preview'], $files['final_preview']));
            if (!$moved) {
                try {
                    $this->repo->deletePending($id, $userId);
                } catch (\Throwable $e) {
                    self::warn('upload', $e);
                }
                self::unlinkAll([$files['final'], $files['final_preview']]);
                $committed = false;

                return self::failure('storage_error');
            }

            return ['success' => true, 'attachment' => [
                'id' => $id,
                'name' => $name,
                'size' => $fileSize,
                'mime' => self::TYPES[$extension]['store'],
                'extension' => $extension,
                'is_image' => $imageType !== null,
                'width' => $image['width'],
                'height' => $image['height'],
                'has_preview' => (int) $image['has_preview'] === 1,
                'animated' => (bool) $image['animated'],
            ]];
        } catch (\Throwable $e) {
            self::warn('upload', $e);

            return self::failure('storage_error');
        } finally {
            if (!$committed) {
                self::unlinkAll([$files['tmp'], $files['tmp_preview']]);
            }
        }
    }

    /**
     * Eigenen, noch nicht zugeordneten Anhang verwerfen (Autocommit, kein `can()`): Zeile zuerst, dann Dateien. Ein Fehler beim
     * Löschen der Dateien wird protokolliert, der Erfolg bleibt.
     *
     * @return array<string, mixed>
     */
    public function discard(int $userId, int $attachmentId): array
    {
        if ($userId < 1 || $attachmentId < 1 || $attachmentId > self::MAX_ID) {
            return self::failure('invalid_input');
        }
        try {
            $row = $this->repo->find($attachmentId);
            if ($row === null || $row['post_id'] !== null || $row['user_id'] !== $userId || $this->repo->deletePending($attachmentId, $userId) !== 1) {
                return self::failure('attachment_not_found');
            }
        } catch (\Throwable $e) {
            self::warn('discard', $e);

            return self::failure('db_error');
        }
        $this->removeFiles($row);

        return ['success' => true, 'id' => $attachmentId];
    }

    /**
     * Eigene, noch nicht zugeordnete Anhänge eines Formulars (`[]` bei ungültiger Kennung oder Fehler).
     *
     * @return list<array<string, mixed>>
     */
    public function pendingForForm(int $userId, string $token): array
    {
        if ($userId < 1 || preg_match(self::HEX_PATTERN, $token) !== 1) {
            return [];
        }
        try {
            return array_map(self::info(...), $this->repo->pendingForForm($userId, $token, self::MAX_PER_POST));
        } catch (\Throwable $e) {
            self::warn('pendingForForm', $e);

            return [];
        }
    }

    /**
     * Anhänge je Beitrag für Einträge aus `PostService::listPage()` (nur `view = full`), eine Abfrage je 100 Beiträge.
     *
     * @param list<array<string, mixed>> $items
     * @return array<int, list<array<string, mixed>>>
     */
    public function listFor(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (is_array($item) && ($item['view'] ?? null) === 'full' && isset($item['id']) && (int) $item['id'] > 0) {
                $ids[(int) $item['id']] = (int) $item['id'];
            }
        }
        if ($ids === []) {
            return [];
        }
        try {
            $result = [];
            foreach ($this->repo->forPosts(array_values($ids)) as $postId => $rows) {
                $result[$postId] = array_map(self::info(...), $rows);
            }

            return $result;
        } catch (\Throwable $e) {
            self::warn('listFor', $e);

            return [];
        }
    }

    /**
     * Auflösen für die Auslieferung (PR #4). Alles, was der Betrachter nicht sehen darf, ergibt `not_found` (auch Modul aus): ein
     * verwaister Anhang nur für den Eigentümer, sonst `can('read')` des Beitrags mit voller Ansicht. `public` = Gäste dürfen ihn sehen
     * (steuert nur `no-store`; `Cache-Control` ist immer `private`, §4.6 Nr. 9).
     *
     * @return array<string, mixed>
     */
    public function resolve(?int $viewerId, int $attachmentId, bool $preview = false): array
    {
        $viewerId = $viewerId !== null && $viewerId > 0 ? $viewerId : null;
        if ($attachmentId < 1 || $attachmentId > self::MAX_ID) {
            return self::failure('not_found');
        }
        $nowTs = ($this->clock)();
        try {
            $row = $this->repo->find($attachmentId);
            if ($row === null) {
                return self::failure('not_found');
            }
            $extension = (string) $row['extension'];
            if (!isset(self::TYPES[$extension]) || preg_match(self::STORAGE_PATTERN, (string) $row['storage_path']) !== 1) {
                self::notice('resolve', 'Anhang ' . $attachmentId . ': ungültiger Ablagepfad');

                return self::failure('not_found');
            }
            if ($row['post_id'] === null) {
                if ($viewerId === null || $viewerId !== (int) $row['user_id']) {
                    return self::failure('not_found');
                }
                if (!($this->can)($viewerId, 'read', ['scope' => 'global', 'now' => $nowTs])->allowed) {
                    return self::failure('not_found');
                }
                $public = false;
            } else {
                $context = ['post_id' => (int) $row['post_id'], 'now' => $nowTs];
                $decision = ($this->can)($viewerId, 'read', $context);
                if (!$decision->allowed || $decision->view !== 'full') {
                    return self::failure('not_found');
                }
                $guest = $viewerId === null ? $decision : ($this->can)(null, 'read', $context);
                $public = $guest->allowed && $guest->view === 'full';
            }
            $isImage = self::TYPES[$extension]['image'] !== null;
            if ($preview && !$isImage) {
                return self::failure('not_found');
            }
            $usePreview = $preview && (int) $row['has_preview'] === 1;
            $relative = $usePreview ? self::previewPath((string) $row['storage_path']) : (string) $row['storage_path'];
            $path = ($this->locate)($relative);
            if ($path === null) {
                self::notice('resolve', 'Anhang ' . $attachmentId . ': Datei fehlt (' . $relative . ')');

                return self::failure('not_found');
            }
        } catch (\PDOException $e) {
            self::warn('resolve', $e);

            return self::failure('db_error');
        } catch (\Throwable $e) {
            self::warn('resolve', $e);

            return self::failure('not_found');
        }

        return [
            'success' => true,
            'path' => $path,
            'mime' => self::TYPES[$extension]['store'],
            'filename' => (string) $row['original_name'],
            'size' => $usePreview ? (int) @filesize($path) : (int) $row['file_size'],
            'sha256' => $usePreview ? (string) @hash_file('sha256', $path) : (string) $row['sha256'],
            'inline' => self::TYPES[$extension]['image'] !== null,
            'public' => $public,
            'post_id' => $row['post_id'],
            'preview' => $usePreview,
        ];
    }

    /** Einen Abruf zählen (Controller in PR #4 nur bei `inline === false` und Erstabruf). `false` bei Fehler oder fehlender Zeile. */
    public function countDownload(int $attachmentId): bool
    {
        if ($attachmentId < 1 || $attachmentId > self::MAX_ID) {
            return false;
        }
        try {
            return $this->repo->bumpDownloads($attachmentId);
        } catch (\Throwable $e) {
            self::warn('countDownload', $e);

            return false;
        }
    }

    /**
     * Aufräumen (PR #5, Regel `community.orphan_uploads`): verwaiste Anhänge älter als ORPHAN_SECONDS, je Zeile zuerst die Zeile (nur
     * wenn noch verwaist), dann die Dateien; danach höchstens `$limit` Zwischendateien (`*.tmp`) des laufenden und des vorigen Monats,
     * die älter als ORPHAN_SECONDS sind (ohne Datenbank). Liefert die Zahl entfernter Anhänge; wirft nie.
     */
    public function purgeOrphans(int $limit = 200): int
    {
        $limit = max(1, min(1000, $limit));
        $nowTs = ($this->clock)();
        $cutoff = gmdate('Y-m-d H:i:s', $nowTs - self::ORPHAN_SECONDS);
        $removed = 0;
        try {
            foreach ($this->repo->orphansBefore($cutoff, $limit) as $row) {
                if ($this->repo->deletePending($row['id']) !== 1) {
                    continue;
                }
                $removed++;
                $this->removeFiles($row);
            }
        } catch (\Throwable $e) {
            self::warn('purgeOrphans', $e);
        }
        try {
            foreach ($this->staleTemporaries($nowTs, $limit) as $file) {
                if (!@unlink($file) && is_file($file)) {
                    self::notice('purgeOrphans', 'Zwischendatei nicht gelöscht: ' . $file);
                }
            }
        } catch (\Throwable $e) {
            self::warn('purgeOrphans', $e);
        }

        return $removed;
    }

    // ── Zuordnung beim Absenden (TopicService, PostService) ───────────────────────────────────────────────────────────────

    /**
     * IDs aus dem Formular: eindeutig, aufsteigend, je 1 bis 4294967295. `null` = ungültig (kein Array, keine Ganzzahl).
     *
     * @return list<int>|null
     */
    public static function claimIds(mixed $listed): ?array
    {
        if (!is_array($listed)) {
            return null;
        }
        $ids = [];
        foreach ($listed as $value) {
            if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', $value) === 1) {
                $value = (int) $value;
            }
            if (!is_int($value) || $value < 1 || $value > self::MAX_ID) {
                return null;
            }
            $ids[$value] = $value;
        }
        sort($ids);

        return array_values($ids);
    }

    /**
     * Leerer Plan (Dienste ohne Anhänge).
     *
     * @return array{ids: list<int>, remove: list<int>, lock: list<int>, existing: int, after: int, files: list<array{storage_path: string, extension: string, has_preview: int}>}
     */
    public static function emptyPlan(): array
    {
        return ['ids' => [], 'remove' => [], 'lock' => [], 'existing' => 0, 'after' => 0, 'files' => []];
    }

    /**
     * Plan der Zuordnung vor der Transaktion (Autocommit, eine `findMany` und beim Bearbeiten `countForPost`).
     * - aufgeführte IDs: müssen existieren und dem Mitglied gehören (sonst `attachment_not_found`); beim Bearbeiten werden Anhänge, die
     *   schon zu diesem Beitrag gehören, übergangen. `post_id` wird hier nicht geprüft, damit ein doppeltes Absenden zur Dublette gelangt.
     * - eingebettete IDs (`stats.attachments` des Renderers): nur eigene, noch nicht zugeordnete.
     * - zu entfernende IDs: müssen zu `$postId` gehören.
     * `after` = vorhandene − entfernte + neue; über MAX_PER_POST ⇒ `attachment_limit`.
     *
     * @param list<int> $listed
     * @param list<int> $referenced
     * @param list<int> $remove
     * @return array{ok: true, plan: array<string, mixed>}|array{ok: false, error: string}
     */
    public function prepareClaim(int $userId, array $listed, array $referenced, ?int $postId = null, array $remove = []): array
    {
        $listed = self::cleanIds($listed);
        $referenced = self::cleanIds($referenced);
        $remove = self::cleanIds($remove);
        $plan = self::emptyPlan();
        if (count($listed) + count($referenced) + count($remove) > self::PLAN_MAX_IDS) {
            return ['ok' => false, 'error' => 'attachment_limit'];
        }
        try {
            $plan['existing'] = $postId !== null ? $this->repo->countForPost($postId) : 0;
            $all = array_values(array_unique([...$listed, ...$referenced, ...$remove]));
            $rows = $all === [] ? [] : $this->repo->findMany($all);
        } catch (\Throwable $e) {
            self::warn('prepareClaim', $e);

            return ['ok' => false, 'error' => 'db_error'];
        }
        $ids = [];
        foreach ($listed as $id) {
            $row = $rows[$id] ?? null;
            if ($postId !== null && $row !== null && $row['post_id'] === $postId) {
                continue;
            }
            if ($row === null || (int) $row['user_id'] !== $userId) {
                return ['ok' => false, 'error' => 'attachment_not_found'];
            }
            $ids[$id] = $id;
        }
        foreach ($referenced as $id) {
            $row = $rows[$id] ?? null;
            if ($row !== null && $row['post_id'] === null && $row['user_id'] === $userId) {
                $ids[$id] = $id;
            }
        }
        foreach ($remove as $id) {
            $row = $rows[$id] ?? null;
            if ($row === null || $postId === null || $row['post_id'] !== $postId) {
                return ['ok' => false, 'error' => 'attachment_not_found'];
            }
            unset($ids[$id]);
            $plan['files'][] = ['storage_path' => (string) $row['storage_path'], 'extension' => (string) $row['extension'], 'has_preview' => (int) $row['has_preview']];
        }
        sort($ids);
        $plan['ids'] = array_values($ids);
        $plan['remove'] = $remove;
        $lock = array_values(array_unique([...$plan['ids'], ...$remove]));
        sort($lock);
        $plan['lock'] = $lock;
        $plan['after'] = $plan['existing'] - count($remove) + count($plan['ids']);
        if ($plan['after'] > self::MAX_PER_POST) {
            return ['ok' => false, 'error' => 'attachment_limit'];
        }

        return ['ok' => true, 'plan' => $plan];
    }

    /**
     * Sperrt die Anhänge des Plans (Ebene 5, nach der Statistik; PRIMARY, aufsteigend). `[]` bei leerem Plan. Wirft `\PDOException`.
     *
     * @param array<string, mixed> $plan
     * @return array<int, array<string, mixed>>
     */
    public function lock(array $plan): array
    {
        $ids = (array) ($plan['lock'] ?? []);

        return $ids === [] ? [] : $this->repo->lockMany($ids);
    }

    /**
     * Prüfung unter der Sperre (rein): zuzuordnende Anhänge noch eigen und verwaist, zu entfernende noch an `$postId`, Obergrenze.
     * `null` = in Ordnung.
     *
     * @param array<string, mixed> $plan
     * @param array<int, array<string, mixed>> $locked
     */
    public static function verify(array $plan, array $locked, int $userId, ?int $postId): ?string
    {
        foreach ((array) ($plan['ids'] ?? []) as $id) {
            $row = $locked[$id] ?? null;
            if ($row === null || $row['post_id'] !== null || (int) $row['user_id'] !== $userId) {
                return 'attachment_not_found';
            }
        }
        foreach ((array) ($plan['remove'] ?? []) as $id) {
            $row = $locked[$id] ?? null;
            if ($row === null || $row['post_id'] !== $postId || $postId === null) {
                return 'attachment_not_found';
            }
        }

        return (int) ($plan['after'] ?? 0) > self::MAX_PER_POST ? 'attachment_limit' : null;
    }

    /**
     * Schreibt Zuordnung und Entfernungen (in der Transaktion, nach dem Schreiben des Beitrags). Eine abweichende Zeilenzahl wirft
     * `\RuntimeException` (Rücknahme; unter der Sperre unmöglich).
     *
     * @param array<string, mixed> $plan
     */
    public function apply(array $plan, int $postId, int $userId): void
    {
        $ids = (array) ($plan['ids'] ?? []);
        if ($ids !== []) {
            $claimed = $this->repo->claim($ids, $postId, $userId);
            if ($claimed !== count($ids)) {
                throw new \RuntimeException('Anhänge: ' . $claimed . ' von ' . count($ids) . ' zugeordnet.');
            }
        }
        foreach ((array) ($plan['remove'] ?? []) as $id) {
            if ($this->repo->deleteOfPost((int) $id, $postId) !== 1) {
                throw new \RuntimeException('Anhang ' . (int) $id . ' gehört nicht mehr zu Beitrag ' . $postId . '.');
            }
        }
    }

    /**
     * Pflegt `topics.has_attachments` nach Zuordnen und Entfernen (in der Transaktion, Thema gesperrt): ein veröffentlichter Beitrag mit
     * neuen Anhängen setzt 1; nach dem Entfernen bestimmt `topicHasPublishedAttachments()` das Kennzeichen neu (nur bei Abweichung).
     * Wartende Beiträge setzen nichts (die Freigabe bucht `CounterBook`).
     *
     * @param array<string, mixed> $topic Zeile oder `{id, has_attachments}`
     * @param array<string, mixed> $plan
     */
    public function syncTopicFlag(array $topic, bool $postPublished, array $plan, string $nowUtc): void
    {
        $topicId = (int) ($topic['id'] ?? 0);
        if ($topicId < 1) {
            return;
        }
        $flag = (int) ($topic['has_attachments'] ?? 0);
        if ($postPublished && $plan['ids'] !== [] && (int) $topic['has_attachments'] === 0) {
            $this->topics->update($topicId, ['has_attachments' => 1, 'updated_at' => $nowUtc]);

            return;
        }
        if ((array) ($plan['remove'] ?? []) !== [] && $flag === 1 && !($postPublished && (array) ($plan['ids'] ?? []) !== [])
            && !$this->posts->topicHasPublishedAttachments($topicId)) {
            $this->topics->update($topicId, ['has_attachments' => 0, 'updated_at' => $nowUtc]);
        }
    }

    /**
     * Nach dem Commit: Dateien entfernter Anhänge löschen (Fehler nur Protokoll mit Pfad).
     *
     * @param array<string, mixed> $plan
     */
    public function afterCommit(array $plan): void
    {
        foreach ((array) ($plan['files'] ?? []) as $file) {
            if (is_array($file)) {
                $this->removeFiles($file);
            }
        }
    }

    // ── Grenzen ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** Größte Dateigröße in Byte: `min(kb · 1024, upload_max_filesize, post_max_size)`; ini-Werte ≤ 0 gelten als ohne Grenze. */
    public static function effectiveMaxBytes(int $settingKb): int
    {
        $limits = [max(0, $settingKb) * 1024];
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $value = self::iniBytes((string) ini_get($key));
            if ($value > 0) {
                $limits[] = $value;
            }
        }

        return min($limits);
    }

    /**
     * Typen, die diese Installation verarbeiten kann: Nicht-Bilder immer, Bilder nur mit GD-Lesen und -Schreiben des Formats.
     *
     * @param \Closure(string): bool|null $functionExists Prüfung einer Funktion (Standard `function_exists`; Tests)
     * @return list<string>
     */
    public static function available(?\Closure $functionExists = null): array
    {
        $exists = $functionExists ?? static fn (string $function): bool => function_exists($function);
        $codecs = [
            'jpg' => ['imagecreatefromjpeg', 'imagejpeg'],
            'png' => ['imagecreatefrompng', 'imagepng'],
            'webp' => ['imagecreatefromwebp', 'imagewebp'],
            'gif' => ['imagecreatefromgif', 'imagegif'],
        ];
        $gd = $exists('imagecreatetruecolor') && $exists('imagecopyresampled');
        $types = [];
        foreach (self::TYPES as $extension => $type) {
            if ($type['image'] === null) {
                $types[] = $extension;
            } elseif ($gd && $exists($codecs[$extension][0]) && $exists($codecs[$extension][1])) {
                $types[] = $extension;
            }
        }

        return $types;
    }

    /**
     * Speicherbedarf einer Dekodierung samt Verkleinern und Vorschau (Vertrag §4.6 Nr. 3):
     * `w·h·(4 + DECODE_EXTRA + Koeffizienten 2·channels) + 2·nw·nh·4 + pw·ph·4 + DECODE_RESERVE`; `nw`/`nh` auf MAX_EDGE,
     * `pw`/`ph` auf PREVIEW_EDGE skaliert, der Faktor 2 deckt Leinwand und gedrehte Leinwand.
     */
    public static function decodeNeed(int $width, int $height, string $extension, bool $coefficients, int $channels): int
    {
        $width = max(1, $width);
        $height = max(1, $height);
        $perPixel = 4 + self::DECODE_EXTRA[$extension] + ($coefficients ? 2 * max(1, $channels) : 0);
        [$nw, $nh] = self::fit($width, $height, self::MAX_EDGE);
        [$pw, $ph] = self::fit($nw, $nh, self::PREVIEW_EDGE);

        return $width * $height * $perPixel + 2 * $nw * $nh * 4 + $pw * $ph * 4 + self::DECODE_RESERVE;
    }

    /**
     * Speicher für eine Dekodierung: mit gebündelter GD (Puffer zählen gegen `memory_limit`) `min(DECODE_BUDGET, Limit − Verbrauch)`,
     * sonst (System-libgd, Puffer außerhalb von `memory_limit`; oder ohne Grenze) DECODE_BUDGET.
     */
    public static function budget(string $gdVersion, int $memoryLimit, int $usage): int
    {
        return str_contains($gdVersion, 'bundled') && $memoryLimit > 0 ? min(self::DECODE_BUDGET, $memoryLimit - $usage) : self::DECODE_BUDGET;
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Name ohne Pfad, Steuer-, Bidi- und Formatzeichen sowie die Endung; sonst ein Fehlercode.
     *
     * @return array{0: string, 1: string}|string
     */
    private function checkName(string $raw): array|string
    {
        $name = FormUploadService::cleanFilename($raw);
        $name = trim((string) preg_replace('/[\p{Cf}\x{2028}\x{2029}]/u', '', $name), " .\t");
        if ($name === '') {
            $name = 'datei';
        }
        $parts = explode('.', $name);
        if (count($parts) < 2) {
            return 'attachment_type';
        }
        $extension = strtolower(trim((string) end($parts)));
        $extension = self::ALIASES[$extension] ?? $extension;
        foreach (array_slice($parts, 1, -1) as $inner) {
            if (in_array(strtolower(trim($inner)), self::DANGEROUS_EXTENSIONS, true)) {
                return 'attachment_type';
            }
        }
        if (!isset(self::TYPES[$extension])) {
            return 'attachment_type';
        }
        $enabled = $this->settings->map('attachment_types');
        if (empty($enabled[$extension])) {
            return 'attachment_type';
        }
        if (!in_array($extension, self::available(), true)) {
            return 'attachment_type';
        }

        return [$name, $extension];
    }

    /**
     * Kontingente (Vorprüfung und erneut unter der Statistiksperre): Stufe ohne Anhänge oder Formular voll ⇒ `too_many_attachments`,
     * Tageskontingent ⇒ `trust_limit`, verwaiste Uploads in 24 h ⇒ `attachment_limit`. `null` = in Ordnung.
     *
     * @param array<string, mixed> $limits
     */
    private function quotaError(int $userId, string $token, array $limits, int $nowTs): ?string
    {
        $perPostLimit = (int) ($limits['attachments_per_post'] ?? 0);
        $perPost = $perPostLimit === -1 ? self::MAX_PER_POST : min($perPostLimit, self::MAX_PER_POST);
        if ($perPost <= 0 || (int) ($limits['attachments_per_day'] ?? 0) === 0) {
            return 'too_many_attachments';
        }
        if ($this->repo->countPendingToken($userId, $token) >= $perPost) {
            return 'too_many_attachments';
        }
        $since = gmdate('Y-m-d H:i:s', $nowTs - 86400);
        if ((int) $limits['attachments_per_day'] !== -1 && $this->repo->countUserSince($userId, $since) >= $limits['attachments_per_day']) {
            return 'trust_limit';
        }
        if ($this->repo->countPendingSince($userId, $since) >= self::MAX_PENDING_PER_DAY) {
            return 'attachment_limit';
        }

        return null;
    }

    /**
     * Bild prüfen, dekodieren und neu kodieren (Vertrag §4.6 Nr. 3): Maße und Typ, Pixelgrenze, Scans (JPEG), Speicherschätzung, Slot,
     * dann Dekodieren und Kodieren in die Zwischendateien. Ergebnis `{width, height, has_preview, animated}` oder `{error, retry_after?}`.
     *
     * @return array<string, mixed>
     */
    private function processImage(string $source, string $extension, int $imageType, string $target, string $previewTarget): array
    {
        $info = @getimagesize($source);
        if (!is_array($info) || (int) ($info[2] ?? 0) !== $imageType) {
            return ['error' => 'attachment_image'];
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width < 1 || $height < 1) {
            return ['error' => 'attachment_image'];
        }
        if ($width * $height > self::MAX_PIXELS) {
            return ['error' => 'attachment_dimensions'];
        }
        $scan = ['scans' => 0, 'coefficients' => false];
        if ($extension === 'jpg') {
            $scan = $this->jpegScan($source);
            if ($scan === null) {
                return ['error' => 'attachment_image'];
            }
            if ($scan['scans'] > self::MAX_JPEG_SCANS) {
                return ['error' => 'attachment_image'];
            }
        }
        $channels = (int) ($info['channels'] ?? 3);
        $channels = $channels > 0 ? $channels : 3;
        $free = ($this->headroom)();
        if ($free < self::decodeNeed($width, $height, $extension, $scan['coefficients'], $channels)) {
            return ['error' => 'attachment_dimensions'];
        }
        $release = ($this->slot)();
        if ($release === null) {
            return ['error' => 'rate_limited', 'retry_after' => self::SLOT_RETRY_AFTER];
        }
        try {
            return $this->recode($source, $extension, $width, $height, $target, $previewTarget);
        } finally {
            $release();
        }
    }

    /**
     * Dekodieren, auf MAX_EDGE verkleinern, auf der Leinwand drehen, kodieren, Vorschau. Nur die neu kodierten Dateien bleiben.
     *
     * @return array<string, mixed>
     */
    private function recode(string $tmp, string $extension, int $width, int $height, string $tmpTarget, string $tmpPreview): array
    {
        $orientation = $extension === 'jpg' ? self::exifOrientation($tmp) : 1;
        $animated = false;
        if ($extension === 'gif') {
            $frames = self::countInChunks($tmp, ["\x21\xF9\x04"]);
            $animated = $frames !== null && $frames["\x21\xF9\x04"] > 1;
        }
        $image = self::decode($tmp, $extension);
        if ($image === null) {
            return ['error' => 'attachment_image'];
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        self::prepareAlpha($image);
        if (max($width, $height) > self::MAX_EDGE) {
            [$nw, $nh] = self::fit(imagesx($image), imagesy($image), self::MAX_EDGE);
            $canvas = self::canvas($nw, $nh, $extension);
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $nw, $nh, imagesx($image), imagesy($image));
            unset($image);
            $image = $canvas;
        }
        foreach (self::ORIENTATION[$orientation] ?? [] as [$operation, $argument]) {
            if ($operation === 'rotate') {
                $rotated = imagerotate($image, (float) $argument, 0);
                if (!$rotated instanceof \GdImage) {
                    return ['error' => 'attachment_image'];
                }
                unset($image);
                $image = $rotated;
                self::prepareAlpha($image);
            } else {
                imageflip($image, $argument === 'h' ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
            }
        }
        if (!self::encode($image, $extension, $tmpTarget)) {
            return ['error' => 'storage_error'];
        }
        $finalWidth = imagesx($image);
        $finalHeight = imagesy($image);
        $hasPreview = 0;
        if (max($finalWidth, $finalHeight) > self::PREVIEW_EDGE) {
            [$pw, $ph] = self::fit($finalWidth, $finalHeight, self::PREVIEW_EDGE);
            $thumb = self::canvas($pw, $ph, $extension);
            imagecopyresampled($thumb, $image, 0, 0, 0, 0, $pw, $ph, $finalWidth, $finalHeight);
            unset($image);
            if (!self::encode($thumb, $extension, $tmpPreview)) {
                return ['error' => 'storage_error'];
            }
            unset($thumb);
            $hasPreview = 1;
        }

        return ['width' => $finalWidth, 'height' => $finalHeight, 'has_preview' => $hasPreview, 'animated' => $animated];
    }

    /**
     * Scans eines JPEG in Stücken zu 1 MiB (das letzte Byte eines Stücks wird mitgeführt): `scans` = Zahl der SOS-Marker `FF DA`,
     * `coefficients` = progressiv (`FF C2`, `FF CA`) oder mehr als ein Scan. Treffer in APP- und COM-Segmenten zählen mit (strenger).
     * `null` bei Lesefehler.
     *
     * @return array{scans: int, coefficients: bool}|null
     */
    private function jpegScan(string $path): ?array
    {
        $counts = self::countInChunks($path, ["\xFF\xDA", "\xFF\xC2", "\xFF\xCA"]);
        if ($counts === null) {
            return null;
        }
        $scans = $counts["\xFF\xDA"];

        return ['scans' => $scans, 'coefficients' => $counts["\xFF\xC2"] > 0 || $counts["\xFF\xCA"] > 0 || $scans > 1];
    }

    /**
     * Zählt Bytefolgen einer Datei in Stücken zu SCAN_CHUNK; die letzten `Länge − 1` Byte eines Stücks werden mitgeführt (die Folgen
     * überlappen sich nicht selbst, deshalb zählt kein Treffer doppelt). `null` bei Lesefehler.
     *
     * @param list<string> $needles
     * @return array<string, int>|null
     */
    private static function countInChunks(string $path, array $needles): ?array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $counts = array_fill_keys($needles, 0);
        $keep = max(array_map('strlen', $needles)) - 1;
        $carry = '';
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, self::SCAN_CHUNK);
                if ($chunk === false) {
                    return null;
                }
                if ($chunk === '') {
                    continue;
                }
                $data = $carry . $chunk;
                foreach ($needles as $needle) {
                    $counts[$needle] += substr_count($data, $needle);
                }
                $carry = $keep > 0 ? substr($data, -$keep) : '';
            }
        } finally {
            fclose($handle);
        }

        return $counts;
    }

    private static function decode(string $path, string $extension): ?\GdImage
    {
        set_error_handler(static fn (): bool => true);
        try {
            $image = match ($extension) {
                'jpg' => imagecreatefromjpeg($path),
                'png' => imagecreatefrompng($path),
                'webp' => imagecreatefromwebp($path),
                'gif' => imagecreatefromgif($path),
                default => false,
            };
        } finally {
            restore_error_handler();
        }

        return $image instanceof \GdImage ? $image : null;
    }

    /** Schreibt das Bild im Format der Endung; `false` bei Fehler oder leerer Datei. */
    private static function encode(\GdImage $image, string $extension, string $path): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            if ($extension === 'jpg') {
                imageinterlace($image, true);
            }
            $written = match ($extension) {
                'jpg' => imagejpeg($image, $path, self::JPEG_QUALITY),
                'png' => imagepng($image, $path, self::PNG_LEVEL),
                'webp' => imagewebp($image, $path, self::WEBP_QUALITY),
                'gif' => imagegif($image, $path),
                default => false,
            };
        } finally {
            restore_error_handler();
        }
        clearstatcache(true, $path);

        return $written === true && is_file($path) && (int) @filesize($path) > 0;
    }

    /** Einzige Stelle für Alphakanal: kein Mischen, Alpha speichern. */
    private static function prepareAlpha(\GdImage $image): void
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }

    /** Neue Leinwand; JPEG mit weißem Grund, sonst transparent. */
    private static function canvas(int $width, int $height, string $extension): \GdImage
    {
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));
        if (!$canvas instanceof \GdImage) {
            throw new \RuntimeException('Leinwand konnte nicht angelegt werden.');
        }
        self::prepareAlpha($canvas);
        $background = $extension === 'jpg' ? imagecolorallocate($canvas, 255, 255, 255) : imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, max(1, $width) - 1, max(1, $height) - 1, (int) $background);

        return $canvas;
    }

    /**
     * Maße auf die längste Kante `$edge` verkleinert (gerundet, mindestens 1); kleinere Bilder bleiben.
     *
     * @return array{0: int, 1: int}
     */
    private static function fit(int $width, int $height, int $edge): array
    {
        $longest = max($width, $height);
        if ($longest <= $edge) {
            return [$width, $height];
        }
        $scale = $edge / $longest;

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    /** EXIF-Orientierung 1 bis 8 (sonst 1). */
    private static function exifOrientation(string $path): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }
        set_error_handler(static fn (): bool => true);
        try {
            $data = exif_read_data($path);
        } catch (\Throwable) {
            $data = false;
        } finally {
            restore_error_handler();
        }
        $orientation = is_array($data) ? (int) ($data['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /**
     * Freier Dekodier-Slot (Standard von `$slot`): `flock(LOCK_EX | LOCK_NB)` auf `.gd-1.lock` bzw. `.gd-2.lock`, neuer Versuch alle
     * 100 ms bis DECODE_WAIT_MS; dann `null`. Ein abgebrochener Prozess gibt die Sperre über das Betriebssystem frei.
     */
    private static function acquireSlot(string $directory): ?\Closure
    {
        $directory = self::dirPath($directory);
        $tries = intdiv(self::DECODE_WAIT_MS, self::SLOT_RETRY_MS);
        for ($try = 0; $try <= $tries; $try++) {
            $opened = 0;
            for ($slot = 1; $slot <= self::DECODE_SLOTS; $slot++) {
                $handle = @fopen($directory . '.gd-' . $slot . '.lock', 'c');
                if ($handle === false) {
                    continue;
                }
                $opened++;
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    return static function () use ($handle): void {
                        flock($handle, LOCK_UN);
                        fclose($handle);
                    };
                }
                fclose($handle);
            }
            if ($opened === 0) {
                throw new \RuntimeException('Sperrdateien der Bilddekodierung nicht beschreibbar: ' . $directory);
            }
            if ($try < $tries) {
                usleep(self::SLOT_RETRY_MS * 1000);
            }
        }

        return null;
    }

    /**
     * Zwischendateien (`<hex>.tmp`, `<hex>.preview.tmp`) des laufenden und des vorigen Monats, älter als ORPHAN_SECONDS, höchstens `$limit`.
     *
     * @return list<string>
     */
    private function staleTemporaries(int $nowTs, int $limit): array
    {
        $cutoff = $nowTs - self::ORPHAN_SECONDS;
        $months = array_values(array_unique([gmdate('Y/m', $nowTs), gmdate('Y/m', $nowTs - (int) gmdate('j', $nowTs) * 86400)]));
        $found = [];
        foreach ($months as $month) {
            $dir = self::dirPath(($this->directory)($month));
            foreach (@scandir($dir) ?: [] as $entry) {
                if (preg_match(self::TEMP_PATTERN, (string) $entry) !== 1) {
                    continue;
                }
                $mtime = @filemtime($dir . $entry);
                if ($mtime !== false && $mtime < $cutoff) {
                    $found[] = $dir . $entry;
                    if (count($found) >= $limit) {
                        return $found;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Löscht Datei und Vorschau eines Anhangs über `locate()`; Fehler nur als Warnung mit Pfad (Abgleich der Dateien ohne Zeile: PR #5).
     *
     * @param array<string, mixed> $row `storage_path`, `has_preview`
     */
    private function removeFiles(array $row): void
    {
        $relative = (string) ($row['storage_path'] ?? '');
        if (preg_match(self::STORAGE_PATTERN, $relative) !== 1) {
            self::notice('removeFiles', 'ungültiger Ablagepfad: ' . $relative);

            return;
        }
        $paths = [$relative];
        if ((int) ($row['has_preview'] ?? 0) === 1) {
            $paths[] = self::previewPath($relative);
        }
        foreach ($paths as $path) {
            try {
                $absolute = ($this->locate)($path);
                if ($absolute !== null && !@unlink($absolute) && is_file($absolute)) {
                    self::notice('removeFiles', 'Datei nicht gelöscht: ' . $absolute);
                }
            } catch (\Throwable $e) {
                self::notice('removeFiles', 'Datei nicht gelöscht: ' . $path . ' (' . $e->getMessage() . ')');
            }
        }
    }

    /** `YYYY/MM/<hex>.<ext>` → `YYYY/MM/<hex>.preview.<ext>`. */
    private static function previewPath(string $relative): string
    {
        return (string) preg_replace('/\.([a-z0-9]{1,10})$/', '.preview.$1', $relative);
    }

    /** Ordner mit genau einem abschließenden `/`. */
    private static function dirPath(string $directory): string
    {
        return rtrim($directory, '/\\') . '/';
    }

    /**
     * Genau einer der Zielschlüssel mit Ganzzahl ≥ 1, sonst `null`.
     *
     * @param array<string, mixed> $target
     */
    private static function targetKey(array $target): ?string
    {
        if (count($target) !== 1) {
            return null;
        }
        $key = (string) array_key_first($target);
        $value = $target[$key];

        return in_array($key, ['forum_id', 'topic_id', 'post_id'], true) && is_int($value) && $value >= 1 && $value <= self::MAX_ID ? $key : null;
    }

    /**
     * Positive, eindeutige Ganzzahlen (Reihenfolge des ersten Auftretens).
     *
     * @param array<array-key, mixed> $ids
     * @return list<int>
     */
    private static function cleanIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_int($id) && $id >= 1 && $id <= self::MAX_ID) {
                $clean[$id] = $id;
            }
        }

        return array_values($clean);
    }

    /**
     * Anzeigeform einer Anhangszeile.
     *
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, size: int, mime: string, extension: string, is_image: bool, width: ?int, height: ?int, has_preview: bool, animated: bool}
     */
    private static function info(array $row): array
    {
        $extension = (string) ($row['extension'] ?? '');

        return [
            'id' => (int) $row['id'],
            'name' => (string) ($row['original_name'] ?? ''),
            'size' => (int) ($row['file_size'] ?? 0),
            'mime' => (string) ($row['mime_type'] ?? ''),
            'extension' => $extension,
            'is_image' => isset(self::TYPES[$extension]) && self::TYPES[$extension]['image'] !== null,
            'width' => isset($row['width']) ? (int) $row['width'] : null,
            'height' => isset($row['height']) ? (int) $row['height'] : null,
            'has_preview' => (int) ($row['has_preview'] ?? 0) === 1,
            'animated' => false,
        ];
    }

    /** Wert einer ini-Größe in Byte (`-1` = ohne Grenze). */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** @param list<string> $paths */
    private static function unlinkAll(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function failure(string $error, array $extra = []): array
    {
        return ['success' => false, 'error' => $error] + $extra;
    }

    /** @return array<string, mixed> */
    private static function denied(AccessDecision $decision): array
    {
        return self::failure($decision->reason, $decision->retryAfter !== null ? ['retry_after' => $decision->retryAfter] : []);
    }

    private static function warn(string $operation, \Throwable $e): void
    {
        Logger::instance()->withChannel('community')->warning('Community-Anhänge: {operation} fehlgeschlagen: {exception}', [
            'operation' => $operation,
            'exception' => $e,
        ]);
    }

    private static function notice(string $operation, string $message): void
    {
        Logger::instance()->withChannel('community')->warning('Community-Anhänge: {operation}: {message}', [
            'operation' => $operation,
            'message' => $message,
        ]);
    }
}
