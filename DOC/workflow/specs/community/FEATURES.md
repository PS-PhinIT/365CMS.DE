# 365CMS – Projektdokumentation | Abschnitt: Community – Funktionskatalog
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.2 | **Status:** Planung | **Übersicht:** [README.md](README.md)

## English (summary)

Feature catalogue of the Community module. Every feature has an ID (`COM-<area>-<nn>`), a priority taken from the
product brief (1 core, 2 moderation & security, 3 community & interaction, 4 comfort & technology, 5 nice-to-have),
acceptance criteria, the core building block it reuses (or "new"), the implementation phase (P1–P3, see
[ROADMAP.md](ROADMAP.md)) and the current state in the `cms-forum` plugin. Features already provided by the core
(registration, login, password reset, e-mail verification, GDPR export/deletion request, cookie consent) are
integrated, not rebuilt.

## Deutsch

### Legende

| Spalte | Bedeutung |
|---|---|
| **ID** | `COM-<Bereich>-<Nr>`; Bereiche: `CORE` Kernfunktionen, `MOD` Moderation & Sicherheit, `INT` Community & Interaktion, `UX` Komfort & Technik, `PLUS` Nice-to-have, `ADM` Administration |
| **Phase** | P1 = Grundlage/Parität (erste Auslieferung), P2 = Community-Ausbau, P3 = Erweiterungen – siehe [ROADMAP.md](ROADMAP.md) |
| **Baustein** | Vorhandener Core-Dienst, der genutzt wird, oder **neu** |
| **cms-forum** | ✅ vorhanden · 🟡 teilweise · ⬜ fehlt · ⚠️ Einstellung vorhanden, aber ohne Wirkung im Code |

Jede Funktion gilt erst als fertig, wenn alle Akzeptanzkriterien erfüllt und im [TEST-PLAN.md](TEST-PLAN.md) abgedeckt sind.

---

### 1. Kernfunktionen (Fundament) – Priorität 1

