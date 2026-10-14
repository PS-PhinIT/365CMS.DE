# 365CMS – Projektdokumentation | Abschnitt: Core – API-Referenz

> **Stand:** 2026-10-03 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

The JSON API is registered by `CMS\Routing\ApiRouter` (`CMS/core/Routing/ApiRouter.php`) and – for the generic `pages` endpoint – handled by `CMS\Api`. Requests below `/api` run in runtime mode `api` (`Cache-Control: no-store`). Authentication is the regular CMS session cookie; admin endpoints additionally require role `admin` and a capability, state-changing admin endpoints a CSRF token. There is no public write API and no CORS configuration. Since 3.4.13 API clients can optionally authenticate with `Authorization: Bearer <JWT>` when `JWT_SECRET` (≥ 32 characters) is set; tokens are issued only to an existing, fully logged-in session (`POST /api/v1/auth/token`) and renewed via `POST /api/v1/auth/refresh`. CSRF-protected endpoints still require their CSRF token.

## Deutsch

### Übersicht

| Methode | Route | Zugriff | Antwort |
|---|---|---|---|
| GET | `/api/v1/status` | öffentlich | `{"status":"ok","version":"3.5.0"}` |
| GET | `/api/v1/pages?q=<suche>` | angemeldet | `{"data":[…]}` – Seitensuche (`PageManager::search()`, leere Suche → leere Liste) |
| GET | `/api/v1/pages/:slug` | angemeldet | `{"data":{…}}` oder `404 {"error":"Page not found"}` |
| POST | `/api/v1/analytics/web-vitals` | gleiche Herkunft | `204` |
| GET | `/api/v1/admin/posts` | Admin + `edit_all_posts` | `{"data":[…],"total":n,"page":p,"limit":l}` |
| GET | `/api/v1/admin/pages` | Admin + `manage_pages` | wie oben |
| GET | `/api/v1/admin/users` | Admin + `manage_users` | wie oben |
| GET | `/api/v1/admin/mail/logs` | Admin + `manage_settings` | Mail-Log-Seite |
| POST | `/api/v1/admin/mail/test` | Admin + CSRF `admin_mail_api` | Ergebnis des Testversands |
| POST | `/api/v1/admin/graph/test` | Admin + CSRF `admin_mail_api` | Ergebnis des Graph-Tests |
| POST | `/api/upload` | angemeldet + CSRF `media_action` | Upload-Ergebnis (`FileUploadService`) |
| GET/POST | `/api/media` | Editor-Token `editorjs_media` | Editor.js-Medien (Upload, Bibliothek, Remote-Bild) |
| POST | `/api/v1/auth/token` | angemeldete Session + gleiche Herkunft, nur mit `JWT_SECRET` | `{"token_type":"Bearer","access_token":…,"expires_in":3600,"refresh_token":…}` (10 je Stunde/IP) |
| POST | `/api/v1/auth/refresh` | Feld `refresh_token` (Form oder JSON), nur mit `JWT_SECRET` | `{"token_type":"Bearer","access_token":…,"expires_in":…}` (30 je Stunde/IP) |

### Allgemeines

- **Format:** `Content-Type: application/json`; Fehler immer als `{"error":"…"}`.
- **Statuscodes:** 200, 204, 400 (ungültige Eingabe), 401 (nicht angemeldet), 403 (Rechte/CSRF/Herkunft), 404, 413 (zu groß), 422 (ungültiges JSON), 429 (Rate-Limit, Header `Retry-After: 60`), 500 (generische Meldung, Details nur im Log).
- **Rate-Limit `CMS\Api`:** 60 Anfragen je 60 Sekunden je IP (Tabelle `cms_login_attempts`, Aktion `api`).
- **Web Vitals:** Body max. 8 KB, max. 40 Meldungen je Minute (Cache-basiert), nur Same-Origin.

### Parameter der Admin-Listen

| Endpunkt | Parameter |
|---|---|
| `/api/v1/admin/posts` | `page` (≥1), `limit` (5–100, Standard 20), `status` (`all`, `published`, `scheduled`, `draft`, `private`, `trash`), `search`, `sort` (`title`, `status`, `published_at`, `views`, `updated_at`, `created_at`), `order` |
| `/api/v1/admin/pages` | `page`, `limit`, `status` (`published`, `draft`, `private`), `search`, `sort` (`title`, `slug`, `status`, `updated_at`, `created_at`), `order` |
| `/api/v1/admin/users` | `page`, `limit`, `search`, `role` (Rolle, `all` oder `banned`), `sort` (`username`, `email`, `display_name`, `role`, `status`, `created_at`), `order` |
| `/api/v1/admin/mail/logs` | `page`, `limit` (10–200), `search`, `status` |

