# 365CMS – Projektdokumentation | Abschnitt: Core – Sicherheitsmodell

> **Stand:** 2026-10-14 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

Security is layered: transport (HTTPS redirect strategy, HSTS), response headers and a nonce-based CSP with Trusted Types (`CMS\Security`), hardened sessions (HttpOnly, Secure, SameSite=Strict, strict mode, ID regeneration, role-based lifetimes, device binding), authentication with rate limiting, MFA (TOTP, backup codes), passkeys and LDAP (`CMS\Auth`, `CMS\Auth\AuthManager`), per-action one-time CSRF tokens (1 h TTL, 20 per action), a global CSRF guard for public POST requests, proxy/CDN-aware client IP detection (`TrustedProxyService`, default `cloudflare`; unknown CDN networks such as Hostinger's are detected and can be approved with one click, highlighted when the admin's own request came through them), an application firewall (`SecurityRuntimeService`), anti-spam, prepared statements, HTML sanitising (HTMLPurifier, Editor.js sanitizer), upload validation, encrypted secrets (`SettingsService`, AES-256-CBC) and audit logging.

## Deutsch

### 1. Transport

| Konstante (`config/app.php`) | Standard | Wirkung |
|---|---|---|
| `CMS_HTTPS_REDIRECT_STRATEGY` | `upstream` | wer HTTP→HTTPS umleitet: vorgelagerter Proxy/Webserver (`upstream`), Apache-`.htaccess` oder Core-PHP; `disabled` möglich |
| `CMS_HSTS_MODE` | `https-only` | HSTS nur bei HTTPS-Anfragen |
| `CMS_HSTS_MAX_AGE` | 31536000 | Gültigkeit in Sekunden |
| `CMS_TRUSTED_PROXIES` | nicht gesetzt (= `cloudflare`) | Komma-Liste vertrauenswürdiger Proxys/CDNs: IPs, CIDR (IPv4 /8–/32, IPv6 /16–/128) und Schlüsselwörter `cloudflare`, `private` (lokaler Reverse Proxy, der `X-Forwarded-For` selbst setzt), `auto` (`private` + `cloudflare`), `*`, `off`. Eine gesetzte Konstante hat Vorrang vor der Admin-Einstellung (*Sicherheit → Firewall → Proxy / CDN & Client-IP*, Option `security_trusted_proxies`); leerer Wert = `off`. Details: Abschnitt 7a. Betrifft Rate-Limits, Firewall, Audit-Log |

HTTPS wird auch hinter Proxys erkannt (`X-Forwarded-Proto`, `X-Forwarded-SSL`, `Front-End-Https`, Port 443).

### 2. Sicherheitsheader (`Security::setSecurityHeaders()`)

