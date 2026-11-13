# 365CMS – Projektdokumentation | Abschnitt: Folgeaudit 2026-11-12

> **Stand:** 2026-11-12 | **Version:** 4.0.0 | **Prüfumfang:** `CMS/` ohne `CMS/vendor/` und gebündelte Drittbibliotheken unter `CMS/assets/` (dort nur Versionen und bekannte Schwachstellen), Theme `cms-default`, Plugin `cms-importer`, `TESTS/`, `Changelog.md` | Übersicht: [README.md](README.md) | Vorheriges Audit: [FOLGEAUDIT-2026-11-09.md](FOLGEAUDIT-2026-11-09.md)

## English (summary)

Code, security and architecture audit of the 4.0.0 code base. The work is split into seven pull requests, one per topic. This file is the audit log: every pull request adds its findings here and sets their status.

1. Changelog: review and rewrite of the `4.0.0` section, initial version of this file.
2. Security and known exploits (OWASP Top 10, dependencies).
3. Missing functions and incomplete logic.
4. Missing or wrong references (includes, assets, routes, database schema).
5. Wrong functions and outdated PHP/SQL constructs.
6. Performance (PHP, MySQL/MariaDB, front end).
7. Best practices, code quality, configuration and final check.

All changes are documented under the existing `4.0.0` heading in `Changelog.md`; no new version number is assigned.

## Deutsch

### Aufbau und Arbeitsweise

- **Ein Thema, ein Pull Request.** Jeder Prüfbereich wird in einem eigenen Branch bearbeitet. Die Branches bauen aufeinander auf, weil jeder PR diese Datei und `Changelog.md` fortschreibt.
- **Version.** Alle Änderungen stehen unter `4.0.0` in `Changelog.md`. Eine neue Versionsnummer wird nicht vergeben.
- **IDs.** Die Nummerierung setzt die bisherigen Audits fort: `SEC` (Sicherheit) ab 49, `DEP` (Bibliotheken) ab 05, `FUN` (Funktionen, Logik) ab 63, `REF` (Verweise) ab 16, `BP` (Best Practice, Code-Qualität) ab 11, `PERF` (Geschwindigkeit) ab 23. Neu ist `CL` für Befunde am Changelog.
- **Schwere:** 🔴 hoch · 🟠 mittel · 🟡 niedrig · ⚪ Hinweis.
- **Status:** `[ ] Offen` → `[x] Behoben in PR #…`. Befunde, die nach Prüfung nicht zutreffen, stehen je Bereich unter *Kein Befund nach Prüfung*.
- **Fundstellen** nennen Dateipfad und Zeile auf dem Stand vor der Korrektur.

### Übersicht der Prüfbereiche

| Nr. | Prüfbereich | IDs | Pull Request | Status |
|---|---|---|---|---|
| 1 | Changelog 4.0.0, Anlage dieser Datei | CL-001 … CL-233 | #119 (Grundgerüst), #120 (Changelog) | erledigt |
| 2 | Sicherheit und bekannte Exploits | SEC, DEP | PR 2 | offen |
| 3 | Fehlende Funktionen und unvollständige Logik | FUN | PR 3 | offen |
| 4 | Fehlende oder falsche Verweise | REF | PR 4 | offen |
| 5 | Falsche Funktionen, veraltete PHP-/SQL-Konstrukte | FUN, BP | PR 5 | offen |
| 6 | Geschwindigkeit (PHP, MySQL/MariaDB, Frontend) | PERF | PR 6 | offen |
| 7 | Best Practices, Code-Qualität, Abschlussprüfung | BP | PR 7 | offen |

### 1. Changelog 4.0.0

**Prüfumfang:** `Changelog.md`, Abschnitte `3.5.0` bis `4.0.0`; überarbeitet wurde nur `4.0.0` (vorher 114 Einträge in den Zeilen 21–134, Stand `f96a12f`).

**Vorgehen**

1. Jeder der 114 Einträge wurde gegen den Code des Stands 4.0.0 geprüft: Routen, Klassen, Optionen, Konstanten, Grenzwerte, Zahlen.
2. Bei Einträgen der Typen `fix` und `security` wurde am Stand 3.5.6 (`1c3cba4`) geprüft, ob der Fehler dort bestand oder nur im unveröffentlichten Entwicklungsstand von 4.0.0.
3. Die 68 Commits seit 3.5.6 (ohne Merges) wurden auf Änderungen durchgesehen, die im Abschnitt fehlten.
4. Der Abschnitt wurde neu gegliedert und geschrieben, jeder Teil danach erneut gegen Code und Prüfergebnisse gegengeprüft (60 Befunde der Gegenprüfung, alle eingearbeitet).

**Ergebnis der Prüfung der alten Einträge:** 52 korrekt, 47 ungenau, 9 redundant, 3 veraltet, 3 falsch. Zehn Einträge beschrieben Korrekturen an Code, der erst während der Entwicklung von 4.0.0 entstand.

**Neuer Aufbau des Abschnitts**

- Einleitung und *Hinweise zum Update von 3.5.x* (zehn Punkte für Betreiber und Entwickler)
- vier Tabellen nach Typ: Neue Funktionen (47 Einträge), Sicherheit (23), Fehlerbehebungen (27), Performance/Umbauten/Dokumentation/Wartung (11)
- einheitliche Bereichsnamen nach dem Schema „Hauptbereich / Teilbereich“
- Routen statt Menüpfaden, weil die Menüpfade je Menü-Profil (Klassisch, Strukturiert) abweichen
- Korrekturen an nie veröffentlichtem Code stehen im jeweiligen Funktions-Eintrag oder entfallen (alte Zeilen 33, 41, 101, 123 ohne Nachfolger)
- Audit-IDs (SEC, DEP, FUN, BP, OFFEN) der alten Einträge sind vollständig erhalten

**Befunde nach Art**

| Art | Schwere | Anzahl |
|---|---|---|
| Faktenfehler | 🟠 | 57 |
| Fehlende Angabe | 🟠 | 60 |
| Veraltete Aussage | 🟡 | 14 |
| Falsche Kategorie | 🟡 | 9 |
| Korrektur an nie veröffentlichtem Code | 🟡 | 8 |
| Redundanz | 🟡 | 21 |
| Unklare Formulierung | ⚪ | 46 |
| Überlänge | ⚪ | 18 |
| **Summe** | | **233** |

**Befunde**

Die Fundstelle nennt die Zeilen von `Changelog.md` vor der Überarbeitung (Stand `f96a12f`). „Kein Eintrag“ heißt: Die Änderung war im Code vorhanden, im Changelog aber nicht beschrieben.

