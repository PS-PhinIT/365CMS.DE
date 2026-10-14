# 365CMS – Gesamtaudit 2026-10-14 | Geschwindigkeit und Massenanfragen

> Übersicht: [README.md](README.md) · Vorheriger Stand: [../checks/PERFORMANCE.md](../checks/PERFORMANCE.md)

## English (summary)

Before this audit, every request ran idempotent schema checks (`SHOW TABLES`, `CREATE TABLE IF NOT EXISTS`, `SHOW INDEX`, `SHOW COLUMNS`) and opened a second PDO connection just to read the session lifetime. Now:

- Schema checks run once per code revision.
- The session lifetime is cached.
- Settings are read through `OptionStore`.
- Repeated category and hub-table lookups are memoised.

Queries per request dropped from 61 to 15 (home page), 63 to 17 (blog) and 89 to 17 (404 page).

Under mass requests, the firewall, the search, the 404 monitor and the Core Web Vitals endpoint could be abused to multiply database writes or CPU work. Analytics tables also had no retention. All of this now has limits, caps and a daily, batched cleanup.

## Deutsch

### Messung

**Umgebung:**

- MySQL 8.0 und PHP 8.3 (Entwicklungsserver), Neuinstallation mit Beispielinhalten.
- Abfragen aus dem MySQL-General-Log je einzelnem Seitenaufruf, anonymer Besucher, zweiter Aufruf (warmer Datei-Cache).

| Seite | vorher | nachher | Wichtigste entfallene Abfragen |
|---|---:|---:|---|
| `/` | 61 | 15 | `SHOW TABLES LIKE …`, `CREATE TABLE IF NOT EXISTS …` (Firewall, SEO-Meta, Redirects, Tracking, Web-Vitals), `SHOW INDEX`, zweite Verbindung samt `SELECT … settings` |
| `/blog` | 63 | 17 | wie oben, zusätzlich doppelte Kategorienabfrage des Themes |
| 404 | 89 | 17 | wie oben, zusätzlich Einzelabfragen der Router-Cache-Einstellungen und der 404-Protokollierung |

Nach den Änderungen läuft keine DDL-Anweisung mehr im normalen Request (geprüft im General-Log).

### Geschwindigkeit

| ID | Schwere | Befund | Korrektur |
|---|---|---|---|
| PERF-07 | 🔴 | **Schemaprüfungen in jedem Request.** `SecurityRuntimeService`, `SeoMetaRepository`, `RedirectService`, `PageManager`, `TrackingService`, `CoreWebVitalsService` und `SchemaManager::ensureRuntimeSchema()` führten bei jedem Aufruf `SHOW TABLES`, `CREATE TABLE IF NOT EXISTS`, `SHOW COLUMNS` und `SHOW INDEX` aus. Allein `ensureRuntimeSchema()` kostete 11 `CREATE TABLE`, 2 `ALTER TABLE … MODIFY` und rund 27 `information_schema`-Abfragen. Unter Last erzeugt das Metadaten-Sperren und dominiert die Abfragezahl. | Neue Methode `Database::runSchemaOnce($key, $sourceFile, $callback)`. Die Prüfung läuft einmal je Codestand (CRC der Quelldatei); als Merker dient ein Flag unter `cache/db_runtime_<key>_<prefix><crc>.flag`. `SchemaManager` nutzt dafür ein eigenes Flag (`ensureRuntimeSchemaOnce()`). Schlägt die Prüfung fehl, wird kein Flag geschrieben, und sie läuft beim nächsten Request erneut. Die Datenbank-Reparatur (`SchemaManager::clearFlag()`) löscht alle Flags. |
| PERF-08 | 🟠 | **Zweite Datenbankverbindung.** `index.php` öffnete vor dem Session-Start eine eigene PDO-Verbindung, nur um die Session-Lebensdauer aus `settings` zu lesen. | Der Wert wird 10 Minuten unter `cache/session_gc_lifetime.cache` gehalten. *Performance → Sitzungen* löscht die Datei beim Speichern. |
| PERF-09 | 🟡 | **Einzelabfragen auf `settings`.** `Router::resolvePublicHtmlCacheTtl()` und die Firewall-Einstellungen lasen Optionen mit eigenen `SELECT`s am Options-Cache vorbei. | `OptionStore::getMany()`. |
| PERF-10 | 🟡 | **Wiederholte Abfragen im Theme.** `meridian_get_categories()` fragte je Aufruf (Header, Sidebar, Footer) neu ab. `SiteTableRepository` lud Hub-Tabellen mehrfach. | Statischer Request-Cache (`meridian_load_categories()`), `hubRowsCache` und `hubBySlugCache`. |
| PERF-11 | ⚪ | **`SELECT *` in der Seiten-Suche.** Lud alle Spalten (inkl. EN-Fassung und Meta-JSON). | Projektion auf die benötigten Spalten. |

### Massenanfragen und Bot-Last

**Ziel der Prüfung:** Kein öffentlicher Endpunkt darf je Anfrage unbegrenzt Datenbankzeilen schreiben, teure Volltextarbeit ohne Limit ausführen oder Tabellen dauerhaft wachsen lassen.

