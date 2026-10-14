# 365CMS – Gesamtaudit 2026-10-14 | Sicherheit

> Übersicht: [README.md](README.md) · Prüfkatalog: [PRUEFKATALOG.md](PRUEFKATALOG.md) · Bezug: OWASP Top 10:2025 (A01 Broken Access Control, A02 Security Misconfiguration, A03 Software Supply Chain Failures, A05 Injection, A06 Insecure Design, A07 Authentication Failures)

## English (summary)

Twelve security findings, all fixed in `3.5.0`:

- **Second factor:** The MFA step had no attempt limit, no expiry and accepted replayed TOTP codes.
- **Duplicate MFA data:** Fresh installs lacked the unique key on `user_meta`, which produced duplicate MFA secrets.
- **Missing password confirmation:** Sensitive account changes did not require the current password.
- **Private files:** Member, import and importer-report files were reachable by URL.
- **Installer:** It enabled debug mode by default.
- **Messages:** Members could reply into foreign threads, and there was no send limit.
- **Client IP:** Several services used `REMOTE_ADDR` instead of the proxy-aware client IP.
- **Checkout:** The public checkout accepted lifetime billing.
- **CSV export:** One export lacked the formula guard.
- **Admin alerts:** `cmsAlert()` used `innerHTML`.
- **CI workflow:** Actions were referenced by mutable tags, one of them `@master`.
- **Page search:** It returned pages scheduled for the future.

## Deutsch