| ID | Fundstelle | Schwere | Beschreibung | Status |
|---|---|---|---|---|
| CL-001 | `Changelog.md:23` | 🟠 | Faktenfehler: Der Menüpfad „Inhalt → Newsletter“ gilt nur im Profil „Strukturiert“ (Klassisch: „Formulare → Newsletter“); genannt wird nur noch die Route `/admin/newsletter`. | [x] Behoben in PR #120 |
| CL-002 | `Changelog.md:24` | 🟠 | Faktenfehler: Der Menüpfad „Inhalt → Knowledgebase“ gilt nur im Profil „Strukturiert“ (Klassisch: „Seiten → Knowledgebase“); genannt wird nur noch die Route `/admin/knowledgebase`. | [x] Behoben in PR #120 |
| CL-003 | `Changelog.md:26, 74` | 🟠 | Faktenfehler: Zeile 74 nannte den Bestätigungslink „einmalig“, während am HEAD bis zu fünf Links gleichzeitig 48 Stunden gültig sind; der Widerspruch ist aufgelöst und der Endzustand (bereits-bestätigt-Meldung, Direktversand mit Warteschlange als Rückfall, höchstens 3 neue Links je Stunde) in einem Eintrag zusammengeführt. | [x] Behoben in PR #120 |
| CL-004 | `Changelog.md:30` | 🟠 | Faktenfehler: „MariaDB 10.11.14“ war die Version des Entwicklungssystems und ist jetzt als Beispiel („z. B. MariaDB 10.11.8“, Produkt und Version) formuliert; der Tooltip mit der vollständigen Kennung ist ergänzt. | [x] Behoben in PR #120 |
| CL-005 | `Changelog.md:30` | 🟠 | Faktenfehler: Die nicht verifizierte Erscheinung „halber Kreis“ ist gestrichen; beschrieben ist die belegte Ursache (feste Maße für alle `.form-check-input`, entferntes Hintergrundbild), und die Vorschaubild-Dateinamen sind benannt. | [x] Behoben in PR #120 |
| CL-006 | `Changelog.md:31` | 🟠 | Faktenfehler: Die Aussage, die Schalter für Urheberangaben hätten „bisher keine Oberfläche“ gehabt, war falsch, weil die Funktion in 3.5.6 nicht existierte; sie ist gestrichen und durch einen Verweis auf den neuen Eintrag ersetzt. | [x] Behoben in PR #120 |
| CL-007 | `Changelog.md:34` | 🟠 | Faktenfehler: Der Menüpfad „System → Werkzeuge → Import/Export“ existiert in keinem Profil; ersetzt durch Route und Capability `import_export`, Bereich in „Inhalte / Import und Export“ vereinheitlicht. | [x] Behoben in PR #120 |
| CL-008 | `Changelog.md:35` | 🟠 | Faktenfehler: „Status jeder Inhaltssprache“ war zu weit gefasst (verfolgt wird nur die englische Fassung) und der Menüpfad „Inhalte → Übersetzungen“ existiert nicht; korrigiert, Capability `manage_translations` ergänzt und die KI-Aktion präzisiert (öffnet den Editor mit Vorschau, speichert nichts automatisch). | [x] Behoben in PR #120 |
| CL-009 | `Changelog.md:36` | 🟠 | Faktenfehler: Der Menüpfad „Inhalte → Letzte Änderungen“ existiert in keinem Menü-Profil; ersetzt durch Route und Capabilities (`edit_all_posts` oder `manage_pages`), Doku-Verweis ergänzt. | [x] Behoben in PR #120 |
| CL-010 | `Changelog.md:37` | 🟠 | Faktenfehler: Der Menüpfad „Werkzeuge → Papierkorb“ gilt nur im Profil „Klassisch“; genannt werden jetzt nur Route `/admin/trash` und Capability `manage_trash`. | [x] Behoben in PR #120 |
| CL-011 | `Changelog.md:39` | 🟠 | Faktenfehler: Die Aussage, die Einstellung gelte für die Passwort-Zurücksetzung, war ungenau; dort wird der Wert gedeckelt übernommen (höchstens 10 Versuche, Fenster mindestens 15 Minuten), und die Einstellung betrifft das IP-bezogene Limit, nicht das Limit je Konto. | [x] Behoben in PR #120 |
| CL-012 | `Changelog.md:40` | 🟠 | Faktenfehler: „Theme-Fehlerseiten zeigten immer 500“ war zu weit gefasst; betroffen waren nur Statuscodes ohne eigenes Theme-Template (403, 429, 503 …), nicht 404. | [x] Behoben in PR #120 |
| CL-013 | `Changelog.md:42` | 🟠 | Faktenfehler: `/feeds` wurde als feste Route dargestellt, ist aber nur der Standardwert von `archive_slug`; die Routen stehen jetzt als `/<archiv-slug>…` mit fester Route `/feed/<bereich>`. | [x] Behoben in PR #120 |
| CL-014 | `Changelog.md:45, 134` | 🟠 | Faktenfehler: Z. 134 nannte für `v25` nur `admin_menu_layouts` und `batch_jobs`, Z. 45 nur drei Tabellengruppen; tatsächlich legt `v25` 32 neue Tabellen an (Abgleich der `CREATE TABLE`-Definitionen 1c3cba4 ↔ HEAD: 44 → 76), jetzt vollständig und gruppiert genannt. | [x] Behoben in PR #120 |
| CL-015 | `Changelog.md:46` | 🟠 | Faktenfehler: „Bei jedem Aufruf“ war zu pauschal; die Meldung entstand nur unter PHP 8.5 bei jedem Verbindungsaufbau, und `Pdo\Mysql::ATTR_*` ist als ab PHP 8.4 verfügbar gekennzeichnet. | [x] Behoben in PR #120 |
| CL-016 | `Changelog.md:47` | 🟠 | Faktenfehler: Die Aussage, Kategorie-Feeds berücksichtigten Unterkategorien, ist in `FeedService.php` nicht belegt (kein Eltern-/Kind-Bezug im Code) und wurde gestrichen; ergänzt wurden die 400-Fälle (Kategorie und Tag zugleich, ungültiger Slug), 404 bei abgeschaltetem Feed und die `<link rel=alternate>`-Ausgabe von `cms-default`. | [x] Behoben in PR #120 |
| CL-017 | `Changelog.md:49` | 🟠 | Faktenfehler: Das Ersetzen einer aktiven MFA betraf nur den Mitgliederbereich bzw. den direkten POST auf `/mfa-setup`, nicht die GET-Seite; der Text unterscheidet das jetzt. | [x] Behoben in PR #120 |
| CL-018 | `Changelog.md:62` | 🟠 | Faktenfehler: „nur vollständige Backups mit passenden Prüfsummen“ war zu weit gefasst: Geprüft werden nur im Manifest hinterlegte Prüfsummen, ältere Einzel-Dumps ohne Manifest werden ohne diese Prüfung eingespielt – so jetzt beschrieben. | [x] Behoben in PR #120 |
| CL-019 | `Changelog.md:63` | 🟠 | Faktenfehler: Die Pauschalaussage „zu keinem Zeitpunkt für andere lokale Konten lesbar“ wurde gestrichen und auf Kopien aus dem Speichern der Einstellungen eingegrenzt, da der Installer seine Kopie weiterhin per `copy()` mit Standardrechten anlegt. | [x] Behoben in PR #120 |
| CL-020 | `Changelog.md:64` | 🟠 | Faktenfehler: „Quellarchive unter `CMS_ASSETS/` ersetzt“ galt nur für die drei Symfony-Pakete (für DOMPurify liegt dort kein Archiv) und wurde entsprechend eingegrenzt. | [x] Behoben in PR #120 |
| CL-021 | `Changelog.md:67` | 🟠 | Faktenfehler: Die Schaltfläche heißt im Mitgliederbereich „Überall sonst abmelden“ und nur im Admin „Überall abmelden“; die Menüpfade wurden durch `/member/security` bzw. das Bearbeiten-Formular des Benutzers ersetzt. | [x] Behoben in PR #120 |
| CL-022 | `Changelog.md:67` | 🟠 | Faktenfehler: „Die eigene Sitzung bleibt angemeldet“ gilt nur für das eigene Konto; widerruft ein Administrator ein fremdes Konto, enden dort alle Sitzungen – so jetzt beschrieben. | [x] Behoben in PR #120 |
| CL-023 | `Changelog.md:72` | 🟠 | Faktenfehler: Der Doku-Pfad `INSTALLATION.md` ist auf `DOC/INSTALLATION.md` korrigiert und der profilabhängige Menüpfad *Einstellungen → Allgemein* durch „Speichern der allgemeinen Einstellungen“ ersetzt. | [x] Behoben in PR #120 |
| CL-024 | `Changelog.md:76` | 🟠 | Faktenfehler: Der Menüpfad „System → Updates“ existiert in 4.0.0 nicht; genannt wird jetzt die Seite „Updates“ mit der Route `/admin/updates`. | [x] Behoben in PR #120 |
| CL-025 | `Changelog.md:78` | 🟠 | Faktenfehler: Die Schwellen 7/30 Tage lasen sich als Neuerung, galten aber schon in 3.5.6, und sind jetzt als unverändert gekennzeichnet; „ältere .sql-Dumps“ ist auf das tatsächlich erkannte Muster `database_<Zeitstempel>.sql(.gz)` präzisiert. | [x] Behoben in PR #120 |
| CL-026 | `Changelog.md:79` | 🟠 | Faktenfehler: Menüpfad „System → Backup & Restore“ und die Beschriftungen „Wiederherstellen“/„Trockenlauf“ stimmten nicht; jetzt Seite „Backup & Restore“ (`/admin/backups`) und die tatsächlichen Schaltflächen „Prüfen“, „Dry-Run“, „Restore“. | [x] Behoben in PR #120 |
| CL-027 | `Changelog.md:80` | 🟠 | Faktenfehler: Der Protokolleintrag wurde nicht überschrieben; tatsächlich ging der Eintrag des zweiten Backups verloren (gleicher Schlüssel), und das neue Schlüsselformat `backup_log_<Zeit>_<Zufall>` ist ergänzt. | [x] Behoben in PR #120 |
| CL-028 | `Changelog.md:84` | 🟠 | Faktenfehler: Die Antwort mit 500 statt 403 war als Tatsache formuliert, ist aber nicht nachgestellt; sie steht jetzt als mögliche Folge („konnte“). | [x] Behoben in PR #120 |
| CL-029 | `Changelog.md:85` | 🟠 | Faktenfehler: Der Menüpfad *Diagnose → Übersicht* existiert so nicht (das ist nur der Seitentitel) und ist durch die Systeminfo (`/admin/info`, „Fehlversuche heute“) ersetzt; *Performance → Datenbank* ist durch die Route `/admin/performance-database` mit der Anzeige „Fehlgeschlagene Logins (24h)“ ersetzt. | [x] Behoben in PR #120 |
| CL-030 | `Changelog.md:86` | 🟠 | Faktenfehler: Der Index verweist nur bei vorhandenen englischen Seiten oder Beiträgen auf `/en-pages.xml` bzw. `/en-posts.xml`; die Bedingung ist ergänzt. | [x] Behoben in PR #120 |
| CL-031 | `Changelog.md:87` | 🟠 | Faktenfehler: Die Seite heißt „Sitemap & robots.txt“ (`/admin/seo-sitemap`), das SEO-Dashboard zeigte „Nicht vorhanden“ statt „fehlt“ und zeigt jetzt einen Hinweistext; die fünf Statuswerte gelten nur für die Sitemap-Seite, „fehlt“ bleibt bei Generierungsfehlern. | [x] Behoben in PR #120 |
| CL-032 | `Changelog.md:88` | 🟠 | Faktenfehler: Die Schaltfläche heißt „robots.txt schreiben“, nicht „speichern“; das Neuschreiben ist ausdrücklich auf das Speichern der Einstellungen (`/admin/settings`) bezogen, und die drei Korrekturen stehen in getrennten Sätzen. | [x] Behoben in PR #120 |
| CL-033 | `Changelog.md:89` | 🟠 | Faktenfehler: Die Aussage, IPv6-Adressen in eckigen Klammern seien „immer“ blockiert worden, ist nicht verifiziert; „immer“ wurde gestrichen. | [x] Behoben in PR #120 |
| CL-034 | `Changelog.md:92` | 🟠 | Faktenfehler: Die alte Erkennung über `HTTPS` und `X-Forwarded-Proto` galt nur für das Dashboard; ergänzt ist, dass `StatusService` in 3.5.6 nur `$_SERVER['HTTPS']` prüfte. | [x] Behoben in PR #120 |
| CL-035 | `Changelog.md:94` | 🟠 | Faktenfehler: Die Bestätigungsmails waren als behobener Fehler genannt, sind aber neu in 4.0.0; gegenüber 3.5.6 war nur die Passwort-Reset-Mail betroffen, der Eintrag nennt nur noch diese. | [x] Behoben in PR #120 |
| CL-036 | `Changelog.md:96` | 🟠 | Faktenfehler: „(Standard nach der Installation)“ war falsch, weil Core-Module standardmäßig aktiv sind, und ist gestrichen; „ausgeblendet“ ist präzisiert: Favoriten werden entfernt, bei Hinweisen und Widgets entfällt nur der Link. | [x] Behoben in PR #120 |
| CL-037 | `Changelog.md:97` | 🟠 | Faktenfehler: Die behaupteten „PHP-Warnungen“ sind nicht nachgestellt und wurden gestrichen; der Eintrag steht jetzt in ganzen Sätzen. | [x] Behoben in PR #120 |
| CL-038 | `Changelog.md:99` | 🟠 | Faktenfehler: „Unter 768 px“ ist zu „bis 768 px Breite“ korrigiert (Media-Query `max-width: 768px`). | [x] Behoben in PR #120 |
| CL-039 | `Changelog.md:103` | 🟠 | Faktenfehler: Die nicht gemessene Angabe „rund 200 px“ ist gestrichen; beschrieben ist nur, dass der unsichtbare Hinweis Platz vor dem Titel belegte. | [x] Behoben in PR #120 |
| CL-040 | `Changelog.md:104` | 🟠 | Faktenfehler: „35 von 80 Änderungen“ stammt nur aus einer Commit-Message und ist gestrichen; stattdessen ist die Ursache genannt (Lesen und Schreiben ohne Sperre). | [x] Behoben in PR #120 |
| CL-041 | `Changelog.md:105` | 🟠 | Faktenfehler: „Dunklere Seitenleiste“ war gegenüber 3.5.6 irreführend (dort dunkelblau, 240 px); jetzt steht hellgraue Seitenleiste mit 264 px bzw. 76 px eingeklappt samt Vergleich zu 3.5.6. | [x] Behoben in PR #120 |
| CL-042 | `Changelog.md:105` | 🟠 | Faktenfehler: Die Akzentfarbe je Site kommt nicht aus `<html data-site>`, sondern aus einem Inline-Style mit Nonce (`--cms-accent` u. a.); `data-site` trägt nur den Site-Schlüssel. | [x] Behoben in PR #120 |
| CL-043 | `Changelog.md:106` | 🟠 | Faktenfehler: „Zuletzt besucht“ heißt in der Oberfläche „Zuletzt genutzt“ und wird nicht mehr als neue Funktion dargestellt. | [x] Behoben in PR #120 |
| CL-044 | `Changelog.md:106` | 🟠 | Faktenfehler: Die Aktionsliste von `/admin/admin-ui` war unvollständig und pauschal „nur Admins“; jetzt GET `notices` (`manage_system`) und `token`, POST `prefs`, `switch_site`, `cache_clear`, `maintenance` mit CSRF-Token. | [x] Behoben in PR #120 |
| CL-045 | `Changelog.md:107` | 🟠 | Faktenfehler: Der Menüpfad *Einstellungen › Plugins › Plugin-Zuweisung* existiert nicht; der Eintrag nennt nur noch die Route `/admin/plugin-assignment` und steht unter „Admin / Plugin-Zuweisung“. | [x] Behoben in PR #120 |
| CL-046 | `Changelog.md:109` | 🟠 | Faktenfehler: Profilbeschreibung korrigiert: Klassisch zeigt auch Kommentare direkt, und in beiden Profilen ist „Mitgliederbereich“ eine Kachelseite. | [x] Behoben in PR #120 |
| CL-047 | `Changelog.md:109` | 🟠 | Faktenfehler: Die widersprüchliche Aussage, Plugin-Einträge landeten auch in Basisprofil- oder Auffanggruppe, wurde entfernt; Plugin-Seiten stehen ausschließlich im Abschnitt „Aktive Plugins“ (Eintrag „Admin / Seitenleiste“). | [x] Behoben in PR #120 |
| CL-048 | `Changelog.md:111` | 🟠 | Faktenfehler: 404 gilt nicht nur für unbekannte Gruppen, sondern auch für Gruppen ohne Kachelmodus oder ohne sichtbare Einträge; ergänzt wurden Kachelseiten für „Mitgliederbereich“ und eigene Kachelgruppen. | [x] Behoben in PR #120 |
| CL-049 | `Changelog.md:115` | 🟠 | Faktenfehler: „Erweiterungs-Tabs in `/admin/settings` (Dateien unter `admin/settings-tabs/`)“ ist kein Erweiterungspunkt, sondern eine feste Liste von Core-Tabs; die Angabe ist entfernt. | [x] Behoben in PR #120 |
| CL-050 | `Changelog.md:116` | 🟠 | Faktenfehler: Der Eintrag nannte zwölf neue Capabilities, es sind 14; `manage_knowledgebase` und `manage_newsletter` sind ergänzt, samt Hinweis, dass sie in keiner Standardrolle stehen und andere Rollen eine Zuweisung unter `/admin/roles` brauchen. | [x] Behoben in PR #120 |
| CL-051 | `Changelog.md:118` | 🟠 | Faktenfehler: Der Marketplace-Eintrag nannte drei statt fünf Plugins ohne Versionsnummer im Katalog und die Kachel „Nur manuell“ statt „Nur manuell / Anfrage“; beides wurde korrigiert und um die Katalogfelder `integrated_in_core: 4.0.0` und `install_supported: false` ergänzt (Filterbezeichnung „Im Core enthalten“ am Sprachschlüssel `admin.marketplace.integrated.filter` bestätigt). | [x] Behoben in PR #120 |
| CL-052 | `Changelog.md:120` | 🟠 | Faktenfehler: Das Feld heißt in der Oberfläche „SEO-Beschreibung“, nicht „Meta-Beschreibung“; die Schreibweise wurde auf „Editor.js“ vereinheitlicht. | [x] Behoben in PR #120 |
| CL-053 | `Changelog.md:122` | 🟠 | Faktenfehler: Revisionen wurden als neu dargestellt, obwohl 3.5.6 sie bereits mit Feldvergleich hatte; der Eintrag beschreibt jetzt den Ausbau (Autosave, Obergrenze, Fingerabdruck, Blockvergleich, Wiederherstellen, API). | [x] Behoben in PR #120 |
| CL-054 | `Changelog.md:125` | 🟠 | Faktenfehler: Die Optionsangabe `cron.last_run_*` war für den Fehlertext falsch; jetzt `cron.last_run_at`, `cron.last_run_status`, `cron.last_run_task` und `cron.last_error`. | [x] Behoben in PR #120 |
| CL-055 | `Changelog.md:132` | 🟠 | Faktenfehler: `RBAC.md` stand ohne Pfad (die Datei liegt unter `DOC/admin/users-groups/RBAC.md`) und „sieben neue Services“ deckt sich nicht mit `DOC/core/SERVICES.md`; Pfad korrigiert, Zählung gestrichen. | [x] Behoben in PR #120 |
| CL-056 | `Changelog.md:133` | 🟠 | Faktenfehler: Die Zahl 902 war falsch: `admin-menu` meldet am HEAD 917 Prüfungen; genannt wird der Stand des Releases mit 984 Prüfungen über vier Suiten (917, 35, 9, 23), per `php TESTS/run-all.php` nachgeprüft. | [x] Behoben in PR #120 |
| CL-057 | `Changelog.md:133` | 🟠 | Faktenfehler: Der Workflow `security.yml` startet nicht `TESTS/admin-menu/run.php`, sondern `TESTS/run-all.php` (Zeile 73); korrigiert. | [x] Behoben in PR #120 |
| CL-058 | `Changelog.md:21` | 🟠 | Fehlende Angabe: In der Formularverwaltung fehlten Listenfunktionen (Statusfilter, Suche, Duplizieren, Papierkorb), Sammelaktionen und Status „neu → gelesen“ der Eingänge, das Menü-Badge sowie die Capabilities `manage_forms` und `view_form_submissions` samt Standardzuweisung für Redakteure (roles.php); sie wurden ergänzt. | [x] Behoben in PR #120 |
| CL-059 | `Changelog.md:21` | 🟠 | Fehlende Angabe: Im Formular-Eintrag fehlten die öffentlichen Seiten `/contact`, `/kontakt`, `/contact/<schlüssel>` (mit 301 von `/kontakt/<schlüssel>`), die Theme-Schnittstelle `contact.php` mit ihren Variablen, die Spam-Stufen `low`/`standard`/`high`, die Upload-Prüfung per `finfo`, die Löschfrist-Regel `forms.submissions` und die 30-Tage-Frist für DSGVO-Exportdateien; sie stehen im neuen Frontend-Eintrag. | [x] Behoben in PR #120 |
| CL-060 | `Changelog.md:23` | 🟠 | Fehlende Angabe: Ergänzt wurden die Capability `manage_newsletter` (in keiner Standardrolle), Kampagnenfunktionen (Kopieren, „Fehlgeschlagene erneut senden“), Platzhalter, Themen/Segmente und Sprache je Abonnent, die SPF/DMARC/BIMI-Prüfung sowie `batch_size` (Standard 50, 5–1000). | [x] Behoben in PR #120 |
| CL-061 | `Changelog.md:23` | 🟠 | Fehlende Angabe: Im Frontend-Teil fehlten die vollständigen Routen (`/newsletter/subscribe`, `/confirm/<token>`, `/unsubscribe/<token>`, `/issue/<id>`, auch `/en/…`), der Anmeldeschutz (Honeypot, 6 Versuche je Stunde und IP, einheitliche Rückmeldung, Bestätigungsmail höchstens alle 10 Minuten), `confirm_expiry_days` (Standard 14) mit Regel `newsletter.pending`, die Mitglieder-Route, die CSRF-Ausnahme für `/newsletter/` und die Theme-Overrides; sie wurden ergänzt. | [x] Behoben in PR #120 |
| CL-062 | `Changelog.md:24` | 🟠 | Fehlende Angabe: Ergänzt wurden die Capability `manage_knowledgebase` (in keiner Standardrolle), Hardreset mit optionalem Neuimport, die 13 mitgelieferten Glossar-CSV-Pakete plus Beispiel-Vorlage (am HEAD gezählt), die Theme-Overrides und die Weiterverwendung der Tabellen `kb_*`. | [x] Behoben in PR #120 |
| CL-063 | `Changelog.md:25` | 🟠 | Fehlende Angabe: Capability `view_editorial_calendar` und die Geltung der Veröffentlichungs-Checkliste beim Planen im Kalender fehlten; die „Datumsauswahl“ wurde am JS bestätigt und als Tastatur-Alternative über die Schaltfläche „Verschieben“ beschrieben, Feldname `planned_at` ergänzt. | [x] Behoben in PR #120 |
| CL-064 | `Changelog.md:27` | 🟠 | Fehlende Angabe: Es fehlten die Ursache (`index.php` lud `config.php` ungeprüft), der Statuscode der Weiterleitung (302) und die Antwort HTTP 503 „CMS ist nicht konfiguriert.“ bei fehlender `install.php`; alle drei sind ergänzt. | [x] Behoben in PR #120 |
| CL-065 | `Changelog.md:28, 77, 115, 119, 133` | 🟠 | Fehlende Angabe: Die Suiten `content-types`, `editorjs-blocks` und `update-swap` waren nur verstreut in anderen Einträgen erwähnt; der Tests-Eintrag nennt jetzt alle vier Suiten, den gemeinsamen Runner und zusätzlich die geprüften Kachelseiten, Badges und Modulzustände. | [x] Behoben in PR #120 |
| CL-066 | `Changelog.md:31` | 🟠 | Fehlende Angabe: Die Urheber- und Lizenzangaben für Medien hatten keinen eigenen Eintrag; neu beschrieben mit Feldern, Lizenzauswahl (sechs CC-Varianten, an `MediaLicenseService::LICENSES` gezählt), Sammelbearbeitung bis 500 Dateien, Vorbelegung aus IPTC/EXIF/XMP, Credit-Zeile und JSON-LD sowie dem Hinweis, dass die Schalter standardmäßig an sind. | [x] Behoben in PR #120 |
| CL-067 | `Changelog.md:31` | 🟠 | Fehlende Angabe: Bei den Medien-Werkzeugen fehlten Capability `manage_media`, die Voraussetzung eines laufenden Crons und der Hinweis in den Medien-Einstellungen nach geänderten Bildgrößen. | [x] Behoben in PR #120 |
| CL-068 | `Changelog.md:34` | 🟠 | Fehlende Angabe: In der Typenliste fehlten Knowledgebase-Einträge sowie der Hinweis, dass Downloads nicht enthalten sind; ergänzt wurden außerdem Archivformat `365cms-content-exchange` Version 1 und die Grenzen 256 MB Upload und 50.000 Archiveinträge; das Umschreiben der Kategorie- und Medienverweise wurde an `ContentRowExchangeProvider`/`ExchangeSupport::rewriteMedia()` bestätigt. | [x] Behoben in PR #120 |
| CL-069 | `Changelog.md:35, 134` | 🟠 | Fehlende Angabe: Ergänzt, dass `kb_*` und `newsletter_*` die Tabellennamen der früheren Plugins tragen und vorhandene Tabellen weiterverwendet und um fehlende Spalten ergänzt werden (u. a. `kb_entries.trashed_at`/`trashed_by`). | [x] Behoben in PR #120 |
| CL-070 | `Changelog.md:36, 45, 134` | 🟠 | Fehlende Angabe: Neue Spalten fehlten: `previous_status`, `trashed_at`, `trashed_by` in `posts` und `pages`, `trashed_at`/`trashed_by` in `site_tables`, die Indizes `idx_trashed_at` und `idx_planned_at`; ergänzt laut `MigrationManager.php`, `page_revisions.created_by` ist dem Schema-Schritt `v25` zugeordnet. | [x] Behoben in PR #120 |
| CL-071 | `Changelog.md:37` | 🟠 | Fehlende Angabe: Die Typenliste des Papierkorbs war unvollständig (Knowledgebase-Einträge fehlten, „Eingänge“ unpräzise) und die Verhaltensänderung nannte nur Mediathek und Hub-Sites; ergänzt sind Beiträge und Seiten (3.5.6 löschte sofort), Listenfilter „Papierkorb“, Bearbeitungssperre und Nichterreichbarkeit von Hub-Sites auch per Domain. | [x] Behoben in PR #120 |
| CL-072 | `Changelog.md:37` | 🟠 | Fehlende Angabe: Der Standard der automatischen Leerung (30 Tage, höchstens 3650, endgültiges Löschen im täglichen Cron) und die Ablage der Medien im privaten Speicher fehlten und wurden ergänzt. | [x] Behoben in PR #120 |
| CL-073 | `Changelog.md:38` | 🟠 | Fehlende Angabe: Ergänzt wurden die Capability `manage_system`, die Option `maintenance_mode`, die Einschränkung des OPcache-Punkts auf aktiven OPcache, der Weg über `POST /admin/admin-ui` mit CSRF-Token und Audit-Log sowie der Doku-Verweis; Bereich jetzt „Admin / Toolbar“. | [x] Behoben in PR #120 |
| CL-074 | `Changelog.md:39` | 🟠 | Fehlende Angabe: Ergänzt sind die Begrenzung auch der Konstantenwerte auf 3–20 bzw. 60–3600 s (Auswirkung nach dem Update), der Rückfall auf 5 Versuche/300 s, Zurücksetzen auf die Konstanten, Optionsnamen `max_login_attempts`/`login_timeout` in Gruppe `security`, CSRF und Audit-Log sowie der Doku-Verweis. | [x] Behoben in PR #120 |
| CL-075 | `Changelog.md:40` | 🟠 | Fehlende Angabe: Der Kompatibilitätshinweis für eigene Themes (nur `$errorCode` gelesen: weiterhin 500, `error_code` auswerten) und der Parameter `error_standalone` fehlten und sind ergänzt; die Korrektur ist ausdrücklich dem Theme `cms-default` zugeordnet. | [x] Behoben in PR #120 |
| CL-076 | `Changelog.md:42` | 🟠 | Fehlende Angabe: Es fehlte, dass der Aggregator standardmäßig aus ist (`aggregator_enabled`) und nur bei der Datenübernahme aus `cms-feed` eingeschaltet wird, dass `require_consent` standardmäßig gilt, dass `/admin/feeds` per 301 umleitet, die Capability `manage_feeds`, der zehnte Abschnitt „RSS-Ausgang“ und der stündliche Lauf von Digests und Abos; alles ergänzt. | [x] Behoben in PR #120 |
| CL-077 | `Changelog.md:42, 129` | 🟠 | Fehlende Angabe: Entfernte Schnittstellen fehlten: sieben globale Sidebar-Helfer aus `admin/partials/sidebar.php`, `Database::invalidateFeedCache()` (Ersatz `FeedService::invalidatePublicFeedCache()`), `CronRunnerService::repairFeedProcessingQueue()` und `runLegacyFeedQueueBridge()` – per `git grep` am HEAD als entfernt bestätigt und ergänzt. | [x] Behoben in PR #120 |
| CL-078 | `Changelog.md:42, 129` | 🟠 | Fehlende Angabe: Geänderte Schnittstellen fehlten: RSS-Erzeugung in `FeedService::serveRssFeed()` statt im `ThemeRouter` und Plugin-Shell mit `<div class="page-body">` statt `<main class="page-body">` (3.5.6: `AdminRouter.php:539/553`) ergänzt. | [x] Behoben in PR #120 |
| CL-079 | `Changelog.md:43` | 🟠 | Fehlende Angabe: Ergänzt wurden die Capabilities (`manage_downloads`, `view_download_stats` für die Seite; `upload_downloads` oder `manage_downloads` für den Editor-Block laut admin/post-editor-api.php:64; Redakteure standardmäßig nur `upload_downloads`), die Template-Namen, der Verbleib öffentlicher Dateien unter `uploads/downloads/` sowie Papierkorb und fehlender Import/Export; der nicht kursive, profilabhängige Menüpfad „Medien → Downloads“ entfiel. | [x] Behoben in PR #120 |
| CL-080 | `Changelog.md:44` | 🟠 | Fehlende Angabe: Ergänzt wurden die 301-Weiterleitung alter Plugin-Links `/downloads/file/<slug>`, die Hinweisseite vor externen Zielen (`show_external_notice`), der optionale Navigationspunkt (`show_nav_link`), Theme-Overrides, die Löschfrist-Regel `downloads.events` und die tägliche Statistik-Verdichtung. | [x] Behoben in PR #120 |
| CL-081 | `Changelog.md:46` | 🟠 | Fehlende Angabe: Die Entfernung der seit PHP 8.5 veralteten Aufrufe `curl_close()`, `finfo_close()` und `imagedestroy()` im Core und im Plugin `cms-importer` (Commit 17edf3b) fehlte im Changelog und ist ergänzt; über gebündelte Bibliotheken wird nichts behauptet. | [x] Behoben in PR #120 |
| CL-082 | `Changelog.md:49` | 🟠 | Fehlende Angabe: Ergänzt: `/mfa-setup` erzeugt nach der Aktivierung Backup-Codes und zeigt sie im Mitgliederbereich an (Commit 708d771). | [x] Behoben in PR #120 |
| CL-083 | `Changelog.md:50` | 🟠 | Fehlende Angabe: Die Ausschlussliste nennt jetzt die tatsächlichen Schlüsselmuster des Filters einschließlich des bisher fehlenden `nonce` und vermerkt, dass `mfa_secret` in 3.5.6 im Klartext vorlag; Bereich jetzt „Mitgliederbereich / Datenexport“. | [x] Behoben in PR #120 |
| CL-084 | `Changelog.md:55` | 🟠 | Fehlende Angabe: Ergänzt, dass die Empfängersuche in allen drei Stufen nur aktive Konten berücksichtigt. | [x] Behoben in PR #120 |
| CL-085 | `Changelog.md:59` | 🟠 | Fehlende Angabe: Ergänzt wurde, dass bereits geschriebene Audit-Log-Einträge nicht bereinigt werden (am HEAD gegengeprüft: kein UPDATE auf `audit_log`, keine Migration), samt Empfehlung, den Cron-Token unter `/admin/mail-settings` (Option „Cron-Token beim Speichern neu erzeugen“) zu erneuern. | [x] Behoben in PR #120 |
| CL-086 | `Changelog.md:65` | 🟠 | Fehlende Angabe: Ergänzt wurden der Ausgangszustand in 3.5.6 (Schlüssel nur aus `AUTH_KEY`/`SECURE_AUTH_KEY` abgeleitet), die Geltung des Schlüsselrings für die Backup-Verschlüsselung (BackupService nutzt CryptoService) und die Empfehlung aus DOC/audit/FOLGEAUDIT-2026-11-09.md:149, `CMS_ENCRYPTION_KEY` getrennt von Datenbank und Backups zu sichern. | [x] Behoben in PR #120 |
| CL-087 | `Changelog.md:66` | 🟠 | Fehlende Angabe: Die Grenzwerte der Rate-Limits fehlten und sind ergänzt: `email_change` 5 je Stunde, `email_change_notice` 3 je Stunde, `email_change_confirm` 10 in 15 Minuten; der Eintrag bleibt wegen der vom Plan verlangten Hook- und Audit-Listen mit rund 980 Zeichen leicht über dem Zielwert. | [x] Behoben in PR #120 |
| CL-088 | `Changelog.md:67` | 🟠 | Fehlende Angabe: Ergänzt wurden der Hook `user_sessions_revoked` (`$userId`, `$initiator`) und der Betreiberhinweis, dass bestehende Sitzungen und Tokens beim Update gültig bleiben (Token-Version 0 entspricht dem bisherigen Fingerabdruck, `Auth::passwordFingerprint()`). | [x] Behoben in PR #120 |
| CL-089 | `Changelog.md:68` | 🟠 | Fehlende Angabe: Ergänzt: `Auth::register()` prüft Längen (Benutzername 3–50 Zeichen, E-Mail-Adresse höchstens 100 Zeichen). | [x] Behoben in PR #120 |
| CL-090 | `Changelog.md:70` | 🟠 | Fehlende Angabe: Ergänzt wurde der update-relevante Hinweis, dass Backups im bisherigen `backups/` nach dem Setzen von `CMS_BACKUP_PATH` nicht mehr gelistet werden und vorher zu verschieben sind (Seite und Sicherheits-Audit weisen auf Alt-Backups hin). | [x] Behoben in PR #120 |
| CL-091 | `Changelog.md:71` | 🟠 | Fehlende Angabe: Ergänzt wurde, für wen der Eintrag relevant ist: abgeleitete Themes und Plugins, die die Helfer verwenden, da das Standard-Theme sie nicht selbst aufruft. | [x] Behoben in PR #120 |
| CL-092 | `Changelog.md:72` | 🟠 | Fehlende Angabe: Es fehlte, wer den Speicher nutzt; ergänzt sind geschützte Downloads, Formular-Anhänge, Import-/Exportarchive, Medien-Papierkorb und Newsletter sowie der Hinweis, dass Nginx-Betreiber `/private/` selbst sperren müssen. | [x] Behoben in PR #120 |
| CL-093 | `Changelog.md:77` | 🟠 | Fehlende Angabe: Die Ursache fehlte; ergänzt ist, dass 3.5.6 nur `backups`, `cache`, `config`, `config.php`, `logs` und `uploads` bewahrte, und die mitgelieferten Ordner stehen mit vollem Pfad (`plugins/cms-importer`, `themes/cms-default`). | [x] Behoben in PR #120 |
| CL-094 | `Changelog.md:78` | 🟠 | Fehlende Angabe: Es fehlte, dass das konfigurierte Backup-Verzeichnis (`CMS_BACKUP_PATH`) ausgewertet wird; ergänzt, Bereich auf „Sicherheit / Sicherheits-Audit“ vereinheitlicht. | [x] Behoben in PR #120 |
| CL-095 | `Changelog.md:79` | 🟠 | Fehlende Angabe: Es fehlte die Einschränkung, dass nur Verzeichnisse nach dem Muster `full_backup_<Zeit>` bzw. `database_backup_<Zeit>` erkannt werden, sowie die am Code geprüfte Angabe, dass „Restore“ für solche Einträge nicht angeboten wird; Bereich auf „Backups / Übersicht“ geändert. | [x] Behoben in PR #120 |
| CL-096 | `Changelog.md:84` | 🟠 | Fehlende Angabe: Es fehlte der Hinweis, dass `logs/.htaccess` in bestehenden Installationen nicht automatisch erneuert wird (Laufzeitpfad, am HEAD keine schreibende Komponente gefunden) und von Hand zu übernehmen ist. | [x] Behoben in PR #120 |
| CL-097 | `Changelog.md:105` | 🟠 | Fehlende Angabe: Ergänzt wurde der Erweiterungspunkt für Entwickler: Admin-Seiten laden eigene ES-Module über `$pageAssets['modules']`. | [x] Behoben in PR #120 |
| CL-098 | `Changelog.md:106` | 🟠 | Fehlende Angabe: Ergänzt wurde, dass Benachrichtigungen, „Mein Profil“ und „Abmelden“ aus der Kopfzeile in den Benutzerbereich am Fuß der Seitenleiste umgezogen sind. | [x] Behoben in PR #120 |
| CL-099 | `Changelog.md:107` | 🟠 | Fehlende Angabe: Ergänzt wurden die Beschränkung auf Administratoren, das Audit-Log beim Speichern und die Klarstellung, dass die Zuweisung ein Anzeigefilter und keine Zugriffssperre ist. | [x] Behoben in PR #120 |
| CL-100 | `Changelog.md:108` | 🟠 | Fehlende Angabe: Ergänzt wurden das Verhalten ohne IDN-Unterstützung von PHP (Hostname bleibt unverändert), die Beschreibung des Site-Umschalters (Position, Neuladen im Site-Kontext) und der Verweis auf `DOC/admin/system-settings/MULTISITES.md`. | [x] Behoben in PR #120 |
| CL-101 | `Changelog.md:109` | 🟠 | Fehlende Angabe: In der Badge-Liste fehlten die Formular-Eingänge; ergänzt wurden außerdem der Verweis auf `DOC/admin/ADMIN-MENU.md` und der Hinweis, dass das Changelog wegen profilabhängiger Menüpfade Routen nennt. | [x] Behoben in PR #120 |
| CL-102 | `Changelog.md:110` | 🟠 | Fehlende Angabe: Ergänzt wurden die Grenzwerte des `LayoutValidator` (200 Knoten, 60 Zeichen je Bezeichnung, 256 KiB JSON) und die Tastaturbedienung mit Alt+Pfeiltasten; die Screenreader-Ansagen wurden am Code bestätigt (`role="status"`/`aria-live` in `admin-sidebar-editor.php`, `announce()` im Skript) und beibehalten. | [x] Behoben in PR #120 |
| CL-103 | `Changelog.md:117` | 🟠 | Fehlende Angabe: Ergänzt wurden die Aktivierungssperre im `PluginManager` (PluginManager.php:256), die Zeitbudgets der Datenübernahme (5 s je Admin-Aufruf, 60 s im täglichen Cron), das Muster der umgeleiteten Adminseiten und dass der Core-Cron das Plugin `cms-feed` nicht mehr bedient. | [x] Behoben in PR #120 |
| CL-104 | `Changelog.md:120` | 🟠 | Fehlende Angabe: Ergänzt wurden „Speichern“ bzw. „Aktualisieren“ in der Kopfleiste, Permalink, Autor und Revisionen in der Einstellungsspalte sowie die Grenzen der Editorbreite (320–1600 px). | [x] Behoben in PR #120 |
| CL-105 | `Changelog.md:120` | 🟠 | Fehlende Angabe: Die Checkliste nannte nur drei Prüfpunkte und vier Durchsetzungsstellen; ergänzt sind der bedingte Punkt „Downloads“ (nicht verfügbare Downloads in Download-Blöcken) und der Redaktionskalender als weitere serverseitige Prüfstelle. | [x] Behoben in PR #120 |
| CL-106 | `Changelog.md:122` | 🟠 | Fehlende Angabe: Die Aktionsliste von `/admin/post-editor-api` war unvollständig; ergänzt sind `token`, `form_choices`, `download_search`, `block_preview` und `download_upload` mit Zuordnung zu GET bzw. POST mit CSRF sowie die Revisionstypen `manual`, `autosave`, `restore`, `baseline`, `legacy`. | [x] Behoben in PR #120 |
| CL-107 | `Changelog.md:125` | 🟠 | Fehlende Angabe: Die Liste der Hinweise war unvollständig; ergänzt wurden „kein Backup vorhanden“, in den Core übernommene Plugins, Prüfung der Barrierefreiheitserklärung und über `AdminNoticeService::registerCheck()` registrierte Prüfungen. | [x] Behoben in PR #120 |
| CL-108 | `Changelog.md:130, 131` | 🟠 | Fehlende Angabe: `DOC/audit/FOLGEAUDIT-2026-11-12.md` (Prüfung des Stands 4.0.0) fehlte und ist ergänzt; zusätzlich wird erklärt, dass die IDs SEC, DEP, FUN, BP und OFFEN auf die Audit-Dokumente verweisen. | [x] Behoben in PR #120 |
| CL-109 | `Changelog.md:130, 131, 132` | 🟠 | Fehlende Angabe: Von den neuen Handbuchseiten unter `DOC/admin/` waren nur `ADMIN-MENU.md` und `MULTISITES.md` genannt; die übrigen 14 per `git diff --diff-filter=A` belegten Seiten (Formulare, Newsletter, Knowledgebase, Papierkorb, Übersetzungen, Änderungen, Downloads, Medien-Werkzeuge, Barrierefreiheit, Löschfristen, Login-Schwellen, Suchindex, Inhaltsaustausch, Feeds, Toolbar) sowie `DOC/core/CONTENT-TYPE-PROVIDERS.md` sind ergänzt. | [x] Behoben in PR #120 |
| CL-110 | `Changelog.md:132` | 🟠 | Fehlende Angabe: Der Betreiberhinweis, dass die Sperre von `private/` unter Nginx von Hand einzurichten ist (`DOC/INSTALLATION.md`), ist beim Doku-Eintrag ergänzt. | [x] Behoben in PR #120 |
| CL-111 | `Changelog.md:134` | 🟠 | Fehlende Angabe: Der Betreiberhinweis aus `update.json` fehlte: nach dem Update das Datenbank-Update auf der Seite „Updates“ (`/admin/updates`) ausführen. | [x] Behoben in PR #120 |
| CL-112 | `Changelog.md:134` | 🟠 | Fehlende Angabe: Ergänzt, dass die Systemanforderungen gegenüber 3.5.6 unverändert sind (PHP 8.4.1 oder neuer, MySQL bzw. MariaDB). | [x] Behoben in PR #120 |
| CL-113 | `Changelog.md` (kein Eintrag) | 🟠 | Fehlende Angabe: Die Suchindex-Verwaltung `/admin/search-index` hatte keinen eigenen Eintrag; neu beschrieben mit Status, Neuaufbau in Stapeln zu 50, anonymer Suchbegriff-Statistik, `log_terms` und Aufbewahrung 7–3650 Tage (Standard 365, 0 = unbegrenzt, an `SearchTermStatsService::clampRetentionDays()` bestätigt). | [x] Behoben in PR #120 |
| CL-114 | `Changelog.md` (kein Eintrag) | 🟠 | Fehlende Angabe: Die Nutzung des Suchindex in der Seitensuche (Grundform-Treffer mit Stemmern für acht Sprachen, bis 200 Kandidaten je Typ, halbe Gewichtung) und die Schnittstellen für eigene Such-Provider fehlten und wurden als eigener Eintrag ergänzt. | [x] Behoben in PR #120 |
| CL-115 | `Changelog.md` (kein Eintrag) | 🟠 | Fehlende Angabe: Die Löschfristen-Übersicht `/admin/data-retention` fehlte als Funktion; neu beschrieben mit Probelauf, „Jetzt ausführen“, den an `DOC/admin/legal/DATA-RETENTION.md` und `DataRetentionService` geprüften Standardfristen und der neuen Regel für Kommentar-IP-Adressen (`retention.comment_ip_days`, `comment_ip_mode`). | [x] Behoben in PR #120 |
| CL-116 | `Changelog.md` (kein Eintrag) | 🟠 | Fehlende Angabe: Die Barrierefreiheitserklärung (Karte unter `/admin/legal-sites#legal-accessibility`, Seiten `/barrierefreiheit` und `/en/accessibility`, Option `accessibility_page_id`, Footer-Link, Erinnerung 30 Tage vor der jährlichen Prüfung, Vorlage ohne Rechtsberatung) war nur im Sprachschlüssel-Fix erwähnt und erhält einen eigenen Eintrag. | [x] Behoben in PR #120 |
| CL-117 | `Changelog.md` (kein Eintrag) | 🟠 | Fehlende Angabe: Neuer Eintrag „Core / Hooks“: 13 neue Actions und 4 neue Filter (u. a. `cms_form_submitted`, `cms_newsletter_*`, `cms_knowledgebase_entry_*`, `cms_download_access`, `cms_member_export_data`) fehlten im Abschnitt; alle Namen sind am Code (HEAD) und in `DOC/core/HOOKS-REFERENCE.md` belegt und in 3.5.6 nicht vorhanden. | [x] Behoben in PR #120 |
| CL-118 | `Changelog.md:44` | 🟡 | Veraltete Aussage: Der Satz „Verwaltung und Editor-Block folgen.“ war ein überholter Zwischenstand und wurde gestrichen. | [x] Behoben in PR #120 |
| CL-119 | `Changelog.md:47` | 🟡 | Veraltete Aussage: Der Pfad `/admin/settings?tab=feeds` zeigt am HEAD die Aggregator-Übersicht; die RSS-Einstellungen liegen unter `/admin/settings?tab=feeds&section=rss` (Abschnitt „RSS-Ausgang“), der Bereich heißt nun „Feeds / RSS“. | [x] Behoben in PR #120 |
| CL-120 | `Changelog.md:48` | 🟡 | Veraltete Aussage: Der Menüpfad *Sicherheit → Firewall → Proxy / CDN & Client-IP* ist profilabhängig; ersetzt durch die Karte „Proxy / CDN & Client-IP“ unter `/admin/firewall`, Bereich vereinheitlicht zu „Sicherheit / Host-Header“. | [x] Behoben in PR #120 |
| CL-121 | `Changelog.md:54` | 🟡 | Veraltete Aussage: Der Menüpfad *Aboverwaltung → Bestellungen & Zuweisung* stammt aus 3.5.6; ersetzt durch die Route `/admin/orders` mit Seitentitel „Bestellungen & Zuweisung“, dazu ein Querverweis auf die Übernahme von Gastbestellungen bei bestätigter Adresse. | [x] Behoben in PR #120 |
| CL-122 | `Changelog.md:59` | 🟡 | Veraltete Aussage: Der Menüpfad „Diagnose → Cron-Job-Status“ stammte aus der Navigation von 3.5.6 und wurde durch die Route `/admin/monitor-cron-status` ersetzt; Bereich jetzt „Core / Cron“. | [x] Behoben in PR #120 |
| CL-123 | `Changelog.md:63` | 🟡 | Veraltete Aussage: Der profilabhängige Menüpfad „Einstellungen → Allgemein“ wurde durch die Route `/admin/settings` ersetzt; Bereich jetzt „Core / Konfiguration“. | [x] Behoben in PR #120 |
| CL-124 | `Changelog.md:65` | 🟡 | Veraltete Aussage: Der Menüpfad *Einstellungen → Allgemein* wurde durch die profilneutrale Formulierung „das Speichern der allgemeinen Einstellungen“ ersetzt. | [x] Behoben in PR #120 |
| CL-125 | `Changelog.md:66` | 🟡 | Veraltete Aussage: Der Menüpfad *Benutzer → Bearbeiten* existiert so nicht (profilabhängig, „Bearbeiten“ ist kein Menüpunkt); ersetzt durch „im Bearbeiten-Formular des Benutzers“. | [x] Behoben in PR #120 |
| CL-126 | `Changelog.md:70` | 🟡 | Veraltete Aussage: Der Menüpfad „System → Backup & Restore“ existiert in keinem 4.0.0-Menüprofil und wurde durch Seite „Backup & Restore“ unter `/admin/backups`, Karte „Speicherort & Verschlüsselung“, ersetzt. | [x] Behoben in PR #120 |
| CL-127 | `Changelog.md:108` | 🟡 | Veraltete Aussage: „Statt fest eingebauter Sites“ wurde gestrichen, weil 3.5.6 keine Site-Verwaltung kannte; der Menüpfad *Einstellungen › MultiSites* entfiel zugunsten der Route, Bereich jetzt „Admin / MultiSites“. | [x] Behoben in PR #120 |
| CL-128 | `Changelog.md:113` | 🟡 | Veraltete Aussage: Der Hinweis, `cms_admin_menu_set_preferred_parent()` und `cms_admin_menu_preferred_parents` blieben „aus Kompatibilität erhalten“, wurde gestrichen, weil beide in 3.5.6 nicht existierten; ebenso die Formulierung „nimmt nur noch … auf“. | [x] Behoben in PR #120 |
| CL-129 | `Changelog.md:117` | 🟡 | Veraltete Aussage: Der Eintrag beschrieb die Plugin-Integration als „Vorbereitung“ für drei Plugins mit dem Vorbehalt „bis dahin laufen die Plugins unverändert“; er nennt nun die vollzogene Übernahme aller fünf Plugins (`cms-feed`, `cms-downloads`, `cms-newsletter`, `cms-knowledgebase`, `cms-contact`) mit ihren Core-Routen. | [x] Behoben in PR #120 |
| CL-130 | `Changelog.md:130, 131` | 🟡 | Veraltete Aussage: Widerspruch aufgelöst: Z. 130 nannte OFFEN-20 … OFFEN-27 als offene Empfehlungen, Z. 131 deren Umsetzung; jetzt steht einheitlich, dass OFFEN-20 … OFFEN-26 in 4.0.0 umgesetzt sind. | [x] Behoben in PR #120 |
| CL-131 | `Changelog.md:132` | 🟡 | Veraltete Aussage: Die Schema-Angabe „`v23` → `v24`“ in der Doku-Beschreibung war ein überholter Stand und widersprach „Schema `v25`“ im selben Eintrag; genannt wird nur noch `v25`. | [x] Behoben in PR #120 |
| CL-132 | `Changelog.md:22` | 🟡 | Falsche Kategorie: Der fix-Eintrag „Plugin-Übernahme“ betraf nur unveröffentlichten 4.0.0-Code (ContactPluginMigrator existiert in 3.5.6 nicht) und entfällt als eigener Eintrag; Empfängeradresse (`admin_email` vor `global_recipient`) und Eingangsbestätigung stehen nun im Satz zur Datenübernahme, „jetzt“ und der Verweis auf PLUGIN-PARITY.md wurden gestrichen. | [x] Behoben in PR #120 |
| CL-133 | `Changelog.md:45` | 🟡 | Falsche Kategorie: Der Schema-Eintrag war als feat geführt und las sich mit „Schema v25 um … erweitert“ wie ein Stand innerhalb der Entwicklung; jetzt chore unter „Datenbank / Schema“ mit dem Sprung `v23` (3.5.6) → `v25`. | [x] Behoben in PR #120 |
| CL-134 | `Changelog.md:60` | 🟡 | Falsche Kategorie: Der Schutz vor Selbstaussperrung war als security eingestuft, ist aber keine Schwachstelle; er ist jetzt fix, mit dem Zusatz, dass die Sammelaktion schon zuvor geschützt war (SEC-44 beibehalten). | [x] Behoben in PR #120 |
| CL-135 | `Changelog.md:72` | 🟡 | Falsche Kategorie: Der private Speicher war als security geführt, ist aber neue Infrastruktur ohne in 3.5.6 behobene Schwachstelle; Typ jetzt feat, Bereich „Core / Privater Speicher“. | [x] Behoben in PR #120 |
| CL-136 | `Changelog.md:74` | 🟡 | Falsche Kategorie: Der feat-Eintrag begann als Fehlerbeschreibung; er ist als Funktion formuliert mit dem Hinweis, dass die Option in 3.5.6 ohne Wirkung war, und dem Bereich „Konto / E-Mail-Verifizierung“. | [x] Behoben in PR #120 |
| CL-137 | `Changelog.md:124` | 🟡 | Falsche Kategorie: Der Eintrag (Symbol 🟡 mit Text „fix“) betraf nur 4.0.0-Entwicklungscode und entfällt als eigener Eintrag; der Hinweis „Systemstatus nicht vollständig geprüft“ mit Link zur Diagnose steht jetzt im feat-Eintrag „Admin / Systemhinweise“, der Revisionsteil gehört nicht in diesen Abschnitt. | [x] Behoben in PR #120 |
| CL-138 | `Changelog.md:127` | 🟡 | Falsche Kategorie: Die BackupService-Korrekturen standen als refactor im Sammelbereich „Robustheit“; sie sind jetzt Teil des fix-Eintrags „Backups / Erstellung“, präzisiert auf `Version::CURRENT` statt der Konstante `CMS_VERSION` (undefiniert: Fatal Error) und das deutsche Zahlenformat bis TB (BP-08). | [x] Behoben in PR #120 |
| CL-139 | `Changelog.md:127` | 🟡 | Falsche Kategorie: Das Schreiben von `robots.txt` mit Dateisperre stand als refactor unter „Robustheit“ und ist als Verhaltenskorrektur in den Eintrag „SEO / robots.txt“ übernommen (BP-08); die übrigen Teile von Z. 127 sind hier nicht enthalten. | [x] Behoben in PR #120 |
| CL-140 | `Changelog.md:127` | 🟡 | Falsche Kategorie: Der Sammel-Eintrag „Robustheit“ (kein Produktbereich) ist aufgelöst: Der Theme-Teil steht mit vollständiger Angabe (Theme `cms-default`, `partials/home-landing.php`) unter „Core / Code-Qualität“, die Backup- und robots.txt-Teile gehören zu den fix-Einträgen „Backups / Erstellung“ und „SEO / robots.txt“. | [x] Behoben in PR #120 |
| CL-141 | `Changelog.md:24` | 🟡 | Korrektur an nie veröffentlichtem Code: Der Satz „Behoben: die Suche brach mit einem SQL-Fehler ab, ‚Nur ganze Wörter‘ ließ sich nicht abwählen“ beschrieb Fehler des übernommenen Plugin-Codes, nicht von 3.5.6, und wurde gestrichen. | [x] Behoben in PR #120 |
| CL-142 | `Changelog.md:26` | 🟡 | Korrektur an nie veröffentlichtem Code: Der fix-Eintrag zur Link-Schleife beschrieb einen Fehler, der nur im unveröffentlichten 4.0.0-Code existierte; er entfällt als eigener Eintrag samt Schleifen-Erzählung und dem Satz „Bereits verschickte Links bleiben gültig“. | [x] Behoben in PR #120 |
| CL-143 | `Changelog.md:28` | 🟡 | Korrektur an nie veröffentlichtem Code: Der fix-Eintrag zum verschobenen `CMS/private/` betraf einen erst in 4.0.0 entstandenen Pfad; übrig bleibt im Speicher-Eintrag die Eigenschaft, dass `private/` bei Core-Updates als Laufzeitpfad erhalten bleibt (vollständige Liste der Laufzeitpfade). | [x] Behoben in PR #120 |
| CL-144 | `Changelog.md:29` | 🟡 | Korrektur an nie veröffentlichtem Code: Der security-Eintrag zu den Entpack-Grenzen betraf Code, der erst in 4.0.0 entstand; die Grenzen (2 GB entpackt, nie mehr als angegeben je Eintrag, zeilenweise Vorschau) stehen jetzt als Schutzmerkmal im Funktions-Eintrag. | [x] Behoben in PR #120 |
| CL-145 | `Changelog.md:33` | 🟡 | Korrektur an nie veröffentlichtem Code: Der fix-Eintrag zum Zähltext betraf die erst in 4.0.0 neue Seite und nannte mit „Recht → Löschfristen“ einen nicht existierenden Menüpfad; er entfällt zugunsten eines feat-Eintrags. | [x] Behoben in PR #120 |
| CL-146 | `Changelog.md:41` | 🟡 | Korrektur an nie veröffentlichtem Code: Der fix-Eintrag zu fehlenden Sprachschlüsseln betraf nur in 4.0.0 neue Bereiche (und nannte mit „rund 330“ statt 387 eine falsche Zahl); er entfällt, die Funktionen haben eigene feat-Einträge. | [x] Behoben in PR #120 |
| CL-147 | `Changelog.md:124` | 🟡 | Korrektur an nie veröffentlichtem Code: Der fix-Eintrag (zudem mit falschem Symbol 🟡) betraf nur 4.0.0-Entwicklungscode; übernommen ist allein die Verhaltensänderung gegenüber 3.5.6 in den Revisions-Eintrag (Beitrag wird trotz fehlgeschlagener Revision gespeichert und eine Warnung angezeigt, 3.5.6 brach das Speichern ab), der Systemhinweis-Teil gehört in den Systemhinweis-Eintrag. | [x] Behoben in PR #120 |
| CL-148 | `Changelog.md:132` | 🟡 | Korrektur an nie veröffentlichtem Code: Interne Arbeitsunterlagen unter `DOC/workflow/specs/` (PROGRESS, KLARSTELLUNGEN) und die wirkungslosen „Plugin-Wunschplätze“ sind nicht mehr als Release-Inhalt genannt; die Gliederung „angepasst … Neu … Ergänzt“ entfällt. | [x] Behoben in PR #120 |
| CL-149 | `Changelog.md:27, 98` | 🟡 | Redundanz: Zwei getrennte Installer-Einträge mit unterschiedlichen Bereichsnamen („Neuinstallation“, „Installer“) wurden zu einem Eintrag „Installer“ zusammengeführt. | [x] Behoben in PR #120 |
| CL-150 | `Changelog.md:30, 92, 96` | 🟡 | Redundanz: Drei verstreute Dashboard-Korrekturen (Bereich „Dashboard“ mehrfach, plus Dashboard-Teil des Sammeleintrags Z. 30) sind in einem Eintrag „Admin / Dashboard“ gebündelt. | [x] Behoben in PR #120 |
| CL-151 | `Changelog.md:30, 99, 102` | 🟡 | Redundanz: Drei kosmetische Einzeleinträge (Kippschalter und Vorschaubilder, Bestätigungsdialog, Editor-Schnellleiste) sind zu einem Sammeleintrag „Admin / Oberfläche“ zusammengeführt; Dateiangaben ohne Nutzen für Leser (`partials/footer.php`, CSS-Selektor) sind entfallen. | [x] Behoben in PR #120 |
| CL-152 | `Changelog.md:35` | 🟡 | Redundanz: Die Nennung der Tabelle `translation_sync` entfällt hier, weil sie in den Schema-Eintrag gehört. | [x] Behoben in PR #120 |
| CL-153 | `Changelog.md:43, 44` | 🟡 | Redundanz: Zwei Einträge mit identischem Bereich „Downloads“ beschrieben dieselbe Funktion in zwei Entwicklungsschritten; sie sind nun als „Downloads / Verwaltung“ und „Downloads / Frontend“ sauber getrennt, jeweils mit „bisher Plugin `cms-downloads`“ nur im ersten. | [x] Behoben in PR #120 |
| CL-154 | `Changelog.md:45, 122, 134` | 🟡 | Redundanz: Die über Z. 45, 122, 132 und 134 verteilten, einander widersprechenden Schema-Angaben sind in zwei Einträgen konsolidiert (Spalten/Indizes und Tabellen). | [x] Behoben in PR #120 |
| CL-155 | `Changelog.md:57, 69` | 🟡 | Redundanz: Zwei Einträge mit demselben Bereich „Anmeldung“ (Laufzeit-Enumeration SEC-41, Rate-Limit je Konto OFFEN-25) wurden zu „Konto / Anmeldung“ zusammengeführt und per Halbsatz von der einstellbaren IP-Sperre („Sicherheit / Login-Sperre“) abgegrenzt. | [x] Behoben in PR #120 |
| CL-156 | `Changelog.md:60, 75` | 🟡 | Redundanz: Zwei Benutzer-Einträge mit uneinheitlichen Bereichen („Benutzer“, „Benutzer anlegen“) wurden unter „Benutzer / Verwaltung“ zusammengeführt. | [x] Behoben in PR #120 |
| CL-157 | `Changelog.md:62` | 🟡 | Redundanz: Grenzwerte und freier Speicher wurden aus der Aufzählung der Archivprüfung entfernt, weil sie im fix-Eintrag „Backups / Wiederherstellung“ (FUN-42) stehen; Bereich einheitlich „Backups“ statt „Backup“, Schaltflächenname „Restore“ laut Oberfläche. | [x] Behoben in PR #120 |
| CL-158 | `Changelog.md:77` | 🟡 | Redundanz: Die Aussage, dass die Datenübernahme nur Datenbank und `uploads/` liest und die Plugin-Ordner nicht braucht, stand im fix-Eintrag zum Core-Update und nannte nur drei Plugins; sie steht jetzt im Integrations-Eintrag und gilt für alle fünf. | [x] Behoben in PR #120 |
| CL-159 | `Changelog.md:77` | 🟡 | Redundanz: Der Satz zur Datenübernahme nannte nur drei der fünf integrierten Plugins und doppelte den Eintrag zur Plugin-Integration; er wurde hier gestrichen. | [x] Behoben in PR #120 |
| CL-160 | `Changelog.md:77` | 🟡 | Redundanz: Der Testverweis `TESTS/update-swap` stand in mehreren Einträgen; er wurde hier entfernt und bleibt dem chore-Eintrag „Tests“ vorbehalten. | [x] Behoben in PR #120 |
| CL-161 | `Changelog.md:83, 90` | 🟡 | Redundanz: Zwei Einträge im selben Bereich „Sicherheits-Audit“ sind zu einem Eintrag mit je einem Abschnitt pro Prüfpunkt zusammengeführt (FUN-44, FUN-51). | [x] Behoben in PR #120 |
| CL-162 | `Changelog.md:86, 87` | 🟡 | Redundanz: Beide Sitemap-Einträge sind unter „SEO / Sitemap“ zusammengeführt (FUN-47, FUN-48). | [x] Behoben in PR #120 |
| CL-163 | `Changelog.md:100, 103` | 🟡 | Redundanz: Zwei Einträge zum Seiten-Editor sind zusammengeführt, der Datenverlust bei der Seitenvorlage steht vorn; Bereich jetzt „Seiten / Editor“. | [x] Behoben in PR #120 |
| CL-164 | `Changelog.md:105` | 🟡 | Redundanz: Die Beschreibung des Site-Umschalters wurde aus dem Layout-Eintrag entfernt und steht nur noch unter „Admin / MultiSites“. | [x] Behoben in PR #120 |
| CL-165 | `Changelog.md:106, 113` | 🟡 | Redundanz: Die Einträge zur Seitenleiste und zum Abschnitt „Aktive Plugins“ wurden zu einem Eintrag zusammengeführt; die doppelte Beschreibung des Aufklappens beim aktiven Eintrag und die Klassennamen des Renderings entfielen. | [x] Behoben in PR #120 |
| CL-166 | `Changelog.md:110` | 🟡 | Redundanz: „Live-Vorschau“ und „Export/Import“ standen je zweimal im Eintrag und wurden zusammengefasst; der Satz zum unveränderten Theme-Menü-Editor entfiel, der Bereich heißt jetzt „Admin / Menü-Editor“. | [x] Behoben in PR #120 |
| CL-167 | `Changelog.md:120, 121` | 🟡 | Redundanz: Editor-Layout und Fokusmodus wurden zu einem Eintrag „Beiträge / Editor“ zusammengeführt; die Veröffentlichungs-Checkliste wurde in den Folgeeintrag ausgelagert. | [x] Behoben in PR #120 |
| CL-168 | `Changelog.md:122` | 🟡 | Redundanz: Die Schema-Angabe `v24` mit Spaltenliste doppelte den Schema-Eintrag und ist hier entfernt; Detailbeschreibung des Panels gestrafft. | [x] Behoben in PR #120 |
| CL-169 | `Changelog.md:134` | 🟡 | Redundanz: Die Schema-Details sind aus dem Release-Eintrag entfernt, weil sie in den beiden Einträgen „Datenbank / Schema“ stehen. | [x] Behoben in PR #120 |
| CL-170 | `Changelog.md:23` | ⚪ | Unklare Formulierung: Die nicht belegbare Aussage über das alte Plugin („konnte Kampagnen nur als gesendet markieren und verschickte keine Bestätigungsmails“) wurde gestrichen, da der Plugin-Quelltext nicht im Repository liegt; stattdessen steht die Datenübernahme über die weiterverwendeten Tabellen `newsletter_*`. | [x] Behoben in PR #120 |
| CL-171 | `Changelog.md:25, 31, 34, 35, 36, 37` | ⚪ | Unklare Formulierung: Die Bereichsnamen wurden auf das Schema „Hauptbereich / Teilbereich“ vereinheitlicht (Inhalte, Medien, Recht, Suche), damit u. a. „Übersetzungen“ nicht mehr mit Oberflächentexten verwechselt wird. | [x] Behoben in PR #120 |
| CL-172 | `Changelog.md:32` | ⚪ | Unklare Formulierung: `/admin/info` gab es bereits in 3.5.6; der Eintrag ist jetzt als Erweiterung („zeigt zusätzlich“) formuliert, Bereich „Admin / Systeminfo“. | [x] Behoben in PR #120 |
| CL-173 | `Changelog.md:37` | ⚪ | Unklare Formulierung: „Noch verwendete Einträge brauchen eine ausdrückliche Bestätigung“ wurde an `TrashConfirmationStore`/`TrashListActions` geprüft und präzisiert: Die Bestätigung betrifft das endgültige Löschen von Einträgen mit Warnungen. | [x] Behoben in PR #120 |
| CL-174 | `Changelog.md:39` | ⚪ | Unklare Formulierung: Der Menüpfad „Einstellungen → Login & Sperren“ gilt nicht in beiden Menü-Profilen; stattdessen steht die Route `/admin/settings?tab=security` mit Capability `manage_settings`. | [x] Behoben in PR #120 |
| CL-175 | `Changelog.md:42` | ⚪ | Unklare Formulierung: Die Formulierung „Core-Queue statt der Plugin-Brücken“ war ein Implementierungsdetail ohne Benennung; die zwei entfernten Brückenmethoden sind jetzt namentlich im Schnittstellen-Eintrag genannt. | [x] Behoben in PR #120 |
| CL-176 | `Changelog.md:44` | ⚪ | Unklare Formulierung: „Grenzwert“ wurde als Abruf-Rate-Limit je IP präzisiert (standardmäßig aktiv, 30 Abrufe in 60 s, einstellbar 5–500 und 30–3600 s, HTTP 429) und die Routen wurden ausgeschrieben (`/downloads/category/<slug>`, `/downloads/<slug>`). | [x] Behoben in PR #120 |
| CL-177 | `Changelog.md:47` | ⚪ | Unklare Formulierung: „Neue Feed-Einstellungen“ und „delegiert an den bestehenden FeedService“ waren Entwicklersicht; der Eintrag stellt klar, dass `/feed` schon in 3.5.6 existierte und Einstellbarkeit und Varianten neu sind, und nennt Standardwerte, Wertebereich 1–100, die Varianten-URLs und die Capability `manage_feeds`. | [x] Behoben in PR #120 |
| CL-178 | `Changelog.md:48` | ⚪ | Unklare Formulierung: „ungeprüft“ war unscharf, weil 3.5.6 das Format der Header prüfte; jetzt „ohne Abgleich mit der eigenen Domain; geprüft wurde nur das Format“. | [x] Behoben in PR #120 |
| CL-179 | `Changelog.md:49` | ⚪ | Unklare Formulierung: Das Rate-Limit `reauth` ist mit seinem Grenzwert (10 Versuche in 15 Minuten je IP) genannt und nicht als neu dargestellt; ergänzt ist der am Code geprüfte Hinweis, dass ein Wechsel der Authenticator-App das vorherige Deaktivieren der MFA verlangt. | [x] Behoben in PR #120 |
| CL-180 | `Changelog.md:51` | ⚪ | Unklare Formulierung: „nicht auswertbar“ wurde präzisiert: Der Installer konnte die Zugangsdaten nicht aus `config/app.php` lesen (z. B. bei `getenv()`); das im Code nicht verifizierte Neuschreiben einer gelöschten Sperrdatei wurde nicht aufgenommen. | [x] Behoben in PR #120 |
| CL-181 | `Changelog.md:52` | ⚪ | Unklare Formulierung: Bereich „Importer“ war mit dem Import/Export des Cores verwechselbar und heißt jetzt „Plugin cms-importer“; ergänzt ist, dass `.svg` nicht mehr als Bild-URL erkannt wird und das Format am Inhalt geprüft wird. | [x] Behoben in PR #120 |
| CL-182 | `Changelog.md:53` | ⚪ | Unklare Formulierung: Der alte Text sprach von „unbekannten Capabilities“, tatsächlich gab 3.5.6 jede Angabe außer `admin` frei; neu sind die Regeln (leer/`member`, `admin`, sonst Rolle oder Capability) ausformuliert, ein Kompatibilitätshinweis für Plugin-Entwickler ergänzt und der Bereich in „Mitgliederbereich / Plugin-Bereiche“ umbenannt. | [x] Behoben in PR #120 |
| CL-183 | `Changelog.md:56` | ⚪ | Unklare Formulierung: Bereich „AI-Pipelines“ in „KI / SEO-Metadaten und Entwürfe“ umbenannt und der Fehler als Lücke in der Bereinigung beschrieben, ohne Aussage zur nicht verifizierten Ausnutzbarkeit. | [x] Behoben in PR #120 |
| CL-184 | `Changelog.md:58` | ⚪ | Unklare Formulierung: „mit User Verification unverändert“ wurde zu „Passkeys mit PIN oder Biometrie melden weiterhin direkt an“ ausformuliert; Bereich jetzt „Konto / Passkeys“. | [x] Behoben in PR #120 |
| CL-185 | `Changelog.md:61` | ⚪ | Unklare Formulierung: Der Bereich „Admin / Weiterleitungen“ war mit den SEO-Weiterleitungen verwechselbar und heißt jetzt „Admin / Open Redirect“; die Auswirkung (offene Weiterleitung, weil Browser `/\evil.tld` als `//evil.tld` werten) ist ausformuliert, ohne Aussage zum steuernden Parameter. | [x] Behoben in PR #120 |
| CL-186 | `Changelog.md:64` | ⚪ | Unklare Formulierung: Die Aktualisierungen sind jetzt als vorsorglich gekennzeichnet und die Symfony-Härtungen als „ohne CVE“ ausgewiesen; die Advisory-Kennungen wurden unverändert übernommen und stimmen mit `DOC/audit/FOLGEAUDIT-2026-11-08.md` überein (extern nicht verifiziert). | [x] Behoben in PR #120 |
| CL-187 | `Changelog.md:65` | ⚪ | Unklare Formulierung: Die Aufzählung der betroffenen Einstellungen (SMTP, Azure, Graph, KI-Provider, Cron-Token) ist nicht einzeln verifiziert und steht jetzt als Beispiel mit „z. B.“. | [x] Behoben in PR #120 |
| CL-188 | `Changelog.md:68` | ⚪ | Unklare Formulierung: Der elliptische Schlusssatz ist ausformuliert: Ohne *E-Mail-Verifizierung erzwingen* ist ein neues Konto sofort aktiv, eine vorhandene Adresse nicht, wodurch die Antwort unterscheidbar bleibt. | [x] Behoben in PR #120 |
| CL-189 | `Changelog.md:70` | ⚪ | Unklare Formulierung: Der in einer Tabelle nicht auflösbare Bezug „Schlüsselring wie oben“ wurde durch den Verweis auf den Eintrag „Sicherheit / Schlüsselverwaltung“ ersetzt; Bereich jetzt „Backups / Speicherort und Verschlüsselung“. | [x] Behoben in PR #120 |
| CL-190 | `Changelog.md:76` | ⚪ | Unklare Formulierung: „Ja/Nein-Felder des Manifests mit Liste statt Wert“ war unverständlich; jetzt als boolesche Manifestfelder beschrieben, die als Liste (Array) statt als Einzelwert geliefert werden. | [x] Behoben in PR #120 |
| CL-191 | `Changelog.md:77` | ⚪ | Unklare Formulierung: Die Upgrade-Warnung (alter Updater beim Update von 3.5.x löscht Zusatz-Plugins und -Themes) ging im Text unter; sie ist jetzt hervorgehoben, auf 3.5.x präzisiert und um das Wieder-Hochladen nach dem Update ergänzt. | [x] Behoben in PR #120 |
| CL-192 | `Changelog.md:80` | ⚪ | Unklare Formulierung: Vier Korrekturen standen in einem Satzgefüge mit schwer verständlichem Symlink-Halbsatz; sie stehen jetzt in eigenen kurzen Sätzen. | [x] Behoben in PR #120 |
| CL-193 | `Changelog.md:81` | ⚪ | Unklare Formulierung: Die alten Grenzen standen in MB, die neuen in GiB; jetzt einheitlich MiB/GiB (256 MiB, 512 MiB, 4 GiB, 32 GiB entpackt), und die frühere Pauschalmeldung ist im Wortlaut zitiert. | [x] Behoben in PR #120 |
| CL-194 | `Changelog.md:83` | ⚪ | Unklare Formulierung: Dass ohne `config/install.lock` weiterhin gewarnt wird, stand nur implizit da und ist jetzt ausdrücklich genannt. | [x] Behoben in PR #120 |
| CL-195 | `Changelog.md:85` | ⚪ | Unklare Formulierung: Die Klammerkette ist in Sätze aufgelöst und die gezählten Aktionen (`login`, `mfa_verify`) sind benannt; „jeder API-Aufruf“ ist zu „API-Aufrufe“ versachlicht. | [x] Behoben in PR #120 |
| CL-196 | `Changelog.md:89` | ⚪ | Unklare Formulierung: Die neue DNS-Fehlermeldung war verkürzt zitiert; jetzt steht der vollständige Wortlaut, und die beiden unabhängigen Korrekturen (DNS-Meldung, IPv6) sind getrennt dargestellt. | [x] Behoben in PR #120 |
| CL-197 | `Changelog.md:90` | ⚪ | Unklare Formulierung: Die Menüpfade *Sicherheit → Firewall* bzw. *Anti-Spam* hängen vom Menüprofil ab und sind durch die Routen `/admin/firewall` und `/admin/antispam` ersetzt; „Kontakt-Plugin“ ist zu „aktives Kontaktformular-Plugin“ präzisiert. | [x] Behoben in PR #120 |
| CL-198 | `Changelog.md:91` | ⚪ | Unklare Formulierung: Die Route `/admin/antispam` ist ergänzt, der Bereich lautet jetzt „Sicherheit / Anti-Spam“; der Inhalt ist unverändert. | [x] Behoben in PR #120 |
| CL-199 | `Changelog.md:95` | ⚪ | Unklare Formulierung: „(auch mit Anhang)“ ließ sich auf `quoted-printable` beziehen; jetzt ist getrennt: RFC-2047-Kodierung von Betreff und Absendername auch bei Mails mit Anhang, `quoted-printable` mit CRLF nur bei Mails ohne Anhang. | [x] Behoben in PR #120 |
| CL-200 | `Changelog.md:104` | ⚪ | Unklare Formulierung: „MediaRepository schreibt nur noch über updateMeta()“ klang wie eine API-Garantie und ist auf den Core eingegrenzt (`saveMeta()` bleibt öffentlich); die Datei-API ist als Hinweis für Entwickler ausgewiesen. | [x] Behoben in PR #120 |
| CL-201 | `Changelog.md:105` | ⚪ | Unklare Formulierung: Die nur stichprobenhaft geprüfte Aussage „Bisherige Admin-URLs bleiben gültig“ wurde gestrichen. | [x] Behoben in PR #120 |
| CL-202 | `Changelog.md:106` | ⚪ | Unklare Formulierung: „Manuelle Entscheidungen“ wurde als vom Benutzer auf- oder zugeklappte Gruppen ausformuliert; die Tastaturangaben wurden um Pos1/Ende ergänzt. | [x] Behoben in PR #120 |
| CL-203 | `Changelog.md:107` | ⚪ | Unklare Formulierung: Der Sidebar-Hinweis wird jetzt wörtlich zitiert („Für <Site> sind keine Plugins mit Menüeinträgen zugewiesen.“); der Methodenname `pruneUnknownSites()` und der Satz zum Filter nach der Registrierung entfielen. | [x] Behoben in PR #120 |
| CL-204 | `Changelog.md:111` | ⚪ | Unklare Formulierung: Der Satz zum Hilfe-Symbol hatte kein Objekt und lautet jetzt „verlinkt sie ebenfalls“; „Baum“ wurde zu „Menübaum“ präzisiert. | [x] Behoben in PR #120 |
| CL-205 | `Changelog.md:114` | ⚪ | Unklare Formulierung: Bereich von „Seiten-Editor / SEO-Bereiche“ auf das einheitliche Schema „Seiten / Editor“ umgestellt; Inhalt unverändert bestätigt. | [x] Behoben in PR #120 |
| CL-206 | `Changelog.md:116` | ⚪ | Unklare Formulierung: „Administratoren haben alle“ ist präzisiert (die Rolle `admin` besteht jede Capability-Prüfung, `Auth::hasCapability()`), Autoren/Mitglieder erhalten keine; die nicht gegengeprüfte Aussage „gespeicherte Rollenrechte haben Vorrang“ ist weggelassen. | [x] Behoben in PR #120 |
| CL-207 | `Changelog.md:119` | ⚪ | Unklare Formulierung: „Größen- und JSON-Prüfung“ war unbestimmt; jetzt stehen die Grenzwerte (Blockdaten 65.536 Bytes, Tunes 8.192 Bytes, sonst 413; unbekannter Blocktyp 404), die Capability `manage_pages` im Seiteneditor und `window.CMSBlockPreview.render()`. | [x] Behoben in PR #120 |
| CL-208 | `Changelog.md:120` | ⚪ | Unklare Formulierung: Die harte Sperre ohne Abschalter stand mitten in einem Layout-Eintrag; sie ist jetzt ein eigener Eintrag, als Verhaltensänderung gekennzeichnet, mit der Oberflächenbezeichnung „SEO-Beschreibung“ statt „Meta-Beschreibung“. | [x] Behoben in PR #120 |
| CL-209 | `Changelog.md:122` | ⚪ | Unklare Formulierung: Die Obergrenze von 30 Revisionen stand nur in Klammern; sie ist jetzt als Verhaltensänderung hervorgehoben (auch ältere Revisionen aus Versionen vor 4.0.0 werden bei der nächsten neuen Revision entfernt). | [x] Behoben in PR #120 |
| CL-210 | `Changelog.md:126` | ⚪ | Unklare Formulierung: Der Satzbau legte nahe, die Leerzeile nach `?>` sei ein PHPStan-Befund; sie steht jetzt als eigene Korrektur neben den PHPStan-Befunden, `cms-importer` ist als Plugin gekennzeichnet. | [x] Behoben in PR #120 |
| CL-211 | `Changelog.md:128` | ⚪ | Unklare Formulierung: Bereich auf „Sicherheit / Anti-Spam“ vereinheitlicht, `CREATE TABLE IF NOT EXISTS` präzisiert und den elliptischen Satz in altes Verhalten (Präteritum) und neues Verhalten (Präsens) getrennt. | [x] Behoben in PR #120 |
| CL-212 | `Changelog.md:129` | ⚪ | Unklare Formulierung: „hier dem Schlüsselring“ verwies auf nichts im Eintrag; jetzt ist der Zweck von `$variant` ausformuliert (Umschlüsselung gespeicherter Geheimnisse einmal je Schlüsselring) und der Eintrag in den Sammel-Eintrag „Core / Schnittstellen“ überführt. | [x] Behoben in PR #120 |
| CL-213 | `Changelog.md:130` | ⚪ | Unklare Formulierung: Das zweifach genannte, mehrdeutige `README.md` und die nicht verifizierten Detailaussagen einzelner Seiten („nur vier Konstanten“, „`LDAP_*`/`JWT_*` bleiben erhalten“) sind gestrichen. | [x] Behoben in PR #120 |
| CL-214 | `Changelog.md:131` | ⚪ | Unklare Formulierung: „OFFEN-27 bleibt offen“ war ein Projektstatus ohne Inhalt; ersetzt durch die bekannte Einschränkung im Klartext (Core-Update-Pakete nur per SHA-256 gegen das Update-Manifest geprüft, keine Signatur). | [x] Behoben in PR #120 |
| CL-215 | `Changelog.md:134` | ⚪ | Unklare Formulierung: „beide `update.json`“ und „`{update,full}.zip` mit SHA-256“ waren unbestimmt; jetzt mit vollen Pfaden, `schema_version` `v25`, `min_php` 8.4.1, `critical: true` und der Prüfsummendatei `RELEASE/365CMS-4.0.0-SHA256SUMS.txt`. | [x] Behoben in PR #120 |
| CL-216 | `Changelog.md:21` | ⚪ | Überlänge: Der Formular-Eintrag wurde in „Formulare / Verwaltung“ und „Formulare / Frontend“ geteilt, damit Verwaltung und Ausgabe/Datenschutz je einen Eintrag ohne Dopplung bilden. | [x] Behoben in PR #120 |
| CL-217 | `Changelog.md:23` | ⚪ | Überlänge: Der Newsletter-Eintrag wurde in „Newsletter / Verwaltung“ und „Newsletter / Frontend“ geteilt. | [x] Behoben in PR #120 |
| CL-218 | `Changelog.md:42` | ⚪ | Überlänge: „ehemals Plugin“ wurde auf „bisher Plugin“ vereinheitlicht, der profilabhängige Menüpfad durch die Route ersetzt und das Implementierungsdetail „Cron-Runner nutzt die Core-Queue statt der Plugin-Brücken“ gestrichen. | [x] Behoben in PR #120 |
| CL-219 | `Changelog.md:65` | ⚪ | Überlänge: Der rund 1.500 Zeichen lange Eintrag wurde in „Sicherheit / Verschlüsselung“ (Schwachstelle, Verfahren, Umschlüsselung) und „Sicherheit / Schlüsselverwaltung“ (Schlüsselring, Betreiberhinweise) geteilt. | [x] Behoben in PR #120 |
| CL-220 | `Changelog.md:65` | ⚪ | Überlänge: Die private Methode `Bootstrap::migrateStoredSecrets()` und der Implementierungssatz „`Auth` nutzt für MFA nur noch `TotpAdapter`“ wurden gestrichen. | [x] Behoben in PR #120 |
| CL-221 | `Changelog.md:73` | ⚪ | Überlänge: Inhalt unverändert übernommen, Bereich auf „Core / Routing“ vereinheitlicht und der Satz in zwei Sätze geteilt. | [x] Behoben in PR #120 |
| CL-222 | `Changelog.md:82` | ⚪ | Überlänge: Inhalt unverändert korrekt; nur sprachlich in Symptom, Ursache und Korrektur gegliedert. | [x] Behoben in PR #120 |
| CL-223 | `Changelog.md:93` | ⚪ | Überlänge: Der überlange Eintrag ist gestrafft, die Fehlerbilder stehen in getrennten Sätzen, und die Ausnahme für Allow-IPs und Konten mit `manage_settings` ist als Verhaltensänderung gekennzeichnet; Bereich jetzt „Sicherheit / Firewall“. | [x] Behoben in PR #120 |
| CL-224 | `Changelog.md:105` | ⚪ | Überlänge: Die Liste von 15 Hex-Farbwerten und die Detailangaben zu Radien wurden entfernt; der Eintrag nennt nur noch die `--cms-*`-Tokens mit Abbildung auf `--tblr-*` und verweist auf `DOC/admin/PANEL-INTEGRATION.md` (Bereich jetzt „Admin / Layout“). | [x] Behoben in PR #120 |
| CL-225 | `Changelog.md:106` | ⚪ | Überlänge: Abweichung von der Zielvorgabe: Der zusammengeführte Seitenleisten-Eintrag hat rund 1.120 Zeichen (Layout, Menü-Profile, MultiSites rund 950–1.000), weil der Plan genau eine Zeile je Eintrag verlangt und Endpunkt, Tastatur und „Aktive Plugins“ erhalten bleiben mussten. | [x] Behoben in PR #120 |
| CL-226 | `Changelog.md:109` | ⚪ | Überlänge: Implementierungsdetails (Feldliste des Katalogs, `MenuBuilder`, Aufzählung der Cache-Schlüssel) wurden gestrichen; es bleibt die Cache-Dauer von 10 Minuten. | [x] Behoben in PR #120 |
| CL-227 | `Changelog.md:112` | ⚪ | Überlänge: Der Satz „Nebenbei behoben: zusammengedrückte Fortschrittspunkte“ ist gestrichen (Entwicklungsgeschichte, in 3.5.6 nicht als sichtbarer Fehler belegt); „sechs statt fünf Schritte“ präzisiert. | [x] Behoben in PR #120 |
| CL-228 | `Changelog.md:115, 119` | ⚪ | Überlänge: Der Sammel-Eintrag mit neun Themen ist entzerrt: Testhinweise sind entfernt (chore-Eintrag „Tests“), die Editor.js-Blockregistry ist mit der Server-Vorschau aus Zeile 119 zu einem Eintrag „Core / Editor.js-Blöcke“ zusammengeführt; Doku-Verweise ergänzt. | [x] Behoben in PR #120 |
| CL-229 | `Changelog.md:120` | ⚪ | Überlänge: Editor-Layout-Beschreibung und Erfolgsmeldungen sind aus dem Checklisten-Eintrag entfernt (Layout gehört in den Editor-Eintrag, Erfolgsmeldungen entfallen). | [x] Behoben in PR #120 |
| CL-230 | `Changelog.md:122` | ⚪ | Überlänge: Hinweis fürs Protokoll: Der neue Revisions-Eintrag liegt mit rund 1.180 Zeichen über dem Längenziel, weil Obergrenze, Autosave-Intervalle, Revisionstypen und die vollständige Endpunktliste betreiber- bzw. entwicklerrelevant sind; eine Teilung in „Revisionen“ und „Editor-API“ wäre möglich. | [x] Behoben in PR #120 |
| CL-231 | `Changelog.md:126, 127` | ⚪ | Überlänge: Zwei Entwicklernotizen ohne Nutzerwirkung zu einem Eintrag mit den IDs BP-07 und BP-08 zusammengefasst. | [x] Behoben in PR #120 |
| CL-232 | `Changelog.md:130, 131, 132` | ⚪ | Überlänge: Die Aufzählungen von rund 60 geänderten Doku-Dateien (vielfach doppelt über drei Einträge) sind durch drei thematische Einträge (Audit, Administration, Core und Entwicklung) mit Bereichsangaben ersetzt. | [x] Behoben in PR #120 |
| CL-233 | `Changelog.md:133` | ⚪ | Überlänge: „Neues …“ und Testinterna (Fake-Registry, Fake-Rechteprüfung, Plugin-API, Sidebar-Modell, Schema-Teilprüfung) sind zugunsten einer Beschreibung des Prüfumfangs je Suite entfallen. | [x] Behoben in PR #120 |

