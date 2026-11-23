# 365CMS Audit-Katalog 4.2.0 (Prüfen und Umsetzen)

> **Stand:** 2026-11-23 | **Release-Baseline:** 4.2.0 / Schema v27 / Git-Tag `V4.2.0`
> **Ablauf:** [Delta-Audit](AUDIT-ABLAUF-PLAN-DELTA.md) · [Vollaudit](AUDIT-ABLAUF-PLAN-VOLLAUDIT.md)
> **Ausführung:** [Agent-Protokoll](AUDIT-AGENT-PROTOKOLL.md) · [Kontext-Slices](AUDIT-SLICES.md) · [Lokale Modelle](AUDIT-LOCAL-LLM.md)

## English (summary)

Single source of truth for *what* a 365CMS audit checks: twenty work packages (AP-01 … AP-20) with numbered check
items, entry points, matching test suites, search recipes and deliberate decisions that must not be re-reported. The
*how* (order, context slices, prompts, ledger, model profiles) lives in the run plans and the agent protocol. Every
check item is answered with `ja` / `nein` / `n. a.` / `offen` plus a `file:line` reference. Findings without a
reproducible flow are not implemented.

## Deutsch

### 1. Auftrag

Prüfe 365CMS (Verzeichnis `CMS/` ohne `CMS/vendor/` und ohne gebündelte Drittbibliotheken unter `CMS/assets/` –
diese nur in AP-16) auf

- **Sicherheit und Exploits** (Zugriff, Eingaben, Ausgaben, Sitzungen, Kryptografie, Uploads, Lieferkette),
- **Fehler** (Logik, Zustände, Wettläufe, Randfälle, PHP-8.4/8.5-Verhalten),
- **fehlende Funktionen und fehlerhafte Verweise** (Routen, Menüs, Hooks, Sprachschlüssel, Einstellungen, Doku),
- **Geschwindigkeit und Skalierung** (Abfragen je Seite, Indizes, Caches, Bot- und Massenlast),
- **Best Practice** (Fehlerbehandlung, Protokollierung, Datenschutz, Betrieb, Tests)

und **setze belegte Befunde um**: kleine Änderung, Regressionstest, Gegenprobe. Ein Befund ohne Fundstelle
(`Datei:Zeile`) und ohne nachvollziehbaren Ablauf (Eingabe → Wirkung) wird **nicht** umgesetzt.

### 2. Ausgangslage

#### 2.1 Architektur

| Bereich | Pfad | Hinweis |
|---|---|---|
| Einstieg, Router | `CMS/index.php`, `CMS/core/Router.php`, `CMS/core/Routing/*` (`PublicRouter`, `AdminRouter`, `MemberRouter`, `ApiRouter`, `PaymentRouter`, `SsoRouter`, `ThemeRouter`, `ThemeArchiveRepository`) | Adminbereich verlangt Rolle `admin`; Ausnahmen nur über `DelegatedAdminAccess::PAGES` |
| Kern | `CMS/core/*.php` (`Bootstrap`, `Auth`, `Security`, `Database`, `Hooks`, `CacheManager`, `PluginManager`, `ThemeManager`, `SchemaManager`, `MigrationManager`, `DatabaseUpdateRunner`, `Container`, `Logger`, `AuditLogger`, `SubscriptionManager`, `Totp`) | globaler CSRF-Guard, Hook-System, PDO-Wrapper |
| Anmeldung | `CMS/core/Auth/*` (`MFA/`, `Passkey/`, `SSO/`, `LDAP/`), `CMS/core/Http/*` | TOTP, Passkeys, Entra ID (OIDC/PKCE), LDAP |
| Dienste | `CMS/core/Services/*` (Root-Dienste und Unterbereiche wie `Security/`, `Payments/`, `Webhooks/`, `Media/`, `Backup/`, `Community/`, `Content/`, `Forms/`, `Newsletter/`, `Legal/`, `AI/`) | überwiegend Singletons |
| Admin | `CMS/admin/*.php` (Einstiege), `CMS/admin/modules/<bereich>/`, `CMS/admin/views/`, `CMS/admin/partials/` | je Seite Capability und CSRF-Aktion |
| Mitglieder | `CMS/member/` | `/member/...`, `PluginDashboardRegistry` |
| Frontend | `CMS/views/`, `CMS/themes/cms-default/` | CSP mit Nonce, Trusted Types, `Http\InlineStyleRewriter` |
| Plugins | `CMS/plugins/cms-importer/`, `CMS/core/PluginManager.php` | Plugin-Code läuft mit Kernrechten |
| Installation/Update | `CMS/install.php`, `CMS/install/`, `CMS/update.php`, `Services/UpdateService.php`, `CMS/update.json`, `RELEASE/` | SHA-256-Pflicht, Verzeichnistausch |
| Zeitgesteuert, extern | `CMS/cron.php`, `CMS/orders.php`, `Services/MarketplaceEndpoints.php` | Cron-Last, Checkout |
| Laufzeitdaten | `CMS/uploads/`, `CMS/private/`, `CMS/backups/`, `CMS/cache/`, `CMS/logs/`, `CMS/config/` | Schutzdateien |
| Sprache | `CMS/lang/de.yaml`, `CMS/lang/en.yaml` | Schlüssel in beiden Katalogen |
| Tests | `TESTS/<suite>/run.php`, `TESTS/lib/`, `TESTS/community-mutants/` | `php TESTS/run-all.php` findet alle Suiten dynamisch (`glob('TESTS/*/run.php')`) |
| CI | `.github/workflows/security.yml` | Semgrep, Trivy (`.github/deps/`), PHP-Lint 8.4/8.5 mit Deprecation-Gate, `run-all.php` |

#### 2.2 Frühere Audits (vorher lesen, nicht doppelt melden)

