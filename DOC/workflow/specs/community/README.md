# 365CMS – Projektdokumentation | Abschnitt: Workflow – Spec: Community-Modul (Forum)
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.2 · Schema v27 | **Status:** Planung · noch kein Code | **Update:** 2026-11-14
> **Ersetzt:** [FORUM-PLUGIN-WORKFLOW.md](../../FORUM-PLUGIN-WORKFLOW.md) (Integrationsfahrplan aus 3.5.0) · **Muster:** [PLUGIN-INTEGRATION.md](../PLUGIN-INTEGRATION.md), [KLARSTELLUNGEN.md](../KLARSTELLUNGEN.md)

## English (summary)

Planning set for a **Community module** in the 365CMS core: a phpBB/bbPress-style discussion forum with categories, sub-forums, topics, posts, moderation, reports, notifications, reactions, profiles, read tracking, attachments and GDPR tooling. It replaces the separate plugin `cms-forum` (365CMS.DE-PLUGINS, v3.0.4, ~11,000 lines, 17 tables `cmsforum_*`) the same way 4.0.0 absorbed cms-feed, cms-downloads, cms-contact, cms-knowledgebase and cms-newsletter: core module with data migrator, URL compatibility and plugin retirement. The documents below define features (with IDs and acceptance criteria), architecture, data model, permissions and moderation, workflows, security and privacy, theme/UI, migration from cms-forum, roadmap with work packages, and the test plan. Nothing is implemented yet; open decisions are listed in §4 and must be confirmed before work package 1 starts.

## Deutsch

### 1. Ziel

Jede 365CMS-Installation bekommt ein vollwertiges Community-Forum **im Core**, ohne Plugin:
Kategorien und Unterforen, Themen und Beiträge, sicherer Editor, Moderation, Melden, Rechte je Bereich,
Benachrichtigungen, Reaktionen, Profile, Gelesen-Status, Anhänge und DSGVO-Funktionen.

Das Modul baut auf vorhandenen Core-Diensten auf, statt sie nachzubauen (Benutzer, Rollen und Gruppen,
Mitgliederbereich, Benachrichtigungen, Nachrichten, Mail-Warteschlange, Antispam, Purifier, Suche,
Papierkorb, Löschfristen, privater Speicher, Admin-Menü). Das bisherige Plugin `cms-forum` wird mit
Datenübernahme abgelöst; seine öffentlichen Adressen bleiben erreichbar.

### 2. Dokumente

| Dokument | Inhalt |
|---|---|
| [FEATURES.md](FEATURES.md) | Funktionskatalog mit IDs (`COM-…`), Priorität, Akzeptanzkriterien, Core-Baustein, Phase, Ist-Stand in `cms-forum` |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Modulschnitt, Verzeichnisse, Dienste, Routen, Hooks, Anbindung an Core-Dienste, Rendering, Caching, Performance |
| [DATA-MODEL.md](DATA-MODEL.md) | Tabellen `community_*`, Spalten, Indizes, Zähler, Schema-Stand, Zuordnung der Plugin-Tabellen `cmsforum_*` |
| [PERMISSIONS-MODERATION.md](PERMISSIONS-MODERATION.md) | Rollen und Rechte (RBAC), Rechte je Bereich, Moderationswerkzeuge, Meldungen, Verwarnungen, Sperren, Freigabe-Warteschlange, Vertrauensstufen |
| [WORKFLOWS.md](WORKFLOWS.md) | Abläufe für Gäste, Mitglieder, Moderation und Administration, Schritt für Schritt mit Diagrammen |
| [SECURITY-PRIVACY.md](SECURITY-PRIVACY.md) | Bedrohungsmodell, Schutzmaßnahmen (XSS, CSRF, SQL, Uploads, Spam, Bots), DSGVO, Löschfristen |
| [THEME-UI.md](THEME-UI.md) | Vorlagen, Theme-Overrides, Komponenten, Mobile-First, Barrierefreiheit, SEO, Auswirkungen auf `cms-phinit` |
| [MIGRATION-CMS-FORUM.md](MIGRATION-CMS-FORUM.md) | Paritäts-Checkliste Plugin → Core, URL-Zuordnung, Datenübernahme, Plugin-Abschaltung |
| [ROADMAP.md](ROADMAP.md) | Phasen, Arbeitspakete mit Abhängigkeiten und Aufwand, Fortschritts-Checkliste |
| [TEST-PLAN.md](TEST-PLAN.md) | Test-Suiten, Prüffälle, Gegenproben, Laufzeit- und Browserprüfungen |

