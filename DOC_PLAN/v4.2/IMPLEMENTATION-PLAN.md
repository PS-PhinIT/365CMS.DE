# 365CMS Projektdokumentation | Community: Umsetzungsfahrplan 4.2.0
> **Stand:** 2026-11-23 | **Auslieferung:** 4.2.0 | **Status:** ✅ historischer Umsetzungsplan vollständig eingepflegt | **Aktueller Fortschritt:** [ROADMAP.md](ROADMAP.md)

> **Stand:** 2026-11-18 | **Ziel-Version:** 4.2.0 · Schema **v27** | **Status:** PR #1 geliefert (Schema v27, `capabilities_v27`, Rolle `moderator`), PR #2a geliefert (Fundament der Dienste: Modul, Einstellungen, `CommunityAccess`, Themen und Beiträge, Lesestatus), PR #2b bis #5 offen; Abweichungen von diesem Plan: Übergabe §7 · Übergabe: [HANDOFF-PR2-PR5.md](HANDOFF-PR2-PR5.md) | **Übersicht:** [README.md](README.md)
> **Quellen:** [ROADMAP.md](ROADMAP.md) · [DATA-MODEL.md](DATA-MODEL.md) · [PERMISSIONS-MODERATION.md](PERMISSIONS-MODERATION.md) · [SECURITY-PRIVACY.md](SECURITY-PRIVACY.md) · [TEST-PLAN.md](TEST-PLAN.md) · [ARCHITECTURE.md](ARCHITECTURE.md)

## English (summary)

Single entry point for building the **Community module** (forum) in release **4.2.0** with schema **v27**. It condenses the planning set into an executable plan: the starting point after 4.1.0 (schema `v26`, 90 tables), the reusable 4.1.0 building blocks, the work packages WP-C0 … WP-C13 for phase P1 (the 4.2.0 release) with deliverables, security gate and tests per package, the 27 planned `community_*` tables (90 + 27 = 117 tables in `v27`), the capabilities `community.*`, privacy and moderation rules, CSP and security requirements and the test suites that must be green before release. Phases P2 (WP-C14, WP-C15) and P3 (WP-C16) follow after 4.2.0. CLI and real-time alerting are **not** part of 4.2.0; they move to 4.3.0 ([../v4.1/06-CLI-ALERTING.md](../v4.1/06-CLI-ALERTING.md)).

## Deutsch

### 1. Ausgangslage nach 4.1.0

| Punkt | Stand |
|---|---|
| Core | 365CMS **4.1.0**, Schema **v26** (90 Tabellen, `SchemaManager::FEATURE_TABLES_V26`), PHP ≥ 8.4.1; `Version::CURRENT = '4.1.0'`, `SCHEMA_VERSION = 'v26'` ([../../../core/STATUS.md](../../../DOC/core/STATUS.md)). |
| Schema v27 | Eigener Stand **v27** für alle Community-Tabellen; Definitionen nur in `SchemaManager::getSchemaQueries()`, Anlage über `MigrationManager::run()` und `ensureFeatureSchema()` (idempotent, wie v25/v26). |
| Release 4.2.0 | Phase **P1** (WP-C0 … WP-C13, ≈ 89 PT). P2 und P3 folgen in Folgereleases. |
| Nicht Teil von 4.2.0 | CLI und Echtzeit-Alerting (→ **4.3.0**), Datenübernahme aus `cms-forum` (entfällt, E6), Mandanten/`site_id` (E10). |

### 2. Wiederverwendbare Bausteine aus 4.1.0

