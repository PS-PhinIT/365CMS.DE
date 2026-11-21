<?php
/**
 * Ränge der Community (PR #2b; Vertrag DESIGN-2b §3.6, §3.10, §3.11; Entwurf interaktion §1.5, §2.6, E16; Platzhalter I-23).
 *
 * Grundsätze:
 * - Ränge werden nicht gespeichert, sondern zur Anzeigezeit aus `post_count` (Semantik S1) bestimmt: ein manueller Rang (`rank_id`,
 *   vorhanden) gewinnt, sonst der höchste `min_posts ≤ post_count` (nur Ränge mit `min_posts`, Gleichstand: höheres `sort_order`).
 *   Ein unbekannter `rank_id` (gelöschter Rang) gilt als automatisch. Englischer Titel `title_en`, leer ⇒ `title`.
 * - Grundausstattung lazy: `all()` sät bei leerer Tabelle einmal je Instanz (`seedDefaults()`); eine Marke in `settings`
 *   (`INSERT IGNORE`) verhindert erneutes Säen, auch wenn ein Admin alle Ränge bewusst gelöscht hat.
 * - Schreiben nur mit `can('manage')`. Datenbankfehler: Rücknahme, Warnung, `db_error`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Service;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Community\Repository\MemberRepository;
use CMS\Services\Community\Repository\UserStatsRepository;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Transaction;

if (!defined('ABSPATH')) {
    exit;
}

final class RankService
{
    /** Marke der Grundausstattung in `settings` (ohne Punkt: nicht Gruppe `community`). */
    public const SEED_MARKER = UserStatsRepository::RANK_SEED_MARKER;
    /** Platzhalter (I-23); ab PR #3 im Admin änderbar. */
    public const DEFAULT_RANKS = [
        ['title' => 'Neu', 'title_en' => 'New', 'min_posts' => 0, 'sort_order' => 10],
        ['title' => 'Mitglied', 'title_en' => 'Member', 'min_posts' => 10, 'sort_order' => 20],
        ['title' => 'Aktiv', 'title_en' => 'Active', 'min_posts' => 50, 'sort_order' => 30],
        ['title' => 'Erfahren', 'title_en' => 'Experienced', 'min_posts' => 200, 'sort_order' => 40],
        ['title' => 'Experte', 'title_en' => 'Expert', 'min_posts' => 500, 'sort_order' => 50],
    ];
    public const TITLE_MAX = 80;
    public const MIN_POSTS_MAX = 1000000;
    public const SORT_MAX = 65535;

    private const COLOR_PATTERN = '/^#[0-9a-f]{6}([0-9a-f]{2})?$/D';
    private const ICON_PATTERN = '/^[a-z0-9][a-z0-9-]{0,49}$/D';
    private const MAX_UINT = 4294967295;

    private static ?self $instance = null;

    private Database $db;
    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(?int, string, array<string, mixed>): AccessDecision */
    private \Closure $can;
    private UserStatsRepository $stats;
    private MemberRepository $members;
    /** @var list<array<string, mixed>>|null Ränge dieser Instanz */
    private ?array $ranks = null;
    private bool $seedTried = false;

    /**
     * Positionen wie `QuoteService`; `$settings` bleibt ungenutzt (Vertrag §3.6).
     *
     * @param \Closure(): int|null $clock Unix-Zeit (Standard `time()`)
     * @param \Closure(?int, string, array<string, mixed>): AccessDecision|null $can Standard `CommunityAccess::instance()->can()`
     */
    public function __construct(?Database $db = null, ?Settings $settings = null, ?\Closure $clock = null, ?\Closure $can = null)
    {
        $this->db = $db ?? Database::instance();
        $this->clock = $clock ?? static fn (): int => time();
        $this->can = $can ?? static fn (?int $userId, string $action, array $context): AccessDecision => CommunityAccess::instance()->can($userId, $action, $context);
        $this->stats = new UserStatsRepository($this->db);
        $this->members = new MemberRepository($this->db);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Alle Ränge (`sort_order`, dann ID), je Instanz gemerkt; bei leerer Tabelle einmal `seedDefaults()`. Datenbankfehler ⇒ `[]`.
     *
     * @return list<array{id: int, title: string, title_en: ?string, min_posts: ?int, color: ?string, icon: ?string, sort_order: int}>
     */
    public function all(): array
    {
        if ($this->ranks !== null) {
            return $this->ranks;
        }
        try {
            $ranks = $this->stats->ranks();
            if ($ranks === [] && !$this->seedTried) {
                $this->seedTried = true;
                if ($this->seedDefaults()) {
                    $ranks = $this->stats->ranks();
                }
            }
        } catch (\PDOException | \RuntimeException $e) {
            $this->warn('rank.all', $e);

            return [];
        }

        return $this->ranks = $ranks;
    }

    /**
     * Rang für eine Beitragszahl und einen manuellen Rang.
     *
     * @return array{id: int, title: string, color: ?string, icon: ?string, manual: bool}|null
     */
    public function rankFor(int $postCount, ?int $rankId, string $locale = 'de'): ?array
    {
        $ranks = $this->all();
        if ($rankId !== null) {
            foreach ($ranks as $rank) {
                if ($rank['id'] === $rankId) {
                    return self::present($rank, $locale, true);
                }
            }
        }
        $best = null;
        foreach ($ranks as $rank) {
            $min = $rank['min_posts'];
            if ($min === null || !($min <= $postCount)) {
                continue;
            }
            if ($best === null || $min > $best['min_posts'] || ($min === $best['min_posts'] && $rank['sort_order'] > $best['sort_order'])) {
                $best = $rank;
            }
        }

        return $best === null ? null : self::present($best, $locale, false);
    }

    /**
     * Ränge mehrerer Mitglieder (eine Abfrage `statsMany`); Mitglieder ohne Statistik zählen 0 Beiträge.
     *
     * @param array<array-key, mixed> $userIds
     * @return array<int, array{id: int, title: string, color: ?string, icon: ?string, manual: bool}|null>
     */
    public function forMembers(array $userIds, string $locale = 'de'): array
    {
        $ids = [];
        foreach ($userIds as $id) {
            if ((int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        try {
            $stats = $this->stats->statsMany(array_values($ids));
        } catch (\PDOException | \RuntimeException $e) {
            $this->warn('rank.members', $e);
            $stats = [];
        }
        $map = [];
        foreach ($ids as $id) {
            $map[$id] = $this->rankFor($stats[$id]['post_count'] ?? 0, $stats[$id]['rank_id'] ?? null, $locale);
        }

        return $map;
    }

    /**
     * Grundausstattung: Marke beanspruchen und bei leerer Tabelle die fünf Standardränge anlegen (eine Transaktion; ein Fehler nimmt
     * die Marke mit zurück). `false`, wenn die Marke schon steht (gesät oder bewusst geleert) oder ein Fehler auftrat.
     */
    public function seedDefaults(): bool
    {
        $now = gmdate('Y-m-d H:i:s', ($this->clock)());
        try {
            return Transaction::run($this->db, function () use ($now): bool {
                if (!$this->stats->claimRankSeed($now)) {
                    return false;
                }
                if ($this->stats->countRanks() === 0) {
                    foreach (self::DEFAULT_RANKS as $rank) {
                        $this->stats->insertRank($rank + ['color' => null, 'icon' => null]);
                    }
                }

                return true;
            });
        } catch (\PDOException | \RuntimeException $e) {
            $this->warn('rank.seed', $e);

            return false;
        } finally {
            $this->ranks = null;
        }
    }

    /**
     * Manuellen Rang setzen (`null` = automatisch).
     *
     * @return array{success: bool, changed?: bool, error?: string, retry_after?: int}
     */
    public function assign(int $actorId, int $userId, ?int $rankId): array
    {
        if ($actorId < 1 || $userId < 1 || $userId > self::MAX_UINT || ($rankId !== null && ($rankId < 1 || $rankId > self::MAX_UINT))) {
            return self::failure('invalid_input');
        }
        $nowTs = ($this->clock)();
        $now = gmdate('Y-m-d H:i:s', $nowTs);
        try {
            $gate = $this->gate($actorId, $nowTs);
            if ($gate !== null) {
                return $gate;
            }
            if ($this->members->find($userId) === null) {
                return self::failure('invalid_input');
            }
            if ($rankId !== null && $this->stats->rank($rankId) === null) {
                return self::failure('not_found');
            }
            $before = $this->stats->statsMany([$userId])[$userId]['rank_id'] ?? null;
            if ($before === $rankId) {
                return ['success' => true, 'changed' => false];
            }
            $this->stats->ensure($userId, $now);
            $this->stats->setRank($userId, $rankId, $now);
        } catch (\PDOException | \RuntimeException $e) {
            return $this->failed('rank.assign', $e);
        }

        return ['success' => true, 'changed' => true];
    }

    /**
     * Rang anlegen (`$rankId` null) oder ändern. Felder: `title` (Pflicht beim Anlegen, 1 bis TITLE_MAX sichtbare Zeichen),
     * `title_en` (NULL oder ≤ TITLE_MAX), `min_posts` (NULL oder 0 bis MIN_POSTS_MAX), `color` (NULL oder `#rrggbb[aa]`, klein),
     * `icon` (NULL oder `[a-z0-9][a-z0-9-]{0,49}`), `sort_order` (0 bis SORT_MAX).
     *
     * @param array<string, mixed> $data
     * @return array{success: bool, id?: int, error?: string, retry_after?: int}
     */
    public function save(int $actorId, array $data, ?int $rankId = null): array
    {
        $values = self::validate($data, $rankId === null);
        if ($actorId < 1 || $values === null || ($rankId !== null && ($rankId < 1 || $rankId > self::MAX_UINT))) {
            return self::failure('invalid_input');
        }
        $nowTs = ($this->clock)();
        try {
            $gate = $this->gate($actorId, $nowTs);
            if ($gate !== null) {
                return $gate;
            }
            if ($rankId === null) {
                if ($this->stats->countRanks() >= UserStatsRepository::MAX_RANKS) {
                    return self::failure('invalid_input');
                }
                $id = $this->stats->insertRank($values);
            } else {
                if (!$this->stats->updateRank($rankId, $values)) {
                    return self::failure('not_found');
                }
                $id = $rankId;
            }
        } catch (\PDOException | \RuntimeException $e) {
            return $this->failed('rank.save', $e);
        }
        $this->ranks = null;

        return ['success' => true, 'id' => $id];
    }

    /**
     * Rang löschen; verwaiste `rank_id` gelten danach als automatisch (kein Scan der Statistik).
     *
     * @return array{success: bool, changed?: bool, error?: string, retry_after?: int}
     */
    public function delete(int $actorId, int $rankId): array
    {
        if ($actorId < 1 || $rankId < 1 || $rankId > self::MAX_UINT) {
            return self::failure('invalid_input');
        }
        $nowTs = ($this->clock)();
        try {
            $gate = $this->gate($actorId, $nowTs);
            if ($gate !== null) {
                return $gate;
            }
            if (!$this->stats->deleteRank($rankId)) {
                return self::failure('not_found');
            }
        } catch (\PDOException | \RuntimeException $e) {
            return $this->failed('rank.delete', $e);
        }
        $this->ranks = null;

        return ['success' => true, 'changed' => true];
    }

    /** @return array<string, mixed>|null Ablehnung von `can('manage')` oder `null` */
    private function gate(int $actorId, int $nowTs): ?array
    {
        $decision = ($this->can)($actorId, 'manage', ['now' => $nowTs]);
        if ($decision->allowed) {
            return null;
        }
        $result = self::failure($decision->reason !== '' ? $decision->reason : 'no_permission');
        if ($decision->retryAfter !== null) {
            $result['retry_after'] = $decision->retryAfter;
        }

        return $result;
    }

    /**
     * Geprüfte Spalten eines Rangs oder `null` bei einem ungültigen Wert.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private static function validate(array $data, bool $create): ?array
    {
        $allowed = ['title', 'title_en', 'min_posts', 'color', 'icon', 'sort_order'];
        if (array_diff(array_map('strval', array_keys($data)), $allowed) !== [] || ($create && !array_key_exists('title', $data)) || $data === []) {
            return null;
        }
        $values = [];
        if (array_key_exists('title', $data)) {
            $title = is_string($data['title']) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $data['title'])) : '';
            if ($title === '' || !mb_check_encoding($title, 'UTF-8') || mb_strlen($title, 'UTF-8') > self::TITLE_MAX) {
                return null;
            }
            $values['title'] = $title;
        }
        if (array_key_exists('title_en', $data)) {
            $title = $data['title_en'];
            if ($title !== null) {
                $title = is_string($title) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title)) : null;
                if ($title === null || !mb_check_encoding($title, 'UTF-8') || mb_strlen($title, 'UTF-8') > self::TITLE_MAX) {
                    return null;
                }
                $title = $title === '' ? null : $title;
            }
            $values['title_en'] = $title;
        }
        if (array_key_exists('min_posts', $data)) {
            $min = $data['min_posts'];
            if ($min !== null && (!is_int($min) || $min < 0 || $min > self::MIN_POSTS_MAX)) {
                return null;
            }
            $values['min_posts'] = $min;
        }
        foreach (['color' => self::COLOR_PATTERN, 'icon' => self::ICON_PATTERN] as $key => $pattern) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                if ($value !== null && (!is_string($value) || preg_match($pattern, $value) !== 1)) {
                    return null;
                }
                $values[$key] = $value;
            }
        }
        if (array_key_exists('sort_order', $data)) {
            $sort = $data['sort_order'];
            if (!is_int($sort) || $sort < 0 || $sort > self::SORT_MAX) {
                return null;
            }
            $values['sort_order'] = $sort;
        }

        return $values;
    }

    /**
     * @param array{id: int, title: string, title_en: ?string, min_posts: ?int, color: ?string, icon: ?string, sort_order: int} $rank
     * @return array{id: int, title: string, color: ?string, icon: ?string, manual: bool}
     */
    private static function present(array $rank, string $locale, bool $manual): array
    {
        $title = $locale === 'en' && $rank['title_en'] !== null && $rank['title_en'] !== '' ? $rank['title_en'] : $rank['title'];

        return ['id' => $rank['id'], 'title' => $title, 'color' => $rank['color'], 'icon' => $rank['icon'], 'manual' => $manual];
    }

    /** @return array{success: false, error: string} */
    private static function failure(string $error): array
    {
        return ['success' => false, 'error' => $error];
    }

    /** @return array{success: false, error: string} */
    private function failed(string $operation, \Throwable $e): array
    {
        $this->warn($operation, $e);

        return self::failure('db_error');
    }

    private function warn(string $operation, \Throwable $e): void
    {
        Logger::instance()->withChannel('community')->warning('Community-Dienst fehlgeschlagen ({operation}): {exception}', ['operation' => $operation, 'exception' => $e]);
    }
}
