# 365CMS – Admin-Prüf-Checkliste
> **Stand:** 2026-05-10 | **Basis:** Laufzeit-Sidebar + Admin-Fachdoku | **Status:** Arbeitsdokument für Audit, Abnahme und Ausbau

## Zweck

Diese Checkliste dient als zentrale Arbeitsgrundlage, um den kompletten Adminbereich von 365CMS **systematisch, menüpunktweise und nachvollziehbar** zu prüfen.

Sie deckt ab:

- **Hauptbereiche** der Sidebar
- **Unterbereiche / Untermenüpunkte**
- **Kernfunktionen** je Unterbereich
- **wichtige Abhängigkeiten** zu anderen Modulen und Services
- **konkrete Prüfpunkte** für Audit, QA und Abnahme
- **Must-haves** als verbindliche Anforderungen
- **Nice-to-haves** als sinnvolle Ausbauoptionen

Die Sidebar in `CMS/admin/partials/sidebar.php` ist für die Menüstruktur führend. Für Detailfragen bleiben die Fachdokumente unter `CMS/DOC/admin/` maßgeblich.

---

## Empfohlene Abarbeitung

1. Zuerst die **globale Pflichtprüfung** einmal komplett durchgehen.
2. Danach die Hauptbereiche **von oben nach unten wie in der Sidebar** prüfen.
3. Pro Unterbereich immer dokumentieren:
   - Status
   - Fehlerbild
   - Reproduktionsweg
   - Abhängige Bereiche
   - offene Must-haves
   - optionale Nice-to-haves
4. Bei Schreibfunktionen immer zusätzlich prüfen:
   - CSRF
   - PRG-Redirect
   - Flash-/Session-Feedback
   - Logging/Audit-Trail
5. Bei Cross-Bereich-Funktionen immer Gegenprobe im Frontend oder im Zielbereich machen.

---

## Globale Pflichtprüfung für **alle** Admin-Seiten

### Architektur & Routing

- [ ] Route ist über die Sidebar erreichbar oder bewusst nur intern verlinkt.
- [ ] Entry-Point unter `CMS/admin/*.php` existiert und ist eindeutig zuständig.
- [ ] Fachlogik liegt im passenden Modul unter `CMS/admin/modules/`.
- [ ] Views werden über Wrapper/Module gerendert, nicht direkt aufrufbar.
- [ ] Legacy-Routen leiten korrekt um oder sind sauber stillgelegt.
- [ ] Query-Tab-Seiten wie `/admin/media?tab=...` oder `/admin/settings?tab=content` funktionieren stabil.

### Sicherheit

- [ ] `ABSPATH`-Guard vorhanden.
- [ ] Admin-Zugriff wird geprüft.
- [ ] Capability-/RBAC-Prüfung ist vorhanden, wo relevant.
- [ ] CSRF-Token wird bei jeder schreibenden Aktion geprüft.
- [ ] PRG-Pattern wird verwendet.
- [ ] Ausgaben sind escaped.
- [ ] Dateioperationen und Uploads sind serverseitig validiert.
- [ ] Kritische Änderungen werden geloggt oder im Audit-Trail festgehalten.

### Daten & Persistenz

- [ ] Prepared Statements statt SQL-String-Interpolation.
- [ ] Tabellenpräfix wird zentral bezogen.
- [ ] Fehlermeldungen sind admin-tauglich und leaken keine sensiblen Interna.
- [ ] Bulk-Aktionen sind transaktionssicher oder mindestens fail-soft umgesetzt.
- [ ] Erfolgreiche Teiloperationen erzeugen keinen nackten HTTP-500.

### UX & Betrieb

- [ ] Erfolgs-/Fehlhinweise sind verständlich.
- [ ] Leere Zustände sind sauber gelöst.
- [ ] Buttons, Tabs, Filter und Pagination funktionieren.
- [ ] Mobile/kleine Viewports brechen die Bedienung nicht.
- [ ] Lange Prozesse sind gegen Doppel-Submit geschützt.

### Dokumentation

- [ ] Route und Funktionsumfang sind in `CMS/DOC/admin/` dokumentiert.
- [ ] Besonderheiten, Legacy-Verträge und Abhängigkeiten sind dokumentiert.
- [ ] Neue Funktionen werden in der passenden Bereichsdoku ergänzt.

---

## Menü-Masterliste

| Hauptbereich | Unterbereiche |
|---|---|
| Dashboard | Dashboard |
| AI Services | AI Dashboard, Übersetzung, Content Creator, SEO Creator, Einstellungen |
| Seiten & Beiträge | Seiten, Beiträge, Kategorien, Tags, Kommentare, Inhaltsverzeichnis, Hub-Sites, Tabellen, Einstellungen |
| Medienverwaltung | Medien, Beitrags & Site Medien, Kategorien, Einstellungen |
| Benutzer & Gruppen | Benutzer, Gruppen, Rollen & Rechte, Einstellungen |
| Member Dashboard | Übersicht, Allgemein, Design & Farben, Frontend-Module, Dashboard Widgets, Plugin-Widgets, Profil-Felder, Benachrichtigungen, Mitglieder-Onboarding |
| Aboverwaltung | Pakete & Abo-Einstellungen, Bestellungen & Zuweisung, Einstellungen |
| Themes & Design | Theme-Verwaltung, Theme-Editor, Theme-Explorer, Theme-Menü, Landing Page, Font Manager, CMS Loginpage, Theme-Marketplace |
| SEO | SEO Dashboard, Analytics, SEO Audit, Meta-Daten, Social Media, Strukturierte Daten, Sitemap & robots.txt, Technisches SEO, Weiterleitungen, 404-Monitor |
| Performance | Übersicht, Cache-Verwaltung, Medien-Optimierung, Datenbank-Wartung, Performance-Einstellungen, Session-Verwaltung |
| Recht | Legal Sites, Cookie-Manager, Auskunft & Löschen |
| Sicherheit | AntiSpam, Firewall, Audit |
| Plugins | Plugins verwalten, Marketplace, dynamische Plugin-Submenüs |
| System & Doku | Einstellungen, Mail & Azure OAuth2, Module, Backup & Restore, Updates, Dokumentation |
| Diagnose | Übersicht, Datenbank, Assets, Response-Time, Cron-Job Status, Disk-Usage, Scheduled Tasks, Health-Check, E-Mail-Benachrichtigungen, Logs & Protokolle |

---

## 1. Dashboard

### Unterbereiche

| Unterbereich | Route | Kernfunktion |
|---|---|---|
| Dashboard | `/admin` | Gesamtüberblick, KPIs, Schnellzugriffe, Statuskarten |

### Wichtige Abhängigkeiten

- Audit- und Aktivitätsdaten
- Monitoring-/Statusquellen aus Diagnose, Performance, Updates, Security
- Schnellzugriffe auf Kernmodule

### Prüfen

- [x] Dashboard lädt ohne statische PHP-/JS-Fehler.
- [x] KPI-Karten zeigen auf definierte Admin-Ziele und fail-soft Fallback-Werte.
- [x] Schnellzugriffe führen auf die korrekten Routen.
- [x] Widgets brechen bei Teilfehlern nicht die gesamte Seite.
- [x] Rollen sehen nur den admin-geschützten Dashboard-Einstieg.

### Must-haves

- [x] Fehlerisolierung pro Widget/Kachel.
- [x] RBAC-gesteuerte Sichtbarkeit sensibler Kennzahlen.
- [x] Audit-/Statusdaten ohne Full-Page-Fatal.

### Nice-to-haves

- [x] Personalisierbare Widgets/Bereiche.
- [x] Kontextuelle Warnungen mit Deep-Links in Problemseiten.
- [x] Gespeicherte Favoriten / zuletzt genutzt.
- [x] Drag-&-Drop-Sortierung mit Persistenz.
- [x] Rollenbasierte Dashboard-Vorlagen.

### Audit-Stand – Dashboard · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.615`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Route:** `/admin`
- **Reproduziertes Fehlerbild:** Fiel eine einzelne Statistikquelle oder Tabelle im Dashboard-Stack aus, konnte `DashboardService::getAllStats()` den kompletten Dashboard-Request statt nur den betroffenen Block abreißen.
- **Umsetzung in diesem Durchlauf:** Dashboard-Statistiken werden jetzt segmentweise mit Fallback-Daten geladen; degradierte Bereiche erzeugen einen verständlichen Warnhinweis mit Deep-Link auf `CMS Logs`, statt in einem Full-Page-Fatal zu enden.
- **Abhängige Bereiche:** Diagnose, CMS Logs, Security Audit, Bestellungen, Sessions, Medien, Content-Statistiken
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** initial personalisierbare Widgets, Favoriten/Zuletzt genutzt und kontextuelle Warnungen; in Folge-Releases `2.9.701`, `2.9.716` und `2.9.717` schrittweise umgesetzt.
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/dashboard/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md`

### Audit-Stand – Dashboard Nice-to-haves · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.701`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Route:** `/admin`
- **Umgesetztes Nice-to-have:** Personalisierbare Dashboard-Bereiche und bereits vorhandene kontextuelle Warnungen mit Deep-Links final als erfüllt markiert.
- **Umsetzung in diesem Durchlauf:** `/admin` speichert sichtbare Dashboard-Bereiche jetzt pro Admin-Benutzer über eine CSRF-geschützte POST-Aktion. Die Backend-Logik normalisiert eingereichte Bereichsschlüssel gegen eine feste Allowlist, erzwingt Pflichtbereiche, speichert die Auswahl nicht-autoloadend in `settings` und schreibt einen Audit-Eintrag. Kritische Alerts bleiben unabhängig von der persönlichen Ansicht sichtbar.
- **Abhängige Bereiche:** `DashboardModule`, `DashboardService`, `section-page-shell.php`, `settings`, `audit_log`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Drag-&-Drop-Sortierung, rollenbasierte Dashboard-Vorlagen, gespeicherte Favoriten/Zuletzt genutzt
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/dashboard/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Dashboard Nice-to-haves · Durchlauf 4

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.716`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Route:** `/admin`
- **Umgesetztes Nice-to-have:** Personalisierbare Widgets innerhalb der zentralen Arbeitsübersicht.
- **Umsetzung in diesem Durchlauf:** Die Dashboard-Personalisierung speichert nicht mehr nur sichtbare Bereiche, sondern zusätzlich einzeln aktivierbare Widgets der „Zentralen Arbeitsübersicht“. Die Serverlogik arbeitet allowlist-basiert mit `visible_work_overview_widgets`, hält die Hauptsektion selbst als Pflichtbereich sichtbar und rendert zusätzliche Arbeitskarten für Nutzerwachstum, Redaktions-Pipeline, Kommentar-Moderation, aktive Sessions, Security Snapshot und System-Stack.
- **Best-Practice-Bezug:** Die Persistenz bleibt CSRF-geschützt, auditierbar und fail-closed auf bekannte Schlüssel beschränkt. Dadurch erweitert die Personalisierung die UI-Granularität, ohne Berechtigungen, Pflichtbereiche oder Warnlogik aufzubrechen.
- **Abhängige Bereiche:** `DashboardModule`, `DashboardService`, `views/dashboard/index.php`, `settings`, `audit_log`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Drag-&-Drop-Sortierung, rollenbasierte Dashboard-Vorlagen, gespeicherte Favoriten/Zuletzt genutzt
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/dashboard/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Dashboard Nice-to-haves · Durchlauf 5

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.717`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Routen:** `/admin` sowie adminweite Sidebar-Navigation
- **Umgesetztes Nice-to-have:** Gespeicherte Favoriten / zuletzt genutzt.
- **Umsetzung in diesem Durchlauf:** Das Dashboard bietet jetzt einen optionalen Bereich „Favoriten & zuletzt genutzt“. Favoriten-Schnellzugriffe werden pro Admin-Benutzer serverseitig im bestehenden Dashboard-Preference-Payload gespeichert und gegen eine feste Shortcut-Allowlist normalisiert. Zusätzlich schreibt die gemeinsame Admin-Sidebar eine kleine, nicht-sensitive Verlaufsliste zuletzt genutzter Admin-Ziele in `localStorage`; das Dashboard rendert diese Liste nur bei verfügbarer Browser-Persistenz und fällt sonst sauber auf einen leeren Zustand zurück.
- **Best-Practice-Bezug:** Die lokale Verlaufsliste speichert nur relative interne Admin-Ziele und Labels, entfernt flüchtige Query-Parameter wie Tokens/Flash-Werte und nutzt Web-Storage-Feature-Detection mit Fallback gemäß MDN, damit blockierte oder deaktivierte Browser-Persistenz nicht zu Fehlern im Admin führt.
- **Abhängige Bereiche:** `DashboardModule`, `views/dashboard/index.php`, `admin/partials/sidebar.php`, `settings`, Browser-Storage im Admin
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Drag-&-Drop-Sortierung, rollenbasierte Dashboard-Vorlagen
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/dashboard/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Dashboard Nice-to-haves · Durchlauf 6

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.718`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Route:** `/admin`
- **Umgesetztes Nice-to-have:** Drag-&-Drop-Sortierung mit Persistenz.
- **Umsetzung in diesem Durchlauf:** Die Dashboard-Personalisierung speichert jetzt neben Sichtbarkeit auch die Reihenfolge der Arbeits-Widgets und Favoriten. Die UI nutzt ein dediziertes Dashboard-Asset für Sortierung und Recent-Rendering; per Drag-&-Drop wird die visuelle Reihenfolge angepasst und über Hidden-Inputs in den CSRF-geschützten Save-Flow gegeben. Serverseitig werden die übermittelten Orders allowlist-basiert normalisiert und fehlende bekannte Keys kontrolliert ergänzt, sodass gespeicherte Reihenfolgen auch bei deaktivierten Optionen oder modulabhängigen Widgets stabil bleiben.
- **Best-Practice-Bezug:** Die Sortierung ist progressiv erweitert: Drag-&-Drop dient als Komfortpfad, Auf/Ab-Buttons bleiben als robuster Fallback verfügbar. Dadurch hängt die Bedienbarkeit nicht allein an Browser-DnD, während die Persistenz weiterhin vollständig serverseitig validiert und auditierbar bleibt.
- **Abhängige Bereiche:** `DashboardModule`, `views/dashboard/index.php`, `assets/js/admin-dashboard.js`, `settings`, `audit_log`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** rollenbasierte Dashboard-Vorlagen
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/dashboard/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Dashboard Nice-to-haves Nachhärtung · Durchlauf 7

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.719`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Routen:** `/admin` sowie adminweite Sidebar-Navigation
- **Umgesetzter Nachhärtungsumfang:** Favoriten / zuletzt genutzt / Drag-&-Drop-Sortierung gegen beschädigte Browserdaten, unnötiges Inline-CSS und unstete Drop-Zustände nachgeprüft und gehärtet.
- **Umsetzung in diesem Durchlauf:** Die browserlokale Verlaufsliste der Admin-Sidebar und des Dashboards bereinigt gespeicherte Recent-Einträge jetzt beim Lesen und Schreiben nochmals auf gültige interne Admin-Ziele, entfernt Dubletten, begrenzt URL-/Label-Längen und hält den Verlauf klein. Zusätzlich räumt das Dashboard-Sortier-JavaScript Drop-Markierungen robuster auf, und die Dashboard-Styles werden als cachebares Seiten-Asset statt inline aus der View geladen.
- **Best-Practice-Bezug:** Die Nachhärtung folgt den MDN-Empfehlungen zu Web Storage Availability/Graceful Degradation und hält den DnD-Pfad progressiv: Persistenz bleibt fail-soft, nicht-sensitive Browserdaten werden defensiv klein und sauber gehalten, und das Layout profitiert von wiederverwendbarem Asset-Caching statt zusätzlichem Inline-CSS.
- **Abhängige Bereiche:** `admin/partials/sidebar.php`, `assets/js/admin-dashboard.js`, `assets/css/admin-dashboard.css`, `views/dashboard/index.php`, `admin/index.php`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** rollenbasierte Dashboard-Vorlagen
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/dashboard/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Dashboard Nice-to-haves · Durchlauf 8

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.720`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Route:** `/admin`
- **Umgesetztes Nice-to-have:** Rollenbasierte Dashboard-Vorlagen.
- **Umsetzung in diesem Durchlauf:** Das Dashboard löst seinen bisherigen Einheits-Default durch rollenbasierte Standardvorlagen ab. Neue oder zurückgesetzte persönliche Ansichten übernehmen pro Rolle bzw. capability-basierter Rollenfamilie (`admin`, `editor`, `author`, `member`) definierte Defaults für sichtbare Bereiche, aktive Arbeits-Widgets, Favoriten und deren Reihenfolge. Gespeicherte Benutzeranpassungen bleiben davon getrennt und können über einen CSRF-geschützten Reset gezielt auf die Rollen-Vorlage zurückgesetzt werden, statt die Vorlage global zu überschreiben.
- **Best-Practice-Bezug:** Die Umsetzung kombiniert sinnvolle Author-Defaults mit expliziter Benutzerkontrolle. Die Vorlagen arbeiten weiterhin allowlist-basiert, überschreiben bestehende persönliche Präferenzen nicht still und bieten einen klaren „Reset to default“-Pfad analog zu etablierten Dashboard-/Bookmark-Konzepten. Damit bleibt der Admin sowohl personalisierbar als auch reproduzierbar.
- **Abhängige Bereiche:** `DashboardModule`, `views/dashboard/index.php`, `settings`, `audit_log`, Rollen-/Capability-Matrix aus `Auth` bzw. `roles.php`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** keine im Dashboard-Bereich
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/dashboard/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Dashboard Nachprüfung CSRF · Durchlauf 2

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.705`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Route:** `/admin`
- **Reproduziertes Fehlerbild:** Beim Speichern von „Dashboard personalisieren“ konnten Admins mit länger geöffneten oder parallel gerenderten Formularen in eine CSRF-Fehlermeldung laufen, obwohl der Formularvertrag grundsätzlich korrekt war.
- **Umsetzung in diesem Durchlauf:** `CMS\Security` speichert pro CSRF-Action jetzt eine begrenzte Token-Historie innerhalb des TTL-Fensters. Erfolgreich verwendete Tokens werden weiterhin invalidiert, aber andere parallel gültige Formular-Tokens bleiben nutzbar. Dadurch wird der Admin-PRG-Flow robuster gegen Mehrtab-/Back-Button-Szenarien, ohne CSRF-Schutz oder One-Time-Verbrauch aufzugeben.
- **Abhängige Bereiche:** `Security`, `section-page-shell.php`, Dashboard-Personalisierung, alle Admin-Formulare mit `generateToken()`/`verifyToken()`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** keine aus diesem Fix-Durchlauf
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/dashboard/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Dashboard Nice-to-haves Nachprüfung · Durchlauf 3

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.707`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Route:** `/admin`
- **Reproduziertes Risiko:** Quicklinks und Deep-Links im Dashboard wurden in der View bislang nur getrimmt; bei tainteten oder beschädigten Zielwerten hätte der Linkpfad unnötig roh übernommen werden können.
- **Umsetzung in diesem Durchlauf:** Dashboard-Links akzeptieren jetzt nur noch interne Pfade mit führendem `/`; Protokoll-spezifische, schemalose oder Steuerzeichen-haltige Zielwerte fallen fail-closed auf ein internes Standardziel zurück.
- **Abhängige Bereiche:** `DashboardModule`, `views/dashboard/index.php`, Quicklinks, Alerts, Highlight-Karten
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Drag-&-Drop-Sortierung, rollenbasierte Dashboard-Vorlagen, gespeicherte Favoriten/Zuletzt genutzt
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

---

## 2. AI Services

### Unterbereiche

| Unterbereich | Route | Kernfunktion |
|---|---|---|
| AI Dashboard | `/admin/ai-services` | Überblick über KI-Dienste, Nutzung, Status und jüngste Läufe |
| Übersetzung | `/admin/ai-translation` | KI-gestützte Übersetzung von Inhalten |
| Content Creator | `/admin/ai-content-creator` | KI-gestützte Erstellung von Texten |
| SEO Creator | `/admin/ai-seo-creator` | KI-Hilfen für Meta-Daten und SEO-Texte |
| Einstellungen | `/admin/ai-settings` | API-Schlüssel, Modelle, Limits, Provider |

### Wichtige Abhängigkeiten

- API-Konfigurationen und Secrets
- Content-/SEO-Module
- Lokalisierung / DE-EN-Workflows
- Protokollierung externer API-Fehler

### Prüfen

- [x] Fehlende oder ungültige API-Keys erzeugen verständliche Admin-Fehler.
- [x] Schreibaktionen sind CSRF-geschützt.
- [x] Übersetzungs-/Generierungsjobs überschreiben bestehende Inhalte nicht ungefragt.
- [x] Token-/Nutzungsgrenzen werden sauber kommuniziert.
- [x] Provider-/Modellwechsel wird korrekt gespeichert.

### Must-haves

- [x] Secrets niemals unmaskiert im UI ausgeben.
- [x] Rate-Limits, Timeouts und Fehlerpfade fail-soft behandeln.
- [x] Generierte Inhalte nur nach expliziter Bestätigung übernehmen.

### Nice-to-haves

- [x] Prompt-/Vorlagenverwaltung je Bereich.
- [x] Verlauf / Historie je Generierung.
- [x] Kosten- oder Token-Monitoring im Dashboard.

### Audit-Stand – AI Services · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.616`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/ai-services`, `/admin/ai-translation`, `/admin/ai-content-creator`, `/admin/ai-seo-creator`, `/admin/ai-settings`, `/admin/ai-translate-editorjs`
- **Reproduziertes Fehlerbild:** Die Translation-Konfiguration bot einen abschaltbaren Preview-Schalter an, obwohl der Bereich laut Prüfkriterien generierte Inhalte nur nach expliziter Bestätigung übernehmen darf. Dadurch konnte der Vertrag „Review vor Übernahme“ im Settings-UI dekorativ aufgeweicht werden.
- **Umsetzung in diesem Durchlauf:** Der Review-/Preview-Schritt wird jetzt beim Laden und Speichern der Translation-Konfiguration serverseitig erzwungen; die Admin-UI dokumentiert den Schritt als festen Sicherheitsvertrag statt als frei abschaltbaren Toggle.
- **Abhängige Bereiche:** Editor.js-Übersetzung, Provider-Gateway, Logging/Audit, Quotas, Rechteprüfung im AI-Hauptbereich
- **Offene Must-haves:** keine
- **Offene Nice-to-haves zu diesem Zeitpunkt:** Prompt-Vorlagen, Verlauf/Historie, Kosten-/Token-Monitoring; in den nachfolgenden Nice-to-have-Durchläufen `2.9.702` und `2.9.703` umgesetzt bzw. eingegrenzt.
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/system-settings/AI-SERVICES.md`, `CMS/DOC/ai/AI-SERVICES.md`

