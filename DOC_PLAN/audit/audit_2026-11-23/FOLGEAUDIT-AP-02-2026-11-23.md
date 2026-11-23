# AP-02 – Autorisierung, Rollen, Objektzugriff

**Zwischenstand vom 23.11.2026.** Geprüft wurde der Quellstand `c51bdce1` auf `main`
(`V4.2.0-35-gc51bdce1`, Version 4.2.0), statisch durch Quelltextlesung. Maßstab:
[Audit-Katalog, AP-02](../AUDIT-KATALOG-4.2.0.md). Der Bericht betrifft nur AP-02; er ist keine
Freigabe der Rechteprüfung insgesamt. Es wurden keine Benutzerkonten, Datenbanken oder Produktivsysteme
verwendet. Alle Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Eine belegte Härtungslücke (02.3) wurde nach dem Audit behoben und ist durch einen
Regressionstest abgesichert. Alle übrigen Prüfpunkte sind auf den gelesenen Pfaden statisch erfüllt oder
für eine Laufzeitprüfung mit Testkonten offen. Es gab keinen Befund, der Fremddaten ohne Berechtigung
preisgibt. „offen“ heißt: Der gelesene Pfad ist unauffällig, der Nachweis im Betrieb steht aus.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 02.1 Admin-Einstiege | ja (gelesene Pfade) | `CMS/core/Routing/AdminRouter.php:59,134,156` verlangen `Auth::isAdmin()`; die Ausnahme für delegierte Seiten steht bei `:85` (`DelegatedAdminAccess::routerAllows`). Die `*-api.php` prüfen Admin, Capability, Methode und `verifyPersistentToken`. Schreibende Beitragsaktionen laufen über die gemeinsame Abschnittskonfiguration mit `csrf_action => 'admin_posts'` (`CMS/admin/posts.php:627`) und je Aktion über `cms_admin_posts_can_run_action()` (`:282–296`). Direktaufruf der Admin-Dateien scheitert am `ABSPATH`-Check. | Einmal mit Testkonten je Rolle alle Seiten der Admin-Navigation abrufen und die HTTP-Statuscodes tabellarisch festhalten. |
| 02.2 Objektzugriff (IDOR) | ja (gelesene Pfade) | Beiträge: Eigentumsfilter und `canAccessPost()` (`CMS/admin/modules/posts/PostsModule.php:58–82`) vor Speichern (`:904–919`), Revision (`:2656–2666`), Papierkorbaktionen (`:1428–1460`) und Freigabe-Aktionen (`:1596–1600`). Nachrichten: `MessageService::getMessage/getThread` mit Teilnehmerprüfung (`CMS/core/Services/MessageService.php:95,122`). Rechnungen: `InvoiceService::canAccess/downloadFor` (`CMS/core/Services/Payments/InvoiceService.php:842,858`). Mitglieder-Medien: Pfade werden auf `member/user-<id>` gezwungen (`CMS/core/Services/FileUploadService.php:90`). Favoriten nur für veröffentlichte, nicht gelöschte Beiträge (`CMS/core/Services/MemberContentService.php:214–219`). | Mit zwei Testmitgliedern fremde IDs für Nachricht, Rechnung, Favorit und Medienpfad anfragen; erwartet 403/404 ohne Inhaltsunterschied. |
| 02.3 Rechteausweitung | ja (Härtung umgesetzt) | Systemrollen (`admin`, `editor`, `author`, `member`, `moderator`) sind nicht umbenenn- und nicht löschbar (`CMS/admin/modules/users/RolesModule.php:24,307–330,379–390`). Selbst-Herabstufung, -Deaktivierung und -Löschung sind gesperrt (`CMS/admin/modules/users/UsersModule.php:854–860,1150`). Registrierung kann nie `admin`/`moderator` vergeben (`CMS/core/Services/UserService.php:1033`). `Auth::isAdmin()` vergleicht strikt. **Abweichung:** siehe TMP-AP02-1. | Gleichzeitiges Herabstufen mehrerer Administratoren auf MariaDB beobachten (kein Zustand ohne Administrator, solange der Handelnde selbst Administrator bleibt). |
| 02.4 Site-Kontext | offen | `AdminSiteContextService` (Option `admin_sites`) steuert nur Darstellung, Akzentfarben und Plugin-Zuweisung; es gibt keine Datenisolation, die umgangen werden könnte. Auflösung: Nutzerpräferenz, dann Request-Host, dann erste Site. | Entscheiden, ob der Kontext künftig Datenzugriff begrenzen soll; dann Pflicht für serverseitige Prüfung je Abfrage. |
| 02.5 Direkte PHP-Einstiege | ja (gelesene Pfade) | `update.php` (Admin, `manage_settings`, CSRF `database_update`), `cron.php` (Token mit `hash_equals`), `install.php` (Sperrdatei, `guardInstalledInstaller`), `PrivateStorageService::resolve` (`realpath`-Prüfung). Uploads: `CMS/uploads/.htaccess` sperrt Skripte und versteckte Dateien; `CMS/.htaccess:80` blockiert `uploads/member` und `uploads/import` direkt. | Webserver-Konfigurationen außer Apache (nginx, IIS) gegen dieselben Regeln prüfen. |
| 02.6 Token (Vorschau, Freigabe, Download) | ja (gelesene Pfade) | Vorschau: Nur der SHA-256-Hash wird gespeichert, Ablauf 7 Tage, widerrufbar, Auflösung nur mit gültiger Form und `expires_at > now` (`CMS/core/Services/Content/ContentWorkflow.php:15,51,653–662`). Freigabe: Vier-Augen-Regel in `canPublish` (`:225–232,371–390`), `approve` zusätzlich mit Veröffentlichungs-Checkliste (`PostsModule.php:1603–1609`). Download-Token: HMAC mit `hash_equals`, externe Token mit Ablauf (`CMS/core/Services/Downloads/DownloadSecurity.php:50–92`). Editor.js-Medien-API: Anmeldung, Editor-Recht, CSRF `editorjs_media` (`CMS/core/Services/EditorJs/EditorJsRequestGuard.php:17–62`); die Bildbibliothek blendet `member/` aus (`EditorJsImageLibraryService.php`). Medienauslieferung: private Pfade (`member/`, `import/`) nur für Eigentümer oder Administrator (`CMS/core/Services/MediaDeliveryService.php:212,619–639`). | Download-Auslieferung mit abgelaufenem und verändertem Token durchspielen. |
| 02.7 Delegierbare Rechte | ja (gelesene Pfade) | `DelegatedAdminAccess::PAGES` (`CMS/core/Services/AdminMenu/DelegatedAdminAccess.php:23`) umfasst Zahlungen, Rechnungen, wiederverwendbare Blöcke und Webhooks; die Seiten prüfen zusätzlich `hasCapability` und `canAccess`. | Je delegierter Seite mit Rolle ohne Capability aufrufen (erwartet 403). |
| 02.8 Typverwechslung | ja (Stichprobe) | Suche nach `in_array` ohne `strict` bei Rolle, Capability, Status und Benutzer sowie nach losen `==`-Vergleichen bei Rollen: keine Treffer. IDs werden mit `(int)` normalisiert; Rollen mit `strtolower` und Slug-Filter (`RolesModule.php:894–901`). | Keine statische Suche ersetzt die Prüfung von Plugin-Code. |

