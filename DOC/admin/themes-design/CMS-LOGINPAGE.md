# 365CMS – Projektdokumentation | Abschnitt: Admin – CMS-Loginseite

> **Stand:** 2026-11-14 | **Version:** 4.0.0 | **Status:** Stable
> **Route:** `/admin/cms-loginpage` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_cms_loginpage`

## English (summary)

The CMS ships its own, theme-independent authentication pages (login, registration, password reset). `/admin/cms-loginpage` (`CMS/admin/cms-loginpage.php` → `CMS/admin/modules/themes/CmsLoginPageModule.php` → `CMS/admin/views/themes/cms-loginpage.php`) configures their branding, texts and behaviour. Everything is stored with the prefix `cms_loginpage_` in `cms_settings` and rendered by `CMS\Services\CmsAuthPageService`.

Public paths: `/cms-login`, `/cms-register`, `/cms-password-forgot` (mode `cms`, default; legacy paths redirect there) or `/login`, `/register`, `/forgot-password` rendered by the active theme's templates (mode `legacy`).

Since 4.0.0 the tab **Showcase** adds an optional image-and-text section below the login form (`showcase_*` settings, image picked from the media library only). `CmsAuthPageService::getShowcase()` returns the data, `views/auth/partials/showcase.php` renders it, and a theme can override the markup with `<theme>/auth/showcase.php`. Themes may suggest colours via `login_page.colors` in `theme.json`; they are applied only through the button **Farben aus dem aktiven Theme übernehmen** in the tab **Farben**.

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

POST-Anfragen werden unter beiden Pfaden verarbeitet. Für englische Aufrufe werden die Pfade über `ContentLocalizationService` lokalisiert (z. B. `/en/cms-login`). Weitere Endpunkte: `/logout` (nur mit CSRF-Token `logout`), `/mfa-challenge`, `/mfa-setup`, `/mfa-disable`, seit 4.0.0 `/cms-verify-email` (Bestätigungslink der E-Mail-Verifizierung: zeigt im Layout der Anmeldeseite die Schaltfläche **E-Mail-Adresse bestätigen** und leitet nach dem Absenden mit Meldung auf die Anmeldeseite; Texte unter `auth.confirm_email.*` in `CMS/lang/*.yaml`; [../users-groups/AUTH-SETTINGS.md](../users-groups/AUTH-SETTINGS.md#e-mail-verifizierung-seit-400)).

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
| Showcase (seit 4.0.0) | siehe [Login-Showcase](#login-showcase-seit-400) |

Farben werden als Hex validiert, URLs geprüft, Texte gekürzt; ungültige Werte fallen auf den Standard zurück. Seit 4.0.0 wird der Hintergrundverlauf `background_start` → `background_end` auf der Core-Loginseite tatsächlich angewendet.

### Login-Showcase (seit 4.0.0)

Optionaler Bereich mit Bild und Text **unter** dem Anmeldebereich (eigener `<section>` nach `</main>`, funktioniert mit `centered` und `split`). Er erscheint nur, wenn er aktiviert ist, die Seite zu `showcase_pages` passt und mindestens eine Überschrift oder ein Feature-Punkt gesetzt ist. Alle Werte liegen wie die übrigen Einstellungen als `cms_loginpage_<schlüssel>` in `cms_settings` (keine Schemaänderung). Das Formular hat einen eigenen Tab **Showcase**; gespeichert wird wie bisher mit dem einen Formular der Seite.

| Schlüssel | Standard | Prüfung beim Speichern |
|---|---|---|
| `showcase_enabled` | `0` | `1`/`0` |
| `showcase_pages` | `login` | `login` (nur Anmeldung) oder `login_register` (Anmeldung und Registrierung); nie auf Passwort-Reset und E-Mail-Bestätigung |
| `showcase_image_url` | leer | nur lokale Bilder aus der Mediathek: Pfad unter `/uploads/` (auch unterhalb eines `SITE_URL`-Unterpfads), Endung `jpg`, `jpeg`, `png`, `webp`, `avif`; keine Query, kein Fragment, keine Pfad-Traversal. Absolute URLs mit dem Origin von `SITE_URL` werden auf den Pfad gekürzt, externe URLs **abgelehnt** (Fehlermeldung, nichts wird gespeichert) – die CSP erlaubt für `img-src` nur `'self'`, `data:`, `blob:` und den Seiten-Origin |
| `showcase_image_alt` | leer | Text, max. 160 Zeichen; **Pflicht**, sobald ein Bild gesetzt ist (sonst Fehlermeldung, nichts wird gespeichert) |
| `showcase_image_width`, `showcase_image_height` | leer | werden beim Speichern aus der Bilddatei ermittelt (`ImageService::getDimensions()`), nicht aus dem Formular |
| `showcase_image_position` | `left` | `left` oder `right` (gilt ab 768 px Breite) |
| `showcase_eyebrow` | leer | Text, max. 60 Zeichen |
| `showcase_headline` | leer | Text, max. 120 Zeichen |
| `showcase_text` | leer | mehrzeilig, kein HTML, max. 600 Zeichen; jede nicht leere Zeile wird ein Absatz |
| `showcase_features` | leer | ein Punkt pro Zeile, max. 8 Punkte à 140 Zeichen (weitere Zeilen werden verworfen); Zeilenzähler im Formular |
| `showcase_cta_label` | leer | Text, max. 60 Zeichen; nur zusammen mit `showcase_cta_url` |
| `showcase_cta_url` | leer | interner Pfad oder `http(s)`-URL; `javascript:`, `data:` u. a. werden abgelehnt |

**Bildauswahl:** über den vorhandenen Medien-Picker (`assets/js/admin-media-integrations.js`, Vorschau-Variante `screenshot`, Token `editorjs_media`). Hochladen ist im Tab nicht vorgesehen; Bilder zuerst in der Mediathek hochladen. Liegt neben einem JPG/PNG eine gleichnamige `.webp`-Datei, wird sie per `<picture>`/`<source type="image/webp">` bevorzugt.

**Ausgabe und Barrierefreiheit:** `<section id="cms-auth-showcase" class="cms-auth-showcase cms-auth-showcase--image-left|right" aria-labelledby="cms-auth-showcase-title">` mit Dachzeile, `<h2 id="cms-auth-showcase-title">`, Absätzen, `<ul>` mit dekorativen Häkchen (`aria-hidden="true"`), optionalem Link und dem Bild als `<figure>` mit `alt`, `width`/`height`, `loading="lazy"` und `decoding="async"`. Ohne Alternativtext wird kein Bild ausgegeben. Alle Werte werden mit `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` escaped, URLs zusätzlich über `esc_url()`. Layout Mobile-First einspaltig (Text vor Bild), ab 768 px zweispaltig; Stile im Nonce-`<style>`-Block der Loginseite, Farben ausschließlich aus den `--cms-auth-*`-Variablen, Breite über `--cms-auth-showcase-width` (Standard 1040 px).

**Vorschau:** Schaltfläche **Showcase ansehen** öffnet die Anmeldeseite mit dem Anker `#cms-auth-showcase` (bei `login_register` zusätzlich die Registrierung).

### Showcase in Themes

- **Daten:** `CMS\Services\CmsAuthPageService::getInstance()->getShowcase(string $pageType = 'login'): ?array` liefert `null` (abgeschaltet, Seite nicht passend oder leer) oder
  `['position' => 'left'|'right', 'eyebrow' => string, 'headline' => string, 'paragraphs' => list<string>, 'features' => list<string>, 'cta' => null|['label' => string, 'url' => string], 'image' => null|['src' => string, 'webp' => ?string, 'alt' => string, 'width' => ?int, 'height' => ?int]]`.
  Die Werte sind **nicht** escaped – Templates escapen selbst.
- **Override:** Existiert `<aktives Theme>/auth/showcase.php`, bindet `CmsAuthPageService::renderShowcase($showcase, $pageType, $mode)` diese Datei statt `CMS/views/auth/partials/showcase.php` ein. Verfügbar sind `$showcase` (Array oben), `$pageType` (`login`/`register`) und `$showcaseMode` (`cms` = Core-Loginseite ohne Theme-CSS, `legacy` = Theme-Vorlage). Im Modus `cms` lädt die Loginseite kein Theme-CSS: Ein Override bringt eigene Stile nur als `<style <?= \CMS\Security::instance()->nonceAttr() ?>>` mit.
- **Legacy-Modus:** `PublicRouter::redirectLegacyLogin()` übergibt `showcase` an `ThemeManager::render('login', ['login_redirect' => …, 'showcase' => …])` (Registrierung entsprechend mit `getShowcase('register')`). `cms-default/login.php` gibt ihn nach der Karte über `renderShowcase($showcase, 'login', 'legacy')` aus; die Stile stehen in `cms-default/style.css`.

### Farben aus dem Theme übernehmen (seit 4.0.0)

Ein Theme kann in seiner `theme.json` Farben für die Core-Loginseite vorschlagen:

```json
"login_page": {
  "colors": {
    "background_start": "#f7f6f2",
    "background_end": "#f2f1ec",
    "card_background": "#ffffff",
    "text_color": "#1a1a18",
    "muted_color": "#6b6b65",
    "primary_color": "#8f5f15",
    "primary_text_color": "#ffffff",
    "link_color": "#8f5f15",
    "input_background": "#ffffff",
    "input_border": "#c8c6bc"
  }
}
```

Erlaubt sind nur die zehn Farbschlüssel der Einstellungen, jeweils als `#rrggbb`; andere Schlüssel und Werte werden ignoriert, fehlende Schlüssel bleiben unverändert. Ist ein gültiger Block vorhanden, zeigt der Tab **Farben** die Vorschläge und die Schaltfläche **Farben aus dem aktiven Theme übernehmen** (eigenes POST-Formular mit CSRF-Aktion `admin_cms_loginpage`). Beim Klick werden nur diese Farbwerte gespeichert. Eine automatische Übernahme beim Theme-Wechsel gibt es bewusst nicht. `cms-default` liefert einen Beispielblock.

### Audit-Log

Speichern (`cms_loginpage.saved`, Metadaten: geänderte Einstellungsnamen ohne Werte, ob Showcase-Felder betroffen sind) und Farbimport (`cms_loginpage.theme_colors_imported`, Theme-Slug und geänderte Farbschlüssel) werden in der Kategorie `setting` protokolliert.

### Sicherheitsverhalten

- **Passwort vergessen:** Antwort ist immer gleich formuliert („Falls ein Konto existiert …“) und künstlich verzögert (mind. 350 ms), damit keine Konten erraten werden können. Rate-Limits: 5 Anfragen bzw. 10 Reset-Versuche (5 je Token) in 15 Minuten.
- Reset-Tokens liegen in `cms_password_resets` und sind einmalig verwendbar.
- **Passkeys:** Der Button erscheint nur, wenn Passkeys technisch verfügbar sind (HTTPS, `SITE_URL`, OpenSSL) und `login_show_passkey` aktiv ist. Schlägt die Anmeldung im Browser fehl, erscheint seit 4.0.0 eine Inline-Meldung (`role="alert"`) unter dem Button statt eines `window.alert()`.
- Fehlermeldungen beim Login sind generisch („Anmeldung fehlgeschlagen. Bitte Zugangsdaten prüfen.“).
- Unangemeldete Zugriffe auf `/admin` leiten mit `?redirect=…&login_error=session_required` auf die Loginseite.

### Verwandte Dokumente

[../users-groups/AUTH-SETTINGS.md](../users-groups/AUTH-SETTINGS.md) · [../../member/MEMBER-SECURITY.md](../../member/MEMBER-SECURITY.md) · [../../core/SECURITY.md](../../core/SECURITY.md)
