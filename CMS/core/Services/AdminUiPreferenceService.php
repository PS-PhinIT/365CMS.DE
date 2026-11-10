<?php
/**
 * Benutzerbezogene Einstellungen der Admin-Oberfläche.
 *
 * Gespeichert als JSON in `user_meta` (meta_key `admin_ui_prefs`, ein Datensatz je Benutzer) –
 * keine Schemaänderung nötig, `uq_user_meta(user_id, meta_key)` erlaubt ein Upsert:
 *   - `sidebar_collapsed` (bool): Seitenleiste als 76px-Icon-Leiste
 *   - `groups` (map id => bool): manuell auf- (true) oder zugeklappte (false) Menügruppen
 *   - `site` (string): gewählter Site-Kontext (siehe AdminSiteContextService)
 *
 * @package 365CMS
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Json;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminUiPreferenceService
{
    public const META_KEY = 'admin_ui_prefs';
    public const GROUP_ID_PATTERN = '/^[gep]:[A-Za-z0-9_.:-]{1,120}$/';
    private const MAX_GROUP_KEYS = 200;

    private static ?self $instance = null;

    /** @var array<int, array{sidebar_collapsed:bool,groups:array<string,bool>,site:string}> */
    private array $cache = [];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * @return array{sidebar_collapsed:bool,groups:array<string,bool>,site:string}
     */
    public function get(int $userId): array
    {
        if ($userId <= 0) {
            return $this->normalize([]);
        }

        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        $raw = null;
        try {
            $db = Database::instance();
            $raw = $db->get_var(
                "SELECT meta_value FROM {$db->getPrefix()}user_meta WHERE user_id = ? AND meta_key = ? LIMIT 1",
                [$userId, self::META_KEY]
            );
        } catch (\Throwable) {
            $raw = null;
        }

        return $this->cache[$userId] = $this->normalize(Json::decodeArray(is_string($raw) ? $raw : '', []));
    }

    public function isSidebarCollapsed(int $userId): bool
    {
        return $this->get($userId)['sidebar_collapsed'];
    }

    /**
     * @return array<string, bool>
     */
    public function getGroupStates(int $userId): array
    {
        return $this->get($userId)['groups'];
    }

    public function getSite(int $userId): string
    {
        return $this->get($userId)['site'];
    }

    /**
     * Übernimmt Teiländerungen (`sidebar_collapsed`, `groups`, `site`) und speichert sie.
     *
     * @param array<string, mixed> $changes
     * @return array{sidebar_collapsed:bool,groups:array<string,bool>,site:string}|null null bei Speicherfehler
     */
    public function update(int $userId, array $changes): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $prefs = $this->get($userId);

        if (array_key_exists('sidebar_collapsed', $changes)) {
            $prefs['sidebar_collapsed'] = filter_var($changes['sidebar_collapsed'], FILTER_VALIDATE_BOOLEAN);
        }

        if (is_array($changes['groups'] ?? null)) {
            foreach ($changes['groups'] as $groupId => $expanded) {
                $groupId = (string) $groupId;
                if (preg_match(self::GROUP_ID_PATTERN, $groupId) !== 1) {
                    continue;
                }
                // Neu gesetzte Zustände ans Ende verschieben, damit das Limit die ältesten verwirft.
                unset($prefs['groups'][$groupId]);
                $prefs['groups'][$groupId] = filter_var($expanded, FILTER_VALIDATE_BOOLEAN);
            }
            if (count($prefs['groups']) > self::MAX_GROUP_KEYS) {
                $prefs['groups'] = array_slice($prefs['groups'], -self::MAX_GROUP_KEYS, null, true);
            }
        }

        if (array_key_exists('site', $changes)) {
            $site = strtolower(trim((string) $changes['site']));
            $prefs['site'] = preg_match('/^[a-z0-9][a-z0-9.-]{0,62}$/', $site) === 1 ? $site : '';
        }

        $prefs = $this->normalize($prefs);

        try {
            $db = Database::instance();
            $db->execute(
                "INSERT INTO {$db->getPrefix()}user_meta (user_id, meta_key, meta_value) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                [$userId, self::META_KEY, json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]
            );
        } catch (\Throwable) {
            return null;
        }

        return $this->cache[$userId] = $prefs;
    }

    /**
     * @param array<mixed> $prefs
     * @return array{sidebar_collapsed:bool,groups:array<string,bool>,site:string}
     */
    private function normalize(array $prefs): array
    {
        $groups = [];
        foreach ((array) ($prefs['groups'] ?? []) as $groupId => $expanded) {
            $groupId = (string) $groupId;
            if (preg_match(self::GROUP_ID_PATTERN, $groupId) === 1) {
                $groups[$groupId] = (bool) $expanded;
            }
        }

        $site = strtolower(trim((string) ($prefs['site'] ?? '')));

        return [
            'sidebar_collapsed' => !empty($prefs['sidebar_collapsed']),
            'groups' => array_slice($groups, -self::MAX_GROUP_KEYS, null, true),
            'site' => preg_match('/^[a-z0-9][a-z0-9.-]{0,62}$/', $site) === 1 ? $site : '',
        ];
    }
}
