# 365CMS – Projektdokumentation | Abschnitt: Audit 2026-10-03 – Fehlende und unvollständige Funktionen

> **Stand:** 2026-10-03 | **Version:** 3.5.0 | Übersicht: [README.md](README.md)

## English (summary)

All twelve "known gaps" from `DOC/core/STATUS.md` were re-verified against the code; seven were fixed in this audit (maintenance mode, settings save overwriting manual constants, `/order` route, `/health` route, storage limit lookup, member upload token, bulk delete semantics) and the marketplace manifests were updated. In 3.4.13 the JWT API login (opt-in), the documentation sync UI and the WordPress asset functions were added and dead code was removed. Runtime tests additionally uncovered and fixed a contact form that always returned 404 and a fresh-install 500 on `/blog` caused by missing post language columns. Still open: the frozen runtime version (a release decision).

## Deutsch

### Behobene Befunde

#### FUN-01 🟠 Wartungsmodus ohne Wirkung

`maintenance_mode` und `maintenance_message` wurden unter `/admin/settings` gespeichert, aber nirgends ausgewertet.
**Fix:** `Router::dispatch()` prüft die Option nach den Redirects. Besucher erhalten **HTTP 503** mit `Retry-After: 3600`, `noindex` und der gespeicherten Nachricht (nur `<p><strong><em><br>`, Attribute entfernt; Inline-Style mit CSP-Nonce). API-Aufrufe erhalten JSON `{"success":false,"error":"maintenance"}`.
Ausgenommen: eingeloggte Admins, `/admin/*`, `/login`, `/logout`, `/cms-login`, `/cms-password-forgot`, `/forgot-password`, `/mfa-*`, `/health` sowie die konfigurierten (ggf. umbenannten) Login- und Passwort-Pfade aus `CmsAuthPageService`.

#### FUN-02 🟠 Einstellungen überschreiben `config/app.php`

`SettingsModule::updateConfigFile()` erzeugte die Datei bei jedem Speichern aus einer Vorlage neu. Manuell gesetzte `LDAP_*`, `JWT_*`, `SMTP_*`, `CMS_HTTPS_*`/`CMS_HSTS_*`, Zeitzone und eigene Konstanten gingen verloren.
**Fix:** Ist die Datei vorhanden, werden nur `SITE_NAME`, `SITE_URL`, `ADMIN_EMAIL` und `CMS_DEBUG` in place ersetzt (`patchConfigContent()`), das Ergebnis mit `validateGeneratedPhpFile()` geprüft und atomar geschrieben. Nur wenn eine Konstante nicht genau einmal gefunden wird, greift die Vorlage wie bisher. Zusätzlich escape-bewusstes Parsen (siehe [SECURITY.md](SECURITY.md) SEC-06).

#### FUN-03 🟠 `/order` liefert 404

`PublicRouter::renderOrder()`/`handleOrder()` erwarteten `CMS/member/order_public.php`, die nicht ausgeliefert wird. Der Checkout liegt in `CMS/orders.php`.
**Fix:** Fehlt die Datei, leitet `/order` auf `/orders.php` weiter (GET: 302, POST: 307 mit erhaltenem Body), Query-String bleibt erhalten. Eine eigene `order_public.php` hat weiterhin Vorrang.

#### FUN-04 🟡 Health-Endpunkt ohne Route

Unter Diagnose → Health-Check ist `monitor_health_endpoint_path` (Standard `/health`) einstellbar, der Core registrierte aber keine Route; die Prüfung schlug immer fehl.
**Fix:** `GET /health` (`PublicRouter::renderHealth()`) liefert bei aktivierter Option `{"status":"ok","database":"ok"}` (200) bzw. 503 bei DB-Fehler, sonst 404. Keine Versions- oder Systemdetails. Ein abweichend konfigurierter Pfad braucht weiterhin einen eigenen Endpunkt. Hinweis: Die feste Route hat Vorrang vor einer CMS-Seite mit dem Slug `health`.

#### FUN-05 🟠 Speicher-Limit und Free-Fallback im Abo-System

- `SubscriptionManager::checkLimit($userId, 'storage')` suchte `limit_storage`; die Spalte heißt `limit_storage_mb`. Ergebnis: Limit immer `0` → Upload verweigert.
- `getFreePlan()` nahm den ersten Datensatz ohne `ORDER BY` und ohne `is_active`.
- Vergleich `=== -1` schlug fehl, wenn PDO den Wert als String lieferte.

**Fix:** `storage` → `limit_storage_mb`, Limit als `(int)`; Free-Fallback: aktiver Plan mit Slug `free`, sonst günstigster aktiver Plan (`price_monthly`, `sort_order`, `id`).

#### FUN-06 🟠 Erster Member-Upload scheitert

`CMS/member/media.php` gab dem Upload-Formular einen Token der Aktion `member_media_action`; `/api/upload` (`FileUploadService`) prüft `media_action`. Der erste Upload je Seitenaufruf endete mit 403.
**Fix:** Das Upload-Formular erhält einen Token der Aktion `media_action`.

#### FUN-07 🟡 Sammelaktion `delete` löscht endgültig

`UserService::bulkAction()` behandelte `delete` wie `hard_delete`. **Fix:** `delete` = Soft-Delete (Status inaktiv), `hard_delete` = endgültig. Die Admin-Oberfläche bietet unverändert nur „Dauerhaft löschen“ (`hard_delete`).

### Mit 3.4.13 behoben

#### FUN-13 🟠 Kontaktformular nie absendbar (Laufzeittest)

