# Community: Rechte und Moderation

## Rechte

- [x] Die sechs `community.*`-Capabilities werden zentral definiert und einmalig durch `capabilities_v27` ausgerollt.
- [x] Systemrolle `moderator`: Mitgliederrechte und `community.moderate`, ohne Admin-Zugang.
- [x] Rechte je Forum berücksichtigen Rolle, Gruppe, Vertrauensstufe, Sperre und Moderationsfenster.
- [x] Admin-Seiten verwenden eigene Capability-Gates; Mitglieds-Moderation verwendet Community-Rechte.

## Moderation

- [x] Freigeben, ablehnen, ausblenden und wiederherstellen
- [x] Verschieben mit Weiterleitung, anheften und Lösung markieren
- [x] Sammelaktionen und Freigabe-Warteschlange
- [x] Meldungen zusammenführen, Schwelle anwenden und erledigen
- [x] Sperren und entsperren mit Rollenprüfung
- [x] Moderationsprotokoll und exakte Zählerbuchung über `CounterBook`

Die Dienste `CommunityAccess`, `ModerationService`, `ReportService` und `BanService` sind die verbindliche Umsetzung.

