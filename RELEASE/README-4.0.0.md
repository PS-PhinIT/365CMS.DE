# 365CMS – Projektdokumentation | Abschnitt: Release-Pakete 4.0.0

## English

This release directory documents the distributable `4.0.0` packages (release date 2026-11-12, database schema `v25`). Both packages contain deployable code only. Installation-specific configuration, uploads, caches, logs and backups are intentionally excluded; the full package ships only the protective `.htaccess`/index files of those directories.

Security and bug-fix release on top of 3.5.6, based on the follow-up audit of 2026-11-08 (`DOC/audit/FOLGEAUDIT-2026-11-08.md`, 44 findings, all fixed). A second pass on 2026-11-09 (`DOC/audit/FOLGEAUDIT-2026-11-09.md`) implemented the recommendations OFFEN-20 … OFFEN-26 and fixed six further defects that had existed since 3.5.x. The update is marked as critical; install it promptly. The release also ships the redesigned administration described below.

Fixed, among others:

- host header manipulation of script and asset URLs (cache poisoning);
- MFA enrolment without the password;
- access secrets in the GDPR export;
- a bypassable installer lock;
- SVG import through the WordPress importer (stored XSS);
- plugin dashboards that granted access for unknown capabilities;
- passkey sign-ins without user verification that skipped TOTP;
- user enumeration through login timing;
- restores that accepted modified or incomplete backups;
- copies of `config/app.php` (with database credentials) on every settings save, unlimited and readable by other local accounts.

Bundled libraries were updated: DOMPurify 3.4.17, symfony/mime 8.1.10, symfony/yaml 8.1.11 and symfony/serializer 8.1.11.

The option *E-Mail-Verifizierung erzwingen* now takes effect: new registrations stay `unverified` until the confirmation link is opened. Also fixed: creating users under `/admin/users` failed on MySQL/MariaDB in strict mode, and the `critical` flag of update manifests was ignored.

Backups and status displays were reviewed as well. The security audit no longer reports existing backups as missing, and it warns while the firewall or anti-spam is switched off. Large sites can restore their own file backups again. The firewall rate limit no longer locks out signed-in administrators or IPs on the allow list. Dashboard, diagnostics and SEO pages no longer show wrong values for failed logins, HTTPS, sitemaps and `robots.txt`. See `Changelog.md` (v4.0.0).

Added in the second pass:

- authenticated encryption of stored secrets and TOTP secrets with a key ring (`CMS_ENCRYPTION_KEY`, `CMS_ENCRYPTION_KEYS_PREVIOUS`);
- e-mail changes take effect only after the new address is confirmed;
- "sign out everywhere" ends all other sessions and all API tokens (JWT);
- registration no longer reveals registered e-mail addresses;
- login throttling per account (1 to 30 s after five failed attempts);
- backup directory outside the web root (`CMS_BACKUP_PATH`) and optional backup encryption.

Also fixed: plain-text mails sent as HTML, missing header and transfer encoding in the `mail()` fallback, dashboard links to disabled modules, exception texts in theme helpers, and the directly reachable template `/member/plugin-section`.

Redesigned administration:

- calm, light layout with design tokens mapped onto Tabler, locally bundled fonts (Inter, Source Serif 4, JetBrains Mono) and an accent colour per site;
- collapsible sidebar: site switcher (phinit.de, 365network.de), collapsible groups whose state is stored per user, a 76 px icon rail with tooltips and flyouts, and an off-canvas drawer below 992 px;
- a "Plugins" group with one entry per plugin; *Einstellungen › Plugins › Plugin-Zuweisung* controls in which sites a plugin's menu appears;
- new post editor with a publish checklist (featured image, SEO description of 120–160 characters, category; also enforced on the server), focus mode (Ctrl+Shift+F), autosave and a revision history with block diff and restore;
- system notices for administrators on the sidebar avatar: failed cron run, backup older than 24 hours, available core or plugin updates.

Plugin menu registration (`cms_admin_menu`, `add_menu_page()`, `add_submenu_page()`) is unchanged; existing plugins work without modification. All existing admin URLs keep working.

Database schema `v24` adds six columns to `cms_post_revisions` (`revision_type`, `created_by`, `featured_image`, `meta_title`, `meta_description`, `fingerprint`) for revision compare, restore and duplicate detection. Existing revisions are kept and marked `legacy`; no data is rewritten. User preferences (`cms_user_meta`, key `admin_ui_prefs`) and plugin assignments (`cms_settings`, options `admin_plugin_site_assignments` and `admin_sites`) use existing tables.

