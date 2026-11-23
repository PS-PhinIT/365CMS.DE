# 365CMS Projektdokumentation | Admin: Community (Forum)

> **Stand:** 2026-11-23 | **Version:** 4.2.0 (Schema v27) | **Status:** Stable
> **Seiten:** `/admin/community` und `/admin/community-*` | **Capabilities:** sechs `community.*`-Rechte; `community.manage` für Einrichtung, `community.moderate` für Moderation | **Modul:** `community` (Kernmodul, standardmäßig aus)

## English (summary)

The Community module (core module `community`, public area `/forum`) is administered under *Community* and *Community-Verwaltung*. The pages delegate decisions to `CommunityAccess`, `WriteGate`, moderation/report/ban services and the content services. `CommunityModule` applies a fail-closed switch over `CoreModuleService`; unknown or broken gate state does not enable Community. Settings live in group `community`; defaults are not persisted automatically.

## Deutsch

### Überblick

Das Community-Modul ist ein Kernmodul (kein `cms-forum`-Plugin). Öffentlich liegt es unter `/forum` (englisch `/en/forum`). Im Admin-Menü erscheinen **Community** (Moderation) und **Community-Verwaltung** (Einrichtung). Ohne aktives Modul führt jede Admin-Seite zur Modulverwaltung (`/admin/modules`), und öffentliche Community-Routen antworten mit 404. `CommunityModule::isEnabled()` hält den bekannten Slug zentral und liefert bei Ausnahme `false`; `CoreModuleService` selbst lässt unbekannte Slugs aus Kompatibilität als aktiv gelten. Rechte und Pflichtgründe entscheiden `CommunityAccess` und die Schreibdienste, nicht die Ansicht.

### Seiten

| Seite | Zweck | Recht |
|---|---|---|
| `/admin/community` | Übersicht mit Kennzahlen und Einrichtungsliste | `community.manage` |
| `/admin/community-structure` | Kategorien und Foren anlegen, bearbeiten, ausblenden, löschen; Reihenfolge per Pfeil-Schaltflächen oder Ziehen | `community.manage` |
| `/admin/community-permissions` | Rechte-Matrix Forum × Gäste, Rollen, Benutzergruppen und Vertrauensstufen (je Recht „nicht festgelegt“, „Ja“, „Nein“); Forum-Moderatoren | `community.manage` |
| `/admin/community-settings` | alle Einstellungen der Gruppe `community` (siehe unten), Exportdateien der DSGVO-Auskunft | `community.manage` |
| `/admin/community-ranks` | Ränge | `community.manage` |
| `/admin/community-queue` | Freigaben: wartende Beiträge freigeben oder ablehnen (Grund bei Ablehnung Pflicht) | `community.moderate` |
| `/admin/community-reports` | Meldungen, gebündelt je Ziel (Beitrag, Thema, Mitglied), erledigen | `community.moderate` |
| `/admin/community-topics` | Themenliste je Forum mit Sammelaktionen | `community.moderate` |
| `/admin/community-sanctions` | Sperren setzen und aufheben | `community.moderate` |
| `/admin/community-log` | Moderationsprotokoll mit Filter | `community.moderate` |
| `/admin/trash` | Papierkorb (Foren-Themen wiederherstellen oder endgültig löschen), nur Administratoren | Administrator |

Die Rolle `moderator` hat keinen Zugang zu `/admin`. Sie moderiert im Frontend (Moderationsmenü je Beitrag) und im Mitgliederbereich `/member/plugin/community` (Reiter „Moderation“: Freigaben und Meldungen).

### Einrichten

1. **Modul aktivieren** (*System → Module*). Rechte und Rolle `moderator` kommen mit dem Update auf 4.2.0 (Schritt `capabilities_v27`). Die sechs Capabilities sind `community.view`, `participate`, `moderate`, `ban`, `manage` und `view_private_data`. Eigene Rollen sowie `subscriber`/`contributor`, die vor dem Update bestanden, erhalten die Leserechte nicht automatisch: in *Benutzer → Rollen* ergänzen (siehe [RBAC](../users-groups/RBAC.md)).
2. **Struktur:** erst Kategorien, dann Foren (Typ Diskussion, Q&A, Ankündigungen, Link). Unterforen entstehen über das übergeordnete Forum. Ein Forum mit Inhalten lässt sich ausblenden; Löschen von Kategorien und Foren fragt nach und räumt die Rechte-Zeilen mit auf.
3. **Rechte:** in der Rechte-Matrix je Forum festlegen, wer lesen, Themen anlegen und antworten darf. Ein leeres Feld erbt vom übergeordneten Forum, dann von den Standardrechten (`default_permissions`). **Interne Foren** erscheinen nie in Suche, Sitemap und für Gäste.
4. **Einstellungen** prüfen (Gäste dürfen lesen, E-Mail-Bestätigung verlangen, Captcha, Freigabepflicht für neue Mitglieder).

