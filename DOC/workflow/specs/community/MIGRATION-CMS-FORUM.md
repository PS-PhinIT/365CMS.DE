# 365CMS – Projektdokumentation | Abschnitt: Community – Übernahme aus cms-forum
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.1 (Vorschlag) | **Status:** Planung | **Übersicht:** [README.md](README.md) · **Muster:** [PLUGIN-PARITY.md](../PLUGIN-PARITY.md)

## English (summary)

How the Community core module replaces the `cms-forum` plugin (repository `PS-PhinIT/365CMS.DE-PLUGINS`, v3.0.4,
DB version 1.0.2): inventory (routes, tables, hooks, settings, admin pages, member area, assets), a parity checklist
that must be fully ticked before the plugin is retired, URL mapping with 301 redirects, the `ForumPluginMigrator`
(idempotent via `legacy_id`, dry run, batch job), settings mapping, and the retirement steps. Important finding:
of 43 default settings only 5 are read by the plugin code, and the plugin's own settings page saves partly different
keys (e.g. `guest_can_read`, `require_post_approval`) that are never read either — so switches such as "guests may
read", "require approval" or attachment limits currently have no effect. The core module must make every migrated
setting effective and tell administrators which behaviour changes.

## Deutsch

### 1. Bestandsaufnahme `cms-forum` 3.0.4 (gelesen am 14.11.2026)

| Bereich | Bestand |
|---|---|
| Code | ca. 10.900 Zeilen PHP; `src/Controllers` (Forum, Thread, Post, Moderator, Admin), `src/Models` (13), `src/Services` (BBCodeParser, Notification, Permission, Search, FloodControl, ReadTracker), 9 Admin-Seiten, 7 Frontend-, 2 Mitglieder-Vorlagen, 6 JS-, 4 CSS-Dateien, Sprachdateien `de_DE`/`en_US` |
| Tabellen | 17 × `{prefix}cmsforum_*`: categories, forums, threads, posts, user_meta, ranks, permissions, subscriptions, attachments, polls, poll_options, poll_votes, likes, reports, mod_log, read_tracking, settings |
| Routen | `/forum`, `/forum/:slug`, `/forum/:slug/new-thread` (GET/POST), `/forum/thread/:id` (GET/POST), `/forum/post/:id/edit` (GET/POST), `/forum/search`, `/forum/user/:id`, `POST /forum/api/{like,subscribe,report,poll-vote}`, `GET /forum/api/similar-threads` – jeweils auch `/en/…` |
| Hooks genutzt | `cms_init`, `register_routes`, `cms_admin_menu`, `member_dashboard_init`, `head`, `body_end`, `dsgvo_export_data`, `dsgvo_delete_data`, `plugin_activated`, `plugin_uninstalled` |
| Hooks gefeuert | keine eigenen |
| Rechte | Tabelle `cmsforum_permissions` je Forum und `group_type` (`guest`, `member`, `moderator`); Rechte `can_read`, `can_post`, `can_create`, `can_edit_own`, `can_delete_own`, `can_upload`, `can_vote`, `can_moderate`; Admin = Core-Admin |
| Mitgliederbereich | `/member/plugin/forum` (Übersicht, Einstellungen) |
| Funktionen laut FeatureList | ✅ Lösung markieren, ✅ ähnliche Themen; ⏸ Vertrauensstufen, ⏸ Freigabe-Warteschlange, ⏸ Langsamer Modus je Forum |
| Theme-Bezug | `cms-phinit`: `member/forum.php`, Mitglieder-Navigation, Dashboard-Schnelllink ([THEME-UI.md](THEME-UI.md) §7) |

### 2. Paritäts-Checkliste

Legende wie in [PLUGIN-PARITY.md](../PLUGIN-PARITY.md): ✅ gleichwertig oder erweitert · ➖ bewusst weggelassen ·
🔧 beim Abgleich ergänzt · ⬜ offen (Planungsstand: alles ⬜, Abhaken bei der Umsetzung).

