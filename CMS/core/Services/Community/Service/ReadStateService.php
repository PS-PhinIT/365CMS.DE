<?php
/**
 * Lesestatus der Community (PR #2a): welche Themen ein Mitglied schon gelesen hat.
 *
 * Regeln (Karte PR #2a, §3.9):
 * - Je Thema steigt die zuletzt gelesene Beitrags-ID nur (`ReadStateRepository::advance()`); ein Wiederbesuch schreibt nichts.
 *   Die ID wird auf die letzte veröffentlichte Beitrags-ID des Themas begrenzt, damit eine falsche Eingabe nie künftige
 *   Beiträge als gelesen markiert.
 * - Ein Thema gilt als ungelesen, wenn es nach der Untergrenze Aktivität hat (`last_post_at > Untergrenze`) und seine
 *   letzte Beitrags-ID über der gelesenen liegt. Untergrenze = das Neueste aus der globalen Marke, den Marken des Forums und
 *   seiner Vorfahren (eine Marke gilt für Unterforen) und dem Horizont `unread_horizon_days` (jetzt minus Tage); ohne
 *   Horizont wäre jedes alte Thema für ein neues Mitglied ungelesen.
 * - Gäste haben keinen Lesestatus. Geschrieben wird nur, wo das Mitglied lesen darf (`can('read')`).
 * - `touchSeen()` (PR #2b) setzt `community_user_stats.last_seen_at` höchstens einmal je SEEN_INTERVAL: das Tor der Forum-Abos
 *   (eine Mail je Forum bis zum nächsten Besuch, Entwurf interaktion §2.9). PR #4 ruft es je Community-Seite eines Mitglieds.
 * - Zeiten sind UTC (`gmdate`); die Uhr wird je Aufruf einmal gelesen. Lesestatus ist unkritisch: ein Datenbankfehler wird
 *   protokolliert und liefert den neutralen Wert (false, 0, leere Karte), er bricht die Seite nicht ab.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\ReadStateRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Repository\UserStatsRepository;
use CMS\Services\Community\Support\Settings;

if (!defined('ABSPATH')) {
    exit;
}

final class ReadStateService
{
    /** Standard für die Obergrenze der Ungelesen-Zahl (Anzeige „99+“ ab dieser Zahl). */
    public const DEFAULT_CAP = 100;
    /** Größte Obergrenze der Ungelesen-Zahl. */
    public const MAX_CAP = 1000;
    /** Sekunden: `last_seen_at` wird höchstens so oft geschrieben (`touchSeen()`). */
    public const SEEN_INTERVAL = 300;
    /** Themen je Abfrage nach Lesestatus (Grenze von `ReadStateRepository::forTopics()`). */
    private const CHUNK = 500;

    private static ?self $instance = null;

    private Database $db;
    private Settings $settings;
    /** @var \Closure(): int Unix-Zeit */
    private \Closure $clock;
    /** @var \Closure(?int, string, array<string, mixed>): AccessDecision Mitglied, Aktion, Kontext */
    private \Closure $can;
    private ReadStateRepository $readStates;
    private TopicRepository $topics;
    private ForumRepository $forums;
    private UserStatsRepository $stats;

    /**
     * @param \Closure(): int|null $clock Unix-Zeit (Standard `time()`)
     * @param \Closure(?int, string, array<string, mixed>): AccessDecision|null $can Standard: `CommunityAccess::instance()->can()`; ohne diese Klasse wird alles abgelehnt
     */
    public function __construct(?Database $db = null, ?Settings $settings = null, ?\Closure $clock = null, ?\Closure $can = null)
    {
        $this->db = $db ?? Database::instance();
        $this->settings = $settings ?? Settings::getInstance();
        $this->clock = $clock ?? static fn (): int => time();
        $this->can = $can ?? static function (?int $userId, string $action, array $context): AccessDecision {
            return class_exists(CommunityAccess::class)
                ? CommunityAccess::instance()->can($userId, $action, $context)
                : AccessDecision::deny('no_permission');
        };
        $this->readStates = new ReadStateRepository($this->db);
        $this->topics = new TopicRepository($this->db);
        $this->forums = new ForumRepository($this->db);
        $this->stats = new UserStatsRepository($this->db);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Setzt die zuletzt gelesene Beitrags-ID eines Themas, nur nach oben. `true`, wenn sie angelegt oder erhöht wurde.
     * Gäste und Themen ohne Leserecht oder ohne veröffentlichten Beitrag schreiben nichts.
     */
    public function advance(int $userId, int $topicId, int $postId): bool
    {
        if ($userId < 1 || $topicId < 1 || $postId < 1) {
            return false;
        }
        $nowTs = ($this->clock)();
        try {
            if (!$this->mayRead($userId, ['topic_id' => $topicId, 'now' => $nowTs])) {
                return false;
            }
            $topic = $this->topics->find($topicId);
            $lastPostId = (int) ($topic['last_post_id'] ?? 0);
            if ($topic === null || $lastPostId < 1) {
                return false;
            }

            return $this->readStates->advance($userId, $topicId, min($postId, $lastPostId), gmdate('Y-m-d H:i:s', $nowTs));
        } catch (\PDOException | \RuntimeException $e) {
            $this->logFailure('read_state.advance', $e);

            return false;
        }
    }

    /**
     * Eine Seite eines Themas ist gelesen: der Lesestatus steigt bis zum größten Beitrag der Seite (sichtbare IDs).
     *
     * @param list<int> $visiblePostIds
     */
    public function markTopicPageRead(int $userId, int $topicId, array $visiblePostIds): bool
    {
        $ids = array_filter(array_map('intval', $visiblePostIds), static fn (int $id): bool => $id > 0);

        return $ids !== [] && $this->advance($userId, $topicId, max($ids));
    }

    /**
     * „Alles gelesen“ für ein Forum (die Marke gilt auch für seine Unterforen) oder für die gesamte Community (`0`). Die Marke
     * steigt nur; `true`, wenn sie angelegt oder erhöht wurde.
     */
    public function markForumRead(int $userId, int $forumId = 0): bool
    {
        if ($userId < 1 || $forumId < 0) {
            return false;
        }
        $nowTs = ($this->clock)();
        try {
            if ($forumId > 0 && ($this->forums->find($forumId) === null || !$this->mayRead($userId, ['forum_id' => $forumId, 'now' => $nowTs]))) {
                return false;
            }

            return $this->readStates->setMark($userId, $forumId, gmdate('Y-m-d H:i:s', $nowTs));
        } catch (\PDOException | \RuntimeException $e) {
            $this->logFailure('read_state.mark_forum', $e);

            return false;
        }
    }

    /**
     * Besuch eines Mitglieds vermerken (`last_seen_at`, Tor der Forum-Abos): höchstens ein Schreibzugriff je SEEN_INTERVAL, ohne
     * Statistikzeile nichts (Abonnenten haben immer eine). `true`, wenn geschrieben wurde. Kein `can()`: eigene Daten.
     */
    public function touchSeen(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        $nowTs = ($this->clock)();
        try {
            return $this->stats->touchSeen($userId, gmdate('Y-m-d H:i:s', $nowTs), gmdate('Y-m-d H:i:s', $nowTs - self::SEEN_INTERVAL));
        } catch (\PDOException | \RuntimeException $e) {
            $this->logFailure('read_state.touch_seen', $e);

            return false;
        }
    }

    /**
     * Ungelesen-Karte für Themenlisten: Thema-ID => ungelesen. Die Zeilen brauchen `id`, `forum_id`, `last_post_id` und
     * `last_post_at` (wie `TopicService::listForum()` sie liefert). Gäste: alles gelesen (`false`). Nicht veröffentlichte
     * Themen gelten nie als ungelesen. Das Leserecht gehört der Liste (die Zeilen kommen aus einer lesbaren Liste).
     *
     * @param list<array<string, mixed>> $topicRows
     * @return array<int, bool>
     */
    public function unreadMap(int $userId, array $topicRows): array
    {
        $map = [];
        $byId = [];
        foreach ($topicRows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $map[$id] = false;
                $byId[$id] = $row;
            }
        }
        if ($userId < 1 || $byId === []) {
            return $map;
        }
        $nowTs = ($this->clock)();
        try {
            $horizon = $this->horizon($nowTs);
            $chains = [];
            foreach ($byId as $row) {
                $forumId = (int) ($row['forum_id'] ?? 0);
                $chains[$forumId] ??= $this->forums->ancestorIds($forumId);
            }
            $marks = $this->readStates->marks($userId, array_merge([], ...array_values($chains)));
            $read = [];
            foreach (array_chunk(array_keys($byId), self::CHUNK) as $chunk) {
                $read += $this->readStates->forTopics($userId, $chunk);
            }
            foreach ($byId as $id => $row) {
                $since = self::lowerBound($horizon, $marks, $chains[(int) ($row['forum_id'] ?? 0)]);
                $map[$id] = self::isUnread($row, $since, $read[$id] ?? 0);
            }
        } catch (\PDOException | \RuntimeException $e) {
            $this->logFailure('read_state.unread_map', $e);
            $map = array_fill_keys(array_keys($map), false);
        }

        return $map;
    }

    /**
     * ID des ersten veröffentlichten Beitrags nach der zuletzt gelesenen („zum ersten ungelesenen Beitrag“); `null`, wenn
     * das Thema nicht ungelesen ist (Gäste, kein Leserecht, nichts Neues, Marke oder Horizont decken es ab).
     * Grenze: nach einer Foren-Marke ohne eigene Lesezeile beginnt die Suche am Themenanfang (es gibt keinen Beitragsbezug zur Marke).
     */
    public function firstUnreadPostId(int $userId, int $topicId): ?int
    {
        if ($userId < 1 || $topicId < 1) {
            return null;
        }
        $nowTs = ($this->clock)();
        try {
            if (!$this->mayRead($userId, ['topic_id' => $topicId, 'now' => $nowTs])) {
                return null;
            }
            $topic = $this->topics->find($topicId);
            if ($topic === null) {
                return null;
            }
            $chain = $this->forums->ancestorIds((int) $topic['forum_id']);
            $since = self::lowerBound($this->horizon($nowTs), $this->readStates->marks($userId, $chain), $chain);
            $lastRead = $this->readStates->forTopics($userId, [$topicId])[$topicId] ?? 0;

            return self::isUnread($topic, $since, $lastRead) ? $this->readStates->firstUnread($topicId, $lastRead) : null;
        } catch (\PDOException | \RuntimeException $e) {
            $this->logFailure('read_state.first_unread', $e);

            return null;
        }
    }

    /**
     * Zahl ungelesener Themen eines Forums (ohne Unterforen), höchstens `$cap` (die Anzeige zeigt dann „99+“). Gäste und
     * Foren ohne Leserecht: 0.
     */
    public function unreadTopicCount(int $userId, int $forumId, int $cap = self::DEFAULT_CAP): int
    {
        if ($userId < 1 || $forumId < 1) {
            return 0;
        }
        $cap = max(1, min(self::MAX_CAP, $cap));
        $nowTs = ($this->clock)();
        try {
            $chain = $this->forums->ancestorIds($forumId);
            if ($chain === [] || !$this->mayRead($userId, ['forum_id' => $forumId, 'now' => $nowTs])) {
                return 0;
            }
            $since = self::lowerBound($this->horizon($nowTs), $this->readStates->marks($userId, $chain), $chain);

            return min($cap, $this->readStates->unreadCount($userId, [$forumId], $since, $cap));
        } catch (\PDOException | \RuntimeException $e) {
            $this->logFailure('read_state.unread_count', $e);

            return 0;
        }
    }

    /** Untergrenze der Aktivität: jetzt minus `unread_horizon_days` (UTC-Text). */
    private function horizon(int $nowTs): string
    {
        return gmdate('Y-m-d H:i:s', $nowTs - $this->settings->int('unread_horizon_days') * 86400);
    }

    /**
     * Das Neueste aus Horizont und den Marken der Kette (Forum, Vorfahren) und der globalen Marke (`0`).
     *
     * @param array<int, string> $marks Forum-ID => Zeitpunkt (inkl. 0 = global)
     * @param list<int> $chain Forum und Vorfahren
     */
    private static function lowerBound(string $horizon, array $marks, array $chain): string
    {
        $since = $horizon;
        foreach ([0, ...$chain] as $forumId) {
            if (isset($marks[$forumId]) && $marks[$forumId] > $since) {
                $since = $marks[$forumId];
            }
        }

        return $since;
    }

    /**
     * Regel aus dem Plan: Aktivität nach der Untergrenze und eine Beitrags-ID über der gelesenen. Nicht veröffentlichte Themen
     * (Status gesetzt und ungleich `published`) und Themen ohne veröffentlichten Beitrag sind nie ungelesen.
     *
     * @param array<string, mixed> $topic
     */
    private static function isUnread(array $topic, string $since, int $lastReadPostId): bool
    {
        $lastPostId = (int) ($topic['last_post_id'] ?? 0);
        $lastPostAt = (string) ($topic['last_post_at'] ?? '');
        if (isset($topic['status']) && $topic['status'] !== 'published') {
            return false;
        }

        return $lastPostId > 0 && $lastPostAt !== '' && $lastPostAt > $since && $lastPostId > $lastReadPostId;
    }

    /** @param array<string, mixed> $context */
    private function mayRead(int $userId, array $context): bool
    {
        return ($this->can)($userId, 'read', $context)->allowed;
    }

    private function logFailure(string $operation, \Throwable $e): void
    {
        Logger::instance()->withChannel('community')->warning('Community-Lesestatus fehlgeschlagen ({operation}): {exception}', [
            'operation' => $operation,
            'exception' => $e,
        ]);
    }
}
