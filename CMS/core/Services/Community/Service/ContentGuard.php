<?php
/**
 * Inhalts- und Request-Schutz der Community (PR #2b; Vertrag DESIGN-2b §2.1 Nr. 6, §3.13, §4.1, §4.7 Nr. 7, §4.9; Entwurf schutz §1.2, §2).
 *
 * Alle Schutzschichten außer Rechten und Kontingenten (die entscheidet `CommunityAccess::can()`, auch die Inhaltsgrenzen der Stufe):
 * - `filterText()`: Wortfilter, rein. Reihenfolge block → replace → review; gilt für Titel und Text und für alle Rollen.
 * - `filterHosts()`: dieselben Muster gegen die normalisierten Hosts der Links (`Stats.hosts`, `ContentRenderer::normalizeHost()`).
 * - `content()`: Zählung von Links, Bildern und Anhängen für den Kontext `content` von `can()`.
 * - `screen()`: nach erlaubtem `can()`, vor `ensure()` und der Transaktion (Vertrag §4.1 Schritt 11, §4.2 Schritt 7), in dieser Folge:
 *   Urteil des Wortfilters, Hosts, Core-Antispam, Signale, Spam-Dublette, Filter `cms_community_spam_check`, `ip_hash`.
 * - `throttle()`: Request-Grenzen (`THROTTLE`) über `login_attempts` mit pseudonymer Kennung; `pruneThrottle()` räumt stündlich auf.
 * - `logRejection()`: Sicherheitsprotokoll ohne Inhalt, gedrosselt.
 *
 * Vergleichsfassung (`fold()`, Vertrag §4.9): unsichtbare Default-Ignorable-Zeichen außer Cf entfernen, dann NFKC, dann `MB_CASE_FOLD`.
 * Sie gilt für die zweite Prüfung des Wortfilters (Text, Titel, Einträge), für die Hosts und für den Fingerabdruck der Spam-Dublette.
 * Der Antispam-Inhalt läuft nur durch `stripIgnorable()`. Gespeichert wird der Text unverändert (Emoji brauchen U+FE0F).
 *
 * Läuft nie in einer Transaktion und nie unter Zeilensperren (Vertrag §1 Nr. 2); liest keine Uhr, die Zeit kommt als `$nowTs`. Beim
 * Konstruieren keine Datenbank; die Muster des Wortfilters entstehen je Instanz einmal. Datenbankfehler der Dublettenabfrage gehen als
 * `\PDOException` an den Dienst (`db_error`); Ausnahmen in Antispam (fail-open), Filter (`review`), Zählung (`failed`), Request-Grenze
 * (fail-open wie der Core) und Protokoll (Warnung) fängt die Klasse selbst. Warnungen im Kanal `community`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Logger;
use CMS\Security;
use CMS\Services\AntispamService;
use CMS\Services\Community\Repository\MemberRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Support\ContentRenderer;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\IpHash;
use CMS\Services\Community\Support\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentGuard
{
    public const ALLOW = 'allow';
    public const REVIEW = 'review';
    public const BLOCK = 'block';

    /** Jüngste Beiträge des Mitglieds im Fenster, die die Spam-Dublette vergleicht. */
    public const DUPLICATE_SCAN = 20;

    /** Kürzere Fingerabdrücke („Danke!“) prüft die Spam-Dublette nicht. */
    public const DUPLICATE_MIN_CHARS = 40;

    /**
     * Request-Grenzen (Vertrag §3.13): Aktion => [Gäste je Fenster, Mitglieder je Fenster, Fenster in s]. Gäste bei `search` zusätzlich
     * gemeinsam `SEARCH_GUESTS_TOTAL` je 60 s. Grenze 0 = keine Anfrage erlaubt (Gäste erreichen diese Aktionen nie).
     */
    public const THROTTLE = [
        'search' => [10, 30, 60],
        'preview' => [0, 10, 60],
        'react' => [30, 30, 60],
        'react_notify' => [0, 10, 3600],
        'subscribe' => [30, 30, 60],
        'upload' => [0, 30, 3600],
        'edit' => [0, 20, 600],
        'reject_log' => [30, 30, 3600],
    ];

    /** Alle Gäste zusammen je 60 s bei der Suche (Kennung `community:g:all`). */
    public const SEARCH_GUESTS_TOTAL = 120;

    /** Endgültige Inhaltsablehnungen, die ins Sicherheitsprotokoll gehen (`spam_rejected` protokolliert AntispamService selbst). */
    public const LOGGED_CODES = ['word_blocked', 'too_many_links', 'too_many_images', 'too_many_attachments', 'content_review'];

    /** Aktionen von `screen()`. */
    private const SCREEN_ACTIONS = ['create_topic', 'reply', 'edit'];

    /** Alternativen je Muster des Wortfilters (ein Durchlauf je Block). */
    private const WORD_BLOCK = 100;

    /**
     * Unsichtbare Zeichen der Vergleichsfassung: jedes Default-Ignorable-Zeichen außer Cf (Cf entfernt schon `normalizeBody()`), keines
     * außerhalb der Eigenschaft. Feste Liste, weil PCRE2 `\p{DI}` erst ab 10.40 kennt (Vertrag §4.9 Nr. 2).
     */
    private const IGNORABLE = '/[\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{2060}-\x{206F}\x{3164}\x{FE00}-\x{FE0F}\x{FFA0}\x{FFF0}-\x{FFF8}\x{E0000}-\x{E0FFF}]/u';

    /** Präfix der Aktionsnamen in `login_attempts`, aufgeräumt nach der doppelten größten Fensterlänge. */
    private const THROTTLE_PREFIX = 'community_';
    private const THROTTLE_KEEP_SECONDS = 7200;

    private static ?self $instance = null;
    private static bool $unknownWarned = false;

    private Database $db;
    private Settings $settings;
    private PostRepository $posts;
    private MemberRepository $members;
    private IpHash $ipHash;
    /** @var \Closure(string): mixed */
    private \Closure $inspect;
    /** @var \Closure(array<string, mixed>): mixed */
    private \Closure $antispam;
    /** @var \Closure(): mixed */
    private \Closure $client;
    /** @var \Closure(string, string, int, int): bool */
    private \Closure $limiter;
    /** @var \Closure(array<string, mixed>): void */
    private \Closure $securityLog;

    /** @var array<string, array{0: list<string>, 1: list<string>}>|null Liste => [Muster im Original, Muster der Vergleichsfassung] */
    private ?array $patterns = null;
    private string $replacement = '***';

    /**
     * @param (\Closure(string $markdown): array)|null $inspect Zählung (`Stats` des Renderers oder `{links, images}`); Standard: `ContentRenderer::instance()->analyze()`
     * @param (\Closure(array $context): array)|null $antispam `{rejected: bool, reason: string}`; Standard: `AntispamService::getInstance()->evaluate()`
     * @param (\Closure(): array)|null $client `{web: bool, ip: string, user_agent: string}`; Standard: CLI ohne Anfrage, sonst Client-IP und User-Agent
     * @param (\Closure(string $name, string $subject, int $max, int $window): bool)|null $limiter true = erlaubt; Standard über `Security` und `login_attempts`
     * @param (\Closure(array $entry): void)|null $securityLog `{code, user_id, ip, user_agent}`; Standard: INSERT in `security_log`
     */
    public function __construct(
        ?Database $db = null,
        ?Settings $settings = null,
        ?\Closure $inspect = null,
        ?\Closure $antispam = null,
        ?\Closure $client = null,
        ?\Closure $limiter = null,
        ?\Closure $securityLog = null,
        ?IpHash $ipHash = null
    ) {
        $this->db = $db ?? Database::instance();
        $this->settings = $settings ?? Settings::getInstance();
        $this->posts = new PostRepository($this->db);
        $this->members = new MemberRepository($this->db);
        $this->ipHash = $ipHash ?? new IpHash();
        $this->inspect = $inspect ?? static function (string $markdown): array {
            if (!class_exists(ContentRenderer::class)) {
                throw new \RuntimeException('ContentRenderer fehlt.');
            }
            $analysis = ContentRenderer::instance()->analyze($markdown);
            if (($analysis['ok'] ?? false) !== true) {
                throw new \RuntimeException('Zählung des Renderers fehlgeschlagen.');
            }

            return $analysis['stats'];
        };
        $this->antispam = $antispam ?? static fn (array $context): array => AntispamService::getInstance()->evaluate($context);
        $this->client = $client ?? static function (): array {
            if (PHP_SAPI === 'cli') {
                return ['web' => false, 'ip' => '', 'user_agent' => ''];
            }

            return ['web' => true, 'ip' => Security::getClientIp(), 'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')];
        };
        // Zählweise laut Vertrag §3.13: zählen (voll ⇒ ablehnen ohne Eintrag), eintragen, nachzählen (über der Grenze ⇒ abgelehnt).
        $this->limiter = $limiter ?? static function (string $name, string $subject, int $max, int $window): bool {
            if (Security::countDbIdentifierAttempts($subject, $name, $window) >= $max) {
                return false;
            }
            Security::recordDbRateLimitAttempt('0.0.0.0', $name, $subject);

            return Security::countDbIdentifierAttempts($subject, $name, $window) <= $max;
        };
        $this->securityLog = $securityLog ?? function (array $entry): void {
            $this->writeSecurityLog($entry);
        };
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    // ── Wortfilter und Vergleichsfassung ─────────────────────────────────────────────────────────────────────────

    /**
     * Wortfilter, rein. block: Treffer im Original (Muster `/iu`) oder in der Vergleichsfassung ⇒ `block`, Text unverändert.
     * replace: nur im Original, jeder Treffer wird zum Ersatztext. review: Treffer der review-Liste im ersetzten Text (Original oder
     * Vergleichsfassung) oder ein replace-Eintrag, der nur noch in der Vergleichsfassung vorkommt ⇒ `review`. Ganzwort, `*` am Ende
     * eines Eintrags ist ein Präfix. Ein PCRE-Fehler ergibt `review` mit unverändertem Text. Titel `null` = nur Text.
     *
     * @return array{title: ?string, body: string, verdict: 'allow'|'review'|'block', replaced: bool}
     *   replaced = true: der Dienst prüft die Längen erneut
     */
    public function filterText(?string $title, string $body): array
    {
        if (!$this->hasEntries()) {
            return ['title' => $title, 'body' => $body, 'verdict' => self::ALLOW, 'replaced' => false];
        }

        try {
            foreach ([$title, $body] as $text) {
                if ($text !== null && $this->hits('block', $text, self::fold($text))) {
                    return ['title' => $title, 'body' => $body, 'verdict' => self::BLOCK, 'replaced' => false];
                }
            }
            $replaced = false;
            $newTitle = $title === null ? null : $this->replace($title, $replaced);
            $newBody = $this->replace($body, $replaced);
            $verdict = self::ALLOW;
            foreach ([$newTitle, $newBody] as $text) {
                if ($text === null) {
                    continue;
                }
                $folded = self::fold($text);
                if ($this->hits('review', $text, $folded) || self::matchesAny($this->patterns()['replace'][1], $folded)) {
                    $verdict = self::REVIEW;
                }
            }

            return ['title' => $newTitle, 'body' => $newBody, 'verdict' => $verdict, 'replaced' => $replaced];
        } catch (\UnexpectedValueException) {
            return ['title' => $title, 'body' => $body, 'verdict' => self::REVIEW, 'replaced' => false]; // PCRE-Fehler
        }
    }

    /**
     * Dieselben Muster wie `filterText()` gegen die normalisierten Hosts (Vertrag §4.7 Nr. 7): block ⇒ `block`; review oder ein Treffer
     * eines replace-Eintrags ⇒ `review`; PCRE-Fehler ⇒ `review`.
     *
     * @param array<array-key, mixed> $hosts
     * @return 'allow'|'review'|'block'
     */
    public function filterHosts(array $hosts): string
    {
        $text = implode("\n", array_filter($hosts, 'is_string'));
        if ($text === '' || !$this->hasEntries()) {
            return self::ALLOW;
        }

        try {
            $folded = self::fold($text);
            if ($this->hits('block', $text, $folded)) {
                return self::BLOCK;
            }

            return $this->hits('review', $text, $folded) || $this->hits('replace', $text, $folded) ? self::REVIEW : self::ALLOW;
        } catch (\UnexpectedValueException) {
            return self::REVIEW;
        }
    }

    /** Vergleichsfassung (Vertrag §4.9): unsichtbare Zeichen entfernen, NFKC, `MB_CASE_FOLD`. Ungültiges UTF-8 ergibt `''`. */
    public static function fold(string $text): string
    {
        $text = self::stripIgnorable($text);
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
            $text = is_string($normalized) ? $normalized : $text;
        }

        return mb_convert_case($text, MB_CASE_FOLD, 'UTF-8');
    }

    // ── Zählung ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Zählung für den Kontext `content` von `can()`. `$counts`: fertige Zählung des Renderers (`Stats`: `links` = `external_links`,
     * also fremde Links und `mailto:`; `images` = alle Bilder) oder die Kurzform `{links, images}`; ohne sie zählt `inspect`. Nur
     * Ganzzahlen ≥ 0 gelten, sonst `failed` (Zahlen 0, der Dienst behandelt den Beitrag als Prüffall). Nie in einer Transaktion
     * ohne fertige Zählung (parst den Text).
     *
     * @param array<string, mixed>|null $counts
     * @return array{links: int, images: int, attachments: int, failed: bool}
     */
    public function content(string $markdown, int $attachments = 0, ?array $counts = null): array
    {
        $result = $counts;
        if ($result === null) {
            try {
                $result = ($this->inspect)($markdown);
            } catch (\Throwable $e) {
                $this->log('Zählung der Links und Bilder fehlgeschlagen, der Beitrag wird geprüft: {exception}', ['exception' => $e]);
                $result = null;
            }
        }
        $links = null;
        $images = null;
        if (is_array($result)) {
            $links = array_key_exists('external_links', $result) ? $result['external_links'] : ($result['links'] ?? null);
            $images = $result['images'] ?? null;
        }
        $valid = is_int($links) && $links >= 0 && is_int($images) && $images >= 0;

        return ['links' => $valid ? $links : 0, 'images' => $valid ? $images : 0, 'attachments' => max(0, $attachments), 'failed' => !$valid];
    }

    // ── Prüfung vor dem Speichern ────────────────────────────────────────────────────────────────────────────────

    /**
     * Prüfungen nach erlaubtem `can()`, vor `ensure()` und der Transaktion. `$action`: `create_topic`, `reply` oder `edit`.
     *
     * @param array<string, mixed> $input {forum_id, topic_id, post_id (edit), title: ?string, body, verdict (aus filterText),
     *                                     content (aus content()), hosts (Stats.hosts)}
     * @return array{ok: true, review: bool, signals: list<string>, ip_hash: ?string}|array{ok: false, error: string}
     *   Signale: `word_review`, `inspect_failed`, `duplicate`, `filter`; Fehler `word_blocked`, `spam_rejected` (endgültig)
     */
    public function screen(int $userId, string $action, array $input, int $nowTs): array
    {
        if (!in_array($action, self::SCREEN_ACTIONS, true)) {
            throw new \InvalidArgumentException('ContentGuard::screen(): unbekannte Aktion ' . $action);
        }
        $input = self::screenInput($input);
        if ($input['verdict'] === self::BLOCK) {
            return self::failure('word_blocked');
        }
        $hostVerdict = $this->filterHosts($input['hosts'] ?? []);
        if ($hostVerdict === self::BLOCK) {
            return self::failure('word_blocked');
        }
        $client = $this->clientInfo();
        if ($this->antispamRejected($userId, $input, $client)) {
            return self::failure('spam_rejected');
        }

        $signals = [];
        if ($input['verdict'] === self::REVIEW || $hostVerdict === self::REVIEW) {
            $signals[] = 'word_review';
        }
        if ($input['content']['failed']) {
            $signals[] = 'inspect_failed';
        }
        if ($this->isDuplicate($userId, $input['body'], $action === 'edit' ? $input['post_id'] : 0, $nowTs)) {
            $signals[] = 'duplicate';
        }
        if ($this->filterVerdict($userId, $action, $input, $signals) === self::REVIEW && $signals === []) {
            $signals[] = 'filter';
        }

        $ipHash = null;
        if ($action !== 'edit' && $client['web'] && $this->settings->int('ip_hash_days') > 0) {
            $ipHash = $this->ipHash->hash($client['ip']);
        }

        return ['ok' => true, 'review' => $signals !== [], 'signals' => $signals, 'ip_hash' => $ipHash];
    }

    // ── Request-Grenzen ──────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Request-Grenze einer Aktion aus `THROTTLE`. Mitglied (`$userId` > 0): Kennung `community:u:<id>`, Mitgliedergrenze. Gast: Kennung
     * `community:g:<IpHash::rateKey()>`, Gästegrenze; ohne IP oder Geheimnis der gemeinsame Eimer `community:g:unknown`. Gäste der Suche
     * zählen zusätzlich im Eimer `community:g:all`; eine Ablehnung genügt. Keine Klar-IP: `login_attempts.ip_address` bleibt `0.0.0.0`.
     *
     * @return array{allowed: bool, retry_after: int} retry_after = Fenster bei Ablehnung, sonst 0
     */
    public function throttle(string $action, ?int $userId): array
    {
        $rule = self::THROTTLE[$action] ?? throw new \InvalidArgumentException('ContentGuard::throttle(): unbekannte Aktion ' . $action);
        $window = $rule[2];
        if ($userId !== null && $userId > 0) {
            $max = $rule[1];
            $allowed = $this->limit($action, 'community:u:' . $userId, $max, $window);
        } else {
            $max = $rule[0];
            $client = $this->clientInfo();
            $key = $client['ip'] === '' ? null : $this->ipHash->rateKey($client['ip']);
            if ($key === null) {
                self::warnUnknownOnce();
            }
            $subject = $key !== null ? 'community:g:' . $key : 'community:g:unknown';
            $allowed = $this->limit($action, $subject, $max, $window)
                && ($action !== 'search' || $this->limit('search', 'community:g:all', self::SEARCH_GUESTS_TOTAL, 60));
        }

        return ['allowed' => $allowed, 'retry_after' => $allowed ? 0 : $window];
    }

    /** Räumt die Request-Grenzen auf (Cron, stündlich): `community_*` älter als 7.200 s. Wirft nie. */
    public function pruneThrottle(): void
    {
        try {
            Security::pruneDbRateLimitAttempts(self::THROTTLE_PREFIX, self::THROTTLE_KEEP_SECONDS);
        } catch (\Throwable $e) {
            $this->log('Request-Grenzen nicht aufgeräumt: {exception}', ['exception' => $e]);
        }
    }

    // ── Sicherheitsprotokoll ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * Sicherheitsprotokoll für einen Code aus `LOGGED_CODES` (ohne Inhalt), nur mit Web-Anfrage, höchstens `THROTTLE['reject_log']`
     * je Konto und Stunde. Nach dem Commit, nie in einer Transaktion. Wirft nie.
     */
    public function logRejection(string $code, int $userId): void
    {
        if (!in_array($code, self::LOGGED_CODES, true)) {
            return;
        }
        $client = $this->clientInfo();
        if (!$client['web']) {
            return;
        }
        if (!$this->throttle('reject_log', $userId)['allowed']) {
            return;
        }

        try {
            ($this->securityLog)(['code' => $code, 'user_id' => $userId, 'ip' => $client['ip'], 'user_agent' => $client['user_agent']]);
        } catch (\Throwable $e) {
            $this->log('Sicherheitsprotokoll nicht geschrieben: {exception}', ['exception' => $e]);
        }
    }

    // ── Schritte von screen() ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Eingabe von `screen()` in fester Form; ein unbekanntes Urteil gilt als `review`, eine fehlende oder unvollständige Zählung als
     * fehlgeschlagen (fail-closed).
     *
     * @param array<string, mixed> $input
     * @return array{forum_id: int, topic_id: int, post_id: int, title: ?string, body: string, verdict: string, content: array{links: int, images: int, failed: bool}, hosts: list<string>}
     */
    private static function screenInput(array $input): array
    {
        $content = is_array($input['content'] ?? null) ? $input['content'] : null;
        $verdict = $input['verdict'] ?? null;

        return [
            'forum_id' => (int) ($input['forum_id'] ?? 0),
            'topic_id' => (int) ($input['topic_id'] ?? 0),
            'post_id' => (int) ($input['post_id'] ?? 0),
            'title' => is_string($input['title'] ?? null) ? $input['title'] : null,
            'body' => is_string($input['body'] ?? null) ? $input['body'] : '',
            'verdict' => in_array($verdict, [self::ALLOW, self::REVIEW, self::BLOCK], true) ? $verdict : self::REVIEW,
            'content' => [
                'links' => (int) ($content['links'] ?? 0),
                'images' => (int) ($content['images'] ?? 0),
                'failed' => $content === null || ($content['failed'] ?? true) !== false,
            ],
            'hosts' => is_array($input['hosts'] ?? null) ? array_values(array_filter($input['hosts'], 'is_string')) : [],
        ];
    }

    /**
     * Core-Antispam (`use_core_antispam`, nur mit Web-Anfrage). Inhalt: Titel, Text und Hosts ohne unsichtbare Zeichen und ohne die
     * Schemata `http(s)://`/`ftp://` (die Linkgrenze der Community ersetzt `antispam_max_links`; Hostnamen bleiben für die Sperrliste
     * sichtbar). Eine Ausnahme lässt den Beitrag durch (fail-open) und wird protokolliert.
     *
     * @param array{title: ?string, body: string, hosts: list<string>} $input
     * @param array{web: bool, ip: string, user_agent: string} $client
     */
    private function antispamRejected(int $userId, array $input, array $client): bool
    {
        if (!$client['web'] || !$this->settings->bool('use_core_antispam')) {
            return false;
        }
        $account = $this->members->accounts([$userId])[$userId] ?? null;
        $name = trim((string) ($account['display_name'] ?? ''));
        $text = ($input['title'] !== null ? $input['title'] . "\n" : '') . $input['body'] . ($input['hosts'] !== [] ? "\n" . implode("\n", $input['hosts']) : '');
        $context = [
            'content' => (string) preg_replace('~\b(?:https?|ftp)://~i', '', self::stripIgnorable($text)),
            'email' => (string) ($account['email'] ?? ''),
            'author_name' => $name !== '' ? $name : (string) ($account['username'] ?? ''),
            'ip_address' => $client['ip'],
            'user_agent' => $client['user_agent'],
            'source' => 'community',
            'honeypot_value' => '',
            'started_at' => 0,
        ];

        try {
            $result = ($this->antispam)($context);
        } catch (\Throwable $e) {
            $this->log('Core-Antispam nicht verfügbar, der Beitrag läuft weiter: {exception}', ['exception' => $e]);

            return false; // Antispam fail-open
        }

        return is_array($result) && ($result['rejected'] ?? false) === true;
    }

    /**
     * Spam-Dublette (`spam_duplicate_hours`, 0 = aus): gleicher Fingerabdruck in einem der `DUPLICATE_SCAN` jüngsten Beiträge des
     * Mitglieds im Fenster, gleich in welchem Thema. Nicht verglichen werden der bearbeitete Beitrag selbst und vom Autor gelöschte
     * Beiträge; abgelehnte, ausgeblendete, wartende und von der Moderation gelöschte zählen (erneutes Posten entfernten Spams).
     */
    private function isDuplicate(int $userId, string $body, int $postId, int $nowTs): bool
    {
        $hours = $this->settings->int('spam_duplicate_hours');
        if ($hours <= 0) {
            return false;
        }
        $fingerprint = self::fingerprint($body);
        if (mb_strlen($fingerprint, 'UTF-8') < self::DUPLICATE_MIN_CHARS) {
            return false;
        }
        $since = gmdate('Y-m-d H:i:s', $nowTs - $hours * 3600);
        foreach ($this->posts->recentWithBody($userId, $since, self::DUPLICATE_SCAN) as $row) {
            if ($row['id'] === $postId) {
                continue;
            }
            if ((string) $row['status'] === 'deleted' && $row['status_reason'] !== 'moderator') {
                continue;
            }
            if (self::fingerprint($row['body']) === $fingerprint) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter `cms_community_spam_check` (Vertrag §1 Nr. 4): jeder Filter einzeln, das Urteil beginnt mit `review` bei Signalen, sonst
     * `allow`. Gültig ist nur `allow` oder `review`; Lockern (`allow` nach `review`) und Unbekanntes werden verworfen und protokolliert,
     * eine Ausnahme ergibt `review` (fail-closed). Der Filter sieht Titel und Text, speichert nichts.
     *
     * @param array{forum_id: int, topic_id: int, post_id: int, title: ?string, body: string, content: array{links: int, images: int, failed: bool}} $input
     * @param list<string> $signals
     */
    private function filterVerdict(int $userId, string $action, array $input, array $signals): string
    {
        $verdict = $signals === [] ? self::ALLOW : self::REVIEW;
        $payload = [
            'forum_id' => $input['forum_id'], 'topic_id' => $input['topic_id'], 'post_id' => $input['post_id'],
            'title' => $input['title'], 'body' => $input['body'],
            'links' => $input['content']['links'], 'images' => $input['content']['images'], 'signals' => $signals,
        ];
        foreach (Events::filters(Events::SPAM_CHECK) as $filter) {
            try {
                $result = call_user_func($filter, $verdict, $userId, $action, $payload);
            } catch (\Throwable $e) {
                $this->log('Filter {hook} warf eine Ausnahme, der Beitrag wird geprüft: {exception}', ['hook' => Events::SPAM_CHECK, 'exception' => $e]);
                $verdict = self::REVIEW; // Ausnahme im Filter
                continue;
            }
            if ($result !== self::ALLOW && $result !== self::REVIEW) {
                $this->log('Filter {hook} lieferte weder allow noch review und wurde ignoriert.', ['hook' => Events::SPAM_CHECK]);
                continue;
            }
            if ($verdict === self::REVIEW && $result === self::ALLOW) {
                $this->log('Filter {hook} wollte das Urteil lockern und wurde ignoriert.', ['hook' => Events::SPAM_CHECK]);
                continue;
            }
            $verdict = $result;
        }

        return $verdict;
    }

    // ── Hilfen ───────────────────────────────────────────────────────────────────────────────────────────────────

    /** Fingerabdruck der Spam-Dublette: Vergleichsfassung, Leerraum zu einem Leerzeichen. */
    private static function fingerprint(string $body): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', self::fold($body)));
    }

    /** Entfernt die unsichtbaren Zeichen der Vergleichsfassung (ohne NFKC und Faltung; Antispam-Inhalt). */
    private static function stripIgnorable(string $text): string
    {
        return (string) preg_replace(self::IGNORABLE, '', $text);
    }

    /** Gibt es mindestens einen Eintrag im Wortfilter? */
    private function hasEntries(): bool
    {
        foreach ($this->patterns() as [$original]) {
            if ($original !== []) {
                return true;
            }
        }

        return false;
    }

    /** Treffer einer Liste im Original oder in der Vergleichsfassung; wirft `\UnexpectedValueException` bei einem PCRE-Fehler. */
    private function hits(string $list, string $text, string $folded): bool
    {
        [$originalPatterns, $foldedPatterns] = $this->patterns()[$list];

        return self::matchesAny($originalPatterns, $text) || self::matchesAny($foldedPatterns, $folded);
    }

    /** Ersetzt jeden Treffer der replace-Liste im Original durch den Ersatztext (ein Durchlauf je Block). */
    private function replace(string $text, bool &$replaced): string
    {
        $replacement = $this->replacement;
        foreach ($this->patterns()['replace'][0] as $pattern) {
            $count = 0;
            $result = preg_replace_callback($pattern, static fn (): string => $replacement, $text, -1, $count);
            if (!is_string($result)) {
                throw new \UnexpectedValueException('PCRE: ' . preg_last_error_msg());
            }
            $text = $result;
            $replaced = $replaced || $count > 0;
        }

        return $text;
    }

    /** @param list<string> $patterns */
    private static function matchesAny(array $patterns, string $text): bool
    {
        foreach ($patterns as $pattern) {
            $hit = preg_match($pattern, $text);
            if ($hit === false) {
                throw new \UnexpectedValueException('PCRE: ' . preg_last_error_msg());
            }
            if ($hit === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Muster des Wortfilters (einmal je Instanz): je Liste die Einträge wie gespeichert und in der Vergleichsfassung.
     *
     * @return array<string, array{0: list<string>, 1: list<string>}>
     */
    private function patterns(): array
    {
        if ($this->patterns === null) {
            $filter = $this->settings->map('word_filter');
            $this->replacement = is_string($filter['replacement'] ?? null) && $filter['replacement'] !== '' ? $filter['replacement'] : '***';
            $patterns = [];
            foreach (Settings::WORD_FILTER_LISTS as $list) {
                $entries = array_values(array_filter(is_array($filter[$list] ?? null) ? $filter[$list] : [], 'is_string'));
                $patterns[$list] = [self::compile($entries), self::compile(array_map(static fn (string $entry): string => self::fold($entry), $entries))];
            }
            $this->patterns = $patterns;
        }

        return $this->patterns;
    }

    /**
     * Muster je Block von höchstens `WORD_BLOCK` Einträgen: Ganzwort, Leerzeichen trifft beliebigen Leerraum, `*` am Ende ist ein Präfix.
     *
     * @param list<string> $entries
     * @return list<string>
     */
    private static function compile(array $entries): array
    {
        $alternatives = [];
        foreach (array_unique($entries) as $entry) {
            $prefix = str_ends_with($entry, '*');
            $core = $prefix ? substr($entry, 0, -1) : $entry;
            if ($core === '') {
                continue;
            }
            $alternatives[] = str_replace(' ', '\s+', preg_quote($core, '/')) . ($prefix ? '[\p{L}\p{N}_]*' : '');
        }
        $patterns = [];
        foreach (array_chunk($alternatives, self::WORD_BLOCK) as $chunk) {
            $patterns[] = '/(?<![\p{L}\p{N}_])(?:' . implode('|', $chunk) . ')(?![\p{L}\p{N}_])/iu';
        }

        return $patterns;
    }

    /**
     * Client der Anfrage in fester Form: `web` nur bei `true`, IP nur gültig und nicht `0.0.0.0` (sonst `''`), User-Agent ohne
     * Steuerzeichen, höchstens 500 Zeichen.
     *
     * @return array{web: bool, ip: string, user_agent: string}
     */
    private function clientInfo(): array
    {
        try {
            $client = ($this->client)();
        } catch (\Throwable $e) {
            $this->log('Client der Anfrage nicht lesbar: {exception}', ['exception' => $e]);
            $client = null;
        }
        $client = is_array($client) ? $client : [];
        $ip = is_string($client['ip'] ?? null) ? trim($client['ip']) : '';

        return [
            'web' => ($client['web'] ?? false) === true,
            'ip' => $ip !== '0.0.0.0' && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '',
            'user_agent' => self::cleanText(is_string($client['user_agent'] ?? null) ? $client['user_agent'] : '', 500),
        ];
    }

    /** Eine Request-Grenze über `$limiter`; ein Fehler lässt die Anfrage durch (fail-open wie der Core, Vertrag §3.13). */
    private function limit(string $action, string $subject, int $max, int $window): bool
    {
        try {
            return ($this->limiter)(self::THROTTLE_PREFIX . $action, $subject, $max, $window) === true;
        } catch (\Throwable $e) {
            $this->log('Request-Grenze {action} nicht prüfbar, die Anfrage läuft weiter: {exception}', ['action' => $action, 'exception' => $e]);

            return true;
        }
    }

    /**
     * Standard von `$securityLog`: ein Eintrag in `security_log` (`antispam_rejected`, wie AntispamService), ohne `created_at`
     * (Sitzungszeit des Cores wie alle Einträge), Pfad ohne Query, IP oder NULL.
     *
     * @param array<string, mixed> $entry
     */
    private function writeSecurityLog(array $entry): void
    {
        $code = (string) ($entry['code'] ?? '');
        $ip = (string) ($entry['ip'] ?? '');
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $userId = (int) ($entry['user_id'] ?? 0);
        $this->db->execute(
            'INSERT INTO ' . $this->db->getPrefix() . 'security_log (action, ip_address, request_uri, user_agent, rule_matched, user_id, extra) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                'antispam_rejected',
                filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
                self::cleanText(is_string($path) && $path !== '' ? $path : '/', 1000),
                self::cleanText((string) ($entry['user_agent'] ?? ''), 500),
                'community:' . $code,
                $userId > 0 ? $userId : null,
                (string) json_encode(['reason' => $code, 'source' => 'community'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    /** Text für Protokollspalten: gültiges UTF-8, Steuerzeichen als Leerzeichen, gekürzt auf `$max` Zeichen. */
    private static function cleanText(string $value, int $max): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', mb_scrub($value, 'UTF-8')));

        return mb_substr($value, 0, $max, 'UTF-8');
    }

    /** @return array{ok: false, error: string} */
    private static function failure(string $code): array
    {
        return ['ok' => false, 'error' => $code];
    }

    private static function warnUnknownOnce(): void
    {
        if (self::$unknownWarned) {
            return;
        }
        self::$unknownWarned = true;
        Logger::instance()->withChannel('community')->warning('Request-Grenze für Gäste ohne IP oder ohne Geheimnis: gemeinsamer Eimer community:g:unknown.');
    }

    /** @param array<string, mixed> $context */
    private function log(string $message, array $context): void
    {
        Logger::instance()->withChannel('community')->warning($message, $context);
    }
}
