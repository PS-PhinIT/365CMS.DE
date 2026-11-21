<?php
/**
 * Worker-Aktionen der Einheit R (PR #2b, Darstellung) für `TESTS/community-db/worker.php`; Abschnitt `render-race` in
 * `sections-render.php`. Geladen im Gültigkeitsbereich des Workers (`$db`, `$settings`, `$can`, `$posts` u. a.).
 *
 *   render.lazy   wiederholt: Zeile lesen → `RenderService::present()` (Purifier mit Verzögerung `delay` µs zwischen Lesen und
 *                 Speichern) → Zeile neu lesen. Abweichung = gespeichertes HTML, das nicht `render(body)` des gelesenen Textes ist.
 *   render.edits  wiederholt `PostService::edit()` ohne Renderer (der Cache wird jedes Mal NULL).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Service\PostService;
use CMS\Services\Community\Service\RenderService;
use CMS\Services\Community\Support\ContentRenderer;
use CMS\Services\PurifierService;

// CommonMark-Bündel und HTMLPurifier (CMS\Vendor\…) über den produktiven Assets-Autoloader.
require_once ABSPATH . 'assets/autoload.php';

/** @var \CMS\Database $db */
/** @var \CMS\Services\Community\Support\Settings $settings */
/** @var \Closure $can */
/** @var PostService $posts */

return [
    'render.lazy' => static function (array $args) use ($db, $settings, $can): array {
        $postId = (int) $args['post'];
        $delay = max(0, (int) ($args['delay'] ?? 0));
        $purifier = PurifierService::getInstance();
        $renderer = new ContentRenderer(purify: static function (string $html) use ($delay, $purifier): ?string {
            if ($delay > 0) {
                usleep($delay);
            }

            return $purifier->isAvailable() ? $purifier->purify($html, ContentRenderer::PROFILE) : null;
        });
        $check = new ContentRenderer();
        $render = new RenderService(
            $db,
            $settings,
            null,
            $can,
            $renderer,
            static fn (): object => throw new \LogicException('kein Job im Wettlauf'),
            static fn (string $html, int $id): string => $html,
            static fn (string $action, ?int $userId): array => ['allowed' => true, 'retry_after' => 0]
        );
        $repo = new PostRepository($db);
        $presented = 0;
        $anomalies = [];
        for ($i = 0; $i < (int) $args['count']; $i++) {
            $row = $repo->find($postId);
            if ($row === null) {
                return ['success' => false, 'error' => 'not_found'];
            }
            $render->flush();
            $out = $render->present(isset($args['viewer']) ? (int) $args['viewer'] : null, [$row]);
            if (!is_string($out[0]['html'] ?? null)) {
                $anomalies[] = ['kind' => 'no_html', 'edit_count' => (int) $row['edit_count']];
            }
            $presented++;
            $after = $repo->find($postId);
            if ($after !== null && $after['body_html'] !== null && $after['body_html'] !== $check->render((string) $after['body'], ['post_id' => $postId])['html']) {
                $anomalies[] = ['kind' => 'stale_html', 'edit_count' => (int) $after['edit_count'], 'body' => (string) $after['body']];
            }
        }

        return ['success' => true, 'presented' => $presented, 'anomaly_count' => count($anomalies), 'anomalies' => array_slice($anomalies, 0, 5)];
    },
    'render.edits' => static function (array $args) use ($posts): array {
        $edited = 0;
        $errors = [];
        for ($i = 0; $i < (int) $args['count']; $i++) {
            $result = $posts->edit((int) $args['user'], (int) $args['post'], 'Fassung ' . $args['tag'] . '-' . $i);
            if (!empty($result['success'])) {
                $edited++;
            } else {
                $errors[] = (string) ($result['error'] ?? '?');
            }
        }

        return ['success' => $errors === [], 'edited' => $edited, 'errors' => array_slice($errors, 0, 5)];
    },
];
