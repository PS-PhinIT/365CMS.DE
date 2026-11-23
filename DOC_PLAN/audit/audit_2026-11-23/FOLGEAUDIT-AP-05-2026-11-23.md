# AP-05 – CSRF, Header, Cookies, Browser-Schutz (Version 4.2.5)

**Stand 23.11.2026.** Geprüft wurde `main` nach `8679a05` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung und
Skriptsuchen über `CMS/core/`, `CMS/admin/`, `CMS/member/`, `CMS/index.php`, `CMS/.htaccess` und die Router; der Apache-Befund
zusätzlich mit einer lokalen Apache-2.4-Instanz. Maßstab: [Audit-Katalog, AP-05](../AUDIT-KATALOG-4.2.0.md#ap-05-csrf-header-cookies-browser-schutz).
Der Bericht betrifft nur AP-05; er ist keine Freigabe der gesamten Browser-Schutzschicht. Es wurden keine Browser, Konten oder
Produktivsysteme verwendet. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Ein Befund (MEDIUM bis LOW, umgesetzt): Der Apache-Fallback in `CMS/.htaccess` überschrieb mit `Header always set` die
Header, die der Core je Route bewusst abweicht (`X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: no-referrer`). Dazu eine
Angleichung (Permissions-Policy). Die Token-Prüfung selbst ist auf allen gelesenen Pfaden vorhanden; die übrigen Prüfpunkte sind erfüllt
oder als offen mit Prüfvorgehen geführt.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 05.1 Token je Zustandsänderung | ja | Öffentliche POST-Routen: globale Sperre in `CMS/core/Router.php:286–330` (Aktion `form_guard`), Ausnahmen haben eigene Prüfung je Aktion: `login`, `register`, `forgot_password`, `mfa_challenge`, `mfa_setup`, `mfa_disable` (`CMS/core/Routing/PublicRouter.php:181, 324, 458, 514, 569, 614`), `comment_<id>` (`:983, :991`), `form_<schlüssel>` (`CMS/core/Services/Forms/FormFrontendController.php:152`). Admin: alle 76 Einstiegsdateien in `CMS/admin/*.php`, die POST verarbeiten (von 140), laufen über die Shell mit `csrf_action` (`CMS/admin/partials/section-page-shell.php:184–200`) oder prüfen selbst; die sechs `*-api.php` verlangen `POST` und `verifyPersistentToken` mit eigener Aktion. Mitglieder: jede Behandlungsmethode ruft `verifyCsrf('<aktion>')` (`CMS/member/includes/class-member-controller.php:340–344`). Editor-/Upload-Endpunkte: Kopf `X-CSRF-Token` oder Feld plus Token (`EditorJsRequestGuard.php:44–58`, `FileUploadService.php:59–62`). Skriptsuche (Funktionen mit `$_POST` ohne Token-Aufruf in Router, Mitgliederbereich, Admin-Einstiegen): nur `handleVerifyEmail`, `handleConfirmEmailChange` (geheimer Token im Formular, im Router begründet), `refreshApiToken` (Refresh-Token statt Cookie) und `renderContact` (delegiert an die Formularprüfung). | – |
| 05.2 Keine Änderung per GET | ja | Newsletter bestätigen/abmelden: `GET` zeigt Seite mit Knopf, `POST` ändert (`CMS/core/Services/Newsletter/NewsletterFrontendController.php:44–47, 151–199`); Admin-Einstiege werten `$_GET['action']` nur zur Ansichtswahl aus (`admin/forms.php:47`, `admin/downloads.php:72`); Abmelden per Link trägt ein einmaliges Token (`CMS/core/Routing/PublicRouter.php:854–864`). Hinweis INFO-1. | – |
| 05.3 Header | ja, mit Befund | Core: `CMS/core/Security.php:276–330` (nosniff, XFO `DENY`, `X-XSS-Protection: 0`, Referrer, Permissions, COOP `same-origin`, CORP `same-site`, HSTS nur über HTTPS und ohne Debug, nonce-CSP mit Trusted Types). `private, no-store` für Admin, API und Mitglieder: `Security.php:288–291`, `CMS/core/Router.php:788–822`, `CMS/member/includes/bootstrap.php:18`, verspätet gestartete Sitzungen in `CMS/index.php:318–327`. Befund TMP-AP05-1. | Abgleich im Browser (siehe unten) |
| 05.4 CORS | ja | Im PHP-Code kein `Access-Control-*`; Apache gibt `*` nur für Schriftdateien unter `uploads/fonts/` ohne Cookies frei (`CMS/.htaccess:151–153, 276–277`). Der Web-Vitals-Empfänger prüft `Origin` und `Referer` gegen `SITE_URL` (`CMS/core/Routing/ApiRouter.php:590–613`), Hinweis INFO-4. | – |
| 05.5 Clickjacking | ja | Global `X-Frame-Options: DENY` und `frame-ancestors 'none'` (`Security.php:121, 281`), damit auch Admin und Anmeldung. Bewusste Ausnahmen mit `SAMEORIGIN`/`frame-ancestors 'self'`: Newsletter-Vorschau im Admin (`CMS/admin/modules/newsletter/NewsletterModule.php:310–311`) und Einbettungsseite des Aggregators (`CMS/core/Services/Feed/AggregatorFrontendController.php:507–513`). | – |
| 05.6 Cookies | ja | Sitzung: `HttpOnly`, `SameSite=Strict`, `Secure` über HTTPS, `use_strict_mode`, nur Cookies (`CMS/index.php:245–250`, `Security.php:345–349`), Host-only (kein `Domain`), Lebensdauer 0 ohne „Angemeldet bleiben“ (`Auth.php:1560–1583`). Gerätecookie `cms_device`: HMAC-signiert, an die Sitzungs-ID gebunden, `HttpOnly`/`Strict` (`Auth.php:1452–1470, 1520–1548`). Cookie-Einwilligung und Theme setzen `SameSite=Lax`, `Secure` im sicheren Kontext (`assets/js/cookieconsent-init.js:274`, `themes/cms-default/js/theme.js:260`). Hinweis INFO-2 (Schlüssel-Rückfall). | – |

## Abweichungen

### TMP-AP05-1 · Apache-Fallback überschreibt Header des Core (MEDIUM bis LOW, umgesetzt)

- **Fundstelle:** `CMS/.htaccess:108–120` (vor der Korrektur `Header always set X-Frame-Options "DENY"` und `Header always set Referrer-Policy "strict-origin-when-cross-origin"`).
- **Ablauf:** Der Core setzt beide Header je Route anders als der Standard: `X-Frame-Options: SAMEORIGIN` für die Newsletter-Vorschau im Admin (`NewsletterModule.php:311`) und die Einbettungsseite des Aggregators (`AggregatorFrontendController.php:507`); `Referrer-Policy: no-referrer` auf Seiten mit Token in der URL (E-Mail-Bestätigung `PublicRouter.php:777`, Entwurfsvorschau `ThemeRouter.php:1367`, Passwort-Zurücksetzen `CmsAuthPageService.php:565`, SSO `SsoRouter.php:196`, Downloads `DownloadFrontendController.php:622`, Anhänge `AttachmentController.php:74`). Unter Apache setzt `Header always set` den Wert nach der Anwendung und ersetzt ihn. Nachweis lokal mit Apache 2.4 und einem CGI-Skript, das beide Header setzt (stellvertretend für PHP): `always set` liefert `X-Frame-Options: DENY` und `Referrer-Policy: strict-origin-when-cross-origin`; mit `always setifempty` bleiben `SAMEORIGIN` und `no-referrer`.
- **Auswirkung:** Auf Apache blockiert `DENY` den Vorschau-Rahmen im Admin und die Einbettungsseite, obwohl der Core sie erlaubt (Funktionsfehler). Die Seiten mit Token in der URL senden bei gleicher Herkunft den vollen Pfad samt Token als Referer statt keinen; bei fremder Herkunft nur den Ursprung (Datenschutz-Härtung geschwächt, kein Token-Abfluss an Dritte).
- **Korrektur:** Beide Direktiven auf `Header always setifempty` (gleiche Logik wie CSP und HSTS im selben Block): Der Core bestimmt den Wert, der Fallback ergänzt Antworten, die der Core nicht erzeugt (statische Dateien, Fehlerseiten des Servers).
- **Regression:** `TESTS/security/header-layers.php` (vier Prüfungen auf die Direktive, fünf weitere auf die Angleichung); ohne die Korrektur schlagen fünf Prüfungen fehl (Direktive für `X-Frame-Options` und `Referrer-Policy`, zwei allgemeine Prüfungen auf mehrfach gesetzte Core-Header, Permissions-Policy). **Status:** umgesetzt. **Konfidenz:** hoch für das Verhalten von `Header always set` (lokal mit Apache nachgestellt); nicht geprüft sind `mod_php`/PHP-FPM auf dem Zielsystem und die sichtbare Wirkung im Browser.

### TMP-AP05-2 · Permissions-Policy in Core und Fallback unterschiedlich (INFO, umgesetzt)

- **Fundstelle:** `CMS/core/Security.php:284` nannte `geolocation`, `microphone`, `camera`; `CMS/.htaccess:140` zusätzlich `payment`. Unter Apache gilt die zweite Fassung, unter anderen Servern die erste.
- **Korrektur:** Der Core nennt jetzt dieselben vier Verbote. Eine Verwendung der Zahlungs-Programmierschnittstelle des Browsers gibt es im Code nicht (Suche in `CMS/assets/js/` und Vorlagen).
- **Regression:** Teil von `TESTS/security/header-layers.php` (Vergleich der Direktivenmengen).

## Hinweise ohne Änderung

- **INFO-1:** Abmelden akzeptiert das Token auch in der URL (`PublicRouter.php:857`; Links in `admin/partials/sidebar.php:26` und `member/partials/header.php:35`). Das Token gilt einmalig und je Aktion; es kann im Verlauf des Browsers oder im Zugriffsprotokoll des Servers stehen, ist dann aber verbraucht. Kein CSRF-Weg.
- **INFO-2 (Übergabe an AP-07.5):** Fehlen `JWT_SECRET`, `SECURE_AUTH_KEY` und `AUTH_KEY`, fallen mehrere Funktionen auf ableitbare Werte zurück: Gerätecookie `hash('sha256', __FILE__ . PHP_VERSION)` (`Auth.php:1503`), TOTP-Wiederverwendungssperre `'cms-totp'` (`Auth/AuthManager.php:508`), Zwischenspeicher (`CacheManager.php:91`), Downloads (`DownloadSecurity.php:47`), Formular-Spamschutz (`FormSpamGuard.php:277`). Der Installer erzeugt alle Schlüssel (`install/InstallerService.php:383–395, 935–936, 1003`); relevant ist nur eine Installation mit fehlenden oder Platzhalter-Werten. Ob ein Start ohne brauchbare Schlüssel verweigert oder gemeldet werden soll, gehört in AP-07.
- **INFO-3 (offen, Browser):** `Cross-Origin-Resource-Policy: same-site` gilt für alle Antworten, auch Mediendateien in `uploads/` (nur Schriften ausgenommen, `.htaccess:277`). Bilder aus Newsletter-Kampagnen oder fremden Seiten, die Uploads einbetten, lädt ein Browser-Mailprogramm auf anderer Site nicht. Ob das gewollt ist (Hotlink-Schutz) oder für Newsletter-Bilder gelockert werden soll, ist eine Entscheidung des Betreibers; Gegenprobe im Webmail-Client steht aus.
- **INFO-4:** `isSameOriginRequest()` vergleicht Schema und Host, nicht den Port. Der Empfänger ist ein öffentlicher, ratenbegrenzter Messpunkt ohne Anmeldung; die Lücke hat keine Folge.

## Geprüft, ohne Befund

- Persistente und einmalige Token: Aktion je Formular, Ablauf nach einer Stunde, höchstens 20 gleichzeitig, `hash_equals`, Nicht-Strings gelten als ungültig (`Security.php:395–540`).
- CSP-Erweiterung nur für `script-src`, `connect-src`, `img-src`, nur `https`-Hosts ohne Pfad (`Security.php:146–173`).
- 304-Antworten ohne neue CSP, damit zwischengespeicherte Nonces passen (`CacheManager.php:742–747`).
- Cron-Aufruf bevorzugt den Kopf vor dem Parameter `token` (`CMS/cron.php:78–91`).

## Testlauf und Übergabe

PHP 8.3.6 CLI unter Linux. `php TESTS/security/run.php`: neue Prüfungen grün; die bekannte Rotprüfung des Quellarchivs `CMS_ASSETS`
(Umzug in ein privates Repository) behebt PR #194. Apache 2.4 (Ubuntu-Paket) nur für den Nachweis des Überschreibens.

**Nächster Schritt:** AP-06 (Uploads, Medien, Dateien, Backups). Offen aus AP-05: Browser-Gegenprobe der Vorschau-Rahmen unter Apache mit PHP-FPM (TMP-AP05-1), CORP-Entscheidung für Newsletter-Bilder (INFO-3), Schlüssel-Rückfälle in AP-07 (INFO-2).
