# 365CMS — Security & Code-Audit: `member/`, `includes/`, `marketplace/`, `views/`

**Datum:** 2026-06-10
**Scope:** `member/` (17 Dateien, ~3.450 Z., user-facing Mitgliederbereich), `includes/` (10 Dateien, ~2.770 Z., globale Helfer: Escaping, Roles, Redirects/Auth, Options-Runtime), `marketplace/` (nur 3 JSON-Manifeste, **kein Code**), `views/` (1 Datei: `views/auth/cms-auth.php`, Login/Register/Reset-UI).
**Methode:** Pattern-Scan über alle PHP-Dateien (eval/system/`unserialize`/dynamische `include`, SQL-Interpolation mit Request-Vars, reflected XSS, CSRF, Redirects/Open-Redirect, File-Ops) + Tiefenlesen der Security-Helfer und der Auth-View.
**Fixes:** Direkt in den Dateien angewandt; Verifikation strukturell (Grep, Tag-Balance). `php -l` in der Sandbox nicht verfügbar.

---

## Gesamtbewertung

Auf demselben hohen Niveau wie `core/` und `admin/`. Die globalen Helfer in `includes/functions/` sind solide: `esc_html`/`esc_attr`/`esc_textarea` nutzen `htmlspecialchars(ENT_QUOTES, UTF-8)`, `esc_url` blockt `javascript:`/`data:`/`vbscript:`/`file:`, `unserialize` läuft mit `['allowed_classes' => false]` (kein Object Injection), alle SQL-Statements verwenden Platzhalter (nur interne Tabellen-Prefixe interpoliert), `current_user_can()` prüft Login + Admin + Capability. Der zentrale Redirect-Guard `cms_normalize_redirect_target()`/`safe_redirect()` verwirft fremde Origins, `//`-Protokoll-relative Ziele und unsichere Schemes. Kein reflected XSS, CSRF auf allen POST-Pfaden. `marketplace/` enthält keinen ausführbaren Code.

**Keine kritischen, hohen oder mittleren Schwachstellen.** Zwei latente Defense-in-Depth-Schwächen in ausgelieferten Bausteinen wurden gehärtet.

---

## 🔴 KRITISCH

**Keine.**

---

## 🟡 WARNUNG (behoben)

### [PHP Security] includes/functions/escaping.php:59 — `esc_js()` zu schwach für Script-Kontext

**Problem:** `esc_js()` nutzte nur `addslashes(html_entity_decode(...))`. `addslashes` maskiert lediglich `'`, `"`, `\`, NUL — **nicht** `</script>` oder JS-Zeilenterminatoren (CR/LF, U+2028, U+2029). In einem `<script>`-Block oder JS-String könnte ein Wert mit `</script>` oder einem Zeilenseparator ausbrechen (XSS). Aktuell wird `esc_js()` **nirgends** aktiv aufgerufen (latent), ist aber ein öffentlich ausgelieferter Helfer, den Plugins/Themes nutzen können.

**Nachher:** (additive Härtung — es wird nur mehr escaped, voll rückwärtskompatibel)
```php
function esc_js(string $text): string {
    $text = addslashes(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
    return str_replace(
        ['</', "\r", "\n", "\u{2028}", "\u{2029}"],
        ['<\/', '\r', '\n', ' ', ' '],
        $text
    );
}
```

### [PHP Security] member/includes/class-member-controller.php:310 — Open-Redirect-Passthrough in `redirect()`

**Problem:** `MemberController::redirect()` ließ absolute `http(s)://`-URLs **unverändert** an `header('Location: …')` durch. Alle aktuellen Aufrufer übergeben hartkodierte interne Pfade oder intern gebaute Media-URLs (kein Request-Input) — der Passthrough ist also eine latente Open-Redirect-Schwäche, falls künftig ein Aufrufer User-Input weiterreicht.

**Nachher:** (absolute URLs nur same-origin, sonst interner Fallback — via vorhandenen Core-Guard)
```php
public function redirect(string $path): void
{
    if (preg_match('#^https?://#i', $path)) {
        $normalized = \function_exists('cms_normalize_redirect_target')
            ? \cms_normalize_redirect_target($path, false)
            : null;
        $path = ($normalized !== null && $normalized !== '') ? $normalized : '/';
    } else {
        $path = '/' . ltrim($path, '/');
    }
    header('Location: ' . $path, true, 302);
    exit;
}
```

---

## 🔵 VERBESSERUNG

- **`marketplace/`** enthält nur JSON-Manifeste (core/plugins/themes). Empfehlung: per `.htaccess`/Server-Regel sicherstellen, dass diese nicht als ausführbares PHP interpretiert werden (aktuell kein Risiko, da reine Daten).
- **`views/auth/cms-auth.php`** ist sauber (CSRF, escapte Ausgabe, Rate-Limit-Hinweise via `CmsAuthPageService`). Keine Änderung nötig.

---

## 📊 Audit-Zusammenfassung

| Kategorie       | Kritisch | Warnung | Verbesserung |
|-----------------|:--------:|:-------:|:------------:|
| PHP Security    |    0     |    2    |      1       |
| PHP Bugs        |    0     |    0    |      0       |
| PHP Performance |    0     |    0    |      0       |
| HTML / JS       |    0     |    0    |      0       |
| **Gesamt**      |  **0**   |  **2**  |    **1**     |

**Geänderte Dateien:** `includes/functions/escaping.php`, `member/includes/class-member-controller.php`.
**Status:** beide Warnungen behoben. Rollback über Git-Historie (Sandbox-Backups wurden beim Umgebungs-Reset entfernt).

---

### Gesamtbild der bisherigen Audits (core + admin + member/includes/views)

Über alle drei Audit-Läufe: **0 kritische**, **6 Warnungen** (alle behoben — durchweg Defense-in-Depth), wenige Verbesserungsempfehlungen. 365CMS ist eine sicherheitstechnisch ausgereifte Codebasis mit konsequent umgesetzten Standardkontrollen.
