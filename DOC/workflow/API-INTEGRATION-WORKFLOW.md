# 365CMS – Projektdokumentation | Abschnitt: Workflow – API-Integration
> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable | **Update:** 2026-10-02
> **Quellen:** `CMS/core/Routing/ApiRouter.php`, `CMS/core/Api.php`, `CMS/core/Router.php`, `CMS/core/Bootstrap.php`, `CMS/core/Services/FileUploadService.php`, `CMS/core/Services/JwtService.php`

## English (summary)

This workflow describes how to consume the shipped JSON endpoints and how a plugin adds its own API route. Requests below `/api` run in bootstrap mode `api` (`Cache-Control: no-store`). Authentication is the CMS session cookie; there is no public write API, no CORS layer and no Bearer/JWT authentication on core routes. Plugins register routes in the `register_routes` hook (fired in `Bootstrap::run()` before dispatch) with `$router->addRoute($method, $path, $callback)`; `:param` placeholders match `[a-zA-Z0-9_-]+` and are passed to the callback in order. Every state-changing endpoint must check login/capability, a CSRF token (`Security::verifyToken()`), validate input and return JSON errors as `{"error":"…"}`.

## Deutsch

### 1. Vorhandene Endpunkte nutzen

| Methode | Route | Zugriff |
|---|---|---|
| GET | `/api/v1/status` | öffentlich – `{"status":"ok","version":"3.5.0"}` |
| GET | `/api/v1/pages?q=…`, `/api/v1/pages/:slug` | angemeldet |
| POST | `/api/v1/analytics/web-vitals` | gleiche Herkunft, max. 8 KB |
| GET | `/api/v1/admin/posts`, `/pages`, `/users`, `/mail/logs` | Admin + Capability |
| POST | `/api/v1/admin/mail/test`, `/api/v1/admin/graph/test` | Admin + CSRF `admin_mail_api` |
| POST | `/api/upload` | angemeldet + CSRF `media_action` |
| GET/POST | `/api/media` | Editor.js-Token `editorjs_media` |

Vollständige Parameterlisten: [core/API-REFERENCE.md](../core/API-REFERENCE.md).

**Ablauf für einen Browser-Client (gleiche Domain):**

1. Benutzer meldet sich regulär an (`/cms-login` bzw. `/login`); der Session-Cookie wird automatisch mitgeschickt (`credentials: 'same-origin'`).
2. Für schreibende Aufrufe das passende Token im serverseitigen Template erzeugen (`Security::instance()->generateToken('<action>')`) und als Feld `csrf_token` oder Header `X-CSRF-Token` mitsenden.
3. Antwort auswerten: Fehler sind immer `{"error":"…"}` mit HTTP-Status (400/401/403/404/413/422/429/500). Bei `429` den Header `Retry-After` beachten (`CMS\Api`: 60 Anfragen/Minute je IP).
4. Uploads liefern ein frisches Token in `new_token` – dieses für den nächsten Request verwenden.

```js
const fd = new FormData();
fd.append('file', input.files[0]);
fd.append('csrf_token', token);
const res = await fetch('/api/upload', { method: 'POST', body: fd, credentials: 'same-origin' });
const json = await res.json();
if (!res.ok) throw new Error(json.error);
token = json.new_token;            // Token rotieren
console.log(json.url, json.path);  // Auslieferungs-URL, relativer Pfad
```

**Server-zu-Server:** Die Core-Routen haben keine Token-Authentifizierung. Für Integrationen ohne Browser-Session (z. B. CI, Monitoring) nur `/api/v1/status` verwenden oder einen eigenen Endpunkt per Plugin bereitstellen (siehe 2.).

### 2. Eigenen Endpunkt per Plugin registrieren

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

\CMS\Hooks::addAction('register_routes', static function (\CMS\Router $router): void {
    $router->addRoute('GET', '/api/v1/events', 'my_events_api_list');
    $router->addRoute('POST', '/api/v1/events/:id/rsvp', 'my_events_api_rsvp');
});

function my_events_api_list(): void
{
    header('Content-Type: application/json; charset=utf-8');
    $rows = \CMS\Database::instance()->get_results(
        'SELECT id, title FROM ' . \CMS\Database::instance()->getPrefix() . 'my_events WHERE status = ?',
        ['published']
    );
    echo json_encode(['data' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

function my_events_api_rsvp(string $id): void
{
    header('Content-Type: application/json; charset=utf-8');
    $auth = \CMS\Auth::instance();
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Nicht angemeldet']);
        exit;
    }
    $token = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!\CMS\Security::instance()->verifyToken($token, 'my_events_rsvp')) {
        http_response_code(403);
        echo json_encode(['error' => 'Sicherheitsprüfung fehlgeschlagen']);
        exit;
    }
    // … $id validieren (ctype_digit), speichern, Audit …
    echo json_encode(['success' => true]);
    exit;
}
```

**Regeln:**

- Routen werden exakt oder per Muster (`:name` → `[a-zA-Z0-9_-]+`) gematcht; eine statische Route hat Vorrang vor Mustern. Callbacks müssen `callable` sein (Funktionsname, Closure oder `[Objekt, 'methode']`).
- Pfade unter `/api/` laufen im Modus `api`: kein Theme, keine Session-Weiterleitungen, `no-store`. Pfade außerhalb von `/api` laufen im Modus `web` – dann greift für POST die globale `form_guard`-Prüfung des Routers.
- Admin-Endpunkte: `Auth::instance()->isAdmin()` **und** `Auth::instance()->hasCapability('<cap>')` prüfen.
- Keine Fehlerdetails ausgeben; Ausnahmen über `Logger::instance()->withChannel('<plugin>')` protokollieren.
- Rate-Limiting bei Bedarf über `Security::checkRateLimit($id, $max, $window)` (Session-basiert) oder `Security::checkDbRateLimit()` / `recordDbRateLimitAttempt()` (Tabelle `cms_login_attempts`).

### 3. JWT (optional)

`CMS\Services\JwtService` stellt `generateToken($userId, $claims, $ttl)`, `generateRefreshToken($userId)` und `validateToken($token)` bereit (HMAC mit `JWT_SECRET`, Fallback `AUTH_KEY`). Keine Core-Route wertet Bearer-Tokens aus – ein Plugin muss den Header `Authorization: Bearer …` selbst lesen und `validateToken()` aufrufen. `JWT_SECRET` dann unbedingt in `config/app.php` setzen.

### 4. Checkliste vor dem Go-live

- [ ] Methode und Pfad gegen bestehende Routen geprüft (keine Kollision mit `/api/v1/*` des Core).
- [ ] Auth, Capability und CSRF für alle schreibenden Aufrufe.
- [ ] Eingaben typisiert/validiert, Ausgaben als JSON mit `JSON_UNESCAPED_UNICODE`.
- [ ] Fehler → HTTP-Status + `{"error":"…"}`; keine Stacktraces.
- [ ] Logging/Audit für sicherheitsrelevante Aktionen.
- [ ] CSP: Frontend-JavaScript als Datei mit Nonce bzw. über Theme/Plugin-Assets, kein Inline-Script.

## Verwandte Dokumente

- [core/API-REFERENCE.md](../core/API-REFERENCE.md) · [core/SECURITY.md](../core/SECURITY.md) · [core/HOOKS-REFERENCE.md](../core/HOOKS-REFERENCE.md)
- [admin/ADMIN-API-AJAX.md](../admin/ADMIN-API-AJAX.md) · [plugins/PLUGIN-DEVELOPMENT.md](../plugins/PLUGIN-DEVELOPMENT.md)
