# 365CMS – Projektdokumentation | Abschnitt: Community – Sicherheit & Datenschutz
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.1 (Vorschlag) | **Status:** Planung | **Übersicht:** [README.md](README.md)

## English (summary)

Threat model and controls for the Community module (bots and spam, account abuse, XSS through user content,
CSRF, SQL injection, IDOR/permission bypass on hidden forums, malicious uploads, enumeration, DoS on search and
polling, notification abuse), the anti-spam layers, an evaluation of captcha libraries proposed for the new
`CaptchaService` (gregwar/captcha suitable, google/recaptcha optional with consent, olakunlevpn/phpcapcha rejected for
licensing, capskip/capskip-php excluded because it is a captcha *solver*), GDPR (legal basis, export, deletion,
retention periods, third-party captchas and consent) and the security checklist every work package must pass.

## Deutsch

### 1. Bedrohungen und Gegenmaßnahmen

| # | Bedrohung | Gegenmaßnahme | Nachweis (TEST-PLAN) |
|---|---|---|---|
| T1 | **Spam-Bots** registrieren sich und posten Links | E-Mail-Bestätigung vor dem Schreiben; Captcha bei Registrierung/erstem Beitrag; Honeypot + Mindestzeit (`AntispamService`); Link-Limits je Stufe; Freigabe für Stufe 0; Wortfilter; Rate-Limits | Spam-Suite S1–S8 |
| T2 | **Menschlicher Spam / Trolle** | Melden mit Schwelle für vorläufiges Ausblenden; Verwarnpunkte mit Folgen; Sperren; Langsamer Modus | Moderation M1–M12 |
| T3 | **XSS** über Beiträge, Signaturen, Titel, Profilfelder, Dateinamen | Kein Roh-HTML im Markdown; Renderer mit Link-Allowlist (`http`, `https`, `mailto`, relativ); Ausgabe nur nach `PurifierService` (Profil `community`: keine `style`-Attribute, keine `on*`-Handler, keine `javascript:`- oder `data:`-URLs); Titel und Namen immer escaped; Dateinamen nur escaped in `Content-Disposition` (RFC 5987); CSP mit Nonce, keine Inline-Skripte | XSS-Korpus X1–X30 (u. a. OWASP-Cheat-Sheet, Markdown-Sonderfälle wie `[a](javascript:…)`, verschachtelte Zitate, Code-Blöcke mit `</code><script>`) |
| T4 | **CSRF** | Jede schreibende Aktion nur per POST mit Token (`Security::generateToken`/`verifyToken`), JSON-API mit Token im Header, `SameSite`-Cookies vom Core | C1–C5 |
| T5 | **SQL-Injection** | Nur gebundene Parameter; Sortier-/Filterspalten aus Allowlists; keine dynamischen Tabellennamen außer Präfix | Semgrep im Workflow `security.yml` + Review-Checkliste §6 |
| T6 | **Rechte-Umgehung / IDOR** (interne Foren, fremde Beiträge bearbeiten, Anhänge erraten) | Jede Route fragt `CommunityAccess`; IDs in Anhang-, Beitrag-, Zitat-, Such-, Feed-, Polling- und Benachrichtigungswegen werden gegen Leserechte geprüft; Anhänge nie direkt per Datei-URL | Rechte-Matrix R1–R40 (jede Route × jede Rolle) |
| T7 | **Schädliche Uploads** (Webshell, Polyglot, SVG-XSS, Zip-Bombe, EXIF-Standort) | Allowlist Endung + `finfo`-Signatur; Bilder neu kodiert (GD/Imagick) und Metadaten entfernt; kein SVG; Ablage außerhalb des Webroots (`PrivateStorageService`); Zufallsnamen; Auslieferung mit `nosniff` und `attachment` für Nicht-Bilder; Archive werden nicht entpackt; Größen- und Pixelgrenzen | U1–U12 |
| T8 | **Enumeration** (Mitglieder, versteckte Foren) | Einheitliche 404 für „gibt es nicht“ und „darfst du nicht sehen“; Erwähnungs-Vorschläge nur ab 2 Zeichen, Rate-Limit, nur sichtbare Profile | E1–E4 |
| T9 | **DoS** über Suche, Vorschau, Polling, Reaktionen | Rate-Limits je IP/Konto (`Security::checkDbRateLimit`), Suchbegriffe begrenzt (Länge, Wörter), Vorschau nur für Angemeldete, Polling mit ETag/304 und Mindestintervall, teure Abfragen mit Index-Nachweis | D1–D6 |
| T10 | **Benachrichtigungs-Missbrauch** (Massen-Erwähnungen, Mail-Flut) | Max. Erwähnungen je Beitrag; eine Mail je Thema bis zum nächsten Besuch; Ignorieren-Liste; Mails nur über die Warteschlange mit Grenzwert | N1–N6 |
| T11 | **Kontoübernahme** | Core: Login-Grenzen, 2FA/Passkeys, Sitzungen; Community fügt nichts Eigenes hinzu | Core-Tests |
| T12 | **Open Redirect** über `redirect`-Parameter | Nur lokale Pfade (gleiche Prüfung wie `FormFrontendController::safeReturnPath`) | O1–O3 |
| T13 | **Cache-Vergiftung / Datenleck über Seiten-Cache** | Seiten mit Token oder personalisierten Teilen `private, no-store`; Gast-Cache-Schlüssel ohne Benutzerdaten; interne Foren nie im öffentlichen Cache | K1–K4 |

