# 365CMS – Projektdokumentation | Abschnitt: Spec 4.1.0 – Bereich 4: Offsite-Backups (S3) & ausgehende Webhooks
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.1.0 · Schema v26 | **Status:** Spezifikation, Umsetzung begonnen | **Übersicht:** [README.md](README.md)

## English (summary)

(4a) A small native S3 client (AWS Signature Version 4, path-style or virtual-hosted addressing, single PUT and
multipart upload in parts read from disk, list and delete for retention) on top of `CMS\Http\Client`, used by
`BackupService` to copy encrypted backups to AWS S3, Cloudflare R2, Hetzner Object Storage or MinIO after each backup
run, with retention clean-up and an upload log. Unencrypted backups are never sent offsite. The signer is verified
against the published AWS SigV4 test vectors. (4b) Signed outbound webhooks: endpoints with event filters, HMAC-SHA256
signatures over timestamp and body, delivery through a queue with retries and exponential back-off, SSRF-safe
delivery, delivery log and automatic disabling after repeated failures.

## Deutsch

---

## 4a. S3-kompatible Offsite-Backups

### 1. Ausgangslage

`BackupService::uploadToS3()` existiert, ist aber an keine Oberfläche und keinen Cron angebunden. Grenzen: Einzel-PUT bis
25 MB, ganze Datei im Speicher, nur Virtual-Host-Adressierung (`bucket.endpoint`), keine Liste/kein Löschen, Signatur
nicht getrennt testbar.

### 2. Ziel

- Nach jedem erfolgreichen Backup (manuell, Cron `daily`, CLI `backup:create`) werden die **verschlüsselten** Dateien des Backups auf das konfigurierte Ziel kopiert.
- Anbieter: AWS S3, Cloudflare R2, Hetzner Object Storage, MinIO, weitere S3-kompatible.
- Aufbewahrung offsite: „letzte *N* Backups“ und/oder „nicht älter als *D* Tage“; Bereinigung nach erfolgreichem Upload.
- Prüfen-Knopf: schreibt, liest (HEAD) und löscht ein Testobjekt.
- Status je Backup: hochgeladen (Zeit, Bytes, ETag), fehlgeschlagen (Grund), ausstehend.

### 3. Technik

**`CMS\Services\Backup\S3Client`** (neu, ohne SDK):

| Methode | Zweck |
|---|---|
| `__construct(S3Config $config, ?Client $http = null, ?callable $clock = null)` | Konfiguration, HTTP-Client und Uhr (für Tests) injizierbar |
| `putObject(string $key, string $localFile, string $contentType)` | Einzel-PUT bis 64 MB (Datei in einem Stück, SHA-256 vorab) |
| `uploadFile(string $key, string $localFile, string $contentType)` | wählt PUT oder Multipart (> 64 MB oder Einstellung) |
| `createMultipartUpload` / `uploadPart` / `completeMultipartUpload` / `abortMultipartUpload` | Teile zu 16 MB (einstellbar 5–64 MB), jeweils von der Platte gelesen; bei Fehler Abbruch + `abort` |
| `headObject(string $key)` | Prüfung nach Upload, Prüfen-Knopf |
| `listObjects(string $prefix, ?string $continuationToken)` | `ListObjectsV2`, XML mit `libxml` ohne externe Entitäten |
| `deleteObject(string $key)` | Retention |

**`CMS\Services\Backup\SigV4Signer`** (neu, rein funktional): `sign(method, url, headers, payloadHash, amzDate, region, service='s3')` → Header; kanonische Anfrage nach AWS-Vorgabe (URI-Kodierung je Segment, sortierte Query, Kleinschreibung der Header, getrimmte Werte). Testbar mit den offiziellen **AWS SigV4-Testvektoren** und dem S3-Beispiel aus der AWS-Dokumentation.