### Audit-Stand – AI Services Nice-to-haves · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.702`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Route:** `/admin/ai-services`
- **Umgesetzte Nice-to-haves:** Verlauf/Historie je Generierung sowie Dashboard-Monitoring für request-/quota-nahe Nutzung.
- **Umsetzung in diesem Durchlauf:** Das AI-Dashboard aggregiert jetzt die letzten protokollierten `ai.editorjs.translate.processed`-/`failed`-Läufe aus `audit_log`, zeigt Erfolgsquote, letzte Läufe, Provider-Auslastung sowie Tages-/Monatskontingente an und nutzt dafür bewusst nur datensparsame Metadaten wie Provider, Ziel-Locale, Laufzeit, Block- und Zeichenanzahl. Rohprompts, Volltexte und Secrets werden weder gespeichert noch im Dashboard ausgegeben. Zusätzlich schreibt die Editor.js-Translation bei aktivierten Request-Metriken Zeichen- und Blockzahlen in den Audit-Kontext, damit Verlauf und Budgetanzeigen belastbar bleiben.
- **Abhängige Bereiche:** `AiServicesModule`, `AiEditorJsTranslationModule`, `AiProviderGateway`, `audit_log`, AI-Settings `ai.logging` und `ai.quotas`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** exakte providerübergreifende Token-/Kostenintegration bei künftig konsistenten Usage-Rückgaben
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/system-settings/AI-SERVICES.md`, `CMS/DOC/ai/AI-SERVICES.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – AI Services Nice-to-haves · Durchlauf 2

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.703`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/ai-translation`, `/admin/ai-content-creator`, `/admin/ai-seo-creator`
- **Umgesetztes Nice-to-have:** Prompt-/Vorlagenverwaltung je Bereich.
- **Umsetzung in diesem Durchlauf:** `ai.prompts` ergänzt die AI-Settings um je eine verwaltbare Vorlage für Übersetzung, Content Creator und SEO Creator. Die Translation-Vorlage wird in der Editor.js-Live-Pipeline an die Prompting-Provider übergeben; serverseitige Pflicht-Leitplanken gegen Prompt Injection, Systemprompt-Leaks und Secret-Offenlegung bleiben unabhängig von Admin-Eingaben aktiv. Content- und SEO-Vorlagen sind als geprüfte Briefing-/Leitplankenbasis für kommende Generatoren vorbereitet.
- **Abhängige Bereiche:** `AiSettingsService`, `AiProviderGateway`, `EditorJsTranslationPipeline`, `AbstractPromptingAiProvider`, `AiServicesModule`, AI-Routen-POST-Vertrag
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** exakte providerübergreifende Token-/Kostenintegration bei künftig konsistenten Usage-Rückgaben
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/system-settings/AI-SERVICES.md`, `CMS/DOC/admin/system-settings/README.md`, `CMS/DOC/ai/AI-SERVICES.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – AI Services Nachprüfung Runtime · Durchlauf 3

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.705`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/ai-services`, `/admin/ai-translation`, `/admin/ai-content-creator`, `/admin/ai-seo-creator`, `/admin/ai-settings`
- **Reproduziertes Fehlerbild:** Alle AI-Service-Seiten konnten als generischer Serverfehler enden, weil `AiServicesModule` bei der Initialisierung `Database::getInstance()` statt der vorhandenen Core-API `Database::instance()` aufrief.
- **Umsetzung in diesem Durchlauf:** Das Modul nutzt die korrekte Datenbank-Singleton-API. Zusätzlich bleibt die AI-UI bei Initialisierungsfehlern fail-soft renderbar: Konfigurationsdaten fallen auf sichere Defaults zurück, Schreibaktionen liefern admin-taugliche Fehlermeldungen, und technische Details werden datensparsam im `admin.ai-services`-Logkanal protokolliert statt an die UI geleakt.
- **Abhängige Bereiche:** `AiServicesModule`, `AiSettingsService`, `Database`, `Logger`, `section-page-shell.php`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** exakte providerübergreifende Token-/Kostenintegration bei künftig konsistenten Usage-Rückgaben
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/system-settings/AI-SERVICES.md`, `CMS/DOC/ai/AI-SERVICES.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – AI Services Nice-to-haves Nachprüfung · Durchlauf 4

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.707`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/ai-services`, `/admin/ai-settings`
- **Reproduziertes Risiko:** Nach Provider-Änderungen konnte `active_provider_id` bei verbleibenden Einträgen leer werden; zusätzlich waren Secret-Felder unnötig offen für Browser-Autofill und Komfortfunktionen.
- **Umsetzung in diesem Durchlauf:** Die Provider-Verwaltung stellt jetzt nach Speichern und Löschen automatisch wieder eine gültige aktive Auswahl her, solange mindestens ein Provider vorhanden ist. Secret-Felder signalisieren Browsern gleichzeitig per `autocomplete="new-password"` und deaktivierten Eingabehilfen, dass keine fremden Zugangsdaten eingefüllt werden sollen.
- **Abhängige Bereiche:** `AiServicesModule`, `AiSettingsService`, `views/system/ai-services.php`, Provider-Meta `ai.providers`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** exakte providerübergreifende Token-/Kostenintegration bei künftig konsistenten Usage-Rückgaben
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/system-settings/AI-SERVICES.md`, `CMS/DOC/ai/AI-SERVICES.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

---

## 3. Seiten & Beiträge

### Unterbereiche

| Unterbereich | Route | Kernfunktion |
|---|---|---|
| Seiten | `/admin/pages` | Seiten anlegen, bearbeiten, lokalisieren, publizieren |
| Beiträge | `/admin/posts` | Blog-/News-Beiträge anlegen, bearbeiten, publizieren |
| Kategorien | `/admin/post-categories` | Beitragskategorien verwalten |
| Tags | `/admin/post-tags` | Tag-Verwaltung für Beiträge |
| Kommentare | `/admin/comments` | Kommentare moderieren |
| Inhaltsverzeichnis | `/admin/table-of-contents` | TOC-Logik und Überschriftsverhalten |
| Hub-Sites | `/admin/hub-sites` | zentrale thematische Hub-Seiten |
| Tabellen | `/admin/site-tables` | pflegbare Datentabellen für Inhalte |
| Einstellungen | `/admin/settings?tab=content` | globale Inhalts-/Editor-/Publikations-Einstellungen |

### Wichtige Abhängigkeiten

- Medienverwaltung für Featured Images und Inhaltsmedien
- SEO-Konfiguration und SEO-Karten im Editor
- Lokalisierung / EN-Präfix-Routing
- Kategorien/Tags und Blog-Routing
- Redirects bei Slug-Änderungen
- Comments-Service, Tabellen-Renderer, Hub-Site-Routing

### Spezialprüfungen je Unterbereich

- **Seiten**
  - [x] DE-/EN-Bearbeitung funktioniert stabil.
  - [x] Slug-Änderungen erzeugen korrekte lokalisierte Redirects mit `/en/<slug>`.
  - [x] Featured-Image-Speichern erzeugt keinen 500 bei erfolgreicher Übernahme.
- **Beiträge**
  - [x] Nur eine primäre Kategorie ist wählbar.
  - [x] Tags sind mehrfach pflegbar.
  - [x] Featured-Image-Speichern bleibt fail-soft.
- **Kategorien / Tags**
  - [x] Blog-Filter funktionieren weiter über Query-Parameter.
  - [x] Zähler und Zuordnungen basieren auf Relationstabellen.
- **Kommentare**
  - [x] Kommentare referenzieren veröffentlichte Beiträge korrekt.
  - [x] Moderationsaktionen sind nachvollziehbar und CSRF-geschützt.
- **Inhaltsverzeichnis**
  - [x] TOC-Konfiguration wirkt im Frontend.
- **Hub-Sites**
  - [x] Reservierte Public-Routen können nicht als Hub-Slug gespeichert werden.
- **Tabellen**
  - [x] Editor-Toggles wirken auch im öffentlichen Renderer.
  - [x] Exporte bleiben auf unterstützte Formate begrenzt.
- **Einstellungen**
  - [x] Content-Settings liegen tatsächlich unter `/admin/settings?tab=content`.

### Bereichsweite Prüfpunkte

- [x] CRUD für Seiten und Beiträge funktioniert inkl. Entwurf/Veröffentlichung.
- [x] PRG-Flow und Flash-Meldungen funktionieren.
- [x] Editor-Inhalte, Uploads und Featured Images bleiben konsistent.
- [x] SEO-Felder werden korrekt gespeichert.
- [x] Slug-/Permalink-Änderungen erzeugen keine kaputten Links.
- [x] Frontend-Gegenprobe für Seite, Beitrag, Kategorie, Tag und Kommentar durchführen.

### Must-haves

- [x] Fail-soft Save-Flow für temporäre Medienverschiebung.
- [x] Lokalisierte Redirects über Präfix-Locale statt Suffix-Altpfad.
- [x] Query-basierte Kategorie-/Tag-Navigation bleibt kompatibel.
- [x] Reservierte Slugs für Hub-Sites serverseitig sperren.
- [x] Tabellen-Frontend respektiert Editor-Schalter.

### Nice-to-haves

- [x] Revisionen / Vergleich / Diff für Seiten.
- [x] Revisionen / Vergleich / Diff für Beiträge.
- [x] Bulk-Aktionen für Kategorien/Tags.
- [x] Kommentarmoderation mit Schnellfiltern und Massenaktionen.
- [x] Inhaltsqualitätsprüfungen direkt im Editor.

### Audit-Stand – Seiten & Beiträge · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.617`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/pages`, `/admin/posts`, `/admin/comments`, `/admin/table-of-contents`, `/admin/hub-sites`, `/admin/site-tables`, `/admin/settings?tab=content`
- **Reproduziertes Fehlerbild:** Nach Slug-Änderungen von Kategorien oder Tags blieben alte Blog-Filterwerte wie `/blog?category=alter-slug` bzw. `/blog?tag=alter-slug` ohne Auflösung auf den neuen Slug zurück und konnten im Public-Flow in 404 enden.
- **Umsetzung in diesem Durchlauf:** Taxonomie-Slug-Änderungen erzeugen jetzt automatische Archiv-Redirects; der Blog-Dispatcher kann alte Query-Slugs über diese Redirect-Spur auf den aktuellen Kategorie-/Tag-Slug auflösen und bleibt damit kompatibel zu bestehenden Theme-Links und Altverweisen.
- **Abhängige Bereiche:** Theme-Routing, Default-Theme-Blogfilter, Redirect-Manager, Kategorien/Tags, Hub-Sites, Site-Tables, Kommentare
- **Offene Must-haves:** keine
- **Offene Nice-to-haves nach Folge-Durchläufen:** Revisionsvergleich, Inhaltsqualitätsprüfungen direkt im Editor
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/pages-posts/README.md`, `CMS/DOC/admin/pages-posts/POSTS.md`

### Audit-Stand – Seiten & Beiträge Nice-to-haves · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.704`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Route:** `/admin/comments`
- **Umgesetztes Nice-to-have:** Kommentarmoderation mit Schnellfiltern und Massenaktionen.
- **Umsetzung in diesem Durchlauf:** Die Kommentar-Liste verbindet Status-Tabs jetzt mit serverseitiger Schnellsuche über Autor, E-Mail, Kommentartext und Beitragstitel sowie zusätzlichen Filtern für Autorentyp (`Gast`, `Registriert`, `Anonymes Mitglied`) und Beitragsbezug (`verknüpft`, `verwaist`). Aktive Filter bleiben über PRG-Redirects nach Einzel- und Bulk-Aktionen erhalten. Während Mehrfachauswahl aktiv ist, schaltet die UI sichtbar in einen Batch-Modus und deaktiviert parallele Zeilenaktionen bewusst, damit Moderations- und Löschpfade nicht gleichzeitig gegeneinander laufen.
- **Abhängige Bereiche:** `CommentsModule`, `CommentService`, `comments.php`, `views/comments/list.php`, `assets/js/admin-comments.js`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Revisionsvergleich für Seiten/Beiträge, Inhaltsqualitätsprüfungen direkt im Editor
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/pages-posts/README.md`, `CMS/DOC/admin/pages-posts/COMMENTS.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Seiten & Beiträge Nice-to-haves · Durchlauf 2

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.706`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/post-categories`, `/admin/post-tags`
- **Umgesetztes Nice-to-have:** Bulk-Aktionen für Kategorien/Tags.
- **Umsetzung in diesem Durchlauf:** Beitragskategorien und Tags können jetzt direkt aus der jeweiligen Taxonomie-Liste gesammelt gelöscht werden. Die POST-Pfade sind CSRF-geschützt, normalisieren IDs serverseitig, validieren den aktuellen Datenbestand und erzwingen bei Beitragsbezug gültige Ersatzkategorien bzw. Ersatztags. Ersatzziele dürfen nicht selbst Teil der Lösch-Auswahl sein. Die Ausführung läuft transaktional und schreibt erfolgreiche Sammelaktionen in den Audit-Trail.
- **Best-Practice-Bezug:** OWASP-CSRF-Hinweise wurden berücksichtigt: zustandsändernde Aktionen bleiben POST-only mit Tokenprüfung; OWASP Input Validation und Mass Assignment wurden über serverseitige ID-/Action-Allowlisting und explizite DTO-artige Parameterübergabe abgebildet.
- **Abhängige Bereiche:** `PostsModule`, `post_categories`, `post_tags`, `post_category_rel`, `post_tag_rel`, Content-Cache, Audit-Log, Public-Taxonomie-Routing
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Revisionsvergleich für Seiten/Beiträge, Inhaltsqualitätsprüfungen direkt im Editor
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/pages-posts/README.md`, `CMS/DOC/admin/pages-posts/POSTS.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Seiten & Beiträge Nice-to-haves Nachprüfung · Durchlauf 3

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.707`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/post-categories`, `/admin/post-tags`
- **Reproduziertes Risiko:** Die Sammellöschungen für Kategorien und Tags leerten den Content-Cache bislang pro Einzellöschung innerhalb der offenen Transaktion; das erzeugte unnötige Invalidierungen und konnte bei späterem Rollback inkonsistente Zwischenzustände begünstigen.
- **Umsetzung in diesem Durchlauf:** Bulk-Löschungen räumen den Cache jetzt nur noch einmal nach erfolgreichem Commit. Zusätzlich nutzen die Sammellöschformulare explizit destruktive Confirm-Metadaten, damit der bestehende Bestätigungs-Flow klar als Löschaktion erscheint.
- **Abhängige Bereiche:** `PostsModule`, `views/posts/categories.php`, `views/posts/tags.php`, Content-Cache, Audit-Log
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Revisionsvergleich für Seiten/Beiträge, Inhaltsqualitätsprüfungen direkt im Editor
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/pages-posts/POSTS.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Seiten & Beiträge Nice-to-haves · Durchlauf 4

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.708`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Route:** `/admin/pages?action=edit&id=...`
- **Umgesetztes Nice-to-have:** Revisionsvergleich für Seiten; Dokumentationsangleichung der bereits vorhandenen Inhaltsqualitätsprüfungen im Editor.
- **Umsetzung in diesem Durchlauf:** Seiten-Revisionen speichern jetzt zusätzlich DE-/EN-Titel, Slugs, Inhalte und Status als Snapshot. Der Seiteneditor zeigt die letzten Revisionen read-only direkt unterhalb der SEO-/Readability-Bereiche an und vergleicht pro Revision die geänderten Felder mit dem aktuellen Stand, inklusive kompakter Inhaltszusammenfassungen für Editor.js-/HTML-Inhalte. Parallel wurde die Prüfliste auf den tatsächlichen Runtime-Stand angepasst: SEO-, Lesbarkeits- und Vorschauprüfungen waren bereits im gemeinsamen Editor-Stack für Seiten und Beiträge vorhanden und gelten damit nicht länger als offenes Nice-to-have.
- **Best-Practice-Bezug:** Der Vergleichspfad bleibt read-only und folgt damit Secure-by-Default sowie Fail-Safe-Design: Revisionsdaten werden nur angezeigt, nicht implizit zurückgeschrieben. Die Oberfläche begrenzt sich aus Performance-Gründen bewusst auf die letzten Snapshots und zeigt nur zusammengefasste Inhaltsauszüge statt kompletter Rohfassungen. Änderungen an der Revisionsspeicherung bleiben serverseitig und auditierbar über den bestehenden Save-Flow.
- **Abhängige Bereiche:** `PageManager`, `SchemaManager`, `PagesModule`, `views/pages/edit.php`, `admin/pages.php`, SEO-/Editor-Stack
- **Offene Nice-to-haves:** Revisionsvergleich für Beiträge
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/pages-posts/README.md`, `CMS/DOC/admin/pages-posts/PAGES.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/core/DATABASE-SCHEMA.md`

