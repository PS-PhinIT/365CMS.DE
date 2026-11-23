# 365CMS — Security & Code-Audit: `core/`

**Datum:** 2026-06-10
**Scope:** Verzeichnis `core/` (142 Dateien, ~Kern-Framework). `vendor/` und die Drittbibliotheken unter `assets/` (HTMLPurifier, LdapRecord, Carbon, Mailer …) waren ausgeschlossen.
**Methode:** Pattern-Scan über **alle** 142 core-Dateien (SQL-Konkatenation, ungeprüfte Ausgaben, `unserialize`, dynamische `include`, lose `==` in Auth-Kontexten, `mail()`-Header, Redirects, Datei-Operationen, Deprecations, ReDoS) + zeilenweises Lesen der sicherheitskritischen Dateien (`Database`, `Auth`, `Security`, `Router`, `Api`, `Bootstrap`, `Routing/*`, `Services/FileUploadService`, `Services/MediaService`, `Services/MailService`, `Services/EditorJsRenderer`, `Services/EditorJs/EditorJsHtmlSanitizer`, `CacheManager`, `Debug`).
**Fixes:** Direkt in den Originaldateien angewandt. Backup unter `core_backup_20260610_170042/`. Verifikation strukturell (Read-Gegenlesen, md5/Backup-Diff, Klammer-Delta); `php -l` war in der Sandbox nicht verfügbar (kein PHP-CLI, kein root).

---

## Gesamtbewertung

`core/` ist **überdurchschnittlich gut abgesichert**. Die im Audit-Auftrag geforderten Kontrollen sind bereits durchgängig implementiert: parametrisierte Queries mit Identifier-Whitelisting, native Prepared Statements, CSRF-Tokens mit `hash_equals`, bcrypt (cost 12) + 12-Zeichen-Passwort-Policy, DB-gestütztes Rate-Limiting, DOM-basierter HTML-Sanitizer gegen Stored-XSS, gehärtete Upload-Validierung (Extension-Whitelist + Dangerous-Blacklist + MIME-Cross-Check + `.htaccess`-Execution-Block), Open-Redirect-Normalisierung, vollständige Security-Header (CSP, HSTS, COOP/CORP, nosniff), saubere Session-Konfiguration (`HttpOnly`, `Secure`, `SameSite=Strict`, `use_strict_mode`, `regenerate_id`) und HMAC-geschützter Cache (JSON statt `unserialize` → kein PHP Object Injection).

Es wurden **keine kritischen** Schwachstellen (SQLi, gespeichertes/reflektiertes XSS, Auth-Bypass, RCE, Object Injection, Open Redirect, Path Traversal) in `core/` gefunden. Die Funde liegen ausschließlich im Bereich **Defense-in-Depth** und **Härtung** und wurden direkt behoben.

---

## 🔴 KRITISCH (sofort beheben)

**Keine.** In `core/` wurden keine kritischen Schwachstellen identifiziert.

---

## 🟡 WARNUNG (zeitnah beheben) — behoben

### [PHP Security] core/Services/MailService.php:665–681 — Header-Injection im `mail()`-Fallback (Betreff/Empfänger)

**Problem:** Der SMTP-Pfad (`createBaseEmail()`) validiert Empfänger (`FILTER_VALIDATE_EMAIL`) und Betreff (`sanitizeSubject()`), gibt die bereinigten Werte aber nur lokal in das Symfony-`Email`-Objekt. Ist SMTP deaktiviert (`use_smtp = false`), läuft der Versand über `mail($to, $subject, …)` mit den **Rohwerten**. `sanitizeSubject()` *reinigt* den Betreff zwar, der ungereinigte `$subject` erreicht `mail()` jedoch trotzdem. Enthält ein von außen beeinflussbarer Betreff CRLF-Sequenzen, lassen sich zusätzliche Mail-Header injizieren (z. B. `Bcc:`). `$to` ist durch die vorgelagerte E-Mail-Validierung praktisch CRLF-frei — der Fix deckt ihn als Defense-in-Depth mit ab.

**Vorher:**
```php
private function sendMessageFallback(string $to, string $subject, string $body, array $headers, bool $isHtml): bool
{
    $config = $this->getEffectiveConfig();
    $messageHeaders = $this->buildHeaders(/* … */);

    return mail($to, $subject, $body, $messageHeaders);
}
```

