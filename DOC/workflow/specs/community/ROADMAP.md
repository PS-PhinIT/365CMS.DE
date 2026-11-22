# 365CMS Projektdokumentation | Community: Roadmap & Fortschritt
> **Stand:** 2026-11-18 | **Ziel-Version:** 4.2.0 · Schema v27 | **Status:** WP-C1 bis WP-C3 teilweise geliefert (PR #1, PR #2a) | **Übersicht:** [README.md](README.md) · **Umsetzungsfahrplan:** [IMPLEMENTATION-PLAN.md](IMPLEMENTATION-PLAN.md)

## English (summary)

Delivery plan for the Community module in three phases: P1 foundation and parity with `cms-forum` (shippable
release, plugin can be retired), P2 community build-out (trust levels, warnings, merge/split, mentions, drafts,
digests, polls, statistics), P3 extensions (reputation, badges, tags, group conversations, webhooks, AI signals).
Work packages (WP-C0 … WP-C16) list dependencies, deliverables, linked feature IDs and a rough effort in person-days.
The progress table at the end is the checklist; every item starts as ⬜ and is ticked only with test evidence.

## Deutsch

### 1. Phasen

| Phase | Ziel | Ergebnis |
|---|---|---|
| **P1: Grundlage & Parität** | Alles aus deinen Prioritäten 1, 2 und den Kernpunkten aus 3 und 4, plus Parität zu `cms-forum` | Auslieferbar; `cms-forum` kann abgelöst werden |
| **P2: Community-Ausbau** | Vertrauensstufen, Verwarnungen, Zusammenführen/Teilen, Erwähnungen, Entwürfe, Zusammenfassungs-Mails, Umfragen, Lesezeichen, Ignorieren, Online-Anzeige, Statistik, Feeds | Moderation skaliert, Mitglieder bleiben aktiv |
| **P3: Erweiterungen** | Reputation, Abzeichen, Tags/Präfixe, Gruppen-Nachrichten (Core), Webhooks, KI-Signale, Export/Import der Struktur | Nice-to-have aus deiner Liste (OAuth als eigenes Core-Vorhaben) |

### 2. Arbeitspakete

Aufwand in Personentagen (PT), grob geschätzt einschließlich Tests und Doku; ± 30 %.

| WP | Inhalt | Features | Abhängig von | PT |
|---|---|---|---|---|
| **WP-C0** | Entscheidungen E1–E12 bestätigen (E11 gesetzt: 4.2/v27); Abhängigkeiten aus 4.1.0 nutzen (Hook `user_role_changed`, Webhooks, SSO, Verwendungsnachweis der Medien; `user_deleted` ist neu einzuführen; Alerting ist auf 4.3.0 verschoben) ([README.md](README.md) §4); Bibliotheken prüfen (CommonMark, Highlighting, `gregwar/captcha`, Proof-of-Work) inkl. Lizenz, PHP 8.4/8.5, CSP | – | – | 2 |
| **WP-C1** | Modul-Gerüst: `CoreModuleService`-Eintrag, `CommunityHooks`, Einstellungen (`Support/Settings`) mit Typen/Grenzen, Sprachschlüssel, Admin-Katalog-Einträge (ausgeblendet bis Seiten existieren), Schema v27 mit allen Tabellen (`Installer`), Update-Schritt Capabilities + neue Rolle `moderator` | COM-ADM-03, COM-MOD-01 (Teil) | C0 | 5 |
| **WP-C2** | Struktur: Kategorien/Foren-Baum, Admin „Struktur“ (Drag & Drop, Tastatur), Forentypen und -status, Rechte-Matrix inkl. Vererbung, Forum-Moderatoren, `CommunityAccess` mit Tests | COM-CORE-02, COM-MOD-01, COM-MOD-02 | C1 | 8 |
| **WP-C3** | Inhalte: Themen/Beiträge erstellen, antworten, zitieren, bearbeiten mit Verlauf, weich löschen; Zähler; Seitennavigation; Startseite, Forum, Thema, Listen „Neu/Aktiv/Unbeantwortet“; Permalinks; Gelesen-Status | COM-CORE-03…06, 09, 11, 12, COM-INT-09, 10 | C2 | 12 |
| **WP-C4** | Editor & Rendering: Markdown-Renderer, Purifier-Profil `community`, Code-Highlighting, Vorschau-Endpunkt, Werkzeugleiste, lokale Entwürfe (Basis), BBCode-Renderer für Altbestand | COM-CORE-07, 08, COM-UX-05 (lokal) | C0, C3 | 7 |
| **WP-C5** | Sicherheit & Spam: `CaptchaService` (eingebaut, Proof-of-Work, Bild: keine Drittanbieter, E7), Antispam-Schichten, Rate-Limits/Langsamer Modus, Wortfilter, Freigabe-Warteschlange, Sicherheits-Checkliste | COM-MOD-10, 12, 13, 14, COM-CORE-01 | C3 | 8 |
| **WP-C6** | Moderation: schließen, anpinnen, Ankündigung, verschieben, ausblenden/wiederherstellen, Beiträge verschieben, Massenaktionen, Melde-System, Sperren, Protokoll, Badges, Admin-Seiten Moderation | COM-MOD-03, 04, 06, 07, 09, 11 | C3, C5 | 9 |
| **WP-C7** | Interaktion: Abos, In-App-Benachrichtigungen (Core), Sofort-Mails, Reaktionen, Lösung markieren, Ränge, Profil-Abschnitt, „Nachricht senden“ (Core-Postfach), Mitgliederbereich `community` | COM-INT-01, 02 (sofort), 03, 05, 06, 07, 08 (1:1), COM-PLUS-01, 04 | C3 | 9 |
| **WP-C8** | Anhänge: Upload, Prüfung, Bildneukodierung, privater Speicher, Auslieferung mit Rechten, Vorschaubilder, verwaiste Uploads | COM-UX-02 | C3, C5 | 5 |
| **WP-C9** | Suche & SEO: `SearchDocumentProvider`, Suche mit Filtern und Rechtefilter, ähnliche Themen, Sitemap-Provider, kanonische URLs, Weiterleitungen, Schema.org, `noindex`-Regeln | COM-CORE-10, COM-UX-03, 06 | C3 | 6 |
| **WP-C10** | Frontend & Theme: Core-Vorlagen und Overrides, `community.css`/JS-Module, Mobile-First, Barrierefreiheit, Dark Mode; **Theme-PR `cms-phinit`** (THEME-UI §7) | COM-UX-01, 07, 09, COM-CORE-13 | C3…C9 parallel | 8 |
| **WP-C11** | DSGVO & Löschfristen: Export-/Lösch-Hooks, Anonymisierung, Löschfristen-Regeln, Legal-Textbausteine, Papierkorb-Provider | COM-UX-04, COM-ADM-06 (Papierkorb) | C3, C8 | 4 |
| ~~WP-C12~~ | **Entfällt (E6):** keine Datenübernahme aus `cms-forum` (Plugin war nirgends aktiv) | ~~COM-ADM-04~~ | – | 0 |
| **WP-C13** | Leistung & Abnahme P1: Abfragebudget, Cache, Lasttest (10.000 Themen / 500.000 Beiträge), Lighthouse, vollständiger TEST-PLAN P1, Doku (`DOC/admin/community/`, `DOC/member/`, Hooks-Referenz, Changelog), Release | COM-UX-08 | alle P1 | 6 |
| | **Summe P1** (ohne WP-C12) | | | **≈ 89 PT** |
| **WP-C14** | P2 Moderation: Vertrauensstufen, Verwarnungen mit Schwellen, Zusammenführen/Teilen, IP-/Kennungshinweise | COM-MOD-05, 08, 15, 16 | P1 | 8 |
| **WP-C15** | P2 Interaktion: Erwähnungen, Zusammenfassungs-Mails, Umfragen, Lesezeichen, Ignorieren, Wer ist online, Server-Entwürfe, Emoji, automatisches Schließen, Feeds, Statistik, Export/Import der Struktur | COM-INT-02 (Digest), 04, 11, 12, 13, COM-UX-05, COM-PLUS-06, 07, 09, 12, COM-ADM-05, 06 | P1 | 12 |
| **WP-C16** | P3: Reputation, Abzeichen, Tags/Präfixe, Webhooks, KI-Signale; Core: Gruppen-Nachrichten (E8), OAuth (E9, eigenes Vorhaben) | COM-PLUS-02, 03, 08, 10, 11, COM-INT-08 (Gruppen) | P2 | 15 + Core |

```mermaid
flowchart LR
  C0 --> C1 --> C2 --> C3
  C3 --> C4 & C5 & C7 & C8 & C9 & C10
  C5 --> C6
  C5 --> C8
  C3 & C8 --> C11
  C2 & C4 & C6 & C7 & C8 & C9 & C10 & C11 --> C13
  C13 --> C14 & C15 --> C16
```

### 3. Meilensteine

| Meilenstein | Inhalt | Kriterium |
|---|---|---|
| M1: Lesbar | WP-C1…C3 | Struktur anlegen, Themen lesen/schreiben unter `/forum` auf einer Testinstallation |
| M2: Sicher | WP-C4…C6, C8 | Sicherheits-Checkliste grün, XSS-Korpus und Rechte-Matrix bestanden |
| M3: Funktionsumfang | WP-C7, C9…C11 | Funktions-Referenz aus MIGRATION-CMS-FORUM §2 abgedeckt |
| M4: Release P1 | WP-C13 | TEST-PLAN P1 grün, Lighthouse ≥ 90, Doku und Changelog, RELEASE-Paket |

### 4. Risiken

| Risiko | Auswirkung | Gegenmaßnahme |
|---|---|---|
| Rechte-Fehler machen interne Foren sichtbar | Datenleck | `CommunityAccess` als einzige Stelle, Rechte-Matrix-Test über alle Routen und Nebenwege (Suche, Feeds, Zitate, Benachrichtigungen, Anhänge) |
| XSS über Markdown-Sonderfälle | Kontoübernahme | Kein Roh-HTML, Purifier nach dem Renderer, XSS-Korpus im CI |
| Große Foren langsam | schlechte Nutzung, Serverlast | Denormalisierte Zähler, Indizes, Keyset-Seiten, Lasttest in WP-C13 |
| Captcha-Anbieter mit Datenabfluss | DSGVO-Verstoß | Nur lokale Anbieter (Proof-of-Work, Bild, Rechenaufgabe; E7); Drittanbieter sind nicht vorgesehen |
| Neue Vendor-Bibliotheken | Pflege, Lizenz, Supply Chain | WP-C0 prüft Lizenz/Pflege, Vendoring mit SHA-256 in `DOC/assets/`, keine CDN-Einbindung |
| Theme-Abhängigkeit (`cms-phinit`) | Mitgliederbereich zeigt alten Plugin-Weg | Theme-PR in WP-C10, Rückfall auf Plugin-Weg bleibt bis zur Abschaltung |

### 5. Fortschritt

**Legende:** ✅ erledigt · 🟡 in Arbeit · ⬜ offen · ➖ entfällt

| WP | Status | Ergebnis / Nachweis |
|---|---|---|
| Planung (dieses Dokument-Set) | ✅ | `DOC/workflow/specs/community/` (14.11.2026), Umsetzungsfahrplan [IMPLEMENTATION-PLAN.md](IMPLEMENTATION-PLAN.md) (15.11.2026) |
| WP-C0 Entscheidungen & Bibliotheken | ⬜ | |
| WP-C1 Modul-Gerüst & Schema v27 | 🟡 | PR #1 (17.11.2026): Schema v27 mit allen 27 Tabellen (`TESTS/schema-v27`, `TESTS/schema-upgrade`: Neuinstallation = Update von v25/v26 gegen MariaDB 10.11), Capabilities `community.*`, Rolle `moderator`, Update-Schritt `capabilities_v27` ([HANDOFF-PR2-PR5.md](HANDOFF-PR2-PR5.md)); Nachlauf: zwei Indizes für `community_topics`, `community_badges.description_en` (HANDOFF §2 Nr. 9). PR #2a (#178, 18.11.2026): `CoreModuleService`-Eintrag (standardmäßig aus), `CommunityHooks`, `Support/Settings`, Sprachschlüssel `community.access.*`/`community.error.*`, Core-Hook `user_role_changed` auch außerhalb des SSO-Pfads (`TESTS/community-settings`, `TESTS/user-role-hook`). Offen: Admin-Katalog (PR #3) |
| WP-C2 Struktur & Rechte | 🟡 | PR #2a (#178): `CommunityAccess` (Rechte je Forum mit Vererbung, Forum-Moderatoren, Sperren, Vertrauensstufen P1), Repositories für Kategorien und Foren (`TESTS/community-access`, `TESTS/community-repositories`, Gegenprobe `TESTS/community-mutants`). Offen: Admin „Struktur“ (Drag & Drop, Tastatur), Verwaltung von Forentypen, -status, Rechten und Forum-Moderatoren (PR #3) |
| WP-C3 Themen, Beiträge, Gelesen-Status | 🟡 | PR #2a (#178): `TopicService`, `PostService` (anlegen, antworten, bearbeiten mit Verlauf, weich löschen, atomare Zähler, Position und Seite), `QuoteService`, `ReadStateService`, Keyset-Listen ohne `OFFSET` (`TESTS/community-content`, `TESTS/community-db` gegen MariaDB). Offen: Startseite, Forum- und Themenseiten, Listen „Neu/Aktiv/Unbeantwortet“, Permalinks als Routen (PR #4) |
| WP-C4 Editor & Rendering | 🟡 | PR #2b (Renderer, `PurifierService`-Profil `community`, Vorschau), PR #4 (visueller Editor: SunEditor mit Markdown-Brücke). Offen: lokale Entwürfe, Upload im Editor |
| WP-C5 Sicherheit, Spam, Captcha, Warteschlange | 🟡 | PR #2b (`ContentGuard`: Antispam-Schichten, Wortfilter, Raten, Freigabe-Warteschlange; `TESTS/community-spam`). Offen: `CaptchaService` (E7) |
| WP-C6 Moderation & Meldungen | ✅ | PR #2b und #3 (`ModerationService`, `ReportService`, `BanService`, Admin-Seiten; `TESTS/community-moderation`, `community-sanctions`, `community-admin`) |
| WP-C7 Interaktion & Mitgliederbereich | 🟡 | PR #2b und #4 (Abos, Benachrichtigungen, Reaktionen, Lösung, Ränge, Mitgliederbereich `/member/plugin/community`). Offen: Profil-Abschnitt `/forum/mitglieder/{id}` |
| WP-C8 Anhänge | ✅ | PR #2b und #4 (`AttachmentService`, `Http\AttachmentController`; `TESTS/community-uploads`) |
| WP-C9 Suche & SEO | ✅ | PR #2b (`CommunitySearch`, Sitemap, `noindex`-Regeln; `TESTS/community-search-seo`) |
| WP-C10 Frontend & Theme-PR | 🟡 | PR #4 (Routen, Vorlagen, `community.css`, JS-Module; `TESTS/community-frontend`, `community-api`, `community-member`, `community-editor`). Offen: Barrierefreiheits- und Lighthouse-Prüfung (PR #5), Theme-PR `cms-phinit` |
| WP-C11 DSGVO & Löschfristen | 🟡 | PR #5: Löschfristen und DSGVO (`TESTS/community-privacy`), Papierkorb-Provider (`TESTS/community-trash`). Offen: Legal-Textbausteine, Admin-Liste der Exportdateien, Löschmodus „Beiträge löschen“ |
| WP-C12 Übernahme cms-forum | ➖ | entfällt (E6) |
| WP-C13 Leistung, Abnahme, Release P1 | ⬜ | |
| WP-C14 P2 Moderation | ⬜ | |
| WP-C15 P2 Interaktion | ⬜ | |
| WP-C16 P3 Erweiterungen | ⬜ | |
