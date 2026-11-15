<?php
declare(strict_types=1);

namespace CMS\Http;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Release-Prüfung 4.1.0 (PERF, blocking:3): Gibt die Sperre der Datei-Session für langsame, nur lesende
 * Arbeit frei.
 *
 * PHP sperrt die Session-Datei für die gesamte Laufzeit eines Requests. Ein langsamer Admin-Aufruf
 * (Monitoring, Warnzentrale, Update-Prüfung) blockiert damit alle parallelen Anfragen desselben
 * Benutzers (weitere Tabs, AJAX, Autosave). withoutLock() schreibt die Session vor der Arbeit zurück,
 * führt den Callback ohne Sperre aus und öffnet die Session danach wieder, damit spätere Schreibzugriffe
 * (CSRF-Token in Kopfzeile und Seitenleiste) wie bisher gespeichert werden.
 *
 * Wichtig: Der Callback darf $_SESSION nicht verändern. Solche Änderungen gingen beim Wiederöffnen verloren.
 */
final class SessionLock
{
    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function withoutLock(callable $callback): mixed
    {
        if (!self::release()) {
            return $callback();
        }

        try {
            return $callback();
        } finally {
            self::reacquire();
        }
    }

    /**
     * Schreibt die aktive Session zurück und gibt die Sperre frei. true, wenn sie danach wieder geöffnet
     * werden kann (noch keine Header gesendet).
     */
    public static function release(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
            return false;
        }

        return session_write_close();
    }

    /** Öffnet die zuvor freigegebene Session erneut (gleiche Session-ID, aktueller Inhalt aus dem Speicher). */
    public static function reacquire(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
            return false;
        }

        return @session_start();
    }
}