Core functions formerly provided by plugins, and new content tools (schema `v25`):

- **Forms** (formerly the plugin cms-contact): builder with 15 field types, per-form mails, spam levels and retention, Editor.js block, `/contact/<key>`, submissions with CSV export, file uploads in private storage, GDPR export/deletion and a member page.
- **Downloads** (formerly cms-downloads): versions, categories, access levels, statistics without personal data, Editor.js block, archive and detail pages, controlled delivery from private storage.
- **Feed aggregator** (formerly cms-feed): sources, articles, categories, e-mail digests, member subscriptions, OPML import/export, public pages `/feeds`, fetching by cron with SSRF checks; RSS output settings.
- **Editorial calendar**, **trash** for pages, posts, media, hub sites, downloads, forms and submissions (with automatic emptying), **recent changes**, **translation status**, **search index** management with anonymous search-term statistics, **content import/export** as ZIP, **retention periods** overview with dry run, **media tools** (regenerate thumbnails, credit output), **image credits** (author, licence, source, AI-generated; caption credit line and Schema.org), **accessibility statement**, adjustable **login thresholds**, **cache & maintenance** menu in the top bar and an **environment overview** under System → Info.
- The plugins cms-contact, cms-downloads and cms-feed are deactivated automatically; their data is migrated in blocks on admin requests and by the daily cron (without IP addresses and user agents).

Schema `v25` adds the tables `admin_menu_layouts`, `batch_jobs`, `form_*`, `downloads_*`, `feeds_*`, `translation_sync`, `search_queries`, `search_term_daily` and `trash_media`, trash columns in `posts`, `pages` and `site_tables`, `planned_at` in `posts`/`pages` and `created_by` in `page_revisions`. New installation and update from `v24` produce the same structure (checked); no data is rewritten.

## Deutsch

Dieses Release-Verzeichnis dokumentiert die auslieferbaren Pakete `4.0.0` (Release 12.11.2026, Datenbankschema `v25`). Update- und Vollpaket enthalten ausschließlich auslieferbaren Code; installationsspezifische Konfiguration, Uploads, Cache, Logs und Backups sind bewusst ausgeschlossen. Das Vollpaket enthält aus diesen Verzeichnissen nur die schützenden `.htaccess`-/Index-Dateien.

Sicherheits- und Korrekturrelease auf Basis von 3.5.6 nach dem Folgeaudit vom 08.11.2026 (`DOC/audit/FOLGEAUDIT-2026-11-08.md`, 44 Befunde, alle behoben). Ein zweiter Durchgang am 09.11.2026 (`DOC/audit/FOLGEAUDIT-2026-11-09.md`) setzt die Empfehlungen OFFEN-20 bis OFFEN-26 um und behebt sechs weitere Fehler, die seit 3.5.x bestanden. Das Update ist als kritisch markiert und sollte zeitnah installiert werden. Installationen mit 3.5.6 zeigen es noch als normales Update an, weil die rote Kennzeichnung kritischer Updates erst mit 4.0.0 kommt. Außerdem enthält das Release die unten beschriebene neue Administration.

Behoben wurden unter anderem:

- manipulierbarer Host-Header in Script- und Asset-URLs (Cache-Poisoning);
- MFA-Einrichtung ohne Passwort;
- Zugangsgeheimnisse im DSGVO-Export;
- umgehbare Installer-Sperre;
- SVG-Import über den WordPress-Importer (gespeichertes XSS);
- Plugin-Dashboards, die unbekannte Capabilities freigaben;
- Passkey-Anmeldungen ohne User Verification, die TOTP übersprangen;
- Benutzer-Enumeration über die Antwortzeit der Anmeldung;
- Wiederherstellung veränderter oder unvollständiger Backups;
- Kopien von `config/app.php` (mit DB-Zugangsdaten) bei jedem Speichern der Einstellungen, unbegrenzt und für andere lokale Konten lesbar.

Aktualisierte Bibliotheken: DOMPurify 3.4.17, symfony/mime 8.1.10, symfony/yaml 8.1.11 und symfony/serializer 8.1.11.

