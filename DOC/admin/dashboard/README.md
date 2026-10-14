# 365CMS – Projektdokumentation | Abschnitt: Admin – Dashboard

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

This folder documents the admin start page `/admin` (sidebar entry *Dashboard*, section *Core system*). See [DASHBOARD.md](DASHBOARD.md).

## Deutsch

| Thema | Dokument |
|---|---|
| Aufbau, Widgets, Favoriten, Personalisierung, Datenquellen | [DASHBOARD.md](DASHBOARD.md) |
| Mitglieder-Dashboard (Frontend `/member`) | [../member/README.md](../member/README.md) |
| Navigation und Bereiche des Adminbereichs | [../README.md](../README.md) |

Nicht angemeldete Besucher werden von `/admin` auf die Loginseite (`/cms-login?redirect=…&login_error=session_required`) umgeleitet, angemeldete Nicht-Administratoren auf `/member`.
