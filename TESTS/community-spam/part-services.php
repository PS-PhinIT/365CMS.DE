<?php
/**
 * Dienste mit Schutzschichten (PR #2b, Nachlauf; Vertrag DESIGN-2b §4.1 Schritt 11, §4.2 Schritt 7; SECURITY-PRIVACY §2): `TopicService::create()`,
 * `PostService::reply()` und `PostService::edit()` rufen den `ContentGuard` über `WriteGate` auf, auf demselben SQLite-Double mit echter
 * `CommunityAccess` und echtem `ContentGuard` (Fakes nur für Antispam, Client, Grenzen und Sicherheitsprotokoll). Prüfkürzel S8: `review` ⇒ `pending`.
 *
 * Ohne „Wirkung:“ (die Wirkungstests der Einstellungen stehen in `run.php`). Die Datei wird von `run.php` geladen (`part-*.php`).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\ContentGuard;
use CMS\Services\Community\Service\PostService;
use CMS\Services\Community\Service\TopicService;
use CMS\Services\Community\Support\ContentRenderer;
use CMS\Services\Community\Support\IpHash;

/**
 * Welt der Dienste: Mitglied „anna“ (Stufe 1 bei bestätigter E-Mail, sonst Stufe 0), echte `CommunityAccess`, echter `ContentGuard`
 * (Fakes für Antispam, Client, Grenzen, Sicherheitsprotokoll mit Merker „in einer Transaktion“), TopicService und PostService mit Guard.
 */
final class SvWorld
{
    public SpWorld $sp;
    public CommunityTestDatabase $db;
    public ContentGuard $guard;
    public TopicService $topics;
    public PostService $posts;
    public TopicService $plainTopics;
    public PostService $plainPosts;
    public int $user;
    public int $forum;
    /** Versatz der Uhr in Sekunden zu `spNow()`. */
    public int $offset = 0;
    public bool $verified = true;
    /** @var array<string, mixed> */
    public array $antispamResult = ['rejected' => false, 'reason' => ''];
    /** @var list<array<string, mixed>> */
    public array $antispamCalls = [];
    /** @var list<array{entry: array<string, mixed>, in_transaction: bool}> */
    public array $securityLog = [];
    /** @var list<array{action: string, context: array<string, mixed>}> */
    public array $canLog = [];

    /** @param array<string, mixed> $settings */
    public function __construct(array $settings = [])
    {
        $this->sp = new SpWorld($settings + ['new_member_approval_posts' => 0, 'trust_level1_posts' => 0, 'require_verified_email' => false, 'rate_post_seconds' => 0, 'rate_topic_seconds' => 0]);
        $this->db = $this->sp->db;
        $this->user = $this->sp->user;
        $this->forum = $this->sp->forum;
        $settingsObject = $this->sp->settings;
        $this->guard = new ContentGuard(
            $this->db,
            $settingsObject,
            null,
            function (array $context): array {
                $this->antispamCalls[] = $context;

                return $this->antispamResult;
            },
            static fn (): array => SP_WEB,
            static fn (string $name, string $subject, int $max, int $window): bool => true,
            function (array $entry): void {
                $this->securityLog[] = ['entry' => $entry, 'in_transaction' => $this->db->getPdo()->inTransaction()];
            },
            new IpHash(static fn (): string => SP_SECRET)
        );
        $clock = fn (): int => spNow($this->offset);
        $access = new CommunityAccess(
            $this->db,
            $settingsObject,
            static fn (string $role, string $capability): bool => $role === 'admin' || in_array($capability, ['community.view', 'community.participate'], true),
            static fn (): bool => true,
            $clock,
            fn (int $userId): bool => $this->verified
        );
        $can = function (?int $userId, string $action, array $context) use ($access): AccessDecision {
            $this->canLog[] = ['action' => $action, 'context' => $context];

            return $access->can($userId, $action, $context);
        };
        $dispatch = static function (string $hook, mixed ...$args): void {
        };
        $this->topics = new TopicService($this->db, $settingsObject, $clock, $can, $dispatch, fn (): ContentGuard => $this->guard);
        $this->posts = new PostService($this->db, $settingsObject, $clock, $can, $dispatch, fn (): ContentGuard => $this->guard);
        $this->plainTopics = new TopicService($this->db, $settingsObject, $clock, $can, $dispatch);
        $this->plainPosts = new PostService($this->db, $settingsObject, $clock, $can, $dispatch);
    }

