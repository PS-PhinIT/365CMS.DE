# AP-20 – Integrationen und Austausch (Version 4.2.20)

**Stand 24.11.2026.** Geprüft wurde `main` nach `12701b8` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung, Skriptsuchen und Funktionsaufrufe
ohne Datenbank und ohne Browser. Maßstab: [Audit-Katalog, AP-20](../AUDIT-KATALOG-4.2.0.md#ap-20-integrationen-und-austausch). Der Bericht betrifft nur AP-20; er ist keine Freigabe des
gesamten Bereichs. Es wurden keine Browser, Konten oder Produktivsysteme verwendet. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Kein Befund in den gelesenen Teilen. Zwei Hinweise (SMTP ohne Auto-TLS bei „none“, mehrere CSV-Schutzfunktionen). Importe und Ausfallverhalten externer Dienste sind nicht geprüft.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 20.1 Mail | ja (Teil) | SMTP mit `setRequireTls(true)` bei „tls“ und implizitem TLS bei „ssl“ (`CMS/core/Services/MailService.php:507–525`), Zeitlimit (`SMTP_TIMEOUT_SECONDS`), Header-Bereinigung (`sanitizeHeaderValue`, AP-03). Hinweis INFO-1. | Queue (Wiederholung, Giftnachrichten), Absender-Spoofing. |
| 20.2 Importe | ja (Teil) | CSV-Formelschutz in allen Exporten: `CsvExchange::sanitizeCell()` (`CsvExchange.php:63–80`), Formular-Eingänge (`FormSubmissionsModule.php:336–343`), Downloads (`DownloadsModule.php:781–786`), Newsletter (`NewsletterModule.php:287–289`), Bestellungen (`OrdersModule.php`), Tabellen (`SiteTableTableRenderer.php:210–217`). Hinweis INFO-2. | Größe, Zeichensatz, Dubletten und Wiederholbarkeit der Importe; PERF-410-49 (WXR in einer Anfrage) bleibt offen. |
| 20.3 Feeds, Sitemap | ja (Teil) | Sitemaps nur mit Status „published“ und `robots_index` (`SeoSitemapService.php:504, 596, 746, 774`); Feed-Kopf mit `X-Robots-Tag` (`FeedService.php:194`). | Auswahl der Feed-Beiträge im Einzelnen, Bereinigung der Feed-Inhalte. |
| 20.4 Downloads, Wissensdatenbank | offen | Auslieferung siehe AP-06 und AP-04; Rechte je Eintrag in `DownloadService::computeAccessState()` (AP-09, INFO-1). | Zähler, Rechte der Wissensdatenbank. |
| 20.5 Externe Dienste | ja (Teil) | Zeitlimits bei SMTP, KI-Anbietern (AP-19), Webhook-Zustellung (AP-08) und HTTP-Client. | Verhalten bei Ausfall (Entra, LDAP, S3, Zahlungen, Graph) ohne Folgen für die Seite. |

## Abweichungen

Keine.

## Hinweise ohne Änderung

- **INFO-1:** Bei der Einstellung „keine Verschlüsselung“ schaltet `MailService::createTransport()` auch die automatische STARTTLS-Aufrüstung ab (`setAutoTls(false)`); Zugangsdaten gehen dann im Klartext, selbst wenn der Server STARTTLS anbietet. Das ist eine gewählte Einstellung. Als Verbesserung könnte „keine“ künftig opportunistisches TLS erlauben; das würde Server mit unvollständigem STARTTLS betreffen und braucht daher eine Einstellung.
- **INFO-2:** Fünf Stellen schützen CSV-Zellen unterschiedlich: `CsvExchange::sanitizeCell()` (führende Leerzeichen, Vollbreiten-Zeichen, Tabulator als Präfix), die übrigen setzen ein `'` vor `=`, `+`, `-`, `@`, Tabulator, Zeilenumbruch. Die Überschriftenzeile der Formular-Eingänge wird nicht geschützt (Feldnamen legt ein Administrator fest). Zusammenführen auf eine Funktion ist Aufgabe für AP-15.

## Testlauf und Übergabe

Quelltextlesung; Gesamtlauf aller Suiten grün.

**Offen:** 20.1 (Queue), 20.2 (Importe), 20.3, 20.4, 20.5 im Ausfallfall.
