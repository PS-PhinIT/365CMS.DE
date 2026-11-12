# 365CMS – Admin: Import/Export von Inhalten

> **Stand:** 2026-11-12 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

*System → Tools → Import/Export* (`/admin/content-exchange`, capability `import_export`) exports categories, tags,
media, forms, pages, posts and hub sites as a ZIP archive and imports such an archive into this installation. The
export runs in steps; the archive is kept for 48 hours in private storage. The import validates the upload (structure,
file names, size, schema version, SHA-256 checksums), shows a preview with new, existing and invalid entries and then
imports with a conflict strategy (skip, overwrite, create additionally), optional draft status and author mapping.
There is no target site: content is imported into the installation.

## Deutsch

### Exportieren

1. Inhalte wählen (nur Typen, für die das eigene Recht reicht), Status von Seiten/Beiträgen, optional Zeitraum der
   letzten Änderung und ob Mediendateien mitkommen.
2. *Export starten*: Der Export läuft in Schritten mit Fortschrittsanzeige und lässt sich abbrechen.
3. *ZIP herunterladen*. Das Archiv liegt 48 Stunden im privaten Speicher (`exchange/exports/`) und wird danach vom
   täglichen Cron gelöscht.

Inhalte im Papierkorb werden nie exportiert.

### Archivformat

```
manifest.json            Format 365cms-content-exchange v1, CMS- und Schema-Version, Quell-Adresse,
                         Upload-Adresse, Typen mit Anzahl, Autoren, SHA-256 aller Dateien
data/<typ>.json          Datensätze je Typ
files/media/<pfad>       Mediendateien (ohne Vorschaubilder; sie werden beim Import neu erzeugt)
```

### Importieren

1. ZIP hochladen (Grenze 256 MB, entpackt höchstens 2 GB und 50 000 Einträge). Geprüft werden Dateinamen (kein
   Verlassen des Zielordners), Manifest, Schema-Version (Archive aus neueren Versionen werden abgelehnt) und
   Prüfsummen.
2. Vorschau: je Typ neu, vorhanden und ungültig, dazu Beispiele für Konflikte.
3. Optionen:
   - **Bei vorhandenen Einträgen:** überspringen, überschreiben oder zusätzlich anlegen (neue Adresse `-2` bzw.
     Dateiname `-2`).
   - **Als Entwurf importieren** (Seiten und Beiträge, Standard an).
   - **Autoren zuordnen:** Autoren aus dem Archiv einem Benutzer zuordnen, Standard ist der importierende Benutzer.
4. *Import starten*: Reihenfolge Kategorien → Schlagwörter → Medien → Formulare → Seiten/Beiträge → Hub-Sites; je
   Schritt 25 Datensätze. Danach zeigt die Seite das Ergebnis je Typ und alle Meldungen.

Konfliktschlüssel: Slug (Kategorien, Schlagwörter, Seiten, Beiträge), Adresse (`table_slug`, Hub-Sites), Pfad
(Medien). Kategorien- und Medienverweise werden auf die neuen IDs bzw. Adressen umgeschrieben, Editor.js-Inhalte beim
Import erneut bereinigt. Domains von Hub-Sites werden nicht übernommen und im Ergebnis genannt.

### Sicherheit

- Seite und JSON-Endpunkt `/admin/content-exchange-api` verlangen `import_export` und zusätzlich die Capability jedes
  Inhaltstyps; Aktionen sind per Token geschützt.
- Hochgeladene Archive werden in einem privaten Staging-Ordner entpackt und nach Import oder Verwerfen gelöscht;
  übrig gebliebene Ordner entfernt der tägliche Cron.
- Medien: nur erlaubte Dateitypen; Pfade werden per `realpath` auf den Staging-Ordner begrenzt.
- Export und Import werden im Audit-Log protokolliert.

### Erweiterung

Weitere Inhaltstypen schließen sich über einen `ExchangeProvider` an
([CONTENT-TYPE-PROVIDERS.md](../../core/CONTENT-TYPE-PROVIDERS.md)).

### Dateien

`CMS/admin/content-exchange.php`, `CMS/admin/content-exchange-api.php`, `CMS/admin/views/system/content-exchange.php`,
`CMS/assets/js/admin-content-exchange.js`, `CMS/core/Services/Exchange/*`,
`CMS/core/Services/FeatureHooks/ContentExchangeHooks.php`
