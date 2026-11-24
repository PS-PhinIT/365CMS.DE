# AP-14 – Barrierefreiheit und Oberfläche (Version 4.2.14)

**Stand 24.11.2026.** Geprüft wurde `main` nach `12701b8` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung, Skriptsuchen und Funktionsaufrufe
ohne Datenbank und ohne Browser. Maßstab: [Audit-Katalog, AP-14](../AUDIT-KATALOG-4.2.0.md#ap-14-barrierefreiheit-und-oberflaeche). Der Bericht betrifft nur AP-14; er ist keine Freigabe des
gesamten Bereichs. Es wurden keine Browser, Konten oder Produktivsysteme verwendet. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Kein Befund, aber kaum Nachweis: Barrierefreiheit lässt sich ohne laufende Seite und Browser nur oberflächlich prüfen. Alle Kernpunkte stehen offen.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 14.1 axe-core | offen | Nicht geprüft (braucht laufende Seiten mit Datenbank). | Start, Beitrag, Login, Registrierung, Mitglied, Admin-Kernseiten. |
| 14.2 Tastatur | offen | Skriptsuche: kein `tabindex` größer 0 in Vorlagen und Skripten. | Reihenfolge, Fokusführung der Dialoge, Alternative zum Ziehen und Ablegen. |
| 14.3 Formulare | offen | Nicht geprüft. | Labels, `role=alert`, Pflichtfelder, Zeitlimit des Captchas. |
| 14.4 Kontrast, Reflow | offen | Skriptsuche: 11 CSS-Regeln mit `outline: none` (z. B. `themes/cms-default/style.css:834, 1362, 2091, 2199`, `assets/css/main.css:107`); Eingabefelder ersetzen den Fokusrahmen zum Teil durch eine Randfarbe. Ob das sichtbar genug ist, zeigt nur der Browser. | Kontrastmessung, 320 px, 200 % Zoom. |
| 14.5 Sprache | ja (Teil) | Kein `<html>` ohne `lang` in Themes, Ansichten, Mitgliederbereich und Admin-Kopf (Skriptsuche); die geprüften `<img>` in Vorlagen tragen ein `alt` (leer bei Zierbildern). | Feste Texte im Code, Datums- und Zahlenformate. |

## Abweichungen

Keine.

## Testlauf und Übergabe

Skriptsuchen. Die Suiten `community-a11y` und `community-lighthouse` decken die Community-Seiten ab und laufen grün.

**Offen:** alle Punkte mit axe-core und Tastaturprüfung im Browser (Playwright ist vorhanden, aber ohne Datenbank startet keine Seite); Fokusdarstellung der `outline: none`-Regeln.