| ID | Schwere | OWASP | Befund | Korrektur |
|---|---|---|---|---|
| SEC-16 | 🔴 | A07 | **Zweiter Faktor brute-force-fähig.** Nach korrektem Passwort ließ `AuthManager::verifyMfa()` beliebig viele TOTP- und Backup-Code-Versuche zu. Der ausstehende Zustand in der Session lief nie ab. Ein gültiger TOTP-Code war im gesamten Prüffenster mehrfach verwendbar (Replay). Bei 10⁶ Codes und ±1 Zeitschritt ist das ohne Limit in Stunden zu erraten. | Nach 5 Fehlversuchen je Passwort-Login muss das Passwort erneut eingegeben werden (dort greift das Login-Limit). Zusätzlich gelten 20 Fehlversuche je IP in 15 Minuten (`mfa_verify` in `login_attempts`). Der ausstehende Zustand verfällt nach 10 Minuten. Ein akzeptierter TOTP-Code wird als HMAC in `user_meta.mfa_last_totp` gemerkt und 120 s lang abgelehnt. Nur sechsstellige Ziffernfolgen werden als TOTP geprüft. |
| SEC-17 | 🟠 | A07 | **`user_meta` ohne Unique-Key bei Neuinstallation.** `uq_user_meta (user_id, meta_key)` legte nur eine Migration an, die der Installer überspringt. Ohne den Schlüssel legt jedes `INSERT … ON DUPLICATE KEY UPDATE` (MFA-Secret, Backup-Codes, Profilfelder) eine **weitere** Zeile an. Lesezugriffe mit `LIMIT 1` liefern dann einen beliebigen, oft veralteten Wert – etwa ein altes MFA-Secret oder bereits verbrauchte Backup-Codes. | `SchemaManager::ensureUserMetaUniqueKey()` bereinigt Dubletten (der jüngste Wert bleibt) und legt den Schlüssel an. Das läuft einmal je Codestand, auch auf bestehenden Installationen. |
| SEC-18 | 🟠 | A07 | **Keine Re-Authentifizierung für sicherheitskritische Änderungen.** Mit einer übernommenen Session (offener Rechner, gestohlenes Cookie) ließen sich ohne Passwort folgende Änderungen vornehmen: MFA abschalten (`/mfa-disable`, Mitgliederbereich), Backup-Codes neu erzeugen, einen eigenen Passkey registrieren, die E-Mail-Adresse ändern und danach das Passwort zurücksetzen. | Neue Methode `Auth::verifyCurrentPassword()`: Rate-Limit `reauth` 10/15 min je IP, Fehlversuche landen im Audit-Log. Pflicht für `handleMfaDisable`, `totp_disable`, `backup_generate`, `passkey_register` und für E-Mail-Änderungen im Profil. Die Formulare haben Passwortfelder und Bestätigungsdialoge. |
| SEC-19 | 🟠 | A01 | **Private Dateien öffentlich.** `uploads/member/…` (Mitglieder-Uploads), `uploads/import/…` sowie `plugins/cms-importer/{reports,wp_import,wp_import_files}/` waren direkt per URL abrufbar. Importberichte enthalten Benutzernamen und E-Mail-Adressen. | `.htaccess`: `[F]` für diese Pfade. Zusätzlich `reports/.htaccess` (deny all) und neu geschriebene `.htaccess` im Importverzeichnis. Berichte werden nur noch über den Admin-Handler `action=download_report` ausgeliefert (Admin-Seite des Plugins, Request-Token, Bericht über `log_id` aufgelöst, `Content-Security-Policy: sandbox`, `nosniff`). nginx-Block in [../INSTALLATION.md](../INSTALLATION.md). |
| SEC-20 | 🟡 | A02 | **Installer aktiviert Debug-Modus standardmäßig.** Die Checkbox war vorausgewählt. Bei aktivem Debug-Modus läuft die CSP nur im Report-Only-Modus, und Fehlermeldungen werden ausführlicher. | Standard ist aus. |
| SEC-21 | 🟠 | A01 | **Nachrichten.** `MessageService::send()` prüfte bei `parent_id` nicht, ob Absender und Empfänger zum Thread gehören. Ein Mitglied konnte so Antworten in fremde Unterhaltungen hängen. Außerdem gab es kein Sendelimit, sodass ein Konto alle Mitglieder zuspammen konnte. | Der Thread wird auf die Wurzelnachricht normalisiert; Absender **und** Empfänger müssen Teilnehmer sein. Sendelimit: 30 Nachrichten je 10 Minuten und Konto. Der Controller zeigt dabei eine Hinweismeldung. |
| SEC-22 | 🟡 | A06 | **Uneinheitliche Client-IP.** Mehrere Stellen lasen `REMOTE_ADDR` direkt statt `Security::getClientIp()` (berücksichtigt `CMS_TRUSTED_PROXIES`): Kommentar- und Legacy-Logs in `PublicRouter`, `UserService`, 404-Protokoll im `Router`, `TrackingService`, `CoreWebVitalsService` und den Web-Vitals-Limiter. Hinter einem Proxy teilten sich alle Besucher eine IP. | Überall `Security::getClientIp()`. |
| SEC-23 | 🟡 | A06 | **Checkout akzeptiert `lifetime`.** `orders.php` übernahm `billing=lifetime` aus dem Request. Pläne haben nur Monats- und Jahrespreise – so ließ sich ein dauerhaftes Abo zum Monatspreis bestellen. | Öffentlich nur `monthly`/`yearly`; alles andere wird zu `monthly`. |
| SEC-24 | 🟡 | A05 | **CSV-Injection.** Der CSV-Export der Seitentabellen (`SiteTableTableRenderer`) übernahm Zellen mit führendem `=`, `+`, `-`, `@` ungeschützt. | `CsvExchange::sanitizeCell()` wie bei allen anderen Admin-Exporten. |
| SEC-25 | 🟡 | A05 | **`cmsAlert()` mit `innerHTML`.** Die Admin-Meldungsfunktion setzte den Text per Template-String in `innerHTML`; der Typ wurde ungeprüft als Klasse übernommen. Aufrufer übergeben teils Serverantworten. | DOM-Aufbau mit `textContent`; Typ per Whitelist (`success`, `danger`, `warning`, `info`). |
| SEC-26 | 🟡 | A03 | **Supply Chain in CI.** `security.yml` nutzte `actions/checkout@v5`, `semgrep-action@v1` und `aquasecurity/trivy-action@master` – Tags bzw. ein Branch, die jederzeit umgehängt werden können. Bei `trivy-action` ist das 2026 tatsächlich passiert. | Alle Actions auf vollständige Commit-SHAs fixiert, die Version steht als Kommentar dahinter. |
| SEC-27 | 🟡 | A01 | **Geplante Seiten in der Suche.** `PageManager::search()` filterte nur `status = 'published'`, nicht `published_at <= NOW()`. Für die Zukunft geplante Seiten tauchten deshalb vorzeitig mit Ausschnitt in der Suche auf. | Zusätzliche Bedingung auf `published_at`. |

### Geprüft ohne Befund

- **SQL:** Prepared Statements in allen neuen und geänderten Abfragen. Tabellennamen stammen ausschließlich aus dem Präfix.
- **CSRF:** Alle neuen Formulare (Re-Auth, Kategorie-/Tag-Beschreibung) laufen über die bestehende Token-Prüfung.
- **CSP:** Durchgängig Nonce und Trusted Types. Neue Inline-Skripte tragen `nonceAttr()`.
- **Sessions:** Regeneration bei Login; nach Passwortwechsel werden andere Sessions beendet (Folgeaudit 2026-10-07).
- **Uploads:** Doppelendungen und gefährliche Endungen sind seit 3.5.0 gesperrt.

### Zunächst offene Empfehlungen (umgesetzt in 3.5.0, siehe [README.md](README.md))

- OFFEN-05: `block_country` vertraut Länderheadern. ✅ umgesetzt in 3.5.0.
- OFFEN-06: Benachrichtigung der alten Adresse bei einer E-Mail-Änderung. ✅ umgesetzt in 3.5.0.
