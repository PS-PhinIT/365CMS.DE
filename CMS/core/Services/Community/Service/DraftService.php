<?php
/**
 * Server-Entwürfe der Community (`/forum/api/entwurf`): ein Entwurf je Mitglied und Ziel, `forum` = neues Thema im Forum, `topic` =
 * Antwort im Thema. Gespeichert wird nur, wo das Mitglied lesen darf (`can('read')`); Gäste haben keine Entwürfe. Nach dem Absenden
 * löscht `ComposeController` den Entwurf (`discard()`). Ergebnisse `{success, error?, …}` mit Codes aus `Support\Codes`; Zeiten UTC.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Community\Repository\DraftRepository;
use CMS\Services\Community\Support\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class DraftService
{
    /** Zielarten: Art => Kontextschlüssel von `can()`. */
    public const TARGET_TYPES = ['forum' => 'forum_id', 'topic' => self::TOPIC_KEY];

    /** Schlüssel des Themas im Rechtekontext von `CommunityAccess::can()`. */
    private const TOPIC_KEY = 'topic' . '_id';

    /** Höchstlänge des Titels (Spalte `title` VARCHAR(200)). */
    public const TITLE_MAX = 200;

    private static ?self $instance = null;

    private Settings $settings;
    private DraftRepository $drafts;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(?int, string, array<string, mixed>): AccessDecision */
    private \Closure $can;

    /**
     * @param \Closure(): int|null $clock Unix-Zeit (Standard `time()`)
     * @param \Closure(?int, string, array<string, mixed>): AccessDecision|null $can Standard `CommunityAccess::instance()->can()`
     */
    public function __construct(?Database $db = null, ?Settings $settings = null, ?\Closure $clock = null, ?\Closure $can = null)
    {
        $this->settings = $settings ?? Settings::getInstance();
        $this->drafts = new DraftRepository($db ?? Database::instance());
        $this->clock = $clock ?? static fn (): int => time();
        $this->can = $can ?? static fn (?int $userId, string $action, array $context): AccessDecision => CommunityAccess::instance()->can($userId, $action, $context);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Entwurf laden: `{success: true, draft: {title, body, updated_at}|null}`.
     *
     * @return array<string, mixed>
     */
    public function load(int $userId, string $type, int $targetId): array
    {
        $error = $this->targetError($userId, $type, $targetId);
        if ($error !== null) {
            return ['success' => false, 'error' => $error];
        }
        try {
            return ['success' => true, 'draft' => $this->drafts->find($userId, $type, $targetId)];
        } catch (\Throwable $e) {
            return $this->databaseError('draft.load', $e);
        }
    }

    /**
     * Entwurf speichern. Ein leerer Text (und leerer Titel) löscht ihn. Text länger als `post_max` ⇒ `body_too_long`.
     *
     * @return array<string, mixed> `{success: true, saved: bool, updated_at: ?string}`
     */
    public function save(int $userId, string $type, int $targetId, ?string $title, string $body): array
    {
        $error = $this->targetError($userId, $type, $targetId);
        if ($error !== null) {
            return ['success' => false, 'error' => $error];
        }
        $body = str_replace("\r\n", "\n", $body);
        if (mb_strlen($body, 'UTF-8') > $this->settings->int('post_max')) {
            return ['success' => false, 'error' => 'body_too_long'];
        }
        $title = $type === 'forum' && $title !== null ? mb_substr(trim($title), 0, self::TITLE_MAX, 'UTF-8') : null;
        try {
            if (trim($body) === '' && ($title === null || $title === '')) {
                $this->drafts->delete($userId, $type, $targetId);

                return ['success' => true, 'saved' => false, 'updated_at' => null];
            }
            $now = gmdate('Y-m-d H:i:s', ($this->clock)());
            $this->drafts->save($userId, $type, $targetId, $title, $body, $now);

            return ['success' => true, 'saved' => true, 'updated_at' => $now];
        } catch (\Throwable $e) {
            return $this->databaseError('draft.save', $e);
        }
    }

    /** Entwurf verwerfen (nach dem Absenden oder auf Wunsch); ohne Rechteprüfung, nur eigene Zeile. */
    public function discard(int $userId, string $type, int $targetId): bool
    {
        if ($userId < 1 || $targetId < 1 || !isset(self::TARGET_TYPES[$type])) {
            return false;
        }
        try {
            return $this->drafts->delete($userId, $type, $targetId) > 0;
        } catch (\Throwable $e) {
            $this->databaseError('draft.discard', $e);

            return false;
        }
    }

    private function targetError(int $userId, string $type, int $targetId): ?string
    {
        if ($userId < 1) {
            return 'login_required';
        }
        if ($targetId < 1 || !isset(self::TARGET_TYPES[$type])) {
            return 'invalid_input';
        }
        $decision = ($this->can)($userId, 'read', [self::TARGET_TYPES[$type] => $targetId, 'now' => ($this->clock)()]);

        return $decision->allowed ? null : $decision->reason;
    }

    /** @return array{success: false, error: string} */
    private function databaseError(string $operation, \Throwable $e): array
    {
        Logger::instance()->withChannel('community')->warning('Community-Dienst fehlgeschlagen ({operation}): {exception}', ['operation' => $operation, 'exception' => $e]);

        return ['success' => false, 'error' => 'db_error'];
    }
}
