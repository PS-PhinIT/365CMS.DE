# 365CMS – Offene Nice-to-haves · Konsolidierte Restliste
> **Stand:** 2026-05-14 | **Quelle:** PRUEF-CHECKLISTE.md | **Scope:** ohne Plugins und ohne weitere Theme-Erweiterungen

## Zweck

Diese Liste bündelt alle noch offenen Nice-to-haves aus der zentralen Prüfliste in eine
arbeitsfähige Reihenfolge. Plugin-spezifische Ausbauten (Bereich 13) und Theme-Marketplace-/
Theme-System-Erweiterungen (Bereich 8 außerhalb des Webbaukastens) sind bewusst
ausgeklammert. Reihenfolge folgt dem Wirkungspfad: zuerst Inhalte, dann Betrieb, dann
querschnittliche Komfortfunktionen.

Nachprüfung 2.9.760: Die zuletzt umgesetzten Nice-to-haves ab 2.9.725 wurden erneut mit Fokus auf bekannte Fehler, unvollständige Übernahmepfade, Security-/Token-Verträge, Best Practice und Performance geprüft. Konkret wurde der 404-Monitor-Übernahmeflow nachgehärtet, damit ungelöste 404-Logs wieder als neue Redirect-Regel gespeichert werden und keine 404-Log-ID als Redirect-ID missverstanden wird. Zusätzlich bleibt das Performance-Sicherheitsnetz auf reinen GET-Seiten fail-soft, weil Rollback-Verzeichnisse erst bei tatsächlichen Snapshot-Mutationen angelegt werden. Die SEO-Audit-Datenquelle ist nun serverseitig begrenzt, damit Dashboard, Trend-Live-Fallback und Broken-Link-Report auch bei großen Inhaltsbeständen nicht ungebremst alle Seiten und Beiträge synchron analysieren. Live-Log-Nacharbeit: MariaDB-kritische `SHOW TABLES LIKE ?`-Prüfungen wurden auf PDO-quotierte read-only Checks umgestellt, fehlende SEO-Trendtabellen erzeugen im Dashboard keine vorbereiteten SELECT-Fehler mehr, Paket-Historien nutzen ein robustes LIKE-Escape-Zeichen und die Medienbibliothek schützt ihre View-Helper gegen doppelte Includes. Neu hinzu kommt ein read-only Kapazitäts-Pre-Check im Performance-Center, der freie Disk-Kapazität, Last und erkannte parallele Hintergrundjobs vor Cache-, DB- und Medien-Massenaktionen sichtbar macht und dieselben Werte in die Bestätigungsdialoge übernimmt. Im Recht-Bereich ergänzt `/admin/legal-sites` nun versionierte DACH-Vorlagenprofile für Impressum, Datenschutz, Widerruf und AGB-Skelett inklusive einzelner Anwendung pro Legal-Site, gespeicherter Profil-/Versionsmetadaten und klarer Hinweise, dass die technischen Vorlagen keine Rechtsberatung ersetzen. `/admin/data-requests` zeigt für Auskunfts- und Löschanfragen einen berechneten Fristenstatus mit 30-Tage-Pflichtfrist, 7-Tage-Warnfenster, Überfällig-Markierung und CSRF-geschützter Admin-Mail-Eskalation über die bestehende Mail-Queue. Parallel akzeptiert die gemeinsame Section-Shell modulare Array-Container, sodass Auskunft & Löschen wieder sauber initialisieren, und der neutrale Admin-Hintergrund ist leicht abgedunkelt, damit Karten klarer lesbar bleiben – ohne neue GET-Mutationen, Token-URLs oder zusätzliche 500-anfällige Pflichtpfade.

