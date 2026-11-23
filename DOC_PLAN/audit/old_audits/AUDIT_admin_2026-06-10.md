# 365CMS — Security & Code-Audit: `admin/`

**Datum:** 2026-06-10
**Scope:** Verzeichnis `admin/` (276 Dateien, ~94.500 Zeilen): `admin/(root)`-Entry-Files (100), `admin/modules/` (53), `admin/views/` (111 Templates), `admin/partials/` (7), `admin/logs/` (5).
**Methode:** Pattern-Scan über **alle** admin-Dateien (eval/system/`unserialize`/dynamische `include`, SQL-Interpolation mit Request-Vars, `ORDER BY`-Interpolation, reflected XSS via `echo $_GET/$_POST`, CSRF-Abdeckung, Output-Escaping in Views, JSON-in-`<script>`-Breakout, File-Operationen/Path-Traversal, Redirects/Open-Redirect, ZIP-Extraktion/Zip-Slip, Autorisierung/Privilege-Escalation) + Tiefenlesen der Treffer und der kritischen Module/Partials.
**Fixes:** Direkt in der betroffenen Datei angewandt. Backup unter `admin_backup_20260610_171218/`. Verifikation strukturell (Read-Gegenlesen, md5/Backup-Diff, Tag-Balance); `php -l` war in der Sandbox nicht verfügbar.

---

## Gesamtbewertung

`admin/` ist **durchgängig kompetent abgesichert** und auf demselben hohen Niveau wie `core/`. Die geprüften Hochrisiko-Flächen sind sauber:

