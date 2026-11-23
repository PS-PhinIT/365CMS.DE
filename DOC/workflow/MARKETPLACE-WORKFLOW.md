# Workflow: Erweiterungen installieren

> **Stand:** 2026-11-23 | **Version:** 4.2.0

1. Quelle, Versionsmanifest und Prüfsumme der Erweiterung kontrollieren.
2. Im Admin nur Plugins/Themes aus vertrauenswürdigen Quellen installieren; Pakete werden in `CMS/plugins/` beziehungsweise `CMS/themes/` abgelegt.
3. Das Manifest muss zum installierten CMS-Stand passen. Drittcode wird nach Installation durch CSP, Berechtigungen und die Admin-Seitenzugriffe des CMS eingeschränkt.
4. Installation auf einer Staging-Kopie testen; Plugin-/Theme-Seiten, Einstellungen, öffentliche Ausgabe und Deinstallation prüfen.
5. Nach Updates `php TESTS/theme-contract/run.php` für Themes beziehungsweise die zugehörigen Plugin-Tests ausführen.

Produktiv geladene Erweiterungen befinden sich ausschließlich in den beiden Runtime-Verzeichnissen. Siehe [../plugins/GUIDE.md](../plugins/GUIDE.md) und [../theme/THEME-DEVELOPMENT.md](../theme/THEME-DEVELOPMENT.md).

