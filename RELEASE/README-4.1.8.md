# 365CMS – Projektdokumentation | Abschnitt: Release-Pakete 4.1.8

## English

This release directory documents the distributable `4.1.8` packages (release date 2026-11-15, database schema `v26`, unchanged from 4.1.0). Both packages contain deployable code only. Installation-specific configuration, uploads, caches, logs and backups are excluded; the full package ships only the protective `.htaccess`/index files of those directories. The update package contains no `CMS/marketplace/`.

4.1.8 is a cumulative patch release. It contains the patch releases 4.1.1 to 4.1.8 that came out of the 4.1.0 release audit (`DOC/audit/RELEASE-AUDIT-4.1.0-2026-11-15.md`). Packages exist for 4.1.5 to 4.1.8 only.

| Version | Audit area | Pull requests |
|---------|-----------|---------------|
| 4.1.1 | Security, cryptography, access control | #158 |
| 4.1.2 | Database schema, migrations, upgrade path | #162 |
| 4.1.3 | Features and workflows since 3.5.0 | #163 |
| 4.1.4 | Regressions, legacy code, PHP 8.4/8.5 | #164, #165 |
| 4.1.5 | Performance, caching, Core Web Vitals (interim) | #166 |
| 4.1.6 | Decisions: media, delegable rights, upload, importer | #167 |
| 4.1.7 | Payments: refunds, chargebacks, cancellation at the provider | #168 |
| 4.1.8 | Feed retention minimum, knowledge base import check | this release |

The update is recommended for every 4.1.0 installation (`critical`: security fixes in 4.1.1 and 4.1.4). No manual database update is needed: on the first request after the update, the runtime schema check changes `posts.tags` and `post_revisions.tags` to `TEXT` once.

## Deutsch

Dieses Verzeichnis dokumentiert die auslieferbaren Pakete `4.1.8` (Release 15.11.2026, Datenbankschema `v26`, unverändert seit 4.1.0). Update- und Vollpaket enthalten ausschließlich auslieferbaren Code. Das Updatepaket enthält kein `CMS/marketplace/`, damit die Feeds eines Marketplace-Hosts erhalten bleiben.

### Inhalt

4.1.8 ist ein kumulatives Patch-Release. Es enthält die Patch-Releases 4.1.1 bis 4.1.8 aus der Release-Prüfung 4.1.0. Pakete gibt es nur für 4.1.5 bis 4.1.8.

| Version | Prüfbereich | Pull Requests |
|---------|-------------|---------------|
| 4.1.1 | Sicherheit, Kryptografie, Zugriffskontrolle | #158 |
| 4.1.2 | Datenbank-Schema, Migrationen, Upgrade-Pfad | #162 |
| 4.1.3 | Funktionen und Workflows seit 3.5.0 | #163 |
| 4.1.4 | Regressionen, Altlasten, PHP 8.4/8.5 | #164, #165 |
| 4.1.5 | Performance, Caching, Core Web Vitals (Zwischenstand) | #166 |
| 4.1.6 | Entscheidungen: Medien, delegierbare Rechte, Upload, Importer | #167 |
| 4.1.7 | Zahlungen: Erstattung, Rückbuchung, Kündigung beim Anbieter | #168 |
| 4.1.8 | Feeds: Mindest-Aufbewahrung, Prüfung Wissensdatenbank-Import | dieses Release |

Einzelheiten je Version stehen im `Changelog.md`, Befunde und Nachweise im Audit-Protokoll.

### Hinweise zum Update von 4.1.0

- **Sicherheitskorrekturen** aus 4.1.1 und 4.1.4: Das Update ist als `critical` markiert und wird für alle 4.1.0-Installationen empfohlen.
- **Datenbank:** Das Schema bleibt `v26`. Beim ersten Aufruf nach dem Update stellt der Laufzeit-Abgleich einmalig `posts.tags` und `post_revisions.tags` auf `TEXT` um. Ein manuelles Datenbank-Update ist nicht nötig.
- **Datenaufbewahrung:** neue Regeln für erledigte Mail-Jobs (30 Tage), das Mail-Protokoll (90 Tage) und die Feature-Telemetrie (180 Tage). Der tägliche Cron löscht ältere Bestände beim ersten Lauf blockweise.
- **Caching:**
  - Eigene Assets tragen `?v=`.
  - Bilder unter `uploads/` werden nicht mehr ein Jahr `immutable` ausgeliefert (1 Tag, danach Revalidierung).
  - Anonyme Beitragsseiten sind wieder `public` cachebar.
  - Wer Nginx nutzt, übernimmt die neuen Regeln aus `DOC/INSTALLATION.md`.
