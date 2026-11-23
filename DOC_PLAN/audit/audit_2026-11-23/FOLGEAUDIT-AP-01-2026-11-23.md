# AP-01 – Anmeldung und Sitzung

**Zwischenstand vom 23.11.2026.** Geprüft wurde der Quellstand `dcd3187a` auf `main`
(`V4.2.0-33-gdcd3187a`, Version 4.2.0, Schema v27), nicht allein das Release-Paket vom Tag `V4.2.0`.
Maßstab: [Audit-Katalog, AP-01](../AUDIT-KATALOG-4.2.0.md#ap-01-anmeldung-und-sitzung).
Der Bericht betrifft nur AP-01; er ist keine Freigabe des Anmeldesystems.

**Ergebnis:** Die zwei belegten Abweichungen (01.3 und 01.4) wurden nach dem Audit behoben und ihre
Regressionstests bestehen. Ein Prüfpunkt ist auf dem API-Pfad statisch erfüllt (01.8); neun bleiben
für die umfassendere Laufzeitprüfung offen. Es wurden keine Benutzerkonten, externen Identitätsanbieter
oder Produktivsysteme verwendet. Alle Fundstellen sind relativ zur Repository-Wurzel.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 01.1 Sitzung | offen | `CMS/index.php:245–249`: HttpOnly, Secure bei erkanntem HTTPS, SameSite=Strict und Strict Mode. `CMS/core/Auth.php:1559–1574`: neue Sitzungs-ID beim Login. `CMS/core/Auth.php:107–137`: absolute Laufzeit und Passwortbindung. | Zwei parallele Testsitzungen über einen Rollenwechsel hinweg prüfen: Wann wechseln ID und Rollen-Timeout, und welche Sitzung bleibt gültig? Der Rollen-Hook allein (`CMS/core/Services/UserService.php:335–352`) ist kein Nachweis einer Rotation. |
| 01.2 Anmeldelimits | offen (Antworten und Hash-Kosten bestätigt) | `CMS/core/Auth.php:383–419,424–461`: IP-Limit, kontobezogene Verzögerung, Dummy-Passwortprüfung und gleiche Fehlermeldung bei gewöhnlichen Fehlversuchen. Der Kontozähler wird nach korrektem Passwort, **vor** Status- und MFA-Prüfung geleert; für reservierte Registrierungen existiert ein eigener Zweig. | Antworten, Zeitverteilungen und Zählerstände für unbekanntes, aktives und unbestätigtes Testkonto vergleichen; Passwort- und MFA-Zähler getrennt bewerten. |
| 01.3 Passwort und Reset | offen (Rehash umgesetzt) | Beim erfolgreichen Passwortnachweis aktualisiert `CMS/core/Auth.php:453–474` alte Hashes per Compare-and-Swap; `CMS/core/Security.php:607–610` prüft dieselbe bcrypt-Kostenrichtlinie wie beim Hashen. Regression: `TESTS/password-policy/run.php` (58 bestanden). Richtlinie und Unicode-Zählung: `CMS/core/Services/Security/PasswordPolicyService.php:243–264,383–386`; Reset: `CMS/core/Services/CmsAuthPageService.php:675–684,787–827`. | DB-gestützten parallelen Login/Passwortwechsel und Sitzungswiderruf nach Reset mit Testkonten prüfen; Sitzung bindet an den Passwort-Fingerprint (`CMS/core/Auth.php:129–136`). |
| 01.4 MFA | offen (Fehlerpfad umgesetzt) | `CMS/core/Auth/AuthManager.php:499–553` lehnt nicht bestätigten TOTP-Verbrauch ab und protokolliert nur Konto-ID und Ausnahmeklasse. Regression mit fehlschlagendem Test-Datenspeicher: `TESTS/mfa-enforcement/run.php` (184 bestanden). Versuchslimit `:318–344`; Backup-Codes `CMS/core/Auth/MFA/BackupCodesManager.php:52–69,81–108`; Passwort vor Deaktivierung `CMS/member/includes/class-member-controller.php:1666–1675`. | MFA-Entscheidung für Passwort, SSO, Passkey und API vergleichend prüfen. |
| 01.5 Passkey | offen | Login-Challenge liegt mit Zeitstempel in der Sitzung und wird vor der Prüfung entfernt (`CMS/core/Routing/PublicRouter.php:151–152,190–199`). RP-ID aus `SITE_URL`: `CMS/core/Auth/Passkey/WebAuthnAdapter.php:44–49`; gespeicherter und neuer Signaturzähler: `CMS/core/Auth/AuthManager.php:162–188`. Löschen verlangt Passwort und ist an das Konto gebunden (`CMS/member/includes/class-member-controller.php:1666–1675,1764–1767`). | Registrierung und Anmeldung mit virtuellem Authenticator prüfen: erwartete Origin, Challenge-Verbrauch und gespeicherter Zähler. Die bestehende Quelltextprüfung in `TESTS/security/audit-410.php:54–64` ersetzt diesen Durchlauf nicht. |
| 01.6 Entra-SSO | offen (Testrobustheit behoben) | Einmaliger Sitzungszustand und PKCE S256: `CMS/core/Auth/SSO/OidcFlowState.php:52–75,84–101,128–130`; Callback: `CMS/core/Auth/SSO/EntraSsoService.php:132–163`. Tenant-Liste, `tid`, `oid`, `iss`, `aud`, `nonce` und `xms_edov`: `CMS/core/Auth/SSO/EntraIdProvider.php:242–268,295–308`. Rollen-Mapping und Auto-Link-Sperre einschließlich `moderator`: `CMS/core/Auth/SSO/IdentityLinker.php:34,173–202,255–281`. SSO-Herkunft nach lokaler MFA: `CMS/core/Auth/AuthManager.php:481–492`. | Der Test zur Unterscheidung von lokalen und SSO-Sitzungen ist rot; er vergleicht Quelltext mit festen Zeilenumbrüchen (`TESTS/sso-entra/run.php:785`). Das tatsächliche Sitzungsverhalten getrennt vom Textvergleich mit zwei synthetischen Sitzungen prüfen. |
| 01.7 LDAP | offen | Benutzername wird für Standard- und konfigurierten Filter maskiert (`CMS/core/Auth/LDAP/LdapAuthProvider.php:324–337,451–460`). Zum Bind dient der DN aus dem Suchergebnis, kein aus der Eingabe gebauter DN. Fehlender DN, abgelehnter Bind und Ausnahmen liefern keinen Benutzer (`:60–89`). TLS ist standardmäßig aktiviert, aber konfigurierbar (`:367–382`). | Zertifikatskette und Hostnamenprüfung in einer isolierten LDAP-Testkonfiguration bestätigen; ein nicht vertrauenswürdiger Server darf keine erfolgreiche Anmeldung liefern. |
| 01.8 JWT | ja | Der API-Pfad stellt Tokens mit `exp` aus und prüft sie mit festem HS256-Schlüsseltyp (`CMS/core/Services/JwtService.php:33,118–129,149–167`). Bearer und Refresh prüfen das aktive Konto und die Bindung (`CMS/core/Auth.php:892–910,952–959`; `CMS/core/Routing/ApiRouter.php:133–148`). Passwortstand und Widerrufsversion fließen in den mit `hash_equals` verglichenen Fingerprint ein (`CMS/core/Auth.php:255–284,309–317,1042–1067`). | Aussage gilt für die CMS-API, nicht für eigene Token-Verbraucher in Plugins. |
| 01.9 E-Mail | offen | Änderung: Anfragelimit, Token-Hash und Hinweis an die bisherige Adresse (`CMS/core/Services/EmailChangeService.php:121–139`); bei Bestätigung Konto, Hash, Ablauf und Adressbelegung erneut geprüft (`:226–254`). Verifizierung: erneuter Versand begrenzt, befristete Hashes (`CMS/core/Services/EmailVerificationService.php:181–192,423–426`). | Gleichzeitige Registrierung und Bestätigung derselben Adresse auf MariaDB mit Testkonten prüfen. Entscheidend sind eindeutiger Besitzer und konsistenter Bestätigungsstatus nach Abschluss beider Transaktionen. |
| 01.10 Öffentliche Formulare | offen (Rücksprungziele bestätigt) | Registrierungsversuche werden pro IP gezählt. Bei aktiviertem `register_captcha` verifiziert der Server die Aufgabe im Kontext `register` (`CMS/core/Routing/PublicRouter.php:628–649`); `redirect` wird vor der Login-Weiterleitung normalisiert (`:176–179,222–247`). | Für Standard- und konfigurierbare Anmeldeseiten denselben Test durchführen: gleichartige Antworten bei belegten Adressen, Captcha an/aus, ausschließlich zulässige lokale Rücksprungziele. Auch `return_to` und `_return` je tatsächlichem Verbraucher zuordnen. |

## Abweichungen

### TMP-K02a-1 · Bestehende Passwort-Hashes beim Login aktualisieren (LOW, umgesetzt)

- **Korrektur:** Nach erfolgreicher Passwortprüfung erkennt `Security::passwordNeedsRehash()` Hashes mit veralteten Parametern. `Auth::login()` speichert den neuen Hash nur, wenn Benutzer-ID **und** alter Hash noch stimmen. Bei einem konkurrierenden Update wird der aktuelle Hash erneut geprüft, statt ein zwischenzeitlich geändertes Passwort zu überschreiben. Der aktualisierte Hash wird für die anschließende Sitzungsbindung übernommen.
- **Regression:** `TESTS/password-policy/run.php` deckt einen alten bcrypt-Hash, einen Hash nach aktueller Richtlinie sowie den atomaren Login-Updatepfad ab. **Status:** umgesetzt; Passwort-/MFA-Regressionstests grün. **Konfidenz:** hoch für die Hash-Richtlinie und den geprüften Anwendungspfad; kein Datenbank-Konkurrenztest.

### TMP-K02a-2 · TOTP-Verbrauch bei Speicherfehler ablehnen (LOW, umgesetzt)

- **Korrektur:** `AuthManager::claimTotpCode()` protokolliert bei einer Speicher-Ausnahme nur Konto-ID und Ausnahmeklasse (keinen Code oder Hash) und liefert `false`. Damit wird die MFA-Prüfung nicht abgeschlossen, wenn der atomare Verbrauch nicht bestätigt werden kann.
- **Wirkung und Grenze:** Der Fehlerpfad verweigert jetzt den Login über diesen TOTP-Code; Passwort, TOTP-Validierung und Versuchslimits bleiben erforderlich. Kein Nachweis einer Umgehung der Anmeldung.
- **Abgrenzung:** [SEC-410-15/22](../audits/RELEASEAUDIT-2026-11-15_4.1.0.md#sec-410-1522-low-totp--und-backup-codes) betrifft den atomaren Verbrauch bei funktionierendem Speicher. Der vorhandene Test `TESTS/security/audit-410.php:46–52` prüft die SQL-Struktur, nicht den Rückgabewert im Ausnahmefall.
- **Regression:** `TESTS/mfa-enforcement/run.php` ruft die Methode mit einem Test-Datenspeicher auf, der beim Lesen gezielt eine Ausnahme wirft, und prüft Ablehnung sowie redigierte Protokollierung. **Status:** umgesetzt; MFA-Regressionstest grün. **Konfidenz:** hoch für den Fehlerpfad.

## Testlauf und Übergabe

Lokaler Windows-Checkout, PHP **8.4.28 CLI (NTS, x64)**, Einzelaufruf je Suite aus der
Repository-Wurzel: `php TESTS/<suite>/run.php`. Ergebnisse mit geladenem `mbstring`, OpenSSL
und `pdo_sqlite`. Geänderte PHP-Dateien zusätzlich mit `php -l` geprüft. Kein Gesamtlauf und keine CI-Abnahme.

| Suite | Exit | Beobachtet |
|---|---:|---|
| `password-policy` | 0 | **58 bestanden** (inkl. Rehash-Regression) |
| `mfa-enforcement` | 0 | **184 bestanden** (inkl. TOTP-Fehlerpfad-Regression mit fehlschlagendem Test-Datenspeicher) |
| `login-showcase` | 0 | 153 bestanden (vor den Änderungen) |
| `sso-entra` | 1 | 230 bestanden, 1 fehlgeschlagen: statischer Integrationscheck der Sitzungssperre (01.6). |
| `security` | 255 | Kein vollständiges Ergebnis. Nach mehreren fehlgeschlagenen Prüfungen Abbruch wegen erneuter Deklaration von `emailLinkMethod()` (`TESTS/security/email-links.php:12`). |
| `user-role-hook` | 1 | Unter anderem fehlgeschlagene Quelltextvergleiche; die Mutanten-Gegenprobe scheitert unter Windows beim Öffnen von `/dev/null` (`TESTS/user-role-hook/run.php:1097`). Kein belastbarer Nachweis der Sitzungsrotation aus dieser Suite. |

**Nächster Schritt:** Die drei nicht grünen Suiten auf demselben Commit in einer Linux-Umgebung mit
LF-Checkout wiederholen. Zusätzlich die Rehash-Konkurrenz mit MariaDB/Testkonten prüfen. Danach zuerst 01.1 und 01.6 gemeinsam prüfen: parallele Sitzungen über Rollenwechsel und
SSO-Richtlinienwechsel hinweg beobachten. Testfehler ohne bestätigten Produktpfad sind nicht als neue
Sicherheitsbefunde gezählt.

## Nachprüfung unter Linux (23.11.2026)

Quellstand `main` nach `8679a05` (PHP 8.3.6 CLI, LF-Checkout, ohne Datenbank), `php TESTS/run-all.php`.

| Suite | Ergebnis auf Linux | Einordnung |
|---|---|---|
| `password-policy`, `mfa-enforcement`, `login-showcase` | grün | wie im Bericht |
| `sso-entra` | **grün** | der rote Textvergleich in 01.6 war ein Zeilenumbruch-Artefakt des Windows-Checkouts, kein Produktfehler |
| `user-role-hook` | **grün** (inklusive Mutanten-Gegenprobe), mit den Korrekturen aus PR #194; auf `main` allein 2 rote Doku-Prüfungen (Signaturzeile von `user_role_changed` in der Hook-Referenz, Pfade nach dem Umbau nach `DOC_PLAN`) | die Windows-Fehler in AP-02 sind Zeilenumbruch- und `/dev/null`-Artefakte, die Verhaltensprüfungen bestanden schon dort |
| `security` | rot, 1 Prüfung: `CMS_ASSETS/suneditor-3.4.3.zip` fehlt | Folge des Umzugs von `CMS_ASSETS/` in ein privates Repository, kein Anmeldeproblem; kein Abbruch durch `emailLinkMethod()` unter Linux |

Gemessen auf `main` plus PR #194: alle übrigen Suiten sind grün (66 Suiten, nur `security` rot). Auf `main` allein sind zusätzlich `functions`, `schema-v27`, `release-4.1.0`, `user-role-hook` und `community-mutants` rot; die Ursachen liegen in der Doku (Umbau nach `DOC_PLAN`), nicht im Anmeldecode.

### TMP-K02a-3 · Rehash beendet fremde Sitzungen schon vor der MFA (LOW, umgesetzt)

- **Befund:** Der Sitzungs- und Token-Fingerprint ist `HMAC(Passwort-Hash [+ Token-Version])` (`CMS/core/Auth.php:309–317`, geprüft in `:129–136` und `:966`). Der Rehash aus TMP-K02a-1 stand in `Auth::login()` **vor** Status-, Sperr- und MFA-Prüfung. Ein Konto mit altem Hash (Kosten unter 12) verlor damit beim ersten Passwortnachweis alle anderen Sitzungen und alle API-Tokens, auch wenn die MFA danach scheiterte.
- **Wer kann auslösen:** Wer das Passwort kennt, aber keinen zweiten Faktor hat, kann den Rehash einmalig auslösen und so die Sitzungen des Inhabers beenden. Es entsteht kein Zugriff auf das Konto; Wirkung: einmaliger Abmeldeeffekt je Konto.
- **Korrektur:** Der Rehash steht jetzt unmittelbar vor `completeAuthenticatedSession()`, also nach Status- und Sperrprüfung und nach der MFA-Weiche. Konten mit aktiver MFA behalten ihren alten Hash bis zur nächsten Passwortänderung (dort wird ohnehin mit Kosten 12 gehasht). Die Compare-and-Swap-Logik bleibt unverändert.
- **Regression:** `TESTS/password-policy/run.php` prüft die Reihenfolge (Rehash nach `MFA_REQUIRED` und Statusprüfung, vor dem Sitzungsaufbau); ohne die Korrektur schlägt der Test fehl. **Konfidenz:** hoch (Ablauf aus dem Code, kein Datenbanklauf).
- **Offen (mit MariaDB):** Rehash-Wettlauf zweier gleichzeitiger Logins mit Testkonto.

## Weitere Nachprüfung der offenen Punkte (23.11.2026)

Drei der neun offenen Punkte wurden ohne Datenbank und Browser so weit bestätigt, wie es statisch und per Funktionsaufruf geht. Kein Punkt wechselt
auf „ja“, weil jeweils ein Laufzeitnachweis fehlt; die Zeilen der Matrix nennen den bestätigten Teil.

| ID | Bestätigt | Nachweis | Weiterhin offen |
|---|---|---|---|
| 01.2 Anmeldelimits | Unbekannter Benutzername und falsches Passwort liefern dieselbe Antwort („Ungültige Anmeldedaten.“), laufen beide durch eine Hash-Prüfung und zählen beide in die Konto-Verzögerung (`CMS/core/Auth.php:408–451`). Der Vergleichs-Hash für unbekannte Konten hat dieselben Kosten (bcrypt, Kosten 12) wie neu erzeugte Hashes (`Auth.php:27`, `Security.php:599–609`). | Neue Prüfung `TESTS/security/login-flow.php` (Verfahren, Kosten, Soll der Hash-Parameter). Mutante „Dummy-Hash mit Kosten 10“ lässt zwei Prüfungen fehlschlagen. | Zeitverteilung und Zählerstände für unbekanntes, aktives und unbestätigtes Testkonto auf MariaDB; Trennung der Passwort- und MFA-Zähler. |
| 01.6 Entra-SSO | Der rote Test lag nicht am Sitzungscode: `TESTS/sso-entra/run.php` verglich Quelltext mit `\n` und scheiterte, wenn ein Checkout CRLF-Zeilenenden hat (Windows). Mit `core.autocrlf=true` ausgecheckt war genau die Prüfung „Sitzungsprüfung beendet lokale Sitzungen bei eingeschalteter Sperre“ rot (230 von 231), nach Normalisierung der Zeilenenden beim Lesen grün (231 von 231), ebenso unter Linux. | Änderung nur an den beiden Lesehilfen der Suite; keine Prüfung entfernt oder abgeschwächt. | Sitzungsverhalten mit zwei synthetischen Sitzungen (lokal und SSO) statt Textvergleich. |
| 01.10 Öffentliche Formulare | Das Ziel nach der Anmeldung (`redirect`) wird auf einen lokalen Pfad reduziert: fremde Hosts, `//`, `///`, `\`, Rückwärtspfade, Steuerzeichen, Zeilenumbrüche, `javascript:` und `data:` enden bei `/member`, `/admin` oder einem Pfad ohne Host (`CMS/core/Routing/PublicRouter.php`, `resolveAllowedRedirectParts()`). `return_to` (Newsletter) und `_return` (Formulare) sind bereits durch `TESTS/security/redirects.php` abgedeckt. | Zweite Prüfung in `TESTS/security/login-flow.php` (13 feindliche Eingaben, je drei Eigenschaften). Mutante „Ziel ungeprüft zurückgeben“ lässt sie fehlschlagen. | Gleichartige Antworten bei belegten Adressen in der Registrierung und Captcha an/aus; Prüfung der konfigurierbaren Anmeldeseiten. |

Die übrigen sechs offenen Punkte (01.1, 01.3, 01.4, 01.5, 01.7, 01.9) brauchen Datenbank, Browser mit virtuellem Authenticator oder einen LDAP-Testserver und bleiben unverändert.
