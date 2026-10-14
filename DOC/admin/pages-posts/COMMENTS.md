# 365CMS – Projektdokumentation | Abschnitt: Admin – Kommentare

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/comments` | **Capabilities:** `comments.view`, `comments.moderate`, `comments.delete` | **CSRF-Aktion:** `admin_comments`

## English (summary)

Comment moderation lives at `/admin/comments` (`CMS/admin/comments.php` → `CMS/admin/modules/comments/CommentsModule.php` → `CMS/admin/views/comments/list.php`; persistence in `CMS/core/Services/CommentService.php`).

- Status tabs: `all`, `pending`, `approved`, `spam`, `trash`.
- Filters: free text `q`, `author_scope` (`all|registered|guest|anonymous`), `link_scope` (`all|linked|orphaned`), `content_view` (`excerpt|full`).
- POST actions: `status`, `delete`, `bulk` (`approve|spam|trash|delete`, max. 100 IDs), `empty_trash`.
- Frontend submissions go to `POST /comments/post` with a per-post CSRF token (`comment_<postId>`), are rate-limited and always start as `pending`; administrators receive an e-mail notification.

## Deutsch

### Überblick

| Bestandteil | Datei |
|---|---|
| Einstieg | `CMS/admin/comments.php` |
| Modul | `CMS/admin/modules/comments/CommentsModule.php` |
| View | `CMS/admin/views/comments/list.php` |
| Service | `CMS/core/Services/CommentService.php` |
| Frontend-Endpunkt | `PublicRouter::handleCommentPost()` → `POST /comments/post` |
| Spam-Schutz | `CMS/core/Services/AntispamService.php` ([../security/ANTISPAM.md](../security/ANTISPAM.md)) |

### Berechtigungen

| Capability | Erlaubt |
|---|---|
| `comments.view` | Liste ansehen |
| `comments.moderate` | Status ändern (freigeben, Spam, Papierkorb) |
| `comments.delete` | Endgültig löschen, Papierkorb leeren |

### Oberfläche

- **Kennzahlenkarten:** Gesamt, Ausstehend, Freigegeben, Spam.
- **Status-Tabs:** Alle · Ausstehend · Freigegeben · Spam · Papierkorb (jeweils mit Zähler).
- **Filter:**
  - Suche `q` (Autor, E-Mail, Inhalt; max. Länge begrenzt, Steuerzeichen werden entfernt, `%`/`_` wörtlich)
  - Autorentyp `author_scope`: `registered` (Mitglied), `guest` (Gast), `anonymous` (Mitglied, das „anonym“ kommentiert hat)
  - Verknüpfung `link_scope`: `linked` (Beitrag existiert) oder `orphaned` (Beitrag gelöscht)
  - Darstellung `content_view`: `excerpt` (gekürzt) oder `full`
- Jede Zeile zeigt Autor mit Initialen-Avatar, E-Mail, Datum, Status-Badge und den Link zum Beitrag (sofern vorhanden).

### Aktionen

| `action` | Parameter | Wirkung |
|---|---|---|
| `status` | `id`, `status` (`pending`, `approved`, `spam`, `trash`) | Status eines Kommentars ändern |
| `delete` | `id` | Kommentar endgültig löschen |
| `bulk` | `bulk_action` (`approve`, `spam`, `trash`, `delete`), `ids[]` | Sammelaktion, höchstens **100** IDs |
| `empty_trash` | – | Alle Kommentare im Papierkorb löschen |

Alle Aktionen werden mit Ergebnis (verarbeitet/fehlgeschlagen) im Audit-Log protokolliert (`comments.*`).

### Ablauf eines Frontend-Kommentars

1. Das Theme rendert das Formular mit CSRF-Token `comment_<postId>`.
2. `POST /comments/post` prüft Token, Länge (max. 5000 Zeichen) und Pflichtfelder.
3. `CommentService::createPendingComment()` normalisiert Name, E-Mail und IP, prüft, ob der Beitrag kommentierbar ist (Status `published` und `allow_comments = 1`) und wendet ein **Flood-Limit** pro E-Mail/IP/Benutzer an.
4. Angemeldete Mitglieder können „anonym“ kommentieren; angezeigt wird dann „Anonym“.
5. Der Kommentar wird mit Status `pending` gespeichert, Administratoren erhalten eine Benachrichtigungs-E-Mail mit Link zu `/admin/comments`.
6. Bei Fehlern werden die Formulardaten in der Session gehalten und der Besucher zurück auf `#comments` geleitet.

### Einstellungen

- Global: *Einstellungen → Allgemein → Kommentare aktiviert* (`comments_enabled`).
- Pro Beitrag: Option „Kommentare erlauben“ im Beitragseditor.
- Spam-Regeln (Honeypot, Blacklist, Linklimit usw.): [../security/ANTISPAM.md](../security/ANTISPAM.md).

### Datenmodell

Tabelle `cms_comments`: `id`, `post_id`, `user_id`, `author`, `author_email`, `author_ip`, `content`, `status` (ENUM `pending|approved|spam|trash`, Standard `pending`), `post_date`, `modified_at`. Details siehe [../../core/DATABASE-SCHEMA.md](../../core/DATABASE-SCHEMA.md).

### Verwandte Dokumente

[POSTS.md](POSTS.md) · [../security/ANTISPAM.md](../security/ANTISPAM.md) · [../legal/DSGVO.md](../legal/DSGVO.md)
