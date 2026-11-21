<?php
/**
 * Community – JSON-API unter `/forum/api/<aktion>` (PR #4, Teil 3): Reaktion, Abo, Melden, Vorschau, Moderation, Lösung.
 *
 * Nur POST. Die Dienste entscheiden über Rechte, Pflichtgründe, Grenzen und Protokoll; dieser Controller prüft Form und Zielart,
 * ruft den Dienst und bildet das Ergebnis auf JSON und einen HTTP-Status ab. CSRF: der Router prüft vor der Route ein dauerhaftes Token
 * (Aktion `community_api`, Filter `cms_public_form_persistent_token`, siehe `tokenFilter()`), weil eine Seite mehrere Aufrufe sendet; es
 * kommt aus `csrf_token` (Formularfeld) oder dem Kopf `X-CSRF-Token`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Http;

use CMS\Auth;
use CMS\Services\Community\Service\ModerationService;
use CMS\Services\Community\Service\ReactionService;
use CMS\Services\Community\Service\RenderService;
use CMS\Services\Community\Service\ReportService;
use CMS\Services\Community\Service\SubscriptionService;
use CMS\Services\Community\Support\Codes;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class ApiController
{
    /** CSRF-Aktion des dauerhaften Tokens (Seite erzeugt es, der Router prüft es). */
    public const TOKEN_ACTION = 'community_api';

    /** Aktionen (Pfadsegment) in der Reihenfolge der Anmeldung. */
    public const ACTIONS = ['reaktion', 'abo', 'melden', 'vorschau', 'moderation', 'loesung'];

    /** Aktionen der Moderation (Teilmenge von `ModerationService::BULK_OPERATIONS`; Umbenennen und Ankündigen gehören in die Verwaltung). */
    public const MODERATION_OPS = [
        'topic.lock', 'topic.unlock', 'topic.pin', 'topic.unpin', 'topic.hide', 'topic.restore', 'topic.approve', 'topic.reject', 'topic.move',
        'post.hide', 'post.restore', 'post.approve', 'post.reject',
    ];

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function registerRoutes(object $router): void
    {
        if (!method_exists($router, 'addRoute')) {
            return;
        }
        foreach (['de', 'en'] as $locale) {
            $router->addRoute('POST', \CMS\Services\Community\Support\Urls::apiPattern($locale), fn (string $action) => $this->handle($action));
        }
    }

    /**
     * Filter `cms_public_form_persistent_token`: für Aufrufe der API das übermittelte Token mit der Aktion `community_api` prüfen lassen.
     *
     * @return mixed bei fremden Pfaden unverändert
     */
    public static function tokenFilter(mixed $current, mixed $uri, mixed $method = 'POST'): mixed
    {
        if ($current !== null || !is_string($uri) || \CMS\Services\Community\Support\Urls::isApiPath($uri) === false) {
            return $current;
        }
        $token = is_string($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : (is_string($_POST['csrf_token'] ?? null) ? (string) $_POST['csrf_token'] : '');

        return ['action' => self::TOKEN_ACTION, 'token' => $token];
    }

    // ── Verarbeitung ────────────────────────────────────────────────────────────────────────────────────────────

    private function handle(string $action): void
    {
        $uid = 0;
        try {
            $uid = (int) (Auth::instance()->currentUser()->id ?? 0);
        } catch (\Throwable) {
        }
        [$status, $body] = $this->dispatch($action, $_POST, $uid);
        $this->send($status, $body);
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0: int, 1: array<string, mixed>} HTTP-Status und JSON-Körper
     */
    public function dispatch(string $action, array $post, int $userId): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return [404, ['success' => false, 'error' => 'unknown_action']];
        }
        if ($userId < 1) {
            return [401, ['success' => false, 'error' => 'login_required']];
        }
        try {
            $result = match ($action) {
                'reaktion' => $this->reaction($userId, $post),
                'abo' => $this->subscription($userId, $post),
                'melden' => $this->report($userId, $post),
                'vorschau' => $this->preview($userId, $post),
                'moderation' => $this->moderation($userId, $post),
                'loesung' => $this->solution($userId, $post),
            };
        } catch (\Throwable $e) {
            \CMS\Logger::instance()->withChannel('community')->warning('Community-API fehlgeschlagen ({action}): {exception}', ['action' => $action, 'exception' => $e]);
            $result = ['success' => false, 'error' => 'db_error'];
        }

        return [self::statusOf($result), self::present($result)];
    }

    /** @param array<string, mixed> $post @return array<string, mixed> */
    private function reaction(int $userId, array $post): array
    {
        $postId = self::int($post['post_id'] ?? null);
        $reaction = is_string($post['reaction'] ?? null) ? trim((string) $post['reaction']) : '';
        if ($postId < 1) {
            return ['success' => false, 'error' => 'invalid_input'];
        }
        $service = ReactionService::instance();

        return $reaction === '' ? $service->remove($userId, $postId) : $service->set($userId, $postId, $reaction);
    }

    /** @param array<string, mixed> $post @return array<string, mixed> */
    private function subscription(int $userId, array $post): array
    {
        $type = (string) ($post['type'] ?? '');
        $id = self::int($post['id'] ?? null);
        $mode = (string) ($post['mode'] ?? 'instant');
        if (!in_array($type, SubscriptionService::TARGET_TYPES, true) || $id < 1 || !in_array($mode, SubscriptionService::P1_MODES, true)) {
            return ['success' => false, 'error' => 'invalid_input'];
        }
        $service = SubscriptionService::instance();

        return $mode === 'none' ? $service->unsubscribe($userId, $type, $id) : $service->subscribe($userId, $type, $id, $mode);
    }

    /** @param array<string, mixed> $post @return array<string, mixed> */
    private function report(int $userId, array $post): array
    {
        $type = (string) ($post['type'] ?? '');
        $id = self::int($post['id'] ?? null);
        $reason = (string) ($post['reason'] ?? '');
        if (!in_array($type, ReportService::TARGET_TYPES, true) || $id < 1 || !in_array($reason, ReportService::REASONS, true)) {
            return ['success' => false, 'error' => 'invalid_input'];
        }

        return ReportService::instance()->create($userId, $type, $id, $reason, (string) ($post['message'] ?? ''), isset($post['notify']) && $post['notify'] === '1');
    }

    /** @param array<string, mixed> $post @return array<string, mixed> */
    private function preview(int $userId, array $post): array
    {
        $target = [];
        foreach (RenderService::PREVIEW_ACTIONS as $key => $unused) {
            if (self::int($post[$key] ?? null) > 0) {
                $target = [$key => self::int($post[$key])];
                break;
            }
        }

        return RenderService::instance()->preview($userId, (string) ($post['body'] ?? ''), $target, I18n::requestLocale());
    }

    /** @param array<string, mixed> $post @return array<string, mixed> */
    private function moderation(int $userId, array $post): array
    {
        $op = (string) ($post['op'] ?? '');
        $id = self::int($post['id'] ?? null);
        if (!in_array($op, self::MODERATION_OPS, true) || $id < 1) {
            return ['success' => false, 'error' => 'invalid_input'];
        }
        $params = ['reason' => trim((string) ($post['reason'] ?? ''))];
        if ($op === 'topic.move') {
            $params['target_forum_id'] = self::int($post['target_forum_id'] ?? null);
        }
        if (in_array($op, ['topic.lock', 'topic.pin'], true) && self::int($post['until'] ?? null) > 0) {
            $params['until'] = self::int($post['until']);
        }
        $result = ModerationService::instance()->bulk($userId, $op, [$id], $params);
        if (empty($result['success'])) {
            return $result;
        }
        // Sammelaktion mit einem Ziel: das Ergebnis des Ziels gilt.
        $item = is_array($result['items'][0] ?? null) ? $result['items'][0] : ['success' => false, 'error' => 'db_error'];

        return $item + ['op' => $op, 'id' => $id];
    }

    /** @param array<string, mixed> $post @return array<string, mixed> */
    private function solution(int $userId, array $post): array
    {
        $topicId = self::int($post['topic_id'] ?? null);
        $postId = self::int($post['post_id'] ?? null);
        if ($topicId < 1) {
            return ['success' => false, 'error' => 'invalid_input'];
        }
        $service = ModerationService::instance();

        return $postId > 0 ? $service->markSolution($userId, $topicId, $postId) : $service->unmarkSolution($userId, $topicId);
    }

    // ── Ausgabe ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** HTTP-Status zu einem Dienstergebnis: Erfolg 200, bekannte Ablehnungsgründe mit ihrem Status, sonst 422. */
    public static function statusOf(array $result): int
    {
        if (!empty($result['success'])) {
            return 200;
        }
        $code = (string) ($result['error'] ?? '');

        return Codes::ACCESS_REASONS[$code] ?? (in_array($code, ['db_error', 'storage_error'], true) ? 500 : 422);
    }

    /**
     * JSON-Körper: Erfolg unverändert, Fehler mit `error` (Code) und `message` (Text in der Sprache der Anfrage). Interne Felder wie
     * `ip_hash` liefert kein Dienst.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function present(array $result): array
    {
        if (!empty($result['success'])) {
            return $result;
        }
        $code = (string) ($result['error'] ?? 'db_error');
        $message = '';
        foreach ([Codes::errorKey($code), Codes::accessKey($code)] as $key) {
            $text = I18n::t($key);
            if ($text !== '' && $text !== $key) {
                $message = $text;
                break;
            }
        }
        $result['message'] = $message !== '' ? $message : 'Das hat nicht geklappt (' . $code . ').';

        return $result;
    }

    /** @param array<string, mixed> $body */
    private function send(int $status, array $body): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    private static function int(mixed $value): int
    {
        return is_string($value) && preg_match('/^[1-9]\d{0,17}$/D', $value) === 1 ? (int) $value : (is_int($value) && $value > 0 ? $value : 0);
    }
}