Nachprüfung 2.9.763: Die offene Security-Alarmierung ist jetzt über die bestehende Monitoring-Mail-Pipeline angeschlossen. Ein neuer, fail-softer Security-Alert-Lauf verdichtet Login-Fehlversuche aus `login_attempts`, Firewall-Blocks aus `security_log` sowie neue AntiSpam-Runtime-Rejections im selben Logpfad über ein konfigurierbares Zeitfenster und löst bei Überschreiten der Schwellwerte eine Mail über Queue oder Direktversand aus. Die Konfiguration hängt bewusst an `/admin/monitor-email-alerts`, die Auslösung erfolgt read-only über `cms_cron_hourly`, Cooldowns begrenzen Alert-Fluten, und es entstehen weder neue GET-Mutationen noch Tokens in URLs oder zusätzliche 500-anfällige Pflichtpfade.

Nachprüfung 2.9.764: Die Sicherheitsbaseline ist jetzt direkt in `/admin/firewall` sichtbar. Entwicklung, Staging und Produktion werden als Härtungsprofile mit read-only Diff gegen die aktuelle Firewall-Konfiguration angezeigt; eine optionale Anwendung läuft ausschließlich über den bestehenden Admin-POST-/CSRF-Pfad und protokolliert sich im Audit-Log. Zusätzlich zeigt dieselbe Firewall-Seite eine Diagnose-Untersektion für Runtime-Verdrahtung, Aktivschalter, Logging, aktive Regeln, Simulationsvorschau und Block-Log. Es gibt keine neuen GET-Mutationen, keine Token-URLs und keine zusätzlichen Pflichtpfade, die bei fehlenden Logs einen 500 erzeugen könnten.

Nachprüfung 2.9.765: Die Nice-to-haves ab 2.9.725 wurden erneut automatisiert inventarisiert und geprüft. Der Changelog-Verweisbestand wurde gegen existierende Dateien abgeglichen, 96 referenzierte PHP-Dateien wurden per `php -l` geprüft, `update.json` wurde validiert und zusätzliche Pattern-Scans suchten nach Token-URLs, GET-Schreibaktionen, rohen Request-Ausgaben, MariaDB-kritischen Schema-Checks, Redirect-Flows ohne `exit` und bekannten TODO-/Temporär-Markern. Konkrete Nacharbeit: `/admin/backups` prüft den finalen Downloadpfad unmittelbar vor dem Chunk-Streaming nochmals per `realpath()` gegen den Backup-Root, der historische Doku-Anker `CMS/DOC/admin/PRUEF-CHECKLISTE.md` wurde als Kompatibilitätsindex wiederhergestellt, und die Security-Alert-View wurde geringfügig bereinigt. `CMS/config/media-processing-job.json` bleibt bewusst eine optionale Laufzeitdatei und muss ohne aktiven Medienjob nicht existieren. Externe URLs wurden in dieser Nachprüfung nicht vom Nutzer bereitgestellt; damit war kein zusätzlicher `fetch_webpage`-Abruf erforderlich. Es entstanden keine neuen GET-Mutationen, Token-URLs oder 500-anfälligen Pflichtpfade.

Nachprüfung 2.9.783: Die zuletzt umgesetzten Nice-to-haves ab 2.9.770 sowie der Changelog-Bereich ab 2.9.700 wurden erneut gegen bekannte Fehler, unvollständige Verdrahtung, Best Practice, Sicherheit, Performance und Web-/Token-Verträge geprüft. PHP-/JS-Syntax, `CMS/update.json`, Versionsgleichstand, Token-URL-Muster, GET-Mutationsverdacht, rohe Exception-Ausgaben, MariaDB-`SHOW`-Platzhalter und direkte Submit-Bypässe wurden automatisiert kontrolliert. Konkrete Nacharbeit: Admin-Confirm-/Fallback-Submits nutzen jetzt `requestSubmit()` bzw. temporäre native Submitter statt direkter `form.submit()`-Bypässe, Backup-Bestätigungsdialoge serialisieren Dateinamen script-sicher, und Design-/TOC-/AI-Translation-/Theme-Explorer-/Orders-Fehlerpfade loggen technische Exception-Details serverseitig statt sie im Admin-Alert auszugeben. Es wurden keine neuen GET-Mutationen, CSRF-Token-URLs oder zusätzlichen 500-anfälligen Pflichtpfade eingeführt; die verbleibenden `token=`-URLs betreffen ausschließlich bewusst getrennte Cron- bzw. Passwort-Reset-Verträge.

