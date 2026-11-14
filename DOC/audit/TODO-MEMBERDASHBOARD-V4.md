# 365CMS – Projektdokumentation | Abschnitt: ToDo-Plan Memberdashboard, Newsletter-Widget und Login-Showcase

> **Stand:** 2026-11-14 | **Version:** 4.0.0 | **Prüfumfang:** Core-Mitgliederbereich (`CMS/member/`, `CMS/core/Member/`, `CMS/core/Routing/MemberRouter.php`), Newsletter-Modul (`CMS/core/Services/Newsletter/`), Login-Seite (`CMS/views/auth/cms-auth.php`, `CMS/core/Services/CmsAuthPageService.php`, `CMS/admin/cms-loginpage.php`), Theme `cms-phinit` 1.9.6 aus dem Repository `PS-PhinIT/365CMS.DE-PhinIT_THEME` | Übersicht: [README.md](README.md)

## English (summary)

Planning document for the next work on the member area in 4.0.0. No code is changed by this document. It covers four topics:

1. Bring the core member dashboard in line with 4.0.0: authentication and CSRF handling, broken or unused data (favorites, notifications, admin settings without effect), duplicated sanitizers, accessibility and responsive layout.
2. A newsletter widget on the member dashboard that subscribes and unsubscribes directly, built on the existing core module (`newsletter_subscribers`, `NewsletterRepository`, `NewsletterMemberSection`) with double opt-in, consent checkbox and the account e-mail prefilled.
3. Align the member area of the `cms-phinit` theme. Its dashboard override ignores the core dashboard data (plugin widgets, newsletter widget, profile progress), runs its own SQL and has 120 hex and 96 rgb/rgba literals in `member-auth.css`.
4. A configurable showcase section (screenshot, heading, text, feature list) below the login form. In the default slug mode `cms`, the login page is the standalone core view `views/auth/cms-auth.php`; the theme template `login.php` is only used in `legacy` mode. The plan therefore puts the feature into the core view first and adds the theme variant on top.

Work packages: 1. core base and newsletter back end, 2. standard dashboard UI, 3. login showcase (admin and front end), 4. `cms-phinit` design, 5. QA and tests.

## Deutsch

### Arbeitsweise

- **Nur Planung.** Dieses Dokument ändert keinen Code. Jede Aufgabe wird in einem eigenen Branch umgesetzt; der Status wechselt dann von `- [ ]` auf `- [x]` mit Verweis auf den Pull Request.
- **Version.** Änderungen am Core stehen unter `4.0.0` in `Changelog.md`. Für das Theme gilt die Regel aus `.github/copilot-instructions.md` (Abschnitt „Commit-Checkliste“) des Theme-Repositorys: Jeder Theme-PR hebt die Version in `theme.json`, `update.json`, `style.css`, der Konstante in `functions.php` und im README an und trägt die Änderungen unter genau dieser Version in `cms-phinit/CHANGELOG.md` ein.
- **IDs.** `MD` (Memberdashboard Core), `NL` (Newsletter-Widget), `UI` (Oberfläche, Responsive, Barrierefreiheit), `PH` (Theme `cms-phinit`), `LS` (Login-Showcase), `QA` (Tests, Doku).
- **Priorität:** 🔴 P1 – Fehler, Sicherheit oder Datenverlust · 🟠 P2 – fehlende oder wirkungslose Funktion · 🟡 P3 – Oberfläche, Code-Qualität · ⚪ P4 – optional.
- **Fundstellen** beziehen sich auf den Stand vor der Umsetzung: Core-Commit `6edfacc`, Theme-Commit `ade984c`. Pfade ohne Präfix liegen unter `CMS/`, Theme-Pfade beginnen mit `cms-phinit/`.

### Bestandsaufnahme (Kurzfassung)

| Bereich | Befund |
|---|---|
| Routing | `/member/:page` lädt zuerst `<theme>/member/<page>.php`, dann `member/<page>.php` (`core/Routing/MemberRouter.php:78-110`). `cms-default` hat keine Member-Overrides, `cms-phinit` überschreibt alle zehn Core-Seiten und bringt sechs eigene mit (analytics, comments, feeds, forum, newsletter, posts). |
| CSRF | `/member*`, `/dashboard*`, `/newsletter/` und `/contact/` sind vom globalen Formularschutz ausgenommen (`core/Router.php:204-216`). Jede Seite prüft selbst; Tokens sind Einmal-Tokens mit höchstens 20 Einträgen je Aktion (`core/Security.php:21-22`, `:397-447`). |
| Dashboard-Daten | `MemberController::getDashboardData()` (`member/includes/class-member-controller.php:479-494`) liefert Begrüßung, Statistik, Plugin-Widgets, eigene Widgets, Profilfortschritt, Benachrichtigungen, Kontostatus, Aktivität. Das Theme-Dashboard nutzt davon nur vier Einstellungen. |
| Newsletter | Core-Modul mit Tabelle `newsletter_subscribers` (u. a. `status`, `user_id`, `optin_token`, `consent_at`, `consent_text`, `segment_slug`, `locale`; `core/SchemaManager.php:1276-1300`). Mitgliederseite `/member/plugin/newsletter` über `NewsletterMemberSection` (`core/Services/Newsletter/NewsletterMemberSection.php`), Dashboard-Kachel nur als Zähler (0/1). |
| Login | Standard `auth_slug_mode = cms` (`core/Services/CmsAuthPageService.php:580`): `/cms-login` rendert `views/auth/cms-auth.php` ohne Theme-CSS. Einstellungen als `cms_loginpage_<key>` in `{prefix}settings`, Admin unter `/admin/cms-loginpage` (Capability `manage_settings`). |
| Tests | `TESTS/` wurde mit Commit `6edfacc` („delete TEST Files“) entfernt, `.gitignore:100-102` sieht das Verzeichnis als versioniert vor. Das Theme hat eigene Regressionstests unter `cms-phinit/tests/`. |
| Doku | `DOC/member/MEMBER-DASHBOARD.md` trägt noch Version 3.5.0. |

Ohne Befund nach Prüfung: keine `TODO`/`FIXME`/`@deprecated`-Marker und keine 3.5er-Versionsangaben in `member/**`, `PluginDashboardRegistry` und `MemberRouter`; keine `onclick`-Handler und keine Inline-`<style>`-Blöcke in den Member-Templates außer der Farbvariablen-Injektion in `member/partials/header.php:95-104`.

---

### 1. Allgemeines Memberdashboard und Newsletter-Bereich (Core, Standard-Layout)

#### 1.1 Architektur und Sicherheit auf 4.0.0-Stand

**Authentifizierung und Sitzung**

- [ ] 🟠 **MD-01** Login-Weiterleitung vereinheitlichen. `MemberController::requireAuth()` leitet fest auf `/login?redirect=` (`member/includes/class-member-controller.php:64-73`), der Router dagegen auf den lokalisierten Pfad aus `CmsAuthPageService::getPublicPath('login')` (`core/Routing/MemberRouter.php:164-170`). Beide auf `CmsAuthPageService` umstellen, damit `auth_slug_mode` und `/en/…` greifen.
- [ ] 🟡 **MD-02** `getCurrentUser()` ruft bei jedem Aufruf erneut `requireAuth()` auf (`class-member-controller.php:75-79`). Benutzer einmal je Request zwischenspeichern.
- [ ] 🟡 **MD-03** Doppelte Weiterleitung `/member` → `/member/dashboard` → `/member/profile` bei abgeschaltetem Dashboard (`MemberRouter.php:68-76`, `member/dashboard.php:16-19`) auf eine Weiterleitung reduzieren.