**Außerhalb dieses PRs festgestellt (Übergabe an Prüfbereich 4)**

- `CMS/update.json` und `CMS/marketplace/core/365cms/update.json` nennen im Feld `notes` nur drei der fünf übernommenen Plugins (`cms-contact`, `cms-downloads`, `cms-feed`); `cms-newsletter` und `cms-knowledgebase` fehlen. Die Dateien gehören zu den Release-Manifesten und werden in diesem PR nicht geändert.

### 2. Sicherheit und bekannte Exploits

**Prüfpunkte**

- SQL-Injection: Prepared Statements und Parameter-Binding, zusammengesetzte Abfragen (`ORDER BY`, `LIMIT`, `IN`), dynamische Tabellen- und Spaltennamen
- XSS: Ausgabe-Escaping in Admin, Mitgliederbereich, Theme und E-Mail-Vorlagen, Inline-Skripte, JSON in HTML
- CSRF: Token-Prüfung bei allen zustandsändernden Anfragen (Formulare, AJAX, API)
- Anmeldung und Sitzungen: Cookie-Flags, Sitzungswechsel nach Anmeldung, Passwort-Hashing, Schutz vor Durchprobieren, Rechteprüfung und Zugriff auf fremde Datensätze (IDOR)
- Datei-Uploads, Pfade und Codeausführung: MIME-Typ, Endungen, Speicherort, `include`/`require` mit variablen Pfaden, `unserialize()`, `eval()`, Shell-Aufrufe
- Bibliotheken: gebündelte PHP- und JavaScript-Bibliotheken gegen bekannte CVEs

