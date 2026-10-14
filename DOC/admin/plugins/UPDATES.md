# 365CMS – Projektdokumentation | Abschnitt: Admin – Plugin-Updates

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/updates` (Bereich *Plugins*) | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_updates`

## English (summary)

Plugin updates are listed and installed on the central updates page (`UpdatesModule::installPluginUpdate()`, action `install_plugin`). `UpdateService::checkPluginUpdates()` compares installed versions with the plugin registry entry (and an optional remote `update_url`). Installation requires an allow-listed download host and a valid SHA-256 checksum. See [../system-settings/UPDATES.md](../system-settings/UPDATES.md).

## Deutsch

### Ablauf

1. `/admin/updates` öffnen, ggf. `check_updates` (Cache 1 Stunde) ausführen.
2. Im Bereich *Plugins* stehen installierte Version, verfügbare Version und Release-Hinweise.
3. Backup erstellen ([../system-settings/BACKUP.md](../system-settings/BACKUP.md)).
4. `install_plugin` für das gewünschte Plugin auslösen.
5. Danach Plugin-Funktionen und Logs prüfen.

### Quellen der Versionsinformation

| Quelle | Zweck |
|---|---|
| Plugin-Header `Version:` in `<slug>.php` | installierte Version |
| Plugin-Registry (`plugin_registry_url`) | Katalogeintrag mit verfügbarer Version, `download_url`, Prüfsumme, Kompatibilität, ggf. Preis |
| `update_url` im Katalogeintrag (optional) | entferntes `update.json`, dessen Werte den Katalogeintrag überschreiben |
| `CMS/plugins/<slug>/update.json` | Paket-Metadaten (`version`, `min_cms_version`, `min_php`, `released`, `download_url`, `checksum_sha256`, `changelog`) für Marketplace-Betreiber; der Update-Check liest die **lokale** Datei nicht |

Fehlt eine Prüfsumme oder liegt die Download-URL nicht auf einem erlaubten Host, ist `install_supported = false`: Die Seite zeigt das Update mit Begründung (bzw. Kauf-Link bei kostenpflichtigen Plugins), installiert es aber nicht automatisch. Das mitgelieferte `cms-importer` hat in seiner `update.json` keine Download-URL und wird mit dem Core aktualisiert.

### Hinweise

- Updates überschreiben das Plugin-Verzeichnis. Eigene Anpassungen gehören in ein separates Plugin oder in Hooks.
- Plugins mit `Requires CMS` höher als die installierte Core-Version lassen sich nach dem Update nicht aktivieren.

### Verwandte Dokumente

[PLUGINS.md](PLUGINS.md) · [MARKETPLACE.md](MARKETPLACE.md) · [../system-settings/UPDATES.md](../system-settings/UPDATES.md)
