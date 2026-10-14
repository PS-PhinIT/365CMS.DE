> **Website:** [365CMS.DE](https://365cms.de/) | **Version:** 3.5.0
> **Datum:** 2026-09-06 | **Status:** Stable – **Zuletzt aktualisiert am:** 2026-10-02 (Abgleich mit `MemberRouter`, `MemberController`, `PluginDashboardRegistry`)
> **Kurzbeschreibung:** Referenz für Dashboard-Runtime, Bereiche, Widgets, Reihenfolge, Plugin-Integration und Normalisierung. Dokumentiert das Verhalten von Version 3.5.0 einschließlich der Admin-Vorschau.

# 365CMS Member Dashboard

## English — user guide

The dashboard can show a welcome block, quick start, statistics, core widgets, custom information widgets, plugin widgets, onboarding, notifications, profile completion, recent activity, and subscription information. Administrators control visibility and order; members only see enabled items allowed for their account.

If `dashboard_enabled` is false, the member dashboard does not become an error page: the runtime redirects to the profile page. A read-only administrator preview uses saved, normalized values and example data.

## English — technical reference

Runtime settings consumed by `MemberController` include:

| Setting | Runtime meaning |
|---|---|
| `dashboard_enabled` | Enables dashboard rendering; disabled state redirects to profile |
| `dashboard_greeting`, `dashboard_welcome_text`, `show_welcome` | Greeting, welcome copy, and visibility |
| `widgets`, `dashboard_columns`, `section_order` | Core widget allowlist, column count, and section order |
| `frontend_modules` | `show_quickstart`, `show_stats`, `show_custom_widgets`, `show_plugin_widgets`, `show_onboarding_panel`, and `show_notifications_panel` |
| `profile_fields`, `required_profile_fields`, `custom_profile_fields` | Profile form and completion contract |
| `custom_widgets`, `custom_widget_order` | Administrator-authored information cards |
| `plugin_widget_order` | Ordering of registry-provided plugin tiles |
| `notifications`, `onboarding` | Centre configuration, allowed types, steps, title, and intro |
| `subscription_visible` | Allows subscription navigation and dashboard data when the subscription module is enabled |
| `design` | Primary, accent, background, card background, text, logo, and related colors |

`PluginDashboardRegistry::getAll()` filters sections by capability and the database setting `member_dashboard_plugin_{plugin}`. A missing visibility option means visible. `getMenuItems()` supplies `/member/plugin/{slug}` entries through `member_menu_items`; duplicate slugs are not added. `getDashboardWidgets()` excludes child sections (`parent_slug`) and explicit `dashboard_widget=false`, safely sanitizes text, six-digit colors, and local/HTTP(S) admin links, and ignores statistics callback exceptions.

The controller validates links and colors, trims text, removes unknown ordering values, limits displayed arrays, and uses fallback labels and colors. `member_dashboard_widgets` can modify the final widget list. Dashboard feature usage is recorded as `member.dashboard`.

---

# 365CMS Member-Dashboard

## Deutsch — Anwenderblock

Das Dashboard kann Begrüßung, Schnellstart, Statistiken, Kern-Widgets, eigene Infokarten, Plugin-Widgets, Onboarding, Benachrichtigungen, Profilfortschritt, aktuelle Aktivitäten und Abonnementinformationen anzeigen. Administratoren steuern Sichtbarkeit und Reihenfolge; Mitglieder sehen nur aktivierte und für ihr Konto zulässige Inhalte.

Bei `dashboard_enabled=false` entsteht keine Fehlerseite: Die Runtime leitet zum Profil um. Die schreibgeschützte Admin-Vorschau verwendet gespeicherte, normalisierte Werte und Beispieldaten.

## Deutsch — Technikblock

Die von `MemberController` verwendeten Einstellungen sind:

| Einstellung | Bedeutung in der Runtime |
|---|---|
| `dashboard_enabled` | Aktiviert das Dashboard; bei Deaktivierung Weiterleitung zum Profil |
| `dashboard_greeting`, `dashboard_welcome_text`, `show_welcome` | Begrüßung, Text und Sichtbarkeit |
| `widgets`, `dashboard_columns`, `section_order` | Kern-Widget-Allowlist, Spalten und Bereichsreihenfolge |
| `frontend_modules` | `show_quickstart`, `show_stats`, `show_custom_widgets`, `show_plugin_widgets`, `show_onboarding_panel`, `show_notifications_panel` |
| `profile_fields`, `required_profile_fields`, `custom_profile_fields` | Profilformular und Fortschrittsprüfung |
| `custom_widgets`, `custom_widget_order` | Administrativ gepflegte Infokarten |
| `plugin_widget_order` | Reihenfolge der Plugin-Kacheln |
| `notifications`, `onboarding` | Benachrichtigungszentrale, Typen, Schritte, Titel und Einleitung |
| `subscription_visible` | Erlaubt Abo-Menü und Dashboarddaten bei aktivem Modul |
| `design` | Primär-, Akzent-, Hintergrund-, Karten-, Textfarben und Logo |

`PluginDashboardRegistry::getAll()` filtert nach Berechtigung und der Datenbankoption `member_dashboard_plugin_{plugin}`. Eine fehlende Sichtbarkeitsoption bedeutet sichtbar. `getMenuItems()` liefert über `member_menu_items` Einträge nach `/member/plugin/{slug}`; doppelte Slugs werden vermieden. `getDashboardWidgets()` überspringt Unterbereiche (`parent_slug`) und `dashboard_widget=false`, bereinigt Texte, sechsstellige Farben und lokale/HTTP(S)-Admin-Links und ignoriert Fehler in Statistik-Callbacks.

Der Controller validiert Links und Farben, kürzt Texte, entfernt unbekannte Sortierwerte, begrenzt Arrays und verwendet sichere Fallbacks. `member_dashboard_widgets` kann die fertige Widget-Liste verändern. Die Feature-Nutzung wird als `member.dashboard` erfasst.

---

## Ergänzung (Stand 2026-10-02): Plugin-Bereiche und -Widgets registrieren

Plugins tragen sich im Hook `member_dashboard_init` in die `CMS\Member\PluginDashboardRegistry` ein. Pflichtfelder: `slug`, `label`, `render_callback`.

```php
\CMS\Hooks::addAction('member_dashboard_init', static function (\CMS\Member\PluginDashboardRegistry $registry): void {
    $registry->register([
        'plugin'     => 'mein-plugin',
        'slug'       => 'meine-buchungen',
        'label'      => 'Meine Buchungen',
        'icon'       => '📅',
        'category'   => 'plugins',
        'priority'   => 20,                 // kleiner = weiter oben
        'capability' => null,               // null = alle Mitglieder, 'admin' = nur Administratoren
        'dashboard_widget' => [
            'title'          => 'Buchungen',
            'description'    => 'Offene und kommende Termine',
            'color'          => '#0ea5e9', // #rrggbb
            'stats_callback' => [MeinPlugin\Member::class, 'stats'], // gibt ['count' => int, 'label' => string] zurück
        ],
        'render_callback' => static function (object $user, array $params): void {
            // Ausgabe innerhalb des Member-Layouts; $params enthält action/id aus der URL
        },
        'post_callback' => static function (object $user, array $params): void {
            // optional: POST-Verarbeitung – bei 'csrf' => 'core' ist das Token hier bereits geprüft
        },
        'csrf' => 'core', // seit 3.4.13: Core prüft das Token member_plugin_<slug>; Standard 'plugin' = selbst prüfen
    ]);
});
```

- Aufruf: `/member/plugin/meine-buchungen[/<aktion>[/<id>]]`; die Aktion steht in `$_GET['action']`, die numerische ID in `$_GET['id']`.
- **CSRF (seit 3.4.13):** Jeder POST auf einen Plugin-Bereich muss – falls der Browser `Origin`/`Referer` sendet – von der eigenen Site kommen, sonst antwortet der Core mit 403. Mit `'csrf' => 'core'` prüft der Core zusätzlich das Token; im Formular genügt `<?= $registry->csrfField('meine-buchungen') ?>` (bzw. `csrfToken()` für AJAX im Header `X-CSRF-Token`). `$registry` ist `\CMS\Member\PluginDashboardRegistry::instance()`.
- Unterbereiche über `parent_slug` erscheinen nicht als eigene Dashboard-Kachel.
- `dashboard_widget => false` blendet die Kachel aus; die Sichtbarkeit lässt sich zusätzlich im Admin unter *Mitglieder-Dashboard → Plugin-Widgets* steuern (Option `member_dashboard_plugin_<plugin>`, Reihenfolge `member_dashboard_plugin_order`).
- Fehler im `stats_callback` werden ignoriert; die Kachel bleibt sichtbar.
- **Achtung Capability:** außer `null` und `'admin'` werden andere Werte derzeit als „erlaubt“ gewertet. Feinere Rechte prüft das Plugin im Callback selbst (`Auth::instance()->hasCapability(...)`).

### Filter

| Filter | Zweck |
|---|---|
| `member_dashboard_widgets` | endgültige Widget-Liste (`$widgets, $user, $settings`) |
| `member_menu_items` | Menüeinträge ergänzen/ändern |

Admin-Konfiguration aller Dashboard-Optionen: [../admin/member/README.md](../admin/member/README.md).
