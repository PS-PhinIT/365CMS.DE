# 365CMS – Projektdokumentation | Abschnitt: Plugins – Quick Start
> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable | **Update:** 2026-10-02
> **Kurzbeschreibung:** Beginner-friendly and technical quick start for creating, loading, activating, and maintaining a 365CMS plugin in the current runtime.

## English

### User-friendly guide

This guide shows the complete path from an empty plugin folder to a plugin that can be activated in 365CMS. The active runtime is `CMS/plugins/`; the separate `365CMS.DE-PLUGINS` repository is a source and maintenance workspace and is not loaded automatically.

#### 1. Create the runtime folder

Create a slug-named folder and a bootstrap file:

```text
CMS/plugins/
└── hello-world/
    ├── hello-world.php
    ├── includes/
    │   └── class-hello-world.php
    ├── admin/
    ├── templates/
    └── assets/
        ├── css/hello-world.css
        └── js/hello-world.js
```

The folder slug and bootstrap filename must match. The Plugin Manager reads the plugin header from the bootstrap file.

#### 2. Add the bootstrap file

```php
<?php
/**
 * Plugin Name: Hello World
 * Description: Small 365CMS example plugin.
 * Version: 1.0.0
 * Author: Example Author
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('HELLO_WORLD_VERSION', '1.0.0');
define('HELLO_WORLD_PATH', PLUGIN_PATH . 'hello-world/');

require_once HELLO_WORLD_PATH . 'includes/class-hello-world.php';

HelloWorld::instance();
```

#### 3. Add the plugin class and hooks

```php
<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class HelloWorld
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        \CMS\Hooks::addAction('cms_init', [$this, 'boot'], 10);
        \CMS\Hooks::addAction('cms_admin_menu', [$this, 'registerAdminMenu'], 10);
    }

    public function boot(): void
    {
        \CMS\Hooks::addAction('after_header', [$this, 'renderBanner'], 10);
    }

    public function renderBanner(): void
    {
        echo '<div class="cms-alert cms-alert-info">Hello World is active.</div>';
    }

    public function registerAdminMenu(): void
    {
        add_menu_page(
            'Hello World',
            'Hello World',
            'manage_settings',
            'hello-world',
            [$this, 'renderAdminPage']
        );
    }

    public function renderAdminPage(): void
    {
        echo '<div class="container-xl"><h1>Hello World</h1></div>';
    }
}
```

Use the core `add_menu_page()` and `add_submenu_page()` helpers. The capability is checked while the menu is registered and must also be checked before every state-changing operation.

#### 4. Activate and verify the plugin

1. Open `/admin/plugins`.
2. Find the plugin by its header name.
3. Activate it with the status switch.
4. Check the frontend hook and the plugin admin page.
5. Review the audit or operational log if loading fails.

The page displays total, active, and inactive counts. `cms-importer` is protected and cannot be removed through the normal plugin delete action.

#### 5. Add data and UI carefully

Use `CMS\Database` for tables and prepared statements for values. Keep templates under the plugin directory, escape all output, protect every POST with a CSRF token, and prefix CSS classes with the plugin slug. Add a member menu or dashboard widget only through the documented member hooks.

### Technical reference

#### Runtime and lifecycle

`PLUGIN_PATH` points to `ABSPATH . 'plugins/'`. `CMS\PluginManager` scans that directory, accepts valid plugin slugs, reads `Plugin Name`, `Description`, `Version`, `Author`, `Requires`, `Requires Plugins`, and `Requires CMS` headers, and persists active slugs in the `active_plugins` setting.

At bootstrap, active plugin files are required once and `plugin_loaded` is emitted per plugin. After all load attempts, `plugins_loaded` is emitted. A missing bootstrap file or load exception is logged, audited, removed from the active list, and does not stop the remaining plugins from loading.

#### Admin routes

- `/admin/plugins` lists installed plugins and accepts the actions `activate`, `deactivate`, and `delete`.
- `/admin/plugin-marketplace` lists catalog plugins and accepts the action `install`.
- `/admin/plugins/:plugin/:page` resolves registered plugin menu callbacks and submenu callbacks.

Admin plugin pages are rendered inside the central shell. The router supports normal requests, AJAX callbacks, stylesheet relocation, unavailable-plugin cards, and exception cards. A plugin callback must not emit a second complete HTML document.

#### Minimal database pattern

```php
$db = \CMS\Database::instance();
$prefix = $db->getPrefix();

$db->query("CREATE TABLE IF NOT EXISTS {$prefix}hello_world_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$statement = $db->prepare(
    "SELECT COUNT(*) AS total FROM {$prefix}hello_world_events WHERE user_id = ?"
);
$statement->execute([$userId]);
$count = (int) ($statement->fetch()->total ?? 0);
```

