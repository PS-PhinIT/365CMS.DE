# Community: Abläufe

## Lesen

- [x] `/forum` listet Kategorien und Foren.
- [x] Gast- und Mitgliederlisten berücksichtigen Zugriff und Leserechte.
- [x] Themen werden per ID und Slug geladen; geänderte Slugs leiten dauerhaft weiter.
- [x] Listen verwenden Keyset-Cursor; „alles gelesen“ aktualisiert Lesestatus idempotent.

## Schreiben

- [x] Formularzugriff und Aktion werden vor dem Dienst geprüft.
- [x] CSRF, einmaliger `form_guard`, Captcha und `ContentGuard` schützen den Schreibweg.
- [x] Vorschau rendert denselben Sanitizer wie die gespeicherte Ausgabe.
- [x] Entwürfe werden lokal und serverseitig gepflegt; Anhänge werden nach Freigabe beansprucht.
- [x] Zähler, Suche und Benachrichtigungen werden konsistent aktualisiert.

## Moderieren

- [x] Meldung öffnen, deduplizieren und der Warteschlange zuordnen.
- [x] Maßnahme unter Rechte- und Rangprüfung ausführen.
- [x] Betroffene Nutzer benachrichtigen und Moderationsprotokoll schreiben.
- [x] Gelöschte Inhalte über den zentralen Papierkorb wiederherstellen oder endgültig entfernen.

## Verwalten

- [x] Struktur, Rechte, Einstellungen, Ränge, Berichte, Sanktionen und Themen über die vorhandenen `/admin/community*`-Seiten steuern.

