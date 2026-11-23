# Community: Umsetzungsplan 4.2.0

Dieser Plan ist der nach Code und Changelog abgeglichene Abschlussstand, keine laufende Versprechensliste.

- [x] P1 – Schema v27, Capabilities und Rolle `moderator`
- [x] P2 – Repositories, Zugriffsmodell, Inhalte und Zähler
- [x] P3 – Rendering, XSS-Schutz, Spamfilter, Captcha und Entwürfe
- [x] P4 – Moderation, Meldungen, Sperren, Benachrichtigungen und Ränge
- [x] P5 – öffentliche Routen, Views, API und Mitgliederbereich
- [x] P6 – Anhänge, Suche, Sitemap und Medienreferenzen
- [x] P7 – Admin-Struktur, Einstellungen, Rechte, Warteschlange und Papierkorb
- [x] P8 – Datenschutz, Löschfristen, Export und Löschung
- [x] P9 – Barrierefreiheit, Performancebudgets, SEO und Browserprüfungen

## Freigabekriterien

- [x] Community-Routen und UI sind im Code vorhanden.
- [x] Datenbankmarker ist `v27`; Snapshot und Upgradepfade sind testbar.
- [x] CSP-/Trusted-Types-Pfade vermeiden Inline-Skripte.
- [x] Schreib-, Upload-, Moderations- und Datenschutzpfade haben eigene Tests.
- [x] `run-all.php` entdeckt die Suiten dynamisch; es gibt keine dokumentierte feste Suitenanzahl.

