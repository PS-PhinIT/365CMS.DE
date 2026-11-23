# 365CMS Audit-Ablaufplan: Delta seit letztem Release

> **Stand:** 2026-11-23 | **Releasebasis:** letzter Tag `V*` (aktuell `V4.2.0`) | **Modus:** Änderungen seit Release plus abhängige Oberflächen
> **Was:** [AUDIT-KATALOG-4.2.0.md](AUDIT-KATALOG-4.2.0.md) · **Wie:** [AUDIT-AGENT-PROTOKOLL.md](AUDIT-AGENT-PROTOKOLL.md) · **Slices:** [AUDIT-SLICES.md](AUDIT-SLICES.md) · **Lokal:** [AUDIT-LOCAL-LLM.md](AUDIT-LOCAL-LLM.md) · **Gegenstück:** [Vollaudit](AUDIT-ABLAUF-PLAN-VOLLAUDIT.md)

## English (summary)

Step-by-step runbook for an audit of everything that changed since the last released tag, including the contracts
those changes can affect. It is written so that Claude (Opus/Sonnet), Gemini, Codex or a local model can execute it
autonomously: every phase has inputs, commands, outputs in the run folder, a gate and the prompt to use. Typical
uses: patch releases (4.2.x), pre-merge review of a larger PR, follow-up after a hotfix.

## Deutsch

### 0. Wann Delta, wann Vollaudit?

| Situation | Plan |
|---|---|
| Patch-Release 4.2.x, Hotfix, Pre-Merge-Prüfung eines PR | **Delta** |
| Delta umfasst > 30 % der Slices oder ändert `SchemaManager`, `Security`, `Auth`, `Router` grundlegend | Vollaudit |
| Feature-Release (4.3, 4.4), letzter Vollaudit > 6 Monate oder externer Prüfbedarf | Vollaudit |
| Zwischen zwei Releases ohne Tag | Delta gegen den letzten Tag **und** Vermerk „unreleased“ im Bericht |

### 1. Rollen, Modelle, Zeitrahmen

| Rolle | Empfehlung Cloud | Empfehlung lokal (R9700) |
|---|---|---|
| Orchestrator, Konsolidierung | Claude Opus · GPT-5-Klasse (Codex, hohes Reasoning) · Gemini Pro | nicht empfohlen; Ergebnis lokal vorbereiten, Konsolidierung in der Cloud |
| Slice-Prüfer | Claude Sonnet · Gemini Pro/Flash · Codex | Qwen3.6-27B (Tiefe) bzw. Qwen3.6-35B-A3B (Tempo), siehe [AUDIT-LOCAL-LLM.md](AUDIT-LOCAL-LLM.md) |
| Verifizierer | andere Modellfamilie als der Finder | lokal finden → Cloud verifizieren |
| Umsetzer | Claude Sonnet/Opus (Claude Code) · Codex CLI · Gemini CLI | Devstral Small 2 / Qwen3-Coder-30B-A3B mit Agent-Werkzeug, nur LOW/MEDIUM |

Richtwert: kleines Delta (≤ 3 Slices) 1–3 Agentensitzungen; mittleres Delta (4–10 Slices) einen Arbeitstag; darüber
Vollaudit erwägen. Jede Sitzung endet mit Checkpoint im Ledger (Protokoll §3).

### 2. Phase D0 – Lauf anlegen und Baseline einfrieren