Nachprüfung 2.9.784: Der Editor.js-Stack wurde gegen Upstream `2.31.6`, bekannte Tool-/Plugin-Best-Practices, XSS-/URL-/Upload-Risiken und Performance geprüft. Konkrete Nacharbeit: Ein zentraler DOM-basierter Editor.js-HTML-Sanitizer bereinigt Inline-HTML und Raw-Blöcke attributsicher, der Frontend-Renderer gibt Raw-Blöcke nicht mehr ungefiltert aus, Link-/Embed-/Asset-URLs sind auf sichere Schemata begrenzt, Media-/Upload-JSON-Antworten geben keine technischen Exception- oder `WP_Error`-Details mehr preis, der Legacy-Editor-Submit nutzt native Submitter statt `form.submit()`, SVG/AVIF wurden im Editor.js-Picker bzw. Remote-Import an die zentrale Medienvalidierung angepasst und die SEO-Liveanalyse wurde für große Editor.js-Dokumente gedrosselt. Es wurden keine neuen GET-Mutationen, Token-URLs oder zusätzlichen 500-anfälligen Pflichtpfade eingeführt.

Nachprüfung 2.9.785: Der angeforderte Snyk-Audit wurde über Core, Theme-Repository und Plugin-Repository gefahren; unterstützte Dependency-Projekte waren ohne bekannte Schwachstellen, das Plugin-Repository blieb in Snyk Code ohne Findings. Konkrete Nacharbeit im First-Party-Code: Admin-Downloads und Diagnose-Exports prüfen Dateipfade erneut gegen erlaubte Roots, Member-Mediennavigation schreibt nur noch normalisierte Query-Parameter, Default-Theme-Cookies erhalten HTTPS-abhängig `Secure`, MedCarePro-Templates entfernen dynamische Login-Redirects und escapen Request-/Suchausgaben direkt, Hashes für reine IDs/Zusammenfassungen nutzen SHA-256, und der PTC-Customizer whitelisted Tabs plus escaped dynamische Formularwerte. Der Installer wurde auf denselben Sicherheitsstand gehoben: CSRF für alle POST-Schritte, gehärtete Session-Cookies, strengere URL-/E-Mail-/Admin-Validierung, 12-Zeichen-Mindestpasswort und aktuelle Default-Settings. Verbleibende Scanner-Hinweise sind als Wrapper-/Vendor-/Protokoll-Restfälle dokumentiert; es wurden keine neuen GET-Mutationen oder Token-URLs eingeführt.

Nachprüfung 2.9.786: Der 365CMS-Core wurde mit `npx -y snyk@latest code test --severity-threshold=low` erneut geprüft; ausgewertet wurden First-Party-Pfade außerhalb `ASSETS`, `CMS/assets`, `CMS/vendor`, `BACKUP` und `RELEASE`. Konkrete Nacharbeit: `/admin/backups` entkoppelt Downloads über kurzlebige serverseitige Tokens im bestehenden CSRF-geschützten POST-Vertrag von direkten URL-Dateinamen, der Router akzeptiert für zentrale Redirects nur noch interne Pfade, Importer-Fallback-Hashes wurden von MD5 auf SHA-256 umgestellt, S3-REST-Uploads verwenden AWS Signature V4 mit HMAC-SHA256, und der Frame-Schutz bleibt mit validiertem `X-Frame-Options: DENY` plus CSP `frame-ancestors 'none'` restriktiv. TOTP bleibt bewusst RFC-6238-kompatibel für vorhandene Authenticator-Apps und ist als Protokoll-HMAC gekapselt. Der finale gefilterte Snyk-Code-Report enthält `0` First-Party-Findings; ausgeschlossen bleiben ausschließlich Drittanbieter-/Runtime-Pfade gemäß Aufgabenstellung, neue Token-URLs wurden nicht eingeführt.