Use the dynamic database prefix only for trusted table identifiers. Bind user-controlled values through the database API; never interpolate them into SQL.

#### Verification before release

Check the PHP syntax of every changed PHP file, activate the plugin in a development environment, exercise the admin and member paths, verify CSRF rejection, test capability denial, inspect logs for load errors, and confirm that plugin documentation is present in `DOC/<area>/`. Do not change core files to make a plugin work.

## Deutsch

### Anwenderleitfaden

Dieser Leitfaden zeigt den vollständigen Weg vom leeren Plugin-Ordner bis zu einem Plugin, das in 365CMS aktiviert werden kann. Die aktive Runtime liegt unter `CMS/plugins/`; das separate Repository `365CMS.DE-PLUGINS` ist ein Quell- und Pflegearbeitsbereich und wird nicht automatisch geladen.

#### 1. Runtime-Ordner anlegen

Legen Sie einen Ordner mit Slug und eine Bootstrap-Datei an:

```text
CMS/plugins/
└── hello-world/
    ├── hello-world.php
    ├── includes/
    │   └── class-hello-world.php
    ├── admin/
    ├── templates/
    └── assets/
        ├── css/hello-world.css
        └── js/hello-world.js
```

Ordner-Slug und Bootstrap-Datei müssen übereinstimmen. Der Plugin Manager liest den Plugin-Header aus der Bootstrap-Datei.

#### 2. Bootstrap-Datei ergänzen

```php
<?php
/**
 * Plugin Name: Hello World
 * Description: Kleines 365CMS-Beispielplugin.
 * Version: 1.0.0
 * Author: Beispielautor
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('HELLO_WORLD_VERSION', '1.0.0');
define('HELLO_WORLD_PATH', PLUGIN_PATH . 'hello-world/');

require_once HELLO_WORLD_PATH . 'includes/class-hello-world.php';

HelloWorld::instance();
```

#### 3. Plugin-Klasse und Hooks ergänzen

```php
<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class HelloWorld
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        \CMS\Hooks::addAction('cms_init', [$this, 'boot'], 10);
        \CMS\Hooks::addAction('cms_admin_menu', [$this, 'registerAdminMenu'], 10);
    }

    public function boot(): void
    {
        \CMS\Hooks::addAction('after_header', [$this, 'renderBanner'], 10);
    }

    public function renderBanner(): void
    {
        echo '<div class="cms-alert cms-alert-info">Hello World ist aktiv.</div>';
    }

    public function registerAdminMenu(): void
    {
        add_menu_page(
            'Hello World',
            'Hello World',
            'manage_settings',
            'hello-world',
            [$this, 'renderAdminPage']
        );
    }

    public function renderAdminPage(): void
    {
        echo '<div class="container-xl"><h1>Hello World</h1></div>';
    }
}
```

Verwenden Sie die Core-Helfer `add_menu_page()` und `add_submenu_page()`. Die Capability wird bei der Menüregistrierung geprüft und muss zusätzlich vor jeder zustandsändernden Aktion geprüft werden.

#### 4. Plugin aktivieren und prüfen

1. `/admin/plugins` öffnen.
2. Das Plugin über seinen Header-Namen suchen.
3. Mit dem Statusschalter aktivieren.
4. Frontend-Hook und Plugin-Adminseite prüfen.
5. Bei Ladefehlern Audit- oder Betriebslog kontrollieren.

Die Seite zeigt Gesamtzahl, aktive und inaktive Plugins. `cms-importer` ist geschützt und kann nicht über die normale Löschaktion entfernt werden.

#### 5. Daten und UI sicher ergänzen

Verwenden Sie `CMS\Database` für Tabellen und vorbereitete Statements für Werte. Templates bleiben im Plugin-Ordner, Ausgaben werden escaped, jedes POST-Formular erhält einen CSRF-Schutz und CSS-Klassen erhalten ein Plugin-Präfix. Member-Menüs oder Dashboard-Widgets werden ausschließlich über die dokumentierten Member-Hooks ergänzt.

### Technische Referenz

#### Runtime und Lebenszyklus

`PLUGIN_PATH` zeigt auf `ABSPATH . 'plugins/'`. `CMS\PluginManager` durchsucht dieses Verzeichnis, akzeptiert gültige Plugin-Slugs, liest die Header `Plugin Name`, `Description`, `Version`, `Author`, `Requires`, `Requires Plugins` und `Requires CMS` und speichert aktive Slugs in der Einstellung `active_plugins`.

