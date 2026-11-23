# 365CMS Projektdokumentation | Admin: Rollen & Rechte (RBAC)

> **Stand:** 2026-11-23 | **Version:** 4.2.0, Schema v27 | **Rolle:** `moderator` (Community-Systemrolle) | **Status:** Stable
> **Route:** `/admin/roles` (Alt-Route `/admin/rbac` leitet um) | **Capability:** `manage_users` | **CSRF-Aktion:** `admin_roles`

## English (summary)

Roles and capabilities are managed at `/admin/roles` (`CMS/admin/roles.php` → `CMS/admin/modules/users/RolesModule.php` → `CMS/admin/views/users/roles.php`). Default role definitions live in `CMS/includes/functions/roles.php` (`cms_get_default_role_definitions()`); overrides are stored in `cms_role_permissions`. `Auth::hasCapability()` grants every capability to role `admin`; other roles are resolved via `cms_load_role_capabilities()`.

**Important:** the admin area itself requires role `admin` (`Auth::isAdmin()` in `AdminRouter`). Capabilities of other roles therefore apply to the member area, the API, plugins and frontend features, not to `/admin`; the only exception are the few pages listed in `DelegatedAdminAccess::PAGES` (payments, invoices, reusable blocks, webhooks), which the role `moderator` cannot open.

## Deutsch

### Rollenmodell

