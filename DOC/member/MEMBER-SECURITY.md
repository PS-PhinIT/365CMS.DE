> **Website:** [365CMS.DE](https://365cms.de/) | **Version:** 3.5.0
> **Datum:** 2026-09-06 | **Status:** Stable – **Zuletzt aktualisiert am:** 2026-10-02 (Abgleich mit `MemberRouter`, `MemberController`, `PluginDashboardRegistry`)
> **Kurzbeschreibung:** Sicherheitsreferenz für Authentifizierung, CSRF, Capabilities, privates Caching, benutzerbezogene Medien, sichere Ausgabe und die Trennung von Member- und Admin-Bereich. Sie entspricht der Implementierung von Version 3.5.0.

# 365CMS Member Security

## English — user guide

Use a unique password, enable TOTP/MFA or a passkey, review active sessions, and store backup codes offline. Treat uploaded files and profile data as private: the member area is scoped to the signed-in account, and actions that change data require the page's form token.

## English — technical reference

- `MemberController::requireAuth()` protects every runtime page. `MemberRouter` applies the same login check before rendering or dispatching.
- `bootstrap.php` calls `CacheManager::sendResponseHeaders('private')`; member responses must not be shared between users.
- `csrfToken($action)` generates `member_{action}` tokens through `Security`; `verifyCsrf()` accepts the form token or `X-CSRF-TOKEN` and verifies the same action namespace.
- Form actions use separate contexts: `profile_save`, `notifications_save`, `privacy_action`, `messages_action`, `media_action`, `security_password`, `security_mfa`, and `security_passkey`. Logout uses a dedicated `logout` token.
- Security actions are allowlisted (`password_change`, `totp_start`, `totp_confirm`, `totp_disable`, `backup_generate`, `passkey_register`, `passkey_delete`) and select the matching CSRF context.
- Media paths are normalized below `member/user-{id}`; traversal, foreign roots, and unbounded move targets are rejected. Uploads use `/api/upload`, a member token, configured size limits, and configured MIME/type allowlists.
- Profile URLs, dynamic field types, dashboard links, colors, labels, and widget text are sanitized before persistence or output. Templates escape output with `htmlspecialchars`.
- Plugin sections require login and may declare a capability. The built-in `admin` capability maps to `Auth::isAdmin()`; unknown capability names currently fail open in the registry helper and must therefore be restricted by plugin authors.
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
- Form-Aktionen verwenden getrennte Kontexte: `profile_save`, `notifications_save`, `privacy_action`, `messages_action`, `media_action`, `security_password`, `security_mfa` und `security_passkey`. Logout nutzt ein eigenes `logout`-Token.
- Sicherheitsaktionen sind aufgelistet (`password_change`, `totp_start`, `totp_confirm`, `totp_disable`, `backup_generate`, `passkey_register`, `passkey_delete`) und wählen den passenden CSRF-Kontext.
- Medienpfade bleiben unter `member/user-{id}`; Traversal, fremde Roots und unbegrenzte Verschiebeziele werden abgewiesen. Uploads verwenden `/api/upload`, Member-Token, Größenlimits und konfigurierte Typ-Allowlist.
- Profil-URLs, dynamische Feldtypen, Dashboard-Links, Farben, Labels und Widgettexte werden vor Speicherung oder Ausgabe bereinigt; Templates escapen mit `htmlspecialchars`.
- Plugin-Bereiche benötigen Login und können eine Capability verlangen. `admin` entspricht `Auth::isAdmin()`; unbekannte Capability-Namen werden in der Registry derzeit als zulässig behandelt und müssen daher von Plugin-Autoren eingeschränkt werden.
- Die Admin-Konfiguration ist vom Member-Zugriff getrennt: Administratorstatus, Allowlist-Capability, aktiviertes Admin-Seitenmodul und CSRF-Aktion `admin_member_dashboard` sind erforderlich.
- Fehlende Tabellen, optionale Dienste, Callback-Fehler, unbekannte Routen, ungültige Einstellungen und fehlende Theme-Dateien nutzen sichere Fallbacks und ermöglichen keinen Benutzerübergriff.

---

## Ergänzung (Stand 2026-10-02)

### Sicherheitsseite (`/member/security`) im Überblick

- **Passwort ändern:** aktuelles Passwort erforderlich; neue Passwörter müssen die Richtlinie erfüllen (≥ 12 Zeichen, Groß-/Kleinbuchstabe, Ziffer, Sonderzeichen).
- **Zwei-Faktor (TOTP):** `totp_start` zeigt Secret und QR-Code (`otpauth://`-URI), `totp_confirm` aktiviert nach gültigem Code und zeigt die Backup-Codes **einmalig** an; `totp_disable` schaltet ab.
- **Backup-Codes:** Anzahl verbleibender Codes wird angezeigt; `backup_generate` erzeugt einen neuen Satz.
- **Passkeys:** Registrierung über WebAuthn im Browser (`passkey_register`), Entfernen einzelner Schlüssel (`passkey_delete`). Voraussetzung: HTTPS und korrekte `SITE_URL`.
- **Aktive Sitzungen:** Liste der eigenen Sessions (Gerät, IP, letzte Aktivität).

### Bekannte Einschränkung beim Datei-Upload

Die Upload-Maske unter `/member/media` übergibt seit 3.5.0 ein Token der Aktion `media_action`, die `POST /api/upload` prüft (vorher `member_media_action`, wodurch der erste Upload je Seitenaufruf mit 403 scheiterte). Jede Antwort enthält ein neues Token für Folge-Uploads.