| Bereich | Plugin | Core (Ziel) | Status |
|---|---|---|---|
| Startseite | `/forum` | `/forum` (Basis einstellbar) | ⬜ |
| Forum | `/forum/{slug}` | gleich; Unterforen, Filter | ⬜ |
| Thema | `/forum/thread/{id}` | `/forum/thema/{id}-{slug}`, alt → 301 | ⬜ |
| Neues Thema | `/forum/{slug}/new-thread` | `/forum/{slug}/neues-thema`, alt → 301 | ⬜ |
| Bearbeiten | `/forum/post/{id}/edit` | `/forum/beitrag/{id}/bearbeiten`, alt → 301 | ⬜ |
| Suche | `/forum/search` | `/forum/suche` (Index + Rechtefilter), alt → 301 | ⬜ |
| Profil | `/forum/user/{id}` | `/forum/mitglieder/{id}` + Core-Profil, alt → 301 | ⬜ |
| API | `POST /forum/api/{like,subscribe,report,poll-vote}`, `GET similar-threads` | neue Endpunkte; alte Namen ein Release als Alias, dann 410 | ⬜ |
| BBCode | `BBCodeParser` | Markdown; Altbeiträge mit `format = bbcode` über übernommenen Parser (E6) | ⬜ |
| Rechte | 3 Gruppentypen × 8 Rechte | Rollen, Gruppen, Gäste, Stufen × 12 Rechte, Vererbung | ⬜ |
| Moderation | schließen, anpinnen, verschieben, löschen, wiederherstellen, freigeben, Meldung erledigen | + zusammenführen, teilen, ausblenden mit Grund, Warteschlange, Verwarnungen | ⬜ |
| Sperren | `cmsforum_user_meta.is_banned/ban_reason/ban_expires` | `community_bans` (Umfang lesen/kein Zugriff) | ⬜ |
| Ränge | `cmsforum_ranks` | `community_ranks` | ⬜ |
| Umfragen | ja | ja, erweitert (Sichtbarkeit Ergebnisse) | ⬜ |
| Anhänge | `cmsforum_attachments` + Dateien | privater Speicher, Neukodierung von Bildern | ⬜ |
| Likes | `cmsforum_likes` | Reaktionen (`like` + Auswahl) | ⬜ |
| Abos | Thema/Forum, `notify_email` | Modi sofort/täglich/wöchentlich/nur In-App | ⬜ |
| Benachrichtigungen | eigener `NotificationService` | Core `notifications` + Mail-Warteschlange | ⬜ |
| Gelesen-Status | `cmsforum_read_tracking` (Zeitstempel) | `community_read_states` (letzter Beitrag) + Forum-Marken | ⬜ |
| Lösung markieren | `accepted_post_id` | `solution_post_id` + `QAPage` | ⬜ |
| Ähnliche Themen | `/forum/api/similar-threads` | gleiches Verhalten, Suchindex | ⬜ |
| Moderationsprotokoll | `cmsforum_mod_log` (mit IP) | `community_moderation_log` (ohne IP) | ⬜ |
| Mitgliederbereich | `/member/plugin/forum` | `/member/plugin/community`; alt → 301 | ⬜ |
| DSGVO | Export-/Lösch-Hooks | gleiche Hooks, erweitert (W13) | ⬜ |
| Admin | 9 Plugin-Seiten | `/admin/community-*`; `/admin/plugins/cms-forum/…` → 301 | ⬜ |
| Sprachen | `lang/de_DE.php`, `en_US.php` | `lang/de.yaml`, `en.yaml` (`community.*`) | ⬜ |
| Farben/Design | `primary_color`, `card_bg`, `border_radius` (ohne Wirkung) | Design-Tokens des Themes | ➖ (Theme-Aufgabe) |
| `enable_dark_mode` (Admin-Feld) | ohne Wirkung | Dark Mode folgt dem Theme | ➖ |
| `video_tag_enabled`, `img_tag_enabled` | ohne Wirkung | Video-Einbettung nur über Core-Embed mit Einwilligung (P3); Bilder aus Anhängen | ➖ / ⬜ |
| IP-Adressen an Beiträgen | `cmsforum_posts.ip_address` (Klartext, unbefristet) | nicht übernommen; nur HMAC gekürzter IP, 30 Tage | ➖ (Datensparsamkeit) |