### 2. Antispam-Schichten (Reihenfolge der Prüfung)

1. **Konto:** E-Mail bestätigt, nicht gesperrt, Vertrauensstufe.
2. **Formular:** CSRF, Honeypot (`website_url` wie `FormSpamGuard::HONEYPOT_FIELD`), signierte Startzeit (Mindestzeit).
3. **Captcha** (nur wenn Stufe/Einstellung es verlangt) – §3.
4. **Grenzen:** Abstand zwischen Beiträgen/Themen, Höchstzahl pro Stunde/Tag, Langsamer Modus.
5. **Inhalt:** `AntispamService::evaluate()` (Links, Schlüsselwörter, leerer User-Agent), Wortfilter, Link-/Bildgrenzen der Stufe, Duplikat-Erkennung (gleicher Text in den letzten 24 h).
6. **Optional:** Filter `cms_community_spam_check` (z. B. KI-Signal, COM-PLUS-11) – darf nur auf „prüfen“ setzen, nie allein löschen.
7. **Ergebnis:** veröffentlichen, in die Warteschlange (`pending`) oder ablehnen; Ablehnungen in `security_log` (ohne Inhalt, mit Grund).

### 3. Captcha: Dienst und Bibliotheken

**`CaptchaService`** (neu, `core/Services/Security/`): eine Schnittstelle `CaptchaProvider` mit `render(context)`,
`verify(request, context)`, `requiresConsent()`, `cspSources()`. Genutzt von Community, Formularen (ersetzt die
eigene Rechenaufgabe in `FormSpamGuard` als Anbieter „eingebaut“) und Registrierung. Barrierefreiheit: Jeder
bildbasierte Anbieter braucht eine gleichwertige Alternative (WCAG 1.1.1), z. B. Rechenaufgabe als Text.

Bewertung der vorgeschlagenen Bibliotheken (Stand der Repositories am 14.11.2026, nur gelesen, nicht ausgeführt):

