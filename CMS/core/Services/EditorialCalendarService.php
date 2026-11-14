<?php
/**
 * Redaktionskalender: Termine von Beiträgen und Seiten, Verschieben (Drag & Drop bzw. Datumsauswahl) und die
 * Dashboard-Widgets „Meine Entwürfe“ und „Demnächst geplant“.
 *
 * Arten eines Kalendereintrags:
 * - `scheduled`: veröffentlichter Beitrag mit `published_at` in der Zukunft (Planen im Beitragseditor)
 * - `planned`:   Entwurf (Beitrag oder Seite) mit „Geplantem Datum“ (`planned_at`, rein redaktionell)
 * - `published`: veröffentlichte Inhalte (optional eingeblendet, nicht verschiebbar)
 *
 * Datenbankwerte stehen in Serverzeit (wie `published_at`). Anzeige, Tagesgrenzen, „heute“ und das Zieldatum
 * beim Verschieben rechnet der Kalender in die Zeitzone der Website (Einstellung `timezone`) um.
 * Hub-Sites liegen in `site_tables` und sind bewusst ausgenommen.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\AuditLogger;
use CMS\Auth;
use CMS\CacheManager;
use CMS\Database;
use CMS\Hooks;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class EditorialCalendarService
{
    public const TYPES = ['post', 'page'];

    public const KIND_SCHEDULED = 'scheduled';
    public const KIND_PLANNED = 'planned';
    public const KIND_PUBLISHED = 'published';

    public const MOVE_REPLAN = 'replan';
    public const MOVE_RESCHEDULE = 'reschedule';
    public const MOVE_PUBLISH_NOW = 'publish_now';
    public const MOVE_CONFIRM_PUBLISH_NOW = 'confirm_publish_now';
    public const MOVE_NOT_MOVABLE = 'not_movable';

    /** Längster abfragbarer Zeitraum (Monatsansicht = 42 Tage). */
    public const MAX_RANGE_DAYS = 62;

    /** Obergrenze je Abfrage (Art × Typ); darüber meldet die API `truncated`. */
    public const EVENT_LIMIT = 500;

    /** Uhrzeit für Einträge ohne verwertbare Uhrzeit beim Verschieben. */
    private const DEFAULT_TIME = '09:00:00';

    private const LOCALE_NAMES = [
        'de' => 'Deutsch',
        'en' => 'English',
        'fr' => 'Français',
        'es' => 'Español',
        'it' => 'Italiano',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
    ];

    private static ?self $instance = null;

    private Database $db;
    private string $prefix;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Reine Helfer (ohne Datenbank testbar)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Validiert die Editor-Felder „Geplantes Datum“ (Datum + optionale Uhrzeit, Serverzeit).
     *
     * @return array{value:?string,error:?string} value im Format `Y-m-d H:i:s`, null = Feld leeren
     */
    public static function normalizePlannedAtInput(string $date, string $time): array
    {
        $date = trim($date);
        $time = trim($time);

        if ($date === '' && $time === '') {
            return ['value' => null, 'error' => null];
        }

        if ($date === '') {
            return ['value' => null, 'error' => self::t('calendar.planned_at.error_date_missing')];
        }

        if ($time === '') {
            $time = '00:00';
        } elseif (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) === 1) {
            $time = substr($time, 0, 5);
        }

        $value = self::parseStrict('Y-m-d H:i', $date . ' ' . $time);
        if ($value === null || (int) $value->format('Y') < 1970 || (int) $value->format('Y') > 2999) {
            return ['value' => null, 'error' => self::t('calendar.planned_at.error_invalid')];
        }

        return ['value' => $value->format('Y-m-d H:i:s'), 'error' => null];
    }

    /**
     * Zeitzone der Website: gültiger IANA-Bezeichner aus den Einstellungen, sonst die Serverzeitzone.
     */
    public static function resolveTimezoneName(?string $configured, string $fallback): string
    {
        $configured = trim((string) $configured);
        if ($configured !== '' && in_array($configured, \DateTimeZone::listIdentifiers(), true)) {
            return $configured;
        }

        return $fallback;
    }

    public static function parseDate(string $value): ?\DateTimeImmutable
    {
        return self::parseStrict('Y-m-d', trim($value));
    }

    /**
     * Prüft einen Datumsbereich (inklusive Endtag) und begrenzt ihn auf MAX_RANGE_DAYS.
     *
     * @return array{start:string,end:string}|null
     */
    public static function normalizeRange(string $start, string $end, int $maxDays = self::MAX_RANGE_DAYS): ?array
    {
        $startDate = self::parseDate($start);
        $endDate = self::parseDate($end);
        if ($startDate === null || $endDate === null || $endDate < $startDate) {
            return null;
        }

        if ((int) $startDate->diff($endDate)->days > $maxDays) {
            return null;
        }

        return ['start' => $startDate->format('Y-m-d'), 'end' => $endDate->format('Y-m-d')];
    }

    public static function serverToSite(?string $value, \DateTimeZone $server, \DateTimeZone $site): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value, $server))->setTimezone($site);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Beginn eines Website-Tages (00:00 Website-Zeit) als Serverzeit `Y-m-d H:i:s`.
     */
    public static function siteDayStartToServer(string $date, \DateTimeZone $site, \DateTimeZone $server): ?string
    {
        $day = self::parseStrict('Y-m-d', $date, $site);

        return $day?->setTimezone($server)->format('Y-m-d H:i:s');
    }

    /**
     * Neuer Zeitpunkt (Serverzeit) beim Verschieben auf `$targetDate` (Website-Datum). Die Uhrzeit des bisherigen
     * Termins in Website-Zeit bleibt erhalten; existiert sie am Zieltag nicht (Beginn der Sommerzeit), rückt sie auf
     * die nächste gültige Uhrzeit (02:30 → 03:30).
     */
    public static function computeMoveTarget(?string $currentServerValue, string $targetDate, \DateTimeZone $server, \DateTimeZone $site): ?string
    {
        $date = self::parseDate($targetDate);
        if ($date === null) {
            return null;
        }

        $current = self::serverToSite($currentServerValue, $server, $site);
        $time = $current?->format('H:i:s') ?? self::DEFAULT_TIME;
        $value = $date->format('Y-m-d') . ' ' . $time;
        // Datum ist geprüft, Uhrzeit wohlgeformt: Uhrzeit in der Zeitumstellungs-Lücke ohne Rückformatierungs-Vergleich übernehmen.
        $target = self::parseStrict('Y-m-d H:i:s', $value, $site)
            ?? (\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $site) ?: null);

        return $target?->setTimezone($server)->format('Y-m-d H:i:s');
    }

    /**
     * Art eines Inhalts im Kalender oder null, wenn er dort nicht erscheint.
     */
    public static function classify(string $type, string $status, ?string $publishedAt, ?string $plannedAt, string $nowServer): ?string
    {
        if ($status === 'draft') {
            $plannedAt = trim((string) $plannedAt);

            return $plannedAt !== '' && !str_starts_with($plannedAt, '0000-00-00') ? self::KIND_PLANNED : null;
        }

        if ($status === 'published' || ($type === 'page' && $status === 'private')) {
            $publishedAt = trim((string) $publishedAt);
            if ($type === 'post' && $status === 'published' && $publishedAt !== '' && strcmp($publishedAt, $nowServer) > 0) {
                return self::KIND_SCHEDULED;
            }

            return self::KIND_PUBLISHED;
        }

        return null;
    }

    /**
     * Entscheidung beim Verschieben. Geplante Beiträge, deren neuer Termin nicht mehr in der Zukunft liegt,
     * werden nur nach ausdrücklicher Bestätigung („jetzt veröffentlichen“) sofort veröffentlicht.
     */
    public static function decideMove(string $kind, string $targetServer, string $nowServer, bool $publishNow): string
    {
        if ($kind === self::KIND_PLANNED) {
            return self::MOVE_REPLAN;
        }

        if ($kind !== self::KIND_SCHEDULED) {
            return self::MOVE_NOT_MOVABLE;
        }

        if (strcmp($targetServer, $nowServer) > 0) {
            return self::MOVE_RESCHEDULE;
        }

        return $publishNow ? self::MOVE_PUBLISH_NOW : self::MOVE_CONFIRM_PUBLISH_NOW;
    }

    /**
     * @param array<string,mixed> $input Rohwerte aus GET
     * @param list<string> $locales erlaubte Sprachen
     * @return array{author:int,type:string,category:int,lang:string,published:bool}
     */
    public static function normalizeFilters(array $input, array $locales): array
    {
        $scalar = static fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $type = $scalar('type');
        $lang = strtolower($scalar('lang'));

        return [
            'author' => max(0, (int) $scalar('author')),
            'type' => in_array($type, self::TYPES, true) ? $type : '',
            'category' => max(0, (int) $scalar('category')),
            'lang' => in_array($lang, $locales, true) ? $lang : '',
            'published' => in_array($scalar('published'), ['1', 'true', 'on'], true),
        ];
    }

    public static function editUrl(string $type, int $id): string
    {
        return ($type === 'page' ? '/admin/pages' : '/admin/posts') . '?action=edit&id=' . $id;
    }

    public static function localeLabel(string $locale): string
    {
        return self::LOCALE_NAMES[$locale] ?? strtoupper($locale);
    }

    private static function parseStrict(string $format, string $value, ?\DateTimeZone $timezone = null): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors) && ((int) ($errors['warning_count'] ?? 0) > 0 || (int) ($errors['error_count'] ?? 0) > 0);

        if (!$parsed instanceof \DateTimeImmutable || $hasErrors || $parsed->format($format) !== $value) {
            return null;
        }

        return $parsed;
    }

    private static function t(string $key): string
    {
        return function_exists('__') ? (string) __($key) : $key;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Zeitzone
    // ─────────────────────────────────────────────────────────────────────

    public function getServerTimezone(): \DateTimeZone
    {
        return new \DateTimeZone(date_default_timezone_get());
    }

    public function getSiteTimezone(): \DateTimeZone
    {
        $configured = '';
        try {
            $configured = function_exists('get_option') ? (string) get_option('timezone', '') : '';
        } catch (\Throwable) {
        }

        return new \DateTimeZone(self::resolveTimezoneName($configured, date_default_timezone_get()));
    }

    public function nowServer(): string
    {
        return (new \DateTimeImmutable('now', $this->getServerTimezone()))->format('Y-m-d H:i:s');
    }

    public function todaySite(): string
    {
        return (new \DateTimeImmutable('now', $this->getSiteTimezone()))->format('Y-m-d');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Kalender
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Sprachen für den Filter: Deutsch plus konfigurierte Inhaltssprachen mit vorhandenen Spalten.
     *
     * @return list<string>
     */
    public function getFilterLocales(): array
    {
        $locales = ['de'];
        foreach (ContentLocalizationService::getInstance()->getContentLocales() as $locale) {
            // Sprachspalten heißen `*_<code>`; regionale Codes (xx-yy) haben keine gültigen Spaltennamen.
            if (preg_match('/^[a-z]{2}$/', $locale) === 1
                && $this->hasColumn('posts', 'title_' . $locale)
                && $this->hasColumn('pages', 'title_' . $locale)
            ) {
                $locales[] = $locale;
            }
        }

        return array_values(array_unique($locales));
    }

    /**
     * @return array{authors:list<array{id:int,name:string}>,categories:list<array{id:int,name:string}>,locales:list<array{code:string,label:string}>}
     */
    public function getFilterOptions(): array
    {
        $authors = [];
        $categories = [];

        try {
            $rows = $this->db->get_results(
                "SELECT u.id, COALESCE(NULLIF(u.display_name, ''), u.username) AS name
                 FROM {$this->prefix}users u
                 WHERE u.id IN (SELECT p.author_id FROM {$this->prefix}posts p WHERE p.author_id IS NOT NULL)
                    OR u.id IN (SELECT pg.author_id FROM {$this->prefix}pages pg WHERE pg.author_id IS NOT NULL)
                 ORDER BY name ASC
                 LIMIT 200"
            );
            foreach ($rows as $row) {
                $authors[] = ['id' => (int) $row->id, 'name' => (string) $row->name];
            }

            $rows = $this->db->get_results("SELECT id, name FROM {$this->prefix}post_categories ORDER BY name ASC LIMIT 500");
            foreach ($rows as $row) {
                $categories[] = ['id' => (int) $row->id, 'name' => (string) $row->name];
            }
        } catch (\Throwable $e) {
            $this->logError('Filteroptionen des Redaktionskalenders konnten nicht geladen werden.', $e);
        }

        $locales = [];
        foreach ($this->getFilterLocales() as $locale) {
            $locales[] = ['code' => $locale, 'label' => self::localeLabel($locale)];
        }

        return ['authors' => $authors, 'categories' => $categories, 'locales' => $locales];
    }

    /**
     * Kalendereinträge im Website-Datumsbereich [start, end] (inklusive).
     *
     * @param array{author:int,type:string,category:int,lang:string,published:bool} $filters
     * @return array{success:bool,events:list<array<string,mixed>>,truncated:bool,error?:string}
     */
    public function getEvents(string $start, string $end, array $filters): array
    {
        $range = self::normalizeRange($start, $end);
        if ($range === null) {
            return ['success' => false, 'events' => [], 'truncated' => false, 'error' => self::t('calendar.error.range')];
        }

        $site = $this->getSiteTimezone();
        $server = $this->getServerTimezone();
        $from = self::siteDayStartToServer($range['start'], $site, $server);
        $until = self::siteDayStartToServer((new \DateTimeImmutable($range['end']))->modify('+1 day')->format('Y-m-d'), $site, $server);
        if ($from === null || $until === null) {
            return ['success' => false, 'events' => [], 'truncated' => false, 'error' => self::t('calendar.error.range')];
        }

        $now = $this->nowServer();
        $events = [];
        $truncated = false;

        try {
            foreach (self::TYPES as $type) {
                if ($filters['type'] !== '' && $filters['type'] !== $type) {
                    continue;
                }

                $kinds = $type === 'post' ? [self::KIND_SCHEDULED, self::KIND_PLANNED] : [self::KIND_PLANNED];
                if ($filters['published']) {
                    $kinds[] = self::KIND_PUBLISHED;
                }

                foreach ($kinds as $kind) {
                    $rows = $this->queryEvents($type, $kind, $from, $until, $now, $filters);
                    if (count($rows) >= self::EVENT_LIMIT) {
                        $truncated = true;
                    }
                    foreach ($rows as $row) {
                        $event = $this->buildEvent($type, $kind, $row, $site, $server);
                        if ($event !== null) {
                            $events[] = $event;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logError('Kalendereinträge konnten nicht geladen werden.', $e, ['start' => $range['start'], 'end' => $range['end']]);

            return ['success' => false, 'events' => [], 'truncated' => false, 'error' => self::t('calendar.error.load')];
        }

        usort($events, static fn (array $a, array $b): int => [$a['datetime'], $a['title']] <=> [$b['datetime'], $b['title']]);

        return ['success' => true, 'events' => $events, 'truncated' => $truncated];
    }

    /**
     * @param array{author:int,type:string,category:int,lang:string,published:bool} $filters
     * @return list<object>
     */
    private function queryEvents(string $type, string $kind, string $from, string $until, string $now, array $filters): array
    {
        $isPost = $type === 'post';
        $alias = $isPost ? 'p' : 'pg';
        $table = $this->prefix . ($isPost ? 'posts' : 'pages');
        $params = [];

        switch ($kind) {
            case self::KIND_SCHEDULED:
                $dateExpr = "{$alias}.published_at";
                $where = ["{$alias}.status = ?", "{$alias}.published_at > ?"];
                $params = ['published', $now];
                break;
            case self::KIND_PLANNED:
                $dateExpr = "{$alias}.planned_at";
                $where = ["{$alias}.status = ?"];
                $params = ['draft'];
                break;
            default:
                $dateExpr = "COALESCE({$alias}.published_at, {$alias}.created_at)";
                if ($isPost) {
                    $where = ["{$alias}.status = ?", "{$dateExpr} <= ?"];
                    $params = ['published', $now];
                } else {
                    $where = ["{$alias}.status IN (?, ?)"];
                    $params = ['published', 'private'];
                }
                break;
        }

        $where[] = "{$alias}.trashed_at IS NULL";
        $where[] = "{$dateExpr} >= ?";
        $where[] = "{$dateExpr} < ?";
        $params[] = $from;
        $params[] = $until;

        if ($filters['author'] > 0) {
            $where[] = "{$alias}.author_id = ?";
            $params[] = $filters['author'];
        }

        if ($filters['category'] > 0) {
            if ($isPost) {
                $where[] = "({$alias}.category_id = ? OR EXISTS (SELECT 1 FROM {$this->prefix}post_category_rel r WHERE r.post_id = {$alias}.id AND r.category_id = ?))";
                $params[] = $filters['category'];
                $params[] = $filters['category'];
            } else {
                $where[] = "{$alias}.category_id = ?";
                $params[] = $filters['category'];
            }
        }

        if ($filters['lang'] !== '') {
            $where[] = $isPost
                ? ContentLocalizationService::getInstance()->buildPostAvailabilitySql($alias, $filters['lang'])
                : ContentLocalizationService::pageLocaleAvailabilityExpression($filters['lang'], $alias);
        }

        $params[] = self::EVENT_LIMIT;

        return $this->db->get_results(
            "SELECT {$alias}.id, {$alias}.title, {$alias}.title_en, {$alias}.status, {$alias}.author_id,
                    {$dateExpr} AS event_at,
                    COALESCE(NULLIF(u.display_name, ''), u.username) AS author_name,
                    c.name AS category_name
             FROM {$table} {$alias}
             LEFT JOIN {$this->prefix}users u ON u.id = {$alias}.author_id
             LEFT JOIN {$this->prefix}post_categories c ON c.id = {$alias}.category_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY event_at ASC, {$alias}.id ASC
             LIMIT ?",
            $params
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildEvent(string $type, string $kind, object $row, \DateTimeZone $site, \DateTimeZone $server): ?array
    {
        $at = self::serverToSite((string) ($row->event_at ?? ''), $server, $site);
        if ($at === null) {
            return null;
        }

        $id = (int) $row->id;
        $authorId = (int) ($row->author_id ?? 0);
        $movable = $kind !== self::KIND_PUBLISHED && ContentEditPermission::currentUserCanEdit($type, $authorId);

        return [
            'uid' => $type . '-' . $id,
            'type' => $type,
            'id' => $id,
            'title' => $this->resolveTitle($row),
            'kind' => $kind,
            'status' => (string) ($row->status ?? ''),
            'date' => $at->format('Y-m-d'),
            'time' => $at->format('H:i'),
            'time_label' => $at->format($this->timeFormat()),
            'datetime' => $at->format(DATE_ATOM),
            'author' => (string) ($row->author_name ?? ''),
            'author_id' => $authorId,
            'category' => (string) ($row->category_name ?? ''),
            'edit_url' => self::editUrl($type, $id),
            'movable' => $movable,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Verschieben
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Verschiebt einen Eintrag auf ein neues Website-Datum.
     *
     * @return array{status:int,body:array<string,mixed>}
     */
    public function move(string $type, int $id, string $targetDate, bool $publishNow): array
    {
        if (!in_array($type, self::TYPES, true) || $id <= 0 || self::parseDate($targetDate) === null) {
            return $this->moveResult(400, false, 'invalid', self::t('calendar.move.error_invalid'));
        }

        try {
            $row = $this->loadMoveRow($type, $id);
        } catch (\Throwable $e) {
            $this->logError('Kalendereintrag konnte nicht geladen werden.', $e, ['type' => $type, 'id' => $id]);

            return $this->moveResult(500, false, 'error', self::t('calendar.move.error_failed'));
        }

        if ($row === null) {
            return $this->moveResult(404, false, 'not_found', self::t('calendar.move.error_not_found'));
        }

        $auth = Auth::instance();
        $userId = ContentEditPermission::currentUserId();
        $can = static fn (string $capability): bool => $auth->hasCapability($capability);
        if (!ContentEditPermission::canEdit($type, (int) ($row->author_id ?? 0), $userId, $can)) {
            return $this->moveResult(403, false, 'forbidden', self::t('calendar.move.error_forbidden'));
        }

        $now = $this->nowServer();
        $kind = self::classify($type, (string) $row->status, $row->published_at ?? null, $row->planned_at ?? null, $now);
        if ($kind === null || $kind === self::KIND_PUBLISHED) {
            return $this->moveResult(422, false, 'not_movable', self::t('calendar.move.error_not_movable'));
        }

        $site = $this->getSiteTimezone();
        $server = $this->getServerTimezone();
        $current = $kind === self::KIND_SCHEDULED ? ($row->published_at ?? null) : ($row->planned_at ?? null);
        $target = self::computeMoveTarget($current, $targetDate, $server, $site);
        if ($target === null) {
            return $this->moveResult(400, false, 'invalid', self::t('calendar.move.error_invalid'));
        }

        $decision = self::decideMove($kind, $target, $now, $publishNow);
        if ($decision === self::MOVE_CONFIRM_PUBLISH_NOW) {
            return $this->moveResult(409, false, 'needs_confirmation', self::t('calendar.move.confirm_publish_now'), [
                'needs_confirmation' => 'publish_now',
            ]);
        }

        $title = $this->resolveTitle($row);
        $newValue = $decision === self::MOVE_PUBLISH_NOW ? $now : $target;

        if ($decision === self::MOVE_RESCHEDULE || $decision === self::MOVE_PUBLISH_NOW) {
            $checklistError = $this->checkPublishChecklist($row, $newValue);
            if ($checklistError !== null) {
                return $checklistError;
            }
        }

        $expectedStatus = $decision === self::MOVE_REPLAN ? 'draft' : 'published';

        try {
            if ($decision === self::MOVE_REPLAN) {
                $statement = $this->db->execute(
                    "UPDATE {$this->prefix}" . ($type === 'post' ? 'posts' : 'pages') . " SET planned_at = ?, updated_at = updated_at WHERE id = ? AND status = ?",
                    [$newValue, $id, $expectedStatus]
                );
            } else {
                $statement = $this->db->execute(
                    "UPDATE {$this->prefix}posts SET published_at = ?, updated_at = NOW() WHERE id = ? AND status = ?",
                    [$newValue, $id, $expectedStatus]
                );
            }

            // rowCount() zählt geänderte Zeilen: 0 heißt entweder „gleicher Wert“ oder „Status inzwischen anders“.
            if ($statement->rowCount() < 1) {
                $fresh = $this->loadMoveRow($type, $id);
                if (self::moveTargetChanged($fresh !== null ? (string) ($fresh->status ?? '') : null, $expectedStatus)) {
                    return $this->moveResult(409, false, 'state_changed', self::t('calendar.move.error_state_changed'));
                }
            }
        } catch (\Throwable $e) {
            $this->logError('Kalendereintrag konnte nicht verschoben werden.', $e, ['type' => $type, 'id' => $id, 'decision' => $decision]);

            return $this->moveResult(500, false, 'error', self::t('calendar.move.error_failed'));
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_CONTENT,
            $decision === self::MOVE_PUBLISH_NOW ? 'calendar.publish_now' : 'calendar.move',
            sprintf('Redaktionskalender: „%s“ %s', $title, $decision === self::MOVE_PUBLISH_NOW ? 'sofort veröffentlicht' : 'verschoben auf ' . $newValue),
            $type === 'post' ? 'posts' : 'pages',
            $id,
            ['kind' => $kind, 'from' => $current, 'to' => $newValue, 'decision' => $decision],
            'info'
        );

        if ($decision !== self::MOVE_REPLAN) {
            // Öffentliche Wirkung (Termin bzw. sofortige Veröffentlichung): Suchindex, Sitemap, Feeds und
            // IndexNow hängen an cms_after_post_save; ohne Formulardaten (drittes Argument leer).
            Hooks::doAction('cms_after_post_save', $id, ['status' => 'published', 'published_at' => $newValue], []);
            $this->clearContentCacheIfEnabled();
        }

        $message = match ($decision) {
            self::MOVE_PUBLISH_NOW => self::t('calendar.move.published_now'),
            default => self::t('calendar.move.success'),
        };

        $event = null;
        $eventKind = $decision === self::MOVE_PUBLISH_NOW ? self::KIND_PUBLISHED : $kind;
        $row->event_at = $newValue;
        $row->author_name = $row->author_name ?? '';
        $event = $this->buildEvent($type, $eventKind, $row, $site, $server);

        return $this->moveResult(200, true, $decision, $message, ['event' => $event]);
    }

    /**
     * Hat ein anderer Vorgang den Eintrag zwischen Lesen und Schreiben verändert (Status gewechselt, in den
     * Papierkorb gelegt, gelöscht)? $currentStatus ist null, wenn der Eintrag nicht mehr ladbar ist.
     */
    public static function moveTargetChanged(?string $currentStatus, string $expectedStatus): bool
    {
        return $currentStatus === null || $currentStatus !== $expectedStatus;
    }

    private function loadMoveRow(string $type, int $id): ?object
    {
        if ($type === 'post') {
            $relationSelect = "(SELECT MIN(r.category_id) FROM {$this->prefix}post_category_rel r WHERE r.post_id = p.id)";

            return $this->db->get_row(
                "SELECT p.id, p.title, p.title_en, p.status, p.author_id, p.published_at, p.planned_at,
                        p.featured_image, p.meta_description, p.category_id, {$relationSelect} AS rel_category_id,
                        COALESCE(NULLIF(u.display_name, ''), u.username) AS author_name, c.name AS category_name
                 FROM {$this->prefix}posts p
                 LEFT JOIN {$this->prefix}users u ON u.id = p.author_id
                 LEFT JOIN {$this->prefix}post_categories c ON c.id = p.category_id
                 WHERE p.id = ? AND p.trashed_at IS NULL
                 LIMIT 1",
                [$id]
            );
        }

        return $this->db->get_row(
            "SELECT pg.id, pg.title, pg.title_en, pg.status, pg.author_id, pg.published_at, pg.planned_at,
                    COALESCE(NULLIF(u.display_name, ''), u.username) AS author_name, c.name AS category_name
             FROM {$this->prefix}pages pg
             LEFT JOIN {$this->prefix}users u ON u.id = pg.author_id
             LEFT JOIN {$this->prefix}post_categories c ON c.id = pg.category_id
             WHERE pg.id = ? AND pg.trashed_at IS NULL
             LIMIT 1",
            [$id]
        );
    }

    /**
     * Gleiche Regeln wie der Beitragseditor: Planen und Veröffentlichen verlangen die vollständige Checkliste.
     *
     * @return array{status:int,body:array<string,mixed>}|null
     */
    private function checkPublishChecklist(object $row, string $targetPublishedAt): ?array
    {
        $checklist = PostPublishChecklistService::getInstance();
        $existing = ['status' => (string) $row->status, 'published_at' => $row->published_at ?? null];
        if (!$checklist->requiresChecklist('published', $targetPublishedAt, $existing)) {
            return null;
        }

        $categoryId = (int) ($row->category_id ?? 0);
        if ($categoryId <= 0) {
            $categoryId = (int) ($row->rel_category_id ?? 0);
        }

        $result = $checklist->evaluate([
            'featured_image' => (string) ($row->featured_image ?? ''),
            'meta_description' => (string) ($row->meta_description ?? ''),
            'category_id' => $categoryId,
        ]);
        if ($result['passed']) {
            return null;
        }

        return $this->moveResult(422, false, 'checklist', self::t('calendar.move.error_checklist'), [
            'details' => $result['missing'],
        ]);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array{status:int,body:array<string,mixed>}
     */
    private function moveResult(int $status, bool $success, string $code, string $message, array $extra = []): array
    {
        $body = ['success' => $success, 'code' => $code];
        $body[$success ? 'message' : 'error'] = $message;

        return ['status' => $status, 'body' => $body + $extra];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Geplantes Datum (Editor)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Werte der Editor-Felder „Geplantes Datum“: nach einem abgelehnten Speichervorgang die Eingabe
     * (`planned_date`/`planned_time`), sonst der gespeicherte Wert (`planned_at`, Serverzeit wie die übrigen
     * Datumsfelder des Editors). Der Zeitzonenhinweis erscheint nur, wenn die Website eine andere Zeitzone nutzt.
     *
     * @param array<string,mixed>|object|null $content
     * @return array{date:string,time:string,timezone_hint:string}
     */
    public function getPlannedAtFieldValues(array|object|null $content): array
    {
        $content = is_object($content) ? (array) $content : ($content ?? []);
        $date = '';
        $time = '';

        if (array_key_exists('planned_date', $content)) {
            $date = is_scalar($content['planned_date']) ? trim((string) $content['planned_date']) : '';
            $time = is_scalar($content['planned_time'] ?? null) ? trim((string) $content['planned_time']) : '';
        } else {
            $planned = self::serverToSite(is_scalar($content['planned_at'] ?? null) ? (string) $content['planned_at'] : '', $this->getServerTimezone(), $this->getServerTimezone());
            if ($planned !== null) {
                $date = $planned->format('Y-m-d');
                $time = $planned->format('H:i');
            }
        }

        $serverName = $this->getServerTimezone()->getName();
        $hint = $this->getSiteTimezone()->getName() !== $serverName
            ? sprintf(self::t('calendar.planned_at.timezone_hint'), $serverName)
            : '';

        return ['date' => $date, 'time' => $time, 'timezone_hint' => $hint];
    }

    /**
     * Speichert „Geplantes Datum“ ohne `updated_at` zu verändern (reines Redaktionsfeld).
     */
    public function savePlannedAt(string $type, int $id, ?string $value): bool
    {
        if (!in_array($type, self::TYPES, true) || $id <= 0) {
            return false;
        }

        try {
            $this->db->execute(
                "UPDATE {$this->prefix}" . ($type === 'post' ? 'posts' : 'pages') . " SET planned_at = ?, updated_at = updated_at WHERE id = ?",
                [$value, $id]
            );

            return true;
        } catch (\Throwable $e) {
            $this->logError('Geplantes Datum konnte nicht gespeichert werden.', $e, ['type' => $type, 'id' => $id]);

            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Dashboard-Widgets
    // ─────────────────────────────────────────────────────────────────────

    /**
     * „Meine Entwürfe“: zuletzt bearbeitete eigene Entwürfe (Beiträge und Seiten).
     *
     * @return list<array<string,mixed>>
     */
    public function getMyDrafts(int $userId, int $limit = 5): array
    {
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min(20, $limit));
        $rows = $this->db->get_results(
            "(SELECT 'post' AS content_type, p.id, p.title, p.title_en, p.updated_at, p.planned_at
              FROM {$this->prefix}posts p
              WHERE p.author_id = ? AND p.status = ? AND p.trashed_at IS NULL
              ORDER BY p.updated_at DESC LIMIT ?)
             UNION ALL
             (SELECT 'page' AS content_type, pg.id, pg.title, pg.title_en, pg.updated_at, pg.planned_at
              FROM {$this->prefix}pages pg
              WHERE pg.author_id = ? AND pg.status = ? AND pg.trashed_at IS NULL
              ORDER BY pg.updated_at DESC LIMIT ?)
             ORDER BY updated_at DESC
             LIMIT ?",
            [$userId, 'draft', $limit, $userId, 'draft', $limit, $limit]
        );

        $site = $this->getSiteTimezone();
        $server = $this->getServerTimezone();
        $items = [];
        foreach ($rows as $row) {
            $type = (string) $row->content_type;
            $updated = self::serverToSite((string) ($row->updated_at ?? ''), $server, $site);
            $planned = self::serverToSite((string) ($row->planned_at ?? ''), $server, $site);
            $items[] = [
                'type' => $type,
                'id' => (int) $row->id,
                'title' => $this->resolveTitle($row),
                'updated_label' => $updated?->format($this->dateTimeFormat()) ?? '',
                'planned_label' => $planned?->format($this->dateTimeFormat()) ?? '',
                'edit_url' => self::editUrl($type, (int) $row->id),
            ];
        }

        return $items;
    }

    /**
     * „Demnächst geplant“: geplante Beiträge und Entwürfe mit geplantem Datum von heute bis in 7 Tagen
     * (Website-Tage). Ohne Kalenderrecht nur eigene Inhalte.
     *
     * @return list<array<string,mixed>>
     */
    public function getUpcoming(int $userId, bool $allContent, int $days = 7, int $limit = 10): array
    {
        $site = $this->getSiteTimezone();
        $server = $this->getServerTimezone();
        $today = $this->todaySite();
        $from = self::siteDayStartToServer($today, $site, $server);
        $until = self::siteDayStartToServer((new \DateTimeImmutable($today))->modify('+' . max(1, $days) . ' days')->format('Y-m-d'), $site, $server);
        if ($from === null || $until === null || (!$allContent && $userId <= 0)) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $now = $this->nowServer();
        $authorSql = $allContent ? '' : ' AND x.author_id = ?';
        $queries = [
            ["SELECT 'post' AS content_type, 'scheduled' AS kind, x.id, x.title, x.title_en, x.published_at AS event_at
              FROM {$this->prefix}posts x
              WHERE x.status = ? AND x.trashed_at IS NULL AND x.published_at > ? AND x.published_at >= ? AND x.published_at < ?{$authorSql}",
             ['published', $now, $from, $until]],
            ["SELECT 'post' AS content_type, 'planned' AS kind, x.id, x.title, x.title_en, x.planned_at AS event_at
              FROM {$this->prefix}posts x
              WHERE x.status = ? AND x.trashed_at IS NULL AND x.planned_at >= ? AND x.planned_at < ?{$authorSql}",
             ['draft', $from, $until]],
            ["SELECT 'page' AS content_type, 'planned' AS kind, x.id, x.title, x.title_en, x.planned_at AS event_at
              FROM {$this->prefix}pages x
              WHERE x.status = ? AND x.trashed_at IS NULL AND x.planned_at >= ? AND x.planned_at < ?{$authorSql}",
             ['draft', $from, $until]],
        ];

        $sql = [];
        $params = [];
        foreach ($queries as [$query, $queryParams]) {
            $sql[] = '(' . $query . ' ORDER BY event_at ASC LIMIT ?)';
            if (!$allContent) {
                $queryParams[] = $userId;
            }
            $queryParams[] = $limit;
            array_push($params, ...$queryParams);
        }
        $params[] = $limit;

        $rows = $this->db->get_results(implode(' UNION ALL ', $sql) . ' ORDER BY event_at ASC LIMIT ?', $params);

        $items = [];
        foreach ($rows as $row) {
            $type = (string) $row->content_type;
            $at = self::serverToSite((string) ($row->event_at ?? ''), $server, $site);
            if ($at === null) {
                continue;
            }
            $items[] = [
                'type' => $type,
                'kind' => (string) $row->kind,
                'id' => (int) $row->id,
                'title' => $this->resolveTitle($row),
                'date' => $at->format('Y-m-d'),
                'when_label' => $at->format($this->dateTimeFormat()),
                'edit_url' => self::editUrl($type, (int) $row->id),
            ];
        }

        return $items;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Intern
    // ─────────────────────────────────────────────────────────────────────

    private function resolveTitle(object $row): string
    {
        foreach (['title', 'title_en'] as $field) {
            $value = trim((string) ($row->{$field} ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return self::t('calendar.untitled');
    }

    private function timeFormat(): string
    {
        $format = function_exists('get_option') ? (string) get_option('time_format', 'H:i') : 'H:i';

        return in_array($format, ['H:i', 'G:i', 'g:i a', 'g:i A', 'h:i A'], true) ? $format : 'H:i';
    }

    private function dateTimeFormat(): string
    {
        $format = function_exists('get_option') ? (string) get_option('date_format', 'd.m.Y') : 'd.m.Y';
        $format = in_array($format, ['d.m.Y', 'Y-m-d', 'm/d/Y', 'd/m/Y'], true) ? $format : 'd.m.Y';

        return $format . ' ' . $this->timeFormat();
    }

    private function hasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            try {
                $cache[$key] = $this->db->columnExists($this->prefix . $table, $column);
            } catch (\Throwable) {
                $cache[$key] = false;
            }
        }

        return $cache[$key];
    }

    private function clearContentCacheIfEnabled(): void
    {
        try {
            $enabled = (string) ($this->db->get_var("SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1", ['perf_auto_clear_content_cache']) ?? '1') !== '0';
            if ($enabled) {
                CacheManager::instance()->clear();
            }
        } catch (\Throwable $e) {
            $this->logError('Content-Cache konnte nach dem Verschieben nicht geleert werden.', $e);
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function logError(string $message, \Throwable $e, array $context = []): void
    {
        Logger::instance()->withChannel('content.calendar')->warning($message, $context + ['exception' => $e->getMessage()]);
    }
}
