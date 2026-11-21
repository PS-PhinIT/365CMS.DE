<?php
/**
 * Integrationsfälle über Einheitsgrenzen für `RenderService` (PR #2b, J2; Vertrag DESIGN-2b §6 J2, Fassung 3.1 GK6). Besitzer: J2.
 *
 *  - R `$throttle`: die Standard-Closure läuft gegen den echten `ContentGuard::throttle('preview', …)` (10 je 60 s für Mitglieder).
 *  - R Standard-Renderer: Anhänge über `AttachmentRepository::findMany()` (Einheit D); Bild mit Breite und Höhe aus der Zeile.
 *
 * Geladen von `run.php` am Ende; nutzt `CrWorld` und `CommunityWiring` (TESTS/lib/CommunityTestDatabase.php).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\RenderService;

T::run('Integration (J2): RenderService::preview über die Standard-Drossel gegen ContentGuard::throttle()', static function (): void {
    $w = new CrWorld();
    $render = new RenderService($w->db, $w->settings, fn (): int => $w->now, fn (?int $u, string $a, array $c): AccessDecision => $w->access->can($u, $a, $c));
    $wiring = new CommunityWiring();
    $results = $wiring->run(static function () use ($render, $w): array {
        $out = [];
        for ($i = 1; $i <= 11; $i++) {
            $out[] = $render->preview($w->member, 'Vorschau Nummer ' . $i, ['topic_id' => $w->topic]);
        }

        return $out;
    });
    T::same(array_fill(0, 10, true), array_map(static fn (array $r): mixed => $r['success'] ?? null, array_slice($results, 0, 10)), 'ContentGuard erlaubt 10 Vorschauen je 60 s');
    T::same(['success' => false, 'error' => 'rate_limited', 'retry_after' => 60], $results[10], '11. Vorschau: rate_limited mit retry_after = Fenster aus ContentGuard::THROTTLE');
    T::same(['community_preview community:u:' . $w->member . ' 10/60'], $wiring->buckets(), 'Aktion preview, Kennung des Mitglieds, Grenze 10 je 60 s');

    $guest = $wiring->run(static fn (): array => $render->preview(null, 'Vorschau eines Gasts', ['topic_id' => $w->topic]));
    T::same([false, 11], [$guest['success'] ?? null, count($wiring->calls)], 'Gast: abgelehnt vor der Drossel (kein weiterer Limiter-Aufruf)');
});

T::run('Integration (J2): Standard-Renderer von RenderService liest Anhänge über AttachmentRepository::findMany()', static function (): void {
    $w = new CrWorld();
    $render = new RenderService($w->db, $w->settings, fn (): int => $w->now, fn (?int $u, string $a, array $c): AccessDecision => $w->access->can($u, $a, $c));
    $post = $w->post('Platzhalter');
    $attachment = $w->db->attachment(['post' => $post, 'user' => $w->author, 'mime_type' => 'image/png', 'extension' => 'png', 'width' => 40, 'height' => 30]);
    $foreign = $w->db->attachment(['post' => $w->post('Andere'), 'user' => $w->author, 'mime_type' => 'image/png', 'extension' => 'png', 'width' => 50, 'height' => 50]);
    $w->db->execute('UPDATE cms_community_posts SET body = ? WHERE id = ?', ['![Bild](attachment:' . $attachment . ') ![Fremd](attachment:' . $foreign . ')', $post]);
    $html = $render->presentRow($w->member, (array) (new PostRepository($w->db))->find($post));
    T::check(preg_match('~<img [^>]*width="40" height="30"~', $html) === 1, 'eigener Anhang: Bild mit Breite und Höhe aus community_attachments', $html);
    T::check(!str_contains($html, 'width="50"') && str_contains($html, 'Fremd'), 'Anhang eines anderen Beitrags: nur der Alternativtext', $html);
});
