> **Website:** [365CMS.DE](https://365cms.de/) | **Version:** 4.0.0
> **Datum:** 2026-09-06 | **Status:** Stable – **Zuletzt aktualisiert am:** 2026-11-14 (Umsetzung TODO-MEMBERDASHBOARD-V4, Pakete 1 und 2: wirksame Layout-Einstellungen, NotificationService, Favoriten, Sprachdateien, Barrierefreiheit)
> **Kurzbeschreibung:** Referenz für Dashboard-Runtime, Bereiche, Widgets, Reihenfolge, Plugin-Integration, Benachrichtigungen, Favoriten und Sprachtexte des Core-Mitgliederbereichs in Version 4.0.0.

# 365CMS Member Dashboard

## English — user guide

The dashboard shows a welcome block, quick access, key figures, custom information widgets, plugin widgets, onboarding and the core widgets profile progress, security status, account status, notifications, favorites, messages and recent activity. Administrators control which core widgets appear (`widgets`), the order of the four main sections (`section_order`) and the number of widget columns (`dashboard_columns`). Members only see enabled items allowed for their account.

If `dashboard_enabled` is false, `/member` and `/member/dashboard` redirect directly to the profile page (one redirect). Texts are read from `lang/de.yaml` and `lang/en.yaml` (`member.*` keys) in the language of the request.

## English — technical reference

| Setting | Runtime meaning (4.0.0) |
|---|---|
| `dashboard_enabled` | Enables the dashboard; disabled state redirects to `/member/profile` (`MemberController::getHomePath()`) |
| `dashboard_greeting`, `dashboard_welcome_text`, `show_welcome` | Greeting (`{name}`), welcome HTML (output through `PurifierService` profile `strict`), visibility |
| `section_order` | Order of `quick_start`, `stats`, `widgets` (custom info widgets) and `plugins`; missing sections are added (`quick_start` first) |
| `dashboard_columns` | 1–4 columns for info and plugin widgets (`MemberController::widgetColumnClass()`); mobile always one column |
| `widgets` | Allowlist of core widgets `profile`, `activity`, `messages`, `bookmarks`, `notifications`, `quick_links`, `statistics`; never saved = all visible |
| `frontend_modules` | Additional switches `show_quickstart`, `show_stats`, `show_custom_widgets`, `show_plugin_widgets`, `show_onboarding_panel`, `show_notifications_panel` |
| `onboarding.require_profile_completion` | While a required profile field is empty: notice on every member page, `/member/messages` and `/member/media` redirect to the profile |
| `notifications.center_enabled`, `types`, `empty_text` | In-app notification centre and type filter; `email_enabled`, `digest_frequency`, `sender_name` are stored but no e-mail is sent yet |
| `dashboard_footer_text` | Optional plain footer text (max. 200 characters) |

Plugin widgets come from `PluginDashboardRegistry::getDashboardWidgets()`. `MemberController::sanitizeDashboardWidget()` passes `html` (already escaped markup from a trusted render callback) unchanged and reduces `anchor` to `[a-z0-9_-]`. With `html`, the dashboard renders `<div class="member-widget member-widget--custom">` plus the markup (which carries its own root id); otherwise a tile with badge, statistics, open link and `admin_link`.

---

# 365CMS Member-Dashboard

## Deutsch — Anwenderblock

Das Dashboard zeigt Begrüßung, Schnellzugriff, Kennzahlen, eigene Info-Widgets, Plugin-Widgets, Onboarding und die Kern-Widgets Profil-Fortschritt, Sicherheitsstatus (2FA, letzte Passwortänderung), Kontostatus, Benachrichtigungen, Favoriten, Nachrichten und letzte Aktivität. Im Admin unter *Mitgliederbereich → Dashboard-Widgets* legen Administratoren fest, welche Kern-Widgets erscheinen, in welcher Reihenfolge die vier Hauptbereiche stehen und wie viele Spalten die Widget-Raster haben.

Ist das Dashboard abgeschaltet, führen `/member` und `/member/dashboard` mit einer einzigen Weiterleitung zum Profil. Die Oberflächentexte stehen in `lang/de.yaml` und `lang/en.yaml` (Schlüssel `member.*`) und erscheinen in der Sprache der Anfrage (`/en/member/…` → Englisch).

## Deutsch — Technikblock

### Einstellungen und ihre Wirkung

| Einstellung | Wirkung im Frontend (4.0.0) |
|---|---|
| `dashboard_enabled` | Dashboard an/aus; aus = Weiterleitung auf `/member/profile` (`MemberController::getHomePath()`) |
| `dashboard_greeting`, `dashboard_welcome_text`, `show_welcome` | Begrüßung mit `{name}`, Begrüßungstext als bereinigtes HTML (`PurifierService`, Profil `strict`), Sichtbarkeit |
| `section_order` | Reihenfolge von `quick_start` (Schnellzugriff), `stats` (Kennzahlen), `widgets` (Info-Widgets), `plugins` (Plugin-Widgets); fehlende Bereiche werden ergänzt, `quick_start` vorne |
| `dashboard_columns` | 1–4 Spalten für Info- und Plugin-Widgets; unter 768 px einspaltig |
| `widgets` | Allowlist der Kern-Widgets `profile`, `activity`, `messages`, `bookmarks`, `notifications`, `quick_links`, `statistics`; nie gespeichert = alle sichtbar; die gespeicherte Reihenfolge gilt innerhalb der Haupt- und der Seitenspalte |
| `frontend_modules` | Zusätzliche Schalter je Bereich (beide Bedingungen müssen erfüllt sein) |
| `onboarding.require_profile_completion` | Solange ein Pflichtfeld leer ist: Hinweis auf allen Member-Seiten, `/member/messages` und `/member/media` leiten mit Meldung auf das Profil um (`MemberController::PROFILE_GATED_PAGES`) |
| `notifications.center_enabled`, `types`, `empty_text` | Benachrichtigungszentrale und Typfilter (`messages`, `community` = Kommentare/Termine, `system`, `billing`, `security`) |
| `notifications.email_enabled`, `digest_frequency`, `sender_name` | werden gespeichert; ein E-Mail-Versand ist in 4.0.0 noch nicht angebunden (Hinweis im Admin) |
| `dashboard_footer_text` | optionaler Fußzeilentext (reiner Text, max. 200 Zeichen) |
| `design` | Farben als CSS-Variablen `--member-*` im Nonce-`<style>`-Block |

### Daten und Services

`MemberController::getDashboardData()` liefert zusätzlich `layout` (`section_order`, `columns`, `widgets`), `favorites`, `content_stats`, `unread_notifications`, `profile_completion` (mit `missing_labels`, `missing_required`) und `profile_completion_required`. Benachrichtigungen werden nur noch einmal geladen (gefiltert nach den Admin-Typen); `permissions` lädt `MemberService::getUserPermissions()` bei Bedarf.

| Service | Öffentliche Methoden |
|---|---|
| `CMS\Services\NotificationService` | `create(int $userId, string $type, string $title, string $message = '', string $url = ''): int`, `markRead(int $userId, int $id): bool`, `markAllRead(int $userId): int`, `countUnread(int $userId): int`, `getRecent(int $userId, int $limit = 20, bool $unreadOnly = false): array`, `isTypeEnabledForUser(int $userId, string $type): bool` |
| `CMS\Services\MemberContentService` | `getContentStats(int $userId): array{favorites,comments,posts}`, `getRecentComments(int $userId, int $limit = 5): array`, `getFavoritePosts(int $userId, int $limit = 20): array`, `isFavorite()`, `toggleFavorite(int $userId, int $postId): bool`, `removeFavorite(int $userId, int $postId): bool` |
| `CMS\Services\MemberService` (neu/öffentlich) | `getRecentActivity()`, `getMemberOrders()`, `resolveMessageRecipientId()`, `setUserMeta()`, `flushUserMetaCache()`, `normalizeNotificationPreferences()`; `getUserMeta()` mit Request-Cache |
| `CMS\Member\MemberSanitizer` | gemeinsame Prüfungen für Farbe, Link, Asset, Text, Datum, Telefon, Anker, Benachrichtigungs-URL |
| `CMS\Member\MemberI18n` | `t($key, $params)`, `locale()`, `formatDate()`, `relative()` |

`NotificationService::create()` akzeptiert die Typen `system|message|comment|event`, bereinigt Titel/Text, lässt als URL nur lokale Pfade oder http(s) zu und legt nichts an, wenn das Mitglied den Typ abbestellt hat (`notify_messages`, `notify_comments`, `notify_events`; `system` immer). Nach dem Anlegen feuert die Action `cms_member_notification_created` (`$id, $userId, $type`). Auf `/member/notifications` markieren die Aktionen `notification_mark_read` und `notifications_mark_all_read` (CSRF `member_notifications_read`, PRG) Einträge als gelesen.

Favoriten: Die Tabelle `favorites` enthält nur `user_id`/`post_id`; Titel und Permalink kommen per Join aus `posts` (nur veröffentlicht, nicht im Papierkorb) über `PermalinkService::buildPostPath()`. `POST /member/favorites` mit `action=favorite_toggle|favorite_remove`, `post_id`, `csrf_token` (`MemberController::csrfToken('favorites_action')`) und optional `return_to` (nur lokaler Pfad) merkt oder entfernt einen Beitrag.

### Plugin-Widgets

`PluginDashboardRegistry::getDashboardWidgets()` filtert nach Berechtigung und `member_dashboard_plugin_{plugin}`. `MemberController::sanitizeDashboardWidget()` übernimmt `html` (bereits escaptes Markup eines vertrauenswürdigen `render_callback`) unverändert und reduziert `anchor` auf `[a-z0-9_-]`. Mit `html` rendert das Dashboard einen Container `member-widget member-widget--custom` ohne eigenes `id` (das Markup bringt seine Wurzel-ID mit, z. B. `id="newsletter"`); ohne `html` die Kachel mit `badge`, Statistik, Öffnen-Link und – für Administratoren – `admin_link`. Die Dashboard-Daten werden vor `partials/header.php` geladen, damit Widget-Callbacks ihre Flash-Meldung selbst verbrauchen können.

### Menü

`MemberController::getMenuItems()` gruppiert nach `category` (`core`, `content`, `account`, `plugins`, `admin`), sortiert innerhalb der Gruppe nach `priority` (bei Plugin-Bereichen aus der Registry) und hängt Einträge mit `parent_slug` als `children` an den Elterneintrag. Der Admin-Link erscheint nur noch in der Sidebar.

---

## Plugin-Bereiche und -Widgets registrieren

Plugins tragen sich im Hook `member_dashboard_init` in die `CMS\Member\PluginDashboardRegistry` ein. Pflichtfelder: `slug`, `label`, `render_callback`.

```php
\CMS\Hooks::addAction('member_dashboard_init', static function (\CMS\Member\PluginDashboardRegistry $registry): void {
    $registry->register([
        'plugin'     => 'mein-plugin',
        'slug'       => 'meine-buchungen',
        'label'      => 'Meine Buchungen',
        'icon'       => '📅',
        'category'   => 'plugins',
        'priority'   => 20,                 // kleiner = weiter oben (Sidebar und Gruppe)
        'capability' => null,               // null = alle Mitglieder, 'admin' = nur Administratoren
        'dashboard_widget' => [
            'title'          => 'Buchungen',
            'description'    => 'Offene und kommende Termine',
            'color'          => '#0ea5e9', // #rrggbb
            'badge'          => 'Neu',
            'admin_url'      => '/admin/mein-plugin',
            'stats_callback' => [MeinPlugin\Member::class, 'stats'], // ['count' => int, 'label' => string]
        ],
        'render_callback' => static function (object $user, array $params): void {
            // Ausgabe innerhalb des Member-Layouts
        },
        'csrf' => 'core',
    ]);
});
```

- Unterbereiche über `parent_slug` erscheinen als Unterpunkt in der Sidebar, nicht als eigene Dashboard-Kachel.
- `dashboard_widget => false` blendet die Kachel aus; Sichtbarkeit und Reihenfolge zusätzlich im Admin unter *Mitgliederbereich → Plugin-Widgets*.
- Formulare in Plugin-Bereichen nutzen für Rückmeldungen `MemberController::instance()->flash($typ, $text)`; die Meldung erscheint nach der Weiterleitung.

### Filter und Actions

| Hook | Zweck |
|---|---|
| `member_dashboard_widgets` (Filter) | endgültige Widget-Liste (`$widgets, $user, $settings`) |
| `member_menu_items` (Filter) | Menüeinträge ergänzen/ändern (`slug`, `label`, `icon`, `url`, `category`, `priority`, `parent_slug`) |
| `cms_member_notification_created` (Action) | nach `NotificationService::create()` (`$id, $userId, $type`) |

Admin-Konfiguration aller Dashboard-Optionen: [../admin/member/README.md](../admin/member/README.md).