| ID | Schwere | Befund | Korrektur |
|---|---|---|---|
| LAST-01 | 🟠 | **Schreibverstärkung in der Firewall.** Jede Anfrage einer bereits gesperrten IP schrieb eine Zeile in `security_log` **und** eine in `audit_log`. Ein Flood mit 1.000 Anfragen pro Sekunde erzeugte so 2.000 INSERTs pro Sekunde. Die Rate-Limit-Zählung lief ohne passenden Index über `security_log`. Die Bereinigung löschte alles in einem einzigen DELETE und sperrte damit die Tabelle. | Höchstens ein Eintrag je IP, Regel und Minute. Index `idx_ip_created (ip_address, created_at)`. Bereinigung in Blöcken (`LIMIT 5000`). |
| LAST-02 | 🟠 | **Suche ohne Rate-Limit.** Jede Suche führt `LIKE '%…%'` über Titel und Inhalt (LONGTEXT) von Beiträgen und Seiten aus. Ein Bot konnte damit die Datenbank-CPU auslasten. | 30 Suchen je 60 Sekunden und IP (`site_search` in `login_attempts`). Darüber hinaus antwortet die Suche mit **429** und `Retry-After`, das Theme zeigt einen Hinweis. Laufzeittest: 29 × 200, danach 429. |
| LAST-03 | 🟡 | **Web-Vitals-Endpunkt.** Der Datei-basierte Limiter las und schrieb ohne Sperre (Race Condition unter Parallelität) und zählte nur je Fingerprint aus IP, Session und User-Agent. Mit wechselndem User-Agent war das Limit damit wirkungslos. Bei voller Cache-Datei wurde zurückgesetzt (fail-open). | `flock`-geschütztes Lesen und Schreiben. Zusätzlich ein IP-Kontingent (40 × 3 je Minute). Ist die Datei voll, wird abgelehnt (fail-closed). Cache bis 256 KB. Client-IP über `getClientIp()`. |
| LAST-04 | 🟠 | **404-Monitor unbegrenzt.** Jede zufällige URL wurde zu einer eigenen Zeile in `not_found_logs`, ohne Längenbegrenzung des Pfads und ohne Obergrenze. Ein Scanner füllte die Tabelle beliebig. | Pfad auf 255 Zeichen gekürzt, Host validiert. Zuerst `UPDATE` des Zählers für bekannte Pfade. Neue Pfade nur bis 20.000 Zeilen, dann wird nur noch gezählt. Fehler werden geloggt statt geworfen. `pruneNotFoundLogs()` mit 90 Tagen. Laufzeittest: ein überlanger Pfad wird auf 255 Zeichen gekürzt gespeichert. |
| LAST-05 | 🟠 | **Keine Aufbewahrungsfristen.** `page_views`, `core_web_vitals`, `not_found_logs` und die Tageslogdateien wuchsen unbegrenzt. Das betrifft Speicher, Backup-Dauer und Datensparsamkeit (Art. 5 Abs. 1 lit. e DSGVO). | Neuer `DataRetentionService` am täglichen Cron (`cms_cron_daily`, Priorität 40). Fristen: 365 / 90 / 90 / 30 Tage. Gelöscht wird in Blöcken zu 5.000 Zeilen, höchstens 40 Blöcke je Lauf. Anpassbar über den Filter `cms_data_retention_days` (0 = nicht löschen, sonst 7–3650 Tage). |
| LAST-06 | 🟡 | **Session-Sperre beim Medien-Streaming.** `MediaDeliveryService` streamte Dateien bei offener Session. Alle weiteren Requests desselben Besuchers (Seitenaufbau, AJAX) warteten, bis der Download fertig war. | `session_write_close()` vor dem Streaming. |

### Bereits vorhanden (geprüft)

- **Rate-Limits:** Login, Passwort-Reset, Registrierung, Kontaktformular, API (DB-basiert, zeitzonenfest seit 3.4.13).
- **Seiten-Cache:** HTML-Cache für öffentliche Seiten (`Router::resolvePublicHtmlCacheTtl`).
- **Sitemaps:** Bundle als Datei, wird nur bei Änderungen neu erzeugt.
- **Medien:** Auslieferung mit `Last-Modified` und Range-Unterstützung.

### Offene Empfehlungen

Siehe [README.md](README.md):

- OFFEN-01: Session für jeden anonymen Besucher – größter verbleibender Hebel für CDN- und Proxy-Caching.
- OFFEN-02: Suche mit FULLTEXT-Index.
- OFFEN-03: Feed-Cache.
- OFFEN-04: statische 404 für Assets.

Für Lastspitzen zusätzlich empfohlen:

- Rate-Limiting am Webserver bzw. CDN (`limit_req` in nginx, WAF-Regeln) vor PHP.
- OPcache mit `opcache.validate_timestamps=0` in Produktion.
- Persistenter Objekt-Cache für `OptionStore`, falls viele Instanzen laufen.
