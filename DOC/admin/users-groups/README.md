# 365CMS – Projektdokumentation | Abschnitt: Admin – Benutzer & Gruppen

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

This folder documents the sidebar group **Users & Groups** (*Benutzer & Gruppen*): user accounts, groups, roles/capabilities and authentication settings. All pages require capability `manage_users` (and, like the whole admin area, role `admin`).

## Deutsch

| Menüpunkt | Route | Dokument |
|---|---|---|
| Benutzer | `/admin/users` | [USERS.md](USERS.md) |
| Gruppen | `/admin/groups` | [GROUPS.md](GROUPS.md) |
| Rollen & Rechte | `/admin/roles` | [RBAC.md](RBAC.md) |
| Einstellungen | `/admin/user-settings` | [AUTH-SETTINGS.md](AUTH-SETTINGS.md) |

### Zusammenhänge

```text
Benutzer ──(role)──► Rolle ──► Capabilities (Standard + cms_role_permissions)
    │
    └──(cms_user_group_members)──► Gruppe ──(plan_id)──► Abo-Paket
    │
    └──(cms_user_subscriptions)──► direktes Abo
```

- **Rollen** bestimmen, *was* jemand darf (Capabilities).
- **Gruppen** und **Abos** bestimmen, *welche Pakete/Limits* gelten.
- **Auth-Einstellungen** bestimmen, *wie* sich jemand anmeldet und registriert.

### Verwandte Bereiche

- Mitglieder-Dashboard konfigurieren: [../member/README.md](../member/README.md)
- Abo-Pakete und Bestellungen: [../subscription/README.md](../subscription/README.md)
- DSGVO-Auskunft und Löschung: [../legal/README.md](../legal/README.md)
- Member-Bereich aus Sicht des Mitglieds: [../../member/README.md](../../member/README.md)