Beiträge enthalten zusätzlich `effective_status` (`scheduled`, wenn `published_at` in der Zukunft liegt).

### Beispiele

```bash
# Status (öffentlich)
curl -s https://example.com/api/v1/status

# Beitragsliste als angemeldeter Administrator (Session-Cookie aus dem Browser)
curl -s -b "PHPSESSID=<session>" "https://example.com/api/v1/admin/posts?status=scheduled&limit=50"
```

```js
// Upload aus einer Admin-Seite (Token im Template erzeugt: Security::generateToken('media_action'))
const body = new FormData();
body.append('file', fileInput.files[0]);
body.append('csrf_token', document.querySelector('[name="csrf_token"]').value);
const res = await fetch('/api/upload', { method: 'POST', body, credentials: 'same-origin' });
```

### Eigene Endpunkte

Plugins registrieren Routen im Hook `register_routes`. Für JSON-Endpunkte unter `/api/…` gilt: Modus `api` (no-store), eigene Authentifizierung/Capability-Prüfung, CSRF für schreibende Browser-Requests, Antwort mit `json_encode(…, JSON_UNESCAPED_UNICODE)`. Siehe [../admin/ADMIN-API-AJAX.md](../admin/ADMIN-API-AJAX.md) und [../workflow/API-INTEGRATION-WORKFLOW.md](../workflow/API-INTEGRATION-WORKFLOW.md).

### JWT-Anmeldung (seit 3.4.13)

- **Aktivierung:** nur wenn `JWT_SECRET` in `config/app.php` gesetzt ist (mindestens 32 Zeichen). Der `AUTH_KEY`-Fallback von `JwtService` schaltet die API-Anmeldung **nicht** frei. Ohne Secret antworten die Token-Routen mit 404.
- **Token holen:** `POST /api/v1/auth/token` aus einer angemeldeten Browser-Session (Login inkl. MFA) mit gleicher Herkunft. Ein reiner Passwort-Login per API ist bewusst nicht vorgesehen, weil er die Zwei-Faktor-Anmeldung umgehen würde.
- **Verwenden:** `Authorization: Bearer <access_token>` auf `/api/*`. `Router::dispatch()` ruft dafür `Auth::authenticateBearerToken()` auf; der Benutzer muss aktiv sein, Refresh-Tokens werden als Access-Token abgelehnt. Es entsteht keine Session.
- **Erneuern:** `POST /api/v1/auth/refresh` mit `refresh_token` (Laufzeit 30 Tage). Das Konto muss weiterhin aktiv sein.
- **Widerruf:** Tokens sind zustandslos. Sperren des Kontos (Status ≠ `active`) entwertet sie sofort. Seit 3.5.0 tragen Access- und Refresh-Token den Claim `pwf` (Fingerabdruck des aktuellen Passwort-Hashes): Eine Passwortänderung oder ein Passwort-Reset entwertet alle Tokens des Kontos; Tokens ohne `pwf` (ausgestellt vor 3.5.0) werden abgelehnt und müssen neu geholt werden. Der Client erkennt solche Tokens an der 401-Antwort mit dem Header `WWW-Authenticate: Bearer error="invalid_token", error_description="Token issued before 365CMS 3.5.0 …"`; `POST /api/v1/auth/refresh` meldet zusätzlich im JSON-Feld `error`, dass eine neue Anmeldung über `POST /api/v1/auth/token` nötig ist. Alle Tokens lassen sich zusätzlich durch Ändern von `JWT_SECRET` ungültig machen. Laufzeit über `JWT_TTL` (Standard 3600 s).
- Endpunkte mit CSRF-Pflicht (`/api/upload`, `/api/v1/admin/*/test`) verlangen auch bei Bearer-Anmeldung ihr CSRF-Token.

```bash
curl -H "Authorization: Bearer $TOKEN" https://example.com/api/v1/admin/posts?limit=10
curl -X POST -d "refresh_token=$REFRESH" https://example.com/api/v1/auth/refresh
```

### Verwandte Dokumente

[ARCHITECTURE.md](ARCHITECTURE.md) · [SECURITY.md](SECURITY.md) · [../admin/ADMIN-API-AJAX.md](../admin/ADMIN-API-AJAX.md)
