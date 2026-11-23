# 365CMS Projektdokumentation | Plugin-Entwicklung: Leitfaden Schritt für Schritt

> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Status:** Stable

## English (summary)

Step-by-step guide for plugin developers: what a plugin is, where every file lives, when the core loads the plugin and fires which hook (request order and lifecycle: activate, deactivate, delete), a complete working example plugin (admin page, public route, settings, own table, member area tab), the rules the core enforces (security scan on activation, automatic deactivation after fatal errors, CSRF, CSP, capabilities) and recipes plus typical mistakes. The full reference stays in [PLUGIN-DEVELOPMENT.md](PLUGIN-DEVELOPMENT.md); the short start is [GUIDE.md](GUIDE.md).

## Deutsch

### 1. Was ist ein Plugin?

Ein Plugin erweitert 365CMS um eine Funktion, ohne den Core zu ändern: eigene Admin-Seiten, öffentliche Seiten unter eigener Adresse, Reiter im Mitgliederbereich, eigene Tabellen, Einträge in Suche und Sitemap. Es hängt sich dazu an **Hooks** (Aktionen und Filter) und ruft Core-Dienste auf. Plugin-Code läuft mit denselben Rechten wie der Core – deshalb prüft der Core ein Plugin vor der Aktivierung und deaktiviert es selbst, wenn es abstürzt.

### 2. Wo liegt was?

Alles liegt in **einem** Ordner: `CMS/plugins/<slug>/`. Die Startdatei heißt **genau wie der Ordner**: `CMS/plugins/<slug>/<slug>.php`.

| Datei / Ordner | Pflicht? | Wofür |
|---|---|---|
| `<slug>.php` | **ja** | Startdatei mit Kopfkommentar (Name, Version …); bindet die Klassen ein und meldet die Hooks an |
| `includes/` | empfohlen | Klassen und Dienste des Plugins |
| `admin/` | nein | Vorlagen der Admin-Seiten |
| `templates/` | nein | Vorlagen für öffentliche Seiten und den Mitgliederbereich |
| `assets/css/`, `assets/js/` | nein | eigene Dateien; im Browser erreichbar unter `/plugins/<slug>/assets/…` |
| `update.json` | für Updates/Marketplace | Version, `min_cms_version`/`requires_cms`, Changelog |

Kopfkommentar der Startdatei (der Core liest diese Felder):

```php
/**
 * Plugin Name: Hallo Welt
 * Description: Beispiel-Plugin für 365CMS
 * Version: 1.0.0
 * Author: Meine Firma
 * Requires CMS: 4.2.0
 * Requires Plugins: anderes-plugin, noch-eins
 */
```

`Requires Plugins` (kommagetrennte Slugs) und `Requires CMS` sind optional. Fehlt ein benötigtes Plugin, lässt sich das Plugin nicht aktivieren.

### 3. Wann passiert was?

#### 3.1 Bei jeder Anfrage

Aktive Plugins werden **bei jeder Anfrage** geladen – im Frontend, im Admin, bei API-Aufrufen und im Cron. Deshalb: In der Startdatei nur Konstanten setzen, Klassen einbinden und Hooks anmelden. Die eigentliche Arbeit passiert in den Hook-Funktionen.

| Schritt | Was der Core tut | Hooks für Ihr Plugin |
|---|---|---|
| 1 | Konfiguration, Datenbank, Sitzung, Sicherheit starten | – |
| 2 | jede aktive Startdatei einbinden | `plugin_loaded` (mit dem Slug), danach einmal `plugins_loaded` |
| 3 | im Frontend: Theme laden | `theme_loaded` |
| 4 | Core ist bereit | **`cms_init`** (hier initialisieren), danach `cms_init_web`, `cms_init_admin`, `cms_init_api` oder `cms_init_cli` |
| 5 | Routen festlegen | `cms_before_route`, **`register_routes`** (bekommt den Router: eigene Adressen anmelden) |
| 6 | im Admin: Menü aufbauen | **`cms_admin_menu`** (Admin-Seiten anmelden) |
| 7 | Seite ausgeben | Frontend: `head`, `before_footer`, `body_end`; Admin: `admin_head`, `admin_body_end`; Mitgliederbereich: `member_dashboard_init`, `member_menu_items` |
| 8 | Antwort fertig | `cms_after_route` |

Zeitgesteuerte Arbeit hängt an den Cron-Hooks `cms_cron_hourly` und `cms_cron_daily`.

#### 3.2 Lebenszyklus

