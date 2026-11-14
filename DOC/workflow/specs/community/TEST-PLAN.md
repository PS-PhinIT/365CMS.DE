# 365CMS – Projektdokumentation | Abschnitt: Community – Testplan
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.2 | **Status:** Planung | **Übersicht:** [README.md](README.md)

## English (summary)

Test plan for the Community module: suites (permissions matrix, content and rendering, XSS corpus, anti-spam,
moderation, notifications, uploads, search/SEO, GDPR, migration from cms-forum, performance, browser and
accessibility), what each must prove, counter-checks (every test must fail against a deliberately broken
implementation) and the release gate. The repository's `TESTS/` directory was removed from `main` on 14 Nov 2026
(commit `6edfacc`); where the Community tests live and which of them run in CI is open decision E12.

## Deutsch

### 1. Grundsätze

- **Jede Funktion aus [FEATURES.md](FEATURES.md) hat mindestens einen Test**, jede Einstellung einen Test, der ihre Wirkung zeigt (Lehre aus den wirkungslosen Plugin-Einstellungen).
- **Gegenprobe:** Jeder neue Test wird einmal gegen eine absichtlich falsche Umsetzung laufen gelassen und muss dort fehlschlagen.
- **Ohne externe Dienste:** Captcha-Drittanbieter, Mail und HTTP werden durch Fakes ersetzt; keine Netzwerkzugriffe in Tests.
- **Ablage (offen, E12):** Das Verzeichnis `TESTS/` wurde am 14.11.2026 aus `main` entfernt (Commit `6edfacc`, 35 Dateien). In CI läuft derzeit nur `.github/workflows/security.yml` (Semgrep, Trivy). Vor WP-C1 festlegen: eigene Suite im Repository, getrenntes Test-Repository oder lokale Ausführung mit Nachweis im PR.

### 2. Suiten

