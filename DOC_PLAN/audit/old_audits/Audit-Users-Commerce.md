# 365CMS – Audit Users, Member & Commerce

Stand: 2026-04-11  

> **⏱️ Historischer Audit-Snapshot (April 2026).** Aktuelle First-Party-Code-Audits (Stand 3.3.47, 2026-06-10): [../AUDIT_core_2026-06-10.md](../AUDIT_core_2026-06-10.md) · [../AUDIT_admin_2026-06-10.md](../AUDIT_admin_2026-06-10.md) · [../AUDIT_member-includes-views_2026-06-10.md](../AUDIT_member-includes-views_2026-06-10.md).
Zweck: Konsolidierter Audit-Stand für Benutzer, Gruppen/RBAC, Member-Runtime sowie Abo-/Bestelllogik.

## Übernommene Altdateien

- `AdminAudit-Benutzer.md`
- `AdminAudit-Gruppen.md`
- `AdminAudit-Member.md`
- `AdminAudit-Abos.md`
- `AssetAudit-Benutzer.md`
- `AssetAudit-Gruppen.md`
- `AssetAudit-Member.md`
- `AssetAudit-Abos.md`

## Konsolidierter Ist-Stand

- Benutzer-, Gruppen- und Rollenpfade wurden gegen **stale IDs**, **Self-Targeting**, **stille 0-Treffer-Erfolge** und **Bulk-Doppelsubmits** fail-closed nachgeschärft.
- Member-Runtime arbeitet heute hostneutraler, nutzt native Submitter in sicherheitsnahen Flows und spiegelt Dashboard-/Notification-/Quickstart-Settings wirksam bis ins Frontend.
- Abo-/Bestellpfade respektieren deaktivierte Ordering-/Package-Zustände, ziehen Zahlungs-/Steuer-/Legal-Settings näher an die echte Checkout-Runtime und nutzen ein konsolidiertes Admin-Asset statt Inline-Resten.

## Bereichsstatus

| Scope | Aktueller Schwerpunkt | Gesicherter Stand | Offener Rest-Backlog |
|---|---|---|---|
| Benutzer | Listen, Edit, Bulk, Fehlerreport-Kontexte | stale Edit-/Bulk-IDs, Self-Target-Bulk-Blocker, submit-gesperrte Aktionen | Weitere Entflechtung von `UsersModule` und Edit-View |
| Gruppen & RBAC | Gruppen-CRUD, Rollen, Capabilities | transaktionale Deletes, stale Slug-/ID-Verträge, sauberere Modale und Delete-Dispatches | Weitere Aufteilung von Rollen-/Capability-Orchestrierung |
| Member | Dashboard, Notifications, Profile, Security, Media | hostneutrale Member-Routen, native Passkey-Submits, ausgelagerte Plugin-Widget-Assets, wirksame Settings | `class-member-controller.php` bleibt zentraler Großbaustein |
| Abos & Orders | Pakete, Bestellungen, Checkout, Settings | deaktivierte Checkout-Pfade fail-closed, Steuer-/Zahlungs-/Legal-Settings wirksam, inline-freie Admin-Flows | Weitere Reduktion großer Business-Orchestrierung und Checkout-Randfälle |

## Maßgebliche Verträge aus den bisherigen Folge-Batches

- **RBAC-/Mutation-Vertrag:** alte `isAdmin()`-Breitpfade wurden in mehreren Bereichen auf explizite Capabilities zurückgeführt.
- **Bulk-Vertrag:** gemischte stale Auswahlen und Self-Ziele werden nicht mehr als Erfolg behandelt.
- **Member-Runtime-Vertrag:** Login-/Reset-/Passkey-/Notification-/Dashboard-Wechsel bleiben locale- und hostneutral.
- **Commerce-Vertrag:** Paket-/Order-Settings steuern die öffentliche Bestellung wieder real statt nur dekorativ.

## Restprioritäten in dieser Domäne

| Priorität | Hotspot | Warum noch relevant |
|---|---|---|
| kritisch | `member/includes/class-member-controller.php` | Hohe Verantwortungskonzentration für Member-Routing, Runtime, Aktionen und Medienpfade |
| hoch | `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` | Große Settings-Mischung aus Payment, Legal, Visibility und Runtime-Fallbacks |
| hoch | `orders.php` | Öffentlicher Checkout bleibt sensibel für State-, Legal- und Payment-Kombinationen |
| hoch | `CMS/admin/modules/users/UsersModule.php` | Save-/Bulk-/Edit-/Fehlerpfade noch stark verdichtet |

## Nächste sinnvolle Folge-Richtung

1. Member-Controller in stärker getrennte Reader-/Action-/Runtime-Pfade zerlegen.
2. Checkout-/Order- und Subscription-Settings weiter testbar staffeln.
3. RBAC-/Roles-/Groups-Pfade nur noch gesammelt hier dokumentieren.