**`S3Config`**: `endpoint` (Host ohne Schema, nur HTTPS; `http` nur für MinIO mit ausdrücklicher Freigabe privater Hosts), `region`, `bucket`, `prefix` (z. B. `365cms/site-a/`), `access_key`, `secret_key` (**verschlüsselt** gespeichert), `path_style` (bool; Standard an für MinIO/Hetzner, aus für AWS), `part_size_mb`, `storage_class` (optional), `allow_private_host` (nur für selbst gehostetes MinIO im LAN; schaltet die SSRF-Ausnahme gezielt für diesen Host über `allowedPrivateHosts`).

**`CMS\Services\Backup\OffsiteBackupService`** (neu): verbindet `BackupService` und `S3Client`:
- `uploadBackup(string $backupName)`: nur, wenn das Backup verschlüsselt ist (`BackupService::isEncryptionEnabled()` und Dateien mit `CMSENC2`-Kopf); Objektschlüssel `{prefix}{backupName}/{datei}`; `manifest.json` (Prüfsummen) zuletzt; Ergebnis in `backup_offsite_uploads`.
- `applyRetention()`: listet `{prefix}`, gruppiert nach Backup-Namen, löscht über den Grenzen; löscht **nie** das zuletzt erfolgreich hochgeladene Backup.
- Aufruf über Hook nach Backup-Erstellung (`cms_backup_created`, neu in `BackupService`) und im Cron `daily` (Nachholen fehlgeschlagener Uploads, höchstens 3 Versuche).
- Ereignisse `cms_backup_offsite_uploaded` / `cms_backup_offsite_failed` → Alerting (Bereich 6) und Webhooks (4b).

**Erweiterung `Http\Client`:** öffentliche Methoden `delete()` und `head()`; Antwort-Header vollständig (für `ETag`), keine Änderung am SSRF-Schutz.

**Admin:** Abschnitt „Offsite-Ziel“ auf der Backup-Seite (`/admin/backups`), Capability `backups.offsite`, CSRF je Aktion
(`backup_offsite_save`, `backup_offsite_test`, `backup_offsite_upload_now`), Secret-Feld nur schreibbar.

### 4. Datenmodell (v26)

```sql
CREATE TABLE {p}backup_offsite_uploads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  backup_name VARCHAR(128) NOT NULL,
  target VARCHAR(20) NOT NULL DEFAULT 's3',
  object_prefix VARCHAR(255) NOT NULL,
  status VARCHAR(12) NOT NULL,            -- pending | uploaded | failed | deleted
  bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  files INT UNSIGNED NOT NULL DEFAULT 0,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  uploaded_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_backup_target (backup_name, target),
  KEY idx_status (status, updated_at)
);
```

Einstellungen in Gruppe `backup_offsite` (`secret_key` verschlüsselt).

### 5. Sicherheits-Gates

- [ ] Nur verschlüsselte Backups offsite (V6); Klartext-Backup → Fehler „Verschlüsselung aktivieren“.
- [ ] Secret nur über `CryptoService`; nie in Logs, Fehlermeldungen oder Formularen.
- [ ] Alle Anfragen über `Http\Client` (SSRF, DNS-Pinning); private Hosts nur mit ausdrücklicher Freigabe für genau diesen Host.
- [ ] Lokale Pfade nur innerhalb des Backup-Verzeichnisses (`isWithinBackupRoot`, `Security::safeLocalPath`).
- [ ] XML-Antworten ohne externe Entitäten, Größe begrenzt.
- [ ] Objektschlüssel nur aus validierten Backup-Namen (`BACKUP_NAME_PATTERN`) und Präfix aus `[A-Za-z0-9._/-]`, ohne `..`.

### 6. Tests (`TESTS/s3-backup/run.php`)

SigV4 gegen AWS-Testvektoren (`get-vanilla`, `get-vanilla-query-order-key-case`, `post-x-www-form-urlencoded`, `get-utf8`, `get-space`) und das S3-Beispiel „GET Object“ aus der AWS-Doku; kanonische URI für Schlüssel mit Leer- und Sonderzeichen; Path-Style vs. Virtual-Host; Multipart mit Fake-HTTP (Teilgrößen, ETags, Abschluss-XML, Abbruch bei Fehler); Retention (behält neuestes erfolgreiches Backup); Ablehnung unverschlüsselter Backups; Präfix-Validierung; Gegenprobe.

