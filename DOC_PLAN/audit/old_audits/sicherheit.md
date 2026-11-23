# Sicherheit

**Bereichsscore:** 92/100

**Audit-Scope:** Für 365CMS-Core-Bewertungen zählt ausschließlich `365CMS.DE/CMS/**`; `TESTS/**` dient als Validierungsnachweis.

## Kurzfazit
Die Security-Findings sind im Audit-Scope abgeschlossen und durch eine reproduzierbare `security-baseline`-Suite abgesichert. Belegbare Schutzmechanismen umfassen CSRF-Token, CSP/HSTS/COOP/CORP/Permissions-Policy, Upload-MIME- und Content-Prüfungen, Import-Payload-Härtung, SQL-Identifier-Guards, Header-first Web-Cron-Token und eine abgeschlossene Request-Migration im Admin-Modul-Scope. Verbleibende direkte Superglobal-Nutzung außerhalb der kritischen Security-Hotspots wird als Wartbarkeits-Backlog unter MAINT-002 weitergeführt.

## Score-Begründung
- Startwert 100
- SEC-001: -3 (abgeschlossen, Restangriffsfläche Upload bleibt naturgemäß zu überwachen)
- SEC-002: -1 (abgeschlossen, administrativer Wartungspfad bleibt sensitiv)
- SEC-003: -4 (Security-Scope abgeschlossen; breite Restmigration ist MAINT-002)
- SEC-004: -0 (abgeschlossen)

## Findings-Tabelle
| ID | Modul | Feature | Funktion | Schweregrad | Auswirkung | Fundstelle | Quelle |
|---|---|---|---|---|---:|---|---|
| SEC-001 | Media/Importer | Datei-Upload | Upload/Import | niedrig | -3 | CMS\core\Services\MediaService.php, CMS\plugins\cms-importer\includes\class-admin.php, TESTS\security-baseline\run.php | Codefund/Test |
| SEC-002 | System | Tabellenwartung | REPAIR/OPTIMIZE | niedrig | -1 | CMS\core\Services\SystemService.php, TESTS\security-baseline\run.php | Codefund/Test |
| SEC-003 | Admin/Runtime | Request-Verarbeitung | GET/POST/FILES/SERVER | niedrig | -4 | CMS\core\Http\Request.php, CMS\admin\modules\**, TESTS\security-baseline\run.php | Heuristik/Codefund/Test |
| SEC-004 | Cron | Web-Cron Token | Token in Query/Header | abgeschlossen | 0 | CMS\cron.php, TESTS\security-baseline\run.php | Codefund/Test |

## Umsetzungsschritte

## Umsetzungsstand (2026-06-13)
- ✅ **SEC-001 (Upload/Import-Härtung) umgesetzt**
	- Zentrale Upload-Scan-Erweiterung über Hook `cms_media_upload_scan` (pre-upload) und `cms_media_post_upload_scan` (post-upload).
	- Heuristische Blockaden für riskante Polyglot-/Script-Muster sowie XML-DOCTYPE (`<!DOCTYPE`) in Upload- und Import-Pfaden.
	- Import-Verzeichnis-Härtung (`index.html`, `.htaccess`) plus restriktive Dateirechte (`0640`) für gespeicherte Importdateien.
- ✅ **SEC-002 (dynamische SQL-Wartung) umgesetzt**
	- Strikte Identifier-Validierung (`/^[A-Za-z0-9_]+$/`) + Prefix-Validierung vor Ausführung.
	- Normalisierte, deterministische Ergebnisstruktur inkl. Details und Audit/Logger-Eintrag.
- ✅ **SEC-003 (Request-Zentralisierung im Security-Scope) abgeschlossen**
	- Neuer zentraler Wrapper `CMS\Http\Request` eingeführt.
	- Kritische Endpunkte (`cron.php`, `FileUploadService`) sowie zentrale Routing-Pfade (`Router`, `ApiRouter`) migriert.
	- Folgeetappe Admin-Module (höchste Trefferdichte) umgesetzt: `MediaModule`, `UsersModule`, `PagesModule`, `PostsModule`.
	- Nächste SEC-003-Welle für Restmodule umgesetzt: `DashboardModule`, `SystemInfoModule`, `HubSitesModule`, `AiServicesModule`, `BackupsModule`.
	- Zusätzlicher Wrapper-Zugriff für Session (`Request::session`) ergänzt.
	- Messbarer Delta im Scope `CMS/admin/modules/**`: direkte Superglobal-Treffer von **31** auf **0** reduziert.
	- Security-Baseline-Gate prüft dauerhaft, dass `CMS/admin/modules/**` keine direkten Superglobal-Zugriffe mehr enthält.
	- Breite Restmigration außerhalb kritischer Security-Hotspots bleibt als Wartbarkeits-Folgeetappe unter MAINT-002 bestehen.
- ✅ **SEC-004 (Web-Cron-Token in URL) umgesetzt**
	- Header-first/standardmäßig Header-only erzwungen (`X-CMS-Cron-Token`).
	- Query-Token nur noch optional über expliziten Übergangsschalter `CMS_CRON_ALLOW_QUERY_TOKEN`.
	- Deprecation-Warnlogging für Query-Token-Aufrufe ergänzt.