| Ereignis | Wo | Was der Core tut | Ihre Funktion (optional) |
|---|---|---|---|
| Hochladen | `/admin/plugins` | ZIP prüfen und entpacken | – |
| **Aktivieren** | `/admin/plugins` | Startdatei vorhanden? Abhängigkeiten erfüllt? **Sicherheitsscan** und Syntaxprüfung aller PHP-Dateien (Abschnitt 6). Dann einbinden | `<slug>_activate()` |
| Deaktivieren | `/admin/plugins` | aus der Liste der aktiven Plugins nehmen | `<slug>_deactivate()` |
| Löschen | `/admin/plugins` (nur deaktiviert) | Startdatei einbinden, Ordner löschen | `<slug>_uninstall()` |

Im Funktionsnamen werden Bindestriche zu Unterstrichen: Plugin `hallo-welt` → `hallo_welt_activate()`. Zusätzlich feuern `plugin_activated`, `plugin_deactivated`, `plugin_before_delete` und `plugin_deleted` (jeweils mit dem Slug).

**Automatisch deaktiviert** wird ein Plugin, wenn seine Startdatei fehlt oder beim Laden einen Fehler wirft. Der Grund steht im Protokoll (`/admin/cms-logs`, Kanal `plugins`) und im Audit-Log.

### 4. Ein vollständiges Beispiel-Plugin

Das Plugin `hallo-welt` zeigt alle typischen Bausteine: Einstellung, Admin-Seite mit Formular, öffentliche Seite, eigene Tabelle und Reiter im Mitgliederbereich.

**`CMS/plugins/hallo-welt/hallo-welt.php`**

```php
<?php
/**
 * Plugin Name: Hallo Welt
 * Description: Beispiel-Plugin für 365CMS
 * Version: 1.0.0
 * Author: Meine Firma
 * Requires CMS: 4.2.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('HALLO_WELT_VERSION', '1.0.0');
define('HALLO_WELT_DIR', PLUGIN_PATH . 'hallo-welt/');
define('HALLO_WELT_URL', '/plugins/hallo-welt/');

require_once HALLO_WELT_DIR . 'includes/class-hallo-welt.php';

HalloWelt::instance();

/** Lebenszyklus: beim Aktivieren Tabelle anlegen. */
function hallo_welt_activate(): void
{
    HalloWelt::instance()->installSchema();
}

/** Lebenszyklus: beim Löschen eigene Daten entfernen. */
function hallo_welt_uninstall(): void
{
    $db = \CMS\Database::instance();
    $db->execute('DROP TABLE IF EXISTS ' . $db->getPrefix() . 'hallo_welt_eintraege');
}
```

**`CMS/plugins/hallo-welt/includes/class-hallo-welt.php`**