**CSRF und Formularablauf**

- [ ] 🔴 **MD-04** Token-Verdrängung in der Medienverwaltung beheben. `member/media.php` erzeugt je Ordner- und Dateizeile ein neues Token `member_media_action` (`:116`, `:167`) plus drei weitere (`:62`, `:189`, `:213`). Bei mehr als etwa 17 Einträgen sind die Tokens der ersten Zeilen aus dem 20er-Puffer verdrängt, Löschen schlägt mit „Sicherheitsüberprüfung fehlgeschlagen“ fehl. Lösung: ein Token je Seitenaufruf an alle Formulare oder `verifyPersistentToken()` für diese Aktion.
- [ ] 🔴 **MD-05** POST in Plugin-Bereichen nach dem PRG-Muster umbauen. `member/plugin-section.php:24-31` ruft `post_callback` erst nach der Ausgabe von `header.php` auf; Weiterleitungen sind dort unmöglich, F5 sendet erneut mit verbrauchtem Token. Die POST-Verarbeitung vor die Ausgabe ziehen (`PluginDashboardRegistry::handleRoute()`, `core/Member/PluginDashboardRegistry.php:383-429`) und Rückmeldungen als Flash übergeben.
- [ ] 🟠 **MD-06** `PluginDashboardRegistry::verifyPostRequest()` (`:457-483`) lässt Anfragen ohne `Origin`- und `Referer`-Header passieren und prüft das Token nur bei `'csrf' => 'core'`. Standardwert für neue Registrierungen auf `'core'` setzen; `'plugin'` nur noch ausdrücklich und mit Hinweis im Debug-Log.
- [ ] 🟠 **MD-07** Fehlerantworten 403/404 aus `PluginDashboardRegistry.php:398-414` und `member/partials/plugin-not-found.php` geben HTML-Fragmente ohne Kopf und Fuß aus. Über `header.php`/`footer.php` des Mitgliederbereichs rendern, `SITE_URL`-Absolutlink (`plugin-not-found.php:15`) durch relativen Pfad ersetzen.
- [ ] 🟠 **MD-08** `handleRoute($slug)` erhält die Routenparameter nicht (`MemberRouter.php:139`); stattdessen werden `:action` und `:id` in `$_GET` geschrieben (`:119-124`). Parameter direkt übergeben, `$_GET`-Manipulation entfernen.

**Eingaben und Datenzugriff**

- [ ] 🟠 **MD-09** Unvalidierte Felder per Whitelist bzw. Längenbegrenzung absichern: `notification_frequency` (`class-member-controller.php:1118`), `phone`, `company`, `position`, `birth_date` (`:975-978`, Datum per `DateTimeImmutable::createFromFormat`), `location` (`:1016`, `:1039`), `credential_name` (`:1408`).
- [ ] 🟠 **MD-10** Datenschutz: Der Löschantrag (`privacy_delete_request`, `member/privacy.php:91-93`, Handler `:1126-1161`) verlangt keine Passwortbestätigung. Re-Auth wie bei `sessions_revoke_all` ergänzen (`:1331-1337`).
- [ ] 🟡 **MD-11** `passkey_delete` und `totp_confirm` (`security.php`, Handler `:1286-1420`) ohne Re-Auth: `passkey_delete` an die übrigen sicherheitsrelevanten Aktionen angleichen.
- [ ] 🟡 **MD-12** Rohes SQL aus dem Controller in Services verschieben: `activity_log` (`:607`), `orders`/`subscription_plans` (`:627-635`), `favorites` (`:708`), `users` (`:1503`, `:1512`), `user_meta`-Upsert (`:1522`, doppelt zu `MemberService::setUserMeta`).
- [ ] 🟡 **MD-13** `MemberService::getUserMeta()` (`core/Services/MemberService.php:148`) läuft je Seite mehrfach ohne Cache (Header, Sidebar, Dashboard, Avatar, Profilfortschritt, Sicherheitsdaten). Request-Cache je Benutzer einführen.
- [ ] 🟡 **MD-14** Sanitizer zusammenführen. URL- und Farbprüfung liegen je viermal vor (`member/dashboard.php:40-59`, `member/partials/header.php:15-34`, `class-member-controller.php:1616-1638`, `PluginDashboardRegistry.php:505-527`), Text- und Asset-Prüfung je zweimal. Eine Klasse `CMS\Member\MemberSanitizer` (oder Erweiterung von `Security::sanitize()`) anlegen und alle Stellen darauf umstellen.
- [ ] ⚪ **MD-15** Docblock-Altlast `@package CMSv2` ersetzen: `PluginDashboardRegistry.php:36`, `MemberRouter.php:5`, `NewsletterMemberSection.php:9`, `NewsletterHooks.php:11`, `NewsletterInstaller.php:10`, `NewsletterFrontendController.php:16`.

**Widgets, Benachrichtigungen, Konto**

- [ ] 🔴 **MD-16** Favoriten reparieren. Tabelle `favorites` hat nur `id`, `user_id`, `post_id`, `created_at` (`core/SchemaManager.php:742-751`), `getFavorites()` liest `url`, `title`, `type` (`class-member-controller.php:702-742`). Alle Einträge erscheinen als „Eintrag #id“ mit Link `#`. Per `JOIN` auf `posts` Titel und Permalink laden. Der Core schreibt die Tabelle nirgends; Merken/Entfernen als Core-Endpunkt ergänzen (das Theme hat einen eigenen in `cms-phinit/functions.php`, `handleFavoriteToggleRequest`).
- [ ] 🟠 **MD-17** Benachrichtigungen: Die Tabelle `notifications` (`SchemaManager.php:753-767`) wird im Core von keinem Code beschrieben, es gibt keine Aktion „gelesen“. `NotificationService` mit `create()`, `markRead()`, `markAllRead()`, `countUnread()` anlegen; `url` und `is_read` in `member/notifications.php` und auf dem Dashboard anzeigen; Datum über die Sprach-/Datumshelfer formatieren statt roh.
- [ ] 🟠 **MD-18** Benachrichtigungs-Einstellungen in `user_meta['notification_preferences']` (`MemberService.php:216-250`) haben keine Wirkung. Beim Anlegen von Benachrichtigungen auswerten; Schalter `email_marketing` mit dem Newsletter-Status verbinden (siehe NL-09).
- [ ] 🟠 **MD-19** Wirkungslose Admin-Einstellungen: `section_order`, `dashboard_columns`, `widgets` (`admin/modules/member/MemberDashboardModule.php:405-419`, `:660-666`) sowie `notifications.email_enabled`, `digest_frequency`, `sender_name` werden gespeichert, aber im Frontend nicht gelesen. Entweder in `member/dashboard.php` umsetzen oder aus der Admin-Oberfläche entfernen. Empfehlung: Reihenfolge und Spalten umsetzen, E-Mail-Optionen erst mit MD-17.
- [ ] 🟠 **MD-20** Plugin-Widgets: `badge` und `admin_link` werden geprüft (`class-member-controller.php:1606-1608`), aber nicht ausgegeben. In der Widget-Karte rendern.
- [ ] 🟡 **MD-21** Sidebar-Sortierung: `getMenuItems()` sortiert alphabetisch und dann nach fester Core-Reihenfolge (`:460-471`); `priority`, `category` und `parent_slug` der Registry wirken nicht. Sortierung nach `priority`, Gruppierung nach `category`, Unterpunkte über `parent_slug`.
- [ ] 🟡 **MD-22** Begrüßungstext: Admin speichert erlaubtes HTML (`MemberDashboardModule.php:372`), das Template escapt es (`member/dashboard.php:78`), Tags erscheinen als Text. Über `PurifierService::purify($html, 'strict')` ausgeben.
- [ ] 🟡 **MD-23** Onboarding: `require_profile_completion` wird nie ausgewertet. Umsetzen (Hinweis und Sperre definierter Bereiche bis Profil vollständig) oder Option entfernen.
- [ ] 🟡 **MD-24** Ungenutzte Dashboard-Daten (`favorites`, `permissions`, `meta`, `two_factor_enabled`, `password_changed_relative`) entweder anzeigen (Sicherheitskarte: 2FA-Status, letzte Passwortänderung; Favoriten-Karte) oder nicht mehr laden. Benachrichtigungen werden doppelt geladen (`MemberService.php:892`, `class-member-controller.php:490`).
- [ ] 🟡 **MD-25** Nachrichten: Der Handler kennt `message_delete` (`:1175-1180`), `member/messages.php` bietet keine Schaltfläche. Löschen mit `data-cms-confirm` ergänzen.
- [ ] ⚪ **MD-26** Footer nutzt `SITE_NAME`, Header `cms_get_site_name()` (`member/partials/footer.php:19`, `header.php:13`); festen Werbetext in `footer.php:22` durch Einstellung ersetzen.

