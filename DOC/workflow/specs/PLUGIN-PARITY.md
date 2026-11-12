# 365CMS – Paritäts-Checkliste der in den Core integrierten Plugins

> **Stand:** 2026-11-12 | **Version:** 4.0.0 | **Gehört zu:** [PLUGIN-INTEGRATION.md](PLUGIN-INTEGRATION.md), PROGRESS-Zeile B0

## English (summary)

Item-by-item comparison of the five plugins integrated in 365CMS 4.0.0 (cms-feed, cms-downloads, cms-contact, cms-knowledgebase, cms-newsletter) with their core replacements: public routes, hooks, settings, admin actions, mails, cron, capabilities, member areas and data. Every public plugin URL was requested against the core (12 Nov 2026), every setting key was checked against the core services and migrators. Gaps found and closed: the contact migrator now takes over `admin_email` and the global `send_confirmation` switch. Intentional differences are listed per plugin.

## Deutsch

### Vorgehen

1. **Routen:** alle `addRoute`-Aufrufe der Plugins (`PS-PhinIT/365CMS.DE-PLUGINS`) ausgelesen und jede öffentliche Adresse – auch unter `/en/…` – gegen den Core abgerufen (Testinstallation, 12.11.2026).
2. **Hooks:** gefeuerte (`doAction`/`applyFilters`) und genutzte Hooks der Plugins gegen den Core abgeglichen.
3. **Einstellungen:** jeden Einstellungsschlüssel der Plugins in den Core-Diensten und Migratoren gesucht.
4. **Admin, Mails, Cron, Rechte, Mitgliederbereich, Daten:** Plugin-Aktionen und Ansichten mit den Core-Seiten verglichen; Datenübernahme je Plugin mit Beispieldaten geprobt (Migratoren, jeweils zweiter Lauf ohne Änderung).

Legende: ✅ gleichwertig oder erweitert · ➖ bewusst weggelassen (Begründung) · 🔧 beim Abgleich ergänzt

### cms-feed → Feed-Aggregator

| Bereich | Plugin | Core | Status |
|---|---|---|---|
| Archiv | `/{slug}` (Standard `feeds`), `/en/{slug}` | gleiche Adressen, Slug aus den Einstellungen | ✅ |
| Embed/White-Label | `/{slug}/embed` inkl. `head`/`body_start`/`body_end` | `views/feeds/embed.php` mit denselben Hooks | ✅ |
| Bereiche | `/feed/{bereich}`, Alias `/{slug}/{bereich}`, je mit `/en/` | gleiche Adressen; nicht öffentliche Bereiche 404 | ✅ |
| Alte Admin-Adresse | `POST /admin/feeds` | `GET /admin/feeds` leitet auf *Einstellungen → Feeds* um, Plugin-Adminseiten per 301 | ✅ |
| Cron | `cms_cron_feeds`, `cron.php?task=feeds`, Mail-Queue- und Stundenlauf | `CronRunnerService` (Tasks `feeds`/`cms_cron_feeds`) und `FeedHooks` | ✅ |
| Admin-Aktionen | `save_channel`, `delete_item`, `toggle_featured`, `toggle_hidden`, `fetch_now`, `cleanup`, `import_catalog`, `import_opml`, `export_opml`, `save_category`, `save_digest(_settings)`, `test_digest`, `save_design`, `save_settings` | `save_source`, `item_flag`/`bulk_items` (hervorheben, ausblenden, löschen), `fetch_source`/`run_queue`, Aufräumen in `FetchQueue`, Katalog/OPML-Import und -Export, Bereiche, Digests mit Testversand, Einstellungen (inkl. Design) | ✅ |
| Einstellungen | 22 Schlüssel | alle in `AggregatorSettings`/`FeedPluginMigrator` | ✅ |
| Mails | Bereichs-Digests, Mitglieder-Abos | `DigestMailer` (Warteschlange oder direkt) | ✅ |
| Mitgliederbereich | Abos je Bereich | `FeedMemberSection` | ✅ |
| Daten | Kategorien, Kanäle, Artikel, Digests, Abos, Abruf-Queue | `FeedPluginMigrator` über `legacy_id` | ✅ |

### cms-downloads → Downloads

| Bereich | Plugin | Core | Status |
|---|---|---|---|
| Archiv | `/downloads`, `/en/downloads` | gleich | ✅ |
| Kategorie | `/downloads/category/{slug}` (+ `/en/`) | gleich | ✅ |
| Datei | `/downloads/file/{slug}` (+ `/en/`) | 301 auf `/download/{slug}` (Auslieferung mit Versionen, Zugriff, Zählung) | ✅ |
| Menüpunkt | `main_nav` | `DownloadHooks` (`main_nav`) | ✅ |
| Admin-Aktionen | Download/Kategorie speichern und löschen, Einstellungen | Liste mit Sammelaktionen, Bearbeiten in Abschnitten, Versionen, Statistik, Kategorien, Einstellungen | ✅ |
| Einstellungen | 16 Schlüssel | 15 in `DownloadSettings`/`DownloadsPluginMigrator` | ✅ |
| `external_token_secret` | Geheimnis für signierte „Weiter zum externen Link“-Tokens | eigenes Verfahren im Core; die Tokens des Plugins galten nur kurz | ➖ |
| Daten | Downloads, Kategorien, Rate-Limits | `DownloadsPluginMigrator` (Versionen mit SHA-256, Zähler, privater Speicher für geschützte Dateien) | ✅ |

### cms-contact → Formulare

