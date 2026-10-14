# 365CMS – Projektdokumentation | Abschnitt: Admin – API, AJAX und JSON-Endpunkte

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

Admin screens are classic server-rendered forms (Post/Redirect/Get). Asynchronous calls use a small set of JSON endpoints: the REST-style routes of `CMS/core/Routing/ApiRouter.php` (`/api/v1/…`, `/api/upload`, `/api/media`), the protected AI endpoints under `/admin/ai-*`, plugin AJAX calls to `/admin/plugins/<plugin>/<page>` and a few module-specific AJAX actions (media processing jobs, dashboard preferences). All of them rely on the session login, server-side capability checks and CSRF tokens; JavaScript is never an authorization boundary. A Bearer/JWT login for the API is prepared (`JwtService`) but not wired into the routes in 3.5.0.

## Deutsch

### Endpunkte des `ApiRouter`

| Methode & Pfad | Zugriff | Zweck |
|---|---|---|
| `GET /api/v1/status` | öffentlich | `{"status":"ok","version":"3.5.0"}` |
| `GET /api/v1/pages` | angemeldet | Seitenliste/Suche (`CMS\Api`, Rate-Limit 60 Anfragen/60 s je IP) |
| `GET /api/v1/pages/:slug` | angemeldet | einzelne Seite |
| `POST /api/v1/analytics/web-vitals` | gleiche Herkunft | Core-Web-Vitals-Messung (Größen- und Rate-Limit, Antwort 204) |
| `GET /api/v1/admin/posts` | Admin + `edit_all_posts` | Beitragsliste: `page`, `limit` (5–100), `status` (`all`, `published`, `scheduled`, `draft`, `private`, `trash`), `sort` |
| `GET /api/v1/admin/pages` | Admin + `manage_pages` | Seitenliste: `page`, `limit`, `status`, `sort` |
| `GET /api/v1/admin/users` | Admin + `manage_users` | Benutzerliste: `page`, `limit`, `search`, `role`, `sort` |
| `GET /api/v1/admin/mail/logs` | Admin + `manage_settings` | Mail-Log: `page`, `limit` (10–200), `search`, `status` |
| `POST /api/v1/admin/mail/test` | Admin + CSRF `admin_mail_api` | Testmail |
| `POST /api/v1/admin/graph/test` | Admin + CSRF `admin_mail_api` | Microsoft-Graph-Verbindungstest |
| `POST /api/upload` | angemeldet + CSRF `media_action` (Feld `csrf_token` oder Header `X-CSRF-Token`) | allgemeiner Datei-Upload (`FileUploadService::handleUploadRequest()`) |
| `GET\|POST /api/media` | Editor-Token `editorjs_media` | Editor.js-Bild-/Datei-Upload und Medienbibliothek (`EditorJsService::handleMediaApiRequest()`) |

Fehlerantworten sind JSON (`{"error":"…"}`) mit passendem HTTP-Status (401, 403, 404, 413, 422, 429, 500).

### Geschützte Admin-JSON-Endpunkte

| Pfad | CSRF-Aktion | Zweck |
|---|---|---|
| `POST /admin/ai-translate-editorjs` | `admin_ai_editorjs_translation` | KI-Übersetzung von Editor.js-Inhalten (blockweise) |
| `POST /admin/ai-generate-seo-metadata` | `admin_ai_seo_metadata` | KI-SEO-Metadaten |
| `POST /admin/error-report` | `admin_error_report` | Fehlerbericht speichern |
| `POST /admin/monitor-cron-runner` | `admin_system_cron_runner` | Cron direkt/Loopback aus dem Admin |

Diese Endpunkte senden `Cache-Control: private`, `X-Robots-Tag: noindex` und verlangen `POST`.

### AJAX innerhalb von Admin-Seiten

- **Plugin-Seiten:** Requests mit `X-Requested-With: XMLHttpRequest` an `/admin/plugins/<plugin>/<seite>` rufen den Plugin-Callback ohne Layout auf; Exceptions ergeben `500` mit `{"success":false,"error":"…"}`.
- **Medien:** Verarbeitungsjobs (`start_/process_/cancel_media_processing_job`) werden per Fetch an `/admin/media` gesendet.
- **Editor:** Medienupload über `/api/media`, Übersetzung/SEO über die KI-Endpunkte; Token werden als Template-Variablen (`editorMediaToken`, `aiTranslationToken`, `aiSeoMetadataToken`) in die Seite gegeben.

### Regeln für eigene Endpunkte

1. Zugriff serverseitig prüfen (`Auth::isAdmin()`, `hasCapability()`), nie nur im JavaScript.
2. Zustandsändernde Requests nur per `POST` und mit CSRF-Token (`Security::verifyToken($token, '<aktion>')`).
3. Eingaben normalisieren (Allowlists, Längen, Typen); keine Rohwerte in SQL – nur Prepared Statements.
4. Antworten als JSON mit `Content-Type: application/json; charset=utf-8`, Fehler mit sinnvollem Status.
5. Keine Secrets, Tokens oder personenbezogenen Daten in Antworten oder Logs.
6. JavaScript als externe Datei unter `CMS/assets/js/` (CSP); Daten über `data-*`-Attribute oder JSON-Skriptblöcke mit Nonce übergeben.

### Verwandte Dokumente

[PANEL-INTEGRATION.md](PANEL-INTEGRATION.md) · [../core/API-REFERENCE.md](../core/API-REFERENCE.md) · [../workflow/API-INTEGRATION-WORKFLOW.md](../workflow/API-INTEGRATION-WORKFLOW.md) · [../core/SECURITY.md](../core/SECURITY.md)
