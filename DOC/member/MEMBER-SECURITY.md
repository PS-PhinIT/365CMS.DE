> **Website:** [365CMS.DE](https://365cms.de/) | **Version:** 4.0.0
> **Datum:** 2026-09-06 | **Status:** Stable – **Zuletzt aktualisiert am:** 2026-11-09 (Sicherheitsaudit 4.0.0: TOTP-Einrichtung mit Passwort, Capability-Prüfung fail-closed, Passkey ohne UV; Folgeaudit: E-Mail-Änderung mit Bestätigung, „Überall sonst abmelden“, verschlüsselte TOTP-Secrets)
> **Kurzbeschreibung:** Sicherheitsreferenz für Authentifizierung, CSRF, Capabilities, privates Caching, benutzerbezogene Medien, sichere Ausgabe und die Trennung von Member- und Admin-Bereich. Sie entspricht der Implementierung von Version 4.0.0.

# 365CMS Member Security

## English — user guide

Use a unique password, enable TOTP/MFA or a passkey, review active sessions, and store backup codes offline. Treat uploaded files and profile data as private: the member area is scoped to the signed-in account, and actions that change data require the page's form token.

## English — technical reference

- `MemberController::requireAuth()` protects every runtime page. `MemberRouter` applies the same login check before rendering or dispatching.
- `bootstrap.php` calls `CacheManager::sendResponseHeaders('private')`; member responses must not be shared between users.
- `csrfToken($action)` generates `member_{action}` tokens through `Security`; `verifyCsrf()` accepts the form token or `X-CSRF-TOKEN` and verifies the same action namespace.
- Form actions use separate contexts: `profile_save`, `email_change_cancel`, `notifications_save`, `privacy_action`, `messages_action`, `media_action`, `security_password`, `security_mfa`, `security_passkey`, and `security_sessions`. Logout uses a dedicated `logout` token.
- Security actions are allowlisted (`password_change`, `totp_start`, `totp_confirm`, `totp_disable`, `backup_generate`, `passkey_register`, `passkey_delete`, `sessions_revoke_all`) and select the matching CSRF context.
- Since 4.0.0, a new e-mail address entered on `/member/profile` (current password required) takes effect only after the link sent to that address is opened (`/cms-confirm-email-change`, 48 hours, single use). The old address is notified and stays active until then. If the address already belongs to another account, the member sees the same response and the owner of that address gets a notice instead.
- `sessions_revoke_all` (*Überall sonst abmelden*, current password required) ends all other sessions and invalidates issued API tokens; the current session stays signed in.
- TOTP secrets are stored encrypted (`CryptoService`, since 4.0.0).
- Media paths are normalized below `member/user-{id}`; traversal, foreign roots, and unbounded move targets are rejected. Uploads use `/api/upload`, a member token, configured size limits, and configured MIME/type allowlists.
- Profile URLs, dynamic field types, dashboard links, colors, labels, and widget text are sanitized before persistence or output. Templates escape output with `htmlspecialchars`.
- Plugin sections require login and may declare a capability. `null`/`member` allows every logged-in member, `admin` maps to `Auth::isAdmin()`, and any other value must match the user's role or a granted role capability (`Auth::hasCapability()`); unknown names fail closed since 4.0.0.
- Admin configuration is separate from member access: it requires administrator status, an allowlisted capability, the enabled admin page module, and CSRF action `admin_member_dashboard`.
- Missing database tables, unavailable optional services, failed callbacks, unknown routes, invalid settings, and missing theme files use safe fallbacks; they do not grant cross-user access.

---

# 365CMS-Sicherheit im Mitgliederbereich

## Deutsch — Anwenderblock

Verwenden Sie ein einzigartiges Passwort, aktivieren Sie TOTP/MFA oder einen Passkey, prüfen Sie aktive Sitzungen und bewahren Sie Backup-Codes offline auf. Hochgeladene Dateien und Profildaten bleiben privat: Der Bereich ist auf das angemeldete Konto begrenzt und jede Änderung benötigt das Formular-Token der jeweiligen Seite.

## Deutsch — Technikblock

- `MemberController::requireAuth()` schützt jede Runtime-Seite; `MemberRouter` prüft vor Rendering und Dispatch ebenfalls den Login.
- `bootstrap.php` ruft `CacheManager::sendResponseHeaders('private')` auf; Antworten dürfen nicht zwischen Benutzern geteilt werden.
- `csrfToken($action)` erzeugt über `Security` Tokens im Namensraum `member_{action}`. `verifyCsrf()` prüft Formular-Token oder `X-CSRF-TOKEN` im selben Kontext.
- Form-Aktionen verwenden getrennte Kontexte: `profile_save`, `email_change_cancel`, `notifications_save`, `privacy_action`, `messages_action`, `media_action`, `security_password`, `security_mfa`, `security_passkey` und `security_sessions`. Logout nutzt ein eigenes `logout`-Token.
- Sicherheitsaktionen sind aufgelistet (`password_change`, `totp_start`, `totp_confirm`, `totp_disable`, `backup_generate`, `passkey_register`, `passkey_delete`, `sessions_revoke_all`) und wählen den passenden CSRF-Kontext.
- Seit 4.0.0 gilt eine unter `/member/profile` eingegebene neue E-Mail-Adresse (aktuelles Passwort erforderlich) erst, wenn der Link an diese Adresse geöffnet wird (`/cms-confirm-email-change`, 48 Stunden, einmal verwendbar). Die bisherige Adresse erhält einen Hinweis und bleibt bis dahin aktiv. Gehört die Adresse bereits einem anderen Konto, sieht das Mitglied dieselbe Antwort, und der Inhaber der Adresse erhält stattdessen einen Hinweis.
- `sessions_revoke_all` („Überall sonst abmelden“, aktuelles Passwort erforderlich) beendet alle anderen Sitzungen und macht ausgestellte API-Tokens ungültig; die aktuelle Sitzung bleibt angemeldet.
- TOTP-Secrets liegen seit 4.0.0 verschlüsselt in der Datenbank (`CryptoService`).
- Medienpfade bleiben unter `member/user-{id}`; Traversal, fremde Roots und unbegrenzte Verschiebeziele werden abgewiesen. Uploads verwenden `/api/upload`, Member-Token, Größenlimits und konfigurierte Typ-Allowlist.
- Profil-URLs, dynamische Feldtypen, Dashboard-Links, Farben, Labels und Widgettexte werden vor Speicherung oder Ausgabe bereinigt; Templates escapen mit `htmlspecialchars`.
- Plugin-Bereiche benötigen Login und können eine Capability verlangen. `null`/`member` erlaubt jedes angemeldete Mitglied, `admin` entspricht `Auth::isAdmin()`, jeder andere Wert muss der Rolle des Benutzers oder einer seiner Rollen-Capabilities entsprechen (`Auth::hasCapability()`). Unbekannte Capability-Namen werden seit 4.0.0 verweigert (vorher freigegeben).
- Die Admin-Konfiguration ist vom Member-Zugriff getrennt: Administratorstatus, Allowlist-Capability, aktiviertes Admin-Seitenmodul und CSRF-Aktion `admin_member_dashboard` sind erforderlich.
- Fehlende Tabellen, optionale Dienste, Callback-Fehler, unbekannte Routen, ungültige Einstellungen und fehlende Theme-Dateien nutzen sichere Fallbacks und ermöglichen keinen Benutzerübergriff.

---

## Ergänzung (Stand 2026-10-02)

### Sicherheitsseite (`/member/security`) im Überblick

- **Passwort ändern:** aktuelles Passwort erforderlich; neue Passwörter müssen die Richtlinie erfüllen (≥ 12 Zeichen, Groß-/Kleinbuchstabe, Ziffer, Sonderzeichen).
- **Zwei-Faktor (TOTP):** `totp_start` verlangt das aktuelle Passwort und zeigt Secret und QR-Code (`otpauth://`-URI), `totp_confirm` aktiviert nur das in dieser Sitzung erzeugte Secret nach gültigem Code und zeigt die Backup-Codes **einmalig** an; `totp_disable` schaltet ab. Ist TOTP bereits aktiv, wird eine Neueinrichtung abgelehnt (erst deaktivieren). `/mfa-setup` verlangt ebenfalls das aktuelle Passwort (4.0.0).
- **Backup-Codes:** Anzahl verbleibender Codes wird angezeigt; `backup_generate` erzeugt einen neuen Satz.
- **Passkeys:** Registrierung über WebAuthn im Browser (`passkey_register`), Entfernen einzelner Schlüssel (`passkey_delete`). Voraussetzung: HTTPS und korrekte `SITE_URL`.
- **Aktive Sitzungen:** Liste der eigenen Sessions (Gerät, IP, letzte Aktivität).
- **Überall sonst abmelden** (seit 4.0.0, `sessions_revoke_all`): beendet nach Eingabe des aktuellen Passworts alle anderen Browser-Sitzungen und macht ausgestellte API-Tokens ungültig; das Passwort bleibt unverändert.

### E-Mail-Adresse ändern (`/member/profile`, seit 4.0.0)

- Für eine neue Adresse ist das aktuelle Passwort nötig. Sie wird erst übernommen, wenn der Link aus der Bestätigungs-Mail an die neue Adresse geöffnet wird (48 Stunden gültig). Bis dahin bleibt die alte Adresse aktiv und erhält einen Hinweis auf die angeforderte Änderung.
- Das Profil zeigt die ausstehende Adresse; **Änderung verwerfen** (`email_change_cancel`) löscht sie.
- Höchstens 5 Änderungsanfragen je Stunde; Bestätigungslinks höchstens 10-mal je IP in 15 Minuten.
- Nach der Bestätigung gilt die Adresse als bestätigt, und Gastbestellungen mit dieser Adresse werden dem Konto zugeordnet.

### Bekannte Einschränkung beim Datei-Upload

Die Upload-Maske unter `/member/media` übergibt seit 3.4.12 ein Token der Aktion `media_action`, die `POST /api/upload` prüft (vorher `member_media_action`, wodurch der erste Upload je Seitenaufruf mit 403 scheiterte). Jede Antwort enthält ein neues Token für Folge-Uploads.