**Befunde**

| ID | Fundstelle | Schwere | Beschreibung | Status |
|---|---|---|---|---|
| – | – | – | Prüfung steht aus (PR 2) | – |

### 3. Fehlende Funktionen und unvollständige Logik

**Prüfpunkte**

- `TODO`, `FIXME`, leere Funktionsrümpfe, auskommentierter Code, halb umgesetzte Funktionen aus dem Umfang von 4.0.0
- Anlegen, Lesen, Bearbeiten und Löschen je Bereich: Randfälle, Validierung, Rückmeldungen bei Erfolg und Fehler

**Befunde**

| ID | Fundstelle | Schwere | Beschreibung | Status |
|---|---|---|---|---|
| – | – | – | Prüfung steht aus (PR 3) | – |

### 4. Fehlende oder falsche Verweise

**Prüfpunkte**

- `require`/`include`/`use` und Namespaces: Pfade und Groß-/Kleinschreibung (Linux)
- Verweise auf CSS, JavaScript, Bilder und Schriften, interne Links, Weiterleitungen, API-Endpunkte
- Tabellen- und Spaltennamen im PHP-Code gegen das Schema (`SchemaManager`, Migrationen)

**Befunde**

| ID | Fundstelle | Schwere | Beschreibung | Status |
|---|---|---|---|---|
| – | – | – | Prüfung steht aus (PR 4) | – |

