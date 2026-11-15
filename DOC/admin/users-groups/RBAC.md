# 365CMS – Projektdokumentation | Abschnitt: Admin – Rollen & Rechte (RBAC)

> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Status:** Stable
> **Route:** `/admin/roles` (Alt-Route `/admin/rbac` leitet um) | **Capability:** `manage_users` | **CSRF-Aktion:** `admin_roles`

## English (summary)

Roles and capabilities are managed at `/admin/roles` (`CMS/admin/roles.php` → `CMS/admin/modules/users/RolesModule.php` → `CMS/admin/views/users/roles.php`). Default role definitions live in `CMS/includes/functions/roles.php` (`cms_get_default_role_definitions()`); overrides are stored in `cms_role_permissions`. `Auth::hasCapability()` grants every capability to role `admin`; other roles are resolved via `cms_load_role_capabilities()`.

**Important:** the admin area itself requires role `admin` (`Auth::isAdmin()` in `AdminRouter`). Capabilities of other roles therefore apply to the member area, the API, plugins and frontend features – not to `/admin`.

## Deutsch

### Rollenmodell

| Rolle | Anzeigename | Kernrechte (Standard) |
|---|---|---|
| `admin` | Administrator | **alle** Capabilities (fest in `Auth::hasCapability()`) |
| `editor` | Editor | `manage_pages`, `edit_all_posts`, `delete_all_posts`, `manage_media`, alle `pages.*`/`posts.*`/`media.*`, `use_ai_*`, `comments.*`; seit 4.0.0 zusätzlich `manage_trash`, `view_editorial_calendar`, `manage_translations`, `upload_downloads`, `view_form_submissions`; seit 4.1.0 `posts.publish`, `pages.publish` (Veröffentlichungsrecht, siehe unten) und `blocks.reusable` |
| `author` | Autor | `edit_own_posts`, `pages.view/create/edit`, `posts.view/create/edit`, `media.view/upload`, `comments.view`; seit 4.1.0 `posts.publish` |
| `member` | Mitglied | `read`, `edit_profile`, `view_content`, `*.view` |
| `subscriber`, `contributor` | WordPress-kompatible Zusatzrollen | minimale Leserechte bzw. Beitragsentwürfe |

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
| Inhalte & Redaktion (seit 4.0.0) | `manage_trash`, `view_editorial_calendar`, `manage_translations`, `import_export` |
| Formulare (seit 4.0.0) | `manage_forms`, `view_form_submissions` |
| Downloads (seit 4.0.0) | `manage_downloads`, `upload_downloads`, `view_download_stats` |
| System & Werkzeuge (seit 4.0.0) | `manage_search_index`, `manage_feeds`, `manage_admin_menu` (Sidebar-Editor, [../ADMIN-MENU.md](../ADMIN-MENU.md)) |
| Wissensdatenbank, Newsletter | `manage_knowledgebase`, `manage_newsletter` |
| Bausteine (seit 4.1.0) | `blocks.reusable` – *EditorJS Block Vorlagen* verwalten (`/admin/reusable-blocks`) und im Editor einfügen; Standard: Editor (Admin immer) |
| Identität (seit 4.1.0) | `security.sso` – Tab *Einstellungen → SSO* (Microsoft Entra ID, [../security/SSO-ENTRA.md](../security/SSO-ENTRA.md)); Admin über die Rolle, weitere Rollen nur per Matrix |
| Backups (seit 4.1.0) | `backups.offsite` – *Backup-Einstellungen* mit S3-Offsite-Ziel ([../system-settings/BACKUP.md](../system-settings/BACKUP.md)) |
| Webhooks (seit 4.1.0) | `webhooks.manage` – ausgehende Webhooks ([../system-settings/WEBHOOKS.md](../system-settings/WEBHOOKS.md)) |
| Zahlungen (seit 4.1.0) | `payments.manage` – Stripe/Mollie-Einstellungen und Zahlungsübersicht ([../subscription/PAYMENTS.md](../subscription/PAYMENTS.md)); `invoices.view` – Rechnungen aller Kunden ansehen und Storno erstellen ([../subscription/INVOICES.md](../subscription/INVOICES.md)) |

