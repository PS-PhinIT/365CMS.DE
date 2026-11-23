## 1. Webbaukasten – Systemmodul als Theme-Alternative

### 1.1 Modul-Grundlage

- [ ] **Webbaukasten als Systemmodul** (`modules/webbuilder/`)
  - [ ] Aktivierbar/deaktivierbar über Admin → System → Module
  - [ ] Mutually exclusive zur Theme-Engine: aktiviert = Theme-Untermenüs ausgeblendet, Baukasten-Untermenüs eingeblendet
  - [ ] Migrationspfad „Theme → Baukasten": Snapshot des aktiven Themes (Header, Footer, Farben, Fonts) als Start-Layout importieren
  - [ ] Migrationspfad „Baukasten → Theme": Warnung plus Export der Baukasten-Konfiguration als JSON, kein Datenverlust
  - [ ] Capability `webbuilder.manage` im RBAC, Default nur für `admin` und `editor`
  - [ ] Feature-Flag in `config/system.php` plus DB-Eintrag in `cms_settings` (`webbuilder_enabled`)
  - [ ] Einheitlicher Hook-Point: `cms.theme.render` vs. `cms.webbuilder.render`, Router entscheidet
  - [ ] Theme-Engine bleibt im passiven Zustand, damit Plugin-Hooks für Theme-Slots nicht brechen

### 1.2 Layout-Engine

- [ ] **Layout-Bereiche (Regions)**
  - [ ] Header, Content, Footer als Pflichtbereiche
  - [ ] Optionale Bereiche: Topbar, Sidebar-Left, Sidebar-Right, Hero, Pre-Footer
  - [ ] Per-Page-Override: einzelne Seiten überschreiben oder blenden Bereiche aus
  - [ ] Globale Layouts vs. seitenspezifische Layouts mit klarer Vererbung
  - [ ] Sticky-Header und Sticky-Footer als Toggle
- [ ] **Grid- und Container-System**
  - [ ] 12-Spalten-Grid auf Tabler-Basis, kompatibel zur bestehenden Admin-Optik
  - [ ] Container-Varianten: full-width, container, container-fluid, narrow für Lesetexte
  - [ ] Responsive Breakpoints: xs, sm, md, lg, xl, xxl mit individueller Spaltenzahl pro Breakpoint
  - [ ] Row/Column-Editor mit Drag-and-Drop, Vanilla JS, kein jQuery
  - [ ] Vertikales Stacking-Verhalten pro Breakpoint konfigurierbar
- [ ] **Sektionen (Section-Blocks)**
  - [ ] Wiederverwendbare Sektionen als Bibliothek speichern, versioniert
  - [ ] Section-Templates: Hero, Feature-Grid, Testimonials, CTA, Logo-Wall, FAQ, Pricing
  - [ ] Hintergrund pro Sektion: Farbe, Verlauf, Bild, Video, Pattern
  - [ ] Padding/Margin als Spacing-Tokens (sm, md, lg, xl) statt freier Pixel-Werte

### 1.3 Design-Tokens

- [ ] **Farb-System**
  - [ ] Primär, Sekundär, Akzent, Erfolg, Warnung, Fehler, Info
  - [ ] Neutralfarben: Background, Surface, Border, Text-Primary, Text-Secondary, Text-Muted
  - [ ] Light-Mode und Dark-Mode parallel pflegbar
  - [ ] Auto-Kontrast-Prüfung WCAG AA/AAA im Color-Picker
  - [ ] Export als CSS Custom Properties (`--cms-color-primary`)
  - [ ] Farbpaletten-Presets, z.B. „Schwarzwald", „Tech-Blau", „Warm-Neutral"
- [ ] **Typografie**
  - [ ] Font-Stack: System-Fonts, Google Fonts, Custom Fonts via Upload
  - [ ] Headings (h1–h6) und Body separat konfigurierbar
  - [ ] Font-Size-Scale (modular scale, z.B. 1.25)
  - [ ] Line-Height, Letter-Spacing, Font-Weight pro Level
  - [ ] Font-Loading-Strategie: preload, font-display: swap
  - [ ] Anbindung an bestehenden Font Manager (`/admin/font-manager`)
- [ ] **Spacing und Radien**
  - [ ] Spacing-Scale auf 4px-Basis (4, 8, 12, 16, 24, 32, 48, 64, 96)
  - [ ] Border-Radius-Token: none, sm, md, lg, full
  - [ ] Shadow-Token: none, sm, md, lg, xl
