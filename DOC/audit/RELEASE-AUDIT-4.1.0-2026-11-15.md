# Release- und Code-Abnahmeprüfung 365CMS 4.1.0 (2026-11-15)

Prüfgegenstand ist die Entwicklungslinie `3.5.0 → 3.5.x → 4.0.0 → 4.1.0`, Schema `v23 → v25 → v26`. Jeder Prüfbereich wird in einem eigenen Pull Request bearbeitet. Dieses Protokoll führt alle Befunde mit ID, Schweregrad, Fundstelle und Status.

| Feld | Wert |
|------|------|
| Stand | `main` @ `a437675` (Release-Kandidat 4.1.0, Schema v26) |
| Baseline Tests | `php TESTS/run-all.php`: 32 Suiten, 0 fehlgeschlagen |
| PHP (Prüfumgebung) | 8.3.6 CLI |
| Schweregrade | `CRITICAL`, `HIGH`, `MEDIUM`, `LOW` |
| ID-Schema | `SEC-410-xx` (Sicherheit), `DB-410-xx` (Schema/Migration), `FUN-410-xx` (Funktion), `PERF-410-xx` (Performance), `DOC-410-xx` (Doku/Release) |

## Prüfbereiche und Status

| PR | Prüfbereich | Status |
|----|-------------|--------|
| #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) | Sicherheit, Kryptografie & Zugriffskontrolle | abgeschlossen: 23 Befunde behoben, 2 bewusst akzeptiert, 1 verworfen, 1 an PR #3 übergeben |
| #2 | Datenbank-Schema, Migrationen & Upgrade-Pfad (`v23 → v25 → v26`) | abgeschlossen: 3 Befunde behoben, 1 dokumentiert, 1 verworfen (DB-410-01) |
| #3 | Features & Workflows seit 3.5.0 (Funktionsprüfung) | ausstehend |
| #4 | Regressionen, Altlasten, PHP 8.4.1+/8.5 | ausstehend |
| #5 | Performance, Caching, Core Web Vitals | ausstehend |
| #6 | Tests, Dokumentation, Release-Fertigstellung | ausstehend |

## Methodik

1. **Statische Prüfung** des Codes, der seit dem Start der 4.1.0-Arbeiten (`071a7a0`) geändert wurde (208 PHP-/JS-Dateien), plus der Datenflüsse in älteren Code. Die Git-Historie des Repositorys beginnt am 2026-11-12, Tags fehlen; Code aus 3.5.x/4.0.0 wurde deshalb über die betroffenen Bereiche geprüft, nicht über einen Diff.
2. **Vier Stränge:** SSO/Zahlungen/Webhooks/Offsite-Backups (eigene Durchsicht) sowie parallel Zugriffskontrolle (CSRF, Capabilities, IDOR), Injection/XSS/CSP/Host-Header und Passwort/MFA/Passkey/Sitzung.
3. **Verifikation:** Jeder Treffer wurde am Code nachvollzogen; unbestätigte Treffer sind unter „Verworfen“ aufgeführt.
4. **Nach jedem Fix-Block:** `php -l` über alle geänderten PHP-Dateien und `php TESTS/run-all.php`.

**Grenzen:** keine Laufzeittests gegen MySQL/MariaDB, Stripe, Mollie, Entra ID oder S3 in dieser Runde. Die Anbieter-Flüsse sind über die vorhandenen Fake-Transporte der Suiten `payments`, `sso-entra`, `s3-backup` und `webhooks` abgedeckt.

---

## PR #1: Sicherheit, Kryptografie & Zugriffskontrolle

### Befundübersicht

