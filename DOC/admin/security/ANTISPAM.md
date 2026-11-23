# 365CMS Projektdokumentation | Admin: Anti-Spam

> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Status:** Stable
> **Route:** `/admin/antispam` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_antispam` | **Core-Modul:** `security`

## English (summary)

Central spam protection for comments, contact forms and plugin forms. Admin page: `CMS/admin/antispam.php` → `CMS/admin/modules/security/AntispamModule.php` → `CMS/admin/views/security/antispam.php`. Runtime check: `CMS\Services\AntispamService::evaluate()`. Settings: `antispam_enabled`, `antispam_honeypot`, `antispam_min_time`, `antispam_max_links`, `antispam_block_empty_ua`. Blacklist table `cms_spam_blacklist` (types `word`, `email`, `ip`, `domain`). Actions: `save_settings`, `add_blacklist`, `delete_blacklist`. In 4.2.0, the local `CaptchaService` adds self-hosted proof-of-work, GD image and math challenges to high-protection forms, registration and community composition.

## Deutsch

### Prüfreihenfolge (`AntispamService::evaluate()`)

Nur wenn `antispam_enabled = 1`:

| # | Prüfung | Einstellung | Meldung an den Besucher |
|---|---|---|---|
| 1 | Honeypot-Feld ausgefüllt | `antispam_honeypot` | „Spam erkannt.“ |
| 2 | Formular schneller abgeschickt als erlaubt | `antispam_min_time` (0–60 s, 0 = aus) | „Bitte warten Sie einen Moment …“ |
| 3 | Leerer User-Agent | `antispam_block_empty_ua` | „Die Anfrage wurde aus Sicherheitsgründen blockiert.“ |
| 4 | Zu viele Links im Inhalt | `antispam_max_links` (0–50, 0 = aus) | „Zu viele Links in der Anfrage.“ |
| 5 | Treffer in der Blacklist (E-Mail, IP, Domain, Wort in Name/Inhalt) | Blacklist | „Die Anfrage wurde aus Sicherheitsgründen blockiert.“ |

Jede Ablehnung wird mit Grund (`honeypot`, `minimum_time`, `empty_user_agent`, `max_links`, `blacklist`) protokolliert und ist im Sicherheits-Log sichtbar.

**Standardwerte**, solange nichts gespeichert wurde: AntiSpam aus, Honeypot an, Mindestzeit 3 s, leerer User-Agent wird blockiert, höchstens 3 Links. Seit 4.0.0 zeigt das Formular genau diese wirksamen Werte; vorher waren Honeypot und User-Agent-Prüfung im Formular nicht angehakt und wurden beim ersten Speichern ausgeschaltet. Ein gespeicherter Wert `0` bei Mindestzeit oder Link-Limit wird als `0` angezeigt und bleibt beim nächsten Speichern erhalten (vorher erschien `3`, und ein erneutes Speichern schaltete die Prüfung wieder ein).

Der Prüfpunkt *AntiSpam Runtime aktiv* im [Sicherheits-Audit](SECURITY-AUDIT.md) warnt, solange AntiSpam ausgeschaltet ist, aber Kommentare oder Kontaktformulare aktiv sind.

### Captcha des Core (`CaptchaService`)

Captcha ist eine zusätzliche Schicht, nicht Ersatz für Honeypot, Mindestzeit, Rate-Limits, Wortfilter oder Freigaben. Alle drei Verfahren sind lokal; es gibt keine Anfrage an einen Drittanbieter und damit keine Captcha-Einwilligung:

| Verfahren | Umsetzung | Rückfall |
|---|---|---|
| `pow` | Proof-of-Work nach dem ALTCHA-Prinzip: Der Server erzeugt Salz und eine Zahl, veröffentlicht nur `SHA-256(salz + n)`, und das Browsermodul sucht `n` mit Web Crypto. Der Server braucht anschließend nur einen Hash. | Eine Rechenaufgabe bleibt ohne JavaScript bzw. Web Crypto sichtbar oder wird nach einem Fehler eingeblendet. |
| `image` | Fünf lokale GD-Zeichen, als PNG-`data:`-URI mit Drehung, Störlinien und Verzerrung. Voraussetzung: `imagecreatetruecolor`, `imagepng` und `imagestring`. | Rechenaufgabe; bei fehlendem GD bereits beim Auswählen. |
| `math` | Addition/Subtraktion ohne JavaScript, Bild oder GD. Das Verfahren ist immer verfügbar. | – |

`CaptchaService` kennt nur bekannte, verfügbare und nicht einwilligungspflichtige Anbieter. Ein unbekannter oder nicht verfügbarer Anbieter fällt still auf `math` zurück, **nicht** auf „kein Captcha“. `cms_captcha_providers` kann Anbieter ergänzen oder ersetzen; `math` bleibt immer erhalten. Die Kennung des tatsächlich gewählten Verfahrens steht serverseitig in der Aufgabe, nicht in der Anfrage.

#### Ablage, Ablauf und Kontext

- Offene Aufgaben liegen unter `$_SESSION['cms_captcha']`; maximal acht je Sitzung. Lazy Sessions und bereits aktive Sessions werden unterstützt. Ist weder eine aktive noch eine verzögerte Sitzung vorhanden, rendert der Dienst einen Hinweis und die Prüfung meldet `unavailable`.
- Eine Aufgabe gilt höchstens 7200 Sekunden (zwei Stunden) und ist an ihren Kontext gebunden. `take()` löscht sie beim Prüfen, also auch bei falscher Antwort, fremdem Kontext oder Ablauf. Eine weitere Antwort auf dieselbe Kennung ist nicht möglich.
- Private Daten (Lösung/Code) verbleiben in der Sitzung; nur Salz, Hash, Obergrenze und die öffentliche Rechenaufgabe gehen ins HTML. Alle Ausgabewerte sind maskiert; die lokalen Verfahren brauchen keine zusätzliche CSP-Quelle.
- Ergebnis-Codes sind `ok`, `missing`, `expired`, `context`, `invalid` und `unavailable`.

#### Einbindungen und Helfer

- **Formulare:** nur Antispam-Stufe `high`. `FormSpamGuard::captchaHtml($formKey)` nutzt standardmäßig `pow`, bindet an `forms.<formKey>` und lässt `cms_forms_captcha_provider` je Formular das Verfahren wählen. `verifyCaptcha()` verbraucht die Aufgabe; `FormRenderer` lädt CSS/JS nur für ein solches Formular.
- **Registrierung:** Einstellung `register_captcha` (Standard aus). Die Core- und `cms-default`-Vorlagen geben `cms_captcha_field('register', 'pow')` samt `cms_captcha_asset_tags()` aus; `PublicRouter` prüft mit dem Kontext `register` **vor** dem Anlegen eines Kontos. Aktivierte Prüfung plus eigenes Theme ohne diese Felder macht die Registrierung absichtlich nicht möglich.
- **Community:** `CaptchaGate` gilt nur für `create_topic` und `reply`, nie beim Bearbeiten. Modi `off`, `first_post`, `trust0`, `always`; globale Moderation ist ausgenommen. `captcha_provider` wählt `pow`, `image` oder `math`; `cms_community_captcha_required` darf nur verschärfen. Fehler landen als `captcha_failed` im Sicherheitsprotokoll. Kontext ist `community.compose`.
- **Eigene Theme-/Plugin-Formulare:** `cms_captcha_field($kontext, $verfahren)` rendert das Fragment, `cms_captcha_asset_tags()` liefert CSS und ES-Modul. Der Server muss dieselbe Kontext-Zeichenfolge und `CaptchaService::verify()` verwenden.

```php
<?= cms_captcha_asset_tags() ?>
<form method="post">
    <?= cms_captcha_field('mein-formular', 'pow') ?>
    <!-- ... -->