`ThemeRouter` registrierte `/contact` und `/kontakt` nur für GET. Das Formular von `cms-default` sendet per POST an dieselbe URL – jedes Absenden endete mit **404**. **Fix:** POST-Routen für beide Pfade; das Template prüft sein eigenes Token `contact_form`, daher steht `/kontakt` jetzt wie `/contact` auf der Bypass-Liste der globalen `form_guard`-Prüfung.

#### FUN-14 🔴 Frische Installation: Blog und API mit 500 (Laufzeittest)

Die Spalten `title_en`, `content_en`, `excerpt_en` von `cms_posts` fehlten im Basisschema; nur `PostsModule::ensureColumns()` ergänzte sie – also erst, wenn ein Admin `/admin/posts` öffnet. Bis dahin brachen `/blog` und `/api/v1/admin/posts` mit `Unknown column 'p.title_en'` ab. **Fix:** Spalten im `CREATE TABLE` und als idempotente Migration in Schema **v23**.

#### FUN-08 🟡 JWT-Anmeldung für die API

`JwtService` ist jetzt verdrahtet – **opt-in** über `JWT_SECRET` (≥ 32 Zeichen):
- `Router::dispatch()` ruft für `/api/*` `Auth::authenticateBearerToken()` auf (`Authorization: Bearer <JWT>`, aktives Konto, kein Refresh-Token, keine Session).
- `POST /api/v1/auth/token` stellt Access- und Refresh-Token aus – nur für eine angemeldete Session mit gleicher Herkunft (Login inkl. MFA), 10 je Stunde/IP, Audit-Eintrag `api.token_issued`.
- `POST /api/v1/auth/refresh` tauscht ein Refresh-Token gegen ein neues Access-Token (30 je Stunde/IP).
Details: [../core/API-REFERENCE.md](../core/API-REFERENCE.md#jwt-anmeldung-seit-3413).

#### FUN-09 🟡 Doku-Sync unter `/admin/documentation`

Karte „Doku-Sync“ mit Status und Button (Aktion `sync_docs`, nur `manage_system`, Bestätigungsdialog). Git-Modus ohne weitere Konfiguration; ZIP-Modus nur mit `CMS_DOCS_SYNC_BUNDLE_SHA256` + `CMS_DOCS_SYNC_BUNDLE_FILES`. Details: [../admin/system-settings/SYSTEM.md](../admin/system-settings/SYSTEM.md#dokumentation-admindocumentation).

#### FUN-10 🟡 WordPress-Asset-Funktionen

`wp_register_style/script`, `wp_enqueue_style/script`, `wp_dequeue_*` und `wp_localize_script` bilden jetzt eine kleine Registry (`cms_wp_assets()` in `CMS/includes/functions/wordpress-compat.php`): Abhängigkeiten werden aufgelöst, `?ver=` angehängt, Styles/Header-Scripts im Hook `head`/`admin_head`, Footer-Scripts und spät eingereihte Assets in `body_end` ausgegeben – mit CSP-Nonce.

#### FUN-11 ⚪ Toter Code entfernt

`CMS/admin/modules/themes/DesignSettingsModule.php`, `CMS/admin/views/themes/settings.php`, `PageManager::listPages()` und `cms_default_theme_customizer_get_admin_menu_paths()` (verwies auf ein nicht existierendes `admin/partials/admin-menu.php`, REF-05).

### Offen

| ID | Schwere | Befund | Fundstelle | Empfehlung |
|---|---|---|---|---|
| FUN-12 | 🟡 | `Version::CURRENT` bleibt `3.4.00`, obwohl seit dem Release Code-Fixes (3.4.01–3.4.13, inkl. Schema v23) ausgeliefert werden. Update-Prüfung, Marketplace-`requires_cms` und Support können Installationen nicht unterscheiden. | `CMS/core/Version.php`, `CMS/update.json`, `CMS/marketplace/core/365cms/update.json` | Release-Entscheidung des Maintainers: `Version::CURRENT`, `RELEASE_DATE`, beide `update.json` (Download-URL, SHA-256) und die Release-Pakete unter `RELEASE/` gemeinsam anheben. Nicht im Audit geändert, weil `update.json` auf ein konkretes ZIP mit Prüfsumme zeigt. **Erledigt in 3.5.0:** `Version::CURRENT = '3.5.0'`, beide `update.json` und `RELEASE/365CMS-3.5.0-*` gemeinsam angehoben. |

### Status der Tabelle „Bekannte Lücken“ (`DOC/core/STATUS.md`)

| # | Befund (2026-10-02) | Status 2026-10-03 |
|---|---|---|
| 1 | Settings überschreiben `config/app.php` | ✅ FUN-02 |
| 2 | Wartungsmodus ohne Wirkung | ✅ FUN-01 |
| 3 | `/order` 404 | ✅ FUN-03 |
| 4 | PHP-Check 8.2 statt 8.4 | ✅ SEC-05 |
| 5 | `checkLimit('storage')`, Free-Fallback | ✅ FUN-05 |
| 6 | `DesignSettingsModule` toter Code | ✅ FUN-11 (3.4.13) |
| 7 | Doku-Sync ohne UI | ✅ FUN-09 (3.4.13) |
| 8 | Sammelaktion `delete` = `hard_delete` | ✅ FUN-07 |
| 9 | `/health` ohne Route | ✅ FUN-04 |
| 10 | JWT nicht verdrahtet | ✅ FUN-08 (3.4.13) |
| 11 | Veraltete Marketplace-Manifeste | ✅ REF-02 |
| 12 | Member-Upload-Token | ✅ FUN-06 |

### Verwandte Dokumente

[README.md](README.md) · [../core/STATUS.md](../core/STATUS.md) · [../admin/subscription/ORDERS.md](../admin/subscription/ORDERS.md)