### 3. Adressen

| Alt (Plugin) | Neu (Core) | Art |
|---|---|---|
| `/forum` | `/forum` | unverändert |
| `/forum/{slug}` | `/forum/{slug}` | unverändert (Slug übernommen) |
| `/forum/thread/{id}` | `/forum/thema/{neue-id}-{slug}` | 301 über `community_redirects` (`legacy_thread`) |
| `/forum/post/{id}/edit` | `/forum/beitrag/{neue-id}/bearbeiten` | 301 (`legacy_post`) |
| `/forum/{slug}/new-thread` | `/forum/{slug}/neues-thema` | 301 |
| `/forum/search?…` | `/forum/suche?…` (Parameter übersetzt) | 301 |
| `/forum/user/{id}` | `/forum/mitglieder/{id}` | 301 (Benutzer-IDs bleiben gleich) |
| `/member/plugin/forum` | `/member/plugin/community` | 301 |
| `/admin/plugins/cms-forum/…` | `/admin/community…` | 301 (wie bei den Plugins in 4.0.0) |
| alles auch unter `/en/…` | `/en/forum/…` mit englischen Segmenten | 301 |

Links **in Beiträgen** auf alte Thema-Adressen werden beim Rendern nicht umgeschrieben (Quelle bleibt unverändert);
die Weiterleitung greift beim Aufruf.

### 4. Einstellungen

Wichtigster Befund: Von den 43 Standardschlüsseln in `config/config.php` liest der Plugin-Code nur **5**:
`flood_interval_post`, `flood_interval_thread`, `members_can_edit_time`, `threads_per_page`, `posts_per_page`.
Die Admin-Seite speichert zudem teils **andere Schlüssel** (`forum_name`, `guest_can_read`, `enable_attachments`,
`enable_bbcode`, `enable_likes`, `enable_polls`, `enable_signatures`, `auto_subscribe_own`, `enable_dark_mode`,
`require_post_approval`, `max_attachment_size`, `allowed_extensions`, `max_title_length`, `min_post_length`,
`max_post_length`, `primary_color`), die ebenfalls nirgends gelesen werden.

**Folge für Betreiber heute:** „Gäste dürfen lesen“, „Beiträge erst nach Freigabe“, Anhang-Grenzen, Längenprüfungen,
Wortfilter, automatisches Schließen, Zusammenfassungs-Mails und „Wer ist online“ zeigen im Plugin keine Wirkung.
Nach der Übernahme **wirken** diese Einstellungen. Der Migrator zeigt deshalb vor dem Lauf eine Liste
„Verhalten ändert sich“ (z. B. „Freigabepflicht war eingestellt, aber aus – ist nach der Übernahme aktiv“) und
lässt jede Abweichung bestätigen oder auf den bisherigen tatsächlichen Zustand setzen.

