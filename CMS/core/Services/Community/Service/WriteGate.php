<?php
/**
 * Tor der Schreibdienste zum `ContentGuard` (PR #2b, Nachlauf): Wortfilter, Zählung, `screen()` und Sicherheitsprotokoll für
 * `TopicService::create()`, `PostService::reply()` und `PostService::edit()` an einer Stelle (Vertrag DESIGN-2b §4.1 Schritt 11, §4.2 Schritt 7).
 *
 * Ablauf eines Schreibvorgangs (Mitglied, `$userId` > 0):
 * 1. `inspect()` vor dem ersten `can()`: `filterText()` (Titel und Text, Ersatz wird übernommen, der Dienst prüft die Längen erneut),
 *    eine Analyse des Renderers (Zählung und Hosts, einmal) und `content()`. Das Ergebnis geht über `context()` als `content`
 *    (beim Bearbeiten auch `content_before`) in jedes `can()` des Vorgangs.
 * 2. `screen()` nach erlaubtem `can()`, vor `ensure()` und der Transaktion: Urteil des Wortfilters (`word_blocked`), Hosts, Core-Antispam
 *    (`spam_rejected`), Signale, Spam-Dublette, Filter `cms_community_spam_check`, `ip_hash`. `review` macht den Beitrag über das
 *    `can(fresh)` unter den Sperren (`review` im Kontext) zu `pending`, beim Bearbeiten eines veröffentlichten Beitrags zu `content_review`.
 * 3. `reject()` nach dem Ende des Vorgangs (nie in einer Transaktion): Sicherheitsprotokoll für Codes aus `ContentGuard::LOGGED_CODES`.
 *
 * Ohne Guard (Standard der Dienste, wenn kein `$guard` übergeben wird) ist das Tor inaktiv: `inspect()` liefert `null`, `context()` ist
 * leer, der Dienst arbeitet wie vor dem Nachlauf. `TopicService::instance()` und `PostService::instance()` übergeben
 * `ContentGuard::instance()`. Der Konstruktor berührt keine Datenbank; die Analyse parst den Text und läuft nie in einer Transaktion.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Logger;
use CMS\Services\Community\Support\ContentRenderer;

if (!defined('ABSPATH')) {
    exit;
}

final class WriteGate
{
    /** @var \Closure(): ?ContentGuard */
    private \Closure $guard;

    /**
     * @param (\Closure(): ?ContentGuard)|null $guard Standard: kein Guard (inaktiv)
     */
    public function __construct(?\Closure $guard = null)
    {
        $this->guard = $guard ?? static fn (): ?ContentGuard => null;
    }

    /**
     * Wortfilter, Zählung und Hosts für Titel (`null` = nur Text) und Text. `null`, wenn kein Guard gesetzt ist.
     *
     * @return array{guard: ContentGuard, title: ?string, body: string, verdict: string, replaced: bool, content: array{links: int, images: int, attachments: int, failed: bool}, content_before?: array<string, mixed>, hosts: list<string>}|null
     */
    public function inspect(?string $title, string $body): ?array
    {
        $guard = ($this->guard)();
        if ($guard === null) {
            return null;
        }
        $filtered = $guard->filterText($title, $body);
        [$content, $hosts] = self::measure($guard, $filtered['body']);

        return [
            'guard' => $guard, 'title' => $filtered['title'], 'body' => $filtered['body'], 'verdict' => $filtered['verdict'],
            'replaced' => $filtered['replaced'], 'content' => $content, 'hosts' => $hosts,
        ];
    }

    /**
     * Ergänzt `content_before` (Zählung der Vorfassung beim Bearbeiten: eine Korrektur scheitert nicht an Inhalt, der schon da war).
     *
     * @param array<string, mixed> $check
     * @return array<string, mixed>
     */
    public function withBefore(array $check, string $previousBody): array
    {
        $check['content_before'] = self::measure($check['guard'], $previousBody)[0];

        return $check;
    }

    /**
     * Zusätzlicher Kontext für `can()`: `content`, `content_before` und, nach `screen()`, `review`. Leer ohne Guard.
     *
     * @param array<string, mixed>|null $check Ergebnis von `inspect()`
     * @param array<string, mixed>|null $screen Ergebnis von `screen()` (`ok = true`)
     * @return array<string, mixed>
     */
    public function context(?array $check, ?array $screen = null): array
    {
        if ($check === null) {
            return [];
        }
        $context = ['content' => $check['content']];
        if (isset($check['content_before'])) {
            $context['content_before'] = $check['content_before'];
        }
        if ($screen !== null && ($screen['review'] ?? false) === true) {
            $context['review'] = true;
        }

        return $context;
    }

    /**
     * `ContentGuard::screen()` für einen erlaubten Vorgang. `\PDOException` der Dublettenabfrage geht an den Dienst (`db_error`).
     *
     * @param array<string, mixed> $check Ergebnis von `inspect()`
     * @param array<string, int> $ids `forum_id`, `topic_id`, `post_id` (was bekannt ist)
     * @return array{ok: true, review: bool, signals: list<string>, ip_hash: ?string}|array{ok: false, error: string}
     */
    public function screen(array $check, int $userId, string $action, array $ids, int $nowTs): array
    {
        return $check['guard']->screen($userId, $action, $ids + [
            'title' => $check['title'], 'body' => $check['body'], 'verdict' => $check['verdict'],
            'content' => $check['content'], 'hosts' => $check['hosts'],
        ], $nowTs);
    }

    /**
     * Sicherheitsprotokoll nach dem Ende des Vorgangs; der Guard beachtet `LOGGED_CODES`, Web-Anfrage und Drosselung und wirft nie.
     *
     * @param array<string, mixed>|null $check Ergebnis von `inspect()`
     */
    public function reject(?array $check, string $code, int $userId): void
    {
        if ($check !== null && $code !== '') {
            $check['guard']->logRejection($code, $userId);
        }
    }

    /**
     * Eine Analyse des Renderers für Zählung und Hosts. Scheitert sie (Bündel fehlt, Grenze, Ausnahme), gilt die Zählung als
     * fehlgeschlagen (`failed`, der Beitrag wird geprüft); `[]` statt `null` verhindert ein zweites Parsen in `content()`.
     *
     * @return array{0: array{links: int, images: int, attachments: int, failed: bool}, 1: list<string>}
     */
    private static function measure(ContentGuard $guard, string $markdown): array
    {
        $stats = null;
        try {
            $analysis = ContentRenderer::instance()->analyze($markdown);
            if (($analysis['ok'] ?? false) === true && is_array($analysis['stats'] ?? null)) {
                $stats = $analysis['stats'];
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('community')->warning('Analyse des Beitrags fehlgeschlagen, der Beitrag wird geprüft: {exception}', ['exception' => $e]);
        }
        $content = $guard->content($markdown, $stats === null ? 0 : count((array) ($stats['attachments'] ?? [])), $stats ?? []);

        return [$content, $stats === null ? [] : array_values(array_filter((array) ($stats['hosts'] ?? []), 'is_string'))];
    }
}