### Audit-Stand – Seiten & Beiträge Nice-to-haves · Durchlauf 5

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.709`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Route:** `/admin/posts?action=edit&id=...`
- **Umgesetztes Nice-to-have:** Revisionsvergleich für Beiträge.
- **Umsetzung in diesem Durchlauf:** Der Beitrags-Save-Flow legt vor relevanten Änderungen automatisch Snapshots des bisherigen Stands in `post_revisions` an – inklusive Titel, Slugs, Teaser, Status, Kategorie, Tags, Autorenanzeige, Veröffentlichungszeitpunkt und DE/EN-Inhalten. Der Beitragseditor rendert daraus eine read-only Vergleichskarte mit geänderten Feldern und kompakten Inhaltszusammenfassungen statt kompletter Rohtexte.
- **Best-Practice-Bezug:** Die Umsetzung folgt Secure-by-Default und Fail-Safe-Design: Die Revisionsansicht erlaubt nur Vergleich, keinen stillen Restore. Die UI begrenzt sich bewusst auf die letzten Snapshots und zeigt bei Inhaltsfeldern nur Summaries, was sensible Volltexte im Admin reduziert und die Darstellung performanter hält. Fehler beim Snapshot-Schreiben brechen das Speichern kontrolliert mit generischer Admin-Fehlermeldung ab, statt halbgespeicherte Revisionszustände zu riskieren.
- **Abhängige Bereiche:** `PostsModule`, `views/posts/edit.php`, `SchemaManager`, `post_revisions`, Editor-/SEO-Stack, Taxonomie-Hilfen
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** keine im Bereich Seiten & Beiträge
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/pages-posts/README.md`, `CMS/DOC/admin/pages-posts/POSTS.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/core/DATABASE-SCHEMA.md`

### Audit-Stand – Seiten & Beiträge Nice-to-haves Nachprüfung · Durchlauf 6

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.711`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Routen:** `/admin/pages?action=edit&id=...`, `/admin/posts?action=edit&id=...`
- **Reproduziertes Risiko:** Die neu eingebauten Revisionsvergleiche blieben funktional read-only, erzeugten im Save-Flow aber zusätzliches Snapshot-Debug-Logging für EN-Felder. Das erhöhte Logvolumen und CPU-/I/O-Last unnötig, ohne den dokumentierten Revisionsvertrag funktional zu erweitern.
- **Umsetzung in diesem Durchlauf:** Die Revisionspfade für Seiten und Beiträge behalten ihre read-only Vergleichsansichten und die kontrollierte Snapshot-Erzeugung bei, entfernen aber das laute Debug-Logging aus dem produktiven Save-Flow. Damit bleibt der Vergleichspfad nachvollziehbar und fail-soft, ohne bei jedem Speichern zusätzliche Log-Summaries zu erzeugen.
- **Best-Practice-Bezug:** Logging bleibt auf wirklich sicherheits- und betriebsrelevante Fehler fokussiert, statt bei normalen Content-Saves unnötige Diagnoseeinträge zu produzieren. Das reduziert Alarm-Fog und vermeidet vermeidbare Last im Redaktionsalltag.
- **Abhängige Bereiche:** `PagesModule`, `PostsModule`, read-only Revisionskarten in `views/pages/edit.php` und `views/posts/edit.php`, Save-Flow, Logger/Audit-Pfade
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** keine im Bereich Seiten & Beiträge
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

---

## 4. Medienverwaltung

### Unterbereiche

| Unterbereich | Route | Kernfunktion |
|---|---|---|
| Medien | `/admin/media` | Bibliothek, Upload, Suche, Rename, Move, Delete |
| Beitrags & Site Medien | `/admin/media?tab=featured` | verwendete Featured Images anzeigen und in place ersetzen |
| Kategorien | `/admin/media?tab=categories` | Medien-Kategorien verwalten |
| Einstellungen | `/admin/media?tab=settings` | Upload-Limits, Typen, Auto-WebP, EXIF, Thumbnails |

### Wichtige Abhängigkeiten

- `MediaService`, `MediaUsageService`, `MediaDeliveryService`
- Editor-/Featured-Image-Upload-Pipeline
- ContentMediaPlacementService für Temp-zu-Slug-Relocation
- Konfigurationswerte aus Settings/Dateikonfiguration
- Frontend-Auslieferung und Cache-Busting

### Prüfen

- [x] Upload erlaubt nur serverseitig unterstützte Formate.
- [x] Browser-`accept` und Server-Allowlist passen zueinander.
- [x] Featured-Image-Ersetzung akzeptiert nur tatsächlich verwendete Zielpfade.
- [x] Replace-in-place hält relative Pfade stabil.
- [x] Authentifizierung für interne Upload-Endpunkte ist verpflichtend.
- [x] Suche, Grid-/Listenansicht, Rename, Move und Bulk-Aktionen funktionieren.
- [x] Auto-WebP, EXIF-Strip und Thumbnail-Generierung respektieren die Einstellungen.
- [x] Geschützte Member-Pfade sind korrekt abgesichert.

### Must-haves

- [x] Erlaubte Bildformate konsistent: JPG, JPEG, PNG, GIF, WebP, BMP, ICO.
- [x] SVG und andere blockierte Typen werden serverseitig abgewiesen.
- [x] Replace-in-place bleibt auf die Featured-Map beschränkt.
- [x] Erfolgreiche Bildübernahmen erzeugen keinen 500.
- [x] Keine anonyme Upload-Nutzung für interne Admin-/Member-Uploads.

### Nice-to-haves

- [x] Duplikat-Erkennung nach Hash.
- [x] Mediensuche mit erweiterten Filtern (nicht nur Name/Pfad).
- [x] Hintergrundverarbeitung für WebP-/Thumbnail-Jobs mit Fortschritt.
- [x] Verwendungsanzeige pro Medium direkt in der Bibliothek.
- [ ] weitere sinnvolle Erweiterungen

### Audit-Stand – Medienverwaltung · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.618`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/media`, `/admin/media?tab=featured`, `/admin/media?tab=categories`, `/admin/media?tab=settings`, `/api/upload`
- **Reproduziertes Fehlerbild:** Der Spezial-Flow zum Ersetzen von Beitrags-/Seitenbildern hing serverseitig an den allgemeinen Bibliotheks-Einstellungen `allowed_types`. Dadurch konnte die Featured-Replace-Oberfläche feste Bildformate wie JPG/JPEG, PNG, GIF, WebP, BMP und ICO bewerben, während der Server dieselben Dateien je nach globaler Medienkonfiguration ablehnte.
- **Umsetzung in diesem Durchlauf:** Der Replace-in-place-Flow erzwingt jetzt serverseitig eine feste Bild-Allowlist nur für Featured Images und bleibt damit konsistent zur UI, zur Doku und zum Sicherheitsvertrag des Bereichs; clientseitige Fehlhinweise nennen dieselben Formate nun ebenfalls explizit.
- **Abhängige Bereiche:** MediaService, MediaUsageService, FileUploadService, Beitrags-/Seiten-Featured-Images, Media-Settings, Admin-JavaScript
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Duplikat-Erkennung, erweiterte Suche, Hintergrundjobs, Verwendungsanzeige in der Bibliothek
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`

### Audit-Stand – Medienverwaltung · Durchlauf 2

- **Status:** abgeschlossen auf Code-/Doku-Basis · Release `2.9.721`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Route:** `/admin/media`
- **Umsetzung in diesem Durchlauf:** Die Medienbibliothek erkennt Duplikate jetzt read-only anhand identischer Dateiinhalte. Sichtbare Dateien werden zunächst nach Byte-Größe vorgruppiert und nur bei gleich großen Kandidaten per SHA-256 gehasht. Treffer erscheinen in Listen- und Grid-Ansicht mit Duplikat-Hinweis, Kurz-Hash und weiteren Pfaden; automatische Löschungen oder Referenzumschreibungen finden bewusst nicht statt.
- **Best-Practice-Bezug:** Die Erkennung basiert auf Inhalts-Hashes statt Dateinamen und bleibt durch Größen-Vorfilter, fail-soft übersprungene nicht lesbare Dateien sowie manuelle Admin-Entscheidung vor destruktiven Aktionen sicher und nachvollziehbar.
- **Abhängige Bereiche:** `MediaService`, `MediaModule`, Medienbibliothek-View, Dateisystem unter `CMS/uploads/`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** erweiterte Suche, Hintergrundjobs für WebP-/Thumbnail-Verarbeitung, weitere Verwendungs-/Bibliothekskomfortfunktionen
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Medienverwaltung · Durchlauf 3

- **Status:** abgeschlossen auf Code-/Best-Practice-/Doku-Basis · Release `2.9.725`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Route:** `/admin/media`
- **Umgesetztes Nice-to-have:** Mediensuche mit erweiterten Filtern.
- **Umsetzung in diesem Durchlauf:** Die Bibliothek erweitert die bestehende Name-/Pfad-/Kategorie-/Verwendungsfilterung um serverseitige GET-Filter für Dateityp, Dateiendung, Größenklasse und Änderungszeitraum. Ordner-, Breadcrumb- und Listen-/Grid-Links behalten die aktiven Filter bei; ein Reset-Link entfernt Such- und Filterparameter gezielt wieder.
- **Best-Practice-Bezug:** Der Ausbau erzeugt keine neue Schreibaktion und damit keinen zusätzlichen CSRF-/Token-Pfad. Alle Filterwerte werden per Allowlist bzw. alphanumerischer Endungsnormalisierung begrenzt; manipulierte Werte fallen auf neutrale Defaults zurück, statt einen HTTP-500 zu riskieren. Die Toolbar darf responsive umbrechen, damit zusätzliche Filter auf kleinen Viewports bedienbar bleiben.
- **Abhängige Bereiche:** `MediaModule`, Medienbibliothek-View, `admin.css`, Dateisystem-Metadaten `size`/`modified`, bestehende Usage-Map
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Hintergrundjobs für WebP-/Thumbnail-Verarbeitung, weitere Verwendungs-/Bibliothekskomfortfunktionen
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Medienverwaltung · Durchlauf 4

- **Status:** abgeschlossen auf Code-/Best-Practice-/Doku-Basis · Release `2.9.726`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Route:** `/admin/media?tab=settings`
- **Umgesetztes Nice-to-have:** Hintergrundverarbeitung für WebP-/Thumbnail-Jobs mit Fortschritt.
- **Umsetzung in diesem Durchlauf:** Die Medien-Einstellungen können bestehende Bilder jetzt in einen fortsetzbaren WebP-/Thumbnail-Job legen. Der Job speichert Status, Cursor, Zähler und letzte Fehler atomar in `CMS/config/media-processing-job.json`, verarbeitet pro Schritt nur wenige Quellbilder und überspringt bereits erzeugte Thumbnail-Derivate. Admins können den nächsten Batch per Button anstoßen oder den Job abbrechen.
- **Best-Practice-Bezug:** Mutierende Aktionen bleiben vollständig im vorhandenen Admin-CSRF-/PRG-Vertrag und verlangen die bestehende Medien-Capability. Der Server verarbeitet kleine Chunks statt langer Komplettrequests, fängt Einzelfehler pro Datei ab, zählt und loggt diese und verhindert damit, dass ein defektes Bild den gesamten Medienbereich als HTTP-500 abreißt.
- **Abhängige Bereiche:** `CMS/admin/media.php`, `MediaModule`, `MediaService`, `ImageProcessor`, `CMS/config/media-processing-job.json`, Medien-Einstellungen-View
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Verwendungsanzeige pro Medium direkt in der Bibliothek und weitere Bibliothekskomfortfunktionen
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Medienverwaltung · Durchlauf 5

- **Status:** abgeschlossen auf Code-/Best-Practice-/Doku-Basis · Release `2.9.727`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Route:** `/admin/media`
- **Umgesetztes Nice-to-have:** Verwendungsanzeige pro Medium direkt in der Bibliothek.
- **Umsetzung in diesem Durchlauf:** Die Bibliothek zeigt pro Datei jetzt nicht nur den Verwendungsfilterstatus, sondern konkrete Referenzen aus Beiträgen und Seiten. `MediaModule` verdichtet die vorhandenen `MediaUsageService`-Daten zu Beitrags-/Seiten- und Feld-Zählern; `library.php` rendert direkte Bearbeitungslinks, Badges für Inhaltstyp/Feldkontext und aufklappbare weitere Referenzen. Zusätzlich zeigt die KPI-Leiste, wie viele sichtbare Dateien eingebunden sind.
- **Best-Practice-Bezug:** Der Ausbau ist read-only, erzeugt keine neuen POST-Aktionen und damit keinen zusätzlichen CSRF-/Token-Pfad. Die Anzeige nutzt die bereits berechnete Usage-Map für sichtbare Dateien, begrenzt lange Referenzlisten per `<details>` und escaped alle Titel, Labels und URLs in der View, damit defekte Inhalte oder unerwartete Referenzen nicht zu XSS oder HTTP-500 führen.
- **Abhängige Bereiche:** `MediaUsageService`, `MediaModule`, Medienbibliothek-View, `admin.css`, Beiträge, Seiten
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** weitere sinnvolle Bibliothekskomfortfunktionen
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Medienverwaltung Nachprüfung · Durchlauf 6

- **Status:** abgeschlossen auf Code-/Best-Practice-/Sicherheits-/Performance-Basis · Release `2.9.728`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Routen:** `/admin/media`, `/admin/media?tab=settings`
- **Reproduziertes Risiko:** Die zuletzt ergänzte read-only Duplikat-Erkennung konnte bei mehreren sehr großen gleich großen Dateien im synchronen Bibliotheks-View unnötig viel I/O und CPU für SHA-256-Hashing erzeugen. Zusätzlich verließ sich die direkte Usage-Anzeige auf bereits interne Service-URLs, ohne diese im Renderpfad nochmals fail-closed zu begrenzen; beschädigte Medienjob-Dateien wurden zwar meist harmlos gelesen, aber nicht explizit nach Größe und Schema verworfen.
- **Umsetzung in diesem Durchlauf:** Duplikat-Hashing überspringt sehr große Dateien jetzt opportunistisch im View-Pfad, Usage-Bearbeitungslinks werden direkt in `library.php` auf interne Beitrags-/Seiten-Edit-Routen normalisiert, und `media-processing-job.json` wird beim Laden größen- und schema-validiert. Job-Pfade werden nochmals normalisiert und auf die definierte Job-Grenze begrenzt.
- **Best-Practice-Bezug:** Die Medienbibliothek bleibt ein schneller Admin-View und kein lang laufender Integritätsjob. Upload-/Datei-Best-Practices aus OWASP bleiben erhalten: erlaubte Typen, Größenlimits, Servervalidierung, authentifizierte Mutationen und CSRF; die Nachhärtung ergänzt fail-soft Verhalten für teure Lesepfade und beschädigte lokale Statusdateien.
- **Abhängige Bereiche:** `MediaService`, `MediaModule`, `views/media/library.php`, Medienbibliothek, WebP-/Thumbnail-Jobstatus
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** weitere sinnvolle Bibliothekskomfortfunktionen
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

---

## 5. Benutzer & Gruppen

### Unterbereiche

| Unterbereich | Route | Kernfunktion |
|---|---|---|
| Benutzer | `/admin/users` | Benutzerkonten, Rollen, Status, Profildaten |
| Gruppen | `/admin/groups` | Gruppen, Mitgliedschaften, Paketbezug |
| Rollen & Rechte | `/admin/roles` | RBAC-Matrix und Rollenverwaltung |
| Einstellungen | `/admin/user-settings` | Registrierung, Passwort-Policy, Auth-Provider |

### Wichtige Abhängigkeiten

- `CMS\Auth`, UserService, RBAC/role_permissions
- Gruppen- und Paketlogik
- Öffentliche Registrierung und Passwort-Reset
- Externe Auth-Provider (z. B. LDAP, Passkeys, TOTP)

### Prüfen

- [x] Benutzer anlegen, bearbeiten, deaktivieren, löschen funktioniert.
- [x] Rollen stammen aus der dynamischen Rechte-Matrix und nicht aus Hardcodes.
- [x] Öffentliche Registrierung nutzt die konfigurierte Default-Rolle.
- [x] Default-Rolle fail-closed auf registrierungssichere Rolle.
- [x] Passwort-Policy gilt konsistent für Registrierung, Reset und Admin-CRUD.
- [x] Gruppenverwaltung verbindet Nutzer, Slugs und Paketbezug korrekt.
- [x] Auth-Provider lassen sich sicher konfigurieren.

### Must-haves