```php
<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Database;
use CMS\Hooks;
use CMS\Security;

final class HalloWelt
{
    private const SCHEMA_VERSION = '1';
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        Hooks::addAction('cms_init', [$this, 'init']);
        Hooks::addAction('cms_admin_menu', [$this, 'registerAdminPages']);
        Hooks::addAction('register_routes', [$this, 'registerRoutes']);
        Hooks::addAction('member_dashboard_init', [$this, 'registerMemberSection']);
        Hooks::addAction('admin_head', [$this, 'adminStyles']);
    }

    /** Läuft bei jeder Anfrage, nachdem der Core bereit ist. Schema nur anlegen, wenn die Version fehlt. */
    public function init(): void
    {
        if ((string) get_option('hallo_welt_schema', '') !== self::SCHEMA_VERSION) {
            $this->installSchema();
        }
    }

    public function installSchema(): void
    {
        $db = Database::instance();
        $db->execute(
            'CREATE TABLE IF NOT EXISTS ' . $db->getPrefix() . 'hallo_welt_eintraege (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                text VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_hallo_welt_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        update_option('hallo_welt_schema', self::SCHEMA_VERSION);
    }

    /** Admin-Seite: erreichbar unter /admin/plugins/hallo-welt */
    public function registerAdminPages(): void
    {
        add_menu_page('Hallo Welt', 'Hallo Welt', 'manage_settings', 'hallo-welt', [$this, 'renderAdminPage'], 'ti-hand-stop');
    }

    public function renderAdminPage(): void
    {
        $security = Security::instance();
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
            if (!$security->verifyToken($token, 'hallo_welt_settings')) {
                $message = 'Sicherheitsprüfung fehlgeschlagen. Bitte erneut versuchen.';
            } elseif (!Auth::instance()->hasCapability('manage_settings')) {
                $message = 'Keine Berechtigung.';
            } else {
                $gruss = is_string($_POST['gruss'] ?? null) ? mb_substr(trim($_POST['gruss']), 0, 100) : '';
                update_option('hallo_welt_gruss', $gruss);
                $message = 'Gespeichert.';
            }
        }
        $gruss = (string) get_option('hallo_welt_gruss', 'Hallo Welt');
        $csrf = $security->generateToken('hallo_welt_settings');
        include HALLO_WELT_DIR . 'admin/settings.php';
    }

    /** Öffentliche Seite /hallo und /hallo/<name> */
    public function registerRoutes(object $router): void
    {
        $router->addRoute('GET', '/hallo', fn () => $this->renderPublic(''));
        $router->addRoute('GET', '/hallo/:name', fn (string $name) => $this->renderPublic($name));
    }

    private function renderPublic(string $name): void
    {
        $gruss = (string) get_option('hallo_welt_gruss', 'Hallo Welt');
        \CMS\ThemeManager::instance()->render('page', ['page' => [
            'title' => $gruss . ($name !== '' ? ', ' . $name : ''),
            'content' => '<p>' . htmlspecialchars($gruss, ENT_QUOTES, 'UTF-8') . '</p>',
        ]]);
    }

    /** Reiter im Mitgliederbereich: /member/plugin/hallo-welt */
    public function registerMemberSection(object $registry): void
    {
        $registry->register([
            'slug' => 'hallo-welt',
            'label' => 'Hallo Welt',
            'icon' => '👋',
            // Gibt selbst aus (echo/include); ein Rückgabewert wird nicht angezeigt.
            'render_callback' => static function (object $user, array $params): void {
                echo '<p>Hallo, ' . htmlspecialchars((string) ($user->username ?? ''), ENT_QUOTES, 'UTF-8') . '!</p>';
            },
        ]);
    }

    public function adminStyles(): void
    {
        echo '<link rel="stylesheet" href="' . htmlspecialchars(HALLO_WELT_URL . 'assets/css/admin.css?v=' . HALLO_WELT_VERSION, ENT_QUOTES, 'UTF-8') . '">';
    }
}
```

**`CMS/plugins/hallo-welt/admin/settings.php`** (Vorlage, nur Ausgabe)

```php
<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<div class="admin-page-header"><h1>Hallo Welt</h1></div>
<?php if ($message !== ''): ?><div class="alert alert-info" role="status"><?= $e($message) ?></div><?php endif; ?>
<form method="post" class="admin-card">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <label for="hallo-welt-gruss">Begrüßung</label>
    <input id="hallo-welt-gruss" name="gruss" maxlength="100" value="<?= $e($gruss) ?>">
    <button type="submit" class="btn btn-primary">Speichern</button>
</form>
```

Ablauf: Ordner anlegen → unter `/admin/plugins` aktivieren (legt die Tabelle an) → Menüeintrag „Hallo Welt“ erscheint im Bereich der aktiven Plugins → `/hallo` und `/hallo/Anna` aufrufen → als Mitglied `/member/plugin/hallo-welt` öffnen.

### 5. Bausteine im Einzelnen

#### Admin-Seiten

- `add_menu_page($seitentitel, $menütitel, $capability, $slug, $callback, $icon, $position)` und `add_submenu_page($eltern_slug, $seitentitel, $menütitel, $capability, $slug, $callback)` **nur im Hook `cms_admin_menu`** aufrufen.
- Adressen: `/admin/plugins/<plugin>` (Hauptseite) und `/admin/plugins/<plugin>/<unterseite>`.
- Der Admin-Bereich ist nur für die Rolle `admin` offen; die Capability im Menü bestimmt zusätzlich, wer die Seite sieht. Im Callback die Capability bei **jeder** schreibenden Aktion erneut prüfen.
- Der Callback gibt nur den Inhalt aus; Kopf, Seitenleiste und Fuß liefert der Core. Für ein einheitliches Aussehen die Bausteine aus [PLUGIN-DEVELOPMENT.md](PLUGIN-DEVELOPMENT.md) nutzen (`.admin-page-header`, `.admin-card`, Plugin-Arbeitsbereich mit `cms_plugin_workspace_open()`).
- Icon: Tabler-Klasse wie `ti-hand-stop`, eigenes SVG oder bis zu vier Zeichen Text.

#### Formulare und CSRF

| Wo | Token erzeugen | Token prüfen |
|---|---|---|
| Admin-Seite | `Security::instance()->generateToken('mein_plugin_aktion')` | `verifyToken($_POST['csrf_token'], 'mein_plugin_aktion')` (einmalig) bzw. `verifyPersistentToken()` für AJAX mit mehreren Aufrufen |
| öffentliche Seite (POST) | `generateToken('form_guard')`, Feld `csrf_token` | der Router prüft **vor** Ihrer Route; ohne gültiges Token antwortet er mit 403 |
| Mitgliederbereich | `$registry->csrfField('<slug>')` im Formular | der Core prüft (Standard `csrf => 'core'`), dann ruft er Ihren `post_callback` |

