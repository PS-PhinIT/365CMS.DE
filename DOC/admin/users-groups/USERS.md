# 365CMS – Projektdokumentation | Abschnitt: Admin – Benutzer

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/users` | **Capability:** `manage_users` | **CSRF-Aktion:** `admin_users`

## English (summary)

User accounts are managed at `/admin/users` (`CMS/admin/users.php` → `CMS/admin/modules/users/UsersModule.php` → `CMS/admin/views/users/list.php`, `edit.php`; persistence in `CMS/core/Services/UserService.php`).

- Views: `list` (filters `role`, `status`, `q`, pagination `page`) and `edit`.
- Actions: `save`, `delete`, `bulk` (`activate`, `deactivate`, `delete`, `hard_delete`).
- Statuses: `active`, `inactive`, `banned`.
- Deleting is **permanent** (single delete and both bulk delete variants call `UserService::deleteUser($id, true)`). Use `deactivate` to keep the account.
- The own account can never be deleted or changed by a bulk action.

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

- **Filter:** Rolle (`?role=`), Status (`?status=active|inactive|banned`), Suche (`?q=` Benutzername, E-Mail, Name), Seite (`?page=`).
- Spalten: Benutzer, E-Mail, Rolle, Status, Gruppen, letzter Login, Registrierung.
- **Sammelaktionen:**

| `bulk_action` | Wirkung |
|---|---|
| `activate` | Status `active` |
| `deactivate` | Status `inactive` (Login gesperrt, Daten bleiben erhalten) |
| `delete` | Soft-Delete: Status `inactive` (seit 3.5.0; vorher endgültig) |
| `hard_delete` | Benutzer **endgültig** löschen (einzige Löschaktion in der Oberfläche) |

Der eigene Account wird bei Sammelaktionen immer übersprungen; nicht (mehr) existierende IDs führen zu einer Fehlermeldung.

### Bearbeiten / Anlegen

| Feld | Hinweis |
|---|---|
| Benutzername (`username`) | eindeutig |
| E-Mail (`email`) | eindeutig, validiert |
| Vorname / Nachname | optional |
| Passwort (`password`) | beim Anlegen Pflicht; beim Bearbeiten leer lassen = unverändert. Es gilt die Passwortrichtlinie (Standard mind. 12 Zeichen, Groß-/Kleinbuchstaben, Ziffer, Sonderzeichen), siehe [AUTH-SETTINGS.md](AUTH-SETTINGS.md) |
| Rolle (`role`) | `admin`, `editor`, `author`, `member` sowie eigene Rollen aus [RBAC.md](RBAC.md); unbekannte Werte werden zu `member` |
| Status (`status`) | `active`, `inactive`, `banned` |

Die Bearbeitungsansicht zeigt zusätzlich Gruppen, Abo, MFA-/Passkey-Status und letzte Aktivitäten.

### Löschen

Beim endgültigen Löschen werden Benutzer und Metadaten (per `ON DELETE CASCADE`) entfernt; offene DSGVO-Löschanträge des Benutzers werden aufgeräumt. Inhalte (Beiträge, Seiten) bleiben erhalten. Für DSGVO-konforme Löschungen mit Nachweis den Weg über [../legal/DELETION-REQUESTS.md](../legal/DELETION-REQUESTS.md) nutzen.

Alle Änderungen werden im Audit-Log protokolliert (`user_deleted`, `user_deactivated` u. a.).

### Hinweis zum Admin-Zugang

Der Adminbereich (`/admin/*`) ist ausschließlich für Benutzer mit der Rolle **`admin`** zugänglich (`AdminRouter` prüft `Auth::isAdmin()`). Andere Rollen werden nach `/member` umgeleitet. Details: [RBAC.md](RBAC.md).

### Verwandte Dokumente

[GROUPS.md](GROUPS.md) · [RBAC.md](RBAC.md) · [AUTH-SETTINGS.md](AUTH-SETTINGS.md) · [../../member/MEMBER-SECURITY.md](../../member/MEMBER-SECURITY.md)
