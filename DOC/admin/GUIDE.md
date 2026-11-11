# 365CMS – Projektdokumentation | Abschnitt: Admin – Bedienleitfaden

> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

Operator guide for day-to-day work in the 365CMS administration: sign-in, navigation, typical tasks with the relevant routes, safe working rules and troubleshooting. Technical details are in the area documents linked from [README.md](README.md).

## Deutsch

### 1. Anmelden

1. `https://<domain>/cms-login` aufrufen (oder direkt `/admin` – nicht angemeldete Besucher werden zur Anmeldung geleitet und danach zurückgeführt).
2. Benutzername/E-Mail und Passwort eingeben; je nach Konto folgt die Abfrage des Authenticator-Codes (`/mfa-challenge`) oder die Anmeldung per Passkey.
3. Nur Konten mit der Rolle **Administrator** gelangen in den Adminbereich; alle anderen landen im Mitgliederbereich `/member`.

Abmelden über das Benutzermenü im Fußbereich der Seitenleiste (Klick auf den Avatar; `/logout` mit CSRF-Token).

### 2. Orientierung

- Die **Seitenleiste** beginnt mit dem Site-Umschalter (Sites aus *Einstellungen › MultiSites*). Darunter folgt der Menübaum des gewählten **Sidebar-Profils** (*Einstellungen › Admin-Menü*, Details in [ADMIN-MENU.md](ADMIN-MENU.md)):
  - *Klassisch* (Standard): drei Abschnitte, *Arbeit* (Dashboard, Updates, Beiträge, Medien, Seiten, Kommentare), *Gestaltung* (Design, Plugins) und *Verwaltung* (Benutzer, Werkzeuge, Einstellungen, Website-Zustand).
  - *Strukturiert*: aufgabenorientierte Gruppen wie Inhalt, Medien, Redaktion, Struktur, Personen, Konfiguration und Berichte.
  - Ein eigenes Layout lässt sich im Sidebar-Editor zusammenstellen.

  Gruppen optionaler Module (z. B. SEO, KI-Dienste, Mitgliederbereich, Abos) erscheinen nur bei aktivem Modul. Gruppen sind zugeklappt, bis man sie öffnet; die Gruppe der aktiven Seite ist immer offen. *Einstellungen*, *Website-Zustand*, *Konfiguration* und *Berichte* öffnen Kachelseiten. Auf-/Zuklapp-Zustände und die eingeklappte Icon-Leiste (Schalter oben in der Seitenleiste) werden je Benutzer gespeichert. Unter 992 px Breite öffnet das Menü-Symbol in der Kopfzeile die Seitenleiste als Drawer.
- Plugin-Seiten stehen in der Gruppe *Plugins* bzw. *Erweitern*; Plugins mit mehreren Seiten erhalten eine eigene Gruppe direkt danach. Welche Plugins in welcher Site erscheinen, legt *Einstellungen › Plugins › Plugin-Zuweisung* fest ([plugins/PLUGINS.md](plugins/PLUGINS.md#plugin-zuweisung)).
- Ein Punkt am Avatar im Fußbereich zeigt Systemhinweise (fehlgeschlagener Cron-Lauf, Backup älter als 24 Stunden, verfügbare Updates); ein Klick öffnet die Liste.
- Fehlende Menüpunkte bedeuten: Modul deaktiviert (`/admin/modules`), Marketplace ausgeschaltet, der Eintrag ist im eigenen Layout ausgeblendet oder – bei Plugins – das Plugin ist der aktuellen Site nicht zugewiesen.
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
