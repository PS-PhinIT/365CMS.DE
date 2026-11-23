# Release- und Code-Abnahmeprüfung 365CMS 4.1.0 (2026-11-15)

> **Historischer Snapshot:** Dieses Protokoll schließt die 4.1-Linie ab. Aktueller Produktstand ist 4.2.0 / Schema v27; Aussagen zu 4.1.8 oder ungepackter 4.1.9 bezeichnen ausschließlich den damaligen Prüfzeitpunkt. Aktuelle Tests werden mit `php TESTS/run-all.php` dynamisch entdeckt.

Prüfgegenstand ist die Entwicklungslinie `3.5.0 → 3.5.x → 4.0.0 → 4.1.0`, Schema `v23 → v25 → v26`. Jeder Prüfbereich wird in einem eigenen Pull Request bearbeitet. Dieses Protokoll führt alle Befunde mit ID, Schweregrad, Fundstelle und Status.

| Feld | Wert |
|------|------|
| Stand | `main` @ `a437675` (Release-Kandidat 4.1.0, Schema v26) |
| Abschluss | 2026-11-17: 145 Befunde, 136 behoben, 4 verworfen, 3 akzeptiert, 1 dokumentiert, 1 offen (PERF-410-49); siehe „PR #6: Audit-Abschluss und Abschlussbilanz“ |
| Baseline Tests | `php TESTS/run-all.php`: Discovery-Lauf am 4.1.0-Prüfpunkt, 0 fehlgeschlagen |
| PHP (Prüfumgebung) | 8.3.6 CLI |
| Schweregrade | `CRITICAL`, `HIGH`, `MEDIUM`, `LOW` |
| ID-Schema | `SEC-410-xx` (Sicherheit), `DB-410-xx` (Schema/Migration), `FUN-410-xx` (Funktion), `PERF-410-xx` (Performance), `DOC-410-xx` (Doku/Release) |

## Versionen

Die Korrekturen erschienen als Patch-Releases, je Prüfbereich eine Version. Beim Abschluss dieses historischen Prüflaufs war `RELEASE/365CMS-4.1.8-*` das letzte gepackte 4.1-Paket; der aktuelle 4.2.0-Paketstand ist davon getrennt dokumentiert.

