<?php
/**
 * Darstellung der Community (PR #2b; Vertrag DESIGN-2b §4.3, §4.7, Entwurf render §1.2, §2.2 bis §2.5): Brücke zwischen
 * `Support\ContentRenderer` und der Datenbank.
 *
 * - Schreib-Rendern für die Dienste (`render()`, `analyze()`), immer vor der Transaktion (Vertrag §1 Nr. 2).
 * - Anzeige je Betrachter (`present()`, `presentRow()`): gespeichertes HTML ab `ContentRenderer::VERSION`, sonst Nachrendern im
 *   Budget (Mitglieder 20 Beiträge und 200.000 Zeichen je Instanz, Gäste 5 und 50.000) mit `storeRendered()` im Autocommit
 *   (Wächter `edit_count`). Zitat-Blöcke werden je Betrachter aufgelöst: alle IDs einer Seite in einer `resolveMany()`, höchstens
 *   MAX_QUOTE_LOOKUPS; IDs darüber gelten als nicht sichtbar. Der Ausweichpfad gibt den Text nur neutralisiert aus. Lese-APIs
 *   erhalten kein `body_html` und `body` nur neutralisiert (§1 Nr. 9, `QuoteService::presentable()`).
 * - Vorschau (`preview()`) nur mit Schreibrecht am Ziel und Drossel `preview`; schreibt nichts.
 * - Neu-Render-Job (`startJob()`, `runJobStep()`, `step()`, `cronTick()`) über `BatchJobService`, Typ JOB_TYPE, Scope je VERSION.
 *
 * Keine Sperren, keine Transaktion: `storeRendered()` ändert eine Zeile über den Primärschlüssel und nur außerhalb einer offenen
 * Transaktion. Die Uhr wird je `present()`/`preview()` einmal gelesen; `step()` braucht keine Uhr. Konstruktor ohne Datenbank.
 *
 * Ergebnisse sind Arrays `{success, error?, retry_after?, …}`; `error` ist ein Code aus `Support\Codes`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Logger;
use CMS\Services\BatchJobService;
use CMS\Services\Community\Repository\AttachmentRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Support\ContentRenderer;
use CMS\Services\Community\Support\Events;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Urls;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-import-type Stats from ContentRenderer
 */
final class RenderService
{
    /** Typ des Neu-Render-Jobs in `batch_jobs`. */
    public const JOB_TYPE = 'community_render';
    /** Beiträge je Job-Schritt. */
    public const STEP_LIMIT = 50;
    /** ID-Fenster je Job-Schritt (PRIMARY-Bereich). */
    public const STEP_WINDOW = 5000;
    /** Zeichen je Job-Schritt; darüber endet der Schritt innerhalb des Fensters (mindestens ein Beitrag je Schritt). */
    public const STEP_MAX_CHARS = 500000;
    /** Schritte je Cron-Lauf. */
    public const CRON_STEPS = 20;
    /** Budget des Nachrenderns je Instanz für Mitglieder: Beiträge. */
    public const LAZY_MAX_POSTS = 20;
    /** Budget des Nachrenderns je Instanz für Mitglieder: Zeichen. */
    public const LAZY_MAX_CHARS = 200000;
    /** Budget des Nachrenderns je Instanz für Gäste: Beiträge (Vertrag §4.7 Nr. 4). */
    public const LAZY_GUEST_POSTS = 5;
    /** Budget des Nachrenderns je Instanz für Gäste: Zeichen. */
    public const LAZY_GUEST_CHARS = 50000;
    /** Höchstens so viele Zitat-IDs löst ein Aufruf von `present()` auf (Vertrag §4.3, S9). */
    public const MAX_QUOTE_LOOKUPS = 200;
    /** Ziel der Vorschau => Schreibaktion, deren Recht `preview()` verlangt (Vertrag §4.7 Nr. 5). */
    public const PREVIEW_ACTIONS = ['forum_id' => 'create_topic', 'topic_id' => 'reply', 'post_id' => 'edit'];