## Abweichungen

### TMP-AP02-1 · Ersatzrolle beim Löschen einer Rolle darf nicht `admin` oder `moderator` sein (LOW, umgesetzt)

- **Befund:** `RolesModule::deleteRole()` hängte alle Konten der gelöschten Rolle auf eine beliebige
  vorhandene Ersatzrolle um, auch auf `admin` oder `moderator`. Ein Administrator konnte so versehentlich
  oder durch eine manipulierte Anfrage eine ganze Gruppe zu Administratoren machen; die Oberfläche bot
  `admin` im Auswahlfeld an. Die Registrierung kennt dieselbe Grenze bereits (`UserService::isRegistrationRoleAllowed`).
  Nur Administratoren erreichen die Funktion, daher kein Angriff von außen.
- **Korrektur:** `deleteRole()` lehnt `admin` und `moderator` (ohne Rücksicht auf Groß-/Kleinschreibung)
  als Ersatzrolle mit Fehlermeldung ab, bevor etwas geschrieben wird. Die Auswahl in
  `CMS/admin/views/users/roles.php` zeigt beide Rollen nicht mehr an. Leere, gleiche oder unbekannte
  Ersatzrolle fällt weiterhin auf `member` zurück.
- **Regression:** `TESTS/user-role-hook/run.php` (`RolesModule::deleteRole: Ersatzrolle`) prüft für
  `admin`, `Admin` und `moderator`: Ablehnung, keine Umstellung der Konten, kein Ereignis, Rolle und
  Rechtezeilen bleiben bestehen. **Status:** umgesetzt. **Konfidenz:** hoch.