- [x] Einheitliche Passwort-Policy in allen Einstiegen.
- [x] Keine administrativen Rollen als öffentliche Standardrolle.
- [x] Capability-Prüfung zentral und nachvollziehbar.
- [x] Auditierbarkeit für kritische Nutzer- und Rollenänderungen.

### Nice-to-haves

- [x] Rollenvergleich / Capability-Diff.
- [x] Gruppen-Massenaktionen.
- [x] Passwort-Policy-Tester im UI.
- [x] Login-/Sicherheitsereignisse pro Benutzer im Profil.

### Audit-Stand – Benutzer & Gruppen · Durchlauf 2

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.710`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Routen:** `/admin/user-settings`
- **Reproduziertes Fehlerbild:** In den Benutzer-/Auth-Einstellungen wurde die Passwort-Policy bisher nur statisch beschrieben. Admins konnten also nicht direkt im UI prüfen, welche konkrete Runtime-Regel ein Testpasswort verletzt; zusätzlich reichte der Save-Pfad bei internen Fehlern rohe Exception-Messages bis in den Alert zurück.
- **Umsetzung in diesem Durchlauf:** `Auth` liefert die Passwort-Policy jetzt strukturiert für Runtime und Admin-UI aus, inklusive Unicode-sicherer Längenprüfung und derselben Reihenfolge der Validierungsfehler wie im Live-Betrieb. `/admin/user-settings` enthält darauf aufbauend einen lokalen Policy-Tester mit Live-Feedback und erster Fehlermeldung nach echtem Runtime-Vertrag, ohne Testeingaben zu speichern oder mitzusenden. Die Save-Route gibt im Fehlerfall nur noch generische UI-Meldungen aus und schreibt technische Details serverseitig ins Log.
- **Abhängige Bereiche:** `CMS\Auth`, Benutzer-/Auth-Settings, öffentliche Registrierung, Passwort-Reset, Admin-Benutzer-CRUD
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Capability-Diff, Sicherheitsereignisse pro Benutzerprofil
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/users-groups/README.md`, `CMS/DOC/admin/users-groups/AUTH-SETTINGS.md`

### Audit-Stand – Benutzer & Gruppen · Durchlauf 3

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.711`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Route:** `/admin/user-settings`
- **Reproduziertes Fehlerbild:** Der lokale Passwort-Policy-Tester zählte Zeichen im Browser nicht in jedem Pfad identisch zur Runtime und hielt die sichtbare Anforderungsliste noch teilweise separat in der View vor. Dadurch konnte die UI bei Unicode-Zeichen vom PHP-Vertrag abweichen und wieder in eine zweite Policy-Quelle driften.
- **Umsetzung in diesem Durchlauf:** Browser und PHP verwenden jetzt denselben Unicode-aware Zeichenzählansatz für die Policy-Prüfung. Gleichzeitig rendert die UI ihre sichtbaren Anforderungen direkt aus der strukturierten Passwort-Policy statt aus statischen Listenpunkten, sodass Runtime-Vertrag, Live-Tester und Textanzeige wieder aus einer Quelle kommen.
- **Best-Practice-Bezug:** Die Passwort-Policy bleibt als Single Source of Truth implementiert; technische Details werden nicht doppelt gepflegt, und Unicode-Eingaben verhalten sich im Tester konsistent zur Servervalidierung.
- **Abhängige Bereiche:** `CMS\Auth`, `UserSettingsModule`, `/admin/user-settings`, öffentliche Registrierung, Passwort-Reset, Admin-Benutzer-CRUD
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Capability-Diff, Sicherheitsereignisse pro Benutzerprofil
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Benutzer & Gruppen · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.619`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/users`, `/admin/groups`, `/admin/roles`, `/admin/user-settings`, `/register`, `/forgot-password`, `/cms-register`, `/cms-password-forgot`
- **Reproduziertes Fehlerbild:** Nicht-Admin-Capability-Prüfungen fielen für Legacy-Core-Rechte wie `manage_settings`, `manage_users`, `manage_pages`, `edit_all_posts` oder `manage_media` noch auf lokale Hardcodes in `Auth::hasCapability()` zurück, statt die gemeinsame Rollenmatrix als alleinige Quelle zu nutzen. Zusätzlich war der öffentliche Passwortvertrag uneinheitlich: Default-Theme-Register- und Reset-Formulare warben noch mit einem 8-Zeichen-Minimum, und das Legacy-Reset-Template validierte schwächer als die globale Core-Policy.
- **Umsetzung in diesem Durchlauf:** Die Rollen- und Rechteverwaltung enthält jetzt auch die weiterhin produktiv genutzten Legacy-Core-Capabilities in derselben Matrix wie moderne `pages.*`-/`settings.*`-Rechte und AI-Capabilities; `Auth::hasCapability()` löst Nicht-Admin-Rechte darüber zentral auf, statt auf lokale Rollenhartcodes zurückzufallen. Parallel spiegeln Default-Theme- und Core-Auth-Formulare für Registrierung und Passwort-Reset denselben 12-Zeichen-/Komplexitätsvertrag wie `Auth::validatePasswordPolicy()`.
- **Abhängige Bereiche:** `CMS\Auth`, `role_permissions`, Rollen & Rechte, Benutzerverwaltung, öffentliche Registrierung, Passwort-Reset, Default-Theme-Auth, CMS-Auth-Page, AI-/SEO-/Settings-/Media-Entrys mit Legacy-Core-Capabilities
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Capability-Diff, Policy-Tester, Sicherheitsereignisse pro Benutzerprofil

### Audit-Stand – Benutzer & Gruppen Nice-to-haves · Durchlauf 4

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.712`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Route:** `/admin/groups`
- **Umgesetztes Nice-to-have:** Gruppen-Massenaktionen.
- **Umsetzung in diesem Durchlauf:** Die Gruppenübersicht unterstützt jetzt Sammelaktionen für Aktivieren, Deaktivieren, Paket zuweisen, Paket entfernen und Löschen direkt in `/admin/groups`. Die POST-Route normalisiert `bulk_action` und `ids[]` serverseitig gegen feste Allowlists, begrenzt Mehrfachauswahlen, validiert den aktuellen Datenbestand fail-soft und delegiert nur explizit erlaubte Felder an das Modul. Paketwechsel laufen als gezielte Massenupdates, Sammellöschungen löschen Mitgliedschaften und Gruppen transaktional, und erfolgreiche kritische Änderungen landen zusätzlich im Audit-Log.
- **Best-Practice-Bezug:** Die Umsetzung folgt Allowlist-/DTO-Prinzipien aus OWASP Input Validation und Mass Assignment: feste Aktionsnamen, normalisierte IDs, keine blinde Übergabe kompletter POST-Daten an destruktive Bulk-Pfade. Die UI schützt Mehrfachaktionen gegen Doppel-Submit, verlangt bei destruktiven Aktionen eine Bestätigung und blendet paketbezogene Eingaben nur für die passende Aktion ein.
- **Abhängige Bereiche:** `groups.php`, `GroupsModule`, `views/users/groups.php`, `assets/js/admin-user-groups.js`, `user_groups`, `user_group_members`, `subscription_plans`, `audit_log`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Capability-Diff, Sicherheitsereignisse pro Benutzerprofil
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/users-groups/README.md`, `CMS/DOC/admin/users-groups/GROUPS.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Benutzer & Gruppen Nice-to-haves · Durchlauf 5

- **Status:** abgeschlossen auf Code-/Best-Practice-/Sicherheits-/Performance-Basis · Release `2.9.729`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Route:** `/admin/roles`
- **Umgesetztes Nice-to-have:** Rollenvergleich / Capability-Diff.
- **Umsetzung in diesem Durchlauf:** Die Rollenverwaltung zeigt jetzt einen read-only Vergleich zweier Rollen an. `RolesModule` nutzt die bereits geladene Rollen-/Capability-Matrix, normalisiert `compare_from` und `compare_to` serverseitig gegen bekannte Rollen und berechnet gemeinsame sowie nur einseitig gesetzte Rechte gruppiert nach Capability-Bereich. Die View rendert den Vergleich über ein GET-Formular ohne Speichern-Button und escaped Rollenlabels sowie Capability-Namen.
- **Best-Practice-Bezug:** Der Ausbau unterstützt Least-Privilege- und Privilege-Creep-Prüfungen nach OWASP Authorization, ohne eine neue schreibende Route, CSRF-Token-Abhängigkeit oder Mass-Assignment-Fläche zu erzeugen. Ungültige GET-Werte fallen fail-closed auf bekannte Rollen zurück; bei zu wenigen Rollen wird der Block nicht gerendert, statt einen HTTP-500 zu riskieren.
- **Abhängige Bereiche:** `roles.php`, `RolesModule`, `views/users/roles.php`, `role_permissions`, gemeinsame Rollenmatrix
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Login-/Sicherheitsereignisse pro Benutzerprofil
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/users-groups/README.md`, `CMS/DOC/admin/users-groups/RBAC.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Benutzer & Gruppen Nice-to-haves · Durchlauf 6

- **Status:** abgeschlossen auf Code-/Best-Practice-/Sicherheits-/Performance-Basis · Release `2.9.730`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Route:** `/admin/users?action=edit&id=...`
- **Umgesetztes Nice-to-have:** Login-/Sicherheitsereignisse pro Benutzer im Profil.
- **Umsetzung in diesem Durchlauf:** Die Benutzerbearbeitung zeigt bei bestehenden Profilen jetzt eine read-only Karte mit den letzten begrenzten Login- und Sicherheitsereignissen aus dem zentralen `audit_log`. `UsersModule` verknüpft Einträge über `user_id`, `entity_type/entity_id` sowie bekannte Auth-Metadaten wie den Login-Identifier, liest nur zusammenfassende Felder und begrenzt die Ausgabe serverseitig. Die View rendert Datum, Kategorie, Aktion, Severity und IP-Adresse, lässt Roh-Metadaten, User-Agent, Tokens, Session-IDs und Secrets aber bewusst weg.
- **Best-Practice-Bezug:** Der Ausbau folgt OWASP Logging und Authentication: Auth-Erfolge/-Fehler und sicherheitsrelevante Kontoereignisse werden für Admins nachvollziehbar, ohne sensible Logdaten offenzulegen. Die Anzeige erzeugt keine neue Schreibroute, keinen zusätzlichen CSRF-/Sicherheitstoken-Pfad und fällt bei fehlendem oder temporär nicht lesbarem Audit-Log fail-soft auf einen neutralen Hinweis zurück, statt die Benutzerverwaltung mit einem HTTP-500 zu blockieren.
- **Abhängige Bereiche:** `users.php`, `UsersModule`, `views/users/edit.php`, `audit_log`, `AuditLogger`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** keine
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/users-groups/README.md`, `CMS/DOC/admin/users-groups/USERS.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Nachprüfung der letzten Nice-to-haves · Durchlauf 7

