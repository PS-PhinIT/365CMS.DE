# 365CMS – Projektdokumentation | Abschnitt: Community – Rechte & Moderation
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.1 (Vorschlag) | **Status:** Planung | **Übersicht:** [README.md](README.md)

## English (summary)

Role-based access control for the Community module: global capabilities (`community.*`) in the core role editor,
per-forum permissions for guests, roles, user groups and trust levels with inheritance, forum moderators, the single
decision point `CommunityAccess::can()` and its evaluation order (account state → community ban → global capability →
forum moderator → forum permission → trust level limits → content state). It also specifies the moderation toolset
(lock, pin, announce, move, merge, split, hide, approve), the report system, warnings with points and automatic
consequences, bans, the approval queue, trust levels 0–4 and the moderation log.

## Deutsch

### 1. Rollen (Standard)

| Rolle | Herkunft | Kurzbeschreibung |
|---|---|---|
| **Gast** | nicht angemeldet | Liest öffentliche Foren, sucht mit Rate-Limit; schreibt nie |
| **Mitglied** | Core-Rolle `member` | Liest, erstellt Themen, antwortet, reagiert, meldet, abonniert – je nach Forum |
| **Moderator** | **neue** Core-Rolle `moderator` (der Core 4.0.0 kennt nur `admin`, `editor`, `author`, `member` in `UserService::DEFAULT_ROLE_CAPABILITIES`) oder Forum-Moderator ohne eigene Rolle | Moderiert global (Capability) oder nur zugewiesene Foren |
| **Administrator** | Core-Rolle `admin` | Alle Rechte inkl. Struktur, Rechte und Einstellungen |

Weitere Core-Rollen (`editor`, `author` …) erhalten standardmäßig die Mitgliederrechte; die Rechtematrix kann sie
abweichend behandeln. **Gruppen** (`user_groups`) sind das Mittel für interne Bereiche („Team“, „Kunden“, „Beta“).

### 2. Globale Capabilities (Core-Rollenverwaltung)

Neu in `RolesModule::CAPABILITY_GROUPS`, Gruppe „Community“ (Schreibweise wie `comments.moderate`):

| Capability | Wirkung | Standard |
|---|---|---|
| `community.view` | Community überhaupt sehen (für Gäste über Einstellung „Gäste dürfen lesen“) | member, editor, author, moderator |
| `community.participate` | Schreiben grundsätzlich erlaubt (zusätzlich zu den Forum-Rechten) | member, editor, author, moderator |
| `community.moderate` | Globale Moderation aller Foren, Warteschlange, Meldungen, Verwarnungen, befristete Sperren | moderator |
| `community.ban` | Dauerhafte Sperren und Aufheben fremder Sperren | admin |
| `community.manage` | Struktur, Rechte, Ränge/Abzeichen, Einstellungen, Statistik, Datenübernahme | admin |
| `community.view_private_data` | Gekürzte IP-Kennung, Bearbeitungsverlauf gelöschter Beiträge | admin |

`admin` hat wie im Core implizit alle Rechte. Ein Update-Schritt vergibt die Standards (wie bei `posts.publish`
in [ROADMAP-CONTENT-PLATFORM.md](../ROADMAP-CONTENT-PLATFORM.md) A.5), damit niemand ausgesperrt wird.

### 3. Rechte je Forum

Schlüssel in `community_forum_permissions.permissions_json`:

| Schlüssel | Bedeutung |
|---|---|
| `read` | Forum und Themen sehen (inkl. Suche, Feeds, Benachrichtigungen) |
| `create_topic` | Neues Thema |
| `reply` | Antworten (in offenen Themen) |
| `edit_own` | Eigene Beiträge im Zeitfenster bearbeiten |
| `delete_own` | Eigene Beiträge löschen (weich) |
| `upload` | Anhänge |
| `poll` | Umfragen erstellen |
| `vote` | In Umfragen abstimmen |
| `react` | Reaktionen |
| `mark_solution` | Lösung im eigenen Thema markieren (Forentyp Q&A) |
| `bypass_approval` | Ohne Freigabe veröffentlichen, auch wenn das Forum Freigabe verlangt |
| `bypass_slow_mode` | Langsamer Modus gilt nicht |