**Internationalisierung**

- [ ] 🟠 **MD-27** Die Core-Member-Templates enthalten keinen `__()`-Aufruf, `header.php:80` setzt fest `<html lang="de">`. Texte in Sprachdateien (`lang/`) überführen, `lang` aus der Request-Locale setzen. Gilt auch für Leerzustände und Bestätigungsdialoge.

#### 1.2 Newsletter-Widget im Memberdashboard

Ziel: Mitglieder melden sich direkt auf dem Dashboard an oder ab, ohne die Unterseite `/member/plugin/newsletter` aufzurufen. Das Widget nutzt das vorhandene Modul; neue Tabellen sind dafür nicht nötig.

**Bausteine (vorhanden)**

| Baustein | Fundstelle |
|---|---|
| Tabelle `newsletter_subscribers` (`status` pending/active/unsubscribed/bounced, `source` member, `user_id`, `optin_token`, `optin_sent_at`, `consent_at`, `consent_text`, `segment_slug`, `locale`, `confirmed_at`, `unsubscribed_at`) | `core/SchemaManager.php:1276-1300`, Spaltenabgleich `core/Services/Newsletter/NewsletterInstaller.php:32-54` |
| `NewsletterRepository::subscribe(array $input, bool $doubleOptIn)`, `subscriberByUser()`, `subscriberByEmail()`, `unsubscribeById()`, `markConfirmationSent()` | `core/Services/Newsletter/NewsletterRepository.php:138-160`, `:234-298`, `:300`, `:333` |
| `NewsletterMailer::sendConfirmation()` | `core/Services/Newsletter/NewsletterMailer.php:124-147` |
| Einstellungen `require_double_opt_in` (Standard `1`), `confirm_expiry_days` (14), `consent_text`, `show_segment_choice`, `default_segment` | `core/Services/Newsletter/NewsletterSettings.php` |
| Mitgliederbereich, Registrierung mit `csrf => 'core'`, Token-Aktion `member_plugin_newsletter` | `core/Services/Newsletter/NewsletterMemberSection.php:27-111`, `:145-194` |
| Hooks `cms_newsletter_subscribed`, `cms_newsletter_unsubscribed` | `NewsletterRepository.php:294`, `:314`, `:342` |

**Backend und Logik**

- [ ] 🔴 **NL-01** Namen beim Anmelden korrekt übernehmen. `NewsletterMemberSection.php:83-84` liest `$user->first_name`/`last_name`; die Tabelle `users` hat diese Spalten nicht (`SchemaManager.php:73-85`), die Namen liegen in `user_meta`. Über `MemberService::getUserMeta()` lesen.
- [ ] 🔴 **NL-02** Einwilligung im Mitgliederbereich einholen. Das öffentliche Formular verlangt eine Checkbox (`NewsletterFrontendController.php:94`, `:124-126`), der Mitgliederbereich zeigt den Text nur an (`NewsletterMemberSection.php:174-188`). Pflicht-Checkbox `newsletter_consent` ergänzen, ohne Haken keine Anmeldung; `consent_text` in der Sprache des Mitglieds speichern.
- [ ] 🟠 **NL-03** Anmeldelogik aus `NewsletterMemberSection::handlePost()` in eine Service-Methode `NewsletterMemberService::subscribeMember(User $user, array $input): array` und `unsubscribeMember(User $user): array` auslagern, damit Widget und Unterseite denselben Weg nutzen. Regeln beibehalten: Double-Opt-In bei unbestätigter Konto-E-Mail immer, sonst laut `require_double_opt_in`; `user_id` nur bei bestätigter Adresse (`EmailVerificationService::matchableEmail()`, `core/Services/EmailVerificationService.php:91`).
- [ ] 🟠 **NL-04** PRG für Widget und Unterseite: POST an `/member/plugin/newsletter` mit verstecktem Feld `return_to=dashboard`, Verarbeitung vor der Ausgabe (setzt MD-05 voraus), Rückmeldung per Flash, 303 auf `/member/dashboard#newsletter` bzw. die Unterseite. `return_to` nur aus einer festen Liste zulassen.
- [ ] 🟠 **NL-05** Statusabfrage für das Widget: `NewsletterMemberService::statusFor(User $user): array{status, email, segment, locale, confirmed_at, optin_sent_at, can_resend}`. Zuordnung über `user_id`, sonst über bestätigte E-Mail (`NewsletterRepository::find()`, `:127-137`).
- [ ] 🟠 **NL-06** „Bestätigungsmail erneut senden“ für Status `pending` anbieten; die vorhandene 10-Minuten-Sperre (`NewsletterRepository.php:253-257`) im Widget als Hinweis anzeigen.
- [ ] 🟠 **NL-07** Rate-Limit für Mitglieder-Aktionen ergänzen (z. B. 10 Aktionen je Stunde und Konto über `CacheManager`, analog `NewsletterFrontendController::rateLimited()` `:287-307`). Heute gilt im Mitgliederbereich nur die Sperre für Bestätigungsmails.
- [ ] 🟠 **NL-08** `show_segment_choice` auch im Mitgliederbereich beachten (`NewsletterFrontendController.php:85` vs. `NewsletterMemberSection.php:176`); Sprachauswahl aus den aktiven Inhaltssprachen statt fest de/en.
- [ ] 🟡 **NL-09** Profil-Schalter `email_marketing` (`member/notifications.php`, `MemberService.php:216-250`) mit dem Newsletter-Status abgleichen oder durch einen Verweis auf das Widget ersetzen, damit es keine zwei widersprüchlichen Schalter gibt.
- [ ] 🟡 **NL-10** Einwilligungsnachweis: `consent_at`/`consent_text` werden bei erneuter Anmeldung überschrieben (`NewsletterRepository.php:199-204`, `:269-270`). Entscheidung treffen: (a) Verlauf in neuer Tabelle `newsletter_consent_log` (`subscriber_id`, `action` subscribe/confirm/unsubscribe, `source`, `consent_text`, `created_at`, ohne IP) mit Schema-Anhebung, oder (b) Einträge im Audit-Log (`AuditLogger`) je Aktion mit Quelle `member`. Empfehlung: (b), da ohne Schemaänderung.
- [ ] 🟡 **NL-11** Abmelden im Widget mit `data-cms-confirm` absichern; danach Status `unsubscribed` und Schaltfläche „Wieder anmelden“ (erneut mit Einwilligung).
- [ ] 🟡 **NL-12** Opt-in-Token beim Bestätigen rotieren. Heute dient dasselbe Token für Bestätigen, Abmelden und Webansicht und bleibt nach der Bestätigung gleich (`NewsletterRepository.php:671-681`). Prüfen, ob Abmeldelinks bereits versandter Mails gültig bleiben müssen (dann eigenes Abmelde-Token).
- [ ] ⚪ **NL-13** Admin: `user_id` in der Abonnenten-Detailansicht anzeigen und auf das Benutzerkonto verlinken (`admin/views/newsletter/subscriber.php:56-66`), Spalte im CSV-Export ergänzen (`admin/modules/newsletter/NewsletterModule.php:224-230`).
- [ ] ⚪ **NL-14** Einstellung `member_widget_enabled` (Standard an) in `NewsletterSettings` und unter `/admin/newsletter` (Einstellungen) aufnehmen; zusätzlich wirkt die vorhandene Sichtbarkeitsoption `member_dashboard_plugin_cms-newsletter` der Registry.

