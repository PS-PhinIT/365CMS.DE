<?php
/**
 * Community – Schreibseiten (PR #4, Teil 2): neues Thema, Antwort, Beitrag bearbeiten.
 *
 * GET zeigt das Formular, POST schreibt über `TopicService`/`PostService` (Rechte, Grenzen, Antispam und Idempotenz entscheiden die
 * Dienste) und leitet mit 303 weiter (Post/Redirect/Get). Der globale `form_guard` des Routers prüft `csrf_token` vor der Route; das
 * Token ist einmalig, jedes Formular trägt deshalb ein frisches. Fehlgeschlagene Eingaben bleiben im Formular erhalten.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Http;

use CMS\Security;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Service\CaptchaGate;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\PostService;
use CMS\Services\Community\Service\QuoteService;
use CMS\Services\Community\Service\TopicService;
use CMS\Services\Community\Support\Codes;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Urls;

if (!defined('ABSPATH')) {
    exit;
}

final class ComposeController
{
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
            $p = Urls::routePatterns($locale);
            foreach (['GET' => false, 'POST' => true] as $method => $submit) {
                $router->addRoute($method, $p['new_topic'], fn (string $slug) => $this->newTopic($slug, $submit));
                $router->addRoute($method, $p['reply'], fn (string $id) => $this->reply((int) $id, $submit));
                $router->addRoute($method, $p['edit'], fn (string $id) => $this->edit((int) $id, $submit));
            }
        }
    }

    // ── Neues Thema ─────────────────────────────────────────────────────────────────────────────────────────────

    private function newTopic(string $slug, bool $submit): void
    {
        $page = PublicController::instance();
        $locale = I18n::requestLocale();
        $forum = (new ForumRepository())->findBySlug($slug, $locale);
        $viewer = $page->currentViewerId();
        if ($forum === null || ($viewer !== null && !CommunityAccess::instance()->can($viewer, 'read', ['forum_id' => (int) $forum['id']])->allowed)) {
            $page->showNotFound();

            return;
        }
        $own = Urls::forumSlugFor((string) $forum['slug'], $forum['slug_en'] ?? null, $locale);
        $cancel = Urls::forum($own, $locale);
        $form = [
            'mode' => 'new', 'heading' => 'Neues Thema', 'submit_label' => 'Thema erstellen', 'cancel_url' => $cancel, 'show_title' => true,
            'title' => '', 'body' => '', 'reason' => '', 'seen' => 0, 'edit_count' => 0, 'forum' => $forum, 'error' => null, 'new_posts' => false,
        ];
        if ($viewer === null) {
            $this->loginRequired($form, $locale);

            return;
        }
        if (!$submit) {
            $this->show($form, $locale);

            return;
        }
        $form['title'] = trim((string) ($_POST['title'] ?? ''));
        $form['body'] = (string) ($_POST['body'] ?? '');
        $captchaError = $this->captchaFailure($viewer, 'create_topic');
        if ($captchaError !== null) {
            $form['error'] = $captchaError;
            $this->show($form, $locale, 422);

            return;
        }
        $result = TopicService::instance()->create($viewer, (int) $forum['id'], $form['title'], $form['body']);
        if (empty($result['success'])) {
            $form['error'] = self::errorText($result);
            $this->show($form, $locale, self::statusOf($result));

            return;
        }
        if (($result['status'] ?? '') === 'pending') {
            $_SESSION['community_flash'] = 'Dein Thema wurde gespeichert und wartet auf Freigabe durch die Moderation.';
            $this->redirect($cancel);
        }
        $this->redirect(Urls::topic((int) $result['topic_id'], (string) ($result['slug'] ?? ''), $locale));
    }

    // ── Antwort ─────────────────────────────────────────────────────────────────────────────────────────────────

    private function reply(int $topicId, bool $submit): void
    {
        $page = PublicController::instance();
        $locale = I18n::requestLocale();
        $viewer = $page->currentViewerId();
        $topic = TopicService::instance()->get($viewer, $topicId);
        if ($topic === null) {
            $page->showNotFound();

            return;
        }
        $cancel = Urls::topic($topicId, (string) ($topic['slug'] ?? ''), $locale);
        $form = [
            'mode' => 'reply', 'heading' => 'Antworten: ' . (string) $topic['title'], 'submit_label' => 'Antwort senden', 'cancel_url' => $cancel,
            'show_title' => false, 'title' => '', 'body' => '', 'reason' => '', 'seen' => (int) ($topic['last_post_id'] ?? 0), 'edit_count' => 0,
            'topic' => $topic, 'error' => null, 'new_posts' => false, 'quote' => 0,
        ];
        if ($viewer === null) {
            $this->loginRequired($form, $locale);

            return;
        }
        $quote = (int) ($submit ? ($_POST['quote_of_post_id'] ?? 0) : ($_GET['zitat'] ?? 0));
        if (!$submit) {
            if ($quote > 0) {
                $text = QuoteService::instance()->build($quote, $viewer);
                if ($text !== null) {
                    $form['body'] = $text . "\n\n";
                    $form['quote'] = $quote;
                }
            }
            $this->show($form, $locale);

            return;
        }
        $form['body'] = (string) ($_POST['body'] ?? '');
        $form['quote'] = $quote;
        $options = ['seen_last_post_id' => (int) ($_POST['seen_last_post_id'] ?? 0)];
        if ($quote > 0) {
            $options['quote_of_post_id'] = $quote;
        }
        if (($_POST['force'] ?? '') === '1') {
            $options['force'] = true;
        }
        $form['seen'] = $options['seen_last_post_id'];
        $captchaError = $this->captchaFailure($viewer, 'reply');
        if ($captchaError !== null) {
            $form['error'] = $captchaError;
            $this->show($form, $locale, 422);

            return;
        }
        $result = PostService::instance()->reply($viewer, $topicId, $form['body'], $options);
        if (empty($result['success'])) {
            $form['error'] = self::errorText($result);
            $form['new_posts'] = ($result['error'] ?? '') === 'new_posts';
            $this->show($form, $locale, self::statusOf($result));

            return;
        }
        if (($result['status'] ?? '') === 'pending' || empty($result['page'])) {
            $_SESSION['community_flash'] = 'Deine Antwort wurde gespeichert und wartet auf Freigabe durch die Moderation.';
            $this->redirect($cancel);
        }
        $this->redirect(Urls::topic($topicId, (string) ($topic['slug'] ?? ''), $locale, (int) $result['page']) . '#post-' . (int) $result['post_id']);
    }

    // ── Bearbeiten ──────────────────────────────────────────────────────────────────────────────────────────────

    private function edit(int $postId, bool $submit): void
    {
        $page = PublicController::instance();
        $locale = I18n::requestLocale();
        $viewer = $page->currentViewerId();
        $post = $postId > 0 ? (new PostRepository())->find($postId) : null;
        $topic = $post === null ? null : TopicService::instance()->get($viewer, (int) $post['topic_id']);
        if ($post === null || $topic === null) {
            $page->showNotFound();

            return;
        }
        $cancel = Urls::post($postId, $locale);
        $first = (int) ($post['is_first_post'] ?? 0) === 1;
        $form = [
            'mode' => 'edit', 'heading' => 'Beitrag bearbeiten', 'submit_label' => 'Änderung speichern', 'cancel_url' => $cancel, 'show_title' => $first,
            'title' => (string) $topic['title'], 'body' => (string) ($post['body'] ?? ''), 'reason' => '', 'seen' => 0,
            'edit_count' => (int) ($post['edit_count'] ?? 0), 'topic' => $topic, 'post' => $post, 'error' => null, 'new_posts' => false,
        ];
        if ($viewer === null) {
            $this->loginRequired($form, $locale);

            return;
        }
        $decision = CommunityAccess::instance()->can($viewer, 'edit', ['post_id' => $postId]);
        if (!$decision->allowed && !$submit) {
            // Wer lesen, aber nicht bearbeiten darf, sieht den Grund; wer nicht lesen darf, wie überall 404.
            if (!CommunityAccess::instance()->can($viewer, 'read', ['post_id' => $postId])->allowed) {
                $page->showNotFound();

                return;
            }
            $form['error'] = self::errorText(['error' => $decision->reason]);
            $form['readonly'] = true;
            $this->show($form, $locale, 403);

            return;
        }
        if (!$submit) {
            $this->show($form, $locale);

            return;
        }
        $form['title'] = $first ? trim((string) ($_POST['title'] ?? '')) : (string) $topic['title'];
        $form['body'] = (string) ($_POST['body'] ?? '');
        $form['reason'] = trim((string) ($_POST['reason'] ?? ''));
        $form['edit_count'] = (int) ($_POST['edit_count'] ?? 0);
        $result = PostService::instance()->edit($viewer, $postId, $form['body'], $first ? $form['title'] : null, $form['reason'], $form['edit_count']);
        if (empty($result['success'])) {
            $form['error'] = self::errorText($result);
            if (($result['error'] ?? '') === 'edit_conflict') {
                // Der Beitrag wurde inzwischen geändert: Stand übernehmen, Eingabe bleibt erhalten.
                $form['edit_count'] = (int) ($result['edit_count'] ?? $form['edit_count']);
            }
            $this->show($form, $locale, self::statusOf($result));

            return;
        }
        $this->redirect($cancel);
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $form */
    private function show(array $form, string $locale, int $status = 200): void
    {
        if ($status === 404) {
            PublicController::instance()->showNotFound();

            return;
        }
        $form['token'] = Security::instance()->generateToken('form_guard');
        $form['locale'] = $locale;
        $form['captcha'] = $this->captchaHtml($form);
        $meta = ['title' => (string) $form['heading'] . ' – Community', 'noindex' => true];
        PublicController::instance()->renderPage('compose', $form, $meta, $status);
    }

    /**
     * Captcha des Formulars (neue Aufgabe), leer wenn keines verlangt wird: nur neues Thema und Antwort, nur angemeldet, nicht im
     * Nur-Lesen-Zustand. Fehler der Ermittlung (Datenbank) verlangen ein Captcha (fail-closed im Dienst); sonst bleibt das Formular ohne.
     *
     * @param array<string, mixed> $form
     */
    private function captchaHtml(array $form): string
    {
        $viewer = PublicController::instance()->currentViewerId();
        $action = ($form['mode'] ?? '') === 'new' ? 'create_topic' : (($form['mode'] ?? '') === 'reply' ? 'reply' : '');
        if ($viewer === null || $action === '' || !empty($form['readonly'])) {
            return '';
        }
        try {
            $gate = CaptchaGate::instance();

            return $gate->required($viewer, $action) ? $gate->render() : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Prüft das Captcha einer Absendung. `null` = in Ordnung oder nicht verlangt, sonst der Text für das Formular. Das Sicherheitsprotokoll (`captcha_failed`) schreibt
     * `CaptchaGate::verify()`.
     */
    private function captchaFailure(int $viewer, string $action): ?string
    {
        $gate = CaptchaGate::instance();
        if (!$gate->required($viewer, $action)) {
            return null;
        }
        $result = $gate->verify($_POST, $viewer);

        return $result->ok ? null : CaptchaGate::errorText($result);
    }

    /** @param array<string, mixed> $form */
    private function loginRequired(array $form, string $locale): void
    {
        $form['error'] = self::errorText(['error' => 'login_required']);
        $form['readonly'] = true;
        $form['login_url'] = $this->loginUrl($locale);
        $this->show($form, $locale, 401);
    }

    private function loginUrl(string $locale): string
    {
        try {
            $path = \CMS\Services\CmsAuthPageService::getInstance()->getPublicPath('login', $locale);

            return is_string($path) && $path !== '' ? $path : '/login';
        } catch (\Throwable) {
            return '/login';
        }
    }

    /** @param array<string, mixed> $result */
    private static function statusOf(array $result): int
    {
        return Codes::ACCESS_REASONS[(string) ($result['error'] ?? '')] ?? 422;
    }

    /**
     * Text zu einem Fehlercode: Dienstfehler (`community.error.*`), dann Ablehnungsgrund (`community.access.*`), sonst eine allgemeine
     * Meldung; bei `retry_after` mit Wartezeit.
     *
     * @param array<string, mixed> $result
     */
    private static function errorText(array $result): string
    {
        $code = (string) ($result['error'] ?? 'db_error');
        $text = '';
        foreach ([Codes::errorKey($code), Codes::accessKey($code)] as $key) {
            $candidate = I18n::t($key);
            if ($candidate !== '' && $candidate !== $key) {
                $text = $candidate;
                break;
            }
        }
        if ($text === '') {
            $text = 'Das hat nicht geklappt (' . $code . ').';
        }
        if (isset($result['retry_after']) && (int) $result['retry_after'] > 0) {
            $text .= ' Bitte warte ' . (int) $result['retry_after'] . ' Sekunden.';
        }

        return $text;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . $path, true, 303);
        exit;
    }
}
