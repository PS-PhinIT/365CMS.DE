# 365CMS – Projektdokumentation | Abschnitt: Admin – Anti-Spam

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/antispam` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_antispam` | **Core-Modul:** `security`

## English (summary)

Central spam protection for comments, contact forms and plugin forms. Admin page: `CMS/admin/antispam.php` → `CMS/admin/modules/security/AntispamModule.php` → `CMS/admin/views/security/antispam.php`. Runtime check: `CMS\Services\AntispamService::evaluate()`. Settings: `antispam_enabled`, `antispam_honeypot`, `antispam_min_time`, `antispam_max_links`, `antispam_block_empty_ua`. Blacklist table `cms_spam_blacklist` (types `word`, `email`, `ip`, `domain`). Actions: `save_settings`, `add_blacklist`, `delete_blacklist`.

## Deutsch

### Prüfreihenfolge (`AntispamService::evaluate()`)

Nur wenn `antispam_enabled = 1`:

| # | Prüfung | Einstellung | Meldung an den Besucher |
|---|---|---|---|
| 1 | Honeypot-Feld ausgefüllt | `antispam_honeypot` | „Spam erkannt.“ |
| 2 | Formular schneller abgeschickt als erlaubt | `antispam_min_time` (0–60 s) | „Bitte warten Sie einen Moment …“ |
| 3 | Leerer User-Agent | `antispam_block_empty_ua` | „Die Anfrage wurde aus Sicherheitsgründen blockiert.“ |
| 4 | Zu viele Links im Inhalt | `antispam_max_links` (0–50, 0 = aus) | „Zu viele Links in der Anfrage.“ |
| 5 | Treffer in der Blacklist (E-Mail, IP, Domain, Wort in Name/Inhalt) | Blacklist | „Die Anfrage wurde aus Sicherheitsgründen blockiert.“ |

Jede Ablehnung wird mit Grund (`honeypot`, `minimum_time`, `empty_user_agent`, `max_links`, `blacklist`) protokolliert und ist im Sicherheits-Log sichtbar.

### Blacklist

| Typ | Validierung | Beispiel |
|---|---|---|
| `word` | Freitext | `casino` |
| `email` | gültige E-Mail | `spam@example.com` |
| `ip` | gültige IPv4/IPv6 | `203.0.113.7` |
| `domain` | gültiger Domainname | `spam-domain.tld` |

Doppelte Einträge (gleicher Typ und Wert) werden erkannt; Tabelle `cms_spam_blacklist` (eindeutiger Schlüssel `type`, `value`).

### Einbindung in eigene Formulare

```php
$result = \CMS\Services\AntispamService::getInstance()->evaluate([
    'honeypot_value' => $_POST['website'] ?? '',      // verstecktes Feld
    'started_at'     => (int) ($_POST['form_ts'] ?? 0), // Zeitstempel beim Rendern
    'content'        => $_POST['message'] ?? '',
    'email'          => $_POST['email'] ?? '',
    'author_name'    => $_POST['name'] ?? '',
    'ip_address'     => \CMS\Security::getClientIp(),
]);
if ($result['rejected']) {
    // $result['message'] anzeigen, nicht speichern
}
```

Das Sicherheits-Audit ([SECURITY-AUDIT.md](SECURITY-AUDIT.md)) prüft, ob aktive Kontaktformular-Plugins diese Auswertung inklusive Zeitstempel nutzen.

### Verwandte Dokumente

[FIREWALL.md](FIREWALL.md) · [SECURITY-AUDIT.md](SECURITY-AUDIT.md) · [../pages-posts/COMMENTS.md](../pages-posts/COMMENTS.md)
