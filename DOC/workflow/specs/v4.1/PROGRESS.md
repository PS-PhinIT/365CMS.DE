# 365CMS – Projektdokumentation | Abschnitt: Spec 4.1.0 – Fortschritt
> **Stand:** 2026-11-15 | **Ziel-Version:** 4.1.0 | **Übersicht:** [README.md](README.md)

## English (summary)

Checklist of the 4.1.0 work packages. ✅ done (with evidence) · 🟡 in progress · ⬜ open.

## Deutsch

| # | Arbeitspaket | Status | Ergebnis / Nachweis |
|---|---|---|---|
| 0 | Analyse und Spezifikation (dieses Dokument-Set) | ✅ | `DOC/workflow/specs/v4.1/` (14.11.2026) |
| 1a | Passwort-Richtlinie mit Historie und Ablauf | ✅ | `Security\PasswordPolicyService`, Einbindung in Registrierung/Admin/Mitglied/Zurücksetzen, Formular unter *Login & Sperren*, Unicode-Zeichenklassen (Server und Live-Tester), Ablauf-Flag beim Login (Weiche: 1b); `TESTS/password-policy` 53 Prüfungen, Gegenproben (Historie, Filter) schlagen fehl |
| 1b | Rollenbasierter MFA-Zwang | ✅ | `Security\MfaEnforcementService`, Konto-Weiche `Router::enforceAccountGates()` (Passwort-Ablauf vor MFA), Passkeys speichern `user_verified` (Registrierung, nachträglich bei UV-Login), Schutz vor Entfernen des letzten Faktors (`/mfa-disable`, Mitgliederbereich), Formular *MFA-Zwang je Rolle* mit Selbstaussperr-Schutz; `TESTS/mfa-enforcement` 173 Prüfungen, Gegenprobe (Präfix-Allowlist, TOTP-Abschalten) schlägt mit 8 Fehlern fehl |
| 1c | Entra-ID-SSO (OIDC) | ✅ | `core/Auth/SSO/` (`EntraIdProvider`, `OidcFlowState`, `IdentityLinker`, `EntraSsoService`, `EntraSettings`), `Routing\SsoRouter` mit Zwischenseite gegen `SameSite=Strict` (in Chromium geprüft: Cookie fehlt beim Rücksprung, ist nach dem Meta-Refresh da), Tab *Einstellungen → SSO* (`security.sso`, Secret verschlüsselt, „Verbindung prüfen“, Rollen-Mapping), Knopf auf `/cms-login`, Verknüpfen/Trennen unter `/member/security`, lokale Anmeldung (Passwort, Passkey, LDAP) abschaltbar (Admin-Notfallzugang), MFA aus `amr`, frische SSO-Anmeldung als Re-Auth beim Hinzufügen eines Faktors, Secret-Ablaufhinweis; Handbuch [SSO-ENTRA.md](../../../admin/security/SSO-ENTRA.md); `TESTS/sso-entra` 215 Prüfungen, Gegenprobe (6 eingebaute Fehler: alg-Vorprüfung, nonce, iss exakt, privilegierte Rollen, `xms_edov`, state-Einmalverwendung) schlägt mit 13 Fehlern fehl |
| 2a | AVIF-Derivate | ✅ | `Media\AvifDerivativeService` (Probe-Kodierung → `media_capabilities`, je Bildgröße nur wenn kleiner als WebP, Pixel-Budget 25 MP, Hook `cms_media_derivative_created`), Upload in beiden Upload-Wegen nach WebP, Einstellungen „AVIF zusätzlich erzeugen“/Qualität/Geschwindigkeit, `Media\AvifRegenerationService` (Auftrag `media_avif`, Schritte à 20, Cron) mit Karte unter *Medien-Werkzeuge*, `<picture>`-Quellen in Editor.js-Bild/Galerie/Medien-Text und den `cms-default`-Vorlagen, Lightbox umschließt `<picture>`, Löschen entfernt Größen/WebP/AVIF; Handbuch [MEDIA-TOOLS.md](../../../admin/media/MEDIA-TOOLS.md); `TESTS/media-avif` 87 Prüfungen |
| 2b | Verwendungsnachweis Medien | ✅ | Tabelle `media_usage` (Schema v26), `Media\MediaUsageIndex` mit `MediaPathNormalizer` (eigene Domains, `/uploads/`, `/media-file?path=`, srcset, CSS, Derivate → Original nur wenn vorhanden) und Extraktoren für Beiträge, Seiten, Hub-Sites, Downloads, Formulare, Design (`cms_register_media_reference_extractors`), Hooks für Speichern/Löschen (neu: `cms_after_form_save`, `cms_form_deleted`, `cms_theme_customizer_saved`), Neuaufbau als Auftrag `media_usage` unter *Medien-Werkzeuge* mit Bereinigung; Mediathek-Spalte, Filter „ungenutzt“ und Papierkorb-Löschwarnung über `MediaUsageService::buildUsageMap()`, sobald der Index aufgebaut ist; `TESTS/media-usage` 89 Prüfungen, Gegenprobe (6 eingebaute Fehler) schlägt mit 14 Fehlern fehl |
| 3a | Vier-Augen-Prinzip (`pending`) | ✅ | `Content\ContentStatus`, `Content\ContentWorkflow` (Freigaberecht inkl. Einstellung „Veröffentlichung braucht eine zweite Person“ – nötig, weil nur Admins `/admin` erreichen), Durchsetzung in `PostsModule`/`PagesModule::save()` (auch nach den Payload-Filtern, im Autosave und in der Sammelaktion), Einreichen/Freigeben/Zurückgeben mit Verlauf (`content_workflow_events`), In-App- und Mail-Benachrichtigung, Bereich *Freigabe* in beiden Editoren, Filter „Zur Prüfung“, Dashboard-Karte „Ausstehende Prüfungen“, Badges `content_pending_posts`/`content_pending_pages`, Vorschau-Links `/preview/<token>` (`content_preview_links`, Hash, 7 Tage, widerrufbar), Rechte-Migration; Handbuch [REVIEW.md](../../../admin/pages-posts/REVIEW.md); `TESTS/content-workflow` 156 Prüfungen, Gegenprobe (11 eingebaute Fehler) schlägt jedes Mal fehl. Offen: erneute Prüfung bei Änderungen an veröffentlichten Inhalten (A.8) |
| 3b | Wiederverwendbare Blöcke | ⬜ | |
| 3c | Ablauf- und Prüfdaten | ⬜ | |
| 4a | S3-Offsite-Backups | ⬜ | |
| 4b | Ausgehende Webhooks | ⬜ | |
| 5 | Zahlungen & Rechnungen | ⬜ | |
| 6a | CLI | ⬜ | |
| 6b | Alerting | ⬜ | |
| S | Schema v26 (gemeinsam) | 🟡 | Tabellen/Spalten für 1, 2b, 3a und 4 definiert (`FEATURE_TABLES_V26`), Marke folgt mit dem Release; `TESTS/schema-v26`. Offen für das Release: Update-Schritt `capabilities_v26` (u. a. `security.sso`; Admins haben sie bis dahin über die Rolle) |
