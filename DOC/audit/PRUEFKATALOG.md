# 365CMS – Gesamtaudit 2026-10-14 | Prüfkatalog und Methodik

> Übersicht: [README.md](README.md)

## English (summary)

The audit checklist combines OWASP Top 10:2025 with current website and CMS audit checklists. Each item below lists what was checked in 365CMS and where the result is documented. Items that need production data or external services (field data, uptime, penetration test, legal review) are marked as out of scope.

## Deutsch

### Quellen

Die Prüfpunkte wurden aus folgenden öffentlichen Checklisten zusammengestellt (Abruf 2026-10-14):

| Quelle | Schwerpunkt |
|---|---|
| OWASP Top 10:2025 – <https://owasp.org/Top10/2025/> | Sicherheitsrisiken von Webanwendungen (A01–A10) |
| SpotDev – „Website Audit Checklist“ – <https://www.spotdev.co.uk/> | Technik, SEO, Geschwindigkeit, Sicherheit, Inhalte |
| Optimum Web – „Website Security Audit Checklist“ – <https://www.optimum-web.com/> | Server-Härtung, Updates, Backups, Zugriffskontrolle, Logging |
| OpenSense Labs – „CMS Audit Checklist“ – <https://opensenselabs.com/> | CMS-spezifisch: Rollen, Plugins, Inhalte, Performance, Barrierefreiheit |
| Vorgänger-Audits – [../checks/README.md](../checks/README.md), [../checks/FOLGEAUDIT-2026-10-07.md](../checks/FOLGEAUDIT-2026-10-07.md) | Nachprüfung bereits behobener Punkte, Nummernkreise |

### Prüfpunkte und Abdeckung

Legende: ✅ geprüft · ➖ nicht anwendbar oder außerhalb des Umfangs (Begründung)

#### Sicherheit (OWASP Top 10:2025)

| Nr. | Prüfpunkt | Abdeckung | Ergebnis |
|---|---|---|---|
| A01 | Zugriffskontrolle: Capabilities, IDOR, private Dateien, Entwürfe | ✅ | SEC-19, SEC-21, SEC-27 |
| A02 | Fehlkonfiguration: Debug, Header, CSP, Verzeichnisschutz, Installer | ✅ | SEC-20, GUI-01 (CSP blockierte eigene Skripte) |
| A03 | Software-Lieferkette: gebündelte Bibliotheken, CI-Actions | ✅ | SEC-26; gebündelte Bibliotheken in `CMS/assets/` nicht inhaltlich geprüft |
| A04 | Kryptografie: Passwort-Hashing, Tokens, TOTP | ✅ | ohne Befund (bcrypt, `random_bytes`, HMAC); TOTP-Replay siehe SEC-16 |
| A05 | Injection: SQL, XSS, CSV, Header | ✅ | SEC-24, SEC-25 |
| A06 | Unsicheres Design: Limits, Missbrauchsfälle | ✅ | SEC-21, SEC-23, LAST-01 bis LAST-05 |
| A07 | Authentifizierung: Brute Force, MFA, Re-Auth, Sessions | ✅ | SEC-16, SEC-17, SEC-18 |
| A08 | Integrität von Software und Daten: Updates, Uploads, Importer | ✅ | SEC-19 |
| A09 | Logging und Alarmierung: Audit-Log, Log-Flut, Aufbewahrung | ✅ | LAST-01, LAST-05, OFFEN-13 |
| A10 | Fehlerbehandlung: Fatal Errors in Fehlerpfaden, Informationsabfluss | ✅ | REF-06 bis REF-10 |

#### Geschwindigkeit

| Prüfpunkt | Abdeckung | Ergebnis |
|---|---|---|
| Abfragen je Request, N+1, wiederholte Abfragen | ✅ (MySQL-General-Log) | PERF-07 bis PERF-10, Messwerte in [PERFORMANCE-LAST.md](PERFORMANCE-LAST.md) |
| DDL oder Metadaten-Abfragen im Request-Pfad | ✅ | PERF-07 |
| Zusätzliche Verbindungen, Session-Sperren | ✅ | PERF-08, LAST-06 |
| `SELECT *` und große Spalten in Listen | ✅ | PERF-11, OFFEN-02 |
| Caching (HTML-Cache, Options-Cache, Sitemaps, Feed) | ✅ | OFFEN-03 |
| Core Web Vitals im Feld | ➖ | keine Produktivdaten |

