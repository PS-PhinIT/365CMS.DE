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
 * Der Autor der Quelle steht nicht im Text (er kommt beim Rendern aus `posts.user_id`). Zitiert wird nur, was das Mitglied
 * lesen darf: `build()` prüft `can('read')` und verlangt die volle Ansicht. Rechte können sich ändern; deshalb ersetzt
 * `neutralize()` Blöcke mit nicht mehr sichtbarer Quelle erst zur Anzeigezeit durch einen Platzhalter (der gespeicherte Text
 * fremder Beiträge bleibt unberührt; der Renderer in PR #2b ruft `neutralize()`).
 *
 * Reine Textregeln (`extractRefs`, `neutralize`, `stripQuotes`) sind statisch und brauchen keine Datenbank.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
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
     * des Zitats vor dem Absenden ohnehin ändern. Nach dem Zitieren ist nur die Marke `post:ID` verlässlich.
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
     * Dieselbe Regel wie `build()`, ohne Text; `PostService::reply()` prüft damit `quote_of_post_id`.
     */
    public function isQuotable(int $postId, ?int $viewerId): bool
    {
        return $this->quotablePost($postId, $viewerId) !== null;
    }

    /**
     * Markerzeile plus Text als Blockquote. Rein; `null`, wenn nach dem Entfernen verschachtelter Zitate kein Text bleibt.
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

    // ── Zitate lesen ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * IDs der zitierten Beiträge (Markerzeilen), in der Reihenfolge des Auftretens, ohne Doppelte (für Benachrichtigungen in PR #2b).
     *
     * @return list<int>
     */
    public static function extractRefs(string $markdown): array
    {
        $ids = [];
        foreach (self::lines($markdown) as $line) {
            if (preg_match(self::MARKER, $line, $m) === 1) {
                $ids[(int) $m[1]] = (int) $m[1];
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
            if (preg_match(self::MARKER, $line) === 1) {
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
            if (preg_match(self::MARKER, $line, $m) === 1) {
                $id = (int) $m[1];
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
     * Prüfer für `neutralize()` aus den Rechten eines Mitglieds: sichtbar, wenn das Mitglied den Beitrag voll lesen darf und er
     * veröffentlicht ist; ausgeblendet oder gelöscht → `REMOVED`; sonst (unbekannt, nicht lesbar, wartend, abgelehnt) → `false`.
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
            $decision = ($this->can)($viewerId, 'read', ['post_id' => $postId, 'post' => $post]);
            if (!$decision->allowed) {
                return false;
            }

            return match ((string) $post['status']) {
                'published' => $decision->view !== 'placeholder',
                'hidden', 'deleted' => self::REMOVED,
                default => false,
            };
        };
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private static function lines(string $markdown): array
    {
        return explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
    }

    /**
     * Zeile des Beitrags, wenn er für das Mitglied zitierbar ist.
     *
     * @return array<string, mixed>|null
     */
    private function quotablePost(int $postId, ?int $viewerId): ?array
    {
        if ($postId < 1) {
            return null;
        }
        $post = $this->posts->find($postId);
        if ($post === null) {
            return null;
        }
        $decision = ($this->can)($viewerId, 'read', ['post_id' => $postId, 'post' => $post]);
        if (!$decision->allowed || $decision->view === 'placeholder') {
            return null;
        }
        $status = (string) $post['status'];
        if ($status !== 'published' && !($status === 'hidden' && $this->moderates($viewerId, (int) $post['forum_id']))) {
            return null;
        }

        return $post;
    }

    private function moderates(?int $userId, int $forumId): bool
    {
        return $userId !== null && $userId > 0 && ($this->can)($userId, 'moderate', ['forum_id' => $forumId])->allowed;
    }
}