- **Rechte ohne Admin-Status:** *Zahlungen*, *Rechnungen*, *EditorJS Block Vorlagen* und *Webhooks* sind über ihre Capability an andere Rollen vergebbar (*Benutzer → Rollen*). Die Rolle *editor* hat `blocks.reusable` seit 4.1.0 und sieht die Block-Vorlagen jetzt. Alle übrigen Admin-Seiten bleiben Admins vorbehalten.
- **Medien:** Dateien, die in Inhalten verwendet werden, lassen sich nicht mehr umbenennen oder verschieben. AVIF-Fassungen neuer Uploads entstehen nach der Antwort (FPM, LiteSpeed).
- **Zahlungen (4.1.7):** Erstattungen und Rückbuchungen beim Anbieter setzen die Bestellung auf `refunded`, erzeugen die Stornorechnung und beenden das Abo. Tarifwechsel und Kündigung durch Mitglieder beenden das Abo beim Anbieter. **Stripe:** im Webhook `charge.refunded` und `charge.dispute.created` ergänzen.
- **Entfernte Schalter ohne Wirkung:** FAQ/HowTo/Review/Event, Pinterest Rich Pins, „Breadcrumbs aktiv“, „Bild-Alt-Texte verpflichtend“ sowie das Feld „Google Analytics / Tag Manager ID“. Die Tracking-IDs werden unter *SEO › Analytics* gepflegt.

### Dateien

- `365CMS-4.1.8-update.zip`: Core-Updatepaket für den automatischen Updater (Wurzelordner `CMS/`).
- `365CMS-4.1.8-full.zip`: Gesamtpaket für eine Neuinstallation (Ordner `365CMS-4.1.8/`). Nach dem Entpacken `CMS/install.php` aufrufen.
- `365CMS-4.1.8-update.json`: Update-Manifest mit SHA-256 des Updatepakets.
- `365CMS-4.1.8-SHA256SUMS.txt`: Prüfsummen beider ZIP-Dateien.

Die Pakete von 4.1.0 und 4.1.5 bis 4.1.7 bleiben zur Nachvollziehbarkeit im Verzeichnis und sollen nicht mehr verteilt werden.

### Bereitstellung

1. Das Updatepaket liegt fertig unter `CMS/marketplace/core/365cms/365CMS-4.1.8-update.zip` (identisch mit `RELEASE/365CMS-4.1.8-update.zip`). Ein Marketplace-Host liefert es unter `https://365cms.de/marketplace/core/365cms/365CMS-4.1.8-update.zip` aus.
2. `CMS/marketplace/core/365cms/update.json` liegt im selben Ordner. Erst wenn beide auf dem Host liegen, wird das Update den Installationen angeboten. Das Paket zuerst hochladen, danach das Manifest.

Der Core-Swap bewahrt `config/`, `config.php`, `uploads/`, `cache/`, `logs/`, `backups/`, `private/` und `marketplace/`.

### Prüfung

- Alle 35 Test-Suiten in `TESTS/` sind grün (mit MariaDB 10.11), ebenso die Paketprüfung `TESTS/release-3.4.0` (Prüfsummen, Laufzeitpfade, kein `marketplace/` im Updatepaket).
- Laufzeitprüfung: alle 166 Admin-, Mitglieder- und Frontend-Routen gegen MariaDB ohne PHP-Meldungen. Dazu kommt eine Messung vor und nach den Performance-Fixes (Audit-Protokoll, Abschnitt PR #5).
- **Offen:**
  - Der abschließende Teil von PR #5 (Gesamt-Review, Folgearbeiten) kommt mit einem späteren Patch-Release.
  - Ebenso PR #6 (Tests, Dokumentation, Entscheidungen zu den offenen Funktionslücken).
  - Wie bei 4.1.0 ist der Test gegen echte Stripe-/Mollie-Testkonten und einen Entra-ID-Mandanten vor dem produktiven Einsatz in einer Staging-Umgebung zu machen.