**Subjekte:** `guest`, `role:<slug>`, `group:<id>`, `trust:<0-4>`.
**Vererbung:** `inherit_permissions = 1` übernimmt die Einträge des Elternforums; eigene Einträge ergänzen bzw.
überschreiben je Subjekt. Ohne Eintrag gelten die globalen Standards (Einstellungen „Rechte-Standards“).
**Mehrere Subjekte** (Rolle + Gruppen + Stufe): ein Recht gilt, wenn **irgendein** Subjekt es erlaubt (Vereinigung) –
mit zwei Ausnahmen, die immer einschränken: Sperren (§6) und Vertrauensstufen-Grenzen (§8).
**Interner Bereich:** Forum ohne `read` für `guest` und `role:member`, mit `read` für `group:<Team>`.
Interne Foren erscheinen nirgends für Unberechtigte (Startseite, Suche, Profile, Feeds, Sitemap, „Neue Beiträge“,
Benachrichtigungen, Zitate in anderen Foren zeigen „[Zitat aus einem nicht sichtbaren Bereich]“).

### 4. Entscheidungsstelle `CommunityAccess::can(user, action, context)`

Reihenfolge der Prüfung (erste zutreffende Regel entscheidet „nein“, sonst weiter):

1. **Modul aktiv?** (`CoreModuleService`) – sonst 404.
2. **Konto:** gesperrt/gelöscht im Core → nur Gast-Rechte. Schreibaktionen verlangen bestätigte E-Mail (Einstellung).
3. **Community-Sperre:** `scope = full` → kein Zugriff; `scope = write` → nur `read`.
4. **Global:** `admin` bzw. `community.moderate` → erlaubt (außer bei Sperre durch höhere Rolle).
5. **Forum-Moderator** des Forums oder eines Elternforums mit `include_children` → Moderationsaktionen erlaubt.
6. **Forum-Recht** aus §3 (Vereinigung der Subjekte, mit Vererbung).
7. **Vertrauensstufe:** Grenzen aus §8 (Links, Bilder, Anhänge, Bearbeitungsfenster, Rate-Limits).
8. **Zustand des Inhalts:** Thema geschlossen/archiviert, Forum `readonly`, Bearbeitungsfenster abgelaufen, Langsamer Modus.
9. **Filter** `cms_community_can` (darf nur weiter einschränken).

Ergebnis ist ein Objekt mit `allowed`, `reason` (für die Oberfläche, z. B. „Thema ist geschlossen“) und
`retry_after` (Rate-Limit). Ergebnisse werden je Anfrage zwischengespeichert. Ein Gesamtcache je Benutzer ist erst
erlaubt, wenn der Core den Hook `user_role_changed` hat ([ARCHITECTURE.md](ARCHITECTURE.md) §6).

### 5. Moderationswerkzeuge

| Aktion | Wer | Wirkung | Protokoll |
|---|---|---|---|
| Schließen / Öffnen (optional bis Datum) | Moderation | keine Antworten außer Moderation | `topic.lock` / `topic.unlock` |
| Anpinnen (optional bis Datum) | Moderation | oben im Forum | `topic.pin` |
| Ankündigung | Moderation (global) | oben in allen Foren der Kategorie | `topic.announce` |
| Verschieben (+ Verweis) | Moderation in Quelle **und** Ziel | Zähler beider Foren, Verweis-Thema mit Ablauf | `topic.move` |
| Zusammenführen | Moderation in beiden Foren | Beiträge chronologisch ins Ziel, Quelle → `moved` + 301 | `topic.merge` |
| Teilen | Moderation | ausgewählte Beiträge → neues Thema (Titel, Ziel-Forum) | `topic.split` |
| Titel/Präfix ändern | Moderation | Slug wird neu erzeugt, alte URL leitet weiter (Auflösung über ID) | `topic.rename` |
| Beitrag ausblenden / wiederherstellen | Moderation | Platzhalter mit Grund; Autor sieht Inhalt + Grund | `post.hide` / `post.restore` |
| Beitrag bearbeiten | Moderation | Bearbeitungshinweis mit Grund, Version gespeichert | `post.edit` |
| Beitrag in anderes Thema verschieben | Moderation | Zähler beider Themen | `post.move` |
| Freigeben / Ablehnen | Moderation | `pending` → `published` bzw. `rejected` (Grund, Benachrichtigung) | `post.approve` / `post.reject` |
| Löschen (weich) / Papierkorb | Moderation / Admin | `deleted` bzw. `trash`; endgültig nur Admin | `post.delete` |
| Massenaktionen | Moderation | Auswahl in Thema und Themenliste | je Ziel ein Eintrag |

Jede Aktion: POST mit CSRF, Rechteprüfung über `CommunityAccess`, Pflichtgrund bei ausblenden, ablehnen, verwarnen
und sperren (Einstellung), Eintrag im Moderationsprotokoll, Ereignis-Hook.

