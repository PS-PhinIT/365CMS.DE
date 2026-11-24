# AP-17 – Datenschutz und Compliance (Version 4.2.17)

**Stand 24.11.2026.** Geprüft wurde `main` nach `12701b8` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung, Skriptsuchen und Funktionsaufrufe
ohne Datenbank und ohne Browser. Maßstab: [Audit-Katalog, AP-17](../AUDIT-KATALOG-4.2.0.md#ap-17-datenschutz-und-compliance). Der Bericht betrifft nur AP-17; er ist keine Freigabe des
gesamten Bereichs. Es wurden keine Browser, Konten oder Produktivsysteme verwendet. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Zwei Befunde (LOW bis MEDIUM, umgesetzt): Der 404-Monitor speicherte volle IP-Adressen, und beim endgültigen Löschen eines Kontos blieben Daten in Tabellen ohne Fremdschlüssel stehen. Offen: Consent, Rechtstexte, Kommentare und Protokolle.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 17.1 Export, Löschung, Fristen | ja, mit Befund | Auskunft und Löschung über `dsgvo_export_data` und `dsgvo_delete_data` für Community, Formulare, Newsletter und Rechnungen (`CMS/core/Services/FeatureHooks/`), Feeds löschen, `MemberService::exportUserData()` exportiert Konto, Meta, Bestellungen und Aktivität. Fristen im `DataRetentionService`. Befund TMP-AP17-2. | Kommentare, Favoriten, Benachrichtigungen, Abos, KI-Nutzung fehlen im Export; Entscheidung zu Kommentaren (INFO-1). |
| 17.2 Consent | offen | Durch die Suite `analytics-consent` abgedeckt, nicht neu geprüft. | Keine Dritt-Anfragen vor der Zustimmung im Browser. |
| 17.3 Logs, IP | ja, mit Befund | Seitenaufrufe, Kommentare und Web-Vitals kürzen (`TrackingService::anonymizeIp()`, `CommentService::anonymizeIpAddress()`); Befund TMP-AP17-1 für den 404-Monitor. Sitzungsregister, Anmeldeversuche, Sicherheits- und Audit-Protokoll speichern die Adresse (Zweck: Sicherheit). | Aufbewahrung der Tabellen `login_attempts`, `security_log`, `sessions` (nur Teile haben Fristen). |
| 17.4 Mails | ja (Teil) | Newsletter: Double-Opt-in, Bestätigen und Abmelden per Knopf (`POST`), einmalige-Klick-Abmeldung nach RFC 8058 (`NewsletterFrontendController.php`). | Keine Fremddaten in Mails: nicht geprüft. |
| 17.5 Rechtstexte | offen | Nicht geprüft. | Generator folgt den Einstellungen (Baustein 8a Community). |
| 17.6 Impressum, Datenschutz, Erklärung | offen | Nicht geprüft. | Erreichbarkeit und Aktualität. |

## Abweichungen

### TMP-AP17-1 · 404-Monitor speichert die volle IP-Adresse (LOW bis MEDIUM, umgesetzt)

- **Fundstelle:** `CMS/core/Services/RedirectService.php` (`logNotFound()`, `writeNotFoundLog()`), Aufruf `CMS/core/Router.php:947`.
- **Ablauf:** Jeder 404-Aufruf legt Pfad, Host, Referrer, User-Agent und die volle Client-IP in `not_found_logs` ab, bei jedem weiteren Treffer wird die Adresse mit der letzten überschrieben. Aufbewahrung 90 Tage. Gelesen wird die Adresse nirgends: Die Oberfläche und der CSV-Export wählen nur Pfad, Host, Referrer und Zähler (`RedirectService.php:601`, Verwaltungsliste ohne Anzeige der Spalte).
- **Auswirkung:** Personenbezogene Daten ohne erkennbaren Zweck; widerspricht dem Vorgehen des Projekts, Adressen bei Statistik und Kommentaren zu kürzen.
- **Korrektur:** Die Adresse wird vor dem Speichern mit `TrackingService::anonymizeIp()` gekürzt (IPv4 letztes Oktett 0, IPv6 erste 48 Bit). Bestehende Zeilen behalten die volle Adresse, bis die 90-Tage-Frist sie entfernt.
- **Regression:** `TESTS/security/ip-minimization.php` (Quelltextvertrag auf `logNotFound()` und Funktionsaufruf der Kürzung); die Mutante ohne Kürzung lässt zwei Prüfungen fehlschlagen. **Status:** umgesetzt. **Konfidenz:** hoch.

### TMP-AP17-2 · Endgültiges Löschen lässt Personenbezug zurück (MEDIUM, umgesetzt)

- **Fundstelle:** `CMS/core/Services/UserService.php` (`deleteUser()`, Zweig `$hard_delete`).
- **Ablauf:** `UserService::deleteUser($id, true)` löscht die Zeile in `users`. Mit Fremdschlüssel `ON DELETE CASCADE` auf `users` sind nur `user_meta`, `favorites` und `notifications` versehen (`SchemaManager.php`). Weitere 35 Tabellen tragen `user_id` ohne Regel; für Community, Formulare, Newsletter, Rechnungen und Feeds gibt es eigene Hooks, aber für `sessions` (IP, User-Agent), `passkey_credentials` (Schlüssel, Gerätename), `user_identities` (SSO-Kennung, E-Mail), `password_history` (Passwort-Hashes), `user_subscriptions`, `subscription_usage`, `user_group_members` und `ai_quota_usage` löscht nirgends Code (Skriptsuche nach `DELETE FROM` und `->delete(` je Tabelle). Auch die Löschanträge der Betroffenen (`DeletionRequestsModule.php:199`) laufen über diesen Weg.
- **Auswirkung:** Nach der „Löschung“ bleiben Zugangs- und Geräte-Daten der Person in der Datenbank (Art. 17 DSGVO); alte SSO-Verknüpfungen können eine spätere Neuanlage derselben Person stören (eindeutiger Schlüssel auf `provider, tenant_id, subject`).
- **Korrektur:** Neue Klasse `UserDataEraser` löscht diese acht Tabellen nach dem Löschen des Kontos und vor dem Hook `cms_user_deleted`; fehlende Tabellen und Sperren durch Fremdschlüssel brechen nicht ab. Bestellungen und Rechnungen (Aufbewahrungspflicht), Protokolle, Beiträge, Seiten und Kommentare bleiben bewusst.
- **Regression:** `TESTS/security/user-erasure.php`: SQLite im Speicher mit den Tabellen, Löschung für ein Konto, Zeilen anderer Konten und Bestellungen bleiben, fehlende Tabelle, ungültiges Präfix und Konto-ID 0; Quelltextvertrag auf den Aufruf im Zweig „endgültig löschen“. Die Mutante ohne Aufruf lässt eine Prüfung fehlschlagen. **Status:** umgesetzt. **Konfidenz:** mittel bis hoch (Wirkung gegen SQLite geprüft, nicht gegen MariaDB).

## Hinweise ohne Änderung

- **INFO-1:** Kommentare angemeldeter Personen behalten nach dem endgültigen Löschen Name und E-Mail im Kommentar; die IP wird nach der Frist entfernt (`CommentService.php:549`). Ob Kommentare anonymisiert oder gelöscht werden sollen, ist eine Entscheidung des Betreibers.
- **INFO-2:** Die Tabellen `failed_logins` und `blocked_ips` kommen im Schema vor, werden aber nirgends beschrieben (`DashboardService.php:508` vermerkt „existiert noch nicht“); Rest einer früheren Fassung.

## Testlauf und Übergabe

PHP 8.3.6 CLI unter Linux, `pdo_sqlite`. Gesamtlauf aller Suiten grün; neue Prüfungen rot ohne, grün mit der Korrektur (Mutanten).

**Offen:** 17.2, 17.5, 17.6; Export für Kommentare, Favoriten, Benachrichtigungen, Abos; Löschfristen für Anmeldeversuche und Sicherheitsprotokoll; Prüfung von TMP-AP17-2 auf MariaDB mit Fremdschlüsseln.