---

## 1. Medienverwaltung – Restausbau

- [x] **Erweiterte Mediensuche mit gespeicherten Filtern**
  - [x] Filter-Presets pro Admin-Benutzer speicherbar (Typ, Kategorie, Datum, Verwendung, Größe)
  - [x] Permalink-Filter über Query-String, damit Filterzustände teilbar sind
- [x] **Bulk-Tagging und Bulk-Kategorisierung**
  - [x] Auswahl in Listen-/Grid-Ansicht, Aktion über bestehende Bulk-Toolbar
  - [x] Audit-Eintrag pro Bulk-Aktion mit Treffer- und Fehlerzahlen
- [x] **Verwaiste Medien erkennen**
  - [x] Read-only Liste „nirgends verwendet seit X Tagen" auf Basis der `MediaUsageService`-Daten
  - [x] Vorschlag zur manuellen Prüfung, kein automatisches Löschen
  - [x] Alt-Text-Bulk-Editor für SEO-/Accessibility-Aufräumen

## 2. SEO – offene Komfortfunktionen

- [x] **Snippet-/SERP-Preview pro Inhalt und global**
  - [x] Live-Vorschau im Editor für Google-Desktop, Google-Mobile und Social-OG
  - [x] Globaler Preview-Modus für Startseite, Archive und Taxonomien
- [x] **Broken-Link-Prüfung**
  - [x] Geplanter Cron-Lauf über Inhalte, Sitemap und Redirect-Manager
  - [x] Read-only Übersicht mit Treffer pro Quelle, Wiederholungsoption, Ignore-Liste
- [x] **Automatische SEO-Hinweise direkt im Editor**
  - [x] Realtime-Checks für Title, Description, H1-Eindeutigkeit, Keyphrase, Bild-Alt-Texte
  - [x] Hinweis-Badges nicht blockierend, nur empfehlend
- [x] **Trendansicht für 404, Redirects und SEO-Score**
  - [x] Sparkline-Karten im SEO-Dashboard
  - [x] Datenquelle: aggregierte Werte aus Redirect-Manager und 404-Monitor

## 3. Performance – Sicherheitsnetze für Massenoperationen

- [x] **Dry-Run und Rollback für Massenoptimierungen**
  - [x] Vorschau betroffener Datensätze pro Optimierungsaktion (Cache-Purge, Bildkonvertierung, DB-Wartung)
  - [x] Snapshot vor Ausführung, Rollback-Aktion innerhalb eines Zeitfensters
- [x] **Historie der Performance-Maßnahmen**
  - [x] Read-only Tabelle mit Zeitpunkt, Aktion, Auslöser, Ergebnis und Dauer
  - [x] Anbindung an `audit_log` analog zur Update-Historie in `/admin/cms-logs`
- [x] **Kapazitätswarnungen vor Optimierungsjobs**
  - [x] Pre-Check für freien Speicher, Last und parallel laufende Cron-Jobs
  - [x] Bestätigungsdialog mit konkreten Werten statt pauschalem „sind Sie sicher"

## 4. Recht – DSGVO-Workflow vervollständigen

- [x] **Vorlagen / Profile für Rechtstexte**
  - [x] Mitgelieferte DACH-Profile für Impressum, Datenschutz, Widerruf, AGB-Skelett
  - [x] Versionierung pro Vorlage, Anwendung pro Legal-Site einzeln möglich
- [x] **Fristen- und Bearbeitungsstatus für Datenschutzanfragen**
  - [x] Eingegangen → in Bearbeitung → erledigt/abgelehnt mit Pflichtfrist
  - [x] Warnung bei näherrückender Frist (Default 30 Tage), Eskalation an Admin-Mail