Die Option *E-Mail-Verifizierung erzwingen* (`member_email_verification`) wirkt jetzt: Neue Registrierungen bleiben `unverified`, bis der Link aus der Bestätigungsmail geöffnet wurde. Außerdem behoben: „Benutzer anlegen“ unter `/admin/users` scheiterte auf MySQL/MariaDB im Strict-Mode, und das Feld `critical` im Update-Manifest wurde nicht ausgewertet.

Außerdem wurden Backups und Statusanzeigen geprüft. Das Sicherheits-Audit meldet vorhandene Backups nicht mehr als fehlend und warnt, solange Firewall oder Anti-Spam ausgeschaltet sind. Große Installationen können ihre Datei-Backups wieder einspielen. Das Rate-Limit der Firewall sperrt angemeldete Administratoren und freigegebene IPs nicht mehr aus. Dashboard, Diagnose und SEO-Seiten zeigen keine falschen Werte mehr zu fehlgeschlagenen Anmeldungen, HTTPS, Sitemaps und `robots.txt`. Details: `Changelog.md` (v4.0.0).

Neu im zweiten Durchgang:

- authentifizierte Verschlüsselung gespeicherter Geheimnisse und der TOTP-Secrets mit Schlüsselring (`CMS_ENCRYPTION_KEY`, `CMS_ENCRYPTION_KEYS_PREVIOUS`);
- geänderte E-Mail-Adressen gelten erst nach Bestätigung der neuen Adresse;
- „Überall abmelden“ beendet alle anderen Sitzungen und alle API-Tokens (JWT);
- die Registrierung verrät keine vorhandenen E-Mail-Adressen mehr;
- Anmeldeverzögerung je Konto (1 bis 30 s nach fünf Fehlversuchen);
- Backup-Verzeichnis außerhalb des Webroots (`CMS_BACKUP_PATH`) und optionale Backup-Verschlüsselung.

Außerdem behoben: Klartext-Mails wurden als HTML versendet, beim Versand über `mail()` fehlten Header- und Transfer-Kodierung, das Dashboard verlinkte deaktivierte Module, Theme-Helfer zeigten Exception-Texte, und die interne Vorlage `/member/plugin-section` war direkt aufrufbar.

Neue Administration:

- ruhiges, helles Layout mit Design-Tokens auf Basis der Tabler-Variablen, lokal ausgelieferten Schriften (Inter, Source Serif 4, JetBrains Mono) und einer Akzentfarbe je Site;
- einklappbare Seitenleiste: Site-Umschalter (phinit.de, 365network.de), einklappbare Gruppen mit gespeichertem Zustand je Benutzer, eingeklappt eine 76 px schmale Symbolspalte mit Tooltips und Flyouts, unter 992 px ein ausklappbares Menü (Drawer);
- Gruppe „Plugins“ mit einem Eintrag je Plugin; unter *Einstellungen › Plugins › Plugin-Zuweisung* wird festgelegt, in welchen Sites ein Plugin-Menü erscheint;
- neuer Beitragseditor mit Veröffentlichungs-Checkliste (Beitragsbild, SEO-Beschreibung mit 120–160 Zeichen, Kategorie; auch serverseitig geprüft), Fokusmodus (Strg+Umschalt+F), Autosave und Revisionsverlauf mit Block-Vergleich und Wiederherstellung;
- Systemhinweise für Administratoren am Avatar der Seitenleiste: fehlgeschlagener Cron-Lauf, Backup älter als 24 Stunden, verfügbare Core- oder Plugin-Updates.

Die Registrierung von Plugin-Menüs (`cms_admin_menu`, `add_menu_page()`, `add_submenu_page()`) ist unverändert; bestehende Plugins funktionieren ohne Anpassung. Alle bisherigen Admin-URLs bleiben gültig.

Datenbankschema `v24`: Die Migration ergänzt in `cms_post_revisions` die Spalten `revision_type`, `created_by`, `featured_image`, `meta_title`, `meta_description` und `fingerprint` für Vergleich, Wiederherstellung und Duplikaterkennung von Revisionen. Vorhandene Revisionen bleiben erhalten und werden als `legacy` gekennzeichnet; Daten werden nicht umgeschrieben. Benutzer-Präferenzen (`cms_user_meta`, Schlüssel `admin_ui_prefs`) und Plugin-Zuweisungen (`cms_settings`, Optionen `admin_plugin_site_assignments` und `admin_sites`) nutzen vorhandene Tabellen.

Bisher als Plugin gelieferte Funktionen im Core und neue Inhaltswerkzeuge (Schema `v25`):

