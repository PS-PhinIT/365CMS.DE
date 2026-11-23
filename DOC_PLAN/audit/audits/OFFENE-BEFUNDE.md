# Audit: Tatsächlich offene Punkte

> **Stand:** 2026-11-23 | **Aktueller Produktstand:** 4.2.0 / Schema v27

Dieses Dokument ist die einzige Kurzsicht auf noch offene Punkte aus den unter
`DOC_PLAN/audit/` archivierten Prüfungen. Ein ungeklärtes Häkchen in einem
historischen Audit zählt **nicht** als zusätzlicher offener Punkt.

| Audit-ID | Status | Einordnung | Nächster Nachweis |
|---|---|---|---|
| OFFEN-27 | offen | Signierte Core-Updatepakete; SHA-256 allein schützt nicht gegen eine kompromittierte Update-Quelle | [v4.2.X 42X-06](../v4.2.X/CATALOG.md#42x-06--signierte-update-pakete) |
| PERF-410-49 | offen, historisch | WXR-Import läuft in einem Request; im 4.1-Audit akzeptiert und kein 4.2.0-Releaseblocker | aktuellen Importpfad reproduzieren, dann in den passenden Patch-/Featurekatalog aufnehmen; siehe [Release-Audit 4.1](RELEASE-AUDIT-4.1.0-2026-11-15.md) |

## Abgeschlossen, nur noch als Evidenz geführt

- OFFEN-01 … OFFEN-19: in den jeweiligen Folge-Releases umgesetzt.
- OFFEN-20 … OFFEN-26: in 4.0.0 umgesetzt und am 2026-11-09 erneut geprüft.
- Sonstige IDs mit Status `✅`: behobene historische Befunde, kein Backlog.
- Ungeprüfte oder nicht mehr vorhandene Begriffe aus `old_audits/`: erst nach einem
  aktuellen Reproduktionsfall wiedereröffnen.

## Releasezuordnung

- 4.2.x: bestätigte Regression, Sicherheitsnacharbeit, Upgrade-/Paketproblem.
- 4.3: CLI-, Alerting- und Betriebsanforderung.
- 4.4: Custom Fields, N-Sprachen, Live Preview oder Managed API.
- Community: explizite P2/P3-Priorisierung.

Auditdateien dokumentieren die Beweislage. Sie übernehmen nicht die Rolle des
Releasekatalogs.