| ID | Funktion | Akzeptanzkriterien | Baustein | Phase | cms-forum |
|---|---|---|---|---|---|
| COM-CORE-01 | **Registrierung, Login, Passwort vergessen, E-Mail-Bestätigung** | Forum nutzt ausschließlich die Core-Seiten (`/cms-login`, `/cms-register`, `/cms-password-forgot`, Bestätigungsmail). Schreiben im Forum erst nach bestätigter E-Mail-Adresse (Einstellung, Standard an). Nach Login Rücksprung auf die Ausgangsseite im Forum (`redirect`-Parameter, nur lokale Pfade). | `CmsAuthPageService`, `EmailVerificationService`, `Auth` | P1 | 🟡 nutzt Core-Login, keine Prüfung der Bestätigung |
| COM-CORE-02 | **Kategorien und Unterforen (Baum)** | Kategorien mit Reihenfolge; Foren beliebig tief verschachtelt (Admin-Grenze Standard 3 Ebenen); je Forum: Titel, Slug (DE/EN), Beschreibung, Symbol/Farbe, Sortierung, Status (offen, nur lesen, archiviert, versteckt), Typ (Diskussion, Fragen & Antworten, Ankündigungen, Link-Forum). Brotkrumen über den ganzen Pfad. Sortieren per Drag & Drop und Tastatur. | neu (`CategoryRepository`, `ForumRepository`) | P1 | ✅ (Kategorien, Unterforen) |
| COM-CORE-03 | **Themen erstellen** | Titel (Länge einstellbar, Standard 5–200 Zeichen) und erster Beitrag; Forum muss Themen erlauben; Pflicht-Präfixe/Tags optional (COM-PLUS-08). Doppelte Absendung wird verhindert (Idempotenz-Token). Nach dem Speichern Sprung zum Thema. | neu (`TopicService`) | P1 | ✅ |
| COM-CORE-04 | **Antworten (Beiträge)** | Antworten unter dem Thema und als Seite „Antworten“ mit Zitat; gesperrte Themen nur für Moderation. Länge einstellbar (Standard 2–50.000 Zeichen). Nach dem Speichern Sprung zum neuen Beitrag (Anker), richtige Seite der Seitennavigation. | neu (`PostService`) | P1 | ✅ |
| COM-CORE-05 | **Bearbeiten mit Hinweis „Zuletzt bearbeitet am … von …“** | Eigene Beiträge bearbeiten innerhalb eines Zeitfensters (Standard 30 min, 0 = unbegrenzt, je Vertrauensstufe änderbar); Moderation immer. Hinweis mit Datum, Bearbeiter (falls nicht Autor) und optionalem Grund. Versionen werden gespeichert (`community_post_revisions`), für Moderation einsehbar. Bearbeitung des ersten Beitrags darf den Titel ändern. | neu | P1 | 🟡 Zeitfenster, kein Verlauf |
| COM-CORE-06 | **Eigene Beiträge löschen** | Einstellung je Forum (Standard aus); Löschen = weiches Löschen (Status `deleted`, Platzhalter „Beitrag wurde vom Autor gelöscht“), Wiederherstellen durch Moderation; erster Beitrag eines Themas mit Antworten kann nicht gelöscht werden (nur Moderation). Endgültig löschen nur über den Papierkorb. | neu + `TrashProvider` | P1 | 🟡 Einstellung `members_can_delete_own` |
| COM-CORE-07 | **Editor mit Formatierung** | Fett, kursiv, durchgestrichen, Überschriften (h3–h4), Listen, Aufgabenlisten, Zitate (mit Quellbeitrag), Links, Inline-Code, Code-Blöcke mit Sprache, Tabellen, Spoiler, Bilder aus Anhängen. Werkzeugleiste und Tastenkürzel, Live-Vorschau (serverseitig gerendert, gleiche Ausgabe wie im Frontend). Speichern als Markdown, Ausgabe nur über den Renderer + Purifier (Entscheidung E4). Kein Roh-HTML von Nutzern. | neu (`ContentRenderer`), `PurifierService` | P1 | 🟡 BBCode-Editor |
| COM-CORE-08 | **Code-Blöcke mit Syntax-Highlighting** | Sprachen mindestens: PHP, JavaScript/TypeScript, HTML, CSS, SQL, Bash, PowerShell, JSON, YAML, Python, C#. Kopier-Knopf, Zeilen umbrechbar, Kontrast im hellen und dunklen Design (WCAG AA). Keine externen CDNs. | neu (E5) | P1 | ⬜ (nur `<pre>`) |
| COM-CORE-09 | **Seitennavigation (Pagination)** | Themenlisten (Standard 20/Seite) und Themen (Standard 15 Beiträge/Seite) einstellbar. Seiten als Pfad `/seite-2` oder `?page=2` (eine Form, die andere leitet um), `rel="next/prev"` im Kopf, Sprung zur letzten Seite, Seitenauswahl im Thema. Abfragen nutzen Indizes (keine `OFFSET`-Scans über große Tabellen: Keyset ab Seite 50, siehe ARCHITECTURE §8). | neu | P1 | ✅ |
| COM-CORE-10 | **Volltextsuche** | Suche über Titel und Inhalte; Filter: Forum (inkl. Unterforen), Autor, Zeitraum, nur Themen/auch Antworten, nur gelöste Themen; Sortierung Relevanz/Datum. Ergebnisse berücksichtigen **Leserechte** je Forum. Treffer mit hervorgehobenem Ausschnitt. Rate-Limit für Gäste. Optional: Forum-Inhalte in der globalen Site-Suche (nur öffentlich lesbare Foren). | `SearchDocumentProvider`, TNTSearch, `SiteSearchService` | P1 | ✅ (eigene SQL-Suche) |
| COM-CORE-11 | **Community-Startseite** | Kategorien mit Foren, je Forum: Themen- und Beitragszahl, letzter Beitrag (Titel, Autor, Zeit), Ungelesen-Markierung; Statistik (Themen, Beiträge, Mitglieder, neuestes Mitglied); optional „Wer ist online“. | neu | P1 | ✅ |
| COM-CORE-12 | **Themenliste eines Forums** | Angepinnte Themen oben, dann nach letzter Aktivität; Filter (ungelöst, ohne Antwort, meine); Spalten Antworten, Aufrufe, letzter Beitrag; Symbole für gesperrt, angepinnt, gelöst, Umfrage, Anhang. | neu | P1 | ✅ |
| COM-CORE-13 | **Zweisprachigkeit DE/EN** | Alle Oberflächentexte über `lang/de.yaml`/`en.yaml`; Routen auch unter `/en/…`; Foren-Titel/Beschreibung je Sprache; Beiträge bleiben in der Sprache des Autors (keine Übersetzung). | `TranslationService`, `ContentLocalizationService` | P1 | ✅ (eigene Sprachdateien) |

