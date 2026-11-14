# 365CMS – Projektdokumentation | Abschnitt: Spec 4.1.0 – Schema v26 & Migrationsplanung
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.1.0 | **Status:** Planung, Teile umgesetzt (siehe [PROGRESS.md](PROGRESS.md)) | **Übersicht:** [README.md](README.md)

## English (summary)

Single schema bump v25 → v26 for all 4.1.0 features (the Community module follows in 4.2 with v27). Lists every new table and column with
its owning area, the migration mechanics (definitions only in `SchemaManager::getSchemaQueries()`, `MigrationManager`
runs them idempotently, `FEATURE_TABLES` list extended), data back-fills, capability grants as an update step, and
the rules for partial rollout: tables may be created before their feature ships, but no feature may read a column
that the schema does not guarantee.

## Deutsch

### 1. Mechanik (wie v25)

1. **Versionsmarke erst mit dem Release:** `SchemaManager::SCHEMA_VERSION`, `MigrationManager::SCHEMA_VERSION`, `Version::CURRENT` und `schema_version` im Marketplace-Manifest werden in **einem** Schritt der Release-Vorbereitung 4.1.0 auf `v26`/`4.1.0` gehoben (das Manifest beschreibt das veröffentlichte Paket, die Release-Suite prüft die Übereinstimmung). Bis dahin entstehen die v26-Tabellen und -Spalten bei Neuinstallationen über `getSchemaQueries()` und bei bestehenden Installationen über `SchemaManager::ensureFeatureSchema()` (Laufzeitprüfung, läuft nach jeder Änderung von `SchemaManager.php` einmal); die ALTER-Schritte im `MigrationManager` sind bereits eingetragen und greifen ab der Marke v26.
2. Das Community-Modul (Release 4.2) ist **nicht** Teil von v26; es bringt Schema **v27** ([../community/DATA-MODEL.md](../community/DATA-MODEL.md)).
3. Neue Tabellen nur in `SchemaManager::getSchemaQueries()`; Liste `SchemaManager::FEATURE_TABLES_V26` (Abhängigkeitsreihenfolge) wird von Neuinstallation, `MigrationManager::run()` und der Laufzeitprüfung genutzt – eine Definition, drei Wege.
4. Spaltenerweiterungen als `ALTER TABLE … ADD COLUMN` in `MigrationManager::run()`; bekannte Fehler („Duplicate column“, „Duplicate key name“ …) werden wie bisher ignoriert → idempotent.
5. Rechte-Vergabe als **Update-Schritt** (einmalig, Marker `capabilities_v26` in `settings`): neue Capabilities an Standardrollen; nie entziehen.
6. Keine Daten-Löschungen in v26.
7. Prüfung: Neuinstallation und Update von einem v25-Abzug ergeben dieselben Tabellen und Spalten (Test `TESTS/schema-v26/run.php`, vergleicht `getSchemaQueries()` mit den ALTER-Schritten statisch und – falls eine Testdatenbank verfügbar ist – per `INFORMATION_SCHEMA`).

### 2. Neue Tabellen

| Tabelle | Bereich | Zweck | Spec |
|---|---|---|---|
| `password_history` | 1a | letzte *N* Passwort-Hashes | [01-IDENTITY.md](01-IDENTITY.md) §1.3 |
| `user_identities` | 1c | SSO-Bindung `provider`+`tenant_id`+`subject` | 01-IDENTITY §3.8 |
| `media_usage` | 2b | Verwendungsindex | [02-MEDIA.md](02-MEDIA.md) |
| `content_workflow_events`, `content_preview_links` | 3a | Freigabe-Verlauf, Vorschau-Links | [ROADMAP-CONTENT-PLATFORM.md](../ROADMAP-CONTENT-PLATFORM.md) A.3 |
| `reusable_blocks`, `reusable_block_usage`, `reusable_block_revisions` | 3b | wiederverwendbare Blöcke | [03-CONTENT-WORKFLOWS.md](03-CONTENT-WORKFLOWS.md) |
| `backup_offsite_uploads` | 4a | Offsite-Status je Backup | [04-BACKUP-WEBHOOKS.md](04-BACKUP-WEBHOOKS.md) §4 |
| `webhook_endpoints`, `webhook_deliveries` | 4b | ausgehende Webhooks | 04-BACKUP-WEBHOOKS §9 |
| `payment_events`, `invoice_sequences`, `invoices` | 5 | Zahlungsereignisse, Nummernkreis, Rechnungen | [05-PAYMENTS.md](05-PAYMENTS.md) §5 |
| `alert_state` | 6b | Cooldown/Entwarnung | [06-CLI-ALERTING.md](06-CLI-ALERTING.md) |

### 3. Neue Spalten

| Tabelle | Spalten | Bereich |
|---|---|---|
| `users` | `password_changed_at DATETIME NULL` | 1a |
| `passkey_credentials` | `user_verified TINYINT(1) NULL` | 1b |
| `posts`, `pages` | `expires_at`, `review_due_at` (+ Indizes) | 3c |
| `kb_entries` | `expires_at`, `review_due_at` | 3c |
| `orders` | `provider`, `provider_checkout_id`, `paid_at` | 5 |
| `user_subscriptions` | `provider`, `provider_subscription_id`, `current_period_end` | 5 |

Status `pending` für `posts`/`pages` braucht keine Schemaänderung (`status VARCHAR(20)`).

### 4. Daten-Übernahmen beim Update

| Schritt | Wirkung |
|---|---|
| Passwort-Richtlinie | Standardwerte = bisherige Konstanten (12 Zeichen, vier Klassen); Ablauf 0, Historie 5 – kein Konto muss sofort ändern |
| `password_changed_at` | bleibt `NULL`; Ablauf rechnet ab `created_at` (Ablauf ist standardmäßig aus) |
| `passkey_credentials.user_verified` | bleibt `NULL` für Bestandspasskeys; wird bei der nächsten Anmeldung mit UV gesetzt |
| Capabilities | `security.policies`, `security.sso`, `backups.offsite`, `webhooks.manage`, `payments.manage`, `invoices.view`, `alerts.manage` → `admin`; `media.tools`, `blocks.reusable` → `admin`, `editor`; `posts.publish` → `editor`, `author`; `pages.publish` → `editor` |
| Medien-Index | nicht im Update, sondern als Batch-Job „Verwendungsindex aufbauen“ (Admin-Hinweis) |

### 5. Regeln für die schrittweise Auslieferung

- Tabellen und Spalten von v26 dürfen vor ihrer Funktion angelegt werden (ein Schema-Stand für das Release).
- Jede Funktion prüft beim Start nur, was v26 garantiert; zusätzliche Spalten außerhalb dieser Liste sind verboten (sonst neuer Schema-Stand).
- Solange ein Bereich nicht ausgeliefert ist, bleiben seine Admin-Seiten im Menü-Katalog ausgeblendet (Seitendatei fehlt), seine Tabellen leer.
