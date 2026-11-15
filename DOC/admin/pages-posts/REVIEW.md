# 365CMS Projektdokumentation | Admin: Freigabe (Vier-Augen-Prinzip)

> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Status:** veröffentlicht
> **Routen:** `/admin/posts`, `/admin/pages` (Aktion `workflow`), `/preview/<token>` (öffentlich) | **Einstellung:** *Einstellungen → Beiträge & Sites → Standard beim Speichern*

## English (summary)

Posts and pages gain the status **`pending`** ("Zur Prüfung"). Publishing (status `published`, also scheduled, or `private`) requires `posts.publish` / `pages.publish`; admins hold every capability. The setting **"Veröffentlichung braucht eine zweite Person"** additionally forbids anyone, admins included, to publish content they authored or submitted: they can only submit it, and another account with the publish right approves it. Content that is already published stays published when edited. The rule is enforced on the server in `PostsModule::save()`, `PagesModule::save()` (also after the `cms_prepare_*_save_payload` filters), in autosave and in the bulk action "publish", which submits drafts the user may not publish. Reviewers approve (status `published`; a future date is kept, so the post is scheduled) or return the item with a mandatory comment (status `draft`). Every step is stored in `cms_content_workflow_events` and shown as a history in the editor; the other parties get an in-app notification and an email from the mail queue. The dashboard shows "Ausstehende Prüfungen", the menu entries *Beiträge* and *Seiten* carry the badges `content_pending_posts` / `content_pending_pages`, both lists have the filter "Zur Prüfung". Shareable preview links `/preview/<token>` show drafts without login (only the SHA-256 hash is stored, valid for 7 days, revocable, `noindex`, `no-store`, `Referrer-Policy: no-referrer`). Hooks: `cms_content_submitted`, `cms_content_approved`, `cms_content_returned`. Service: `CMS\Services\Content\ContentWorkflow`; status values: `ContentStatus`.

## Deutsch

### Wann gilt was?

| Situation | Ergebnis |
|---|---|
| Konto ohne `posts.publish` bzw. `pages.publish` wählt „Veröffentlichen“, „Planen“ oder „Privat“ | gespeichert wird **Zur Prüfung** (`pending`), Hinweis in der Meldung |
| Einstellung **„Veröffentlichung braucht eine zweite Person“** an, eigener Beitrag bzw. eigene Seite | wie oben: auch für Admins; freigeben muss ein anderes Konto mit Veröffentlichungsrecht |
| Einstellung an, fremder Inhalt, den man selbst eingereicht hat | ebenfalls keine Freigabe durch einen selbst (Autor **und** einreichende Person sind ausgeschlossen) |
| Inhalt ist bereits veröffentlicht oder privat | Bearbeiten bleibt ohne neue Prüfung möglich; der Status bleibt |
| Zurück auf Entwurf, Einreichen | immer erlaubt |

Admins haben in 365CMS alle Rechte. Solange nur Admins den Admin-Bereich nutzen, wirkt das Vier-Augen-Prinzip deshalb **nur über die Einstellung**. Der Weg über die Rechte (`posts.publish`/`pages.publish`) greift automatisch, sobald andere Rollen Zugang zum Admin-Bereich erhalten.

### Ablauf im Editor

- **Kopfleiste (Beiträge):** Wer nicht selbst veröffentlichen darf, sieht statt „Planen“/„Veröffentlichen“ den Knopf **„Zur Prüfung einreichen“** (bzw. „Erneut einreichen“). Er speichert die Änderungen und reicht ein; ein eingetragenes Veröffentlichungsdatum bleibt für die Freigabe stehen.
- **Statusfeld:** neuer Wert „Zur Prüfung“; „Veröffentlicht“ und „Privat“ sind ohne Recht deaktiviert.
- **Bereich „Freigabe“** (Beiträge: Einstellungsspalte; Seiten: Karte „Veröffentlichung“): aktueller Status, Kommentarfeld, Knöpfe **Freigeben** und **Zurückgeben** (nur für Konten, die freigeben dürfen), **Kommentar senden**, Vorschau-Links und der **Verlauf** (Einreichen, Freigeben, Zurückgeben mit Begründung, Zurückziehen, Kommentare, Vorschau-Links; neueste zuerst).
- **Zurückgeben** verlangt eine Begründung und setzt den Status auf Entwurf. Zieht die Autorin eine Einreichung selbst zurück (Status „Entwurf“ speichern), steht „Einreichung zurückgezogen“ im Verlauf.
- **Freigeben** setzt `published`; liegt das Datum in der Zukunft, ist der Beitrag danach geplant. Freigeben kann man auch über „Veröffentlichen“ in der Kopfleiste (speichert zusätzlich die Änderungen) oder über die Sammelaktion.

