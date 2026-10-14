# 365CMS – Projektdokumentation | Abschnitt: Admin – Löschanträge (Art. 17 DSGVO)

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/data-requests` (Bereich „Löschung“; `/admin/deletion-requests` leitet um) | **Capability:** `manage_settings` | **Core-Modul:** `legal`

## English (summary)

Erasure requests (`type = deletion` in `cms_privacy_requests`) are handled by `CMS/admin/modules/legal/DeletionRequestsModule.php`. A member request sets `execute_after` to 30 days in the future (cool-down). `execute` refuses to run before that date; afterwards it permanently deletes the user via `UserService::deleteUser($id, true)` and marks the request `completed`. Other actions: `process`, `reject` (with reason), `escalate` (mail to admin), `delete`.

## Deutsch

### Ablauf

1. **Antrag:** Mitglied klickt unter `/member/privacy` auf „Konto löschen“ → `MemberService::requestAccountDeletion()` setzt den Benutzerstatus auf `pending_deletion` und legt den Antrag mit `execute_after = +30 Tage` an.
2. **Prüfung (`process`):** Administrator übernimmt den Antrag (Status `processing`).
3. **Frist:** Bis `execute_after` kann der Antrag noch abgelehnt oder geklärt werden. Die Übersicht warnt 7 Tage vor Fristablauf und markiert überfällige Anträge.
4. **Ausführung (`execute`):** Erst nach Ablauf der Frist möglich („Die Löschfrist ist noch nicht abgelaufen. Früheste Ausführung ab …“). Dann:
   - zuerst wird der Hook `dsgvo_delete_data` (`$userId`, `$email`) ausgelöst, damit Plugins ihre Daten entfernen,
   - Benutzerkonto wird endgültig gelöscht (`UserService::deleteUser(..., true)`), Metadaten per Fremdschlüssel,
   - Antrag erhält Status `completed` und `completed_at`,
   - Aktion wird im Audit-Log protokolliert.
5. **Ablehnung (`reject`):** mit Begründung, z. B. wenn gesetzliche Aufbewahrungspflichten entgegenstehen.
6. **Eskalation (`escalate`):** E-Mail an die Admin-Adresse (Mail-Queue, Header `X-365CMS-Source: legal-data-request-escalation`).

### Was gelöscht wird – und was nicht

| Daten | Verhalten |
|---|---|
| Benutzerkonto | gelöscht |
| `cms_user_meta` (inkl. MFA-Daten), Nachrichten, Favoriten, Benachrichtigungen | per `ON DELETE CASCADE` mitgelöscht |
| Sessions, Passkeys, Gruppen- und Abo-Zuordnungen | ohne Fremdschlüssel – Sessions laufen ab; verwaiste Zuordnungen bei Bedarf über die jeweiligen Admin-Seiten entfernen |
| Beiträge/Seiten des Benutzers | bleiben bestehen (Autor-Zuordnung prüfen, ggf. Anzeigename ändern) |
| Kommentare | bleiben mit gespeichertem Namen/E-Mail bestehen – bei Bedarf manuell unter `/admin/comments` löschen |
| Bestellungen (`cms_orders`) | bleiben wegen handels-/steuerrechtlicher Aufbewahrungspflichten bestehen |
| Logs (Audit, 404, Mail) | werden nach ihren eigenen Aufbewahrungsregeln bereinigt |

Vor der Ausführung prüfen, ob weitere Daten (Kommentare, Plugin-Daten, Uploads unter `uploads/member/user-<id>/`) manuell entfernt werden müssen.

### Verwandte Dokumente

[DSGVO.md](DSGVO.md) · [../users-groups/USERS.md](../users-groups/USERS.md) · [../../member/MEMBER-SECURITY.md](../../member/MEMBER-SECURITY.md)