## 5. Sicherheit – proaktive Härtung

- [x] **Simulationsmodus für Firewall-Regeln**
  - [x] Neue Regel zunächst nur loggen, nicht blockieren
  - [x] Read-only Treffervorschau über X Stunden, dann scharfschalten
- [x] **Alarmierung bei sicherheitsrelevanten Ereignissen**
  - [x] Schwellenwert-basierte Mail-Alerts für Login-Brute-Force, AntiSpam-Spitzen, Firewall-Blocks
  - [x] Wiederverwendung der bestehenden Monitoring-Mail-Pipeline
- [x] **Sicherheitsbaseline / Härtungsprofil pro Umgebung**
  - [x] Profile „Entwicklung", „Staging", „Produktion" mit empfohlenen Einstellungen
  - [x] Diff-Ansicht zwischen aktivem Zustand und Profil, Anwendung optional
  - [x] Diagnose Untersite bei der Firewall um zu sehen das diese auch funktioniert und was bewirkt!

## 6. System & Doku – Konfigurationsdisziplin

- [x] **Backup-Validierung / Restore-Check im Trockentest**
  - [x] Hash-Verifikation der Sicherung, Probe-Lesen der wichtigsten Tabellen
  - [x] Optionaler Restore in temporäre Datenbank, Vergleichsbericht als read-only Ergebnis
- [x] **Update-Vorabprüfung auf Abhängigkeiten und Schreibrechte**
  - [x] PHP-Version, Erweiterungen, Disk-Space, Schreibrechte für `cache/`, `backups/`, `logs/`, `assets/`
  - [x] Blockierender Pre-Flight-Check mit klarer Anweisung pro Befund

### Nachprüfung 2.9.767

- `/admin/backups` ergänzt jetzt eine echte Backup-Validierung im bestehenden POST-/PRG-Flow.
- Geprüft werden Manifest/Prüfsummen, SQL-Dump-Integrität, Probe-Lesen wichtiger Tabellen sowie optional ein Restore-Dry-Run in eine temporäre Wegwerf-Datenbank mit Vergleichsbericht.
- Die Prüfergebnisse bleiben read-only sichtbar, verwenden keine Token-URLs und führen keine neue öffentliche GET-Mutation ein.
- Zusätzlich wurden die bislang fehlenden internen Verzeichnis-Helfer im Restore-Pfad vervollständigt, damit Backup-Restore und Dry-Run nicht in undefinierte Methoden laufen.

### Nachprüfung 2.9.766

- `/admin/updates` zeigt jetzt eine echte, blockierende Vorabprüfung für automatische Core-, Theme- und Plugin-Updates.
- Geprüft werden PHP-/DB-Version, notwendige PHP-Erweiterungen, freier Speicher sowie Schreibrechte auf `cache/`, `backups/`, `logs/`, `assets/` und den jeweiligen Zielpfaden.
- Blockierende Befunde deaktivieren Installationsbuttons sichtbar und werden zusätzlich serverseitig vor dem eigentlichen Installationslauf abgefangen.
- Keine neuen GET-Mutationen, keine Tokens in URLs; Installationen bleiben im bestehenden POST-/CSRF-Vertrag.

## 7. Diagnose – Beobachtbarkeit ausbauen

- [x] **Trendhistorie für Response-Time, Cron und Speicherverbrauch**
  - [x] Aggregation über 24 h, 7 d, 30 d mit Sparklines
  - [x] Datenquelle: bestehende Monitoring-Sammler, Persistenz in eigener Trend-Tabelle
- [x] **Export/Download für Diagnoseberichte**
  - [x] Bündelt Systeminfo, Health-Check, letzte Logs, Asset-Status, Cron-Status als ZIP
  - [x] Sensible Werte (Keys, DB-Passwort, Mail-Credentials) werden serverseitig redacted
