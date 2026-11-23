# Community: Sicherheit und Datenschutz

## Sicherheit

- [x] Zentrale Entscheidung über `CommunityAccess::can()`
- [x] CSRF und einmalige Formular-Tokens
- [x] CommonMark plus strikter Purifier statt Roh-HTML
- [x] Wortfilter, Hostregeln, Antispam, Duplikate, Link-/Bildgrenzen und IP-HMAC
- [x] Lokales Captcha: Proof-of-Work, Bild und Rechenaufgabe
- [x] Uploadprüfung nach Endung, MIME, Header und Dekodiergrenzen; private Speicherung
- [x] vorbereitete SQL-Abfragen, Keyset-Paging und begrenzte Listen
- [x] keine externen CDN-/Renderdienste

## Datenschutz

- [x] Konfigurierbare Aufbewahrung für IP-Hashes, Logs und Entwürfe
- [x] JSON-Export des Community-Teilbestands
- [x] Konto-Löschmodus `anonymize` oder Inhalte löschen
- [x] Automatischer Rechtsseitenbaustein „8a. Forum (Community)“
- [x] Papierkorb- und endgültige Löschung beziehen Anhänge und Suchdaten ein

Die Tests `community-xss`, `community-spam`, `community-privacy` und `community-uploads` bilden die zentralen Gegenproben ab.

