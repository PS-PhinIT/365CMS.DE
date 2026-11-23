# Community: Übergabe PR #2 bis PR #5

> Abschlussdokument 4.2.0, aktualisiert 2026-11-23

Die frühere stufenweise Übergabe ist vollständig ausgeliefert:

- [x] Dienste, Repositories, Rechte, Inhalte und Moderation
- [x] öffentliche HTTP-Controller und Views
- [x] API und Mitgliederintegration
- [x] Admin-Seiten und zentrale Papierkorb-Anbindung
- [x] Uploads, Suche, Benachrichtigungen, Datenschutz und Prüfungen

Der aktuelle Code liegt in `CMS/core/Services/Community/`, `CMS/core/Routing/`, `CMS/admin/community*.php`, `CMS/admin/modules/community/`, `CMS/views/community/` sowie `CMS/assets/js/community/`.

## Übergabegrenzen

- Externe Datenbankausführung benötigt die Variablen von `TESTS/community-db/` beziehungsweise `TESTS/schema-upgrade/`.
- Browser- und Lighthouse-Teile benötigen lokale Node-, Playwright- und Browserpakete.
- Ein separates Theme außerhalb dieses Repositories wird nicht durch den Codebestand `cms-default` abgedeckt.

Diese drei Punkte sind Prüfbedingungen, keine offen gebliebenen Implementierungsaufgaben.