| Bibliothek | Art | Lizenz / Pflege | Abhängigkeiten | Datenschutz | Bewertung |
|---|---|---|---|---|---|
| [`gregwar/captcha`](https://github.com/gregwar/captcha) | Bild-Captcha, lokal erzeugt | MIT; aktiv (letzter Commit 27.08.2026); Schriften mit eigenen Lizenzdateien | PHP ^8.2, `ext-gd`, `ext-mbstring`, `ext-fileinfo`, `symfony/finder` | keine Drittanbieter, keine Einwilligung nötig | **Geeignet** als Anbieter „Bild-Captcha“. Grenzen: für OCR-Bots lösbar, nicht barrierefrei ohne Alternative → nur zusammen mit Text-Alternative und als eine Schicht von mehreren. `symfony/finder` als zusätzliche Vendor-Bibliothek prüfen (Core bringt `symfony-contracts` mit, nicht `finder`). |
| [`google/recaptcha`](https://github.com/google/recaptcha) | Client für Google reCAPTCHA (v2/v3) | BSD-3-Clause; aktiv (23.10.2026) | PHP ≥ 8.4 | Daten an Google (USA): Einwilligung über Cookie-Consent (Kategorie „Externe Medien/Sicherheit“), Datenschutztext, CSP-Freigabe `www.google.com`, `www.gstatic.com` | **Optional**, nie Standard. Ohne Einwilligung Rückfall auf einen lokalen Anbieter. Die Bibliothek ist nur ein dünner HTTP-Client; die Prüfung geht auch ohne sie über einen einfachen HTTP-Aufruf wie bei Turnstile – dann entfällt eine Abhängigkeit. |
| [`olakunlevpn/phpcapcha`](https://github.com/olakunlevpn/phpcapcha) | Bild-/Rechen-Captcha, lokal | `composer.json` nennt MIT, aber **keine LICENSE-Datei** im Repository; sehr kleines Projekt | PHP ^8.0, `ext-gd` | lokal | **Nicht empfohlen**: mitgelieferte Schrift `fonts/Monaco.ttf` ist eine proprietäre Apple-Schrift (Weitergabe nicht erlaubt); Lizenzlage unklar. Funktional durch `gregwar/captcha` + eingebaute Rechenaufgabe abgedeckt. |
| [`capskip/capskip-php`](https://github.com/capskip/capskip-php) | **Captcha-Löser** (SDK für eine Desktop-App, die reCAPTCHA, Turnstile, hCaptcha, ALTCHA, Friendly Captcha u. a. automatisch löst) | MIT | PHP ≥ 8.0, `ext-curl` | – | **Ausgeschlossen.** Kein Schutz, sondern ein Werkzeug zum Umgehen von Captchas. Gehört nicht in das CMS. Für Tests der eigenen Abwehr wird es ebenfalls nicht eingesetzt (Tests prüfen die Serverlogik direkt). |

Weitere Anbieter ohne Bibliothek (reine HTTP-Prüfung, wenige Zeilen im Dienst):

| Anbieter | Datenschutz | Empfehlung |
|---|---|---|
| Eingebaut: Rechenaufgabe (aus `FormSpamGuard`) | lokal | Standard-Rückfall, barrierearm |
| Proof-of-Work, selbst gehostet (ALTCHA-Prinzip: Browser löst eine Rechenaufgabe, Server prüft mit HMAC) | lokal, keine Einwilligung | **Empfohlener Standard**; Bibliothek bzw. eigene Umsetzung in Phase P1 prüfen |
| Cloudflare Turnstile | Drittanbieter (USA), datensparsam | optional mit Einwilligung/Hinweis |
| hCaptcha | Drittanbieter | optional mit Einwilligung/Hinweis |

Schlüssel für Drittanbieter liegen verschlüsselt in den Einstellungen (`CryptoService`); fehlt ein Schlüssel, warnt
ein Admin-Hinweis und der Dienst fällt auf „eingebaut“ zurück (kein stilles Abschalten des Schutzes).

### 4. Ausgabe- und Speicherregeln

- Gespeichert wird die **Quelle** (Markdown); ausgegeben nur `body_html` nach dem Purifier. Ein Renderer-Update erhöht `rendered_version` und rendert per Batch neu.
- Titel, Namen, Signaturen ohne Markdown-Bilder bis Stufe 2; Signaturen maximal 500 Zeichen und 3 Zeilen.
- Externe Links: `rel="nofollow ugc noopener"`, optional Hinweisseite vor dem Verlassen (Einstellung).
- Externe Bilder in Beiträgen: Standard aus (nur Anhänge), sonst nur von erlaubten Hosts und mit Einwilligung „Externe Medien“ (IP-Übertragung an Fremdserver).
- Code-Highlighting ohne CDN (E5).

### 5. Datenschutz (DSGVO)

| Thema | Festlegung |
|---|---|
| Rechtsgrundlage | Nutzungsvertrag (Art. 6 Abs. 1 lit. b) für Konto und Beiträge; berechtigtes Interesse (lit. f) für Spam-Schutz und Moderation; Einwilligung (lit. a) für Drittanbieter-Captcha und externe Medien |
| Datensparsamkeit | Keine Klar-IP in Community-Tabellen; nur HMAC einer gekürzten IP an Beiträgen (Löschfrist 30 Tage), nur für Moderation |
| Export | `dsgvo_export_data`: alle Inhalte und Zuordnungen des Mitglieds (W13) als Teil des Core-Exports |
| Löschung | `dsgvo_delete_data`: Anonymisieren (Standard) oder Löschen der Beiträge (Einstellung, im Datenschutztext genannt) |
| Selbstlöschung des Kontos | Core bietet heute den **Löschantrag** (`member/privacy.php`, `privacy_delete_request`); sofortige Selbstlöschung ist ein Core-Vorhaben außerhalb dieses Moduls. Die Community reagiert auf beide Wege gleich. |
| Profile | Sichtbarkeit je Feld über die vorhandenen Profil-Einstellungen (`public_profile_fields`); „Wer ist online“ nur mit Zustimmung des Mitglieds |
| Rechtstexte | Legal-Modul erhält Textbausteine: Forum, Moderation, Captcha-Anbieter, Benachrichtigungsmails |
| Cookies | Community setzt keine eigenen Cookies; lokale Entwürfe in `localStorage` sind technisch notwendig und werden im Datenschutztext erwähnt |

**Löschfristen** (`DataRetentionService::registerRule`, Standardwerte, einstellbar):

| Regel | Daten | Standard |
|---|---|---|
| `community.ip_hash` | `community_posts.ip_hash` | 30 Tage |
| `community.read_states` | Lesestatus älter als die Forum-Lesemarke bzw. älter als | 180 Tage |
| `community.orphan_uploads` | Anhänge ohne Beitrag | 24 Stunden |
| `community.drafts` | Server-Entwürfe | 30 Tage |
| `community.reports` | erledigte Meldungen | 365 Tage |
| `community.moderation_log` | Protokoll | 365 Tage |
| `community.warnings` | abgelaufene Verwarnungen | 2 Jahre |
| `community.revisions` | Bearbeitungsverlauf | 365 Tage (letzte Fassung bleibt) |

### 6. Sicherheits-Checkliste je Arbeitspaket

- [ ] Jede neue Route: Rechteprüfung über `CommunityAccess`, 404 statt 403 bei fehlendem Leserecht.
- [ ] Jede schreibende Aktion: POST + CSRF + Rate-Limit + Protokoll (bei Moderation).
- [ ] Jede Ausgabe von Nutzerinhalt: escaped oder durch den Purifier; neue Felder im XSS-Korpus.
- [ ] Jede SQL-Abfrage: gebundene Parameter, Allowlist für Spalten/Sortierung, Index vorhanden.
- [ ] Jede Einstellung: Leser im Code + Test (keine wirkungslosen Schalter).
- [ ] Jede neue Datenart: Löschfrist, DSGVO-Export und -Löschung bedacht.
- [ ] Kein Inline-Skript, keine externen Ressourcen ohne CSP-Eintrag und Einwilligungsprüfung.
