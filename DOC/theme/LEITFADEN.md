# 365CMS Projektdokumentation | Theme-Entwicklung: Leitfaden Schritt für Schritt

> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Status:** Stable | **Referenz-Theme:** `cms-default` 1.1.0

## English (summary)

Step-by-step guide for theme developers: what a theme is, where every file lives, when the core loads and renders what (request order), a working minimal theme you can copy, recipes for the everyday tasks (styles and scripts, menus, page templates, forms, error pages, customizer values, member pages), the rules the core enforces (CSP, security scan of `functions.php`, versions) and the typical mistakes with their fix. The reference document for every detail is [THEME-DEVELOPMENT.md](THEME-DEVELOPMENT.md).

## Deutsch

### 1. Was ist ein Theme?

Ein Theme bestimmt, **wie** die öffentliche Website aussieht. Es liefert HTML-Vorlagen, CSS und JavaScript. **Was** angezeigt wird (Seiten, Beiträge, Menüs, Suche), entscheidet der Core: Er lädt die Daten, prüft Rechte und übergibt dem Theme fertige Variablen. Ein Theme fragt keine Datenbank ab und verarbeitet keine Formulare selbst.

Der Admin-Bereich (`/admin`) nutzt **nie** das Theme. Das Theme wirkt nur im Frontend und, falls es eigene Dateien dafür mitbringt, im Mitgliederbereich.

### 2. Wo liegt was?

Alles liegt in **einem** Ordner: `CMS/themes/<slug>/`. Der Ordnername ist der Slug (nur Kleinbuchstaben, Ziffern, Bindestrich).

| Datei / Ordner | Pflicht? | Wofür |
|---|---|---|
| `style.css` | **ja** | Kopfkommentar mit `Theme Name`, `Version`, `Author`, `Description`; danach das CSS |
| `functions.php` **oder** `index.php` | **ja** (eins von beiden) | `functions.php`: Hooks, Helfer, CSS/JS einbinden. `index.php`: Rückfall-Vorlage für jede Seite |
| `index.php` | dringend empfohlen | wird benutzt, wenn eine passende Vorlage fehlt |
| `header.php`, `footer.php` | empfohlen | Seitenrahmen; der Core bindet sie um jede Vorlage herum ein |
| `theme.json` | empfohlen | Name, Slug, Version, Menüpositionen, Seitenvorlagen, Customizer-Bereiche |
| `update.json` | für Updates/Marketplace | Version, `min_cms_version`, Changelog |
| `home.php`, `page.php`, `blog.php`, `blog-single.php`, `category.php`, `tag.php`, `author.php`, `archive.php`, `search.php`, `contact.php`, `404.php`, `error.php` | nein | Vorlagen je Seitentyp (fehlt eine, gilt `index.php`) |
| `partials/` | nein | wiederverwendbare Bausteine, die Ihre Vorlagen selbst einbinden |
| `includes/` | nein | PHP-Helfer, die `functions.php` einbindet |
| `js/`, `css/`, `images/` | nein | eigene Dateien; im Browser erreichbar unter `/themes/<slug>/…` |
| `admin/customizer.php` | nein | eigene Optionen im Theme-Customizer (`/admin/theme-editor`) |
| `member/<seite>.php` | nein | ersetzt eine Seite des Mitgliederbereichs (z. B. `member/dashboard.php`) |

Nicht ins Theme gehören: Zugangsdaten, `.git`, `node_modules`, Build-Werkzeuge.

### 3. Wann passiert was? (Ablauf einer Seitenanfrage)

So läuft ein Aufruf von z. B. `/ueber-uns` ab. Die Hooks in der rechten Spalte sind die Stellen, an denen Ihr Code mitwirken kann.

