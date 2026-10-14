# 365CMS – Projektdokumentation | Abschnitt: INSTALLATION
> **Stand:** 2026-10-02 | **Version:** 3.4.00 (Changelog bis 3.4.09) | **Status:** Stable | **Update:** 2026-10-02

## Table of contents | Inhaltsverzeichnis

- [Overview](#overview--überblick)
- [Requirements](#requirements--systemvoraussetzungen)
- [Target layout](#target-layout--zielstruktur-beim-deployment)
- [Database](#database--datenbank-anlegen)
- [Configuration](#configuration--konfiguration-in-cmsconfigappphp)
- [File permissions](#file-permissions--dateirechte)
- [Web server](#web-server--webserver-konfigurieren)
- [First start](#first-start--erster-start)
- [Production checklist](#production-checklist--produktions-checkliste)
- [Troubleshooting](#troubleshooting)

---

## Overview | Überblick

**English**

Two practical install paths exist:

1. **Web installer via `CMS/install.php`**  
   The installer checks PHP compatibility, PDO MySQL availability, and write access for `CMS/config/app.php`. It collects database, site, and admin data, writes `CMS/config/app.php`, creates the schema through `CMS\SchemaManager` (`SCHEMA_VERSION = v23`), and creates the first administrator.
2. **Repository checkout / manual setup**  
   Edit `CMS/config/app.php` directly. Replace every placeholder and every security key.

The installer is the preferred path for a fresh deployment. After a successful install, remove `install.php` from public deployments or keep it reachable only for administrators.

`CMS/config.php` is a stub. It sets `ABSPATH`, enforces PHP 8.4.1+, and loads `CMS/config/app.php`. If `config/app.php` is missing, the stub sends the visitor to the installer.

The installer session cookie uses `HttpOnly`, `SameSite=Strict`, and `Secure` when HTTPS is detected. Forms use a CSRF token. The admin password help text requires at least 12 characters.

On an existing install the welcome step can run a schema repair / update without wiping data, or a full reinstall that deletes all database tables.

**Deutsch**

Für neue Installationen gibt es zwei praktikable Wege:

1. **Web-Installer über `CMS/install.php`**  
   Der Installer prüft PHP-Kompatibilität, PDO-MySQL und Schreibrechte für `CMS/config/app.php`. Er fragt Datenbank-, Site- und Admin-Daten ab, schreibt `CMS/config/app.php`, erstellt das Schema über `CMS\SchemaManager` (`SCHEMA_VERSION = v23`) und legt den ersten Administrator an.
2. **Repository-Checkout / manuelle Einrichtung**  
   `CMS/config/app.php` direkt bearbeiten. Alle Platzhalterwerte und Security-Keys müssen ersetzt werden.

Der bevorzugte Einstieg für frische Deployments ist der Installer. Nach erfolgreicher Installation sollte `install.php` aus öffentlichen Deployments entfernt oder nur für Administratoren erreichbar gehalten werden.

`CMS/config.php` ist ein Stub. Er setzt `ABSPATH`, erzwingt PHP 8.4.1+ und lädt `CMS/config/app.php`. Fehlt `config/app.php`, leitet der Stub zum Installer weiter.

Die Installer-Session nutzt `HttpOnly`, `SameSite=Strict` und bei HTTPS zusätzlich `Secure`. Formulare sind CSRF-geschützt. Der Installer verlangt laut Hilfetext mindestens 12 Zeichen für das Admin-Passwort.

Bei einer bestehenden Installation kann der Willkommensschritt ein Schema-Update ohne Datenverlust oder eine Komplett-Neuinstallation mit Löschen aller Tabellen anbieten.

---

## Requirements | Systemvoraussetzungen

**English**

Enforced in code (`CMS_MIN_PHP_VERSION` in `CMS/config.php`): **PHP 8.4.1+**. Below that, `config.php`, installer, and bootstrap block a normal start. The bundled Symfony 8.1 libraries require PHP 8.4.1, so the code also ships this floor as `CMS\Version::MIN_PHP`; the higher of the two values applies, which keeps older installations (whose `config.php` is not replaced by core updates) working.

The installer welcome screen checks:

- PHP version ≥ required version
- MySQL PDO extension (`pdo_mysql`)
- write permission so `config/app.php` can be created

The installer does **not** encode a MySQL/MariaDB version gate. Database access is PDO MySQL (`mysql:host=…;dbname=…;charset=utf8mb4`).

Neither `CMS/` nor the root [`README.md`](../README.md) names a minimum database version any more ("MySQL- or MariaDB-compatible"). Recommended, because the schema uses `utf8mb4` and `ON DUPLICATE KEY` upserts: MySQL 8.0+ or MariaDB 10.6+ (audit 2026-10-03).

| Component | In code / documented |
|---|---|
| PHP | **8.4.1+** (enforced) |
| Database | MySQL or MariaDB via `pdo_mysql` |
| Web server | Apache 2.4 with rewrite, or Nginx with `try_files` |
| PHP extensions (needed) | `pdo_mysql`, plus `mbstring`, `json`, `openssl` used across the core |
| PHP extensions (feature paths) | `curl`, `zip`, `gd` / image support, `fileinfo`, `intl` |
| Memory | not gated in installer |

**Deutsch**

Im Code erzwungen (`CMS_MIN_PHP_VERSION` in `CMS/config.php`): **PHP 8.4.1+**. Darunter blockieren `config.php`, Installer und Bootstrap den normalen Start. Die gebündelten Symfony-8.1-Libraries verlangen PHP 8.4.1, deshalb liefert der Code diese Untergrenze zusätzlich als `CMS\Version::MIN_PHP` mit; es gilt der höhere der beiden Werte. Ältere Installationen, deren `config.php` bei Core-Updates nicht ersetzt wird, laufen damit weiter.

Der Installer-Willkommensbildschirm prüft:

- PHP-Version ≥ erforderliche Version
- MySQL-PDO-Erweiterung (`pdo_mysql`)
- Schreibrecht, damit `config/app.php` erzeugt werden kann

Der Installer prüft **keine** MySQL-/MariaDB-Versionsnummer. Der Datenbankzugriff läuft über PDO MySQL (`mysql:host=…;dbname=…;charset=utf8mb4`).

Weder `CMS/` noch das Root-[`README.md`](../README.md) nennen noch eine Mindestversion der Datenbank („MySQL- oder MariaDB-kompatibel“). Empfohlen, weil das Schema `utf8mb4` und `ON DUPLICATE KEY`-Upserts nutzt: MySQL 8.0+ oder MariaDB 10.6+ (Audit 2026-10-03).

| Komponente | Im Code / dokumentiert |
|---|---|
| PHP | **8.4.1+** (erzwungen) |
| Datenbank | MySQL oder MariaDB über `pdo_mysql` |
| Webserver | Apache 2.4 mit Rewrite oder Nginx mit `try_files` |
| PHP-Erweiterungen (nötig) | `pdo_mysql`, plus `mbstring`, `json`, `openssl` im Core |
| PHP-Erweiterungen (Featurepfade) | `curl`, `zip`, `gd` / Bildverarbeitung, `fileinfo`, `intl` |
| Arbeitsspeicher | im Installer nicht geprüft |

---

## Target layout | Zielstruktur beim Deployment

**English**

In production the contents of `CMS/` are typically the web root.

| Path | Role |
|---|---|
| `CMS/index.php` | public entry |
| `CMS/config.php` | stub, loads `config/app.php`, enforces PHP 8.4.1+ |
| `CMS/config/app.php` | real configuration |
| `CMS/install.php` | installer entry (remove after install) |
| `CMS/cron.php` | cron / background entry |
| `CMS/update.php` | update entry |
| `CMS/orders.php` | orders entry |
| `CMS/core/` | bootstrap, router, database, services |
| `CMS/admin/` | admin entry points and modules |
| `CMS/member/` | member area |
| `CMS/themes/` | active themes (`cms-default`) |
| `CMS/plugins/` | installed plugins (`cms-importer`) |
| `CMS/assets/` | runtime CSS, JS, bundled libraries |
| `CMS/backups/` | local backups |
| `CMS/logs/` | log files (`LOG_PATH`) |
| `CMS/cache/` | runtime cache |
| `CMS/uploads/` | public uploads |
| `CMS/vendor/` | additional vendor code (currently `vendor/dompdf/`) |

**Deutsch**

In produktiven Installationen ist in der Regel der Inhalt von `CMS/` das Webroot.

| Pfad | Zweck |
|---|---|
| `CMS/index.php` | Frontend-Einstieg |
| `CMS/config.php` | Stub, lädt `config/app.php`, erzwingt PHP 8.4.1+ |
| `CMS/config/app.php` | echte Konfiguration |
| `CMS/install.php` | Installer-Einstieg (nach der Installation entfernen) |
| `CMS/cron.php` | Cron-/Hintergrund-Einstieg |
| `CMS/update.php` | Update-Einstieg |
| `CMS/orders.php` | Bestell-Einstieg |
| `CMS/core/` | Bootstrap, Router, Datenbank, Services |
| `CMS/admin/` | Admin-Einstiegspunkte und Module |
| `CMS/member/` | Mitgliederbereich |
| `CMS/themes/` | aktive Themes (`cms-default`) |
| `CMS/plugins/` | installierte Plugins (`cms-importer`) |
| `CMS/assets/` | Runtime-CSS, -JS, gebündelte Bibliotheken |
| `CMS/backups/` | lokale Backups |
| `CMS/logs/` | Log-Dateien (`LOG_PATH`) |
| `CMS/cache/` | Laufzeit-Cache |
| `CMS/uploads/` | öffentliche Uploads |
| `CMS/vendor/` | zusätzlicher Vendor-Code (aktuell `vendor/dompdf/`) |

---

## Database | Datenbank anlegen

**English**

Create an empty UTF-8 database, then let the installer or `SchemaManager` create tables.

```sql
CREATE DATABASE cms365 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cms365user'@'localhost' IDENTIFIED BY 'SICHERES_PASSWORT';
GRANT ALL PRIVILEGES ON cms365.* TO 'cms365user'@'localhost';
FLUSH PRIVILEGES;
```

Core tables are created with `CREATE TABLE IF NOT EXISTS` by `CMS\SchemaManager`. Default table prefix is `cms_` (`DB_PREFIX`). Default charset is `utf8mb4` (`DB_CHARSET`).

The installer can also run a schema repair on an existing database without deleting content.

**Deutsch**

Eine leere UTF-8-Datenbank anlegen. Tabellen erzeugt anschließend der Installer bzw. `SchemaManager`.

```sql
CREATE DATABASE cms365 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cms365user'@'localhost' IDENTIFIED BY 'SICHERES_PASSWORT';
GRANT ALL PRIVILEGES ON cms365.* TO 'cms365user'@'localhost';
FLUSH PRIVILEGES;
```

Die Kern-Tabellen werden mit `CREATE TABLE IF NOT EXISTS` durch `CMS\SchemaManager` angelegt. Standardpräfix ist `cms_` (`DB_PREFIX`). Standardzeichensatz ist `utf8mb4` (`DB_CHARSET`).

Der Installer kann auf einer bestehenden Datenbank auch eine Schema-Reparatur ohne Inhaltslöschung ausführen.

---

## Configuration | Konfiguration in `CMS/config/app.php`

**English**

`CMS/config/app.php` is a **template with placeholders**. `install.php` is supposed to overwrite it with real values and keys from `random_bytes(32)`. Do not commit real credentials.

If you configure by hand, replace **every** placeholder.

| Constant | Purpose |
|---|---|
| `CMS_DEBUG` | debug mode (production: `false`) |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | database |
| `DB_CHARSET` | default `utf8mb4` |
| `DB_PREFIX` | default `cms_` |
| `AUTH_KEY`, `SECURE_AUTH_KEY`, `NONCE_KEY` | security keys |
| `SITE_NAME` | site name |
| `SITE_URL` | full base URL **without trailing slash and without a subdirectory** (comment in `app.php`) |
| `ADMIN_EMAIL` | admin address |
| `CMS_VERSION` | set from `\CMS\Version::CURRENT` |
| `CORE_PATH`, `THEME_PATH`, `PLUGIN_PATH`, `UPLOAD_PATH`, `ASSETS_PATH` | paths |
| `LOG_PATH`, `CMS_ERROR_LOG` | logging |
| `DEFAULT_THEME` | default `cms-default` |
| `MAX_LOGIN_ATTEMPTS` | default `5` |
| `LOGIN_TIMEOUT` | default `300` seconds |
| `CMS_HTTPS_REDIRECT_STRATEGY` | default `upstream` |
| `CMS_HSTS_MODE` | default `https-only` |
| `CMS_HSTS_MAX_AGE` | default `31536000` |
| `LDAP_*` | optional LDAP / Active Directory |
| `JWT_SECRET`, `JWT_TTL`, `JWT_ISSUER` | API JWT (`JWT_SECRET` empty → fallback `AUTH_KEY`) |
| `SMTP_*` | mail; empty `SMTP_HOST` falls back to `mail()` |

Example for a key:

```php
bin2hex(random_bytes(32))
```

Timezone in the template is `Europe/Berlin`.

**Deutsch**

`CMS/config/app.php` ist ein **Template mit Platzhaltern**. `install.php` soll die Datei mit echten Werten und Keys aus `random_bytes(32)` überschreiben. Keine echten Credentials committen.

Bei manueller Einrichtung **alle** Platzhalter ersetzen.

| Konstante | Zweck |
|---|---|
| `CMS_DEBUG` | Debug-Modus (Produktion: `false`) |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Datenbank |
| `DB_CHARSET` | Standard `utf8mb4` |
| `DB_PREFIX` | Standard `cms_` |
| `AUTH_KEY`, `SECURE_AUTH_KEY`, `NONCE_KEY` | Sicherheits-Keys |
| `SITE_NAME` | Site-Name |
| `SITE_URL` | vollständige Basis-URL **ohne Trailing Slash und ohne Unterverzeichnis** (Kommentar in `app.php`) |
| `ADMIN_EMAIL` | Admin-Adresse |
| `CMS_VERSION` | aus `\CMS\Version::CURRENT` |
| `CORE_PATH`, `THEME_PATH`, `PLUGIN_PATH`, `UPLOAD_PATH`, `ASSETS_PATH` | Pfade |
| `LOG_PATH`, `CMS_ERROR_LOG` | Logging |
| `DEFAULT_THEME` | Standard `cms-default` |
| `MAX_LOGIN_ATTEMPTS` | Standard `5` |
| `LOGIN_TIMEOUT` | Standard `300` Sekunden |
| `CMS_HTTPS_REDIRECT_STRATEGY` | Standard `upstream` |
| `CMS_HSTS_MODE` | Standard `https-only` |
| `CMS_HSTS_MAX_AGE` | Standard `31536000` |
| `LDAP_*` | optional LDAP / Active Directory |
| `JWT_SECRET`, `JWT_TTL`, `JWT_ISSUER` | API-JWT (`JWT_SECRET` leer → Fallback `AUTH_KEY`) |
| `SMTP_*` | Mail; leerer `SMTP_HOST` fällt auf `mail()` zurück |

Beispiel für einen Key:

```php
bin2hex(random_bytes(32))
```

Zeitzone im Template: `Europe/Berlin`.

---

## File permissions | Dateirechte

**English**

Do not use `777` in production.

| Path | Recommendation |
|---|---|
| Directories | `755` |
| Normal files | `644` |
| `CMS/config.php` | `640` or `644` |
| `CMS/config/app.php` | `640` or `644` (web-inaccessible via `config/.htaccess`) |
| `CMS/logs/` | writable by the server only |
| `CMS/cache/` | writable by the server |
| `CMS/backups/` | not publicly served |
| `CMS/uploads/` | writable; execution of uploaded scripts must stay blocked |

**Deutsch**

Keine pauschalen `777`-Rechte in Produktion.

| Pfad | Empfehlung |
|---|---|
| Verzeichnisse | `755` |
| normale Dateien | `644` |
| `CMS/config.php` | `640` oder `644` |
| `CMS/config/app.php` | `640` oder `644` (per `config/.htaccess` nicht direkt auslieferbar) |
| `CMS/logs/` | nur serverseitig beschreibbar |
| `CMS/cache/` | serverseitig beschreibbar |
| `CMS/backups/` | nicht öffentlich auslieferbar |
| `CMS/uploads/` | beschreibbar; Ausführung hochgeladener Skripte muss blockiert bleiben |

---

## Web server | Webserver konfigurieren

**English**

### Apache

Requirements:

- `mod_rewrite` enabled
- `AllowOverride All` for the web root
- directory indexes disabled

Shipped `CMS/.htaccess` does not use `<Directory>` blocks (invalid in `.htaccess`). Private runtime paths (`config/`, `core/`, `logs/`, `backups/`, `cache/`) and installer internals are covered by rewrite / files rules. `php_value` limits apply only under `mod_php`; with PHP-FPM/CGI set upload limits in `php.ini`, the pool, or the hosting panel.

The private cache area stays blocked except public CSS/JS produced by `AssetOptimizerService` under `cache/optimized-assets/<hash>.min.css` and `cache/optimized-assets/<hash>.min.js`.

Public uploads remain direct URLs. `.htaccess` blocks executable uploads and hidden files, but allows normal files under `uploads/`. Exception: `uploads/member/` (private member files, delivered only through `/media-file` with an owner check) and `uploads/import/` (importer sources) are blocked. Local FontManager fonts under `uploads/fonts/` set WOFF/WOFF2/TTF/OTF MIME types so browsers load them with `X-Content-Type-Options: nosniff`.

### Nginx

Unknown paths must reach `index.php`:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

Do not serve `config/`, `logs/`, `cache/`, `backups/`, or `core/` as static files. Nginx ignores `.htaccess`, so the private paths must be mirrored explicitly:

```nginx
# Private runtime paths, bundled libraries and installer internals
location ~ ^/cache/optimized-assets/[a-f0-9]{24}\.min\.(?:css|js)$ { try_files $uri =404; }
location ~ ^/(?:config|core|logs|backups|cache|vendor)(?:/|$) { return 403; }
location ~ ^/install/.+\.php$ { return 403; }
location ~ /\.(?!well-known/) { return 403; }
location ~ \.(?:bak|old|orig|save|swp|swo|tmp|dist)$ { return 403; }

# Private uploads (served only via /media-file) and importer sources/reports
location ~ ^/uploads/(?:member|import)(?:/|$) { return 403; }
location ~ ^/plugins/cms-importer/(?:reports|wp_import|wp_import_files)(?:/|$) { return 403; }

# Never execute scripts from uploads or asset folders
location ~ ^/(?:uploads|assets)/.*\.(?:php[0-9]?|phtml|phar)$ { return 403; }
```

Missing static files under `assets/`, `themes/`, `plugins/` and `uploads/` are answered by `.htaccess` with a plain 404 (since 3.5.0), so bot scans for old asset URLs no longer boot the CMS. Nginx equivalent (place after the blocking rules above):

```nginx
location ~* ^/(?:assets|themes|plugins|uploads)/.+\.(?:css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|bmp|woff2?|ttf|otf|eot|mp4|webm|mp3|ogg|wav|pdf|zip)$ {
    try_files $uri =404;
}
```

Nginx evaluates regex locations in order: keep these rules above your `location ~ \.php$` FastCGI handler and the `cache/optimized-assets` rule before the generic private-path rule.

**Deutsch**

### Apache

Voraussetzungen:

- `mod_rewrite` aktiv
- `AllowOverride All` für das Webroot
- Verzeichnisindexe deaktiviert

Die ausgelieferte `CMS/.htaccess` nutzt keine `<Directory>`-Blöcke (im `.htaccess`-Kontext unzulässig). Private Runtime-Pfade (`config/`, `core/`, `logs/`, `backups/`, `cache/`) und Installer-Interna werden über Rewrite-/Files-Regeln geschützt. `php_value`-Limits gelten nur unter `mod_php`; bei PHP-FPM/CGI müssen Upload-Limits in `php.ini`, Pool-Config oder Hosting-Panel gesetzt werden.

Der private Cache bleibt gesperrt, ausgenommen öffentliche CSS-/JS-Dateien von `AssetOptimizerService` unter `cache/optimized-assets/<hash>.min.css` und `cache/optimized-assets/<hash>.min.js`.

Öffentliche Uploads bleiben direkte URLs. Die `.htaccess` blockiert ausführbare Uploads und versteckte Dateien, lässt normale Dateien unter `uploads/` aber durch. Ausnahme: `uploads/member/` (private Mitglieder-Dateien, nur über `/media-file` mit Besitzerprüfung) und `uploads/import/` (Importer-Quellen) sind gesperrt. Für lokale FontManager-Schriften unter `uploads/fonts/` werden WOFF/WOFF2/TTF/OTF-MIME-Typen gesetzt, damit Browser sie auch mit `X-Content-Type-Options: nosniff` laden.

### Nginx

Unbekannte Pfade müssen bei `index.php` landen:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

`config/`, `logs/`, `cache/`, `backups/` und `core/` nicht als statische Dateien ausliefern. Nginx wertet keine `.htaccess` aus, deshalb müssen die privaten Pfade ausdrücklich nachgebildet werden:

```nginx
# Private Runtime-Pfade, gebündelte Libraries und Installer-Interna
location ~ ^/cache/optimized-assets/[a-f0-9]{24}\.min\.(?:css|js)$ { try_files $uri =404; }
location ~ ^/(?:config|core|logs|backups|cache|vendor)(?:/|$) { return 403; }
location ~ ^/install/.+\.php$ { return 403; }
location ~ /\.(?!well-known/) { return 403; }
location ~ \.(?:bak|old|orig|save|swp|swo|tmp|dist)$ { return 403; }

# Private Uploads (nur über /media-file) sowie Importer-Quellen/-Berichte
location ~ ^/uploads/(?:member|import)(?:/|$) { return 403; }
location ~ ^/plugins/cms-importer/(?:reports|wp_import|wp_import_files)(?:/|$) { return 403; }

# Skripte aus uploads/ und assets/ nie ausführen
location ~ ^/(?:uploads|assets)/.*\.(?:php[0-9]?|phtml|phar)$ { return 403; }
```

Fehlende statische Dateien unter `assets/`, `themes/`, `plugins/` und `uploads/` beantwortet die `.htaccess` seit 3.5.0 direkt mit einer schlanken 404, damit Bot-Scans nach alten Asset-URLs nicht jedes Mal das CMS starten. Nginx-Gegenstück (nach den Sperrregeln oben einfügen):

```nginx
location ~* ^/(?:assets|themes|plugins|uploads)/.+\.(?:css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|bmp|woff2?|ttf|otf|eot|mp4|webm|mp3|ogg|wav|pdf|zip)$ {
    try_files $uri =404;
}
```

Nginx prüft Regex-Locations in Reihenfolge: Diese Regeln über dem `location ~ \.php$`-FastCGI-Handler platzieren und die `cache/optimized-assets`-Regel vor der allgemeinen Sperrregel.

---

## First start | Erster Start

**English**

After configuration:

1. Open the production `SITE_URL`
2. 365CMS loads `CMS/config.php`
3. The stub loads `CMS/config/app.php`
4. `CMS\Bootstrap` detects mode (`web` / `admin` / `api` / `cli`) and initializes services and routing
5. `CMS\Database` and `CMS\SchemaManager` create the base schema (`v23`) if needed
6. If you used the installer, the first admin account is created in the installer (step 4 of 5)

Change the first admin password immediately after install.

**Deutsch**

Nach korrekter Konfiguration:

1. Browser auf die produktive `SITE_URL` öffnen
2. 365CMS lädt `CMS/config.php`
3. Der Stub lädt `CMS/config/app.php`
4. `CMS\Bootstrap` erkennt den Modus (`web` / `admin` / `api` / `cli`) und initialisiert Services und Routing
5. `CMS\Database` und `CMS\SchemaManager` legen bei Bedarf das Basisschema (`v23`) an
6. Beim Installer wird das erste Admin-Konto im Installer angelegt (Schritt 4 von 5)

Das erste Admin-Passwort nach der Installation sofort ändern.

---

## Production checklist | Produktions-Checkliste

**English**

- [ ] All placeholders in `CMS/config/app.php` replaced
- [ ] Strong `AUTH_KEY` / `SECURE_AUTH_KEY` / `NONCE_KEY`
- [ ] `CMS_DEBUG` is `false`
- [ ] HTTPS active
- [ ] `SITE_URL` has no trailing slash and no subdirectory
- [ ] `config/`, `logs/`, `cache/`, `backups/`, `core/` not publicly readable
- [ ] First admin password changed (minimum 12 characters at install time)
- [ ] `install.php` removed or locked down
- [ ] Backups scheduled
- [ ] Write access limited to `logs/`, `cache/`, `uploads/`, `backups/`

**Deutsch**

- [ ] Platzhalter in `CMS/config/app.php` vollständig ersetzt
- [ ] starke `AUTH_KEY` / `SECURE_AUTH_KEY` / `NONCE_KEY`
- [ ] `CMS_DEBUG` ist `false`
- [ ] HTTPS aktiv
- [ ] `SITE_URL` ohne Trailing Slash und ohne Unterverzeichnis
- [ ] `config/`, `logs/`, `cache/`, `backups/`, `core/` nicht öffentlich lesbar
- [ ] erstes Admin-Passwort geändert (Installer: mindestens 12 Zeichen)
- [ ] `install.php` entfernt oder abgesichert
- [ ] regelmäßige Backups eingerichtet
- [ ] Schreibrechte auf `logs/`, `cache/`, `uploads/`, `backups/` begrenzt

---

## Troubleshooting

**English**

### Redirect to installer or configuration

Usually `CMS/config/app.php` is missing or still contains placeholders such as `YOUR_DATABASE_USER`.

### Database connection fails

Check `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, user grants, and database reachability.

### Pretty URLs do not work

Check Apache rewrite or Nginx `try_files`, the web root, and `SITE_URL` (no typo, no trailing slash, no subdirectory).

### Admin loads but features are missing

Typical causes: missing PHP extensions (`curl`, `zip`, `gd`), directories that are not writable (`cache`, `logs`, `backups`, `uploads`), or a schema that was not migrated to `v23`.

Further reading:

- [System architecture](core/ARCHITECTURE.md)
- [Database schema](core/DATABASE-SCHEMA.md)
- [System & monitoring](admin/system-settings/SYSTEM.md)

**Deutsch**

### Weiterleitung auf Installer oder Konfiguration

Meist fehlt `CMS/config/app.php` oder enthält noch Platzhalter wie `YOUR_DATABASE_USER`.

### Datenbankverbindung schlägt fehl

`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, Rechte des Datenbankbenutzers und Erreichbarkeit prüfen.

### Pretty URLs funktionieren nicht

Apache-Rewrite bzw. Nginx-`try_files`, korrektes Webroot und `SITE_URL` prüfen (kein Tippfehler, kein Trailing Slash, kein Unterverzeichnis).

### Admin lädt, aber Teilfunktionen fehlen

Typische Ursachen: fehlende PHP-Erweiterungen (`curl`, `zip`, `gd`), nicht beschreibbare Verzeichnisse (`cache`, `logs`, `backups`, `uploads`) oder ein nicht auf `v23` migriertes Schema.

Weiterführend:

- [Systemarchitektur](core/ARCHITECTURE.md)
- [Datenbank-Schema](core/DATABASE-SCHEMA.md)
- [System & Monitoring](admin/system-settings/SYSTEM.md)

---

## Ergänzung (Stand 2026-10-02)

- **Konfigurationsreihenfolge:** `CMS/index.php` lädt `config/app.php` vor `config.php`. Eigene Konstanten (z. B. `JWT_SECRET`, Debug-Schalter) gehören deshalb in `config/app.php`; nach jedem erneuten Installer-Lauf prüfen, ob sie erhalten geblieben sind (siehe [core/STATUS.md](core/STATUS.md)).
- **Installer-Sperre:** Existiert bereits eine Konfiguration, schreibt der Installer eine Lock-Datei und beantwortet weitere Aufrufe mit HTTP 403 („blocked“). `install.php` trotzdem nach der Installation entfernen oder per Webserver sperren.
- **Mindest-PHP:** `CMS_MIN_PHP_VERSION = 8.4.1` (Prüfung im Willkommensschritt).
- **Cron:** `php CMS/cron.php --task=all` (CLI) oder HTTP-Aufruf mit Token (`?token=…&task=…`); Tasks `all`, `mail-queue`, `hourly`, `daily`, `feeds`, `cms_cron_*`. Details: [admin/system-settings/SYSTEM.md](admin/system-settings/SYSTEM.md).
- **Nach der Installation:** Mailversand testen ([admin/system-settings/MAIL.md](admin/system-settings/MAIL.md)), erstes Backup anlegen ([admin/system-settings/BACKUP.md](admin/system-settings/BACKUP.md)), Sicherheits-Audit ausführen ([admin/security/SECURITY-AUDIT.md](admin/security/SECURITY-AUDIT.md)).
- **Updates und Deployments:** [workflow/UPDATE-DEPLOYMENT-WORKFLOW.md](workflow/UPDATE-DEPLOYMENT-WORKFLOW.md).