    /** @return array<string, mixed>|null */
    public function row(string $table, int $id): ?array
    {
        return $this->db->row($table, ['id' => $id]);
    }

    public function count(string $table): int
    {
        return count($this->db->rows($table, [], 'id', 100000));
    }

    /** Beitrag des Mitglieds, `$age` Sekunden alt, im Thema `$topic` (Standard: das erste Thema der Welt). */
    public function post(string $body, int $age = 60, array $o = []): int
    {
        return $this->sp->post($body, $age, $o);
    }

    /** Codes der Protokolleinträge. */
    public function loggedCodes(): array
    {
        return array_map(static fn (array $row): string => (string) $row['entry']['code'], $this->securityLog);
    }
}

const SV_TWO_LINKS = "Zwei Links: [a](https://one.example/a) und [b](https://two.example/b).";

T::run('Dienste: Anlegen, Antworten und Bearbeiten ohne Auffälligkeit (ip_hash nur beim Anlegen und Antworten, nie beim Bearbeiten)', static function (): void {
    $w = new SvWorld();
    $topic = $w->topics->create($w->user, $w->forum, 'Ein ordentliches Thema', 'Ein ganz gewöhnlicher Beitrag ohne Auffälligkeiten.');
    T::same([true, 'published'], [$topic['success'] ?? null, $topic['status'] ?? null], 'Thema veröffentlicht');
    $hash = spExpectedHash('203.0.113.0/24');
    T::same($hash, $w->row('community_posts', (int) $topic['post_id'])['ip_hash'] ?? null, 'ip_hash des Themenstarts: HMAC des /24-Netzes (S, ip_hash_days > 0)');

    $reply = $w->posts->reply($w->user, (int) $topic['topic_id'], 'Eine Antwort ohne Auffälligkeiten im Thema.');
    T::same([true, 'published', $hash], [$reply['success'] ?? null, $reply['status'] ?? null, $w->row('community_posts', (int) ($reply['post_id'] ?? 0))['ip_hash'] ?? null], 'Antwort veröffentlicht, ip_hash gespeichert');

    $w->offset = 60;
    $edit = $w->posts->edit($w->user, (int) $reply['post_id'], 'Eine geänderte Antwort ohne Auffälligkeiten im Thema.');
    $stored = $w->row('community_posts', (int) $reply['post_id']);
    T::same([true, true, $hash], [$edit['success'] ?? null, $edit['changed'] ?? null, $stored['ip_hash'] ?? null], 'Bearbeiten: gespeichert, ip_hash bleibt (wird nie neu gesetzt)');
    T::same([], $w->securityLog, 'kein Sicherheitsprotokoll für erlaubte Beiträge');

    $none = new SvWorld(['ip_hash_days' => 0]);
    $created = $none->topics->create($none->user, $none->forum, 'Ein ordentliches Thema', 'Ein ganz gewöhnlicher Beitrag ohne Auffälligkeiten.');
    T::same(null, $none->row('community_posts', (int) $created['post_id'])['ip_hash'], 'ip_hash_days = 0: kein ip_hash');
});