**Prompt:** P0 aus dem [Protokoll](AUDIT-AGENT-PROTOKOLL.md#10-prompt-bausteine) mit Plan „DELTA“.

```bash
git status --short                                   # muss leer sein oder Abweichung im Ledger begründen
git fetch --tags                                     # nur wenn Netzwerk erlaubt
BASE=$(git tag --list 'V*' --sort=-v:refname | head -n 1)   # aktuell V4.2.0
git rev-parse "$BASE" HEAD
git describe --tags --always --dirty
git diff --name-status "$BASE"..HEAD
git diff --stat "$BASE"..HEAD
git log --oneline "$BASE"..HEAD
git diff --check "$BASE"..HEAD
php -v                                               # >= 8.4.1
php TESTS/run-all.php                                # Ausgangsstand, Ergebnis ins Ledger
```

PowerShell: `$BASE = git tag --list 'V*' --sort=-v:refname | Select-Object -First 1`, sonst identisch.

Zusätzlich die Releasekonsistenz der **Baseline** festhalten: `CMS/core/Version.php` (`CURRENT`, Schema),
`CMS/update.json`, `CMS/marketplace/core/365cms/update.json`, `RELEASE/365CMS-<Version>-*`, Abschnitt in
`Changelog.md`. Fehlt ein Tag, wird die Baseline daraus bestimmt – nie geschätzt.

**Ausgabe:** `runs/<YYYY-MM-DD>-delta/STATE.md` (Kopf, Ledger), `COMMANDS.log`.
**Tor G0:** Baseline- und Ziel-SHA stehen fest; Ausgangsergebnis der Tests ist protokolliert (rote Suiten im
Ausgangsstand sind **kein** Befund des Deltas, aber ein Blocker-Eintrag).

### 3. Phase D1 – Änderungsinventar und Slice-Zuordnung

Jede geänderte Datei bekommt eine Zeile in `INVENTORY.md`:

| Datei | Art (A/M/D/R) | Slice | AP (primär) | Suiten | Risiko | Grund |
|---|---|---|---|---|---|---|

Zuordnung über [AUDIT-SLICES.md §3](AUDIT-SLICES.md#3-slices). Risikostufe:

| Risiko | Kriterium |
|---|---|
| **hoch** | Anmeldung, Rechte, Router, Eingabe-/Ausgabe-Bereinigung, Uploads, Update/Install, Zahlungen, Schema, Kryptografie, Community-Zugriff |
| **mittel** | Dienste mit Personendaten, Cron/Queues, Importe, Admin-Module, Hooks |
| **niedrig** | reine Ansichten ohne Fremddaten, CSS, Doku, Tests |

Erweiterungsregeln (geänderte Datei → zusätzlich prüfen):

| Geändert | Zusätzlich |
|---|---|
| `CMS/core/*.php`, `Services/*` | alle Aufrufer der geänderten öffentlichen Methoden (`rg -n 'name\('`), Hooks, DI/Container, Tests, Doku |
| `core/Routing/*`, `admin/*.php`, `member/*` | Routen, Rechte, CSRF-Aktion, Vorlagen, Sprachschlüssel, Menüeintrag (`Services/AdminMenu/*`) |
| `SchemaManager`, `MigrationManager`, `DatabaseUpdateRunner` | gesamter Slice K04, Neuinstallation **und** Upgrade v26→v27(+), `REQUIRED_*`, `TESTS/schema-*`, `DOC/core/DATABASE-SCHEMA.md` |
| Views, Theme, `assets/js` | Escaping, CSP/Nonce, Trusted Types, `InlineStyleRewriter`, Barrierefreiheit |
| API, Payments, Uploads, Webhooks | Authentisierung, Scope, SSRF, Idempotenz, Limits, Audit-Log |
| `Services/Community/**` | `CommunityAccess`, alle Nebenwege aus Katalog 11.1, `WriteGate`/`ContentGuard`/`CaptchaGate`, `CounterBook`, Privacy/Retention, Queue, Keyset |
| Hook hinzugefügt/umbenannt | `DOC/core/HOOKS-REFERENCE.md`, alle Listener, Plugins (`cms-importer`) |
| Capability/Rolle | `DOC/admin/users-groups/RBAC.md`, SSO-Mapping (`moderator`-Ausschluss), `DelegatedAdminAccess::PAGES` |
| `lang/*.yaml` | Schlüsselgleichheit de/en, Platzhalter |
| `TESTS/*` | Suite läuft in `run-all.php`, `.gitignore`-Whitelist, Inventare `DOC/FILELIST.md`/`FILESTRUCTUR.md` |
| `RELEASE/`, `update.json`, `Version.php` | Version, Schema, SHA-256 in allen drei Manifesten, Paketinhalt, README/Changelog, `TESTS/release-package` |
| `.github/workflows/*`, `.github/deps/*` | AP-16.5, Trivy-/Semgrep-Konfiguration |

Abhängige Dateien werden im Inventar mit Art `abh.` geführt. Für Slices mit Risiko **hoch** wird der **ganze Slice**
geprüft, nicht nur der Diff.

**Ausgabe:** `INVENTORY.md`, Ledgerzeilen je betroffenem Slice (`offen`), `CONTRACTS.md` (Prompt P1, nur für die
betroffenen Verträge).
**Tor:** jede geänderte Datei hat Slice, AP und Suite; Delta-Umfang (Anzahl Slices) entschieden (§0).

### 4. Phase D2 – Mechanische Vorprüfung (Skripte, kein LLM)

```bash
for f in $(git diff --name-only --diff-filter=AMR "$BASE"..HEAD -- '*.php'); do php -l "$f"; done
for f in $(git diff --name-only --diff-filter=AMR "$BASE"..HEAD -- '*.js'); do node --check "$f"; done
git diff -U0 "$BASE"..HEAD -- CMS | rg '^\+' | rg -n '\b(eval|unserialize|shell_exec|exec|system|passthru|proc_open|popen|md5|sha1|rand|mt_rand)\s*\(|innerHTML|insertAdjacentHTML|\$_(GET|POST|REQUEST|COOKIE|FILES)'
git diff -U0 "$BASE"..HEAD -- CMS | rg '^\+' | rg -n '\b(TODO|FIXME|XXX|HACK)\b'
git diff "$BASE"..HEAD -- CMS/lang                  # neue Schlüssel in beiden Sprachen?
```

Treffer werden als **Hinweise** in `COMMANDS.log` notiert und in D3 dem Slice-Prüfer mitgegeben. Zusätzlich die
Such-Rezepte aus [Katalog Anhang B](AUDIT-KATALOG-4.2.0.md#anhang-b-such-rezepte-hinweise-keine-befunde) auf
die geänderten Dateien beschränken.

### 5. Phase D3 – Slice-Prüfung

Reihenfolge nach Risiko: erst alle **hoch**-Slices in der Folge AP-02 → AP-03 → AP-04 → AP-09 → AP-11 → AP-01 →
AP-06 → AP-08 → AP-10, dann **mittel**, dann **niedrig**.

Je Slice (Prompt **P2**):

1. Dateien des Slices + `CONTRACTS.md` + Hinweise aus D2 laden (Budget [Slices §1](AUDIT-SLICES.md#1-budgetregel)).
2. Die primären AP-Prüfpunkte des Slices **und** AP-13/AP-15 beantworten; bei Diff-Dateien zusätzlich: Was hat sich
   gegenüber der Baseline am Verhalten geändert (`git diff "$BASE"..HEAD -- <datei>`)? Regression?
3. `CHECKS.md`, `FINDINGS.jsonl`, `findings/*.md` schreiben; Ledgerzeile `erledigt`; Kontext verdichten.

Pflicht-Fragen im Delta zusätzlich zum Katalog:

- Wurde ein Guard (Rechte, CSRF, Rate-Limit, Escape) entfernt, verschoben oder hinter eine Bedingung gelegt?
- Gibt es einen **neuen** Eingang (Route, Parameter, AJAX-Aktion, Hook, Cron-Job, Import-Format)?
- Ändert sich eine Datenform (Spalte, JSON-Struktur, Option), und lesen alte Daten/Plugins sie noch?
- Sind neue Strings übersetzt, neue Hooks dokumentiert, neue Suiten in den Inventaren?

### 6. Phase D4 – Verifikation

Prompt **P3** mit einem anderen Modell bzw. frischem Kontext. Jeder Kandidat ≥ MEDIUM braucht `verified_by`.
CRITICAL/HIGH zusätzlich mit Reproduktion (Suite oder `runs/<run-id>/repro/`). Ergebnis: Status `bestätigt`,
`verworfen` oder `dublette`.

### 7. Phase D5 – Tests

```bash
php TESTS/run-all.php                         # vollständig, Suitenzahl nicht hart kodieren
php TESTS/<suite>/run.php                     # je betroffener Suite aus INVENTORY.md, mit -v falls unterstützt
php TESTS/release-package/run.php             # wenn RELEASE/, update.json oder Version.php betroffen
CMS_TEST_DB_HOST=… CMS_TEST_DB_USER=… php TESTS/schema-upgrade/run.php   # wenn Schema betroffen; sonst "offen"
```

Fehlende Testabdeckung für einen geänderten Sicherheits- oder Datenpfad ist selbst ein Befund (`BP`, mindestens LOW).

### 8. Phase D6 – Umsetzung (optional, nach Freigabe)

Nur für bestätigte Befunde. Prompt **P4** (Umsetzer), danach **P5** (Prüfer der Umsetzung). Regeln aus
[Katalog §5](AUDIT-KATALOG-4.2.0.md#5-umsetzungsregeln). Keine Versionsänderung, keine Release-Pakete im
Audit-Lauf – das passiert am Release-Schnitt.

### 9. Phase D7 – Einordnung und Bericht

Routing der Befunde:

| Art | Ziel |
|---|---|
| CRITICAL, aktiv ausnutzbar | sofort, eigener Hotfix (4.2.x), vertrauliche Meldung |
| Regression gegenüber Baseline | 4.2.x → [../v4.2.X/CATALOG.md](../v4.2.X/CATALOG.md) |
| unbehandelter Altbefund | [Offene Befunde](audits/README.md#offene-befunde) + passender Katalog |
| Betriebsfunktion (CLI, Alerting) | [../v4.3/CATALOG.md](../v4.3/CATALOG.md) |
| Custom Fields, Sprachen, Live-Vorschau, Managed API | [../v4.4/CATALOG.md](../v4.4/CATALOG.md) |
| Community P2/P3 | nur nach vorhandener Priorisierung in [../STATUS.md](../STATUS.md) |

Bericht (Prompt **P7**): `DOC_PLAN/audit/audits/AUDIT-DELTA-<YYYY-MM-DD>.md`

```markdown
# 365CMS Delta-Audit <YYYY-MM-DD>

> **Baseline:** <Tag> = <sha> | **Ziel:** <sha> (<describe>) | **Version im Code:** <v> / Schema <v> | **Modelle:** …

## English (summary)
<3–6 Sätze: Umfang, Zahl der Befunde je Schwere, wichtigste Punkte, Status>

## Deutsch
### 1. Umfang
Dateiliste (Kurzform), betroffene Slices, Impact-Matrix (Slice × AP), ausgeschlossene AP mit Begründung.
### 2. Ergebnis je AP
Tabelle AP | geprüft | Befunde | offen.
### 3. Befunde
Tabelle ID | Schwere | Titel | Status | Ziel; danach Details im Format des Protokolls §5.1 (ohne Exploit-Details bei offenen Punkten).
### 4. Verworfen / akzeptiert / Dubletten
### 5. Tests und Umgebung
run-all-Ergebnis vorher/nachher, Einzelsuiten, DB-/Browser-Einschränkungen.
### 6. Release- und Paketstatus
Version, Schema, Manifeste, SHA-256, Changelog.
### 7. Offene Punkte
mit Link auf 4.2.X / 4.3 / 4.4 / „Offene Befunde“ im Audit-Index.
```

Danach [Offene Befunde](audits/README.md#offene-befunde) und den [Index](audits/README.md) aktualisieren.
**Tor G4:** Links gültig, `git diff --check` sauber.

### 10. Abbruchkriterien

- Baseline lässt sich nicht eindeutig bestimmen;
- Version, Schema oder Manifeste widersprechen sich (Befund `OPS`, Lauf pausieren);
- ein geänderter Sicherheits- oder Datenpfad ist nicht testbar und nicht statisch belegbar;
- Suiten scheitern bereits im Baseline-Commit und überdecken das Delta;
- Produktiv- oder Fremddaten wären nötig.

In diesen Fällen wird ein reproduzierbarer Blocker im Ledger und im Bericht dokumentiert. Das Fehlen eines Belegs
wird nie als „grün“ gewertet.

### 11. Schnellstart (Kopiervorlage)

```text
Führe einen Delta-Audit von 365CMS nach DOC_PLAN/audit/AUDIT-ABLAUF-PLAN-DELTA.md aus.
Run-ID: <YYYY-MM-DD>-delta. Halte dich an DOC_PLAN/audit/AUDIT-AGENT-PROTOKOLL.md (Ledger, Befundformat, Regeln).
Beginne mit Phase D0 und D1, ändere keinen Code, und melde nach jeder Phase: Ledger-Stand, nächste Aktion, Blocker.
```