---

## 4b. Ausgehende Webhooks

### 7. Ziel

Admins legen Empfänger an (URL, Ereignisse, Geheimnis). Ereignisse: `backup.offsite_uploaded`, `backup.offsite_failed`,
`content.published`, `content.submitted`, `user.registered`, `order.paid`, `order.failed`, `subscription.renewed`,
`alert.raised`, `community.report_created` (ab 4.2) – erweiterbar über Filter `cms_webhook_event`.

### 8. Technik

- **`CMS\Services\Webhooks\WebhookService`**: Verwaltung, `dispatch(string $event, array $payload)` → je passendem Empfänger ein Eintrag in `webhook_deliveries` (Warteschlange); keine Zustellung im Request.
- **Zustellung** im Cron `mail-queue`-Takt (minütlich, falls eingerichtet) bzw. `hourly`: `POST` mit JSON, Header `Content-Type: application/json`, `User-Agent: 365CMS-Webhooks/1.0`, `X-365CMS-Event`, `X-365CMS-Delivery` (UUID), `X-365CMS-Timestamp`, `X-365CMS-Signature: v1=<hex HMAC-SHA256(secret, timestamp + "." + body)>` (Schema wie Stripe, Empfänger prüfen Zeitfenster 5 min).
- **Wiederholungen:** 5 Versuche, Abstand 1, 5, 30, 120, 720 min; 2xx = Erfolg; 410 = Empfänger sofort deaktivieren; nach 20 Fehlschlägen in Folge automatisch deaktivieren + Admin-Hinweis.
- **Sicherheit:** Zustellung über `Http\Client` (SSRF-Schutz, keine privaten Ziele außer ausdrücklich erlaubt), Timeout 10 s, Antwort auf 64 KB begrenzt und nicht ausgewertet außer Status; Geheimnis (32 Byte) verschlüsselt gespeichert, nach dem Anlegen einmal angezeigt; Payload ohne Geheimnisse und ohne personenbezogene Daten über das Nötige hinaus (z. B. Benutzer-ID statt E-Mail, einstellbar).
- **Admin:** `/admin/webhooks` (Liste, Anlegen, Test-Ereignis senden, Zustellprotokoll, Geheimnis neu erzeugen), Capability `webhooks.manage`.

### 9. Datenmodell (v26)

```sql
CREATE TABLE {p}webhook_endpoints (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  url VARCHAR(500) NOT NULL,
  events_json LONGTEXT NOT NULL,
  secret_encrypted TEXT NOT NULL,          -- CryptoService, Kontext settings, Bindung webhook:{id}
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
  disabled_reason VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
);
CREATE TABLE {p}webhook_deliveries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  endpoint_id INT UNSIGNED NOT NULL,
  delivery_uuid CHAR(36) NOT NULL,
  event VARCHAR(60) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'pending',   -- pending | delivered | failed | dead
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NULL,
  response_status SMALLINT NULL, last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL, delivered_at DATETIME NULL,
  UNIQUE KEY uq_uuid (delivery_uuid),
  KEY idx_due (status, next_attempt_at), KEY idx_endpoint (endpoint_id, created_at)
);
```

Löschfrist `webhooks.deliveries` (Standard 30 Tage) über `DataRetentionService::registerRule`.

### 10. Tests (`TESTS/webhooks/run.php`)

Signatur (bekannte Werte), Zeitstempel im Header = signierter Zeitstempel; Ereignisfilter; Warteschlange statt
Sofortzustellung; Wiederholungsplan; 410 → deaktiviert; 20 Fehlschläge → deaktiviert; Ziel `http://127.0.0.1` abgelehnt;
Antwortgröße begrenzt; Geheimnis nie im Protokoll; Gegenprobe.
