<?php
/**
 * SEO Indexing-Service für IndexNow und Google URL Notifications.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Http\Client as HttpClient;
use CMS\Logger;
use CMS\VendorRegistry;
use Melbahja\Seo\Indexing\GoogleIndexer;
use Melbahja\Seo\Indexing\URLIndexingType;

if (!defined('ABSPATH')) {
    exit;
}

VendorRegistry::instance()->loadPackage('melbahja-seo');

final class IndexingService
{
    private static ?self $instance = null;
    private const MAX_INDEXNOW_KEY_FILE_SIZE = 4096;

    /**
     * Gemeinsamer IndexNow-Endpunkt. Teilnehmende Suchmaschinen teilen empfangene URLs
     * untereinander, eine Meldung pro URL-Gruppe genügt daher (https://www.indexnow.org/documentation).
     */
    public const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';

    /** Maximale Anzahl URLs je IndexNow-POST laut Protokoll. */
    public const INDEXNOW_MAX_URLS_PER_REQUEST = 10000;

    /** Protokoll: 8–128 Zeichen, nur a-z, A-Z, 0-9 und Bindestrich. */
    public const INDEXNOW_KEY_PATTERN = '/^[A-Za-z0-9-]{8,128}$/';
    public const INDEXNOW_KEY_MIN_LENGTH = 8;
    public const INDEXNOW_KEY_MAX_LENGTH = 128;
    public const INDEXNOW_KEY_DEFAULT_LENGTH = 32;

    /** Antwortcodes des IndexNow-Protokolls. */
    public const INDEXNOW_RESPONSE_CODES = [
        200 => ['label' => 'OK', 'description' => 'URL(s) erfolgreich übermittelt.', 'success' => true],
        202 => ['label' => 'Accepted', 'description' => 'URL(s) empfangen, die Prüfung des IndexNow-Keys steht noch aus.', 'success' => true],
        400 => ['label' => 'Bad Request', 'description' => 'Ungültiges Anfrageformat.', 'success' => false],
        403 => ['label' => 'Forbidden', 'description' => 'Key ungültig (Keydatei nicht gefunden oder Key nicht in der Datei).', 'success' => false],
        422 => ['label' => 'Unprocessable Entity', 'description' => 'URLs gehören nicht zum Host oder der Key entspricht nicht dem Protokoll-Schema.', 'success' => false],
        429 => ['label' => 'Too Many Requests', 'description' => 'Zu viele Anfragen (mögliches Spam-Verhalten) – später erneut senden.', 'success' => false],
    ];

    /** @var array{success:bool, status:int, message:string, url_count:int, requests:int} */
    private array $lastIndexNowResult = ['success' => false, 'status' => 0, 'message' => '', 'url_count' => 0, 'requests' => 0];

    /** Obergrenze für die je Zeitfenster an IndexNow gemeldeten URLs (Schutz vor Überlast bei sehr großen Zeiträumen). */
    private const MAX_RECENT_CONTENT_URLS_PER_TYPE = 500;

    /**
     * Zeitfenster für die Massen-Meldung kürzlich veröffentlichter Inhalte an IndexNow.
     *
     * @var array<string, array{interval:string, label:string}>
     */
    private const RECENT_CONTENT_RANGES = [
        '24h' => ['interval' => '-1 day', 'label' => 'Letzte 24 Stunden'],
        '48h' => ['interval' => '-2 days', 'label' => 'Letzte 48 Stunden'],
        '1w'  => ['interval' => '-1 week', 'label' => 'Letzte Woche'],
        '1m'  => ['interval' => '-1 month', 'label' => 'Letzter Monat'],
        '3m'  => ['interval' => '-3 months', 'label' => 'Letzte 3 Monate'],
        '6m'  => ['interval' => '-6 months', 'label' => 'Letzte 6 Monate'],
    ];

    /** Erlaubte Ziel-Dienste für die Massen-Meldung kürzlich veröffentlichter Inhalte. */
    private const RECENT_CONTENT_TARGETS = ['indexnow', 'google'];

    /** Settings-Gruppe für Google-Access-Token und IndexNow-Warteschlange. */
    private const SETTINGS_GROUP = 'seo_indexing';
    private const GOOGLE_ACCESS_TOKEN_KEY = 'google_access_token';
    private const INDEXNOW_QUEUE_KEY = 'indexnow_queue';
    private const INDEXNOW_QUEUE_RESULT_KEY = 'indexnow_queue_last_result';

    /** Option (Opt-in) für die automatische IndexNow-Meldung gespeicherter Seiten und Beiträge. */
    public const INDEXNOW_AUTO_SUBMIT_OPTION = 'seo_indexnow_auto_submit';

    /** Obergrenze vorgemerkter URLs; bei Überlauf fallen die ältesten Einträge heraus. */
    public const INDEXNOW_QUEUE_MAX_URLS = 1000;

    /**
     * Mindestabstand in Sekunden, bevor dieselbe URL aus der Warteschlange erneut gemeldet wird
     * (IndexNow-FAQ: mindestens 5 Minuten zwischen zwei Meldungen derselben URL).
     */
    public const INDEXNOW_RESUBMIT_INTERVAL = 300;
    private const INDEXNOW_RECENT_SUBMISSIONS_KEY = 'indexnow_recent_submissions';
    /** Aufbewahrung der Meldezeitpunkte (Sekunden) – zugleich maximales Zeitfenster der stündlichen Nachmeldung. */
    private const INDEXNOW_RECENT_SUBMISSIONS_RETENTION = 86400;
    private const INDEXNOW_RECENT_SUBMISSIONS_MAX = 2000;
    private const INDEXNOW_PUBLISH_CHECK_KEY = 'indexnow_publish_checked_at';

    /** HTTP-Status, bei denen eine Wiederholung derselben URL-Gruppe nichts ändert (Warteschlange wird verworfen). */
    private const INDEXNOW_PERMANENT_ERROR_CODES = [400, 422];

    /** Herkunft des aktiven IndexNow-Keys (siehe resolveIndexNowKeyWithSource()). */
    public const INDEXNOW_KEY_SOURCES = [
        'database' => 'SEO-Einstellungen (Option „seo_indexnow_key“)',
        'legacy_setting' => 'Alt-Einstellung „seo.indexnow_key“',
        'constant' => 'Konstante SEO_INDEXNOW_KEY (config.php)',
    ];

    private Database $db;
    private SettingsService $settings;
    private Logger $logger;
    private string $prefix;
    private bool $indexNowFlushScheduled = false;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->settings = SettingsService::getInstance();
        $this->logger = Logger::instance()->withChannel('seo.indexing');
        $this->prefix = $this->db->getPrefix();
    }

    /**
     * Meldet URLs per IndexNow-Protokoll (JSON-POST an api.indexnow.org).
     *
     * URLs werden je Host gruppiert und in Blöcken von maximal 10.000 URLs gesendet. Für den
     * eigenen Host wird `keyLocation` mitgesendet, damit die Keydatei auch bei Installationen
     * in Unterverzeichnissen gefunden wird. HTTP 200 und 202 gelten als Erfolg.
     */
    public function submitIndexNow(string|array $urls): bool
    {
        $urlList = $this->normalizeUrls($urls);
        if ($urlList === []) {
            $this->logger->warning('IndexNow-Submission ohne URLs verworfen.');
            return $this->setLastIndexNowResult(false, 0, 'Keine gültigen URLs für IndexNow angegeben.', 0, 0);
        }

        $apiKey = $this->resolveIndexNowKey();
        if ($apiKey === '') {
            $this->logger->warning('IndexNow-Submission übersprungen: kein API-Key konfiguriert.');
            return $this->setLastIndexNowResult(false, 0, 'Es ist kein IndexNow-API-Key konfiguriert.', count($urlList), 0);
        }

        if (!self::isValidIndexNowKey($apiKey)) {
            $this->logger->warning('IndexNow-Submission übersprungen: API-Key entspricht nicht dem Protokoll.');
            return $this->setLastIndexNowResult(false, 0, 'Der IndexNow-API-Key entspricht nicht dem Protokoll (8–128 Zeichen, nur a-z, A-Z, 0-9 und Bindestrich).', count($urlList), 0);
        }

        $byHost = [];
        foreach ($urlList as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== '') {
                $byHost[$host][] = $url;
            }
        }

        $keyLocations = $this->buildIndexNowKeyLocationsByHost($apiKey);
        $success = true;
        $acceptedUrls = [];
        $requests = 0;
        $lastStatus = 0;
        $messages = [];

        foreach ($byHost as $host => $hostUrls) {
            foreach (array_chunk($hostUrls, self::INDEXNOW_MAX_URLS_PER_REQUEST) as $chunk) {
                $payload = [
                    'host' => $host,
                    'key' => $apiKey,
                ];
                if (isset($keyLocations[$host])) {
                    $payload['keyLocation'] = $keyLocations[$host];
                }
                $payload['urlList'] = array_values($chunk);

                $requests++;
                $result = $this->postIndexNowPayload($payload);
                $lastStatus = $result['status'];

                if (!$result['success']) {
                    $success = false;
                    $messages[] = $host . ': ' . $result['message'];
                    $this->logger->warning('IndexNow-Submission für {host} fehlgeschlagen.', [
                        'host' => $host,
                        'status' => $result['status'],
                        'url_count' => count($chunk),
                        'reason' => $result['message'],
                    ]);
                } elseif ($result['status'] === 202) {
                    $messages[] = $host . ': ' . $result['message'];
                }

                if ($result['success']) {
                    array_push($acceptedUrls, ...$chunk);
                }
            }
        }

        if ($acceptedUrls !== []) {
            $this->rememberIndexNowSubmissions($acceptedUrls);
        }

        if ($requests === 0) {
            return $this->setLastIndexNowResult(false, 0, 'Keine gültigen URLs mit Host für IndexNow gefunden.', count($urlList), 0);
        }

        $message = $success
            ? count($urlList) . ' URL(s) per IndexNow übermittelt (HTTP ' . $lastStatus . ').'
            : 'IndexNow-Übermittlung fehlgeschlagen.';
        if ($messages !== []) {
            $message .= ' ' . implode(' ', $messages);
        }

        return $this->setLastIndexNowResult($success, $lastStatus, $message, count($urlList), $requests);
    }

    /**
     * @return array{success:bool, status:int, message:string, url_count:int, requests:int}
     */
    public function getLastIndexNowResult(): array
    {
        return $this->lastIndexNowResult;
    }

    public static function isValidIndexNowKey(string $key): bool
    {
        return preg_match(self::INDEXNOW_KEY_PATTERN, $key) === 1;
    }

    /**
     * Erzeugt einen kryptografisch zufälligen hexadezimalen IndexNow-Key (Standard: 32 Zeichen).
     */
    public function generateIndexNowKey(int $length = self::INDEXNOW_KEY_DEFAULT_LENGTH): string
    {
        $length = max(self::INDEXNOW_KEY_MIN_LENGTH, min(self::INDEXNOW_KEY_MAX_LENGTH, $length));

        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }

    /**
     * Verzeichnis, in dem die Keydatei abgelegt wird: das Web-Root des CMS (ABSPATH, dort liegt index.php).
     */
    public function getIndexNowKeyFileDirectory(): string
    {
        return $this->normalizeDirectoryPath((string) ABSPATH);
    }

    public function getIndexNowKeyLocation(string $key = ''): string
    {
        $key = $key !== '' ? $key : $this->resolveIndexNowKey();
        if ($key === '' || !self::isValidIndexNowKey($key)) {
            return '';
        }

        return $this->resolvePublicBaseUrl() . '/' . $key . '.txt';
    }

    /**
     * keyLocation je eigenem Host: die konfigurierte SITE_URL hat Vorrang, zusätzlich wird der Host des
     * aktuellen Requests berücksichtigt (z. B. www/ohne www). Fremde Hosts erhalten keine keyLocation.
     *
     * @return array<string, string>
     */
    private function buildIndexNowKeyLocationsByHost(string $key): array
    {
        $locations = [];
        foreach ([defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '', $this->resolvePublicBaseUrl()] as $baseUrl) {
            $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
            if ($host !== '' && !isset($locations[$host])) {
                $locations[$host] = $baseUrl . '/' . $key . '.txt';
            }
        }

        return $locations;
    }

    /**
     * Schreibt die UTF-8-Keydatei `{key}.txt` mit dem Key als Inhalt in das Web-Root (Option 1 des Protokolls).
     * Optional wird die Keydatei eines vorherigen Keys entfernt – aber nur, wenn sie exakt diesem Key entspricht.
     *
     * @return array{success:bool, file:string, path:string, error:string, removed_previous:bool}
     */
    public function writeIndexNowKeyFile(string $key, string $previousKey = ''): array
    {
        $result = ['success' => false, 'file' => '', 'path' => '', 'error' => '', 'removed_previous' => false];

        if (!self::isValidIndexNowKey($key)) {
            $result['error'] = 'Der IndexNow-API-Key entspricht nicht dem Protokoll (8–128 Zeichen, nur a-z, A-Z, 0-9 und Bindestrich).';
            return $result;
        }

        $directory = $this->getIndexNowKeyFileDirectory();
        if ($directory === '' || !is_dir($directory)) {
            $result['error'] = 'Das Root-Verzeichnis des CMS wurde nicht gefunden.';
            return $result;
        }

        $fileName = $key . '.txt';
        $path = $directory . DIRECTORY_SEPARATOR . $fileName;
        $result['file'] = $fileName;
        $result['path'] = $path;

        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            $result['error'] = 'Am Zielpfad existiert bereits ein Eintrag, der keine normale Datei ist.';
            return $result;
        }

        if (!is_writable($directory) && !(is_file($path) && is_writable($path))) {
            $result['error'] = 'Das Root-Verzeichnis ist nicht beschreibbar. Bitte die Datei „' . $fileName . '“ mit dem Key als Inhalt manuell hochladen – bis dahin liefert das CMS sie dynamisch aus.';
            return $result;
        }

        if (@file_put_contents($path, $key, LOCK_EX) !== strlen($key)) {
            $result['error'] = 'Die Keydatei „' . $fileName . '“ konnte nicht geschrieben werden.';
            return $result;
        }

        $result['success'] = true;

        if ($previousKey !== '' && $previousKey !== $key && self::isValidIndexNowKey($previousKey)) {
            $result['removed_previous'] = $this->removeIndexNowKeyFile($previousKey);
        }

        $this->logger->info('IndexNow-Keydatei geschrieben.', ['file' => $fileName]);

        return $result;
    }

    /**
     * Entfernt `{key}.txt` aus dem Web-Root, sofern die Datei exakt den Key enthält.
     */
    public function removeIndexNowKeyFile(string $key): bool
    {
        if (!self::isValidIndexNowKey($key)) {
            return false;
        }

        $path = $this->getIndexNowKeyFileDirectory() . DIRECTORY_SEPARATOR . $key . '.txt';
        if (is_link($path) || !is_file($path) || (int) filesize($path) > self::MAX_INDEXNOW_KEY_FILE_SIZE) {
            return false;
        }

        $content = file_get_contents($path);
        if ($content === false || trim($content) !== $key) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{success:bool, status:int, message:string}
     */
    private function postIndexNowPayload(array $payload): array
    {
        try {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['success' => false, 'status' => 0, 'message' => 'Die IndexNow-Nutzlast konnte nicht als JSON kodiert werden.'];
        }

        $response = HttpClient::getInstance()->post(self::INDEXNOW_ENDPOINT, $body, [
            'userAgent' => '365CMS-IndexNow/1.0',
            'headers' => ['Content-Type: application/json; charset=utf-8'],
            'timeout' => 20,
            'maxBytes' => 65536,
        ]);

        $status = (int) ($response['status'] ?? 0);
        $known = self::INDEXNOW_RESPONSE_CODES[$status] ?? null;

        if ($known !== null) {
            return [
                'success' => (bool) $known['success'],
                'status' => $status,
                'message' => 'HTTP ' . $status . ' ' . $known['label'] . ' – ' . $known['description'],
            ];
        }

        if (!empty($response['success'])) {
            return ['success' => true, 'status' => $status, 'message' => 'HTTP ' . $status];
        }

        return [
            'success' => false,
            'status' => $status,
            'message' => $status > 0
                ? 'HTTP ' . $status . ' – unerwartete Antwort des IndexNow-Endpunkts.'
                : (string) ($response['error'] ?? 'Der IndexNow-Endpunkt war nicht erreichbar.'),
        ];
    }

    private function setLastIndexNowResult(bool $success, int $status, string $message, int $urlCount, int $requests): bool
    {
        $this->lastIndexNowResult = [
            'success' => $success,
            'status' => $status,
            'message' => $message,
            'url_count' => $urlCount,
            'requests' => $requests,
        ];

        return $success;
    }

    /**
     * Sendet URLs an die Google Indexing API.
     * Ohne explizit übergebenes Token wird automatisch das gespeicherte Google-Access-Token verwendet.
     */
    public function submitGoogle(string|array $urls, string $accessToken = ''): bool
    {
        $urlList = $this->normalizeUrls($urls);
        $token = trim($accessToken) !== '' ? trim($accessToken) : $this->resolveGoogleAccessToken();

        if ($urlList === [] || $token === '') {
            $this->logger->warning('Google-Submission verworfen: Token oder URLs fehlen.', [
                'url_count' => count($urlList),
            ]);
            return false;
        }

        try {
            $indexer = new GoogleIndexer($token);
            $results = $indexer->submitUrls($urlList, URLIndexingType::UPDATE);
            return !in_array(false, $results, true);
        } catch (\Throwable $e) {
            $this->logger->error('Google-Submission fehlgeschlagen.', [
                'url_count' => count($urlList),
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * Entfernt eine URL via Google Indexing API aus dem Index.
     * Ohne explizit übergebenes Token wird automatisch das gespeicherte Google-Access-Token verwendet.
     */
    public function deleteGoogle(string $url, string $accessToken = ''): bool
    {
        $normalizedUrl = trim($url);
        $token = trim($accessToken) !== '' ? trim($accessToken) : $this->resolveGoogleAccessToken();

        if ($normalizedUrl === '' || $token === '') {
            $this->logger->warning('Google-Delete verworfen: URL oder Token fehlen.');
            return false;
        }

        try {
            $indexer = new GoogleIndexer($token);
            return $indexer->submitUrl($normalizedUrl, URLIndexingType::DELETE);
        } catch (\Throwable $e) {
            $this->logger->error('Google-Delete fehlgeschlagen.', [
                'exception' => $e,
            ]);
            return false;
        }
    }

    public function hasIndexNowKey(): bool
    {
        return $this->resolveIndexNowKey() !== '';
    }

    public function getIndexNowKey(): string
    {
        return $this->resolveIndexNowKey();
    }

    /**
     * Prüft, ob dauerhaft ein Google-Access-Token hinterlegt ist.
     */
    public function hasGoogleAccessToken(): bool
    {
        return $this->resolveGoogleAccessToken() !== '';
    }

    /**
     * Speichert das Google-Access-Token verschlüsselt und dauerhaft in der Datenbank.
     */
    public function saveGoogleAccessToken(string $accessToken): bool
    {
        $token = trim($accessToken);
        if ($token === '') {
            return $this->clearGoogleAccessToken();
        }

        return $this->settings->set(self::SETTINGS_GROUP, self::GOOGLE_ACCESS_TOKEN_KEY, $token, true, 0);
    }

    /**
     * Entfernt ein zuvor gespeichertes Google-Access-Token.
     */
    public function clearGoogleAccessToken(): bool
    {
        return $this->settings->forget(self::SETTINGS_GROUP, self::GOOGLE_ACCESS_TOKEN_KEY);
    }

    private function resolveGoogleAccessToken(): string
    {
        return $this->settings->getString(self::SETTINGS_GROUP, self::GOOGLE_ACCESS_TOKEN_KEY, '');
    }

    public function isIndexNowAutoSubmitEnabled(): bool
    {
        return $this->getDirectSettingValue(self::INDEXNOW_AUTO_SUBMIT_OPTION) === '1';
    }

    /**
     * Merkt die öffentlichen URLs (DE und – falls übersetzt – EN) einer gespeicherten Seite bzw. eines
     * gespeicherten Beitrags für IndexNow vor. Nur aktiv, wenn die automatische Meldung eingeschaltet und ein
     * protokollgültiger Key vorhanden ist. Entwürfe, geplante Beiträge und noindex-Inhalte werden übersprungen.
     *
     * @return int Anzahl neu vorgemerkter URLs
     */
    public function queueContentForIndexNow(string $contentType, int $contentId): int
    {
        if ($contentId <= 0 || !in_array($contentType, ['post', 'page'], true) || !$this->isIndexNowAutoSubmitEnabled()) {
            return 0;
        }

        if (!self::isValidIndexNowKey($this->resolveIndexNowKey())) {
            return 0;
        }

        return $this->queueIndexNowUrls($this->buildContentIndexNowUrls($contentType, $contentId));
    }

    /**
     * Fügt URLs (dedupliziert) der IndexNow-Warteschlange hinzu.
     *
     * @param list<string> $urls
     * @return int Anzahl neu vorgemerkter URLs
     */
    public function queueIndexNowUrls(array $urls): int
    {
        $urls = $this->normalizeUrls($urls);
        if ($urls === []) {
            return 0;
        }

        $queue = $this->getIndexNowQueue();
        $known = array_fill_keys($queue, true);
        $added = 0;
        foreach ($urls as $url) {
            if (!isset($known[$url])) {
                $queue[] = $url;
                $known[$url] = true;
                $added++;
            }
        }

        if ($added === 0) {
            return 0;
        }

        if (count($queue) > self::INDEXNOW_QUEUE_MAX_URLS) {
            $queue = array_slice($queue, -self::INDEXNOW_QUEUE_MAX_URLS);
        }

        return $this->settings->set(self::SETTINGS_GROUP, self::INDEXNOW_QUEUE_KEY, array_values($queue)) ? $added : 0;
    }

    /**
     * @return list<string>
     */
    public function getIndexNowQueue(): array
    {
        $queue = $this->settings->get(self::SETTINGS_GROUP, self::INDEXNOW_QUEUE_KEY, []);
        if (!is_array($queue)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(mixed $url): string => is_string($url) ? trim($url) : '', $queue),
            static fn(string $url): bool => $url !== ''
        ));
    }

    /**
     * @return array{enabled:bool, count:int, max:int, urls:list<string>, resubmit_interval:int, last_result:array<string, mixed>}
     */
    public function getIndexNowQueueStatus(): array
    {
        $queue = $this->getIndexNowQueue();
        $lastResult = $this->settings->get(self::SETTINGS_GROUP, self::INDEXNOW_QUEUE_RESULT_KEY, []);

        return [
            'enabled' => $this->isIndexNowAutoSubmitEnabled(),
            'count' => count($queue),
            'max' => self::INDEXNOW_QUEUE_MAX_URLS,
            'urls' => array_slice($queue, 0, 25),
            'resubmit_interval' => self::INDEXNOW_RESUBMIT_INTERVAL,
            'last_result' => is_array($lastResult) ? $lastResult : [],
        ];
    }

    public function clearIndexNowQueue(): bool
    {
        return $this->settings->forget(self::SETTINGS_GROUP, self::INDEXNOW_QUEUE_KEY);
    }

    /**
     * Sendet alle vorgemerkten URLs per IndexNow. Bei Erfolg werden die gesendeten URLs aus der Warteschlange
     * entfernt; bei vorübergehenden Fehlern (Netzwerk, 403, 429, 5xx) bleiben sie für den nächsten Versuch
     * erhalten, bei 400/422 werden sie verworfen, weil eine Wiederholung nichts ändern würde. URLs, die vor weniger
     * als INDEXNOW_RESUBMIT_INTERVAL Sekunden erfolgreich gemeldet wurden, bleiben bis zum nächsten Lauf vorgemerkt.
     *
     * @param string $trigger 'auto' (nach dem Speichern), 'cron' oder 'manual'
     * @return array{success:bool, status:int, message:string, url_count:int, requests:int}
     */
    public function flushIndexNowQueue(string $trigger = 'manual'): array
    {
        $trigger = in_array($trigger, ['auto', 'cron', 'manual'], true) ? $trigger : 'manual';
        $queue = $this->getIndexNowQueue();
        if ($queue === []) {
            return ['success' => true, 'status' => 0, 'message' => 'Die IndexNow-Warteschlange ist leer.', 'url_count' => 0, 'requests' => 0];
        }

        // Vor weniger als INDEXNOW_RESUBMIT_INTERVAL Sekunden gemeldete URLs bleiben für den nächsten Lauf vorgemerkt.
        $recentSubmissions = $this->getRecentIndexNowSubmissions();
        $due = array_values(array_filter($queue, static fn(string $url): bool => !isset($recentSubmissions[$url])));
        $deferred = count($queue) - count($due);
        $deferredMessage = $deferred > 0
            ? $deferred . ' URL(s) wurden vor weniger als ' . intdiv(self::INDEXNOW_RESUBMIT_INTERVAL, 60) . ' Minuten gemeldet und bleiben bis zum nächsten Lauf vorgemerkt.'
            : '';

        if ($due === []) {
            $result = ['success' => true, 'status' => 0, 'message' => $deferredMessage, 'url_count' => 0, 'requests' => 0];
            $this->storeIndexNowQueueResult($trigger, $result);

            return $result;
        }

        $success = $this->submitIndexNow($due);
        $result = $this->lastIndexNowResult;
        $discard = !$success && in_array($result['status'], self::INDEXNOW_PERMANENT_ERROR_CODES, true);

        if ($success || $discard) {
            // Nur die gesendeten URLs entfernen – zwischenzeitlich (auch von parallelen Requests) neu
            // vorgemerkte bleiben erhalten; daher den Request-Cache vor dem erneuten Lesen verwerfen.
            OptionStore::getInstance()->flush();
            $remaining = array_values(array_diff($this->getIndexNowQueue(), $due));
            if ($remaining === []) {
                $this->clearIndexNowQueue();
            } else {
                $this->settings->set(self::SETTINGS_GROUP, self::INDEXNOW_QUEUE_KEY, $remaining);
            }
        }

        if ($discard) {
            $result['message'] .= ' Die vorgemerkten URLs wurden verworfen, da eine Wiederholung nichts ändern würde.';
        }

        if ($deferredMessage !== '') {
            $result['message'] .= ' ' . $deferredMessage;
        }

        $this->storeIndexNowQueueResult($trigger, $result);

        return $result;
    }

    /**
     * @param array{success:bool, status:int, message:string, url_count:int, requests:int} $result
     */
    private function storeIndexNowQueueResult(string $trigger, array $result): void
    {
        $this->settings->set(self::SETTINGS_GROUP, self::INDEXNOW_QUEUE_RESULT_KEY, [
            'time' => date('Y-m-d H:i:s'),
            'trigger' => $trigger,
            'success' => $result['success'],
            'status' => $result['status'],
            'message' => $result['message'],
            'url_count' => $result['url_count'],
        ]);
    }

    /**
     * Erfolgreich gemeldete URLs der letzten $maxAge Sekunden (Standard: INDEXNOW_RESUBMIT_INTERVAL).
     *
     * @return array<string, int> URL => Unix-Zeitpunkt der Meldung
     */
    private function getRecentIndexNowSubmissions(int $maxAge = self::INDEXNOW_RESUBMIT_INTERVAL): array
    {
        $stored = $this->settings->get(self::SETTINGS_GROUP, self::INDEXNOW_RECENT_SUBMISSIONS_KEY, []);
        if (!is_array($stored)) {
            return [];
        }

        $threshold = time() - $maxAge;
        $recent = [];
        foreach ($stored as $url => $submittedAt) {
            if (is_string($url) && is_int($submittedAt) && $submittedAt > $threshold) {
                $recent[$url] = $submittedAt;
            }
        }

        return $recent;
    }

    /**
     * @param list<string> $urls
     */
    private function rememberIndexNowSubmissions(array $urls): void
    {
        try {
            OptionStore::getInstance()->flush();
            $recent = $this->getRecentIndexNowSubmissions(self::INDEXNOW_RECENT_SUBMISSIONS_RETENTION);
            $now = time();
            foreach ($urls as $url) {
                unset($recent[$url]);
                $recent[$url] = $now;
            }

            if (count($recent) > self::INDEXNOW_RECENT_SUBMISSIONS_MAX) {
                $recent = array_slice($recent, -self::INDEXNOW_RECENT_SUBMISSIONS_MAX, null, true);
            }

            $this->settings->set(self::SETTINGS_GROUP, self::INDEXNOW_RECENT_SUBMISSIONS_KEY, $recent);
        } catch (\Throwable $e) {
            $this->logger->warning('IndexNow: Zeitpunkte der Meldung konnten nicht gespeichert werden.', ['exception' => $e]);
        }
    }

    /**
     * Sendet die Warteschlange am Ende des aktuellen Requests – nach Abschluss der Antwort, sofern der Server
     * das unterstützt (PHP-FPM/LiteSpeed), damit das Speichern im Admin nicht auf die Suchmaschine wartet.
     * Schlägt die Übermittlung fehl, wiederholt der stündliche Cron sie.
     */
    public function scheduleIndexNowQueueFlush(): void
    {
        if ($this->indexNowFlushScheduled) {
            return;
        }

        $this->indexNowFlushScheduled = true;
        register_shutdown_function(function (): void {
            try {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }

                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    litespeed_finish_request();
                }

                $this->flushIndexNowQueue('auto');
            } catch (\Throwable $e) {
                $this->logger->error('Automatische IndexNow-Übermittlung fehlgeschlagen.', ['exception' => $e]);
            }
        });
    }

    /**
     * Stündlicher Lauf (nur bei aktiver automatischer Meldung): merkt seit dem letzten Lauf veröffentlichte,
     * noch nicht gemeldete Inhalte vor und sendet die Warteschlange (inkl. Wiederholung fehlgeschlagener Meldungen).
     */
    public function runScheduledIndexNowFlush(): void
    {
        if (!$this->isIndexNowAutoSubmitEnabled() || !self::isValidIndexNowKey($this->resolveIndexNowKey())) {
            return;
        }

        try {
            $this->queueNewlyPublishedContent();
        } catch (\Throwable $e) {
            $this->logger->warning('IndexNow: Neu veröffentlichte Inhalte konnten nicht ermittelt werden.', ['exception' => $e]);
        }

        if ($this->getIndexNowQueue() === []) {
            return;
        }

        $this->flushIndexNowQueue('cron');
    }

    /**
     * Merkt Beiträge vor, die seit dem letzten Lauf öffentlich geworden sind, ohne beim Speichern gemeldet worden
     * zu sein – geplante Beiträge, deren Veröffentlichungszeitpunkt erreicht ist, und per Massenaktion
     * veröffentlichte Beiträge. Der erste Lauf setzt nur den Startzeitpunkt; das Zeitfenster ist auf
     * INDEXNOW_RECENT_SUBMISSIONS_RETENTION begrenzt.
     *
     * @return int Anzahl neu vorgemerkter URLs
     */
    private function queueNewlyPublishedContent(): int
    {
        $now = time();
        $lastCheck = $this->settings->getInt(self::SETTINGS_GROUP, self::INDEXNOW_PUBLISH_CHECK_KEY, 0);
        $this->settings->set(self::SETTINGS_GROUP, self::INDEXNOW_PUBLISH_CHECK_KEY, $now);
        if ($lastCheck <= 0 || $lastCheck >= $now) {
            return 0;
        }

        $from = date('Y-m-d H:i:s', max($lastCheck, $now - self::INDEXNOW_RECENT_SUBMISSIONS_RETENTION));
        $until = date('Y-m-d H:i:s', $now);
        $submitted = $this->getRecentIndexNowSubmissions(self::INDEXNOW_RECENT_SUBMISSIONS_RETENTION);
        $urls = [];

        $rows = $this->db->get_results(
            "SELECT id, published_at FROM {$this->prefix}posts
             WHERE status = 'published' AND published_at > ? AND published_at <= ?
             ORDER BY published_at ASC
             LIMIT " . self::MAX_RECENT_CONTENT_URLS_PER_TYPE,
            [$from, $until]
        ) ?: [];

        foreach ($rows as $row) {
            $publishedAt = strtotime((string) ($row->published_at ?? '')) ?: 0;
            foreach ($this->buildContentIndexNowUrls('post', (int) ($row->id ?? 0)) as $url) {
                // Beim Speichern bereits nach der Veröffentlichung gemeldete URLs nicht doppelt senden.
                if (($submitted[$url] ?? 0) < $publishedAt) {
                    $urls[] = $url;
                }
            }
        }

        return $this->queueIndexNowUrls($urls);
    }

    /**
     * @return list<string>
     */
    private function buildContentIndexNowUrls(string $contentType, int $contentId): array
    {
        try {
            $row = $contentType === 'post'
                ? $this->db->get_row(
                    "SELECT id, slug, slug_en, status, published_at, created_at, " . $this->buildHasEnglishSql() . " AS has_en
                     FROM {$this->prefix}posts
                     WHERE id = ?
                     LIMIT 1",
                    [$contentId]
                )
                : $this->db->get_row(
                    "SELECT id, slug, slug_en, status, " . $this->buildHasEnglishSql() . " AS has_en
                     FROM {$this->prefix}pages
                     WHERE id = ?
                     LIMIT 1",
                    [$contentId]
                );

            if ($row === null || !$this->isContentIndexable($contentType, $contentId)) {
                return [];
            }

            return $this->buildIndexNowUrlsFromRow($contentType, $row);
        } catch (\Throwable $e) {
            $this->logger->warning('IndexNow: URL für {type} #{id} konnte nicht ermittelt werden.', [
                'type' => $contentType,
                'id' => $contentId,
                'exception' => $e,
            ]);

            return [];
        }
    }

    /**
     * Baut die öffentliche DE-URL und – falls übersetzt – die EN-URL eines Inhalts.
     * Gemeinsame Grundlage für Auto-Meldung, Cron-Nachholung und Sammelmeldung.
     *
     * @return list<string>
     */
    private function buildIndexNowUrlsFromRow(string $contentType, object $row): array
    {
        $slug = trim((string) ($row->slug ?? ''));
        if ($slug === '') {
            return [];
        }

        $slugEn = trim((string) ($row->slug_en ?? ''));
        $hasEnglish = (int) ($row->has_en ?? 0) === 1;
        $urls = [];

        if ($contentType === 'post') {
            if (!\cms_post_is_publicly_visible($row)) {
                return [];
            }

            $permalinks = PermalinkService::getInstance();
            $publishedAt = (string) ($row->published_at ?? '');
            $createdAt = (string) ($row->created_at ?? '');
            $urls[] = $permalinks->buildPostUrlFromValues($slug, $publishedAt, $createdAt);
            if ($hasEnglish) {
                $urls[] = $permalinks->buildPostUrlFromValues($slugEn !== '' ? $slugEn : $slug, $publishedAt, $createdAt, 'en');
            }
        } else {
            if ((string) ($row->status ?? '') !== 'published') {
                return [];
            }

            $urls[] = $this->buildPagePathUrl($slug);
            if ($hasEnglish) {
                $urls[] = rtrim((string) SITE_URL, '/') . ContentLocalizationService::getInstance()->buildLocalizedPath(
                    '/' . ltrim($slugEn !== '' ? $slugEn : $slug, '/'),
                    'en'
                );
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * SQL-Ausdruck „EN-Übersetzung vorhanden“ – identisch zur Sitemap (Titel und Inhalt EN befüllt).
     */
    private function buildHasEnglishSql(string $alias = ''): string
    {
        $column = $alias !== '' ? rtrim($alias, '.') . '.' : '';

        return "(CHAR_LENGTH(TRIM(COALESCE({$column}title_en, ''))) > 0 AND CHAR_LENGTH(TRIM(COALESCE({$column}content_en, ''))) > 0)";
    }

    private function isContentIndexable(string $contentType, int $contentId): bool
    {
        try {
            $robotsIndex = $this->db->get_var(
                "SELECT robots_index FROM {$this->prefix}seo_meta WHERE content_type = ? AND content_id = ? LIMIT 1",
                [$contentType, $contentId]
            );
        } catch (\Throwable) {
            return true;
        }

        return $robotsIndex === null || (int) $robotsIndex === 1;
    }

    /**
     * Verfügbare Zeitfenster für die Massen-Meldung kürzlich veröffentlichter Inhalte.
     *
     * @return array<string, string>
     */
    public function getRecentContentRangeOptions(): array
    {
        $labels = [];
        foreach (self::RECENT_CONTENT_RANGES as $range => $definition) {
            $labels[$range] = (string) $definition['label'];
        }

        return $labels;
    }

    /**
     * Verfügbare Ziel-Dienste für die Massen-Meldung kürzlich veröffentlichter Inhalte.
     *
     * @return list<string>
     */
    public function getRecentContentTargetOptions(): array
    {
        return self::RECENT_CONTENT_TARGETS;
    }

    /**
     * Ermittelt die öffentlichen URLs (DE und übersetzte EN-Fassung) aller veröffentlichten,
     * indexierbaren Seiten/Beiträge im gewählten Zeitfenster. noindex-Inhalte werden wie in der Sitemap ausgelassen.
     *
     * @return list<string>
     */
    public function getRecentContentUrls(string $range): array
    {
        $interval = self::RECENT_CONTENT_RANGES[$range]['interval'] ?? null;
        if ($interval === null) {
            return [];
        }

        $cutoffTimestamp = strtotime($interval);
        if ($cutoffTimestamp === false) {
            return [];
        }

        $cutoff = date('Y-m-d H:i:s', $cutoffTimestamp);
        $urls = [];

        try {
            $pageRows = $this->db->get_results(
                "SELECT p.id, p.slug, p.slug_en, p.status, " . $this->buildHasEnglishSql('p') . " AS has_en
                 FROM {$this->prefix}pages p
                 LEFT JOIN {$this->prefix}seo_meta sm ON sm.content_type = 'page' AND sm.content_id = p.id
                 WHERE p.status = 'published' AND p.updated_at >= ? AND COALESCE(sm.robots_index, 1) = 1
                 ORDER BY p.updated_at DESC
                 LIMIT " . self::MAX_RECENT_CONTENT_URLS_PER_TYPE,
                [$cutoff]
            ) ?: [];

            foreach ($pageRows as $row) {
                array_push($urls, ...$this->buildIndexNowUrlsFromRow('page', $row));
            }

            $postRows = $this->db->get_results(
                "SELECT p.id, p.slug, p.slug_en, p.status, p.published_at, p.created_at, " . $this->buildHasEnglishSql('p') . " AS has_en
                 FROM {$this->prefix}posts p
                 LEFT JOIN {$this->prefix}seo_meta sm ON sm.content_type = 'post' AND sm.content_id = p.id
                 WHERE " . \cms_post_publication_where('p') . "
                   AND COALESCE(p.published_at, p.created_at) >= ?
                   AND COALESCE(sm.robots_index, 1) = 1
                 ORDER BY COALESCE(p.published_at, p.created_at) DESC
                 LIMIT " . self::MAX_RECENT_CONTENT_URLS_PER_TYPE,
                [$cutoff]
            ) ?: [];

            foreach ($postRows as $row) {
                array_push($urls, ...$this->buildIndexNowUrlsFromRow('post', $row));
            }
        } catch (\Throwable $e) {
            $this->logger->error('IndexNow: Ermittlung kürzlich veröffentlichter Inhalte fehlgeschlagen.', [
                'range' => $range,
                'exception' => $e,
            ]);

            return [];
        }

        return array_values(array_unique($urls));
    }

    /**
     * Meldet alle veröffentlichten Seiten/Beiträge des gewählten Zeitfensters an die gewählten Ziel-Dienste
     * (IndexNow und/oder Google). Für Google wird automatisch das dauerhaft gespeicherte Access-Token verwendet.
     *
     * @param list<string> $targets Erlaubt: 'indexnow', 'google'
     * @return array{success:bool, count:int, error:string, message:string}
     */
    public function submitRecentContent(string $range, array $targets = ['indexnow']): array
    {
        if (!array_key_exists($range, self::RECENT_CONTENT_RANGES)) {
            return ['success' => false, 'count' => 0, 'error' => 'Ungültiger Zeitraum ausgewählt.', 'message' => ''];
        }

        $targets = array_values(array_unique(array_filter(
            $targets,
            static fn(mixed $target): bool => in_array($target, self::RECENT_CONTENT_TARGETS, true)
        )));

        if ($targets === []) {
            return ['success' => false, 'count' => 0, 'error' => 'Bitte mindestens ein Ziel für die Meldung auswählen.', 'message' => ''];
        }

        $wantsIndexNow = in_array('indexnow', $targets, true);
        $wantsGoogle = in_array('google', $targets, true);

        if ($wantsIndexNow && !$this->hasIndexNowKey()) {
            return ['success' => false, 'count' => 0, 'error' => 'IndexNow ist nicht aktiv: Es ist kein API-Key konfiguriert.', 'message' => ''];
        }

        if ($wantsGoogle && !$this->hasGoogleAccessToken()) {
            return ['success' => false, 'count' => 0, 'error' => 'Google-Submission ist nicht aktiv: Es ist kein Access-Token hinterlegt.', 'message' => ''];
        }

        $urls = $this->getRecentContentUrls($range);
        $label = self::RECENT_CONTENT_RANGES[$range]['label'] ?? $range;

        if ($urls === []) {
            return ['success' => false, 'count' => 0, 'error' => 'Für „' . $label . '“ wurden keine veröffentlichten Seiten oder Beiträge gefunden.', 'message' => ''];
        }

        $messages = [];
        $errors = [];

        if ($wantsIndexNow) {
            if ($this->submitIndexNow($urls)) {
                $messages[] = 'IndexNow: ' . count($urls) . ' URL(s) aus „' . $label . '“ gemeldet.';
            } else {
                $errors[] = 'IndexNow konnte die URLs nicht vollständig entgegennehmen: ' . $this->lastIndexNowResult['message'];
            }
        }

        if ($wantsGoogle) {
            if ($this->submitGoogle($urls)) {
                $messages[] = 'Google: ' . count($urls) . ' URL(s) aus „' . $label . '“ gemeldet.';
            } else {
                $errors[] = 'Google konnte die URLs nicht verarbeiten (Access-Token evtl. abgelaufen).';
            }
        }

        return [
            'success' => $messages !== [] && $errors === [],
            'count' => count($urls),
            'error' => implode(' ', $errors),
            'message' => implode(' ', $messages),
        ];
    }

    private function buildPagePathUrl(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '' || $slug === '/') {
            return rtrim((string) SITE_URL, '/') . '/';
        }

        return rtrim((string) SITE_URL, '/') . '/' . ltrim($slug, '/');
    }

    /**
     * @return list<string>
     */
    public function getIndexNowRootTxtFiles(): array
    {
        $result = [];
        foreach ($this->getIndexNowRootDirectories() as $directory) {
            $files = glob($directory . DIRECTORY_SEPARATOR . '*.txt');
            if (!is_array($files) || $files === []) {
                continue;
            }

            foreach ($files as $file) {
                $basename = basename((string) $file);
                if ($basename === '' || !is_file($file)) {
                    continue;
                }

                $result[] = $basename;
            }
        }

        natcasesort($result);

        return array_values(array_unique($result));
    }

    /**
     * @return array{
     *     key:string,
     *     key_available:bool,
     *     key_source:string,
     *     key_source_label:string,
     *     key_protocol_valid:bool,
     *     key_location:string,
     *     key_file_directory:string,
     *     key_file_directory_writable:bool,
     *     endpoint:string,
     *     dynamic_key_file_active:bool,
     *     dynamic_key_file_url:string,
     *     selected_root_file:string,
     *     selected_root_file_url:string,
     *     selected_root_file_exists:bool,
     *     selected_root_file_path:string,
     *     selected_root_file_matches_key:bool,
     *     selected_root_file_content_matches_key:bool,
     *     selected_root_file_valid:bool,
     *     ready_for_submission:bool,
     *     root_directory:string,
     *     root_txt_files:list<string>,
     *     validation_errors:list<string>,
     *     validation_notes:list<string>,
     *     debug:array{
     *         root_candidates:list<array{
     *             source:string,
     *             original_path:string,
     *             normalized_path:string,
     *             usable:bool,
     *             reason:string,
     *             txt_files:list<string>,
     *             selected_file_path:string,
     *             selected_file_exists:bool
     *         }>,
     *         selected_file_reason:string,
     *         selected_file_resolved_from:string
     *     }
     * }
     */
    public function getIndexNowConfigurationStatus(): array
    {
        ['key' => $key, 'source' => $keySource] = $this->resolveIndexNowKeyWithSource();
        $selectedFile = $this->resolveIndexNowSelectedRootFile();
        $publicBaseUrl = $this->resolvePublicBaseUrl();
        $rootDebugCandidates = $this->getIndexNowRootDebugCandidates($selectedFile);
        $rootDirectories = array_values(array_map(
            static fn(array $candidate): string => (string) $candidate['normalized_path'],
            array_values(array_filter(
                $rootDebugCandidates,
                static fn(array $candidate): bool => !empty($candidate['usable']) && (string) ($candidate['normalized_path'] ?? '') !== ''
            ))
        ));
        $rootTxtFiles = $this->getIndexNowRootTxtFiles();
        $rootDirectory = implode(' | ', $rootDirectories);
        $keyProtocolValid = $key !== '' && self::isValidIndexNowKey($key);
        // Der ThemeRouter liefert /{key}.txt nur für protokollgültige Keys aus.
        $dynamicKeyFileUrl = $keyProtocolValid
            ? $publicBaseUrl . '/' . rawurlencode($key) . '.txt'
            : '';

        $selectedFileExists = false;
        $selectedFileMatchesKey = false;
        $selectedFileContentMatchesKey = false;
        $selectedFileValid = false;
        $selectedFileUrl = '';
        $selectedFilePath = '';
        $validationErrors = [];
        $validationNotes = [];
        $selectedFileReason = $selectedFile === ''
            ? 'Keine physische Root-TXT-Datei ausgewählt.'
            : 'Die ausgewählte Root-TXT-Datei wurde noch nicht geprüft.';
        $selectedFileResolvedFrom = '';

        if ($key === '') {
            $validationNotes[] = 'Kein IndexNow-API-Key gespeichert.';
        } elseif ($keyProtocolValid) {
            $validationNotes[] = 'Die dynamische Keydatei ist unter `' . $dynamicKeyFileUrl . '` verfügbar.';
        } else {
            $validationErrors[] = 'Der gespeicherte API-Key entspricht nicht dem IndexNow-Protokoll (8–128 Zeichen, nur a-z, A-Z, 0-9 und Bindestrich). Bitte einen neuen Key erzeugen.';
        }

        if ($selectedFile !== '') {
            $selectedFileUrl = $publicBaseUrl . '/' . rawurlencode($selectedFile);
            $selectedFilePath = $this->findIndexNowRootFilePath($selectedFile);
            $selectedFileExists = $selectedFilePath !== null && in_array($selectedFile, $rootTxtFiles, true);
            $selectedFilePath = $selectedFilePath ?? '';

            foreach ($rootDebugCandidates as $candidate) {
                if (!empty($candidate['selected_file_exists'])) {
                    $selectedFileResolvedFrom = (string) ($candidate['normalized_path'] ?? '');
                    break;
                }
            }

            if (!$selectedFileExists) {
                $validationErrors[] = 'Die ausgewählte Root-TXT-Datei wurde nicht gefunden.';
                $selectedFileReason = 'Die Datei wurde in keinem der geprüften Root-Pfade gefunden.';
            } else {
                $expectedFileName = $key !== '' ? $key . '.txt' : '';
                $selectedFileMatchesKey = $key !== '' && $selectedFile === $expectedFileName;
                $selectedFileReason = 'Die Datei wurde gefunden und wird jetzt gegen Name und Inhalt geprüft.';

                $selectedFileCanBeRead = true;
                if (!is_readable($selectedFilePath)) {
                    $validationErrors[] = 'Die ausgewählte Root-TXT-Datei ist nicht lesbar.';
                    $selectedFileCanBeRead = false;
                    $selectedFileReason = 'Die Datei existiert, ist aber nicht lesbar.';
                } else {
                    $fileSize = filesize($selectedFilePath);
                    if ($fileSize === false) {
                        $validationErrors[] = 'Die Größe der ausgewählten Root-TXT-Datei konnte nicht ermittelt werden.';
                        $selectedFileCanBeRead = false;
                        $selectedFileReason = 'Die Datei existiert, aber ihre Größe konnte nicht ermittelt werden.';
                    } elseif ((int) $fileSize > self::MAX_INDEXNOW_KEY_FILE_SIZE) {
                        $validationErrors[] = 'Die ausgewählte Root-TXT-Datei ist für eine IndexNow-Keydatei ungewöhnlich groß.';
                        $selectedFileCanBeRead = false;
                        $selectedFileReason = 'Die Datei ist größer als für eine IndexNow-Keydatei erwartet.';
                    }
                }

                $selectedContent = '';
                if ($selectedFileCanBeRead) {
                    $selectedContentRaw = $this->readSafeIndexNowRootFile($selectedFilePath);
                    if ($selectedContentRaw === false) {
                        $validationErrors[] = 'Die ausgewählte Root-TXT-Datei konnte nicht gelesen werden.';
                        $selectedFileReason = 'Die Datei wurde gefunden, konnte aber nicht ausgelesen werden.';
                    } else {
                        $selectedContent = trim($selectedContentRaw);
                    }
                }

                $selectedFileContentMatchesKey = $key !== '' && $selectedContent === $key;
                $selectedFileValid = $selectedFileMatchesKey && $selectedFileContentMatchesKey;

                if (!$selectedFileMatchesKey && $key !== '') {
                    $validationErrors[] = 'Der Dateiname der ausgewählten TXT-Datei entspricht nicht dem API-Key.';
                    $selectedFileReason = 'Der Dateiname passt nicht exakt zum gespeicherten API-Key.';
                }

                if (!$selectedFileContentMatchesKey && $key !== '') {
                    $validationErrors[] = 'Der Inhalt der ausgewählten TXT-Datei entspricht nicht dem API-Key.';
                    $selectedFileReason = 'Der Dateiinhalt passt nicht exakt zum gespeicherten API-Key.';
                }

                if ($selectedFileValid) {
                    $validationNotes[] = 'Die ausgewählte Root-TXT-Datei wurde erfolgreich gegen den API-Key geprüft.';
                    $selectedFileReason = 'Die Datei ist gültig: Dateiname und Inhalt entsprechen dem API-Key.';
                }
            }
        } else {
            $validationNotes[] = 'Es ist aktuell keine physische Root-TXT-Datei ausgewählt.';
        }

        $keyFileDirectory = $this->getIndexNowKeyFileDirectory();

        return [
            'key' => $key,
            'key_available' => $key !== '',
            'key_source' => $keySource,
            'key_source_label' => self::INDEXNOW_KEY_SOURCES[$keySource] ?? '',
            'key_protocol_valid' => $keyProtocolValid,
            'key_location' => $keyProtocolValid ? $this->getIndexNowKeyLocation($key) : '',
            'key_file_directory' => $keyFileDirectory,
            'key_file_directory_writable' => $keyFileDirectory !== '' && is_dir($keyFileDirectory) && is_writable($keyFileDirectory),
            'endpoint' => self::INDEXNOW_ENDPOINT,
            'dynamic_key_file_active' => $keyProtocolValid,
            'dynamic_key_file_url' => $dynamicKeyFileUrl,
            'selected_root_file' => $selectedFile,
            'selected_root_file_url' => $selectedFileUrl,
            'selected_root_file_exists' => $selectedFileExists,
            'selected_root_file_path' => $selectedFilePath,
            'selected_root_file_matches_key' => $selectedFileMatchesKey,
            'selected_root_file_content_matches_key' => $selectedFileContentMatchesKey,
            'selected_root_file_valid' => $selectedFileValid,
            'ready_for_submission' => $keyProtocolValid && ($selectedFile === '' || $selectedFileValid),
            'root_directory' => $rootDirectory,
            'root_txt_files' => $rootTxtFiles,
            'validation_errors' => $validationErrors,
            'validation_notes' => $validationNotes,
            'debug' => [
                'root_candidates' => $rootDebugCandidates,
                'selected_file_reason' => $selectedFileReason,
                'selected_file_resolved_from' => $selectedFileResolvedFrom,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function getIndexNowRootDirectories(): array
    {
        $directories = [];
        foreach ($this->getIndexNowRootDebugCandidates('') as $candidate) {
            if (empty($candidate['usable'])) {
                continue;
            }

            $normalizedPath = (string) ($candidate['normalized_path'] ?? '');
            if ($normalizedPath === '') {
                continue;
            }

            $directories[] = $normalizedPath;
        }

        return array_values(array_unique($directories));
    }

    private function findIndexNowRootFilePath(string $selectedFile): ?string
    {
        foreach ($this->getIndexNowRootDirectories() as $directory) {
            $path = $directory . DIRECTORY_SEPARATOR . $selectedFile;
            if ($this->isSafeIndexNowRootFilePath($path, $directory)) {
                return $path;
            }
        }

        return null;
    }

    private function normalizeDirectoryPath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }

        $realPath = realpath($trimmed);
        if ($realPath !== false) {
            return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $realPath), DIRECTORY_SEPARATOR);
        }

        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed), DIRECTORY_SEPARATOR);
    }

    /**
     * @return list<array{
     *     source:string,
     *     original_path:string,
     *     normalized_path:string,
     *     usable:bool,
     *     reason:string,
     *     txt_files:list<string>,
     *     selected_file_path:string,
     *     selected_file_exists:bool
     * }>
     */
    private function getIndexNowRootDebugCandidates(string $selectedFile): array
    {
        $candidates = $this->getIndexNowRootDirectoryCandidates();
        $result = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $originalPath = (string) ($candidate['path'] ?? '');
            $normalizedPath = $this->normalizeDirectoryPath($originalPath);
            $usable = true;
            $reason = 'Pfad wird geprüft.';
            $txtFiles = [];
            $selectedFilePath = '';
            $selectedFileExists = false;

            if ($normalizedPath === '') {
                $usable = false;
                $reason = 'Pfad ist leer und kann nicht geprüft werden.';
            } elseif (!is_dir($normalizedPath)) {
                $usable = false;
                $reason = 'Pfad existiert nicht als Verzeichnis.';
            } elseif (!is_readable($normalizedPath)) {
                $usable = false;
                $reason = 'Pfad ist nicht lesbar.';
            } else {
                $files = glob($normalizedPath . DIRECTORY_SEPARATOR . '*.txt');
                if (is_array($files) && $files !== []) {
                    foreach ($files as $file) {
                        if (!is_file($file)) {
                            continue;
                        }

                        $txtFiles[] = basename((string) $file);
                    }
                }

                sort($txtFiles, SORT_NATURAL | SORT_FLAG_CASE);

                if ($selectedFile !== '') {
                    $selectedCandidatePath = $normalizedPath . DIRECTORY_SEPARATOR . $selectedFile;
                    if (is_file($selectedCandidatePath)) {
                        $selectedFileExists = true;
                        $selectedFilePath = $selectedCandidatePath;
                    }
                }

                $reason = $txtFiles === []
                    ? 'Pfad geprüft, aber keine .txt-Dateien gefunden.'
                    : 'Pfad geprüft, .txt-Dateien gefunden: ' . count($txtFiles);
            }

            $uniqueKey = ($candidate['source'] ?? 'unknown') . '|' . $normalizedPath;
            if (isset($seen[$uniqueKey])) {
                continue;
            }
            $seen[$uniqueKey] = true;

            $result[] = [
                'source' => (string) ($candidate['source'] ?? 'unbekannt'),
                'original_path' => $originalPath,
                'normalized_path' => $normalizedPath,
                'usable' => $usable,
                'reason' => $reason,
                'txt_files' => $txtFiles,
                'selected_file_path' => $selectedFilePath,
                'selected_file_exists' => $selectedFileExists,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{source:string, path:string}>
     */
    private function getIndexNowRootDirectoryCandidates(): array
    {
        $candidates = [
            [
                'source' => 'dirname(ABSPATH)',
                'path' => dirname(rtrim((string) ABSPATH, DIRECTORY_SEPARATOR)),
            ],
            [
                'source' => 'ABSPATH',
                'path' => rtrim((string) ABSPATH, DIRECTORY_SEPARATOR),
            ],
        ];

        $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($documentRoot !== '') {
            $candidates[] = [
                'source' => '$_SERVER[DOCUMENT_ROOT]',
                'path' => $documentRoot,
            ];
        }

        $scriptFilename = trim((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if ($scriptFilename !== '') {
            $candidates[] = [
                'source' => 'dirname($_SERVER[SCRIPT_FILENAME])',
                'path' => dirname($scriptFilename),
            ];
        }

        return $candidates;
    }

    private function resolveIndexNowKey(): string
    {
        return $this->resolveIndexNowKeyWithSource()['key'];
    }

    /**
     * Ermittelt den aktiven IndexNow-Key samt Herkunft. Der in den SEO-Einstellungen gespeicherte Key hat
     * Vorrang; die Alt-Einstellung `seo.indexnow_key` und die Konstante `SEO_INDEXNOW_KEY` dienen nur als Fallback.
     *
     * @return array{key:string, source:string}
     */
    private function resolveIndexNowKeyWithSource(): array
    {
        $candidates = [
            'database' => $this->getDirectSettingValue('seo_indexnow_key'),
            'legacy_setting' => $this->settings->getString('seo', 'indexnow_key', ''),
            'constant' => defined('SEO_INDEXNOW_KEY') ? trim((string) SEO_INDEXNOW_KEY) : '',
        ];

        foreach ($candidates as $source => $candidate) {
            if ($candidate !== '') {
                return ['key' => $candidate, 'source' => $source];
            }
        }

        return ['key' => '', 'source' => ''];
    }

    private function resolveIndexNowSelectedRootFile(): string
    {
        $candidates = [
            $this->getDirectSettingValue('seo_indexnow_key_file'),
            $this->settings->getString('seo', 'indexnow_key_file', ''),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || preg_match('/^[A-Za-z0-9._-]+\.txt$/', $candidate) !== 1) {
                continue;
            }

            return $candidate;
        }

        return '';
    }

    private function getDirectSettingValue(string $optionName): string
    {
        return trim((string) (OptionStore::getInstance()->get($optionName) ?? ''));
    }

    private function resolvePublicBaseUrl(): string
    {
        if (function_exists('cms_runtime_base_url')) {
            try {
                $runtimeBaseUrl = rtrim((string) cms_runtime_base_url(), '/');
                if ($runtimeBaseUrl !== '') {
                    return $runtimeBaseUrl;
                }
            } catch (\Throwable) {
                // Fallback auf SITE_URL, wenn die Runtime-Basis nicht verfügbar ist.
            }
        }

        return rtrim((string) SITE_URL, '/');
    }

    /**
     * @return array<int, string>
     */
    private function normalizeUrls(string|array $urls): array
    {
        $list = is_array($urls)
            ? $urls
            : (preg_split('/\r\n|\r|\n|,/', $urls) ?: []);
        $normalized = [];

        foreach ($list as $url) {
            $url = trim((string) $url);
            if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false
                || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                continue;
            }
            $normalized[] = $url;
        }

        return array_values(array_unique($normalized));
    }

    private function isSafeIndexNowRootFilePath(string $path, string $rootDirectory): bool
    {
        $realRoot = realpath($rootDirectory);
        $realPath = realpath($path);
        if ($realRoot === false || $realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            return false;
        }

        $normalizedRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $realRoot), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $realPath);

        return str_starts_with($normalizedPath, $normalizedRoot)
            && strtolower((string) pathinfo($normalizedPath, PATHINFO_EXTENSION)) === 'txt';
    }

    private function readSafeIndexNowRootFile(string $selectedFilePath): string|false
    {
        foreach ($this->getIndexNowRootDirectories() as $rootDirectory) {
            if (!$this->isSafeIndexNowRootFilePath($selectedFilePath, $rootDirectory)) {
                continue;
            }

            $realPath = realpath($selectedFilePath);
            if ($realPath === false) {
                return false;
            }

            $size = filesize($realPath);
            if ($size === false || $size < 0 || $size > self::MAX_INDEXNOW_KEY_FILE_SIZE) {
                return false;
            }

            return file_get_contents($realPath);
        }

        return false;
    }
}