### 2. Moderation & Sicherheit – Priorität 2

| ID | Funktion | Akzeptanzkriterien | Baustein | Phase | cms-forum |
|---|---|---|---|---|---|
| COM-MOD-01 | **Rollen- und Rechtesystem** | Gast, Mitglied, Moderator, Administrator als Standard; neue Capabilities `community.*` in der Core-Rollenverwaltung. Rechte **je Forum** für Rollen und Gruppen (lesen, Themen erstellen, antworten, Anhänge, abstimmen, reagieren, eigene bearbeiten/löschen, moderieren). Vererbung vom Elternforum, „interne Bereiche“ (nur bestimmte Gruppen) möglich. Details: [PERMISSIONS-MODERATION.md](PERMISSIONS-MODERATION.md). | `roles`, `user_groups`, `RolesModule` | P1 | ✅ (je Forum, eigene Tabelle) |
| COM-MOD-02 | **Moderatoren je Forum** | Mitglieder oder Gruppen als Moderatoren einzelner Foren (inkl. Unterforen); globale Moderatoren über Capability. Anzeige „Moderiert von“ im Forum. | neu | P1 | 🟡 nur über Rechtematrix |
| COM-MOD-03 | **Themen schließen/öffnen, anpinnen, als Ankündigung** | Geschlossen = keine Antworten außer Moderation; angepinnt = oben im Forum; Ankündigung = oben in allen Foren einer Kategorie. Zeitgesteuert möglich (bis Datum). Aktion im Moderationsprotokoll. | neu | P1 | ✅ (schließen, anpinnen) |
| COM-MOD-04 | **Themen verschieben** | In ein anderes Forum, optional mit Verweis-Thema am alten Ort („verschoben nach …“, läuft nach X Tagen ab). Zähler beider Foren werden aktualisiert. | neu | P1 | ✅ |
| COM-MOD-05 | **Themen zusammenführen und teilen** | Zusammenführen: Beiträge eines Themas in ein anderes, chronologisch einsortiert, altes Thema wird zur Weiterleitung (301). Teilen: ausgewählte Beiträge in ein neues Thema. | neu | P2 | ⬜ |
| COM-MOD-06 | **Beiträge moderieren** | Ausblenden (mit Grund, für Autor sichtbar), wiederherstellen, bearbeiten (mit Grund im Bearbeitungshinweis), löschen, verschieben in anderes Thema. Massenaktionen in der Themenansicht (Auswahl mehrerer Beiträge). | neu | P1 | 🟡 löschen/wiederherstellen/freigeben |
| COM-MOD-07 | **Melde-System (Report)** | „Melden“-Knopf an jedem Beitrag und Profil; Gründe (Spam, Beleidigung, Off-Topic, Rechtsverstoß, Sonstiges) + Freitext; Mehrfachmeldungen desselben Beitrags werden gebündelt; Badge im Admin-Menü; Bearbeitung mit Ergebnis (berechtigt/unberechtigt/erledigt), optional Rückmeldung an Meldende. Rate-Limit gegen Meldungs-Spam. | neu + `BadgeProvider`, `notifications` | P1 | ✅ |
| COM-MOD-08 | **Verwarnungen** | Verwarnung mit Grund, Punkten und Ablaufdatum; Mitglied wird benachrichtigt; Schwellen lösen automatisch Schreibsperre oder Freigabepflicht aus (einstellbar). Übersicht je Mitglied. | neu | P2 | ⬜ |
| COM-MOD-09 | **Sperren (Bannen)** | Community-Sperre zeitlich begrenzt oder dauerhaft, mit Grund (für Mitglied sichtbar); Stufen: nur Lesen, kein Zugriff aufs Forum. Kontosperre der ganzen Site bleibt in der Core-Benutzerverwaltung. Gesperrte Mitglieder können nicht melden, reagieren, abstimmen oder Nachrichten im Forum-Kontext senden. | neu, `UserService` | P1 | ✅ (mit Ablauf) |
| COM-MOD-10 | **Freigabe-Warteschlange** | Beiträge/Themen mit Status `pending`, wenn: Forum verlangt Freigabe, Vertrauensstufe 0, Spam-Verdacht, Verwarnungsschwelle. Warteschlange mit Vorschau, freigeben/ablehnen (mit Grund), Massenaktionen; Autor sieht „wartet auf Freigabe“. Badge im Admin-Menü. | neu + `BadgeProvider` | P1 | 🟡 `is_approved`, keine Warteschlange |
| COM-MOD-11 | **Moderationsprotokoll** | Jede Moderationsaktion mit Wer, Was, Ziel, Grund, Zeit; filterbar; Aufbewahrung über Löschfristen. | neu + `DataRetentionService` | P1 | ✅ |
| COM-MOD-12 | **Spam- und Bot-Schutz** | Honeypot und Mindest-Ausfüllzeit an allen Formularen; Link-Limit für neue Mitglieder; Wortfilter (sperren oder ersetzen); Captcha bei Registrierung und – einstellbar – beim ersten Beitrag bzw. für Vertrauensstufe 0 (E7: eingebaut, Proof-of-Work, Bild-Captcha `gregwar/captcha`, Turnstile, hCaptcha, optional reCAPTCHA; Bewertung in SECURITY-PRIVACY §3). Fehlversuche ins Sicherheitsprotokoll. | `AntispamService`, neu `CaptchaService` | P1 | 🟡 Wortfilter ⚠️ |
| COM-MOD-13 | **Rate-Limiting / Langsamer Modus** | Grenzen je Mitglied: Abstand zwischen Beiträgen (Standard 30 s) und Themen (Standard 120 s), Höchstzahl pro Stunde/Tag, je Vertrauensstufe; „Langsamer Modus“ je Forum oder Thema (z. B. 1 Beitrag/10 min). Serverseitig, nicht nur im Browser. | `Security::checkDbRateLimit()` | P1 | 🟡 feste Abstände |
| COM-MOD-14 | **Technische Sicherheit** | Nur gebundene SQL-Parameter; jede POST-Aktion mit CSRF-Token; Ausgabe escaped bzw. durch den Purifier; Uploads geprüft; Rechteprüfung serverseitig bei jeder Aktion (auch API). Details und Nachweise: [SECURITY-PRIVACY.md](SECURITY-PRIVACY.md). | `Security`, `PurifierService` | P1 | ✅ (Plugin gehärtet in 3.0.1) |
| COM-MOD-15 | **Vertrauensstufen** | Stufen 0–4 automatisch aus Tagen seit Registrierung, gelesenen Themen, Beiträgen, erhaltenen Reaktionen, Verwarnungen; steuern Freigabepflicht, Links, Bilder, Anhänge, Bearbeitungsfenster, Rate-Limits. Manuell festsetzbar durch Moderation. | neu | P2 | ⬜ (Plugin: geplant) |
| COM-MOD-16 | **IP- und Gerätehinweise für Moderation** | Gekürzte IP (/24 bzw. /48) und Kennung je Beitrag nur für Moderation sichtbar, Löschfrist Standard 30 Tage; „gleiche Kennung wie …“-Hinweis gegen Mehrfachkonten. | `DataRetentionService` | P2 | ⬜ |