T::run('Dienste: Wortfilter block (Titel und Text, alle Wege) ⇒ word_blocked, nichts geschrieben, Sicherheitsprotokoll nach dem Ende', static function (): void {
    $w = new SvWorld(spFilter(['Schlechtwort']));
    $topicsBefore = $w->count('community_topics');
    $postsBefore = $w->count('community_posts');
    $title = $w->topics->create($w->user, $w->forum, 'Ein Schlechtwort im Titel', 'Ein ganz gewöhnlicher Beitrag ohne Auffälligkeiten.');
    $body = $w->topics->create($w->user, $w->forum, 'Ein ordentliches Thema', 'Ein Beitrag mit einem Schlechtwort darin, lang genug.');
    $reply = $w->posts->reply($w->user, $w->sp->topics[1], 'Eine Antwort mit einem Schlechtwort darin, lang genug.');
    $postId = $w->post('Ein Beitrag zum Bearbeiten, lang genug für alles.', 60, ['topic' => $w->sp->topics[1]]);
    $postsBefore++;
    $edit = $w->posts->edit($w->user, $postId, 'Geändert mit einem Schlechtwort darin, lang genug.');
    T::same(
        [['success' => false, 'error' => 'word_blocked'], ['success' => false, 'error' => 'word_blocked'], ['success' => false, 'error' => 'word_blocked'], ['success' => false, 'error' => 'word_blocked']],
        [$title, $body, $reply, $edit],
        'Titel, Text, Antwort und Bearbeiten: word_blocked'
    );
    T::same([$topicsBefore, $postsBefore], [$w->count('community_topics'), $w->count('community_posts')], 'es wurde nichts geschrieben');
    T::same('Ein Beitrag zum Bearbeiten, lang genug für alles.', $w->row('community_posts', $postId)['body'] ?? null, 'der Beitrag blieb unverändert');
    T::same(array_fill(0, 4, 'word_blocked'), $w->loggedCodes(), 'vier Einträge im Sicherheitsprotokoll (Code word_blocked)');
    T::same(array_fill(0, 4, false), array_column($w->securityLog, 'in_transaction'), 'das Sicherheitsprotokoll wird außerhalb einer Transaktion geschrieben');

    $plain = $w->plainTopics->create($w->user, $w->forum, 'Ein ordentliches Thema', 'Ein Beitrag mit einem Schlechtwort darin, lang genug.');
    T::same(true, $plain['success'] ?? null, 'Gegenprobe: derselbe Dienst ohne Guard prüft nichts (Standard des Konstruktors)');
});

T::run('Dienste: Wortfilter replace (Ersatz wird gespeichert, Längen werden erneut geprüft) und review ⇒ pending (S8)', static function (): void {
    $w = new SvWorld(spFilter([], ['Verdacht'], ['Dummkopf'], '#') + ['title_min' => 10]);
    $created = $w->topics->create($w->user, $w->forum, 'Du Dummkopf, hallo Welt', 'Du bist ein Dummkopf und das ist der Beitrag.');
    T::same([true, 'published'], [$created['success'] ?? null, $created['status'] ?? null], 'replace: veröffentlicht');
    T::same(['Du #, hallo Welt', 'Du bist ein # und das ist der Beitrag.'], [$w->row('community_topics', (int) $created['topic_id'])['title'] ?? null, $w->row('community_posts', (int) $created['post_id'])['body'] ?? null], 'Titel und Text werden ersetzt gespeichert');
    $short = $w->topics->create($w->user, $w->forum, 'Dummkopf Dummkopf', 'Ein ganz gewöhnlicher Beitrag ohne Auffälligkeiten.');
    T::same(['success' => false, 'error' => 'title_too_short'], $short, 'ersetzter Titel unter title_min (10): title_too_short');

    $counted = (int) ($w->row('community_forums', $w->forum)['topic_count'] ?? -1);
    $pending = $w->topics->create($w->user, $w->forum, 'Ein Thema mit Verdacht drin', 'Ein ganz gewöhnlicher Beitrag ohne Auffälligkeiten.');
    $topic = $w->row('community_topics', (int) $pending['topic_id']);
    T::same(['pending', 'pending', $counted], [$pending['status'] ?? null, $topic['status'] ?? null, (int) ($w->row('community_forums', $w->forum)['topic_count'] ?? -1)], 'review-Wort im Titel: Thema wartend, Forumzähler unverändert');
    T::same('pending', $w->row('community_posts', (int) $pending['post_id'])['status'] ?? null, 'erster Beitrag wartend');

    $w->offset = 3600;
    $reply = $w->posts->reply($w->user, $w->sp->topics[1], 'Eine Antwort mit Verdacht darin, lang genug dafür.');
    T::same(['pending', true, null], [$reply['status'] ?? null, array_key_exists('position', $reply), $reply['position'] ?? null], 'review-Wort in der Antwort: wartend ohne Position');
    $ok = $w->posts->reply($w->user, $w->sp->topics[1], 'Eine unauffällige Antwort im selben Thema, lang genug.');
    T::same('published', $ok['status'] ?? null, 'Gegenprobe: ohne Auffälligkeit veröffentlicht');
    T::same([], $w->securityLog, 'review ist kein Fehler: keine Protokolleinträge');
});