### 6. Verwarnungen und Sperren

- **Verwarnung:** Grund (Vorlagen + Freitext), Punkte (1–10), Ablauf (Standard 90 Tage), optional Bezug auf Beitrag.
  Mitglied erhält Hinweis + Mail; sieht seine aktiven Verwarnungen im Mitgliederbereich.
- **Schwellen** (Einstellung, Beispiel): 3 Punkte → Freigabepflicht 7 Tage; 5 Punkte → Schreibsperre 7 Tage;
  8 Punkte → Schreibsperre 30 Tage + Hinweis an Admins. Automatische Folgen werden protokolliert und sind aufhebbar.
- **Sperre:** `write` (nur lesen) oder `full` (kein Forumzugriff), befristet oder dauerhaft (`community.ban`).
  Grund für das Mitglied sichtbar, interne Notiz nicht. Ablauf wirkt automatisch (Abfrage beim Zugriff, kein Cron nötig).
- **Kontosperre der ganzen Site** bleibt in der Core-Benutzerverwaltung; die Community zeigt dann nur Gast-Ansichten.
- Moderatoren können niemanden mit gleicher oder höherer Rolle verwarnen oder sperren.

### 7. Meldungen und Freigabe-Warteschlange

**Melden:** jeder angemeldete Nutzer mit `read` (Gäste nicht); Gründe `spam`, `abuse`, `offtopic`, `illegal`, `other`;
höchstens 10 Meldungen/Stunde; erneute Meldung desselben Ziels durch dieselbe Person wird zusammengeführt.
Ab X offenen Meldungen (Einstellung, Standard 3) von Mitgliedern mit Stufe ≥ 2 wird ein Beitrag vorläufig
ausgeblendet, bis die Moderation entscheidet.

**Bearbeitung:** Liste offener Meldungen je Ziel gebündelt (Anzahl, Gründe, Melder), Vorschau mit Kontext,
Aktionen direkt aus der Meldung (ausblenden, verwarnen, sperren), Ergebnis `valid`/`invalid`/`resolved` mit Notiz;
Melder erhalten optional eine neutrale Rückmeldung („Danke, wir haben uns darum gekümmert“).
Forum-Moderatoren sehen nur Meldungen aus ihren Foren.

**Warteschlange:** Beiträge/Themen mit `pending` aus: Forum mit Freigabepflicht, Stufe 0 (Einstellung), Spam-Verdacht
(`AntispamService`/Filter), Verwarnungsschwelle. Sortierung älteste zuerst, Vorschau, Massenfreigabe, Ablehnen mit
Grund. Admin-Hinweis, wenn ein Eintrag älter als 24 h ist (`AdminNoticeService`).

### 8. Vertrauensstufen (P2)

| Stufe | Name | Erreicht durch (Standardwerte, einstellbar) | Grenzen |
|---|---|---|---|
| 0 | Neu | Registrierung | Freigabe für erste 2 Beiträge (Einstellung), max. 1 Link, keine Bilder/Anhänge, 5 Beiträge/Tag, Captcha beim ersten Beitrag |
| 1 | Mitglied | E-Mail bestätigt, 1 Tag, 5 Themen gelesen, 1 freigegebener Beitrag | max. 5 Links, Bilder, 3 Anhänge, 30 Beiträge/Tag |
| 2 | Aktiv | 15 Tage, 20 Beiträge, 10 Reaktionen erhalten, keine aktive Verwarnung | volle Grenzen, Meldungen zählen für vorläufiges Ausblenden |
| 3 | Vertraut | 60 Tage, 100 Beiträge, 50 Reaktionen, 3 Lösungen | Titel des eigenen Themas immer bearbeitbar, Bearbeitungsfenster 24 h |
| 4 | Leitung | nur manuell | wie 3, plus Themen in Unterforen verschieben (falls Forum-Moderator) |

Neu berechnet nächtlich (Cron) und bei Ereignissen (Beitrag freigegeben, E-Mail bestätigt). Verwarnungen senken
nicht automatisch, sperren aber den Aufstieg; Moderation kann eine Stufe festsetzen (`trust_level_locked`).

### 9. Moderationsprotokoll

Alle Aktionen aus §5–§7 mit Akteur, Aktion, Ziel, Forum, Grund, Details und Zeit; Filter nach Akteur, Aktion,
Zeitraum, Ziel. Sichtbar für Moderation (eigene Foren) und Admins. Löschfrist Standard 365 Tage
(`DataRetentionService`, Regel `community.moderation_log`). Keine IP-Adressen im Protokoll.