- ✅ **Security-Abschlussgate umgesetzt**
	- Neue Suite `TESTS\security-baseline\run.php` validiert SEC-001 bis SEC-004 reproduzierbar über den zentralen Runner.
	- `TESTS\manifest.php` registriert `security-baseline` als Required-Suite.
	- `.gitignore` gibt `TESTS\security-baseline\run.php` explizit frei und widersprüchliche Re-Ignore-Zeilen wurden entfernt.

### step-001
- **Ziel:** Upload- und Importpfade weiter härten.
- **Befund:** Uploads prüfen CSRF, Auth, Dateigröße und MIME, bleiben aber eine zentrale Angriffsfläche; Importer speichert XML/JSON in Upload-Strukturen.
- **Risiko:** Missbrauch durch Parser-Bugs, Polyglot-Dateien, DoS über große Importinhalte oder später fehlerhafte Auslieferung.
- **Technische Ursache:** Breite Upload-Funktionalität in Media, EditorJS, Importer und Theme-Customizer.
- **Lösungsweg:** Einheitliche Upload-Policy mit Allowlist je Kontext, Quarantäne-Verzeichnis außerhalb Webroot, Viren-/Content-Scan-Hook, serverseitige Revalidierung vor Auslieferung.
- **Betroffene Dateien:** CMS\core\Services\FileUploadService.php, CMS\core\Services\Media\UploadHandler.php, CMS\plugins\cms-importer\includes\class-admin.php.
- **Priorität:** P1
- **Aufwand:** M
- **Abhängigkeiten:** Upload-Konfiguration, Webserver-Auslieferung.
- **Status:** ✅ umgesetzt (2026-06-13)

### step-002
- **Ziel:** Dynamische SQL-Wartungsbefehle absichern.
- **Befund:** Tabellenwartung baut SQL aus Operation und Tabellenname zusammen.
- **Risiko:** Bei unvollständiger Identifier-Validierung können administrative Wartungsfunktionen zu SQL-Manipulationen führen.
- **Technische Ursache:** SQL-Identifier sind nicht parametrisierbar; die Sicherheit hängt vollständig an `quoteIdentifier` und vorgelagerten Quellen.
- **Lösungsweg:** Tabellen ausschließlich aus `information_schema`/Prefix-Allowlist auswählen, Identifier vor `quoteIdentifier` streng gegen `/^[A-Za-z0-9_]+$/` validieren und Audit-Log erzwingen.
- **Betroffene Dateien:** CMS\core\Services\SystemService.php:611-617.
- **Priorität:** P1
- **Aufwand:** S
- **Abhängigkeiten:** Admin-Systemmodul.
- **Status:** ✅ umgesetzt (2026-06-13)

### step-003
- **Ziel:** Request-Zugriffe zentralisieren.
- **Befund:** Der Fokus-Scan fand 676 direkte Superglobal-Zugriffe in First-Party-PHP-Dateien.
- **Risiko:** Inkonsistente Validierung/Normalisierung, XSS-/Path-Traversal-/Business-Logic-Lücken.
- **Technische Ursache:** Kein strikt durchgesetzter Request-DTO-/Validator-Layer.
- **Lösungsweg:** Request-Wrapper für GET/POST/FILES/SERVER, typed Validatoren pro Formular/Endpoint, schrittweise Migration der Admin-Module.
- **Betroffene Dateien:** CMS\admin\modules\*, CMS\core\Services\*, CMS\plugins\cms-importer\*.
- **Priorität:** P2
- **Aufwand:** L
- **Abhängigkeiten:** Regressionstests pro Modul.
- **Status:** ✅ abgeschlossen im Security-Scope (Wrapper + kritische Endpunkte + Admin-Modul-Scope vollständig migriert; Restmigration unter MAINT-002)

### step-004
- **Ziel:** Web-Cron-Token nicht über URLs transportieren.
- **Befund:** Cron akzeptiert Token aus Header oder Query-Parameter.
- **Risiko:** Query-Token können in Logs, Browser-History oder Referern landen.
- **Technische Ursache:** Fallback auf `$_GET['token']`.
- **Lösungsweg:** Query-Token als deprecated markieren, Header-only erzwingen, Übergangszeit mit Warn-Logging.
- **Betroffene Dateien:** CMS\cron.php:46-58.
- **Priorität:** P3
- **Aufwand:** S
- **Abhängigkeiten:** Cron-Aufrufer.
- **Status:** ✅ umgesetzt (2026-06-13)

## Update 2026-06-13 (Security-Abschluss)
- `TESTS\security-baseline\run.php` ergänzt ein reproduzierbares Security-Gate für Upload-/Import-Härtung, SQL-Identifier-Guard, Cron-Header-Policy und Request-Migration.
- `TESTS\manifest.php` registriert die Suite als Required-Suite; `php TESTS\run.php --suite=security-baseline` läuft erfolgreich.
- `.gitignore` bereinigt widersprüchliche Re-Ignore-Zeilen für zentrale Testdateien und gibt die Security-Baseline explizit frei.
- Security-Score: **73 → 92**. Alle Security-Findings SEC-001 bis SEC-004 sind im Audit-Scope abgeschlossen; verbleibende breite Superglobal-Restmigration wird als Wartbarkeitsthema MAINT-002 weitergeführt.
