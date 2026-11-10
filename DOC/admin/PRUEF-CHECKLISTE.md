# 365CMS – Projektdokumentation | Abschnitt: Admin – Prüf-Checkliste

> **Stand:** 2026-11-09 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

Checklist for reviewing a change to the administration (core page, module, view or plugin admin page) and for the post-deployment smoke test. Each item maps to a concrete mechanism in the code base.

## Deutsch

### A. Code-Review einer Admin-Änderung

**Routing & Struktur**
- [ ] Route `/admin/<seite>` löst über `AdminRouter` auf die vorgesehene Datei auf; Slug nur `[a-zA-Z0-9_-]`.
- [ ] Einstieg, Modul und View existieren und folgen der Schichtentrennung ([FILESTRUCTURE.md](FILESTRUCTURE.md)).
- [ ] Neue Seite ist in der Seitenleiste eingetragen (`AdminNavigationService::buildCoreGroups()`) und – falls Teil eines Feature-Bereichs – in `CoreModuleService::MODULES[...]['admin_pages']`.
- [ ] Alt-Routen leiten weiter statt 404.

**Zugriff & Eingaben**
- [ ] `Auth::isAdmin()` **und** passende Capability werden serverseitig geprüft.
- [ ] Aktionen, Ansichten, Status, Sammelaktionen werden gegen Allowlists normalisiert; IDs als positive Integer; Sammelaktionen mit Obergrenze.
- [ ] Jede zustandsändernde Anfrage ist POST mit `csrf_token` der Seitenaktion.
- [ ] GET-Anfragen ändern keine Daten.

**Daten & Ausgabe**
- [ ] SQL ausschließlich mit Prepared Statements bzw. `Database::insert/update/delete`.
- [ ] Ausgaben kontextgerecht escaped (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`, JSON-Flags `JSON_HEX_*`).
- [ ] Rich-Text über `PurifierService`/Editor.js-Sanitizer, Uploads über `MediaService`/`FileUploadService`.
- [ ] Keine Inline-Skripte/-Styles/-Event-Handler (CSP); JS/CSS als Datei unter `CMS/assets/`, ES-Module über `page_assets['modules']`.
- [ ] Bedienbarkeit: sichtbarer Fokus, `aria-label` an Icon-Buttons, `aria-expanded` an aufklappbaren Elementen, Klickflächen ≥ 44 px, Kontrast ≥ 4,5:1.

**Fehler & Protokoll**
- [ ] Fehler erscheinen als Flash-Meldung mit Details, nicht als weiße Seite; Ausnahmen werden über `Logger` protokolliert.
- [ ] Sicherheitsrelevante Aktionen schreiben ins Audit-Log (`AuditLogger`).
- [ ] Keine Secrets, Tokens, Passwörter oder unnötigen personenbezogenen Daten in UI, Logs oder Fehlermeldungen.

**Dokumentation**
- [ ] Zugehöriges Dokument unter `DOC/admin/` aktualisiert, Eintrag in `Changelog.md`.

### B. Smoke-Test nach Deployment / Update

- [ ] `/admin` lädt, Sidebar vollständig, keine PHP-Warnungen im `/admin/logs/php-errors`.
- [ ] Seitenleiste: Gruppen auf-/zuklappen und einklappen (Icon-Leiste) bleiben nach dem Neuladen erhalten; Gruppe „Plugins“ zeigt alle aktiven Plugins mit Menüeinträgen; unter 992 px öffnet sich die Seitenleiste als Drawer (Escape schließt).
- [ ] *Einstellungen › Plugins › Plugin-Zuweisung*: nach einem Update sind alle Plugins allen Sites zugewiesen.
- [ ] `/admin/updates`: installierte = erwartete Core- und Schema-Version (`4.0.0`, `v24`); ggf. `run_database_update`.
- [ ] `/admin/monitor-health-check` ohne kritische Befunde.
- [ ] `/admin/security-audit` ausgeführt; `install.php` nicht erreichbar, Debug aus, HTTPS aktiv.
- [ ] Seite und Beitrag speichern, Medien-Upload, Vorschau im Frontend; Beitrag ohne Beitragsbild lässt sich nicht veröffentlichen (Checkliste), Revisionen erscheinen im Editor.
- [ ] Login/Logout, MFA-Abfrage, Passwort-vergessen-Mail.
- [ ] Cron läuft (`/admin/monitor-cron-status`), Mail-Queue leer bzw. abgearbeitet; Systemhinweise am Avatar der Seitenleiste geprüft.
- [ ] Sitemap `/sitemap.xml` und `/robots.txt` erreichbar.
- [ ] Backup erstellt und validiert.

### Verwandte Dokumente

[README.md](README.md) · [GUIDE.md](GUIDE.md) · [security/SECURITY-AUDIT.md](security/SECURITY-AUDIT.md) · [../workflow/UPDATE-DEPLOYMENT-WORKFLOW.md](../workflow/UPDATE-DEPLOYMENT-WORKFLOW.md)
