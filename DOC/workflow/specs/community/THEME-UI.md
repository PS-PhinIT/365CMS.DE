# Community: Theme, Oberfläche und SEO

## Oberfläche

- [x] Views liegen in `CMS/views/community/` und werden in das aktive Theme eingebettet.
- [x] Lokale Assets: `community.css`, `community.js`, `compose.js`, `editor.js`, `md-bridge.js`.
- [x] Inline-Skripte werden vermieden; das Admin-/Public-Shell-CSP mit Nonce und Trusted Types bleibt wirksam.
- [x] Mobile und Tastatur bedienen Listen, Formulare, Dialoge und Reaktionen.
- [x] Hell-/Dunkel-Kontraste, sichtbarer Fokus und responsive Reflow sind durch `community-a11y` geprüft.

## SEO

- [x] Kanonische Adressen und 301 bei geändertem Themen-Slug
- [x] Regeln für `noindex` auf nicht indexierbaren Ansichten
- [x] Beschreibung und strukturierte Daten auf geeigneten Themenseiten
- [x] Community-Einträge in Suche und Sitemap

Theme-Verträge außerhalb des mitgelieferten `cms-default` werden durch das jeweilige Theme geliefert und können nicht aus diesem Repository abgeleitet werden.