| Baustein (4.1.0) | Nutzung in der Community |
|---|---|
| `Security\MfaEnforcementService`, `PasswordPolicyService`, Entra-SSO (E9) | Anmeldung und Konten bleiben Core-Sache; die Community fügt keine eigene Anmeldung hinzu. Das Recht `community.manage` lässt sich per MFA-Zwang für Rollen absichern. |
| `Content\ContentStatus` / Vier-Augen-Muster | Muster für die Freigabe-Warteschlange (Status `pending`, Verlauf in `community_moderation_log`), nicht dieselben Tabellen. |
| `Media\MediaReferenceExtractor` (`cms_register_media_reference_extractors`) | Extraktor `Community` meldet Anhänge/Bilder aus Beiträgen an den Verwendungsnachweis. |
| `Webhooks\WebhookService` + Filter `cms_webhook_event` | Ereignisse `community.topic_created`, `community.post_reported` u. a. (P3, WP-C16); Zustellung unverändert signiert. |
| `Backup\OffsiteBackupService` | Keine Änderung; Community-Tabellen und privater Speicher (`community/uploads/`) kommen automatisch in die Backups. |
| `ReusableBlocks`, `<picture>`-Ausgabe | Anhänge-Bilder nutzen dieselbe AVIF/WebP-Ausgabe (`display: contents`); Beitrags-HTML selbst enthält kein Roh-`<picture>`. |
| Hooks `user_role_changed` (seit 4.1.0 nur im SSO-Pfad gefeuert), `cms_sso_user_provisioned`, `user_registered`, `user_email_verified` | Rechte-Cache leeren, neue Konten ohne Vertrauensstufe. **Lücke:** `user_deleted` existiert noch nicht als Hook (`UserService::deleteUser()` schreibt nur den Audit-Eintrag) und `user_role_changed` fehlt in `UserService`/Rollenverwaltung: beides ist in WP-C1 als Core-Änderung einzuplanen (bis dahin: `CommunityAccess` prüft Rolle je Anfrage frisch, täglicher Cron anonymisiert Beiträge gelöschter Konten, [ARCHITECTURE.md](ARCHITECTURE.md) §6). |
| `FeatureHooks\Registry`, `CoreModuleService`, `BatchJobService`, `DataRetentionService`, `PrivateStorageService`, Trash-/Search-Provider | Wie bei Formularen/Downloads/Knowledgebase aus 4.0.0 (siehe [ARCHITECTURE.md](ARCHITECTURE.md) §5). |

### 3. Entscheidungen (verbindlich, [README.md](README.md) §4)

Core-Modul `community`, abschaltbar (E1) · Basis `/forum` (E2) · Adressen `/forum/thema/{id}-{slug}` bzw. `/en/forum/topic/{id}-{slug}` (E3) · Markdown (CommonMark + GFM) ohne Roh-HTML (E4) · `league/commonmark` und `highlight.js` lokal (E5) · keine Datenübernahme (E6) · Captcha nur lokal: `gregwar/captcha`, eingebaute Rechenaufgabe, Proof-of-Work, **keine** Drittanbieter (E7) · private Nachrichten über den Core-`MessageService` (E8) · keine `site_id` (E10) · Schema v27 (E11) · Tests unter `TESTS/<suite>/run.php` (E12).

### 4. Arbeitspakete Phase P1 (Release 4.2.0)

Reihenfolge und Abhängigkeiten: `WP-C0 → C1 → C2 → C3`, danach `C4 · C5 · C7 · C8 · C9 · C10` (parallel), `C5 → C6`, `C3 + C8 → C11`, alles → `C13`. Aufwand in Personentagen (±30 %) aus [ROADMAP.md](ROADMAP.md) §2. Ein Paket gilt erst mit Testnachweis als fertig; Fortschritt in [ROADMAP.md](ROADMAP.md) §5.