| Plugin-Schlüssel (Config / Admin) | Wirkung im Plugin | Core-Schlüssel (`community.*`) |
|---|---|---|
| `forum_title` / `forum_name`, `forum_description` | keine | `title`, `description` |
| `forum_enabled` | keine | Modulstatus `CoreModuleService` |
| `threads_per_page`, `posts_per_page` | ✅ | `topics_per_page`, `posts_per_page` |
| `min_/max_title_length`, `min_/max_post_length` | keine | `title_min/max`, `post_min/max` |
| `guests_can_read` / `guest_can_read`, `guests_can_search` | keine (Rechtematrix entscheidet) | Rechte-Standard `guest.read`, `guest.search` |
| `members_can_post`, `members_can_create_threads`, `members_can_upload`, `members_can_delete_own` | keine | Rechte-Standards `role:member` |
| `members_can_edit_time` | ✅ | `edit_window_minutes` (je Stufe überschreibbar) |
| `flood_interval_post`, `flood_interval_thread` | ✅ | `rate.post_seconds`, `rate.topic_seconds` |
| `attachments_enabled` / `enable_attachments`, `max_attachment_size`, `max_attachments_per_post`, `allowed_extensions` | keine | `attachments.enabled`, `.max_kb`, `.max_per_post`, `.types` |
| `polls_enabled` / `enable_polls`, `max_poll_options`, `poll_change_vote` | keine | `polls.enabled`, `.max_options`, `.allow_change` |
| `bbcode_enabled` / `enable_bbcode`, `smileys_enabled`, `img_tag_enabled`, `video_tag_enabled` | keine | Editor-Einstellungen (`editor.emoji`, `editor.images`) |
| `enable_likes` | keine | `reactions.enabled`, `reactions.set` |
| `enable_signatures` | keine | `signatures.enabled` |
| `auto_subscribe_own` | keine | Standard je Mitglied `auto_subscribe` |
| `email_notifications`, `notification_digest` | keine | `notifications.email_default`, `.digest_default` |
| `primary_color`, `border_radius`, `card_bg`, `enable_dark_mode` | keine | entfällt (Theme) |
| `show_online_users`, `show_forum_stats`, `show_breadcrumbs` | keine | `index.show_online`, `index.show_stats` (Brotkrumen immer) |
| `seo_title_format`, `seo_noindex_empty` | keine | `seo.title_format`, `seo.noindex_empty` |
| `auto_lock_days` | keine | `auto_close_days` (global, je Forum überschreibbar) |
| `censor_words`, `censor_replacement` | keine | `wordfilter.words`, `.mode` (ersetzen/sperren), `.replacement` |
| `require_approval` / `require_post_approval` | keine | `approval.default`, je Forum `require_approval` |

### 5. Migrator `ForumPluginMigrator`

Basis `PluginMigration\PluginMigrator` (wie Feed, Downloads, Kontakt, Wissensdatenbank, Newsletter):

1. **Erkennung:** Tabellen `{prefix}cmsforum_*` vorhanden (Plugin aktiv oder nur installiert); Plugin-DB-Version ≥ 1.0.0; Spaltenvariante `cmsforum_poll_options.text` bzw. `option_text`.
2. **Vorschau / Probelauf:** Zählungen je Tabelle, Waisen (Beiträge ohne Thema, Themen ohne Forum, Anhänge ohne Datei), Benutzer-IDs ohne Konto (→ anonymisiert), Slug-Konflikte mit Seiten/Beiträgen unter `/forum/…`, Liste „Verhalten ändert sich“ (§4).
3. **Reihenfolge:** Einstellungen → Ränge → Kategorien → Foren (Baum, `path`/`depth`) → Rechte → Themen → Beiträge (Batch 1.000, BBCode bleibt Quelle, `body_html` per Renderer) → Umfragen → Likes → Abos → Lesestatus → Anhänge (Dateien kopieren, SHA-256) → Meldungen → Protokoll → Mitgliederdaten/Sperren → Zähler neu berechnen → Weiterleitungen.
4. **Idempotenz:** `legacy_id` je Zieltabelle; zweiter Lauf überspringt bekannte Zeilen und zieht neue nach (Plugin kann bis zur Abschaltung weiterlaufen).
5. **Fortschritt:** `BatchJobService` mit Anzeige im Admin; Abbruch und Fortsetzen möglich.
6. **Bericht:** Zahlen je Tabelle (Quelle/Ziel/übersprungen/Fehler), Downloadbar als CSV; Audit-Log-Eintrag.
7. **Abschluss:** `IntegratedPluginService` deaktiviert `cms-forum` erst, wenn die Migrator-Klasse vorhanden **und** ein vollständiger Lauf ohne Fehler protokolliert ist; Plugin-Tabellen und -Dateien bleiben, Löschen nur über „Plugin-Daten entfernen“ mit Bestätigung.

### 6. Prüfung der Übernahme

Mit einer Kopie echter Plugin-Daten (oder Beispieldaten aus dem Plugin-Installer + Zufallsdaten):
Zahlen je Tabelle gleich, Stichprobe von 50 Themen inhaltlich gleich gerendert (BBCode), alle alten URLs 301 auf
erreichbare Ziele, Rechte je Forum für Gast/Mitglied/Moderator gleich oder bewusst geändert (Bericht §4), Anhänge
abrufbar mit gleichen Prüfsummen, zweiter Lauf ohne Änderung. Siehe [TEST-PLAN.md](TEST-PLAN.md) Suite `migration`.