### Geprüft, kein Befund

- Kein Schutz „letzter Administrator“: Weil Selbst-Herabstufung, -Deaktivierung und -Löschung gesperrt sind,
  bleibt der Handelnde Administrator; ein Aussperren ist nur über einen Wettlauf zweier Administratoren
  denkbar und nicht belegt.
- Favoriten ohne Sichtbarkeitsprüfung: Es werden nur Beiträge mit Status `published` und ohne Papierkorb
  zugelassen (`MemberContentService.php:214–219`).

## Testlauf und Übergabe

Lokaler Windows-Checkout, PHP **8.4.28 CLI (NTS, x64)** mit geladenem `mbstring`, OpenSSL und
`pdo_sqlite`. Geänderte PHP-Dateien zusätzlich mit `php -l` geprüft. Kein Gesamtlauf und keine CI-Abnahme.

| Suite | Exit | Beobachtet |
|---|---:|---|
| `admin-menu` | 0 | **1011 bestanden**, 0 fehlgeschlagen |
| `user-role-hook` | 1 | 140 bestanden, 13 fehlgeschlagen. Alle Fehler liegen in Quelltext-, Dokumentations- und Mutantenprüfungen und sind Windows-Artefakte (Zeilenumbrüche, Pfadtrenner `\` statt `/`, `/dev/null` bei `proc_open`). Die Verhaltensprüfungen einschließlich der neuen Fälle zur Ersatzrolle bestehen. |

**Nächster Schritt:** `user-role-hook` auf demselben Commit in Linux mit LF-Checkout wiederholen. Dann
die in der Matrix genannten Laufzeitprüfungen mit Testkonten je Rolle durchführen (02.1, 02.2, 02.7);
die Suiten `member-area`, `security` und `community-access` wurden in diesem Lauf nicht ausgeführt.

## Nachprüfung unter Linux (Claude Sonnet 5.5, 23.11.2026)

- **Tests:** `admin-menu` ist auf Linux grün (PHP 8.3.6, LF-Checkout), `user-role-hook` mit den Korrekturen aus PR #194 (auf `main` allein
  zwei rote Doku-Prüfungen, siehe Bericht AP-01). Die 13 Fehler des Windows-Laufs sind Zeilenumbruch- und `/dev/null`-Artefakte; die
  Verhaltensprüfungen der Ersatzrolle bestehen unverändert.
- **02.1 skriptgestützt bestätigt:** Von 140 Einstiegsdateien unter `CMS/admin/*.php` haben alle den `ABSPATH`-Wächter. 43 enthalten
  keine eigene Rechteprüfung; sie sind ausschließlich dünne Wrapper, die genau sechs gemeinsame Rahmen laden (`ai-page.php`,
  `logs-page.php`, `member-dashboard-page.php`, `performance-page.php`, `seo-page.php`, `system-monitor-page.php`). Alle sechs
  prüfen `isAdmin()`, Capability und CSRF (`section-page-shell.php:305–386`). Keine Einstiegsdatei mit POST-Verarbeitung ohne
  CSRF-Bezug.
- **02.3 bestätigt:** Die Ersatzrollen-Sperre in `RolesModule::deleteRole()` greift serverseitig nach `sanitizeRoleSlug()` (Groß-/
  Kleinschreibung egal) und vor jeder Schreiboperation; das Ausblenden im Auswahlfeld ist nur Komfort. Kein Umgehungsweg über die
  Umbenennung (Systemrollen sind nicht umbenennbar).
- **Kein neuer Befund** in AP-02.