- [ ] **Token-Export und Live-Preview**
  - [ ] Live-Preview im Admin via iFrame
  - [ ] Tokens werden als generierte `assets/cache/tokens.css` ausgespielt
  - [ ] Cache-Invalidierung über Hash im Dateinamen

### 1.4 Media: Logos, Bilder, Videos

- [ ] **Logo-Verwaltung**
  - [ ] Logo Light, Logo Dark, Logo Mobile, Favicon, Apple-Touch-Icon, OG-Default
  - [ ] SVG-Sanitizer (entfernt `<script>`, `onerror` etc.)
  - [ ] Automatische Größen-Generierung für Favicon (16, 32, 48, 180, 192, 512)
- [ ] **Media-Library-Integration**
  - [ ] Bestehende 365CMS-Mediathek wiederverwenden, nicht doppelt bauen
  - [ ] Bildpicker mit Vorschau, Alt-Text-Pflichtfeld, Caption optional
  - [ ] Responsive Images: automatische Srcset-Generierung (WebP, AVIF, JPEG-Fallback)
  - [ ] Lazy Loading per Default (`loading="lazy"`, `decoding="async"`)
  - [ ] Lightbox-Option pro Bild (über bestehende `cms-lightbox.js`-Roadmap)
- [ ] **Hintergrund-Bilder und Videos**
  - [ ] Position, Repeat, Size, Attachment inklusive parallax-light
  - [ ] Overlay-Farbe und Opacity über Bildern
  - [ ] Video-Background mit Poster, autoplay/muted/loop, mobile-Fallback auf Bild

### 1.5 Content-Blöcke (Editor.js)

- [ ] **Editor.js als Block-Engine im Frontend-Builder**
  - [ ] Bestehende Editor.js-Integration aus 365CMS wiederverwenden
  - [ ] Custom Blocks: Hero, Feature-Card, CTA-Banner, Pricing-Table, Team-Member, Timeline
  - [ ] Block-Settings-UI rechts (Tabler-Offcanvas), Block-Inhalt links
  - [ ] Block-Bibliothek mit Suche und Kategorien
- [ ] **Hierarchie-Klärung**
  - [ ] Page → Sections → Rows → Columns → Editor.js-Content
  - [ ] Editor.js bleibt nur für die innerste Inhaltsebene zuständig
- [ ] **News- und Content-Quellen-Blöcke**
  - [ ] News-Block: zieht aus Posts, filterbar nach Kategorie/Tag
  - [ ] KB-Block: zieht aus Knowledge-Base
  - [ ] Forum-Block: aktuelle Threads
  - [ ] Custom-Query-Block: für Devs, Whitelist statt freiem SQL
- [ ] **Standard-Inhaltsblöcke**
  - [ ] Heading, Paragraph, List, Quote, Code, Table
  - [ ] Image, Gallery, Embed (YouTube, Vimeo, Eigenes), File-Download
  - [ ] Button (Primary/Secondary/Ghost, Größen, Icon)
  - [ ] Spacer, Divider, Columns (2/3/4 Spalten)
  - [ ] Accordion, Tabs, Cards
  - [ ] Form-Block über bestehenden Form-Stack
  - [ ] Map-Block (OpenStreetMap als Default, Google Maps optional)
  - [ ] Icon-Block (Tabler-Icons plus Lucide)
  - [ ] HTML-Raw-Block, capability-geschützt: nur `webbuilder.html_raw`

### 1.6 Navigation und Menüs

- [ ] **Menü-Manager**
  - [ ] Mehrere Menüs (Header, Footer, Mobile, Sidebar)
  - [ ] Drag-and-Drop-Hierarchie, max. 3 Ebenen
  - [ ] Menü-Item-Typen: interne Seite, externe URL, Anker, Dropdown, Mega-Menu
  - [ ] Sichtbarkeitsregeln: nur eingeloggt, nur Rolle X, nur Sprache Y
  - [ ] Icons pro Menüpunkt (Tabler-Icons)
- [ ] **Mega-Menu**
  - [ ] Spalten mit Überschriften, Links, optional Promo-Block (Bild plus CTA)
- [ ] **Breadcrumbs**
  - [ ] Auto-Generierung aus Seitenhierarchie
  - [ ] Schema.org-Markup (BreadcrumbList) inkludiert

### 1.7 Vorlagen, Seiten, Wiederverwendung

- [ ] **Page-Templates**
  - [ ] Mitgelieferte Templates: Landing, About, Contact, Blog-List, Blog-Single, 404
  - [ ] Eigene Templates speicherbar, als Startpunkt für neue Seiten
  - [ ] Template-Vererbung: globale Änderungen propagieren, lokale Overrides bleiben
