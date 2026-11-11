# 365CMS – Admin: Downloads

> **Stand:** 2026-11-11 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

Downloads are managed under *Media → Downloads* (`/admin/downloads`, capability `manage_downloads`; `view_download_stats` alone shows the statistics). Files live in private storage and are delivered only through `/download/<slug>[/<version>]`. Posts and pages embed downloads with the Editor.js block “Download” (templates Compact, Card, Featured). Data of the former plugin cms-downloads is migrated automatically.

## Deutsch

### Bereiche

| Bereich | Adresse | Inhalt |
|---|---|---|
| Liste | `/admin/downloads` | Filter (Status, Kategorie, Zugriff, Dateityp), Suche, Sammelaktionen (Status, Kategorie, Zugriff, Papierkorb, Wiederherstellen, endgültig löschen); Spalten mit aktueller Version, Downloads gesamt / 30 Tage und Anzahl der Verwendungen |
| Neuer Download | `?action=new` | mit Datei, externem Link oder zunächst ohne Version |
| Bearbeiten | `?action=edit&id=<id>&section=…` | Abschnitte **Allgemein**, **Versionen**, **Eigenschaften**, **Zugriff**, **Statistik**, **Verwendung**, **SEO** – jeder mit eigenem Formular |
| Statistik | `?tab=stats` | Abrufe 30/90/365 Tage als Balken, Top-Downloads, Quellseiten, CSV-Export (`?export=csv&period=…[&id=…]`) |
| Kategorien | `?tab=categories` | Anlegen, Bearbeiten, Ausblenden, Löschen |
| Einstellungen | `?tab=settings` | Standard-Template, Archivseite, Menüpunkt, erlaubte Dateitypen, Höchstgröße, Speicherbereich, Standard-Zugriff, externe Domains, Grenzwert, Zählregeln, Aufbewahrung, Eigenschaftsfelder |

### Versionen

Jeder Download hat genau eine aktuelle Version (eindeutiger Index). SHA-256 und Größe berechnet der Server beim Hochladen. Eine Version ist entweder eine Datei im privaten Speicher oder ein externer Link. Die aktuelle Version lässt sich nicht löschen; ältere Versionen bleiben über `/download/<slug>/<version>` erreichbar, solange sie existieren.

### Zugriff

`public`, `logged_in`, `roles` (gewählte Rollen) oder `member` (aktive Mitgliedschaft, optional bestimmte Pakete). Besucher ohne Zugriff sehen in Block und Einzelseite ein Schloss und einen Hinweis, nie eine Datei-Adresse; die Auslieferung leitet zur Anmeldung um bzw. antwortet mit 403. Wer `manage_downloads` hat, hat immer Zugriff. Filter: `cms_download_access`.

### Auslieferung und Zählung

- Nur über `/download/<slug>[/<version>]`: realpath-geprüft aus dem privaten Speicher, `Content-Disposition: attachment`, Range (206), `X-Robots-Tag: noindex`. Die frühere Plugin-Adresse `/downloads/file/<slug>` leitet per 301 um.
- Externe Ziele: optional Hinweisseite mit 15 Minuten gültigem, signiertem Link; Domains lassen sich einschränken.
- Gezählt wird ohne Personendaten: keine IP, Bot-Filter, Zusammenfassen wiederholter Abrufe über einen Schlüssel-Hash (nach 24 h gelöscht), Quellseite nur als signierter Typ/ID-Parameter aus dem Block. Rohzeilen werden täglich zu Tageswerten verdichtet; die Aufbewahrungsregel `downloads.events` löscht sie nach der eingestellten Frist (*Löschfristen*).

### Editor.js-Block „Download“

Im Beitrags- und Seiteneditor: Tab **Aus Downloads wählen** (Suche, Kategorie, Dateityp, Zugriff; Mehrfachauswahl nur im Template Kompakt) oder **Hochladen** (legt einen vollständigen Download an; Recht `upload_downloads`). Version „immer aktuell“ oder fest, Button-Text, Template und Sichtbarkeit (Block-Menü). Gespeichert werden nur Referenzen und Anzeigeoptionen. Die Vorschau rendert der Server mit denselben Templates wie das Frontend. Nicht verfügbare Downloads (Entwurf, archiviert, Papierkorb, ohne Version) blendet das Frontend aus; der Editor zeigt einen Hinweis, die Veröffentlichungs-Checkliste meldet sie.

### Templates und Theme-Overrides

| Template | Core-Partial | Theme-Override |
|---|---|---|
| Kompakt | `CMS/views/partials/download/compact.php` | `themes/<slug>/partials/download/compact.php` |
| Karte | `CMS/views/partials/download/card.php` | `themes/<slug>/partials/download/card.php` |
| Hervorgehoben | `CMS/views/partials/download/featured.php` | `themes/<slug>/partials/download/featured.php` |
| Archiv, Einzelseite, Hinweisseite | `CMS/views/downloads/{archive,single,external}.php` | `themes/<slug>/downloads/<name>.php` |

Partials erhalten `$downloads`, `$download` (View-Modell aus `DownloadRenderer::viewModel()`), `$options`, `$template` und `$mode`. Stile: `assets/css/downloads.css` (Design-Tokens des Themes).

### Übernahme aus cms-downloads

`PluginMigration\DownloadsPluginMigrator` übernimmt Kategorien, Downloads (je eine aktuelle Version mit SHA-256, Zähler als Basiswert), den Plugin-Typ als Schlagwort und die Einstellungen – idempotent über `legacy_id`. Öffentliche Dateien bleiben unter `uploads/downloads/`; Dateien von Downloads mit Anmeldepflicht werden in den privaten Speicher verschoben. Danach wird das Plugin deaktiviert und `/admin/plugins/cms-downloads/…` leitet auf `/admin/downloads` um.

### Verwandte Dokumente

[MEDIA.md](MEDIA.md) · [../../core/SERVICES.md](../../core/SERVICES.md) · [../../core/CONTENT-TYPE-PROVIDERS.md](../../core/CONTENT-TYPE-PROVIDERS.md) · [../../INSTALLATION.md](../../INSTALLATION.md)
