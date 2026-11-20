<?php
/**
 * Integrationsfall über Einheitsgrenzen für `AttachmentService` (PR #2b, J2; Vertrag DESIGN-2b §6 J2, Fassung 3.1 GK6). Besitzer: J2.
 *
 *  - D `$throttle`: die Standard-Closure läuft gegen den echten `ContentGuard::throttle('upload', …)` (30 je 3.600 s für Mitglieder);
 *    die Drossel steht nach Rechten und vor der Datei (Vertrag §4.6).
 *
 * Geladen von `run.php` am Ende; nutzt `CommunityWiring` (TESTS/lib/CommunityTestDatabase.php).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\AttachmentService;
use CMS\Services\Community\Support\Settings;

T::run('Integration (J2): AttachmentService::upload über die Standard-Drossel gegen ContentGuard::throttle()', static function (): void {
    $db = CommunityTestDatabase::create();
    $user = $db->user();
    $forum = $db->forum();
    $service = new AttachmentService(
        $db,
        Settings::fromArray([]),
        static fn (): int => 1767225600,
        static fn (?int $u, string $a, array $c): AccessDecision => AccessDecision::allow(),
        isUploaded: static fn (string $path): bool => false
    );
    $token = $service->newToken();
    $file = ['name' => 'bild.png', 'tmp_name' => '/gibt/es/nicht.png', 'error' => UPLOAD_ERR_OK, 'size' => 100];
    $wiring = new CommunityWiring();
    $results = $wiring->run(static function () use ($service, $user, $forum, $file, $token): array {
        $out = [];
        for ($i = 1; $i <= 31; $i++) {
            $out[] = $service->upload($user, $file, ['forum_id' => $forum], $token);
        }

        return $out;
    });
    T::same(array_fill(0, 30, 'attachment_invalid'), array_map(static fn (array $r): mixed => $r['error'] ?? null, array_slice($results, 0, 30)), '30 Uploads je Stunde passieren die Drossel (danach scheitert die Datei)');
    T::same(['rate_limited', 3600], [$results[30]['error'] ?? null, $results[30]['retry_after'] ?? null], '31. Upload: rate_limited mit retry_after = Fenster aus ContentGuard::THROTTLE');
    T::same(['community_upload community:u:' . $user . ' 30/3600'], $wiring->buckets(), 'Aktion upload, Kennung des Mitglieds, Grenze 30 je 3.600 s');
});