| Suite | Prüft | Beispiele | Phase |
|---|---|---|---|
| `access` – Rechte-Matrix | jede Route und jeder Nebenweg × Gast, Mitglied (Stufe 0/1/3), Gruppenmitglied, Forum-Moderator, globaler Moderator, Admin, gesperrt (`write`/`full`), unbestätigte E-Mail | internes Forum: 404 für Gast/Mitglied in Startseite, Forum, Thema, Suche, Feed, Sitemap, „Neue Beiträge“, Profil-Liste, Zitat in öffentlichem Thema, Anhang-URL, Polling, Benachrichtigung; Vererbung Elternforum; `cms_community_can` kann nur einschränken | P1 |
| `content` – Themen & Beiträge | Erstellen, Antworten, Zitieren, Bearbeiten im Zeitfenster/danach, Verlauf, weiches Löschen, Zähler nach jeder Aktion, Seiten und Permalinks, Idempotenz (doppeltes Absenden) | Beitrag 31 bei 15/Seite → Seite 3 + Anker; Zähler nach Verschieben in beiden Foren korrekt | P1 |
| `render` – Editor & Ausgabe | Markdown-Funktionen, Code-Highlighting je Sprache, Tabellen, Spoiler, Erwähnungen, Zitate, Links mit `rel`, Bilder nur aus Anhängen, BBCode-Altbestand, `rendered_version` | gleiche Ausgabe in Vorschau und Frontend | P1 |
| `xss` – Korpus | ≥ 30 Angriffe gegen Beitrag, Titel, Signatur, Profilfeld, Dateiname, Umfrage, Meldegrund, Suchbegriff | `[x](javascript:alert(1))`, `<img src=x onerror=…>` im Markdown, `</code><script>` im Code-Block, verschachtelte Zitate mit HTML, Unicode-Tricks in Links, `data:`-URLs, SVG-Upload | P1 |
| `spam` – Schutzschichten | Honeypot, Mindestzeit, Captcha je Anbieter (Fakes), Rate-Limits und Langsamer Modus, Link-/Bildgrenzen je Stufe, Wortfilter (sperren/ersetzen), Duplikate, Freigabe für Stufe 0 | 2. Beitrag nach 10 s abgelehnt mit `retry_after`; Captcha-Schlüssel fehlt → Rückfall + Admin-Hinweis | P1 |
| `moderation` | jede Aktion aus PERMISSIONS-MODERATION §5 inkl. Protokolleintrag, Pflichtgrund, Massenaktionen; Meldungen bündeln, Schwelle für vorläufiges Ausblenden, Rückmeldung; Sperren mit Ablauf; Moderator kann keinen Admin sperren | Zusammenführen: Reihenfolge, 301, Abos/Lesestatus zusammengeführt | P1/P2 |
| `notify` – Benachrichtigungen | Empfänger je Ereignis, Rechteprüfung, Ignorieren, keine Eigenbenachrichtigung, Bündelung, eine Mail bis zum Besuch, Zusammenfassungen, signierter Abmeldelink | Erwähnung in internem Forum benachrichtigt keinen Unberechtigten | P1/P2 |
| `uploads` | Allowlist Endung + Signatur, Polyglot (JPEG mit PHP), SVG, übergroße Pixel, EXIF entfernt, WebP-Umwandlung, Auslieferung mit Rechten und Headern, verwaiste Uploads nach 24 h | `shell.php.jpg`, Zip-Bombe wird nicht entpackt | P1 |
| `search-seo` | Suche mit Filtern und Rechtefilter, Index-Aktualisierung bei Ereignissen, kanonische URLs, 301 bei falschem Slug/alten URLs, `noindex`-Regeln, Sitemap ohne interne Foren, Schema.org valide | Thema umbenannt → alte Slug-URL 301 | P1 |
| `privacy` – DSGVO & Fristen | Export vollständig, Anonymisieren vs. Löschen, Zitate neutralisiert, jede Löschfrist-Regel | gelöschtes Mitglied erscheint als „Gelöschtes Mitglied“, Profil 404 | P1 |
| ~~`migration` – cms-forum~~ (entfällt, E6) | Vorschau, Probelauf, Zählungen je Tabelle, BBCode-Stichprobe, Spaltenvariante `text`/`option_text`, Rechte-Abbildung, Sperren, Anhänge mit Prüfsummen, alle alten URLs, zweiter Lauf ohne Änderung, Liste „Verhalten ändert sich“ | Daten aus dem Plugin-Installer + Zufallsdaten (10.000 Beiträge) | P1 |
| `settings` | jede Einstellung aus `Support/Settings` hat einen Leser und einen Wirkungstest; Grenzen und Typen werden erzwungen | statischer Test: jeder Schlüssel kommt außerhalb von Settings/Admin-View vor | P1 |
| `performance` | Abfragebudget je Seite (≤ 6 bei warmem Cache), Lasttest 10.000 Themen / 500.000 Beiträge (Startseite, Forum, Thema Seite 1 und letzte Seite, Suche) | Seite 400 eines Themas ohne `OFFSET`-Scan (EXPLAIN) | P1 |
| `browser` – Oberfläche | Playwright: Schreiben, Vorschau, Zitieren, Melden-Dialog, Moderationsmenü, Upload per Ziehen, Entwurf wiederherstellen, mobil 360 px, Dark Mode, Tastaturbedienung | kein waagerechtes Scrollen bei 320 px | P1 |
| `a11y` | axe-core auf Startseite, Forum, Thema, Editor, Suche, Mitgliederbereich; manuelle Prüfung mit Screenreader (Stichprobe) | keine Verstöße der Stufe „serious“/„critical“ | P1 |
| `lighthouse` | mobil ≥ 90 in allen Kategorien für Startseite, Forum, Thema | Theme `cms-phinit` und `cms-default` | P1 |

### 3. Testdaten

- Generator für Struktur (3 Kategorien, 12 Foren in 3 Ebenen, 1 internes Forum je Gruppe), Mitglieder je Rolle/Stufe/Sperre, Themen mit allen Zuständen (angepinnt, geschlossen, gelöst, Umfrage, Anhänge, verschoben, wartend, ausgeblendet).
- Lastdaten per Batch (10.000 Themen, 500.000 Beiträge, 5.000 Mitglieder, 1 Mio. Reaktionen).
- ~~Plugin-Daten für `migration`~~ (entfällt, E6): Installation von `cms-forum` 3.0.4 in einer Testinstanz bzw. SQL-Abzug mit beiden Spaltenvarianten der Umfrageoptionen.

### 4. Freigabe-Kriterien (Release P1)

- [ ] Alle Suiten der Phase P1 grün, Gegenproben dokumentiert.
- [ ] Rechte-Matrix ohne Ausnahme; XSS-Korpus ohne Treffer.
- [ ] Funktions-Referenz in [MIGRATION-CMS-FORUM.md](MIGRATION-CMS-FORUM.md) §2 abgedeckt.
- [ ] Abfragebudget und Lasttest erfüllt; Lighthouse ≥ 90.
- [ ] Semgrep/Trivy (`security.yml`) ohne neue Befunde.
- [ ] Doku aktualisiert: `DOC/admin/…`, `DOC/member/…`, `DOC/core/HOOKS-REFERENCE.md`, `DOC/core/DATABASE-SCHEMA.md`, Changelog; ROADMAP-Fortschritt gepflegt.
