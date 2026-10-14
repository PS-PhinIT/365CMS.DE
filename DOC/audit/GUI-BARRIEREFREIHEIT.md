# 365CMS – Gesamtaudit 2026-10-14 | GUI und Barrierefreiheit

> Übersicht: [README.md](README.md) · Vorheriger Stand: A11Y-01 bis A11Y-04 in [../checks/FOLGEAUDIT-2026-10-07.md](../checks/FOLGEAUDIT-2026-10-07.md)

## English (summary)

Seven GUI findings, all fixed:

- **Blocked scripts:** Four admin log views carried inline scripts without the CSP nonce. The browser blocked them, so their filters and toggles did not work.
- **Missing confirmations:** Several destructive admin and member actions ran without asking first.
- **Dashboard texts:** The dashboard showed ASCII transliterations ("Aktivitaet") and developer wording ("Gültiger Leerzustand").
- **Confirm modal:** It had no dialog semantics.
- **Search limit:** The new search rate limit needed a visible notice.
- **Form values:** Descriptions were lost after validation errors.

## Deutsch

| ID | Schwere | Befund | Korrektur |
|---|---|---|---|
| GUI-01 | 🟠 | **Inline-Skripte ohne Nonce.** `admin/views/logs/{overview,operational,php-errors,security-audit}.php` gaben `<script>` ohne `nonce` aus. Unter der aktiven CSP (`script-src 'nonce-…'`) blockiert der Browser diese Skripte. Die Umschalter „Alle Einträge anzeigen“ und die Filter funktionierten deshalb nicht. Im Debug-Modus (Report-Only) fiel das nicht auf. | `<script <?= Security::instance()->nonceAttr() ?>>`. Laufzeittest: Auf allen Log-Seiten bleibt kein `<script>` ohne Nonce übrig. |
| GUI-02 | 🟡 | **Destruktive Admin-Aktionen ohne Rückfrage:** Sicherheits-Logs leeren (*Sicherheit → Audit*), Google-Token entfernen und URL-Entfernung bei Google melden (*SEO → Sitemap*). | `data-cms-confirm` mit eindeutigem Text (zentraler Handler `cms-inline-actions.js`, Browser-Bestätigung). |
| GUI-03 | 🟡 | **Mitgliederbereich:** Zwei-Faktor deaktivieren, Backup-Codes neu erzeugen, Passkey entfernen und Kontolöschung anfordern liefen ohne Rückfrage. | `data-cms-confirm` (Handler `cms-inline-actions.js` ist im Member-Footer geladen). Zusätzlich das Passwortfeld (SEC-18). |
| GUI-04 | ⚪ | **Texte im Dashboard:** „Aktivitaet“, „fuer“, „koennen“, „ausgefuehrte“ und Entwicklertexte wie „Gültiger Leerzustand – …“ waren sichtbar. | Echte Umlaute, nutzerorientierte Leerzustandstexte. |
| GUI-05 | 🟡 | **Bestätigungsdialog:** `#confirmModal` hatte kein `role="dialog"`, kein `aria-modal` und keine Verknüpfung mit Titel und Text. Screenreader kündigten ihn nicht als Dialog an. `cmsConfirm()` brach ohne `options`-Objekt ab. | `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, `aria-describedby`; `options` abgesichert. `cmsAlert()` siehe SEC-25. |
| GUI-06 | ⚪ | **Such-Rate-Limit (LAST-02)** braucht eine verständliche Rückmeldung statt einer leeren Trefferliste. | `search.php` zeigt „Zu viele Suchanfragen in kurzer Zeit. Bitte in einer Minute erneut versuchen.“; HTTP 429 mit `Retry-After`. |
| GUI-07 | ⚪ | **Formularwerte nach Fehlern:** Bei einem Validierungsfehler (z. B. Slug vergeben) gingen Kategorie- und Tag-Beschreibung verloren. | `cat_description` und `tag_description` in den zurückgegebenen Formularwerten. |

### Geprüft ohne Befund

- **Zentrale Bestätigung:** `data-cms-confirm` wird in Admin und Mitgliederbereich einheitlich von `cms-inline-actions.js` behandelt (Submit in der Capture-Phase, `window.confirm()`). `data-cms-confirm-modal` nutzt `cmsConfirm()` mit Fallback auf `confirm()`.
- **Admin-Seiten:** Dashboard, Logs, Kategorien, Tags, Medien, Einstellungen, Kommentare, Info und Diagnose antworten mit HTTP 200, ohne PHP-Warnungen und ohne Fatal Errors (Laufzeittest nach den Änderungen).
- **Standard-Theme:** axe-core-Stand aus dem Folgeaudit 2026-10-07 (0 Verstöße auf 11 Seiten). Die Änderungen in `search.php` und `blog-single.php` fügen nur Text bzw. Bedingungen hinzu.

### Zunächst offene Empfehlungen (umgesetzt in 3.5.0)

Siehe [README.md](README.md):

- OFFEN-10: `<label>` ohne `for=`. ✅ umgesetzt in 3.5.0.
- OFFEN-11: fest verdrahtete Dashboard-Links. ✅ umgesetzt in 3.5.0.
- OFFEN-12: ungenutzte JS-Handler. ✅ umgesetzt in 3.5.0.