| Schritt | Was der Core tut | Hooks, die dabei feuern |
|---|---|---|
| 1 | Konfiguration, Datenbank, Sitzung, Sicherheit starten | – |
| 2 | aktive Plugins laden | `plugin_loaded` (je Plugin), `plugins_loaded` |
| 3 | **nur im Frontend:** aktives Theme laden, d. h. `functions.php` wird jetzt eingebunden (vorher Sicherheitsscan, siehe Abschnitt 6) | `theme_loaded` |
| 4 | Core ist bereit | `cms_init`, danach `cms_init_web` |
| 5 | Routen festlegen und die Adresse auflösen | `cms_before_route`, `register_routes` |
| 6 | Daten laden und `ThemeManager::render('<vorlage>', $daten)` aufrufen | Filter `template_name` (Vorlage umleiten) |
| 7 | Rahmen und Vorlage ausgeben (siehe unten) | `before_render`, `before_header`, `head`, `after_header`, `before_footer`, `body_end`, `after_render` |
| 8 | Antwort fertig | `cms_after_route` |

Schritt 7 im Detail:

```text
before_render
  before_header
    header.php          ← muss im <head> Hooks::doAction('head') aufrufen
  after_header
  <vorlage>.php         ← bzw. index.php, wenn die Vorlage fehlt; Daten liegen als Variablen bereit
  footer.php            ← muss vor </body> Hooks::doAction('before_footer') und Hooks::doAction('body_end') aufrufen
after_render
```

Wichtig:

- **Vorlagen rufen den Rahmen nie selbst auf.** Kein `include 'header.php'` in `page.php` – der Core macht das, sonst erscheint der Kopf doppelt.
- `before_footer` und `body_end` laufen je Anfrage **nur einmal**, auch wenn sie mehrfach aufgerufen werden.
- Code in `functions.php` läuft bei **jeder** Frontend-Anfrage. Schwere Arbeit (Dateien schreiben, externe Abrufe) gehört nicht dorthin.

### 4. In 15 Minuten zum eigenen Theme

Der schnellste Weg ist eine Kopie des Standard-Themes. Wer bei null anfangen will, nimmt das Minimal-Theme aus Abschnitt 5.

1. **Kopieren:** `CMS/themes/cms-default/` nach `CMS/themes/mein-theme/`.
2. **Umbenennen:** in `style.css` `Theme Name`, `Version`, `Author`; in `theme.json` `name`, `slug`, `version`; in `update.json` `slug`, `name`, `version`; in `functions.php` die Konstanten und Funktionsnamen mit Präfix `MERIDIAN_`/`meridian_` auf ein eigenes Präfix (sonst kollidieren sie, sobald beide Themes vorhanden sind).
3. **Version an vier Stellen gleich halten:** `style.css`, `theme.json`, `update.json`, Versionskonstante in `functions.php`.
4. **Aktivieren:** `/admin/themes` → *Aktivieren*. Der Core prüft dabei, ob `style.css` und `index.php` oder `functions.php` vorhanden sind und ob alle PHP-Dateien fehlerfrei sind.
5. **Entwickeln:** In `CMS/config/app.php` `CMS_DEBUG` auf `true` setzen. Dann meldet die CSP Verstöße nur in der Browser-Konsole, statt zu blockieren. Vor dem Livegang wieder `false`.
6. **Prüfen:** Startseite, eine Seite, einen Beitrag, Blog, Suche, `/kategorie`, `/tag`, eine falsche Adresse (404) und die englischen Pfade `/en/…` aufrufen. Die vollständige Prüfliste steht in [DEVELOPMENT.md](DEVELOPMENT.md).

### 5. Minimal-Theme zum Kopieren

Diese fünf Dateien ergeben ein vollständiges, CSP-sicheres Theme. Ordner: `CMS/themes/mein-theme/`.

**`style.css`**

```css
/*
Theme Name: Mein Theme
Version: 1.0.0
Author: Meine Firma
Description: Schlankes Theme für 365CMS
*/
:root { --text: #1a1a18; --bg: #ffffff; --accent: #0b5cad; }
body { margin: 0; font-family: system-ui, sans-serif; color: var(--text); background: var(--bg); }
.site { max-width: 960px; margin: 0 auto; padding: 1rem; }
```

