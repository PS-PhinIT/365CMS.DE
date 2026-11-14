# 365CMS – Projektdokumentation | Abschnitt: Spec 4.1.0 – Fortschritt
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.1.0 | **Übersicht:** [README.md](README.md)

## English (summary)

Checklist of the 4.1.0 work packages. ✅ done (with evidence) · 🟡 in progress · ⬜ open.

## Deutsch

| # | Arbeitspaket | Status | Ergebnis / Nachweis |
|---|---|---|---|
| 0 | Analyse und Spezifikation (dieses Dokument-Set) | ✅ | `DOC/workflow/specs/v4.1/` (14.11.2026) |
| 1a | Passwort-Richtlinie mit Historie und Ablauf | ✅ | `Security\PasswordPolicyService`, Einbindung in Registrierung/Admin/Mitglied/Zurücksetzen, Formular unter *Login & Sperren*, Unicode-Zeichenklassen (Server und Live-Tester), Ablauf-Flag beim Login (Weiche: 1b); `TESTS/password-policy` 53 Prüfungen, Gegenproben (Historie, Filter) schlagen fehl |
| 1b | Rollenbasierter MFA-Zwang | ✅ | `Security\MfaEnforcementService`, Konto-Weiche `Router::enforceAccountGates()` (Passwort-Ablauf vor MFA), Passkeys speichern `user_verified` (Registrierung, nachträglich bei UV-Login), Schutz vor Entfernen des letzten Faktors (`/mfa-disable`, Mitgliederbereich), Formular *MFA-Zwang je Rolle* mit Selbstaussperr-Schutz; `TESTS/mfa-enforcement` 173 Prüfungen, Gegenprobe (Präfix-Allowlist, TOTP-Abschalten) schlägt mit 8 Fehlern fehl |
| 1c | Entra-ID-SSO (OIDC) | ⬜ | |
| 2a | AVIF-Derivate | ⬜ | |
| 2b | Verwendungsnachweis Medien | ⬜ | |
| 3a | Vier-Augen-Prinzip (`pending`) | ⬜ | |
| 3b | Wiederverwendbare Blöcke | ⬜ | |
| 3c | Ablauf- und Prüfdaten | ⬜ | |
| 4a | S3-Offsite-Backups | ⬜ | |
| 4b | Ausgehende Webhooks | ⬜ | |
| 5 | Zahlungen & Rechnungen | ⬜ | |
| 6a | CLI | ⬜ | |
| 6b | Alerting | ⬜ | |
| S | Schema v26 (gemeinsam) | 🟡 | Tabellen/Spalten für 1 und 4 definiert (`FEATURE_TABLES_V26`), Marke folgt mit dem Release; `TESTS/schema-v26` |