### 3. Community & Interaktion – Priorität 3

| ID | Funktion | Akzeptanzkriterien | Baustein | Phase | cms-forum |
|---|---|---|---|---|---|
| COM-INT-01 | **Benachrichtigungen im Forum** | In-App-Hinweise bei: Antwort in abonniertem Thema, neues Thema in abonniertem Forum, Zitat, `@Erwähnung`, Reaktion (gebündelt), Lösung markiert, Moderationsentscheidung (Freigabe, Ablehnung, Verwarnung, Meldungsergebnis). Glocke mit Zähler im Theme-Kopf. Gebündelt („3 neue Antworten in …“), kein Hinweis für eigene Aktionen. | `notifications`, `MemberService` | P1 | 🟡 eigener Dienst |
| COM-INT-02 | **E-Mail-Benachrichtigungen** | Je Ereignisart wählbar: sofort, tägliche Zusammenfassung, wöchentliche Zusammenfassung, aus. Versand über die Mail-Warteschlange; Abmeldelink in jeder Mail (signiert, ohne Login); keine zweite Mail zum selben Thema, bis das Mitglied es wieder besucht hat. | `MailQueueService`, `CronRunnerService` | P1 | 🟡 sofort; Digest ⚠️ |
| COM-INT-03 | **Abonnements** | Themen und Foren abonnieren/abbestellen; automatisch beim Erstellen/Antworten (Einstellung je Mitglied); Übersicht im Mitgliederbereich. | neu | P1 | ✅ |
| COM-INT-04 | **@Erwähnungen** | Autovervollständigung beim Tippen von `@` (nur sichtbare Mitglieder, Rate-Limit); Erwähnung wird zum Profil-Link; Benachrichtigung nur, wenn das erwähnte Mitglied das Thema lesen darf; Höchstzahl je Beitrag (Standard 10). | neu | P2 | ⬜ (nur Einstellung) |
| COM-INT-05 | **Zitate mit Benachrichtigung** | „Zitieren“ übernimmt markierten Text oder ganzen Beitrag mit Verweis auf den Quellbeitrag; zitierte Person wird benachrichtigt. | neu | P1 | 🟡 Zitat ohne Benachrichtigung |
| COM-INT-06 | **Reaktionen / Likes** | „Danke“ bzw. eine kleine, vom Admin festgelegte Auswahl (Standard: 👍 ❤️ 😂 💡); je Mitglied eine Reaktion pro Beitrag, änderbar; Liste „wer hat reagiert“; Zähler fließt in Reputation (COM-PLUS-02). Keine Reaktion auf eigene Beiträge. | neu | P1 | ✅ (Like) |
| COM-INT-07 | **Benutzerprofile** | Core-Profil (`/author/user-{id}`) bekommt einen Community-Abschnitt: Avatar (Upload im Mitgliederbereich, `ImageService` verkleinert/WebP), Biografie, Signatur (Länge begrenzt, gleiche Formatierung wie Beiträge, ohne Bilder bis Vertrauensstufe 2), Beitragszähler, Reaktionen erhalten, Rang/Abzeichen, Mitglied seit, letzte Aktivität, letzte Themen/Beiträge (nur aus lesbaren Foren). Sichtbarkeit je Feld über die vorhandenen Profil-Einstellungen. | `MemberService` (Profil, Avatar) | P1 | ✅ (eigene Profilseite) |
| COM-INT-08 | **Private Nachrichten** | Aus Profil und Beitrag „Nachricht senden“ → Core-Postfach (`/member/messages`). 1:1 sofort (P1); Gruppen-Konversationen als Erweiterung von `MessageService` (P3, E8). Blockieren von Mitgliedern; Rate-Limit vorhanden (`isSendRateLimited`). | `MessageService` | P1 / P3 | ⬜ |
| COM-INT-09 | **Gelesen-/Ungelesen-Status** | Je Mitglied und Thema letzter gelesener Beitrag; Markierung in Foren- und Themenlisten; Knopf „Zum ersten ungelesenen Beitrag“ (richtige Seite + Anker); „Alle als gelesen markieren“ je Forum und global; ältere Themen als Zeitstempel je Forum (keine Zeile pro Thema nach X Tagen, Löschfrist). Neue Beiträge seit dem Laden werden durch Abfrage im Intervall (Standard 60 s, nur bei sichtbarem Tab) angekündigt. | neu | P1 | ✅ (`ReadTracker`) |
| COM-INT-10 | **„Neue Beiträge“ / „Aktive Themen“** | Ansicht aller Themen mit ungelesenen Beiträgen bzw. letzter Aktivität, gefiltert nach Lesbarkeit. | neu | P1 | 🟡 |
| COM-INT-11 | **Wer ist online** | Mitglieder der letzten 15 min (nur wer das in den Privatsphäre-Einstellungen erlaubt), Gäste als Zahl; ohne zusätzliche Personendaten (Sitzungstabelle). | `sessions` | P2 | ⚠️ Einstellung ohne Wirkung |
| COM-INT-12 | **Lesezeichen** | Beiträge/Themen merken; nutzt die Core-Favoriten (`favorites`), sichtbar unter `/member/favorites`. | `favorites` | P2 | ⬜ |
| COM-INT-13 | **Ignorieren-Liste** | Beiträge ignorierter Mitglieder eingeklappt; keine Benachrichtigungen und Nachrichten von ihnen. Moderation ausgenommen. | neu | P2 | ⬜ |

