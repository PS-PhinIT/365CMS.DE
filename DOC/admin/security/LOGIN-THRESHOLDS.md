# 365CMS – Admin: Login & Sperren

> **Stand:** 2026-11-12 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

*Settings → Login & lockout* (`/admin/settings?tab=security`, `manage_settings`) sets the number of failed attempts
before an IP address is locked out (3–20) and the lockout period (60–3600 seconds). Without a setting, the constants
`MAX_LOGIN_ATTEMPTS` and `LOGIN_TIMEOUT` from `config/app.php` apply. The page shows the effective value and its
source. The limits apply to admin and member login and additionally cap password resets via link.

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