- **Formulare** (bisher Plugin cms-contact): Baukasten mit 15 Feldtypen, Mails je Formular, Spam-Stufen und Löschfrist, Editor.js-Block, `/contact/<schlüssel>`, Eingänge mit CSV-Export, Datei-Uploads im privaten Speicher, DSGVO-Auskunft und -Löschung sowie Seite im Mitgliederbereich.
- **Downloads** (bisher cms-downloads): Versionen, Kategorien, Zugriffsstufen, Statistik ohne Personendaten, Editor.js-Block, Archiv- und Detailseiten, kontrollierte Auslieferung aus dem privaten Speicher.
- **Feed-Aggregator** (bisher cms-feed): Quellen, Artikel, Bereiche, E-Mail-Digests, Mitglieder-Abos, OPML-Import/-Export, öffentliche Seiten `/feeds`, Abruf per Cron mit SSRF-Prüfung; Einstellungen der RSS-Ausgabe.
- **Redaktionskalender**, **Papierkorb** für Seiten, Beiträge, Medien, Hub-Sites, Downloads, Formulare und Eingänge (mit automatischem Leeren), **Letzte Änderungen**, **Übersetzungsstatus**, **Suchindex**-Verwaltung mit anonymer Suchbegriff-Statistik, **Import/Export** von Inhalten als ZIP, **Löschfristen**-Übersicht mit Probelauf, **Medien-Werkzeuge** (Vorschaubilder neu erzeugen, Ausgabe der Urheberangaben), **Urheberangaben** (Urheber, Lizenz, Quelle, KI-generiert; Credit-Zeile und Schema.org), **Erklärung zur Barrierefreiheit**, einstellbare **Login-Grenzwerte**, Menü **Cache & Wartung** in der oberen Leiste und **Umgebungsinfo** unter System → Info.
- Die Plugins cms-contact, cms-downloads und cms-feed werden automatisch deaktiviert; ihre Daten übernimmt der Core in Blöcken bei Admin-Aufrufen und im täglichen Cron (ohne IP-Adressen und User-Agents).

Schema `v25` ergänzt die Tabellen `admin_menu_layouts`, `batch_jobs`, `form_*`, `downloads_*`, `feeds_*`, `translation_sync`, `search_queries`, `search_term_daily` und `trash_media`, Papierkorb-Spalten in `posts`, `pages` und `site_tables`, `planned_at` in `posts`/`pages` und `created_by` in `page_revisions`. Neuinstallation und Update von `v24` ergeben dieselbe Struktur (geprüft); Daten werden nicht umgeschrieben.

### Dateien

- `365CMS-4.0.0-update.zip`: vollständiges Core-Updatepaket für den automatischen Core-Updater (Wurzelordner `CMS/`).
- `365CMS-4.0.0-full.zip`: sauberes Gesamtpaket für eine Neuinstallation. Nach dem Entpacken `CMS/install.php` aufrufen.
- `365CMS-4.0.0-update.json`: Update-Manifest inklusive SHA-256 für das Updatepaket.
- `365CMS-4.0.0-SHA256SUMS.txt`: Prüfsummen beider ZIP-Dateien.

### Bereitstellung des automatischen Updates

Das Updatepaket muss unter folgendem Pfad erreichbar sein:

`https://365cms.de/marketplace/core/365cms/365CMS-4.0.0-update.zip`

Die Datei `CMS/marketplace/core/365cms/update.json` muss am selben Marketplace-Pfad liegen. Nach dem Core-Update den Adminbereich neu laden und unter **Admin → Updates** das idempotente Datenbank-Update ausführen (Schema bis `v25`: neue Spalten in `cms_post_revisions`, `posts`, `pages`, `site_tables`, `page_revisions` und die neuen Tabellen; die installierte Version wird auf `4.0.0` gesetzt). Fehlende Tabellen und Spalten legen die Dienste beim ersten Aufruf auch selbst an.

Die automatische Installation setzt eine gültige SHA-256-Prüfsumme im Manifest voraus. Der Core-Swap bewahrt `config/`, `config.php`, `uploads/`, `cache/`, `logs/`, `backups/` und den privaten Speicher `private/`.

### Nach dem Update prüfen

