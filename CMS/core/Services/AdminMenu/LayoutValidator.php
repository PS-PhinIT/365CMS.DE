<?php
/**
 * Prüft und normalisiert ein Admin-Menü-Layout (Format „365cms-admin-menu“, Version 1).
 *
 * - höchstens 2 Ebenen: verschachtelte Gruppen werden mit Warnung aufgelöst
 * - unbekannte Core-IDs werden mit Warnung entfernt; `plugin:`-IDs bleiben (Plugin evtl. inaktiv)
 * - doppelte IDs werden entfernt, gesperrte Einträge können nicht ausgeblendet werden und
 *   werden bei Fehlen wieder eingefügt (Schutz vor Aussperren)
 * - Beschriftungen sind reiner Anzeigetext; Routen und Capabilities sind nicht Teil des Layouts
 *
 * @package CMSv2\Core\Services\AdminMenu
 */

declare(strict_types=1);

namespace CMS\Services\AdminMenu;

if (!defined('ABSPATH')) {
    exit;
}

final class LayoutValidator
{
    public const FORMAT = '365cms-admin-menu';
    public const FORMAT_VERSION = 1;
    public const MAX_NODES = 200;
    public const MAX_LABEL_LENGTH = 60;
    public const MAX_JSON_BYTES = 262144;

    public const DISPLAY_MENU = 'menu';
    public const DISPLAY_TILES = 'tiles';

    public const NODE_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/';
    public const ICON_PATTERN = '/^[a-z0-9][a-z0-9-]{0,59}$/';

