# 365CMS – Projektdokumentation | Abschnitt: Core – Übersicht

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Schema:** v23 | **Status:** Stable

## English (summary)

This folder documents the runtime core in `CMS/core/`: bootstrap and request lifecycle, routing, classes and services, database schema, hooks, JSON API, security model, directory structure and the current implementation status. All statements were verified against the source code on 2026-10-02.

## Deutsch

### Dokumente

| Dokument | Inhalt |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | Schichten, Einstiegspunkte, Laufzeitmodi, Bootstrap, Routing/Dispatch, Mehrsprachigkeit |
| [CORE-CLASSES.md](CORE-CLASSES.md) | alle Kernklassen mit Aufgaben und Methoden |
| [SERVICES.md](SERVICES.md) | Service-Schicht, Container-Aliase, Konventionen |
| [DATABASE-SCHEMA.md](DATABASE-SCHEMA.md) | Datenbankschicht, Migrationen, alle Tabellen |
| [HOOKS-REFERENCE.md](HOOKS-REFERENCE.md) | sämtliche Actions und Filter mit Argumenten |
| [API-REFERENCE.md](API-REFERENCE.md) | JSON-Endpunkte, Parameter, Fehlercodes |
| [SECURITY.md](SECURITY.md) | Header, CSP, Sessions, Authentifizierung, CSRF, Datenhaltung |
| [STRUCTURE.md](STRUCTURE.md) | Verzeichnisse, Namespaces, Autoloading |
| [STATUS.md](STATUS.md) | Versionsstand, Änderungen seit 3.4.00, bekannte Lücken |

### Kernfakten

- `CMS\Version::CURRENT = '3.5.0'`, Schema `v23`, PHP ≥ 8.4.1.
- Einstieg `CMS/index.php` → `CMS\Bootstrap` → Modus `cli`/`api`/`admin`/`web` → `CMS\Router`.
- Routing nach Präfix: `/api` → `ApiRouter`, `/admin` → `AdminRouter`, `/member`/`/dashboard` → `MemberRouter`, Rest → `PublicRouter` + `ThemeRouter`.
- Erweiterung über `CMS\Hooks`, Plugins (`CMS/plugins/`) und Themes (`CMS/themes/`).
- Datenbank über `CMS\Database` (PDO, Prepared Statements), Präfix `cms_`.

### Lesepfade

- **Neu im Projekt:** ARCHITECTURE → STRUCTURE → CORE-CLASSES.
- **Plugin-Entwicklung:** HOOKS-REFERENCE → SERVICES → [../plugins/PLUGIN-DEVELOPMENT.md](../plugins/PLUGIN-DEVELOPMENT.md).
- **Betrieb/Sicherheit:** SECURITY → STATUS → [../admin/security/README.md](../admin/security/README.md).