Zustandsänderungen nur per POST, nie per GET-Link.

#### Öffentliche Adressen

- Im Hook `register_routes`: `$router->addRoute('GET', '/pfad/:name', $callback)`. Platzhalter `:name` passt auf Buchstaben, Ziffern, `_` und `-` und wird als Argument übergeben; `:year` nur auf vier Ziffern, `:month`/`:day` auf ein bis zwei Ziffern.
- Die Seite im Theme-Rahmen ausgeben: `ThemeManager::instance()->render('page', ['page' => [...]])` – dann passen Kopf, Fuß, CSP und SEO automatisch.
- Englische Adressen (`/en/…`): Helfer `cms_plugin_public_language()`, `cms_plugin_public_localized_path()`.
- Titel und Beschreibung je Seite: `\CMS\Services\SEOService::getInstance()->setRequestMeta(['title' => …, 'description' => …])`.

#### Einstellungen

- Einfach: `get_option('mein_plugin_schluessel', $standard)` und `update_option('mein_plugin_schluessel', $wert)` (Tabelle `settings`, Arrays werden als JSON gespeichert).
- Gruppiert und bei Bedarf verschlüsselt (z. B. API-Schlüssel): `\CMS\Services\SettingsService::getInstance()->set('mein_plugin', 'api_key', $wert, true)` und `->get('mein_plugin', 'api_key')`.
- Schlüssel immer mit dem Plugin-Präfix benennen.

#### Eigene Tabellen

- `CREATE TABLE IF NOT EXISTS` mit `Database::instance()->getPrefix()`, InnoDB, `utf8mb4`, Index auf häufigen Filtern (z. B. `user_id`). Nie `cms_` fest einschreiben.
- Anlegen in `<slug>_activate()` und zusätzlich in `cms_init`, wenn eine gespeicherte Schema-Version fehlt (für Updates des Plugins).
- Keine `NOT NULL`-Spalte ohne Standardwert (Strict Mode der Datenbank).
- Abfragen nur mit Platzhaltern: `$db->get_results('SELECT … WHERE user_id = ?', [$userId])`. Nur selbst erzeugte Tabellen- und Spaltennamen dürfen im SQL-Text stehen.

#### Mitgliederbereich

- Hook `member_dashboard_init` liefert die Registry; `register()` braucht mindestens `slug` und `render_callback`. Weitere Schlüssel: `label`, `icon`, `priority`, `capability`, `post_callback`, `dashboard_widget`.
- `render_callback(object $user, array $params)` **gibt selbst aus** (`echo` oder `include` einer Vorlage); ein Rückgabewert wird nicht angezeigt.
- `post_callback(object $user, array $params): ?array` verarbeitet POST-Anfragen **vor** jeder Ausgabe und gibt z. B. `['type' => 'success', 'message' => 'Gespeichert.']` zurück; der Core zeigt die Meldung und leitet per 303 zurück (kein doppeltes Absenden beim Neuladen).
- Adresse: `/member/plugin/<slug>`. Der Core prüft die Anmeldung; Daten nur für das angemeldete Konto lesen und schreiben.
- Zusätzliche Menüeinträge: Filter `member_menu_items`; Kacheln auf dem Dashboard: Filter `member_dashboard_widgets`.

#### CSS und JavaScript

- Frontend: Hook `head` für `<link rel="stylesheet">`, Hook `before_footer` für `<script src="…" defer>`.
- Admin: Hooks `admin_head` und `admin_body_end`.
- Adresse der eigenen Dateien: `/plugins/<slug>/assets/…`.
- Klassennamen mit Plugin-Präfix (`.hallo-welt-karte`), keine allgemeinen Klassen wie `.card` überschreiben.
- Keine Inline-Skripte ohne Nonce, keine `onclick=`-Attribute (CSP). Für einfache Rückfragen das Core-Attribut `data-cms-confirm="Wirklich löschen?"` nutzen.

#### Suche, Sitemap, Datenschutz