    /**
     * @param array<string, true> $knownCoreIds
     * @param list<string>        $lockedIds
     * @return array{valid:bool, layout:array<string, mixed>, warnings:list<string>, errors:list<string>}
     */
    public static function validate(mixed $input, array $knownCoreIds, array $lockedIds = []): array
    {
        $warnings = [];
        $errors = [];

        if (!is_array($input)) {
            return self::result(false, self::emptyLayout(ProfileLayouts::CLASSIC), [], ['Das Layout muss ein JSON-Objekt sein.']);
        }

        $format = $input['format'] ?? self::FORMAT;
        if ($format !== self::FORMAT) {
            $errors[] = 'Unbekanntes Format. Erwartet wird „' . self::FORMAT . '“.';
        }
        $version = $input['version'] ?? self::FORMAT_VERSION;
        if (!is_int($version) || $version < 1 || $version > self::FORMAT_VERSION) {
            $errors[] = 'Nicht unterstützte Formatversion. Unterstützt wird Version ' . self::FORMAT_VERSION . '.';
        }
        if (!isset($input['nodes']) || !is_array($input['nodes']) || !array_is_list($input['nodes'])) {
            $errors[] = 'Das Feld „nodes“ fehlt oder ist keine Liste.';
        }
        if ($errors !== []) {
            return self::result(false, self::emptyLayout(ProfileLayouts::CLASSIC), $warnings, $errors);
        }

        $base = (string) ($input['base'] ?? ProfileLayouts::CLASSIC);
        if (!in_array($base, ProfileLayouts::PROFILES, true)) {
            $warnings[] = 'Unbekanntes Basisprofil „' . self::short($base) . '“ – „classic“ wird verwendet.';
            $base = ProfileLayouts::CLASSIC;
        }

        $nodes = [];
        $seenItems = [];
        $seenNodes = [];
        $count = 0;

        $acceptItem = static function (mixed $raw, string $context) use (&$seenItems, &$warnings, $knownCoreIds): ?string {
            $id = is_array($raw) ? (string) ($raw['id'] ?? '') : (is_string($raw) ? $raw : '');
            if ($id === '' || preg_match(AdminMenuItem::ID_PATTERN, $id) !== 1) {
                $warnings[] = 'Ungültige Eintrags-ID ' . $context . ' wurde übersprungen.';

                return null;
            }
            if (!str_starts_with($id, 'plugin:') && !isset($knownCoreIds[$id])) {
                $warnings[] = 'Unbekannter Eintrag „' . self::short($id) . '“ wurde übersprungen.';

                return null;
            }
            if (isset($seenItems[$id])) {
                $warnings[] = 'Doppelter Eintrag „' . self::short($id) . '“ wurde entfernt.';

                return null;
            }
            $seenItems[$id] = true;

            return $id;
        };

        $uniqueNodeId = static function (string $id, string $prefix) use (&$seenNodes, &$warnings): string {
            if ($id === '' || preg_match(self::NODE_ID_PATTERN, $id) !== 1) {
                $id = $prefix . '-' . (count($seenNodes) + 1);
            }
            $candidate = $id;
            $suffix = 2;
            while (isset($seenNodes[$candidate])) {
                $candidate = $id . '-' . $suffix++;
            }
            if ($candidate !== $id) {
                $warnings[] = 'Doppelte Gruppen- bzw. Trenner-ID „' . self::short($id) . '“ wurde umbenannt.';
            }
            $seenNodes[$candidate] = true;

            return $candidate;
        };

        foreach ($input['nodes'] as $position => $node) {
            if (++$count > self::MAX_NODES) {
                $errors[] = 'Das Layout enthält mehr als ' . self::MAX_NODES . ' Elemente.';
                break;
            }
            if (is_string($node)) {
                $node = ['type' => 'item', 'id' => $node];
            }
            if (!is_array($node)) {
                $warnings[] = 'Element ' . ((int) $position + 1) . ' hat ein ungültiges Format und wurde übersprungen.';
                continue;
            }

            $type = (string) ($node['type'] ?? 'item');
            if ($type === 'separator') {
                $nodes[] = [
                    'type' => 'separator',
                    'id' => $uniqueNodeId((string) ($node['id'] ?? ''), 'sep'),
                    'label' => self::label($node['label'] ?? ''),
                ];
                continue;
            }

            if ($type === 'item') {
                $id = $acceptItem($node, 'auf Ebene 1');
                if ($id !== null) {
                    $nodes[] = ['type' => 'item', 'id' => $id];
                }
                continue;
            }

            if ($type !== 'group') {
                $warnings[] = 'Unbekannter Elementtyp „' . self::short($type) . '“ wurde übersprungen.';
                continue;
            }

            $groupId = $uniqueNodeId((string) ($node['id'] ?? ''), 'group');
            $label = self::label($node['label'] ?? '');
            $display = (string) ($node['display'] ?? self::DISPLAY_MENU);
            if (!in_array($display, [self::DISPLAY_MENU, self::DISPLAY_TILES], true)) {
                $display = self::DISPLAY_MENU;
            }

            $items = [];
            foreach (self::flattenGroupItems($node['items'] ?? [], $groupId, $warnings) as $child) {
                if (++$count > self::MAX_NODES) {
                    $errors[] = 'Das Layout enthält mehr als ' . self::MAX_NODES . ' Elemente.';
                    break 2;
                }
                $id = $acceptItem($child, 'in Gruppe „' . self::short($groupId) . '“');
                if ($id !== null) {
                    $items[] = $id;
                }
            }

            $nodes[] = [
                'type' => 'group',
                'id' => $groupId,
                'label' => $label !== '' ? $label : $groupId,
                'icon' => self::icon($node['icon'] ?? '') ?? 'folder',
                'display' => $display,
                'items' => $items,
            ];
        }

        if ($errors !== []) {
            return self::result(false, self::emptyLayout($base), $warnings, $errors);
        }

        $labels = [];
        foreach (is_array($input['labels'] ?? null) ? $input['labels'] : [] as $id => $label) {
            $label = self::label($label);
            if (is_string($id) && $label !== '' && preg_match(AdminMenuItem::ID_PATTERN, $id) === 1
                && (str_starts_with($id, 'plugin:') || isset($knownCoreIds[$id]))) {
                $labels[$id] = $label;
            }
        }

        $icons = [];
        foreach (is_array($input['icons'] ?? null) ? $input['icons'] : [] as $id => $icon) {
            $icon = self::icon($icon);
            if (is_string($id) && $icon !== null && preg_match(AdminMenuItem::ID_PATTERN, $id) === 1
                && (str_starts_with($id, 'plugin:') || isset($knownCoreIds[$id]))) {
                $icons[$id] = $icon;
            }
        }

        $locked = array_fill_keys($lockedIds, true);
        $hidden = [];
        foreach (is_array($input['hidden'] ?? null) ? $input['hidden'] : [] as $id) {
            if (!is_string($id) || preg_match(AdminMenuItem::ID_PATTERN, $id) !== 1) {
                continue;
            }
            if (isset($locked[$id])) {
                $warnings[] = 'Der gesperrte Eintrag „' . self::short($id) . '“ kann nicht ausgeblendet werden.';
                continue;
            }
            if (str_starts_with($id, 'plugin:') || isset($knownCoreIds[$id])) {
                $hidden[$id] = true;
            }
        }

        // Gesperrte Einträge müssen erreichbar bleiben: fehlende wieder einfügen.
        foreach ($lockedIds as $lockedId) {
            if (isset($seenItems[$lockedId])) {
                continue;
            }
            $warnings[] = 'Der gesperrte Eintrag „' . $lockedId . '“ wurde wieder eingefügt.';
            $target = ProfileLayouts::baseGroupOf($base, $lockedId);
            $inserted = false;
            if ($target !== null) {
                foreach ($nodes as &$node) {
                    if ($node['type'] === 'group' && $node['id'] === $target) {
                        $node['items'][] = $lockedId;
                        $inserted = true;
                        break;
                    }
                }
                unset($node);
            }
            if (!$inserted) {
                $entry = ['type' => 'item', 'id' => $lockedId];
                if ($lockedId === 'dashboard') {
                    $position = isset($nodes[0]) && $nodes[0]['type'] === 'separator' ? 1 : 0;
                    array_splice($nodes, $position, 0, [$entry]);
                } else {
                    $nodes[] = $entry;
                }
            }
            $seenItems[$lockedId] = true;
        }

        $groupIds = [];
        $tileGroupIds = [];
        foreach ($nodes as $node) {
            if ($node['type'] === 'group') {
                $groupIds[$node['id']] = true;
                if ($node['display'] === self::DISPLAY_TILES) {
                    $tileGroupIds[$node['id']] = true;
                }
            }
        }

        $fallback = (string) ($input['fallback_group'] ?? '');
        if ($fallback !== '' && !isset($groupIds[$fallback])) {
            $warnings[] = 'Die Auffanggruppe „' . self::short($fallback) . '“ fehlt; nicht platzierte Core-Einträge erscheinen in der Gruppe „Weitere“.';
            $fallback = '';
        }

        $root = (string) ($input['root_group'] ?? '');
        if ($root !== '' && !isset($tileGroupIds[$root])) {
            $warnings[] = 'Die Start-Kachelseite „' . self::short($root) . '“ ist keine Kachelgruppe dieses Layouts.';
            $root = '';
        }

        return self::result(true, [
            'format' => self::FORMAT,
            'version' => self::FORMAT_VERSION,
            'base' => $base,
            'fallback_group' => $fallback,
            'root_group' => $root,
            'nodes' => $nodes,
            'labels' => $labels,
            'icons' => $icons,
            'hidden' => array_keys($hidden),
        ], $warnings, []);
    }