| ID | Schwere | Bereich | Datei:Zeile | Kurzbeschreibung | Status |
|----|---------|---------|-------------|------------------|--------|
| SEC-410-01 | HIGH | SSO / Sitzung | `CMS/core/Auth/SSO/EntraSsoService.php:234`, `CMS/core/Auth/AuthManager.php:479` | Entra-Anmeldung mit lokaler TOTP-Abfrage verliert die SSO-Herkunft → Sperre „Lokale Anmeldung“ meldet verknüpfte Konten sofort wieder ab | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-02 | MEDIUM | SSO / Richtlinie | `CMS/member/includes/class-member-controller.php:1691`, `CMS/core/Auth/SSO/EntraSsoService.php:350` | Konto trennt die Verknüpfung selbst und umgeht damit `disabled_for_linked` (bzw. sperrt sich bei `admins_only` aus) | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-03 | MEDIUM | Zahlungen | `CMS/core/Services/Payments/PaymentService.php:391` | Zustandswechsel „bezahlt“ nicht atomar → zwei Ereignisse aktivieren das Abo doppelt | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-04 | MEDIUM | Zahlungen / Idempotenz | `CMS/core/Services/Payments/PaymentService.php:288` | Interner Fehler markiert das Ereignis dauerhaft als `failed`; die Wiederholung des Anbieters wird als Duplikat verworfen, die Bestellung bleibt offen | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-05 | LOW | Zahlungen / Verlängerung | `CMS/core/Services/Payments/PaymentService.php:530` | Verlängerungen prüfen die Währung nicht; Betragsabweichung nur im Log, nicht am Ereignis | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-06 | – | Webhooks | `CMS/core/Services/Webhooks/WebhookService.php:313` | Vermutete Doppelzustellung bei überlappendem Cron | Verworfen (Cron läuft unter `flock`) |
| SEC-410-07 | MEDIUM | Offsite-Backups | `CMS/core/Services/Backup/OffsiteBackupService.php:333` | Aufbewahrung löscht jedes „backupartige“ Objekt unter dem Präfix: bei leerem/geteiltem Präfix auch fremde Daten | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-08 | MEDIUM | MFA-Zwang / Passwort-Ablauf | `CMS/update.php:47`, `CMS/orders.php:263`, `CMS/core/Services/Security/MfaEnforcementService.php:259` | Direkt ausgelieferte Einstiegsdateien umgehen die Konto-Weiche von `Router::dispatch()` | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-09 | MEDIUM | API / JWT | `CMS/core/Auth.php:946`, `CMS/core/Routing/ApiRouter.php` | Bearer-Tokens (Refresh 30 Tage) ignorieren nachträglich eingeschalteten MFA-Zwang, Passwort-Ablauf und Sperre „Lokale Anmeldung“ | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-10 | LOW | MFA-Zwang | `CMS/core/Services/Security/MfaEnforcementService.php:230` | Lesefehler der Einstellungen schalten den Zwang für die Anfrage ab (fail-open) | Akzeptiert (siehe unten) |
| SEC-410-11 | LOW | HTTPS-Erkennung | `CMS/core/Security.php:93` | `X-Forwarded-Proto`/`X-Forwarded-Ssl` ohne Trusted-Proxy-Prüfung | Akzeptiert (siehe unten) |
| SEC-410-12 | MEDIUM | Backups / CSRF | `CMS/admin/backup-settings.php:71` | Schalter „Backup-Verschlüsselung“ auf `/admin/backup-settings` scheitert immer (Token-Name passt nicht) | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-13 | LOW | Redaktion / Vier-Augen | `CMS/core/Services/Content/ContentWorkflow.php:137`, `:267` | `privat → veröffentlicht` ohne Freigaberecht (auch Sammelaktion) | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-14 | LOW | Redaktionskalender | `CMS/core/Services/EditorialCalendarService.php:625` | Geplante Beiträge per Drag & Drop sofort veröffentlichen/umplanen ohne `posts.publish` | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-15 | LOW | MFA / TOTP | `CMS/core/Auth/AuthManager.php:501` | Replay-Sperre merkt nur den letzten Code und schreibt ohne Vergleich-und-Tausch | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-16 | MEDIUM | Passwort-Richtlinie | `CMS/core/Services/UserService.php:598` | Admin-Bearbeitung: Passwort nur aus Leerzeichen umgeht Richtlinie und Historie | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-17 | LOW | Passkey | `CMS/assets/webauthn/WebAuthn.php:641` | Herkunftsprüfung als Suffix-Regex ohne Punktgrenze (`evilexample.com` ≙ `example.com`) | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-18 | LOW | Passkey-Login | `CMS/core/Auth/AuthManager.php:148`, `CMS/core/Routing/PublicRouter.php:194` | Kein Rate-Limit, keine Ablaufzeit der Challenge, Fehlermeldungen verraten Credential-Existenz | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-19 | LOW | Passwort-Reset | `CMS/core/Services/CmsAuthPageService.php:805` | Reset-Token nicht atomar eingelöst | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-20 | LOW | Downloads | `CMS/core/Services/Downloads/DownloadService.php:801` | Unbekannte `access_level` geben frei (fail-open) | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-21 | LOW | Downloads / CSV | `CMS/admin/modules/downloads/DownloadsModule.php:782` | Download-Statistik-Export ohne Schutz vor Formel-Injection | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-22 | LOW | MFA / Backup-Codes | `CMS/core/Auth/MFA/BackupCodesManager.php:96` | Backup-Code nicht atomar verbraucht | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-23 | LOW | Installer | `CMS/install/InstallerController.php:357`, `CMS/install/InstallerService.php:1135` | Erstes Admin-Passwort ohne Zeichenklassen, ohne Historie und `password_changed_at` | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-24 | LOW | Beiträge / Kategorien | `CMS/admin/posts.php:280` | Kategorie speichern/löschen schon mit `edit_own_posts`/`posts.view` | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-25 | LOW | XSS / JSON-Konfiguration | `CMS/admin/views/legal/cookies.php:599`, `CMS/admin/views/legal/data-requests.php:339`, `CMS/admin/views/plugins/marketplace.php:348` | JSON in `<script type="application/json">` ohne `JSON_HEX_TAG` (Marketplace-Daten kommen von außen) | [x] Behoben in PR #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) |
| SEC-410-26 | LOW | RBAC / Admin-Zugang | `CMS/core/Routing/AdminRouter.php:82` | Neue Capabilities (`payments.manage`, `blocks.reusable`, …) lassen sich nicht an Nicht-Admin-Rollen delegieren: jede Admin-Seite verlangt zusätzlich `isAdmin()` | [ ] Offen → PR #3 (Entscheidung Funktionsumfang) |