</form>

<?php
$ok = \CMS\Services\Security\CaptchaService::instance()
    ->verify($_POST, 'mein-formular')
    ->ok;
?>
```

### Blacklist

| Typ | Validierung | Beispiel |
|---|---|---|
| `word` | Freitext | `casino` |
| `email` | gültige E-Mail | `spam@example.com` |
| `ip` | gültige IPv4/IPv6 | `203.0.113.7` |
| `domain` | gültiger Domainname | `spam-domain.tld` |

Doppelte Einträge (gleicher Typ und Wert) werden erkannt; Tabelle `cms_spam_blacklist` (eindeutiger Schlüssel `type`, `value`).

### Einbindung in eigene Formulare

```php
$result = \CMS\Services\AntispamService::getInstance()->evaluate([
    'honeypot_value' => $_POST['website'] ?? '',      // verstecktes Feld
    'started_at'     => (int) ($_POST['form_ts'] ?? 0), // Zeitstempel beim Rendern
    'content'        => $_POST['message'] ?? '',
    'email'          => $_POST['email'] ?? '',
    'author_name'    => $_POST['name'] ?? '',
    'ip_address'     => \CMS\Security::getClientIp(),
]);
if ($result['rejected']) {
    // $result['message'] anzeigen, nicht speichern
}
```

Das Sicherheits-Audit ([SECURITY-AUDIT.md](SECURITY-AUDIT.md)) prüft, ob aktive Kontaktformular-Plugins diese Auswertung inklusive Zeitstempel nutzen.

### Verwandte Dokumente

[FIREWALL.md](FIREWALL.md) · [SECURITY-AUDIT.md](SECURITY-AUDIT.md) · [../pages-posts/COMMENTS.md](../pages-posts/COMMENTS.md)

### Tests

Aus dem CMS-Installationsverzeichnis:

```bash
php TESTS/captcha/run.php
php TESTS/community-captcha/run.php
```

Die Läufe decken Anbieter und Fallback, Browser-Proof-of-Work, Sitzung, Ablauf, Kontext, Ausgabe sowie die Integrationen in Formulare, Registrierung und Community ab.
