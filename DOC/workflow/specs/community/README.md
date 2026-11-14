# 365CMS – Projektdokumentation | Abschnitt: Workflow – Spec: Community-Modul (Forum)
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.1 (Vorschlag) | **Status:** Planung · noch kein Code | **Update:** 2026-11-14
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

Empfehlungen sind markiert; **offene Punkte** brauchen vor Arbeitspaket 1 eine Bestätigung.

| # | Frage | Empfehlung | Begründung | Status |
|---|---|---|---|---|
| E1 | Core-Modul oder Plugin? | **Core-Modul** `community`, abschaltbar über `CoreModuleService` | Gleiches Vorgehen wie bei Feed, Downloads, Formularen, Wissensdatenbank und Newsletter in 4.0.0; nutzt Core-Dienste direkt; ein Ort für Sicherheit und Updates | Modul |
| E2 | Name im Admin und in der URL | Admin: **„Community“**; öffentliche Basis **`/forum`** (einstellbar) | `/forum` hält alle Plugin-URLs ohne Umleitung gültig | /forum oder was der Admin eingestellt hat, std. aber /forum |
| E3 | Adressen für Themen | `/forum/thema/{id}-{slug}` (DE), `/en/forum/topic/{id}-{slug}` (EN); Auflösung über die ID, falscher Slug → 301 auf die kanonische Adresse; alt `/forum/thread/{id}` → 301 | Lesbar wie gewünscht (`/thema/482-welches-php-framework`), robust gegen Titeländerungen | was am besten bei einem forum passt! |
| E4 | Speicherformat und Editor | **Markdown (CommonMark + GFM-Tabellen/Aufgaben), kein Roh-HTML**; Editor = Textfeld mit Werkzeugleiste, Vorschau, Einfügen/Ziehen von Bildern; serverseitiges Rendern, Ausgabe durch `PurifierService` (eigenes Profil `community`); Code-Blöcke mit Syntax-Highlighting | Sicher (kein HTML von Nutzern), portabel, Zitate und Code natürlich; Alternative SunEditor (schon im Core, speichert HTML) bleibt als Option für Admins | einen passenden auf GitHub als Asset laden oder entsprechend bauen |
| E5 | Markdown- und Highlighting-Bibliothek | `league/commonmark` (vendored unter `assets/`), Highlighting serverseitig mit `scrivo/highlight.php` **oder** im Browser mit selbst gehostetem `highlight.js` | Keine externen CDNs (CSP, DSGVO); serverseitig = kein JS nötig, im Browser = weniger Serverlast | nichts extern! Alles seitens des 365CMS kommend |
| E6 | Alte BBCode-Beiträge aus `cms-forum` | Spalte `format` je Beitrag (`markdown`, `bbcode`); BBCode-Beiträge werden mit dem übernommenen `BBCodeParser` gerendert; beim nächsten Bearbeiten Umwandlung in Markdown | Keine verlustbehaftete Massenkonvertierung bei der Migration | nichts zu übernehmen da das Plugin nirgends bisher aktiv war! |
| E7 | Captcha | Neuer Core-Dienst **`CaptchaService`** mit Anbietern: eingebaut (Rechenaufgabe aus `FormSpamGuard`), selbst gehosteter Proof-of-Work (Standard, ohne Drittanbieter), Bild-Captcha mit **`gregwar/captcha`**, Cloudflare Turnstile, hCaptcha, optional Google reCAPTCHA (**`google/recaptcha`**). Nicht verwenden: `olakunlevpn/phpcapcha` (proprietäre Schrift, keine Lizenzdatei), `capskip/capskip-php` (Captcha-*Löser*, Angriffswerkzeug). Bewertung: [SECURITY-PRIVACY.md](SECURITY-PRIVACY.md) §3 | Wird auch von Formularen und Registrierung genutzt; Drittanbieter nur mit Einwilligung bzw. Hinweis im Datenschutztext und CSP-Freigabe | entscheide dich für das Asset packet was passt! |
| E8 | Private Nachrichten | Vorhandenen `MessageService` nutzen (1:1); **Gruppen-Konversationen** als Erweiterung des Core-Dienstes, nicht als Forum-eigene Tabellen | Ein Postfach für alle Mitglieder statt zwei, aufbauend auf die 365CMS Funktionen, nichts eigenes hier! |
| E9 | OAuth / Social Login | Eigenes Core-Vorhaben (Auth), nicht Teil des Community-Moduls; Community nutzt es, sobald vorhanden | Betrifft Login für die ganze Site, nicht nur das Forum | aufbauend auf die 365CMS Funktionen, nichts eigenes hier! |
| E10 | Mandanten / MultiSite | Keine `site_id` (wie [KLARSTELLUNGEN.md](../KLARSTELLUNGEN.md) §3); Hosts über die bestehende Auflösung | Verbindliche Festlegung aus 4.0.0 | gesetzt |
| E11 | Version und Schema | Eigenes Release (Vorschlag **4.1**), **ein** neuer Schema-Stand **v26**, idempotente Migrationen | Wie in 4.0.0 |  was eben hier passt! |
| E12 | Ablage der Tests | Vor WP-C1 festlegen: eigene Suite im Repository, eigenes Test-Repository oder lokale Ausführung mit Nachweis im PR | `TESTS/` wurde am 14.11.2026 aus `main` entfernt (Commit `6edfacc`); CI prüft derzeit nur Semgrep/Trivy | entsprechend wiederherstellen oder neu erstellen |

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
