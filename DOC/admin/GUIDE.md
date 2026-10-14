# 365CMS – Projektdokumentation | Abschnitt: Admin – Bedienleitfaden

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

Operator guide for day-to-day work in the 365CMS administration: sign-in, navigation, typical tasks with the relevant routes, safe working rules and troubleshooting. Technical details are in the area documents linked from [README.md](README.md).

## Deutsch

### 1. Anmelden

1. `https://<domain>/cms-login` aufrufen (oder direkt `/admin` – nicht angemeldete Besucher werden zur Anmeldung geleitet und danach zurückgeführt).
2. Benutzername/E-Mail und Passwort eingeben; je nach Konto folgt die Abfrage des Authenticator-Codes (`/mfa-challenge`) oder die Anmeldung per Passkey.
3. Nur Konten mit der Rolle **Administrator** gelangen in den Adminbereich; alle anderen landen im Mitgliederbereich `/member`.

Abmelden über das Benutzermenü oben rechts oder in der Sidebar (sichere POST-Abmeldung).

### 2. Orientierung

- Die **Sidebar** ist nach Abschnitten gegliedert (Kernsystem, Inhalte, Benutzer, Marketing & Gestaltung, Sicherheit & Protokolle, System, Plugin-Erweiterungen).
- Fehlende Menüpunkte bedeuten: Modul deaktiviert (`/admin/modules`) oder Marketplace ausgeschaltet.
- Das **Dashboard** zeigt offene Aufgaben unter „Nächste Aufmerksamkeit“ und lässt sich je Administrator personalisieren (sichtbare Bereiche, Widgets, Favoriten).

### 3. Häufige Aufgaben

| Aufgabe | Wo |
|---|---|
| Beitrag schreiben, planen, veröffentlichen | `/admin/posts` → „Neuer Beitrag“ ([pages-posts/POSTS.md](pages-posts/POSTS.md)) |
| Seite anlegen / Englische Fassung erstellen | `/admin/pages` → Editor, Umschalter DE/EN ([pages-posts/PAGES.md](pages-posts/PAGES.md)) |
| Bilder hochladen, ersetzen, Alt-Texte pflegen | `/admin/media` ([media/MEDIA.md](media/MEDIA.md)) |
| Kommentare freigeben | `/admin/comments` |
| Menü ändern | `/admin/menu-editor` |
| Farben/Logo/Startseite des Themes | `/admin/theme-editor` |
| Weiterleitung anlegen, 404-Fehler beheben | `/admin/redirect-manager`, `/admin/not-found-monitor` |
| Benutzer anlegen/sperren | `/admin/users` |
| Impressum/Datenschutz erzeugen | `/admin/legal-sites` |
| DSGVO-Anfragen bearbeiten | `/admin/data-requests` |
| Backup erstellen | `/admin/backups` |
| Updates einspielen | `/admin/updates` |
| Fehler untersuchen | `/admin/logs`, `/admin/info`, `/admin/monitor-health-check` |

### 4. Sicher arbeiten

1. **Vor Änderungen** Warnungen und aktuelle Werte prüfen.
2. **Vor riskanten Aktionen** (Löschen, Sammelaktionen, Restore, Updates, Site-URL-Migration) ein Backup erstellen.
3. Formulare nur über die Oberfläche absenden; nach dem Speichern die Erfolgsmeldung und das Ergebnis kontrollieren.
4. Nach sicherheitsrelevanten Änderungen das Sicherheits-Audit (`/admin/security-audit`) ausführen.
5. Zugangsdaten (Mail, KI-Provider, LDAP) nur in die dafür vorgesehenen Felder bzw. `config/app.php` eintragen – nie in Inhalte, Kommentare oder Prompts.

### 5. Fehlerbilder

| Meldung | Bedeutung / Lösung |
|---|---|
| „Sicherheitstoken ungültig.“ | Seite war zu lange offen oder wurde in einem anderen Tab erneut geladen → Seite neu laden, erneut speichern |
| „Die Admin-Sektion konnte nicht geladen werden …“ | Datei fehlt oder Cache veraltet → Deployment prüfen, OPcache leeren (`/admin/performance-cache`) |
| Fehlerkarte auf Plugin-Seite | Exception im Plugin → `/admin/logs/php-errors` prüfen, Plugin aktualisieren/deaktivieren |
| Menüpunkt fehlt | Modul deaktiviert oder fehlende Capability |
| „Fehler melden“-Button | erstellt einen Fehlerbericht unter `/admin/diagnose` |

### Verwandte Dokumente

[README.md](README.md) · [PRUEF-CHECKLISTE.md](PRUEF-CHECKLISTE.md) · [../workflow/CONTENT-MANAGEMENT-WORKFLOW.md](../workflow/CONTENT-MANAGEMENT-WORKFLOW.md)