| Rolle | Anzeigename | Kernrechte (Standard) |
|---|---|---|
| `admin` | Administrator | **alle** Capabilities (fest in `Auth::hasCapability()`) |
| `editor` | Editor | `manage_pages`, `edit_all_posts`, `delete_all_posts`, `manage_media`, alle `pages.*`/`posts.*`/`media.*`, `use_ai_*`, `comments.*`, zusätzlich `manage_trash`, `view_editorial_calendar`, `manage_translations`, `upload_downloads`, `view_form_submissions`, `posts.publish`, `pages.publish` (Veröffentlichungsrecht, siehe unten) und `blocks.reusable` |
| `author` | Autor | `edit_own_posts`, `pages.view/create/edit`, `posts.view/create/edit`, `media.view/upload`, `comments.view`, `posts.publish` |
| `member` | Mitglied | `read`, `edit_profile`, `view_content`, `*.view`, `community.view`, `community.participate` |
| `moderator` | Moderator (seit 4.2.0, Systemrolle) | wie `member`, zusätzlich `community.moderate`; kein Zugang zu `/admin` |
| `subscriber`, `contributor` | WordPress-kompatible Zusatzrollen | minimale Leserechte bzw. Beitragsentwürfe; kein `community.*` (siehe [Community-Rechte](#community-rechte-und-rolle-moderator-seit-420)) |

Alle Rollen besitzen `read`, `edit_profile` und `view_content`. Der Alias `administrator` wird auf `admin` normalisiert.

### Capabilities nach Bereich

| Bereich | Capabilities |
|---|---|
| Seiten | `pages.view`, `pages.create`, `pages.edit`, `pages.delete`, `pages.publish`, `manage_pages` |
| Beiträge | `posts.view`, `posts.create`, `posts.edit`, `posts.delete`, `posts.publish`, `edit_all_posts`, `delete_all_posts`, `edit_own_posts` |
| Medien | `media.view`, `media.upload`, `media.delete`, `media.settings`, `manage_media` |
| Benutzer | `users.view`, `users.create`, `users.edit`, `users.delete`, `users.roles`, `manage_users` |
| Themes | `themes.view`, `themes.activate`, `themes.customize`, `themes.install` |
| Plugins | `plugins.view`, `plugins.activate`, `plugins.install`, `plugins.settings` |
| Einstellungen | `settings.view`, `settings.edit`, `settings.system`, `manage_settings`, `manage_system` |
| KI | `manage_ai_services`, `use_ai_translation`, `use_ai_rewrite`, `use_ai_summary`, `use_ai_seo_meta` |
| Analyse | `view_analytics` |
| Kommentare | `comments.view`, `comments.moderate`, `comments.delete` |
| Inhalte & Redaktion | `manage_trash`, `view_editorial_calendar`, `manage_translations`, `import_export` |
| Formulare | `manage_forms`, `view_form_submissions` |
| Downloads | `manage_downloads`, `upload_downloads`, `view_download_stats` |
| System & Werkzeuge | `manage_search_index`, `manage_feeds`, `manage_admin_menu` (Sidebar-Editor, [../ADMIN-MENU.md](../ADMIN-MENU.md)) |
| Wissensdatenbank, Newsletter | `manage_knowledgebase`, `manage_newsletter` |
| Bausteine | `blocks.reusable`: *EditorJS Block Vorlagen* verwalten (`/admin/reusable-blocks`) und im Editor einfügen; Standard: Editor (Admin immer) |
| Identität | `security.sso`: Tab *Einstellungen → SSO* (Microsoft Entra ID, [../security/SSO-ENTRA.md](../security/SSO-ENTRA.md)); Admin über die Rolle, weitere Rollen nur per Matrix |
| Backups | `backups.offsite`: *Backup-Einstellungen* mit S3-Offsite-Ziel ([../system-settings/BACKUP.md](../system-settings/BACKUP.md)) |
| Webhooks | `webhooks.manage`: ausgehende Webhooks ([../system-settings/WEBHOOKS.md](../system-settings/WEBHOOKS.md)) |
| Community (seit 4.2.0) | `community.view` (Community sehen), `community.participate` (schreiben), `community.moderate` (globale Moderation: Warteschlange, Meldungen, Verwarnungen, befristete Sperren), `community.ban` (dauerhafte Sperren), `community.manage` (Struktur, Rechte, Einstellungen), `community.view_private_data` (gekürzte IP-Kennung, Verlauf gelöschter Beiträge); Standard: siehe [Community-Rechte](#community-rechte-und-rolle-moderator-seit-420) |
| Zahlungen | `payments.manage`: Stripe/Mollie-Einstellungen und Zahlungsübersicht ([../subscription/PAYMENTS.md](../subscription/PAYMENTS.md)); `invoices.view`, Rechnungen aller Kunden ansehen und Storno erstellen ([../subscription/INVOICES.md](../subscription/INVOICES.md)) |

Capability-Namen werden normalisiert (Kleinschreibung, `/`, `\` und `:` werden zu `.`).

### Oberfläche und Aktionen

- **Rechte-Matrix:** Rollen × Capabilities als Checkboxen, gruppiert nach Bereich; Rollenvergleich nebeneinander.
- Aktionen (`action`):

| Aktion | Wirkung |
|---|---|
| `save_permissions` | Matrix speichern → `cms_role_permissions (role, capability, granted)` |
| `reset_permissions` | schreibt die Standardrechte der Rollen (`getDefaultPermissions()`) als ausdrückliche Zeilen (`granted` 0/1) neu; weicht in Einzelfällen von den Code-Standards in `roles.php` ab |
| `add_role` / `update_role` / `delete_role` | Eigene Rollen verwalten (Standardrollen sind geschützt) |
| `add_capability` / `update_capability` / `delete_capability` | Eigene Capabilities (z. B. für Plugins) verwalten |

### Veröffentlichungsrechte

`posts.publish` und `pages.publish` werden tatsächlich geprüft: Wer das Recht nicht besitzt, kann „Veröffentlichen“, „Planen“ und „Privat“ nur als Einreichung (Status `pending`, „Zur Prüfung“) speichern ([../pages-posts/REVIEW.md](../pages-posts/REVIEW.md)). Damit sich für bestehende Rollen nichts ändert, vergibt der Update-Schritt `ContentWorkflow::migrateCapabilities()` einmalig `posts.publish` und `pages.publish` an *Editor* sowie `posts.publish` an *Autor*, nur, wenn für die Rolle und die Capability noch kein Eintrag in `cms_role_permissions` steht; ein bewusst entzogenes Recht bleibt entzogen. Admins sind nicht betroffen. Mit der Einstellung „Veröffentlichung braucht eine zweite Person“ kann zusätzlich niemand mehr den eigenen oder selbst eingereichten Inhalt freigeben, auch kein Admin.

### Community-Rechte und Rolle `moderator` (seit 4.2.0)

Das Community-Modul bringt sechs Capabilities (Gruppe „Community“ in `RolesModule::CAPABILITIES`) und die Systemrolle `moderator` mit (nicht umbenenn- oder löschbar, nie als Registrierungsrolle wählbar, keine automatische Entra-Verknüpfung per E-Mail). Ein einmaliger Update-Schritt (`capabilities_v27`, Marker `capabilities_v27` in `settings`) trägt die Standards als Zeilen in `cms_role_permissions` ein:

| Capability | Standard |
|---|---|
| `community.view`, `community.participate` | `member`, `editor`, `author`, `moderator` |
| `community.moderate` | `moderator` |
| `community.ban`, `community.manage`, `community.view_private_data` | nur `admin` (über die Rolle) |

Der Schritt überschreibt nie eine vorhandene Zeile: Ein ausdrücklich entzogenes Recht (`granted = 0`) bleibt entzogen, ein zweiter Lauf ändert nichts. Die Grundrechte der Rolle `moderator` (wie `member`: `read`, `edit_profile`, `view_content`, `pages.view`, `posts.view`, `media.view`, `comments.view`) stehen nur in den Code-Standards (`cms_get_default_role_definitions()`), nicht als Zeilen: Zeilen für Rechte außerhalb des Katalogs erschienen im Rollen-Editor als „eigene Rechte“, und das Speichern der Matrix entzöge sie anderen Rollen. **Eine schon vorhandene eigene Rolle `moderator`** wird mit diesen Standards zur Systemrolle: Die Standards gelten zusätzlich zu ihren gespeicherten Zeilen (ein ausdrücklicher Entzug mit `granted = 0` bleibt wirksam); wer eine solche Rolle angelegt hat, prüft ihre Rechte vor dem Update. Schlägt der Schritt fehl, meldet *Updates → Datenbank-Update* den fehlenden Marker (`option:capabilities_v27`) und wiederholt ihn. Moderation für Nicht-Admins läuft im Frontend und Mitgliederbereich, nicht in `/admin` (siehe Hinweis oben). Technik: [../../workflow/specs/community/HANDOFF-PR2-PR5.md](../../workflow/specs/community/HANDOFF-PR2-PR5.md) §4.

**Übrige Rollen (Entscheidung):** `subscriber`, `contributor` und eigene Rollen, die vor dem Update auf 4.2.0 bestehen, erhalten `community.view` und `community.participate` **nicht automatisch**. Konten dieser Rollen sehen die Community nicht, auch wenn Gäste sie lesen dürfen, und die Rechte je Forum ändern daran nichts. Wer sie beteiligen will, setzt im Rollen-Editor (Gruppe „Community“) die Haken bei `community.view` und gegebenenfalls `community.participate`. Eine nach dem Update angelegte Rolle übernimmt die Rechte ihrer Vorlage („Rechte übernehmen von“, Standard Mitglied), also auch die Community-Rechte.

**SSO-Standardrolle:** War in *Einstellungen → SSO* eine eigene Rolle `moderator` als Standardrolle eingetragen, gilt ab 4.2.0 `member`; mit „Rollen synchronisieren“ fallen über SSO angelegte Konten mit der Rolle `moderator` ohne Mapping-Treffer bei der nächsten Anmeldung auf `member` zurück. Details: [../security/SSO-ENTRA.md](../security/SSO-ENTRA.md).

### Auflösung zur Laufzeit

1. `Auth::hasCapability($cap)` → nicht angemeldet: `false`.
2. Rolle `admin`: immer `true`.
3. Sonst `cms_load_role_capabilities($role)`: Standarddefinition + Overrides aus `cms_role_permissions`.
4. Plugins prüfen über `current_user_can('…')` (WordPress-kompatibler Wrapper) dieselbe Logik.

### Wichtig: Adminbereich nur für `admin`

`AdminRouter::renderAdminPage()` lässt nur `Auth::isAdmin()` (Rolle exakt `admin`) zu. Ein Editor oder Autor wird, unabhängig von seinen Capabilities, auf `/member` umgeleitet. Feingranulare Rechte wirken daher auf:

- Member-Bereich und Plugin-Bereiche im Member-Dashboard,
- JSON-API (`/api/v1/…`, z. B. `/api/v1/admin/posts`) und Upload-Endpunkte (`/api/upload`, `/api/media`),
- Plugin-Funktionen, die `current_user_can()` (`CMS/includes/functions/redirects-auth.php`) verwenden.

Für Benutzer mit der Rolle `admin` kann kein Recht entzogen werden, `hasCapability()` liefert für `admin` immer `true`. Die Capability-Prüfungen in den Admin-Einstiegen (z. B. `manage_pages`, `edit_own_posts`) sind damit eine zusätzliche Absicherung für künftige oder angepasste Rollenmodelle.

### Verwandte Dokumente

[USERS.md](USERS.md) · [GROUPS.md](GROUPS.md) · [../../core/SECURITY.md](../../core/SECURITY.md) · [../../plugins/PLUGIN-DEVELOPMENT.md](../../plugins/PLUGIN-DEVELOPMENT.md)
