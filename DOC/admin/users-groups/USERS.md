# 365CMS – Projektdokumentation | Abschnitt: Admin – Benutzer

> **Stand:** 2026-11-09 | **Version:** 4.0.0 | **Status:** Stable
> **Route:** `/admin/users` | **Capability:** `manage_users` | **CSRF-Aktion:** `admin_users`

## English (summary)

User accounts are managed at `/admin/users` (`CMS/admin/users.php` → `CMS/admin/modules/users/UsersModule.php` → `CMS/admin/views/users/list.php`, `edit.php`; persistence in `CMS/core/Services/UserService.php`).

- Views: `list` (filters `role`, `status`, `q`, pagination `page`) and `edit`.
- Actions: `save`, `delete`, `revoke_sessions`, `cancel_email_change`, `bulk` (`activate`, `deactivate`, `delete`, `hard_delete`).
- Since 4.0.0, a changed e-mail address takes effect only after the user opens the confirmation link sent to the new address and presses the button on that page (`/cms-confirm-email-change`, valid 48 hours; opening the link alone changes nothing). The old address is notified and stays active until then. The checkbox *Neue Adresse ohne Bestätigung übernehmen* (`email_skip_confirmation`) applies the address immediately; `cancel_email_change` discards a pending change.
- *Überall abmelden* (`revoke_sessions`, since 4.0.0) signs the user out on all devices and invalidates all issued API tokens without changing the password.
- Statuses: `active`, `inactive`, `banned`, `unverified` (e-mail address not confirmed yet, since 4.0.0; see [AUTH-SETTINGS.md](AUTH-SETTINGS.md#e-mail-verifizierung-seit-400)).
- Deleting is **permanent** (single delete and both bulk delete variants call `UserService::deleteUser($id, true)`). Use `deactivate` to keep the account.
- The own account can never be deleted or changed by a bulk action. Since 4.0.0, the edit form also refuses to demote, deactivate or ban it.
- New accounts get a display name from first and last name, otherwise the username. Before 4.0.0, creating users failed on MySQL/MariaDB in strict mode.

## Deutsch

### Überblick

| Bestandteil | Datei |
|---|---|
| Einstieg | `CMS/admin/users.php` |
| Modul | `CMS/admin/modules/users/UsersModule.php` |
| Views | `CMS/admin/views/users/list.php`, `edit.php` |
| Service | `CMS/core/Services/UserService.php` |
| Tabellen | `cms_users`, `cms_user_meta`, `cms_user_group_members`, `cms_user_subscriptions` |

### Liste

- **Filter:** Rolle (`?role=`), Status (`?status=active|inactive|banned|unverified`), Suche (`?q=` Benutzername, E-Mail, Name), Seite (`?page=`).
- Spalten: Benutzer, E-Mail, Rolle, Status, Gruppen, letzter Login, Registrierung.
- Die Kopfzeile zählt unbestätigte Konten („N E-Mail unbestätigt“), sobald es welche gibt.
- **Sammelaktionen:**

| `bulk_action` | Wirkung |
|---|---|
| `activate` | Status `active` (schaltet auch unbestätigte Konten ohne Bestätigungslink frei) |
| `deactivate` | Status `inactive` (Login gesperrt, Daten bleiben erhalten) |
| `delete` | Soft-Delete: Status `inactive` (seit 3.4.12; vorher endgültig) |
| `hard_delete` | Benutzer **endgültig** löschen (einzige Löschaktion in der Oberfläche) |

Der eigene Account wird bei Sammelaktionen immer übersprungen; nicht (mehr) existierende IDs führen zu einer Fehlermeldung. Seit 4.0.0 lehnt auch das Bearbeiten-Formular ab, das eigene Konto herabzustufen, zu deaktivieren oder zu sperren.

### Bearbeiten / Anlegen

| Feld | Hinweis |
|---|---|
| Benutzername (`username`) | eindeutig |
| E-Mail (`email`) | eindeutig, validiert; beim Bearbeiten gilt eine neue Adresse seit 4.0.0 erst nach Bestätigung, siehe [E-Mail-Adresse ändern](#e-mail-adresse-ändern-seit-400) |
| Vorname / Nachname | optional; beim Anlegen bilden sie den Anzeigenamen (`display_name`), ohne Namen wird der Benutzername verwendet (max. 100 Zeichen). Bis 3.5.6 fehlte der Anzeigename, und das Anlegen scheiterte auf MySQL/MariaDB im Strict-Mode mit „Datenbankfehler beim Erstellen“ |
| Passwort (`password`) | beim Anlegen Pflicht; beim Bearbeiten leer lassen = unverändert. Es gilt die Passwortrichtlinie (Standard mind. 12 Zeichen, Groß-/Kleinbuchstaben, Ziffer, Sonderzeichen), siehe [AUTH-SETTINGS.md](AUTH-SETTINGS.md) |
| Rolle (`role`) | `admin`, `editor`, `author`, `member` sowie eigene Rollen aus [RBAC.md](RBAC.md); unbekannte Werte werden zu `member` |
| Status (`status`) | `active`, `inactive`, `banned`, `unverified` („E-Mail unbestätigt“: Anmeldung erst nach Bestätigungslink, siehe [AUTH-SETTINGS.md](AUTH-SETTINGS.md#e-mail-verifizierung-seit-400)); unbekannte Werte werden beim Anlegen zu `active` und beim Bearbeiten ignoriert |

Die Bearbeitungsansicht zeigt zusätzlich Gruppen, Abo, MFA-/Passkey-Status und letzte Aktivitäten.

### E-Mail-Adresse ändern (seit 4.0.0)

Umgesetzt in `CMS/core/Services/EmailChangeService.php`.

- **Mit Bestätigung (Standard):** Eine geänderte Adresse wird nicht sofort übernommen. Der Benutzer erhält an die **neue** Adresse einen Link (`/cms-confirm-email-change?uid=…&token=…`, 48 Stunden gültig, einmal verwendbar) und bestätigt dort mit der Schaltfläche **E-Mail-Adresse bestätigen** – das bloße Öffnen des Links ändert nichts. Die bisherige Adresse erhält einen Hinweis. Bis zur Bestätigung bleibt die alte Adresse aktiv. Die Bearbeitungsansicht zeigt „Ausstehend: …“ mit Ablaufzeit und den Button **Änderung verwerfen** (`action=cancel_email_change`).
- **Neue Adresse ohne Bestätigung übernehmen** (`email_skip_confirmation`): Die Adresse gilt sofort, die bisherige Adresse wird benachrichtigt. Der Bestätigungsstatus (`email_verified_at`) wird zurückgesetzt, Gastbestellungen werden nicht zugeordnet.
- Nach der Bestätigung per Link werden Gastbestellungen mit der neuen Adresse dem Konto zugeordnet.
- Gehört die Adresse bereits einem anderen Konto, meldet der Adminbereich einen Fehler. Ändert sich nur die Groß-/Kleinschreibung, wird die Adresse sofort übernommen.
- Höchstens 5 Änderungsanfragen je Konto und Stunde (gemeinsam mit dem Mitgliederbereich). Scheitert die Anfrage, speichert das Formular auch keine anderen Änderungen.
- Audit-Einträge `user.email_change_requested`, `user.email_changed`, `user.email_change_cancelled`; Hooks siehe [../../core/HOOKS-REFERENCE.md](../../core/HOOKS-REFERENCE.md).

### Sitzungen & API-Tokens (seit 4.0.0)

**Überall abmelden** (`action=revoke_sessions`) meldet den Benutzer auf allen Geräten ab und macht ausgestellte API-Tokens (Access und Refresh) ungültig, ohne das Passwort zu ändern, etwa bei Verdacht auf eine übernommene Sitzung. `Auth::revokeAllSessions()` erhöht dazu die Token-Version (`user_meta` `auth_token_version`) und löscht die Sitzungszeilen; Audit-Eintrag `sessions_revoked`. Ein neues Passwort beendet die anderen Sitzungen weiterhin automatisch.

### Löschen

Beim endgültigen Löschen werden Benutzer und Metadaten (per `ON DELETE CASCADE`) entfernt; offene DSGVO-Löschanträge des Benutzers werden aufgeräumt. Inhalte (Beiträge, Seiten) bleiben erhalten. Für DSGVO-konforme Löschungen mit Nachweis den Weg über [../legal/DELETION-REQUESTS.md](../legal/DELETION-REQUESTS.md) nutzen.

Alle Änderungen werden im Audit-Log protokolliert (`user_deleted`, `user_deactivated` u. a.).

### Hinweis zum Admin-Zugang

Der Adminbereich (`/admin/*`) ist ausschließlich für Benutzer mit der Rolle **`admin`** zugänglich (`AdminRouter` prüft `Auth::isAdmin()`). Andere Rollen werden nach `/member` umgeleitet. Details: [RBAC.md](RBAC.md).

### Verwandte Dokumente

[GROUPS.md](GROUPS.md) · [RBAC.md](RBAC.md) · [AUTH-SETTINGS.md](AUTH-SETTINGS.md) · [../../member/MEMBER-SECURITY.md](../../member/MEMBER-SECURITY.md)