| Version | Prüfbereich | Pull Requests |
|---------|-------------|---------------|
| 4.1.1 | PR #1: Sicherheit, Kryptografie & Zugriffskontrolle | [#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158) |
| 4.1.2 | PR #2: Datenbank-Schema, Migrationen & Upgrade-Pfad | [#162](https://github.com/PS-PhinIT/365CMS.DE/pull/162) |
| 4.1.3 | PR #3: Features & Workflows seit 3.5.0 | [#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163) |
| 4.1.4 | PR #4: Regressionen, Altlasten, PHP 8.4.1+/8.5 | [#164](https://github.com/PS-PhinIT/365CMS.DE/pull/164), [#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165) |
| 4.1.5 | PR #5: Performance, Caching, Core Web Vitals (Zwischenstand) | [#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166) |
| 4.1.6 | Entscheidungen: FUN-410-17/18/19, SEC-410-26, PERF-410-46 … -49, DOC-410-01 | [#167](https://github.com/PS-PhinIT/365CMS.DE/pull/167) |
| 4.1.7 | Zahlungen: FUN-410-21, FUN-410-27 | [#168](https://github.com/PS-PhinIT/365CMS.DE/pull/168) |
| 4.1.8 | FUN-410-34 (verworfen), FUN-410-35 | [#169](https://github.com/PS-PhinIT/365CMS.DE/pull/169) |
| 4.1.9 | Abschluss (PR #6, in der Planung „PR #0“): FUN-410-07, -10, -28, -33, -37, -38, DOC-410-03 | [#170](https://github.com/PS-PhinIT/365CMS.DE/pull/170) (FUN-410-10), [#171](https://github.com/PS-PhinIT/365CMS.DE/pull/171) (FUN-410-38), [#172](https://github.com/PS-PhinIT/365CMS.DE/pull/172) (FUN-410-37), [#173](https://github.com/PS-PhinIT/365CMS.DE/pull/173) (FUN-410-33), [#174](https://github.com/PS-PhinIT/365CMS.DE/pull/174) (Rest, Bilanz). Am damaligen Prüfpunkt lag der Quelltext auf `main`; ein Paket 4.1.9 wurde nicht gebaut. |

## Prüfbereiche und Status

| PR | Prüfbereich | Status |
|----|-------------|--------|
| #1 ([#158](https://github.com/PS-PhinIT/365CMS.DE/pull/158)) | Sicherheit, Kryptografie & Zugriffskontrolle | abgeschlossen: 23 Befunde behoben, 2 bewusst akzeptiert, 1 verworfen, 1 an PR #3 übergeben |
| #2 ([#162](https://github.com/PS-PhinIT/365CMS.DE/pull/162)) | Datenbank-Schema, Migrationen & Upgrade-Pfad (`v23 → v25 → v26`) | abgeschlossen: 3 Befunde behoben, 1 dokumentiert, 1 verworfen (DB-410-01) |
| #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) | Features & Workflows seit 3.5.0 (Funktionsprüfung) | abgeschlossen: 39 Befunde (FUN-410-01 … -39), davon 37 behoben und 2 verworfen (-34, -39); die ursprünglich offenen Funktionslücken sind in 4.1.6 bis 4.1.9 geschlossen |
| #4 ([#164](https://github.com/PS-PhinIT/365CMS.DE/pull/164), [#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) | Regressionen, Altlasten, PHP 8.4.1+/8.5 | abgeschlossen: CI-Wächter für 8.4/8.5, 24 Befunde behoben (2 SEC, 22 FUN inkl. FUN-410-36 aus PR #3), 0 verworfen |
| #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166), Zwischenstand) | Performance, Caching, Core Web Vitals | abgeschlossen bis auf PERF-410-49: 49 Befunde (PERF-410-01 … -49), davon 47 behoben, 1 akzeptiert (-46) und 1 offen (-49, WXR-Import, bewusst getrennt); dazu FUN-410-17/18/19, SEC-410-26 und DOC-410-01 behoben. Folgearbeiten ohne Befund-ID: siehe „Abschlussbilanz“ |
| #6 ([#174](https://github.com/PS-PhinIT/365CMS.DE/pull/174)) | Abschluss: offene Funktionslücken, Tests, Dokumentation, Abschlussbilanz | abgeschlossen: siehe „PR #6: Audit-Abschluss und Abschlussbilanz“ |

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
| SEC-410-26 | LOW | RBAC / Admin-Zugang | `CMS/core/Routing/AdminRouter.php:82` | Neue Capabilities (`payments.manage`, `blocks.reusable`, …) lassen sich nicht an Nicht-Admin-Rollen delegieren: jede Admin-Seite verlangt zusätzlich `isAdmin()` | [x] Behoben in 4.1.6 ([#167](https://github.com/PS-PhinIT/365CMS.DE/pull/167)): delegierbare Seiten Zahlungen, Rechnungen, Block-Vorlagen, Webhooks (Capability genügt); übrige Admin-Seiten weiter nur für Admins |

Querverweise in andere Prüfbereiche:

| ID | Schwere | Datei:Zeile | Kurzbeschreibung | Status |
|----|---------|-------------|------------------|--------|
| DB-410-01 | – | `CMS/core/MigrationManager.php:297` → `CMS/core/Services/Content/ContentWorkflow.php:743` | Upgrade gibt `author` das Recht `posts.publish`, Neuinstallation angeblich nicht | Verworfen in PR #2 (Laufzeittest: beide Wege identisch, siehe unten) |
| DOC-410-02 | HIGH | `RELEASE/365CMS-4.1.0-full.zip`, `RELEASE/365CMS-4.1.0-update.zip` | Bereits gepackte 4.1.0-Pakete enthalten u. a. DB-410-02; nach PR #1–#5 neu packen (SHA-256, `update.json`, `RELEASE/365CMS-4.1.0-update.json`, `-SHA256SUMS.txt`) | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)): neue Pakete 4.1.5 mit Manifest und Prüfsummen; die 4.1.0-Pakete bleiben zur Nachvollziehbarkeit liegen |
| DOC-410-01 | LOW | `Changelog.md` (v4.1.0, „Tests“) | Changelog und `run-all.php` trugen widersprüchliche Suitenstände; `DOC/FILESTRUCTUR.md` wurde in PR #2 korrigiert | [x] Behoben in 4.1.6 ([#167](https://github.com/PS-PhinIT/365CMS.DE/pull/167)); aktuelle Dokumentation verwendet dynamische Discovery statt fester Summen |
| DOC-410-03 | LOW | `DOC_PLAN/v4.1/SCHEMA-v26.md:20`, `01-IDENTITY.md:254`, `02-MEDIA.md:92`, `Changelog.md` (v4.1.0, „Hinweise zum Update“) | Spezifikation und Changelog versprachen, `capabilities_v26` vergebe `security.sso` und `media.tools` an Standardrollen (mit Marker in `settings`). Umgesetzt ist nur die Übernahme von `posts.publish`/`pages.publish` (ohne Marker); `media.tools` gibt es nicht, die übrigen neuen Rechte hat bewusst nur „admin“ | [x] Behoben in 4.1.9 ([#174](https://github.com/PS-PhinIT/365CMS.DE/pull/174)): Dokumente an das Verhalten angepasst, Kommentar im `MigrationManager` korrigiert |

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
- `php TESTS/run-all.php`: damaliger Discovery-Lauf, 0 fehlgeschlagen.
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
| DB-410-02 | HIGH | Erster Request / Verbindungen | `CMS/includes/functions/roles.php:262`, `CMS/core/SchemaManager.php:2511`, `CMS/core/MigrationManager.php:303` | Rekursion im Konstruktor von `Database`: 152 Verbindungen und 7,4 s beim ersten Request nach Installation, Update oder Reparatur, bis „Too many connections“ | [x] Behoben in PR #2 ([#162](https://github.com/PS-PhinIT/365CMS.DE/pull/162)) |
| DB-410-03 | LOW | Kollation | `CMS/core/SchemaManager.php:117` | Mit 3.5.x oder älter angelegte Tabellen tragen die Server-Standardkollation (MariaDB `utf8mb4_general_ci`, MySQL 8 `utf8mb4_0900_ai_ci`), neue Tabellen `utf8mb4_unicode_ci` | Dokumentiert (kein aktiver Fehler, siehe unten) |
| DB-410-04 | MEDIUM | Datenbank-Update | `CMS/core/DatabaseUpdateRunner.php:205` | Integritätsprüfung vor dem Schreiben der Schema-Marke prüfte nur `ai_quota_usage`; fehlende v25/v26-Tabellen und -Spalten meldeten trotzdem „erfolgreich aktualisiert“ | [x] Behoben in PR #2 ([#162](https://github.com/PS-PhinIT/365CMS.DE/pull/162)) |
| DB-410-05 | MEDIUM | Reparatur | `CMS/core/MigrationManager.php:64`, `:74` | Spalten, die nur der `MigrationManager` anlegt (`login_attempts.action`, `mail_queue.locked_at`, `post_revisions.fingerprint`, `roles.sort_order` …), wurden nach gespeicherter Marke v26 nie mehr ergänzt, auch nicht über *Datenbank-Update* oder *Tabellen reparieren* | [x] Behoben in PR #2 ([#162](https://github.com/PS-PhinIT/365CMS.DE/pull/162)) |

### Details

#### DB-410-02 (HIGH): Verbindungsrekursion beim ersten Request

- **Ursache:** `Database::__construct()` ruft `SchemaManager::createTables()` auf; ohne Flag-Datei laufen `MigrationManager::run()` und `ensureFeatureSchema()`. Beide rufen seit 4.1.0 (Bereich 3a, PR #147) `cms_ensure_role_permissions_table()` auf, das `Database::instance()` nutzt. Weil die Instanz erst nach dem Konstruktor gesetzt wird, baut jeder Aufruf eine neue Verbindung und startet die Schemaprüfung erneut, bis der Server `Too many connections` meldet.
- **Gemessen:** 152 Verbindungen und 7,4 s für einen Request (`max_connections = 151`). Betroffen ist jeder erste Request nach Neuinstallation, Core-Update (neue Flag-Datei) und „Tabellen reparieren“. Auf Shared Hosting mit `max_user_connections` von 10–25 scheitern dabei auch gleichzeitige Besucher. Das ausgelieferte 4.0.0 ist nicht betroffen; die bereits gepackten 4.1.0-Pakete schon (DOC-410-02, ersetzt durch die Pakete 4.1.5).
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
- `php TESTS/run-all.php`: damaliger Discovery-Lauf, 0 fehlgeschlagen (ohne Datenbank: `schema-upgrade` mit Hinweis übersprungen).
- `CMS_TEST_DB_HOST=localhost CMS_TEST_DB_USER=root php TESTS/schema-upgrade/run.php`: 30 bestanden, auch mit `CMS_TEST_SQL_MODE` = MySQL-8-Standard.
- Erweitert: `TESTS/schema-v26` (Rekursion, Pflichtspalten, Integritätsprüfung, Reparatur), `TESTS/release-package` (Prüfung vor dem Schreiben der Marke).

---

## PR #3: Features & Workflows seit 3.5.0 (Funktionsprüfung)

### Vorgehen

1. **Laufzeit:** CMS-Kopie gegen MariaDB 10.11 mit dem PHP-Entwicklungsserver, angemeldet als Admin.
   - Abgerufen: alle 115 Admin-Routen aus `AdminMenu\CoreCatalog`, der Mitgliederbereich sowie Frontend, Feed, Sitemap, Suche, Vorschau-Links, Zahlungs-Rücksprung, API und Cron.
   - Ausgewertet: HTTP-Status, PHP-Fehler im Inhalt, PHP-Fehlerlog und CMS-Logs.
   - Ergebnis: keine Fatal Errors, Warnings, Notices oder Deprecations. Alle Admin-Seiten antworten mit 200.
   - **Grenze:** PHP 8.3.6 statt 8.4.1 (8.4 ist in der Prüfumgebung nicht erreichbar). Die Plattformsperre wurde nur in der Testkopie aufgehoben. Alle gebündelten Bibliotheken parsen unter 8.3, und der Code nutzt keine 8.4-only-Funktionen.
2. **Statisch:** drei Stränge.
   - Redaktion: Vier-Augen-Prinzip, Vorschau-Links, EditorJS Block Vorlagen, Ablauf-/Prüfdaten.
   - Medien, Zahlungen, Backups, Webhooks, Feeds.
   - Core-Module und Admin-Shell: Menüprofile, Routen, Hooks, Cron, Papierkorb, Löschfristen.
   - Jeder Treffer wurde am Code nachvollzogen. Unbestätigte Treffer stehen unter „Verworfen“.
3. **Nach den Fixes:** Laufzeit-Crawl wiederholt, dabei die Vier-Augen-Warnung real über das Formular gespeichert, und alle Suiten neu ausgeführt.

### Befundübersicht

| ID | Schwere | Bereich | Datei:Zeile | Kurzbeschreibung | Status |
|----|---------|---------|-------------|------------------|--------|
| FUN-410-01 | MEDIUM | Updates / Doku-Sync | `CMS/core/Services/UpdateService.php:112`, `CMS/admin/modules/system/DocumentationModule.php:104` | Update-Rückfall (GitHub-API), Doku-Sync (codeload-Zip) und Changelog-URL zeigten auf `PS-easyIT/365CMS.DE`; das Projekt liegt unter `PS-PhinIT`. `Http\Client` folgt keinen Weiterleitungen | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-02 | MEDIUM | Vier-Augen-Prinzip | `CMS/admin/modules/settings/SettingsModule.php:485` | Mit weniger als zwei Freigabeberechtigten (z. B. ein einziger Admin) kann nichts Neues mehr veröffentlicht werden; kein Hinweis beim Einschalten | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163), Warnung beim Speichern) |
| FUN-410-03 | MEDIUM | Freigabe | `CMS/admin/modules/posts/PostsModule.php:1513` | „Freigeben“ veröffentlichte ohne Veröffentlichungs-Checkliste (Beitragsbild, Meta-Beschreibung, Kategorie) | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-04 | LOW | Freigabe / Sammelaktion | `CMS/core/Services/Content/ContentWorkflow.php:300` | Sammelaktion „Entwurf“ setzt eingereichte Inhalte ohne Begründung zurück und erschien als begründete Rückgabe | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163), Vermerk im Verlauf) |
| FUN-410-05 | MEDIUM | Block-Vorlagen | `CMS/core/Services/ReusableBlocks/ReusableBlockService.php:483` | Verschachtelungsgrenze (3) nur beim Rendern: tiefere Ebenen fehlten im Frontend still | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-06 | MEDIUM | Block-Vorlagen | `CMS/core/Services/ReusableBlocks/ReusableBlockService.php:564` | Löschen einer Vorlage aktualisierte nur direkte Verwendungen, nicht mittelbare (Beitrag → B → A) | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-07 | MEDIUM | Block-Vorlagen | `CMS/core/Services/EditorJs/EditorJsAssetService.php:89`, `CMS/assets/js/editor-init.js:27` | Werkzeug „Vorlagen“ steht in jedem Editor.js-Editor (auch Wissensdatenbank, Hub-Sites); dort wird keine Verwendung erfasst (keine Löschwarnung, kein Neuindizieren) | [x] Behoben in 4.1.9 ([#174](https://github.com/PS-PhinIT/365CMS.DE/pull/174)): „Vorlagen“ fehlt in der Block-Auswahl und Schnellwahl der Editoren für Wissensdatenbank und Hub-Sites; vorhandene Blöcke bleiben ladbar |
| FUN-410-08 | MEDIUM | Ablaufdaten | `CMS/core/Services/Content/ContentExpiryService.php:177` | Abgelaufen, zurückgestuft und mit gleichem Datum wieder veröffentlicht → lief nie wieder ab | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-09 | MEDIUM | Ablaufdaten / Wissensdatenbank | `CMS/core/Services/Content/ContentExpiryService.php:154`, `CMS/core/Services/Knowledgebase/Http/PublicController.php:245` | Einstellung 410 griff für Wissensdatenbank-Einträge nie | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-10 | LOW | Freigabe | `CMS/core/Services/Content/ContentWorkflow.php:350` | „Zurückgezogen“ löst keinen eigenen Hook aus; Prüfende werden nicht benachrichtigt | [x] Behoben in 4.1.9 ([#170](https://github.com/PS-PhinIT/365CMS.DE/pull/170)): Hook `cms_content_withdrawn`, Benachrichtigung der Prüfenden |
| FUN-410-11 | LOW | Freigabe | `CMS/core/Services/Content/ContentWorkflow.php:461` | `submit()` prüfte den Zustandswechsel nicht (Verlauf und Benachrichtigung trotz parallelem Statuswechsel) | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-12 | LOW | Vorschau-Links | `CMS/core/Services/Content/ContentWorkflow.php:618` | Vorschau-Links auch für bereits öffentliche Inhalte (nur die Oberfläche verhinderte es) | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-13 | LOW | Ablaufdaten | `CMS/core/Services/Content/ContentExpiryService.php:240` | Zurückstufen durch Ablauf meldete `cms_content_status_changed` nicht (Webhooks, Verlauf) | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-14 | HIGH | Medien / AVIF | `CMS/core/Services/Trash/MediaTrashProvider.php:539`, `CMS/core/Services/Media/AvifDerivativeService.php:389` | Papierkorb ließ `.avif`-Fassungen öffentlich liegen; nach erneutem Upload gleichen Namens oder Ersetzen lieferte `<picture>` das alte Bild | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-15 | MEDIUM | Medien / AVIF | `CMS/core/Services/Media/AvifDerivativeService.php:201`, `:377` | Animierte GIFs bekamen eine statische AVIF-/WebP-Quelle, die Animation ging verloren | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-16 | MEDIUM | Medien / AVIF | `CMS/core/Services/Media/AvifDerivativeService.php:130` | AVIF-Unterstützung wurde erst nach dem Speichern der Medien-Einstellungen erkannt; bis dahin keine Fassungen | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-17 | MEDIUM | Medien | `CMS/core/Services/Media/UploadHandler.php:150`, `:212` | Umbenennen/Verschieben lässt Fassungen und Verweise in Inhalten zurück (404, Verwendungsindex veraltet) | [x] Behoben in 4.1.6 ([#167](https://github.com/PS-PhinIT/365CMS.DE/pull/167)): Fassungen wandern mit; verwendete Dateien lassen sich nicht umbenennen oder verschieben (Verweise würden ins Leere zeigen) |
| FUN-410-18 | MEDIUM | Medien / Batch | `CMS/core/Services/Media/AvifRegenerationService.php:160` | Ein Bild, das den Speicher sprengt, hält den AVIF-Neuaufbau dauerhaft an derselben Stelle fest | [x] Behoben in 4.1.6 ([#167](https://github.com/PS-PhinIT/365CMS.DE/pull/167)): Speicher-Vorprüfung und Absturzmarke, das Bild wird übersprungen und als fehlgeschlagen gezählt |
| FUN-410-19 | MEDIUM | Medien / Verwendung | `CMS/core/Services/Media/MediaUsageIndex.php:112`, `CMS/core/Services/ThemeCustomizer.php:574` | Wissensdatenbank-Inhalte werden nicht indiziert; Theme-Reset und Theme-Wechsel indizieren nicht neu | [x] Behoben in 4.1.6 ([#167](https://github.com/PS-PhinIT/365CMS.DE/pull/167)): Extraktor `kb_entry`, Neuindizieren nach Theme-Reset und -Wechsel |
| FUN-410-20 | HIGH | Zahlungen / Abo | `CMS/core/Services/Payments/PaymentService.php:512` | Verlängerung reaktivierte ein gekündigtes bzw. durch Tarifwechsel ersetztes Abo (zwei aktive Abos) | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-21 | HIGH | Zahlungen / Abo | `CMS/core/SubscriptionManager.php:702` | Tarifwechsel kündigt das Abo beim Anbieter nicht (Doppelabbuchung möglich); keine Kündigung durch Mitglieder | [x] Behoben in 4.1.7 ([#168](https://github.com/PS-PhinIT/365CMS.DE/pull/168)): Kündigung beim Anbieter bei Tarifwechsel, Erstattung und durch Mitglieder (zum Periodenende) |
| FUN-410-22 | MEDIUM | Webhooks | `CMS/core/Services/Webhooks/WebhookService.php:384` | Fehlgeschlagenes Testereignis wurde bis zu fünfmal wiederholt und zählte zur Abschaltung | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-23 | LOW | Webhooks | `CMS/core/Services/Webhooks/WebhookService.php:499` | Offene Zustellungen eines abgeschalteten Empfängers gingen beim Wiedereinschalten alle auf einmal hinaus | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-24 | MEDIUM | Offsite-Backups | `CMS/core/Services/Backup/OffsiteBackupService.php:218` | Upload ohne `set_time_limit`/`ignore_user_abort`: großer Upload brach am Zeitlimit ab | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163), der Abbruch eines Multipart-Uploads nach einem Fatal Error bleibt offen) |
| FUN-410-25 | MEDIUM | Offsite-Backups | `CMS/core/Services/Backup/S3Client.php:82`, `:114` | Ein kurzer Fehler eines Teils brach den ganzen Multipart-Upload ab; keine Grenze von 10 000 Teilen | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-26 | MEDIUM | Offsite-Backups | `CMS/core/Services/Backup/OffsiteBackupService.php:282` | Backups, die bei unvollständiger Konfiguration entstanden, wurden nach der Korrektur nie hochgeladen | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-27 | HIGH | Zahlungen | `CMS/core/Services/Payments/StripeGateway.php:160`, `MollieGateway.php:122` | Erstattungen und Rückbuchungen erreichen nie den Status `refunded` (kein Abo-Ende, keine Stornorechnung) | [x] Behoben in 4.1.7 ([#168](https://github.com/PS-PhinIT/365CMS.DE/pull/168)): Stripe `charge.refunded`/`charge.dispute.created`, Mollie `amountRefunded`/`amountChargedBack` → `refunded`, Stornorechnung, Abo-Ende |
| FUN-410-28 | MEDIUM | Zahlungen / Mitglieder | `CMS/core/SubscriptionManager.php:184`, `:396` | Keine Kulanzzeit bis zum Eingang der Verlängerung; `current_period_end` wird nicht angezeigt; „Nächste Verlängerung“ auch für Rechnungs-Abos | [x] Behoben in 4.1.9 ([#174](https://github.com/PS-PhinIT/365CMS.DE/pull/174)): Kulanzzeit für Abos mit Anbieter-Verlängerung, `current_period_end` in der Anzeige, „Nächste Verlängerung“ nur bei Anbieter-Verlängerung |
| FUN-410-29 | MEDIUM | Papierkorb / Medien | `CMS/core/Services/FeatureHooks/MediaFeatureHooks.php:79` | Endgültig gelöschte Hub-Sites blieben im Verwendungsindex der Medien | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-30 | MEDIUM | Papierkorb / Recht | `CMS/core/Services/Trash/PageTrashProvider.php:31` | Seite der Barrierefreiheitserklärung war nicht gegen endgültiges Löschen geschützt | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-31 | LOW | Papierkorb | `CMS/core/Services/Trash/PostTrashProvider.php:85`, `PageTrashProvider.php:125` | Freigabeverlauf und Vorschau-Links blieben nach dem Löschen stehen | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-32 | MEDIUM | Tabellen / Hub-Sites | `CMS/admin/modules/tables/TablesModule.php:25` | Seite „Tabellen“ lud, löschte und duplizierte über die ID auch Hub-Sites (endgültig, am Papierkorb vorbei); Liste filterte erst nach `LIMIT 200` | [x] Behoben in PR #3 ([#163](https://github.com/PS-PhinIT/365CMS.DE/pull/163)) |
| FUN-410-33 | MEDIUM | Plugin cms-importer | `CMS/plugins/cms-importer/includes/trait-admin-cleanup.php:99` | „Zurücksetzen“ löscht Beiträge/Seiten ohne Lösch-Hooks (Verwendungsindizes veraltet) | [x] Behoben in 4.1.9 ([#173](https://github.com/PS-PhinIT/365CMS.DE/pull/173)): abhängige Daten, Caches und Sitemaps, Hook `cms_importer_content_reset` |
| FUN-410-34 | LOW | Wissensdatenbank | `CMS/core/Services/Knowledgebase/Repository/EntryRepository.php:1217` | CSV-Import aktualisiert Einträge im Papierkorb | Verworfen bei Nachprüfung in 4.1.8 ([#169](https://github.com/PS-PhinIT/365CMS.DE/pull/169)): `importPresetEntries()` überspringt Einträge im Papierkorb (`EntryRepository.php:909`, schon vor Prüfbeginn); Regressionstest ergänzt |
| FUN-410-35 | LOW | Feeds | `CMS/core/Services/Feed/AggregatorSettings.php:288` | Aufbewahrung unter 7 Tagen leert wöchentliche Digests | [x] Behoben in 4.1.8 ([#169](https://github.com/PS-PhinIT/365CMS.DE/pull/169)): 1–6 Tage werden auf 7 angehoben, 0 = nie löschen |
| FUN-410-36 | LOW | Altlast | `CMS/core/PageManager.php:218` | `PageManager::deletePage()` ohne Papierkorb und Hooks, ohne Aufrufer | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165), verschiebt in den Papierkorb) |
| FUN-410-37 | LOW | Zahlungen | `CMS/core/Services/Payments/PaymentService.php:427` | Mollie-Abo wird innerhalb der Transaktion angelegt; ein Rollback danach kann zu einem zweiten Abo führen | [x] Behoben in 4.1.9 ([#172](https://github.com/PS-PhinIT/365CMS.DE/pull/172)): Mollie-Abo entsteht erst nach dem Commit |
| FUN-410-38 | LOW | Zahlungen | `CMS/core/Services/Payments/PaymentService.php:592` | `renewalFailed` meldet `order.failed` mit `order_id: 0`; ohne bezahlte Vorlage entsteht keine Folgebestellung/Rechnung | [x] Behoben in 4.1.9 ([#171](https://github.com/PS-PhinIT/365CMS.DE/pull/171)): `order_id` der Bestellung des Abos, Vorlage mit Rückfall, Warnung und Hook ohne Vorlage |
| FUN-410-39 | – | Webhooks | `CMS/core/Services/Webhooks/WebhookService.php:316` | Doppelzustellung bei überlappendem Cron | Verworfen (wie SEC-410-06: `CronRunnerService` hält `flock`) |

Querverweis (behoben in PR #5, [#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)): **PERF-410-01:** *Monitor → Health-Check/Antwortzeit/Warnungen* brauchen 5–8 s je Aufruf (blockierende HTTP-Prüfungen im Web-Request).

### Details zu den behobenen Befunden

- **FUN-410-01:**
  - Alle Projekt-URLs in Code, Manifesten und Doku zeigen jetzt auf `PS-PhinIT/365CMS.DE`.
  - Ein gespeicherter Wert `update_github_repo = PS-easyIT/365CMS.DE` wird beim Laden umgestellt.
  - Die Release-Artefakte in `RELEASE/` folgen mit dem Neupacken (DOC-410-02).
  - Unverändert bleiben der fremde Link `PS-easyIT/WordPress-365network` und Autorenangaben.
- **FUN-410-02:** Beim Speichern des Inhalts-Tabs zählt das CMS aktive Konten mit `posts.publish` (Admins oder Rolle mit Recht). Bei weniger als zwei erscheint ein Hinweis; die Einstellung wird trotzdem gespeichert. Getestet gegen MariaDB über das echte Formular.
- **FUN-410-05/06:**
  - `ReusableBlockService::chainDepth()` und `ancestorDepth()` prüfen beim Speichern die Kette nach unten und die einbindenden Vorlagen nach oben.
  - Das Abschneiden beim Rendern bleibt für Importe als Netz.
  - Das Löschen übergibt `linkedContent()` statt nur der direkten Verwendungen.
- **FUN-410-08:** Nur im Modus „auf Entwurf zurückstufen“ wird die Markierung eines wieder sichtbaren Inhalts zurückgesetzt. Im Modus „nur markieren“ bleibt sie stehen, sonst käme bei jedem Speichern eine neue Meldung.
- **FUN-410-14/15/16:**
  - Der Papierkorb verschiebt `.avif` und `-<größe>.avif` mit; die Wiederherstellung findet sie über dieselbe Liste.
  - `<picture>` (Core und Theme `cms-default`) gibt nur Fassungen aus, die nicht älter als das Original sind.
  - GIFs bekommen keine `<picture>`-Quellen. Animierte GIFs bekommen keine AVIF-Fassung (`isAnimatedGif()`).
  - Ohne gespeichertes Ergebnis wird die AVIF-Unterstützung einmal geprobt.
- **FUN-410-20:** Eine Verlängerung für ein gekündigtes Abo bleibt als `failed` am Ereignis stehen („beim Anbieter beenden und Zahlung prüfen“); das Abo wird nicht reaktiviert.
- **FUN-410-24/25/26:**
  - `set_time_limit(0)` und `ignore_user_abort(true)` gelten auch beim Upload aus der Oberfläche.
  - Jedes Teil wird bis zu dreimal versucht; die Teilgröße wächst bei mehr als 10 000 Teilen.
  - Der Cron holt die drei neuesten lokalen Backups ohne Statuszeile nach.
- **FUN-410-32:** Laden, Speichern, Löschen und Duplizieren gelten nur für Zeilen mit `content_mode <> 'hub'` und `trashed_at IS NULL`. Der Filter läuft in SQL vor dem `LIMIT`.

### Funktionslücken mit Entscheidungsbedarf (Stand 15.11.2026, inzwischen geschlossen)

Status am Ende der Prüfung: FUN-410-17/18/19, -21 und -27 in 4.1.6/4.1.7 behoben, FUN-410-10, -33, -37 und -38 in 4.1.9 (PR [#170](https://github.com/PS-PhinIT/365CMS.DE/pull/170) bis [#173](https://github.com/PS-PhinIT/365CMS.DE/pull/173)), FUN-410-07 und -28 im Abschluss-PR (siehe „PR #6“). Der ursprüngliche Text bleibt zur Nachvollziehbarkeit stehen.

Diese Punkte sind keine Regressionen, sondern unvollständige Funktionen. Sie brauchen eine Produktentscheidung, weil der Umbau über einen Fix hinausgeht. Die Entscheidung fällt mit der Release-Freigabe in PR #6: umsetzen, auf 4.1.1 verschieben oder in den Release-Notizen als Einschränkung nennen.

- **FUN-410-21/27/28 (Zahlungen):**
  - Kündigung beim Anbieter, beim Tarifwechsel und durch Mitglieder.
  - Abbildung von Erstattungen und Rückbuchungen (Stripe `charge.refunded`/`charge.dispute.*`, Mollie `amountRefunded`).
  - Kulanzzeit und Anzeige von `current_period_end`.
  - **Empfehlung:** Bis zur Umsetzung in den Release-Notizen festhalten: „Kündigung und Erstattung beim Anbieter vornehmen, danach das Abo im Admin beenden.“
- **FUN-410-07 (Block-Vorlagen in Wissensdatenbank/Hub-Sites):** Entweder das Werkzeug dort ausblenden (Option je Editor in `editor-init.js`) oder `reusable_block_usage` um `kb`/`hub_site` erweitern.
- **FUN-410-17/18/19 (Medien):**
  - Umbenennen/Verschieben mit Fassungen und Verweisen.
  - Fortschritt des AVIF-Neuaufbaus je Bild speichern.
  - Extraktor für die Wissensdatenbank und Theme-Hooks.
- **FUN-410-10, -33, -34, -35, -37, -38:** kleinere Lücken, siehe Tabelle.

### Geprüft ohne Befund

- **Admin-Menü:** Alle 115 Katalogeinträge zeigen auf vorhandene Dateien mit passender Seiten-ID. Die Profile „Klassisch“ (Gruppen „Inhalte“, „Bausteine“) und „Strukturiert“ führen jede ID genau einmal. Die Capabilities im Menü entsprechen den Prüfungen der Seiten. Alle 7 Badges haben eine Quelle.
- **Routing:** Admin-, Plugin- und Log-Routen sowie die 301-Weiterleitungen der integrierten Plugins zeigen auf vorhandene Seiten. Der Plugin-Workspace wird geladen.
- **Hooks und Cron:** Jeder in `FeatureHooks/*` verwendete Hook hat einen Erzeuger. Die stündlichen und täglichen Aufgaben sind registriert: Löschfristen, Papierkorb, Suchindex, Download-Statistik, Content-Exchange, Newsletter, Feeds, Ablauf/Wiedervorlage und Vorschau-Links.
- **Freigabe-Ablauf:** Übergänge pending → published/private/draft und draft → pending; Zweitperson-Regel einschließlich der einreichenden Person; geplante Freigaben; Parität zwischen Beiträgen und Seiten; Benachrichtigungen ohne Handelnde und Autor.
- **Vorschau-Links:** Hash, TTL, Widerruf, Cron-Bereinigung, 404 für Papierkorb und gelöschte Inhalte.
- **Block-Vorlagen:** Zyklen (direkt, mittelbar, über die EN-Fassung), 20 Revisionen, Cache-/Suchindex-/Sitemap-Aktualisierung beim Speichern, Bestätigung beim Löschen einer verwendeten Vorlage.
- **Medien:** Lightbox mit `<picture>`, Aufräumen der Fassungen auf dem Löschweg ohne Papierkorb, Sperren und Fortsetzen der Batch-Jobs, Verwendungsindex für Beiträge, Seiten, Hub-Sites, Downloads, Formulare und Vorlagen.
- **Zahlungen, Webhooks, Feeds:**
  - Zahlungen: dieselbe Mollie-Webhook-URL bei Checkout und Abo; Rechnung bei Zahlung und Verlängerung innerhalb der Transaktion.
  - Webhooks: Backoff 1/5/30/120/720 min, 410 und 20 Fehlschläge deaktivieren, Testknopf, Protokoll, Reaktivieren.
  - Feeds: Feed-Design mit strikt geprüften Farben und Enums.
- **Core-Module:** Formulare, Newsletter mit Mitglieder-Widget, Wissensdatenbank, Downloads, Feed-Aggregator, MultiSites, Redaktionskalender, Suchindex, Content-Exchange, Barrierefreiheitserklärung (bis auf FUN-410-30), Löschfristen, Memberdashboard.

### Verifikation PR #3

- `php -l` über alle geänderten PHP-Dateien ohne Fehler.
- `php TESTS/run-all.php`: damaliger Discovery-Lauf, 0 fehlgeschlagen.
- Neu: `TESTS/release-4.1.0` (Regressionen der Release-Prüfung ohne Datenbank). Erweitert: `reusable-blocks`, `media-avif`, `content-expiry`, `s3-backup`, `webhooks`, `payments`.
- Laufzeit-Crawl nach den Fixes: 117 Admin- und Mitgliederseiten mit 200, keine PHP-Fehler. Die Vier-Augen-Warnung wurde über das echte Formular gespeichert.

---

## PR #4: Regressionen, Altlasten, PHP 8.4.1+/8.5

PR #4 kam in zwei Schritten: [#164](https://github.com/PS-PhinIT/365CMS.DE/pull/164) (Zwischenstand: CI-Wächter) und [#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165) (Befunde).

### Vorgehen

1. **CI-Wächter ([#164](https://github.com/PS-PhinIT/365CMS.DE/pull/164)):**
   - `security.yml`, Job *PHP Syntax Check* (Matrix 8.4 und 8.5): `php -l` läuft mit `error_reporting=-1`. Compile-Deprecations und Warnings aus eigenem Code lassen den Lint scheitern. Meldungen aus `CMS/vendor/` und `CMS/assets/` erscheinen als Warnung. Bisher meldete `php -l` nur Syntaxfehler.
   - `TESTS/lib/TestRunner.php`: Deprecated, Warning und Notice aus eigenem Code zählen in jeder Suite als Fehler, aus Fremdbibliotheken als Hinweis. Mit `@` unterdrückte Meldungen bleiben unterdrückt.
   - Ergebnis auf GitHub (PHP 8.4 und 8.5): Lint und alle Suiten grün. Eigener Code erzeugt unter beiden Versionen keine Deprecation.
2. **Deterministischer Scan** (895 PHP-Dateien, Tokenizer):
   - Aufrufe entfernter oder umbenannter Methoden und undefinierter Funktionen, Klassen und statischer Methoden.
   - Implizit nullable Parameter (8.4), veraltete Funktionen und Konstanten (8.4/8.5), CSV-Aufrufe ohne `$escape`.
   - Ergebnis: ohne Befund. Falsch-Positive (`new self`, Methoden namens `list()`, Interface- und Trait-Methoden) wurden aussortiert.
3. **Altlasten im Laufzeitbetrieb** (MariaDB 10.11, PHP-Entwicklungsserver, angemeldet als Admin):
   - Die 23 Admin-Weiterleitungen der integrierten Plugins antworten mit 301. Jedes Ziel antwortet mit 200.
   - Nach den Fixes: alle betroffenen Admin-Seiten mit 200 und ohne PHP-Meldung. Dazu 15 Funktionsprüfungen über HTTP mit umgeschalteten Einstellungen: Beiträge pro Seite, Editor-Breite, Marketplace aus/an, Canonical, Schema-Autor, entfernte Felder.
   - `deletePage()` und die DSGVO-Löschung der Feed-Digests wurden im App-Kontext gegen die Datenbank geprüft.
4. **Statische Durchsicht** in vier Strängen, jeder Treffer adversarial verifiziert (22 bestätigt, 0 verworfen):
   - Beitrags- und Seiten-Editor (DE/EN, Autosave, Checkliste, Revisionen).
   - PHP 8.4/8.5 im eigenen Code und in den erreichbaren Bibliotheken.
   - Formulare, SEO, Sitemaps, OPcache.
   - Einstellungen ohne Wirkung, tote Verweise und Routen.

### Befundübersicht

| ID | Schwere | Bereich | Datei:Zeile | Kurzbeschreibung | Status |
|----|---------|---------|-------------|------------------|--------|
| SEC-410-27 | MEDIUM | Beiträge / Zugriff | `CMS/admin/modules/posts/PostsModule.php:1264`, `CMS/admin/posts.php:278` | „DE → EN kopieren“ prüfte den Besitz nicht. Ein Autor mit `edit_own_posts` (sogar ein Konto mit `posts.view`) konnte die EN-Fassung fremder Beiträge überschreiben | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| SEC-410-28 | LOW | Beiträge / Freigabe | `CMS/admin/modules/posts/PostsModule.php:1001` | Ohne Veröffentlichungsrecht ließ sich ein geplanter Beitrag über „Veröffentlichen“ oder ein neues Datum sofort live schalten oder umplanen (Restlücke neben SEC-410-14) | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-40 | LOW | PHP 8.5 / Bibliotheken | `CMS/assets/melbahja-seo/src/Utils/HttpClient.php:81`, `CMS/assets/webauthn/WebAuthn.php:544` | `curl_close()` ohne Versionsprüfung (seit PHP 8.5 veraltet), erreichbar über die Google Indexing API und Passkey-Metadaten | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-41 | LOW | Seiten-Editor | `CMS/admin/views/pages/edit.php:77` | Einstellung „Editorbreite Seiten“ wurde berechnet, aber nie ausgegeben | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-42 | LOW | Checkliste | `CMS/admin/modules/posts/PostsModule.php:2911`, `CMS/core/Services/EditorialCalendarService.php` | Prüfpunkt „Downloads“ galt nur beim Speichern, nicht bei Sammel-Veröffentlichung, Wiederherstellen und Kalender (Checkliste ohne Inhalt) | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-43 | LOW | Weiterleitungen | `CMS/admin/modules/posts/PostsModule.php:1170` | Autosave (seit 4.0) legte 301-Regeln für nie veröffentlichte Beiträge und halb getippte Slugs an | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-44 | LOW | Schlagwörter / Schema | `CMS/core/Services/PostRevisionService.php:979`, `CMS/core/SchemaManager.php:481`, `:525` | Revisionen schnitten Schlagwörter bei 500 Zeichen ab; Wiederherstellen legte ein kaputtes Schlagwort an und verlor den Rest. `posts.tags` und `post_revisions.tags` sind jetzt `TEXT` (Neuinstallation und Upgrade) | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-45 | LOW | Seiten / Revisionen | `CMS/admin/modules/pages/PagesModule.php:1236` | Revisionsvergleich führte „Seitenvorlage“ und „Vorlagen-Zusatzfelder“ doppelt | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-46 | LOW | SEO / Schalter | `CMS/admin/modules/seo/SeoSuiteModule.php:34`, `:52`, `:386` | Schalter ohne Wirkung: „Self-referencing Canonical“ und „Person / Author“ wirken jetzt; FAQ/HowTo/Review/Event, Pinterest Rich Pins, „Breadcrumbs aktiv“ (Doppel) und „Bild-Alt-Texte verpflichtend“ sind entfernt | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-47 | MEDIUM | SEO / Technik | `CMS/core/Services/SEO/SeoHeadRenderer.php:262`, `PostsModule.php:4230`, `PagesModule.php:1607` | „Archive/Tags noindex“, „Pagination-SEO“ und „Weiterleitung bei Slug-Änderung“ wurden gespeichert, aber nie ausgewertet (widersprach `REDIRECTS.md`) | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-48 | MEDIUM | Formulare | `CMS/core/Services/Forms/FormSpamGuard.php:83` | Mit Lazy Sessions wurde die Captcha-Lösung nie gespeichert. Die erste Absendung jedes neuen Besuchers scheiterte | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-49 | MEDIUM | Sitemap | `CMS/core/Services/SEO/SeoSitemapService.php:450` | `en-pages.xml` und `en-posts.xml` ignorierten `noindex` und fremde Canonicals | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-50 | LOW | Einstellungen | `CMS/core/Routing/ThemeRouter.php:737`, `:795`, `:907`, `:1117` | „Beiträge pro Seite“ wurde gespeichert, aber nie gelesen | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-51 | LOW | Bild-Sitemap | `CMS/core/Services/SEO/SeoSitemapService.php:576` | Beitragsbilder fehlten, wenn kein eigenes OG-Bild gesetzt war (leerer String statt NULL) | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-52 | LOW | Sitemap | `CMS/admin/modules/seo/SeoSuiteModule.php:876` | Sitemap-Einstellungen und SEO-Audit-Änderungen verwarfen das gespeicherte Bundle nicht | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-53 | LOW | Sitemap | `CMS/core/Bootstrap.php:918` | Geplante Beiträge erschienen erst nach dem nächsten Tages-Cron in der gespeicherten Sitemap | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-54 | LOW | OPcache | `CMS/core/Services/OpcacheWarmupService.php:483` | Ausschlüsse wurden am absoluten Pfad geprüft. Eine Installation unter `/…/test/…` oder `/…/cache/…` wärmte nichts vor | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-55 | LOW | DSGVO / Feeds | `CMS/core/Services/FeatureHooks/FeedHooks.php` | Ein DSGVO-Löschantrag ließ E-Mail-Digests an die Adresse und das Feed-Abo bestehen (`deleteDigestsByEmail()` ohne Aufrufer) | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-56 | LOW | Einstellungen | `CMS/admin/views/settings/general.php:444` | Altfeld „Google Analytics / Tag Manager ID“ wurde gespeichert, aber nie ausgegeben; die Analytics-Übersicht las es trotzdem | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-57 | LOW | Themes | `CMS/admin/views/themes/list.php:68` | Button „Design-Einstellungen“ führte zu den allgemeinen Einstellungen statt zum Theme-Editor | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-58 | LOW | Marketplace | `CMS/admin/plugin-marketplace.php`, `CMS/admin/theme-marketplace.php` | „Marketplace anzeigen“ aus blendete nur das Menü aus; Katalogabruf und Installation blieben per URL und über Buttons erreichbar | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-59 | LOW | Zahlungen / Module | `CMS/admin/payments.php:20`, `CMS/admin/invoices.php:22` | `/admin/payments` und `/admin/invoices` prüften das Kernmodul nicht (anders als Bestellungen und Pakete) | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |
| FUN-410-60 | LOW | Importer | `CMS/admin/modules/settings/SettingsModule.php:1343` | Rückfallpfad zum Plugin `cms-importer` lag außerhalb von `CMS/`. Die Slug-Reparatur fand bei inaktivem Importer keinen Parser | [x] Behoben in PR #4 ([#165](https://github.com/PS-PhinIT/365CMS.DE/pull/165)) |

FUN-410-36 aus PR #3 (`PageManager::deletePage()`) ist ebenfalls in PR #4 behoben, siehe Tabelle PR #3.

### Details

- **SEC-410-27:** `copyGermanToEnglish()` ruft `canAccessPost()` vor dem Schreiben auf. Die Aktion `copy_de_to_en` verlangt `edit_all_posts` oder `edit_own_posts`. Der Aufruf aus `admin/translations.php` liegt ohnehin hinter `edit_all_posts`.
- **SEC-410-28:** Ist der gespeicherte Beitrag geplant und fehlt das Veröffentlichungsrecht (`ContentWorkflow::canPublishWith`), bleiben Termin und Status erhalten. Die Absicht „Veröffentlichen“ und „als neu behandeln“ werden verworfen.
- **FUN-410-42:** Die Checkliste erhält `content` und `content_en`, auch im Redaktionskalender.
- **FUN-410-43:** Slug-Weiterleitungen entstehen nur, wenn der gespeicherte Beitrag öffentlich war (`PostPublishChecklistService::isLive`), und nie beim Autosave.
- **FUN-410-44:** `SchemaManager::ensureTagsTextColumns()` stellt bestehende Spalten per `MODIFY` auf `TEXT` um. Die Suite `schema-upgrade` (30/30) bestätigt Neuinstallation und alle Upgrade-Pfade. Auf `tags` liegt kein Index.
- **FUN-410-46/47:**
  - Der Canonical-Schalter entfernt nur den automatisch gebildeten Canonical der Anfrage. Redaktionell gesetzte Canonicals bleiben.
  - „Archive/Tags noindex“ gilt für alle Archiv-Basen und Sprachen (`cms_get_archive_base`).
  - „Pagination-SEO“ aus = Canonical ohne Seitenparameter.
  - Die automatische Weiterleitung wird an den Aufrufstellen geprüft, nicht in `RedirectService`, weil die Import-Slug-Reparatur dieselbe Methode nutzt.
- **FUN-410-48:** Die Lösung wird gespeichert, wenn die Sitzung aktiv oder verzögert ist (`Security::isSessionDeferred()`); der Shutdown-Handler startet sie dann.
- **FUN-410-50:** Ohne gespeicherten Wert bleibt die bisherige Vorgabe (Blog 9, Archive 10). Ein gespeicherter Wert (1–100) gilt für Blog, Kategorie, Schlagwort und Autor.
- **FUN-410-53:** Der stündliche Cron (Priorität 46) verwirft das Bundle, wenn seit dem letzten Lauf ein geplanter Beitrag live gegangen ist (`sitemap_scheduled_check_at`).
- **FUN-410-58:** Beide Marketplace-Seiten leiten mit 303 auf die Plugin- bzw. Theme-Liste um; die Buttons sind ausgeblendet.
- **FUN-410-36:** `PageManager::deletePage()` bleibt als Plugin-API erhalten und verschiebt über `PageTrashProvider` in den Papierkorb, mit Lösch-Hooks, Slug-Parken und Aufräumen der Verwendungsindizes. Endgültiges Löschen läuft über den Papierkorb.
- **Gebündelte Bibliotheken:** Die lokalen Anpassungen (`melbahja/seo` `HttpClient.php`, `lbuchs/webauthn` `WebAuthn.php`) sind in `DOC/assets/README.md` und `DOC/assets/ASSET.md` vermerkt, damit ein Update sie nicht überschreibt.

### Geprüft ohne Befund

- **PHP 8.4/8.5 im eigenen Code:**
  - Keine entfernten Sitzungs-INI-Werte und keine `session_set_save_handler`-, `trigger_error(E_USER_ERROR)`-, `lcg_value`-, `mysqli_*`- oder `mhash`-Aufrufe.
  - Kein `$http_response_header`, keine Backticks, keine `(unset)`-Casts, kein `__sleep`/`__wakeup`.
  - `ord()`/`chr()` nur auf Einzelbytes. Keine Kollision eigener Funktionen mit neuen Built-ins (`array_find`, `array_first`, `mb_trim` …).
  - PDO-Treiberkonstanten über `Pdo\Mysql`/`Pdo\Sqlite`. Keine eigenen Implementierungen interner Interfaces (keine Probleme mit vorläufigen Rückgabetypen).
  - Versionsprüfung: Mindestversion 8.4.1 ohne Obergrenze, die 8.5 sperren würde.
- **Erreichbare Bibliotheken:** HTMLPurifier wandelt `E_USER_ERROR` in eine Exception. dompdf 3.1.6 schützt `curl_close`/`imagedestroy` per Versionsprüfung. Die TNTSearch-Konstante `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` liegt nur in Methoden ohne Aufrufer.
- **Editor:**
  - DE/EN-Rundlauf beim Speichern. Autosave ändert öffentliche, geplante und private Beiträge nicht, nur ihre Revision. Die Checkliste wird zweimal geprüft (vor und nach dem Filter).
  - Wiederherstellen und Vergleich von Revisionen, Slug-Parken im Papierkorb.
  - KI-Übersetzung: CSRF, Limits und Chunk-Sitzungen. Editor-API mit Besitzprüfung.
- **Lazy Sessions und Ausgabe:** Reihenfolge der Shutdown-Handler (Sitzung vor IndexNow), keine Sitzungsschreibzugriffe auf Pfaden, die den Puffer verwerfen.
- **TrustedProxyService, OPcache-Vorwärmen** (Klassen-Erkennung, Absturzschutz), **Schriftenverwaltung, DE-Sitemaps, IndexNow.**
- **Routen und Verweise:**
  - Alle 136 Admin-Einstiegsdateien sind im Katalog oder dokumentierte Aliase/Endpunkte, alle Alias- und Legacy-Ziele existieren.
  - Alle fest verdrahteten `/admin/…`-, `/member/…`- und `/api/v1/…`-Verweise in PHP und JS haben eine Datei oder Route. Weiterleitungen in `ThemeRouter` und Downloads stimmen.
- **Verwaiste Einstellungen:** Benachrichtigungseinstellungen im Mitgliederbereich sind als „noch nicht angebunden“ gekennzeichnet. Die Medien-Performance-Schalter sind angebunden.
- **Hinweis:** `CMS/core/Services/ContentLanguageCopyService.php` hat keinen Aufrufer (toter Code, Verhalten wie 3.5.0). Entscheidung im Abschluss: bleibt bestehen, weil der Dienst in `DOC/core/SERVICES.md` als Dienst für Plugins geführt wird (wie `PageManager::deletePage()`); ein Entfernen gehört in ein Major-Release.

### Verifikation PR #4

- `php -l` über alle geänderten PHP-Dateien ohne Fehler (mit `error_reporting=-1`).
- `php TESTS/run-all.php` mit MariaDB: damaliger Discovery-Lauf, 0 fehlgeschlagen (`schema-upgrade` 30/30, `release-4.1.0` 165/165).
- Erweitert: `TESTS/release-4.1.0` um fünf Blöcke für alle Befunde aus PR #4.
- Laufzeit: betroffene Admin-Seiten und Frontend mit 200 ohne PHP-Meldung; 15/15 HTTP-Funktionsprüfungen; `deletePage()` und DSGVO-Löschung im App-Kontext.

---

## PR #5: Performance, Caching & Core Web Vitals (Zwischenstand)

Zwischen-PR [#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166). Der abschließende Teil folgt in einem weiteren PR: skeptischer Gesamt-Review des Diffs und die unter „Folgearbeiten“ genannten Punkte.

### Vorgehen

1. **Laufzeit-Basismessung:**
   - Umgebung: MariaDB 10.11, PHP-Entwicklungsserver.
   - Testdaten: 312 Beiträge, 51 Seiten, 912 Kommentare, 8 Kategorien, 40 Schlagwörter.
   - Abgerufen: alle 166 Routen (Admin, Mitgliederbereich, Frontend), je zweimal (warm).
   - Je Request erfasst: Zeit, Anzahl der Abfragen, doppelte Abfragen, langsamste Abfragen, Spitzenspeicher. Die Instrumentierung von `Database` lief nur in der Testkopie.
   - Anonyme Frontend-Aufrufe: Header (`Cache-Control`, `ETag`, `Set-Cookie`) und 304-Verhalten geprüft.
2. **Statische Durchsicht** in fünf Strängen:
   - Stränge: DB-Abfragen, Caching, blockierende Arbeit im Request, Core Web Vitals, Cron/Batch.
   - Jeder Befund wurde adversarial verifiziert: 61 gemeldet, 49 bestätigt, 12 verworfen.
3. **Umsetzung** in sechs dateidisjunkten Clustern, je Cluster mit eigenen Regressionstests (`TESTS/release-4.1.0/perf-*.php`).

### Befundübersicht

| ID | Schwere | Bereich | Datei:Zeile | Kurzbeschreibung | Status |
|----|---------|---------|-------------|------------------|--------|
| PERF-410-01 | HIGH | Blockierende Arbeit | `CMS/admin/modules/system/SystemInfoModule.php:2463` | Monitor-Seiten (Warnungen, Health-Check, Antwortzeit) rufen die eigene Website synchron per Loopback ab (5 s Timeout): 5–8 s je Aufruf | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-02 | HIGH | Caching | `CMS/core/CacheManager.php:238` | Speichern eines Beitrags oder einer Seite leert das ganze `cache/` und APCu, einschließlich der Schema-Flags; die nächsten Requests laufen den vollen Schema-Abgleich | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-03 | HIGH | Blockierende Arbeit | `CMS/core/Services/UpdateService.php:227` | `/admin/updates` und Monitor-Warnungen laden Plugin-/Theme-Katalog und Update-Adressen bei jedem Aufruf ohne Cache | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-04 | HIGH | Cron/Batch | `CMS/core/Services/MailQueueService.php:582` | `mail_queue` behält jede versendete und fehlgeschlagene Mail (mit HTML) für immer; `mail_log` ohne Löschfrist | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-05 | HIGH | Cron/Batch | `CMS/core/Services/CmsAuthPageService.php:710` | Passwort-Reset und andere Transaktionsmails warten hinter Newsletter-/Digest-Massenversand (8 Mails/min); Links laufen vor der Zustellung ab | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-06 | HIGH | Cron/Batch | `CMS/core/Services/BackupService.php:348` | Datenbank-Backup lädt jede Tabelle vollständig in den PHP-Speicher (gepuffertes `SELECT *`) | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-07 | MEDIUM | DB-Abfragen | `CMS/admin/modules/menus/MenuEditorModule.php:540` | `GET /admin/menu-editor` löscht und schreibt alle Menüeinträge aller Positionen neu (Schreiben beim Lesen, IDs ändern sich) | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-08 | MEDIUM | DB-Abfragen | `CMS/admin/modules/posts/PostsModule.php:395` | Beitrags-/Seitenmodul: bei jedem Request rund 31 `SHOW COLUMNS`, 2 `CREATE TABLE` und 21–30 Kategorieabfragen | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-09 | MEDIUM | DB-Abfragen | `CMS/admin/modules/member/MemberDashboardModule.php:1097` | Profilfelder-Seite lädt alle `member_%`-Einstellungen je Feldprüfung neu (42×) und zählt je Feld zweimal über `users` | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-10 | MEDIUM | DB-Abfragen | `CMS/core/Services/SystemService.php:254` | `/admin/diagnose`: `COUNT(*)`, `CHECK TABLE` und zwei information_schema-Abfragen je Tabelle (31 Tabellen) bei jedem Aufruf | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-11 | MEDIUM | DB-Abfragen | `CMS/admin/modules/posts/PostsModule.php:672` | Kategorienliste: korrelierte Unterabfrage mit OR über einen Join je Kategorie, ohne Index nutzbar | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-12 | MEDIUM | DB-Abfragen | `CMS/core/Services/MediaUsageService.php:179` | Beitragsbild-Übersichten laden `content`/`content_en` (LONGTEXT) aller Beiträge und Seiten, nur um `featured_image` zu lesen | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-13 | MEDIUM | Caching | `CMS/core/Router.php:886` | ETag/Last-Modified von Seiten hängen nur an `updated_at` der Seite: 304 trotz geänderter Menüs, Einstellungen oder Theme | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-14 | MEDIUM | Caching | `CMS/themes/cms-default/blog-single.php:346` | CSRF-Token des Kommentarformulars startet bei jedem anonymen Beitragsaufruf eine Session: Beiträge nie cachebar, Session-Dateien wachsen | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-15 | MEDIUM | Caching | `CMS/core/Services/MediaService.php:1599` | Ersetzte Bilder behalten die URL, `uploads/` wird aber ein Jahr `immutable` ausgeliefert | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-16 | MEDIUM | Blockierende Arbeit | `CMS/admin/admin-ui.php:59` | Lange Admin-Requests halten die Session-Sperre und blockieren andere Tabs und AJAX desselben Admins | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-17 | MEDIUM | Blockierende Arbeit | `CMS/core/Auth.php:1106` | Login wartet per `sleep()` bis zu 30 s vor der Passwortprüfung (Worker und Session blockiert) | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-18 | MEDIUM | Blockierende Arbeit | `CMS/orders.php:133` | Checkout und Registrierung senden SMTP synchron im Frontend-Request, ohne Timeout | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-19 | MEDIUM | Core Web Vitals | `CMS/themes/cms-default/header.php:69` | DOMPurify (29,8 KB) und CSP-Runtime (7,7 KB) blockieren als erste Skripte im `<head>` jeder Frontend-Seite das Rendern | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-20 | MEDIUM | Core Web Vitals | `CMS/assets/js/photoswipe-init.js:19` | PhotoSwipe (7 KB CSS + 68,6 KB ESM) lädt auf jedem Beitrag, auch ohne passenden Container | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-21 | MEDIUM | Core Web Vitals | `CMS/themes/cms-default/functions.php:1177` | WebP-Umwandlung mit GD während des HTML-Renderns, ohne Aktualitätsprüfung | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-22 | MEDIUM | Core Web Vitals | `CMS/core/Services/EditorJsRenderer.php:670` | EditorJS-Bilder ohne `width`/`height`: Layout-Verschiebungen (CLS) | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-23 | MEDIUM | Core Web Vitals | `CMS/themes/cms-default/partials/home-landing.php:443` | Vorschaubilder laden immer das Original: kein `srcset`/`sizes`, erzeugte Größen ungenutzt | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-24 | MEDIUM | Cron/Batch | `CMS/core/Services/CronRunnerService.php:345` | Web-Cron ohne Zeitbudget: drei Medien-Budgets à 45 s überschreiten `max_execution_time`; `hourly_last_run` wird erst am Ende gesetzt | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-25 | MEDIUM | Cron/Batch | `CMS/core/Services/CronRunnerService.php:76` | Eine globale Cron-Sperre für alles: lange stündliche/tägliche Aufgaben blockieren die minütliche Mail-Queue | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-26 | MEDIUM | Cron/Batch | `CMS/core/Services/MediaService.php:1876` | Thumbnail-/AVIF-Neuaufbau still bei 5 000 Originalen gekappt; Medienwerkzeuge durchlaufen `uploads/` je GET zweimal | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-27 | MEDIUM | Cron/Batch | `CMS/core/Services/SEO/SeoSitemapService.php:489` | EN-Sitemaps laden alle `content_en`-Texte und fragen `seo_meta` je Eintrag ab, nach jedem Speichern im Frontend-Request | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-28 | LOW | DB-Abfragen | `CMS/core/Services/Content/ContentWorkflow.php:574` | Admin-Badges je Request: N+1 je wartendem Inhalt und ungecachte information_schema-Prüfungen | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-29 | LOW | DB-Abfragen | `CMS/core/Services/FeatureUsageService.php:334` | Feature-Telemetrie führt bei jedem Admin-/Mitglieder-Request `CREATE TABLE IF NOT EXISTS` aus | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-30 | LOW | DB-Abfragen | `CMS/core/Services/SettingsService.php:116` | `SettingsService::getGroup()` ohne Request-Cache: wiederholte `cms_settings LIKE ?`-Abfragen | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-31 | LOW | DB-Abfragen | `CMS/core/Routing/ThemeArchiveRepository.php:158` | Tag-Wolke und Kategoriezählung durchlaufen bei jedem Blog-/Archivaufruf alle veröffentlichten Beiträge | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-32 | LOW | Caching | `CMS/core/Services/SEO/SeoSitemapService.php:60` | Sitemap-Bundle ohne Sperre und nicht atomar: Crawler-Ansturm, leere Teil-Sitemaps für eine Stunde gespeichert | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-33 | LOW | Caching | `CMS/core/Services/FeedService.php:563` | Feed-304 hinter der Apache-Kompression wirkungslos (If-Modified-Since wird bei vorhandenem If-None-Match übersprungen) | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-34 | LOW | Caching | `CMS/core/Bootstrap.php:888` | Eigene Frontend-Assets ohne Version, `.htaccess` markiert CSS/JS ein Jahr `immutable` | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-35 | LOW | Core Web Vitals | `CMS/core/Bootstrap.php:888` | Unversionierte Asset-URLs mit einem Jahr `immutable`: Updates erreichen wiederkehrende Besucher nicht | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-36 | LOW | Core Web Vitals | `CMS/core/Bootstrap.php:778` | `editorjs-content.css` (44,9 KB) plus 8,8 KB Inline-Kopie derselben Regeln auf jeder Frontend-Seite | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-37 | LOW | Core Web Vitals | `CMS/themes/cms-default/header.php:61` | `fetchpriority=high` auf dem Logo (zweimal) und bis zu drei Bildern je Beitrag verwässert den LCP-Hinweis | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-38 | LOW | Core Web Vitals | `DOC/INSTALLATION.md:357` | Nginx-Anleitung ohne Kompression und Cache-Regeln für statische Assets | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-39 | LOW | Blockierende Arbeit | `CMS/core/Http/Client.php:487` | DNS-Vorabauflösung (SSRF-Schutz) nicht durch die curl-Timeouts begrenzt, doppelt je Update-Request, nicht gemerkt | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-40 | LOW | Blockierende Arbeit | `CMS/core/Services/DashboardService.php:328` | Dashboard und Mediathek durchlaufen `uploads/` rekursiv ohne Cache; `getimagesize()` je Bild in den Monitor-Warnungen | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-41 | LOW | Blockierende Arbeit | `CMS/admin/modules/system/SystemInfoModule.php:1958` | Cron-Status und Scheduled Tasks lesen bei jedem Aufruf alle PHP-Dateien der Installation | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-42 | LOW | Blockierende Arbeit | `CMS/core/Services/IndexingService.php:745` | IndexNow blockiert die Speicherantwort auf SAPIs ohne `fastcgi_finish_request` (20 s je Block) | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-43 | LOW | Cron/Batch | `CMS/core/Services/DataRetentionService.php:57` | Aufbewahrung löscht höchstens 200 000 Zeilen je Tabelle und Tag; große Sites holen nie auf | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-44 | LOW | Cron/Batch | `CMS/core/PageManager.php:176` | `page_revisions` ohne Obergrenze je Seite (Beiträge: 30) | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-45 | LOW | Cron/Batch | `CMS/core/Services/FeatureUsageService.php:312` | Wachsende Tabellen ohne Löschfrist (`feature_usage`); DDL bei jeder Instanziierung | [x] Behoben in PR #5 ([#166](https://github.com/PS-PhinIT/365CMS.DE/pull/166)) |
| PERF-410-46 | LOW | Blockierende Arbeit | `CMS/admin/modules/feeds/FeedAggregatorModule.php:247` | Feed-Aggregator: Admin-Aktionen holen bis zu 25 Feeds nacheinander im POST-Request | Akzeptiert (Entscheidung 15.11.2026: höchstens 25 Feeds je Admin-Aktion sind vertretbar) |
| PERF-410-47 | LOW | Blockierende Arbeit | `CMS/plugins/cms-importer/includes/class-importer.php:1138` | Importer lädt jedes entfernte Bild nacheinander im Import-POST (20 s Timeout je Bild, ohne Zeitbudget) | [x] Behoben in 4.1.6 ([#167](https://github.com/PS-PhinIT/365CMS.DE/pull/167)): 10 s je Bild, Zeitbudget 120 s je Lauf (Filter `cms_importer_image_budget_seconds`), gestörte Hosts übersprungen; übrige Bilder bleiben extern verlinkt und werden gemeldet |
| PERF-410-48 | LOW | Blockierende Arbeit | `CMS/core/Services/MediaService.php:1129` | Bild-Upload erzeugt Thumbnails, WebP und AVIF aller Größen synchron im Upload-Request | [x] Behoben in 4.1.6 ([#167](https://github.com/PS-PhinIT/365CMS.DE/pull/167)): AVIF-Kodierung nach der Antwort (FPM/LiteSpeed), Thumbnails und WebP weiter sofort |
| PERF-410-49 | MEDIUM | Cron/Batch | `plugins/cms-importer/includes/class-admin.php:794` | WXR-Import komplett in einem Web-Request (ganzes DOM, Bilddownloads, ohne Zeitbudget und Fortsetzung) | [ ] Offen, bewusst nicht Teil dieses Abschlusses (Entscheidung 15.11.2026: WXR-Import getrennt betrachten); bekannte Einschränkung, siehe „Abschlussbilanz“ |

### Messung vorher/nachher

Gleiche Testkopie und gleiche Daten, zweiter (warmer) Lauf des Crawls:

| Route | vorher | nachher |
|-------|--------|---------|
| `/admin/monitor-warnings` | 8 158 ms, 64 Abfragen | 100 ms, 57 Abfragen |
| `/admin/monitor-health-check` | 5 080 ms | 78 ms |
| `/admin/monitor-response-time` | 5 073 ms | 73 ms |
| `/admin/updates` | 1 149 ms | 100 ms |
| `/admin/posts` | 98 Abfragen | 36 Abfragen |
| `/admin/post-categories`, `/admin/post-tags` | je 82 Abfragen | je 20 Abfragen |
| `/admin/member-dashboard-profile-fields` | 90 Abfragen | 38 Abfragen |
| `/admin/pages` | 58 Abfragen | 35 Abfragen |
| `/admin/diagnose` | 50 Abfragen | 19 Abfragen |
| `/admin/menu-editor` | 55 Abfragen, davon 26 Schreibzugriffe | 36 Abfragen, keine Schreibzugriffe |
| `/` (anonym, warm) | 17 Abfragen | 15 Abfragen |
| `/blog/<beitrag>` (anonym) | Session-Cookie, `private, no-store` | kein Cookie, `public, max-age=300` |

Die Antwortzeiten des Frontends liegen unverändert bei 70–110 ms. Die Streuung kommt vom Entwicklungsserver. Status-Codes aller 166 Routen sind unverändert, ohne PHP-Meldungen im Inhalt oder im Fehlerlog. Ein Kommentar über das echte Formular als anonymer Besucher kommt ohne Session an (Status `pending`).

### Details

- **PERF-410-01 (PERF-410-01):** Antwortzeit und Health-Endpunkt kommen aus dem Cache (10 min). Fehlt der Cache, gilt der stündliche Cron-Snapshot (höchstens 2 h alt). Erst danach wird einmal live gemessen und gecacht. „Jetzt messen“ erzwingt eine frische Messung, der Verbindungs-Timeout sinkt auf 2 s.
- **PERF-410-02:** `CacheManager::clear()`/`clearAll()` löschen nur eigene Einträge (`<sha512>.cache`) und APCu-Schlüssel mit Präfix `365cms:`. Schema- und Plattform-Flags sowie die Update-Sperre bleiben. Dasselbe gilt jetzt für „Datei-Cache leeren“ unter *Performance*.
- **PERF-410-13:** ETag und Last-Modified öffentlicher Seiten enthalten die CMS-Version und eine seitenweite Inhaltsversion (`site_content_version`). Sie steigt nach schreibenden Admin-Anfragen (Einstellungen, Menüs, Theme), beim Speichern oder Löschen von Inhalten und bei geplanten Veröffentlichungen. Liegt `If-None-Match` vor, entscheidet nur das ETag.
- **PERF-410-14:** Anonyme Besucher erhalten im Kommentarformular ein zustandsloses, signiertes Token (HMAC über Beitrags-ID und Ausgabezeit, mit Ablauf). Für angemeldete Konten gilt weiter das Session-CSRF-Token. Honeypot und Zeitprüfung bleiben.
- **PERF-410-17:** Die Anmeldeverzögerung je Konto arbeitet ohne `sleep()`. Der erste Versuch startet die Wartezeit als Freigabe in der eigenen Session und antwortet sofort mit der Wartezeit. Danach ist genau eine Passwortprüfung frei. Dritte können die Freigabe nicht verschieben, Konten bleiben nicht aussperrbar.
- **PERF-410-04/PERF-410-45:** neue Aufbewahrungsregeln für `mail_queue` (nur erledigte Jobs, 30 Tage), `mail_log` (90 Tage) und `feature_usage` (180 Tage), blockweise gelöscht. Sie erscheinen unter *Datenaufbewahrung*.
- **PERF-410-06:** Das Backup streamt jede Tabelle ungepuffert. Das Dump-Format ist unverändert, `s3-backup` und `update-swap` sind grün.
- **PERF-410-25:** getrennte Sperren `.cron-run.lock` (Hooks, stündlich, täglich, Feeds) und `.cron-mail.lock` (Mail-Queue).

### Entscheidungen vom 15.11.2026 (umgesetzt in 4.1.6)

- **PERF-410-46** (Feed-Abruf im Admin): akzeptiert.
- **PERF-410-47** (Importer-Bilder) und **PERF-410-48** (Upload): umgesetzt wie in der Tabelle.
- **PERF-410-49** (WXR-Import in einem Request): wird getrennt betrachtet und bleibt offen.
- Aus Prüfbereich 3 sind FUN-410-17, -18, -19 und SEC-410-26 umgesetzt. Nachweise: `TESTS/release-4.1.0/final-410.php` und die Laufzeitprüfung mit einem Redakteur-Konto (Zahlungen und Webhooks mit Recht 200, ohne Recht Umleitung; Einstellungen weiter gesperrt).

### Ursprünglich offen (Entscheidung Prüfbereich 6)

PERF-410-46, PERF-410-47, PERF-410-48 und PERF-410-49 verlangen eine asynchrone Verarbeitung: Feed-Abruf im Admin, Bilddownloads und WXR-Import des Importers, Bildverarbeitung beim Upload. Dafür braucht es eine Job-Warteschlange mit Fortsetzung, also eine Architekturentscheidung statt eines Fixes. Bis dahin bleiben die Abläufe wie in 4.0.

### Folgearbeiten für den abschließenden Teil von PR #5

Die Umsetzungs-Cluster haben Punkte außerhalb ihrer Dateien gemeldet. Sie sind nicht Teil dieses Zwischenstands:

- **Theme:** `meridian_load_categories()` auf die gecachte Kategorieübersicht umstellen.
- **Bootstrap:** den Rückfall auf `cms_csp_runtime_tags(true)` setzen und `photoswipe.css` nur bei Bedarf einbinden.
- **Updates und Marketplace:** `refreshUpdateSnapshot()` erzwingt die Prüfung ausdrücklich. GET-Pfade laufen über `SessionLock::withoutLock()`, Fehlschläge des Katalogs werden kurz gecacht.
- **Monitoring:** `MonitoringTrendService::measureResponseTime()` überspringt den Loopback, wenn er nicht verfügbar ist.
- **Medien:**
  - Den Cache der Medienstatistik bei Upload und Löschen verwerfen.
  - Fortsetzungsbasierter Neuaufbau (Cursor statt Pfadliste).
  - Medien-Budgets im Cron am Restbudget ausrichten.
- **Sonstiges:**
  - Retry der Offsite-Uploads je Tageslauf begrenzen.
  - `deliverDue()` nur einmal je Cron-Lauf.
  - DNS-Vorabauflösung in `FeedService` über `Http\Client` teilen.
  - Sprachschlüssel für die neuen Aufbewahrungsregeln.
- **Optional (nicht empfohlen):** Index auf `users.status`. Nahezu alle Konten sind aktiv, die gebündelte Zählung entfernt die Scans ohnehin.
- **Produktentscheidung:** `generate_thumbnails` standardmäßig an, damit die neuen `srcset` greifen. Bis dahin gelten sie nur nach dem Neuaufbau der Bildgrößen.

### Verifikation Zwischenstand

- `php -l` (mit `error_reporting=-1`) über alle geänderten und neuen PHP-Dateien ohne Fehler.
- `php TESTS/run-all.php` mit MariaDB: damaliger Discovery-Lauf, 0 fehlgeschlagen. `release-4.1.0` hatte damals 552 Prüfungen, darunter neue Dateien `perf-*.php` und `final-410.php`.
- Laufzeitmessung vorher/nachher wie oben, Kommentar-Rundlauf als anonymer Besucher.

---

## PR #6: Audit-Abschluss und Abschlussbilanz (2026-11-17)

Abschluss-Pull-Request [#174](https://github.com/PS-PhinIT/365CMS.DE/pull/174); in der Planung der 4.2.0-Arbeiten „PR #0“ genannt. Er schließt den Prüflauf 4.1.0 ab, bevor die Entwicklung von 4.2.0 (Schema v27) beginnt. Es ist keine neue Prüfung, sondern Abarbeiten der offenen Punkte, Angleich von Protokoll und Changelog und die Bilanz.

### Vorgehen

1. Alle Protokolle (`RELEASE-AUDIT-4.1.0`, `FOLGEAUDIT-2026-10-14`, `-11-08`, `-11-09`, `-11-12`) und `DOC_PLAN/v4.1/PROGRESS.md` nach `[ ] Offen`, `OFFEN-xx` und „ausstehend“ durchsucht; jeden Treffer am Code geprüft.
2. Protokollstand gegen `main` abgeglichen. FUN-410-10, -33, -37 und -38 waren am 16.11.2026 bereits umgesetzt (PR [#170](https://github.com/PS-PhinIT/365CMS.DE/pull/170) bis [#173](https://github.com/PS-PhinIT/365CMS.DE/pull/173)); Protokoll und Changelog führten sie noch als offen.
3. Die zwei verbliebenen Funktionslücken umgesetzt (FUN-410-07, FUN-410-28), je mit Regressionstest und Laufzeitprüfung (MariaDB 10.11 bzw. Chromium).
4. Schema-Marke v26 und Rechte-Übernahme `capabilities_v26` geprüft; die dabei gefundene Abweichung zwischen Spezifikation und Umsetzung als DOC-410-03 korrigiert.
5. Abschlussbilanz erstellt.

### Details

#### FUN-410-07 (MEDIUM): „Vorlagen“ in Wissensdatenbank und Hub-Sites

- **Ursache:** Das Werkzeug „Vorlagen“ (`reusableBlock`) steht über die `EditorJsBlockRegistry` in jedem Editor.js-Editor. Den Nutzungsindex (`reusable_block_usage`) pflegt `ReusableBlockHooks` nur für Beiträge und Seiten. In der Wissensdatenbank und in Hub-Sites fehlten daher „Verwendet in“, Löschwarnung und Neuindizierung.
- **Behebung (Variante „ausblenden“):**
  - `editor-init.js` registriert das Werkzeug in Editoren innerhalb von `.editorjs-wrap--cms-knowledgebase` und `.hub-editorjs-holder` ohne `toolbox`: Es erscheint nicht in der Block-Auswahl.
  - `EditorJsAssetService` lässt die Schnellwahl „Vorlagen“ im Wissensdatenbank-Editor weg.
  - Das Werkzeug bleibt registriert. Bereits eingefügte Blöcke laden und gehen beim Speichern nicht verloren (ein Werkzeug ganz zu entfernen, würde sie verwerfen).
- **Prüfung im Browser** (Chromium, Prüfstand mit den ausgelieferten Editor-Bundles und `editor-init.js`; je Editor ein Dokument mit einem Block `reusableBlock` und `id` 7):

  | Editor | Einträge in der Block-Auswahl | „Vorlagen“ | Block nach dem Speichern |
  |--------|-------------------------------|-----------|--------------------------|
  | Beitrag/Seite (`default`) | 21 | ja | `reusableBlock:7` erhalten |
  | Block-Vorlage (`cms-reusable-block`) | 21 | ja (verschachtelte Vorlagen bleiben möglich) | erhalten |
  | Wissensdatenbank (`cms-knowledgebase`) | 20 | nein | erhalten |
  | Hub-Site (`hub-editorjs-holder`) | 20 | nein | erhalten |

  Gegenprobe mit dem alten `editor-init.js`: alle vier Editoren bieten „Vorlagen“ an.
- **Grenzen:** Einfügen über die Block-Zwischenablage oder einen Import bleibt möglich; dort fehlt der Index weiter. Die Alternative, den Index um `kb` und `hub_site` zu erweitern, wurde nicht umgesetzt. Nicht im Browser geprüft: der echte Seiten-Rundlauf (Anmeldung, Formular); der Prüfstand lädt dieselben Skripte ohne Server.
- **Tests:** `TESTS/release-4.1.0/closure-410.php`.

#### FUN-410-28 (MEDIUM): Kulanzzeit und Anzeige der Verlängerung

- **Ursache:** `getUserSubscription()` sperrte ein Abo sofort mit `end_date`; kam die Verlängerung des Anbieters Minuten oder Stunden später (Webhook-Verzug, Wiederholung der Abbuchung), verlor das Mitglied kurz den Zugang. Die Einstellung „Kulanzzeit nach Ablauf“ (`grace_period_days`, Standard 3) wirkte nirgends, der Hinweis nannte sie trotzdem. Zusätzlich zeigte das Mitglied „Nächste Verlängerung“ auch für Abos per Rechnung und manuell zugewiesene Abos, obwohl dort nichts verlängert wird, und `current_period_end` wurde nie angezeigt.
- **Behebung:**
  - `getUserSubscription()`: Abos mit wiederkehrender Zahlung beim Anbieter (`provider_subscription_id` gesetzt), nicht gekündigt (`cancelled_at` leer) und mit Status `active` behalten den Zugang bis zu `grace_period_days` Tage über `end_date` hinaus. Kulanzzeit 0 schaltet das ab. Gekündigte, erstattete, per Rechnung bezahlte und manuell zugewiesene Abos enden mit `end_date`.
  - `getSubscriptionRenewalNotice()`: Fälligkeit zuerst aus `current_period_end`, dann wie bisher. „Nächste Verlängerung“ nur bei Anbieter-Verlängerung (nicht gekündigt, nicht `lifetime`, Schalter „Automatische Verlängerung“ an). Der Satz zur Kulanzzeit erscheint nur dort, wo sie wirkt; bei Rechnungs-Abos entfällt die bisherige, wirkungslose Angabe.
- **Laufzeitprüfung** (MariaDB 10.11, echte Abfrage aus `getUserSubscription()`, 11 Fälle): laufendes Abo und `lifetime` → Zugang; Rechnungs-Abo 1 Tag abgelaufen → kein Zugang; Anbieter-Abo 1 Tag und 71 Stunden überfällig → Zugang; Anbieter-Abo 4 Tage überfällig → kein Zugang; gekündigt, ohne Abo-ID, Status `cancelled` oder `expired` → kein Zugang; Kulanzzeit 0 → kein Zugang. Gegenprobe ohne die Änderung: die zwei Kulanz-Fälle schlagen fehl.
- **Verhaltensänderung für Bestandsinstallationen:** Mit Stripe- oder Mollie-Abos gilt jetzt die eingestellte Kulanzzeit (Standard 3 Tage). Wer das nicht will, setzt sie unter *Abos → Pakete* auf 0.
- **Grenzen:** Die Admin-Übersichten (`getRenewalOverview()`, Benutzer- und Gruppenlisten) rechnen weiter mit `COALESCE(next_billing_date, end_date)` und kennen die Kulanzzeit nicht. Die Ereignisse beim Anbieter werden nicht verändert.
- **Tests:** `TESTS/release-4.1.0/closure-410.php` (Hinweis, Quelltext der Abfrage).

#### FUN-410-10, -33, -37, -38 (bereits in PR #170 bis #173 umgesetzt, hier nachgetragen)

- **FUN-410-10** ([#170](https://github.com/PS-PhinIT/365CMS.DE/pull/170)): Zurückziehen einer Einreichung löst `cms_content_withdrawn` aus und benachrichtigt die Prüfenden (In-App und Mail).
- **FUN-410-38** ([#171](https://github.com/PS-PhinIT/365CMS.DE/pull/171)): `cms_payment_failed` und Webhook `order.failed` tragen bei fehlgeschlagener Verlängerung die letzte Bestellung des Abos statt 0. Vorlage für Folgebestellungen: letzte bezahlte Bestellung beim Anbieter, sonst letzte bezahlte oder erstattete Bestellung des Pakets. Ohne Vorlage: Warnung im Log (Kanal `payments`) und Hook `cms_subscription_renewal_unbilled`.
- **FUN-410-37** ([#172](https://github.com/PS-PhinIT/365CMS.DE/pull/172)): Das Mollie-Abo entsteht erst nach dem Commit der Transaktion. Nach einem Rollback legt die Wiederholung des Ereignisses das Abo an, es gibt kein zweites. Schlägt der Aufruf beim Anbieter nach dem Commit fehl, steht der Fehler am Ereignis.
- **FUN-410-33** ([#173](https://github.com/PS-PhinIT/365CMS.DE/pull/173)): „Zurücksetzen“ im Importer löscht abhängige Daten mit (Verwendungsnachweis, Block-Vorlagen-Nutzung, Freigabeverlauf, Vorschau-Links, Kategoriezuordnung, Revisionen, Kommentare), verwirft Cache und gespeicherte Sitemaps und löst `cms_importer_content_reset` aus.
- **Aufräumen im Abschluss:** `PaymentService::createRenewalOrder()` trug nach FUN-410-38 zwei hintereinander stehende Doc-Blöcke; der verwaiste ist entfernt.

#### DOC-410-03 (LOW): `capabilities_v26` in Spezifikation und Changelog

- **Befund:** `SCHEMA-v26.md` (Punkt 5), `01-IDENTITY.md` und `02-MEDIA.md` sowie der Changelog 4.1.0 („Hinweise zum Update“) beschrieben `capabilities_v26` als einmaligen Schritt mit Marker in `settings`, der neue Rechte an Standardrollen vergibt (`security.sso`, `media.tools`). Umgesetzt ist etwas anderes: `ContentWorkflow::migrateCapabilities()` überträgt nur `posts.publish`/`pages.publish` (Redakteur, Autor), ohne Marker, idempotent über vorhandene Zeilen in `role_permissions`. Die Capability `media.tools` gibt es nicht (die Medien-Werkzeuge verlangen `manage_media`). `security.sso`, `backups.offsite`, `webhooks.manage`, `payments.manage` und `invoices.view` hat bewusst nur „admin“; `blocks.reusable` gehört zu den Standardrechten von „editor“.
- **Bewertung:** kein Code-Fehler. Fehlende Migrationen gibt es nicht, weil die übrigen Rechte nicht vergeben werden sollen (so auch in den Hinweisen des Update-Manifests). Die Dokumente waren falsch.
- **Behebung:** `SCHEMA-v26.md`, `01-IDENTITY.md`, `02-MEDIA.md`, Changelog 4.1.0 und der Kommentar in `MigrationManager::run()` beschreiben jetzt das tatsächliche Verhalten.
- **Tests:** `TESTS/release-4.1.0/closure-410.php` („Schema- und Rechte-Marker v26“).

### Prüfung der v26-Marker und der Rechte-Übernahme

| Prüfpunkt | Ergebnis |
|-----------|----------|
| `SchemaManager::SCHEMA_VERSION`, `MigrationManager::SCHEMA_VERSION` | beide `v26` |
| Neuinstallation | Marke `v26` nach `install.php`-Ablauf, alle `FEATURE_TABLES_V26`, Pflichtspalten und `posts.status` VARCHAR(20) vorhanden (`TESTS/schema-upgrade`, MariaDB 10.11.14) |
| Upgrade 4.0.0 (v25) → 4.1.x | gleiche Struktur wie Neuinstallation, Daten erhalten, wiederholte Läufe ohne Änderung; auch mit dem `sql_mode` von MySQL 8 |
| Reparatur bei gespeicherter Marke v26 | fehlende Spalten (`login_attempts.action`, `mail_queue.locked_at` …) werden ergänzt (DB-410-05) |
| `capabilities_v26` | läuft in `MigrationManager::run()` (Upgrade) und `SchemaManager::ensureFeatureSchema()` (Neuinstallation); Redakteur erhält `posts.publish`/`pages.publish`, Autor `posts.publish`; ein entzogenes Recht bleibt entzogen (DB-410-01) |
| Ausstehende v26-Marker | keine. Offene Punkte zu v26 gibt es nicht; Schema v27 (4.2.0) ist nicht Teil dieses Abschlusses |

**Grenze:** Der Upgrade-Pfad von 3.5.x (Schema v23) konnte nicht erneut laufen, weil der Git-Tag `V3.5.00` im Klon fehlt; die Suite meldet dann 20 statt 30 Prüfungen. Der Pfad wurde in PR #2 und PR #4 mit 30/30 geprüft; die Changelog-Einträge 4.1.5 bis 4.1.9 nennen keine Schemaänderung.

### Abschlussbilanz

Alle Befunde des Prüflaufs 4.1.0 (Stand 2026-11-17, gezählt aus den Befundtabellen dieses Protokolls):

| Kennung | gemeldet | behoben | verworfen | akzeptiert | dokumentiert | offen |
|---------|---------:|--------:|----------:|-----------:|-------------:|------:|
| `SEC-410` (Sicherheit) | 28 | 25 | 1 | 2 | 0 | 0 |
| `DB-410` (Schema, Migration) | 5 | 3 | 1 | 0 | 1 | 0 |
| `FUN-410` (Funktion) | 60 | 58 | 2 | 0 | 0 | 0 |
| `PERF-410` (Performance) | 49 | 47 | 0 | 1 | 0 | 1 |
| `DOC-410` (Doku, Release) | 3 | 3 | 0 | 0 | 0 | 0 |
| **Summe** | **145** | **136** | **4** | **3** | **1** | **1** |

- **Verworfen (4):** SEC-410-06, DB-410-01, FUN-410-34, FUN-410-39. Jeder Treffer ist am Code oder zur Laufzeit widerlegt.
- **Akzeptiert (3):** SEC-410-10 (Fail-open bei nicht lesbaren MFA-Einstellungen), SEC-410-11 (Forwarded-Proto ohne Trusted Proxy), PERF-410-46 (bis zu 25 Feeds je Admin-Aktion).
- **Dokumentiert (1):** DB-410-03 (gemischte Kollation nach Upgrade von 3.5.x).
- **Offen (1):** PERF-410-49 (WXR-Import).

Nach Schweregrad: 0 CRITICAL, 13 HIGH (alle behoben: SEC-410-01, DB-410-02, DOC-410-02, FUN-410-14, -20, -21, -27 und PERF-410-01 bis -06), 58 MEDIUM, 71 LOW und 3 ohne Einstufung (verworfen). Das einzige offene Element, PERF-410-49, ist MEDIUM.

### Offene Punkte und bekannte Einschränkungen

- **PERF-410-49 (MEDIUM): WXR-Import in einem Request.** Bewusst nicht Teil des Abschlusses (Entscheidung 15.11.2026: getrennt betrachten). Der Import des Plugins `cms-importer` liest die ganze WXR-Datei als DOM, lädt Bilder nacheinander und läuft ohne Fortsetzung. Bilder haben seit 4.1.6 ein Zeitbudget von 120 s. **Einschränkung für Betreiber:** sehr große Exporte vor dem Import in mehrere WXR-Dateien teilen oder `max_execution_time` erhöhen. Lösung wäre ein Import als Auftrag mit Fortsetzung (`BatchJobService`) und braucht eine eigene Planung.
- **Aus älteren Audits, weiter offen auf Wunsch des Maintainers:** `OFFEN-27` (Signatur der Core-Updatepakete, nur SHA-256 aus derselben Quelle; `FOLGEAUDIT-2026-11-09.md`).
- **FUN-86 (`FOLGEAUDIT-2026-11-12.md`):** teilweise gelöst durch SEC-410-26. Vier Seiten (Zahlungen, Rechnungen, Block-Vorlagen, Webhooks) lassen sich über ihre Capability an Nicht-Admins vergeben. Alle übrigen Admin-Seiten verlangen weiter die Rolle „admin“; das öffnet nur eine Grundsatzentscheidung zum Adminbereich.
- **`FOLGEAUDIT-2026-11-12.md`, Bereiche 6 und 7:** als „offen“ geführt. Bereich 6 (Geschwindigkeit) ist im Prüflauf 4.1.0 (PR #5) aufgegangen. Bereich 7 (Best Practices: PSR-12, Beispielkonfiguration, Sicherheits-Header) wurde nicht separat geprüft; der Prüflauf 4.1.0 deckt Altlasten und Regressionen ab (PR #4), aber keinen PSR-12-Lauf.
- **Folgearbeiten aus PR #5 ohne Befund-ID** (Liste unter „Folgearbeiten für den abschließenden Teil von PR #5“). Stichprobe am Code am 2026-11-17:
  - umgesetzt: `photoswipe.css` nur bei Bedarf.
  - nicht umgesetzt: `meridian_load_categories()` fragt die Kategorien direkt ab; der Rückfall in `Bootstrap` ruft `cms_csp_runtime_tags()` ohne `true`; `MonitoringTrendService::measureResponseTime()` überspringt den Loopback nicht; `WebhookService::deliverDue()` läuft je Cron-Lauf zweimal (Mail-Queue- und stündlicher Hook); `generate_thumbnails` ist weiter standardmäßig aus (Produktentscheidung offen).
  - nicht einzeln nachgeprüft: Verwerfen der Medienstatistik bei Upload und Löschen, Cursor-Neuaufbau und Restbudget im Cron, Retry-Begrenzung der Offsite-Uploads je Tageslauf (begrenzt ist die Gesamtzahl der Versuche: `MAX_ATTEMPTS` 3), DNS-Vorabauflösung in `FeedService`, Sprachschlüssel der Aufbewahrungsregeln.
  - Bewertung: Optimierungen ohne Fehlverhalten; sie gehen in den Backlog und gelten nicht als offene Befunde.
- **Release-Paket 4.1.9 (damaliger Stand):** Der Quelltext mit den Korrekturen dieses Abschlusses lag auf `main`, ein Paket wurde nicht gebaut (`Version::CURRENT`, Manifeste und `RELEASE/` standen beim damaligen Prüfpaket auf 4.1.8; `TESTS/release-package` prüft den jeweils aktuellen Paketstand). Seit 2026-11-23 ist 4.2.0 der freigegebene Stand.

### Verifikation (PR #6)

- `php -l` über alle geänderten PHP-Dateien (`MigrationManager.php`, `SubscriptionManager.php`, `PaymentService.php`, `EditorJsAssetService.php`, `TESTS/release-4.1.0/closure-410.php`, `TESTS/release-4.1.0/run.php`): ohne Fehler. `node --check` für `editor-init.js`: ohne Fehler.
- `php TESTS/run-all.php`: damaliger Discovery-Lauf, 0 fehlgeschlagen. `release-4.1.0` hatte damals 593 Prüfungen (neu: `closure-410.php`).
- Mit MariaDB 10.11.14: `CMS_TEST_DB_HOST=localhost CMS_TEST_DB_USER=root php TESTS/schema-upgrade/run.php` 20 bestanden, auch mit `CMS_TEST_SQL_MODE` = MySQL-8-Standard (Pfad 3.5.x: siehe Grenze oben).
- Laufzeit: Kulanzzeit, 11 Fälle gegen die echte Abfrage; Editor im Browser, vier Editoren, jeweils mit Gegenprobe (siehe Details).
- Grenzen: PHP 8.3.6 (nicht 8.4/8.5), keine Läufe gegen Stripe, Mollie, Entra ID oder S3; MySQL 8 selbst nicht getestet.