### Einstellungen (Gruppe `community`)

| Bereich | Schlüssel |
|---|---|
| Sichtbarkeit | `guests_can_read`, `require_verified_email`, `base_path` |
| Seitengrößen, Längen | `topics_per_page`, `posts_per_page`, `title_min`, `title_max`, `post_min`, `post_max` |
| Bearbeiten, Abstände | `edit_window_minutes`, `edit_window_minutes_trusted`, `rate_post_seconds`, `rate_topic_seconds`, `duplicate_window_seconds` |
| Freigabe, Spam | `new_member_approval_posts`, `max_pending_per_user`, `word_filter`, `use_core_antispam`, `spam_duplicate_hours`, `report_hide_threshold` |
| Captcha | `captcha_mode` (aus, erster Beitrag, niedrigste Vertrauensstufe, immer), `captcha_provider` (Proof-of-Work, Bild, Rechenaufgabe; nur lokal, keine Drittanbieter) |
| Vertrauensstufen, Rechte | `trust_limits`, `default_permissions` |
| Moderation | `moderation_reason_required`, `moderator_ban_max_days`, `moved_stub_days` |
| Benachrichtigungen | `notify_mail` |
| Reaktionen, Anhänge | `reaction_set`, `attachment_types`, `attachment_max_kb` |
| Datenschutz | `ip_hash_days`, `unread_horizon_days`, `erase_mode` |

Standardwerte werden nie ungefragt in die Datenbank geschrieben; gespeichert werden nur Abweichungen. Die Bearbeitungsseite begrenzt jeden Wert (Typ, Grenzen).

### Moderation

- **Freigaben:** Beiträge neuer Mitglieder (`new_member_approval_posts`), von Wortfilter-Regeln „prüfen“ oder von Spam-Signalen landen in der Warteschlange. Ablehnen verlangt einen Grund, wenn `moderation_reason_required` gilt. Das einheitliche Tor ist `WriteGate`: Wortfilter/Zählung vor `CommunityAccess`, `screen()` danach.
- **Meldungen:** ab `report_hide_threshold` offenen Meldungen wird ein Beitrag automatisch ausgeblendet. Erledigen schreibt ins Protokoll.
- **Sperren:** `write` (nur lesen, nicht schreiben) oder `full` (kein Zugang), befristet oder unbefristet. Wer nur `community.moderate` besitzt (einschließlich Systemrolle `moderator`), ist auf `moderator_ban_max_days` begrenzt; dauerhaft und länger verlangt `community.ban`.
- **Protokoll:** jede Moderationsaktion mit Zeit, Konto und Grund; Löschfrist siehe unten.
- **Systemrolle:** `moderator` arbeitet im Frontend und im Mitgliederbereich, nicht unter `/admin`. Sie ist gegen automatische Registrierung und als SSO-Standardrolle gesperrt, wird bei der automatischen SSO-E-Mail-Verknüpfung wie `admin`/`editor`/`author` ausgeschlossen und bleibt über eine ausdrückliche Entra-Mapping-Regel erreichbar.

### Spam und Captcha

Schutzschichten auf dem Schreibweg (`ContentGuard`, `WriteGate`): Wortfilter (`block`, `replace`, `review`), Host-Sperrliste, Core-Antispam, Duplikat- und Link-Prüfung sowie Rate-Limits. `CaptchaGate` gilt nur für neues Thema und Antwort und nutzt ausschließlich lokale Core-Verfahren: Proof-of-Work (`pow`), mit GD erzeugtes Bild (`image`) oder Rechenaufgabe (`math`). Bild und PoW bieten die Rechenaufgabe als Alternative; ohne GD fällt `image` auf `math` zurück. Ein Lesefehler im Profil verlangt das Captcha (fail-closed), der Filter `cms_community_captcha_required` darf nur verschärfen.

### Datenschutz und Löschfristen

