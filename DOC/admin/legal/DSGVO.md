# 365CMS – Projektdokumentation | Abschnitt: Admin – DSGVO-Anfragen (Auskunft & Löschung)

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/data-requests` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_data_requests` | **Core-Modul:** `legal`

## English (summary)

`/admin/data-requests` (`CMS/admin/data-requests.php`, view `CMS/admin/views/legal/data-requests.php`) combines two request types stored in `cms_privacy_requests`:

- **Access/export (Art. 15 GDPR)** – type `export`, module `PrivacyRequestsModule`; actions `process`, `complete`, `reject`, `escalate`, `delete`.
- **Erasure (Art. 17 GDPR)** – type `deletion`, module `DeletionRequestsModule`; actions `process`, `execute`, `reject`, `escalate`, `delete`. See [DELETION-REQUESTS.md](DELETION-REQUESTS.md).

Both use a 30-day deadline with a warning 7 days before it expires. Members can download their own data immediately at `/member/privacy` and file an erasure request there.

## Deutsch

### Herkunft der Anfragen

| Weg | Ergebnis |
|---|---|
| Mitglied → `/member/privacy` → „Daten exportieren“ (`privacy_export`) | Sofortiger JSON-Download (`member-export-<id>.json`) über `MemberService::exportUserData()` – keine Admin-Bearbeitung nötig |
| Mitglied → `/member/privacy` → „Konto löschen“ (`privacy_delete_request`) | Datensatz in `cms_privacy_requests` (`type = deletion`, `execute_after = jetzt + 30 Tage`) |
| Anfrage per E-Mail/Post | Bearbeitung und Dokumentation über diese Seite (Exportanfrage `type = export`) |

### Oberfläche

- Zwei Bereiche (Scope `privacy` und `deletion`) mit Kennzahlen: offen, in Bearbeitung, abgeschlossen, abgelehnt, **überfällig** bzw. **bald fällig** (7 Tage vor Ablauf der 30-Tage-Frist).
- Je Anfrage: Name, E-Mail, verknüpfter Benutzer, Eingang, Frist, Status.

### Status

`pending` → `processing` → `completed` oder `rejected`; gelöschte Einträge werden als `deleted` protokolliert.

### Aktionen – Auskunft (`scope=privacy`)

| Aktion | Wirkung |
|---|---|
| `process` | Status `processing`, Zeitpunkt `processed_at` |
| `complete` | Status `completed`; löst den Hook `dsgvo_export_data` (`$userId`, `$email`) aus, über den Plugins ihre Daten beisteuern können |
| `reject` | Status `rejected` mit Begründung (`reject_reason`) |
| `escalate` | Eskalations-E-Mail an die Admin-Adresse über die Mail-Queue |
| `delete` | Anfrage entfernen |

### Aktionen – Löschung (`scope=deletion`)

Siehe [DELETION-REQUESTS.md](DELETION-REQUESTS.md).

### Tabelle `cms_privacy_requests`

`id`, `type` (`export`/`deletion`), `user_id`, `email`, `name`, `status`, `reject_reason`, `processed_at`, `completed_at`, `execute_after`, Zeitstempel. Die Tabelle wird bei Bedarf von den Modulen bzw. `MemberService` angelegt.

### Für Plugin-Entwickler

```php
\CMS\Hooks::addAction('dsgvo_export_data', static function (int $userId, string $email): void {
    // eigene personenbezogene Daten des Benutzers zusammenstellen und bereitstellen
});
```

### Verwandte Dokumente

[DELETION-REQUESTS.md](DELETION-REQUESTS.md) · [README.md](README.md) · [../../member/MEMBER-SECURITY.md](../../member/MEMBER-SECURITY.md)
