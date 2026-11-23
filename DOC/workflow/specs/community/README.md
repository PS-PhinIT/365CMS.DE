# Community-Modul 4.2.0

> **Stand:** 2026-11-23 | **Produkt:** 4.2.0 | **Schema:** v27 | **Status:** implementiert und freigegeben

Das abschaltbare Kernmodul `community` ist in 4.2.0 ausgeliefert. Verbindliche Grundlagen sind `CMS/`, `Changelog.md` und `CMS/core/SchemaManager.php`; diese Spezifikation fasst den belegten Lieferstand zusammen.

## Umgesetzter Umfang

- [x] Schema v27 mit 28 `community_*`-Tabellen und Rechte-Schritt `capabilities_v27`
- [x] Kategorien, Foren, Themen, Beiträge, Zitate, Lesestatus und Keyset-Pagination
- [x] Öffentliche Seiten, mehrsprachige Routen, Suche, Sitemap und SEO
- [x] Visueller SunEditor, Markdown-Speicherung, Vorschau und lokale/serverseitige Entwürfe
- [x] Moderation, Meldungen, Sperren, Ränge, Reaktionen, Abos und Benachrichtigungen
- [x] Anhänge mit MIME-/Headerprüfung und geschützter Auslieferung
- [x] Mitgliederbereich, öffentliche Profile und Signaturen
- [x] Admin-Struktur, Rechte, Warteschlange, Berichte, Sanktionen, Ränge, Einstellungen und Themen
- [x] DSGVO-Auskunft, Löschung, Aufbewahrung und Papierkorb-Anbindung
- [x] Captcha, XSS-/CSRF-Schutz, Rate-Limits und Zugriffsprüfung

## Spezifikationen

| Dokument | Inhalt |
|---|---|
| [FEATURES.md](FEATURES.md) | Code-verifizierte Funktionsliste |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Dienste, HTTP-Schicht und Integrationen |
| [DATA-MODEL.md](DATA-MODEL.md) | Schema v27 |
| [PERMISSIONS-MODERATION.md](PERMISSIONS-MODERATION.md) | Capabilities, Freigaben und Moderation |
| [WORKFLOWS.md](WORKFLOWS.md) | Benutzer-, Moderations- und Admin-Abläufe |
| [SECURITY-PRIVACY.md](SECURITY-PRIVACY.md) | Schutz- und Datenschutzmaßnahmen |
| [THEME-UI.md](THEME-UI.md) | Vorlagen, Assets, CSP und SEO |
| [MIGRATION-CMS-FORUM.md](MIGRATION-CMS-FORUM.md) | Ablösung des früheren Plugins ohne Datenübernahme |
| [IMPLEMENTATION-PLAN.md](IMPLEMENTATION-PLAN.md) | Erledigte Lieferpakete |
| [ROADMAP.md](ROADMAP.md) | Abschluss-Checkliste |
| [HANDOFF-PR2-PR5.md](HANDOFF-PR2-PR5.md) | Übergabe in den ausgelieferten Stand |
| [TEST-PLAN.md](TEST-PLAN.md) | Test-Discovery und Prüfumfang |

## Offene Punkte

Es ist kein funktionaler Community-Lieferpunkt als offen belegt. Datenbank-/Browserprüfungen mit externen Werkzeugen hängen von ihrer jeweiligen Umgebung ab und gelten nicht als fehlende Produktimplementierung.

