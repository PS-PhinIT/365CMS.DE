﻿**Version:** 2.9.788

# 365CMS Changelog

## 📋 Legende

| Symbol | Typ | Bedeutung |
|--------|-----|-----------|
| 🟢 | `feat` | Neues Feature |
| 🔴 | `fix` | Bugfix |
| 🟡 | `refactor` | Code-Umbau ohne Funktionsänderung |
| 🟠 | `perf` | Performance-Verbesserung |
| 🔵 | `docs` | Dokumentation |
| ⬜ | `chore` | Wartungsarbeit / CI/CD |
| 🎨 | `style` | Design- / UI-Änderungen |

---

## 📜 Vollständige Versionshistorie

---

### v2.9.788 — 14. Mai 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.788** | 🔴 fix | Core/Admin Diagnose – Live-Log- und Redeclare-Hotfixes | **`CMS/core/Services/BackupService.php`, `CMS/admin/modules/seo/PerformanceModule.php`, `CMS/core/Services/PurifierService.php`, `CMS/core/Database.php`, `CMS/core/Debug.php`, `CMS/core/Version.php`, `CMS/update.json`, `CMS/marketplace/core/365cms/update.json`, `README.md` und `Changelog.md` beheben die aktuellen Diagnose-/PHP-Error-Log-Hotspots**: `BackupService` und das globale `PerformanceModule` sind jetzt als konditionale Klassendeklarationen gekapselt, sodass doppelte Includes über System-/Performance-/Diagnosepfade nicht mehr mit `Cannot redeclare class ...` abbrechen. Der zentrale `PurifierService` registriert die benötigten HTML5-Elemente und Attribute (`figure`, `figcaption`, `mark`, `details/open`, `video`, `source`, `audio` sowie moderne `img`-Attribute) in der HTMLPurifier-Definition und invalidiert den Definitionscache, damit keine Header-brechenden Unsupported-Warnings mehr entstehen. Zusätzlich schreibt `Database::connect()` erfolgreiche Verbindungen nicht mehr in den PHP-Error-Log, und `Debug::query()` hält schnelle SQL-Telemetrie nur noch intern vor; Datei-Logs entstehen weiterhin für langsame Queries oder bei explizitem `CMS_DEBUG_LOG_ALL_QUERIES`-Opt-in. |
| **2.9.787** | 🔴 fix | Core/Admin Live-Hotfixes – Login, Editor-Saves & Audit-Logs | **`CMS/index.php`, `CMS/install/InstallerController.php`, `CMS/core/Routing/PublicRouter.php`, `CMS/core/Services/CmsAuthPageService.php`, `CMS/core/Routing/AdminRouter.php`, `CMS/admin/views/pages/edit.php`, `CMS/admin/views/posts/edit.php`, `CMS/assets/js/admin-content-editor.js`, `CMS/admin/modules/users/UsersModule.php`, `CMS/core/Version.php`, `CMS/update.json`, `CMS/marketplace/core/365cms/update.json`, `README.md` und `Changelog.md` beheben den aktuellen Live-Block nach Neuinstallation, Inhaltsanlage und Diagnose-Logprüfung**: Login- und Installationswechsel räumen Installer-Sessions sowie alte Domain-Cookies gezielt auf, ohne die aktuelle Request-Cookie-Basis vorzeitig zu zerstören, und Auth-Redirects liefern nachvollziehbare Fehlercodes statt stiller Login-Rücksprünge. Seiten- und Beitragseditoren senden ihre Speicheraktion jetzt eindeutig über `_action=save`, während der gemeinsame Content-Editor native Formular-Submits nicht mehr abfängt, wenn keine Editor.js-Serialisierung nötig ist. Zusätzlich verwendet die Benutzerprofil-Sicherheitsereignis-Abfrage für `metadata LIKE` nun ein explizites `!`-Escape inklusive passender Pattern-Escapes, sodass MariaDB nicht mehr über `ESCAPE '\'` mit SQLSTATE 42000 stolpert. Die Änderungen bleiben im bestehenden POST-/CSRF- bzw. read-only Diagnosevertrag, erzeugen keine Token-URLs und führen keine neuen GET-Mutationen ein. |
| **2.9.786** | 🔴 fix | Core-Snyk-Code-Cleanup ohne ASSETS | **`CMS/admin/backups.php`, `CMS/admin/views/system/backups.php`, `CMS/core/Router.php`, `CMS/core/Security.php`, `CMS/core/Services/BackupService.php`, `CMS/core/Totp.php`, `CMS/plugins/cms-importer/includes/class-importer.php`, `CMS/plugins/cms-importer/includes/class-xml-parser.php`, `CMS/core/Version.php`, `CMS/update.json`, `CMS/marketplace/core/365cms/update.json`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/DOC/INSTALLATION.md`, `README.md` und `Changelog.md` schließen den erneuten Snyk-Code-Durchlauf für den 365CMS-Core außerhalb `ASSETS`, `CMS/assets`, `CMS/vendor`, `BACKUP` und `RELEASE` ab**: Backup-Downloads verwenden nun kurzlebige, serverseitig gespeicherte Download-Tokens im bestehenden CSRF-geschützten POST-Vertrag statt Dateinamen/Parts direkt aus der URL; die finale Datei bleibt zusätzlich per `realpath()`, Backup-Root-Allowlist, `is_file()` und `is_readable()` abgesichert. Router-Redirects akzeptieren für zentrale `Location`-Header nur noch interne Pfade und lehnen absolute URL-Schemata konsequent ab. Nicht-kryptografische Importer-Referenzen und Fallback-Dateinamen nutzen SHA-256 statt MD5, der S3-REST-Fallback signiert Uploads per AWS Signature V4/HMAC-SHA256 statt Signature V2/HMAC-SHA1, und der X-Frame-Schutz bleibt mit validiertem `DENY` plus bestehendem CSP `frame-ancestors 'none'` eindeutig restriktiv. Der TOTP-Pfad bleibt bewusst RFC-6238-kompatibel für bestehende Authenticator-Apps und wird klar als Protokoll-HMAC, nicht als Passwort-Hashing, gekapselt. Der abschließende Snyk-Code-Scan meldet nach Filterung der ausgeschlossenen Drittanbieter-/Runtime-Pfade `0` First-Party-Findings; neue Token-URLs wurden nicht eingeführt. |
| **2.9.785** | 🔴 fix | Snyk-Audit & Installer-Sync | **`CMS/admin/backups.php`, `CMS/admin/modules/system/SystemInfoModule.php`, `CMS/admin/modules/pages/PagesModule.php`, `CMS/admin/modules/posts/PostsModule.php`, `CMS/admin/views/media/featured.php`, `CMS/admin/views/media/library.php`, `CMS/assets/js/member-dashboard.js`, `CMS/themes/cms-default/js/theme.js`, `CMS/install.php`, `CMS/install/InstallerController.php`, `CMS/install/InstallerService.php`, `CMS/install/views/*.php`, `CMS/core/Version.php`, `CMS/update.json`, `CMS/marketplace/core/365cms/update.json`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/DOC/INSTALLATION.md`, `README.md` und `Changelog.md` synchronisieren den Snyk-Audit-Stand und den Installer auf die aktuelle Runtime**: First-Party-Funde aus Snyk Code wurden in Downloadpfaden, Diagnose-Exports, Member-Mediennavigation, Default-Theme-Cookies sowie Seiten-/Beitrags-/Medien-Hashpfaden nachgehärtet. MedCarePro-Templates vermeiden dynamische Login-Redirects, escapen Request- und Suchausgaben direkt am Sink und normalisieren Ergebnis-URLs; der PTC-Customizer whitelisted Tabs und escaped dynamische Formular-/Navigationswerte. Der Installer setzt Session-Cookies nun mit `HttpOnly`, `SameSite=Lax` und HTTPS-abhängigem `Secure`, schützt alle POST-Schritte per CSRF-Token, validiert Site-URL, E-Mail, Admin-Benutzernamen und 12-Zeichen-Passwörter strenger und legt Neuinstallationen mit `cms-default` sowie dem gewählten Site-Namen als `site_title` an. Verbleibende Scanner-Hinweise betreffen akzeptierte Wrapper-/Tool-False-Positives, Drittanbieter-Code oder protokollgebundene HMAC-SHA1-Pfade wie TOTP/S3; neue GET-Mutationen oder Token-URLs wurden nicht eingeführt. |
| **2.9.784** | 🔴 fix | Editor.js – Security, Uploads & Performance | **`CMS/core/Services/EditorJs/EditorJsHtmlSanitizer.php`, `CMS/core/Services/EditorJs/EditorJsSanitizer.php`, `CMS/core/Services/EditorJsRenderer.php`, `CMS/core/Services/EditorJs/EditorJsAssetService.php`, `CMS/core/Services/EditorJs/EditorJsMediaService.php`, `CMS/core/Services/EditorJs/EditorJsUploadService.php`, `CMS/core/Services/EditorJs/EditorJsRemoteMediaService.php`, `CMS/core/Services/EditorJs/EditorJsImageLibraryService.php`, `CMS/assets/js/admin-seo-editor.js`, `CMS/DOC/assets/editorjs/README.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen den Editor.js-Komplettaudit gegen Upstream `2.31.6`, bekannte Tool-/Plugin-Risiken, Best Practice, Sicherheit und Geschwindigkeit ab**: Editor.js-Inline-HTML wird nun zentral per DOM-Allowlist bereinigt, `javascript:`-/Event-Attribute und nicht freigegebene Tags werden entfernt, `tg-spoiler` bleibt kontrolliert erhalten und Raw-Blöcke werden sowohl beim Speichern als auch beim Frontend-Rendering strikt gesäubert statt ungefiltert ausgegeben. Link-/Embed-/Asset-URLs sind auf sichere Schemata und Medienpfade begrenzt, validierte Datenbilder bleiben nur für den Zeichentool-Pfad erlaubt. Die Media-API und Uploads loggen technische Fehler serverseitig, liefern Clients aber generische Meldungen. Der ältere Inline-Renderpfad nutzt nach `editor.save()` native Submitter/`requestSubmit()` statt direktem `form.submit()`. Remote-Import und Bildbibliothek zeigen nur Formate, die die zentrale Medienvalidierung sicher unterstützt; SVG und derzeit nicht unterstützte AVIF-Bilder werden im Editor-Picker/Remote-Import nicht mehr angeboten. Die SEO-Liveanalyse ist bei Editor.js-Mutationen gedrosselt, damit große Dokumente nicht pro Einzelmutation vollständig neu analysiert werden. Es entstehen keine neuen GET-Mutationen, Token-URLs oder zusätzlichen 500-anfälligen Pflichtpfade. |

### v2.9.783 — 13. Mai 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.783** | 🔴 fix | Admin/Nice-to-have-Audit ab 2.9.700 | **`CMS/assets/js/admin.js`, `CMS/assets/js/admin-comments.js`, `CMS/assets/js/admin-hub-site-edit.js`, `CMS/assets/js/admin-hub-sites.js`, `CMS/assets/js/admin-pages.js`, `CMS/assets/js/admin-plugins.js`, `CMS/assets/js/admin-site-tables.js`, `CMS/assets/js/admin-user-groups.js`, `CMS/assets/js/admin-users.js`, `CMS/assets/js/member-dashboard.js`, `CMS/admin/views/security/firewall.php`, `CMS/admin/views/system/backups.php`, `CMS/admin/views/pages/list.php`, `CMS/admin/views/posts/list.php`, `CMS/admin/views/security/antispam.php`, `CMS/admin/modules/system/AiEditorJsTranslationModule.php`, `CMS/admin/modules/themes/DesignSettingsModule.php`, `CMS/admin/modules/themes/ThemeEditorModule.php`, `CMS/admin/modules/toc/TocModule.php`, `CMS/admin/orders.php`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen die angeforderte Nachprüfung der letzten Nice-to-haves ab `2.9.770` sowie des Changelog-Bereichs ab `2.9.700` ab**: PHP-/JS-Syntax, JSON-Metadaten, Token-URL-, GET-Mutations-, Exception-, MariaDB- und Submit-Bypass-Muster wurden erneut geprüft. Als konkrete Nachhärtung laufen bestätigte Admin-Submits nun auch in Legacy-/Fallback-Pfaden über `requestSubmit()` bzw. temporäre native Submitter statt über direkte `form.submit()`-Bypässe; Backup-Bestätigungsdialoge bauen Dateinamen script-sicher per JSON-Kontext auf; Design-, TOC-, AI-Translation-, Theme-Explorer- und Orders-Fehlerpfade geben technische Exception-Details nur noch ins Serverlog statt in sichtbare Admin-Alerts. Die bekannten Cron-/Reset-Token-URLs bleiben bewusst außerhalb des CSRF-/Admin-Mutationsvertrags, während neue CSRF-Token-URLs, GET-Mutationen oder 500-anfällige Pflichtpfade nicht eingeführt wurden. |
| **2.9.782** | 🟢 feat | Admin/Member Dashboard – Profilfeld-Kompatibilität | **`CMS/admin/modules/member/MemberDashboardModule.php`, `CMS/admin/views/member/profile-fields.php`, `CMS/DOC/admin/member/README.md`, `CMS/DOC/admin/users-groups/AUTH-SETTINGS.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste Cross-Bereich-Nice-to-have ab**: `/admin/member-dashboard-profile-fields` zeigt jetzt vor dem Speichern eine read-only Kompatibilitätsvorschau für Profilfeld-/Completion-Änderungen. Admins sehen aktive Konten, aktuell unvollständige Profile, neu ausgewählte Feldrisiken und begrenzte Beispielkonten mit fehlenden Werten, ohne personenbezogene Volltabellenlisten auszugeben. Optional kann derselbe bestehende POST-/CSRF-Speicherpfad den vorhandenen Onboarding-/Profilabschluss-Hinweis reaktivieren; es werden keine Benutzerprofile geändert, keine E-Mails versendet, keine GET-Mutationen eingeführt und keine Token-URLs erzeugt. |
| **2.9.781** | 🟢 feat | Admin/Benutzer & Gruppen – Support-Kontext für Pakete und Verträge | **`CMS/admin/modules/users/UsersModule.php`, `CMS/admin/views/users/list.php`, `CMS/admin/modules/users/GroupsModule.php`, `CMS/admin/views/users/groups.php`, `CMS/DOC/admin/users-groups/README.md`, `CMS/DOC/admin/users-groups/USERS.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste offene Cross-Bereich-Nice-to-have ab**: `/admin/users` zeigt pro sichtbarem Benutzer eine kompakte Support-Kontext-Zeile mit direktem aktivem Paket, Gruppenpaketen, sichtbaren Member-Bereichen und Vertragsfriststatus. `/admin/groups` zeigt denselben Kontext pro Gruppe mit Paketbezug, Paketmodulen, global sichtbaren Member-Bereichen sowie fälligen oder überfälligen Verträgen der Gruppenmitglieder. Die Daten werden serverseitig begrenzt und read-only voraggregiert, optionale Abo-/Gruppentabellen fallen fail-soft auf neutrale Hinweise zurück, und es entstehen weder neue GET-Mutationen noch Token-URLs oder automatische Paket-/Vertragsänderungen. |
| **2.9.780** | 🟢 feat | Admin/Benutzer & Rollen – Wirkungsvorschau bei Rollenänderungen | **`CMS/admin/modules/users/UsersModule.php`, `CMS/admin/views/users/edit.php`, `CMS/assets/js/admin-users.js`, `CMS/DOC/admin/users-groups/README.md`, `CMS/DOC/admin/users-groups/USERS.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste offene Cross-Bereich-Nice-to-have ab**: Der Benutzer-Editor zeigt direkt am Rollenfeld eine read-only Wirkungsvorschau für die ausgewählte Zielrolle. Admins sehen vor dem Speichern, welche Capabilities hinzukommen oder entfallen, welche Member-Bereiche sichtbar sind, welche Plugin-Dashboard-Widgets nach Rollen-/Capability-Vertrag erscheinen und welche Paket-/Abo-Hinweise relevant werden. Bestehende direkte Abos, Gruppenpakete und Laufzeiten werden bewusst nicht automatisch verändert; die Vorschau weist nur auf notwendige manuelle Prüfungen hin. Der Ausbau nutzt keine AJAX-Schreibroute, keine Token-URL und keine GET-Mutation, sondern bleibt beim vorhandenen Benutzer-POST-/CSRF-Vertrag und fällt bei optionalen Tabellen oder Plugin-Registries fail-soft auf neutrale Hinweise zurück. |
| **2.9.779** | 🟢 feat | Admin/Beiträge – sichtbarer Slug-/Redirect-Vertrag für Kategorien & Tags | **`CMS/admin/modules/posts/PostsModule.php`, `CMS/admin/post-categories.php`, `CMS/admin/post-tags.php`, `CMS/admin/views/posts/categories.php`, `CMS/admin/views/posts/tags.php`, `CMS/DOC/admin/pages-posts/README.md`, `CMS/DOC/admin/pages-posts/POSTS.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste offene Cross-Bereich-Nice-to-have ab**: Die bereits seit `2.9.617` vorhandene Redirect-Automatik für Kategorie- und Tag-Slug-Änderungen ist jetzt im Admin sichtbar finalisiert. Die Edit-Formulare zeigen den Redirect- und Filter-Vertrag direkt am Slug-Feld, listen die aktuellen Archivpfade je Taxonomie auf und verlinken auf den Redirect-Manager als zentrale Pflegeoberfläche. Nach erfolgreicher Slug-Änderung geben Kategorien und Tags zusätzlich die konkret gepflegten Archiv-Weiterleitungen als Erfolgsdetails aus und erinnern daran, dass alte Theme-/Blog-Filter mit `?category=` bzw. `?tag=` weiterhin auf den aktuellen Archiv-Slug aufgelöst werden. Damit wird die vorhandene Cross-Verkabelung endlich transparent, ohne neue GET-Mutationen, Token-URLs oder konkurrierende Spezial-Schreibpfade einzuführen. |
| **2.9.778** | 🟢 feat | Admin/SEO – lokale Meta-Overrides vs. Defaults | **`CMS/assets/js/admin-seo-editor.js`, `CMS/admin/views/pages/edit.php`, `CMS/admin/views/posts/edit.php`, `CMS/DOC/admin/seo/SEO.md`, `CMS/DOC/admin/seo/README.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste offene Cross-Bereich-Nice-to-have ab**: Seiten- und Beitragseditoren zeigen jetzt transparent an, wenn lokale Meta-Titel oder Meta-Beschreibungen die aktiven SEO-Defaults überschreiben. Entspricht ein lokaler Wert bereits dem globalen Titel-Template bzw. dem automatisch aus Kurzfassung/erstem Absatz/Inhalt abgeleiteten Description-Default, erscheint ein expliziter Hinweis mit Option „auf Default zurücksetzen“, der bewusst keinen neuen Schreibpfad erzeugt, sondern nur das lokale Feld im bestehenden Editor-Formular leert. Parallel folgt die Live-Preview jetzt dem echten Resolver-Vertrag: Bei Beiträgen wird für die Meta-Beschreibung zuerst die Kurzfassung, danach der erste Absatz und erst danach der restliche Inhalt herangezogen. Damit bleiben Preview, Editor-Hinweis und Frontend konsistent – ohne neue GET-Mutationen, Token-URLs oder 500-anfällige Nebenpfade. |
| **2.9.777** | 🔴 fix | Admin/Diagnose, Medien & System-Einstellungen | **`CMS/core/Services/SystemService.php`, `CMS/admin/modules/system/SystemInfoModule.php`, `CMS/core/Services/ErrorReportService.php`, `CMS/admin/system-monitor-page.php`, `CMS/admin/views/system/diagnose.php`, `CMS/admin/views/system/cms-logs.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/settings/general.php`, `CMS/DOC/admin/diagnose/README.md`, `CMS/DOC/admin/diagnose/DIAGNOSE.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` beheben den gemeldeten Betriebsblock rund um Logs, Protokolle und Diagnose**: Das PHP-Error-Log wird jetzt sicher geleert, CMS-Dateilogs werden bevorzugt entfernt und bei gesperrten Handles sicher geleert; leere Logdateien verschwinden aus der Logzentrale und Fehlschläge werden nicht mehr fälschlich als Erfolg gemeldet. Die Sammelaktion bereinigt weiterhin operative Diagnose-Protokolle und Update-Historie im bestehenden POST-/CSRF-Vertrag, während `/admin/diagnose` zusätzlich eine eigene POST-/CSRF-geschützte Bereinigung gespeicherter Fehlerreports erhält. Der 404-Monitor wurde gegen den bestehenden geschützten Löschpfad verifiziert, der Medien-Admin lädt seine Core-Service-Abhängigkeiten defensiv nach, damit Deployment-/OPcache-Drift nicht mehr in `Class "CMS\\Services\\MediaService" not found` endet, und `System → Einstellungen` zeigt die allgemeinen Karten nun einspaltig untereinander. |
| **2.9.776** | 🟢 feat | Admin/Medien – Featured-Image-Konsistenz-Check | **`CMS/core/Services/MediaUsageService.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/featured.php`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste offene Cross-Bereich-Nice-to-have ab**: `/admin/media?tab=featured` ergänzt einen read-only Konsistenz-Check für Beiträge und Seiten ohne Featured Image oder mit defekter Featured-Image-Referenz. Die neue Prüfliste nutzt bestehende Inhalts- und Medienpfade weiter, zeigt pro Inhalt den Status sowie die gespeicherte Referenz an und verlinkt ausschließlich in bereits vorhandene Arbeitswege: den Editor mit dem bestehenden Featured-Image-Picker aus der Medienbibliothek oder – bei geteilten defekten Referenzen – den zentralen Replace-in-place-Flow derselben Ansicht. Die Umsetzung führt keine neue GET-Mutation, keine Token-URL und keinen automatischen Massen-Fix ein; Legacy- oder beschädigte Referenzen werden nur sichtbar gemacht und fail-soft behandelt, statt den Adminbereich mit einem HTTP 500 zu blockieren. |
| **2.9.775** | 🟢 feat | Admin/Diagnose – Warnzentrale für aktive Systemhinweise | **`CMS/admin/system-monitor-page.php`, `CMS/admin/monitor-warnings.php`, `CMS/admin/modules/system/SystemInfoModule.php`, `CMS/admin/views/system/subnav.php`, `CMS/admin/views/system/diagnose.php`, `CMS/admin/views/system/warnings.php`, `CMS/DOC/admin/diagnose/README.md`, `CMS/DOC/admin/diagnose/DIAGNOSE.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste offene Diagnose-Nice-to-have ab**: `/admin/monitor-warnings` bündelt aktive Warnungen aus den bestehenden Bereichen Performance, Security, Diagnose, Updates und Recht in einer gemeinsamen Sammelansicht. Die Seite selbst bleibt read-only im GET-Pfad und liest ausschließlich vorhandene Module/Services zusammen; pro Warnung gibt es einen direkten Link in den zuständigen Adminbereich sowie POST-/CSRF-geschützte Aktionen zum Ignorieren mit Begründung oder zur Wiedervorlage. Unterdrückte Warnungen werden serverseitig klein per `SettingsService` gespeichert, nur für aktuell bekannte Warn-IDs berücksichtigt und erscheinen getrennt als unterdrückter Bestand. Die Umsetzung vermeidet neue GET-Mutationen, Tokens in URLs und 500-anfällige Pflichtpfade, weil unlesbare Teilquellen fail-soft lediglich aus der Sammelansicht herausfallen. |
| **2.9.774** | 🔴 fix | Diagnose/SEO/Theme-Runtime – Log-Clears & Redeclare-Härtung | **`CMS/core/Services/RedirectService.php`, `CMS/core/Services/UpdateService.php`, `CMS/admin/modules/system/SystemInfoModule.php`, `CMS/admin/views/system/cms-logs.php`, `CMS/admin/posts.php`, `CMS/core/Routing/ThemeRouter.php`, `CMS/themes/cms-default/functions.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` beheben den aktuellen Diagnose-/Live-Fehlerblock**: Der 404-Monitor leert sein Protokoll jetzt über einen robusteren `DELETE`-Pfad statt über `TRUNCATE`, sodass das Bereinigen nicht an restriktiveren DB-Rechten scheitert und das Ergebnis als echte Anzahl zurückmeldet. In `/admin/cms-logs` bereinigt die Sammelaktion nun neben CMS-Logdateien auch die im Diagnosekontext angezeigten operativen Audit-Spuren und die Update-Historie; gleichzeitig werden reine `system.logs.clear*`-Selbstprotokolle aus der operativen Diagnose-Liste ausgeblendet, damit eine erfolgreiche Löschaktion nicht sofort wieder als scheinbar „nicht bereinigt“ auftaucht. Zusätzlich wurden die gemeldeten Redeclare-Hotspots um `CMS\Routing\ThemeRouter` und die Theme-Helfer weiter gehärtet, und der tote `gridjs`-Inline-Rest im Beitrags-Entry-Point wurde entfernt, um unnötige PHP-Hotspots im Live-Betrieb zu entschärfen. |
| **2.9.773** | 🟢 feat | Admin/Diagnose – Trendhistorie für Response-Time, Disk und Cron | **`CMS/core/Services/MonitoringTrendService.php`, `CMS/core/Bootstrap.php`, `CMS/admin/modules/system/SystemInfoModule.php`, `CMS/admin/views/system/response-time.php`, `CMS/admin/views/system/disk-usage.php`, `CMS/admin/views/system/cron-status.php`, `CMS/DOC/admin/diagnose/README.md`, `CMS/DOC/admin/diagnose/DIAGNOSE.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste offene Diagnose-Nice-to-have ab**: Die dedizierten Monitoring-Seiten für Response-Time, Disk-Usage und Cron-Status zeigen jetzt zusätzlich eine read-only Trendhistorie über `24 h`, `7 d` und `30 d` mit Sparklines sowie Min-/Max-/Ø-Werten. Eine neue, kleine Snapshot-Persistenz verdichtet die Kennzahlen stündlich über den bestehenden Hook `cms_cron_hourly` in `monitoring_trends`; Live-Werte bleiben parallel direkt sichtbar. Für Cron wird bewusst der Abstand zum zuletzt dokumentierten stündlichen Lauf als `Cron-Lag` visualisiert. Die Umsetzung führt keine neue GET-Mutation, keine Token-URL und keinen zusätzlichen 500-anfälligen Pflichtpfad ein; fehlen Snapshots, fallen die Seiten fail-soft auf Live-Werte und Hinweise zurück. |
| **2.9.772** | 🔴 fix | Admin/Dashboard – Pflichtseiten-Prüfung entfernt | **`CMS/admin/modules/dashboard/DashboardModule.php`, `CMS/DOC/admin/dashboard/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/DOC/audit/MustHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` entfernen die bisherige Dashboard-Pflichtseiten-Prüfung vollständig**: Das Hauptdashboard erzeugt keine speziellen Legal-Alerts oder Attention-Items für Impressum, Datenschutzerklärung und Cookie-Hinweis mehr. Der restliche Dashboard-Flow bleibt unverändert fail-soft, und es werden keine neuen Mutations- oder Tokenpfade eingeführt. |
| **2.9.771** | 🔴 fix | Admin/System – Backup-/Restore-Berechtigungsfehler | **`CMS/admin/modules/system/BackupsModule.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` beheben den fälschlichen Berechtigungsfehler im Backup-Bereich**: Der Backup-&-Restore-Pfad akzeptiert jetzt korrekt CSRF-Tokens, die bereits von der gemeinsamen Admin-Section-Shell validiert wurden. Dadurch schlagen Aktionen in `/admin/backups` nicht mehr mit `Keine Berechtigung für diese Aktion.` fehl, nur weil der Einmal-Token im Shell-Flow schon geprüft wurde. Die eigentliche Rechteprüfung bleibt unverändert, es werden keine neuen GET-Mutationen oder Token-URLs eingeführt. |
| **2.9.770** | 🟢 feat | Admin/Diagnose – ZIP-Export für Diagnoseberichte | **`CMS/admin/system-monitor-page.php`, `CMS/admin/modules/system/SystemInfoModule.php`, `CMS/admin/views/system/diagnose.php`, `CMS/admin/views/system/cms-logs.php`, `CMS/DOC/admin/diagnose/DIAGNOSE.md`, `CMS/DOC/admin/diagnose/README.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste offene Diagnose-Nice-to-have ab**: `/admin/diagnose` und `/admin/cms-logs` erzeugen jetzt einen Diagnosebericht als ZIP direkt im bestehenden POST-/CSRF-Vertrag. Das Archiv bündelt Systeminfo, Health-Check, Asset-Status, Cron-Status, geplante Tasks sowie begrenzte Error-/CMS-/Audit-/Update-Logauszüge in separaten Dateien. Sensible Werte wie Tokens, Passwörter, Secrets und Credentials werden serverseitig redigiert, der Download benötigt keine Token-URL und fehlende Datenquellen fallen fail-soft auf leere Abschnitte zurück. |
| **2.9.769** | 🟢 feat | Admin/Diagnose & Updates – sprechende Benutzerlabels in der Update-Historie | **`CMS/core/Services/UpdateService.php`, `CMS/admin/modules/system/SystemInfoModule.php`, `CMS/admin/views/system/updates.php`, `CMS/DOC/admin/system-settings/UPDATES.md`, `CMS/DOC/admin/diagnose/DIAGNOSE.md`, `CMS/DOC/admin/diagnose/README.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen den nächsten Diagnose-Nice-to-have-Punkt ab**: Die persistierte Update-Historie löst Benutzer-IDs jetzt serverseitig auf `display_name` plus Rollenbezeichnung auf. Dadurch zeigen `/admin/updates` und `/admin/cms-logs` nachvollziehbare Benutzerangaben statt roher IDs; bei gelöschten Konten bleibt der Verlauf fail-soft über `User #ID` lesbar. Die Umsetzung führt keine neuen GET-Mutationen, keine Token-URLs und keine zusätzlichen 500-anfälligen Pflichtpfade ein. |
| **2.9.768** | 🔴 fix | Admin/System – Backup-/Restore-Robustheit | **`CMS/core/Services/BackupService.php`, `CMS/admin/modules/system/BackupsModule.php`, `CMS/DOC/admin/system-settings/BACKUP.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` härten die operative Backup-Strecke nach**: Datenbank-Backups und SQL-Restores verarbeiten Dumps jetzt streaming-basiert statt komplette Dateien erst in den Arbeitsspeicher zu laden. Das reduziert Fehler bei größeren Datenbanken sowohl beim Erstellen als auch beim Restore. Zusätzlich werden konkrete Servicefehler im Admin besser zurückgegeben, damit `/admin/backups` bei Problemen nicht mehr nur mit einer zu knappen Standardmeldung antwortet. |
| **2.9.767** | 🟢 feat | Admin/System – Backup-Validierung & Restore-Dry-Run | **`CMS/core/Services/BackupService.php`, `CMS/admin/modules/system/BackupsModule.php`, `CMS/admin/backups.php`, `CMS/admin/views/system/backups.php`, `CMS/DOC/admin/system-settings/BACKUP.md`, `CMS/DOC/admin/system-settings/README.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste offene System-&-Doku-Nice-to-have ab**: `/admin/backups` ergänzt jetzt eine echte Backup-Validierung im bestehenden POST-/PRG-Flow. Pro Sicherung werden Manifest/Prüfsummen, SQL-Dump-Integrität, ein Probe-Lesen wichtiger Tabellen und optional ein Restore-Dry-Run in eine temporäre Wegwerf-Datenbank mit Vergleichsbericht geprüft. Die Ergebnisse bleiben read-only im Admin sichtbar, nutzen keine Token-URLs und führen keine neue öffentliche GET-Mutation ein. Zusätzlich wurden die bislang fehlenden internen Verzeichnis-Helfer im Restore-Pfad vervollständigt, damit Restore und Dry-Run nicht in undefinierte Methoden laufen. |
| **2.9.766** | 🟢 feat | Admin/System – blockierende Update-Vorabprüfung | **`CMS/core/Services/UpdateService.php`, `CMS/admin/modules/system/UpdatesModule.php`, `CMS/admin/views/system/updates.php`, `CMS/DOC/admin/system-settings/UPDATES.md`, `CMS/DOC/admin/system-settings/README.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste offene System-&-Doku-Nice-to-have ab**: `/admin/updates` ergänzt jetzt eine echte Vorabprüfung vor automatischen Core-, Theme- und Plugin-Updates. Geprüft werden PHP- und Datenbankversion, notwendige PHP-Erweiterungen, freier Speicherplatz sowie Schreibrechte für `cache/`, `backups/`, `logs/`, `assets/` und die jeweiligen Update-Zielpfade. Blockierende Befunde werden sichtbar im Admin dargestellt, deaktivieren die Installationsbuttons und werden zusätzlich serverseitig vor dem eigentlichen Update-Lauf abgefangen. Damit bleibt der Update-Pfad fail-soft und nachvollziehbar, ohne neue GET-Mutationen, ohne Sicherheitstoken in URLs und ohne zusätzliche 500-anfällige Nebenpfade. |
| **2.9.765** | 🔴 fix | Admin/Nice-to-have-Nachprüfung ab 2.9.725 | **`CMS/admin/backups.php`, `CMS/admin/views/system/email-alerts.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen die erneute Nachprüfung der Nice-to-haves ab 2.9.725 ab**: Die im Changelog referenzierten PHP-/JSON-Dateien wurden inventarisiert und automatisiert gelintet, typische Token-URL-, GET-Mutations-, Redirect-, SQL-/MariaDB- und rohe Ausgabe-Muster wurden geprüft. Als konkrete Nachhärtung validiert `/admin/backups` den finalen Downloadpfad vor dem Chunk-Streaming nochmals per `realpath()` gegen den Backup-Root, sodass Pfad-Traversal-/SSRF-Scanner und Laufzeit denselben fail-closed Vertrag sehen. Zusätzlich wurde der historische Doku-Anker `CMS/DOC/admin/PRUEF-CHECKLISTE.md` als Kompatibilitätsindex wiederhergestellt und eine kleine Einrückung in der Security-Alert-Konfiguration bereinigt. Es wurden keine neuen GET-Mutationen, Token-URLs oder zusätzlichen 500-anfälligen Pflichtpfade eingeführt. |

### v2.9.764 — 11. Mai 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.764** | 🟢 feat | Admin/Sicherheit – Firewall-Härtungsprofile & Diagnose | **`CMS/admin/firewall.php`, `CMS/admin/modules/security/FirewallModule.php`, `CMS/admin/views/security/firewall.php`, `CMS/DOC/admin/security/README.md`, `CMS/DOC/admin/security/FIREWALL.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste Sicherheits-Nice-to-have ab**: `/admin/firewall` zeigt jetzt Sicherheitsbaseline-Profile für Entwicklung, Staging und Produktion mit empfohlenen Firewall-Basiswerten, read-only Diff gegen den aktuellen Zustand und optionaler Profilanwendung per bestehendem POST-/CSRF-Pfad. Ergänzend wurde eine Firewall-Diagnose integriert, die Runtime-Verdrahtung, Aktivschalter, Logging, aktive Regeln, Simulationsvorschau und Block-Log fail-soft sichtbar macht. Die Umsetzung erzeugt keine neue GET-Mutation, keine Token-URLs und keine zusätzlichen 500-anfälligen Pflichtpfade; Profilanwendungen werden im Audit-Log nachvollziehbar protokolliert. |
| **2.9.763** | 🟢 feat | Admin/Sicherheit & Diagnose – Schwellenwert-Mailalarme | **`CMS/core/Services/SecurityAlertService.php`, `CMS/core/Services/AntispamService.php`, `CMS/core/Services/CommentService.php`, `CMS/core/Bootstrap.php`, `CMS/admin/modules/system/SystemInfoModule.php`, `CMS/admin/views/system/email-alerts.php`, `CMS/DOC/admin/security/README.md`, `CMS/DOC/admin/security/FIREWALL.md`, `CMS/DOC/admin/diagnose/README.md`, `CMS/DOC/admin/diagnose/DIAGNOSE.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste offene Sicherheits-Nice-to-have ab**: `/admin/monitor-email-alerts` verwaltet jetzt zusätzlich eine echte Security-Alarmierung für Login-Brute-Force, AntiSpam-Spitzen und Firewall-Blocks. Die Auslösung nutzt bewusst die bestehende Monitoring-Mail-Pipeline samt Mail-Queue weiter, läuft read-only über den vorhandenen `cms_cron_hourly`-Hook und begrenzt Wiederholungen über einen konfigurierbaren Cooldown. Dafür protokolliert der zentrale `AntispamService` Runtime-Rejections fail-soft im bestehenden `security_log`, während Login-Fehlversuche und Firewall-Blocks aus bereits vorhandenen Datenquellen verdichtet werden. Die Admin-Seite zeigt ergänzend einen kleinen Statusüberblick mit aktuellem Fenster sowie letztem Scan-/Versandzeitpunkt. Die Umsetzung vermeidet neue GET-Mutationen, Tokens in URLs und zusätzliche 500-anfällige Sonderpfade. |
| **2.9.762** | 🎨 style | Admin/UI – hellere Surface-Hierarchie & harmonisierte Controls | **`CMS/assets/css/admin-tabler.css`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` verfeinern die zentrale Admin-Oberfläche gestalterisch auf Basis der aktuellen Tabler-Empfehlungen für lichte Layouts, klare Surface-Trennung und subtile Interaktionszustände**: Der allgemeine Admin-Hintergrund ist nun rund 20 % heller abgestimmt und läuft mit einem sanften Verlauf von leicht dunkler oben zu heller unten. Gleichzeitig wurden Karten, Tabellen, Headerflächen, GridJS-Listen, Buttons, Auswahlzustände, Formfelder, Selects, Switches und Checkboxen auf ein gemeinsames, dezentes Surface-System mit weicheren Borders, zurückhaltenderen Hover-/Focus-States und konsistenteren Sekundärfarben umgestellt. Die Änderung bleibt bewusst reines CSS ohne neue GET-Mutationen, ohne Tokens in URLs und ohne zusätzliche 500-anfällige Laufzeitpfade. |
| **2.9.761** | 🟢 feat | Admin/Sicherheit – Firewall-Simulationsmodus | **`CMS/core/Services/SecurityRuntimeService.php`, `CMS/admin/modules/security/FirewallModule.php`, `CMS/admin/firewall.php`, `CMS/admin/views/security/firewall.php`, `CMS/core/SchemaManager.php`, `CMS/DOC/admin/security/FIREWALL.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste Sicherheits-Nice-to-have ab**: Neue Firewall-Blockregeln können jetzt zunächst in einem echten Runtime-Simulationsmodus laufen. Treffer werden im bestehenden `security_log` als `simulated` protokolliert, Requests aber bewusst noch nicht blockiert. `/admin/firewall` ergänzt dafür einen Regelmodus, eine read-only Treffervorschau über ein konfigurierbares Stundenfenster sowie einen expliziten, CSRF-geschützten POST-Pfad zum Scharfschalten oder Zurückschalten auf reine Simulation. `allow_ip`-Regeln bleiben weiterhin sofort wirksam, automatische Rate-Limit-Sperren behalten ihren Enforce-Vertrag und die Umsetzung vermeidet neue GET-Mutationen, Token in URLs sowie 500-anfällige harte Abhängigkeiten an Vorschau- oder Alt-Schemadaten. |
| **2.9.760** | 🟢 feat | Admin/Dashboard – Pflichtseiten-Prüfung & Regression-Fixes | **`CMS/admin/modules/dashboard/DashboardModule.php`, `CMS/admin/partials/section-page-shell.php`, `CMS/admin/modules/legal/DeletionRequestsModule.php`, `CMS/assets/css/admin-tabler.css`, `CMS/DOC/admin/dashboard/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste Dashboard-Nice-to-have ab und beheben die begleitend gemeldeten Regressionen**: Das Hauptdashboard prüft jetzt read-only, ob Impressum, Datenschutzerklärung und Cookie-Hinweis korrekt vorhanden bzw. verknüpft sind, zeigt bei Lücken eine kontextuelle Warnung plus Attention-Item mit Deep-Link zu `/admin/legal-sites` und bleibt dabei rein im GET-Lesepfad ohne neue Token-URLs. Parallel akzeptiert die gemeinsame Admin-Section-Shell jetzt neben Modul-Objekten auch modulare Array-Container, sodass `/admin/data-requests` mit Privacy- und Deletion-Modul wieder korrekt initialisiert statt in die generische Bootstrap-Fehlermeldung zu laufen. Zusätzlich wurde der allgemeine Admin-Seitenhintergrund wieder leicht neutral abgedunkelt, damit Karten und Inhalte klarer hervortreten, und der DSGVO-Löschpfad zeigt im Fehlerfall keine rohen Exception-Details mehr im Admin an. |
| **2.9.759** | 🟢 feat | Admin/Recht – Fristenstatus für Datenschutzanfragen | **`CMS/admin/modules/legal/PrivacyRequestsModule.php`, `CMS/admin/modules/legal/DeletionRequestsModule.php`, `CMS/admin/data-requests.php`, `CMS/admin/views/legal/data-requests.php`, `CMS/DOC/admin/legal/README.md`, `CMS/DOC/admin/legal/DSGVO.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste Recht-/DSGVO-Nice-to-have ab**: `/admin/data-requests` zeigt für Auskunfts- und Löschanfragen jetzt einen vollständigen Fristenstatus entlang des Workflows `Eingegangen` → `In Bearbeitung` → `Erledigt` oder `Abgelehnt`. Offene Vorgänge erhalten eine berechnete 30-Tage-Pflichtfrist ab Eingang, ein 7-Tage-Warnfenster und eine Überfällig-Markierung. Fristnahe oder überfällige Anfragen können per expliziter, CSRF-geschützter POST-Aktion an die konfigurierte Admin-Mail eskaliert werden; der Versand läuft über die bestehende Mail-Queue und wird auditierbar protokolliert. GET-Ansichten bleiben rein lesend, es entstehen keine Sicherheitstoken in URLs, keine neue Pflichtmigration und keine 500-anfällige automatische Mailauslösung beim bloßen Öffnen der Adminseite. |
| **2.9.758** | 🟢 feat | Admin/Recht – versionierte DACH-Rechtstextvorlagen | **`CMS/admin/modules/legal/LegalSitesModule.php`, `CMS/admin/legal-sites.php`, `CMS/admin/views/legal/sites.php`, `CMS/DOC/admin/legal/README.md`, `CMS/DOC/admin/legal/LEGAL.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste Recht-/DSGVO-Nice-to-have ab**: `/admin/legal-sites` besitzt jetzt versionierte DACH-Vorlagenprofile für Deutschland, Österreich, Schweiz und ein neutrales DACH-Skelett. Impressum, Datenschutzerklärung, Widerrufsbelehrung und AGB-Skelett können weiterhin einzeln generiert oder als Seite erstellt/aktualisiert werden; dabei speichert das Modul pro Legal-Site zusätzlich angewendetes Profil, Vorlagenversion und Anwendungszeitpunkt. Die erzeugten Texte enthalten klare Hinweise, dass es sich um technische Grundgerüste mit projektspezifischen Platzhaltern handelt und keine Rechtsberatung ersetzt wird. Der Ausbau nutzt die vorhandenen Profilfelder, POST-/CSRF-/PRG-Flows und Audit-Einträge weiter, führt keine GET-Mutationen oder Token-URLs ein und bleibt fail-soft bei ungültigen Typen oder fehlender Berechtigung. |
| **2.9.757** | 🔴 fix | Admin/Performance – tote Aktionsbuttons & Confirm-Resubmit | **`CMS/assets/js/admin.js`, `CMS/admin/partials/footer.php`, `CMS/admin/performance-page.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` beheben die aktuell gemeldete Performance-Regression**: Bestätigungsdialoge für Performance-Aktionen fallen jetzt fail-soft auf `window.confirm` zurück, falls die Modal-/Bootstrap-Runtime nicht verfügbar ist, statt den Submit nach `preventDefault()` wirkungslos hängen zu lassen. Zusätzlich reicht der globale Confirm-Flow beim erneuten Absenden den ursprünglichen Submitter wieder korrekt weiter, sodass buttongebundene Werte wie der WebP-Modus (`dry_run` vs. `convert`) erhalten bleiben. Parallel ist die Medien-Rollback-Aktion serverseitig wieder in der Performance-Whitelist freigeschaltet. Damit reagieren Cache-, Datenbank-, Session- und Medien-Aktionen im Performance-Center wieder verlässlich, ohne neue GET-Mutationen, Token in URLs oder 500-Risiken einzuführen; der Cron-Bereich wurde zusätzlich gegen aktuelle Web-Best-Practices geprüft und nutzt weiterhin Locking, strukturierte JSON-Antworten und direkte/Loopback-Ausführung sauber weiter. |
| **2.9.756** | 🟢 feat | Admin/Performance – Kapazitätswarnungen vor Optimierungsjobs | **`CMS/admin/modules/seo/PerformanceModule.php`, `CMS/admin/views/performance/cache.php`, `CMS/admin/views/performance/database.php`, `CMS/admin/views/performance/media.php`, `CMS/DOC/admin/performance/PERFORMANCE.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste offene Performance-Nice-to-have ab**: Cache-, Datenbank- und Medien-Optimierungen zeigen jetzt vor Massenaktionen einen read-only Kapazitäts-Pre-Check mit freiem Speicher, Disk-Auslastung, empfohlener Reserve, Lastsignal und erkannten parallelen Hintergrundjobs. Dieselben Werte landen zusätzlich im jeweiligen Bestätigungsdialog, sodass Aktionen nicht mehr nur mit pauschalem „wirklich ausführen?“ bestätigt werden. Die Umsetzung bleibt fail-soft, führt keine neuen GET-Mutationen, keine Tokens in URLs und keine blockierenden Zwangsschreibpfade ein; aktive Mail-Queue- oder Medien-Hintergrundläufe werden lediglich transparent als Warnsignal eingeblendet. |
| **2.9.755** | 🟢 feat | Admin/Performance – Historie der Performance-Maßnahmen | **`CMS/admin/modules/seo/PerformanceModule.php`, `CMS/admin/views/seo/performance.php`, `CMS/DOC/admin/performance/PERFORMANCE.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste offene Performance-Nice-to-have ab**: Die Performance-Übersicht zeigt jetzt eine read-only Historie der letzten Maßnahmen aus dem bestehenden `audit_log` – inklusive Zeitpunkt, Bereich, Maßnahme, Auslöser, Ergebnis und, soweit vorhanden, Laufzeit. Neue Performance-Aktionen schreiben dafür zusätzlich standardisierte History-Einträge in datensparsamer Form; solange noch keine dieser Einträge vorliegen, fällt die Übersicht fail-soft auf vorhandene Performance-Audits zurück. Die Umsetzung bleibt rein lesend im GET-Pfad, führt keine Tokens in URLs ein, vermeidet neue 500-Risiken bei fehlenden Audit-Daten und nutzt weiter den bestehenden POST-/CSRF-Vertrag für tatsächliche Mutationen. |
| **2.9.754** | 🔴 fix | Admin/Live-Logs – MariaDB-SQL & Media-Redeclare-Hotfix | **`CMS/core/Services/SeoTrendService.php`, `CMS/admin/modules/subscriptions/PackagesModule.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/modules/settings/SettingsModule.php`, `CMS/admin/views/media/library.php`, `CMS/core/Version.php`, `CMS/update.json`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `README.md` und `Changelog.md` beheben die aktuellen Live-Fehler aus den Logs**: Optionale SEO-Trenddaten werden im Dashboard jetzt erst nach einer MariaDB-kompatiblen, read-only Tabellenprüfung gelesen, sodass fehlende `seo_dashboard_trends`-Tabellen nicht mehr als vorbereitete SELECT-Fehler in den Logs landen. Tabellen-Existenzprüfungen in Medien- und Settings-Modulen verwenden ebenfalls PDO-quotierte `SHOW TABLES LIKE`-Abfragen statt Platzhalter-Prepared-Statements, die je nach MariaDB/PDO-Kombination fehlschlagen können. Die Pakethistorie ersetzt das fragile Backslash-`ESCAPE` in `metadata LIKE`-Klauseln durch ein explizites `!`-Escape und bleibt weiterhin parametrisiert. Zusätzlich sind alle globalen Helper der Medienbibliothek per `function_exists()` geschützt, damit doppelte View-Includes keinen `Cannot redeclare function mediaTypeIcon()`-Fatal mehr erzeugen. Die Änderungen bleiben fail-soft, führen keine neuen GET-Mutationen, keine Token in URLs und keine externen Fetch-Pfade ein. |
| **2.9.753** | 🟠 perf | Admin/SEO – Nice-to-have-Nachprüfung & Audit-Begrenzung | **`CMS/core/Services/SEO/SeoAuditService.php`, `CMS/core/Services/SEOService.php`, `CMS/DOC/admin/seo/SEO.md`, `CMS/DOC/admin/seo/README.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen die erneute Prüfung der Nice-to-haves ab 2.9.725 mit einer Performance-Härtung ab**: Die zentrale SEO-Audit-Datenquelle lädt Seiten und Beiträge nicht mehr ungebremst vollständig, sondern standardmäßig die jeweils 1.000 zuletzt aktualisierten Datensätze pro Inhaltstyp und klemmt explizite Limits serverseitig auf maximal 5.000. Dadurch bleiben SEO-Dashboard, Trend-Live-Fallback und Broken-Link-Report auch bei größeren Installationen reaktionsfähig, während die bestehende POST-/CSRF-Trennung, tokenfreie GET-Ansichten, lokale Broken-Link-Prüfung ohne externe Fetches und fail-softes Verhalten unverändert erhalten bleiben. |
| **2.9.752** | 🎨 style | Admin/UI – Seitenhintergrund & Kartenkontrast | **`CMS/assets/css/admin-tabler.css`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` passen die Admin-Oberflächen visuell nach**: Der allgemeine Admin-Seitenhintergrund ist jetzt etwas dunkler und kühler abgestimmt (`--cms-admin-page-bg`), während Karten und Inhaltsboxen über einen helleren Surface-Token (`--cms-admin-card-bg`) klarer hervortreten. Body, Tabler-Page-Wrapper, Page-Body und Legacy-Inline-Admin-Content nutzen denselben zentralen Hintergrund, damit Listen-, Dashboard- und Modulansichten konsistent wirken. Die Änderung bleibt reines CSS, erzeugt keine neuen Mutationspfade, keine Token in URLs und keine Performance-Last. |
| **2.9.751** | 🔴 fix | Admin/SEO & Performance – Nice-to-have-Nachprüfung | **`CMS/assets/js/admin-seo-redirects.js`, `CMS/core/Services/PerformanceSafetyNetService.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md`, `CMS/DOC/admin/seo/REDIRECTS.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md` und `Changelog.md` härten die Nice-to-haves ab 2.9.725 mit konkretem Fokus auf den gemeldeten 404-Monitor-Übernahmefehler nach**: Ungelöste 404-Einträge werden im Übernehmen-Dialog jetzt wieder zuverlässig als neue Redirect-Regel gespeichert, weil das gemeinsame Redirect-JavaScript im `not-found`-Modus ausschließlich eine echte `redirect_id` aus vorhandenen Redirect-Matches akzeptiert und die interne 404-Log-ID nicht mehr als Bearbeitungs-ID missversteht. Zusätzlich läuft die Dialoglogik robuster über delegierte Klick-Handler, behandelt blockierten Browser-Storage fail-soft und besitzt einen einfachen Modal-Fallback, falls die Bootstrap-Modal-API nicht global verfügbar ist. Die neue Performance-SafetyNet-Schicht bleibt auf GET-Seiten ebenfalls fail-soft, weil Rollback-Verzeichnisse erst bei Snapshot-Mutationen angelegt werden und nicht schon beim Anzeigen der Performance-Seiten Schreibrechte erzwingen. Der POST-/CSRF-Vertrag bleibt unverändert tokenfrei in URLs; es entstehen keine neuen GET-Mutationen und keine 500-anfälligen Serverpfade. |
| **2.9.750** | 🟢 feat | Admin/Performance – Dry-Run & Rollback-Sicherheitsnetz | **`CMS/core/Services/PerformanceSafetyNetService.php`, `CMS/admin/modules/seo/PerformanceModule.php`, `CMS/admin/performance-page.php`, `CMS/admin/views/performance/cache.php`, `CMS/admin/views/performance/database.php`, `CMS/core/Version.php`, `CMS/update.json`, `CMS/DOC/admin/performance/PERFORMANCE.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `README.md` und `Changelog.md` schließen das nächste offene Performance-Nice-to-have ab**: Das Performance-Center zeigt für Cache-Purges und Datenbank-Wartung jetzt read-only Dry-Run-/Impact-Vorschauen statt nur pauschaler Warntexte. `/admin/performance-cache` beziffert vor einem Purge Datei-Cache-Menge, Snapshot-Größe sowie die betroffenen flüchtigen Runtime-Caches und legt vor Datei-/Gesamt-Purge automatisch einen zeitlich begrenzten Datei-Cache-Snapshot an, der über eine explizite Rollback-Aktion wiederhergestellt werden kann – ehrlich nur für Datei-Cache, nicht für APCu/OPcache. `/admin/performance-database` ergänzt eine enginebewusste Wartungsvorschau und erzeugt vor `OPTIMIZE TABLE` bzw. `REPAIR TABLE` automatisch ein eigenständiges Datenbank-Backup als belastbaren Rollback-Pfad; die Rücksicherung läuft kontrolliert über den bestehenden Restore-Mechanismus, der seinerseits vorab erneut einen Sicherheits-Snapshot anlegt. Der Ausbau bleibt auf POST/CSRF für Mutationen beschränkt, führt keine Token in URLs ein, hält GET-Seiten read-only und vermeidet falsche „magische Undo“-Versprechen für DB-Wartung. |

### v2.9.749 — 10. Mai 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.749** | 🟢 feat | Admin/SEO – Trendansicht für Dashboard-KPIs | **`CMS/core/Services/SeoTrendService.php`, `CMS/admin/modules/seo/SeoSuiteModule.php`, `CMS/admin/views/seo/dashboard.php`, `CMS/core/Services/RedirectService.php`, `CMS/core/Bootstrap.php`, `CMS/core/Version.php`, `CMS/update.json`, `CMS/DOC/admin/seo/README.md`, `CMS/DOC/admin/seo/REDIRECTS.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `README.md` und `Changelog.md` schließen das nächste offene SEO-Nice-to-have ab**: `/admin/seo-dashboard` zeigt jetzt drei read-only Sparkline-Karten für Ø SEO-Score, 404-Pfade und Redirect-Regeln. Eine neue Trendpersistenz verdichtet die Kennzahlen stündlich über den bestehenden `cms_cron_hourly`-Hook in einer kleinen Snapshot-Tabelle; im Dashboard wird der aktuelle Live-Stand zusätzlich ohne GET-Schreibzugriff ergänzt. Solange noch keine oder nur wenige Snapshots vorliegen, fällt die Ansicht fail-soft auf Zeitstempel-basierte Live-Proxies aus Inhalts-, Redirect- und 404-Daten zurück. Parallel berechnet `RedirectService` die 404-/Redirect-Statistiken für Redirect-Manager und 404-Monitor nun über echte Aggregatabfragen statt nur über die auf 200 Einträge begrenzte Tabellenansicht, sodass Trendkarten und bestehende KPI-Boxen dieselbe belastbare Datenbasis nutzen – ohne Token in URLs und ohne neue 500-anfällige Mutationspfade. |
| **2.9.748** | 🟢 feat | Admin/SEO – Live-Hinweise im Editor | **`CMS/assets/js/admin-seo-editor.js`, `CMS/admin/views/partials/content-seo-score-panel.php`, `CMS/admin/views/pages/edit.php`, `CMS/admin/views/posts/edit.php`, `CMS/core/Version.php`, `CMS/update.json`, `CMS/DOC/admin/seo/SEO.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `README.md` und `Changelog.md` schließen das nächste offene SEO-Nice-to-have ab**: Seiten- und Beitragseditoren ergänzen jetzt nicht blockierende Live-Hinweise direkt im bestehenden SEO-Editor-Stack. Die Realtime-Auswertung prüft zusätzlich die H1-Eindeutigkeit, indem sie sichtbaren Titel und H1-Überschriften im Inhalt zusammenführt; Seiten mit aktivem `hide_title` müssen die eindeutige H1 daher aus dem Content liefern. Darüber hinaus rendert das SEO-Score-Panel eine empfehlende Badge-Zeile für Title, Description, H1, Keyphrase und Bild-Alt-Texte. Der Ausbau bleibt rein klientenseitig und read-only, verändert keine POST-/CSRF-Verträge, führt keine Token in URLs ein und blockiert Speichern oder Veröffentlichen bewusst nicht. |
| **2.9.747** | 🟢 feat | Admin/SEO – Broken-Link-Report | **`CMS/core/Services/SeoBrokenLinkService.php`, `CMS/admin/modules/seo/SeoSuiteModule.php`, `CMS/admin/views/seo/technical.php`, `CMS/admin/seo-page.php`, `CMS/core/Bootstrap.php`, `CMS/core/Version.php`, `CMS/update.json`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/seo/README.md`, `CMS/DOC/admin/seo/SEO.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md`, `README.md` und `Changelog.md` schließen das nächste offene SEO-Nice-to-have ab**: `/admin/seo-technical` ergänzt jetzt einen lokalen, read-only Broken-Link-Report mit geplanter Cron-Aktualisierung, manuellem Rerun sowie Ignore-Liste. Statt eines externen Crawlers kombiniert der neue Service interne Links aus Inhalten, XML-Sitemap-Einträge, aktive Redirect-Ziele und ungelöste 404-Monitor-Pfade zu einer fail-soften Übersicht pro Zielpfad inklusive Treffer pro Quelle und beobachteter 404-Hits. Mutationen bleiben auf POST/CSRF für Rerun und Ignore beschränkt; es gibt bewusst keine Token in URLs, keinen externen Fetch-Pfad und keinen neuen 500-anfälligen GET-Mutationsvertrag. |
| **2.9.746** | 🔴 fix | Admin/Themes & Design – Font-Manager-Nachschärfung | **`CMS/admin/modules/themes/FontManagerModule.php`, `CMS/DOC/admin/themes-design/FONTS.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen die Nachprüfung des letzten Font-Manager-Nice-to-haves ab**: Beim Löschen lokal gespeicherter Fonts blieb die Runtime bislang an verwaisten Settings hängen, wenn die entfernte Schrift noch als `font_heading` oder `font_body` ausgewählt war. Dadurch referenzierten Frontend-CSS-Variablen und Admin-Status weiter einen gelöschten Font-Slug, bis irgendein späteres Speichern zufällig auf einen gültigen Wert normalisierte. Der Löschpfad räumt nun unmittelbar die betroffenen Runtime-Zuordnungen auf, setzt aktive Heading-/Body-Referenzen fail-soft auf `system-ui` zurück, entfernt den zugehörigen `font_stack_<slug>`-Eintrag und protokolliert die Bereinigung im Audit-Kontext – ohne neuen Schreibpfad, ohne Token-URL und ohne zusätzlichen 500-Risikopfad. |
| **2.9.745** | 🔴 fix | Admin/Medienverwaltung – Preset-Nachschärfung & Release-Sync | **`CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/library.php`, `README.md`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/core/Version.php`, `CMS/update.json` und `Changelog.md` schließen die Nachprüfung der letzten Medien-Nice-to-haves ab**: Persönliche Filter-Presets in `/admin/media` sichern jetzt den vollständigen normalisierten Bibliothekszustand einschließlich der read-only Verwaist-Altersgrenze. Zuvor gingen Presets mit aktivem `orphan_days`-Filter beim Wiederherstellen still auf einen unvollständigen Zustand zurück, obwohl Permalink, GET-Filter und PRG-Redirects diesen Query-Parameter bereits korrekt transportierten. Die Bibliothek rendert den Preset-Zustand nun konsistent inklusive Orphan-Hinweis, der Reset-Link berücksichtigt den aktiven Verwaist-Filter sichtbar, und die Root-README ist wieder auf den aktuellen Release-Stand synchronisiert. |
| **2.9.744** | 🟢 feat | Admin/SEO – SERP- & Snippet-Preview | **`CMS/admin/views/partials/content-preview-card.php`, `CMS/assets/js/admin-seo-editor.js`, `CMS/admin/modules/seo/SeoSuiteModule.php`, `CMS/admin/seo-page.php`, `CMS/admin/views/seo/meta.php`, `CMS/assets/js/admin-seo-meta.js`, `CMS/core/Version.php`, `CMS/update.json`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/seo/SEO.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md` und `Changelog.md` schließen das nächste offene SEO-Nice-to-have ab**: Die Vorschau in Seiten- und Beitragseditoren rendert SEO-Snippets jetzt parallel als Google-Desktop-, Google-Mobile- und Social-OG-Variante, statt nur eine einzelne SERP-Karte zu spiegeln. Zusätzlich ergänzt `/admin/seo-meta` einen globalen Live-Preview-Modus für Startseite, Blog-Archiv, Kategorie- und Tag-Archive. Die Ansicht kombiniert bestehende Meta-Defaults, Titel-Template, Separator und das globale Social-Fallback-Bild rein read-only; es gibt bewusst keine neue Schreibroute, keine Token in URLs und keine neuen 500-anfälligen Mutationspfade. Fehlen Taxonomiedaten, arbeitet die Vorschau fail-soft mit sicheren Beispielwerten weiter. |
| **2.9.743** | 🟢 feat | Admin/Medienverwaltung – Bulk-Alt-Texte | **`CMS/admin/modules/media/MediaModule.php`, `CMS/admin/media.php`, `CMS/admin/views/media/library.php`, `CMS/assets/js/admin-media-integrations.js`, `CMS/core/Version.php`, `CMS/update.json`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md` und `Changelog.md` schließen das nächste offene Medien-Nice-to-have ab**: `/admin/media` ergänzt einen Bulk-Alt-Text-Editor für sichtbare Dateien in Listen- und Grid-Ansicht. Die Eingaben bleiben im bestehenden POST-/CSRF-/PRG-Vertrag, werden nur für ausgewählte Dateien gespeichert und erzeugen keine neuen Token- oder Query-Parameter-Pfade. Alt-Texte werden fail-soft aus der vorhandenen Tabelle `cms_media` geladen; fehlt ein Datensatz, wird er beim Speichern minimal ergänzt, statt die Bibliothek oder SEO-Auswertungen zu entkoppeln. Zusätzlich synchronisieren Rename-, Move- und Delete-Aktionen vorhandene `cms_media.filepath`-Einträge bestmöglich weiter, sodass Alt-Texte nach Aufräumaktionen nicht an alten Pfaden hängen bleiben und der Ausbau ohne neue 500-Risiken bleibt. |
| **2.9.742** | 🟢 feat | Admin/Medienverwaltung – Verwaiste Medien erkennen | **`CMS/core/Services/MediaService.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/library.php`, `CMS/admin/media.php`, `CMS/core/Version.php`, `CMS/update.json`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md` und `Changelog.md` schließen das nächste offene Medien-Nice-to-have ab**: `/admin/media` ergänzt jetzt eine globale, read-only Orphan-Prüfung für Medien, die laut `MediaUsageService` aktuell nirgends in Beiträgen oder Seiten verwendet werden und deren Upload- oder Änderungsdatum seit mindestens 30, 90, 180 oder 365 Tagen zurückliegt. Die Analyse liest Uploads rekursiv über einen begrenzten Inventurpfad, schließt geschützte System- und Member-Pfade bewusst aus und bleibt ein tokenfreier GET-Zustand, der in Bibliotheksnavigation und PRG-Redirects erhalten bleibt. Die UI empfiehlt ausschließlich die manuelle Prüfung im Ordner bzw. per Direktöffnung der Datei; es gibt bewusst keine automatische Löschaktion und keinen neuen Mutations- oder Tokenpfad, sodass der Ausbau fail-soft und ohne 500-Risiko bleibt. |
| **2.9.741** | 🟢 feat | Admin/Medienverwaltung – Bulk-Tagging & Bulk-Kategorisierung | **`CMS/core/Services/Media/MediaRepository.php`, `CMS/core/Services/MediaService.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/media.php`, `CMS/admin/views/media/library.php`, `CMS/assets/js/admin-media-integrations.js`, `CMS/core/Version.php`, `CMS/update.json`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md` und `Changelog.md` schließen das nächste offene Medien-Nice-to-have ab**: Die bestehende Bulk-Toolbar kann ausgewählte Dateien jetzt gesammelt kategorisieren, entkategorisieren und mit Tags versehen. Tag-Aktionen unterstützen Hinzufügen, Ersetzen, Entfernen und vollständiges Leeren; Ordner werden bei Datei-Metadatenaktionen kontrolliert übersprungen statt als Fehlerpfad einen HTTP-500 zu riskieren. Medien-Tags werden im vorhandenen `media-meta.json`-Store gespeichert, serverseitig längen- und mengenbegrenzt normalisiert und in Listen-/Grid-Ansicht sichtbar gemacht. Jede Bulk-Aktion schreibt einen datensparsamen Audit-Eintrag mit Auswahl-, Erfolgs-, Skip- und Fehlerzahlen; Mutationen bleiben im bestehenden POST/CSRF/PRG-Vertrag, während Filter- und Permalink-URLs weiterhin tokenfrei bleiben. |
| **2.9.740** | 🟢 feat | Admin/Medienverwaltung – Filter-Presets & teilbare Filterzustände | **`CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/library.php`, `CMS/assets/js/admin-media-integrations.js`, `CMS/core/Version.php`, `CMS/update.json`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/audit/NiceToHave-CHECKLISTE.md` und `Changelog.md` schließen das nächste offene Medien-Nice-to-have ab**: Die Bibliothek unterstützt jetzt persönliche Filter-Presets pro Admin-Benutzer für Suchbegriff, Kategorie, Verwendung sowie die erweiterten Filter nach Dateityp, Endung, Größenklasse und Änderungszeitraum. Gespeichert wird ausschließlich ein klein gehaltener, serverseitig allowlist-normalisierter Zustand im bestehenden `settings`-Store mit `autoload = 0`; beschädigte oder fehlende Preset-Daten fallen fail-soft zurück, statt `/admin/media` mit einem HTTP-500 zu destabilisieren. Zusätzlich rendert die Bibliothek einen sichtbaren Filter-Link auf Basis des aktuellen Query-Strings – bewusst ohne CSRF- oder Sicherheitstoken – und PRG-Redirects nach Bibliotheksaktionen behalten die erweiterten Filter jetzt konsistent bei, sodass Rename, Move, Kategoriezuweisung und Bulk-Aktionen den aktiven Suchkontext nicht mehr still verlieren. |
| **2.9.739** | 🟢 feat | Admin/Themes & Design – Font-Nutzungsanalyse | **`CMS/admin/modules/themes/FontManagerModule.php`, `CMS/admin/views/themes/fonts.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/themes-design/README.md`, `CMS/DOC/admin/themes-design/FONTS.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md` und `Changelog.md` schließen das nächste offene Nice-to-have im Bereich Themes & Design ab**: `/admin/font-manager` ergänzt jetzt eine read-only Font-Nutzungsanalyse, die Theme-Scan, konfigurierte Heading-/Body-Fonts, lokale `custom_fonts` und den Asset-Status der gespeicherten CSS-/Font-Dateien zu einer klaren Runtime-Sicht zusammenführt. Admins sehen dadurch sofort, welche Fonts lokal aktiv, nur lokal vorhanden, weiterhin extern erkannt oder als ungenutzte lokale Fonts eher Aufräumkandidaten sind. Die Analyse erzeugt keine neue Mutation, keine Token in URLs und keine rohen Dateiinhalte; unvollständige Assets oder fehlende Scan-Daten werden lediglich als Hinweise dargestellt, statt den Font Manager mit einem HTTP-500 abzureißen. |
| **2.9.738** | 🟢 feat | Admin/Aboverwaltung – Historie pro Paket & Bestellung | **`CMS/admin/modules/subscriptions/OrdersModule.php`, `CMS/admin/views/subscriptions/orders.php`, `CMS/admin/modules/subscriptions/PackagesModule.php`, `CMS/admin/views/subscriptions/packages.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/subscription/README.md`, `CMS/DOC/admin/subscription/ORDERS.md`, `CMS/DOC/admin/subscription/PACKAGES.md`, `CMS/DOC/admin/subscription/SUBSCRIPTION-SYSTEM.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md` und `Changelog.md` schließen das letzte offene Nice-to-have der Aboverwaltung ab**: `/admin/orders` zeigt jetzt eine read-only Historie für sichtbare Bestellungen, Paketzuweisungen und Exportereignisse, während `/admin/packages` eine Pakethistorie für Erstellung, Aktualisierung, Statuswechsel und Löschung ergänzt. Beide Ansichten nutzen ausschließlich begrenzte Auszüge aus dem vorhandenen `audit_log`, geben keine rohen Metadaten, Kontakt-Payloads oder Tokens aus und fallen bei fehlendem oder temporär nicht lesbarem Audit-Log mit neutralem Hinweis aus, ohne die Admin-Seiten in einen HTTP-500 zu ziehen. Neue Paket-Audit-Einträge setzen zusätzlich die Paket-ID als `entity_id`, damit künftige Historien präziser paketbezogen gefunden werden. |
| **2.9.737** | 🟢 feat | Admin/Aboverwaltung – Exporte für Orders & Paketnutzung | **`CMS/admin/orders.php`, `CMS/admin/modules/subscriptions/OrdersModule.php`, `CMS/admin/views/subscriptions/orders.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/subscription/README.md`, `CMS/DOC/admin/subscription/ORDERS.md`, `CMS/DOC/admin/subscription/SUBSCRIPTION-SYSTEM.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md` und `Changelog.md` schließen das nächste offene Nice-to-have der Aboverwaltung ab**: `/admin/orders` bietet jetzt zwei read-only CSV-Exporte an – für Bestellungen sowie für Paketnutzung. Der Orders-Export übernimmt optional die aktuelle Statusfilterung, der Nutzungs-Export verdichtet `subscription_usage` mit aktuellem Abo-/Plankontext. Beide Downloads bleiben bewusst GET-only ohne CSRF- oder Sicherheitstoken in der URL, begrenzen große Exportmengen fail-soft, härten CSV-Zellen gegen Formula Injection durch Spreadsheet-Apps und schreiben nur datensparsame Audit-Einträge ohne unnötige PII in den Logkontext. |
| **2.9.736** | 🟢 feat | Admin/Member Aboverwaltung – Ablaufwarnungen & Renewal-Hinweise | **`CMS/core/SubscriptionManager.php`, `CMS/core/Services/MemberService.php`, `CMS/admin/modules/subscriptions/OrdersModule.php`, `CMS/admin/views/subscriptions/orders.php`, `CMS/member/subscription.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/subscription/README.md`, `CMS/DOC/admin/subscription/ORDERS.md`, `CMS/DOC/admin/subscription/SUBSCRIPTION-SYSTEM.md`, `CMS/DOC/member/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md` und `Changelog.md` schließen das nächste offene Nice-to-have der Aboverwaltung ab**: Die bislang nur speicherbare Einstellung `notification_before_expiry` bekommt einen echten read-only Runtime-Vertrag. `SubscriptionManager` leitet jetzt zentrale Renewal-/Ablaufhinweise aus `next_billing_date`, `end_date`, globaler Auto-Verlängerung und Kulanzzeit ab. `/admin/orders` zeigt daraus ein Hinweisfenster mit fälligen bzw. überfälligen Mitgliedschaften, und `/member/subscription` nutzt denselben Vertrag für Laufzeit- und Verlängerungshinweise statt am dekorativen Feld `expires_at` vorbeizulaufen. Der Ausbau bleibt bewusst lesend: keine neue Schreibroute, kein zusätzlicher Token-Pfad, kein Mailversand und fail-softes Verhalten bei fehlenden oder unvollständigen Daten. |
| **2.9.735** | 🟢 feat | Admin/Member Dashboard – Onboarding-Analytics & Abschlussrate | **`CMS/admin/modules/member/MemberDashboardModule.php`, `CMS/admin/views/member/onboarding.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/member/README.md`, `CMS/DOC/member/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md` und `Changelog.md` schließen das letzte offene Member-Dashboard-Nice-to-have ab**: `/admin/member-dashboard-onboarding` zeigt jetzt read-only Onboarding-Analytics mit einer transparent dokumentierten Abschlussrate. Die Kennzahlen leiten sich ausschließlich aus bestehenden Signalen ab – aktuell aktiven Konten, konfigurierten Profilfeldern, MFA-/Passkey-Adoption und erfolgreichen Logins der letzten 30 Tage – und bleiben bewusst aggregiert, ohne neue Tracking-Tabelle, zusätzliche POST-Route, Token in URLs oder personenbezogene Detailausgabe. Falls optionale Datenquellen wie `activity_log` oder `passkey_credentials` fehlen, fällt die Ansicht fail-soft auf sichere Defaultwerte zurück und protokolliert nur datensparsame Diagnosedetails serverseitig. |
| **2.9.734** | 🟢 feat | Admin/Member Dashboard – Widget-Sortierung mit Persistenz | **`CMS/admin/modules/member/MemberDashboardModule.php`, `CMS/admin/views/member/widgets.php`, `CMS/admin/views/member/plugin-widgets.php`, `CMS/assets/js/admin-member-dashboard.js`, `CMS/member/includes/class-member-controller.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/member/README.md`, `CMS/DOC/admin/themes-design/DASHBOARD-WIDGETS.md`, `CMS/DOC/member/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md` und `Changelog.md` schließen das nächste Member-Dashboard-Nice-to-have ab**: `/admin/member-dashboard-widgets` unterstützt jetzt eine persistente Reihenfolge für Kern-Widgets und eigene Info-Widgets, `/admin/member-dashboard-plugin-widgets` ergänzt denselben Auf/Ab-Fallback zusätzlich zum bestehenden Drag-&-Drop. Die Sortierung bleibt progressiv erweitert: Browser-DnD ist Komfort, Pfeilbuttons sind der robuste Fallback. Gespeichert wird ausschließlich per bestehendem CSRF-geschütztem POST-Flow; Widget-, Custom- und Plugin-Reihenfolgen werden serverseitig allowlist-basiert normalisiert, Duplikate entfernt und fehlende bekannte Keys kontrolliert ergänzt. Die Member-Runtime respektiert die Reihenfolge eigener Info-Widgets weiterhin fail-soft, die Plugin-Reihenfolge bleibt konsistent, und die Admin-Preview zeigt die daraus abgeleitete Reihenfolge ohne neue GET-Mutationen, Token in URLs oder technische Fehleroffenlegung. |
| **2.9.733** | 🟢 fix | Admin/Member Dashboard – Nice-to-have-Nachprüfung | **`CMS/admin/modules/member/MemberDashboardModule.php`, `CMS/admin/views/member/dashboard.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/member/README.md`, `CMS/DOC/member/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md` und `Changelog.md` prüfen und härten die letzten Nice-to-haves nach**: Der read-only Preview-Modus bleibt ein reiner GET-/Lesepfad ohne neue Schreibaktion und ohne CSRF-Token in URLs, lädt Plugin-Widget-Metadaten für die Übersicht nur noch einmal pro Request und zeigt die gespeicherte Bereichsreihenfolge nun sichtbar in der Vorschau an. Die Nachprüfung orientiert sich an OWASP CSRF, Input Validation, XSS Output Encoding, Error Handling, Authorization und Logging: Farben bleiben hex-allowlisted, Preview-Daten werden kontextnah escaped, unbekannte Widget-/Plugin-Werte fallen fail-soft aus und technische Fehlerdetails werden nicht im Admin-UI offengelegt. |
| **2.9.732** | 🟢 feat | Admin/Member Dashboard – Preview-Modus | **`CMS/admin/modules/member/MemberDashboardModule.php`, `CMS/admin/views/member/dashboard.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/member/README.md`, `CMS/DOC/member/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md` und `Changelog.md` schließen das nächste Member-Dashboard-Nice-to-have ab**: `/admin/member-dashboard?preview=1` zeigt eine read-only Vorschau der gespeicherten Member-Dashboard-Runtime-Konfiguration. Die Vorschau nutzt denselben Settings-Vertrag wie `/member/dashboard`, zeigt Dashboard-Status, Welcome-Bereich, Schnellstart, Statistik-Kacheln, Onboarding, eigene Info-Widgets, sichtbare Plugin-Widgets, Kern-Widgets, Profilfelder und Benachrichtigungstexte mit sicheren Defaults an und erzeugt keine neue POST-Aktion, keinen zusätzlichen CSRF-Token-Pfad und keinen Token in der URL. Ungültige Farben, unbekannte Widgets oder deaktivierte Plugin-Widgets fallen fail-soft aus der Preview, statt einen HTTP-500 auszulösen. Zusätzlich protokollieren Member-Dashboard-Schreibfehler keine rohen Exception-Messages mehr im Audit-Kontext, sondern nur noch die Exception-Klasse. |
| **2.9.731** | 🔴 fix | Admin/Nachprüfung der letzten Nice-to-haves | **`CMS/admin/modules/media/MediaModule.php`, `CMS/admin/modules/users/UsersModule.php`, `CMS/admin/modules/users/RolesModule.php`, `CMS/core/SchemaManager.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/users-groups/README.md`, `CMS/DOC/admin/users-groups/USERS.md`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md` und `Changelog.md` härten die zuletzt umgesetzten Nice-to-haves gegen bekannte Fehler, unvollständige Schema-Verträge, Best Practice, Sicherheit und Fehleroffenlegung nach**: Medien-Derivat-Jobs übernehmen unerwartete Exception-Texte nicht mehr in die sichtbare Job-Fehlerliste und protokollieren stattdessen nur datensparsame Diagnosedaten. Benutzer-Speichern/-Löschen sowie Rollen-/Rechte-Schreibaktionen geben interne Exception-Nachrichten nicht mehr in Admin-Alerts oder Fehlerreport-Payloads zurück. Zusätzlich unterstützt das `audit_log`-Schema den von `AuditLogger` und Profilansicht bereits vorgesehenen Severity-Wert `error`, inklusive Runtime-Migration für bestehende Installationen. |
| **2.9.730** | 🟢 feat | Admin/Benutzer & Gruppen – Profil-Sicherheitsereignisse | **`CMS/admin/modules/users/UsersModule.php`, `CMS/admin/views/users/edit.php`, `CMS/DOC/admin/users-groups/README.md`, `CMS/DOC/admin/users-groups/USERS.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das letzte offene Benutzer-&-Gruppen-Nice-to-have ab**: `/admin/users?action=edit&id=...` zeigt bei bestehenden Benutzerprofilen jetzt die letzten begrenzten Login- und Sicherheitsereignisse aus `audit_log` an. Die Abfrage nutzt nur zusammenfassende Audit-Felder, verknüpft Einträge über Benutzer-ID, Entity-Bezug und bekannte Auth-Metadaten, rendert keine Roh-Metadaten, Tokens oder Session-Daten und bleibt read-only ohne neuen POST-, CSRF- oder Sicherheitstoken-Pfad. Falls das Audit-Log in Altinstallationen fehlt oder temporär nicht lesbar ist, fällt die Profilkarte fail-soft auf einen neutralen Hinweis zurück, während die Benutzerbearbeitung weiterhin verfügbar bleibt. |
| **2.9.729** | 🟢 feat | Admin/Benutzer & Gruppen – Rollenvergleich / Capability-Diff | **`CMS/admin/modules/users/RolesModule.php`, `CMS/admin/roles.php`, `CMS/admin/views/users/roles.php`, `CMS/DOC/admin/users-groups/README.md`, `CMS/DOC/admin/users-groups/RBAC.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste Benutzer-&-Gruppen-Nice-to-have ab**: `/admin/roles` zeigt jetzt einen read-only Rollenvergleich an, der zwei Rollen aus der bestehenden Rechte-Matrix per GET vergleicht und gemeinsame sowie abweichende Capabilities gruppiert ausweist. Die Auswahl wird serverseitig gegen bekannte Rollen normalisiert, ungültige Werte fallen auf sichere Defaults zurück, und der Ausbau erzeugt keine neue Schreibaktion, keinen zusätzlichen CSRF-/Sicherheitstoken-Pfad und keinen neuen Mass-Assignment-Risikopfad. Damit können Admins Least-Privilege- und Privilege-Creep-Prüfungen direkt in der Rollenverwaltung durchführen, ohne die Matrix speichern zu müssen. |
| **2.9.728** | 🔴 fix | Admin/Medienverwaltung – Nachprüfung der letzten Nice-to-haves | **`CMS/core/Services/MediaService.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/library.php`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` härten die zuletzt umgesetzten Medien-Nice-to-haves nach Best-Practice-, Sicherheits- und Performance-Prüfung nach**: Die read-only Duplikat-Erkennung überspringt sehr große Dateien jetzt im Bibliotheks-View-Pfad, statt mehrere riesige gleich große Archive oder Videos synchron per SHA-256 zu hashen. Die direkte Verwendungsanzeige normalisiert Bearbeitungslinks zusätzlich direkt in der View fail-closed auf interne Beitrags-/Seiten-Edit-Routen; unerwartete Zielwerte bleiben als Textreferenz sichtbar, aber nicht klickbar. Außerdem wird `CMS/config/media-processing-job.json` beim Laden größen- und schema-validiert, Pfadlisten werden erneut normalisiert und begrenzt, sodass beschädigte oder unvollständig geschriebene Jobdaten den Medien-Settings-Tab nicht als HTTP-500 destabilisieren. |
| **2.9.727** | 🟢 feat | Admin/Medienverwaltung – direkte Verwendungsanzeige | **`CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/library.php`, `CMS/assets/css/admin.css`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste Medien-Nice-to-have ab**: Die allgemeine Medienbibliothek zeigt jetzt pro Datei direkt an, ob und wo sie in Beiträgen oder Seiten verwendet wird. Die vorhandene `MediaUsageService`-Map wird dafür read-only weitergenutzt, im Modul zu Beitrags-/Seiten- und Feld-Zusammenfassungen verdichtet und in der Listenansicht mit direkten Bearbeitungslinks sowie aufklappbaren weiteren Referenzen dargestellt; die Grid-Ansicht erhält kompakte Zähler- und Feld-Badges. Zusätzlich zeigen die Bibliotheks-KPIs, wie viele sichtbare Dateien eingebunden sind. Der Ausbau erzeugt keine neue Schreibaktion, keinen zusätzlichen Sicherheitstoken-Pfad und keine langen Bild-/Dateioperationen; alle Ausgaben bleiben escaped und ungültige oder fehlende Referenzen fallen auf neutrale Anzeigen zurück. |
| **2.9.726** | 🟢 feat | Admin/Medienverwaltung – WebP-/Thumbnail-Hintergrundjobs | **`CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/settings.php`, `CMS/core/Services/MediaService.php`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste Medien-Nice-to-have ab**: Unter `/admin/media?tab=settings` können Admins jetzt bestehende Bilder in eine WebP-/Thumbnail-Warteschlange legen, den Fortschritt sehen und die Verarbeitung in kleinen Batches fortsetzen oder abbrechen. Der Job speichert seinen Zustand atomar in `CMS/config/media-processing-job.json`, überspringt bereits erzeugte Thumbnail-Varianten, verarbeitet pro POST nur wenige Quellbilder und nutzt die vorhandene `ImageProcessor`-Logik statt neue Bildpfade zu duplizieren. Mutierende Aktionen bleiben im bestehenden Admin-CSRF-/PRG-Vertrag, Einzelfehler werden gezählt und geloggt, sodass defekte Dateien nicht den kompletten Request als HTTP-500 abreißen. |
| **2.9.725** | 🟢 feat | Admin/Medienverwaltung – erweiterte Suche & Filter | **`CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/library.php`, `CMS/assets/css/admin.css`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste Medien-Nice-to-have ab**: Die Bibliothek filtert jetzt nicht mehr nur nach Name/Pfad und Kategorie, sondern zusätzlich serverseitig nach Dateityp, Dateiendung, Größenklasse und Änderungszeitraum. Alle neuen Filter laufen bewusst als GET-Parameter ohne neue Schreibaktion, werden per Allowlist normalisiert, bleiben in Ordner-, Breadcrumb- und Listen-/Grid-Links erhalten und haben einen expliziten Reset-Pfad. Dadurch entsteht kein zusätzlicher CSRF-/Sicherheitstoken-Risikopfad, und ungültige Filterwerte fallen fail-closed auf neutrale Defaults zurück statt einen HTTP-500 zu erzeugen. |
| **2.9.724** | 🔴 fix | Core/Bootstrap & Default-Theme – Redeclare-Fatals | **`CMS/core/SchemaManager.php`, `CMS/themes/cms-default/functions.php`, `CMS/themes/cms-default/includes/theme-runtime-helpers.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md` und `Changelog.md` schließen die aktuellen Live-Fatals aus `error.log`**: Der bisherige `return`-Guard in `SchemaManager.php` konnte bei erneuter Dateiauswertung zu spät kommen, weil die Top-Level-Klasse bereits beim Kompilieren erneut registriert wurde. `SchemaManager` ist jetzt als echte konditionale Klassendeklaration gekapselt, sodass doppelte Includes über unterschiedliche Bootstrap-/Repair-/Installer-Pfade nicht mehr mit `Cannot redeclare class CMS\\SchemaManager` abbrechen. Zusätzlich ist `meridian_nav_menu()` sowohl im Default-Theme-`functions.php` als auch in `includes/theme-runtime-helpers.php` per `function_exists()` geschützt, damit doppelte Theme-Helferladungen nicht erneut in einen Fatal laufen. Der ältere Dashboard-`$sections`-Fatal ist im aktuellen Stand bereits durch die initialisierte Sections-Liste im Rollen-Vorlagenpfad behoben. |
| **2.9.723** | 🔴 fix | Admin/Recht – Matomo-URL-Erkennung | **`CMS/admin/modules/legal/CookieManagerModule.php`, `CMS/core/Services/CookieConsentService.php`, `CMS/DOC/admin/legal/COOKIES.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` härten den Matomo-Self-Hosted-URL-Pfad nach**: Die Servervalidierung hängt nicht mehr an `filter_var()` allein, sondern normalisiert typische korrekte Matomo-URLs robuster – inklusive Copy/Paste-Leerzeichen, IDN-/Intranet-Hosts, `localhost`/IP-Adressen, optionalen Ports, Pfaden und Query-Parametern. Zugangsdaten in URLs bleiben weiterhin verboten, und nur `http`/`https` wird akzeptiert. Admin-Speichern und öffentliche `/cookie-einstellungen` verwenden damit dieselbe URL-Logik; zusätzlich greift die Public-Runtime bei leer gespeicherter Cookie-Matomo-URL nun wirklich auf vorhandene SEO-Matomo-Werte zurück, statt durch einen leeren gespeicherten Cookie-Wert den Fallback zu blockieren. |
| **2.9.722** | 🔴 fix | Admin/Recht – Cookie-Manager & Matomo Self-Hosted | **`CMS/admin/modules/legal/CookieManagerModule.php`, `CMS/core/Services/CookieConsentService.php`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/legal/README.md`, `CMS/DOC/admin/legal/COOKIES.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` beheben den Cookie-/Matomo-Persistenzpfad**: Globale Cookie- und Matomo-Self-Hosted-Einstellungen werden nicht mehr über stille `insert()`/`update()`-Rückgabewerte gespeichert, sondern atomar per `INSERT ... ON DUPLICATE KEY UPDATE` in `settings` geschrieben; Speicherfehler brechen damit sichtbar ab, statt nach dem PRG-Redirect leere Admin-Felder vorzutäuschen. Die öffentliche `/cookie-einstellungen`-Runtime liest dieselben Settings konsistent, nutzt bei leerem Cookie-spezifischem Matomo-URL-Feld kontrolliert konfigurierte SEO-Matomo-Werte als Fallback und aktiviert den Matomo-Transparenzblock nur noch bei tatsächlicher Service- oder Setting-Konfiguration statt durch reine Defaultwerte. |
| **2.9.721** | 🟢 feat | Admin/Medienverwaltung – Duplikat-Erkennung nach Hash | **`CMS/core/Services/MediaService.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/library.php`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste Medien-Nice-to-have ab**: Die Medienbibliothek erkennt sichtbare identische Dateien jetzt serverseitig anhand eines SHA-256-Inhalts-Hashs. Zur Schonung großer Medienordner werden Dateien zuerst nach Byte-Größe vorgruppiert und nur gleich große Kandidaten gehasht. Die UI markiert Duplikatgruppen read-only mit Kurz-Hash und weiteren Pfaden, ohne Dateien automatisch zu löschen, umzubenennen oder bestehende Inhaltsreferenzen zu verändern. |
| **2.9.720** | 🟢 feat | Admin/Dashboard – rollenbasierte Standardvorlagen | **`CMS/admin/modules/dashboard/DashboardModule.php`, `CMS/admin/views/dashboard/index.php`, `CMS/assets/css/admin-dashboard.css`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/dashboard/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das letzte offene Dashboard-Nice-to-have ab**: `/admin` startet persönliche Dashboard-Ansichten jetzt nicht mehr aus einem einzigen generischen Default, sondern aus rollenbasierten Vorlagen für Bereiche, Arbeits-Widgets, Favoriten und deren Reihenfolge. Neue oder zurückgesetzte Benutzeransichten übernehmen damit eine passendere Ausgangslage; gespeicherte persönliche Anpassungen bleiben separat erhalten und können über einen expliziten, CSRF-geschützten Reset sauber auf die Rollen-Vorlage zurückfallen, ohne globale Defaults oder andere Benutzeransichten zu überschreiben. |
| **2.9.719** | 🔴 fix | Admin/Dashboard – Nachhärtung für Recents, Drag-&-Drop & CSS-Asset | **`CMS/admin/index.php`, `CMS/admin/views/dashboard/index.php`, `CMS/admin/partials/sidebar.php`, `CMS/assets/js/admin-dashboard.js`, `CMS/assets/css/admin-dashboard.css`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/admin/dashboard/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` härten die letzten Dashboard-Nice-to-haves nach**: Die browserlokale Liste zuletzt genutzter Admin-Ziele wird beim Lesen und Schreiben nun zusätzlich auf gültige interne Admin-Pfade bereinigt, dedupliziert und größenbegrenzt. Gleichzeitig räumt das Sortier-JavaScript Drop-Zustände robuster auf, und das Dashboard-spezifische CSS läuft als cachebares Seiten-Asset statt inline in der View. Dadurch bleiben Favoriten/Recents stabiler, das Markup etwas schlanker und der Pfad resilienter gegen beschädigte Browserdaten oder unnötigen Inline-Ballast. |
| **2.9.718** | 🟢 feat | Admin/Dashboard – persistente Sortierung für Widgets & Favoriten | **`CMS/admin/index.php`, `CMS/admin/modules/dashboard/DashboardModule.php`, `CMS/admin/views/dashboard/index.php`, `CMS/assets/js/admin-dashboard.js`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/admin/dashboard/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste Dashboard-Nice-to-have ab**: Die Dashboard-Personalisierung speichert jetzt zusätzlich die Reihenfolge der Arbeits-Widgets und Favoriten. Die UI bietet dafür einen progressiv erweiterten Sortierpfad mit Drag-&-Drop als Komfortfunktion und Auf/Ab-Buttons als robustem Fallback; serverseitig werden die übermittelten Reihenfolgen allowlist-basiert normalisiert, fehlende bekannte Keys kontrolliert ergänzt und gemeinsam mit Sichtbarkeit/Favoriten auditierbar gespeichert. Dadurch zeigt `/admin` nicht nur die richtigen Karten und Schnellzugriffe, sondern auch in der persönlichen Arbeitsreihenfolge. |
| **2.9.717** | 🟢 feat | Admin/Dashboard – Favoriten & zuletzt genutzt | **`CMS/admin/modules/dashboard/DashboardModule.php`, `CMS/admin/views/dashboard/index.php`, `CMS/admin/partials/sidebar.php`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/admin/dashboard/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` erweitern das Dashboard um persönliche Favoriten und einen lokalen Recent-Block**: Admins können jetzt vordefinierte Schnellzugriffe als persönliche Favoriten im Dashboard speichern; die Persistenz läuft serverseitig, allowlist-basiert und auditierbar über denselben Preference-Payload wie die übrige Dashboard-Personalisierung. Zusätzlich schreibt die gemeinsame Admin-Sidebar zuletzt genutzte Admin-Ziele browserlokal in `localStorage`, entfernt dabei flüchtige Parameter wie Tokens oder Flash-Werte und zeigt die Liste auf `/admin` fail-soft nur dann an, wenn Web Storage im Browser verfügbar ist. |
| **2.9.716** | 🟢 feat | Admin/Dashboard – einzeln schaltbare Arbeits-Widgets | **`CMS/admin/modules/dashboard/DashboardModule.php`, `CMS/admin/views/dashboard/index.php`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/admin/dashboard/README.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` erweitern die Dashboard-Personalisierung von Bereichsebene auf Widget-Ebene**: Die „Zentrale Arbeitsübersicht“ bleibt als Pflichtsektion sichtbar, ihre einzelnen Karten lassen sich pro Admin-Benutzer nun aber separat aktivieren oder deaktivieren. Zusätzlich erhält die Übersicht mehrere neue, direkt nutzbare Widgets für Nutzerwachstum, Redaktions-Pipeline, Kommentar-Moderation, aktive Sessions, Security Snapshot und System-Stack; Bestell-/Umsatzkarten erscheinen weiterhin nur bei aktivem Order-Modul. Die Persistenz speichert dafür neben `visible_sections` auch `visible_work_overview_widgets` allowlist-basiert und auditierbar, ohne Berechtigungen oder Pflicht-Alerts aufzuweichen. |
| **2.9.715** | 🔴 fix | Admin/Kommentare – Volltext-Option in der Moderation | **`CMS/admin/comments.php`, `CMS/admin/modules/comments/CommentsModule.php`, `CMS/admin/views/comments/list.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` erweitern die Kommentar-Moderation um eine echte Anzeige-Option für den Inhalt**: Unter `/admin/comments` lässt sich der Kommentarinhalt nun zwischen `Ausschnitt` und `Volltext` umschalten. Die Auswahl bleibt über Schnellfilter, Status-Tabs sowie Moderations- und Löschaktionen hinweg erhalten, sodass Admins längere Kommentare vollständig prüfen können, ohne nach jedem Statuswechsel wieder nur auf einen gekürzten Teaser zurückzufallen. |
| **2.9.714** | 🔴 fix | Admin/Beiträge – robuster Modul-Bootstrap im Live-Betrieb | **`CMS/admin/posts.php`, `CMS/admin/modules/posts/PostsModule.php`, `CMS/admin/post-categories.php`, `CMS/admin/post-tags.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` ziehen den noch offenen Live-500 im Beitragsbereich endgültig nach**: Der Beitrags-Entry-Point hängt beim Speichern nicht länger an einem starren globalen `PostsModule`-Klassennamen, sondern löst das Modul jetzt über einen expliziten Methodenvertrag plus Dateireflexion auf. Falls eine produktive Deployment-/OPcache-Situation kurzzeitig inkonsistente Includes liefert, lädt der Bootstrap die Moduldatei gezielt erneut und beendet nicht mehr mit `Class "PostsModule" not found` oder `PostsModule konnte nach dem Laden der Moduldatei nicht aufgelöst werden`. Zusätzlich schützt `PostsModule.php` seine Klassendeklaration gegen erneute Auswertung, und die separaten Admin-Einstiege für Beitrags-Kategorien und -Tags verwenden denselben robusteren Initialisierungspfad für die gemeinsame Beitragsverwaltung. |
| **2.9.713** | 🔴 fix | Admin/Live-Hotfixes – Dashboard, Beiträge, Seiten & Theme-Runtime | **`CMS/core/Router.php`, `CMS/admin/partials/section-page-shell.php`, `CMS/admin/posts.php`, `CMS/admin/pages.php`, `CMS/core/Routing/ThemeRouter.php`, `CMS/themes/cms-default/functions.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen den aktuellen Live-Fehlerblock aus Produktiv-Logs**: Die globale Public-CSRF-Vorprüfung behandelt exakte Admin-/Member-Routen wie `/admin` nicht länger als normales Public-Formular, sodass die Dashboard-Personalisierung nicht mehr vor dem eigentlichen Admin-Handler mit `403 CSRF-Validierung fehlgeschlagen` abgewiesen wird. Parallel lösen die Beitrags- und Seiten-Entry-Points ihre Module jetzt defensiv auch über mögliche namespaced Klassennamen auf und die gemeinsame Section-Shell kapselt Modul-Bootstrap-Fehler fail-soft, statt bei Klassen-/Deploy-Drift mit einem nackten HTTP-500 zu enden. Zusätzlich verhindern Reentrancy-Guards doppelte Includes von `ThemeRouter.php` und dem Default-Theme-`functions.php`, wodurch die gemeldeten Redeclare-Fatals um `CMS\\Routing\\ThemeRouter` und `meridian_nav_menu()` robuster abgefangen werden. |
| **2.9.712** | 🟢 feat | Admin/Benutzer & Gruppen – Gruppen-Massenaktionen | **`CMS/admin/groups.php`, `CMS/admin/modules/users/GroupsModule.php`, `CMS/admin/views/users/groups.php`, `CMS/assets/js/admin-user-groups.js`, `CMS/DOC/admin/users-groups/README.md`, `CMS/DOC/admin/users-groups/GROUPS.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste echte Nice-to-have im Bereich Benutzer & Gruppen ab**: `/admin/groups` unterstützt jetzt Sammelaktionen für Aktivieren, Deaktivieren, Paket zuweisen, Paket entfernen und Löschen direkt aus der Gruppenübersicht. Der Server normalisiert Bulk-Aktionen und Gruppen-IDs konsequent per Allowlist, prüft den aktuellen Bestand fail-soft, protokolliert kritische Gruppenänderungen im Audit-Log und löscht Gruppen inklusive Mitgliedschaften transaktional. Die Admin-UI ergänzt dazu Auswahl-Checkboxen, Paketwahl nur bei passender Aktion, Double-Submit-Schutz und eine explizite Bestätigung für destruktive Sammellöschungen. |
| **2.9.711** | 🔴 fix | Admin/Nachprüfung der letzten Nice-to-haves | **`CMS/core/Auth.php`, `CMS/admin/modules/users/UserSettingsModule.php`, `CMS/admin/views/users/settings.php`, `CMS/admin/modules/pages/PagesModule.php`, `CMS/admin/modules/posts/PostsModule.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen die direkte Nachprüfung der zuletzt eingebauten Nice-to-haves ab**: Der Passwort-Policy-Tester unter `/admin/user-settings` nutzt jetzt auch browserseitig dieselbe Unicode-aware Zeichenzählung wie die Servervalidierung, und die sichtbaren Anforderungen stammen direkt aus der strukturierten Runtime-Policy statt aus statischen UI-Listen. Parallel verlieren die read-only Revisionspfade für Seiten und Beiträge ihr zusätzliches Snapshot-Debug-Logging im Save-Flow, sodass die neuen Vergleichsansichten ihren Vertrag behalten, ohne Logvolumen und Redaktionslatenz unnötig zu erhöhen. |
| **2.9.710** | 🟢 feat | Admin/Benutzer & Gruppen – Passwort-Policy-Tester | **`CMS/core/Auth.php`, `CMS/admin/modules/users/UserSettingsModule.php`, `CMS/admin/views/users/settings.php`, `CMS/DOC/admin/users-groups/AUTH-SETTINGS.md`, `CMS/DOC/admin/users-groups/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das nächste echte Nice-to-have im Bereich Benutzer & Gruppen ab**: Unter `/admin/user-settings` gibt es jetzt einen lokalen Live-Tester für die Passwort-Policy, der exakt denselben Runtime-Vertrag wie `Auth::validatePasswordPolicy()` spiegelt und die erste verletzte Regel unmittelbar anzeigt, ohne Eingaben zu speichern oder mitzuschicken. Gleichzeitig liefert `Auth` die Policy nun strukturiert für UI und Laufzeit aus, zählt Passwortlängen Unicode-sicherer und die Save-Route für Benutzer-/Auth-Settings leakt im Fehlerfall keine rohen Exception-Texte mehr in den Admin-Alert, sondern protokolliert Details nur serverseitig. |
| **2.9.709** | 🟢 feat | Admin/Beiträge – Revisionsvergleich | **`CMS/admin/modules/posts/PostsModule.php`, `CMS/admin/views/posts/edit.php`, `CMS/core/SchemaManager.php`, `CMS/DOC/core/DATABASE-SCHEMA.md`, `CMS/DOC/admin/pages-posts/README.md`, `CMS/DOC/admin/pages-posts/POSTS.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` schließen das letzte offene Nice-to-have im Bereich Seiten & Beiträge ab**: Beiträge sichern vor relevanten Änderungen jetzt automatisch Revisions-Snapshots für Titel, Slugs, Teaser, Status, Kategorie, Tags, Autoren-Anzeigename, Veröffentlichungszeitpunkt sowie DE-/EN-Inhalte. Im Beitragseditor erscheint eine bewusst read-only gehaltene Vergleichsansicht der letzten Revisionen gegen den aktuellen Stand, inklusive kompakter Inhaltszusammenfassungen statt kompletter Rohfassungen. Das hält den Pfad secure-by-default, begrenzt die UI aus Performance-Gründen auf die jüngsten Snapshots und dokumentiert den neuen Revisionsvertrag zusätzlich im Core-Schema und in der Bereichsdoku. |

### v2.9.708 — 9. Mai 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.708** | 🟢 feat | Admin/Seiten – Revisionsvergleich & Dokuabgleich | **`CMS/core/PageManager.php`, `CMS/core/SchemaManager.php`, `CMS/admin/modules/pages/PagesModule.php`, `CMS/admin/views/pages/edit.php`, `CMS/DOC/core/DATABASE-SCHEMA.md`, `CMS/DOC/admin/pages-posts/README.md`, `CMS/DOC/admin/pages-posts/PAGES.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/core/Version.php`, `CMS/update.json`, `README.md` und `Changelog.md` setzen den nächsten Nice-to-have-Schritt für Seiten & Beiträge um**: Seiten-Revisionen speichern jetzt zusätzlich DE-/EN-Titel, Slugs, Inhalte und Status als Snapshot. Im Seiteneditor erscheint daraus eine read-only Vergleichsansicht der letzten Revisionen gegen den aktuellen Stand, damit Redaktionen Unterschiede schnell prüfen können, ohne unbeabsichtigt alte Inhalte zurückzuschreiben. Gleichzeitig wurde die Prüfliste an den realen Editor-Stack angepasst: SEO-, Lesbarkeits- und Vorschauprüfungen waren für Seiten und Beiträge bereits vorhanden und gelten nicht länger als offenes Nice-to-have. |
| **2.9.707** | 🔴 fix | Admin/Nachprüfung der letzten Nice-to-haves | **`CMS/admin/views/dashboard/index.php`, `CMS/admin/modules/system/AiServicesModule.php`, `CMS/admin/views/system/ai-services.php`, `CMS/admin/modules/posts/PostsModule.php`, `CMS/admin/views/posts/categories.php`, `CMS/admin/views/posts/tags.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/admin/system-settings/AI-SERVICES.md`, `CMS/DOC/ai/AI-SERVICES.md`, `CMS/DOC/admin/pages-posts/POSTS.md`, `CMS/core/Version.php`, `CMS/update.json` und `README.md` schließen die explizite Nachprüfung der letzten Nice-to-have-Releases gegen Best Practice, Sicherheit und Performance ab**: Dashboard-Links aus den personalisierbaren Karten werden jetzt fail-closed auf interne Ziele begrenzt, falls eine unerwartete URL in die View gelangt. Die AI-Provider-Verwaltung hält nach Speichern oder Löschen immer einen gültigen aktiven Provider-Eintrag, solange noch Provider vorhanden sind; zusätzlich vermeiden Secret-Felder Browser-Autofill und unnötige Eingabehilfen. Bei Bulk-Löschungen für Kategorien und Tags wird der Content-Cache nicht mehr pro Einzellöschung innerhalb der Transaktion geleert, sondern einmalig nach erfolgreichem Commit, was unnötige Cache-Clears und inkonsistente Zwischenzustände reduziert. |
| **2.9.706** | 🟢 feat | Admin/Seiten & Beiträge – Kategorien/Tags Nice-to-have | **`CMS/admin/post-categories.php`, `CMS/admin/post-tags.php`, `CMS/admin/modules/posts/PostsModule.php`, `CMS/admin/views/posts/categories.php`, `CMS/admin/views/posts/tags.php`, `CMS/DOC/admin/pages-posts/README.md`, `CMS/DOC/admin/pages-posts/POSTS.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json` und `README.md` schließen den nächsten Nice-to-have-Durchlauf im Bereich Seiten & Beiträge ab**: Beitragskategorien und Tags bieten jetzt eigene Bulk-Löschpfade direkt in den Taxonomie-Listen. Der Server normalisiert die ausgewählten IDs per Allowlist, prüft den aktuellen Datenbestand, erzwingt bei Beitragsbezug gültige Ersatzkategorien bzw. Ersatztags und verhindert, dass ein Ersatzziel selbst Teil der Lösch-Auswahl ist. Die Aktionen laufen CSRF-geschützt per POST, nutzen den bestehenden Confirm-Flow, werden transaktional ausgeführt und schreiben nach erfolgreicher Sammelaktion einen Audit-Eintrag. |
| **2.9.705** | 🔴 fix | Admin/AI Services & Dashboard-CSRF | **`CMS/admin/modules/system/AiServicesModule.php`, `CMS/core/Security.php`, `CMS/core/Version.php`, `CMS/update.json`, `README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/system-settings/AI-SERVICES.md`, `CMS/DOC/ai/AI-SERVICES.md`, `CMS/DOC/admin/dashboard/README.md` und `CMS/DOC/admin/dashboard/DASHBOARD.md` schließen die direkte Nachprüfung der letzten AI-/Dashboard-Anpassungen**: Die AI-Services-Seiten liefen bei der Modulinitialisierung in einen falschen `Database::getInstance()`-Aufruf, obwohl die Core-DB-API `Database::instance()` bereitstellt; dadurch konnten alle AI-Unterseiten als generischer Serverfehler enden. Das Modul nutzt jetzt die korrekte API und kapselt Initialisierungsprobleme zusätzlich fail-soft mit datensparsamer Logger-Ausgabe. Parallel akzeptiert die CSRF-Schicht mehrere parallel erzeugte Admin-Formular-Tokens pro Action innerhalb des TTL-Fensters, invalidiert den tatsächlich verwendeten Token aber weiterhin nach erfolgreicher Prüfung. Damit bleiben stale Tabs und das Dashboard-Personalisierungsformular bedienbar, ohne den One-Time-Token-Vertrag grundsätzlich aufzugeben. |
| **2.9.704** | 🟢 feat | Admin/Seiten & Beiträge – Kommentare Nice-to-have | **`CMS/core/Services/CommentService.php`, `CMS/admin/modules/comments/CommentsModule.php`, `CMS/admin/comments.php`, `CMS/admin/views/comments/list.php`, `CMS/assets/js/admin-comments.js`, `CMS/DOC/admin/pages-posts/COMMENTS.md`, `CMS/DOC/admin/pages-posts/README.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/core/Version.php`, `CMS/update.json` und `README.md` schließen den ersten Nice-to-have-Durchlauf im Bereich Kommentare ab**: Die Moderationsliste unter `/admin/comments` bietet jetzt neben Status-Tabs echte serverseitige Schnellfilter für Autor, E-Mail, Kommentartext und Beitragstitel sowie zusätzliche Filter nach Autorentyp und Beitragsbezug (`verknüpft`/`verwaist`). Aktive Filter bleiben über Einzel- und Bulk-Aktionen hinweg per PRG erhalten. Gleichzeitig schaltet die UI bei Mehrfachauswahl in einen sichtbaren Batch-Modus und deaktiviert parallele Zeilenaktionen, damit Moderation und destruktive Sammelaktionen nicht gegeneinander ausgelöst werden. |
| **2.9.703** | 🟢 feat | Admin/AI Services Prompt-Vorlagen | **`CMS/core/Services/AI/AiSettingsService.php`, `CMS/core/Services/AI/AiProviderGateway.php`, `CMS/core/Services/AI/EditorJsTranslationPipeline.php`, `CMS/core/Services/AI/Providers/AbstractPromptingAiProvider.php`, `CMS/admin/ai-page.php`, `CMS/admin/modules/system/AiServicesModule.php`, `CMS/admin/views/system/ai-services.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/system-settings/AI-SERVICES.md`, `CMS/DOC/admin/system-settings/README.md`, `CMS/DOC/ai/AI-SERVICES.md`, `CMS/DOC/admin/README.md`, `CMS/core/Version.php`, `CMS/update.json` und `README.md` schließen das letzte direkte AI-Services-Nice-to-have ab**: Der Admin verwaltet jetzt Prompt-/Vorlagen je Bereich über `ai.prompts`. Die Translation-Vorlage wirkt direkt in der Editor.js-Live-Pipeline für Prompting-Provider, während Content- und SEO-Vorlagen als geprüfte Briefing-/Leitplankenbasis für kommende Generatoren bereitstehen. Prompt-Änderungen laufen über den bestehenden CSRF-/PRG-Vertrag, werden ohne Prompt-Volltext auditierbar protokolliert und behalten serverseitige Pflichtregeln gegen Prompt Injection, Systemprompt-Leaks und Secret-Offenlegung bei. |
| **2.9.702** | 🟢 feat | Admin/AI Services Nice-to-haves | **`CMS/admin/ai-page.php`, `CMS/admin/modules/system/AiServicesModule.php`, `CMS/admin/modules/system/AiEditorJsTranslationModule.php`, `CMS/admin/views/system/ai-services.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/system-settings/AI-SERVICES.md`, `CMS/DOC/ai/AI-SERVICES.md`, `CMS/DOC/admin/README.md`, `CMS/core/Version.php`, `CMS/update.json` und `README.md` setzen den ersten Nice-to-have-Durchlauf für AI Services um**: Das AI-Dashboard zeigt jetzt request- und quota-nahe Nutzungsdaten sowie die letzten Generierungsläufe direkt aus `audit_log`, inklusive Erfolgsquote, Tages-/Monatsbudgets, Provider-Auslastung und Verlaufstabelle. Die Editor.js-Translation ergänzt bei aktivierten Request-Metriken Zeichen- und Blockzahlen im Audit-Kontext, damit Monitoring und Historie belastbar bleiben. Der gesamte Verlauf bleibt bewusst datensparsam: Rohprompts, Volltexte und Secrets werden weder im Dashboard ausgegeben noch als Teil dieses Nice-to-have-Ausbaus eingeführt. |
| **2.9.701** | 🟢 feat | Admin/Dashboard Nice-to-haves | **`CMS/admin/index.php`, `CMS/admin/modules/dashboard/DashboardModule.php`, `CMS/admin/views/dashboard/index.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/dashboard/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md`, `CMS/DOC/admin/README.md` und `README.md` setzen den ersten Nice-to-have-Durchlauf für das Admin-Dashboard um**: Die Startseite war bislang bewusst kuratiert, aber nicht benutzerbezogen anpassbar. Admins können jetzt pro Benutzer festlegen, welche Dashboard-Bereiche sichtbar bleiben – etwa Aufmerksamkeit, Systemstatus, Sicherheit/Performance, Bestellungen oder letzte Aktivitäten. Kritische Alerts und die zentrale Arbeitsübersicht bleiben fail-closed sichtbar, die Speicherung läuft CSRF-geschützt per POST über die gemeinsame Section-Shell, wird serverseitig über eine feste Allowlist normalisiert, auditierbar in `audit_log` protokolliert und als nicht-autoloadende `settings`-Option pro Admin-Benutzer gespeichert. 


### v2.9.631 — 9. Mai 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.631** | 🔴 fix | Admin/Review, Updates & Doku | **`CMS/core/Services/UpdateService.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `Changelog.md` und `README.md` schließen die direkte Nachprüfung der 2.9.630-Anpassungen gegen Vollständigkeit, Best Practice und Nachvollziehbarkeit**: Der Diagnosepfad nutzte die persistierte Update-Historie zwar bereits in `/admin/cms-logs`, las sie aber weiter über ein `LIMIT ?` mit `execute([$limit])`, obwohl `PDOStatement::execute()` Array-Werte laut PHP-Dokumentation standardmäßig als String behandelt; das kann bei nativen MySQL-Prepares an `LIMIT '12'` scheitern und die neue Diagnose-Historie still leeren. Zusätzlich verwendete `UpdateService::logUpdate()` weiter nur `time()` für `update_log_*`-Schlüssel, sodass mehrere Update-Ereignisse derselben Sekunde einander überschreiben konnten. Die Update-Historie bindet ihr Limit jetzt explizit als Integer, liest nur die benötigte Nutzlast und erzeugt kollisionsarme, lexikografisch sortierbare Log-Schlüssel; parallel wurden die bei der Review gefundenen Versionsdrifts in `Changelog.md` und `CMS/DOC/admin/README.md` bereinigt. |
| **2.9.630** | 🔴 fix | Admin/Cross-Checks System ↔ Diagnose ↔ Performance | **`CMS/admin/modules/system/SystemInfoModule.php`, `CMS/admin/views/system/cms-logs.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/diagnose/README.md`, `CMS/DOC/admin/diagnose/DIAGNOSE.md`, `CMS/DOC/admin/system-settings/README.md`, `CMS/DOC/admin/system-settings/SYSTEM.md`, `CMS/DOC/admin/performance/PERFORMANCE.md` und `README.md` schließen den ersten Cross-Durchlauf für System ↔ Diagnose ↔ Performance gegen den Nachvollziehbarkeits-Vertrag**: Die Diagnose-Logzentrale zeigte bisher fast nur Dateilogs, PHP Error-Log und `admin.documentation`, obwohl System-, Backup- und Performance-Aktionen längst im zentralen `audit_log` liefen und erfolgreiche Updates zusätzlich eine persistierte Update-Historie erzeugten. `/admin/cms-logs` bindet jetzt ein operatives Betriebs-Audit für System-, Backup-, Monitoring-, Cron-/Queue- und Performance-Spuren direkt ein und ergänzt es um die Update-Historie des Update-Services, sodass Diagnose die Effekte von Cache-, Session-, Cron-, Backup-, Performance- und Update-Aktionen im echten Logkontext sichtbar macht. |
| **2.9.629** | 🔴 fix | Admin/Diagnose | **`CMS/admin/modules/system/SystemInfoModule.php`, `CMS/admin/views/system/email-alerts.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/diagnose/README.md`, `CMS/DOC/admin/diagnose/DIAGNOSE.md`, `CMS/DOC/admin/system-settings/SYSTEM.md` und `README.md` schließen den ersten Diagnose-Durchlauf gegen den Health-Check-Vertrag**: Der Diagnosebereich behandelte den konfigurierten Health-Endpunkt bislang nur als dekorative Markierung; sobald `monitor_health_endpoint_enabled` gesetzt war, galt der Check als bestanden, ohne den Endpunkt tatsächlich zu prüfen. Das Monitoring normalisiert den konfigurierten Health-Pfad jetzt host-lokal auf relative Site-Pfade und ruft ihn real gegen die eigene Installation auf, sodass `/admin/monitor-health-check` Status, Laufzeit und Fehler des Endpunkts anzeigt statt bloß eines gespeicherten Schalters. |
| **2.9.628** | 🔴 fix | Admin/System & Doku | **`CMS/admin/backups.php`, `CMS/admin/views/system/backups.php`, `CMS/admin/modules/system/BackupsModule.php`, `CMS/core/Services/BackupService.php`, `CMS/admin/updates.php`, `CMS/admin/views/system/updates.php`, `CMS/admin/modules/system/UpdatesModule.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/system-settings/README.md`, `CMS/DOC/admin/system-settings/BACKUP.md`, `CMS/DOC/admin/system-settings/UPDATES.md` und `README.md` schließen den ersten System-&-Doku-Durchlauf gegen den Restore-/Theme-Update-Vertrag**: Das Update-Center prüfte Theme-Updates zwar bereits über den gesicherten Update-Service, bot aber keinen zentralen Installpfad dafür an. Gleichzeitig listete der Backup-Bereich vorhandene Sicherungen nur auf und erlaubte Erstellen/Löschen, obwohl Bereichsdoku und Prüfliste Download sowie Wiederherstellung versprachen. Theme-Updates lassen sich jetzt direkt aus `/admin/updates` installieren, und `/admin/backups` unterstützt Download von Datenbank- und Datei-Artefakten sowie echte Restore-Aktionen mit automatisch erzeugtem Rollback-Snapshot vor jeder Wiederherstellung. |
| **2.9.627** | 🔴 fix | Admin/Plugins | **`CMS/includes/functions/admin-menu.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/PANEL-INTEGRATION.md`, `CMS/DOC/admin/plugins/PLUGINS.md` und `README.md` schließen den ersten Plugin-Durchlauf gegen den dynamischen Menü-Registry-Vertrag**: Plugin-Unterseiten konnten `cms_admin_menu` im selben Request mehrfach auslösen – einmal im Plugin-Router und später erneut beim Sidebar-Rendering – während `add_menu_page()` und `add_submenu_page()` identische Slugs blind erneut anhängten. Die Admin-Menü-Registry ersetzt bestehende Plugin- und Child-Einträge mit gleichem Slug jetzt idempotent, sodass dynamische Plugin-Menüs und Unterseiten stabil bleiben, statt Sidebar-Dubletten oder aufgeblähte Menüstrukturen zu erzeugen. |
| **2.9.626** | 🔴 fix | Admin/Sicherheit | **`CMS/core/Services/AntispamService.php`, `CMS/core/Services/CommentService.php`, `CMS/admin/modules/security/SecurityAuditModule.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/security/README.md`, `CMS/DOC/admin/security/ANTISPAM.md`, `CMS/DOC/admin/security/SECURITY-AUDIT.md`, `CMS/DOC/core/SECURITY.md`, `README.md` sowie `365CMS.DE-PLUGINS/cms-contact/**` schließen den ersten Sicherheits-Durchlauf gegen den öffentlichen AntiSpam-Vertrag**: Die globale AntiSpam-Konfiguration wirkte zuvor nur im Kommentarpfad, während aktive `cms-contact`-Formulare mit eigener Honeypot-/Captcha-/Sessionlogik an Mindestzeit, Linklimit, leerem User-Agent und Blacklist vorbeiliefen. Ein neuer zentraler `AntispamService` wertet diese Regeln jetzt gemeinsam für Kommentare und Kontaktformulare aus, Kontakt-Templates senden dafür einen Formular-Timestamp mit, und das Security-Audit bewertet die AntiSpam-Runtime nicht mehr nur anhand des Kommentarwegs. |
| **2.9.625** | 🔴 fix | Admin/Recht | **`CMS/admin/modules/legal/PrivacyRequestsModule.php`, `CMS/admin/modules/legal/DeletionRequestsModule.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/legal/README.md`, `CMS/DOC/admin/legal/DSGVO.md`, `CMS/DOC/admin/legal/DELETION-REQUESTS.md` und `README.md` schließen den ersten Rechts-Durchlauf gegen den DSGVO-Nachvollziehbarkeits-Vertrag**: Die Sammelroute `/admin/data-requests` bearbeitete Auskunfts- und Löschanfragen zwar funktional, hielt zentrale Zustandswechsel aber nicht im Audit-Log fest; zusätzlich akzeptierten die Servermodule Ablehnungen ohne belastbare Begründung. Datenschutzanfragen erzeugen jetzt nachvollziehbare Audit-Einträge für Bearbeitung, Abschluss, Ablehnung, Löschausführung und finales Entfernen, und Ablehnungen verlangen serverseitig eine echte Begründung statt nur eines HTML-`required`-Flags. |
| **2.9.624** | 🔴 fix | Admin/Performance | **`CMS/admin/modules/seo/PerformanceModule.php`, `CMS/admin/views/performance/settings.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/performance/README.md`, `CMS/DOC/admin/performance/PERFORMANCE.md` und `README.md` schließen den ersten Performance-Durchlauf gegen den Kompressions-Vertrag**: Die globale Performance-Seite bot mit `perf_gzip` einen speicherbaren Schalter für „GZIP/Brotli-Auslieferung“ an, obwohl die echte Kompression im Core ausschließlich über Apache-/Proxy-Konfiguration erkannt und bereitgestellt wird; der Setting-Wert wurde nirgends zur Laufzeit ausgewertet und blieb damit dekorativ. Der Performance-Admin zeigt Server-Kompression jetzt nur noch lesend als Infrastrukturstatus an, statt einen wirkungslosen CMS-Toggle zu speichern. |
| **2.9.623** | 🔴 fix | Admin/SEO | **`CMS/core/Services/SEO/SeoSettingsStore.php`, `CMS/core/Services/SEO/SeoHeadRenderer.php`, `CMS/admin/views/seo/social.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/seo/README.md`, `CMS/DOC/admin/seo/SEO.md` und `README.md` schließen den ersten SEO-Durchlauf gegen den Social-Defaults-Vertrag**: Die in `/admin/seo-social` gespeicherten globalen Social-Defaults wurden bislang nur administrativ gepflegt, aber im echten Frontend-Head-Renderer nicht konsistent als Fallback verwendet; stattdessen liefen Inhalte ohne eigene Social-Meta-Werte weiter mit fest codiertem `og:type`, `twitter:card`, leerem Fallback-Bild und starrem `SITE_NAME`. `SeoSettingsStore` liefert die Social-Defaults jetzt normalisiert an die Laufzeit, `SeoHeadRenderer` nutzt sie für Homepage- und Inhalts-Fallbacks tatsächlich für `og:type`, `og:image`, `twitter:card` und `og:site_name`, und die Social-UI bewirbt keinen nicht unterstützten OG-Type mehr. |
| **2.9.622** | 🔴 fix | Admin/Themes & Design | **`CMS/core/Services/Landing/LandingPluginService.php`, `CMS/core/Services/LandingPageService.php`, `CMS/admin/modules/landing/LandingPageModule.php`, `CMS/admin/views/landing/page.php`, `CMS/themes/cms-default/partials/home-landing.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/themes-design/README.md`, `CMS/DOC/admin/landing-page/LANDING-PAGE.md`, `CMS/DOC/core/HOOKS-REFERENCE.md` und `README.md` schließen den ersten Themes-&-Design-Durchlauf gegen den Landing-Page-Plugin-Override-Vertrag**: Der Plugins-Tab der Landing Page speicherte bislang nur dekorative `enabled`-/`sort_order`-Werte, während die dokumentierten Overrides für `header`, `content` und `footer` weder im Admin zugewiesen noch im Default-Theme gerendert wurden. Landing-Plugins arbeiten jetzt mit einem echten `render_callback`-Vertrag, der Admin speichert bereichsgenaue Zuweisungen pro Plugin, und das Default-Theme rendert aktive Overrides nun tatsächlich im Landing-Frontend; ungültige oder nicht renderbare Zuordnungen fallen geschlossen auf die Core-Sektionen zurück. |
| **2.9.621** | 🔴 fix | Admin/Aboverwaltung | **`CMS/core/SubscriptionManager.php`, `CMS/core/Auth.php`, `CMS/core/Services/UserService.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/subscription/README.md`, `CMS/DOC/admin/subscription/SUBSCRIPTION-SYSTEM.md`, `CMS/DOC/member/README.md` und `README.md` schließen den ersten Aboverwaltungs-Durchlauf gegen den Default-Plan-Vertrag**: Das in `/admin/subscription-settings` gewählte Standardpaket für neue Mitglieder wurde bislang zwar gespeichert, wirkte aber weder im öffentlichen Registrierungsflow noch beim Anlegen neuer Member-Konten im Admin – und blieb damit dekorativ. `SubscriptionManager` stellt jetzt einen zentralen Runtime-Pfad für die Standardpaket-Zuweisung bereit; `Auth::register()` und `UserService::createUser()` wenden diesen für neue Mitglieder automatisch an, ohne bestehende aktive oder Trial-Abos zu überschreiben. Zusätzlich beschreibt die Bereichsdoku jetzt den tatsächlichen Setting-Namensraum und den gültigen Laufzeitvertrag der Aboverwaltung. |
| **2.9.620** | 🔴 fix | Admin/Member Dashboard | **`CMS/admin/modules/member/MemberDashboardModule.php`, `CMS/member/includes/class-member-controller.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/member/README.md`, `CMS/DOC/member/README.md` und `README.md` schließen den ersten Member-Dashboard-Durchlauf gegen den Trennungs-Vertrag von Admin und Runtime**: Die öffentliche Member-Runtime hing ihre Einstellungen bislang an `MemberDashboardModule::getData()` und damit an einen admin-geschützten Read-Pfad, der für normale Mitglieder bewusst leere Daten liefert. Dadurch konnten aktivierte Dashboard-Settings wie `dashboard_enabled`, Frontend-Module, Onboarding oder Notification-Center im echten `/member/dashboard`-Pfad verloren gehen und Mitglieder trotz gespeicherter Konfiguration auf `/member/profile` umleiten. Das Modul stellt jetzt einen eigenen Runtime-Lesepfad für persistierte Member-Settings bereit, und der `MemberController` nutzt diesen getrennt von der Admin-Oberfläche, sodass Konfigurationsänderungen wieder sichtbar im Mitgliederbereich ankommen, ohne die Admin-Seite für Nicht-Admins zu öffnen. |
| **2.9.619** | 🔴 fix | Admin/Benutzer & Gruppen | **`CMS/core/Auth.php`, `CMS/includes/functions/roles.php`, `CMS/core/Services/UserService.php`, `CMS/admin/modules/users/RolesModule.php`, `CMS/themes/cms-default/register.php`, `CMS/themes/cms-default/forgot-password.php`, `CMS/views/auth/cms-auth.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/users-groups/README.md`, `CMS/DOC/admin/users-groups/RBAC.md`, `CMS/DOC/admin/users-groups/AUTH-SETTINGS.md` und `README.md` schließen den ersten Benutzer-&-Gruppen-Durchlauf gegen Rollenmatrix- und Passwort-Policy-Vertrag**: Nicht-Admin-Capability-Prüfungen hängen jetzt an derselben Rollenmatrix wie Rollenverwaltung und User-Service – inklusive der produktiv genutzten Legacy-Core-Capabilities `manage_settings`, `manage_users`, `manage_pages`, `edit_all_posts`, `manage_media`, `view_analytics` und der AI-Rechte – statt auf lokale Fallback-Arrays in `Auth::hasCapability()` zurückzufallen. Zusätzlich spiegeln Default-Theme- und Core-Auth-Formulare für Registrierung und Passwort-Reset nun denselben 12-Zeichen-/Komplexitätsvertrag wie `Auth::validatePasswordPolicy()`, statt mit veralteten 8-Zeichen-Hinweisen oder schwächerer Template-Validierung auseinanderzulaufen. |
| **2.9.618** | 🔴 fix | Admin/Medienverwaltung | **`CMS/core/Services/MediaService.php`, `CMS/assets/js/admin-media-integrations.js`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/media/README.md`, `CMS/DOC/admin/media/MEDIA.md`, `CMS/DOC/admin/README.md` und `README.md` schließen den ersten Medien-Durchlauf gegen den Featured-Replace-Vertrag**: Das Ersetzen von Beitrags-/Seitenbildern erzwingt serverseitig jetzt immer die feste Bild-Allowlist `jpg`, `jpeg`, `png`, `gif`, `webp`, `bmp`, `ico`, statt indirekt von den allgemeinen Bibliotheks-Typ-Häkchen abzuhängen; dadurch passen UI, Doku und Backend wieder zusammen, und die Client-Fehlhinweise nennen dieselben erlaubten Formate explizit. |
| **2.9.617** | 🔴 fix | Admin/Seiten & Beiträge | **`CMS/admin/modules/posts/PostsModule.php`, `CMS/core/Routing/ThemeRouter.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/pages-posts/README.md`, `CMS/DOC/admin/pages-posts/POSTS.md`, `CMS/DOC/admin/README.md` und `README.md` schließen den ersten Durchlauf für `Seiten & Beiträge` gegen den öffentlichen Taxonomie-Vertrag**: Ändern sich Kategorie- oder Tag-Slugs, werden jetzt automatische Archiv-Redirects angelegt; zusätzlich kann der Blog-Dispatcher alte Query-Werte aus `?category=` und `?tag=` über diese Redirect-Spur wieder auf den aktuellen Slug auflösen, sodass bestehende Theme-Links und Altverweise nicht mehr in 404 enden. |
| **2.9.616** | 🔴 fix | Admin/AI Services | **`CMS/admin/modules/system/AiServicesModule.php`, `CMS/core/Services/AI/AiSettingsService.php`, `CMS/admin/views/system/ai-services.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/system-settings/AI-SERVICES.md`, `CMS/DOC/ai/AI-SERVICES.md`, `CMS/DOC/admin/README.md` und `README.md` schließen den ersten AI-Services-Durchlauf gegen den Prüfpunkt „Generierte Inhalte nur nach expliziter Bestätigung übernehmen“**: Die Translation-Konfiguration erzwingt den Preview-/Diff-Schritt jetzt serverseitig beim Laden und Speichern, statt ihn als dekorativen Toggle optional abschaltbar zu lassen; die Admin-UI dokumentiert den Review-Schritt als festen Sicherheitsvertrag, und Checkliste sowie AI-Doku spiegeln diese verpflichtende Bestätigung nun explizit wider. |
| **2.9.615** | 🔴 fix | Admin/Dashboard | **`CMS/core/Services/DashboardService.php`, `CMS/admin/modules/dashboard/DashboardModule.php`, `CMS/DOC/admin/PRUEF-CHECKLISTE.md`, `CMS/DOC/admin/README.md`, `CMS/DOC/admin/dashboard/README.md`, `CMS/DOC/admin/dashboard/DASHBOARD.md` und `README.md` ziehen den ersten Dashboard-Durchlauf der neuen Admin-Prüf-Checkliste auf einen belastbaren Fail-Soft-Vertrag**: Statistikquellen für Benutzer, Content, Medien, Sessions, Sicherheit, Performance, System und Bestellungen werden jetzt segmentweise mit strukturierten Fallbacks geladen, sodass ein einzelner Ausfall nicht mehr die komplette Admin-Startseite abreißt; zusätzlich erscheint bei degradierten Blöcken ein verständlicher Warnhinweis mit Deep-Link auf `CMS Logs`, und Checkliste sowie Bereichsdoku dokumentieren den Dashboard-Durchlauf nun explizit. |
| **2.9.614** | 🔴 fix | Admin/Seiten & Beiträge – Featured-Image-Speichern | **`CMS/core/Services/ContentMediaPlacementService.php`, `CMS/core/Services/EditorJs/EditorJsUploadService.php` und `CMS/admin/views/partials/featured-image-picker.php` härten den Speichern-Flow für Beitrags- und Seitenbilder nach**: Temporäre Featured-Image-Uploads werden beim Speichern weiterhin in den Slug-Ordner verschoben, aber Fehler bei Datei-/Metadaten-Synchronisation werden nun strukturiert geloggt und führen nicht mehr zu einem nackten HTTP-500, wenn das Bild bereits übernommen wurde. Falls die Datei trotz Metadatenfehler schon am Ziel liegt, verwendet der Relocator den Zielpfad weiter; außerdem bewirbt und akzeptiert der gemeinsame Picker nur noch die Backend-Allowlist JPG, PNG, GIF, WebP, BMP und ICO statt generischem `image/*` oder nicht unterstütztem AVIF. |

---

### v2.9.613 — 6. Mai 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.613** | 🔴 fix | Admin/Sites, Beiträge & Medien – Datei-Allowlist Feinschliff | **`CMS/admin/views/media/featured.php` und `CMS/assets/js/admin-media-integrations.js` schließen die erneute Best-Practice-Nachprüfung des Beitrags-/Site-Medien-Replace-Flows ab**: Die lokale Clientprüfung akzeptiert Ersatzbilder jetzt nur noch, wenn eine serverseitig erlaubte Dateiendung vorhanden ist und der Browser-MIME-Typ entweder leer oder passend zur Backend-Allowlist ist; dadurch reichen gespoofte generische Bild-MIME-Typen nicht mehr aus, um geblockte Endungen clientseitig als gültig erscheinen zu lassen. Zusätzlich bewirbt die Dateiauswahl nun auch den vom Backend akzeptierten BMP-Alias `image/x-bmp`, damit UI-Hinweis und `MediaService`-Validierung vollständig deckungsgleich bleiben. |
| **2.9.612** | 🔴 fix | Admin/Sites, Beiträge & Medien – Best-Practice-Nachprüfung | **`CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/featured.php`, `CMS/assets/js/admin-media-integrations.js`, `CMS/admin/pages.php`, `CMS/admin/posts.php`, `CMS/admin/modules/pages/PagesModule.php` und `CMS/admin/modules/posts/PostsModule.php` wurden gezielt gegen OWASP-/MDN-Best-Practices für Uploads, PRG und Datei-Vorschauen geprüft**: Der neue Bereich `Medien → Beitrags & Site Medien` akzeptiert Replace-POSTs jetzt nur noch für Pfade, die tatsächlich als Beitrags- oder Seitenbild referenziert sind, statt beliebige gültige Medienpfade über den Spezial-Endpunkt zuzulassen; gleichzeitig nennen Dropzone und Dateiauswahl nur noch die serverseitig erlaubten Bildformate JPG, PNG, GIF, WebP, BMP und ICO, damit Client-Hinweis und Backend-Validierung nicht mehr durch geblockte Formate wie SVG oder AVIF auseinanderlaufen. |
| **2.9.611** | 🔴 fix | Admin/Medien – Featured Images Nachschärfung | **`CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/featured.php` und `CMS/assets/js/admin-media-integrations.js` härten den neuen Bereich `Medien → Beitrags & Site Medien` nach dem Erstrelease gezielt für den Redaktionsalltag nach**: Die Ansicht filtert Verwendungen jetzt zusätzlich sauber auf nur Beiträge oder nur Seiten, erfolgreiche Bild-Ersetzungen markieren nach dem PRG-Redirect wieder exakt die betroffene Referenzzeile mit lokalem Erfolgshinweis, die Ersetzen-Zone unterstützt Drag-&-Drop robuster gegen Fehlbedienung und Browser-Navigation außerhalb der Zielzone, und vor dem Speichern erscheint pro Bild eine lokale Mini-Vorschau der neu gewählten Datei inklusive Dateiinformationen. |
| **2.9.610** | 🟢 feat | Admin/Medien – Beitrags- & Site Medien | **`CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/core/Services/MediaUsageService.php`, `CMS/core/Services/MediaService.php`, `CMS/admin/views/media/featured.php` und `CMS/admin/partials/sidebar.php` führen den neuen Unterpunkt `Medien → Beitrags & Site Medien` ein**: Der Admin erhält damit erstmals eine fokussierte Übersicht aller aktuell als Beitrags- oder Seitenbild verwendeten Medien inklusive Verwendungsnachweis pro Referenz, Direktlinks in die zugehörige Bearbeitung sowie der Möglichkeit, eine bestehende Bilddatei unter derselben Medien-Referenz global zu ersetzen, sodass alle verknüpften Beiträge und Seiten automatisch das neue Bild übernehmen. |

### v2.9.609 — 3. Mai 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.609** | 🔴 fix | Admin/Dokumentation & Core/Mail-Queue/Best-Practice-Nachprüfung | **`CMS/admin/views/system/documentation.php`, `CMS/assets/css/admin.css` und `CMS/core/Services/MailQueueService.php` härten die letzten Doku- und Mail-Fixes nach Web-Best-Practice-Prüfung nach**: Die Doku-Sidebar nutzt nun explizite Navigations-Landmarks und `aria-current="page"`, die eigene Caret-Animation respektiert `prefers-reduced-motion`, und die Mail-Queue bereinigt Betreff sowie Headernamen/-werte bereits vor dem Speichern gegen CRLF-/Header-Injection statt erst beim späteren Versandpfad. |
| **2.9.608** | 🎨 style | Admin/Dokumentation | **`CMS/admin/views/system/documentation.php` und `CMS/assets/css/admin.css` geben der Live-Doku eine klassische Dokumentations-Sidebar**: Wichtige Einstiegsdokumente werden oben als dezenter Schnellzugriff angezeigt, der `/DOC`-Baum nutzt klare Ordnerzeilen mit Caret statt Emoji-Optik, Dateien bekommen ruhige Typ-Badges und Pfadangaben, und die Navigation wirkt wie eine ordentliche technische Dokumentation statt wie ein generiertes Kartenmenü. |
| **2.9.607** | 🎨 style | Admin/Dokumentation | **`CMS/admin/views/system/documentation.php` macht die Live-Doku-Navigation kompakter**: Wichtige Markdown-Einstiege stehen wieder dezent oberhalb des `/DOC`-Baums, Ordner und Unterordner sind standardmäßig eingeklappt, und nur der aktive Dokumentpfad wird automatisch geöffnet, damit große Dokumentationsbäume übersichtlich bleiben. |
| **2.9.606** | 🎨 style | Admin/Dokumentation | **`CMS/admin/modules/system/DocumentationCatalog.php` und `CMS/admin/views/system/documentation.php` stellen die Dokumentation jetzt als dezente Live-Dateibaum-Ansicht des lokalen `/DOC`-Verzeichnisses dar**: Die Navigation bildet Ordner und Unterordner rekursiv anhand des Dateisystems ab, zeigt `README.md` je Ebene immer zuerst, verzichtet auf künstliche Bereichsbeschreibungen, Datei-Excerpts und die bisherige Schnellstart-Topbox, und verschiebt wichtige Markdown-Links ans untere Ende des Menüs. |
| **2.9.605** | 🔴 fix | Core/Cron & Plugins/Mail | **`CMS/core/Services/CronRunnerService.php`, `cms-contact` und `cms-feed` schließen den Cron-/Mail-Pfad für Kontakt- und Feed-Mails an die zentrale Queue an**: Kontakt-Benachrichtigungen und Feed-Digests umgehen die CMS-Mail-Infrastruktur nicht länger per Direktversand, sondern nutzen bei aktiver Queue `MailQueueService` und sonst `MailService`; zusätzlich verarbeitet der CronRunner nach tatsächlich ausgeführten stündlichen Hooks die Mail-Queue nochmals, damit durch Feed-Digests oder andere Plugin-Hourly-Hooks frisch erzeugte Queue-Mails noch im selben Cron-Lauf versendet bzw. sauber in Retry/Logging übernommen werden. |
| **2.9.604** | 🟡 refactor | Admin/Dokumentation | **`CMS/admin/documentation.php`, `CMS/admin/modules/system/DocumentationModule.php`, `CMS/admin/modules/system/DocumentationCatalog.php` und `CMS/admin/views/system/documentation.php` reduzieren die Admin-Dokumentation auf eine lokale Liveansicht des Repository-Verzeichnisses `/DOC`**: Der bisherige Git-/GitHub-/ZIP-Doku-Sync inklusive Remote-Downloadklassen, Sync-POST-Aktion, Sync-Diagnose, GitHub-Buttons und GitHub-Linkmetadaten wurde entfernt; die Ansicht liest Markdown-/CSV-Dokumente jetzt ausschließlich direkt aus dem lokalen `/DOC`-Baum und zeigt lokale Pfade als Quelle an. |
| **2.9.603** | 🔴 fix | Core/Auth & Public-Routing/Best-Practice-Nachprüfung | **`CMS/core/Services/CmsAuthPageService.php` und `CMS/core/Routing/PublicRouter.php` schließen weitere OWASP-/MDN-Prüfpunkte im Passwort-Reset-Flow**: Gültig formatierte Reset-Anfragen erhalten eine Mindestantwortzeit, damit existierende und nicht existierende Konten weniger über Timing unterscheidbar sind; Reset-Token-Seiten überschreiben die globale Referrer-Policy gezielt auf `no-referrer`, damit Token nicht über Referer-Pfade weitergereicht werden; und alle öffentlichen Forgot-Password-POST-Flows redirecten PRG-konform mit HTTP `303`, sodass Reloads keine Reset-Anfrage oder Token-Einlösung erneut absenden. |
| **2.9.602** | 🔴 fix | Core/Auth & Default-Theme/Best-Practice-Nachprüfung | **`CMS/core/Services/CmsAuthPageService.php`, `CMS/themes/cms-default/functions.php` und `CMS/themes/cms-default/includes/theme-utility-helpers.php` härten die letzten Theme- und Live-Log-Fixes nach Web-Best-Practice-Nachprüfung nach**: Passwort-Reset-Anfragen bleiben weiterhin gegen Account-Enumeration konsistent, werden aber zusätzlich zur IP nun auch konto-bezogen begrenzt, damit einzelne Zielpostfächer nicht durch verteilte Requests geflutet werden; Reset-Token-Einlösungen erhalten ein token-bezogenes Limit gegen Brute-Force-Versuche; und die Meridian-Default-Theme-Helfer sind durchgängig mit `function_exists`-Guards geschützt, sodass doppelte Includes nicht nur bei den Font-/Custom-Style-Wrappern, sondern auch bei Utility-Helfern keine Fatal-Redeclarations mehr auslösen. |
| **2.9.601** | 🔴 fix | Admin/Content & Benutzerverwaltung | **`CMS/admin/partials/section-page-shell.php`, `CMS/admin/pages.php`, `CMS/admin/posts.php`, `CMS/admin/roles.php`, `CMS/admin/views/users/roles.php`, `CMS/assets/js/admin-user-groups.js` und `CMS/assets/js/admin-users.js` härten die destruktiven Admin-Flows nach**: Admin-POST-Redirects laufen jetzt PRG-konform über HTTP `303`, damit Browser nach `save`/`delete` nicht an unsauberen POST/Refresh-Pfaden hängenbleiben; Seiten- und Beitragslöschungen leiten nach Erfolg bewusst zurück auf die jeweilige Listenansicht statt erneut auf gelöschte Edit-URLs, was die gemeldeten 500er nach dem Löschen entschärft; und die Löschpfade für Gruppen, eigene Rollen und eigene Rechte sind im UI robuster verdrahtet, sodass Bestätigung und Formularsubmit nicht mehr still an fragilen Modal-/Submitter-Ketten hängen. |
| **2.9.600** | 🔴 fix | Core, Admin & Default-Theme/Live-Log-Härtung | **`CMS/index.php`, `CMS/core/Bootstrap.php`, `CMS/core/Routing/ThemeRouter.php`, `CMS/admin/posts.php`, `CMS/admin/post-categories.php`, `CMS/admin/post-tags.php`, `CMS/admin/modules/menus/MenuEditorModule.php`, `CMS/themes/cms-default/functions.php` und `CMS/themes/cms-default/includes/theme-utility-helpers.php` schließen die akuten Live-Log-Fatals und Warnings in einem 6xx-Batch**: Der globale Fatal-Fallback springt bei Bootfehlern nicht länger blind auf das nicht vorhandene `themes/default/error.php`, sondern rendert zuerst das deklarierte Default-Theme bzw. einen generischen HTML-Fallback; der Admin-Posts-Flow löst `PostsModule` in `posts`, `post-categories` und `post-tags` nun explizit global auf, damit Includes unter `CMS\\Routing\\AdminRouter` nicht mehr an einem nackten Klassennamen scheitern; `Bootstrap` lädt `SecurityRuntimeService` defensiv vor der ersten Runtime-Firewall-Nutzung; `ThemeRouter` und die Meridian-Wrapper werden gegen doppelte Deklaration gehärtet; und der Menu-Editor ersetzt den fragilen URL-RegEx-Pfad durch deterministische Präfixprüfungen, sodass der Warning `preg_match(): Unknown modifier '|'` nicht mehr auftreten kann. |

### v2.9.513 — 3. Mai 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.513** | 🔴 fix | Admin, Core & Docs/Themes & Design | **`CMS/admin/modules/themes/FontManagerModule.php`, `CMS/core/Services/CmsAuthPageService.php` sowie `CMS/DOC/admin/themes-design/README.md`, `DESIGN-SETTINGS.md`, `EDITOR.md`, `CUSTOMIZER.md`, `MARKETPLACE.md`, `CMS-LOGINPAGE.md` und `FONTS.md` schließen den Bereich „Themes & Design“ als eigenen 5xx-Batch ab**: Der Font Manager erkennt verknüpfte lokale Font-Assets aus CSS nun auch dann korrekt, wenn eine CSS-Datei mehrere `url(...)`-Referenzen enthält, sodass Statusanzeige, fehlende Asset-Zähler und Löschbereinigung nicht mehr still nur beim Ein-Datei-Fall stimmen; die CMS Loginpage schützt Passwort-Reset-Anfrage und Token-Einlösung zusätzlich über Core-Rate-Limits pro IP, ohne den bestehenden konsistenten Erfolgsvertrag für Reset-Anfragen aufzugeben; und die Bereichsdokumentation beschreibt jetzt den tatsächlichen Runtime-Stand mit sicherem Theme-Editor statt Roh-Editor, Legacy-Alias `design-settings` → `theme-editor`, gehärtetem Theme-Marketplace, aktuellem Font-Manager-Vertrag und der themeunabhängigen Core-Auth-Oberfläche. |
| **2.9.512** | 🔴 fix | Admin, Core & Default-Theme/Benutzer & Gruppen | **`CMS/core/Auth.php`, `CMS/core/Services/UserService.php`, `CMS/admin/modules/users/UserSettingsModule.php`, `CMS/admin/modules/users/GroupsModule.php`, `CMS/admin/views/users/list.php`, `CMS/admin/views/users/edit.php`, `CMS/admin/views/users/settings.php`, `CMS/admin/views/users/groups.php`, `CMS/assets/js/admin-users.js`, `CMS/assets/js/admin-user-groups.js`, `CMS/themes/cms-default/functions.php`, `CMS/themes/cms-default/includes/theme-auth-helpers.php` sowie `CMS/DOC/admin/users-groups/*.md` schließen den Bereich „Benutzer & Gruppen“ als eigenen 5xx-Batch ab**: Öffentliche Registrierungen übernehmen nun die in `/admin/user-settings` gespeicherte Standardrolle tatsächlich zur Laufzeit und fallen bei administrativen oder ungültigen Altwerten fail-closed auf `member` zurück; die Passwort-Policy mit mindestens 12 Zeichen plus Groß-/Kleinbuchstaben, Ziffer und Sonderzeichen gilt jetzt konsistent auch für das Admin-Erstellen und -Bearbeiten von Benutzern; die Benutzerliste macht Gruppenbezüge sichtbar und benennt Bulk-Aktionen explizit; Benutzer-Löschungen laufen über ein explizites Delete-Formular statt impliziter Submitter-Semantik; und `/admin/groups` pflegt Slug, Paketbezug, Aktiv-Status sowie Mitgliedschaften direkt mit, statt auf Name/Beschreibung reduziert und auf frischen Schemas still am Pflichtfeld `slug` vorbeizulaufen. |
| **2.9.511** | 🔴 fix | Admin & Member/Medien | **`CMS/core/Services/Media/UploadHandler.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/settings.php`, `CMS/admin/views/media/library.php`, `CMS/member/media.php`, `CMS/assets/js/admin-media-integrations.js` sowie `CMS/DOC/admin/media/README.md` und `CMS/DOC/admin/media/MEDIA.md` schließen die Nachprüfung des Medien-Bereichs ab**: gespeicherte Upload-Dateinamen folgen nun einer engeren Allowlist mit Maximal­länge und ohne irreführende Mehrfach-Punkte im Basenamen, sodass Speicherpfade wie `bild.php.jpg` nicht mehr in dieser Form bestehen bleiben; die Bulk-Aktionsschaltfläche der Bibliothek benennt die gewählte Operation jetzt explizit und bleibt ohne gültige Auswahl/Move-Ziel gesperrt; Upload-Feedback in Admin und Member arbeitet mit Live-Regionen; und die bislang im Settings-UI sichtbare Option „Login für Upload erforderlich“ wird nicht länger als frei schaltbarer Modus vorgetäuscht, weil der interne Upload-Endpunkt bewusst dauerhaft authentifizierte Admin-/Member-Kontexte erzwingt. |
| **2.9.510** | 🔴 fix | Admin & Member/Medien | **`CMS/core/Services/MediaService.php`, `CMS/core/Services/Media/UploadHandler.php`, `CMS/core/Services/Media/ImageProcessor.php`, `CMS/core/Services/FileUploadService.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/media.php`, `CMS/assets/js/admin-media-integrations.js`, `CMS/assets/js/member-dashboard.js` sowie `CMS/DOC/admin/media/README.md` und `CMS/DOC/admin/media/MEDIA.md` schließen den Medien-Bereichsaudit als eigenen 5xx-Batch ab**: Die Medien-Einstellungen unter `/admin/media?tab=settings` sind jetzt nicht länger nur dekorativ gespeichert, sondern greifen für Bibliotheks- und Member-Uploads tatsächlich in der Laufzeit — inklusive Jahres-/Monats-Ordnerorganisation, Dateinamensregeln, fail-closed bei deaktivierter Eindeutigkeit, Maximalmaßen für Bilder, Thumbnail-Sätzen `-small/-medium/-large/-banner` und zusätzlichen WebP-Derivaten. Gleichzeitig springen Admin- und Member-Uploader nach erfolgreichen Uploads wieder in den **tatsächlich** genutzten Zielordner zurück, sodass automatisch angelegte Unterordner nicht mehr wie „verschwundene Uploads“ wirken. |
| **2.9.509** | 🔴 fix | Admin/Content-Übersichten | **`CMS/assets/js/admin.js`, `CMS/assets/js/admin-comments.js`, `CMS/admin/views/pages/list.php`, `CMS/admin/views/posts/list.php`, `CMS/admin/views/comments/list.php` und `CMS/DOC/admin/pages-posts/README.md` härten die Listen- und Auswahl-UX in den Inhaltsbereichen nach**: Dropdown-Menüs innerhalb scrollbarer Tabellen öffnen nun auch in Hub-Sites-, Tabellen- und Kommentar-Übersichten sichtbar statt hinter der horizontalen Scrollbox zu verschwinden, Bulk-Aktionsleisten blenden sich nur noch bei echter Auswahl ein, und die Ausführen-Schaltfläche benennt die gewählte Aktion jetzt explizit — etwa „Seiten löschen“ statt eines generischen „Anwenden“. Dadurch wird klarer, welche Massenaktion tatsächlich ausgeführt wird, Fehleingaben Richtung „Entwurf“ statt „Löschen“ werden sichtbar abgefangen, und scheinbar folgenlose Klicks in den Übersichten verlieren ihre versteckte Overflow-Falle. |
| **2.9.508** | 🔴 fix | Admin/Seiten & Beiträge Einstellungen | **`CMS/admin/settings.php`, `CMS/admin/modules/settings/SettingsModule.php`, `CMS/admin/views/settings/general.php`, `CMS/assets/js/admin-settings.js`, `CMS/DOC/admin/pages-posts/README.md` und `CMS/DOC/admin/pages-posts/SETTINGS.md` schließen den Unterbereich „Einstellungen“ als eigenen 5xx-Batch ab**: Der Content-Tab unter `/admin/settings?tab=content` wird jetzt als echter Bereich „Beiträge & Sites – Einstellungen“ gerendert statt irreführend als allgemeine Systemseite, Permalink-/Archiv-Basen sitzen direkt dort statt versteckt unter „Allgemein“, benutzerdefinierte Permalinks werden nur bei aktivem Custom-Preset bearbeitet, und Validierungsfehler behalten Eingaben samt Feldbezug über den Redirect hinweg statt das Formular kommentarlos auf den gespeicherten Altstand zurückschnappen zu lassen. |
| **2.9.507** | 🔴 fix | Admin & Public/Hub-Sites + Tabellen | **`CMS/admin/modules/hub/HubSitesModule.php`, `CMS/admin/hub-sites.php`, `CMS/admin/views/hub/edit.php`, `CMS/core/Services/SiteTable/SiteTableRepository.php`, `CMS/core/Services/SiteTableService.php`, `CMS/core/Services/SiteTable/SiteTableHubRenderer.php`, `CMS/admin/modules/tables/TablesModule.php`, `CMS/admin/site-tables.php`, `CMS/admin/views/tables/edit.php`, `CMS/core/Services/SiteTable/SiteTableTableRenderer.php`, `CMS/assets/js/site-tables.js`, `CMS/assets/css/main.css`, `CMS/DOC/admin/pages-posts/README.md`, `CMS/DOC/admin/pages-posts/TABLES.md` und `CMS/DOC/admin/pages-posts/HUBSITES.md` schließen den Unterbereich Hub-Sites & Tabellen als eigenen 5xx-Batch ab**: Hub-Sites reservieren jetzt auch reale Public-Routen und Archivbasen als Slugs statt später an `/contact`, `/authors`, `/feed`, `/category` oder `/tag` unsichtbar zu scheitern; Legacy-Hubs mit nur gesetztem `table_slug` lösen über Zusatzdomains wieder konsistent auf, öffentliche Hub-Navigation/TOC-Labels folgen dem aktuellen Locale statt fest auf Deutsch zu bleiben, und fehlgeschlagene Saves springen wieder ins Edit-Formular zurück statt kommentarlos auf die Liste. Parallel schließt die Site-Table-Runtime ihre im Editor sichtbaren Schalter nun tatsächlich an das Frontend an: Suche, Sortierung, Paginierung und Zeilenhervorhebung wirken öffentlich sichtbar, `aria-sort` sitzt nur noch auf der aktiv sortierten Spalte, statische Datentabellen verlieren die irreführende `role=\"grid\"`-Umdeutung zugunsten semantischer `<table>`-Struktur, fehlgeschlagene Tabellen-Saves behalten Eingaben und Fehlermeldung im Edit-Formular, und die zuvor nur scheinbar vorhandene Excel-Option verschwindet aus dem Editor zugunsten des real produktiven Export-Vertrags CSV/JSON. |
| **2.9.506** | 🔴 fix | Admin & Public/Kommentare + Inhaltsverzeichnis | **`CMS/core/Routing/PublicRouter.php`, `CMS/themes/cms-default/blog-single.php`, `CMS/core/TableOfContents.php`, `CMS/admin/modules/toc/TocModule.php`, `CMS/admin/table-of-contents.php`, `CMS/admin/views/toc/settings.php`, `CMS/assets/js/admin-comments.js` und `CMS/DOC/admin/pages-posts/POSTS.md` schließen den Unterbereich Kommentare & Inhaltsverzeichnis als eigenen 5xx-Batch ab**: Einzelbeiträge rendern freigegebene Kommentare jetzt wieder sichtbar statt nur ein Formular, besitzen endlich ein echtes `#comments`-Sprungziel, respektieren `allow_comments` im Frontend und halten Formularfehler/-erfolge inline am Beitrag mit erhaltenem Zustand statt bloß als generischen Header-Flash; eingeloggte Nutzer können vorhandene Profildaten konsistent verwenden und optional anonym posten. Im Kommentar-Admin ist veraltete JS-Altlogik entfernt, `Select all` synchronisiert nun korrekt mit Einzelhaken, und Bulk-Aktionen feuern nur noch aus einem konsistenten Auswahlzustand. Parallel respektiert das TOC nun Ausschlusslisten per Pipe **oder** Komma, die Optionen `lowercase`, `hyphenate`, `homepage_toc` und `exclude_css` wirken in der Runtime tatsächlich, `light`/`dark` landen wieder auf funktionierenden Theme-Varianten, die Positionsauswahl beschreibt den realen Vertrag „vor/nach der ersten Überschrift“ korrekt, und die TOC-Adminseite läuft nun auf denselben Section-Shell-Standard wie andere modernisierte Admin-Bereiche. |
| **2.9.505** | 🔴 fix | Public/Beiträge-Kategorien & -Tags | **`CMS/core/Routing/ThemeRouter.php`, `CMS/themes/cms-default/functions.php`, `CMS/themes/cms-default/blog.php` und `CMS/DOC/admin/pages-posts/POSTS.md` schließen den öffentlichen Kategorien-/Tags-Vertrag jetzt an den bereits gehärteten Admin-Stand an**: `/blog?category=...` und `/blog?tag=...` delegieren wieder sauber in die bestehenden Kategorie-/Tag-Archive statt die Auswahl still zu ignorieren, das Default-Theme zählt Sidebar-/Header-Kategorien relationstabellen- und locale-aware statt nur über `posts.category_id`, die Tag-Cloud nutzt wieder den echten Tag-Bestand aus Relationstabelle plus Legacy-Fallback statt eine rohe `posts.tags`-Abfrage ohne Prefix, und die Bereichsdoku beschreibt diese Public-Runtime nun explizit. |
| **2.9.504** | 🎨 style | Admin/Seiten & Beiträge Editor-Aktionen | **`CMS/admin/views/posts/edit.php`, `CMS/admin/views/pages/edit.php`, `CMS/DOC/admin/pages-posts/POSTS.md` und `CMS/DOC/admin/pages-posts/PAGES.md` ziehen die obere Aktionshierarchie im Editor auf einen konsistenteren Kartenvertrag**: Der Einzel-Löschbutton sitzt bei Seiten und Beiträgen jetzt dezent in der jeweiligen Aktionskarte direkt unter den Vorschau-Buttons statt als separater Block, wodurch Speichern, Preview und destruktive Aktion als zusammengehörige Bediengruppe erscheinen; zusätzlich nutzt der Seiten-Editor oben nun denselben Drei-Karten-Aufbau wie die Beiträge, mit Bild plus Aktionen in der mittleren Spalte und Veröffentlichung rechts. |
| **2.9.503** | 🔴 fix | Admin/Beitrags-Kategorien & -Tags | **`CMS/admin/post-categories.php`, `CMS/admin/post-tags.php`, `CMS/admin/views/posts/categories.php`, `CMS/admin/views/posts/tags.php`, `CMS/assets/js/admin.js` und `CMS/DOC/admin/pages-posts/POSTS.md` schließen den Kategorien-/Tags-Unterbereich als eigenen 5xx-Audit-Batch ab**: Validierungsfehler in Taxonomie-Formularen verlieren Eingaben nicht mehr beim Redirect, sondern zeigen die Rückmeldung direkt im Formular mit erhaltenem Zustand; der Kategorien-Löschfallback ohne Bootstrap-Modal respektiert hinterlegte Ersatzkategorien wieder statt legitime Löschungen pauschal abzubrechen; und die Bereichsdoku beschreibt den tatsächlichen Taxonomie-Vertrag nun explizit inklusive Ersatzlogik, Fehlerrückfluss und Bestätigungsdialogen. |
| **2.9.502** | 🔴 fix | Admin/Beiträge | **`CMS/admin/modules/posts/PostsModule.php`, `CMS/admin/views/posts/list.php`, `CMS/admin/views/posts/edit.php` und `CMS/DOC/admin/pages-posts/POSTS.md` schließen den Beiträge-Bereichsaudit mit robusteren Redirect-/Delete-/Bulk-/Taxonomie-Verträgen ab**: Bootstrap-Migrationen loggen Warnungen jetzt strukturiert statt über `error_log()`, Einzel-, Bulk-, Kategorie- und Tag-Mutationen räumen den Inhaltscache konsistent mit auf, Kategorie-/Tag-Löschpfade prüfen Delete-Statements robuster, EN-Slug-Änderungen erzeugen ebenfalls lokalisierte Redirects im aktuellen Präfix-Schema `/en/blog/...` inklusive Legacy-Kompatibilität, die Editor-UI bietet einen sichtbaren Einzel-Löschpfad mit Bestätigung sowie klarere Vorschau-Labels, und die Bereichsdoku beschreibt wieder den tatsächlichen Runtime-Stand statt veralteter 2.9.0-Annahmen. |
| **2.9.501** | 🔴 fix | Admin/Seiten | **`CMS/admin/modules/pages/PagesModule.php`, `CMS/admin/views/pages/list.php`, `CMS/admin/views/pages/edit.php` und `CMS/DOC/admin/pages-posts/PAGES.md` schließen den Seiten-Bereichsaudit mit korrigierten Redirect-/Delete-/Bulk-Verträgen ab**: Lokalisierte Slug-Redirects folgen wieder dem aktuellen Präfix-Schema `/en/...` statt der veralteten Suffix-Variante `.../en`, Legacy-EN-Pfade bleiben per Redirect kompatibel, Einzel- und Bulk-Löschungen validieren Seitenbestände jetzt fail-closed und leeren den Inhaltscache konsistent mit, die Seiten-UI bietet endlich einen sichtbaren Einzel-Löschpfad inklusive Bestätigung sowie klarere Bulk-Rückmeldung, und die Bereichsdoku beschreibt den echten Runtime-Stand statt eines überholten 2.9.0-Bilds. |
| **2.9.500** | 🎨 style | Admin/Dashboard | **`CMS/admin/views/dashboard/index.php`, `CMS/admin/modules/dashboard/DashboardModule.php` und `CMS/DOC/admin/dashboard/README.md` schließen den Dashboard-Bereichsaudit mit kompakteren KPI-/Highlight-Karten, robusterem View-Fallback und aktualisierter Bereichsdoku ab**: Die Admin-Startseite zeigt Kernsignale jetzt dichter und scanbarer, Highlight-Karten nutzen passende Icons statt eines pauschalen Activity-Fallbacks, das Beitrags-KPI besitzt wieder ein korrektes Icon-Mapping, und fehlende `kpis`-/`activity`-Payloads kippen im Renderpfad nicht mehr in fragile Annahmen. |

### v2.9.248 — 2. Mai 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.248** | 🔴 fix | Default-Theme Customizer CSRF | **`CMS/themes/cms-default/admin/customizer.php` und `CMS/themes/cms-default/admin/customizer/helpers.php` trennen den eingebetteten Theme-Editor-Shell-Token wieder sauber vom eigenen Customizer-Token**: Der Default-Theme-Customizer erzeugt erneut `theme_customizer`-Tokens, akzeptiert Shell-vorvalidierte Theme-Editor-POSTs kompatibel und zeigt beim Speichern nicht mehr fälschlich „Sicherheitscheck fehlgeschlagen. Bitte erneut versuchen.“. |
| **2.9.248** | 🔴 fix | Admin/Theme Editor Customizer-Speichern | **`CMS/admin/theme-editor.php` und `CMS/admin/partials/section-page-shell.php` akzeptieren für eingebettete Theme-Customizer eine explizite CSRF-Action-Allowlist mit persistenter Vorprüfung**: Der `cms-phinit`-Customizer kann Tabs wie „Startseite · Empfohlene Artikel“ nun wieder inline speichern, statt vor dem Theme-POST-Handler auf die Theme-Editor-Übersicht zurückgeleitet zu werden und den alten Wert stehen zu lassen; gleichzeitig bleiben andere Theme-Customizer mit eigenen Actions wie `theme_customizer` oder `ptc_customizer` kompatibel. |
| **2.9.248** | 🟠 perf | Admin/Performance offene Punkte umgesetzt | **`CMS/admin/modules/seo/PerformanceModule.php`, `CMS/admin/views/performance/*.php`, `CMS/core/Services/EditorJsRenderer.php`, `CMS/core/Services/AssetOptimizerService.php`, das Default-Theme und die 2FA-Assets setzen die zuvor offenen Performance-Punkte um**: WebP-Massenläufe besitzen nun Dry-Run, Batch-Limit, Manifest-Backups und Rollback, Lazy Loading schont konfigurierbare LCP-/Above-the-fold-Bilder, CSS/JS-Minify erzeugt echte lokale Cache-Dateien statt nur Strategie-Schalter zu speichern, Cache-Purge-Aktionen feuern CDN-/Proxy-Hooks, die Settings zeigen erkannte Brotli/Deflate/zlib-Kompression, und ungenutzte externe QR-Code-Provider wurden entfernt. |
| **2.9.248** | 🔴 fix | Admin/Performance Best-Practice-Nachschärfung | **`CMS/admin/modules/seo/PerformanceModule.php`, `CMS/core/Services/MediaDeliveryService.php`, `CMS/core/Services/SystemService.php` und die Performance-Dokumentation schließen die Nachaudit-Lücken**: Die globale Performance-Seite überschreibt Medien-WebP/EXIF-Schalter nicht mehr unbeabsichtigt, deaktiviertes Medien-Browser-Caching nutzt weiter `ETag`/`Last-Modified` für saubere Revalidierung, Datenbankwartung ermittelt CMS-Tabellen dynamisch und überspringt `REPAIR TABLE` für InnoDB statt sinnlos bzw. engine-widrig zu laufen, und Admin-Hinweise/Doku benennen diese Grenzen explizit. |
| **2.9.248** | 🟠 perf | Admin/Performance Runtime-Verkabelung | **`CMS/core/CacheManager.php`, `CMS/core/Router.php`, `CMS/core/Services/MediaDeliveryService.php`, `CMS/core/Services/MediaService.php`, `CMS/core/Services/EditorJsRenderer.php`, `CMS/admin/modules/seo/PerformanceModule.php`, `CMS/admin/modules/pages/PagesModule.php`, `CMS/admin/modules/posts/PostsModule.php` und die Performance-Views ziehen die Performance-Schalter auf echte Runtime-Wirkung**: deaktivierter Seiten-/Mediencache sendet jetzt Revalidierungs- statt positiver Public-TTL-Header, Medien-Performance-Schalter synchronisieren auf `auto_webp`/`strip_exif`, EXIF-Re-Encoding respektiert die Einstellung, Editor.js-Medien beachten den Lazy-Loading-Schalter, Inhaltsänderungen leeren optional den CMS-Cache, APCu-Statusprüfungen sind robuster, Datenbank-/WebP-Aktionen warnen klarer vor Live-Risiken, und der MFA-QR-Pfad fragt keinen externen QR-Code-Dienst mehr aktiv ab. |
| **2.9.248** | 🔴 fix | Admin/Beiträge & Seiten Sprachspeicherung | **`CMS/admin/modules/posts/PostsModule.php`, `CMS/admin/modules/pages/PagesModule.php`, `CMS/admin/posts.php` und `CMS/admin/pages.php` speichern getrennte DE-/EN-Editorseiten jetzt tatsächlich sprachisoliert**: Beim Speichern der englischen Ansicht werden deutsche Titel-, Slug-, Inhalt- und Kurzfassungsfelder aus dem bestehenden Datensatz erhalten, beim Speichern der deutschen Ansicht bleiben die EN-Felder erhalten, und Inline-Fehlerrenderings ziehen die inaktive Sprachfassung wieder aus dem Persistenzstand statt aus versteckten/stalen Formularwerten. |
| **2.9.248** | 🔴 fix | Core/Public Locale-Routing | **`CMS/core/PageManager.php`, `CMS/core/Router.php`, `CMS/core/Routing/ThemeRouter.php`, `CMS/core/Services/ContentLocalizationService.php`, `CMS/core/Services/PermalinkService.php` und `CMS/themes/cms-default/includes/theme-runtime-helpers.php` ziehen Seiten auf denselben strikten Prefix-Vertrag wie Beiträge**: `/en/...` liefert nur Seiten mit echten EN-Feldern, deutsche Public-Pfade bleiben ohne `/en`, und alte Suffix-Erkennungen wie `.../en` werden nicht mehr als lokalisierte Route normalisiert. |
| **2.9.248** | 🟢 feat | Admin/Coverbild-Auswahl & Media API | **`CMS/admin/views/partials/featured-image-picker.php`, `CMS/core/Services/EditorJs/EditorJsMediaService.php`, `CMS/core/Services/EditorJs/EditorJsImageLibraryService.php` und `CMS/core/Services/EditorJs/EditorJsUploadService.php` begrenzen die Seiten-/Beitrags-Coverbild-Auswahl gezielt auf Dateien mit dem Prefix `ArtikelRahmen_`**: Der normale Editor-Medienpicker bleibt unverändert, Coverbild-Uploads erhalten automatisch `ArtikelRahmen_<slug>.<endung>`, und der optionale API-Parameter `filename_prefix` ist serverseitig nach Upload-Best-Practice auf erlaubte Zeichen und Länge begrenzt. |
| **2.9.248** | 🔴 fix | Public/Site Tables HTML-Ausgabe | **`CMS/admin/modules/tables/TablesModule.php`, `CMS/core/Services/PurifierService.php` und `CMS/core/Services/SiteTable/SiteTableTableRenderer.php` lassen Tabellenzellen jetzt sichere Inline-Formatierung statt rohem Textverlust zu**: `<b>/<strong>`, `<i>/<em>`, `<u>` und `<a>` werden beim Speichern bzw. Public-Rendering erkannt, auf eine enge Whitelist reduziert und anschließend im Frontend korrekt als Fett, Kursiv, Unterstrichen oder Link ausgegeben; der Sanitizer-Fallback entfernt gefährliche Attribute/URL-Schemata und ergänzt `target="_blank"`-Links mit `rel="noopener noreferrer"`. |
| **2.9.248** | 🟢 feat | Admin/Site Tables CMS-Quellen | **`CMS/core/Services/SiteTable/SiteTableContentSource.php`, `CMS/admin/views/tables/edit.php`, `CMS/assets/js/admin-site-tables.js` und `CMS/core/Services/SiteTable/SiteTableTableRenderer.php` reduzieren die Option „Als Site Table aus Seiten/Beiträgen verwenden“ auf den vorgesehenen Vertragsumfang**: Ist sie aktiv, entstehen Zeilen entweder aus konkret ausgewählten veröffentlichten Seiten/Beiträgen — sortiert als Seiten A–Z, danach Beiträge A–Z — oder alternativ aus einem Kategorie-Filter; freie Spalten- und Zelleneingaben bleiben deaktiviert, die Auswahlwerte werden serverseitig gegen die tatsächlich angebotenen Optionen geprüft, und Public sowie Exporte bleiben `/en/...`-bewusst. |
| **2.9.248** | 🔴 fix | Core/Security Runtime & Firewall | **`CMS/core/Services/SecurityRuntimeService.php`, `CMS/core/Bootstrap.php`, `CMS/core/Security.php`, `CMS/core/Auth.php` und `CMS/core/Api.php` ziehen Firewall-, Header-, Session- und API-Rate-Limit-Schutz aus der reinen Admin-Konfiguration in den Runtime-Pfad**: aktive Firewall-Regeln blockieren jetzt IPs, CIDR-Bereiche, User-Agents und Länderkennungen serverseitig, temporäre Rate-Limit-Sperren werden in `security_log` protokolliert, API-Requests schreiben echte Zählereignisse, Admin/API-Antworten senden `no-store`/No-Index-Header, Sessions erhalten `SameSite=Lax`, und Device-Cookies bleiben ohne explizite `COOKIE_DOMAIN` host-only. |
| **2.9.248** | 🔴 fix | Core/AntiSpam, Audit & Logs | **`CMS/core/Services/CommentService.php`, `CMS/themes/cms-default/blog-single.php`, `CMS/core/AuditLogger.php`, `CMS/core/Logger.php`, `CMS/core/Services/SystemService.php`, `CMS/admin/modules/security/AntispamModule.php`, `CMS/admin/modules/security/SecurityAuditModule.php` und `CMS/admin/security-audit.php` härten Spam-, Audit- und Log-Pfade nach OWASP-Kriterien**: öffentliche Kommentare werten AntiSpam-Schalter, Honeypot, Mindestzeit, Linklimit, leere User-Agents und Blacklist-Einträge aus, CAPTCHA-Fremddienste werden nicht mehr als Core-Runtime-Feature beworben, Audit-/File-Logs redigieren sensitive Kontextwerte rekursiv und neutralisieren CR/LF-Injection, Security-Audit-Aktionen sind allowlisted, und die Log-Anzeige liest große Dateien tail-limitiert mit Kanalnamen inklusive Punkt/Bindestrich. |
| **2.9.248** | 🔴 fix | Core/Security Zweitprüfung & Fremdassets | **`CMS/themes/cms-default/functions.php`, `CMS/themes/cms-default/includes/theme-class.php`, `CMS/core/Security.php`, `CMS/.htaccess`, `CMS/core/Services/EditorJs/EditorJsAssetService.php`, `CMS/assets/js/editor-init.js`, `CMS/core/Services/EditorJsRenderer.php`, `CMS/admin/modules/security/SecurityAuditModule.php` sowie die Theme-Font-Konfigurationen ziehen den Asset-Vertrag auf einen kontrollierten Opt-in-Status**: Google-Fonts bleiben im Public-Theme wieder als bewusster Fallback einbindbar, solange keine lokalen Fonts aktiv sind; der Font Manager kann diese Fonts weiterhin lokal spiegeln und unterdrückt dann den Remote-Fallback, CSP/Fallback-Header erlauben dafür nur die genehmigten Google-Font-Hosts, das Security-Audit wertet genehmigte Font-Fallbacks nicht als ungeplantes Fremdasset, Editor.js lädt die externen Embed-/Columns-Bundles weiterhin nicht mehr, bestehende Embed-Blöcke rendern nur noch als sicherer Link statt als Fremd-Iframe, und README/Changelog verzichten auf externe Badge-/Screenshot-Assets. |
| **2.9.248** | 🔴 fix | Frontend/Fonts Runtime | **`CMS/themes/cms-default/functions.php`, `CMS/themes/cms-default/includes/theme-class.php` und `CMS/admin/modules/themes/FontManagerModule.php` schließen die letzte Lücke zwischen Font Manager und Default-Theme**: Sind lokale Fonts aktiv, übernimmt das Public-Theme nun auch wirklich die im Font Manager gespeicherten Font-Familien, Font-Stacks, Basisgrößen und Zeilenhöhen statt weiterhin nur auf die Customizer-Typografie zu hören; zusätzlich richtet sich die Erfolgs-/Fehlerkommunikation des Font Managers jetzt nach dem tatsächlichen Toggle-Wert statt bloß nach der Feld-Existenz. |
| **2.9.248** | 🔴 fix | Admin/Security Audit & AntiSpam Vertrag | **`CMS/admin/modules/security/SecurityAuditModule.php`, `CMS/admin/modules/security/AntispamModule.php` und `CMS/admin/views/security/antispam.php` ziehen die Security-Oberflächen auf den tatsächlich unterstützten lokalen Schutzumfang**: Das Audit prüft jetzt Firewall-Runtime, AntiSpam-Runtime und Runtime-Fremdassets, AntiSpam-Blacklists vermeiden Duplikate per Laufzeitprüfung/Unique-Key, und reCAPTCHA wird nicht länger als aktives Feature beworben, weil externe CAPTCHA-Dienste bewusst nicht in der Public-Runtime geladen werden. |

### v2.9.244 - .247 — 24. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.247** | 🔴 fix | Core/Public Routing & Archive Search | **`CMS/core/Routing/ThemeRouter.php` initialisiert in `renderContact()` die aufgelöste Request-Locale jetzt vor dem Seiten-Lookup und zieht Category-/Tag-Archive bei der Query-Suche auf denselben locale-aware Suchvertrag wie `/search`**: `/contact` bzw. `/kontakt` laufen damit nicht mehr in einen `$locale`-Warning samt falschem Seiten-Lookup, und EN-Archive filtern Titel, Auszug und Inhalt jetzt konsistent über `*_en` mit sauberem Fallback auf die Basissprache. |
| **2.9.247** | 🔴 fix | Admin/Beiträge Listenvertrag | **`CMS/admin/modules/posts/PostsModule.php` spiegelt die Admin-Postsuche jetzt wieder an den JSON-Vertrag aus `CMS/core/Routing/ApiRouter.php` an**: Fallback-/Serverlisten durchsuchen englische Titel und Slugs damit genauso wie `/api/v1/admin/posts`, statt EN-Beiträge nur im API/Grid-Pfad sichtbar zu machen. |
| **2.9.246** | 🔴 fix | Core/Public, Theme & Admin Auth Redirects | **`CMS/core/Routing/PublicRouter.php`, `CMS/core/Routing/ThemeRouter.php` und `CMS/core/Routing/AdminRouter.php` lassen sichere same-origin Redirects jetzt wieder bis zu privaten Public-Zielen durch, statt sie still auf `/member` zu kastrieren**: Private Seiten/Beiträge, locale-aware Login-Redirects und anonyme Admin-Zugriffe springen damit nach erfolgreichem Login wieder auf den ursprünglich angeforderten Public- oder Admin-Pfad zurück, solange das Ziel denselben Host nutzt und nicht auf API-/Auth-Schleifen zeigt. |
| **2.9.245** | 🔴 fix | Core/Routing, Member & Public Auth | **`CMS/core/Routing/PublicRouter.php`, `CMS/core/Routing/MemberRouter.php`, `CMS/core/Routing/ThemeRouter.php` und `CMS/core/Member/PluginDashboardRegistry.php` verwenden für anonyme Redirects jetzt wieder konsequent die lokalisierte öffentliche Login-Strecke über `CmsAuthPageService::getPublicPath()`**: Legacy-Aliasse wie `/login`, private Seiten/Beiträge, MFA-Fallbacks sowie Member-/Plugin-Zugriffe verlieren damit nicht länger ihre EN-/Slug-Kontextinformation und landen nicht mehr hart auf dem unlokalisierten `/cms-login`. |
| **2.9.244** | 🔴 fix | Core/Routing & Theme Runtime | **`CMS/core/Router.php` und `CMS/core/ThemeManager.php` härten öffentliche Fehler- und Renderpfade jetzt weiter für produktive Bot- und 404-Szenarien**: Öffentliche POST-Scanner ohne CSRF-Token laufen damit nicht mehr in laute CSRF-Warnungen, sondern fail-soft in den normalen 404-Pfad, und Theme-Renderings laden Header, Footer und Templates wieder garantiert über das aktive Theme, bevor Hilfsfunktionen wie `phinit_t()` benötigt werden. |
| **2.9.244** | 🔴 fix | Core/Analytics & Dashboard | **`CMS/core/Services/AnalyticsService.php` und `CMS/core/Services/DashboardService.php` behandeln Session-KPIs jetzt schema-aware gegen Altinstallationen ohne `sessions.created_at`**: Besucher-, Dashboard- und Sessionstatistiken brechen damit nicht mehr mit SQL-Fehlern auf langlebigen Bestandsdatenbanken ab, sondern fallen bei fehlender Spalte kontrolliert auf kompatible Kennzahlen zurück. |
| **2.9.244** | 🔴 fix | Core/Mail, Dokumentation & Media | **`CMS/core/Services/MailService.php`, `CMS/admin/modules/system/DocumentationSyncDownloader.php` und `CMS/core/Services/MediaDeliveryService.php` räumen drei produktive Fehlerpfade im Betriebsalltag auf einmal auf**: Der PHP-`mail()`-Fallback hängt nicht mehr implizit an fehlenden Symfony-Mime-/Egulias-Abhängigkeiten, der GitHub-Doku-Download akzeptiert jetzt denselben 50-MB-Rahmen wie der nachgelagerte ZIP-Sync, und leere `/media-file`-Probe-Requests enden als stille 404 statt als unnötiger Warning-Logspam. |

### v2.9.243 — 15. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.243** | 🎨 style | Frontend/Default-Theme Detailseiten | **`CMS/themes/cms-default/blog-single.php`, `CMS/themes/cms-default/page.php` und `CMS/themes/cms-default/style.css` geben Beitrags- und statischen Seiten-Detailansichten jetzt mit klarerem Editorial-Hero, sauberer Meta-Hierarchie, wertigeren Related-Cards und einer deutlich ruhigeren Inhaltsführung aus**: Die Public-Details wirken damit nicht länger „hingeworfen“, sondern konsistent komponiert. |
| **2.9.243** | 🟠 perf | Frontend/Default-Theme Medien & Fonts | **`CMS/themes/cms-default/functions.php`, `CMS/themes/cms-default/header.php`, `CMS/themes/cms-default/home.php` sowie die Blog-Card-Partials ziehen lokale Font-Ausgabe und Featured Images auf denselben moderneren Vertrag wie im Phinit-Theme**: lokale Fonts werden nicht länger unnötig blockierend eingebunden, Karten/Home-Hero nutzen jetzt normalisierte Bildquellen mit optionalem WebP-Source-Fallback und echten `width`/`height`-Attributen, und der Header vermeidet doppelte Head-Asset-Ausgaben. |
| **2.9.243** | 🔴 fix | Frontend/PhinIT-Theme Header | **`365CMS.DE-THEME/cms-phinit/header.php` übernimmt `$_currentLocale` jetzt korrekt in die locale-aware Desktop-Navigations-Closure**: Der bislang im Live-Header sichtbare Warning `Undefined variable $_currentLocale` verschwindet damit, ohne das Active-State-Verhalten lokalisierter Menüpunkte zu verlieren. |

### v2.9.242 — 15. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.242** | 🔴 fix | Frontend/PhinIT-Theme, Seiten & EN-Custom-Slugs | **`CMS/core/Services/ContentLocalizationService.php`, `CMS/core/Router.php` und `CMS/themes/cms-default/includes/theme-runtime-helpers.php` halten lokalisierte Seiten-Slugs jetzt auch im Public-Runtime-Vertrag konsistent**: Seiten und Beiträge übernehmen ihren tatsächlich aufgelösten EN-Slug wieder direkt in den lokalisierten Payload, dynamische Seiten leiten auf ihren kanonischen lokalen Pfad um, der vorbereitete `landing_slug`-Alias kann das Home-/Landing-Template wieder unter einem Custom-Slug rendern, und die Theme-Routenprüfung validiert `/en/...`-Seiten/Hubs nicht länger mit hart verdrahtetem DE-Lookup. Dadurch verliert das PhinIT-/Default-Theme bei EN-Seiten mit eigenem Slug nicht mehr seinen erwarteten Frontend-Stil bzw. Landing-Look. |

### v2.9.241 — 15. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.241** | 🔴 fix | Admin/Hub-Sites & EN-Pfade | **`CMS/admin/views/hub/edit.php` verwendet für EN-Preview, Badges, Hinweise und „EN öffnen“-Links jetzt ebenfalls konsequent das Präfix-Schema `/en/...` statt der alten Suffix-Variante `.../en`**: Die Hub-Site-Verwaltung folgt damit endlich demselben Lokalisierungsvertrag wie Beiträge, Seiten und der zentrale `ContentLocalizationService`, sodass die Admin-Anzeige keine irreführenden EN-Pfade mehr ausgibt. |

### v2.9.240 — 15. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.240** | 🔴 fix | Admin/Beiträge, Seiten & EN-Pfade | **`CMS/admin/views/posts/edit.php`, `CMS/admin/views/pages/edit.php`, `CMS/admin/views/pages/list.php` und `CMS/core/Router.php` ziehen verbliebene EN-Pfade jetzt konsistent auf das Präfix-Schema `/en/...` statt auf die alte Suffix-Variante `.../en`**: EN-Vorschauen, Seitenübersichten und locale-aware Alias-Weiterleitungen bauen englische URLs damit wieder in der erwarteten Form `/en/<slug>` bzw. `/en/blog/<slug>`, statt `en` ans Ende des Slugs zu hängen. |
| **2.9.240** | 🔴 fix | Admin/DE→EN Kopie | **`CMS/admin/views/posts/edit.php` und `CMS/admin/views/pages/edit.php` nehmen Titel und Slug aus dem manuellen Button `DE nach EN kopieren` bewusst heraus**: Beim expliziten Copy-Flow werden damit nur noch Inhalt bzw. Kurzfassung übernommen, während EN-Titel und EN-Slug immer eigenständig gepflegt bleiben und niemals mehr aus der DE-Version überschrieben werden. |

### v2.9.239 — 15. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.239** | 🔴 fix | Admin/Beiträge, Seiten & EN-Übersichten | **`CMS/assets/js/admin-content-editor.js`, `CMS/core/Routing/ApiRouter.php`, `CMS/admin/posts.php`, `CMS/admin/modules/pages/PagesModule.php` und `CMS/admin/views/pages/list.php` härten den getrennten DE/EN-Workflow weiter nach**: `DE nach EN kopieren` überschreibt im Editor.js-Pfad vorhandene EN-Inhalte jetzt wieder zuverlässig, ohne zuerst den alten EN-Zustand erfolgreich serialisieren zu müssen, und Beitrags-/Seitenübersichten erkennen EN-Varianten nicht länger nur über `title_en`, sondern über alle relevanten EN-Felder inklusive Inhalt und Slug. Dadurch erscheinen EN-Badges wieder belastbar auch dann, wenn der englische Inhalt vorhanden ist, aber kein separater EN-Titel gepflegt wurde. |

### v2.9.238 — 15. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.238** | 🔴 fix | Admin/Beiträge & Seiten EN-Kopie | **`CMS/admin/views/posts/edit.php`, `CMS/admin/views/pages/edit.php`, `CMS/assets/js/admin-content-editor.js` und `CMS/core/Services/EditorService.php` bringen die fehlende Funktion `DE nach EN kopieren` in den neuen getrennten Sprachansichten wieder konsistent zurück**: Der Copy-Button wird auf der EN-Bearbeitungsseite jetzt nicht mehr nur bei `Editor.js`, sondern auch im Legacy-HTML-/SunEditor-Modus angezeigt, und die gemeinsame Admin-Bridge kann deutsche Titel-, Slug-, Kurzfassungs- und Inhaltswerte damit in beiden Editorwelten sauber in die EN-Bearbeitung übernehmen, ohne dass der sichtbare Legacy-Editor auf alten Inhalten stehen bleibt. |

### v2.9.237 — 15. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.237** | 🔴 fix | Admin/Beiträge & Seiten Sprachbutton | **`CMS/admin/views/posts/edit.php` und `CMS/admin/views/pages/edit.php` verwenden für `Deutsch`/`English` jetzt echte locale-aware Navigationslinks statt Submit-Buttons innerhalb des Bearbeitungsformulars**: Der Klick auf `ENG` löst damit garantiert keinen Save-POST mehr aus, sondern öffnet direkt die separate EN-Bearbeitungsseite für Beiträge bzw. Seiten, ohne unterwegs in `Beitrag aktualisiert.` oder `Seite gespeichert.` abzubiegen. |

### v2.9.236 — 15. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.236** | 🔴 fix | Admin/Beiträge & Seiten EN-Edit-Redirects | **`CMS/admin/posts.php` und `CMS/admin/pages.php` halten locale-aware Redirects aus den neuen DE/EN-Edit-Screens jetzt auch für noch ungespeicherte Entwürfe sauber auf der Bearbeitungsansicht**: Muss ein neuer Beitrag oder eine neue Seite ohne bestehende ID aus der Edit-Ansicht erneut gerendert oder umgeleitet werden, landet der Flow damit nicht versehentlich in der Listenansicht, sondern bleibt inklusive `lang=en` auf der passenden separaten DE/EN-Bearbeitungsseite. |

### v2.9.235 — 15. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.235** | 🔴 fix | Admin/Beiträge & Seiten EN-Wechsel | **`CMS/assets/js/admin-content-editor.js` erhält beim asynchronen Editor.js-Pre-Save jetzt den ursprünglich geklickten Submit-Button inklusive `name`, `value` und `formaction` sauber über den finalen Formular-Submit hinweg**: Der Klick auf `English` in Seiten- und Beitragseditoren kippt dadurch nicht länger still in ein normales `save` zurück, das sofort wieder die deutsche Ansicht mit `Erfolgreich aktualisiert` lädt, sondern bleibt beim echten Sprachwechsel auf der separaten EN-Bearbeitungsseite. Zusätzlich toleriert die UI-Initialisierung fehlende In-Page-Sprachtoggle-Selektoren der neuen getrennten DE/EN-Ansichten jetzt robust, statt an leeren Selektoren zu stolpern. |

### v2.9.234 — 15. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.234** | 🔴 fix | Admin/Beiträge & Seiten EN-Bearbeitung | **`CMS/admin/posts.php`, `CMS/admin/pages.php`, `CMS/admin/views/posts/edit.php` und `CMS/admin/views/pages/edit.php` ziehen die Sprachbearbeitung für Beiträge und Seiten von einem gemeinsamen DE/EN-In-Page-Tab auf getrennte Admin-Ansichten um**: Der Klick auf `English` rendert nun eine eigene EN-Bearbeitungsseite mit den passenden englischen Feldern für Titel, Slug und Inhalt, hält die jeweils andere Sprachfassung im Formular versteckt erhalten und bringt die DE→EN-Kopier- bzw. AI-Übersetzungsfunktion direkt in diese EN-Ansicht zurück. Dadurch hängt der Save-Flow nicht länger an zwei konkurrierenden Editorinstanzen auf derselben Seite, was das bisherige „EN wirkt gespeichert, ist nach Reload aber weg“-Verhalten für Beiträge und Seiten zuverlässig entschärft. |

### v2.9.233 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.233** | 🔵 docs | Admin/EN-Save Debug-Instrumentierung | **`CMS/admin/modules/posts/PostsModule.php` und `CMS/admin/modules/pages/PagesModule.php` protokollieren `content_en` für die laufende Fehlersuche jetzt als Row-Level-Snapshots vor Filter, nach Filter und direkt nach dem DB-Write**: Für Beiträge und Seiten landen damit Länge, SHA-1, Kurzvorschau und erkannte Blockanzahl des EN-Inhalts sowie der zugehörigen EN-Titel-/Slug-Felder im jeweiligen Admin-Logchannel, sodass sich sauber belegen lässt, ob der Wert schon vor dem SQL-Write leer wird oder erst danach verloren geht. |

### v2.9.232 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.232** | 🔴 fix | Admin/EditorJS EN-Lazy-Save | **`CMS/assets/js/admin-content-editor.js` wartet beim Formular-Save jetzt zusätzlich auf laufende Lazy-Bindings und die echte `Editor.js`-Bereitschaft der Zielinstanz, bevor DE/EN-Inhalte serialisiert werden**: Wird der EN-Tab erst kurz vor dem Speichern geöffnet oder ist seine Instanz nach Paste/Init noch im Aufbau, landet damit nicht länger ein leerer Frühzustand in `content_en`, der nach dem Reload wie „weg gespeichert“ wirkt. |

### v2.9.231 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.231** | 🔴 fix | Admin/EditorJS EN-Save nach Paste | **`CMS/assets/js/admin-content-editor.js` wartet vor dem Formular-Save jetzt kurz auf laufende Paste-/Drop-/Input-Mutationen des jeweiligen Editors, bevor `Editor.js.save()` den Zustand serialisiert**: Gerade im englischen Editor wird damit nach frisch eingefügten Inhalten nicht mehr der noch leere Zwischenstand gespeichert, nur weil der Speichern-Klick schneller war als die interne Einfüge-Verarbeitung des Editors. |

### v2.9.230 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.230** | 🔴 fix | Admin/EditorJS EN-Workflow | **`CMS/admin/views/posts/edit.php` und `CMS/admin/views/pages/edit.php` entfernen den fehleranfälligen Button `Alles aus DE nach EN kopieren` samt zugehöriger Copy-Konfiguration aus der Editor.js-Bridge**: Beitrags- und Seiteneditoren zeigen damit im EN-Tab keine weiterhin defekte Direktkopie mehr an, während die direkte EN-Bearbeitung und die optionale AI-Übersetzung erhalten bleiben. |

### v2.9.229 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.229** | 🔴 fix | Admin/EditorJS Sprachkopie Runtime | **`CMS/assets/js/admin-content-editor.js` schreibt explizite DE→EN- und AI-Overwrite-Zustände jetzt nicht nur in Hidden-Inputs und In-Place-Renderpfade, sondern bindet den EN-Editor bei Bedarf frisch aus dem neuen JSON-Zielzustand neu**: Seiten- und Beitragseditoren verlieren damit einen weiteren Stale-State-Pfad, bei dem die zugrunde liegenden EN-Daten bereits überschrieben waren, die sichtbare Editor.js-Instanz aber nach Lazy-Init- oder Render-Sonderfällen noch alte Blöcke zeigte. |

### v2.9.228 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.228** | 🔴 fix | Admin/EditorJS Sprachkopie | **`CMS/assets/js/admin-content-editor.js` wartet bei manuellen DE→EN-Kopien und AI-Übernahmen jetzt explizit auf ausstehende Lazy-Initialisierungen des EN-Editors und unterdrückt dabei konkurrierende Erstkopien**: Seiten- und Beitragseditoren überschreiben Titel-, Slug-, Kurzfassungs- und Blockinhalte damit wieder deterministisch auch dann, wenn der EN-Tab gerade erst geöffnet wurde oder die englische Editor.js-Instanz noch mitten im Lazy-Bind steckt. |

### v2.9.227 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.227** | 🔴 fix | Admin/Medienverwaltung & Content-Medienpfade | **`CMS/core/Services/MediaUsageService.php` und `CMS/core/Services/ContentMediaPlacementService.php` erkennen Editor-Medienreferenzen jetzt auch dann korrekt, wenn die Installation unter einem Unterpfad wie `/CMS` läuft**: Neben nackten `/media-file?...`- und `/uploads/...`-Pfade werden nun auch Varianten wie `/CMS/media-file?...` bzw. `/CMS/uploads/...` aus JSON-, HTML- und CSS-Strings sauber aufgelöst, sodass eingebundene Editor-Bilder in der Medienverwaltung nicht länger fälschlich als „nicht eingebunden“ erscheinen und Temp-/Final-Pfad-Umschreibungen denselben Subdirectory-Vertrag mitziehen. |

### v2.9.226 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.226** | 🔴 fix | Admin/Medienverwaltung Verwendungslogik | **`CMS/core/Services/MediaUsageService.php` erkennt eingebundene Editor-Bilder jetzt nicht mehr nur über den exakt gespeicherten Einzelpfad, sondern propagiert Verwendungen auch auf die automatisch erzeugten Dateivarianten desselben Uploads**: Originaldateien, zugehörige `.webp`-Ableitungen sowie bekannte Thumbnail-Suffixe wie `-small`, `-medium`, `-large` und `-banner` landen damit in der Medienverwaltung nicht mehr fälschlich auf „nicht eingebunden“, nur weil der Editor im Inhalt z. B. die JPG-Datei speichert, während die Bibliothek zusätzlich die generierte WebP- oder Größenvariante anzeigt. |

### v2.9.225 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.225** | 🟢 feat | Admin/Medienverwaltung Filter | **`CMS/admin/modules/media/MediaModule.php` und `CMS/admin/views/media/library.php` erweitern die Medienbibliothek um einen Verwendungsfilter für eingebundene vs. ungenutzte Dateien**: Bereits referenzierte Medien lassen sich damit in der Mediathek auf Wunsch direkt ausblenden oder gezielt isolieren, ohne dass die vorhandenen Kategorie-, Such- und Ansichtsfilter ihren Zustand verlieren. |
| **2.9.225** | 🔴 fix | Admin/EditorJS Galerie-Uploads | **`CMS/assets/js/editor-init.js` und `CMS/core/Services/EditorJs/EditorJsSanitizer.php` ziehen `imageGallery` endlich auf echte Upload-/Import-Logik samt internem Medien-URL-Vertrag um**: Galerieblöcke können jetzt Bilder direkt hochladen, aus der Mediathek übernehmen oder externe URLs sauber importieren, speichern die Ergebnisse als normale interne Medienpfade statt als loses URL-Textarea-Konstrukt und akzeptieren beim Sanitizing nun auch root-relative `/media-file?...`- bzw. `/uploads/...`-Assets, wie sie der restliche Editor.js-Medienpfad bereits produktiv verwendet. |

### v2.9.224 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.224** | 🔴 fix | Admin/EditorJS Medienordner | **`CMS/assets/js/editor-init.js` und `CMS/assets/editorjs/cropper-tune.umd.js` ziehen jetzt auch die restlichen bildbezogenen Editor.js-Workflows auf denselben Beitrags-/Seitenordnervertrag wie normale Inhaltsbilder und Cover**: Carousel-Uploads verwenden nun denselben kontextgebundenen Datei-/URL-Uploader wie das Standard-Image-Tool, das Drawing-Tool lädt neu eingefügte Canvas-Bilder sowie Save-Rewrites nicht mehr als lose Data-URLs oder generische Dateien weg, sondern über den passenden Post-/Page-Kontext hoch, und der Cropper-Tune promoted zugeschnittene Bilder beim Anwenden direkt als echte Upload-Datei in den richtigen `articles/<slug>`- bzw. `pages/<slug>`-Ordner statt nur ein eingebettetes `data:image/...` im Block zu hinterlassen. |

### v2.9.223 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.223** | 🔴 fix | Frontend/EditorJS Medienkonsistenz | **`CMS/core/Services/EditorJsRenderer.php` normalisiert Public-Medien-URLs für alle relevanten Editor.js-Medienblöcke jetzt zentral und einheitlich**: Neben normalen Bild- und Attachment-Blöcken folgen nun auch Cropper-Ausgaben, Galerie, MediaText, Carousel, Drawing und Link-Preview-Bilder demselben hostneutralen Frontend-Vertrag über `MediaDeliveryService::normalizeUrl(...)`, sodass interne `/media-file?...`- und `/uploads/...`-Pfade im öffentlichen Output konsistent gerendert werden statt blockabhängig mal roh, mal gar nicht oder mal nur als absolute URL zu funktionieren. |

### v2.9.222 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.222** | 🔴 fix | Frontend/EditorJS Medien-Rendering | **`CMS/core/Services/EditorJsRenderer.php` akzeptiert für Bild-, Galerie-, MediaText-, Carousel-, Drawing- und Attachment-Blöcke jetzt auch hostneutrale interne Medienpfade wie `/media-file?...` und `/uploads/...` statt fälschlich nur absolute URLs zu rendern**: Neu hinzugefügte Bilder und Dateien bleiben damit nach dem Speichern nicht mehr auf die Admin-Vorschau beschränkt, sondern erscheinen auch im öffentlichen Frontend zuverlässig, obwohl die Upload-API interne Medien bewusst als relative Root-Pfade zurückliefert. |

### v2.9.221 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.221** | 🔴 fix | Admin/Beiträge, Seiten & Medienordner | **`CMS/core/Services/ContentMediaPlacementService.php`, `CMS/admin/modules/posts/PostsModule.php`, `CMS/admin/modules/pages/PagesModule.php` und `CMS/core/Services/EditorJs/EditorJsUploadService.php` finalisieren neue Beitrags-/Seitenmedien jetzt konsequent in den echten Slug-Ordnern `articles/<slug>` bzw. `pages/<slug>`**: Frisch hochgeladene Editor.js-Bilder aus neuen Entwürfen werden beim ersten Save aus `.../temp/<draftKey>/...` in den finalen Slug-Pfad verschoben und ihre Referenzen in DE-/EN-Inhalten sauber umgeschrieben; zusätzlich verwendet auch der Featured-Image-Flow wieder denselben hyphenbasierten Slug-Vertrag statt abweichender Unterstrich-Ordner. |

### v2.9.220 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.220** | 🔴 fix | Admin/Medienverwaltung Vorschau | **`CMS/core/Services/MediaDeliveryService.php` liefert Preview-URLs der Medienverwaltung für inline-fähige Bildformate jetzt gezielt über `/media-file?...&disposition=inline` statt über direkte statische `/uploads/...`-Pfade**: Die Übersicht der Medienbibliothek rendert damit auch JPG-, PNG-, GIF-, BMP- und ICO-Dateien wieder zuverlässig als Vorschau, selbst wenn die statische Webserver-MIME-Zuordnung für Originaldateien problematisch ist und bisher nur WebP sauber angezeigt wurde. |

### v2.9.219 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.219** | 🟢 feat | Admin/Medienverwaltung Referenzspalte | **`CMS/core/Services/MediaUsageService.php`, `CMS/admin/modules/media/MediaModule.php` und `CMS/admin/views/media/library.php` erweitern die Medienbibliothek um echte Nutzungsreferenzen aus Beiträgen und Seiten**: Die Listenansicht zeigt pro Datei jetzt in einer zusätzlichen Spalte, in welchen Beiträgen bzw. Seiten ein Medium als Inhaltsbild oder Featured-/Seitenbild verwendet wird, inklusive Direktlinks in die jeweilige Bearbeitung; die Grid-Ansicht blendet ergänzend eine kompakte Verwendungszusammenfassung ein. |

### v2.9.218 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.218** | 🔴 fix | Admin/EditorJS Medienauslieferung | **`CMS/core/Services/EditorJs/EditorJsUploadService.php` und `CMS/core/Services/EditorJs/EditorJsImageLibraryService.php` liefern Editor.js-Bilder jetzt bewusst über den Inline-Delivery-Endpoint `/media-file?...` statt über direkte statische `/uploads/...`-Pfade aus, und `CMS/core/Services/MediaDeliveryService.php` erkennt den tatsächlichen Bild-MIME-Typ dafür bevorzugt aus den Dateiinhalten**: Damit hängt die Editor-/Mediathek-Vorschau nicht mehr an der statischen Webserver-MIME-Zuordnung oder einer ggf. irreführenden Dateiendung des Uploads, sondern an der echten Bildsignatur der Datei — genau passend zum Fehlerbild „Original kaputt, WebP sichtbar“. |

### v2.9.217 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.217** | 🔴 fix | Media/Clipboard Bildtyp-Erkennung | **`CMS/core/Services/EditorJs/EditorJsUploadService.php` und `CMS/core/Services/MediaService.php` erkennen den echten Bildtyp temporärer Clipboard-Uploads jetzt bevorzugt über `exif_imagetype()`/`getimagesize()` statt sich primär auf `finfo` oder den vom Browser gemeldeten MIME-Typ zu verlassen**: Fälle, in denen ein eingefügtes Bild intern bereits WebP-Daten trägt, aber fälschlich als PNG/JPG durchrutscht, werden damit korrekt als echter Bildtyp behandelt — genau der Fehlerpfad, der kaputte „Original“-Dateien bei gleichzeitig funktionierendem WebP-Derivat erklärt. |

### v2.9.216 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.216** | 🔴 fix | Media/Clipboard Originaldatei | **`CMS/core/Services/MediaService.php` schreibt gespeicherte JPEG-/PNG-Originale nach dem Upload jetzt einmal sauber neu über GD zurück**: Clipboard-Bilder, deren Roh-Original zwar noch zu WebP konvertierbar ist, vom Browser aber als kaputtes PNG/JPG interpretiert wird, landen damit nicht mehr als beschädigte Originaldatei im Upload, sondern als neu kodiertes, browsergültiges Original plus weiterhin vorhandenem WebP-Derivat. |

### v2.9.215 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.215** | 🔴 fix | Admin/EditorJS Clipboard Originaldatei | **`CMS/assets/js/editor-init.js` bevorzugt beim Bild-Einfügen aus der Zwischenablage jetzt echte Clipboard-`File`-Objekte gegenüber anonymen `blob:`-Zwischenzuständen des Browsers**: Wenn der Browser beim Paste den ursprünglichen Dateinamen und die Original-Endung noch in `clipboardData.files/items` mitliefert, verwendet Editor.js genau diese Datei für den Upload statt einer nachträglich aus `blob:` geholten namenlosen Kopie; damit bleiben echte JPG-/PNG-/GIF-Dateien aus Datei-/Explorer- oder App-Clipboard-Flows deutlich zuverlässiger als Originaldatei erhalten, während der bestehende Blob-Fallback für reine Raster-/Screenshot-Pastes weiterhin aktiv bleibt. |

### v2.9.214 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.214** | 🔴 fix | Admin/EditorJS Clipboard Formaterkennung | **`CMS/core/Services/EditorJs/EditorJsUploadService.php` normalisiert Clipboard-Bilduploads jetzt anhand des real erkannten MIME-Typs der temporären Datei statt sich nur auf den vom Browser gelieferten Dateinamen zu verlassen**: JPG-, PNG-, GIF-, BMP-, AVIF- und WebP-Bilder aus der Zwischenablage behalten damit beim Editor.js-Upload wieder zuverlässig die zu ihren echten Bilddaten passende Original-Endung, und der Featured-Image-Pfadvertrag bleibt trotz der zuletzt hostneutral relativierten Media-URLs weiterhin korrekt auflösbar. |

### v2.9.213 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.213** | 🔴 fix | Admin/EditorJS Media Response Contract | **`CMS/core/Services/EditorJs/EditorJsUploadService.php` und `CMS/core/Services/EditorJs/EditorJsImageLibraryService.php` liefern Editor.js-Bild- und Bibliotheks-URLs für interne Medien jetzt hostneutral als relative `/uploads/...`- bzw. `/media-file?...`-Pfade aus**: Neu hochgeladene Clipboard-/Direktuploads und Bibliothekseinträge hängen damit nicht länger an einer eventuell vom aktuellen Admin-Host abweichenden `SITE_URL`/`UPLOAD_URL`-Origin, was den hängenden Bild-Spinner im Editor bei erfolgreichem Upload zusätzlich serverseitig absichert. |

### v2.9.212 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.212** | 🔴 fix | Admin/EditorJS Media URL Runtime | **`CMS/assets/js/editor-init.js` normalisiert vom Editor.js-Media-Endpunkt zurückgelieferte interne Bild-URLs jetzt hostneutral auf die aktuelle Browser-Origin und ergänzt ein hartes Timeout-Fallback für den Upload-State**: Clipboard- und Direktuploads geraten damit auch dann nicht mehr in einem dauerhaften Spinner-Zustand, wenn `SITE_URL`/`UPLOAD_URL` von der aktuell genutzten Admin-Origin abweichen oder ein interner Bild-Load-Request seinen finalen Load/Error-Event nicht sauber liefert. |

### v2.9.211 — 13. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.211** | 🔴 fix | Admin/EditorJS Clipboard Upload | **`CMS/assets/js/editor-init.js` härtet den Bild-Paste-Flow im Editor.js-Image-Tool jetzt gegen Clipboard-/Blob-Sonderfälle und verlorene Load-Events ab**: Bilder aus der Zwischenablage bekommen bei Bedarf einen stabilen Dateinamen mit passender Endung, und der Image-Block finalisiert seinen Render-/Preloader-Status nach erfolgreichem Upload robuster inklusive Retry/Fallback, sodass eingefügte Clipboard-Bilder im Editor nicht mehr dauerhaft im Ladekreis hängen bleiben, obwohl der Upload bereits erfolgreich war. |

### v2.9.210 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.210** | 🟡 refactor | AI Services / Settings | **`CMS/core/Services/AI/AiSettingsService.php`, `CMS/admin/modules/system/AiServicesModule.php`, `CMS/admin/ai-page.php` und `CMS/admin/views/system/ai-services.php` ziehen die Provider-Verwaltung von einer starren Slug-Matrix auf eine bewusst anlegbare Provider-Liste um**: AI Services zeigt damit nur noch die tatsächlich genutzten Provider-Einträge, erlaubt neue Einträge gezielt per `+` und verwaltet Standard/Fallback, Secrets und Scope-Flags pro Instanz statt alle potenziellen Provider dauerhaft im Formular auszubreiten. |
| **2.9.210** | 🔴 fix | AI Runtime / Live Provider | **`CMS/core/Services/AI/AiProviderGateway.php`, `CMS/core/Services/AI/Providers/AbstractPromptingAiProvider.php`, `CMS/core/Services/AI/Providers/OllamaAiProvider.php`, `CMS/core/Services/AI/Providers/AzureOpenAiProvider.php`, `CMS/core/Services/AI/Providers/MockAiProvider.php`, `CMS/admin/modules/system/AiEditorJsTranslationModule.php` und `CMS/assets/js/admin-content-editor.js` machen die ersten echten Live-Provider in der bestehenden Editor.js-Übersetzung produktiv**: Übersetzungsrequests können jetzt über konfiguriertes Ollama oder Azure AI laufen, inklusive Secret-/Deployment-/API-Version-Auflösung, fail-softem Fallback auf andere freigegebene Provider und providerneutraler UI-/Audit-Meldungen statt reiner Mock-Kommunikation. |
| **2.9.210** | 🔴 fix | Admin/EditorJS & Medienpfade | **`CMS/assets/js/editor-init.js`, `CMS/core/Services/EditorJs/EditorJsUploadService.php`, `CMS/core/Services/EditorJs/EditorJsMediaService.php`, `CMS/core/Services/EditorJs/EditorJsRemoteMediaService.php`, `CMS/admin/views/posts/edit.php` und `CMS/admin/views/pages/edit.php` hängen Editor.js-Bilduploads und URL-Importe jetzt konsistent an den aktuellen Beitrags-/Seitenkontext**: Datei-Uploads, Zwischenablage-Bilder und Remote-Bildimporte landen damit für Posts/Pages nicht mehr halb im Shared-Pfad `editorjs`, sondern folgen auch bei JSON-basierten `fetch_image`-Requests den aufgelösten Zielordnern wie `articles/<slug>` bzw. `pages/<slug>` inklusive Draft-Fallback. |
| **2.9.210** | 🔴 fix | Admin/Beiträge, Seiten & Featured Media | **`CMS/assets/js/admin-content-editor.js` sowie `CMS/admin/views/partials/featured-image-picker.php` räumen den Featured-Image-Tempvertrag für Post-/Page-Editoren jetzt sauber nach**: Entfernen oder das Umschalten von einer frisch hochgeladenen Temp-Datei auf ein bestehendes Bibliotheksbild lässt keinen veralteten `featured_image_temp_path` mehr zurück, sodass spätere Saves nicht versehentlich wieder das alte Temp-Bild in den Zielordner promoten und die aktuelle Auswahl überschreiben. |
| **2.9.210** | 🔴 fix | Admin/Beiträge, Seiten & EN-Editor | **`CMS/assets/js/admin-content-editor.js`, `CMS/admin/posts.php` und `CMS/admin/pages.php` härten DE/EN-Editorwechsel und fehlgeschlagene Save-Roundtrips weiter ab**: Die gemeinsame Editor-Bridge serialisiert alle konfigurierten DE-/EN-Instanzen zuverlässig vor dem Submit, der Sprachwechsel bleibt nach dem Syntax-Hotfix funktionsfähig, und ein fehlgeschlagener Inline-Re-Render zieht bei Beiträgen und Seiten jetzt die gerade abgeschickten EN-Inhalte samt SEO-/Meta-Feldern aus `$_POST` statt wieder den älteren DB-Stand einzublenden. |

### v2.9.209 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.209** | 🔴 fix | Core/Module & Admin-Navigation | **`CMS/core/Services/CoreModuleService.php`, `CMS/admin/partials/sidebar.php`, `CMS/admin/seo-page.php`, `CMS/admin/performance-page.php`, `CMS/admin/legal-sites.php`, `CMS/admin/cookie-manager.php`, `CMS/admin/data-requests.php`, `CMS/admin/antispam.php`, `CMS/admin/firewall.php`, `CMS/admin/security-audit.php`, `CMS/admin/redirect-manager.php` und `CMS/admin/not-found-monitor.php` heben `SEO`, `Sicherheit`, `Recht` und `Performance` auf echte Root-Core-Module mit fail-closed Admin-Gates und modulgefilterten Sidebar-Bereichen**: Die vier Fachbereiche sind damit standardmäßig aktiv, unter `System → Module` vollständig deaktivierbar und verschwinden bei deaktiviertem Root-Modul konsistent aus Navigation und Entry-Runtime statt nur dekorativ im Menü zu stehen. |
| **2.9.209** | 🔴 fix | Assets/Diagnose & Frontend | **`CMS/admin/pages.php`, `CMS/admin/posts.php`, `CMS/core/Services/CookieConsentService.php`, `CMS/core/Services/CoreWebVitalsService.php`, `CMS/core/Routing/PublicRouter.php`, `CMS/core/VendorRegistry.php` und `CMS/admin/views/system/assets.php` binden modulgebundene SEO-/Legal-Assets jetzt direkt an den Core-Modulvertrag und erweitern `Diagnose → Assets` um Quellenlinks sowie eine eigene Tabelle für modulabhängige Assets**: SEO-Editor, Cookie-Consent, Cookie-Präferenzroute und Web-Vitals werden damit bei deaktivierten Root-Modulen nicht mehr geladen, während Diagnose/Assets sichtbar macht, welche Bundles aktiv, modulgebunden oder bewusst abgeschaltet sind – inklusive GitHub-/Projektlinks pro Paket. |
| **2.9.209** | 🟡 refactor | Admin/AI Services | **`CMS/admin/ai-page.php`, `CMS/admin/ai-services.php`, `CMS/admin/ai-translation.php`, `CMS/admin/ai-content-creator.php`, `CMS/admin/ai-seo-creator.php`, `CMS/admin/ai-settings.php` und `CMS/admin/views/system/ai-services.php` ziehen AI Services aus `System` in einen eigenen Admin-Hauptbereich mit Dashboard-, Übersetzungs-, Content-Creator-, SEO-Creator- und Einstellungssektionen um**: AI Services erscheint damit als eigenständige Navigationsdomäne mit Legacy-Tab-Redirects für bestehende Links, statt weiter als einzelner Unterpunkt im Systembereich zu hängen. |

### v2.9.208 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.208** | 🔴 fix | Assets/AI Platform & Module UI | **`CMS/assets/autoload.php`, `CMS/assets/ai-platform/`, `CMS/core/VendorRegistry.php`, `CMS/admin/views/system/modules.php` und `CMS/core/Services/CoreModuleService.php` ziehen die Symfony AI Platform jetzt vom Staging-Bestand in die produktive Asset-Runtime**: Das Paket liegt damit unter `CMS/assets/ai-platform`, wird über den zentralen Assets-Autoloader aufgelöst und erscheint unter `Diagnose -> Assets` als registriertes Produktivpaket samt Plattformprüfung; parallel erklärt die Modulverwaltung jetzt explizit, dass nur echte Root-Core-Module schaltbar sind, und der `Member Dashboard`-Schalter zeigt seine betroffenen Admin-Bereiche sichtbar im Modul-Card-Detail an. |

### v2.9.207 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.207** | 🔴 fix | System/Installer/Member | **`CMS/core/Services/CoreModuleService.php`, `CMS/install/InstallerService.php`, `CMS/install/InstallerController.php`, `CMS/install/views/site.php`, `CMS/admin/member-dashboard*.php`, `CMS/admin/partials/sidebar.php`, `CMS/member/includes/class-member-controller.php` und `CMS/member/dashboard.php` ziehen den Modulvertrag jetzt auf echte Root-Core-Module zusammen**: Unter `System → Module` sind damit nur noch die eigentlichen Core-Module statt interner Untermodule schaltbar, der Installer übernimmt die Auswahl direkt in neue Installationen, und das Member Dashboard hängt in Admin, Sidebar, Menü sowie Frontend-Startseite konsistent am selben Root-Schalter inklusive Legacy-Setting-Sync. |

### v2.9.206 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.206** | 🔴 fix | System/AI Services | **`CMS/core/Services/CoreModuleService.php`, `CMS/admin/ai-services.php`, `CMS/admin/ai-translate-editorjs.php`, `CMS/admin/partials/sidebar.php`, `CMS/admin/pages.php`, `CMS/admin/posts.php` sowie die Seiten-/Beitrags-Edit-Views hängen AI Services jetzt an den zentralen Core-Modulvertrag**: Unter `System → Module` lässt sich `AI Services` damit wie gewünscht aktivieren/deaktivieren, der Sidebar-Eintrag verschwindet bei deaktiviertem Modul, `/admin/ai-services` wird fail-closed gesperrt und auch die Editor.js-AI-Übersetzung in Seiten- und Beitragseditoren blendet ihren Button aus bzw. lehnt Requests über den separaten Endpoint sauber ab. |

### v2.9.205 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.205** | 🔴 fix | Menüs/Doku-Sync | **`CMS/admin/modules/menus/MenuEditorModule.php` und `CMS/admin/modules/system/DocumentationGithubZipSync.php` beheben zwei produktive Admin-Fehlerpfade**: Interne Hash-/Query-Ziele im Menü-Editor laufen wieder ohne `preg_match(): Unknown modifier '|'`, und der GitHub-Doku-Sync löst den `/DOC`-Baum für den API-/Raw-Fallback jetzt gezielt über das echte `DOC`-Verzeichnis statt über einen potenziell abgeschnittenen Komplett-Repo-Tree auf, sodass große Repositories den ZIP-Fallback nicht mehr wegen leerer DOC-Dateiliste verlieren. |

### v2.9.204 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.204** | 🔴 fix | Admin/Editor | **`CMS/assets/js/admin-content-editor.js` fängt den manuellen `Alles aus DE nach EN kopieren`-Flow in Seiten- und Beitragseditoren jetzt robuster ab**: Der Shared-Flow serialisiert Quelle und Zielzustand vorab, verhindert versehentliche Standard-Klickfolgen, wartet den EN-Tab/Paint sauber ab und überschreibt die EN-Felder sowie den EN-Editorinhalt deterministisch auch dann, wenn die englische Editor.js-Instanz lazy initialisiert wird. |

### v2.9.203 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.203** | 🟡 refactor | Core/Logging | **Große Teile von `CMS/core` leiten Laufzeit-, Routing-, Theme-, Update-, Search-, Backup-, Analytics-, Landing-, Plugin-, LDAP-, Passkey-, Cron- und Customizer-Fehler jetzt über strukturierte `CMS\Logger`-Channels mit Kontextdaten statt über rohe direkte `error_log()`-Strings**: Im Core verbleiben direkte `error_log()`-Aufrufe damit im Wesentlichen nur noch in bewusst primitiven Low-Level-/Fallback-Pfaden wie `Bootstrap`, `Database`, `Logger`, `AuditLogger`, `CacheManager`, `SchemaManager`, `SubscriptionManager` und ähnlichen Frühstart- bzw. Rekursions-Sonderfällen. |

### v2.9.202 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.202** | 🔴 fix | System/Logging | **`CMS/config/app.php`, `CMS/install/InstallerService.php` und `CMS/admin/modules/settings/SettingsModule.php` halten PHP-Fehlerlogging jetzt auch außerhalb des Debug-Modus aktiv und pinnen den `error_log`-Pfad weiterhin auf `CMS/logs/error.log`**: Direkte `error_log()`-Aufrufe, PHP-Warnings und andere Laufzeitfehler verschwinden damit in Produktion nicht mehr ins Nirwana, sondern landen wieder korrekt im zentralen `/logs/`-Pfad, während `display_errors` weiterhin deaktiviert bleibt. |

### v2.9.201 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.201** | 🔴 fix | System/Assets & Diagnose | **`CMS/core/VendorRegistry.php` lädt für die Diagnose den produktiven Assets-Autoloader jetzt vor den Runtime-Symbolprüfungen und blendet nicht verdrahtete Staging-/Referenz-/Legacy-Kandidaten aus der Runtime-Library-Liste aus**: `Symfony Translation` wird dadurch in der Diagnose wieder korrekt als `auflösbar` erkannt, während `Symfony Contracts (lokaler Runtime-Shim)` in der Asset-Info sichtbar bleibt und die Laufzeitliste nur noch produktiv relevante Bundles zeigt. |

### v2.9.200 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.200** | 🔴 fix | System/Assets & Diagnose | **`CMS/assets/autoload.php` lädt jetzt lokale `Symfony\Contracts`-Shims für die produktiv gebündelten Translation-/Mailer-Pfade und `CMS/core/VendorRegistry.php` deckt zusätzlich aktive Runtime-Assets wie `editorjs`, `gridjs`, `PhotoSwipe`, `SunEditor`, `Tabler` sowie dokumentierte Staging-Kandidaten wie `symfony/ai-platform` ab**: Die Diagnose meldet damit `symfony/translation` nicht mehr mit fehlendem `TranslatorInterface`, und die Vendor-/Asset-Registry zeigt deutlich mehr vom tatsächlichen Asset-Bestand statt nur einen schmalen PHP-Subset. |

### v2.9.199 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.199** | 🔴 fix | System/Diagnose & Vendor Registry | **`CMS/core/VendorRegistry.php` behandelt Runtime-Fehler einzelner Bundle-Symbolprüfungen jetzt pro Paket fail-soft und `CMS/admin/views/system/diagnose.php` blendet diese Details direkt in der Vendor-/Asset-Registry ein**: Die Diagnose bleibt damit bei unvollständigen oder fehlerhaften Asset-Abhängigkeiten nicht mehr komplett leer, sondern zeigt Autoloader, Registry-Pakete, Libraries und konkrete Problemstellen weiterhin sichtbar an. |

### v2.9.198 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.198** | 🔴 fix | Core/Redirect Runtime | **`CMS/core/Router.php` nutzt für die interne Redirect-Pfadvalidierung jetzt ein robusteres Pattern-Delimiter-Setup**: Redirect-Prüfungen auf Live-Hosts mit prozentkodierten Pfaden laufen dadurch nicht mehr in `preg_match(): Unknown modifier '%'`. |
| **2.9.198** | 🔴 fix | System/Dokumentation | **`CMS/admin/modules/system/DocumentationGithubZipSync.php` verifiziert GitHub-Doku-Snapshots jetzt dynamisch gegen den offiziellen GitHub-Tree inklusive Blob-Signaturen statt nur gegen einen starren freigegebenen Bundle-Hash**: ZIP- oder API-basierte DOC-Syncs brechen damit nicht mehr bei legitimen Doku-Änderungen mit einem veralteten Integritätsprofil ab. |
| **2.9.198** | 🔴 fix | System/OPcache Warmup | **`CMS/core/Services/OpcacheWarmupService.php` wärmt nur noch verwaltete CMS-Top-Level-Pfade vor und ignoriert fremde Live-Verzeichnisse wie `ptc/`, `meridan/` oder `beta/` automatisch**: OPcache-Warmup läuft damit nicht mehr in Compile-Fehler aus Altlasten außerhalb des eigentlichen 365CMS-Kerns. |

### v2.9.197 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.197** | 🔴 fix | Admin/EditorJS Runtime | **`CMS/assets/js/admin-content-editor.js` synchronisiert DE→EN-Kopie und AI-Übernahme jetzt nicht nur mit den Hidden-Inputs, sondern rendert die Zielblöcke robust in die bereits laufende EN-Editorinstanz zurück**: Seiten- und Beiträge-Editoren reagieren dadurch auch dann sichtbar auf „Alles aus DE nach EN kopieren“, wenn der EN-Editor bereits lazy initialisiert wurde oder schon offen ist. |

### v2.9.196 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.196** | 🔴 fix | Admin/EditorJS | **`CMS/assets/js/admin-content-editor.js` kopiert bei `Alles aus DE nach EN kopieren` den deutschen Inhalt jetzt direkt und überschreibt vorhandene EN-Felder bewusst ohne zusätzliche Abbruch-Confirm-Logik**: Seiten- und Beiträge-Editoren reagieren damit wieder sofort auf den Button statt bestehende EN-Inhalte still als Blocker im Hintergrund zu behandeln. |

### v2.9.195 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.195** | 🔴 fix | Core/Site Tables | **`CMS/core/Services/SiteTable/SiteTableHubRenderer.php` prüft Hub-Slugs jetzt über den echten Site-Table-Repository-Pfad statt über eine nicht existente `content_type`-Spalte in `bs_pages`**: Ältere oder aktuelle Installationen ohne `pages.content_type` erzeugen damit keine SQL-Fatals mehr, wenn Hub-Routing oder Host-/Slug-Erkennung anspringen. |

### v2.9.194 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.194** | 🔴 fix | Frontend/Landing Mobile | **`CMS/themes/cms-default/partials/home-landing.php` zwingt die Landing-Feature-Kacheln unter `768px` jetzt explizit auf eine einzelne Spalte und stapelt Icon-Left-Karten mobil sauber untereinander**: Die Landingpage zeigt ihre Kacheln in der Mobileansicht damit nicht mehr nebeneinander gequetscht, sondern lesbar als echte vertikale Kartenfolge. |

### v2.9.193 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.193** | 🔴 fix | Admin/EditorJS | **`CMS/assets/js/admin-content-editor.js` stellt seine Basis-Helper `parseJsonInput()`, `clearElement()` und `extractTextFromHtml()` am Dateikopf wieder korrekt her**: Der gemeinsame Seiten-/Beiträge-Editor initialisiert dadurch wieder vollständig, sodass der Wechsel zwischen Deutsch und Englisch im Editor nicht länger ins Leere läuft. |

### v2.9.192 — 12. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.192** | 🔴 fix | Core/Auth & Frontend | **`CMS/index.php` lädt `config/app.php` jetzt vor der Session-Cookie-Domain-Auflösung**: Default-Theme-Logins bleiben damit nach erfolgreicher Anmeldung auf derselben Origin im gültigen Session-Vertrag, statt sofort wieder auf eine anonyme Startseite zurückzufallen. |
| **2.9.192** | 🔴 fix | Landing/Theme Runtime | **`CMS/themes/cms-default/home.php` nutzt im Landing-Modus jetzt ausschließlich `partials/home-landing.php`, und die öffentliche Hero-Badge folgt dem gespeicherten Feld `badge_text` statt dem veralteten `version`-Schattenwert**: Änderungen aus dem Landing-Admin wirken damit public wieder sichtbar und laufen nicht länger durch doppelte Frontend-Logik auseinander. |
| **2.9.192** | 🔴 fix | Core/System & Admin Runtime | **`CMS/core/Router.php`, `CMS/core/Services/Media/UploadHandler.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/core/Services/DashboardService.php`, `CMS/core/Services/MemberService.php`, die DSGVO-/Settings-Module sowie der Backups-Admin ziehen Regex-, MariaDB-`SHOW COLUMNS`-, Capability- und Legacy-Fallback-Pfade nach**: Runtime-Warnungen, Backup-Rechteabbrüche und lokale 404s für Marketplace-/Sidebar-Kompatibilitätsdateien fallen damit deutlich leiser und fail-closed aus. |

### v2.9.190 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.190** | 🔴 fix | CMS Auth Runtime Logging | **`CMS/core/Services/CmsAuthPageService.php` hängt Settings-, Seitenlisten- und Passwort-Reset-Fehler jetzt an einen dedizierten Logger-Channel statt an rohe `error_log()`-Pfadstücke**: Design-/Auth-Fehler bleiben damit näher am strukturierten Diagnosevertrag des restlichen Admin-/Runtime-Stacks. |

### v2.9.189 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.189** | 🔴 fix | Landing Runtime Contract | **`CMS/core/Services/LandingPageService.php` normalisiert Legacy-Feature-Titel jetzt mbstring-fallsicher und meldet Servicefehler strukturiert über Logger**: Default-Upgrades und Diagnosepfade des Landing-Editors kippen damit weder auf Setups ohne `mbstring` noch in rohe PHP-Logs. |

### v2.9.188 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.188** | 🔴 fix | Menü Editor Entry | **`CMS/admin/menu-editor.php` und `CMS/admin/modules/menus/MenuEditorModule.php` validieren angeforderte `?menu=`-Ziele jetzt vor dem Rendern gegen den Live-Bestand und brechen stale Menülinks mit Warn-Redirect fail-closed ab**: Veraltete Menü-URLs landen damit nicht mehr kommentarlos in derselben Leerauswahl wie ein absichtlich ungewähltes Menü. |

### v2.9.187 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.187** | 🔴 fix | Assets/Fonts | **`CMS/assets/js/admin-font-manager.js` nutzt auch ohne `requestSubmit()` nur noch native temporäre Submitter statt direkter `form.submit()`-Bypässe**: Delete-, Download- und Settings-Aktionen bleiben damit auch im Browser-Fallback am Pending-/Submitter-Vertrag des Font-Managers. |

### v2.9.186 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.186** | 🔴 fix | Assets/Menüs | **`CMS/assets/js/admin-menu-editor.js` stößt bestätigte Menü-Deletes jetzt auch im Fallback über native temporäre Submitter statt über `form.submit()` an**: Der Menü-Editor verliert damit einen weiteren Legacy-Bypass außerhalb des gemeinsamen Submit-Lock-Vertrags. |

### v2.9.185 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.185** | 🔴 fix | Assets/Theme Explorer | **`CMS/assets/js/admin-theme-explorer.js` nutzt für `Ctrl+S` jetzt auch ohne `requestSubmit()` nur noch einen nativen temporären Submitter statt eines direkten `form.submit()`-Fallbacks**: Explorer-Saves umgehen damit weder Validation noch Submit-Lock im Browser-Legacy-Pfad. |

### v2.9.184 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.184** | 🔴 fix | Assets/Design Submitters | **Theme-Explorer-, Menü- und Font-Assets ziehen ihre letzten direkten Fallback-Submits jetzt auf native Submitter-Verträge zusammen**: Browser ohne `requestSubmit()` laufen damit in denselben Submit-/Pending-/Listener-Pfad wie moderne Laufzeitumgebungen. |

### v2.9.183 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.183** | 🔴 fix | Theme Marketplace Filesystem | **`CMS/admin/modules/themes/ThemeMarketplaceModule.php` behandelt Symlinks in Paket-Promotion, Copy und Cleanup jetzt fail-closed und reserviert Theme-Zielordner per Install-Lock**: Parallele Theme-Installationen und symlinkartige Archiv-Sonderpfade kippen damit nicht mehr in unklare Finalisierungs- oder Cleanup-Zustände. |

### v2.9.182 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.182** | 🔴 fix | Theme Marketplace Install | **`CMS/admin/modules/themes/ThemeMarketplaceModule.php` serialisiert Marketplace-Installationen jetzt pro Theme-Zielordner**: Gleichzeitige Installationen desselben Themes laufen damit nicht mehr parallel bis in Download-, Update- und Finalisierungspfade hinein. |

### v2.9.181 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.181** | 🔴 fix | Theme Core Delete Contract | **`CMS/core/ThemeManager.php` löscht Themes jetzt nur noch symlink-sicher innerhalb validierter Managed-Theme-Roots**: Rekursive Theme-Deletes geraten damit nicht mehr über Nicht-Theme-Verzeichnisse oder Link-Sonderpfade außerhalb von `THEME_PATH` in gefährliche Zustände. |

### v2.9.180 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.180** | 🔴 fix | Theme Core Mutations | **`CMS/core/ThemeManager.php` serialisiert Activate-/Delete-Aktionen jetzt über kleine Theme-Mutations-Locks**: Gleichzeitige Theme-Wechsel oder Theme-Löschungen kippen damit nicht mehr in konkurrierende Admin-Mutationen. |

### v2.9.179 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.179** | 🔴 fix | Theme Core Discovery | **`CMS/core/ThemeManager.php` enumeriert verwaltete Themes jetzt nur noch über validierte Nicht-Symlink-Verzeichnisse innerhalb von `THEME_PATH`**: Theme-Liste, Health-Check und Delete-Pfade hängen damit nicht länger an zu optimistischen Verzeichnisannahmen. |

### v2.9.178 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.178** | 🔴 fix | Admin/Themes Runtime | **`CMS/admin/modules/themes/ThemesModule.php` spiegelt Activate-/Delete-POSTs jetzt gegen den aktuellen Managed-Theme-Bestand**: Stale oder parallel entfernte Theme-Ziele enden damit nicht mehr tief im Runtime-Core, sondern fail-closed direkt im Modul. |

### v2.9.177 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.177** | 🔴 fix | Assets/Themes | **`CMS/assets/js/admin-themes.js`, `CMS/admin/themes.php` und `CMS/admin/views/themes/list.php` ziehen Theme-Aktivierung und Theme-Löschung jetzt auf ein dediziertes Admin-Asset mit Shared-Confirm und Submit-Locks**: Die Theme-Liste nutzt damit keine Inline-Skripte oder direkten `form.submit()`-Bypässe mehr. |

### v2.9.176 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.176** | 🔴 fix | Checkout/Abo-Settings | **`CMS/orders.php` macht Zahlungsarten, Steuersatz, Steuer-Inklusivlogik sowie AGB-/Widerrufsseiten jetzt wirklich runtime-wirksam**: Öffentliche Bestellungen speichern damit Netto-, Steuer- und Gesamtbetrag plus gewählte Zahlungsmethode konsistent und validieren AGB-Zustimmung gegen die konfigurierten Rechtstexte statt gegen einen impliziten Rechnung-/Standardpfad. |

### v2.9.175 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.175** | 🔴 fix | Checkout/Runtime | **`CMS/orders.php` lehnt deaktivierte Subscription-/Ordering-Zustände und inaktive Pakete jetzt fail-closed ab und nutzt für Login-, Fallback- und Erfolgssprünge nur noch hostneutrale relative Ziele**: Öffentliche Bestellpfade geraten damit nicht mehr in halb offene Checkout-Zustände oder an `SITE_URL` gebundene Rücksprünge. |

### v2.9.174 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.174** | 🔴 fix | Abo/Runtime Contract | **`CMS/core/Services/CoreModuleService.php` löst Subscription-Module jetzt fallbacksicher über ihre Legacy-Settings wie `subscription_enabled`, `subscription_ordering_enabled` oder `subscription_public_pricing_enabled` auf**: Gespeicherte Abo-Schalter bleiben damit nicht länger dekorativ in `settings.option_name` liegen, sondern steuern Checkout-, Member- und Admin-Runtime wieder konsistent. |

### v2.9.173 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.173** | 🔴 fix | Member/Abo Runtime | **`CMS/core/Services/MemberService.php` und `CMS/member/subscription.php` respektieren deaktivierte Bestellstrecken jetzt auch im Member-Bereich tatsächlich**: Buchbare Pakete verschwinden bei deaktiviertem Ordering aus der Member-Runtime, und der Rücksprung aus einem deaktivierten Abo-Bereich bleibt hostneutral auf `/member/dashboard`. |

### v2.9.172 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.172** | 🔴 fix | Assets/Abos | **Das neue `CMS/assets/js/admin-subscriptions.js` bündelt Orders-, Pakete- und Settings-Flows jetzt in einem gemeinsamen First-Party-Asset mit nativen temporären Submittern und Submit-Locks**: Delete-, Assign-, Create- und Edit-Aktionen laufen damit nicht länger über view-lokale Inline-Skripte oder direkte `form.submit()`-Bypässe. |

### v2.9.171 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.171** | 🔴 fix | Admin/Abos UI | **`CMS/admin/views/subscriptions/packages.php` und `CMS/admin/views/subscriptions/settings.php` entfernen die letzten Inline-`onclick`- und Hidden-Submit-Sonderwege aus dem Paket- und Settings-Admin**: Paket-Create/Edit/Delete sowie Seed-/Settings-/Modal-Saves hängen damit am gemeinsamen Asset-Vertrag statt an lokalen Inline-Handlern und ungesperrten Mehrfach-POSTs. |

### v2.9.170 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.170** | 🔴 fix | Admin/Orders UI | **`CMS/admin/views/subscriptions/orders.php` zieht Statusfilter, Assign-Modal und Delete-Flow jetzt auf kanonische Statuswerte plus gemeinsames Asset um**: Doppelte Legacy-Filter wie `confirmed`/`completed` verschwinden aus der Oberfläche, und Bestell-Zuweisungen bzw. Deletes laufen nicht mehr in Inline-Skripte oder ungesperrte Folge-Submits. |

### v2.9.169 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.169** | 🔴 fix | Member/Header | **`CMS/member/partials/header.php` rendert das konfigurierte `dashboard_logo` jetzt tatsächlich im Member-Branding und zieht interne Dashboard-, Profil-, Security-, Admin- und Logout-Links auf hostneutrale relative Ziele zusammen**: Gespeichertes Header-Branding und interne Member-Navigation hängen damit nicht mehr an toten Settings oder `SITE_URL`-gebundenen Links. |

### v2.9.168 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.168** | 🔴 fix | Member/Benachrichtigungen | **`CMS/member/notifications.php` folgt dem Notification-Center-Vertrag jetzt direkt**: Die Member-Seite respektiert `center_enabled`, nutzt den konfigurierten Leertext und zeigt nur noch die über die Admin-Auswahl erlaubten Notification-Typen statt eines roh geladenen Standard-Feeds. |

### v2.9.167 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.167** | 🔴 fix | Member/Dashboard | **`CMS/member/dashboard.php` macht `show_quickstart`, Notification-Center-Empty-State und interne Quicklinks jetzt tatsächlich runtime-wirksam**: Schnellzugriff, Dashboard-Panel und Member-interne Links folgen damit wieder denselben Frontend-Modul- und Same-Origin-Verträgen wie die gespeicherten Admin-Settings. |

### v2.9.166 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.166** | 🔴 fix | Member/Core Notifications | **`CMS/member/includes/class-member-controller.php` bündelt den Notification-Center-Vertrag jetzt zentral über Konfiguration plus gefilterten Feed**: `center_enabled`, `types` und `empty_text` bleiben damit nicht länger reine Admin-Dekoration, sondern steuern Dashboard und Notification-Seite fail-closed über denselben Runtime-Pfad. |

### v2.9.165 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.165** | 🔴 fix | Member/Routing | **`CMS/member/includes/class-member-controller.php` verwendet für Login-Fallback und interne Redirects jetzt hostneutrale relative Routen statt `SITE_URL`-Absoluten**: Member-Login-, PRG- und Interaktionspfade bleiben damit auch unter Proxy-, Alternativhost- und lokalen Dev-Umgebungen auf der aktuellen Origin. |

### v2.9.164 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.164** | 🔴 fix | Admin/Member Assets | **`CMS/admin/views/member/plugin-widgets.php` und das neue `CMS/assets/js/admin-member-dashboard.js` ziehen die Plugin-Widget-Sortierung aus dem Inline-Skript in ein dediziertes Admin-Asset um**: Drag-&-Drop-Reihenfolge und gespeicherte Widget-Order hängen damit nicht länger an einem View-lokalen Script-Sonderpfad. |

### v2.9.163 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.163** | 🔴 fix | Assets/Member Security | **`CMS/assets/js/member-dashboard.js` ersetzt den direkten `form.submit()`-Pfad der Passkey-Registrierung durch einen temporären nativen Submitter**: WebAuthn-Registrierungen umgehen damit Browser-Validierung und denselben Submit-Vertrag wie andere sichere Formulare im Member-Bereich nicht länger per Direkt-Bypass. |

### v2.9.162 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.162** | 🔴 fix | Assets/Medien | **`CMS/assets/js/admin-media-integrations.js` ersetzt direkte Confirm-/Auto-Submit-Fallbacks durch temporäre native Submitter und sperrt Bulk-Submits gegen Doppel-POSTs**: Delete-, Filter- und Bulk-Roundtrips bleiben damit näher an Browser-Validierung, Submit-Events und dem gemeinsamen Admin-Formvertrag. |

### v2.9.161 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.161** | 🔴 fix | Admin/Medien UI | **`CMS/admin/views/media/library.php` entfernt die letzten Inline-`this.form.submit()`-Pfade aus Bibliotheks-Filter und Datei-Kategoriezuweisung**: Select-Roundtrips hängen damit nicht mehr an View-lokalen Submit-Bypässen, sondern am dedizierten Medien-Asset. |

### v2.9.160 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.160** | 🔴 fix | Admin/Medien Kategorien | **`CMS/core/Services/Media/MediaRepository.php` bestätigt Kategorie-Löschungen jetzt nur noch für tatsächlich vorhandene Slugs**: Veraltete oder parallel entfernte Medienkategorien enden damit nicht mehr in stillen Schein-Erfolgen. |

### v2.9.159 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.159** | 🔴 fix | Admin/Medien Bulk | **`CMS/admin/modules/media/MediaModule.php` spiegelt Bulk-`paths[]` und Bulk-Move-Ziele jetzt vor dem Write gegen den aktuellen Medienbestand**: Gemischte stale/protected Auswahlen oder fehlende Zielordner laufen damit nicht mehr in partielle Sammelmutationen. |

### v2.9.158 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.158** | 🔴 fix | Admin/Medien Mutationen | **`CMS/admin/modules/media/MediaModule.php` blockiert Delete-, Rename- und Move-Aktionen jetzt fail-closed für geschützte Systempfade sowie bereits verschwundene Quellen**: Manipulierte oder stale Medienziele wirken damit nicht länger wie legitime Mutationen. |

### v2.9.157 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.157** | 🔴 fix | Admin/Medien Upload | **`CMS/admin/modules/media/MediaModule.php` akzeptiert Uploads nur noch in real vorhandene Zielordner statt fehlende Pfade implizit weiterzureichen**: Manipulierte oder veraltete Upload-Ziele kippen damit nicht mehr erst spät im Service- oder Dateisystempfad. |

### v2.9.156 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.156** | 🔴 fix | Admin/Medien Bibliothek | **`CMS/admin/media.php` bricht stale `?path=...`-Ziele jetzt mit Flash-Hinweis und Root-/Fallback-Rücksprung fail-closed ab**: Veraltete Bibliothekslinks enden damit nicht mehr in leeren Pseudo-Ordneransichten. |

### v2.9.155 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.155** | 🔴 fix | Admin/Medien Runtime | **`CMS/core/Services/MediaService.php` und `CMS/core/Services/Media/MediaRepository.php` reichen jetzt explizite Existenz-, Verzeichnis- und Protected-Path-Helfer an den Admin durch**: Medienmutationen können stale oder geschützte Ziele damit bereits vor dem eigentlichen Write-Vertrag fail-closed ablehnen. |

### v2.9.154 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.154** | 🔴 fix | Assets/Benutzer & Gruppen | **`CMS/assets/js/admin-users.js` und `CMS/assets/js/admin-user-groups.js` ziehen Bulk- und Delete-Dispatch jetzt auf Submit-Lock bzw. temporären nativen Submitter zusammen**: Benutzer-Bulk-Aktionen laufen damit nicht mehr in Doppel-POSTs, und Gruppen-Löschungen umgehen den Browser-/Form-Vertrag nicht länger über direkten `form.submit()`-Bypass. |

### v2.9.153 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.153** | 🔴 fix | Admin/Rollen & Rechte | **`CMS/admin/modules/users/RolesModule.php` behandelt Custom-Capability-Umbenennungen und -Löschungen jetzt fail-closed gegen den aktuellen Bestand statt stale Slugs mit `UPDATE`/`DELETE` still wie Erfolg wirken zu lassen**: Veraltete oder parallel entfernte Rechte enden damit nicht mehr in irreführenden Erfolgsmeldungen. |

### v2.9.152 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.152** | 🔴 fix | Admin/Rollen | **`CMS/admin/modules/users/RolesModule.php` kapselt Rename-/Delete-Pfade für Custom-Rollen jetzt transaktional und bestätigt sie nur noch für tatsächlich vorhandene Ziele**: Stale Rollen-Slugs oder teilgeschriebene Mehrzeilen-Mutationen wirken damit nicht länger wie erfolgreiche RBAC-Änderungen. |

### v2.9.151 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.151** | 🔴 fix | Admin/RBAC | **`CMS/admin/modules/users/RolesModule.php` legt neue Rollen und Capabilities jetzt transaktional an statt breite Fan-out-INSERTs ohne Rollback-Schutz zu schreiben**: Teilfehler lassen den Rollen-/Rechtebestand damit nicht mehr halb erweitert zurück. |

### v2.9.150 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.150** | 🔴 fix | Admin/RBAC Matrix | **`CMS/admin/modules/users/RolesModule.php` speichert die Berechtigungsmatrix jetzt transaktional statt den RBAC-Bestand per `DELETE` plus Folge-INSERTs ungeschützt neu aufzubauen**: Scheitert eine Teilmutation, bleibt die Rollen-/Rechte-Matrix damit nicht mehr teilweise geleert zurück. |

### v2.9.149 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.149** | 🔴 fix | Admin/Gruppen | **`CMS/admin/modules/users/GroupsModule.php` kapselt Gruppen-Löschungen jetzt transaktional und bestätigt sie nur noch für tatsächlich vorhandene Datensätze**: Stale oder parallel gelöschte Gruppen enden damit nicht mehr in stillen Erfolgszuständen. |

### v2.9.148 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.148** | 🔴 fix | Admin/Gruppen | **`CMS/admin/modules/users/GroupsModule.php` behandelt stale Gruppen-IDs beim Bearbeiten jetzt fail-closed statt `UPDATE ... WHERE id = ?` mit `rowCount() === 0` indirekt wie Erfolg aussehen zu lassen**: Veraltete Gruppen-Edit-Ziele kippen damit nicht mehr in irreführende Save-Banner. |

### v2.9.147 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.147** | 🔴 fix | Admin/Benutzer Bulk | **`CMS/admin/modules/users/UsersModule.php` validiert Bulk-`ids[]` jetzt explizit gegen den aktuellen Benutzerbestand und blockt Self-Targeting vor dem Sammel-Write**: Stale Bulk-Auswahlen oder Aktionen auf den eigenen Account kippen damit nicht mehr in stille Schein-Erfolge oder partielle Sammelresultate. |

### v2.9.146 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.146** | 🔴 fix | Admin/Benutzer Fehlerpfade | **`CMS/admin/modules/users/UsersModule.php` stellt `buildUserEditSourceUrl()` wieder als echte Helper-Methode für Error- und Report-Payloads bereit**: Save-/Delete-Fehlerpfade laufen damit nicht mehr selbst in einen `undefined method`-Fatal, wenn sie den Diagnosekontext aufbauen. |

### v2.9.145 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.145** | 🔴 fix | Admin/Benutzer | **`CMS/admin/users.php` bricht stale `?action=edit&id=...`-Ziele jetzt mit Flash-Hinweis und Listen-Rücksprung fail-closed ab statt verschwundene Benutzer in leeren oder künstlich weiterbefüllten Edit-Formularen enden zu lassen**: Veraltete Benutzer-Links oder Saves auf inzwischen gelöschte Konten wirken damit nicht mehr wie legitime Bearbeitungszustände. |

### v2.9.144 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.144** | 🔴 fix | Assets/Editor.js | **`CMS/assets/js/admin-content-editor.js` ersetzt den letzten direkten Submit-Fallback im Shared-Editor durch einen temporären nativen Submitter statt `form.submit()`/Prototype-Bypass**: Auch der Altpfad der gemeinsamen Seiten-/Beiträge-Saves bleibt damit näher an Browser-Validierung, Submit-Events und dem echten Formularvertrag. |

### v2.9.143 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.143** | 🔴 fix | Admin/Beiträge Tags | **`CMS/admin/modules/posts/PostsModule.php` behandelt Tag-Löschungen jetzt auch bei stale IDs fail-closed und bestätigt harte Deletes nur noch für tatsächlich vorhandene Tags**: Veraltete oder parallel gelöschte Tag-Ziele enden damit nicht mehr in stillen Erfolgsmeldungen. |

### v2.9.142 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.142** | 🔴 fix | Admin/Beiträge Kategorien | **`CMS/admin/modules/posts/PostsModule.php` behandelt Kategorie-Löschungen jetzt auch bei stale IDs fail-closed und bestätigt harte Deletes nur noch für tatsächlich vorhandene Kategorien**: Veraltete oder parallel gelöschte Kategorie-Ziele wirken damit nicht mehr wie erfolgreich entfernt. |

### v2.9.141 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.141** | 🔴 fix | Admin/Beiträge | **`CMS/admin/modules/posts/PostsModule.php` prüft Einzel-Delete und Bulk-`ids[]` jetzt explizit gegen den aktuellen Post-Bestand statt stale Ziele oder gemischte Auswahlmengen still als Erfolg zu behandeln**: Offene Grid-Auswahlen und parallele Löschungen kippen damit nicht mehr in irreführende Sammel- oder Delete-Banner. |

### v2.9.140 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.140** | 🔴 fix | Admin/Beiträge Tags | **`CMS/admin/post-tags.php` bricht stale `?edit=`-Ziele jetzt mit Flash-Hinweis und Rücksprung in die Tag-Liste fail-closed ab statt still ein Neuformular zu rendern**: Veraltete Tag-Edit-Links wirken damit nicht mehr wie legitime Create-Kontexte. |

### v2.9.139 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.139** | 🔴 fix | Admin/Beiträge Kategorien | **`CMS/admin/post-categories.php` bricht stale `?edit=`-Ziele jetzt mit Flash-Hinweis und Rücksprung in die Kategorie-Liste fail-closed ab statt still ein Neuformular zu rendern**: Veraltete Kategorie-Edit-Links kippen damit nicht mehr in irreführende Create-Zustände. |

### v2.9.138 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.138** | 🔴 fix | Admin/Beiträge | **`CMS/admin/posts.php` behandelt stale `?action=edit&id=...`-Ziele jetzt nicht mehr wie `Neuer Beitrag`, sondern bricht sie mit Flash-Hinweis und Listen-Rücksprung fail-closed ab**: Veraltete Post-Edit-Links können dadurch keine versehentlichen Neu-Anlagen mehr auslösen. |

### v2.9.137 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.137** | 🔴 fix | Admin/Orders UI | **`CMS/admin/views/subscriptions/orders.php` ersetzt den letzten direkten `form.submit()`-Fallback im Shared-Submit-Helper durch einen nativen temporären Submitter**: Auch Altbrowser-Fallbacks umgehen damit keine Submit-Events oder Browser-Validierung mehr über einen direkten Bypass-Aufruf. |

### v2.9.136 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.136** | 🔴 fix | Admin/Abos Einstellungen | **`CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` verlangt für alle Settings-Lese-/Schreibpfade jetzt explizit `manage_settings` statt nur generischem Admin-Zugriff**: Abo-Einstellungen folgen damit derselben Capability-Kante wie der restliche mutierende Settings-Admin. |

### v2.9.135 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.135** | 🔴 fix | Admin/Pakete | **`CMS/admin/modules/subscriptions/PackagesModule.php` prüft seine CRUD-/Toggle-Pfade jetzt über dieselbe explizite `manage_settings`-Capability statt nur über `isAdmin()`**: Paket-Mutationen bleiben damit auch modulseitig fail-closed an der echten Rechtekante hängen. |

### v2.9.134 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.134** | 🔴 fix | Admin/Orders | **`CMS/admin/modules/subscriptions/OrdersModule.php` bindet Bestell-Assign-/Status-/Delete-Pfade jetzt ebenfalls an `manage_settings` statt nur an generische Admin-Rechte**: Der Orders-Admin fällt damit nicht mehr aus dem Capability-Vertrag des restlichen Settings-Clusters heraus. |

### v2.9.133 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.133** | 🔴 fix | Admin/Abos | **`CMS/admin/packages.php` zieht Entry-Guard und Section-Shell-`access_checker` für die Pakete-Seite auf explizites `manage_settings` nach**: Rollen mit breitem Admin-Flag, aber ohne Settings-Recht, erreichen die Paketverwaltung damit nicht mehr versehentlich über die alte zu weite Kante. |

### v2.9.132 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.132** | 🔴 fix | Admin/Abo-Einstellungen | **`CMS/admin/subscription-settings.php` verlangt im Entry-Guard jetzt dieselbe explizite `manage_settings`-Capability wie andere Settings-Seiten**: Die Abo-Einstellungsseite hängt damit nicht länger nur am generischen Admin-Flag. |

### v2.9.131 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.131** | 🔴 fix | Admin/Orders | **`CMS/admin/orders.php` zieht Entry-Guard und Section-Shell-`access_checker` auf explizites `manage_settings` hoch**: Die Bestellverwaltung folgt damit derselben Ziel-Capability wie benachbarte mutierende Settings-/System-Admins statt einer breiteren reinen `isAdmin()`-Kante. |

### v2.9.130 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.130** | 🔴 fix | Admin/Abos UI | **`CMS/admin/views/subscriptions/packages.php` blockt Paket-Saves im Modal jetzt über einen expliziten Submit-Lock und setzt ihn beim Reset/Schließen sauber zurück**: Hektische Mehrfachklicks erzeugen damit keine doppelten Paket-POSTs mehr, obwohl die Pakete-UI bisher keinen eigenen Pending-/Lock-Vertrag kannte. |

### v2.9.129 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.129** | 🔴 fix | Admin/Abos UI | **`CMS/admin/views/subscriptions/packages.php` leitet bestätigte Paket-Löschungen jetzt über denselben nativen Submit-Pfad wie der restliche Admin statt über direkten Hidden-`form.submit()`-Bypass weiter**: Paket-Deletes bleiben damit näher am echten Browser-/Confirm-Vertrag statt an einem verbliebenen Inline-Sonderweg zu hängen. |

### v2.9.128 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.128** | 🔴 fix | Admin/Abos & Pakete | **`CMS/admin/modules/subscriptions/PackagesModule.php` behandelt Paket-Create-/Update-/Delete-/Toggle-Pfade jetzt belastbarer fail-closed**: Fehlgeschlagene `insert()`-/`update()`-Writes, stale Deletes und konkurrierende Toggle-Wechsel enden nicht mehr als stille Erfolge, sondern melden echte Persistenz- bzw. Reload-Fehler an den Admin zurück. |

### v2.9.127 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.127** | 🔴 fix | Admin/Abos Einstellungen | **`CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` behandelt fehlgeschlagene `settings`-Writes jetzt fail-closed statt `Database::insert()`-/`update()`-Fehlschläge still zu übergehen**: Abo- und Paket-Einstellungen erscheinen damit nicht länger als erfolgreich gespeichert, obwohl im Persistenzpfad tatsächlich nichts geschrieben wurde. |

### v2.9.126 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.126** | 🔴 fix | Admin/Tabellen | **`CMS/admin/modules/tables/TablesModule.php` bestätigt Tabellen-Löschungen jetzt nur noch für tatsächlich existierende bzw. wirklich entfernte Datensätze**: unbekannte oder zwischenzeitlich entfernte Tabellen enden damit nicht mehr in einem stillen Schein-Erfolg, sondern sauber mit fail-closed Rückmeldung. |

### v2.9.125 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.125** | 🔴 fix | Admin/Tabellen UI | **`CMS/admin/site-tables.php` und `CMS/admin/modules/tables/TablesModule.php` behandeln stale `?action=edit&id=...`-Ziele jetzt nicht mehr wie `Neue Tabelle`**: Veraltete Tabellen-Links landen damit wieder mit Fehlhinweis in der Liste statt in einem irreführenden Neuformular, das unabsichtlich als frische Tabelle gespeichert werden könnte. |

### v2.9.124 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.124** | 🔴 fix | Admin/Tabellen | **`CMS/admin/modules/tables/TablesModule.php` akzeptiert beschädigte `columns_json`-/`rows_json`-Payloads aus dem Tabellen-Editor jetzt nicht länger still als leere Arrays**: Kaputte Hidden-JSON-Werte führen damit nicht mehr zu leeren Tabellen-Saves, sondern brechen den POST fail-closed mit klarem Hinweis ab. |

### v2.9.123 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.123** | 🔴 fix | Admin/Abos & Orders | **`CMS/admin/modules/subscriptions/OrdersModule.php` zieht Statuswechsel jetzt fail-closed auf einen race-sicheren Update-Vertrag zusammen**: Statusänderungen werden nicht mehr nur gegen einen zuvor gelesenen Snapshot validiert, sondern per konditionalem Write auch gegen den tatsächlich noch unveränderten DB-Status abgesichert. Zwischenzeitlich geänderte Bestellungen enden damit nicht mehr in still möglichen „unmöglichen“ Transitionen, sondern sauber mit Reload-Hinweis. |

### v2.9.122 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.122** | 🔴 fix | Admin/Abos UI | **Das Inline-Orders-UI in `CMS/admin/views/subscriptions/orders.php` nutzt für Delete-Resubmits jetzt den nativen `requestSubmit()`-Pfad und blockt Mehrfach-Submits im Zuweisungsmodal**: Bestell-Löschungen und Paketzuweisungen laufen damit näher am echten Browser-/Form-Vertrag statt an direktem `form.submit()`-Bypass oder hektischen Doppelklicks im Modal zu hängen. |

### v2.9.121 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.121** | 🔴 fix | Assets/Tabellen | **`CMS/assets/js/admin-site-tables.js` normalisiert Spalten-, Label- und Zellenwerte jetzt codepoint-sicher über `Array.from(...).slice(...)` statt über rohe UTF-16-`.slice()`-Grenzen**: Unicode-/Emoji-haltige Tabelleninhalte driften damit im Client nicht länger leichter vom serverseitigen UTF-8-/Fallback-Vertrag ab. |

### v2.9.120 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.120** | 🔴 fix | Assets/Tabellen | **`CMS/assets/js/admin-site-tables.js` schickt Hidden-Delete-/Duplicate-Formulare jetzt über `requestSubmit()` weiter und ruft den globalen Admin-Alert wieder mit dem echten Vertrag `cmsAlert(type, message)` auf**: Der Tabellen-Admin bleibt damit bei Duplicate/Delete näher am gemeinsamen Submit-/Confirm-Flow, und Validierungsfehler rendern nicht mehr an einem vertauschten Alert-Helfer vorbei. |

### v2.9.119 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.119** | 🔴 fix | Admin/Kommentare | **`CMS/admin/comments.php` spiegelt Aktionsrechte jetzt bereits am Entry explizit für Status-, Delete- und Bulk-Pfade statt erst tiefer im Modul**: Die Kommentar-Moderation bleibt damit capability-seitig klarer fail-closed, bevor Mutationspfade anlaufen oder nur implizit auf Modulantworten zurückfallen. |

### v2.9.118 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.118** | 🔴 fix | Assets/Kommentare | **`CMS/assets/js/admin-comments.js` hängt Bulk-`delete` jetzt an eine explizite Bestätigungsstufe und nutzt für Einzelaktionen denselben nativen Submit-Pfad wie der restliche Admin**: Kommentar-Löschungen laufen damit nicht mehr ohne zusätzliche Sicherheitsbremse oder per direktem `form.submit()`-Bypass in den POST. |

### v2.9.117 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.117** | 🔴 fix | Assets/Editor.js | **`CMS/assets/js/admin-content-editor.js` reicht Seiten-/Beitrags-Saves nach erfolgreicher Editor.js-Serialisierung jetzt über einen validierungswahrenden nativen Submit-Pfad weiter statt über direkten `form.submit()`-Bypass**: Der Shared-Editor bleibt damit näher an Browser-Validierung und demselben echten Formularvertrag wie die übrigen Admin-Flows. |

### v2.9.116 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.116** | 🔴 fix | Admin/SEO & Analytics | **`CMS/admin/modules/seo/SeoSuiteModule.php` behandelt SEO-Audit-, Broken-Link-, Indexing- und Backlink-Pfade jetzt konsistenter fail-closed**: `schema_type` und `twitter_card` werden beim Inline-Save auf erlaubte Verträge zurückgeführt, Same-Site-Absolute-URLs werden für Broken-Link- und IndexNow-Pfade nicht länger nur gegen die konfigurierte `SITE_URL` geprüft, und interne Referrer tauchen in der Backlink-Domain-Übersicht nicht mehr als scheinbare externe Herkunft auf. |
| **2.9.116** | 🔴 fix | Admin/SEO UI & Core/IndexNow | **`CMS/admin/views/seo/meta.php`, `CMS/admin/views/seo/sitemap.php`, `CMS/admin/views/seo/audit.php` und `CMS/core/Services/IndexingService.php` ziehen öffentliche SEO-Beispiel-, Sitemap- und IndexNow-Status-URLs auf runtime-aware Public-Basen und typgesicherte Audit-Eingaben zusammen**: SERP-Vorschauen, Sitemap-/robots-/Keyfile-Hinweise und Inline-Audits bleiben damit auch unter Proxy-, Alternativhost- oder lokaler Dev-Umgebung auf der aktuellen Public-Origin, statt an starren `SITE_URL`-Resten oder freien Enum-Textfeldern zu hängen. |

### v2.9.115 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.115** | 🔴 fix | Admin/Auth & Core/Loginpage | **`CMS/core/Services/CmsAuthPageService.php` behandelt Settings-Saves der CMS Loginpage jetzt fail-closed statt stille Schreibfehler als Erfolg zu melden und erzeugt Passwort-Reset-Links locale- sowie Public-Route-konsistent über `getPublicUrl(...)`**: Loginpage-Branding, Reset-Mail-Templates und Reset-Roundtrips laufen damit nicht mehr in Schein-Erfolge oder auf hart verdrahtete Auth-URLs, sobald Persistenz- oder Locale-/Slug-Pfade vom Default abweichen. |
| **2.9.115** | 🔴 fix | Public/Auth UI & Member-Security | **`CMS/views/auth/cms-auth.php`, `CMS/core/Routing/PublicRouter.php` und `CMS/member/includes/class-member-controller.php` ziehen Passkey- und MFA-Runtime jetzt auf locale-aware Dokumentsprachen, hostneutrale Asset-Pfade, script-sicher hex-encodete Passkey-Payloads und einen request-submit-basierten Submit-Lock gegen Doppelaktionen**: Öffentliche Login-/MFA-Seiten und der Member-Passkey-Vertrag bleiben damit näher an derselben sicheren Runtime-Basis statt an harten `lang="de"`-/`SITE_URL`-Resten, direktem `form.submit()`-Bypass und unnötig rohen Inline-JSON-Payloads zu hängen. |

---

### v2.9.114 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.114** | 🔴 fix | Admin/Design & Landing Page | **`CMS/admin/modules/landing/LandingPageModule.php` behandelt fehlgeschlagene Header-, Content-, Footer-, Feature- und Plugin-Saves jetzt fail-closed statt trotz `false`-/`0`-Rückgaben Erfolg zu melden**: Persistenzfehler oder abgewiesene Schreibpfade laufen damit nicht mehr in stille Erfolgs-Alerts, obwohl im Landing-Admin nichts gespeichert wurde. |
| **2.9.114** | 🔴 fix | Core/Landing & Design-UI | **`CMS/core/Services/Landing/LandingRepository.php`, `CMS/core/Services/Landing/LandingPluginService.php` und `CMS/admin/views/landing/page.php` begrenzen Feature-Mutationen jetzt strikt auf echte `feature`-Datensätze, melden unbekannte Feature-Deletes fail-closed, erlauben Plugin-Overrides auch ohne plugin-spezifischen Settings-Callback und hängen Feature-Löschungen an den gemeinsamen Confirm-Vertrag**: Manipulierte `feature_id`-POSTs können damit keine fremden Landing-Sektionen mehr überschreiben, stale Feature-Deletes wirken nicht länger wie erfolgreich und destruktive Feature-Löschungen laufen sichtbar über denselben Bestätigungsfluss wie andere Admin-Gefahrenzonen. |

---

### v2.9.113 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.113** | 🔴 fix | Admin/Design & Font Manager | **`CMS/admin/modules/themes/FontManagerModule.php` persistiert Theme-Scan-Caches jetzt wirklich belastbar per Upsert statt über einen wirkungslosen `UPDATE`-Scheinpfad und meldet unbekannte Font-IDs im Delete-Flow explizit fail-closed statt als stillen Erfolg**: Theme-Scans können damit ihren Cache tatsächlich wiederverwenden, und Löschanfragen auf stale oder manipulierte IDs wirken nicht mehr wie erfolgreich entfernte Fonts. |
| **2.9.113** | 🔴 fix | Assets/Design & Font Manager | **`CMS/assets/js/admin-font-manager.js` blockt Delete- und Submit-Flows jetzt ohne Selbstsperre im `requestSubmit()`-Pfad**: Bestätigte Löschungen oder Folge-Submits geraten damit nicht mehr in einen Zustand, in dem das Asset den eigenen Formular-POST schon vor dem echten Browser-Submit wieder abfängt. |

---

### v2.9.112 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.112** | 🔴 fix | Admin/Design & Menü-Editor | **`CMS/admin/modules/menus/MenuEditorModule.php` behandelt fehlerhaftes `items`-JSON jetzt fail-closed statt still auf ein leeres Array zurückzufallen, nutzt für Menülöschungen korrekt parametrisierte Deletes und kapselt Delete-/Save-Pfade transaktional**: Defekte oder manipulierte Menü-Payloads löschen damit nicht mehr versehentlich bestehende Menüs, und der Delete-Flow scheitert nicht länger an einem ungebundenen Placeholder-Query. |
| **2.9.112** | 🔴 fix | Assets/Design & Menü-Editor | **`CMS/assets/js/admin-menu-editor.js` schützt Save-, Delete- und Modal-Formulare jetzt über einen gemeinsamen Submit-Lock und nutzt für bestätigte Delete-POSTs den nativen Request-Submit-Pfad statt eines direkten `form.submit()`-Sonderwegs**: Hektische Mehrfachklicks erzeugen damit keine doppelten Menü-Requests mehr, und der Menü-Editor bleibt näher am tatsächlichen Browser-/Form-Vertrag. |

---

### v2.9.111 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.111** | 🔴 fix | Admin/Design & Theme Editor | **`CMS/admin/theme-editor.php` und `CMS/admin/views/themes/customizer-missing.php` härten eingebettete Theme-Customizer jetzt über explizite Größen- und Binärgrenzen sowie klarer gespiegelte Fallback-Constraints**: Oversize- oder binäre `admin/customizer.php`-Dateien werden damit nicht mehr still inline geladen, sondern fail-closed mit nachvollziehbarem Hinweis auf die sichere Fallback-Ansicht abgefangen. |
| **2.9.111** | 🔴 fix | Admin/Design & Theme Explorer | **`CMS/admin/theme-explorer.php` und `CMS/admin/modules/themes/ThemeEditorModule.php` blocken überlange Explorer-Dateipfade jetzt explizit, behandeln nicht lesbare Theme-Dateien konsistent als read-only bzw. überspringen sie im Baum und verhindern damit stille Dateipfad-Kürzungen oder irreführend editierbare Leerzustände**: Explorer-Auswahl, Dateibaum und Save-Vertrag bleiben dadurch näher an einem fail-closed Runtime-Zustand. |
| **2.9.111** | 🔴 fix | Themes/Customizer Runtime | **`CMS/themes/cms-default/admin/customizer/helpers.php`, `partials/page.php`, `partials/field.php` und `partials/scripts.php` ziehen den eingebetteten `cms-default`-Customizer auf hostneutrale Logo-/Seitenpfade, sichere Asset-URL-Sanitierung und DOM-basierte Preview-Resets zusammen**: Interne `/uploads/...`-Logo-Pfade bleiben damit konsistent speicher- und renderbar, unbekannte Aktionen enden explizit mit Fehler, und leere oder zurückgesetzte Bildzustände lassen keine stale Vorschau mehr im Customizer stehen. |

---

### v2.9.110 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.110** | 🔴 fix | Admin/Themes & Marketplace | **`CMS/admin/theme-marketplace.php` und `CMS/admin/modules/themes/ThemeMarketplaceModule.php` staffeln Theme-Katalogquellen jetzt über Cache-/Remote-/Local-Fallbacks, prüfen bereits installierte Themes explizit gegen den echten lokalen Bestand und liefern Installations- bzw. Constraint-Fehler strukturiert an den Shared-Flash-/Report-Vertrag zurück**: Theme-Installationen kippen damit nicht mehr in schwer nachvollziehbare Remote- oder Zielzustände, sondern bleiben mit Detail-, Hash-, Host- und Zielkontext nachvollziehbar im Admin. |
| **2.9.110** | 🔴 fix | Admin/Themes UI | **`CMS/admin/views/themes/marketplace.php` spiegelt Hosts, Paket-, Manifest- und Archivgrenzen, SHA-256-Kurzstatus, Host-/Archiv-Blocker und Kompatibilität jetzt deutlich transparenter direkt in Suche, Karten und Alert-Kontext**: Der Theme-Marketplace bleibt damit im UI näher an seinem tatsächlichen Runtime-Vertrag und zeigt schneller, warum ein Theme automatisch installierbar ist oder bewusst nur manuell behandelt werden darf. |

---

### v2.9.109 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.109** | 🔴 fix | Admin/Beiträge & Taxonomien | **`CMS/admin/modules/posts/PostsModule.php` normalisiert Kategorie- und Tag-Slugs jetzt vor Persistenz konsistent fail-closed, blockt stale Edit-IDs und meldet doppelte Tag-Slugs sauber vor dem DB-Write**: Kategorien und Tags laufen damit nicht mehr in stille Erfolgszustände oder nachträgliche Unique-Constraint-Kollisionen, wenn veraltete Datensätze oder unsaubere Slugs im Posts-Cluster ankommen. |
| **2.9.109** | 🔴 fix | Admin/Beiträge UI | **`CMS/admin/post-tags.php` und `CMS/admin/views/posts/tags.php` ziehen die Tag-Verwaltung auf einen vollständigen Create-/Edit-/Delete-Roundtrip zusammen, während `CMS/admin/views/posts/list.php` destruktive Bulk-Löschungen jetzt explizit bestätigt**: Der Beiträge-Admin bietet damit endlich eine echte Tag-Bearbeitung statt nur Neu/Löschen, und Sammellöschungen springen nicht mehr ohne Sicherheitsbremse direkt in den POST. |

---

### v2.9.108 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.108** | 🔴 fix | Admin/Medien & Services | **`CMS/core/Services/MediaService.php` und `CMS/core/Services/Media/MediaRepository.php` schreiben Media-Settings, Upload-`.htaccess` und Medien-Metadaten jetzt atomisch mit SHA-512-Integritätscheck statt über rohe Direktwrites**: Medien-Konfiguration und Kategoriemeta kippen damit bei Write-/Swap-Fehlern nicht mehr halbfertig in JSON- oder Schutzdateien, und beschädigte Meta-Strukturen werden beim Laden defensiver normalisiert. |
| **2.9.108** | 🔴 fix | Admin/Medien & Upload-Handling | **`CMS/core/Services/Media/UploadHandler.php` schließt Hidden-Name- und Rename-Bypässe jetzt fail-closed**: Versteckte Datei-/Ordnernamen wie `.htaccess` werden für neue Uploadziele und Renames blockiert, Dateiendungen lassen sich beim Umbenennen nicht mehr in andere Typen umbiegen, und nicht hochgeladene Tempdateien fallen bei Cross-Device-Moves auf einen sicheren Copy-/Cleanup-Pfad zurück. |

---

### v2.9.107 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.107** | 🔴 fix | Admin/Design & Theme Explorer | **`CMS/admin/modules/themes/ThemeEditorModule.php`, `CMS/admin/views/themes/editor.php` und `CMS/assets/js/admin-theme-explorer.js` härten den Theme-Explorer-Save-Vertrag jetzt auf atomische Datei-Swaps mit SHA-512-Integritätscheck, direkte Binary-Overwrite-Blockaden und einen robusteren Tree-/Search-Vertrag**: Erlaubte Dateiendungen mit Binärinhalt können nicht mehr per direktem POST leer überschrieben werden, und der Explorer rendert seine Such-/Tree-Datenattribute ohne nachgelagerten String-Umbau. |
| **2.9.107** | 🔴 fix | Admin/Design & Fonts | **`CMS/admin/modules/themes/FontManagerModule.php` und `CMS/admin/views/themes/fonts.php` sichern lokale Font-Downloads und -Bereinigung jetzt vollständiger ab**: Self-hosted Font-Binaries und CSS werden atomisch mit SHA-512 verifiziert geschrieben, das gespeicherte Primärformat entspricht wieder der tatsächlich geladenen Datei, und Löschungen räumen auch aus der CSS referenzierte WOFF-/TTF-Dateien auf, statt stille Reste im `uploads/fonts`-Pfad zu hinterlassen. |

---

### v2.9.106 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.106** | 🔴 fix | Admin/System & Marketplace | **`CMS/core/Services/UpdateService.php` normalisiert absolute und relative Marketplace-/Update-URLs jetzt über denselben strikten HTTPS-/Host-/Port-/Pfad-Vertrag**: Core-, Plugin- und Theme-Updates akzeptieren damit weder Credentials, Fremdports, Fragmente noch Traversal-artige Remote-Pfade still, und Katalog-/Manifest-/Download-Ziele werden fail-closed nur noch aus erlaubten Hosts bzw. sauberen relativen Pfaden zusammengesetzt. |
| **2.9.106** | 🔴 fix | Admin/Themes | **`CMS/admin/theme-marketplace.php` und `CMS/admin/modules/themes/ThemeMarketplaceModule.php` ziehen den Theme-Marketplace auf denselben Installationsvertrag wie der Plugin-Marketplace**: Theme-Slugs werden am Entry jetzt längenbegrenzt und gegen den aktuellen Katalog gespiegelt, während Auto-Installationen zusätzlich eine erlaubte ZIP-Endung und eine zulässige Paketgröße verlangen, statt halboffene Theme-Downloads bis in den Installer durchzureichen. |

---

### v2.9.105 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.105** | 🔴 fix | Admin/Recht & Info | **`CMS/admin/cookie-manager.php`, `CMS/admin/data-requests.php`, `CMS/admin/privacy-requests.php`, `CMS/admin/deletion-requests.php`, `CMS/admin/documentation.php`, `CMS/admin/support.php` und `CMS/admin/system-info.php` ziehen verbleibende generische `isAdmin()`-Guards jetzt auf explizite Capability-Helfer zusammen**: Cookie-Manager, DSGVO-Anfragen, Doku-Sync und Legacy-Aliasrouten spiegeln damit denselben Zielvertrag wie ihre Rechts-/Info-Seiten statt halboffene Admin-Oberflächen oder zu breite Alias-Sprünge zuzulassen. |
| **2.9.105** | 🔴 fix | Admin/System, Diagnose & Hub | **`CMS/admin/error-report.php`, `CMS/admin/hub-sites.php`, `CMS/admin/settings.php`, `CMS/admin/table-of-contents.php` und `CMS/admin/updates.php` verlangen jetzt konkrete Settings-Capabilities und fail-closed Mutation-Gates statt bloßer Admin-Rollenchecks**: Fehlerreport-POSTs, Hub-Site-Saves, globale Einstellungen, TOC-Konfiguration und Update-Läufe bleiben damit capability-seitig konsistent zu ihren Zielmodulen, statt erst nach geladenem Screen oder im Write-Pfad an Berechtigungen zu scheitern. |

---

### v2.9.104 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.104** | 🔴 fix | Assets/Editor.js | **`CMS/assets/js/editor-init.js` ersetzt verbleibende `innerHTML`-Pfade in Legacy-HTML-Sanitizing, `htmlToBlocks()` und dem gemeinsamen Editor.js-Bildpicker durch `DOMParser`, explizite Node-Serialisierung sowie DOM-basierten Overlay-/Grid-Aufbau**: Seiten- und Beitragseditor importieren Legacy-HTML, rendern Mediathek-Auswahl und synchronisieren Bildauswahl damit ohne stringbasierten First-Party-Markup-Pfad. |
| **2.9.104** | 🔴 fix | Assets/Recht | **`CMS/assets/js/cookieconsent-init.js` baut Consent-Banner, Präferenz-Modal, Kategorien und Services jetzt vollständig über echte DOM-Knoten statt über zusammengesetzte `innerHTML`-Strings**: Der öffentliche Consent-Dialog hält Banner-, Modal- und Kategorie-States damit stringfrei und näher an einem fail-closed Legal-/Frontend-Vertrag. |
| **2.9.104** | 🔴 fix | Assets/Grid | **`CMS/assets/js/gridjs-init.js` escaped Zellinhalte jetzt direkt über einen String-Escape-Helfer statt über einen temporären DOM-Container mit anschließendem `innerHTML`-Readback**: Die Shared-Grid-Bridge behält ihr Escaping damit ohne verbleibenden Markup-Sonderpfad im First-Party-Formatter. |

---

### v2.9.103 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.103** | 🔴 fix | Assets/SEO | **`CMS/assets/js/admin-seo-editor.js` analysiert HTML-Fallbacks jetzt über `DOMParser` und leert die Regelübersicht via DOM-Clear-Helper statt über `template.innerHTML` bzw. `rulesList.innerHTML = ''`**: Der Shared-SEO-Editor hält damit Score-, Readability- und Vorschaupfade stringärmer, selbst wenn Raw-/Caption-/Link-Fragmente aus bearbeitbaren Inhalten analysiert werden. |
| **2.9.103** | 🔴 fix | Assets/Marketplace | **`CMS/assets/js/admin-plugin-marketplace.js` und `CMS/assets/js/admin-theme-marketplace.js` schalten Install-Pending-Texte jetzt über `textContent` statt über gespeichertes bzw. überschriebenes `innerHTML` um**: Plugin- und Theme-Marketplace behalten damit ihren gesperrten Submit-Zustand ohne unnötigen Markup-Sonderpfad in First-Party-Buttons. |

---

### v2.9.102 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.102** | 🔴 fix | Assets/Hub | **`CMS/assets/js/admin-hub-site-edit.js`, `CMS/assets/js/admin-hub-template-edit.js` und `CMS/assets/js/admin-hub-template-editor.js` bauen Hub-Karten, Template-Quicklinks, TOC-, Section- und Starter-Card-Vorschauen sowie die zugehörigen Editor-Listen jetzt ausschließlich über echte DOM-Knoten statt über zusammengesetzte `innerHTML`-/`insertAdjacentHTML`-Strings**: Der Hub-Cluster hält damit Site- und Template-Editor-Renderpfade stringfrei und näher an einem fail-closed DOM-Vertrag. |
| **2.9.102** | 🔴 fix | Assets/Editor.js | **`CMS/assets/js/admin-content-editor.js` räumt Preview-/Holder-Zustände jetzt per DOM-Clear-Helper auf und extrahiert Vorschautext aus HTML via `DOMParser` statt über rohe `innerHTML`-Parse-Helfer**: Shared-Preview-, Diff- und Cleanup-Pfade in Seiten-/Beitragseditoren tragen damit keine verbliebenen stringbasierten Renderreste mehr mit. |

---

### v2.9.101 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.101** | 🔴 fix | Assets/Design | **`CMS/assets/js/admin-menu-editor.js` baut Parent-Selects, leere Zustände und die komplette Menü-Item-Liste jetzt ausschließlich über echte DOM-Knoten statt über zusammengesetzte `innerHTML`-Strings**: Der Menü-Editor hält damit Titel-, URL-, Parent- und Button-Renderpfade stringfrei und näher an einem fail-closed DOM-Vertrag. |
| **2.9.101** | 🔴 fix | Assets/Medien & Member | **`CMS/assets/js/admin-media-integrations.js` und `CMS/assets/js/member-dashboard.js` räumen Preview-, Upload-, Picker- und Statuszustände jetzt ebenfalls DOM-basiert auf und nach, statt verbleibende `innerHTML`-Fallbacks mitzuschleppen**: Medienbibliothek, interne Picker und Member-Uploads behalten damit auch in Fehler-, Leer- und Mehrfach-Upload-Zuständen sichere First-Party-Renderpfade. |
| **2.9.101** | 🔴 fix | Assets/Tabellen | **`CMS/assets/js/admin-site-tables.js` leert Header-, Zeilen- und Spaltenbereiche des Tabellen-Editors jetzt über einen gemeinsamen DOM-Clear-Helper statt über direkte `innerHTML`-Resets**: Der Tabellen-Editor bleibt damit auch im Rebuild-Pfad konsistent bei node-basiertem Rendering. |

---

### v2.9.100 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.100** | 🔴 fix | Admin/Users & Content | **`CMS/admin/modules/users/UsersModule.php` und `CMS/admin/views/partials/featured-image-picker.php` halten Fehlerreport-Quellen sowie den gemeinsamen Featured-Image-Picker jetzt hostneutral auf relativen `/admin/...`- bzw. `/api/media`-Pfaden und rendern Picker-Vorschau/Grid nicht mehr per `innerHTML`**: Benutzer-Reports, Page-/Post-Featured-Image-Auswahl und interne Media-Picker-Roundtrips springen damit auch unter Proxy-, Alternativhost- und lokalen Dev-URLs nicht mehr auf feste Host-Basen oder stringbasiertes DOM-Markup zurück. |
| **2.9.100** | 🔴 fix | Admin/Design & Core | **`CMS/admin/modules/menus/MenuEditorModule.php`, `CMS/core/TableOfContents.php` und `CMS/core/CacheManager.php` nutzen für Menü-Normalisierung, TOC-ID-Erzeugung sowie Cache-Datei-/ETag-Bildung jetzt explizite `mb_*`-/Core-PHP-Fallbacks, `random_bytes()` mit sicherem Hash-Fallback und moderne `hash('sha512', ...)`-/`hash('sha256', ...)`-Pfade statt roher `mbstring`-Abhängigkeiten oder Legacy-`md5()`/`sha1()`**: Menü-Saves bleiben damit auch ohne geladene `mbstring`-Extension stabil, TOC-Navigationsanker hängen nicht mehr an `md5(microtime...)`, und der First-Party-Cachepfad schleppt keine veralteten Hashfunktionen mehr mit. |

---

### v2.9.99 — 11. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.99** | 🔴 fix | Admin/Diagnose & Info | **`CMS/admin/error-report.php`, `CMS/admin/partials/post-action-shell.php`, `CMS/admin/partials/redirect-alias-shell.php`, `CMS/admin/views/partials/flash-alert.php`, `CMS/core/Debug.php`, `CMS/core/Services/ErrorReportService.php` und `CMS/admin/views/system/info.php` halten Fehlerreport-POSTs, Diagnose-Rücksprünge, Report-Buttons und gespeicherte `source_url`-Werte jetzt hostneutral relativ und ziehen fehlende `mbstring`-Fallbacks nach**: Fehlerreports und Debug-/Flash-Trigger springen damit auch unter Proxy-, Alternativhost- und lokalen Dev-URLs nicht mehr unnötig auf feste `SITE_URL`-Hosts, während Diagnose- und Info-Routinen auf kleineren PHP-Setups ohne geladene `mbstring`-Extension nicht mehr schon an einfachen String-Kürzungen oder Badge-Klassifizierungen fatal aussteigen. |
| **2.9.99** | 🔴 fix | Admin/Design | **`CMS/admin/modules/themes/FontManagerModule.php` nutzt für erkannte, installierte und katalogisierte Font-Namen jetzt explizite `mb_strtolower()`-/`strtolower()`-Fallbacks statt roher `mbstring`-Abhängigkeiten**: Theme-Scans, Sammeldownloads und Font-Katalogzustände bleiben damit auch ohne `mbstring` im Font Manager belastbar, statt nach dem bereits gehärteten Entry an späteren Modulpfaden doch noch fatal abzubrechen. |

---

### v2.9.98 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.98** | 🔴 fix | Admin/Routing | **`CMS/admin/post-categories.php`, `CMS/admin/post-tags.php`, `CMS/admin/table-of-contents.php`, `CMS/admin/updates.php`, `CMS/admin/subscription-settings.php`, `CMS/admin/settings.php`, `CMS/admin/packages.php`, `CMS/admin/menu-editor.php`, `CMS/admin/pages.php` und `CMS/admin/views/toc/settings.php` nutzen für Guard-, PRG- und Formularziele jetzt hostneutrale relative Admin-Pfade**: Kategorien-, Tags-, TOC-, System-, Abo-, Menü- und Seiten-Entrys bleiben damit auch unter Proxy-, Alternativhost- und lokalen Dev-URLs im aktuellen Admin-Kontext, statt bei internen Redirects oder dem TOC-Save auf eine feste `SITE_URL`-Origin zu springen. |

---

### v2.9.97 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.97** | 🔴 fix | Admin/Abo & Checkout | **`CMS/admin/modules/subscriptions/OrdersModule.php`, `CMS/core/Services/DashboardService.php`, `CMS/core/SchemaManager.php`, `CMS/admin/orders.php`, `CMS/orders.php`, `CMS/member/includes/class-member-controller.php` und `CMS/member/subscription.php` ziehen den Orders-Vertrag jetzt auf kanonische Status-, Kunden- und Betragssichten zusammen**: Admin-Statuswechsel, Dashboard-KPIs, Checkout-Anlagen, Schema-Nachpflege und Member-Bestellhistorie laufen damit auch auf älteren Installationen mit gemischtem Orders-Schema wieder auf demselben Datenmodell statt zwischen `confirmed/completed`, `total_amount/amount` und `email/customer_email` auseinanderzufallen. |

---

### v2.9.96 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.96** | 🔴 fix | Assets/SEO | **`CMS/assets/js/admin-seo-editor.js` analysiert Page-/Post-Inhalte jetzt bevorzugt strukturiert aus live synchronisiertem Editor.js-JSON statt aus rohem DOM-/String-Fallback und zählt Keyphrases sowie Transition-Wörter Unicode-sicher**: SEO-/Readability-Scores, Absatzlogik, Link- und Bildsignale bleiben damit näher am tatsächlich bearbeiteten Editor.js-Inhalt statt an technischen JSON-Resten oder brüchigen Regex-Grenzen zu hängen. |

---

### v2.9.95 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.95** | 🔴 fix | Assets/SEO | **`CMS/assets/js/admin-seo-editor.js` rendert Score-Regeln jetzt über echte DOM-Knoten statt über `innerHTML` mit aus Slug, Focus-Phrase und Regel-Details zusammengesetzten Strings**: Der gemeinsame SEO-Editor trägt damit im Page-/Post-Admin keinen vermeidbaren DOM-XSS-/Markup-Pfad mehr im Regel-Rendering. |

---

### v2.9.94 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.94** | 🔴 fix | Assets/Editor.js | **`CMS/assets/js/admin-content-editor.js` verdrahtet die gemeinsamen DE-/EN-Editoren jetzt an eine Live-Synchronisierung der Hidden-JSON-Felder**: SEO-/Readability-Panels, Preview-Bridge und Folgeaktionen arbeiten damit im Page-/Post-Admin nicht mehr auf veralteten Editor.js-Daten, solange der sichtbare Editorzustand bereits weiterbearbeitet wurde. |

---

### v2.9.93 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.93** | 🔴 fix | Assets/Editor.js | **`CMS/assets/js/admin-content-editor.js` serialisiert vor `Alles aus DE nach EN kopieren` jetzt auch den EN-Zieleditor und verlangt bei vorhandenem EN-Entwurf eine explizite Bestätigung**: Manuelle DE→EN-Kopien überschreiben damit im Page-/Post-Editor keine bereits bearbeiteten EN-Entwürfe mehr still im Hintergrund. |

---

### v2.9.92 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.92** | 🔴 fix | Assets/Editor.js & AI | **`CMS/assets/js/admin-content-editor.js` serialisiert vor AI-Übersetzungen jetzt ebenfalls den EN-Zielzustand und prüft den Preview-/Overwrite-Pfad gegen den echten aktuellen Entwurf**: Unsichtbare, noch nicht in Hidden-Feldern stehende EN-Änderungen geraten damit nicht mehr in einen scheinbar leeren Zielzustand. |

---

### v2.9.91 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.91** | 🔴 fix | Assets/Editor.js | **`CMS/assets/js/editor-init.js` ergänzt für gemeinsame Editor.js-Instanzen einen debounced `onChange`-Synchronisationspfad**: Shared-Assets können sichtbare Blockänderungen damit direkt in ihren Hidden-/JSON-Vertrag zurückspiegeln, statt erst auf den nächsten Formular-Submit angewiesen zu sein. |

---

### v2.9.90 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.90** | 🔴 fix | Diagnose/Cron | **`CMS/core/Services/CronRunnerService.php` nutzt in `truncateForLog()` jetzt einen expliziten `mb_substr()`-/`substr()`-Fallback**: Cron- und Monitoring-Logs hängen damit beim Kürzen von Lauftexten nicht mehr still an der optionalen `mbstring`-Extension. |

---

### v2.9.89 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.89** | 🔴 fix | Kommentare/Core | **`CMS/core/Services/CommentService.php` nutzt für die E-Mail-Grenze in `createPendingComment()` jetzt ebenfalls den internen UTF-8-Fallback-Helfer statt eines rohen `mb_substr()`-Aufrufs**: Der öffentliche Kommentar-Create-Pfad ist damit in seiner gesamten String-Normalisierung ohne harte `mbstring`-Abhängigkeit konsistent gehärtet. |

---

### v2.9.88 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.88** | 🔴 fix | Admin/Recht | **`CMS/admin/legal-sites.php` nutzt für Profil- und HTML-Grenzen jetzt explizite `mb_substr()`-/`substr()`-Fallbacks**: Der Legal-Sites-Entry hängt damit beim Speichern von Profilen und generierten Rechtstexten nicht mehr still an der optionalen `mbstring`-Extension. |

---

### v2.9.87 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.87** | 🔴 fix | Admin/Info | **`CMS/admin/documentation.php` begrenzt den ausgewählten Dokumentpfad jetzt über einen expliziten `mb_substr()`-/`substr()`-Fallback**: Der Dokumentations-Entry bleibt damit auch auf PHP-Setups ohne geladene `mbstring`-Extension beim normalen Dokumentwechsel lauffähig. |

---

### v2.9.86 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.86** | 🔴 fix | Admin/Design | **`CMS/admin/theme-explorer.php` nutzt für den aktiven Dateipfad jetzt einen `mb_substr()`-/`substr()`-Fallback und leitet Guard-Fälle hostneutral auf `/` zurück**: Theme-Explorer-Dateiwechsel hängen damit nicht mehr still an `mbstring`, und Access-Denied-Fallbacks kippen nicht mehr auf eine feste Origin. |

---

### v2.9.85 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.85** | 🔴 fix | Admin/Plugins | **`CMS/admin/plugin-marketplace.php` nutzt für Install-Slug-Grenzen und Längenprüfungen jetzt explizite `mb_*`-/Core-PHP-Fallbacks**: Der Plugin-Marketplace-Entry bricht damit auf PHP-Setups ohne geladene `mbstring`-Extension nicht mehr schon vor dem eigentlichen Install-Flow fatal ab. |

---

### v2.9.84 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.84** | 🔴 fix | Admin/Design | **`CMS/admin/font-manager.php` nutzt für Google-Font-Familien, Font-Keys und Truncation-Checks jetzt explizite `mb_*`-/Core-PHP-Fallbacks**: Der Font-Manager-Entry hängt damit beim Speichern und beim Google-Font-Download nicht mehr still an der optionalen `mbstring`-Extension. |

---

### v2.9.83 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.83** | 🔴 fix | Admin/Design & Diagnose | **`CMS/admin/modules/themes/FontManagerModule.php` und `CMS/core/Services/CronRunnerService.php` ersetzen ihre verbleibenden Legacy-`sha1()`-/`md5()`-Suffixe jetzt durch `hash('sha256', ...)`**: Self-Hosted-Font-Dateinamen und der Cron-Lock-Namespace hängen damit nicht mehr an veralteten Hashfunktionen. |

---

### v2.9.82 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.82** | 🔴 fix | Security/Kommentare | **`CMS/core/Security.php` ersetzt Legacy-`md5()` im Session-Fallback des Login-/API-Rate-Limits durch `hash('sha256', ...)`, und `CMS/core/Services/CommentService.php` nutzt für den öffentlichen Kommentarpfad jetzt `mb_*`-/Core-PHP-Fallbacks sowie nur noch maskierte E-Mail-Werte statt eines rohen `sha1(email)` im Flood-Log**: Security- und Kommentarpfade hängen damit nicht mehr an veralteten Hashes oder optionaler `mbstring`-Verfügbarkeit. |

---

### v2.9.81 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.81** | 🔴 fix | Admin/System | **`CMS/admin/modules/settings/SettingsModule.php` nutzt für Route-Basen, Audit-String-Kürzung und Testmail-Maskierung jetzt explizite `mb_strtolower()`-/`strtolower()`, `mb_substr()`-/`substr()`- und `mb_strlen()`-/`strlen()`-Fallbacks**: Die allgemeinen Systemeinstellungen hängen damit beim Speichern, beim URL-Migrations-/Audit-Logging und beim Testmail-Flow nicht mehr still an der optionalen `mbstring`-Extension. |

---

### v2.9.80 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.80** | 🔴 fix | Admin/Beiträge | **`CMS/admin/modules/posts/PostsModule.php` nutzt für Slug-Generierung, Slug-Normalisierung und Tag-Kürzungen jetzt explizite `mb_strtolower()`-/`strtolower()`- sowie `mb_substr()`-/`substr()`-Fallbacks**: Der Beiträge-Admin hängt damit beim Speichern, Slug-Aufbereiten und Tag-Normalisieren nicht mehr still an der optionalen `mbstring`-Extension. |

---

### v2.9.79 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.79** | 🔴 fix | Assets/Medien | **`CMS/assets/js/admin-media-integrations.js` und `CMS/assets/js/member-dashboard.js` übernehmen bei fehlgeschlagenen Uploads jetzt ebenfalls das vom Server gelieferte `new_token`**: Schlägt in Admin- oder Member-Medien ein Datei-Upload innerhalb eines Mehrfach-Batches fehl, bleiben Folge-Uploads damit nicht mehr auf einem bereits verbrauchten CSRF-Token hängen. |

---

### v2.9.78 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.78** | 🔴 fix | Admin/Medien | **`CMS/admin/modules/media/MediaModule.php` baut den Root-Breadcrumb `Uploads` der Medienbibliothek jetzt ohne den aktuellen Unterordnerpfad auf**: In verschachtelten Medienordnern springt der erste Breadcrumb damit wieder tatsächlich auf die Bibliothekswurzel zurück, statt nur denselben Unterordner erneut zu laden. |

---

### v2.9.77 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.77** | 🔴 fix | Admin/Beiträge & Grid-Bridge | **`CMS/admin/posts.php` reicht die aktive Beitragslisten-Konfiguration für `cmsGrid()` jetzt hostneutral über `/api/v1/admin/posts` und relative Edit-Links weiter und nutzt für den Guard-Fallback keinen festen `SITE_URL`-Sprung mehr**: Die geladene Post-Liste bleibt damit auch unter Proxy-, Alternativhost- oder lokaler Dev-Umgebung im aktuellen Admin-Kontext. |

---

### v2.9.76 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.76** | 🔴 fix | Assets/Editor.js | **`CMS/assets/js/editor-init.js` sanitisiert den Legacy-HTML→Editor.js-Fallback jetzt vor der Initialisierung auf einen kleinen, URL-geprüften Inline-Subset und verwirft gefährliche Root-Tags**: Bestehende HTML-Inhalte gelangen damit beim Öffnen von Seiten- und Beitragseditoren nicht mehr blind als rohes `innerHTML` in den initialen Editorzustand. |

---

### v2.9.75 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.75** | 🔴 fix | Assets/Editor.js | **`CMS/assets/js/admin-content-editor.js` submitet Seiten- und Beitragsformulare jetzt nur noch nach vollständig erfolgreicher Serialisierung aller aktiven Editor.js-Instanzen**: Scheitert `instance.save()` in einem aktiven DE-/EN-Editor, wird der POST jetzt fail-closed gestoppt und nicht mehr still mit veralteten Hidden-JSON-Daten weitergesendet. |

---

### v2.9.74 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.74** | 🔴 fix | Admin/Hub Assets | **`CMS/assets/js/admin-hub-site-edit.js` und `CMS/assets/js/admin-hub-sites.js` rufen `cmsAlert()` jetzt wieder mit dem echten Vertrag `type, message` auf**: Copy-/Clipboard-Feedback und Browser-Warnungen im Hub-Cluster rendern damit wieder mit korrekter Meldung und Alert-Klasse statt nur mit vertauschten Typ-/Textwerten. |

---

### v2.9.73 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.73** | 🔴 fix | Admin/Hub | **`CMS/admin/modules/hub/HubTemplateProfileManager.php` nutzt für Template-Labels, Metadaten, Vergleichs-Arrays und Starter-Karten jetzt zentrale Kürzungshelper statt roher `mb_substr()`-Aufrufe**: Der Hub-Template-Editor hängt damit beim Laden, Speichern, Kopieren und Vererben von Templates nicht mehr stillschweigend an der optionalen `mbstring`-Extension. |

---

### v2.9.72 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.72** | 🔴 fix | Admin/Hub | **`CMS/admin/modules/hub/HubSitesModule.php` nutzt für Badge-, Titel-, Meta-, Link-, Karten- und Feature-Card-Normalisierung jetzt zentrale Kürzungshelper statt roher `mb_substr()`-Aufrufe**: Der Hub-Admin hängt damit beim Speichern von Hub-Sites und Karten nicht mehr stillschweigend an der optionalen `mbstring`-Extension. |

---

### v2.9.71 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.71** | 🔴 fix | Admin/Sicherheit | **`CMS/admin/modules/security/FirewallModule.php` validiert `block_ua`-Regeln jetzt mit explizitem `mb_strlen()`-/`strlen()`-Fallback statt über ein rohes `mb_strlen()`**: Der Firewall-Admin hängt damit beim Anlegen von User-Agent-Blockregeln nicht mehr stillschweigend an der optionalen `mbstring`-Extension. |

---

### v2.9.70 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.70** | 🔴 fix | Admin/Sicherheit | **`CMS/admin/modules/security/SecurityAuditModule.php` begrenzt Check-, Detail- und Audit-Log-Texte jetzt über `cms_truncate_text()` statt über eine zentrale Routine mit rohen `mb_strlen()`-/`mb_substr()`-Aufrufen**: Das Security-Audit hängt damit beim Rendern längerer Security-Checks und Audit-Logs nicht mehr stillschweigend an der optionalen `mbstring`-Extension. |

---

### v2.9.69 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.69** | 🔴 fix | Admin/Tabellen | **`CMS/admin/modules/tables/TablesModule.php` begrenzt Tabellenbeschreibungen für die Listenansicht jetzt über `cms_truncate_text()` statt über einen rohen `mb_substr()`-Aufruf**: Der Tabellen-Admin hängt damit beim Aufbereiten längerer Beschreibungen nicht mehr stillschweigend an der optionalen `mbstring`-Extension. |

---

### v2.9.68 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.68** | 🔴 fix | Admin/Info | **`CMS/admin/modules/system/DocumentationCatalog.php` und `CMS/admin/modules/system/DocumentationRenderer.php` begrenzen Excerpts, Tabellenzellen und Link-Ziele jetzt über `cms_truncate_text()` statt über rohe `mb_strlen()`-/`mb_substr()`-Aufrufe**: Der Dokumentationsbrowser hängt damit beim Rendern längerer Doku-Inhalte nicht mehr stillschweigend an der optionalen `mbstring`-Extension. |

---

### v2.9.67 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.67** | 🔴 fix | Admin/Diagnose | **`CMS/admin/error-report.php` begrenzt Titel, Nachricht, Fehlercode und JSON-Schlüssel jetzt über `cms_truncate_text()` statt über rohe `mb_substr()`-Aufrufe**: Der Error-Report-Endpunkt hängt damit beim Normalisieren eingehender Fehlerreports nicht mehr stillschweigend an der optionalen `mbstring`-Extension. |

---

### v2.9.66 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.66** | 🔴 fix | Admin/System | **`CMS/admin/views/system/updates.php` kürzt den Core-Changelog im Update-Hinweis jetzt über `cms_truncate_text()` statt über einen rohen `mb_substr()`-Aufruf**: Die Update-Ansicht hängt damit beim Rendern verfügbarer Core-Updates nicht mehr stillschweigend an der optionalen `mbstring`-Extension. |

---

### v2.9.65 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.65** | 🔴 fix | Admin/Kommentare | **`CMS/admin/modules/comments/CommentsModule.php` nutzt für Kommentar-Excerpts jetzt `cms_truncate_text()` statt eines rohen `mb_substr()`-Aufrufs**: Die Kommentar-Moderation hängt damit beim Rendern langer Kommentartexte nicht mehr stillschweigend an der optionalen `mbstring`-Extension. |
| **2.9.65** | 🔴 fix | Admin/Kommentare | **Auch die Author-Initialen der Kommentar-Liste ziehen jetzt über einen expliziten `mb_strtoupper()`-/`strtoupper()`-Fallback hoch**: Avatar-Kürzel bleiben damit auch auf kleineren/shared PHP-Setups ohne geladene `mbstring`-Extension renderbar, statt schon im Listenaufbau fatal auszusteigen. |

---

### v2.9.64 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.64** | 🔴 fix | Admin/Dashboard & Performance | **`CMS/admin/views/dashboard/index.php` und `CMS/admin/views/performance/sessions.php` kürzen Aktivitätsdetails bzw. Session-User-Agents jetzt nicht mehr direkt über `mb_strimwidth()`**: Die beiden Admin-Views hängen damit nicht länger an der optionalen `mbstring`-Extension, obwohl sie nur Anzeige-Kürzungen brauchen. |
| **2.9.64** | 🔴 fix | Core/Helpers | **`CMS/includes/functions/escaping.php` ergänzt dafür mit `cms_truncate_text()` einen zentralen UTF-8-sicheren Kürzungshelfer mit `mb_strimwidth()`-Fallback**: Bei vorhandener `mbstring` bleibt das Verhalten präzise, auf kleineren/shared Setups ohne Extension rendern die betroffenen Admin-Tabellen aber trotzdem robust weiter. |

---

### v2.9.63 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.63** | 🔴 fix | Performance/Settings | **`CMS/admin/modules/seo/PerformanceModule.php` speichert bei `save_media_settings` und `save_session_settings` jetzt nur noch die zum jeweiligen Teilformular gehörenden Setting-Keys**: Session- oder Medien-Saves ziehen damit nicht mehr implizit fremde Boolean-Schalter wie `perf_page_cache`, `perf_browser_cache` oder `perf_auto_clear_content_cache` auf `0`. |
| **2.9.63** | 🔴 fix | Performance/Admin UX | **Teilbereichs-Saves bleiben damit wieder konsistent zum sichtbaren Formularumfang**: Wer nur Medien- oder Session-Einstellungen speichert, verliert keine anderen Performance-Toggles mehr still im Hintergrund. |

---

### v2.9.62 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.62** | 🔴 fix | Security/RBAC | **`CMS/admin/security-audit.php` verlangt jetzt ebenfalls `manage_settings` statt nur generischer Admin-Rechte**: Der sensible Audit-Entry folgt damit wieder demselben Capability-Vertrag wie AntiSpam und Firewall. |
| **2.9.62** | 🔴 fix | Security/Admin UX | **Audit-Läufe und Log-Bereinigung geraten damit nicht mehr in halb offene Zustände für zu breit berechtigte Admin-Rollen**: Nutzer ohne passenden Settings-/Security-Kontext landen gar nicht erst im sichtbaren Security-Audit. |

---

### v2.9.61 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.61** | 🔴 fix | Security/RBAC | **`CMS/admin/antispam.php` und `CMS/admin/firewall.php` prüfen schon am Entry auf `manage_settings` statt nur auf `isAdmin()`**: Die Security-Oberflächen folgen damit wieder demselben Capability-Vertrag wie ihre eigentlichen Mutationspfade. |
| **2.9.61** | 🔴 fix | Security/Admin UX | **AntiSpam- und Firewall-Konfiguration geraten dadurch nicht mehr in halb offene Zustände mit späteren Berechtigungsabbrüchen beim Speichern, Löschen oder Regeln-Togglen**: Nutzer ohne passende Capability landen gar nicht erst in sichtbaren Security-Admins mit schreibgesperrten Folgeroutinen. |

---

### v2.9.60 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.60** | 🔴 fix | SEO/Admin Guards | **`CMS/admin/redirect-manager.php` und `CMS/admin/not-found-monitor.php` nutzen für Access-Denied-Fallbacks jetzt den hostneutralen Root-Pfad `/` statt eines an `SITE_URL` gebundenen Redirects**: Guard-Abbrüche bleiben damit auf der aktuellen Origin, obwohl kein externer Zielhost nötig ist. |
| **2.9.60** | 🔴 fix | SEO/Routing | **Fehlende Berechtigungen im Redirect- und 404-Admin springen dadurch auch unter Proxy-, Alternativhost- oder lokalen Dev-URLs nicht mehr auf eine falsche Hostbasis**: Die SEO-Entries folgen damit auch im Guard-Fallback demselben hostneutralen Redirect-Muster wie andere nachgezogene Admin-Bereiche. |

---

### v2.9.59 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.59** | 🔴 fix | Frontend/Landing | **`CMS/themes/cms-default/home.php` und `CMS/themes/cms-default/partials/home-landing.php` rendern Landing-Hintergrund und Logo jetzt über explizite Safe-URL-Variablen und separate Bild-Layer statt über dynamisch zusammengesetzte CSS-`url(...)`-Fragmente**: Der Hero-/Branding-Pfad bleibt damit robuster zwischen Template, Runtime und Sicherheitsprüfung. |
| **2.9.59** | 🔴 fix | Frontend/Asset-Pfade | **Interne Landing-Bildpfade bleiben in beiden Templates hostneutral root-relativ, während absolute Bild-URLs weiterhin über die gemeinsamen Escape-Helfer sanitisiert werden**: Landing-Hintergründe und Logos bleiben damit same-origin-freundlich, ohne externe Bildziele ungefiltert zu übernehmen. |

---

### v2.9.58 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.58** | 🔴 fix | Landing/Frontend Runtime | **`CMS/core/Services/Landing/LandingHeaderService.php` und `CMS/core/Services/Landing/LandingDefaultsProvider.php` führen `bg_image` wieder als echten Header-Vertragswert**: Das im Landing-Admin gespeicherte Hero-Hintergrundbild fällt damit nicht länger still aus dem Service-/Frontend-Pfad heraus. |
| **2.9.58** | 🔴 fix | Frontend/Landing | **`CMS/themes/cms-default/home.php` und `CMS/themes/cms-default/partials/home-landing.php` rendern das konfigurierte Landing-Hintergrundbild jetzt als echten Hero-Hintergrund über die aktuelle Runtime-URL**: Interne Assetpfade und externe Bilder wirken damit im Live-Frontend wieder tatsächlich statt nur in der Datenbank zu landen. |
| **2.9.58** | 🔴 fix | Frontend/Admin-Hinweise | **Die Landing-Leerzustände verlinken die Konfiguration jetzt hostneutral auf `/admin/landing-page` bzw. `/admin/landing-page?tab=content` statt auf `SITE_URL`-gebundene oder veraltete `/admin/landing-page.php`-Pfade**: Interne Admin-Hinweise bleiben damit auch unter Proxy-, Alternativhost- und lokalen Dev-URLs auf der aktuellen Origin. |

---

### v2.9.57 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.57** | 🔴 fix | Admin/Font Manager | **`CMS/admin/modules/themes/FontManagerModule.php` und `CMS/core/Bootstrap.php` leiten lokal gespeicherte Font-Slugs jetzt auch ohne externen Hook-Provider direkt aus den gespeicherten Font-Manager-Settings in den Frontend-Ladepfad durch**: Aktivierte lokale Fonts verlassen sich damit nicht mehr auf einen leeren `local_font_slugs`-Filter. |
| **2.9.57** | 🔴 fix | Frontend/Typografie | **`CMS/core/ThemeManager.php` setzt `font_body`, `font_heading`, `font_size_base` und `font_line_height` aus dem Font Manager jetzt als echte Runtime-CSS um**: Die gewählten Schriftfamilien, Größen und Zeilenhöhen wirken damit im Live-Frontend statt nur als gespeicherte Admin-Einstellung. |

---

### v2.9.56 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.56** | 🔴 fix | Admin/Font Manager | **`CMS/admin/modules/themes/FontManagerModule.php` schreibt ersetzte `url(...)`-Ziele im lokal generierten Google-Font-CSS jetzt hostneutral relativ statt als `SITE_URL`-absolute Upload-Pfade**: Self-hosted Font-Dateien bleiben damit direkt an ihrer lokalen CSS-Datei ausgerichtet, statt einen festen Host zu konservieren. |
| **2.9.56** | 🔴 fix | Frontend/Fonts Runtime | **`CMS/core/Bootstrap.php` löst gespeicherte lokale Font-CSS-Dateien jetzt zur aktuellen Runtime-URL auf statt starr über `SITE_URL`**: Proxy-, Alternativhost- und lokale Dev-Umgebungen laden den Self-Hosting-Fontpfad damit nicht mehr versehentlich von einer falschen Origin. |

---

### v2.9.55 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.55** | 🔴 fix | Admin/Menü Editor | **`CMS/admin/views/menus/editor.php` nutzt für interne Menüwechsel und den Bearbeiten-Sprung aus der Theme-Positionsliste jetzt hostneutrale relative Admin-Pfade**: Die View koppelt reine Admin-Navigation damit nicht länger an `SITE_URL`. |
| **2.9.55** | 🔴 fix | Admin/Routing | **Menülisten- und Theme-Positions-Klicks bleiben dadurch auch unter Proxy-, Alternativhost- oder lokalen Dev-URLs im aktiven Admin-Kontext**: Interne Wechsel springen nicht mehr auf eine potenziell falsche Origin, obwohl keine externe Navigation nötig ist. |

---

### v2.9.54 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.54** | 🔴 fix | Admin/Theme Explorer | **`CMS/admin/modules/themes/ThemeEditorModule.php` blockiert Explorer-Dateien jetzt auch dann fail-closed, wenn der angefragte Pfad intern über einen Symlink läuft**: Formal saubere `?file=`-Aliase können damit nicht mehr auf andere Theme-Ziele auflösen, die der Explorer bewusst nicht direkt freigibt. |
| **2.9.54** | 🔴 fix | Themes/Sicherheitsgrenzen | **Aufgelöste Theme-Explorer-Ziele werden nach `realpath()` erneut gegen Hidden- und Skip-Segmente geprüft**: Aliaspfade können intern ausgeschlossene Bereiche wie `vendor/` damit nicht mehr indirekt les- oder speicherbar machen, obwohl Dateibaum und Warnhinweise diese Segmente bewusst überspringen. |
---

### v2.9.53 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.53** | 🔴 fix | Admin/Theme Editor | **`CMS/admin/theme-editor.php` prüft eingebettete Theme-Customizer jetzt vor dem Laden auf parsebare PHP-Syntax und blockierte Risko-Funktionen**: Ein bloßer Pfadtreffer genügt damit nicht mehr, um einen defekten oder riskanten `admin/customizer.php` direkt im Admin-Kontext auszuführen. |
| **2.9.53** | 🔴 fix | Design/Safety Fallback | **Der Theme-Editor fällt bei fehlerhaften oder unsicheren Customizer-Dateien gezielt auf die sichere Fallback-View zurück**: Parse-Fehler oder Funktionsaufrufe wie `eval`, `exec` oder `shell_exec` reißen die Route damit nicht mehr fatal aus dem Admin. |

---

### v2.9.52 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.52** | 🔴 fix | Admin/Theme Marketplace | **`CMS/admin/modules/themes/ThemeMarketplaceModule.php` validiert installierte Marketplace-Pakete jetzt wieder themespezifisch nach dem generischen Download-/Install-Schritt**: Erfolgsrückmeldungen bleiben damit nicht mehr auf ZIPs sitzen, die nur einen Repo-Wrapper oder keine direkt aktivierbare Theme-Struktur in `CMS/themes/<slug>/` hinterlassen. |
| **2.9.52** | 🔴 fix | Themes/Runtime | **Verschachtelte gültige Theme-Wurzeln werden nach der Installation in den echten Laufzeitordner hochgezogen**: Enthält ein Marketplace-Archiv das Theme nicht direkt an der Paketwurzel, wird die gültige Theme-Struktur jetzt nach `CMS/themes/<slug>/` promoted, statt als unbrauchbarer Wrapper installiert zu bleiben. |

---

### v2.9.51 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.51** | 🔴 fix | System/AI Services | **`CMS/core/Services/AI/AiSettingsService.php` kanonisiert Editor.js-Blocktypen für AI-Übersetzungen jetzt explizit auf die Runtime-Namen der Pipeline**: Gespeicherte oder per CSV geladene Werte wie `mediatext` landen damit wieder korrekt auf `mediaText`, statt trotz erweitertem Default still aus dem exakten Block-Match zu fallen. |
| **2.9.51** | 🔴 fix | Admin/AI-Translate-EditorJS | **`CMS/admin/modules/system/AiServicesModule.php` nutzt für `supported_block_types` denselben kanonischen Save-Pfad statt generischer Kleinschreibung**: Leere oder manuell gepflegte Translation-Settings halten damit den realen Editor.js-Vertrag inklusive `mediaText` auch nach dem Speichern stabil ein. |

---

### v2.9.50 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.50** | 🔴 fix | System/AI Services | **`CMS/core/Services/AI/AiSettingsService.php` erweitert den Default-Vertrag für Editor.js-AI-Übersetzungen jetzt um `warning` und `mediaText` und kanonisiert gespeicherte Blocktypen pipeline-kompatibel**: Frische oder zurückgesetzte Translation-Profile starten damit nicht länger mit einem zu kleinen Blocktyp-Satz, und `mediaText` kippt im Settings-Pfad nicht mehr still zu `mediatext`. |
| **2.9.50** | 🔴 fix | Admin/AI-Translate-EditorJS | **`CMS/admin/modules/system/AiServicesModule.php` zieht denselben erweiterten Fallback auch im Save-Pfad für leere `supported_block_types` nach**: Nach leer gespeicherten Translation-Einstellungen fallen Warnboxen und Medien-Text-Blöcke damit nicht mehr still aus dem DE→EN-/AI-Flow heraus oder scheitern an einer unpassenden Blocktyp-Schreibweise. |
---

### v2.9.49 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.49** | 🔴 fix | Assets/Editor.js | **`CMS/assets/js/admin-content-editor.js` invalidiert offene AI-Preview-/Diff-Karten jetzt automatisch, sobald sich der EN-Zielzustand manuell ändert**: Stale Vorschläge können damit nicht mehr später per `Übernehmen` frisch bearbeitete EN-Felder oder EN-Editorinhalte wieder überschreiben. |
---

### v2.9.48 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.48** | 🔴 fix | Assets/Theme Explorer | **`CMS/assets/js/admin-theme-explorer.js` verhindert Wiederholungs-Submits des Explorer-Formulars jetzt explizit via `event.preventDefault()`**: Theme-Dateisaves bleiben damit auch bei schnellen Mehrfachauslösern fail-closed auf genau einen Request begrenzt, statt trotz laufendem Pending-State noch einen zweiten nativen POST auszulösen. |

---

### v2.9.47 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.47** | 🔴 fix | Admin/Theme Marketplace | **`CMS/admin/modules/themes/ThemeMarketplaceModule.php` prüft in `normalizeCatalogString()` jetzt erst auf `mb_substr()` und fällt andernfalls auf `substr()` zurück**: Theme-Katalog und Manifest-Felder lassen sich damit auch auf PHP-Setups ohne geladene `mbstring`-Extension weiter normalisieren, statt den Theme-Marketplace fatal abbrechen zu lassen. |
| **2.9.47** | 🔴 fix | Assets/Marketplace | **`CMS/admin/views/themes/marketplace.php` baut den suchbaren `data-name`-Vertrag für `CMS/assets/js/admin-theme-marketplace.js` jetzt mit `mb_strtolower()`-Prüfung und `strtolower()`-Fallback**: Kartenrendering und Suchfilter bleiben damit auch ohne `mbstring` lauffähig, statt schon beim Rendern des Filterdatensatzes auszufallen. |

---

### v2.9.46 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.46** | 🔴 fix | Admin/Plugin Marketplace | **`CMS/admin/modules/plugins/PluginMarketplaceModule.php` prüft in `normalizeCatalogString()` jetzt erst auf `mb_substr()` und fällt andernfalls auf `substr()` zurück**: Registry- und Manifest-Felder lassen sich damit auch auf PHP-Setups ohne geladene `mbstring`-Extension weiter normalisieren, statt den Marketplace-Katalog fatal abzubrechen. |

---

### v2.9.45 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.45** | 🔴 fix | Admin/Kommentare | **`CMS/admin/modules/comments/CommentsModule.php` und `CMS/admin/views/comments/list.php` nutzen für Status-Tabs jetzt hostneutrale relative Admin-Pfade**: Kommentar-Moderation bleibt damit auch unter Proxy-, Alternativhost- und lokalen Dev-URLs im aktuellen Admin-Kontext, statt auf eine an `SITE_URL` gebundene Origin zu springen. |
| **2.9.45** | 🔴 fix | Admin/Plugins | **`CMS/admin/views/plugins/list.php` nutzt für den internen Sprung `Plugin installieren` jetzt die hostneutrale relative Admin-Route `/admin/plugin-marketplace`**: Die Plugin-Liste wechselt damit auch in alternativen Host-Setups sauber im aktuellen Admin-Kontext in den Marketplace. |
| **2.9.45** | 🔴 fix | Assets/Grid | **`CMS/assets/js/admin-grid.js` nutzt für generische Grid.js-Requests jetzt die hostneutrale relative Basis `/api/v1/admin/` statt `CMS_SITE_URL`/`SITE_URL`**: Search-, Sortier- und Paging-Requests bleiben damit auch unter Proxy-, Alternativhost- und lokalen Dev-URLs auf derselben Origin. |

---

### v2.9.44 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.44** | 🔴 fix | Admin/Tabellen | **`CMS/admin/site-tables.php` sowie `CMS/admin/views/tables/list.php`, `edit.php` und `settings.php` nutzen für Guard-/PRG-Redirects, interne Listen-/Edit-/Settings-Wechsel und Form-Roundtrips jetzt hostneutrale relative Admin-Pfade**: Tabellenliste, Editor und Einstellungen bleiben damit auch unter Proxy-, Alternativhost- und lokalen Dev-URLs im aktuellen Admin-Kontext. |
| **2.9.44** | 🔴 fix | Assets/Tabellen | **`CMS/admin/views/tables/list.php` reicht die Such-Basis für `CMS/assets/js/admin-site-tables.js` jetzt hostneutral über `/admin/site-tables` weiter**: Die Search-Bridge übernimmt damit keine falsche `SITE_URL`-Basis mehr, obwohl das Asset selbst nur den DOM-Vertrag konsumiert. |

---

### v2.9.43 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.43** | 🔴 fix | Admin/Hub | **`CMS/admin/hub-sites.php` sowie `CMS/admin/views/hub/list.php`, `edit.php`, `templates.php` und `template-edit.php` nutzen für Access-Fallback, interne Admin-Wechsel und Form-Roundtrips jetzt hostneutrale relative Admin-Pfade**: Hub-Liste, Template-Wechsel und Editor-Saves bleiben damit auch unter Proxy-, Alternativhost- und lokalen Dev-URLs im aktuellen Admin-Kontext. |
| **2.9.43** | 🔴 fix | Assets/Hub | **`CMS/assets/js/admin-hub-sites.js` und `CMS/assets/js/admin-hub-site-edit.js` lösen Public-Pfade jetzt gegen die aktuelle Browser-Origin auf und schicken `Speichern & Public Site öffnen` wieder über den vorbereiteten Submit-Pfad**: Clipboard-/Open-Flows übernehmen damit keine falsche `SITE_URL`-Basis mehr, und Rich-Text-/Kachel-Inhalte gehen vor dem Spezial-Save nicht mehr verloren. |

---

### v2.9.42 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.42** | 🔴 fix | Admin/System | **`CMS/admin/views/system/mail-settings.php` und `CMS/admin/views/system/ai-services.php` nutzen für interne Tab-Basen jetzt hostneutrale relative Admin-Pfade**: System-Tabs bleiben damit auch unter Proxy-, Alternativhost- und lokalen Dev-URLs im aktuellen Admin-Kontext, statt auf eine an `SITE_URL` gebundene Origin zu springen. |
| **2.9.42** | 🔴 fix | Admin/System API | **`CMS/admin/views/system/mail-settings.php` reicht die interne Mail-Logs-API jetzt hostneutral über `/api/v1/admin/mail/logs` weiter**: Der Mail-Logs-Vertrag bleibt damit auch in alternativen Host-Setups auf derselben Origin. |

---

### v2.9.41 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.41** | 🔴 fix | Admin/Dashboard | **`CMS/core/Services/DashboardService.php` und `CMS/admin/views/dashboard/index.php` nutzen für Quicklinks, KPI-/Highlight-Karten, Attention-Items und Warnungs-Sprünge jetzt hostneutrale relative Admin-Pfade**: Dashboard-Sprünge bleiben damit auch unter Proxy-, Alternativhost- und lokalen Dev-URLs im aktuellen Admin-Kontext, statt auf eine an `SITE_URL` gebundene Origin zu springen. |
| **2.9.41** | 🔴 fix | Admin/Sicherheit | **`CMS/admin/security-audit.php` nutzt für Guard-Fallback und PRG-Roundtrip jetzt hostneutrale relative Redirect-Ziele**: Der Security-Audit-Entry bleibt damit auch unter Proxy-, Alternativhost- und lokalen Dev-URLs im aktuellen Admin-Kontext. |
| **2.9.41** | 🔴 fix | Admin/Recht | **`CMS/admin/views/legal/cookies.php` öffnet die öffentliche Consent-Seite jetzt hostneutral über `/cookie-einstellungen` statt über eine an `SITE_URL` gebundene Host-Basis**: Der Cookie-Manager-Querlink bleibt damit auch in alternativen Host-Setups auf dem aktuellen Kontext. |

---

### v2.9.40 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.40** | 🔴 fix | Admin/Users | **`CMS/admin/user-settings.php`, `CMS/admin/roles.php` und `CMS/admin/views/users/settings.php` nutzen für Guard-/PRG-Redirects sowie den internen Member-Dashboard-Querlink jetzt hostneutrale relative Admin-Pfade**: User-Settings, Rollen-POSTs und interne Benutzer-Querlinks bleiben damit auch unter Proxy-, Alternativhost- und lokalen Dev-URLs im aktuellen Admin-Kontext, statt auf eine an `SITE_URL` gebundene Origin zu springen. |
| **2.9.40** | 🔴 fix | Admin/Groups | **`CMS/admin/views/users/groups.php` nutzt für Modal- und Delete-Mutationen jetzt Same-Route-POSTs statt eines harten `SITE_URL`-Action-Ziels**: Gruppen-Erstellen, Bearbeiten und Löschen bleiben damit im aktuellen Admin-Kontext, statt in alternativen Host-Setups auf eine falsche Origin zu kippen. |

---

### v2.9.39 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.39** | 🔴 fix | Admin/Content | **`CMS/admin/views/pages/list.php`, `CMS/admin/views/pages/edit.php`, `CMS/admin/views/posts/list.php`, `CMS/admin/views/posts/edit.php` und `CMS/admin/views/posts/categories.php` nutzen für interne Listen-, Edit-, Kategorie- und Zurück-Wechsel jetzt hostneutrale relative Admin-Routen**: Content-Wechsel bleiben damit auch unter Proxy-, Alternativhost- und lokalen Dev-URLs im aktuellen Admin-Kontext, statt auf eine an `SITE_URL` gebundene Origin zu springen. |
| **2.9.39** | 🔴 fix | Assets/EditorJS | **`CMS/admin/views/pages/edit.php` und `CMS/admin/views/posts/edit.php` reichen die Editor.js-Preview-, Media- und AI-Fallback-Ziele jetzt hostneutral relativ an `CMS/assets/js/admin-content-editor.js` weiter**: Die Editor-Brücke bleibt damit auch bei Fallback-Konfigurationen im aktuellen Kontext, statt auf eine feste Host-Basis zu kippen. |

---

### v2.9.38 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.38** | 🔴 fix | Admin/Member | **`CMS/admin/views/member/subnav.php`, `CMS/admin/views/member/general.php` und `CMS/admin/member-dashboard-page.php` nutzen für Subtabs, den Querhinweis zu `Benutzer & Gruppen → Einstellungen` und den Wrapper-Fallback jetzt hostneutrale relative Admin-Pfade**: Member-Wechsel bleiben damit auch unter Proxy-, Alternativhost- und lokalen Dev-URLs im aktuellen Admin-Kontext, statt auf eine an `SITE_URL` gebundene Origin zu springen. |

---

### v2.9.37 — 10. April 2026
|---------|-----|---------|-------------|
| **2.9.37** | 🔴 fix | Admin/Media | **`CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php` und `CMS/admin/views/media/library.php` nutzen für interne Bibliotheks-Redirects, Browse-Ziele und den nativen Upload-Endpunkt jetzt hostneutrale relative Pfade**: Mediennavigation und Upload-Queue bleiben damit auch unter Proxy-, Alternativhost- und lokalen Dev-URLs im aktuellen Admin-Kontext, statt auf eine an `SITE_URL` gebundene Origin zu springen. |
| **2.9.37** | 🔴 fix | Member/Media | **`CMS/member/media.php` reicht Upload-Endpunkt, Breadcrumbs und Ordnerwechsel ebenfalls hostneutral über relative interne Ziele weiter**: Der Member-Medienbereich folgt damit demselben Medien-/API-Vertrag wie der Admin und kippt bei Dateiwechseln oder Uploads nicht mehr auf eine harte Host-Basis. |

---

### v2.9.36 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.36** | 🔴 fix | Admin/Diagnose | **`CMS/admin/views/system/subnav.php`, `CMS/admin/views/system/diagnose.php` und `CMS/admin/views/system/cms-logs.php` nutzen für Info-, Dokumentations-, Diagnose-, Monitoring- und Log-Wechsel jetzt hostneutrale relative Admin-Routen**: System-Subnav, `CMS Logs öffnen` und Log-Dateiauswahl springen damit nicht mehr auf eine an `SITE_URL` gebundene Origin. |

---

### v2.9.35 — 10. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.35** | 🔴 fix | Admin/Info | **`CMS/admin/partials/redirect-alias-shell.php`, `CMS/admin/support.php`, `CMS/admin/system-info.php`, `CMS/admin/documentation.php` und `CMS/admin/modules/system/DocumentationModule.php` halten Alias-Weiterleitungen, Dokumentwechsel und den Sprung in `CMS Logs` jetzt hostneutral auf relativen Admin-Pfaden**: Der Info-/Dokublock bleibt damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung sauber im aktuellen Admin-Kontext. |
| **2.9.35** | 🔴 fix | Assets/EditorJS | **`CMS/admin/pages.php` und `CMS/admin/posts.php` reichen den geschützten AI-Übersetzungsendpunkt für `CMS/assets/js/admin-content-editor.js` jetzt als relativen Pfad `/admin/ai-translate-editorjs` weiter**: DE→EN-Kopie, AI-Preview und Diff-/Übernahme-Flow springen damit nicht mehr auf eine an `SITE_URL` gebundene Origin. |

---

### v2.9.34 — 09. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.34** | 🔴 fix | Admin/System | **`CMS/admin/views/settings/general.php` nutzt für interne Querlinks zu `Mail & Azure OAuth2`, `Benutzer & Gruppen → Einstellungen` sowie für den Media-Picker-Endpunkt jetzt hostneutrale relative Pfade statt an `SITE_URL` gebundener Ziele**: Der zentrale Settings-Screen bleibt damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung sauber im aktuellen Admin-Kontext. |

---

### v2.9.33 — 09. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.33** | 🔴 fix | Admin/System | **`CMS/admin/modules/settings/SettingsModule.php`, `CMS/install/InstallerService.php` und `CMS/config/app.php` entfernen den veralteten Schattenwert `SESSIONS_LIFETIME` aus neuen und aktualisierten Konfigurationsdateien**: `config/app.php` erzeugt damit keine zweite, wirkungslose Session-Wahrheit mehr neben den real verwendeten Performance-Timeouts. |

---

### v2.9.32 — 09. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.32** | 🔴 fix | Core/Auth | **`CMS/core/Auth.php` richtet Session-Prüfung und Remember-Me-Cookie jetzt an den im Performance-Admin gespeicherten Admin-/Member-Timeouts aus statt an fest eingebauten 8h/30-Tage-Werten**: Session-Einstellungen wirken damit nicht länger nur im UI und im Wartungspfad, sondern auch in der echten Auth-Laufzeit. |
| **2.9.32** | 🔴 fix | Core/Bootstrap | **`CMS/index.php` setzt `session.gc_maxlifetime` vor `session_start()` jetzt auf den höchsten konfigurierten Session-Timeout**: aktive PHP-Sessions laufen damit nicht mehr schon am Server-Default aus, obwohl im Performance-Admin bewusst längere Admin-/Member-Lifetimes konfiguriert sind. |

---

### v2.9.31 — 09. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.31** | 🔴 fix | Admin/Performance | **`CMS/admin/modules/seo/PerformanceModule.php` richtet die Bereinigung von Dateisessions jetzt nach den konfigurierten Admin-/Member-Timeouts aus, statt starr einen 24h-Schwellenwert zu verwenden**: Session-Cleanup löscht damit bei längeren konfigurierten Laufzeiten keine noch gültigen Dateisessions mehr vorzeitig weg. |

---

### v2.9.30 — 09. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.30** | 🔴 fix | Admin/Performance | **`CMS/admin/views/performance/settings.php` und `CMS/admin/views/performance/sessions.php` spiegeln die serverseitigen Schutzgrenzen für Browser-/HTML-TTL sowie Admin-/Member-Timeouts jetzt direkt im Formular**: Performance-Einstellungen lassen damit im Browser nicht länger Werte außerhalb der von `PerformanceModule::saveSettings()` erzwungenen Min-/Max-Bereiche zu. |

---

### v2.9.29 — 09. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.29** | 🔴 fix | Admin/Performance | **`CMS/admin/views/performance/cache.php`, `CMS/admin/views/performance/database.php`, `CMS/admin/views/performance/media.php` und `CMS/admin/views/performance/sessions.php` hängen destruktive Wartungsaktionen jetzt wieder an den gemeinsamen Confirm-Vertrag aus `admin.js`**: Cache-Leeren, OPcache-Reset, Tabellenwartung, WebP-Massenkonvertierung und Session-Bereinigung laufen damit nicht mehr ohne vorgeschaltete Bestätigung sofort auf dem Live-Bestand an. |

---

### v2.9.28 — 09. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.28** | 🔴 fix | Admin/Performance | **`CMS/admin/views/performance/settings.php` nutzt für den internen Hinweis zum `Font Manager` jetzt eine hostneutrale relative Admin-Route statt eines an `SITE_URL` gebundenen Links**: Der Wechsel aus den Performance-Einstellungen in den Font-Manager bleibt damit auch unter Proxy-, Alternativhost- und lokalen Umgebungen im aktuellen Admin-Kontext. |

---

### v2.9.27 — 09. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.27** | 🔴 fix | Admin/Performance | **`CMS/admin/views/seo/performance.php` und `CMS/admin/views/performance/subnav.php` nutzen für Übersicht und Unterbereiche jetzt hostneutrale relative Admin-Routen statt an `SITE_URL` gebundener Links**: Wechsel in Cache-, Medien-, Datenbank-, Settings- und Session-Unterbereiche bleiben damit auch unter Proxy-, Alternativhost- und lokalen Umgebungen im aktuellen Admin-Kontext. |

---

### v2.9.26 — 09. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.26** | 🔴 fix | Admin/SEO | **`CMS/admin/views/seo/dashboard.php` nutzt für die Schnellzugriffe auf Audit-, Meta-, Social-, Schema-, Sitemap- und Technical-SEO jetzt hostneutrale relative Admin-Routen statt an `SITE_URL` gebundener Links**: Der Wechsel aus dem SEO-Dashboard in die Unterbereiche bleibt damit auch unter Proxy-, Alternativhost- und lokalen Umgebungen im aktuellen Admin-Kontext. |

---

### v2.9.25 — 09. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.25** | 🔴 fix | Admin/SEO | **`CMS/admin/redirect-manager.php`, `CMS/admin/not-found-monitor.php`, `CMS/admin/views/seo/not-found.php` und `CMS/admin/views/seo/subnav.php` nutzen für interne SEO-Admin-Wechsel, Quick-Actions und PRG-Rücksprünge jetzt hostneutrale relative Admin-Routen statt `SITE_URL`-gebundener Ziele**: Redirect-Manager, 404-Monitor und der SEO-Tab-Wechsel bleiben damit auch unter Proxy-, Alternativhost- und lokalen Umgebungen im aktuellen Admin-Kontext. |
| **2.9.25** | 🔴 fix | Admin/SEO | **`CMS/admin/views/seo/redirects.php` hängt die globalen Confirm-Metadaten wieder an die echten Löschpfade statt versehentlich an Quick-Save und Aktivieren/Deaktivieren**: Redirect-Erstellung löst dadurch keinen falschen Löschdialog mehr aus, während Einzel-Löschen und Slug-Massenlöschen wieder zuverlässig über den gemeinsamen Confirm-Vertrag aus `admin.js` bestätigt werden. |

---

### v2.9.24 — 09. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.24** | 🔴 fix | Assets/SEO | **`CMS/assets/js/admin-seo-redirects.js` entfernt seinen lokalen Confirm-Submit-Wrapper für `form[data-confirm-message]` und verlässt sich für Redirect-Löschungen sowie 404-Cleanup wieder vollständig auf den gemeinsamen Admin-Confirm-Vertrag aus `admin.js`**: Redirect-Manager und 404-Monitor geraten dadurch nicht länger in doppelte Confirm-/Re-Submit-Pfade zwischen Shared- und SEO-Asset. |

---

### v2.9.23 — 09. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.23** | 🔴 fix | Admin/Plugins | **`CMS/admin/views/plugins/marketplace.php` nutzt für den Rücksprung `Installierte Plugins` jetzt die hostneutrale relative Admin-Route `/admin/plugins` statt eines `SITE_URL`-gebundenen Links**: Der Wechsel aus dem Plugin-Marketplace zurück in die Plugin-Liste bleibt damit auch unter Proxy-, Alternativhost- und lokalen Umgebungen im aktuellen Admin-Kontext. |

---

### v2.9.22 — 09. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.22** | 🔴 fix | Assets/Marketplace | **`CMS/assets/js/admin-theme-marketplace.js` und `CMS/assets/js/admin-plugin-marketplace.js` erkennen bestätigte Install-Submits jetzt über den real freigegebenen Browser-Submit statt über das vom globalen Confirm-Handler bereits zurückgesetzte `confirmAccepted`-Flag**: Theme- und Plugin-Marketplace ziehen ihren Pending-State damit nach bestätigten Installationen wieder zuverlässig nach und sperren Doppel-Submits belastbar ab. |

---

### v2.9.21 — 09. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.21** | 🔴 fix | Admin/Design | **`CMS/admin/theme-editor.php` und `CMS/admin/views/themes/customizer-missing.php` halten die sicheren Ausweichlinks des Theme-Editor-Fallbacks jetzt hostneutral über relative Admin-Routen**: Theme-Verwaltung und Theme-Explorer bleiben damit auch dann im aktuellen Admin-Kontext, wenn ein Theme keinen ladbaren Customizer bereitstellt. |

---

### v2.9.20 — 09. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.20** | 🔴 fix | Admin/Themes | **`CMS/admin/views/themes/list.php` verlinkt `Editor` und `Explorer` für das aktive Theme jetzt hostneutral über relative Admin-Routen statt über `SITE_URL`-gebundene Ziele**: Die Sprünge aus der Theme-Verwaltung bleiben damit auch unter Proxy-, Alternativhost- und lokalen Umgebungen im aktuellen Admin-Kontext. |

---

### v2.9.19 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.19** | 🔴 fix | Admin/Design | **`CMS/admin/views/themes/editor.php` nutzt für Theme-Explorer-Dateiklicks jetzt eine hostneutrale relative Explorer-Basis statt einer `SITE_URL`-verketteten Route**: Dateiwechsel bleiben damit auch unter Proxy-, Alternativhost- und lokalen Umgebungen im aktuellen Admin-Kontext. |

---

### v2.9.18 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.18** | 🔴 fix | Admin/Auth | **`CMS/core/Services/CmsAuthPageService.php` speichert interne Loginpage-Logo-Pfade jetzt hostneutral statt sie auf `SITE_URL` zu verabsolutieren**: Auth-Logos bleiben damit auch unter Proxy-, Alternativhost- und lokalen Umgebungen auf der aktuellen Origin erreichbar. |

---

### v2.9.17 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.17** | 🔴 fix | Admin/Landing | **`CMS/admin/views/landing/page.php` nutzt für die Landing-Tab-Navigation jetzt die vorbereiteten relativen Admin-Routen direkt statt `SITE_URL`-verketteter Links**: Header-, Content-, Footer-, Design- und Plugin-Tabs bleiben damit auch unter Proxy-, Alternativhost- und lokalen Umgebungen im aktuellen Admin-Kontext. |

---

### v2.9.16 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.16** | 🔴 fix | Admin/Landing | **`CMS/admin/modules/landing/LandingPageModule.php` reicht im Design-Tab wieder sowohl `design` als auch `colors` an die View durch**: Gespeicherte Landing-Farbwerte erscheinen damit nach Reload im Admin wieder korrekt, statt fälschlich auf die Fallback-Defaults zu kippen. |
---

### v2.9.15 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.15** | 🔴 fix | Admin/Design | **`CMS/admin/views/themes/cms-loginpage.php` nutzt für das Admin-Formular der CMS Loginpage jetzt einen hostneutralen Same-Route-POST statt eines harten `SITE_URL`-Ziels**: Save-Requests bleiben damit auch unter Proxy-, Alternativhost- und lokalen Umgebungen im aktuellen Admin-Kontext, statt auf einen abweichenden Host zu springen. |

---

### v2.9.14 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.14** | 🔴 fix | Admin/Design | **`CMS/admin/views/themes/cms-loginpage.php` rendert Shell-basierte Flash- und Fehlermeldungen jetzt wieder sichtbar**: Save-Erfolge, CSRF-Ablehnungen und Validierungsfehler der CMS Loginpage verschwinden damit nach dem Redirect nicht länger stumm, sondern landen wieder direkt am Formular. |

---

### v2.9.13 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.13** | 🔴 fix | Assets/EditorJS | **`CMS/assets/js/admin-content-editor.js` blockt doppelte Submit-Auslöser in den Page- und Post-Editoren jetzt explizit**: Während die laufende Editor.js-Serialisierung noch aktiv ist, fallen weitere Save-Auslöser nicht mehr ohne `preventDefault()` in einen zusätzlichen nativen Browser-POST zurück. |

---

### v2.9.12 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.12** | 🔴 fix | Admin/Design | **`CMS/admin/modules/landing/LandingPageModule.php` liefert Design- und Farbdaten für den Landing-Page-Tab `design` jetzt wieder im von der View erwarteten Format aus**: Gespeicherte Hero-, Karten-, Footer- und Content-Designwerte erscheinen damit nach dem Reload im Admin wieder korrekt, statt fälschlich wie verlorene Defaults auszusehen. |

---

### v2.9.11 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.11** | 🔴 fix | Admin/Design | **`CMS/admin/modules/themes/ThemeEditorModule.php` sperrt erkannte Binärdateien im Theme-Explorer jetzt konsequent als read-only**: Kleine Dateien mit erlaubter Endung, deren Inhalt Binärdaten enthält, zeigen damit nicht länger einen leeren Sicherheits-Editor mit weiter aktivem Save-Pfad, der den Originalinhalt versehentlich überschreiben könnte. |

---

### v2.9.10 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.10** | 🔴 fix | Assets/Design | **`CMS/assets/js/admin-font-manager.js` nutzt `requestSubmit(submitter)` jetzt nur noch mit echten Submit-Buttons**: Bestätigte Font-Löschungen scheitern damit in modernen Browsern nicht mehr daran, dass der sichtbare Delete-Trigger bewusst `type="button"` trägt und als unzulässiger Submitter an die Formular-API gereicht wurde. |
---

### v2.9.9 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.9** | 🔴 fix | Assets/EditorJS | **`CMS/assets/js/admin-content-editor.js` leert beim manuellen `DE → EN`-Kopierflow jetzt eine noch offene AI-Preview-/Diff-Karte sofort mit**: Veraltete `Übernehmen`-/`Verwerfen`-Aktionen bleiben damit nicht länger sichtbar, nachdem die EN-Bearbeitung bereits per Direktkopie überschrieben wurde. |

---

### v2.9.8 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.8** | 🔴 fix | Assets/Design | **`CMS/assets/js/admin-menu-editor.js` validiert Menüziele jetzt wieder konsistent zum `MenuEditorModule`**: slug-basierte interne Pfade ohne führenden Slash sowie leere URLs für `Startseite`-/`Home`-Einträge und echte Elternpunkte blockieren damit nicht länger schon im Browser, obwohl das Backend diese Fälle gezielt auf interne Pfade, `/` oder `#` normalisiert. |

---

### v2.9.7 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.7** | 🔴 fix | Admin/Themes | **`CMS/admin/theme-settings.php` nutzt jetzt den gemeinsamen `redirect-alias-shell.php`-Vertrag statt eines harten Sofort-Redirects mit totem Legacy-Restcode**: Der Theme-Settings-Querpfad prüft damit wieder sauber `manage_settings` und leitet kontrolliert auf `/admin/settings` weiter, statt als inkonsistenter Sonderfall neben dem restlichen Admin-Alias-Muster zu hängen. |

---

### v2.9.6 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.6** | 🔴 fix | Admin/Member | **`CMS/admin/member-dashboard.php` akzeptiert im Legacy-Redirect den von `redirect-alias-shell.php` übergebenen Konfigurationsparameter jetzt kompatibel**: Aufrufe von Member-Subsektionen wie `general`, `widgets`, `profile-fields` oder `notifications` scheitern damit nicht mehr mit `ArgumentCountError`, sondern leiten wieder sauber auf ihre jeweilige Legacy-Route weiter. |
---

### v2.9.5 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.5** | 🔴 fix | Admin/Media | **`CMS/core/Services/Media/UploadHandler.php` normalisiert beim Umbenennen von Root-Dateien und Root-Ordnern den Elternpfad jetzt korrekt**: Statt Meta-Ziele über `dirname($path)` auf `.` aufzubauen, bleiben Kategorien, Uploader-Zuordnung und weitere Medien-Metadaten nach Root-Renames jetzt auf dem echten Zielpfad und driften nicht mehr auf kaputte Schlüssel wie `./datei.jpg` ab. |

---

### v2.9.4 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.4** | 🔴 fix | Admin/Users | **`CMS/admin/users.php`, `CMS/admin/views/users/list.php` und `CMS/assets/js/admin-users.js` entfernen den toten `usersGridConfig`-/GridJS-Restvertrag aus der Benutzerliste und aktivieren die bestehende Bulk-Logik wieder im UI**: Auswahl-Checkboxen, Select-all und `ids[]`-Versand machen `activate`, `deactivate` und `hard_delete` in der serverseitig gerenderten Tabelle wieder tatsächlich bedienbar. |
| **2.9.4** | 🔴 fix | Admin/RBAC | **`CMS/admin/user-settings.php`, `CMS/admin/groups.php` und `CMS/admin/roles.php` erzwingen jetzt denselben `manage_users`-Vertrag wie `/admin/users`**: Benutzernahe Konfiguration, Gruppen- und Rollenverwaltung bleiben damit nicht länger lockerer geschützt als die eigentliche Benutzerverwaltung. |

---

### v2.9.2 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.2** | ⬜ chore | Assets/Runtime | **`CMS/assets/` und `CMS/vendor/dompdf/` wurden erneut selektiv gegen den frisch heruntergeladenen `/ASSETS`-Stand nachgezogen**: Der produktive Runtime-Satz ist damit für `Carbon 3.11.4`, `LdapRecord 4.0.3`, `mailer 8.0.8`, `mime 8.0.8`, `translation 8.0.8`, `tntsearch 5.0.3`, `suneditor 3.0.5` und `dompdf 3.1.5` wieder auf dem aktuellen verifizierten Snapshot. |
| **2.9.2** | 🔴 fix | Editor/Assets | **`CMS/assets/editorjs/editorjs.umd.js`, `CMS/assets/editorjs/editorjs.mjs` und `CMS/assets/editorjs/delimiter.umd.js` wurden auf den aktuellen Editor.js-Stand gehoben; das Delimiter-Bundle wurde dabei aus `ASSETS/editor.js-2.31.6/plugins/editorjs-delimiter-version1.0.2.zip` neu gebaut**: Der von `EditorJsAssetService` erwartete Runtime-Vertrag bleibt damit vollständig, statt sich auf eine zufällig liegengebliebene Altdatei zu verlassen. |
| **2.9.2** | ⬜ chore | Assets/Scope | **Die frisch heruntergeladenen Pakete `cache-8.0.8`, `guzzle-7.10.0`, `php-jwt_yuliyan_1.1.3` und `tabler-icons-3.41.1` wurden bewusst nicht produktiv verdrahtet**: Code- und Runtime-Prüfung zeigen dafür aktuell keine aktiven Referenzen in `CMS/**`, sodass 365CMS weiterhin nur tatsächlich genutzte Bundles übernimmt statt Staging-Pakete blind in die Live-Laufzeit zu tragen. |
| **2.9.2** | ⬜ chore | Assets/Staging | **`ASSETS/google-translate-php-5.3.0/` wurde bewusst aus dem Repository-Staging entfernt**: Die zuvor nur als Kandidat dokumentierte Bibliothek basiert auf inoffizieller Google-Translate-Website-Nutzung und soll nicht weiter als technische Grundlage für produktive Übersetzungsfunktionen in 365CMS dienen. |
| **2.9.2** | 🔵 docs | Assets | **`DOC/ASSET.md`, `DOC/assets/README.md`, `DOC/ASSETS_NEW.md` und `DOC/ASSETS_OwnAssets.md` ziehen die Asset-Bewertung jetzt auf einen bereinigten AI-/Translation-Stand**: Die Doku trennt damit den aktiven Runtime-Refresh sauber von unverknüpften Kandidatenpaketen, dokumentiert die Entfernung von `google-translate-php` aus `/ASSETS` und fokussiert Übersetzungsfunktionen auf einen providerbasierten AI-Services-Ansatz. |
| **2.9.2** | 🔵 docs | Admin/AI | **`DOC/admin/system-settings/AI-SERVICES.md`, `DOC/admin/system-settings/README.md`, `DOC/admin/README.md`, `DOC/INDEX.md`, `DOC/README.md` und `README.md` beschreiben jetzt ein geplantes Admin-Zielbild für `AI Services` unter `System / Einstellungen`**: Provider-Scope, Feature-Gates sowie eine erste Phase für Editor.js-Übersetzung nach Englisch sind damit dokumentiert, ohne die Funktion bereits runtime-seitig zu behaupten. |
| **2.9.2** | 🔵 docs | AI/Architektur | **`DOC/ai/AI-SERVICES.md` wurde als kanonische AI-Konzeptdoku neu angelegt und die übrigen Verweise wurden darauf umgestellt**: Provider-Scope, Capability-Modell, Provider-Matrix, Editor.js-Datenfluss, Admin-UI, Ausbaustufen und eine explizite Liste offener Umsetzungsbausteine liegen damit nicht länger nur im Admin-Kontext, sondern zentral gebündelt im Doku-Baum. |
| **2.9.2** | 🟢 feat | Admin/AI | **`CMS/admin/ai-services.php`, `CMS/admin/modules/system/AiServicesModule.php`, `CMS/admin/views/system/ai-services.php`, `CMS/core/Services/AI/AiSettingsService.php` und `CMS/admin/partials/sidebar.php` führen eine erste AI-Services-Settings-Shell im Core ein**: Provider, Feature-Gates, Translation-Regeln, Logging und Quotas lassen sich damit bereits unter `/admin/ai-services` verwalten und in der vorhandenen Settings-Tabelle persistieren, inklusive verschlüsselter Provider-Secrets. |
| **2.9.2** | 🟢 feat | Core/AI | **`CMS/core/Services/AI/AiProviderGateway.php`, `CMS/core/Services/AI/Providers/MockAiProvider.php`, `CMS/core/Services/AI/EditorJsTranslationPipeline.php`, `CMS/admin/ai-translate-editorjs.php`, `CMS/admin/modules/system/AiEditorJsTranslationModule.php`, `CMS/assets/js/admin-content-editor.js`, `CMS/admin/views/posts/edit.php` und `CMS/admin/views/pages/edit.php` heben AI Services auf eine erste echte Runtime-Stufe**: Post- und Page-Editoren können Editor.js-Inhalte damit bereits über ein Provider-Gateway mit lokalem `mock`-Provider testweise von DE nach EN übersetzen und in die EN-Felder zurückführen, ohne externe Live-Calls auszulösen. |
| **2.9.2** | 🟢 feat | Admin/AI | **`CMS/assets/js/admin-content-editor.js` ergänzt jetzt einen echten Preview-/Diff-Workflow vor der EN-Übernahme von AI-Vorschlägen**: Titel, Slug, Kurzfassung und geänderte Editor.js-Blöcke werden vor dem Überschreiben sichtbar gegenüber der aktuellen EN-Fassung verglichen, statt nur mit einem simplen Bestätigungsdialog zu arbeiten. |
| **2.9.2** | 🔴 fix | Admin/Navigation | **`CMS/admin/partials/sidebar.php` normalisiert Legacy-Alias-Seiten jetzt auf kanonische Admin-Ziele und ergänzt den fehlenden Eintrag `Theme Marketplace` unter `Themes & Design`**: Die Sidebar bleibt damit konsistenter zu den echten Runtime-Routen und versteckt keine produktive Theme-Marketplace-Seite mehr im Schatten. |
| **2.9.2** | 🔴 fix | Admin/Dashboard | **`CMS/core/Services/DashboardService.php` verweist den Attention-Hinweis `HTTPS nicht aktiv` jetzt auf `/admin/security-audit` statt auf die nicht existente Schattenroute `/admin/system`**: Der erste Fund aus dem neuen Admin-Gesamtaudit endet damit nicht mehr in einem toten Ziel, sondern direkt im realen Security-Prüfpfad. |
| **2.9.2** | 🔴 fix | Admin/Posts | **`CMS/admin/posts.php` und `CMS/core/Routing/ApiRouter.php` verlangen für die Beitragsverwaltung jetzt konsistent `edit_all_posts`**: Eingeschränkte Admins landen damit nicht mehr erst in einer sichtbaren Beitragsoberfläche oder im Grid-Endpoint, um später an Schreib- oder AI-Berechtigungen zu scheitern. |
| **2.9.2** | 🔵 docs | Audit/Admin | **`DOC/audit/AdminAudit-INDEX.md` sowie `DOC/audit/AdminAudit-*.md` legen jetzt eine vollständige Arbeitsmappe für alle Hauptbereiche des Admins an**: Dashboard, Seiten, Beiträge, Benutzer, Gruppen, Medien, Member, Themes, Design, SEO, Sicherheit, Performance, Recht, Plugins, System, Info und Diagnose sind damit jeweils mit Entry-, Modul-, View-, Unterbereichs- und Hotspot-Doku als Basis für die nachfolgende Detailprüfung erfasst. |
| **2.9.2** | 🔵 docs | AI/Settings | **`DOC/ai/AI-SERVICES.md`, `DOC/admin/system-settings/AI-SERVICES.md`, `DOC/admin/system-settings/README.md`, `README.md` und `Changelog.md` dokumentieren jetzt die konkrete 365CMS-Datenstruktur für `ai.providers`, `ai.features`, `ai.translation`, `ai.logging` und `ai.quotas`**: Die Doku trennt nun klar zwischen bereits vorhandener Settings-/Admin-Hülle und noch fehlender Provider-Ausführung, Editor.js-Integration sowie produktivem Preview-Workflow. |
| **2.9.2** | 🔵 docs | AI/Runtime | **`DOC/ai/AI-SERVICES.md`, `DOC/admin/system-settings/AI-SERVICES.md`, `README.md` und `Changelog.md` dokumentieren jetzt zusätzlich Provider-Gateway, eingebauten `mock`-Provider, den Endpoint `/admin/ai-translate-editorjs`, die Editor.js-DE→EN-Mock-Pipeline und den neuen Preview-/Diff-Schritt**: Die Doku trennt nun klar zwischen vorhandener Mock-Runtime mit Review-Schritt und weiterhin fehlenden externen Live-Provider-Requests. |

---

### v2.9.3 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.3** | 🔴 fix | Admin/Pages | **`CMS/admin/pages.php` und `CMS/admin/views/pages/list.php` entfernen den toten `pagesGridConfig`-/GridJS-Restvertrag aus der Seitenliste**: Die serverseitig gerenderte Tabellenansicht lädt damit keine ungenutzten Grid-Assets oder wirkungslosen Konfigurationsballast mehr nach. |
| **2.9.3** | 🔴 fix | Admin/Posts | **`CMS/admin/modules/posts/PostsModule.php` und `CMS/admin/views/posts/edit.php` laden und speichern zusätzliche Beitrags-Kategorien jetzt wieder explizit über `additional_category_ids[]`**: Vorhandene Mehrfachzuordnungen aus `post_category_rel` gehen damit beim erneuten Bearbeiten nicht länger still verloren. |
| **2.9.3** | 🔵 docs | Audit/Admin | **`DOC/audit/AdminAudit-Beitraege.md` und `DOC/audit/AdminAudit-Seiten.md` dokumentieren die ersten konkret nachgezogenen Verdrahtungsfehler jetzt direkt am Bereich**: Tag-Reassignment, Beitrags-RBAC, Mehrfach-Kategorien und der tote Seiten-Grid-Vertrag sind damit nicht nur gefixt, sondern auch als belastbare Audit-Funde verankert. |

---

### v2.9.1 — 08. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.1** | ⬜ chore | Assets/Runtime | **`CMS/assets/` und `CMS/vendor/dompdf/` wurden auf den aktuellen eingebundenen Bestand aus `/ASSETS` nachgezogen**: Der Runtime-Sync übernimmt damit wieder die produktiven Bundles für `tabler`, `editorjs`, `suneditor`, `Carbon`, `ldaprecord`, `mailer`, `mime`, `translation`, `tntsearch`, `photoswipe`, `gridjs` und `dompdf`, statt die Staging-Bäume nur lose neben der Laufzeit liegen zu lassen. |
| **2.9.1** | 🔴 fix | Admin/Users | **`CMS/admin/modules/users/UsersModule.php`, `CMS/core/Services/UserService.php`, `CMS/core/Services/MemberService.php` sowie die Legal-Admin-Module trennen Admin-Löschung und Self-Service-Löschung jetzt sauber**: Admins löschen Benutzer im Backend wieder hart und direkt, während eine Selbstlöschung im Member-Bereich stattdessen einen 30-Tage-Löschantrag mit `execute_after` erzeugt, der im Admin sichtbar und nachvollziehbar bleibt. |
| **2.9.1** | 🔴 fix | Admin/System | **`CMS/admin/cms-logs.php`, `CMS/admin/views/system/cms-logs.php`, `CMS/core/Services/SystemService.php`, `CMS/admin/modules/system/SystemInfoModule.php` und die Dokumentationsansicht bündeln Diagnose und Laufzeitlogs jetzt sichtbar unter `CMS Logs`**: Admins sehen damit konfigurierte Logdateien, Kanal-Einträge und Doku-Sync-Hinweise direkt im Systembereich statt im Blindflug zwischen Dateisystem und Einzelansichten zu suchen. |
| **2.9.1** | 🔴 fix | Admin/Dashboard | **`CMS/admin/views/system/diagnose.php`, `CMS/admin/views/system/documentation.php`, `CMS/admin/views/dashboard/index.php` und `CMS/admin/modules/dashboard/DashboardModule.php` zentralisieren Logaktionen und Alert-Ausgaben jetzt strikter**: Loglisten und Log-Löschaktionen bleiben damit ausschließlich unter `System > CMS Logs`, während das Admin-Dashboard defensive nur noch `warning`-/`danger`-Hinweise rendert und keine reinen Info-Meldungen mehr als Startseiten-Alerts ausspielt. |
| **2.9.1** | 🔴 fix | Core/Logging | **`CMS/config/app.php`, `CMS/admin/modules/settings/SettingsModule.php` und `CMS/install/InstallerService.php` normalisieren den Logpfad jetzt konsequent auf `ABSPATH . 'logs/'`**: Runtime-, Installer- und Konfig-Generatoren schreiben Logs damit unter dem FTP-/Webroot neben `index.php` statt in verstreute `var/logs`- oder Temp-Fallbacks. |
| **2.9.1** | 🔴 fix | Installer/Updates | **`CMS/install/InstallerService.php` migriert bestehende `config/app.php`-Dateien beim Update jetzt deutlich vollständiger**: Vorhandene Konfigurationen werden weiterhin zuerst gesichert, anschließend aber mit aktueller Struktur für LDAP, JWT, SMTP, HSTS, Session-/Login-Limits und den neuen `logs/`-Pfad neu geschrieben, ohne dass Bestandswerte aus Legacy-Installationen still verloren gehen. |
| **2.9.1** | 🔴 fix | Admin/Documentation | **`CMS/admin/modules/system/DocumentationGithubZipSync.php` und `DocumentationSyncFilesystem.php` ergänzen für den Doku-Sync einen GitHub-API-/Raw-Fallback nur für `DOC/**`**: Wenn das komplette Repository-ZIP auf `codeload.github.com` für dieses große Monorepo zu groß wird, synchronisiert 365CMS die Dokumentation jetzt stattdessen direkt über die GitHub-Tree-API und einzelne Raw-Dateien, statt den Sync komplett abzubrechen. |
| **2.9.1** | 🔴 fix | Admin/Documentation | **`CMS/admin/modules/system/DocumentationModule.php` aktualisiert das freigegebene `/DOC`-Integritätsprofil auf den aktuellen Repository-Stand**: Der GitHub-Sync verwirft damit nicht länger den inzwischen gewachsenen Dokumentationsbaum nur deshalb, weil Dateianzahl und Bundle-Hash noch auf einem älteren Snapshot (`132` Dateien) fest verdrahtet waren. |
| **2.9.1** | 🔵 docs | Assets | **`DOC/ASSET.md`, `DOC/assets/README.md`, `DOC/ASSETS_NEW.md`, `DOC/ASSETS_OwnAssets.md`, `DOC/INDEX.md`, `DOC/README.md` und `README.md` dokumentieren den Asset-Stand jetzt sauberer**: Runtime-Pfade unter `CMS/assets/` und `CMS/vendor/dompdf/` sind damit explizit vom Staging-Bereich `/ASSETS` getrennt, und neue Kandidaten wie `symfony/ai-platform` sowie `google-translate-php` sind als separate Integrationsbewertung statt als implizite Runtime-Annahme erfasst. |
| **2.9.1** | 🔵 docs | Release | **`README.md`, `Changelog.md`, `CMS/core/Version.php` und `CMS/update.json` wurden auf den Release-Stand `2.9.1` synchronisiert**: sichtbare Versionsnummer, Hotfix-Highlights und Update-Metadaten zeigen damit denselben Stand für Lösch-Workflow, Logs und Installer-Migration. |

---

### v2.9.0 — 07. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.9.0** | 🟢 feat | Core/Auth | **`CMS/core/Services/CmsAuthPageService.php`, `CMS/views/auth/cms-auth.php` sowie die öffentlichen Routerpfade führen eine CMS-eigene Auth-Strecke für Login, Registrierung und Passwort-Reset ein**: Die festen Core-Routen `/cms-login`, `/cms-register` und `/cms-password-forgot` funktionieren damit unabhängig vom aktiven Frontend-Theme. |
| **2.9.0** | 🟢 feat | Admin/Themes | **`CMS/admin/cms-loginpage.php`, `CMS/admin/modules/themes/CmsLoginPageModule.php` und `CMS/admin/views/themes/cms-loginpage.php` ergänzen eine neue Admin-Oberfläche für die CMS Loginpage**: Branding, Farben, Formtexte, Footer-Links, Rechtsseiten, Passkey-Sichtbarkeit sowie Reset-Mail-Vorlagen und Link-Ablaufzeit lassen sich damit zentral im Admin steuern. |
| **2.9.0** | 🔴 fix | Core/Auth | **`CMS/core/Auth.php` und `CMS/core/Auth/AuthManager.php` finalisieren Passwort-, MFA-, Backup-Code-, Passkey- und LDAP-Logins jetzt über denselben Session-Vertrag**: MFA-Benutzer werden dadurch nach erfolgreicher Bestätigung nicht mehr aus der Login-Strecke herausgeworfen, und der Remember-Me-Status bleibt über den kompletten Auth-Flow erhalten. |
| **2.9.0** | 🔴 fix | Core/Routing | **`CMS/core/Routing/PublicRouter.php` nutzt jetzt strukturierte allowlist-basierte Redirect-Teile und locale-aware MFA-Pfade statt loser Redirect-Strings**: offene Redirect-Kanten und theme-/sprachabhängige MFA-Sprünge hängen dadurch enger an einem fail-closed Same-Origin-Vertrag. |
| **2.9.0** | 🎨 style | Admin/Posts | **`CMS/admin/views/posts/edit.php` ordnet den Beitrags-Editor kompakter neu**: Kategorie und Tags sitzen jetzt direkt unter dem Slug in der ersten Card, die Mehrfachauswahl „Zusätzliche Kategorien“ entfällt vollständig, und unter `Beitragsbild` gibt es eine eigene Aktions-Card für `Erstellen/Aktualisieren` sowie die öffentlichen DE-/EN-Vorschauen. |
| **2.9.0** | 🟢 feat | Admin/Posts | **`CMS/assets/js/admin-content-editor.js` und `CMS/admin/views/posts/edit.php` initialisieren den englischen Beitrags-Editor jetzt beim ersten Wechsel fail-safe aus dem deutschen Inhalt**: Die DE-Fassung wird genau einmal in den noch leeren EN-Editor kopiert, sobald Redakteure erstmals auf die englische Bearbeitungsansicht wechseln; vorhandener EN-Content bleibt danach unangetastet. |
| **2.9.0** | 🔴 fix | Admin/Post-Kategorien | **`CMS/admin/post-categories.php`, `CMS/assets/js/admin.js` und `CMS/admin/modules/posts/PostsCategoryViewModelBuilder.php` reichen beim Löschen von Beitragskategorien die hinterlegte Ersatzkategorie jetzt korrekt durch und zeigen wieder belastbare Zuordnungszahlen an**: Kategorien mit angezeigter Ersatzkategorie lassen sich dadurch wieder löschen, und die Kategorienübersicht meldet zugewiesene Beiträge nicht länger fälschlich mit `0`. |
| **2.9.0** | 🟢 feat | Admin/Post-Kategorien | **`CMS/admin/modules/posts/PostsModule.php` ergänzt neben dem bestehenden Block `Microsoft 365` jetzt auch einen eigenen Default-Kategorienbaum `Technik & IT`**: Neue und bestehende Installationen erhalten damit automatisch zusätzliche Technik-/IT-Kategorien wie `IT-Infrastruktur`, `Cyber Security`, `Cloud & DevOps`, `Softwareentwicklung` und `KI & Automatisierung` außerhalb des M365-Bereichs. |
| **2.9.0** | 🔴 fix | SEO/404-Monitor | **`CMS/admin/views/seo/not-found.php`, `CMS/admin/views/seo/redirects.php` und `CMS/assets/js/admin-seo-redirects.js` härten die JSON-Übergabe für 404-Übernahmen und Redirect-Bearbeitung gegen ungültige UTF-8-Zeichen ab**: Erkannte 404-Einträge lassen sich dadurch wieder zuverlässig als neue Weiterleitung übernehmen, statt dass der Übernehmen-Button bei kaputten Referrer-/User-Agent-Daten still ins Leere läuft. |
| **2.9.0** | 🔴 fix | SEO/Admin | **`CMS/assets/js/admin-seo-redirects.js` öffnet die Redirect-Modale im 404-Monitor und im Weiterleitungsmanager jetzt über delegierte Klick-Handler mit robuster Bootstrap-/Fallback-Initialisierung**: `Übernehmen`, `Bearbeiten` und `Erweitert anlegen` laufen damit nicht länger still ins Leere, selbst wenn Dropdown-Timing, fehlende globale Bootstrap-Referenzen oder restriktive `localStorage`-Umgebungen den bisherigen Klickpfad gestört haben. |
| **2.9.0** | 🔴 fix | Admin/Users | **`CMS/admin/users.php`, `CMS/admin/views/users/list.php` und `CMS/admin/views/users/edit.php` verdrahten Benutzerlöschungen jetzt sichtbar und mit korrekter Rückleitung zurück in die Benutzerliste**: Benutzer lassen sich damit im Admin wieder direkt deaktivieren, statt dass nur Bearbeiten verfügbar ist oder der Delete-Flow nach Erfolg wieder auf die alte Edit-URL zurückfällt. |
| **2.9.0** | 🔴 fix | Admin/Users | **`CMS/assets/js/admin.js` stößt explizite Ziel-Formulare für Admin-Aktionsbuttons jetzt direkt per `data-submit-form` an**: Der Benutzer-Löschen-Button in der Edit-Ansicht hängt damit nicht länger an fragiler `form="..."`-Browserlogik innerhalb des großen Bearbeitungsformulars, sondern feuert den eigentlichen Delete-POST belastbar ab. |
| **2.9.0** | 🔴 fix | Admin/Users | **`CMS/admin/views/users/edit.php` nutzt für die Benutzerdeaktivierung jetzt zusätzlich ein eigenständiges sichtbares Löschformular in der Sidebar**: Der Delete-Flow hängt damit nicht mehr an einem indirekten Trigger aus der Speichern-Card, sondern an einem nativen POST-Submit mit Bestätigungsdialog direkt am eigentlichen Danger-Bereich. |
| **2.9.0** | 🔴 fix | Admin/Users | **`CMS/admin/views/users/edit.php` vermeidet im Bearbeitungsformular jetzt verschachtelte Form-Tags und reicht `save`/`delete` stattdessen über die geklickten Submit-Buttons an denselben `userForm`-POST weiter**: Der CSRF-Token bleibt dadurch im gültigen Hauptformular, und der Delete-Button läuft nicht länger in einen kaputten Nested-Form-Submit mit „Sicherheitstoken ungültig.“. |
| **2.9.0** | 🔴 fix | Admin/Routing | **`CMS/admin/partials/section-page-shell.php`, `CMS/admin/users.php`, `CMS/admin/views/users/list.php` und `CMS/admin/views/users/edit.php` verwenden für den User-Bereich jetzt interne relative Admin-Pfade statt hart an `SITE_URL` gebundene Ziele**: Form-Posts, Edit-Links und Redirects springen damit lokal oder auf alternativen Hosts nicht mehr fälschlich auf `phinit.de`, sondern bleiben auf dem aktuellen Host der laufenden Admin-Session. |
| **2.9.0** | 🔴 fix | Admin/Users | **`CMS/admin/views/users/edit.php`, `CMS/admin/views/users/list.php` und `CMS/assets/js/admin-users.js` orientieren User-Form-Actions und Grid-Links jetzt direkt an der aktuellen Request-URL bzw. an hostneutralen Admin-Pfaden**: Auch unter Rewrite-, Proxy- oder Alternativhost-Setups bleibt der User-Delete-Request damit auf genau dem aktuell funktionierenden Admin-Endpunkt statt wieder auf einen abweichenden Host oder Pfad zu springen. |
| **2.9.0** | 🔴 fix | Admin/Users | **`CMS/admin/views/users/edit.php` und `CMS/admin/views/users/list.php` normalisieren `REQUEST_URI` jetzt vor der Verwendung als Formularziel explizit auf internen Pfad plus Query**: Selbst wenn Upstream/Proxy-Setups eine absolute URL in `REQUEST_URI` liefern, posten User-Aktionen damit nicht mehr versehentlich wieder auf einen externen oder veralteten Host. |
| **2.9.0** | 🟢 feat | Admin/Posts | **`CMS/admin/views/posts/edit.php` und `CMS/assets/js/admin-content-editor.js` ergänzen einen expliziten DE→EN-Kopierbutton für Beitragsinhalte**: Titel, Slug, Kurzfassung und sämtliche Editor.js-Blöcke lassen sich damit kontrolliert in die englische Variante übernehmen, ohne Medien erneut hochzuladen oder Bilder doppelt anzulegen. |
| **2.9.0** | 🔵 docs | Auth | **`DOC/admin/themes-design/CMS-LOGINPAGE.md`, `DOC/admin/users-groups/AUTH-SETTINGS.md`, `DOC/member/README.md`, `DOC/README.md` und `DOC/INDEX.md` dokumentieren jetzt die neue Core-Auth-Strecke und ihre Admin-Aufteilung**: Betriebs- und Support-Doku folgen damit dem tatsächlichen Login-/MFA-Vertrag statt älteren themeabhängigen Pfaden. |
| **2.9.0** | 🔵 docs | Release | **`README.md`, `Changelog.md`, `CMS/core/Version.php` und `CMS/update.json` wurden auf den Release-Stand `2.9.0` synchronisiert**: sichtbare Versionsnummer, Highlights und Update-Metadaten zeigen damit denselben aktuellen Auth-/MFA-Stand. |

---

### v2.8.5 — 04. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.8.5** | 🔴 fix | Core/Routing | **`CMS/core/Router.php` sendet Redirects jetzt nur noch über strikt interne Zielpfade im `Location`-Header und rendert den Fallback mit absolutem Link nur noch außerhalb des Header-Pfads**: Der Redirect-Vertrag hängt damit enger an internen Pfadnormalisierungen statt an einer absoluten URL-Zusammensetzung im Response-Header. |
| **2.8.5** | 🔴 fix | Core/Themes | **`CMS/core/Services/ThemeCustomizer.php` löst `theme.json` jetzt über eine filesystembasierte Allowlist tatsächlich vorhandener Theme-Konfigurationen auf und fällt auf den ersten gültigen Theme-Slug zurück**: Theme-Konfigurationen hängen damit nicht mehr an losem DB-Slug-zu-Pfad-Vertrauen, sondern an einem kleinen, reproduzierbaren Dateisystemvertrag. |
| **2.8.5** | 🔵 docs | Audit | **`DOC/audit/Snyk_Audit_04042026.md` und `DOC/audit/BEWERTUNG.md` dokumentieren jetzt den nächsten Follow-up-Scan mit 36 Gesamtfunden, 9 `Medium`, 27 `High` und 0 Nicht-Vendor-/Runtime-Funden**: Der First-Party-Snyk-Block ist damit im aktuellen Snapshot vollständig leergezogen. |
| **2.8.5** | 🔵 docs | Release | **`README.md`, `Changelog.md`, `CMS/core/Version.php` und `CMS/update.json` wurden auf den Release-Stand `2.8.5` synchronisiert**: Versionsnummer, Highlights und Update-Metadaten zeigen damit denselben finalen Security-Follow-up-Stand. |

---

### v2.8.4 — 04. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.8.4** | 🔴 fix | Core/API | **`CMS/core/Api.php` liefert bei ungefangenen API-Fehlern jetzt nur noch eine generische 500-Antwort und protokolliert den internen Exception-Text ausschließlich serverseitig**: REST-Endpunkte leaken damit keine rohen Stack-/Fehlerdetails mehr über JSON-Responses. |
| **2.8.4** | 🔴 fix | Checkout/Orders | **`CMS/orders.php` gibt bei Bestellfehlern keine rohen Exception-Texte mehr an den öffentlichen Checkout zurück, sondern loggt intern und zeigt nur noch eine generische Fehlermeldung**: Der Bestellpfad bleibt damit UX-seitig verständlich, ohne interne Betriebsdetails preiszugeben. |
| **2.8.4** | 🔴 fix | Security/Headers | **`CMS/core/Security.php` setzt `X-Frame-Options` jetzt explizit auf `DENY`**: Framing wird damit auch auf Header-Ebene an die bereits restriktive `frame-ancestors 'none'`-CSP angeglichen. |
| **2.8.4** | 🔴 fix | Security/Mail | **`CMS/admin/modules/system/MailSettingsModule.php`, `CMS/core/Services/MailService.php` und `CMS/admin/views/system/mail-settings.php` benennen den nicht-OAuth2-Auth-Mode jetzt konsistent als `credentials` statt als Literal `password`**: bestehende Konfigurationen bleiben kompatibel, während die beiden Snyk-Mediums „Use of Hardcoded Passwords“ aus dem First-Party-Block verschwinden. |
| **2.8.4** | 🔴 fix | Security/Media | **`CMS/core/Services/MediaDeliveryService.php` gibt bei abgelehnten Medienanforderungen nur noch generische öffentliche Fehlermeldungen aus, streamt Binärdaten über `php://output` und hält die konkreten Gründe im Log**: Ablehnungen bleiben damit nachvollziehbar administrierbar, ohne potenziell detailreiche Response-Texte direkt an Clients zu spiegeln. |
| **2.8.4** | 🔴 fix | Security/File I/O | **`CMS/core/Services/IndexingService.php` und `CMS/core/Services/ThemeCustomizer.php` lesen erlaubte Dateien jetzt nur noch über kanonische Realpaths und Stream-Reader**: Die Dateiverträge bleiben damit expliziter fail-closed, und zusätzliche Scanner-Mediums in `IndexingService.php` sowie `MediaDeliveryService.php` verschwinden aus dem First-Party-Restblock. |
| **2.8.4** | 🔵 docs | Audit | **`DOC/audit/Snyk_Audit_04042026.md` und `DOC/audit/BEWERTUNG.md` dokumentieren jetzt den nächsten Follow-up-Scan mit 38 Gesamtfunden, 11 `Medium`, 27 `High` und nur noch 2 Nicht-Vendor-/Runtime-Funden**: die Restliste schrumpft damit im First-Party-Block auf `Router.php` und `ThemeCustomizer.php`. |
| **2.8.4** | 🔵 docs | Release | **`README.md`, `Changelog.md`, `CMS/core/Version.php` und `CMS/update.json` wurden auf den Release-Stand `2.8.4` synchronisiert**: Versionsnummer, Highlights und Update-Metadaten zeigen damit denselben Security-Follow-up-Stand. |

---

### v2.8.3 — 04. April 2026

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.8.3** | 🔴 fix | Core/Updates | **`CMS/core/Services/UpdateService.php` begrenzt lokale Marketplace-Manifestpfade jetzt traversal-sicher auf echte JSON-Dateien innerhalb des erlaubten Basisverzeichnisses und deckelt lokale JSON-Reads zusätzlich über Dateigröße**: Lokale Katalog- oder Manifest-Sonderpfade laufen damit nicht mehr blind in `file_get_contents()` außerhalb des vorgesehenen Roots. |
| **2.8.3** | 🔴 fix | Core/Routing | **`CMS/core/Routing/PublicRouter.php` normalisiert Post-Login- und Kommentar-Redirects jetzt strikt auf interne Zielpfade, verwirft Host-/Schema-Sprünge, `//`-Ziele und `..`-Segmente und blockiert Auth-Loop-Ziele explizit**: Die öffentlichen Auth- und Kommentarflüsse hängen damit enger an einem klaren Same-Origin-Redirect-Vertrag statt an losem Request-Input. |
| **2.8.3** | 🔴 fix | Frontend/Contact | **`CMS/themes/cms-default/contact.php` trennt Formular-Sanitizing jetzt sauber von Ausgabe-Escaping, validiert Empfänger-/Absender-Adressen strenger und nutzt bevorzugt `MailService::sendPlain()` statt roher Header-Zusammensetzung**: Kontaktanfragen landen dadurch nicht mehr über vorgemischte Header- oder Outputpfade in Mail-/XSS-Kanten. |
| **2.8.3** | 🔴 fix | Core/Error | **`CMS/index.php` zeigt detaillierte Fatal-Fehlerausgaben im Debug-Modus jetzt nur noch lokal (`CLI`, `127.0.0.1`, `::1`) statt sie auf beliebigen Requests auszuspielen**: Stacktrace- und Pfad-Details bleiben damit trotz Debug-Flag aus öffentlicher Fehlerausgabe heraus. |
| **2.8.3** | 🔴 fix | Security/Follow-up | **Der erste Snyk-Nachzieh-Batch wurde anschließend noch einmal bewusst fail-closed verschärft**: `PublicRouter.php` ignoriert benutzerkontrollierte Login-Redirects jetzt vollständig zugunsten von `/member`, Kommentar-Redirects verlassen sich nicht mehr auf den Referrer, `UpdateService.php` lädt keine lokalen Marketplace-Manifeste mehr dynamisch nach, `contact.php` setzt keine benutzerkontrollierten Reply-To-Header mehr und `CMS/index.php` liefert im Fatal-Pfad nur noch die generische Fehlerseite aus. |
| **2.8.3** | 🔴 fix | Plugin/Importer | **`CMS/plugins/cms-importer/assets/js/importer.js` rendert Status-Notices und Cleanup-Dialog-Texte jetzt ohne `innerHTML`, sondern nur noch über sichere DOM-Knoten und `textContent`**: Die gemeldeten DOM-XSS-Pfade im WordPress-Importer verschwinden damit aus dem aktuellen Snyk-Nachscan. |
| **2.8.3** | 🔵 docs | Audit | **`DOC/audit/Snyk_Audit_04042026.md`, `DOC/audit/LiveAudit_365CMS.md`, `DOC/audit/BEWERTUNG.md` und `README.md` dokumentieren jetzt den fortgeschriebenen Snyk- und Live-Snapshot von `365cms.de`**: Der Nachscan liegt bei 47 Snyk-Codefunden, 13 Restfunden im Nicht-Vendor-/Runtime-Block, 0 verbleibenden First-Party-`High`s und weiterhin 0 SCA-Funden; die aktiv verlinkten 404-Ziele `/impressum`, `/datenschutz` und `/agb` bleiben separat sichtbar dokumentiert. |
| **2.8.3** | 🟢 feat | Editor.js | **`CMS/assets/js/editor-init.js`, `CMS/assets/js/admin-content-editor.js`, `CMS/core/Services/EditorJs/EditorJsAssetService.php`, `CMS/core/Services/EditorJs/EditorJsSanitizer.php`, `CMS/core/Services/EditorJsRenderer.php` und `CMS/assets/css/admin.css` ergänzen jetzt sichtbare Live-Toolbars in den Post-/Page-Editoren sowie zusätzliche Tech-Blog-Blöcke für `Callout`, `Terminal`, `Code Tabs`, `Mermaid`, `API Endpoint`, `Changelog` und `Pros / Cons`**: Die zuvor verdrahteten neuen Editor.js-Features sind damit im echten Admin-Edit-Flow sofort sichtbar und Redakteure können technische Inhalte strukturierter statt über generische Workarounds modellieren. |
| **2.8.3** | 🟢 feat | Editor.js | **`CMS/assets/js/editor-init.js`, `CMS/core/Services/EditorJsRenderer.php`, `CMS/core/Services/EditorJs/EditorJsSanitizer.php`, `CMS/core/Services/EditorJs/EditorJsAssetService.php` und `CMS/assets/css/admin.css` ergänzen jetzt einen eigenen Block `Medien + Text` mit festem 30/70-Layout sowie eine CMS-gestützte `Gallery` mit 2/3/4/6 Spalten, Mediathek-Auswahl, Mehrfach-Upload und Bildunterschriften**: Redakteure können Tech- und Produktartikel damit deutlich strukturierter direkt im Editor.js-Workflow bauen, ohne Bilder nur über URL-Paste oder generische Spaltenblöcke zusammensetzen zu müssen. |
| **2.8.3** | 🔴 fix | Landing Page | **`CMS/core/Services/Landing/LandingSectionProfileService.php` speichert den Landing-Footer-Schalter jetzt als echtes Boolean statt ihn implizit über `isset()` wieder auf aktiv zu ziehen, und `CMS/themes/cms-default/partials/home-landing.php` macht Text-Modus ohne Inhalt im Frontend sichtbar statt stumm leer zu bleiben**: Deaktivierte Footer bleiben damit nach dem Speichern wirklich aus, und nicht-featurebasierte Landing-Content-Modi wirken nachvollziehbarer. |
| **2.8.3** | 🟢 feat | Landing Page | **`CMS/core/Services/Landing/LandingDefaultsProvider.php`, `CMS/core/Services/LandingPageService.php` und `CMS/install/InstallerService.php` modernisieren die Landing-Page-Feature-Karten auf den aktuellen 365CMS-Stand und erweitern sie um mindestens sechs zusätzliche Highlights**: Neue und bestehende Installationen zeigen damit Editor.js-Blöcke, Suche, Monitoring, Updates, DSGVO/Legal sowie Navigation/Redirects direkt in der Landing-Kommunikation. |
| **2.8.3** | 🔴 fix | Admin/Posts | **`CMS/admin/modules/posts/PostsModule.php` wurde nach dem Editor-/Landing-Batch auf versehentliche Copy/Paste-Artefakte im Kategorien- und Bulk-Pfad bereinigt**: Ein irrtümlich in SQL und Rückgabedaten eingefügter `compact(...)`-Rest sowie ein doppelter `case 'draft'`-Zweig blockieren damit weder die Kategorien-Adminseite noch Bulk-Aktionen der Beitragsverwaltung. |
| **2.8.3** | 🔴 fix | Admin/Themes | **`CMS/themes/cms-default/admin/customizer/helpers.php` erkennt jetzt, wenn die Admin-Section-Shell den CSRF-Token für `theme_customizer` bereits erfolgreich geprüft hat, und `CMS/themes/cms-default/admin/customizer.php` baut Success-Meldungen wieder als Klartext statt mit vor-escapten HTML-Entities**: Der Theme-Customizer läuft damit im Admin wieder stabil, statt nach erfolgreichem Shell-Guard am zweiten Token-Verbrauch mit „Sicherheitscheck fehlgeschlagen. Bitte erneut versuchen.“ hängen zu bleiben, und Rücksetz-/Speicherhinweise zeigen „Header & Logo“ wieder normal statt sichtbarer `&bdquo;`-/`&amp;`-Artefakte. |
| **2.8.3** | 🔴 fix | Admin/Menüs | **`CMS/admin/modules/menus/MenuEditorModule.php`, `CMS/assets/js/admin-menu-editor.js` und `DOC/admin/themes-design/MENUS.md` normalisieren Startseiten-Ziele jetzt robuster auf `/` und akzeptieren im Editor zusätzlich Home-Aliasformen sowie leere Home-Einträge fail-safe**: Ein Menüpunkt wie „Startseite“ kippt damit beim Speichern nicht mehr wegen einer leeren oder aliasierten Root-URL in den Fehler „benötigt eine gültige URL oder einen gültigen internen Pfad“, obwohl fachlich eindeutig die Website-Wurzel gemeint ist. |
| **2.8.3** | 🟡 refactor | Admin/Themes | **`CMS/themes/cms-default/admin/customizer.php` rendert den Runtime-Customizer jetzt nur noch als schlanken Bootstrap über `customizer/config.php`, `customizer/helpers.php` und die `customizer/partials/*.php`-Bausteine**: Doppelte HTML-/JS-/CSS-Pflege verschwindet damit aus dem Runtime-Entry, und die zuvor nur im Monolithen auftretenden alten XSS-Static-Analysis-Warnungen entfallen zusammen mit dem duplizierten Feld-Rendering. |
| **2.8.3** | 🟡 refactor | Admin/Themes | **`CMS/themes/cms-default/admin/customizer/partials/styles.php`, `field.php`, `page.php`, `modal.php` und `scripts.php` reduzieren weitere Inline-Styles und Inline-Handler, typisieren Feldtypen strenger und arbeiten beim Bild-/Reset-Handling stärker über Klassen und Datenattribute statt über globale IDs/Funktionsannahmen**: Die Partial-Struktur wird damit konsistenter wartbar, weniger fragil für zusätzliche Felder und sauberer zwischen Markup, Verhalten und Styling getrennt. |
| **2.8.3** | 🔵 docs | Release | **`README.md`, `Changelog.md`, `CMS/core/Version.php` und `CMS/update.json` wurden auf den Release-Stand `2.8.3` synchronisiert**: Editor.js-, Landing- und Posts-Stabilitätsänderungen stehen damit konsistent in Doku, sichtbarer Versionsnummer und Update-Metadaten. |

---

### v2.8.2 — 03. April 2026


| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.8.2** | 🔴 fix | Security/Contact | **`CMS/themes/cms-default/contact.php` trennt Request-Normalisierung jetzt sauber von Ausgabe-Escaping und versendet Kontaktanfragen nur noch über `MailService` statt über einen rohen `mail()`-Fallback**: Formularwerte landen dadurch nicht mehr ungefiltert in Antwort-HTML oder Mail-Headern, und ohne konfigurierten Mail-Service bleibt der Versandpfad ausdrücklich fail-closed. |
| **2.8.2** | 🔴 fix | Security/Cache | **`CMS/core/CacheManager.php` ersetzt den fest verdrahteten Fallback-HMAC-Key durch Installations- und Laufzeit-Secrets mit sicherem Fingerprint-Fallback**: Cache-Signaturen hängen damit nicht mehr an einem global bekannten Standardwert. |
| **2.8.2** | 🔴 fix | Security/Feeds | **`CMS/core/Services/FeedService.php` liest und schreibt Feed-Caches jetzt nur noch über streng begrenzte Cache-Pfade und nutzt für Remote-Feeds den zentralen `CMS\Http\Client`**: SSRF-, Redirect- und Cache-Dateizugriffe folgen dadurch demselben kleinen Sicherheitsvertrag wie andere Core-Downloads. |
| **2.8.2** | 🔴 fix | Security/EditorJs | **`CMS/core/Services/EditorJs/EditorJsRemoteMediaService.php` kapselt temporäre Download-Dateien jetzt über einen geprüften Temp-Root und bereinigt nur noch eigene Prefix-Dateien**: Remote-Medienimporte vermeiden damit unsaubere Temp-Datei-Pfade und riskantere Cleanup-Kanten. |
| **2.8.2** | 🔴 fix | Security/Marketplace | **`CMS/admin/modules/plugins/PluginMarketplaceModule.php` und `CMS/admin/modules/themes/ThemeMarketplaceModule.php` verwerfen URL-Dot-Segmente, lösen lokale Registry-/Manifest-Pfade nur noch innerhalb des vertrauenswürdigen Katalog-Roots auf und lesen lokale JSON-Dateien begrenzt ein**: Plugin- und Theme-Kataloge bleiben damit enger an einem fail-closed Manifest-Vertrag statt an frei zusammengesetzten Dateipfaden. |
| **2.8.2** | 🔵 docs | Release | **`README.md`, `Changelog.md`, `CMS/core/Version.php` und `CMS/update.json` wurden auf den Release-Stand `2.8.2` synchronisiert**: Dokumentation und Update-Metadaten spiegeln damit denselben Security-Batch wie der Core selbst. |

---

### v2.8.1 — 29. März 2026


| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.8.1** | 🔴 fix | Frontend/Hub-Sites | **`CMS/core/Bootstrap.php` bindet `hub-sites.css` jetzt robust über den Frontend-`head`-Hook ein statt die Styles an eine zu fragile Hub-Request-Erkennung zu koppeln**: Öffentliche Hub-Sites und eingebettete Hub-Inhalte verlieren dadurch nach Routing-/Localization-/Alias-Konstellationen nicht länger ihr komplettes Styling. |
| **2.8.1** | 🔴 fix | Admin/Posts | **`CMS/admin/posts.php` repariert die GridJS-Initialisierung der Beitragsliste nach dem Status-Refactoring**: Ein verrutschter `private`-Zweig im Inline-JavaScript konnte die Listenansicht vollständig leeren; das Status-Badge-Rendering für `draft`, `published`, `scheduled` und `private` ist jetzt wieder stabil verdrahtet. |
| **2.8.1** | 🔴 fix | Core/API | **`CMS/core/Routing/ApiRouter.php`, `CMS/admin/views/posts/edit.php` und `CMS/assets/js/admin-content-editor.js` vereinheitlichen die Beitrags-Statusermittlung auf eine gemeinsame serverseitige Zeitbasis**: Bereits veröffentlichte Beiträge erscheinen im Admin nicht länger fälschlich als „geplant“, nur weil SQL-`NOW()`, PHP-Zeit und lokale Browser-Uhr voneinander abweichen. |
| **2.8.1** | 🔴 fix | Member/Security | **`CMS/member/includes/class-member-controller.php`, `CMS/member/favorites.php` und `CMS/core/Services/MemberService.php` validieren `website`, `social` und `avatar` jetzt serverseitig vor dem Speichern und reichen gespeicherte Member-Favoriten nur noch über bereinigte URLs an die Views weiter**: Der Member-Profilpfad akzeptiert nur noch gültige HTTP(S)-Links bzw. erlaubte öffentliche Medienpfade, verwirft ungültige URLs früh mit klaren Fehlermeldungen und normalisiert öffentliche Profil-/Avatar- sowie Favoriten-Ausgaben konsistenter. |
| **2.8.1** | 🔴 fix | Member/UI | **`CMS/member/dashboard.php` und `CMS/member/partials/header.php` validieren Dashboard-CTA-Links, Plugin-Widget-Links und designnahe Farbwerte jetzt defensiver vor der Ausgabe**: Member-Settings und Plugin-Widgets landen dadurch nicht mehr roh in `href`-Attributen oder CSS-Variablen, sondern folgen einem kleinen HTTP(S)- bzw. Hex-Farb-Vertrag. |
| **2.8.1** | 🔴 fix | Member/Widgets | **`CMS/member/includes/class-member-controller.php` normalisiert Plugin- und Custom-Widget-Payloads jetzt zusätzlich zentral vor der View-Weitergabe**: Titel, Icons, Beschreibungen, Badges, Stats, Links und Farben laufen dadurch auch nach Plugin-Filtern oder Registry-Metadaten erst durch einen kleinen Controller-Vertrag, bevor Templates sie rendern. |
| **2.8.1** | 🔴 fix | Member/Widgets | **`CMS/core/Member/PluginDashboardRegistry.php` und `CMS/admin/modules/member/MemberDashboardModule.php` härten Plugin-Widget-Metadaten jetzt bereits an der Quelle**: Registry-Configs, DB-Overrides und Admin-Preview-Kacheln normalisieren Titel, Icons, Farben, Badges und Admin-Links früher, sodass rohe Plugin-Payloads nicht erst in späteren Controllern oder Views auffallen. |
| **2.8.1** | 🔴 fix | Admin/Themes | **`CMS/admin/theme-editor.php` verwendet für eingebettete Theme-Customizer jetzt denselben CSRF-Vertrag wie die Theme-Formulare selbst und reicht zusätzlich `embedInAdminLayout` an das aktive Theme weiter**: POST-Requests im Theme-Editor werden dadurch nicht mehr vor dem eigentlichen Save-Pfad vom Shell-Guard abgewiesen, und eingebettete Customizer können sauber innerhalb der Admin-Shell rendern statt gegen sie zu arbeiten. |
| **2.8.1** | 🔴 fix | Admin/Menüs | **`CMS/admin/modules/menus/MenuEditorModule.php` und `CMS/assets/js/admin-menu-editor.js` akzeptieren slug-basierte interne Menüziele jetzt wieder ohne führenden Slash, wandeln harmlose no-op-Elternlinks wie `javascript:void(0)` fail-safe in `#` um und normalisieren alles beim Erfassen wie auch beim Speichern zu gültigen internen Pfaden**: Einträge wie `kontakt` oder `unternehmen/team` lösen damit im Menüeditor nicht länger fälschlich den Fehler „gültige URL oder gültiger interner Pfad“ aus, bestehende Elternpunkte ohne echte Ziel-URL blockieren den Save-Pfad nicht mehr still, und neue Container-Punkte können im Editor nun zunächst leer angelegt werden, bevor ihnen Unterpunkte zugeordnet werden. |
| **2.8.1** | 🔴 fix | Core/Content | **Private, veröffentlichte und geplante Beiträge folgen jetzt konsistenter derselben Sichtbarkeitslogik in Admin und öffentlichem Routing**: Listenfilter, Badge-Status, Edit-Hinweise und effektiver Status hängen damit enger an `cms_post_publication_where()` und `cms_post_is_scheduled()` statt an mehreren Schattenpfaden. |
| **2.8.1** | 🔴 fix | SEO/Analytics | **`CMS/admin/modules/seo/AnalyticsModule.php` und `CMS/admin/modules/seo/SeoSuiteModule.php` zählen Beiträge für Analytics-, Sitemap- und News-KPIs jetzt nach öffentlicher Sichtbarkeit statt bloßem Rohstatus `published`**: Geplante Beiträge rutschen dadurch nicht mehr vorzeitig in veröffentlichte SEO-Kennzahlen. |
| **2.8.1** | 🟠 perf | Search/Archive | **Archive, Suche und Post-Sitemaps bleiben auf dem zentralen Veröffentlichungsvertrag konsolidiert**: Die aktuelle Prüfung bestätigt, dass Blog-Archive, Tag-/Kategoriepfade, TNTSearch-Postindex und Post-/News-Sitemaps weiter über `cms_post_publication_where()` laufen und keine abweichende Statuslogik nachziehen mussten. |
| **2.8.1** | 🟢 feat | Admin/Cron | **`CMS/admin/modules/system/SystemInfoModule.php`, `CMS/admin/monitor-cron-runner.php`, `CMS/core/Services/CronRunnerService.php` und `CMS/assets/js/admin-system-cron.js` ergänzen einen echten Cron-Runner im Admin**: Cron-Tasks lassen sich damit direkt aus dem Systemmonitor starten – inklusive Direktlauf, HTTP-Loopback-Test und Ajax-Runner für Mail-Queue- und häufige Cron-Aufgaben. |
| **2.8.1** | 🔴 fix | Core/Routing & Search | **`CMS/core/Routing/ThemeRouter.php`, `CMS/core/Services/SearchService.php`, `CMS/admin/modules/pages/PagesModule.php` und `CMS/admin/modules/posts/PostsModule.php` halten Routing- und Suchdaten jetzt konsistenter über mehrsprachige Felder und Lösch-Hooks frisch**: Archive und Trefferlisten bleiben damit näher an der tatsächlichen Veröffentlichungssicht, während Seiten- und Beitragslöschungen den Suchindex gezielter nachziehen. |
| **2.8.1** | 🔴 fix | Core/Cron & Plugins | **Cron-Hinweise, Diagnosepfade und Plugin-Laufzeitverhalten orientieren sich wieder sauber an der zentralen `cron.php` im CMS-Webroot**: `SystemInfoModule`, `MailQueueService` und die Doku erzeugen damit belastbarere Cron-Kommandos, während `PluginManager.php` verwaiste aktive Plugins im deployten Laufzeitpfad sichtbarer erkennt statt sie still wie geladen wirken zu lassen. |
| **2.8.1** | 🔵 docs | Themes | **`README.md` und `DOC/audit/BEWERTUNG.md` dokumentieren jetzt explizit den Runtime-Vertrag des Theme-Editors**: Admin-Customizer und Theme-Editor arbeiten ausschließlich gegen deployte Laufzeit-Themes unter `CMS/themes/`; Änderungen außerhalb dieses Laufzeitpfads werden erst nach Übernahme oder Deployment wirksam. |
| **2.8.1** | 🔵 docs | Release | **`README.md`, `Changelog.md`, `CMS/core/Version.php` und `CMS/update.json` wurden auf den Release-Stand `2.8.1` synchronisiert**: Dokumentation, sichtbare Core-Version und Update-Metadaten zeigen damit denselben Stand für die Status- und Admin-Konsistenzkorrekturen. |

---

### v2.8.0 — 28. März 2026


| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.8.0** | 🟢 feat | Version | **Stand `2.8.0` als führender Release-Snapshot konsolidiert** |
| **2.8.0** | ⬜ chore | Version | **`CMS/core/Version.php` und `CMS/update.json` wurden auf den dokumentierten Stand `2.8.0` synchronisiert**: Versionsnummer, Release-Datum und Update-Hinweis spiegeln denselben Grundstand wie README und Changelog. |
| **2.8.0** | 🟢 feat | SEO/IndexNow | **`CMS/admin/modules/seo/SeoSuiteModule.php`, `CMS/core/Services/IndexingService.php`, `CMS/admin/views/seo/technical.php` und `CMS/admin/views/seo/sitemap.php` ergänzen eine pflegbare IndexNow-Konfiguration mit API-Key, Root-`.txt`-Auswahl und Validierung im SEO-Bereich**: Der Admin kann den Schlüssel direkt speichern, vorhandene Root-Keydateien im CMS-Webroot auswählen und prüfen, ob Dateiname, Inhalt und öffentliche Bereitstellung zur erwarteten IndexNow-Keydatei passen. |
| **2.8.0** | 🟢 feat | SEO/IndexNow | **`CMS/core/Services/IndexingService.php` und `CMS/admin/views/seo/technical.php` ergänzen ein detailliertes Debug-Interface für Root-Pfad-Kandidaten**: Die SEO-Prüfung zeigt jetzt pro Kandidat Quelle, Status, gefundene `.txt`-Dateien, aufgelöste Pfade sowie konkrete Validierungs- und Fehlergründe an, statt die Root-Datei nur als knappen Einzelstatus zu behandeln. |
| **2.8.0** | 🟢 feat | Admin/Media | **`CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php` und `CMS/assets/js/admin-media-integrations.js` ergänzen Bulk-Löschen und Bulk-Verschieben inklusive Mehrfachauswahl, Zielordner-Select und serverseitiger Pfad-Deduplikation**: Mehrere Medien lassen sich damit in einem Durchlauf bearbeiten, ohne doppelte Kind-/Ordneroperationen oder rohe Freitext-Zielpfade zu riskieren. |
| **2.8.0** | 🔴 fix | SEO/Security | **Die neue IndexNow-Dateiprüfung begrenzt die Root-Datei zusätzlich über Lesbarkeit und eine kleine Größenobergrenze**: Unlesbare oder ungewöhnlich große `.txt`-Dateien werden nicht blind als valide Keydatei akzeptiert, bevor ihre Inhalte mit dem API-Key verglichen werden. |
| **2.8.0** | 🔴 fix | SEO/Security | **`CMS/core/Services/IndexingService.php` hält die Keydatei-Prüfung explizit fail-closed**: Wenn Lesbarkeit oder Dateigröße scheitern oder sich die Größe nicht sicher bestimmen lässt, bleibt der Dateilesepfad geschlossen und liefert stattdessen einen klaren Validierungsfehler zurück. |
| **2.8.0** | 🔴 fix | Core/Feeds | **`CMS/core/Services/FeedService.php` folgt Redirects beim nativen Feed-Abruf nur noch manuell und mit erneuter URL-/Host-Prüfung pro Hop**: 30x-Ziele werden relativ oder absolut sauber aufgelöst, erneut durch `normalizeFeedUrl()` geprüft und bei unzulässigen Hosts, fehlender `Location` oder zu vielen Redirects verworfen. |
| **2.8.0** | 🔴 fix | Security/SSRF | **cURL-basierte Feed-Fetches pinnen Verbindungen an zuvor geprüfte DNS-Ziele**: Der Feed-Service nutzt pro Request ein geprüftes `CURLOPT_RESOLVE`-Target und reduziert damit Redirect-/DNS-Rebinding-Restkanten deutlich gegenüber implizitem Auto-Following. |
| **2.8.0** | 🔴 fix | Core/Media | **`CMS/core/Services/Media/MediaRepository::isSystemPath()` klassifiziert Member-erstellte Unterordner nicht mehr fälschlicherweise als Systemordner**: Bisher galten alle Pfade unterhalb von `member/` (z. B. `member/user-1/fotos`) als System-Pfad und erhielten deshalb kein Aktions-Dropdown. Die neue Logik schützt nur die Root-Ordner selbst (Ebene 1) und die direkten User-Roots `member/user-X` (Ebene 2); Unterordner von Usern (Ebene 3+) sind nicht mehr geschützt und zeigen korrekt Umbenennen/Verschieben/Löschen an. |
| **2.8.0** | 🔴 fix | Admin/Media JS | **`CMS/assets/js/admin-media-integrations.js` ergänzt einen Pending-Trigger-Fallback für Bootstrap-Modals**: Bootstrap 5 setzt `event.relatedTarget` nicht immer, wenn ein Modal-Trigger innerhalb eines sich schließenden Dropdown-Menüs liegt. Der neue Click-Listener auf `.js-media-open-rename` und `.js-media-open-move` speichert den auslösenden Button synchron und reicht ihn an `show.bs.modal` weiter, falls `event.relatedTarget` `null` ist. Umbenennen und Verschieben befüllen das Modal damit zuverlässig mit dem richtigen Pfad. |
| **2.8.0** | 🔴 fix | Member/Media JS | **`CMS/assets/js/member-dashboard.js` erhält denselben Pending-Trigger-Fallback für Member-Medien**: `.js-member-media-open-rename` und `.js-member-media-open-move` setzen den gespeicherten Trigger nach dem Konsumieren auf `null` zurück, um Überbleibsel zwischen aufeinanderfolgenden Modal-Öffnungen zu verhindern. |
| **2.8.0** | 🔴 fix | Review | **Die SEO-, Feed- und Medienänderungen wurden erneut auf Fehler, Best Practice und Security geprüft**: keine neuen Editorfehler, keine PHP-Lint-Fehler; Root-Dateien, Redirects und Medienpfade hängen enger an kleinen, kontrollierten Verträgen statt an impliziten Defaults. |
| **2.8.0** | 🎨 style | Admin/Media | **`CMS/admin/views/media/library.php` ersetzt die platzfressenden Inline-Formulare durch kompakte Aktions-Dropdowns und zentrale Rename-/Move-Modale**: Die Medienbibliothek bleibt dadurch übersichtlicher, ohne Delete-, Rename- oder Move-Verträge wieder an fragile UI-Sonderpfade zu hängen. |
| **2.8.0** | 🎨 style | Member/Media | **`CMS/member/includes/class-member-controller.php`, `CMS/member/media.php` und `CMS/assets/js/member-dashboard.js` ziehen die Member-Medienaktionen ebenfalls in kompakte Dropdowns und Modale mit vorbereiteten Zielordnern**: Der persönliche Upload-Bereich bleibt damit schlanker bedienbar und behält trotzdem seine sauberen Root-/CSRF-Grenzen. |
| **2.8.0** | 🔵 docs | README | **`README.md` wurde deutlich ausgebaut und zweisprachig auf Deutsch und Englisch strukturiert**: Feature- und Technik-Badges, kompakte Audit-Bewertung ohne Tiefendetails, eine öffentliche 5×5-Screenshot-Galerie sowie klarere Architektur-, Dokumentations- und Schnellstartabschnitte machen den Projekteinstieg sichtbarer. |
| **2.8.0** | 🔵 docs | README | **`README.md` beschreibt zusätzlich den SEO-Workflow für IndexNow-Key- und Root-`.txt`-Prüfung**: Der Projektüberblick nennt die Funktion sowohl im deutschen als auch im englischen Bereich. |
| **2.8.0** | 🔵 docs | README | **Die Repository-Struktur im README wurde am Test-Pfad auf `tests/` korrigiert und der SEO-Hinweis um die Guard-Logik ergänzt**: Die Doku beschreibt damit den aktuellen Workspace präziser und nennt den sicheren Prüfpfad ohne verfrühte Dateizugriffe. |
| **2.8.0** | 🔵 docs | Assets | **`DOC/ASSETS_OwnAssets.md` fokussiert den Eigenersatz jetzt auf den aktiven Restbestand unter `CMS/assets/`**: `gridjs`, `photoswipe` und `melbahja-seo` sind als konkrete Kandidaten mit Prioritäten, Zielmodulen und Migrationslogik dokumentiert, während sicherheitskritische Bibliotheken ausdrücklich als Kapselungs- statt Eigenbau-Kandidaten markiert bleiben. |

---

### v2.7.375 — 27. März 2026 · Folge-Batch 457, Medien lassen sich jetzt robust umbenennen und verschieben

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.375** | 🔴 fix | Admin/Media | **`CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php` und `CMS/admin/views/media/library.php` verdrahten Umbenennen und Verschieben jetzt als echte POST-Aktionen bis in den Media-Service durch**: Dateien und Ordner lassen sich damit ohne JS-Sonderlogik belastbar umbenennen oder in andere Ordner verschieben. |
| **2.7.375** | 🔴 fix | Member/Media | **`CMS/member/includes/class-member-controller.php` und `CMS/member/media.php` unterstützen im persönlichen Upload-Bereich jetzt ebenfalls Rename-/Move-Aktionen über serverseitige Form-Posts**: Der Member-Bereich schließt damit denselben stillen No-op-Typ aus, der zuvor schon bei Löschaktionen auffiel, und bleibt bei Medienaktionen vollständig auf echten Request-/CSRF-Verträgen. |

---

### v2.7.374 — 27. März 2026 · Folge-Batch 456, Admin-Medienlöschung läuft jetzt robust ohne JS-Pflicht

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.374** | 🔴 fix | Admin/Media | **`CMS/admin/views/media/library.php` nutzt für Datei- und Ordnerlöschung jetzt echte POST-Formulare mit Confirm statt rein JS-abhängigen Delete-Buttons**: Die Medienbibliothek löscht damit wieder belastbar, selbst wenn der Button-Handler oder das Medien-JavaScript nicht sauber initialisiert werden. |

---

### v2.7.373 — 27. März 2026 · Folge-Batch 455, Member-Medienbereich zieht Ordnerpfade, Delete-Flow und Script-Härtung nach

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.373** | 🔴 fix | Member/Media | **`CMS/member/includes/class-member-controller.php` und `CMS/member/media.php` unterstützen jetzt konsistente Pfadnavigation, Breadcrumbs sowie Datei- und Ordnerlöschung im persönlichen Upload-Bereich**: Member-Aktionen bleiben dadurch innerhalb des eigenen Wurzelpfads, kehren sauber in den aktuellen Ordner zurück und bieten wieder belastbare Datei-/Ordner-Aktionen. |
| **2.7.373** | 🔴 fix | Security/UI | **`CMS/assets/js/cookieconsent-init.js`, `CMS/core/Services/CookieConsentService.php`, `CMS/assets/js/admin-media-integrations.js`, `CMS/assets/js/member-dashboard.js` und `CMS/core/Services/FeedService.php` wurden nach dem Review zusätzlich gehärtet**: Escape-Fehler, DOM-XSS-Risiken, fehlendes `Secure`-Cookie-Flag und ein unnötiger `sha1`-Cache-Key wurden bereinigt. |

---

### v2.7.372 — 27. März 2026 · Folge-Batch 454, Fremd-Assets für Consent, Uploads und Feeds durch native 365CMS-Pfade ersetzt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.372** | 🟡 refactor | Core/Media | **`CMS/core/Services/CookieConsentService.php`, `CMS/assets/js/cookieconsent-init.js`, `CMS/assets/js/admin-media-integrations.js`, `CMS/member/media.php` und `CMS/core/Services/FeedService.php` ersetzen CookieConsent, FilePond, elFinder und SimplePie in den aktiven Laufzeitpfaden durch native 365CMS-Implementierungen**: Consent-Banner, Media-Picker, Member-Uploads und Feed-Parsing hängen damit enger an internen APIs, weniger an externen Runtime-Abhängigkeiten und robuster an klaren Verträgen. |

---

### v2.7.371 — 27. März 2026 · Folge-Batch 453, Gruppen-Asset öffnet Modal und blockt Doppel-Submits robuster

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.371** | 🔴 fix | Admin/UI | **`CMS/assets/js/admin-user-groups.js` füllt Gruppen-Modale jetzt über `show.bs.modal`, setzt Submit-Pending-State und blockt doppelte Delete-/Save-Aktionen robuster**: Die Gruppenverwaltung reagiert dadurch stabiler auf Öffnen, Bearbeiten und wiederholte Klicks. |

---

### v2.7.370 — 27. März 2026 · Folge-Batch 452, Gruppen-View verdrahtet Modal- und Formularziele belastbarer

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.370** | 🎨 style | Admin/UI | **`CMS/admin/views/users/groups.php` ergänzt Bootstrap-Modal-Trigger sowie explizite Form-Actions für Save- und Delete-Requests**: Neue Gruppen und Bearbeitungen hängen damit weniger an stillen JS-Annahmen und feuern zuverlässiger an die richtige Route. |

---

### v2.7.369 — 27. März 2026 · Folge-Batch 451, User-Form spiegelt Eingabegrenzen früher im UI

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.369** | 🎨 style | Admin/UI | **`CMS/admin/views/users/edit.php` ergänzt Passwort-, Username- und Feldlängen-Hinweise direkt im Formular**: Ungültige Benutzerdaten werden dadurch früher abgefangen, bevor der Save-Pfad überhaupt ins Backend läuft. |

---

### v2.7.368 — 27. März 2026 · Folge-Batch 450, UsersModule staffelt Save-Fehler und Rückgabekanten robuster

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.368** | 🔴 fix | Admin/Users | **`CMS/admin/modules/users/UsersModule.php` normalisiert Save-Eingaben defensiver, protokolliert Ausnahmen und behandelt fehlende Erfolgs-/ID-Rückgaben explizit als Fehler**: Neue Benutzer bleiben dadurch nachvollziehbarer speicherbar, statt in einem generischen Catch-All zu versanden. |

---

### v2.7.367 — 27. März 2026 · Folge-Batch 449, Media-Settings melden unveränderte Saves verständlicher

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.367** | 🔴 fix | Admin/Media | **`CMS/admin/modules/media/MediaModule.php` erklärt unveränderte Settings-Saves jetzt expliziter als bestätigte Bestandswerte statt als rätselhafte Nicht-Änderung**: Die Medienverwaltung bleibt damit für Admin-Nutzer klarer lesbar, wenn ein Save keine effektiven Wertänderungen enthält. |

---

### v2.7.366 — 27. März 2026 · Folge-Batch 448, Marketplace-Asset blockt doppelte Install-Submits robuster ab

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.366** | 🔴 fix | Admin/UI | **`CMS/assets/js/admin-plugin-marketplace.js` schützt Install-Formulare jetzt zusätzlich über einen Form-Pending-State samt `aria-disabled`**: Mehrfachklicks auf den Install-Button feuern dadurch robuster nicht erneut los. |

---

### v2.7.365 — 27. März 2026 · Folge-Batch 447, Marketplace-View nennt Archiv-Endungen als weiteres Risiko deutlicher

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.365** | 🎨 style | Admin/UI | **`CMS/admin/views/plugins/marketplace.php` spiegelt erlaubte Archiv-Endungen und entsprechende Warnhinweise jetzt expliziter im Admin**: Auto-Install-Risiken bleiben damit sichtbarer statt nur indirekt über fehlgeschlagene Installationen aufzufallen. |

---

### v2.7.364 — 27. März 2026 · Folge-Batch 446, Marketplace-Modul verlangt erlaubte Archiv-Endungen für Auto-Install

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.364** | 🔴 fix | Admin/Plugins | **`CMS/admin/modules/plugins/PluginMarketplaceModule.php` verlangt für Auto-Installationen jetzt zusätzlich erlaubte Archiv-Endungen und trägt den Zustand explizit in Daten- und Fehlerkontext ein**: Download- und Archivpfade hängen damit enger an einem klaren Paketvertrag. |

---

### v2.7.363 — 27. März 2026 · Folge-Batch 445, Marketplace-Entry lehnt überlange Slugs sauberer ab

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.363** | 🔴 fix | Admin/Plugins | **`CMS/admin/plugin-marketplace.php` ergänzt spezifischere Payload-Fehlercodes und weist überlange Slugs explizit zurück statt sie nur still zu kürzen**: Entry-nahe Install-Requests bleiben dadurch nachvollziehbarer. |

---

### v2.7.362 — 27. März 2026 · Audit-Batch 444, Font-Manager-Entry validiert Save- und Google-Font-Payloads strenger

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.362** | 🔴 fix | Admin/Themes | **`CMS/admin/font-manager.php` prüft Save-Requests jetzt explizit auf numerische Font-Size-/Line-Height-Werte und weist zu lange Google-Font-Namen sauber zurück**: Entry-nahe Font-Änderungen hängen damit enger an einem kleinen Request- und Fehlervertrag statt stiller Clamp-/Trim-Pfade. |

---

### v2.7.361 — 27. März 2026 · Audit-Batch 443, Theme-Explorer-Entry staffelt Datei- und Inhaltsgrenzen präziser

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.361** | 🔴 fix | Admin/Themes | **`CMS/admin/theme-explorer.php` ergänzt Write-Capability-Guard, begrenzt Dateipfade und Editor-Inhalte expliziter und gibt Payload-Fehler mit spezifischeren Codes zurück**: Save-Requests hängen damit enger an einem kleinen Entry- und Report-Vertrag. |

---

### v2.7.360 — 27. März 2026 · Audit-Batch 442, Missing-Customizer-View nennt sichere nächste Schritte deutlicher

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.360** | 🎨 style | Admin/UI | **`CMS/admin/views/themes/customizer-missing.php` spiegelt Reason-Hinweis, erwarteten Pfad und sicheren Fallback jetzt deutlicher im Admin**: fehlende oder unsichere Customizer-Dateien bleiben damit früher einordenbar statt nur als knappe Warnung stehenzubleiben. |

---

### v2.7.359 — 27. März 2026 · Audit-Batch 441, Theme-Editor-Entry staffelt Reason-Hinweise präziser

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.359** | 🔴 fix | Admin/Themes | **`CMS/admin/theme-editor.php` ergänzt strukturierte Reason-Hints pro Fallback-Code und reicht den sicheren Fallback-Kontext konsistenter an die View weiter**: Customizer-Fallbacks hängen damit enger an einem kleinen Entry- und View-Vertrag. |

---

### v2.7.358 — 27. März 2026 · Audit-Batch 440, Theme-Explorer-Asset blockt Mehrfach-Submits robuster ab

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.358** | 🔴 fix | Admin/UI | **`CMS/assets/js/admin-theme-explorer.js` schützt Editor-Submits jetzt über einen gemeinsamen Pending-Zustand samt `aria-disabled`**: Speichern per Button oder `Ctrl+S` feuert dadurch seltener mehrfach aus schnellen Folge-Aktionen. |

---

### v2.7.357 — 27. März 2026 · Audit-Batch 439, Theme-Explorer-View nennt Limits und erlaubte Endungen expliziter

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.357** | 🎨 style | Admin/UI | **`CMS/admin/views/themes/editor.php` spiegelt Baumtiefe, Verzeichnislimit, erlaubte Endungen, Skip-Segmente und Browser-Limits jetzt deutlicher im Admin**: Explorer-Grenzen bleiben damit früher sichtbar statt nur implizit im Modul hinterlegt. |

---

### v2.7.356 — 27. März 2026 · Audit-Batch 438, ThemeEditorModule staffelt Validierungsfehler und Constraints enger

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.356** | 🔴 fix | Admin/Themes | **`CMS/admin/modules/themes/ThemeEditorModule.php` liefert für Browser-Editor-Validierungen jetzt strukturierte Fehlerresultate, ergänzt Endungs-/Skip-Constraints und sanitisiert Exception-Kontext enger**: Save- und Tree-Pfade hängen damit enger an einem kleinen Modul- und Report-Vertrag. |

---

### v2.7.355 — 27. März 2026 · Audit-Batch 437, Legal-Sites-Asset blockt Mehrfach-Submits robuster ab

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.355** | 🔴 fix | Admin/UI | **`CMS/assets/js/admin-legal-sites.js` schützt Post-Formulare jetzt über einen gemeinsamen Pending-Zustand samt `aria-disabled`**: Speichern, Generieren und Seitenerstellung feuern dadurch seltener mehrfach aus schnellen Folge-Klicks. |

---

### v2.7.354 — 27. März 2026 · Audit-Batch 436, Legal-View nennt Generator-Typen und Eingabegrenzen expliziter

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.354** | 🎨 style | Admin/UI | **`CMS/admin/views/legal/sites.php` spiegelt Generator-Bereiche, Vorlagentypen, Feature-Toggles und HTML-Grenzen jetzt direkter im Admin**: Rechtstext- und Generator-Grenzen bleiben damit früher sichtbar statt nur implizit im Modul hinterlegt. |

---

### v2.7.353 — 27. März 2026 · Audit-Batch 435, LegalSitesModule staffelt Save-, Profil- und Sammelseiten-Kontext enger

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.353** | 🔴 fix | Admin/Legal | **`CMS/admin/modules/legal/LegalSitesModule.php` ergänzt Generator- und Profil-Constraints, zählt geänderte Schlüssel nachvollziehbarer und reichert Sammel-Seitenläufe sowie Fehlerdetails strukturierter an**: Save-, Profil- und Generate-Pfade hängen damit enger an einem kleinen Modul- und Report-Vertrag. |

---

### v2.7.352 — 27. März 2026 · Audit-Batch 434, Font-Manager-Asset blockt Mehrfach-Submit und Delete-Replays robuster

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.352** | 🔴 fix | Admin/UI | **`CMS/assets/js/admin-font-manager.js` sperrt Formulare und Delete-Aktionen jetzt über einen gemeinsamen Pending-Zustand samt `aria-disabled` und Submit-Guard**: Scan-, Save-, Download- und Delete-Requests feuern dadurch seltener doppelt aus hektischem Mehrfachklicken. |

---

### v2.7.351 — 27. März 2026 · Audit-Batch 433, Font-View nennt Scan- und Remote-Schutzgrenzen deutlich früher

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.351** | 🎨 style | Admin/UI | **`CMS/admin/views/themes/fonts.php` zeigt Scan-Gesamtlimit, Einzeldatei-Limit, erlaubte Hosts sowie Scan-Endungen und Skip-Segmente jetzt als vorbereiteten Hinweisblock an**: Self-Hosting- und Scan-Grenzen bleiben damit früher sichtbar statt nur implizit im Modul zu wohnen. |

---

### v2.7.350 — 27. März 2026 · Audit-Batch 432, FontManagerModule staffelt Save-, Scan- und Bulk-Download-Kontext enger

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.350** | 🔴 fix | Admin/Themes | **`CMS/admin/modules/themes/FontManagerModule.php` ergänzt zusätzliche Host-/Scan-/Download-Constraints, gibt Save- und Delete-Erfolge detailreicher zurück und modelliert Sammeldownload-Fehler strukturierter reportbar**: Font-Scan-, Self-Hosting- und Settings-Pfade hängen damit enger an einem kleinen Modul- und Report-Vertrag. |

---

### v2.7.349 — 27. März 2026 · Audit-Batch 431, Marketplace-View zeigt Hosts sowie Manifest- und Archivgrenzen deutlicher an

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.349** | 🎨 style | Admin/UI | **`CMS/admin/views/plugins/marketplace.php` nennt erlaubte Hosts sowie Manifest- und Archivgrenzen jetzt direkt über dem Suchbereich**: Remote- und Auto-Install-Limits bleiben dadurch im Marketplace früher sichtbar statt nur implizit in Fehlerpfaden. |

---

### v2.7.348 — 27. März 2026 · Audit-Batch 430, PluginMarketplaceModule staffelt Remote-Fallbacks und Install-Kontext sichtbarer

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.348** | 🔴 fix | Admin/Plugins | **`CMS/admin/modules/plugins/PluginMarketplaceModule.php` ergänzt für Install-Erfolge jetzt Zielpfad und SHA-256-Verifizierungsstatus und reicht Installer-Ergebnisdaten strukturierter in Fehlerkontexte weiter**: Auto-Installationen bleiben damit nachvollziehbarer, wenn der Update-Service scheitert oder erfolgreich war. |

---

### v2.7.347 — 27. März 2026 · Audit-Batch 429, PluginMarketplaceModule zeigt Remote-Registry-Fehler und Cache-Fallbacks präziser an

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.347** | 🟡 refactor | Admin/Plugins | **`CMS/admin/modules/plugins/PluginMarketplaceModule.php` modelliert Remote-Registry-Ladevorgänge jetzt mit Fehler- und Detailkontext statt stummem Leer-Array-Fallback**: Cache-, Local- und None-Pfade können dadurch HTTP-/Content-Type-/Eintragsprobleme expliziter an den Marketplace weiterreichen. |

---

### v2.7.346 — 27. März 2026 · Audit-Batch 428, Media-Bibliothek spiegelt Finder-Grenzen und Upload-Limits direkter

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.346** | 🎨 style | Admin/UI | **`CMS/admin/views/media/library.php` zeigt Upload-, Such- und Ordnergrenzen jetzt explizit im Bibliothekskopf und bindet Formularfelder an Modul-Constraints**: Die Medienbibliothek hält Finder-Limits damit sichtbarer im UI statt in verstreuten lokalen Maximalwerten. |

---

### v2.7.345 — 27. März 2026 · Audit-Batch 427, Media-Kategorien spiegeln Name- und Slug-Grenzen direkter

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.345** | 🎨 style | Admin/UI | **`CMS/admin/views/media/categories.php` zeigt Kategorie-Limits jetzt direkt als Hinweis an und bindet Formularlängen an den Modulvertrag**: Kategorien behalten damit dieselben Eingabegrenzen im UI wie im Backend. |

---

### v2.7.344 — 27. März 2026 · Audit-Batch 426, Media-Settings nutzen Modul-Constraints statt lokaler Schattenwerte

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.344** | 🟡 refactor | Admin/Media | **`CMS/admin/views/media/settings.php` verwendet Upload-, Qualitäts- und Dimensionsgrenzen jetzt direkt aus dem Modulvertrag und reduziert lokale Default-/Max-Werte**: Die Settings-View bleibt damit näher an derselben Validierungslogik wie der Serverpfad. |

---

### v2.7.343 — 27. März 2026 · Audit-Batch 425, MediaModule liefert Constraints und Reportkontext strukturierter

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.343** | 🔴 fix | Admin/Media | **`CMS/admin/modules/media/MediaModule.php` ergänzt Finder-, Kategorie- und Settings-Constraints sowie `details`/`error_details` für Medien-Aktionen und speichert geänderte Setting-Felder im Fehlerkontext**: Upload-, Delete-, Kategorie- und Save-Pfade hängen damit enger an einem kleinen Modul- und Report-Vertrag. |

---

### v2.7.342 — 27. März 2026 · Audit-Batch 424, Media-Entry staffelt Requestfehler reportbar an den Shell-Vertrag an

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.342** | 🔴 fix | Admin/Media | **`CMS/admin/media.php` normalisiert unbekannte Aktionen, Berechtigungsfehler und ungültige Media-Payloads jetzt als strukturierte Failure-Rückgaben mit Report-Kontext**: Entry-nahe Ablehnungen landen damit konsistenter im gemeinsamen Admin-Flash-/Report-Pfad. |

---

### v2.7.341 — 27. März 2026 · Audit-Batch 423, Theme-Explorer-View zeigt Editor-Limits und Dateistatus klarer an

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.341** | 🎨 style | Admin/UI | **`CMS/admin/views/themes/editor.php` nennt Editor-Limits jetzt zusätzlich direkt im Dateibaum und im Datei-Kopf**: Theme-Dateien bleiben damit hinsichtlich Bearbeitungsgrenze und Dateistatus transparenter im Explorer. |

---

### v2.7.340 — 27. März 2026 · Audit-Batch 422, ThemeEditorModule liefert Fehler- und Save-Kontext strukturierter

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.340** | 🔴 fix | Admin/Themes | **`CMS/admin/modules/themes/ThemeEditorModule.php` gibt Fehler jetzt mit `details`, `error_details` und `report_payload` zurück und ergänzt Save-Erfolge um Dateikontext**: Der Explorer hängt Fehler- und Save-Pfade dadurch enger an den gemeinsamen Admin-Vertrag. |

---

### v2.7.339 — 27. März 2026 · Audit-Batch 421, Theme-Explorer-Entry staffelt Payloadfehler an Report-Vertrag an

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.339** | 🔴 fix | Admin/Themes | **`CMS/admin/theme-explorer.php` normalisiert fehlerhafte Save-POSTs jetzt als strukturierte Failure-Rückgaben mit Detail- und Report-Kontext**: Ungültige Datei- oder Aktions-Payloads landen damit konsistenter im gemeinsamen Shell-/Flash-Pfad. |

---

### v2.7.338 — 27. März 2026 · Audit-Batch 420, Theme-Editor-Fallback zeigt Grundcode und Erwartungspfad deutlicher an

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.338** | 🎨 style | Admin/UI | **`CMS/admin/views/themes/customizer-missing.php` zeigt Reason-Code, Theme-Slug und erwarteten Customizer-Pfad jetzt expliziter an**: Fehlende oder unsichere Customizer-Dateien bleiben dadurch für Theme-Entwickler nachvollziehbarer. |

---

### v2.7.337 — 27. März 2026 · Audit-Batch 419, Theme-Editor-Entry bereitet Fallback-Status strukturierter auf

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.337** | 🟡 refactor | Admin/Themes | **`CMS/admin/theme-editor.php` liefert Reason-Code, erwarteten Customizer-Pfad und Constraints jetzt vorbereitet an den Fallback**: Der Theme-Editor-Fallback hängt damit sichtbarer an einem kleinen Runtime-State statt an bloßen Freitextgründen. |

---

### v2.7.336 — 27. März 2026 · Audit-Batch 418, Legal-Sites-View spiegelt Status und Eingabegrenzen direkter

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.336** | 🎨 style | Admin/UI | **`CMS/admin/views/legal/sites.php` nutzt vorbereitete Kennzahlen und zeigt Eingabegrenzen für HTML- und Profilfelder jetzt expliziter an**: Generator- und Eingabezustände bleiben damit früher sichtbar und hängen nicht nur implizit an Formularen. |

---

### v2.7.335 — 27. März 2026 · Audit-Batch 417, LegalSitesModule staffelt Fehler- und Statusdaten enger

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.335** | 🔴 fix | Admin/Legal | **`CMS/admin/modules/legal/LegalSitesModule.php` liefert Save-, Validierungs- und Generatorfehler jetzt mit `details`, `error_details` und `report_payload` und bereitet zusätzliche Status-/Constraint-Daten für die View vor**: Legal-Sites hängt damit Fehler und UI-Kontext enger an einem kleinen Modulvertrag. |

---

### v2.7.334 — 27. März 2026 · Audit-Batch 416, Legal-Sites-Entry staffelt Fehlerrückgaben an Report-Vertrag an

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.334** | 🔴 fix | Admin/Legal | **`CMS/admin/legal-sites.php` gibt Berechtigungs- und Requestfehler jetzt als strukturierte Failure-Rückgaben mit Detail- und Report-Kontext an die Section-Shell zurück**: Entry-nahe Fehlerpfade bleiben damit konsistenter zum restlichen gehärteten Admin-Rahmen. |

---

### v2.7.333 — 27. März 2026 · Audit-Batch 415, Font-View zeigt Asset-Status lokaler Schriften transparenter an

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.333** | 🎨 style | Admin/UI | **`CMS/admin/views/themes/fonts.php` zeigt für lokale Schriften jetzt Dateigröße, CSS-Pfad und Asset-Status sichtbarer an**: Fehlende Font- oder CSS-Dateien bleiben damit im Admin nicht länger nur implizite Backend-Zustände. |

---

### v2.7.332 — 27. März 2026 · Audit-Batch 414, FontManagerModule gibt Report-Kontext und Font-Asset-Metadaten strukturierter aus

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.332** | 🔴 fix | Admin/Themes | **`CMS/admin/modules/themes/FontManagerModule.php` liefert Download-/Delete-Fehler jetzt mit `error_details` und `report_payload` und bereitet lokale Font-Asset-Metadaten serverseitig auf**: Font-Fehler und fehlende Assets hängen damit sichtbarer an einem kleineren Modulvertrag statt an losen Meldetexten. |

---

### v2.7.331 — 27. März 2026 · Audit-Batch 413, Font-Manager-Entry staffelt Payload-Fehler mit Report-Kontext

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.331** | 🔴 fix | Admin/Themes | **`CMS/admin/font-manager.php` gibt Berechtigungs- und Payloadfehler jetzt mit strukturiertem Detail- und Report-Kontext an die Section-Shell zurück**: Ungültige Font-Aktionen bleiben damit im Admin nachvollziehbarer und reportbar. |

---

### v2.7.330 — 27. März 2026 · Audit-Batch 412, Marketplace-View spiegelt Auto-Install-Limits direkter

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.330** | 🎨 style | Admin/UI | **`CMS/admin/views/plugins/marketplace.php` nennt Paket- und Registry-Limits jetzt expliziter direkt über der Suche**: Auto-Install-Grenzen und Cache-Verhalten bleiben dadurch früher sichtbar statt implizit aus Fehlerpfaden. |

---

### v2.7.329 — 27. März 2026 · Audit-Batch 411, PluginMarketplaceModule gibt Installfehler strukturierter und reportbar zurück

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.329** | 🔴 fix | Admin/Plugins | **`CMS/admin/modules/plugins/PluginMarketplaceModule.php` versieht Installationsfehler jetzt mit `details`, `error_details` und `report_payload` und liefert zusätzliche Marketplace-Constraints an die View**: Remote-, Paket- und Zielpfadfehler landen damit klarer im gemeinsamen Admin-Vertrag. |

---

### v2.7.328 — 27. März 2026 · Audit-Batch 410, Plugin-Marketplace-Entry normalisiert Fehler mit Report-Kontext

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.328** | 🔴 fix | Admin/Plugins | **`CMS/admin/plugin-marketplace.php` staffelt Berechtigungs-, Payload- und Katalog-Slug-Fehler jetzt über einen strukturierten Failure-Vertrag**: Der Marketplace-Entry bleibt damit näher an demselben Report- und Detailpfad wie andere gehärtete Admin-Bereiche. |

---

### v2.7.327 — 27. März 2026 · Audit-Batch 409, Section-Shell reicht Alert- und Report-Kontext vollständig durch

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.327** | 🟡 refactor | Admin/Core | **`CMS/admin/partials/section-page-shell.php` bewahrt Alert-Typen sowie `error_details` und `report_payload` jetzt auch über Redirect- und Inline-Pfade hinweg**: Strukturierte Fehler- und Report-Hinweise kommen damit endlich vollständig in `flash-alert.php` an, statt unterwegs kastriert zu werden. |

---

### v2.7.326 — 27. März 2026 · Audit-Batch 408, Marketplace-View zeigt Host-, Paket- und Hash-Kontext klarer an

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.326** | 🎨 style | Admin/UI | **`CMS/admin/views/plugins/marketplace.php` spiegelt Download-Host, Paketgröße, gekürzte SHA-256 und Auto-Install-Sperrgründe jetzt direkter in den Karten**: Installierbarkeit bleibt damit im Marketplace nachvollziehbarer, statt nur implizit aus einem Button-Zustand ableitbar zu sein. |

---

### v2.7.325 — 27. März 2026 · Audit-Batch 407, PluginMarketplaceModule staffelt Paketgröße und Host-Vertrag enger

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.325** | 🔴 fix | Admin/Plugins | **`CMS/admin/modules/plugins/PluginMarketplaceModule.php` normalisiert Paketgrößen jetzt explizit, sperrt übergroße Pakete für Auto-Installationen und führt Host-/Paket-/Hash-Metadaten klarer im ViewModel**: Der Marketplace hängt Auto-Install-Pfade damit sichtbarer an einem engeren Paket- und Quellenvertrag. |

---

### v2.7.324 — 27. März 2026 · Audit-Batch 406, Font-Manager-View zeigt Remote-Download-Grenzen deutlicher an

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.324** | 🎨 style | Admin/UI | **`CMS/admin/views/themes/fonts.php` zeigt Remote-Datei- und Gesamtgrößenlimits für Self-Hosting-Downloads jetzt direkt im Admin**: Nutzer sehen die Schutzgrenzen früher, statt erst nach einem abgewiesenen Download im Rückweg überrascht zu werden. |

---

### v2.7.323 — 27. März 2026 · Audit-Batch 405, FontManagerModule härtet Remote-Downloads und Cleanup weiter

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.323** | 🔴 fix | Admin/Themes | **`CMS/admin/modules/themes/FontManagerModule.php` begrenzt Remote-Font-Dateien jetzt zusätzlich über Gesamtvolumen, prüft geladene WOFF/TTF/OTF-Header und räumt bereits gespeicherte Teil-Downloads bei CSS-/Persistenzfehlern wieder auf**: Self-Hosting-Downloads hängen damit enger an einem sicheren Remote-/Datei-Vertrag statt an stillen Teilzuständen. |

---

### v2.7.322 — 27. März 2026 · Audit-Batch 404, Media-Kategorien folgen dem Modulvertrag für System-Slugs

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.322** | 🟡 refactor | Admin/Media | **`CMS/admin/views/media/categories.php` übernimmt die Liste geschützter System-Kategorien jetzt aus `MediaModule` statt einen lokalen Slug-Katalog zu duplizieren**: Die Kategorien-Ansicht bleibt damit näher an derselben Löschgrenze wie das Backend selbst. |

---

### v2.7.321 — 27. März 2026 · Audit-Batch 403, Media-Bibliothek rendert vorbereitete Zustände statt lokaler Helper

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.321** | 🎨 style | Admin/UI | **`CMS/admin/views/media/library.php` verwendet Breadcrumbs, Navigations-URLs, Ordner-/Datei-Metadaten und Größenformate jetzt direkt aus dem Modulvertrag**: Das Template verliert weitere lokale Zustands- und Pfadhelper und bleibt robuster gegen künftige Medien-Änderungen. |

---

### v2.7.320 — 27. März 2026 · Audit-Batch 402, Media-Uploads geben strukturiertere Fehlerdetails zurück

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.320** | 🔴 fix | Admin/Media | **`CMS/admin/media.php` gibt Upload-Fehler jetzt zusätzlich als Detail-Liste und – sofern vorhanden – mit Report-Payload an die Section-Shell weiter**: Mehrfachfehler oder Service-Probleme landen dadurch nicht mehr nur als zusammengedrückter Satz im Flash-Hinweis. |

---

### v2.7.319 — 27. März 2026 · Audit-Batch 401, MediaModule bereitet Bibliotheks-ViewModels serverseitig auf

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.319** | 🟡 refactor | Admin/Media | **`CMS/admin/modules/media/MediaModule.php` liefert Bibliothekszustand, Breadcrumbs, Ordner-/Datei-ViewModels, Kategorieoptionen und Kennzahlen jetzt vorbereitet an die View**: Die Medienbibliothek hängt damit sichtbarer an einem kleineren Modul-/View-Vertrag statt an verstreuten Template-Helfern. |

---

### v2.7.318 — 27. März 2026 · Audit-Batch 400, Font-Manager-View zeigt Scan-Quelle und Stand sichtbar an

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.318** | 🎨 style | Admin/UI | **`CMS/admin/views/themes/fonts.php` zeigt Scan-Quelle und Zeitstempel jetzt direkt neben den Scan-Kennzahlen an**: Admin-Nutzer sehen damit sofort, ob Daten frisch oder aus dem Cache stammen, statt über wiederholte Scans raten zu müssen. |

---

### v2.7.317 — 27. März 2026 · Audit-Batch 399, FontManagerModule wiederverwendet Theme-Scans und strukturiert Rückgaben klarer

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.317** | 🔴 fix | Admin/Themes | **`CMS/admin/modules/themes/FontManagerModule.php` cached Theme-Scan-Ergebnisse jetzt themebezogen im Settings-Speicher und nutzt sie für Folgeaktionen wie Sammeldownloads wieder**: Wiederholte I/O-Scans im selben Admin-Fenster werden dadurch reduziert. |
| **2.7.317** | 🟡 refactor | Admin/Themes | **Scan-, Download- und Einzel-Download-Antworten liefern jetzt strukturiertere Details an die UI statt nur zusammengesetzte Fehlersätze**: Flash-Hinweise und spätere Erweiterungen hängen damit klarer an einem kleinen Ergebnisvertrag. |
| **2.7.317** | 🔴 fix | Admin/Themes | **Font-Mutationen invalidieren den Theme-Scancache jetzt gezielt**: Bereits lokal geladene oder gelöschte Fonts bleiben dadurch nicht als veralteter Scan-Schatten im Admin hängen. |

---

### v2.7.316 — 27. März 2026 · Audit-Batch 398, Plugin-Marketplace-Quelle mit Cache-Details sichtbarer gemacht

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.316** | 🎨 style | Admin/UI | **`CMS/admin/views/plugins/marketplace.php` zeigt zur Registry-Herkunft jetzt auch Cache-Stand und Cache-Alter an, wenn ein zwischengespeicherter Katalog genutzt wird**: Fallbacks bleiben damit nicht länger nur implizit über eine allgemeine Warnmeldung sichtbar. |

---

### v2.7.315 — 27. März 2026 · Audit-Batch 397, PluginMarketplaceModule mit Registry-Cache und klarerem Fallback-Vertrag

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.315** | 🔴 fix | Admin/Plugins | **`CMS/admin/modules/plugins/PluginMarketplaceModule.php` speichert Remote-Registry-Daten jetzt mit TTL im Settings-Speicher zwischen und nutzt sie als Cache/Fallback**: Remote-Latenzen und komplette Ausfälle blockieren den Marketplace dadurch seltener direkt im Request. |
| **2.7.315** | 🟡 refactor | Admin/Plugins | **Der Module-Vertrag unterscheidet jetzt sauberer zwischen `remote`, `cache`, `local` und `none` als Quellenstatus**: Die View bekommt dadurch präzisere Herkunfts- und Fallback-Hinweise statt eines groben Binärsignals. |
| **2.7.315** | 🔴 fix | Admin/Plugins | **Installationsfehler geben jetzt Quelle und Hash-Kontext strukturiert zurück**: Remote- oder Paketprobleme lassen sich im Admin damit zielgerichteter nachvollziehen. |

---

### v2.7.314 — 27. März 2026 · Audit-Batch 396, Plugin-Marketplace-Entry prüft Slugs enger gegen den Katalog

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.314** | 🔴 fix | Admin/Plugins | **`CMS/admin/plugin-marketplace.php` begrenzt Install-Slugs jetzt früher und prüft sie zusätzlich gegen den aktuell geladenen Marketplace-Katalog**: Veraltete oder manipulierte POST-Slugs laufen damit nicht mehr blind bis in den Installpfad. |

---

### v2.7.313 — 27. März 2026 · Audit-Batch 395, Font-Manager-Forms an klaren Pending-Vertrag gehängt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.313** | 🎨 style | Admin/UI | **`CMS/admin/views/themes/fonts.php` bindet Scan-, Download-, Delete- und Save-Formulare jetzt über konsistente `data-font-manager-form`-/Pending-Attribute an denselben UI-Vertrag**: Der Font-Manager signalisiert laufende Aktionen damit sichtbarer und bleibt bei mehreren Buttons pro Seite konsistenter. |

---

### v2.7.312 — 27. März 2026 · Audit-Batch 394, Font-Manager-Asset gegen Doppelaktionen geglättet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.312** | 🔴 fix | Admin/UI | **`CMS/assets/js/admin-font-manager.js` markiert Submit-Buttons jetzt beim Absenden als laufend und sperrt sie bis zum Request-Ende**: Delete-, Scan-, Download- und Save-Aktionen feuern damit nicht mehr so leicht doppelt aus hektischem Mehrfachklicken. |
| **2.7.312** | 🟡 refactor | Admin/UI | **Delete-Confirms und klassische Form-Submits nutzen jetzt denselben Pending-Button-Pfad**: Der Font-Manager hält seine Laufzeitlogik dadurch enger an einem kleinen Asset-Vertrag statt an getrennten Sonderfällen für Confirm und Direktsubmit. |

---

### v2.7.311 — 27. März 2026 · Audit-Batch 393, Theme-Explorer-Asset um Dirty- und Pending-Zustände ergänzt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.311** | 🔴 fix | Admin/UI | **`CMS/assets/js/admin-theme-explorer.js` schützt ungespeicherte Editoränderungen jetzt mit Dirty-State und `beforeunload`-Warnung**: Versehentliches Verlassen des Editors kostet damit seltener stille Änderungen. |
| **2.7.311** | 🔴 fix | Admin/UI | **Ctrl+S und normale Saves sperren den Save-Button jetzt in einen Pending-Zustand statt Mehrfachsubmits zu erlauben**: Der Explorer bleibt dadurch ruhiger, wenn Nutzer mehrfach speichern oder Tastatur-Shortcuts drücken. |
| **2.7.311** | 🎨 style | Admin/UI | **Die Dateisuche blendet Ordner jetzt konsistenter anhand sichtbarer Treffer ein oder aus**: Der Dateibaum bleibt bei Filterung lesbarer und zeigt weniger leere Ordnerhüllen. |

---

### v2.7.310 — 27. März 2026 · Audit-Batch 392, Theme-Explorer-View spiegelt Schutzgrenzen sichtbarer

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.310** | 🎨 style | Admin/UI | **`CMS/admin/views/themes/editor.php` zeigt Dateibaum-Limits, übersprungene Einträge und Schutzwarnungen jetzt direkt im Explorer an**: Begrenzungen bleiben damit nicht mehr nur implizit im Modul verborgen. |
| **2.7.310** | 🔴 fix | Admin/UI | **Die Explorer-View markiert Save-Button und Formular jetzt mit expliziten Pending-/Unsaved-Attributen**: Asset und Markup teilen sich damit einen kleineren, stabileren Save-Vertrag. |

---

### v2.7.309 — 27. März 2026 · Audit-Batch 391, ThemeEditorModule begrenzt Dateibaum und Hotspot-Verzeichnisse klarer

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.309** | 🔴 fix | Admin/Themes | **`CMS/admin/modules/themes/ThemeEditorModule.php` begrenzt Theme-Dateibäume jetzt über Gesamtanzahl, Verzeichnisgröße, Tiefe und ausgesparte Hotspot-Segmente wie `vendor`, `node_modules` oder `dist`**: Der Explorer reduziert dadurch weitere I/O- und Render-Ausreißer bei großen Themes. |
| **2.7.309** | 🟡 refactor | Admin/Themes | **Das Modul liefert Baum-Zusammenfassung und Limits jetzt vorbereitet an die View aus**: Explorer-Markup und Asset müssen Schutzgrenzen nicht mehr implizit kennen. |
| **2.7.309** | 🔴 fix | Admin/Themes | **Bearbeitungs- und Pfadauflösung lehnen nun auch ausgesparte Theme-Segmente konsistenter ab**: Schreibzugriffe bleiben dadurch enger am sicheren Edit-Pfad. |

---

### v2.7.308 — 27. März 2026 · Audit-Batch 390, Theme-Explorer an Section-Shell und kleinen Request-Vertrag gehängt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.308** | 🟡 refactor | Admin/Themes | **`CMS/admin/theme-explorer.php` nutzt jetzt den gemeinsamen Section-Shell-Rahmen statt eigenen Redirect-/Flash-/Layout-Boilerplate zu pflegen**: Der Explorer hängt damit näher an denselben Guard-, CSRF- und Post-Handling-Regeln wie andere modernisierte Admin-Entrys. |
| **2.7.308** | 🔴 fix | Admin/Themes | **Aktion, Datei und Inhalt werden vor dem Modul-Dispatch über einen kleineren Payload-Vertrag normalisiert**: Ungültige Save-POSTs landen damit früher im Entry statt lose im Explorer-Pfad zu versickern. |

---

### v2.7.307 — 27. März 2026 · Audit-Batch 389, Font-Manager-UI und Grenzen sichtbarer gemacht

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.307** | 🎨 style | Admin/UI | **`CMS/admin/views/themes/fonts.php` zeigt Scan-Limits, übersprungene Dateien und Schutzwarnungen jetzt explizit im Admin an**: Theme-Scans bleiben dadurch transparenter, statt still im Hintergrund an internen Grenzen abzuscheiden. |
| **2.7.307** | 🔴 fix | Admin/UI | **Typografie- und Direktdownload-Formulare spiegeln Zahlen- und Textgrenzen jetzt direkt in ihren Feldern**: Font-Größe, Zeilenhöhe und Google-Font-Namen werden früher sichtbar begrenzt, bevor fehlerhafte Werte erst spät ins Backend laufen. |

---

### v2.7.306 — 27. März 2026 · Audit-Batch 388, Font-Manager-Theme-Scans und Font-Auswahl weiter gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.306** | 🔴 fix | Admin/Themes | **`CMS/admin/modules/themes/FontManagerModule.php` begrenzt Theme-Scans jetzt über Datei-, Größen- und Gesamtvolumen-Limits und überspringt versteckte bzw. große I/O-Hotspots**: Der Font-Scan bleibt dadurch robuster gegen unnötig teure Theme-Verzeichnisse und große Dateien. |
| **2.7.306** | 🟡 refactor | Admin/Themes | **Der Font-Manager liefert Scan-Zusammenfassung, Warnungen und UI-Constraints jetzt vorbereitet aus dem Modul**: View und Entry müssen Limits nicht länger implizit kennen oder selbst zusammensuchen. |
| **2.7.306** | 🔴 fix | Admin/Themes | **Heading-/Body-Font-Zuweisungen werden jetzt gegen tatsächlich auswählbare Font-Keys validiert**: Persistierte Typografie-Einstellungen bleiben damit näher an real verfügbaren System- und lokalen Schriftarten. |

---

### v2.7.305 — 27. März 2026 · Audit-Batch 387, Font-Manager-Request-Vertrag weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.305** | 🔴 fix | Admin/Themes | **`CMS/admin/font-manager.php` normalisiert Save-, Delete- und Download-Payloads jetzt über einen engeren Request-Vertrag**: Font-ID, Google-Font-Name, Font-Keys, Größe und Zeilenhöhe werden vor dem Modul-Dispatch früher begrenzt und validiert. |
| **2.7.305** | 🟡 refactor | Admin/Themes | **Der Font-Manager reicht `saveSettings()` nicht mehr mit rohem `$_POST`, sondern mit vorbereiteten Settings-Daten an das Modul weiter**: Save-Pfade hängen damit sichtbarer an einem kleineren Entry-/Modulvertrag statt an breiten Formular-Payloads. |

---

### v2.7.304 — 27. März 2026 · Audit-Batch 386, Theme-Editor an den gemeinsamen Shell-Vertrag angenähert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.304** | 🟡 refactor | Admin/Themes | **`CMS/admin/theme-editor.php` nutzt jetzt den gemeinsamen Section-Shell-Rahmen statt einen eigenen Header-/Sidebar-/Footer-Sonderpfad**: Der Theme-Editor hängt damit näher an denselben Guard-, Daten- und View-Regeln wie andere modernisierte Admin-Entrys. |
| **2.7.304** | 🔴 fix | Admin/Themes | **Der Theme-Editor bereitet Customizer-Zustand und Fehlergründe jetzt als expliziten Runtime-State auf**: Nicht lesbare Theme-Pfade oder fehlende `admin/customizer.php`-Dateien landen damit nicht mehr als stiller Sonderfall im Entry. |
| **2.7.304** | 🎨 style | Admin/Themes | **`CMS/admin/views/themes/customizer-missing.php` zeigt den Fallback für fehlende bzw. unsichere Customizer-Dateien jetzt als eigene Admin-View mit klaren Folge-Links**: Theme-Verwaltung und Theme-Explorer bleiben dadurch direkt erreichbar, ohne dass der Entry selbst HTML ausgeben muss. |

---

### v2.7.303 — 27. März 2026 · Audit-Batch 385, Medien-Upload- und Settings-Vertrag weiter gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.303** | 🔴 fix | Admin/Media | **`CMS/admin/media.php` begrenzt Upload-Dateinamen, Dateianzahl und Batch-Größe jetzt enger vor dem Modul-Dispatch**: Inkonsistente Upload-Payloads werden früher verworfen und numerische Settings-Felder robuster auf sichere Bereiche geklemmt. |
| **2.7.303** | 🟡 refactor | Admin/Media | **`CMS/admin/modules/media/MediaModule.php` normalisiert Typauswahlen für allgemeine und Member-Uploads jetzt auf echte Mediengruppen zurück und akzeptiert nur reale Upload-Tempdateien**: Settings- und Upload-Pfade hängen damit sichtbarer an einem kleineren, konsistenteren Modulvertrag. |
| **2.7.303** | 🎨 style | Admin/UI | **`CMS/admin/views/media/settings.php` und `CMS/admin/views/media/library.php` spiegeln die engeren Zahlen- und Textgrenzen jetzt direkt im Formular wider**: Medien-Settings und Bibliotheksfilter geben Grenzwerte früher sichtbar vor, statt Fehler erst spät aus dem Backend zurückzubekommen. |

---

### v2.7.302 — 27. März 2026 · Audit-Batch 384, Legal-Sites-POST-State und Guard-Vertrag weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.302** | 🔴 fix | Admin/Legal | **`CMS/admin/legal-sites.php` normalisiert rechtliche HTML-Felder jetzt bereits im Entry defensiver und hält fehlgeschlagene Save-Payloads für den nächsten Render in Session-State zusammen**: Formularinhalte und Seitenzuordnungen springen damit bei Validierungsfehlern nicht mehr lose auf den alten Persistenzstand zurück. |
| **2.7.302** | 🟡 refactor | Admin/Legal | **Der Legal-Sites-Entry bündelt Aktion, Fehler und Payload jetzt über einen kleinen Request-Vertrag statt mehrfach verteilter Template-Type-Prüfungen**: POST-Fehlerpfade bleiben damit kompakter und näher am Section-Shell-Muster. |
| **2.7.302** | 🔴 fix | Admin/Legal | **`CMS/admin/modules/legal/LegalSitesModule.php` erzwingt Admin- plus `manage_settings`-Capability jetzt auch im Modul selbst**: Der Legal-Sites-Pfad verlässt sich damit nicht nur auf den Entry-Guard, sondern zieht die Berechtigungsgrenze zusätzlich an der Modul-Tür nach. |

---

### v2.7.301 — 27. März 2026 · Audit-Batch 383, Theme-Explorer-Dateimetadaten und Save-Grenzen sichtbarer gemacht

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.301** | 🟡 refactor | Admin/Themes | **`CMS/admin/modules/themes/ThemeEditorModule.php` liefert Dateigröße, Erweiterung, Schreibbarkeit und Save-Sperrgründe jetzt als vorbereitete Dateimetadaten an die View**: Der Explorer muss Bearbeitbarkeitsregeln nicht länger stillschweigend nur im Save-Pfad verstecken. |
| **2.7.301** | 🔴 fix | Admin/Themes | **`CMS/admin/views/themes/editor.php` deaktiviert Speichern und setzt den Editor auf `readonly`, wenn eine Datei zwar angezeigt, aber nicht sicher bearbeitet werden kann**: Oversize- oder schreibgeschützte Dateien landen damit nicht mehr erst beim Submit in einem vermeidbaren Fehlpfad. |
| **2.7.301** | 🎨 style | Admin/UI | **Der Theme-Explorer zeigt Erweiterung, Dateigröße und Bearbeitbarkeitsstatus jetzt direkt im Editor-Kopf an**: Dateityp- und Schreibstatus werden damit sichtbarer statt als implizites Backend-Wissen verborgen zu bleiben. |

---

### v2.7.300 — 27. März 2026 · Audit-Batch 382, Marketplace-Quellen und Fallback-Hinweise weiter geglättet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.300** | 🔴 fix | Admin/Marketplace | **`CMS/admin/modules/plugins/PluginMarketplaceModule.php` und `CMS/admin/modules/themes/ThemeMarketplaceModule.php` melden Remote-, Local-Fallback- und Ausfallstatus jetzt explizit an ihre Views zurück**: Marketplace-Fehlerpfade bleiben damit nicht mehr stumm, wenn auf lokalen Index statt auf Remote-Katalog umgeschaltet wird. |
| **2.7.300** | 🟡 refactor | Admin/Marketplace | **Die Module halten Quellenstatus und Quellen-URL jetzt als kleinen ViewModel-Baustein im Datenvertrag**: Registry- und Katalog-Herkunft müssen im Template nicht länger indirekt aus leeren Listen erraten werden. |
| **2.7.300** | 🎨 style | Admin/UI | **`CMS/admin/views/plugins/marketplace.php` und `CMS/admin/views/themes/marketplace.php` zeigen die aktuell genutzte Quelle jetzt als Flash-Hinweis mit Quellenangabe an**: Admin-Nutzer sehen damit sofort, ob Remote-Daten geladen oder ein lokaler Fallback verwendet wurde. |

---

### v2.7.299 — 27. März 2026 · Audit-Batch 381, Theme-Explorer-Interaktionen in dediziertes Asset gezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.299** | 🔴 fix | Admin/Themes | **`CMS/admin/theme-explorer.php` bindet den Theme-Explorer jetzt über ein dediziertes Admin-Asset statt über lokales Inline-Script an**: Der Editor-Pfad hält Keyboard-Shortcuts und Laufzeitlogik damit konsistenter am Asset-Vertrag. |
| **2.7.299** | 🟡 refactor | Admin/Themes | **`CMS/admin/views/themes/editor.php` liefert Suche, Dateibaum-Markierungen und Editor-Konfiguration jetzt vorbereiteter an `CMS/assets/js/admin-theme-explorer.js`**: Das Theme-Template reduziert weiteren Laufzeit-Boilerplate und trennt Markup klarer von Interaktionen. |
| **2.7.299** | 🎨 style | Assets/Admin | **`CMS/assets/js/admin-theme-explorer.js` ergänzt Dateifilter, Tab-Einrückung und `Ctrl+S`-Speichern zentral**: Der Theme-Explorer bleibt damit auch bei größeren Dateibäumen fokussierter bedienbar. |

---

### v2.7.298 — 27. März 2026 · Audit-Batch 380, Landing-POST- und Tab-Vertrag weiter gestaffelt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.298** | 🔴 fix | Admin/Landing | **`CMS/admin/landing-page.php` normalisiert Aktion, Feature-ID, aktiven Tab und Plugin-ID jetzt enger in einem gemeinsamen Payload**: Fehler und Redirect-Ziele bleiben dadurch robuster an einem kleinen Entry-Vertrag statt an verstreuten Spezialfällen hängen. |
| **2.7.298** | 🟡 refactor | Admin/Landing | **Der Landing-Entry leitet nach POSTs jetzt deterministisch zurück in den zugehörigen Tab**: Header-, Content-, Footer-, Design- und Plugin-Änderungen springen damit nicht mehr lose auf GET-Fallbacks zurück. |
| **2.7.298** | 🎨 style | Admin/Landing | **`CMS/admin/views/landing/page.php` übergibt den aktiven Tab jetzt in allen Formularen explizit mit**: Die Landing-UI hält ihren Zustand dadurch auch bei Fehlern und Feature-/Plugin-Aktionen sichtbar stabiler. |

---

### v2.7.297 — 27. März 2026 · Audit-Batch 379, Kommentar-KPIs und Zeilenaktionen weiter vorbereitet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.297** | 🟡 refactor | Admin/Comments | **`CMS/admin/modules/comments/CommentsModule.php` liefert KPI-Karten und zeilenabhängige Aktionsmodelle jetzt vorbereitet an die View**: Die Kommentar-Moderation hängt damit weniger an View-seitigen Status- und Rechte-Verzweigungen. |
| **2.7.297** | 🔴 fix | Admin/Comments | **`CMS/admin/views/comments/list.php` rendert KPI-Karten und Dropdown-Aktionen jetzt aus vorbereiteten ViewModels**: Das Template reduziert weiteren Moderations-Boilerplate und bleibt robuster gegen künftige Status-/Rechte-Erweiterungen. |
| **2.7.297** | 🎨 style | Admin/UI | **Die Kommentar-Liste hält Icon-, Badge- und Action-Darstellung klarer an einem kleineren Datenvertrag**: KPI- und Zeilen-UI wirken dadurch konsistenter zwischen Modul und Markup. |

---

### v2.7.296 — 27. März 2026 · Audit-Batch 378, Tabellen-Editor bei Größenlimits und Save-Vertrag gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.296** | 🔴 fix | Admin/Tables | **`CMS/admin/modules/tables/TablesModule.php` validiert Tabellenname, Beschreibung, Spalten- und Zeilenstruktur jetzt restriktiver**: Der Save-Pfad begrenzt Spalten-, Zeilen- und Zellgrößen robuster und normalisiert Editor-Daten vor dem Persistieren. |
| **2.7.296** | 🟠 perf | Admin/Tables | **`CMS/admin/site-tables.php`, `CMS/admin/views/tables/edit.php` und `CMS/assets/js/admin-site-tables.js` ziehen Größenlimits und Redirects enger zusammen**: Übergroße JSON-Payloads werden früher abgefangen, Save-Fehler landen wieder im Editor und die UI verhindert unnötige Überläufe bereits clientseitig. |
| **2.7.296** | 🟡 refactor | Admin/Tables | **Die Tabellen-Bearbeitung liefert Editor-Zusammenfassung, Limits und JSON-Konfiguration jetzt vorbereiteter aus dem Modulvertrag**: View und Asset müssen den Editorzustand dadurch nicht länger ad hoc selbst zusammenstecken. |

---

### v2.7.295 — 27. März 2026 · Audit-Batch 377, Menü-Editor bei Picker- und Item-Vertrag weiter gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.295** | 🔴 fix | Admin/Menus | **`CMS/admin/menu-editor.php` bündelt Aktions-, Menü-ID- und Payload-Fehler jetzt enger in einem gemeinsamen Request-Vertrag**: Der Entry verwirft damit übergroße Item-Payloads und ungültige Kontrollwerte früher, bevor sie in den Modul-Dispatch laufen. |
| **2.7.295** | 🟡 refactor | Admin/Menus | **`CMS/admin/modules/menus/MenuEditorModule.php` validiert Menü-Namen, Theme-Positionen, Item-URLs, Parent-Beziehungen und doppelte Editor-IDs jetzt restriktiver und liefert vorbereitete Page-Picker-/Editor-Konfiguration an die View**: Der Menü-Editor hängt damit weniger an rohen View-Ableitungen und schützt Save-/Sync-Pfade robuster gegen inkonsistente Item-Strukturen. |
| **2.7.295** | 🎨 style | Admin/UI | **`CMS/admin/views/menus/editor.php` und `CMS/assets/js/admin-menu-editor.js` nutzen vorbereitete Page-Picker-Daten, klarere URL-/Titel-Validierung und einen kompakteren Seiten-Übernahme-Flow**: Die Menü-UI bleibt dadurch übersichtlicher, vermeidet lose Seiten-Button-Listen und gibt Fehler früher direkt im Editor zurück. |

---

### v2.7.294 — 27. März 2026 · Audit-Batch 376, Landing-ViewModels weiter in den Modulvertrag gezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.294** | 🟡 refactor | Admin/Landing | **`CMS/admin/modules/landing/LandingPageModule.php` bereitet Content-Typen, Feature-Karten und Plugin-Karten jetzt stärker als ViewModels auf**: Der Landing-Admin hängt damit weniger an rohen Service-Strukturen und verstreuten Template-Ableitungen. |
| **2.7.294** | 🔴 fix | Admin/Landing | **`CMS/admin/views/landing/page.php` nutzt vorkonfigurierte Plugin-Karten statt roher Override-Zugriffe und zeigt Plugin-Metadaten konsistenter an**: Der Plugins-Tab hängt damit nicht länger an einer nicht gesicherten `label`-Annahme und reduziert Restlogik im Template. |
| **2.7.294** | 🎨 style | Admin/Landing | **Die Content- und Plugin-Tabs der Landing-Ansicht rendern vorbereitete Auswahl- und Kartenmodelle klarer**: Inhalts-Typen, Feature-Löschzustände und Plugin-Zielbereiche werden damit sichtbarer über kleine View-Daten statt ad hoc im Template zusammengesetzt. |

---

### v2.7.293 — 27. März 2026 · Audit-Batch 375, Theme-Marketplace an den Wrapper-/Feedback-Vertrag angeglichen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.293** | 🔴 fix | Admin/Marketplace | **`CMS/admin/theme-marketplace.php` bündelt Aktion, Theme-Slug und Fehlermeldung jetzt über einen kleinen Payload-Vertrag**: Der Entry reduziert damit weitere verteilte Einzelfallprüfungen und bleibt näher am gemeinsamen Section-Shell-Muster. |
| **2.7.293** | 🟡 refactor | Admin/Themes | **`CMS/admin/modules/themes/ThemeMarketplaceModule.php` liefert vorbereitete Statusmetriken und Statusfilter für die View und schützt das Zielverzeichnis zusätzlich gegen inkonsistente Installationspfade**: Statistik-, Filter- und Installationspfade hängen damit sichtbarer an einem konsistenten Modulvertrag. |
| **2.7.293** | 🎨 style | Admin/UI | **`CMS/admin/views/themes/marketplace.php` und `CMS/assets/js/admin-theme-marketplace.js` nutzen vorbereitete Filterdaten und sperren bestätigte Install-Buttons bis zum Submit**: Die Theme-Marketplace-UI vermeidet doppelte Klicks und trennt Filter-/Feedbacklogik klarer vom Template. |

---

### v2.7.292 — 27. März 2026 · Audit-Batch 374, Plugin-Marketplace-Wrapper und Feedbackpfade verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.292** | 🔴 fix | Admin/Marketplace | **`CMS/admin/plugin-marketplace.php` bündelt Aktion, Slug und Fehlermeldung jetzt über einen kleinen Payload-Vertrag statt mehrfach verteilter Einzelfallprüfungen**: Der Entry bleibt damit näher am gemeinsamen Section-Shell-Muster und hält seine Installationsfehler kompakter. |
| **2.7.292** | 🟡 refactor | Admin/Plugins | **`CMS/admin/modules/plugins/PluginMarketplaceModule.php` liefert vorbereitete Kategorie-/Statusfilter und schützt das Zielverzeichnis zusätzlich vor inkonsistenten Installationspfaden**: Statistik-, Filter- und Installationspfade hängen damit sichtbarer an einem gemeinsamen Modulvertrag. |
| **2.7.292** | 🎨 style | Admin/UI | **`CMS/admin/views/plugins/marketplace.php` und `CMS/assets/js/admin-plugin-marketplace.js` ziehen Filteroptionen aus vorbereiteten View-Daten und sperren den Install-Button nach Bestätigung bis zum Submit**: Die Marketplace-UI vermeidet doppelte Klicks und hält Such-/Status-Logik klarer getrennt vom Template. |

---

### v2.7.291 — 27. März 2026 · Audit-Batch 373, Landing-Badge konfigurierbar gemacht

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.291** | 🟢 feat | Admin/Landing | **`CMS/admin/views/landing/page.php` ergänzt im Header-Tab ein frei pflegbares Badge-Feld**: Redakteure können damit statt der starren Versionszahl beliebigen Text wie „Beta“, „Neu“ oder einen Release-Hinweis hinterlegen. |
| **2.7.291** | 🔴 fix | Core/Landing | **`CMS/admin/modules/landing/LandingPageModule.php`, `CMS/core/Services/Landing/LandingHeaderService.php`, `CMS/core/Services/Landing/LandingDefaultsProvider.php` und `CMS/install/InstallerService.php` tragen den Badge-Wert jetzt konsistent durch den Header-Vertrag**: Bestehende Landing-Daten bleiben kompatibel, weil fehlende Badge-Werte zunächst aus der bisherigen Versionsanzeige abgeleitet werden. |
| **2.7.291** | 🎨 style | Frontend/Landing | **`CMS/themes/cms-default/partials/home-landing.php` rendert das Hero-Badge nur noch bei hinterlegtem Text und entfernt den harten `v`-Prefix**: Leere Badge-Felder führen dadurch automatisch zu einem sauberen Hero ohne Badge-Restmarkup. |

---

### v2.7.290 — 27. März 2026 · Audit-Batch 372, Wrapper- und Theme-Editor-Verträge weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.290** | 🔴 fix | Admin/Comments & Landing | **`CMS/admin/comments.php`, `CMS/admin/modules/comments/CommentsModule.php`, `CMS/admin/views/comments/list.php`, `CMS/admin/landing-page.php` und `CMS/admin/views/landing/page.php` ziehen Status-, Tab- und View-Helfer enger an kleine Wrapper-/ViewModel-Verträge**: Kommentar-Statusfilter hängen nicht länger implizit an `$_GET` im Modul, und Landing-Tabs/Formularwerte kommen vorbereiteter in der View an. |
| **2.7.290** | 🟠 perf | Admin/Menus & Tables | **`CMS/admin/modules/menus/MenuEditorModule.php`, `CMS/admin/menu-editor.php`, `CMS/admin/modules/tables/TablesModule.php` und `CMS/admin/site-tables.php` reduzieren Bootstrap-, Such- und Redirect-Sonderpfade**: Theme-Sync läuft nur noch gezielt, neue Menüs springen sauber in den Editor zurück, und Tabellenlisten/Fehlerpfade hängen klarer am Wrapper-/Modulvertrag statt an verstreuten Direktzugriffen. |
| **2.7.290** | 🟡 refactor | Admin/Themes | **`CMS/admin/theme-editor.php`, `CMS/admin/theme-explorer.php`, `CMS/admin/modules/themes/ThemeEditorModule.php` und `CMS/admin/views/themes/editor.php` härten Capability- und Pfadgrenzen weiter**: Der aktive Theme-Customizer wird nur noch über einen verifizierten Theme-Pfad eingebunden, Hidden-Segmente werden aus Dateibaum und Safe-Path-Auflösung ausgeschlossen und der Explorer hält seine Baum-/Link-Helfer kompakter. |

---

### v2.7.289 — 27. März 2026 · Audit-Batch 371, Marketplace-Härtung und View-Restpfade nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.289** | 🔴 fix | Admin/Themes | **`CMS/admin/theme-marketplace.php` und `CMS/admin/modules/themes/ThemeMarketplaceModule.php` normalisieren Theme-Slugs, Registry-/Manifest-Daten und HTTPS-Marketplace-URLs jetzt restriktiver**: Kollidierende bzw. doppelte Theme-Slugs werden robuster verworfen, Asset-/Download-Pfade enger auf erlaubte Hosts begrenzt. |
| **2.7.289** | 🟠 perf | Admin/Marketplace | **`CMS/admin/views/themes/marketplace.php`, `CMS/assets/js/admin-theme-marketplace.js`, `CMS/admin/views/plugins/marketplace.php` und `CMS/assets/js/admin-plugin-marketplace.js` ergänzen KPI-Karten, Such-/Statusfilter und Empty-State-Handling**: Größere Marketplace-Kataloge bleiben damit im Admin fokussierter filterbar und manuelle Kandidaten sichtbarer getrennt. |
| **2.7.289** | 🟡 refactor | Admin/Landing & Views | **`CMS/admin/modules/landing/LandingPageModule.php` initialisiert Defaults nur noch lazy, während Kommentar-, Menü- und Tabellen-Views zusätzliche Helper-/JSON-Vorbereitung erhalten**: Konstruktor-Seiteneffekte sinken, und Template-/Escaping-Grenzen bleiben konsistenter vorbereitet. |

---

### v2.7.288 — 27. März 2026 · Audit-Batch 370, Core-Modulverwaltung für Abointegration ergänzt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.288** | 🔴 fix | Admin/Content | **`CMS/admin/pages.php` und `CMS/admin/posts.php` importieren `CMS\\Security` wieder korrekt**: Der produktive Fatal Error bei der Editor-/Media-Token-Erzeugung entfällt damit in beiden Admin-Entrys. |
| **2.7.288** | 🟢 feat | Core/System | **`CMS/core/Services/CoreModuleService.php` führt eine zentrale Registry für integrierte Kernmodule ein**: Abo-Core, Limits, Member-Abo-Bereich sowie Admin-Unterbereiche können jetzt mit Abhängigkeiten und Legacy-Setting-Sync zentral gesteuert werden. |
| **2.7.288** | 🟡 refactor | Admin/System | **`System -> Module` ergänzt eine echte Core-Modulverwaltung und bindet Sidebar, Admin-Gates, Dashboard sowie Member-Pfade daran an**: Deaktivierte Abo-Bereiche verschwinden sichtbar aus dem Admin und werden in ihren Laufzeitpfaden wirksam abgeschaltet. |

---

### v2.7.287 — 26. März 2026 · Audit-Batch 369, Plugin-Marketplace-Katalog weiter gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.287** | 🔴 fix | Admin/Plugins | **`CMS/admin/modules/plugins/PluginMarketplaceModule.php` verwirft jetzt kollidierende Manifest-Slugs und dedupliziert doppelte Katalogeinträge pro Plugin-Slug**: Der Marketplace hält seinen Remote-Katalogpfad damit konsistenter, bevor Download- und Update-URLs weiter aufgelöst werden. |
| **2.7.287** | 🟠 perf | Admin/Plugins | **Installierte Plugin-Verzeichnisse werden für den Marketplace jetzt als normalisierte Slug-Map aufgebaut statt linear gegen rohe Ordnernamen geprüft**: Die Installationsmarkierung spart dadurch unnötige Mehrfach-Lookups über den Katalog. |
| **2.7.287** | 🟡 refactor | Admin/Plugins | **Manifestdaten fließen nur noch als skalare allowlist-basierte Metadaten in den Katalog ein**: Das hält den Marketplace-Vertrag klarer zwischen Registry, Manifest und finaler Installationsentscheidung getrennt. |

---

### v2.7.286 — 26. März 2026 · Audit-Batch 368, Error-Report-Vertrag weiter gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.286** | 🔴 fix | Admin/System | **`CMS/admin/error-report.php` reicht Fehlerreports jetzt nur noch als gezielt normalisierte Payloads weiter**: Titel, Nachricht, Fehlercode, Source-URL sowie JSON-Felder werden vor dem Service-Dispatch enger begrenzt und von Steuerzeichen bereinigt. |
| **2.7.286** | 🟠 perf | Core/Services | **`CMS/core/Services/ErrorReportService.php` normalisiert Status, Source-URL sowie verschachtelte `error_data`-/`context`-Payloads jetzt zusätzlich selbst**: Der Service bleibt damit robuster, auch wenn künftige Aufrufer nicht aus dem Admin-Wrapper kommen. |
| **2.7.286** | 🟡 refactor | Admin/System | **Der Fehlerreport trennt Wrapper- und Service-Trust-Boundary klarer**: Request-Normalisierung und persistente Report-Sanitierung hängen sichtbarer an einem gemeinsamen Payload-Vertrag statt an lose verteilten Einzelpfaden. |

---

### v2.7.285 — 26. März 2026 · Audit-Batch 367, Roles-View weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.285** | 🔴 fix | Admin/Users | **`CMS/admin/views/users/roles.php` verzichtet bei Gruppen-Toggles sowie Role-/Capability-Modals auf lokales Inline-Script**: Die View hängt ihre Interaktionen jetzt an Datenattribute und ein gemeinsames Users-Admin-Asset. |
| **2.7.285** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-users.js` übernimmt Role-/Capability-Modalbefüllung und Gruppen-Toggle zentral**: Die Rollenverwaltung hält ihre Laufzeitlogik dadurch nicht länger direkt im PHP-Template. |
| **2.7.285** | 🟡 refactor | Admin/Users | **Die Rollen-Ansicht trennt Markup, Modalzustand und Rechte-Interaktionen klarer**: Das hält die Benutzerverwaltung konsistenter zu anderen modernisierten Admin-Views. |

---

### v2.7.284 — 26. März 2026 · Audit-Batch 366, Roles-Entry weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.284** | 🔴 fix | Admin/Users | **`CMS/admin/roles.php` normalisiert erlaubte Aktionen jetzt einmalig und dispatcht über einen gemeinsamen Action-Helper**: Der POST-Pfad validiert Kontrollwerte damit klarer vor Rollen- und Capability-Mutationen. |
| **2.7.284** | 🟠 perf | Admin/Users | **Der Roles-Entry bindet `CMS/assets/js/admin-users.js` sauber über `pageAssets` ein**: Modal- und Toggle-Interaktionen hängen damit sichtbarer am gemeinsamen Admin-Asset-Vertrag. |
| **2.7.284** | 🟡 refactor | Admin/Users | **`cms_admin_roles_handle_action()` arbeitet direkt mit dem vorbereiteten Payload**: Rollen- und Rechte-Aktionen bleiben dadurch klarer an einer kleinen Entry-Logik gebündelt. |

---

### v2.7.283 — 26. März 2026 · Audit-Batch 365, Users-List-View weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.283** | 🔴 fix | Admin/Users | **`CMS/admin/views/users/list.php` verzichtet bei Rollen-/Status-Filtern auf lokale Inline-`onchange`-Handler und liefert Grid-Konfiguration per JSON**: Die Listenansicht hängt ihre Filter- und Grid-Initialisierung jetzt an ein dediziertes Admin-Asset. |
| **2.7.283** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-users.js` übernimmt Users-Grid und Filter-Redirects zentral**: Die Benutzerliste hält ihre Laufzeitlogik dadurch nicht länger direkt im PHP-Template. |
| **2.7.283** | 🟡 refactor | Admin/Users | **Die Benutzer-Liste trennt Markup, Filterzustand und Grid-Konfiguration klarer**: Das hält die Benutzerverwaltung konsistenter zu anderen modernisierten Admin-Views. |

---

### v2.7.282 — 26. März 2026 · Audit-Batch 364, Users-Entry weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.282** | 🔴 fix | Admin/Users | **`CMS/admin/users.php` bündelt Aktion, ID, Bulk-Aktion und Bulk-IDs jetzt einmalig in einem normalisierten Payload**: Der POST-Pfad validiert Kontrollfelder damit klarer vor Save-, Delete- und Bulk-Dispatch. |
| **2.7.282** | 🟠 perf | Admin/Users | **Der Users-Entry baut die Listen-Grid-Konfiguration jetzt über `cms_admin_users_grid_config()` und bindet `CMS/assets/js/admin-users.js` sauber ein**: Grid- und Filter-Interaktionen hängen damit sichtbarer am gemeinsamen Admin-Asset-Vertrag. |
| **2.7.282** | 🟡 refactor | Admin/Users | **Die Benutzerverwaltung trennt Listen-Konfiguration, Payload-Normalisierung und Dispatch klarer**: Das hält Entry-, View- und Asset-Vertrag kompakter und konsistenter. |

---

### v2.7.281 — 26. März 2026 · Audit-Batch 363, Tables-Edit-View weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.281** | 🔴 fix | Admin/Tables | **`CMS/admin/views/tables/edit.php` verzichtet beim Spalten-/Zeilen-Editor auf lokales Inline-Script und generierte Inline-Handler**: Die View liefert den Editorzustand jetzt per JSON-Konfiguration an ein dediziertes Tabellen-Asset. |
| **2.7.281** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-site-tables.js` übernimmt den Tabellen-Editor für Spalten, Zeilen und JSON-Serialisierung zentral**: Die Bearbeitungsansicht hält ihre Laufzeitlogik dadurch nicht länger direkt im PHP-Template. |
| **2.7.281** | 🟡 refactor | Admin/Tables | **Die Tabellen-Bearbeitung trennt Markup, Editorzustand und Mutationslogik klarer**: Das hält die Tabellenverwaltung konsistenter zu anderen modernisierten Admin-Views. |

---

### v2.7.280 — 26. März 2026 · Audit-Batch 362, Tables-List-View weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.280** | 🔴 fix | Admin/Tables | **`CMS/admin/views/tables/list.php` verzichtet bei Suche, Duplicate- und Delete-Aktionen auf lokale Inline-Handler**: Die Listenansicht liefert ihre Interaktionen jetzt über Datenattribute an ein gemeinsames Tabellen-Asset. |
| **2.7.280** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-site-tables.js` übernimmt Such-Redirect sowie Duplicate-/Delete-Flow der Tabellenliste zentral**: Die Listenansicht hält ihre Laufzeitlogik dadurch nicht länger direkt im PHP-Template. |
| **2.7.280** | 🟡 refactor | Admin/Tables | **Die Tabellen-Liste trennt Markup, Suchzustand und Aktionslogik klarer**: Das hält die Tabellenverwaltung konsistenter zu anderen modernisierten Admin-Views. |

---

### v2.7.279 — 26. März 2026 · Audit-Batch 361, Site-Tables-Entry weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.279** | 🔴 fix | Admin/Tables | **`CMS/admin/site-tables.php` normalisiert Aktion und Tabellen-ID jetzt einmalig in einem gemeinsamen Payload**: Der POST-Pfad validiert Kontrollfelder damit klarer vor Save-/Delete-/Duplicate-Dispatch. |
| **2.7.279** | 🟠 perf | Admin/Tables | **Der Site-Tables-Entry bindet `CMS/assets/js/admin-site-tables.js` für Listen- und Edit-Ansicht sauber über `pageAssets` ein**: Tabellen-Interaktionen hängen damit sichtbarer am gemeinsamen Admin-Asset-Vertrag. |
| **2.7.279** | 🟡 refactor | Admin/Tables | **`cms_admin_site_tables_handle_action()` arbeitet direkt mit dem vorbereiteten Payload**: Save-, Delete- und Duplicate-Pfade bleiben dadurch klarer voneinander getrennt. |

---

### v2.7.278 — 26. März 2026 · Audit-Batch 360, Menu-Editor-View weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.278** | 🔴 fix | Admin/Menus | **`CMS/admin/views/menus/editor.php` verzichtet bei Modal- und Item-Editor-Aktionen auf lokales Inline-Script und lokale Inline-Handler**: Die View liefert Menüzustand und Trigger jetzt über Datenattribute plus JSON-Konfiguration an ein dediziertes Asset. |
| **2.7.278** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-menu-editor.js` übernimmt Modal-Befüllung, Item-Rendering und Menü-Delete-Confirm zentral**: Der Menü-Editor hält seine Laufzeitlogik dadurch nicht länger direkt im PHP-Template. |
| **2.7.278** | 🟡 refactor | Admin/Menus | **Der Menü-Editor trennt Markup, Modalzustand und Item-Struktur klarer**: Das hält die Navigationsverwaltung konsistenter zu anderen modernisierten Admin-Views. |

---

### v2.7.277 — 26. März 2026 · Audit-Batch 359, Menu-Editor-Entry weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.277** | 🔴 fix | Admin/Menus | **`CMS/admin/menu-editor.php` normalisiert Aktion, Menü-ID und Item-JSON jetzt einmalig in einem gemeinsamen Payload**: Der Entry pflegt dadurch keine separate Handler-Map mehr für seinen POST-Pfad. |
| **2.7.277** | 🟠 perf | Admin/Menus | **Der Menu-Editor-Entry bindet `CMS/assets/js/admin-menu-editor.js` sauber über `page_assets` ein**: Modal- und Item-Editor-Pfade hängen damit sichtbarer am gemeinsamen Admin-Asset-Vertrag. |
| **2.7.277** | 🟡 refactor | Admin/Menus | **`cms_admin_menu_editor_handle_action()` dispatcht direkt über den vorbereiteten Payload**: Guard, Menü-ID-Prüfung und Modul-Aufruf bleiben dadurch klarer an einer kleinen Entry-Logik. |

---

### v2.7.276 — 26. März 2026 · Audit-Batch 358, Hub-Templates-View weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.276** | 🔴 fix | Admin/Hub | **`CMS/admin/views/hub/templates.php` verzichtet bei Duplizieren-/Löschen-Aktionen auf lokale Inline-`onclick`-Handler**: Die View liefert Template-Aktionen jetzt über Datenattribute an ein gemeinsames Hub-Asset. |
| **2.7.276** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-hub-sites.js` übernimmt Duplicate-/Delete-Flow für Hub-Templates zentral**: Die Template-Bibliothek hält ihre Laufzeitlogik dadurch nicht länger direkt im PHP-Template. |
| **2.7.276** | 🟡 refactor | Admin/Hub | **Die Hub-Template-Liste trennt Markup, Formularzustand und Template-Aktionen klarer**: Das hält die Hub-Verwaltung konsistenter zu anderen modernisierten Admin-Views. |

---

### v2.7.275 — 26. März 2026 · Audit-Batch 357, Hub-List-View weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.275** | 🔴 fix | Admin/Hub | **`CMS/admin/views/hub/list.php` verzichtet bei Suche, Copy-, Duplicate- und Delete-Aktionen auf lokale Inline-Handler**: Die View liefert Listeninteraktionen jetzt über Datenattribute an ein gemeinsames Hub-Asset. |
| **2.7.275** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-hub-sites.js` übernimmt Suche, Clipboard-Flow sowie Site-Duplicate-/Delete-Aktionen zentral**: Die Hub-Liste hält ihre Laufzeitlogik dadurch nicht länger direkt im PHP-Template. |
| **2.7.275** | 🟡 refactor | Admin/Hub | **Die Hub-Site-Liste trennt Markup, Suchzustand und Aktionslogik klarer**: Das hält die Routing-Verwaltung konsistenter zu anderen modernisierten Admin-Views. |

---

### v2.7.274 — 26. März 2026 · Audit-Batch 356, Hub-Entry weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.274** | 🔴 fix | Admin/Hub | **`CMS/admin/hub-sites.php` normalisiert Aktion, Hub-Site-ID und Template-Key jetzt einmalig in einem gemeinsamen Payload**: Der Entry validiert Kontrollfelder damit klarer vor Delete-/Duplicate- und Template-Dispatch. |
| **2.7.274** | 🟠 perf | Admin/Hub | **Der Hub-Entry bindet `CMS/assets/js/admin-hub-sites.js` für Listen- und Template-Ansicht jetzt sauber über `pageAssets` ein**: Hub-Sites und Templates hängen damit sichtbarer am gemeinsamen Admin-Asset-Vertrag. |
| **2.7.274** | 🟡 refactor | Admin/Hub | **`cms_admin_hub_sites_handle_action()` arbeitet direkt mit dem vorbereiteten Payload**: Site- und Template-Aktionen bleiben dadurch klarer voneinander getrennt. |

---

### v2.7.273 — 26. März 2026 · Audit-Batch 355, Comments-View weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.273** | 🔴 fix | Admin/Comments | **`CMS/admin/views/comments/list.php` verzichtet bei Status- und Delete-Aktionen auf lokale Inline-`onclick`-Handler**: Die View hängt Kommentaraktionen jetzt über Datenattribute an ein dediziertes Admin-Asset. |
| **2.7.273** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-comments.js` übernimmt Status-Dispatch, Delete-Confirm sowie Bulk-Bar-/Dropdown-Verhalten zentral**: Die Kommentarverwaltung hält ihre Laufzeitlogik dadurch nicht länger direkt im PHP-Template. |
| **2.7.273** | 🟡 refactor | Admin/Comments | **Die Kommentar-Liste trennt Markup, Formularzustand und Moderationslogik klarer**: Das hält die Moderation konsistenter zu anderen modernisierten Admin-Views. |

---

### v2.7.272 — 26. März 2026 · Audit-Batch 354, Comments-Entry weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.272** | 🔴 fix | Admin/Comments | **`CMS/admin/comments.php` bündelt Aktion, Kommentar-ID, Zielstatus, Bulk-Aktion und Bulk-IDs jetzt einmalig in einem normalisierten Payload**: Der POST-Pfad validiert Kontrollfelder damit klarer vor dem Modul-Dispatch. |
| **2.7.272** | 🟠 perf | Admin/Comments | **Der Comments-Entry bindet sein dediziertes UI-Asset jetzt sauber über `page_assets` ein**: Moderations- und Bulk-UI hängen damit sichtbarer am gemeinsamen Admin-Asset-Vertrag. |
| **2.7.272** | 🟡 refactor | Admin/Comments | **`cms_admin_comments_handle_action()` arbeitet direkt mit dem vorbereiteten Payload**: Status-, Delete- und Bulk-Pfade bleiben dadurch klarer voneinander getrennt. |

---

### v2.7.271 — 26. März 2026 · Audit-Batch 353, Groups-View weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.271** | 🔴 fix | Admin/Users | **`CMS/admin/views/users/groups.php` verzichtet bei Modal- und Delete-Aktionen auf lokale Inline-`onclick`-Handler**: Die View liefert jetzt Datenattribute für Edit-/Delete-Interaktionen statt eigener Script-Inseln. |
| **2.7.271** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-user-groups.js` übernimmt Modal-Befüllung und Delete-Confirm der Gruppenverwaltung zentral**: Die Gruppen-UI hält ihre Laufzeitlogik dadurch nicht länger direkt im PHP-Template. |
| **2.7.271** | 🟡 refactor | Admin/Users | **Die Gruppen-Ansicht trennt Kartendarstellung, Modalzustand und Delete-Flow klarer**: Das hält die Benutzerverwaltung konsistenter zu anderen modernisierten Admin-Views. |

---

### v2.7.270 — 26. März 2026 · Audit-Batch 352, Groups-Entry weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.270** | 🔴 fix | Admin/Users | **`CMS/admin/groups.php` normalisiert Aktion und Gruppen-ID jetzt einmalig in einem gemeinsamen Payload**: Der Entry pflegt dadurch keine separate Handler-Map mehr für seinen POST-Pfad. |
| **2.7.270** | 🟠 perf | Admin/Users | **Der Gruppen-Entry bindet sein dediziertes UI-Asset jetzt sauber über `page_assets` ein**: Modal- und Delete-Pfade hängen damit sichtbarer am gemeinsamen Admin-Asset-Vertrag. |
| **2.7.270** | 🟡 refactor | Admin/Users | **`cms_admin_groups_handle_action()` dispatcht direkt über den vorbereiteten Payload**: Guard, ID-Prüfung und Modul-Aufruf bleiben dadurch klarer an einer kleinen Entry-Logik. |

---

### v2.7.269 — 26. März 2026 · Audit-Batch 351, Marketplace-View weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.269** | 🔴 fix | Admin/Plugins | **`CMS/admin/views/plugins/marketplace.php` pflegt Such-/Filter-Logik und Install-Confirm nicht länger über lokales Inline-Script bzw. Inline-`confirm(...)`**: Die View liefert jetzt JSON-Konfiguration und `data-confirm-*` statt eigener Script-Inseln. |
| **2.7.269** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-plugin-marketplace.js` übernimmt Suche und Kategorie-Filter des Marketplace zentral**: Die Plugin-Karten-UI bleibt dadurch näher an einem dedizierten Admin-Asset statt im PHP-View. |
| **2.7.269** | 🟡 refactor | Admin/Plugins | **Der Marketplace-View trennt Markup, Filterzustand und Installations-Confirm klarer**: Das hält die UI konsistenter zu anderen bereits modernisierten Admin-Ansichten. |

---

### v2.7.268 — 26. März 2026 · Audit-Batch 350, Marketplace-Entry weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.268** | 🔴 fix | Admin/Plugins | **`CMS/admin/plugin-marketplace.php` normalisiert Aktion und Slug jetzt gemeinsam in einem Payload**: Der Entry hält seinen POST-Pfad damit kompakter und re-normalisiert Installationsdaten nicht länger im Dispatch. |
| **2.7.268** | 🟠 perf | Admin/Plugins | **Der Marketplace-Entry bindet sein dediziertes UI-Asset jetzt sauber über `page_assets` ein**: Such-/Filter- und Confirm-Pfade hängen damit sichtbarer am gemeinsamen Admin-Asset-Vertrag. |
| **2.7.268** | 🟡 refactor | Admin/Plugins | **`cms_admin_plugin_marketplace_handle_action()` arbeitet direkt mit dem vorbereiteten Payload**: Guard, Aktionsprüfung und Modul-Dispatch bleiben dadurch klarer voneinander getrennt. |

---

### v2.7.267 — 26. März 2026 · Audit-Batch 349, Pages-View weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.267** | 🔴 fix | Admin/Pages | **`CMS/admin/views/pages/list.php` verzichtet auf lokales Bulk-/Grid-Script und Inline-`onchange`-Handler der Filterform**: Die View liefert stattdessen JSON-Konfiguration und CSS-/JS-Hooks für das dedizierte Asset. |
| **2.7.267** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-pages.js` übernimmt Grid-Initialisierung, Bulk-Bar und Filter-Auto-Submit zentral**: Die Seitenliste hält ihre Laufzeitlogik dadurch nicht länger direkt im PHP-Template. |
| **2.7.267** | 🟡 refactor | Admin/Pages | **Die Pages-Liste trennt Markup, Grid-Konfiguration und Bulk-Laufzeitlogik klarer**: Das hält die Seitenverwaltung konsistenter zu anderen modernisierten Admin-Views. |

---

### v2.7.266 — 26. März 2026 · Audit-Batch 348, Pages-Entry weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.266** | 🔴 fix | Admin/Pages | **`CMS/admin/pages.php` baut die Grid-Konfiguration für die Listenansicht jetzt über `cms_admin_pages_grid_config()` statt über großen Inline-JavaScript-String**: Der Entry hält damit seinen Listenpfad kompakter und weniger fehleranfällig. |
| **2.7.266** | 🟠 perf | Admin/Pages | **Die Pages-List-Ansicht bindet jetzt zusätzlich `CMS/assets/js/admin-pages.js` statt Grid-/Bulk-Initialisierung als Inline-Footer-Script zu bekommen**: Das reduziert weiteren Inline-Asset-Boilerplate im Entry-Vertrag. |
| **2.7.266** | 🟡 refactor | Admin/Pages | **Der Pages-Entry trennt Datenladung, Grid-Konfiguration und Asset-Vertrag klarer**: Listen- und Edit-Pfade bleiben dadurch sichtbarer voneinander getrennt. |

---

### v2.7.265 — 26. März 2026 · Audit-Batch 347, Dokumentations-View weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.265** | 🔴 fix | Admin/System | **`CMS/admin/views/system/documentation.php` zieht Dokument- und Bereichsdaten jetzt stärker über View-Model-Helfer zusammen**: Die Renderpfade greifen dadurch nicht länger wiederholt auf viele kleine Einzelhelper im Hauptfluss zu. |
| **2.7.265** | 🟠 perf | Admin/Views | **Die Dokumentations-Ansicht reduziert weiteren Render-Boilerplate im Listen- und Bereichspfad**: Dokument-Metadaten und Bereichszustände werden kompakter vorbereitet, bevor das Markup sie ausgibt. |
| **2.7.265** | 🟡 refactor | Admin/System | **Der Doku-View bleibt sichtbarer auf Renderblöcke statt auf verteilte Datensammelreste fokussiert**: Das hält die View konsistenter zu den bereits verdichteten Admin-Ansichten. |

---

### v2.7.264 — 26. März 2026 · Audit-Batch 346, Mail-Settings-Entry weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.264** | 🔴 fix | Admin/System | **`CMS/admin/mail-settings.php` normalisiert Tab und Aktion jetzt einmalig in einem gemeinsamen Payload**: Der POST-Pfad pflegt damit keine separate Handler-Map und keine doppelte Aktionsauflösung mehr. |
| **2.7.264** | 🟠 perf | Admin/System | **Der Mail-Settings-Entry dispatcht Transport-, Azure-, Graph-, Queue- und Cache-Aktionen jetzt über einen direkten `match`-Helper**: Guard, Tab-Validierung und Redirect-Ziel bleiben dadurch kompakter lesbar. |
| **2.7.264** | 🟡 refactor | Admin/System | **`cms_admin_mail_settings_handle_action()` hält den Aktionspfad klarer an einem kleinen Entry-Vertrag**: Das reduziert weiteren Dispatch-Boilerplate ohne die Fachlogik aus dem Modul zu ziehen. |

---

### v2.7.263 — 26. März 2026 · Audit-Batch 345, Landing-Page-Entry weiter verschlankt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.263** | 🔴 fix | Admin/Landing | **`CMS/admin/landing-page.php` normalisiert Aktion und Feature-ID jetzt einmalig über `cms_admin_landing_page_normalize_payload()`**: Der Entry pflegt dadurch keine Closure-basierte Handler-Map mehr für seine POST-Aktionen. |
| **2.7.263** | 🟠 perf | Admin/Landing | **Der Landing-Page-Entry dispatcht Header-, Content-, Footer-, Design- und Feature-Aktionen jetzt über einen direkten `match`-Pfad**: Validierung und Modul-Dispatch bleiben dadurch kompakter lesbar. |
| **2.7.263** | 🟡 refactor | Admin/Landing | **`cms_admin_landing_page_handle_action()` trennt Kontroll-Payload und Aktionsausführung klarer**: Delete-Feature hängt damit sichtbarer an demselben Entry-Vertrag wie die übrigen Landing-Aktionen. |

---

### v2.7.262 — 26. März 2026 · Audit-Batch 344, Cookie-Manager-View weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.262** | 🔴 fix | Admin/Legal | **`CMS/admin/views/legal/cookies.php` pflegt Modal- und Delete-Aktionen nicht länger über lokale Inline-`onclick`-/`confirm(...)`-Pfade**: Die View liefert jetzt Datenattribute und Confirm-Metadaten statt eigener Script-Inseln. |
| **2.7.262** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-cookie-manager.js` übernimmt Reset-, Edit- und Modal-Öffnungslogik für Kategorien und Services zentral**: Der Cookie-Manager hält seine Laufzeitlogik damit näher an einem dedizierten Admin-Asset statt direkt im PHP-Template. |
| **2.7.262** | 🟡 refactor | Admin/Legal | **`CMS/admin/cookie-manager.php` bindet das neue Asset konsistent über `page_assets` ein**: Der Cookie-Manager folgt damit sichtbarer demselben Asset-/Confirm-Vertrag wie andere modernisierte Admin-Ansichten. |

---

### v2.7.261 — 26. März 2026 · Audit-Batch 343, Cookie-Manager-Entry weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.261** | 🔴 fix | Admin/Legal | **`CMS/admin/cookie-manager.php` normalisiert Action, ID, Service-Slug und Self-Hosted-Flag jetzt einmalig in einem gemeinsamen Payload**: Delete- und Curated-Import-Pfade ziehen dadurch nicht länger mehrere getrennte kleine Normalisierungsschritte im `post_handler` nach. |
| **2.7.261** | 🟠 perf | Admin/Legal | **Der Cookie-Manager-Entry dispatcht seine Aktionen jetzt über `cms_admin_cookie_manager_handle_action()` mit bereits vorbereitetem Payload**: Guard, Validierung und Modul-Dispatch bleiben damit kompakter lesbar. |
| **2.7.261** | 🟡 refactor | Admin/Legal | **Kontrollfelder des Cookie-Managers hängen klarer an einem kleinen Entry-Vertrag, während Formularinhalte weiterhin im Modul sanitisiert werden**: Der Wrapper reduziert doppelten Kontrollfluss-Boilerplate, ohne den Fachpfad des Moduls zu verwischen. |

---

### v2.7.260 — 26. März 2026 · Audit-Batch 342, Plugins-View weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.260** | 🔴 fix | Admin/Plugins | **`CMS/admin/views/plugins/list.php` verzichtet bei Toggle- und Delete-Aktionen auf lokale Inline-`onchange`-/`confirm(...)`-Handler**: Die Plugin-Liste hängt damit sichtbarer am gemeinsamen Confirm-Vertrag und einer dedizierten JS-Datei. |
| **2.7.260** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-plugins.js` übernimmt das Auto-Submit der Aktivieren-/Deaktivieren-Switches zentral**: Der Toggle-Pfad muss damit nicht länger direkt im View-Markup gepflegt werden. |
| **2.7.260** | 🟡 refactor | Admin/Plugins | **`CMS/admin/plugins.php` bindet das neue Script über `page_assets` ein**: Die Plugin-Liste folgt dadurch demselben Asset-Vertrag wie andere bereits harmonisierte Admin-Seiten. |

---

### v2.7.259 — 26. März 2026 · Audit-Batch 341, Plugins-Vertrag weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.259** | 🔴 fix | Admin/Plugins | **`CMS/admin/plugins.php` normalisiert Action und Slug jetzt einmalig über `cms_admin_plugins_normalize_payload()`**: Der Entry hält damit seinen POST-Pfad kompakter und validiert keine Einzelteile mehrfach. |
| **2.7.259** | 🟠 perf | Admin/Plugins | **`CMS/admin/modules/plugins/PluginsModule.php` bündelt Slug-, Hauptdatei- und Plugin-Pfadlogik jetzt in kleinen Hilfsmethoden**: Aktivierung, Deaktivierung, Löschung und Active-Plugin-Lookup vermeiden dadurch weitere doppelte Pfad-/String-Reste. |
| **2.7.259** | 🟡 refactor | Admin/Plugins | **Der Plugins-Modulpfad prüft Plugin-Verzeichnisse und Hauptdateien konsistenter über gemeinsame Helper**: Das hält Normalisierung, Pfadauflösung und Delete-Schutz lesbarer an einer Stelle. |

---

### v2.7.258 — 26. März 2026 · Audit-Batch 340, Font-Manager-Vertrag weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.258** | 🔴 fix | Admin/Themes | **`CMS/admin/font-manager.php` normalisiert Action, Font-ID und Google-Font-Familie jetzt einmalig in einem gemeinsamen Payload**: Der POST-Pfad hängt dadurch konsistenter am Shared-Shell-Vertrag statt mehrere kleine Normalisierungsschritte separat zu pflegen. |
| **2.7.258** | 🟠 perf | Admin/Views | **`CMS/admin/views/themes/fonts.php` verzichtet in der Preview auf überflüssige statische `font-family`-Inline-Styles**: Die Vorschau bleibt an der bestehenden JS-Initialisierung statt doppelte Startwerte im Markup zu tragen. |
| **2.7.258** | 🟡 refactor | Admin/Themes | **Der Font-Manager bündelt seinen Dispatch klarer über `cms_admin_font_manager_normalize_payload()`**: Entry und View bleiben dadurch etwas schlanker und konsistenter. |

---

### v2.7.257 — 26. März 2026 · Audit-Batch 339, Media-Settings-Vertrag weiter verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.257** | 🔴 fix | Admin/Media | **`CMS/admin/modules/media/MediaModule.php` liefert Defaultwerte, Dateityp-Optionen und Thumbnail-Metadaten für den Settings-View jetzt zentral aus dem Modul**: Die Media-Einstellungen pflegen dadurch keine zweite lokale Default-/Options-Wahrheit mehr im Template. |
| **2.7.257** | 🟠 perf | Admin/Views | **`CMS/admin/views/media/settings.php` rendert Dateityp- und Thumbnail-Blöcke jetzt aus den vom Modul gelieferten Optionen**: Das reduziert lokale Listen- und Mapping-Duplikate und hält den View schlanker. |
| **2.7.257** | 🟡 refactor | Admin/Media | **Der Media-Settings-Pfad trennt View-Boilerplate sauberer vom Modulvertrag**: Größenwerte werden als MB für das Formular vorbereitet, und interne Feldnamen/Defaults bleiben näher an einer zentralen Stelle. |

---

### v2.7.256 — 26. März 2026 · Audit-Batch 338, Dokumentations-Entry weiter verschlankt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.256** | 🔴 fix | Admin/System | **`CMS/admin/documentation.php` normalisiert POST-Aktionen jetzt einmalig aus dem übergebenen Payload statt direkt mehrfach aus `$_POST`**: Der Entry hängt damit klarer am Shared-Shell-Post-Handler-Vertrag. |
| **2.7.256** | 🟠 perf | Admin/System | **Der Dokumentations-Entry verzichtet auf eine Handler-Map für nur eine Sync-Aktion**: Der direkte Dispatch über einen kleinen Action-Helper hält den Request-Pfad kompakter. |
| **2.7.256** | 🟡 refactor | Admin/System | **`cms_admin_documentation_handle_action()` bündelt den Aktionspfad der Doku-Seite explizit**: Guard, Normalisierung und Dispatch sind dadurch lesbarer getrennt. |

---

### v2.7.255 — 26. März 2026 · Audit-Batch 337, Data-Requests-View weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.255** | 🔴 fix | Admin/Legal | **`CMS/admin/views/legal/data-requests.php` pflegt Lösch- und Ausführungsaktionen nicht länger über lokale Inline-`confirm(...)`-Handler**: Die Formulare hängen jetzt am gemeinsamen `data-confirm-*`-Vertrag statt an verstreuten Button-Sonderpfaden. |
| **2.7.255** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-data-requests.js` übernimmt den Reject-Modal-Pfad der Data-Requests-Ansicht zentral**: Scope, Request-ID und Modal-Titel werden dadurch konsistent über Datenattribute gesetzt statt durch ein lokales Inline-Script. |
| **2.7.255** | 🟡 refactor | Admin/Legal | **`CMS/admin/data-requests.php` bindet das neue Asset sauber über `page_assets` ein**: Der DSGVO-Bereich folgt damit demselben Confirm-/Asset-Vertrag wie andere modernisierte Admin-Seiten. |

---

### v2.7.254 — 26. März 2026 · Audit-Batch 336, Marketplace-Remote-Pfad weiter gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.254** | 🔴 fix | Admin/Plugins | **`CMS/admin/plugin-marketplace.php` reicht Installationsdaten jetzt nur noch minimal normalisiert weiter**: Der Entry begrenzt den POST-Pfad für `install` damit auf den validierten Slug statt rohe Request-Reste in die Aktion mitzunehmen. |
| **2.7.254** | 🟠 perf | Admin/Marketplace | **`CMS/admin/modules/plugins/PluginMarketplaceModule.php` kanonisiert Registry-, Manifest- und Katalog-URLs vor Remote-Zugriffen zentral**: Host, Port, Credentials und Steuerzeichen werden dadurch einheitlicher geprüft, bevor externe Ressourcen geladen oder weitergereicht werden. |
| **2.7.254** | 🟡 refactor | Admin/Plugins | **Der Marketplace-Modulpfad nutzt jetzt gemeinsame Normalisierung für Slugs und Remote-URLs**: Registry-Fallback, relative Marketplace-Pfade und direkte Catalog-URLs hängen dadurch sichtbarer an einem konsistenten Remote-Vertrag. |

---

### v2.7.253 — 26. März 2026 · Audit-Batch 335, Legal-Sites-Cluster weiter gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.253** | 🔴 fix | Admin/Legal | **`CMS/admin/legal-sites.php` normalisiert Legal-Sites-Payloads jetzt allowlist-basiert pro Aktion**: `save` und `save_profile` reichen dadurch nicht länger rohe POST-Komplettpakete in den Modulpfad weiter. |
| **2.7.253** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-legal-sites.js` übernimmt die bisherige DOM-Logik der Legal-Sites-Ansicht zentral**: Requirement-Toggles, Privacy-Feature-Blöcke und Template-Einfügen hängen damit nicht länger als großes lokales Inline-Script im View. |
| **2.7.253** | 🟡 refactor | Admin/Legal | **`CMS/admin/views/legal/sites.php` trennt Markup und Laufzeitlogik klarer**: die View liefert nur noch JSON-Konfiguration und Datenattribute, während das Verhalten konsistent über das eingebundene Admin-Asset läuft. |

---

### v2.7.252 — 26. März 2026 · Audit-Batch 334, Dokumentations-View weiter verschlankt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.252** | 🔴 fix | Admin/System | **`CMS/admin/views/system/documentation.php` ersetzt die verbliebenen Render-Closures durch benannte View-Funktionen**: Metric-Cards, Dokument-Listen, Bereichs-Akkordeons und Dokument-Inhalt pflegen damit keinen verstreuten Inline-Closure-Pfad mehr. |
| **2.7.252** | 🟠 perf | Admin/Views | **Kleine Aufbereitungsreste wie Quellen-Text und Metric-Card-Konfiguration liegen jetzt ebenfalls hinter präfixierten Helpern**: der View hält weniger Logik im Hauptfluss und bleibt dadurch lesbarer wartbar. |
| **2.7.252** | 🟡 refactor | Admin/System | **Die Dokumentations-Ansicht konzentriert sich stärker auf den Renderfluss statt auf lokale Render-Werkzeuge**: der bestehende Flash-/HTML-Vertrag bleibt erhalten, aber die View-Struktur ist konsistenter zu anderen modernisierten Admin-Views. |

---

### v2.7.250 — 26. März 2026 · Audit-Batch 332, Updates-View weiter vereinheitlicht

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.250** | 🔴 fix | Admin/System | **`CMS/admin/views/system/updates.php` pflegt Core- und Plugin-Installationen nicht länger über lokale `confirm(...)`-Sonderpfade**: die Formulare hängen jetzt am gemeinsamen Confirm-Vertrag statt an einem View-lokalen Inline-Handler. |
| **2.7.250** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin.js` unterstützt jetzt wiederverwendbare Formular-Bestätigungen über `form[data-confirm-message]`**: Modal-Confirm und Fallback-Submit müssen dadurch nicht länger pro View separat nachgebaut werden. |
| **2.7.250** | 🟡 refactor | Admin/System | **Der Updates-View trennt Aktions-Markup und Laufzeitlogik klarer**: Core- und Plugin-Installationen nutzen denselben Confirm-Helfer wie andere harmonisierbare Admin-Aktionen. |

---

### v2.7.251 — 26. März 2026 · Audit-Batch 333, Media-Cluster weiter gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.251** | 🔴 fix | Admin/Media | **`CMS/admin/media.php` normalisiert Media-Aktions-Payloads jetzt gezielter pro Aktion und validiert kritische Request-Felder bereits im Wrapper**: Delete-, Rename-, Kategorie- und Settings-Pfade reichen dadurch nicht länger rohe POST-Daten weiter. |
| **2.7.251** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-media-integrations.js` übernimmt jetzt zusätzlich den Kategorie-Löschflow der Media-Kategorien-Ansicht**: Delete-Confirm und Form-Submit müssen dort nicht länger als lokale Script-Insel gepflegt werden. |
| **2.7.251** | 🟡 refactor | Admin/Media | **`CMS/admin/views/media/categories.php` trennt Markup und Laufzeitlogik klarer**: die View liefert für den Delete-Pfad nur noch Datenattribute und JSON-Konfiguration statt Inline-`onclick` plus lokalem `<script>`. |

---

### v2.7.249 — 26. März 2026 · Audit-Batch 331, Media-Library-View weiter entschlackt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.249** | 🔴 fix | Admin/Media | **`CMS/admin/views/media/library.php` pflegt Member-Ordner-Confirm und Delete-Aktionen nicht länger über lokale Inline-`onclick`-Attribute oder ein View-lokales `<script>`**: die View liefert stattdessen Datenattribute und JSON-Konfiguration. |
| **2.7.249** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-media-integrations.js` übernimmt die Media-Library-Aktionslogik jetzt zentral**: Delete-Submit und Member-Folder-Confirm laufen dadurch näher am bestehenden Media-Asset-Vertrag statt verteilt im PHP-View. |
| **2.7.249** | 🟡 refactor | Admin/Media | **Die Media-Library trennt Markup und Laufzeitlogik klarer**: verbleibende Action-Handler sitzen jetzt im bereits geladenen Admin-Media-Script statt als separate kleine Script-Insel im View. |

---

### v2.7.248 — 26. März 2026 · Audit-Batch 330, Font-Manager-View weiter entschlackt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.248** | 🔴 fix | Admin/Themes | **`CMS/admin/views/themes/fonts.php` pflegt Preview- und Lösch-Confirm-Logik nicht länger als lokales Inline-Script**: der View liefert nur noch Konfiguration und Markup, während das Verhalten ausgelagert wird. |
| **2.7.248** | 🟠 perf | Assets/Admin | **`CMS/assets/js/admin-font-manager.js` bündelt Font-Preview und Delete-Confirm für den Font Manager in einer dedizierten Admin-JS-Datei**: derselbe Code muss nicht länger direkt im PHP-View geparkt werden. |
| **2.7.248** | 🟡 refactor | Admin/Themes | **`CMS/admin/font-manager.php` bindet das neue Script konsistent über `pageAssets` ein**: der Font-Manager folgt damit demselben Admin-Asset-Vertrag wie andere modernisierte Bereiche. |

---

### v2.7.247 — 26. März 2026 · Audit-Batch 329, Font-Manager-Entry weiter verschlankt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.247** | 🔴 fix | Admin/Themes | **`CMS/admin/font-manager.php` ersetzt sein Closure-basiertes Action-Handler-Mapping durch einen direkten `match`-Dispatch**: der Entry pflegt keinen separaten Handler-Map-Sonderpfad mehr. |
| **2.7.247** | 🟠 perf | Admin/Themes | **Der POST-Pfad normalisiert Font-ID und Google-Font-Familie jetzt nur noch einmal**: kleine doppelte Dispatch- und Normalisierungsarbeit im Font-Manager-Entry entfällt. |
| **2.7.247** | 🟡 refactor | Admin/Themes | **Der Entry bleibt klarer auf Guard, Normalisierung und Modul-Dispatch fokussiert**: kleine anonyme Action-Closures werden zugunsten eines direkteren, besser lesbaren Request-Flows reduziert. |

---

### v2.7.246 — 26. März 2026 · Audit-Batch 328, Legal-Sites-Entry weiter verschlankt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.246** | 🔴 fix | Admin/Legal | **`CMS/admin/legal-sites.php` ersetzt den Closure-basierten Action-Dispatch durch einen direkten `match`-Dispatch**: der Entry pflegt keinen separaten Handler-Map-Sonderpfad mehr. |
| **2.7.246** | 🟠 perf | Admin/Views | **Der POST-Pfad normalisiert den Vorlagentyp jetzt nur noch einmal**: doppelte kleine Dispatch- und Normalisierungsarbeit im Legal-Sites-Entry entfällt. |
| **2.7.246** | 🟡 refactor | Admin/Legal | **Der Entry bleibt klarer auf Guard, Normalisierung und Dispatch fokussiert**: kleine anonyme Action-Closures werden zugunsten eines direkteren, besser lesbaren Request-Flows reduziert. |

---

### v2.7.245 — 26. März 2026 · Audit-Batch 327, Dokumentations-View weiter verschlankt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.245** | 🔴 fix | Admin/System | **`CMS/admin/views/system/documentation.php` ersetzt mehrere kleine anonyme Dokument- und Status-Helfer durch benannte View-Funktionen**: die Datei hält weniger Inline-Closure-Logik direkt im Kopfbereich. |
| **2.7.245** | 🟠 perf | Admin/Views | **Die Dokumentations-View reduziert weiteren Inline-Helper-Boilerplate, ohne ihren Rendervertrag zu verändern**: Dokumentlisten, Active-State-Checks und Bereichs-Metadaten bleiben dadurch lesbarer und konsistenter wartbar. |
| **2.7.245** | 🟡 refactor | Admin/System | **Der Dokumentations-View konzentriert sich klarer auf Render-Blöcke statt auf verteilte kleine Datentransformations-Closures**: die fachlichen Mini-Helfer sind jetzt sichtbar benannt und lokaler präfixiert. |

---

### v2.7.244 — 26. März 2026 · Audit-Batch 326, Media-Entry-/Modulvertrag weiter gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.244** | 🔴 fix | Admin/Media | **`CMS/admin/media.php` delegiert die Parent-Path-Auflösung jetzt an `CMS/admin/modules/media/MediaModule.php`**: der Entry pflegt keinen separaten String-/`dirname()`-Sonderpfad mehr neben dem Modulvertrag. |
| **2.7.244** | 🟠 perf | Admin/Views | **Upload-Fehler bauen Dateinamen wieder als rohe Textdaten statt vorzeitig HTML-escaped zusammen**: der Media-Flash-Pfad vermeidet damit doppelte Escaping-Arbeit und bleibt konsistenter zum zentralen Alert-Partial. |
| **2.7.244** | 🟡 refactor | Admin/Media | **Der Media-Entry hält Pfadnormalisierung und Fehlermeldungsaufbereitung klarer getrennt**: Parent-Path-Logik liegt näher am Modul, während der View-Vertrag wieder mit rohen Textdaten statt gemischtem HTML-Status arbeitet. |

---

### v2.7.243 — 26. März 2026 · Audit-Batch 325, System-Update-Warnboxen weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.243** | 🔴 fix | Admin/System | **`CMS/admin/views/system/updates.php` nutzt die verbliebenen komplexeren Core- und Theme-Update-Warnboxen jetzt ebenfalls über das gemeinsame `flash-alert.php`**: lokale `alert alert-warning`-Blöcke werden damit weiter aus dem View zurückgebaut. |
| **2.7.243** | 🟠 perf | Admin/Views | **Die Updates-Ansicht spart weiteren Alert-Boilerplate und hält Aktionsbuttons klarer getrennt vom gemeinsamen Hinweisvertrag**: künftige Verbesserungen an Typ-Mapping, Detail-Listen und Dismiss-Verhalten greifen dadurch zentral statt in lokalem Warn-Markup. |
| **2.7.243** | 🟡 refactor | Admin/System | **Der Updates-View bleibt konsistenter zu anderen harmonisierten Shared-Shell-Entrys**: sowohl einfache Statushinweise als auch die bislang komplexeren Update-Warnungen hängen jetzt sichtbarer am selben Partial-Vertrag. |

---

### v2.7.242 — 26. März 2026 · Audit-Batch 324, Media-Library-Alert weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.242** | 🔴 fix | Admin/Media | **`CMS/admin/views/media/library.php` nutzt den verbliebenen elFinder-Infohinweis jetzt ebenfalls über das gemeinsame `flash-alert.php`**: der Finder-Zweig pflegt keinen separaten lokalen `alert alert-info`-Block mehr. |
| **2.7.242** | 🟠 perf | Admin/Views | **Die Media-Library spart weiteren lokalen Alert-Boilerplate und übernimmt künftige Verbesserungen am gemeinsamen Alert-Partial automatisch mit**: Typ-Mapping, Detail-Listen und Dismiss-Verhalten bleiben dadurch zentral statt view-lokal gepflegt. |
| **2.7.242** | 🟡 refactor | Admin/Media | **Der Bibliotheks-View bleibt konsistenter zum bereits standardisierten Media-Entry und den übrigen harmonisierten Admin-Views**: auch der elFinder-Hinweis hängt jetzt sichtbar am Shared-View-Vertrag. |

---

### v2.7.241 — 26. März 2026 · Audit-Batch 323, Legal-Sites-Alerts weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.241** | 🔴 fix | Admin/Legal | **`CMS/admin/views/legal/sites.php` nutzt die verbliebenen lokalen Intro- und Datenschutz-Hinweisboxen jetzt ebenfalls über das gemeinsame `flash-alert.php`**: lokale Alert-Sonderpfade in der Legal-Sites-View werden damit weiter zurückgebaut. |
| **2.7.241** | 🟠 perf | Admin/Views | **Die Legal-Sites-View spart weiteren lokalen Alert-Boilerplate und übernimmt künftige Verbesserungen an Alert-Typen, Detail-Listen und Dismiss-Verhalten zentral mit**: insbesondere die featurebezogenen Datenschutz-Hinweise hängen jetzt sichtbar am Shared-View-Vertrag. |
| **2.7.241** | 🟡 refactor | Admin/Legal | **Der Rechtstext-Generator bleibt im View konsistenter zum bereits standardisierten Entry- und Flash-Vertrag**: Intro- und Feature-Hinweise werden nicht mehr als separate lokale Bootstrap-Alerts gepflegt. |

---

### v2.7.240 — 26. März 2026 · Audit-Batch 322, Font-Manager-Vertrag weiter gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.240** | 🔴 fix | Admin/Themes | **`CMS/admin/modules/themes/FontManagerModule.php` gibt bei Speicher- und Löschfehlern keine rohen Exception-Texte mehr an die Admin-UI weiter**: Fehler werden stattdessen strukturiert über `Logger::instance()->withChannel('admin.font-manager')` protokolliert. |
| **2.7.240** | 🟠 perf | Admin/Views | **`CMS/admin/views/themes/fonts.php` nutzt jetzt auch die verbliebene Self-Hosting-Hinweisbox über das gemeinsame `flash-alert.php`**: der Font-Manager spart weiteren lokalen Alert-Boilerplate und hängt sichtbarer am Shared-View-Vertrag. |
| **2.7.240** | 🟡 refactor | Admin/Themes | **Der hoch priorisierte Font-Manager zieht Fehler- und Hinweispfade näher an denselben Modul-/Partial-Vertrag wie andere modernisierte Admin-Bereiche**: Logging, UI-Meldungen und View-Hinweise bleiben konsistenter getrennt. |

---

### v2.7.239 — 26. März 2026 · Audit-Batch 321, Diagnose- und Member-Hinweise weiter harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.239** | 🔴 fix | Admin/Views | **`CMS/admin/views/system/diagnose.php` und `CMS/admin/views/member/general.php` nutzen verbleibende einfache Hinweisboxen jetzt ebenfalls über das gemeinsame `flash-alert.php`**: lokale Info-/Warning-Blöcke werden damit weiter aus einzelnen Views herausgezogen. |
| **2.7.239** | 🟠 perf | Admin/Views | **Diagnose- und Member-UI sparen weiteren Alert-Boilerplate und hängen sichtbarer am selben Shared-View-Vertrag wie andere modernisierte Admin-Seiten**: künftige Flash-Partial-Anpassungen greifen damit zentral statt dateiweise. |
| **2.7.239** | 🟡 refactor | Admin/Views | **Der Restcluster fachlicher Hinweisboxen schrumpft weiter**: View-spezifische Alert-Sonderpfade werden zugunsten eines einheitlicheren Partial-Vertrags reduziert. |

---

### v2.7.238 — 26. März 2026 · Audit-Batch 320, lokale Admin-Hinweise weiter auf Flash-Partial harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.238** | 🔴 fix | Admin/Views | **`CMS/admin/views/system/updates.php`, `system/documentation.php`, `themes/fonts.php` und `subscriptions/settings.php` nutzen einfache Status- und Hinweisblöcke jetzt über das gemeinsame `flash-alert.php`**: lokale Alert-Sonderpfade für Erfolg, Info, Warning und Error werden damit weiter aus einzelnen Views herausgezogen. |
| **2.7.238** | 🟠 perf | Admin/Views | **Die betroffenen Views sparen weiteren UI-Boilerplate und profitieren zentral von denselben Alert-Typ-Mappings, Detail-Listen und Dismiss-Standards**: spätere Partial-Anpassungen greifen dadurch automatisch in weiteren System-, Theme- und Abo-Oberflächen. |
| **2.7.238** | 🟡 refactor | Admin/Documentation | **`CMS/admin/views/system/documentation.php` pflegt keinen eigenen `$renderAlertBlock`-Renderer mehr**: die View hängt jetzt auch für Verfügbarkeits-, Sync- und Excerpt-Hinweise direkt am Shared-Partial-Vertrag. |

---

### v2.7.237 — 26. März 2026 · Audit-Batch 319, Plugins-/Themes-Entrys auf Shared-Shell verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.237** | 🔴 fix | Admin/Extensions | **`CMS/admin/plugins.php` und `CMS/admin/themes.php` laufen jetzt über die gemeinsame `section-page-shell.php` statt eigene Redirect-/Flash-/POST-Sonderpfade zu pflegen**: Access-Check, CSRF-Flow und Shell-Rendering hängen dadurch an demselben Shared-Vertrag wie die bereits modernisierten Admin-Entrys. |
| **2.7.237** | 🟠 perf | Admin/Extensions | **Die Plugin- und Theme-Verwaltung spart weiteren Entry-Boilerplate und nutzt denselben PRG-/Flash-Vertrag wie Marketplace, Media oder Packages**: Mutationspfade landen damit konsistenter wieder auf derselben Verwaltungsansicht. |
| **2.7.237** | 🟡 refactor | Admin/Extensions | **`CMS/admin/modules/plugins/PluginsModule.php` und `CMS/admin/modules/themes/ThemesModule.php` härten ihre UI-Verträge zusätzlich**: Plugin-Aktivierungs-/Deaktivierungsfehler werden strukturiert geloggt statt rohe Exception-Texte weiterzureichen, und Theme-Slugs/Erfolgsmeldungen werden konsistent normalisiert statt bereits im Modul HTML-escaped zu werden. |

---

### v2.7.236 — 26. März 2026 · Audit-Batch 318, Marketplace-Entrys und Installationspfade gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.236** | 🔴 fix | Admin/Marketplace | **`CMS/admin/plugin-marketplace.php` und `CMS/admin/theme-marketplace.php` laufen jetzt über die gemeinsame `section-page-shell.php` statt eigene Redirect-/Flash-/POST-Sonderpfade zu pflegen**: Access-Checks, CSRF-Flow und Shell-Rendering hängen dadurch an demselben Shared-Vertrag wie andere modernisierte Admin-Entrys. |
| **2.7.236** | 🟠 perf | Admin/Marketplace | **`CMS/admin/modules/plugins/PluginMarketplaceModule.php` und `CMS/admin/modules/themes/ThemeMarketplaceModule.php` normalisieren relative Remote-Manifest-/Downloadpfade jetzt strikter**: nur erlaubte HTTPS-Marketplace-Ziele mit sauberen relativen Pfaden werden noch zu Remote-URLs zusammengesetzt. |
| **2.7.236** | 🟡 refactor | Admin/Marketplace | **Auto-Installationen werden zusätzlich an `requires_cms`- und `requires_php`-Vorgaben gekoppelt**: inkompatible Plugin-/Theme-Pakete bleiben damit im Marketplace sichtbar, werden aber nicht mehr als automatisch installierbar angeboten. |

---

### v2.7.235 — 26. März 2026 · Audit-Batch 317, Flash-Ausgabe in 14 weiteren Admin-Views harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.235** | 🔴 fix | Admin/SEO | **`CMS/admin/views/seo/technical.php`, `social.php`, `meta.php`, `sitemap.php`, `schema.php`, `redirects.php`, `not-found.php`, `dashboard.php` und `audit.php` nutzen ihre Alert-Ausgabe jetzt über das gemeinsame `flash-alert.php`**: Erfolg-/Fehlerhinweise, Dismiss-Verhalten und Detail-Listen werden damit nicht länger lokal pro View gepflegt. |
| **2.7.235** | 🟠 perf | Admin/Views | **`CMS/admin/views/security/audit.php`, `plugins/marketplace.php`, `themes/marketplace.php`, `themes/list.php` und `toc/settings.php` hängen jetzt ebenfalls am selben Flash-Partial-Vertrag**: wiederholtes Alert-Markup entfällt in weiteren Admin-Bereichen, und spätere Partial-Verbesserungen greifen zentral. |
| **2.7.235** | 🟡 refactor | Admin/Core | **`CMS/admin/views/partials/flash-alert.php` unterstützt zusätzlich generische Detail-Listen aus `details`**: Redirect- und 404-Views können ihre zusätzlichen Hinweislisten jetzt über denselben zentralen Alert-Renderer ausgeben statt mit lokalem Sondermarkup. |

---

### v2.7.234 — 26. März 2026 · Audit-Batch 316, Member- und System-Flash-Ausgabe breit harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.234** | 🔴 fix | Admin/Member | **`CMS/admin/views/member/dashboard.php`, `general.php`, `design.php`, `frontend-modules.php`, `widgets.php`, `plugin-widgets.php`, `profile-fields.php`, `notifications.php` und `onboarding.php` nutzen ihre Seiten-Flash-Ausgabe jetzt über das gemeinsame `flash-alert.php`**: Member-Unterseiten pflegen dadurch keine eigenen Alert-Blöcke mehr vor der Subnav. |
| **2.7.234** | 🟠 perf | Admin/System | **`CMS/admin/views/system/email-alerts.php`, `response-time.php`, `cron-status.php`, `disk-usage.php`, `health-check.php`, `scheduled-tasks.php`, `info.php` und `diagnose.php` hängen jetzt ebenfalls am gemeinsamen Flash-Partial**: Monitoring- und Diagnose-Views sparen damit weiteren wiederholten Alert-Boilerplate. |
| **2.7.234** | 🟡 refactor | Admin/Views | **Insgesamt 17 Admin-Views wurden auf denselben Flash-Partial-Vertrag vereinheitlicht**: künftige Änderungen an Dismiss-Verhalten, Alert-Typ-Mapping oder Fehlerreport-Payloads greifen damit zentral statt dateiweise. |

---

### v2.7.233 — 26. März 2026 · Audit-Batch 315, SEO-Subnav auf gemeinsames Section-Partial standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.233** | 🔴 fix | Admin/SEO | **`CMS/admin/views/seo/subnav.php` nutzt jetzt das gemeinsame `section-subnav.php` statt eine lokale Navigationsstruktur zu pflegen**: die aktive Seite und Link-Ausgabe hängen dadurch näher am standardisierten Partial-Vertrag. |
| **2.7.233** | 🟠 perf | Admin/SEO | **Die SEO-Navigation spart eigenen Markup-/Class-Boilerplate und übernimmt künftige Subnav-Anpassungen automatisch mit**: nur die beiden Sitemap-/robots-Aktionsbuttons bleiben als schlanker separater Action-Block zurück. |
| **2.7.233** | 🟡 refactor | Admin/Views | **Ein weiterer Admin-View verlagert seinen Navigationsaufbau in ein gemeinsames Partial**: Subnav-Verhalten bleibt dadurch konsistenter zwischen SEO-, Performance-, System- und Member-Bereich. |

---

### v2.7.232 — 26. März 2026 · Audit-Batch 314, Flash-Ausgabe in Analytics- und Performance-Media-View harmonisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.232** | 🔴 fix | Admin/SEO | **`CMS/admin/views/seo/analytics.php` nutzt jetzt das gemeinsame `flash-alert.php` statt einen lokalen Alert-Block zu pflegen**: Dismiss-Handling und optionale Fehlerreport-Payloads hängen damit näher am standardisierten View-Vertrag. |
| **2.7.232** | 🟠 perf | Admin/Performance | **`CMS/admin/views/performance/media.php` rendert Shell-basierte Alerts jetzt ebenfalls über das gemeinsame Flash-Partial**: die View spart duplizierte Alert-Ausgabe und bleibt konsistenter zu anderen standardisierten Admin-Seiten. |
| **2.7.232** | 🟡 refactor | Admin/Views | **Zwei weitere Admin-Views verlassen ihre lokalen Alert-Sonderpfade**: Flash-Ausgabe und künftige Partial-Erweiterungen greifen dadurch zentral statt dateiweise. |

---

### v2.7.231 — 26. März 2026 · Audit-Batch 313, System-Monitor-Wrapper und Fehlerpfade weiter gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.231** | 🔴 fix | Admin/System | **`CMS/admin/modules/system/SystemInfoModule.php` gibt bei Diagnose-/Monitoring-Fehlern keine rohen Exception-Texte mehr an die Admin-Oberfläche aus**: technische Details werden stattdessen gekürzt im Audit-Log festgehalten, während die UI generische Fehlermeldungen zeigt. |
| **2.7.231** | 🟠 perf | Admin/System | **`CMS/admin/system-monitor-page.php` baut seinen Shell-Vertrag jetzt über einen benannten Helper auf und delegiert den Access-Check an die gemeinsame `section-page-shell.php`**: der Wrapper spart eigenen Header-Redirect-Boilerplate und bleibt näher an verwandten Shared-Entrys. |
| **2.7.231** | 🟡 refactor | Admin/System | **Wrapper- und Modul-Fehlerpfade sind klarer getrennt**: die Datei konzentriert sich stärker auf Section-Registry und Action-Dispatch, während Modulfehler zentral geloggt und UI-sicher aufbereitet werden. |

---

### v2.7.230 — 26. März 2026 · Audit-Batch 312, Performance-Wrapper weiter auf Shared-Shell verdichtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.230** | 🔴 fix | Admin/Performance | **`CMS/admin/performance-page.php` delegiert den Access-Check jetzt an die gemeinsame `section-page-shell.php` statt einen eigenen Header-Redirect-Sonderpfad vorzuhalten**: der Wrapper hängt dadurch enger am bestehenden Shared-Entry-Vertrag. |
| **2.7.230** | 🟠 perf | Admin/Performance | **Die Section-Shell-Konfiguration wird jetzt über einen benannten Helper aufgebaut**: Abschnitts-, Modul- und Datenlade-Kontext bleiben kompakter zusammengefasst und müssen nicht länger als loser Inline-Block gepflegt werden. |
| **2.7.230** | 🟡 refactor | Admin/Performance | **Die Datei konzentriert sich jetzt stärker auf Normalisierung und Aktionsregeln**: Shell-Zusammenbau und Access-Standardverhalten sind sichtbarer vereinheitlicht. |

---

### v2.7.229 — 26. März 2026 · Audit-Batch 311, Member-Dashboard-Overview-Entry weiter verschlankt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.229** | 🔴 fix | Admin/Member | **`CMS/admin/member-dashboard.php` nutzt für Legacy-Sektionen jetzt den gemeinsamen `redirect-alias-shell.php` statt einen eigenen Header-Redirect-Helfer zu pflegen**: der Overview-Entry hängt dadurch näher am vorhandenen Redirect-Wrapper-Vertrag. |
| **2.7.229** | 🟠 perf | Admin/Member | **Der eigentliche Overview-Flow delegiert Guard und Rendering jetzt klarer an `member-dashboard-page.php`**: der Entry spart verteiltes Redirect-Boilerplate und hält seinen Legacy-/Overview-Pfad kompakter. |
| **2.7.229** | 🟡 refactor | Admin/Member | **Die Datei konzentriert sich jetzt auf Legacy-Section-Normalisierung und kanonische Overview-Konfiguration**: Redirect- und Access-Standardverhalten bleiben stärker in den gemeinsamen Wrappers statt in einem zusätzlichen Sonderpfad. |

---

### v2.7.228 — 26. März 2026 · Audit-Batch 310, SEO-Wrapper auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.228** | 🔴 fix | Admin/SEO | **`CMS/admin/seo-page.php` nutzt jetzt die gemeinsame `section-page-shell.php` statt einen eigenen Redirect-/Flash-/Renderpfad zu pflegen**: die gemeinsame SEO-/Analytics-Schicht hängt dadurch näher am vorhandenen Shared-Entry-Vertrag. |
| **2.7.228** | 🟠 perf | Admin/SEO | **Section-, Capability-, Redirect- und Datenladepfade laufen jetzt zentral über die Shared-Shell**: der Wrapper spart verteiltes Boilerplate, während der Analytics-Alias automatisch denselben Shell-Flow mitnutzt. |
| **2.7.228** | 🟡 refactor | Admin/SEO | **Die Datei konzentriert sich jetzt auf Section-Registry, Capability-Helfer und Action-Dispatch**: CSRF-, Redirect- und Render-Standardverhalten bleiben sichtbar zentral in der gemeinsamen Shell. |

---

### v2.7.227 — 26. März 2026 · Audit-Batch 309, Users-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.227** | 🔴 fix | Admin/Users | **`CMS/admin/users.php` nutzt jetzt die gemeinsame `section-page-shell.php` statt einen eigenen Redirect-/Flash-/Renderpfad zu pflegen**: Listen- und Edit-Flow hängen dadurch näher am bestehenden Shared-Entry-Vertrag. |
| **2.7.227** | 🟠 perf | Admin/Users | **Die Benutzerverwaltung löst Listen- und Edit-Kontext jetzt zentral über die Shared-Shell auf und kann Save-Fehler inline auf derselben Edit-Ansicht weiter rendern**: GridJS- und Flash-Kontext müssen dadurch nicht länger separat im Entry nachgebaut werden. |
| **2.7.227** | 🟡 refactor | Admin/Users | **Die Datei konzentriert sich jetzt auf Action-Allowlist, View-Auflösung und Dispatch**: zusätzlich leakt der Save-Fehlerpfad im `UsersModule` keine rohen Exception-Texte mehr in die Admin-Oberfläche. |

---

### v2.7.226 — 26. März 2026 · Audit-Batch 308, Posts-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.226** | 🔴 fix | Admin/Posts | **`CMS/admin/posts.php` nutzt jetzt die gemeinsame `section-page-shell.php` statt einen eigenen Redirect-/Flash-/Renderpfad zu pflegen**: Listen- und Edit-Flow hängen dadurch näher am bestehenden Shared-Entry-Vertrag. |
| **2.7.226** | 🟠 perf | Admin/Posts | **Die Posts-Verwaltung löst Listen- und Edit-Kontext jetzt zentral über die Shared-Shell auf und kann Save-Fehler inline auf derselben Edit-Ansicht weiter rendern**: GridJS-, Editor.js- und Flash-Kontext müssen dadurch nicht länger separat im Entry nachgebaut werden. |
| **2.7.226** | 🟡 refactor | Admin/Posts | **Die Datei konzentriert sich jetzt auf Action-Allowlist, View-Auflösung und Dispatch**: die Posts-Views rendern Shell-basiertes Feedback zusätzlich über das gemeinsame Flash-Partial. |

---

### v2.7.225 — 26. März 2026 · Audit-Batch 307, Pages-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.225** | 🔴 fix | Admin/Pages | **`CMS/admin/pages.php` nutzt jetzt die gemeinsame `section-page-shell.php` statt einen eigenen Redirect-/Flash-/Renderpfad zu pflegen**: Listen- und Edit-Flow hängen dadurch näher am bestehenden Shared-Entry-Vertrag. |
| **2.7.225** | 🟠 perf | Admin/Core | **Die Shared-Shell unterstützt jetzt optionales Inline-Rendering nach POST-Fehlern**: komplexe Edit-Seiten wie die Seitenverwaltung können Fehlermeldungen im selben Renderpfad zeigen, ohne dafür wieder einen separaten Entry-Sonderpfad zu behalten. |
| **2.7.225** | 🟡 refactor | Admin/Pages | **Die Datei konzentriert sich jetzt auf Action-Allowlist, View-Auflösung und Dispatch**: die Seiten-Views rendern Shell-basiertes Feedback zusätzlich über das gemeinsame Flash-Partial. |

---

### v2.7.224 — 26. März 2026 · Audit-Batch 306, Packages-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.224** | 🔴 fix | Admin/Subscriptions | **`CMS/admin/packages.php` nutzt jetzt die gemeinsame `section-page-shell.php` statt einen eigenen Redirect-/Flash-/Renderpfad zu pflegen**: Paket-CRUD und Paket-Einstellungen hängen dadurch näher am bestehenden Shared-Entry-Vertrag. |
| **2.7.224** | 🟠 perf | Admin/Subscriptions | **Die Paketverwaltung spart verteiltes Entry-Boilerplate und zeigt Shell-basiertes Feedback im View jetzt ebenfalls über das gemeinsame Flash-Partial**: Save-, Seed-, Toggle-, Delete- und Settings-Pfade müssen ihren Alert-Flow nicht länger separat pflegen. |
| **2.7.224** | 🟡 refactor | Admin/Subscriptions | **Die Datei konzentriert sich jetzt auf Action-Allowlist, ID-Normalisierung und Dispatch**: Standardverhalten bleibt zentral in der Shared-Shell. |

---

### v2.7.223 — 26. März 2026 · Audit-Batch 305, Media-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.223** | 🔴 fix | Admin/Media | **`CMS/admin/media.php` nutzt jetzt die gemeinsame `section-page-shell.php` statt einen eigenen Tab-/Redirect-/Flash-/Renderpfad zu pflegen**: Bibliothek, Kategorien und Einstellungen hängen dadurch näher am bestehenden Shared-Entry-Vertrag. |
| **2.7.223** | 🟠 perf | Admin/Media | **Die Medienverwaltung spart verteiltes Entry-Boilerplate und reicht Media-Zusatz-Token sowie tab-spezifische View-/Asset-Kontexte zentral an die Views durch**: Upload-, Kategorie- und Settings-Aktionen landen konsistenter wieder auf ihrer Zielansicht. |
| **2.7.223** | 🟡 refactor | Admin/Media | **Die Datei konzentriert sich jetzt auf Action-Allowlist, Pfadnormalisierung und Dispatch**: Standardverhalten bleibt zentral in der Shared-Shell. |

---

### v2.7.222 — 26. März 2026 · Audit-Batch 304, Orders-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.222** | 🔴 fix | Admin/Subscriptions | **`CMS/admin/orders.php` nutzt jetzt die gemeinsame `section-page-shell.php` statt einen eigenen Redirect-/Flash-/Renderpfad zu pflegen**: CSRF-Flow, Datenladung und View-Rendering hängen dadurch näher am bestehenden Shared-Entry-Vertrag. |
| **2.7.222** | 🟠 perf | Admin/Subscriptions | **Der Orders-Entry spart verteiltes Boilerplate und hält den Statusfilter über den PRG-Flow näher am zentralen Shell-Vertrag**: Bestellstatus- und Paketzuweisungsaktionen landen konsistenter wieder auf derselben Übersicht. |
| **2.7.222** | 🟡 refactor | Admin/Subscriptions | **Die Datei konzentriert sich jetzt auf Action-Allowlist, Normalisierung und Dispatch**: Standardverhalten bleibt zentral in der Shared-Shell. |

---

### v2.7.221 — 26. März 2026 · Audit-Batch 303, Menü-Editor-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.221** | 🔴 fix | Admin/Menus | **`CMS/admin/menu-editor.php` läuft jetzt über die gemeinsame `section-page-shell.php` statt einen eigenen Redirect-/Flash-/Renderpfad zu pflegen**: Zugriffsgate, CSRF-Flow, Datenladung und Redirect-Ziele hängen damit näher am Shared-Entry-Vertrag. |
| **2.7.221** | 🟠 perf | Admin/Menus | **Der Menü-Editor spart verteiltes Entry-Boilerplate und zeigt Shell-basiertes Feedback im View jetzt ebenfalls über das gemeinsame Flash-Partial**: Bearbeitungs- und Löschpfade müssen ihren Alert-Flow nicht länger separat pflegen. |
| **2.7.221** | 🟡 refactor | Admin/Menus | **Die Datei reduziert sich jetzt auf kleine Helper, Action-Dispatch und Redirect-Konfiguration**: Standardverhalten bleibt zentral in der Shared-Shell. |

---

### v2.7.220 — 26. März 2026 · Audit-Batch 302, Hub-Sites-Entry auf die erweiterte Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.220** | 🔴 fix | Admin/Hub | **`CMS/admin/hub-sites.php` nutzt jetzt die erweiterte `section-page-shell.php` statt einen eigenen Redirect-/Flash-/Renderpfad mit Multi-View-Sonderlogik zu pflegen**: Listen-, Edit- und Template-Views hängen damit näher an einem gemeinsamen Shared-Entry-Vertrag. |
| **2.7.220** | 🟠 perf | Admin/Hub | **Die Shell kann jetzt auch dynamische View-, Titel- und Asset-Kontexte zentral auflösen**: Hub-Sites spart verteiltes Boilerplate selbst bei wechselnden Views und übernimmt künftige Shell-Verbesserungen automatisch mit. |
| **2.7.220** | 🟡 refactor | Admin/Hub | **Der Entry konzentriert sich jetzt auf View-Normalisierung, Action-Dispatch und Redirect-Zielberechnung**: Flash-Ausgabe in Templates/Edit-Views läuft ebenfalls konsistent über das gemeinsame Partial. |

---

### v2.7.219 — 26. März 2026 · Audit-Batch 301, Mail-Settings-Entry auf die erweiterte Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.219** | 🔴 fix | Admin/System | **`CMS/admin/mail-settings.php` nutzt jetzt die gemeinsame `section-page-shell.php` statt einen eigenen Tab-/Redirect-/Flash-/Renderpfad zu pflegen**: Guard, CSRF-Flow und Tab-Redirects hängen dadurch näher am Shared-Entry-Vertrag. |
| **2.7.219** | 🟠 perf | Admin/System | **Die Mail-/OAuth2-Verwaltung spart verteiltes Boilerplate und reicht `currentTab` sowie API-CSRF-Token zentral als Template-Kontext durch**: Transport-, Azure-, Graph-, Log- und Queue-Aktionen landen wieder konsistent auf ihrem Zieltab. |
| **2.7.219** | 🟡 refactor | Admin/System | **Die Datei konzentriert sich jetzt auf Tab-/Action-Normalisierung und Handler-Mapping**: Standardverhalten liegt zentral in Shared-Shell und View-Kontext. |

---

### v2.7.218 — 26. März 2026 · Audit-Batch 300, Legal-Sites-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.218** | 🔴 fix | Admin/Legal | **`CMS/admin/legal-sites.php` läuft jetzt über die gemeinsame `section-page-shell.php` statt einen eigenen Redirect-/Flash-/Renderpfad zu pflegen**: Access-Guard, CSRF-Flow, Profil-Roundtrip und Datenladung hängen dadurch näher am bestehenden Shared-Entry-Vertrag. |
| **2.7.218** | 🟠 perf | Admin/Legal | **Die Legal-Sites sparen verteiltes Entry-Boilerplate für Profilspeicherung, Generator und Seitenerstellung**: Flash-Ausgabe im View wurde zugleich auf das gemeinsame Partial harmonisiert. |
| **2.7.218** | 🟡 refactor | Admin/Legal | **Der Entry reduziert sich jetzt auf Action-Allowlist, Profil-Helfer und Dispatch-Konfiguration**: Standardverhalten bleibt zentral in der Shared-Shell. |

---

### v2.7.217 — 26. März 2026 · Audit-Batch 299, Landing-Page-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.217** | 🔴 fix | Admin/Landing | **`CMS/admin/landing-page.php` nutzt jetzt die gemeinsame `section-page-shell.php` statt einen eigenen Redirect-/Flash-/Renderpfad zu pflegen**: Guard, CSRF-Flow, Datenladung und Rendering hängen dadurch näher am bestehenden Shared-Entry-Vertrag. |
| **2.7.217** | 🟠 perf | Admin/Landing | **Der tab-basierte Landing-Page-Editor spart verteiltes Entry-Boilerplate und hält den aktiven Tab über den PRG-Flow stabil**: Header-, Content-, Footer-, Design- und Plugin-Aktionen landen wieder konsistent auf ihrem jeweiligen Tab. |
| **2.7.217** | 🟡 refactor | Admin/Landing | **Die Datei konzentriert sich jetzt auf Tab-/Action-Normalisierung und Dispatch**: Standardverhalten sowie Flash-Ausgabe liegen zentral in Shared-Shell und Flash-Partial. |

---

### v2.7.216 — 26. März 2026 · Audit-Batch 298, Gruppen-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.216** | 🔴 fix | Admin/Users | **`CMS/admin/groups.php` nutzt jetzt die gemeinsame `section-page-shell.php` statt einen eigenen Redirect-/Flash-/Renderpfad zu pflegen**: Admin-Guard, CSRF-Flow, Datenladung und Rendering hängen dadurch näher am bestehenden Shared-Entry-Vertrag. |
| **2.7.216** | 🟠 perf | Admin/Users | **Die Gruppenverwaltung spart verteiltes Entry-Boilerplate für Save- und Delete-Aktionen**: künftige Shell-Verbesserungen greifen damit automatisch auch für die Benutzergruppen-Verwaltung. |
| **2.7.216** | 🟡 refactor | Admin/Users | **Die Datei konzentriert sich jetzt auf Action-Allowlist, ID-Normalisierung und Dispatch**: Standardverhalten bleibt zentral in der Shared-Shell. |

---

### v2.7.215 — 26. März 2026 · Audit-Batch 297, Font-Manager-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.215** | 🔴 fix | Admin/Themes | **`CMS/admin/font-manager.php` läuft jetzt über die gemeinsame `section-page-shell.php` statt einen eigenen Redirect-/Flash-/Renderpfad zu pflegen**: Read-/Write-Gates, CSRF-Flow, Datenladung und Rendering hängen damit näher am vorhandenen Shared-Entry-Vertrag. |
| **2.7.215** | 🟠 perf | Admin/Themes | **Der Font-Manager spart verteiltes Boilerplate und nutzt im View jetzt ebenfalls das gemeinsame Flash-Partial**: Theme-Scans, Self-Hosting und Save-Aktionen teilen sich denselben zentralen Feedback- und Request-Flow. |
| **2.7.215** | 🟡 refactor | Admin/Themes | **Der Entry reduziert sich jetzt auf Capability-Helfer, Allowlist und Dispatch-Konfiguration**: Standardverhalten bleibt zentral in der Shared-Shell. |

---

### v2.7.214 — 26. März 2026 · Audit-Batch 296, Firewall-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.214** | 🔴 fix | Admin/Security | **`CMS/admin/firewall.php` läuft jetzt über die gemeinsame `section-page-shell.php` statt einen eigenen Redirect-/Flash-/Renderpfad zu pflegen**: Guard, CSRF-Flow, Datenladung und Rendering hängen damit näher am vorhandenen Shared-Entry-Vertrag. |
| **2.7.214** | 🟠 perf | Admin/Security | **Die Firewall spart verteiltes Entry-Boilerplate und übernimmt Shell-Verbesserungen automatisch mit**: die Datei konzentriert sich wieder auf Action-Allowlist, Capability-Checks und Dispatch. |
| **2.7.214** | 🟡 refactor | Admin/Security | **Der Entry reduziert sich jetzt auf kleine Helper und Shell-Konfiguration**: Standardverhalten bleibt zentral in der Shared-Shell. |

---

### v2.7.213 — 26. März 2026 · Audit-Batch 295, Error-Report-Endpoint auf gemeinsamen POST-Action-Wrapper standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.213** | 🔴 fix | Admin/System | **`CMS/admin/error-report.php` nutzt jetzt den neuen `post-action-shell.php` statt eigenen CSRF-/Redirect-/Flash-Boilerplate**: der POST-only-Endpunkt bleibt damit näher an einem kleinen gemeinsamen Request-Vertrag. |
| **2.7.213** | 🟠 perf | Admin/System | **Der Error-Report-Endpunkt spart wiederkehrende Redirect- und Flash-Helfer**: künftige POST-Wrapper-Verbesserungen greifen automatisch auch für Admin-Aktionsendpunkte ohne eigene View. |
| **2.7.213** | 🟡 refactor | Admin/System | **Die Datei konzentriert sich jetzt auf Payload-Normalisierung und Service-Aufruf**: Standardpfade liegen zentral in der neuen Post-Action-Shell. |

---

### v2.7.212 — 26. März 2026 · Audit-Batch 294, Design-Settings-Alias auf gemeinsamen Redirect-Wrapper standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.212** | 🔴 fix | Admin/Design | **`CMS/admin/design-settings.php` nutzt jetzt denselben kleinen Redirect-Alias-Wrapper statt eigenen Guard-/Redirect-Boilerplate**: Capability-Check und Zielpfad bleiben sichtbar, während der eigentliche Redirect-Flow zentral abgewickelt wird. |
| **2.7.212** | 🟠 perf | Admin/Design | **Der Design-Alias spart redundante Funktionsdefinitionen und Redirect-Pfade pro Request**: künftige Alias-Anpassungen greifen dadurch auf einer kleinen gemeinsamen Schicht statt in Einzeldateien. |
| **2.7.212** | 🟡 refactor | Admin/Design | **Die Datei reduziert sich jetzt auf Zugriffsgate und Zielroute**: Alias-Boilerplate liegt zentral in `redirect-alias-shell.php`. |

---

### v2.7.211 — 26. März 2026 · Audit-Batch 293, Deletion-Requests-Alias auf gemeinsamen Redirect-Wrapper standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.211** | 🔴 fix | Admin/Legal | **`CMS/admin/deletion-requests.php` nutzt jetzt denselben kleinen Redirect-Alias-Wrapper statt eigenen Guard-/Redirect-Boilerplate**: der Alias bleibt damit näher an einem kleinen gemeinsamen Vertrag und pflegt nicht länger eigene Redirect-Helfer. |
| **2.7.211** | 🟠 perf | Admin/Legal | **Der Löschantrags-Alias spart redundante Funktionsdefinitionen und harmoniert mit verwandten Legal-Alias-Seiten**: dieselbe Redirect-Schicht wird im selben Zug auch für `privacy-requests.php` verwendet. |
| **2.7.211** | 🟡 refactor | Admin/Legal | **Die Datei reduziert sich jetzt auf Zugriffsgate und Zielroute**: Alias-Boilerplate liegt zentral in `redirect-alias-shell.php`. |

---

### v2.7.210 — 26. März 2026 · Audit-Batch 292, Data-Requests-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.210** | 🔴 fix | Admin/Legal | **`CMS/admin/data-requests.php` nutzt jetzt die gemeinsame `section-page-shell.php` statt eines eigenen Redirect-/Flash-/Renderpfads**: Admin-Guard, Token-Prüfung, Scope-Dispatch, Datenladung und View-Rendering hängen dadurch näher am vorhandenen Shared-Entry-Vertrag. |
| **2.7.210** | 🟠 perf | Admin/Legal | **Die DSGVO-Arbeitsseite spart verteiltes Entry-Boilerplate für Auskunfts- und Löschanträge**: beide Modulpfade werden konsistent über denselben Shell-Request-Flow bedient. |
| **2.7.210** | 🟡 refactor | Admin/Legal | **Der Entry konzentriert sich jetzt auf Scope-Allowlist und Aktions-Dispatch**: Standardverhalten bleibt zentral in der Shared-Shell. |

---

### v2.7.209 — 26. März 2026 · Audit-Batch 291, Cookie-Manager-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.209** | 🔴 fix | Admin/Legal | **`CMS/admin/cookie-manager.php` läuft jetzt über die gemeinsame `section-page-shell.php` statt einen eigenen Redirect-/Flash-/Renderpfad zu pflegen**: Admin-Guard, CSRF-Flow, Datenladung und Rendering hängen damit näher am vorhandenen Shared-Entry-Vertrag. |
| **2.7.209** | 🟠 perf | Admin/Legal | **Der Cookie-Manager spart verteiltes Boilerplate und zeigt Flash-Feedback jetzt sichtbar im View an**: Scanner-, Import-, Save- und Delete-Aktionen nutzen denselben zentralen Session-Alert-Flow. |
| **2.7.209** | 🟡 refactor | Admin/Legal | **Die Datei reduziert sich jetzt auf Allowlists, Normalisierung und Dispatch**: Standardverhalten bleibt zentral in der Shared-Shell. |

---

### v2.7.208 — 26. März 2026 · Audit-Batch 290, Documentation-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.208** | 🔴 fix | Admin/System | **`CMS/admin/documentation.php` nutzt jetzt die gemeinsame `section-page-shell.php` statt eines eigenen Redirect-/Flash-/Renderpfads**: Admin-Guard, Token-Prüfung, Datenladung und View-Rendering hängen dadurch näher am bestehenden Shared-Entry-Vertrag. |
| **2.7.208** | 🟠 perf | Admin/System | **Die Dokumentationsseite spart verteiltes Entry-Boilerplate und hält die Dokumentauswahl auch nach POST-Redirects stabil**: das ausgewählte Dokument bleibt über denselben query-fähigen Shell-Redirect erhalten. |
| **2.7.208** | 🟡 refactor | Admin/System | **Der Entry konzentriert sich jetzt auf Dokument-Normalisierung und Sync-Dispatch**: Standardpfade liegen sichtbar zentral in der Admin-Shell. |

---

### v2.7.207 — 26. März 2026 · Audit-Batch 289, Comments-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.207** | 🔴 fix | Admin/Content | **`CMS/admin/comments.php` läuft jetzt über die gemeinsame `section-page-shell.php` statt einen eigenen Redirect-/Flash-/Renderpfad zu pflegen**: View-Guard, CSRF-Flow, Datenladung und Rendering hängen damit näher am vorhandenen Shared-Entry-Vertrag. |
| **2.7.207** | 🟠 perf | Admin/Content | **Die Kommentarverwaltung spart verteiltes Boilerplate und hält den Statusfilter über den PRG-Flow stabil**: Freigabe-, Spam-, Trash- und Delete-Aktionen landen wieder auf derselben gefilterten Listenansicht. |
| **2.7.207** | 🟡 refactor | Admin/Content | **Die Datei reduziert sich jetzt auf Allowlists, Normalisierung und Aktions-Dispatch**: Standardverhalten bleibt zentral in der Shared-Shell. |

---

### v2.7.206 — 26. März 2026 · Audit-Batch 288, Backup-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.206** | 🔴 fix | Admin/System | **`CMS/admin/backups.php` läuft jetzt über die gemeinsame `section-page-shell.php` statt einen eigenen Redirect-/Flash-/Renderpfad zu pflegen**: Read-/Write-Checks, Datenladung und View-Rendering hängen dadurch näher am vorhandenen Shared-Entry-Vertrag. |
| **2.7.206** | 🟠 perf | Admin/System | **Der Backup-Entry spart verteiltes Boilerplate und doppelten Guard-Aufwand**: spätere Shell-Verbesserungen greifen automatisch auch für Backup & Restore. |
| **2.7.206** | 🟡 refactor | Admin/System | **Der Entry ist jetzt auf Aktions- und Zugriffsnormalisierung reduziert**: Redirect-, Flash- und Render-Standardlogik bleibt zentral in der Admin-Shell. |

---

### v2.7.205 — 26. März 2026 · Audit-Batch 287, AntiSpam-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.205** | 🔴 fix | Admin/Security | **`CMS/admin/antispam.php` nutzt jetzt die gemeinsame `section-page-shell.php` statt eines eigenen Redirect-/Flash-/Renderpfads**: der Entry übernimmt dadurch keine lose Standardlogik mehr neben der Shell. |
| **2.7.205** | 🟠 perf | Admin/Security | **Der AntiSpam-Entry spart wiederkehrendes Boilerplate für Guard, Datenladung und Rendering**: künftige Shell-Verbesserungen wirken automatisch auch auf den Security-Bereich. |
| **2.7.205** | 🟡 refactor | Admin/Security | **Die Datei konzentriert sich jetzt auf Aktions-Allowlist und Capability-Gates**: Standardverhalten liegt sichtbar zentral in der Shared-Shell. |

---

### v2.7.204 — 26. März 2026 · Audit-Batch 286, Dashboard-Entry auf die gemeinsame Section-Shell standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.204** | 🔴 fix | Admin/Dashboard | **`CMS/admin/index.php` nutzt jetzt die gemeinsame `section-page-shell.php` statt eines separaten Sonderpfads**: Auth-Guard, Modul-Initialisierung, Datenladung und Rendering laufen dadurch nicht mehr losgelöst vom vorhandenen Shared-Entry-Muster. |
| **2.7.204** | 🟠 perf | Admin/Dashboard | **Der Dashboard-Entry spart doppelten Boilerplate-Overhead und bleibt näher am zentralen Wrapper-Vertrag**: spätere Shell-Verbesserungen greifen damit automatisch auch auf `/admin`. |
| **2.7.204** | 🟡 refactor | Admin/Dashboard | **Das Dashboard hängt jetzt sichtbarer an derselben Admin-Section-Infrastruktur wie andere standardisierte Entrys**: Sonderlogik im Entry wurde auf Konfiguration reduziert. |

---

### v2.7.203 — 26. März 2026 · Audit-Batch 285, Section-Page-Shell bei Access-, Flash- und Asset-Normalisierung nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.203** | 🔴 fix | Admin/Core | **`CMS/admin/partials/section-page-shell.php` kapselt Access-Checks, Flash-Payloads und Asset-Normalisierung jetzt robuster zentral**: Shared-Entrys übernehmen dadurch keine losen Session-, Asset- oder Redirect-Annahmen mehr unterschiedlich pro Wrapper. |
| **2.7.203** | 🟠 perf | Admin/Core | **Die gemeinsame Shell filtert ungültige CSS-/JS-Assets früher und vereinheitlicht Flash-/Redirect-Pfade**: Standard-Entrys müssen diese wiederkehrenden Schritte nicht mehr einzeln nachbauen. |
| **2.7.203** | 🟡 refactor | Admin/Core | **Der Shared-Entry-Vertrag ist klarer geworden**: Access-Checker, Flash-Payload und Asset-Listen liegen sichtbar zentral statt verteiltem Boilerplate in einzelnen Entrys. |

---

### v2.7.202 — 26. März 2026 · Audit-Batch 284, Redirect- und 404-Entries auf section-spezifische Admin-Datensichten umgestellt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.202** | 🔴 fix | Admin/SEO | **`redirect-manager.php` und `not-found-monitor.php` ziehen ihre Daten jetzt über section-spezifische Modulzugriffe statt denselben Voll-Datensatz zu laden**: Redirect-Regeln und 404-Logs übernehmen dadurch keine unnötigen Fremddaten mehr zwischen den beiden SEO-Entrys. |
| **2.7.202** | 🟠 perf | Admin/SEO | **Redirect-Manager und 404-Monitor vermeiden unnötigen Admin-Overfetch vor dem Rendern**: jede Seite erhält nur noch ihren tatsächlich benötigten Redirect-, Log-, Target- und Site-Scope. |
| **2.7.202** | 🟡 refactor | Admin/SEO | **Die Entry-Pfade hängen näher an einem kleinen Section-Datenvertrag**: die Seitenauswahl bleibt sichtbar am Modul statt losem Vertrauen auf einen gemeinsamen Voll-Dump. |

---

### v2.7.201 — 26. März 2026 · Audit-Batch 283, Redirect-Manager-Modul um section-spezifische Datenzugriffe ergänzt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.201** | 🔴 fix | Admin/SEO | **`RedirectManagerModule.php` bietet jetzt getrennte Loader für Redirect-Manager und 404-Monitor**: der Modulvertrag reicht dadurch nicht mehr pauschal denselben Voll-Datensatz an beide SEO-Seiten weiter. |
| **2.7.201** | 🟠 perf | Admin/SEO | **Das Modul kann Redirect- und 404-Views gezielter bedienen**: Seiten fordern nur noch den benötigten Scope statt unnötige Nachbar-Daten mitzuschleppen. |
| **2.7.201** | 🟡 refactor | Admin/SEO | **Der Admin-Vertrag bleibt klarer lesbar**: `getRedirectManagerData()` und `getNotFoundMonitorData()` dokumentieren die Datensicht jetzt explizit am Modul. |

---

### v2.7.200 — 26. März 2026 · Audit-Batch 282, RedirectService auf getrennte Admin-Datensichten und Shared-Helper aufgeteilt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.200** | 🔴 fix | Core/SEO | **`RedirectService.php` liefert Redirect-Manager und 404-Monitor jetzt über getrennte Admin-Datensichten statt nur über `getAdminData()`**: Redirect-Regeln, 404-Logs, Stats und Targets bleiben dadurch näher am jeweils benötigten Seiten-Scope. |
| **2.7.200** | 🟠 perf | Core/SEO | **Die Redirect-Verwaltung vermeidet unnötigen Datenballast zwischen Redirect- und 404-Pfaden**: Entrys transportieren nicht länger zwangsläufig dieselbe Vollmenge aus Regeln, Logs, Targets und Sites. |
| **2.7.200** | 🟡 refactor | Core/SEO | **Gemeinsame Helfer für Redirect-Regeln, 404-Logs, Targets und Stats kapseln die Datensichten jetzt sichtbar zentral**: der Service hängt näher an einem kleinen wiederverwendbaren Admin-Datenvertrag. |

---

### v2.7.199 — 26. März 2026 · Audit-Batch 281, Dashboard-Modul nutzt vorhandene Stats für Attention-Items weiter

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.199** | 🔴 fix | Admin/Dashboard | **`DashboardModule.php` reicht den bereits geladenen Stats-Bestand jetzt direkt an die Attention-Items weiter**: der Dashboard-Renderpfad löst dadurch keine zweite Vollberechnung derselben Kennzahlen mehr aus. |
| **2.7.199** | 🟠 perf | Admin/Dashboard | **Das Dashboard spart eine unnötige Service-Runde pro Request**: KPIs, Highlights und Attention-Items teilen sich dieselbe Stats-Basis statt Doppelarbeit. |
| **2.7.199** | 🟡 refactor | Admin/Dashboard | **Der Modulvertrag wird expliziter**: vorbereitete Dashboard-Stats bleiben sichtbar im selben Renderkontext statt implizit nochmals im Service nachgeladen zu werden. |

---

### v2.7.198 — 26. März 2026 · Audit-Batch 280, DashboardService akzeptiert vorhandene Stats für Attention-Items

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.198** | 🔴 fix | Core/Dashboard | **`DashboardService::getAttentionItems()` kann vorhandene Stats jetzt direkt verwerten**: Attention-Items hängen dadurch nicht mehr zwingend an einer erneuten Komplettberechnung des Stats-Bundles. |
| **2.7.198** | 🟠 perf | Core/Dashboard | **Der Service reduziert unnötige Aggregationsarbeit im selben Request**: bereits vorbereitete Dashboard-Werte werden wiederverwendet statt sofort neu eingesammelt. |
| **2.7.198** | 🟡 refactor | Core/Dashboard | **Die Attention-Logik hängt näher an einem kleinen Datenvertrag**: vorbereitete Stats werden optional explizit hereingereicht statt implizit aus einem zweiten Full-Load zu stammen. |

---

### v2.7.197 — 26. März 2026 · Audit-Batch 279, DashboardService cached Komplett-Stats pro Request

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.197** | 🔴 fix | Core/Dashboard | **`DashboardService.php` cached `getAllStats()` jetzt request-lokal**: wiederholte Stats-Zugriffe im selben Dashboard-Lauf verursachen dadurch keine zweite identische Komplettaggregation mehr. |
| **2.7.197** | 🟠 perf | Core/Dashboard | **Das Dashboard spart doppelte User-, Page-, Media-, Session-, Security-, Performance- und Order-Statistikarbeit**: identische Daten werden pro Request nur einmal aufgebaut. |
| **2.7.197** | 🟡 refactor | Core/Dashboard | **Die Service-Schicht bekommt einen klareren Request-State**: bereits aggregierte Dashboard-Stats bleiben sichtbar zentral am Service statt losem erneuten Vollaufruf. |

---

### v2.7.196 — 26. März 2026 · Audit-Batch 278, Performance-Settings-Alias auf kanonische Section-Konfiguration reduziert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.196** | 🔴 fix | Admin/Performance | **`performance-settings.php` übergibt nur noch die kanonische `settings`-Section an den Shared-Wrapper**: der Settings-Alias übernimmt damit keine eigene Route-/View-/Titel-Duplikation mehr außerhalb der zentralen Performance-Registry. |
| **2.7.196** | 🟠 perf | Admin/Performance | **Der Settings-Alias spart redundante Konfigurationsarbeit vor dem Wrapper**: Route-, View- und Active-Page-Werte kommen nur noch aus der kanonischen Section-Matrix. |
| **2.7.196** | 🟡 refactor | Admin/Performance | **Der Alias hängt näher am Shared-Wrapper-Vertrag**: die Datei bleibt ein schlanker Section-Entry statt eigenem Konfigurationsduplikat. |

---

### v2.7.195 — 26. März 2026 · Audit-Batch 277, Performance-Sessions-Alias auf kanonische Section-Konfiguration reduziert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.195** | 🔴 fix | Admin/Performance | **`performance-sessions.php` übergibt nur noch die kanonische `sessions`-Section an den Shared-Wrapper**: der Sessions-Alias übernimmt damit keine eigene Route-/View-/Titel-Duplikation mehr außerhalb der zentralen Performance-Registry. |
| **2.7.195** | 🟠 perf | Admin/Performance | **Der Sessions-Alias spart redundante Konfigurationsarbeit vor dem Wrapper**: Route-, View- und Active-Page-Werte kommen nur noch aus der kanonischen Section-Matrix. |
| **2.7.195** | 🟡 refactor | Admin/Performance | **Der Alias hängt näher am Shared-Wrapper-Vertrag**: die Datei bleibt ein schlanker Section-Entry statt eigenem Konfigurationsduplikat. |

---

### v2.7.194 — 26. März 2026 · Audit-Batch 276, Performance-Media-Alias auf kanonische Section-Konfiguration reduziert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.194** | 🔴 fix | Admin/Performance | **`performance-media.php` übergibt nur noch die kanonische `media`-Section an den Shared-Wrapper**: der Medien-Alias übernimmt damit keine eigene Route-/View-/Titel-Duplikation mehr außerhalb der zentralen Performance-Registry. |
| **2.7.194** | 🟠 perf | Admin/Performance | **Der Medien-Alias spart redundante Konfigurationsarbeit vor dem Wrapper**: Route-, View- und Active-Page-Werte kommen nur noch aus der kanonischen Section-Matrix. |
| **2.7.194** | 🟡 refactor | Admin/Performance | **Der Alias hängt näher am Shared-Wrapper-Vertrag**: die Datei bleibt ein schlanker Section-Entry statt eigenem Konfigurationsduplikat. |

---

### v2.7.193 — 26. März 2026 · Audit-Batch 275, Performance-Datenbank-Alias auf kanonische Section-Konfiguration reduziert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.193** | 🔴 fix | Admin/Performance | **`performance-database.php` übergibt nur noch die kanonische `database`-Section an den Shared-Wrapper**: der Datenbank-Alias übernimmt damit keine eigene Route-/View-/Titel-Duplikation mehr außerhalb der zentralen Performance-Registry. |
| **2.7.193** | 🟠 perf | Admin/Performance | **Der Datenbank-Alias spart redundante Konfigurationsarbeit vor dem Wrapper**: Route-, View- und Active-Page-Werte kommen nur noch aus der kanonischen Section-Matrix. |
| **2.7.193** | 🟡 refactor | Admin/Performance | **Der Alias hängt näher am Shared-Wrapper-Vertrag**: die Datei bleibt ein schlanker Section-Entry statt eigenem Konfigurationsduplikat. |

---

### v2.7.192 — 26. März 2026 · Audit-Batch 274, Performance-Cache-Alias auf kanonische Section-Konfiguration reduziert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.192** | 🔴 fix | Admin/Performance | **`performance-cache.php` übergibt nur noch die kanonische `cache`-Section an den Shared-Wrapper**: der Cache-Alias übernimmt damit keine eigene Route-/View-/Titel-Duplikation mehr außerhalb der zentralen Performance-Registry. |
| **2.7.192** | 🟠 perf | Admin/Performance | **Der Cache-Alias spart redundante Konfigurationsarbeit vor dem Wrapper**: Route-, View- und Active-Page-Werte kommen nur noch aus der kanonischen Section-Matrix. |
| **2.7.192** | 🟡 refactor | Admin/Performance | **Der Alias hängt näher am Shared-Wrapper-Vertrag**: die Datei bleibt ein schlanker Section-Entry statt eigenem Konfigurationsduplikat. |

---

### v2.7.191 — 26. März 2026 · Audit-Batch 273, Monitoring-Cron-Alias auf kanonische Section-Konfiguration reduziert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.191** | 🔴 fix | Admin/System | **`monitor-cron-status.php` übergibt nur noch die kanonische `cron`-Section an den Shared-Wrapper**: der Cron-Alias übernimmt damit keine eigene Route-/View-/Titel-Duplikation mehr außerhalb der zentralen Monitoring-Registry. |
| **2.7.191** | 🟠 perf | Admin/System | **Der Cron-Alias spart redundante Konfigurationsarbeit vor dem Wrapper**: Route-, View- und Active-Page-Werte kommen nur noch aus der kanonischen Section-Matrix. |
| **2.7.191** | 🟡 refactor | Admin/System | **Der Alias hängt näher am Shared-Wrapper-Vertrag**: die Datei bleibt ein schlanker Section-Entry statt eigenem Konfigurationsduplikat. |

---

### v2.7.190 — 26. März 2026 · Audit-Batch 272, Monitoring-E-Mail-Alias auf kanonische Section-Konfiguration reduziert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.190** | 🔴 fix | Admin/System | **`monitor-email-alerts.php` übergibt nur noch die kanonische `email-alerts`-Section an den Shared-Wrapper**: der Monitoring-Alias übernimmt damit keine eigene Route-/View-/Titel-Duplikation mehr außerhalb der zentralen Monitoring-Registry. |
| **2.7.190** | 🟠 perf | Admin/System | **Der E-Mail-Alias spart redundante Konfigurationsarbeit vor dem Wrapper**: Route-, View- und Active-Page-Werte kommen nur noch aus der kanonischen Section-Matrix. |
| **2.7.190** | 🟡 refactor | Admin/System | **Der Alias hängt näher am Shared-Wrapper-Vertrag**: die Datei bleibt ein schlanker Section-Entry statt eigenem Konfigurationsduplikat. |

---

### v2.7.189 — 26. März 2026 · Audit-Batch 271, Monitoring-Health-Alias auf kanonische Section-Konfiguration reduziert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.189** | 🔴 fix | Admin/System | **`monitor-health-check.php` übergibt nur noch die kanonische `health-check`-Section an den Shared-Wrapper**: der Health-Alias übernimmt damit keine eigene Route-/View-/Titel-Duplikation mehr außerhalb der zentralen Monitoring-Registry. |
| **2.7.189** | 🟠 perf | Admin/System | **Der Health-Alias spart redundante Konfigurationsarbeit vor dem Wrapper**: Route-, View- und Active-Page-Werte kommen nur noch aus der kanonischen Section-Matrix. |
| **2.7.189** | 🟡 refactor | Admin/System | **Der Alias hängt näher am Shared-Wrapper-Vertrag**: die Datei bleibt ein schlanker Section-Entry statt eigenem Konfigurationsduplikat. |

---

### v2.7.188 — 26. März 2026 · Audit-Batch 270, Monitoring-Scheduled-Tasks-Alias auf kanonische Section-Konfiguration reduziert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.188** | 🔴 fix | Admin/System | **`monitor-scheduled-tasks.php` übergibt nur noch die kanonische `scheduled-tasks`-Section an den Shared-Wrapper**: der Scheduled-Tasks-Alias übernimmt damit keine eigene Route-/View-/Titel-Duplikation mehr außerhalb der zentralen Monitoring-Registry. |
| **2.7.188** | 🟠 perf | Admin/System | **Der Scheduled-Tasks-Alias spart redundante Konfigurationsarbeit vor dem Wrapper**: Route-, View- und Active-Page-Werte kommen nur noch aus der kanonischen Section-Matrix. |
| **2.7.188** | 🟡 refactor | Admin/System | **Der Alias hängt näher am Shared-Wrapper-Vertrag**: die Datei bleibt ein schlanker Section-Entry statt eigenem Konfigurationsduplikat. |

---

### v2.7.187 — 26. März 2026 · Audit-Batch 269, Monitoring-Disk-Alias auf kanonische Section-Konfiguration reduziert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.187** | 🔴 fix | Admin/System | **`monitor-disk-usage.php` übergibt nur noch die kanonische `disk`-Section an den Shared-Wrapper**: der Disk-Alias übernimmt damit keine eigene Route-/View-/Titel-Duplikation mehr außerhalb der zentralen Monitoring-Registry. |
| **2.7.187** | 🟠 perf | Admin/System | **Der Disk-Alias spart redundante Konfigurationsarbeit vor dem Wrapper**: Route-, View- und Active-Page-Werte kommen nur noch aus der kanonischen Section-Matrix. |
| **2.7.187** | 🟡 refactor | Admin/System | **Der Alias hängt näher am Shared-Wrapper-Vertrag**: die Datei bleibt ein schlanker Section-Entry statt eigenem Konfigurationsduplikat. |

---

### v2.7.186 — 26. März 2026 · Audit-Batch 268, Monitoring-Response-Time-Alias auf kanonische Section-Konfiguration reduziert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.186** | 🔴 fix | Admin/System | **`monitor-response-time.php` übergibt nur noch die kanonische `response-time`-Section an den Shared-Wrapper**: der Response-Time-Alias übernimmt damit keine eigene Route-/View-/Titel-Duplikation mehr außerhalb der zentralen Monitoring-Registry. |
| **2.7.186** | 🟠 perf | Admin/System | **Der Response-Time-Alias spart redundante Konfigurationsarbeit vor dem Wrapper**: Route-, View- und Active-Page-Werte kommen nur noch aus der kanonischen Section-Matrix. |
| **2.7.186** | 🟡 refactor | Admin/System | **Der Alias hängt näher am Shared-Wrapper-Vertrag**: die Datei bleibt ein schlanker Section-Entry statt eigenem Konfigurationsduplikat. |

---

### v2.7.185 — 26. März 2026 · Audit-Batch 267, Performance-Overview-Alias auf kanonische Section-Konfiguration reduziert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.185** | 🔴 fix | Admin/Performance | **`performance.php` übergibt nur noch die kanonische `overview`-Section an den Shared-Wrapper**: der Overview-Alias übernimmt damit keine eigene Route-/View-/Titel-Duplikation mehr außerhalb der zentralen Performance-Registry. |
| **2.7.185** | 🟠 perf | Admin/Performance | **Der Overview-Alias spart redundante Konfigurationsarbeit vor dem Wrapper**: Route-, View- und Active-Page-Werte kommen nur noch aus der kanonischen Section-Matrix. |
| **2.7.185** | 🟡 refactor | Admin/Performance | **Der Alias hängt näher am Shared-Wrapper-Vertrag**: die Datei bleibt ein schlanker Section-Entry statt eigenem Konfigurationsduplikat. |

---

### v2.7.184 — 26. März 2026 · Audit-Batch 266, Diagnose-Alias auf kanonische Section-Konfiguration reduziert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.184** | 🔴 fix | Admin/System | **`diagnose.php` übergibt nur noch die kanonische `diagnose`-Section an den Shared-Wrapper**: der Diagnose-Alias übernimmt damit keine eigene Route-/View-/Titel-Duplikation mehr außerhalb der zentralen Monitoring-Registry. |
| **2.7.184** | 🟠 perf | Admin/System | **Der Diagnose-Alias spart redundante Konfigurationsarbeit vor dem Wrapper**: Route-, View- und Active-Page-Werte kommen nur noch aus der kanonischen Section-Matrix. |
| **2.7.184** | 🟡 refactor | Admin/System | **Der Alias hängt näher am Shared-Wrapper-Vertrag**: die Datei bleibt ein schlanker Section-Entry statt eigenem Konfigurationsduplikat. |

---

### v2.7.183 — 26. März 2026 · Audit-Batch 265, Info-Alias auf kanonische Section-Konfiguration reduziert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.183** | 🔴 fix | Admin/System | **`info.php` übergibt nur noch die kanonische `info`-Section an den Shared-Wrapper**: der Info-Alias übernimmt damit keine eigene Route-/View-/Titel-Duplikation mehr außerhalb der zentralen Monitoring-Registry. |
| **2.7.183** | 🟠 perf | Admin/System | **Der Info-Alias spart redundante Konfigurationsarbeit vor dem Wrapper**: Route-, View- und Active-Page-Werte kommen nur noch aus der kanonischen Section-Matrix. |
| **2.7.183** | 🟡 refactor | Admin/System | **Der Alias hängt näher am Shared-Wrapper-Vertrag**: die Datei bleibt ein schlanker Section-Entry statt eigenem Konfigurationsduplikat. |

---

### v2.7.182 — 26. März 2026 · Audit-Batch 264, Analytics-Alias auf kanonische Section-Konfiguration reduziert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.182** | 🔴 fix | Admin/SEO | **`analytics.php` übergibt nur noch die kanonische `analytics`-Section an den Shared-Wrapper**: der Analytics-Alias übernimmt damit keine eigene Route-/View-/Titel-Duplikation mehr außerhalb der zentralen SEO-Registry. |
| **2.7.182** | 🟠 perf | Admin/SEO | **Der Analytics-Alias spart redundante Konfigurationsarbeit vor dem Wrapper**: Route-, View- und Active-Page-Werte kommen nur noch aus der kanonischen Section-Matrix. |
| **2.7.182** | 🟡 refactor | Admin/SEO | **Der Alias hängt näher am Shared-Wrapper-Vertrag**: die Datei bleibt ein schlanker Section-Entry statt eigenem Konfigurationsduplikat. |

---

### v2.7.181 — 26. März 2026 · Audit-Batch 263, Performance-Wrapper auf kanonische Section-Seitenregistries gezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.181** | 🔴 fix | Admin/Performance | **`performance-page.php` bindet Overview-, Cache-, Datenbank-, Medien-, Session- und Settings-Unterseiten jetzt an eine kanonische Section-Registry für Route, View, Titel und Active-Page**: section-fremde oder divergierende Alias-Konfigurationen werden damit nicht mehr lose in denselben Shared-Wrapper hineingereicht. |
| **2.7.181** | 🟠 perf | Admin/Performance | **Der Performance-Wrapper spart redundante Alias-Konfigurationsarbeit vor dem Renderpfad**: Route-/View-Metadaten kommen nur noch aus einer zentralen Matrix statt aus jedem Unterseiten-Entry separat. |
| **2.7.181** | 🟡 refactor | Admin/Performance | **Der Wrapper hängt näher an einem kleinen Section-Vertrag**: Kanonische Seitenkonfiguration und Section-Normalisierung liegen jetzt sichtbar zentral statt verteiltem Konfigurationsduplikat in Alias-Dateien. |

---

### v2.7.180 — 26. März 2026 · Audit-Batch 262, System-Monitor-Wrapper auf kanonische Section-Seitenregistries gezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.180** | 🔴 fix | Admin/System | **`system-monitor-page.php` bindet Info-, Diagnose- und Monitoring-Unterseiten jetzt an eine kanonische Section-Registry für Route, View, Titel und Active-Page**: section-fremde oder divergierende Alias-Konfigurationen werden damit nicht mehr lose in denselben Shared-Wrapper hineingereicht. |
| **2.7.180** | 🟠 perf | Admin/System | **Der Monitoring-Wrapper spart redundante Alias-Konfigurationsarbeit vor dem Renderpfad**: Route-/View-Metadaten kommen nur noch aus einer zentralen Matrix statt aus jedem Unterseiten-Entry separat. |
| **2.7.180** | 🟡 refactor | Admin/System | **Der Wrapper hängt näher an einem kleinen Section-Vertrag**: Kanonische Seitenkonfiguration und Section-Normalisierung liegen jetzt sichtbar zentral statt verteiltem Konfigurationsduplikat in Alias-Dateien. |

---

### v2.7.179 — 25. März 2026 · Audit-Batch 261, gemeinsame Section-Shell bei Route-/View-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.179** | 🔴 fix | Admin/Core | **`section-page-shell.php` normalisiert Shared-Routen jetzt serverseitig, verlangt vorhandene View-Dateien und bündelt Redirects zentral**: gemeinsame Member-, Performance- und System-Pfade übernehmen dadurch keine losen `route_path`- oder `view_file`-Annahmen mehr direkt in der generischen Shell. |
| **2.7.179** | 🟠 perf | Admin/Core | **Die generische Section-Shell verwirft ungültige View-/Route-Konfigurationen früher und billiger**: falsche oder leere Shell-Ziele scheitern vor unnötigen Header-, Sidebar- oder View-Ladevorgängen im Shared-Wrapper. |
| **2.7.179** | 🟡 refactor | Admin/Core | **Die gemeinsame Shell hängt näher an einem kleinen Shared-Vertrag aus Route-, View- und Redirect-Helfern**: Normalisierung und Redirects liegen jetzt sichtbar zentral statt losem Vertrauen auf rohe Wrapper-Konfigurationen. |

---

### v2.7.178 — 25. März 2026 · Audit-Batch 260, SEO-Suite-Modul auf section-spezifische Datensichten umgestellt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.178** | 🔴 fix | Admin/SEO | **`SeoSuiteModule.php` liefert Dashboard-, Audit-, Analytics-, Meta-, Social-, Schema-, Sitemap- und Technical-Unterseiten jetzt nur noch ihren tatsächlich benötigten Datenausschnitt**: SEO-Unterseiten übernehmen damit keine unnötigen Voll-Dumps aus Audit-, Tracking-, Schema-, Sitemap- und Redirect-Pfaden mehr stillschweigend über denselben Sammelaufruf. |
| **2.7.178** | 🟠 perf | Admin/SEO | **SEO-Unterseiten vermeiden teure Sammelabfragen außerhalb ihres eigenen Scopes**: Analytics-, Sitemap-, Schema- oder Technical-Seiten ziehen nur noch ihre jeweiligen Datenpfade und sparen unnötige Audit-, Tracking- oder Redirect-Arbeit bei fremden Bereichen. |
| **2.7.178** | 🟡 refactor | Admin/SEO | **Das Modul hängt näher an einem section-gebundenen Datenvertrag**: `getSectionData()` und ein gemeinsamer Section-Kontext kapseln die abschnittsspezifische Sicht jetzt sichtbar zentral statt losem Vertrauen auf `getData()` für jede Unterseite. |

---

### v2.7.177 — 25. März 2026 · Audit-Batch 259, gemeinsame SEO-Schicht auf section-spezifische Datenladung gezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.177** | 🔴 fix | Admin/SEO | **`seo-page.php` lädt pro SEO-Unterseite jetzt section-spezifische Daten statt pauschal den kompletten SEO-Datensatz**: Shared-SEO-Pfade verlassen sich damit nicht länger auf denselben Voll-Dump für Dashboard, Audit, Analytics, Meta, Social, Schema, Sitemap oder Technical. |
| **2.7.177** | 🟠 perf | Admin/SEO | **Der gemeinsame SEO-Wrapper vermeidet unnötige Voll-Ladepfade für Analytics-, Schema-, Sitemap- und Technical-Seiten**: Renderpfade ziehen nur noch ihren jeweiligen Scope und sparen unnötige Audit-, Tracking- oder Redirect-Daten bei fremden Bereichen. |
| **2.7.177** | 🟡 refactor | Admin/SEO | **Der Wrapper hängt näher an einem kleinen Section-Vertrag aus Capability-, Action- und Data-Scope-Gates**: Render- und POST-Pfade bleiben sichtbarer zentral gebündelt statt losem Sammelabruf über dieselbe Full-Data-Schiene. |

---

### v2.7.176 — 25. März 2026 · Audit-Batch 258, Member-Dashboard-Modul auf section-spezifische Datensichten umgestellt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.176** | 🔴 fix | Admin/Member | **`MemberDashboardModule.php` liefert Overview-, General-, Widget-, Profilfeld-, Design-, Frontend-, Notification-, Onboarding- und Plugin-Widget-Unterseiten jetzt nur noch ihren tatsächlich benötigten Datenausschnitt**: Member-Unterseiten übernehmen damit keine unnötigen Voll-Dumps aus Stats-, Widget-, Profil- oder Plugin-Widget-Pfaden mehr stillschweigend über denselben Sammelaufruf. |
| **2.7.176** | 🟠 perf | Admin/Member | **Member-Unterseiten vermeiden teure Sammelabfragen außerhalb ihres eigenen Scopes**: Widgets, Notifications, Profilfelder oder Plugin-Widgets lösen nur noch ihre jeweiligen Hilfsdaten aus und sparen unnötige Stats-/Plugin-/Overview-Arbeit bei fremden Bereichen. |
| **2.7.176** | 🟡 refactor | Admin/Member | **Das Modul hängt näher an einem section-gebundenen Datenvertrag**: `getSectionData()` und kleine Empty-/Overview-Helfer kapseln die abschnittsspezifische Sicht jetzt sichtbar zentral statt losem Vertrauen auf `getData()` für jede Unterseite. |

---

### v2.7.175 — 25. März 2026 · Audit-Batch 257, gemeinsame Member-Dashboard-Schicht auf section-spezifische Datenladung gezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.175** | 🔴 fix | Admin/Member | **`member-dashboard-page.php` lädt pro Unterseite jetzt section-spezifische Daten statt pauschal den kompletten Dashboard-Datensatz**: Shared-Member-Pfade verlassen sich damit nicht länger auf denselben Voll-Dump für Overview, General, Widgets, Profilfelder, Design, Notifications, Onboarding oder Plugin-Widgets. |
| **2.7.175** | 🟠 perf | Admin/Member | **Der gemeinsame Member-Dashboard-Wrapper vermeidet unnötige Voll-Ladepfade für alle Unterseiten**: Renderpfade ziehen nur noch ihren jeweiligen Scope und sparen unnötige Stats-, Widget-, Profil- oder Plugin-Widget-Daten bei fremden Bereichen. |
| **2.7.175** | 🟡 refactor | Admin/Member | **Der Wrapper hängt näher an einem kleinen Section-Vertrag aus Capability-, Action- und Data-Scope-Gates**: Render- und POST-Pfade bleiben sichtbarer zentral gebündelt statt losem Sammelabruf über dieselbe Full-Data-Schiene. |

---

### v2.7.174 — 25. März 2026 · Audit-Batch 256, Performance-Modul auf section-spezifische Datensichten umgestellt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.174** | 🔴 fix | Admin/Performance | **`PerformanceModule.php` liefert Cache-, Datenbank-, Medien-, Session- und Settings-Unterseiten jetzt nur noch ihren tatsächlich benötigten Datenausschnitt**: Unterseiten übernehmen damit keine unnötigen Voll-Dumps aus Cache-, Media-, DB-, Session- und PHP-Info-Pfaden mehr stillschweigend über denselben Sammelaufruf. |
| **2.7.174** | 🟠 perf | Admin/Performance | **Performance-Unterseiten vermeiden teure Sammelmetriken außerhalb ihres eigenen Scopes**: Cache-, DB-, Medien- oder Session-Seiten lösen nur noch ihre jeweiligen Metriken aus und sparen unnötige Telemetrie- und Scan-Arbeit bei fremden Bereichen. |
| **2.7.174** | 🟡 refactor | Admin/Performance | **Das Modul hängt näher an einem section-gebundenen Datenvertrag**: `getSectionData()` kapselt die abschnittsspezifische Datensicht sichtbar zentral statt losem Vertrauen auf `getData()` für jede Unterseite. |

---

### v2.7.173 — 25. März 2026 · Audit-Batch 255, gemeinsame Performance-Schicht bei Section-Daten und Read-Gate nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.173** | 🔴 fix | Admin/Performance | **`performance-page.php` bindet jetzt auch die reine Ansicht explizit an `manage_settings` und lädt pro Unterseite nur noch deren section-spezifische Daten**: Shared-Performance-Pfade verlassen sich damit nicht länger bloß auf den generischen Admin-Guard oder pauschale Voll-Datensätze für jede Unterseite. |
| **2.7.173** | 🟠 perf | Admin/Performance | **Der gemeinsame Performance-Wrapper vermeidet unnötige Voll-Ladepfade für Cache-, Medien-, DB-, Session- und Settings-Seiten**: Unterseiten scheitern früher am Read-Gate und laden bei gültigem Zugriff nur noch ihren eigenen Scope. |
| **2.7.173** | 🟡 refactor | Admin/Performance | **Der Wrapper hängt näher an einem kleinen Section-Vertrag aus Read-Gate, Action-Allowlist und Daten-Scope**: Render- und POST-Pfade bleiben sichtbarer zentral gebündelt statt losem Sammelabruf über dieselbe Full-Data-Schiene. |

---

### v2.7.172 — 25. März 2026 · Audit-Batch 254, SystemInfo-Modul auf section-spezifische Datensichten umgestellt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.172** | 🔴 fix | Admin/System | **`SystemInfoModule.php` liefert Info-, Diagnose- und Monitoring-Unterseiten jetzt nur noch ihren tatsächlich benötigten Datenausschnitt**: Info-, Diagnose-, Cron-, Disk-, Response-Time-, Health-, Scheduled-Tasks- und Alert-Pfade übernehmen damit keine unnötigen Voll-Dumps sensibler Runtime-, Query- oder Monitoring-Daten mehr quer über denselben Sammelaufruf. |
| **2.7.172** | 🟠 perf | Admin/System | **System-Unterseiten vermeiden teure Komplett-Scans außerhalb ihres eigenen Scopes**: Cron-, Disk-, Health- und Response-Time-Seiten ziehen nur noch ihre jeweiligen Prüfungen statt pauschal das gesamte Systempaket mitzuladen. |
| **2.7.172** | 🟡 refactor | Admin/System | **Das Modul hängt näher an einem section-gebundenen Datenvertrag**: `getSectionData()` sowie verkleinerte Info-/Diagnose-Sichten kapseln den benötigten Scope sichtbar zentral statt losem Vertrauen auf den Voll-`getData()`-Pfad. |

---

### v2.7.171 — 25. März 2026 · Audit-Batch 253, gemeinsame System-Monitor-Schicht bei Section-/Action-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.171** | 🔴 fix | Admin/System | **`system-monitor-page.php` normalisiert Monitoring-Sections jetzt serverseitig, bindet die Sammelschicht an `manage_settings` und akzeptiert POST-Aktionen nur noch pro passender Unterseite**: Diagnose- und Alert-Pfade übernehmen dadurch keine losen `section`-/`action`-Werte oder pauschalen Admin-Zugriffe mehr direkt im Shared-Wrapper. |
| **2.7.171** | 🟠 perf | Admin/System | **Die gemeinsame System-Monitor-Schicht verwirft section- oder capability-fremde Requests früher und billiger**: unzulässige Render- oder POST-Pfade scheitern vor unnötigen Modulaufrufen in Diagnose-, Cron-, Health- oder Alert-Ansichten. |
| **2.7.171** | 🟡 refactor | Admin/System | **Der System-Sammel-Wrapper hängt näher an einem kleinen Section-Vertrag**: Section-Normalisierung, Action-Allowlist und Capability-Gates liegen jetzt sichtbar zentral statt losem Vertrauen auf die generische Section-Shell. |

---

### v2.7.170 — 25. März 2026 · Audit-Batch 252, gemeinsame SEO-/Analytics-Schicht bei section-spezifischen Capability-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.170** | 🔴 fix | Admin/SEO | **`seo-page.php` bindet SEO-Unterseiten jetzt an section-spezifische Read-/Write-Capabilities**: Analytics akzeptiert Lesezugriffe nur noch über `manage_settings` oder `view_analytics`, während Mutationen pro Section kontrolliert an `manage_settings` gebunden bleiben und capability-fremde Requests nicht mehr lose durch denselben Shared-Wrapper laufen. |
| **2.7.170** | 🟠 perf | Admin/SEO | **Die gemeinsame SEO-Schicht verwirft section-fremde oder capability-fremde Requests früher und billiger**: Render- und POST-Pfade scheitern vor unnötigen Modulaufrufen, wenn Read-/Write-Vertrag und angeforderte Unterseite nicht zusammenpassen. |
| **2.7.170** | 🟡 refactor | Admin/SEO | **Der SEO-Sammel-Wrapper hängt näher an einem kleinen, section-gebundenen Admin-Vertrag**: Read-/Write-Matrix und Capability-Helfer liegen jetzt sichtbar zentral statt losem Vertrauen auf den pauschalen Sammel-Entry. |

---

### v2.7.169 — 25. März 2026 · Audit-Batch 251, Member-Dashboard-Entry bei Legacy-Sections und Overview-Capabilities nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.169** | 🔴 fix | Admin/Member | **`member-dashboard.php` normalisiert Legacy-Sections jetzt wieder serverseitig und bindet die Overview an passende Read-Capabilities**: Legacy-Weiterleitungen übernehmen damit keine losen `section`-Werte mehr, und die Dashboard-Übersicht verlässt sich nicht länger nur auf `isAdmin()`. |
| **2.7.169** | 🟠 perf | Admin/Member | **Der Member-Dashboard-Entry verwirft unzulässige Legacy-Ziele früher und billiger**: section-fremde oder capability-fremde Requests scheitern vor unnötigen Redirects in nachgelagerte Unterseiten. |
| **2.7.169** | 🟡 refactor | Admin/Member | **Der Overview-Entry hängt näher am gemeinsamen Member-Dashboard-Vertrag**: Legacy-Routenmap und Overview-Access liegen jetzt sichtbar zentral statt losem Query-Vertrauen im Alias-Entry. |

---

### v2.7.168 — 25. März 2026 · Audit-Batch 250, Mail-Settings-Entry bei Read-/Write-Capability-Matrix nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.168** | 🔴 fix | Admin/System | **`mail-settings.php` trennt Leserechte und Mutationen jetzt explizit über eine Capability-Matrix**: Transport-, Azure-, Graph-, Logs- und Queue-Bereiche bleiben nur noch für `manage_settings`/`manage_system` lesbar, während Mutationen kontrolliert an `manage_settings` gebunden sind. |
| **2.7.168** | 🟠 perf | Admin/System | **Der Mail-Entry verwirft capability-fremde Mutationen früher und billiger**: POST-Pfade scheitern bereits vor CSRF- und Modul-Dispatch, wenn der Write-Vertrag nicht erfüllt ist. |
| **2.7.168** | 🟡 refactor | Admin/System | **Der Mail-Settings-Entry hängt näher am kleinen Wrapper-Vertrag aus Tab-, Action- und Capability-Gates**: Read-/Write-Helfer liegen jetzt sichtbar im Einstieg statt bloßem Vertrauen auf den breiten Admin-Guard. |

---

### v2.7.167 — 25. März 2026 · Audit-Batch 249, Legal-Sites-Entry bei Capability- und Template-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.167** | 🔴 fix | Admin/Legal | **`legal-sites.php` bindet Read-/Write-Zugriffe jetzt explizit an `manage_settings` und normalisiert Actions früher**: Save-, Profil-, Generate- und Create-Page-Pfade übernehmen damit keine capability-fremden Requests oder losen Action-Werte mehr direkt im Einstieg. |
| **2.7.167** | 🟠 perf | Admin/Legal | **Der Legal-Sites-Entry verwirft unzulässige Mutationen früher und billiger**: unbekannte Aktionen oder ungültige `template_type`-Werte scheitern vor unnötigen Modulaufrufen im Generator- und Page-Flow. |
| **2.7.167** | 🟡 refactor | Admin/Legal | **Der Rechtstext-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Capability-, Action- und Template-Gates liegen jetzt sichtbar in kleinen Helfern statt losem Vertrauen auf den Modulpfad. |

---

### v2.7.166 — 25. März 2026 · Audit-Batch 248, Font-Manager-Entry bei Read-/Write-Capabilities nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.166** | 🔴 fix | Admin/Font Manager | **`font-manager.php` bindet Ansicht und Mutationen jetzt explizit an `manage_settings`**: Save-, Scan-, Delete- und Google-Font-Download-Pfade übernehmen damit keine pauschalen Admin-Zugriffe mehr nur über `isAdmin()`. |
| **2.7.166** | 🟠 perf | Admin/Font Manager | **Der Font-Manager-Entry verwirft capability-fremde Mutationen früher und billiger**: POST-Pfade scheitern bereits vor CSRF- und Handler-Dispatch, wenn der Settings-Vertrag nicht erfüllt ist. |
| **2.7.166** | 🟡 refactor | Admin/Font Manager | **Der Entry hängt näher am kleinen Wrapper-Vertrag aus Action- und Capability-Gates**: Read-/Write-Helfer liegen jetzt sichtbar zentral statt losem Vertrauen auf den generischen Admin-Guard. |

---

### v2.7.165 — 25. März 2026 · Audit-Batch 247, Backup-Entry bei Read-/Write-Capability-Split nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.165** | 🔴 fix | Admin/System | **`backups.php` trennt Backup-Ansicht und Mutationen jetzt explizit über Read-/Write-Capabilities**: Listen und Restore-nahe Views bleiben an `manage_settings`/`manage_system` gebunden, während Create- und Delete-Pfade nur noch mit passender Write-Capability laufen. |
| **2.7.165** | 🟠 perf | Admin/System | **Der Backup-Entry verwirft capability-fremde Mutationen früher und billiger**: POST-Pfade scheitern bereits vor CSRF- und Handler-Dispatch, wenn der Write-Vertrag nicht erfüllt ist. |
| **2.7.165** | 🟡 refactor | Admin/System | **Der Backup-Wrapper hängt näher am Modulvertrag des `BackupsModule`**: Read-/Write-Helfer spiegeln jetzt sichtbar die vorhandene Capability-Trennung statt bloßem Vertrauen auf `isAdmin()`. |

---

### v2.7.164 — 25. März 2026 · Audit-Batch 246, gemeinsame Member-Dashboard-Schicht bei Section- und Capability-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.164** | 🔴 fix | Admin/Member | **`member-dashboard-page.php` normalisiert Section und Aktion jetzt bereits im Shared-Wrapper und bindet jede Unterseite an section-spezifische Capabilities**: General-, Design-, Widget-, Profilfeld-, Notification-, Onboarding- und Plugin-Widget-Pfade übernehmen dadurch keine bereichsfremden Zugriffe oder losen `action`-Werte mehr über denselben Sammel-Entry. |
| **2.7.164** | 🟠 perf | Admin/Member | **Die gemeinsame Member-Dashboard-Schicht verwirft unzulässige Requests früher und billiger**: section-fremde Zugriffe oder unbekannte Aktionen scheitern vor unnötigen Modulaufrufen und halten den Shared-Wrapper kompakter. |
| **2.7.164** | 🟡 refactor | Admin/Member | **Der Member-Dashboard-Sammel-Wrapper hängt näher an einem kleinen, section-gebundenen Admin-Vertrag**: Capability-Matrix, Section-Normalisierung und Save-Allowlist liegen jetzt sichtbar zentral statt losem Vertrauen auf den generischen Section-Shell-Guard. |

---

### v2.7.163 — 25. März 2026 · Audit-Batch 245, Media-Entry bei Capability- und Action-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.163** | 🔴 fix | Admin/Media | **`media.php` bindet Medien-Mutationen jetzt explizit an `manage_media` und normalisiert Actions serverseitig vor dem Dispatch**: Upload-, Folder-, Delete-, Kategorie- und Settings-Pfade übernehmen dadurch keine losen `action`-Werte oder capability-fremden Admin-Zugriffe mehr direkt im Einstieg. |
| **2.7.163** | 🟠 perf | Admin/Media | **Der Media-Entry verwirft unzulässige Mutationen früher und billiger**: unbekannte Aktionen oder capability-fremde Requests scheitern vor unnötigen Service- und Modulaufrufen im Medienpfad. |
| **2.7.163** | 🟡 refactor | Admin/Media | **Der Medien-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Capability-Gate und Action-Normalisierung liegen jetzt sichtbar zentral statt nur losem Vertrauen auf `isAdmin()` plus nachgelagerte Modulgrenzen. |

---

### v2.7.162 — 25. März 2026 · Audit-Batch 244, Landing-Page-Entry bei Tab-, Action- und Capability-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.162** | 🔴 fix | Admin/Landing | **`landing-page.php` begrenzt Tabs und POST-Aktionen jetzt explizit über kleine Allowlists, prüft Feature-IDs serverseitig und bindet den Entry an `manage_settings`**: Header-, Content-, Footer-, Design-, Feature- und Plugin-Pfade übernehmen dadurch keine losen `action`-Werte oder pauschalen Admin-Zugriffe mehr direkt im Einstieg. |
| **2.7.162** | 🟠 perf | Admin/Landing | **Der Landing-Page-Entry verwirft unzulässige Mutationen früher und billiger**: unbekannte Aktionen, ungültige Feature-IDs oder capability-fremde Requests scheitern vor unnötigen Modulaufrufen im PRG-Flow. |
| **2.7.162** | 🟡 refactor | Admin/Landing | **Der Landing-Page-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Tab-/Action-Normalisierung, ID-Gates und Capability-Prüfung liegen jetzt sichtbar zentral statt losem Request-Handling im Einstieg. |

---

### v2.7.161 — 25. März 2026 · Audit-Batch 243, Menü-Editor-Entry bei Action-, ID- und Capability-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.161** | 🔴 fix | Admin/Themes | **`menu-editor.php` begrenzt Menü-Mutationen jetzt explizit über eine kleine Action-Allowlist, normalisiert `menu_id` serverseitig und bindet den Entry an `manage_settings`**: Save-, Delete- und Item-Save-Pfade übernehmen dadurch keine losen `action`-/`menu_id`-Werte oder pauschalen Admin-Zugriffe mehr direkt im Einstieg. |
| **2.7.161** | 🟠 perf | Admin/Themes | **Der Menü-Editor verwirft unzulässige Mutationen früher und billiger**: unbekannte Aktionen, ungültige Menü-IDs oder capability-fremde Requests scheitern vor unnötigen Modulaufrufen im PRG-Flow. |
| **2.7.161** | 🟡 refactor | Admin/Themes | **Der Menü-Editor hängt näher am gemeinsamen Admin-Wrapper-Muster**: Action-, ID- und Capability-Gates liegen jetzt sichtbar in kleinen Helfern statt losem Vertrauen auf `isAdmin()` und rohe Request-Casts im Einstieg. |

---

### v2.7.160 — 25. März 2026 · Audit-Batch 242, gemeinsame Performance-Wrapper-Schicht auf section-gebundene Action-Gates gezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.160** | 🔴 fix | Admin/Performance | **`performance-page.php` normalisiert Section und Action jetzt gemeinsam serverseitig und akzeptiert pro Performance-Unterseite nur noch die tatsächlich erlaubten Mutationen**: Cache-, Datenbank-, Medien- und Session-Pfade übernehmen dadurch keine losen `action`-Werte mehr quer durch den gemeinsamen Sammel-Wrapper. |
| **2.7.160** | 🟠 perf | Admin/Performance | **Die gemeinsame Performance-Schicht verwirft unzulässige Mutationen früher und billiger**: section-fremde oder unbekannte Aktionen scheitern vor unnötigen Modulaufrufen und halten Cache-, DB-, Media- und Session-Pfade kompakter. |
| **2.7.160** | 🟡 refactor | Admin/Performance | **Der Performance-Sammel-Wrapper hängt näher an einem kleinen, section-gebundenen Admin-Vertrag**: Section-/Action-Allowlist und Capability-Gates über `manage_settings` liegen jetzt sichtbar zentral statt losem Sammel-Dispatch im POST-Handler. |

---

### v2.7.159 — 25. März 2026 · Audit-Batch 241, 404-Monitor-Entry bei Action- und Capability-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.159** | 🔴 fix | Admin/SEO | **`not-found-monitor.php` begrenzt 404-Mutationen jetzt wieder explizit über eine kleine Action-Allowlist und bindet den Entry an `manage_settings`**: Save-Redirect- und Log-Clear-Pfade übernehmen dadurch keine losen `action`-Werte oder pauschalen Admin-Zugriffe mehr direkt im Einstieg. |
| **2.7.159** | 🟠 perf | Admin/SEO | **Der 404-Monitor verwirft unzulässige Mutationen früher und billiger**: unbekannte Aktionen oder capability-fremde Requests scheitern vor unnötigen Modulaufrufen und bleiben im PRG-Flow kompakter. |
| **2.7.159** | 🟡 refactor | Admin/SEO | **Der 404-Monitor hängt näher am gemeinsamen SEO-Wrapper-Muster**: Action-Allowlist, Capability-Gate und Flash-/Redirect-Pfade liegen jetzt sichtbar zentral statt losem POST-Dispatch im Einstieg. |

---

### v2.7.158 — 25. März 2026 · Audit-Batch 240, Users-Entry bei Action-, ID-, Bulk- und View-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.158** | 🔴 fix | Admin/Users | **`users.php` normalisiert Benutzer-Aktionen, positive IDs, Bulk-IDs und View-Ziele jetzt bereits im Wrapper**: Save-, Delete- und Bulk-Pfade übernehmen dadurch keine losen `action`-/`id`-/`ids[]`- oder `action`-Query-Werte mehr direkt in das Modul. |
| **2.7.158** | 🟠 perf | Admin/Users | **Der Users-Entry verwirft unzulässige Mutationen früher und billiger**: unbekannte Aktionen, ungültige Benutzer-IDs oder leere/unsaubere Bulk-Auswahlen scheitern vor unnötigen Modulaufrufen und bleiben im PRG-Flow kompakter. |
| **2.7.158** | 🟡 refactor | Admin/Users | **Der Benutzer-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Flash-, Redirect-, View- und Bulk-Normalisierung liegen jetzt sichtbar in kleinen Helfern, und der Einstieg ist explizit an `manage_users` gebunden. |

---

### v2.7.157 — 25. März 2026 · Audit-Batch 239, Pages-Entry bei Action-, Bulk-, View- und Capability-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.157** | 🔴 fix | Admin/Pages | **`pages.php` normalisiert Seiten-Aktionen, Bulk-IDs und View-Ziele jetzt bereits im Wrapper und blockt ungültige Delete-/Bulk-Requests früher**: Delete- und Bulk-Pfade übernehmen dadurch keine losen `action`-, `id`-, `ids[]`- oder `bulk_ids`-Werte mehr direkt aus dem Request. |
| **2.7.157** | 🟠 perf | Admin/Pages | **Der Pages-Entry verwirft unzulässige Seiten-Mutationen früher und billiger**: unbekannte Aktionen, ungültige Seiten-IDs oder leere Bulk-Auswahlen scheitern vor unnötigen Modulaufrufen und halten den PRG-Pfad kompakter. |
| **2.7.157** | 🟡 refactor | Admin/Pages | **Der Seiten-Entry hängt näher an einem kleinen Capability-/Wrapper-Vertrag**: View-Normalisierung, Flash-/Redirect-Helfer und der Access-Guard über `manage_pages` liegen jetzt sichtbar zentral statt losem Request-Handling im Einstieg. |

---

### v2.7.156 — 25. März 2026 · Audit-Batch 238, Posts-Entry bei Action-, Bulk-, Kategorie- und View-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.156** | 🔴 fix | Admin/Posts | **`posts.php` normalisiert Beitrags-Aktionen, Bulk-Werte, positive IDs, Kategorie-IDs und View-Ziele jetzt bereits im Wrapper**: Save-, Delete-, Bulk- und Kategoriepfade übernehmen dadurch keine losen `action`-/`id`-/`cat_id`-/`ids[]`- oder `action`-Query-Werte mehr direkt aus dem Request. |
| **2.7.156** | 🟠 perf | Admin/Posts | **Der Posts-Entry verwirft unzulässige Content-Mutationen früher und billiger**: unbekannte Aktionen, ungültige Beitrags-/Kategorie-IDs oder leere/unsaubere Bulk-Auswahlen scheitern vor unnötigen Modulaufrufen und bleiben im PRG-Flow kompakter. |
| **2.7.156** | 🟡 refactor | Admin/Posts | **Der Beitrags-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Flash-, Redirect-, Bulk- und View-Normalisierung liegen jetzt sichtbar in kleinen Helfern statt losem Session- und Request-Handling im Einstieg. |

---

### v2.7.155 — 25. März 2026 · Audit-Batch 237, Privacy-Requests-Alias auf echten Redirect-Zweck zurückgebaut

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.155** | 🔴 fix | Admin/Legal | **`privacy-requests.php` bildet wieder nur noch seinen tatsächlichen Redirect-Zweck auf `/admin/data-requests` ab**: der Alias schleppt damit keinen unerreichbaren Legacy-POST-, Modul- oder View-Code mehr hinter einem sofortigen Redirect mit. |
| **2.7.155** | 🟠 perf | Admin/Legal | **Der Privacy-Requests-Alias bleibt kompakter und vorhersehbarer**: tote Altlogik wird nicht mehr mitgeladen oder mitgepflegt, obwohl der Entry fachlich nur als Weiterleitung dient. |
| **2.7.155** | 🟡 refactor | Admin/Legal | **Der Alias hängt näher am gemeinsamen Redirect-Entry-Muster**: Guard-, Ziel- und Redirect-Pfade liegen jetzt sichtbar klein und explizit im Einstieg statt hinter unerreichbarer Alt-Orchestrierung versteckt. |

---

### v2.7.154 — 25. März 2026 · Audit-Batch 236, Redirect-Manager-Entry bei Action-, ID-, Slug- und Capability-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.154** | 🔴 fix | Admin/SEO | **`redirect-manager.php` normalisiert Redirect-Aktionen, positive IDs und Slug-Filter jetzt bereits im Wrapper und bindet jede Mutation an `manage_settings`**: Save-, Delete-, Toggle- und Slug-Cleanup-Pfade übernehmen dadurch keine losen `action`-/`id`-/`slug_filter`-Werte oder pauschalen Admin-Zugriffe mehr stillschweigend im Einstieg. |
| **2.7.154** | 🟠 perf | Admin/SEO | **Der Redirect-Manager verwirft unzulässige Mutationen früher und billiger**: unbekannte Aktionen, ungültige Redirect-IDs, leere Slug-Cleanup-Requests oder capability-fremde Zugriffe scheitern vor unnötigen Modulaufrufen im PRG-Flow. |
| **2.7.154** | 🟡 refactor | Admin/SEO | **Der Redirect-Manager hängt näher am gemeinsamen Admin-Wrapper-Muster**: Action-Allowlist, Capability-Gates sowie Flash-/Redirect-Helfer liegen jetzt sichtbar zentral statt losem Switch-Dispatch und direktem Session-Handling im Einstieg. |

---

### v2.7.153 — 25. März 2026 · Audit-Batch 235, Firewall-Entry bei Action-, ID- und Capability-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.153** | 🔴 fix | Admin/Security | **`firewall.php` normalisiert Firewall-Aktionen und positive Regel-IDs jetzt bereits im Wrapper und bindet jede Mutation an eine explizite Capability**: Settings-, Toggle- und Delete-Pfade übernehmen dadurch keine losen `action`-/`id`-Werte oder pauschalen Vollzugriff nur wegen `isAdmin()` mehr stillschweigend im Einstieg. |
| **2.7.153** | 🟠 perf | Admin/Security | **Der Firewall-Entry verwirft unzulässige Mutationen früher und billiger**: unbekannte Aktionen, ungültige Regel-IDs oder capability-fremde Requests scheitern vor unnötigen Modulaufrufen im PRG-Flow. |
| **2.7.153** | 🟡 refactor | Admin/Security | **Der Firewall-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Action-, ID- und Capability-Gates liegen jetzt sichtbar in kleinen Helfern statt losem Request-Parsing und pauschalem Admin-Check im Einstieg. |

---

### v2.7.152 — 25. März 2026 · Audit-Batch 234, Packages-Entry bei Action-/ID-Gates nachgezogen und Logger-Fatal entschärft

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.152** | 🔴 fix | Admin/Subscriptions | **`packages.php` normalisiert Paket-Aktionen und positive IDs jetzt bereits im Wrapper**: Delete-, Toggle- und Settings-nahe Mutationen übernehmen dadurch keine losen `action`-/`id`-Werte mehr direkt aus `POST` in den Dispatch. |
| **2.7.152** | 🟠 perf | Admin/Subscriptions | **Der Packages-Entry verwirft unzulässige Mutationen früher und billiger**: unbekannte Aktionen oder ungültige Paket-IDs scheitern vor unnötigen Modulaufrufen im PRG-Flow. |
| **2.7.152** | 🟡 refactor | Admin/Subscriptions | **Der Packages-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Action- und ID-Normalisierung liegen jetzt sichtbar in kleinen Helfern statt losem Request-Parsing im Einstieg. |
| **2.7.152** | 🔴 fix | Admin/Subscriptions | **`PackagesModule.php` nutzt den Core-Logger wieder über den vorhandenen Channel-Vertrag**: Ausnahme-Pfade rufen jetzt `Logger::instance()->withChannel('admin.packages')` auf statt eines statischen Nicht-API-Aufrufs, der sonst bei Fehlerfällen in einen zusätzlichen Fatal laufen konnte. |

---

### v2.7.151 — 25. März 2026 · Audit-Batch 233, Design-Settings-Redirect an manage_settings gebunden

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.151** | 🔴 fix | Admin/Themes | **`design-settings.php` bindet den Redirect auf den Theme-Editor jetzt explizit an `manage_settings` statt bloß an einen pauschalen Admin-Check**: der Design-/Theme-Customizing-Pfad leitet damit nicht mehr jeden Admin stillschweigend weiter, sondern nur noch Nutzer mit der tatsächlich passenden Settings-Berechtigung. |
| **2.7.151** | 🟠 perf | Admin/Themes | **Der Design-Settings-Entry bleibt kompakt und verwirft unzulässige Zugriffe früher**: capability-fremde Requests scheitern vor dem Wechsel in den Theme-Editor und sparen unnötige Weiterleitungen in nachgelagerte Editor-Pfade. |
| **2.7.151** | 🟡 refactor | Admin/Themes | **Der Redirect-Entry hängt näher an einem kleinen, expliziten Access-Vertrag**: Access- und Fallback-Pfade liegen jetzt sichtbar in kleinen Helfern statt nur lose an einem pauschalen `isAdmin()`-Check zu hängen. |

---

### v2.7.150 — 25. März 2026 · Audit-Batch 232, AntiSpam-Entry bei Action-, ID- und Capability-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.150** | 🔴 fix | Admin/Security | **`antispam.php` normalisiert AntiSpam-Aktionen und positive IDs jetzt bereits im Wrapper und bindet jede Mutation an eine explizite Capability**: Settings- und Blacklist-Pfade übernehmen dadurch keine losen `action`-/`id`-Werte oder pauschalen Vollzugriff nur wegen `isAdmin()` mehr stillschweigend im Einstieg. |
| **2.7.150** | 🟠 perf | Admin/Security | **Der AntiSpam-Entry verwirft unzulässige Mutationen früher und billiger**: unbekannte Aktionen, ungültige IDs oder capability-fremde Requests scheitern vor unnötigen Modulaufrufen im PRG-Flow. |
| **2.7.150** | 🟡 refactor | Admin/Security | **Der AntiSpam-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Action-/ID-Normalisierung, Pull-Alert und Redirect-Helfer liegen jetzt sichtbar zentral statt teils losem Session- und Request-Handling im Einstieg. |

---

### v2.7.149 — 25. März 2026 · Audit-Batch 231, Kommentar-Entry bei Action-, Status- und Bulk-Normalisierung nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.149** | 🔴 fix | Admin/Comments | **`comments.php` normalisiert Kommentar-Aktionen, Statuswerte, positive IDs und Bulk-IDs jetzt bereits im Wrapper**: Moderations-, Delete- und Bulk-Pfade übernehmen dadurch keine losen Request-Werte mehr direkt aus `POST` in das Kommentar-Modul. |
| **2.7.149** | 🟠 perf | Admin/Comments | **Der Kommentar-Entry verwirft unzulässige Mutationen früher und billiger**: unbekannte Aktionen, rohe IDs, ungültige Statuswerte und ausufernde/unsaubere Bulk-IDs scheitern vor unnötigen Modulaufrufen. |
| **2.7.149** | 🟡 refactor | Admin/Comments | **Der Kommentar-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Action-, Status-, ID- und Bulk-Normalisierung liegen jetzt sichtbar in kleinen Helfern statt losem Request-Parsing direkt im Dispatch. |
| **2.7.149** | 🔴 fix | Admin/Backups | **`BackupsModule.php` nutzt wieder den vorhandenen Core-Logger-Vertrag**: Der produktive Fatal Error durch den nicht existierenden Aufruf `Logger::channel()` ist behoben, weil das Modul den Logger jetzt korrekt über `Logger::instance()->withChannel('admin.backups')` bezieht. |

---

### v2.7.148 — 25. März 2026 · Audit-Batch 230, gemeinsame SEO-Wrapper-Schicht auf Registry- und Action-Gates gezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.148** | 🔴 fix | Admin/SEO | **`seo-page.php` akzeptiert für die gemeinsamen SEO-Unterseiten jetzt nur noch kanonische Sections, View-Dateien, Routen und seitengebundene POST-Aktionen**: lose `section`-, `action`-, `view_file`- oder `return_to`-Werte laufen damit nicht mehr quer durch den zentralen Wrapper in falsche Render- oder Redirect-Pfade. |
| **2.7.148** | 🟠 perf | Admin/SEO | **Die gemeinsame SEO-Eintrittsschicht verwirft unzulässige Unterseiten- und Mutationspfade früher und billiger**: unbekannte Actions oder fremde Redirect-Ziele scheitern vor unnötigen Modulaufrufen, View-Loads oder PRG-Sprüngen. |
| **2.7.148** | 🟡 refactor | Admin/SEO | **Der SEO-Sammel-Wrapper hängt jetzt näher an einem expliziten Registry-Vertrag**: Section-Metadaten, Action-Allowlist sowie Flash-/Redirect-Helfer liegen sichtbar zentral statt lose aus variablen Konfigurationswerten zusammengebaut zu werden. |

---

### v2.7.147 — 25. März 2026 · Audit-Batch 229, Orders-Entry bei Action-, Status- und Billing-Normalisierung nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.147** | 🔴 fix | Admin/Orders | **`orders.php` normalisiert POST-Aktionen, positive IDs, Statuswerte und Billing-Cycles jetzt bereits im Wrapper**: Statuswechsel-, Delete- und Zuweisungspfade übernehmen dadurch keine losen Request-Werte mehr direkt in das Orders-Modul. |
| **2.7.147** | 🟠 perf | Admin/Orders | **Der Orders-Entry verwirft unzulässige Bestellmutationen früher und billiger**: unbekannte Aktionen, rohe IDs und ungültige Status-/Billing-Werte scheitern vor unnötigen Modulaufrufen oder tieferen Guard-Pfaden. |
| **2.7.147** | 🟡 refactor | Admin/Orders | **Der Orders-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Action-, ID-, Status- und Billing-Normalisierung liegen jetzt sichtbar in kleinen Helfern statt roher Request-Casts direkt im Dispatch. |

---

### v2.7.146 — 25. März 2026 · Audit-Batch 228, Site-Tables-Entry bei Action-, ID- und View-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.146** | 🔴 fix | Admin/Tables | **`site-tables.php` begrenzt POST-Mutationen jetzt wieder explizit über eine kleine Action-Allowlist und normalisiert IDs bereits im Wrapper**: Delete-, Duplicate- und Save-Redirect-Pfade übernehmen dadurch keine losen Aktionen oder rohen `id`-Werte mehr direkt aus dem Request. |
| **2.7.146** | 🟠 perf | Admin/Tables | **Der Tabellen-Entry verwirft unzulässige Mutationen früher und billiger**: unbekannte Aktionen, ungültige IDs und unsaubere Edit-Redirect-Ziele scheitern vor unnötigen Modulaufrufen oder Redirect-Verzweigungen im PRG-Flow. |
| **2.7.146** | 🟡 refactor | Admin/Tables | **Der Site-Tables-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Flash-, Redirect-, Allowlist- und View-Normalisierung liegen jetzt sichtbar in kleinen Helfern statt losem Switch- und Query-Handling im Einstieg. |

---

### v2.7.145 — 25. März 2026 · Audit-Batch 227, Kommentar-Post-Links auf zentralen Permalink-Service gezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.145** | 🔴 fix | Admin/Comments | **`CommentsModule.php` baut Beitrag-Links in der Kommentar-Moderation jetzt über den zentralen `PermalinkService` statt harte `/blog/{slug}`-Pfade zu verwenden**: Die Kommentarliste bleibt damit konsistent zur konfigurierbaren Post-Permalink-Struktur des Cores und läuft bei abweichenden Blog-URLs nicht in stille Fehlverlinkungen. |
| **2.7.145** | 🟠 perf | Admin/Comments | **Die Kommentar-Moderation vermeidet zusätzlichen URL-Fallback-Müll im UI-Pfad**: `CommentService` liefert `published_at` und `created_at` direkt mit, sodass die Listenansicht Beitrag-URLs ohne nachträgliches Raten oder weitere Lookups sauber aufbauen kann. |
| **2.7.145** | 🟡 refactor | Admin/Comments | **Der Kommentarpfad hängt näher am gemeinsamen Routing-/SEO-Vertrag des Cores**: Die Moderationsliste konsumiert jetzt denselben Permalink-Baustein wie Redirect-, SEO- und Post-Module statt einen isolierten Legacy-Linkpfad lokal nachzubauen. |

---

### v2.7.144 — 25. März 2026 · Audit-Batch 226, Error-Report-Entry bei Payload-Gates und Source-URL-Normalisierung nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.144** | 🔴 fix | Admin/System | **`error-report.php` begrenzt Report-Felder und JSON-Payloads jetzt bereits im Wrapper und akzeptiert `source_url` nur noch als interne/same-site Quelle**: ausufernde oder lose Fehlerreport-Daten laufen dadurch nicht mehr direkt aus dem Request in den Service-Pfad. |
| **2.7.144** | 🟠 perf | Admin/System | **Der Error-Report-Entry verwirft auffällige Report-Payloads früher und billiger**: übergroße JSON-Strings und tiefere/ausufernde Strukturen scheitern vor unnötiger Service- und Datenbankarbeit im PRG-Flow. |
| **2.7.144** | 🟡 refactor | Admin/System | **Der Error-Report-Entry hängt näher an einem kleinen, expliziten Request-Vertrag**: Feldbegrenzung, Source-URL-Normalisierung und JSON-Strukturgrenzen liegen sichtbar im Wrapper statt nur implizit über nachgelagerte Service-Limits zu wirken. |

---

### v2.7.143 — 25. März 2026 · Audit-Batch 225, Gruppen-Entry bei Action-Gates und ID-Normalisierung nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.143** | 🔴 fix | Admin/Users | **`groups.php` begrenzt Mutationen jetzt wieder explizit über eine kleine Action-Allowlist und normalisiert Gruppen-IDs bereits im Wrapper**: Delete-Pfade übernehmen dadurch keine losen Aktionen oder rohen IDs mehr direkt in das Gruppen-Modul. |
| **2.7.143** | 🟠 perf | Admin/Users | **Der Gruppen-Entry verwirft unzulässige Delete-Anfragen früher und billiger**: ungültige Aktionswerte oder Gruppen-IDs scheitern vor unnötigen Modulaufrufen im PRG-Flow. |
| **2.7.143** | 🟡 refactor | Admin/Users | **Der Gruppen-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Allowlist und ID-Normalisierung liegen sichtbar im Einstieg statt nur implizit über Handler-Map und rohe Casts zu wirken. |

---

### v2.7.142 — 25. März 2026 · Audit-Batch 224, DSGVO-Module bei Request-Typ-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.142** | 🔴 fix | Admin/Legal | **`PrivacyRequestsModule.php` und `DeletionRequestsModule.php` binden Mutationen jetzt explizit an den passenden Request-Typ**: Auskunfts- und Löschanträge werden damit nicht mehr allein per roher ID verarbeitet, wenn der Datensatz eigentlich zum anderen DSGVO-Pfad gehört. |
| **2.7.142** | 🟠 perf | Admin/Legal | **Die DSGVO-Module blocken bereichsfremde IDs früher und billiger**: unnötige Update-, Delete- oder Hook-Aufrufe entfallen, wenn ein Request nicht zum erwarteten Typ gehört. |
| **2.7.142** | 🟡 refactor | Admin/Legal | **Beide Module hängen näher an einem kleinen, expliziten Request-Vertrag**: `getRequestById()` kapselt den Typ-Guard sichtbar zentral statt dass einzelne Mutationspfade nur implizit oder gar nicht auf `type` achten. |

---

### v2.7.141 — 25. März 2026 · Audit-Batch 223, Cookie-Manager-Entry bei Request-Normalisierung nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.141** | 🔴 fix | Admin/Legal | **`cookie-manager.php` normalisiert POST-Aktionen, positive IDs, `service_slug` und Self-Hosted-Flags jetzt serverseitig bereits im Wrapper**: Delete- und Import-Pfade übernehmen dadurch keine losen oder unsauberen Request-Werte mehr direkt in Kategorie-, Service- oder kuratierte Dienst-Mutationen. |
| **2.7.141** | 🟠 perf | Admin/Legal | **Der Cookie-Manager verwirft unzulässige Delete- und Import-Anfragen früher und billiger**: ungültige IDs oder unbekannte Service-Slugs scheitern vor unnötigen Modulaufrufen im PRG-Flow. |
| **2.7.141** | 🟡 refactor | Admin/Legal | **Der Cookie-Manager-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Action-, ID- und Slug-Normalisierung liegen sichtbar im Einstieg statt nur implizit über Modulfallbacks oder rohe Request-Casts zu wirken. |

---

### v2.7.140 — 25. März 2026 · Audit-Batch 222, Backup-Entry bei Action-Gates und Backup-Namen nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.140** | 🔴 fix | Admin/System | **`backups.php` begrenzt Backup-Mutationen jetzt wieder explizit über eine kleine Allowlist und normalisiert `backup_name` serverseitig vor Delete-Dispatches**: lose Aktionen oder unsaubere Backup-Namen laufen dadurch nicht mehr direkt aus dem Request in den Löschpfad. |
| **2.7.140** | 🟠 perf | Admin/System | **Der Backup-Entry verwirft unzulässige Delete-Anfragen früher und billiger**: ungültige Aktionswerte oder Backup-Namen scheitern vor unnötigen Modulaufrufen im PRG-Flow. |
| **2.7.140** | 🟡 refactor | Admin/System | **Der Backup-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Allowlist und Namensnormalisierung liegen sichtbar im Einstieg statt nur implizit über Handler-Map und Modulfallbacks zu wirken. |

---

### v2.7.139 — 25. März 2026 · Audit-Batch 221, DSGVO-Requests-Entry bei Scope-/Action-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.139** | 🔴 fix | Admin/Legal | **`data-requests.php` begrenzt DSGVO-Mutationen jetzt wieder explizit über scope-gebundene Allowlists und normalisiert `scope`, `action`, `id` sowie Ablehnungsgründe serverseitig**: lose oder bereichsfremde Aktionen laufen dadurch nicht mehr direkt aus dem Request in Auskunfts- oder Löschpfade. |
| **2.7.139** | 🟠 perf | Admin/Legal | **Der Sammel-Entry verwirft unzulässige DSGVO-Mutationen früher und billiger**: ungültige Scope-/Action-Kombinationen und auffällige Request-Werte scheitern vor unnötigen Modulaufrufen im PRG-Flow. |
| **2.7.139** | 🟡 refactor | Admin/Legal | **Der DSGVO-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Allowlist, Scope-/Action-Normalisierung und kleine Request-Helfer liegen sichtbar im Einstieg statt nur implizit über `match`-Fallbacks zu wirken. |

---

### v2.7.138 — 25. März 2026 · Audit-Batch 220, Theme-Marketplace auf zentralen staging-basierten Installationspfad gezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.138** | 🔴 fix | Admin/Themes | **`ThemeMarketplaceModule.php` delegiert Marketplace-Theme-Installationen jetzt an den zentralen `UpdateService`**: Theme-Pakete laufen damit nicht mehr über eine separate ZIP-/Download-/Extract-Pipeline direkt im Modul, sondern nutzen denselben staging-basierten Zielpfad-, Integritäts- und Rollback-Vertrag wie reguläre Theme-Updates. |
| **2.7.138** | 🟠 perf | Admin/Themes | **Doppelte Download-, Entpack- und Cleanup-Logik im Theme-Marketplace-Installationspfad entfällt**: der Modulpfad bleibt im Installationsflow kompakter, weil er keinen zweiten Archiv-Lifecycle neben dem Update-Service mehr pflegt. |
| **2.7.138** | 🟡 refactor | Admin/Themes | **Der Theme-Marketplace hängt jetzt näher am gemeinsamen Update-Lifecycle für Zielpfade, Logging und Rollback**: das Modul konzentriert sich wieder stärker auf Katalog- und Marketplace-Logik statt eine eigene zweite Installationspipeline zu unterhalten. |

---

### v2.7.137 — 25. März 2026 · Audit-Batch 219, Documentation-Entry bei Action-Allowlist nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.137** | 🔴 fix | Admin/System | **`documentation.php` prüft Sync-Aktionen jetzt wieder explizit über eine kleine Allowlist und normalisiert den `action`-Wert serverseitig vor dem Dispatch**: lose oder nicht erlaubte Aktionen laufen damit nicht mehr nur implizit deshalb in den PRG-/Fehlerpfad, weil ein Handler nachgeschlagen wird. |
| **2.7.137** | 🟠 perf | Admin/System | **Der Doku-Entry hält seinen Dispatch-Pfad kompakter und vorhersehbarer**: unzulässige Sync-Aktionen werden vor unnötigen Modulaufrufen oder Ergebnisobjekten früh blockiert. |
| **2.7.137** | 🟡 refactor | Admin/System | **Der Documentation-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Allowlist und Action-Normalisierung liegen sichtbar im Einstieg statt nur implizit über die Handler-Map zu wirken. |

---

### v2.7.136 — 25. März 2026 · Audit-Batch 218, Font-Manager-Entry bei Action-Allowlist und Request-Normalisierung nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.136** | 🔴 fix | Admin/Font Manager | **`font-manager.php` prüft POST-Aktionen jetzt explizit über eine kleine Allowlist und normalisiert `font_id` sowie Google-Font-Familien bereits im Wrapper**: lose oder unsaubere Request-Werte laufen dadurch nicht mehr bloß deshalb in Delete- oder Download-Pfade, weil ein Handler existiert. |
| **2.7.136** | 🟠 perf | Admin/Font Manager | **Der Entry verwirft ungültige Mutationen früher und billiger**: auffällige Action-, ID- oder Family-Werte scheitern vor unnötigen Modulaufrufen und bleiben im PRG-Flow kompakter. |
| **2.7.136** | 🟡 refactor | Admin/Font Manager | **Der Font-Manager-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Allowlist und kleine Wrapper-Normalisierer für ID- und Family-Eingaben liegen sichtbar im Einstieg statt nur implizit über Handler und Modul-Fallbacks zu wirken. |

---

### v2.7.135 — 25. März 2026 · Audit-Batch 217, Mail-Settings-Entry bei tab-gebundenem Action-Dispatch nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.135** | 🔴 fix | Admin/System | **`mail-settings.php` bindet POST-Aktionen jetzt explizit an erlaubte Tabs und normalisiert den `action`-Wert serverseitig**: lose oder bereichsfremde Aktionen laufen dadurch nicht mehr bloß deshalb durch den Wrapper, weil irgendwo ein Handler existiert. |
| **2.7.135** | 🟠 perf | Admin/System | **Redirect- und Dispatch-Pfade bleiben kompakter und vorhersehbarer**: der Entry springt nach Mutationen immer in den kanonischen Bereich zurück und vermeidet unnötige Modulaufrufe für tab-fremde Aktionen. |
| **2.7.135** | 🟡 refactor | Admin/System | **Der Mail-Settings-Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Action-Allowlist, Bereichsbindung und kanonischer PRG-Rücksprung liegen sichtbar im Einstieg statt nur implizit über die Handler-Map zu wirken. |

---

### v2.7.134 — 25. März 2026 · Audit-Batch 216, Legal-Sites-Entry bei Action-Gates und Template-Typen nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.134** | 🔴 fix | Admin/Legal Sites | **`legal-sites.php` begrenzt POST-Aktionen jetzt wieder explizit über eine kleine Allowlist und normalisiert `template_type` serverseitig vor dem Modulaufruf**: ungültige Aktionen oder Rechtstext-Typen laufen dadurch nicht mehr lose aus dem Request in die Mutationspfade. |
| **2.7.134** | 🟠 perf | Admin/Legal Sites | **Der Wrapper hält seinen Dispatch-Pfad kompakter und vorhersehbarer**: unnötige Modulaufrufe für unzulässige Aktionen oder Template-Typen werden früher blockiert. |
| **2.7.134** | 🟡 refactor | Admin/Legal Sites | **Der Entry hängt näher am gemeinsamen Admin-Wrapper-Muster**: Request-Gates und Typ-Normalisierung liegen sichtbar im Einstieg statt nur implizit über Handler-Map und Modul-Fallbacks zu wirken. |

---

### v2.7.133 — 25. März 2026 · Audit-Batch 215, Font-Download-Pfad bei Remote-Dateinamen gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.133** | 🔴 fix | Admin/Font Manager | **`FontManagerModule.php` akzeptiert beim Google-Font-Download jetzt nur noch eine kleine Anzahl erlaubter Remote-Fontdateien und normalisiert lokale Dateinamen aus Remote-URLs hart**: auffällige oder überlange Dateinamen laufen damit nicht mehr ungefiltert ins lokale Fonts-Verzeichnis. |
| **2.7.133** | 🟠 perf | Admin/Font Manager | **Ausufernde Remote-CSS-Pakete werden früher abgewiesen**: der Download-Pfad stoppt kontrolliert, wenn ein Remote-Font-Stylesheet ungewöhnlich viele Dateien referenziert. |
| **2.7.133** | 🟡 refactor | Admin/Font Manager | **Die lokale Dateinamenerzeugung hängt jetzt an einem kleinen, expliziten Helfer**: Remote-URL-Bestandteile werden nicht mehr lose inline zu lokalen Dateinamen zusammengesetzt, sondern über einen engeren Namensvertrag gebaut. |

---

### v2.7.132 — 25. März 2026 · Audit-Batch 214, Font-Delete-Pfad auf verwalteten Root begrenzt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.132** | 🔴 fix | Admin/Font Manager | **`FontManagerModule.php` löst lokale Font- und CSS-Dateien vor dem Löschen jetzt nur noch innerhalb von `uploads/fonts/` auf**: DB-basierte Pfadangaben können damit nicht mehr blind in Dateilöschungen außerhalb des verwalteten Fonts-Verzeichnisses durchschlagen. |
| **2.7.132** | 🟠 perf | Admin/Font Manager | **Der Delete-Pfad scheitert früher und kontrollierter bei ungültigen Pfaden**: problematische Dateiangaben werden vor unnötigen Dateisystemzugriffen abgewiesen und nur als Hinweis in die Rückmeldung übernommen. |
| **2.7.132** | 🟡 refactor | Admin/Font Manager | **Der Font-Lifecycle hängt näher an einem expliziten Root-Vertrag**: Pfadauflösung und Fonts-Verzeichnis werden in kleine Hilfsmethoden gebündelt, statt Löschpfade rohe DB-Werte direkt zu absoluten Dateisystempfaden zusammenzusetzen. |

---

### v2.7.131 — 25. März 2026 · Audit-Batch 213, Media-Upload-Wrapper bei Multi-File-Payloads gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.131** | 🔴 fix | Admin/Media | **`media.php` normalisiert Multi-Upload-Payloads jetzt robuster und verwirft unvollständige oder inkonsistente `$_FILES`-Strukturen früh**: Der Upload-Entry läuft damit nicht mehr blind durch vorausgesetzte Array-Formate, wenn ein Request kaputt oder manipuliert ankommt. |
| **2.7.131** | 🟠 perf | Admin/Media | **Upload-Fehlerschwälle bleiben kompakter**: aggregierte Fehlermeldungen werden begrenzt, damit Flash-Nachrichten bei vielen problematischen Dateien nicht unnötig anwachsen. |
| **2.7.131** | 🟡 refactor | Admin/Media | **Der Upload-Wrapper hängt näher an einem klaren Request-Vertrag**: Batch-Normalisierung und Fehlerformatierung sind sichtbar in kleine Helfer ausgelagert, statt die `$_FILES`-Struktur implizit direkt im Upload-Loop vorauszusetzen. |

---

### v2.7.130 — 25. März 2026 · Audit-Batch 212, Plugins-/Themes-Entrys bei Action-Gates nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.130** | 🔴 fix | Admin/Plugins & Admin/Themes | **`plugins.php` und `themes.php` begrenzen POST-Mutationen jetzt wieder explizit über kleine Action-Allowlists**: Ungültige Aktionen laufen dadurch nicht mehr nur implizit in den `match`-Fallback, sondern scheitern kontrolliert schon im Entry-Wrapper. |
| **2.7.130** | 🟠 perf | Admin/Plugins & Admin/Themes | **Die Wrapper halten ihren Dispatch-Pfad kompakter und vorhersehbarer**: der POST-Block vermeidet unnötige Modulaufrufe für unzulässige Aktionen und bleibt näher am strengeren Update-/Marketplace-Muster. |
| **2.7.130** | 🟡 refactor | Admin/Plugins | **`plugins.php` normalisiert Plugin-Slugs jetzt sichtbar vor dem Modulaufruf**: der Entry hängt damit konsistenter am vorhandenen Plugin-Slug-Vertrag, statt rohe Request-Werte direkt in Aktivierungs-, Deaktivierungs- und Löschpfade zu reichen. |

---

### v2.7.129 — 25. März 2026 · Audit-Batch 211, Theme-Marketplace bei Manifest- und Archiv-Gates nachgeschärft

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.129** | 🔴 fix | Admin/Themes | **`ThemeMarketplaceModule.php` validiert lokale Manifestpfade jetzt traversal-sicher innerhalb des Katalog-Roots**: Theme-Manifeste aus lokalen Index-Dateien dürfen damit nicht mehr über rohe Relative-Pfade aus dem erlaubten Katalogbereich ausbrechen oder übergroße JSON-Dateien ungebremst in den Marketplace-Pfad ziehen. |
| **2.7.129** | 🟠 perf | Admin/Themes | **Auffällige Theme-Archive werden früher und billiger verworfen**: die ZIP-Prüfung blockt Pakete mit zu vielen Einträgen, Kontrollzeichen oder übergroßer unkomprimierter Gesamtmenge schon vor dem Entpacken. |
| **2.7.129** | 🟡 refactor | Admin/Themes | **Der Theme-Marketplace hängt näher am strengeren Registry- und Paketvertrag des Plugin-Pendants**: Manifest-Normalisierung und Archiv-Gates sind sichtbarer zentralisiert, statt den lokalen Katalog- und ZIP-Pfad lockerer nebeneinander laufen zu lassen. |

---

### v2.7.128 — 25. März 2026 · Audit-Batch 210, Plugin-Marketplace auf zentralen staging-basierten Installationspfad gezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.128** | 🔴 fix | Admin/Plugins | **`PluginMarketplaceModule.php` delegiert Marketplace-Installationen jetzt an den zentralen `UpdateService`**: Plugin-Pakete laufen damit nicht mehr über einen separaten ZIP-/Extract-Pfad direkt ins Plugins-Verzeichnis, sondern nutzen denselben staging-basierten Installations- und Zielpfadvertrag wie reguläre Update-Installationen. |
| **2.7.128** | 🟠 perf | Admin/Plugins | **Doppelte Download-, ZIP- und Cleanup-Logik im Marketplace-Modul entfällt**: der Plugin-Marketplace baut keinen zweiten Entpackpfad mehr parallel zum Update-Service auf und bleibt im Installationsfluss dadurch kompakter wartbar. |
| **2.7.128** | 🟡 refactor | Admin/Plugins | **Marketplace-Installationen hängen jetzt näher am gemeinsamen Lifecycle für Integrität, Rollback und atomaren Verzeichnis-Swap**: der Modulpfad konzentriert sich wieder stärker auf Registry- und Marketplace-Logik statt auf eine eigene zweite Archiv-Installationspipeline. |

---

### v2.7.127 — 25. März 2026 · Audit-Batch 209, Marketplace-Entrys bei Action-Gates und Slug-Normalisierung nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.127** | 🔴 fix | Admin/Themes & Admin/Plugins | **`theme-marketplace.php` und `plugin-marketplace.php` validieren POST-Aktionen jetzt wieder über explizite Allowlists, und der Plugin-Marketplace normalisiert den angeforderten Slug vor dem Modulaufruf**: Die Entrys lassen damit keine losen Mutationen oder unsauberen Slug-Werte direkt in den Installationspfad laufen. |
| **2.7.127** | 🟠 perf | Admin/Themes & Admin/Plugins | **Die Marketplace-Wrapper halten ihren Request-Flow kompakter und vorhersehbarer**: Aktionsfehler werden früh im Entry abgefangen, statt erst tiefer im Modulpfad auf denselben ungültigen Zustand zu reagieren. |
| **2.7.127** | 🟡 refactor | Admin/Themes & Admin/Plugins | **Beide Marketplace-Entrys bleiben näher am gemeinsamen Admin-Wrapper-Muster**: Allowlist-, Flash- und Dispatch-Verhalten liegen wieder sichtbar im Einstieg statt implizit nur über `match`-Fallbacks zu leben. |

---

### v2.7.126 — 25. März 2026 · Audit-Batch 208, Update-Prüfung ohne direkten Doppelabruf nach Redirect

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.126** | 🔴 fix | Admin/System | **`UpdatesModule.php` hält Core-, Plugin- und Theme-Prüfstände jetzt als kleinen Snapshot zusammen, und `updates.php` übernimmt manuelle Check-Resultate per Session-Snapshot in den Folge-Request**: Eine explizite Update-Prüfung landet damit nach dem PRG-Redirect nicht sofort wieder in denselben Remote-Checks, obwohl der Stand gerade erst ermittelt wurde. |
| **2.7.126** | 🟠 perf | Admin/System | **Doppelte Update-Checks im direkten Prüfen-→-Redirect-→-Rendern-Pfad entfallen**: Core-, Plugin- und Theme-Checks werden pro Modulinstanz wiederverwendet, statt im selben Bedienablauf mehrfach identisch aufgebaut zu werden. |
| **2.7.126** | 🟡 refactor | Admin/System | **Der Update-Entry bleibt näher an einem kleinen Snapshot-Vertrag**: `checkAllUpdates()`, Snapshot-Hydration und PRG-Weitergabe bündeln den Prüffluss sichtbarer, statt Callback- und Folge-Request-Logik lose nebeneinander stehen zu lassen. |

---

### v2.7.125 — 25. März 2026 · Audit-Batch 207, Theme-Verwaltung bei Katalog- und Delete-Pfad nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.125** | 🔴 fix | Admin/Themes | **`ThemeManager.php` cached verfügbare Themes jetzt zentral inklusive `theme.json`- und Screenshot-Metadaten, während `ThemesModule.php` Delete-Aktionen wieder an den gemeinsamen Theme-Manager delegiert**: Theme-Listen und Löschpfade greifen damit näher auf denselben Lifecycle-Vertrag zu, statt Metadaten und Delete-Logik parallel zu duplizieren. |
| **2.7.125** | 🟠 perf | Admin/Themes | **Theme-Listen vermeiden doppelte Dateisystem-Anreicherung im Modulpfad**: verfügbare Themes werden pro Theme-Manager-Instanz einmal aufgebaut und nicht mehr zusätzlich im `ThemesModule` erneut über `theme.json`- und Screenshot-Reads erweitert. |
| **2.7.125** | 🟡 refactor | Admin/Themes | **Theme-Aktivierung und -Löschung halten den zentralen Runtime-Zustand sauberer konsistent**: `switchTheme()` und `deleteTheme()` invalidieren den verfügbaren Theme-Bestand jetzt sichtbar zentral, statt den Cache- und Lifecycle-Zustand implizit auseinanderlaufen zu lassen. |

---

### v2.7.124 — 25. März 2026 · Audit-Batch 206, Plugins-Modul bei Aktiv-Status und Delete-Vertrag nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.124** | 🔴 fix | Admin/Plugins | **`PluginsModule.php` cached aktive Plugin-Slugs jetzt pro Modulinstanz und delegiert Löschungen wieder an den zentralen `PluginManager`**: Plugin-Listen und Mutationspfade greifen damit auf denselben Aktivstatus-Index zu, während Delete-Aktionen Uninstall- und Delete-Hooks nicht mehr am Manager vorbei umgehen. |
| **2.7.124** | 🟠 perf | Admin/Plugins | **Der Plugin-Listenpfad vermeidet wiederholte `active_plugins`-Lookups im selben Modul-Lebenszyklus**: `getData()` und Aktivstatus-Prüfungen lesen aktive Plugins nicht mehr pro Plugin erneut aus Settings oder Manager-Zustand zusammen. |
| **2.7.124** | 🟡 refactor | Admin/Plugins | **Fallback-Persistenz und Aktivstatus-Auflösung bleiben näher an einem kleinen Modulvertrag**: `getActivePluginsLookup()` und `persistActivePlugins()` bündeln Lookup- und Fallback-Speicherpfade sichtbar, statt Aktivierungs-/Deaktivierungslogik mehrfach lose um dieselbe Settings-Struktur herumzubauen. |

---

### v2.7.123 — 25. März 2026 · Audit-Batch 205, Hub-Sites-Zusatzdomains ohne wiederholten Vollscan

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.123** | 🔴 fix | Admin/Hub | **`HubSitesModule.php` cached normalisierte Zusatzdomain-Zuordnungen jetzt pro Modulinstanz**: Domain-Prüfungen für Hub-Sites greifen damit auf einen gemeinsamen Zuordnungsindex zu, statt pro Zusatzdomain erneut alle Hub-Sites samt `settings_json` zu durchlaufen. |
| **2.7.123** | 🟠 perf | Admin/Hub | **Mehrere Zusatzdomain-Checks vermeiden wiederholte Tabellen-Vollscans**: Der Save-Pfad kann mehrere Domains gegen denselben Cache prüfen, ohne für jede einzelne Domain die komplette Hub-Site-Liste erneut zu decodieren. |
| **2.7.123** | 🟡 refactor | Admin/Hub | **Der Domain-Validierungspfad bleibt näher an einem kleinen Modulvertrag**: `getHubDomainAssignments()` bündelt Laden und Normalisieren der Domain-Zuordnung sichtbar zentral, während Save-/Delete-/Duplicate-Pfade den Cache nach Mutationen gezielt invalidieren. |

---

### v2.7.122 — 25. März 2026 · Audit-Batch 204, Media-Modul bei Kategorien und Settings-Schlüsseln bereinigt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.122** | 🔴 fix | Admin/Media | **`MediaModule.php` cached Kategorien jetzt pro Modulinstanz und verwendet beim Speichern wieder die kanonischen Service-Keys**: Kategorieprüfungen und Listenpfade greifen damit auf denselben kleinen Kategorienbestand zu, während Dateinamen- und Thumbnail-Settings nicht mehr als Alias-Mix im Save-Pfad landen. |
| **2.7.122** | 🟠 perf | Admin/Media | **Wiederholte Kategorien-Lookups entfallen im selben Modul-Lebenszyklus**: Bibliothek, Kategorien-Tab und Kategorievalidierung müssen den Medienservice nicht mehr mehrfach identisch nach Kategorien fragen. |
| **2.7.122** | 🟡 refactor | Admin/Media | **Die Settings-Persistenz bleibt näher am eigentlichen Medien-Service-Vertrag**: `sanitize_filenames`, `unique_filenames`, `lowercase_filenames` sowie `thumb_*`-Werte werden wieder explizit auf ihre kanonischen Schlüssel abgebildet, statt auf tolerierte Legacy-Aliasse zu vertrauen. |

---

### v2.7.121 — 25. März 2026 · Audit-Batch 203, Plugin-Marketplace-Registry entschlackt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.121** | 🔴 fix | Admin/Plugins | **`PluginMarketplaceModule.php` cached die normalisierte Registry jetzt pro Modulinstanz und nutzt einen wiederverwendeten HTTP-Client**: Registry-Ladevorgänge bleiben konsistenter, ohne Remote- und lokale Katalogdaten bei erneutem Zugriff unnötig neu aufzubauen. |
| **2.7.121** | 🟠 perf | Admin/Plugins | **Registry- und Manifest-Zugriffe vermeiden wiederholte Initialisierungskosten**: Das Modul lädt Marketplace-Daten über denselben HTTP-Client und denselben Cache-Pfad statt mehrere Neuladungen innerhalb desselben Lebenszyklus anzustoßen. |
| **2.7.121** | 🟡 refactor | Admin/Plugins | **Die Registry-Logik bleibt näher an einem kleinen Modulvertrag**: `loadRegistry()` übernimmt das Caching sichtbar zentral, während Remote-JSON-Reads den vorbereiteten Client wiederverwenden statt ihren Transportzugang mehrfach selbst aufzubauen. |

---

### v2.7.120 — 25. März 2026 · Audit-Batch 202, Theme-Marketplace-Katalogpfad entschlackt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.120** | 🔴 fix | Admin/Themes | **`ThemeMarketplaceModule.php` sucht Installationsziele jetzt direkt im normalisierten Katalog statt über den kompletten angereicherten `getData()`-Pfad**: Die Theme-Suche für Installationen bleibt damit konsistenter und hängt nicht mehr an zusätzlichem Status- und Installations-Anreicherungsaufwand. |
| **2.7.120** | 🟠 perf | Admin/Themes | **Der Marketplace-Katalog wird pro Modulinstanz zwischengespeichert**: Remote-/lokale Katalogdaten werden nicht mehrfach neu aufgebaut, wenn Listen- und Installationspfad nacheinander auf denselben Theme-Bestand zugreifen. |
| **2.7.120** | 🟡 refactor | Admin/Themes | **Die Kataloglogik bleibt näher an einem kleinen Modulvertrag**: `getCatalog()` übernimmt das Caching sichtbar zentral, während `findCatalogTheme()` nur noch den schlanken Katalog durchsucht statt den kompletten View-Datenaufbau anzustoßen. |

---

### v2.7.119 — 25. März 2026 · Audit-Batch 201, Font-Manager-Settings ohne N+1-Existenzchecks

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.119** | 🔴 fix | Admin/Font Manager | **`FontManagerModule.php` lädt vorhandene Setting-Namen jetzt gesammelt vor und persistiert Font-Optionen über einen gemeinsamen Helfer**: Der Save-Pfad für Heading-, Body-, Größen-, Zeilenhöhen- und Local-Font-Settings läuft konsistenter, ohne pro Option erneut die Existenz in `settings` abzufragen. |
| **2.7.119** | 🟠 perf | Admin/Font Manager | **Der Font-Settings-Save-Pfad vermeidet wiederholte Datenbank-Roundtrips**: Statt pro Schlüssel `COUNT(*)`-Existenzchecks plus Mutation auszuführen, wird der bekannte Settings-Bestand einmal vorgeladen und anschließend gezielt `UPDATE` oder `INSERT` genutzt. |
| **2.7.119** | 🟡 refactor | Admin/Font Manager | **Die Persistenz bleibt näher an einem kleinen, klaren Modulvertrag**: `loadExistingSettings()` und `persistSetting()` bündeln Vorladen und Schreiblogik sichtbar, sodass `saveSettings()` stärker beim eigentlichen Font-Input bleibt. |

---

### v2.7.118 — 25. März 2026 · Audit-Batch 200, Cookie-Manager-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.118** | 🔴 fix | Admin/Legal | **`cookie-manager.php` bündelt Ziel-URL, Redirect-, Allowlist- und Dispatch-Pfade jetzt klarer über kleine Helfer**: Token-Fehler sowie Cookie-Manager-Aktionsrückgaben laufen konsistenter, ohne Redirect- und Action-Details lose im Einstieg zu verteilen. |
| **2.7.118** | 🟠 perf | Admin/Legal | **Action- und Fehlerpfade bleiben kompakter wartbar**: Ziel-URL, Allowlist-Prüfung und Dispatch-Auflösung müssen nicht mehr in mehreren Request-Zweigen parallel gepflegt werden. |
| **2.7.118** | 🟡 refactor | Admin/Legal | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Ziel-URL-, Allowlist- und Dispatch-Details sind in kleine Helfer ausgelagert, während die Cookie-Verwaltung im Einstieg fokussiert bleibt. |

---

### v2.7.117 — 25. März 2026 · Audit-Batch 199, Legal-Sites-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.117** | 🔴 fix | Admin/Legal Sites | **`legal-sites.php` bündelt Ziel-URL, Redirect-, Profil-Session-State und Template-Aufbau jetzt klarer über kleine Helfer**: Token-Fehler sowie Profil-, Template- und Mutationsrückgaben laufen konsistenter, ohne Session- und Redirect-Details lose im Einstieg zu verteilen. |
| **2.7.117** | 🟠 perf | Admin/Legal Sites | **Profil- und Template-Pfade bleiben kompakter wartbar**: Redirect-Ziel, Profil-State und Template-Aufbau müssen nicht mehr in mehreren Request-Zweigen parallel gepflegt werden. |
| **2.7.117** | 🟡 refactor | Admin/Legal Sites | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Ziel-URL-, Profil-State- und Template-Details sind in kleine Helfer ausgelagert, während die Legal-Sites-Verwaltung im Einstieg fokussiert bleibt. |

---

### v2.7.116 — 25. März 2026 · Audit-Batch 198, Error-Report-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.116** | 🔴 fix | Admin/System | **`error-report.php` bündelt Default-/Redirect-Ziel, JSON-Payload-Normalisierung und Report-Dispatch jetzt klarer über kleine Helfer**: Token-Fehler sowie Fehlerreport-Rückgaben laufen konsistenter, ohne Redirect- und Payload-Details lose im Einstieg zu verteilen. |
| **2.7.116** | 🟠 perf | Admin/System | **Payload- und Redirect-Pfade bleiben kompakter wartbar**: Redirect-Auflösung, JSON-Dekodierung und Report-Payload müssen nicht mehr in mehreren Request-Zweigen parallel gepflegt werden. |
| **2.7.116** | 🟡 refactor | Admin/System | **Der Entry bleibt näher am eigentlichen Service-Aufruf**: Default-URL-, Payload- und Dispatch-Details sind in kleine Helfer ausgelagert, während der Error-Report-Flow im Einstieg fokussiert bleibt. |

---

### v2.7.115 — 25. März 2026 · Audit-Batch 197, Theme-Explorer-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.115** | 🔴 fix | Admin/Themes | **`theme-explorer.php` bündelt Ziel-URL, Action-Allowlist, Redirect-, Flash-/Pull-Alert-Pfade und Save-Dispatch jetzt klarer über kleine Helfer**: Token-Fehler sowie Datei-Save-Rückgaben laufen konsistenter, ohne Session- und Redirect-Details lose im Einstieg zu verteilen. |
| **2.7.115** | 🟠 perf | Admin/Themes | **Datei- und Fehlerpfade bleiben kompakter wartbar**: Redirect-Ziel, Action-Allowlist und Save-Rückmeldungen müssen nicht mehr in mehreren POST-Zweigen parallel gepflegt werden. |
| **2.7.115** | 🟡 refactor | Admin/Themes | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Ziel-URL-, Pull-Alert- und Dispatch-Details sind in kleine Helfer ausgelagert, während der Theme-Explorer im Einstieg fokussiert bleibt. |

---

### v2.7.114 — 25. März 2026 · Audit-Batch 196, Hub-Sites-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.114** | 🔴 fix | Admin/Hub | **`hub-sites.php` bündelt Action-Allowlist, View-Normalisierung, Redirect-Abgänge, Flash-/Pull-Alert-Pfade und Action-Dispatch jetzt klarer über kleine Helfer**: Token-Fehler sowie Listen-, Edit-, Template- und Mutationspfade laufen konsistenter, ohne Redirect- und Session-Details lose im Einstieg zu verteilen. |
| **2.7.114** | 🟠 perf | Admin/Hub | **View- und Render-Pfade bleiben kompakter wartbar**: View-Auflösung, Asset-Konfiguration und Action-spezifische Redirect-Ziele müssen nicht mehr in mehreren `if`-/`switch`-Blöcken parallel gepflegt werden. |
| **2.7.114** | 🟡 refactor | Admin/Hub | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Allowlist-, Pull-Alert-, Dispatch- und Render-Konfiguration sind in kleine Helfer ausgelagert, während die Hub-Sites-Verwaltung im Einstieg fokussiert bleibt. |

---

### v2.7.113 — 25. März 2026 · Audit-Batch 195, Media-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.113** | 🔴 fix | Admin/Media | **`media.php` bündelt Action-Allowlist, Pfadauflösung, Redirect-Parameter, Upload-Rückmeldungen und Action-Dispatch jetzt klarer über kleine Helfer**: Token-Fehler, Member-Bestätigungs-nahe Redirects sowie Library-, Kategorie- und Settings-Aktionen laufen konsistenter, ohne Redirect- und Flash-Details lose im Einstieg zu verteilen. |
| **2.7.113** | 🟠 perf | Admin/Media | **Upload- und Redirect-Pfade bleiben kompakter wartbar**: Redirect-Query-Aufbau, Action-spezifische Zielpfade und Upload-Rückmeldungen müssen nicht mehr in mehreren POST-Zweigen parallel gepflegt werden. |
| **2.7.113** | 🟡 refactor | Admin/Media | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Allowlist-, Pull-Alert-, Pfad- und Dispatch-Details sind in kleine Helfer ausgelagert, während die Medienverwaltung im Einstieg fokussiert bleibt. |

---

### v2.7.112 — 25. März 2026 · Audit-Batch 194, Theme-Editor-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.112** | 🔴 fix | Admin/Themes | **`theme-editor.php` bündelt Layout- und Fallback-Pfade jetzt klarer über kleine Helfer**: Customizer-Einbettung und Fallback-Ansicht laufen konsistenter über denselben kleinen Entry-Pfad, ohne Header-/Sidebar-/Footer-Aufbau lose in zwei Zweigen zu verteilen. |
| **2.7.112** | 🟠 perf | Admin/Themes | **Customizer- und Fallback-Pfade bleiben kompakter wartbar**: Layout-Defaults, Fallback-Links und Render-Aufbau müssen nicht mehr in mehreren Zweigen parallel gepflegt werden. |
| **2.7.112** | 🟡 refactor | Admin/Themes | **Der Entry bleibt näher an seinem eigentlichen Routing-Zweck**: Layout- und Fallback-Details sind in kleine Helfer ausgelagert, während die Theme-Editor-Entscheidung fokussiert bleibt. |

---

### v2.7.111 — 25. März 2026 · Audit-Batch 193, Updates-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.111** | 🔴 fix | Admin/System | **`updates.php` bündelt Redirect-, Flash-, Pull-Alert-, Allowlist- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer**: Token-Fehler, Update-Prüfung sowie Core- und Plugin-Installationsrückgaben laufen konsistenter, ohne Session- und Redirect-Details lose im Einstieg zu verteilen. |
| **2.7.111** | 🟠 perf | Admin/System | **Prüf- und Fehlerpfade bleiben kompakter wartbar**: Action-Allowlist, Plugin-Slug-Normalisierung, Flash-Aufbau und Redirect-Abgang müssen nicht mehr in mehreren Verzweigungen parallel gepflegt werden. |
| **2.7.111** | 🟡 refactor | Admin/System | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Alert-, Pull-Alert- und Dispatch-Details sind in kleine Helfer ausgelagert, während die Update-Verwaltungslogik fokussiert bleibt. |

---

### v2.7.110 — 25. März 2026 · Audit-Batch 192, Themes-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.110** | 🔴 fix | Admin/Themes | **`themes.php` bündelt Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer**: Token-Fehler, Theme-Slug-Normalisierung sowie Aktivierungs- und Löschrückgaben laufen konsistenter, ohne Session- und Redirect-Details lose im Einstieg zu verteilen. |
| **2.7.110** | 🟠 perf | Admin/Themes | **Aktivierungs- und Fehlerpfade bleiben kompakter wartbar**: Slug-Normalisierung, Flash-Aufbau und Redirect-Abgang müssen nicht mehr in mehreren Verzweigungen parallel gepflegt werden. |
| **2.7.110** | 🟡 refactor | Admin/Themes | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Alert-, Pull-Alert- und Dispatch-Details sind in kleine Helfer ausgelagert, während die Theme-Verwaltungslogik fokussiert bleibt. |

---

### v2.7.109 — 25. März 2026 · Audit-Batch 191, Plugins-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.109** | 🔴 fix | Admin/Plugins | **`plugins.php` bündelt Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer**: Token-Fehler sowie Aktivierungs-, Deaktivierungs- und Löschrückgaben laufen konsistenter, ohne Session- und Redirect-Details lose im Einstieg zu verteilen. |
| **2.7.109** | 🟠 perf | Admin/Plugins | **Aktivierungs- und Fehlerpfade bleiben kompakter wartbar**: Action-Dispatch, Flash-Aufbau und Redirect-Abgang müssen nicht mehr mehrfach direkt im POST-Block gepflegt werden. |
| **2.7.109** | 🟡 refactor | Admin/Plugins | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Alert-, Pull-Alert- und Dispatch-Details sind in kleine Helfer ausgelagert, während die Plugin-Verwaltungslogik fokussiert bleibt. |

---

### v2.7.108 — 25. März 2026 · Audit-Batch 190, Theme-Marketplace-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.108** | 🔴 fix | Admin/Themes | **`theme-marketplace.php` bündelt Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer**: Token-Fehler und Installationsrückgaben laufen konsistenter, ohne Session- und Redirect-Details lose im Einstieg zu verteilen. |
| **2.7.108** | 🟠 perf | Admin/Themes | **Installations- und Fehlerpfade bleiben kompakter wartbar**: Slug-Normalisierung, Flash-Aufbau und Redirect-Abgang müssen nicht mehr in mehreren Verzweigungen parallel gepflegt werden. |
| **2.7.108** | 🟡 refactor | Admin/Themes | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Alert-, Pull-Alert- und Dispatch-Details sind in kleine Helfer ausgelagert, während die Marketplace-Seitenlogik fokussiert bleibt. |

---

### v2.7.107 — 25. März 2026 · Audit-Batch 189, Plugin-Marketplace-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.107** | 🔴 fix | Admin/Plugins | **`plugin-marketplace.php` bündelt Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer**: Token-Fehler und Installationsrückgaben laufen konsistenter, ohne Session- und Redirect-Details lose im Einstieg zu verteilen. |
| **2.7.107** | 🟠 perf | Admin/Plugins | **Installations- und Fehlerpfade bleiben kompakter wartbar**: Action-Dispatch, Flash-Aufbau und Redirect-Abgang müssen nicht mehr mehrfach direkt im POST-Block gepflegt werden. |
| **2.7.107** | 🟡 refactor | Admin/Plugins | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Alert-, Pull-Alert- und Dispatch-Details sind in kleine Helfer ausgelagert, während die Marketplace-Seitenlogik fokussiert bleibt. |

---

### v2.7.106 — 25. März 2026 · Audit-Batch 188, Landing-Page-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.106** | 🔴 fix | Admin/Landing Page | **`landing-page.php` bündelt Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer**: Token-Fehler und Aktionsrückgaben laufen konsistenter, ohne Handler- und Tab-Details lose im Einstieg zu verteilen. |
| **2.7.106** | 🟠 perf | Admin/Landing Page | **Dispatch- und Fehlerpfade bleiben kompakter wartbar**: die Handler-Map und der tabbezogene Redirect-Abgang müssen nicht mehr in mehreren Verzweigungen parallel gepflegt werden. |
| **2.7.106** | 🟡 refactor | Admin/Landing Page | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Alert-, Pull-Alert-, Tab- und Dispatch-Details sind in kleine Helfer ausgelagert, während die Seitenlogik fokussiert bleibt. |

---

### v2.7.105 — 25. März 2026 · Audit-Batch 187, Firewall-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.105** | 🔴 fix | Admin/Firewall | **`firewall.php` bündelt Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer**: Token-Fehler und Aktionsrückgaben laufen konsistenter, ohne Session- und Dispatch-Details lose im Einstieg zu verteilen. |
| **2.7.105** | 🟠 perf | Admin/Firewall | **Dispatch- und Fehlerpfade bleiben kompakter wartbar**: Flash- und Pull-Alert-Pfade müssen nicht mehr mehrfach im POST-Block parallel gepflegt werden. |
| **2.7.105** | 🟡 refactor | Admin/Firewall | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Alert-, Pull-Alert- und Dispatch-Details sind in kleine Helfer ausgelagert, während die Seitenlogik fokussiert bleibt. |

---

### v2.7.104 — 25. März 2026 · Audit-Batch 186, Font-Manager-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.104** | 🔴 fix | Admin/Font Manager | **`font-manager.php` bündelt Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer**: Token-Fehler und Aktionsrückgaben laufen konsistenter, ohne die Handler-Kette lose im Einstieg zu verteilen. |
| **2.7.104** | 🟠 perf | Admin/Font Manager | **Dispatch- und Fehlerpfade bleiben kompakter wartbar**: die Handler-Map und der Redirect-Abgang müssen nicht mehr in mehreren `if`-/`elseif`-Zweigen parallel gepflegt werden. |
| **2.7.104** | 🟡 refactor | Admin/Font Manager | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Alert-, Pull-Alert- und Dispatch-Details sind in kleine Helfer ausgelagert, während die Seitenlogik fokussiert bleibt. |

---

### v2.7.103 — 25. März 2026 · Audit-Batch 185, Backups-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.103** | 🔴 fix | Admin/System | **`backups.php` bündelt Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade jetzt klarer über kleine Helfer**: Token-Fehler und Aktionsrückgaben laufen konsistenter, ohne Handler- und Alert-Details lose im Einstieg zu verteilen. |
| **2.7.103** | 🟠 perf | Admin/System | **Dispatch- und Fehlerpfade bleiben kompakter wartbar**: die Handler-Map und der Redirect-Abgang müssen nicht mehr mehrfach im POST-Block parallel gepflegt werden. |
| **2.7.103** | 🟡 refactor | Admin/System | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Alert-, Pull-Alert- und Dispatch-Details sind in kleine Helfer ausgelagert, während die Seitenlogik fokussiert bleibt. |

---

### v2.7.102 — 25. März 2026 · Audit-Batch 184, Cookie-Manager-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.102** | 🔴 fix | Admin/Cookie Manager | **`cookie-manager.php` bündelt Action-Allowlist und Session-Alert-Pfade jetzt klarer über kleine Helfer**: Token-Fehler und Aktionsrückgaben laufen konsistenter, ohne Allowlist- und Alert-Details lose im Einstieg zu verteilen. |
| **2.7.102** | 🟠 perf | Admin/Cookie Manager | **Alert- und Fehlerpfade bleiben kompakter wartbar**: die Allowlist wird nicht mehr als lose Werteliste direkt im Request-Flow behandelt. |
| **2.7.102** | 🟡 refactor | Admin/Cookie Manager | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Alert- und Allowlist-Details sind in kleine Helfer ausgelagert, während die Seitenlogik fokussiert bleibt. |

---

### v2.7.101 — 25. März 2026 · Audit-Batch 183, Packages-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.101** | 🔴 fix | Admin/Pakete | **`packages.php` bündelt Action-Dispatch und Session-Alert-Pfade jetzt klarer über kleine Handler-Helfer**: Paket- und Settings-Aktionen laufen konsistenter, ohne Switch-Logik und Redirects lose im Einstieg zu verteilen. |
| **2.7.101** | 🟠 perf | Admin/Pakete | **Dispatch- und Fehlerpfade bleiben kompakter wartbar**: Handler-Map und Redirect-Flow müssen nicht mehr mehrfach im POST-Block gepflegt werden. |
| **2.7.101** | 🟡 refactor | Admin/Pakete | **Der Entry bleibt näher an den eigentlichen Modulen**: Dispatch-Details liegen in kleinen Helfern, während der Seitenaufbau unverändert fokussiert bleibt. |

---

### v2.7.100 — 25. März 2026 · Audit-Batch 182, Orders-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.100** | 🔴 fix | Admin/Orders | **`orders.php` bündelt Action-Allowlist und Session-Alert-Pfade jetzt klarer über kleine Helfer**: Token-Fehler und Aktionsrückgaben laufen konsistenter, ohne Allowlist- und Alert-Details lose im Einstieg zu verteilen. |
| **2.7.100** | 🟠 perf | Admin/Orders | **Alert- und Fehlerpfade bleiben kompakter wartbar**: die Allowlist wird nicht mehr erst im Request-Flow separat aufgebaut. |
| **2.7.100** | 🟡 refactor | Admin/Orders | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Alert- und Allowlist-Details sind in kleine Helfer ausgelagert, während die Seitenlogik fokussiert bleibt. |

---

### v2.7.99 — 25. März 2026 · Audit-Batch 181, Error-Report-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.99** | 🔴 fix | Admin/Error Report | **`error-report.php` bündelt Redirect-, Flash- und JSON-Normalisierung jetzt klarer über kleine Entry-Helfer**: Token-Fehler und Service-Rückgaben laufen konsistenter, ohne Request-Normalisierung lose im Einstieg zu verteilen. |
| **2.7.99** | 🟠 perf | Admin/Error Report | **Payload- und Fehlerpfade bleiben kompakter wartbar**: JSON-Parsing und Alert-Aufbau werden nicht mehr implizit an mehreren Stellen behandelt. |
| **2.7.99** | 🟡 refactor | Admin/Error Report | **Der Entry bleibt näher am eigentlichen Service-Aufruf**: Request-Normalisierung und Flash-Details sind in kleine Helfer ausgelagert, während die Endpoint-Logik fokussiert bleibt. |

---

### v2.7.98 — 25. März 2026 · Audit-Batch 180, Deletion-Requests-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.98** | 🔴 fix | Admin/DSGVO | **`deletion-requests.php` bündelt sein Redirect-Ziel jetzt klarer über einen kleinen Ziel-Helfer**: Guard- und Standard-Redirect nutzen denselben sichtbaren Zielpfad, statt die Ziel-URL lose auszuschreiben. |
| **2.7.98** | 🟠 perf | Admin/DSGVO | **Redirect-Ziele bleiben kompakter wartbar**: Zielpfade müssen nicht mehr an mehreren Stellen parallel gepflegt werden. |
| **2.7.98** | 🟡 refactor | Admin/DSGVO | **Der Entry bleibt näher an seinem eigentlichen Routing-Zweck**: Ziel-Details sind in einen kleinen Helfer ausgelagert, während der Einstieg minimal fokussiert bleibt. |

---

### v2.7.97 — 25. März 2026 · Audit-Batch 179, Design-Settings-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.97** | 🔴 fix | Admin/Design | **`design-settings.php` bündelt sein Redirect-Ziel jetzt klarer über einen kleinen Ziel-Helfer**: Guard- und Standard-Redirect nutzen denselben sichtbaren Zielpfad, statt die Ziel-URL lose auszuschreiben. |
| **2.7.97** | 🟠 perf | Admin/Design | **Redirect-Ziele bleiben kompakter wartbar**: Zielpfade müssen nicht mehr an mehreren Stellen parallel gepflegt werden. |
| **2.7.97** | 🟡 refactor | Admin/Design | **Der Entry bleibt näher an seinem eigentlichen Routing-Zweck**: Ziel-Details sind in einen kleinen Helfer ausgelagert, während der Einstieg minimal fokussiert bleibt. |

---

### v2.7.96 — 25. März 2026 · Audit-Batch 178, Legal-Sites-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.96** | 🔴 fix | Admin/Legal Sites | **`legal-sites.php` bündelt POST-Dispatch, Flash-Meldungen und Redirects jetzt klarer über kleine Entry-Helfer**: Action-Handler und PRG-Redirect laufen konsistenter, ohne die eigentliche Modul-Logik im Einstieg zu verteilen. |
| **2.7.96** | 🟠 perf | Admin/Legal Sites | **Fehler- und Redirect-Pfade bleiben kompakter wartbar**: Session-Alerts, Handler-Map und Redirect-Aufbau werden nicht mehr mehrfach im POST-Block ausgeschrieben. |
| **2.7.96** | 🟡 refactor | Admin/Legal Sites | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Dispatch-Details sind in kleine Helfer ausgelagert, während die Seitenlogik unverändert fokussiert bleibt. |

---

### v2.7.95 — 25. März 2026 · Audit-Batch 177, Documentation-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.95** | 🔴 fix | Admin/Dokumentation | **`documentation.php` bündelt Dokumentauswahl, Aktionshandler, Flash-Meldungen und Redirects jetzt klarer über kleine Entry-Helfer**: Sync-Aktionen und PRG-Redirect laufen konsistenter, ohne die eigentliche Modul-Logik im Einstieg zu verteilen. |
| **2.7.95** | 🟠 perf | Admin/Dokumentation | **Fehler- und Redirect-Pfade bleiben kompakter wartbar**: Dokumentauswahl, Handler und Redirect-Aufbau werden nicht mehr verstreut im Request-Flow definiert. |
| **2.7.95** | 🟡 refactor | Admin/Dokumentation | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Auswahl- und Dispatch-Details sind in kleine Helfer ausgelagert, während die Seitenlogik unverändert fokussiert bleibt. |

---

### v2.7.94 — 25. März 2026 · Audit-Batch 176, Mail-Settings-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.94** | 🔴 fix | Admin/System | **`mail-settings.php` koppelt Actions jetzt enger an dieselbe Handler-Map**: Dispatch und unbekannte Aktionen laufen konsistenter, ohne parallele Allowlist- und Handler-Definitionen im Einstieg zu pflegen. |
| **2.7.94** | 🟠 perf | Admin/System | **Handler-Definitionen bleiben kompakter wartbar**: POST-Input und Aktionseinträge werden nicht mehr doppelt zwischen Allowlist und Handlern gepflegt. |
| **2.7.94** | 🟡 refactor | Admin/System | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Dispatch-Details sind auf die kleine Handler-Map konzentriert, während die Seitenlogik unverändert fokussiert bleibt. |

---

### v2.7.93 — 25. März 2026 · Audit-Batch 175, Gruppen-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.93** | 🔴 fix | Admin/Gruppen | **`groups.php` bündelt POST-Dispatch, Flash-Meldungen und Redirects jetzt klarer über kleine Entry-Helfer**: Action-Handler und PRG-Redirect laufen konsistenter, ohne die eigentliche Modul-Logik im Einstieg zu verteilen. |
| **2.7.93** | 🟠 perf | Admin/Gruppen | **Fehler- und Redirect-Pfade bleiben kompakter wartbar**: Session-Alerts, Handler-Map und Redirect-Aufbau werden nicht mehr mehrfach im POST-Block ausgeschrieben. |
| **2.7.93** | 🟡 refactor | Admin/Gruppen | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Dispatch-Details sind in kleine Helfer ausgelagert, während die Seitenlogik unverändert fokussiert bleibt. |

---

### v2.7.92 — 25. März 2026 · Audit-Batch 174, Data-Requests-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.92** | 🔴 fix | Admin/DSGVO | **`data-requests.php` bündelt POST-Dispatch, Flash-Meldungen und Redirects jetzt klarer über kleine Entry-Helfer**: Scope-Aktionen und PRG-Redirect laufen konsistenter, ohne die eigentliche Modul-Logik im Einstieg zu verteilen. |
| **2.7.92** | 🟠 perf | Admin/DSGVO | **Fehler- und Redirect-Pfade bleiben kompakter wartbar**: Session-Alerts und Redirect-Aufrufe werden nicht mehr getrennt zwischen Fehler- und Erfolgsfall behandelt. |
| **2.7.92** | 🟡 refactor | Admin/DSGVO | **Der Entry bleibt näher an den eigentlichen Modulen**: Privacy- und Deletion-Dispatch bleiben fokussiert, während Redirect-Details in kleinen Helfern liegen. |

---

### v2.7.91 — 25. März 2026 · Audit-Batch 173, Settings-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.91** | 🔴 fix | Admin/Settings | **`settings.php` bündelt Tab-Normalisierung, POST-Dispatch, Flash-Meldungen und Redirects jetzt klarer über kleine Entry-Helfer**: Action-Handler und PRG-Redirect laufen konsistenter, ohne die eigentliche Modul-Logik im Einstieg zu verteilen. |
| **2.7.91** | 🟠 perf | Admin/Settings | **Fehler- und Redirect-Pfade bleiben kompakter wartbar**: Tab-Normalisierung, Session-Alerts und Redirect-Aufbau werden nicht mehr mehrfach im POST-Block ausgeschrieben. |
| **2.7.91** | 🟡 refactor | Admin/Settings | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Dispatch-Details sind in kleine Helfer ausgelagert, während die Seitenlogik unverändert fokussiert bleibt. |

---

### v2.7.90 — 25. März 2026 · Audit-Batch 172, Comments-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.90** | 🔴 fix | Admin/Kommentare | **`comments.php` bündelt POST-Dispatch, Flash-Meldungen und Redirects jetzt klarer über kleine Entry-Helfer**: Aktionspfade und PRG-Redirect laufen konsistenter, ohne die eigentliche Modul-Logik im Einstieg zu verteilen. |
| **2.7.90** | 🟠 perf | Admin/Kommentare | **Fehler- und Redirect-Pfade bleiben kompakter wartbar**: Session-Alerts und Redirect-Aufrufe werden nicht mehr mehrfach im POST-Block ausgeschrieben. |
| **2.7.90** | 🟡 refactor | Admin/Kommentare | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Dispatch-Details sind in kleine Helfer ausgelagert, während die Seitenlogik unverändert fokussiert bleibt. |

---

### v2.7.89 — 25. März 2026 · Audit-Batch 171, 404-Monitor-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.89** | 🔴 fix | Admin/SEO | **`not-found-monitor.php` bündelt POST-Dispatch, Flash-Meldungen und Redirects jetzt klarer über kleine Entry-Helfer**: Token-Fehler und Aktionsrückgaben laufen konsistenter über denselben PRG-Pfad, ohne die Modul-Logik im Einstieg zu verteilen. |
| **2.7.89** | 🟠 perf | Admin/SEO | **Fehler- und Redirect-Pfade bleiben kompakter wartbar**: Action-Handler und Redirect-Ziel werden nicht mehr verstreut im POST-Block aufgebaut. |
| **2.7.89** | 🟡 refactor | Admin/SEO | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: kleine Helfer kapseln Dispatch- und Session-Details, während der Seitenaufbau unverändert fokussiert bleibt. |

---

### v2.7.88 — 25. März 2026 · Audit-Batch 170, Menü-Editor-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.88** | 🔴 fix | Admin/Menüs | **`menu-editor.php` bündelt POST-Dispatch, Flash-Meldungen und Redirects jetzt klarer über kleine Entry-Helfer**: Action-Handler und PRG-Redirect laufen konsistenter, ohne die eigentliche Modul-Logik im Einstieg zu verteilen. |
| **2.7.88** | 🟠 perf | Admin/Menüs | **Fehler- und Redirect-Pfade bleiben kompakter wartbar**: Session-Alerts, Handler-Map und Redirect-Aufbau werden nicht mehr mehrfach im POST-Block ausgeschrieben. |
| **2.7.88** | 🟡 refactor | Admin/Menüs | **Der Entry bleibt näher am eigentlichen Modul-Aufruf**: Dispatch-Details sind in kleine Helfer ausgelagert, während die Seitenlogik unverändert fokussiert bleibt. |

---

### v2.7.87 — 25. März 2026 · Audit-Batch 169, Diagnose-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.87** | 🔴 fix | Admin/Monitoring | **Der Diagnose-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/diagnose.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `system-monitor-page.php`. |
| **2.7.87** | 🟠 perf | Admin/Monitoring | **Änderungen an Diagnose-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.87** | 🟡 refactor | Admin/Monitoring | **Der Diagnose-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.86 — 25. März 2026 · Audit-Batch 168, Performance-Overview-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.86** | 🔴 fix | Admin/Performance | **Der Performance-Overview-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/performance.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `performance-page.php`. |
| **2.7.86** | 🟠 perf | Admin/Performance | **Änderungen an Performance-Overview-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.86** | 🟡 refactor | Admin/Performance | **Der Overview-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.85 — 25. März 2026 · Audit-Batch 167, SEO-Schema-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.85** | 🔴 fix | Admin/SEO | **Der SEO-Schema-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/seo-schema.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `seo-page.php`. |
| **2.7.85** | 🟠 perf | Admin/SEO | **Änderungen an SEO-Schema-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.85** | 🟡 refactor | Admin/SEO | **Der Schema-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.84 — 25. März 2026 · Audit-Batch 166, SEO-Sitemap-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.84** | 🔴 fix | Admin/SEO | **Der SEO-Sitemap-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/seo-sitemap.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `seo-page.php`. |
| **2.7.84** | 🟠 perf | Admin/SEO | **Änderungen an SEO-Sitemap-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.84** | 🟡 refactor | Admin/SEO | **Der Sitemap-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.83 — 25. März 2026 · Audit-Batch 165, SEO-Technical-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.83** | 🔴 fix | Admin/SEO | **Der SEO-Technical-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/seo-technical.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `seo-page.php`. |
| **2.7.83** | 🟠 perf | Admin/SEO | **Änderungen an SEO-Technical-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.83** | 🟡 refactor | Admin/SEO | **Der Technical-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.82 — 25. März 2026 · Audit-Batch 164, SEO-Audit-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.82** | 🔴 fix | Admin/SEO | **Der SEO-Audit-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/seo-audit.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `seo-page.php`. |
| **2.7.82** | 🟠 perf | Admin/SEO | **Änderungen an SEO-Audit-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.82** | 🟡 refactor | Admin/SEO | **Der Audit-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.81 — 25. März 2026 · Audit-Batch 163, SEO-Page-Konfigurationspfad weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.81** | 🔴 fix | Admin/SEO | **`seo-page.php` normalisiert Seitenkonfiguration jetzt zentral über einen kleinen Helper**: Fallbacks für Section-, Route-, View-, Titel- und Active-Page-Werte werden nicht mehr lose direkt im Einstieg verteilt, sondern über einen gemeinsamen Normalisierungspfad aufgelöst. |
| **2.7.81** | 🟠 perf | Admin/SEO | **Änderungen an SEO-Seitenmetadaten bleiben zentraler wartbar**: Default- und Wrapper-Werte müssen nicht mehr an mehreren Stellen parallel angepasst werden. |
| **2.7.81** | 🟡 refactor | Admin/SEO | **Das gemeinsame Seitengerüst bleibt näher an seinem eigentlichen Seitenvertrag**: Normalisierung und Fallbacks sind sichtbar gebündelt, während der restliche Request-Flow unverändert fokussiert bleibt. |

---

### v2.7.80 — 25. März 2026 · Audit-Batch 162, SEO-Social-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.80** | 🔴 fix | Admin/SEO | **Der SEO-Social-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/seo-social.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `seo-page.php`. |
| **2.7.80** | 🟠 perf | Admin/SEO | **Änderungen an SEO-Social-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.80** | 🟡 refactor | Admin/SEO | **Der Social-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.79 — 25. März 2026 · Audit-Batch 161, SEO-Meta-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.79** | 🔴 fix | Admin/SEO | **Der SEO-Meta-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/seo-meta.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `seo-page.php`. |
| **2.7.79** | 🟠 perf | Admin/SEO | **Änderungen an SEO-Meta-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.79** | 🟡 refactor | Admin/SEO | **Der Meta-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.78 — 25. März 2026 · Audit-Batch 160, SEO-Dashboard-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.78** | 🔴 fix | Admin/SEO | **Der SEO-Dashboard-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/seo-dashboard.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `seo-page.php`. |
| **2.7.78** | 🟠 perf | Admin/SEO | **Änderungen an SEO-Dashboard-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.78** | 🟡 refactor | Admin/SEO | **Der Dashboard-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.77 — 25. März 2026 · Audit-Batch 159, Analytics-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.77** | 🔴 fix | Admin/SEO | **Der Analytics-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/analytics.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `seo-page.php`. |
| **2.7.77** | 🟠 perf | Admin/SEO | **Änderungen an Analytics-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.77** | 🟡 refactor | Admin/SEO | **Der Analytics-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.76 — 25. März 2026 · Audit-Batch 158, Info-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.76** | 🔴 fix | Admin/System | **Der Info-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/info.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `system-monitor-page.php`. |
| **2.7.76** | 🟠 perf | Admin/System | **Änderungen an Info-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.76** | 🟡 refactor | Admin/System | **Der Info-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.75 — 25. März 2026 · Audit-Batch 157, Monitor-Email-Alerts-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.75** | 🔴 fix | Admin/Monitoring | **Der Monitor-Email-Alerts-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/monitor-email-alerts.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `system-monitor-page.php`. |
| **2.7.75** | 🟠 perf | Admin/Monitoring | **Änderungen an Email-Alerts-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.75** | 🟡 refactor | Admin/Monitoring | **Der Email-Alerts-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.74 — 25. März 2026 · Audit-Batch 156, Monitor-Health-Check-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.74** | 🔴 fix | Admin/Monitoring | **Der Monitor-Health-Check-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/monitor-health-check.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `system-monitor-page.php`. |
| **2.7.74** | 🟠 perf | Admin/Monitoring | **Änderungen an Health-Check-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.74** | 🟡 refactor | Admin/Monitoring | **Der Health-Check-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.73 — 25. März 2026 · Audit-Batch 155, Monitor-Cron-Status-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.73** | 🔴 fix | Admin/Monitoring | **Der Monitor-Cron-Status-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/monitor-cron-status.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `system-monitor-page.php`. |
| **2.7.73** | 🟠 perf | Admin/Monitoring | **Änderungen an Cron-Status-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.73** | 🟡 refactor | Admin/Monitoring | **Der Cron-Status-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.72 — 25. März 2026 · Audit-Batch 154, System-Monitor-Page-Konfigurationspfad weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.72** | 🔴 fix | Admin/Monitoring | **`system-monitor-page.php` normalisiert Seitenkonfiguration jetzt zentral über einen kleinen Helper**: Fallbacks für Section-, Route-, View-, Titel-, Active-Page- und Asset-Werte werden nicht mehr lose direkt im Einstieg verteilt, sondern über einen gemeinsamen Normalisierungspfad aufgelöst. |
| **2.7.72** | 🟠 perf | Admin/Monitoring | **Änderungen an Monitoring-Seitenmetadaten bleiben zentraler wartbar**: Default- und Wrapper-Werte müssen nicht mehr an mehreren Stellen parallel angepasst werden. |
| **2.7.72** | 🟡 refactor | Admin/Monitoring | **Das gemeinsame Seitengerüst bleibt näher an seinem eigentlichen Seitenvertrag**: Normalisierung und Fallbacks sind sichtbar gebündelt, während der restliche Shell-Aufbau unverändert fokussiert bleibt. |

---

### v2.7.71 — 25. März 2026 · Audit-Batch 153, Monitor-Scheduled-Tasks-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.71** | 🔴 fix | Admin/Monitoring | **Der Monitor-Scheduled-Tasks-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/monitor-scheduled-tasks.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `system-monitor-page.php`. |
| **2.7.71** | 🟠 perf | Admin/Monitoring | **Änderungen an Scheduled-Tasks-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.71** | 🟡 refactor | Admin/Monitoring | **Der Scheduled-Tasks-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.70 — 25. März 2026 · Audit-Batch 152, Monitor-Disk-Usage-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.70** | 🔴 fix | Admin/Monitoring | **Der Monitor-Disk-Usage-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/monitor-disk-usage.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `system-monitor-page.php`. |
| **2.7.70** | 🟠 perf | Admin/Monitoring | **Änderungen an Disk-Usage-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.70** | 🟡 refactor | Admin/Monitoring | **Der Disk-Usage-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.69 — 25. März 2026 · Audit-Batch 151, Monitor-Response-Time-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.69** | 🔴 fix | Admin/Monitoring | **Der Monitor-Response-Time-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/monitor-response-time.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `system-monitor-page.php`. |
| **2.7.69** | 🟠 perf | Admin/Monitoring | **Änderungen an Response-Time-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.69** | 🟡 refactor | Admin/Monitoring | **Der Response-Time-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.68 — 25. März 2026 · Audit-Batch 150, Performance-Sessions-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.68** | 🔴 fix | Admin/Performance | **Der Performance-Sessions-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/performance-sessions.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `performance-page.php`. |
| **2.7.68** | 🟠 perf | Admin/Performance | **Änderungen an Performance-Sessions-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.68** | 🟡 refactor | Admin/Performance | **Der Sessions-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.67 — 25. März 2026 · Audit-Batch 149, Performance-Page-Konfigurationspfad weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.67** | 🔴 fix | Admin/Performance | **`performance-page.php` normalisiert Seitenkonfiguration jetzt zentral über einen kleinen Helper**: Fallbacks für Section-, Route-, View-, Titel-, Active-Page- und Asset-Werte werden nicht mehr lose direkt im Einstieg verteilt, sondern über einen gemeinsamen Normalisierungspfad aufgelöst. |
| **2.7.67** | 🟠 perf | Admin/Performance | **Änderungen an Performance-Seitenmetadaten bleiben zentraler wartbar**: Default- und Wrapper-Werte müssen nicht mehr an mehreren Stellen parallel angepasst werden. |
| **2.7.67** | 🟡 refactor | Admin/Performance | **Das gemeinsame Seitengerüst bleibt näher an seinem eigentlichen Seitenvertrag**: Normalisierung und Fallbacks sind sichtbar gebündelt, während der restliche Shell-Aufbau unverändert fokussiert bleibt. |

---

### v2.7.66 — 25. März 2026 · Audit-Batch 148, Performance-Settings-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.66** | 🔴 fix | Admin/Performance | **Der Performance-Settings-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/performance-settings.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `performance-page.php`. |
| **2.7.66** | 🟠 perf | Admin/Performance | **Änderungen an Performance-Settings-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.66** | 🟡 refactor | Admin/Performance | **Der Settings-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.65 — 25. März 2026 · Audit-Batch 147, Performance-Media-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.65** | 🔴 fix | Admin/Performance | **Der Performance-Media-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/performance-media.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `performance-page.php`. |
| **2.7.65** | 🟠 perf | Admin/Performance | **Änderungen an Performance-Media-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.65** | 🟡 refactor | Admin/Performance | **Der Media-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.64 — 25. März 2026 · Audit-Batch 146, Performance-Database-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.64** | 🔴 fix | Admin/Performance | **Der Performance-Database-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/performance-database.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `performance-page.php`. |
| **2.7.64** | 🟠 perf | Admin/Performance | **Änderungen an Performance-Database-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.64** | 🟡 refactor | Admin/Performance | **Der Database-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.63 — 25. März 2026 · Audit-Batch 145, Performance-Cache-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.63** | 🔴 fix | Admin/Performance | **Der Performance-Cache-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/performance-cache.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `performance-page.php`. |
| **2.7.63** | 🟠 perf | Admin/Performance | **Änderungen an Performance-Cache-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.63** | 🟡 refactor | Admin/Performance | **Der Cache-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.62 — 25. März 2026 · Audit-Batch 144, Member-Dashboard-Page-Konfigurationspfad weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.62** | 🔴 fix | Admin/Member | **`member-dashboard-page.php` normalisiert Seitenkonfiguration jetzt zentral über einen kleinen Helper**: Fallbacks für Section-, Route-, View-, Titel-, Active-Page- und Asset-Werte werden nicht mehr lose direkt im Einstieg verteilt, sondern über einen gemeinsamen Normalisierungspfad aufgelöst. |
| **2.7.62** | 🟠 perf | Admin/Member | **Änderungen an Member-Dashboard-Seitenmetadaten bleiben zentraler wartbar**: Default- und Wrapper-Werte müssen nicht mehr an mehreren Stellen parallel angepasst werden. |
| **2.7.62** | 🟡 refactor | Admin/Member | **Das gemeinsame Seitengerüst bleibt näher an seinem eigentlichen Seitenvertrag**: Normalisierung und Fallbacks sind sichtbar gebündelt, während der restliche Shell-Aufbau unverändert fokussiert bleibt. |

---

### v2.7.61 — 25. März 2026 · Audit-Batch 143, Member-Dashboard-Widgets-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.61** | 🔴 fix | Admin/Member | **Der Member-Dashboard-Widgets-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/member-dashboard-widgets.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `member-dashboard-page.php`. |
| **2.7.61** | 🟠 perf | Admin/Member | **Änderungen an Widgets-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.61** | 🟡 refactor | Admin/Member | **Der Widgets-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.60 — 25. März 2026 · Audit-Batch 142, Member-Dashboard-Profile-Fields-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.60** | 🔴 fix | Admin/Member | **Der Member-Dashboard-Profile-Fields-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/member-dashboard-profile-fields.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `member-dashboard-page.php`. |
| **2.7.60** | 🟠 perf | Admin/Member | **Änderungen an Profile-Fields-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.60** | 🟡 refactor | Admin/Member | **Der Profile-Fields-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.59 — 25. März 2026 · Audit-Batch 141, Member-Dashboard-Plugin-Widgets-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.59** | 🔴 fix | Admin/Member | **Der Member-Dashboard-Plugin-Widgets-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/member-dashboard-plugin-widgets.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `member-dashboard-page.php`. |
| **2.7.59** | 🟠 perf | Admin/Member | **Änderungen an Plugin-Widgets-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.59** | 🟡 refactor | Admin/Member | **Der Plugin-Widgets-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.58 — 25. März 2026 · Audit-Batch 140, Member-Dashboard-Onboarding-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.58** | 🔴 fix | Admin/Member | **Der Member-Dashboard-Onboarding-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/member-dashboard-onboarding.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `member-dashboard-page.php`. |
| **2.7.58** | 🟠 perf | Admin/Member | **Änderungen an Onboarding-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.58** | 🟡 refactor | Admin/Member | **Der Onboarding-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.57 — 25. März 2026 · Audit-Batch 139, Member-Dashboard-Notifications-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.57** | 🔴 fix | Admin/Member | **Der Member-Dashboard-Notifications-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/member-dashboard-notifications.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `member-dashboard-page.php`. |
| **2.7.57** | 🟠 perf | Admin/Member | **Änderungen an Notifications-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.57** | 🟡 refactor | Admin/Member | **Der Notifications-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.56 — 25. März 2026 · Audit-Batch 138, Member-Dashboard-General-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.56** | 🔴 fix | Admin/Member | **Der Member-Dashboard-General-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/member-dashboard-general.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `member-dashboard-page.php`. |
| **2.7.56** | 🟠 perf | Admin/Member | **Änderungen an General-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.56** | 🟡 refactor | Admin/Member | **Der General-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.55 — 25. März 2026 · Audit-Batch 137, Member-Dashboard-Frontend-Modules-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.55** | 🔴 fix | Admin/Member | **Der Member-Dashboard-Frontend-Modules-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/member-dashboard-frontend-modules.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `member-dashboard-page.php`. |
| **2.7.55** | 🟠 perf | Admin/Member | **Änderungen an Frontend-Modules-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.55** | 🟡 refactor | Admin/Member | **Der Frontend-Modules-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.54 — 25. März 2026 · Audit-Batch 136, Member-Dashboard-Design-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.54** | 🔴 fix | Admin/Member | **Der Member-Dashboard-Design-Entry nutzt jetzt denselben kleinen Konfigurations-Wrapper wie seine Schwesterseiten**: `CMS/admin/member-dashboard-design.php` übergibt Section-, Route- und View-Metadaten nicht mehr als lose Einzelvariablen, sondern als kompaktes Konfigurationsarray an `member-dashboard-page.php`. |
| **2.7.54** | 🟠 perf | Admin/Member | **Änderungen an Design-Metadaten bleiben zentraler wartbar**: Section-Definitionen lassen sich konsistenter anpassen, ohne dass lose Variablensets pro Wrapper auseinanderdriften. |
| **2.7.54** | 🟡 refactor | Admin/Member | **Der Design-Wrapper bleibt näher an seinem eigentlichen Zweck**: der Entry beschreibt nur noch seine Seitenkonfiguration statt wiederholt dieselbe Variablenstruktur auszuschreiben. |

---

### v2.7.53 — 25. März 2026 · Audit-Batch 135, Member-Dashboard-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.53** | 🔴 fix | Admin/Member | **Der Member-Dashboard-Entry nutzt jetzt denselben kleinen Redirect-Helper wie andere Alias-Entrys**: `CMS/admin/member-dashboard.php` kapselt Legacy-Section-Weiterleitungen und Admin-Redirects nicht mehr als rohe Header-Aufrufe direkt im Entry. |
| **2.7.53** | 🟠 perf | Admin/Member | **Änderungen an Alias-Weiterleitungen bleiben zentraler wartbar**: Redirect-Verhalten für Legacy-Sektionen liegt sichtbar an einer Stelle und folgt dem gleichen Muster wie andere schlanke Admin-Entrys. |
| **2.7.53** | 🟡 refactor | Admin/Member | **Der Member-Dashboard-Entry bleibt näher am eigentlichen Request-Flow**: der Einstieg konzentriert sich nur noch auf Legacy-Routing, Admin-Guard und Redirect statt auf offene Header-/Exit-Strecken. |

---

### v2.7.52 — 25. März 2026 · Audit-Batch 134, Media-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.52** | 🔴 fix | Admin/Media | **Media-POST-Aktionen nutzen jetzt denselben kleinen Redirect-/Flash-Rahmen**: `CMS/admin/media.php` baut Redirects und Session-Alerts für Library-, Category- und Settings-Aktionen nicht mehr mehrfach direkt im POST-Flow zusammen. |
| **2.7.52** | 🟠 perf | Admin/Media | **Änderungen an Media-Aktionen bleiben zentraler wartbar**: Anpassungen an gemeinsamer Redirect- oder Alert-Logik müssen nicht mehr in mehreren Zweigen parallel nachgezogen werden. |
| **2.7.52** | 🟡 refactor | Admin/Media | **Der Media-Entry bleibt näher am eigentlichen Request-Flow**: der POST-Pfad konzentriert sich stärker auf Token-Prüfung und Aktionsresultate statt auf wiederholte Redirect- und Alert-Orchestrierung. |

---

### v2.7.51 — 25. März 2026 · Audit-Batch 133, Legal-Sites-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.51** | 🔴 fix | Admin/Legal | **Legal-Site-POST-Aktionen nutzen jetzt denselben kleinen Redirect-/Flash-/Dispatch-Rahmen**: `CMS/admin/legal-sites.php` baut Redirects, Alerts und Aktionsauflösung für Save-, Profile-, Generate- und Page-Aktionen nicht mehr mehrfach direkt im POST-Flow zusammen. |
| **2.7.51** | 🟠 perf | Admin/Legal | **Änderungen an Legal-Site-Aktionen bleiben zentraler wartbar**: Anpassungen an gemeinsamer Redirect-, Alert- oder Dispatch-Logik müssen nicht mehr in mehreren Zweigen parallel nachgezogen werden. |
| **2.7.51** | 🟡 refactor | Admin/Legal | **Der Legal-Sites-Entry bleibt näher am eigentlichen Request-Flow**: der POST-Pfad konzentriert sich stärker auf Token-Prüfung und Aktionsauflösung statt auf wiederholte Redirect- und Alert-Orchestrierung. |

---

### v2.7.50 — 25. März 2026 · Audit-Batch 132, Landing-Page-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.50** | 🔴 fix | Admin/Landing | **Landing-Page-POST-Aktionen nutzen jetzt denselben kleinen Redirect-/Flash-/Dispatch-Rahmen**: `CMS/admin/landing-page.php` baut Redirects, Alerts und Aktionsauflösung für Header-, Content-, Footer-, Design-, Feature- und Plugin-Aktionen nicht mehr mehrfach direkt im POST-Flow zusammen. |
| **2.7.50** | 🟠 perf | Admin/Landing | **Änderungen an Landing-Page-Aktionen bleiben zentraler wartbar**: Anpassungen an gemeinsamer Redirect-, Alert- oder Dispatch-Logik müssen nicht mehr in mehreren Zweigen parallel nachgezogen werden. |
| **2.7.50** | 🟡 refactor | Admin/Landing | **Der Landing-Page-Entry bleibt näher am eigentlichen Request-Flow**: der POST-Pfad konzentriert sich stärker auf Token-Prüfung und Aktionsauflösung statt auf wiederholte Redirect- und Alert-Orchestrierung. |

---

### v2.7.49 — 25. März 2026 · Audit-Batch 131, Hub-Sites-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.49** | 🔴 fix | Admin/Hub | **Hub-Site-POST-Aktionen nutzen jetzt denselben kleinen Redirect-/Flash-Rahmen**: `CMS/admin/hub-sites.php` baut Redirects und Alerts für Save-, Template- und Duplicate-/Delete-Aktionen nicht mehr mehrfach direkt im POST-Flow zusammen. |
| **2.7.49** | 🟠 perf | Admin/Hub | **Änderungen an Hub-Site-Aktionen bleiben zentraler wartbar**: Anpassungen an gemeinsamer Redirect- oder Alert-Logik müssen nicht mehr in mehreren Zweigen parallel nachgezogen werden. |
| **2.7.49** | 🟡 refactor | Admin/Hub | **Der Hub-Sites-Entry bleibt näher am eigentlichen Request-Flow**: der POST-Pfad konzentriert sich stärker auf Token-Prüfung und resultatspezifische Weiterleitungen statt auf wiederholte Redirect- und Alert-Orchestrierung. |

---

### v2.7.48 — 25. März 2026 · Audit-Batch 130, Groups-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.48** | 🔴 fix | Admin/Users | **Gruppen-POST-Aktionen nutzen jetzt denselben kleinen Redirect-/Flash-/Dispatch-Rahmen**: `CMS/admin/groups.php` baut Redirects, Alerts und Aktionsauflösung für Save- und Delete-Aktionen nicht mehr mehrfach direkt im POST-Flow zusammen. |
| **2.7.48** | 🟠 perf | Admin/Users | **Änderungen an Gruppen-Aktionen bleiben zentraler wartbar**: Anpassungen an gemeinsamer Redirect-, Alert- oder Dispatch-Logik müssen nicht mehr in mehreren Zweigen parallel nachgezogen werden. |
| **2.7.48** | 🟡 refactor | Admin/Users | **Der Groups-Entry bleibt näher am eigentlichen Request-Flow**: der POST-Pfad konzentriert sich stärker auf Token-Prüfung und Aktionsauflösung statt auf wiederholte Redirect- und Alert-Orchestrierung. |

---

### v2.7.47 — 25. März 2026 · Audit-Batch 129, Firewall-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.47** | 🔴 fix | Admin/Security | **Firewall-POST-Aktionen nutzen jetzt denselben kleinen Redirect-/Flash-/Dispatch-Rahmen**: `CMS/admin/firewall.php` baut Redirects, Alerts und Aktionsauflösung für Settings- und Rule-Aktionen nicht mehr mehrfach direkt im POST-Flow zusammen. |
| **2.7.47** | 🟠 perf | Admin/Security | **Änderungen an Firewall-Aktionen bleiben zentraler wartbar**: Anpassungen an gemeinsamer Redirect-, Alert- oder Dispatch-Logik müssen nicht mehr in mehreren Zweigen parallel nachgezogen werden. |
| **2.7.47** | 🟡 refactor | Admin/Security | **Der Firewall-Entry bleibt näher am eigentlichen Request-Flow**: der POST-Pfad konzentriert sich stärker auf Supported-Action-/Token-Prüfung statt auf wiederholte Redirect- und Alert-Orchestrierung. |

---

### v2.7.46 — 25. März 2026 · Audit-Batch 128, Error-Report-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.46** | 🔴 fix | Admin/System | **Fehlerreport-POST-Aktionen nutzen jetzt denselben kleinen Redirect-/Flash-Rahmen**: `CMS/admin/error-report.php` baut Redirects und Alerts für Report-Erstellung und Token-Fehler nicht mehr mehrfach direkt im POST-Flow zusammen. |
| **2.7.46** | 🟠 perf | Admin/System | **Änderungen an Fehlerreport-Aktionen bleiben zentraler wartbar**: Anpassungen an gemeinsamer Redirect- oder Alert-Logik müssen nicht mehr in mehreren Zweigen parallel nachgezogen werden. |
| **2.7.46** | 🟡 refactor | Admin/System | **Der Error-Report-Entry bleibt näher am eigentlichen Request-Flow**: der POST-Pfad konzentriert sich stärker auf Redirect-Normalisierung und Report-Erstellung statt auf wiederholte Redirect- und Alert-Orchestrierung. |

---

### v2.7.45 — 25. März 2026 · Audit-Batch 127, Documentation-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.45** | 🔴 fix | Admin/System | **Dokumentations-POST-Aktionen nutzen jetzt denselben kleinen Alert-/Redirect-Rahmen**: `CMS/admin/documentation.php` baut Alert-Speicherung und Redirect-Aufbau für Doku-Sync-Aktionen nicht mehr mehrfach direkt im POST-Flow zusammen. |
| **2.7.45** | 🟠 perf | Admin/System | **Änderungen an Dokumentations-Aktionen bleiben zentraler wartbar**: Anpassungen an gemeinsamer Alert- oder Redirect-Logik müssen nicht mehr in mehreren Zweigen parallel nachgezogen werden. |
| **2.7.45** | 🟡 refactor | Admin/System | **Der Documentation-Entry bleibt näher am eigentlichen Request-Flow**: der POST-Pfad konzentriert sich stärker auf Token-Prüfung und Action-Dispatch statt auf wiederholte Alert- und Redirect-Orchestrierung. |

---

### v2.7.44 — 25. März 2026 · Audit-Batch 126, Backups-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.44** | 🔴 fix | Admin/System | **Backup-POST-Aktionen nutzen jetzt denselben kleinen Redirect-/Flash-Rahmen**: `CMS/admin/backups.php` baut Redirects und Session-Alerts für Full-, DB- und Delete-Aktionen nicht mehr mehrfach direkt im POST-Flow zusammen. |
| **2.7.44** | 🟠 perf | Admin/System | **Änderungen an Backup-Aktionen bleiben zentraler wartbar**: Anpassungen an gemeinsamer Redirect- oder Alert-Logik müssen nicht mehr in mehreren Zweigen parallel nachgezogen werden. |
| **2.7.44** | 🟡 refactor | Admin/System | **Der Backups-Entry bleibt näher am eigentlichen Request-Flow**: der POST-Pfad konzentriert sich stärker auf Token-Prüfung und Action-Handler statt auf wiederholte Session-Alert-Orchestrierung. |

---

### v2.7.43 — 25. März 2026 · Audit-Batch 125, AntiSpam-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.43** | 🔴 fix | Admin/Security | **AntiSpam-POST-Aktionen nutzen jetzt dieselben kleinen Redirect-/Flash-/Dispatch-Helfer**: `CMS/admin/antispam.php` baut Redirects, Alerts und Aktionsauflösung für Settings- und Blacklist-Aktionen nicht mehr mehrfach direkt im POST-Flow zusammen. |
| **2.7.43** | 🟠 perf | Admin/Security | **Änderungen an AntiSpam-Aktionen bleiben zentraler wartbar**: Anpassungen an gemeinsamer Redirect-, Alert- oder Dispatch-Logik müssen nicht mehr in mehreren Zweigen parallel nachgezogen werden. |
| **2.7.43** | 🟡 refactor | Admin/Security | **Der AntiSpam-Entry bleibt näher am eigentlichen Request-Flow**: der POST-Pfad konzentriert sich stärker auf Token-/Allowlist-Handling statt auf wiederholte Redirect- und Alert-Orchestrierung. |

---

### v2.7.42 — 25. März 2026 · Audit-Batch 124, 404-Monitor-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.42** | 🔴 fix | Admin/SEO | **404-Monitor-POST-Aktionen nutzen jetzt dieselben kleinen Redirect-/Flash-/Dispatch-Helfer**: `CMS/admin/not-found-monitor.php` baut Redirects, Alerts und Aktionsauflösung für Redirect-Speicherung und Log-Bereinigung nicht mehr mehrfach direkt im POST-Flow zusammen. |
| **2.7.42** | 🟠 perf | Admin/SEO | **Änderungen an 404-Monitor-Aktionen bleiben zentraler wartbar**: Anpassungen an gemeinsamer Redirect-, Alert- oder Dispatch-Logik müssen nicht mehr in mehreren Zweigen parallel nachgezogen werden. |
| **2.7.42** | 🟡 refactor | Admin/SEO | **Der 404-Monitor-Entry bleibt näher am eigentlichen Request-Flow**: der POST-Pfad konzentriert sich stärker auf Token-Prüfung und Resultatverarbeitung statt auf wiederholte Session-Alert-Orchestrierung. |

---

### v2.7.41 — 25. März 2026 · Audit-Batch 123, Design-Settings-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.41** | 🔴 fix | Admin/Themes | **Der Design-Settings-Entry nutzt jetzt denselben kleinen Redirect-Helper wie andere Alias-Entrys**: `CMS/admin/design-settings.php` kapselt Guard- und Weiterleitungslogik nicht mehr als rohe Header-Aufrufe direkt im Entry. |
| **2.7.41** | 🟠 perf | Admin/Themes | **Änderungen am Alias-Redirect bleiben zentraler wartbar**: Redirect-Verhalten Richtung Theme-Editor liegt sichtbar an einer Stelle und folgt dem gleichen Muster wie andere schlanke Admin-Entrys. |
| **2.7.41** | 🟡 refactor | Admin/Themes | **Der Design-Settings-Entry bleibt näher am eigentlichen Request-Flow**: der Einstieg konzentriert sich nur noch auf Admin-Guard und Redirect statt auf offene Header-/Exit-Strecken. |

---

### v2.7.40 — 25. März 2026 · Audit-Batch 122, Deletion-Requests-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.40** | 🔴 fix | Admin/Legal | **Der Deletion-Requests-Entry bildet jetzt nur noch seinen tatsächlichen Redirect-Zweck ab**: `CMS/admin/deletion-requests.php` schleppt keinen unerreichbaren POST-, Modul- und View-Altpfad mehr hinter einem sofortigen Redirect mit. |
| **2.7.40** | 🟠 perf | Admin/Legal | **Änderungen am Alias-Entry bleiben zentraler wartbar**: Redirect-Verhalten für Löschanträge liegt sichtbar an einer Stelle, statt verdeckt von totem Altcode überlagert zu werden. |
| **2.7.40** | 🟡 refactor | Admin/Legal | **Der Deletion-Requests-Entry bleibt näher am eigentlichen Request-Flow**: der Einstieg konzentriert sich nur noch auf Admin-Guard und Redirect statt auf nie erreichte DSGVO-Orchestrierung. |

---

### v2.7.39 — 25. März 2026 · Audit-Batch 121, Data-Requests-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.39** | 🔴 fix | Admin/Legal | **DSGVO-POST-Aktionen nutzen jetzt dieselben kleinen Alert-/Dispatch-Helfer**: `CMS/admin/data-requests.php` baut Alert-Normalisierung und Scope-Aktionsauflösung für Auskunfts- und Löschanträge nicht mehr mehrfach direkt im POST-Flow zusammen. |
| **2.7.39** | 🟠 perf | Admin/Legal | **Änderungen an DSGVO-Aktionen bleiben zentraler wartbar**: Anpassungen an gemeinsamer Alert- oder Scope-Dispatch-Logik müssen nicht mehr in mehreren Zweigen parallel nachgezogen werden. |
| **2.7.39** | 🟡 refactor | Admin/Legal | **Der Data-Requests-Entry bleibt näher am eigentlichen Request-Flow**: der POST-Pfad konzentriert sich stärker auf Token-/Scope-Handling statt auf wiederholte Scope-Verzweigung und Alert-Orchestrierung. |

---

### v2.7.38 — 25. März 2026 · Audit-Batch 120, Cookie-Manager-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.38** | 🔴 fix | Admin/Legal | **Cookie-POST-Aktionen nutzen jetzt dieselben kleinen Redirect-/Flash-/Dispatch-Helfer**: `CMS/admin/cookie-manager.php` baut Redirects, Session-Alerts und Aktionsauflösung für Save-, Delete-, Import- und Scan-Aktionen nicht mehr mehrfach direkt im POST-Flow zusammen. |
| **2.7.38** | 🟠 perf | Admin/Legal | **Änderungen an Cookie-Aktionen bleiben zentraler wartbar**: Anpassungen an gemeinsamer Redirect-, Alert- oder Dispatch-Logik müssen nicht mehr in mehreren Zweigen parallel nachgezogen werden. |
| **2.7.38** | 🟡 refactor | Admin/Legal | **Der Cookie-Manager-Entry bleibt näher am eigentlichen Request-Flow**: der POST-Pfad konzentriert sich stärker auf Token-/Allowlist-Handling statt auf wiederholte Redirect- und Alert-Orchestrierung. |

---

### v2.7.37 — 25. März 2026 · Audit-Batch 119, Comments-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.37** | 🔴 fix | Admin/Comments | **Kommentar-Aktionen nutzen jetzt dieselben kleinen Alert-Helfer**: `CMS/admin/comments.php` baut Session-Alerts für unbekannte Aktionen, ungültige CSRF-Tokens und verarbeitete Resultate nicht mehr mehrfach direkt im POST-Flow zusammen. |
| **2.7.37** | 🟠 perf | Admin/Comments | **Änderungen an Comment-Alerts bleiben zentraler wartbar**: Anpassungen an gemeinsamer Alert-Struktur müssen nicht mehr in mehreren Zweigen parallel nachgezogen werden. |
| **2.7.37** | 🟡 refactor | Admin/Comments | **Der Comments-Entry bleibt näher am eigentlichen Request-Flow**: der POST-Pfad konzentriert sich stärker auf Aktionsverarbeitung statt auf wiederholten Session-Alert-Aufbau. |

---

### v2.7.36 — 25. März 2026 · Audit-Batch 118, Packages-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.36** | 🔴 fix | Admin/Subscriptions | **Packages-POST-Aktionen nutzen jetzt dieselben kleinen Flash-/Redirect-Helfer**: `CMS/admin/packages.php` baut Session-Alerts und Redirect-Abgänge für Save-, Seed-, Delete-, Toggle- und Settings-Aktionen nicht mehr mehrfach direkt im POST-Flow zusammen. |
| **2.7.36** | 🟠 perf | Admin/Subscriptions | **Änderungen an Packages-Aktionen bleiben zentraler wartbar**: Anpassungen an gemeinsamer Alert- oder Redirect-Logik müssen nicht mehr in mehreren Zweigen parallel nachgezogen werden. |
| **2.7.36** | 🟡 refactor | Admin/Subscriptions | **Der Packages-Entry bleibt näher am eigentlichen Request-Flow**: der POST-Pfad konzentriert sich stärker auf Token-/Action-Handling statt auf wiederholte Session- und Redirect-Orchestrierung. |

---

### v2.7.35 — 25. März 2026 · Audit-Batch 117, Orders-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.35** | 🔴 fix | Admin/Subscriptions | **POST-Aktionen laufen jetzt über denselben kleinen Dispatch-Helfer**: `CMS/admin/orders.php` löst `assign_subscription`, `update_status` und `delete` nicht mehr als gestaffelten Inline-Block mit mehrfach ähnlichem Erfolgsablauf auf. |
| **2.7.35** | 🟠 perf | Admin/Subscriptions | **Änderungen an Orders-Aktionen bleiben zentraler wartbar**: Anpassungen an der Parameterübergabe oder Aktionsauflösung müssen nicht mehr in mehreren Switch-Zweigen parallel nachgezogen werden. |
| **2.7.35** | 🟡 refactor | Admin/Subscriptions | **Der Orders-Entry bleibt näher am eigentlichen Request-Flow**: der POST-Pfad konzentriert sich stärker auf Token-/Allowlist-Handling statt auf wiederholte Aktionsorchestrierung. |

---

### v2.7.34 — 25. März 2026 · Audit-Batch 116, Mail-Settings-View Button-Aktionen weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.34** | 🔴 fix | Admin/System | **Mail-Aktionsbuttons nutzen jetzt denselben kleinen Renderer**: `CMS/admin/views/system/mail-settings.php` baut wiederkehrende Submit-Buttons mit `name="action"` und variierenden Klassen/Attributen nicht mehr mehrfach direkt in Transport-, Azure-, Graph-, Log- und Queue-Bereichen zusammen. |
| **2.7.34** | 🟠 perf | Admin/System | **Änderungen an Aktionsbuttons bleiben zentraler wartbar**: Anpassungen an gemeinsamer Submit-Button-Struktur müssen nicht mehr in mehreren Mail-Settings-Bereichen parallel nachgezogen werden. |
| **2.7.34** | 🟡 refactor | Admin/System | **Die Mail-Settings-View bleibt näher an kleinen Render-Bausteinen**: die betroffenen Formulare konzentrieren sich stärker auf ihre Fachfelder statt auf wiederholtes Button-Markup. |

---

### v2.7.33 — 25. März 2026 · Audit-Batch 115, Dokumentations-View Listen weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.33** | 🔴 fix | Admin/System | **Dokumentlisten nutzen jetzt denselben kleinen Renderer**: `CMS/admin/views/system/documentation.php` rendert Schnellstart- und Bereichslisten nicht mehr mehrfach direkt über identische `foreach`-Blöcke mit demselben `is_array`-Guard. |
| **2.7.33** | 🟠 perf | Admin/System | **Änderungen an Dokumentlisten bleiben zentraler wartbar**: Anpassungen an gemeinsamer Listenlogik müssen nicht mehr in mehreren View-Bereichen parallel nachgezogen werden. |
| **2.7.33** | 🟡 refactor | Admin/System | **Die Dokumentations-View bleibt näher an kleinen Render-Bausteinen**: die betroffenen Karten konzentrieren sich stärker auf ihre Struktur statt auf wiederholte Listeniteration. |

---

### v2.7.32 — 25. März 2026 · Audit-Batch 114, Dokumentations-Sync-Service weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.32** | 🔴 fix | Admin/System | **Sync-Abschlusslogs nutzen jetzt denselben kleinen Ausführungs-Kontext-Helfer**: `CMS/admin/modules/system/DocumentationSyncService.php` baut `mode`- und `capabilities`-Kontext für Erfolgs- und Fehlerpfade nicht mehr mehrfach direkt in `finalizeSyncResult()` zusammen. |
| **2.7.32** | 🟠 perf | Admin/System | **Änderungen an Sync-Kontexten bleiben zentraler wartbar**: Anpassungen an gemeinsamen Abschluss-Metadaten müssen nicht mehr in mehreren Logging-Pfaden parallel nachgezogen werden. |
| **2.7.32** | 🟡 refactor | Admin/System | **Der Dokumentations-Sync-Service bleibt näher an kleinen Orchestrator-Bausteinen**: der Abschluss-Pfad konzentriert sich stärker auf Result-Verarbeitung statt auf wiederholten Kontextaufbau. |

---

### v2.7.31 — 25. März 2026 · Audit-Batch 113, Dokumentations-Downloader weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.31** | 🔴 fix | Admin/System | **Download-Logs nutzen jetzt denselben kleinen URL-Kontext-Helfer**: `CMS/admin/modules/system/DocumentationSyncDownloader.php` baut URL-basierte Kontextdaten für Response-, Validierungs- und Erfolgslogs nicht mehr mehrfach direkt in den jeweiligen Pfaden zusammen. |
| **2.7.31** | 🟠 perf | Admin/System | **Änderungen an Download-Kontexten bleiben zentraler wartbar**: Anpassungen an URL-bezogenen Log-Metadaten müssen nicht mehr in mehreren Downloader-Pfaden parallel nachgezogen werden. |
| **2.7.31** | 🟡 refactor | Admin/System | **Der Dokumentations-Downloader bleibt näher an kleinen Infrastruktur-Bausteinen**: die betroffenen Pfade konzentrieren sich stärker auf Download- und Validierungslogik statt auf wiederholten Kontextaufbau. |

---

### v2.7.30 — 25. März 2026 · Audit-Batch 112, Subscription-Settings-Modul weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.30** | 🔴 fix | Admin/Subscriptions | **Beide Einstellungs-Speicherpfade nutzen jetzt denselben kleinen Save-Helfer**: `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` bündelt Admin-Guard, Persistierung und Audit-Logging für allgemeine und paketbezogene Abo-Einstellungen nicht mehr doppelt direkt in beiden Save-Methoden. |
| **2.7.30** | 🟠 perf | Admin/Subscriptions | **Änderungen an Save-Orchestrierungen bleiben zentraler wartbar**: Anpassungen an gemeinsamem Persistier- oder Audit-Verhalten müssen nicht mehr in mehreren Settings-Pfaden parallel nachgezogen werden. |
| **2.7.30** | 🟡 refactor | Admin/Subscriptions | **Das Subscription-Settings-Modul bleibt näher an kleinen Orchestrator-Bausteinen**: die Save-Methoden konzentrieren sich stärker auf ihre Payloads statt auf wiederholte Erfolgsabläufe. |

---

### v2.7.29 — 25. März 2026 · Audit-Batch 111, Orders-View Formular-Kontexte weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.29** | 🔴 fix | Admin/Subscriptions | **Bestellformulare nutzen jetzt denselben kleinen Kontext-Renderer**: `CMS/admin/views/subscriptions/orders.php` rendert die wiederkehrenden Hidden-Felder für `csrf_token`, `action` und optional `id` nicht mehr mehrfach direkt in Statuswechsel-, Delete- und Zuweisungsformularen aus. |
| **2.7.29** | 🟠 perf | Admin/Subscriptions | **Änderungen an Formular-Kontexten bleiben zentraler wartbar**: Anpassungen an gemeinsamen Hidden-Feldern müssen nicht mehr über mehrere Bestellaktionspfade hinweg synchron gehalten werden. |
| **2.7.29** | 🟡 refactor | Admin/Subscriptions | **Die Orders-View bleibt näher an kleinen Formular-Bausteinen**: die betroffenen Aktionen konzentrieren sich stärker auf ihren eigentlichen Zweck statt auf wiederholtes Kontext-Markup. |

---

### v2.7.28 — 25. März 2026 · Audit-Batch 110, Orders-Modul weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.28** | 🔴 fix | Admin/Subscriptions | **Status- und Delete-Mutationen nutzen jetzt denselben kleinen Guard-Pfad**: `CMS/admin/modules/subscriptions/OrdersModule.php` bündelt die wiederkehrenden Vorbedingungen für ID- und Snapshot-Prüfung nicht mehr doppelt direkt in beiden Mutationspfaden. |
| **2.7.28** | 🟠 perf | Admin/Subscriptions | **Audit-Kontexte für Bestellmutationen bleiben zentraler wartbar**: gemeinsame Maskierung und Kontextaufbereitung für Order-Nummer und Kundenmail müssen nicht mehr in mehreren Mutationszweigen separat gepflegt werden. |
| **2.7.28** | 🟡 refactor | Admin/Subscriptions | **Das Orders-Modul bleibt näher an kleinen Orchestrator-Bausteinen**: Status- und Delete-Pfade konzentrieren sich stärker auf ihre eigentliche Mutation statt auf wiederholten Guard- und Audit-Kontext-Aufbau. |

---

### v2.7.27 — 25. März 2026 · Audit-Batch 109, Mail-Settings-Formular-Kontexte weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.27** | 🔴 fix | Admin/System | **Mail-Formulare nutzen jetzt denselben kleinen Kontext-Renderer**: `CMS/admin/views/system/mail-settings.php` rendert die wiederkehrenden Hidden-Felder für `csrf_token` und `tab` nicht mehr mehrfach direkt in Transport-, Azure-, Graph-, Log- und Queue-Formularen aus. |
| **2.7.27** | 🟠 perf | Admin/System | **Pflege von Formular-Kontexten bleibt zentraler wartbar**: Änderungen an CSRF- oder Tab-Kontexten müssen nicht mehr an mehreren Stellen im Template synchron gehalten werden. |
| **2.7.27** | 🟡 refactor | Admin/System | **Die Mail-Settings-View bleibt näher an kleinen View-Bausteinen**: die betroffenen Formulare konzentrieren sich stärker auf ihre eigentlichen Felder statt auf wiederholtes Hidden-Input-Markup. |

---

### v2.7.26 — 25. März 2026 · Audit-Batch 108, Settings-Config-Writer gegen globale Runtime-Fehler gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.26** | 🔴 fix | Admin/Settings | **Generierte `config/app.php`-Dateien werden vor dem Live-Swap validiert**: `CMS/admin/modules/settings/SettingsModule.php` prüft die erzeugte Runtime-Konfiguration jetzt auf erwartete Kernfragmente und gültige PHP-Syntax, bevor sie atomar geschrieben wird. |
| **2.7.26** | 🟠 perf | Admin/Settings | **Fehlerhafte Settings-Generierungen brechen früher und kontrollierter ab**: der Config-Writer schaltet keine kaputte Konfiguration mehr live und reduziert damit teure Debug-/Rollback-Schleifen nach Permalink- oder allgemeinen Einstellungen. |
| **2.7.26** | 🟡 refactor | Admin/Settings | **Der Settings-Writer bleibt enger an seiner Installer-Vorlage**: Log-Pfad- und HTTPS-/HSTS-Defaults laufen konsistenter mit der Installer-Konfiguration zusammen, wodurch Runtime- und Installationspfad weniger auseinanderdriften. |

---

### v2.7.25 — 25. März 2026 · Audit-Batch 107, Dokumentations-Downloader weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.25** | 🔴 fix | Admin/System | **Response-Fehlerpfade laufen jetzt konsistenter über einen kleinen Downloader-Helfer**: `CMS/admin/modules/system/DocumentationSyncDownloader.php` behandelt Download- und Persistenzfehler nicht mehr separat mit ähnlichem Cleanup- und Failure-Abgang. |
| **2.7.25** | 🟠 perf | Admin/System | **Änderungen an Downloader-Fehlern bleiben zentraler wartbar**: der gemeinsame Response-Failure-Helfer reduziert Duplikate bei Cleanup, Logging und Result-Erzeugung. |
| **2.7.25** | 🟡 refactor | Admin/System | **Der Dokumentations-Downloader bleibt näher an kleinen Failure-Bausteinen**: Remote- und Persistenzpfade konzentrieren sich stärker auf ihre eigentliche Aufgabe statt auf wiederholte Failure-Abgänge. |

---

### v2.7.24 — 25. März 2026 · Audit-Batch 106, Dokumentations-Sync-Service weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.24** | 🔴 fix | Admin/System | **Capability-Fehlerpfade laufen jetzt konsistenter über einen kleinen Service-Helfer**: `CMS/admin/modules/system/DocumentationSyncService.php` behandelt unavailable- und invalid-capabilities nicht mehr separat mit identischem Kontextaufbau im Orchestrator. |
| **2.7.24** | 🟠 perf | Admin/System | **Änderungen an Capability-Fehlern bleiben zentraler wartbar**: der gemeinsame Failure-Helfer reduziert Kontext-Duplikate im Sync-Service und erleichtert spätere Anpassungen an Logging oder Fehlermeldungen. |
| **2.7.24** | 🟡 refactor | Admin/System | **Der Dokumentations-Sync-Service bleibt näher an kleinen Failure-Bausteinen**: der Orchestrator konzentriert sich stärker auf die Sync-Auswahl statt auf wiederholte Failure-Kontexte. |

---

### v2.7.23 — 25. März 2026 · Audit-Batch 105, Mail-Settings-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.23** | 🔴 fix | Admin/System | **KPI-Kartenzeilen laufen jetzt konsistenter über einen kleinen View-Renderer**: `CMS/admin/views/system/mail-settings.php` behandelt Logs- und Queue-Metriken nicht mehr als zwei fast identische Kartenreihen direkt im Template. |
| **2.7.23** | 🟠 perf | Admin/System | **Änderungen an Mail-Metriken bleiben zentraler wartbar**: der gemeinsame Kartenreihen-Renderer reduziert Markup-Duplikate in Logs- und Queue-Bereich und erleichtert spätere KPI-Anpassungen. |
| **2.7.23** | 🟡 refactor | Admin/System | **Die Mail-Settings-View bleibt näher an kleinen Render-Bausteinen**: die Tab-Bereiche konzentrieren sich stärker auf ihre Inhalte statt auf wiederholte KPI-Wrapper. |

---

### v2.7.22 — 25. März 2026 · Audit-Batch 104, Dokumentations-Modul weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.22** | 🔴 fix | Admin/System | **Lese-Vorbedingungen laufen jetzt konsistenter über einen kleinen Modul-Guard**: `CMS/admin/modules/system/DocumentationModule.php` behandelt Zugriff, Repository-Layout und DOC-Verfügbarkeit nicht mehr direkt als gestaffelten Inline-Block im Read-Pfad. |
| **2.7.22** | 🟠 perf | Admin/System | **Änderungen am Dokument-Ladepfad bleiben zentraler wartbar**: der gemeinsame View-Guard reduziert Kopierlogik im Modul und erleichtert spätere Anpassungen an Vorbedingungen oder Fehlermeldungen. |
| **2.7.22** | 🟡 refactor | Admin/System | **Das Dokumentations-Modul bleibt näher an kleinen Orchestrator-Bausteinen**: der Read-Pfad konzentriert sich stärker auf Katalog- und Render-Aufbau statt auf Vorbedingungsprüfungen. |

---

### v2.7.21 — 25. März 2026 · Audit-Batch 103, Dokumentations-Ansicht weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.21** | 🔴 fix | Admin/System | **Die ausgewählte Dokumentenkarte läuft jetzt konsistenter über einen kleinen Renderer**: `CMS/admin/views/system/documentation.php` behandelt Excerpt, Quellenhinweis, Leerzustand und CSV-Hinweis nicht mehr direkt als gewachsenen Inhaltsblock im Hauptlayout. |
| **2.7.21** | 🟠 perf | Admin/System | **Änderungen an der Dokumentenansicht bleiben zentraler wartbar**: der gemeinsame Content-Renderer reduziert Markup-Duplikate im rechten Dokumentenpanel und erleichtert spätere Anpassungen an Hinweise oder Zustände. |
| **2.7.21** | 🟡 refactor | Admin/System | **Die Dokumentations-View bleibt näher an kleinen Render-Bausteinen**: das Hauptlayout konzentriert sich stärker auf die Kartenstruktur statt auf den kompletten Inhaltszustand. |

---

### v2.7.20 — 25. März 2026 · Audit-Batch 102, Bestellübersicht weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.20** | 🔴 fix | Admin/Subscriptions | **Das Bestell-Aktionsmenü läuft jetzt konsistenter über einen kleinen Renderer**: `CMS/admin/views/subscriptions/orders.php` behandelt Statuswechsel, Paketzuweisung und Löschaktion nicht mehr direkt als gewachsenen Dropdown-Block in jeder Tabellenzeile. |
| **2.7.20** | 🟠 perf | Admin/Subscriptions | **Änderungen an Bestellaktionen bleiben zentraler wartbar**: der gemeinsame Menü-Renderer reduziert Markup-Duplikate in der Orders-Tabelle und erleichtert spätere Anpassungen an Aktionen oder Attribute. |
| **2.7.20** | 🟡 refactor | Admin/Subscriptions | **Die Orders-View bleibt näher an kleinen View-Bausteinen**: die Tabellenzeile konzentriert sich stärker auf Daten statt auf das komplette Dropdown-Markup. |

---

### v2.7.19 — 25. März 2026 · Audit-Batch 101, Dokumentations-Downloader weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.19** | 🔴 fix | Admin/System | **Persistenz-Fehlerpfade laufen jetzt konsistenter über einen kleinen Downloader-Helfer**: `CMS/admin/modules/system/DocumentationSyncDownloader.php` behandelt Schreib- und Hash-Fehler für ZIP-Artefakte nicht mehr jeweils separat über ähnliche Cleanup- und Failure-Blöcke. |
| **2.7.19** | 🟠 perf | Admin/System | **Änderungen an Archiv-Fehlerpfaden bleiben zentraler wartbar**: der gemeinsame Persistenz-Helfer reduziert Kopierlogik im Downloader und erleichtert spätere Anpassungen an Logging, Cleanup oder Failure-Metadaten. |
| **2.7.19** | 🟡 refactor | Admin/System | **Der Dokumentations-Downloader bleibt näher an kleinen Infrastruktur-Bausteinen**: Persistenz-Fehler sind jetzt sichtbar standardisiert und halten den Archivpfad kompakter. |

---

### v2.7.18 — 25. März 2026 · Audit-Batch 100, Dokumentations-Sync-Service weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.18** | 🔴 fix | Admin/System | **Capability-Verzweigungen laufen jetzt konsistenter über einen kleinen Service-Helfer**: `CMS/admin/modules/system/DocumentationSyncService.php` baut Verfügbarkeits-, Git- und GitHub-ZIP-Auswahl im Sync-Einstieg nicht mehr direkt als gestaffelten Inline-Block auf. |
| **2.7.18** | 🟠 perf | Admin/System | **Änderungen an der Sync-Auswahl bleiben zentraler wartbar**: der gemeinsame Helfer reduziert Kopierlogik im Orchestrator und erleichtert spätere Anpassungen an Capability- oder Moduspfade. |
| **2.7.18** | 🟡 refactor | Admin/System | **Der Dokumentations-Sync-Service bleibt näher an kleinen Infrastruktur-Bausteinen**: Capability-basierte Sync-Auswahl ist jetzt sichtbar standardisiert und hält den Einstieg kompakter. |

---

### v2.7.17 — 25. März 2026 · Audit-Batch 099, Mail-Settings-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.17** | 🔴 fix | Admin/System | **Status-Kartenköpfe laufen jetzt konsistenter über einen kleinen View-Helfer**: `CMS/admin/views/system/mail-settings.php` baut Azure- und Graph-Header mit Status-Badge nicht mehr zweimal leicht variiert direkt im Template zusammen. |
| **2.7.17** | 🟠 perf | Admin/System | **Änderungen an Konfigurationskarten bleiben zentraler wartbar**: der gemeinsame Status-Header reduziert Kopierlogik in der Mail-UI und erleichtert spätere Anpassungen an Titel oder Badge-Zustände. |
| **2.7.17** | 🟡 refactor | Admin/System | **Die Mail-Settings-View bleibt näher an kleinen wiederverwendbaren Render-Bausteinen**: Status-Kartenköpfe sind jetzt sichtbar standardisiert und halten das Template kompakter. |

---

### v2.7.16 — 25. März 2026 · Audit-Batch 098, Dokumentations-Modul weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.16** | 🔴 fix | Admin/System | **Sync-Vorbedingungen laufen jetzt konsistenter über einen kleinen Modul-Helfer**: `CMS/admin/modules/system/DocumentationModule.php` verteilt Zugriffs- und Layout-Fehler im Sync-Einstieg nicht mehr über leicht doppelte Failure-Pfade, sondern bündelt sie vorab in einem Guard. |
| **2.7.16** | 🟠 perf | Admin/System | **Änderungen am Sync-Einstieg bleiben zentraler wartbar**: der gemeinsame Guard reduziert Kopierlogik im Orchestrator und erleichtert spätere Anpassungen an Zugriff- oder Layout-Voraussetzungen. |
| **2.7.16** | 🟡 refactor | Admin/System | **Das Dokumentations-Modul bleibt näher an kleinen Infrastruktur-Bausteinen**: Sync-Gates sind jetzt sichtbar standardisiert und halten den Einstieg kompakter. |

---

### v2.7.15 — 25. März 2026 · Audit-Batch 097, Dokumentations-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.15** | 🔴 fix | Admin/System | **Accordion-Bereiche laufen jetzt konsistenter über einen kleinen View-Helfer**: `CMS/admin/views/system/documentation.php` baut Header-, Collapse- und Dokumentlisten für Bereichs-Accordions nicht mehr mehrfach direkt im Template zusammen. |
| **2.7.15** | 🟠 perf | Admin/System | **Bereichsänderungen bleiben zentraler wartbar**: der gemeinsame Accordion-Renderer reduziert Kopierlogik in der Dokumentations-UI und erleichtert spätere Anpassungen an Struktur, Beschriftung oder Collapse-Markup. |
| **2.7.15** | 🟡 refactor | Admin/System | **Die Dokumentations-View bleibt näher an kleinen wiederverwendbaren Render-Bausteinen**: Bereichs-Accordions sind jetzt sichtbar standardisiert und halten das Template kompakter. |

---

### v2.7.14 — 25. März 2026 · Audit-Batch 096, Orders-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.14** | 🔴 fix | Admin/Subscriptions | **Statuswechsel- und Delete-Formulare laufen jetzt konsistenter über kleine View-Helfer**: `CMS/admin/views/subscriptions/orders.php` baut Dropdown-Mutationen und das versteckte Delete-Formular nicht mehr mehrfach über leicht variierte Hidden-Field-Strukturen auf. |
| **2.7.14** | 🟠 perf | Admin/Subscriptions | **Aktionsänderungen bleiben zentraler wartbar**: die neuen lokalen Renderer reduzieren Kopierlogik in den Order-Aktionen und erleichtern spätere Anpassungen an Hidden-Felder oder Formularattribute. |
| **2.7.14** | 🟡 refactor | Admin/Subscriptions | **Die Orders-View bleibt näher an kleinen wiederverwendbaren Formular-Bausteinen**: Status- und Delete-Aktionen sind jetzt sichtbar standardisiert und halten das Template kompakter. |

---

### v2.7.13 — 25. März 2026 · Audit-Batch 095, Dokumentations-Downloader weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.13** | 🔴 fix | Admin/System | **Response-basierte Failure-Resultate laufen jetzt konsistenter über einen kleinen Downloader-Builder**: `CMS/admin/modules/system/DocumentationSyncDownloader.php` baut Download-Fehler aus HTTP-Status, Content-Type und Byte-Zahlen nicht mehr mehrfach über leicht variierte `failureResult(...)`-Aufrufe zusammen. |
| **2.7.13** | 🟠 perf | Admin/System | **Fehlerpfad-Änderungen bleiben zentraler wartbar**: der gemeinsame Response-Failure-Builder reduziert Kopierlogik in Download-, Persistenz- und Validierungsfehlern und erleichtert spätere Result-Anpassungen. |
| **2.7.13** | 🟡 refactor | Admin/System | **Der Dokumentations-Downloader bleibt näher an kleinen Result-Helfern**: Response-getriebene Fehlerpfade sind jetzt sichtbar standardisiert und halten den Lifecycle kompakter. |

---

### v2.7.12 — 25. März 2026 · Audit-Batch 094, Dokumentations-Sync-Service weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.12** | 🔴 fix | Admin/System | **Konfigurations-Failure-Arrays laufen jetzt konsistenter über einen kleinen Service-Builder**: `CMS/admin/modules/system/DocumentationSyncService.php` baut Validierungsfehler für Repo-, DOC-, Git- und Integritätsprüfung nicht mehr mehrfach über leicht variierte Inline-Arrays auf. |
| **2.7.12** | 🟠 perf | Admin/System | **Validierungsänderungen bleiben zentraler wartbar**: der gemeinsame Failure-Builder reduziert Kopierlogik im Konfigurationspfad und erleichtert spätere Kontext- oder Meldungsanpassungen. |
| **2.7.12** | 🟡 refactor | Admin/System | **Der Doku-Sync-Service bleibt näher an kleinen Result-/Failure-Helfern**: Konfigurationsfehler sind jetzt sichtbar standardisiert und halten die Validierung kompakter. |

---

### v2.7.11 — 25. März 2026 · Audit-Batch 093, Mail-Settings-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.11** | 🔴 fix | Admin/System | **Einfache Kartenköpfe laufen jetzt konsistenter über einen kleinen View-Helfer**: `CMS/admin/views/system/mail-settings.php` baut Transport-, Runtime-, Queue- und Worker-Karten nicht mehr mehrfach über identische Header-Blöcke direkt im Template. |
| **2.7.11** | 🟠 perf | Admin/System | **Header-Änderungen bleiben zentraler wartbar**: der gemeinsame Kartenkopf-Renderer reduziert Kopierlogik in der Mail-UI und erleichtert spätere Titelanpassungen. |
| **2.7.11** | 🟡 refactor | Admin/System | **Die Mail-Settings-View bleibt näher an kleinen wiederverwendbaren Render-Bausteinen**: einfache Kartenüberschriften sind jetzt sichtbar standardisiert und halten das Template kompakter. |

---

### v2.7.10 — 25. März 2026 · Audit-Batch 092, Dokumentations-Modul weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.10** | 🔴 fix | Admin/System | **Repository-Layout-Warnings laufen jetzt konsistenter über einen kleinen Modul-Helfer**: `CMS/admin/modules/system/DocumentationModule.php` schreibt ungültige Repo-/DOC-Layout-Hinweise nicht mehr über mehrfach leicht variierte Warning-Blöcke direkt in `hasValidRepositoryLayout()`. |
| **2.7.10** | 🟠 perf | Admin/System | **Layout-Prüfungen bleiben zentraler wartbar**: der gemeinsame Warning-Helfer reduziert Kopierlogik in der Repository-Validierung und erleichtert spätere Kontext- oder Log-Anpassungen. |
| **2.7.10** | 🟡 refactor | Admin/System | **Das Dokumentations-Modul bleibt näher an kleinen Infrastruktur-Helfern**: Layout-Fehlerpfade sind jetzt sichtbar standardisiert und halten die Orchestrator-Methode kompakter. |

---

### v2.7.09 — 25. März 2026 · Audit-Batch 091, Dokumentations-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.09** | 🔴 fix | Admin/System | **Kartenköpfe laufen jetzt konsistenter über einen kleinen View-Helfer**: `CMS/admin/views/system/documentation.php` baut Schnellstart-, Bereichs- und Dokumentkarten nicht mehr über mehrfach leicht variierte Header-Blöcke zusammen. |
| **2.7.09** | 🟠 perf | Admin/System | **Header-Änderungen bleiben zentraler wartbar**: der gemeinsame Kartenkopf-Renderer reduziert Kopierlogik im Dokumentations-UI und erleichtert spätere Titel-/Untertitel-Anpassungen. |
| **2.7.09** | 🟡 refactor | Admin/System | **Die Dokumentations-View bleibt näher an kleinen wiederverwendbaren Render-Bausteinen**: Kartenüberschriften sind jetzt sichtbar standardisiert und halten das Template kompakter. |

---

### v2.7.08 — 25. März 2026 · Audit-Batch 090, Orders-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.08** | 🔴 fix | Admin/Subscriptions | **Select-Felder im Zuweisungsmodal laufen jetzt konsistenter über einen kleinen View-Helfer**: `CMS/admin/views/subscriptions/orders.php` baut Benutzer-, Paket- und Abrechnungsintervall-Auswahl nicht mehr als drei leicht variierte Inline-Blöcke auf. |
| **2.7.08** | 🟠 perf | Admin/Subscriptions | **Modal-Optionen bleiben zentraler wartbar**: vorbereitete Optionslisten und der gemeinsame Select-Renderer reduzieren Kopierlogik im Zuweisungsdialog und erleichtern spätere Feldanpassungen. |
| **2.7.08** | 🟡 refactor | Admin/Subscriptions | **Die Orders-View bleibt näher an kleinen wiederverwendbaren Render-Bausteinen**: das Assignment-Modal trägt weniger Template-Duplikate und bleibt klarer für weitere Partial- oder Builder-Schritte. |

---

### v2.7.07 — 25. März 2026 · Audit-Batch 089, Dokumentations-Downloader weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.07** | 🔴 fix | Admin/System | **Audit- und Channel-Logs laufen jetzt konsistenter über einen kleinen Downloader-Helfer**: `CMS/admin/modules/system/DocumentationSyncDownloader.php` schreibt Erfolgs- und Fehlerlogs nicht mehr über zwei fast identische Methodenpfade mit doppeltem Logger-/Audit-Aufbau. |
| **2.7.07** | 🟠 perf | Admin/System | **Logging-Änderungen bleiben zentraler wartbar**: der gemeinsame Downloader-Logger reduziert Kopierlogik zwischen `logFailure()` und `logSuccess()` und erleichtert spätere Channel- oder Severity-Anpassungen. |
| **2.7.07** | 🟡 refactor | Admin/System | **Der Dokumentations-Downloader bleibt näher an kleinen Infrastruktur-Helfern**: wiederkehrende Log-Ausleitung ist jetzt sichtbar standardisiert, während Download-Validierung und Persistenzpfade ihren Fachkontext behalten. |

---

### v2.7.06 — 25. März 2026 · Audit-Batch 088, Dokumentations-Sync-Service weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.06** | 🔴 fix | Admin/System | **Audit- und Channel-Logs laufen jetzt konsistenter über einen kleinen Service-Helfer**: `CMS/admin/modules/system/DocumentationSyncService.php` schreibt Erfolgs- und Fehlerlogs nicht mehr über zwei fast identische Methodenpfade mit doppeltem Logger-/Audit-Aufbau. |
| **2.7.06** | 🟠 perf | Admin/System | **Logging-Änderungen bleiben zentraler wartbar**: der gemeinsame Dokumentations-Logger reduziert Kopierlogik zwischen `logFailure()` und `logSuccess()` und erleichtert spätere Channel- oder Severity-Anpassungen. |
| **2.7.06** | 🟡 refactor | Admin/System | **Der Doku-Sync-Service bleibt näher an kleinen Infrastruktur-Helfern**: wiederkehrende Log-Ausleitung ist jetzt sichtbar standardisiert, während Erfolgs- und Fehlerpfade ihren Fachkontext behalten. |

---

### v2.7.05 — 25. März 2026 · Audit-Batch 087, Mail-Settings-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.05** | 🔴 fix | Admin/System | **Secret-Statushinweise laufen jetzt konsistenter über einen kleinen View-Helfer**: `CMS/admin/views/system/mail-settings.php` rendert den „Aktuell gespeichert“-Hinweis und die Reset-Checkbox für Transport-, Azure- und Graph-Secrets nicht mehr dreifach leicht variiert inline. |
| **2.7.05** | 🟠 perf | Admin/System | **Formularfragmente bleiben leichter wartbar**: der neue lokale Renderer reduziert Kopierlogik in den drei Secret-Bereichen und macht spätere Beschriftungs- oder Zustandsänderungen zentraler. |
| **2.7.05** | 🟡 refactor | Admin/System | **Die Mail-Settings-View bleibt näher an kleinen wiederverwendbaren Render-Bausteinen**: wiederkehrende Secret-Hinweise und Löschoptionen sind jetzt sichtbar standardisiert. |

---

### v2.7.04 — 25. März 2026 · Audit-Batch 086, Dokumentations-Modul weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.04** | 🔴 fix | Admin/System | **Throwable-Warnings laufen jetzt konsistenter über einen kleinen Modul-Helfer**: `CMS/admin/modules/system/DocumentationModule.php` protokolliert Lade- und Sync-Ausnahmen nicht mehr mehrfach über leicht variierte Inline-Logger-Blöcke, sondern nutzt einen gemeinsamen Warning-Helper. |
| **2.7.04** | 🟠 perf | Admin/System | **Default-Payloads für ausgewählte Dokumente werden zentral vorbereitet**: der Orchestrator baut den leeren Read-Zustand nicht mehr ad hoc in `buildSelectedDocumentPayload()`, sondern nutzt einen kleinen Default-Payload-Helfer für stabilere Read-Pfade. |
| **2.7.04** | 🟡 refactor | Admin/System | **Das Dokumentations-Modul bleibt noch näher an seinen Verträgen**: kleine Hilfsmethoden für Throwable-Logging und Initial-Payload reduzieren weitere Orchestrator-Duplikate und erleichtern die nächsten Service- oder Result-Schritte. |

---

### v2.7.03 — 25. März 2026 · Audit-Batch 085, Dokumentations-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.03** | 🔴 fix | Admin/System | **Alert-Blöcke laufen jetzt konsistenter über einen kleinen View-Helfer**: `CMS/admin/views/system/documentation.php` baut Fehler-, Sync- und Hinweisboxen nicht mehr mehrfach mit leicht variierten Inline-Blöcken zusammen, sondern nutzt einen gemeinsamen Alert-Renderer. |
| **2.7.03** | 🟠 perf | Admin/System | **Bereichs-Einleitungen und Quellhinweise werden aus vorbereiteten Renderern/Texten aufgebaut**: Accordion-Intro und Source-Hinweis müssen nicht mehr mehrfach direkt im Template zusammengesetzt werden, wodurch der Informationspfad kompakter bleibt. |
| **2.7.03** | 🟡 refactor | Admin/System | **Die Dokumentations-View bleibt noch näher am eigentlichen Rendern**: kleine Renderer für Alert- und Introblöcke reduzieren weitere Template-Duplikate und erleichtern die nächsten Partial- oder Builder-Schritte. |

---

### v2.7.02 — 25. März 2026 · Audit-Batch 084, Orders-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.02** | 🔴 fix | Admin/Subscriptions | **Status-Badges und Sekundärzeilen laufen jetzt konsistenter über kleine Render-Helfer**: `CMS/admin/views/subscriptions/orders.php` baut Kunden-, Assignment- und Statusanzeige nicht mehr mehrfach mit leicht variierten Inline-Blöcken zusammen, sondern nutzt gemeinsame Renderer für Badge- und Primär-/Sekundärtexte. |
| **2.7.02** | 🟠 perf | Admin/Subscriptions | **Billing-Cycle-Optionen werden aus vorbereiteten Listen gerendert**: das Zuweisungs-Modal hält Monats-, Jahres- und Lifetime-Auswahl nicht mehr als feste Einzeloptionen im Markup verstreut vor, wodurch der Formularpfad kompakter und konsistenter bleibt. |
| **2.7.02** | 🟡 refactor | Admin/Subscriptions | **Die Orders-View bleibt noch näher am eigentlichen Rendern**: kleine Renderer für Badge- und Textgruppen reduzieren weitere Template-Duplikate und erleichtern nächste Partial- oder Builder-Schritte in Tabellen- und Modalbereichen. |

---

### v2.7.01 — 25. März 2026 · Audit-Batch 083, Dokumentations-Downloader weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.01** | 🔴 fix | Admin/System | **Failure-Resultate laufen jetzt konsistenter über einen kleinen Downloader-Helfer**: `CMS/admin/modules/system/DocumentationSyncDownloader.php` baut Download-, Persistenz- und Hash-Fehler nicht mehr an mehreren Stellen separat als Result-Objekte auf, sondern nutzt einen fokussierten Failure-Builder. |
| **2.7.01** | 🟠 perf | Admin/System | **Remote-Fehlerpfade tragen weniger verteilte Result-Erzeugung**: Validierungs-, HTTP- und Schreibfehler laufen über denselben Result-Helfer, wodurch der Download-Lifecycle weniger wiederholte Objektkonstruktion im Fehlerpfad mit sich herumträgt. |
| **2.7.01** | 🟡 refactor | Admin/System | **Der Downloader bleibt näher an seinem Result-Vertrag**: kleine Failure-Helper reduzieren losen Result-Mix und erleichtern weitere Payload- oder Lifecycle-Aufspaltungen im Downloadpfad. |

---

### v2.7.00 — 25. März 2026 · Audit-Batch 082, Dokumentations-Sync-Service weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.7.00** | 🔴 fix | Admin/System | **Failure-Fallbacks laufen jetzt konsistenter über einen kleinen Service-Helfer**: `CMS/admin/modules/system/DocumentationSyncService.php` baut Konfigurations- und Fail-Responses nicht mehr mehrfach direkt über lose Ergebnis-Arrays auf, sondern nutzt einen fokussierten Failure-Builder für `DocumentationSyncServiceResult`. |
| **2.7.00** | 🟠 perf | Admin/System | **Finalize- und Konfigpfade nutzen denselben Result-Wrapper**: der Orchestrator konvertiert Result-Arrays zentral in einen Service-Result-Vertrag, wodurch Erfolg- und Fehlerpfade weniger doppelte Array-zu-Objekt-Übergänge mit sich herumtragen. |
| **2.7.00** | 🟡 refactor | Admin/System | **Der Doku-Sync-Orchestrator bleibt näher an seinen Verträgen**: kleine Helper für Result-Erzeugung reduzieren losen Array-Mix und erleichtern weitere Objekt- oder Result-Aufspaltungen im Sync-Service. |

---

### v2.6.99 — 25. März 2026 · Audit-Batch 081, Mail-Settings-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.99** | 🔴 fix | Admin/System | **Status-Badges laufen jetzt konsistenter über einen kleinen View-Helfer**: `CMS/admin/views/system/mail-settings.php` rendert Transport-, OAuth2-, Log- und Queue-Status nicht mehr über mehrfach ausgeschriebene Badge-Fragmente, sondern über einen gemeinsamen Badge-Renderer. |
| **2.6.99** | 🟠 perf | Admin/System | **Empty States und Hinweis-Karten werden wiederverwendet aufgebaut**: leere Tabellenzeilen sowie die seitlichen Azure-/Graph-Hinweisboxen laufen über kleine Render-Helfer, wodurch Logs-, Queue- und Sidebar-Markup weniger doppelte UI-Struktur tragen. |
| **2.6.99** | 🟡 refactor | Admin/System | **Die Mail-Settings-View bleibt näher am eigentlichen Rendern**: Badge-, Empty-State- und Info-Card-Helfer reduzieren Template-Duplikate und erleichtern weitere Partial- oder Builder-Schritte. |

---

### v2.6.98 — 25. März 2026 · Audit-Batch 080, Dokumentations-Modul weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.98** | 🔴 fix | Admin/System | **Sync-Resultate werden im Doku-Modul jetzt konsistenter gebaut**: `CMS/admin/modules/system/DocumentationModule.php` bündelt Sanitizing und Fehlererzeugung für Sync-Antworten über fokussierte Helfer, statt dieselbe Result-Logik mehrfach direkt im Modul zu verteilen. |
| **2.6.98** | 🟠 perf | Admin/System | **Ausgewählte Dokumente laufen über klarere Payload-Helfer**: Pfadauflösung und Dokument-Rendering sind in kleine Methoden ausgelagert, wodurch der Read-Pfad für ausgewählte Dateien weniger Inline-Verzweigungen mit sich herumträgt. |
| **2.6.98** | 🟡 refactor | Admin/System | **Der Doku-Orchestrator bleibt näher an seinen Verträgen**: Hilfsmethoden für Payload- und Failure-Aufbau reduzieren losen Lifecycle-Mix und erleichtern weitere Service-Aufspaltungen im Modul. |

---

### v2.6.97 — 25. März 2026 · Audit-Batch 079, Dokumentations-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.97** | 🔴 fix | Admin/System | **Dokumentations-KPI-Karten laufen jetzt über einen kleinen Render-Helfer**: `CMS/admin/views/system/documentation.php` baut Dokument-, Bereichs-, Quellen- und Sync-Karten aus einer vorbereiteten Kartenliste auf, statt dieselben Card-Blöcke mehrfach direkt im Markup auszuschreiben. |
| **2.6.97** | 🟠 perf | Admin/System | **Schnellstart- und Bereichslinks nutzen denselben Dokument-Renderer**: wiederkehrende Listen-Items werden über einen lokalen Helfer gerendert, wodurch Featured-Docs und Bereichslisten weniger doppelte UI-Struktur im Renderpfad tragen. |
| **2.6.97** | 🟡 refactor | Admin/System | **Die Dokumentations-View bleibt näher am eigentlichen Rendern**: kleine Render-Helfer für Metric-Cards und Dokument-Links reduzieren Template-Duplikate und erleichtern weitere Partial- oder Builder-Schritte. |

---

### v2.6.96 — 25. März 2026 · Audit-Batch 078, Orders-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.96** | 🔴 fix | Admin/Subscriptions | **KPI-Karten und Leerzustände laufen jetzt über kleine View-Helfer**: `CMS/admin/views/subscriptions/orders.php` rendert Kennzahlen und leere Tabellenzeilen aus vorbereiteten Datenlisten statt dieselben Card- und Empty-State-Blöcke mehrfach direkt im Markup auszuschreiben. |
| **2.6.96** | 🟠 perf | Admin/Subscriptions | **Statuswechsel und Assignment-Anzeige nutzen vorbereitete Zwischenwerte**: verfügbare Übergänge, JSON-Payloads und Laufzeittexte werden lokal gebündelt, wodurch Dropdown- und Tabellenpfade weniger wiederholte UI-Logik im Renderpfad tragen. |
| **2.6.96** | 🟡 refactor | Admin/Subscriptions | **Die Orders-View bleibt noch näher am eigentlichen Rendern**: kleine Template-Helfer für Metrics, Empty States und Assignment-Felder reduzieren Template-Duplikate und erleichtern weitere Partial- oder Builder-Schritte. |

---

### v2.6.95 — 25. März 2026 · Audit-Batch 077, Dokumentations-Downloader weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.95** | 🔴 fix | Admin/System | **Download-Resultate laufen jetzt über benannte Erfolgs-/Fehlerfabriken**: `CMS/admin/modules/system/DocumentationSyncDownloader.php` baut Fehl- und Erfolgsergebnisse über `DocumentationDownloadResult::failure()` und `::success()` auf, statt wiederholt dieselbe Parameterkette direkt in den Lifecycle zu schreiben. |
| **2.6.95** | 🟠 perf | Admin/System | **Validierte ZIP-Antworten bleiben als kleines Payload-DTO zusammen**: der Downloader reicht Body und Content-Type nach der Prüfung als `DocumentationDownloadPayload` weiter, wodurch Persistenz- und Hash-Pfade weniger lose Response-Fragmente mit sich herumtragen. |
| **2.6.95** | 🟡 refactor | Admin/System | **Der Downloader-Lifecycle spricht schärfere Zwischenverträge**: Result-Factory und Payload-DTO halten Validierung, Persistenz und Fehlerpfade expliziter getrennt und erleichtern weitere Zerlegung im Remote-/Filesystem-Pfad. |

---

### v2.6.94 — 25. März 2026 · Audit-Batch 076, Dokumentations-Sync-Service weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.94** | 🔴 fix | Admin/System | **Capability-Abfragen laufen jetzt über echte Objektmethoden**: `CMS/admin/modules/system/DocumentationSyncService.php` nutzt `canSync()`, `hasGit()` und `hasGithubZip()` direkt am Capability-Vertrag, statt diese Informationen sofort wieder über lose Array-Schlüssel auszulesen. |
| **2.6.94** | 🟠 perf | Admin/System | **Logging und Finalisierung übernehmen vorbereitete Capability-Kontexte**: der Orchestrator reicht Capability-Daten über `toLogContext()` weiter, wodurch Erfolgs-, Fehler- und Unavailable-Pfade weniger eigene Array-Normalisierung mit sich herumtragen. |
| **2.6.94** | 🟡 refactor | Admin/System | **Environment und Sync-Service teilen einen schärferen Objektvertrag**: `DocumentationSyncCapabilities` bietet jetzt Getter plus kleinen Log-Kontext-Helfer, sodass Capability-Normalisierung und Sync-Dispatch klarer voneinander getrennt bleiben. |

---

### v2.6.93 — 25. März 2026 · Audit-Batch 075, Mail-Settings-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.93** | 🔴 fix | Admin/System | **Mail-KPI-Karten laufen jetzt über eine kleine View-Schleife**: `CMS/admin/views/system/mail-settings.php` rendert Log- und Queue-Metriken aus vorbereiteten Kartenlisten statt dieselben Card-Blöcke mehrfach direkt im Markup auszuschreiben. |
| **2.6.93** | 🟠 perf | Admin/System | **Readonly-Felder und Worker-Status werden wiederverwendet aufgebaut**: kleine Helfer bündeln Readonly-Eingaben und den Last-Run-Text, wodurch die View weniger wiederholte UI-Struktur im Renderpfad trägt. |
| **2.6.93** | 🟡 refactor | Admin/System | **Die Mail-Settings-View bleibt näher am eigentlichen Rendern**: vorbereitete Karten-, Feld- und Statusdaten reduzieren Template-Duplikate und erleichtern weitere Partial- oder Builder-Schritte. |

---

### v2.6.92 — 25. März 2026 · Audit-Batch 074, Mail-Entry weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.92** | 🔴 fix | Admin/System | **Mail-Aktionen laufen jetzt über eine zentrale Action-Map**: `CMS/admin/mail-settings.php` bündelt den POST-Dispatch in einem kleinen Handler-Register statt die Modulmethoden direkt im Hauptfluss per langem `match` zu verdrahten. |
| **2.6.92** | 🟠 perf | Admin/System | **Session-Alerts werden über einen kleinen Pull-Helfer übernommen**: der Wrapper räumt die Session konsistenter auf und hält den Entry-Fluss kompakter. |
| **2.6.92** | 🟡 refactor | Admin/System | **Der Mail-Entry bleibt näher am eigentlichen Request-Flow**: Action-Map und Alert-Pull-Helfer reduzieren verstreute Dispatch- und Session-Logik und erleichtern weitere Wrapper-Anpassungen. |

---

### v2.6.91 — 25. März 2026 · Audit-Batch 073, Dokumentations-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.91** | 🔴 fix | Admin/System | **Dokument- und Bereichslinks laufen konsistenter über kleine View-Helfer**: `CMS/admin/views/system/documentation.php` bereitet Admin-URLs, GitHub-Links und Bereichs-Slugs jetzt zentral auf, statt diese Werte mehrfach inline im Listen- und Accordion-Markup zusammenzubauen. |
| **2.6.91** | 🟠 perf | Admin/System | **Titel-, Pfad-, Extension- und Count-Ableitungen werden wiederverwendet**: Dokument- und Bereichsmetadaten laufen über lokale Helfer, wodurch die View weniger wiederholte UI-Logik im Renderpfad trägt. |
| **2.6.91** | 🟡 refactor | Admin/System | **Die Dokumentations-View bleibt näher am eigentlichen Rendern**: kleine Helfer sammeln Listen- und Bereichsmetadaten zentral ein und reduzieren Template-Duplikate für weitere Partial- oder Builder-Schritte. |

---

### v2.6.90 — 25. März 2026 · Audit-Batch 072, Orders-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.90** | 🔴 fix | Admin/Subscriptions | **Bestell- und Kundenlabels laufen konsistenter über kleine View-Helfer**: `CMS/admin/views/subscriptions/orders.php` bereitet Bestellnummer, Kundenname und Kundenmail jetzt zentral auf, statt diese Werte mehrfach direkt im Tabellen-Markup zusammenzusetzen. |
| **2.6.90** | 🟠 perf | Admin/Subscriptions | **Filter- und Select-Optionen nutzen wiederverwendete Template-Helfer**: Filterbutton-Klassen sowie Benutzer- und Paketlabels werden zentral aufgebaut, wodurch die View weniger wiederholte UI-Logik im Renderpfad trägt. |
| **2.6.90** | 🟡 refactor | Admin/Subscriptions | **Die Orders-View bleibt näher am eigentlichen Rendern**: kleine lokale Helfer sammeln Anzeige- und Form-Labels ein und reduzieren Template-Duplikate für weitere Partial- oder Modal-Schritte. |

---

### v2.6.89 — 25. März 2026 · Audit-Batch 071, Dokumentations-Downloader weiter zerlegt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.89** | 🔴 fix | Admin/System | **Download-Fehlpfade laufen jetzt über einen gemeinsamen Reject-/Failure-Flow**: `CMS/admin/modules/system/DocumentationSyncDownloader.php` bündelt Host-, Ziel-, Verzeichnis- und HTTP-Fehler über kleine Helfer, statt alle Rückgaben direkt im großen `downloadFile()`-Block zu mischen. |
| **2.6.89** | 🟠 perf | Admin/System | **Payload-Prüfung und Persistenz sind getrennt lesbar**: Response-Validierung, Datei-Persistenz und Cleanup verteilen sich jetzt auf fokussierte Helfer, wodurch der Downloader-Lifecycle klarer und gezielter weiter optimierbar bleibt. |
| **2.6.89** | 🟡 refactor | Admin/System | **Der Download-Result-Vertrag ist expliziter geworden**: `DocumentationDownloadResult` kapselt Metadaten jetzt über Methoden wie `isSuccess()`, `bytes()` und `sha256()`, und `DocumentationGithubZipSync.php` liest diese Werte nicht mehr als lose Public-Properties aus. |

---

### v2.6.88 — 25. März 2026 · Audit-Batch 070, Dokumentations-Sync-Service-Verträge geschärft

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.88** | 🔴 fix | Admin/System | **Der Doku-Sync-Orchestrator liefert jetzt einen kleinen Service-Result-Vertrag**: `CMS/admin/modules/system/DocumentationSyncService.php` kapselt Sync-Ergebnisse über `DocumentationSyncServiceResult`, statt lose Erfolgs-/Fehler-Arrays über mehrere Ebenen durchzureichen. |
| **2.6.88** | 🟠 perf | Admin/System | **Normalisierte Sync-Capabilities bleiben als Objekt erhalten**: der Service arbeitet intern mit `DocumentationSyncCapabilities` weiter, statt das Environment-Ergebnis sofort in lose Arrays zu zerlegen und wieder aufzubauen. |
| **2.6.88** | 🟡 refactor | Admin/System | **Dokumentationsmodul und Sync-Service teilen schärfere Grenzen**: `CMS/admin/modules/system/DocumentationModule.php` konsumiert die Service-Objekte gezielt über `->toArray()`, wodurch Orchestrator und Modul klarer voneinander getrennt bleiben. |

---

### v2.6.87 — 25. März 2026 · Audit-Batch 069, Mail-Settings-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.87** | 🔴 fix | Admin/System | **Status- und Secret-Anzeigen laufen konsistenter über kleine View-Helfer**: `CMS/admin/views/system/mail-settings.php` nutzt vorbereitete Badge-/Label-Helfer für Konfigurationsstände statt verstreuter Inline-Entscheidungen. |
| **2.6.87** | 🟠 perf | Admin/System | **Tabs, Selects und Checkboxen werden über wiederverwendete Template-Helfer bewertet**: wiederkehrende `active`-/`selected`-/`checked`-Logik muss nicht mehr mehrfach direkt im Markup aufgelöst werden. |
| **2.6.87** | 🟡 refactor | Admin/System | **Die Mail-Settings-View bleibt näher am eigentlichen Rendern**: kleine lokale Helfer sammeln UI-Zustände zentral ein und reduzieren Template-Duplikate für die nächsten UI-Schritte. |

---

### v2.6.86 — 25. März 2026 · Audit-Batch 068, Dokumentationsmodul-Verträge geschärft

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.86** | 🔴 fix | Admin/System | **Sync-Antworten des Doku-Moduls laufen jetzt über einen kleinen Result-Vertrag**: `CMS/admin/modules/system/DocumentationModule.php` liefert `syncDocsFromRepository()` als `DocumentationSyncActionResult`, und `CMS/admin/documentation.php` leitet Flash-Typ und Meldung konsistent daraus ab. |
| **2.6.86** | 🟠 perf | Admin/System | **Die Auswahl eines aktiven Dokuments wird über einen fokussierten Payload-Builder zusammengesetzt**: Render-HTML, Rohinhalt und CSV-Status entstehen jetzt in `buildSelectedDocumentPayload()` statt als größerer Inline-Block in `getData()`. |
| **2.6.86** | 🟡 refactor | Admin/System | **Das Dokumentationsmodul spricht explizitere Read-/Write-Grenzen**: `DocumentationViewData` kapselt den View-Vertrag, während Sanitizing und Fehlerrückgaben für den Sync über kleine Helfer vereinheitlicht werden. |

---

### v2.6.85 — 25. März 2026 · Audit-Batch 067, Dokumentations-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.85** | 🔴 fix | Admin/System | **Alert-Kontext wird auch in der Doku-View defensiver und einheitlicher gespiegelt**: `CMS/admin/views/system/documentation.php` übergibt Flash-Daten jetzt konsistent als Array an den gemeinsamen Alert-Partial statt über einen losen Optional-Block. |
| **2.6.85** | 🟠 perf | Admin/System | **Aktive Dokument- und Sync-Zustände laufen über kleine Template-Helfer**: aktive Links, Bereichsstatus, Sync-Alert-Klasse und Default-Pfadlabel müssen nicht mehr mehrfach inline ausgewertet werden. |
| **2.6.85** | 🟡 refactor | Admin/System | **Die Dokumentations-View trägt weniger verstreute Zustandslogik**: vorbereitete Helfer halten das Template näher am eigentlichen Rendern und leichter lesbar für weitere UI-Schritte. |

---

### v2.6.84 — 25. März 2026 · Audit-Batch 066, Subscription-Settings-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.84** | 🔴 fix | Admin/Subscriptions | **Alert-Kontext wird defensiver und einheitlicher in die View gespiegelt**: `CMS/admin/views/subscriptions/settings.php` übernimmt Flash-Daten jetzt konsistent als Array-Kontext für den gemeinsamen Alert-Partial statt über einen losen Optional-Block. |
| **2.6.84** | 🟠 perf | Admin/Subscriptions | **Checkbox- und Select-Zustände laufen über kleine Template-Helfer**: wiederkehrende `checked`-/`selected`-Bedingungen und vorbereitete Notice-Werte müssen nicht mehr mehrfach inline im Markup ausgewertet werden. |
| **2.6.84** | 🟡 refactor | Admin/Subscriptions | **Die Settings-View trägt weniger Inline-Entscheidungslogik**: vorbereitete Default-/Notice-Werte halten das Template dümmer und näher am eigentlichen Rendern. |

---

### v2.6.83 — 25. März 2026 · Audit-Batch 065, GitHub-ZIP-Sync weiter zerlegt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.83** | 🔴 fix | Admin/System | **ZIP-Sync-Arbeitsverzeichnisse laufen jetzt über einen kleinen Workspace-Vertrag**: `CMS/admin/modules/system/DocumentationGithubZipSync.php` bündelt ZIP-, Extract-, Staging- und Backup-Pfade über `DocumentationGithubZipWorkspace`, statt diese quer durch den großen Sync-Block mitzuschleppen. |
| **2.6.83** | 🟠 perf | Admin/System | **Archiv-, Staging- und Aktivierungsschritte sind separat gekapselt**: Extraktion, Snapshot-Staging, Aktivierung und Cleanup liegen jetzt in fokussierten Helfern, wodurch der ZIP-Sync-Lebenszyklus klarer lesbar und gezielter weiter optimierbar wird. |
| **2.6.83** | 🟡 refactor | Admin/System | **Der GitHub-ZIP-Sync trägt weniger Lifecycle-Mix im Top-Level**: `sync()` konzentriert sich jetzt stärker auf den Ablauf, während Detailarbeit in kleine Methoden ausgelagert wurde. |

---

### v2.6.82 — 25. März 2026 · Audit-Batch 064, Mail-Settings-Verträge geschärft

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.82** | 🔴 fix | Admin/System | **Mail-Admin-Mutationen sprechen jetzt einen einheitlicheren Result-Vertrag**: `CMS/admin/modules/system/MailSettingsModule.php` liefert Save-, Test-, Queue- und Cache-Aktionen über `MailSettingsActionResult`, während `CMS/admin/mail-settings.php` Flash-Meldungen zentral daraus ableitet. |
| **2.6.82** | 🟠 perf | Admin/System | **Read-Pfade sind sauberer in kleine Datenbausteine zerlegt**: Transport-, Azure-, Graph- und Queue-Stats werden im Modul über fokussierte Builder zusammengesetzt, statt als großer Inline-Sammelblock in `getData()` zu wohnen. |
| **2.6.82** | 🟡 refactor | Admin/System | **Mail-Settings nutzen jetzt ein kleines View-DTO statt losem Array-Mix**: `MailSettingsViewData` hält den View-Vertrag explizit, sodass Wrapper und Modul weniger implizite Schlüsselannahmen teilen müssen. |

---

### v2.6.81 — 25. März 2026 · Audit-Batch 063, Doku-Sync-Environment weiter entkoppelt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.81** | 🔴 fix | Admin/System | **Doku-Sync-Kommandos sprechen jetzt einen schärferen Shell-Vertrag**: `DocumentationSyncEnvironment.php` liefert Shell-Ausführungen über `DocumentationShellCommandResult`, und `DocumentationGitSync.php` arbeitet damit statt mit losen `output`-/`exitCode`-Arrays. |
| **2.6.81** | 🟠 perf | Admin/System | **Capability-Auflösung ist klarer von den Konsumenten getrennt**: `DocumentationSyncCapabilities` bündelt Git-/ZIP-Modi in einem kleinen Objekt, das `DocumentationSyncService.php` gezielt normalisiert weiterverarbeitet. |
| **2.6.81** | 🟡 refactor | Admin/System | **Shell-/Capability-Layer wurden weiter entkoppelt**: `DocumentationSyncEnvironment.php` kapselt seine Read-Modelle jetzt expliziter, wodurch Doku-Sync-Aufrufer weniger implizite Array-Details kennen müssen. |

---

### v2.6.80 — 25. März 2026 · Audit-Batch 062, Subscription-Settings weiter zerlegt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.80** | 🔴 fix | Admin/Subscriptions | **Subscription-Settings sprechen jetzt einen einheitlicheren Result-Vertrag**: `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` liefert Save-Antworten über ein kleines `SubscriptionSettingsActionResult`, während `subscription-settings.php` und `packages.php` Flash-Meldungen daraus konsistent ableiten. |
| **2.6.80** | 🟠 perf | Admin/Subscriptions | **General- und Package-Settings werden über fokussierte Payload-Helfer gebaut**: wiederkehrende ID- und Range-Normalisierung ist aus den großen Save-Methoden herausgezogen und damit leichter weiter zu optimieren. |
| **2.6.80** | 🟡 refactor | Admin/Subscriptions | **Read-Pfade nutzen jetzt ein kleines View-DTO statt losen Array-Mix**: `SubscriptionSettingsViewData` bündelt Settings-, Plan- und Seitenlisten, wodurch Modul- und Entry-Grenzen klarer bleiben. |

---

### v2.6.79 — 25. März 2026 · Audit-Batch 061, Orders-Modul weiter entknotet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.79** | 🔴 fix | Admin/Subscriptions | **Orders-Mutationen sprechen jetzt einen schärferen Fehlervertrag**: `CMS/admin/modules/subscriptions/OrdersModule.php` liefert Zuweisung, Statuswechsel und Löschung über ein kleines `OrdersActionResult`, während `CMS/admin/orders.php` Flash-Meldungen zentral aus genau diesem Result ableitet. |
| **2.6.79** | 🟠 perf | Admin/Subscriptions | **Listen- und Statistik-Ladevorgänge wurden aus dem großen Sammelblock herausgezogen**: fokussierte Fetch-/Stats-Helfer für Orders, Assignments, Plans und Users machen den Datenpfad lesbarer und leichter weiter zu optimieren. |
| **2.6.79** | 🟡 refactor | Admin/Subscriptions | **Dashboard-Daten kommen jetzt über ein kleines DTO statt über losen Array-Mix zurück**: `OrdersDashboardData` bündelt den Read-Pfad, während Modulzugriff und Fehlerbehandlung enger an einen konsistenten Modulkontrakt gezogen wurden. |

---

### v2.6.78 — 25. März 2026 · Audit-Batch 060, Orders-View weiter standardisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.78** | 🔴 fix | Admin/Subscriptions | **Orders-View nutzt jetzt den gemeinsamen Flash-Alert-Standard**: `CMS/admin/views/subscriptions/orders.php` bindet die vorhandene Alert-Partial ein und übernimmt Session-/UI-Meldungen nicht länger über einen eigenen Inline-Alert-Block. |
| **2.6.78** | 🟠 perf | Admin/Subscriptions | **Wiederkehrende Status-, Datums- und Betragsformatierung liegt jetzt lokal gebündelt vor**: kleine Helper für Status-Metadaten und Ausgabeformate reduzieren Template-Duplikate und halten die Orders-Tabelle lesbarer. |
| **2.6.78** | 🟡 refactor | Admin/Subscriptions | **Inline-Handler wurden in datengetriebene Aktionen gezogen**: Assign-/Delete-Schaltflächen arbeiten nun über `data-*`-Attribute und ein zentrales Script statt über verteilte `onclick`-Fragmente pro Button. |

---

### v2.6.75 — 25. März 2026 · Audit-Batch 057, Doku-Sync-Environment enger gezogen

### v2.6.77 — 25. März 2026 · Audit-Batch 059, Doku-Downloader weiter entkoppelt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.77** | 🔴 fix | Admin/System | **Downloader prüft Response-Header und Dateiintegrität jetzt konsequenter**: `CMS/admin/modules/system/DocumentationSyncDownloader.php` validiert `content-length` und Content-Type enger, schreibt nur konsistente ZIP-Antworten weg und erzeugt direkt eine SHA-256-Checksumme für den weiteren Sync-Pfad. |
| **2.6.77** | 🔴 security | Admin/System | **GitHub-ZIP-Sync vertraut nicht mehr blind auf das gespeicherte Artefakt**: `CMS/admin/modules/system/DocumentationGithubZipSync.php` verifiziert heruntergeladene ZIP-Dateien zusätzlich gegen Größe und Hash des Downloader-Ergebnisses und blockiert inkonsistente Download-Artefakte vor dem Entpacken. |
| **2.6.77** | 🟡 refactor | Admin/System | **Download-Ergebnis aus Array-Mix in kleines DTO gezogen**: `DocumentationDownloadResult` bündelt Status, Content-Type, Bytes und Hash, sodass Downloader- und ZIP-Sync-Pfade weniger lose Rückgabe-Arrays und implizite Nachprüfungen mit sich herumschleppen. |

---

### v2.6.76 — 25. März 2026 · Audit-Batch 058, Mail-Settings-Wrapper & View vereinheitlicht

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.76** | 🔴 fix | Admin/System | **Mail-Settings-Entry dispatcht POST-Aktionen jetzt über kleine Standard-Helper**: `CMS/admin/mail-settings.php` nutzt eine explizite Tab-/Action-Allowlist, vereinheitlicht Flash + Redirect und übernimmt Session-Alerts nur noch defensiv als Array. |
| **2.6.76** | 🔴 security | Admin/System | **Mail-Settings-View folgt dem gemeinsamen Flash- und Statusmuster**: `CMS/admin/views/system/mail-settings.php` rendert Meldungen über den zentralen Flash-Partial, hält Tab-Definitionen lokal gebündelt und kapselt Queue-Status-Badges über einen kleinen Helper statt losem Inline-Mix. |
| **2.6.76** | 🟡 refactor | Admin/System | **Mail-UI-Kontrakt bleibt enger am Admin-Standard**: API-/Tab-Konstanten und wiederkehrende View-Helfer liegen jetzt zentral im Template-Kontext, wodurch weniger implizite Sonderlogik zwischen Wrapper und View verteilt bleibt. |

---

### v2.6.75 — 25. März 2026 · Audit-Batch 057, Doku-Sync-Environment enger gezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.75** | 🔴 security | Admin/System | **Doku-Sync-Environment akzeptiert nur noch erwartete Git-Kommandos**: `CMS/admin/modules/system/DocumentationSyncEnvironment.php` blockiert jetzt Shell-Aufrufe außerhalb definierter Git-Subcommands sowie auffällige Kommando-Payloads mit Redirect-/Pipe-Spielereien deutlich früher. |
| **2.6.75** | 🔴 fix | Admin/System | **Repository-Root wird vor Capability- und Command-Pfaden früher validiert**: ungültige oder symlinkartige Repo-Roots laufen nicht mehr halb in Capability- oder Git-Pfade hinein, sondern werden kontrolliert als nicht nutzbare Umgebung behandelt. |
| **2.6.75** | 🟡 refactor | Admin/System | **Command-Sanitizing und Root-Normalisierung zentralisiert**: die Environment-Schicht bündelt Command-Längenlimit, Root-Normalisierung und Allowlist-Prüfung nun in kleinen Helpern statt losem Blindvertrauen auf Übergabestrings. |

---

### v2.6.74 — 25. März 2026 · Audit-Batch 056, Subscription-Settings-Wrapper vereinheitlicht

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.74** | 🔴 fix | Admin/Subscriptions | **Subscription-Settings-Entry akzeptiert nur noch die erwartete Mutation**: `CMS/admin/subscription-settings.php` nutzt jetzt eine explizite Action-Allowlist und behandelt CSRF-/Aktionsfehler konsistent per Flash + Redirect statt mit losem POST-Sonderpfad. |
| **2.6.74** | 🔴 security | Admin/Subscriptions | **Flash-State wird defensiver übernommen**: Session-Alerts werden nur noch als Array akzeptiert und nicht mehr blind aus der Session in den View-Kontext gespiegelt. |
| **2.6.74** | 🟡 refactor | Admin/Subscriptions | **Settings-View folgt dem gemeinsamen Alert-Partial**: `CMS/admin/views/subscriptions/settings.php` nutzt jetzt den bestehenden Flash-Alert-Baustein und sendet die Mutation explizit als `save_settings`, statt Wrapper-Logik implizit zu erraten. |

---

### v2.6.73 — 25. März 2026 · Audit-Batch 055, Orders-Admin restriktiver gemacht

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.73** | 🔴 fix | Admin/Subscriptions | **Orders-Entry akzeptiert nur noch bekannte Mutationen**: `CMS/admin/orders.php` dispatcht POST-Aktionen jetzt über eine explizite Allowlist, normalisiert Statusfilter serverseitig und behandelt CSRF-/Aktionsfehler konsistent per Flash + Redirect statt mit losem Wrapper-Verhalten. |
| **2.6.73** | 🔴 security | Admin/Subscriptions | **Bestell-Mutationen prüfen Status, Existenz und Kontext enger**: `CMS/admin/modules/subscriptions/OrdersModule.php` validiert Billing-/Statuswerte zentral, bricht Statuswechsel und Löschungen bei fehlenden Bestellungen sauber ab und schreibt nur noch maskierte Bestell-/Mailkontexte ins Audit-Log. |
| **2.6.73** | 🟡 refactor | Admin/Subscriptions | **Orders-Modul nutzt gemeinsame Limits und Helper statt losem Array-Mix**: Status-/Billing-Normalisierung, Snapshot-Reads und kompaktere Listenlimits reduzieren Dupplikate und halten die Bestellverwaltung besser auf Linie. |

---

### v2.6.72 — 25. März 2026 · Audit-Batch 054, GitHub-ZIP-Sync nachgeschärft

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.72** | 🔴 security | Admin/System | **GitHub-ZIP-Quellen bleiben jetzt enger auf saubere Archive beschränkt**: `CMS/admin/modules/system/DocumentationGithubZipSync.php` akzeptiert keine ZIP-URLs mehr mit Query-, Fragment- oder Credential-Anteilen und prüft die geladene Archivdatei lokal zusätzlich auf sichere Dateiform und Größe. |
| **2.6.72** | 🔴 fix | Admin/System | **Rollback-Reste werden kontrollierter aufgeräumt**: nach erfolgreich wiederhergestelltem `/DOC`-Stand löscht der ZIP-Sync verbliebene Backup-Verzeichnisse gezielter, statt unnötige Alt-Artefakte im Repo-Root liegen zu lassen. |
| **2.6.72** | 🟡 refactor | Admin/System | **Logpfade werden kompakter relativ zum Repo-Root ausgegeben**: Pfadkontexte zeigen weniger absolute Serverdetails und bleiben für Doku-Sync-Logs trotzdem nachvollziehbar. |

---

### v2.6.71 — 25. März 2026 · Audit-Batch 053, Backup-Service enger gezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.71** | 🔴 fix | Core/Backups | **Backup-Zielpfade werden jetzt realpath-basiert gegen den Backup-Root geprüft**: `CMS/core/Services/BackupService.php` akzeptiert Ziel- und Unterverzeichnisse nicht mehr nur per String-Präfix, sondern normalisiert bestehende und neue Pfade über ihren aufgelösten Root-Kontext. |
| **2.6.71** | 🟠 perf | Core/Backups | **Datenbank-Dumps laufen speicherschonender über Tabellenzeilen**: der Dump-Pfad iteriert Tabelleninhalte jetzt zeilenweise statt jede Tabelle per `fetchAll()` vollständig in den Speicher zu ziehen. |
| **2.6.71** | 🔴 security | Core/Backups | **REST-S3-Uploads wurden enger begrenzt**: Uploads akzeptieren nur noch lesbare Dateien innerhalb des Backup-Roots, blockieren auffällige Endpoint-/Bucket-Werte und laden keine übergroßen Backup-Dateien mehr blind komplett in den Request-Pfad. |

---

### v2.6.70 — 25. März 2026 · Audit-Batch 052, Mail-Admin-Operationen bereinigt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.70** | 🔴 fix | Admin/System | **Mail-Admin-Aktionen fangen Unterservice-Ausnahmen konsistenter ab**: `CMS/admin/modules/system/MailSettingsModule.php` kapselt Queue-Läufe, Testmails, Graph-Tests sowie Cache-/Log-Clears jetzt sauber über generische Fehlerpfade, statt sich auf implizit störungsfreie Unterservices zu verlassen. |
| **2.6.70** | 🔴 security | Admin/System | **Unterservice-Rückgaben werden vor der UI-Nutzung sanitisiert**: Test-, Queue- und Graph-Antworten werden auf kompakte, UI-taugliche Message-/Error-Felder reduziert, damit keine ausufernden oder künftig detailreicheren Service-Payloads ungebremst in den Admin-Flow rutschen. |
| **2.6.70** | 🟡 refactor | Admin/System | **Queue-Save-Pfad auditierbarer gemacht**: Queue-Konfiguration und optionale Cron-Token-Rotation laufen nun über einen gemeinsamen Guard-/Try-Catch-Pfad und protokollieren die Token-Neuerstellung explizit im Audit-Kontext mit. |

---

### v2.6.69 — 25. März 2026 · Audit-Batch 051, Doku-Sync-Orchestrator serialisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.69** | 🔴 fix | Admin/System | **Doku-Sync verlangt jetzt intern explizit Admin-Rechte**: `CMS/admin/modules/system/DocumentationSyncService.php` verlässt sich nicht mehr nur auf den äußeren Wrapper, sondern blockiert direkte Service-Aufrufe ohne Admin-Kontext selbstständig. |
| **2.6.69** | 🔴 security | Admin/System | **Parallele Doku-Syncs werden zentral abgefangen**: Git- und ZIP-basierte Läufe teilen sich jetzt ein gemeinsames Lockfile im Orchestrator, sodass gleichzeitige Sync-Starts nicht mehr gegeneinander arbeiten oder denselben `/DOC`-Baum parallel anfassen. |
| **2.6.69** | 🟡 refactor | Admin/System | **Capabilities berücksichtigen Fehlkonfigurationen früher**: Der Service meldet inkonsistente Repo-/DOC-/ZIP-/Integritätsprofile bereits im Statuspfad als „nicht verfügbar“, statt der Oberfläche trotz kaputter Sync-Konfiguration noch einen scheinbar nutzbaren Modus anzuzeigen. |

---

### v2.6.68 — 25. März 2026 · Audit-Batch 050, Paket-Admin restriktiver gemacht

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.68** | 🔴 fix | Admin/Subscriptions | **Paket-Entry akzeptiert nur noch bekannte Aktionen**: `CMS/admin/packages.php` prüft POST-Aktionen jetzt per Allowlist, leitet CSRF-/Aktionsfehler konsistent per Redirect + Flash zurück und vermeidet lose Wrapper-Sonderpfade. |
| **2.6.68** | 🔴 security | Admin/Subscriptions | **Paket-Mutationen validieren Slugs und Zugriffe restriktiver**: `CMS/admin/modules/subscriptions/PackagesModule.php` prüft Admin-Zugriff intern, erzwingt valide/unique Slugs und gibt bei Save-/Delete-/Toggle-Fehlern keine rohen Exception-Texte mehr an die UI weiter. |
| **2.6.68** | 🟡 refactor | Admin/Subscriptions | **Paket-Änderungen werden sauberer auditiert**: Erstellen, Aktualisieren, Löschen, Aktivieren und Standard-Seed-Läufe schreiben jetzt strukturierte Audit-Ereignisse statt nur lose Rückgabewerte zu liefern. |

---

### v2.6.67 — 25. März 2026 · Audit-Batch 049, Documentation-Katalog defensiver gemacht

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.67** | 🔴 fix | Admin/System | **Doku-Dateien werden kontrollierter gelesen**: `CMS/admin/modules/system/DocumentationCatalog.php` begrenzt Preview- und Vollreads serverseitig auf feste Maximalgrößen und kappt übergroße Dokumente für die Admin-Ansicht kontrolliert statt ungebremst komplette Dateien einzulesen. |
| **2.6.67** | 🔴 security | Admin/System | **Docs-Root- und Symlink-Grenzen nachgezogen**: der Katalog liest nur noch echte Dateien innerhalb des `/DOC`-Roots, überspringt Symlinks im rekursiven Scan und loggt Dateipfade kompakter relativ statt mit rohen absoluten Serverpfaden. |
| **2.6.67** | 🟠 perf | Admin/System | **Metadaten-Scanning mit weniger I/O**: Titel/Excerpts werden nur noch aus begrenzten Preview-Reads aufgebaut, sodass der Doku-Katalog beim Section-Scan weniger unnötige Datei-Last erzeugt. |

---

### v2.6.66 — 25. März 2026 · Audit-Batch 048, Documentation-Wrapper vereinheitlicht

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.66** | 🔴 fix | Admin/System | **Doku-Entry normalisiert Dokumentpfade jetzt defensiver**: `CMS/admin/documentation.php` begrenzt `doc`-Parameter auf erwartete Markdown-/CSV-Ziele, verwirft Traversal-artige Segmente und nutzt dieselbe normalisierte Auswahl für Redirect und Render-Aufruf. |
| **2.6.66** | 🔴 security | Admin/System | **POST-Dispatch und Alert-State bleiben enger am Admin-Standard**: unbekannte Aktionen laufen über einen generischen Fallback; Session-Alerts werden nur noch als Array übernommen und nicht lose direkt gerendert. |
| **2.6.66** | 🟡 refactor | Admin/System | **Doku-View nutzt den gemeinsamen Flash-Alert-Partial**: `CMS/admin/views/system/documentation.php` folgt jetzt dem etablierten Admin-Alert-Muster statt eigener Inline-Alert-Ausgabe. |

---

### v2.6.65 — 25. März 2026 · Audit-Batch 047, Backup-Flows vereinheitlicht

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.65** | 🔴 fix | Admin/System | **Backup-Entry und DB-Backups greifen jetzt sauber ineinander**: `CMS/admin/backups.php` dispatcht bekannte POST-Aktionen einheitlich; `CMS/admin/modules/system/BackupsModule.php` erzeugt reine Datenbank-Backups jetzt über verwaltbare Container statt als lose Root-Dateien. |
| **2.6.65** | 🔴 security | Admin/System | **Legacy-Dateien bleiben kontrolliert verwaltbar**: `CMS/core/Services/BackupService.php` erkennt alte `database_*.sql(.gz)`-Backups weiter defensiv, listet sie mit Metadaten und erlaubt das Löschen nur innerhalb des Backup-Roots. |
| **2.6.65** | 🟠 perf | Admin/System | **Große Backup-Listen werden früher begrenzt**: der Service priorisiert Verzeichniskandidaten vor dem Manifest-Parsing und lädt für die Admin-Liste nur noch die relevanten neuesten Backups statt stumpf jedes Manifest einzulesen. |

---

### v2.6.64 — 25. März 2026 · Audit-Batch 046, Mail-Settings gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.64** | 🔴 fix | Admin/System | **Mail-Entry und Mail-Settings validieren Aktionen, Hosts, URLs und Empfänger restriktiver**: `CMS/admin/mail-settings.php` akzeptiert nur noch bekannte POST-Aktionen; `CMS/admin/modules/system/MailSettingsModule.php` normalisiert SMTP-Host, Azure-/Graph-Endpunkte und Testempfänger jetzt enger und blockt unsaubere Werte deutlich früher. |
| **2.6.64** | 🔴 security | Admin/System | **Sensible Auditdaten werden maskiert**: Empfängeradressen sowie Tenant-/Client-Kennungen landen nur noch maskiert in Audit-Kontexten; Queue-Läufe protokollieren keine rohen Ergebnis-Arrays mehr. |
| **2.6.64** | 🟡 refactor | Admin/System | **Fehlerpfade generischer und interner abgesichert**: das Modul prüft Admin-Zugriff jetzt intern, gibt bei Save-/Graph-/Queue-/Testpfaden keine rohen Detailfehler mehr an die UI und auditiert Cache-Clears explizit. |

---

### v2.6.63 — 25. März 2026 · Audit-Batch 045, Subscription-Settings gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.63** | 🔴 fix | Admin/Subscriptions | **Abo-Settings validieren IDs und Pflichtwerte restriktiver**: `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` akzeptiert Standardpläne sowie AGB-/Widerrufsseiten jetzt nur noch, wenn die referenzierten Datensätze tatsächlich existieren bzw. veröffentlicht sind. |
| **2.6.63** | 🟠 perf | Admin/Subscriptions | **Settings-Laden und -Speichern gebündelt**: allgemeine und Paket-Settings werden gesammelt geladen und über einen gemeinsamen Persistenzpfad geschrieben, statt pro Option wiederholt eigene Existenzabfragen auszulösen. |
| **2.6.63** | 🟡 refactor | Admin/Subscriptions | **Fehler- und Auditpfade vereinheitlicht**: das Modul prüft Admin-Zugriff jetzt auch intern, gibt keine rohen Exception-Texte mehr an die UI und protokolliert Save-Vorgänge strukturiert über Logger und Audit-Log. |

---

### v2.6.62 — 25. März 2026 · Audit-Batch 044, Cookie-Manager nachgeschärft

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.62** | 🔴 fix | Admin/Legal | **Matomo-Self-Hosted-URL jetzt strikt validiert**: `CMS/admin/modules/legal/CookieManagerModule.php` akzeptiert gespeicherte Matomo-URLs nur noch als saubere HTTP(S)-Ziele ohne eingebettete Zugangsdaten und bricht bei ungültigen Werten früh mit einer klaren Admin-Meldung ab. |
| **2.6.62** | 🟠 perf | Admin/Legal | **Scanner- und Settings-Zugriffe stärker gestaffelt**: Low-Value-Pfade wie Cache-, Vendor-, Upload- oder Backup-Verzeichnisse werden im Cookie-Scanner übersprungen; Scan-Metadaten und Settings-Updates werden gebündelt statt in mehreren Einzelpfaden geschrieben. |
| **2.6.62** | 🟡 refactor | Admin/Legal | **Kategorie-/Settings-Lookups entkoppelt**: Default-Kategorien und Setting-Existenzprüfungen nutzen interne Caches, wodurch wiederholte Datenbank-Existenzchecks im Modulpfad sauberer gebündelt werden. |

---

### v2.6.61 — 24. März 2026 · Audit-Batch 043, Documentation-Renderer gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.61** | 🔴 fix | Admin/Documentation | **Link-Resolver-Ausfälle bleiben lokal**: `CMS/admin/modules/system/DocumentationRenderer.php` fängt Resolver-Fehler für Markdown-Links jetzt kontrolliert ab und fällt auf sichere Platzhalter-Links zurück, statt das gesamte Rendering mitzureißen. |
| **2.6.61** | 🔴 security | Admin/Documentation | **Href- und Render-Grenzen defensiver gemacht**: protokollrelative `//`-Links, Backslashes und Steuerzeichen werden verworfen; Linkziele werden gekappt und überlange Codeblöcke nur noch bis zu einem festen Maximalumfang gerendert. |
| **2.6.61** | 🟡 refactor | Admin/Documentation | **Codeblock- und Link-Guards vereinheitlicht**: große Markdown-Codefences laufen jetzt über denselben Guard-/Log-Pfad wie andere Renderer-Limits und halten die Admin-Dokumentation auch bei Sonderfällen stabiler. |

---

### v2.6.60 — 24. März 2026 · Audit-Batch 042, Security-Audit-Modul nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.60** | 🔴 fix | Admin/Security | **Audit-Log-Cleanup auf Security/Auth begrenzt**: `CMS/admin/modules/security/SecurityAuditModule.php` zählt und löscht alte Logeinträge jetzt nur noch innerhalb der vom Modul tatsächlich angezeigten Sicherheits- und Auth-Kategorien. |
| **2.6.60** | 🔴 security | Admin/Security | **Audit-Details und IP-Adressen defensiver gemacht**: Detailtexte werden sanitisiert, IP-Adressen im Audit-Log maskiert und `.htaccess`-Fehlerpfade ohne unnötige absolute Serverpfade protokolliert. |
| **2.6.60** | 🟡 refactor | Admin/Security | **Prüfpfade gezielter auf Runtime-Konfiguration ausgedehnt**: Das Modul bewertet jetzt zusätzlich `config/app.php`-Berechtigungen und verwendet einen gemeinsamen Sanitize-Pfad für Security-Audit-Kontexte. |

---

### v2.6.59 — 24. März 2026 · Audit-Batch 041, Settings-Modul gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.59** | 🔴 fix | Admin/Settings | **Settings-Persistenz ohne N+1-Existenzchecks**: `CMS/admin/modules/settings/SettingsModule.php` lädt vorhandene Setting-Namen jetzt gesammelt vor, statt pro Option zusätzliche `COUNT(*)`-Abfragen auszuführen. |
| **2.6.59** | 🔴 security | Admin/Settings | **Audit- und Mail-Kontexte defensiver gemacht**: Exception-Texte werden sanitisiert protokolliert, Test-Mail-Audits maskieren Empfängeradressen und URL-Migrationen landen mit kompakten Summaries statt roher Detail-Arrays im Audit-Log. |
| **2.6.59** | 🟡 refactor | Admin/Settings | **Konfigurations-Schreibpfad robuster gemacht**: `config/app.php` und `.htaccess` werden mit sichererer Ersatzlogik geschrieben; Tabellen-/Spaltenprüfungen für die URL-Migration nutzen jetzt wiederverwendete Caches statt redundanter Wiederholungsabfragen. |

---

### v2.6.58 — 24. März 2026 · Audit-Batch 040, Plugin-Marketplace gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.58** | 🔴 fix | Admin/Marketplace | **Lokale Manifestpfade und Plugin-Zielverzeichnis restriktiver geprüft**: `CMS/admin/modules/plugins/PluginMarketplaceModule.php` akzeptiert lokale Manifestpfade nur noch ohne Traversal-Segmente und validiert das Plugins-Verzeichnis vor der Auto-Installation gegen Schreibbarkeit und erwarteten Runtime-Root. |
| **2.6.58** | 🔴 security | Admin/Marketplace | **ZIP-Archive gegen auffällige Strukturen begrenzt**: Plugin-Pakete werden jetzt zusätzlich auf maximale Eintragsanzahl, unkomprimierte Gesamtgröße, Kontrollzeichen und segmentierte Pfadmanipulationen geprüft, bevor entpackt wird. |
| **2.6.58** | 🟡 refactor | Admin/Marketplace | **Download-/Entpackpfade sauberer gekapselt**: temporäre Dateien werden kontrollierter aufgeräumt, Schreibfehler beim lokalen Paket-Store liefern klare Fehlpfade und Registry-/Manifest-Downloads nutzen zentrale Größenlimits. |

---

### v2.6.57 — 24. März 2026 · Audit-Batch 039, Performance-Modul nachgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.57** | 🔴 fix | Admin/Performance | **Performance-Settings ohne N+1-Existenzchecks gespeichert**: `CMS/admin/modules/seo/PerformanceModule.php` lädt vorhandene Setting-Namen jetzt gesammelt vor, statt pro Einzelwert zusätzliche COUNT-Abfragen auszuführen. |
| **2.6.57** | 🔴 security | Admin/Performance | **Session- und Pfadkontexte defensiver gemacht**: Cache-Verzeichnisangaben und Medienpfade werden ohne unnötige Server-Interna ausgegeben; Session-Listen maskieren IP-Adressen und bereinigen User-Agents vor der View-Ausgabe. |
| **2.6.57** | 🟡 refactor | Admin/Performance | **Audit-/Warmup-Kontexte bereinigt**: OPcache-Warmup- und Save-Fehlerpfade loggen nur noch sanitisierte Kurzkontexte statt potenziell ausufernde Detaildaten direkt ins Audit-Log zu kippen. |

---

### v2.6.56 — 24. März 2026 · Audit-Batch 038, SEO-Suite-Modul nachgeschärft

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.56** | 🔴 fix | Admin/SEO | **Submission- und Social-Defaults restriktiver validiert**: `CMS/admin/modules/seo/SeoSuiteModule.php` akzeptiert Submission-Ziele, OG-Typen und Twitter-Card-Werte nur noch über explizite Allowlists; Matomo-Site-IDs werden serverseitig auf positive Ganzzahlen reduziert. |
| **2.6.56** | 🟠 perf | Admin/SEO | **Settings-Persistenz ohne N+1-Existenzchecks**: beim Speichern von SEO-Einstellungen werden vorhandene Setting-Keys jetzt gesammelt vorgeladen, statt pro Einzelwert erst ein zusätzlicher COUNT-Query zu laufen. |
| **2.6.56** | 🟡 refactor | Admin/SEO | **Fehler- und Statusdaten bereinigt**: Audit-Fehlertexte werden sanitisiert protokolliert und Sitemap-Dateistatusdaten liefern keine absoluten Serverpfade mehr an die Admin-Oberfläche weiter. |

---

### v2.6.55 — 24. März 2026 · Audit-Batch 037, Git-Doku-Sync gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.55** | 🔴 fix | Admin/Documentation | **Git-basierter Doku-Sync mit Ref- und Status-Gates nachgeschärft**: `CMS/admin/modules/system/DocumentationGitSync.php` prüft den Remote-Ref jetzt explizit vor dem Checkout und bricht bei nicht prüfbarem oder inkonsistentem `/DOC`-Status kontrolliert ab. |
| **2.6.55** | 🔴 security | Admin/Documentation | **Lokale Änderungen und Parallel-Läufe werden nicht mehr still überfahren**: laufende Git-Syncs werden per Lockfile serialisiert, und uncommittete bzw. untracked Änderungen unter `/DOC` blockieren den Sync mit auditierbarem Fehlerpfad. |
| **2.6.55** | 🟡 refactor | Admin/Documentation | **Git-Aufrufe restriktiver und Log-Kontexte sauberer**: Fetches laufen mit reduzierten Nebeneffekten (`--no-tags --prune --no-recurse-submodules`), Ref-/Pfad-Kontexte werden sanitisiert und Runtime-Fehler landen zuverlässig im generischen Modul-Fehlerpfad. |

---

### v2.6.54 — 24. März 2026 · Audit-Batch 036, Root-Cron gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.54** | 🔴 fix | Core/Cron | **Root-Cron-Entry auf kontrollierte Web-Methoden und normalisierte Parameter begrenzt**: `CMS/cron.php` akzeptiert im Web nur noch `GET` und `HEAD`, normalisiert `task` und `limit` serverseitig und beantwortet `HEAD`-Checks ohne unnötigen Response-Body. |
| **2.6.54** | 🔴 security | Core/Cron | **Token- und Fehlerpfade nachgeschärft**: Cron-Tokens können zusätzlich über Header transportiert werden, parallele Läufe werden per Lockfile abgefangen und rohe Exception-Details leaken nicht mehr direkt in JSON-Antworten. |
| **2.6.54** | 🟡 refactor | Core/Cron | **Operative Schutzgeländer ergänzt**: der Entry verzichtet auf unnötigen Session-Start, setzt `X-Robots-Tag` für Web-Cron-Antworten und protokolliert technische Fehler nur noch intern in sanitierter Form. |

---

### v2.6.53 — 24. März 2026 · Audit-Batch 035, Doku-Downloader gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.53** | 🔴 fix | Admin/Documentation | **Doku-Downloader gegen falsche Ziele und nicht-zipartige Responses gehärtet**: `CMS/admin/modules/system/DocumentationSyncDownloader.php` akzeptiert nur noch dedizierte Temp-Zieldateien und erwartete GitHub-ZIP-URLs. |
| **2.6.53** | 🔴 security | Admin/Documentation | **ZIP-Signatur- und Größenprüfungen ergänzt**: zu kleine, zu große oder nicht mit ZIP-Magic beginnende Responses werden vor dem Schreiben verworfen; Remote-Fehler werden nur noch generisch an die UI gegeben. |
| **2.6.53** | 🟡 refactor | Admin/Documentation | **Download-Pfade auditierbar gemacht**: erfolgreiche und fehlgeschlagene Downloads werden mit sanitierter URL-/Pfad-Kontextinfo geloggt und auditiert. |

---

### v2.6.52 — 24. März 2026 · Audit-Batch 034, GitHub-ZIP-Doku-Sync gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.52** | 🔴 fix | Admin/Documentation | **GitHub-ZIP-Sync gegen fehlerhafte Quellen und Archive gehärtet**: `CMS/admin/modules/system/DocumentationGithubZipSync.php` validiert ZIP-URL und Integritätsprofil jetzt auch intern, statt sich nur auf Vorprüfungen außerhalb des Moduls zu verlassen. |
| **2.6.52** | 🔴 security | Admin/Documentation | **Archivgrenzen und Kontext-Sanitizing nachgeschärft**: ZIP-Dateien mit zu vielen Einträgen oder zu großer Gesamtgröße werden früh verworfen; Audit- und Logger-Kontexte enthalten keine rohen Exception-Texte oder kompletten Fremd-URLs mehr. |
| **2.6.52** | 🟡 refactor | Admin/Documentation | **Erfolgspfad auditiert**: erfolgreiche GitHub-ZIP-Syncs werden explizit protokolliert und liefern strukturierte Dokumentenzahlen statt lose impliziter Seiteneffekte. |

---

### v2.6.51 — 24. März 2026 · Audit-Batch 033, Backup-Modul gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.51** | 🔴 fix | Admin/System | **Backup-Modul mit internen RBAC- und CSRF-Gates abgesichert**: `CMS/admin/modules/system/BackupsModule.php` validiert Lese- und Schreibzugriffe jetzt auch intern und verlässt sich nicht nur auf den äußeren Admin-Entry-Point. |
| **2.6.51** | 🔴 security | Admin/System | **Backup-Metadaten und Löschpfade stärker eingegrenzt**: nur noch erlaubte Backup-Namen, Typen und Dateiendungen gelangen in UI- und Delete-Pfade; lose Manifest-/History-Daten werden vor der Anzeige serverseitig normalisiert. |
| **2.6.51** | 🟡 refactor | Admin/System | **Audit- und Fehlerpfade vereinheitlicht**: erfolgreiche Create-/Delete-Aktionen werden explizit auditiert; technische Fehlerdetails landen gekürzt im Logger statt roh im UI-Kontext. |

---

### v2.6.50 — 24. März 2026 · Audit-Batch 032, Member-Dashboard-Modul gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.50** | 🔴 fix | Admin/Member | **Member-Dashboard-Modul mit internen RBAC- und CSRF-Gates abgesichert**: `CMS/admin/modules/member/MemberDashboardModule.php` prüft Schreibzugriffe jetzt pro Bereich intern gegen Capability und Sicherheitstoken statt sich nur auf die äußere Admin-Shell zu verlassen. |
| **2.6.50** | 🟠 perf | Admin/Member | **Settings- und KPI-Zugriffe gebündelt**: Member-Settings, Plugin-Widget-Metadaten und Dashboard-Statistiken werden deutlich kompakter geladen, wodurch wiederholte Einzelqueries im Modul entfallen. |
| **2.6.50** | 🟡 refactor | Admin/Member | **Auditierbare Save-Pfade**: erfolgreiche Konfigurationsänderungen an Member-Dashboard-Bereichen werden explizit auditiert; Fehlerpfade loggen nur gekürzte technische Details statt rohe Exception-Texte zu streuen. |

---

### v2.6.49 — 24. März 2026 · Audit-Batch 031, Documentation-Sync-Dateisystem gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.49** | 🔴 fix | Admin/Documentation | **Dateisystem-Grenzen des Doku-Syncs nachgeschärft**: `CMS/admin/modules/system/DocumentationSyncFilesystem.php` erlaubt Copy-, Rename-, Delete-, Count- und Integrity-Pfade nur noch innerhalb explizit verwalteter Repo-, DOC- und Temp-Roots. |
| **2.6.49** | 🔴 security | Admin/Documentation | **Staging-, Backup- und Cleanup-Pfade isoliert**: auch noch nicht existierende Zielpfade werden über ihren aufgelösten Elternpfad gegen die erlaubten Arbeitsbereiche geprüft, bevor Dateisystem-Mutationen stattfinden. |
| **2.6.49** | 🟡 refactor | Admin/Documentation | **Root-Kontext explizit verdrahtet**: `DocumentationSyncService` instanziiert den Filesystem-Dienst jetzt mit Repository-, DOC- und Temp-Root, sodass Guard-Logik nicht mehr implizit oder kontextfrei arbeiten muss. |

---

### v2.6.48 — 24. März 2026 · Audit-Batch 030, EditorJs Remote-Media-Service gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.48** | 🔴 fix | Core/EditorJs | **Remote-Media-Fetches für Editor.js deutlich restriktiver gemacht**: `CMS/core/Services/EditorJs/EditorJsRemoteMediaService.php` akzeptiert nur noch normalisierte HTTPS-URLs ohne eingebettete Credentials und blockt überlange oder zeilenumbruchhaltige Remote-URLs frühzeitig ab. |
| **2.6.48** | 🔴 security | Core/EditorJs | **Remote-Metadaten und Preview-Bilder gehärtet**: fremdes HTML wird größenbegrenzt verarbeitet, Metadaten werden sauber gekürzt und bereinigt, Preview-Bilder nur noch als validierte sichere Remote-URLs übernommen. |
| **2.6.48** | 🟡 refactor | Core/EditorJs | **Fehlerpfade bereinigt**: Netzwerk- und Remote-Fehler werden intern geloggt, aber gegenüber Editor.js nur noch generisch und UI-tauglich ausgegeben; der libxml-Fehlerzustand wird nach DOM-Verarbeitung wiederhergestellt. |

---

### v2.6.47 — 24. März 2026 · Audit-Batch 029, Mail-Service gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.47** | 🔴 fix | Core/Mail | **Mail-Service gegen Header-Injection und rohe Transportfehler gehärtet**: `CMS/core/Services/MailService.php` validiert Header, Adresslisten, Empfänger, Absender und Betreff restriktiver und blockiert kritische Header-Overrides wie `To`, `Subject` oder `Return-Path`. |
| **2.6.47** | 🔴 security | Core/Mail | **TLS-Enforcement für SMTP verschärft**: nicht-lokale SMTP-Hosts sowie OAuth2-basierte Mailtransporte laufen nicht mehr still ohne Verschlüsselung, sondern werden im Service auf TLS gehoben. |
| **2.6.47** | 🟡 refactor | Core/Mail | **Fehlerpfade bereinigt**: UI- und API-Rückgaben aus den Detailed-Send-Pfaden verwenden klassifizierte, generische Fehlermeldungen statt roher Provider- oder Exception-Texte; interne Fehlertexte werden gekürzt und bereinigt geloggt. |

---

### v2.6.46 — 24. März 2026 · Audit-Batch 028, Landing-Page-Modul gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.46** | 🔴 fix | Admin/Landing | **Landing-Page-Modul gegen freie POST-Payloads und rohe Fehlerausgaben gehärtet**: `CMS/admin/modules/landing/LandingPageModule.php` prüft Admin-Zugriff jetzt auch intern, normalisiert Tabs serverseitig und akzeptiert bei Header-, Content-, Footer-, Design-, Feature- und Plugin-Mutationen nur noch explizit erlaubte Felder. |
| **2.6.46** | 🟠 perf | Admin/Landing | **Kleinere Mutations-Payloads**: unnötige oder fremde POST-Felder werden vor den Service-Aufrufen verworfen, wodurch die Landing-Verwaltung weniger lose Daten weiterreicht und deterministischer speichert. |
| **2.6.46** | 🟡 refactor | Admin/Landing | **Fehlerpfade vereinheitlicht**: statt roher Exception-Meldungen an die Oberfläche werden Fehler intern kanalisiert geloggt und generisch an die UI zurückgegeben. |

---

### v2.6.45 — 24. März 2026 · Audit-Batch 027, Legal-Sites-Modul gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.45** | 🔴 fix | Admin/Legal | **Legal-Sites-Modul gegen lose Seitenzuordnungen und ungebremste Payloads gehärtet**: `CMS/admin/modules/legal/LegalSitesModule.php` prüft Admin-Zugriff jetzt auch intern, validiert zugewiesene Rechtstext-Seiten serverseitig gegen veröffentlichte Seiten und begrenzt HTML- sowie Profilwerte deutlich strenger. |
| **2.6.45** | 🟠 perf | Admin/Legal | **Settings-Zugriffe gebündelt**: Inhalte, Seiten-IDs und Profilwerte werden bei Lese- und Speicherpfaden stärker gesammelt verarbeitet statt über viele Einzelabfragen. |
| **2.6.45** | 🟡 refactor | Admin/Legal | **Persistenz- und Fehlerpfade vereinheitlicht**: generierte Rechtstexte, Profilwerte und Seitensynchronisierungen nutzen konsistente Settings-Writer; Audit-Logs führen keine rohen Exception-Texte mehr. |

---

### v2.6.44 — 24. März 2026 · Audit-Batch 026, Dokumentations-Modul gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.44** | 🔴 fix | Admin/System | **Dokumentations-Modul gegen lose Pfad- und Zugriffsannahmen gehärtet**: `CMS/admin/modules/system/DocumentationModule.php` prüft Admin-Zugriff jetzt auch intern, validiert Repository-/`/DOC`-Layout vor Datenaufbau und Sync-Aufruf und akzeptiert ausgewählte Dokumente nur noch in erwarteten Längen und Dateitypen. |
| **2.6.44** | 🔴 fix | Admin/System | **Render- und Sync-Fehler laufen kontrollierter**: unerwartete Ausnahmen werden intern gekürzt geloggt und nach außen nur noch mit generischen, UI-tauglichen Meldungen beantwortet. |
| **2.6.44** | 🟡 refactor | Admin/System | **Fehlerzustände liefern konsistente View-Daten**: das Modul gibt auch bei Fehlkonfigurationen strukturierte Antwortpayloads zurück, damit die Doku-Oberfläche stabil und ohne lose Spezialfälle rendern kann. |

---

### v2.6.43 — 24. März 2026 · Audit-Batch 025, Feed-Service gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.43** | 🔴 fix | Core/Feeds | **Feed-Service gegen unsichere Remote-Ziele und rohe Fehlerpfade gehärtet**: `CMS/core/Services/FeedService.php` validiert Feed-URLs jetzt auf erlaubte HTTP(S)-Schemes, blockiert Hosts mit Credentials sowie private/reservierte Zielnetze und begrenzt Batch-Listen auf eine kontrollierte Anzahl valider Feed-Quellen. |
| **2.6.43** | 🔴 fix | Core/Feeds | **Feed-Metadaten und Items werden defensiver normalisiert**: Titel, Kategorien, Autoren, GUIDs sowie Link-/Bild-Ziele werden serverseitig bereinigt, während Feed-Beschreibungen und -Inhalte über `PurifierService` sanitisiert werden. |
| **2.6.43** | 🟡 refactor | Core/Feeds | **Cache- und Logging-Pfade vereinheitlicht**: Cache-Dateien werden nur noch innerhalb des echten Feed-Cache-Roots gelöscht, Parser-/Remote-Fehler werden gekürzt geloggt und nach außen nur noch generisch beantwortet. |

---

### v2.6.42 — 24. März 2026 · Audit-Batch 024, Azure-Mail-Token-Provider gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.42** | 🔴 fix | Core/Integration | **Azure-Mail-Token-Provider gegen Konfigurationsdrift und unsaubere Endpunkte gehärtet**: `CMS/core/Services/AzureMailTokenProvider.php` validiert Tenant-/Client-/Mailbox-/Scope-Werte restriktiver, akzeptiert nur noch sichere Microsoft-Login-Tokenpfade und verwirft Query-/Fragment-Anteile an benutzerdefinierten Token-Endpunkten. |
| **2.6.42** | 🔴 fix | Core/Integration | **Token-Cache und Remote-Antworten defensiver gemacht**: gecachte Tokens werden nur noch bei sauberer Form und ausreichender Restlaufzeit wiederverwendet; kaputte oder abgelaufene Cache-Einträge werden aktiv entfernt, während Remote-JSON und Fehlermeldungen serverseitig begrenzt und bereinigt werden. |
| **2.6.42** | 🟡 refactor | Core/Integration | **Azure-OAuth2-Fehlerpfade vereinheitlicht**: Token-Typen werden konsistent normalisiert und Response-/Remote-Fehler laufen über kleine zentrale Helper statt über lose Einzelprüfungen. |

---

### v2.6.41 — 24. März 2026 · Audit-Batch 023, Cookie-Manager-Modul gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.41** | 🔴 fix | Admin/Legal | **Cookie-Manager-Modul gegen unsaubere Mutationen und lose Zugriffsannahmen gehärtet**: `CMS/admin/modules/legal/CookieManagerModule.php` prüft Admin-Zugriff jetzt auch intern, validiert Kategorie-/Service-Payloads strenger und blockiert doppelte Slugs sowie das Löschen noch verwendeter Kategorien serverseitig. |
| **2.6.41** | 🔴 fix | Admin/Legal | **Cookie-Scanner begrenzt und normalisiert**: Datei- und DB-Scans lesen nur noch begrenzte Größen/Mengen, kürzen Quellen/Resultate und normalisieren gespeicherte Treffer auf bekannte kuratierte Services zurück. |
| **2.6.41** | 🟡 refactor | Admin/Legal | **Settings- und Audit-Pfade vereinheitlicht**: Cookie-Settings werden gesammelt persistiert statt per wiederholtem Existenz-Check, während Mutationen und Scanner-Läufe zusätzlich nachvollziehbar im Audit-Log landen. |

---

### v2.6.40 — 24. März 2026 · Audit-Batch 022, Security-Audit-Modul gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.40** | 🔴 fix | Admin/Security | **Security-Audit-Modul gegen rohe Audit-Daten und Detail-Leaks gehärtet**: `CMS/admin/modules/security/SecurityAuditModule.php` liest nur noch relevante Security-/Auth-Logfelder, begrenzt Audit-Details und Check-Texte serverseitig und schützt den Modulzugriff zusätzlich gegen unberechtigte Aufrufe ab. |
| **2.6.40** | 🔴 fix | Admin/Security | **Log-Bereinigung und Teilfehler liefern nur noch generische UI-Meldungen**: Fehlschläge bei `clearLog()`, Passwort-Hash-Checks oder Audit-Log-Ladevorgängen werden intern geloggt und auditierbar protokolliert, ohne rohe Exception-Texte in die Oberfläche zu leaken. |
| **2.6.40** | 🟡 refactor | Admin/Security | **.htaccess-Inspektion und Audit-Checks defensiver normalisiert**: Header-Fallback-Prüfung liest die Root-`.htaccess` nur noch begrenzt ein und das Modul bündelt Status-/Textnormalisierung zentral über kleine Helper. |

---

### v2.6.39 — 24. März 2026 · Audit-Batch 021, Dokumentations-Renderer gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.39** | 🔴 fix | Admin/System | **Dokumentations-Renderer gegen ausufernde Render-Payloads gehärtet**: `CMS/admin/modules/system/DocumentationRenderer.php` begrenzt Markdown-/CSV-Größe, Zeilenanzahl sowie Tabellen- und Zellumfang, bevor Inhalte in HTML für den Admin-Bereich überführt werden. |
| **2.6.39** | 🔴 fix | Admin/System | **Linkziele im Doku-HTML enger validiert**: erzeugte `href`-Werte werden auf saubere Anchors, interne Pfade oder valide HTTP(S)-URLs begrenzt, sodass keine losen Sonderziele im Admin-Rendering landen. |
| **2.6.39** | 🟡 refactor | Admin/System | **Render-Grenzen werden nachvollziehbar geloggt**: begrenzte Dokumente, Tabellen und CSV-Ansichten schreiben jetzt Guard-Logs statt ungebremst oder still in große HTML-Ausgaben zu laufen. |

---

### v2.6.38 — 24. März 2026 · Audit-Batch 020, Kommentar-Service gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.38** | 🔴 fix | Core/Comments | **Öffentliche Kommentarerstellung gegen Missbrauch und geschlossene Posts gehärtet**: `CMS/core/Services/CommentService.php` akzeptiert nur noch valide Autor-/Mail-/Content-Payloads, blockiert Kommentare auf nicht veröffentlichten oder kommentargesperrten Beiträgen und normalisiert IP-Adressen defensiver. |
| **2.6.38** | 🔴 fix | Core/Comments | **Kommentar-Flood-Limit und Logging-/Audit-Pfade ergänzt**: der Service begrenzt Kommentarfluten pro Mail/IP/User in einem Zeitfenster und protokolliert verworfene bzw. erfolgreiche Pending-Kommentare intern nachvollziehbar. |
| **2.6.38** | 🟡 refactor | Core/Comments | **Öffentliche Ausgabe und Listenabrufe entschärft**: freigegebene Kommentarlisten leaken keine Autor-Mailadressen mehr ins Frontend und Admin-List-Reads werden zusätzlich auf sinnvolle Grenzen geklemmt. |

---

### v2.6.37 — 24. März 2026 · Audit-Batch 019, Microsoft-Graph-Service gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.37** | 🔴 fix | Core/Integration | **Microsoft-Graph-Service gegen Konfigurationsdrift und Response-Leaks gehärtet**: `CMS/core/Services/GraphApiService.php` validiert Tenant-/Client-/Scope-/Endpoint-Werte restriktiver, akzeptiert nur sichere Graph-/Token-Pfade und gibt bei Verbindungstests nur noch generische Fehlermeldungen nach außen. |
| **2.6.37** | 🟡 refactor | Core/Integration | **Graph-Tokenabruf auf sauberen Form-Request umgestellt**: Client-Credentials werden jetzt als `application/x-www-form-urlencoded` über den HTTP-Client gesendet, inklusive fester Größen- und Content-Type-Grenzen für Antworten. |
| **2.6.37** | 🟠 perf | Core/Integration | **Graph-Antworten defensiver normalisiert**: Organisationsdaten und Remote-Fehler werden gekürzt, bereinigt und auf ein erwartbares Schema reduziert, wodurch Folgepfade weniger Sonderfälle behandeln müssen. |

---

### v2.6.36 — 24. März 2026 · Audit-Batch 018, Hub-Template-Profile gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.36** | 🔴 fix | Admin/Hub | **Hub-Template-Profilmanager gegen unsaubere Payloads und stille Persistenzfehler gehärtet**: `CMS/admin/modules/hub/HubTemplateProfileManager.php` begrenzt Link-/Section-/Starter-Card-Payloads, normalisiert URL-Ziele restriktiver und behandelt fehlgeschlagene Settings-Speicherungen sowie Template-Mutationen nur noch generisch mit internem Logging/Audit. |
| **2.6.36** | 🟠 perf | Admin/Hub | **Template-Nutzungszähler ohne N+1-Abfragen berechnet**: das Hub-Template-Listing holt Usage-Counts gesammelt per Aggregatabfrage statt für jedes Profil separat. |
| **2.6.36** | 🟡 refactor | Admin/Hub | **Vererbte Hub-Sites werden nur noch bei echten Template-Änderungen nachgezogen**: der Profilmanager erkennt unveränderte Link-/Starter-Card-Vererbungen früher und protokolliert fehlschlagende Sync-Updates kontrolliert. |

---

### v2.6.35 — 24. März 2026 · Audit-Batch 017, Firewall-Flow gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.35** | 🔴 fix | Admin/Security | **Firewall-Entry auf Action-Whitelist gebracht**: `CMS/admin/firewall.php` akzeptiert nur noch bekannte POST-Aktionen und behandelt CSRF-/Aktionsfehler konsistent über Redirect + Flash-Alert. |
| **2.6.35** | 🔴 fix | Admin/Security | **Firewall-Modul gegen unvalidierte Regeln und Fehlerdetail-Leaks gehärtet**: `CMS/admin/modules/security/FirewallModule.php` validiert IP-/CIDR-/Country-/UA-Regeln strenger, blockiert Dubletten, prüft Delete-/Toggle-Ziele serverseitig und beantwortet Save-/Mutationsfehler im UI nur noch generisch mit internem Logging/Audit. |
| **2.6.35** | 🟡 refactor | Admin/Security | **Firewall-View an gemeinsamen Security-UI-Standard angenähert**: `CMS/admin/views/security/firewall.php` nutzt Flash-Alerts, rendert Ablaufdaten ohne unescaped Inline-HTML und bestätigt Löschaktionen über `cmsConfirm(...)` statt Browser-`confirm()`. |

---

### v2.6.34 — 24. März 2026 · Audit-Batch 016, Doku-Sync-Orchestrator gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.34** | 🔴 fix | Admin/System | **Doku-Sync-Orchestrator gegen Konfigurationsdrift gehärtet**: `CMS/admin/modules/system/DocumentationSyncService.php` validiert Repository-Root, `/DOC`-Ziel, Branch-/Remote-Werte, GitHub-ZIP-Quelle und Integritätsprofil jetzt zentral, bevor Unterservices den eigentlichen Sync starten. |
| **2.6.34** | 🟡 refactor | Admin/System | **Capability- und Ergebnisfluss vereinheitlicht**: Nicht verfügbare oder inkonsistente Sync-Modi laufen jetzt über einen generischen, auditierbaren Fehlerpfad, während erfolgreiche Git-/GitHub-ZIP-Synchronisationen zusätzlich zentral geloggt und im Audit-Log festgehalten werden. |

---

### v2.6.33 — 24. März 2026 · Audit-Batch 015, Kommentar-Moderation gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.33** | 🔴 fix | Admin/Comments | **Kommentar-Entry an RBAC-Capabilities ausgerichtet**: `CMS/admin/comments.php` nutzt jetzt `comments.view` für den Zugriff, akzeptiert nur noch bekannte POST-Aktionen und hält Redirects enger am validierten Statusfilter. |
| **2.6.33** | 🔴 fix | Admin/Comments | **Kommentar-Modul gegen unvalidierte Mutationen und stille Bulk-Fehler gehärtet**: `CMS/admin/modules/comments/CommentsModule.php` prüft IDs, Zielstatus, Kommentar-Existenz und Rechte serverseitig, begrenzt Bulk-Mengen und protokolliert Teil-/Fehlschläge intern per Logging und Audit-Log. |
| **2.6.33** | 🟡 refactor | Admin/Comments | **Kommentar-View an Rechtezustand gekoppelt**: `CMS/admin/views/comments/list.php` rendert Bulk-Bar, Checkboxen und Row-Actions jetzt capability-basiert und nutzt vorbereitete Post-Ziele aus dem Modul statt roher Slug-Verkettung im Template. |

---

### v2.6.32 — 24. März 2026 · Audit-Batch 014, FileUploadService-Härtung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.32** | 🔴 fix | Core/Uploads | **Zentralen Upload-Endpunkt enger abgesichert**: `CMS/core/Services/FileUploadService.php` akzeptiert nur noch echte `POST`-Uploads, prüft Dateipayloads auf Einzeldatei-Form und Pflichtfelder und validiert Zielpfade jetzt segmentweise gegen Traversal-, Dotfile- und Steuerzeichen-Pfade. |
| **2.6.32** | 🔴 fix | Core/Uploads | **Upload-Fehlerpfade gegen Detail-Leaks vereinheitlicht**: Validierungs- und Persistenzfehler aus dem Media-Stack werden im Client nur noch generisch beantwortet, während technische Details intern geloggt und auditierbar protokolliert werden. |

---

### v2.6.31 — 24. März 2026 · Audit-Batch 013, Hub-Sites-Härtung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.31** | 🔴 fix | Admin/Hub | **Hub-Sites-Entry auf Whitelist-Flow gebracht**: `CMS/admin/hub-sites.php` akzeptiert nur noch bekannte POST-Aktionen und Views und behandelt Fehlfälle mit konsistenten Fallback-Meldungen statt losem Sonderverhalten. |
| **2.6.31** | 🔴 fix | Admin/Hub | **Hub-Sites-Modul gegen Detail-Leaks und unsaubere Linkziele gehärtet**: `CMS/admin/modules/hub/HubSitesModule.php` normalisiert Suche, Plaintext-, CTA-, Card-, Bild- und Linkwerte zentraler, fällt bei unsicheren URLs auf sichere Defaults zurück und behandelt Save-/Delete-/Duplicate-Fehler im UI nur noch generisch mit internem Logging/Audit. |

---

### v2.6.30 — 24. März 2026 · Audit-Batch 012, Theme-Editor-Resthärtung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.30** | 🔴 fix | Admin/Themes | **Theme-Explorer-Wrapper enger begrenzt**: `CMS/admin/theme-explorer.php` verarbeitet den Save-Flow jetzt nur noch über eine explizite Allowlist bekannter Aktionen. |
| **2.6.30** | 🔴 fix | Admin/Themes | **Theme-Editor gegen Rest-Leaks und Binär-/Oversize-Inhalte gehärtet**: `CMS/admin/modules/themes/ThemeEditorModule.php` beantwortet unsichere Dateianfragen kontrolliert, blockiert Binärdaten und zu große neue Inhalte vor dem Schreiben und behandelt Syntax-/Schreibfehler nur noch generisch mit internem Logging/Audit. |
| **2.6.30** | 🟡 refactor | Admin/Themes | **Theme-Editor-View defensiver gemacht**: `CMS/admin/views/themes/editor.php` schützt den Tree-Renderer gegen Redeclare-/Datentyp-Randfälle und escaped den Basis-Link stringenter. |

---

### v2.6.29 — 24. März 2026 · Audit-Batch 011, Pages-Härtung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.29** | 🔴 fix | Admin/Content | **Pages-Entry auf Whitelist-Flow gebracht**: `CMS/admin/pages.php` akzeptiert nur noch bekannte POST-Aktionen und Views, leitet CSRF-/Aktionsfehler konsistent per Redirect + Flash zurück und typisiert Bulk-Parameter defensiver vor der Modulübergabe. |
| **2.6.29** | 🔴 fix | Admin/Content | **Pages-Modul gegen Detail-Leaks und unnormalisierte Eingaben gehärtet**: `CMS/admin/modules/pages/PagesModule.php` normalisiert Listenfilter und Bulk-Aktionen zentral, sanitisiert Titel-/Meta-/Medienfelder vor Persistenz und behandelt Save-/Delete-/Bulk-Fehler im UI nur noch generisch mit internem Logging/Audit. |

---

### v2.6.28 — 24. März 2026 · Audit-Batch 010, Posts-Härtung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.28** | 🔴 fix | Admin/Content | **Posts-Entry auf Whitelist-Flow gebracht**: `CMS/admin/posts.php` akzeptiert nur noch bekannte POST-Aktionen und Views, typisiert Bulk-/Kategorie-Parameter defensiver und behandelt ungültige Mutationen konsistent über Redirect + Flash-Alert. |
| **2.6.28** | 🔴 fix | Admin/Content | **Posts-Modul gegen Detail-Leaks und versteckte Request-Kopplung gehärtet**: `CMS/admin/modules/posts/PostsModule.php` normalisiert Listenfilter, Bulk-Aktionen sowie mehrere Text-/Meta-/Medienfelder zentral, entkoppelt Kategorie-/Tag-Löschpfade von direkten `$_POST`-Reads und gibt Fehler im UI nur noch generisch aus, während Details intern geloggt und auditierbar protokolliert werden. |

---

### v2.6.27 — 24. März 2026 · Audit-Batch 009, Update-Härtung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.27** | 🔴 fix | Admin/System | **Update-Entry auf Aktions-Whitelist gebracht**: `CMS/admin/updates.php` akzeptiert nur noch bekannte POST-Aktionen, normalisiert Plugin-Slugs vor der Übergabe und behandelt ungültige Mutationen konsistent über Redirect + Flash-Alert. |
| **2.6.27** | 🔴 fix | Admin/System | **Updates-Modul gegen Detail-Leaks gehärtet**: `CMS/admin/modules/system/UpdatesModule.php` normalisiert Plugin-Slugs zentral, trennt manuelle von direkt installierbaren Plugin-Updates und gibt Prüf-/Installationsfehler im UI nur noch generisch aus, während Details intern geloggt und auditierbar protokolliert werden. |
| **2.6.27** | 🔴 fix | Core/Updates | **Update-Service enger an erlaubte Roots und sichere Downloads gebunden**: `CMS/core/Services/UpdateService.php` verlangt für Downloads jetzt zusätzlich den SSRF-/DNS-Sicherheitscheck, begrenzt Installationsziele auf erlaubte Core-/Plugin-/Theme-Pfade, verwirft leere Download-Bodies und beantwortet Installationsfehler nach außen generisch. |

---

### v2.6.26 — 24. März 2026 · Audit-Batch 008, Media-Härtung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.26** | 🔴 fix | Admin/Media | **Media-Entry defensiver gemacht**: `CMS/admin/media.php` akzeptiert nur noch bekannte POST-Aktionen und normalisiert Redirect-Parameter wie `path`, `view`, `category` und `q`, bevor sie zurück in den Admin-Flow gespiegelt werden. |
| **2.6.26** | 🔴 fix | Admin/Media | **Media-Modul gegen unnormalisierte Eingaben und Detail-Leaks gehärtet**: `CMS/admin/modules/media/MediaModule.php` bereinigt Pfade, Tabs, Views, Suchbegriffe, Datei-/Ordnernamen und Kategorie-Slugs zentral, blockiert System-Kategorien serverseitig und gibt Service-Fehler im UI nur noch generisch aus, während Details intern geloggt und auditierbar protokolliert werden. |
| **2.6.26** | 🟡 refactor | Admin/Media | **Media-Settings enger begrenzt**: Uploadgrößen sowie Qualitäts- und Dimensionsfelder werden vor dem Persistieren konsistenter gekappt, damit das Modul weniger ungültige oder ausreißende Settings an den Service weiterreicht. |

---

### v2.6.25 — 24. März 2026 · Audit-Batch 007, Doku-Sync-Härtung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.25** | 🔴 fix | Admin/System | **Git-basierter Doku-Sync defensiver gemacht**: `CMS/admin/modules/system/DocumentationGitSync.php` validiert Repository-/DOC-Ziele und Git-Ref-Teile vor dem Lauf, begrenzt Shell-Fehlerdetails auf interne Logs und liefert im Admin-UI nur noch generische Fehlermeldungen zurück. |
| **2.6.25** | 🔴 fix | Admin/System | **GitHub-ZIP-Sync gegen Pfad- und Link-Fallen gehärtet**: `CMS/admin/modules/system/DocumentationGithubZipSync.php`, `DocumentationSyncDownloader.php` und `DocumentationSyncFilesystem.php` begrenzen Arbeits- und Downloadpfade auf erlaubte Roots, blockieren symbolische Links defensiver, verwerfen leere Download-Bodies und propagieren Cleanup-/Filesystem-Fehler sauberer. |
| **2.6.25** | 🟡 refactor | Admin/System | **Dokumentations-Entry vereinheitlicht**: `CMS/admin/documentation.php` akzeptiert jetzt nur noch die erwartete Aktion `sync_docs`, und `DocumentationSyncService.php` prüft das erlaubte `/DOC`-Layout vor dem eigentlichen Sync zusätzlich vor. |

---

### v2.6.24 — 24. März 2026 · Audit-Batch 006, Member-/Legal-Härtung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.24** | 🔴 fix | Admin/Member | **Member-Dashboard-Settings gehärtet**: `CMS/admin/modules/member/MemberDashboardModule.php` normalisiert Dashboard-Logo und Onboarding-CTA-URLs defensiver und führt Speicherroutinen bei Fehlern über einen zentralen, auditierbaren Generic-Error-Pfad statt rohe Exception-Texte an die UI weiterzugeben. |
| **2.6.24** | 🔴 fix | Admin/Legal | **Cookie-Manager robuster gemacht**: `CMS/admin/modules/legal/CookieManagerModule.php` begrenzt Policy-URLs, Slugs, Matomo-Site-IDs und Bannertexte strenger, hält Dateisystem-Scans von Symlinks fern und behandelt Persistenzfehler nur noch generisch im UI. |
| **2.6.24** | 🔴 fix | Admin/Legal | **Legal-Sites-Fehlerpfade vereinheitlicht**: `CMS/admin/modules/legal/LegalSitesModule.php` leakt in Save-, Profil- und Seitengenerierungs-Pfaden keine rohen Exceptions mehr, sondern protokolliert Fehler zentral auditierbar. |

---

### v2.6.23 — 24. März 2026 · Audit-Batch 005, SEO-/Performance-/Settings-Härtung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.23** | 🔴 fix | Admin/SEO | **SEO-Suite defensiver gemacht**: `CMS/admin/modules/seo/SeoSuiteModule.php` validiert Indexing-URLs jetzt hostgebunden gegen die eigene Site, dedupliziert Submission-Listen, normalisiert Sitemap-Prioritäten/-Frequenzen und nutzt für Broken-Link-Prüfungen die konfigurierbare Permalink-Struktur statt harter `/blog/`-Annahmen. |
| **2.6.23** | 🔴 fix | Admin/Performance | **Performance-Dateipfade robuster abgesichert**: `CMS/admin/modules/seo/PerformanceModule.php` überspringt Symlinks in Cache-, Session- und Medienläufen, begrenzt numerische Settings sauberer und leakt bei Settings-Fehlern keine rohen Exceptions mehr ins UI. |
| **2.6.23** | 🔴 fix | Admin/Settings | **Allgemeine Einstellungen und Config-Writer gehärtet**: `CMS/admin/modules/settings/SettingsModule.php` normalisiert Logo-/Favicon-Referenzen strenger, schreibt `config/app.php` und `config/.htaccess` kontrollierter über temporäre Dateien und reduziert Fehlerdetail-Leaks in Save-, Migrations- und Slug-Repair-Pfaden. |
| **2.6.23** | 🟡 refactor | Admin/Settings | **Settings-Entry vereinheitlicht**: `CMS/admin/settings.php` akzeptiert nur noch bekannte POST-Aktionen und `CMS/admin/views/settings/general.php` nutzt jetzt den gemeinsamen Flash-Alert-Partial statt eigener Alert-Duplikate. |

---

### v2.6.22 — 24. März 2026 · Audit-Batch 001, Antispam-Härtung & Versions-Sync

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.22** | 🔴 fix | Admin/Security | **AntiSpam-Flow gehärtet**: `CMS/admin/antispam.php`, `CMS/admin/modules/security/AntispamModule.php` und `CMS/admin/views/security/antispam.php` verarbeiten Mutationen jetzt mit Action-Allowlist, konsistentem Redirect-/Flash-Flow, generischeren Fehlerantworten und ohne vorbelegtes reCAPTCHA-Secret im Formular. |
| **2.6.22** | 🔴 fix | Core/Backups | **Backup-Pfade und I/O-Failsafes gehärtet**: `CMS/core/Services/BackupService.php` akzeptiert Zielverzeichnisse nur noch innerhalb des Backup-Roots, validiert Backup-Namen, liest Manifeste defensiver ein, folgt beim Löschen keinen Symlinks blind und beseitigt den Mail-Backup-Fehler mit `filesize()` nach dem Löschen der Temp-Datei. |
| **2.6.22** | 🔴 fix | Admin/System | **Backup-Modul leakt keine Rohfehler mehr**: `CMS/admin/modules/system/BackupsModule.php` gibt in der UI nur noch generische Fehlermeldungen aus, statt interne Exception-Texte direkt durchzureichen. |
| **2.6.22** | 🔴 fix | Admin/Themes | **Theme-Dateieditor gegen Traversal und Oversize-Dateien gehärtet**: `CMS/admin/theme-explorer.php`, `CMS/admin/modules/themes/ThemeEditorModule.php` und `CMS/admin/views/themes/editor.php` begrenzen Aktionen, normalisieren Pfade, erzwingen Theme-Root + Größenlimit, ignorieren Symlinks und schreiben Dateien mit `LOCK_EX`. |
| **2.6.22** | 🟡 refactor | Admin/Marketplace | **Marketplace-Entrypoints vereinheitlicht**: `CMS/admin/plugin-marketplace.php` und `CMS/admin/theme-marketplace.php` erlauben nur noch die erwartete Installationsaktion und behandeln CSRF-/Aktionsfehler jetzt konsistent über Redirect + Flash-Alert; die bereits vorhandenen SHA-256-/Allowlist-Gates der Module wurden dabei erneut verifiziert. |
| **2.6.22** | 🔵 docs | Audit | **Inkrementelles Prüfprotokoll eingeführt**: `DOC/audit/ToDoPrüfung.md` dokumentiert die Abarbeitung von `PRÜFUNG.MD` ab jetzt schrittweise; `DOC/audit/BEWERTUNG.md` enthält zusätzlich eine Delta-Sektion für bereits umgesetzte Audit-Batches. |
| **2.6.22** | ⬜ chore | Versionierung | **Release-Quellen wieder synchronisiert**: `CMS/core/Version.php`, `CMS/update.json` und der Changelog-Badge wurden auf denselben Release-Stand gezogen, damit Laufzeit-, Updater- und Doku-Version nicht länger auseinanderlaufen. |

---

### v2.6.21 — 24. März 2026 · Backup-Admin-Fix & Changelog-Konsolidierung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.21** | 🔴 fix | Admin/System | **Leere Backup-Seite behoben**: `CMS/admin/backups.php` setzt vor dem Rendern jetzt denselben `CMS_ADMIN_SYSTEM_VIEW`-Guard wie die übrigen Systemseiten, sodass `/admin/backups` nicht mehr per sofortigem View-`exit` in einer weißen/leeren Seite endet. |
| **2.6.21** | 🎨 style | Admin/UX | **Backup-Alerts wieder sichtbar**: `CMS/admin/views/system/backups.php` rendert Session- und Statusmeldungen jetzt über den gemeinsamen Partial `admin/views/partials/flash-alert.php`, damit Erstellen/Löschen/CSRF-Fehler im UI klar sichtbar werden. |
| **2.6.21** | 🔵 docs | Changelog/Release | **Changelog vollständig vereinheitlicht**: Die gemischten oberen Release-Blöcke wurden auf dasselbe tabellarische Format wie die Historie darunter umgebaut, damit alle Versionen konsistent lesbar und gleich aufgebaut sind. |

---

### v2.6.19 — 24. März 2026 · Device-Cookie-Bindung für Logins

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.19** | 🔴 fix | Core/Auth | **Sessions an Device-Cookie gebunden**: `CMS/core/Auth.php` bindet eingeloggte Sessions jetzt zusätzlich an ein signiertes Device-Cookie `cms_device` mit maximal zwei Stunden TTL; fehlt das Cookie oder passt Signatur/Sitzungsbindung nicht mehr, wird die Session beim nächsten Check sauber invalidiert. |
| **2.6.19** | 🔴 fix | Core/Auth/MFA | **Passkey- und MFA-Logins ziehen mit**: `CMS/core/Auth/AuthManager.php` setzt dieselbe Gerätebindung auch für Passkey- und MFA-abgeschlossene Logins, und Logout räumt Session-Cookie plus Device-Cookie gemeinsam ab. |

---

### v2.6.18 — 24. März 2026 · Upload-Beispiele von Runtime getrennt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.18** | ⬜ chore | Uploads/Docs | **Beispielgrafik aus Runtime-Pfad entfernt**: Die versionierte Datei `SidebarRahmenThumnail_V5_CopilotLizenzen.png` liegt nicht mehr unter `CMS/uploads/`, sondern unter `DOC/assets/examples/`; damit bleibt der Upload-Baum für echte Laufzeitdaten reserviert. |
| **2.6.18** | 🔵 docs | Docs/Assets | **Trennlinie dokumentiert**: `DOC/assets/examples/README.md` hält jetzt explizit fest, dass versionierte Demo-/Referenzdateien in den Doku-/Beispielpfad und nicht in produktive Upload-Verzeichnisse gehören. |

---

### v2.6.17 — 24. März 2026 · Große Editor-Views modularisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.17** | 🟡 refactor | Admin/Views | **Seiten- und Beitragseditor entschlackt**: `CMS/admin/views/posts/edit.php` und `CMS/admin/views/pages/edit.php` delegieren die wiederkehrenden Lesbarkeits-, Vorschau-, SEO-Score- und erweiterten SEO-Blöcke jetzt an gemeinsame Partials unter `CMS/admin/views/partials/`. |
| **2.6.17** | 🟡 refactor | Admin/Partials | **Gemeinsame SEO-/Preview-Bausteine extrahiert**: `content-readability-card.php`, `content-preview-card.php`, `content-seo-score-panel.php` und `content-advanced-seo-panel.php` kapseln die gemeinsamen Admin-Blöcke, ohne bestehende IDs, Form-Felder oder Frontend-Hooks der Editor-/SEO-Logik zu verbiegen. |

---

### v2.6.16 — 24. März 2026 · Media-Delivery mit Range-Streaming

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.16** | 🟠 perf | Core/Media | **Byte-Range-Requests sauber unterstützt**: `CMS/core/Services/MediaDeliveryService.php` verarbeitet jetzt `206 Partial Content`, `416 Range Not Satisfiable`, `Accept-Ranges` und passendes `Content-Range`, damit größere Medien und Resume-/Preview-Clients nicht mehr auf einen Alles-oder-nichts-Download festgenagelt sind. |
| **2.6.16** | 🟠 perf | Core/Streaming | **Auslieferung streamt chunkweise**: Mediendateien werden nun über einen kontrollierten File-Handle in Chunks statt per `readfile()` in einem Rutsch ausgegeben; `HEAD`-Requests liefern die Header ohne Response-Body. |

---

### v2.6.15 — 24. März 2026 · Routing- und Admin-Hotspots verkleinert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.15** | 🟡 refactor | Core/Routing | **`ThemeRouter` delegiert Archivlogik**: Kategorie-/Tag-Archivdaten, Legacy-Tag-Normalisierung und veröffentlichte Archiv-Overviews liegen jetzt im neuen `ThemeArchiveRepository`, wodurch der große Routing-Pfad deutlich schmaler und gezielter testbar bleibt. |
| **2.6.15** | 🟡 refactor | Admin/Posts | **Kategorien-ViewModel ausgelagert**: `CMS/admin/modules/posts/PostsModule.php` nutzt für Kategorienäume, Optionslabels und Admin-Row-Metadaten jetzt den neuen `PostsCategoryViewModelBuilder`, statt diese Logik weiter direkt im Modul zu halten. |
| **2.6.15** | 🟡 refactor | Admin/Hub | **Template-Katalog separiert**: `CMS/admin/modules/hub/HubTemplateProfileManager.php` bezieht Template-Optionen, Presets und Default-Profile jetzt aus `HubTemplateProfileCatalog`; umfangreiche Inline-Kataloge und Default-Helfer sind damit aus dem Hotspot herausgezogen. |

---

### v2.6.14 — 23. März 2026 · Importer weiter zerlegt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.14** | 🟡 refactor | Plugins/Importer | **Importer-Preview und Reporting ausgelagert**: `CMS/plugins/cms-importer/includes/class-importer.php` delegiert Preview-/Planungslogik und Meta-/Reporting jetzt an `trait-importer-preview.php` und `trait-importer-reporting.php`, statt diese Blöcke weiter im Service-Monolithen zu halten. |
| **2.6.14** | 🟡 refactor | Plugins/Importer/Admin | **Admin-Cleanup aus Entry-Point gelöst**: `CMS/plugins/cms-importer/includes/class-admin.php` nutzt Cleanup-/Backfill-/Reporting-Helfer nun über `trait-admin-cleanup.php`; UI-Flows bleiben stabil, während die bislang sehr großen Bereinigungs- und Verlaufsroutinen separat wart- und testbarer werden. |

---

### v2.6.13 — 23. März 2026 · Global-Helper thematisch gesplittet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.13** | 🟡 refactor | Core/Bootstrap | **`includes/functions.php` auf Loader-Rolle reduziert**: `CMS/includes/functions.php` ist jetzt nur noch der kanonische Bootstrap für globale Helfer und lädt die bisherige Sammellogik thematisch getrennt aus `CMS/includes/functions/*.php` nach. |
| **2.6.13** | 🟡 refactor | Core/Helpers | **Helper-Gruppen getrennt wartbar**: Escaping/String-Helfer, Optionen/Archiv-/Runtime, Redirect/Auth, Rollen, Admin-Menüs, Übersetzungen, WP-Kompatibilität und Mail bleiben API-stabil, sind aber jetzt deutlich getrennt wart- und prüfbar. |

---

### v2.6.12 — 23. März 2026 · Installer-Monolith aufgespalten

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.12** | 🟡 refactor | Core/Installer | **`install.php` auf Bootstrap reduziert**: `CMS/install.php` delegiert den mehrstufigen Installer-Ablauf jetzt an einen dedizierten `InstallerController`, statt UI, Datenbank, Konfigurationsschreibzugriffe und Success-Flow weiter in einer Datei zu mischen. |
| **2.6.12** | 🟡 refactor | Core/Installer/Views | **Setup- und View-Logik sauber getrennt**: `InstallerService` kapselt Setup-, Lock-, Config-, Schema- und Datenbanklogik zentral, während die HTML-Schritte unter `CMS/install/views/` als getrennte Views gerendert werden. |

---

### v2.6.11 — 23. März 2026 · HTTPS-/HSTS-Linie vereinheitlicht

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.11** | 🟡 refactor | Security/HTTPS | **Redirect-Verantwortung klargezogen**: Die HTTPS-Strategie ist jetzt verbindlich auf Redirects durch Reverse-Proxy/Webserver ausgerichtet; der ausgelieferte Apache-Fallback normalisiert nur noch Proxy-HTTPS für dieselbe Sicherheitslinie. |
| **2.6.11** | 🟡 refactor | Security/HSTS | **HSTS folgt zentraler HTTPS-Erkennung**: `Security` und die Systemdiagnose weisen die aktive Redirect-Verantwortung jetzt explizit aus und erzeugen HSTS nur noch über eine zentrale HTTPS-/HSTS-Konfiguration mit demselben HTTPS-Erkennungsmodell wie der Apache-Fallback. |
| **2.6.11** | 🔵 docs | Audit/Security | **Device-Cookie als offener Backlogpunkt dokumentiert**: Audit und ToDo führten zusätzlich einen neuen Security-Punkt für ein signiertes, kurzlebiges Login-/Device-Cookie mit, damit Browser-/Gerätebindung nicht als lose Randnotiz hängen bleibt. |

---

### v2.6.10 — 23. März 2026 · Updates atomar gemacht

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.10** | 🔴 fix | Core/Updates | **Updates landen zuerst im Staging**: Core-Updates werden nicht mehr direkt in das Live-Ziel entpackt, sondern zuerst in ein benachbartes Staging-Verzeichnis extrahiert und erst danach per atomarem Verzeichnis-Swap oder rollback-fähigem Inhalts-Swap übernommen. |
| **2.6.10** | 🔴 fix | Core/Rollback | **Halbfertige Installationen verhindert**: Abgebrochene oder fehlschlagende Installationen hinterlassen keine inkonsistenten Update-Zustände mehr; bestehende Inhalte werden vor dem Umschalten in ein temporäres Backup verschoben und bei Fehlern wiederhergestellt. |

---

### v2.6.9 — 23. März 2026 · `session.cookie_secure` an HTTPS gekoppelt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.9** | 🔴 fix | Core/Sessions | **Secure-Flag nur noch bei echtem HTTPS**: `Security::startSession()`, `index.php` und `cron.php` setzen `session.cookie_secure` jetzt nur noch bei tatsächlich erkanntem HTTPS bzw. Proxy-HTTPS statt pauschal immer auf `1`. |
| **2.6.9** | 🔴 fix | Betrieb/Staging | **HTTP-Setups bleiben funktionsfähig**: HTTP-Staging-Setups und CLI-nahe Cron-Läufe verlieren damit nicht mehr unnötig ihre Session-Cookies durch eine erzwungene Secure-Flag auf Nicht-HTTPS-Anfragen. |

---

### v2.6.8 — 23. März 2026 · SSRF-DNS-Fallback gehärtet

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.8** | 🔴 fix | Security/HTTP | **Ungelöste Remote-Hosts werden standardmäßig blockiert**: `CMS\Http\Client` versucht vorab eine echte IPv4/IPv6-Auflösung und lässt ungelöste Hosts nur noch per explizitem `allowUnresolvedHosts`-Opt-in zu. |
| **2.6.8** | 🔴 fix | Core/Updates | **`UpdateService` folgt derselben DNS-Härte**: Sensible Remote-Ziele werden bei fehlender Host-Auflösung nicht mehr stillschweigend durchgewunken. |

---

### v2.6.6 — 23. März 2026 · ZIP-Einträge vor `extractTo()` validiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.6** | 🔴 fix | Admin/System | **GitHub-Doku-Sync validiert ZIP-Inhalte vor dem Entpacken**: ZIP-Einträge werden jetzt vor `extractTo()` auf Traversals, absolute Pfade, NUL-/Steuerzeichen sowie leere oder punktbasierte Segmente geprüft. |

---

### v2.6.5 — 23. März 2026 · Debug-Logs aus Release-Baum herausgezogen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.5** | 🔴 fix | Core/Logging | **Logs liegen standardmäßig außerhalb des Release-Baums**: Debug-Logs landen nicht mehr im `CMS/logs/`-Verzeichnis, sondern über `LOG_PATH`/`CMS_ERROR_LOG` in einem externen Logpfad; Konfig-Writer und `SystemService` nutzen denselben aktiven Pfad. |

---

### v2.6.4 — 23. März 2026 · Audit-Scope sauber konsolidiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.4** | 🔵 docs | Audit/Scope | **`FILEINVENTAR.md` als kanonische Quelle verankert**: Die Audit-Dokumentation nutzt `FILEINVENTAR.md` jetzt konsequent als Scope-Quelle; konkurrierende eingebettete Inventarstände und alte 444-Dateien-Referenzen wurden aus Audit und ToDo entfernt. |

---

### v2.6.3 — 23. März 2026 · Importer-Fetch auf Core-HTTP-Härtung umgestellt

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.3** | 🔴 fix | Plugins/Importer | **Remote-Bilder laufen über den zentralen HTTP-Client**: Der WordPress-Importer lädt Remote-Bilder jetzt mit aktivierter TLS-Prüfung, SSRF-Schutz sowie Größen- und Image-Content-Type-Limits statt über einen ungehärteten Direkt-Fetch. |

---

### v2.6.2 — 23. März 2026 · Audit-Welle, SEO-Ausbau & Release-Abgleich

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.2** | 🟢 feat | Core/SEO | **IndexNow und Archivlogik erweitert**: Die SEO-Linie erweitert die IndexNow-Integration um eine dynamische Keydatei-Auslieferung; Kategorien und Tags unterstützen jetzt mehrsprachige Archivbasen sowie Ersatzkategorien/-tags beim Löschen. |
| **2.6.2** | 🟢 feat | Plugins/Importer | **Importer deutlich ausgebaut**: Der Core bringt einen erweiterten Importer unter `CMS/plugins/cms-importer` mit Meta-Report, Admin-Oberfläche, Styles, JavaScript und Importlogik für größere Importpfade mit. |
| **2.6.2** | 🟡 refactor | Release/Runtime | **`CMS\Version` wieder zentrale Release-Quelle**: Runtime, Installer, Update-Metadaten und sichtbare Versions-Badges wurden auf den konsistenten Stand `2.6.2` nachgezogen. |
| **2.6.2** | 🟡 refactor | Routing/Content | **Routing und Inhaltsauflösung nachgeschärft**: Kategorie-/Tag-Archive, Slug-Validierung in Seiten/Beiträgen sowie die allgemeine Inhaltsauflösung im Frontend wurden in mehreren Wellen weiter konsolidiert. |
| **2.6.2** | 🟡 refactor | Marketplace/Updates | **Update- und Marketplace-Pfade erweitert**: Theme-/Plugin-Verwaltung, Update-Ansichten und die zugrunde liegende `UpdateService`-Logik unterstützen den jüngsten Ausbauzustand deutlich umfangreicher als im Stand `2.6.1`. |
| **2.6.2** | 🟡 refactor | Member/Header | **Admin-Einstieg im Memberbereich eingeblendet**: Der Mitgliederbereich zeigt im Header jetzt gezielt einen Admin-Einstieg an, wenn der aktuelle Nutzer entsprechende Rechte besitzt. |
| **2.6.2** | 🔴 fix | Core/Installer | **Installer hart abgesichert**: `install.php` sperrt bestehende Installationen jetzt per Install-Lock und Admin-Guard für öffentliche Zugriffe; zusätzlich wird das Datenbank-Passwort im Reinstall-Pfad nicht mehr aus der vorhandenen Konfiguration vorbefüllt. |
| **2.6.2** | 🔴 fix | Routing/Archive | **Löschen von Kategorien/Tags robuster gemacht**: Das Löschverhalten bricht bei verknüpften Beiträgen nicht mehr stumpf weg, sondern kann Ziele auf Ersatzkategorien/-tags umlenken; Archiv- und Routingpfade verhalten sich in der mehrsprachigen CMS-Linie robuster. |
| **2.6.2** | 🔵 docs | Audit | **Neuer Audit-Stand 23.03.2026 dokumentiert**: `DOC/audit/AUDIT_23032026_CMS_PHINIT-LIVE.md` hält den CMS- und Live-Site-Prüfstand inklusive öffentlicher PhinIT-Stichprobe fest. |
| **2.6.2** | 🔵 docs | Audit/ToDo | **Nacharbeiten und Scope-Abdeckung nachgezogen**: `DOC/audit/NACHARBEIT_AUDIT_ToDo.md` sowie `DOC/audit/ToDo_Audit_23032026.md` dokumentieren den offenen Release-/Versionsabgleich, Proxy-/CDN-/Tracking-Verifikation und die vollständige First-Party-Dateiabdeckung explizit. |
| **2.6.2** | 🔵 docs | README/Betrieb | **Auditstatus direkt in README verankert**: `README.md` beschreibt den Auditstatus vom `23.03.2026` jetzt direkt im Betriebsabschnitt, damit offene Betriebs- und Sicherheitsbaustellen nicht nur im Audit-Ordner versteckt bleiben. |

---

### v2.6.1 — 17. März 2026 · Redirects, Theme-Polish & Frontend-Härtung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.1** | 🟡 refactor | SEO/Redirects | **Redirect- und `404`-Admin getrennt**: Der SEO-Admin trennt Weiterleitungen und erkannte `404` jetzt in zwei eigenständige Bereiche; neue Redirects lassen sich wieder direkt anlegen und Übernahmen aus dem `404`-Monitor können die passende Site-/Host-Zuordnung mitspeichern. |
| **2.6.1** | 🟡 refactor | Core/RedirectService | **Redirect-Regeln site-spezifisch bewertet**: `RedirectService` arbeitet jetzt host- bzw. pfadbezogen über `site_scope`, protokolliert den anfragenden Host in `404`-Logs mit und verhindert Dubletten nur noch innerhalb desselben Site-Scope statt global über alle Sites hinweg. |
| **2.6.1** | 🟡 refactor | Admin/Content | **Beiträge und Seiten teilen sich Kategorienbasis**: Zusätzlich werden Microsoft-365-Standardkategorien wie Copilot, Teams, SharePoint Online, Exchange Online, Intune, Defender oder Power Platform automatisch zur Auswahl vorgehalten. |
| **2.6.1** | 🟡 refactor | Theme/cms-phinit | **`cms-phinit` modernisiert Header und Assets**: Header, Dark-Mode-Init, Customizer-Logik, Analytics-Loader und Consent-Eventing laufen jetzt deutlich stärker über zentrale, cachebare Assets statt über Inline-Blöcke. |
| **2.6.1** | 🟡 refactor | Theme/365Network | **`365Network`-Customizer und Directory-Templates geglättet**: Admin-Customizer nutzt ausgelagerte Assets; Filter-Selects, Reset-/Listen-Stile und 404-Aktionen hängen an zentralen Klassen/Data-Attributen statt an Inline-Handlern. |
| **2.6.1** | 🔴 fix | Routing/Public | **`HEAD`-Requests für Public-Routen korrigiert**: Monitoring-, Header-Checks und SEO-Tools laufen für Pfade wie `/feed`, `/forgot-password` oder `/.well-known/security.txt` nicht mehr fälschlich in `404`. |
| **2.6.1** | 🔴 fix | Auth/Recovery | **Recovery-Seiten senden private Cache-Header**: Sensible Pfade wie `/forgot-password` verwenden jetzt dieselbe private/no-store-Cache-Strategie wie Login- und Registrierungsseiten. |
| **2.6.1** | 🔴 fix | Feed/RSS | **RSS-Descriptions liefern robusten Plaintext**: Editor.js-Inhalte werden nicht mehr als rohe oder abgeschnittene JSON-Blockpayloads an Feed-Reader gereicht; auch unvollständige JSON-Fragmente liefern wieder lesbaren Text. |
| **2.6.1** | 🔴 fix | Cron/Feeds | **`cms_cron_hourly` wird wieder wirklich ausgelöst**: `CMS/cron.php` stößt den bislang nur registrierten Hook kompatibel an und drosselt ihn intern auf höchstens einen echten Lauf pro Stunde, sodass `cms-feed`-Fetch-Queue und Feed-Digests wieder automatisch nachziehen. |
| **2.6.1** | 🔴 fix | Plugins/cms-contact | **Verbleibende Admin-Views auf zentrale Assets umgestellt**: Filter, Template-Auswahl, Modale, Statuswechsel und Sammelaktionen bleiben funktional, kommen aber ohne zusätzliche Inline-Styles/-Scripts aus. |
| **2.6.1** | 🔴 fix | Plugins/cms-feed | **Feed-Pfade und Admin-UI inline-frei gemacht**: Public-JavaScript lädt jetzt auf allen echten Feed-Routen inklusive Consent-Sperrseite, und der große Admin-View `page-admin.php` kommt ohne direkte `onclick`-/`confirm`-Handler oder `javascript:void(0)`-Links aus. |
| **2.6.1** | 🔴 fix | Plugins/cms-events | **Admin-, Meta-Box-, Member- und Kalenderpfade entinline-ifiziert**: Bestätigungen, Modalsteuerung, Preview-Syncs, Formular-Toggles und Monatsnavigation hängen nun an zentralen Assets bzw. echten Navigationslinks. |
| **2.6.1** | 🔴 fix | Theme/cms-phinit | **Customizer und Tracking ohne Inline-Skripte**: Font-Preview-Styles, Analytics-Loader sowie verbleibende `onclick`-/`oninput`-Handler wurden in zentrale Admin-/Theme-Assets überführt. |
| **2.6.1** | 🔴 fix | Admin/Bulk-Editing | **Bulk-Bearbeitung für Seiten und Beiträge erweitert**: Kategorien lassen sich jetzt setzen oder entfernen; Seiten unterstützen erstmals auch eine eigene Einzelbearbeitung per Kategorieauswahl und Listenfilter. |
| **2.6.1** | 🟢 feat | Core/Routing | **`security.txt` unter zwei Standardpfaden verfügbar**: `ThemeRouter` liefert jetzt `security.txt` sowohl unter `/security.txt` als auch unter `/.well-known/security.txt` mit Kontakt, Canonical, Sprachenhinweis und Ablaufdatum aus. |
| **2.6.1** | 🔵 docs | Audit/Theme/Security | **Doku auf PhinIT-Live-Nacharbeit nachgezogen**: Audit-, Sicherheits- und Theme-Dokumentation spiegeln jetzt `security.txt`, Forgot-Password-Recovery, Feed-Härtung, Redirect-/404-Admin, site-spezifische Redirect-Scopes, Tabellen-Darkmode und bereinigte `cms-contact`-Views wider. |

---

### v2.6.0 — 16. März 2026 · Permalink-, Error-Report- und Redaktionsausbau

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.6.0** | 🟢 feat | Core/Routing | **`PermalinkService` zentralisiert Beitrags-URL-Strukturen**: Slug-Extraktion, URL-Schemata und Migrationspfade für beitragsbezogene Router- und Theme-Pfade laufen jetzt über einen dedizierten Service. |
| **2.6.0** | 🟢 feat | Admin/Error-Reporting | **Persistente Admin-Fehlerreports eingeführt**: `ErrorReportService` und `/admin/error-report` führen Audit-Log, Kontextdaten und einen CSRF-geschützten Redirect-Flow für nachvollziehbare Fehlerreports ein. |
| **2.6.0** | 🟢 feat | Admin/Editorial | **Neue Redaktions-Einstiege ergänzt**: Eigenständige CRUD-Ansichten für Beitrags-Kategorien, Beitrags-Tags und Tabellen-Display-Defaults erweitern den Redaktionsbereich. |
| **2.6.0** | 🟡 refactor | Theme/Rendering | **Theme-Dateien rendern in isoliertem Scope**: Werte aus einem Render-Kontext sickern nicht mehr unbeabsichtigt in andere Templates durch. |
| **2.6.0** | 🟡 refactor | Routing/Schema | **Archiv-, Sitemap- und Hub-Pfade erweitert**: Routing-, Redirect- und Hub-/Schema-Pfade wurden für Archiv- und Sitemap-Routen, URL-Nachmigrationen und robustere Flag-Verwaltung in `SchemaManager` und `MigrationManager` nachgeschärft. |
| **2.6.0** | 🔴 fix | Kommentare/Admin-JSON | **Kommentar- und Admin-JSON-Pfade stabilisiert**: Eingeloggte Nutzer füllen Kommentarformulare zuverlässiger vor, Moderation meldet Erfolg verlässlich zurück und Admin-/AJAX-Endpunkte für Posts, Seiten, Nutzer und Medien reagieren konsistenter. |

### v2.5.30 — 11. März 2026 · Standard-Theme-Home-Split, Partials & Audit-Sync

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.30** | 🟡 refactor | Theme/Standard-Theme | **Startseiten-Orchestrator drastisch verkleinert**: `CMS/themes/cms-default/home.php` lädt nur noch Daten und delegiert anschließend an die spezialisierten Partials `partials/home-landing.php` und `partials/home-blog.php`. |
| **2.5.30** | 🟡 refactor | Theme/Frontend | **Landing- und Blog-Markup sauber getrennt**: Die frühere Mischdatei wurde in `partials/home-landing.php` (Landing-Logik, CTA, Footer-Callout) und `partials/home-blog.php` (Hero, Listen, Sidebar) aufgeteilt, wodurch Theme-Anpassungen deutlich lokalere Änderungen erlauben. |
| **2.5.30** | 🟢 feat | Core/Quality Gates | **Architektur-Suite bestätigt den Theme-Split**: `php tests/architecture/run.php` läuft nach dem Split erfolgreich durch; `home.php` liegt jetzt bei 131 LOC statt als weiterer großer Theme-Monolith im Laufzeitpfad zu bleiben. |
| **2.5.30** | 🔵 docs | Audit/Release | **Audit- und Bewertungsstand nachgezogen**: Die Standard-Homepage gilt nicht mehr als dominanter Restblock; `AUDIT_FACHBEREICHE.md`, `AUDIT_BEWERTUNG.md` und `AUDIT_09032026.md` verschieben den Restdruck nun stärker auf große CSS-/Admin-Dateien und Proxy-/CDN-Realvalidierung. |



### v2.5.29 — 11. März 2026 · Release-Smoke-Disziplin, Beta-Pflichtpfade & Audit-Sync

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.29** | 🟢 feat | Core/Quality Gates | **Verbindliche Release-Smoke-Suite ergänzt**: `tests/release-smoke/manifest.php` und `tests/release-smoke/run.php` halten jetzt Public-, Auth-, Member-, Admin- und Fehlpfade inklusive historischer Retests als reproduzierbaren Repo-Standard fest. |
| **2.5.29** | ⬜ chore | CI/Release | **CI prüft die Release-Disziplin automatisch mit**: `.github/workflows/security-regression.yml` führt die neue Release-Smoke-Suite jetzt zusammen mit Security-, Architektur-, Vendor- und Doku-Sync-Checks aus. |
| **2.5.29** | 🔵 docs | Workflow/Release | **Deployment-Leitfaden enthält jetzt feste Beta-Stichprobe**: `DOC/workflow/UPDATE-DEPLOYMENT-WORKFLOW.md` definiert eine verbindliche Phase „Beta-Smoke nach Deployment“ mit Pflichtbefehl `php tests/release-smoke/run.php`, Pflichtpfaden und zusätzlichen Browser-/Log-Prüfungen. |
| **2.5.29** | 🔵 docs | Audit/Release | **Audit- und Bewertungsstand nachgezogen**: Die Beta-Smoke-Welle gilt als erledigt, `AUDIT_BEWERTUNG.md` hebt die Betriebs-/Release-Reife an und der nächste offene Schwerpunkt verschiebt sich auf große Theme-/Admin-Dateien sowie Proxy-/CDN-Realvalidierung. |

---

### v2.5.28 — 11. März 2026 · Marketplace-Integrität, SHA-256-Gates & Audit-Sync

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.28** | 🔴 fix | Marketplace/Supply Chain | **Auto-Installationen erzwingen jetzt Integritätsmetadaten**: `PluginMarketplaceModule` und `ThemeMarketplaceModule` erlauben automatische Installationen nur noch bei vorhandener Paket-URL, erlaubtem Zielhost und gültiger SHA-256-Prüfsumme statt allein aufgrund eines Download-Links. |
| **2.5.28** | 🔴 fix | Marketplace/Updates | **ZIP-Downloads werden vor dem Entpacken aktiv verifiziert**: Marketplace-Pakete werden über `UpdateService::verifyDownloadIntegrity()` gegen ihre erwartete Prüfsumme geprüft; bei fehlender oder falscher SHA-256 wird die Installation sauber abgebrochen. |
| **2.5.28** | 🎨 style | Admin/Marketplace | **Marketplace-UI trennt verifizierte von manuellen Paketen sichtbar**: Plugin- und Theme-Ansichten zeigen jetzt explizite Prüfsummen-/Warnhinweise und markieren Einträge ohne Integritätsmetadaten als „Nur manuell“, statt ihnen still denselben Installationspfad zu geben. |
| **2.5.28** | 🟢 feat | Core/Quality Gates | **Security-Suite sichert SHA-256-Gates regressionsseitig ab**: `tests/security/run.php` prüft jetzt zusätzlich, dass Plugin- und Theme-Marketplace Auto-Installationen ohne gültige 64-stellige SHA-256 nicht freigeben. |
| **2.5.28** | 🔵 docs | Audit/Release | **Audit- und Bewertungsstand nachgezogen**: Die Marketplace-/Supply-Chain-Welle gilt als erledigt, `AUDIT_BEWERTUNG.md` hält die Integritätsprüfung mit **99/100 Punkten** fest und der nächste offene Schwerpunkt rückt auf feste Beta-Smoke-Disziplin sowie verbleibende Proxy-/CDN-Realtests. |

---

### v2.5.27 — 11. März 2026 · Bootstrap-Profil-Messung, Cold-Path-Transparenz & Audit-Sync

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.27** | 🟠 perf | Core/Bootstrap | **Cold-Path-Profil startet jetzt vor dem Dependency-Load**: `Debug::resetRuntimeProfile()` läuft bereits vor `loadDependencies()`, sodass Dependency-Load, Plattformprüfung und Migrationslauf erstmals sauber in der Bootstrap-Zeit landen statt unsichtbar vor dem Messstart zu verschwinden. |
| **2.5.27** | 🟢 feat | Admin/System | **Diagnose zeigt aktives Bootstrap-Profil pro Modus**: `/admin/diagnose` wertet das neue Profil jetzt als eigene Ansicht mit Modus, Kaltstart bis `bootstrap.ready`, Post-Bootstrap-Zeit, Cold-Path-Anteil und teuersten Bootstrap-Phasen für CLI/API/Admin/Web aus – auch ohne aktiviertes `CMS_DEBUG`. |
| **2.5.27** | 🟢 feat | Core/Quality Gates | **Runtime- und Architektur-Suiten sichern Profilierung ab**: `tests/runtime-telemetry/run.php` prüft jetzt das leichtgewichtige Bootstrap-Profil explizit auch ohne Debug-Modus, und `tests/architecture/run.php` hält frühe Messung sowie Diagnose-Sichtbarkeit regressionsseitig fest. |
| **2.5.27** | 🔵 docs | Audit/Release | **Audit- und Bewertungsstand nachgezogen**: Die offene Messwelle für Bootstrap-Profile gilt als erledigt, `AUDIT_BEWERTUNG.md` steigt auf **99/100 Punkte** und der nächste offene Schwerpunkt rückt auf Marketplace-/Supply-Chain-Härtung sowie Proxy-/CDN-Realtests. |

---

### v2.5.26 — 11. März 2026 · Registry-Diagnose, Bundle-Transparenz & Audit-Sync

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.26** | 🟢 feat | Core/Diagnose | **`VendorRegistry` als Diagnosequelle erweitert**: `getDiagnostics()` exportiert jetzt Assets-Autoloader-Kandidaten, registrierte Produktivpakete, gebündelte Runtime-Libraries und die Symfony-Manifest-/PHP-Plattformprüfung zentral statt diese Informationen nur implizit in Bootstrap- und Servicepfaden zu verstecken. |
| **2.5.26** | 🟢 feat | Admin/System | **Diagnose macht Registry-/Bundle-Status sichtbar**: `SystemInfoModule` speist die Registry-Daten in `/admin/diagnose` ein; `admin/views/system/diagnose.php` zeigt Autoloader, Produktivpakete, Asset-Bundles und Plattformwarnungen direkt im Admin an. |
| **2.5.26** | 🟢 feat | Core/Quality Gates | **Architekturregel für Diagnose-Sichtbarkeit ergänzt**: `tests/architecture/run.php` prüft jetzt regressionsseitig, dass `VendorRegistry`, `SystemInfoModule` und die Diagnose-View die Vendor-/Asset-Registry weiterhin sichtbar anbinden. |
| **2.5.26** | 🔵 docs | Audit/Release | **Audit- und Bewertungsstand nachgezogen**: Die Registry-/Dependency-/Asset-Diagnosewelle gilt als erledigt, `AUDIT_BEWERTUNG.md` steigt auf **98/100 Punkte** und der nächste offene Schwerpunkt verschiebt sich auf Marketplace-/Supply-Chain-Härtung sowie Proxy-/CDN-Realtests. |

---

### v2.5.25 — 11. März 2026 · Layout-Shell-Reuse, Subnav-Zentralisierung & Audit-Sync

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.25** | 🟡 refactor | Admin/Layout | **Gemeinsame Admin-Section-Shell eingeführt**: `admin/partials/section-page-shell.php` bündelt den gemeinsamen Auth-/CSRF-/Alert-/Render-Ablauf für `performance-page.php`, `member-dashboard-page.php` und `system-monitor-page.php`, statt diese Wrapper separat mit nahezu identischem Seiten-Skelett zu pflegen. |
| **2.5.25** | 🟡 refactor | Admin/Views | **Button-Subnav zentralisiert**: `admin/views/partials/section-subnav.php` rendert jetzt die wiederkehrende Navigation für Performance-, Member- und System-Unterseiten; die drei bisherigen Subnav-Partials liefern nur noch ihre Konfiguration statt eigenes Markup. |
| **2.5.25** | 🟢 feat | Core/Quality Gates | **Architekturregel für Layout-Reuse ergänzt**: `tests/architecture/run.php` prüft jetzt regressionsseitig, dass die zentralen Section-Seiten und Subnavs die gemeinsamen Layout-Bausteine weiterverwenden. |
| **2.5.25** | 🔵 docs | Audit/Release | **Audit- und Bewertungsstand nachgezogen**: Die Layout-/Shell-Wiederverwendungswelle gilt als erledigt, `AUDIT_BEWERTUNG.md` steigt auf **97/100 Punkte** und der nächste offene Schwerpunkt verschiebt sich auf Registry-/Diagnose-Ausbau, Marketplace-Härtung und echte Proxy-/CDN-Realtests. |

---

### v2.5.24 — 10. März 2026 · Content-/Hub-View-Glättung, Asset-Split & Audit-Sync

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.24** | 🟡 refactor | Admin/Views | **Seiten-, Beitrags- und Hub-Editoren entlastet**: `admin/views/pages/edit.php`, `admin/views/posts/edit.php` und `admin/views/hub/edit.php` liefern ihre Bedienlogik nicht mehr als große Inline-Skriptblöcke, sondern nur noch als Konfiguration + Markup für zentrale Admin-Assets. |
| **2.5.24** | 🟢 feat | Core/Quality Gates | **Zentrale Admin-Assets + Architekturregel ergänzt**: `admin-content-editor.js` und `admin-hub-site-edit.js` bündeln die Editor-/Hub-Interaktionen, während `tests/architecture/run.php` regressionsseitig erzwingt, dass diese Views inline-scriptfrei bleiben und die Entry-Points die Assets weiter anbinden. |
| **2.5.24** | 🔵 docs | Audit/Release | **Audit- und Bewertungsstand nachgezogen**: Die Hub-/Content-View-Welle gilt als erledigt, `AUDIT_BEWERTUNG.md` steigt auf **96/100 Punkte** und der nächste offene Schwerpunkt verschiebt sich auf Layout-/Shell-Wiederverwendung, Registry-/Diagnose-Ausbau und Proxy-/CDN-Realtests. |

---

### v2.5.23 — 10. März 2026 · Legacy-Cache-Pfade, Header-Härtung & Audit-Sync

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.23** | 🔴 fix | Performance/Headers | **Standalone-Entry-Points an zentrale Cache-Policy angeglichen**: `config.php`, `install.php`, `cron.php` und der Installer-Redirect in `orders.php` senden jetzt ebenfalls private/no-store-Header über `CacheManager::sendResponseHeaders('private')`. |
| **2.5.23** | 🟢 feat | Core/Quality Gates | **Architektur-Suite überwacht Legacy-Header mit**: `tests/architecture/run.php` prüft jetzt zusätzlich, dass die verbliebenen Standalone-Entry-Points ihre privaten Cache-Header nicht wieder verlieren. |
| **2.5.23** | 🔵 docs | Audit/Release | **Audit- und Bewertungsstand nachgezogen**: Die Cache-/Legacy-Randpfad-Welle gilt als erledigt, `AUDIT_BEWERTUNG.md` steigt auf **95/100 Punkte** und der nächste offene Schwerpunkt verschiebt sich auf Hub-/Content-Views, Registry-/Diagnose-Ausbau und Proxy-/CDN-Realtests. |

---

### v2.5.22 — 10. März 2026 · Host-Allowlisten, Remote-Härtung & Audit-Sync

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.22** | 🔴 fix | Security/Remote | **Sensible Remote-Ziele enger begrenzt**: `UpdateService`, `PluginMarketplaceModule`, `ThemeMarketplaceModule` und `DocumentationSyncDownloader` akzeptieren für Update-, Marketplace- und Doku-Downloads jetzt nur noch explizite Zielhosts wie `365network.de`, GitHub-, GitHubusercontent- und Codeload-Ziele. |
| **2.5.22** | 🟢 feat | Core/Quality Gates | **Security-Suite um Host-Allowlists erweitert**: `tests/security/run.php` prüft jetzt zusätzlich, dass fremde Update-, Marketplace- und Doku-Hosts blockiert werden, während legitime Zielräume funktionsfähig bleiben. |
| **2.5.22** | 🔵 docs | Audit/Release | **Audit- und Bewertungsstand nachgezogen**: Die Host-Allowlist-Welle gilt als erledigt, `AUDIT_BEWERTUNG.md` steigt auf **94/100 Punkte** und der nächste offene Schwerpunkt verschiebt sich auf restliche Cache-/Legacy-Randpfade sowie Supply-Chain-Feinschliff. |

---

### v2.5.21 — 10. März 2026 · Vendor-Netzwerkmonitoring, Audit-Sync & Release-Nachzug

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.21** | 🟢 feat | Core/Quality Gates | **Vendor-/Drittpfad-Netzwerkmonitor ergänzt**: `tests/vendor-network-monitoring/run.php` prüft bekannte Remote-Primitiven in `CMS/assets/` und `CMS/vendor/` jetzt gegen eine explizite Allowlist und macht neue Drittpfade sofort sichtbar. |
| **2.5.21** | ⬜ chore | CI/Monitoring | **Security-Workflow erweitert**: `.github/workflows/security-regression.yml` führt den Vendor-Monitor jetzt automatisch mit aus; `DOC/assets/VENDOR-NETWORK-PATHS.md` dokumentiert die beobachteten Drittpfade getrennt vom Eigencode. |
| **2.5.21** | 🔵 docs | Audit/Release | **Audit- und Bewertungsstand nachgezogen**: Vendor-/Drittpfad-Monitoring gilt als erledigter Restblock, `AUDIT_BEWERTUNG.md` steigt auf **93/100 Punkte** und der nächste offene Schwerpunkt liegt klar auf engen Host-Allowlisten für sensible Remote-Ziele. |

---

### v2.5.20 — 10. März 2026 · Security-Regressionssuite, ZIP-Pakethärtung & Release-Nachzug

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.20** | 🔴 fix | Marketplace/Updates | **ZIP-Pakete gegen Pfad-Traversal gehärtet**: `PluginMarketplaceModule`, `ThemeMarketplaceModule` und `UpdateService` validieren Archiv-Einträge jetzt vor dem Entpacken und blockieren unsichere `../`- bzw. absolute Pfade, bevor ein Paket ins Dateisystem greifen darf. |
| **2.5.20** | 🟢 feat | Core/Quality Gates | **Security-Suite deutlich verbreitert**: `tests/security/run.php` prüft jetzt zusätzlich GitHub-API-Host-Disziplin, localhost-Blockaden für Remote-Ziele sowie ZIP-Traversal in Marketplace-/Update-Paketen. |
| **2.5.20** | 🔵 docs | Audit/Release | **Audit-Bewertung und Nacharbeitsstand nachgezogen**: Die Security-Regressionssuite gilt als abgearbeiteter Restblock, `AUDIT_BEWERTUNG.md` steigt auf **92/100 Punkte** und die verbleibenden offenen Themen fokussieren sich jetzt stärker auf Vendor-/Allowlist-/Legacy-Ränder. |

---

### v2.5.19 — 10. März 2026 · Sonderpfad-Härtung, Audit-Konsolidierung & Release-Nachzug

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.19** | 🔴 fix | Core/Admin I/O | **Verbleibende Sonderpfade explizit gehärtet**: `FeedService`, `ElfinderService`, `PurifierService`, `ImageProcessor`, `SeoSitemapService`, `FontManagerModule` und `PerformanceModule` behandeln Datei-, Temp-, GD- und Verzeichnisfehler jetzt explizit statt sie still per `@...` wegzudrücken. |
| **2.5.19** | 🟢 feat | Core/Quality Gates | **Security-Regression für ungültige Bildpfade ergänzt**: `tests/security/run.php` prüft zusätzlich, dass `ImageProcessor` kaputte Bilddateien sauber als `WP_Error` zurückweist. |
| **2.5.19** | 🔵 docs | Audit/Release | **Audit-Berichte auf sechs Kern-Dateien konsolidiert**: Die früheren Einzelberichte für Core, Feature, Performance und Security wurden in `DOC/audit/AUDIT_FACHBEREICHE.md` zusammengeführt; historische Testblocker sind jetzt in `AUDIT_TESTS_ToDo.md` konsolidiert und die Release-/Bewertungsdoku spiegelt den Stand mit **91/100 Punkten** wider. |

---

### v2.5.18 — 10. März 2026 · Media-Delivery-Härtung, Audit-Sync & Release-Nachzug

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.18** | 🟡 refactor | Core/Media | **Private Medienauslieferung zentralisiert**: `MediaDeliveryService` bündelt jetzt die kontrollierte Auslieferung privater Member-Dateien über `GET /media-file`, normalisiert lokale Upload-URLs delivery-aware und versorgt Preview-/Download-Pfade mit passender Cache-Policy, `Last-Modified` und Rollen-/Owner-Prüfung. |
| **2.5.18** | 🔴 fix | Uploads/UX+Security | **Upload-Auslieferung sauber balanciert**: `MediaService::syncUploadsProtection()` hält Attachment + `nosniff` weiter als Standard, erlaubt sichere Bildtypen aber gezielt wieder inline; Media-Library, EditorJS sowie Featured-Image-Previews nutzen dafür jetzt delivery-aware Preview-/Access-URLs statt roher Upload-Links. |
| **2.5.18** | 🟢 feat | Core/Quality Gates | **Neue Media-Delivery-Regression ergänzt**: `tests/media-delivery/run.php` prüft Route, URL-Normalisierung, Member-Schutz und `.htaccess`-Header-Strategie; der Security-Workflow führt die Suite jetzt automatisch mit aus. |
| **2.5.18** | 🔵 docs | Audit/Release | **Audit- und Release-Spiegel nachgezogen**: Nacharbeitsliste, Fach-Audits, Bewertungsmatrix, Test-Checkliste, Changelog und Versions-Fallbacks spiegeln die abgeschlossene Medien-/Bild-/Proxy-Härtung jetzt konsistent wider. |

---

### v2.5.17 — 10. März 2026 · DocumentationSyncService-Split, Audit-Sync & Release-Nachzug

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.17** | 🟡 refactor | Admin/System | **`DocumentationSyncService` weiter entschärft**: Der frühere 545-LOC-Doku-Sync-Block ist jetzt ein schlanker Orchestrator (`71 LOC`) über `DocumentationSyncEnvironment`, `DocumentationSyncFilesystem`, `DocumentationSyncDownloader`, `DocumentationGitSync` und `DocumentationGithubZipSync`; Environment-Probing, Git-Sync, GitHub-ZIP-Download und Dateisystem-Swap sind sauber getrennt. |
| **2.5.17** | 🟢 feat | Core/Quality Gates | **Neue Architekturabsicherung für den Doku-Sync**: `tests/documentation-sync-service/run.php` prüft den Split regressionsseitig, der Workflow führt den Check jetzt automatisch mit aus und der frühere Sync-Monolith bleibt damit unter Dauerbeobachtung. |
| **2.5.17** | ⬜ chore | Versionierung | **Release-Synchronisierung nachgezogen**: Badge, Installer, API-/Dashboard-Fallbacks, Landing-Defaults, Update-Metadaten, Audit-Spiegel und Core-Konstanten wurden auf `2.5.17` angehoben. |

---

### v2.5.16 — 10. März 2026 · media-proxy-Abbau, Audit-Sync & Release-Nachzug

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.16** | 🟡 refactor | Core/Routing | **`media-proxy.php` vollständig abgebaut**: Die physische Legacy-Datei wurde entfernt; `GET /media-proxy.php` leitet jetzt zentral über `PublicRouter` auf `/member/media`, `POST /media-proxy.php` delegiert an `FileUploadService::handleUploadRequest()` und die Apache-Sonderbehandlung in `.htaccess` ist verschwunden. |
| **2.5.16** | 🟢 feat | Core/Quality Gates | **Neue Regression für den Legacy-Abbau**: `tests/media-proxy/run.php` prüft das Entfernen der Datei, die zentrale Router-Übernahme und den fehlenden Apache-Bypass; der Workflow führt den Check automatisch mit aus. |
| **2.5.16** | ⬜ chore | Versionierung | **Release-Synchronisierung nachgezogen**: Badge, Installer, API-/Dashboard-Fallbacks, Landing-Defaults, Update-Metadaten und Core-Konstanten wurden auf `2.5.16` angehoben. |

---

### v2.5.15 — 10. März 2026 · EditorJsMedia-Split, Audit-Sync & Release-Nachzug

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.15** | 🟡 refactor | Core/EditorJs | **`EditorJsMediaService` weiter entschärft**: Der frühere 545-LOC-Medienkern ist jetzt ein schlanker Orchestrator (`87 LOC`) über `EditorJsRequestGuard`, `EditorJsUploadService`, `EditorJsRemoteMediaService` und `EditorJsImageLibraryService`; Guard-, Upload-, Remote-Fetch- und Bibliothekslogik sind sauber getrennt. |
| **2.5.15** | 🟢 feat | Core/Quality Gates | **Neue Architekturabsicherung für EditorJs-Media**: `tests/editorjs-media-service/run.php` prüft den Split regressionsseitig, der Workflow führt den Check jetzt automatisch mit aus und der EditorJs-Medienpfad bleibt damit dauerhaft unter Monolithenaufsicht. |
| **2.5.15** | ⬜ chore | Versionierung | **Release-Synchronisierung nachgezogen**: Badge, Installer, API-/Dashboard-Fallbacks, Landing-Defaults, Update-Metadaten und Core-Konstanten wurden auf `2.5.15` angehoben. |

---

### v2.5.14 — 10. März 2026 · LandingSection-Split, Audit-Sync & Release-Nachzug

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.14** | 🟡 refactor | Core/Landing | **`LandingSectionService` weiter entschärft**: Der frühere 674-LOC-Landing-Kern ist jetzt ein schlanker Orchestrator (`129 LOC`) über `LandingDefaultsProvider`, `LandingHeaderService`, `LandingFeatureService` und `LandingSectionProfileService`; Defaults, Header/Farben, Feature-Migrationen sowie Footer-/Content-/Settings-/Design-Logik sind sauber getrennt. |
| **2.5.14** | 🟢 feat | Core/Quality Gates | **Neue Architekturabsicherung für Landing-Sections**: `tests/landing-section-service/run.php` prüft den Split regressionsseitig, der Workflow führt den Check jetzt automatisch mit aus und der Landing-Kern bleibt damit dauerhaft unter Monolith-Verdacht statt wieder darunter begraben zu werden. |
| **2.5.14** | ⬜ chore | Versionierung | **Release-Synchronisierung nachgezogen**: Badge, Installer, API-/Dashboard-Fallbacks, Landing-Defaults, Update-Metadaten und Core-Konstanten wurden auf `2.5.14` angehoben. |

---

### v2.5.13 — 10. März 2026 · SeoMeta-Split, Audit-Sync & Release-Nachzug

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.13** | 🟡 refactor | Core/SEO | **`SeoMetaService` weiter entschärft**: Der frühere 678-LOC-Meta-Kern ist jetzt ein schlanker Orchestrator (`89 LOC`) über `SeoSettingsStore`, `SeoMetaRepository`, `SeoSchemaRenderer`, `SeoAnalyticsRenderer` und `SeoHeadRenderer`; Settings, Persistenz, Schema, Analytics und Head-Rendering sind sauber getrennt. |
| **2.5.13** | 🟢 feat | Core/Quality Gates | **Neue Architekturabsicherung für SEO-Meta**: `tests/seo-meta-service/run.php` prüft den Split regressionsseitig, der Workflow führt den Check jetzt automatisch mit aus und die generische Architektur-Suite grandfathert `SeoMetaService.php` nicht länger als Ausnahme. |
| **2.5.13** | ⬜ chore | Versionierung | **Release-Synchronisierung nachgezogen**: Badge, Installer, API-/Dashboard-Fallbacks, Landing-Defaults, Update-Metadaten und Core-Konstanten wurden auf `2.5.13` angehoben. |

---

### v2.5.12 — 10. März 2026 · SiteTable-Split, Audit-Sync & Release-Nachzug

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.12** | 🟡 refactor | Core/SiteTable | **`SiteTableService` konsequent entschärft**: Der frühere 1065-LOC-Großservice ist jetzt ein schlanker Orchestrator (`128 LOC`) über `SiteTableRepository`, `SiteTableTemplateRegistry`, `SiteTableHubRenderer` und `SiteTableTableRenderer`; Rendering-, Persistenz-, Hub- und Exportlogik sind sauberer voneinander getrennt. |
| **2.5.12** | 🟢 feat | Core/Quality Gates | **Neue Architekturabsicherung für SiteTable**: `tests/site-table-service/run.php` prüft den Split regressionsseitig, der Workflow führt den Check jetzt automatisch mit aus und die generische Architektur-Suite grandfathert `SiteTableService.php` nicht länger als Ausnahme. |
| **2.5.12** | ⬜ chore | Versionierung | **Release-Synchronisierung nachgezogen**: Badge, Installer, API-/Dashboard-Fallbacks, Landing-Defaults, Update-Metadaten und Core-Konstanten wurden auf `2.5.12` angehoben; nebenbei wurde auch ein veralteter `2.5.4`-Fallback im API-Router beseitigt. |

---

### v2.5.11 — 10. März 2026 · Audit-Härtung, Runtime-Fixes & Release-Stabilisierung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.11** | 🔴 fix | Core/Auth+Security | **Mehrfachformular-CSRF und Login-Fehlerpfade stabilisiert**: wiederverwendbare CSRF-Tokens für identische Actions verhindern falsche Ablehnungen auf Multi-Form-Seiten; Login-/Passkey-Logging ruft `cms_log()` nun mit korrekter Signatur auf. |
| **2.5.11** | 🔴 fix | Member/Media | **Member-Medienpfade und Seitenbild-Uploads gehärtet**: persönliche Upload-Basispfade werden bei Bedarf automatisch erzeugt, und Upload-Metadaten greifen korrekt auf `Auth::getCurrentUser()` statt auf eine nicht existierende Methode zu. |
| **2.5.11** | 🔴 fix | Frontend/Kommentare | **Kommentarfluss Ende-zu-Ende repariert**: `POST /comments/post` ist sauber im Public-Router registriert, Frontend-Kommentare landen wieder in der Moderation, die Admin-Einzelfreigabe sendet nun den korrekten Status `approved`, und das Aktionsmenü scrollt nicht mehr im Tabellen-Overflow fest. |
| **2.5.11** | 🔴 fix | Admin/System+Users | **Mehrere produktive Testblocker beseitigt**: TOC-Speichern (`HY093`), Gruppenverwaltung (`execute()` statt falschem `query()`), Benutzeranlage ohne `CMS\Services\is_wp_error()`-Fatal sowie fehlendes `site_tables`-Schema inklusive Runtime-Nachzug sind bereinigt. |
| **2.5.11** | 🔴 fix | Admin/Runtime | **Leere und fatale Admin-/Theme-Pfade bereinigt**: Sidebar-/Dashboard-Nullwerte, 404-Headerwarnungen sowie früher leere Admin-Views wie `redirect-manager`, `mail-settings`, `updates` und `documentation` rendern wieder robust und kontextsicher. |
| **2.5.11** | 🟡 refactor | Core/Architektur | **Audit-Refactor-Welle umgesetzt**: Router-, Media-, SEO-, Landing-, EditorJs-, Hub-, Theme-Customizer-, Theme-Functions- und zuletzt Documentation-Module wurden weiter in kleinere Verantwortungsbereiche zerlegt; `DocumentationModule` delegiert nun an Katalog-, Render- und Sync-Services statt selbst alles zu schleppen. |
| **2.5.11** | 🟠 perf | Core/Performance | **Bootstrap-, Cache- und Diagnosepfade ausgebaut**: proxy-freundliche Cache-Header (`s-maxage`, `stale-if-error`, `Surrogate-Control`), robustes `Vary`-Merging, OPcache-Warmup der 30 größten PHP-Dateien, echte Core-Web-Vitals-Felddaten sowie Debug-Runtime-/Query-Telemetrie verbessern Messbarkeit und Kaltstartverhalten. |
| **2.5.11** | 🟢 feat | Core/Observability | **Nutzungs- und Runtime-Metriken erweitert**: Admin-/Member-Funktionsnutzung wird datensparsam erfasst, SEO-Analytics zeigt echte Feature-Nutzung, und die Diagnoseschiene liefert Query-Zähler, langsame SQLs und Runtime-Checkpoints. |
| **2.5.11** | 🟢 feat | Core/Quality Gates | **Regressions- und Architekturtests deutlich verbreitert**: neue Checks für Architekturregeln, Contract-Grenzen, HTTP-Cache-Profile, Runtime-Telemetrie, Rollen/Capabilities, Router-Fallbacks, Admin-View-Guards, Medien-Defaults und Kommentarstatus laufen jetzt reproduzierbar im Workflow mit. |
| **2.5.11** | 🎨 style | Admin/UX | **Wiederkehrende Admin-Muster vereinheitlicht**: Flash-Alerts, leere Tabellenzustände und mehrere Liste-/Moderationsansichten verhalten sich konsistenter, robuster und weniger „Überraschungsparty im Backoffice“. |
| **2.5.11** | 🔵 docs | Audit/Release | **Audit-Stand konsolidiert**: Audit-Berichte, Nacharbeitslisten und Release-Doku spiegeln jetzt den gehärteten Stand mit **88/100 Punkten** Gesamtbewertung, deutlich robusterer Release-Basis und klarerem Rest-Backlog. |
| **2.5.11** | ⬜ chore | Versionierung | **CMS-Versionlinie angehoben**: Core-Konstanten, Installer, Update-Metadaten, API-/Dashboard-Fallbacks, Landing-Defaults und Changelog wurden auf `2.5.11` synchronisiert. |

---

### v2.5.4 — 08. März 2026 · Sitemap-Live-Fixes, SEO-Admin-Härtung & Doku-Sync

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.4** | 🔴 fix | Core/Sitemap | **Live-Sitemap-Generierung auf Webservern gehärtet**: Die eingebundene `melbahja/seo`-Sitemap-Engine wurde für den Web-Kontext abgesichert, sodass kein undefiniertes `STDOUT` mehr die Generierung von `sitemap.xml`, `pages.xml`, `posts.xml`, `images.xml` oder `news.xml` blockiert. |
| **2.5.4** | 🔴 fix | Admin/SEO | **CSRF-Token-Flow im SEO-Subnav stabilisiert**: Globale Aktionen wie „Sitemaps generieren“ und „robots.txt schreiben“ verwenden jetzt denselben gültigen `admin_seo_suite`-Token wie die Zielseite und erzeugen keine versehentlichen „Sicherheitstoken ungültig.“-Fehler mehr. |
| **2.5.4** | 🔴 fix | Auth/Passkeys | **Passkey-Schema dauerhaft integriert**: `passkey_credentials` ist jetzt offizieller Bestandteil von `SchemaManager` und `MigrationManager`; neue Installationen und bestehende Deployments erhalten die WebAuthn-Tabelle regulär, und fehlende Passkey-Migrationen reißen die Member-Sicherheitsseite nicht mehr in einen Fatal Error. |
| **2.5.4** | 🔴 fix | SchemaManager | **fehlende Tabellen ergänzt**: `cms_favorites` Tabelle (v15→v16) und `cms_security_log` Tabelle in SchemaManager ergänzt (v16→v17) |

---

### v2.5.3 — 08. März 2026 · melbahja/seo integriert, Sitemaps modularisiert, Admin ausgebaut

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.3** | 🟢 feat | Core/SEO | **`melbahja/seo` produktiv integriert**: Das lokale Asset-Bundle unter `CMS/assets/melbahja-seo/` ist jetzt per Autoloader eingebunden; `SEOService` rendert Schema.org über `Melbahja\Seo\Schema` und `Thing` statt über manuell gebaute JSON-LD-Strings. |
| **2.5.3** | 🟢 feat | Core/Sitemap | **Sitemap-Architektur modularisiert**: Neuer `SitemapService` erzeugt `pages.xml`, `posts.xml`, `images.xml`, `news.xml` und den Index `sitemap.xml` im sicheren TEMP-Modus; ergänzend steuert `IndexingService` IndexNow- und Google-Submissions. |
| **2.5.3** | 🎨 style | Admin/SEO | **SEO-Adminbereich erweitert**: Die Sitemap-/Schema-Ansichten zeigen jetzt den neuen Bundle-Status, die modulare Dateistruktur, News-Defaults sowie Formulare für manuelle URL-Submissions an IndexNow und Google. |
| **2.5.3** | 🔵 docs | Release | **Versionierung nachgezogen**: Changelog, `CMS/update.json` und die Core-Versionskonstante wurden auf den neuen Stand der SEO-Migration synchronisiert. |

---

### v2.5.2 — 08. März 2026 · Asset-Cleanup finalisiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.2** | ⬜ chore | Assets/Cleanup | **Runtime-Bereinigung**: Die ungenutzte Reserve-Library `schema-org/` sowie sämtliche ungenutzten Sub-Libs unter `CMS/assets/tabler/libs/` wurden endgültig aus dem Runtime-Baum entfernt. |
| **2.5.2** | 🔵 docs | Docs/Assets | **Asset-Dokumentation auf Löschstand synchronisiert**: `DOC/ASSET.md`, `DOC/ASSET_OUTDATET.md` und die Bundle-Referenzen dokumentieren jetzt den bereinigten Ist-Zustand ohne `schema-org/` und ohne `tabler/libs/`. |
| **2.5.2** | 🔴 fix | Assets/Autoload | **Autoloader nach Bereinigung konsistent gehalten**: Verweise auf entfernte Bundles wurden aus `CMS/assets/autoload.php` entfernt, während die FilePond-Locales bewusst unangetastet blieben. |

---

### v2.5.1 — 08. März 2026 · Asset-Inventar & Bundle-Doku konsolidiert

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.1** | 🔵 docs | Docs/Assets | **Asset-Inventar vollständig neu abgeglichen**: Die Runtime-Nutzung von `CMS/assets/` wurde systematisch geprüft und in `DOC/ASSET.md` mit aktiven, transitiven und reservierten Bundles sauber nachgezogen. |
| **2.5.1** | 🔵 docs | Docs/Bundles | **Bundle-Dokumentation vereinheitlicht**: Neue bzw. überarbeitete Detaildokus für `mailer/`, `mime/`, `psr/` und offene Migrationshinweise wie `melbahja-seo/` wurden im Doku-Baum verankert. |
| **2.5.1** | ⬜ chore | Assets/Autoload | **Stale Loader vorab bereinigt**: Nicht mehr vorhandene Pfade wie `image/` und `rate-limiter/` wurden aus dem Asset-Autoloader entfernt und die Mailer-/Mime-/PSR-Reihenfolge konsistent gezogen. |

---

### v2.5.0 — 08. März 2026 · Full Sync, Mail-Infrastruktur & Doku-Konsolidierung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.5.0** | 🟢 feat | Core/Mail | **Mail-Infrastruktur produktiv ausgebaut**: `MailQueueService` verarbeitet E-Mails asynchron über `CMS/cron.php`, inklusive Retry-Strategie, Backoff, Queue-Management und Diagnose-Hooks. |
| **2.5.0** | 🟢 feat | Core/Integrations | **Microsoft-365-/Transport-Bausteine ergänzt**: `AzureMailTokenProvider`, `GraphApiService`, `MailLogService` und `SettingsService` erweitern den Transport-Stack um Token-Caching, Graph-Zugriff, Laufzeit-Settings und nachvollziehbare Mail-Logs. |
| **2.5.0** | 🟢 feat | Auth/LDAP | **Authentifizierung erweitert**: LDAP-Provider, Admin-Statusansichten und ein initialer LDAP-Sync für lokale CMS-Konten wurden in die Benutzer-/Authentifizierungsverwaltung integriert. |
| **2.5.0** | 🟢 feat | Admin/API | **Admin und API robuster gemacht**: Neue API-Routen für Seiten und Medien, härtere CSRF-Verifizierung sowie eine Grid-basierte Benutzerlistenansicht modernisieren zentrale Verwaltungsabläufe. |
| **2.5.0** | 🔵 docs | Docs/Assets | **`/CMS/assets` und `/DOC` vollständig synchronisiert**: Asset-Mapping, Workflow-Dokumente, Service-Referenzen und lokale Bundle-Dokus wurden zusammengeführt und auf den aktuellen Runtime-Stand gehoben. |
| **2.5.0** | ⬜ chore | Repo/Cleanup | **Repository bereinigt**: Veraltete Admincenter-Bilder, alte To-do-Dokumente und überholte Asset-Aufräumhinweise wurden entfernt; zusätzliche Parser-Klassen wurden mit dem Asset-Sync übernommen. |

---

### v2.4.1 — 08. März 2026 · Workflows, SEO-Medienlogik & Betriebsdoku

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.4.1** | 🟢 feat | SEO/Media | **Medienverarbeitung im SEO-Kontext verfeinert**: Bestimmte Pfade werden gezielt aus Berechnungen ausgeschlossen, Verzeichnisgrößen robuster ermittelt und Medien-Scans betriebssicherer ausgewertet. |
| **2.4.1** | 🔵 docs | Workflow | **Operative Workflow-Doku deutlich ausgebaut**: Neue Leitfäden für Marketplace, Media-Upload, Update/Deployment sowie Forum- und Newsletter-Plugin dokumentieren reale Betriebs- und Entwicklungsabläufe. |
| **2.4.1** | 🔵 docs | Assets | **Asset-Nutzung präziser dokumentiert**: Empfehlungen zur aktiven Nutzung lokaler Bundles, Mail-/LDAP-Verdrahtung und Runtime-Pfade wurden zentral in der Asset-Dokumentation ergänzt. |
| **2.4.1** | 🟡 refactor | Admin/UI | **Benutzer- und Verwaltungsoberflächen modernisiert**: Listen, Medien- und API-nahe Admin-Flows wurden strukturell aufgeräumt und besser auf die aktuelle Admin-Architektur abgestimmt. |
| **2.4.1** | ⬜ chore | Repo | **Nicht mehr benötigte Assets und Alt-Dokumente bereinigt**: Unbenutzte Artefakte und veraltete Hinweise wurden entfernt, um Doku- und Repository-Struktur klarer zu halten. |

---

### v2.4.0 — 08. März 2026 · Mailer, Auth-Settings & Integrationsbasis

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.4.0** | 🟢 feat | Core/Mail | **MailService auf lokale Symfony-Komponenten umgestellt**: Versand nutzt nun klar dokumentiert `Symfony Mailer` und `Symfony Mime`, inklusive verbesserter Fehlerbehandlung und konsistenter Transportbasis. |
| **2.4.0** | 🟢 feat | Admin/Auth | **Benutzer- und Authentifizierungs-Einstellungen erweitert**: Neue Verwaltungsflächen bündeln Status und Konfiguration für Login-, Provider- und LDAP-bezogene Einstellungen. |
| **2.4.0** | 🟢 feat | Auth/LDAP | **LDAP-Authentifizierung implementiert**: Externe Verzeichnisdienste lassen sich anbinden; ergänzend wurde der Admin-Erstsync für Benutzerkonten vorbereitet. |
| **2.4.0** | 🟢 feat | Core/Services | **Neue Integrations-Services ergänzt**: `SettingsService`, `GraphApiService`, `AzureMailTokenProvider` und `MailLogService` schaffen die Basis für moderne Mail- und Provider-Anbindungen. |
| **2.4.0** | 🔵 docs | Docs/Release | **Release-Dokumentation nachgezogen**: Mail-, Auth-, Asset- und Service-Dokumente wurden auf die neue Integrationslinie ausgerichtet und im Doku-Baum verankert. |

---

### v2.3.1 — 07. März 2026 · WebP-Automation, Font-Self-Hosting & Audit-Härtung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.3.1** | 🟢 feat | Admin/Performance | **WebP-Massenkonvertierung produktiv ergänzt**: Geeignete Bilder in `uploads/` können gesammelt in WebP umgewandelt werden; bekannte Referenzen in Medien-, Seiten-, Beitrags- und SEO-Daten werden automatisch auf die neue Datei aktualisiert. |
| **2.3.1** | 🔴 fix | Admin/Fonts | **Font-Manager robuster gemacht**: Download externer Google-Fonts nutzt zusätzliche Fallbacks für `css`/`css2`, toleriert typische SSL-/CA-Probleme auf Shared-Hosting/Windows-Setups besser und speichert erfolgreiche Self-Hosting-Aktionen nachvollziehbar. |
| **2.3.1** | 🔴 fix | Admin/SEO | **SEO-Audit defensiv stabilisiert**: Audit-Ansicht und Modul normalisieren fehlende Score-/Issue-Daten jetzt zuverlässig und vermeiden Notice-/Warning-Folgen bei unvollständigen Datensätzen. |
| **2.3.1** | 🟡 refactor | Audit/Logging | **Admin-Aktionen stärker protokolliert**: Firewall-, AntiSpam-, Plugin-, Font-, Performance- und Sicherheits-Audit-Aktionen schreiben strukturierte Einträge ins zentrale `audit_log`. |
| **2.3.1** | 🔵 docs | Docs/Release | **README, Changelog und Doku-Indizes auf 2.3.1 ausgerichtet**: Release-Linie, Monitoring-/SEO-Ausbau sowie WebP-/Font-Funktionen wurden zentral dokumentiert. |

---

### v2.3.0 — 07. März 2026 · SEO Suite & Editor-Optimierung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.3.0** | 🟢 feat | Admin/SEO | **SEO-Suite deutlich erweitert**: Neue Bereiche für Audit, Meta-Daten, Social Media, Schema, Sitemap und technisches SEO wurden als eigene Admin-Unterseiten ergänzt. |
| **2.3.0** | 🟢 feat | Editor/SEO | **Seiten- und Beitragseditoren ausgebaut**: Drei SEO-/Readability-/Preview-Karten unter dem Editor, Live-Scoring, Social-Preview und erweiterte SEO-Felder verbessern den Redaktions-Workflow. |
| **2.3.0** | 🟢 feat | Core/SEO | **Sitemap-Bundle erweitert**: XML-Sitemaps für Standard-Inhalte, Bilder und News können zentral regeneriert und überwacht werden. |

---

### v2.2.0 — 07. März 2026 · Performance Center & System-Monitoring

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.2.0** | 🟢 feat | Admin/Performance | **Performance als eigener Hauptbereich**: Cache, Medien, Datenbank, Einstellungen und Sessions wurden in eigenständige, datengetriebene Admin-Unterseiten überführt. |
| **2.2.0** | 🟢 feat | Admin/System | **Info, Diagnose und Monitoring ausgebaut**: Response-Time, Cron-Status, Disk-Usage, Scheduled Tasks, Health-Check und E-Mail-Alerts ergänzen die Systemwerkzeuge. |
| **2.2.0** | 🟡 refactor | Admin/Navigation | **System- und SEO-Navigation neu strukturiert**: Hauptmenüs für SEO, Performance, System, Info und Diagnose wurden klarer aufgeteilt und konsistent sortiert. |

---

### v2.1.2 — 07. März 2026 · Legal-Sites, Lösch-Workflow & Sicherheits-Layout

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.1.2** | 🔴 fix | Admin/Pages+Posts | **Löschen von Seiten und Beiträgen stabilisiert**: Single-Delete nutzt wieder einen robusten Formular-Submit mit direkter Bestätigung; die Delete-Logik in den Modulen liefert bei Fehlern jetzt saubere Rückmeldungen statt stillem Nichtstun. |
| **2.1.2** | 🟢 feat | Admin/Legal | **Legal Sites um Profiltyp erweitert**: Rechtstexte können jetzt gezielt für `Firma` oder `Privat` gepflegt werden. Die Pflichtfelder passen sich server- und clientseitig an den gewählten Profiltyp an. |
| **2.1.2** | 🟢 feat | Admin/Legal+Cookie | **Legal-Sites synchronisieren Folgeeinstellungen**: Beim Erstellen oder Zuordnen von Datenschutz-, AGB- und Widerrufsseiten werden abhängige Felder in anderen Admin-Bereichen automatisch befüllt, z. B. `cookie_policy_url` im Cookie-Manager sowie rechtliche Seiten-IDs für Abo-/Checkout-Einstellungen. |
| **2.1.2** | 🎨 style | Admin/Security | **Firewall- und AntiSpam-Layouts repariert**: KPI-Cards, Formulare und Listen werden wieder korrekt innerhalb des Admin-Containers gerendert und sauber nebeneinander ausgerichtet. |
| **2.1.2** | 🔵 docs | Docs/Release | **README und Changelog erweitert**: Die neue Legal-Sites-Logik, die Auto-Verknüpfung mit Cookie-/Abo-Einstellungen sowie die stabilisierten Lösch-Workflows wurden in der Projektdokumentation nachgetragen. |

---

### v2.1.1 — 07. März 2026 · Medienverwaltung, Rollenrechte & Release-Dokumentation

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.1.1** | 🔴 fix | Admin/Media | **Medienbibliothek funktional vervollständigt**: Standardmäßig Listenansicht, Suche, Kategorien-Filter, Datei-/Ordner-Löschung, robustere Redirects nach Aktionen und korrekt URL-encodierte Vorschaupfade für Bilder mit Leerzeichen oder Umlauten. |
| **2.1.1** | 🟢 feat | Admin/Media | **Geschützter Member-Medienbereich**: Der Ordner `member` verlangt vor dem Öffnen eine zusätzliche Bestätigung, wird als geschützter Systembereich behandelt und Member-Bilder werden in der Vorschaubild-Auswahl für Seiten/Beiträge ausgeblendet. |
| **2.1.1** | 🟡 refactor | Admin/Navigation | **Medien-Navigation aufgeräumt**: Doppelte Tab-Navigation entfernt, aktive Sidebar-Zustände für Medien-Unterseiten korrigiert und der Medien-Menübereich bleibt bei Unterpunkten zuverlässig geöffnet. |
| **2.1.1** | 🟢 feat | Admin/RBAC | **Rollen & Rechte erweiterbar gemacht**: In `Benutzer & Gruppen -> Rollen & Rechte` können jetzt neue Rollen und neue Rechte direkt angelegt werden; die Matrix verarbeitet dynamische Rollen und Capabilities. |
| **2.1.1** | 🔴 fix | Admin/Users | **Benutzerverwaltung an dynamische Rollen angebunden**: Rollen-Dropdowns und Filter in Listen- und Bearbeitungsansichten nutzen nun dieselbe dynamische Rollenquelle wie die Rechteverwaltung. |
| **2.1.1** | 🔴 fix | Core/Auth | **Capability-Prüfung DB-basiert erweitert**: `Auth::hasCapability()` berücksichtigt gespeicherte Rollenrechte aus `role_permissions`, damit neu angelegte Rollen sofort wirksam sind. |
| **2.1.1** | 🔵 docs | Docs/Release | **README, Changelog und Release-Metadaten synchronisiert**: Versionsstände auf `2.1.1` angehoben, neue Medien- und RBAC-Funktionen dokumentiert und die Patch-Version ohne Versionssprung in die Historie aufgenommen. |

---

### v2.1.0 — 07. März 2026 · Editor.js, Routing, Services & System-Tools

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.1.0** | 🟢 feat | Core/Editor | **Editor.js zusätzlich integriert**: Neben SunEditor steht jetzt auch Editor.js für moderne, blockbasierte Inhalte zur Verfügung. |
| **2.1.0** | 🟢 feat | Core/Services | **Neue Services ergänzt**: Comment-Management, Cookie-Consent, File-Uploads, PDF-Generierung, Site-Tables und Translation-Services wurden ausgebaut bzw. neu integriert. |
| **2.1.0** | 🟢 feat | Core/Router | **Mitglieder- und Dashboard-Routing erweitert**: Eigene Dashboard-Routen, Theme-Overrides, POST-Routen für den Member-Bereich und zusätzliche Seitennamen-Prüfungen ergänzen das Routing-System. |
| **2.1.0** | 🟢 feat | Admin/System | **DB-Tools in System-Info & Diagnose**: Neue Aktionen zum Erstellen fehlender Tabellen und zur Tabellen-Reparatur direkt im Admin. Die Diagnose deckt jetzt das vollständige Core-Schema mit 30 Tabellen inkl. `posts`, `comments`, `messages`, `audit_log` und `custom_fonts` ab. |
| **2.1.0** | 🟢 feat | Admin/RBAC | **Benutzer-, Rollen- und Berechtigungsverwaltung erweitert**: Neue Verwaltungsansichten und überarbeitete Admin-Oberflächen erleichtern Rollen- und Rechtemanagement. |
| **2.1.0** | 🟢 feat | Admin/Theme | **Schriften & Theme-Assets erweitert**: Brand-Schriften wurden in Download- und Ladefunktion integriert; Google Fonts lassen sich DSGVO-konform lokal speichern und im Frontend einbinden. Neue Tabelle `custom_fonts`, Schema-Version `v9`. |
| **2.1.0** | 🔴 fix | Admin/Security | **CSRF-Token-Flows korrigiert**: Die Token-Reihenfolge in Admin-Formularen wurde bereinigt, fehlende Token-Erzeugung auf normalen GET-Loads ergänzt und fehleranfällige Formularabläufe stabilisiert. |
| **2.1.0** | 🔴 fix | Admin/System | **Diagnose-Ansichten stabilisiert**: Berechtigungsanzeige und System-Info verarbeiten Rückgabedaten wieder korrekt und vermeiden TypeErrors in der Ausgabe. |
| **2.1.0** | 🟡 refactor | Admin/UI | **Admin-UI modernisiert**: Theme-Seiten, Dashboard, Posts- und User-Oberflächen wurden aufgeräumt, stärker auf Tabler Icons ausgerichtet und strukturell vereinheitlicht. |
| **2.1.0** | 🔴 fix | Theme/Navigation | **Defensive Verarbeitung für Menüs und Themes**: Ungültige Einträge in Theme- und Menü-Arrays werden robuster abgefangen und übersprungen. |
| **2.1.0** | 🔵 docs | Docs | **README & Changelog komplett aktualisiert**: Release-Version angehoben, System-/Schema-Dokumentation korrigiert und vollständige Übersicht der gebündelten Drittanbieter-Assets mit Autor, Website und GitHub-Links ergänzt. |

---

### v2.0.9 — 07. März 2026 · Rollenverwaltung & Release-Vorbereitung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.0.9** | 🟢 feat | Admin/RBAC | **Rollen- und Berechtigungsansicht erweitert**: Neue Verwaltungsoberfläche für Benutzerrollen und Berechtigungen vorbereitet bzw. eingebunden. |
| **2.0.9** | ⬜ chore | Assets | **Vendor-/Asset-Bestand bereinigt**: Größere Asset-Bestände wie `remark42` wurden in der Arbeitsbasis überarbeitet bzw. ausgeräumt, um das Repository zu konsolidieren. |
| **2.0.9** | 🔵 docs | Project | **Release-Vorbereitung für 2.1.0**: Versionspflege und Projekt-Metadaten wurden auf den nächsten Major-Patch-Zwischenschritt vorbereitet. |

---

### v2.0.8 — 06. März 2026 · Services-Ausbau

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.0.8** | 🟢 feat | Core/Services | **Neue Service-Bausteine ergänzt**: Comment-Management, Cookie-Consent, File-Uploads, PDF-Generierung, Site-Tables und Translation wurden als Services ergänzt bzw. deutlich erweitert. |
| **2.0.8** | 🟢 feat | Core/Docs | **Infrastruktur für weitere Core-Integrationen**: Die Service-Schicht wurde als Grundlage für zusätzliche Admin- und Frontend-Funktionen ausgebaut. |

---

### v2.0.7 — 05. März 2026 · Admin-UI, Editor.js & Asset-Sync

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.0.7** | 🟢 feat | Core/Editor | **Editor.js integriert**: Editor.js wurde zusätzlich zu SunEditor eingebunden, um blockbasierte Inhaltsbearbeitung zu ermöglichen. |
| **2.0.7** | 🟡 refactor | Admin/UI | **Admin-Oberflächen überarbeitet**: Dashboard, Posts, Users und Theme-Seiten wurden strukturell modernisiert, aufgeräumt und stärker auf Tabler abgestimmt. |
| **2.0.7** | 🟢 feat | Admin/Layout | **Layout-Funktionen für Dashboard/Seiten ausgebaut**: HTML-Strukturen wurden in zentrale Layout-Helfer überführt und Script-Verknüpfungen vereinheitlicht. |
| **2.0.7** | ⬜ chore | Assets | **Asset-Bestand aktualisiert**: Zusätzliche Vendor-Assets wie Tabler-Libs wurden in die Arbeitsbasis übernommen; veraltete Test-/Import-Verzeichnisse wurden entfernt. |

---

### v2.0.6 — 04. März 2026 · Fonts & Dashboard-Routing

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.0.6** | 🟢 feat | Core/Router | **Dashboard-Routen ergänzt**: Themes können jetzt ein eigenes Dashboard ausspielen; andernfalls greift sauber der Fallback auf `/member/dashboard`. |
| **2.0.6** | 🟢 feat | Admin/Theme | **Brand-Schriftarten erweitert**: Brand-Fonts wurden sowohl in die Ladefunktion als auch in die Downloadfunktion für den Font-Workflow aufgenommen. |

---

### v2.0.5 — 03. März 2026 · Member-Routing & defensive Theme-Menüs

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.0.5** | 🟢 feat | Core/Router | **Memberbereich auf `/member/dashboard` umgestellt**: Routing für den Mitgliederbereich wurde vereinheitlicht und Theme-Overrides für Seitenimplementierungen ergänzt. |
| **2.0.5** | 🔴 fix | Theme/Navigation | **Defensive Menü-/Theme-Verarbeitung**: Ungültige Einträge in Menü- und Theme-Arrays werden jetzt robuster erkannt und übersprungen. |

---

### v2.0.4 — 02. März 2026 · Member-POST-Routen & Kontaktseite

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.0.4** | 🟢 feat | Core/Router | **POST-Routen für den Mitgliederbereich**: Formulare und Aktionen im Memberbereich erhielten eigene POST-Routen inklusive zusätzlicher Prüfung erlaubter Seitennamen. |
| **2.0.4** | 🟢 feat | Frontend/Kontakt | **Kontaktseite im Routing berücksichtigt**: Kontaktformulare bekamen die nötige Sonderbehandlung im Routing, damit Frontend-POSTs sauber verarbeitet werden. |

---

### v2.0.3 — 01. März 2026 · Legal-Generator & Abo-Zuweisungen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.0.3** | 🔴 fix | Admin/Abo | **Benutzer-Abos in Zuweisungen abgesichert**: Die Anzeige und Zuordnung aktiver Benutzer-Abos wurde nach dem Split der Abo-Ansichten weiter stabilisiert. |
| **2.0.3** | 🟢 feat | Admin/Legal | **Impressum-Generator nachgeschärft**: Der Generator wurde weiter erweitert und für den produktiven Einsatz in den Rechtstexten verfeinert. |

---

### v2.0.2 — 01. März 2026 · Admin-Fixes, SEO-Frontend, Abo-Split

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.0.2** | 🔴 fix | Admin/Legal | **Legal Pages Posts->Pages**: `cms_posts` durch `cms_pages` ersetzt; `type`-Spalte entfernt (existiert nicht). DSGVO-Texte erweitert (Art. 13/14, EU-Streitschlichtung, SSL/TLS). Unicode-Quotes durch HTML-Entities ersetzt. |
| **2.0.2** | 🟢 feat | Admin/Legal | **Impressum Generator erweitert**: Neue Abschnitte: Haftung fuer Inhalte, Haftung fuer Links, Urheberrechtshinweis. Neue Formularfelder: Website-Name, Registergericht, verbundene Domains, Datenschutzbeauftragter. Kontaktzeile zeigt Telefon nur wenn ausgefuellt. HTML-Entities statt Unicode-Sonderzeichen. |
| **2.0.2** | 🔴 fix | Admin/Media | **CSRF Auto-Retry**: `cmsPost()` erkennt CSRF-Fehler und wiederholt den Request automatisch mit neuem Token. Behebt "Sicherheitsueberprüfung fehlgeschlagen" bei Ordner-Navigation. |
| **2.0.2** | 🟢 feat | Core/SEO | **SEO Frontend-Integration**: 5 neue public Getter in `SEOService` (`getHomepageTitle`, `getHomepageDescription`, `getMetaDescription`, `getSiteTitleFormat`, `getTitleSeparator`). Theme-Header nutzt SEO-Titel-Prioritaetskette. |
| **2.0.2** | 🟢 feat | Admin/Theme | **Multi-Rolle Editor-Zugriff**: Einzelauswahl-Dropdown durch Mehrfach-Checkboxen ersetzt (`theme_editor_roles`, kommasepariert). Marketplace-Sektion komplett entfernt. |
| **2.0.2** | 🔴 fix | Admin/Settings | **Aktive Module Mock entfernt**: Hardcodierte "Blog Modul: Aktiv / Shop System: Inaktiv"-Karte entfernt. |
| **2.0.2** | 🔴 fix | Core/UpdateService | **Update-URL konfigurierbar**: GitHub Repo/API-URL per DB-Setting (`update_github_repo`, `update_github_api`) konfigurierbar mit Fallback auf Defaults. Behebt HTTP 404 bei falscher API-URL. |
| **2.0.2** | 🟢 feat | Admin/Abo | **Zuweisungen gesplittet**: Neuer Tab "Uebersicht" (`?tab=overview`) mit Statistiken, Benutzer-Abo-Tabelle und Gruppen-Paketzuordnung (read-only). Tab "Zuweisungen" nur noch fuer Formulare. Neuer Sidebar-Menuepunkt. |
| **2.0.2** | 🔴 fix | Admin/Abo | **Benutzer-Abos in Zuweisungen**: Aktive Benutzer-Abos-Tabelle zurueck in den Tab "Zuweisungen" (war nach Overview-Split verloren gegangen). |
| **2.0.2** | 🔴 fix | Plugins/JPG | **Installer Column-Fix**: `setting_key`/`setting_value` auf `option_name`/`option_value` korrigiert fuer Zugriff auf Core-Tabelle `cms_settings`. |

---

### v2.0.1 — 01. März 2026 · Admin-Panel Audit & Bugfixes

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.0.1** | 🔴 fix | Admin/Media | **CSRF-Token-Rotation**: `query()` → `execute()` für AJAX-Aufrufe; neuer Token wird nach jeder Verifizierung zurückgegeben und im JS aktualisiert (`const` → `let` für `CMS_MEDIA_NONCE`). |
| **2.0.1** | 🔴 fix | Admin/Users+Groups | **Dynamische Rollen**: Hardcodierte Rollen-Arrays (`['admin','member','editor']`) durch DB-Abfragen aus `cms_roles` ersetzt; Rollendropdowns, Filter-Tabs und Validierung nutzen jetzt alle CMS-Rollen. Auto-Migration für `sort_order`/`member_dashboard_access`-Spalten in `groups.php`. |
| **2.0.1** | 🔴 fix | Admin/Subscriptions | **SQL-Fehler behoben**: `$db->query()` (kein Param-Support) → `$db->execute()` für Prepared Statements in `update_settings`, `assign_group_plan` und `delete_plan`. In `subscription-settings.php`: nicht-existierende `fetch()`/`fetchColumn()` → `get_row()`/`get_var()`; CSRF Action-Slug ergänzt. |
| **2.0.1** | 🔴 fix | Admin/Theme | **Editor-Rolle dynamisch**: Dropdown in `theme-settings.php` zeigt jetzt alle DB-Rollen statt nur `admin`/`editor`. |
| **2.0.1** | 🔴 fix | Admin/Legal | **type=page bei INSERT**: Impressum und Datenschutz-Posts erhalten jetzt `'type' => 'page'` wie Cookie-Richtlinie. |
| **2.0.1** | 🔴 fix | Admin/Settings | **Rollen-Validierung dynamisch**: `$allowedRoles` wird aus DB geladen statt hardcodiert (`['admin','editor','author','member','subscriber']`). |
| **2.0.1** | 🔴 fix | Admin/System | **Tabellenzahl nicht mehr hardcoded**: `/ 22` aus CMS-Tabellen-Anzeige entfernt (tatsächlich 29+ Tabellen). |
| **2.0.1** | 🟡 refactor | Admin/Updates | `window.confirm()` durch eigenes Bestätigungs-Modal ersetzt (Konventions-konform). |
| **2.0.1** | 🟡 refactor | Admin/Layout | `theme-marketplace.php`, `plugin-marketplace.php`, `support.php`, `updates.php`: Manuelles HTML-Boilerplate durch `renderAdminLayoutStart()`/`renderAdminLayoutEnd()` ersetzt. |

---

### v2.0.0 — 28. Februar 2026 · Nachrichten-System & Security-Audit

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **2.0.0** | 🟢 feat | Core/Member | **Nachrichten-System**: Vollständige Member-to-Member-Messaging-Funktion mit Posteingang, Gesendet-Ansicht, Thread-Konversationen, Empfänger-Autocomplete und Soft-Delete. Neue `cms_messages`-Tabelle (SchemaManager v8). Neuer `MessageService` (Singleton, Inbox/Sent/Thread/Send/Delete/UnreadCount). Member-Dashboard mit Two-Panel-Layout (Konversationsliste + Detail/Thread/Compose). **Security-Audit**: 10 CRITICAL- und 9 HIGH-Priority-Fixes implementiert (XSS-Escaping, CSRF-Schutz, Path-Traversal, Admin-Passwort, SQL-Injection-Prävention). **Installer**: CMS_VERSION → 2.0.0, PHP-Mindestversion → 8.2, Messages-Tabelle hinzugefügt. |

---

### v1.9.x — 27.–28. Februar 2026 · Security Hardening & UI-Improvements

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **1.9.5** | 🔴 fix | Admin/Security | **Media**: XSS-Escaping mit `escHtml()`/`escAttr()` für Dateinamen und Alt-Texte; Custom Delete-Modal statt `window.confirm()`. **Pages**: `wp_kses_post` für Seiteninhalt-Sanitierung; Custom Lösch-Modal. **Backup**: Path-Traversal-Schutz mit `basename()`+Regex. **Updates**: Core-Update-Button mit AJAX-Handler. **Security-Audit**: Inline-CSS + admin-page-header Fix. **Plugin-UI**: 10px Menü-Spacing-Fix. |
| **1.9.4** | 🔴 fix | Admin | `ABSPATH`-Guard in `users.php` hinzugefügt; dupliziertes HTML entfernt; `last_login`-Spalte und Delete-Button ergänzt; Site-URL-Definition sichergestellt. |
| **1.9.3** | 🎨 style | Admin | Plugin-Management-UI komplett überarbeitet für besseres Layout und Responsiveness. |
| **1.9.2** | 🟡 refactor | Theme | ThemeCustomizer: `prepare/execute` durch `execute` ersetzt für korrekte NULL-Wert-Behandlung; Error-Logging verbessert. |
| **1.9.1** | 🔴 fix | Admin/Plugin | Unbenutzte Feature-Widgets aus Widget-Dashboard entfernt; Output-Buffering für Plugin-Admin-Bereich korrigiert. |
| **1.9.0** | 🟢 feat | Member | Accordion-Navigation für Member-Sidebar mit ausklappbaren Plugin-Bereichen und verbessertem Styling. |

---

### v1.8.x — 22.–26. Februar 2026 · Security, Themes & Blog

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| **1.8.5** | 🟢 feat | Member | Plugin-Navigation im Member-Bereich mit Sub-Items und verbessertem Styling. |
| **1.8.4** | ⬜ chore | Theme | TechNexus-Theme und zugehörige Unit-Tests entfernt. |
| **1.8.3** | 🟢 feat | Theme | 365Network Theme Customizer: Konfigurierbare Einstellungen für Farben, Typografie, Layout, Header, Footer, Buttons und erweiterte Optionen. |
| **1.8.2** | 🔴 fix | Core | `Security::escape()` akzeptiert nun `string|int`. EditorService nutzt `setContents()` statt `set()` um WYSIWYG-Double-Encoding zu verhindern. `fetchGitHubData` von `file_get_contents` auf cURL umgestellt. |
| **1.8.1** | 🔴 fix | Router/Admin | **Öffentliche Seiten 404-Bug**: Neue CMS-Seiten wurden standardmäßig als `draft` angelegt. `admin/pages.php` – Default-Status auf `published` geändert. Router-Debug-Logging verbessert. |
| **1.8.0** | 🟢 feat | Security | CMS-Firewall mit IP-Blocking, Geo-Filtering und Request-Analyse sowie AntiSpam und Security-Audit vollständig überarbeitet. |

---

### v1.7.x — 22. Februar 2026 · Theme & Plugin Marketplace

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| 1.7.9 | 🟢 feat | Admin | RBAC-Verwaltung vollständig neu implementiert mit granularen Capabilities |
| 1.7.8 | 🟢 feat | Admin | Support-Ticket-System mit Prioritäten und Status-Tracking in Admin integriert |
| 1.7.7 | 🟢 feat | Theme | Theme-Marketplace mit 10 fertigen Themes und Vorschau-Funktion |
| 1.7.6 | 🟢 feat | Plugin | Plugin-Marketplace mit Kategorie-Browser und Such-Filter |
| 1.7.5 | 🟢 feat | Theme | Lokaler Fonts Manager mit Upload, Verwaltung und Theme-Integration |
| 1.7.4 | 🟡 refactor | Theme | Theme-Customizer auf 50+ Optionen in 8 Kategorien erweitert |
| 1.7.3 | 🟢 feat | Admin | Update-Manager für Core, Plugins und Themes direkt via GitHub API |
| 1.7.2 | 🟢 feat | Admin | Benutzerdefinierte Site-Tables mit CRUD, Import/Export CSV/JSON erweitert |
| 1.7.1 | 🟢 feat | Member | Member-Dashboard Admin-Verwaltung mit Übersichts- und Statusseite überarbeitet |
| 1.7.0 | 🟢 feat | Admin | README-Dokumentation vollständig mit Screenshots und Feature-Übersicht aktualisiert |

---

### v1.6.x — 21.–22. Februar 2026 · Cookie-Manager & Legal-Suite

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| 1.6.9 | 🟢 feat | Cookie | Cookie-Verwaltung mit Dienstbibliothek und Sicherheitsprüfungen erweitert |
| 1.6.8 | 🔵 docs | Core | Dokumentation und Skripte für 365CMS aktualisiert |
| 1.6.7 | ⬜ chore | Docs | Veraltete Sicherheitsarchitektur-Dokumentation entfernt |
| 1.6.6 | 🔵 docs | README | README-Dateien mit neuen Versionsinformationen und verbesserten Beschreibungen aktualisiert |
| 1.6.5 | 🟢 feat | Admin | Site-Tables-Management mit CRUD-Operationen und Import/Export; neue Menüeinträge |
| 1.6.4 | 🟡 refactor | Legal | Generierung von Rechtstexten bereinigt und optimiert; Menübezeichnung aktualisiert |
| 1.6.3 | 🟢 feat | Cookie | Cookie-Richtlinie mit dynamischem Zustimmungsstatus und optimierter Darstellung |
| 1.6.2 | 🟢 feat | Cookie | Cookie-Richtlinie-Generierung in Rechtstexte-Generator integriert |
| 1.6.1 | 🟢 feat | Legal | AntiSpam-Einstellungsseite und Rechtstexte-Generator implementiert |
| 1.6.0 | 🟢 feat | Cache | Cache-Clearing-Funktionalität und Asset-Regenerierung hinzugefügt |

---

### v1.5.x — 21. Februar 2026 · Support-System & DSGVO

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| 1.5.9 | 🔴 fix | Database | Tabellenbezeichnungen von `usermeta` zu `user_meta` in mehreren Dateien aktualisiert |
| 1.5.8 | 🔴 fix | SEO | Einstellungsname für benutzerdefinierten robots.txt-Inhalt korrigiert |
| 1.5.7 | 🟢 feat | GDPR | DSGVO-konforme Datenlöschung und Security-Audit-Seite hinzugefügt |
| 1.5.6 | 🔵 docs | Docs | INDEX.md in Dokumentationsliste priorisiert; Dokumentationsindex bereinigt |
| 1.5.5 | 🔵 docs | Docs | Dokumentation für Content-Management, SEO, Performance, Backup und User-Management |
| 1.5.4 | 🟡 refactor | Support | Übersichtsseiten je Bereich mit GitHub-Links statt Markdown-Rendering |
| 1.5.3 | 🔴 fix | Support | Timeout auf 4/6s reduziert; 5-min-Datei-Cache für Dok-Liste; Refresh-Link |
| 1.5.2 | 🔴 fix | Support | fetchDocContent auf GitHub Contents-API umgestellt; CDN entfernt, Markdown serverseitig gerendert |
| 1.5.1 | 🔴 fix | Support | cURL-basierter GitHub-API-Client; Debug-Modus; DOC/admin-Ordner umbenannt |
| 1.5.0 | 🟡 refactor | Support | Support.php komplett neu: Docs ausschließlich via GitHub API + raw.githubusercontent.com |

---

### v1.4.x — 21. Februar 2026 · Admin-Erweiterungen & Logging

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| 1.4.9 | 🟢 feat | Docs/Support | Dokumentationsabruf mit rekursivem Directory-Traversal; Sidebar-Gruppierung |
| 1.4.8 | 🟡 refactor | Core | File-Struktur bereinigt; Code-Struktur für bessere Lesbarkeit optimiert |
| 1.4.7 | 🟢 feat | Admin | Plugin- und Theme-Marketplace-Seiten mit Settings-Management hinzugefügt |
| 1.4.6 | 🟢 feat | Landing | Landing-Page-Management erweitert |
| 1.4.5 | 🔴 fix | Logging | Logs werden nur noch bei `CMS_DEBUG=true` in `/logs` geschrieben |
| 1.4.4 | 🎨 style | Orders | Admin-Design für Bestellverwaltung vereinheitlicht (Benutzer & Gruppen) |
| 1.4.3 | 🔵 docs | Changelog | Versionierung auf 0.x umgestellt; Changelog + README aktualisiert |
| 1.4.2 | 🟢 feat | Subscriptions | Admin-Subscriptions-UI mit verbesserter Navigation und Labels |
| 1.4.1 | 🟡 refactor | Subscriptions | Pakete-Editor in Übersicht integriert; neue Einstellungen-Seite; Sub-Tabs entfernt |
| 1.4.0 | 🟢 feat | Dashboard | Version-Badge im Admin Dashboard-Header |

---

### v1.3.x — 20. Februar 2026 · Public Release & Blog/Subscriptions

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| 1.3.6 | ⬜ chore | CI/CD | PHP-Composer-Workflow-Konfiguration hinzugefügt |
| 1.3.5 | 🟢 feat | Subscriptions | Subscription- und Checkout-System implementiert |
| 1.3.4 | 🟢 feat | Pages | Page-Management-UI mit Success/Error-Messages und verbessertem Layout |
| 1.3.3 | 🔴 fix | Security | CSRF-Token-Handling in User- und Post-Management-Formularen verbessert |
| 1.3.2 | 🟢 feat | Blog | Blog-Routen für Post-Listing und Single-Post-Detailansicht hinzugefügt |
| 1.3.1 | 🟢 feat | Database | Datenbankschema auf Version 3 aktualisiert; Blog-Post-Tabellen hinzugefügt |
| **1.3.0** | 🟢 feat | **Release** | **First Public Release – 365CMS.DE veröffentlicht** |

---

### v1.2.x — 18.–20. Februar 2026 · Media & Member-Erweiterungen

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| 1.2.7 | 🔵 docs | Projekt | Initial Commit 365CMS.DE Repository; README mit CMS-Beschreibung und Website-Link |
| 1.2.6 | 🟢 feat | Subscriptions | Zahlungsarten-Update implementiert; Benutzerabonnements-Abfrage verbessert |
| 1.2.5 | 🟢 feat | Member | Member-Menü überarbeitet; Favoriten- und Nachrichten-Funktionalität hinzugefügt |
| 1.2.4 | 🟡 refactor | Error | Fehlerbehandlung überarbeitet; Media-Upload-Struktur für mehr Robustheit verbessert |
| 1.2.3 | 🟡 refactor | Media | Media-View und AJAX-Handling für bessere UX und Fehlerbehandlung überarbeitet |
| 1.2.2 | 🔴 fix | AJAX | AJAX-URL-Handling für mehr Robustheit und Debugging verbessert |
| 1.2.1 | 🟢 feat | Media | Media-Proxy und AJAX-Handling für verbesserte Medienoperationen implementiert |
| 1.2.0 | 🟢 feat | Media | Medien-AJAX-Handling und Authentifizierung verbessert; robustere Fehlerbehandlung |

---

### v1.1.x — 10.–18. Februar 2026 · Member-System & Plugins

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| 1.1.9 | 🟢 feat | Member | Member-Medien-Management implementiert (Upload, Verwaltung) |
| 1.1.8 | 🟢 feat | Admin | Dashboard-Funktionalität um Logo-Upload erweitert; Widget-Anzahl auf 4 erhöht |
| 1.1.7 | 🔵 docs | Themes | Umfassende Dokumentation für Theme-Entwicklung in CMSv2 erstellt |
| 1.1.6 | 🟢 feat | Member | Member-Service hinzugefügt; CMS-Speakers-Plugin refaktoriert |
| 1.1.5 | 🟢 feat | Events | CMS-Experts und Events-Management erweitert |
| 1.1.4 | 🟢 feat | Experts | Expert-Management: Status-Updates, Skill-Presets und Plugin-Einstellungen |
| 1.1.3 | 🟡 refactor | Core | Code-Struktur für bessere Lesbarkeit und Wartbarkeit refaktoriert |
| 1.1.2 | 🟢 feat | Landing | Landing-Page-Service um Footer-Management erweitert |
| 1.1.1 | 🟢 feat | Cookie | Cookie-Scanning-Funktionalität mit serverseitigen und Content-Heuristik-Prüfungen |
| 1.1.0 | 🟢 feat | Admin | Landing-Page und Theme-Management-Funktionalität im Admin hinzugefügt |

---

### v1.0.x — 01.–09. Februar 2026 · Stabilisierung & AJAX-Architektur

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| 1.0.9 | 🔴 fix | Dashboard | Escaped Backslash-Dollar in SQL-Prefix-Interpolation entfernt |
| 1.0.8 | 🔴 fix | Subscriptions | Fehlendes PHP-Schlusstag `?>` in create-plan-Modal (Zeile 521) ergänzt |
| 1.0.7 | 🔴 fix | Subscriptions | Price-Felder zu Float gecastet vor `number_format()` |
| 1.0.6 | 🔴 fix | Core | Sicherheits-Fixes in Core-Klassen |
| 1.0.5 | 🔴 fix | Core | Datenbank-Prefix-Methoden und Session-Logout-Handling verbessert |
| 1.0.4 | 🟢 feat | Admin | Vollständiger Admin-Bereich: AJAX-Architektur für 12 Dateien (Services + AJAX + Views-Trennung) |
| 1.0.3 | 🔵 docs | Core | Core-Bereich vollständig dokumentiert |
| 1.0.2 | 🟡 refactor | Services | Prefix-Property + hardkodierte Tabellennamen eliminiert |
| 1.0.1 | 🟠 perf | Core | `createTables()` Performance Guards in Database + SubscriptionManager |
| 1.0.0 | 🔴 fix | Admin | Konsistenz + Performance-Fixes im Admin-Bereich |

---

### v0.9.x — Januar 2026 · Member-Bereich & Admin-Neugestaltung

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| 0.9.9 | 🔴 fix | Admin | Kritische Sicherheits-Fixes – groups.php, subscriptions.php, theme-editor.php |
| 0.9.8 | 🔵 docs | Admin | README.md aktualisiert und ADMIN-FILESTRUCTURE.md zur vollständigen Dokumentation erstellt |
| 0.9.7 | 🔴 fix | Subscriptions | Redundante statusBadges in subscription-view entfernt |
| 0.9.6 | 🔴 fix | Member | Critical Bug Fixes: Method-Visibility, Config-Loading, XSS, Escaping |
| 0.9.5 | 🟢 feat | Member | Member-Profil, Security, Subscription und Datenschutz-Views und Controller hinzugefügt |
| 0.9.4 | 🟢 feat | Subscriptions | Subscription-Management Admin-Seite mit Plan-Erstellung und Zuweisung |
| 0.9.3 | 🟢 feat | Admin | Updates-, Backup- und Tracking-Services hinzugefügt |
| 0.9.2 | 🟢 feat | Admin | Backup-Management-Seite mit Backup-Funktionalitäten implementiert |
| 0.9.1 | 🟢 feat | Admin | Komplett neuer Admin-Bereich – Modern & Friendly |
| 0.9.0 | 🔴 fix | Assets | CSS/JS-Pfade auf absolute Server-Root-Pfade geändert + Test-Datei |

---

### v0.8.x — Januar 2026 · Sicherheits-Patches & Dashboard

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| 0.8.9 | 🔴 fix | Admin | Admin-CSS- und JS-Pfade korrigiert (global → admin/assets) |
| 0.8.8 | 🟢 feat | Dashboard | Dashboard mit moderner AJAX-Architektur ersetzt; DashboardService-Datenbankfehler behoben |
| 0.8.7 | 🟢 feat | Cache | Umfassende Cache-Clearing-Funktion implementiert |
| 0.8.6 | 🔴 fix | Services | Service-Fehler behoben; fehlende `use CMS\Security` in landing-get.php ergänzt |
| 0.8.5 | 🔴 fix | Settings | Settings-Tabelle Spaltennamen korrigiert (`setting_key/value` → `option_name/value`) |
| 0.8.4 | 🟢 feat | Database | Automatische DB-Bereinigung bei Neuinstallation implementiert |
| 0.8.3 | 🔴 fix | Install | install-schema.php HTTP 500 durch falsche Database-Methoden behoben |
| 0.8.2 | 🔴 fix | Namespaces | Namespace-Regressionen in Services und Datenbank-Schema behoben |
| 0.8.1 | 🔴 fix | Core | Session-Management in autoload.php zentralisiert; `Auth::getCurrentUser()` hinzugefügt |
| **0.8.0** | 🔴 **fix** | **Core** | **KRITISCH: 7 Sicherheitsprobleme behoben** |

---

### v0.7.x — Januar 2026 · Sicherheit, E-Mail & PWA

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| 0.7.8 | 🔴 fix | Security | CORS-Konfiguration und SEO-External-Code-Embedding gesichert |
| 0.7.7 | 🔴 fix | Security | 5 kritische Sicherheitsprobleme im Core-System behoben |
| 0.7.6 | 🟢 feat | Admin | Phase 1.1: Admin-Core-Migration – Admin.php mit erweiterten Features erstellt |
| 0.7.5 | 🟢 feat | Core | Phase 1.3: Job-Queue-System mit Scheduling, Worker-Management und Monitoring |
| 0.7.4 | 🟢 feat | Email | Phase 1.2: E-Mail-System mit Templates, Queue und Tracking vollständig implementiert |
| 0.7.3 | 🔴 fix | Security | SQL-Injection- und Credential-Exposure-Schwachstellen behoben |
| 0.7.2 | 🟢 feat | Cache | LiteSpeed-Cache-Integration und Performance-Optimierungen implementiert |
| 0.7.1 | 🟢 feat | PWA | Phase 1.5 PWA-Support implementiert – Phase 1 Implementierung 100 % abgeschlossen |
| **0.7.0** | 🟢 feat | Security | Phase 1.4 Sicherheits-Enhancements: MFA, OAuth, Social Login, Intrusion Detection, GDPR |

---

### v0.6.x — Januar 2026 · Bugfixes, Bookings & Multi-Tenancy

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| 0.6.9 | 🟢 feat | Core | Multi-Tenancy-Foundation (Tenant.php) implementiert – Phase 2 Core-Start |
| 0.6.8 | 🟠 perf | Bookings | Datenbankindex-Optimierung für 75 % Abfrage-Performance-Verbesserung |
| 0.6.7 | 🟢 feat | Bookings | Konflikt-Erkennung mit Pufferzeiten, Urlaubssperrung und Concurrency-Limits erweitert |
| 0.6.6 | 🔴 fix | Admin | Admin-Panel Plugin-Management gefixt; Subdirectory-Support hinzugefügt |
| 0.6.5 | 🔴 fix | Database | Merge-Konflikte, Schema-Doppelpräfix und Konfig-Struktur behoben |
| 0.6.4 | 🔴 fix | Core | Fehlende Helper-Funktionen ergänzt: `has_action`, `has_filter`, `trailingslashit` |
| 0.6.3 | 🔴 fix | Database | Schema.sql bereinigt: Plugin-Tabellen entfernt, cms_users-Felder korrigiert |
| 0.6.2 | 🟡 refactor | Core | Modulare Architektur: index.php von 258 auf 72 Zeilen reduziert |
| 0.6.1 | 🔴 fix | Database | Datenbank-Prefix-Doppelpräfix-Bugs im gesamten Codebase behoben |
| 0.6.0 | 🔴 fix | Core | Kritische Routing- und Datenbank-Prefix-Bugs im CMS-Core behoben |

---

### v0.5.x — January 2026 · 365CMS v2 Initial Release** | V1→V2 Migration  
First development attempts with GitHub Copilot (Opus 4.6 and GPT-5.4) via Vibecoding

| Version | Typ | Bereich | Beschreibung |
|---------|-----|---------|-------------|
| 0.5.9 | 🔵 docs | Docs | ADMIN-GUIDE.md in `doc/admin/`-Unterverzeichnis reorganisiert |
| 0.5.8 | 🔵 docs | Admin | Umfassende ADMIN-GUIDE.md + Security/Performance-Admin-Seiten erstellt |
| 0.5.7 | 🟢 feat | Core | PluginManager: getActivePlugins angepasst; getCurrentTheme; time_ago erweitert; clear-cache.php |
| 0.5.6 | 🟢 feat | Admin | System-Status-Seite hinzugefügt; User-Erstellungsformular verbessert |
| 0.5.5 | 🟢 feat | Admin | User-Management mit CRUD-Operationen, Rollenverwaltung und Bulk-Aktionen |
| 0.5.4 | 🟢 feat | Admin | Vollständiger Admin-Bereich implementiert |
| 0.5.3 | 🔵 docs | Docs | Vollständige Dokumentation für CMS365-Phasen und Security-Audit hinzugefügt |
| 0.5.2 | 🟢 feat | Security | Security-Layer implementiert; 5 kritische Sicherheitsprobleme im Core behoben |
| 0.5.1 | 🟢 feat | Core | Install.php, Updater.php und erweitertes index.php mit Full-Routing hinzugefügt |
| **0.5.0** | 🟢 feat | **Core** | **CMSv2 Initial: Core-System mit Hooks, Datenbank, Auth und Routing implementiert** |

---

> *CMSv1 (0.1.xx – 0.4.99) – Interne Entwicklungsphase 2024-2025, nicht öffentlich verfügbar*