| WP | Lieferumfang (Dateien/Dienste) | Sicherheits-Gate | Tests (Suite) | PT |
|---|---|---|---|---|
| **C0** | Entscheidungen bestätigen; Bibliotheken prüfen (CommonMark, highlight.js, `gregwar/captcha`, Proof-of-Work): Lizenz, PHP 8.4/8.5, CSP, SHA-256 in `DOC/assets/` | keine CDN-Einbindung, Vendoring über `VendorRegistry` | – | 2 |
| **C1** | `CoreModuleService`-Eintrag `community`, `FeatureHooks\CommunityHooks`, Core-Hooks `user_deleted` und `user_role_changed` (in `UserService`/`RolesModule`), `Support/Settings` (Gruppe `community`, Typen/Grenzen), Sprachschlüssel `community.*`, Admin-Katalog-Einträge (ausgeblendet bis Seiten existieren), **Schema v27** (alle 27 Tabellen), Update-Schritt `capabilities_v27` + Rolle `moderator` | Capabilities nur per Update-Schritt, niemand wird ausgesperrt | `schema-v27` (Neuinstallation = Update, Tabellenzahl 117), `settings` | 5 |
| **C2** | Kategorien-/Foren-Baum, Admin „Struktur“ (Drag & Drop **und** Tastatur), Forentypen/-status, Rechte-Matrix mit Vererbung, Forum-Moderatoren, `CommunityAccess` als einzige Entscheidungsstelle | 404 statt 403; Rechte nur über `CommunityAccess` | `access` (Matrix Teil 1) | 8 |
| **C3** | Themen/Beiträge: erstellen, antworten, zitieren, bearbeiten mit Verlauf, weich löschen; Zähler (`community_user_stats`, Themen-/Forenzähler); Seitennavigation (Keyset statt `OFFSET`); Startseite, Forum, Thema, „Neu/Aktiv/Unbeantwortet“; Permalinks; Gelesen-Status | POST + CSRF + Rate-Limit; Idempotenz bei Doppel-Submit | `content`, `access` | 12 |
| **C4** | Markdown-Renderer (Link-Allowlist `http`, `https`, `mailto`, relativ), Purifier-Profil `community`, Code-Highlighting, Vorschau-Endpunkt (nur angemeldet), Werkzeugleiste (ES-Modul), lokale Entwürfe | kein Roh-HTML; Ausgabe nur nach `PurifierService`; `rendered_version` | `render`, `xss` (Korpus ≥ 30) | 7 |
| **C5** | `CaptchaService` (eingebaut, Proof-of-Work, Bild), Antispam-Schichten (Honeypot, Mindestzeit, Link-/Bildgrenzen je Stufe), Rate-Limits/Langsamer Modus, Wortfilter, Freigabe-Warteschlange | Fehlender Schlüssel/Anbieter fällt auf „eingebaut“ zurück (kein stilles Abschalten) | `spam` | 8 |
| **C6** | Moderation: schließen, anpinnen, Ankündigung, verschieben, ausblenden/wiederherstellen, Beiträge verschieben, Massenaktionen, Meldungen (Schwelle für vorläufiges Ausblenden), Sperren, Protokoll, Badges, Admin-Seiten | Pflichtgrund, Protokolleintrag je Aktion; Moderatoren sperren keine gleich/höher Berechtigten | `moderation` | 9 |
| **C7** | Abos, In-App-Benachrichtigungen (Core `notifications`), Sofort-Mails über die Mail-Warteschlange, Reaktionen, „Lösung markieren“, Ränge, Profil-Abschnitt, „Nachricht senden“ (Core-Postfach), Mitgliederbereich `community` | Rechtsprüfung je Empfänger (kein Leak interner Foren), max. Erwähnungen, eine Mail je Thema bis zum Besuch | `notify` | 9 |
| **C8** | Anhänge: Upload, Allowlist + `finfo`, Bildneukodierung, privater Speicher, Auslieferung mit Rechten/Headern, Vorschaubilder, verwaiste Uploads (24 h) | kein SVG, Polyglot-Prüfung, EXIF entfernt, Ablage außerhalb des Webroots | `uploads` | 5 |
| **C9** | Suche (`SearchDocumentProvider`, Filter, Rechtefilter), ähnliche Themen, Sitemap-Provider ohne interne Foren, kanonische URLs, 301 bei falschem Slug (`community_redirects`), Schema.org, `noindex`-Regeln | interne Foren nie in Suche/Feed/Sitemap/Cache | `search-seo`, `access` | 6 |
| **C10** | Core-Vorlagen und Theme-Overrides, `community.css` + JS-Module (kein Build-Schritt), Mobile-First, Barrierefreiheit, Dark Mode; **Theme-PR `cms-phinit`** ([THEME-UI.md](THEME-UI.md) §7) | CSP-konform (Abschnitt 8) | `browser`, `a11y` | 8 |
| **C11** | DSGVO-Hooks `dsgvo_export_data`/`dsgvo_delete_data`, Anonymisierung, Löschfristen-Regeln (Abschnitt 7), Legal-Textbausteine, Papierkorb-Provider | keine Klar-IP in Community-Tabellen | `privacy` | 4 |
| **C13** | Abfragebudget, Cache, Lasttest (10.000 Themen / 500.000 Beiträge), Lighthouse ≥ 90, vollständiger TEST-PLAN P1, Doku (`DOC/admin/community/`, `DOC/member/`, Hooks-Referenz, `DATABASE-SCHEMA.md`, Changelog), Release-Pakete | alle Checklisten aus Abschnitt 8 abgehakt | `performance`, `lighthouse`, alle | 6 |
| | **Summe P1** (C12 entfällt, E6) | | | **≈ 89** |