**Widget-Oberfläche**

- [ ] 🟠 **NL-15** Registrierung erweitern: `dashboard_widget` erhält einen `render_callback` (neuer Schlüssel in `PluginDashboardRegistry::register()`, `:163-188`), damit ein Widget statt der Zählerkachel eigenes Markup liefert. Fallback bleibt die Kachel. Der Callback gibt Markup über ein Template aus (`views/member/widgets/newsletter.php`, im Theme überschreibbar als `<theme>/member/widgets/newsletter.php`), nicht als echo-String wie heute in `NewsletterMemberSection::output()`.
- [ ] 🟠 **NL-16** Widget-Inhalt je Status:
  - kein Abo: Kurztext, E-Mail-Adresse des Kontos schreibgeschützt vorbefüllt (`<input type="email" readonly>` mit Hinweis „Adresse im Profil ändern“), optional Thema und Sprache, Pflicht-Checkbox mit `consent_text`, Schaltfläche „Anmelden“;
  - unbestätigte Konto-E-Mail: Hinweis, dass eine Bestätigungsmail an die Adresse geht (Double-Opt-In erzwungen);
  - `pending`: Hinweis „Bestätigung ausstehend seit …“, Schaltfläche „Erneut senden“ (NL-06), „Anmeldung zurückziehen“;
  - `active`: bestätigt seit `confirmed_at`, Thema/Sprache ändern, „Abmelden“;
  - `unsubscribed` / `bounced`: Status und „Wieder anmelden“ bzw. Hinweis auf ungültige Adresse.
- [ ] 🟠 **NL-17** Formular-Sicherheit: verstecktes Feld `csrf_token` über `PluginDashboardRegistry::csrfField('newsletter')`; ein Token je Seitenaufruf für alle Widget-Formulare (siehe MD-04); alle Ausgaben mit `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`.
- [ ] 🟡 **NL-18** Statusmeldungen mit `role="status"` (Erfolg) bzw. `role="alert"` (Fehler), Status zusätzlich als Text-Badge, nicht nur als Farbe.
- [ ] 🟡 **NL-19** Widget-Position über `section_order` (MD-19) steuerbar; Anker `id="newsletter"` für den Rücksprung nach dem POST.

#### 1.3 Oberfläche, Responsive, Barrierefreiheit, Performance

**Struktur und Navigation**

- [ ] 🟠 **UI-01** Landmarks ergänzen: Inhalt in `<main id="main-content">` statt `div.page-body` (`member/partials/header.php:179`), Sidebar-Liste in `<nav aria-label="Mitgliederbereich">` (`member/partials/sidebar.php:26`), `aria-label` am `<aside>` (`header.php:111`), Skip-Link als erstes Element im `<body>`.
- [ ] 🟡 **UI-02** Überschriften: `h1` ist heute der Seitenname, `h2` der Seitentitel (`header.php:116`, `:169`). Seitentitel als `h1`, Markenname ohne Überschriftenrolle.
- [ ] 🟡 **UI-03** Emoji-Icons in Sidebar und Karten mit `aria-hidden="true"` versehen (`sidebar.php:30`, `dashboard.php:95`, `:108`, `:121`, `:134`, `:214`, `:238`, `messages.php:83`, `media.php:100`) oder durch Tabler-SVG-Icons ersetzen.
- [ ] 🟡 **UI-04** Admin-Link nur einmal anzeigen; heute als Sidebar-Eintrag (`class-member-controller.php:449-451`), im Dropdown (`header.php:158-160`) und als Schaltfläche (`header.php:171-175`).
- [ ] 🟡 **UI-05** Avatar-Dropdown: `<a href="#">` (`header.php:144`) durch `<button type="button" aria-expanded>` ersetzen.

**Karten, Formulare, Leerzustände**

- [ ] 🟠 **UI-06** KPI-Karten: einheitliche Komponente (Wert, Label, Trend/Hinweis, Link) für Logins (30 Tage), Sicherheitswert, ungelesene Nachrichten, aktive Sitzungen, Newsletter-Status. Werte als Text, Farbe nur unterstützend.
- [ ] 🟡 **UI-07** Fortschrittsbalken in `profile.php:123` und `security.php:37` mit `role="progressbar"` und `aria-valuenow/min/max` wie in `dashboard.php:266`.
- [ ] 🟡 **UI-08** Meldungen: `member/partials/alerts.php:20` setzt `role="alert"` auch für Erfolg, der Schließen-Link hat kein `href` (`:32`) und ist per Tastatur nicht erreichbar. Erfolg mit `role="status"`, Schließen als `<button type="button" aria-label="Meldung schließen">`.
- [ ] 🟡 **UI-09** Pflichtfeld-Kennzeichnung `<span aria-label="Pflichtfeld">*</span>` (`profile.php:69`) durch `required`/`aria-required="true"` und sichtbaren Text in der Legende ersetzen.
- [ ] 🟡 **UI-10** Tabellen: `<caption>` (ggf. visuell versteckt), `scope="col"`, Screenreader-Text für leere Kopfzellen (`media.php:139`, `security.php:207`); Aktionsmenüs mit kontextbezogenem `aria-label` (`media.php:107`, `:158`); aktive Unterhaltung mit `aria-current` (`messages.php:41`).
- [ ] 🟡 **UI-11** Leerzustände ergänzen, wo Bereiche heute verschwinden: Plugin-Widgets, eigene Widgets, Sicherheitsempfehlungen (`security.php:44`). Muster: Icon (dekorativ), ein Satz, eine Aktion.
- [ ] 🟡 **UI-12** JavaScript-Rückmeldungen: `window.alert` bei Passkey-Fehlern (`assets/js/member-dashboard.js:107`, `:119`, `:127`) durch Inline-Meldung mit `role="alert"` ersetzen; Kopieren der Backup-Codes (`:297-311`) als Schaltfläche mit Tastaturbedienung und Bestätigung.

**Responsive, CSS, Inline-Code**