- [x] **Sammelansicht für kritische Systemwarnungen**
  - [x] Eine Seite mit allen aktiven Warnungen aus Performance, Security, Diagnose, Updates, Recht
  - [x] Direkt-Action pro Warnung (lösen, ignorieren mit Begründung, später erinnern)
- [x] **Sprechende Benutzeranzeige in der Update-Historie**
  - [x] User-ID auflösen auf `display_name` plus Rolle, Fallback auf ID bei gelöschten Benutzern
  - [x] Konsistent in `/admin/cms-logs` und Update-Center

### Nachprüfung 2.9.769

- Die persistierte Update-Historie löst Benutzer-IDs jetzt serverseitig auf sprechende Labels aus `display_name` plus Rollenbezeichnung auf.
- `/admin/updates` und `/admin/cms-logs` verwenden denselben aufbereiteten Datenpfad und zeigen bei gelöschten Konten fail-soft weiterhin `User #ID` an.
- Es wurden keine neuen GET-Mutationen, keine Token-URLs und keine zusätzlichen 500-anfälligen Pflichtpfade eingeführt.

### Nachprüfung 2.9.770

- `/admin/diagnose` und `/admin/cms-logs` bieten jetzt einen POST-/CSRF-geschützten ZIP-Export für Diagnoseberichte direkt aus dem bestehenden Admin-Kontext an.
- Das Archiv bündelt Systeminformationen, Health-Check, Asset-Status, Cron-Status, geplante Tasks sowie begrenzte Error-/CMS-/Audit-/Update-Logauszüge in getrennten Dateien.
- Sensible Werte wie Tokens, Passwörter, Secrets und Credentials werden serverseitig redigiert; es gibt keine Token-URLs, keine neue GET-Mutation und fehlende Datenquellen fallen fail-soft auf leere Abschnitte zurück.

### Nachprüfung 2.9.773

- `/admin/monitor-response-time`, `/admin/monitor-disk-usage` und `/admin/monitor-cron-status` zeigen jetzt eine read-only Trendhistorie über 24 Stunden, 7 Tage und 30 Tage mit Sparklines und Bereichsstatistiken.
- Die Persistenz läuft stündlich über den bestehenden Hook `cms_cron_hourly` in eine eigene Tabelle `monitoring_trends`; Live-Werte bleiben zusätzlich direkt aus den bestehenden Monitoring-Sammlern sichtbar.
- Für Cron wird bewusst der Abstand zum zuletzt dokumentierten stündlichen Lauf als `Cron-Lag` visualisiert. Die Umsetzung bleibt ohne neue GET-Mutationen, ohne Token-URLs und fällt bei fehlenden Snapshots oder optionalen Daten fail-soft auf Live-Werte zurück.

### Nachprüfung 2.9.775

- `/admin/monitor-warnings` bündelt aktive Hinweise aus Performance, Security, Diagnose, Updates und Recht in einer gemeinsamen Warnzentrale auf Basis bestehender Module und Services.
- Jede Warnung besitzt einen direkten Lösungs-/Öffnen-Link in den zuständigen Adminbereich; Ignorieren und Wiedervorlage laufen ausschließlich über den bestehenden POST-/CSRF-Vertrag mit serverseitig gespeicherten Zuständen.
- GET-Seiten bleiben read-only, es werden keine Tokens in URLs erzeugt und fehlende Teilquellen fallen fail-soft weg, statt die Warnzentrale mit einem HTTP 500 zu blockieren.

### Nachprüfung 2.9.777

