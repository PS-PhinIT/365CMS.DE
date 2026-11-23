# 365CMS Audit-Katalog 4.2.0

> **Stand:** 2026-11-23 | **Release-Baseline:** 4.2.0 / Schema v27 | **Tag:** `V4.2.0`

Dieser Katalog ist die gemeinsame Prüfliste für den Delta-Audit nach 4.2.0 und
den vollständigen Vollaudit. Die Ablaufreihenfolge steht in
[AUDIT-ABLAUF-PLAN-DELTA.md](AUDIT-ABLAUF-PLAN-DELTA.md) bzw.
[AUDIT-ABLAUF-PLAN-VOLLAUDIT.md](AUDIT-ABLAUF-PLAN-VOLLAUDIT.md).

## Regeln

1. Code und Laufzeitvertrag sind Wahrheit; ein Grep-Treffer ist nur ein Hinweis.
2. Jeder Befund enthält Fundstelle, reproduzierbaren Ablauf, Auswirkung, Nachweis
   und Regressionstest.
3. Bewusste Entscheidungen und behobene historische Befunde nicht erneut melden.
4. Test-Suiten werden mit `php TESTS/run-all.php` dynamisch ermittelt. Keine feste
   Suitenanzahl in Berichten oder Checklisten.
5. Keine Produktivdaten, keine externen Ziele, keine Secrets.
6. CRITICAL/HIGH zuerst; ein kleiner Fix mit Test ist einem breiten Refactoring
   vorzuziehen.

## Schweregrade

| Stufe | Definition |
|---|---|
| CRITICAL | System-/Kontozugriff ohne Berechtigung, Codeausführung, massenhafter Datenabfluss |
| HIGH | Rechteausweitung, fremde private Daten, gespeichertes XSS, SQLi/SSRF, Datenverlust |
| MEDIUM | CSRF, Informationsabfluss, Race, fehlerhafte Zähler/Löschung, leichtes DoS |
| LOW | harte Randfälle, Logging-/Fehlermängel, kleine Funktions- oder Verweisfehler |
| INFO | Best Practice oder Produktvorschlag ohne aktuell belegtes Fehlverhalten |

## Arbeitspakete

| AP | Bereich | Kernprüfung |
|---|---|---|
| AP-01 | Anmeldung & Sitzung | Session, Passwort, MFA, Passkeys, SSO, LDAP, JWT, Account-Aufzählung |
| AP-02 | Autorisierung & Objektzugriff | Admin/Capability/CSRF, IDOR, Rollen, Sites, Datei- und Downloadpfade |
| AP-03 | Eingaben / Injection / Traversal / SSRF | SQL, Pfade, Archive, Commands, Include, XML/JSON/YAML/CSV, Host/Redirect |
| AP-04 | Ausgabe / XSS / CSP / Vorlagen | Escaping, Purifier, Markdown, DOM-XSS, Trusted Types, Content-Disposition |
| AP-05 | CSRF / Header / Cookies | Tokens, keine GET-Mutation, CSP/HSTS/Frame/CORS, sichere Cookies |
| AP-06 | Uploads / Medien / Dateien / Backups | MIME/Magic, Pixelbomben, Speicher, Range, Zip, private Auslieferung |
| AP-07 | Kryptografie / Geheimnisse / Konfiguration | Zufall, AEAD, KDF/Hashes, Secret-Lecks, Schlüsselrotation, sichere Defaults |
| AP-08 | Zahlungen / Abos / Webhooks | Signatur, Idempotenz, Beträge, Zustandsautomat, Rechnungen, Wiederholung |
| AP-09 | Installation / Update / Marketplace / Plugins | Installer, atomarer Update, Signatur/Hash, Schema-Reihenfolge, Archive |
| AP-10 | Datenbank / Migration / Konsistenz | Neuinstallation=Update, Strict Mode, Transaktionen, Deadlocks, Cursors, Wachstum |
| AP-11 | Community 4.2.0 | Access, 404-Uniformität, Gate, S1-Zähler, Privacy, Benachrichtigung, API, Anhänge, Last |
| AP-12 | Geschwindigkeit / Skalierung | Queryzahl, N+1, EXPLAIN, Cache, Stampede, Queue, Bots, Frontend-Laufzeit |
| AP-13 | Funktionen / Verweise / Konsistenz | Routen, Hooks, Optionen, Sprache, Klassen, tote UI, Doku-Inventare |
| AP-14 | Barrierefreiheit / Oberfläche | axe, Tastatur, Fokus, Formfehler, Reflow, Kontrast, Mehrsprachigkeit |
| AP-15 | Best Practice / Codequalität | PHP 8.4/8.5, catches, Logging, zentrale Services, Tests und Mutanten |
| AP-16 | Drittbibliotheken / Lieferkette | Version, Lizenz, Prüfsumme, Upstream-Drift, CI/Actions, Parsergrenzen |
| AP-17 | Datenschutz / Compliance | Export, Löschung, Retention, Consent, Protokolle, Rechtstexte |
| AP-18 | Betrieb / Härtung / Restore | Webserverregeln, Debug-Reste, Cron/CLI, Backup-Rückweg, Monitoring |
| AP-19 | KI | SSRF, Schlüssel, Prompt-Injection, Ausgabe-Sanitizing, Quota, Datenoffenlegung |
| AP-20 | Integration / Austausch | Mail, Import, Feeds, Downloads, KB, Entra, LDAP, S3, externe Ausfälle |

## Befundformat

```text
ID: SEC-420-<laufend>
Schwere: HIGH
Fundstelle: CMS/...php:Zeile (Funktion/Methode)
Ablauf: 1. ... 2. ... 3. ...
Auswirkung: ...
Nachweis: fokussierter Test/Befehl, der vor dem Fix fehlschlägt
Korrektur: ...
Regression: Suite und Gegenprobe
Status: offen | umgesetzt | akzeptiert | verworfen
```

Bereiche: `SEC`, `COM`, `DB`, `FUN`, `PERF`, `BP`, `LIB`, `PRIV`, `OPS`, `A11Y`, `AI`.

## Pflichtlauf

```bash
php TESTS/run-all.php
php -l <geaenderte.php>
node --check <geaenderte.js>
php TESTS/release-package/run.php
git diff --check
```

Zusätzliche Datenbank-, Browser-, Lighthouse-, axe-, MariaDB- oder externe
Dienst-Prüfungen werden nur mit reproduzierbarer Testumgebung ausgeführt und
sonst als `nicht geprüft` mit Vorgehen protokolliert.

## Verbindliche Ausnahmen

- 4.2.x, 4.3 und 4.4 werden nicht aus einem Audit heraus als Featurebeweis
  behandelt; Befunde werden in den passenden Plan eingeordnet.
- Archivbefunde unter `old_audits/` sind ohne erneuten Nachweis keine aktiven
  Befunde.
- `OFFEN-27` und `PERF-410-49` sind in
  [OFFENE-BEFUNDE.md](OFFENE-BEFUNDE.md) separat geführt.