- [ ] 🟠 **UI-13** Breakpoints an Tabler/Bootstrap angleichen: `assets/css/member-dashboard.css:173-180` nutzt 1024 px, die Navigation `navbar-expand-lg` (992 px). Mobile-First-Regeln ab 576/768/992/1200 px; Sidebar unter 992 px als ausklappbare Navigation mit sichtbarem Fokus.
- [ ] 🟡 **UI-14** Feste Indigo-Farben in `member-dashboard.css` (`:7`, `:17`, `:104-105`, `:163-171`) auf `var(--member-primary)` und abgeleitete Variablen umstellen; `:focus-visible`-Stile und `@media (prefers-reduced-motion: reduce)` für den Hover-Versatz (`:81-88`) ergänzen.
- [ ] 🟡 **UI-15** Tote Regel `.filepond--root` (`member-dashboard.css:159-161`) entfernen.
- [ ] 🟡 **UI-16** Inline-Stile: sechs `style=""`-Attribute (`dashboard.php:237`, `:266`, `profile.php:108`, `:123`, `security.php:37`, `header.php:146`) auf CSS-Variablen bzw. Klassen umstellen. Sie werden zwar per `InlineStyleRewriter` in Nonce-Klassen umgeschrieben, die Werte gehören aber in Variablen (`--member-widget-color`, `--progress`, Avatar über `<img>`).
- [ ] ⚪ **UI-17** Inline-Script-Block in `member/partials/footer.php:38-44` (`$inlineJs`) wird von keiner Core-Seite gesetzt; entfernen oder dokumentieren.
- [ ] 🟡 **UI-18** Performance: Member-CSS und -JS nur auf Member-Seiten laden (heute ok), Tabler-Icons-Teilmenge prüfen, `member-dashboard.js` als Modul mit `defer` und in Seitenmodule aufteilen (Passkey, Upload, Medien).

---

### 2. Memberdashboard im Theme `cms-phinit`

Grundlage: Theme 1.9.6, `requires_cms` 3.4.00, `tested_up_to` 4.0.0 (`cms-phinit/theme.json`). Konventionen aus `.github/copilot-instructions.md` und `.github/instructions/*.md` des Theme-Repositorys: keine Datenbankzugriffe in Templates, Inline-Stil nur für dynamische CSS-Variablen, Dark Mode über `body.dark-mode`, Breakpoints 1024/768/480, kein „KI-Look“ (Glassmorphism, Verläufe überall, Pillen-Radien).

#### 2.1 Anbindung an die Core-Daten (Voraussetzung für das Design)

- [ ] 🔴 **PH-01** `cms-phinit/member/dashboard.php` (688 Zeilen) auf `MemberController::getDashboardData()` umstellen. Heute liest es nur `show_welcome`, `dashboard_greeting`, `dashboard_welcome_text`, `dashboard_logo` (`:322-329`); Plugin-Widgets (auch das Newsletter-Widget aus NL-15), eigene Widgets, Profilfortschritt, Onboarding und Kontostatus fehlen.
- [ ] 🔴 **PH-02** Rohes SQL aus den Theme-Templates entfernen und durch Core-Services ersetzen: `dashboard.php:154-161` (Tabellenprüfung über `getPdo()`), `:172`, `:183`, `:193`, `:211-219`, `:228-236`; `analytics.php:143-155`; `comments.php:69`, `:99`; `favorites.php:52`, `:68`, `:111`; `newsletter.php:57-63`, `:139-142` (direktes `UPDATE`); `posts.php:46` (`INSERT INTO notifications`, nach MD-17 über `NotificationService`), `:106-245`. Fehlende Service-Methoden im Core ergänzen (z. B. Zähler für Kommentare und Beiträge eines Mitglieds).
- [ ] 🟠 **PH-03** Auth einheitlich über `member/includes/bootstrap.php`. dashboard, analytics, comments, favorites, feeds, forum und newsletter prüfen selbst per `Auth::isLoggedIn()` und leiten auf `theme_login_url()`; damit fehlen die `private`-Cache-Header des Bootstraps und die Controller-Tokens. Alle 16 Templates auf das Bootstrap-Muster umstellen.
- [ ] 🟠 **PH-04** Altpfad `cms-phinit/member/newsletter.php` (`:42-158`, Plugin-Klassen `CMS_Newsletter_Repository`, Rohes SQL) entfernen. In 4.0.0 ist das Plugin zwangsdeaktiviert, der Zweig ist toter Code; die Datei leitet bereits auf `/member/plugin/newsletter` (`:27-31`). Ebenso `feeds.php` mit `CMS_Feed_Database`/`CMS_Feed_Email_Digest` prüfen (Weiterleitung `:25-29`).
- [ ] 🟠 **PH-05** Fest eingebaute M365-Karte (`dashboard.php:284-292`, `:527-535`, Zugriff auf `CMS_M365LIC_Repository`) als Plugin-Widget über `member_dashboard_init` registrieren lassen, nicht im Theme.
- [ ] 🟡 **PH-06** Navigation: `member/partials/member-nav.php:116-131` pflegt 14 feste Einträge mit Emoji neben `MemberController::getMenuItems()`. Auf die Core-Menüliste umstellen und Theme-Zusätze über den Filter `member_menu_items` einhängen; Sichtbarkeit weiter über Customizer `show_sidebar_*`.
- [ ] 🟡 **PH-07** Texte übersetzbar machen: `phinit_t()`/`__()` wird in `member/` nicht verwendet (u. a. `member-nav.php:117-131`, `dashboard.php:484-503`, `:585-679`). DE/EN wie in `login.php:27-51`.
- [ ] 🟡 **PH-08** Versionshinweise in Kommentaren bereinigen („cms-newsletter 3.x“ `newsletter.php:5`, „Core ab 3.4.15“ `posts.php:133`, „3.5.0“ `includes/theme-template-helpers.php:182`); `requires_cms` in `theme.json` auf `4.0.0` anheben, sobald PH-01 Core-APIs aus 4.0.0 voraussetzt.

#### 2.2 Design-System und Layout