### 4. Komfort & Technik – Priorität 4

| ID | Funktion | Akzeptanzkriterien | Baustein | Phase | cms-forum |
|---|---|---|---|---|---|
| COM-UX-01 | **Responsive / Mobile-First** | Alle Ansichten ab 320 px ohne waagerechtes Scrollen; Touch-Ziele ≥ 44 px; Editor-Werkzeugleiste scrollbar; Moderationsaktionen über ein Aktionsmenü. Lighthouse mobil ≥ 90 (Leistung, Barrierefreiheit, Best Practices, SEO) für Startseite, Forum, Thema. | Theme | P1 | ✅ |
| COM-UX-02 | **Dateianhänge** | Upload per Knopf, Ziehen und Einfügen; Typen und Größe je Forum/Vertrauensstufe (Standard: jpg, png, webp, gif, pdf, zip; 5 MB; 3 je Beitrag); Prüfung von Endung **und** MIME-Typ/Signatur; Bilder werden verkleinert, nach WebP umgewandelt, EXIF entfernt; Ablage im privaten Speicher, Auslieferung nur über eine Route mit Rechteprüfung; Vorschaubilder; verwaiste Uploads werden nach 24 h entfernt. | `PrivateStorageService`, `FileUploadService`, `ImageService` | P1 | ✅ (ohne Umwandlung) |
| COM-UX-03 | **SEO-freundliche Adressen** | `/forum/{forum-slug}`, `/forum/thema/{id}-{slug}` (E3); kanonische URL je Seite; falscher Slug → 301; alte Plugin-Adressen → 301; Sitemap mit öffentlichen Foren und Themen; `noindex` für leere Foren, Suche, Profile ohne Beiträge, Antwort-/Bearbeitungsseiten; Schema.org `DiscussionForumPosting` bzw. `QAPage` (gelöste Fragen). | `SitemapService`, `SEOService` | P1 | 🟡 |
| COM-UX-04 | **DSGVO** | Rechtstexte über das Legal-Modul (Textbausteine für das Forum); Cookie-Consent nur, wenn ein Captcha-Anbieter mit Drittland-Bezug aktiv ist; Datenexport enthält Themen, Beiträge, Reaktionen, Abos, Meldungen, Verwarnungen, Profilfelder; Konto löschen: Beiträge wahlweise anonymisieren („Gelöschtes Mitglied“) oder löschen (Einstellung + Hinweis an das Mitglied). Selbstlöschung des Kontos: siehe [SECURITY-PRIVACY.md](SECURITY-PRIVACY.md) §5. | Legal-Modul, DSGVO-Hooks | P1 | ✅ (Export/Löschen-Hooks) |
| COM-UX-05 | **Entwürfe (Auto-Save)** | Lokal (`localStorage`, je Thema/Forum, nach 30 Tagen verworfen) **und** optional serverseitig für angemeldete Mitglieder (ein Entwurf je Ziel). Hinweis „Entwurf wiederhergestellt“, Verwerfen-Knopf. Nach dem Absenden wird der Entwurf gelöscht. | neu | P2 | ⬜ |
| COM-UX-06 | **Doppelte Themen vermeiden** | Beim Tippen des Titels Vorschläge ähnlicher Themen (nur lesbare Foren, ab 3 Wörtern, entprellt). | `SearchService` | P2 | ✅ |
| COM-UX-07 | **Barrierefreiheit** | WCAG 2.2 AA: Tastaturbedienung aller Aktionen, sichtbarer Fokus, Überschriftenstruktur, `aria-live` für Statusmeldungen, Beiträge als `<article>` mit Autor/Zeit, Kontrast in beiden Designs. | Theme | P1 | 🟡 |
| COM-UX-08 | **Leistung** | Startseite, Forum und Thema mit ≤ 6 SQL-Abfragen bei warmem Cache; Zähler denormalisiert; Ausgabe-Cache je Seite für Gäste (privat für Angemeldete); 10.000 Themen / 500.000 Beiträge ohne merkliche Verlangsamung (Lasttest im TEST-PLAN). | `CacheManager` | P1 | 🟡 |
| COM-UX-09 | **Dunkles Design** | Alle Community-Komponenten folgen den Design-Tokens des aktiven Themes (inkl. Dark Mode). | Theme | P1 | 🟡 eigene Farbeinstellungen |

