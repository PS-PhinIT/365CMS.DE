# 365CMS – Projektdokumentation | Abschnitt: Admin – Benutzer- & Auth-Einstellungen

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/user-settings` | **Capability:** `manage_users` | **CSRF-Aktion:** `admin_user_settings`

## English (summary)

`/admin/user-settings` (`CMS/admin/user-settings.php` → `CMS/admin/modules/users/UserSettingsModule.php` → `CMS/admin/views/users/settings.php`) controls registration and shows the technical state of all authentication providers.

- Database settings: `registration_enabled`, `member_registration_enabled`, `member_email_verification`, `member_default_role`.
- Read-only status: login protection (`MAX_LOGIN_ATTEMPTS`, `LOGIN_TIMEOUT`), password policy (12 characters, upper/lower case, digit, special character), session lifetimes, providers (password, TOTP, backup codes, passkeys/WebAuthn, LDAP), JWT.
- Action `sync_ldap` imports up to 250 directory users.
- Provider credentials (LDAP, JWT, SMTP) are constants in `CMS/config/app.php`, never stored in the database.

## Deutsch

### Bereich „Registrierung & Konten“ (in der Datenbank gespeichert)

| Schalter | Option | Standard | Wirkung |
|---|---|---|---|
| Globale Benutzerregistrierung | `registration_enabled` | aus | Öffentliche Registrierung (Standardpfad `/cms-register`, `/register` leitet weiter; Pfade konfigurierbar unter CMS-Loginseite) |
| Registrierung im Member-Bereich | `member_registration_enabled` | an | Registrierungseinstieg im Member-Kontext |
| E-Mail-Verifizierung erzwingen | `member_email_verification` | aus | Neue Konten müssen den Bestätigungslink anklicken |
| Standardrolle neuer Mitglieder | `member_default_role` | `member` | Nur Rollen ohne Adminrechte wählbar (`UserService::resolveRegistrationRole()`) |

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

> **Wichtig:** Das Speichern unter `/admin/settings` erzeugt `config/app.php` neu und setzt dabei `LDAP_*` und `JWT_*` auf leere Standardwerte zurück. Werte nach jedem Speichern der allgemeinen Einstellungen prüfen bzw. erneut eintragen ([../system-settings/SYSTEM.md](../system-settings/SYSTEM.md#speichern-und-configappphp)).

### Sicherheitshinweise

- Zugangsdaten (LDAP-Service-Account, JWT-Secret, SMTP) gehören ausschließlich in `config/app.php` und werden in der Oberfläche nie im Klartext angezeigt.
- Nach Änderungen an Registrierung oder Verifizierung den Ablauf einmal mit einem Testkonto prüfen.
- Die Anmeldeseite selbst (Branding, Texte) wird unter [../themes-design/CMS-LOGINPAGE.md](../themes-design/CMS-LOGINPAGE.md) gestaltet.

### Verwandte Dokumente

[USERS.md](USERS.md) · [RBAC.md](RBAC.md) · [../../member/MEMBER-SECURITY.md](../../member/MEMBER-SECURITY.md) · [../../core/SECURITY.md](../../core/SECURITY.md) · [../../assets/ldaprecord/README.md](../../assets/ldaprecord/README.md) · [../../assets/webauthn/README.md](../../assets/webauthn/README.md)