- **Löschfristen** (`CommunityRetention`, über `DataRetentionService`; wirken auch bei abgeschaltetem Modul): IP-Kennung an Beiträgen (`ip_hash_days`, Standard 30 Tage), Lesestatus 180 Tage, verwaiste Uploads 24 Stunden, Entwürfe 30 Tage, erledigte Meldungen 365 Tage, Moderationsprotokoll 365 Tage, Verwarnungen 2 Jahre, frühere Beitragsfassungen 365 Tage (die jüngste bleibt).
- **Auskunft und Löschung:** Datenexport und Löschung laufen über die Hooks `dsgvo_export_data` und `dsgvo_delete_data`. Die Exportdateien erscheinen auf *Community → Einstellungen* und lassen sich dort herunterladen und löschen. Bei der Löschung gilt `erase_mode`: `anonymize` (Beiträge bleiben, Anzeige „Gelöschtes Mitglied“) oder `delete` (Inhalt wird gelöscht).
- **Datenschutzerklärung:** der generierte Text (*Rechtliche Seiten*) enthält bei aktivem Modul den Abschnitt „8a. Forum (Community)“ mit den wirksamen Einstellungen und Fristen. Er ist eine Vorlage und muss vom Betreiber geprüft werden.
- **Papierkorb:** `CommunityContentProvider` meldet den Typ `community_topic` mit Capability `community.manage` im zentralen Papierkorb an. Nur Administratoren bedienen dort den Papierkorb (`manage_trash`). Ein Thema liegt dort bei `status = deleted`; `trash` wird als Themenstatus nicht benutzt. Wiederherstellen setzt den vorherigen Status wieder und bucht veröffentlichte Inhalte nach S1 zurück. Abgelehnte Themen sind nicht wiederherstellbar. Endgültiges Löschen räumt Beiträge, Revisionen, Reaktionen, Anhänge, Meldungen, Abos, Lesemarken, Umfragen, Queue und Verweise auf; offene Meldungen verlangen manuelle Bestätigung.
- **S1-Zähler:** Ein Beitrag zählt in Forum und Mitgliederstatistik genau dann, wenn Beitrag **und** Thema veröffentlicht sind (`CounterBook`). `reply_count` und Themenaktivität berücksichtigen dagegen veröffentlichte Beiträge unabhängig vom heutigen Themenstatus; Statuswechsel buchen alle betroffenen Zähler unter Sperren.

### Leistung, Editor und Auslieferung

- Lesecontroller öffnen `ReadScope`: dieselbe Forum-/Themenzeile wird in der Anfrage nur einmal geladen, Schreibanweisungen entwerten betroffene Memo-Werte. Große Listen blättern mit dem Cursor `?nach=…` als Keyset; die Community-SQL-Pfade verwenden kein `OFFSET`.
- Fachliche Zeitwerte sind UTC (`DATETIME Y-m-d H:i:s`); die Anzeige rechnet über `I18n` in die Website-Zeitzone um.
- Der SunEditor ist nur eine Browserhilfe; gespeichert wird Markdown. `md-bridge.js` erhält die Markdown-Quelle, entfernt nicht unterstütztes Editor-Markup und überlässt eine nicht verlustfreie Quelle der Markdown-Ansicht. `DraftService` hält pro Mitglied und Ziel (`forum` oder `topic`) einen Server-Entwurf.
- Anhänge liegen im privaten Speicher, werden nur aus erlaubten Typen erzeugt und bei Download/Vorschau erneut über `AttachmentService`/`CommunityAccess` freigegeben. Die Browser-Interaktionen laufen über `POST /forum/api/*` mit dauerhaftem CSRF-Token und JSON-Antwort.
- Öffentliche, veröffentlichte erste Themenseiten erhalten JSON-LD: `DiscussionForumPosting` bzw. in Q&A-Foren `QAPage`/`Question` und ggf. die akzeptierte `Answer`. Private Foren, Listen, Suche und Profile werden nicht so indiziert.

### Prüfen und Fehlersuche

| Beobachtung | Ursache und Lösung |
|---|---|
| `/forum` zeigt „nicht gefunden“ | Modul aus, oder kein Forum für Gäste lesbar (Rechte-Matrix, `guests_can_read`) |
| Mitglied sieht Forum nicht | fehlendes `community.view`; bei eigenen Rollen nach dem Update ergänzen |
| Beiträge erscheinen nicht | in der Warteschlange (`/admin/community-queue`) |
| Seite langsam | Abfragebudget je Seite wird in der Suite `community-performance` geprüft; Indizes aus Schema v27 vorhanden? (`DatabaseUpdateRunner`) |

Tests: `php TESTS/run-all.php` (Suiten `community-*`); Hooks siehe [HOOKS-REFERENCE](../../core/HOOKS-REFERENCE.md), Dienste siehe [SERVICES](../../core/SERVICES.md).