### Listen, Dashboard, Menü

- Filter **„Zur Prüfung“** und Zähler in den Listen *Beiträge* und *Seiten* (`?status=pending`).
- Sammelaktion **„Veröffentlichen“**: veröffentlicht, was das Konto freigeben darf; eigene Entwürfe werden stattdessen eingereicht, eingereichte Inhalte ohne Recht bleiben unverändert. Die Meldung nennt beide Zahlen.
- Dashboard-Karte **„Ausstehende Prüfungen“**: Titel, Typ, Autor, Einreichungszeitpunkt: nur Inhalte, die das angemeldete Konto freigeben dürfte.
- Badges an *Beiträge* (`content_pending_posts`) und *Seiten* (`content_pending_pages`) nach derselben Regel.
- Redaktionskalender: eingereichte Inhalte mit „Geplantem Datum“ bleiben sichtbar und verschiebbar.

### Benachrichtigungen

| Schritt | Empfänger | Weg |
|---|---|---|
| Eingereicht | alle aktiven Konten, die den Inhaltstyp freigeben dürften: ohne Autor und einreichende Person | In-App-Hinweis + Mail (Mail-Warteschlange, Quelle `content_workflow`) |
| Freigegeben, Zurückgegeben | Autor und einreichende Person: ohne die handelnde Person | wie oben |

Die Mail enthält Titel, Kommentar und den Link in den Editor.

### Vorschau-Link

- „Vorschau-Link erstellen“ im Bereich *Freigabe* (nicht für bereits öffentliche Inhalte und den Papierkorb). Der Link `https://<site>/preview/<token>` wird **nur einmal** in der Erfolgsmeldung angezeigt, gespeichert wird lediglich der SHA-256-Hash.
- Gültig 7 Tage; „Alle widerrufen“ entfernt sofort alle Links des Inhalts. Abgelaufene Einträge räumt `cms_cron_daily` auf. Landet der Inhalt im Papierkorb, liefert der Link 404.
- Ausgabe über dieselben Theme-Vorlagen (`blog-single`, `page`) mit `X-Robots-Tag: noindex, nofollow`, `Cache-Control: private, no-store` und `Referrer-Policy: no-referrer`. Unbekannte, abgelaufene und widerrufene Tokens liefern dieselbe 404-Seite.

### Rechte und Update

Mit 4.1.0 werden `posts.publish` und `pages.publish` erstmals geprüft. Damit niemand ausgesperrt wird, vergibt die Laufzeitprüfung des Schemas einmalig `posts.publish` und `pages.publish` an *Redakteur* sowie `posts.publish` an *Autor*, nur dort, wo für die Rolle noch kein Eintrag gespeichert ist. Ein bewusst entzogenes Recht bleibt entzogen. Soll ein Autor nicht selbst veröffentlichen, wird ihm `posts.publish` unter *Rollen & Rechte* (`/admin/roles`) entzogen.

### Grenzen

- Import (*Inhalte & Daten*) übernimmt den Status der Datei unverändert; „Als Entwurf importieren“ verhindert veröffentlichte Importe.
- Änderungen an bereits veröffentlichten Inhalten werden bewusst nicht erneut geprüft (Entscheidung vom 15.11.2026): Die Freigabe gilt für das Veröffentlichen. Was sich danach geändert hat, zeigen die Revisionen.
- Downloads, Wissensdatenbank und Newsletter haben keinen Freigabe-Ablauf.

### Technik

| Baustein | Aufgabe |
|---|---|
| `CMS\Services\Content\ContentStatus` | Statuswerte, Bezeichnungen, Badges, „ist Veröffentlichung?“ |
| `CMS\Services\Content\ContentWorkflow` | `canPublish()`, `resolveSaveStatus()`, `submit()`, `approve()`, `returnToAuthor()`, `addComment()`, `history()`, `pendingItems()`, Vorschau-Links, `migrateCapabilities()` |
| `FeatureHooks\ContentWorkflowHooks` | Badges, Aufräumen der Vorschau-Links |
| Tabellen | `cms_content_workflow_events`, `cms_content_preview_links` ([DATABASE-SCHEMA.md](../../core/DATABASE-SCHEMA.md)) |
| Hooks | `cms_content_submitted`, `cms_content_approved`, `cms_content_returned` ([HOOKS-REFERENCE.md](../../core/HOOKS-REFERENCE.md)) |
| Tests | `php TESTS/content-workflow/run.php` |

### Verwandte Dokumente

[POSTS.md](POSTS.md) · [PAGES.md](PAGES.md) · [SETTINGS.md](SETTINGS.md) · [Spezifikation 3a](../../workflow/specs/v4.1/03-CONTENT-WORKFLOWS.md)