### 5. Nice-to-have – Priorität 5

| ID | Funktion | Akzeptanzkriterien | Baustein | Phase | cms-forum |
|---|---|---|---|---|---|
| COM-PLUS-01 | **Ränge** | Ränge nach Beitragszahl oder manuell (Titel, Farbe, Symbol); Anzeige am Beitrag und im Profil. | neu | P1 (Parität) | ✅ |
| COM-PLUS-02 | **Reputation** | Punkte aus erhaltenen Reaktionen und Lösungen, abzüglich Verwarnungen; Anzeige im Profil; fließt in Vertrauensstufen. | neu | P3 | ⬜ |
| COM-PLUS-03 | **Abzeichen (Badges)** | Automatische (erster Beitrag, 100 Beiträge, 10 Lösungen, 1 Jahr dabei …) und manuell vergebene Abzeichen; Benachrichtigung bei Vergabe; Übersichtsseite. | neu | P3 | ⬜ |
| COM-PLUS-04 | **„Als Lösung markieren“** | Ersteller und Moderation markieren eine Antwort als Lösung; Lösung erscheint direkt unter der Frage; Thema „gelöst“ in Listen und Suche; Forentyp „Fragen & Antworten“ zeigt ungelöste zuerst; Schema.org `QAPage`. | neu | P1 (Parität) | ✅ |
| COM-PLUS-05 | **OAuth / Social Login** | Anmeldung über GitHub, Google, Discord, Microsoft; Konto-Verknüpfung im Mitgliederbereich. **Eigenes Core-Vorhaben** (E9); das Forum braucht keine eigene Anpassung. | neu (Core-Auth) | außerhalb | ⬜ |
| COM-PLUS-06 | **Umfragen** | Umfrage am Thema: Frage, 2–10 Optionen, Einfach-/Mehrfachauswahl, Ende, Ergebnisse vor/nach Abstimmung, Stimme änderbar (Einstellung). | neu | P2 (Parität) | ✅ |
| COM-PLUS-07 | **Smileys / Emoji** | Emoji-Auswahl im Editor (Unicode, keine Bilder); `:shortcode:`-Umwandlung. | neu | P2 | ⚠️ Einstellung ohne Wirkung |
| COM-PLUS-08 | **Tags und Präfixe** | Themen-Tags (frei oder aus Liste, je Forum); Präfixe wie „[Gelöst]“, „[Frage]“; Filter in Listen. | neu | P3 | ⬜ |
| COM-PLUS-09 | **Automatisches Schließen** | Themen ohne Aktivität seit X Tagen automatisch schließen (je Forum). | `CronRunnerService` | P2 | ⚠️ Einstellung ohne Wirkung |
| COM-PLUS-10 | **Webhooks / Integrationen** | Ereignisse (neues Thema, Meldung) an Discord/Teams/Slack-Webhook; Hooks für Plugins (siehe ARCHITECTURE §6). | `Hooks` | P3 | ⬜ |
| COM-PLUS-11 | **KI-Unterstützung (optional)** | Bei aktivem Modul „AI Services“: Spam-Einschätzung als zusätzliches Signal (nie alleinige Entscheidung), Zusammenfassung langer Themen, Vorschlag ähnlicher Lösungen. Abschaltbar, Hinweis im Datenschutztext. | `AI`-Dienste | P3 | ⬜ |
| COM-PLUS-12 | **Feeds** | RSS je Forum und für neueste Themen (nur öffentlich lesbare Foren). | `FeedService` | P2 | ⬜ |

