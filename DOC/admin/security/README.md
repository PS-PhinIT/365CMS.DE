# 365CMS – Projektdokumentation | Abschnitt: Admin – Sicherheit

> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

This folder documents the admin area **Security** (core module `security`): anti-spam, application firewall and security audit. All pages require `manage_settings`.

## Deutsch

| Menüpunkt | Route | Dokument |
|---|---|---|
| Anti-Spam | `/admin/antispam` | [ANTISPAM.md](ANTISPAM.md) |
| Firewall | `/admin/firewall` | [FIREWALL.md](FIREWALL.md) |
| Audit | `/admin/security-audit` | [SECURITY-AUDIT.md](SECURITY-AUDIT.md) |
| Login & Sperren (Einstellungen) | `/admin/settings?tab=security` | [LOGIN-THRESHOLDS.md](LOGIN-THRESHOLDS.md) |
| SSO mit Microsoft Entra ID (seit 4.1.0) | `/admin/settings?tab=sso` | [SSO-ENTRA.md](SSO-ENTRA.md) |

### Schutzschichten im Überblick

| Schicht | Komponente |
|---|---|
| Transport | HTTPS-Erzwingung (`CMS_HTTPS_REDIRECT_STRATEGY`), HSTS |
| Header | CSP mit Nonces, Trusted Types, `X-Content-Type-Options`, Referrer-Policy (`CMS\Security`) |
| Anfrage | Firewall-Regeln und Rate-Limit (`SecurityRuntimeService`) |
| Anmeldung | Login-Sperre nach Fehlversuchen, MFA, Passkeys, Passwortrichtlinie ([../users-groups/AUTH-SETTINGS.md](../users-groups/AUTH-SETTINGS.md)), Entra-ID-SSO ([SSO-ENTRA.md](SSO-ENTRA.md)) |
| Formulare | CSRF-Token je Aktion, Anti-Spam |
| Daten | Prepared Statements, HTMLPurifier für Rich-Text, Upload-Prüfung |
| Nachweis | Audit-Log, Sicherheits-Log, Sicherheits-Audit |

Weitere Details zum Sicherheitsmodell: [../../core/SECURITY.md](../../core/SECURITY.md).