**Meilensteine:** M1 *Lesbar* (C1–C3) · M2 *Sicher* (C4–C6, C8: Rechte-Matrix und XSS-Korpus bestanden) · M3 *Funktionsumfang* (C7, C9–C11) · M4 *Release 4.2.0* (C13).

### 5. Geplante Datenbanktabellen (Schema v27)

27 Tabellen mit Präfix `cms_`; Einstellungen liegen in `settings` (Gruppe `community`), **keine** eigene Tabelle. Spalten, Indizes und Zähler: [DATA-MODEL.md](DATA-MODEL.md). Gesamtstand v27: 90 + 27 = **117 Tabellen**.

| Bereich | Tabellen |
|---|---|
| Struktur (4) | `community_categories`, `community_forums`, `community_forum_permissions`, `community_forum_moderators` |
| Inhalte (5) | `community_topics`, `community_posts`, `community_post_revisions`, `community_attachments`, `community_drafts` |
| Interaktion (8) | `community_reactions`, `community_subscriptions`, `community_read_states`, `community_forum_read_marks`, `community_polls`, `community_poll_options`, `community_poll_votes`, `community_ignores` |
| Mitglieder (4) | `community_user_stats`, `community_ranks`, `community_badges`, `community_user_badges` |
| Moderation (4) | `community_reports`, `community_warnings`, `community_bans`, `community_moderation_log` |
| Betrieb (2) | `community_stats_daily`, `community_redirects` |

Alle Tabellen entstehen mit WP-C1 (damit Neuinstallation und Update identisch sind); Tabellen für P2/P3 (Umfragen, Ränge/Abzeichen, Verwarnungen, Ignorieren) bleiben bis WP-C14/C15/C16 ohne Oberfläche. Pflicht: `TESTS/schema-v27` vergleicht Neuinstallation und Update (Vorbild `TESTS/schema-v26`), alle Wege nutzen dieselbe Definition.

### 6. Capabilities und Rollen

Neue Gruppe „Community“ in `RolesModule::CAPABILITY_GROUPS`; ein einmaliger Update-Schritt (`capabilities_v27`, Marker in `settings`) vergibt die Standards. Details: [PERMISSIONS-MODERATION.md](PERMISSIONS-MODERATION.md) §2.

| Capability | Wirkung | Standard |
|---|---|---|
| `community.view` | Community sehen (Gäste über Einstellung „Gäste dürfen lesen“) | member, editor, author, moderator |
| `community.participate` | Schreiben (zusätzlich zu den Forum-Rechten) | member, editor, author, moderator |
| `community.moderate` | globale Moderation, Warteschlange, Meldungen, Verwarnungen, befristete Sperren | moderator |
| `community.ban` | dauerhafte Sperren, fremde Sperren aufheben | admin |
| `community.manage` | Struktur, Rechte, Ränge/Abzeichen, Einstellungen, Statistik | admin |
| `community.view_private_data` | gekürzte IP-Kennung, Bearbeitungsverlauf gelöschter Beiträge | admin |

