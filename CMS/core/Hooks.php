<?php
/**
 * Hooks System - WordPress-like Action/Filter System
 * 
 * Allows plugins to hook into CMS functionality
 * 
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS;

if (!defined('ABSPATH')) {
    exit;
}

class Hooks
{
    private static array $actions = [];
    private static array $filters = [];

    /** @var array<string, int> Anzahl der doAction()-Aufrufe je Tag im aktuellen Request */
    private static array $firedActions = [];

    /**
     * Dokument-Abschluss-Hooks, die pro Request nur einmal ausgeführt werden.
     *
     * Der Core feuert sie in ThemeManager::getFooter() rund um footer.php; viele Themes
     * rufen sie zusätzlich selbst im Footer auf. Ohne diese Sperre würden Scripts,
     * Cookie-Banner und Tracking doppelt ausgegeben.
     */
    private const ONCE_PER_REQUEST_ACTIONS = ['before_footer', 'body_end'];
    
    /**
     * Add action hook
     */
    public static function addAction(string $tag, callable $callback, int $priority = 10): void
    {
        self::$actions[$tag][$priority][] = $callback;
    }
    
    /**
     * Do action hook
     */
    public static function doAction(string $tag, ...$args): void
    {
        if (in_array($tag, self::ONCE_PER_REQUEST_ACTIONS, true) && isset(self::$firedActions[$tag])) {
            return;
        }
        self::$firedActions[$tag] = (self::$firedActions[$tag] ?? 0) + 1;

        if (!isset(self::$actions[$tag])) {
            return;
        }
        
        // Sort by priority
        ksort(self::$actions[$tag]);
        
        foreach (self::$actions[$tag] as $callbacks) {
            foreach ($callbacks as $callback) {
                call_user_func_array($callback, $args);
            }
        }
    }

    /**
     * Wie `doAction()`, aber jeder Callback läuft einzeln abgesichert: wirft einer, wird `$onError($exception, $callback)`
     * aufgerufen (falls gesetzt) und die übrigen Callbacks laufen trotzdem. Zählt wie `doAction()` für `didAction()` und
     * beachtet die einmaligen Dokument-Abschluss-Hooks. Wirft nie (auch nicht, wenn `$onError` wirft).
     *
     * @param (callable(\Throwable, callable): void)|null $onError
     */
    public static function doActionIsolated(string $tag, ?callable $onError, ...$args): void
    {
        if (in_array($tag, self::ONCE_PER_REQUEST_ACTIONS, true) && isset(self::$firedActions[$tag])) {
            return;
        }
        self::$firedActions[$tag] = (self::$firedActions[$tag] ?? 0) + 1;

        foreach (self::getCallbacks($tag, 'action') as $callback) {
            try {
                call_user_func_array($callback, $args);
            } catch (\Throwable $e) {
                if ($onError === null) {
                    continue;
                }
                try {
                    $onError($e, $callback);
                } catch (\Throwable) {
                    // Die Fehlerbehandlung selbst darf die übrigen Callbacks nicht verhindern.
                }
            }
        }
    }

    /**
     * Die registrierten Callbacks eines Tags in Ausführungsreihenfolge (Priorität aufsteigend, innerhalb einer Priorität in
     * der Reihenfolge der Registrierung), für Aufrufer, die eine Kette selbst durchlaufen (einzelne Callbacks absichern,
     * Zwischenergebnisse prüfen). Ändert nichts; `$type` ist `filter` oder `action`, sonst und für unbekannte Tags kommt eine
     * leere Liste.
     *
     * @return list<callable>
     */
    public static function getCallbacks(string $tag, string $type = 'filter'): array
    {
        $registry = match ($type) {
            'filter' => self::$filters[$tag] ?? [],
            'action' => self::$actions[$tag] ?? [],
            default => [],
        };
        ksort($registry);
        $callbacks = [];
        foreach ($registry as $group) {
            foreach ($group as $callback) {
                $callbacks[] = $callback;
            }
        }

        return $callbacks;
    }

    /**
     * Wie oft wurde ein Action-Tag im aktuellen Request bereits ausgelöst?
     */
    public static function didAction(string $tag): int
    {
        return self::$firedActions[$tag] ?? 0;
    }

    /**
     * Prüft, ob für einen Action-Tag bereits Callbacks registriert sind.
     *
     * Wird ein Callback angegeben, erfolgt ein exakter Abgleich auf Prioritätsebene.
     */
    public static function hasAction(string $tag, ?callable $callback = null, ?int $priority = null): bool
    {
        if (!isset(self::$actions[$tag])) {
            return false;
        }

        if ($callback === null) {
            if ($priority === null) {
                return self::$actions[$tag] !== [];
            }

            return !empty(self::$actions[$tag][$priority]);
        }

        if ($priority !== null) {
            if (!isset(self::$actions[$tag][$priority])) {
                return false;
            }

            return in_array($callback, self::$actions[$tag][$priority], true);
        }

        foreach (self::$actions[$tag] as $callbacks) {
            if (in_array($callback, $callbacks, true)) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Add filter hook
     */
    public static function addFilter(string $tag, callable $callback, int $priority = 10): void
    {
        self::$filters[$tag][$priority][] = $callback;
    }
    
    /**
     * Apply filters
     */
    public static function applyFilters(string $tag, $value, ...$args): mixed
    {
        if (!isset(self::$filters[$tag])) {
            return $value;
        }
        
        // Sort by priority
        ksort(self::$filters[$tag]);
        
        foreach (self::$filters[$tag] as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = call_user_func_array($callback, array_merge([$value], $args));
            }
        }
        
        return $value;
    }
    
    /**
     * Remove action
     */
    public static function removeAction(string $tag, callable $callback, int $priority = 10): bool
    {
        if (!isset(self::$actions[$tag][$priority])) {
            return false;
        }
        
        $key = array_search($callback, self::$actions[$tag][$priority], true);
        if ($key !== false) {
            unset(self::$actions[$tag][$priority][$key]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Remove filter
     */
    public static function removeFilter(string $tag, callable $callback, int $priority = 10): bool
    {
        if (!isset(self::$filters[$tag][$priority])) {
            return false;
        }
        
        $key = array_search($callback, self::$filters[$tag][$priority], true);
        if ($key !== false) {
            unset(self::$filters[$tag][$priority][$key]);
            return true;
        }
        
        return false;
    }
}