### 5. Falsche Funktionen und veraltete PHP-/SQL-Konstrukte

**Prüfpunkte**

- Logikfehler, falsche Rückgabetypen, Typkonflikte (`strict_types`, PHP 8.4/8.5), veraltete Funktionen
- Funktionsaufrufe: Reihenfolge und Vollständigkeit der Parameter, ungenutzte Rückgabewerte
- Fehlerbehandlung: `try`/`catch`, Protokollierung, keine Systempfade oder Zugangsdaten in der Ausgabe

**Befunde**

| ID | Fundstelle | Schwere | Beschreibung | Status |
|---|---|---|---|---|
| – | – | – | Prüfung steht aus (PR 5) | – |

### 6. Geschwindigkeit

**Prüfpunkte**

- Datenbank: Abfragen in Schleifen (N+1), fehlende Indizes auf `WHERE`-, `JOIN`- und `ORDER BY`-Spalten, `SELECT *`, nicht freigegebene Ressourcen
- PHP und Frontend: Caching, Dateizugriffe in Schleifen, Array- und String-Operationen, Ladereihenfolge der Assets (`defer`/`async`)

**Befunde**

| ID | Fundstelle | Schwere | Beschreibung | Status |
|---|---|---|---|---|
| – | – | – | Prüfung steht aus (PR 6) | – |

### 7. Best Practices, Code-Qualität und Abschlussprüfung

**Prüfpunkte**

- PSR-12, Trennung von Logik und Darstellung, doppelter Code, einheitliche Benennung
- Konfiguration: Beispielkonfiguration, `.htaccess`, Sicherheits-Header (CSP, `X-Frame-Options`, HSTS)
- Abschluss: alle Befunde dieser Datei erledigt, `Changelog.md` unter `4.0.0` vollständig

**Befunde**

| ID | Fundstelle | Schwere | Beschreibung | Status |
|---|---|---|---|---|
| – | – | – | Prüfung steht aus (PR 7) | – |
