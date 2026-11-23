# 365CMS 4.4: Sicherheits- und Datenschutzvertrag

> **Stand:** 2026-11-23

## Custom Fields und Typen

- Definitionen sind Daten, keine PHP-Konfiguration.
- Erlaubte Feldtypen und Validatoren stehen im Code; Admin-Daten wählen nur
  registrierte IDs.
- Feldschlüssel sind ascii-begrenzt, eindeutig und gegen Überschreiben von
  Core-Spalten geschützt.
- Rich-Text wird im bestehenden Purifier-Profil verarbeitet; URLs werden mit den
  vorhandenen HTML-/URL-Prüfungen validiert.
- Beziehungen zu Benutzern, Medien oder Inhalten lösen über registrierte Services
  auf; das Feld enthält keine beliebige Klassen-/Methodenreferenz.
- Feldwerte unterliegen Export, Löschung, Retention und Papierkorb wie der
  Elterninhalt.

## Übersetzungen

- Locale wird normalisiert und gegen aktivierte Locale geprüft.
- Slug und Route sind je Site/Typ/Locale eindeutig.
- Fallback kann Inhalte zeigen, aber muss im SEO- und UI-Kontext eindeutig sein.
- DSGVO-Export enthält alle Sprachfassungen; Löschung betrifft alle Sprachzeilen
  derselben Entität.
- KI-Übersetzungsanfragen enthalten nur autorisierte Inhaltsdaten und dürfen keinen
  Prompt/Provider-Key in Logs oder OpenAPI materialisieren.

## Live Preview

- POST plus CSRF; nur Benutzer mit Bearbeitungsrecht für den konkreten Inhalt.
- Preview-Zeitlimit, Body-Limit, Block-Limit und Rate-Limit.
- Server rendert im Preview-Modus, ohne Speichern, Revision oder Content-Hooks.
- Iframe ohne Scripts/Formulare/Popups/Top-Navigation.
- Kein `srcdoc`; HTML wird über die sichere Render-Pipeline erzeugt.
- CSP `frame-src 'self'` und Trusted Types bleiben erhalten; keine `unsafe-inline`-
  Ausnahme.
- Externe Bilder, Embeds und Ausführung werden in Preview wie in der produktiven
  Ausgabe behandelt oder explizit blockiert.

## Managed API

### Secret-Modell

- 128 Bit oder mehr kryptografische Zufälligkeit;
- Secret nur bei Erstellung/Rotation sichtbar;
- Speicherung nur als Passwort-Hash/KDF plus nichtgeheimer Lookup-Präfix;
- konstante Antwort für unbekannten Client und falsches Secret;
- Ablauf, Widerruf und Rotation ohne Löschung von Auditspuren;
- keine Secrets in URL, OpenAPI, Export, Logs oder Fehlerseite.

### Scopes und Identität

- Scopes werden auf vorhandene Capabilities und Typen abgebildet;
- API-Client ist kein CMS-Benutzer und kann keine Session/MFA-Umgehung auslösen;
- Write-Scope impliziert niemals Admin- oder Benutzerverwaltung;
- Inhaltsstatus und Sichtbarkeit werden serverseitig erneut geprüft;
- private Inhalte bleiben geschützt, auch wenn ein Content-Endpunkt existiert.

### Token-/Key-Missbrauch

- Client Credentials liefert kurzlebige Access-Tokens;
- Refresh außerhalb des Browser-Flows ist vorerst nicht vorgesehen;
- `jti`, Ablauf, Issuer, Audience und Scope werden geprüft;
- Rate-Limit pro Client und zusätzlich pro IP;
- Brute-Force und widerrufene Clients werden alarmierbar;
- kontrolliertes `Idempotency-Key` für Schreibendpunkte.

## OpenAPI

- nur öffentliche, freigegebene Schemas und Scopes;
- keine internen Tabellen-, Dienst- oder Secretnamen;
- Route-Annotationen können keine SQL-, Datei- oder PHP-Callback-Definition aus
  Konfiguration nachladen;
- `openapi.json` ist gecacht, versioniert und enthält Sicherheitsanforderungen.

## Pflichttests

- Rechte-Matrix je Feld/Typ/Sprache/API-Scope;
- XSS-/URL-/Schema-Injection-Korpus für Custom Fields;
- CSP-/DOM-/iframe-Tests für Live Preview;
- Token, Secret, Ablauf, Rotation, Revoke und Rate-Limits;
- DSGVO-Auskunft/Löschung über Custom Fields, Sprachzeilen und Integrationslogs;
- Upgrade und Rückfall bei beschädigten JSON-/Translationsdaten.
