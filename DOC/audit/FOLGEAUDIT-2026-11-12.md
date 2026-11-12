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
| 1 | Changelog 4.0.0, Anlage dieser Datei | CL | PR 1 | in Arbeit |
| 2 | Sicherheit und bekannte Exploits | SEC, DEP | PR 2 | offen |
| 3 | Fehlende Funktionen und unvollständige Logik | FUN | PR 3 | offen |
| 4 | Fehlende oder falsche Verweise | REF | PR 4 | offen |
| 5 | Falsche Funktionen, veraltete PHP-/SQL-Konstrukte | FUN, BP | PR 5 | offen |
| 6 | Geschwindigkeit (PHP, MySQL/MariaDB, Frontend) | PERF | PR 6 | offen |
| 7 | Best Practices, Code-Qualität, Abschlussprüfung | BP | PR 7 | offen |

### 1. Changelog 4.0.0

Folgt in diesem PR.

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