#### Massenanfragen, Bots und Wachstum

| Prüfpunkt | Abdeckung | Ergebnis |
|---|---|---|
| Rate-Limits je öffentlichem Schreib- oder Such-Endpunkt | ✅ | LAST-02, LAST-03, SEC-21 |
| Schreibverstärkung (ein Request → mehrere INSERTs) | ✅ | LAST-01, LAST-04 |
| Unbegrenztes Tabellenwachstum, Aufbewahrungsfristen | ✅ | LAST-04, LAST-05 |
| Ermittlung der Client-IP hinter einem Proxy | ✅ | SEC-22 |
| Cache-Fähigkeit für anonyme Besucher | ✅ | OFFEN-01 |
| Lasttest gegen Produktivhardware | ➖ | außerhalb der Prüfumgebung |

#### Funktionen und Verweise

| Prüfpunkt | Abdeckung | Ergebnis |
|---|---|---|
| Gespeicherte, aber nicht ausgewertete Einstellungen | ✅ | FUN-21, FUN-22, OFFEN-07 |
| Formularfelder ohne Speicherlogik | ✅ | FUN-23, FUN-24 |
| Links oder Aktionen ohne Handler | ✅ | FUN-25 |
| Aufrufe nicht existierender Klassen oder Methoden, falsche Namespaces | ✅ | REF-06 bis REF-10 |
| Schema-Unterschiede zwischen Neuinstallation und Update | ✅ | REF-11, SEC-17 |
| Toter Code | ✅ | OFFEN-08 |
| Dokumentationsverweise | ✅ | OFFEN-09 |

#### GUI und Barrierefreiheit

| Prüfpunkt | Abdeckung | Ergebnis |
|---|---|---|
| Skripte unter der aktiven CSP lauffähig | ✅ | GUI-01 |
| Bestätigung vor destruktiven Aktionen | ✅ | GUI-02, GUI-03 |
| Texte, Umlaute, Entwicklertexte | ✅ | GUI-04 |
| Dialoge, ARIA, Labels | ✅ (statisch) | GUI-05, OFFEN-10 |
| Rückmeldung bei Fehlern und Limits, Formularwerte erhalten | ✅ | GUI-06, GUI-07 |
| Unterverzeichnis-Installation (Links) | ✅ | OFFEN-11 |

#### Betrieb und Recht

| Prüfpunkt | Abdeckung | Ergebnis |
|---|---|---|
| Webserver-Konfiguration (Apache und nginx) | ✅ | SEC-19, Doku in [../INSTALLATION.md](../INSTALLATION.md) |
| Backups (Upload-Pfad) | ✅ | REF-10 |
| Datensparsamkeit und Löschfristen (DSGVO Art. 5) | ✅ | LAST-05, OFFEN-13 |
| CI-Sicherheitsprüfungen | ✅ | SEC-26 |
| Rechtliche Prüfung (Impressum, Datenschutzerklärung) | ➖ | inhaltlich Sache des Betreibers |
| Penetrationstest, Uptime-Monitoring | ➖ | außerhalb des Umfangs |

### Vorgehen

1. **Statische Analyse:** in vier parallelen Strängen mit gezielten Suchmustern.
   - Rate-Limits, `REMOTE_ADDR`, `innerHTML` und `<script>` ohne Nonce.
   - `instanceof`/`use` gegen vorhandene Klassen und Methodenaufrufe gegen Definitionen.
   - Optionen: Lesezugriffe gegen Admin-Felder.
   - DDL im Request-Pfad.
2. **Verifikation:** Jeder Treffer wurde manuell am Code bestätigt; Fehlalarme sind nicht aufgenommen.
3. **Laufzeit:** Neuinstallation auf MySQL 8.0, danach Abfragezählung, Rate-Limit-Tests, Speichertests im Admin und Seitenaufrufe aller betroffenen Admin-Seiten. Details in [README.md](README.md).
4. **Umsetzung:** in kleinen Schritten, nach jedem Schritt `php -l` und ein erneuter Laufzeittest.