- [ ] **Globale Bereiche (Reusable Blocks)**
  - [ ] Header-, Footer-, CTA-Blöcke einmal pflegen, überall einbinden
  - [ ] Versionierung mit Diff-Ansicht
- [ ] **Revisionen und Drafts**
  - [ ] Autosave alle 30 Sekunden
  - [ ] Manuelle Revisionen mit Kommentar
  - [ ] Revisions-Vergleich (links alt, rechts neu)
  - [ ] Veröffentlichen / Geplant veröffentlichen / Privat / Passwortgeschützt

### 1.8 Editor-UX im Admin

- [ ] **Drei-Panel-Layout**
  - [ ] Links: Block-/Sektion-Bibliothek
  - [ ] Mitte: Live-Preview (iFrame, gleiche Engine wie Frontend)
  - [ ] Rechts: Eigenschafts-Panel für selektierten Block
- [ ] **Device-Preview**
  - [ ] Toggle Desktop / Tablet / Mobile mit korrekten Breakpoints
- [ ] **Inline-Editing**
  - [ ] Texte direkt in der Preview editierbar (contenteditable, kontrolliert)
- [ ] **Undo/Redo**
  - [ ] Mind. 50 Schritte
  - [ ] Tastaturkürzel (Ctrl/Cmd+Z, Ctrl/Cmd+Shift+Z)
- [ ] **Keyboard-Navigation**
  - [ ] Block-Auswahl mit Pfeiltasten, Duplizieren mit Ctrl+D, Löschen mit Entf

### 1.9 SEO, Social, Strukturdaten

- [ ] **Pro-Seite-SEO-Panel**
  - [ ] Meta-Title, Meta-Description, Canonical, Robots (index/noindex/follow/nofollow)
  - [ ] Open Graph: Title, Description, Image, Type
  - [ ] Twitter Cards: Card-Type, Site, Creator
  - [ ] Schema.org: Article, Product, FAQPage, HowTo, Organization (auto plus manuell)
- [ ] **Sitemap und Robots**
  - [ ] Auto-generierte XML-Sitemap, splittbar nach Typ
  - [ ] `robots.txt`-Editor
  - [ ] Konsistenz mit bestehendem `/admin/seo-sitemap`
- [ ] **SEO-Checks im Editor**
  - [ ] Title-Länge, Description-Länge, H1-Vorhandensein, Bild-Alt-Texte, interne Links

### 1.10 Mehrsprachigkeit (optional)

- [ ] **i18n-Schicht**
  - [ ] Sprachen aktivierbar in Systemeinstellungen
  - [ ] Pro Seite: Übersetzungen verknüpft, hreflang automatisch
  - [ ] Übersetzbare Strings im UI über Translation-Editor
  - [ ] Default-Sprache Deutsch, da DACH-Zielgruppe
  - [ ] Anbindung an AI-Translation, Review-vor-Übernahme bleibt erzwungen

### 1.11 Performance und Caching

- [ ] **Render-Cache**
  - [ ] Pro-Seite-HTML-Cache mit Cache-Tags (page, block, global)
  - [ ] Invalidierung bei Block-/Settings-Änderung
- [ ] **Asset-Pipeline**
  - [ ] CSS-/JS-Bundling pro Seite, nur wirklich genutzte Blöcke laden
  - [ ] Critical-CSS-Inlining für Above-the-Fold
  - [ ] Lazy-Hydration für interaktive Blöcke (Tabs, Accordion)
- [ ] **Bild-Optimierung**
  - [ ] WebP/AVIF-Generierung beim Upload (libvips/Imagick)
  - [ ] CDN-Hook (Pfad-Rewriting via Setting)

### 1.12 Accessibility

- [ ] **Pflicht-Checks vor Veröffentlichung**
  - [ ] H1-Eindeutigkeit pro Seite
  - [ ] Alt-Texte für nicht-dekorative Bilder
  - [ ] Kontrastverhältnis WCAG AA
  - [ ] Fokus-Reihenfolge logisch, Skip-Link am Anfang
- [ ] **ARIA-Defaults**
  - [ ] Landmarks (header, nav, main, footer) automatisch
  - [ ] Mobile-Menü mit `aria-expanded`, `aria-controls`

### 1.13 Datenschutz und Cookie-Consent