Neue Standardrolle **`moderator`** (Anzeigename „Moderator“). Die Entscheidung trifft ausschließlich `CommunityAccess::can(user, action, context)` in der Reihenfolge Kontostatus → Community-Sperre → globale Capability → Forum-Recht → Vertrauensstufe; der Filter `cms_community_can` darf nur **einschränken**. Hinweis: `/admin` erreichen weiterhin nur Admins ([../../admin/users-groups/RBAC.md](../../../DOC/admin/users-groups/RBAC.md)); Moderation für andere Rollen läuft deshalb im Frontend/Mitgliederbereich.

### 6a. Hooks (Auswahl, Namensschema `cms_community_*`)

`cms_community_topic_created|updated|deleted|moved|merged`, `cms_community_post_created|updated|deleted|approved|rejected`, `cms_community_report_created|resolved`, `cms_community_user_warned|banned|unbanned`, `cms_community_solution_marked`; Filter `cms_community_render_post`, `cms_community_can`, `cms_community_spam_check`, `cms_community_notification_types`, vollständig in [ARCHITECTURE.md](ARCHITECTURE.md) §6; bei Umsetzung in [../../core/HOOKS-REFERENCE.md](../../../DOC/core/HOOKS-REFERENCE.md) aufnehmen.

### 7. Datenschutz- und Moderationsregeln

**Datenschutz** ([SECURITY-PRIVACY.md](SECURITY-PRIVACY.md) §5):

- Keine Klar-IP in Community-Tabellen; an Beiträgen nur ein HMAC einer gekürzten IP (`ip_hash`), nur für Moderation (Recht `community.view_private_data`), Löschfrist 30 Tage.
- Export (`dsgvo_export_data`) vollständig; Löschung (`dsgvo_delete_data`): Anonymisieren (Standard) oder Löschen, Zitate werden neutralisiert, Anzeige „Gelöschtes Mitglied“, Profil 404. Löschantrag und Kontolöschung wirken gleich.
- „Wer ist online“ nur mit Zustimmung; externe Bilder in Beiträgen standardmäßig aus; die Community setzt keine eigenen Cookies (lokale Entwürfe in `localStorage` im Datenschutztext nennen); Rechtsgrundlagen: Vertrag (lit. b), berechtigtes Interesse für Spam-Schutz/Moderation (lit. f).
- Löschfristen über `DataRetentionService::registerRule`: `community.ip_hash` 30 Tage · `community.read_states` 180 Tage · `community.orphan_uploads` 24 Stunden · `community.drafts` 30 Tage · `community.reports` 365 Tage · `community.moderation_log` 365 Tage · `community.warnings` 2 Jahre · `community.revisions` 365 Tage (letzte Fassung bleibt).

**Moderation** ([PERMISSIONS-MODERATION.md](PERMISSIONS-MODERATION.md) §5–§9):

- Jede Moderationsaktion: POST + CSRF + `CommunityAccess` + Eintrag im `community_moderation_log` (ohne IP) + Ereignis-Hook; Pflichtgrund bei ausblenden, ablehnen, verwarnen, sperren.
- Melden nur für angemeldete Nutzer, Gründe `spam`, `abuse`, `offtopic`, `illegal`, `other`, höchstens 10 Meldungen/Stunde, Doppelmeldungen werden zusammengeführt; ab 3 offenen Meldungen von Mitgliedern mit Stufe ≥ 2 wird ein Beitrag vorläufig ausgeblendet (einstellbar).
- Verwarnungen (P2, WP-C14): 1–10 Punkte, Ablauf 90 Tage, automatische Folgen (3/5/8 Punkte) protokolliert und aufhebbar; Sperren `write` oder `full`, befristet wirkt ohne Cron.
- Moderatoren können niemanden mit gleicher oder höherer Rolle verwarnen oder sperren; Kontosperren der ganzen Site bleiben in der Core-Benutzerverwaltung.

