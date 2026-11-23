# 365CMS 4.4: Entscheidungen

| ID | Entscheidung | Empfohlene Richtung | Blockiert |
|---|---|---|---|
| D44-01 | Custom-Field-Speicher für Bestandszeilen | versionsfähiges `custom_fields_json` über typisierten Service; relationale Tabellen für Definitionen | V44-01 |
| D44-02 | Eigene Typen | normalisierte `content_types`/`content_entries`, Provider für bestehende Infrastruktur | V44-01 |
| D44-03 | Feld-zu-Revision-Vertrag | Snapshot in Post/Page-Revision; für Typen eigener Revisionstyp mit derselben Fingerprint-Regel | V44-01 |
| D44-04 | Locale-Identität | kanonische `entity_type + entity_id + locale`, kein neue Entitäts-ID pro Sprache | V44-02 |
| D44-05 | DE/EN-Migration | dual-read/dual-write in 4.4; Altspalten erst nach gesondertem Folge-Release entfernen | V44-02 |
| D44-06 | Site-/Locale-Slug-Eindeutigkeit | Unique je Site, Typ und Locale; Redirects bei Slugwechsel | V44-02 |
| D44-07 | Preview-Iframe | `sandbox="allow-same-origin"` ohne Scripts/Forms/Popups; kein `srcdoc` | V44-03 |
| D44-08 | Preview-Datenschonung | keine Persistenz und kein Save-Hook; kurze serverseitige Lebensdauer | V44-03 |
| D44-09 | API-Auth MVP | Client Credentials + kurzlebige JWT; optional direkter read-only Key | V44-04 |
| D44-10 | API-Schreibzugriff | Architektur vorbereiten, Endpunkte nur nach Idempotency-/CSRF-freier Maschinenauth-Freigabe | V44-04 |
| D44-11 | GraphQL | nicht im 4.4-MVP; nur bei validiertem Clientbedarf als additive Fassade | V44-04 |
| D44-12 | Secret-Speicherung | niemals reversibel; Lookup-Präfix + starker Password-Hash/KDF | V44-04 |
| D44-13 | OpenAPI-Quelle | Route-/Schema-Registry ist Wahrheit; Markdown bleibt Erklärung | V44-04 |

Abweichungen benötigen eine dokumentierte Kompatibilitäts- und Security-Begründung,
nicht nur eine kürzere Implementierung.