    private const ESCAPE = ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5;
    /** Öffnende und schließende Blockzitat-Tags im gereinigten HTML. */
    private const BLOCKQUOTE_TAGS = '#<blockquote\b[^>]*>|</blockquote>#i';
    /** Zitat-ID am öffnenden Tag (Attributreihenfolge beliebig; der Purifier lässt nur Zahlen zu). */
    private const QUOTE_ATTRIBUTE = '/\sdata-quote-post="([1-9][0-9]{0,18})"/';

    private static ?self $instance = null;

    private Database $db;
    private Settings $settings;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(?int, string, array<string, mixed>): AccessDecision */
    private \Closure $can;
    private ContentRenderer $renderer;
    /** @var \Closure(): object */
    private \Closure $jobs;
    /** @var \Closure(string, int): mixed */
    private \Closure $filter;
    /** @var \Closure(string, ?int): array{allowed: bool, retry_after: int} */
    private \Closure $throttle;
    private PostRepository $posts;
    private QuoteService $quotes;

    /** Nachgerenderte Beiträge und Zeichen dieser Instanz (Budget, `flush()` setzt zurück). */
    private int $lazyPosts = 0;
    private int $lazyChars = 0;

    /**
     * @param \Closure(): int|null $clock Unix-Zeit (Standard `time()`)
     * @param \Closure(?int, string, array<string, mixed>): AccessDecision|null $can Standard: `CommunityAccess::instance()->can()`; ohne diese Klasse wird alles abgelehnt
     * @param ContentRenderer|null $renderer Standard: Renderer mit Anhängen aus `AttachmentRepository::findMany()`
     * @param \Closure(): object|null $jobs Standard: `BatchJobService::getInstance()` (`start`, `step`, `cancel`, `find`, `findActive`, `latest`, `toPublic`)
     * @param \Closure(string, int): mixed|null $filter Standard: Filter `cms_community_render_post` (`Events::applyFilter()`)
     * @param \Closure(string, ?int): array{allowed: bool, retry_after: int}|null $throttle Standard: `ContentGuard::instance()->throttle()`
     */
    public function __construct(
        ?Database $db = null,
        ?Settings $settings = null,
        ?\Closure $clock = null,
        ?\Closure $can = null,
        ?ContentRenderer $renderer = null,
        ?\Closure $jobs = null,
        ?\Closure $filter = null,
        ?\Closure $throttle = null
    ) {
        $this->db = $db ?? Database::instance();
        $this->settings = $settings ?? Settings::getInstance();
        $this->clock = $clock ?? static fn (): int => time();
        $this->can = $can ?? static function (?int $userId, string $action, array $context): AccessDecision {
            return class_exists(CommunityAccess::class)
                ? CommunityAccess::instance()->can($userId, $action, $context)
                : AccessDecision::deny('no_permission');
        };
        $database = $this->db;
        $this->renderer = $renderer ?? new ContentRenderer(attachments: static function (array $ids) use ($database): array {
            return class_exists(AttachmentRepository::class) ? (new AttachmentRepository($database))->findMany($ids) : [];
        });
        $this->jobs = $jobs ?? static fn (): object => BatchJobService::getInstance();
        $this->filter = $filter ?? static fn (string $html, int $postId): mixed => Events::applyFilter(Events::RENDER_POST, $html, $postId);
        // Ohne ContentGuard (Einheit S) bleibt die Vorschau zu (fail-closed).
        $this->throttle = $throttle ?? static function (string $action, ?int $userId): array {
            return class_exists(ContentGuard::class)
                ? ContentGuard::instance()->throttle($action, $userId)
                : ['allowed' => false, 'retry_after' => 60];
        };
        $this->posts = new PostRepository($this->db);
        $this->quotes = new QuoteService($this->db, $this->settings, $this->clock, $this->can);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Budget des Nachrenderns zurücksetzen (Cron und Worker je Auftrag). */
    public function flush(): void
    {
        $this->lazyPosts = 0;
        $this->lazyChars = 0;
    }

    // ── Schreiben ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Schreib-Rendern für die Dienste (delegiert an `ContentRenderer::render()`, Ergebnisform dort). Nie in einer Transaktion aufrufen.
     *
     * @param array{post_id?: ?int, uploader_id?: ?int, images?: bool} $options
     * @return array{ok: bool, html: ?string, version: int, stats: Stats, error?: 'render_failed'}
     */
    public function render(string $markdown, array $options = []): array
    {
        return $this->renderer->render($markdown, $options);
    }

    /**
     * Zählung ohne HTML (delegiert an `ContentRenderer::analyze()`; dieselben Grenzen).
     *
     * @return array{ok: bool, stats: Stats, error?: 'render_failed'}
     */
    public function analyze(string $markdown): array
    {
        return $this->renderer->analyze($markdown);
    }

    // ── Anzeige ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Anzeige je Betrachter. `$items`: Einträge wie `PostService::listPage()['items']` oder Beitragszeilen (ohne `view` = voll).
     * Volle Einträge erhalten `html` (Zitate aufgelöst, Filter angewandt); kein Eintrag enthält danach `body_html`, `body` ist
     * neutralisiert (`QuoteService::presentable()`). Platzhalter tragen keinen Text und kein `html`. Wirft nie.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function present(?int $viewerId, array $items, ?string $locale = null): array
    {
        $viewer = $viewerId !== null && $viewerId > 0 ? $viewerId : null;
        $items = array_values($items);
        try {
            $locale = I18n::normalizeLocale($locale ?? I18n::requestLocale());
            $now = ($this->clock)();
            $chosen = [];
            foreach ($items as $index => $item) {
                if (is_array($item) && self::isFull($item)) {
                    $chosen[$index] = ['body' => self::text($item['body'] ?? null), 'html' => $this->chooseHtml($viewer, $item)];
                }
            }
            $decide = $this->decisions($viewer, $chosen, $now);
            $out = [];
            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $presented = QuoteService::presentable($item, $decide);
                if (isset($chosen[$index])) {
                    $presented['html'] = $this->output($chosen[$index], $decide, $locale, (int) ($item['id'] ?? 0));
                } else {
                    unset($presented['html']);
                }
                $out[] = $presented;
            }

            return $out;
        } catch (\Throwable $e) {
            // Unerwarteter Fehler: alles verborgen, nur der neutralisierte Klartext (fail-closed).
            self::warn('present', $e);
            $hidden = static fn (int $id): bool => false;
            $out = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $presented = QuoteService::presentable($item, $hidden);
                unset($presented['html']);
                if (self::isFull($item)) {
                    $presented['html'] = $this->fallback(self::text($item['body'] ?? null), $hidden, 'de');
                }
                $out[] = $presented;
            }

            return $out;
        }
    }

    /** HTML einer Beitragszeile für einen Betrachter (dieselben Schritte wie `present()`); '' für Platzhalter. Wirft nie. */
    public function presentRow(?int $viewerId, array $row, ?string $locale = null): string
    {
        $presented = $this->present($viewerId, [$row], $locale)[0] ?? [];

        return is_string($presented['html'] ?? null) ? $presented['html'] : '';
    }

    /**
     * Zitat-Blöcke im gereinigten HTML: äußerste Blockzitate mit `data-quote-post` (der Renderer setzt das Attribut nur auf oberster
     * Ebene), samt allen verschachtelten Blockzitaten. Fehlt das schließende Tag, reicht der Block bis zum Ende (fail-closed).
     *
     * @return list<array{id: int, start: int, open_end: int, end: int}> start/open_end: öffnendes Tag, end: hinter `</blockquote>`
     */
    public static function quoteSpans(string $html): array
    {
        if (preg_match_all(self::BLOCKQUOTE_TAGS, $html, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $spans = [];
        $depth = 0;
        $open = null;
        foreach ($matches[0] as [$tag, $at]) {
            if ($tag[1] === '/') {
                $depth = max(0, $depth - 1);
                if ($open !== null && $depth === $open['depth']) {
                    $spans[] = ['id' => $open['id'], 'start' => $open['start'], 'open_end' => $open['open_end'], 'end' => $at + strlen($tag)];
                    $open = null;
                }
                continue;
            }
            if ($open === null && preg_match(self::QUOTE_ATTRIBUTE, $tag, $id) === 1) {
                $open = ['id' => (int) $id[1], 'start' => $at, 'open_end' => $at + strlen($tag), 'depth' => $depth];
            }
            $depth++;
        }
        if ($open !== null) {
            $spans[] = ['id' => $open['id'], 'start' => $open['start'], 'open_end' => $open['open_end'], 'end' => strlen($html)];
        }

        return $spans;
    }

    // ── Vorschau ────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Vorschau ohne Speichern (Vertrag §4.7 Nr. 5). `$target` enthält genau einen Schlüssel aus PREVIEW_ACTIONS mit int ≥ 1; verlangt
     * wird das Recht der zugehörigen Schreibaktion am Ziel (ohne Inhaltsgrenzen). Grenzgründe (`TopicService::LIMIT_REASONS`) gelten als
     * erlaubt: wer heute nicht mehr schreiben darf, darf vorbereiten. Danach die Drossel `preview`, dann Rendern (`post_id` nur bei
     * `edit`, `uploader_id` = Mitglied) und die Zitatauflösung wie bei `present()`; der Filter erhält `postId` 0.
     *
     * @param array<string, mixed> $target
     * @return array{success: true, html: string, stats: Stats}|array{success: false, error: string, retry_after?: int}
     *   Fehler: invalid_input | body_too_short | body_too_long | Grund aus can() (login_required, banned_write, not_found, …) | rate_limited | render_failed
     */
    public function preview(?int $userId, string $markdown, array $target = [], ?string $locale = null): array
    {
        $key = count($target) === 1 ? array_key_first($target) : null;
        if (!is_string($key) || !isset(self::PREVIEW_ACTIONS[$key]) || !is_int($target[$key]) || $target[$key] < 1) {
            return self::failure('invalid_input');
        }
        $body = PostService::normalizeBody($markdown);
        if ($body === null) {
            return self::failure('invalid_input');
        }
        $length = mb_strlen($body, 'UTF-8');
        if ($length < $this->settings->int('post_min')) {
            return self::failure('body_too_short');
        }
        if ($length > $this->settings->int('post_max')) {
            return self::failure('body_too_long');
        }
        $userId = $userId !== null && $userId > 0 ? $userId : null;
        $now = ($this->clock)();
        $decision = ($this->can)($userId, self::PREVIEW_ACTIONS[$key], $target + ['now' => $now]);
        if (!$decision->allowed && !in_array($decision->reason, TopicService::LIMIT_REASONS, true)) {
            return self::denied($decision);
        }
        if ($userId === null) {
            return self::failure('login_required');
        }
        $gate = ($this->throttle)('preview', $userId);
        if (empty($gate['allowed'])) {
            return ['success' => false, 'error' => 'rate_limited', 'retry_after' => max(1, (int) ($gate['retry_after'] ?? 60))];
        }
        $rendered = $this->renderer->render($body, ['post_id' => $key === 'post_id' ? $target[$key] : null, 'uploader_id' => $userId]);
        if (!$rendered['ok']) {
            return self::failure('render_failed');
        }
        try {
            $locale = I18n::normalizeLocale($locale ?? I18n::requestLocale());
            $entry = ['body' => $body, 'html' => $rendered['html']];
            $decide = $this->decisions($userId, [$entry], $now);
            $html = $this->output($entry, $decide, $locale, 0);
        } catch (\Throwable $e) {
            self::warn('preview', $e);
            $html = $this->fallback($body, static fn (int $id): bool => false, 'de');
        }

        return ['success' => true, 'html' => $html, 'stats' => $rendered['stats']];
    }

    // ── Neu-Render-Job ──────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Job starten oder fortsetzen (ein aktiver Job je Scope `v<VERSION>` bzw. `v<VERSION>:all`). `$all`: auch aktuelle Zeilen neu
     * rendern (nach einem Wechsel von `base_path`, Pflicht für PR #3).
     *
     * @return array{success: true, job: array<string, mixed>, resumed: bool}|array{success: false, error: 'db_error'}
     */
    public function startJob(bool $all = false, ?int $userId = null): array
    {
        try {
            $jobs = ($this->jobs)();
            $max = $this->posts->maxId();
            $started = $jobs->start(self::JOB_TYPE, self::scope($all), $max, ['after_id' => 0, 'max_id' => $max, 'all' => $all, 'version' => ContentRenderer::VERSION], $userId !== null && $userId > 0 ? $userId : null);

            return ['success' => true, 'job' => $jobs->toPublic((array) $started['job']), 'resumed' => (bool) ($started['resumed'] ?? false)];
        } catch (\Throwable $e) {
            self::warn('startJob', $e);

            return self::failure('db_error');
        }
    }

    /**
     * Ein Schritt eines Jobs über `BatchJobService::step()`. Ein Job eines anderen Typs oder eine unbekannte ID ergibt `invalid_input`.
     *
     * @return array{success: true, job: array<string, mixed>}|array{success: false, error: 'invalid_input'|'db_error'}
     */
    public function runJobStep(int $jobId): array
    {
        if ($jobId < 1) {
            return self::failure('invalid_input');
        }
        try {
            $jobs = ($this->jobs)();
            $job = $jobs->find($jobId);
            if (!is_array($job) || ($job['type'] ?? null) !== self::JOB_TYPE) {
                return self::failure('invalid_input');
            }
            $after = $jobs->step($jobId, fn (array $job): array => $this->step((array) ($job['state'] ?? [])));

            return ['success' => true, 'job' => $jobs->toPublic((array) $after)];
        } catch (\Throwable $e) {
            self::warn('runJobStep', $e);

            return self::failure('db_error');
        } finally {
            $this->flush();
        }
    }

    /**
     * Job abbrechen (nur Typ JOB_TYPE).
     *
     * @return array{success: bool, error?: 'invalid_input'|'db_error'}
     */
    public function cancelJob(int $jobId): array
    {
        if ($jobId < 1) {
            return self::failure('invalid_input');
        }
        try {
            $jobs = ($this->jobs)();
            $job = $jobs->find($jobId);
            if (!is_array($job) || ($job['type'] ?? null) !== self::JOB_TYPE) {
                return self::failure('invalid_input');
            }

            return ['success' => (bool) $jobs->cancel($jobId)];
        } catch (\Throwable $e) {
            self::warn('cancelJob', $e);

            return self::failure('db_error');
        }
    }

    /**
     * Öffentliche Sicht (`toPublic()`) des aktiven Jobs dieser VERSION (beide Scopes), sonst des jüngsten; `null` ohne Job oder bei Fehler.
     *
     * @return array<string, mixed>|null
     */
    public function jobStatus(): ?array
    {
        try {
            $jobs = ($this->jobs)();
            $latest = null;
            foreach ([false, true] as $all) {
                $active = $jobs->findActive(self::JOB_TYPE, self::scope($all));
                if (is_array($active)) {
                    return $jobs->toPublic($active);
                }
                $last = $jobs->latest(self::JOB_TYPE, self::scope($all));
                if (is_array($last) && ($latest === null || (int) $last['id'] > (int) $latest['id'])) {
                    $latest = $last;
                }
            }

            return $latest === null ? null : $jobs->toPublic($latest);
        } catch (\Throwable $e) {
            self::warn('jobStatus', $e);

            return null;
        }
    }

    /**
     * Reiner Arbeitsschritt: Beiträge im Fenster `after_id < id ≤ min(after_id + STEP_WINDOW, max_id)` mit älterer Version (Modus
     * `all`: auch der aktuellen) rendern und mit dem Wächter `edit_count` speichern. Höchstens STEP_LIMIT Beiträge und STEP_MAX_CHARS
     * Zeichen (mindestens ein Beitrag); dann rückt `after_id` auf die letzte bearbeitete ID, sonst auf das Fensterende. Eine Zeile,
     * die nicht rendert oder nicht speichert, zählt als `failed` und wird übersprungen. `processed` = Fortschritt in IDs.
     *
     * @param array<string, mixed> $state {after_id: int, max_id: int, all: bool, version: int}
     * @return array{processed: int, failed: int, state: array<string, mixed>, done: bool, message: string}
     */
    public function step(array $state): array
    {
        $after = max(0, (int) ($state['after_id'] ?? 0));
        $max = max(0, (int) ($state['max_id'] ?? 0));
        $all = !empty($state['all']);
        $state = ['after_id' => $after, 'max_id' => $max, 'all' => $all, 'version' => ContentRenderer::VERSION];
        if ($after >= $max) {
            return ['processed' => 0, 'failed' => 0, 'state' => $state, 'done' => true, 'message' => ''];
        }
        $until = min($after + self::STEP_WINDOW, $max);
        $rows = $this->posts->renderBatch($after, $until, $all ? ContentRenderer::VERSION + 1 : ContentRenderer::VERSION, self::STEP_LIMIT);
        $chars = 0;
        $last = $after;
        $failed = 0;
        $stopped = false;
        foreach ($rows as $row) {
            $body = (string) ($row['body'] ?? '');
            if ($last > $after && $chars + strlen($body) > self::STEP_MAX_CHARS) {
                $stopped = true;
                break;
            }
            $chars += strlen($body);
            $last = (int) $row['id'];
            $rendered = $this->renderer->render($body, ['post_id' => (int) $row['id']]);
            if (!$rendered['ok'] || $rendered['html'] === null) {
                $failed++;
                continue;
            }
            try {
                $this->posts->storeRendered((int) $row['id'], (int) $row['edit_count'], $rendered['html'], (int) $rendered['version'], $all);
            } catch (\PDOException | \RuntimeException | \InvalidArgumentException $e) {
                self::warn('step', $e);
                $failed++;
            }
        }
        $next = $stopped || count($rows) >= self::STEP_LIMIT ? $last : $until;
        $state['after_id'] = $next;

        return ['processed' => $next - $after, 'failed' => $failed, 'state' => $state, 'done' => $next >= $max, 'message' => ''];
    }

    /** Cron (`cms_cron_hourly`): fertiger Job dieser VERSION → nichts; sonst `startJob()` und bis CRON_STEPS Schritte. Wirft nie. */
    public function cronTick(): void
    {
        try {
            $jobs = ($this->jobs)();
            $latest = $jobs->latest(self::JOB_TYPE, self::scope(false));
            if (is_array($latest) && ($latest['status'] ?? '') === BatchJobService::STATUS_DONE) {
                return;
            }
            $started = $this->startJob(false);
            $jobId = (int) ($started['job']['id'] ?? 0);
            if (empty($started['success']) || $jobId < 1) {
                return;
            }
            for ($i = 0; $i < self::CRON_STEPS; $i++) {
                $job = (array) $jobs->step($jobId, fn (array $job): array => $this->step((array) ($job['state'] ?? [])));
                if (!empty($job['busy']) || ($job['status'] ?? '') !== BatchJobService::STATUS_RUNNING) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            self::warn('cronTick', $e);
        } finally {
            $this->flush();
        }
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * HTML eines vollen Eintrags: aktuelles gespeichertes HTML, sonst im Budget nachrendern (und im Autocommit speichern), sonst
     * gespeichertes HTML ab MIN_SERVE_VERSION, sonst `null` (Ausweichpfad).
     *
     * @param array<string, mixed> $item
     */
    private function chooseHtml(?int $viewerId, array $item): ?string
    {
        $cached = $item['body_html'] ?? null;
        if (is_string($cached) && (int) ($item['rendered_version'] ?? 0) >= ContentRenderer::VERSION) {
            return $cached;
        }
        $body = $item['body'] ?? null;
        if (is_string($body) && $this->withinBudget($viewerId, strlen($body))) {
            $postId = (int) ($item['id'] ?? 0);
            $rendered = $this->renderer->render($body, ['post_id' => $postId > 0 ? $postId : null, 'uploader_id' => null]);
            if ($rendered['ok'] && $rendered['html'] !== null) {
                $this->store($item, $rendered['html'], (int) $rendered['version']);

                return $rendered['html'];
            }
        }

        return self::servable($item) ? (string) $item['body_html'] : null;
    }

    /** Budget des Nachrenderns je Instanz; Gäste haben ein kleineres (Vertrag §4.7 Nr. 4). */
    private function withinBudget(?int $viewerId, int $chars): bool
    {
        $posts = $viewerId === null ? self::LAZY_GUEST_POSTS : self::LAZY_MAX_POSTS;
        $maxChars = $viewerId === null ? self::LAZY_GUEST_CHARS : self::LAZY_MAX_CHARS;
        if ($this->lazyPosts >= $posts || $this->lazyChars + $chars > $maxChars) {
            return false;
        }
        $this->lazyPosts++;
        $this->lazyChars += $chars;

        return true;
    }

    /** Darf gespeichertes HTML ausgeliefert werden? (einzige Stelle; ältere Fassungen enthalten womöglich Fehler) */
    private static function servable(array $row): bool
    {
        return ($row['body_html'] ?? null) !== null && (int) ($row['rendered_version'] ?? 0) >= ContentRenderer::MIN_SERVE_VERSION;
    }

    /**
     * Frisch gerendertes HTML im Autocommit speichern: nur außerhalb einer offenen Transaktion und mit bekanntem `edit_count`
     * (Wächter: eine Bearbeitung dazwischen verhindert das Schreiben). Fehler (auch Lock-Timeout) ergeben eine Warnung.
     *
     * @param array<string, mixed> $item
     */
    private function store(array $item, string $html, int $version): void
    {
        $postId = (int) ($item['id'] ?? 0);
        if ($postId < 1 || !isset($item['edit_count']) || !is_numeric($item['edit_count'])) {
            return;
        }
        try {
            if ($this->db->getPdo()->inTransaction() === false) {
                $this->posts->storeRendered($postId, (int) $item['edit_count'], $html, $version);
            }
        } catch (\Throwable $e) {
            self::warn('storeRendered', $e);
        }
    }

    /**
     * Entscheidungen für alle Zitate der gewählten Einträge: IDs aus den Zitat-Blöcken des HTML und aus den Markerzeilen des Textes
     * (die ersten MAX_QUOTES je Text), eindeutig in der Reihenfolge des Auftretens, höchstens MAX_QUOTE_LOOKUPS, eine `resolveMany()`.
     * IDs über der Grenze, fehlende IDs und alle IDs bei einem Fehler ergeben `false`.
     *
     * @param array<int, array{body: string, html: ?string}> $chosen
     * @return \Closure(int): (bool|string)
     */
    private function decisions(?int $viewerId, array $chosen, int $now): \Closure
    {
        $ids = [];
        foreach ($chosen as $entry) {
            if ($entry['html'] !== null) {
                foreach (self::quoteSpans($entry['html']) as $span) {
                    $ids[$span['id']] = $span['id'];
                }
            }
            foreach (array_slice(QuoteService::extractRefs($entry['body']), 0, ContentRenderer::MAX_QUOTES) as $id) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        $ids = array_slice($ids, 0, self::MAX_QUOTE_LOOKUPS);
        $decisions = $ids === [] ? [] : $this->quotes->resolveMany($viewerId, $ids, $now);
        $decide = static fn (int $id): bool|string => $decisions[$id] ?? false;

        return $decide;
    }

    /**
     * Ausgabe eines Eintrags: Zitat-Blöcke ersetzen (bzw. Ausweichpfad), dann der Filter.
     *
     * @param array{body: string, html: ?string} $entry
     * @param \Closure(int): (bool|string) $decide
     */
    private function output(array $entry, \Closure $decide, string $locale, int $postId): string
    {
        $html = $entry['html'] === null ? $this->fallback($entry['body'], $decide, $locale) : $this->resolveQuotes($entry['html'], $decide, $locale);

        return $this->filtered($html, $postId);
    }

    /**
     * Ausweichpfad (Budget erschöpft, Purifier fehlt, Cache nicht auslieferbar, Grenzen überschritten): nie der rohe Text, sondern
     * der mit denselben Entscheidungen neutralisierte (Vertrag §4.3 Nr. 2), als escapter Klartext. Die Platzhalter erscheinen in der
     * Sprache des Betrachters.
     *
     * @param \Closure(int): (bool|string) $decide
     */
    private function fallback(string $body, \Closure $decide, string $locale): string
    {
        $html = ContentRenderer::fallbackHtml(QuoteService::neutralize($body, $decide));
        if ($locale === 'de') {
            return $html;
        }

        return strtr($html, [
            self::escape(QuoteService::HIDDEN_NOTICE) => self::escape(I18n::t('community.quote.hidden', [], $locale)),
            self::escape(QuoteService::REMOVED_NOTICE) => self::escape(I18n::t('community.quote.removed', [], $locale)),
        ]);
    }

    /**
     * Zitat-Blöcke je Entscheidung ersetzen (rückwärts nach Position): sichtbar → Quellverweis nach dem öffnenden Tag; entfernt bzw.
     * nicht sichtbar → der ganze Block (samt verschachtelten Blockzitaten) wird ein Platzhalter ohne Text der Quelle.
     *
     * @param \Closure(int): (bool|string) $decide
     */
    private function resolveQuotes(string $html, \Closure $decide, string $locale): string
    {
        $spans = self::quoteSpans($html);
        for ($i = count($spans) - 1; $i >= 0; $i--) {
            $span = $spans[$i];
            $state = $decide($span['id']);
            if ($state === true) {
                $source = '<p class="cms-quote__source"><a href="' . self::escape(Urls::post($span['id'], $locale)) . '">'
                    . self::escape(I18n::t('community.quote.source', [], $locale)) . '</a></p>';
                $html = substr_replace($html, $source, $span['open_end'], 0);
                continue;
            }
            $notice = I18n::t($state === QuoteService::REMOVED ? 'community.quote.removed' : 'community.quote.hidden', [], $locale);
            $block = '<blockquote class="cms-quote cms-quote--hidden" data-quote-post="' . $span['id'] . '"><p>' . self::escape($notice) . '</p></blockquote>';
            $html = substr_replace($html, $block, $span['start'], $span['end'] - $span['start']);
        }

        return $html;
    }

    /**
     * Filter `cms_community_render_post` (Anzeigezeit, nach der Neutralisierung, nicht gespeichert, nicht erneut gereinigt). Eine
     * Ausnahme oder eine Rückgabe, die kein String ist, ergibt den Eingang und eine Warnung.
     */
    private function filtered(string $html, int $postId): string
    {
        try {
            $filtered = ($this->filter)($html, $postId);
        } catch (\Throwable $e) {
            self::warn('filter', $e);

            return $html;
        }
        if (!is_string($filtered)) {
            Logger::instance()->withChannel('community')->warning('Filter {hook} lieferte keinen String (Beitrag {post_id}); Eingang verwendet.', [
                'hook' => Events::RENDER_POST,
                'post_id' => $postId,
            ]);
        }

        return is_string($filtered) ? $filtered : $html;
    }

    /** @param array<string, mixed> $item */
    private static function isFull(array $item): bool
    {
        return ($item['view'] ?? 'full') === 'full';
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function scope(bool $all): string
    {
        return 'v' . ContentRenderer::VERSION . ($all ? ':all' : '');
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, self::ESCAPE, 'UTF-8');
    }

    /** @return array{success: false, error: string, retry_after?: int} */
    private static function denied(AccessDecision $decision): array
    {
        $result = self::failure($decision->reason !== '' ? $decision->reason : 'no_permission');
        if ($decision->retryAfter !== null) {
            $result['retry_after'] = $decision->retryAfter;
        }

        return $result;
    }

    /** @return array{success: false, error: string} */
    private static function failure(string $error): array
    {
        return ['success' => false, 'error' => $error];
    }

    private static function warn(string $operation, \Throwable $e): void
    {
        Logger::instance()->withChannel('community')->warning('Community-Darstellung: {operation} fehlgeschlagen: {exception}', [
            'operation' => $operation,
            'exception' => $e,
        ]);
    }
}