`X-Content-Type-Options: nosniff`, `X-Frame-Options` (Standard `DENY`), `X-XSS-Protection: 0`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: geolocation=(), microphone=(), camera=()`, `Cross-Origin-Opener-Policy: same-origin`, `Cross-Origin-Resource-Policy: same-site`, `Strict-Transport-Security` (bei HTTPS). Admin und API zusätzlich `Cache-Control: no-store`, `Pragma: no-cache`, `X-Robots-Tag: noindex, nofollow`.

### 3. Content-Security-Policy

Pro Request wird ein Nonce erzeugt (`Security::getNonce()`, im Template `Security::nonceAttr()`).

```text
default-src 'self';
script-src 'self' 'nonce-…' [+ freigegebene Quellen];
style-src 'self' 'nonce-…' https://fonts.googleapis.com;
img-src 'self' data: blob: […]; font-src 'self' data: https://fonts.gstatic.com;
connect-src 'self' […]; media-src 'self' data: blob:;
object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; manifest-src 'self';
upgrade-insecure-requests;
trusted-types cms365 default sanitize-html dompurify; require-trusted-types-for 'script'
```

- Mit `CMS_DEBUG = true` wird die Richtlinie nur als `Content-Security-Policy-Report-Only` gesendet.
- Zusätzliche HTTPS-Hosts für `script-src`, `connect-src`, `img-src` (z. B. Matomo) gibt Code über `Security::allowCspSources()` frei – typischerweise im Hook `cms_csp_prepare`.
- **Inline-Styles** in vollständigen HTML-Antworten schreibt `Http\InlineStyleRewriter` in nonce-geschützte Klassen um (`style-src-attr`).
- Konsequenz für Entwickler: keine Inline-Skripte, keine `on*=`-Attribute, kein `eval`; JavaScript als Datei, DOM-Zuweisungen über Trusted-Types-Policies.

### 4. Sessions

- `session.cookie_httponly = 1`, `cookie_secure` bei HTTPS, `cookie_samesite = Strict`, `use_only_cookies`, `use_strict_mode`; neue Session-ID beim ersten Start und beim Login.
- Laufzeiten je Rolle: Admin 8 h (`perf_session_timeout_admin`), Mitglied 30 Tage (`perf_session_timeout_member`); abgelaufene Sessions werden beim Laden verworfen.
- Session-Bindung an ein HMAC-signiertes Geräte-Cookie (`cms_device`, 2 h, enthält Benutzer-ID, Zeitstempel und Hash der Session-ID) erschwert Session-Diebstahl.
- Cookie-Domain wird bei Alias-/Hub-Domains bewusst host-only gesetzt (`CMS/index.php`).
- Seit 3.5.0 startet die Session für anonyme GET-/HEAD-Seiten erst, wenn sie gebraucht wird (Formular-Token, Login, Warenkorb): kein `Set-Cookie`, Antwort bleibt `public` und damit CDN-cachebar. Wird `$_SESSION` im Request beschrieben, startet `index.php` die Session am Ende des Requests und schaltet die Antwort auf `private`. Sofort gestartet wird bei vorhandenem Session- oder `cms_device`-Cookie, allen Nicht-GET-Anfragen, CLI sowie unter `/admin`, `/member`, `/api`, `/install`, Login/Registrierung/Passwort-Reset/MFA, `/order`, `/checkout`. Abschalten: `define('CMS_LAZY_SESSIONS', false);`.

### 5. Authentifizierung

| Baustein | Umsetzung |
|---|---|
| Passwort-Hash | `password_hash(…, PASSWORD_BCRYPT, cost 12)` |
| Passwortrichtlinie | ≥ 12 Zeichen, Groß-/Kleinbuchstabe, Ziffer, Sonderzeichen (`Auth::getPasswordPolicyDefinition()`) |
| Login-Sperre | `MAX_LOGIN_ATTEMPTS` (5) in `LOGIN_TIMEOUT` (300 s) je IP über `Security::checkDbRateLimit()` |
| Generische Fehlermeldung | „Ungültige Anmeldedaten.“ – kein Hinweis, ob Benutzer existiert |
| Kontostatus | nur `active` darf sich anmelden |
| MFA | TOTP (`Totp`, `TotpAdapter`) + Backup-Codes; Login liefert `MFA_REQUIRED` → `/mfa-challenge` |
| Passkeys | WebAuthn (`WebAuthnAdapter`, `cms_passkey_credentials`) |
| LDAP | `LdapAuthProvider` (LdapRecord) |
| Passwort-Reset | Token in `cms_password_resets`, einmalig, Ablauf 60 min, Rate-Limits, konstante Antwortzeit |
| Abmeldung | nur mit CSRF-Token `logout` |
| Audit | `loginSuccess`, `loginFailed`, Rollenwechsel, Sperren |
| API-Bearer (seit 3.5.0) | `Authorization: Bearer <JWT>` nur mit `JWT_SECRET` (≥ 32 Zeichen); Ausgabe nur an angemeldete Sessions (kein MFA-Bypass), Refresh-Tokens nicht als Access-Token nutzbar, Konto muss aktiv sein – siehe [API-REFERENCE.md](API-REFERENCE.md#jwt-anmeldung-seit-3413) |

Rollen- und Rechtemodell: [../admin/users-groups/RBAC.md](../admin/users-groups/RBAC.md).

### 6. CSRF

- `Security::generateToken($aktion)` erzeugt 64 Hex-Zeichen; je Aktion werden bis zu **20** Tokens für **1 Stunde** in der Session gehalten (mehrere Tabs möglich).
- `verifyToken()` prüft zeitkonstant (`hash_equals`) und **entwertet** das Token (Einmalverwendung).
- `verifyPersistentToken()` prüft ohne Entwertung (Editor-Uploads `editorjs_media`, KI-Endpunkte, Favoriten).
- Jede Admin-Seite hat eine eigene Aktion (z. B. `admin_pages`); öffentliche Formulare nutzen `form_guard` (globale Prüfung im Router), Kommentare `comment_<postId>`, Checkout `checkout_process`.
- Plugin-Bereiche im Member-Dashboard (seit 3.5.0): Jeder POST muss eine passende Herkunft haben (`Origin`/`Referer`, falls gesendet). Mit `'csrf' => 'core'` bei der Registrierung erzwingt der Core zusätzlich das Token `member_plugin_<slug>` (`PluginDashboardRegistry::csrfField()`).

### 7. Anfrageschutz

- **Firewall** mit IP-/CIDR-/User-Agent-/Länderregeln, Simulationsmodus und Rate-Limit ([../admin/security/FIREWALL.md](../admin/security/FIREWALL.md)).
- **API-Rate-Limit** 60 Anfragen/60 s je IP (`CMS\Api`), Web-Vitals mit eigenem Limit und Same-Origin-Prüfung.
- **Registrierung**: seit 3.5.0 höchstens 5 Versuche je IP in 15 Minuten (`Security::checkDbRateLimit(…, 'register', 5, 900)`).
- **Kontaktformular** (`cms-default`): seit 3.5.0 höchstens 5 Nachrichten je IP und Stunde (Aktion `contact_form`).
- **Zeitbasis der DB-Rate-Limits:** Einträge in `cms_login_attempts` entstehen mit `NOW()`, geprüft wird gegen PHP-Zeit. Seit 3.5.0 gleicht `CMS\Database` die MySQL-Sitzungszeitzone an PHP an; vorher zählten die Limits auf Servern mit UTC-Datenbank und `Europe/Berlin`-PHP nie.
- **API-Token**: `POST /api/v1/auth/token` 10/h, `POST /api/v1/auth/refresh` 30/h je IP.
- **Anti-Spam** für Formulare ([../admin/security/ANTISPAM.md](../admin/security/ANTISPAM.md)).
- **Sichere Weiterleitungen**: `Router::redirect()` und Login-Redirects lassen nur interne/erlaubte Ziele zu.
- **Ausgehende Requests** (`Http\Client`): Host-Allowlists für Updates/Marketplace/KI, HTTPS-Pflicht für Cloud-Ziele, keine privaten Netze außer explizit freigegebenen (Ollama).

### 7a. Client-IP hinter Proxy oder CDN (`TrustedProxyService`, seit 3.5.0)

- `Security::getClientIp()` liefert die IP aus `TrustedProxyService::clientIp()`. Weiterleitungs-Header werden **nur** ausgewertet, wenn `REMOTE_ADDR` vertrauenswürdig ist.
- Reihenfolge: `CF-Connecting-IP` (nur bei Cloudflare-Adressen) → `X-Forwarded-For` von rechts, vertrauenswürdige Hops werden übersprungen → `Forwarded` (RFC 7239) → `X-Real-IP` → `True-Client-IP`. Ports werden entfernt.
- `*` vertraut jedem direkten Gegenüber und nimmt den ersten nicht vertrauenswürdigen Hop von rechts. Nur verwenden, wenn der Server ausschließlich über das CDN erreichbar ist – sonst kann jeder Besucher seine IP fälschen.
- **Standard `cloudflare`:** Ohne Einstellung werden nur die offiziellen Cloudflare-Netze vertraut, weil Cloudflare die Header selbst setzt. Private Netze (`private`) sind bewusst Opt-in: Ein lokaler nginx, der nur `X-Real-IP` setzt und ein vom Client mitgeschicktes `X-Forwarded-For` durchreicht, würde sonst gefälschte IPs übernehmen.
- **Unbekannte CDN-Adressen (z. B. Hostinger-CDN):** Kommt eine Anfrage mit Weiterleitungs-Headern von einer nicht vertrauenswürdigen Adresse (öffentlich oder privat), merkt sich das CMS deren Netz (IPv4 /24, IPv6 /48) samt Anzahl Beobachtungen und anonymisierter Client-Stichprobe in `cache/trusted_proxy_candidates.json` (max. 30 Netze, 14 Tage, Schreibzugriff höchstens einmal pro Minute). Die Firewall-Seite zeigt die Kandidaten; **Vertrauen** übernimmt das Netz mit einem Klick. Verlässliches Signal ist nur die eigene Admin-Anfrage: Kam sie über ein solches Netz, ist es als **Ihre Anfrage** markiert und das Sicherheits-Audit warnt. Beobachtungen und Client-Anzahl stammen aus Request-Headern und sind fälschbar; sie lösen keine Empfehlung aus. Eine automatische Übernahme gibt es bewusst nicht, weil gefälschte Header sonst Rate-Limits aushebeln würden.
- Ersetzt der Webserver `REMOTE_ADDR` bereits selbst (z. B. LiteSpeed mit „Use Client IP in Header“), entstehen keine Kandidaten; die Diagnose auf der Firewall-Seite zeigt `REMOTE_ADDR`, Header und die ermittelte IP der aktuellen Anfrage.
- Länder-Header (`CF-IPCountry`, `X-Country-Code`, …) für die Firewall-Regel `block_country` gelten nur bei Anfragen über einen vertrauenswürdigen Proxy (`TrustedProxyService::countryCode()`).

### 8. Daten

- Datenbank nur über PDO mit nativen Prepared Statements (`ATTR_EMULATE_PREPARES = false`).
- Rich-Text über `PurifierService` (HTMLPurifier), Editor.js über `EditorJsSanitizer`/`EditorJsHtmlSanitizer`, Frontend zusätzlich DOMPurify.
- Ausgabe-Escaping über `htmlspecialchars` bzw. Helfer in `includes/functions/escaping.php`.
- Uploads: Endungs-Allowlist, gefährliche Endungen gesperrt (seit 3.5.0 auch als innere Doppelendung, z. B. `datei.php.jpg`), MIME- und Bildinhaltsprüfung, Dateinamen-Bereinigung, `.htaccess` im Upload-Ordner ([../admin/media/MEDIA.md](../admin/media/MEDIA.md)).
- Geheimnisse in der Datenbank: `SettingsService` verschlüsselt mit AES-256-CBC (Präfix `enc:`). Der Schlüssel wird aus `AUTH_KEY` und `SECURE_AUTH_KEY` abgeleitet – werden diese Konstanten geändert, sind gespeicherte Secrets (SMTP, Azure, Graph, KI-Provider) nicht mehr lesbar und müssen neu eingegeben werden.
- ZIP-Verarbeitung (Updates, Marketplace, Backups): Grenzen für Einträge/Größe, Pfadprüfung, SHA-256-Pflicht.

### 9. Protokollierung

`AuditLogger` (Tabelle `cms_audit_log`), `Logger` (Dateien je Kanal), `cms_security_log` (Firewall/Anti-Spam), `SecurityAlertService` (E-Mail-Alarme). Diagnoseausgaben werden bereinigt (`Bearer`-Tokens, Passwörter → `***`).

### 10. Prüfen

Sicherheits-Audit unter `/admin/security-audit` ([../admin/security/SECURITY-AUDIT.md](../admin/security/SECURITY-AUDIT.md)), Checkliste [../admin/PRUEF-CHECKLISTE.md](../admin/PRUEF-CHECKLISTE.md).

### Verwandte Dokumente

[ARCHITECTURE.md](ARCHITECTURE.md) · [../admin/security/README.md](../admin/security/README.md) · [../member/MEMBER-SECURITY.md](../member/MEMBER-SECURITY.md)