- `/admin/cms-logs` bereinigt PHP-Error-Log und CMS-Dateilogs jetzt robuster: Das PHP-Error-Log wird sicher geleert, CMS-Dateilogs werden bevorzugt entfernt und bei gesperrten Handles sicher geleert; leere Logdateien werden nicht mehr als scheinbar ungelöschte Einträge angezeigt.
- Die Sammelaktion meldet Fehlschläge ehrlich zurück, räumt operative Audit-Spuren und Update-Historie weiter ausschließlich über den bestehenden POST-/CSRF-Vertrag auf und erzeugt keine GET-Mutation oder Token-URL.
- `/admin/diagnose` ergänzt eine eigene POST-/CSRF-geschützte Bereinigung für gespeicherte Fehlerreports, damit Diagnose-Logs nicht dauerhaft stehen bleiben.
- Der Medien-Admin lädt zentrale Medien-Services defensiv nach, um Deployment-/OPcache-Drift um `CMS\\Services\\MediaService` abzufangen; `System → Einstellungen` rendert die allgemeinen Kacheln einspaltig untereinander.

## 8. Cross-Bereich · Inhalte ↔ Medien ↔ SEO

- [x] **Featured-Image-Konsistenz prüfen**
  - [x] Read-only Liste der Inhalte mit fehlendem oder gebrochenem Featured Image
  - [x] Vorschlag zur Direktauswahl aus der Medienbibliothek
- [x] **SEO-Felder vs. globale Templates**
  - [x] Erkennung, wenn lokale Felder das globale Template ungewollt überschreiben
  - [x] Hinweis im Editor mit Option „auf globalen Default zurücksetzen"
- [x] **Kategorie-/Tag-Filter und Redirects gemeinsam pflegen**
  - [x] Ein Verwaltungspfad für Slug-Änderungen, der Redirects automatisch erzeugt und alte Filter-Links auflöst
  - [x] Bereits in 2.9.617 grundgelegt, hier als sichtbare Admin-Funktion finalisieren

### Nachprüfung 2.9.776

- `/admin/media?tab=featured` ergänzt jetzt einen read-only Konsistenz-Check für Beiträge und Seiten ohne Featured Image oder mit defekter Featured-Image-Referenz.
- Die Liste bleibt ein reiner GET-/Lesepfad und verlinkt nur in bestehende, bereits abgesicherte Editor- und Medien-Flows: Auswahl über den vorhandenen Featured-Image-Picker im Editor bzw. zentrales Replace-in-place für geteilte defekte Referenzen.
- Es entstehen keine neuen Token-URLs, keine GET-Mutationen und keine zusätzlichen 500-anfälligen Pflichtpfade; beschädigte oder lokale Legacy-Referenzen werden lediglich sichtbar gemacht statt automatisch umgeschrieben.

### Nachprüfung 2.9.778

- Seiten- und Beitragseditoren zeigen jetzt transparent an, wenn lokale Meta-Titel oder Meta-Beschreibungen aktive SEO-Defaults überschreiben.
- Redundante lokale Werte können direkt im bestehenden Editor-Formular auf den Standard zurückgesetzt werden; dafür wird bewusst nur das lokale Feld geleert und kein neuer Schreibpfad eingeführt.
- Die Live-Preview folgt dem echten Resolver-Vertrag: Bei Beiträgen wird die Meta-Beschreibung zuerst aus der Kurzfassung, danach aus dem ersten Absatz und erst dann aus dem restlichen Inhalt abgeleitet.
- Es entstehen keine neuen GET-Mutationen, keine Token-URLs und keine zusätzlichen 500-anfälligen Pflichtpfade.

### Nachprüfung 2.9.779

- Die Kategorie- und Tag-Editoren zeigen den Redirect- und Legacy-Filter-Vertrag jetzt direkt am Slug-Feld an und listen die aktuellen Archivpfade der jeweiligen Taxonomie sichtbar auf.
- Nach einer Slug-Änderung liefern die Admin-Alerts die konkret gepflegten Archiv-Weiterleitungen als Erfolgsdetails zurück, sodass die bisher unsichtbare Redirect-Automatik nachvollziehbar wird.
- Die Redirect-Regeln bleiben bewusst zentral im Redirect-Manager geführt; es gibt keinen neuen Spezial-Schreibpfad, keine GET-Mutation und keine Token-URL.
- Alte Theme-/Blog-Filter mit `?category=` bzw. `?tag=` bleiben weiterhin auf den aktuellen Archiv-Slug auflösbar.

