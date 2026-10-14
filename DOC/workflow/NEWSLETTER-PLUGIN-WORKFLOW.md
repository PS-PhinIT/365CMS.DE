# 365CMS – Projektdokumentation | Abschnitt: Workflow – Newsletter-Plugin
> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Nicht in der Runtime enthalten | **Update:** 2026-10-02
> **Quellen:** `CMS/plugins/`, `CMS/core/PluginManager.php` (`PLUGIN_SLUG_ALIASES`), `CMS/core/Services/MailService.php`, `CMS/core/Services/MailQueueService.php`, `CMS/admin/modules/legal/LegalSitesModule.php`

## English (summary)

No newsletter plugin ships with 365CMS 3.5.0. The core provides the building blocks: slug alias `cms-365netnewsletter → cms-newsletter`, `MailService` (SMTP or Microsoft Graph), `MailQueueService` with cron task `mail-queue`, and the legal-text generator profile fields `legal_profile_has_newsletter` / `legal_profile_newsletter_provider`, which add a newsletter section to the generated privacy policy. A newsletter plugin must implement double opt-in, unsubscribe and data-protection hooks itself.

## Deutsch

### Ist-Stand

| Baustein | Vorhanden | Ort |
|---|---|---|
| Newsletter-Plugin | nein | – |
| Slug-Alias | ja | `cms-365netnewsletter` → `cms-newsletter` |
| Mailversand | ja | `MailService::getInstance()->send($to, $subject, $html, $headers)` |
| Warteschlange | ja | `MailQueueService::getInstance()->enqueue($to, $subject, $html, $headers, $availableAt, $source)`; Cron `php CMS/cron.php --task=mail-queue` |
| Mail-Protokoll | ja | `/admin/mail-settings` (Logs), API `/api/v1/admin/mail/logs` |
| Rechtstext | ja | Profilfelder „Newsletter vorhanden“ und „Newsletter-Anbieter“ im Rechtstexte-Generator |

### Integrationsfahrplan für `cms-newsletter`

1. **Grundgerüst** und Tabellen (`{$prefix}newsletter_subscribers` mit Status `pending|confirmed|unsubscribed`, Token, Zeitstempel, IP-Hash) in `cms_newsletter_activate()`.
2. **Anmeldeformular** als Route (`register_routes`, z. B. `POST /newsletter/subscribe`) mit `form_guard`-Token und Rate-Limit (`Security::checkDbRateLimit($ip, 'newsletter')`).
3. **Double-Opt-in:** Bestätigungsmail über `MailService`/`MailQueueService` mit einmaligem Token; erst nach `GET /newsletter/confirm/:token` Status `confirmed`. Einwilligungszeitpunkt speichern.
4. **Abmeldung:** Link in jeder Mail (`/newsletter/unsubscribe/:token`) und Header `List-Unsubscribe`.
5. **Versand:** Kampagnen in die Queue schreiben (`enqueue()` mit `$source = 'newsletter'`); der Versand läuft über den Cron-Task `mail-queue` und respektiert dessen Ratenlimit.
6. **Admin-Oberfläche** über `add_menu_page()` mit Capability-Prüfung und CSRF.
7. **DSGVO:** `dsgvo_export_data` (Abo-Status, Einwilligung) und `dsgvo_delete_data` (Eintrag löschen) implementieren; Rechtstexte-Profil „Newsletter vorhanden“ aktivieren und Anbieter eintragen.
8. **Member-Integration (optional):** Abo-Verwaltung als Bereich im Mitglieder-Dashboard.

Erst nach tatsächlicher Implementierung werden Tabellen, Routen und Oberflächen hier verbindlich dokumentiert.

## Verwandte Dokumente

- [admin/system-settings/MAIL.md](../admin/system-settings/MAIL.md) · [admin/legal/LEGAL.md](../admin/legal/LEGAL.md) · [plugins/PLUGIN-DEVELOPMENT.md](../plugins/PLUGIN-DEVELOPMENT.md)
