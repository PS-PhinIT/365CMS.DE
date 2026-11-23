# AP-03 – Eingaben: Injection, Traversal, SSRF (Version 4.2.3)

**Stand 23.11.2026.** Geprüft wurde `main` nach `8679a05` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung und
Skriptsuchen über `CMS/` ohne `vendor/` und `assets/`, dazu ein Laufzeittest der Zieladressen-Prüfung (PHP 8.3.6, Linux,
ohne Datenbank und Netzwerk). Maßstab: [Audit-Katalog, AP-03](../AUDIT-KATALOG-4.2.0.md#ap-03-eingaben-injection-traversal-ssrf).
Der Bericht betrifft nur AP-03. Es wurden keine Konten, Datenbanken oder Produktivsysteme verwendet. Fundstellen sind relativ zur
Repository-Wurzel.

**Ergebnis:** Ein Befund (LOW, Härtung, umgesetzt): Die Zieladressen-Prüfung des HTTP-Clients ließ in IPv6 eingebettete
IPv4-Adressen und Multicast durch. Alle zehn Prüfpunkte sind sonst auf den gelesenen Pfaden erfüllt; was nur mit Datenbank,
Webserver oder externen Diensten prüfbar ist, steht unter „Offen“.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 03.1 SQL | ja | Fremdwerte laufen über Platzhalter. Skriptsuche über `CMS/`: kein Datenbankaufruf mit Superglobal im Argument. Alle `ORDER BY`/`LIMIT`-Interpolationen mit Anfragewerten sind Allowlist oder `(int)`-begrenzt: `CMS/core/Routing/ApiRouter.php:307–314,420–430,511–518` (`in_array(..., true)`, `ASC`/`DESC`, `min`/`max`), `CMS/core/Services/UserService.php:483–484`, `CMS/themes/cms-default/functions.php:2270–2271`, `CMS/core/Services/Forms/FormSubmissionService.php:251` (feste Auswahl), `CMS/core/Services/Downloads/DownloadRepository.php:215` (`SORTS`-Karte). `LIKE`-Muster: 53 Kandidaten, die 8 ohne Maskierung in der Umgebung sind unkritisch (interne Präfixe wie `$this->prefix . '%'`, Pfadpräfixe der Foren, Vorfilter vor `REPLACE()`/Nachfilter `str_starts_with` in `CMS/core/Services/SettingsService.php:128–160`). | Interpolierte Spaltenlisten (`{$set}`, `{$where}`, `{$columns}`) der ~1500 Fundstellen mit `{$…}` einzeln nur dort geprüft, wo Anfragewerte hineinfließen können; Stichprobe ohne Befund. |
| 03.2 Pfade | ja | Medien: `CMS/core/Services/MediaDeliveryService.php:173–205,316–322,440–468` (`..`-Sperre, Steuerzeichen, Elternverzeichnis per `realpath` gegen `UPLOAD_PATH`, private Pfade `member/user-<ID>` nur für Eigentümer, Vergleich ohne Groß-/Kleinschreibung). Theme-Editor: `CMS/admin/modules/themes/ThemeEditorModule.php:277–330,585–590` (Allowlist `[A-Za-z0-9._/-]`, versteckte Segmente, Symlink-Prüfung je Segment, `realpath`-Präfix). Archive vor dem Entpacken geprüft (Pfad, `..`, Laufwerk, Steuerzeichen, Symlink, Größe, Anzahl): `CMS/core/PluginManager.php:737–800`, `CMS/core/Services/BackupService.php:1712–1790`, `CMS/core/Services/UpdateService.php:2011–2030`, `CMS/admin/modules/system/DocumentationGithubZipSync.php:434–470`, `CMS/core/Services/Exchange/ContentExchangeService.php:598–610`. Skriptsuche: nur zwei Funktionen mit Superglobal und Dateizugriff ohne Schutzmuster, beide Fehlalarm (`FeedService::serveRssFeed` Dateiname aus Hash, Sprache per Allowlist `:96–105`). | Datei-Symlink **im** Upload-Ordner: `MediaDeliveryService::resolveAbsolutePath()` prüft nur das Elternverzeichnis per `realpath`, nicht die Datei selbst. Anlegen setzt Dateisystemzugriff voraus (kein Weg über die Anwendung); als INFO geführt. |
| 03.3 Prozesse | ja | Einziger Aufruf von `exec()`: `CMS/admin/modules/system/DocumentationSyncEnvironment.php:155–170` mit Befehls-Allowlist (`isAllowedCommand`); alle Argumente `escapeshellarg()` (`CMS/admin/modules/system/DocumentationGitSync.php:38–86,150–163`), Werte stammen aus Konfiguration, nicht aus der Anfrage. `SystemInfoModule.php:2025–2032` erzeugt nur Anzeigetext mit `escapeshellarg`. Kein `shell_exec`/`system`/`passthru`/`proc_open`/`popen`/Backtick im eigenen Code (Rezept Anhang B). | – |
| 03.4 Einbindung | ja | `include`/`require` mit Variablen nur für interne Pfade: Router, Theme-Datei (`ThemeManager.php:222,405` mit Pfadprüfung `:243`), Mitgliederseiten (`CMS/core/Routing/MemberRouter.php:103–125`: Muster `^[a-zA-Z0-9_-]+$` und Sperrliste interner Vorlagen), Adminseiten (`AdminRouter.php:112–126`). `unserialize` nur mit `allowed_classes => false` (`CMS/includes/functions/escaping.php:370`, `CMS/plugins/cms-importer/includes/class-xml-parser.php:1403`). `extract()` nur mit `EXTR_SKIP` auf interne Arrays (`Router.php:1265`, `PublicController.php:657`, `section-page-shell.php:457`, `InstallerController.php:512`). | – |
| 03.5 SSRF | ja (ein Härtungsbefund) | Alle ausgehenden Abrufe laufen über `CMS/core/Http/Client.php`: einziger `curl_init` (`:206`), kein Redirect-Folgen (`:209`), Schema nur http(s), keine Zugangsdaten in der URL, Zielauflösung mit DNS-Pinnung (`CURLOPT_RESOLVE`, `:250`) und Prüfung der tatsächlich verbundenen Adresse (`:272,288`, Schutz gegen DNS-Rebinding). Weiterleitungen werden von den Aufrufern neu über den Client angefragt (z. B. `CMS/core/Services/FeedService.php:878–925`). `allowPrivateHosts` nur für Anfragen an die eigene `SITE_URL` (Monitoring, Cron-Schleife, Health-Check; `MonitoringTrendService.php:554`, `SystemInfoModule.php:2324,2744,2777`), `allowedPrivateHosts` für Ollama und den konfigurierten S3-Host. **Abweichung:** siehe TMP-AP03-1. | Lauf gegen eine echte Netzumgebung mit 6to4-/NAT64-Übersetzer (kein Test im Repository möglich). |
| 03.6 Weiterleitung | ja | Alle `header('Location: …')` mit Variablen nutzen feste Pfade, geprüfte Router-Ziele oder normalisierte Rücksprünge (`PublicRouter.php:176–179,222–247`, `Router::redirect()`), `CMS/admin/error-report.php:53–80` verlangt Pfad unter `/admin` (ein Präfix wie `https://seite.tld.evil/…` fällt auf den Standard zurück). `Router.php:658` (WWW-Alias) nutzt `SITE_URL` plus bereinigte Anfrage-URI ohne Zeilenumbruch. | – |
| 03.7 Header-Injektion | ja | PHP lehnt Header mit Zeilenumbruch selbst ab; zusätzlich sind Dateinamen in `Content-Disposition` intern erzeugt oder bereinigt (`CMS/core/Services/MediaDeliveryService.php:848–852`, `Community/Http/AttachmentController.php:103–118`, `CsvExchange.php:37`). Mail: Kopfzeilen über `sanitizeHeaderName/-Value` (`CMS/core/Services/MailService.php:601–616,1220–1227`), Adressen über `Address::create()`; der Fallback über `mail()` bereinigt PHP selbst (Zeilenumbrüche in Empfänger und Betreff werden ersetzt). | – |
| 03.8 XML, CSV, Arrays | ja | XML: alle Parser ohne `LIBXML_NOENT`/`LIBXML_DTDLOAD`, mit `LIBXML_NONET` (`FeedService.php:975–977`, `OpmlService.php:36–37`, `S3Client.php:276`, Importer `class-xml-parser.php:80`). CSV-Exporte mit Formelschutz (Newsletter, Formulare, Bestellungen, Weiterleitungen/404, Site-Tabellen). YAML nur für eigene Sprachdateien. Arrays statt Zeichenketten: Skriptsuche über alle `trim($_POST[…])`-ähnlichen Aufrufe und Zuweisungen ohne Prüfung findet keinen weiteren Fall (der Checkout wurde im ersten Durchgang behoben, PR #193). | Kopfzeile des Formular-CSV-Exports (Feldnamen aus der Formulardefinition) ohne Formelschutz, nur Admin-gepflegt; INFO (BP-420-04 des ersten Durchgangs). |
| 03.9 Regex | ja | Muster aus Fremdwerten: Wortfilter der Community prüft `preg_*` auf `false` und bricht fail-closed ab (`CMS/core/Services/Community/Service/ContentGuard.php:575–605`); Formularmuster werden vor Gebrauch kompiliert (`FormFieldTypes.php:127–141`) und bei `false` als Fehler gewertet (`FormValidator.php:180–188`). Muster aus Konfiguration (Permalink, Cookie-Manager) sind admingepflegt. | ReDoS-Messung mit Gegenproben für admingepflegte Formularmuster (öffentlich auslösbar, Wirkung durch `pcre.backtrack_limit` begrenzt); als INFO offen. |
| 03.10 Host-Header | ja | Host und `X-Forwarded-*` für die Laufzeit-URL nur über `cms_runtime_host()` mit Trusted-Proxy-Prüfung und Allowlist (`CMS/includes/functions/options-runtime.php:549–552`). Links in Mails und Canonical nutzen `SITE_URL`. Der 404-Log speichert Host und Referer normalisiert und gekürzt (`RedirectService.php:163–168`). `X-Forwarded-Proto` ohne Proxy-Prüfung (`index.php`, `Security.php`, `cron.php`) ist als SEC-410-11 akzeptiert. | – |

## Abweichungen

### TMP-AP03-1 · Zieladressen-Prüfung: eingebettete IPv4 und Multicast (LOW, umgesetzt)

- **Fundstelle:** `CMS/core/Http/Client.php:386–` (`isNonPublicIp()`), genutzt von `Client::request()`, `FeedService`, `UpdateService` und `WebhookService`.
- **Ablauf:** Der Client erlaubt ein Ziel erst, wenn alle aufgelösten Adressen `FILTER_FLAG_GLOBAL_RANGE` bestehen. Gemessen mit PHP 8.3.6 lassen drei Gruppen den Filter passieren, obwohl sie eine interne IPv4-Adresse enthalten oder nicht unicastfähig sind:
  1. IPv4-kompatible Adressen in Hex-Schreibweise (`::7f00:1`, `::a9fe:a9fe`), IPv4-übersetzte (`::ffff:0:7f00:1`) und 6to4 (`2002:7f00:1::`, `2002:a9fe:a9fe::1`). Nur die Punktschreibweise `::127.0.0.1` und `::ffff:a:b` wurden bisher entpackt.
  2. Multicast (`224.0.0.0/4`, `ff00::/8`).
  Ein Angreifer mit Einfluss auf einen abgerufenen Host (Feed-URL, Webhook-Ziel, Bild-URL im Import) kann einen Namen mit AAAA-Eintrag aus diesen Bereichen anlegen.
- **Zweite Schutzschicht:** Die Verbindung muss auf dem Server dorthin geroutet werden (6to4- oder SIIT-Übersetzer, IPv4-kompatibles Tunneling). Das ist auf üblichen Hosting-Servern nicht der Fall; Multicast ist mit TCP nicht erreichbar. Daher keine Ausnutzung belegt, aber die Prüfung war unvollständig gegenüber ihrer Beschreibung im Katalog (03.5: „IPv4-mapped IPv6“ und weitere Einbettungen).
- **Korrektur:** `isNonPublicIp()` liest die Adresse über `inet_pton()` und prüft für die vier Einbettungsformen die enthaltene IPv4-Adresse erneut (`embeddedIpv4()`), unabhängig von der Schreibweise; Multicast ist gesperrt. Öffentliche eingebettete Adressen (`2002:808:808::1`, `::808:808`) bleiben erlaubt.
- **Regression:** `TESTS/security/ssrf-ip.php` mit 14 bisher fehlschlagenden Fällen (gesperrt) und neun Gegenfällen (erlaubt). **Status:** umgesetzt. **Konfidenz:** hoch für die Adressprüfung; keine Netzumgebung zum Routing-Nachweis.

## Geprüft, ohne Befund

- Medienauslieferung, Theme-Editor und alle vier Archivwege (Plugin, Backup, Update, Doku-Sync) gegen Pfaddurchlauf und Symlinks.
- Alle Admin-Sortierungen und Seitengrößen der API gegen Allowlist/Begrenzung.
- Header-Dateinamen, Mail-Kopfzeilen, XML-Parser, Weiterleitungsziele, Host-Header.

## Testlauf und Übergabe

PHP 8.3.6 CLI unter Linux. `php TESTS/security/run.php`: 725 bestanden, 1 fehlgeschlagen (Quellarchiv `CMS_ASSETS/suneditor-3.4.3.zip` fehlt,
unabhängig von diesem Paket). Suiten, die den Client nutzen, sind grün: `webhooks` (73), `payments` (139), `s3-backup` (84),
`update-swap` (35), `sso-entra` (231).

**Nächster Schritt:** AP-04 (Ausgabe: XSS, CSP, Vorlagen). Offene Laufzeitprüfungen aus AP-03: SSRF gegen eine Testumgebung mit
6to4-/NAT64-Übersetzer, ReDoS-Messung der Formularmuster.
