# 365CMS – Projektdokumentation | Abschnitt: Admin – Gruppen

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/groups` | **Capability:** `manage_users` | **CSRF-Aktion:** `admin_groups`

## English (summary)

User groups bundle members and can be linked to a subscription plan. Managed at `/admin/groups` (`CMS/admin/groups.php` → `CMS/admin/modules/users/GroupsModule.php` → `CMS/admin/views/users/groups.php`). Tables: `cms_user_groups`, `cms_user_group_members`. Actions: `save`, `delete`, `bulk` (`activate`, `deactivate`, `delete`, `set_plan`, `clear_plan`).

## Deutsch

### Zweck

Gruppen fassen Benutzer zusammen (z. B. „Kunden Firma X“, „Beta-Tester“). Eine Gruppe kann einem **Abo-Paket** (`plan_id` → `cms_subscription_plans`) zugeordnet werden; Mitglieder der Gruppe erhalten dann die Rechte und Limits dieses Pakets (siehe [../subscription/SUBSCRIPTION-SYSTEM.md](../subscription/SUBSCRIPTION-SYSTEM.md)).

### Felder

| Feld | Hinweis |
|---|---|
| Name, Slug | Slug wird aus dem Namen erzeugt, eindeutig |
| Beschreibung | Freitext |
| Paket (`plan_id`) | Nur existierende Pakete; leer = kein Paket |
| Aktiv (`is_active`) | Inaktive Gruppen vergeben keine Paketrechte |
| Mitglieder | Zuordnung von Benutzern (`cms_user_group_members`) |

### Übersicht

Die Seite zeigt je Gruppe Mitgliederzahl, verknüpftes Paket und Status sowie Kennzahlen zu Paketen und aktiven Abos der Mitglieder.

### Aktionen

| `action` / `bulk_action` | Wirkung |
|---|---|
| `save` | Gruppe anlegen oder aktualisieren |
| `delete` | Gruppe löschen (Mitgliedschaften werden entfernt, Benutzer bleiben) |
| `bulk` → `activate` / `deactivate` | Status setzen |
| `bulk` → `set_plan` / `clear_plan` | Paket zuweisen bzw. entfernen |
| `bulk` → `delete` | Gruppen löschen |

### Verwandte Dokumente

[USERS.md](USERS.md) · [RBAC.md](RBAC.md) · [../subscription/PACKAGES.md](../subscription/PACKAGES.md)
