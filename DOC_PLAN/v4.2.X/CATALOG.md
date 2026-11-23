# 365CMS 4.2.x: Katalog

> **Stand:** 2026-11-23 | **Baseline:** 4.2.0 / Schema v27

## A. Verbindliche Restabnahme

| ID | Pri. | Status | Punkt | Nachweis | 4.2.x-Ergebnis |
|---|---|---|---|---|---|
| 42X-01 | MUST | [ ] | Core-Vorlagen gegen das reale externe `cms-phinit`-Theme integrieren und abnehmen | [v4.2/ROADMAP.md](../v4.2/ROADMAP.md), WP-C10 | Community bleibt im Fremd-Theme optisch, barrierefrei und funktional nutzbar; Overrides dokumentiert |
| 42X-02 | MUST | [ ] | 10.000 Themen / 500.000 Beiträge auf realer MariaDB/MySQL messen | [v4.2/ROADMAP.md](../v4.2/ROADMAP.md), WP-C13 | reproduzierbarer Lastlauf, Abfragebudget, EXPLAIN-Befunde und Schwellenwerte dokumentiert |
| 42X-03 | MUST | [ ] | MariaDB-Prüflauf in CI verankern | [v4.2/ROADMAP.md](../v4.2/ROADMAP.md), WP-C13 | Schema, Upgrades, Community-Pfade und Zeichensatzkollation gegen MariaDB geprüft |
| 42X-04 | MUST | [ ] | manuelles Screenreader-Sampling dokumentieren | [v4.2/ROADMAP.md](../v4.2/ROADMAP.md), WP-C13 | Start, Forum, Thema, Schreiben, Vorschau, Moderation und Fehlerdialoge mit nachvollziehbarem Protokoll |
| 42X-05 | SHOULD | [ ] | Bild-Einbettung im visuellen Community-Editor prüfen bzw. schließen | [v4.2/ROADMAP.md](../v4.2/ROADMAP.md), WP-C4 | Upload, bestehender Anhang, Vorschau, Markdown-Quelle und Rechteprüfung funktionieren konsistent |

Die Punkte 42X-01 bis 42X-05 erweitern den Community-Umfang nicht. Sie schließen
ausdrücklich dokumentierte P1-Abnahmen ab.

## B. Sicherheits- und Release-Nacharbeiten

### 42X-06 – Signierte Update-Pakete

**Priorität:** MUST als Sicherheitsziel; Umsetzung nur, wenn Signaturprüfung und
Schlüsselverteilung rückwärtskompatibel ausrollbar sind.

**Offener Stand:** SHA-256-Prüfung ist vorhanden, stammt aber aus derselben
Update-Quelle wie das Paket. Die separate Signatur fehlt.

**4.2.x-Scope:**

- Signaturformat und Vertrauensanker festlegen;
- öffentliche Schlüssel über einen vom Update-Feed getrennten Pfad verteilen;
- gültige Pakete akzeptieren, fehlende/falsche Signaturen vor Entpacken ablehnen;
- alte, noch nicht signierte 4.1/4.2-Pakete ausdrücklich behandeln;
- Schlüsselrotation, Kompromittierung und Rollback dokumentieren;
- `TESTS/release-package` und Update-Preflight erweitern.

**Nicht im Scope:** neuer Marketplace oder signierte Plugin-Themes, sofern beides
nicht zwingend mit dem Core-Vertrag verbunden ist.

### 42X-07 – Umfragen: Ist-Stand und Vertrag eindeutig machen

**Priorität:** MUST · **Status:** VERIFY

Die v27-Tabellen, Berechtigungsprüfungen und Changelog behaupten Umfragen,
während die Community-Roadmap Umfragen weiterhin unter P2 führt. Vor einem
4.2.x-Release ist ein Ende-zu-Ende-Nachweis erforderlich:

- Anlegen und Bearbeiten einer Umfrage;
- Einfach-/Mehrfachwahl und Ablaufdatum;
- Abstimmen, erneute Abstimmung soweit erlaubt, Berechnung und Sichtbarkeit;
- Rechte, private Foren, Löschung und Export/Retention;
- Verhalten auf kleinen/mobilen Ansichten und mit Tastatur.

Fehlt nur eine Doku-Zeile, wird 42X-07 als erledigt dokumentiert. Fehlt produktiver
Code, wird es als 4.2.x-Bug bewertet, wenn die bereits angekündigte 4.2.0-Funktion
betroffen ist; sonst wandert der Umfang nach 4.3/P2.

## C. Regressionsschutz für ausgelieferte 4.2.0-Verträge

Die folgenden Einträge sind **keine vorab bestätigten Fehler**. Sie werden nur mit
reproduzierbarem Befund oder bei einem fehlschlagenden Regressionstest in einen
Patch aufgenommen.

| ID | Pri. | Bereich | Pflichtprüfung vor Einstufung |
|---|---|---|---|
| 42X-08 | MUST | Schema v27 / Upgrade | Neuinstallation, Upgrade v26/v27, reparierte Schema-Elemente, `capabilities_v27`, 118/28/284/65-Vertrag |
| 42X-09 | MUST | Rollen / SSO | `moderator`, Auto-Link-Ausschluss, Rollen-Mapping, `cms_role_renamed`, `cms_role_deleted`, `user_role_changed` |
| 42X-10 | SHOULD | Captcha | `pow`/`image`/`math`, Fallback, Einmalverbrauch, Kontext, Registrierung, Formulare, Community, JS-Ausfall |
| 42X-11 | SHOULD | Medien | verlustfreier Strip, ICC/Ausrichtung, 8 EXIF-Orientierungen, Alpha, Original-vs.-Derivat-Pfade |
| 42X-12 | SHOULD | Redirects | 250/Seite, stabile Reihenfolge, kein Hintergrund-Reload, `updated_at` bei Treffer unverändert |
| 42X-13 | MUST | Payments | provisorische/finale Bestellnummer, Webhook-Idempotenz, Refund, `current_period_end` UTC, lokale Kern-Zeitspalten |
| 42X-14 | MUST | Community-Privacy | `anonymize`/`delete`, `deleted_at`, Export, Papierkorb, 24-h-verwaiste Anhänge, aktive Fristen bei Modul `off` |
| 42X-15 | SHOULD | Hooks | Triggerreihenfolge, Parameter, Commit-Nähe, Fehlerisolierung und Dokumentation der 4.2-Hooks |

Ein Patch erhält nur die konkrete, nachgewiesene Korrektur. Kein Eintrag aus dieser
Matrix rechtfertigt eine pauschale Neuimplementierung.

## D. Ausschlüsse

Folgende Punkte sind **nicht** Bestandteil von 4.2.x:

- neue CLI und `alert.raised`;
- eigene Feldgruppen, Inhaltstypen oder beliebige Sprachen;
- Community-P2/P3;
- Config-as-Code, OpenAPI-generierte Verträge oder neue API-Schlüsseltypen;
- neue Medien-Speichertreiber, Low-Code-Automationen oder große Schema-Migrationen;
- Marktvergleichs-Wünsche ohne 4.2-Vertragsbruch.
