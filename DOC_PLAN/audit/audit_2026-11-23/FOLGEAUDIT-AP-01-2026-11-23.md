# AP-01 – Anmeldung und Sitzung

**Zwischenstand vom 23.11.2026.** Geprüft wurde der Quellstand `dcd3187a` auf `main`
(`V4.2.0-33-gdcd3187a`, Version 4.2.0, Schema v27), nicht allein das Release-Paket vom Tag `V4.2.0`.
Maßstab: [Audit-Katalog, AP-01](../AUDIT-KATALOG-4.2.0.md#ap-01-anmeldung-und-sitzung).
Der Bericht betrifft nur AP-01; er ist keine Freigabe des Anmeldesystems.

**Ergebnis:** Zwei der zehn Prüfpunkte enthalten eine im Code belegte Abweichung (01.3 und 01.4).
Einer ist auf dem geprüften API-Pfad statisch erfüllt (01.8). Bei den übrigen sieben
ist jeweils benannt, welcher Nachweis fehlt. Es wurden keine Benutzerkonten, externen Identitätsanbieter
oder Produktivsysteme verwendet. Alle Fundstellen sind relativ zur Repository-Wurzel.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 01.1 Sitzung | offen | `CMS/index.php:245–249`: HttpOnly, Secure bei erkanntem HTTPS, SameSite=Strict und Strict Mode. `CMS/core/Auth.php:1559–1574`: neue Sitzungs-ID beim Login. `CMS/core/Auth.php:107–137`: absolute Laufzeit und Passwortbindung. | Zwei parallele Testsitzungen über einen Rollenwechsel hinweg prüfen: Wann wechseln ID und Rollen-Timeout, und welche Sitzung bleibt gültig? Der Rollen-Hook allein (`CMS/core/Services/UserService.php:335–352`) ist kein Nachweis einer Rotation. |
| 01.2 Anmeldelimits | offen | `CMS/core/Auth.php:383–419,424–461`: IP-Limit, kontobezogene Verzögerung, Dummy-Passwortprüfung und gleiche Fehlermeldung bei gewöhnlichen Fehlversuchen. Der Kontozähler wird nach korrektem Passwort, **vor** Status- und MFA-Prüfung geleert; für reservierte Registrierungen existiert ein eigener Zweig. | Antworten, Zeitverteilungen und Zählerstände für unbekanntes, aktives und unbestätigtes Testkonto vergleichen; Passwort- und MFA-Zähler getrennt bewerten. |
| 01.3 Passwort und Reset | nein | **TMP-K02a-1** (unten). Richtlinie und Unicode-Zählung: `CMS/core/Services/Security/PasswordPolicyService.php:243–264,383–386`; Registrierung liest Passwörter ohne Trimmen (`CMS/core/Routing/PublicRouter.php:652–655`). Reset: Zufall und Speicherung `CMS/core/Services/CmsAuthPageService.php:675–684`; Hashvergleich über die DB-Suche, Ablauf, einmaliger Verbrauch und Sitzungsbereinigung `:787–827`. | Nach einem Reset auch die nächste Anfrage zweier bereits angemeldeter Testclients prüfen; die Sitzungsprüfung verwendet den Passwort-Fingerprint (`CMS/core/Auth.php:129–136`). |
| 01.4 MFA | nein | **TMP-K02a-2** (unten). Versuchslimit `CMS/core/Auth/AuthManager.php:318–344`; Backup-Codes `CMS/core/Auth/MFA/BackupCodesManager.php:52–69,81–108`; Passwort vor Deaktivierung `CMS/member/includes/class-member-controller.php:1666–1675`. | Nach Korrektur den Fehlerpfad mit einem Test-Datenspeicher abdecken; MFA-Entscheidung für Passwort, SSO, Passkey und API vergleichend prüfen. |
| 01.5 Passkey | offen | Login-Challenge liegt mit Zeitstempel in der Sitzung und wird vor der Prüfung entfernt (`CMS/core/Routing/PublicRouter.php:151–152,190–199`). RP-ID aus `SITE_URL`: `CMS/core/Auth/Passkey/WebAuthnAdapter.php:44–49`; gespeicherter und neuer Signaturzähler: `CMS/core/Auth/AuthManager.php:162–188`. Löschen verlangt Passwort und ist an das Konto gebunden (`CMS/member/includes/class-member-controller.php:1666–1675,1764–1767`). | Registrierung und Anmeldung mit virtuellem Authenticator prüfen: erwartete Origin, Challenge-Verbrauch und gespeicherter Zähler. Die bestehende Quelltextprüfung in `TESTS/security/audit-410.php:54–64` ersetzt diesen Durchlauf nicht. |
| 01.6 Entra-SSO | offen | Einmaliger Sitzungszustand und PKCE S256: `CMS/core/Auth/SSO/OidcFlowState.php:52–75,84–101,128–130`; Callback: `CMS/core/Auth/SSO/EntraSsoService.php:132–163`. Tenant-Liste, `tid`, `oid`, `iss`, `aud`, `nonce` und `xms_edov`: `CMS/core/Auth/SSO/EntraIdProvider.php:242–268,295–308`. Rollen-Mapping und Auto-Link-Sperre einschließlich `moderator`: `CMS/core/Auth/SSO/IdentityLinker.php:34,173–202,255–281`. SSO-Herkunft nach lokaler MFA: `CMS/core/Auth/AuthManager.php:481–492`. | Der Test zur Unterscheidung von lokalen und SSO-Sitzungen ist rot; er vergleicht Quelltext mit festen Zeilenumbrüchen (`TESTS/sso-entra/run.php:785`). Das tatsächliche Sitzungsverhalten getrennt vom Textvergleich mit zwei synthetischen Sitzungen prüfen. |
| 01.7 LDAP | offen | Benutzername wird für Standard- und konfigurierten Filter maskiert (`CMS/core/Auth/LDAP/LdapAuthProvider.php:324–337,451–460`). Zum Bind dient der DN aus dem Suchergebnis, kein aus der Eingabe gebauter DN. Fehlender DN, abgelehnter Bind und Ausnahmen liefern keinen Benutzer (`:60–89`). TLS ist standardmäßig aktiviert, aber konfigurierbar (`:367–382`). | Zertifikatskette und Hostnamenprüfung in einer isolierten LDAP-Testkonfiguration bestätigen; ein nicht vertrauenswürdiger Server darf keine erfolgreiche Anmeldung liefern. |
| 01.8 JWT | ja | Der API-Pfad stellt Tokens mit `exp` aus und prüft sie mit festem HS256-Schlüsseltyp (`CMS/core/Services/JwtService.php:33,118–129,149–167`). Bearer und Refresh prüfen das aktive Konto und die Bindung (`CMS/core/Auth.php:892–910,952–959`; `CMS/core/Routing/ApiRouter.php:133–148`). Passwortstand und Widerrufsversion fließen in den mit `hash_equals` verglichenen Fingerprint ein (`CMS/core/Auth.php:255–284,309–317,1042–1067`). | Aussage gilt für die CMS-API, nicht für eigene Token-Verbraucher in Plugins. |
| 01.9 E-Mail | offen | Änderung: Anfragelimit, Token-Hash und Hinweis an die bisherige Adresse (`CMS/core/Services/EmailChangeService.php:121–139`); bei Bestätigung Konto, Hash, Ablauf und Adressbelegung erneut geprüft (`:226–254`). Verifizierung: erneuter Versand begrenzt, befristete Hashes (`CMS/core/Services/EmailVerificationService.php:181–192,423–426`). | Gleichzeitige Registrierung und Bestätigung derselben Adresse auf MariaDB mit Testkonten prüfen. Entscheidend sind eindeutiger Besitzer und konsistenter Bestätigungsstatus nach Abschluss beider Transaktionen. |
| 01.10 Öffentliche Formulare | offen | Registrierungsversuche werden pro IP gezählt. Bei aktiviertem `register_captcha` verifiziert der Server die Aufgabe im Kontext `register` (`CMS/core/Routing/PublicRouter.php:628–649`); `redirect` wird vor der Login-Weiterleitung normalisiert (`:176–179,222–247`). | Für Standard- und konfigurierbare Anmeldeseiten denselben Test durchführen: gleichartige Antworten bei belegten Adressen, Captcha an/aus, ausschließlich zulässige lokale Rücksprungziele. Auch `return_to` und `_return` je tatsächlichem Verbraucher zuordnen. |

## Abweichungen

### TMP-K02a-1 · Bestehende Passwort-Hashes werden beim Login nicht aktualisiert (LOW)

- **Nachweis:** `CMS/core/Security.php:599–610` erzeugt bcrypt-Hashes mit Kostenfaktor 12. Die Verifikation prüft das Passwort, aber nicht, ob der gespeicherte Hash der aktuellen Vorgabe entspricht. Der erfolgreiche Passwortpfad in `CMS/core/Auth.php:437–500` geht ohne Aktualisierung in MFA oder Sitzung über.
- **Wirkung und Grenze:** Ein Konto mit gültigem älteren Hash behält dessen Parameter auch bei regelmäßiger Nutzung. Neue Passwörter werden bereits nach aktueller Vorgabe gespeichert. Kein Nachweis, dass im Betrieb solche Alt-Hashes existieren.
- **Empfehlung:** Nur nach korrektem Passwort veraltete Parameter aktualisieren, ohne einen parallelen Passwortwechsel zu überschreiben. Dabei die Bindung laufender Sitzungen und API-Tokens an den Hash berücksichtigen.
- **Regression:** In `password-policy` den Login mit einem älteren und einem aktuellen Test-Hash abdecken; ein Fehlversuch darf nichts umschreiben. **Status:** statisch belegt; nicht umgesetzt. **Konfidenz:** hoch für den lokalen Passwortpfad.

### TMP-K02a-2 · TOTP-Verbrauch wird bei Speicherfehler als erfolgreich behandelt (LOW)

- **Nachweis:** `CMS/core/Auth/AuthManager.php:501–547` gibt bei erfolgreichem DB-Zugriff das Ergebnis des atomaren Verbrauchs zurück. Nach einer Ausnahme liefert dieselbe Methode `true`, ohne den Verbrauch bestätigt zu haben. Der Aufrufer wertet dies als erfüllte Teilprüfung (`:340–350`).
- **Wirkung und Grenze:** Der Einmalgebrauch eines **gültigen** TOTP-Codes ist in diesem Fehlerfall nicht gewährleistet. Passwort, TOTP-Validierung und Versuchslimits entfallen dadurch nicht. Es wurde keine Umgehung der Anmeldung beobachtet.
- **Abgrenzung:** [SEC-410-15/22](../audits/RELEASEAUDIT-2026-11-15_4.1.0.md#sec-410-1522-low-totp--und-backup-codes) betrifft den atomaren Verbrauch bei funktionierendem Speicher. Der vorhandene Test `TESTS/security/audit-410.php:46–52` prüft die SQL-Struktur, nicht den Rückgabewert im Ausnahmefall.
- **Empfehlung und Regression:** Wenn der Verbrauch nicht bestätigt werden kann, Anmeldung nicht abschließen; Betriebsfehler ohne Code oder Geheimnis protokollieren. Einen isolierten Test mit fehlerhaftem Speicher sowie einen Kontrollfall mit erfolgreichem Verbrauch ergänzen. **Status:** statisch belegt; nicht umgesetzt. **Konfidenz:** hoch für den Rückgabepfad.

## Testlauf und Übergabe

Lokaler Windows-Checkout, PHP **8.4.28 CLI (NTS, x64)**, Einzelaufruf je Suite aus der
Repository-Wurzel: `php TESTS/<suite>/run.php`. Ergebnisse mit geladenem `mbstring`, OpenSSL
und `pdo_sqlite`. Kein Gesamtlauf und keine CI-Abnahme.

| Suite | Exit | Beobachtet |
|---|---:|---|
| `password-policy` | 0 | 53 bestanden |
| `mfa-enforcement` | 0 | 179 bestanden |
| `login-showcase` | 0 | 153 bestanden |
| `sso-entra` | 1 | 230 bestanden, 1 fehlgeschlagen: statischer Integrationscheck der Sitzungssperre (01.6). |
| `security` | 255 | Kein vollständiges Ergebnis. Nach mehreren fehlgeschlagenen Prüfungen Abbruch wegen erneuter Deklaration von `emailLinkMethod()` (`TESTS/security/email-links.php:12`). |
| `user-role-hook` | 1 | Unter anderem fehlgeschlagene Quelltextvergleiche; die Mutanten-Gegenprobe scheitert unter Windows beim Öffnen von `/dev/null` (`TESTS/user-role-hook/run.php:1097`). Kein belastbarer Nachweis der Sitzungsrotation aus dieser Suite. |

**Nächster Schritt:** Die drei nicht grünen Suiten auf demselben Commit in einer Linux-Umgebung mit
LF-Checkout wiederholen. Danach zuerst 01.1 und 01.6 gemeinsam prüfen: parallele Sitzungen über
Rollenwechsel und SSO-Richtlinienwechsel hinweg beobachten. Testfehler ohne bestätigten Produktpfad
sind nicht als neue Sicherheitsbefunde gezählt.
