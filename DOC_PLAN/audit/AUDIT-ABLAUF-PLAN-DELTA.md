# 365CMS Audit-Ablaufplan: Delta seit letztem Release

> **Stand:** 2026-11-23 | **Releasebasis:** Tag `V4.2.0` | **Modus:** nur Änderungen und deren abhängige Oberflächen

## Ziel

Der Delta-Audit prüft alles, was seit der letzten freigegebenen Version geändert
wurde, plus alle Verträge, die durch die Änderung beeinflusst werden können.
Er ist für Patch-Releases, Pre-Merge-Prüfung und Nachträge nach 4.2.0 gedacht.

## 1. Baseline einfrieren

```bash
git status --short
git rev-parse V4.2.0
git rev-parse HEAD
git describe --tags --always --dirty
git diff --name-status V4.2.0..HEAD
git diff --stat V4.2.0..HEAD
```

Wird kein Release-Tag gefunden, wird die Baseline aus `CMS/core/Version.php`,
`Changelog.md`, beiden `update.json` und `RELEASE/365CMS-<Version>-*` ermittelt.
Die feste Version darf nicht geschätzt werden.

## 2. Änderungsinventar

| Änderung | Erweiterung der Prüfung |
|---|---|
| `CMS/core/*.php`, `Services/*` | Aufrufer, Hooks, DI, Tests, Doku und Releasevertrag |
| Router/Admin/Member | Routen, Rechte, CSRF, Vorlagen, Sprachschlüssel |
| Schema/Migration | Neuinstallation, v26/v27-Upgrade, Repair, REQUIRED_* und MariaDB |
| Theme/Assets/Views | Escaping, CSP, Trusted Types, Reflow und Browserpfad |
| API/Payment/Upload/Webhook | Auth, Scope, SSRF, Idempotenz, Limits und Audit |
| Community | `CommunityAccess`, Nebenwege, Gate, Zähler, Privacy, Queue, Paginierung |
| Tests/Doku | Inventare, Linkziele, dynamische Discovery, keine erfundenen Zahlen |
| `RELEASE/`, `update.json`, `Version.php` | Version, Schema, SHA-256, Paketinhalt, README/Changelog |

## 3. Abhängigkeiten erweitern

Für jede geänderte Datei:

1. Definitionen/Aufrufer ermitteln;
2. geänderte Hooks, Routen, Capabilities, Sprachschlüssel und Optionen inventarisieren;
3. betroffene Tests und Gegenproben auswählen;
4. Dokumentation aus [AUDIT-KATALOG-4.2.0.md](AUDIT-KATALOG-4.2.0.md) dem AP zuordnen;
5. bei Schema, Sicherheit oder Releasepaket den vollständigen AP statt nur der
   geänderten Zeilen prüfen.

## 4. Prüf-Reihenfolge

1. baseline `php TESTS/run-all.php`;
2. Diff-Normalisierung und `git diff --check`;
3. CRITICAL/HIGH-nahe AP-02, AP-03, AP-04, AP-09, AP-11 für betroffene Flächen;
4. übrige betroffene AP;
5. fokussierte Test-Suite je Änderung;
6. `php TESTS/run-all.php` nochmals;
7. `php TESTS/release-package/run.php`;
8. Paket-/Manifest-/Doku-Abgleich.

Der Delta-Audit darf nicht nur die geänderte Funktion lesen: Jede Änderung an
einem Hook, einer Capability, einem Provider, einem Zähler oder einer
Retentionsregel erzwingt die Gegenprüfung ihrer Nebenwege.

## 5. Befund und Entscheidung

Für jeden Befund:

- im 4.2.0-Releasevertrag belegen;
- als Regression, unbehandelten Altbefund oder neue 4.x-Anforderung trennen;
- Regression → 4.2.x;
- Betriebsfeature → 4.3;
- Custom Fields/Sprachen/Preview/Managed API → 4.4;
- Community-P2/P3 nur nach vorhandener Priorisierung.

## 6. Ausgabe

Datei:

```text
DOC_PLAN/audit/AUDIT-DELTA-<YYYY-MM-DD>.md
```

Pflichtinhalt:

- Baseline-Tag und Ziel-Commit;
- Dateiliste und Impact-Matrix;
- geprüfte AP und ausgeschlossene AP mit Begründung;
- Befunde und Regressionen;
- getestete Suiten, DB-/Browser-Einschränkungen;
- Release-/Paketstatus;
- offene Punkte mit Link zu 4.2.X/4.3/4.4.

## 7. Abbruchkriterien

- Baseline lässt sich nicht eindeutig bestimmen;
- Release-Version, Schema oder Manifeste widersprechen sich;
- ein geänderter Sicherheits-/Datenpfad ist nicht testbar;
- ein lokaler Testfall scheitert bereits im Baseline-Commit;
- Produktiv-/Externdaten wären nötig.

In diesen Fällen wird ein reproduzierbarer Blocker dokumentiert; das Fehlen eines
Belegs darf nicht als „grün“ gewertet werden.