**Nachher:**
```php
private function sendMessageFallback(string $to, string $subject, string $body, array $headers, bool $isHtml): bool
{
    $config = $this->getEffectiveConfig();

    // Defense-in-Depth: PHP mail() übernimmt $to und $subject direkt in den
    // Mail-Header. Anders als der SMTP-Pfad (createBaseEmail) erhält dieser
    // Fallback die Rohwerte – daher hier CRLF/Steuerzeichen entfernen.
    $to = $this->sanitizeHeaderValue($to);
    $subject = $this->sanitizeHeaderValue($subject);

    $messageHeaders = $this->buildHeaders(/* … */);

    return mail($to, $subject, $body, $messageHeaders);
}
```

> Identischer Fix zusätzlich in `sendWithAttachmentFallback()` angewandt (`$to`, `$subject`).
> `sanitizeHeaderValue()` ist idempotent — für bereits gültige Betreffs/Adressen ändert sich nichts.

---

### [PHP Security] core/Services/MailService.php:705–709 — MIME-Header-Injection über Anhangsdateinamen

**Problem:** `$attachmentName` wird ungefiltert in die MIME-Header `Content-Type: …; name="{$attachmentName}"` und `Content-Disposition: attachment; filename="{$attachmentName}"` interpoliert. Ein Dateiname mit `"`, CRLF oder Steuerzeichen ermöglicht das Ausbrechen aus dem Header-Wert bzw. das Einschleusen weiterer MIME-Header. Der Wert kann vom Aufrufer gesetzt werden (`sendWithAttachmentDetailed(..., $attachmentName)`); nur bei leerem Wert greift `basename($attachmentPath)`.

**Vorher:**
```php
$messageBody .= "Content-Type: {$mimeType}; name=\"{$attachmentName}\"\r\n";
$messageBody .= "Content-Transfer-Encoding: base64\r\n";
$messageBody .= "Content-Disposition: attachment; filename=\"{$attachmentName}\"\r\n\r\n";
```

**Nachher:** (Bereinigung vor der Verwendung + neue Helper-Methode)
```php
// am Anfang von sendWithAttachmentFallback():
$attachmentName = $this->sanitizeAttachmentFilename($attachmentName);

// neue Methode:
private function sanitizeAttachmentFilename(string $filename): string
{
    $filename = str_replace('\\', '/', $filename);
    $filename = basename($filename);                                  // keine Pfadanteile
    $filename = str_replace(["\r", "\n", "\0", '"'], '', $filename);   // kein Header-Ausbruch
    $filename = preg_replace('/[\x00-\x1F\x7F]+/u', '', $filename) ?? $filename;
    $filename = trim($filename);

    if ($filename === '') {
        $filename = 'attachment';
    }

    return mb_substr($filename, 0, 200, 'UTF-8');
}
```

---

### [PHP Security / Error Handling] core/Bootstrap.php — kein Applikations-seitiger Schutz gegen Stack-Trace-Leaks

**Problem:** Es existierte kein globaler `set_exception_handler`/`set_error_handler` und kein erzwungenes `display_errors=0`. Das Fehlerverhalten hing vollständig von der `php.ini` der Plattform ab. Ist dort `display_errors=On` (häufiger Fehlkonfigurationsfall auf Shared Hosting / nach Migration), würde eine nicht abgefangene Exception Dateipfade und Stack-Traces an den Client leaken. Der Audit-Auftrag fordert explizit: `display_errors` produktiv aus, Custom-Handler, keine Stack-Traces an den Client.