- [ ] 🟠 **PH-09** Member-Tokens in `cms-phinit/style.css` definieren und in `member-auth.css` verwenden: `--member-surface`, `--member-surface-raised`, `--member-border`, `--member-text`, `--member-text-muted`, `--member-accent` (aus `--accent-color`), `--member-success|warning|danger|info` je mit `-bg`/`-fg`, `--member-radius` (aus `--radius`/`--radius-lg`), `--member-gap`. `member-auth.css` definiert heute keine eigenen Variablen.
- [ ] 🟠 **PH-10** Farbliterale ersetzen: 120 Hex- (auf 91 Zeilen) und 96 rgb/rgba-Werte in `assets/css/member-auth.css`, u. a. Meldungen (`:1343-1348`, `:1993-2000`, Auth `:91-95`), Status-Badges (`:1325-1331`), Pflichtfeld-Stern `#ef4444`, Logout-Hover `#dc2626`. Ziel: 0 Literale außerhalb der Token-Definition.
- [ ] 🟠 **PH-11** Dark Mode über Tokens statt Einzelregeln: 104 `body.dark-mode`-Selektorzeilen (`:3-16`, `:94-144`, `:1833-1935`, `:2055-2073`) durch Umdefinition der Member-Tokens in einem `body.dark-mode`-Block ersetzen. Prüfen, dass `--bg-dark-3` für Karten genügend Kontrast zu `--text-white` hat (WCAG AA 4,5:1).
- [ ] 🟡 **PH-12** Theme-Regeln einhalten: Pillen-Radien (`999px`/`99px`, acht Stellen, u. a. `.member-hero-action` `:366-380`, `.member-badge-soft` `:46-56`), Verläufe (`linear-gradient` ×8, `radial-gradient` ×1, Hero `:236-248`) und `backdrop-filter` (×1) auf das flache Editorial-Design zurückführen.
- [ ] 🟡 **PH-13** Komponenten vereinheitlichen (BEM mit Präfix `member-`): Karte `.member-card` mit `__header`, `__body`, `__footer`; KPI `.member-kpi` mit `__value`, `__label`, `__icon`, Modifier je Typ (die Modifier `.member-overview-card--favorite|--comment|--notification|--post|--security` haben heute keine Regeln, `:437-475`); Badge `.member-badge--success|warning|danger|neutral`; `.member-stat-card` und `.member-analytics-mini-card` in `.member-kpi` überführen.
- [ ] 🟡 **PH-14** Buttons: `.btn-secondary` wird sechsmal verwendet, hat in `style.css:269-309` aber keine Regel; ergänzen oder durch `.btn-outline` ersetzen. Ungestylte Klassen `.auth-title`, `.auth-copy-block__text` (`forgot-password.php:118-149`) stylen oder entfernen.
- [ ] 🟡 **PH-15** Raster: `.member-container` (`260px 1fr`, `:154-161`) und `.member-dashboard-overview` (vier Spalten, `:430`) auf das Theme-Raster mit `--container-max` abstimmen; fehlenden 1024-px-Schritt ergänzen (heute nur 768, 640, 480 px); Sidebar unter 1024 px einklappbar mit `aria-expanded`.
- [ ] 🟡 **PH-16** `prefers-reduced-motion` für `data-anim`-Animationen und Hover-Effekte in `member-auth.css` ergänzen (in `style.css:449`, `:489` vorhanden, in `member-auth.css` nicht); `:focus-visible` für alle Member-Links und -Buttons (heute vier Stellen).
- [ ] 🟡 **PH-17** Inline-Stile: die vier `style=""`-Attribute (`dashboard.php:442`, `:445`, `:528`, `member-nav.php:176`) setzen nur CSS-Variablen und sind nach den Theme-Regeln zulässig. Feste Fallback-Farben in PHP (`dashboard.php:305-307`, `:345-355`, `:403-407`, `member-nav.php:56`) durch Token-Namen ersetzen, damit der Dark Mode greift; `hexToRgba`-Closure (`dashboard.php:114-125`) in einen Helfer verschieben.

#### 2.3 Newsletter-Widget und Plugin-Bereiche im Theme

- [ ] 🟠 **PH-18** Theme-Override `cms-phinit/member/widgets/newsletter.php` für NL-15 anlegen: `.member-card` mit Status-Badge, schreibgeschützter E-Mail, Einwilligungs-Checkbox, Primär- und Sekundäraktion. Keine eigene Logik, nur Darstellung der vom Core übergebenen Daten.
- [ ] 🟡 **PH-19** `.member-plugin-body` (`member-auth.css:1981-2077`) überschreibt Bootstrap-Klassen aus den Core-Sektionen. Nach NL-15 (Template statt echo-String) die Theme-Klassen direkt im Override verwenden und die Bootstrap-Nachbildung reduzieren.
- [ ] 🟡 **PH-20** Customizer-Gruppe `memberdashboard` (78 Einstellungen in `theme.json`, Tabs in `admin/customizer-schema.php:564`): Schalter „Newsletter-Widget anzeigen“ und Position in `overview_card_order` bzw. Dashboard-Blöcken ergänzen; Farbfelder (`hero_color_start/_end`, `card_icon_color`, `card_hover_color`, `quicklink_icon_color`, `sidebar_active_color`) auf `#rrggbb` prüfen und als Token-Overrides ausgeben.

#### 2.4 Kompatibilität mit 4.0.0

- [ ] 🟠 **PH-21** Hooks in den Theme-Templates prüfen: `member_head`, `member_body_end`, `member_plugin_section_head` und die generischen `head`, `body_start`, `body_end` müssen in allen 16 Templates aufgerufen werden, damit Plugin- und Core-Assets laden (Core: `member/partials/header.php:106`, `footer.php:46`).
- [ ] 🟠 **PH-22** Theme-Override `cms-phinit/member/plugin-section.php` nach MD-05 anpassen (POST vor Ausgabe, Flash).
- [ ] 🟡 **PH-23** Regressionstests unter `cms-phinit/tests/` (`template-regressions.php:385-407`, `audit-regressions.php:630-668`) um Dashboard mit Core-Daten, Newsletter-Widget und Dark Mode erweitern; Lighthouse-Workflow `.github/workflows/cms-phinit-lighthouse.yml` um `/member/dashboard` ergänzen (Budget aus `DOC/PERFORMANCE-BUDGETS.md`).

---

### 3. Login-Seite: konfigurierbarer Bild-und-Text-Bereich (Showcase)

**Ausgangslage.** Im Standardmodus `auth_slug_mode = cms` rendert `/cms-login` die eigenständige Core-View `views/auth/cms-auth.php` (`CmsAuthPageService::render()`, `core/Services/CmsAuthPageService.php:242-316`). Diese View lädt kein Theme-CSS, alle Stile stehen in einem Nonce-`<style>`-Block (`:77-355`) mit Variablen `--cms-auth-*`. Die Theme-Vorlagen `cms-default/login.php` und `cms-phinit/login.php` greifen nur im Modus `legacy` (`core/Routing/PublicRouter.php:767-780`, `:1018-1023`). Der Showcase gehört daher zuerst in die Core-View; die Theme-Vorlagen erhalten dieselben Daten.

#### 3.1 Einstellungen und Admin-Oberfläche

- [ ] 🟠 **LS-01** Neue Schlüssel in `CmsAuthPageService::getDefaultSettings()` (`:575-631`) aufnehmen, gespeichert als `cms_loginpage_<key>` in `{prefix}settings` (keine Schemaänderung):

  | Schlüssel | Typ / Standard | Prüfung beim Speichern |
  |---|---|---|
  | `showcase_enabled` | `'0'` | Boolean `'1'`/`'0'` |
  | `showcase_pages` | `login` | Enum `login`, `login_register` |
  | `showcase_image_url` | `''` | `sanitizeUrl()` (`:820-857`), zusätzlich nur lokale Pfade unter `/uploads/` und erlaubte Endungen (jpg, jpeg, png, webp, avif) |
  | `showcase_image_alt` | `''` | `sanitizeText()`, max. 160 Zeichen; Pflicht, wenn ein Bild gesetzt ist |
  | `showcase_image_position` | `left` | Enum `left`, `right` |
  | `showcase_eyebrow` | `''` | `sanitizeText()`, max. 60 |
  | `showcase_headline` | `''` | `sanitizeText()`, max. 120 |
  | `showcase_text` | `''` | `sanitizeMultilineText()` (`:807`), max. 600; Ausgabe als Absätze, kein HTML |
  | `showcase_features` | `''` | `sanitizeMultilineText()`, eine Zeile je Punkt, max. 8 Punkte à 140 Zeichen |
  | `showcase_cta_label` | `''` | `sanitizeText()`, max. 60 |
  | `showcase_cta_url` | `''` | `sanitizeUrl()` |

