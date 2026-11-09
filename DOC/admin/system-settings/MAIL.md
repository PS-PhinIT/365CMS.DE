# 365CMS – Projektdokumentation | Abschnitt: Admin – Mail & Azure OAuth2

> **Stand:** 2026-11-09 | **Version:** 4.0.0 | **Status:** Stable
> **Route:** `/admin/mail-settings` (Tabs `transport`, `azure`, `graph`, `logs`, `queue`) | **Capability:** lesen `manage_settings` oder `manage_system`, schreiben `manage_settings` | **CSRF-Aktion:** `admin_mail_settings`

## English (summary)

Outgoing mail is configured at `/admin/mail-settings` (`CMS/admin/mail-settings.php` → `CMS/admin/modules/system/MailSettingsModule.php` → `CMS/admin/views/system/mail-settings.php`). Transport is PHP `mail()` or SMTP (Symfony Mailer under `CMS/assets/mailer/`), optionally with Microsoft 365 **Azure OAuth2 / XOAUTH2** (`AzureMailTokenProvider`). A separate Microsoft Graph app registration (`GraphApiService`) provides tokens for Graph-based features and plugins. Mail can be queued (`cms_mail_queue`, `MailQueueService`) and is processed by `cron.php`. All settings live in the `mail` settings group; secrets are stored with authenticated encryption (`SettingsService` → `CryptoService`, prefix `enc:v2:`, since 4.0.0; older `enc:` values are re-encrypted automatically). Since 4.0.0 `cms_mail()` honours a `Content-Type: text/plain` header, and the PHP `mail()` path encodes non-ASCII subjects and sender names (RFC 2047).

## Deutsch

### Tabs und Aktionen

| Tab | Aktionen |
|---|---|
| `transport` | `save_transport`, `send_test_email` |
| `azure` | `save_azure`, `clear_azure_cache` |
| `graph` | `save_graph`, `test_graph_connection`, `clear_graph_cache` |
| `logs` | `clear_logs` |
| `queue` | `save_queue`, `run_queue_now`, `release_queue_stale`, `enqueue_queue_test` |

### Transport

| Feld | Werte |
|---|---|
| Treiber (`driver`) | `mail` (PHP-`mail()`) oder `smtp` |
| SMTP-Host / Port / Verschlüsselung | z. B. `smtp.office365.com`, 587, `tls` (auch `ssl` oder leer) |
| Authentifizierung (`auth_mode`) | `credentials` (Benutzername + Passwort) oder `oauth2` (Azure OAuth2 / XOAUTH2) |
| Benutzername / Passwort | Passwort verschlüsselt gespeichert (seit 4.0.0 authentifiziert über `CryptoService`, siehe [../../core/SECURITY.md](../../core/SECURITY.md)); „Passwort löschen“ entfernt es |
| Absender (`from_email`, `from_name`) | Fallback `SMTP_FROM_EMAIL` / `SMTP_FROM_NAME` aus `config/app.php` |

`send_test_email` verschickt eine Testmail an die angegebene Adresse und zeigt den verwendeten Transport an.

Ohne gespeicherte Einstellungen gilt: ist `SMTP_HOST` in `config/app.php` gesetzt, wird SMTP verwendet, sonst `mail()`.

### Azure OAuth2 (SMTP mit Microsoft 365)

| Feld | Hinweis |
|---|---|
| Tenant-ID, Client-ID, Client-Secret | App-Registrierung in Entra ID; Secret verschlüsselt |
| Postfach (`azure_mailbox`) | Absenderpostfach für XOAUTH2 |
| Scope (`azure_scope`) | Standard `https://outlook.office365.com/.default` |
| Token-Endpunkt | optional, Standard `login.microsoftonline.com/<tenant>/oauth2/v2.0/token` |

Die App benötigt die Anwendungsberechtigung `SMTP.SendAsApp`, das Postfach muss für SMTP AUTH freigegeben und dem Dienstprinzipal zugeordnet sein. `clear_azure_cache` verwirft das zwischengespeicherte Token.

### Microsoft Graph

Eigene App-Registrierung (Client-Credentials) für Graph-Zugriffe, z. B. durch M365-Plugins: Tenant-ID, Client-ID, Secret, Scope (Standard `https://graph.microsoft.com/.default`), Basis-URL, Token-Endpunkt. `test_graph_connection` holt ein Token und meldet Erfolg/Fehler; `clear_graph_cache` leert den Token-Cache. API-Test auch per `POST /api/v1/admin/graph/test`.

### Mail-Log

`cms_mail_log` protokolliert jeden Versand (Empfänger, Betreff, Status, Fehler, Transport, Metadaten). `clear_logs` leert das Protokoll; einsehbar auch über `GET /api/v1/admin/mail/logs`.

### Mail-Queue

| Option | Standard | Bereich |
|---|---|---|
| `queue_enabled` | aus | – |
| `queue_batch_size` | 10 | 1–100 |
| `queue_max_attempts` | 5 | 1–20 |
| `queue_retry_delay_seconds` | 300 | 60–86400 |
| `queue_throttle_delay_seconds` | 900 | 60–86400 (bei Drosselung durch den Provider) |
| `queue_lock_timeout_seconds` | 900 | 60–86400 |
| `queue_rate_limit_per_minute` | 8 | 1–600 |

- **Verarbeitung:** `cron.php --task=mail-queue` (CLI) oder Web-Cron mit Token. Die Seite zeigt die fertige CLI-Zeile und die Web-Cron-URL; als Token-Header wird `X-CMS-Cron-Token` empfohlen. Die Option `regenerate_queue_cron_token` erzeugt beim Speichern (`save_queue`) einen neuen Token.
- `run_queue_now` verarbeitet fällige Jobs sofort, `release_queue_stale` gibt hängende Jobs (älter als Lock-Timeout) frei, `enqueue_queue_test` legt eine Test-Mail in die Queue.
- Fehlgeschlagene Jobs werden mit Fehlerkategorie und empfohlener Wartezeit erneut eingeplant, bis `queue_max_attempts` erreicht ist.

### Für Entwickler

```php
$mail = \CMS\Services\MailService::getInstance();
$result = $mail->sendDetailed('kunde@example.com', 'Betreff', '<p>HTML-Inhalt</p>');
// $result['success'], $result['error'], $result['transport']
```

Header-Injection wird verhindert (gesperrte Headernamen, Längenbegrenzung Betreff 255 / Body 200 000 Zeichen).

Der Helfer `cms_mail($to, $subject, $body, $headers)` versendet HTML (bzw. reiht es bei aktiver Queue ein). Seit 4.0.0 gilt ein Header `Content-Type: text/plain` als Wunsch nach reinem Text: Der Body wird dann über `sendPlain()` bzw. `enqueuePlain()` als reiner Text versendet, statt als HTML interpretiert zu werden. Beim Versand über PHP-`mail()` (Treiber `mail` oder Fallback ohne Symfony Mailer) werden Betreff und Absendername mit Umlauten seit 4.0.0 nach RFC 2047 kodiert.

### Verwandte Dokumente

[SYSTEM.md](SYSTEM.md) · [MONITORING.md](MONITORING.md) · [../../assets/mailer/README.md](../../assets/mailer/README.md)
