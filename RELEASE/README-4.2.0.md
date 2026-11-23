# 365CMS – Projektdokumentation | Abschnitt: Release-Pakete 4.2.0

## English

This release directory documents the distributable `4.2.0` packages (release date 2026-11-23, database schema `v27`). Both packages contain deployable code only. Installation-specific runtime data (`CMS/config.php`, `CMS/config/app.php`, uploads, caches, logs, backups, private data, marketplace feeds) is never part of a package; the full package only creates the runtime folders with their protection files for fresh installations.

4.2.0 is a feature release. Main changes (details in `Changelog.md`, section 4.2.0):

- **Community module** (core module `community`, switchable, public area `/forum`): categories and forums with a permission matrix, topics and posts with Markdown and a visual editor (uploads, server and local drafts, preview), reactions, subscriptions and notifications, reports, bans and a moderation queue, search, sitemap and SEO (canonical URLs, `noindex` rules, structured data), member area and public profile, GDPR export and erasure with retention rules, trash integration, admin pages and a `moderator` role. Replaces the plugin `cms-forum`.
- **Captcha service** (`Security\CaptchaService`, local methods only: proof of work, image, arithmetic) for community forms, high-protection forms and registration.
- Admin: redirects are paged (250 rules per page) and no longer refresh in the background.
- Quality: 66 test suites, query budget per page, accessibility (axe-core) and Lighthouse checks for the community pages.

**Update notes:** run the database update to schema `v27` under *Admin > Updates* after installing the update. Custom roles and the roles `subscriber` and `contributor` do not receive `community.view` and `community.participate` automatically (assign them under *Users > Roles*). If the Entra ID default role pointed to a custom role named `moderator`, the default role is `member` from 4.2.0 on. The community theme integration for `cms-phinit` is delivered with that theme.

Contents of the directory:

- `365CMS-4.2.0-update.zip`: update package (code only, no runtime data).
- `365CMS-4.2.0-full.zip`: full package for fresh installations (with `README`, `Changelog.md` and the runtime folders with protection files).
- `365CMS-4.2.0-update.json`: update manifest with the SHA-256 of the update package.
- `365CMS-4.2.0-SHA256SUMS.txt`: checksums of both ZIP files.

## Deutsch

Dieses Verzeichnis dokumentiert die auslieferbaren Pakete `4.2.0` (Release 23.11.2026, Datenbankschema `v27`). Update- und Vollpaket enthalten ausschließlich auslieferbaren Code. Installationsspezifische Laufzeitdaten (`CMS/config.php`, `CMS/config/app.php`, Uploads, Caches, Logs, Backups, private Daten, Marketplace-Feeds) gehören nie in ein Paket; das Vollpaket legt für Neuinstallationen nur die Laufzeitordner mit ihren Schutzdateien an.

4.2.0 ist ein Funktions-Release. Wichtigste Änderungen (Einzelheiten im `Changelog.md`, Abschnitt 4.2.0):

- **Community-Modul** (Kernmodul `community`, abschaltbar, öffentlicher Bereich `/forum`): Kategorien und Foren mit Rechte-Matrix, Themen und Beiträge mit Markdown und visuellem Editor (Upload, Server- und lokale Entwürfe, Vorschau), Reaktionen, Abos und Benachrichtigungen, Meldungen, Sperren und Freigabe-Warteschlange, Suche, Sitemap und SEO (kanonische Adressen, `noindex`-Regeln, strukturierte Daten), Mitgliederbereich und öffentliches Profil, DSGVO-Auskunft und -Löschung mit Löschfristen, Papierkorb-Anbindung, Admin-Seiten und Rolle `moderator`. Löst das Plugin `cms-forum` ab.
- **Captcha-Dienst** (`Security\CaptchaService`, nur lokale Verfahren: Proof-of-Work, Bild, Rechenaufgabe) für Community-Formulare, Formulare der Antispam-Stufe „hoch“ und die Registrierung.
- Admin: Weiterleitungen zeigen 250 Regeln je Seite und aktualisieren sich nicht mehr im Hintergrund.
- Qualität: 66 Test-Suiten, Abfragebudget je Seite, Prüfungen der Barrierefreiheit (axe-core) und von Lighthouse für die Community-Seiten.

**Hinweise zum Update:** Nach der Installation unter *Admin > Updates* das Datenbank-Update auf Schema `v27` ausführen. Eigene Rollen sowie `subscriber` und `contributor` erhalten `community.view` und `community.participate` nicht automatisch (unter *Benutzer > Rollen* vergeben). Stand die Entra-ID-Standardrolle auf einer eigenen Rolle `moderator`, gilt ab 4.2.0 `member`. Die Theme-Anbindung für `cms-phinit` liefert dieses Theme selbst.

Inhalt des Verzeichnisses:

- `365CMS-4.2.0-update.zip`: Update-Paket (nur Code, keine Laufzeitdaten).
- `365CMS-4.2.0-full.zip`: Vollpaket für Neuinstallationen (mit `README`, `Changelog.md` und den Laufzeitordnern mit Schutzdateien).
- `365CMS-4.2.0-update.json`: Update-Manifest mit SHA-256 des Updatepakets.
- `365CMS-4.2.0-SHA256SUMS.txt`: Prüfsummen beider ZIP-Dateien.
