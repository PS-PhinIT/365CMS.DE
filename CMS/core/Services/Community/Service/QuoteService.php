<?php
/**
 * Zitate (Community, PR #2a; Format laut Karte §3.8).
 *
 * Ein Zitat ist ein Blockquote im Markdown-Text des antwortenden Beitrags. Die erste Zeile ist der Marker, danach folgt der
 * übernommene Text, jede Zeile mit `> ` eingeleitet:
 *
 *     > [quote=post:123]
 *     > Zeile 1 des zitierten Textes
 *     > Zeile 2
 *
 * Die Markerzeile ist die einzige Zeile eines erzeugten Blocks, die ein Marker sein darf: jedes Muster `[quote=post:ID]` im
 * übernommenen Text (Quelle oder Auswahl) wird mit einem Backslash entschärft (`\[quote=post:ID]`), gleich ob es eine eigene Zeile
 * ist, hinter Leerraum steht oder mitten im Text. Sonst würde aus einer unmarkierten Zeile `[quote=post:1]` der Quelle durch das
 * Voranstellen von `> ` ein gefälschter Marker: `neutralize()` beendete dort den Block der verborgenen Quelle, und der Text dahinter
 * bliebe sichtbar (Befund B7).
 *
 * Der Autor der Quelle steht nicht im Text (er kommt beim Rendern aus `posts.user_id`). Zitiert wird nur, was das Mitglied
 * lesen darf: `build()` prüft `can('read')` und verlangt die volle Ansicht. Rechte können sich ändern; deshalb ersetzt
 * `neutralize()` Blöcke mit nicht mehr sichtbarer Quelle erst zur Anzeigezeit durch einen Platzhalter (der gespeicherte Text
 * fremder Beiträge bleibt unberührt; der Renderer in PR #2b ruft `neutralize()`).
 *
 * Reine Textregeln (`markerId`, `extractRefs`, `neutralize`, `stripQuotes`, `presentable`) sind statisch und brauchen keine
 * Datenbank. `markerId()` ist die einzige Marker-Regel: der Renderer (PR #2b, `Support\ContentRenderer`) erkennt Zitat-Blöcke über
 * dieselbe Methode an ihrer Quellzeile.
 *
 * Mengen-API (PR #2b, Vertrag DESIGN-2b §3.6, §4.3, §4.7 Nr. 9): `resolveMany()` liefert die Entscheidungen eines Betrachters für
 * alle Zitate einer Seite, `quotableMany()` die Zitierbarkeit neuer Marker beim Schreiben. Beide lesen mit genau einer
 * `PostRepository::findMany()` (Stücke zu 100 IDs) und fragen je ID `can('read')` mit dem Zwischenspeicher der Anfrage. Ein
 * Datenbankfehler ergibt für alle IDs `false` (fail-closed) und eine Warnung. Die Zuordnung „Beitrag + Entscheidung → Zustand“
 * steht nur in `stateOf()`, die Zitierbarkeit nur in `quotableRow()`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Support\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class QuoteService
{
    /** Längster übernommener Text eines Zitats in Zeichen; Längeres wird mit „[…]“ abgeschnitten (keine Einstellung). */
    public const MAX_LENGTH = 2000;
    /** Längster Text, den `format()` überhaupt verarbeitet (Schutz vor sehr großen Eingaben); Längeres gilt als abgeschnitten. */
    public const MAX_SOURCE = 20000;
    /** Zeile, die den Rest eines abgeschnittenen Zitats ersetzt. */
    public const OMISSION = '[…]';
    /** Platzhalter, wenn die Quelle in einem nicht sichtbaren Bereich liegt. */
    public const HIDDEN_NOTICE = '[Zitat aus einem nicht sichtbaren Bereich]';
    /** Platzhalter, wenn die Quelle ausgeblendet oder gelöscht wurde. */
    public const REMOVED_NOTICE = '[Zitat entfernt]';
    /** Rückgabe von `neutralize()`-Prüfern für „Quelle ausgeblendet oder gelöscht“. */
    public const REMOVED = 'removed';

    /** Markerzeile am Zeilenanfang. */
    private const MARKER = '/^> \[quote=post:([1-9]\d{0,18})\][ \t]*$/';

    private static ?self $instance = null;

    private Database $db;
    /** @var \Closure(?int, string, array<string, mixed>): AccessDecision */
    private \Closure $can;
    private PostRepository $posts;

    /**
     * `$settings` und `$clock` haben hier keine Wirkung: sie stehen nur an derselben Stelle wie bei den übrigen Diensten.
     *
     * @param \Closure(?int, string, array<string, mixed>): AccessDecision|null $can Standard: `CommunityAccess::instance()->can()`; ohne diese Klasse wird alles abgelehnt
     */
    public function __construct(?Database $db = null, ?Settings $settings = null, ?\Closure $clock = null, ?\Closure $can = null)
    {
        $this->db = $db ?? Database::instance();
        $this->can = $can ?? static function (?int $userId, string $action, array $context): AccessDecision {
            return class_exists(CommunityAccess::class)
                ? CommunityAccess::instance()->can($userId, $action, $context)
                : AccessDecision::deny('no_permission');
        };
        $this->posts = new PostRepository($this->db);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    // ── Zitat erzeugen ──────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Zitat eines Beitrags für ein Mitglied: Markerzeile plus Text (ganzer Beitrag oder die Auswahl). `null`, wenn der Beitrag
     * nicht zitierbar ist: unbekannt, nicht lesbar, nur als Platzhalter sichtbar, nicht veröffentlicht (wartend, abgelehnt,
     * gelöscht; ausgeblendet nur für die Moderation) oder ohne zitierbaren Text. Verschachtelte Zitate der Quelle entfallen
     * (Tiefe 1), der Text ist auf MAX_LENGTH Zeichen begrenzt. Das Ergebnis hat keinen Zeilenumbruch am Ende.
     *
     * Die Auswahl (`$selection`) ist Text des Mitglieds und wird nicht gegen die Quelle geprüft: das Mitglied kann den Text
     * des Zitats vor dem Absenden ohnehin ändern. Nach dem Zitieren ist nur die Marke `post:ID` verlässlich. Marker-Muster in
     * der Auswahl und in der Quelle werden entschärft (siehe `defuseMarkers()`): `extractRefs(build(…))` liefert nur die ID des Zitats.
     */
    public function build(int $postId, ?int $viewerId, ?string $selection = null): ?string
    {
        $post = $this->quotablePost($postId, $viewerId);
        if ($post === null) {
            return null;
        }
        $text = $selection !== null && trim($selection) !== '' ? $selection : (string) ($post['body'] ?? '');

        return self::format($postId, $text);
    }

    /**
     * Darf das Mitglied diesen Beitrag zitieren (lesen mit voller Ansicht, veröffentlicht oder als Moderation ausgeblendet)?
     * Dieselbe Regel wie `build()`, ohne Text; `PostService::reply()` prüft damit `quote_of_post_id`. Delegiert an `quotableMany()`
     * (ein Datenbankfehler ergibt `false`).
     */
    public function isQuotable(int $postId, ?int $viewerId): bool
    {
        return $postId > 0 && ($this->quotableMany($viewerId, [$postId])[$postId] ?? false);
    }

    /**
     * Zitierbarkeit mehrerer Beiträge für ein Mitglied (Zitatmarker beim Schreiben, Vertrag §4.1 Schritt 7a): je ID dieselbe Regel
     * wie `isQuotable()`. Eine Abfrage `findMany()`, danach je ID `can('read')` (mit `now`, wenn angegeben). Fehlende IDs und alle
     * IDs bei einem Datenbankfehler ergeben `false`. Nur ganze Zahlen ≥ 1 zählen, doppelte einmal.
     *
     * @param array<array-key, mixed> $postIds
     * @return array<int, bool> Beitrags-ID => zitierbar, in der Reihenfolge des ersten Auftretens
     */
    public function quotableMany(?int $userId, array $postIds, ?int $now = null): array
    {
        $ids = self::positiveIds($postIds);
        if ($ids === []) {
            return [];
        }
        try {
            $found = $this->posts->findMany($ids);
            $quotable = [];
            foreach ($ids as $id) {
                $quotable[$id] = isset($found[$id]) && $this->quotableRow($found[$id], $userId, $now);
            }

            return $quotable;
        } catch (\PDOException | \RuntimeException $e) {
            self::warn('quotableMany', $e, count($ids));

            return array_fill_keys($ids, false);
        }
    }

    /**
     * Markerzeile plus Text als Blockquote. Rein; `null`, wenn nach dem Entfernen verschachtelter Zitate kein Text bleibt.
     * Marker-Muster im Text werden entschärft: die erste Zeile ist die einzige Markerzeile des Blocks.
     */
    public static function format(int $postId, string $text): ?string
    {
        if ($postId < 1 || !mb_check_encoding($text, 'UTF-8')) {
            return null;
        }
        $omitted = mb_strlen($text, 'UTF-8') > self::MAX_SOURCE;
        $text = mb_substr($text, 0, self::MAX_SOURCE, 'UTF-8');
        $text = self::stripQuotes(str_replace(["\r\n", "\r"], "\n", $text));
        $text = trim((string) preg_replace('/[^\P{Cc}\n\t]/u', '', $text));
        if ($text === '') {
            return null;
        }
        $text = self::defuseMarkers($text);
        if (mb_strlen($text, 'UTF-8') > self::MAX_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::MAX_LENGTH, 'UTF-8'));
            $omitted = true;
        }
        $lines = ['> [quote=post:' . $postId . ']'];
        foreach (explode("\n", $text) as $line) {
            $lines[] = rtrim('> ' . $line);
        }
        if ($omitted) {
            $lines[] = '> ' . self::OMISSION;
        }

        return implode("\n", $lines);
    }

    /**
     * Entschärft jedes Marker-Muster `[quote=post:…]` (auch mit Leerraum um `=` und `:`, in beliebiger Schreibweise) durch einen
     * Backslash vor der öffnenden Klammer. Der Text bleibt lesbar (Markdown zeigt `[quote=post:5]` als Text), ist aber nie ein Marker.
     * Eine Klammer, die schon ein Backslash schützt (ungerade Zahl von Backslashes davor), bleibt unverändert; bei gerader Zahl
     * (`\\[`) steht die Klammer ungeschützt und bekommt einen weiteren Backslash. Wiederholbar:
     * `defuseMarkers(defuseMarkers($x)) === defuseMarkers($x)`.
     */
    public static function defuseMarkers(string $text): string
    {
        return (string) preg_replace_callback(
            '/(\\\\*)\[(?=\s*quote\s*=\s*post\s*:)/iu',
            static fn (array $m): string => strlen($m[1]) % 2 === 1 ? $m[0] : $m[1] . '\\[',
            $text
        );
    }

    // ── Zitate lesen ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Beitrags-ID einer Markerzeile (`> [quote=post:ID]`, Leerraum am Ende erlaubt), sonst `null`. Einzige Marker-Regel für die
     * Zeilenfunktionen dieser Klasse und für den Renderer (Quellzeile eines Zitat-Blocks auf oberster Ebene).
     */
    public static function markerId(string $line): ?int
    {
        return preg_match(self::MARKER, $line, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * IDs der zitierten Beiträge (Markerzeilen), in der Reihenfolge des Auftretens, ohne Doppelte (für Benachrichtigungen in PR #2b).
     *
     * @return list<int>
     */
    public static function extractRefs(string $markdown): array
    {
        $ids = [];
        foreach (self::lines($markdown) as $line) {
            $id = self::markerId($line);
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Entfernt Zitatblöcke (Markerzeile und die folgenden `>`-Zeilen) aus einem Text.
     */
    public static function stripQuotes(string $markdown): string
    {
        $kept = [];
        $inQuote = false;
        foreach (self::lines($markdown) as $line) {
            if (self::markerId($line) !== null) {
                $inQuote = true;
                continue;
            }
            if ($inQuote && str_starts_with($line, '>')) {
                continue;
            }
            $inQuote = false;
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * Ersetzt Zitatblöcke, deren Quelle nicht (mehr) sichtbar ist, durch einen Platzhalter. Der Block bleibt eine
     * Markerzeile mit genau einer Textzeile; die Marke bleibt, damit die Quelle nachvollziehbar und der Aufruf wiederholbar
     * bleibt (`neutralize(neutralize($x)) === neutralize($x)`).
     *
     * `$visible(int $postId)` liefert `true` für sichtbar, `QuoteService::REMOVED` für ausgeblendet/gelöscht und sonst
     * (`false`) für „nicht sichtbarer Bereich“. Eine Entscheidung je Beitrags-ID wird nur einmal erfragt.
     *
     * @param \Closure(int): (bool|string) $visible
     */
    public static function neutralize(string $markdown, \Closure $visible, ?string $hiddenNotice = null, ?string $removedNotice = null): string
    {
        $hiddenNotice ??= self::HIDDEN_NOTICE;
        $removedNotice ??= self::REMOVED_NOTICE;
        $decided = [];
        $out = [];
        $replacing = false;
        foreach (self::lines($markdown) as $line) {
            $id = self::markerId($line);
            if ($id !== null) {
                $decided[$id] ??= $visible($id);
                $state = $decided[$id];
                $replacing = $state !== true;
                $out[] = $line;
                if ($replacing) {
                    $out[] = '> ' . ($state === self::REMOVED ? $removedNotice : $hiddenNotice);
                }
                continue;
            }
            if ($replacing && str_starts_with($line, '>')) {
                continue;
            }
            $replacing = false;
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * Ausgaberegel für Lese-APIs (Vertrag DESIGN-2b §1 Nr. 9): `body_html` verlässt keinen Dienst, `body` nur neutralisiert mit den
     * Entscheidungen des Betrachters (`$visible` wie bei `neutralize()`). Gilt für volle Einträge (`view` fehlt oder `full`).
     * Platzhalter (`view` ≠ `full`) tragen keinen Text: ein unerwartetes `body` entfällt dort ebenfalls (fail-closed).
     *
     * @param array<string, mixed> $item Eintrag wie `PostService::listPage()['items']` oder eine Beitragszeile
     * @param \Closure(int): (bool|string) $visible
     * @return array<string, mixed>
     */
    public static function presentable(array $item, \Closure $visible): array
    {
        unset($item['body_html']);
        if (($item['view'] ?? 'full') !== 'full') {
            unset($item['body']);

            return $item;
        }
        if (isset($item['body']) && is_string($item['body'])) {
            $item['body'] = self::neutralize($item['body'], $visible);
        }

        return $item;
    }

    /**
     * Prüfer für `neutralize()` aus den Rechten eines Mitglieds: sichtbar, wenn das Mitglied den Beitrag voll lesen darf und er
     * veröffentlicht ist; ausgeblendet oder gelöscht → `REMOVED`; sonst (unbekannt, nicht lesbar, wartend, abgelehnt) → `false`.
     * Je Aufruf eine Abfrage; für ganze Seiten `resolveMany()`.
     *
     * @return \Closure(int): (bool|string)
     */
    public function resolver(?int $viewerId): \Closure
    {
        return function (int $postId) use ($viewerId): bool|string {
            $post = $this->posts->find($postId);
            if ($post === null) {
                return false;
            }

            return self::stateOf($post, ($this->can)($viewerId, 'read', ['post_id' => $postId, 'post' => $post]));
        };
    }

    /**
     * Entscheidungen eines Betrachters für viele Zitatquellen (Vertrag DESIGN-2b §4.3, eine Abfrage je Seite): Regel wie `resolver()`.
     * Eine Abfrage `findMany()`, danach je ID `can($viewerId, 'read', ['post_id', 'post', 'now'])`. Fehlende IDs ergeben `false`;
     * ein Datenbankfehler ergibt `false` für alle IDs (fail-closed) und eine Warnung. Nur ganze Zahlen ≥ 1 zählen, doppelte einmal.
     *
     * @param array<array-key, mixed> $postIds
     * @return array<int, bool|string> Beitrags-ID => `true` | `QuoteService::REMOVED` | `false`
     */
    public function resolveMany(?int $viewerId, array $postIds, ?int $now = null): array
    {
        $ids = self::positiveIds($postIds);
        if ($ids === []) {
            return [];
        }
        try {
            $rows = $this->posts->findMany($ids);
            $states = [];
            foreach ($ids as $id) {
                $states[$id] = isset($rows[$id]) ? self::stateOf($rows[$id], ($this->can)($viewerId, 'read', self::readContext($rows[$id], $now))) : false;
            }

            return $states;
        } catch (\PDOException | \RuntimeException $e) {
            self::warn('resolveMany', $e, count($ids));

            return array_fill_keys($ids, false);
        }
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private static function lines(string $markdown): array
    {
        return explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
    }

    /**
     * Zustand einer Zitatquelle für einen Betrachter (einzige Stelle der Zuordnung, `resolver()` und `resolveMany()`). Die Entscheidung
     * kommt vor dem Status (Vertrag §4.7 Nr. 9): ein ausgeblendeter Beitrag in einem nicht lesbaren Forum ist „nicht sichtbar“, nie
     * `REMOVED` (sonst verriete der Platzhalter, dass dort etwas ausgeblendet wurde).
     *
     * @param array<string, mixed> $post
     */
    private static function stateOf(array $post, AccessDecision $d): bool|string
    {
        if (!$d->allowed) {
            return false;
        }

        return match ((string) ($post['status'] ?? '')) {
            'published' => $d->view !== 'placeholder',
            'hidden', 'deleted' => self::REMOVED,
            default => false,
        };
    }

    /**
     * Zeile des Beitrags, wenn er für das Mitglied zitierbar ist (mit Text, für `build()`).
     *
     * @return array<string, mixed>|null
     */
    private function quotablePost(int $postId, ?int $viewerId): ?array
    {
        if ($postId < 1) {
            return null;
        }
        $post = $this->posts->find($postId);

        return $post !== null && $this->quotableRow($post, $viewerId, null) ? $post : null;
    }

    /**
     * Zitierregel (einzige Stelle, `build()`, `isQuotable()`, `quotableMany()`): lesbar mit voller Ansicht und veröffentlicht, oder
     * ausgeblendet für die Moderation des Forums.
     *
     * @param array<string, mixed> $post Beitragszeile (mit oder ohne Text)
     */
    private function quotableRow(array $post, ?int $viewerId, ?int $now): bool
    {
        $decision = ($this->can)($viewerId, 'read', self::readContext($post, $now));
        if (!$decision->allowed || $decision->view === 'placeholder') {
            return false;
        }
        $status = (string) $post['status'];

        return $status === 'published' || ($status === 'hidden' && $this->moderates($viewerId, (int) $post['forum_id']));
    }

    private function moderates(?int $userId, int $forumId): bool
    {
        return $userId !== null && $userId > 0 && ($this->can)($userId, 'moderate', ['forum_id' => $forumId])->allowed;
    }

    /**
     * Kontext der Leseprüfung einer Beitragszeile; `now` nur, wenn angegeben (#2a-Aufrufe ohne Uhr bleiben unverändert).
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private static function readContext(array $post, ?int $now): array
    {
        return ['post_id' => (int) $post['id'], 'post' => $post] + ($now !== null ? ['now' => $now] : []);
    }

    /**
     * Ganze Zahlen ≥ 1, ohne Doppelte, in der Reihenfolge des ersten Auftretens.
     *
     * @param array<array-key, mixed> $ids
     * @return list<int>
     */
    private static function positiveIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (is_int($id) && $id > 0) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    private static function warn(string $operation, \Throwable $e, int $count): void
    {
        Logger::instance()->withChannel('community')->warning('Community-Zitate: {operation} fehlgeschlagen ({count} IDs): {exception}', [
            'operation' => $operation,
            'count' => $count,
            'exception' => $e,
        ]);
    }
}