**`theme.json`**

```json
{
  "name": "Mein Theme",
  "slug": "mein-theme",
  "version": "1.0.0",
  "author": "Meine Firma",
  "description": "Schlankes Theme für 365CMS",
  "menus": { "primary": "Hauptmenü" }
}
```

**`functions.php`**

```php
<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('MEIN_THEME_VERSION', '1.0.0');

// Stylesheet im <head> ausgeben (läuft im Hook 'head' von header.php).
\CMS\Hooks::addAction('head', static function (): void {
    $url = \CMS\ThemeManager::instance()->getThemeUrl() . '/style.css?v=' . MEIN_THEME_VERSION;
    echo '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . "\n";
}, 10);

// Menüposition im Admin-Menüeditor anbieten.
\CMS\Hooks::addFilter('register_menu_locations', static function (array $locations): array {
    $locations[] = ['slug' => 'primary', 'label' => 'Hauptmenü'];
    return $locations;
});
```

**`header.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}
$siteTitle = defined('SITE_NAME') ? (string) SITE_NAME : '365CMS';
$menu = \CMS\ThemeManager::instance()->getMenu('primary');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <?= function_exists('cms_csp_runtime_tags') ? cms_csp_runtime_tags(true) : '' ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars((string) \CMS\Hooks::applyFilters('page_title', $siteTitle), ENT_QUOTES, 'UTF-8') ?></title>
    <?php \CMS\Hooks::doAction('head'); ?>
</head>
<body>
<div class="site">
<header>
    <a href="/"><?= htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8') ?></a>
    <nav aria-label="Hauptmenü">
        <?php foreach ($menu as $item): ?>
            <a href="<?= htmlspecialchars((string) ($item['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
    </nav>
</header>
<main id="main">
```

**`footer.php`**

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}
?>
</main>
<footer>&copy; <?= date('Y') ?></footer>
</div>
<?php
\CMS\Hooks::doAction('before_footer');
\CMS\Hooks::doAction('body_end');
?>
</body>
</html>
```

**`index.php`** (gilt für jede Seite ohne eigene Vorlage)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}
if (isset($page) && is_array($page)) {
    echo '<h1>' . htmlspecialchars((string) ($page['title'] ?? ''), ENT_QUOTES, 'UTF-8') . '</h1>';
    echo (string) ($page['content'] ?? ''); // bereits bereinigtes HTML vom Core
} elseif (isset($post) && is_object($post)) {
    echo '<h1>' . htmlspecialchars((string) ($post->title ?? ''), ENT_QUOTES, 'UTF-8') . '</h1>';
    echo (string) ($post->content ?? '');
} else {
    echo '<h1>Willkommen</h1>';
}
```

Danach unter `/admin/themes` aktivieren. Weitere Vorlagen (`page.php`, `blog.php` …) legen Sie an, sobald ein Seitentyp anders aussehen soll.

### 6. Regeln, die der Core durchsetzt

Wer diese Regeln kennt, spart sich die häufigsten Fehlersuchen.

**Sicherheitsscan von `functions.php`.** Vor dem Laden durchsucht der Core `functions.php` nach den Bezeichnern `eval`, `exec`, `system`, `shell_exec`, `passthru`, `proc_open`, `popen` und `base64_decode`. Geprüft wird **jeder Bezeichner**, also auch Methodennamen: `$pdo->exec()` oder eine eigene Methode `system()` lösen den Scan ebenso aus. Namen, die die Wörter nur enthalten (`system_info`, `exec_count`), sind erlaubt. Ein Treffer oder ein Syntaxfehler führt dazu, dass der Core auf das Standard-Theme zurückschaltet und den Grund protokolliert. Ein Laufzeitfehler beim Einbinden hat dieselbe Folge.

**Content Security Policy (CSP).** Im Betrieb blockiert der Browser:

- Inline-`<script>` und `<style>` **ohne** Nonce. Mit Nonce: `<script <?= \CMS\Security::instance()->nonceAttr() ?>>…</script>`.
- Ereignis-Attribute wie `onclick="…"`, `onload="…"`, `onsubmit="…"`.
- Links mit `javascript:`, `eval()` und `new Function()`.
- Skripte und Schriften fremder Domains, solange sie nicht freigegeben sind (Hook `cms_csp_prepare` mit `Security::allowCspSources()`) und – bei Diensten wie YouTube oder Karten – die Einwilligung fehlt.

`style="…"`-Attribute schreibt der Core automatisch in Klassen mit Nonce um; sie funktionieren also, sind aber nicht schön. JavaScript gehört in eigene Dateien unter `js/` und wird über `data-…`-Attribute an das HTML gebunden.

**Ausgaben maskieren.** Jeder Wert, der nicht vom Core als HTML geliefert wird, geht durch `htmlspecialchars($wert, ENT_QUOTES, 'UTF-8')` (oder `esc_html()`, `esc_attr()`, `esc_url()`). Bereits bereinigt sind nur `$page['content']` und der Inhalt von `$post`.

**Formulare.** Jedes öffentliche POST-Formular braucht ein CSRF-Token, sonst lehnt der Router die Anfrage ab (403):

```php
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\CMS\Security::instance()->generateToken('form_guard'), ENT_QUOTES, 'UTF-8') ?>">
```

Abmelden geht nur per POST mit einem Token der Aktion `logout` (Vorbild: `header.php` von `cms-default`).

**Datenschutz.** Bei aktiver Einstellung „Lokale Schriften“ keine Google-Fonts laden. Ein eigenes Cookie-Banner nur zeigen, wenn `CookieConsentService::getInstance()->isManagedExternally()` `false` ist.

**Versionen.** `style.css`, `theme.json`, `update.json` und die Versionskonstante in `functions.php` tragen dieselbe Version. Die automatischen Prüfungen des Repositories prüfen das für jedes Theme unter `CMS/themes/`.

### 7. Rezepte für typische Aufgaben

| Aufgabe | Datei | So geht's |
|---|---|---|
| CSS einbinden | `functions.php` | im Hook `head` ein `<link rel="stylesheet">` ausgeben (Beispiel in Abschnitt 5) |
| JavaScript einbinden | `functions.php` | im Hook `before_footer`: `echo '<script src="' . $url . '" defer></script>';` mit `$url = ThemeManager::instance()->getThemeUrl() . '/js/theme.js?v=…'` |
| Menüposition anbieten | `functions.php` | Filter `register_menu_locations` (Abschnitt 5); im Admin unter `/admin/menu-editor` füllen |
| Menü ausgeben | `header.php` | `ThemeManager::instance()->getMenu('primary')` liefert Einträge mit `title`, `url`, `target`, `icon`, `parent_id` |
| Seiteninhalt ausgeben | `page.php` | `$page['title']` maskieren, `$page['content']` unverändert ausgeben |
| Beitragsliste | `blog.php` | `$posts` (Objekte), Blättern mit `$currentPage`, `$totalPages` (`?p=N`) |
| Einzelbeitrag | `blog-single.php` | `$post` (Objekt); Links mit `\CMS\Services\PermalinkService::getInstance()->buildPostUrl($post)` |
| Seitenvorlage zur Auswahl im Editor | `theme.json` + Datei | Eintrag in `page_templates` mit `id`, `label`, `file`, optional `meta_fields`; die Felder stehen dann in `$page['meta']` (Details: [THEME-DEVELOPMENT.md §2](THEME-DEVELOPMENT.md)) |
| Fehlerseite | `404.php`, `error.php` | `error.php` bekommt `$error_code`, `$error_title`, `$error_message`; für einen Status eigene Datei möglich (`403.php`) |
| Suchseite | `search.php` | `$results`, `$query`, `$type`, `$sort`, `$total`; das Formular sendet `q`, `type`, `sort` |
| Customizer-Wert lesen | beliebig | `\CMS\Services\ThemeCustomizer::instance()->get('bereich', 'schluessel', 'standard')`, als CSS-Variable ausgeben |
| Seitentitel ändern | `functions.php` | Filter `page_title` |
| SEO-Angaben | `functions.php` | im Hook `head` `\CMS\Services\SEOService::getInstance()->renderCurrentHeadTags()` ausgeben (so macht es `cms-default`) |
| Mitgliederseite anpassen | `member/<seite>.php` | die Datei ersetzt die Core-Seite; `member/dashboard.php` schaltet zusätzlich `/dashboard` frei |
| Inline-Skript (nur wenn unvermeidbar) | Vorlage | `<script <?= \CMS\Security::instance()->nonceAttr() ?>>…</script>` |