| Ziel | Hook | Kurz |
|---|---|---|
| Treffer in der Website-Suche | Filter `search_results` | Treffer mit `title`, `url`, optional `excerpt`, `_type`, `date` anhängen |
| Adressen in der Sitemap | Filter `cms_sitemap_entries` | Einträge `['url' => 'pfad', 'lastmod' => …]` anhängen, nur indexierbare Seiten |
| DSGVO-Auskunft | Aktion `dsgvo_export_data` | Daten des Kontos `$userId` zur Auskunft beitragen |
| DSGVO-Löschung | Aktion `dsgvo_delete_data` | Daten des Kontos löschen, bevor das Konto gelöscht wird |
| Aufräumen nach Frist | Aktion `cms_cron_daily` | alte Zeilen eigener Tabellen löschen |

Alle Hooks mit Argumenten: [../core/HOOKS-REFERENCE.md](../core/HOOKS-REFERENCE.md).

### 6. Regeln, die der Core durchsetzt

- **Sicherheitsscan beim Aktivieren:** Jede PHP-Datei des Plugins muss fehlerfrei sein und darf die Funktionen `eval`, `exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_open` und `pcntl_exec` nicht aufrufen. Methodenaufrufe wie `$pdo->exec()` sind erlaubt. Ein Treffer bricht die Aktivierung mit Datei und Funktionsname ab.
- **Absturzschutz:** Wirft die Startdatei beim Laden einen Fehler, deaktiviert der Core das Plugin und protokolliert den Grund.
- **Rechte:** Admin-Seiten nur für Administratoren; jede schreibende Aktion prüft Capability **und** CSRF-Token auf dem Server.
- **CSP:** wie bei Themes – Skripte in Dateien, Nonce für unvermeidbare Inline-Blöcke (`Security::instance()->nonceAttr()`).
- **Ausgaben maskieren:** `htmlspecialchars($wert, ENT_QUOTES, 'UTF-8')` bzw. `esc_html()`, `esc_attr()`, `esc_url()` für alles, was aus Anfrage oder Datenbank kommt.
- **Eingaben prüfen:** `$_POST`/`$_GET`-Werte können Arrays sein; vor der Verarbeitung mit `is_string()` prüfen und in der Länge begrenzen.

### 7. Häufige Fehler und ihre Lösung

| Symptom | Ursache | Lösung |
|---|---|---|
| Plugin erscheint nicht in `/admin/plugins` | Startdatei heißt anders als der Ordner oder Kopfkommentar fehlt | `CMS/plugins/<slug>/<slug>.php` mit `Plugin Name:` |
| Aktivieren bricht mit „Sicherheitswarnung“ ab | verbotene Funktion (Abschnitt 6) | Aufruf entfernen bzw. Core-Dienst nutzen (z. B. `CMS\Http\Client` statt Shell-Befehl) |
| Plugin ist plötzlich deaktiviert | Fehler beim Laden | `/admin/cms-logs`, Kanal `plugins`; Fehler beheben, erneut aktivieren |
| Menüeintrag fehlt | `add_menu_page()` außerhalb von `cms_admin_menu` aufgerufen oder Capability fehlt | im Hook anmelden; Capability prüfen |
| Öffentliches Formular liefert 403 | Token `form_guard` fehlt | verstecktes Feld `csrf_token` |
| Route liefert 404 | Route nicht in `register_routes` angemeldet oder Platzhalter passt nicht | Muster prüfen (`:name` ohne Punkte und Schrägstriche) |
| Skript läuft nicht | CSP blockiert Inline-Code oder `onclick=` | Skript in Datei auslagern |
| Tabelle fehlt nach Update | Schema wird nur beim Aktivieren angelegt | zusätzlich in `cms_init` mit Schema-Version prüfen (Beispiel oben) |

### 8. Veröffentlichen

1. Version in Startdatei und `update.json` gleich halten, Changelog in `update.json` ergänzen.
2. ZIP mit dem Plugin-Ordner auf oberster Ebene (`hallo-welt/hallo-welt.php` …), ohne `.git`, Testdaten und Zugangsdaten.
3. SHA-256 berechnen und im Katalog eintragen ([PLUGIN-MARKETPLACE.md](PLUGIN-MARKETPLACE.md)).
4. Vor der Freigabe: Aktivieren, Deaktivieren, Löschen und erneutes Aktivieren in einer Testinstallation durchspielen.

### Verwandte Dokumente

[GUIDE.md](GUIDE.md) (Kurzstart) · [PLUGIN-DEVELOPMENT.md](PLUGIN-DEVELOPMENT.md) (Referenz) · [PLUGIN-MARKETPLACE.md](PLUGIN-MARKETPLACE.md) · [../core/HOOKS-REFERENCE.md](../core/HOOKS-REFERENCE.md) · [../admin/plugins/PLUGINS.md](../admin/plugins/PLUGINS.md)
