# 365CMS 4.3: Vorzu leistende Entscheidungen

> **Stand:** 2026-11-23 | **Regel:** offene Entscheidungen blockieren die jeweilige Implementierungswelle

| ID | Entscheidung | Erforderlicher Beschluss | Blockiert |
|---|---|---|---|
| D-01 | CLI-Vertrag | Unterbefehle, Exitcodes, JSON/Text, Web-SAPI-Sperre, Audit und Rechte | V43-01 |
| D-02 | Alert-Modell | Ereignisnamen, Schwere, Deduplizierung, Cooldown, Kanal-Rechte, Geheimnisse im Kontext | V43-02 |
| D-03 | Config-Snapshots | erlaubte Einstellungen, Dateiformat, Secret-Behandlung, Signierung, Import- und Rollback-Regeln | V43-03 |
| D-04 | Felddatenspeicher | JSON, normalisierte Tabellen oder gemischt; Abfrage- und Indexanforderungen | V43-06/07 |
| D-05 | Typ-/Felddefinition | ausschließlich UI, ausschließlich Datei, oder kontrollierter Merge; Besitzer und Konfliktregeln | V43-08 |
| D-06 | eigene Typen im Theme/API | URL-Präfix, Template-Hierarchie, Controller-Override, SEO, Preview und REST-Schema | V43-07/09 |
| D-07 | Sprachidentität | gemeinsame Inhalts-ID mit sprachlichen Zeilen vs. Sprachobjekt; Fallback und Status | V43-11 |
| D-08 | Sprach-Migration | feste Spalten als Quelle, Datenvalidierung, Reversibilität, Umgang mit Partials | V43-11/15 |
| D-09 | Integrationsschlüssel | Schlüsselart, Scope, Ablauf, Rotation, Bindung an Konto/Site, Letztnutzung | V43-16 |
| D-10 | Cache-Tags | Tag-Namensraum, Transitivität, Backend-Vertrag, APCu-/Datei-/CDN-Verhalten | V43-20 |
| D-11 | Medienspeicher | Treibervertrag, URL-Signierung, Migration, Konsistenz, Löschung, private/public Domain | V43-23 |
| D-12 | Community P2 | Reihenfolge, Warnpunkte, Digests, Online-Status, Poll-Status, Datenschutz und Retention | V43-26 bis V43-32 |
| D-13 | Automations-Flows | ob überhaupt; zulässige Trigger/Aktionen, Sandbox, Audit, Berechtigung und Schleifen | V43-19 |
| D-14 | OpenAPI/Routequelle | Annotation, Registry oder Generator; Auth-Scope und Fehlerformat | V43-17 |

## Entscheidungsnachweis

Jede Entscheidung wird mit fünf Feldern geschlossen:

1. bestätigte Option;
2. verworfene Alternativen und Risiko;
3. Migrations- und Rückwärtskompatibilitätsfolge;
4. betroffene Tests und Dokumente;
5. Datum und Verantwortliche.

Marketing-/Vergleichsargumente allein schließen keine Entscheidung.