- [ ] 🟠 **LS-02** `normalizeSettingsInput()` (`:636-703`) um alle Schlüssel erweitern. Wichtig: `saveSettings()` schreibt bei jedem Speichern alle Schlüssel neu (`:203-225`), fehlende POST-Felder werden zu `''`/`'0'`. Die Felder müssen deshalb im einzigen Formular der Seite stehen.
- [ ] 🟠 **LS-03** Neuer Tab „Showcase“ in `admin/views/themes/cms-loginpage.php` (Tabs `:74-81`, Panels `.cms-loginpage-tab-panel`): Schalter, Seitenauswahl, Bildauswahl, Alternativtext, Bildposition, Eyebrow, Überschrift, Text, Feature-Liste (Textarea mit Zeilenzähler), CTA. Escaping wie in der View mit `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`.
- [ ] 🟠 **LS-04** Bildauswahl über den vorhandenen Medien-Picker (`assets/js/admin-media-integrations.js`, `initMediaPickers()` `:347-579`):
  - Markup wie `admin/views/settings/general.php:143-182` (`data-open-media-picker`, `data-clear-media-input`, `data-media-preview`);
  - Modal `#settingsMediaPickerModal` mit `data-api-url="/api/media"` und Token `Security::instance()->generateToken('editorjs_media')` (Vorlage `general.php:641-668`, `admin/settings.php:207`);
  - Skript über `page_assets['js']` in `admin/cms-loginpage.php:18-33` laden;
  - neue Vorschau-Variante `screenshot` (heute nur `logo` mit `max-height:48px`, `admin-media-integrations.js:29-66`).
  Hochladen im Picker ist nicht Teil von Option A; der Hinweis „Bild zuerst in der Mediathek hochladen“ genügt. Alternative mit Upload: `admin/views/partials/featured-image-picker.php` (stärker an Beiträge gebunden).
- [ ] 🟡 **LS-05** Bei gespeichertem Bild Breite und Höhe ermitteln (`ImageService::getDimensions()`, `core/Services/ImageService.php:469`) und als `showcase_image_width`/`showcase_image_height` mitspeichern, damit das Frontend `width`/`height` ohne Dateizugriff ausgibt.
- [ ] 🟡 **LS-06** Vorschau-Link für den Showcase in den vorhandenen `preview_urls` (`admin/modules/themes/CmsLoginPageModule.php:19-34`) berücksichtigen.
- [ ] 🟡 **LS-07** Zugriff bleibt bei `manage_settings` (`admin/cms-loginpage.php:10-16`, Menüeintrag `core/Services/AdminMenu/CoreCatalog.php:190`); CSRF über die Shell (`admin/partials/section-page-shell.php:384-392`, Aktion `admin_cms_loginpage`). Änderungen im Audit-Log festhalten.

#### 3.2 Darstellung unter dem Login-Bereich (Core-View)

- [ ] 🟠 **LS-08** `CmsAuthPageService::render()` übergibt `$showcase` (normalisiertes Array: `enabled`, `image`, `alt`, `width`, `height`, `position`, `eyebrow`, `headline`, `paragraphs[]`, `features[]`, `cta`) an die View; Ausgabe nur, wenn `showcase_enabled = '1'`, die Seite passt (`showcase_pages`) und mindestens Überschrift oder ein Feature gesetzt ist.
- [ ] 🟠 **LS-09** Markup in `views/auth/cms-auth.php` nach `</main>` (`:669`) als eigener Abschnitt, damit es unter dem Login-Bereich steht und mit `centered` und `split` funktioniert:
  - `<section class="cms-auth-showcase cms-auth-showcase--image-left|right" aria-labelledby="cms-auth-showcase-title">`;
  - Bild als `<figure>` mit `<img src alt width height loading="lazy" decoding="async">`; bei leerem Alt-Text und gesetztem Bild kein Rendern des Bildes (Admin-Pflichtfeld LS-01);
  - Text: Eyebrow, `<h2 id="cms-auth-showcase-title">`, Absätze, Feature-Liste als `<ul>` mit dekorativen Häkchen (`aria-hidden="true"`), optional CTA-Link;
  - alle Werte mit `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`, URLs zusätzlich über `esc_url()` (`includes/functions/escaping.php:18`).
- [ ] 🟠 **LS-10** Layout im vorhandenen Nonce-`<style>`-Block: Mobile-First einspaltig (Text vor Bild, damit die Features ohne Scrollen über das Bild erreichbar sind), ab 768 px zweispaltig per Grid (`grid-template-columns: minmax(0,1fr) minmax(0,1fr)`), Bildseite über Modifier, maximale Breite an `--cms-auth-card-width` gekoppelt bzw. eigene `--cms-auth-showcase-width` (Standard 1040 px). Farben ausschließlich aus den vorhandenen `--cms-auth-*`-Variablen (`:78-90`).
- [ ] 🟠 **LS-11** CSP: `img-src` erlaubt nur `'self' data: blob:` und den Seiten-Origin (`core/Security.php:116`). Externe Bild-URLs deshalb beim Speichern ablehnen (LS-01), nicht über `Security::allowCspSources()` freischalten.
- [ ] 🟡 **LS-12** Performance: Bild unterhalb des sichtbaren Bereichs immer `loading="lazy"`; WebP-Variante nutzen, wenn vorhanden (Muster `meridian_get_picture_sources()` aus `themes/cms-default/functions.php:1124` als Core-Helfer verallgemeinern oder `<picture>` nur bei vorhandener `.webp`-Datei).
- [ ] 🟡 **LS-13** Bestehende Mängel der View im selben Zug beheben: Hintergrundverlauf `--cms-auth-bg-start/-end` wird definiert, aber nicht angewendet (`:79-80`, `body` `:93-102`); `substr($brandName, 0, 2)` nicht multibyte-sicher (`:366`, `:390`, `mb_substr`); `<aside class="cms-auth-panel" aria-hidden="true">` enthält Links (`:359-382`), `aria-hidden` entfernen oder Links herausnehmen; Passkey-Fehler per `window.alert` (`:536`) durch Inline-Meldung ersetzen.
- [ ] 🟡 **LS-14** Override für Themes: optionales Partial `<theme>/auth/showcase.php`, das mit `$showcase` gerendert wird, falls vorhanden; sonst Core-Markup. Damit kann `cms-phinit` den Showcase gestalten, obwohl die Login-View kein Theme-CSS lädt (Theme liefert Markup und eigene Variablenwerte im Partial).

#### 3.3 Theme-Varianten

- [ ] 🟡 **LS-15** `cms-default/login.php` (Legacy-Modus): `redirectLegacyLogin()` (`core/Routing/PublicRouter.php:767-780`) übergibt `$showcase` zusätzlich an `ThemeManager::render('login', …)`; Ausgabe nach `.auth-card` (`themes/cms-default/login.php:154`) mit derselben Struktur, Stile in `themes/cms-default/style.css`.
- [ ] 🟡 **LS-16** `cms-phinit/login.php` (Legacy-Modus): `.auth-wrapper` ist ein zentrierendes Flex-Element in Zeilenrichtung (`member-auth.css:61-66`), ein Geschwisterelement nach `.auth-card` stünde daneben. Showcase daher nach `.auth-wrapper` ausgeben oder den Wrapper auf `flex-direction: column` umstellen. Gestaltung siehe PH-24.

---

### 4. Theme `cms-phinit`: Login-Showcase-Design

