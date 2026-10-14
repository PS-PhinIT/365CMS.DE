# 365CMS – Projektdokumentation | Abschnitt: Admin – CMS-Loginseite

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/cms-loginpage` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_cms_loginpage`

## English (summary)

The CMS ships its own, theme-independent authentication pages (login, registration, password reset). `/admin/cms-loginpage` (`CMS/admin/cms-loginpage.php` → `CMS/admin/modules/themes/CmsLoginPageModule.php` → `CMS/admin/views/themes/cms-loginpage.php`) configures their branding, texts and behaviour. Everything is stored with the prefix `cms_loginpage_` in `cms_settings` and rendered by `CMS\Services\CmsAuthPageService`.

Public paths: `/cms-login`, `/cms-register`, `/cms-password-forgot` (mode `cms`, default; legacy paths redirect there) or `/login`, `/register`, `/forgot-password` rendered by the active theme's templates (mode `legacy`).

## Deutsch

### Öffentliche Auth-Seiten

| Seite | Modus `cms` (Standard) | Modus `legacy` |
|---|---|---|
| Anmeldung | `/cms-login` | `/login` |
| Registrierung | `/cms-register` | `/register` |
| Passwort vergessen | `/cms-password-forgot` | `/forgot-password` |

Verhalten der Modi:

- **`cms`:** `GET /login`, `/register`, `/forgot-password` leiten auf die `/cms-*`-Pfade um; ausgeliefert wird die CMS-eigene Auth-Seite (`CmsAuthPageService::render()`), unabhängig vom Frontend-Theme.
- **`legacy`:** `GET /login` usw. rendern die Templates des aktiven Themes (`login.php`, `register.php`, `forgot-password.php` in `CMS/themes/<theme>/`). Die `/cms-*`-Pfade bleiben zusätzlich erreichbar.

POST-Anfragen werden unter beiden Pfaden verarbeitet. Für englische Aufrufe werden die Pfade über `ContentLocalizationService` lokalisiert (z. B. `/en/cms-login`). Weitere Endpunkte: `/logout` (nur mit CSRF-Token `logout`), `/mfa-challenge`, `/mfa-setup`, `/mfa-disable`.

### Einstellungen

| Gruppe | Felder (Standardwerte) |
|---|---|
| Marke & Layout | `brand_name` (Site-Name), `logo_url`, `card_width` (380–960, Standard 520 px), `layout_variant` (`centered` oder `split`), `auth_slug_mode` (`cms`/`legacy`), `footer_note` |
| Farben | `background_start` `#0f172a`, `background_end` `#1d4ed8` (Verlauf), `card_background`, `text_color`, `muted_color`, `primary_color` `#2563eb`, `primary_text_color`, `link_color`, `input_background`, `input_border` |
| Überschriften | `headline_login` „Willkommen zurück“, `headline_register` „Neues Konto erstellen“, `headline_forgot` „Passwort zurücksetzen“ und die zugehörigen `subheadline_*` |
| Login-Formular | Beschriftungen und Platzhalter, „Angemeldet bleiben“ (`login_show_remember`), **Passkey-Button** (`login_show_passkey`, Text `login_passkey_button_text`) |
| Registrierung | Beschriftungen, Pflicht-Checkbox für Bedingungen (`register_require_terms`, Text `register_terms_label`), Meldung bei deaktivierter Registrierung |
| Passwort vergessen | Beschriftungen, Erfolgsmeldungen, Gültigkeit des Reset-Links (`password_reset_expiry_minutes`, Standard 60), Betreff und Text der Reset-E-Mail mit Platzhaltern `{site_name}`, `{reset_url}`, `{expires_minutes}`, `{brand_name}` |
| Rechtliche Seiten | `privacy_page_id`, `terms_page_id`, `imprint_page_id` – werden im Footer der Auth-Seiten verlinkt |
| Registrierung erlaubt | gemeinsam genutzt mit `registration_enabled` / `member_registration_enabled` ([../users-groups/AUTH-SETTINGS.md](../users-groups/AUTH-SETTINGS.md)) |

Farben werden als Hex validiert, URLs geprüft, Texte gekürzt; ungültige Werte fallen auf den Standard zurück.

### Sicherheitsverhalten

- **Passwort vergessen:** Antwort ist immer gleich formuliert („Falls ein Konto existiert …“) und künstlich verzögert (mind. 350 ms), damit keine Konten erraten werden können. Rate-Limits: 5 Anfragen bzw. 10 Reset-Versuche (5 je Token) in 15 Minuten.
- Reset-Tokens liegen in `cms_password_resets` und sind einmalig verwendbar.
- **Passkeys:** Der Button erscheint nur, wenn Passkeys technisch verfügbar sind (HTTPS, `SITE_URL`, OpenSSL) und `login_show_passkey` aktiv ist.
- Fehlermeldungen beim Login sind generisch („Anmeldung fehlgeschlagen. Bitte Zugangsdaten prüfen.“).
- Unangemeldete Zugriffe auf `/admin` leiten mit `?redirect=…&login_error=session_required` auf die Loginseite.

### Verwandte Dokumente

[../users-groups/AUTH-SETTINGS.md](../users-groups/AUTH-SETTINGS.md) · [../../member/MEMBER-SECURITY.md](../../member/MEMBER-SECURITY.md) · [../../core/SECURITY.md](../../core/SECURITY.md)