Querverweise in andere Prüfbereiche:

| ID | Schwere | Datei:Zeile | Kurzbeschreibung | Status |
|----|---------|-------------|------------------|--------|
| DB-410-01 | – | `CMS/core/MigrationManager.php:297` → `CMS/core/Services/Content/ContentWorkflow.php:743` | Upgrade gibt `author` das Recht `posts.publish`, Neuinstallation angeblich nicht | Verworfen in PR #2 (Laufzeittest: beide Wege identisch, siehe unten) |
| DOC-410-02 | HIGH | `RELEASE/365CMS-4.1.0-full.zip`, `RELEASE/365CMS-4.1.0-update.zip` | Bereits gepackte 4.1.0-Pakete enthalten u. a. DB-410-02; nach PR #1–#5 neu packen (SHA-256, `update.json`, `RELEASE/365CMS-4.1.0-update.json`, `-SHA256SUMS.txt`) | [ ] Offen → PR #6 |
| DOC-410-01 | LOW | `Changelog.md` (v4.1.0, „Tests“) | Changelog nennt 30 Suiten, `run-all.php` führt 32 (mit PR #2: 33) aus; `DOC/FILESTRUCTUR.md` in PR #2 bereits korrigiert | [ ] Offen → PR #6 |

### Details

#### SEC-410-01 (HIGH): SSO + lokales TOTP: Sitzung verliert die SSO-Herkunft

- **Ursache:** `EntraSsoService::finishLogin()` setzt bei fehlendem `amr`-Nachweis und aktivem TOTP nur `mfa_pending_user_id`. Nach dem Code ruft `AuthManager::verifyMfa()` `completeLoginForUserId()` auf; `Auth::completeAuthenticatedSession()` entfernt alle SSO-Merkmale (`auth_sso_user_id`, `auth_sso_at`). Niemand setzt sie wieder.
- **Folge:** Bei `local_login = disabled_for_linked` oder `admins_only` hält `Auth::checkSession()` die Sitzung für lokal begonnen und beendet sie mit der nächsten Anfrage. Verknüpfte Nicht-Admins mit TOTP können sich gar nicht anmelden. Zusätzlich blieb ein Passwort-Ablauf-Flag aus einem früheren Versuch in derselben Sitzung stehen.
- **Behebung:** Merkmal `auth_sso_mfa_pending_user_id` beim Übergang zur Code-Abfrage; `AuthManager::completePendingLogin()` stellt nach TOTP oder Backup-Code die SSO-Herkunft über `EntraSsoService::markSsoSession()` wieder her und entfernt das Passwort-Ablauf-Flag. Passwort-, Passkey- und LDAP-Weg verwerfen ein altes Merkmal; `completeAuthenticatedSession()` und `clearMfaPending()` räumen es ab.
- **Tests:** `TESTS/sso-entra` („Audit 4.1.0: SSO-Sitzung nach lokaler Code-Abfrage …“).

#### SEC-410-02 (MEDIUM): Selbst-Trennung umgeht „Lokale Anmeldung“

- **Ursache:** `sso_unlink` auf `/member/security` verlangte nur CSRF und Passwort.
- **Folge:** Ein über Entra offboardetes Konto mit laufender Sitzung (oder bekanntem Passwort) trennt die Verknüpfung und meldet sich danach unter `disabled_for_linked` wieder lokal an. Unter `admins_only` sperrt sich das Konto aus.
- **Behebung:** `EntraSsoService::mayUnlink()`: solange der wirksame Modus nicht `all` ist, trennt nur ein Admin. Meldung `unlink_blocked`.

#### SEC-410-03 (MEDIUM): Doppelte Abo-Aktivierung

- **Ursache:** `markPaid()` prüfte `canTransition()` auf einer zuvor gelesenen Zeile und schrieb ohne Bedingung.
- **Folge:** Zwei verschiedene „bezahlt“-Ereignisse (z. B. `checkout.session.completed` und `…async_payment_succeeded`) liefen parallel durch; `assignSubscription()` und Mollie-Abo-Anlage doppelt.
- **Behebung:** `UPDATE … SET status = 'paid' … WHERE id = ? AND status IN ('pending', 'failed')`; nur bei `rowCount() === 1` geht es weiter.

#### SEC-410-04 (MEDIUM): Ereignis nach internem Fehler verloren

- **Ursache:** Eine Exception in `process()` speicherte das Ereignis als `failed`. Die Wiederholung des Anbieters scheiterte an der Eindeutigkeit (`duplicate`), der Webhook antwortete trotzdem 200.
- **Behebung:** Verarbeitung in einer Transaktion (`InvoiceService` nutzt bereits Savepoints); bei Exception Rollback, Ereigniszeile entfernen, Antwort **500**, damit Stripe/Mollie erneut zustellen. Fachliche Ablehnungen (Betrag, Währung, fremder Checkout) bleiben endgültig `failed`.

#### SEC-410-05 (LOW): Verlängerung ohne Währungsabgleich

- Verlängerung bleibt verbucht (der Anbieter hat abgebucht), aber Betrags- oder Währungsabweichung steht jetzt als Fehlertext am Ereignis (*Zahlungen → Ereignisse*) mit dem Hinweis „Rechnung prüfen“.

#### SEC-410-07 (MEDIUM): Offsite-Aufbewahrung löscht fremde Objekte

- **Ursache:** `applyRetention()` gruppierte alle Objekte unter dem Präfix nach dem ersten Pfadteil, sobald er `NAME_PATTERN` entsprach.
- **Folge:** Unter leerem oder geteiltem Präfix wurden fremde „Ordner“ (`reports/`, `exports/` …) als alte Backups gelöscht.
- **Behebung:** Gelöscht wird nur, was die Statustabelle `backup_offsite_uploads` als eigenes Backup kennt und als `{name}/{datei}` vorliegt. Test: `TESTS/s3-backup`.

#### SEC-410-08 (MEDIUM): Konto-Weiche in direkt ausgelieferten Dateien

- **Ursache:** `.htaccess` leitet nur nicht existierende Pfade auf `index.php`; `/update.php` und `/orders.php` laufen ohne `Router::enforceAccountGates()`.
- **Folge:** Admin unter MFA-Zwang ohne Faktor kann das Datenbank-Update ausführen; abgelaufene Passwörter erreichen den Checkout.
- **Behebung:** Entscheidungslogik nach `MfaEnforcementService::decisionForUser()` verschoben (Router nutzt sie unverändert), neue `enforceOnEntryPoint()` in beiden Dateien. Tests: `TESTS/mfa-enforcement`.

#### SEC-410-09 (MEDIUM): API-Tokens und Kontoregeln

- **Ursache:** `authenticateBearerToken()` und der Refresh prüften nur Signatur, Kontostatus und Passwort-Bindung (`pwf`).
- **Behebung:** `Auth::apiTokenPoliciesAllow()` bei Zugriff und Refresh: Sperre „Lokale Anmeldung“ (`EntraSettings::sessionMayContinue()`), MFA-Zwang ohne Faktor, abgelaufenes Passwort. Tokens tragen die Herkunft der ausstellenden Sitzung (`sso`, `smfa`), der Refresh übernimmt sie. Lesefehler sperren wie bei Sitzungen niemanden aus.

#### SEC-410-12 (MEDIUM): Backup-Verschlüsselung auf der Einstellungsseite

- Die Seiten-Shell löst `admin_backup_settings` ein; `BackupsModule::setEncryption()` erwartet `admin_backups` und versucht danach ein bereits verbrauchtes Token. Ergebnis immer „Keine Berechtigung“. Die Seite markiert jetzt beide Aktionen als geprüft. Kein Sicherheitsrisiko (fail-closed), aber die Offsite-Pflicht „nur verschlüsselt“ war auf dieser Seite nicht einschaltbar.

#### SEC-410-13/14 (LOW): Freigaberecht

- `resolveSaveStatus()`/`partitionByPublishRight()` behandelten `private` wie „bereits veröffentlicht“. Jetzt braucht nur ein bereits öffentlicher oder unveränderter Status keine neue Freigabe.
- Redaktionskalender: `MOVE_RESCHEDULE` und `MOVE_PUBLISH_NOW` verlangen `posts.publish`/`pages.publish` (ohne Vier-Augen-Bedingung, die Freigabe ist bereits erfolgt).

#### SEC-410-15/22 (LOW): TOTP- und Backup-Codes

- TOTP: alle im 120-s-Fenster akzeptierten Codes (höchstens 10) werden gemerkt; Schreiben per `INSERT IGNORE` bzw. `UPDATE … WHERE meta_value = ?`. Parallele Anfragen mit demselben Code: nur eine gewinnt.
- Backup-Codes: Verbrauch per Vergleich-und-Tausch auf dem gespeicherten JSON.

#### SEC-410-16 (MEDIUM): Leerzeichen-Passwort

- `validateUserData()` (Kontext `update`) prüfte nur bei `trim($password) !== ''`, `updateUser()` setzt bei `!empty()`. Ein Passwort aus Leerzeichen wurde ungeprüft gesetzt. Jetzt ohne `trim()`.

#### SEC-410-17/18 (LOW): Passkey

- Gebündelte lbuchs-Bibliothek: `_checkOrigin()` vergleicht exakt bzw. mit Punktgrenze (Patch mit Kommentar markiert).
- Login: Rate-Limit `passkey_login` (20 Fehlversuche je IP in 15 min), Challenge höchstens 5 Minuten gültig, eine einheitliche Fehlermeldung; Details im Audit-Log.

#### SEC-410-19/20/21/23/24/25 (LOW)

- **19:** Reset-Token wird per `DELETE … WHERE token = ?` mit `rowCount() === 1` eingelöst, bevor das Passwort gesetzt wird.
- **20:** Unbekannte Zugriffsstufe → nur Admins/Redaktion, sonst `ACCESS_ROLE`.
- **21:** CSV-Zellen mit `= + - @ \t \r` am Anfang werden mit `'` entschärft (wie Newsletter-/Formular-Export).
- **23:** Installer prüft die Zeichenklassen der Standard-Richtlinie und schreibt `password_changed_at` sowie den ersten Historien-Eintrag.
- **24:** Kategorie-Aktionen nur mit `edit_all_posts` oder `manage_settings`.
- **25:** `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` in den drei JSON-Konfigurationsblöcken.

### Akzeptiert und verworfen

- **SEC-410-06 (verworfen):** `WebhookService::deliverDue()` beansprucht Zustellungen nicht atomar. Alle Cron-Aufrufe laufen über `CronRunnerService` mit exklusivem `flock` (`cache/.cron-run.lock`); `mail-queue` und `hourly` laufen innerhalb eines Laufs nacheinander. Keine Doppelzustellung.
- **SEC-410-10 (akzeptiert):** Fail-open bei nicht lesbaren MFA-Einstellungen ist bewusst dokumentiert („lieber nicht aussperren“) und wird protokolliert; ein Fail-closed ohne zweiten Speicher würde bei einer DB-Störung alle Admins aussperren.
- **SEC-410-11 (akzeptiert):** Ein Client kann HTTPS nur für die eigene Anfrage vortäuschen; Reverse-Proxy-Installationen ohne konfigurierte Trusted Proxies verlassen sich auf das Verhalten (auch `.htaccess` setzt `HTTPS=on` aus `X-Forwarded-Proto`). Nicht gegen Dritte ausnutzbar.

### Geprüft ohne Befund

- **Entra ID OIDC/PKCE:** nur RS256 vor jeder Schlüsselwahl; JWKS nur RSA/`use=sig`, Algorithmus fest; `iss` exakt, `aud` = Client-ID, `exp`/`nbf`/`iat` mit 120 s, `nonce` per `hash_equals`, `tid`/`oid` GUID, `ver` = 2.0; Mandanten-Allowlist; `state` als SHA-256 in der Sitzung, einmalig, 10 min, höchstens 5 parallel; S256-Challenge; Rate-Limit `sso_callback` (20/15 min, kein Zählen ohne eigenen Start); `SameSite=Strict`-Zwischenseite mit `hop=1` und geprüften Parametern; Weiterleitung nur nach `https://login.microsoftonline.com/`; keine Tokens gespeichert.
- **Kontoübernahme (GHSA-6hhv-gc4g-f4r7):** keine Zuordnung über `preferred_username`/`upn`; Auto-Verknüpfung nur Single-Tenant, `xms_edov`, Domain-Allowlist, genau ein Kandidat, lokal bestätigte Adresse, keine Rolle `admin`/`editor`/`author`/MFA-Zwang; Konflikt → Ablehnung; inaktive Konten nie reaktiviert; `admin`-Mapping nur mit `allow_admin_mapping`; Verknüpfen nur für das startende Konto.
- **Sperre „Lokale Anmeldung“:** nach dem Faktor in Passwort-, Passkey- und LDAP-Weg sowie in `Auth::checkSession()`; wirksam nur bei bereitstehender Entra-Konfiguration.
- **Passwort-Richtlinie:** `\p{Lu}`, `\p{Ll}`, `\p{Nd}`, `[^\p{L}\p{N}]` mit `/u`, `mb_strlen`; Historie per `password_verify`, inkl. aktuellem Hash, gekürzt per `OFFSET`; `recordChange()` in Registrierung, Reset, Mitgliederbereich, Admin-Anlage und -Bearbeitung.
- **MFA-Zwang:** `decide()` lässt nur `/mfa-setup`, `/member/security`, `/logout` und Assets zu; API → 403 JSON; Selbstaussperr-Schutz (`wouldLockOut`, `canRemoveFactor`); Pending-Zustand ohne `user_id`, 10 min TTL, Limits je IP und Konto vor der Prüfung gezählt; Passkey `user_verified` nur nach signaturgeprüfter UV-Assertion.
- **Stripe/Mollie:** Stripe-Signatur `t`/`v1` mit 300 s Toleranz, mehrere `v1`, `hash_equals`; Mollie nur per Abruf mit eigenem Key; Idempotenz über eindeutigen Schlüssel `(provider, provider_event_id)`; Betrag und Währung gegen die Bestellung; Checkout-ID-Bindung; signierte Rücksprung-URL (HMAC, 128 Bit, `hash_equals`); Rate-Limit 300/min und 64 KB.
- **Outbound-Webhooks:** nur HTTPS, keine internen Hosts/IPs/Zugangsdaten, erneute Prüfung bei jeder Zustellung; `Http\Client` ohne Redirect-Folgen, DNS-Pinning per `CURLOPT_RESOLVE`, Global-Range-Prüfung inkl. NAT64 und IPv4-mapped; Geheimnis per `CryptoService` mit Bindung `webhook:{id}`; Signatur `v1=HMAC(secret, ts.body)`; 410 und 20 Fehlschläge deaktivieren.
- **S3:** nur `CMSENC2`-Dateien und Manifest mit `encryption`; XML ohne DOCTYPE/ENTITY, `LIBXML_NONET`, 5 MB; privater Host nur für genau den konfigurierten Hostnamen; SigV4; Multipart mit `abort` bei Fehlern; Backup-Pfad per `realpath` eingegrenzt.
- **Injection/XSS/CSP:** alle interpolierten `LIMIT`/`ORDER BY`/Spalten sind gecastet oder per Whitelist; Ausgaben in Admin-, Member-, Theme- und Auth-Views escaped; EditorJs-Renderer und `<picture>`/AVIF mit URL-Allowlist; keine Inline-Handler, kein `eval`/`new Function` in eigenem JS, Editor.js-Bundles gepatcht (`TESTS/security/csp-bundles.php`); `unserialize` nur mit `allowed_classes => false`.
- **Host-Header, Redirects, Storage:** `cms_runtime_host()` mit Trusted-Proxy-Prüfung und Host-Allowlist; Login-/SSO-Redirects über Routen-Whitelist, `Router::redirect()` lehnt `//`, Schemata und Steuerzeichen ab; `PrivateStorageService` mit `realpath`-Prefix, NUL-Byte-Sperre, Auslieferung nach Berechtigung mit `nosniff` und Sandbox-CSP.
- **CSRF/Capabilities/IDOR:** alle POST-Handler der geänderten Admin-Seiten prüfen ein aktionsbezogenes Token vor der Verarbeitung; JSON-APIs prüfen Login, Capability, Methode und Token; keine zustandsändernden GETs; Mitglieder-Rechnungen, Passkeys und Sitzungen auf das eigene Konto beschränkt; alle verwendeten Capabilities sind definiert.

### Verifikation PR #1

- `php -l`: alle 33 geänderten bzw. neuen PHP-Dateien (27 unter `CMS/`, 6 unter `TESTS/`) ohne Fehler.
- `php TESTS/run-all.php`: 32 Suiten, 0 fehlgeschlagen.
- Neue bzw. erweiterte Tests: `TESTS/security/audit-410.php` (neu), `TESTS/payments`, `TESTS/sso-entra`, `TESTS/mfa-enforcement`, `TESTS/s3-backup`, `TESTS/content-workflow`.
- Laufzeit-Stichprobe der gepatchten Passkey-Herkunftsprüfung: `example.com`, `a.example.com` und `EXAMPLE.com` erlaubt; `evilexample.com` und `http://example.com` abgelehnt.

---

## PR #2: Datenbank-Schema, Migrationen & Upgrade-Pfad (`v23 → v25 → v26`)

### Vorgehen

Laufzeitprüfung gegen **MariaDB 10.11** (Strict Mode `STRICT_TRANS_TABLES`, zusätzlich der MySQL-8-Standard mit `ONLY_FULL_GROUP_BY`, `NO_ZERO_DATE`, `NO_ZERO_IN_DATE`):

1. **Neuinstallation v26** wie `install.php`: alle `SchemaManager::getSchemaQueries()`, Schema-Marke v26, danach erster Request (`Database::instance()` → Laufzeitprüfung).
2. **Ausgangsstände:** ausgeliefertes Paket `RELEASE/365CMS-4.0.0-full.zip` (4.0.0, v25, 76 Tabellen) und Git-Tag `V3.5.00` (3.5.4, v23, 44 Tabellen), jeweils mit Testdaten (veröffentlicht ohne `published_at`, Papierkorb, entzogenes Recht).
3. **Upgrade** mit dem 4.1.0-Code: erster Request (automatische Migration), danach *Datenbank-Update* (`DatabaseUpdateRunner::run()`), danach zwei weitere Läufe (Idempotenz).
4. **Vergleich** über `information_schema`: Tabellen und Engine, Spalten mit Typ, NULL, Default und Extra, Indizes mit Spalten, Präfixlänge und Eindeutigkeit, Fremdschlüssel.

**Ergebnis:** Neuinstallation und beide Upgrade-Pfade ergeben **strukturell dasselbe Schema** (91 Tabellen), auch im MySQL-8-Modus. Bestandsdaten bleiben erhalten, `published_at` wird nachgezogen, `posts.status` ist VARCHAR(20) und nimmt `private` an. Weitere Läufe ändern nichts. Abweichend ist nur die Kollation alter Tabellen (DB-410-03).

Der Ablauf ist als Test-Suite `TESTS/schema-upgrade` festgehalten. Sie ist optional und läuft nur mit `CMS_TEST_DB_HOST`/`CMS_TEST_DB_USER`, sonst mit Hinweis übersprungen. Gegenprobe: Ohne den Fix für DB-410-02 schlägt sie fünfmal an.

### Befundübersicht

| ID | Schwere | Bereich | Datei:Zeile | Kurzbeschreibung | Status |
|----|---------|---------|-------------|------------------|--------|
| DB-410-02 | HIGH | Erster Request / Verbindungen | `CMS/includes/functions/roles.php:262`, `CMS/core/SchemaManager.php:2511`, `CMS/core/MigrationManager.php:303` | Rekursion im Konstruktor von `Database`: 152 Verbindungen und 7,4 s beim ersten Request nach Installation, Update oder Reparatur, bis „Too many connections“ | [x] Behoben in PR #2 |
| DB-410-03 | LOW | Kollation | `CMS/core/SchemaManager.php:117` | Mit 3.5.x oder älter angelegte Tabellen tragen die Server-Standardkollation (MariaDB `utf8mb4_general_ci`, MySQL 8 `utf8mb4_0900_ai_ci`), neue Tabellen `utf8mb4_unicode_ci` | Dokumentiert (kein aktiver Fehler, siehe unten) |
| DB-410-04 | MEDIUM | Datenbank-Update | `CMS/core/DatabaseUpdateRunner.php:205` | Integritätsprüfung vor dem Schreiben der Schema-Marke prüfte nur `ai_quota_usage`; fehlende v25/v26-Tabellen und -Spalten meldeten trotzdem „erfolgreich aktualisiert“ | [x] Behoben in PR #2 |
| DB-410-05 | MEDIUM | Reparatur | `CMS/core/MigrationManager.php:64`, `:74` | Spalten, die nur der `MigrationManager` anlegt (`login_attempts.action`, `mail_queue.locked_at`, `post_revisions.fingerprint`, `roles.sort_order` …), wurden nach gespeicherter Marke v26 nie mehr ergänzt, auch nicht über *Datenbank-Update* oder *Tabellen reparieren* | [x] Behoben in PR #2 |

### Details

#### DB-410-02 (HIGH): Verbindungsrekursion beim ersten Request

- **Ursache:** `Database::__construct()` ruft `SchemaManager::createTables()` auf; ohne Flag-Datei laufen `MigrationManager::run()` und `ensureFeatureSchema()`. Beide rufen seit 4.1.0 (Bereich 3a, PR #147) `cms_ensure_role_permissions_table()` auf, das `Database::instance()` nutzt. Weil die Instanz erst nach dem Konstruktor gesetzt wird, baut jeder Aufruf eine neue Verbindung und startet die Schemaprüfung erneut, bis der Server `Too many connections` meldet.
- **Gemessen:** 152 Verbindungen und 7,4 s für einen Request (`max_connections = 151`). Betroffen ist jeder erste Request nach Neuinstallation, Core-Update (neue Flag-Datei) und „Tabellen reparieren“. Auf Shared Hosting mit `max_user_connections` von 10–25 scheitern dabei auch gleichzeitige Besucher. Das ausgelieferte 4.0.0 ist nicht betroffen; die bereits gepackten 4.1.0-Pakete schon (DOC-410-02, Neupacken in PR #6).
- **Behebung:** `cms_ensure_role_permissions_table(?Database $db = null)`; `SchemaManager` und `MigrationManager` übergeben ihre Verbindung. Danach: 1 Verbindung, 0,09 s.

#### DB-410-04 (MEDIUM): Integritätsprüfung des Datenbank-Updates

- `DatabaseUpdateRunner::missingSchemaElements()` prüft mit zwei Abfragen auf `information_schema` alle Tabellen aus `FEATURE_TABLES` und `FEATURE_TABLES_V26`, die Pflichtspalten aus `SchemaManager::REQUIRED_COLUMNS` und `posts.status` als VARCHAR.
- Fehlt etwas, schreibt das Update keine Marke, meldet die fehlenden Elemente im Log, und `getStatus()['schema_missing']` nennt sie auf *Admin → Updates*.
- Getestet: gelöschte Tabelle `webhook_deliveries`, gelöschte Spalten und ein zurückgesetztes ENUM werden erkannt und durch das Update repariert.

#### DB-410-05 (MEDIUM): Reparatur bei gespeicherter Zielversion

- `MigrationManager::repairTables()` führt nach `createTables()` `run(true)` aus: Die idempotenten Migrationen laufen auch bei Marke v26.
- Eine höhere Marke wird weiterhin nie überschrieben. Normale Requests laufen weiter ohne ALTER-Statements.

#### DB-410-03 (LOW, dokumentiert): Gemischte Kollation nach Upgrade von 3.5.x

- Bis 3.5.x legte `getSchemaQueries()` Tabellen mit `DEFAULT CHARSET=utf8mb4` ohne Kollation an; der Server-Standard griff. Seit 4.0.0 gilt fest `utf8mb4_unicode_ci`. Nach dem Upgrade von 3.5.x: 44 Tabellen `general_ci`, 47 `unicode_ci` (MariaDB).
- **Risiko:** „Illegal mix of collations“ nur bei JOIN/UNION/Vergleich von Textspalten über alte und neue Tabellen. Im Core gibt es keine solche Abfrage (alle JOINs zwischen alten und neuen Tabellen laufen über Integer-IDs; die UNIONs in `ContentChangesService`, `EditorialCalendarService` und `SearchTermStatsService` verbinden nur Tabellen gleicher Herkunft).
- **Entscheidung:** keine automatische Konvertierung (`ALTER TABLE … CONVERT TO` baut große Tabellen im Web-Request neu auf und sperrt sie). Hinweis für eigene Plugins: Textvergleiche zwischen Core-Tabellen mit `COLLATE utf8mb4_unicode_ci` formulieren oder Tabellen im Wartungsfenster konvertieren.

#### DB-410-01 (verworfen)

Die Laufzeitprüfung zeigt: `ContentWorkflow::migrateCapabilities()` läuft auch in `ensureFeatureSchema()` und damit bei der Neuinstallation. `editor` erhält `posts.publish`/`pages.publish`, `author` erhält `posts.publish`, bei Neuinstallation und Upgrade gleich. Ein ausdrücklich entzogenes Recht (`granted = 0`) bleibt entzogen.

### Geprüft ohne Befund

- **Versionsmarken:** `SchemaManager::SCHEMA_VERSION` und `MigrationManager::SCHEMA_VERSION` = v26; der Installer setzt `db_schema_version` und `installed_cms_schema_version`; eine höhere oder unbekannte Marke wird nie überschrieben, ein Downgrade blockiert.
- **v26-Tabellen und -Spalten:** alle 14 Tabellen aus `FEATURE_TABLES_V26` sowie `users.password_changed_at`, `passkey_credentials.user_verified`, `expires_at`/`review_due_at`/`*_notified_at` (Beiträge, Seiten, Wissensdatenbank), `posts.status` VARCHAR(20), `trash_slug`, `has_locale_*`, `planned_at`, `content_updated_at`, `author_display_url`, Zahlungsspalten an `orders`/`user_subscriptions`: identisch bei Neuinstallation und beiden Upgrade-Pfaden.
- **Strict Mode:** keine Fehler unter MariaDB-Standard und MySQL-8-Standard-`sql_mode` (Installation, Migration, Backfill, Laufzeitprüfung).
- **Papierkorb aus 3.5.x:** Beiträge mit Status `trash` ohne `trashed_at` erhalten beim ersten Fristlauf einen Zeitstempel (`AbstractContentTrashProvider::expiredIds()`).
- **Core-Update-Tausch:** `UpdateService::RUNTIME_CORE_ENTRIES` enthält `marketplace`; Pakete mit `marketplace/` werden abgelehnt (`TESTS/update-swap`).

### Grenzen

MySQL 8 selbst wurde nicht getestet, nur dessen `sql_mode`. Unter MySQL 8 lautet die Standardkollation alter Tabellen `utf8mb4_0900_ai_ci` (DB-410-03 gilt gleich).

### Verifikation PR #2

- `php -l` über alle geänderten PHP-Dateien ohne Fehler.
- `php TESTS/run-all.php`: 33 Suiten, 0 fehlgeschlagen (ohne Datenbank: `schema-upgrade` mit Hinweis übersprungen).
- `CMS_TEST_DB_HOST=localhost CMS_TEST_DB_USER=root php TESTS/schema-upgrade/run.php`: 30 bestanden, auch mit `CMS_TEST_SQL_MODE` = MySQL-8-Standard.
- Erweitert: `TESTS/schema-v26` (Rekursion, Pflichtspalten, Integritätsprüfung, Reparatur), `TESTS/release-3.4.0` (Prüfung vor dem Schreiben der Marke).

