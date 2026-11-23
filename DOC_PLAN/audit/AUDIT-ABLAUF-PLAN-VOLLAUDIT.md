# 365CMS Audit-Ablaufplan: Vollaudit

> **Stand:** 2026-11-23 | **Release:** 4.2.0 / Schema v27 | **Modus:** gesamter Erstanbieter-Code, Laufzeit, Lieferkette, Doku
> **Was:** [AUDIT-KATALOG-4.2.0.md](AUDIT-KATALOG-4.2.0.md) · **Wie:** [AUDIT-AGENT-PROTOKOLL.md](AUDIT-AGENT-PROTOKOLL.md) · **Slices:** [AUDIT-SLICES.md](AUDIT-SLICES.md) · **Lokal:** [AUDIT-LOCAL-LLM.md](AUDIT-LOCAL-LLM.md) · **Gegenstück:** [Delta](AUDIT-ABLAUF-PLAN-DELTA.md)

## English (summary)

Runbook for a complete audit of 365CMS: all first-party code (~5.4M tokens, therefore sliced), runtime behaviour
(database, browser), supply chain, tests and documentation. Nine phases (V0–V8) with inputs, commands, outputs,
gates and prompts; a fixed slice schedule that covers AP-01…AP-20; a parallel plan for multi-agent setups; dedicated
runtime procedures; and a report template. Designed for autonomous execution by Claude Opus/Sonnet, Gemini, Codex or
local models with ≥256k context, with cross-model verification.

## Deutsch

### 0. Anlass und Umfang

