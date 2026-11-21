<?php
/**
 * Integrationsfälle über Einheitsgrenzen für `ModerationService` (PR #2b, J2; Vertrag DESIGN-2b §6 J2, Fassung 3.1 GK6). Besitzer: J2.
 *
 *  - M `$render`: `instance()` verdrahtet `RenderService::instance()` (Einheit R); beim Konstruieren keine Datenbank.
 *  - `queue()` mit dem echten `RenderService`: Einträge aus `queueRow()` passen zu `present()` (volle Ansicht), `html` mit aufgelöstem
 *    lesbarem Zitat, nicht lesbares Zitat verborgen, kein `body_html`, kein Rohtext der fremden Quelle (§1 Nr. 9).
 *
 * Geladen von `run.php` am Ende; nutzt `MdWorld`, `mdSeed()` und `CommunityWiring::swap()` (TESTS/lib/CommunityTestDatabase.php).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

// Bibliotheken (CommonMark-Bündel, HTMLPurifier) für den echten Renderer; run.php lädt sie nicht.
require_once ABSPATH . 'assets/autoload.php';

use CMS\Database;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\ModerationService;
use CMS\Services\Community\Service\QuoteService;
use CMS\Services\Community\Service\RenderService;
use CMS\Services\Community\Support\Settings;

T::run('Integration (J2): ModerationService::instance() verdrahtet RenderService::instance()', static function (): void {
    $db = CommunityTestDatabase::create();
    $previous = [
        Database::class => CommunityWiring::swap(Database::class, $db),
        ModerationService::class => CommunityWiring::swap(ModerationService::class, null),
        RenderService::class => CommunityWiring::swap(RenderService::class, null),
        Settings::class => CommunityWiring::swap(Settings::class, Settings::fromArray([])),
    ];
    try {
        $db->log = [];
        $moderation = ModerationService::instance();
        $render = (new \ReflectionProperty(ModerationService::class, 'render'))->getValue($moderation);
        T::check($render instanceof RenderService && $render === RenderService::instance(), 'ModerationService::instance(): render = RenderService::instance()');
        T::same([], $db->log, 'instance() beider Dienste: keine Abfrage');
        T::same($moderation, ModerationService::instance(), 'eine Instanz je Prozess');
    } finally {
        foreach ($previous as $class => $value) {
            CommunityWiring::swap($class, $value);
        }
    }
});

T::run('Integration (J2): queue() mit echtem RenderService: html je Moderator, Zitate nach Leserecht, kein Rohtext fremder Zitate', static function (): void {
    $w = new MdWorld();
    $mod = $w->db->user(['role' => 'moderator']);
    $starter = $w->db->user();
    $open = $w->db->forum();
    $secret = $w->db->forum();
    [, $visible] = mdSeed($w, $open, $starter);
    [, $hidden] = mdSeed($w, $secret, $starter);
    $w->db->execute('UPDATE cms_community_posts SET body = ? WHERE id = ?', ['Lesbare Quelle', $visible[0]]);
    $w->db->execute('UPDATE cms_community_posts SET body = ? WHERE id = ?', ['GEHEIMTEXT der Quelle', $hidden[0]]);
    [, $pending] = mdSeed($w, $open, $starter, [[$starter, 'pending']]);
    $body = QuoteService::format($visible[0], 'Lesbare Quelle') . "\n\n" . QuoteService::format($hidden[0], 'GEHEIMTEXT der Quelle') . "\n\nMeine **Antwort**";
    $w->db->execute('UPDATE cms_community_posts SET body = ? WHERE id = ?', [$body, $pending[1]]);
    $w->recount();
    $render = new RenderService(
        $w->db,
        $w->settings,
        static fn (): int => (int) $w->time['now'],
        static fn (?int $u, string $a, array $c): AccessDecision => ($c['post_id'] ?? null) === $hidden[0] ? AccessDecision::deny('not_found') : AccessDecision::allow()
    );
    $moderation = new ModerationService($w->db, $w->settings, static fn (): int => (int) $w->time['now'], static fn (): AccessDecision => AccessDecision::allow(), null,
        static fn (): array => ['global' => true, 'forum_ids' => [$open, $secret]], $render);
    $queue = $moderation->queue($mod);
    $item = $queue['items'][0] ?? [];
    T::same([true, $pending[1], 'full'], [$queue['success'] ?? null, $item['id'] ?? null, $item['view'] ?? null], 'Warteschlange: der wartende Beitrag, volle Ansicht');
    $html = (string) ($item['html'] ?? '');
    T::check(str_contains($html, '<strong>Antwort</strong>') && str_contains($html, 'Lesbare Quelle'), 'html über RenderService::present(): Markdown gerendert, lesbares Zitat aufgelöst', $html);
    T::check(!str_contains((string) json_encode($queue), 'GEHEIMTEXT') && !array_key_exists('body_html', $item), 'kein Rohtext der nicht lesbaren Quelle, kein body_html');
    T::check(str_contains((string) ($item['body'] ?? ''), QuoteService::HIDDEN_NOTICE), 'body neutralisiert: nicht lesbares Zitat als Platzhalter', (string) ($item['body'] ?? ''));
});