T::run('Dienste: Core-Antispam (spam_rejected), Spam-Dublette (pending) und Filter cms_community_spam_check', static function (): void {
    $w = new SvWorld(['use_core_antispam' => true]);
    $w->antispamResult = ['rejected' => true, 'reason' => 'links'];
    $before = $w->count('community_posts');
    $rejected = $w->posts->reply($w->user, $w->sp->topics[1], 'Eine Antwort, die der Core-Antispam ablehnt, lang genug.');
    T::same([['success' => false, 'error' => 'spam_rejected'], $before, 1], [$rejected, $w->count('community_posts'), count($w->antispamCalls)], 'spam_rejected: nichts geschrieben, Antispam einmal gefragt');
    $w->antispamResult = ['rejected' => false, 'reason' => ''];

    $first = $w->posts->reply($w->user, $w->sp->topics[1], SP_LONG);
    $w->offset = 3600;
    $second = $w->posts->reply($w->user, $w->sp->topics[0], SP_LONG);
    T::same(['published', 'pending'], [$first['status'] ?? null, $second['status'] ?? null], 'derselbe lange Text im anderen Thema binnen spam_duplicate_hours: wartend (Signal duplicate)');
    $w->offset = 3600 + 25 * 3600;
    $later = $w->posts->reply($w->user, $w->sp->topics[1], SP_LONG);
    T::same('published', $later['status'] ?? null, 'nach dem Fenster wieder veröffentlicht');
});

T::run('Dienste: Link- und Bildgrenzen der Stufe über content (too_many_links, too_many_images) mit Protokoll, content_before beim Bearbeiten', static function (): void {
    $w = new SvWorld();
    $w->verified = false; // Stufe 0: ein Link, kein Bild
    $links = $w->topics->create($w->user, $w->forum, 'Ein Thema mit Links', SV_TWO_LINKS);
    $images = $w->posts->reply($w->user, $w->sp->topics[1], 'Ein Bild: ![x](https://img.example/a.png) im Text, lang genug.');
    $one = $w->posts->reply($w->user, $w->sp->topics[1], 'Genau ein Link: [a](https://one.example/a) im Text, lang genug.');
    T::same(
        [['success' => false, 'error' => 'too_many_links'], ['success' => false, 'error' => 'too_many_images'], 'published'],
        [$links, $images, $one['status'] ?? null],
        'Stufe 0: zwei Links und ein Bild abgelehnt, ein Link erlaubt'
    );
    T::same(['too_many_links', 'too_many_images'], $w->loggedCodes(), 'Protokolleinträge mit dem Grund');
    $withContent = array_filter($w->canLog, static fn (array $call): bool => isset($call['context']['content']) && $call['action'] === 'create_topic' && empty($call['context']['fresh']));
    T::check($withContent !== [], 'can() bekam content (Zählung) im Kontext');

    $level1 = new SvWorld(); // Stufe 1: fünf Links
    $okLinks = $level1->topics->create($level1->user, $level1->forum, 'Ein Thema mit Links', SV_TWO_LINKS);
    T::same('published', $okLinks['status'] ?? null, 'Stufe 1: zwei Links erlaubt');

    // Bearbeiten: schon vorhandener Inhalt zählt nicht doppelt (content_before), neuer Inhalt über der Grenze wird abgelehnt.
    $w->verified = false;
    $postId = $w->post(SV_TWO_LINKS, 60, ['topic' => $w->sp->topics[1]]);
    $kept = $w->posts->edit($w->user, $postId, "Zwei Links, anders formuliert: [a](https://one.example/a) und [b](https://two.example/b).");
    $more = $w->posts->edit($w->user, $postId, "Drei Links: [a](https://one.example/a) [b](https://two.example/b) [c](https://three.example/c).");
    T::same([true, ['success' => false, 'error' => 'too_many_links']], [$kept['success'] ?? null, $more], 'Bearbeiten: Vorfassung mit zwei Links bleibt erlaubt, ein dritter Link nicht');
});

