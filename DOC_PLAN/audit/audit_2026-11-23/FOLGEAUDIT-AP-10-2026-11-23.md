# AP-10 – Datenbank: Schema, Migration, Konsistenz, Wettläufe (Version 4.2.10)

**Stand 24.11.2026.** Geprüft wurde `main` nach `12701b8` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung, Skriptsuchen und Funktionsaufrufe; erstmals mit einer lokalen MariaDB 10.11 (`CMS_TEST_DB_HOST`, `CMS_TEST_DB_USER`, `CMS_TEST_DB_PASS`), gegen die alle Suiten mit Datenbankteil liefen. Maßstab: [Audit-Katalog, AP-10](../AUDIT-KATALOG-4.2.0.md#ap-10-datenbank-schema-migration-konsistenz-wettlaeufe). Der Bericht betrifft nur AP-10; er ist keine Freigabe des gesamten Bereichs. Es wurden keine Browser, Konten oder Produktivsysteme verwendet. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Zum ersten Mal gegen eine echte Datenbank (MariaDB 10.11 lokal): alle 66 Suiten grün, Schema geprüft. Ein Befund in der Testinfrastruktur (umgesetzt) und die Bestätigung von TMP-AP17-2 auf MariaDB. Mehrere Punkte bleiben offen.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 10.1 Schema | ja | Suite `schema-upgrade` gegen MariaDB: 643 bestanden (Neuinstallation und Update führen zum gleichen Schema, zweites Update ändert nichts). Frisch angelegtes Schema: 119 Tabellen, alle InnoDB, Tabellen und Spalten durchgängig `utf8mb4_unicode_ci`, 22 Fremdschlüssel. | Strict-Mode-Lauf, MySQL 8. |
| 10.2 Transaktionen | offen | Die Community- und Rechnungs-Suiten (Sperrfolge, `FOR UPDATE` in Transaktion, Savepoint) laufen gegen MariaDB grün (AP-08). | Deadlock-Wiederholung, zwei parallele Verbindungen. |
| 10.3 Zähler | offen | Nicht geprüft; die Suiten `community-db` und `community-repositories` laufen grün. | Zähler nie negativ, Abgleich. |
| 10.4 Eindeutigkeit | ja (Teil) | 16 von 19 Tabellen mit Spalte `slug` haben einen eindeutigen Index auf `slug` (bei `downloads_terms` zusammen mit `term_type`). Ohne eindeutigen Index: `post_revisions`, `page_revisions` (Verlauf) und `community_topics`. | Ob die Anwendung die Eindeutigkeit der Themen-Slugs anders sichert; Wettlauf-Test. |
| 10.5 Löschkaskaden | ja, mit Befund | Siehe Befund TMP-AP10-2 (TMP-AP17-2 auf MariaDB bestätigt). | Kommentare, Reaktionen verwaister Beiträge (INFO-2). |
| 10.6 Zeit | offen | Nicht geprüft. | UTC gegen Sitzungszeitzone, Sommerzeit, Keyset-Cursor. |
| 10.7 Wachstum | ja (Stichprobe) | Aufräumen vorhanden für Mailwarteschlange (30 Tage, `MailQueueService.php:42`), Batch-Jobs (`BatchJobService.php:335`), KI-Quoten (`AiQuotaService.php:174`), Community-Moderationsprotokoll (365 Tage, `CommunityRetention.php`), Seitenaufrufe, Web-Vitals, 404-Monitor, Aktivitäts- und Audit-Protokoll (`DataRetentionService`). Ohne Aufbewahrungsregel: `content_workflow_events` (Verlauf der Redaktionsschritte), `security_log` (nur Teile), `failed_logins` und `blocked_ips` (nie beschrieben, Rest einer früheren Fassung). | Frist für `content_workflow_events` und `security_log` festlegen. |
| 10.8 MariaDB/MySQL | offen (Teil bestätigt) | MariaDB 10.11 (Ubuntu-Paket): alle Suiten grün. | MySQL 8, Reconnect, Timeouts. |

## Abweichungen

### TMP-AP10-1 · `schema-upgrade` stürzt ohne die alten Release-Pakete ab (LOW, umgesetzt; nur Testcode)

- **Fundstelle:** `TESTS/schema-upgrade/run.php`.
- **Ablauf:** Die Suite sucht die Pakete `RELEASE/365CMS-4.0.0-full.zip` und `RELEASE/365CMS-4.1.8-full.zip`; im Repository liegt nur noch das aktuelle Paket. Sobald die Datenbank-Variablen gesetzt sind, griffen zwei Prüfungen auf die fehlende Quelle zu und brachen mit `TypeError: Argument #1 ($root) must be of type string, null given` ab, statt sich zu überspringen. Ohne Variablen fällt das nicht auf (die Suite überspringt sich ganz); die CI läuft offenbar so.
- **Korrektur:** Der Ausgangsstand 4.0.0 wird, wenn das Paket fehlt, aus dem Git-Tag `V4.0.0` gebaut; fehlt auch der Tag, überspringen sich die zwei Prüfungen mit einem Hinweis. Die Prüfung der Ausgangslage „Tabelle `notifications` mit Standardkollation des Servers“ überspringt sich, wenn die Quelle der Tag ist (der Tag legt die Tabelle schon mit `utf8mb4_unicode_ci` an, das ausgelieferte Paket nicht). Keine Prüfung wurde entfernt oder abgeschwächt; der Schritt „4.1.8 → 4.2.0“ bleibt ohne Quelle (kein Tag, kein Paket) ungeprüft.
- **Nachweis:** Mit Datenbank 643 bestanden, 0 fehlgeschlagen, ein Hinweis; zuvor `TypeError` und 1 Fehler in der Ausgangslage. **Status:** umgesetzt. **Konfidenz:** hoch.

### TMP-AP10-2 · Endgültiges Löschen eines Kontos: Restzeilen (MEDIUM, umgesetzt in AP-17, hier auf MariaDB bestätigt)

- **Messung:** Frisches Schema (`SchemaManager::createTables()`), je Tabelle mit `user_id` (38) eine Zeile für das Zielkonto und ein zweites Konto, danach `UserService::deleteUser($id, true)`. Ohne `UserDataEraser`: in allen acht Tabellen (`sessions`, `passkey_credentials`, `user_identities`, `password_history`, `subscription_usage`, `user_subscriptions`, `user_group_members`, `ai_quota_usage`) bleiben die Zeilen des gelöschten Kontos. Mit `UserDataEraser`: in keiner; Zeilen des zweiten Kontos bleiben unberührt. Mit den registrierten Hooks (Community, Rechnungen, Newsletter, Feeds, Formulare) verbleiben 13 Tabellen mit Zeilen des Kontos, durchweg gewollt oder Entscheidung des Betreibers: `audit_log`, `security_log`, `activity_log` (Nachweis), `orders`, `invoices` (Aufbewahrungspflicht, Sperrvermerk), `comments`, `page_views`, `core_web_vitals`, `theme_customizations`, `content_workflow_events`, `newsletter_subscribers`, `feeds_subscriptions`, `community_reactions` (INFO-2).
- **Index:** Die Lösch-Abfragen nutzen bei sieben der acht Tabellen einen Index auf `user_id`; `ai_quota_usage` hat keinen (Volltabellenscan bei jeder Konto-Löschung, selten und unkritisch). Tabellen mit `user_id` ohne Index am Anfang: `ai_quota_usage`, `content_workflow_events`, `security_log`.

## Hinweise ohne Änderung

- **INFO-1:** Die CI läuft offenbar ohne Datenbankvariablen; die Suiten mit MariaDB (u. a. alle 24 `community-*`, `schema-upgrade`, `invoices`, `webhooks`) überspringen sich dort. Ein MariaDB-Dienst in der CI würde sie ausführen (hier: 66 von 66 grün mit MariaDB 10.11).
- **INFO-2:** `CommunityPrivacy::erase()` löscht Reaktionen über die Verbindung zu den Beiträgen (`CommunityPrivacy.php:293–306`). Eine Reaktion, deren Beitrag nicht mehr existiert, bliebe stehen; ob Beitragslöschung die Reaktionen mitnimmt, ist nicht geprüft.

## Testlauf und Übergabe

PHP 8.3.6 CLI, MariaDB 10.11.14 (Ubuntu-Paket, lokal). `php TESTS/run-all.php` mit den Variablen: 66 von 66 grün.

**Offen:** 10.2, 10.3, 10.6 vollständig; MySQL 8; Parallel-Tests; Schritt 4.1.8 → 4.2.0 ohne Quelle.