Beim Bootstrap werden aktive Plugin-Dateien einmalig eingebunden und pro Plugin wird `plugin_loaded` ausgelöst. Danach wird `plugins_loaded` ausgelöst. Eine fehlende Bootstrap-Datei oder eine Exception beim Laden wird geloggt, auditiert, aus der Aktivliste entfernt und verhindert nicht das Laden der übrigen Plugins.

#### Admin-Routen

- `/admin/plugins` listet installierte Plugins und akzeptiert `activate`, `deactivate` und `delete`.
- `/admin/plugin-marketplace` listet Katalog-Plugins und akzeptiert `install`.
- `/admin/plugins/:plugin/:page` löst registrierte Plugin-Menü- und Untermenü-Callbacks auf.

Plugin-Adminseiten werden in der zentralen Shell gerendert. Der Router unterstützt normale Requests, AJAX-Callbacks, das Verschieben von Stylesheets, Karten für nicht verfügbare Plugins und Exception-Karten. Ein Plugin-Callback darf kein zweites vollständiges HTML-Dokument ausgeben.

#### Minimales Datenbankmuster

```php
$db = \CMS\Database::instance();
$prefix = $db->getPrefix();

$db->query("CREATE TABLE IF NOT EXISTS {$prefix}hello_world_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$statement = $db->prepare(
    "SELECT COUNT(*) AS total FROM {$prefix}hello_world_events WHERE user_id = ?"
);
$statement->execute([$userId]);
$count = (int) ($statement->fetch()->total ?? 0);
```

Verwenden Sie den dynamischen Datenbankpräfix nur für vertrauenswürdige Tabellenbezeichner. Benutzerwerte werden über die Datenbank-API gebunden und niemals in SQL interpoliert.

#### Prüfung vor dem Release

Prüfen Sie die PHP-Syntax jeder geänderten PHP-Datei, aktivieren Sie das Plugin in einer Entwicklungsumgebung, testen Sie Admin- und Member-Pfade, prüfen Sie CSRF-Ablehnungen und Capability-Fehler, kontrollieren Sie Ladefehler in den Logs und legen Sie die Plugin-Dokumentation unter `DOC/<Bereich>/` ab. Core-Dateien werden nicht geändert, damit ein Plugin funktioniert.

## Ergänzung (Stand 2026-10-02)

### Checkliste „Plugin in 10 Minuten“

1. Ordner `CMS/plugins/<slug>/` und Bootstrap `CMS/plugins/<slug>/<slug>.php` anlegen (Dateiname = Ordnername; sonst wird das Plugin beim Laden entfernt).
2. Header mit `Plugin Name`, `Version`, `Description`, `Author`, optional `Requires CMS` / `Requires PHP` ergänzen.
3. Direktaufruf-Schutz `if (!defined('ABSPATH')) { exit; }` an den Anfang setzen.
4. Hooks registrieren (`add_action`, `add_filter`, `CMS\Hooks::addAction`) – keine Ausgabe beim Laden.
5. Optional Lifecycle-Callbacks `<slug_mit_unterstrichen>_activate()`, `_deactivate()`, `_uninstall()` definieren (z. B. `hello_world_activate`).
6. Unter `/admin/plugins` aktivieren; vorher prüft der Sicherheits-Scan den Code auf `eval`, `exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_open`, `pcntl_exec`.
7. Fehler beim Laden (fehlende Datei, Exception) werden geloggt, auditiert und das Plugin aus der aktiven Liste entfernt – im Audit-Log unter `/admin/cms-logs` nachsehen.

### Was der Quick Start nicht abdeckt

- Admin-Menüs, eigene Routen, Member-Dashboard-Widgets, Abo-Gating und DSGVO-Hooks: siehe [PLUGIN-DEVELOPMENT.md](PLUGIN-DEVELOPMENT.md).
- Verteilung über den Marketplace (Registry-Eintrag, SHA-256, erlaubte Hosts): siehe [PLUGIN-MARKETPLACE.md](PLUGIN-MARKETPLACE.md).

## Verwandte Dokumente

- [PLUGIN-DEVELOPMENT.md](PLUGIN-DEVELOPMENT.md)
- [PLUGIN-MARKETPLACE.md](PLUGIN-MARKETPLACE.md)
- [core/HOOKS-REFERENCE.md](../core/HOOKS-REFERENCE.md)
- [admin/plugins/PLUGINS.md](../admin/plugins/PLUGINS.md)
