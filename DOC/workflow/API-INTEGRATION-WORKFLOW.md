# Workflow: API integrieren

> **Stand:** 2026-11-23 | **Version:** 4.2.0

1. Öffentliche, Admin- und Community-Endpunkte aus [../core/API-REFERENCE.md](../core/API-REFERENCE.md) beziehungsweise der Route-Registrierung auswählen.
2. Authentifizierung, Berechtigung und CSRF-Anforderung je Endpunkt beachten. Schreibende Community-Endpunkte benötigen zusätzlich den einmaligen Formularschutz.
3. JSON-Fehlercodes und HTTP-Status nicht anhand des Transporttexts, sondern anhand des dokumentierten Status behandeln.
4. Eigene Routen in der zuständigen Routergruppe registrieren; Router-Code nicht als Auslagerung permanenter Geschäftslogik verwenden.
5. Mit Fake-Transporten oder isolierten Daten testen. Community-API-Verträge werden durch `TESTS/community-api/` abgedeckt.
6. Keine Tokens, geheimen Schlüssel oder Anbieterantworten protokollieren.