Welche Variablen jede Vorlage bekommt, steht vollständig in [THEME-DEVELOPMENT.md §3](THEME-DEVELOPMENT.md). Alle Hooks mit Argumenten: [../core/HOOKS-REFERENCE.md](../core/HOOKS-REFERENCE.md).

### 8. Häufige Fehler und ihre Lösung

| Symptom | Ursache | Lösung |
|---|---|---|
| Nach dem Aktivieren erscheint wieder das Standard-Theme | Sicherheitsscan oder Fehler in `functions.php` (Abschnitt 6) | Protokoll unter `/admin/cms-logs`, Kanal `theme`; verbotenen Bezeichner umbenennen bzw. Fehler beheben |
| Kopf oder Fuß erscheinen doppelt | Vorlage bindet `header.php`/`footer.php` selbst ein | Einbindung aus der Vorlage entfernen |
| Skript läuft nicht, Konsole meldet CSP | Inline-Skript ohne Nonce oder `onclick=` | Code in eine Datei unter `js/` verschieben oder Nonce setzen |
| Cookie-Banner, SEO-Tags oder Statistik fehlen | `head` oder `body_end` wird nicht ausgelöst | `Hooks::doAction('head')` im `<head>`, `before_footer` und `body_end` vor `</body>` |
| Formular liefert 403 | CSRF-Token fehlt | verstecktes Feld `csrf_token` mit Token der Aktion `form_guard` |
| Änderung an PHP-Datei wirkt nicht | OPcache | `/admin/performance-cache` → OPcache leeren |
| Bildregeln wie `figure > img` greifen nicht | Bilder stehen in `<picture>` | Nachfahren-Selektor `figure img` verwenden |
| Update zeigt falsche Version | Versionen in den vier Dateien unterschiedlich | alle vier angleichen |

### 9. Veröffentlichen

1. Prüfliste in [DEVELOPMENT.md](DEVELOPMENT.md) abarbeiten.
2. ZIP mit dem Theme-Ordner auf oberster Ebene erstellen (`mein-theme/style.css` …), ohne `.git` und Build-Dateien.
3. SHA-256 berechnen (`sha256sum mein-theme-1.0.0.zip`) und in `update.json` bzw. den Theme-Katalog eintragen ([../admin/themes-design/MARKETPLACE.md](../admin/themes-design/MARKETPLACE.md)).
4. Customizer-Werte bleiben bei Updates erhalten (Datenbank); Dateiänderungen auf dem Server gehen beim Update verloren. Anpassungen gehören ins Repository des Themes.

### Verwandte Dokumente

[README.md](README.md) · [THEME-DEVELOPMENT.md](THEME-DEVELOPMENT.md) (Referenz) · [DEVELOPMENT.md](DEVELOPMENT.md) (Ablauf und Prüfliste) · [COMPONENTS.md](COMPONENTS.md) · [DESIGN-SYSTEM.md](DESIGN-SYSTEM.md) · [JAVASCRIPT.md](JAVASCRIPT.md) · [../core/HOOKS-REFERENCE.md](../core/HOOKS-REFERENCE.md)