## 9. Cross-Bereich · Benutzer ↔ Rollen ↔ Gruppen ↔ Member

- [x] **Wirkungsvorschau bei Rollenänderungen**
  - [x] Anzeige, welche Member-Bereiche, Plugin-Widgets und Pakete sich für einen Benutzer ändern
  - [x] Vor dem Speichern als read-only Diff
- [x] **Gruppen-/Paketbezüge sichtbar machen**
  - [x] Pro Benutzer und pro Gruppe eine Zeile mit aktiven Paketen, Member-Modulen und ablaufenden Verträgen
  - [x] Reduziert Suchaufwand bei Support-Fällen
- [x] **Profilfeld-Kompatibilität bei Auth-Settings-Änderungen**
  - [x] Pflichtfeld-Änderungen warnen vorab, welche Benutzer dadurch unvollständig werden
  - [x] Optionaler Onboarding-Re-Trigger für betroffene Benutzer

### Nachprüfung 2.9.780

- `/admin/users?action=edit&id=...` zeigt direkt am Rollenfeld eine read-only Wirkungsvorschau für die aktuell ausgewählte Zielrolle.
- Die Vorschau vergleicht Capabilities, sichtbar werdende bzw. entfallende Member-Bereiche, Plugin-Dashboard-Widgets und Paket-/Abo-Auswirkungen, bevor der bestehende Benutzer-POST gespeichert wird.
- Bestehende Pakete, Gruppenpakete und Laufzeiten werden bewusst nicht automatisch verändert; die UI weist nur auf notwendige manuelle Prüfungen hin.
- Der Ausbau nutzt keine AJAX-Route, keine Token-URL und keine neue GET-Mutation. Optionale Tabellen und Plugin-Registries fallen fail-soft auf neutrale Hinweise zurück, damit der Benutzer-Editor nicht mit HTTP 500 blockiert.

### Nachprüfung 2.9.781

- `/admin/users` zeigt pro sichtbarem Benutzer eine Support-Kontext-Zeile mit direktem Paket, Gruppenpaketen, sichtbaren Member-Bereichen und Vertragsfriststatus.
- `/admin/groups` zeigt pro Gruppe den Paketbezug, Paketmodule, globale Member-Bereiche sowie fällige oder überfällige Verträge der Gruppenmitglieder direkt in der Gruppenkarte.
- Die Daten werden serverseitig begrenzt und read-only voraggregiert; fehlende Abo-/Gruppentabellen oder unlesbare Vertragsdaten fallen fail-soft auf neutrale Hinweise zurück.
- Es entstehen keine neuen GET-Mutationen, keine Token-URLs und keine automatischen Paket- oder Vertragsänderungen. Der bestehende POST-/CSRF-Vertrag für Benutzer- und Gruppenänderungen bleibt unverändert.

### Nachprüfung 2.9.782

- `/admin/member-dashboard-profile-fields` zeigt vor dem Speichern eine read-only Kompatibilitätsvorschau für Profilfeld-/Completion-Änderungen.
- Die Vorschau zählt aktive Konten, aktuell unvollständige Profile und pro neu ausgewähltem Feld begrenzte Beispielkonten mit fehlenden Werten, ohne Benutzerprofile zu ändern oder personenbezogene Volltabellenlisten auszugeben.
- Ein optionaler Re-Trigger aktiviert ausschließlich über den bestehenden POST-/CSRF-Speicherpfad den vorhandenen Onboarding-/Profilabschluss-Hinweis im Member-Dashboard; es werden keine E-Mails versendet und keine einzelnen Benutzer mutiert.
- Fehlende optionale Datenquellen fallen fail-soft auf neutrale Hinweise zurück. Es entstehen keine neuen GET-Mutationen, keine Token-URLs und kein zusätzlicher 500-anfälliger Spezialpfad.