Vollaudit bei Feature-Release (4.3, 4.4), nach größeren Architekturänderungen, wenn der letzte Vollaudit älter als
sechs Monate ist, wenn ein Delta zu groß wird (siehe [Delta §0](AUDIT-ABLAUF-PLAN-DELTA.md#0-wann-delta-wann-vollaudit))
oder bei externem Prüfbedarf.

**Im Umfang:** `CMS/` ohne `CMS/vendor/`; gebündelte Bibliotheken unter `CMS/assets/` nur in AP-16; Theme
`cms-default`; Plugin `cms-importer`; `TESTS/`; `.github/`; `RELEASE/`; `DOC/` und `DOC_PLAN/` (AP-13.7).
**Nicht im Umfang:** Produktivsysteme, fremde Themes/Plugins, Hosting-Konfiguration außer den mitgelieferten Beispielen.

### 1. Rollen und Besetzung

| Rolle | Cloud | Lokal (R9700) |
|---|---|---|
| Orchestrator, Konsolidierung, Bericht | Claude Opus · GPT-5-Klasse (Codex, hohes Reasoning) · Gemini Pro | – |
| Slice-Prüfer Sicherheit (AP-01–09, 11) | Claude Sonnet/Opus · Codex · Gemini Pro | Qwen3.6-27B (Thinking) |
| Slice-Prüfer Breite (AP-10, 12–20) | Gemini Pro/Flash · Claude Sonnet · Codex | Qwen3.6-35B-A3B |
| Inventar/Querverweise (Phase V1) | Gemini (großes Fenster) · Claude Sonnet | Qwen3.6-35B-A3B, Nemotron 3 Nano |
| Verifizierer | jeweils **andere** Familie als der Finder | lokal nur Vorfilter |
| Umsetzer | Claude Code (Sonnet/Opus) · Codex CLI · Gemini CLI | Devstral Small 2, Qwen3-Coder-30B-A3B (nur LOW/MEDIUM) |

### 2. Phase V0 – Lauf anlegen, Baseline, Beweismittel

**Prompt:** P0 mit Plan „VOLLAUDIT“, Run-ID `<YYYY-MM-DD>-voll`.

```bash
git status --short
git rev-parse HEAD
git describe --tags --always --dirty
php -v                                   # >= 8.4.1; wenn verfügbar zusätzlich 8.5 für Lint
node --version
php TESTS/run-all.php                    # Ausgangsstand
php TESTS/release-package/run.php
for f in $(git ls-files 'CMS/*.php' | grep -v '^CMS/vendor/'); do php -l "$f" >/dev/null || echo "LINT $f"; done
```

Umgebung für Laufzeitprüfungen (wenn verfügbar, sonst Prüfpunkte als `offen` führen):

| Mittel | Zweck | Hinweis |
|---|---|---|
| MariaDB/MySQL (Docker) | Schema, Upgrade, EXPLAIN, Wettläufe | `CMS_TEST_DB_HOST`, `CMS_TEST_DB_USER` für `schema-upgrade` |
| PHP 8.4 + 8.5 | Lint, Deprecations | CI-Gate spiegeln |
| Chromium/Playwright | Editor, A11y, Lighthouse | Suiten `community-a11y`, `community-lighthouse` als Vorlage |
| Testinstallation | Laufzeit, Last | `php -S` mit Router oder Docker; nie Produktion |
| Semgrep, Trivy | CI-Spiegel | gleiche Konfiguration wie `.github/workflows/security.yml` |

**Ausgabe:** `STATE.md`, `COMMANDS.log`. **Tor G0** laut Protokoll.

### 3. Phase V1 – Inventar (Querschnitt)

Ziel: eine belegte Landkarte, gegen die alle Slices geprüft werden. Weitgehend per Skript, das Modell fasst zusammen
und gleicht mit der Doku ab. Ergebnisse in `INVENTORY.md` (Tabellen) und `CONTRACTS.md` (Prompt P1).

| Nr. | Inventar | Ermittlung | Abgleich mit |
|---|---|---|---|
| I1 | Routen und direkte PHP-Einstiege | `rg -n "addRoute\(|->(get|post|any)\(" CMS/core/Routing CMS/plugins`; `git ls-files 'CMS/*.php' 'CMS/admin/*.php' 'CMS/member/*.php'` | `DOC/core/ARCHITECTURE.md`, `DOC/core/API-REFERENCE.md`, `DOC/member/MEMBER-ROUTES.md` |
| I2 | Admin-/Member-Seiten, Capabilities, CSRF-Aktionen | `rg -n "requireCapability|hasCapability|isAdmin\(|verifyToken|csrf" CMS/admin CMS/member` | `DOC/admin/users-groups/RBAC.md`, `php TESTS/admin-menu/run.php -v` |
| I3 | Hooks (Actions/Filter) | Rezept Katalog Anhang B | `DOC/core/HOOKS-REFERENCE.md` |
| I4 | Dienste, Provider, Container-Aliase | `git ls-files CMS/core/Services`; `rg -n "Container::|->bind\(|->singleton\(" CMS/core` | `DOC/core/SERVICES.md`, `DOC/CMSFILESTRUCTUR.md` |
| I5 | Tabellen, Spalten, Indizes | `TESTS/schema-v27` ausführen; `SchemaManager::getSchemaQueries()` lesen | `DOC/core/DATABASE-SCHEMA.md` |
| I6 | Einstellungen und Optionen | `rg -on "get_option\('[a-z0-9_]+'|getOption\('[a-z0-9_]+'" CMS` | Admin-Oberflächen (AP-13.3) |
| I7 | Sprachschlüssel | Skript: Schlüsselmengen `de.yaml`/`en.yaml`, Platzhalter | AP-13.4 |
| I8 | Upload-, Export-, Import-, Backup-, Auslieferungs-Endpunkte | `rg -n "\$_FILES|Content-Disposition|readfile\(|fpassthru\(" CMS -g '!CMS/vendor/**'` | AP-06 |
| I9 | Externe Aufrufe | `rg -n "curl_init|Http\\\\Client|file_get_contents\(\s*['\"]https?" CMS -g '!CMS/vendor/**'` | AP-03.5, AP-20 |
| I10 | Secrets und Konfigurationskonstanten | `rg -n "define\('CMS_|_SECRET|_KEY|password" CMS/config.php CMS/install CMS/core/Services/SettingsService.php` | AP-07 |
| I11 | Cron-Jobs und Queues | `rg -n "registerJob|cron_|schedule" CMS/core` | AP-12.7 |
| I12 | Releasepakete, Manifeste, Hashes | `RELEASE/`, beide `update.json`, `Version.php`; `TESTS/release-package` | AP-09.6 |
| I13 | Bibliotheken | `CMS/assets/*/`, `.github/deps/`, Versionen/Lizenzen | AP-16 |
| I14 | Tests | `git ls-files 'TESTS/*/run.php'` (Zahl nur berichten, nicht festschreiben) | `DOC/FILELIST.md`, `DOC/FILESTRUCTUR.md` |

**Tor V1:** jedes Inventar hat Quelle (Befehl/Datei) und Abgleichsergebnis; Abweichungen sind AP-13-Kandidaten.

### 4. Phase V2 – Mechanische Prüfung (Skripte)

1. Such-Rezepte aus [Katalog Anhang B](AUDIT-KATALOG-4.2.0.md#anhang-b-such-rezepte-hinweise-keine-befunde) über
   den gesamten Umfang; Treffer je Slice in `COMMANDS.log` gruppieren.
2. Semgrep (`semgrep --config auto CMS --exclude CMS/vendor`) und Trivy (`trivy fs .github/deps`) lokal, falls
   installiert; sonst letzte CI-Ergebnisse auswerten (AP-15.7).
3. PHP-Lint 8.4/8.5 (Deprecations), `node --check` für alle eigenen JS-Dateien.
4. Datenskripte: Glossar-CSV (Formel-Präfixe), Sprachschlüssel, Doku-Links (`DOC/`, `DOC_PLAN/`).

Ergebnisse sind **Hinweise** für Phase V3, keine Befunde.

### 5. Phase V3 – Slice-Fahrplan

Reihenfolge folgt dem Risiko (AP-02 → 03 → 04 → 01 → 11 → 06 → 09 → 08 → 07 → 05 → 10 → 12 → 13 → 17 → 16 → 18 →
19 → 20 → 14 → 15). Jede Zeile ist eine Agentensitzung (Prompt **P2**); nach jeder Sitzung Checkpoint.
Querschnitts-AP-13/AP-15 laufen in jedem Slice mit.

| Welle | Slices | Schwerpunkt-AP | Modellklasse |
|---|---|---|---|
| W1 Fundament | K01, K02a, K02b, K03 | 01, 02, 03, 04, 05, 07 | stark (Sicherheit) |
| W2 Admin-Einstiege | A01, A02 | 02, 05 | stark |
| W3 Community | C1, C2, C3, C4, C5 | 11, 02, 04, 06, 10, 17 | stark |
| W4 Dateien, Update | R2, D3, K04, P1a, P1b | 06, 09, 10, 03 | stark |
| W5 Zahlungen, Integrationen | D5, R3, D1, D2, D4 | 08, 20, 19, 17, 03 | stark/mittel |
| W6 Admin-Module | AM1 … AM6 | 02, 04, 05, 13 | mittel |
| W7 Admin-Ansichten | AV1 … AV7 | 04, 13, 14 | mittel |
| W8 Dienste Rest | R1, R4, R5, D6, D7, K05 | 04, 12, 13, 17, 15 | mittel |
| W9 Frontend | F1, F2, F3a, F3b, F4a, F4b, F5, F6 | 04, 05, 14, 17 | mittel |
| W10 Querschnitt | AP-16 (Inventar I13), AP-18 (Betrieb), AP-14 (Browser), AP-12 (Messung) | 12, 14, 16, 18 | Skripte + mittel |

Je Slice zusätzlich prüfen: Ergebnisse aus V1 (z. B. Route ohne Doku, Hook ohne Auslöser) und V2-Hinweise des Slices.

#### 5.1 Parallelbetrieb

Wellen sind innerhalb sich unabhängig; mehrere Agenten dürfen gleichzeitig verschiedene Slices bearbeiten, wenn
jeder nur **eigene** Zeilen in `CHECKS.md` schreibt und Befunde in `FINDINGS.jsonl` **anhängt** (nie umschreibt).
Bei konkurrierenden Schreibzugriffen alternativ je Agent `FINDINGS-<agent>.jsonl` und Zusammenführung in V5.
Der Orchestrator vergibt Slices über das Ledger (`in Arbeit` + Agentname), damit nichts doppelt läuft.

#### 5.2 Halb-Slices für lokale Modelle

Lokale Prüfer verwenden `a`/`b`-Hälften jedes Slices (alphabetisch nach Pfad geteilt) und die Kontextstrategie aus
[AUDIT-LOCAL-LLM.md §6](AUDIT-LOCAL-LLM.md#6-kontextstrategie-für-256k). Lokale Kandidaten ≥ MEDIUM werden immer
mit einem Cloud-Modell verifiziert.

### 6. Phase V4 – Laufzeitprüfungen

| Nr. | Prüfung | Vorgehen | AP |
|---|---|---|---|
| L1 | Neuinstallation = Upgrade | Installation v27 frisch; Installation v26 + Update; Schema-Diff (Tabellen, Spalten, Indizes, Kollation) | 10.1 |
| L2 | Abfragen je Seite | Abfrageprotokoll für Start, Beitrag, Listen, Suche, 404, Forum, Thema, Admin-Listen | 12.1 |
| L3 | EXPLAIN großer Listen | Testdaten (≥ 100k Beiträge/Community-Posts), `EXPLAIN` + `Handler_read_rnd_next` | 12.2, 11.13 |
| L4 | Wettläufe | parallele Requests auf Zähler, Slugs, Rechnungsnummern, Upload-Zuordnung, Abo-Status | 10.2–10.4, 08.3–08.4 |
| L5 | Header/CSP | `curl -sI` auf Startseite, Login, Admin, Mitglied, Datei-Auslieferung | 05.3, 04.4, 04.9 |
| L6 | Browser | axe-core, Tastatur, Lighthouse mobil für Kernseiten | 14, 12.8 |
| L7 | Update-Pfad | Paket aus `RELEASE/` auf Testinstallation einspielen, Rollback erzwingen | 09.2, 18.4 |
| L8 | Backup/Restore | Backup erstellen, auf leerer Instanz wiederherstellen, Integrität prüfen | 06.7, 18.4 |
| L9 | Last/Bots | `k6`/`wrk` gegen Testinstallation: Suche, Login, Registrierung, 404, Forum | 12.5 |
| L10 | Datenschutz-Ende-zu-Ende | Konto mit Inhalten → Export → Löschung (`anonymize` und `delete`) → Restdaten-Suche in allen Tabellen | 17.1, 11.6 |

Nicht ausführbare Prüfungen werden mit genauem Prüfvorgehen als `offen` geführt.

### 7. Phase V5 – Verifikation und Konsolidierung

1. Prompt **P3** für alle Kandidaten; Finder ≠ Verifizierer (Modellfamilie).
2. Dubletten über Slices zusammenführen (gleiche Ursache = ein Befund mit mehreren Fundstellen).
3. Endgültige IDs `<BEREICH>-<RELEASE>-<NR>` vergeben (Katalog §3.2).
4. `CHECKS.md` auf Vollständigkeit prüfen: jeder Prüfpunkt AP-01 … AP-20 hat mindestens einen Eintrag.

**Tor G2** laut Protokoll.

### 8. Phase V6 – Umsetzung

- CRITICAL/HIGH zuerst und einzeln (eigener PR je Befund), dann MEDIUM gebündelt je AP.
- Prompt **P4** (Umsetzer) → **P5** (Prüfer, andere Modellfamilie).
- Jede Korrektur mit Regressionstest, der ohne Fix fehlschlägt; Mutant, wo vorhanden.
- Inventare, Hooks, Schema, Sprachdateien und Doku mitpflegen ([Katalog §5](AUDIT-KATALOG-4.2.0.md#5-umsetzungsregeln)).
- Keine Release-Pakete im Lauf; Exploit-Details, Zugangsdaten, Personendaten nie in Bericht oder PR-Text.

### 9. Phase V7 – Gegenprüfung

```bash
php -l <alle geänderten PHP-Dateien>
node --check <alle geänderten JS-Dateien>
php TESTS/run-all.php
php TESTS/release-package/run.php
git diff --check
```

Zusätzlich: betroffene AP-Prüfpunkte an allen geänderten Stellen erneut beantworten; L1/L5/L6 an geänderten
Bereichen wiederholen; prüfen, dass keine historische Auditbehauptung als neuer Befund wiederkehrt.

### 10. Phase V8 – Bericht

Prompt **P7**. Datei: `DOC_PLAN/audit/audits/AUDIT-VOLLAUDIT-<YYYY-MM-DD>.md`

```markdown
# 365CMS Vollaudit <YYYY-MM-DD>

> **Commit:** <sha> (<describe>) | **Version:** <v> / Schema <v> | **Umgebung:** PHP …, DB …, Browser … | **Modelle:** Orchestrator …, Prüfer …, Verifizierer …

## English (summary)
## Deutsch
### 1. Umfang und Methode (Slices, Wellen, Laufzeitprüfungen, Abweichungen vom Plan)
### 2. Befundzahlen je Schwere und AP (Tabelle)
### 3. Befunde (Tabelle, dann Details im Format Protokoll §5.1)
### 4. Akzeptiert / verworfen / Dubletten mit Begründung
### 5. Geprüft ohne Befund je AP (Prüfpunkt-Matrix verdichtet)
### 6. Nicht prüfbar, mit konkretem Folgeablauf
### 7. Verifikation (Tests vorher/nachher, Mutanten, DB, Browser, Last)
### 8. Offene Punkte mit Releasezuordnung (4.2.X / 4.3 / 4.4 / Offene Befunde im Index)
### 9. Vergleich zum letzten Vollaudit (neu, behoben, unverändert)
```

Danach [Offene Befunde](audits/README.md#offene-befunde), [Index](audits/README.md) und
[AUDIT-ZUSAMMENFASSUNG-AKTUELL.md](AUDIT-ZUSAMMENFASSUNG-AKTUELL.md) fortschreiben.

### 11. Abnahme

- AP-01 bis AP-20 vollständig beantwortet (`CHECKS.md`).
- Alle CRITICAL/HIGH korrigiert oder ausdrücklich akzeptiert; MEDIUM korrigiert oder terminiert.
- `php TESTS/run-all.php` grün; keine neuen CI-Sicherheitsbefunde.
- Paket-, Doku- und Auditstatus konsistent; Links gültig.
- Historisches Auditarchiv unverändert belegt.

### 12. Abbruchkriterien

Wie [Delta §10](AUDIT-ABLAUF-PLAN-DELTA.md#10-abbruchkriterien); zusätzlich: Ziel-Commit ändert sich während des
Laufs (neue Commits auf `main`) → betroffene Slices im Ledger zurücksetzen oder Lauf auf festen Commit/Branch pinnen.

### 13. Schnellstart (Kopiervorlage)

```text
Führe einen Vollaudit von 365CMS nach DOC_PLAN/audit/AUDIT-ABLAUF-PLAN-VOLLAUDIT.md aus.
Run-ID: <YYYY-MM-DD>-voll. Halte dich strikt an DOC_PLAN/audit/AUDIT-AGENT-PROTOKOLL.md.
Starte mit V0 und V1, ändere keinen Code, verteile danach die Slices der Welle W1 und melde nach jeder Phase
Ledger-Stand, nächste Aktion und Blocker.
```