### 8. CSP- und Sicherheitsvorgaben (Pflicht je Arbeitspaket)

- **CSP:** kein Inline-Skript und -Style, keine Inline-Handler; alle Skripte als ES-Module unter `CMS/assets/js/community/`, Styles unter `CMS/assets/css/`; Trusted-Types-konform (nur DOMPurify-Policy, kein `innerHTML` mit Nutzerdaten); keine externen Ressourcen und keine CDNs. Highlighting, Markdown und Captcha lokal (E5, E7). Neue Hosts nur über `Security::allowCspSources()` **und** Einwilligungsprüfung.
- **Rechte:** jede Route über `CommunityAccess`; 404 statt 403 bei fehlendem Leserecht; interne Foren nie in Suche, Feed, Sitemap, Zitaten, Benachrichtigungen, Anhängen oder öffentlichem Cache (Seiten mit Token/Personalisierung `private, no-store`).
- **Schreibzugriffe:** nur POST mit CSRF-Token je Aktion (`Security::generateToken`/`verifyToken`), JSON-API mit Token im Header, Rate-Limit (`Security::checkDbRateLimit`), Idempotenz; Weiterleitungsziele nur lokale Pfade.
- **SQL:** nur gebundene Parameter, Sortier-/Filterspalten aus Allowlists, Index je Abfrage, Keyset-Paginierung.
- **Ausgabe:** gespeichert wird die Markdown-Quelle, ausgegeben nur `body_html` nach dem Purifier-Profil `community`; externe Links `rel="nofollow ugc noopener"`.
- **Uploads:** Allowlist (Endung + `finfo`), Neukodierung, kein SVG, privater Speicher, Auslieferung mit Rechten und Sicherheits-Headern (`nosniff`, `Content-Disposition`).
- **Einstellungen:** jede Einstellung hat einen Leser im Code und einen Wirkungstest (keine wirkungslosen Schalter).
- **Neue Datenart:** Löschfrist, DSGVO-Export und -Löschung bedenken. Checkliste je Paket: [SECURITY-PRIVACY.md](SECURITY-PRIVACY.md) §6; CI: `security.yml` (Semgrep/Trivy) ohne neue Befunde.

### 9. Test-Anforderungen

Suiten unter `TESTS/<suite>/run.php` mit `TESTS/lib/TestRunner.php`, aufrufbar über `php TESTS/run-all.php` (alle Suiten `TESTS/*/run.php`; die aktuelle Zahl nennt die Ausgabe). Beschreibung und Prüffälle: [TEST-PLAN.md](TEST-PLAN.md).

| Suite | Prüft | Paket |
|---|---|---|
| `schema-v27` | Tabellen/Spalten, Neuinstallation = Update | C1 |
| `settings` | jede Einstellung hat Leser und Wirkungstest | C1 |
| `access` | Rechte-Matrix über **jede** Route und jeden Nebenweg × alle Rollen/Sperren | C2, C3, C9 |
| `content` | Erstellen, Antworten, Bearbeiten, Verlauf, Zähler, Seiten/Permalinks, Idempotenz | C3 |
| `render` | Markdown, Highlighting, Zitate, Links, `rendered_version` | C4 |
| `xss` | Korpus ≥ 30 Angriffe gegen Beitrag, Titel, Signatur, Profilfeld, Dateiname, Meldegrund, Suchbegriff | C4 |
| `spam` | Honeypot, Mindestzeit, Captcha (Fakes), Rate-Limits, Wortfilter, Warteschlange | C5 |
| `moderation` | alle Aktionen inkl. Protokoll, Pflichtgrund, Massenaktionen, Meldungen, Sperren | C6 |
| `notify` | Empfänger je Ereignis, Rechteprüfung, keine Eigenbenachrichtigung, Mail-Bündelung | C7 |
| `uploads` | Allowlist, Polyglot, SVG, Pixelgrenze, EXIF, Auslieferung mit Rechten | C8 |
| `search-seo` | Rechtefilter, kanonische URLs, 301, `noindex`, Sitemap ohne interne Foren | C9 |
| `privacy` | Export, Anonymisieren/Löschen, jede Löschfrist-Regel | C11 |
| `performance` | ≤ 6 Abfragen je Seite (warmer Cache), Lasttest, kein `OFFSET`-Scan | C13 |
| `browser` · `a11y` · `lighthouse` | Playwright (360/320 px, Dark Mode, Tastatur), axe-core ohne *serious/critical*, Lighthouse mobil ≥ 90 | C10, C13 |