- [ ] 🟡 **PH-24** Showcase im Theme-Stil: Partial `cms-phinit/auth/showcase.php` (LS-14) und Regeln in `member-auth.css` mit Theme-Tokens (`--primary-color`, `--accent-color`, `--bg-secondary`, `--radius-lg`, `--font-brand` für die Überschrift); Screenshot mit `--shadow-sm` und Rahmen `--border-color`, ohne Verlauf und Glas-Effekt; Dark Mode über `body.dark-mode`-Tokens.
- [ ] 🟡 **PH-25** Für den Standardmodus `cms` prüfen, ob die Login-View die Farbwerte des Themes übernehmen soll (z. B. Voreinstellung der `cms_loginpage_*`-Farben aus `theme.json` beim Theme-Wechsel). Entscheidung dokumentieren; ohne diese Übernahme sieht die Login-Seite im Modus `cms` nicht nach `cms-phinit` aus.
- [ ] ⚪ **PH-26** Login-Vorlage `cms-phinit/login.php` hat keine Passkey-Oberfläche (nur den Fehlertext `login_error_passkey`). Für den Legacy-Modus Passkey-Schaltfläche analog `views/auth/cms-auth.php:446-535` ergänzen oder im Admin darauf hinweisen, dass Passkeys nur im Modus `cms` angeboten werden.

---

### 5. Umsetzungs-Roadmap

| Paket | Inhalt | Aufgaben | Abhängigkeit | Repository |
|---|---|---|---|---|
| **1. Core-Basis und Newsletter-Backend** | Sicherheit und Ablauf im Mitgliederbereich, Reparatur Favoriten/Benachrichtigungen, Newsletter-Service für Mitglieder | MD-01 … MD-18, MD-27, NL-01 … NL-14 | – | `365CMS.DE` |
| **2. Standard-Dashboard-UI** | Widget-Schnittstelle mit Template, Newsletter-Widget, Admin-Einstellungen wirksam machen, Barrierefreiheit, Responsive | MD-19 … MD-26, NL-15 … NL-19, UI-01 … UI-18 | Paket 1 (MD-04, MD-05, NL-03) | `365CMS.DE` |
| **3. Login-Showcase Admin und Frontend** | Einstellungen, Admin-Tab mit Medien-Picker, Ausgabe in der Core-View, Legacy-Vorlagen | LS-01 … LS-16 | unabhängig von 1 und 2, kann parallel laufen | `365CMS.DE` |
| **4. `cms-phinit` Theme-Design** | Anbindung an Core-Daten, Token-System, Dark Mode, Newsletter-Widget- und Showcase-Override | PH-01 … PH-26 | Pakete 1–3 (Core-APIs, Widget-Template, Showcase-Partial) | `365CMS.DE-PhinIT_THEME` |
| **5. QA und Tests** | Tests, Barrierefreiheits- und Lighthouse-Prüfung, Doku | QA-01 … QA-10 | begleitend, Abschluss nach Paket 4 | beide |

Empfohlene Reihenfolge der Pull Requests im Core: (1a) MD-04, MD-05, MD-06 → (1b) MD-16, MD-17 → (1c) NL-01 … NL-14 → (2a) NL-15 … NL-19 → (2b) UI → (3) LS. Paket 3 kann jederzeit nach (1a) eingeschoben werden. Im Theme je Paket ein PR mit Versionssprung.

#### 5.1 QA und Tests

- [ ] 🟠 **QA-01** Testverzeichnis klären: `TESTS/` wurde mit Commit `6edfacc` gelöscht, `.gitignore:100-102` erwartet es versioniert. Entscheiden, ob die Suite wiederhergestellt wird (`git show 6edfacc^:TESTS/…`); neue Tests andernfalls in den gewählten Ersatzort legen.
- [ ] 🟠 **QA-02** Tests Mitgliederbereich: CSRF bei vielen Medien-Einträgen (MD-04), PRG in Plugin-Bereichen (MD-05), Re-Auth beim Löschantrag (MD-10), Favoriten mit Titel/Link (MD-16), Benachrichtigung anlegen/lesen (MD-17).
- [ ] 🟠 **QA-03** Tests Newsletter-Widget: Anmeldung mit/ohne Einwilligung, Double-Opt-In bei unbestätigter Konto-E-Mail, `user_id`-Verknüpfung nur bei bestätigter Adresse, Namen aus `user_meta`, Abmelden, erneutes Senden (10-Minuten-Sperre), Rate-Limit, Escaping aller Ausgaben, gleiches Verhalten über Widget und Unterseite.
- [ ] 🟠 **QA-04** Tests Login-Showcase: Speichern und Normalisieren aller Schlüssel, Ablehnung externer Bild-URLs und `javascript:`-Links, Escaping von Überschrift/Text/Features (XSS-Nutzlasten wie `<script>`, `"><img onerror>`), keine Ausgabe bei abgeschaltetem Schalter, `loading="lazy"`, `width`/`height`, CSP ohne Verstoß in der Browser-Konsole.
- [ ] 🟡 **QA-05** Barrierefreiheit: Tastaturdurchlauf Dashboard, Sidebar, Widget-Formulare, Login mit Showcase; axe-Prüfung ohne kritische Befunde; Kontrast in Hell und Dunkel (WCAG 2.2 AA).
- [ ] 🟡 **QA-06** Responsive-Prüfung bei 360, 768, 1024 und 1440 px für Core-Dashboard, `cms-phinit`-Dashboard und Login mit Showcase (beide Bildpositionen, `centered` und `split`).
- [ ] 🟡 **QA-07** Lighthouse: `/member/dashboard` und `/cms-login` mit Showcase; Budgets aus `cms-phinit/DOC/PERFORMANCE-BUDGETS.md` (LCP ≤ 3,0 s, CLS ≤ 0,10, TBT ≤ 200 ms).
- [ ] 🟡 **QA-08** Doku aktualisieren: `DOC/member/MEMBER-DASHBOARD.md` (noch 3.5.0), `DOC/member/MEMBER-ROUTES.md`, `DOC/admin/themes-design/CMS-LOGINPAGE.md` (Einstellungstabelle um `showcase_*`), `DOC/admin/pages-posts/NEWSLETTER.md` (Mitglieder-Widget), `DOC/core/HOOKS-REFERENCE.md` (Widget-`render_callback`, Showcase-Partial), Theme-Doku `cms-phinit/DOC/TEMPLATES.md`.
- [ ] 🟡 **QA-09** Theme-Tests: `cms-phinit/tests/theme-contracts.php` (Versionen), `template-regressions.php` (Dashboard mit Core-Daten, Widget-Override), `css-regressions.php` (keine Farbliterale außerhalb der Tokens), `dark-mode.test.js`.
- [ ] 🟡 **QA-10** Syntax- und Stilprüfung je PR: `php -l` über geänderte Dateien, PHPStan auf dem bisherigen Level, `node --check` für geänderte Skripte.

---

### Offene Entscheidungen

| Nr. | Frage | Empfehlung |
|---|---|---|
| E-1 | Einwilligungsverlauf für den Newsletter als eigene Tabelle oder im Audit-Log (NL-10)? | Audit-Log, keine Schemaänderung in 4.0.0 |
| E-2 | Wirkungslose Dashboard-Einstellungen umsetzen oder entfernen (MD-19)? | Reihenfolge und Spalten umsetzen, E-Mail-Optionen nach MD-17 |
| E-3 | Login-Farben beim Theme-Wechsel aus dem Theme übernehmen (PH-25)? | Optionaler Import-Button im Admin statt automatischer Übernahme |
| E-4 | Speicherort der Tests nach dem Löschen von `TESTS/` (QA-01)? | Suite wiederherstellen und erweitern |
| E-5 | Bild-Upload direkt im Showcase-Tab oder nur Auswahl aus der Mediathek (LS-04)? | Auswahl aus der Mediathek (vorhandener Picker) |