### 6. Administration

| ID | Funktion | Akzeptanzkriterien | Baustein | Phase | cms-forum |
|---|---|---|---|---|---|
| COM-ADM-01 | **Admin-Bereich „Community“** | Übersicht (Kennzahlen, offene Meldungen, Warteschlange), Struktur (Kategorien & Foren), Themen, Moderation (Warteschlange, Meldungen, Verwarnungen, Sperren, Protokoll), Ränge & Abzeichen, Rechte, Einstellungen. Einträge im Admin-Menü-Katalog beider Profile, Badges für Meldungen und Warteschlange. | `AdminMenu\CoreCatalog`, `BadgeProvider` | P1 | ✅ (Plugin-Admin) |
| COM-ADM-02 | **Einstellungen** | Allgemein, Beiträge & Editor, Rechte-Standards, Spam & Grenzen, Anhänge, Benachrichtigungen, SEO, Darstellung. **Jede Einstellung hat eine Wirkung und einen Test** (Lehre aus ⚠️-Einträgen des Plugins). | `SettingsService` | P1 | 🟡 |
| COM-ADM-03 | **Modul an/aus** | Über `CoreModuleService`; aus = öffentliche Routen 404, Admin-Seiten ausgeblendet, Daten bleiben. | `CoreModuleService` | P1 | – |
| COM-ADM-04 | **Datenübernahme aus cms-forum** | Migrator mit Vorschau, idempotent, Plugin wird danach deaktiviert; Details [MIGRATION-CMS-FORUM.md](MIGRATION-CMS-FORUM.md). | `IntegratedPluginService`, `PluginMigrator` | P1 | – |
| COM-ADM-05 | **Statistik** | Beiträge/Themen/aktive Mitglieder pro Tag (30/90/365), Top-Foren, Antwortzeit bis erste Antwort, Anteil gelöster Fragen; ohne personenbezogene Rohdaten. | neu | P2 | 🟡 |
| COM-ADM-06 | **Papierkorb, Export/Import** | Themen im Core-Papierkorb (`TrashProvider`); Export/Import der Struktur (Kategorien, Foren, Rechte, Einstellungen) über `ExchangeProvider`; Beiträge nur im DSGVO-Export. | `ContentTypeRegistry` | P1 / P2 | ⬜ |