**Freigabe-Kriterien Release 4.2.0** ([TEST-PLAN.md](TEST-PLAN.md) §4): alle P1-Suiten grün mit dokumentierten Gegenproben · Rechte-Matrix ohne Ausnahme, XSS-Korpus ohne Treffer · Abfragebudget und Lasttest erfüllt, Lighthouse ≥ 90 · `security.yml` ohne neue Befunde · Doku aktualisiert (`DOC/admin/community/`, `DOC/member/`, `HOOKS-REFERENCE.md`, `DATABASE-SCHEMA.md`, `FILELIST.md`, `INDEX.md`, Changelog) · Release-Vorbereitung: `Version::CURRENT`, `SCHEMA_VERSION = 'v27'`, `CMS/update.json`, Pakete mit SHA-256.

### 10. Phasen nach 4.2.0

| Phase | Pakete | Inhalt |
|---|---|---|
| **P2** | WP-C14 (8 PT), WP-C15 (12 PT) | Vertrauensstufen, Verwarnungen mit Schwellen, Zusammenführen/Teilen, Erwähnungen, Zusammenfassungs-Mails, Umfragen, Lesezeichen, Ignorieren, Wer ist online, Server-Entwürfe, Feeds, Statistik |
| **P3** | WP-C16 (15 PT + Core) | Reputation, Abzeichen, Tags/Präfixe, Webhooks (`community.*`-Ereignisse), KI-Signale (`cms_community_spam_check`), Gruppen-Nachrichten im Core |
| **4.3.0** | – | CLI `CMS/cli.php`, Echtzeit-Alerting, Webhook-Ereignis `alert.raised` ([../v4.1/06-CLI-ALERTING.md](../v4.1/06-CLI-ALERTING.md)); falls sinnvoll mit Community-Alarmen (Meldungs-Flut) verbinden |

### 11. Risiken (Kurzfassung)

Rechte-Fehler machen interne Foren sichtbar (→ nur `CommunityAccess`, Matrix-Test) · XSS über Markdown-Sonderfälle (→ kein Roh-HTML, Purifier danach, Korpus) · große Foren langsam (→ denormalisierte Zähler, Keyset, Lasttest) · neue Vendor-Bibliotheken (→ WP-C0, SHA-256, kein CDN) · Theme-Abhängigkeit `cms-phinit` (→ Theme-PR in WP-C10). Vollständig: [ROADMAP.md](ROADMAP.md) §4.

### Verwandte Dokumente

[README.md](README.md) · [ROADMAP.md](ROADMAP.md) · [FEATURES.md](FEATURES.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · [DATA-MODEL.md](DATA-MODEL.md) · [PERMISSIONS-MODERATION.md](PERMISSIONS-MODERATION.md) · [SECURITY-PRIVACY.md](SECURITY-PRIVACY.md) · [TEST-PLAN.md](TEST-PLAN.md) · [../v4.1/PROGRESS.md](../v4.1/PROGRESS.md) · [../../DEVLIST.md](../../../DOC/DEVLIST.md) · [../../core/DATABASE-SCHEMA.md](../../../DOC/core/DATABASE-SCHEMA.md)