**Nachher:** (additive Härtung in `__construct()`, gated über `CMS_DEBUG`)
```php
Debug::enable(defined('CMS_DEBUG') && CMS_DEBUG);
$this->hardenErrorReporting(defined('CMS_DEBUG') && CMS_DEBUG);   // NEU
```
```php
private function hardenErrorReporting(bool $debug): void
{
    if ($debug) {
        @ini_set('display_errors', '1'); @ini_set('display_startup_errors', '1');
        error_reporting(E_ALL);
    } else {
        @ini_set('display_errors', '0'); @ini_set('display_startup_errors', '0');
        @ini_set('log_errors', '1'); error_reporting(E_ALL);    // alles loggen, nichts anzeigen
    }

    // Nur registrieren, wenn noch kein eigener Handler aktiv ist:
    $previous = set_exception_handler(null);
    if ($previous !== null) { set_exception_handler($previous); return; }

    set_exception_handler(static function (\Throwable $e) use ($debug): void {
        error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (PHP_SAPI === 'cli') { fwrite(STDERR, 'Fatal error: ' . $e->getMessage() . PHP_EOL); exit(1); }
        if (!headers_sent()) { http_response_code(500); }
        if ($debug) {
            echo 'Fatal error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        } else {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            if (defined('CMS_AJAX_REQUEST') || str_starts_with($uri, '/api/')) {
                if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
                echo json_encode(['success' => false, 'error' => 'Internal server error'], JSON_UNESCAPED_UNICODE);
            } else {
                echo 'Es ist ein interner Fehler aufgetreten. Bitte versuche es später erneut.';
            }
        }
        exit(1);
    });
}
```

> 💡 **Annahme:** Die App nutzte bisher keinen eigenen globalen Exception-Handler (Grep über `index.php`, `config.php`, `cron.php`, `core/*` ergab keinen). Der neue Handler ist daher rein additiv und feuert nur bei sonst fatalen, nicht abgefangenen Exceptions. Der bestehende Plattform-Mismatch-Renderer (`abortForPlatformMismatch`, 503) bleibt unberührt.

---

## 🔵 VERBESSERUNG (empfohlen — nicht automatisch geändert)

### [Auth] core/Security.php:477 — Passwort-Hash auf Argon2id umstellen (mit Migration)

**Beobachtung:** `password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])`. bcrypt mit cost 12 ist solide und erfüllt den Audit-Auftrag. Der Auftrag nennt jedoch **Argon2id** als bevorzugte Option (speicherhart, GPU-resistenter).

**Empfehlung (kein Auto-Fix, da auth-kritisch):** Neue Hashes mit `PASSWORD_ARGON2ID` erzeugen und beim Login per `password_needs_rehash()` transparent migrieren. `password_verify()` verifiziert bestehende bcrypt-Hashes weiterhin, daher kein Bruch — aber die Umstellung sollte gezielt getestet und gegen die `PHP`-Build-Verfügbarkeit von Argon2 abgesichert werden (`defined('PASSWORD_ARGON2ID')`-Fallback auf bcrypt).

```php
// Skizze:
$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
$hash = password_hash($password, $algo);
// beim Login:
if (password_verify($input, $stored) && password_needs_rehash($stored, $algo)) {
    $newHash = password_hash($input, $algo); // persistieren
}
```

### [Performance / Code-Qualität] Beobachtungen ohne Handlungsdruck

- **`X-XSS-Protection: 0`** (Security.php:205) ist **korrekt** so — der Legacy-XSS-Auditor wird bewusst deaktiviert; kein Fehler.
- **CSP** wird teils als `Content-Security-Policy-Report-Only` und teils enforced ausgeliefert (Security.php:240/242). Falls die Report-Only-Phase abgeschlossen ist, kann sie entfernt werden, um doppelte Header zu vermeiden.
- **Roh-`query()`-Aufrufe** (z. B. `SchemaManager`, `BackupService`, `RedirectService`) verwenden ausschließlich interne, validierte Bezeichner (Prefix, `quoteSqlIdentifier`, ENUM-Literale) — kein User-Input. Unkritisch, aber dokumentiert.

---

## 📊 Audit-Zusammenfassung

| Kategorie         | Kritisch | Warnung | Verbesserung |
|-------------------|:--------:|:-------:|:------------:|
| PHP Security      |    0     |    3    |      1       |
| PHP Bugs          |    0     |    0    |      0       |
| PHP Performance   |    0     |    0    |      2       |
| HTML              |    –     |    –    |      –       |
| CSS               |    –     |    –    |      –       |
| **Gesamt**        |  **0**   |  **3**  |    **3**     |

> HTML/CSS waren im Scope `core/` nicht relevant (reine PHP-/Logik-Schicht; Templates liegen unter `themes/`, `admin/views/`).

**Status der Warnungen:** alle 3 **behoben** (direkt in den Dateien, Backup vorhanden).
**Geänderte Dateien:** `core/Services/MailService.php`, `core/Bootstrap.php`.
**Rollback:** `core_backup_20260610_170042/` zurückkopieren.