- [ ] **Consent-Manager als Block**
  - [ ] Kategorien: notwendig, funktional, statistik, marketing
  - [ ] Embeds (YouTube, Maps) erst nach Consent laden, sonst Platzhalter mit „Klick zum Aktivieren"
  - [ ] DSGVO-konform, Standort Deutschland berücksichtigt
  - [ ] Konsistenz mit bestehendem `/admin/cookie-manager`
- [ ] **Footer-Pflichtlinks**
  - [ ] Impressum, Datenschutz, Kontakt vorbelegt aus Systemeinstellungen
  - [ ] Fehlt eine Pflichtseite, sichtbare Warnung im Editor

### 1.14 Formulare

- [ ] **Form-Builder als Block**
  - [ ] Felder: Text, Textarea, Email, Tel, Select, Radio, Checkbox, Datei-Upload, Hidden, Honeypot
  - [ ] Validierung clientseitig (Vanilla JS) und serverseitig (PHP), gleiche Regeln
  - [ ] Submission-Speicher in DB plus Mail-Versand (Symfony Mailer)
  - [ ] Anti-Spam: Honeypot plus Rate-Limit plus optional hCaptcha/Cloudflare Turnstile
  - [ ] DSGVO-Hinweis-Checkbox als Standard
  - [ ] Anbindung an zentralen `AntispamService`

### 1.15 Suche

- [ ] **Site-Search**
  - [ ] Volltext-Suche über Seiten, Posts, KB
  - [ ] TNTSearch als Default (bereits in Runtime), optional Meilisearch-Adapter
  - [ ] Such-Block für Header (Autocomplete) und Such-Ergebnis-Seite

### 1.16 Entwickler-Schnittstellen

- [ ] **Hook-System**
  - [ ] Filter und Actions für jeden Render-Schritt (`webbuilder.block.render`, `webbuilder.page.head`)
  - [ ] Custom-Block-API: PHP-Klasse plus JS-Editor-Komponente registrierbar
- [ ] **REST-/JSON-API**
  - [ ] Endpoints: `/api/webbuilder/pages`, `/api/webbuilder/blocks`
  - [ ] Auth via API-Token mit Scopes
- [ ] **CLI-Tool**
  - [ ] Export/Import von Seiten, Sektionen, Tokens als JSON
  - [ ] Migrationsbefehle (`php cms webbuilder:migrate-from-theme=default`)
- [ ] **Logging**
  - [ ] PSR-3/Monolog: Editor-Aktionen, Render-Fehler, Cache-Invalidierungen
  - [ ] Audit-Log: wer hat wann welche Seite geändert, sichtbar in `/admin/cms-logs`

### 1.17 Sicherheit

- [ ] **Input-Sanitizing**
  - [ ] HTML-Purifier für alle Block-Outputs (vorhandene Runtime nutzen)
  - [ ] CSP-Header mit Nonce für Inline-Scripts
- [ ] **CSRF-Schutz**
  - [ ] Tokens für alle Editor-POSTs, mehrtab-tolerantes TTL-Fenster wie im restlichen Admin
- [ ] **Capability-Checks**
  - [ ] `webbuilder.manage`, `webbuilder.publish`, `webbuilder.html_raw`, `webbuilder.media`

### 1.18 Backup und Migration

- [ ] **Export/Import**
  - [ ] Komplette Site als ZIP (DB-Inhalte plus Media plus Tokens)
  - [ ] Selektiver Export einzelner Seiten oder Sektionen
- [ ] **Theme → Webbaukasten Migration-Wizard**
  - [ ] Aktives Theme analysieren, Header/Footer/Farben vorausfüllen
  - [ ] Bestehende Posts/Pages bleiben unangetastet, nur Layout-Hülle wechselt

### 1.19 Telemetrie und Analytics-Hooks

- [ ] **Analytics-Block-Slot im `<head>`**
  - [ ] Matomo (self-hosted, primary), Plausible, GA4 als Optionen
  - [ ] Consent-Gate vorgeschaltet
- [ ] **Performance-Marker**
  - [ ] Core Web Vitals via `web-vitals.js` optional einbindbar

### 1.20 Dokumentation und Onboarding

- [ ] **In-App-Hilfe**
  - [ ] Tooltips an jedem Setting
  - [ ] Erste-Schritte-Tour beim ersten Aktivieren des Moduls
- [ ] **Beispielseiten**
  - [ ] Demo-Site importierbar (1 Klick) zum Lernen
- [ ] **Changelog-Anzeige im Admin**
  - [ ] Neue Features pro Version sichtbar

---