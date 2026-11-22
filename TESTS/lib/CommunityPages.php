<?php
/**
 * Seiten der Community gegen die SQLite-Attrappe (PR #5): die echten Controller (`PublicController`, `ComposeController`) laufen mit den
 * echten Diensten und Vorlagen auf Testdaten; statt Theme und Ausgabe nimmt die Prüfstelle (`PublicController::setSink()`) Ansicht,
 * Variablen und Kopfdaten entgegen. Gemeinsame Grundlage der Suiten `community-performance` (Abfragebudget), `community-a11y` und
 * `community-lighthouse` (Seiten als HTML für Browser, axe-core und Lighthouse).
 *
 * - `CommunityPages::create()` baut die Welt (Konten, Forum mit Unterforum, Themen mit Antworten, Reaktionen, angeheftetes, geschlossenes
 *   und gelöstes Thema, ein Thema mit mehreren Seiten) und verdrahtet Datenbank, Einstellungen, Modul-Schalter und Dienste.
 * - `request()` führt eine Seitenanfrage wie ein neuer PHP-Prozess aus: alle Dienste und Zwischenspeicher der Instanzen sind frisch, die
 *   Datenbank bleibt; es liefert Ansicht, Variablen, Kopfdaten, Status und die Abfragen (SQL und Parameter).
 * - `html()` setzt die echte Vorlage in ein minimales Dokument (Sprache, Titel, Kopfangaben, Sprunglink, Kopf, `<main>`, Fuß wie im Standard-Theme), das die Browser-Prüfungen laden.
 * - `close()` stellt alle ersetzten Singletons wieder her.
 *
 * Die Beiträge sind schon gerendert (Zwischenspeicher `body_html` warm), damit die Abfragezahl dem Dauerbetrieb entspricht und nicht dem
 * ersten Aufruf je Beitrag (der schreibt das gerenderte HTML zurück).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Auth;
use CMS\Database;
use CMS\Services\Community\CommunityMemberSection;
use CMS\Services\Community\Http\ComposeController;
use CMS\Services\Community\Http\PublicController;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\CommunitySearch;
use CMS\Services\Community\Service\PostService;
use CMS\Services\Community\Service\ReactionService;
use CMS\Services\Community\Service\ReadStateService;
use CMS\Services\Community\Service\RenderService;
use CMS\Services\Community\Service\SubscriptionService;
use CMS\Services\Community\Service\TopicService;
use CMS\Services\Community\Support\CommunityModule;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Urls;

// Hilfsfunktionen des Themes und der Website, die Controller und Kopfangaben aufrufen (`cms_asset_url()`).
if (!defined('SITE_URL')) {
    define('SITE_URL', 'https://example.test');
}
require_once ABSPATH . 'includes/functions/options-runtime.php';

final class CommunityPages
{
    /** Dienste mit Singleton, die eine Anfrage frisch aufbaut (wie ein neuer Prozess). */
    private const SINGLETONS = [
        TopicService::class, PostService::class, RenderService::class, ReadStateService::class,
        ReactionService::class, SubscriptionService::class, CommunitySearch::class,
    ];

    public CommunityTestDatabase $db;

    /** @var array<string, int> */
    public array $ids = [];

    /** @var array<string, array<string, array<string, callable>>> Methode → Muster → Handler */
    private array $routes = [];

    /** @var array<class-string, mixed> */
    private array $previous = [];

    /** @var array{view: string, vars: array<string, mixed>, meta: array<string, mixed>, status: int}|null */
    private ?array $captured = null;

    private function __construct()
    {
    }

    /**
     * @param array{topics?: int, replies?: int} $size Themen im Hauptforum und Antworten je Thema (Standard 24 und 6; das lange Thema hat immer 40)
     * @param array<string, mixed> $settings Einstellungen über die Testvorgaben hinaus (z. B. `posts_per_page`)
     */
    public static function create(array $size = [], array $settings = []): self
    {
        $w = new self();
        $w->db = CommunityTestDatabase::create();
        // Tabelle der Request-Grenzen des Cores (Suche und Schreibwege zählen dort): ohne sie scheiterten die Zählabfragen der Seiten.
        foreach ([
            'CREATE TABLE cms_login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, ip_address TEXT, action TEXT NOT NULL DEFAULT \'login\', attempted_at TEXT DEFAULT CURRENT_TIMESTAMP)',
            'CREATE INDEX idx_username ON cms_login_attempts (username)',
            'CREATE INDEX idx_action ON cms_login_attempts (action)',
            'CREATE INDEX idx_ip_action ON cms_login_attempts (ip_address, action)',
            'CREATE INDEX idx_time ON cms_login_attempts (attempted_at)',
        ] as $ddl) {
            $w->db->getPdo()->exec($ddl);
        }
        CommunityModule::setGate(static fn (): bool => true);
        $w->previous[Database::class] = CommunityWiring::swap(Database::class, $w->db);
        $w->previous[Settings::class] = CommunityWiring::swap(Settings::class, Settings::fromArray($settings + ['guests_can_read' => true, 'require_verified_email' => false]));
        $w->seed((int) ($size['topics'] ?? 24), (int) ($size['replies'] ?? 6));
        $w->wire();
        $w->warm();

        return $w;
    }

    public function close(): void
    {
        PublicController::setSink(null);
        foreach ($this->previous as $class => $value) {
            CommunityWiring::swap($class, $value);
        }
        $this->previous = [];
        CommunityModule::setGate(null);
        $_GET = [];
        $_POST = [];
    }

    // ── Anfragen ────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Seitenanfrage `GET $path` als `$viewer` (`null` = Gast; Schlüssel aus `ids`, z. B. `member`, `admin`).
     *
     * @param array<string, string> $query Abfrageparameter (`$_GET`)
     * @param bool $fresh true (Standard): Anfrage in einem neuen Prozess (Dienste und ihre Zwischenspeicher frisch); false: im selben Prozess nach
     *                    einer früheren Anfrage (warme Zwischenspeicher der Dienstinstanzen)
     * @return array{view: string, vars: array<string, mixed>, meta: array<string, mixed>, status: int, queries: list<string>, params: list<list<mixed>>, writes: int}
     */
    public function request(string $path, ?string $viewer = null, array $query = [], bool $fresh = true): array
    {
        $this->asViewer($viewer);
        if ($fresh) {
            $this->freshProcess();
        }
        $_GET = $query;
        $_POST = [];
        $_SERVER['REQUEST_URI'] = $path . ($query === [] ? '' : '?' . http_build_query($query));
        $this->captured = null;
        PublicController::setSink(function (string $view, array $vars, array $meta, int $status): void {
            $this->captured = ['view' => $view, 'vars' => $vars, 'meta' => $meta, 'status' => $status];
        });
        $before = count($this->db->log);
        // Im Prüflauf ist die Ausgabe der Suite schon gesendet: `http_response_code()` der Controller meldet das; sonst zählt jeder Fehler.
        $outer = null;
        $outer = set_error_handler(static function (int $no, string $message, string $file = '', int $line = 0) use (&$outer): bool {
            if (str_contains($message, 'headers already sent')) {
                return true;
            }

            return is_callable($outer) ? (bool) $outer($no, $message, $file, $line) : false;
        });
        try {
            $this->dispatch($path);
        } finally {
            restore_error_handler();
            PublicController::setSink(null);
        }
        $queries = array_slice($this->db->log, $before);
        $params = array_slice($this->db->logParams, $before, null, false);
        $writes = count(array_filter($queries, static fn (string $sql): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)/i', $sql) === 1));
        if ($this->captured === null) {
            throw new \RuntimeException('Keine Seite für ' . $path . ' ausgegeben.');
        }

        return $this->captured + ['queries' => $queries, 'params' => array_values($params), 'writes' => $writes];
    }

    /**
     * Reiter des Community-Bereichs im Mitgliederbereich (`CommunityMemberSection::render()`), als Ausgabe des Reiters und Abfragen.
     *
     * @return array{html: string, queries: list<string>, params: list<list<mixed>>, writes: int}
     */
    public function member(string $tab, string $viewer = 'member'): array
    {
        $this->asViewer($viewer);
        $this->freshProcess();
        $_GET = ['tab' => $tab];
        $_POST = [];
        $user = Auth::instance()->getCurrentUser();
        $before = count($this->db->log);
        ob_start();
        try {
            CommunityMemberSection::render($user);
        } finally {
            $html = (string) ob_get_clean();
        }
        $queries = array_slice($this->db->log, $before);

        return [
            'html' => $html, 'queries' => $queries, 'params' => array_values(array_slice($this->db->logParams, $before, null, false)),
            'writes' => count(array_filter($queries, static fn (string $sql): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)/i', $sql) === 1)),
        ];
    }

    /**
     * Der Reiter als HTML-Dokument für den Browser: im Rahmen des Themes mit Überschrift und Karte mit Titel wie `member/plugin-section.php`.
     *
     * @param array{html: string} $page
     */
    public function memberHtml(array $page, string $assetBase = '/assets/', string $theme = 'standard'): string
    {
        $body = "<h1>Mein Bereich</h1>\n" . '<div class="card"><div class="card-header"><h2 class="card-title">Community</h2></div><div class="card-body">' . $page['html'] . '</div></div>';

        return $this->shell('Community – Mitgliederbereich', '<link rel="stylesheet" href="' . $assetBase . "css/member-dashboard.css\">\n", $body, $assetBase, $theme);
    }

    /**
     * Die Seite als HTML-Dokument für den Browser: echte Vorlage im Rahmen des Standard-Themes (`shell()`), Kopfangaben der Community.
     *
     * @param array{view: string, vars: array<string, mixed>, meta: array<string, mixed>, status: int} $page
     */
    public function html(array $page, string $assetBase = '/assets/', string $theme = 'standard'): string
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $render = static function (string $view, array $vars) use ($e): string {
            extract($vars, EXTR_SKIP);
            ob_start();
            include ABSPATH . 'views/community/' . $view . '.php';

            return (string) ob_get_clean();
        };
        $body = $render($page['view'], $page['vars']);
        $head = (new \ReflectionMethod(PublicController::class, 'headTags'))->invoke(null, $page['meta']);
        // Adressen der Dateien auf den Prüfserver legen (die Seite nennt `cms_asset_url()`-Adressen mit Versionsangabe).
        $head = (string) preg_replace('~href="[^"]*?/assets/css/~', 'href="' . $assetBase . 'css/', (string) $head);
        $head = (string) preg_replace('~src="[^"]*?/assets/js/~', 'src="' . $assetBase . 'js/', $head);
        $title = (string) ($page['meta']['title'] ?? 'Community');

        return $this->shell($title, $head, $body, $assetBase, $theme);
    }

    /**
     * Farben der Themes, die die Seiten umgeben (Quelle: `themes/cms-default/style.css`; das Theme `cms-phinit` folgt mit dem Theme-PR):
     * - `standard`: das Standard-Theme: hell, Fließtext `#1a1a18` auf `#f7f6f2`, Links erben die Farbe und sind nicht unterstrichen
     *   (`a { color: inherit; text-decoration: none; }`), keine Variablen `--card-bg`, `--text-muted`, `--border-color`, `--primary`, kein Dunkelmodus;
     * - `dunkel`: ein Theme mit Dunkelmodus, das die Variablen der Community setzt (hell und per `prefers-color-scheme: dark` dunkel).
     */
    public const THEME_CSS = [
        'standard' => 'body{margin:0;background:#f7f6f2;color:#1a1a18;font-family:system-ui,sans-serif;line-height:1.5}a{color:inherit;text-decoration:none}'
            . '.skip-link{position:absolute;left:-9999px;top:0}.skip-link:focus{left:.5rem;top:.5rem;z-index:10;background:#fff;color:#1a1a18;padding:.5rem 1rem;outline:2px solid #8f5f15}'
            . '.site-header,.site-main,footer{max-width:72rem;margin:0 auto;padding:.5rem 1rem}.primary-nav ul{display:flex;gap:1rem;list-style:none;margin:0;padding:0}footer{color:#4d4d48}',
        'dunkel' => ':root{--card-bg:#fff;--text-muted:#566170;--border-color:#d9dee5;--primary:#1d4ed8}'
            . '@media (prefers-color-scheme:dark){:root{--card-bg:#1b2028;--text-muted:#a4adba;--border-color:#343b46;--primary:#8ab4ff}}'
            . 'body{margin:0;background:#fff;color:#1f2933;font-family:system-ui,sans-serif;line-height:1.5}a{color:var(--primary)}'
            . '@media (prefers-color-scheme:dark){body{background:#12161c;color:#e6e9ee}}'
            . '.skip-link{position:absolute;left:-9999px;top:0}.skip-link:focus{left:.5rem;top:.5rem;z-index:10;background:#fff;color:#1f2933;padding:.5rem 1rem;outline:2px solid #1d4ed8}'
            . '.site-header,.site-main,footer{max-width:72rem;margin:0 auto;padding:.5rem 1rem}.primary-nav ul{display:flex;gap:1rem;list-style:none;margin:0;padding:0}footer{color:var(--text-muted)}',
    ];

    /**
     * Rahmen wie im Standard-Theme (`themes/cms-default/header.php`): Sprunglink zu `#main-content`, Kopfbereich mit der Hauptnavigation,
     * ein einziger `<main id="main-content" tabindex="-1">` um den Inhalt der Vorlage, Fußbereich. Die Vorlagen der Community selbst
     * liefern keinen `<main>` (das Theme hat ihn schon).
     */
    private function shell(string $title, string $head, string $body, string $assetBase, string $theme = 'standard'): string
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $css = self::THEME_CSS[$theme] ?? throw new \InvalidArgumentException('Unbekanntes Theme ' . $theme);

        return "<!doctype html>\n<html lang=\"de\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . '<title>' . $e($title) . "</title>\n<style>" . $css . "</style>\n" . $head . "</head>\n<body>\n"
            . "<a class=\"skip-link\" href=\"#main-content\">Zum Inhalt springen</a>\n"
            . "<header class=\"site-header\"><nav class=\"primary-nav\" aria-label=\"Hauptnavigation\"><ul><li><a href=\"/\">Startseite</a></li><li><a href=\"/forum\">Community</a></li></ul></nav></header>\n"
            . "<main class=\"site-main\" id=\"main-content\" tabindex=\"-1\">\n" . $body . "\n</main>\n"
            . "<footer><p>© Beispielseite</p></footer>\n</body>\n</html>\n";
    }

    // ── Aufbau ──────────────────────────────────────────────────────────────────────────────────────────────────

    private function seed(int $topics, int $replies): void
    {
        $db = $this->db;
        $this->ids['member'] = $db->user(['username' => 'maria', 'display_name' => 'Maria Muster']);
        $this->ids['author'] = $db->user(['username' => 'jonas', 'display_name' => 'Jonas Autor']);
        $this->ids['admin'] = $db->user(['username' => 'admin', 'display_name' => 'Anna Admin', 'role' => 'admin']);
        $cat = $db->category(['title' => 'Allgemein']);
        $this->ids['forum'] = $db->forum(['title' => 'Technik', 'slug' => 'technik', 'category' => $cat, 'sort' => 1]);
        $this->ids['child'] = $db->forum(['title' => 'Netzwerk', 'slug' => 'netzwerk', 'parent' => $this->ids['forum'], 'category' => $cat]);
        $this->ids['help'] = $db->forum(['title' => 'Hilfe', 'slug' => 'hilfe', 'category' => $cat, 'sort' => 2]);

        $bodies = [
            "Hallo zusammen,\n\nich suche **Tipps** zur Einrichtung. Was ist _wichtig_?\n\n- Punkt eins\n- Punkt zwei\n\n```php\necho 'Hallo';\n```",
            "Eine Antwort mit einem [Link](https://example.org/) und einer Liste:\n\n1. erster Schritt\n2. zweiter Schritt\n\n> Zitat aus dem Handbuch",
            'Kurze Antwort.',
        ];
        $n = 0;
        $forumTopics = 0;
        $forumPosts = 0;
        $lastTopic = 0;
        $lastAt = '';
        for ($t = 1; $t <= $topics; $t++) {
            $at = gmdate('Y-m-d H:i:s', 1800000000 - $t * 3600);
            $tid = $db->topic([
                'forum' => $this->ids['forum'], 'user' => $this->ids['author'], 'title' => 'Frage zur Einrichtung Nr. ' . $t, 'slug' => 'frage-' . $t,
                'created_at' => $at, 'pinned' => $t === 1, 'locked' => $t === 2,
            ]);
            $first = $db->post(['topic' => $tid, 'user' => $this->ids['author'], 'first' => true, 'body' => $bodies[$t % 3], 'created_at' => $at]);
            $lastPost = $first;
            for ($r = 0; $r < $replies; $r++) {
                $lastPost = $db->post(['topic' => $tid, 'user' => $r % 2 === 0 ? $this->ids['member'] : $this->ids['admin'], 'body' => $bodies[($t + $r) % 3], 'created_at' => gmdate('Y-m-d H:i:s', 1800000000 - $t * 3600 + ($r + 1) * 60)]);
                $n++;
            }
            $db->execute('UPDATE cms_community_topics SET first_post_id = ?, last_post_id = ?, last_poster_id = ?, reply_count = ?, last_post_at = ? WHERE id = ?', [
                $first, $lastPost, $this->ids['member'], $replies, gmdate('Y-m-d H:i:s', 1800000000 - $t * 3600 + max(1, $replies) * 60), $tid,
            ]);
            if ($t === 3) {
                $db->execute('UPDATE cms_community_topics SET solution_post_id = ?, solved_at = ?, solved_by = ? WHERE id = ?', [$lastPost, $at, $this->ids['author'], $tid]);
            }
            $db->reaction(['post' => $first, 'user' => $this->ids['member']]);
            $this->ids['topic' . $t] = $tid;
            $forumTopics++;
            $forumPosts += $replies + 1;
            if ($t === 1) {
                $lastTopic = $tid;
            }
        }
        // Ein Thema mit mehreren Seiten (40 Antworten bei 15 Beiträgen je Seite)
        $at = '2027-01-01 08:00:00';
        $long = $db->topic(['forum' => $this->ids['forum'], 'user' => $this->ids['author'], 'title' => 'Langer Austausch über Netzwerke', 'slug' => 'langer-austausch', 'created_at' => $at]);
        $first = $db->post(['topic' => $long, 'user' => $this->ids['author'], 'first' => true, 'body' => $bodies[0], 'created_at' => $at]);
        $last = $first;
        for ($r = 0; $r < 40; $r++) {
            $last = $db->post(['topic' => $long, 'user' => $r % 2 === 0 ? $this->ids['member'] : $this->ids['author'], 'body' => $bodies[$r % 3], 'created_at' => gmdate('Y-m-d H:i:s', strtotime($at . ' UTC') + ($r + 1) * 60)]);
        }
        $db->execute('UPDATE cms_community_topics SET first_post_id = ?, last_post_id = ?, last_poster_id = ?, reply_count = 40, last_post_at = ? WHERE id = ?', [$first, $last, $this->ids['member'], '2027-01-01 08:41:00', $long]);
        $this->ids['long'] = $long;
        $this->ids['long_first'] = $first;
        $forumTopics++;
        $forumPosts += 41;
        $db->execute('UPDATE cms_community_forums SET topic_count = ?, post_count = ?, last_topic_id = ?, last_post_at = ? WHERE id = ?', [$forumTopics, $forumPosts, $lastTopic, '2027-01-15 07:00:00', $this->ids['forum']]);
        $this->ids['first_topic'] = $this->ids['topic1'] ?? $lastTopic;
        $this->ids['solved_topic'] = $this->ids['topic3'] ?? $lastTopic;
        $this->ids['locked_topic'] = $this->ids['topic2'] ?? $lastTopic;
        // Ein wartender Beitrag und eine Meldung für die Moderationsansicht
        $pending = $db->post(['topic' => $this->ids['first_topic'], 'user' => $this->ids['member'], 'status' => 'pending', 'body' => 'Wartet auf Freigabe', 'created_at' => '2027-01-15 07:30:00']);
        $db->report(['target' => $first, 'forum' => $this->ids['forum'], 'reporter' => $this->ids['member']]);
        // Eigene Themen des Mitglieds im Forum „Hilfe“ (zwei veröffentlicht, eins wartend) und vier Abos für den Mitgliederbereich
        foreach ([['Eigene Frage zur Anmeldung', 'published'], ['Noch eine Frage', 'published'], ['Wartende Frage', 'pending']] as $i => [$title, $status]) {
            $at = gmdate('Y-m-d H:i:s', 1790000000 + $i * 600);
            $own = $db->topic(['forum' => $this->ids['help'], 'user' => $this->ids['member'], 'title' => $title, 'slug' => 'eigene-frage-' . ($i + 1), 'status' => $status, 'created_at' => $at]);
            $post = $db->post(['topic' => $own, 'user' => $this->ids['member'], 'first' => true, 'status' => $status, 'body' => $bodies[$i % 3], 'created_at' => $at]);
            $db->execute('UPDATE cms_community_topics SET first_post_id = ?, last_post_id = ? WHERE id = ?', [$post, $post, $own]);
            $this->ids['own' . ($i + 1)] = $own;
        }
        $db->subscription(['user' => $this->ids['member'], 'target' => $this->ids['first_topic']]);
        $db->subscription(['user' => $this->ids['member'], 'target' => $this->ids['solved_topic']]);
        $db->subscription(['user' => $this->ids['member'], 'target' => $long]);
        $db->subscription(['user' => $this->ids['member'], 'target' => $this->ids['forum'], 'target_type' => 'forum']);
        unset($pending, $n);
    }

    private function wire(): void
    {
        $router = new class($this) {
            public function __construct(private CommunityPages $pages)
            {
            }

            public function addRoute(string $method, string $pattern, callable $handler): void
            {
                $this->pages->addRoute($method, $pattern, $handler);
            }
        };
        $this->freshProcess();
        // Wie im Betrieb (`CommunityHooks`): Schreibseiten zuerst, ihre Muster sind enger als `topic_page`/`forum_page`.
        ComposeController::instance()->registerRoutes($router);
        PublicController::instance()->registerRoutes($router);
    }

    /** @internal vom Router-Ersatz in `wire()` */
    public function addRoute(string $method, string $pattern, callable $handler): void
    {
        $this->routes[strtoupper($method)][$pattern] = $handler;
    }

    /** Alle Beiträge der Seiten einmal ausgeben (das gerenderte HTML landet in `body_html`), danach arbeitet jede Anfrage im Dauerbetrieb. */
    private function warm(): void
    {
        foreach (['/forum/thema/' . $this->ids['long'] . '-langer-austausch', '/forum/thema/' . $this->ids['long'] . '-langer-austausch/seite-2', '/forum/thema/' . $this->ids['long'] . '-langer-austausch/seite-3'] as $path) {
            $this->request($path, 'admin');
        }
        for ($t = 1; $t <= 6; $t++) {
            if (isset($this->ids['topic' . $t])) {
                $this->request('/forum/thema/' . $this->ids['topic' . $t] . '-frage-' . $t, 'admin');
            }
        }
        $this->request('/forum/thema/' . $this->ids['first_topic'] . '-frage-1', 'admin');
        $this->member('moderation', 'admin');
    }

    /** Wie ein neuer PHP-Prozess: Dienste und ihre Zwischenspeicher neu aufbauen, Datenbank und Einstellungen bleiben. */
    private function freshProcess(): void
    {
        foreach (self::SINGLETONS as $class) {
            CommunityWiring::swap($class, null);
        }
        // Rollenrechte und E-Mail-Bestätigung ohne die Funktionen des Cores (im Betrieb liest `cms_load_role_capabilities()` die Rollentabelle,
        // einmal je Anfrage und Rolle: diese Abfrage zählt das Budget nicht mit).
        $grants = \CMS\Services\Community\Support\CommunityCapabilities::DEFAULT_GRANTS;
        CommunityWiring::swap(CommunityAccess::class, new CommunityAccess(
            $this->db,
            Settings::getInstance(),
            static fn (string $role, string $capability): bool => $role === 'admin' || in_array($capability, $grants[$role] ?? [], true),
            static fn (): bool => true,
            static fn (): int => 1800000000,
            static fn (int $userId): bool => true
        ));
        I18n::reset('de', 'de', 'Europe/Berlin');
    }

    private function asViewer(?string $viewer): void
    {
        $user = null;
        if ($viewer !== null) {
            $id = $this->ids[$viewer] ?? throw new \InvalidArgumentException('Unbekanntes Konto ' . $viewer);
            $row = $this->db->row('users', ['id' => $id]) ?? [];
            $user = (object) ($row + ['id' => $id]);
        }
        $auth = (new \ReflectionClass(Auth::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(Auth::class, 'currentUser');
        $property->setValue($auth, $user);
        $previous = CommunityWiring::swap(Auth::class, $auth);
        $this->previous[Auth::class] ??= $previous;
    }

    /** Die Adresse auf einen Handler abbilden (feste Muster zuerst, dann die mit `:name`). */
    private function dispatch(string $path): void
    {
        $handlers = $this->routes['GET'] ?? [];
        $patterns = array_keys($handlers);
        usort($patterns, static fn (string $a, string $b): int => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));
        foreach ($patterns as $pattern) {
            $regex = '~^' . preg_replace_callback('~:[a-z_]+|[^:]+~', static fn (array $m): string => $m[0][0] === ':' ? '([a-zA-Z0-9_-]+)' : preg_quote($m[0], '~'), $pattern) . '$~';
            if (preg_match($regex, $path, $m) === 1) {
                array_shift($m);
                $handlers[$pattern](...$m);

                return;
            }
        }
        throw new \RuntimeException('Keine Route für ' . $path);
    }
}