Lesereihenfolge für die Umsetzung: README → FEATURES → ARCHITECTURE → DATA-MODEL → PERMISSIONS-MODERATION →
SECURITY-PRIVACY → ROADMAP. WORKFLOWS, THEME-UI und TEST-PLAN begleiten die jeweiligen Arbeitspakete.

### 3. Ausgangslage (14.11.2026)

| Punkt | Befund |
|---|---|
| Core | 365CMS **4.0.0**, Schema **v25** (`SchemaManager::SCHEMA_VERSION`). Kein Forum im Core. `PLUGIN-PARITY.md` führt `cms-forum` ausdrücklich als eigenständiges Plugin. |
| Plugin `cms-forum` | Repository `PS-PhinIT/365CMS.DE-PLUGINS`, Version 3.0.4, DB-Version 1.0.2, ca. 10.900 Zeilen PHP. 17 Tabellen `{prefix}cmsforum_*`, 15 Routen unter `/forum` (je mit `/en/`), BBCode-Editor, Rechte je Forum (nur Gast/Mitglied/Moderator), Umfragen, Anhänge, Likes, Abos, Meldungen, Ränge, Moderationsprotokoll, „Gelöst“-Markierung, Doppel-Themen-Vorschläge, DSGVO-Hooks, Mitgliederbereich. **Nur 5 von 43 Einstellungen wirken** (Details: [MIGRATION-CMS-FORUM.md](MIGRATION-CMS-FORUM.md) §4). |
| Core-Bausteine | Registrierung/Login/Passwort/E-Mail-Bestätigung (`CmsAuthPageService`, `EmailVerificationService`), Rollen und Gruppen (`roles`, `user_groups`), Profile mit Avatar (`MemberService`), Benachrichtigungen (`notifications`), Nachrichten 1:1 (`MessageService`), Favoriten, Mail-Warteschlange, `AntispamService` (Honeypot, Mindestzeit, Link-Limit), `Security::checkDbRateLimit()`, `PurifierService`, Suche (TNTSearch, `SearchDocumentProvider`), Papierkorb (`TrashProvider`), Löschfristen (`DataRetentionService::registerRule`), `PrivateStorageService`, `ImageService`, Admin-Menü-Katalog, `BadgeProvider`, `CoreModuleService`, `IntegratedPluginService` + `PluginMigrator`, `PluginDashboardRegistry` für den Mitgliederbereich, DSGVO-Export und -Löschantrag. |
| Lücken im Core | Kein Captcha-Anbieter (nur die Rechenaufgabe der Formulare in `FormSpamGuard`), kein OAuth/Social Login, keine Gruppen-Nachrichten, kein Markdown-Parser, kein Syntax-Highlighting. |
| Theme `cms-phinit` | hat `member/forum.php` (Weiterleitung auf den Plugin-Mitgliederbereich bzw. Hinweis „Plugin nicht aktiviert“) – muss auf das Core-Modul umgestellt werden ([THEME-UI.md](THEME-UI.md) §7). |

### 4. Grundsatzentscheidungen

Alle Punkte sind entschieden (Rückmeldung vom 14.11.2026, eingetragen in der Spalte „Entscheidung“). Wo die Wahl
offen gelassen wurde („was am besten passt“), steht die getroffene Festlegung; Versionen und Lizenzen der Assets
werden in WP-C0 noch einmal am Repository geprüft.