| Bereich | Plugin | Core | Status |
|---|---|---|---|
| Seiten | `/contact`, `/contact/{schlüssel}` (+ `/en/`), `/kontakt` | gleich (`ThemeRouter`) | ✅ |
| API | `POST /api/contact/{schlüssel}/submit` | gleich (`FormHooks`) | ✅ |
| Hook | `contact_submitted` | weiterhin gefeuert, dazu `cms_form_submitted` | ✅ |
| DSGVO | `dsgvo_export_data`, `dsgvo_delete_data` | `FormPrivacyService` | ✅ |
| Mitgliederbereich | „Kontaktanfragen“ | `FormMemberSection` (gleicher Slug) | ✅ |
| Admin | Formulare, Felder, Eingänge, Einstellungen | Baukasten (15 Feldtypen), Eingänge mit Status/CSV/DSGVO-Exporten, Einstellungen | ✅ |
| Einstellungen | 13 Schlüssel | 11 übernommen | ✅ |
| Empfänger `admin_email` | neuere Einstellung, Vorrang vor `global_recipient` | Migrator übernahm nur `global_recipient` → jetzt `admin_email`, sonst `global_recipient` | 🔧 |
| `send_confirmation` | globale Eingangsbestätigung an den Absender | Migrator schaltet die Bestätigungsmail je übernommenem Formular ein (Text wie im Plugin, Absendername aus `from_name`) | 🔧 |
| Sicherheitsereignisse | Tabelle `contact_security_events` | Spam-Abweisungen protokolliert der Core über `FormSpamGuard`/AntiSpam; alte Protokolle werden nicht übernommen | ➖ |
| Daten | Formulare, Felder, Eingänge, Metadaten | `ContactPluginMigrator` (ohne IP-Adressen und User-Agents) | ✅ |

### cms-knowledgebase → Knowledgebase

| Bereich | Plugin | Core | Status |
|---|---|---|---|
| Seiten | `/kb`, `/kb/{slug}`, `/glossar`, `/glossar-sitemap.xml` (+ `/en/`) | gleich | ✅ |
| Auto-Verlinkung | `content_render`, Output-Buffer-Fallback | gleich | ✅ |
| Assets | Tooltip-Skript, Stylesheets, Design-Variablen (`head`, `before_footer`) | gleich, nur auf Seiten mit Bedarf | ✅ |
| Menüpunkt | `main_nav` | gleich | ✅ |
| Admin | Dashboard, Einträge, Editor, Kategorien, Einstellungen (4 Tabs), CSV-Pakete, Hardreset | gleich, im Core-Stil; Löschen über den Papierkorb | ✅ |
| Daten | `kb_*` | gleiche Tabellen, Schema-Anhebung durch `KnowledgebasePluginMigrator` | ✅ |
| Neu im Core | – | Papierkorb, Suchindex, Import/Export, Sprachdateien | ✅ |

### cms-newsletter → Newsletter

| Bereich | Plugin | Core | Status |
|---|---|---|---|
| Seiten | `/newsletter`, `POST /newsletter/subscribe`, `/newsletter/unsubscribe/{token}` (GET und POST), je mit `/en/` | gleich, dazu `/newsletter/confirm/{token}` und `/newsletter/issue/{id}` | ✅ |
| Abmelden per GET | meldete sofort ab (auch bei Vorabprüfung durch Mailfilter) | Seite mit Knopf, Abmeldung per POST bzw. One-Click | ✅ |
| Bestätigung | `confirm_subscriber()` ohne Route, keine Bestätigungsmail | Bestätigungsmail und -seite | ✅ |
| Versand | nur Status „gesendet“, keine Mails | Versand in Blöcken per Cron, Test, Planen, Stoppen | ✅ |
| Einstellungen | 13 Schlüssel | alle übernommen, `@example.com`-Platzhalter geleert | ✅ |
| Admin | Dashboard, Abonnenten, Templates, Kampagnen, Einstellungen (3 Seiten, DNS-Prüfung) | gleich, dazu Import/CSV, Vorschau, DSGVO-Auskünfte | ✅ |
| Daten | `newsletter_*` | gleiche Tabellen, `NewsletterPluginMigrator` | ✅ |

### Übergreifend

| Punkt | Umsetzung | Status |
|---|---|---|
| Stilllegung | `IntegratedPluginService` deaktiviert integrierte Plugins, bereinigt die MultiSite-Zuweisung, zeigt 30 Tage einen Hinweis | ✅ |
| Alte Admin-Adressen | 301 auf die Core-Seiten (`admin_redirects` je Plugin, Wurzel-Aliase `knowledgebase-dashboard`, `newsletter-dashboard`) | ✅ |
| Marketplace | `integrated_in_core: 4.0.0` für alle fünf Plugins, keine Installation und keine Update-Angebote | ✅ |
| Rechte | Plugins prüften meist nur „Admin“ | eigene Capabilities: `manage_feeds`, `manage_downloads`/`upload_downloads`/`view_download_stats`, `manage_forms`/`view_form_submissions`, `manage_knowledgebase`, `manage_newsletter` | ✅ |
| Öffentliche POSTs | Plugins verließen sich teils auf eigene Tokens | globaler CSRF-Schutz des Routers; Ausnahmen nur für `/contact/` und `/newsletter/` mit eigener Prüfung | ✅ |

### Nicht Teil dieser Integration

`cms-beratung`, `cms-booking`, `cms-forum`, `cms-projects` und `cms-promos` bleiben eigenständige Plugins.