T::run('Dienste: normalisierte Hosts gegen den Wortfilter (evil%2Ecom trifft evil.com) und fehlgeschlagene Zählung ⇒ pending', static function (): void {
    $w = new SvWorld(spFilter(['evil.com']));
    $blocked = $w->posts->reply($w->user, $w->sp->topics[1], 'Bitte hier klicken: [Angebot](https://evil%2Ecom/x) und mehr Text.');
    T::same(['success' => false, 'error' => 'word_blocked'], $blocked, 'Host im Link: word_blocked (screen() prüft Stats.hosts)');

    $plain = new SvWorld(['post_max' => 30000]);
    $failed = $plain->posts->reply($plain->user, $plain->sp->topics[1], 'Text ' . str_repeat('*', ContentRenderer::MAX_DELIMITERS + 1000));
    T::same(true, $failed['success'] ?? null, 'Grenze des Renderers überschritten: Beitrag wird gespeichert');
    T::same('pending', $failed['status'] ?? null, 'fehlgeschlagene Zählung ergibt das Signal inspect_failed: wartend (fail-closed)');
});

T::run('Dienste: Bearbeiten mit Prüffall (review) ⇒ content_review bei veröffentlichten, erlaubt bei wartenden Beiträgen; Ersatz beim Bearbeiten', static function (): void {
    $w = new SvWorld(spFilter([], ['Verdacht'], ['Dummkopf'], '#'));
    $published = $w->post('Ein veröffentlichter Beitrag zum Bearbeiten, lang genug.', 60, ['topic' => $w->sp->topics[1]]);
    $pending = $w->post('Ein wartender Beitrag zum Bearbeiten, lang genug dafür.', 60, ['topic' => $w->sp->topics[1], 'status' => 'pending']);
    $denied = $w->posts->edit($w->user, $published, 'Nun mit Verdacht im Text, lang genug für alles.');
    T::same(['success' => false, 'error' => 'content_review'], $denied, 'veröffentlichter Beitrag: review lehnt das Bearbeiten ab (content_review)');
    T::same('Ein veröffentlichter Beitrag zum Bearbeiten, lang genug.', $w->row('community_posts', $published)['body'] ?? null, 'Text unverändert');
    T::same(['content_review'], $w->loggedCodes(), 'Protokolleintrag content_review');
    $allowed = $w->posts->edit($w->user, $pending, 'Nun mit Verdacht im Text, lang genug für alles.');
    T::same([true, 'pending'], [$allowed['success'] ?? null, $w->row('community_posts', $pending)['status'] ?? null], 'wartender Beitrag: Bearbeiten erlaubt, bleibt wartend');
    $replaced = $w->posts->edit($w->user, $published, 'Du Dummkopf, das ist der geänderte Beitrag, lang genug.');
    T::same([true, 'Du #, das ist der geänderte Beitrag, lang genug.'], [$replaced['success'] ?? null, $w->row('community_posts', $published)['body'] ?? null], 'replace beim Bearbeiten: Ersatz gespeichert');
});

T::run('Dienste: ohne Guard unverändert (kein content im Kontext, kein ip_hash), instance() verdrahtet ContentGuard::instance(), nur ModerationService baut Dienste ohne', static function (): void {
    $w = new SvWorld(spFilter(['Schlechtwort']));
    $w->canLog = [];
    $plain = $w->plainTopics->create($w->user, $w->forum, 'Ein Thema mit Schlechtwort', 'Ein ganz gewöhnlicher Beitrag ohne Auffälligkeiten.');
    T::same([true, null], [$plain['success'] ?? null, $w->row('community_posts', (int) $plain['post_id'])['ip_hash']], 'ohne Guard: gespeichert, ip_hash NULL');
    T::same([false, false], [isset($w->canLog[0]['context']['content']), array_key_exists('review', $w->canLog[1]['context'] ?? [])], 'ohne Guard: kein content und kein review im Kontext von can()');
    foreach (['TopicService', 'PostService'] as $class) {
        $source = (string) file_get_contents(ABSPATH . 'core/Services/Community/Service/' . $class . '.php');
        T::check(str_contains($source, 'return self::$instance ??= new self(guard: static fn (): ContentGuard => ContentGuard::instance());'), $class . '::instance() übergibt ContentGuard::instance()');
    }
    $offenders = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ABSPATH, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/assets/')) {
            continue;
        }
        if (preg_match('/new\s+(?:\\\\?CMS\\\\Services\\\\Community\\\\Service\\\\)?(?:TopicService|PostService)\s*\(/', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = substr($file->getPathname(), strlen(ABSPATH));
        }
    }
    sort($offenders);
    T::same(['core/Services/Community/Service/ModerationService.php'], $offenders, 'Produktivcode baut TopicService und PostService mit new nur in ModerationService (Löschen); sonst nur über instance() (mit Guard)');
});