| # | Frage | Entscheidung | Begründung | Status |
|---|---|---|---|---|
| E1 | Core-Modul oder Plugin? | **Core-Modul** `community`, abschaltbar über `CoreModuleService` | wie Feed, Downloads, Formulare, Wissensdatenbank, Newsletter in 4.0.0 | gesetzt |
| E2 | Basis-Adresse | **`/forum`** als Standard, im Admin änderbar | lesbar, üblich | gesetzt |
| E3 | Adressen für Themen | **`/forum/thema/{id}-{slug}`** (DE), **`/en/forum/topic/{id}-{slug}`** (EN); Auflösung über die ID, falscher Slug → 301 | ID macht die Adresse robust gegen Titeländerungen, Slug sorgt für Lesbarkeit und SEO – Muster großer Foren (Discourse, phpBB mit SEO-URLs) | gesetzt |
| E4 | Speicherformat und Editor | **Markdown (CommonMark + GFM), kein Roh-HTML**; Werkzeugleiste, Vorschau, Bild-Upload als **eigener Baustein** (Vanilla-ES-Modul, Tabler-Tokens, kein externer Editor) | sicher, leichtgewichtig, ohne Build-Schritt | gesetzt |
| E5 | Markdown- und Highlighting-Bibliothek | **`league/commonmark`** (serverseitig) und **`highlight.js`** (nur benötigte Sprachen) als Assets unter `CMS/assets/`, über `VendorRegistry` geführt; **keine externen Quellen/CDNs** | beide verbreitet, MIT bzw. BSD-3; Highlighting nur auf Seiten mit Code geladen | gesetzt |
| E6 | Daten aus `cms-forum` | **Keine Übernahme**: Das Plugin war nirgends aktiv. Kein Migrator, keine Plugin-Weiterleitungen, kein BBCode-Altbestand; [MIGRATION-CMS-FORUM.md](MIGRATION-CMS-FORUM.md) dient nur noch als Funktions-Referenz | spart Arbeitspaket WP-C12 | gesetzt |
| E7 | Captcha | **`gregwar/captcha`** (MIT, gepflegt) als Asset für das Bild-Captcha **plus** eingebaute Rechenaufgabe als barrierefreie Alternative; Honeypot, Mindestzeit, Rate-Limits davor. **Keine Drittanbieter** (Turnstile, hCaptcha, reCAPTCHA) – passt zu „nichts extern“ | lokal, DSGVO ohne Einwilligung | gesetzt |
| E8 | Private Nachrichten | **Core-`MessageService`**; Gruppen-Konversationen als Erweiterung des Core-Dienstes, nichts Forum-eigenes | ein Postfach für alle Mitglieder | gesetzt |
| E9 | OAuth / Social Login | **Core-Funktion** (in 4.1.0: Entra-ID-SSO, [../v4.1/01-IDENTITY.md](../v4.1/01-IDENTITY.md)); die Community nutzt sie | Anmeldung gilt für die ganze Site | gesetzt |
| E10 | Mandanten / MultiSite | keine `site_id` ([KLARSTELLUNGEN.md](../KLARSTELLUNGEN.md) §3) | verbindlich aus 4.0.0 | gesetzt |
| E11 | Version und Schema | **Release 4.2**, eigener Schema-Stand **v27** (4.1.0 belegt v26, [../v4.1/](../v4.1/README.md)) | Roadmap 4.1.0 kommt zuerst | gesetzt |
| E12 | Ablage der Tests | **`TESTS/<suite>/run.php`** mit `TESTS/lib/TestRunner.php` (in `main` wiederhergestellt) | wie die übrigen Core-Suiten | gesetzt |

### 5. Nicht-Ziele (in diesem Vorhaben)

- Echtzeit-Chat oder WebSockets (Aktualisierung über Abfragen in Intervallen reicht; siehe COM-INT-09).
- Eigene Benutzerverwaltung, eigenes Login oder eigene Profile neben dem Core.
- Forum-eigene Nachrichten- oder Benachrichtigungstabellen neben `messages` und `notifications`.
- Mandantenfähigkeit je Site.
- Native Apps; die mobile Nutzung läuft über das responsive Frontend.

### 6. Begriffe

| Begriff | Bedeutung | Plugin `cms-forum` |
|---|---|---|
| Kategorie | Oberste Gliederung auf der Startseite der Community, enthält Foren | `cmsforum_categories` |
| Forum | Bereich mit Themen; kann Unterforen haben (Baum) | `cmsforum_forums` (`parent_id`) |
| Thema (Topic) | Diskussion mit Titel und erstem Beitrag | `cmsforum_threads` |
| Beitrag (Post) | Einzelne Antwort in einem Thema; der erste Beitrag gehört zum Thema | `cmsforum_posts` |
| Moderation | Rechte und Werkzeuge zum Prüfen, Verschieben, Sperren usw. | `can_moderate`, `cmsforum_mod_log` |
| Vertrauensstufe | Stufe eines Mitglieds (0–4), die Grenzen und Freigaben steuert | im Plugin nur geplant (FeatureList Nr. 2) |