    /**
     * Prüft Größe und Syntax eines JSON-Imports und validiert das Layout.
     *
     * @param array<string, true> $knownCoreIds
     * @param list<string>        $lockedIds
     * @return array{valid:bool, layout:array<string, mixed>, warnings:list<string>, errors:list<string>}
     */
    public static function validateJson(string $json, array $knownCoreIds, array $lockedIds = []): array
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            return self::result(false, self::emptyLayout(ProfileLayouts::CLASSIC), [], ['Die Datei ist größer als ' . (int) (self::MAX_JSON_BYTES / 1024) . ' KB.']);
        }

        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::result(false, self::emptyLayout(ProfileLayouts::CLASSIC), [], ['Die Datei enthält kein gültiges JSON.']);
        }

        if (is_array($decoded) && isset($decoded['layout']) && is_array($decoded['layout'])) {
            $decoded = $decoded['layout'];
        }

        return self::validate($decoded, $knownCoreIds, $lockedIds);
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyLayout(string $base): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::FORMAT_VERSION,
            'base' => in_array($base, ProfileLayouts::PROFILES, true) ? $base : ProfileLayouts::CLASSIC,
            'fallback_group' => '',
            'root_group' => '',
            'nodes' => [],
            'labels' => [],
            'icons' => [],
            'hidden' => [],
        ];
    }

    public static function label(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = strip_tags($value);
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return function_exists('mb_substr') ? mb_substr($value, 0, self::MAX_LABEL_LENGTH, 'UTF-8') : substr($value, 0, self::MAX_LABEL_LENGTH);
    }

    public static function icon(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        if (str_starts_with($value, 'ti-')) {
            $value = substr($value, 3);
        }

        return preg_match(self::ICON_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * Gruppen-Einträge auf eine Ebene bringen: verschachtelte Gruppen werden aufgelöst, Trenner verworfen.
     *
     * @param list<string> $warnings
     * @return list<mixed>
     */
    private static function flattenGroupItems(mixed $items, string $groupId, array &$warnings): array
    {
        if (!is_array($items)) {
            return [];
        }

        $flat = [];
        foreach ($items as $child) {
            if (is_array($child) && ($child['type'] ?? 'item') === 'group') {
                $warnings[] = 'Die verschachtelte Gruppe in „' . self::short($groupId) . '“ wurde aufgelöst (höchstens 2 Ebenen).';
                foreach (self::flattenGroupItems($child['items'] ?? [], $groupId, $warnings) as $nested) {
                    $flat[] = $nested;
                }
                continue;
            }
            if (is_array($child) && ($child['type'] ?? 'item') === 'separator') {
                $warnings[] = 'Ein Trenner innerhalb der Gruppe „' . self::short($groupId) . '“ wurde entfernt.';
                continue;
            }
            $flat[] = $child;
        }

        return $flat;
    }

    private static function short(string $value): string
    {
        $value = (string) preg_replace('/[^\p{L}\p{N}_.:\- ]+/u', '', $value);

        return function_exists('mb_substr') ? mb_substr($value, 0, 80, 'UTF-8') : substr($value, 0, 80);
    }

    /**
     * @param array<string, mixed> $layout
     * @param list<string>         $warnings
     * @param list<string>         $errors
     * @return array{valid:bool, layout:array<string, mixed>, warnings:list<string>, errors:list<string>}
     */
    private static function result(bool $valid, array $layout, array $warnings, array $errors): array
    {
        return ['valid' => $valid, 'layout' => $layout, 'warnings' => array_values(array_unique($warnings)), 'errors' => $errors];
    }
}
