# 365CMS – Admin: Login & Sperren

> **Stand:** 2026-11-14 | **Version:** 4.0.0 (Abschnitte „seit 4.1.0“: in Entwicklung) | **Status:** Stable

## English (summary)

*Settings → Login & lockout* (`/admin/settings?tab=security`, `manage_settings`) sets the number of failed attempts
before an IP address is locked out (3–20) and the lockout period (60–3600 seconds). Without a setting, the constants
`MAX_LOGIN_ATTEMPTS` and `LOGIN_TIMEOUT` from `config/app.php` apply. The page shows the effective value and its
source. The limits apply to admin and member login and additionally cap password resets via link.
Since 4.1.0 the page also holds the password policy and role-based MFA enforcement (accounts of selected roles without
TOTP or a user-verified passkey are confined to the MFA setup until they add a factor).

## Deutsch

| Feld | Bereich | ohne Eintrag |
|---|---|---|
| Fehlversuche bis zur Sperre | 3–20 | `MAX_LOGIN_ATTEMPTS` aus `config/app.php` (Standard 5) |
| Sperrzeit | 60–3600 Sekunden | `LOGIN_TIMEOUT` aus `config/app.php` (Standard 300) |

- Die Seite zeigt den wirksamen Wert und seine Quelle (*Einstellung* oder *Konstante*). Auch Konstanten außerhalb des
  erlaubten Bereichs werden begrenzt. *Wieder Werte aus config/app.php verwenden* entfernt die Einstellung.
- Gezählt werden Fehlversuche je IP-Adresse in der Tabelle `login_attempts`. Das gilt für die Admin- und die
  Mitglieder-Anmeldung (`Auth::login()`); die Passwort-Zurücksetzung per Link wird höchstens so großzügig begrenzt wie
  der Login. Die zusätzliche Verzögerung je Konto nach 5 Fehlversuchen bleibt unabhängig davon aktiv.
- Änderungen werden im Audit-Log festgehalten (`security.login_thresholds_updated`).
- Dienst: `CMS\Services\LoginThrottleSettings` (`maxAttempts()`, `lockoutSeconds()`, `describe()`, `save()`).

## Passwort-Richtlinie (seit 4.1.0)

Zweites Formular auf derselben Seite (eigenes CSRF-Token `admin_password_policy`, Audit-Log `password_policy_saved`).
Die Standardwerte entsprechen dem Verhalten bis 4.0.0.

| Feld | Bereich | Standard |
|---|---|---|
| Mindestlänge | 8–128 Zeichen | 12 |
| Zeichenklassen | Groß-, Kleinbuchstabe, Ziffer, Sonderzeichen (je abschaltbar) | alle an |
| Passwort-Historie | 0–24 (0 = aus) | 5 |
| Höchstalter | 0–730 Tage (0 = nie) | 0 |

- **Zeichenklassen sind Unicode-fähig:** Umlaute zählen als Groß- bzw. Kleinbuchstaben, als Sonderzeichen gilt alles außer
  Buchstaben und Ziffern (bis 4.0.0 nur `A–Z`/`a–z`; `ß` zählte als Sonderzeichen). Der Live-Tester unter
  *Benutzer → Einstellungen* prüft mit denselben Mustern.
- **Geltungsbereich:** Registrierung, Passwortänderung im Mitgliederbereich, Anlegen/Bearbeiten im Admin, Zurücksetzen per
  Link. Die Historie wird geprüft, sobald das Konto feststeht (Änderung, Admin-Bearbeitung, Zurücksetzen); gespeichert werden
  nur bcrypt-Hashes (`password_history`). Historie 0 löscht die gespeicherten Hashes beim nächsten Wechsel.
- **Höchstalter:** Nach dem Passwort-Login mit abgelaufenem Passwort führt jede Seite zur Passwortänderung, bis ein neues
  Passwort gesetzt ist. Grundlage ist `users.password_changed_at` (Bestandskonten: Registrierungsdatum).
- **Erweiterungen** können die Richtlinie über den Filter `cms_password_policy` nur verschärfen; jede Änderung löst
  `cms_password_changed` aus.
- Dienst: `CMS\Services\Security\PasswordPolicyService`; Spezifikation:
  [workflow/specs/v4.1/01-IDENTITY.md](../../workflow/specs/v4.1/01-IDENTITY.md) §1a.

## MFA-Zwang je Rolle (seit 4.1.0)

Drittes Formular auf derselben Seite (eigenes CSRF-Token `admin_mfa_policy`, Audit-Log `mfa_policy_saved` mit
Vorher/Nachher). Standard: keine Rolle.

- **Wirkung:** Ein angemeldetes Konto einer gewählten Rolle ohne anerkannten zweiten Faktor erreicht nur noch
  `/mfa-setup`, `/member/security` (TOTP einrichten, Passkey registrieren), `/logout` und statische Assets. Alle anderen
  Seiten leiten mit 303 auf `/mfa-setup` um, API-Aufrufe mit Sitzung erhalten `403 {"error":"mfa_setup_required"}`.
  Aufrufe mit Bearer-Token (API-Clients) sind ausgenommen. Die erste Umleitung je Sitzung schreibt
  `mfa_enforcement_redirect` ins Audit-Log.
- **Anerkannte Faktoren:** aktive Authenticator-App (TOTP) oder ein Passkey mit User Verification (PIN/Biometrie,
  `passkey_credentials.user_verified = 1`). Passkeys aus der Zeit vor 4.1.0 zählen nach der nächsten Anmeldung mit
  PIN/Biometrie. Eine Entra-ID-Anmeldung mit MFA-Nachweis gilt nur für die laufende Sitzung (Bereich 1c).
- **Kein Aussperren:** Die eigene Rolle lässt sich nur wählen, wenn das eigene Konto bereits einen Faktor hat. Bei aktivem
  Zwang lehnen `/mfa-disable`, „2FA deaktivieren“ und „Passkey entfernen“ das Entfernen des letzten Faktors ab.
- **Reihenfolge der Weiche** (`Router::dispatch()`, nach dem Wartungsmodus, vor dem CSRF-Block): erst abgelaufenes
  Passwort (nur Sicherheitsseite mit Aktion `password_change`), dann MFA-Zwang.
- **Erweiterungen** können den Zwang über den Filter `cms_mfa_enforcement_required` zusätzlich einschalten, aber für eine
  gewählte Rolle nicht aufheben.
- Dienst: `CMS\Services\Security\MfaEnforcementService`; Tests: `TESTS/mfa-enforcement`; Spezifikation:
  [01-IDENTITY.md](../../workflow/specs/v4.1/01-IDENTITY.md) §1b.
