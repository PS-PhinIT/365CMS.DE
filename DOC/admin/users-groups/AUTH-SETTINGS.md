# 365CMS – Projektdokumentation | Abschnitt: Admin – Benutzer- & Auth-Einstellungen

> **Stand:** 2026-11-08 | **Version:** 4.0.0 | **Status:** Stable
> **Route:** `/admin/user-settings` | **Capability:** `manage_users` | **CSRF-Aktion:** `admin_user_settings`

## English (summary)

`/admin/user-settings` (`CMS/admin/user-settings.php` → `CMS/admin/modules/users/UserSettingsModule.php` → `CMS/admin/views/users/settings.php`) controls registration and shows the technical state of all authentication providers.

- Database settings: `registration_enabled`, `member_registration_enabled`, `member_email_verification`, `member_default_role`.
- Since 4.0.0, `member_email_verification` is enforced: new registrations get the status `unverified` and a confirmation link (`/cms-verify-email`, valid for 48 hours, single use). They can sign in only after confirming. A completed password reset also confirms the address.
- Read-only status: login protection (`MAX_LOGIN_ATTEMPTS`, `LOGIN_TIMEOUT`), password policy (12 characters, upper/lower case, digit, special character), session lifetimes, providers (password, TOTP, backup codes, passkeys/WebAuthn, LDAP), JWT.
- Action `sync_ldap` imports up to 250 directory users.
- Provider credentials (LDAP, JWT, SMTP) are constants in `CMS/config/app.php`, never stored in the database.

## Deutsch

### Bereich „Registrierung & Konten“ (in der Datenbank gespeichert)

