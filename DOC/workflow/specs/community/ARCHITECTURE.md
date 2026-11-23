# Community: Architektur

## Schichten

1. **Routing/HTTP:** `CMS/core/Services/Community/Http/` verarbeitet öffentliche Seiten, Schreibwege, Anhänge und JSON-API.
2. **Anwendung:** `Service/` enthält Zugriff, Inhalte, Moderation, Benachrichtigung, Upload, Suche und Profile.
3. **Daten:** `Repository/` kapselt SQL und Keyset-Paging.
4. **Unterstützung:** `Support/` enthält Capabilities, Einstellungen, Renderer, Slugs, URLs, Transaktionen und den Request-Lesescope.
5. **Integration:** `CommunityModule`, Feature-Hooks, Mitgliederbereich, Trash, Legal/DSGVO und Admin-Katalog binden das Modul in den Core ein.

## Laufzeitgrenzen

- Schreibwege führen über `CommunityAccess`, `WriteGate` und `ContentGuard`.
- Einträge werden nur bei aktivem Modul bereitgestellt.
- Öffentliche Ausgabe verwendet Core-Views und lokale Assets; kein externes CDN ist Teil des Moduls.
- SQL-Pagination verwendet Cursor/Keyset statt datenabhängigem `OFFSET`.

## Integrationspunkte

- Rollen/Gruppen, Mitglieder- und Benachrichtigungsdienste des Core
- gemeinsamer Purifier und CommonMark-Bundle
- private Speicherung und Mediathekverweise
- `TrashProvider`, Datenaufbewahrung, Suchindex, Sitemap, Mail-Warteschlange
- Admin-Shell und CSP-Nonce-Mechanismen