- **Status:** abgeschlossen auf Code-/Best-Practice-/Sicherheits-/Performance-Basis · Release `2.9.731`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Routen:** `/admin/media?tab=settings`, `/admin/users?action=edit&id=...`, `/admin/roles`, zuletzt ergänzte Medien-/Benutzer-&-Gruppen-Nice-to-haves
- **Umgesetzte Nachhärtung:** Die Paketprüfung gegen OWASP Input Validation, CSRF, Logging, File Upload, SQL Injection, Query Parameterization, Error Handling und Authorization ergab vier konkrete Nachläufer: sichtbare Medienjob-Fehler durften keine rohen Exception-Texte enthalten, Benutzer-Speichern/-Löschen und Rollen-/Rechte-Schreibaktionen sollten interne Exception-Meldungen nicht in Alerts oder Fehlerreport-Payloads zurückgeben, und das Audit-Log-Schema musste den im Code bereits vorgesehenen Severity-Wert `error` unterstützen.
- **Umsetzung in diesem Durchlauf:** `MediaModule` zeigt bei unerwarteten Derivat-Job-Exceptions nur noch generische UI-Details und protokolliert serverseitig datensparsam Exception-Klasse und relativen Pfad. `UsersModule` gibt bei unerwarteten Save/Delete-Exceptions keine rohen Exception-Messages mehr an Admin-Alerts oder Report-Payloads weiter. `RolesModule` behandelt Rollen-/Capability-Schreibfehler ebenfalls generisch und protokolliert serverseitig nur Operation und Exception-Klasse. `SchemaManager` erstellt und migriert `audit_log.severity` mit `info`, `warning`, `error` und `critical`, damit `AuditLogger::sanitizeSeverity()` und Profilanzeige schema-kompatibel bleiben.
- **Best-Practice-Bezug:** Die Nachhärtung folgt OWASP Error Handling und Logging: technische Details bleiben serverseitig, Benutzeroberflächen erhalten generische Hinweise, Logs enthalten ausreichend Diagnosekontext ohne unnötige Rohfehlerausgabe. Der Severity-Fix verhindert stille Audit-Log-Ausfälle bei legitimen `error`-Ereignissen und stärkt damit Monitoring/Nachvollziehbarkeit.
- **Abhängige Bereiche:** `MediaModule`, `UsersModule`, `SchemaManager`, `AuditLogger`, `audit_log`, Medienjob-Statusdatei, Benutzer-Alerts und Fehlerreport-Payloads
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** keine
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/users-groups/README.md`, `CMS/DOC/admin/users-groups/USERS.md`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

---

## 6. Member Dashboard

### Unterbereiche

| Unterbereich | Route | Kernfunktion |
|---|---|---|
| Übersicht | `/admin/member-dashboard` | Status und Schnellzugriffe |
| Allgemein | `/admin/member-dashboard-general` | globale Member-Settings |
| Design & Farben | `/admin/member-dashboard-design` | Design-Konfiguration |
| Frontend-Module | `/admin/member-dashboard-frontend-modules` | sichtbare Module und Seiten |
| Dashboard Widgets | `/admin/member-dashboard-widgets` | Kern-Widgets verwalten |
| Plugin-Widgets | `/admin/member-dashboard-plugin-widgets` | Plugin-Widgets einbinden |
| Profil-Felder | `/admin/member-dashboard-profile-fields` | benutzerdefinierte Profildaten |
| Benachrichtigungen | `/admin/member-dashboard-notifications` | Kommunikations- und Hinweislogik |
| Mitglieder-Onboarding | `/admin/member-dashboard-onboarding` | Onboarding-Prozesse |

### Wichtige Abhängigkeiten

- Member-Frontend und Theme-Ausgabe
- Plugin-Widgets / Hook-Integration
- Benutzerprofile, Rollen und Benachrichtigungssystem

### Prüfen

- [x] Änderungen wirken im Mitgliederbereich sichtbar.
- [x] Nicht verfügbare Plugin-Widgets brechen die Seite nicht.
- [x] Profilfelder werden sauber gespeichert und ausgegeben.
- [x] Onboarding-Schritte greifen in der richtigen Reihenfolge.
- [x] Benachrichtigungsoptionen führen nicht zu doppelten oder verlorenen Events.

### Must-haves

- [x] Klare Trennung zwischen Admin-Konfiguration und Member-Runtime.
- [x] Fail-soft bei fehlenden Plugin-Widgets.
- [x] Sichere Speicherung benutzerdefinierter Profilfelder.

### Nice-to-haves

- [x] Preview-Modus für Member-Dashboard-Konfiguration.
- [x] Widget-Sortierung per Drag & Drop mit Persistenz.
- [x] Onboarding-Analytics / Abschlussrate.

### Audit-Stand – Member Dashboard · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.620`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/member-dashboard`, `/admin/member-dashboard-general`, `/admin/member-dashboard-widgets`, `/admin/member-dashboard-profile-fields`, `/admin/member-dashboard-design`, `/admin/member-dashboard-frontend-modules`, `/admin/member-dashboard-notifications`, `/admin/member-dashboard-onboarding`, `/admin/member-dashboard-plugin-widgets`, `/member/dashboard`
- **Reproduziertes Fehlerbild:** Die Member-Runtime zog ihre Einstellungen über `MemberDashboardModule::getData()` aus dem admin-geschützten Konfigurationsmodul. Für normale Mitglieder lieferte dieser Read-Pfad bewusst leere Daten, sodass der öffentliche `/member/dashboard`-Pfad gespeicherte Einstellungen wie `dashboard_enabled`, Frontend-Module, Onboarding und Notification-Center effektiv verlor und Mitglieder trotz aktivierter Admin-Konfiguration auf `/member/profile` umgeleitet werden konnten.
- **Umsetzung in diesem Durchlauf:** `MemberDashboardModule` stellt jetzt einen eigenen Runtime-Lesepfad für persistierte Member-Settings bereit, der nicht an Admin-Read-Capabilities hängt; `MemberController` nutzt diesen Pfad für den öffentlichen Mitgliederbereich und hält nur die Modul-Aktivierung selbst zusätzlich als Laufzeit-Gate. Dadurch wirken Admin-Änderungen wieder im echten Member-Frontend, ohne die Admin-Konfigurationsoberfläche für Nicht-Admins zu öffnen.
- **Abhängige Bereiche:** Member-Frontend `/member/*`, `MemberController`, Plugin-Widgets, Profil-Fortschritt, Onboarding, Benachrichtigungszentrale, Core-Module-Service
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Preview-Modus, Drag-&-Drop-Widgetsortierung, Onboarding-Analytics
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/member/README.md`, `CMS/DOC/member/README.md`

### Audit-Stand – Member Dashboard Nice-to-haves · Durchlauf 2

- **Status:** abgeschlossen auf Code-/Best-Practice-/Sicherheits-/Performance-Basis · Release `2.9.732`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Route:** `/admin/member-dashboard?preview=1`
- **Umgesetztes Nice-to-have:** Preview-Modus für Member-Dashboard-Konfiguration.
- **Umsetzung in diesem Durchlauf:** Die Übersicht des Member-Dashboard-Admins kann jetzt eine read-only Vorschau der gespeicherten Runtime-Konfiguration öffnen. `MemberDashboardModule` verdichtet vorhandene Settings zu einem Preview-View-Model mit Welcome-Bereich, Frontend-Modulen, Beispiel-Statistiken, Kern-Widgets, eigenen Info-Widgets, sichtbaren Plugin-Widgets, Profilfeldern, Onboarding und Benachrichtigungen. Die View rendert diese Daten auf `/admin/member-dashboard?preview=1` ohne Speichern-Button und ohne personenbezogene Live-Daten. Zusätzlich geben Member-Dashboard-Schreibfehler keine rohen Exception-Messages mehr an Audit-/Admin-Ausgaben weiter.
- **Best-Practice-Bezug:** Der Ausbau folgt OWASP CSRF, Input Validation, Authorization, Error Handling und Logging: Die Vorschau ist ein reiner GET-/Lesepfad, erzeugt keine neue Schreibaktion, transportiert keine CSRF-Token in URLs, nutzt bestehende Admin-Berechtigungen, normalisiert Farben und bekannte Widget-/Plugin-Schlüssel serverseitig und fällt bei fehlenden Plugin-Widgets oder unbekannten Konfigurationswerten fail-soft auf sichere Defaults zurück.
- **Abhängige Bereiche:** `MemberDashboardModule`, `views/member/dashboard.php`, Runtime-Settings `member_*`, `/member/dashboard`, Plugin-Dashboard-Registry, Profilfelder, Onboarding, Benachrichtigungen
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Widget-Sortierung per Drag & Drop mit Persistenz, Onboarding-Analytics / Abschlussrate
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/member/README.md`, `CMS/DOC/member/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Nachprüfung letzte Nice-to-haves · Durchlauf 8

- **Status:** abgeschlossen auf bekannte Fehler, Unvollständiges, Best Practice, Sicherheit und Geschwindigkeit · Release `2.9.733`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Web-Referenzen:** OWASP CSRF Prevention, Input Validation, XSS Prevention, Error Handling, Authorization und Logging Cheat Sheets
- **Geprüfter Fokus:** Zuletzt umgesetzter Member-Dashboard-Preview-Modus und angrenzende Fehler-/Logging-Härtung
- **Gefundene Nachbesserungen:** Die Preview lud Plugin-Widget-Metadaten im Übersichtspfad mehrfach und berechnete zwar die gespeicherte `section_order`, machte diese aber in der Vorschau noch nicht sichtbar.
- **Umsetzung in diesem Durchlauf:** `MemberDashboardModule` lädt Plugin-Widget-Metadaten für `overview`/`plugin-widgets` nun einmal pro Request und reicht sie an Overview- und Preview-Builder weiter. `views/member/dashboard.php` rendert die gespeicherte Bereichsreihenfolge als read-only Badge-Liste mit allowlisted Section-Schlüsseln. Der Preview-Pfad bleibt GET-only, ohne POST-Aktion, ohne CSRF-Token in URLs und ohne personenbezogene Live-Daten.
- **Best-Practice-Bezug:** Keine state-changing GETs, serverseitige Allowlist für Bereichsschlüssel/Farben/Widgets, kontextnahes HTML-/Attribut-Escaping, generische Fehlerausgabe, keine rohen Exception-Messages in Admin-Ausgaben und reduzierter Registry-/Plugin-Overhead im Renderpfad.
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Widget-Sortierung per Drag & Drop mit Persistenz, Onboarding-Analytics / Abschlussrate
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/member/README.md`, `CMS/DOC/member/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Member Dashboard Nice-to-haves · Durchlauf 3

- **Status:** abgeschlossen auf Code-/Best-Practice-/Sicherheits-/Performance-Basis · Release `2.9.734`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Routen:** `/admin/member-dashboard-widgets`, `/admin/member-dashboard-plugin-widgets`, `/member/dashboard`
- **Web-Referenzen:** OWASP CSRF Prevention, MDN HTML Drag and Drop API / Drag operations
- **Umgesetztes Nice-to-have:** Widget-Sortierung per Drag & Drop mit Persistenz.
- **Umsetzung in diesem Durchlauf:** Die Widgets-Seite des Member-Dashboards speichert jetzt zusätzlich eine persistente Reihenfolge für Kern-Widgets und eigene Info-Widgets; Plugin-Widgets behalten ihre persistente Reihenfolge und erhalten denselben Auf/Ab-Fallback wie das Admin-Dashboard. Die UI nutzt progressive Enhancement: Drag-&-Drop als Komfortpfad, Pfeilbuttons als robuster Fallback. `MemberDashboardModule` normalisiert Widget-, Custom- und Plugin-Reihenfolgen serverseitig allowlist-basiert, entfernt Duplikate und ergänzt fehlende bekannte Werte kontrolliert. `MemberController` respektiert die Reihenfolge eigener Info-Widgets im öffentlichen `/member/dashboard`, ohne personenbezogene Daten, Tokens oder neue GET-Aktionen einzuführen.
- **Best-Practice-Bezug:** Keine state-changing GETs; Speichern bleibt beim bestehenden CSRF-geschützten POST-Flow. Die Client-Logik baut Requests nicht aus URL-/Hash-Parametern zusammen, nutzt nur lokale bekannte Sort-Keys und hält Drag-Zustände sowie Drop-Fallbacks robust. Fehlerhafte oder unvollständige Browserdaten fallen auf sichere Defaults zurück, statt einen HTTP-500 auszulösen.
- **Abhängige Bereiche:** `MemberDashboardModule`, `views/member/widgets.php`, `views/member/plugin-widgets.php`, `assets/js/admin-member-dashboard.js`, `MemberController`, `/member/dashboard`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** keine

### Audit-Stand – Member Dashboard Nice-to-haves · Durchlauf 4

- **Status:** abgeschlossen auf Code-/Best-Practice-/Sicherheits-/Performance-Basis · Release `2.9.735`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Route:** `/admin/member-dashboard-onboarding`
- **Web-Referenzen:** OWASP Logging Cheat Sheet, OWASP Error Handling Cheat Sheet, OWASP User Privacy Protection Cheat Sheet, MDN `<progress>`
- **Umgesetztes Nice-to-have:** Onboarding-Analytics / Abschlussrate.
- **Umsetzung in diesem Durchlauf:** `MemberDashboardModule` berechnet jetzt read-only Onboarding-Analytics direkt aus bestehenden Datenquellen. Die Auswertung nutzt aktive Konten aus `users`, Profil-Vervollständigung aus konfigurierten `user_meta`-Feldern, MFA-/Passkey-Adoption aus `user_meta` bzw. `passkey_credentials` und – falls vorhanden – erfolgreiche Logins der letzten 30 Tage aus `activity_log`. `views/member/onboarding.php` rendert daraus eine Abschlussrate, Security-/Aktivitätsquoten und konfigurationsnahe KPI-Karten mit nativen `<progress>`-Elementen. Es werden weder neue Trackingtabellen noch zusätzliche POST-Routen eingeführt.
- **Best-Practice-Bezug:** Keine state-changing GETs, keine zusätzlichen CSRF-Token-Pfade, keine personenbezogenen Einzelwerte im Analytics-UI, generische Fehlerbehandlung mit serverseitigem Logging ohne rohe Exception-Texte und fail-softes Verhalten bei optional fehlenden Tabellen wie `activity_log` oder `passkey_credentials`.
- **Abhängige Bereiche:** `MemberDashboardModule`, `views/member/onboarding.php`, `user_meta`, optionale `activity_log`- und `passkey_credentials`-Tabellen, bestehende Member-Runtime- und Security-Signale
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** keine
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/member/README.md`, `CMS/DOC/member/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/member/README.md`, `CMS/DOC/admin/themes-design/DASHBOARD-WIDGETS.md`, `CMS/DOC/member/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

---

## 7. Aboverwaltung

### Unterbereiche

| Unterbereich | Route | Kernfunktion |
|---|---|---|
| Pakete & Abo-Einstellungen | `/admin/packages` | Tarife, Features, Grenzen |
| Bestellungen & Zuweisung | `/admin/orders` | Order-Lifecycle und Zuordnung |
| Einstellungen | `/admin/subscription-settings` | globale Abo-Logik |

### Wichtige Abhängigkeiten

- Benutzer, Gruppen, Rollen
- Bestell-/Paketdaten
- ggf. Plugin- oder Zahlungsintegration

### Prüfen

- [x] Pakete sind vollständig pflegbar.
- [x] Zuweisungen an Benutzer/Gruppen funktionieren.
- [x] Statuswechsel von Orders sind nachvollziehbar.
- [x] Globale Subscription-Settings wirken auf neue Vorgänge.

### Must-haves

- [x] Konsistente Zuordnung Paket ↔ Benutzer/Gruppe.
- [x] Sichere Statuswechsel mit Audit-Log.
- [x] Keine stillen Inkonsistenzen bei deaktivierten Paketen.

### Nice-to-haves

- [x] Ablaufwarnungen / Renewal-Hinweise.
- [x] Export für Orders und Paketnutzung.
- [x] Historie pro Paket und Bestellung.

### Audit-Stand – Aboverwaltung · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.621`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/packages`, `/admin/orders`, `/admin/subscription-settings`, `/register`, `/cms-register`
- **Reproduziertes Fehlerbild:** Die globale Einstellung `subscription_default_plan_id` ließ sich im Admin speichern, wurde aber im echten Registrierungs- und Benutzer-Anlagepfad nicht angewendet. Damit blieb das im Bereich „Einstellungen“ ausgewählte Standardpaket für neue Mitglieder rein dekorativ und wirkte auf neue Vorgänge nicht.
- **Umsetzung in diesem Durchlauf:** `SubscriptionManager` stellt jetzt einen zentralen Runtime-Helfer für das konfigurierte Standardpaket bereit. Öffentliche Registrierungen über `Auth::register()` und neu im Admin angelegte Member-Konten über `UserService::createUser()` wenden diese Standardpaket-Zuweisung jetzt automatisch an, sofern ein aktiver Plan referenziert ist. Bereits vorhandene aktive/trial-Abos werden dabei nicht überschrieben.
- **Abhängige Bereiche:** `SubscriptionSettingsModule`, `OrdersModule`, `SubscriptionManager`, öffentliche Registrierung, Benutzerverwaltung, Member-Bereich `/member/subscription`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Ablaufwarnungen/Renewal-Hinweise, Export für Orders/Paketnutzung, Historie pro Paket/Bestellung
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/subscription/README.md`, `CMS/DOC/admin/subscription/SUBSCRIPTION-SYSTEM.md`, `CMS/DOC/member/README.md`

### Audit-Stand – Aboverwaltung · Durchlauf 2

- **Status:** abgeschlossen auf Code-/Runtime-/Doku-Basis · Release `2.9.736`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Routen:** `/admin/orders`, `/member/subscription`, `/admin/subscription-settings`
- **Reproduziertes Fehlerbild:** Die globale Einstellung `notification_before_expiry` war zwar im Admin pflegbar, wurde aber in keinem echten Runtime-Pfad ausgewertet. Gleichzeitig zeigte `/member/subscription` weiterhin nur das dekorative Feld `expires_at`, obwohl aktive Mitgliedschaften ihre Laufzeit bzw. Renewal-Termine praktisch über `end_date` und `next_billing_date` führen. Damit blieb das Nice-to-have „Ablaufwarnungen / Renewal-Hinweise“ unvollständig und der Member-Hinweisvertrag inkonsistent.
- **Umsetzung in diesem Durchlauf:** `SubscriptionManager` stellt jetzt einen zentralen read-only Renewal-Vertrag bereit. Admin und Member leiten Hinweise gemeinsam aus `next_billing_date`, `end_date`, globaler Auto-Verlängerung, Kulanzzeit und dem konfigurierten Hinweisfenster ab. `/admin/orders` zeigt fällige bzw. überfällige Verträge in einer eigenen Hinweis-Sektion; `/member/subscription` rendert denselben Laufzeit-/Renewal-Hinweis für das aktive Paket. Der Ausbau bleibt bewusst ohne neue POST-Route, ohne Mailversand und ohne zusätzliche Trackingtabelle und fällt bei fehlenden Daten fail-soft aus.
- **Abhängige Bereiche:** `SubscriptionManager`, `OrdersModule`, `MemberService`, Member-Runtime `/member/subscription`, globale Abo-Einstellungen unter `/admin/subscription-settings`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Export für Orders/Paketnutzung, Historie pro Paket/Bestellung
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/subscription/README.md`, `CMS/DOC/admin/subscription/ORDERS.md`, `CMS/DOC/admin/subscription/SUBSCRIPTION-SYSTEM.md`, `CMS/DOC/member/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Aboverwaltung · Durchlauf 3

- **Status:** abgeschlossen auf Code-/Best-Practice-/Sicherheits-/Performance-Basis · Release `2.9.737`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Route:** `/admin/orders`
- **Umgesetztes Nice-to-have:** Export für Orders und Paketnutzung.
- **Umsetzung in diesem Durchlauf:** `/admin/orders` bietet jetzt zwei read-only CSV-Exporte an: einen Orders-Export mit optionaler Statusfilterung sowie einen Paketnutzungs-Export auf Basis von `subscription_usage` plus aktuellem Abo-/Plankontext. Der Exportpfad läuft bewusst als GET-Download ohne neue Schreibroute und ohne CSRF- oder Sicherheitstoken in der URL. `OrdersModule` begrenzt große Exportmengen fail-soft, protokolliert Exporte datensparsam im Audit-Log und härtet jede CSV-Zelle gegen Spreadsheet-Formula-Injection, statt untrusted Inhalte ungefiltert in Tabellenkalkulationen zu übergeben.
- **Best-Practice-Bezug:** Keine state-changing GETs, keine Token-Leaks in URLs, datensparsame Audit-Protokollierung für den High-Risk-Use-Case „Data Export“ und CSV-Härtung gegen Formula Injection. Fehlerpfade bleiben generisch und leiten mit Flash/Redirect zurück, statt einen nackten Download-500 oder rohe Exception-Texte im UI zu erzeugen.
- **Abhängige Bereiche:** `orders.php`, `OrdersModule`, `orders.php`-View, `subscription_usage`, `user_subscriptions`, `subscription_plans`, `audit_log`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Historie pro Paket/Bestellung
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/subscription/README.md`, `CMS/DOC/admin/subscription/ORDERS.md`, `CMS/DOC/admin/subscription/SUBSCRIPTION-SYSTEM.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Aboverwaltung · Durchlauf 4

- **Status:** abgeschlossen auf Code-/Best-Practice-/Sicherheits-/Performance-Basis · Release `2.9.738`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Routen:** `/admin/orders`, `/admin/packages`
- **Umgesetztes Nice-to-have:** Historie pro Paket und Bestellung.
- **Umsetzung in diesem Durchlauf:** `/admin/orders` zeigt jetzt einen begrenzten read-only Historienblock für sichtbare Bestellungen, Paketzuweisungen und Exportereignisse aus dem vorhandenen `audit_log`. `/admin/packages` ergänzt denselben Ansatz für Paket-Erstellung, -Aktualisierung, -Statuswechsel, -Löschung und Seed-Defaults. Die Anzeige bleibt bewusst datensparsam: Es werden nur verdichtete Ereignisse mit Zeitpunkt, Bezug, Kurzbeschreibung und Severity-Badge gerendert, keine rohen Audit-Metadaten, Kontakt-Payloads oder Tokenwerte.
- **Best-Practice-Bezug:** Der Verlauf nutzt bestehende Auditdaten statt eine neue Historien-Tabelle oder Mutationsroute einzuführen, bleibt limitiert zur Schonung der Admin-Ansicht und fällt bei nicht lesbarem Audit-Log fail-soft mit neutralem Hinweis aus. Das entspricht OWASP-Logging- und Error-Handling-Grundsätzen: Audit-Trail für High-Risk-Aktionen ja, aber ohne sensitive Logdaten im UI und ohne technische Fehleroffenlegung oder HTTP-500 bei Logproblemen.
- **Abhängige Bereiche:** `OrdersModule`, `PackagesModule`, `audit_log`, `AuditLogger`, `orders.php`-View, `packages.php`-View
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** keine im Bereich Aboverwaltung
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/subscription/README.md`, `CMS/DOC/admin/subscription/ORDERS.md`, `CMS/DOC/admin/subscription/PACKAGES.md`, `CMS/DOC/admin/subscription/SUBSCRIPTION-SYSTEM.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

---

## 8. Themes & Design

### Unterbereiche

| Unterbereich | Route | Kernfunktion |
|---|---|---|
| Theme-Verwaltung | `/admin/themes` | Themes aktivieren, wechseln, prüfen |
| Theme-Editor | `/admin/theme-editor` | Theme-Customizer und Design-Tokens |
| Theme-Explorer | `/admin/theme-explorer` | Theme-Dateien und Struktur analysieren |
| Theme-Menü | `/admin/menu-editor` | Navigationsmenüs verwalten |
| Landing Page | `/admin/landing-page` | Homepage-/Landingpage-Aufbau |
| Font Manager | `/admin/font-manager` | Fonts scannen, laden, verwalten |
| CMS Loginpage | `/admin/cms-loginpage` | Loginpage-Branding |
| Theme-Marketplace | `/admin/theme-marketplace` | Theme-Pakete aus vertrauenswürdigen Quellen |

### Wichtige Abhängigkeiten

- ThemeManager / ThemeCustomizer
- Theme-Dateisystemzugriffe
- Menüdaten / Frontend-Navigation
- Font-Assets und Uploads
- Login-/Auth-Seiten
- Marketplace-Downloads, Host-Allowlist, Paketprüfung

### Prüfen

- [x] Theme-Wechsel läuft ohne Frontend-Ausfall.
- [x] Theme-Editor lädt nur sichere und zulässige Customizer-Inhalte.
- [x] Theme-Explorer begrenzt Pfade, Dateitypen und Schreibzugriffe.
- [x] Menü-Editor speichert Navigation korrekt.
- [x] Landing-Page-Änderungen werden sichtbar.
- [x] Font-Manager validiert Dateitypen, Größen und Quellen.
- [x] Loginpage-Branding beeinflusst Auth-Flows nicht negativ.
- [x] Theme-Marketplace prüft Host, ZIP, Hash und Mindeststruktur.

### Must-haves

- [x] Theme-Operationen mit Locking/Health-Check/Audit-Log.
- [x] Atomisches Schreiben bei Dateiänderungen.
- [x] Pfad-Whitelist und Syntaxprüfung bei editierbaren Dateien.
- [x] Remote-Downloads nur per HTTPS und Allowlist.

### Nice-to-haves

## Systemmodul: Web Baukasten (Page Builder)

- [ ] **Web Baukasten als Systemmodul** (`modules/webbuilder/`)
  - [ ] Aktivierbar/deaktivierbar über Admin → System → Module
  - [ ] Mutually exclusive zur Theme-Engine: aktiviert = Theme-Untermenüs ausgeblendet, Baukasten-Untermenüs eingeblendet
  - [ ] Migrationspfad: Beim Aktivieren Snapshot des aktiven Themes (Header/Footer/Colors) als Start-Layout importieren
  - [ ] Migrationspfad: Beim Deaktivieren Warnung + Export der Baukasten-Konfiguration als JSON (kein Datenverlust)
  - [ ] Capability `webbuilder.manage` im RBAC, Default nur für `admin` und `editor`
  - [ ] Feature-Flag in `config/system.php` plus DB-Eintrag in `cms_settings` (`webbuilder_enabled`)
  - [ ] Einheitlicher Hook-Point: `cms.theme.render` vs. `cms.webbuilder.render` (Router entscheidet)

### Layout-Engine

- [ ] **Layout-Bereiche (Regions)**
  - [ ] Header, Content, Footer als Pflichtbereiche
  - [ ] Optionale Bereiche: Topbar, Sidebar-Left, Sidebar-Right, Hero, Pre-Footer
  - [ ] Per-Page-Override: einzelne Seiten können Bereiche überschreiben oder ausblenden
  - [ ] Globale Layouts vs. seitenspezifische Layouts (Vererbung)
  - [ ] Sticky-Header und Sticky-Footer als Toggle
- [ ] **Grid- und Container-System**
  - [ ] 12-Spalten-Grid auf Bootstrap/Tabler-Basis
  - [ ] Container-Varianten: full-width, container, container-fluid, narrow (max 800px für Lesetexte)
  - [ ] Responsive Breakpoints: xs, sm, md, lg, xl, xxl mit individueller Spaltenzahl pro Breakpoint
  - [ ] Row/Column-Editor mit Drag-and-Drop (SortableJS, kein jQuery)
  - [ ] Vertikales Stacking-Verhalten pro Breakpoint konfigurierbar
- [ ] **Sektionen (Section-Blocks)**
  - [ ] Wiederverwendbare Sektionen als Bibliothek speichern
  - [ ] Section-Templates: Hero, Feature-Grid, Testimonials, CTA, Logo-Wall, FAQ, Pricing
  - [ ] Hintergrund pro Sektion: Farbe, Verlauf, Bild, Video, Pattern
  - [ ] Padding/Margin-Spacing-Tokens (sm, md, lg, xl) statt freier Pixel-Werte

### Design-Tokens und Farben

- [ ] **Farb-System**
  - [ ] Primärfarbe, Sekundärfarbe, Akzent, Erfolg, Warnung, Fehler, Info
  - [ ] Neutralfarben: Background, Surface, Border, Text-Primary, Text-Secondary, Text-Muted
  - [ ] Light-Mode und Dark-Mode parallel pflegbar
  - [ ] Auto-Kontrast-Prüfung (WCAG AA/AAA) im Color-Picker
  - [ ] Export der Farben als CSS Custom Properties (`--cms-color-primary`)
  - [ ] Farbpaletten-Presets (z.B. „Schwarzwald", „Tech-Blau", „Warm-Neutral")
- [ ] **Typografie**
  - [ ] Font-Stack: System-Fonts, Google Fonts, Custom Fonts (Upload)
  - [ ] Headings (h1–h6) und Body separat konfigurierbar
  - [ ] Font-Size-Scale (modular scale, z.B. 1.25 ratio)
  - [ ] Line-Height, Letter-Spacing, Font-Weight pro Level
  - [ ] Font-Loading-Strategie: preload, font-display: swap
- [ ] **Spacing und Radien**
  - [ ] Spacing-Scale (4px-Basis: 4, 8, 12, 16, 24, 32, 48, 64, 96)
  - [ ] Border-Radius-Token (none, sm, md, lg, full)
  - [ ] Shadow-Token (none, sm, md, lg, xl)
- [ ] **Token-Export**
  - [ ] Live-Preview im Admin via iFrame
  - [ ] Tokens werden als generierte `assets/cache/tokens.css` ausgespielt
  - [ ] Cache-Invalidierung bei Änderung über Hash im Dateinamen

### Media: Logos, Bilder, Videos

- [ ] **Logo-Verwaltung**
  - [ ] Logo Light, Logo Dark, Logo Mobile, Favicon, Apple-Touch-Icon, OG-Default
  - [ ] SVG-Sanitizer (entfernt `<script>`, `onerror` etc.)
  - [ ] Automatische Größen-Generierung für Favicon (16, 32, 48, 180, 192, 512)
- [ ] **Media-Library-Integration**
  - [ ] Bestehende 365CMS-Mediathek nutzen, nicht doppelt bauen
  - [ ] Bildpicker mit Vorschau, Alt-Text-Pflichtfeld, Caption optional
  - [ ] Responsive Images: automatische Srcset-Generierung (WebP, AVIF, JPEG-Fallback)
  - [ ] Lazy Loading per Default (`loading="lazy"`, `decoding="async"`)
  - [ ] Lightbox-Option pro Bild
- [ ] **Hintergrund-Bilder und Videos**
  - [ ] Position, Repeat, Size, Attachment (incl. parallax-light)
  - [ ] Overlay-Farbe und Opacity über Bildern
  - [ ] Video-Background mit Poster, autoplay/muted/loop, mobile-Fallback auf Bild

### Content-Blöcke (via Editor.js)

- [ ] **Editor.js als Block-Engine im Frontend-Builder**
  - [ ] Bestehende Editor.js-Integration aus 365CMS wiederverwenden
  - [ ] Custom Blocks: Hero, Feature-Card, CTA-Banner, Pricing-Table, Team-Member, Timeline
  - [ ] Block-Settings-UI rechts (Tabler-Offcanvas), Block-Inhalt links
  - [ ] Block-Bibliothek mit Suche und Kategorien
- [ ] **News- und Content-Quellen**
  - [ ] News-Block: zieht aus Posts, filterbar nach Kategorie/Tag
  - [ ] KB-Block: zieht aus Knowledge-Base-Plugin
  - [ ] Forum-Block: aktuelle Threads aus cms-forum
  - [ ] Custom-Query-Block: für Devs, mit sicherer Whitelist statt freiem SQL
- [ ] **Standard-Inhaltsblöcke**
  - [ ] Heading, Paragraph, List, Quote, Code, Table
  - [ ] Image, Gallery, Embed (YouTube, Vimeo, Eigenes), File-Download
  - [ ] Button (Primary/Secondary/Ghost, Größen, Icon)
  - [ ] Spacer, Divider, Columns (2/3/4 Spalten)
  - [ ] Accordion, Tabs, Cards
  - [ ] Form-Block (Anbindung an Forms-Plugin)
  - [ ] Map-Block (OpenStreetMap als Default, Google Maps optional)
  - [ ] Icon-Block (Tabler-Icons + Lucide)
  - [ ] HTML-Raw-Block (capability-geschützt: nur `webbuilder.html_raw`)

### Navigation und Menüs

- [ ] **Menü-Manager**
  - [ ] Mehrere Menüs (Header, Footer, Mobile, Sidebar)
  - [ ] Drag-and-Drop-Hierarchie, max. 3 Ebenen
  - [ ] Menü-Item-Typen: interne Seite, externe URL, Anker, Dropdown, Mega-Menu
  - [ ] Sichtbarkeitsregeln: nur eingeloggt, nur Rolle X, nur Sprache Y
  - [ ] Icons pro Menüpunkt
- [ ] **Mega-Menu**
  - [ ] Spalten mit Überschriften, Links, optional Promo-Block (Bild + CTA)
- [ ] **Breadcrumbs**
  - [ ] Auto-Generierung aus Seitenhierarchie
  - [ ] Schema.org-Markup (BreadcrumbList) inkludiert

### Vorlagen, Seiten und Wiederverwendung

- [ ] **Page-Templates**
  - [ ] Mitgelieferte Templates: Landing, About, Contact, Blog-List, Blog-Single, 404
  - [ ] Eigene Templates speicherbar, als Startpunkt für neue Seiten
  - [ ] Template-Vererbung: globale Änderungen propagieren, lokale Overrides bleiben
- [ ] **Globale Bereiche (Reusable Blocks)**
  - [ ] Header-, Footer-, CTA-Blöcke einmal pflegen, überall einbinden
  - [ ] Versionierung mit Diff-Ansicht
- [ ] **Revisionen und Drafts**
  - [ ] Autosave alle 30 Sekunden
  - [ ] Manuelle Revisionen mit Kommentar
  - [ ] Revisions-Vergleich (links alt, rechts neu)
  - [ ] Veröffentlichen / Geplant veröffentlichen / Privat / Passwortgeschützt

### Editor-UX im Admin

- [ ] **Drei-Panel-Layout**
  - [ ] Links: Block-/Sektion-Bibliothek
  - [ ] Mitte: Live-Preview (iFrame, gleiche Engine wie Frontend)
  - [ ] Rechts: Eigenschafts-Panel für selektierten Block
- [ ] **Device-Preview**
  - [ ] Toggle Desktop / Tablet / Mobile mit korrekten Breakpoints
- [ ] **Inline-Editing**
  - [ ] Texte direkt in der Preview editierbar (contenteditable, kontrolliert)
- [ ] **Undo/Redo**
  - [ ] Mind. 50 Schritte
  - [ ] Tastaturkürzel (Ctrl/Cmd+Z, Ctrl/Cmd+Shift+Z)
- [ ] **Keyboard-Navigation**
  - [ ] Block-Auswahl mit Pfeiltasten, Duplizieren mit Ctrl+D, Löschen mit Entf

### SEO, Social und Strukturdaten

- [ ] **Pro-Seite-SEO-Panel**
  - [ ] Meta-Title, Meta-Description, Canonical, Robots (index/noindex/follow/nofollow)
  - [ ] Open Graph: Title, Description, Image, Type
  - [ ] Twitter Cards: Card-Type, Site, Creator
  - [ ] Schema.org: Article, Product, FAQPage, HowTo, Organization (auto + manuell)
- [ ] **Sitemap und Robots**
  - [ ] Auto-generierte XML-Sitemap, splittbar nach Typ
  - [ ] `robots.txt`-Editor
- [ ] **SEO-Checks im Editor**
  - [ ] Title-Länge, Description-Länge, H1-Vorhandensein, Bild-Alt-Texte, interne Links

### Mehrsprachigkeit (optional, deaktivierbar)

- [ ] **i18n-Schicht**
  - [ ] Sprachen aktivierbar in Systemeinstellungen
  - [ ] Pro Seite: Übersetzungen verknüpft (hreflang automatisch)
  - [ ] Übersetzbare Strings im UI über Translation-Editor
  - [ ] Default-Sprache Deutsch, da DACH-Zielgruppe

### Performance und Caching

- [ ] **Render-Cache**
  - [ ] Pro-Seite-HTML-Cache mit Cache-Tags (page, block, global)
  - [ ] Invalidierung bei Block-/Settings-Änderung
- [ ] **Asset-Pipeline**
  - [ ] CSS-/JS-Bundling pro Seite, nur wirklich genutzte Blöcke laden
  - [ ] Critical-CSS-Inlining für Above-the-Fold
  - [ ] Lazy-Hydration für interaktive Blöcke (Tabs, Accordion)
- [ ] **Bild-Optimierung**
  - [ ] WebP/AVIF-Generierung beim Upload (libvips/Imagick)
  - [ ] CDN-Hook (Pfad-Rewriting via Setting)

### Accessibility (a11y)

- [ ] **Pflicht-Checks vor Veröffentlichung**
  - [ ] H1-Eindeutigkeit pro Seite
  - [ ] Alt-Texte für nicht-dekorative Bilder
  - [ ] Kontrastverhältnis WCAG AA
  - [ ] Fokus-Reihenfolge logisch (Skip-Link am Anfang)
- [ ] **ARIA-Defaults**
  - [ ] Landmarks (header, nav, main, footer) automatisch
  - [ ] Mobile-Menü mit `aria-expanded`, `aria-controls`

### Datenschutz und Cookie-Consent

- [ ] **Consent-Manager als Block**
  - [ ] Kategorien: notwendig, funktional, statistik, marketing
  - [ ] Embeds (YouTube, Maps) erst nach Consent laden, sonst Platzhalter mit „Klick zum Aktivieren"
  - [ ] DSGVO-konform, Standort Deutschland berücksichtigt
- [ ] **Footer-Pflichtlinks**
  - [ ] Impressum, Datenschutz, Kontakt vorbelegt aus Systemeinstellungen

### Formulare

- [ ] **Form-Builder als Block**
  - [ ] Felder: Text, Textarea, Email, Tel, Select, Radio, Checkbox, Datei-Upload, Hidden, Honeypot
  - [ ] Validierung clientseitig (Vanilla JS) und serverseitig (PHP, gleiche Regeln)
  - [ ] Submission-Speicher in DB plus Mail-Versand (PHPMailer/Symfony Mailer)
  - [ ] Anti-Spam: Honeypot + Rate-Limit + optional hCaptcha/Cloudflare Turnstile
  - [ ] DSGVO-Hinweis-Checkbox als Standard

### Suche

- [ ] **Site-Search**
  - [ ] Volltext-Suche über Seiten, Posts, KB
  - [ ] MySQL-Fulltext als Default, optional Meilisearch-Adapter
  - [ ] Such-Block für Header (Autocomplete) und Such-Ergebnis-Seite

### Entwickler-Schnittstellen

- [ ] **Hook-System**
  - [ ] Filter und Actions für jeden Render-Schritt (`webbuilder.block.render`, `webbuilder.page.head`)
  - [ ] Custom-Block-API: PHP-Klasse + JS-Editor-Komponente registrierbar
- [ ] **REST-/JSON-API**
  - [ ] Endpoints: `/api/webbuilder/pages`, `/api/webbuilder/blocks`
  - [ ] Auth via API-Token mit Scopes
- [ ] **CLI-Tool**
  - [ ] Export/Import von Seiten, Sektionen, Tokens als JSON
  - [ ] Migrationsbefehle (`php cms webbuilder:migrate-from-theme=default`)
- [ ] **Logging**
  - [ ] PSR-3/Monolog: Editor-Aktionen, Render-Fehler, Cache-Invalidierungen
  - [ ] Audit-Log: wer hat wann welche Seite geändert

### Sicherheit

- [ ] **Input-Sanitizing**
  - [ ] HTML-Purifier für alle Block-Outputs
  - [ ] CSP-Header mit Nonce für Inline-Scripts
- [ ] **CSRF-Schutz**
  - [ ] Tokens für alle Editor-POSTs
- [ ] **Capability-Checks**
  - [ ] `webbuilder.manage`, `webbuilder.publish`, `webbuilder.html_raw`, `webbuilder.media`

### Backup und Migration

- [ ] **Export/Import**
  - [ ] Komplette Site als ZIP (DB-Inhalte + Media + Tokens)
  - [ ] Selektiver Export einzelner Seiten oder Sektionen
- [ ] **Theme → Webbaukasten Migration**
  - [ ] Wizard: aktives Theme analysieren, Header/Footer/Farben vorausfüllen
  - [ ] Bestehende Posts/Pages bleiben unangetastet, nur Layout-Hülle wechselt

### Telemetrie und Analytics-Hooks

- [ ] **Analytics-Block-Slot im `<head>`**
  - [ ] Matomo (self-hosted, primary), Plausible, GA4 als Optionen
  - [ ] Consent-Gate vorgeschaltet
- [ ] **Performance-Marker**
  - [ ] Core Web Vitals via `web-vitals.js` optional einbindbar

### Dokumentation und Onboarding

- [ ] **In-App-Hilfe**
  - [ ] Tooltips an jedem Setting
  - [ ] Erste-Schritte-Tour beim ersten Aktivieren des Moduls
- [ ] **Beispielseiten**
  - [ ] Demo-Site importierbar (1 Klick) zum Lernen
- [ ] **Changelog-Anzeige im Admin**
  - [ ] Neue Features pro Version sichtbar

# weitere Nice-to-Haves:
- [x] Font-Nutzungsanalyse.
- [ ] Komponentenbibliothek für Landing-Page-Bausteine.

### Audit-Stand – Themes & Design · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.622`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/themes`, `/admin/theme-editor`, `/admin/theme-explorer`, `/admin/menu-editor`, `/admin/landing-page`, `/admin/font-manager`, `/admin/cms-loginpage`, `/admin/theme-marketplace`, `/`
- **Reproduziertes Fehlerbild:** Der Plugins-Tab der Landing-Page speicherte nur dekorative `enabled`-/`sort_order`-Werte unter `plugin_settings`, während die eigentlichen Bereichs-Overrides `header`, `content` und `footer` weder im Admin gepflegt noch im Default-Theme gerendert wurden. Damit blieb der dokumentierte Plugin-Override-Pfad der Landing Page in der echten Runtime wirkungslos.
- **Umsetzung in diesem Durchlauf:** Landing-Plugins verwenden jetzt einen echten Override-Vertrag mit renderbarem `render_callback`. Der Admin speichert pro Plugin die konkreten Bereichs-Zuweisungen für Header, Content und Footer statt rein dekorativer Toggles, und das Default-Theme rendert aktive Overrides nun bereichsgenau im Landing-Frontend. Nicht renderbare oder ungültige Zuordnungen failen dabei geschlossen.
- **Abhängige Bereiche:** `LandingPageModule`, `LandingPageService`, `LandingPluginService`, Default-Theme-Partial `home-landing.php`, Hook `landing_page_plugins`, Themes-&-Design-Doku
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** ein Theme was wie ein "Web Baukasten" funktioniert, Komponentenbibliothek für Landing-Page-Bausteine
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/themes-design/README.md`, `CMS/DOC/admin/landing-page/LANDING-PAGE.md`, `CMS/DOC/core/HOOKS-REFERENCE.md`

### Audit-Stand – Themes & Design · Durchlauf 2

- **Status:** abgeschlossen auf Code-/Best-Practice-/Sicherheits-/Performance-Basis · Release `2.9.739`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Route:** `/admin/font-manager`
- **Umgesetztes Nice-to-have:** Font-Nutzungsanalyse.
- **Umsetzung in diesem Durchlauf:** `FontManagerModule` verdichtet jetzt den Theme-Scan, lokale `custom_fonts`, den Asset-Status der lokal gespeicherten CSS-/Font-Dateien sowie die aktuelle Heading-/Body-Konfiguration zu einer read-only Analyse. Die View zeigt daraus Runtime-Statuskarten, Zuweisungen für Überschriften und Fließtext sowie eine priorisierte Tabelle für lokal aktive, nur lokal vorhandene, extern erkannte und aktuell ungenutzte Fonts.
- **Best-Practice-Bezug:** Der Ausbau bleibt komplett lesend und führt keine neue POST-Aktion, keine zusätzliche GET-Mutation und keinen Tokenpfad ein. Dateinamen und Hinweise werden escaped ausgegeben; fehlende Scan-Daten oder beschädigte Font-Assets erzeugen nur Warnhinweise statt eines HTTP-500. Die Analyse nutzt ausschließlich bereits vorhandene Datenquellen und respektiert den bestehenden lokalen Font-Modus `privacy_use_local_fonts` als Runtime-Gate.
- **Abhängige Bereiche:** `FontManagerModule`, `views/themes/fonts.php`, `ThemeManager`, `Bootstrap`, `custom_fonts`, `settings`, lokales Verzeichnis `/uploads/fonts`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** ein Theme was wie ein "Web Baukasten" funktioniert, Komponentenbibliothek für Landing-Page-Bausteine
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/themes-design/README.md`, `CMS/DOC/admin/themes-design/FONTS.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

---

## 9. SEO

### Unterbereiche

| Unterbereich | Route | Kernfunktion |
|---|---|---|
| SEO Dashboard | `/admin/seo-dashboard` | Überblick und Health-Score |
| Analytics | `/admin/analytics` | Kennzahlen, Tracking, Reports |
| SEO Audit | `/admin/seo-audit` | Optimierungspotenziale |
| Meta-Daten | `/admin/seo-meta` | globale Meta-Templates |
| Social Media | `/admin/seo-social` | Open Graph / Social Tags |
| Strukturierte Daten | `/admin/seo-schema` | Schema.org-Konfiguration |
| Sitemap & robots.txt | `/admin/seo-sitemap` | Sitemap-Generierung und robots.txt |
| Technisches SEO | `/admin/seo-technical` | Canonical, Index, technische Defaults |
| Weiterleitungen | `/admin/redirect-manager` | Redirect-Regeln |
| 404-Monitor | `/admin/not-found-monitor` | 404-Erkennung und Auswertung |

### Wichtige Abhängigkeiten

- Seiten/Beiträge und deren Meta-Daten
- Lokalisierung und Redirects
- Frontend-Routing und Theme-Ausgabe
- Analytics-/Tracking-Konfiguration

### Prüfen

- [x] SEO-Dashboard aggregiert plausible Werte.
- [x] Meta-Templates greifen für Inhalte korrekt.
- [x] Social-/Schema-Daten erscheinen im Frontend.
- [x] Sitemap und robots.txt sind generierbar und gültig.
- [x] Redirects funktionieren für alte und neue Slugs.
- [x] 404-Monitor protokolliert und entlastet die Hauptseite.
- [x] Kategorie-/Tag-Routing bleibt query-basiert kompatibel.

### Must-haves

- [x] Redirects für lokalisierte Seiten nutzen `/en/<slug>`.
- [x] Alte Suffix-Redirects bleiben kompatibel.
- [x] SEO-Einstellungen und Editor-Karten greifen konsistent ineinander.
- [x] Keine stillen Routing-Brüche bei Kategorie-/Tag-Filtern.

### Nice-to-haves

- [ ] Snippet-/SERP-Preview pro Inhalt und global.
- [ ] Broken-Link-Prüfung.
- [ ] Automatische SEO-Hinweise direkt im Editor.
- [ ] Trendansicht für 404, Redirects und SEO-Score.

### Audit-Stand – SEO · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.623`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/seo-dashboard`, `/admin/analytics`, `/admin/seo-audit`, `/admin/seo-meta`, `/admin/seo-social`, `/admin/seo-schema`, `/admin/seo-sitemap`, `/admin/seo-technical`, `/admin/redirect-manager`, `/`, Beitrags-/Seiten-Frontend
- **Reproduziertes Fehlerbild:** Die im Bereich `/admin/seo-social` gespeicherten globalen Social-Defaults für `og_type`, `default_image`, `twitter_card` und Brand-Name wurden im echten Frontend-Head-Renderer nicht konsistent als Fallback genutzt. Dadurch blieb ein Teil der Social-Konfiguration dekorativ: Inhalte ohne eigene Social-Meta-Werte liefen weiterhin mit hart codiertem `website`/`summary_large_image`, leerem Fallback-Bild und festem `SITE_NAME`; zusätzlich bewarb die UI einen nicht unterstützten OG-Type `event`.
- **Umsetzung in diesem Durchlauf:** `SeoSettingsStore` liefert jetzt normalisierte globale Social-Defaults, und `SeoHeadRenderer` nutzt diese im Homepage-/Fallback-Pfad sowie für Inhalte ohne eigene Social-Meta-Werte tatsächlich zur Laufzeit für `og:type`, `og:image`, `twitter:card` und `og:site_name`. Die Social-Admin-UI bewirbt nur noch die wirklich unterstützten OG-Typen und erklärt die Fallback-Wirkung direkt am Formular.
- **Abhängige Bereiche:** `SeoSuiteModule`, `SeoSettingsStore`, `SeoHeadRenderer`, Admin-View `views/seo/social.php`, Frontend-Head-Ausgabe, SEO-Dokumentation
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Snippet-/SERP-Preview pro Inhalt und global, Broken-Link-Prüfung, automatische SEO-Hinweise direkt im Editor, Trendansicht für 404/Redirects/SEO-Score
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/seo/README.md`, `CMS/DOC/admin/seo/SEO.md`

---

## 10. Performance

### Unterbereiche

| Unterbereich | Route | Kernfunktion |
|---|---|---|
| Übersicht | `/admin/performance` | Score, KPIs, Status |
| Cache-Verwaltung | `/admin/performance-cache` | Cache-Status und Bereinigung |
| Medien-Optimierung | `/admin/performance-media` | WebP, EXIF, Bildoptimierung |
| Datenbank-Wartung | `/admin/performance-database` | Cleanup, Optimize, Repair |
| Performance-Einstellungen | `/admin/performance-settings` | globale Tuning-Optionen |
| Session-Verwaltung | `/admin/performance-sessions` | Session-Übersicht und Cleanup |

### Wichtige Abhängigkeiten

- Cache-Subsystem und HTTP-Header
- Medien-Settings und Upload-Pipeline
- Datenbank-Engine-Eigenheiten
- Session-Speicher und Cron-/Cleanup-Jobs

### Prüfen

- [x] Cache lässt sich gezielt leeren.
- [x] Medienoptimierung respektiert Auto-WebP-/EXIF-Schalter.
- [x] Performance-Settings speichern nur ihre eigenen Felder.
- [x] OPTIMIZE/REPAIR wird nur auf unterstützte Engines angewendet.
- [x] Session-Übersicht ist konsistent und Cleanup wirksam.

### Must-haves

- [x] Kein InnoDB-Repair.
- [x] Cache-Header und UI-Schalter bleiben konsistent.
- [x] Lange Optimierungsjobs sind robust und unterbrechungssicher.

### Nice-to-haves

- [ ] Dry-Run und Rollback für Massenoptimierungen.
- [ ] Historie der Performance-Maßnahmen.
- [ ] Kapazitätswarnungen vor Optimierungsjobs.

### Audit-Stand – Performance · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.624`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/performance`, `/admin/performance-cache`, `/admin/performance-media`, `/admin/performance-database`, `/admin/performance-settings`, `/admin/performance-sessions`, öffentliche HTML-/Medienauslieferung
- **Reproduziertes Fehlerbild:** Die globale Performance-Seite bot mit `perf_gzip` einen speicherbaren Schalter „GZIP/Brotli-Auslieferung vorbereiten“ an, obwohl die echte Kompression laut Runtime ausschließlich über Apache-/Proxy-Konfiguration erkannt und bereitgestellt wird. Der Setting-Wert wurde nur im Performance-Modul gespeichert, aber nirgends von Router oder Medienauslieferung ausgewertet – damit war der Schalter dekorativ und ließ UI, Doku und Laufzeit auseinanderlaufen.
- **Umsetzung in diesem Durchlauf:** Der dekorative `perf_gzip`-Schreibpfad wurde aus dem Performance-Modul entfernt. Die Einstellungsseite zeigt Server-Kompression jetzt bewusst nur noch als Status mit Hinweis auf die echte Apache-/Brotli-/Deflate-Konfiguration, während die tatsächlich wirksamen Performance-Schalter für Cache-Header, Minify, Lazy Loading, WebP/EXIF und Session-Timeouts unverändert aktiv bleiben.
- **Abhängige Bereiche:** `PerformanceModule`, Admin-View `views/performance/settings.php`, `Router`, `MediaDeliveryService`, Performance-Dokumentation
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Dry-Run/Rollback für Massenoptimierungen, Historie der Performance-Maßnahmen, Kapazitätswarnungen vor Optimierungsjobs
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/performance/README.md`, `CMS/DOC/admin/performance/PERFORMANCE.md`

---

## 11. Recht

### Unterbereiche

| Unterbereich | Route | Kernfunktion |
|---|---|---|
| Legal Sites | `/admin/legal-sites` | Impressum, Datenschutz, AGB, Widerruf |
| Cookie-Manager | `/admin/cookie-manager` | Consent-Banner und Kategorien |
| Auskunft & Löschen | `/admin/data-requests` | DSGVO-Export- und Löschanfragen |

### Wichtige Abhängigkeiten

- Seiten-/Content-System
- Consent-/Tracking-Logik
- Export-/Löschroutinen und personenbezogene Daten in Plugins

### Prüfen

- [x] Legal-Seiten lassen sich erzeugen und pflegen.
- [x] Cookie-Manager wirkt im Frontend korrekt.
- [x] Auskunfts- und Löschanfragen werden vollständig verarbeitet.
- [x] Relevante Aktionen landen im Audit-Log.

### Must-haves

- [x] DSGVO-Workflows müssen nachvollziehbar und protokolliert sein.
- [x] Export/Löschung berücksichtigt Plugin-Hooks.
- [x] Consent-Einstellungen und Frontend-Banner dürfen nicht auseinanderlaufen.

### Nice-to-haves

- [ ] Vorlagen / Profile für Rechtstexte.
- [ ] Fristen- und Bearbeitungsstatus für Datenschutzanfragen.

### Audit-Stand – Recht · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.625`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/legal-sites`, `/admin/cookie-manager`, `/admin/data-requests`, `/cookie-einstellungen`
- **Reproduziertes Fehlerbild:** Die DSGVO-Sammelroute `/admin/data-requests` bearbeitete Auskunfts- und Löschanfragen zwar funktional, dokumentierte zentrale Zustandswechsel aber nicht im Audit-Log. Zusätzlich akzeptierten die Servermodule Ablehnungen ohne belastbare Begründung, obwohl UI und Doku eine nachvollziehbare Ablehnung voraussetzen. Damit blieb der Datenschutz-Workflow für Bearbeitung, Ablehnung und Löschung nur teilweise nachvollziehbar.
- **Umsetzung in diesem Durchlauf:** `PrivacyRequestsModule` und `DeletionRequestsModule` schreiben Zustandswechsel für Bearbeitung, Abschluss, Ablehnung, Löschausführung und endgültiges Entfernen jetzt ins Audit-Log. Ablehnungen verlangen serverseitig eine nichtleere Begründung. Die bestehenden Plugin-Hooks `dsgvo_export_data` und `dsgvo_delete_data` bleiben dabei erhalten und sind nun in einen nachvollziehbaren Admin-Workflow eingebettet.
- **Abhängige Bereiche:** `PrivacyRequestsModule`, `DeletionRequestsModule`, `data-requests.php`, DSGVO-Hooks `dsgvo_export_data` / `dsgvo_delete_data`, Legal-Dokumentation
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Vorlagen/Profile für Rechtstexte, Fristen-/Bearbeitungsstatus für Datenschutzanfragen
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/legal/README.md`, `CMS/DOC/admin/legal/DSGVO.md`, `CMS/DOC/admin/legal/DELETION-REQUESTS.md`

### Audit-Stand – Recht/Cookie-Manager · Matomo-Persistenzfix

- **Status:** abgeschlossen auf Code-/Runtime-/Doku-Basis · Release `2.9.722`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Routen:** `/admin/cookie-manager`, `/cookie-einstellungen`
- **Reproduziertes Fehlerbild:** Matomo-Self-Hosted-Werte konnten nach dem Speichern im Admin wieder leer erscheinen und wurden auf der öffentlichen Cookie-Einstellungsseite nicht zuverlässig übernommen, weil der Settings-Speicher stille DB-Wrapper-Fehlschläge nicht als Fehler behandelte und die Public-Runtime teils mit Defaultwerten statt echten Matomo-Konfigurationssignalen arbeitete.
- **Umsetzung in diesem Durchlauf:** `CookieManagerModule` speichert globale Cookie-/Matomo-Settings atomar per `INSERT ... ON DUPLICATE KEY UPDATE` direkt gegen die prefixed `settings`-Tabelle und wirft bei Speicherfehlern eine sichtbare Modul-Fehlermeldung. `CookieConsentService` liest die gespeicherten Matomo-Werte konsistent für `/cookie-einstellungen`, nutzt bei Bedarf vorhandene SEO-Matomo-Werte als URL-/Site-ID-Fallback und rendert den Matomo-Transparenzblock nur noch bei tatsächlicher Konfiguration.
- **Abhängige Bereiche:** `CookieManagerModule`, `CookieConsentService`, `settings`, SEO-Analytics-Matomo-Settings, Public-Routing `/cookie-einstellungen`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Rechtstext-Vorlagen/Profile, Fristen-/Bearbeitungsstatus für Datenschutzanfragen
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/legal/README.md`, `CMS/DOC/admin/legal/COOKIES.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Audit-Stand – Recht/Cookie-Manager · Matomo-URL-Nachfix

- **Status:** abgeschlossen auf Code-/Runtime-/Doku-Basis · Release `2.9.723`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Routen:** `/admin/cookie-manager`, `/cookie-einstellungen`
- **Reproduziertes Fehlerbild:** Korrekte Matomo-Self-Hosted-URLs konnten weiterhin mit „Die Matomo-URL muss als gültige http(s)-URL ohne Zugangsdaten angegeben werden.“ abgewiesen werden, weil die Validierung zu stark von `filter_var()` abhing und typische Self-Hosted-/Copy-Paste-Varianten nicht robust normalisierte.
- **Umsetzung in diesem Durchlauf:** Admin-Modul und Public-Service nutzen jetzt dieselbe URL-Normalisierung für Matomo: Unicode-/Copy-Paste-Leerzeichen werden bereinigt, URLs ohne Schema erhalten kontrolliert `https://`, IDN-/Intranet-Hosts, `localhost`, IP-Adressen, Ports, Pfade und Query-Parameter werden akzeptiert, während Zugangsdaten sowie nicht-http(s)-Schemata weiterhin fail-closed abgelehnt werden. Zusätzlich blockiert ein leer gespeicherter Cookie-Matomo-URL-Wert den SEO-Matomo-Fallback im Public-Service nicht mehr.
- **Abhängige Bereiche:** `CookieManagerModule`, `CookieConsentService`, `settings`, SEO-Analytics-Matomo-Settings, Public-Routing `/cookie-einstellungen`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Rechtstext-Vorlagen/Profile, Fristen-/Bearbeitungsstatus für Datenschutzanfragen
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/legal/COOKIES.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Live-Fatal-Hotfix – SchemaManager & Default-Theme-Helfer

- **Status:** abgeschlossen auf Code-/Log-/Doku-Basis · Release `2.9.724`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-10
- **Geprüfte Logmeldungen:** `Cannot redeclare class CMS\\SchemaManager`, `Cannot redeclare function meridian_nav_menu()` sowie der ältere Dashboard-`$sections`-Fatal aus dem Rollen-Vorlagenpfad.
- **Umsetzung in diesem Durchlauf:** `SchemaManager.php` nutzt jetzt eine echte konditionale Klassendeklaration statt eines zu spät greifenden Top-Level-Return-Guards. `meridian_nav_menu()` ist im Default-Theme sowohl in `functions.php` als auch in `includes/theme-runtime-helpers.php` per `function_exists()` gekapselt. Der Dashboard-Stack wurde gegen den gemeldeten `$sections`-Pfad gegengeprüft; im aktuellen Code wird `$sections` vor der Rollen-Vorlagen-Normalisierung initialisiert.
- **Abhängige Bereiche:** `Database`, `Bootstrap`, `MigrationManager`, Installer-/Repair-Pfade, Default-Theme-Runtime, Admin-Dashboard
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** keine aus diesem Hotfix
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

---

## 12. Sicherheit

### Unterbereiche

| Unterbereich | Route | Kernfunktion |
|---|---|---|
| AntiSpam | `/admin/antispam` | Formular- und Kommentar-Spamschutz |
| Firewall | `/admin/firewall` | Regeln, Sperren, Filter |
| Audit | `/admin/security-audit` | Sicherheitsbewertung und Härtungshinweise |

### Wichtige Abhängigkeiten

- Öffentliche Formulare, Kommentare, Login
- Audit-/Log-System
- IP-/Pattern-Regelwerk

### Prüfen

- [x] AntiSpam-Regeln wirken auf alle relevanten öffentlichen Formulare.
- [x] Firewall-Regeln können erstellt, aktiviert und entfernt werden.
- [x] Security-Audit liefert plausible Befunde.
- [x] Kritische Aktionen sind geschützt und protokolliert.

### Must-haves

- [x] Keine Sicherheitsseite ohne Auth, CSRF und Logging.
- [x] Öffentliche Angriffspunkte sind mit AntiSpam/Firewall verknüpft.
- [x] Audit-Hinweise sind umsetzbar und technisch belastbar.

### Nice-to-haves

- [ ] Simulationsmodus für Firewall-Regeln.
- [ ] Alarmierung bei sicherheitsrelevanten Ereignissen.
- [ ] Sicherheitsbaseline / Härtungsprofil pro Umgebung.

### Audit-Stand – Sicherheit · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.626`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/antispam`, `/admin/firewall`, `/admin/security-audit`, öffentliche Kommentarformulare, öffentliche Kontaktformulare
- **Reproduziertes Fehlerbild:** Die Security-Checkliste und die AntiSpam-Doku versprachen Schutz für relevante öffentliche Formulare, aber die zentrale AntiSpam-Konfiguration wirkte zur Laufzeit nur im Kommentarpfad. `cms-contact` verarbeitete öffentliche Kontaktformulare mit eigener Honeypot-/Captcha-/Session-Rate-Limit-Logik und ignorierte globale AntiSpam-Regeln wie Mindestzeit, Linklimit, leere User-Agents und Blacklist. Das Security-Audit konnte deshalb nur die Kommentar-Runtime sicher bewerten, nicht den gesamten öffentlichen Formularpfad.
- **Umsetzung in diesem Durchlauf:** Ein neuer zentraler `AntispamService` wertet die globalen AntiSpam-Einstellungen jetzt einheitlich aus. `CommentService` und das Kontaktformular-Plugin `cms-contact` verwenden denselben Runtime-Pfad für Honeypot, Mindestzeit, User-Agent, Linklimit und Blacklist. Kontakt-Templates senden dafür zusätzlich einen Formular-Timestamp mit, und das Security-Audit erkennt aktive Kontaktformulare nun ebenfalls als Teil des zentralen AntiSpam-Vertrags.
- **Abhängige Bereiche:** `AntispamModule`, `CommentService`, `SecurityAuditModule`, `cms-contact/includes/class-frontend.php`, Kontaktformular-Templates, `DOC/core/SECURITY.md`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Firewall-Simulationsmodus, Alarmierung bei sicherheitsrelevanten Ereignissen, Sicherheitsbaseline/Härtungsprofil pro Umgebung
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/security/README.md`, `CMS/DOC/admin/security/ANTISPAM.md`, `CMS/DOC/admin/security/SECURITY-AUDIT.md`, `CMS/DOC/core/SECURITY.md`, `365CMS.DE-PLUGINS/cms-contact/README.md`

---

## 13. Plugins

### Unterbereiche

| Unterbereich | Route | Kernfunktion |
|---|---|---|
| Plugins verwalten | `/admin/plugins` | Plugin-Lifecycle verwalten |
| Marketplace | `/admin/plugin-marketplace` | neue Plugins installieren |
| Dynamische Plugin-Submenüs | `/admin/plugins/{plugin}/{submenu}` | plugin-spezifische Admin-Seiten |

### Wichtige Abhängigkeiten

- PluginManager / Lifecycle-Hooks
- `cms_admin_menu` Hook für Sidebar-Integration
- plugin-spezifische Module, Views und Datenbanken

### Prüfen

- [x] Aktivieren/Deaktivieren/Löschen funktioniert stabil.
- [x] Dynamische Plugin-Menüs erscheinen korrekt in der Sidebar.
- [x] Plugin-Seiten sind sauber in RBAC, CSRF und PRG eingebunden.
- [x] Cross-Plugin-Zugriffe prüfen vorab `PluginManager::isPluginActive()`.

### Must-haves

- [x] Keine Hardcodes für Plugin-Menüs in der Sidebar.
- [x] Plugin-Lifecycle darf das Adminpanel nicht destabilisieren.
- [x] Plugin-Hauptdateien und `update.json` müssen vollständig sein.

### Nice-to-haves

- [ ] Plugin-Abhängigkeitsanzeige.
- [ ] Health-Checks vor Aktivierung.
- [ ] Plugin-Konfigurations-Export.

### Zusätzliche Prüfschleife für **jedes** Plugin-Menü

- [x] Menü wird korrekt über Hook registriert.
- [x] Übersicht und Child-Menüs sind erreichbar.
- [x] Plugin-spezifische Rechteprüfung ist vorhanden.
- [x] Deaktiviertes Plugin hinterlässt keine defekten Sidebar-Einträge.
- [x] Plugin-Doku unter `365CMS.DE-PLUGINS/DOC/` ist aktuell.

### Audit-Stand – Plugins · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.627`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/plugins`, `/admin/plugin-marketplace`, `/admin/plugins/{plugin}/{submenu}`
- **Reproduziertes Fehlerbild:** Die Plugin-Doku beschreibt dynamische Plugin-Menüs korrekt über `cms_admin_menu` und die Admin-Registry, aber die Laufzeit war nicht request-idempotent. `AdminRouter::renderPluginPage()` und später die Sidebar führten `cms_admin_menu` im selben Request erneut aus; `add_menu_page()` und `add_submenu_page()` hängten dabei bestehende Top-Level- und Child-Menüs blind nochmals an. Plugin-Unterseiten konnten so aufgeblähte oder doppelte Sidebar-Einträge erzeugen und den Eindruck eines instabilen Plugin-Menüzustands hinterlassen.
- **Umsetzung in diesem Durchlauf:** Die Admin-Menü-Registry ersetzt vorhandene Plugin-Menüs und Child-Menüs mit gleichem Slug jetzt idempotent, statt sie bei wiederholter Hook-Ausführung doppelt anzulegen. Dadurch bleiben Plugin-Routing und Sidebar-Aufbau innerhalb desselben Requests stabil, ohne die dokumentierte Hook-basierte Menüintegration zu ändern.
- **Abhängige Bereiche:** `includes/functions/admin-menu.php`, `core/Routing/AdminRouter.php`, `admin/partials/sidebar.php`, `DOC/admin/PANEL-INTEGRATION.md`, `DOC/admin/plugins/PLUGINS.md`
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Plugin-Abhängigkeitsanzeige, Health-Checks vor Aktivierung, Plugin-Konfigurations-Export
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/PANEL-INTEGRATION.md`, `CMS/DOC/admin/plugins/PLUGINS.md`

---

## 14. System & Doku

### Unterbereiche

| Unterbereich | Route | Kernfunktion |
|---|---|---|
| Einstellungen | `/admin/settings` | zentrale Core-Einstellungen |
| Mail & Azure OAuth2 | `/admin/mail-settings` | Mailversand und OAuth2 |
| Module | `/admin/modules` | Core-Module schalten |
| Backup & Restore | `/admin/backups` | Sicherung und Wiederherstellung |
| Updates | `/admin/updates` | Core-/Theme-/Plugin-Updates |
| Dokumentation | `/admin/documentation` | lokale Dokuansicht |

### Wichtige Abhängigkeiten

- Settings-Speicher und Konfigurationsdateien
- Mail-/OAuth2-Provider
- Backup-/Update-Services
- Modul-Registry und Dokumentationsdateien

### Prüfen

- [x] Settings werden korrekt geladen und gespeichert.
- [x] Mail-Tests und OAuth2-Konfiguration liefern verständliche Rückmeldungen.
- [x] Modulschalter wirken nur auf vorgesehene Core-Module.
- [x] Backup-Erstellung, Liste, Download und Restore sind robust.
- [x] Update-Prüfung funktioniert für Core, Themes und Plugins.
- [x] Dokumentationsansicht zeigt vorhandene Doku ohne Pfadprobleme.

### Must-haves

- [x] Konfigurationsänderungen sind CSRF-geschützt und nachvollziehbar.
- [x] Backups und Updates laufen mit klaren Fehlermeldungen und Sperren/Locks.
- [x] Remote-Update-Prüfungen nutzen sichere TLS-/Host-Prüfung.

### Nice-to-haves

- [ ] Konfigurations-Diff vor dem Speichern.
- [ ] Backup-Validierung / Restore-Check im Trockentest.
- [ ] Update-Vorabprüfung auf Abhängigkeiten und Schreibrechte.

### Audit-Stand – System & Doku · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.628`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/backups`, `/admin/updates`, `/admin/documentation`, ergänzend Bereichsdoku `system-settings/*`
- **Reproduziertes Fehlerbild:** Der Bereich bewarb Backup & Restore sowie zentrale Core-/Theme-/Plugin-Updates, hielt diesen Vertrag aber nur teilweise. `/admin/updates` prüfte Theme-Updates bereits über den gesicherten Update-Service, bot im zentralen Update-Center aber keinen Theme-Installpfad an. Parallel listete `/admin/backups` vorhandene Sicherungen zwar auf, verdrahtete zur Laufzeit aber nur Erstellen und Löschen; Download und Wiederherstellung aus der Bereichsdoku waren im Admin nicht erreichbar.
- **Umsetzung in diesem Durchlauf:** Das Update-Center kann Theme-Updates jetzt direkt aus derselben abgesicherten staging-basierten Update-Infrastruktur installieren wie Core- und Plugin-Updates. Der Backup-Bereich unterstützt nun Download von Datenbank- und Datei-Artefakten sowie echte Restore-Aktionen; vor jeder Wiederherstellung wird automatisch ein Rollback-Snapshot erstellt, und Datei-Restores arbeiten mit Staging-/Rollback-Pfaden statt blindem Überschreiben.
- **Abhängige Bereiche:** `UpdateService`, `BackupService`, `BackupsModule`, `UpdatesModule`, Admin-System-Views, System-/Betriebsdoku
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Konfigurations-Diff, Restore-Trockentest, Update-Vorabprüfung auf Abhängigkeiten/Schreibrechte
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/system-settings/README.md`, `CMS/DOC/admin/system-settings/BACKUP.md`, `CMS/DOC/admin/system-settings/UPDATES.md`

---

## 15. Diagnose

### Unterbereiche

| Unterbereich | Route | Kernfunktion |
|---|---|---|
| Übersicht | `/admin/info` | Systeminformationen |
| Datenbank | `/admin/diagnose` | Datenbankdiagnose |
| Assets | `/admin/monitor-assets` | Asset-Prüfungen |
| Response-Time | `/admin/monitor-response-time` | Antwortzeiten und Trends |
| Cron-Job Status | `/admin/monitor-cron-status` | Cron-Laufzeiten und Status |
| Disk-Usage | `/admin/monitor-disk-usage` | Speicherverbrauch |
| Scheduled Tasks | `/admin/monitor-scheduled-tasks` | geplante Aufgaben |
| Health-Check | `/admin/monitor-health-check` | allgemeine Systemgesundheit |
| E-Mail-Benachrichtigungen | `/admin/monitor-email-alerts` | Alerts und Testversand |
| Logs & Protokolle | `/admin/cms-logs` | zentrale Logs und Protokolle |

### Wichtige Abhängigkeiten

- Datenbank, Dateisystem, CronRunner, MailQueue, Logger
- Monitoring-/Statistiksammler
- externe Dienste und Systemumgebung

### Prüfen

- [x] Alle Monitoring-Seiten laden ohne Timeouts oder harte Fehler.
- [x] Diagnose zeigt echte Systemdaten statt Platzhalter.
- [x] Cron-Status spiegelt die reale Ausführung wider.
- [x] Mail-Alerts können getestet werden.
- [x] Logs sind erreichbar, aber ausreichend geschützt.
- [x] Disk- und Health-Werte sind plausibel.

### Must-haves

- [x] Diagnosefunktionen dürfen selbst keine Gefahr für den Betrieb erzeugen.
- [x] Logs und Systeminfos nur für berechtigte Admins.
- [x] Cron- und Mail-Queue-Zustand nachvollziehbar, insbesondere vor/nach Hourly-Hooks.

### Nice-to-haves

- [ ] Trendhistorie für Response-Time, Cron und Speicherverbrauch.
- [ ] Export/Download für Diagnoseberichte.
- [ ] Sammelansicht für kritische Systemwarnungen.

### Audit-Stand – Diagnose · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.629`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/diagnose`, `/admin/monitor-response-time`, `/admin/monitor-cron-status`, `/admin/monitor-health-check`, `/admin/monitor-email-alerts`, `/admin/cms-logs`
- **Reproduziertes Fehlerbild:** Der Diagnosebereich war weitgehend auf eine gemeinsame Monitoring-Shell umgestellt, ließ aber beim Health-Check einen dekorativen Rest zurück: Der Eintrag „Health-Endpunkt“ bewertete nicht die echte Erreichbarkeit des konfigurierten Endpunkts, sondern nur, ob in den Alert-Einstellungen der Schalter `monitor_health_endpoint_enabled` gesetzt war. Damit konnten Diagnose und Bereichsdoku einen plausiblen Health-Wert anzeigen, obwohl der Endpunkt selbst fehlerhaft, falsch konfiguriert oder gar nicht erreichbar war.
- **Umsetzung in diesem Durchlauf:** `SystemInfoModule` normalisiert den konfigurierten Health-Pfad jetzt host-lokal auf relative Site-Pfade und prüft den Endpunkt real per HTTP gegen die eigene Installation. Der Health-Check zeigt dadurch Status, Laufzeit und Fehler des tatsächlichen Endpunkts statt bloß einer gespeicherten Markierung; die Monitoring-Einstellungen dokumentieren diesen Vertrag nun auch explizit als echte Prüfung lokaler Pfade.
- **Abhängige Bereiche:** `SystemInfoModule`, Monitoring-Views, `CMS\Http\Client`, Diagnose-/Systemdoku
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Trendhistorie, Diagnose-Export, Sammelansicht kritischer Warnungen
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/diagnose/README.md`, `CMS/DOC/admin/diagnose/DIAGNOSE.md`, `CMS/DOC/admin/system-settings/SYSTEM.md`

---

## Cross-Bereichs-Abhängigkeiten, die immer mitzuprüfen sind

### Inhalte ↔ Medien ↔ SEO

- [ ] Featured Images werden korrekt hochgeladen, verschoben und im Inhalt referenziert.
- [ ] SEO-Felder und globale Templates ergänzen sich ohne Konflikte.
- [ ] Kategorie-/Tag-Filter und Redirects bleiben intakt.

### Benutzer ↔ Rollen ↔ Gruppen ↔ Member Dashboard

- [ ] Rollenänderungen wirken korrekt im Admin und im Member-Bereich.
- [ ] Gruppen- und Paketbezüge beeinflussen Member-Funktionen nachvollziehbar.
- [ ] Profilfelder und Auth-Settings bleiben kompatibel.

### System ↔ Diagnose ↔ Performance

- [x] Caches, Sessions, Cron und Logs greifen sichtbar ineinander.
- [x] Diagnose zeigt die Effekte von Performance- oder Systemänderungen.
- [x] Backups/Updates werden in Monitoring und Logs nachvollziehbar.

### Audit-Stand – Cross-Checks · System ↔ Diagnose ↔ Performance · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Vertragsbasis · Release `2.9.630`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Routen:** `/admin/cms-logs`, `/admin/backups`, `/admin/updates`, `/admin/performance`, `/admin/performance-cache`, `/admin/performance-media`, `/admin/performance-database`, `/admin/performance-settings`, `/admin/performance-sessions`
- **Reproduziertes Fehlerbild:** System-, Backup- und Performance-Aktionen wurden bereits sauber im zentralen `audit_log` protokolliert, und erfolgreiche Updates erzeugten zusätzlich eine persistierte Update-Historie. Die Diagnose-Logzentrale unter `/admin/cms-logs` zeigte davon zur Laufzeit aber fast nur Dateilogs, PHP-Error-Log und `admin.documentation`; dadurch blieben Cache-/Session-/Cron-/Backup-/Performance-Effekte im Diagnosepfad nur indirekt oder gar nicht sichtbar, obwohl die Prüfliste genau diese bereichsübergreifende Nachvollziehbarkeit fordert.
- **Umsetzung in diesem Durchlauf:** `/admin/cms-logs` bindet jetzt ein operatives Betriebs-Audit aus dem zentralen `audit_log` ein und gruppiert Einträge für System, Backups, Updates, Performance, Monitoring, Cron/Queue und Log-Aktionen direkt in der Diagnoseoberfläche. Zusätzlich spiegelt dieselbe Seite die persistierte Update-Historie des Update-Services, sodass erfolgreiche Update-Läufe nicht nur auf der Update-Seite, sondern auch im Diagnose-/Logkontext nachvollziehbar bleiben. Damit zeigt Diagnose die Effekte von Cache-, Session-, Cron-, Backup-, Performance- und Update-Aktionen endlich im echten Betriebsjournal statt nur über verstreute Teilansichten.
- **Abhängige Bereiche:** `SystemInfoModule`, `cms-logs.php`, `AuditLogger`, `UpdateService`, `PerformanceModule`, `BackupsModule`, Diagnose-/System-/Performance-Doku
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** Trendhistorie für Response-Time/Cron/Speicher, Diagnose-Export, Sammelansicht kritischer Warnungen
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/diagnose/README.md`, `CMS/DOC/admin/diagnose/DIAGNOSE.md`, `CMS/DOC/admin/system-settings/README.md`, `CMS/DOC/admin/system-settings/SYSTEM.md`, `CMS/DOC/admin/performance/PERFORMANCE.md`

### Audit-Stand – Review/Nachprüfung · Gesamtstand der letzten Admin-Anpassungen · Durchlauf 1

- **Status:** abgeschlossen auf Code-/Best-Practice-/Vertragsbasis · Release `2.9.631`
- **Prüfer:** GitHub Copilot
- **Datum:** 2026-05-09
- **Geprüfte Artefakte:** `CMS/core/Services/UpdateService.php`, `Changelog.md`, `CMS/DOC/admin/README.md`, `/admin/cms-logs`-bezogene Update-Historie
- **Reproduziertes Fehlerbild:** Die neue Diagnose-Update-Historie hing an einer PDO-Abfrage mit `LIMIT ?` plus `execute([$limit])`, obwohl diese Bindung laut PHP-Dokumentation standardmäßig als String behandelt wird und bei nativen MySQL-Prepares zu still leeren Ergebnissen führen kann. Zusätzlich verwendete `UpdateService::logUpdate()` nur `time()` als Schlüsselbasis, wodurch mehrere Update-Ereignisse derselben Sekunde einander überschreiben konnten. Parallel blieb nach `2.9.630` eine Versionsdrift in `Changelog.md` und `CMS/DOC/admin/README.md` zurück.
- **Umsetzung in diesem Durchlauf:** Die Update-History-Abfrage bindet das Limit jetzt explizit als Integer und liest nur die benötigte Nutzlast. Persistierte `update_log_*`-Einträge erhalten kollisionsarme, zeitlich sortierbare Schlüssel mit Zeitstempel, Mikrosekunden und Kurzsuffix, sodass schnelle Update-Folgen nicht mehr zusammenfallen. Außerdem wurden die bei der Nachprüfung gefundenen Versionsdrifts in Changelog und Admin-Übersicht bereinigt.
- **Abhängige Bereiche:** `UpdateService`, Diagnose-Logzentrale `/admin/cms-logs`, zentrale Release-/Admin-Doku
- **Offene Must-haves:** keine
- **Offene Nice-to-haves:** sprechende Benutzeranzeige statt User-ID in der Update-Historie, optionaler Diagnose-Export
- **Doku aktualisiert:** `Changelog.md`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`

### Plugins ↔ alle anderen Bereiche

- [ ] Aktivierte Plugins erweitern Menüs und Funktionen sauber.
- [ ] Deaktivierte Plugins hinterlassen keine defekten UI- oder Routing-Reste.
- [ ] Cross-Plugin-Integrationen sind abgesichert.

---

## Abschluss pro geprüftem Bereich

Nach jedem Hauptbereich dokumentieren:

- [ ] Verantwortlicher / Prüfer
- [ ] Datum
- [ ] geprüfte Routes
- [ ] reproduzierte Fehler
- [ ] offene Must-haves
- [ ] offene Nice-to-haves
- [ ] Doku aktualisiert
- [ ] Follow-up-Tickets angelegt

---

## Zugehörige Fachdokumente

- `CMS/DOC/admin/README.md`
- `CMS/DOC/admin/FILESTRUCTURE.md`
- `CMS/DOC/admin/GUIDE.md`
- `CMS/DOC/admin/pages-posts/README.md`
- `CMS/DOC/admin/media/README.md`
- `CMS/DOC/admin/users-groups/README.md`
- `CMS/DOC/admin/member/README.md`
- `CMS/DOC/admin/subscription/README.md`
- `CMS/DOC/admin/themes-design/README.md`
- `CMS/DOC/admin/seo/README.md`
- `CMS/DOC/admin/performance/README.md`
- `CMS/DOC/admin/legal/README.md`
- `CMS/DOC/admin/security/README.md`
- `CMS/DOC/admin/system-settings/README.md`
- `CMS/DOC/admin/diagnose/README.md`

Diese Datei ist bewusst als **laufende Arbeitscheckliste** formuliert. Sie soll nicht nur beschreiben, was da ist, sondern auch festhalten, **was verbindlich geprüft werden muss** und **welche sinnvollen Ausbaustufen** noch eingeplant werden können.