- **Hostname / Proxy:** Script- und Asset-URLs verwenden nur noch den Host aus `SITE_URL`, dessen `www`-Variante oder eine Hub-Domain. `SITE_URL` muss die öffentliche Adresse enthalten. Hinter Reverse-Proxy oder CDN gelten `X-Forwarded-Host` und `X-Forwarded-Proto` nur, wenn der Proxy als vertrauenswürdig eingetragen ist (*Sicherheit → Firewall → Proxy / CDN & Client-IP* oder `CMS_TRUSTED_PROXIES`).
- **Cache:** Seiten- und CDN-Cache leeren, damit keine vorher manipulierten Einträge ausgeliefert werden.
- **MFA:** Das Einrichten einer Authenticator-App verlangt jetzt das aktuelle Passwort. Bei aktiver MFA verlangen Passkeys ohne PIN/Biometrie zusätzlich den TOTP-Code.
- **E-Mail-Verifizierung:** Vor dem Einschalten der Option den Mailversand prüfen. Bei aktiver Mail-Warteschlange muss der Cron laufen. Bestehende Konten bleiben unverändert; unbestätigte Konten lassen sich in der Benutzerliste über *Aktivieren* freischalten.
- **Benutzer anlegen:** Unter `/admin/users` einen Testbenutzer anlegen. Das Speichern muss ohne „Datenbankfehler beim Erstellen“ gelingen.
- **Plugin-Dashboards:** Bereiche mit unbekannter `capability` sind nicht mehr für alle Mitglieder sichtbar. Plugins verwenden Rollennamen (`member`, `editor`, `admin`) oder echte Capabilities.
- **Installer:** `config/install.lock` muss vorhanden sein; `install.php` bleibt dann gesperrt.
- **Importer:** Vor 4.0.0 importierte SVG-Dateien unter `uploads/images/importer/` prüfen und bei Bedarf entfernen.
- **Sicherheits-Audit:** *Sicherheit → Audit* erneut ausführen. Neue Warnungen zu Firewall oder Anti-Spam bedeuten, dass der Schutz ausgeschaltet ist (Standard nach der Installation).
- **Anti-Spam:** Wurde das Formular vor 4.0.0 gespeichert, Honeypot und User-Agent-Prüfung kontrollieren; sie wurden dabei ausgeschaltet.
- **Backups:** Unter *System → Backup & Restore* als „Unvollständig“ markierte Backups prüfen und löschen. Datei-Backups enthalten `assets/` nicht mehr. Unter nginx `backups/`, `logs/` und `config/` per Server-Regel sperren.
- **Verschlüsselungsschlüssel:** Das Update ändert `config/app.php` nicht; Geheimnisse werden bis dahin mit dem aus `AUTH_KEY`/`SECURE_AUTH_KEY` abgeleiteten Schlüssel verschlüsselt. Empfohlen ist ein eigener Schlüssel: `defined('CMS_ENCRYPTION_KEY') || define('CMS_ENCRYPTION_KEY', '…');` mit 64 Hex-Zeichen (z. B. aus `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`). Gespeicherte Geheimnisse werden beim nächsten Aufruf umgeschlüsselt. Den Schlüssel getrennt von Datenbank und Backups sichern; ohne ihn sind verschlüsselte Einstellungen, TOTP-Secrets und Backups verloren. Für einen späteren Schlüsselwechsel den bisherigen Schlüssel in `CMS_ENCRYPTION_KEYS_PREVIOUS` eintragen.
- **Backup-Speicherort:** `CMS_BACKUP_PATH` außerhalb des Webroots setzen und unter *System → Backup & Restore* die Verschlüsselung einschalten (PHP-Erweiterung sodium). Ältere Backups unter `CMS/backups/` per SFTP/SSH verschieben oder löschen; das Sicherheits-Audit nennt ihre Anzahl.
- **E-Mail-Änderung:** Geänderte Adressen gelten erst nach dem Bestätigungslink. Bei aktiver Mail-Warteschlange muss der Cron laufen. Admins können die Bestätigung unter *Benutzer → Bearbeiten* überspringen.
- **Registrierung und Anmeldung:** Für eine vollständig neutrale Registrierung *E-Mail-Verifizierung erzwingen* einschalten. Nach fünf Fehlversuchen in 15 Minuten verzögert die Anmeldung für das betroffene Konto um bis zu 30 s.
- **API-Tokens:** „Überall abmelden“ beendet auch alle JWTs des Kontos; Integrationen fordern danach ein neues Token an.
- **Konfigurationskopien:** Unter `config/` bleiben nur die fünf neuesten `app.backup-*.php`; ältere entfernt das nächste Speichern mit Änderung. Kopien aus Versionen vor 3.4.12 (`app.php.backup.*`) manuell löschen.
- **Neue Seitenleiste:** Die Menüs sind neu gruppiert (Übersicht, Inhalte, Benutzer, Gestaltung & Marketing, Sicherheit & Recht, Plugins, Verwaltung); alle bisherigen Admin-URLs bleiben gültig. Aufgeklappte Gruppen und die eingeklappte Seitenleiste speichert jeder Benutzer selbst.
- **Sites:** Der Site-Umschalter zeigt standardmäßig `phinit.de` und `365network.de`. Andere Installationen hinterlegen ihre Sites in der Option `admin_sites` (JSON-Liste mit `key`, `label`, optional `url`, `accent`, `hosts`; siehe `DOC/core/DATABASE-SCHEMA.md`).
- **Plugin-Zuweisung:** Plugins ohne gespeicherte Zuweisung erscheinen in allen Sites, nach dem Update fehlt also kein Menüeintrag. Unter *Einstellungen › Plugins › Plugin-Zuweisung* (`/admin/plugin-assignment`) festlegen, in welchen Sites ein Plugin-Menü erscheint. Direkt aufgerufene Plugin-Seiten bleiben erreichbar und zeigen in nicht zugewiesenen Sites einen Hinweis.
- **Veröffentlichungs-Checkliste:** Veröffentlichen und Planen verlangen jetzt Beitragsbild, SEO-Beschreibung mit 120–160 Zeichen und Kategorie – im Editor, bei der Bulk-Aktion „Veröffentlichen“ (unvollständige Beiträge werden übersprungen) und beim Wiederherstellen einer Revision eines geplanten Beitrags. Veröffentlichte Beiträge lassen sich ohne Checkliste weiter bearbeiten. Bereits geplante Beiträge erscheinen unverändert zum geplanten Zeitpunkt, verlangen beim nächsten Speichern aber die vollständige Checkliste.
- **Revisionen:** Je Beitrag bleiben die letzten 30 Revisionen. Bis 4.0.0 war der Verlauf unbegrenzt; die erste neue Revision eines Beitrags nach dem Update kürzt seinen Verlauf auf 30 Einträge. Wer ältere Stände braucht, sichert vorher die Datenbank.
- **Systemhinweise:** Administratoren (`manage_system`) sehen am Avatar einen Punkt, wenn der letzte Cron-Lauf fehlschlug, kein Backup vorhanden oder das letzte älter als 24 Stunden ist, oder Core- bzw. Plugin-Updates bereitstehen. Der Cron-Status wird erst ab 4.0.0 erfasst, ein Cron-Hinweis erscheint also frühestens nach dem ersten fehlgeschlagenen Lauf. Die Hinweise werden fünf Minuten zwischengespeichert.
- **Cron:** Feed-Abruf, Digests, Löschfristen, Papierkorb und das Fortsetzen von „Vorschaubilder neu erzeugen“ laufen über `cron.php`. Den Cron mindestens stündlich ausführen.
- **Privater Speicher:** Formular-Anhänge, geschützte Downloads, Medien im Papierkorb und Exporte liegen in `CMS/private/` oder unter `CMS_PRIVATE_PATH`. Empfohlen ist ein Ort außerhalb des Webroots; unter nginx `private/` per Server-Regel sperren.
- **Plugins cms-contact, cms-downloads, cms-feed:** Werden beim Laden automatisch deaktiviert (nicht gelöscht). Ihre Daten übernimmt der Core bei Admin-Aufrufen in Blöcken von je 5 Sekunden und im täglichen Cron, bis alles übernommen ist; der Stand erscheint als Systemhinweis. Downloads mit Anmeldepflicht wandern dabei in den privaten Speicher. Danach können die Plugin-Ordner entfernt werden.
- **Thumbnails:** Wurden die Bildgrößen geändert, unter *Medien → Medien-Werkzeuge* die Vorschaubilder neu erzeugen.
- **OPcache:** Falls weiterhin der alte Stand ausgeführt wird, OPcache bzw. PHP-FPM neu starten (Hoster-Panel).
- **Upgrade von 3.4.x:** Zusätzlich die Hinweise aus `RELEASE/README-3.5.0.md` beachten (Proxy/CDN, Lazy Sessions, JWT-Bindung, nginx-Regel).
