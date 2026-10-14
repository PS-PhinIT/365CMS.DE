# 365CMS – Projektdokumentation | Abschnitt: Administration – Runtime
> **Stand:** 2026-09-14 | **Version:** 3.5.0 | **Status:** Stable | **Update:** 2026-09-14

## English

The admin runtime is under `CMS/admin/`. Routes are dispatched through `CMS/core/Routing/AdminRouter.php`; feature modules live in `CMS/admin/modules/`, views in `CMS/admin/views/`, and shared layout fragments in `CMS/admin/partials/`. Direct PHP entry-file access is not a substitute for authentication or capability checks.

## Deutsch

Die Admin-Runtime liegt unter `CMS/admin/`. Routen werden durch `CMS/core/Routing/AdminRouter.php` aufgelöst; Fachmodule liegen unter `CMS/admin/modules/`, Views unter `CMS/admin/views/` und gemeinsame Layout-Fragmente unter `CMS/admin/partials/`. Der direkte Aufruf von PHP-Einstiegsdateien ersetzt keine Authentifizierungs- oder Capability-Prüfung.