[AUDIT-ZUSAMMENFASSUNG-AKTUELL.md](AUDIT-ZUSAMMENFASSUNG-AKTUELL.md),
[AUDIT-ZUSAMMENFASSUNG-LEGACY.md](AUDIT-ZUSAMMENFASSUNG-LEGACY.md), [Offene Befunde](audits/README.md#offene-befunde) und die
datierten Berichte in `audits/` und `audit_2026-10-14/`. Abschnitte „Akzeptiert“ und „Verworfen“ früherer Berichte gelten weiter,
solange sich der Code an der Stelle nicht geändert hat (`git log -L` bzw. `git blame` prüfen).

#### 2.3 Schwerpunkt seit 4.1

Community-Modul (`CMS/core/Services/Community/`, `CMS/views/community/`, `CMS/assets/js/community/`,
`CMS/admin/modules/community/`, Spezifikation [../v4.2/](../v4.2/README.md)), `Security\CaptchaService`,
Redirect-Manager, Papierkorb-Provider, Datenschutz-Hooks, Schema v27, Medien-Metadaten und -Orientierung,
Bestellnummern und Zeitzonen in Payments, Rollen-Hooks (`cms_role_renamed`, `cms_role_deleted`).

#### 2.4 Bewusste Entscheidungen (nicht als Befund melden)

| ID | Entscheidung |
|---|---|
| E6 | keine Datenübernahme aus dem Plugin `cms-forum` |
| E7 | Captcha nur lokal (`pow`, `image`, `math`), keine Drittanbieter |
| ZUGRIFF | `CommunityAccess::can()` ist die einzige Community-Entscheidungsstelle; fehlende Berechtigung antwortet mit 404 statt 403 |
| ZÄHLER | Zählersemantik S1 über `CounterBook`; Community-Listen sind Keyset-Listen ohne `OFFSET` |
| ADMIN | `/admin` verlangt Rolle `admin`; delegierbare Ausnahmen nur über `DelegatedAdminAccess::PAGES` |
| CSP | `script-src 'self' 'nonce-…'` ohne `strict-dynamic`, Trusted Types mit Standard-Richtlinie |
| BUDGET | Forum und Folgeseiten eines Themas: 7 statt 6 Abfragen (bewusste Abweichung in `community-performance`) |
| RELEASE | Update-Paket enthält kein `config.php`, `config/`, `uploads/`, `logs/`, `backups/`, `marketplace/`, `update.json` |
| UNBEKANNT-AKTIV | `CoreModuleService` lässt unbekannte Slugs aus Kompatibilität aktiv; Community prüft fail-closed über `Community\Support\CommunityModule::isEnabled()` |
| OFFEN-27 | Update-Signatur ist bekannt offen ([Offene Befunde](audits/README.md#offene-befunde), 42X-06) – nicht neu nummerieren |
| PERF-410-49 | WXR-Import in einem Request ist bekannt; nur neu melden, wenn sich das Risiko verschärft hat |

### 3. Befunde

#### 3.1 Schwere

| Stufe | Kriterium |
|---|---|
| **CRITICAL** | Codeausführung, Konto- oder Systemübernahme ohne Anmeldung, Datenabfluss über alle Mitglieder, Umgehung der Anmeldung |
| **HIGH** | Rechteausweitung, fremde private Daten, gespeichertes XSS, SQL-Injection, SSRF auf interne Ziele, Zahlungsumgehung, Datenverlust im Normalbetrieb |
| **MEDIUM** | Wettlauf mit falschem Ergebnis, CSRF auf Zustandsänderung, Informationsabfluss, einfacher Dienstausfall, falsche Zähler, fehlerhafte Löschung |
| **LOW** | Härtung, fehlende Protokollierung, Randfälle ohne Sicherheitswirkung, Detail-Fehlermeldungen, tote Verweise |
| **INFO** | Best Practice, Empfehlung ohne Fehlverhalten |

#### 3.2 ID-Schema

`<BEREICH>-<RELEASE>-<NR>`, z. B. `SEC-420-07`. Bereiche: `SEC` Sicherheit, `COM` Community, `DB` Datenbank,
`FUN` Funktionen/Verweise, `PERF` Leistung, `BP` Best Practice, `LIB` Bibliotheken, `PRIV` Datenschutz, `OPS`
Betrieb, `A11Y` Barrierefreiheit, `AI` KI. `<RELEASE>` ist die geprüfte Version ohne Punkte (`420`, `421` …).
Nummern sind je Lauf fortlaufend; Agenten vergeben vorläufige IDs (`TMP-<Slice>-<n>`), die Konsolidierung nummeriert.

#### 3.3 Format

Das verbindliche Markdown- und JSONL-Format steht im [Agent-Protokoll §5](AUDIT-AGENT-PROTOKOLL.md#5-befundformat).
Pflichtfelder: Fundstelle, Ablauf, Auswirkung, Nachweis, Korrektur, Regression, Status, Konfidenz.

### 4. Arbeitspakete

Jeder Prüfpunkt wird mit `ja` (erfüllt), `nein` (Befund), `n. a.` (nicht anwendbar) oder `offen` (nicht prüfbar, mit
Grund und Prüfvorgehen) und Fundstelle beantwortet. „Wo“ nennt Einstiegspunkte, keine vollständige Liste; Pfade
relativ zu `CMS/`. „Tests“ nennt passende Suiten; neue Prüfungen gehören dorthin. Welche Dateien in welchem
Kontextfenster gelesen werden, regelt [AUDIT-SLICES.md](AUDIT-SLICES.md).

#### AP-01 Anmeldung und Sitzung

**Wo:** `core/Auth.php`, `core/Auth/*`, `core/Security.php`, `core/Http/*`, `core/Totp.php`, `core/Services/Security/*`,
`Services/LoginThrottleSettings.php`, `EmailVerificationService.php`, `EmailChangeService.php`, `JwtService.php`,
`CmsAuthPageService.php`, `core/Routing/PublicRouter.php`, `core/Routing/SsoRouter.php`, `views/auth/`.
**Tests:** `password-policy`, `mfa-enforcement`, `sso-entra`, `login-showcase`, `security`, `user-role-hook`.

| ID | Prüfpunkt |
|---|---|
| 01.1 | Sitzungs-ID wird bei Anmeldung und Rechte-/Rollenwechsel erneuert; Cookie `HttpOnly`, `Secure`, `SameSite`; Ablauf, Fixation, parallele Sitzungen |
| 01.2 | Brute-Force je Konto **und** je IP, ohne Konto-Aufzählung (gleiche Antwort, ähnliche Zeit); Zähler nur nach Erfolg zurücksetzen |
| 01.3 | Passwort: `password_hash`, Rehash, Richtlinie, Unicode/Leerzeichen; Reset-Token zufällig, gehasht, einmalig, befristet, beendet alle Sitzungen |
| 01.4 | MFA: TOTP-Replay, Zeitfenster, Versuchslimit, Backup-Codes einmalig und gehasht; Deaktivieren nur mit Passwort; MFA-Zwang auf allen Wegen (Passwort, SSO, Passkey, API) |
| 01.5 | Passkey: Challenge einmalig und gebunden, `rp_id`/Origin, Signaturzähler, Löschen mit Bestätigung |
| 01.6 | SSO: `state`, `nonce`, PKCE, `iss`/`aud`/`tid`/`oid`, Tenant-Allowlist, Auto-Link nur mit `xms_edov` und Nicht-Privilegrollen (inkl. `moderator`), Rollen-Mapping, SSO-Herkunft der Sitzung |
| 01.7 | LDAP: Filter-/DN-Escaping, TLS-Prüfung, Fehlerfälle nie als Erfolg |
| 01.8 | JWT: Algorithmus fest (kein `none`), Ablauf, Token-Version/Widerruf, Bindung an Konto und Passwortstand, `hash_equals` |
| 01.9 | E-Mail-Änderung/-Bestätigung: Token-Bindung, Hinweis an alte Adresse, Wettlauf mit Registrierung, Rate-Limit |
| 01.10 | Registrierungs-/Login-Seiten: Aufzählung, `register_captcha`, Antispam, offene Weiterleitung (`return_to`, `_return`, `redirect`) |

#### AP-02 Autorisierung, Rollen, Objektzugriff

**Wo:** `core/Routing/*`, `admin/*.php`, `admin/modules/*`, `member/*`, `Services/ContentEditPermission.php`,
`UserService.php`, `admin/modules/users/`, `MemberContentService.php`, `PrivateStorageService.php`,
`AdminSiteContextService.php`, `includes/functions/`.
**Tests:** `admin-menu`, `member-area`, `security`, `user-role-hook`, `community-access`.

| ID | Prüfpunkt |
|---|---|
| 02.1 | Jeder Admin-Einstieg prüft Anmeldung, Rolle/Capability und CSRF-Aktion – auch AJAX, Download, Export, `?action=`, Sammelaktionen, `*-api.php` |
| 02.2 | IDOR: Objekte anderer Konten per ID (Mitgliederbereich, Rechnungen, Nachrichten, Favoriten, Uploads, Backups, Exporte, Community) – Besitz **und** Recht serverseitig |
| 02.3 | Rechteausweitung: Rolle/Capabilities bei Anlegen, Ändern, Sammelaktion, Import, SSO-Mapping, Gruppen; Systemrolle `moderator` nicht umbenenn- oder löschbar |
| 02.4 | Site-Kontext (`AdminSiteContextService`, `SiteTable/*`, Hub-Sites): Daten anderer Sites lesbar oder schreibbar? |
| 02.5 | Direkte PHP-Einstiege außerhalb des Routers (`orders.php`, `cron.php`, `update.php`, `install.php`, `member/*.php`, Medienauslieferung) prüfen selbst |
| 02.6 | Vorschau-, Freigabe-, Download- und Abmelde-Token: Zufall, Ablauf, Bindung an Objekt/Konto, nur Hash gespeichert |
| 02.7 | Delegierbare Rechte (Zahlungen, Rechnungen, Block-Vorlagen, Webhooks): nur genau diese Fähigkeit |
| 02.8 | Typverwechslung (`'0' == false`, `in_array` ohne `strict`, String-Vergleich von Rollen, Groß-/Kleinschreibung) |

#### AP-03 Eingaben: Injection, Traversal, SSRF

**Wo:** alle Stellen mit `$_GET`, `$_POST`, `$_REQUEST`, `$_FILES`, `$_SERVER`, `php://input`; `core/Database.php`;
`core/Http/Client.php`; `TrustedProxyService.php`; `RedirectService.php`.
**Tests:** `security`, `functions`, `slugs`, `admin-redirects`.

| ID | Prüfpunkt |
|---|---|
| 03.1 | SQL: Fremdwerte nur über Platzhalter; dynamische Teile (Tabelle, Spalte, `ORDER BY`, `LIMIT`, `IN (…)`, `LIKE`) per Allowlist bzw. `cms_escape_like()` |
| 03.2 | Pfade: `..`, absolute Pfade, Null-Bytes, Backslash, Symlinks; `realpath`-Vergleich gegen die Wurzel; Zip-Slip |
| 03.3 | Prozesse: `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`, Backticks – keine Fremdwerte, `escapeshellarg` |
| 03.4 | `include`/`require` mit Variablen, `eval`, `assert`, `unserialize` auf Fremddaten, `extract`/`$$` |
| 03.5 | SSRF: Feeds, Importer, Webhooks, Bild-Abruf, Marketplace, Link-Prüfung, KI, S3 – Schema-Allowlist, Ziel-IP (privat, Loopback, Link-Local, Metadaten, IPv4-mapped IPv6), Redirects erneut prüfen, DNS-Rebinding, Zeit-/Größenlimit |
| 03.6 | Offene Weiterleitung: `header('Location: …')` mit Fremdwert nur über `Security::safeLocalPath()` o. ä. |
| 03.7 | Header-Injection: Mail-Header, `header()`, Cookies, `Content-Disposition`-Dateinamen |
| 03.8 | XML/XXE, YAML, CSV (Formel-Injection `= + - @`), JSON-Tiefe/-Größe, Arrays statt Strings (`a[]=`), Typverwirrung (vgl. `cms_checkout_post_string()`) |
| 03.9 | Regex mit Fremdwerten: `preg_quote`, ReDoS, `preg_*`-Rückgabe `false`/`null` behandelt |
| 03.10 | Host-Header und `X-Forwarded-*` nur über `TrustedProxyService`; Mail-Links und Canonical nicht aus dem Host-Header |

#### AP-04 Ausgabe: XSS, CSP, Vorlagen

**Wo:** `views/`, `themes/cms-default/`, `admin/views/`, `PurifierService.php`, `EditorJsRenderer.php`,
`core/Http/InlineStyleRewriter.php`, `Community/Support/ContentRenderer.php`, `assets/js/`.
**Tests:** `csp-style-rewriter`, `editorjs-csp`, `editorjs-blocks`, `community-xss`, `community-render`, `theme-contract`, `feeds-design`.

| ID | Prüfpunkt |
|---|---|
| 04.1 | Kontextgerechtes Escaping in HTML-Text, Attributen, URLs (Schema-Allowlist, kein `javascript:`/`data:`), JS (`json_encode` mit `JSON_HEX_*`), CSS |
| 04.2 | Gespeicherte Inhalte (Beiträge, Kommentare, Profile, Signaturen, Formulare, Newsletter, Landing, Editor.js) werden beim **Ausgeben** bereinigt; Purifier-Profil je Kontext; Block-Allowlist |
| 04.3 | Community-Markdown: kein Roh-HTML, Linkschemata, Bild-Hosts, Zitat-Marker; Korpus in `community-xss` erweitern |
| 04.4 | CSP: Nonce je Anfrage, kein `unsafe-inline`/`unsafe-eval`, keine Inline-Handler, `style=` über `InlineStyleRewriter`; Trusted Types ohne ungeprüfte `innerHTML`-Senken |
| 04.5 | DOM-XSS im eigenen JS: `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`, `location`, `eval`, `postMessage` ohne Origin-Prüfung |
| 04.6 | Admin-Ansichten mit Fremdinhalt (Logs, Benutzernamen, Formulareingänge, Fehler, Referer, User-Agent) |
| 04.7 | Fehler- und Debug-Ausgaben ohne Pfade, SQL, Secrets |
| 04.8 | `application/ld+json` und `data-*`-JSON: Maskierung von `<`, `>`, `&`, Anführungszeichen |
| 04.9 | Dateiauslieferung: `Content-Type`, `nosniff`, `Content-Disposition`, `Content-Security-Policy: sandbox` für Nutzerdateien |
| 04.10 | Vorschauen (Block-Vorschau, Community-Vorschau, `/preview/<token>`) nutzen dieselbe Bereinigungskette wie die Ausgabe |

#### AP-05 CSRF, Header, Cookies, Browser-Schutz

**Wo:** `core/Security.php`, `core/Router.php`, `core/Bootstrap.php`, `admin/partials/`, `admin/*-api.php`.
**Tests:** `security`, `csp-style-rewriter`, `community-api`.

| ID | Prüfpunkt |
|---|---|
| 05.1 | Jede Zustandsänderung trägt ein Token (Aktionsname je Formular); JSON-/AJAX-Endpunkte prüfen Header **und** Token |
| 05.2 | Keine Zustandsänderung per GET (Logout, Löschen, Freigeben, Abo beenden, Redirect anlegen) |
| 05.3 | Header: CSP, `frame-ancestors`/XFO, Referrer-, Permissions-Policy, HSTS, COOP/CORP; `private, no-store` für Mitgliedsdaten |
| 05.4 | CORS: keine `*`-Freigabe mit Credentials, Allowlist, Preflight |
| 05.5 | Clickjacking: Admin und Login nicht einbettbar |
| 05.6 | Cookies: Name, Pfad, Domain, Lebensdauer; keine Geheimnisse im Klartext |

#### AP-06 Uploads, Medien, Dateien, Backups

**Wo:** `FileUploadService.php`, `ImageService.php`, `MediaService.php`, `Services/Media/*` (u. a. `ImageMetadataStripper`,
`ImageOrientation`), `MediaDeliveryService.php`, `PrivateStorageService.php`, `ElfinderService.php`, `Services/Backup/*`,
`BackupService.php`, `Community/Service/AttachmentService.php`, `Community/Http/*`, `Services/Forms/*`.
**Tests:** `media-images`, `media-avif`, `media-usage`, `community-uploads`, `s3-backup`.

| ID | Prüfpunkt |
|---|---|
| 06.1 | Typ: Endung, MIME aus Inhalt (`finfo`), Magic Bytes, Doppel-Endung, Null-Byte, Groß-/Kleinschreibung; Allowlist |
| 06.2 | Bilder: Neukodierung bzw. verlustfreier Metadaten-Strip, Pixel-/Größengrenzen (Dekompressionsbombe), Animation, EXIF-Orientierung (8 Werte), Alpha, SVG nur bereinigt oder gar nicht |
| 06.3 | Ablage: serverseitige Dateinamen, keine Ausführung, private Dateien nur über Auslieferung mit Rechteprüfung |
| 06.4 | Auslieferung: Rechte je Datei, Range, ETag/Cache ohne Rechteumgehung, Header-Escaping |
| 06.5 | Archive und Importe: Zip-Slip, Entpackgröße, Anzahl, Symlinks, `cms-importer` |
| 06.6 | Kontingente, verwaiste Uploads (Community nach 24 h), Wettlauf Upload ↔ Zuordnung |
| 06.7 | Backups: Verschlüsselung, nicht öffentlich, nicht erratbar, Restore prüft Integrität, Offsite löscht keine fremden Objekte |

#### AP-07 Kryptografie, Geheimnisse, Konfiguration

**Wo:** `CryptoService.php`, `JwtService.php`, `core/Security.php`, `config.php`, `config/`, `SettingsService.php`,
`OptionStore.php`, `install/InstallerService.php`.
**Tests:** `security`, `s3-backup`, `sso-entra`.

| ID | Prüfpunkt |
|---|---|
| 07.1 | Zufall nur `random_bytes`/`random_int` für Token, Schlüssel, Dateinamen |
| 07.2 | AEAD, einmalige Nonce, Schlüsselableitung, Rotation (`CMS_ENCRYPTION_KEYS_PREVIOUS`), kein Fehler-Orakel |
| 07.3 | `hash_equals` für Geheimnisse und Signaturen; Token nur als Hash gespeichert |
| 07.4 | Secrets (API, SMTP/Graph, Zahlungen, S3, KI, Entra) verschlüsselt, nie in Logs, Fehlern, Exporten, Backups im Klartext; Admin-Ansicht maskiert |
| 07.5 | Sichere Defaults, `config.php` nicht abrufbar, Debug aus, keine Beispiel- oder Testkonten, Dateirechte |
| 07.6 | Zeit: Token-Ablauf mit UTC und Toleranz, Zeitzonenfehler (siehe AP-10) |

#### AP-08 Zahlungen, Abos, Bestellungen, Webhooks

**Wo:** `Services/Payments/*`, `core/Routing/PaymentRouter.php`, `orders.php`, `core/SubscriptionManager.php`,
`Services/Webhooks/*`, `Services/Payments/InvoiceService.php`.
**Tests:** `payments`, `invoices`, `webhooks`.

| ID | Prüfpunkt |
|---|---|
| 08.1 | Anbieter-Callbacks: Signatur (Konstantzeit), Zeitfenster, Idempotenz je Ereignis-ID, veraltete Ereignisse, Zuordnung |
| 08.2 | Betrag und Währung serverseitig, Rundung, Verlängerung, Steuer, Storno, negative Beträge |
| 08.3 | Abo-Zustandsautomat (aktiv, gekündigt, abgelaufen, Erstattung, Rückbuchung), Wettlauf, Transaktionen; `cms_payment_refunded` |
| 08.4 | Rechnungen: lückenloser Nummernkreis unter Last, Zugriff nur Besitzer/Recht, PDF ohne Injection und mit Grenzen |
| 08.5 | Bestellnummer: `PaymentService::provisionalOrderNumber()` eindeutig, `formatOrderNumber()` Platzhalter/Fallback, Verlängerungssuffix |
| 08.6 | Zeitzonen: `current_period_end` UTC, Kernspalten Ortszeit, keine Vermischung bei `NOW()`-Vergleichen |
| 08.7 | Ausgehende Webhooks: Signatur, SSRF, Wiederholung mit Grenze, Deaktivierung, Secret einmal sichtbar, Löschfrist |
| 08.8 | Test-/Live-Modus nicht verwechselbar, getrennte Schlüssel |

#### AP-09 Installation, Update, Marketplace, Plugins, Themes

**Wo:** `install.php`, `install/`, `update.php`, `UpdateService.php`, `MarketplaceEndpoints.php`, `core/PluginManager.php`,
`core/ThemeManager.php`, `core/Routing/ThemeRouter.php`, `RELEASE/`, beide `update.json`.
**Tests:** `release-package`, `update-swap`, `schema-upgrade`, `theme-contract`.

| ID | Prüfpunkt |
|---|---|
| 09.1 | Installer nach Installation gesperrt (`install.lock`), keine Neuinstallation per Parameter, keine Zugangsdaten in Logs |
| 09.2 | Update: HTTPS, Host-Allowlist, SHA-256-Pflicht, Größe/Typ, Staging-Entpacken, atomarer Tausch mit Rollback, Sperre, Laufzeitordner erhalten |
| 09.3 | DB-Update: erst Code, dann Schema; Downgrade-Schutz; Fehler hinterlassen nutzbares System; Backup vorher |
| 09.4 | Plugins/Themes: Archiv-Prüfung, Manifest, Aktivierung nur Admin, dokumentierte Vertrauensgrenze; Filter in Sicherheitsentscheidungen dürfen nur verschärfen |
| 09.5 | Marketplace-Feeds: Prüfsumme, keine Ausführung von Feed-Inhalt |
| 09.6 | `RELEASE/`: Allowlist-Inhalt, keine Zugangsdaten, `SHA256SUMS` stimmt, Manifeste verweisen auf das Paket |
| 09.7 | OFFEN-27: aktuellen Stand der Signaturfähigkeit dokumentieren, nicht neu nummerieren |

#### AP-10 Datenbank: Schema, Migration, Konsistenz, Wettläufe

**Wo:** `core/SchemaManager.php`, `core/MigrationManager.php`, `core/DatabaseUpdateRunner.php`, `core/Database.php`, alle Repositories.
**Tests:** `schema-v27`, `schema-v26`, `schema-upgrade`, `community-db`, `community-repositories`, `content-types`.

| ID | Prüfpunkt |
|---|---|
| 10.1 | Neuinstallation = Update (Tabellen, Spalten, Indizes), Strict Mode, InnoDB, `utf8mb4_unicode_ci`, Schlüssellängen, Idempotenz, `REQUIRED_INDEXES`/`REQUIRED_COLUMN_DEFINITIONS` |
| 10.2 | Transaktionen atomar, einheitliche Sperrfolge, `FOR UPDATE` nur in Transaktion, Deadlock-Wiederholung |
| 10.3 | Zähler und abgeleitete Werte atomar, korrigierbar, nie negativ |
| 10.4 | Eindeutigkeit (Slugs, Token, Zuordnungen) unter Wettlauf |
| 10.5 | Fremdschlüssel-Ersatz: manuelle Löschkaskaden vollständig (Konto, Inhalt, Papierkorb), Aufräumjobs |
| 10.6 | Zeit: UTC vs. Sitzungszeitzone (`Database` setzt `time_zone` auf PHP-Offset), `NOW()` vs. PHP, Sommerzeit, Keyset-Cursor bei gleichen Zeitstempeln |
| 10.7 | Wachstum: Tabellen ohne Aufbewahrungsregel (Logs, Analysen, Queues, Versuche) |
| 10.8 | MariaDB-/MySQL-Unterschiede, Reconnect, Timeouts; Schema-Prüfungen nur über `Database::runSchemaOnce()` |

#### AP-11 Community-Modul

**Wo:** `Services/Community/**`, `views/community/`, `assets/js/community/`, `admin/modules/community/`,
`admin/views/community/`, `Services/FeatureHooks/CommunityHooks.php`; Spezifikation
[../v4.2/SECURITY-PRIVACY.md](../v4.2/SECURITY-PRIVACY.md), [../v4.2/PERMISSIONS-MODERATION.md](../v4.2/PERMISSIONS-MODERATION.md).
**Tests:** alle `community-*`.

| ID | Prüfpunkt |
|---|---|
| 11.1 | Rechte nur über `CommunityAccess`; Nebenwege: Suche, Sitemap, Feeds, Zitate, Benachrichtigungen, Mails, Anhänge, Lesestand, Profil, Mitgliederbereich, JSON-API, Papierkorb, Export |
| 11.2 | 404 statt 403 durchgängig; keine Zeit-, Längen- oder Cache-Unterschiede; interne Foren nie öffentlich indexiert |
| 11.3 | Sanktionen, Vertrauensstufen, Freigabepflicht, Bearbeitungsfenster, Raten – auch über `/forum/api/*` und parallel |
| 11.4 | `WriteGate`, `ContentGuard`, `CaptchaGate` vor **jedem** Schreiben (Neu, Antwort, Bearbeiten, Verschieben) |
| 11.5 | S1-Zähler (`CounterBook`) bei jedem Statuswechsel exakt umkehrbar; Abgleich Zählen gegen Summen |
| 11.6 | Datenschutz: Export vollständig ohne Fremddaten; `erase_mode` `anonymize`/`delete` inkl. `community_posts.deleted_at`; `CommunityRetention`; `ip_hash` nie Klartext; Hooks auch bei Modul aus aktiv |
| 11.7 | Benachrichtigungen: Queue, Abmeldetoken, maskierte Inhalte, Header-Injection, Mail-Bombing, Rechte zum Zustellzeitpunkt, Gruppierung (`group_key`, `event_count`) |
| 11.8 | Suche: Rechtefilter vor Treffern, Snippets, Lastbegrenzung, Gast-Drosselung |
| 11.9 | JSON-API: Token, Header, Typen, Größen, Idempotenz; `ReadScope` nie veraltet in Schreibwegen |
| 11.10 | Anhänge: Zuordnung, Rechte beim Abruf, verwaiste Uploads, Typ/Größe, Sandbox-CSP |
| 11.11 | Moderation: lückenloses Protokoll, Pflichtgründe, `moderator_ban_max_days`, Massenaktionsgrenzen, Melde-Schwelle gegen Missbrauch |
| 11.12 | Frontend: XSS-Korpus, Dialoge, JSON-LD, `noindex`, Profil nur öffentliche Felder, Signaturen, `md-bridge.js` |
| 11.13 | Leistung: Abfragebudget, Indizes/EXPLAIN bei großen Foren, Keyset, `POSITION_MAX` |
| 11.14 | Modul aus: Routen 404, Admin-Seiten umgeleitet, Retention-/Privacy-/Trash-Provider laufen wie vorgesehen |
| 11.15 | Umfragen: Ende-zu-Ende-Stand verifizieren (siehe [../v4.2.X/CATALOG.md](../v4.2.X/CATALOG.md)), nicht spekulativ melden |

#### AP-12 Geschwindigkeit, Skalierung, Missbrauchslast

**Wo:** `core/CacheManager.php`, `OpcacheWarmupService.php`, `AssetOptimizerService.php`, `PerformanceSafetyNetService.php`,
`CoreWebVitalsService.php`, `CronRunnerService.php`, `MailQueueService.php`, `BatchJobService.php`, `core/Bootstrap.php`, `core/Router.php`.
**Tests:** `community-performance`, `community-lighthouse`, `community-db`.

| ID | Prüfpunkt |
|---|---|
| 12.1 | Abfragen je Seite (Start, Beitrag, Listen, Suche, 404, Admin-Listen, Mitglied): N+1, Duplikate, Schleifen, `SELECT *` auf großen Zeilen |
| 12.2 | Indizes/EXPLAIN, Volltabellenscans, Sortierung ohne Index, `COUNT(*)` auf großen Tabellen |
| 12.3 | Pro Request: Schema-Checks (nur `runSchemaOnce`), Dateisystem, Netzwerk, Session-Sperren, Autoload-Optionen |
| 12.4 | Cache: Schlüssel, Invalidierung, Stampede, Größe, nur öffentliche Daten für Gäste |
| 12.5 | Bots und Massenlast: Drosselung für Suche, Login, Registrierung, Formulare, Kommentare, Community, 404-Log, Web-Vitals, Webhooks (`Security::checkDbRateLimit()`) |
| 12.6 | Ressourcen: unbegrenzte Listen, große Uploads, Bild/PDF, ReDoS, Exporte gestreamt, `memory_limit`/Zeitlimit |
| 12.7 | Cron und Queues: Überlappung, Batchgröße, Backoff, Aufräumen, Laufzeit, Ausfallverhalten |
| 12.8 | Frontend: Assetgröße, Blocking, Bilder (`<picture>`, `loading`), lokale Schriften, Cache-Header, Lighthouse mobil ≥ 90 |
| 12.9 | Redirect-Manager: Paginierung (250 je Seite), kein Hintergrund-Reload, Treffer ändern `updated_at` nicht |

#### AP-13 Fehlende Funktionen, Verweise, Konsistenz

**Wo:** Router, `Services/AdminMenu/*`, `AdminNavigationService.php`, Member-Reiter, Themes, `lang/*.yaml`, `DOC/`, `DOC_PLAN/`.
**Tests:** `functions`, `admin-menu`, `theme-contract`, `content-types`, `reusable-blocks`, `editorjs-blocks`.

| ID | Prüfpunkt |
|---|---|
| 13.1 | Jede Route und jeder Menüeintrag hat Handler, Vorlage, Recht; kein Menüpunkt ins Leere |
| 13.2 | Jeder Hook-Name ist in `DOC/core/HOOKS-REFERENCE.md` mit Auslöser und Argumenten dokumentiert; keine verwaisten Listener |
| 13.3 | Jede Einstellung hat Leser und Oberfläche; keine toten Optionen |
| 13.4 | Sprachschlüssel beidseitig (`de.yaml`/`en.yaml`), Platzhalter stimmen, ungenutzte Schlüssel |
| 13.5 | Verweise auf nicht definierte Klassen, Funktionen, Methoden; tote `use`; tote Dateien |
| 13.6 | Formulare ohne Auswertung, Buttons ohne Handler, `data-*`-Ziele ohne Gegenstück |
| 13.7 | Doku: Links in `DOC/**/*.md` und `DOC_PLAN/**/*.md` gültig; Zahlen (Tabellen, Versionen) aus Code; Inventare (`FILELIST.md`, `FILESTRUCTUR.md`, `CMSFILESTRUCTUR.md`) vollständig; keine festen Suitenzahlen |
| 13.8 | Platzhalter, TODO, FIXME im ausgelieferten Code |

#### AP-14 Barrierefreiheit und Oberfläche

**Tests:** `community-a11y`, `community-lighthouse` (Vorlagen für weitere Seiten).

| ID | Prüfpunkt |
|---|---|
| 14.1 | axe-core auf Start, Beitrag, Login, Registrierung, Mitglied, Admin-Kernseiten: keine `serious`/`critical` |
| 14.2 | Tastatur: Reihenfolge, sichtbarer Fokus, Dialoge mit Fokusführung, Drag-and-Drop-Alternative |
| 14.3 | Formulare: Labels, Fehler (`role=alert`), Pflichtfelder, Autocomplete, Zeitlimits (Captcha) |
| 14.4 | Kontrast, hell/dunkel, Links nicht nur über Farbe, Reflow 320 px, Zoom 200 % |
| 14.5 | `lang`-Attribute, keine festen Texte, Datums- und Zahlenformate |

#### AP-15 Best Practice und Codequalität

**Tests:** `functions`, `security`, `community-mutants`; CI-Lint.

| ID | Prüfpunkt |
|---|---|
| 15.1 | PHP 8.4/8.5: `declare(strict_types=1)`, Typen, implizit nullbare Parameter, dynamische Properties, Deprecations (CI-Gate) |
| 15.2 | Fehlerbehandlung: leere `catch (\Throwable)`, ungeprüfte `false`-Rückgaben (`file_get_contents`, `json_decode`, `preg_*`) |
| 15.3 | Logging: keine Passwörter, Token, vollen IPs, Inhalte; Log-Injection; Stufen; Rotation |
| 15.4 | Struktur: Kopien von Sicherheitslogik statt zentraler Dienste, globale Zustände, Testbarkeit |
| 15.5 | Keine CDN-Einbindung, keine Laufzeit-Netzwerkzugriffe ohne Grund, Versionen fest, Lizenzen verträglich |
| 15.6 | Jede Sicherheitsregel hat Test **und** Gegenprobe; Tests ohne DB und Netz; keine flakigen Zeit-/Zufallstests |
| 15.7 | CI-Befunde (Semgrep, Trivy) bewertet statt pauschal verworfen |

#### AP-16 Drittbibliotheken und Lieferkette

**Wo:** `CMS/vendor/`, `CMS/assets/` (Bundles), `.github/deps/`, `.github/workflows/security.yml`.
**Tests:** `security` (`TESTS/security/dependencies.php`).

| ID | Prüfpunkt |
|---|---|
| 16.1 | Inventar: Name, Version, Herkunft, Lizenz, Prüfsumme, Aktualität, bekannte Schwachstellen (Trivy) |
| 16.2 | Bundles unverändert gegen Upstream oder dokumentiert lokal angepasst |
| 16.3 | Browser-Bibliotheken ohne externe Aufrufe, CSP-/Trusted-Types-kompatibel |
| 16.4 | Parser-Bibliotheken (Archiv, Bild, PDF, XML, YAML, Markdown): XXE, Entpackgrenzen, Objekt-Injection |
| 16.5 | Workflow: Secrets, Permissions, Actions per Commit-SHA gepinnt, Abgleich Manifest ↔ Bundle ↔ Doku |

#### AP-17 Datenschutz und Compliance

**Tests:** `analytics-consent`, `community-privacy`, `newsletter-member`, `content-expiry`.

| ID | Prüfpunkt |
|---|---|
| 17.1 | Jeder Bereich mit Personendaten: Export (`dsgvo_export_data`), Löschung (`dsgvo_delete_data`, `cms_user_deleted`), Löschfrist (`DataRetentionService`, `cms_mail_retention_days`, `cms_feature_usage_retention_days`, `cms_community_retention_days`) |
| 17.2 | Consent: Tracking erst nach Zustimmung, Widerruf wirkt, keine Dritt-Requests vorher |
| 17.3 | Logs und Analytics: IP-Kürzung/-Hash, Dauer, Zweck, Zugriff |
| 17.4 | Mails: Abmeldung, Double-Opt-in, keine Fremddaten |
| 17.5 | Rechtstext-Generator folgt den Einstellungen (inkl. Baustein „8a. Forum (Community)“ aus `CommunityLegal`), Prüfhinweis |
| 17.6 | Impressum, Datenschutz, Barrierefreiheitserklärung erreichbar und aktuell |

#### AP-18 Betrieb, Härtung, Wiederherstellung

**Tests:** `release-package`, `update-swap`, `s3-backup`.

| ID | Prüfpunkt |
|---|---|
| 18.1 | Webserver-Regeln (`.htaccess`, nginx-Beispiele in `DOC/INSTALLATION.md`): Sperren für `config/`, `private/`, `logs/`, `backups/`, `cache/`, `vendor/`, ausführbare Endungen in `uploads/` |
| 18.2 | Informationsabfluss: `phpinfo`, Versionen, Stacktraces, `.bak`/`~`/`.orig`, `.git`, Composer-Dateien |
| 18.3 | `cron.php` (und ab 4.3 `cli.php`): nur CLI oder mit Geheimnis |
| 18.4 | Backup/Restore praktisch getestet, Verschlüsselung, Aufbewahrung, Offsite, Update-Rückfall |
| 18.5 | Monitoring-, Status- und Fehlerberichte ohne Datenabfluss |
| 18.6 | Plattformprüfung beim Start (PHP ≥ 8.4.1, Erweiterungen) |

#### AP-19 KI-Funktionen

**Wo:** `Services/AI/*`, Admin-Seiten `ai-*`.
**Tests:** `ai-admin-contract`, `ai-content-creator`, `ai-editorjs-translation`, `ai-policy`, `ai-seo-metadata`.

| ID | Prüfpunkt |
|---|---|
| 19.1 | Schlüssel verschlüsselt, Ziele nur konfiguriert (SSRF), Zeit- und Größenlimit |
| 19.2 | Prompt-Injection: Fremdinhalte als Daten markiert; Ausgaben bereinigt; keine automatischen Schreibaktionen |
| 19.3 | Quoten (`ai_quota_usage`), Missbrauch durch viele Anfragen |
| 19.4 | Richtlinie: Datenabfluss an Dritte benannt und abschaltbar; Logs ohne Secrets |

#### AP-20 Integrationen und Austausch

**Wo:** `GraphApiService.php`, `AzureMailTokenProvider.php`, `MailService.php`, `MailQueueService.php`, `Services/Newsletter/*`,
`Services/Feed/*`, `Services/Exchange/*`, `CsvExchange.php`, `Services/Downloads/*`, `Services/Knowledgebase/*`, `plugins/cms-importer/`.
**Tests:** `newsletter-member`, `feeds-design`, `webhooks`, `s3-backup`, `sso-entra`.

| ID | Prüfpunkt |
|---|---|
| 20.1 | Mail: TLS, Zertifikatsprüfung, Header-Injection, Queue (Retry, Giftnachrichten), Absender-Spoofing |
| 20.2 | Importe (CSV, Feeds, WXR): Größe, Formel-Injection, Zeichensätze, Dubletten, Rechte, Wiederholbarkeit; PERF-410-49 |
| 20.3 | Feeds, Sitemap, robots: nur öffentliche Inhalte, Cache, Bereinigung |
| 20.4 | Downloads und Knowledgebase: Rechte je Eintrag, Zähler, Auslieferung (siehe AP-06) |
| 20.5 | Externe Dienste (Entra, LDAP, S3, Zahlungen, Graph): Timeout, Fehlerfall, Ausfall isoliert |

### 5. Umsetzungsregeln

1. Ein Pull Request je AP oder Befundgruppe, Titel `Audit <Version> AP-xx: …`; CRITICAL/HIGH einzeln und zuerst.
2. Jede Korrektur bekommt einen Regressionstest, der vor dem Fix fehlschlägt; einen Mutanten in `TESTS/community-mutants/`, wo das Mutantensystem greift.
3. Vor jedem Push: `php -l` auf geänderten PHP-Dateien, `node --check` auf geänderten JS-Dateien, `php TESTS/run-all.php` grün.
4. Inventare pflegen: neue Suite → `DOC/FILELIST.md`, `DOC/FILESTRUCTUR.md` und `.gitignore`-Whitelist (`!/TESTS/<suite>/`, `!/TESTS/<suite>/run.php`); neue Dienste → `DOC/CMSFILESTRUCTUR.md`, `DOC/core/SERVICES.md`; neue Hooks → `DOC/core/HOOKS-REFERENCE.md`; Sprachschlüssel in beiden Katalogen; neue Tabellen → `DOC/core/DATABASE-SCHEMA.md`.
5. Schema: `SchemaManager::getSchemaQueries()` als einzige Definition, Migration, `REQUIRED_*`, `TESTS/schema-v27` bzw. Folgeversion, `schema-upgrade`.
6. Release-Pakete nur am Release-Schnitt (Version, `update.json`, `RELEASE/…`, SHA-256, Changelog, README).
7. Sicherheitsbefunde: PR-Text ohne Exploit-Details; keine Zugangsdaten oder echten Personendaten; CRITICAL vor Veröffentlichung melden.
8. Bewusste Entscheidungen (2.4) nicht umkehren; im Zweifel als Vorschlag (`INFO`) melden.
9. Refactoring ohne Sicherheits-, Fehler- oder Leistungsgrund nicht umsetzen.
10. Nicht Ausführbares (echte MariaDB, Produktivdaten, externe Dienste, fremde Themes) als `offen` mit konkretem Prüfvorgehen führen.

### 6. Abnahme

- Alle Prüfpunkte AP-01 … AP-20 (bzw. im Delta-Audit die betroffenen) beantwortet (`ja`/`nein`/`n. a.`/`offen` mit Fundstelle).
- CRITICAL/HIGH umgesetzt und per Test belegt; MEDIUM umgesetzt oder begründet terminiert; LOW/INFO gesammelt.
- `php TESTS/run-all.php` grün, CI grün, keine neuen Semgrep-/Trivy-Funde.
- Inventare, Hooks, Sprachschlüssel, Schema-Doku und Changelog konsistent.
- Bericht unter `DOC_PLAN/audit/audits/` und Eintrag im [Index](audits/README.md).

### Anhang A: Bewertungsfragen je Fund

Wer kann es auslösen (anonym, Mitglied, Moderator, Admin, Plugin)? Welche Voraussetzung? Welche Wirkung
(Vertraulichkeit, Integrität, Verfügbarkeit)? Reproduzierbar (Test, Skript)? Gibt es eine zweite Schutzschicht? Wie
klein ist die Korrektur, welche Seiteneffekte? Ist der Fund durch eine bewusste Entscheidung (2.4) abgedeckt?

### Anhang B: Such-Rezepte (Hinweise, keine Befunde)

Aus dem Repository-Wurzelverzeichnis; `rg` (ripgrep) verhält sich unter Linux, macOS und Windows gleich. Treffer sind
**Kandidaten** und müssen am Code bestätigt werden.

```bash
# Gefährliche Funktionen
rg -n '\b(eval|assert|create_function|unserialize|extract|shell_exec|exec|system|passthru|proc_open|popen)\s*\(' CMS -g '!CMS/vendor/**' -g '!CMS/assets/**'
rg -n '\b(include|require)(_once)?\s*\(?\s*\$' CMS -g '!CMS/vendor/**' -g '!CMS/assets/**'

# SQL mit Interpolation ({$this->prefix} o. ä. ist meist nur der Tabellenname)
rg -n '(SELECT|UPDATE|DELETE|INSERT)[^;]*\{\$[a-zA-Z_]+' CMS -g '!CMS/vendor/**' -g '!CMS/assets/**'
rg -n '->(query|exec|get_results|get_row|get_var|get_col)\([^)]*\.\s*\$' CMS -g '!CMS/vendor/**'

# Fremdwerte in Ausgabe, Weiterleitung, Browser-Code
rg -n 'echo\s+\$_(GET|POST|REQUEST|COOKIE|SERVER)' CMS -g '!CMS/vendor/**'
rg -n "header\(\s*['\"]Location:[^)]*\.\s*\\$" CMS -g '!CMS/vendor/**'
rg -n '(innerHTML|outerHTML|insertAdjacentHTML|document\.write)\s*(=|\()' CMS/assets/js CMS/themes CMS/views

# Zufall, Vergleiche
rg -n '\b(rand|mt_rand|uniqid|lcg_value)\s*\(' CMS -g '!CMS/vendor/**' -g '!CMS/assets/**'
rg -n '(==|!=)\s*\$\w*(token|hash|signature|secret)' CMS -g '!CMS/vendor/**' -i
rg -n 'in_array\(' CMS -g '!CMS/vendor/**' -g '!CMS/assets/**' | rg -v 'true\)'

# Ausgehende Abrufe, Dateien
rg -n '(curl_init|file_get_contents\(\s*\$|fopen\(\s*\$|simplexml_load|new DOMDocument)' CMS -g '!CMS/vendor/**' -g '!CMS/assets/**'
rg -n '(move_uploaded_file|\$_FILES)' CMS -g '!CMS/vendor/**'

# Verschlucken und Altlasten
rg -nU 'catch\s*\(\\?Throwable[^)]*\)\s*\{\s*\}' CMS -g '!CMS/vendor/**'
rg -n '\b(TODO|FIXME|XXX|HACK)\b' CMS -g '!CMS/vendor/**' -g '!CMS/assets/**'

# Inventar: Hooks
rg -on "(addAction|addFilter|doAction|applyFilters)\(\s*'[a-z0-9_]+'" CMS -g '!CMS/vendor/**' -r '$0' | sort -u
```

### Anhang C: Messungen

- Abfragen je Seite: `TESTS/lib/CommunityPages.php` als Vorlage; gegen MariaDB `EXPLAIN` und `Handler_read_rnd_next`.
- Browser: `TESTS/community-lighthouse`, `TESTS/community-a11y` als Vorlage.
- Last: `ab`, `wrk` oder `k6` nur gegen eine Testinstallation, nie gegen Produktion.
