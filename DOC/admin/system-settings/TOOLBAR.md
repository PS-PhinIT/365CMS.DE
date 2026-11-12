# 365CMS – Admin: Toolbar „Cache & Wartung“

> **Stand:** 2026-11-12 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

Administrators with `manage_system` get a “Cache & maintenance” menu in the admin top bar: clear everything, the
page/object cache, optimised assets or OPcache (warmed up again afterwards), and switch maintenance mode on or off
with a confirmation dialog. While maintenance mode is active, a red banner below the top bar and a badge are shown;
visitors receive a 503 maintenance page.

## Deutsch

Das Menü **Cache & Wartung** steht in der oberen Leiste jeder Admin-Seite (Capability `manage_system`).

| Aktion | Wirkung |
|---|---|
| Alles | alle folgenden Bereiche nacheinander |
| Seiten- und Objekt-Cache | APCu, Datei-Cache unter `cache/`, Cache-Tabelle, RSS-Cache, LiteSpeed-Purge |
| Optimierte Assets (CSS/JS) | minimierte Dateien unter `cache/optimized-assets/`; sie entstehen beim nächsten Aufruf neu |
| OPcache | nur, wenn OPcache aktiv ist: zurücksetzen und die meistgenutzten Dateien neu laden |

Das Ergebnis erscheint als Hinweis unten rechts.

**Wartungsmodus:** Ein- und Ausschalten mit Rückfrage. Solange er aktiv ist, zeigt die Leiste ein rotes Badge
„Wartung“ und darunter einen Hinweis für alle Admins. Besucher erhalten eine Wartungsseite mit HTTP 503 und
`Retry-After`; angemeldete Admins sowie Login- und Admin-Adressen bleiben erreichbar. Der Text der Wartungsseite wird
unter *Einstellungen → Allgemein* gepflegt (dort lässt sich der Modus ebenfalls schalten).

Technik: POST an `/admin/admin-ui` (`action=cache_clear`, `scope=all|pages|assets|opcache` bzw.
`action=maintenance`, `active=0|1`) mit CSRF-Token der Admin-Oberfläche; jede Aktion wird im Audit-Log festgehalten
(`system.cache.clear_toolbar`, `system.maintenance.enabled`/`disabled`).