| Schalter | Option | Standard | Wirkung |
|---|---|---|---|
| Globale Benutzerregistrierung | `registration_enabled` | aus | Öffentliche Registrierung (Standardpfad `/cms-register`, `/register` leitet weiter; Pfade konfigurierbar unter CMS-Loginseite) |
| Registrierung im Member-Bereich | `member_registration_enabled` | an | Registrierungseinstieg im Member-Kontext |
| E-Mail-Verifizierung erzwingen | `member_email_verification` | aus | Neue Registrierungen müssen den Bestätigungslink aus der Mail öffnen, bevor sie sich anmelden können (seit 4.0.0 durchgesetzt, vorher ohne Wirkung). Bestehende Konten bleiben unverändert; siehe [E-Mail-Verifizierung](#e-mail-verifizierung-seit-400) |
| Standardrolle neuer Mitglieder | `member_default_role` | `member` | Nur Rollen ohne Adminrechte wählbar (`UserService::resolveRegistrationRole()`) |

### E-Mail-Verifizierung (seit 4.0.0)

Umgesetzt in `CMS/core/Services/EmailVerificationService.php`.

1. **Registrierung:** Ist die Option an, legt `Auth::register()` das Konto mit dem Status `unverified` an und sendet eine Bestätigungsmail an die angegebene Adresse.
2. **Link:** `/cms-verify-email?uid=<id>&token=<64 Hex-Zeichen>` (englische Anfragen mit `/en`-Präfix), 48 Stunden gültig und nur einmal verwendbar. In der Datenbank liegt nur der SHA-256-Hash des Tokens (`user_meta` `email_verification_token`, Ablauf in `email_verification_expires`).
3. **Bestätigung:** Der Link setzt den Status auf `active`, löscht das Token, speichert `email_verified_at`, schreibt den Audit-Eintrag `auth.email_verified` und löst den Hook `user_email_verified` (Parameter: Benutzer-ID) aus. Anschließend leitet er mit Hinweis auf die Anmeldeseite.
4. **Anmeldung vor der Bestätigung:** Mit richtigem Passwort erscheint der Hinweis „E-Mail-Adresse noch nicht bestätigt“, und ein neuer Link wird gesendet (höchstens 3 Mails je Konto und Stunde; der alte Link wird ungültig). Mit falschem Passwort bleibt es bei der allgemeinen Fehlermeldung.
5. **Passwort vergessen:** Funktioniert auch für unbestätigte Konten. Ein abgeschlossener Reset belegt den Zugriff auf das Postfach und aktiviert das Konto. So kann niemand eine fremde Adresse dauerhaft blockieren.
6. **Rate-Limit:** höchstens 10 Bestätigungsversuche je IP in 15 Minuten.

Unbestätigte Konten erscheinen in der Benutzerliste mit dem Status „E-Mail unbestätigt“ (Filter `?status=unverified`, Zähler in der Kopfzeile). Freischalten ohne Link: Status auf `active` setzen oder Sammelaktion *Aktivieren*. Wird die Option ausgeschaltet, sind neue Konten sofort aktiv; bereits unbestätigte Konten bleiben unbestätigt, können ihren Link aber weiter nutzen.

Voraussetzung ist ein funktionierender Mailversand. Bei aktiver Mail-Warteschlange muss der Cron laufen.

### Login-Schutz und Passwortrichtlinie (Anzeige)

| Wert | Quelle | Standard |
|---|---|---|
| Max. Fehlversuche | `MAX_LOGIN_ATTEMPTS` in `config/app.php` | 5 |
| Sperrdauer | `LOGIN_TIMEOUT` | 300 s |
| Passwort-Mindestlänge | `Auth::PASSWORD_POLICY_MIN_LENGTH` | 12 |
| Zeichenklassen | `Auth::getPasswordPolicyDefinition()` | Groß-, Kleinbuchstabe, Ziffer, Sonderzeichen |
| Session Admin | Option `perf_session_timeout_admin` | 8 Stunden |
| Session Mitglied | Option `perf_session_timeout_member` | 30 Tage |

Session-Laufzeiten werden unter `/admin/performance-sessions` geändert ([../performance/PERFORMANCE.md](../performance/PERFORMANCE.md)).

### Auth-Provider

`AuthManager::getAvailableProviders()` liefert den Status aller Verfahren:

| Provider | Implementierung | Voraussetzung |
|---|---|---|
| Passwort | `CMS/core/Auth.php` | – |
| TOTP (Authenticator-App) | `CMS/core/Auth/MFA/TotpAdapter.php`, `CMS/core/Totp.php` | Benutzer aktiviert im Member-Bereich |
| Backup-Codes | `CMS/core/Auth/MFA/BackupCodesManager.php` | zusammen mit TOTP |
| Passkeys (WebAuthn) | `CMS/core/Auth/Passkey/WebAuthnAdapter.php`, Tabelle `cms_passkey_credentials` | `SITE_URL` gesetzt, OpenSSL, HTTPS |
| LDAP / Active Directory | `CMS/core/Auth/LDAP/LdapAuthProvider.php` | PHP-Erweiterung `ldap`, Konstanten `LDAP_*` |
| JWT (API) | `CMS/core/Services/JwtService.php` | `JWT_SECRET` (Fallback `AUTH_KEY`), `JWT_TTL` (3600 s), `JWT_ISSUER` |

Die Seite zeigt außerdem Kennzahlen: Benutzer gesamt/aktiv, Benutzer mit MFA, mit Backup-Codes und Anzahl registrierter Passkeys.

### LDAP

Konfiguration in `CMS/config/app.php`:

```php
define('LDAP_HOST', 'ldap.example.com');
define('LDAP_PORT', 389);
define('LDAP_BASE_DN', 'dc=example,dc=com');
define('LDAP_USERNAME', 'cn=svc-cms,ou=Service,dc=example,dc=com');
define('LDAP_PASSWORD', '…');
define('LDAP_USE_SSL', false);
define('LDAP_USE_TLS', true);
define('LDAP_FILTER', '(sAMAccountName={username})');
define('LDAP_DEFAULT_ROLE', 'member');
```

Der Button **„LDAP-Erstsynchronisierung“** (`action=sync_ldap`) legt bis zu **250** Verzeichnisbenutzer mit `LDAP_DEFAULT_ROLE` an. Das Ergebnis wird im Audit-Log (`user_settings.ldap.sync`) protokolliert. Der Button ist deaktiviert, solange LDAP nicht vollständig konfiguriert ist.

> **Hinweis:** Das Speichern unter `/admin/settings` ändert in `config/app.php` nur `SITE_NAME`, `SITE_URL`, `ADMIN_EMAIL` und `CMS_DEBUG`; `LDAP_*` und `JWT_*` bleiben erhalten. Nur wenn eine dieser vier Konstanten fehlt oder mehrfach definiert ist, erzeugt das CMS die Datei aus der Vorlage neu und setzt `LDAP_*`/`JWT_*` auf leere Standardwerte; die vorherige Fassung liegt dann als `config/app.backup-<Datum>.php` vor ([../system-settings/SYSTEM.md](../system-settings/SYSTEM.md#speichern-und-configappphp)).

### Sicherheitshinweise

- Zugangsdaten (LDAP-Service-Account, JWT-Secret, SMTP) gehören ausschließlich in `config/app.php` und werden in der Oberfläche nie im Klartext angezeigt.
- Nach Änderungen an Registrierung oder Verifizierung den Ablauf einmal mit einem Testkonto prüfen.
- Die Anmeldeseite selbst (Branding, Texte) wird unter [../themes-design/CMS-LOGINPAGE.md](../themes-design/CMS-LOGINPAGE.md) gestaltet.

### Verwandte Dokumente

[USERS.md](USERS.md) · [RBAC.md](RBAC.md) · [../../member/MEMBER-SECURITY.md](../../member/MEMBER-SECURITY.md) · [../../core/SECURITY.md](../../core/SECURITY.md) · [../../assets/ldaprecord/README.md](../../assets/ldaprecord/README.md) · [../../assets/webauthn/README.md](../../assets/webauthn/README.md)
