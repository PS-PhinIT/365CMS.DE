> **Website:** [365CMS.DE](https://365cms.de/) | **Version:** 3.5.0
> **Datum:** 2026-09-06 | **Status:** Stable – **Zuletzt aktualisiert am:** 2026-10-02 (Abgleich mit `MemberRouter`, `MemberController`, `PluginDashboardRegistry`)
> **Kurzbeschreibung:** Vollständige Referenz des privaten Member-Bereichs mit Seiten, Routing, Einstellungen, Erweiterungspunkten, Sicherheit und Fallbacks. Die Angaben entsprechen dem Code-Stand von Version 3.5.0.

# 365CMS Member Area

## English — user guide

The member area is the private, authenticated workspace for profile data, security, notifications, messages, media, favourites, privacy, subscriptions, and plugin features. Open `/member/dashboard` after signing in; `/member` is an alias that redirects to the dashboard. If the dashboard is disabled, the runtime redirects the member to `/member/profile`.

### Runtime pages

| Page | URL | What the member can do |
|---|---|---|
| Dashboard | `/member/dashboard` | View greeting, quick links, statistics, widgets, onboarding, notifications, profile completion, and plugin tiles |
| Profile | `/member/profile` | Edit account data and configured custom fields |
| Security | `/member/security` | Change password; configure TOTP/MFA, backup codes, passkeys, and sessions |
| Notifications | `/member/notifications` | Read the notification centre and save preferences |
| Messages | `/member/messages` | Read inbox/sent mail, open threads, and send messages |
| Media | `/member/media` | Browse the personal file tree, upload, rename, move, and delete where enabled |
| Favourites | `/member/favorites` | Review saved content |
| Privacy | `/member/privacy` | Manage privacy choices and submit data requests |
| Subscription | `/member/subscription` | View package, limits, renewal, and orders when the module is enabled |
| Plugin area | `/member/plugin/{slug}[/{action}[/{id}]]` | Use a registered plugin section |

All pages require a logged-in session. A failed authentication check redirects to the configured public login page and preserves the original request in `redirect`.

## English — technical reference

The runtime entry point is `CMS/member/includes/bootstrap.php`. It loads `CMS\MemberArea\MemberController`, calls `requireAuth()`, sends private cache headers, and exposes normalized settings to the page. Each page includes the shared header, sidebar, alerts, and footer; the footer fires `body_end` and `member_body_end`.

`CMS/core/Routing/MemberRouter.php` registers GET and POST routes for `/dashboard`, `/member`, `/member/:page`, and the three plugin route shapes. `/dashboard` requires a theme file at `member/dashboard.php`; otherwise it redirects to `/member/dashboard`. A page slug must match `[a-zA-Z0-9_-]+`. The resolver prefers the active theme's `member/{page}.php`, then falls back to `CMS/member/{page}.php`, and finally renders 404 while logging the miss.

The controller obtains settings from `MemberDashboardModule::getRuntimeSettings()` and combines them with safe defaults. It loads plugin widgets through `PluginDashboardRegistry`, applies `member_dashboard_widgets`, and adds registered menu entries through `member_menu_items`. Optional database tables, unknown widgets, missing plugin sections, failed statistics callbacks, and invalid settings fail soft rather than breaking the whole area.

Member data is scoped to the authenticated user. Media is rooted at `member/user-{id}`; normalized paths reject traversal. The subscription page is shown only when `subscription_visible` is enabled and `subscription_member_area` is active. The admin portal link is displayed only when `canAccessAdminPortal()` succeeds.

See [MEMBER-ROUTES.md](MEMBER-ROUTES.md), [MEMBER-DASHBOARD.md](MEMBER-DASHBOARD.md), and [MEMBER-SECURITY.md](MEMBER-SECURITY.md) for contracts and extension details.

---

# 365CMS Mitgliederbereich

## Deutsch — Anwenderblock

Der Member-Bereich ist der private, authentifizierte Arbeitsbereich für Profil, Sicherheit, Benachrichtigungen, Nachrichten, Medien, Favoriten, Datenschutz, Abonnements und Plugin-Funktionen. Nach der Anmeldung öffnet `/member/dashboard` das Dashboard; `/member` ist ein Alias und leitet dorthin weiter. Ist das Dashboard deaktiviert, leitet die Runtime auf `/member/profile` um.

### Runtime-Seiten

| Seite | URL | Zweck |
|---|---|---|
| Dashboard | `/member/dashboard` | Begrüßung, Schnelllinks, Statistiken, Widgets, Onboarding, Meldungen, Profilfortschritt und Plugin-Kacheln |
| Profil | `/member/profile` | Kontodaten und konfigurierte eigene Felder bearbeiten |
| Sicherheit | `/member/security` | Passwort, TOTP/MFA, Backup-Codes, Passkeys und Sitzungen verwalten |
| Benachrichtigungen | `/member/notifications` | Benachrichtigungen und Einstellungen |
| Nachrichten | `/member/messages` | Posteingang, Gesendet, Threads und neue Nachrichten |
| Medien | `/member/media` | Eigene Dateien und Ordner durchsuchen, hochladen, umbenennen, verschieben und löschen, sofern erlaubt |
| Favoriten | `/member/favorites` | Gespeicherte Inhalte prüfen |
| Datenschutz | `/member/privacy` | Datenschutzoptionen und Datenanfragen |
| Abonnement | `/member/subscription` | Paket, Limits, Verlängerung und Bestellungen bei aktivem Modul |
| Plugin-Bereich | `/member/plugin/{slug}[/{action}[/{id}]]` | Registrierte Plugin-Funktionen nutzen |

Alle Seiten benötigen eine eingeloggte Sitzung. Bei fehlender Authentifizierung erfolgt eine Weiterleitung zur konfigurierten öffentlichen Login-Seite mit der ursprünglichen Anfrage im Parameter `redirect`.

## Deutsch — Technikblock

Der Runtime-Einstieg ist `CMS/member/includes/bootstrap.php`. Er lädt `CMS\MemberArea\MemberController`, ruft `requireAuth()` auf, setzt private Cache-Header und stellt normalisierte Einstellungen für die Seite bereit. Gemeinsamer Header, Sidebar, Meldungen und Footer werden von jeder Seite verwendet; der Footer feuert `body_end` und `member_body_end`.

`CMS/core/Routing/MemberRouter.php` registriert GET- und POST-Routen für `/dashboard`, `/member`, `/member/:page` sowie die drei Plugin-Routen. `/dashboard` erwartet `member/dashboard.php` im aktiven Theme; fehlt die Datei, erfolgt eine Weiterleitung nach `/member/dashboard`. Seiten-Slugs müssen `[a-zA-Z0-9_-]+` entsprechen. Der Resolver bevorzugt das Theme, fällt danach auf `CMS/member/{page}.php` zurück und rendert bei Fehlen eine 404-Seite mit Logeintrag.

Die Steuerung liest `MemberDashboardModule::getRuntimeSettings()` und ergänzt sichere Defaults. Plugin-Widgets kommen aus `PluginDashboardRegistry`, der Filter `member_dashboard_widgets` wird angewendet und `member_menu_items` erweitert das Menü. Optionale Tabellen, unbekannte Widgets, fehlende Plugin-Bereiche, fehlerhafte Statistik-Callbacks und ungültige Einstellungen werden fail-soft behandelt.

Alle Mitgliedsdaten sind auf den authentifizierten Benutzer begrenzt. Medien liegen unter `member/user-{id}`; normalisierte Pfade verhindern Traversal. Das Abonnement erscheint nur bei aktivem `subscription_visible` und aktiviertem Modul `subscription_member_area`. Ein Admin-Portal-Link erscheint nur bei erfolgreichem `canAccessAdminPortal()`.

Details stehen in [MEMBER-ROUTES.md](MEMBER-ROUTES.md), [MEMBER-DASHBOARD.md](MEMBER-DASHBOARD.md) und [MEMBER-SECURITY.md](MEMBER-SECURITY.md).

---

## Ergänzung (Stand 2026-10-02): Formularaktionen je Seite

Alle Formulare senden `POST` an die eigene Seite mit Feld `action` und `csrf_token` (Kontext `member_<aktion>`, siehe [MEMBER-SECURITY.md](MEMBER-SECURITY.md)). Nach der Verarbeitung leitet der Controller mit Flash-Meldung zurück (PRG).

| Seite | `action` | Wirkung |
|---|---|---|
| `/member/profile` | `profile_save` | Stammdaten und aktivierte Profilfelder (inkl. eigener Felder) speichern; Pflichtfelder laut Admin-Konfiguration |
| `/member/security` | `password_change` | Passwort ändern (aktuelles Passwort, Richtlinie: 12 Zeichen + Zeichenklassen), setzt `password_changed_at` |
| | `totp_start` / `totp_confirm` / `totp_disable` | Authenticator-App einrichten (QR-Code), mit Code bestätigen → Backup-Codes werden einmalig angezeigt; deaktivieren |
| | `backup_generate` | neue Backup-Codes erzeugen (alte werden ungültig) |
| | `passkey_register` / `passkey_delete` | Passkey (WebAuthn) hinzufügen bzw. entfernen |
| `/member/notifications` | `notifications_save` | E-Mail-Benachrichtigungen (Sicherheit, Updates, Marketing), Browser-/Desktop-/Mobil-Hinweise, Häufigkeit, neue Funktionen/Aktionen |
| `/member/messages` | `message_send` | Nachricht an Benutzer-ID oder Benutzername senden, Antwort über `parent_id` (Thread) |
| `/member/media` | `media_folder_create`, `media_folder_delete`, `media_delete`, `media_rename`, `media_move` | eigene Dateien unter `uploads/member/user-<id>/` verwalten; Löschen nur bei `member_delete_own` |
| | Upload | per JavaScript an `POST /api/upload` (nur wenn `member_uploads_enabled`), Größe/Typen laut Medien-Einstellungen (`member_max_upload_size`, `member_allowed_types`) |
| `/member/privacy` | `privacy_save` | Profil-Sichtbarkeit, öffentliche Felder, E-Mail anzeigen, Aktivitäten anzeigen |
| | `privacy_export` | sofortiger JSON-Download aller eigenen Daten (`member-export-<id>.json`) |
| | `privacy_delete_request` | Löschantrag: Status `pending_deletion`, Ausführung frühestens nach 30 Tagen durch Administratoren ([../admin/legal/DELETION-REQUESTS.md](../admin/legal/DELETION-REQUESTS.md)) |
| `/member/favorites` | – | Liste gemerkter Beiträge/Seiten (Merken über das Theme) |
| `/member/subscription` | – | aktuelles Paket, Limits/Nutzung, Verlängerungshinweise, eigene Bestellungen |

### Menü

Reihenfolge: Dashboard (falls aktiv) · Profil · Sicherheit · Benachrichtigungen · Nachrichten · Dateien · Favoriten · Datenschutz · Abo & Bestellungen (falls sichtbar) · Plugin-Bereiche · „Adminmenü“ (nur Administratoren). Plugins erweitern das Menü über `PluginDashboardRegistry` bzw. den Filter `member_menu_items`.

### Theme-Overrides

Ein Theme kann jede Seite ersetzen, indem es `CMS/themes/<theme>/member/<seite>.php` mitliefert (z. B. `member/dashboard.php`). Das mitgelieferte Theme `cms-default` nutzt die Core-Seiten unter `CMS/member/`.

### Konfiguration

Alle Einstellungen des Mitgliederbereichs werden im Admin unter *Mitglieder-Dashboard* gepflegt: [../admin/member/README.md](../admin/member/README.md). Registrierung und Anmeldeverfahren: [../admin/users-groups/AUTH-SETTINGS.md](../admin/users-groups/AUTH-SETTINGS.md).
