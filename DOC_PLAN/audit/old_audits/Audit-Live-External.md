# 365CMS – Audit Live & External Snapshots

Stand: 2026-04-11  

> **⏱️ Historischer Audit-Snapshot (April 2026).** Aktuelle First-Party-Code-Audits (Stand 3.3.47, 2026-06-10): [../AUDIT_core_2026-06-10.md](../AUDIT_core_2026-06-10.md) · [../AUDIT_admin_2026-06-10.md](../AUDIT_admin_2026-06-10.md) · [../AUDIT_member-includes-views_2026-06-10.md](../AUDIT_member-includes-views_2026-06-10.md).
Zweck: Konsolidierter Sammelbericht für externe Prüfsichten – Snyk-Code-/SCA-Snapshots und produktive Live-Site-Audits.

## Übernommene Altdateien

- `Snyk_Audit_04042026.md`
- `LiveAudit_365CMS.md`
- `PHINIT-LIVE-AUDIT-2026-04-03.md`
- `PHINIT-LIVE-LOCALIZATION-2026-04-03.md`
- `PHINIT-LIVE-SEARCH-ARCHIVE-2026-04-03.md`

## 1) Snyk-Snapshot – Repository `365CMS.DE`

### Kernergebnis

| Sicht | Status | Aussage |
|---|---|---|
| Snyk Code / Gesamt | offen | Restfunde konzentrieren sich überwiegend auf gebündelte Vendor-/Bundle-Fläche |
| First-Party / Runtime | bereinigt | Im dokumentierten Nachscan blieben keine verwertbaren Nicht-Vendor-Funde mehr übrig |
| SCA / Manifeste | sauber | Für die erkannten Composer-Manifeste wurden 0 verwertbare SCA-Funde dokumentiert |

### Maßgebliche Restflächen

- `dompdf`-Bundle
- `translation-status` bzw. gebündelte Fremdtools
- weitere ausgelieferte Vendor-Komponenten außerhalb des eigentlichen First-Party-Cores

### Konsequenz

- First-Party-Remediation bleibt gut kontrollierbar.
- Der weitere Hebel liegt weniger im Eigen-Code als in **gekapselter, aktualisierter oder ersetzter Bundle-Fläche**.

## 2) Live-Audit – `https://365cms.de/`

### Kernergebnis

| Bereich | Befund |
|---|---|
| Erreichbarkeit | Startseite, Login, Registrierung, Passwort-Reset und Blog waren grundsätzlich erreichbar |
| Kritische Vertrauensziele | aktiv verlinkte Rechtsziele wie `/impressum`, `/datenschutz` und `/agb` liefen im dokumentierten Snapshot in 404 |
| Release-Konsistenz | sichtbarer Live-Stand wich vom lokalen Repo-/Dokustand ab |

### Maßgebliche Wirkung

- Die produktive Site war nutzbar, aber in **Legal-/Trust-Signalen** klar unter dem dokumentierten Code-Stand.
- Der Live-Audit ist daher kein Core-Code-Urteil, sondern ein **Produktions-Snapshot mit Fokus auf sichtbare Pflichtpfade**.

## 3) Live-Audit – `https://phinit.de/`

### Kernergebnis

| Bereich | Befund |
|---|---|
| Localization | EN-Routen enthielten teils weiterhin deutsche Inhalte oder deutsche Shell-Bausteine |
| Routing | Sprachwechsler zeigten auf inkonsistente oder nicht existente Gegenslugs |
| Archive | DE- und EN-Archive listeten Sprachvarianten teils gemischt statt sprachrein |
| Suche | Exakte Fachqueries streuten noch zu breit |
| EN Legal | EN-Impressum, EN-Datenschutz und EN-Kontakt waren im dokumentierten Snapshot nicht konsistent ausgeliefert |

### Verdichtete Teilbefunde

- **Localization/UI-Mix:** Kommentartexte, Footer, CTA-Blöcke und Bereichstitel waren auf EN-Seiten teils noch deutsch.
- **Archive & Search:** Sprachtrennung in Archiven und Relevanz für präzise Queries blieben sichtbar unruhig.
- **Canonicality:** Rechtstexte und Sprachrouten deuteten auf Duplicate- bzw. Fallback-Probleme.

## Priorisierte externe Folgeschritte

1. **Vendor-/Bundle-Fläche strategisch aktualisieren oder kapseln** (Snyk-Fokus).
2. **Sichtbar verlinkte Legal-Ziele auf produktiven Sites immer zuerst schließen**.
3. **Sprachrouting, Gegenslug-Mapping und sprachreine Archive** in produktiven Mehrsprachen-Deployments systematisch prüfen.
4. **Externe Snapshots nur als Abgleich** nutzen – nicht als führende Quelle für lokale Runtime-Verträge.

## Einordnung

Diese Sammeldatei bleibt die einzige Audit-Referenz für:

- Snyk-/SAST-/SCA-Snapshots
- Live-Audits produktiver Sites
- mehrsprachige Produktionsbefunde außerhalb des lokalen Repositories

Alle lokalen Runtime-, Admin- und Asset-Fixes bleiben dagegen in den domänenspezifischen Sammel-Audits dokumentiert.