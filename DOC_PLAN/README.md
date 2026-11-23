# 365CMS Planungs- und Workflow-Dokumentation

> **Stand:** 2026-11-23 | **Aktueller Produktstand:** 4.2.0 / Schema v27

`DOC_PLAN/` bündelt operative Workflows, verbindliche Architekturentscheidungen und historische
Umsetzungspläne. `DOC/workflow` existiert nicht mehr und darf weder als Ablage noch als Linkziel
verwendet werden.

## Zentrale Navigation

1. [STATUS.md](STATUS.md) – verbindliche Gesamtübersicht und einzige Liste der aktuell offenen Punkte
2. [Roadmap Inhaltsplattform](ROADMAP-CONTENT-PLATFORM.md) – Freigabe-Workflow, eigene Felder/Typen und weitere Sprachen
3. [Release 4.1](v4.1/README.md) – ausgelieferte Bereiche 1–5 und der nach 4.3.0 verschobene Bereich 6
4. [Release 4.2: Community](v4.2/README.md) – ausgeliefertes Community-Modul, P2/P3- und Abnahmerest
5. [Release 4.2.x](v4.2.X/README.md) – Patches und P1-Abnahmereste
6. [Release 4.3](v4.3/README.md) – CLI und Alerting
7. [Release 4.4](v4.4/README.md) – Custom Fields/Typen, N Sprachen, Live Preview und Managed API

## Operative Workflows

| Dokument | Zweck |
|---|---|
| [API-Integration](../DOC/workflow/API-INTEGRATION-WORKFLOW.md) | Core-API verwenden und eigene Plugin-Endpunkte bereitstellen |
| [Content Management](../DOC/workflow/CONTENT-MANAGEMENT-WORKFLOW.md) | Seiten und Beiträge anlegen, prüfen, planen und veröffentlichen |
| [Medien-Upload](../DOC/workflow/MEDIA-UPLOAD-WORKFLOW.md) | Uploadwege, Validierung, Nachverarbeitung und Auslieferung |
| [Marketplace](../DOC/workflow/MARKETPLACE-WORKFLOW.md) | Plugins/Themes installieren oder veröffentlichen |
| [Update und Deployment](../DOC/workflow/UPDATE-DEPLOYMENT-WORKFLOW.md) | Backup, Core-/Datenbank-Update, Deployment und Rollback |

## Übergreifende Spezifikationen

| Dokument | Bedeutung |
|---|---|
| [Admin-Menü-Profile](ADMIN-MENU-PROFILE.md) | Umgesetzte Menüstruktur für Classic, Structured und Custom |
| [Verbindliche Klarstellungen](KLARSTELLUNGEN.md) | Historische, weiterhin geltende Architekturentscheidungen |
| [Plugin-Parität](PLUGIN-PARITY.md) | Nachweis der 4.0.0-Core-Integration ehemals separater Plugins |
| [Inhaltsplattform](ROADMAP-CONTENT-PLATFORM.md) | Ausgelieferter Freigabe-Ablauf und offene Teile B/C |

## Versionen

### 4.1.0

- ✅ Identität, Konto-Sicherheit und SSO
- ✅ Medien- und Nutzungsindex
- ✅ Redaktions-Workflow, Blockvorlagen und Inhaltsablauf
- ✅ Offsite-Backups und Webhooks
- ✅ Zahlungen und Rechnungen
- 🟡 CLI und Echtzeit-Alerting → 4.3.0

Details und Liefernachweise: [v4.1/PROGRESS.md](v4.1/PROGRESS.md)

### 4.2.0

- ✅ Community-Kernmodul, Moderation, Suche, Profile, Benachrichtigungen und Schema v27
- 🟡 Community P2/P3 und externe Abnahmepunkte

Details und Restumfang: [v4.2/ROADMAP.md](v4.2/ROADMAP.md)

### 4.3.0

- 🟡 CLI und Echtzeit-Alerting
- 🟡 gemeinsamer Preflight-/Worker-Betriebsvertrag

Details: [v4.3/README.md](v4.3/README.md)

### 4.4.0

- 🔴 Custom Fields und eigene Inhaltstypen
- 🔴 normalisiertes Modell für beliebig viele Sprachen
- 🔴 Live-Splitscreen-Vorschau
- 🔴 Managed Public API mit Client Credentials/API-Keys und OpenAPI

Details: [v4.4/README.md](v4.4/README.md)

## Pflegehinweise

- Statusänderungen zuerst in [STATUS.md](STATUS.md) eintragen, danach die betroffene Detaildatei.
- Ausgelieferte Versionen bleiben als historische Baseline erhalten.
- Neue Pläne nach Hauptversion direkt unter `DOC_PLAN/v<major>.<minor>/` ablegen.
- Keine Pfade oder Verweise auf `DOC/workflow` wieder einführen.