- **Autorisierung (RBAC):** Entry-Files erzwingen vor jeder sensiblen Aktion `Auth::instance()->isAdmin() && hasCapability(<CAP>) && isAdminPageEnabled(<page>)` und leiten sonst auf `/` um — capability-basiert, nicht nur „eingeloggt".
- **Direktzugriffsschutz:** **Alle 53 Module** tragen den `if (!defined('ABSPATH')) { exit; }`-Guard; Module sind nicht direkt per URL erreichbar, sondern nur über die geschützten Entry-Files.
- **CSRF:** Alle 21 Dateien mit `$_POST` referenzieren CSRF-/Token-Prüfung (`Security::verifyToken`).
- **SQL:** Keine Request-Variablen in Queries, kein interpoliertes `ORDER BY`, keine String-Konkatenation mit User-Input.
- **XSS (reflected):** Keine ungeescapte Ausgabe von `$_GET/$_POST/$_SERVER`. View-Echos sind entweder Integer oder via `htmlspecialchars(ENT_QUOTES)`-Closures (`$escape`/`$fieldValue`) gekapselt.
- **JSON-in-`<script>`:** Durchgängig mit `JSON_HEX_TAG|HEX_AMP|HEX_APOS|HEX_QUOT` gegen `</script>`-Breakout gehärtet (Hub-, Menüs-, Tables-, SEO-Views).
- **File-Operationen:** Backup-Download nutzt `realpath()` + `str_starts_with($backupRoot)` + `is_file/is_readable` (kein Path-Traversal). Media schreibt atomar (Temp + `rename`).
- **ZIP/Theme-Upload (höchste RCE-Fläche):** `validateZipEntries()` blockt `../`, `..\`, Steuerzeichen, `C:/`-Pfade, begrenzt Entry-Anzahl und Gesamt-Dekomprimatgröße (Zip-Bomb) und erzwingt einen Single-Root → kein Zip-Slip.
- **Open Redirect:** Der einzige request-abgeleitete Admin-Redirect (`error-report.php`) verwirft via `parse_url` Scheme/Host vollständig und erzwingt `/admin`-Präfix.
- **Code-Injection:** Kein `eval`/`system`/`unserialize`/dynamisches `include`, keine Settings, die als PHP-Code (`<?php`/`var_export`) in Dateien geschrieben werden.

Es wurden **keine kritischen, hohen oder mittleren** Schwachstellen gefunden. Eine konkrete Defense-in-Depth-Härtung wurde angewandt; zwei Beobachtungen sind als Empfehlung dokumentiert.

---

## 🔴 KRITISCH (sofort beheben)

**Keine.**

---

## 🟡 WARNUNG (zeitnah beheben) — behoben

### [HTML/JS Security] admin/views/partials/featured-image-picker.php:54–78 — JSON-Werte ohne HEX-Flags in `<script>`-Block

**Problem:** Der Picker bettete 13 Werte per `<?= json_encode($var) ?>` in einen `<script>`-Block ein — **ohne** `JSON_HEX_TAG`. Anders als alle anderen Script-JSON-Stellen der Codebasis (Hub/Menüs/Tables/SEO nutzen die vollen HEX-Flags) konnte hier ein Wert mit der Zeichenfolge `</script>` das Script-Element vorzeitig schließen und nachfolgendes Markup einschleusen (Script-Breakout). Die aktuell durchgereichten Werte (Element-IDs, Hex-Token, `'post'/'page'`, Dateinamen-Präfix) sind zwar kontrolliert/statisch — der Schutz ist aber **defense-in-depth** und schließt zukünftige dynamische Werte (z. B. themebare Präfixe) sicher ein.

**Vorher:**
```php
<script>
(function() {
    var modalEl = document.getElementById(<?= json_encode($pickerModalId) ?>);
    var token = <?= json_encode($pickerToken) ?>;
    var pickerFilenamePrefix = <?= json_encode($pickerFilenamePrefix) ?>;
    // … 13 Aufrufe ohne JSON_HEX_TAG
```

**Nachher:** (zentrale, geflaggte Encoder-Closure; alle 13 Aufrufe umgestellt)
```php
<?php
// Defense-in-Depth: JSON-Werte in einem <script>-Block können das </script>-Tag
// mit JSON_HEX_TAG/AMP/APOS/QUOT nicht vorzeitig schließen (kein Script-Breakout).
$jsEnc = static fn (mixed $value): string => (string) json_encode(
    $value,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);
?>
<script>
(function() {
    var modalEl = document.getElementById(<?= $jsEnc($pickerModalId) ?>);
    var token = <?= $jsEnc($pickerToken) ?>;
    var pickerFilenamePrefix = <?= $jsEnc($pickerFilenamePrefix) ?>;
    // … alle 13 Aufrufe nutzen jetzt $jsEnc
```

> Rein additive Härtung, voll rückwärtskompatibel — für die bisherigen Werte ändert sich die Ausgabe nicht.

---

## 🔵 VERBESSERUNG (empfohlen — nicht automatisch geändert)

### [Architektur] admin/partials/post-action-shell.php:19–21 — generische Redirect-Shell vertraut dem Resolver

**Beobachtung:** Die wiederverwendbare Post-Action-Shell bildet `$redirectUrl` aus einem aufrufer-spezifischen `redirect_resolver($_POST, $_SERVER)` und gibt ihn direkt an `header('Location: ' . $redirectUrl)`. Eine eigene Pfad-Normalisierung findet in der Shell **nicht** statt. Der einzige aktuelle Consumer (`error-report.php`) normalisiert korrekt auf interne `/admin`-Pfade — daher **kein** Open Redirect. PHPs `header()` blockt zudem CRLF, sodass auch keine Header-Injection möglich ist.

**Empfehlung:** Die Shell sollte `$redirectUrl` **selbst** defensiv auf einen internen Pfad normalisieren (analog zu `Router::normalizeInternalRedirectPath()` in `core/`), damit ein künftiger Resolver nicht versehentlich einen Open Redirect einführen kann. Nicht automatisch geändert, da es geteiltes Infrastruktur-Verhalten betrifft und aktuell keine Lücke besteht.

### [Code-Qualität] admin/partials/section-page-shell.php:428 — `extract($templateVars, EXTR_SKIP)`

**Beobachtung:** `extract()` wird mit `EXTR_SKIP` auf ein **intern aufgebautes** Template-Variablen-Array angewandt (kein `$_POST`/`$_GET`). `EXTR_SKIP` verhindert das Überschreiben bestehender Variablen. Funktional sicher, aber `extract()` erschwert das statische Nachvollziehen, welche Variablen ein Template sieht. Optionale Verbesserung: explizite Variablenübergabe statt `extract()`.

---

## 📊 Audit-Zusammenfassung

| Kategorie         | Kritisch | Warnung | Verbesserung |
|-------------------|:--------:|:-------:|:------------:|
| PHP Security      |    0     |    0    |      1       |
| PHP Bugs          |    0     |    0    |      0       |
| PHP Performance   |    0     |    0    |      0       |
| HTML / JS         |    0     |    1    |      0       |
| CSS               |    –     |    –    |      –       |
| Code-Qualität     |    0     |    0    |      1       |
| **Gesamt**        |  **0**   |  **1**  |    **2**     |

**Status der Warnung:** **behoben** (direkt in der Datei, Backup vorhanden).
**Geänderte Datei:** `admin/views/partials/featured-image-picker.php`.
**Rollback:** `admin_backup_20260610_171218/` zurückkopieren.

---

## Methodische Einschränkung (Transparenz)

„admin/ komplett" bedeutet hier: Pattern-Scan über **alle** 276 Dateien plus zeilenweises Lesen der Treffer, der sicherheitskritischen Partials/Entry-Files und der relevanten Modul-Funktionen (Auth-Gate, Backup-Download, ZIP-Validierung, Redirect-Resolver, Editor-Config-Encoder). Die größten Module (`MediaModule` 4.116 Z., `PostsModule` 3.188 Z., `SystemInfoModule` 2.870 Z.) wurden gezielt nach den Schwachstellenmustern gescannt und an den Treffern gelesen, nicht vollständig Zeile für Zeile. `php -l`-Linting war mangels PHP-CLI in der Sandbox nicht möglich; die Verifikation erfolgte strukturell.