Capability-Namen werden normalisiert (Kleinschreibung, `/`, `\` und `:` werden zu `.`).

### Oberfläche und Aktionen

- **Rechte-Matrix:** Rollen × Capabilities als Checkboxen, gruppiert nach Bereich; Rollenvergleich nebeneinander.
- Aktionen (`action`):

| Aktion | Wirkung |
|---|---|
| `save_permissions` | Matrix speichern → `cms_role_permissions (role, capability, granted)` |
| `reset_permissions` | Overrides löschen, Standarddefinitionen gelten wieder |
| `add_role` / `update_role` / `delete_role` | Eigene Rollen verwalten (Standardrollen sind geschützt) |
| `add_capability` / `update_capability` / `delete_capability` | Eigene Capabilities (z. B. für Plugins) verwalten |

### Veröffentlichungsrechte (seit 4.1.0)

`posts.publish` und `pages.publish` werden ab 4.1.0 tatsächlich geprüft: Wer das Recht nicht besitzt, kann „Veröffentlichen“, „Planen“ und „Privat“ nur als Einreichung (Status `pending`, „Zur Prüfung“) speichern ([../pages-posts/REVIEW.md](../pages-posts/REVIEW.md)). Damit sich für bestehende Rollen nichts ändert, vergibt der Update-Schritt `ContentWorkflow::migrateCapabilities()` einmalig `posts.publish` und `pages.publish` an *Editor* sowie `posts.publish` an *Autor* – nur, wenn für die Rolle und die Capability noch kein Eintrag in `cms_role_permissions` steht; ein bewusst entzogenes Recht bleibt entzogen. Admins sind nicht betroffen. Mit der Einstellung „Veröffentlichung braucht eine zweite Person“ kann zusätzlich niemand mehr den eigenen oder selbst eingereichten Inhalt freigeben, auch kein Admin.

### Auflösung zur Laufzeit

1. `Auth::hasCapability($cap)` → nicht angemeldet: `false`.
2. Rolle `admin`: immer `true`.
3. Sonst `cms_load_role_capabilities($role)`: Standarddefinition + Overrides aus `cms_role_permissions`.
4. Plugins prüfen über `current_user_can('…')` (WordPress-kompatibler Wrapper) dieselbe Logik.

### Wichtig: Adminbereich nur für `admin`

`AdminRouter::renderAdminPage()` lässt nur `Auth::isAdmin()` (Rolle exakt `admin`) zu. Ein Editor oder Autor wird – unabhängig von seinen Capabilities – auf `/member` umgeleitet. Feingranulare Rechte wirken daher auf:

- Member-Bereich und Plugin-Bereiche im Member-Dashboard,
- JSON-API (`/api/v1/…`, z. B. `/api/v1/admin/posts`) und Upload-Endpunkte (`/api/upload`, `/api/media`),
- Plugin-Funktionen, die `current_user_can()` (`CMS/includes/functions/redirects-auth.php`) verwenden.

Für Benutzer mit der Rolle `admin` kann kein Recht entzogen werden – `hasCapability()` liefert für `admin` immer `true`. Die Capability-Prüfungen in den Admin-Einstiegen (z. B. `manage_pages`, `edit_own_posts`) sind damit eine zusätzliche Absicherung für künftige oder angepasste Rollenmodelle.

### Verwandte Dokumente

[USERS.md](USERS.md) · [GROUPS.md](GROUPS.md) · [../../core/SECURITY.md](../../core/SECURITY.md) · [../../plugins/PLUGIN-DEVELOPMENT.md](../../plugins/PLUGIN-DEVELOPMENT.md)
