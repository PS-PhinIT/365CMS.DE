# 365CMS – Projektdokumentation | Abschnitt: Release-Pakete 4.1.0

## English

This release directory documents the distributable `4.1.0` packages (release date 2026-11-15, database schema `v26`). Both packages contain deployable code only. Installation-specific configuration, uploads, caches, logs and backups are excluded; the full package ships only the protective `.htaccess`/index files of those directories. The update package contains no `CMS/marketplace/`.

4.1.0 is a feature release:
- **Identity:** password policy with history and expiry, role-based MFA enforcement, sign-in with Microsoft Entra ID (OIDC).
- **Media:** AVIF derivatives and a media usage index.
- **Content workflows:** four-eyes review with preview links, reusable Editor.js block templates, expiry and review dates.
- **Integrations:** S3-compatible offsite backups and outgoing signed webhooks.
- **Commerce:** payments via Stripe and Mollie (PayPal through Mollie), and invoices according to § 14 UStG with gap-free numbering, PDF, cancellation invoices and a 10-year retention.
- **Admin menu:** new "Bausteine" group; the classic group "Beiträge" is now called "Inhalte".

CLI and real-time alerting move to 4.3.0. After the core update run **Admin → Updates → database update** (schema `v26`) and keep the cron running at least hourly.

## Deutsch

Dieses Verzeichnis dokumentiert die auslieferbaren Pakete `4.1.0` (Release 15.11.2026, Datenbankschema `v26`). Update- und Vollpaket enthalten ausschließlich auslieferbaren Code. Das Updatepaket enthält kein `CMS/marketplace/`, damit die Feeds eines Marketplace-Hosts erhalten bleiben.

### Inhalt

- **Identität:**
  - Passwort-Richtlinie mit Historie und Ablauf
  - MFA-Zwang je Rolle
  - Anmeldung mit Microsoft Entra ID
- **Medien:**
  - AVIF-Fassungen
  - Verwendungsnachweis
- **Redaktion:**
  - Vier-Augen-Prinzip mit Vorschau-Links
  - EditorJS Block Vorlagen
  - Ablauf- und Prüfdaten mit Wiedervorlage
- **Betrieb:**
  - Offsite-Backups auf S3-kompatible Ziele, Seite *Backup-Einstellungen*
  - ausgehende Webhooks
- **Abos:**
  - Zahlungen über Stripe und Mollie; PayPal läuft über Mollie
  - Rechnungen nach § 14 UStG: lückenloser Nummernkreis, PDF, Storno-Rechnung, DSGVO-Auskunft, 10 Jahre Aufbewahrung
- **Oberfläche:**
  - Feed-Seiten gestaltbar, keine Lesezeit auf Übersichten
  - Menügruppe *Bausteine*
  - Gruppe *Beiträge* heißt im klassischen Profil *Inhalte*

Verschoben auf 4.3.0: zentrale CLI und Echtzeit-Alerting.

### Datenbankschema v26

Neue Tabellen:
- Passwort und Anmeldung: `password_history`, `user_identities`
- Medien: `media_usage`
- Redaktion: `content_workflow_events`, `content_preview_links`
- Vorlagen: `reusable_blocks`, `reusable_block_usage`, `reusable_block_revisions`
- Backups: `backup_offsite_uploads`
- Webhooks: `webhook_endpoints`, `webhook_deliveries`
- Zahlungen und Rechnungen: `payment_events`, `invoice_sequences`, `invoices`

Neue Spalten:
- `users.password_changed_at`, `passkey_credentials.user_verified`
- Ablauf und Prüfung (`expires_at`, `review_due_at` und Meldemarken) in `posts`, `pages` und `kb_entries`
- Zahlungsanbieter in `orders` und `user_subscriptions`

Der Update-Schritt `capabilities_v26` überträgt `posts.publish`/`pages.publish` auf Rollen, die bisher veröffentlichen durften. Die übrigen neuen Rechte (`security.sso`, `blocks.reusable`, `backups.offsite`, `webhooks.manage`, `payments.manage`, `invoices.view`) hat zunächst nur „admin“. Unter *Benutzer → Rollen* lassen sie sich anderen Rollen erteilen.

### Dateien

- `365CMS-4.1.0-update.zip`: Core-Updatepaket für den automatischen Updater (Wurzelordner `CMS/`).
- `365CMS-4.1.0-full.zip`: Gesamtpaket für eine Neuinstallation (Ordner `365CMS-4.1.0/`). Nach dem Entpacken `CMS/install.php` aufrufen.
- `365CMS-4.1.0-update.json`: Update-Manifest mit SHA-256 des Updatepakets.
- `365CMS-4.1.0-SHA256SUMS.txt`: Prüfsummen beider ZIP-Dateien.

### Bereitstellung

1. Das Updatepaket unter `https://365cms.de/marketplace/core/365cms/365CMS-4.1.0-update.zip` bereitstellen.
2. `CMS/marketplace/core/365cms/update.json` an denselben Marketplace-Pfad legen. **Erst danach** wird das Update den Installationen angeboten.
3. Nach dem Core-Update unter **Admin → Updates** das Datenbank-Update ausführen.

Fehlende Tabellen und Spalten legen die Dienste beim ersten Aufruf auch selbst an. Der Core-Swap bewahrt `config/`, `config.php`, `uploads/`, `cache/`, `logs/`, `backups/`, `private/` und `marketplace/`.

### Prüfung

- Alle Test-Suiten in `TESTS/` sind grün, ebenso die Paketprüfung `TESTS/release-3.4.0` (Prüfsummen, Laufzeitpfade, kein `marketplace/` im Updatepaket).
- **Offen (nicht in der Arbeitsumgebung möglich):**
  - Neuinstallation und Update von v25 auf einer echten MySQL/MariaDB-Instanz
  - Zahlungen gegen Stripe- und Mollie-Testkonten
  - Entra-ID-Anmeldung gegen einen echten Mandanten

  Vor dem produktiven Einsatz in einer Staging-Umgebung prüfen.

### Nach dem Update prüfen

- **Zahlungen:** unter *Abos → Zahlungen* zuerst im Testmodus einrichten; die Webhook-URL bei Stripe eintragen.
- **Rechnungen:** unter *Abos → Rechnungen* die Absenderdaten und die Steuernummer bzw. USt-IdNr. prüfen. Die alten Felder *Rechnungspräfix* und *Nächste Rechnungsnummer* unter *Pakete* werden nicht mehr verwendet.
- **Datenschutzerklärung:** den Text unter *Rechtstexte* neu erzeugen. Er enthält jetzt den Hinweis zur Aufbewahrung der Rechnungen.
- **Cron:** mindestens stündlich, damit diese Aufgaben laufen:
  - Webhook-Zustellung
  - Ablauf- und Prüfdaten
  - Nachholen der Offsite-Uploads
