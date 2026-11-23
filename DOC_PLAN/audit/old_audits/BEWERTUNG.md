# BEWERTUNG

> **⏱️ Historischer Snapshot (April 2026).** Dieses Dokument hält den Snyk-/Bewertungsstand vom **April 2026** (CMS-Stand ~2.8.x) fest und bleibt als Delta-Referenz erhalten. **Für die aktuelle First-Party-Code-Sicherheit gelten die internen Code-Audits vom 2026-06-10:**
> [../AUDIT_core_2026-06-10.md](../AUDIT_core_2026-06-10.md) · [../AUDIT_admin_2026-06-10.md](../AUDIT_admin_2026-06-10.md) · [../AUDIT_member-includes-views_2026-06-10.md](../AUDIT_member-includes-views_2026-06-10.md) — Ergebnis: **0 kritische Funde**, alle Defense-in-Depth-Härtungen übernommen (CMS-Stand 3.3.47).

> Strukturhinweis: Die frühere Verteilung auf `AdminAudit-*.md`, `AssetAudit-*.md`, `PRÜFUNG.MD` sowie einzelne Snyk-/Live-Audit-Dateien wurde in sechs Sammelaudits unter `DOC/audit/Audit-*.md` konsolidiert. Diese Datei bleibt die führende Bewertungs- und Delta-Referenz.

## Snyk-Audit — 4. April 2026

Diese Sektion dokumentiert den aktuellen **Snyk-Snapshot** des Repositories `365CMS.DE`.
Geprüft wurden dabei sowohl **Code-Funde (SAST / Snyk Code)** als auch die lokal erkennbaren **Manifest-/Abhängigkeitsquellen (SCA)**.

### Snyk-Snapshot `365CMS.DE`

| Bereich | Score | Kurzbegründung |
|---|---:|---|
| First-Party Code | 100,00 | Nach dem aktuellen Follow-up-Scan bleiben keine Nicht-Vendor-/Runtime-Funde mehr übrig; First-Party-`High`s sind im Snapshot weiterhin verschwunden |
| Gebündelte Vendor-Fläche | 42,00 | 34 Funde liegen weiterhin in mit ausgelieferten Drittanbieter-Bundles wie `dompdf`, `translation-status` und weiteren Bundle-Artefakten |
| Dependency Manifests (SCA) | 100,00 | Snyk Open Source meldet für die erkannten Composer-Manifeste aktuell 0 verwertbare Funde |
| Abarbeitbarkeit | 99,00 | Die Restliste liegt jetzt vollständig in Vendor-/Bundle-Fläche; First-Party-Hotspots sind im aktuellen Snapshot abgearbeitet |
| **Gesamt** | **85,25** | SCA bleibt sauber; der First-Party-Block ist im aktuellen Snapshot leer, während die Hauptlast nun klar sichtbar in Bundles/Vendor-Fläche liegt |

### Delta Snyk-Snapshot 04.04.2026

| Bereich | Status | Befund | Wirkung |
|---|---|---|---|
| Snyk Code gesamt | verbessert | Der nächste Follow-up-Scan nach Router-/Theme-Follow-up meldet noch 36 Funde (`27 High`, `9 Medium`) statt zuvor 47. | Der Gesamtblock schrumpft weiter; zusätzliche Mediums verschwinden aus dem eigenen Code und der Rest konzentriert sich jetzt vollständig auf Bundle-/Vendor-Fläche. |
| First-Party-Hotspots | bereinigt | Im aktuellen Nicht-Vendor-/Runtime-Block bleiben keine Funde mehr übrig; `Router.php` und `ThemeCustomizer.php` sind aus der Restliste verschwunden. | Die weitere Abarbeitung kann sich jetzt vollständig auf Vendor-/Bundle-Fläche wie `dompdf` und `translation-status` konzentrieren. |
| Vendor-Bundles | offen | Ein großer Teil der Funde liegt in mit ausgelieferten Fremdkomponenten, vor allem `dompdf`. | Die Runtime-Oberfläche enthält weiterhin Drittcode, der separat gekapselt, aktualisiert oder ersetzt werden sollte. |
| SCA | gut | Der Open-Source-Abhängigkeitsscan über erkannte Composer-Manifeste meldet aktuell 0 Issues. | Supply-Chain-Risiken über Manifeste sind im Snapshot unauffällig; Schwerpunkt bleibt Code- und Bundle-Härtung. |
| Importer-DOM-XSS | umgesetzt | `CMS/plugins/cms-importer/assets/js/importer.js` rendert Notices und Cleanup-Dialog-Texte jetzt ohne `innerHTML`. | Die zuvor gemeldeten DOM-XSS-Befunde im Importer sind aus dem aktuellen Scan verschwunden. |
| Dokumentation | aktualisiert | Detailbericht liegt aktualisiert in `DOC/audit/Snyk_Audit_04042026.md`, README und Changelog spiegeln den neuen Stand als `2.8.5`. | Der neue Snyk-Stand ist für Folge-Batches direkt referenzierbar und konsistent im Release-Snapshot verankert. |

## Live-Site-Audit — 4. April 2026

Diese Sektion dokumentiert **keinen neuen Core-Code-Batch**, sondern einen Produktions-Snapshot der öffentlich erreichbaren Website `https://365cms.de/`.
`404`-Seiten wurden **nicht pauschal negativ bewertet**; berücksichtigt wurden nur 404-Fälle bei **aktiv verlinkten Kernzielen** wie Footer- oder Formularlinks.

### Live-Snapshot `365cms.de`

| Scope | Routing & Erreichbarkeit | Auth-/Form-Flows | Legal & Trust | Content-/Release-Konsistenz | Navigation/Footer | Gesamt |
|---|---:|---:|---:|---:|---:|---:|
| `https://365cms.de/` | 71,00 | 84,00 | 24,00 | 58,00 | 44,00 | **56,20** |

Die Live-Prüfung zeigt damit einen funktionierenden öffentlichen Grundstand mit klaren Schwächen bei **rechtlich relevanten Linkzielen** und bei der **Release-Konsistenz zwischen Live-Site und lokalem Repo-Stand**.

### Delta Live-Audit 04.04.2026

| Bereich | Status | Befund | Wirkung |
|---|---|---|---|
| Homepage | geprüft | Startseite lädt stabil und zeigt Produkt-/Feature-Highlights; sichtbare Live-Version ist `2.8.2`. | Öffentliche Produktseite ist erreichbar, spiegelt aber nicht den lokalen Repo-Stand `2.8.5`. |
| Login / Register / Reset | geprüft | `/login`, `/register` und `/forgot-password` sind öffentlich erreichbar und strukturiert. | Auth-Flows sind grundsätzlich vorhanden und für Nutzer zugänglich. |
| Blog | geprüft | `/blog` liefert einen sauberen Empty-State statt Fehlerseite. | Content-Leerstand ist UX-seitig abgefedert. |
| Legal Links | offen | Die aktiv verlinkten Ziele `/impressum`, `/datenschutz` und `/agb` laufen live in 404. | Footer- und Formular-Vertrauenssignale brechen auf Kernzielen sichtbar weg. |
| Dokumentation | dokumentiert | Detailbericht liegt in `DOC/audit/LiveAudit_365CMS.md`. | Der Produktions-Snapshot ist jetzt reproduzierbar dokumentiert und gegen konkrete URLs abarbeitbar. |

## Live-Site-Audit — 3. April 2026

Diese Sektion dokumentiert **keinen neuen Core-Code-Batch**, sondern einen Produktions-Snapshot der via FTP deployten Live-Site `https://phinit.de/`.
Die hier genannten Werte ergänzen deshalb die bestehenden Repo-/Code-Auditwerte um einen separaten Blick auf die **tatsächlich ausgelieferte Website**.

### Live-Snapshot `phinit.de`

| Scope | Localization | Routing/SEO | Search | UI/Content | EN Legal/Contact | Gesamt |
|---|---:|---:|---:|---:|---:|---:|
| `https://phinit.de/` | 38,00 | 52,00 | 64,00 | 46,00 | 28,00 | 45,60 |

Die Produktionsprüfung zeigt damit eine klare Lücke zwischen dem bereits hoch bewerteten Core-/Repo-Stand und dem real ausgelieferten Frontend-Snapshot von `phinit.de`. Besonders die englischen Pflichtseiten, Sprachwechsler, Archivlisten und die Live-Suche benötigen Nachpflege, bevor der öffentliche Stand dieselbe Qualität wie der dokumentierte Code-Snapshot erreicht.

### Delta Live-Audit 03.04.2026

| Bereich | Status | Befund | Wirkung |
|---|---|---|---|
| EN-Pflichtseiten | offen | `/en/impressum`, `/en/datenschutz`, `/en/contact/kontakt` und `/en/datenschutzerklaerung` liefern weiterhin deutschsprachige Inhalte. | Die englische Site ist rechtlich und UX-seitig nicht konsistent ausgeliefert. |
| Sprachrouting | offen | Sprachwechsel auf Artikelbasis zeigen teils auf falsche oder nicht existierende Gegenslugs; ein nachgewiesenes EN-Ziel läuft in 404. | Nutzer und Suchmaschinen erhalten inkonsistente Sprachrelationen statt stabiler Gegenstücke. |
| Archive | offen | `/blog?page=2` und `/en/blog?page=3` listen DE-/EN-Varianten desselben Themas parallel. | Sprachtrennung, Archivklarheit und Duplicate-Content-Signal bleiben geschwächt. |
| EN-Layout/UI | offen | EN-Homepage und EN-Artikel mischen englische Inhalte mit deutschen Bereichstiteln, CTA-Texten, Kommentarstrings und Footer-Bausteinen. | Die englische Auslieferung wirkt unfertig und untergräbt die Sprachkonsistenz des Frontends. |
| Suche & Rechtsrouten | offen | Die Suche für `Browser Protection` streut live noch breit; zusätzlich existieren doppelte Datenschutz-Routen (`/datenschutz` und `/datenschutzerklaerung`). | Relevanz, Kanonisierung und öffentliche Content-Integrität bleiben unter Produktionsniveau. |
| Dokumentation | dokumentiert | Die Detailbefunde wurden in `PHINIT-LIVE-AUDIT-2026-04-03.md`, `PHINIT-LIVE-LOCALIZATION-2026-04-03.md` und `PHINIT-LIVE-SEARCH-ARCHIVE-2026-04-03.md` abgelegt. | Der Live-Stand ist jetzt reproduzierbar dokumentiert und kann gezielt gegen konkrete URLs abgearbeitet werden. |

## Inkrementelle Nachpflege — 25. März 2026

Diese Sektion dokumentiert bereits umgesetzte Teilfortschritte aus `DOC/audit/PRÜFUNG.MD`,
ohne die große Bewertungsmatrix bei jedem einzelnen Batch vollständig neu auszurechnen.

### Gesamtstand nach Batch 592

| Dateien | Ø Security | Ø Speed | Ø PHP/BP | Ø Gesamt |
|---:|---:|---:|---:|---:|
| 592 | 96,20 | 93,26 | 97,52 | 97,61 |

Der aktuelle Nachpflege-Stand umfasst damit **592 umgesetzte Batches**, davon weiterhin **444 von 444 Prüfplan-Punkten** im ursprünglichen Auditplan und zusätzlich **148 Folge-Batches** darüber hinaus. Zuletzt wurde der Design-Cluster weiter nachgezogen: Theme-Explorer, Menü-Editor und Font-Manager nutzen jetzt auch in Legacy-/Fallback-Browserpfaden nur noch native temporäre Submitter statt direkter `form.submit()`-Bypässe, der Menü-Editor bricht stale `?menu=`-Ziele mit Warn-Redirect fail-closed ab und Landing-/Auth-Services laufen bei Logging sowie fehlender `mbstring` näher am strukturierten Runtime-Vertrag. Security, Speed und PHP/BP steigen dadurch erneut in kleinen Schritten gleichzeitig.

### Delta Folge-Batch 592

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-theme-explorer.js`, `CMS/assets/js/admin-menu-editor.js`, `CMS/assets/js/admin-font-manager.js`, `CMS/admin/menu-editor.php`, `CMS/admin/modules/menus/MenuEditorModule.php`, `CMS/core/Services/LandingPageService.php`, `CMS/core/Services/CmsAuthPageService.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AssetAudit-Design.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Design-Cluster staffelt mehrere verbleibende Fallback- und Diagnosepfade enger an denselben nativen Browser- und Runtime-Vertrag: Theme-Explorer, Menü-Editor und Font-Manager springen bei fehlendem `requestSubmit()` nicht mehr direkt in `form.submit()`, sondern nutzen temporäre native Submitter; `menu-editor.php` validiert angeforderte `?menu=`-Ziele vor dem Rendern gegen den Live-Bestand und bricht stale Links mit Warnhinweis fail-closed ab; `LandingPageService` ergänzt einen `mb_strtolower()`-/`strtolower()`-Fallback und strukturiertes Logger-Handling, während `CmsAuthPageService` seine Settings-/PageOptions-/Reset-Fehler aus rohen `error_log()`-Pfaden in einen dedizierten Logger-Channel zieht. | Design-Asset- und Servicepfade verlieren damit gleich mehrere stille Altlasten zugleich: Browser-Fallbacks umgehen weder Submit-Listener noch Validierung mehr per Direkt-Submit, stale Menülinks landen nicht länger kommentarlos in derselben Leerauswahl wie ein absichtlich ungewähltes Menü, und Landing-/Auth-Laufzeitfehler bleiben sowohl ohne `mbstring` als auch unter produktiver Diagnose näher am dokumentierten Service-Vertrag. Der dokumentierte Aggregatstand steigt damit leicht auf **96,20 / 93,26 / 97,52 / 97,61**. |

### Delta Folge-Batch 591

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-themes.js`, `CMS/admin/themes.php`, `CMS/admin/views/themes/list.php`, `CMS/admin/modules/themes/ThemesModule.php`, `CMS/core/ThemeManager.php`, `CMS/admin/modules/themes/ThemeMarketplaceModule.php`, `DOC/audit/AdminAudit-Themes.md`, `DOC/audit/AssetAudit-Themes.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Themes-Cluster staffelt Listen-, Mutations- und Installationspfade jetzt enger an einen kleinen Managed-Theme-Vertrag: die Theme-Liste hängt Activate-/Delete-Aktionen über das neue Asset `admin-themes.js` an Shared-Confirm und Submit-Locks, `ThemesModule` spiegelt POST-Ziele fail-closed gegen den aktuellen Theme-Bestand, `ThemeManager` enumeriert Themes nur noch über validierte Nicht-Symlink-Roots unter `THEME_PATH`, serialisiert Activate-/Delete-Aktionen per Lock-Verzeichnis und löscht Theme-Inhalte symlink-sicher nur innerhalb des echten Theme-Roots, während `ThemeMarketplaceModule` parallele Installationen pro Zielordner blockt und Symlinks in Paket-Promotion, Copy und Cleanup explizit fail-closed behandelt. | Theme-Aktionen verlieren damit mehrere stille Race-, Stale- und Dateisystem-Sonderpfade zugleich: die produktive Theme-Verwaltung umgeht den Browser-/Formvertrag nicht länger per Inline-`form.submit()`, veraltete Theme-POSTs enden nicht mehr tief im Runtime-Core, und Theme- sowie Marketplace-Mutationen geraten weder über parallele Requests noch über Symlink-/Nicht-Theme-Verzeichnisse in unklare Zustände. Der dokumentierte Aggregatstand steigt damit leicht auf **96,12 / 93,24 / 97,38 / 97,49**. |

### Delta Folge-Batch 590

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-subscriptions.js`, `CMS/core/Services/CoreModuleService.php`, `CMS/core/Services/MemberService.php`, `CMS/member/subscription.php`, `CMS/admin/views/subscriptions/orders.php`, `CMS/admin/views/subscriptions/packages.php`, `CMS/admin/views/subscriptions/settings.php`, `CMS/orders.php`, `DOC/audit/AdminAudit-Abos.md`, `DOC/audit/AssetAudit-Abos.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Abo-Cluster staffelt mehrere auseinanderdriftende Runtime- und Asset-Verträge jetzt enger an denselben echten Produktivpfad: `CoreModuleService` löst gespeicherte Subscription-Flags wieder fallbacksicher über ihre Legacy-Settings auf, Member-Abo und öffentliche Paketliste respektieren deaktivierte Ordering-/Public-Pricing-Schalter tatsächlich, `orders.php` lehnt deaktivierte Orderflows oder inaktive Pakete fail-closed ab und zieht Zahlung, Steuer, AGB/Widerruf, Betragsspeicherung sowie hostneutrale Rücksprünge auf dieselbe Checkout-Wahrheit zusammen. Parallel bündeln `orders.php`, `packages.php` und `settings.php` ihre letzten Inline-Skripte, `onclick`-Reste, Hidden-`form.submit()`-Bypässe und Mehrfach-POST-Risiken jetzt über das neue gemeinsame Asset `admin-subscriptions.js`. | Abo- und Checkout-Einstellungen bleiben damit nicht länger nur dekorativ in der Datenbank hängen, sondern steuern Member-, Admin- und Public-Runtime wieder konsistent. Deaktivierte Bestellstrecken oder inaktive Pakete rutschen nicht mehr in halb offene Checkout-Zustände, Steuer-/Zahlungsmethoden und Rechtsseiten werden tatsächlich am Bestellformular und in gespeicherten Orders wirksam, und Orders-/Paketaktionen verlieren weitere Inline-/Bypass-Sonderpfade zugunsten nativer Submitter und Submit-Locks. Der dokumentierte Aggregatstand steigt damit leicht auf **95,98 / 93,22 / 97,20 / 97,31**. |

### Delta Folge-Batch 589

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/member-dashboard.js`, `CMS/assets/js/admin-member-dashboard.js`, `CMS/admin/views/member/plugin-widgets.php`, `CMS/member/includes/class-member-controller.php`, `CMS/member/dashboard.php`, `CMS/member/notifications.php`, `CMS/member/partials/header.php`, `DOC/audit/AdminAudit-Member.md`, `DOC/audit/AssetAudit-Member.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Member-Cluster staffelt mehrere bislang lose gekoppelte Admin-/Frontend-Verträge jetzt enger an echte Runtime-Pfade: Passkey-Registrierung läuft wieder submitter-nativ statt per direktem `form.submit()`, die Plugin-Widget-Sortierung hängt nicht länger an einem Inline-Skript, `dashboard_logo`, `show_quickstart` sowie Notification-Center-Einstellungen (`center_enabled`, `types`, `empty_text`) wirken jetzt tatsächlich im Frontend-Dashboard und auf `/member/notifications`, und Member-interne Login-, Redirect- und Navigationspfade verzichten auf feste `SITE_URL`-Basen zugunsten hostneutraler relativer Ziele. | Gespeicherte Member-Dashboard-Einstellungen kippen damit nicht mehr in tote oder nur halb verdrahtete Konfiguration, Sicherheits- und Notification-Flows bleiben näher an denselben nativen Submit-/Same-Origin-Verträgen wie der restliche gehärtete Core, und Admin-/Frontend-Wechsel springen auch unter Proxy-, Alternativhost- oder lokalen Dev-URLs nicht mehr unnötig auf eine feste Origin. Zusammen mit der neu angelegten `AssetAudit-Member.md` steigt der dokumentierte Aggregatstand damit leicht auf **95,90 / 93,15 / 97,12 / 97,24**. |

### Delta Folge-Batch 588

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/core/Services/MediaService.php`, `CMS/core/Services/Media/MediaRepository.php`, `CMS/admin/views/media/library.php`, `CMS/assets/js/admin-media-integrations.js`, `DOC/audit/AdminAudit-Medien.md`, `DOC/audit/AssetAudit-Medien.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Medien-Cluster staffelt Bibliotheks-, Mutations- und Asset-Pfade jetzt enger an fail-closed Laufzeitverträge: `media.php` bricht stale `?path=`-Ziele mit Listen-/Root-Rücksprung ab, `MediaModule` validiert Upload-, Delete-, Rename-, Move- und Bulk-POSTs vor dem Write gegen den aktuellen Bestand, blockiert geschützte Systempfade und akzeptiert Zielordner nur noch, wenn sie real existieren, während `MediaRepository` Kategorie-Deletes für verschwundene Slugs nicht länger still als Erfolg behandelt. Parallel ziehen Library und Medien-Asset Select-/Delete-Roundtrips auf temporäre native Submitter zusammen und sperren Bulk-Submits gegen Doppel-POSTs. | Veraltete Bibliothekslinks, manipulierte Zielordner, geschützte Root-Pfade oder gemischte Bulk-Auswahlen kippen damit nicht mehr in leere Ansichten, Schein-Erfolge oder partielle Sammelwrites. Gleichzeitig bleiben Datei-Kategoriezuweisungen, Filterwechsel und bestätigte Delete-Flows näher an Browser-Validierung, Submit-Events und dem gemeinsamen Admin-Formvertrag. Zusammen mit der neu angelegten `AssetAudit-Medien.md` steigt der dokumentierte Aggregatstand damit leicht auf **95,82 / 93,09 / 97,05 / 97,17**. |

### Delta Folge-Batch 587

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/users.php`, `CMS/admin/modules/users/UsersModule.php`, `CMS/admin/modules/users/GroupsModule.php`, `CMS/admin/modules/users/RolesModule.php`, `CMS/assets/js/admin-users.js`, `CMS/assets/js/admin-user-groups.js`, `DOC/audit/AdminAudit-Benutzer.md`, `DOC/audit/AdminAudit-Gruppen.md`, `DOC/audit/AssetAudit-Benutzer.md`, `DOC/audit/AssetAudit-Gruppen.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Benutzer-/Gruppen-/RBAC-Cluster staffelt mehrere Restpfade jetzt enger an fail-closed Runtime- und Asset-Verträge: `users.php` bricht stale Edit-Ziele sowie fehlgeschlagene Saves auf verschwundene Benutzer mit Listen-Rücksprung ab, `UsersModule` validiert Bulk-IDs gegen den aktuellen Bestand, blockt Self-Targeting und hält Fehlerreport-Payloads wieder über einen real vorhandenen Helper stabil, `GroupsModule` behandelt stale Gruppen-IDs nicht länger als Erfolg und kapselt Delete-Roundtrips transaktional, `RolesModule` zieht Berechtigungsmatrix-, Rollen- und Capability-Mutationen auf Transaktionen plus Existenz-/RowCount-Verträge zusammen, und die User-/Group-Assets ersetzen direkten Delete-Bypass bzw. Bulk-Doppelsubmit durch native, gesperrte Submit-Flows. | Veraltete User-/Group-/RBAC-Ziele kippen damit nicht mehr in irreführende Neuformulare oder Schein-Erfolge, offene Benutzerlisten verlieren stale Bulk- und Self-Target-Brüche und die Rollen-/Rechteverwaltung bleibt auch bei Mehrzeilen- oder stale Slug-Mutationen näher an einem atomaren, nachvollziehbaren Write-Vertrag. Zusammen mit den neuen Asset-Audit-Mappen steigt der dokumentierte Aggregatstand damit leicht auf **95,74 / 93,06 / 96,98 / 97,09**. |

### Delta Folge-Batch 586

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/posts.php`, `CMS/admin/post-categories.php`, `CMS/admin/post-tags.php`, `CMS/admin/modules/posts/PostsModule.php`, `CMS/assets/js/admin-content-editor.js`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/AssetAudit-Beitraege.md`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Beiträge-/EditorJS-Cluster staffelt mehrere Restpfade jetzt enger an fail-closed UI- und Persistenzverträge: stale `?action=edit&id=...`-/`?edit=...`-Kontexte für Beiträge, Kategorien und Tags werden nicht länger wie Create-Ansichten behandelt, `PostsModule` validiert Einzel-Delete, Tag-/Kategorie-Delete und Bulk-`ids[]` explizit gegen den aktuellen Datenbestand statt stille 0-Treffer oder gemischte stale Listen als Erfolg zu melden, und `admin-content-editor.js` ersetzt den letzten direkten `submit()`-Fallback durch einen temporären nativen Submitter. | Veraltete Admin-Links oder offene Grid-Auswahlen kippen damit nicht mehr in irreführende Neuformulare oder Sammel-/Delete-Erfolgsmeldungen, obwohl die Ziele schon verschwunden sind; parallel bleibt auch der letzte Shared-Editor-Fallback näher an Browser-Validierung und Submit-Events statt an einem direkten Bypass. Zusammen mit den nachgezogenen Audit-Mappen steigt der dokumentierte Aggregatstand damit leicht auf **95,67 / 93,03 / 96,89 / 96,99**. |

### Delta Folge-Batch 585

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/orders.php`, `CMS/admin/packages.php`, `CMS/admin/subscription-settings.php`, `CMS/admin/modules/subscriptions/OrdersModule.php`, `CMS/admin/modules/subscriptions/PackagesModule.php`, `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php`, `CMS/admin/views/subscriptions/orders.php`, `DOC/audit/AdminAudit-Abos.md`, `DOC/audit/AssetAudit-Abos.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Orders-/Abo-Cluster zieht seine Rechte- und Submit-Verträge enger zusammen: die Admin-Entrys für Orders, Pakete und Abo-Einstellungen sowie die korrespondierenden Module prüfen jetzt nicht mehr nur generische Admin-Rechte, sondern explizit `manage_settings`; parallel ersetzt die Orders-View den letzten direkten `form.submit()`-Fallback im Shared-Submit-Helper durch einen temporären nativen Submitter. | Feingranulare Rollenmodelle können die Abo-/Orders-Verwaltung damit nicht mehr allein über breite Admin-Rechte erreichen, und auch der letzte Orders-Fallback umgeht Browser-/Form-Handler nicht mehr über einen direkten Submit-Befehl. Zusammen mit den nachgezogenen Audit-Mappen steigt der dokumentierte Aggregatstand damit leicht auf **95,62 / 93,00 / 96,83 / 96,93**. |

### Delta Folge-Batch 584

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/tables/TablesModule.php`, `CMS/admin/site-tables.php`, `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php`, `CMS/admin/modules/subscriptions/PackagesModule.php`, `CMS/admin/views/subscriptions/packages.php`, `DOC/audit/AdminAudit-Abos.md`, `DOC/audit/AdminAudit-Tabellen.md`, `DOC/audit/AssetAudit-Abos.md`, `DOC/audit/AssetAudit-Tabellen.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Tabellen-/Abo-Cluster staffelt mehrere Restpfade jetzt enger an fail-closed Persistenz- und UI-Verträge: `TablesModule` akzeptiert Editor-JSON nicht länger still als leere Arrays, stale `edit`-/`delete`-Ziele enden nicht mehr als neue Tabelle oder Schein-Erfolg, `SubscriptionSettingsModule` behandelt fehlgeschlagene `settings`-Writes nicht mehr als Erfolg und `PackagesModule` prüft Paket-Create-/Update-/Delete-/Toggle-Verträge explizit gegen echte DB-Schreibresultate bzw. konkurrierende Zustandswechsel. Parallel nutzt die Pakete-View für bestätigte Deletes denselben nativen Submit-Pfad wie andere Admin-Formulare und blockt Mehrfach-Saves im Paket-Modal. | Tabellen-Editor und Paket-/Aboverwaltung verlieren damit mehrere stille Fehlerklassen zugleich: beschädigte Hidden-JSON-Werte oder stale Tabellenlinks kippen nicht mehr in leere Neuformulare bzw. falsche Delete-Erfolge, Abo-Settings und Pakete melden fehlgeschlagene Writes sauber fail-closed statt technischer „Alles gut“-Illusionen und Paket-Delete-/Modal-Aktionen laufen nicht mehr an direkten `form.submit()`-Bypässen oder Doppelklick-POSTs vorbei. Zusammen mit den nachgezogenen Admin-/Asset-Audit-Mappen steigt der dokumentierte Aggregatstand damit leicht auf **95,58 / 92,98 / 96,78 / 96,88**. |

### Delta Folge-Batch 583

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-content-editor.js`, `CMS/assets/js/admin-comments.js`, `CMS/admin/comments.php`, `CMS/assets/js/admin-site-tables.js`, `CMS/admin/modules/subscriptions/OrdersModule.php`, `CMS/admin/views/subscriptions/orders.php`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/AdminAudit-Abos.md`, `DOC/audit/AdminAudit-Tabellen.md`, `DOC/audit/AdminAudit-Kommentare.md`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/AssetAudit-Tabellen.md`, `DOC/audit/AssetAudit-Abos.md`, `DOC/audit/AssetAudit-Kommentare.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Shared-Content-/Kommentare-/Tabellen-/Abos-Cluster staffelt mehrere Mutationspfade jetzt enger an native Browser- und Capability-Verträge: `admin-content-editor.js` reicht Saves nach erfolgreicher Editor.js-Serialisierung validierungswahrend weiter, `admin-comments.js` bestätigt Bulk-`delete` explizit und sendet Einzelaktionen nicht länger per direktem `form.submit()`, `comments.php` spiegelt spezifische Status-/Delete-/Bulk-Rechte schon am Entry, `admin-site-tables.js` nutzt für Hidden-Dispatch denselben nativen Submit-Pfad, ruft den Shared-Alert korrekt an und behandelt Unicode-Grenzen codepoint-sicher, während `OrdersModule` Statuswechsel konditional gegen den tatsächlich noch unveränderten DB-Status schreibt und die Orders-View Delete-/Assign-Flows gegen Doppel- und Bypass-Submits härtert. | Seiten-/Beitrags-Saves verlieren damit einen verbleibenden nativen Submit-Bypass, Kommentar-Moderation und Tabellen-Dispatch bleiben näher am gemeinsamen Admin-Formvertrag und der Orders-Admin kippt weder bei Doppelklicks noch bei konkurrierenden Statuswechseln in stille Race-/Bypass-Zustände. Zusammen mit den nachgezogenen Admin-/Asset-Audit-Mappen steigt der dokumentierte Aggregatstand damit leicht auf **95,53 / 92,96 / 96,71 / 96,82**. |

### Delta Folge-Batch 576

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/seo/SeoSuiteModule.php`, `CMS/admin/views/seo/meta.php`, `CMS/admin/views/seo/sitemap.php`, `CMS/admin/views/seo/audit.php`, `CMS/core/Services/IndexingService.php`, `DOC/audit/AdminAudit-SEO.md`, `DOC/audit/AssetAudit-SEO.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der SEO-Cluster staffelt Audit-, Broken-Link-, Indexing- und Diagnosepfade jetzt über robustere Runtime-Verträge: `SeoSuiteModule` normalisiert `schema_type` und `twitter_card` fail-closed auf erlaubte Werte, erkennt Same-Site-Absolute-URLs über konfigurierte plus aktuelle Runtime-Autoritäten für Broken-Link-Scan und Manual-Indexing, bereinigt interne Referrer aus der Backlink-Domain-Übersicht und die Views für Meta/Sitemap sowie `IndexingService` bauen öffentliche SERP-, Sitemap- und IndexNow-Status-URLs runtime-aware statt an starrer `SITE_URL`-Basis. | SEO-Audit-Edits verlieren damit freie Enum-Schattenwerte, Proxy-/Alternativhost-Setups kippen interne Public-URLs nicht mehr in falsche Broken-Link- oder Indexing-Ablehnungen, und Analytics-/Backlink-Zahlen zählen eigene Referrer nicht länger als scheinbar externe Domains mit. Gleichzeitig bleiben SERP-, Sitemap-, robots- und Keyfile-Hinweise im Admin näher an der tatsächlichen Public-Origin der laufenden Installation. Die Aggregate steigen damit leicht auf **95,48 / 92,91 / 96,66 / 96,77**. |

### Delta Folge-Batch 575

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Services/CmsAuthPageService.php`, `CMS/views/auth/cms-auth.php`, `CMS/core/Routing/PublicRouter.php`, `CMS/member/includes/class-member-controller.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AssetAudit-Design.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Design-/Auth-Cluster staffelt CMS-Loginpage-, Public-Auth- und Member-Passkey-Pfade jetzt über robustere Runtime-Verträge: `CmsAuthPageService` behandelt fehlgeschlagene Settings-Writes fail-closed und erzeugt Passwort-Reset-Links locale-aware über `getPublicUrl(...)`, die öffentliche Auth-View nutzt für Passkey-Login einen request-submit-basierten Submit-Lock statt direkten `form.submit()`-Bypass, `PublicRouter` zieht MFA-Markup auf hostneutrale CSS-Pfade und locale-aware Dokumentsprachen zusammen und Public-/Member-Passkey-Payloads werden mit zusätzlichen JSON-HEX-Flags script-sicher encodiert. | Loginpage-Saves verlieren damit stille Persistenzfehler und Passwort-Reset-/MFA-Roundtrips bleiben konsistent zu Locale-, Slug- und Same-Origin-Verträgen, statt an harten Public-Pfaden, `SITE_URL`-Assets oder `lang=de`-Resten zu hängen. Parallel blockt der Passkey-Login Doppel-Submits näher am nativen Browser-Submit und Inline-/Datenattribut-Payloads schleppen keine unnötig rohen Script-Zeichenkonstellationen mehr mit. Die Aggregate steigen damit leicht auf **95,44 / 92,90 / 96,62 / 96,73**. |

### Delta Folge-Batch 574

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/landing/LandingPageModule.php`, `CMS/core/Services/Landing/LandingRepository.php`, `CMS/core/Services/Landing/LandingPluginService.php`, `CMS/admin/views/landing/page.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AssetAudit-Design.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Landing-Page-Cluster staffelt Save-, Feature- und Plugin-Pfade jetzt über robustere Runtime-Verträge: `LandingPageModule` meldet fehlgeschlagene Header-/Content-/Footer-/Feature-/Plugin-Saves nicht länger als Erfolg, `LandingRepository` bindet Feature-Updates strikt an echte `feature`-Datensätze und unbekannte Feature-Deletes an betroffene Zeilen, `LandingPluginService` erlaubt core-seitige Plugin-Overrides auch ohne optionalen `settings_callback` und die Landing-View hängt destruktive Feature-Löschungen an den gemeinsamen Confirm-Vertrag aus `admin.js`. | Landing-Saves verlieren damit mehrere stille Schein-Erfolgs- und Mutationsbrüche zugleich: Persistenzfehler bleiben im Admin sichtbar fail-closed, manipulierte `feature_id`-POSTs können keine fremden Landing-Sektionen mehr überschreiben, registrierte Landing-Plugins behalten ihre `enabled`-/`sort_order`-Overrides auch ohne Zusatz-Settings-UI und Feature-Deletes laufen nicht mehr ohne vorgeschaltete Bestätigung direkt in den POST. Die Aggregate steigen damit leicht auf **95,40 / 92,89 / 96,58 / 96,69**. |

### Delta Folge-Batch 573

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/FontManagerModule.php`, `CMS/assets/js/admin-font-manager.js`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AssetAudit-Design.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Font-Manager staffelt Scan-, Delete- und Submit-Pfade jetzt über robustere Runtime-Verträge: Theme-Scan-Caches werden per echtem Upsert persistiert statt über einen wirkungslosen `UPDATE`-Scheinpfad verloren zu gehen, unbekannte `font_id`-Löschungen liefern explizite Fehler statt stiller Erfolge und das Design-Asset trennt Pending-State und `requestSubmit()`-Flow so, dass Confirm-Löschungen nicht mehr vom eigenen Submit-Guard abgefangen werden. | Theme-Scans können damit ihren Cache tatsächlich wiederverwenden, stale oder manipulierte Delete-IDs wirken nicht mehr wie erfolgreich entfernte Fonts und Delete-/Save-Aktionen bleiben auch im Confirm-Pfad belastbar gegen Selbstblockaden und Doppelaktionen. Der Design-Cluster gewinnt dadurch zugleich an Performance, Datenintegrität und UI-Robustheit; die Aggregate steigen leicht auf **95,36 / 92,88 / 96,55 / 96,65**. |

### Delta Folge-Batch 572

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/menus/MenuEditorModule.php`, `CMS/assets/js/admin-menu-editor.js`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AssetAudit-Design.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Menü-Editor staffelt Save- und Delete-Pfade jetzt über robustere Runtime-Verträge: fehlerhaftes `items`-JSON wird fail-closed mit explizitem Fehler statt als leeres Array behandelt, Menü-Löschungen laufen über korrekt parametrisierte Prepared-Statement-Deletes in einer Transaktion und das Design-Asset zieht für Save-, Delete- und Modal-Formulare einen gemeinsamen Submit-Lock mit nativer `requestSubmit()`-Weiterleitung nach. | Menü-Items können damit nicht mehr durch kaputte oder manipulierte JSON-Payloads still geleert werden, der Delete-Flow scheitert nicht länger an ungebundenen Placeholder-Queries und hektische Mehrfachklicks erzeugen keine doppelten Menü-Requests mehr. Der Design-Cluster gewinnt damit zugleich an Datenintegrität, Admin-Robustheit und UI-Stabilität; die Aggregate steigen leicht auf **95,33 / 92,85 / 96,50 / 96,60**. |

### Delta Folge-Batch 571

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-editor.php`, `CMS/admin/views/themes/customizer-missing.php`, `CMS/admin/theme-explorer.php`, `CMS/admin/modules/themes/ThemeEditorModule.php`, `CMS/themes/cms-default/admin/customizer/helpers.php`, `CMS/themes/cms-default/admin/customizer/partials/page.php`, `CMS/themes/cms-default/admin/customizer/partials/field.php`, `CMS/themes/cms-default/admin/customizer/partials/scripts.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AssetAudit-Design.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Design-Cluster staffelt Theme-Editor, Explorer und den eingebetteten `cms-default`-Customizer jetzt über engere Runtime-Verträge: eingebettete Customizer-Dateien werden zusätzlich über Byte-Limit und Binärcheck fail-closed validiert, der Explorer meldet überlange Dateipfade explizit statt sie still zu kürzen und behandelt nicht lesbare Theme-Dateien konsistent als read-only/übersprungen, während der Runtime-Customizer interne Logo-Assets hostneutral relativ speichert, sichere `/uploads/...`-Pfade bis in die Preview akzeptiert und Bild-Reset-/Leerzustände DOM-basiert statt über verbleibende Stringpfade rendert. | Theme-Editor und Explorer verlieren damit mehrere stille Fehlzustände zugleich: unsichere oder unrealistisch große Customizer-Dateien laden nicht mehr inline, Dateiauswahl kippt nicht mehr per Truncation auf ein anderes Ziel, und nicht lesbare Theme-Dateien wirken weder im Baum noch im Editor fälschlich bearbeitbar. Parallel bleibt der eingebettete Theme-Customizer auch bei internen Logo-Pfaden, Bild-Reset und Preview-Empty-State näher an denselben hostneutralen und DOM-basierten Verträgen wie der restliche gehärtete Design-Admin; die Aggregate steigen leicht auf **95,28 / 92,84 / 96,42 / 96,54**. |

### Delta Folge-Batch 570

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-marketplace.php`, `CMS/admin/modules/themes/ThemeMarketplaceModule.php`, `CMS/admin/views/themes/marketplace.php`, die neue `DOC/audit/AssetAudit-Marketplace.md`, `DOC/audit/AdminAudit-Themes.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Theme-Marketplace staffelt Katalogquellen jetzt über frischen Cache, Remote-Lookup, stale Cache und lokalen Index statt jeden Aufruf ungefedert direkt ins Netz zu schicken; Installationen prüfen bereits installierte Themes explizit gegen den echten lokalen Bestand und geben Fehler samt Detail-/Report-Payload über den Shared-Flash-Vertrag strukturiert zurück. Parallel spiegelt die View Hosts, Paket-, Manifest- und Archivgrenzen, SHA-256-Kurzstatus, Host-/Archiv-Blocker und Kompatibilität deutlich direkter, und die bislang nur im Index referenzierte Marketplace-Asset-Arbeitsmappe liegt nun real vor. | Theme-Install- und Fallback-Pfade kippen damit nicht mehr still in schwer nachvollziehbare Remote- oder Zielzustände: Admins sehen sofort, ob Cache, Remote oder lokaler Index greift, warum ein Theme nur manuell installierbar ist und mit welchem Ziel-/Hash-/Constraint-Kontext ein Fehler auftritt. Der Cluster gewinnt dadurch sowohl an Betriebsrobustheit als auch an Diagnose- und UI-Transparenz; die Aggregate steigen leicht auf **95,24 / 92,79 / 96,37 / 96,49**. |

### Delta Folge-Batch 569

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/posts/PostsModule.php`, `CMS/admin/post-tags.php`, `CMS/admin/views/posts/tags.php`, `CMS/admin/views/posts/list.php`, `CMS/admin/views/posts/edit.php`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/AssetAudit-Beitraege.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Beiträge-Cluster staffelt Taxonomie- und Listenpfade jetzt auf konsistentere Runtime-Verträge: Kategorie- und Tag-Slugs werden vor Persistenz fail-closed normalisiert und gegen stale Edit-IDs bzw. Duplicate-Slugs geprüft, der Tag-Admin spiegelt seinen vorhandenen Modul-Updatepfad jetzt als echten Edit-Roundtrip im UI und destruktive Bulk-Löschungen der Beitragsliste laufen nicht mehr ohne explizite Bestätigung an den Server. Parallel liegt mit `AssetAudit-Beitraege.md` erstmals eine eigene Asset-Arbeitsmappe für postspezifische Grid-/Modal-/Editor-Integrationen vor. | Der Beiträge-Admin verliert damit gleich mehrere stille Bruchstellen zwischen Modul, View und UX-Vertrag: Taxonomie-Edits kippen nicht mehr in späte DB-Kollisionen oder falsche Erfolgsmeldungen, Tags lassen sich im Admin sauber bearbeiten statt nur indirekt ersetzen, und Bulk-Deletes springen nicht mehr ohne Sicherheitsbremse direkt in den POST. Die Aggregate steigen für diesen kleinen Beiträge-/Runtime-Batch leicht auf **95,20 / 92,74 / 96,33 / 96,44**. |

### Delta Folge-Batch 568

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Services/MediaService.php`, `CMS/core/Services/Media/MediaRepository.php`, `CMS/core/Services/Media/UploadHandler.php`, `DOC/audit/AdminAudit-Medien.md`, `DOC/audit/AssetAudit-Medien.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Medien-Cluster staffelt Settings-, Meta-, Upload- und Rename-Pfade jetzt auf robustere Datei- und Integritätsverträge: Media-Settings, Upload-`.htaccess` und `media-meta.json` laufen über atomische Same-Directory-Swaps mit SHA-512-Integritätscheck, beschädigte Meta-Strukturen werden defensiver normalisiert, Kategoriezuweisungen an reale Dateien gebunden und Hidden-Namen sowie Dateiendungswechsel beim Rename fail-closed blockiert. | Medien-Konfiguration und Bibliotheks-Meta kippen damit nicht mehr still auf halbfertige Dateistände, während Upload-/Rename-Flows keine nachträglichen Hidden-File- oder Extension-Bypässe mehr öffnen. Admin- und Asset-Audit halten den neuen Medien-Vertrag jetzt konsistent fest; die Aggregate steigen für diesen sicherheits- und robustheitslastigen Follow-up leicht auf **95,18 / 92,74 / 96,30 / 96,42**. |

### Delta Folge-Batch 567

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/ThemeEditorModule.php`, `CMS/admin/views/themes/editor.php`, `CMS/assets/js/admin-theme-explorer.js`, `CMS/admin/modules/themes/FontManagerModule.php`, `CMS/admin/views/themes/fonts.php`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/AssetAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md`, `CMS/update.json`, `CMS/core/Version.php` | umgesetzt | Der Design-Cluster staffelt Theme-Explorer- und Font-Manager-Pfade jetzt auf robustere Datei- und Asset-Verträge: Theme-Dateien werden atomisch mit SHA-512-Check gespeichert, Binary-Ziele lassen sich nicht mehr per direktem POST überschreiben, Explorer-Tree-Datenattribute werden direkt gerendert und der Font-Manager schreibt Self-Hosting-Assets ebenfalls atomisch, speichert das reale Primärformat und räumt CSS-verknüpfte Font-Dateien beim Delete mit auf. | Theme-Dateiänderungen verlieren damit einen stillen Korruptions- und Direct-POST-Overwrite-Pfad, während lokale Google-Font-Familien nicht mehr mit falschem Format-Metadatum oder liegengebliebenen WOFF-/TTF-Resten enden. Parallel bleibt der Explorer-Suchvertrag im Asset näher am echten DOM statt an String-Umbauten zu hängen. Die Aggregate bleiben für diesen kleinen Design-/Asset-Batch stabil. |

### Delta Folge-Batch 566

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Services/UpdateService.php`, `CMS/admin/theme-marketplace.php`, `CMS/admin/modules/themes/ThemeMarketplaceModule.php`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AdminAudit-Plugins.md`, `DOC/audit/AdminAudit-Themes.md`, `DOC/audit/AdminAudit-System.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Update-/Marketplace-Cluster staffelt absolute und relative Remote-Ziele jetzt über denselben strikten HTTPS-/Host-/Port-/Pfad-Vertrag, statt relative Katalog-/Manifest-/Download-Pfade im UpdateService noch lockerer an erlaubte Hosts zu hängen. Parallel zieht der Theme-Marketplace Slug-Längenlimit, Katalog-Spiegel, erlaubte ZIP-Endungen und Paketgrößen für Auto-Installationen nach. | Core-, Plugin- und Theme-Updates akzeptieren damit keine halboffenen Remote-Ziele mit Credentials, Fremdports, Fragmenten oder Traversal-artigen Pfaden mehr; Theme-Installationen bleiben zusätzlich auf plausible Slugs und Pakete begrenzt, statt problematische Archive still bis in den Installer durchzureichen. Die Aggregate bleiben für diesen kleinen Remote-/Marketplace-Batch stabil. |

### Delta Folge-Batch 565

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/cookie-manager.php`, `CMS/admin/data-requests.php`, `CMS/admin/privacy-requests.php`, `CMS/admin/deletion-requests.php`, `CMS/admin/documentation.php`, `CMS/admin/support.php`, `CMS/admin/system-info.php`, `CMS/admin/error-report.php`, `CMS/admin/hub-sites.php`, `CMS/admin/settings.php`, `CMS/admin/table-of-contents.php`, `CMS/admin/updates.php`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AdminAudit-Recht.md`, `DOC/audit/AdminAudit-Info.md`, `DOC/audit/AdminAudit-Diagnose.md`, `DOC/audit/AdminAudit-System.md`, `DOC/audit/AdminAudit-Hub.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Mehrere Legacy-Admin-Entrys im Rechts-, Info-, Diagnose-, System- und Hub-Cluster verließen sich noch auf generische `isAdmin()`-Guards, obwohl Zielseiten, Aliasrouten und Mutationspfade bereits enger an `manage_settings` bzw. `manage_system` hängen. Der Batch zieht diese Entrys jetzt auf explizite Capability-Helfer samt fail-closed Post-Handlern und Alias-Checks zusammen. | Cookie-Manager, DSGVO-Anfragen, Doku-/Info-Aliasse, Fehlerreport, Hub-Sites, globale Einstellungen, TOC-Konfiguration und Updates geraten damit nicht mehr in halboffene Admin-Oberflächen oder aliasbasierte Umgehungswege. Lese- und Mutationspfade spiegeln jetzt denselben Capability-Vertrag wie ihre Zielmodule; die Aggregate bleiben für diesen kleinen RBAC-/Access-Control-Batch stabil. |

### Delta Folge-Batch 564

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/editor-init.js`, `CMS/assets/js/cookieconsent-init.js`, `CMS/assets/js/gridjs-init.js`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AdminAudit-Recht.md`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/AssetAudit-Grid.md`, `DOC/audit/AssetAudit-Recht.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die gemeinsame Editor.js-Brücke ersetzt verbleibende Stringpfade in Legacy-HTML-Import und Bildpicker durch `DOMParser` und DOM-Knoten; der Consent-Dialog baut Banner, Modal und Kategorien jetzt DOM-basiert; der Grid-Helfer escaped Zelltexte ohne `innerHTML`-Roundtrip. | Shared-Editor, Public-Consent und Grid-Formatter bleiben damit stringfrei und näher an einem fail-closed First-Party-DOM-Vertrag. Parallel ist der Legal-/Consent-Asset-Strang jetzt als eigene `AssetAudit-Recht.md` dokumentiert. Die Aggregate bleiben für diesen kleinen Editor-/Legal-/Grid-Batch stabil. |

### Delta Folge-Batch 563

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-seo-editor.js`, `CMS/assets/js/admin-plugin-marketplace.js`, `CMS/assets/js/admin-theme-marketplace.js`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AdminAudit-SEO.md`, `DOC/audit/AdminAudit-Plugins.md`, `DOC/audit/AdminAudit-Themes.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/AssetAudit-SEO.md`, `DOC/audit/AssetAudit-Marketplace.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Shared-SEO-Editor ersetzt verbleibende HTML-Fallback- und Resetpfade durch `DOMParser` und DOM-Clear-Helper; Plugin- und Theme-Marketplace schalten Pending-Texte jetzt textbasiert statt über `innerHTML`. | SEO-Regellisten, HTML-Analyse und Marketplace-Install-Buttons bleiben damit stringärmer und näher an einem fail-closed First-Party-DOM-Vertrag. Die Aggregate bleiben für diesen kleinen SEO-/Marketplace-Batch stabil. |

### Delta Folge-Batch 562

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-hub-site-edit.js`, `CMS/assets/js/admin-hub-template-edit.js`, `CMS/assets/js/admin-hub-template-editor.js`, `CMS/assets/js/admin-content-editor.js`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AdminAudit-Hub.md`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/AssetAudit-Hub.md`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Hub-Site-/Template-Editoren und der gemeinsame Content-Editor ersetzen verbleibende First-Party-`innerHTML`-/`insertAdjacentHTML`-Render- und Resetpfade durch DOM-Clear-Helper, echte Knoten und `DOMParser`-basierte Vorschautext-Extraktion. | Hub-Karten, Template-Quicklinks, TOC-, Section- und Starter-Card-Vorschauen sowie Preview-/Diff-/Holder-Zustände im Shared-Editor bleiben damit stringfrei und näher an einem fail-closed DOM-Vertrag. Die Aggregate bleiben für diesen kleinen Hub-/EditorJS-Batch stabil. |

### Delta Folge-Batch 561

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-menu-editor.js`, `CMS/assets/js/admin-media-integrations.js`, `CMS/assets/js/member-dashboard.js`, `CMS/assets/js/admin-site-tables.js`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AdminAudit-Medien.md`, `DOC/audit/AdminAudit-Member.md`, `DOC/audit/AdminAudit-Tabellen.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/AssetAudit-Design.md`, `DOC/audit/AssetAudit-Medien.md`, `DOC/audit/AssetAudit-Tabellen.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Menü-Editor, Medienbibliothek, Member-Upload und Tabellen-Editor ersetzen verbleibende First-Party-`innerHTML`-Render- bzw. Resetpfade durch DOM-Clear-Helper und echte Knoten für Listen-, Preview-, Alert-, Select- und Empty-State-Rendering. | First-Party-Admin-Assets halten ihre Renderpfade damit konsistenter fail-closed und stringfrei: Menülisten, Picker-/Upload-Zustände, Member-Ergebnislisten und Tabellen-Rebuilds hängen nicht mehr an verbleibenden `innerHTML`-Sonderpfaden. Die Aggregate bleiben für diesen kleinen Asset-/DOM-Batch stabil. |

### Delta Folge-Batch 560

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/users/UsersModule.php`, `CMS/admin/views/partials/featured-image-picker.php`, `CMS/admin/modules/menus/MenuEditorModule.php`, `CMS/core/TableOfContents.php`, `CMS/core/CacheManager.php`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AdminAudit-Benutzer.md`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AdminAudit-Performance.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/AssetAudit-Medien.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Benutzer-Report-Quellen und der gemeinsame Featured-Image-Picker nutzen für interne Admin-/Media-Ziele jetzt hostneutrale relative `/admin/...`- bzw. `/api/media`-Pfade statt `SITE_URL`-gebundener Ziele; parallel baut der Picker Preview/Grid nur noch über DOM-Knoten auf, `MenuEditorModule` zieht explizite `mb_*`-/Core-PHP-Fallbacks nach und `TableOfContents` sowie `CacheManager` ersetzen verbleibende Legacy-`md5()`-/`sha1()`-Pfadreste durch sichere Random-/`hash(...)`-Varianten. | Benutzer-Reports, Page-/Post-Featured-Image-Auswahl, Menü-Saves, TOC-Navigationsanker und Cache-Datei-/ETag-Pfade bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung bzw. auf PHP-Setups ohne geladene `mbstring`-Extension belastbar, ohne feste Host-Basen, stringbasiertes DOM-Markup oder veraltete Hashfunktionen mitzuschleppen. Die Aggregate bleiben für diesen kleinen Shared-Follow-up stabil. |

### Delta Folge-Batch 559

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/error-report.php`, `CMS/admin/partials/post-action-shell.php`, `CMS/admin/partials/redirect-alias-shell.php`, `CMS/admin/views/partials/flash-alert.php`, `CMS/core/Debug.php`, `CMS/core/Services/ErrorReportService.php`, `CMS/admin/views/system/info.php`, `CMS/admin/modules/themes/FontManagerModule.php`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AdminAudit-Info.md`, `DOC/audit/AdminAudit-Diagnose.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der gemeinsame Fehlerreport-/Diagnose-Flow nutzt für Report-Buttons, POST-Ziele, Default-Redirects und gespeicherte `source_url`-Werte jetzt hostneutrale relative `/admin/...`-Pfade statt an `SITE_URL` gebundener Ziele; parallel ziehen `ErrorReportService`, `FontManagerModule` und `CMS/admin/views/system/info.php` explizite `mb_*`-/Core-PHP-Fallbacks nach. | Fehlerreports, Debug-Reports, Info-Badges und Font-Scans bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Admin-Kontext und auf PHP-Setups ohne geladene `mbstring`-Extension belastbar, statt auf eine feste Origin zu springen oder an optionalen String-Helfern fatal auszusteigen. Die Aggregate bleiben für diesen kleinen Diagnose-/Info-/Design-Batch stabil. |

### Delta Folge-Batch 558

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/post-categories.php`, `CMS/admin/post-tags.php`, `CMS/admin/table-of-contents.php`, `CMS/admin/updates.php`, `CMS/admin/subscription-settings.php`, `CMS/admin/settings.php`, `CMS/admin/packages.php`, `CMS/admin/menu-editor.php`, `CMS/admin/pages.php`, `CMS/admin/views/toc/settings.php`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/AdminAudit-Abos.md`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AdminAudit-System.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Mehrere verbliebene Admin-Entrys und der TOC-Save-Pfad nutzen für Access-Denied-Fallbacks, PRG-Redirects und interne Formularziele jetzt hostneutrale relative `/`- bzw. `/admin/...`-Routen statt an `SITE_URL` gebundener Ziele; parallel ist der Routing-Nachzug in den Bereichsaudits und der Release-Doku festgehalten. | Kategorien-, Tags-, Inhaltsverzeichnis-, Settings-, Updates-, Pakete-, Abo-Settings-, Menü- und Seiten-Roundtrips bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung auf derselben Origin, statt bei Guard- oder Save-Pfaden ausgerechnet auf eine feste Hostbasis zu kippen. Die Aggregate bleiben für diesen kleinen Routing-/Doku-Batch stabil. |

### Delta Folge-Batch 557

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/subscriptions/OrdersModule.php`, `CMS/core/Services/DashboardService.php`, `CMS/core/SchemaManager.php`, `CMS/admin/orders.php`, `CMS/admin/views/subscriptions/orders.php`, `CMS/orders.php`, `CMS/member/includes/class-member-controller.php`, `CMS/member/subscription.php`, `DOC/audit/AdminAudit-Abos.md`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AdminAudit-Dashboard.md`, `DOC/audit/AdminAudit-Member.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Abo-/Orders-Cluster nutzt jetzt einen kanonischen Bestellvertrag mit Statusaliasen (`confirmed/completed` → `paid`), fail-closed Statuswechseln, runtime-sicherer Schema-Nachpflege und normalisierten Kunden-/Betragsfeldern über Admin, Checkout, Dashboard und Member-Historie hinweg; parallel liegt dafür erstmals eine eigene Admin-Audit-Datei vor. | Bestelllisten, Umsatz-KPIs, Checkout-Anlagen, manuelle Zuweisungen und Member-Bestellhistorien laufen damit auch auf älteren Installationen mit gemischtem Orders-Schema wieder auf demselben Datenmodell, statt zwischen `total_amount/amount`, `email/customer_email` und Legacy-Statuswerten auseinanderzufallen. Die Aggregate bleiben für diesen größeren Runtime-/Dokubatch stabil. |

### Delta Folge-Batch 466

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Services/DashboardService.php`, `DOC/audit/AdminAudit-*.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Für den neu gestarteten Admin-Gesamtaudit wurde eine vollständige Arbeitsmappe unter `DOC/audit/AdminAudit-*.md` angelegt; parallel verweist der Dashboard-Hinweis `HTTPS nicht aktiv` jetzt nicht länger auf die tote Schattenroute `/admin/system`, sondern auf den realen Prüfpfad `/admin/security-audit`. | Die weitere Admin-Prüfung kann jetzt top-down auf einer sauberen Dokumentationsbasis weiterlaufen, und der erste Dashboard-Attention-Fund endet nicht mehr in einer ungültigen Admin-Zielroute. Die zuletzt ausgewiesenen Aggregate aus Batch 465 bleiben für diesen kleinen Routing-/Dokubatch unverändert belastbar. |

### Delta Folge-Batch 467

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/posts.php`, `CMS/core/Routing/ApiRouter.php`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Beiträge-Bereich verlangt jetzt bereits am Entry und am Grid-JSON-Endpoint dieselbe Capability `edit_all_posts`, statt Admin-Nutzer erst nach sichtbarem Listen-/Edit-Kontext an späteren Action- oder AI-Guards scheitern zu lassen. | Beitragsverwaltung verhält sich konsistenter zu Seiten und zu den vorhandenen Write-/AI-Verträgen: Benutzer ohne passende Capability landen nicht mehr in einer halboffenen Oberfläche mit nachgelagerten Berechtigungsabbrüchen. Die Gesamtkennzahlen bleiben für diesen kleinen RBAC-Batch praktisch stabil. |

### Delta Folge-Batch 468

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-menu-editor.js`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/AssetAudit-Design.md`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Design-/Asset-Audit zieht jetzt eine erste eigene `AssetAudit*.md`-Arbeitsmappe auf und harmonisiert den Menü-Editor-Client mit dem Backend-Vertrag: slug-basierte interne Pfade ohne führenden Slash sowie leere Home-/Parent-URLs werden im Browser nicht länger fälschlich blockiert. | Der Menü-Editor verliert einen echten UI-Blocker für Container-Menüs, Home-Aliasse und interne Slug-Ziele; parallel ist die Asset-Prüfung für Design-Assets und Editor.js-Flows jetzt separat dokumentiert und kann wie die Admin-Audits fortlaufend weitergeführt werden. Die Aggregate bleiben für diesen kleinen JS-/Doku-Batch stabil. |

### Delta Folge-Batch 469

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-content-editor.js`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der gemeinsame Editor.js-Admin-Client räumt beim manuellen `DE → EN`-Kopierflow jetzt eine bestehende AI-Preview-/Diff-Karte sofort weg, statt veraltete `Übernehmen`-/`Verwerfen`-Aktionen neben bereits frisch kopiertem EN-Inhalt stehen zu lassen. | Page- und Post-Editoren verlieren damit einen stillen Zustandsbruch zwischen AI-Preview und manueller Kopie: Redakteure können die EN-Fassung nicht mehr versehentlich über einen stale Vorschlagszustand zurücküberschreiben. Die Aggregate bleiben für diesen kleinen Shared-Asset-Batch stabil. |

### Delta Folge-Batch 470

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-font-manager.js`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AssetAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Font-Manager-Delete-Flow übergibt an `requestSubmit()` keinen Nicht-Submit-Button mehr als fehlerhaften Submitter, sondern nutzt für Confirm-Löschungen sauber den nativen Formular-Submit ohne unzulässigen Trigger. | Lokale Font-Löschungen scheitern damit in modernen Browsern nicht mehr an einem reinen Asset-/Submitter-Vertragsbruch zwischen Confirm-Dialog, `type="button"` und `requestSubmit()`. Die Aggregate bleiben für diesen kleinen Design-/Asset-Batch stabil. |

### Delta Folge-Batch 471

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/ThemeEditorModule.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AssetAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Theme-Explorer zieht erkannte Binärdateien jetzt konsequent auf denselben Readonly-Vertrag wie andere unsicher bearbeitbare Dateien: Sobald ein erlaubter Dateityp Binärdaten enthält, sperrt das Modul den Save-Zustand statt nur eine Warnung über leerem Editor-Inhalt anzuzeigen. | Der Explorer verliert damit einen stillen Überschreibungsfehler zwischen Modul, View und Asset: Binäre Theme-Dateien mit erlaubter Endung können nach dem Laden nicht mehr versehentlich leer zurückgespeichert werden, nur weil der Editor den Inhalt aus Sicherheitsgründen zuvor entfernt hat. Die Aggregate bleiben für diesen kleinen Design-/Explorer-Batch stabil. |

### Delta Folge-Batch 472

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/landing/LandingPageModule.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Landing-Page-Tab `design` liefert gespeicherte Farb- und Layoutwerte jetzt wieder in derselben flachen Struktur aus, die `CMS/admin/views/landing/page.php` beim Rendern erwartet, statt `design` und `colors` noch einmal unter `design` zu verschachteln. | Landing-Farben und Layoutoptionen wirken im Admin damit nicht länger wie „gespeichert, aber verschwunden“: Nach einem erfolgreichen Save zeigt der Design-Tab beim Reload wieder die tatsächlich persistierten Werte statt fälschlicher Defaults. Die Aggregate bleiben für diesen kleinen Design-Batch stabil. |

### Delta Folge-Batch 473

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-content-editor.js`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der gemeinsame Editor.js-Submit-Handler blockt Folge-Submits jetzt explizit, sobald bereits eine Save-Serialisierung läuft, statt bei aktivem `submitLocked` ohne `preventDefault()` aus dem Event zurückzufallen. | Seiten- und Beitragseditoren verlieren damit einen echten Race-Condition-Pfad im Shared-Asset: Hektische Doppelklicks oder doppelte Submit-Auslöser schicken nicht mehr parallel einen zweiten nativen POST los, während der erste Request noch die Editor.js-Daten einsammelt. Die Aggregate bleiben für diesen kleinen Shared-Asset-Batch stabil. |

### Delta Folge-Batch 474

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/themes/cms-loginpage.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die CMS-Loginpage-View rendert Shell-basierte Flash-/Fehlerhinweise jetzt wieder sichtbar über das gemeinsame `flash-alert.php`, statt Save-Erfolge, CSRF-Ablehnungen und Validierungsfehler nach dem PRG-Flow still ins Leere laufen zu lassen. | Der Loginpage-Admin verliert damit einen echten UX-/Runtime-Bruch: Einstellungen wirken nach dem Speichern nicht mehr wie kommentarlos „nichts passiert“, sondern melden Erfolg oder Fehler wieder belastbar direkt am Formular. Die Aggregate bleiben für diesen kleinen Design-/View-Batch stabil. |

### Delta Folge-Batch 475

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/themes/cms-loginpage.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Das CMS-Loginpage-Formular postet jetzt hostneutral an dieselbe Admin-Route, statt per hart verdrahtetem `SITE_URL`-Action-Ziel von der aktuellen Laufzeit-URL wegzuspringen. | Loginpage-Saves bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Admin-Kontext, statt versehentlich auf einen abweichenden Host oder eine falsche Basis-URL zu kippen. Die Aggregate bleiben für diesen kleinen Design-/Formular-Batch stabil. |

### Delta Folge-Batch 476

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/landing/LandingPageModule.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Landing-Design-Tab liefert an die View wieder gemeinsam `design`- und `colors`-Daten aus, statt im Live-Stand nur das Layout-Array zu reichen und alle Farbfelder implizit in ihre Fallback-Defaults laufen zu lassen. | Gespeicherte Landing-Farbwerte bleiben nach Reload und PRG-Redirect jetzt wieder sichtbar im Admin, statt wie „nicht gespeichert“ zu wirken. Die Aggregate bleiben für diesen kleinen Design-/Landing-Batch stabil. |

### Delta Folge-Batch 477

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/landing/page.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die Landing-Tab-Navigation verwendet jetzt die bereits vorbereiteten relativen Admin-Routen direkt, statt sie erneut an `SITE_URL` zu hängen und damit den aktuellen Host zu verhärten. | Tab-Wechsel innerhalb des Landing-Admins bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung auf dem aktuellen Host, statt unbeabsichtigt auf eine andere Basis-URL zu springen. Die Aggregate bleiben für diesen kleinen Design-/Navigation-Batch stabil. |

### Delta Folge-Batch 478

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Services/CmsAuthPageService.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die CMS-Loginpage speichert interne Logo-Pfade jetzt hostneutral, statt `/uploads/...` beim Persistieren in eine feste `SITE_URL`-Absolute umzuschreiben. | Auth-Logos bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung auf der aktuellen Origin erreichbar, statt unnötig auf einen abweichenden Host verdrahtet zu werden. Die Aggregate bleiben für diesen kleinen Design-/Auth-Batch stabil. |

### Delta Folge-Batch 479

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/themes/editor.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Theme-Explorer nutzt für Dateibaum-Links jetzt eine hostneutrale relative Admin-Basis, statt Dateiklicks über eine an `SITE_URL` gehängte Explorer-URL zu routen. | Dateiwechsel im Theme-Explorer bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktiven Admin-Kontext, statt auf eine abweichende Origin zu springen. Die Aggregate bleiben für diesen kleinen Design-/Explorer-Batch stabil. |

### Delta Folge-Batch 480

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/themes/list.php`, `DOC/audit/AdminAudit-Themes.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die Theme-Verwaltung nutzt für die Buttons `Editor` und `Explorer` des aktiven Themes jetzt hostneutrale relative Admin-Routen statt an `SITE_URL` gebundener Links. | Der Sprung aus der Theme-Liste in Editor und Explorer bleibt damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Admin-Kontext, statt auf eine falsche Origin zu kippen. Die Aggregate bleiben für diesen kleinen Themes-/Navigation-Batch stabil. |

### Delta Folge-Batch 481

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-editor.php`, `CMS/admin/views/themes/customizer-missing.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der sichere Theme-Editor-Fallback nutzt für Theme-Verwaltung und Theme-Explorer jetzt hostneutrale relative Admin-Routen statt eines erneuten `SITE_URL`-Hardcodings im Schutzpfad. | Auch wenn ein Theme keinen sicheren Customizer bereitstellt, bleiben die Ausweichlinks im aktuellen Admin-Kontext und springen unter Proxy-, Alternativhost- oder lokaler Dev-Umgebung nicht mehr auf eine falsche Origin. Die Aggregate bleiben für diesen kleinen Design-/Fallback-Batch stabil. |

### Delta Folge-Batch 482

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-theme-marketplace.js`, `CMS/assets/js/admin-plugin-marketplace.js`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/AssetAudit-Marketplace.md`, `DOC/audit/AdminAudit-Themes.md`, `DOC/audit/AdminAudit-Plugins.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Theme- und Plugin-Marketplace erkennen bestätigte Install-Submits jetzt über den real freigegebenen Browser-Submit statt über das vom globalen Confirm-Handler bereits zurückgesetzte `confirmAccepted`-Flag; parallel zieht das Asset-Audit dafür einen eigenen Marketplace-Strang nach. | Bestätigte Installationen sperren ihre Buttons im Theme- und Plugin-Marketplace wieder zuverlässig gegen Doppel-Submits und zeigen den Pending-Zustand belastbar an, statt nach dem Confirm optisch in einen erneuten Klickpfad zurückzufallen. Die Aggregate bleiben für diesen kleinen Marketplace-/Asset-Batch stabil. |

### Delta Folge-Batch 483

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/plugins/marketplace.php`, `DOC/audit/AdminAudit-Plugins.md`, `DOC/audit/AssetAudit-Marketplace.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Plugin-Marketplace nutzt für den Rücksprung `Installierte Plugins` jetzt eine hostneutrale relative Admin-Route statt eines an `SITE_URL` gebundenen Links. | Der Wechsel aus dem Plugin-Marketplace zurück in die Plugin-Liste bleibt damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Admin-Kontext, statt auf eine falsche Origin zu springen. Die Aggregate bleiben für diesen kleinen Plugins-/Marketplace-Batch stabil. |

### Delta Folge-Batch 484

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-seo-redirects.js`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/AssetAudit-SEO.md`, `DOC/audit/AdminAudit-SEO.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der SEO-Redirect-/404-Client entfernt seinen lokalen Confirm-Submit-Doppelpfad und nutzt für `form[data-confirm-message]` wieder ausschließlich den gemeinsamen Confirm-Vertrag aus `admin.js`; parallel zieht das Asset-Audit dafür einen eigenen SEO-Strang nach. | Redirect-Löschungen und 404-Cleanup-Formulare geraten damit nicht länger in doppelte Confirm-/Re-Submit-Pfade zwischen Shared- und SEO-Asset, sondern laufen wieder über genau einen gemeinsamen Admin-Confirm-Flow. Die Aggregate bleiben für diesen kleinen SEO-/Asset-Batch stabil. |

### Delta Folge-Batch 485

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/redirect-manager.php`, `CMS/admin/not-found-monitor.php`, `CMS/admin/views/seo/redirects.php`, `CMS/admin/views/seo/not-found.php`, `CMS/admin/views/seo/subnav.php`, `DOC/audit/AssetAudit-SEO.md`, `DOC/audit/AdminAudit-SEO.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Redirect-Manager und 404-Monitor hängen ihre internen SEO-Admin-Wechsel, Subnav-Ziele, Quick-Actions und PRG-Rücksprünge nicht länger an `SITE_URL`; parallel liegen die globalen Confirm-Metadaten in `redirects.php` wieder auf den echten Löschpfaden statt versehentlich auf Quick-Save oder Toggle. | SEO-Navigation und Save-/Delete-Roundtrips bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung sauber im aktuellen Admin-Kontext, während Redirect-Erstellung nicht mehr fälschlich einen Löschdialog zeigt und destruktive Löschpfade wieder konsistent bestätigt werden. Die Aggregate bleiben für diesen kleinen SEO-/Admin-Batch stabil. |

### Delta Folge-Batch 486

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/seo/dashboard.php`, `DOC/audit/AdminAudit-SEO.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Das SEO-Dashboard nutzt für seine Schnellzugriffe auf Audit-, Meta-, Social-, Schema-, Sitemap- und Technical-SEO jetzt hostneutrale relative Admin-Routen statt an `SITE_URL` gebundener Links. | Die zentralen SEO-Dashboard-Wechsel bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Admin-Kontext, statt ausgerechnet vom Übersichts-Einstieg aus auf eine falsche Origin zu kippen. Die Aggregate bleiben für diesen kleinen SEO-Dashboard-Batch stabil. |

### Delta Folge-Batch 487

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/seo/performance.php`, `CMS/admin/views/performance/subnav.php`, `DOC/audit/AdminAudit-Performance.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Performance-Übersicht und Performance-Subnav nutzen für Cache-, Medien-, Datenbank-, Settings- und Session-Unterbereiche jetzt hostneutrale relative Admin-Routen statt an `SITE_URL` gebundener Links. | Der Wechsel innerhalb des Performance-Clusters bleibt damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung sauber im aktuellen Admin-Kontext, statt von Übersicht oder Subnav aus auf eine falsche Origin zu springen. Die Aggregate bleiben für diesen kleinen Performance-Navigations-Batch stabil. |

### Delta Folge-Batch 488

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/performance/settings.php`, `DOC/audit/AdminAudit-Performance.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der interne Querbezug vom Performance-Settings-Hinweis zum `Font Manager` nutzt jetzt eine hostneutrale relative Admin-Route statt eines an `SITE_URL` gebundenen Links. | Der Wechsel aus den Performance-Einstellungen in den Font-Manager bleibt damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Admin-Kontext, statt über einen kleinen Hilfelink auf eine falsche Origin zu springen. Die Aggregate bleiben für diesen kleinen Performance-/Design-Querbezug stabil. |

### Delta Folge-Batch 489

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/performance/cache.php`, `CMS/admin/views/performance/database.php`, `CMS/admin/views/performance/media.php`, `CMS/admin/views/performance/sessions.php`, `DOC/audit/AdminAudit-Performance.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Destruktive Wartungsaktionen im Performance-Cluster hängen jetzt wieder am gemeinsamen Confirm-Vertrag aus `CMS/assets/js/admin.js`, statt Cache-Leeren, OPcache-Reset, Tabellenwartung, WebP-Massenkonvertierung und Session-Bereinigung ohne vorgeschaltete Bestätigung direkt auszuführen. | Der Performance-Admin reduziert damit einen echten Shared-Contract-Bruch zwischen vorhandener Confirm-Infrastruktur und mutierenden Wartungsaktionen: schwere Cache-, DB-, Medien- und Session-Eingriffe laufen nicht mehr mit einem einzelnen Fehlklick sofort auf dem Live-Bestand an. Die Aggregate bleiben für diesen kleinen Performance-/Confirm-Batch stabil. |

### Delta Folge-Batch 490

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/performance/settings.php`, `CMS/admin/views/performance/sessions.php`, `DOC/audit/AdminAudit-Performance.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die Performance-Formulare spiegeln die serverseitigen Min-/Max-Grenzen für Browser-/HTML-TTL und Session-Timeouts jetzt direkt im UI, statt Werte unterhalb oder oberhalb der von `PerformanceModule::saveSettings()` erzwungenen Bereiche zuzulassen. | Performance-Einstellungen laufen damit nicht mehr still zwischen Browser-Formular und Backend-Validierung auseinander: Redakteure sehen die echten Schutzgrenzen sofort im Admin, statt erst nach dem Speichern implizit auf einen anderen Wert geklemmt zu werden. Die Aggregate bleiben für diesen kleinen Performance-Settings-Batch stabil. |

### Delta Folge-Batch 491

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/seo/PerformanceModule.php`, `DOC/audit/AdminAudit-Performance.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die Dateisession-Bereinigung im Performance-Modul richtet sich jetzt nach den konfigurierten Admin-/Member-Timeouts statt nach einem starren 24h-Schwellenwert. | Session-Cleanup und Session-Einstellungen laufen damit nicht mehr gegeneinander: aktive Dateisessions werden bei längeren konfigurierten Laufzeiten nicht mehr zu früh gelöscht, nur weil der Wartungspfad noch mit einem festen Tageswert arbeitet. Die Aggregate bleiben für diesen kleinen Performance-/Session-Batch stabil. |

### Delta Folge-Batch 492

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Auth.php`, `CMS/index.php`, `DOC/audit/AdminAudit-Performance.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die echten Laufzeit-Sessionpfade hängen Admin-/Member-Lifetimes jetzt an die konfigurierten Performance-Timeouts statt an fest eingebaute 8h/30-Tage-Werte und einen serverseitigen PHP-GC-Default. | Session-Settings sind damit nicht länger bloß Admin-Dekoration plus Cleanup-Hinweis: Login-Prüfung, Remember-Me-Cookie und `session.gc_maxlifetime` folgen jetzt demselben Performance-Vertrag, sodass konfigurierte Laufzeiten auch im realen Auth-Betrieb wirken. Die Aggregate bleiben für diesen kleinen Performance-/Runtime-Batch stabil. |

### Delta Folge-Batch 493

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/settings/SettingsModule.php`, `CMS/install/InstallerService.php`, `CMS/config/app.php`, `DOC/audit/AdminAudit-System.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | System-Settings, Installer und Config-Template schreiben den veralteten Schattenwert `SESSIONS_LIFETIME` nicht länger in neue oder aktualisierte `config/app.php`-Dateien, nachdem die echte Session-Laufzeit bereits auf die Performance-Timeouts umgestellt wurde. | 365CMS pflegt damit nicht länger zwei konkurrierende Session-Wahrheiten, von denen eine wirkungslos ist: neue Konfigurationen spiegeln nur noch die tatsächlich genutzten Auth-/Performance-Verträge, statt Admins bei Session-Themen durch tote Altkonstanten fehlzuleiten. Die Aggregate bleiben für diesen kleinen System-/Konfig-Batch stabil. |

### Delta Folge-Batch 494

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/settings/general.php`, `DOC/audit/AdminAudit-System.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die allgemeinen System-Einstellungen nutzen für interne Querlinks zu Mail-/Benutzer-Settings sowie für den Media-Picker-Endpunkt jetzt hostneutrale relative `/admin/...`- und `/api/...`-Pfade statt an `SITE_URL` gebundener Ziele. | Navigation und Media-Picker bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Admin-Kontext, statt ausgerechnet im zentralen Settings-Screen auf eine abweichende Origin oder Basis-URL zu kippen. Die Aggregate bleiben für diesen kleinen System-/Navigation-Batch stabil. |

### Delta Folge-Batch 495

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/partials/redirect-alias-shell.php`, `CMS/admin/support.php`, `CMS/admin/system-info.php`, `CMS/admin/documentation.php`, `CMS/admin/modules/system/DocumentationModule.php`, `CMS/admin/views/system/documentation.php`, `CMS/admin/pages.php`, `CMS/admin/posts.php`, `DOC/audit/AdminAudit-Info.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Info-/Dokublock und die Editor.js-AI-Bridge nutzen ihre internen Alias-, Dokument-, PRG-, Logs- und Übersetzungsziele jetzt hostneutral über relative Admin-Pfade statt an `SITE_URL` gebundener Redirects oder Endpoint-URLs. | Support-/Info-Aliasrouten, Dokumentwechsel, CMS-Logs-Sprünge und der geschützte DE→EN-/AI-Übersetzungsflow bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Admin-Kontext, statt auf eine falsche Origin oder Basis-URL zu kippen. Die Aggregate bleiben für diesen kleinen Info-/Asset-Batch stabil. |

### Delta Folge-Batch 496

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/subnav.php`, `CMS/admin/views/system/diagnose.php`, `CMS/admin/views/system/cms-logs.php`, `DOC/audit/AdminAudit-Diagnose.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die gemeinsame System-Subnav sowie die internen Diagnose-/Log-Sprünge nutzen für Info, Dokumentation, Diagnose, Monitoring und Log-Dateiauswahl jetzt hostneutrale relative Admin-Routen statt an `SITE_URL` gebundener Links. | System-/Diagnose-Navigation und die Auswahl einzelner CMS-Logdateien bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Admin-Kontext, statt aus dem Monitoring-Cluster auf eine falsche Origin zu springen. Die Aggregate bleiben für diesen kleinen Diagnose-/Navigation-Batch stabil. |

### Delta Folge-Batch 497

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/library.php`, `CMS/member/media.php`, `DOC/audit/AdminAudit-Medien.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/AssetAudit-Medien.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Medien-Cluster nutzt für interne Bibliotheks-Redirects, Browse-Links sowie für die nativen Upload-Endpunkte in Admin und Member jetzt hostneutrale relative `/admin/media`, `/member/media` und `/api/upload`-Pfade statt an `SITE_URL` gebundener Ziele. | Mediennavigation, Upload-Queue und Member-Dateiwechsel bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Kontext, statt auf eine falsche Origin oder Basis-URL zu springen. Die Aggregate bleiben für diesen kleinen Medien-/Asset-Batch stabil. |

### Delta Folge-Batch 498

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/member-dashboard-page.php`, `CMS/admin/views/member/subnav.php`, `CMS/admin/views/member/general.php`, `DOC/audit/AdminAudit-Member.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Member-Cluster nutzt für Übersicht, Subtabs, den Querhinweis zu `Benutzer & Gruppen → Einstellungen` und den Wrapper-Fallback jetzt hostneutrale relative Admin-Pfade statt an `SITE_URL` gebundener Ziele. | Member-Navigation und interne Settings-Wechsel bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Admin-Kontext, statt auf eine falsche Origin oder Basis-URL zu springen. Die Aggregate bleiben für diesen kleinen Member-/Navigation-Batch stabil. |

### Delta Folge-Batch 499

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/pages/edit.php`, `CMS/admin/views/pages/list.php`, `CMS/admin/views/posts/edit.php`, `CMS/admin/views/posts/list.php`, `CMS/admin/views/posts/categories.php`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Seiten- und Beiträge-Views nutzen für interne Listen-/Edit-/Kategorie-Wechsel sowie für Editor.js-Preview-, Media- und AI-Fallback-Ziele jetzt hostneutrale relative Admin-/API-Pfade statt an `SITE_URL` gebundener Ziele. | Seitenliste, Beitragseditor, Kategoriesprünge und die Editor.js-Brücke bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Admin-Kontext, statt auf eine falsche Origin oder Basis-URL zu springen. Die Aggregate bleiben für diesen kleinen Content-/EditorJS-Batch stabil. |

### Delta Folge-Batch 500

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/user-settings.php`, `CMS/admin/roles.php`, `CMS/admin/views/users/settings.php`, `CMS/admin/views/users/groups.php`, `DOC/audit/AdminAudit-Benutzer.md`, `DOC/audit/AdminAudit-Gruppen.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Benutzer-/RBAC-Settings nutzen für Guard-/PRG-Redirects, den internen Member-Dashboard-Querlink und Gruppen-Mutationsformulare jetzt hostneutrale relative Admin-Pfade bzw. Same-Route-POSTs statt an `SITE_URL` gebundener Ziele. | User-Settings, Rollen-POSTs und Gruppen-Mutationen bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Admin-Kontext, statt auf eine falsche Origin oder Basis-URL zu springen. Die Aggregate bleiben für diesen kleinen Benutzer-/RBAC-Navigationsbatch stabil. |

### Delta Folge-Batch 501

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Services/DashboardService.php`, `CMS/admin/views/dashboard/index.php`, `CMS/admin/security-audit.php`, `CMS/admin/views/legal/cookies.php`, `DOC/audit/AdminAudit-Dashboard.md`, `DOC/audit/AdminAudit-Sicherheit.md`, `DOC/audit/AdminAudit-Recht.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Dashboard-Schnellzugriffe, Security-Audit-Redirects und der öffentliche Cookie-Manager-Querlink nutzen jetzt hostneutrale relative Admin-/Public-Pfade statt an `SITE_URL` gebundener Ziele. | Dashboard, Security-Audit und Cookie-Manager bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Kontext, statt auf eine falsche Origin oder Basis-URL zu springen. Die Aggregate bleiben für diesen kleinen Navigationsbatch stabil. |

### Delta Folge-Batch 502

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/mail-settings.php`, `CMS/admin/views/system/ai-services.php`, `DOC/audit/AdminAudit-System.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | System-Tabs und die interne Mail-Logs-API nutzen jetzt hostneutrale relative Admin-/API-Pfade statt an `SITE_URL` gebundener Basen. | Mail-Settings, AI-Services und der Mail-Logs-Vertrag bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Admin-Kontext, statt auf eine falsche Origin oder API-Basis zu springen. Die Aggregate bleiben für diesen kleinen System-/Routing-Batch stabil. |

### Delta Folge-Batch 503

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/hub-sites.php`, `CMS/admin/views/hub/list.php`, `CMS/admin/views/hub/edit.php`, `CMS/admin/views/hub/templates.php`, `CMS/admin/views/hub/template-edit.php`, `CMS/assets/js/admin-hub-sites.js`, `CMS/assets/js/admin-hub-site-edit.js`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AdminAudit-Hub.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/AssetAudit-Hub.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Hub-Cluster nutzt für Access-Fallback, interne Admin-Wechsel, Template-/Edit-Roundtrips, Public-Sprünge, Clipboard-Pfade und den Spezial-Button `Speichern & Public Site öffnen` jetzt hostneutrale relative Admin-/Public-Pfade statt an `SITE_URL` gebundener URLs; zusätzlich folgt der Edit-Asset wieder demselben Submit-/Alert-Vertrag wie der restliche Admin. | Hub-Sites, Template-Verwaltung, Public-Öffnen und Slug-Kopie bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung auf der aktuellen Origin, statt auf eine falsche Basis-URL zu springen. Gleichzeitig serialisiert der Spezial-Save die Rich-Text-/Kachel-Inhalte wieder zuverlässig vor dem POST. Die Aggregate bleiben für diesen kleinen Hub-/Asset-Batch stabil. |

### Delta Folge-Batch 504

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/site-tables.php`, `CMS/admin/views/tables/list.php`, `CMS/admin/views/tables/edit.php`, `CMS/admin/views/tables/settings.php`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AdminAudit-Tabellen.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/AssetAudit-Tabellen.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Tabellen-Cluster nutzt für Guard-/PRG-Redirects, interne Listen-/Edit-/Settings-Wechsel, die Such-Basis für das Tabellen-Asset und Formular-Roundtrips jetzt hostneutrale relative Admin-Pfade bzw. Same-Route-POSTs statt an `SITE_URL` gebundener Ziele. | Tabellenliste, Editor, Settings und die Search-Bridge bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Admin-Kontext, statt auf eine falsche Origin oder Basis-URL zu springen. Die Aggregate bleiben für diesen kleinen Tabellen-/Routing-Batch stabil. |

### Delta Folge-Batch 505

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/comments/CommentsModule.php`, `CMS/admin/views/comments/list.php`, `CMS/admin/views/plugins/list.php`, `CMS/assets/js/admin-grid.js`, `DOC/audit/AdminAudit-INDEX.md`, `DOC/audit/AdminAudit-Kommentare.md`, `DOC/audit/AdminAudit-Plugins.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/AssetAudit-Grid.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Kommentare, Plugin-Liste und das gemeinsame Grid-Asset nutzen für Status-Tabs, den Plugin-Marketplace-Sprung sowie die generische Admin-API-Basis jetzt hostneutrale relative Admin-/API-Pfade statt an `SITE_URL` oder `CMS_SITE_URL` gebundener Ziele. | Kommentar-Moderation, Plugin-Übergang in den Marketplace und Grid.js-basierte Admin-Listen bleiben damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung auf derselben Origin, statt bei internen Admin-Wechseln oder API-Requests auf eine falsche Basis-URL zu kippen. Die Aggregate bleiben für diesen kleinen Kommentar-/Plugin-/Grid-Batch stabil. |

### Delta Folge-Batch 506

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/plugins/PluginMarketplaceModule.php`, `DOC/audit/AdminAudit-Plugins.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Plugin-Marketplace-String-Normalizer prüft vor dem Zuschneiden von Registry-/Manifestfeldern jetzt auf `mb_substr()` und fällt sonst robust auf `substr()` zurück, statt die `mbstring`-Extension stillschweigend vorauszusetzen. | Der Marketplace-Katalog und Manifest-Fallbacks bleiben damit auch auf PHP-Setups ohne geladene `mbstring`-Extension lauffähig, statt beim ersten Remote- oder lokalen Registry-Eintrag mit einem Fatal Error abzubrechen. Die Aggregate bleiben für diesen kleinen Robustheits-Batch stabil. |

### Delta Folge-Batch 507

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/ThemeMarketplaceModule.php`, `CMS/admin/views/themes/marketplace.php`, `DOC/audit/AdminAudit-Themes.md`, `DOC/audit/AssetAudit-Marketplace.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Theme-Marketplace-Modul und -View prüfen `mb_substr()` bzw. `mb_strtolower()` jetzt explizit und fallen andernfalls robust auf `substr()`/`strtolower()` zurück, statt die `mbstring`-Extension stillschweigend vorauszusetzen. | Theme-Katalog, Kartenrendering und der Such-/Filter-Vertrag des Marketplace-Assets bleiben damit auch auf PHP-Setups ohne geladene `mbstring`-Extension lauffähig, statt bereits beim Laden oder Filtern des Marketplace mit einem Fatal Error auszusteigen. Die Aggregate bleiben für diesen kleinen Theme-/Marketplace-Batch stabil. |

### Delta Folge-Batch 508

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-theme-explorer.js`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AssetAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Theme-Explorer blockiert Wiederholungs-Submits jetzt nicht nur optisch über den Pending-State, sondern verhindert bei bereits laufendem Submit explizit den nativen Browser-POST via `event.preventDefault()`. | Theme-Explorer-Saves bleiben damit auch bei schnellen Mehrfachklicks oder erneuten Submit-Auslösern fail-closed auf genau einen Request begrenzt, statt trotz laufendem Pending-State noch einen zweiten Save-Roundtrip absetzen zu können. Die Aggregate bleiben für diesen kleinen Design-/Asset-Batch stabil. |

### Delta Folge-Batch 509

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-content-editor.js`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Offene AI-Preview-/Diff-Karten werden jetzt invalidiert, sobald sich der EN-Zielzustand im Content-Editor manuell ändert, statt veraltete Vorschläge weiter mit aktivem `Übernehmen`-/`Verwerfen`-Pfad sichtbar zu lassen. | Seiten- und Beitragseditoren können damit nach manuellen EN-Änderungen keinen stale AI-Vorschlag mehr erneut über frisch bearbeitete Inhalte legen. Der gemeinsame DE→EN-/AI-Flow bleibt damit auch in Misch-Workflows aus Vorschau und Handarbeit fail-closed konsistent. Die Aggregate bleiben für diesen kleinen Editor.js-/Content-Batch stabil. |

### Delta Folge-Batch 514

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/ThemeEditorModule.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Theme-Explorer lehnt angeforderte Dateien jetzt auch dann fail-closed ab, wenn der angegebene Pfad zwar formal sauber aussieht, intern aber über einen Symlink auf eigentlich übersprungene Ziele wie `vendor/...` oder andere verborgene Teilpfade zeigt. | Manuell gesetzte `?file=`-Aliase können damit die Symlink-/Skip-Segment-Grenzen des Explorers nicht mehr indirekt aushebeln; Lese- und Save-Pfade bleiben wieder deckungsgleich zu Dateibaum, Warnhinweisen und dem dokumentierten Sicherheitsvertrag. Die Aggregate bleiben für diesen kleinen Design-/Theme-Explorer-Batch stabil. |

### Delta Folge-Batch 515

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/menus/editor.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Menü-Editor nutzt für interne Menüwechsel und Theme-Positions-Sprünge jetzt dieselbe hostneutrale relative Admin-Basis `/admin/menu-editor` statt an `SITE_URL` gehängter Links. | Menüwechsel bleiben damit auch unter Proxy-, Alternativhost- oder lokaler Dev-Umgebung im aktiven Admin-Kontext, statt beim einfachen Klick auf eine Menüliste oder Positionskarte auf eine falsche Origin zu springen. Die Aggregate bleiben für diesen kleinen Design-/Menü-Editor-Batch stabil. |

### Delta Folge-Batch 516

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/FontManagerModule.php`, `CMS/core/Bootstrap.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Font-Manager schreibt lokal gespeicherte Font-Dateien im generierten Self-Hosting-CSS jetzt nur noch hostneutral relativ und der Frontend-Bootstrap löst die gespeicherten CSS-Dateien gegen die aktuelle Runtime-URL statt hart gegen `SITE_URL` auf. | Self-hosted Fonts bleiben damit auch unter Proxy-, Alternativhost- oder lokaler Dev-Umgebung wirklich same-origin und hostneutral; lokal gespiegeltete WOFF-/CSS-Pfade kippen nicht mehr still auf eine falsche Origin, obwohl der Font-Manager gerade externe CDN-Abhängigkeiten abbauen soll. Die Aggregate bleiben für diesen kleinen Design-/Font-Manager-Batch stabil. |

### Delta Folge-Batch 517

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/FontManagerModule.php`, `CMS/core/Bootstrap.php`, `CMS/core/ThemeManager.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Font-Manager hängt seine gespeicherten Typografie-Zuordnungen jetzt nicht mehr nur an den Admin-Save, sondern reicht lokale Font-Slugs, Größen und Zeilenhöhe auch in die Frontend-Runtime durch, statt auf einen leeren `local_font_slugs`-Filter und ungenutzte `font_*`-Settings zu vertrauen. | Aktivierte lokale Fonts wirken damit im Live-Frontend wieder tatsächlich auf Schriftfamilien, Font-Größe und Zeilenhöhe, statt Google Fonts nur abzuschalten und die vom Font Manager gespeicherten Zuweisungen ansonsten folgenlos in der DB liegen zu lassen. Die Aggregate bleiben für diesen kleinen Design-/Font-Runtime-Batch stabil. |

### Delta Folge-Batch 518

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Services/Landing/LandingHeaderService.php`, `CMS/core/Services/Landing/LandingDefaultsProvider.php`, `CMS/themes/cms-default/home.php`, `CMS/themes/cms-default/partials/home-landing.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Landing-Header verliert das im Admin gepflegte Hero-Hintergrundbild nicht mehr zwischen Service und Frontend: `bg_image` wird wieder aus dem Header-Vertrag geladen, über die aktuelle Runtime-URL aufgelöst und in beiden Landing-Templates als echter Hero-Hintergrund gerendert; parallel nutzen die Frontend-Hinweise zur Landing-Konfiguration keine an `SITE_URL` oder `/admin/landing-page.php` gebundenen Admin-Links mehr. | Landing-Hintergrundbilder wirken damit im Live-Frontend wieder tatsächlich statt still in der Datenbank zu verschwinden, und interne Admin-Hinweislinks aus den Public-Leerzuständen bleiben auch unter Proxy-, Alternativhost- oder lokaler Dev-Umgebung auf der aktuellen Origin statt auf einer veralteten Host-/Legacy-Route zu landen. Die Aggregate bleiben für diesen kleinen Design-/Landing-Frontend-Batch stabil. |

### Delta Folge-Batch 519

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/themes/cms-default/home.php`, `CMS/themes/cms-default/partials/home-landing.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AssetAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die Landing-Templates rendern Hero-Hintergrund und Logo jetzt über vorab escapte URL-Variablen, explizite Boolean-Guards und ein eigenes Bild-Layer statt über ein dynamisches CSS-`url(...)`-Fragment mit roh ausgewerteten Pfaden; parallel ist der Design-/Template-Vertrag jetzt auch im Asset-Audit nachgezogen. | Landing-Hero und Branding bleiben damit bei internen hostneutralen Assetpfaden ebenso wie bei externen Bild-URLs sauber im Fail-closed-Renderpfad, und die Sicherheits-/Diagnostikspur für beide Frontend-Templates bleibt ohne Sonderwarnungen nachvollziehbar. Die Aggregate bleiben für diesen kleinen Design-/Template-Follow-up stabil. |

### Delta Folge-Batch 520

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/redirect-manager.php`, `CMS/admin/not-found-monitor.php`, `DOC/audit/AdminAudit-SEO.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Redirect-Manager und 404-Monitor nutzen für Access-Denied-Fallbacks am Entry jetzt denselben hostneutralen Root-Redirect `/` statt eines an `SITE_URL` gebundenen Sprungs. | Fehlende Berechtigungen im SEO-Cluster kippen damit auch unter Proxy-, Alternativhost- oder lokaler Dev-Umgebung nicht mehr auf eine potenziell falsche Origin, sondern bleiben auf der aktuellen Site-Basis. Die Aggregate bleiben für diesen kleinen SEO-/Guard-Batch stabil. |

### Delta Folge-Batch 521

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/antispam.php`, `CMS/admin/firewall.php`, `DOC/audit/AdminAudit-Sicherheit.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | AntiSpam- und Firewall-Entry prüfen schon für das Laden der Oberfläche jetzt dieselbe Capability `manage_settings`, die ihre Save-/Blacklist-/Regel-Aktionen intern ohnehin bereits verlangen. | Sicherheitskonfiguration wird damit nicht mehr als halb offene Admin-Oberfläche sichtbar, bei der Nutzer erst nach geladenen Daten und Formularen auf Berechtigungsfehler laufen. Die Aggregate bleiben für diesen kleinen Security-/RBAC-Batch stabil. |

### Delta Folge-Batch 522

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/security-audit.php`, `DOC/audit/AdminAudit-Sicherheit.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Security-Audit-Entry verlangt jetzt ebenfalls `manage_settings` statt nur generischer Admin-Rechte und bleibt damit capability-seitig konsistent zu AntiSpam und Firewall. | Sensible Audit-Läufe und Log-Bereinigungen werden damit nicht mehr für Admin-Nutzer ohne passenden Security-/Settings-Kontext sichtbar oder ausführbar, nur um erst später auf halboffene Zustände zu treffen. Die Aggregate bleiben für diesen kleinen Security-Audit-/RBAC-Follow-up stabil. |

### Delta Folge-Batch 523

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/seo/PerformanceModule.php`, `DOC/audit/AdminAudit-Performance.md`, `DOC/audit/AssetAudit-INDEX.md`, `DOC/audit/AssetAudit-Performance.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Performance-Teilformulare speichern jetzt nur noch die zu ihrem sichtbaren Formular gehörenden Setting-Keys, statt beim Speichern von Session- oder Medien-Optionen implizit fremde Boolean-Schalter wie Page-Cache, Browser-Cache oder Auto-Clear auf `0` zu ziehen; parallel ist dafür ein eigener Performance-Asset-Audit-Strang angelegt. | Session- und Medien-Roundtrips im Performance-Admin verhalten sich damit wieder konsistent zum sichtbaren Formularumfang: Teilbereichs-Saves löschen keine fremden Performance-Schalter mehr still im Hintergrund. Die Aggregate bleiben für diesen kleinen Performance-/Settings-Batch stabil. |

### Delta Folge-Batch 524

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/includes/functions/escaping.php`, `CMS/admin/views/performance/sessions.php`, `CMS/admin/views/dashboard/index.php`, `DOC/audit/AdminAudit-Performance.md`, `DOC/audit/AdminAudit-Dashboard.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Dashboard und Performance-Session-View nutzen für Anzeige-Kürzungen jetzt den zentralen UTF-8-sicheren Helfer `cms_truncate_text()` statt rohe `mb_strimwidth()`-Aufrufe, die auf PHP-Setups ohne geladene `mbstring`-Extension fatal abbrechen konnten. | Aktivitätsliste und Session-Tabelle bleiben damit auch auf kleineren/shared PHP-Installationen renderbar, statt schon an einer optionalen Kürzungsfunktion die komplette Admin-Ansicht zu verlieren. Die Aggregate bleiben für diesen kleinen Kompatibilitäts-/Robustheits-Batch stabil. |

### Delta Folge-Batch 525

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/comments/CommentsModule.php`, `DOC/audit/AdminAudit-Kommentare.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die Kommentar-Moderation nutzt für Kommentar-Excerpts jetzt den zentralen Helfer `cms_truncate_text()` und zieht Author-Initialen nicht länger über rohe `mb_substr()`-Aufrufe, sondern mit explizitem `mb_strtoupper()`-/`strtoupper()`-Fallback hoch. | Die Moderationsliste bleibt damit auch auf PHP-Setups ohne geladene `mbstring`-Extension renderbar, statt schon beim Kürzen von Kommentartexten oder Initialen mit einem Fatal Error auszusteigen. Die Aggregate bleiben für diesen kleinen Kommentare-/Kompatibilitäts-Batch stabil. |

### Delta Folge-Batch 526

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/updates.php`, `DOC/audit/AdminAudit-System.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die Update-Ansicht kürzt den Core-Changelog im Hinweis auf verfügbare Updates jetzt über `cms_truncate_text()` statt über einen rohen `mb_substr()`-Aufruf. | Der System-/Updates-Admin bleibt damit auch auf PHP-Setups ohne geladene `mbstring`-Extension renderbar, statt schon beim Anzeigen eines verfügbaren Core-Updates mit Fatal Error auszusteigen. Die Aggregate bleiben für diesen kleinen System-/Kompatibilitäts-Batch stabil. |

### Delta Folge-Batch 527

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/error-report.php`, `DOC/audit/AdminAudit-Diagnose.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Error-Report-Endpunkt begrenzt Titel, Nachricht, Fehlercode und JSON-Schlüssel jetzt über `cms_truncate_text()` statt über rohe `mb_substr()`-Aufrufe. | Der Diagnose-/Error-Reporting-Pfad bleibt damit auch auf PHP-Setups ohne geladene `mbstring`-Extension verarbeitbar, statt schon beim Normalisieren eines Fehlerreports mit Fatal Error auszusteigen. Die Aggregate bleiben für diesen kleinen Diagnose-/Kompatibilitäts-Batch stabil. |

### Delta Folge-Batch 528

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationCatalog.php`, `CMS/admin/modules/system/DocumentationRenderer.php`, `DOC/audit/AdminAudit-Info.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Dokumentationsbrowser nutzt für Excerpts, Tabellenzellen und Link-Ziellimits jetzt `cms_truncate_text()` statt roher `mb_strlen()`-/`mb_substr()`-Aufrufe. | Der Info-/Doku-Admin bleibt damit auch auf PHP-Setups ohne geladene `mbstring`-Extension renderbar, statt schon beim Aufbereiten längerer Dokumente, Tabellen oder Link-Ziele mit Fatal Error auszusteigen. Die Aggregate bleiben für diesen kleinen Info-/Kompatibilitäts-Batch stabil. |

### Delta Folge-Batch 529

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/tables/TablesModule.php`, `DOC/audit/AdminAudit-Tabellen.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die Tabellenliste nutzt für Beschreibungs-Excerpts jetzt `cms_truncate_text()` statt eines rohen `mb_substr()`-Aufrufs. | Der Tabellen-Admin bleibt damit auch auf PHP-Setups ohne geladene `mbstring`-Extension renderbar, statt schon beim Aufbau der Listenübersicht mit Fatal Error auszusteigen. Die Aggregate bleiben für diesen kleinen Tabellen-/Kompatibilitäts-Batch stabil. |

### Delta Folge-Batch 530

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/security/SecurityAuditModule.php`, `DOC/audit/AdminAudit-Sicherheit.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Das Security-Audit nutzt für gekürzte Check-, Detail- und Audit-Log-Texte jetzt `cms_truncate_text()` statt einer zentralen Routine mit rohen `mb_strlen()`-/`mb_substr()`-Aufrufen. | Der Security-Admin bleibt damit auch auf PHP-Setups ohne geladene `mbstring`-Extension renderbar, statt schon beim Aufbau von Checklisten, Audit-Logs oder IP-Fallbacks mit Fatal Error auszusteigen. Die Aggregate bleiben für diesen kleinen Security-/Kompatibilitäts-Batch stabil. |

### Delta Folge-Batch 531

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/security/FirewallModule.php`, `DOC/audit/AdminAudit-Sicherheit.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die Firewall validiert `block_ua`-Regeln bei der Mindestlänge jetzt mit explizitem `mb_strlen()`-/`strlen()`-Fallback statt über ein rohes `mb_strlen()`. | Der Firewall-Admin bleibt damit auch auf PHP-Setups ohne geladene `mbstring`-Extension beim Anlegen von User-Agent-Blockregeln funktionsfähig, statt schon im Validierungspfad mit Fatal Error auszusteigen. Die Aggregate bleiben für diesen kleinen Security-/Kompatibilitäts-Batch stabil. |

### Delta Folge-Batch 532

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/hub/HubSitesModule.php`, `DOC/audit/AdminAudit-Hub.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Hub-Admin nutzt für Hub-Site-, Karten-, Link- und Feature-Card-Normalisierung jetzt zentrale Kürzungshelper statt zahlreicher roher `mb_substr()`-Aufrufe im Save-Pfad. | Der Hub-Admin bleibt damit auch auf PHP-Setups ohne geladene `mbstring`-Extension beim Speichern von Hub-Sites render- und speicherfähig, statt schon beim Normalisieren von Badge-, Titel-, Meta- oder Textfeldern mit Fatal Error auszusteigen. Die Aggregate bleiben für diesen kleinen Hub-/Kompatibilitäts-Batch stabil. |

### Delta Folge-Batch 533

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/hub/HubTemplateProfileManager.php`, `DOC/audit/AdminAudit-Hub.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Hub-Template-Manager nutzt für Template-Labels, Metadaten, Vergleichs-Arrays und Starter-Karten jetzt zentrale Kürzungshelper statt roher `mb_substr()`-Aufrufe in Load-/Save-/Sync-Pfaden. | Der Hub-Template-Editor bleibt damit auch auf PHP-Setups ohne geladene `mbstring`-Extension beim Laden, Speichern, Kopieren und Vererben von Templates funktionsfähig, statt schon im Profil-Normalisierungs- oder Sync-Pfad mit Fatal Error auszusteigen. Die Aggregate bleiben für diesen kleinen Hub-/Kompatibilitäts-Batch stabil. |

### Delta Folge-Batch 534

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-hub-site-edit.js`, `CMS/assets/js/admin-hub-sites.js`, `DOC/audit/AssetAudit-Hub.md`, `DOC/audit/AdminAudit-Hub.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die geladene Hub-Asset-Schicht nutzt den Shared-Alert-Helfer jetzt wieder mit dem echten Vertrag `cmsAlert(type, message)` statt mit vertauschter Argument-Reihenfolge. | Copy-/Clipboard-Feedback, Browser-Warnungen und Erfolgsbestätigungen im Hub-Editor sowie in der Hub-Liste bleiben damit im Live-Admin sichtbar und korrekt gestylt, statt nur noch den Typ-String anzuzeigen oder mit defekten Alert-Klassen zu rendern. Die Aggregate bleiben für diesen kleinen Hub-/Asset-Batch stabil. |

### Delta Folge-Batch 535

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-content-editor.js`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der gemeinsame Editor.js-Submitpfad submitet Seiten- und Beitragsformulare jetzt nur noch nach vollständig erfolgreicher Serialisierung aller aktiven Editor-Instanzen, statt Save-Fehler still zu schlucken und trotzdem mit veralteten Hidden-JSON-Werten weiterzuposten. | Page- und Post-Editoren verlieren damit einen echten Datenverlustpfad im Shared-Asset: Defekte Blockzustände oder fehlgeschlagene `instance.save()`-Aufrufe führen nicht mehr zu einem scheinbar erfolgreichen Save mit altem Inhalt, sondern stoppen den POST fail-closed mit sichtbarer Fehlermeldung im betroffenen Editor-Kontext. |

### Delta Folge-Batch 536

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/editor-init.js`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der gemeinsame Legacy-HTML→Editor.js-Fallback parsed bestehende HTML-Inhalte jetzt inert über ein `<template>`, verwirft gefährliche Root-Tags und sanitisiert Inline-Markup auf einen kleinen, URL-geprüften Safe-Subset, statt rohe `innerHTML`-Fragmente direkt in Blockdaten zu übernehmen. | Seiten- und Beitragseditoren verlieren damit einen echten Admin-XSS-/Markup-Einschleuspfad beim Öffnen älterer HTML-Inhalte: problematische Inline-Tags oder Event-Attribute landen nicht mehr blind im initialen Editorzustand, sondern werden vor der Editor.js-Initialisierung konsequent reduziert. |

### Delta Folge-Batch 537

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/posts.php`, `DOC/audit/AssetAudit-Grid.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die aktive Grid.js-Brücke der Beitragsliste nutzt für API-Basis, Edit-Links und den Access-Denied-Fallback jetzt hostneutrale relative interne Ziele statt an `SITE_URL` gebundener Pfade im Entry-Setup. | Die Beitragsliste bleibt damit auch unter Proxy-, Alternativhost- und lokaler Dev-Umgebung im aktuellen Admin-/Origin-Kontext: Suche, Paging, Edit-Sprünge und Guard-Fallback kippen nicht mehr auf eine feste Hostbasis, obwohl der geladene Grid-Laufzeitpfad nur dieselbe interne Origin braucht. |

### Delta Folge-Batch 538

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/media/MediaModule.php`, `DOC/audit/AssetAudit-Medien.md`, `DOC/audit/AdminAudit-Medien.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die Medienbibliothek baut den Root-Breadcrumb `Uploads` jetzt ohne den aktuellen Unterordnerpfad auf, statt den laufenden `path` still in `root_url` mitzuschleppen und damit einen Pseudo-Root-Link auf denselben Ordner zu erzeugen. | Die Bibliotheksnavigation funktioniert damit in verschachtelten Medienordnern wieder korrekt: Ein Klick auf `Uploads` springt tatsächlich an den Bibliotheksanfang zurück, während View-, Kategorie- und Suchzustand erhalten bleiben. |

### Delta Folge-Batch 539

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-media-integrations.js`, `CMS/assets/js/member-dashboard.js`, `DOC/audit/AssetAudit-Medien.md`, `DOC/audit/AdminAudit-Medien.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Admin- und Member-Medienuploads übernehmen das vom Server gelieferte Ersatz-`new_token` jetzt nicht mehr nur nach erfolgreichen Uploads, sondern auch nach Fehlerantworten aus `FileUploadService`, obwohl `Security::verifyToken()` das gerade verwendete `media_action`-Token bereits verbraucht hat. | Mehrfach-Uploads im Medien-Admin und im Member-Bereich laufen damit nach einer fehlgeschlagenen Datei nicht mehr vermeidbar in nachgelagerte CSRF-Fehler, sondern bleiben innerhalb desselben Batches auf dem aktuellen Token-Vertrag synchron. |

### Delta Folge-Batch 540

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/posts/PostsModule.php`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die Slug- und Tag-Normalisierung des Beiträge-Moduls nutzt für `generateSlug()`, `normalizeSlug()` und `normalizeTagInput()` jetzt explizite `mb_strtolower()`-/`strtolower()`- und `mb_substr()`-/`substr()`-Fallbacks statt roher `mb_*`-Aufrufe im Live-Save-Pfad. | Der Beiträge-Admin bleibt damit auch auf PHP-Setups ohne geladene `mbstring`-Extension beim Speichern, Slug-Aufbereiten und Tag-Normalisieren funktionsfähig, statt schon im normalen Save-Flow mit Fatal Error auszusteigen. Die Aggregate bleiben für diesen kleinen Beiträge-/Kompatibilitäts-Batch stabil. |

### Delta Folge-Batch 541

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/settings/SettingsModule.php`, `DOC/audit/AdminAudit-System.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die allgemeinen Systemeinstellungen nutzen für `normalizeRouteBase()`, `sanitizeAuditString()` und `maskEmailAddress()` jetzt explizite `mb_strtolower()`-/`strtolower()`, `mb_substr()`-/`substr()`- und `mb_strlen()`-/`strlen()`-Fallbacks statt roher `mb_*`-Aufrufe in Save-, Audit- und Testmail-Pfaden. | Der Systembereich bleibt damit auch auf PHP-Setups ohne geladene `mbstring`-Extension beim Speichern, beim Audit-/Migrations-Logging und beim Testmail-Flow funktionsfähig, statt in diesen Live-Pfaden schon an optionalen String-Helfern fatal auszusteigen. Die Aggregate bleiben für diesen kleinen System-/Kompatibilitäts-Batch stabil. |

### Delta Folge-Batch 542

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Security.php`, `CMS/core/Services/CommentService.php`, `DOC/audit/AdminAudit-Sicherheit.md`, `DOC/audit/AdminAudit-Kommentare.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Session-Fallback des Login-/API-Rate-Limits nutzt für seine Bucket-Keys jetzt `hash('sha256', ...)` statt Legacy-`md5()`, und der öffentliche Kommentarpfad ersetzt den rohen `sha1(email)`-Fingerprint im Flood-Log durch eine maskierte Adresse und zieht seine String-Grenzen über explizite `mb_*`-/Core-PHP-Fallbacks. | Security- und Kommentarpfade bleiben damit auch bei DB-Fallback, Kommentar-Flood-Schutz und auf PHP-Setups ohne geladene `mbstring`-Extension belastbar, ohne veraltete Hashes oder unnötig rückrechenbare E-Mail-Fingerprints weiter mitzuschleppen. Die Aggregate bleiben für diesen kleinen Security-/Kommentar-Batch stabil. |

### Delta Folge-Batch 543

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/FontManagerModule.php`, `CMS/core/Services/CronRunnerService.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/AdminAudit-Diagnose.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Font-Manager und Cron-Runner ersetzen ihre verbleibenden Legacy-`sha1()`-/`md5()`-Suffixe jetzt durch `hash('sha256', ...)`, statt überlange verwaltete Font-Dateinamen oder den globalen Cron-Lock-Namespace weiter an veralteten Hashfunktionen aufzuhängen. | Self-Hosted-Fontdownloads und der Runtime-Cron-Lock bleiben damit funktional unverändert, tragen im First-Party-Core aber keine Legacy-Hashfunktionen mehr in aktiven Admin-/Monitoring-Pfaden mit. Die Aggregate bleiben für diesen kleinen Design-/Diagnose-Batch stabil. |

### Delta Folge-Batch 556

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-seo-editor.js`, `DOC/audit/AssetAudit-SEO.md`, `DOC/audit/AdminAudit-SEO.md`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der gemeinsame SEO-Editor analysiert Page-/Post-Inhalte jetzt blockstrukturiert aus live synchronisiertem Editor.js-JSON und zählt Keyphrases sowie Transition-Wörter Unicode-sicher statt primär über rohe String-Sammlung, DOM-Fallbacks und brüchige Wortgrenzen. | SEO-/Readability-Panels bleiben damit näher am tatsächlich bearbeiteten Editor.js-Inhalt, statt technische JSON-Reste mitzuzählen oder strukturierte Link-/Bildsignale zu verlieren. Die Aggregate bleiben für diesen kleinen Shared-SEO-/EditorJS-Batch stabil. |

### Delta Folge-Batch 555

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-seo-editor.js`, `DOC/audit/AssetAudit-SEO.md`, `DOC/audit/AdminAudit-SEO.md`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die Regel-/Score-Liste des gemeinsamen SEO-Editors rendert bearbeitbare Slug-, Focus-Phrase- und Detailwerte jetzt über echte DOM-Knoten statt über `innerHTML`. | Page-/Post-Editoren verlieren damit einen unnötigen DOM-XSS-/Markup-Pfad im Shared-SEO-Asset; Regeltexte bleiben auf reine Textknoten begrenzt. Die Aggregate bleiben für diesen kleinen Shared-SEO-Batch stabil. |

### Delta Folge-Batch 554

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-content-editor.js`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der gemeinsame AI-Übersetzungsflow serialisiert den EN-Zielzustand jetzt vor Preview-/Overwrite-Entscheidungen explizit mit und prüft Draft-Inhalte gegen den echten aktuellen Editorstand statt gegen potenziell veraltete Hidden-Felder. | Noch nicht gespeicherte EN-Änderungen geraten damit im DE→EN-/AI-Flow nicht mehr in einen scheinbar leeren Zielzustand und werden nicht still von frischen AI-Vorschlägen überfahren. Die Aggregate bleiben für diesen kleinen Shared-EditorJS-/AI-Batch stabil. |

### Delta Folge-Batch 553

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-content-editor.js`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der manuelle `Alles aus DE nach EN kopieren`-Flow serialisiert Source und Target jetzt vorab und verlangt bei vorhandenem EN-Entwurf eine explizite Bestätigung statt stiller Überschreibung. | Bereits bearbeitete EN-Fassungen von Seiten und Beiträgen werden damit nicht mehr versehentlich durch eine erneute Direktkopie aus DE überschrieben. Die Aggregate bleiben für diesen kleinen Shared-EditorJS-Batch stabil. |

### Delta Folge-Batch 552

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-content-editor.js`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die Page-/Post-Editoren spiegeln aktive Editor.js-Änderungen jetzt live in ihre Hidden-JSON-Felder zurück, statt den Hidden-Vertrag erst beim Formular-Submit zu aktualisieren. | SEO-/Readability-Panels, Preview-Bridge und Folgeaktionen arbeiten damit nicht mehr auf veralteten Editor-Inhalten, solange Redakteure bereits weitergeschrieben haben. Die Aggregate bleiben für diesen kleinen Shared-EditorJS-Batch stabil. |

### Delta Folge-Batch 551

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/editor-init.js`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die gemeinsame Editor.js-Brücke stellt jetzt einen debounced `onChange`-Synchronisationspfad bereit, über den Shared-Assets sichtbare Blockänderungen direkt in ihren Hidden-/JSON-Vertrag zurückspiegeln können. | Shared-Editor-Integrationen hängen damit nicht mehr ausschließlich am nächsten Save-Submit, sondern können Live-Zustände für Folge-Assets und UI-Verträge belastbar nachziehen. Die Aggregate bleiben für diesen kleinen EditorJS-Basis-Batch stabil. |

### Delta Folge-Batch 550

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Services/CronRunnerService.php`, `DOC/audit/AdminAudit-Diagnose.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Cron-Runner nutzt für `truncateForLog()` jetzt einen expliziten `mb_substr()`-/`substr()`-Fallback statt eines rohen `mb_substr()`-Aufrufs im Diagnose-/Monitoring-Pfad. | Cron-Läufe und Monitoring-Logs bleiben damit auch auf PHP-Setups ohne geladene `mbstring`-Extension beim Kürzen von Ergebnis- und Fehlertexten funktionsfähig, statt im reinen Logging-Pfad fatal auszusteigen. Die Aggregate bleiben für diesen kleinen Diagnose-/Cron-Batch stabil. |

### Delta Folge-Batch 549

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Services/CommentService.php`, `DOC/audit/AdminAudit-Kommentare.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Die Kommentar-Erstellung nutzt für die aufgelöste Autor-E-Mail in `createPendingComment()` jetzt ebenfalls den internen UTF-8-Fallback-Helfer statt eines verbliebenen rohen `mb_substr()`-Aufrufs. | Der öffentliche Kommentar-Create-Pfad ist damit in seiner gesamten String-Normalisierung konsistent ohne harte `mbstring`-Abhängigkeit gehärtet, statt an einer einzelnen E-Mail-Grenze noch auf einen Fatal Error laufen zu können. Die Aggregate bleiben für diesen kleinen Kommentar-/Core-Follow-up stabil. |

### Delta Folge-Batch 548

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/legal-sites.php`, `DOC/audit/AdminAudit-Recht.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Legal-Sites-Entry nutzt für Profil- und HTML-Grenzen jetzt explizite `mb_substr()`-/`substr()`-Fallback-Helfer statt roher `mb_substr()`-Aufrufe in der Request-Normalisierung. | Profil-Saves und Rechtstext-Generierung bleiben damit auch auf PHP-Setups ohne geladene `mbstring`-Extension funktionsfähig, statt bereits im Entry vor dem eigentlichen Modulaufruf fatal abzubrechen. Die Aggregate bleiben für diesen kleinen Recht-/Entry-Batch stabil. |

### Delta Folge-Batch 547

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/documentation.php`, `DOC/audit/AdminAudit-Info.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Dokumentations-Entry begrenzt den ausgewählten Dokumentpfad jetzt über einen expliziten `mb_substr()`-/`substr()`-Fallback statt über einen rohen `mb_substr()`-Aufruf. | Dokumentwechsel und PRG-Roundtrips im Doku-Browser bleiben damit auch auf PHP-Setups ohne geladene `mbstring`-Extension lauffähig, statt bereits beim `?doc=`-Pfad fatal abzubrechen. Die Aggregate bleiben für diesen kleinen Info-/Dokubatch stabil. |

### Delta Folge-Batch 546

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-explorer.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Theme-Explorer-Entry nutzt für den aktiven Dateipfad jetzt einen expliziten `mb_substr()`-/`substr()`-Fallback und leitet Guard-Fallbacks hostneutral auf `/` statt an `SITE_URL` gebunden weiter. | Dateiwechsel im Theme-Explorer bleiben damit auch ohne geladene `mbstring`-Extension funktionsfähig, und Access-Denied-Fälle springen unter Proxy-, Alternativhost- oder lokaler Dev-Umgebung nicht mehr auf eine falsche Origin. Die Aggregate bleiben für diesen kleinen Design-/Theme-Explorer-Batch stabil. |

### Delta Folge-Batch 545

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/plugin-marketplace.php`, `DOC/audit/AdminAudit-Plugins.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Plugin-Marketplace-Entry nutzt für Install-Slug-Grenzen und Truncation-Checks jetzt explizite `mb_*`-/Core-PHP-Fallback-Helfer statt roher `mb_substr()`-/`mb_strlen()`-Aufrufe. | Plugin-Installationen bleiben damit auch auf PHP-Setups ohne geladene `mbstring`-Extension schon im Entry-Vertrag lauffähig, statt vor dem eigentlichen Marketplace-Modul an einem Fatal Error zu scheitern. Die Aggregate bleiben für diesen kleinen Plugin-/Entry-Batch stabil. |

### Delta Folge-Batch 544

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/font-manager.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Font-Manager-Entry nutzt für Google-Font-Familien, Font-Keys und Längenprüfungen jetzt explizite `mb_*`-/Core-PHP-Fallback-Helfer statt roher `mb_substr()`-/`mb_strlen()`-Aufrufe. | Speichern und Google-Font-Downloads im Design-Admin bleiben damit auch auf PHP-Setups ohne geladene `mbstring`-Extension funktionsfähig, statt schon in der Entry-Normalisierung fatal abzubrechen. Die Aggregate bleiben für diesen kleinen Design-/Font-Manager-Batch stabil. |

### Delta Folge-Batch 513

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-editor.php`, `DOC/audit/AdminAudit-Design.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Theme-Editor bindet `admin/customizer.php` des aktiven Themes jetzt nur noch ein, wenn die Datei im Theme-Pfad liegt, syntaktisch parsebar ist und keine blockierten Risko-Funktionen wie `eval`, `exec` oder `shell_exec` enthält. | Defekte oder riskante Theme-Customizer reißen den Admin damit nicht mehr direkt beim Öffnen des Theme Editors aus der Kurve, sondern landen fail-closed auf der sicheren Fallback-View mit nachvollziehbarem Hinweis. Die Aggregate bleiben für diesen kleinen Design-/Theme-Editor-Batch stabil. |

### Delta Folge-Batch 512

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/ThemeMarketplaceModule.php`, `DOC/audit/AdminAudit-Themes.md`, `DOC/audit/AssetAudit-Marketplace.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der Theme-Marketplace validiert nach dem generischen Update-Install jetzt themespezifisch, ob im Zielordner wirklich ein aktivierbares Theme liegt, zieht verschachtelte Theme-Wurzeln bei Bedarf nach oben und verwirft unvollständige Paket-Wrapper fail-closed. | Marketplace-Installationen enden damit nicht mehr in einem Schein-Erfolg, bei dem der Button sauber sperrt und der Server Erfolg meldet, unter `CMS/themes/<slug>/` aber nur ein Repo-Wrapper statt eines echten Laufzeit-Themes landet. Die Aggregate bleiben für diesen kleinen Theme-/Marketplace-Batch stabil. |

### Delta Folge-Batch 511

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Services/AI/AiSettingsService.php`, `CMS/admin/modules/system/AiServicesModule.php`, `DOC/audit/AdminAudit-System.md`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der AI-Translate-EditorJS-Settings-Pfad kanonisiert `supported_block_types` jetzt explizit auf die echten Pipeline-/Runtime-Namen, statt gemischt geschriebene Editor.js-Blocktypen wie `mediaText` per generischer Kleinschreibung in einen stillen Nicht-Match zu verwandeln. | Seiten- und Beitragseditoren behalten damit Warnboxen und Medien-Text-Blöcke nicht nur im Default-Vertrag, sondern auch nach Save-/Load-Roundtrips belastbar im DE→EN-Flow. Die Aggregate bleiben für diesen kleinen System-/EditorJS-Follow-up stabil. |

### Delta Folge-Batch 510

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Services/AI/AiSettingsService.php`, `CMS/admin/modules/system/AiServicesModule.php`, `DOC/audit/AdminAudit-System.md`, `DOC/audit/AssetAudit-EditorJS.md`, `DOC/audit/AdminAudit-Seiten.md`, `DOC/audit/AdminAudit-Beitraege.md`, `DOC/audit/BEWERTUNG.md`, `README.md`, `Changelog.md` | umgesetzt | Der AI-Translate-EditorJS-Standardvertrag, der Save-Fallback für leere Translation-Settings und die Blocktyp-Kanonisierung folgen jetzt gemeinsam dem realen Pipeline-Satz inklusive `warning` und `mediaText`, statt nach Reset oder generischer Kleinschreibung still auf einen zu kleinen bzw. falsch geschriebenen Blocktyp-Satz zurückzufallen. | Seiten- und Beitragseditoren übersetzen damit Warnboxen und Medien-Text-Blöcke auch nach frischen System-Settings wieder belastbar nach EN, statt im scheinbar erfolgreichen AI-Roundtrip genau diese Editor.js-Segmente unangetastet in der DE-Fassung stehen zu lassen. Die Aggregate bleiben für diesen kleinen System-/EditorJS-Batch stabil. |

### Delta Folge-Batch 465

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-editor.php`, `README.md`, `Changelog.md` | umgesetzt | Der Theme-Editor verwendet für eingebettete Customizer jetzt denselben CSRF-Vertrag wie die Theme-Formulare selbst und reicht `embedInAdminLayout` an das aktive Theme weiter; parallel dokumentiert die Basis-Doku klarer, dass Admin-Customizer nur gegen deployte Laufzeit-Themes unter `CMS/themes/` arbeiten. | Theme-Customizer-Saves werden im Admin nicht länger vom Shell-Guard vorzeitig verworfen, und Theme-Arbeit außerhalb von `CMS/themes/` lässt sich sauberer vom tatsächlich aktiven Runtime-Theme abgrenzen. |

### Delta Folge-Batch 464

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `DOC/README.md`, `DOC/INDEX.md`, `DOC/INSTALLATION.md`, `DOC/core/README.md`, `DOC/core/STATUS.md`, `DOC/core/ARCHITECTURE.md`, `DOC/core/STRUCTURE.md`, `README.md` | umgesetzt | Die zentrale Projektdokumentation wurde gesammelt auf einen konsistenten `2.8.0`-Stand ohne `RC`-Suffix harmonisiert; das Root-README erklärt zusätzlich, dass Folge-Härtungen separat dokumentiert werden und den Basis-Doku-Snapshot nicht unnötig auf Patch-Etiketten umziehen. | Die führende Basis-Doku bleibt leichter lesbar und widerspruchsärmer, weil zentrale Einstiegs- und Core-Snapshot-Dateien nun denselben Release-Stand transportieren, ohne Audit-Folge-Batches mit dem Grundsnapshot zu vermischen. |

### Delta Folge-Batch 463

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Services/FeedService.php`, `README.md`, `Changelog.md` | umgesetzt | Der Feed-Service folgt Redirects jetzt nur noch kontrolliert und validiert jedes Ziel erneut; cURL-Fetches nutzen zusätzlich ein geprüftes `CURLOPT_RESOLVE`-Target, um DNS-/Redirect-Restkanten weiter zu verkleinern; README und Changelog spiegeln die Härtung. | Native Feed-Imports bleiben näher an einem kleinen, nachvollziehbaren SSRF-Vertrag statt auf implizite Redirect-Defaults und spätere Resolver-Entscheidungen zu vertrauen. |

### Delta Folge-Batch 462

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Version.php`, `CMS/update.json`, `README.md`, `Changelog.md` | umgesetzt | Release-Metadaten und zentrale Projektdoku wurden auf den dokumentierten Stand `2.8.0` synchronisiert und nennen den fail-closed gehärteten IndexNow-Guard jetzt konsistent im öffentlichen Überblick. | Der Release-Snapshot bleibt als kleiner, überprüfbarer Stand dokumentiert, statt dass Versionsdateien und Hauptdoku auseinanderlaufen. |

### Delta Folge-Batch 461

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Services/IndexingService.php`, `README.md`, `Changelog.md` | umgesetzt | Die IndexNow-Validierung schließt den eigentlichen Dateilesepfad jetzt konsequent, sobald Lesbarkeit oder Dateigröße fehlschlagen oder sich die Dateigröße nicht sicher bestimmen lässt; README und Changelog spiegeln die Guard-Logik sowie den korrigierten `tests/`-Pfad wider. | Der SEO-Workflow bleibt näher an einem fail-closed-Vertrag: problematische Root-Dateien erzeugen klare Admin-Hinweise, statt trotz früher Guard-Fehler noch in einen Dateileseversuch zu laufen. |

### Delta Folge-Batch 460

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Services/IndexingService.php`, `CMS/admin/modules/seo/SeoSuiteModule.php`, `CMS/admin/views/seo/technical.php`, `CMS/admin/views/seo/sitemap.php`, `README.md`, `Changelog.md` | umgesetzt | Der SEO-Bereich pflegt IndexNow jetzt direkt über Admin und Core: API-Key und optionale Root-`.txt`-Datei werden allowlist-basiert gespeichert, gegen Dateiname/Inhalt geprüft und zusätzlich auf Lesbarkeit sowie unplausible Dateigröße validiert; README und Changelog spiegeln den neuen Workflow. | IndexNow-Übermittlungen bleiben näher an einem klaren, überprüfbaren Vertrag statt an manuellen Dateisystem-Annahmen; Fehlkonfigurationen der Keydatei werden früher sichtbar, ohne die bestehende dynamische `/<key>.txt`-Auslieferung im Core zu verbiegen. |

### Delta Folge-Batch 459

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Services/Media/MediaRepository.php`, `CMS/assets/js/admin-media-integrations.js`, `CMS/assets/js/member-dashboard.js` | umgesetzt | `isSystemPath()` schützt jetzt nur Ebene 1 (Root-Systemordner) und Ebene 2 unter `member/` (User-Roots); Dropdown-Modal-Trigger nutzen einen Click-Pending-Fallback, damit `event.relatedTarget` nicht leer bleibt, wenn Buttons innerhalb schließender Bootstrap-Dropdowns ausgelöst werden. | Member-erstellte Unterordner erhalten wieder sichtbare Aktions-Dropdowns; Umbenennen und Verschieben befüllen das Modal zuverlässig mit dem richtigen Pfad, unabhängig vom Bootstrap-internen Dropdown-Close-Timing. |

### Delta Folge-Batch 458

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/library.php`, `CMS/assets/js/admin-media-integrations.js`, `CMS/member/includes/class-member-controller.php`, `CMS/member/media.php`, `CMS/assets/js/member-dashboard.js` | umgesetzt | Der Medienbereich nutzt jetzt kompakte Dropdown-/Modal-Aktionen, vorbereitete Zielordner-Selects und Admin-Bulk-Operationen für Löschen/Verschieben; die neuen Flows wurden vor dem RC-Bump erneut auf Fehler, Best Practice und Security geprüft. | Medienaktionen bleiben belastbar an denselben serverseitigen Verträgen, werden aber deutlich kompakter bedienbar; Mehrfachaktionen vermeiden zusätzlich doppelte Unterpfad-Operationen und rohe Freitext-Ziele. |

### Delta Folge-Batch 457

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/library.php`, `CMS/member/includes/class-member-controller.php`, `CMS/member/media.php` | umgesetzt | Der Medienbereich unterstützt jetzt echte Rename-/Move-Aktionen in Admin und Member; Entry, Modul und View reichen Pfad-, Zielordner- und Namensdaten konsistent per POST/CSRF bis zum Media-Service durch. | Medienaktionen degradieren nicht mehr zu stillen UI-No-ops, und Admin-/Member-Bereich hängen bei Rename/Move enger an denselben robusten Request-Verträgen wie bereits beim Delete-Flow. |

### Delta Folge-Batch 456

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/media/library.php` | umgesetzt | Datei- und Ordnerlöschung in der Medienbibliothek laufen jetzt über echte POST-Formulare mit serverseitigem Delete-Entry statt nur über JS-Buttons plus Hidden-Form. | Medien-Löschaktionen funktionieren belastbarer und degradieren sauber weiter, selbst wenn JavaScript-Initialisierung oder globale Confirm-Dialoge ausfallen. |

### Delta Folge-Batch 455

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/member/includes/class-member-controller.php`, `CMS/member/media.php`, `CMS/assets/js/cookieconsent-init.js`, `CMS/core/Services/CookieConsentService.php`, `CMS/assets/js/admin-media-integrations.js`, `CMS/assets/js/member-dashboard.js`, `CMS/core/Services/FeedService.php` | umgesetzt | Member-Medienpfade unterstützen jetzt aktuellen Ordner, Breadcrumbs, Datei-/Ordnerlöschung und sichere Redirects innerhalb des Benutzerwurzelpfads; parallel wurden Consent-/Medien-Skripte gegen DOM-XSS, fehlendes `Secure`-Cookie-Flag und einen unnötigen `sha1`-Cache-Key gehärtet. | Datei- und Ordneraktionen im Member-Bereich funktionieren belastbarer, und die zuvor nativen Consent-/Upload-/Feed-Pfade laufen sicherer und reviewfester weiter. |

### Delta Folge-Batch 454

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/core/Services/CookieConsentService.php`, `CMS/assets/js/cookieconsent-init.js`, `CMS/assets/js/admin-media-integrations.js`, `CMS/member/media.php`, `CMS/core/Services/FeedService.php` | umgesetzt | Aktive CookieConsent-, FilePond-, elFinder- und SimplePie-Pfade wurden durch native Consent-, Upload-, Picker- und Feed-Implementierungen ersetzt; Autoload, Router und Member-Assets wurden entsprechend bereinigt. | Weniger aktive Fremdabhängigkeiten in Consent-, Medien- und Feed-Laufzeitpfaden; Upload- und Picker-Flows hängen enger an internen APIs und klaren Sicherheits-/Token-Verträgen. |

### Delta Folge-Batch 453

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-user-groups.js` | umgesetzt | Gruppen-Asset füllt Modale jetzt über `show.bs.modal`, setzt Pending-State und blockt wiederholte Save-/Delete-Aktionen robuster. | Gruppen anlegen, bearbeiten und löschen bleibt belastbarer gegen Modal- und Doppel-Submit-Probleme. |

### Delta Folge-Batch 452

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/users/groups.php` | umgesetzt | Gruppen-View ergänzt Bootstrap-Trigger und explizite Action-Ziele für Modal- und Delete-Formulare. | Gruppenmutationen hängen sichtbarer am korrekten Route- und Modal-Vertrag statt an implizitem JS-Verhalten. |

### Delta Folge-Batch 451

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/users/edit.php` | umgesetzt | User-Form spiegelt Passwort-, Username- und Feldlängenregeln direkt im Formular. | Ungültige Benutzerdaten werden früher abgefangen und führen seltener zu irritierenden Save-Abbrüchen. |

### Delta Folge-Batch 450

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/users/UsersModule.php` | umgesetzt | Users-Modul normalisiert Save-Input defensiver, protokolliert Exceptions und behandelt fehlende Erfolgs-/ID-Rückgaben explizit als Fehler. | Benutzer-Speicherpfade bleiben nachvollziehbarer statt in generischen Catch-All-Fehlern zu enden. |

### Delta Folge-Batch 449

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/media/MediaModule.php` | umgesetzt | Media-Settings geben bei unveränderten Saves jetzt eine klarere Bestätigungsrückmeldung aus. | Admin-Nutzer können bestätigte Bestandswerte besser von echten Änderungen unterscheiden. |

### Delta Folge-Batch 448

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-plugin-marketplace.js` | umgesetzt | Install-Formulare erhalten zusätzlich einen Form-Pending-State samt `aria-disabled`. | Install-Aktionen bleiben robuster gegen doppelte Submits und hektische Folge-Klicks. |

### Delta Folge-Batch 447

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/views/plugins/marketplace.php` | umgesetzt | Marketplace-View spiegelt erlaubte Archiv-Endungen und entsprechende Warnungen expliziter. | Auto-Install-Risiken bleiben früher sichtbar und hängen nicht nur an Modul- oder Fehlerpfaden. |

### Delta Folge-Batch 446

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/plugins/PluginMarketplaceModule.php` | umgesetzt | Marketplace-Modul verlangt erlaubte Archiv-Endungen und trägt den Zustand explizit in Daten- und Fehlerkontext ein. | Download- und Archivpfade hängen enger an einem klaren Paket- und Auto-Install-Vertrag. |

### Delta Folge-Batch 445

| Datei/Bereich | Status | Folge-Härtung über `PRÜFUNG.MD` hinaus | Wirkung |
|---|---|---|---|
| `CMS/admin/plugin-marketplace.php` | umgesetzt | Marketplace-Entry lehnt überlange Slugs und präzisere Payload-Fehler expliziter ab. | Entry-nahe Install-Requests bleiben nachvollziehbarer und hängen weniger an stillen Kürzungen. |

### Delta Batch 444

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/font-manager.php` | umgesetzt | Font-Manager-Entry weiter verdichtet, indem Save-Parameter und Google-Font-Namen expliziter validiert werden. | Entry-nahe Font-Mutationen bleiben nachvollziehbarer und hängen enger an einem klaren Request- und Fehlervertrag. |

### Delta Batch 443

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-explorer.php` | umgesetzt | Theme-Explorer-Entry weiter verdichtet, indem Write-Guard, Datei-/Inhaltsgrenzen und spezifischere Payload-Fehlercodes ergänzt werden. | Save-Requests bleiben robuster und hängen enger an sicheren Entry-Grenzen statt nur an später Modulvalidierung. |

### Delta Batch 442

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/themes/customizer-missing.php` | umgesetzt | Theme-Editor-Fallback-View weiter verdichtet, indem Reason-Hint, erwarteter Pfad und sicherer Fallback expliziter gespiegelt werden. | Fehlende oder unsichere Customizer-Dateien bleiben im Admin früher einordenbar und führen schneller zum sicheren nächsten Schritt. |

### Delta Batch 441

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-editor.php` | umgesetzt | Theme-Editor-Entry weiter verdichtet, indem pro Fallback-Code ein strukturierter Hinweis vorbereitet und konsistenter an die View gereicht wird. | Customizer-Fallbacks bleiben nachvollziehbarer und hängen enger an einem kleinen Entry- und View-Vertrag. |

### Delta Batch 440

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-theme-explorer.js` | umgesetzt | Theme-Explorer-Asset weiter verdichtet, indem Editor-Submits einen gemeinsamen Pending- und Submit-Guard erhalten. | Speichern per Button und `Ctrl+S` bleibt robuster gegen Doppel-Submits und hektische Folge-Aktionen. |

### Delta Batch 439

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/themes/editor.php` | umgesetzt | Theme-Explorer-View weiter verdichtet, indem erlaubte Endungen, Skip-Segmente, Baumtiefe und Browser-Limits vorbereiteter gespiegelt werden. | Explorer-Grenzen bleiben früher sichtbar und hängen nicht nur implizit an Modulkonstanten oder späten Warning-Zuständen. |

### Delta Batch 438

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/ThemeEditorModule.php` | umgesetzt | Theme-Explorer-Modul weiter verdichtet, indem Browser-Editor-Validierungen strukturierte Fehlerresultate, engere Kontext-Sanitierung und zusätzliche Constraints erhalten. | Save- und Tree-Pfade bleiben nachvollziehbarer und enger am gemeinsamen Report- und Modulvertrag gebündelt. |

### Delta Batch 437

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-legal-sites.js` | umgesetzt | Legal-Sites-Asset weiter verdichtet, indem Post-Formulare einen gemeinsamen Pending- und Submit-Guard erhalten. | Speichern, Generieren und Seitenerstellung bleiben robuster gegen Doppel-Submits und hektische Folge-Klicks. |

### Delta Batch 436

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/legal/sites.php` | umgesetzt | Legal-Sites-View weiter verdichtet, indem Generator-Bereiche, Vorlagentypen, Feature-Toggles und HTML-Grenzen vorbereiteter gespiegelt werden. | Generator- und Eingabegrenzen bleiben früher sichtbar und hängen nicht nur implizit an Modulkonstanten oder späten Fehlermeldungen. |

### Delta Batch 435

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/legal/LegalSitesModule.php` | umgesetzt | Legal-Sites-Modul weiter verdichtet, indem Generator- und Profil-Constraints, Änderungszählung und strukturiertere Sammelseiten-/Fehlerdetails ergänzt werden. | Save-, Profil- und Generator-Pfade bleiben nachvollziehbarer und enger am gemeinsamen Report- und Modulvertrag gebündelt. |

### Delta Batch 434

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-font-manager.js` | umgesetzt | Font-Manager-Asset weiter verdichtet, indem Formulare und Delete-Aktionen einen gemeinsamen Pending- und Submit-Guard erhalten. | Scan-, Save-, Download- und Delete-Aktionen bleiben robuster gegen Doppel-Submits und hektische Folge-Klicks. |

### Delta Batch 433

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/themes/fonts.php` | umgesetzt | Font-Manager-View weiter verdichtet, indem Scan-, Host- und Download-Grenzen als vorbereitete Hinweis- und Formulartexte direkter im Admin gespiegelt werden. | Scan- und Self-Hosting-Limits bleiben früher sichtbar und hängen nicht nur implizit an Modulkonstanten oder Fehlermeldungen. |

### Delta Batch 432

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/FontManagerModule.php` | umgesetzt | Font-Manager-Modul weiter verdichtet, indem zusätzliche Constraints, Save-/Delete-Details und strukturiertere Bulk-Download-Fehler ergänzt werden. | Font-Scan-, Settings- und Sammeldownload-Pfade bleiben nachvollziehbarer und enger am gemeinsamen Report- und Modulvertrag gebündelt. |

### Delta Batch 431

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/plugins/marketplace.php` | umgesetzt | Plugin-Marketplace-View weiter verdichtet, indem erlaubte Hosts sowie Manifest- und Archivgrenzen zusätzlich direkt im Admin genannt werden. | Remote- und Auto-Install-Limits bleiben früher sichtbar und hängen nicht nur implizit an Badge- oder Fehlerzuständen. |

### Delta Batch 430

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/plugins/PluginMarketplaceModule.php` | umgesetzt | Plugin-Marketplace-Modul weiter verdichtet, indem Install-Erfolge Zielpfad und SHA-256-Verifizierungsstatus spiegeln und Installer-Fehler mehr Kontext zurückgeben. | Installationsläufe lassen sich im Marketplace klarer einordnen, ohne nur auf einen knappen Updater-Satz angewiesen zu sein. |

### Delta Batch 429

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/plugins/PluginMarketplaceModule.php` | umgesetzt | Plugin-Marketplace-Modul weiter verdichtet, indem Remote-Registry-Ladevorgänge Fehler- und Detailkontext für Cache-/Local-/None-Fallbacks vorbereiten. | Remote-Ausfälle und leere bzw. unbrauchbare Registry-Antworten bleiben im Admin präziser sichtbar, statt still in generische Fallback-Meldungen zu fallen. |

### Delta Batch 428

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/media/library.php` | umgesetzt | Media-Bibliotheks-View weiter verdichtet, indem Finder-Grenzen, Upload-Paketlimits und Formular-Maxima direkt im Admin gespiegelt werden. | Upload-, Such- und Ordnergrenzen bleiben für Nutzer früher sichtbar und hängen nicht mehr nur implizit an Entry-/Modulkonstanten. |

### Delta Batch 427

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/media/categories.php` | umgesetzt | Media-Kategorien-View weiter verdichtet, indem Name-/Slug-Limits und geschützte System-Slugs expliziter im Admin genannt werden. | Kategorie-Formulare folgen damit sichtbarer demselben Vertrag wie das Backend statt an losen UI-Annahmen zu hängen. |

### Delta Batch 426

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/media/settings.php` | umgesetzt | Media-Settings-View weiter verdichtet, indem Upload-, Qualitäts- und Dimensionslimits direkt aus vorbereiteten Modul-Constraints gerendert werden. | Die Settings-Oberfläche reduziert lokale Schattenwerte und bleibt enger an der serverseitigen Validierung. |

### Delta Batch 425

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/media/MediaModule.php` | umgesetzt | Media-Modul weiter verdichtet, indem Constraints, Erfolgsdetails sowie `error_details`/Report-Kontexte für Medien-Aktionen ergänzt werden. | Upload-, Delete-, Kategorie- und Settings-Pfade bleiben dadurch nachvollziehbarer und robuster an einem kleinen Modulvertrag gebündelt. |

### Delta Batch 424

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/media.php` | umgesetzt | Media-Entry weiter verdichtet, indem unbekannte Aktionen, Berechtigungsfehler und ungültige Payloads als strukturierte Failure-Rückgaben mit Report-Kontext laufen. | Entry-nahe Medienfehler hängen damit konsistenter am gemeinsamen Admin-Rahmen statt an nackten Fehlersätzen. |

### Delta Batch 423

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/themes/editor.php` | umgesetzt | Theme-Explorer-View weiter verdichtet, indem Editor-Limits zusätzlich im Dateibaum und Datei-Kopf gespiegelt werden. | Dateigröße und Bearbeitungsgrenzen bleiben für Nutzer sichtbarer, bevor Save-Sperren oder Oversize-Dateien erst im Fehlerpfad auffallen. |

### Delta Batch 422

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/ThemeEditorModule.php` | umgesetzt | Theme-Explorer-Modul weiter verdichtet, indem Save-Fehler strukturierte Report-Payloads liefern und Save-Erfolge Dateikontext mitgeben. | Fehler und Erfolgszustände hängen konsistenter am gemeinsamen Admin-Vertrag statt nur an kurzen Meldetexten. |

### Delta Batch 421

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-explorer.php` | umgesetzt | Theme-Explorer-Entry weiter verdichtet, indem ungültige Save-Payloads als strukturierte Failure-Rückgaben mit Report-Kontext laufen. | Entry-nahe Ablehnungen bleiben reportbar und konsistenter zum übrigen Shell-/Flash-Vertrag. |

### Delta Batch 420

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/themes/customizer-missing.php` | umgesetzt | Theme-Editor-Fallback weiter verdichtet, indem Reason-Code, Theme-Slug und erwarteter Customizer-Pfad direkt angezeigt werden. | Fehlende oder unsichere Customizer-Dateien bleiben für Theme-Entwickler transparenter und schneller einordenbar. |

### Delta Batch 419

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-editor.php` | umgesetzt | Theme-Editor-Entry weiter verdichtet, indem Fallback-Zustand mit Reason-Code und erwarteter Customizer-Datei vorbereitet wird. | Der Fallback hängt sichtbarer an einem kleinen Runtime-State statt an reinem Freitext. |

### Delta Batch 418

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/legal/sites.php` | umgesetzt | Legal-Sites-View weiter verdichtet, indem vorbereitete Kennzahlen und sichere Eingabegrenzen direkt im Admin gespiegelt werden. | Generator- und Eingabezustände bleiben für Nutzer früher sichtbar und hängen nicht mehr nur implizit an Formularen. |

### Delta Batch 417

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/legal/LegalSitesModule.php` | umgesetzt | Legal-Sites-Modul weiter verdichtet, indem Fehlerpfade `details`, `error_details` und `report_payload` liefern und Status-/Constraint-Daten vorbereitet werden. | Save-, Validierungs- und Generatorfehler hängen damit enger am gemeinsamen Admin-Fehlervertrag und die View erhält mehr serverseitig vorbereiteten Zustand. |

### Delta Batch 416

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/legal-sites.php` | umgesetzt | Legal-Sites-Entry weiter verdichtet, indem Berechtigungs- und Requestfehler als strukturierte Failure-Rückgaben mit Report-Kontext normalisiert werden. | Entry-nahe Fehlerpfade bleiben konsistenter zum restlichen Admin-Rahmen und lassen sich ohne Sonderpfade reporten. |

### Delta Batch 415

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/themes/fonts.php` | umgesetzt | Font-Manager-View weiter verdichtet, indem Dateigröße, CSS-Pfad und Asset-Status lokaler Fonts sichtbarer direkt in der Tabelle gespiegelt werden. | Fehlende Font- oder CSS-Dateien bleiben für Admin-Nutzer transparenter und hängen nicht länger nur implizit an Backend-Checks oder Delete-Fehlern. |

### Delta Batch 414

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/FontManagerModule.php` | umgesetzt | Font-Manager-Modul weiter verdichtet, indem Download-/Delete-Fehler strukturierte Report-Payloads liefern und lokale Font-Dateimetadaten serverseitig vorbereitet werden. | Font-Fehler lassen sich gezielter reporten, und die View muss Asset-Status nicht mehr aus rohen DB-Werten ableiten. |

### Delta Batch 413

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/font-manager.php` | umgesetzt | Font-Manager-Entry weiter verdichtet, indem Berechtigungs- und Payloadfehler als strukturierte Failure-Rückgaben mit Detail- und Report-Kontext an die Section-Shell laufen. | Ungültige Delete-/Download-/Save-POSTs bleiben im Admin nachvollziehbarer und können direkt in denselben Error-Report-Pfad überführt werden. |

### Delta Batch 412

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/plugins/marketplace.php` | umgesetzt | Plugin-Marketplace-View weiter verdichtet, indem Paketlimit und Registry-TTL zusätzlich direkt über dem Suchbereich genannt werden. | Auto-Install- und Cache-Grenzen sind früher sichtbar und hängen nicht mehr nur implizit an Warnbadges oder Fallback-Meldungen. |

### Delta Batch 411

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/plugins/PluginMarketplaceModule.php` | umgesetzt | Plugin-Marketplace-Modul weiter verdichtet, indem Installationsfehler mit `details`, `error_details` und `report_payload` sowie weiteren Constraint-Metadaten zurückgegeben werden. | Remote-, Paket- und Zielpfadprobleme hängen damit enger am gemeinsamen Admin-Fehlervertrag statt an bloßen Textmeldungen. |

### Delta Batch 410

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/plugin-marketplace.php` | umgesetzt | Plugin-Marketplace-Entry weiter verdichtet, indem Berechtigungs-, Payload- und Katalogfehler als strukturierte Failure-Rückgaben mit Report-Kontext normalisiert werden. | Entry-nahe Ablehnungen bleiben konsistenter zum restlichen Admin-Rahmen und lassen sich bei Bedarf ohne Zusatzpfade reporten. |

### Delta Batch 409

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/partials/section-page-shell.php` | umgesetzt | Gemeinsame Section-Shell weiter verdichtet, indem Alert-Typen, `error_details` und `report_payload` auch über Redirect- und Inline-Pfade vollständig erhalten bleiben. | Strukturierte Fehler- und Report-Hinweise kommen jetzt zuverlässig in der Flash-Komponente an und werden nicht mehr im Shell-Rahmen abgeschnitten. |

### Delta Batch 408

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/plugins/marketplace.php` | umgesetzt | Plugin-Marketplace-View weiter verdichtet, indem Download-Host, Paketgröße, gekürzte Prüfsumme und Sperrgründe für Auto-Install klarer in den Karten sichtbar werden. | Installierbarkeit bleibt für Admin-Nutzer transparenter und hängt nicht länger nur implizit an Button- oder Badge-Zuständen. |

### Delta Batch 407

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/plugins/PluginMarketplaceModule.php` | umgesetzt | Plugin-Marketplace-Modul weiter verdichtet, indem Paketgrößen normalisiert, übergroße Pakete für Auto-Install gesperrt und Host-/Hash-/Paket-Metadaten expliziter aufbereitet werden. | Der Marketplace hängt Auto-Installationen enger an einem sicheren Paket- und Quellenvertrag und begründet manuelle Installpfade sichtbarer. |

### Delta Batch 406

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/themes/fonts.php` | umgesetzt | Font-Manager-View weiter verdichtet, indem Remote-Datei- und Gesamtgrößenlimits für direkte und erkannte Font-Downloads direkt im Admin gespiegelt werden. | Self-Hosting-Grenzen bleiben für Nutzer transparenter und tauchen nicht erst im Fehlerpfad nach einem Download-Versuch auf. |

### Delta Batch 405

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/FontManagerModule.php` | umgesetzt | Font-Manager-Modul weiter verdichtet, indem Remote-Font-Dateien zusätzlich über Gesamtvolumen begrenzt, Header gegen den erwarteten Font-Typ geprüft und Teil-Downloads bei Persistenzfehlern wieder entfernt werden. | Font-Self-Hosting reduziert weitere Remote- und Dateizustandsrisiken und hält Cleanup sowie Binärvalidierung klarer am Modulvertrag. |

### Delta Batch 404

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/media/categories.php` | umgesetzt | Media-Kategorien-View weiter verdichtet, indem die geschützten System-Slugs aus dem Modulvertrag statt aus einer lokalen View-Liste übernommen werden. | Delete-Grenzen bleiben zwischen Modul und UI konsistenter, und neue System-Kategorien müssen nicht mehr an mehreren Stellen synchron gehalten werden. |

### Delta Batch 403

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/media/library.php` | umgesetzt | Media-Bibliotheks-View weiter verdichtet, indem Breadcrumbs, Ansichts-URLs, Datei-/Ordner-Metadaten und Größenformate direkt aus vorbereiteten ViewModels gerendert werden. | Das Template reduziert weitere lokale Hilfsfunktionen und hält Navigation, Anzeigezustand sowie Delete-/Browse-Daten näher an einem kleinen Modulvertrag. |

### Delta Batch 402

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/media.php` | umgesetzt | Media-Upload-Flash-Vertrag weiter verdichtet, indem Batch-Fehler und modulare Upload-Fehler als strukturierte Detail-Liste samt optionalem Report-Kontext an die Section-Shell weitergegeben werden. | Upload-Probleme bleiben im Admin nachvollziehbarer und lassen sich gezielter als Report erfassen, statt nur als zusammengedrückte Sammelmeldung sichtbar zu sein. |

### Delta Batch 401

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/media/MediaModule.php` | umgesetzt | Media-Modul weiter verdichtet, indem Bibliothekszustand, Breadcrumbs, Kategorieoptionen sowie Datei-/Ordner-ViewModels mit Anzeige-Metadaten serverseitig vorbereitet werden. | Die Medienbibliothek reduziert weitere Template-Logik, hält Browse-/Confirm-Pfade klarer an einem Datenvertrag und trennt Darstellung besser von Medien- und Pfadlogik. |

### Delta Batch 400

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/themes/fonts.php` | umgesetzt | Font-Manager-View weiter verdichtet, indem Scan-Quelle und Zeitstempel explizit im Admin gespiegelt werden. | Theme-Scan-Ergebnisse bleiben transparenter, weil Live- und Cache-Daten direkt unterscheidbar sind. |

### Delta Batch 399

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/FontManagerModule.php` | umgesetzt | Font-Manager-Modul weiter verdichtet, indem Theme-Scan-Ergebnisse gecacht, bei Mutationen invalidiert und Sammeldownload-/Scan-Ergebnisse strukturierter an die UI zurückgegeben werden. | Wiederholte Theme-Scans werden reduziert, und Flash-Hinweise hängen klarer an kleinen Ergebnis- statt an bloßen Textverträgen. |

### Delta Batch 398

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/plugins/marketplace.php` | umgesetzt | Plugin-Marketplace-View weiter verdichtet, indem Cache-Stand und Cache-Alter für die Katalogquelle direkt im Admin angezeigt werden. | Registry-Fallbacks und Cache-Nutzung bleiben transparenter, statt nur über eine generische Warnmeldung sichtbar zu werden. |

### Delta Batch 397

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/plugins/PluginMarketplaceModule.php` | umgesetzt | Plugin-Marketplace-Modul weiter verdichtet, indem die Remote-Registry mit TTL gecacht, Cache-Fallbacks als eigene Quelle markiert und Installationsfehler strukturierter mitgegeben werden. | Der Marketplace entkoppelt weitere Remote-Latenzen vom Request und hält Herkunfts- sowie Fehlerkontext konsistenter am Modulvertrag. |

### Delta Batch 396

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/plugin-marketplace.php` | umgesetzt | Plugin-Marketplace-Entry weiter verdichtet, indem Install-Slugs begrenzt und zusätzlich gegen den aktuellen Katalog geprüft werden. | Veraltete oder manipulierte Install-POSTs werden früher verworfen und laufen nicht mehr unnötig in den Installpfad hinein. |

### Delta Batch 395

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/themes/fonts.php` | umgesetzt | Font-Manager-UI weiter verdichtet, indem mutierende Formulare über einen kleinen Pending-/Status-Vertrag vereinheitlicht werden. | Scan-, Download-, Delete- und Save-Aktionen bleiben im Admin konsistenter und zeigen laufende Zustände sichtbarer an. |

### Delta Batch 394

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-font-manager.js` | umgesetzt | Font-Manager-Asset weiter verdichtet, indem laufende Form- und Delete-Aktionen einen gemeinsamen Pending-Button-Pfad erhalten. | Der Font-Manager reduziert weitere Doppel-Submits und hält Confirm- sowie Direktsubmit-Verhalten näher an einem gemeinsamen UI-Vertrag. |

### Delta Batch 393

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/assets/js/admin-theme-explorer.js` | umgesetzt | Theme-Explorer-Asset weiter verdichtet, indem Dirty-State, Pending-Save und Such-/Ordnersichtbarkeit klarer an der Explorer-Konfiguration hängen. | Der Explorer schützt ungespeicherte Änderungen besser, verhindert Mehrfachspeichern und bleibt bei Dateifiltern übersichtlicher. |

### Delta Batch 392

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/themes/editor.php` | umgesetzt | Theme-Explorer-View weiter verdichtet, indem Dateibaum-Grenzen, Warnungen und Save-Status direkt im UI gespiegelt werden. | Nutzer sehen Schutzgrenzen und Save-Zustände früher, statt erst bei abgeschnittenen Bäumen oder mehrfachen Save-Versuchen überrascht zu werden. |

### Delta Batch 391

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/ThemeEditorModule.php` | umgesetzt | Theme-Dateibaum- und Pfadlogik weiter verdichtet, indem Eintrags- und Verzeichnislimits, ausgesparte Hotspot-Segmente und vorbereitete Baum-Metadaten ergänzt werden. | Der Explorer reduziert weitere I/O-Hotspots in großen Themes und liefert seine Schutzgrenzen konsistenter vorbereitet an die View. |

### Delta Batch 390

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-explorer.php` | umgesetzt | Theme-Explorer-Entry weiter verdichtet, indem Guard-, CSRF-, Flash-, Redirect- und Save-Dispatch-Logik auf den gemeinsamen Section-Shell-/Payload-Vertrag umgestellt wird. | Der Explorer hängt näher am standardisierten Admin-Rahmen und hält Save-POSTs klarer an einem kleinen Entry-Vertrag. |

### Delta Batch 389

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/themes/fonts.php` | umgesetzt | Font-Manager-UI weiter verdichtet, indem Scan-Schutzgrenzen, übersprungene Dateien und Formularlimits direkt im Admin gespiegelt werden. | Theme-Scans und Typografie-Formulare bleiben transparenter, weil Schutzgrenzen und Begrenzungen nicht mehr nur implizit im Backend wirken. |

### Delta Batch 388

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/FontManagerModule.php` | umgesetzt | Font-Scan- und Save-Pfade weiter verdichtet, indem Theme-Dateiscans auf Datei-, Größen- und Pfadgrenzen begrenzt, Font-Zuweisungen gegen echte Auswahlwerte geprüft und Scan-Zusammenfassungen vorbereitet werden. | Der Font-Manager reduziert weitere I/O-Ausreißer, hält Typografie-Einstellungen näher an real verfügbaren Fonts und liefert View-relevante Limits/Warnungen konsistenter aus dem Modul. |

### Delta Batch 387

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/font-manager.php` | umgesetzt | Font-Manager-Entry weiter verdichtet, indem erlaubte Aktionen, Font-ID, Google-Font-Name sowie Save-Settings in einem kleinen Request-Vertrag normalisiert und validiert werden. | Delete-, Download- und Save-Aktionen hängen damit sichtbarer an einem engeren Entry-Payload statt an rohen Formularwerten. |

### Delta Batch 386

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-editor.php`, `CMS/admin/views/themes/customizer-missing.php` | umgesetzt | Theme-Editor-Entry weiter verdichtet, indem der eigene Layout-Sonderpfad entfernt und Customizer-Fallbacks als vorbereiteter Runtime-State plus dedizierte Admin-View gerendert werden. | Der Theme-Editor hängt näher am gemeinsamen Section-Shell-Vertrag, und fehlende oder unsichere Customizer-Dateien landen transparenter in einem klaren Admin-Fallback statt in Entry-lokalem HTML. |

### Delta Batch 385

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/settings.php`, `CMS/admin/views/media/library.php` | umgesetzt | Medien-Entry, Upload-Batch und Settings-Vertrag weiter verdichtet, indem Dateianzahl, Batch-Größe, Dateinamen, Zahlengrenzen und Typauswahlen enger normalisiert sowie UI-Grenzen sichtbarer vorgegeben werden. | Die Medienverwaltung verwirft inkonsistente Upload-Payloads früher, hält Settings näher an erlaubten Typgruppen und spiegelt Grenzwerte direkter im Admin wider, statt Fehler erst tief im Service-Pfad sichtbar zu machen. |

### Delta Batch 384

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/legal-sites.php`, `CMS/admin/modules/legal/LegalSitesModule.php` | umgesetzt | Legal-Sites-Entry und Modul weiter verdichtet, indem Rechtstext-Payloads früher normalisiert, fehlgeschlagene Save-Inhalte für den nächsten Render gehalten und Capability-Grenzen auch im Modul selbst nachgezogen werden. | Rechtstext- und Seitenzuordnungs-Formulare bleiben bei Validierungsfehlern stabiler am letzten POST-Zustand, und der Legal-Sites-Pfad hängt seine Berechtigungsgrenze nicht mehr nur am Entry. |

### Delta Batch 383

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/ThemeEditorModule.php`, `CMS/admin/views/themes/editor.php` | umgesetzt | Theme-Explorer-Editor weiter verdichtet, indem Dateimetadaten, Bearbeitbarkeitsstatus und Save-Sperrgründe serverseitig vorbereitet und im Editor direkt sichtbar bzw. wirksam gemacht werden. | Der Theme-Explorer reduziert weitere versteckte Save-Fehlerpfade, zeigt Größe/Typ/Bearbeitbarkeit früher an und sperrt nicht sicher editierbare Dateien bereits im UI. |

### Delta Batch 382

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/plugins/PluginMarketplaceModule.php`, `CMS/admin/modules/themes/ThemeMarketplaceModule.php`, `CMS/admin/views/plugins/marketplace.php`, `CMS/admin/views/themes/marketplace.php` | umgesetzt | Marketplace-Fehler- und Fallbackpfade weiter geglättet, indem Plugin- und Theme-Kataloge ihre Quelle inklusive Remote-/Fallback-Status explizit an die Views melden und diese Quelle direkt im Admin angezeigt wird. | Marketplace-Ansichten bleiben bei Remote-Ausfällen transparenter, weil lokale Fallbacks oder fehlende Kataloge nicht länger nur implizit über leere Listen oder stilles Verhalten sichtbar werden. |

### Delta Batch 381

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-explorer.php`, `CMS/admin/views/themes/editor.php`, `CMS/assets/js/admin-theme-explorer.js` | umgesetzt | Den Theme-Explorer weiter verdichtet, indem Editor-Interaktionen aus dem Template in ein dediziertes Admin-Asset verlagert, Dateifilter ergänzt und die Konfiguration kleiner zwischen Entry, View und JS verteilt wird. | Der Theme-Explorer reduziert weiteren Inline-JavaScript-Boilerplate, hält Tastatur- und Filterlogik sichtbarer am Asset-Vertrag und bleibt bei größeren Dateibäumen bedienbarer. |

### Delta Batch 380

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/landing-page.php`, `CMS/admin/views/landing/page.php` | umgesetzt | Den Landing-Entry weiter verdichtet, indem Aktions-, Feature-, Plugin- und Tab-Payloads enger normalisiert und Formular-Redirects konsequent an den aktiven Tab zurückgeführt werden. | Der Landing-Admin reduziert weitere POST-/Redirect-Sonderpfade, bleibt bei Fehlern und Save-Aktionen im richtigen Tab und hält Entry- sowie Formularvertrag klarer. |

### Delta Batch 379

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/comments/CommentsModule.php`, `CMS/admin/views/comments/list.php` | umgesetzt | Die Kommentar-Moderation weiter verdichtet, indem KPI-Karten und statusabhängige Zeilenaktionen serverseitig vorbereitet und in der View nur noch gerendert werden. | Die Kommentar-Liste reduziert weitere Status-/Rechte-Verzweigungen im Template, hängt sichtbarer an vorbereiteten Zeilenmodellen und bleibt stabiler bei künftigen Moderations-Erweiterungen. |

### Delta Batch 378

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/tables/TablesModule.php`, `CMS/admin/site-tables.php`, `CMS/admin/views/tables/edit.php`, `CMS/assets/js/admin-site-tables.js` | umgesetzt | Den Tabellen-Editor weiter verdichtet, indem Spalten-/Zeilenstrukturen, Größenlimits und Save-Redirects enger normalisiert sowie Editor-Limits vorbereitet an View und Asset übergeben werden. | Die Tabellen-Bearbeitung reduziert weitere Payload- und Größenrisiken, hält Save-Fehler näher am Editor und trennt Validierung, Limits sowie Editorzustand klarer zwischen Modul, Entry und Asset. |

### Delta Batch 377

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/menu-editor.php`, `CMS/admin/modules/menus/MenuEditorModule.php`, `CMS/admin/views/menus/editor.php`, `CMS/assets/js/admin-menu-editor.js` | umgesetzt | Den Menü-Editor weiter verdichtet, indem Payload-Fehler und Größenlimits früher im Entry greifen, Theme-Positionen sowie Menü-Items serverseitig strikter validiert werden und Page-Picker-/Editor-Konfiguration vorbereitet aus dem Modul in View und Asset fließt. | Der Menü-Editor reduziert weitere Trust-Boundary-Schwächen bei URL-, Parent- und Item-Payloads, hängt sichtbarer an einem kleineren Modul-/View-Vertrag und gibt Picker-/Editor-Fehler früher und konsistenter im Admin zurück. |

### Delta Batch 376

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/landing/LandingPageModule.php`, `CMS/admin/views/landing/page.php` | umgesetzt | Landing-ViewModels weiter verdichtet, indem Content-Typen, Feature-Karten und Plugin-Karten serverseitig vorbereitet und in der View nur noch gerendert werden. | Der Landing-Admin reduziert weitere Template-Logik, hängt Plugin-Metadaten robuster an echten Service-Feldern und trennt Override-/Darstellungsdetails klarer vom Markup. |

### Delta Batch 375

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-marketplace.php`, `CMS/admin/modules/themes/ThemeMarketplaceModule.php`, `CMS/admin/views/themes/marketplace.php`, `CMS/assets/js/admin-theme-marketplace.js` | umgesetzt | Theme-Marketplace-Wrapper weiter verdichtet, indem Action-/Theme-Slug-Fehler über einen gemeinsamen Payload laufen, Statusmetriken serverseitig vorbereitet und Install-Buttons im UI gegen Mehrfachsubmit abgesichert werden. | Der Theme-Marketplace hält seinen Entry näher am Section-Shell-Vertrag, trennt Statuszähler und Statusfilter klarer vom Template und vermeidet doppelte Installationsversuche durch einen klaren Submit-Zustand im Admin. |

### Delta Batch 374

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/plugin-marketplace.php`, `CMS/admin/modules/plugins/PluginMarketplaceModule.php`, `CMS/admin/views/plugins/marketplace.php`, `CMS/assets/js/admin-plugin-marketplace.js` | umgesetzt | Plugin-Marketplace-Wrapper weiter verdichtet, indem Action-/Slug-Fehler über einen gemeinsamen Payload laufen, Filterdaten serverseitig vorbereitet und Install-Buttons im UI gegen Mehrfachsubmit abgesichert werden. | Der Marketplace hält seinen Entry näher am Section-Shell-Vertrag, trennt Filter-/Statusoptionen klarer vom Template und vermeidet hektische Doppel-Installationsversuche durch einen klaren Submit-Zustand im Admin. |

### Delta Batch 373

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/landing/LandingPageModule.php`, `CMS/admin/views/landing/page.php`, `CMS/core/Services/Landing/LandingHeaderService.php`, `CMS/core/Services/Landing/LandingDefaultsProvider.php`, `CMS/install/InstallerService.php`, `CMS/themes/cms-default/partials/home-landing.php` | umgesetzt | Landing-Header-Vertrag weiter verdichtet, indem das Hero-Badge als eigener Admin-/Service-Wert geführt und im Frontend leerlaufsicher gerendert wird. | Der Landing-Admin kann Badge-Texte jetzt frei pflegen, bestehende Installationen bleiben durch den Versions-Fallback kompatibel und das Hero-Markup blendet das Badge automatisch aus, wenn kein Text hinterlegt ist. |

### Delta Batch 372

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/comments.php`, `CMS/admin/modules/comments/CommentsModule.php`, `CMS/admin/views/comments/list.php`, `CMS/admin/landing-page.php`, `CMS/admin/views/landing/page.php`, `CMS/admin/menu-editor.php`, `CMS/admin/modules/menus/MenuEditorModule.php`, `CMS/admin/site-tables.php`, `CMS/admin/modules/tables/TablesModule.php`, `CMS/admin/views/tables/list.php`, `CMS/admin/theme-editor.php`, `CMS/admin/theme-explorer.php`, `CMS/admin/modules/themes/ThemeEditorModule.php`, `CMS/admin/views/themes/editor.php` | umgesetzt | Rechte-, ViewModel-, Redirect- und Dateipfadverträge für Kommentar-, Landing-, Menü-, Tabellen- und Theme-Editor-Pfade weiter verdichtet. | Kommentare liefern vorbereitete Status-Tabs und Zeilenmodelle ohne direkte `$_GET`-Abhängigkeit im Modul, Landing-Tabs und Plugin-Overrides hängen sauberer an vorkonfigurierten View-Daten, der Menü-Editor vermeidet Doppel-Bootstrap und landet nach neuen Menüs korrekt im Editor, Tabellen kapseln Such-/Listen- und Fehlerpfade klarer, und Theme-Editor/Explorer erzwingen engere Capability- sowie Hidden-Path-Grenzen für Customizer- und Dateibaum-Zugriffe. |

### Delta Batch 371

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-marketplace.php`, `CMS/admin/modules/themes/ThemeMarketplaceModule.php`, `CMS/admin/views/themes/marketplace.php`, `CMS/assets/js/admin-theme-marketplace.js`, `CMS/admin/modules/plugins/PluginMarketplaceModule.php`, `CMS/admin/views/plugins/marketplace.php`, `CMS/assets/js/admin-plugin-marketplace.js`, `CMS/admin/landing-page.php`, `CMS/admin/modules/landing/LandingPageModule.php`, `CMS/admin/views/comments/list.php`, `CMS/admin/views/menus/editor.php`, `CMS/admin/views/tables/edit.php` | umgesetzt | Theme-/Plugin-Marketplace-Verträge weiter gehärtet, Landing-Defaults nur noch lazy initialisiert und verbleibende View-/JSON-Restpfade in Kommentar-, Menü- und Tabellen-Views verdichtet. | Der Theme-Marketplace validiert Registry-/Manifest-/Asset-Pfade restriktiver, dedupliziert Theme-Slugs sauber und liefert Such-/Statusfilter; der Plugin-Marketplace zeigt manuelle bzw. inkompatible Kandidaten klarer an, während Landing-, Kommentar-, Menü- und Tabellenpfade weniger Inline-/Template-Boilerplate und stabilere JSON-/Escaping-Grenzen mitbringen. |

### Delta Batch 370

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/pages.php`, `CMS/admin/posts.php`, `CMS/core/Services/CoreModuleService.php`, `CMS/admin/modules.php`, `CMS/admin/views/system/modules.php`, `CMS/admin/partials/sidebar.php` | umgesetzt | Produktive Admin-Fatals durch fehlenden `CMS\Security`-Import behoben und die Aboverwaltung zusätzlich an eine zentrale Core-Modul-Registry mit Admin- und Runtime-Gates gehängt. | Pages- und Posts-Editorpfade laufen wieder stabil, während integrierte Abo-Module künftig zentral unter `System -> Module` geschaltet werden können; deaktivierte Bereiche verschwinden im Admin und werden in Dashboard-, Member- sowie Subscription-Laufzeitpfaden wirksam abgeschaltet. |

### Delta Batch 369

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/plugins/PluginMarketplaceModule.php` | umgesetzt | Den Plugin-Marketplace-Katalog weiter gehärtet, indem installierte Slugs eindeutig normalisiert, kollidierende Manifest-Slugs verworfen und doppelte Katalogeinträge vor der URL-/Installationsauflösung dedupliziert werden. | Der Marketplace reduziert weitere Remote-Katalog-Sonderpfade, hält Auto-Installationsentscheidungen klarer an einem konsistenten Slug-/Manifest-Vertrag und vermeidet unnötige Mehrfach-Lookups gegen lokal installierte Plugins. |

### Delta Batch 368

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/error-report.php`, `CMS/core/Services/ErrorReportService.php` | umgesetzt | Den Error-Report-Trust-Boundary weiter verdichtet, indem der Admin-Entry nur noch normalisierte Report-Payloads übergibt und der Service Titel-, Message-, Status-, Source- sowie verschachtelte Kontext-/Fehlerdaten zusätzlich selbst sanitisiert. | Der Fehlerreport reduziert weiteren Request-Sonderpfad, hält Wrapper und Service näher an einem gemeinsamen Payload-Vertrag und bleibt robuster gegen übergroße, inkonsistente oder roh verschachtelte Report-Daten. |

### Delta Batch 367

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/users/roles.php`, `CMS/assets/js/admin-users.js` | umgesetzt | Die Rollen-Ansicht weiter harmonisiert, indem Gruppen-Toggles sowie Role-/Capability-Modalpfade aus lokalem Inline-Script in Datenattribute plus gemeinsames Users-Admin-Asset überführt wurden. | Die Rollenverwaltung reduziert weiteren Inline-JavaScript-Boilerplate, hängt sichtbarer am Admin-Asset-Vertrag und hält Markup, Modalzustand sowie Rechte-Interaktionen klarer getrennt vom PHP-Template. |

### Delta Batch 366

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/roles.php` | umgesetzt | Den Roles-Entry weiter verdichtet, indem erlaubte Aktionen einmalig normalisiert, über einen gemeinsamen Action-Helper dispatcht und das Users-Admin-Asset sauber über `pageAssets` eingebunden wird. | Der Entry reduziert weiteren Dispatch-Boilerplate, bleibt lesbarer wartbar und hält Aktionsprüfung, Payload-Normalisierung sowie Asset-Vertrag klarer an einer kleinen Entry-Logik. |

### Delta Batch 365

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/users/list.php`, `CMS/assets/js/admin-users.js` | umgesetzt | Die Benutzer-Liste weiter harmonisiert, indem Grid-Initialisierung und Filter-Redirects aus lokalem Inline-JavaScript und Inline-`onchange`-Handlern in JSON-Konfiguration plus dediziertes Users-Admin-Asset überführt wurden. | Die Benutzerverwaltung reduziert weiteren Inline-JavaScript-Boilerplate, hängt sichtbarer am Admin-Asset-Vertrag und hält Markup, Filterzustand sowie Grid-Laufzeitlogik klarer getrennt vom PHP-Template. |

### Delta Batch 364

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/users.php` | umgesetzt | Den Users-Entry weiter verdichtet, indem Aktion, ID, Bulk-Aktion und Bulk-IDs einmalig in einem gemeinsamen Payload normalisiert, die Grid-Konfiguration über einen Helper aufgebaut und das Users-Admin-Asset sauber eingebunden wird. | Der Entry reduziert weiteren Dispatch- und Inline-Asset-Boilerplate, bleibt lesbarer wartbar und hält Listen-Konfiguration, Payload-Normalisierung sowie Asset-Vertrag klarer an einer kleinen Entry-Logik. |

### Delta Batch 363

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/tables/edit.php`, `CMS/assets/js/admin-site-tables.js` | umgesetzt | Die Tabellen-Bearbeitungsansicht weiter harmonisiert, indem Spalten-/Zeilen-Editor, JSON-Serialisierung und generierte Inline-Handler aus lokalem Inline-Script in ein dediziertes Admin-Asset überführt wurden. | Die Tabellen-Bearbeitung reduziert weiteren Inline-JavaScript-Boilerplate, hängt sichtbarer am Admin-Asset-Vertrag und hält Editorzustand, Zeilen-/Spaltenmutationen sowie Submit-Serialisierung klarer getrennt vom PHP-Markup. |

### Delta Batch 362

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/tables/list.php`, `CMS/assets/js/admin-site-tables.js` | umgesetzt | Die Tabellen-Liste weiter harmonisiert, indem Suche, Duplicate- und Delete-Laufzeitlogik aus lokalen Inline-Handlern in Datenattribute plus dediziertes Admin-Asset überführt wurden. | Die Tabellenverwaltung reduziert weiteren Inline-JavaScript-Boilerplate, hängt sichtbarer am Admin-Asset-/Confirm-Vertrag und hält Suchzustand, Tabellen-Aktionen sowie Form-Dispatch klarer getrennt vom PHP-Markup. |

### Delta Batch 361

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/site-tables.php` | umgesetzt | Den Site-Tables-Entry weiter verdichtet, indem Aktion und Tabellen-ID einmalig in einem gemeinsamen Payload normalisiert und Listen-/Edit-Assets konsistenter über `pageAssets` gebunden werden. | Der Entry reduziert weiteren Dispatch-Boilerplate, bleibt lesbarer wartbar und hält Aktionsprüfung, Payload-Normalisierung sowie Asset-Vertrag klarer an einer kleinen Entry-Logik. |

### Delta Batch 360

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/menus/editor.php`, `CMS/assets/js/admin-menu-editor.js` | umgesetzt | Die Menü-Editor-Ansicht weiter harmonisiert, indem Modal-Trigger, Menü-Delete-Confirm und die Item-Editor-Laufzeitlogik aus lokalem Inline-Script in Datenattribute plus dediziertes Admin-Asset überführt wurden. | Die Menüverwaltung reduziert weiteren Inline-JavaScript-Boilerplate, hängt sichtbarer am Admin-Asset-/Confirm-Vertrag und hält Modalzustand, Item-Struktur sowie Delete-Flow klarer getrennt vom PHP-Markup. |

### Delta Batch 359

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/menu-editor.php` | umgesetzt | Den Menu-Editor-Entry weiter verdichtet, indem Aktion, Menü-ID und Item-JSON einmalig in einem gemeinsamen Payload normalisiert und die Handler-Map durch direkten Dispatch ersetzt wurden. | Der Entry reduziert weiteren Dispatch-Boilerplate, bleibt lesbarer wartbar und hält Guard, Menü-ID-Prüfung, Modul-Dispatch sowie Asset-Vertrag klarer an einer kleinen Entry-Logik. |

### Delta Batch 358

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/hub/templates.php`, `CMS/assets/js/admin-hub-sites.js` | umgesetzt | Die Hub-Template-Ansicht weiter harmonisiert, indem Duplicate-/Delete-Sonderpfade aus lokalen Inline-Handlern in Datenattribute plus gemeinsames Hub-Admin-Asset überführt wurden. | Die Hub-Template-Bibliothek reduziert weiteren Inline-JavaScript-Boilerplate, hängt sichtbarer am Admin-Asset-/Confirm-Vertrag und hält Template-Markup, Formularzustand sowie Delete-Flow klarer getrennt. |

### Delta Batch 357

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/hub/list.php`, `CMS/assets/js/admin-hub-sites.js` | umgesetzt | Die Hub-Site-Liste weiter harmonisiert, indem Suche, Clipboard-, Duplicate- und Delete-Laufzeitlogik aus lokalen Inline-Handlern in Datenattribute plus gemeinsames Hub-Admin-Asset überführt wurden. | Die Routing-Verwaltung reduziert weiteren Inline-JavaScript-Boilerplate, hängt sichtbarer am Admin-Asset-/Confirm-Vertrag und hält Suchzustand, Site-Aktionen sowie Clipboard-Flow klarer getrennt vom PHP-Markup. |

### Delta Batch 356

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/hub-sites.php` | umgesetzt | Den Hub-Sites-Entry weiter verdichtet, indem Aktion, Hub-Site-ID und Template-Key einmalig in einem gemeinsamen Payload normalisiert und Delete-/Duplicate- sowie Template-Aktionen enger validiert werden. | Der Entry reduziert weiteren Dispatch-Boilerplate, bleibt lesbarer wartbar und hält Aktionsprüfung, Payload-Normalisierung sowie Asset-Vertrag klarer an einer kleinen Entry-Logik. |

### Delta Batch 355

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/comments/list.php`, `CMS/assets/js/admin-comments.js` | umgesetzt | Die Kommentar-Moderationsansicht weiter harmonisiert, indem Status-/Delete-Aktionen sowie Bulk-/Dropdown-Laufzeitlogik aus lokalem Inline-Script in ein dediziertes Admin-Asset überführt wurden. | Die Kommentarverwaltung reduziert weiteren Inline-JavaScript-Boilerplate, hängt sichtbarer am Admin-Asset-/Confirm-Vertrag und hält Moderations-, Delete- sowie Bulk-UI klarer getrennt vom PHP-Markup. |

### Delta Batch 354

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/comments.php` | umgesetzt | Den Comments-Entry weiter verdichtet, indem Aktion, Kommentar-ID, Zielstatus, Bulk-Aktion und Bulk-IDs einmalig in einem gemeinsamen Payload normalisiert und vor dem Modul-Dispatch enger validiert werden. | Der Entry reduziert weiteren Dispatch-Boilerplate, bleibt lesbarer wartbar und hält Aktionsprüfung, Payload-Normalisierung sowie Asset-Vertrag klarer an einer kleinen Entry-Logik. |

### Delta Batch 353

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/users/groups.php`, `CMS/assets/js/admin-user-groups.js` | umgesetzt | Die Gruppen-Ansicht weiter harmonisiert, indem Modal- und Delete-Sonderpfade aus lokalen Inline-Handlern in Datenattribute plus dediziertes Admin-Asset überführt wurden. | Die Gruppenverwaltung reduziert weiteren Inline-JavaScript-Boilerplate, hängt sichtbarer am Admin-Asset-/Confirm-Vertrag und hält Karten-Markup, Modalzustand sowie Delete-Flow klarer getrennt. |

### Delta Batch 352

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/groups.php` | umgesetzt | Den Gruppen-Entry weiter verdichtet, indem Aktion und Gruppen-ID einmalig in einem gemeinsamen Payload normalisiert und die Handler-Map durch direkten Dispatch ersetzt wurden. | Der Entry reduziert weiteren Dispatch-Boilerplate, bleibt lesbarer wartbar und hält Guard, ID-Prüfung, Modul-Dispatch sowie Asset-Vertrag klarer an einer kleinen Entry-Logik. |

### Delta Batch 351

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/plugins/marketplace.php`, `CMS/assets/js/admin-plugin-marketplace.js` | umgesetzt | Die Such-/Filter- und Install-Confirm-Sonderpfade der Plugin-Marketplace-Ansicht aus lokalem Inline-Script und Inline-`confirm(...)` in dediziertes Admin-Asset plus gemeinsamen Confirm-Vertrag überführt. | Der Marketplace reduziert weiteren Inline-JavaScript-Boilerplate, hängt sichtbarer am Admin-Asset-/Confirm-Vertrag und hält Karten-Filter sowie Installations-UI klarer getrennt vom PHP-Markup. |

### Delta Batch 350

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/plugin-marketplace.php` | umgesetzt | Den Plugin-Marketplace-Entry weiter verdichtet, indem Aktion und Slug einmalig in einem gemeinsamen Payload normalisiert und das UI-Asset sauber über `page_assets` eingebunden werden. | Der Entry reduziert weiteren Dispatch-Boilerplate, bleibt lesbarer wartbar und hält Aktionsprüfung, Payload-Normalisierung sowie Asset-Vertrag klarer an einer kleinen Entry-Logik. |

### Delta Batch 349

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/pages/list.php`, `CMS/assets/js/admin-pages.js` | umgesetzt | Die Seitenliste weiter harmonisiert, indem Grid-, Bulk- und Filter-Laufzeitlogik aus dem lokalen View-Script in ein dediziertes Admin-Asset ausgelagert und Filter-`onchange`-Handler entfernt wurden. | Die Pages-Ansicht reduziert weiteren Inline-JavaScript-Boilerplate, hängt sichtbarer am Asset-Vertrag und hält Grid-/Bulk-Interaktionen klarer getrennt vom View-Markup. |

### Delta Batch 348

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/pages.php` | umgesetzt | Den Pages-Entry weiter verdichtet, indem die Listen-Grid-Konfiguration nicht länger als großer Inline-JavaScript-String gebaut, sondern als strukturierte Konfiguration an den View-/Asset-Vertrag übergeben wird. | Der Entry reduziert weiteren Inline-Asset-Boilerplate, bleibt lesbarer wartbar und trennt Listen-Konfiguration, Datenladung und Asset-Vertrag klarer voneinander. |

### Delta Batch 347

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/documentation.php` | umgesetzt | Die Dokumentations-Ansicht weiter verdichtet, indem Dokument- und Bereichsdaten kompakter über View-Model-Helfer vorbereitet werden statt mehrfach verstreut im Renderfluss zusammengebaut zu werden. | Die Doku-Ansicht reduziert weiteren Render-Boilerplate, bleibt lesbarer wartbar und konzentriert ihren Hauptfluss stärker auf Markup- und View-Blöcke statt auf wiederholte Einzelabfragen. |

### Delta Batch 346

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/mail-settings.php` | umgesetzt | Den Mail-Settings-Entry weiter verdichtet, indem Tab und Aktion einmalig in einem gemeinsamen Payload normalisiert und danach direkt über einen `match`-basierten Action-Helper dispatcht werden. | Der Entry reduziert weiteren Dispatch-Boilerplate, bleibt lesbarer wartbar und hält Guard, Tab-/Aktionsvalidierung sowie Redirect-Ziel klarer an einem kleinen Entry-Vertrag. |

### Delta Batch 345

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/landing-page.php` | umgesetzt | Den Landing-Page-Entry weiter verschlankt, indem Aktion und Feature-ID einmalig in einem gemeinsamen Payload normalisiert und die Closure-basierte Handler-Map durch direkten Dispatch ersetzt werden. | Der Entry reduziert weiteren Kontrollfluss-Boilerplate, bleibt lesbarer wartbar und hält Delete-Feature sowie die übrigen Landing-Aktionen sichtbarer am selben Request-Vertrag. |

### Delta Batch 344

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/legal/cookies.php`, `CMS/assets/js/admin-cookie-manager.js`, `CMS/admin/cookie-manager.php` | umgesetzt | Die verbleibenden Inline-Modal- und Delete-Sonderpfade der Cookie-Manager-Ansicht in ein dediziertes Admin-Asset überführt und die View auf den gemeinsamen Confirm-Vertrag harmonisiert. | Der Cookie-Manager reduziert weiteren Inline-JavaScript-Boilerplate, hängt sichtbarer am Asset-/Confirm-Vertrag und hält Kategorie-/Service-Modalpfade klarer getrennt vom PHP-Markup. |

### Delta Batch 343

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/cookie-manager.php` | umgesetzt | Den Cookie-Manager-Entry weiter verdichtet, indem Action, ID, Service-Slug und Self-Hosted-Flag einmalig in einem gemeinsamen Payload normalisiert und validiert werden. | Der Entry reduziert weiteren Kontrollfluss-Boilerplate, bleibt lesbarer wartbar und konzentriert seinen POST-Pfad stärker auf Guard, Payload-Normalisierung und direkten Modul-Dispatch. |

### Delta Batch 342

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/plugins/list.php`, `CMS/assets/js/admin-plugins.js`, `CMS/admin/plugins.php` | umgesetzt | Die verbliebenen Inline-`onchange`- und Delete-Confirm-Sonderpfade der Plugin-Liste auf dediziertes Asset plus gemeinsamen Confirm-Vertrag harmonisiert. | Die Plugin-Verwaltung reduziert weiteren Inline-UI-Boilerplate, hängt sichtbarer am Asset-/Confirm-Vertrag und hält Toggle- sowie Delete-Aktionen klarer getrennt vom View-Markup. |

### Delta Batch 341

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/plugins.php`, `CMS/admin/modules/plugins/PluginsModule.php` | umgesetzt | Den Plugins-Entry-/Modulvertrag weiter verdichtet, indem Action/Slug einmalig normalisiert und Slug-, Hauptdatei- sowie Pfadlogik im Modul über gemeinsame Helper gebündelt werden. | Die Plugin-Verwaltung reduziert weiteren Dispatch- und Pfad-Boilerplate, hängt sichtbarer an einem konsistenten Entry-/Modulvertrag und begrenzt Delete-/Pfadauflösungsreste robuster auf gemeinsame Hilfslogik. |

### Delta Batch 340

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/font-manager.php`, `CMS/admin/views/themes/fonts.php` | umgesetzt | Den Font-Manager weiter verdichtet, indem der Entry Aktions-Payloads einmalig normalisiert und die View überflüssige Preview-Inline-Styles an die bestehende JS-gesteuerte Vorschau abgibt. | Der Font-Manager reduziert weiteren Entry- und View-Boilerplate, hängt sichtbarer am gemeinsamen Wrapper-/Asset-Vertrag und hält Dispatch sowie Preview-Startzustand klarer getrennt. |

### Delta Batch 339

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/media/MediaModule.php`, `CMS/admin/views/media/settings.php` | umgesetzt | Den Media-Settings-Vertrag weiter verdichtet, indem Defaultwerte, Dateityp-Optionen, Thumbnail-Metadaten und MB-Aufbereitung aus dem lokalen View in das Modulmodell verlagert wurden. | Die Media-Einstellungen reduzieren weiteren View-Boilerplate, hängen sichtbarer am Modulvertrag und halten Defaults, Feldmapping sowie Formularoptionen klarer an einer zentralen Stelle. |

### Delta Batch 338

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/documentation.php` | umgesetzt | Den Dokumentations-Entry weiter verschlankt, indem POST-Aktionen nur noch über ein einmalig normalisiertes Payload plus direkten Action-Helper dispatcht werden statt über direkte `$_POST`-Zugriffe und eine kleine Handler-Map. | Der Doku-Entry reduziert weiteren Dispatch-Boilerplate, bleibt lesbarer wartbar und konzentriert seinen Request-Pfad stärker auf Guard, Normalisierung und klaren Modul-Dispatch. |

### Delta Batch 337

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/data-requests.php`, `CMS/admin/views/legal/data-requests.php`, `CMS/assets/js/admin-data-requests.js` | umgesetzt | Die verbliebenen lokalen Confirm- und Modal-Sonderpfade der DSGVO-Data-Requests-Ansicht auf den gemeinsamen Admin-Confirm-/Asset-Vertrag gezogen, indem Inline-`confirm(...)`-Aufrufe entfernt und der Reject-Dialog per dediziertem Script über Datenattribute angesteuert wird. | Der DSGVO-Bereich reduziert weiteren Inline-JavaScript-Boilerplate, hängt sichtbarer am gemeinsamen Admin-Vertrag und hält Markup, Formularaktionen sowie Modal-Laufzeitlogik klarer voneinander getrennt. |

### Delta Batch 336

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/plugin-marketplace.php`, `CMS/admin/modules/plugins/PluginMarketplaceModule.php` | umgesetzt | Den kritischen Marketplace-Remote-Pfad weiter gehärtet, indem der Entry nur noch den normalisierten Installations-Slug weiterreicht und das Modul Registry-, Manifest- sowie Katalog-URLs vor externen Requests zentral kanonisiert und auf erlaubte Host-/Port-/Credential-Kombinationen prüft. | Der Marketplace reduziert weiteren Request- und Remote-Validierungs-Sonderpfad, hängt sichtbarer an einem einheitlichen URL-Vertrag und begrenzt Remote-Ladepfade robuster auf erlaubte Quellen. |

### Delta Batch 335

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/legal-sites.php`, `CMS/admin/views/legal/sites.php`, `CMS/assets/js/admin-legal-sites.js` | umgesetzt | Den Legal-Sites-Cluster weiter gehärtet, indem der Entry `save`-/`save_profile`-Payloads allowlist-basiert normalisiert und die View ihre verbleibende DOM-/Template-Logik in ein dediziertes Admin-Asset auslagert. | Der Legal-Bereich reduziert weiteren Request- und Inline-JavaScript-Boilerplate, hängt sichtbarer am Wrapper-/Asset-Vertrag und trennt Markup, Payload-Filterung sowie Laufzeitlogik klarer voneinander. |

### Delta Batch 334

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/documentation.php` | umgesetzt | Die verbliebenen Render-Closures der Dokumentations-Ansicht in benannte View-Funktionen überführt und kleine Aufbereitungsreste wie Quellen-Text sowie Metric-Card-Konfiguration hinter präfixierte Helper gezogen. | Die Dokumentations-Ansicht reduziert weiteren Inline-Boilerplate, bleibt lesbarer wartbar und konzentriert ihren Hauptfluss stärker auf den eigentlichen Renderpfad statt auf lokale Render-Werkzeuge. |

### Delta Batch 333

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/media.php`, `CMS/admin/views/media/categories.php`, `CMS/assets/js/admin-media-integrations.js` | umgesetzt | Den Media-Cluster weiter gehärtet, indem der Entry kritische Action-Payloads pro Aktion auf normalisierte Felder begrenzt und die Kategorien-View ihren verbliebenen Inline-Delete-Scriptpfad an das bestehende Media-Admin-Script abgibt. | Der Media-Bereich reduziert weiteren Request- und Inline-JavaScript-Boilerplate, hängt sichtbarer am bestehenden Wrapper-/Asset-Vertrag und hält Markup, Payload-Normalisierung sowie Delete-Laufzeitlogik klarer voneinander getrennt. |

### Delta Batch 332

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/updates.php`, `CMS/assets/js/admin.js` | umgesetzt | Die verbleibenden Confirm-Aktionspfade des Updates-Views für Core- und Plugin-Installationen aus lokalem `confirm(...)`-Markup in einen wiederverwendbaren globalen Formular-Confirm-Helfer überführt. | Der Updates-View reduziert weiteren Inline-Boilerplate, hängt sichtbarer am gemeinsamen Admin-Confirm-Vertrag und hält Formular-Markup klarer getrennt von der Laufzeitlogik. |

### Delta Batch 331

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/media/library.php`, `CMS/assets/js/admin-media-integrations.js` | umgesetzt | Die verbleibenden Inline-Aktionspfade der Media-Library-View für Member-Ordner-Confirm und Delete-Aktionen aus `onclick`-/lokalem `<script>`-Markup in das bereits geladene Admin-Media-Script verlagert. | Die Media-Library reduziert weiteren Inline-JavaScript-Boilerplate, hängt sichtbarer am bestehenden Media-Asset-Vertrag und hält Markup sowie Aktionslogik klarer getrennt. |

### Delta Batch 330

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/themes/fonts.php`, `CMS/admin/font-manager.php`, `CMS/assets/js/admin-font-manager.js` | umgesetzt | Den verbleibenden Preview-/Inline-Script-Sonderpfad des Font-Manager-Views in eine dedizierte Admin-JS-Datei ausgelagert und die Asset-Einbindung über den bestehenden `pageAssets`-Vertrag des Entries nachgezogen. | Der Font-Manager-View reduziert weiteren Inline-JavaScript-Boilerplate, hängt sichtbarer am gemeinsamen Admin-Asset-Vertrag und hält Preview- sowie Delete-Confirm-Verhalten klarer getrennt vom PHP-Markup. |

### Delta Batch 329

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/font-manager.php` | umgesetzt | Das Closure-basierte Action-Handler-Mapping des Font-Manager-Entrys auf einen direkten `match`-Dispatch umgestellt und die Normalisierung von Font-ID sowie Google-Font-Familie im POST-Pfad auf einen einmaligen Schritt reduziert. | Der Font-Manager-Entry reduziert weiteren Inline-Dispatch-Boilerplate, bleibt lesbarer wartbar und konzentriert seinen Request-Flow stärker auf Guard, Normalisierung und direkten Modul-Dispatch. |

### Delta Batch 328

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/legal-sites.php` | umgesetzt | Den Action-Dispatch des Legal-Sites-Entrys vom Closure-basierten Handler-Mapping auf einen direkten `match`-Dispatch umgestellt und die Template-Typ-Normalisierung im POST-Pfad auf einen einmaligen Schritt reduziert. | Der Legal-Sites-Entry reduziert weiteren Inline-Dispatch-Boilerplate, bleibt lesbarer wartbar und konzentriert seinen Request-Flow stärker auf Guard, Normalisierung und direkten Modul-Dispatch. |

### Delta Batch 327

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/documentation.php` | umgesetzt | Die Helper-/Closure-Dichte im Dokumentations-View weiter reduziert, indem mehrere kleine Dokument-, Bereichs- und Active-State-Helfer aus anonymen Closures in benannte View-Funktionen überführt wurden. | Die Dokumentations-Ansicht reduziert weiteren Inline-Boilerplate, bleibt lesbarer wartbar und konzentriert ihren Renderpfad stärker auf Markup-/View-Blöcke statt auf verstreute kleine Datentransformations-Closures. |

### Delta Batch 326

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php` | umgesetzt | Die Parent-Path-Auflösung des Media-Entrys an den Modulvertrag delegiert und die Upload-Fehleraufbereitung so nachgezogen, dass Dateinamen nicht mehr vorzeitig HTML-escaped in Flash-Meldungen zusammengesetzt werden. | Der Media-Entry reduziert weiteren String-/Normalisierungs-Sonderpfad, hält Pfadlogik näher am Modul und vermeidet doppelte Escaping-Pfade im gemeinsamen Admin-Alert-Vertrag. |

### Delta Batch 325

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/updates.php` | umgesetzt | Die verbliebenen komplexeren Core- und Theme-Update-Warnboxen der Updates-View auf das gemeinsame `flash-alert.php` harmonisiert, damit auch diese Hinweise keinen separaten lokalen `alert alert-warning`-Sonderpfad mehr im View pflegen. | Die System-Updates-Ansicht reduziert weiteren lokalen UI-Boilerplate, hängt sichtbarer am zentralen Flash-Vertrag der Shared-Shell-Entrys und hält Aktionsblöcke klarer getrennt von der gemeinsamen Hinweisdarstellung. |

### Delta Batch 324

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/media/library.php` | umgesetzt | Den verbliebenen lokalen elFinder-Infohinweis der Media-Library-View auf das gemeinsame `flash-alert.php` harmonisiert, damit der Finder-Zweig keinen separaten einfachen Bootstrap-Alert mehr lokal im View pflegt. | Die Media-Library reduziert weiteren lokalen UI-Boilerplate, hängt sichtbarer am zentralen Flash-Vertrag der Shared-Shell-Entrys und übernimmt künftige Partial-Verbesserungen an Typ-Mapping, Detail-Listen und Dismiss-Verhalten automatisch mit. |

### Delta Batch 323

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/legal/sites.php` | umgesetzt | Die verbliebenen lokalen Intro- sowie mehrere einfache featurebezogene Datenschutz-Hinweisboxen der Legal-Sites-View auf das gemeinsame `flash-alert.php` harmonisiert, damit der Rechtstext-Generator keine verteilten Alert-Sonderpfade mehr im View pflegt. | Die Legal-Sites-Ansicht reduziert weiteren lokalen UI-Boilerplate, hängt sichtbarer am zentralen Flash-Vertrag der Shared-Shell-Entrys und übernimmt künftige Partial-Verbesserungen an Typ-Mapping, Detail-Listen und Dismiss-Verhalten automatisch mit. |

### Delta Batch 322

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/FontManagerModule.php`, `CMS/admin/views/themes/fonts.php` | umgesetzt | Den hoch priorisierten Font-Manager bei Fehler- und Hinweispfaden weiter gehärtet: Speichern/Löschen loggt Fehler jetzt strukturiert statt rohe Exception-Texte in die UI zu geben, und die verbleibende Self-Hosting-Hinweisbox der View läuft nun ebenfalls über das gemeinsame `flash-alert.php`. | Der Font-Manager reduziert UI-Leaks bei Modulfehlern, hängt näher an einem konsistenten Admin-Logging-Vertrag und spart weiteren lokalen View-Boilerplate, weil auch die größere Bibliotheks-Hinweisbox künftige Partial-Verbesserungen automatisch mitnimmt. |

### Delta Batch 321

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/diagnose.php`, `CMS/admin/views/member/general.php` | umgesetzt | Weitere einfache fachliche Info-/Warning-Hinweise in Diagnose- und Member-General-View auf das gemeinsame `flash-alert.php` harmonisiert, damit auch diese verbleibenden Alert-Sonderpfade nicht länger lokal pro View gepflegt werden. | Die betroffenen Views reduzieren weiteren UI-Boilerplate, hängen näher am zentralen Flash-Vertrag der Shared-Shell-Entrys und übernehmen künftige Partial-Verbesserungen an Typ-Mapping, Detail-Listen und Dismiss-Verhalten automatisch mit. |

### Delta Batch 320

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/updates.php`, `CMS/admin/views/system/documentation.php`, `CMS/admin/views/themes/fonts.php`, `CMS/admin/views/subscriptions/settings.php` | umgesetzt | Einfache Status-, Error-, Info- und Hinweisblöcke in vier weiteren Admin-Views auf das gemeinsame `flash-alert.php` harmonisiert und in der Dokumentations-View den lokalen `$renderAlertBlock`-Sonderpfad entfernt, damit UI-Hinweise nicht länger dateiweise als eigene Alert-Implementierungen gepflegt werden. | Die betroffenen Views reduzieren weiteren UI-Boilerplate, hängen näher am zentralen Flash-Vertrag der Shared-Shell-Entrys und übernehmen künftige Partial-Verbesserungen an Typ-Mapping, Detail-Listen und Dismiss-Verhalten automatisch mit. |

### Delta Batch 319

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/plugins.php`, `CMS/admin/themes.php`, `CMS/admin/modules/plugins/PluginsModule.php`, `CMS/admin/modules/themes/ThemesModule.php` | umgesetzt | Die Entrys für installierte Plugins und Themes auf die gemeinsame `section-page-shell.php` gezogen, Capability-Gates auf `manage_settings` ausgerichtet und die Modul-Fehler-/Meldungspfade so nachgezogen, dass keine rohen Exception-Texte oder bereits HTML-escaped Erfolgsstrings mehr als impliziter UI-Vertrag herumliegen. | Die Extensions-Verwaltung reduziert weiteren Redirect-/Flash-/POST-Boilerplate, hängt näher am Shared-Shell-Vertrag und begrenzt UI-Leaks in Fehler- sowie Erfolgspfaden besser, weil technische Details im Log statt im Admin-Alert landen und Slugs/Meldungen konsistenter erst im View escaped werden. |

### Delta Batch 318

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/plugin-marketplace.php`, `CMS/admin/theme-marketplace.php`, `CMS/admin/modules/plugins/PluginMarketplaceModule.php`, `CMS/admin/modules/themes/ThemeMarketplaceModule.php` | umgesetzt | Die beiden Marketplace-Entrys auf die gemeinsame `section-page-shell.php` gezogen, Capability-Gates auf `manage_settings` ausgerichtet und die Module bei relativen Remote-Manifest-/Downloadpfaden sowie `requires_cms`-/`requires_php`-Prüfungen gehärtet, damit Remote-Installationen keinen eigenen Redirect-/Flash-Sonderpfad mehr pflegen und inkompatible Pakete nicht mehr als auto-installierbar auftauchen. | Die kritischen Marketplace-Pfade reduzieren verteiltes Entry-Boilerplate, hängen näher am Shared-Shell-Vertrag und begrenzen Remote- und Installationsrisiken stärker, weil nur noch sauber normalisierte Marketplace-Ziele und kompatible Pakete automatisch installierbar bleiben. |

### Delta Batch 317

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/seo/technical.php`, `social.php`, `meta.php`, `sitemap.php`, `schema.php`, `redirects.php`, `not-found.php`, `dashboard.php`, `audit.php`, `CMS/admin/views/security/audit.php`, `CMS/admin/views/plugins/marketplace.php`, `CMS/admin/views/themes/marketplace.php`, `CMS/admin/views/themes/list.php`, `CMS/admin/views/toc/settings.php`, `CMS/admin/views/partials/flash-alert.php` | umgesetzt | Die Alert-Ausgabe in 14 weiteren Admin-Views auf das gemeinsame `flash-alert.php` harmonisiert und das Partial zusätzlich um generische Detail-Listen erweitert, damit Fehler-/Erfolgshinweise inklusive Redirect-/404-Details nicht länger lokal als eigene View-Blöcke nachgebaut werden. | Die betroffenen Views reduzieren verteiltes UI-Boilerplate, bleiben näher am standardisierten Flash-Vertrag der Shared-Shell-Entrys und übernehmen künftige Verbesserungen an Alert-Typ-Mapping, Detail-Ausgabe und Fehlerreport-Payloads automatisch mit. |

### Delta Batch 316

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/member/dashboard.php`, `general.php`, `design.php`, `frontend-modules.php`, `widgets.php`, `plugin-widgets.php`, `profile-fields.php`, `notifications.php`, `onboarding.php`, `CMS/admin/views/system/email-alerts.php`, `response-time.php`, `cron-status.php`, `disk-usage.php`, `health-check.php`, `scheduled-tasks.php`, `info.php`, `diagnose.php` | umgesetzt | Die Seiten-Flash-Ausgabe in 17 weiteren Member- und System-Views auf das gemeinsame `flash-alert.php` harmonisiert, damit Erfolg-/Fehlerhinweise, Dismiss-Verhalten und optionale Fehlerreport-Payloads nicht länger als lokale Alert-Blöcke pro View gepflegt werden. | Die betroffenen Admin-Views reduzieren verteiltes UI-Boilerplate, hängen näher am standardisierten Flash-Vertrag der Shared-Shell-Entrys und übernehmen künftige Verbesserungen am gemeinsamen Alert-Partial automatisch mit. |

### Delta Batch 315

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/seo/subnav.php` | umgesetzt | Den SEO-Subnav auf das gemeinsame `section-subnav.php` standardisiert, damit Link-Aufbau, Active-State und Button-Markup nicht länger als lokaler Navigationsblock im View gepflegt werden und nur die echten SEO-Aktionsbuttons separat verbleiben. | Der View reduziert verteiltes UI-Boilerplate, hängt näher am gemeinsamen Subnav-Vertrag der anderen Admin-Bereiche und übernimmt künftige Partial-Anpassungen automatisch mit. |

### Delta Batch 314

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/seo/analytics.php`, `CMS/admin/views/performance/media.php` | umgesetzt | Die Alert-Ausgabe in zwei weiteren Admin-Views auf das gemeinsame `flash-alert.php` harmonisiert, damit Fehler-/Erfolgsmeldungen, Dismiss-Verhalten und optionale Fehlerreport-Payloads nicht länger lokal als eigene View-Blöcke nachgebaut werden. | Die Views reduzieren verteiltes UI-Boilerplate, bleiben näher am standardisierten Flash-Vertrag der Shared-Shell-Entrys und übernehmen künftige Verbesserungen am gemeinsamen Alert-Partial automatisch mit. |

### Delta Batch 313

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/system-monitor-page.php`, `CMS/admin/modules/system/SystemInfoModule.php` | umgesetzt | Den System-Monitor-Wrapper weiter auf gemeinsame Shell-Muster verdichtet und die Modul-Fehlerpfade auf generische UI-Meldungen mit Audit-Logging umgestellt, sodass Access-Check und Shell-Zusammenbau nicht länger als separater Header-Redirect-Sonderpfad laufen und Diagnose-/Monitoring-Fehler keine rohen Exception-Texte mehr an die Admin-Oberfläche weiterreichen. | Der Wrapper reduziert verteiltes Boilerplate, hängt näher am bestehenden Shared-Shell-Vertrag und hält technische Fehlerdetails künftig im Audit-Log statt in UI-Alerts, wodurch der Diagnose- und Monitoring-Bereich konsistenter und robuster wird. |

### Delta Batch 312

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance-page.php` | umgesetzt | Den Performance-Wrapper weiter auf gemeinsame Shell-Muster verdichtet, sodass die Section-Konfiguration über einen benannten Helper aufgebaut und der Access-Check zentral an die `section-page-shell.php` delegiert wird, statt als eigener Header-Redirect-Sonderpfad im Wrapper zu verbleiben. | Der Wrapper reduziert verteiltes Boilerplate, hält Access- und Shell-Zusammenbau näher am gemeinsamen Entry-Vertrag und macht die Performance-Unterseiten wartbarer, weil kanonische Section-, Modul- und Datenlade-Kontexte kompakter zusammengefasst bleiben. |

### Delta Batch 311

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/member-dashboard.php` | umgesetzt | Den Member-Dashboard-Overview-Entry weiter auf gemeinsame Wrapper reduziert, sodass Legacy-Sektionen über `redirect-alias-shell.php` laufen und der eigentliche Overview-Guard nicht länger zusätzlich als eigener Header-Redirect-Sonderpfad im Entry gepflegt wird. | Der Entry reduziert verteiltes Boilerplate, hält Legacy-Routing näher am gemeinsamen Redirect-Vertrag und delegiert den tatsächlichen Overview-Zugriff klarer an die vorhandene Member-Dashboard-Shared-Schicht. |

### Delta Batch 310

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/seo-page.php` | umgesetzt | Den gemeinsamen SEO-/Analytics-Wrapper auf die `section-page-shell.php` standardisiert, sodass Section-Registry, Capability-Gates, Redirect-Zielberechnung, CSRF-Flow und POST-Dispatch nicht länger als eigener Sonderpfad neben der Shared-Shell gepflegt werden. | Der Wrapper reduziert verteiltes Boilerplate, hält Dashboard-, Audit-, Meta-, Social-, Schema-, Sitemap-, Technical- und Analytics-Pfade näher an derselben Shared-Laufzeitschicht und lässt den dünnen Alias `CMS/admin/analytics.php` automatisch denselben Shell-Vertrag mitnutzen. |

### Delta Batch 309

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/users.php`, `CMS/admin/modules/users/UsersModule.php` | umgesetzt | Den Users-Entry auf die gemeinsame `section-page-shell.php` standardisiert, Listen-/Edit-Kontexte zentral über die Shell aufgelöst und den Save-Fehlerpfad im Users-Modul auf generische UI-Fehler statt roher Exception-Texte zurückgeführt, damit Redirect-, Feedback-, Asset- und Renderpfade nicht länger separat im Entry gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält Listen-/Edit-Flow näher an der Shared-Shell, nutzt den Inline-POST-Fallback jetzt auch für die Benutzerverwaltung und vermeidet im Save-Fehlerpfad unnötige technische Detail-Leaks an die Admin-Oberfläche. |

### Delta Batch 308

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/posts.php`, `CMS/admin/views/posts/list.php`, `CMS/admin/views/posts/edit.php` | umgesetzt | Den Posts-Entry auf die gemeinsame `section-page-shell.php` standardisiert, Listen-/Edit-Kontexte zentral über die Shell aufgelöst und die Views auf das gemeinsame Flash-Partial harmonisiert, damit Redirect-, Feedback-, Asset- und Renderpfade nicht länger separat im Entry gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält Listen-/Edit-Flow näher an der Shared-Shell und nutzt den neuen Inline-POST-Fallback jetzt auch für die Beitragsverwaltung, ohne dafür wieder einen separaten Sonderpfad zu pflegen. |

### Delta Batch 307

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/pages.php`, `CMS/admin/partials/section-page-shell.php`, `CMS/admin/views/pages/list.php`, `CMS/admin/views/pages/edit.php` | umgesetzt | Den Pages-Entry auf die gemeinsame `section-page-shell.php` standardisiert, die Shell um optionales Inline-Rendering nach POST-Fehlern erweitert und die Views auf das gemeinsame Flash-Partial harmonisiert, damit Redirect-, Feedback- und Renderpfade nicht länger separat im Entry gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält Listen-/Edit-Flow näher an der Shared-Shell und schafft zugleich einen wiederverwendbaren Inline-POST-Fallback für weitere komplexe Admin-Entrys wie Posts. |

### Delta Batch 306

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/packages.php`, `CMS/admin/views/subscriptions/packages.php` | umgesetzt | Den Packages-Entry auf die gemeinsame `section-page-shell.php` standardisiert und die View auf das gemeinsame Flash-Partial harmonisiert, damit Redirect-, Feedback- und Renderpfade nicht länger separat im Entry gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält Paket-CRUD und Paket-Einstellungen näher an der Shared-Shell und zeigt Alerts im View jetzt konsistent über dasselbe Partial wie verwandte Admin-Seiten an. |

### Delta Batch 305

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/media.php` | umgesetzt | Den Media-Entry auf die gemeinsame `section-page-shell.php` standardisiert, damit Tab-Redirects, Zusatz-Token, Datenladung und Rendering nicht länger separat im Entry gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält Bibliothek-, Kategorien- und Settings-Flow näher an derselben Shell-Schicht und übernimmt künftige Wrapper-Verbesserungen automatisch mit. |

### Delta Batch 304

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/orders.php` | umgesetzt | Den Orders-Entry auf die gemeinsame `section-page-shell.php` standardisiert, damit CSRF-, Redirect-, Datenlade- und Renderpfade nicht länger separat neben dem Shared-Entry-Vertrag gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält Bestellstatus- und Abozuweisungsaktionen näher an derselben PRG-/Shell-Schicht und übernimmt künftige Wrapper-Verbesserungen automatisch mit. |

### Delta Batch 303

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/menu-editor.php`, `CMS/admin/views/menus/editor.php` | umgesetzt | Den Menü-Editor-Entry auf die gemeinsame `section-page-shell.php` standardisiert und die View auf das gemeinsame Flash-Partial harmonisiert, damit Redirect-, Feedback- und Renderpfade nicht länger separat im Entry gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält Menü-CRUD und Item-Speicherung näher an der Shared-Shell und zeigt Alerts im View jetzt konsistent über dasselbe Partial wie verwandte Admin-Seiten an. |

### Delta Batch 302

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/hub-sites.php`, `CMS/admin/partials/section-page-shell.php`, `CMS/admin/views/hub/edit.php`, `CMS/admin/views/hub/template-edit.php`, `CMS/admin/views/hub/templates.php` | umgesetzt | Den Hub-Sites-Entry auf die erweiterte `section-page-shell.php` standardisiert, damit Multi-View-Renderpfade, Redirects, Flash-Ausgabe und Asset-Kontexte nicht länger separat neben dem Shared-Entry-Vertrag gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält Listen-, Edit- und Template-Views näher an einer gemeinsamen Laufzeit-Schicht und übernimmt künftige Shell-Verbesserungen automatisch mit. |

### Delta Batch 301

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/mail-settings.php`, `CMS/admin/partials/section-page-shell.php` | umgesetzt | Den Mail-Settings-Entry auf die gemeinsame `section-page-shell.php` standardisiert, damit Tab-Redirects, Guard, Flash-Ausgabe und View-Kontext nicht länger separat im Entry gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält Transport-/Azure-/Graph-/Log-/Queue-Aktionen näher an der Shared-Shell und übernimmt künftige Wrapper-Verbesserungen automatisch mit. |

### Delta Batch 300

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/legal-sites.php`, `CMS/admin/views/legal/sites.php` | umgesetzt | Den Legal-Sites-Entry auf die gemeinsame `section-page-shell.php` standardisiert und die View auf das gemeinsame Flash-Partial harmonisiert, damit Profilspeicherung, Generator-Dispatch und Feedback nicht länger separat im Entry gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält Legal-Profile und Seitengenerator näher an der Shared-Shell und übernimmt künftige Wrapper-Verbesserungen automatisch mit. |

### Delta Batch 299

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/landing-page.php`, `CMS/admin/views/landing/page.php` | umgesetzt | Den Landing-Page-Entry auf die gemeinsame `section-page-shell.php` standardisiert und die View auf das gemeinsame Flash-Partial harmonisiert, damit Tab-Redirects, Feedback und Rendering nicht länger separat im Entry gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält Header-/Content-/Footer-/Design-/Plugin-Aktionen näher an der Shared-Shell und übernimmt künftige Wrapper-Verbesserungen automatisch mit. |

### Delta Batch 298

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/groups.php` | umgesetzt | Den Gruppen-Entry auf die gemeinsame `section-page-shell.php` standardisiert, damit Admin-Guard, Redirects, Datenladung und Rendering nicht länger separat neben dem Shared-Entry-Vertrag gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält Save-/Delete-Dispatch näher an der Shared-Shell und übernimmt künftige Shell-Verbesserungen automatisch mit. |

### Delta Batch 297

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/font-manager.php`, `CMS/admin/views/themes/fonts.php` | umgesetzt | Den Font-Manager-Entry auf die gemeinsame `section-page-shell.php` standardisiert und die View auf das gemeinsame Flash-Partial harmonisiert, damit Guard-, Redirect-, Feedback- und Renderpfade nicht länger separat im Entry gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, zeigt Shell-basiertes Feedback konsistent im Theme-View an und übernimmt künftige Shell-Verbesserungen automatisch mit. |

### Delta Batch 296

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/firewall.php` | umgesetzt | Den Firewall-Entry auf die gemeinsame `section-page-shell.php` standardisiert, damit Rechte-, Redirect-, Datenlade- und Renderpfade nicht länger separat neben dem Shared-Entry-Vertrag gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält Action-Allowlist und Capability-Gates näher an der Shared-Shell und übernimmt künftige Shell-Verbesserungen automatisch mit. |

### Delta Batch 295

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/error-report.php`, `CMS/admin/partials/post-action-shell.php` | umgesetzt | Den Error-Report-Endpunkt auf einen gemeinsamen POST-Action-Wrapper standardisiert, damit CSRF-, Redirect- und Flash-Pfade nicht länger separat pro POST-only-Endpunkt gepflegt werden. | Der Endpunkt reduziert verteiltes Boilerplate, hält Payload-Normalisierung näher am Service-Aufruf und schafft einen kleinen Shared-Vertrag für weitere Admin-Aktionspfade ohne eigene View. |

### Delta Batch 294

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/design-settings.php` | umgesetzt | Den Design-Settings-Alias auf einen gemeinsamen Redirect-Wrapper standardisiert, damit Zugriffscheck und Zielroute nicht länger mit eigenem Guard-/Redirect-Boilerplate pro Datei gepflegt werden. | Der Alias reduziert verteiltes Boilerplate, hängt näher an einem kleinen Shared-Redirect-Vertrag und übernimmt künftige Alias-Anpassungen automatisch mit. |

### Delta Batch 293

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/deletion-requests.php` | umgesetzt | Den Deletion-Requests-Alias auf einen gemeinsamen Redirect-Wrapper standardisiert, damit Guard- und Redirect-Pfade nicht länger separat neben verwandten Legal-Alias-Seiten gepflegt werden. | Der Alias reduziert verteiltes Boilerplate, bleibt näher an demselben Redirect-Vertrag wie verwandte DSGVO-Alias-Seiten und übernimmt künftige Wrapper-Verbesserungen automatisch mit. |

### Delta Batch 292

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/data-requests.php` | umgesetzt | Den Data-Requests-Entry auf die gemeinsame `section-page-shell.php` standardisiert, damit Schreibpfade, Redirects, Datenladung und Rendering nicht länger separat neben dem Shared-Entry-Vertrag gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält Auskunfts- und Löschanträge näher an einem gemeinsamen Request-Vertrag und übernimmt künftige Shell-Verbesserungen automatisch mit. |

### Delta Batch 291

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/cookie-manager.php`, `CMS/admin/views/legal/cookies.php` | umgesetzt | Den Cookie-Manager-Entry auf die gemeinsame `section-page-shell.php` standardisiert und die zentrale Flash-Ausgabe im View sichtbar gemacht, damit Schreibpfade, Redirects und Feedback nicht länger separat im Entry nachgebaut werden. | Der Entry reduziert verteiltes Boilerplate, zeigt Shell-basierte Alerts jetzt direkt im Legal-View und übernimmt künftige Shell-Verbesserungen automatisch mit. |

### Delta Batch 290

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/documentation.php` | umgesetzt | Den Documentation-Entry auf die gemeinsame `section-page-shell.php` standardisiert, damit Redirect-, Flash-, Datenlade- und Renderpfade nicht länger separat neben dem Shared-Entry-Vertrag gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält die Dokumentauswahl auch nach POST-Redirects stabil und übernimmt künftige Shell-Verbesserungen automatisch mit. |

### Delta Batch 289

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/comments.php` | umgesetzt | Den Comments-Entry auf die gemeinsame `section-page-shell.php` standardisiert, damit CSRF-/Rechte-Flow, Redirects, Datenladung und Rendering nicht länger separat neben dem Shared-Entry-Vertrag gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält den Statusfilter über den PRG-Flow stabil und übernimmt künftige Shell-Verbesserungen automatisch mit. |

### Delta Batch 288

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/backups.php` | umgesetzt | Den Backup-Entry auf die gemeinsame `section-page-shell.php` standardisiert, damit Redirect-, Flash-, Datenlade- und Renderpfade nicht länger separat neben dem Shared-Entry-Vertrag gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hängt näher an der gemeinsamen Admin-Shell und übernimmt künftige Shell-Verbesserungen automatisch mit. |

### Delta Batch 287

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/antispam.php` | umgesetzt | Den AntiSpam-Entry auf die gemeinsame `section-page-shell.php` standardisiert, damit Redirect-, Flash-, Datenlade- und Renderpfade nicht länger separat neben dem Shared-Entry-Vertrag gepflegt werden. | Der Entry reduziert verteiltes Boilerplate, hält Action-Allowlist und Capability-Gates näher am Shell-Vertrag und übernimmt künftige Shell-Verbesserungen automatisch mit. |

### Delta Batch 286

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/index.php` | umgesetzt | Den Dashboard-Entry auf die gemeinsame `section-page-shell.php` standardisiert, damit Guard, Modul-Initialisierung, Datenladung und Rendering nicht länger als separater Sonderpfad neben dem restlichen Shared-Entry-Muster laufen. | Der Dashboard-Entry reduziert implizites Boilerplate, hängt näher an der vorhandenen Section-Infrastruktur und übernimmt künftige Shell-Verbesserungen automatisch. |

### Delta Batch 285

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/partials/section-page-shell.php` | umgesetzt | Die gemeinsame Section-Shell um Access-Checker, Flash-Payload-Helfer und Asset-Normalisierung ergänzt, damit Shared-Entrys keine losen Redirect-, Session- oder Asset-Annahmen unterschiedlich nachbauen. | Die Shell reduziert verteiltes Boilerplate zwischen standardisierten Admin-Entrys, hält Access-, Flash- und Asset-Logik näher an einem kleinen gemeinsamen Vertrag und blockt unklare Asset-Listen sichtbar früher. |

### Delta Batch 284

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/redirect-manager.php`, `CMS/admin/not-found-monitor.php` | umgesetzt | Die SEO-Entrys auf section-spezifische Modulzugriffe gezogen, damit Redirect-Manager und 404-Monitor nicht mehr denselben Voll-Datensatz aus Redirect-Regeln, 404-Logs, Targets und Sites laden. | Die Renderpfade reduzieren unnötigen Admin-Overfetch zwischen Redirect- und 404-Scope, bleiben näher an einem kleinen Seitenvertrag und machen den tatsächlich verwendeten Datenausschnitt expliziter. |

### Delta Batch 283

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/seo/RedirectManagerModule.php` | umgesetzt | Das Modul um getrennte Loader für Redirect-Manager und 404-Monitor ergänzt, damit beide SEO-Seiten nicht länger pauschal denselben Voll-Datensatz anfordern. | Der Modulvertrag reduziert implizite Datenannahmen, hält die Datensicht näher am jeweiligen Seiten-Scope und macht Redirect- gegenüber 404-Daten explizit lesbar. |

### Delta Batch 282

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/core/Services/RedirectService.php` | umgesetzt | Den Redirect-Service auf getrennte Admin-Datensichten und gemeinsame Redirect-/404-Helfer aufgeteilt, damit Redirect-Manager und 404-Monitor nur noch ihren eigenen Scope aus Regeln, Logs, Targets und Sites laden. | Der Service reduziert unnötige Voll-Dumps zwischen SEO-Unterseiten, hält Stats-, Target- und Log-Aufbereitung näher an einem kleinen Shared-Vertrag und verbessert die Render-Kosten der Admin-Seiten. |

### Delta Batch 281

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/dashboard/DashboardModule.php` | umgesetzt | Das Dashboard-Modul so nachgezogen, dass Attention-Items die bereits geladenen Dashboard-Stats wiederverwenden statt dieselben Kennzahlen erneut aus dem Service anzufordern. | Der Dashboard-Renderpfad reduziert doppelte Aggregationsarbeit, hält KPIs, Highlights und Attention-Items näher an derselben Stats-Basis und spart unnötige Service-Runden. |

### Delta Batch 280

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/core/Services/DashboardService.php` | umgesetzt | `getAttentionItems()` auf optional übergebene Stats gezogen, damit vorhandene Dashboard-Kennzahlen ohne erneute Komplettaggregation weiterverwendet werden können. | Die Attention-Logik reduziert implizite Full-Reloads im selben Request, bleibt näher an einem kleinen Datenvertrag und verbessert den Dashboard-Renderpfad. |

### Delta Batch 279

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/core/Services/DashboardService.php` | umgesetzt | Die Komplettaggregation des Dashboard-Services request-lokal gecacht, damit wiederholte Stats-Zugriffe im selben Lauf keine identische zweite Datenrunde auslösen. | Der Service reduziert doppelte User-, Seiten-, Medien-, Session-, Security-, Performance- und Order-Aggregation und hält den Request-State sichtbarer zentral. |

### Delta Batch 278

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance-settings.php` | umgesetzt | Den Performance-Settings-Alias auf eine reine Section-Konfiguration reduziert, damit der Entry keine eigene Route-/View-/Titel-Duplikation mehr außerhalb des Shared-Wrappers pflegt. | Der Alias reduziert implizite Konfigurationsannahmen im Entry, hängt näher an der kanonischen Performance-Registry und bleibt als schlanker Section-Entry wartbarer. |

### Delta Batch 277

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance-sessions.php` | umgesetzt | Den Performance-Sessions-Alias auf eine reine Section-Konfiguration reduziert, damit der Entry keine eigene Route-/View-/Titel-Duplikation mehr außerhalb des Shared-Wrappers pflegt. | Der Alias reduziert implizite Konfigurationsannahmen im Entry, hängt näher an der kanonischen Performance-Registry und bleibt als schlanker Section-Entry wartbarer. |

### Delta Batch 276

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance-media.php` | umgesetzt | Den Performance-Media-Alias auf eine reine Section-Konfiguration reduziert, damit der Entry keine eigene Route-/View-/Titel-Duplikation mehr außerhalb des Shared-Wrappers pflegt. | Der Alias reduziert implizite Konfigurationsannahmen im Entry, hängt näher an der kanonischen Performance-Registry und bleibt als schlanker Section-Entry wartbarer. |

### Delta Batch 275

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance-database.php` | umgesetzt | Den Performance-Datenbank-Alias auf eine reine Section-Konfiguration reduziert, damit der Entry keine eigene Route-/View-/Titel-Duplikation mehr außerhalb des Shared-Wrappers pflegt. | Der Alias reduziert implizite Konfigurationsannahmen im Entry, hängt näher an der kanonischen Performance-Registry und bleibt als schlanker Section-Entry wartbarer. |

### Delta Batch 274

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance-cache.php` | umgesetzt | Den Performance-Cache-Alias auf eine reine Section-Konfiguration reduziert, damit der Entry keine eigene Route-/View-/Titel-Duplikation mehr außerhalb des Shared-Wrappers pflegt. | Der Alias reduziert implizite Konfigurationsannahmen im Entry, hängt näher an der kanonischen Performance-Registry und bleibt als schlanker Section-Entry wartbarer. |

### Delta Batch 273

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/monitor-cron-status.php` | umgesetzt | Den Monitoring-Cron-Alias auf eine reine Section-Konfiguration reduziert, damit der Entry keine eigene Route-/View-/Titel-Duplikation mehr außerhalb des Shared-Wrappers pflegt. | Der Alias reduziert implizite Konfigurationsannahmen im Entry, hängt näher an der kanonischen Monitoring-Registry und bleibt als schlanker Section-Entry wartbarer. |

### Delta Batch 272

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/monitor-email-alerts.php` | umgesetzt | Den Monitoring-E-Mail-Alias auf eine reine Section-Konfiguration reduziert, damit der Entry keine eigene Route-/View-/Titel-Duplikation mehr außerhalb des Shared-Wrappers pflegt. | Der Alias reduziert implizite Konfigurationsannahmen im Entry, hängt näher an der kanonischen Monitoring-Registry und bleibt als schlanker Section-Entry wartbarer. |

### Delta Batch 271

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/monitor-health-check.php` | umgesetzt | Den Monitoring-Health-Alias auf eine reine Section-Konfiguration reduziert, damit der Entry keine eigene Route-/View-/Titel-Duplikation mehr außerhalb des Shared-Wrappers pflegt. | Der Alias reduziert implizite Konfigurationsannahmen im Entry, hängt näher an der kanonischen Monitoring-Registry und bleibt als schlanker Section-Entry wartbarer. |

### Delta Batch 270

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/monitor-scheduled-tasks.php` | umgesetzt | Den Monitoring-Scheduled-Tasks-Alias auf eine reine Section-Konfiguration reduziert, damit der Entry keine eigene Route-/View-/Titel-Duplikation mehr außerhalb des Shared-Wrappers pflegt. | Der Alias reduziert implizite Konfigurationsannahmen im Entry, hängt näher an der kanonischen Monitoring-Registry und bleibt als schlanker Section-Entry wartbarer. |

### Delta Batch 269

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/monitor-disk-usage.php` | umgesetzt | Den Monitoring-Disk-Alias auf eine reine Section-Konfiguration reduziert, damit der Entry keine eigene Route-/View-/Titel-Duplikation mehr außerhalb des Shared-Wrappers pflegt. | Der Alias reduziert implizite Konfigurationsannahmen im Entry, hängt näher an der kanonischen Monitoring-Registry und bleibt als schlanker Section-Entry wartbarer. |

### Delta Batch 268

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/monitor-response-time.php` | umgesetzt | Den Monitoring-Response-Time-Alias auf eine reine Section-Konfiguration reduziert, damit der Entry keine eigene Route-/View-/Titel-Duplikation mehr außerhalb des Shared-Wrappers pflegt. | Der Alias reduziert implizite Konfigurationsannahmen im Entry, hängt näher an der kanonischen Monitoring-Registry und bleibt als schlanker Section-Entry wartbarer. |

### Delta Batch 267

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance.php` | umgesetzt | Den Performance-Overview-Alias auf eine reine Section-Konfiguration reduziert, damit der Entry keine eigene Route-/View-/Titel-Duplikation mehr außerhalb des Shared-Wrappers pflegt. | Der Alias reduziert implizite Konfigurationsannahmen im Entry, hängt näher an der kanonischen Performance-Registry und bleibt als schlanker Section-Entry wartbarer. |

### Delta Batch 266

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/diagnose.php` | umgesetzt | Den Diagnose-Alias auf eine reine Section-Konfiguration reduziert, damit der Entry keine eigene Route-/View-/Titel-Duplikation mehr außerhalb des Shared-Wrappers pflegt. | Der Alias reduziert implizite Konfigurationsannahmen im Entry, hängt näher an der kanonischen Monitoring-Registry und bleibt als schlanker Section-Entry wartbarer. |

### Delta Batch 265

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/info.php` | umgesetzt | Den Info-Alias auf eine reine Section-Konfiguration reduziert, damit der Entry keine eigene Route-/View-/Titel-Duplikation mehr außerhalb des Shared-Wrappers pflegt. | Der Alias reduziert implizite Konfigurationsannahmen im Entry, hängt näher an der kanonischen Monitoring-Registry und bleibt als schlanker Section-Entry wartbarer. |

### Delta Batch 264

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/analytics.php` | umgesetzt | Den Analytics-Alias auf eine reine Section-Konfiguration reduziert, damit der Entry keine eigene Route-/View-/Titel-Duplikation mehr außerhalb des Shared-Wrappers pflegt. | Der Alias reduziert implizite Konfigurationsannahmen im Entry, hängt näher an der kanonischen SEO-Registry und bleibt als schlanker Section-Entry wartbarer. |

### Delta Batch 263

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance-page.php` | umgesetzt | Die gemeinsame Performance-Eintrittsschicht auf eine kanonische Section-Registry für Route, View, Titel und Active-Page gezogen, damit Alias-Dateien keine divergierenden Metadaten mehr lose in denselben Wrapper hineinreichen. | Der Performance-Sammel-Wrapper reduziert implizite Konfigurationsannahmen zwischen Overview-, Cache-, Datenbank-, Medien-, Session- und Settings-Pfaden und hält Route-/View-Metadaten näher an einem kleinen gemeinsamen Section-Vertrag. |

### Delta Batch 262

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/system-monitor-page.php` | umgesetzt | Die gemeinsame Monitoring-Eintrittsschicht auf eine kanonische Section-Registry für Route, View, Titel und Active-Page gezogen, damit Alias-Dateien keine divergierenden Metadaten mehr lose in denselben Wrapper hineinreichen. | Der Monitoring-Sammel-Wrapper reduziert implizite Konfigurationsannahmen zwischen Info-, Diagnose- und Monitoring-Pfaden und hält Route-/View-Metadaten näher an einem kleinen gemeinsamen Section-Vertrag. |

### Delta Batch 261

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/partials/section-page-shell.php` | umgesetzt | Die generische Admin-Section-Shell auf zentrale Route-/View-Normalisierung und sichere Redirect-Helfer gezogen, damit Shared-Wrapper keine losen `route_path`- oder `view_file`-Werte blind übernehmen. | Die gemeinsame Shell reduziert implizite Konfigurationsannahmen zwischen Member-, Performance- und System-Unterseiten, hält Route-, View- und Redirect-Pfade näher an einem kleinen Shared-Vertrag und blockt ungültige Shell-Ziele sichtbar früher. |

### Delta Batch 260

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/seo/SeoSuiteModule.php` | umgesetzt | Das SEO-Suite-Modul auf section-spezifische Datensichten gezogen, damit Unterseiten nicht mehr pauschal den kompletten SEO-Datensatz aus Audit-, Tracking-, Schema-, Sitemap- und Redirect-Pfaden laden. | Das Modul reduziert unnötige Sammelabfragen und Datensichten auf SEO-Unterseiten, hält Dashboard-, Audit-, Analytics-, Meta-, Social-, Schema-, Sitemap- und Technical-Sichten näher an einem kleinen Datenvertrag und vermeidet implizite Voll-Dumps über denselben Sammelpfad. |

### Delta Batch 259

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/seo-page.php` | umgesetzt | Die gemeinsame SEO-Eintrittsschicht auf section-spezifische Datenladung gezogen, damit Unterseiten nicht mehr pauschal denselben Voll-Datensatz über `getData()` laden. | Der SEO-Sammel-Wrapper reduziert implizite Datenannahmen zwischen Dashboard-, Audit-, Analytics-, Meta-, Social-, Schema-, Sitemap- und Technical-Pfaden, hält Renderpfade näher an einem kleinen Section-Vertrag und blockt unnötige Voll-Ladepfade sichtbar früher. |

### Delta Batch 258

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/member/MemberDashboardModule.php` | umgesetzt | Das Member-Dashboard-Modul auf section-spezifische Datensichten gezogen, damit Unterseiten nicht mehr pauschal den kompletten Member-Dashboard-Datensatz aus Stats-, Widget-, Profil-, Notification- und Plugin-Widget-Pfaden laden. | Das Modul reduziert unnötige Sammelabfragen und Datensichten auf Member-Unterseiten, hält Overview-, Widget-, Profil-, Notification- und Plugin-Widget-Sichten näher an einem kleinen Datenvertrag und vermeidet implizite Voll-Dumps über denselben Sammelpfad. |

### Delta Batch 257

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/member-dashboard-page.php` | umgesetzt | Die gemeinsame Member-Dashboard-Eintrittsschicht auf section-spezifische Datenladung gezogen, damit Unterseiten nicht mehr pauschal denselben Voll-Datensatz über `getData()` laden. | Der Member-Dashboard-Sammel-Wrapper reduziert implizite Datenannahmen zwischen Overview-, General-, Widget-, Profilfeld-, Design-, Notification-, Onboarding- und Plugin-Widget-Pfaden, hält Renderpfade näher an einem kleinen Section-Vertrag und blockt unnötige Voll-Ladepfade sichtbar früher. |

### Delta Batch 256

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/seo/PerformanceModule.php` | umgesetzt | Das Performance-Modul auf section-spezifische Datensichten gezogen, damit Unterseiten nicht mehr pauschal den kompletten Performance-Datensatz aus Cache-, Medien-, Datenbank-, Session- und PHP-Infos laden. | Das Modul reduziert unnötige Telemetrie- und Scan-Arbeit auf Performance-Unterseiten, hält Cache-, DB-, Medien-, Session- und Settings-Sichten näher an einem kleinen Datenvertrag und vermeidet implizite Voll-Dumps über denselben Sammelpfad. |

### Delta Batch 255

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance-page.php` | umgesetzt | Den Shared-Performance-Wrapper zusätzlich an ein explizites Read-Gate gebunden und die Datenladung pro Unterseite auf section-spezifische Scopes reduziert, statt jede Unterseite pauschal über denselben Voll-Datenpfad zu bedienen. | Der Performance-Sammel-Wrapper reduziert implizite Rechte- und Datenannahmen zwischen Cache-, Datenbank-, Medien-, Session- und Settings-Pfaden, hält Read-/Write- und Data-Scope-Gates näher an einem kleinen gemeinsamen Vertrag und blockt unnötige Voll-Ladepfade sichtbar früher. |

### Delta Batch 254

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/SystemInfoModule.php` | umgesetzt | Das SystemInfo-Modul auf section-spezifische Datensichten für Info-, Diagnose- und Monitoring-Unterseiten umgestellt, damit sensible Runtime-, Query- und Monitoring-Daten nicht mehr pauschal auf jeder Unterseite mitgeladen werden. | Das Modul reduziert implizite Voll-Dumps zwischen Info-, Diagnose-, Cron-, Disk-, Response-Time-, Health-, Scheduled-Task- und Alert-Pfaden, hält Daten-Sichten näher an einem kleinen Section-Vertrag und vermeidet unnötige Komplett-Scans über denselben Sammelpfad. |

### Delta Batch 253

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/system-monitor-page.php` | umgesetzt | Die gemeinsame System-Monitor-Eintrittsschicht auf kanonische Sections, kleine Action-Allowlists und ein explizites `manage_settings`-Read-Gate gezogen, damit Diagnose- und Alert-Pfade keine losen `section`-/`action`-Werte oder pauschalen Admin-Zugriffe mehr übernehmen. | Der System-Sammel-Wrapper reduziert implizite Request- und Rechteannahmen zwischen Info-, Diagnose- und Monitoring-Unterseiten, hält Section-, Action- und Capability-Gates näher an einem kleinen gemeinsamen Admin-Vertrag und blockt section-fremde oder capability-fremde Requests sichtbar früher. |

### Delta Batch 252

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/seo-page.php` | umgesetzt | Die gemeinsame SEO-Eintrittsschicht an section-spezifische Read-/Write-Capabilities gebunden, sodass Analytics-Lesezugriffe nur noch über `manage_settings` oder `view_analytics` laufen und Mutationen pro Section kontrolliert an `manage_settings` hängen. | Der SEO-Sammel-Wrapper reduziert implizite Rechteannahmen zwischen Analytics- und SEO-Unterseiten, hält Read-/Write-Gates näher an einem kleinen gemeinsamen Admin-Vertrag und blockt capability-fremde Render- oder Mutationspfade sichtbar früher, bevor Modulmethoden unnötig angesprungen werden. |

### Delta Batch 251

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/member-dashboard.php` | umgesetzt | Legacy-Section-Weiterleitungen serverseitig auf eine kanonische Routenmap normalisiert und die Dashboard-Overview an passende Read-Capabilities gebunden, statt nur pauschal auf `isAdmin()` zu vertrauen. | Der Member-Dashboard-Entry reduziert implizite Query- und Rechteannahmen im Alias-Pfad, hält Legacy-Routing und Overview-Zugriff näher am gemeinsamen Member-Dashboard-Vertrag und blockt section-fremde oder capability-fremde Zugriffe sichtbar früher. |

### Delta Batch 250

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/mail-settings.php` | umgesetzt | Den Mail-Settings-Entry auf eine explizite Read-/Write-Capability-Matrix gezogen, damit Logs-, Queue-, Azure- und Graph-Pfade nicht mehr nur über `isAdmin()` lesbar oder mutierbar sind. | Der Mail-Entry reduziert implizite Rechteannahmen zwischen Lese- und Mutationspfaden, hält Capability-Gates näher am vorhandenen Tab-/Action-Vertrag und blockt capability-fremde POST-Requests sichtbar früher, bevor CSRF- oder Modulpfade unnötig angesprungen werden. |

### Delta Batch 249

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/legal-sites.php` | umgesetzt | Den Legal-Sites-Entry explizit an `manage_settings` gebunden, Actions serverseitig zentral normalisiert und `template_type` früher im Wrapper validiert, damit Rechtstext-Mutationen keine capability-fremden oder losen Requests übernehmen. | Der Legal-Sites-Entry reduziert implizite Request- und Rechteannahmen im Generator- und Page-Flow, hält Action-, Template- und Capability-Gates näher am gemeinsamen Admin-Wrapper-Muster und blockt unzulässige Mutationen sichtbar früher. |

### Delta Batch 248

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/font-manager.php` | umgesetzt | Den Font-Manager-Entry explizit an `manage_settings` gebunden, sodass Ansichts- und Mutationspfade keine pauschalen Admin-Zugriffe mehr nur über `isAdmin()` übernehmen. | Der Font-Manager-Entry reduziert implizite Rechteannahmen im Wrapper, hält Read-/Write-Gates näher am vorhandenen Action-Vertrag und blockt capability-fremde POST-Requests sichtbar früher, bevor CSRF- oder Handlerpfade unnötig angesprungen werden. |

### Delta Batch 247

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/backups.php` | umgesetzt | Den Backup-Entry auf den vorhandenen Read-/Write-Capability-Split des Moduls gezogen, sodass Listenpfad und Mutationen nicht mehr nur auf `isAdmin()` beruhen. | Der Backup-Entry reduziert implizite Rechteannahmen im Wrapper, hält Read-/Write-Gates näher am `BackupsModule`-Vertrag und blockt capability-fremde POST-Requests sichtbar früher, bevor CSRF- oder Handlerpfade unnötig angesprungen werden. |

### Delta Batch 246

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/member-dashboard-page.php` | umgesetzt | Die gemeinsame Member-Dashboard-Eintrittsschicht auf section-gebundene Capability-Gates und eine kleine Save-Allowlist gezogen, damit General-, Design-, Widget-, Profilfeld-, Notification-, Onboarding- und Plugin-Widget-Pfade keine bereichsfremden Zugriffe oder losen `action`-Werte direkt im Shared-Wrapper übernehmen. | Der Member-Dashboard-Sammel-Wrapper reduziert implizite Request- und Rechteannahmen zwischen mehreren Unterseiten, hält Section-, Action- und Capability-Gates näher an einem kleinen gemeinsamen Admin-Vertrag und blockt section-fremde oder unbekannte Requests sichtbar früher, bevor Modulmethoden unnötig angesprungen werden. |

### Delta Batch 245

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/media.php` | umgesetzt | Medien-Mutationen wieder explizit an `manage_media` gebunden und `action` serverseitig vor dem Dispatch normalisiert, damit Upload-, Folder-, Delete-, Kategorie- und Settings-Pfade keine losen Aktionen oder capability-fremden Admin-Zugriffe direkt im Einstieg übernehmen. | Der Media-Entry reduziert implizite Request- und Rechteannahmen im Mutationspfad, hält Action- und Capability-Gates näher am übrigen Admin-Wrapper-Muster und blockt unbekannte Aktionen oder capability-fremde Requests sichtbar früher, bevor Modul- oder Servicepfade unnötig angesprungen werden. |

### Delta Batch 244

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/landing-page.php` | umgesetzt | Landing-Page-Mutationen wieder explizit über kleine Tab-/Action-Allowlists begrenzt, Feature-IDs serverseitig normalisiert und den Entry an `manage_settings` gebunden, damit Header-, Content-, Footer-, Design-, Feature- und Plugin-Pfade keine losen Request-Werte oder pauschalen Admin-Zugriffe direkt im Einstieg übernehmen. | Der Landing-Page-Entry reduziert implizite Request- und Rechteannahmen im Mutationspfad, hält Tab-, Action-, ID- und Capability-Gates näher am übrigen Admin-Wrapper-Muster und blockt unbekannte Aktionen, rohe Feature-IDs oder capability-fremde Requests sichtbar früher, bevor Modulmethoden unnötig angesprungen werden. |

### Delta Batch 243

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/menu-editor.php` | umgesetzt | Menü-Mutationen wieder explizit über eine kleine Action-Allowlist begrenzt, `menu_id` serverseitig vor Delete-/Save-Dispatches normalisiert und den Entry an `manage_settings` gebunden, damit Menüpfade keine losen Aktionen, rohen IDs oder pauschalen Admin-Zugriffe direkt im Einstieg übernehmen. | Der Menü-Editor reduziert implizite Request- und Rechteannahmen im Mutationspfad, hält Action-, ID- und Capability-Gates näher am übrigen Admin-Wrapper-Muster und blockt unbekannte Aktionen, rohe Menü-IDs oder capability-fremde Requests sichtbar früher, bevor Modulmethoden unnötig angesprungen werden. |

### Delta Batch 242

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance-page.php` | umgesetzt | Die gemeinsame Performance-Eintrittsschicht auf section-gebundene Action-Allowlists und ein explizites `manage_settings`-Gate gezogen, damit Performance-Unterseiten keine losen POST-Aktionen oder pauschalen Admin-Zugriffe direkt im Sammel-Wrapper übernehmen. | Der Performance-Sammel-Wrapper reduziert implizite Request- und Rechteannahmen zwischen Cache-, Datenbank-, Medien- und Session-Pfaden, hält Section-, Action- und Capability-Gates näher an einem kleinen gemeinsamen Admin-Vertrag und blockt section-fremde oder unbekannte Aktionen sichtbar früher, bevor Modulmethoden unnötig angesprungen werden. |

### Delta Batch 241

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/not-found-monitor.php` | umgesetzt | 404-Mutationen wieder explizit über eine kleine Action-Allowlist begrenzt und den Entry zusätzlich an `manage_settings` gebunden, damit Redirect-Save- und Log-Clear-Pfade keine losen Request-Werte oder pauschalen Admin-Zugriffe direkt im Einstieg übernehmen. | Der 404-Monitor reduziert implizite Request- und Rechteannahmen im Mutationspfad, hält Action- und Capability-Gates näher am übrigen SEO-/Admin-Wrapper-Muster und blockt unbekannte Aktionen oder capability-fremde Requests sichtbar früher, bevor Modulmethoden unnötig angesprungen werden. |

### Delta Batch 240

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/users.php` | umgesetzt | Benutzer-Mutationen wieder explizit über eine kleine Action-Allowlist begrenzt, positive IDs und Bulk-IDs serverseitig vor dem Dispatch normalisiert, View-Ziele auf kanonische Modi zurückgeführt und den Entry an `manage_users` gebunden, damit User-Pfade keine losen Request-Werte oder bloß pauschale Admin-Zugriffe direkt im Einstieg übernehmen. | Der Users-Entry reduziert implizite Request- und Rechteannahmen im Mutationspfad, hält Action-, ID-, Bulk- und View-Gates näher am übrigen Admin-Wrapper-Muster und blockt unbekannte Aktionen, rohe Benutzer-IDs, leere Bulk-Auswahlen oder capability-fremde Zugriffe sichtbar früher, bevor Modulmethoden unnötig angesprungen werden. |

### Delta Batch 239

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/pages.php` | umgesetzt | Seiten-Mutationen wieder explizit über eine kleine Action-Allowlist begrenzt, positive IDs und Bulk-IDs serverseitig vor dem Dispatch normalisiert, View-Ziele auf kanonische Modi zurückgeführt und den Entry an `manage_pages` gebunden, damit Seitenpfade keine losen Request-Werte oder capability-fremden Zugriffe direkt im Einstieg übernehmen. | Der Pages-Entry reduziert implizite Request- und Rechteannahmen im Mutations- und Renderpfad, hält Action-, ID-, Bulk-, View- und Capability-Gates näher am übrigen Admin-Wrapper-Muster und blockt unbekannte Aktionen, rohe Seiten-IDs, leere Bulk-Auswahlen oder falsche View-Modi sichtbar früher, bevor Modulmethoden unnötig angesprungen werden. |

### Delta Batch 238

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/posts.php` | umgesetzt | Beitrags-Mutationen wieder explizit über kleine Action- und Bulk-Allowlists begrenzt, positive Beitrags- und Kategorie-IDs serverseitig vor dem Dispatch normalisiert und View-Ziele auf kanonische Modi zurückgeführt, damit Content-Pfade keine losen Request-Werte direkt aus `POST`/`GET` in Save-, Delete-, Bulk- oder Kategoriepfade übernehmen. | Der Posts-Entry reduziert implizite Request-Annahmen im Mutations- und Renderpfad, hält Action-, ID-, Bulk-, Kategorie- und View-Gates näher am übrigen Admin-Wrapper-Muster und blockt unbekannte Aktionen, rohe IDs oder leere Bulk-Auswahlen sichtbar früher, bevor Modulmethoden unnötig angesprungen werden. |

### Delta Batch 237

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/privacy-requests.php` | umgesetzt | Den DSGVO-Alias wieder auf seinen tatsächlichen Redirect-Zweck reduziert und unerreichbaren Legacy-POST-, Modul- und View-Code hinter dem sofortigen Redirect entfernt, damit der Entry keine tote Altlogik mehr mitführt. | Der Privacy-Requests-Alias reduziert überflüssigen Ballast im Routing-Pfad, hängt näher am bereits bereinigten Redirect-Muster von Alias-Entrys und bleibt für spätere DSGVO-/Routing-Änderungen klarer wartbar, weil nur noch der echte Guard- und Ziel-Redirect übrig ist. |

### Delta Batch 236

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/redirect-manager.php` | umgesetzt | Redirect-Mutationen wieder explizit über eine kleine Action-Allowlist begrenzt, positive IDs und Slug-Filter serverseitig vor dem Dispatch normalisiert sowie jede Mutation an `manage_settings` gebunden, damit Redirect-Pfade keine losen Request-Werte oder pauschalen Admin-Zugriffe direkt im Einstieg übernehmen. | Der Redirect-Manager reduziert implizite Request- und Rechteannahmen im Mutationspfad, hält Action-, ID-, Slug- und Capability-Gates näher am übrigen Admin-Wrapper-Muster und blockt unbekannte Aktionen, rohe IDs, leere Slug-Cleanup-Requests oder capability-fremde Zugriffe sichtbar früher, bevor Modulmethoden unnötig angesprungen werden. |

### Delta Batch 235

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/firewall.php` | umgesetzt | Firewall-Mutationen wieder explizit über kleine Action-Allowlists begrenzt, positive Regel-IDs serverseitig vor Toggle-/Delete-Dispatches normalisiert und jede Mutation an eine explizite Capability gebunden, damit der Entry keine losen Request-Werte oder pauschalen Admin-Zugriffe direkt in Settings- und Regelpfade übernimmt. | Der Firewall-Entry reduziert implizite Request- und Rechteannahmen im Mutationspfad, hält Action-, ID- und Capability-Gates näher am übrigen Admin-Wrapper-Muster und blockt unbekannte Aktionen, rohe IDs oder capability-fremde Requests sichtbar früher, bevor Modulmethoden unnötig angesprungen werden. |

### Delta Batch 234

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/packages.php` / `CMS/admin/modules/subscriptions/PackagesModule.php` | umgesetzt | Paket-Mutationen wieder explizit über eine kleine Action-Allowlist begrenzt, positive IDs serverseitig vor Delete-/Toggle-Dispatches normalisiert und den Modul-Logger auf den vorhandenen Channel-Vertrag zurückgeführt, damit Paketpfade keine losen Request-Werte oder versteckten Logger-Fatals mehr mittragen. | Der Packages-Pfad reduziert implizite Request-Annahmen im Mutations-Entry, hält Action-, ID- und Fehlerpfade näher am übrigen Admin-Wrapper-/Logger-Muster und blockt unbekannte Aktionen oder ungültige IDs sichtbar früher, bevor Modulmethoden unnötig angesprungen oder Fehlerfälle zusätzlich durch einen Logger-Fatal verschärft werden. |

### Delta Batch 233

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/design-settings.php` | umgesetzt | Den Redirect-Entry explizit an `manage_settings` gebunden und Access-/Fallback-Pfade in kleine Helfer gezogen, damit der Einstieg nicht mehr nur über einen pauschalen Admin-Check den Theme-Editor öffnet. | Der Design-Settings-Entry reduziert implizite Rechteannahmen im Redirect-Pfad, hält Access- und Fallback-Logik näher an einem kleinen Wrapper-Vertrag und blockt capability-fremde Zugriffe sichtbar früher, bevor sie in nachgelagerte Theme-Editor-Pfade weitergeleitet werden. |

### Delta Batch 232

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/antispam.php` | umgesetzt | AntiSpam-Mutationen wieder explizit über kleine Action-Allowlists begrenzt, positive IDs serverseitig vor Delete-Dispatches normalisiert und jede Aktion an eine explizite Capability gebunden, damit der Entry keine losen Request-Werte oder pauschalen Admin-Zugriffe direkt in Settings- und Blacklist-Pfade übernimmt. | Der AntiSpam-Entry reduziert implizite Request- und Rechteannahmen im Mutationspfad, hält Action-, ID- und Capability-Gates näher am übrigen Admin-Wrapper-Muster und blockt unbekannte Aktionen, rohe IDs oder capability-fremde Requests sichtbar früher, bevor Modulmethoden unnötig angesprungen werden. |

### Delta Batch 231

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/comments.php` | umgesetzt | Kommentar-Mutationen wieder explizit über kleine Action-, Status- und Bulk-Allowlists begrenzt sowie positive Einzel- und Bulk-IDs serverseitig vor dem Dispatch normalisiert, damit der Kommentar-Entry keine losen Request-Werte direkt aus `POST` in Moderations-, Delete- oder Bulk-Pfade übernimmt. | Der Kommentar-Entry reduziert implizite Request-Annahmen im Mutationspfad, hält Action-, Status-, ID- und Bulk-Gates näher am übrigen Admin-Wrapper-Muster und blockt unbekannte Aktionen, rohe IDs oder ungültige Status-/Bulk-Werte sichtbar früher, bevor Modulmethoden unnötig angesprungen werden. |

### Delta Batch 230

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/seo-page.php` | umgesetzt | Die gemeinsame SEO-Eintrittsschicht auf eine kanonische Section-Registry mit festen View-/Route-Metadaten, seitengebundenen Action-Allowlists und sicheren Redirect-Zielen gezogen, damit SEO-Unterseiten keine losen Konfigurations- oder POST-Werte direkt im zentralen Wrapper übernehmen. | Der SEO-Sammel-Wrapper reduziert implizite Vertrauensannahmen bei Section-, Render-, Redirect- und Mutationspfaden, hält seine Unterseiten näher an einem kleinen gemeinsamen Admin-Vertrag und blockt unbekannte Actions oder fremde Redirect-Ziele sichtbar früher, bevor Modulmethoden, View-Loads oder PRG-Sprünge unnötig angesprungen werden. |

### Delta Batch 229

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/orders.php` | umgesetzt | Bestellmutationen wieder explizit über eine kleine Action-Allowlist begrenzt und Status-, Billing-, ID- sowie Action-Werte serverseitig vor dem Dispatch normalisiert, damit der Orders-Entry keine losen Request-Werte direkt aus `POST` in Status-, Delete- oder Zuweisungspfade übernimmt. | Der Orders-Entry reduziert implizite Request-Annahmen im Mutationspfad, hält Input-Gates näher am übrigen Admin-Wrapper-Muster und blockt ungültige Aktionen, rohe IDs oder unzulässige Status-/Billing-Werte sichtbar früher, bevor Modulmethoden oder tiefere Guard-Pfade unnötig angesprungen werden. |

### Delta Batch 228

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/site-tables.php` | umgesetzt | Tabellen-Mutationen wieder explizit über eine kleine Action-Allowlist begrenzt, positive IDs serverseitig normalisiert und View-/Redirect-Pfade auf kanonische Modi zurückgeführt, damit Tabellen-Entry und View-Routing keine losen Request-Werte direkt aus `POST`/`GET` übernehmen. | Der Site-Tables-Entry reduziert implizite Request-Annahmen im Mutations- und Renderpfad, hält Flash-, Redirect- und View-Gates näher am übrigen Admin-Wrapper-Muster und blockt unzulässige Aktionen, rohe IDs oder unbekannte View-Modi sichtbar früher, bevor Modulmethoden oder falsche Redirect-Ziele unnötig angesprungen werden. |

### Delta Batch 227

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/comments/CommentsModule.php` / `CMS/core/Services/CommentService.php` | umgesetzt | Beitrag-Links der Kommentar-Moderation auf den zentralen `PermalinkService` umgestellt und die benötigten Post-Datumswerte direkt im Comment-Listing mitgeführt, damit die Moderationsliste keine harten `/blog/{slug}`-Pfade mehr annimmt. | Die Kommentar-Moderation reduziert harte Routing-Annahmen im Admin-Listing, hängt ihre Beitrag-Links näher an den gemeinsamen Permalink-Vertrag des Cores und zeigt auch bei konfigurierbaren Post-Strukturen weiterhin auf die korrekten Beitrag-URLs statt still in Legacy-Pfade abzuweichen. |

### Delta Batch 226

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/error-report.php` | umgesetzt | Report-Felder bereits im Wrapper serverseitig begrenzt, `source_url` auf interne/same-site Ziele normalisiert und JSON-Payloads in Größe, Tiefe und Struktur gedeckelt, damit Fehlerreports keine ausufernden oder losen Request-Daten direkt an den Service weiterreichen. | Der Error-Report-Entry reduziert vertrauensvolle Request-Annahmen im Report-Pfad, hält Feld- und Payload-Gates näher an einem kleinen Wrapper-Vertrag und blockt übergroße JSON-Strings oder unsaubere Source-Ziele sichtbar früher, bevor Service- und Datenbankpfade unnötig belastet werden. |

### Delta Batch 225

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/groups.php` | umgesetzt | Mutationen wieder explizit über eine kleine Action-Allowlist begrenzt und Gruppen-IDs serverseitig vor Delete-Dispatches normalisiert, damit Gruppenpfade keine losen Aktionen oder rohen IDs direkt aus dem Request übernehmen. | Der Gruppen-Entry reduziert implizite Request-Annahmen im Mutationspfad, hält Delete-Gates näher am übrigen Admin-Wrapper-Muster und blockt ungültige Aktionswerte oder Gruppen-IDs sichtbar früher, bevor Modulmethoden unnötig angerufen werden. |

### Delta Batch 224

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/legal/PrivacyRequestsModule.php` / `CMS/admin/modules/legal/DeletionRequestsModule.php` | umgesetzt | Mutationspfade explizit an den erwarteten Request-Typ gebunden, damit Auskunfts- und Löschanträge nicht mehr allein über rohe IDs Datensätze des jeweils anderen DSGVO-Pfads verändern oder löschen können. | Die DSGVO-Module reduzieren implizite Vertrauensannahmen gegenüber IDs im Mutationspfad, halten Hook-, Update- und Delete-Operationen näher an einem kleinen Typ-Vertrag und blocken bereichsfremde Requests sichtbar früher, bevor unnötige Seiteneffekte entstehen. |

### Delta Batch 223

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/cookie-manager.php` | umgesetzt | Delete- und Import-Mutationen weiter serverseitig gehärtet, indem POST-Aktionen, positive IDs, `service_slug` und Self-Hosted-Flags bereits im Wrapper normalisiert und vor dem Dispatch auf Plausibilität geprüft werden. | Der Cookie-Manager-Entry reduziert implizite Request-Annahmen im Mutationspfad, hält Delete- und Import-Gates näher am übrigen Admin-Wrapper-Muster und blockt ungültige IDs oder unbekannte Service-Slugs sichtbar früher, bevor Modulmethoden unnötig angerufen werden. |

### Delta Batch 222

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/backups.php` | umgesetzt | Backup-Mutationen wieder explizit über eine kleine Allowlist begrenzt und `backup_name` serverseitig vor Delete-Dispatches normalisiert, damit Backup-Löschpfade keine losen Aktionen oder unsauberen Namen direkt aus dem Request übernehmen. | Der Backup-Entry reduziert implizite Fallback-Dispatches im Mutationspfad, hält Request-Gates näher am übrigen Admin-Wrapper-Muster und blockt ungültige Aktionswerte oder Backup-Namen sichtbar früher, bevor Modulmethoden unnötig angerufen werden. |

### Delta Batch 221

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/data-requests.php` | umgesetzt | DSGVO-Mutationen wieder explizit über scope-gebundene Allowlists begrenzt und `scope`, `action`, `id` sowie Ablehnungsgründe serverseitig vor dem Dispatch normalisiert, damit Auskunfts- und Löschpfade keine losen oder bereichsfremden Aktionen direkt aus dem Request übernehmen. | Der DSGVO-Sammel-Entry reduziert implizite Fallback-Dispatches zwischen Auskunfts- und Löschpfad, hält Request-Gates näher am übrigen Admin-Wrapper-Muster und blockt unzulässige Scope-/Action-Kombinationen oder unsaubere Request-Werte sichtbar früher, bevor Modulmethoden unnötig angerufen werden. |

### Delta Batch 220

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/ThemeMarketplaceModule.php` | umgesetzt | Marketplace-Installationen auf den zentralen `UpdateService` umgestellt, damit Theme-Pakete nicht mehr über einen separaten ZIP-/Extract-Pfad direkt ins Themes-Verzeichnis entpackt werden, sondern denselben staging-basierten Installations- und Rollback-Vertrag wie reguläre Updates nutzen. | Das Theme-Marketplace-Modul reduziert parallele Download-, Archiv- und Cleanup-Logik im Installationspfad, hält Zielpfad- und Integritätsprüfungen näher am gemeinsamen Core-Service und bindet Marketplace-Installationen sauberer an denselben atomaren Verzeichnis-Swap wie der restliche Update-Lifecycle. |

### Delta Batch 219

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/documentation.php` | umgesetzt | Sync-Aktionen wieder explizit über eine Allowlist begrenzt und den `action`-Wert serverseitig vor dem Dispatch normalisiert, damit der Documentation-Entry keine losen oder nicht erlaubten Aktionen bloß implizit über die Handler-Map behandelt. | Der Documentation-Entry reduziert implizite Fallback-Dispatches im Sync-Pfad, hält Request-Gates näher am übrigen Admin-Wrapper-Muster und blockt unzulässige Aktionen sichtbar früher, bevor Modulmethoden oder Ergebnisobjekte unnötig aufgebaut werden. |

### Delta Batch 218

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/font-manager.php` | umgesetzt | POST-Aktionen wieder explizit über eine Allowlist begrenzt sowie `font_id` und Google-Font-Familien bereits im Wrapper serverseitig normalisiert, damit Font-Delete- und Download-Pfade keine losen oder unsauberen Request-Werte direkt übernehmen. | Der Font-Manager-Entry reduziert implizite Fallback-Dispatches im Mutationspfad, hält Request-Gates näher am übrigen Admin-Wrapper-Muster und blockt ungültige Action-, ID- oder Family-Werte sichtbar früher, bevor Modulmethoden unnötig angerufen werden. |

### Delta Batch 217

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/mail-settings.php` | umgesetzt | POST-Aktionen explizit an die erlaubten Tabs gebunden, den `action`-Wert serverseitig normalisiert und Redirects nach Mutationen auf den kanonischen Bereich zurückgeführt, damit Mail-, Azure-, Graph-, Log- und Queue-Pfade keine losen oder bereichsfremden Aktionen direkt aus dem Request übernehmen. | Der Mail-Settings-Entry reduziert implizite Fallback-Dispatches zwischen Transport-, Azure-, Graph-, Logs- und Queue-Bereich, hält Request-Gates näher am übrigen Admin-Wrapper-Muster und blockt unzulässige Tab-/Action-Kombinationen sichtbar früher, bevor Modulmethoden unnötig angerufen werden. |

### Delta Batch 216

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/legal-sites.php` | umgesetzt | POST-Aktionen wieder explizit über eine Allowlist begrenzt und den `template_type` vor dem Modulaufruf serverseitig normalisiert, damit Rechtstext-Generierung und Seitenerstellung keine losen Aktionen oder unsauberen Typen direkt aus dem Request übernehmen. | Der Legal-Sites-Entry reduziert implizite Fallback-Dispatches im Mutationspfad, hält Request-Gates näher am übrigen Admin-Wrapper-Muster und blockt ungültige Template-Typen sichtbar früher, bevor sie tiefer in Template- oder Seitenerstellungslogik laufen. |

### Delta Batch 215

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/FontManagerModule.php` | umgesetzt | Remote-Fontdownloads auf eine kleine Anzahl erlaubter Dateien begrenzt und lokal gespeicherte Dateinamen aus Remote-URLs hart normalisiert, damit auffällige oder überlange Dateinamen nicht ungefiltert im verwalteten Fonts-Verzeichnis landen. | Das Font-Manager-Modul reduziert vertrauensvolle Annahmen gegenüber Remote-URL-Pfaden im Download-Flow, hält lokale Font-Dateien näher an einem klaren Namens- und Mengenvertrag und verwirft ausufernde Remote-CSS-Pakete früher, bevor sie im lokalen Fonts-Root materialisiert werden. |

### Delta Batch 214

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/FontManagerModule.php` | umgesetzt | Lokale Font- und CSS-Dateien vor Delete-Aktionen explizit an das verwaltete Verzeichnis `uploads/fonts/` gebunden, damit DB-basierte Pfade keine Löschoperationen außerhalb des vorgesehenen Fonts-Roots auslösen. | Das Font-Manager-Modul reduziert vertrauensvolle Dateisystemannahmen im Delete-Pfad, hält lokale Font-Löschungen näher an einem klaren Root-Vertrag und verwirft problematische Pfadangaben kontrollierter, bevor Dateien auf dem System angefasst werden. |

### Delta Batch 213

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/media.php` | umgesetzt | Den Multi-Upload-Request im Media-Entry robuster normalisiert, unvollständige oder inkonsistente `$_FILES`-Strukturen früh verworfen und aggregierte Upload-Fehler auf eine kleine, kompaktere Ausgabe begrenzt, damit der Upload-Pfad bei problematischen Requests kontrollierter bleibt. | Der Media-Entry reduziert implizite Annahmen über die Form von Multi-Upload-Payloads, hält den Upload-Loop näher an einem expliziten Request-Vertrag und verhindert übermäßig anwachsende Flash-Meldungen, wenn mehrere Dateien in einem fehlerhaften Batch scheitern. |

### Delta Batch 212

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/plugins.php` / `CMS/admin/themes.php` | umgesetzt | POST-Aktionen in den Plugin- und Theme-Entrys wieder explizit über Allowlists begrenzt und den Plugin-Slug vor dem Modulaufruf serverseitig normalisiert, damit Mutationspfade keine losen Aktionen oder unsauberen Slugs direkt aus dem Request übernehmen. | Die Entrys reduzieren implizite Fallback-Dispatches in Aktivierungs-, Deaktivierungs- und Löschpfaden, halten die Request-Gates näher am bereits strengeren Update-/Marketplace-Muster und blocken ungültige Aktionswerte sichtbar früher, bevor Modulmethoden unnötig angerufen werden. |

### Delta Batch 211

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/ThemeMarketplaceModule.php` | umgesetzt | Lokale Manifestpfade traversal-sicher innerhalb des Katalog-Roots normalisiert und Theme-ZIPs zusätzlich gegen übermäßige Eintragsanzahl, Kontrollzeichen und unkomprimierte Gesamtgröße begrenzt, damit auffällige Marketplace-Pakete früher blockiert werden. | Das Theme-Marketplace-Modul reduziert lockere Dateipfadannahmen im lokalen Katalogpfad, hält Manifest-Reads näher an einem expliziten Root-Vertrag und verwirft problematische Theme-Archive kontrollierter schon vor dem Entpacken. |

### Delta Batch 210

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/plugins/PluginMarketplaceModule.php` | umgesetzt | Marketplace-Installationen auf den zentralen `UpdateService` umgestellt, damit Plugin-Pakete nicht mehr über einen separaten ZIP-/Extract-Pfad direkt ins Plugins-Verzeichnis entpackt werden, sondern denselben staging-basierten Installations- und Rollback-Vertrag wie reguläre Updates nutzen. | Das Plugin-Marketplace-Modul reduziert parallele Download-, Archiv- und Cleanup-Logik im Installationspfad, hält Zielpfad- und Integritätsprüfungen näher am gemeinsamen Core-Service und bindet Marketplace-Installationen sauberer an denselben atomaren Verzeichnis-Swap wie der restliche Update-Lifecycle. |

### Delta Batch 209

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-marketplace.php` / `CMS/admin/plugin-marketplace.php` | umgesetzt | POST-Aktionen im Marketplace-Entry wieder explizit über Allowlists begrenzt und den Plugin-Slug vor dem Modulaufruf serverseitig normalisiert, damit Installationspfade keine losen Mutationen oder unsauberen Slug-Werte direkt aus dem Request übernehmen. | Die Marketplace-Entrys reduzieren implizite Fallback-Dispatches im POST-Pfad, halten Theme- und Plugin-Installationen näher am gemeinsamen Admin-Wrapper-Muster und blocken ungültige Aktions- oder Slug-Werte früher, bevor sie tiefer in den Installationsfluss wandern. |

### Delta Batch 208

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/UpdatesModule.php` / `CMS/admin/updates.php` | umgesetzt | Core-, Plugin- und Theme-Checks pro Modulinstanz als Snapshot gebündelt und manuelle Update-Prüfresultate per Session-Snapshot über den PRG-Redirect in den Folge-Request übernommen, damit eine explizite Check-Aktion dieselben Remote-Reads nicht unmittelbar doppelt ausführt. | Der Update-Pfad reduziert direkte Doppelchecks im Prüfen-→-Redirect-→-Rendern-Ablauf, hält Core-, Plugin- und Theme-Status näher an einem kleinen Snapshot-Vertrag zusammen und entkoppelt den Entry sauberer von losem Callback-Verhalten. |

### Delta Batch 207

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/core/ThemeManager.php` / `CMS/admin/modules/themes/ThemesModule.php` | umgesetzt | Verfügbare Themes pro Theme-Manager-Instanz inklusive `theme.json`-/Screenshot-Metadaten gecached, Theme-Wechsel und Löschpfade den Cache gezielt invalidieren lassen und Theme-Löschungen im Admin-Modul wieder an den zentralen Theme-Manager delegiert, damit Theme-Listen keine doppelte Dateisystem-Anreicherung fahren und Delete-Pfade Audit-/Lifecycle-Verträge nicht lokal umgehen. | Die Theme-Verwaltung reduziert wiederholte Dateisystem-Reads im Listenpfad, hält Theme-Metadaten näher an einem gemeinsamen Core-Vertrag und bindet Delete-Aktionen wieder sauber an den zentralen Theme-Lifecycle statt parallele Modul-Delete-Logik separat zu pflegen. |

### Delta Batch 206

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/plugins/PluginsModule.php` | umgesetzt | Aktive Plugin-Slugs pro Modulinstanz gecached, Fallback-Persistenz der `active_plugins`-Settings über kleine Helfer gebündelt und Delete-Pfade wieder an den zentralen `PluginManager` delegiert, damit Plugin-Listen keine N+1-Status-Lookups fahren und Löschungen Uninstall-/Delete-Hooks nicht lokal umgehen. | Das Plugins-Modul reduziert wiederholte Aktivstatus-Lookups im Listen- und Mutationspfad, hält Fallback-Persistenz näher an einem gemeinsamen Modulvertrag und bindet Delete-Aktionen wieder sauber an den zentralen Plugin-Lifecycle statt parallele Dateisystemlogik separat zu pflegen. |

### Delta Batch 205

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/hub/HubSitesModule.php` | umgesetzt | Normalisierte Zusatzdomain-Zuordnungen pro Modulinstanz gecached und Save-/Delete-/Duplicate-Pfade den Cache nach Mutationen gezielt invalidieren lassen, damit Zusatzdomain-Prüfungen nicht pro Domain erneut alle Hub-Sites samt JSON-Settings lesen müssen. | Das Hub-Sites-Modul reduziert wiederholte Vollscans im Zusatzdomain-Validierungspfad, hält Domain-Konfliktprüfungen näher an einem gemeinsamen Datenindex und bleibt bei mehreren Hub-Domains pro Save-Vorgang deutlich schlanker und vertragsnäher. |

### Delta Batch 204

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/media/MediaModule.php` | umgesetzt | Kategorien pro Modulinstanz gecached und die Persistenz von Dateinamen-/Thumbnail-Settings wieder auf die kanonischen Service-Keys gezogen, damit Kategorien nicht mehrfach identisch geladen werden und die Medien-Settings nicht auf tolerierte Alias-Namen angewiesen bleiben. | Das Media-Modul reduziert wiederholte Kategorie-Lookups im selben Request, hält Bibliothek, Kategorien-Tab und Kategorievalidierung näher an einem gemeinsamen Datenpfad und schreibt seine Einstellungen wieder explizit im erwarteten Medien-Service-Vertrag. |

### Delta Batch 203

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/plugins/PluginMarketplaceModule.php` | umgesetzt | Die normalisierte Plugin-Registry pro Modulinstanz gecached und den HTTP-Client wiederverwendet, damit Registry- und Manifest-Zugriffe nicht mehrfach unnötig neu initialisiert werden. | Das Plugin-Marketplace-Modul reduziert wiederholte Registry-Ladevorgänge im selben Lebenszyklus, hält den Transportzugang zentraler und bleibt im Marketplace-Datenpfad näher an einem schlanken, wiederverwendbaren Modulvertrag. |

### Delta Batch 202

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/ThemeMarketplaceModule.php` | umgesetzt | Den normalisierten Theme-Katalog pro Modulinstanz gecached und die Themensuche für Installationen direkt auf den schlanken Katalogpfad gezogen, statt erneut den kompletten angereicherten Marketplace-Datenaufbau auszulösen. | Das Theme-Marketplace-Modul reduziert wiederholte Remote-/Normalisierungsarbeit im Installationspfad, hält `findCatalogTheme()` näher am eigentlichen Suchzweck und entkoppelt die Installationslogik stärker vom View-spezifischen Statusaufbau. |

### Delta Batch 201

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/themes/FontManagerModule.php` | umgesetzt | Vorhandene Setting-Namen für den Font-Manager gesammelt vorgeladen und die Persistenz über einen gemeinsamen Helfer gebündelt, damit beim Speichern von Font-Optionen keine N+1-Existenzchecks pro Schlüssel mehr laufen. | Das Font-Manager-Modul reduziert wiederholte Datenbank-Roundtrips im Settings-Save-Pfad, hält `saveSettings()` näher an der eigentlichen Input-Normalisierung und schafft einen klareren Persistenzvertrag für weitere Font-Optionen. |

### Delta Batch 200

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/cookie-manager.php` | umgesetzt | Ziel-URL, Redirect-, Allowlist- und Dispatch-Pfade über kleine Helfer gebündelt, damit Token-Checks sowie Cookie-Manager-Aktionen konsistenter bleiben. | Der Cookie-Manager-Entry reduziert verteilte Request-Logik im Einstieg, hält Fehler- und Redirect-Pfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Ziel-, Allowlist- und Dispatch-Details mehrfach zu pflegen. |

### Delta Batch 199

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/legal-sites.php` | umgesetzt | Ziel-URL, Redirect-, Profil-Session-State und Template-Aufbau über kleine Helfer gebündelt, damit Token-Checks sowie Profil-, Template- und Redirect-Pfade konsistenter bleiben. | Der Legal-Sites-Entry reduziert verteilte Request-Logik im Einstieg, hält Fehler- und Redirect-Pfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Session-, Ziel- und Template-Details mehrfach zu pflegen. |

### Delta Batch 198

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/error-report.php` | umgesetzt | Default-/Redirect-Ziel, JSON-Payload-Normalisierung und Report-Dispatch über kleine Helfer gebündelt, damit Token-Checks sowie Fehlerreport- und Redirect-Pfade konsistenter bleiben. | Der Error-Report-Entry reduziert verteilte Request-Logik im Einstieg, hält Fehler- und Redirect-Pfade kompakter und trennt Ziel-, Payload- und Dispatch-Details klarer vom eigentlichen Service-Aufruf. |

### Delta Batch 197

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-explorer.php` | umgesetzt | Ziel-URL, Action-Allowlist, Redirect-, Flash-/Pull-Alert-Pfade und Save-Dispatch über kleine Helfer gebündelt, damit Token-Checks sowie Datei-Save- und Fehlerpfade konsistenter bleiben. | Der Theme-Explorer-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehler- und Redirect-Pfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Session-, Ziel- und Dispatch-Details mehrfach zu pflegen. |

### Delta Batch 196

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/hub-sites.php` | umgesetzt | Action-Allowlist, View-Normalisierung, Redirect-Abgänge, Flash-/Pull-Alert-Pfade, Action-Dispatch und Render-Konfiguration über kleine Helfer gebündelt, damit Listen-, Edit- und Template-Pfade konsistenter bleiben. | Der Hub-Sites-Entry reduziert verteilte Request- und Render-Logik im Einstieg, hält Fehler- und Redirect-Pfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt View-, Asset- und Dispatch-Details mehrfach zu pflegen. |

### Delta Batch 195

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/media.php` | umgesetzt | Action-Allowlist, Pfadauflösung, Redirect-Parameter, Upload-Rückmeldungen und Action-Dispatch über kleine Helfer gebündelt, damit Token-Checks sowie Library-, Kategorie- und Settings-Aktionen konsistenter bleiben. | Der Media-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehler- und Redirect-Pfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Query-, Upload- und Dispatch-Details mehrfach zu pflegen. |

### Delta Batch 194

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-editor.php` | umgesetzt | Layout- und Fallback-Pfade über kleine Helfer gebündelt, damit Customizer-Einbettung, Fallback-Links und Admin-Layout-Aufbau konsistenter bleiben. | Der Theme-Editor-Entry reduziert verteilte Wrapper-Logik im Einstieg, hält Fallback-Pfade kompakter und bleibt näher an seinem eigentlichen Routing-Zweck statt Header-, Sidebar- und Fallback-Details mehrfach zu pflegen. |

### Delta Batch 193

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/updates.php` | umgesetzt | Redirect-, Flash-, Pull-Alert-, Allowlist- und Action-Dispatch-Pfade über kleine Helfer gebündelt, damit Token-Checks, Update-Prüfung sowie Core- und Plugin-Installationsrückgaben konsistenter bleiben. | Der Updates-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Alert-, Redirect- und Dispatch-Details mehrfach zu pflegen. |

### Delta Batch 192

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/themes.php` | umgesetzt | Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade über kleine Helfer gebündelt, damit Token-Checks, Theme-Slug-Normalisierung sowie Aktivierungs- und Löschrückgaben konsistenter bleiben. | Der Themes-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Alert-, Redirect- und Dispatch-Details mehrfach zu pflegen. |

### Delta Batch 191

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/plugins.php` | umgesetzt | Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade über kleine Helfer gebündelt, damit Token-Checks sowie Aktivierungs-, Deaktivierungs- und Löschrückgaben konsistenter bleiben. | Der Plugins-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Alert-, Redirect- und Dispatch-Details mehrfach zu pflegen. |

### Delta Batch 190

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-marketplace.php` | umgesetzt | Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade über kleine Helfer gebündelt, damit Token-Checks, Theme-Slug-Normalisierung und Rückmeldungen konsistenter bleiben. | Der Theme-Marketplace-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Alert-, Redirect- und Dispatch-Details mehrfach zu pflegen. |

### Delta Batch 189

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/plugin-marketplace.php` | umgesetzt | Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade über kleine Helfer gebündelt, damit Token-Checks, Installationsaktionen und Rückmeldungen konsistenter bleiben. | Der Plugin-Marketplace-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Alert-, Redirect- und Dispatch-Details mehrfach zu pflegen. |

### Delta Batch 188

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/landing-page.php` | umgesetzt | Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade über kleine Helfer gebündelt, damit Token-Checks, Tab-Pfade und Rückmeldungen konsistenter bleiben. | Der Landing-Page-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Alert-, Tab- und Dispatch-Details mehrfach zu pflegen. |

### Delta Batch 187

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/firewall.php` | umgesetzt | Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade über kleine Helfer gebündelt, damit Token-Checks, Aktionspfade und Rückmeldungen konsistenter bleiben. | Der Firewall-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Alert- und Dispatch-Details mehrfach zu pflegen. |

### Delta Batch 186

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/font-manager.php` | umgesetzt | Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade über kleine Helfer gebündelt, damit Token-Checks, Aktionspfade und Rückmeldungen konsistenter bleiben. | Der Font-Manager-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Alert- und Dispatch-Details mehrfach zu pflegen. |

### Delta Batch 185

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/backups.php` | umgesetzt | Redirect-, Flash-, Pull-Alert- und Action-Dispatch-Pfade über kleine Helfer gebündelt, damit Token-Checks, Aktionspfade und Rückmeldungen konsistenter bleiben. | Der Backups-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Alert- und Dispatch-Details mehrfach zu pflegen. |

### Delta Batch 184

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/cookie-manager.php` | umgesetzt | Action-Allowlist und Session-Alert-Pfade über kleine Helfer gebündelt, damit Token-Checks, Aktionspfade und Rückmeldungen konsistenter bleiben. | Der Cookie-Manager-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Allowlist- und Alert-Details lose zu pflegen. |

### Delta Batch 183

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/packages.php` | umgesetzt | Action-Dispatch und Session-Alert-Pfade über kleine Handler-Helfer gebündelt, damit Paket- und Settings-Aktionen konsistenter bleiben. | Der Packages-Entry reduziert verteilte Switch-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher an den eigentlichen Modulen statt Flash- und Dispatch-Details mehrfach auszuschreiben. |

### Delta Batch 182

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/orders.php` | umgesetzt | Action-Allowlist und Session-Alert-Pfade über kleine Helfer gebündelt, damit Token-Checks, Aktionspfade und Rückmeldungen konsistenter bleiben. | Der Orders-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Allowlist- und Alert-Details lose zu pflegen. |

### Delta Batch 181

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/error-report.php` | umgesetzt | Redirect-, Flash- und JSON-Normalisierung über kleine Entry-Helfer gebündelt, damit Fehlerfälle, Token-Checks und Report-Payload konsistenter bleiben. | Der Error-Report-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und trennt Request-Normalisierung klarer vom eigentlichen Service-Aufruf. |

### Delta Batch 180

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/deletion-requests.php` | umgesetzt | Das Redirect-Ziel des Löschanträge-Entrys über einen kleinen Ziel-Helfer gebündelt, damit Weiterleitungen konsistenter und sichtbarer bleiben. | Der Löschanträge-Entry reduziert hart verteilte Zielpfade im Einstieg und bleibt näher an seinem eigentlichen Routing-Zweck statt Ziel-URLs lose auszuschreiben. |

### Delta Batch 179

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/design-settings.php` | umgesetzt | Das Redirect-Ziel des Design-Entrys über einen kleinen Ziel-Helfer gebündelt, damit Weiterleitungen konsistenter und sichtbarer bleiben. | Der Design-Settings-Entry reduziert hart verteilte Zielpfade im Einstieg und bleibt näher an seinem eigentlichen Routing-Zweck statt Ziel-URLs lose auszuschreiben. |

### Delta Batch 178

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/legal-sites.php` | umgesetzt | POST-Dispatch, Flash-Meldungen und Redirects über kleine Entry-Helfer gebündelt, damit Aktionspfade, Fehlerfälle und PRG-Flow konsistenter bleiben. | Der Legal-Sites-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Session- und Redirect-Details mehrfach auszuschreiben. |

### Delta Batch 177

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/documentation.php` | umgesetzt | Aktionshandler, Flash-Meldungen und Redirects über kleine Entry-Helfer gebündelt, damit Dokumentauswahl, Fehlerfälle und PRG-Flow konsistenter bleiben. | Der Documentation-Entry reduziert verteilte Request-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Redirect- und Session-Details mehrfach auszuschreiben. |

### Delta Batch 176

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/mail-settings.php` | umgesetzt | Action-Handler enger an die Handler-Map gekoppelt und POST-Dispatch über denselben kleinen Entry-Pfad gebündelt, damit Fehlerfälle und PRG-Flow konsistenter bleiben. | Der Mail-Settings-Entry reduziert doppelte Aktionsdefinitionen im Einstieg, hält Dispatch-Pfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Handler- und Redirect-Details doppelt zu pflegen. |

### Delta Batch 175

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/groups.php` | umgesetzt | POST-Dispatch, Flash-Meldungen und Redirects über kleine Entry-Helfer gebündelt, damit Aktionspfade, Fehlerfälle und PRG-Flow konsistenter bleiben. | Der Gruppen-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Session- und Redirect-Details mehrfach auszuschreiben. |

### Delta Batch 174

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/data-requests.php` | umgesetzt | POST-Dispatch, Flash-Meldungen und Redirects über kleine Entry-Helfer gebündelt, damit Scope-Aktionen, Fehlerfälle und PRG-Flow konsistenter bleiben. | Der Data-Requests-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher an den eigentlichen Modulen statt Session- und Redirect-Details mehrfach auszuschreiben. |

### Delta Batch 173

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/settings.php` | umgesetzt | Tab-Normalisierung, POST-Dispatch, Flash-Meldungen und Redirects über kleine Entry-Helfer gebündelt, damit Aktionspfade, Fehlerfälle und PRG-Flow konsistenter bleiben. | Der Settings-Entry reduziert verteilte POST-Logik im Einstieg, hält Tab- und Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Redirect- und Session-Details mehrfach auszuschreiben. |

### Delta Batch 172

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/comments.php` | umgesetzt | POST-Dispatch, Flash-Meldungen und Redirects über kleine Entry-Helfer gebündelt, damit Aktionspfade, Fehlerfälle und PRG-Flow konsistenter bleiben. | Der Kommentare-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Session- und Redirect-Details mehrfach auszuschreiben. |

### Delta Batch 171

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/not-found-monitor.php` | umgesetzt | POST-Dispatch, Flash-Meldungen und Redirects über kleine Entry-Helfer gebündelt, damit Aktionspfade, Fehlerfälle und PRG-Flow konsistenter bleiben. | Der 404-Monitor-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Session- und Redirect-Details mehrfach auszuschreiben. |

### Delta Batch 170

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/menu-editor.php` | umgesetzt | POST-Dispatch, Flash-Meldungen und Redirects über kleine Entry-Helfer gebündelt, damit Aktionspfade, Fehlerfälle und PRG-Flow konsistenter bleiben. | Der Menü-Editor-Entry reduziert verteilte POST-Logik im Einstieg, hält Fehlerpfade kompakter und bleibt näher am eigentlichen Modul-Aufruf statt Session- und Redirect-Details mehrfach auszuschreiben. |

### Delta Batch 169

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/diagnose.php` | umgesetzt | Den Diagnose-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `system-monitor-page.php` übergeben werden. | Der Diagnose-Entry reduziert weiteres Wrapper-Duplikat im Monitoring-Bereich, hält Metadaten-Änderungen zentraler und rundet die gemeinsame System-/Monitoring-Familie konsistent ab. |

### Delta Batch 168

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance.php` | umgesetzt | Den Performance-Overview-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `performance-page.php` übergeben werden. | Der Performance-Overview-Entry reduziert weiteres Wrapper-Duplikat im Performance-Bereich, hält Metadaten-Änderungen zentraler und rundet die gemeinsame Performance-Familie konsistent ab. |

### Delta Batch 167

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/seo-schema.php` | umgesetzt | Den Schema-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `seo-page.php` übergeben werden. | Der Schema-Entry reduziert weiteres Wrapper-Duplikat im SEO-Bereich, hält Metadaten-Änderungen zentraler und rundet die gemeinsame SEO-Unterseitenfamilie konsistent ab. |

### Delta Batch 166

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/seo-sitemap.php` | umgesetzt | Den Sitemap-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `seo-page.php` übergeben werden. | Der Sitemap-Entry reduziert weiteres Wrapper-Duplikat im SEO-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 165

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/seo-technical.php` | umgesetzt | Den Technical-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `seo-page.php` übergeben werden. | Der Technical-Entry reduziert weiteres Wrapper-Duplikat im SEO-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 164

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/seo-audit.php` | umgesetzt | Den Audit-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `seo-page.php` übergeben werden. | Der Audit-Entry reduziert weiteres Wrapper-Duplikat im SEO-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 163

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/seo-page.php` | umgesetzt | Das gemeinsame SEO-Seitengerüst über einen kleinen Normalisierungs-Helper auf einen zentralen Konfigurationspfad gezogen, statt Fallbacks lose direkt im Einstieg zu verteilen. | `seo-page.php` reduziert weiteres Shell-Duplikat im SEO-Bereich, hält Default- und Wrapper-Metadaten zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 162

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/seo-social.php` | umgesetzt | Den Social-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `seo-page.php` übergeben werden. | Der Social-Entry reduziert weiteres Wrapper-Duplikat im SEO-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 161

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/seo-meta.php` | umgesetzt | Den Meta-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `seo-page.php` übergeben werden. | Der Meta-Entry reduziert weiteres Wrapper-Duplikat im SEO-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 160

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/seo-dashboard.php` | umgesetzt | Den Dashboard-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `seo-page.php` übergeben werden. | Der Dashboard-Entry reduziert weiteres Wrapper-Duplikat im SEO-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 159

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/analytics.php` | umgesetzt | Den Analytics-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `seo-page.php` übergeben werden. | Der Analytics-Entry reduziert weiteres Wrapper-Duplikat im SEO-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 158

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/info.php` | umgesetzt | Den Info-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `system-monitor-page.php` übergeben werden. | Der Info-Entry reduziert weiteres Wrapper-Duplikat im System-Bereich, hält Metadaten-Änderungen zentraler und rundet die gemeinsame System-/Monitoring-Familie konsistent ab. |

### Delta Batch 157

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/monitor-email-alerts.php` | umgesetzt | Den Email-Alerts-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `system-monitor-page.php` übergeben werden. | Der Email-Alerts-Entry reduziert weiteres Wrapper-Duplikat im Monitoring-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 156

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/monitor-health-check.php` | umgesetzt | Den Health-Check-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `system-monitor-page.php` übergeben werden. | Der Health-Check-Entry reduziert weiteres Wrapper-Duplikat im Monitoring-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 155

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/monitor-cron-status.php` | umgesetzt | Den Cron-Status-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `system-monitor-page.php` übergeben werden. | Der Cron-Status-Entry reduziert weiteres Wrapper-Duplikat im Monitoring-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 154

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/system-monitor-page.php` | umgesetzt | Das gemeinsame System-Monitor-Seitengerüst über einen kleinen Normalisierungs-Helper auf einen zentralen Konfigurationspfad gezogen, statt Fallbacks lose direkt im Einstieg zu verteilen. | `system-monitor-page.php` reduziert weiteres Shell-Duplikat im Monitoring-Bereich, hält Default- und Wrapper-Metadaten zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 153

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/monitor-scheduled-tasks.php` | umgesetzt | Den Scheduled-Tasks-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `system-monitor-page.php` übergeben werden. | Der Scheduled-Tasks-Entry reduziert weiteres Wrapper-Duplikat im Monitoring-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 152

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/monitor-disk-usage.php` | umgesetzt | Den Disk-Usage-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `system-monitor-page.php` übergeben werden. | Der Disk-Usage-Entry reduziert weiteres Wrapper-Duplikat im Monitoring-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 151

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/monitor-response-time.php` | umgesetzt | Den Response-Time-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `system-monitor-page.php` übergeben werden. | Der Response-Time-Entry reduziert weiteres Wrapper-Duplikat im Monitoring-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 150

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance-sessions.php` | umgesetzt | Den Sessions-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `performance-page.php` übergeben werden. | Der Sessions-Entry reduziert weiteres Wrapper-Duplikat im Performance-Bereich, hält Metadaten-Änderungen zentraler und rundet die gemeinsame Performance-Unterseitenfamilie konsistent ab. |

### Delta Batch 149

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance-page.php` | umgesetzt | Das gemeinsame Performance-Seitengerüst über einen kleinen Normalisierungs-Helper auf einen zentralen Konfigurationspfad gezogen, statt Fallbacks lose direkt im Einstieg zu verteilen. | `performance-page.php` reduziert weiteres Shell-Duplikat im Performance-Bereich, hält Default- und Wrapper-Metadaten zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 148

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance-settings.php` | umgesetzt | Den Settings-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `performance-page.php` übergeben werden. | Der Settings-Entry reduziert weiteres Wrapper-Duplikat im Performance-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 147

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance-media.php` | umgesetzt | Den Media-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `performance-page.php` übergeben werden. | Der Media-Entry reduziert weiteres Wrapper-Duplikat im Performance-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 146

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance-database.php` | umgesetzt | Den Database-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `performance-page.php` übergeben werden. | Der Database-Entry reduziert weiteres Wrapper-Duplikat im Performance-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 145

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/performance-cache.php` | umgesetzt | Den Cache-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `performance-page.php` übergeben werden. | Der Cache-Entry reduziert weiteres Wrapper-Duplikat im Performance-Bereich, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 144

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/member-dashboard-page.php` | umgesetzt | Das gemeinsame Member-Dashboard-Seitengerüst über einen kleinen Normalisierungs-Helper auf einen zentralen Konfigurationspfad gezogen, statt Fallbacks lose direkt im Einstieg zu verteilen. | `member-dashboard-page.php` reduziert weiteres Shell-Duplikat im Member-Dashboard, hält Default- und Wrapper-Metadaten zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 143

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/member-dashboard-widgets.php` | umgesetzt | Den Widgets-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `member-dashboard-page.php` übergeben werden. | Der Widgets-Entry reduziert weiteres Wrapper-Duplikat im Member-Dashboard, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 142

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/member-dashboard-profile-fields.php` | umgesetzt | Den Profile-Fields-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `member-dashboard-page.php` übergeben werden. | Der Profile-Fields-Entry reduziert weiteres Wrapper-Duplikat im Member-Dashboard, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 141

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/member-dashboard-plugin-widgets.php` | umgesetzt | Den Plugin-Widgets-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `member-dashboard-page.php` übergeben werden. | Der Plugin-Widgets-Entry reduziert weiteres Wrapper-Duplikat im Member-Dashboard, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 140

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/member-dashboard-onboarding.php` | umgesetzt | Den Onboarding-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `member-dashboard-page.php` übergeben werden. | Der Onboarding-Entry reduziert weiteres Wrapper-Duplikat im Member-Dashboard, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 139

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/member-dashboard-notifications.php` | umgesetzt | Den Notifications-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `member-dashboard-page.php` übergeben werden. | Der Notifications-Entry reduziert weiteres Wrapper-Duplikat im Member-Dashboard, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 138

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/member-dashboard-general.php` | umgesetzt | Den General-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `member-dashboard-page.php` übergeben werden. | Der General-Entry reduziert weiteres Wrapper-Duplikat im Member-Dashboard, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 137

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/member-dashboard-frontend-modules.php` | umgesetzt | Den Frontend-Modules-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `member-dashboard-page.php` übergeben werden. | Der Frontend-Modules-Entry reduziert weiteres Wrapper-Duplikat im Member-Dashboard, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 136

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/member-dashboard-design.php` | umgesetzt | Den Design-Entry auf einen kleinen Konfigurations-Wrapper umgestellt, sodass Section-, Route- und View-Metadaten nicht mehr als loses Variablenset an `member-dashboard-page.php` übergeben werden. | Der Design-Entry reduziert weiteres Wrapper-Duplikat im Member-Dashboard, hält Metadaten-Änderungen zentraler und bleibt bei späteren Unterseiten-Anpassungen konsistenter wartbar. |

### Delta Batch 135

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/member-dashboard.php` | umgesetzt | Den Alias-Entry für das Member-Dashboard über kleinen Redirect-Helper standardisiert statt roher Header-/Exit-Aufrufe direkt im Einstieg. | Der Member-Dashboard-Entry reduziert weiteres Entry-Duplikat im Aliaspfad, hält Redirect-Änderungen zentraler und bleibt bei späteren Member-Dashboard-Navigationen konsistenter wartbar. |

### Delta Batch 134

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/media.php` | umgesetzt | Wiederkehrende Redirect- und Session-Alert-Orchestrierung für Library-, Category- und Settings-Aktionen über kleine lokale Helper gebündelt statt mehrfacher ähnlicher POST-Abgänge im Media-Entry. | Der Media-Entry reduziert weiteres Entry-Duplikat im Media-POST-Pfad, hält Redirect-/Alert-Änderungen zentraler und bleibt bei späteren Medienaktionen konsistenter wartbar. |

### Delta Batch 133

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/legal-sites.php` | umgesetzt | Wiederkehrende Redirect-, Session-Alert- und Aktionsauflösung für Save-, Profile-, Generate- und Page-Aktionen über kleine lokale Helper gebündelt statt mehrfacher ähnlicher POST-Orchestrierung im Legal-Sites-Entry. | Der Legal-Sites-Entry reduziert weiteres Entry-Duplikat im Legal-POST-Pfad, hält Redirect-/Alert-Änderungen zentraler und bleibt bei späteren Legal-Site-Aktionen konsistenter wartbar. |

### Delta Batch 132

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/landing-page.php` | umgesetzt | Wiederkehrende Redirect-, Session-Alert- und Aktionsauflösung für Tab-bezogene Header-, Content-, Footer-, Design-, Feature- und Plugin-Aktionen über kleine lokale Helper gebündelt statt mehrfacher ähnlicher POST-Orchestrierung im Landing-Page-Entry. | Der Landing-Page-Entry reduziert weiteres Entry-Duplikat im Landing-POST-Pfad, hält Redirect-/Alert-Änderungen zentraler und bleibt bei späteren Landing-Page-Aktionen konsistenter wartbar. |

### Delta Batch 131

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/hub-sites.php` | umgesetzt | Wiederkehrende Redirect- und Session-Alert-Orchestrierung für Save-, Template-, Duplicate- und Delete-Aktionen über kleine lokale Helper gebündelt statt mehrfacher ähnlicher POST-Abgänge im Hub-Sites-Entry. | Der Hub-Sites-Entry reduziert weiteres Entry-Duplikat im Hub-POST-Pfad, hält Redirect-/Alert-Änderungen zentraler und bleibt bei späteren Hub-Site-Aktionen konsistenter wartbar. |

### Delta Batch 130

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/groups.php` | umgesetzt | Wiederkehrende Redirect-, Session-Alert- und Aktionsauflösung für Save- und Delete-Aktionen über kleine lokale Helper gebündelt statt mehrfacher ähnlicher POST-Orchestrierung im Gruppen-Entry. | Der Groups-Entry reduziert weiteres Entry-Duplikat im Users-POST-Pfad, hält Redirect-/Alert-Änderungen zentraler und bleibt bei späteren Gruppenaktionen konsistenter wartbar. |

### Delta Batch 129

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/firewall.php` | umgesetzt | Wiederkehrende Redirect-, Session-Alert- und Aktionsauflösung für Settings- und Rule-Aktionen über kleine lokale Helper gebündelt statt mehrfacher ähnlicher POST-Orchestrierung im Firewall-Entry. | Der Firewall-Entry reduziert weiteres Entry-Duplikat im Security-POST-Pfad, hält Redirect-/Alert-Änderungen zentraler und bleibt bei späteren Firewall-Aktionen konsistenter wartbar. |

### Delta Batch 128

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/error-report.php` | umgesetzt | Wiederkehrende Redirect- und Session-Alert-Orchestrierung für Report-Erstellung und Token-Fehler über kleine lokale Helper gebündelt statt mehrfacher ähnlicher POST-Abgänge im Error-Report-Entry. | Der Error-Report-Entry reduziert weiteres Entry-Duplikat im System-POST-Pfad, hält Redirect-/Alert-Änderungen zentraler und bleibt bei späteren Fehlerreport-Aktionen konsistenter wartbar. |

### Delta Batch 127

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/documentation.php` | umgesetzt | Wiederkehrende Alert-Speicherung und Redirect-Auflösung für Doku-Sync-Aktionen über kleine lokale Helper gebündelt statt mehrfacher ähnlicher POST-Orchestrierung im Dokumentations-Entry. | Der Documentation-Entry reduziert weiteres Entry-Duplikat im System-POST-Pfad, hält Redirect-/Alert-Änderungen zentraler und bleibt bei späteren Doku-Aktionen konsistenter wartbar. |

### Delta Batch 126

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/backups.php` | umgesetzt | Wiederkehrende Redirect- und Session-Alert-Orchestrierung für Create- und Delete-Aktionen über kleine lokale Helper gebündelt statt mehrfacher ähnlicher POST-Abgänge im Backup-Entry. | Der Backups-Entry reduziert weiteres Entry-Duplikat im System-POST-Pfad, hält Redirect-/Alert-Änderungen zentraler und bleibt bei späteren Backup-Aktionen konsistenter wartbar. |

### Delta Batch 125

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/antispam.php` | umgesetzt | Wiederkehrende Redirect-, Session-Alert- und Aktionsauflösung für Settings- und Blacklist-Aktionen über kleine lokale Helper gebündelt statt mehrfacher identischer POST-Orchestrierung im Security-Entry. | Der AntiSpam-Entry reduziert weiteres Entry-Duplikat im Security-POST-Pfad, hält Redirect-/Alert-Änderungen zentraler und bleibt bei späteren AntiSpam-Aktionen konsistenter wartbar. |

### Delta Batch 124

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/not-found-monitor.php` | umgesetzt | Wiederkehrende Redirect-, Session-Alert-, Details- und Aktionsauflösung für Redirect-Speicherung und Log-Bereinigung über kleine lokale Helper gebündelt statt mehrfacher identischer POST-Orchestrierung im SEO-Entry. | Der 404-Monitor-Entry reduziert weiteres Entry-Duplikat im SEO-POST-Pfad, hält Redirect-/Alert-Änderungen zentraler und bleibt bei späteren Redirect- und Log-Aktionen konsistenter wartbar. |

### Delta Batch 123

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/design-settings.php` | umgesetzt | Den Alias-Entry für Design-Einstellungen über kleinen Redirect-Helper standardisiert statt roher Header-/Exit-Aufrufe direkt im Einstieg. | Der Design-Settings-Entry reduziert weiteres Entry-Duplikat im Aliaspfad, hält Redirect-Änderungen zentraler und bleibt bei späteren Theme-Navigationen konsistenter wartbar. |

### Delta Batch 122

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/deletion-requests.php` | umgesetzt | Den Redirect-Alias auf Admin-Guard plus Ziel-Redirect verschlankt und unerreichbaren Altcode hinter dem sofortigen Redirect entfernt. | Der Deletion-Requests-Entry reduziert weiteres Entry-Ballast im DSGVO-Aliaspfad, hält Redirect-Änderungen zentraler und bleibt bei späteren Legal-Navigationen konsistenter wartbar. |

### Delta Batch 121

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/data-requests.php` | umgesetzt | Wiederkehrende Alert-Normalisierung und Scope-Aktionsauflösung für Auskunfts- und Löschanträge über kleine lokale Helper gebündelt statt mehrfacher ähnlicher Verzweigungs- und Alert-Blöcke im DSGVO-POST-Flow. | Der Data-Requests-Entry reduziert weiteres Entry-Duplikat im DSGVO-POST-Pfad, hält Alert-/Dispatch-Änderungen zentraler und bleibt bei späteren Auskunfts- und Löschaktionen konsistenter wartbar. |

### Delta Batch 120

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/cookie-manager.php` | umgesetzt | Wiederkehrende Redirect-, Session-Alert- und Aktionsauflösung für Save-, Delete-, Import- und Scan-Aktionen über kleine lokale Helper gebündelt statt mehrfacher identischer POST-Orchestrierung im Entry-Flow. | Der Cookie-Manager-Entry reduziert weiteres Entry-Duplikat im DSGVO-POST-Pfad, hält Redirect-/Alert-Änderungen zentraler und bleibt bei späteren Cookie-Aktionen konsistenter wartbar. |

### Delta Batch 119

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/comments.php` | umgesetzt | Wiederkehrenden Session-Alert-Aufbau für unbekannte Aktion, ungültiges Token und Aktionsresultate über kleine lokale Helper gebündelt statt mehrfacher identischer Alert-Arrays im POST-Flow. | Der Comments-Entry reduziert weiteres Entry-Duplikat im Kommentar-POST-Pfad, hält Alert-Änderungen zentraler und bleibt bei späteren Kommentaraktionen konsistenter wartbar. |

### Delta Batch 118

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/packages.php` | umgesetzt | Wiederkehrende Flash- und Redirect-Orchestrierung für Save-, Seed-, Delete-, Toggle- und Package-Settings-Aktionen über kleine lokale Helper gebündelt statt mehrfacher identischer Session-/Redirect-Blöcke im POST-Flow. | Der Packages-Entry reduziert weiteres Entry-Duplikat im POST-Pfad, hält Alert- und Redirect-Änderungen zentraler und bleibt bei späteren Paketaktionen konsistenter wartbar. |

### Delta Batch 117

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/orders.php` | umgesetzt | Wiederkehrende Aktionsauflösung für `assign_subscription`, `update_status` und `delete` über kleinen lokalen Dispatch-Helper gebündelt statt gestaffelter Inline-Verzweigung mit wiederholtem Erfolgsablauf. | Der Orders-Entry reduziert weiteres Entry-Duplikat im POST-Flow, hält Aktionsänderungen zentraler und bleibt bei späteren Bestellaktionen konsistenter wartbar. |

### Delta Batch 116

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/mail-settings.php` | umgesetzt | Wiederkehrende Submit-Button-Markup-Struktur über kleinen lokalen Renderer gebündelt statt mehrfacher identischer `button type="submit" name="action" ...`-Blöcke in Transport-, Azure-, Graph-, Log- und Queue-Bereichen. | Die Mail-Settings-View reduziert weiteres Template-Duplikat in Aktionsbereichen, hält Button-Änderungen zentraler und bleibt bei späteren Mail-Aktions-Anpassungen konsistenter wartbar. |

### Delta Batch 115

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/documentation.php` | umgesetzt | Wiederkehrende Dokumentlisten-Schleifen für Schnellstart und Bereichslisten über kleinen lokalen Renderer gebündelt statt mehrfacher identischer `foreach`-Blöcke mit demselben Array-Guard. | Die Dokumentations-View reduziert weiteres Template-Duplikat in Listenbereichen, hält Listenänderungen zentraler und bleibt bei späteren Dokumentkarten-Anpassungen konsistenter wartbar. |

### Delta Batch 114

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncService.php` | umgesetzt | Wiederkehrenden Sync-Ausführungs-Kontext für Erfolgs- und Fehlerlogging über kleinen lokalen Helper gebündelt statt mehrfacher identischer `mode`-/`capabilities`-Metadaten in den Abschluss-Pfaden. | Der Doku-Sync-Service reduziert weiteres Orchestrator-Duplikat im Abschluss-Logging, hält Kontextänderungen zentraler und bleibt bei Sync-Erfolg und Sync-Fehlern konsistenter wartbar. |

### Delta Batch 113

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncDownloader.php` | umgesetzt | Wiederkehrenden URL-bezogenen Download-Log-Kontext über kleinen lokalen Helper gebündelt statt mehrfacher identischer URL-Metadaten in Response-, Validierungs- und Erfolgslogs. | Der Dokumentations-Downloader reduziert weiteres Infrastruktur-Duplikat im Logging, hält URL-bezogene Kontextänderungen zentraler und bleibt bei Download- und Validierungspfaden konsistenter wartbar. |

### Delta Batch 112

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` | umgesetzt | Wiederkehrende Save-Orchestrierung für Admin-Guard, Persistierung und Audit-Logging über kleine lokale Helfer gebündelt statt doppelter Erfolgsabläufe in allgemeinen und paketbezogenen Speicherpfaden. | Das Subscription-Settings-Modul reduziert weiteres Orchestrator-Duplikat in Einstellungs-Updates, hält Persistier- und Audit-Änderungen zentraler und bleibt bei späteren Save-Anpassungen konsistenter wartbar. |

### Delta Batch 111

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/subscriptions/orders.php` | umgesetzt | Wiederkehrende Hidden-Felder für `csrf_token`, `action` und optionale `id`-Werte über kleinen lokalen Renderer gebündelt statt mehrfacher identischer Formular-Kontext-Blöcke in Statuswechsel-, Delete- und Zuweisungsformularen. | Die Orders-View reduziert weiteres Template-Duplikat in Mutationsformularen, hält Formular-Kontexte zentraler und bleibt bei späteren Bestellaktions-Anpassungen konsistenter wartbar. |

### Delta Batch 110

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/subscriptions/OrdersModule.php` | umgesetzt | Wiederkehrende Mutations-Vorbedingungen und Audit-Kontexte für Status- und Delete-Pfade über kleine lokale Helfer gebündelt statt doppelter Guard- und Kontextlogik in beiden Methoden. | Das Orders-Modul reduziert weiteres Orchestrator-Duplikat in Bestellmutationen, hält Guard- und Audit-Änderungen zentraler und bleibt bei Status- und Delete-Anpassungen konsistenter wartbar. |

### Delta Batch 109

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/mail-settings.php` | umgesetzt | Wiederkehrende Hidden-Felder für `csrf_token` und `tab` über kleinen lokalen Renderer gebündelt statt mehrfacher identischer Formular-Kontext-Blöcke in Transport-, Azure-, Graph-, Log- und Queue-Formularen. | Die Mail-Settings-View reduziert weiteres Template-Duplikat in Formular-Kontexten, hält CSRF-/Tab-Änderungen zentraler und bleibt bei späteren Formularanpassungen konsistenter wartbar. |

### Delta Batch 108

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/settings/SettingsModule.php` | umgesetzt | Generierte `config/app.php` vor atomarem Schreiben auf erwartete Kernfragmente und gültige PHP-Syntax validiert, damit fehlerhafte Settings-Schreibläufe keine kaputte Runtime-Konfiguration live schalten. | Das Settings-Modul reduziert das Risiko globaler 500-Fehler nach Permalink- oder Allgemein-Änderungen, hält den Config-Writer näher an der Installer-Vorlage und bricht fehlerhafte Generierungen kontrolliert vor dem Live-Swap ab. |

### Delta Batch 107

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncDownloader.php` | umgesetzt | Wiederkehrende Response-Failure-Pfade für Download- und Persistenzfehler über kleinen Helfer gebündelt statt separater Cleanup-/Failure-Abgänge mit ähnlichem Aufbau. | Der Dokumentations-Downloader reduziert weiteres Lifecycle-Duplikat in Failure-Pfaden, hält Cleanup und Result-Erzeugung zentraler und bleibt bei Remote- und Persistenzfehlern konsistenter wartbar. |

### Delta Batch 106

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncService.php` | umgesetzt | Wiederkehrende Capability-Fehlerpfade für unavailable- und invalid-capabilities über kleinen Helfer gebündelt statt separater Failure-Aufrufe mit identischem Kontextaufbau. | Der Doku-Sync-Service reduziert weiteres Orchestrator-Duplikat in Capability-Fehlern, hält Failure-Kontexte zentraler und bleibt bei Konfigurationsabweichungen konsistenter wartbar. |

### Delta Batch 105

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/mail-settings.php` | umgesetzt | Wiederkehrende KPI-Kartenzeilen für Logs und Queue über kleinen lokalen Renderer gebündelt statt zweier fast identischer Kartenreihen direkt im Template. | Die Mail-Settings-View reduziert weiteres Template-Duplikat in Metrikbereichen, hält KPI-Änderungen zentraler und bleibt in Logs- und Queue-Tabs konsistenter wartbar. |

### Delta Batch 104

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationModule.php` | umgesetzt | Wiederkehrende Lese-Vorbedingungen für Zugriff, Repository-Layout und DOC-Verfügbarkeit über kleinen Guard gebündelt statt gestaffelter Inline-Fehlerpfade direkt im Read-Orchestrator. | Das Dokumentations-Modul reduziert weitere Orchestrator-Duplikate im Lese-Pfad, hält Vorbedingungen zentraler und bleibt bei View-Fehlern konsistenter wartbar. |

### Delta Batch 103

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/documentation.php` | umgesetzt | Gewachsenen Inhalt der ausgewählten Dokumentenkarte über kleinen lokalen Renderer gebündelt statt Excerpt, Quellenhinweis, Leerzustand und CSV-Hinweis direkt im Hauptlayout zu halten. | Die Dokumentations-Ansicht reduziert weiteres Panel-Markup-Duplikat, hält Zustandsänderungen zentraler und bleibt in der rechten Dokumentenkarte kompakter wartbar. |

### Delta Batch 102

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/subscriptions/orders.php` | umgesetzt | Gewachsenes Bestell-Aktionsmenü über kleinen lokalen Renderer gebündelt statt Statuswechsel, Zuweisung und Löschaktion direkt im Tabellen-Loop zu halten. | Die Bestellübersicht reduziert weiteres View-Markup-Duplikat, hält Aktionsanpassungen zentraler und bleibt in der Tabellenzeile kompakter wartbar. |

### Delta Batch 101

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncDownloader.php` | umgesetzt | Wiederkehrende Persistenz-Fehlerpfade für Schreib- und Hash-Fehler über kleinen Helfer gebündelt statt ähnlicher Cleanup- und Failure-Blöcke direkt im Archivpfad. | Der Dokumentations-Downloader reduziert weitere Lifecycle-Duplikate in Persistenz-Fehlern, hält Archiv-Änderungen zentraler und bleibt bei Schreib- und Hash-Fehlern konsistenter wartbar. |

### Delta Batch 100

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncService.php` | umgesetzt | Wiederkehrende Capability-Verzweigungen für Verfügbarkeit, Git- und GitHub-ZIP-Sync über kleinen Helfer gebündelt statt gestaffelter Inline-Auswahl direkt im Orchestrator-Einstieg. | Der Doku-Sync-Service reduziert weitere Orchestrator-Duplikate in der Sync-Auswahl, hält Capability-Änderungen zentraler und bleibt bei Moduspfaden konsistenter wartbar. |

### Delta Batch 099

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/mail-settings.php` | umgesetzt | Wiederkehrende Status-Kartenkopf-Strukturen für Azure- und Graph-Konfiguration über kleinen lokalen Renderer gebündelt statt zweier leicht variierter Header-Blöcke mit Status-Badge. | Die Mail-Settings-View reduziert weitere Template-Duplikate in Konfigurationskarten, hält Header-Änderungen zentraler und bleibt bei Statusdarstellung konsistenter wartbar. |

### Delta Batch 098

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationModule.php` | umgesetzt | Wiederkehrende Sync-Vorbedingungen für Zugriff und Repository-Layout über kleinen Guard-Helfer gebündelt statt leicht doppelter Failure-Pfade direkt im Sync-Einstieg. | Das Dokumentations-Modul reduziert weitere Orchestrator-Duplikate im Sync-Start, hält Guard-Änderungen zentraler und bleibt bei Zugriffs- und Layout-Fehlern konsistenter wartbar. |

### Delta Batch 097

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/documentation.php` | umgesetzt | Wiederkehrende Accordion-Strukturen für Dokumentationsbereiche über kleinen lokalen Renderer gebündelt statt mehrfacher leicht variierter Header-, Collapse- und Dokumentlisten-Blöcke im Bereichs-Markup. | Die Doku-View reduziert weitere Template-Duplikate in Bereichs-Accordions, hält Strukturänderungen zentraler und bleibt bei Dokumentlisten konsistenter wartbar. |

### Delta Batch 096

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/subscriptions/orders.php` | umgesetzt | Wiederkehrende Statuswechsel- und Delete-Formulare für Order-Aktionen über kleine lokale Renderer gebündelt statt mehrfacher leicht variierter Hidden-Field-Strukturen im Dropdown- und Delete-Pfad. | Die Orders-View reduziert weitere Template-Duplikate in Aktionspfaden, hält Formularänderungen zentraler und bleibt bei Mutations-Markup konsistenter wartbar. |

### Delta Batch 095

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncDownloader.php` | umgesetzt | Wiederkehrende Response-basierte Failure-Result-Erzeugung für Download-, Persistenz- und Validierungsfehler über kleinen Builder gebündelt statt mehrfacher leicht variierter `failureResult(...)`-Aufrufe mit Status-/Content-Type-/Bytes-Kombinationen. | Der Dokumentations-Downloader reduziert weitere Lifecycle-Duplikate in Fehlerpfaden, hält Result-Anpassungen zentraler und bleibt bei Download- und Persistenzfehlern konsistenter wartbar. |

### Delta Batch 094

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncService.php` | umgesetzt | Wiederkehrende Konfigurations-Failure-Arrays für Repo-, DOC-, Git- und Integritätsprüfung über kleinen Builder gebündelt statt mehrfacher leicht variierter Inline-Arrays in der Validierung. | Der Doku-Sync-Service reduziert weitere Orchestrator-Duplikate im Konfigurationspfad, hält Fehlermeldungen zentraler und bleibt bei Validierungsfehlern konsistenter wartbar. |

### Delta Batch 093

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/mail-settings.php` | umgesetzt | Wiederkehrende einfache Kartenkopf-Strukturen für Transport-, Runtime-, Queue- und Worker-Karten über kleinen lokalen Renderer gebündelt statt mehrfacher identischer Header-Blöcke. | Die Mail-Settings-View reduziert weitere Template-Duplikate im Kartenaufbau, hält Überschriften konsistenter und vereinfacht künftige Änderungen an einfachen Header-Titeln. |

### Delta Batch 092

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationModule.php` | umgesetzt | Wiederkehrende Repository-Layout-Warnings für ungültigen Repo-Root, CMS-Layout und DOC-Pfad über kleinen Hilfsweg gebündelt statt mehrfacher leicht variierter Warning-Blöcke in der Layout-Prüfung. | Das Dokumentations-Modul reduziert weitere Orchestrator-Duplikate in der Konfigurationsvalidierung, hält Logging-Änderungen zentraler und bleibt in Layout-Fehlerpfaden konsistenter wartbar. |

### Delta Batch 091

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/documentation.php` | umgesetzt | Wiederkehrende Kartenkopf-Strukturen für Schnellstart-, Bereichs- und Dokumentkarten über kleinen lokalen Renderer gebündelt statt mehrfacher leicht variierter Header-Blöcke. | Die Doku-View reduziert weitere Template-Duplikate im Kartenaufbau, hält Überschriften konsistenter und vereinfacht künftige Anpassungen an Titel- oder Untertitelstrukturen. |

### Delta Batch 090

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/subscriptions/orders.php` | umgesetzt | Wiederkehrende Select-Feld-Blöcke für Benutzer-, Paket- und Intervallauswahl im Zuweisungsmodal über kleinen lokalen Renderer und vorbereitete Optionslisten gebündelt statt dreier leicht variierter Inline-Blöcke. | Die Orders-View reduziert weitere Template-Duplikate im Assignment-Modal, hält Formularfelder konsistenter und vereinfacht künftige Änderungen an Auswahlfeldern oder Beschriftungen. |

### Delta Batch 089

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncDownloader.php` | umgesetzt | Wiederkehrende Audit- und Channel-Logs für Download-Erfolgs- und Fehlerpfade über kleinen gemeinsamen Hilfsweg gebündelt statt zweier fast identischer Logging-Methoden mit dupliziertem Logger-/Audit-Aufbau. | Der Dokumentations-Downloader reduziert weitere Lifecycle-Duplikate, hält Logging-Änderungen zentraler und bleibt bei Remote-, Validierungs- und Persistenzpfaden konsistenter wartbar. |

### Delta Batch 088

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncService.php` | umgesetzt | Wiederkehrende Audit- und Channel-Logs für Erfolgs- und Fehlerpfade über kleinen gemeinsamen Hilfsweg gebündelt statt zweier fast identischer Logging-Methoden mit dupliziertem Logger-/Audit-Aufbau. | Der Doku-Sync-Service reduziert weitere Orchestrator-Duplikate, hält Logging-Änderungen zentraler und bleibt bei Erfolgs- und Fehlerpfaden konsistenter wartbar. |

### Delta Batch 087

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/mail-settings.php` | umgesetzt | Wiederkehrende Secret-Statushinweise und Lösch-Checkboxen für Transport-, Azure- und Graph-Secrets über kleinen lokalen Renderer gebündelt statt dreifacher leicht variierter Inline-Blöcke. | Die Mail-Settings-View reduziert weitere Template-Duplikate, hält Secret-Statusdarstellung konsistenter und vereinfacht künftige Änderungen an Formulartexten oder Reset-Optionen. |

### Delta Batch 086

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationModule.php` | umgesetzt | Wiederkehrende Throwable-Warnings und Default-Payloads für ausgewählte Dokumente über kleine Hilfsmethoden gebündelt statt mehrfacher leicht variierter Inline-Blöcke im Orchestrator. | Das Dokumentations-Modul bleibt näher an seinen Read-/Write-Verträgen, reduziert weitere Orchestrator-Duplikate in Lade- und Sync-Pfaden und lässt sich für die nächsten Service-/Result-Schritte leichter lesen und nachziehen. |

### Delta Batch 085

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/documentation.php` | umgesetzt | Wiederkehrende Alert-Blöcke, Bereichs-Einleitungen und der Quellhinweis über kleine Render-Helfer und vorbereitete Texte gebündelt statt mehrfacher leicht variierter Inline-Blöcke im Template. | Die Doku-View bleibt näher am Rendern, reduziert weitere Template-Duplikate in Sync-, Fehler- und Info-Bereichen und lässt sich für die nächsten Partial-/Builder-Schritte leichter lesen und nachziehen. |

### Delta Batch 084

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/subscriptions/orders.php` | umgesetzt | Wiederkehrende Status-Badges, Primär-/Sekundärtextblöcke und Billing-Cycle-Optionen über kleine Render-Helfer und vorbereitete Listen gebündelt statt mehrfacher leicht variierter Inline-Blöcke im Tabellen- und Modal-Markup. | Die Orders-View bleibt näher am Rendern, reduziert weitere UI-Duplikate in Tabellen- und Modalpfaden und lässt sich für die nächsten Partial-/Builder-Schritte leichter lesen und nachziehen. |

### Delta Batch 083

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncDownloader.php` | umgesetzt | Failure-Resultate über kleinen Helper gebündelt statt Download-, Persistenz- und Validierungsfehler mehrfach separat direkt in Result-Objekte zu überführen. | Der Downloader trägt weniger verstreute Result-Erzeugung im Fehlerpfad, hält Remote- und Filesystem-Fehler klarer zusammen und lässt sich für weitere Payload-/Lifecycle-Schritte leichter lesen und nachziehen. |

### Delta Batch 082

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncService.php` | umgesetzt | ServiceResult-Erzeugung und Failure-Fallbacks über kleine Helper gebündelt statt Konfigurations- und Finalize-Pfade mehrfach lose Arrays direkt in Result-Objekte zu überführen. | Der Doku-Sync-Orchestrator trägt weniger doppelte Result-Konvertierung, hält Fehlerpfade klarer zusammen und lässt sich für weitere Objekt-/Result-Aufspaltungen leichter lesen und nachziehen. |

## Bewertungsmatrix (letzter Vollstand)

| Datei | Bereich | Fokus | Security | Speed | PHP/BP | Gesamt |
|---|---|---|---:|---:|---:|---:|
| `CMS/admin/views/subscriptions/orders.php` | Orders, Assignments, Modal | Tabellen, Status, Zuweisung | 91 | 82 | 93 | 89 |
| `CMS/admin/views/system/documentation.php` | Doku-UI | Karten, Listen, Renderpfad | 91 | 82 | 93 | 89 |
| `CMS/admin/views/system/mail-settings.php` | Transport, Azure, Graph, Queue | SMTP, OAuth2, Logs | 92 | 83 | 94 | 90 |
| `CMS/admin/modules/system/DocumentationSyncService.php` | Doku-Sync | Capabilities, Result, Logging | 92 | 83 | 94 | 90 |
| `CMS/admin/modules/system/DocumentationSyncDownloader.php` | Doku-Download | Remote, Persistenz, Logging | 93 | 83 | 94 | 91 |
| `CMS/admin/modules/system/DocumentationModule.php` | Doku-Logik | Renderer, Sync, Catalog | 92 | 82 | 93 | 89 |

### Delta Batch 081

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/mail-settings.php` | umgesetzt | Wiederkehrende Status-Badges, leere Tabellenzeilen und seitliche Hinweis-Karten über kleine Render-Helfer gebündelt statt mehrfacher Inline-Blöcke im Logs-, Queue- und OAuth2-Markup. | Die Mail-View bleibt näher am Rendern, reduziert wiederholte Badge-, Empty-State- und Sidebar-Struktur und lässt sich für weitere Partial-/Builder-Schritte leichter lesen und nachziehen. |

### Delta Batch 080

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationModule.php` | umgesetzt | Ausgewählte Dokument-Payloads und Sync-Resultate über fokussierte Hilfsmethoden gebündelt statt Pfad-, Render-, Sanitizing- und Failure-Logik mehrfach inline im Modul zu mischen. | Der Doku-Orchestrator trägt weniger Inline-Lifecycle, hält Read-/Write-Helfer klarer getrennt und lässt sich für weitere Service-Aufspaltungen leichter lesen und nachziehen. |

### Delta Batch 079

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/documentation.php` | umgesetzt | Wiederkehrende KPI-Karten sowie Schnellstart- und Bereichs-Dokumentlinks über kleine Render-Helfer und vorbereitete Kartenlisten gebündelt statt mehrfacher Inline-Blöcke im Markup. | Die Doku-View bleibt näher am Rendern, reduziert wiederholte Card- und Listenstruktur und lässt sich für weitere Partial-/Builder-Schritte leichter lesen und nachziehen. |

### Delta Batch 078

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/subscriptions/orders.php` | umgesetzt | Wiederkehrende KPI-Karten, Leerzustände, Statuswechsel und Assignment-Felder über kleine Template-Helfer und vorbereitete Datenlisten gebündelt statt mehrfacher Inline-Blöcke im Markup. | Die Orders-View bleibt näher am Rendern, reduziert wiederholte Tabellen- und Dropdown-Struktur und lässt sich für weitere Partial-/Builder-Schritte leichter lesen und nachziehen. |

### Delta Batch 077

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncDownloader.php` | umgesetzt | Download-Resultate über benannte Erfolgs-/Fehlerfabriken vereinheitlicht und validierte ZIP-Antworten über kleines Payload-DTO statt lose Body-/Content-Type-Arrays geführt. | Der Downloader hält Validierung, Persistenz und Fehlerpfade expliziter getrennt, reduziert lose Parameterketten im Lifecycle und lässt sich für weitere Remote-/Filesystem-Schritte klarer lesen und nachziehen. |

### Delta Batch 076

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncService.php` | umgesetzt | Capability-Abfragen und Log-Kontexte stärker am Objektvertrag ausgerichtet statt frühem Zurückfallen auf lose Array-Schlüssel im Orchestrator. | Der Doku-Sync-Orchestrator hält Capability-, Dispatch- und Logging-Grenzen expliziter zusammen und lässt sich für weitere Service-Zerlegung klarer lesen und nachziehen. |

### Delta Batch 075

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/mail-settings.php` | umgesetzt | Wiederkehrende KPI-Karten, Readonly-Felder und den Queue-Last-Run-Text über kleine Template-Helfer und vorbereitete Datenlisten gebündelt statt mehrfacher Inline-Blöcke im Markup. | Die Mail-View bleibt näher am Rendern, reduziert wiederholte UI-Struktur und lässt sich für weitere Partial-/Builder-Schritte leichter lesen und nachziehen. |

### Delta Batch 074

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/mail-settings.php` | umgesetzt | POST-Dispatch über zentrale Action-Map geführt und Session-Alerts über kleinen Pull-Helfer vereinheitlicht. | Der Mail-Entry bleibt näher am eigentlichen Request-Flow, reduziert verstreute Dispatch-/Session-Logik und lässt sich für weitere Wrapper-Anpassungen klarer lesen und nachziehen. |

### Delta Batch 073

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/documentation.php` | umgesetzt | Wiederkehrende Dokument- und Bereichsmetadaten über kleine Template-Helfer gebündelt statt mehrfacher Inline-Ableitungen für Links, Titel, Pfade und Counts. | Die Doku-View bleibt näher am Rendern, reduziert verstreute Metadatenlogik und lässt sich für weitere Partial-/Builder-Schritte leichter lesen und nachziehen. |

### Delta Batch 072

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/subscriptions/orders.php` | umgesetzt | Wiederkehrende Filter-, Bestell-, Kunden- und Select-Label-Logik über kleine Template-Helfer gebündelt statt mehrfacher Inline-Zusammensetzung im Markup. | Die Orders-View bleibt näher am Rendern, reduziert verstreute Anzeigeentscheidungen und lässt sich für weitere Modal-/Partial-Schritte leichter lesen und nachziehen. |

### Delta Batch 071

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncDownloader.php` | umgesetzt | Download-Resultat stärker gekapselt, Fehlpfade zentralisiert und Payload-/Persistenz-Lifecycle in kleine Helfer zerlegt. | Der Downloader trägt weniger Inline-Lifecycle, blockt Fehler- und Cleanup-Pfade konsistenter und liefert Metadaten an den ZIP-Sync über einen expliziteren Vertrag statt über lose Public-Properties. |
| `CMS/admin/modules/system/DocumentationGithubZipSync.php` | umgesetzt | Konsum des Downloader-Resultats an den expliziteren Result-Vertrag angepasst. | Der ZIP-Sync kennt weniger interne Downloader-Details und bleibt klarer auf Archivprüfung und Aktivierung fokussiert. |

### Delta Batch 070

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncService.php` | umgesetzt | Sync-Resultat über kleines Service-DTO geführt, normalisierte Capabilities als Objekt beibehalten und Konfig-/Fail-/Finalize-Pfade an die schärferen Verträge angepasst. | Der Doku-Sync-Orchestrator trägt weniger losen Array-Mix, hält Lock-/Capability-/Result-Grenzen expliziter und bleibt für weitere Zerlegung in Downloader-/Git-/ZIP-Pfade leichter wartbar. |
| `CMS/admin/modules/system/DocumentationModule.php` | umgesetzt | Konsum des Sync-Services an die neuen Objektverträge angepasst. | Der Modul-Layer kennt weniger interne Service-Array-Details und bleibt klarer auf View-/Action-Verträge fokussiert. |

### Delta Batch 069

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/mail-settings.php` | umgesetzt | Wiederkehrende Tab-, Select-, Checkbox- und Status-Badge-Logik über kleine Template-Helfer gebündelt statt mehrfacher Inline-Bedingungen im Markup. | Die Mail-View bleibt näher am Rendern, reduziert verstreute UI-Zustände und lässt sich für weitere Formular-/Partial-Schritte leichter lesen und nachziehen. |

### Delta Batch 068

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationModule.php` | umgesetzt | View-DTO und Sync-Result ergänzt, aktives Dokument in fokussierten Payload-Builder ausgelagert und Sync-Sanitizing/Fehlerpfade über kleine Helfer vereinheitlicht. | Der Doku-Orchestrator trägt weniger losen Array-Mix, hält Entry- und Modulgrenzen expliziter und bleibt für weitere Service-Aufspaltungen leichter beherrschbar. |
| `CMS/admin/documentation.php` | umgesetzt | Entry an den neuen DTO-/Result-Vertrag des Moduls angepasst. | Wrapper-Logik bleibt schlanker und muss keine losen `success`-/`message`-/`error`-Felder mehr aus beliebigen Modul-Arrays erraten. |

### Delta Batch 067

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/system/documentation.php` | umgesetzt | Alert-Kontext und wiederkehrende Aktiv-/Sync-Zustände in kleine Template-Helfer bzw. vorbereitete Werte gezogen statt mehrfacher Inline-Bedingungen im Markup. | Die Doku-View bleibt näher am Rendern, reduziert verstreute Zustandslogik und lässt sich für weitere UI-Anpassungen klarer lesen. |

### Delta Batch 066

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/subscriptions/settings.php` | umgesetzt | Checkbox-/Select-Logik und Alert-Kontext in kleine Template-Helfer bzw. vorbereitete Werte gezogen statt mehrfacher Inline-Bedingungen im Markup. | Die View bleibt näher am Rendern, reduziert wiederholte Zustandslogik und lässt sich bei weiteren UI-Anpassungen einfacher lesen. |

### Delta Batch 065

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationGithubZipSync.php` | umgesetzt | Archiv-/Staging-Helfer weiter extrahiert, Arbeitsverzeichnisse in kleinen Workspace-Vertrag gezogen und den ZIP-Sync-Lifecycle in fokussierte Methoden zerlegt. | Der ZIP-Sync trägt weniger Top-Level-Zustand, lässt sich gezielter weiter härten und hält Setup, Entpacken, Aktivierung und Cleanup klarer getrennt. |

### Delta Batch 064

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/MailSettingsModule.php` | umgesetzt | View-DTO und Action-Result ergänzt, Datenaufbau für Transport/Azure/Graph in fokussierte Builder zerlegt und wiederkehrende Erfolgs-/Fehlerantworten vereinheitlicht. | Das Modul trägt weniger losen Array-Mix, hält Read-/Write-Kontrakte klarer getrennt und bietet eine stabilere Basis für weitere Service-Extraktion im Mail-Admin. |
| `CMS/admin/mail-settings.php` | umgesetzt | Flash-Handling und Datenübergabe an den neuen Mail-Result-/View-Vertrag angepasst. | Der Entry bleibt schlanker und muss keine losen `success`-/`message`-/`error`-Felder aus beliebigen Modul-Arrays mehr erraten. |

### Delta Batch 063

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncEnvironment.php` | umgesetzt | Capability- und Shell-Ergebnisse in kleine Objekte gezogen statt losem Array-Mix. | Die Environment-Schicht kapselt Git-/ZIP-Fähigkeiten und Kommandoausgaben klarer und lässt sich gezielter weiter aufspalten. |
| `CMS/admin/modules/system/DocumentationGitSync.php` | umgesetzt | Git-Sync auf den neuen Shell-Result-Vertrag des Environments umgestellt. | Der Git-Sync kennt weniger implizite Command-Array-Details und hängt enger am expliziten Shell-Layer. |
| `CMS/admin/modules/system/DocumentationSyncService.php` | umgesetzt | Capability-Konsum auf den neuen Environment-Vertrag angepasst. | Service- und Environment-Schicht bleiben klarer getrennt, ohne am UI-Vertrag etwas umzubauen. |

### Delta Batch 062

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` | umgesetzt | View-DTO und Action-Result ergänzt, General-/Package-Settings in fokussierte Payload-Helfer zerlegt und Listen-/ID-Ladepfade vereinheitlicht. | Das Modul trägt weniger losen Array-Mix, hält Save-/Read-Kontrakte klarer getrennt und bietet eine sauberere Basis für weitere Service- oder ViewModel-Extraktion. |
| `CMS/admin/subscription-settings.php` | umgesetzt | Flash-Handling an den neuen Result-Vertrag des Moduls angeglichen. | Der Entry bleibt schlanker und leitet Save-Ergebnisse konsistenter in den gemeinsamen Alert-Flow. |
| `CMS/admin/packages.php` | umgesetzt | Save-Package-Settings-Flow und Settings-Datenübergabe an den DTO-/Result-Vertrag angepasst. | Paket- und Abo-Settings bleiben kompatibel, ohne dass der Entry lose Annahmen über interne Modul-Arrays treffen muss. |

### Delta Batch 061

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/subscriptions/OrdersModule.php` | umgesetzt | Dashboard-Daten über kleines DTO gebündelt, Mutationen auf Action-Result umgestellt und Listen-/Stats-Ladevorgänge in fokussierte Helfer zerlegt; Fehlerpfade zusätzlich mit zentralem Log-/Audit-Result vereinheitlicht. | Das Orders-Modul trägt weniger losen Array-Mix, hält Read-/Write-Kontrakte klarer getrennt und lässt sich beim nächsten Schritt gezielter in weitere Services oder ViewModels zerlegen. |

### Delta Batch 060

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/views/subscriptions/orders.php` | umgesetzt | Gemeinsamen Flash-Alert-Partial eingebunden, Status-/Datums-/Betrags-Helfer lokal gebündelt und Inline-`onclick`-Fragmente auf datengetriebene Aktionen mit zentralem Script umgestellt. | Die Orders-View folgt dem Admin-UI-Standard enger, reduziert Template-Duplikate und hält Modal-/Löschaktionen wartbarer, ohne pro Zeile eigene Inline-Skriptfragmente mitzuschleppen. |

### Delta Batch 059

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncDownloader.php` | umgesetzt | Downloader-Policies über kleines Result-DTO gebündelt; Content-Type-/Content-Length-Konsistenz und SHA-256-Metadaten im Downloadpfad ergänzt. | Der Downloader verlässt sich nicht mehr nur auf Body-Magic und Dateigröße, sondern liefert kontrollierte Metadaten für Folgeschichten und blockt inkonsistente ZIP-Antworten früher. |
| `CMS/admin/modules/system/DocumentationGithubZipSync.php` | umgesetzt | Heruntergeladene ZIP-Datei gegen Größe und Hash des Downloader-Ergebnisses validiert statt losem Datei-Nachraten. | Der ZIP-Sync erkennt inkonsistente Download-Artefakte früher und entkoppelt Validierungslogik sauberer zwischen Download- und Entpackpfad. |

### Delta Batch 058

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/mail-settings.php` | umgesetzt | Entry-POST-Dispatch über kleine Allowlist-/Redirect-/Flash-Helfer vereinheitlicht; Tabs werden serverseitig normalisiert und Session-Alerts nur noch defensiv übernommen. | Der Mail-Entry verarbeitet keine losen Mutationen mehr, hält CSRF-/Aktionsfehler enger am gemeinsamen Admin-Standard und spiegelt weniger rohe Session-/Query-Werte in den Flow zurück. |
| `CMS/admin/views/system/mail-settings.php` | umgesetzt | Gemeinsamen Flash-Alert-Partial eingebunden, Tab- und API-Konstanten lokal gebündelt und Queue-Status-Badge über kleinen Helper vereinheitlicht. | Die Mail-View reduziert eigenes Inline-Sonderverhalten, folgt dem UI-Standard enger und bleibt bei Alert-/Status-Ausgabe konsistenter und wartbarer. |

### Delta Batch 001

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/analytics.php` | geprüft | Entry-Point-Standardisierung verifiziert: die Datei delegiert bereits schlank an `seo-page.php`; der operative Audit-Hotspot liegt aktuell eher in `SeoSuiteModule` als im Entry selbst. | Kein Sofortfix am Entry nötig; Bewertungsfokus verschiebt sich auf Modul-/Service-Ebene. |
| `CMS/admin/antispam.php` | umgesetzt | POST-Actions nur noch über Allowlist, konsistenter Redirect-/Flash-Flow für CSRF- und Aktionsfehler. | Security- und PHP/BP-Risiko im Entry-Flow gesenkt. |
| `CMS/admin/modules/security/AntispamModule.php` | umgesetzt | Eingaben bereinigt, Secrets werden bei leerem Feld erhalten, rohe Exception-Texte nicht mehr an die UI geleakt, fehlgeschlagene Mutationen auditierbar behandelt. | Security und Fehlerhärte verbessert; Secret-Handling und Mutationspfade robuster. |
| `CMS/admin/views/security/antispam.php` | umgesetzt | Gemeinsame Flash-Alerts eingebunden, reCAPTCHA-Secret nicht mehr vorbelegt, Inline-`confirm()` entfernt. | UI folgt dem gemeinsamen Admin-Muster; XSS-/Secret-/Inline-JS-Risiko reduziert. |
| `CMS/admin/backups.php` | verifiziert | Vorheriger Entry-Fix bestätigt: `CMS_ADMIN_SYSTEM_VIEW`-Guard und gemeinsamer Flash-Partial sind aktiv. | Leere Backup-Seite bleibt behoben; nächster Fokus liegt auf Modul/Service statt Entry-Page. |

### Delta Batch 002

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/BackupsModule.php` | umgesetzt | UI-Fehlerpfade leaken keine rohen Exception-Texte mehr. | Admin-Feedback bleibt verständlich, ohne interne Details preiszugeben. |
| `CMS/core/Services/BackupService.php` | umgesetzt | Backup-Namen und Zielpfade werden validiert, Backup-Root wird systematisch abgesichert, Manifest-/Lösch-/Mail-/ZIP-Pfade defensiver behandelt. | Security-, I/O- und Fehlertoleranz im eigentlichen Backup-Hotpath spürbar verbessert. |

### Delta Batch 003

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-editor.php` | geprüft | Aktuelle Rolle verifiziert: Der Entry bettet primär den Theme-Customizer ein; der operative Dateieditor liegt heute im separaten `theme-explorer`-Pfad. | Risikofokus fachlich präziser eingeordnet; kritischer Dateieditor nicht mehr am falschen Entry gesucht. |
| `CMS/admin/theme-explorer.php` | umgesetzt | POST-Aktionsfläche reduziert, Redirect-/Flash-Flow vereinheitlicht. | Security- und PHP/BP-Risiko im Entry-Flow gesenkt. |
| `CMS/admin/modules/themes/ThemeEditorModule.php` | umgesetzt | Pfadnormalisierung, Pattern-Gates, Root-Enforcement, Größenlimit, Symlink-Skip und `LOCK_EX` beim Schreiben. | Traversal-, Oversize- und unsichere Schreibpfade deutlich reduziert. |
| `CMS/admin/views/themes/editor.php` | umgesetzt | Gemeinsame Flash-Alerts und kontrollierte Dateiwarnungen statt losem Sonder-Alert-Markup. | View folgt stärker dem Admin-Standard und bleibt im Fehlerfall sauberer. |

### Delta Batch 004

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/plugin-marketplace.php` | umgesetzt | Entry-Point auf konsistenten `install`-Only-Flow mit Redirect-/Flash-Behandlung gebracht. | Wrapper folgt jetzt derselben Sicherheits- und UX-Linie wie andere Admin-Mutationen. |
| `CMS/admin/theme-marketplace.php` | umgesetzt | Entry-Point auf konsistenten `install`-Only-Flow mit Redirect-/Flash-Behandlung gebracht. | CSRF-/Aktionsfehler werden sauber und einheitlich behandelt. |
| `CMS/admin/modules/plugins/PluginMarketplaceModule.php` | verifiziert | HTTPS-/Host-Allowlist, SHA-256-Prüfung und ZIP-Validierung bereits aktiv. | Audit-Restdruck verschiebt sich vom groben Supply-Chain-Fund auf Detail- und Wrapper-Ebene. |
| `CMS/admin/modules/themes/ThemeMarketplaceModule.php` | verifiziert | HTTPS-/Host-Allowlist, SHA-256-Prüfung, ZIP-Validierung und Manual-only-Handling bereits aktiv. | Der ursprüngliche Kritikalitätsstand ist im Modul selbst fachlich teilweise überholt. |

### Delta Batch 005

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/seo/SeoSuiteModule.php` | umgesetzt | Indexing-URLs werden hostgebunden validiert, dedupliziert und begrenzt; Sitemap-Werte werden normalisiert; Broken-Link-Checks orientieren sich an der konfigurierbaren Permalink-Struktur statt an harten `/blog/`-Pfaden; Fehlerdetails bleiben intern. | Kritische SEO-Trust-Boundaries sind deutlich entschärft; gleichzeitig sinkt das Risiko von Fehlalarmen in SEO-Audits bei abweichender Permalink-Struktur. |
| `CMS/admin/modules/seo/PerformanceModule.php` | umgesetzt | Dateisystemläufe überspringen Symlinks, Settings-Werte werden sauber begrenzt und Save-Fehler generisch behandelt. | Das Risiko unbeabsichtigter Linkpfade und unnötiger Detail-Leaks sinkt; die Modulhärte steigt ohne die Oberfläche aufzublähen. |
| `CMS/admin/modules/settings/SettingsModule.php` | umgesetzt | Config-Schreibpfade für `config/app.php` und `.htaccess` robuster gemacht, Asset-/URL-Werte strenger normalisiert, erfolgreiche Änderungen auditgeloggt und Exception-Leaks entfernt. | Das Modul wirkt weniger „kritisch aus Prinzip“ und mehr wie ein kontrollierter Admin-Kernpfad; Restaufwand liegt eher in weiterer Zerlegung als in Sofortlücken. |
| `CMS/admin/settings.php` | umgesetzt | POST-Aktionen per Allowlist begrenzt; Redirect-/Flash-Flow vereinheitlicht. | Entry folgt konsequenter dem inzwischen etablierten Admin-Sicherheitsmuster. |

### Delta Batch 006

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/member/MemberDashboardModule.php` | umgesetzt | Speicherroutinen nutzen jetzt einen zentralen, auditierbaren Fehlerpfad; Asset-Referenzen und Onboarding-CTA-URLs werden defensiver normalisiert. | Admin-UI leakt keine rohen Exception-Details mehr; gleichzeitig sinkt das Risiko unsauberer Asset-/Link-Konfigurationen im Member-Bereich. |
| `CMS/admin/modules/legal/CookieManagerModule.php` | umgesetzt | Policy-URL, Slugs, Matomo-Site-ID und Bannertexte werden strenger normalisiert; der Scanner überspringt Symlinks; Save-Fehler bleiben generisch. | Cookie-Consent- und Tracking-Konfigurationen werden kontrollierter gespeichert, während Dateisystem-Scans weniger anfällig für Ausreißer und Detail-Leaks sind. |
| `CMS/admin/modules/legal/LegalSitesModule.php` | umgesetzt | Save-, Profil- und Seitengenerierungsfehler laufen über einen zentralen, auditierbaren Fehlerpfad statt über rohe Exception-Ausgaben. | Rechtstext-Management bleibt für Admins bedienbar, ohne interne Fehlerdetails oder Stack-Hinweise direkt offenzulegen. |

### Delta Batch 007

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/documentation.php` | umgesetzt | POST-Aktionen sind auf `sync_docs` begrenzt. | Der Entry folgt dem etablierten Admin-Muster und verarbeitet keine unerwarteten Mutationsaktionen mehr. |
| `CMS/admin/modules/system/DocumentationGitSync.php` | umgesetzt | Repo-/DOC-Ziel, Remote/Branch und Fehlerpfade werden vor/nach Git-Kommandos defensiver behandelt; Rohoutputs bleiben intern. | Git-basierte Doku-Syncs geben keine internen Shell-Details mehr an die Oberfläche weiter und arbeiten nur noch mit erwarteten Zielpfaden. |
| `CMS/admin/modules/system/DocumentationGithubZipSync.php` | umgesetzt | Arbeitsverzeichnisse werden auf Temp-/Repo-Roots begrenzt, symbolische Links ausgeschlossen und Fehler generisch behandelt. | ZIP-basierte Doku-Syncs sind robuster gegen Pfad-Ausreißer, Link-Fallen und UI-Detail-Leaks. |
| `CMS/admin/modules/system/DocumentationSyncDownloader.php` | umgesetzt | ZIP-Ziele werden auf neue Temp-Dateien begrenzt; leere Bodies werden verworfen. | Das Risiko unbeabsichtigter Überschreibungen oder leerer/unsauberer Download-Artefakte sinkt. |
| `CMS/admin/modules/system/DocumentationSyncFilesystem.php` | umgesetzt | Integritäts-, Finder-, Copy-, Count- und Delete-Pfade behandeln symbolische Links defensiv und propagieren rekursive Fehler sauberer. | Der eigentliche Dateisystem-Hotpath des Doku-Syncs wird deutlich robuster gegenüber Traversal-/Link-Effekten und halb stillen Cleanup-Fehlern. |

### Delta Batch 008

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/media.php` | umgesetzt | POST-Aktionen per Allowlist begrenzt; Redirect-Parameter werden vor Wiederverwendung normalisiert. | Der Media-Entry verarbeitet keine unerwarteten Mutationsaktionen mehr und spiegelt weniger rohe Query-Daten in Redirects zurück. |
| `CMS/admin/modules/media/MediaModule.php` | umgesetzt | Pfade, Tabs, Views, Suchbegriffe, Kategorie-Slugs sowie Upload-/Rename-/Category-Eingaben werden zentral bereinigt; System-Kategorien sind serverseitig geschützt; Service-Fehler werden intern geloggt und nur generisch im UI angezeigt. | Der Admin-Media-Hotspot verliert mehrere Trust-Boundary-Schwächen gleichzeitig: weniger unnormalisierter Input, weniger Detail-Leaks und klarere Schutzgeländer um Kategorien, Uploads und Dateipfade. |

### Delta Batch 009

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/updates.php` | umgesetzt | POST-Aktionen per Allowlist begrenzt; Plugin-Slugs vor Modulaufruf normalisiert; Redirect-/Flash-Flow für Fehlfälle vereinheitlicht. | Der Update-Entry verarbeitet keine unerwarteten Mutationen mehr und leitet Update-Aktionen kontrollierter in den Modulpfad weiter. |
| `CMS/admin/modules/system/UpdatesModule.php` | umgesetzt | Fehlerpfade für Prüf- und Installationsläufe liefern nur noch generische UI-Meldungen; technische Details werden intern geloggt und auditierbar protokolliert; manuelle Plugin-Fälle werden vor Auto-Install getrennt. | Der Modul-Hotspot verliert rohe Exception-Leaks und zieht beim Update-Handling auf das inzwischen etablierte Admin-Sicherheitsmuster nach. |
| `CMS/core/Services/UpdateService.php` | umgesetzt | Download-Quellen müssen zusätzlich den SSRF-/DNS-Sicherheitscheck bestehen; Installationsziele werden auf erlaubte Roots begrenzt; leere Bodies werden verworfen; Installationsfehler bleiben generisch. | Supply-Chain- und Dateisystem-Risiken im eigentlichen Update-Installationspfad sinken spürbar, ohne den Rollout-Mechanismus fachlich umzubauen. |

### Delta Batch 010

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/posts.php` | umgesetzt | POST-Aktionen und View-Routing per Allowlist begrenzt; Bulk-/Kategorie-Parameter vor Modulaufruf typisiert; Redirect-/Flash-Flow für Fehlfälle vereinheitlicht. | Der Posts-Entry verarbeitet keine unerwarteten Mutationen oder View-Sprünge mehr und spiegelt weniger rohe Request-Werte in den Admin-Flow zurück. |
| `CMS/admin/modules/posts/PostsModule.php` | umgesetzt | Listenfilter, Bulk-Aktionen und mehrere Text-/Meta-/Medienfelder werden zentral normalisiert; Kategorie-/Tag-Löschpfade sind von verdeckten `$_POST`-Reads entkoppelt; Fehlerpfade liefern nur noch generische UI-Meldungen mit internem Logging/Audit. | Der Posts-Hotspot verliert mehrere klassische Trust-Boundary-Schwächen zugleich: weniger unnormalisierte Eingaben, weniger Detail-Leaks und klarere Modulgrenzen zwischen Entry und Geschäftslogik. |

### Delta Batch 011

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/pages.php` | umgesetzt | POST-Aktionen und View-Routing per Allowlist begrenzt; CSRF-/Aktionsfehler per Redirect + Flash vereinheitlicht; Bulk-Parameter vor Modulaufruf defensiver typisiert. | Der Pages-Entry verarbeitet keine unerwarteten Mutationen oder View-Sprünge mehr und folgt jetzt enger dem etablierten Admin-Entry-Muster. |
| `CMS/admin/modules/pages/PagesModule.php` | umgesetzt | Listenfilter, Bulk-Aktionen sowie mehrere Titel-/Meta-/Medienfelder werden zentral normalisiert; Save-/Delete-/Bulk-Fehler liefern nur noch generische UI-Meldungen mit internem Logging/Audit. | Der Seiten-Hotspot verliert rohe Detail-Leaks und mehrere unnormalisierte Eingabepfade, ohne den fachlichen CRUD-Flow umzubauen. |

### Delta Batch 012

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/theme-explorer.php` | umgesetzt | POST-Aktionsfläche über explizite Allowlist konsolidiert. | Der Entry bleibt beim Save-Flow enger an bekannten Mutationen und reduziert Restspielraum im Wrapper. |
| `CMS/admin/modules/themes/ThemeEditorModule.php` | umgesetzt | Unsichere Dateianfragen liefern kontrollierte Warnungen; Binärdaten/zu große Inhalte werden vor dem Schreiben abgefangen; Syntax- und Schreibfehler bleiben im UI generisch und werden intern geloggt/auditierbar protokolliert. | Der Theme-Editor verliert weitere Detail-Leaks und gewinnt zusätzliche Schutzgeländer um die browserseitige Dateibearbeitung. |
| `CMS/admin/views/themes/editor.php` | umgesetzt | Tree-Renderer defensiver gegen Redeclare-/Datentyp-Randfälle gemacht. | Die View bleibt robuster und folgt enger dem defensiven Admin-Template-Stil. |

### Delta Batch 013

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/hub-sites.php` | umgesetzt | POST-Aktionen und View-Routing per Allowlist begrenzt; Fehlfälle liefern konsistente Fallback-Meldungen statt losem Sonderverhalten. | Der Hub-Sites-Entry verarbeitet keine unerwarteten Mutationen oder View-Sprünge mehr und bleibt beim Fehler-Flow enger am gemeinsamen Admin-Muster. |
| `CMS/admin/modules/hub/HubSitesModule.php` | umgesetzt | Suche, Plaintext-Felder und mehrere CTA-/Card-/Link-/Bild-URLs werden zentral normalisiert; Save-/Delete-/Duplicate-Fehler liefern nur noch generische UI-Meldungen mit internem Logging/Audit. | Der Hub-Hotspot verliert weitere Trust-Boundary-Schwächen: weniger unnormalisierte Eingaben, weniger Detail-Leaks und klarere Schutzgeländer um Hub-Konfiguration, Karten und Linkziele. |

### Delta Batch 014

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/core/Services/FileUploadService.php` | umgesetzt | Nur noch echte POST-Uploads, segmentweise Pfadvalidierung, Einzeldatei-Prüfung und generische Fehlerantworten mit internem Logging/Audit statt roher `WP_Error`-Details. | Der zentrale Upload-Endpunkt verliert Traversal-/Payload-Schwächen und reduziert Detail-Leaks an API-/Public-Clients, ohne den Media-/EditorJS-Flow fachlich umzubauen. |

### Delta Batch 015

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/comments.php` | umgesetzt | Kommentar-Entry nutzt jetzt die vorhandene View-Capability, akzeptiert nur noch bekannte POST-Aktionen und hält Redirects enger am validierten Statusfilter. | Der Entry trennt View- von Mutationsrechten sauberer und spiegelt keine losen Kommentar-Aktionen mehr in den Moderationsflow. |
| `CMS/admin/modules/comments/CommentsModule.php` | umgesetzt | Moderations-, Delete- und Bulk-Pfade validieren IDs, Zielstatus und Vorhandensein der Kommentare serverseitig und protokollieren Fehl- bzw. Teilzustände intern über Logging/Audit. | Der Kommentar-Hotspot verliert mehrere Trust-Boundary-Schwächen: weniger unvalidierte Mutationen, klarere Rechtekanten und weniger stille Bulk-Fehlpfade. |
| `CMS/admin/views/comments/list.php` | umgesetzt | Bulk-Bar, Checkboxen und Row-Actions richten sich an den tatsächlichen Rechten aus; Post-Ziele kommen vorbereitet aus dem Modul. | Die View koppelt Mutations-UI enger an den serverseitigen Rechtezustand und reduziert rohe Verkettungslogik im Template. |

### Delta Batch 016

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncService.php` | umgesetzt | Konfiggrenzen, Capability-Normalisierung und zentrale Fehler-/Erfolgspfade für den Doku-Sync-Orchestrator nachgezogen. | Der Orchestrator blockt inkonsistente Repo-/DOC-/ZIP-Konfiguration früher, protokolliert Sync-Erfolg und Abbruch zentral und verlässt sich weniger auf lose Roh-Rückgaben aus Unterservices. |

### Delta Batch 017

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/firewall.php` | umgesetzt | Firewall-Entry verarbeitet nur noch bekannte Aktionen und behandelt CSRF-/Aktionsfehler konsistent über Redirect + Flash. | Der Entry spiegelt keine losen Security-Mutationen mehr direkt in den Request-Flow und bleibt beim Fehlerverhalten enger am gemeinsamen Admin-Muster. |
| `CMS/admin/modules/security/FirewallModule.php` | umgesetzt | Regel- und Settings-Mutationen validieren Eingaben strenger, blocken Dubletten, leaken keine rohen Exceptions mehr und auditieren Fehlschläge kontrollierter. | Der Firewall-Hotspot verliert mehrere Trust-Boundary-Schwächen: weniger unvalidierte Regeln, weniger Detail-Leaks und klarere Pfade für Delete-/Toggle-/Save-Fehler. |
| `CMS/admin/views/security/firewall.php` | umgesetzt | Flash-Alerts, kontrollierter Ablaufdatum-Output und bestätigtes Löschen via `cmsConfirm(...)` nachgezogen. | Die UI bleibt näher am restlichen Security-Backend, reduziert unescaped Inline-Ausgabe und entkoppelt sich vom rohen Browser-Confirm. |

### Delta Batch 018

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/hub/HubTemplateProfileManager.php` | umgesetzt | Template-Persistenz härter validiert, URL-/JSON-Payloads begrenzt und Fehler-/Sync-Pfade sauber geloggt; Listen-Nutzung wird ohne N+1-Queries ermittelt. | Der Hub-Template-Hotspot verarbeitet weniger unbereinigte Profil-Daten, läuft robuster bei DB-/Sync-Fehlern und entlastet das Listing spürbar durch aggregierte Nutzungszähler. |

### Delta Batch 019

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/core/Services/GraphApiService.php` | umgesetzt | Graph-Konfiguration, Token-/API-Requests und Response-Handling restriktiver validiert; Testfehler bleiben nach außen generisch. | Der Service reduziert Konfigurationsdrift, unsaubere Endpoint-Pfade und Detail-Leaks im Graph-Testpfad, während Token-Abrufe jetzt sauber als Formular-Request und mit engeren Response-Grenzen laufen. |

### Delta Batch 020

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/core/Services/CommentService.php` | umgesetzt | Öffentliche Kommentar-Payloads, Post-Freigabe, Flood-Limits und Logging-/Audit-Pfade nachgezogen; öffentliche Ausgabe gibt keine Autor-Mail mehr mit aus. | Der Kommentar-Service blockt Missbrauch an der öffentlichen Eingabekante früher, reduziert unnötige personenbezogene Daten im Frontend und hält Persistenz-/Abbruchpfade nachvollziehbarer. |

### Delta Batch 021

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationRenderer.php` | umgesetzt | Doku-Rendering gegen übergroße Markdown-/CSV-Payloads, ausufernde Tabellen und unsaubere Linkziele begrenzt. | Der Renderer erzeugt HTML aus Repository-Dokumenten jetzt kontrollierter, validiert `href`-Ziele enger und hält große oder auffällige Dokumente durch serverseitige Limits und Logging besser im Zaum. |

### Delta Batch 022

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/security/SecurityAuditModule.php` | umgesetzt | Modulzugriff, Audit-Log-Ausgabe, Check-Texte und `.htaccess`-Inspektion defensiver gemacht; Fehlerpfade bleiben generisch. | Das Sicherheits-Audit verarbeitet weniger rohe Audit-Daten, begrenzt große/ungewöhnliche Prüfausgaben serverseitig und vermeidet Detail-Leaks bei Log-Bereinigung oder Teilfehlern im Audit-Lauf. |

### Delta Batch 023

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/legal/CookieManagerModule.php` | umgesetzt | Modulzugriff, Mutationsvalidierung, Settings-Persistenz und Scanner-Grenzen nachgezogen. | Der Cookie-Manager akzeptiert weniger unsaubere Konfigurationspayloads, auditierbare Mutationen laufen kontrollierter und der Scanner bleibt durch Größen-/Mengenlimits deutlich berechenbarer. |

### Delta Batch 024

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/core/Services/AzureMailTokenProvider.php` | umgesetzt | Konfiguration, Token-Endpoint, Cache-Validierung und Response-Handling restriktiver gemacht. | Der Azure-Mail-Tokenpfad akzeptiert weniger unsaubere Tenant-/Scope-/Endpoint-Werte, verwirft abgelaufene oder kaputte Cache-Einträge früher und reagiert kontrollierter auf Remote-Fehler und ungewöhnliche Antworten. |

### Delta Batch 025

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/core/Services/FeedService.php` | umgesetzt | Feed-URL-Validierung, Ausgabesanitisierung und Cache-Cleanup restriktiver gemacht. | Der Feed-Service blockt private/reservierte Remote-Ziele, begrenzt Batch-/Item-Mengen, sanitisiert Feed-HTML serverseitig und leakt Parser- bzw. Remote-Fehler nicht mehr roh an Aufrufer. |

### Delta Batch 026

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationModule.php` | umgesetzt | Modulzugriff, Repo-/DOC-Layout-Checks und Fehlerpfade restriktiver gemacht. | Der Doku-Orchestrator validiert Dokumentauswahl und lokale Pfade früher, kapselt Render-/Sync-Fehler generisch und verlässt sich weniger auf lose Annahmen aus Entry oder Unterservices. |

### Delta Batch 027

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/legal/LegalSitesModule.php` | umgesetzt | Modulzugriff, Seitenzuordnung, Settings-Reads und Eingaben restriktiver gemacht. | Das Legal-Sites-Modul validiert zugeordnete Seiten serverseitig, bündelt Settings-Zugriffe, begrenzt Profil-/HTML-Payloads und hält Fehlerpfade für UI und Audit-Log deutlich kontrollierter. |

### Delta Batch 028

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/landing/LandingPageModule.php` | umgesetzt | Interne Guards, Whitelisting und generische Fehlerpfade für Admin-Mutationen nachgezogen. | Das Landing-Page-Modul normalisiert Tabs und Payloads serverseitig, lässt nur erlaubte Felder zu und gibt bei Fehlern keine rohen Exceptions mehr in die Admin-Oberfläche weiter. |

### Delta Batch 029

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/core/Services/MailService.php` | umgesetzt | Header-Injection-Schutz, TLS-Härtung und UI-taugliche Fehlerpfade nachgezogen. | Der zentrale Mail-Service validiert Header, Adressen und Betreff restriktiver, erzwingt TLS für nicht-lokale SMTP-/OAuth2-Transporte und leakt keine rohen Provider-Fehler mehr an Admin-Oberflächen oder API-Aufrufer. |

### Delta Batch 030

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/core/Services/EditorJs/EditorJsRemoteMediaService.php` | umgesetzt | HTTPS-/URL-Validierung, Remote-Fehlerpfade und Metadaten-Sanitizing deutlich verschärft. | Der Remote-Media-Service für Editor.js akzeptiert nur noch normalisierte HTTPS-URLs ohne eingebettete Credentials, kapselt Remote-Fehler generisch und begrenzt fremde Metadaten sowie Preview-Bilder restriktiver. |

### Delta Batch 031

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncFilesystem.php` | umgesetzt | Repo-/DOC-/Temp-Roots jetzt in allen Copy-/Rename-/Delete-Pfaden strikt erzwungen. | Der Doku-Sync-Dateisystem-Layer operiert nur noch innerhalb explizit verwalteter Arbeitsbereiche und lehnt ausreißende Staging-, Backup-, Extract- oder Cleanup-Pfade konsistent vor der Operation ab. |

### Delta Batch 032

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/member/MemberDashboardModule.php` | umgesetzt | Interne RBAC-/CSRF-Gates, auditierte Save-Pfade und weniger N+1-Settings-Zugriffe nachgezogen. | Das Member-Dashboard-Modul prüft Rechte und Tokens jetzt auch intern pro Bereich, lädt Member-Settings und Plugin-Meta gebündelt und protokolliert erfolgreiche sicherheitsrelevante Konfigurationsänderungen explizit. |

### Delta Batch 033

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/BackupsModule.php` | umgesetzt | Interne RBAC-/CSRF-Gates, Backup-Namens-/Typvalidierung und auditierte Mutationen nachgezogen. | Das Backup-Modul prüft Lese- und Schreibzugriffe jetzt auch intern, akzeptiert für UI und Löschpfade nur noch whitelisted Backup-Namen/-Typen und protokolliert erfolgreiche Create-/Delete-Aktionen sowie Fehlerpfade kontrolliert statt lose Metadaten oder rohe Fehlertexte durchzureichen. |

### Delta Batch 034

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationGithubZipSync.php` | umgesetzt | Zusätzliche URL-/Archiv-Gates, Bundle-Konfig-Checks und sanitierte Audit-Kontexte nachgezogen. | Der GitHub-ZIP-Sync akzeptiert nur noch sauber validierte ZIP-Quellen, begrenzt Archivgröße und Eintragsanzahl, protokolliert Erfolg/Fehler strukturiert und schreibt keine rohen Exception-Texte oder kompletten Remote-URLs mehr in die Audit-/Logger-Kontexte. |

### Delta Batch 035

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncDownloader.php` | umgesetzt | Strengere Download-Ziel-/URL-Validierung, ZIP-Signatur-Checks und auditierte Response-Pfade nachgezogen. | Der Doku-Downloader akzeptiert nur noch dedizierte Temp-Ziele und erwartete GitHub-ZIP-URLs, verwirft zu kleine, zu große oder nicht-zipartige Responses früh und protokolliert Erfolge/Fehler kontrolliert statt rohe Remote-Fehler an die Oberfläche zu geben. |

### Delta Batch 036

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/cron.php` | umgesetzt | Web-Methoden begrenzt, Task-/Limit-Input normalisiert, Header-Token erlaubt, Parallel-Lock und generische Fehlerpfade ergänzt. | Der öffentliche Cron-Endpunkt (im Repo `CMS/cron.php`, in typischen FTP-Deployments als `cron.php` im Webroot) akzeptiert im Web nur noch erwartete Aufrufarten, verhindert parallele Läufe per Lockfile und gibt bei Fehlern keine rohen technischen Details mehr preis; gleichzeitig wurden unnötiger Session-Start und Body-Ausgabe für `HEAD`-Checks vermieden. |

### Delta Batch 037

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationGitSync.php` | umgesetzt | Remote-Ref-Prüfung, Parallel-Lock, Local-Change-Gate und robustere Runtime-Fehlerpfade nachgezogen. | Der Git-basierte Doku-Sync serialisiert parallele Läufe, überschreibt lokale `/DOC`-Änderungen nicht mehr still, prüft den Ziel-Ref vor dem Checkout und kapselt Status-/Runtime-Fehler weiter in sanitierte, auditierbare Modulpfade. |

### Delta Batch 038

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/seo/SeoSuiteModule.php` | umgesetzt | Allowlist-Gates für Submission-/Social-Werte, sanitierte Fehlerkontexte, N+1-freies Settings-Persistieren und bereinigte Sitemap-Dateistatusdaten nachgezogen. | Das SEO-Suite-Modul akzeptiert weniger lose Admin-Payloads, speichert Settings effizienter und gibt weder rohe Fehlertexte noch absolute Dateisystempfade unnötig in UI-/Audit-Kontexte weiter. |

### Delta Batch 039

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/seo/PerformanceModule.php` | umgesetzt | N+1-freies Settings-Persistieren, sanitierte Warmup-/Fehlerkontexte, maskierte Session-Daten und bereinigte Pfadangaben nachgezogen. | Das Performance-Modul speichert Konfiguration effizienter, reduziert Server-/PII-Leaks in Session- und Pfaddaten und hält Audit-/Warmup-Kontexte deutlich kompakter und kontrollierter. |

### Delta Batch 040

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/plugins/PluginMarketplaceModule.php` | umgesetzt | Relative Manifest-Gates, restriktivere ZIP-Validierung, Plugins-Root-Check und kontrollierteres Download-/Cleanup-Verhalten nachgezogen. | Das Marketplace-Modul blockt Traversal-Pfade in lokalen Manifesten früher, akzeptiert keine auffälligen ZIP-Strukturen mehr und führt Auto-Installationen mit robusteren Ziel- und Cleanup-Pfaden aus. |

### Delta Batch 041

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/settings/SettingsModule.php` | umgesetzt | N+1-freies Settings-Persistieren, maskierte Audit-/Mail-Kontexte, auditierte URL-Migrationen und robusterer Config-Dateiersatz nachgezogen. | Das Settings-Modul speichert Konfiguration effizienter, reduziert unnötige PII-/Fehlerleaks im Audit-Trail und ersetzt kritische Runtime-Konfigurationsdateien kontrollierter ohne unnötiges Verlustfenster. |

### Delta Batch 042

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/security/SecurityAuditModule.php` | umgesetzt | Log-Cleanup auf Security/Auth begrenzt, Audit-Detailpfade sanitisiert, IP-Maskierung und gezieltere Runtime-Konfigurationschecks nachgezogen. | Das Security-Audit-Modul räumt keine fachfremden Audit-Einträge mehr weg, zeigt weniger sensible Details im Security-Log und prüft die produktive Konfigurationslage gezielter. |

### Delta Batch 043

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationRenderer.php` | umgesetzt | Linkziel-Limits, Resolver-Fehlerabfang, Href-Härtung gegen `//`-/Steuerzeichen-Fälle und Guard-Limits für große Codeblöcke nachgezogen. | Der Doku-Renderer bleibt bei fehlerhaften Linkzielen stabil, blockt problematische Hrefs früher und begrenzt große Markdown-Codefences kontrollierter, ohne die Admin-Ansicht zu sprengen. |

### Delta Batch 044

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/legal/CookieManagerModule.php` | umgesetzt | Matomo-Self-Hosted-URLs restriktiver validiert, Scanner-Zielpfade stärker gestaffelt und Settings-/Kategorie-Lookups gebündelt. | Der Cookie-Manager speichert Tracking-Ziele kontrollierter, scannt weniger Low-Value-Dateibäume und reduziert unnötige Existenzabfragen in Settings- und Kategoriepfaden. |

### Delta Batch 045

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` | umgesetzt | Modulzugriff, Settings-Persistenz, Plan-/Seitenvalidierung und generische Fehler-/Auditpfade nachgezogen. | Die Subscription-Settings speichern Billing- und Default-Zuweisungen jetzt kontrollierter, vermeiden N+1-Settings-Queries und leaken keine rohen Exception-Texte mehr in die Admin-Oberfläche. |

### Delta Batch 046

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/mail-settings.php` | umgesetzt | POST-Aktionsfläche per Allowlist begrenzt, Redirect-Basis vereinheitlicht und Fehlpfade konsistent gemacht. | Der Mail-Entry verarbeitet keine unerwarteten Mutationen mehr und hält CSRF-/Aktionsfehler enger am gemeinsamen Admin-Muster. |
| `CMS/admin/modules/system/MailSettingsModule.php` | umgesetzt | Host-/Endpoint-/Empfänger-Validierung, Audit-Masking und generische Queue-/Graph-/Testpfade nachgezogen. | Die Mail-Settings speichern Transport- und Cloud-Endpunkte kontrollierter, maskieren sensible Identitätsdaten im Audit-Log und halten operative Testergebnisse kompakter und weniger plaudernd. |

### Delta Batch 047

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/backups.php` | umgesetzt | POST-Aktionsfläche über zentralen Allowlist-Dispatch vereinheitlicht; unbekannte Aktionen liefern kontrollierte Fallbacks. | Der Backup-Entry verarbeitet Mutationen jetzt konsistent wie andere Admin-Wrapper und streut keine losen Sonderpfade mehr in den Request-Flow. |
| `CMS/admin/modules/system/BackupsModule.php` | umgesetzt | Datenbank-Backups laufen jetzt über verwaltbare Container, Listen sind begrenzt und UI-Größen werden bereits serverseitig aufbereitet. | Das Modul arbeitet enger mit dem Service zusammen, vermeidet unverwaltbare DB-Backup-Artefakte und hält den Listenpfad bei wachsendem Backup-Bestand berechenbarer. |
| `CMS/core/Services/BackupService.php` | umgesetzt | Standalone-DB-Backups erhalten Manifest-Container, Legacy-Dateien bleiben defensiv verwaltbar und große Listen werden vor dem Manifest-Parsing früher begrenzt. | Backup-Artefakte sind konsistenter verwaltbar; zugleich sinkt unnötiger I/O-Druck, weil für Admin-Listen weniger Manifest-Dateien eingelesen werden müssen. |

### Delta Batch 048

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/documentation.php` | umgesetzt | `doc`-Parameter werden defensiver normalisiert, Redirects spiegeln nur noch validierte Dokumente und POST-Aktionen laufen über einen zentralen Dispatch-Fallback. | Der Doku-Entry hält Query- und Mutationspfade enger am gemeinsamen Admin-Muster und reicht weniger rohe Request-Werte in Redirect und Modulpfad durch. |
| `CMS/admin/views/system/documentation.php` | umgesetzt | Statusmeldungen nutzen jetzt den gemeinsamen Flash-Alert-Partial statt eines eigenen Inline-Alerts. | Die Doku-View folgt dem etablierten Admin-UI-Kontrakt enger und reduziert Template-eigene Alert-Sonderlogik. |

### Delta Batch 049

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationCatalog.php` | umgesetzt | Docs-Root-Gates, Symlink-Skip, begrenzte Preview-/Vollreads und kompaktere Logpfade nachgezogen. | Der Doku-Katalog scannt Dokumentbäume berechenbarer, liest weniger unnötige Daten pro Datei und vermeidet rohe absolute Pfadangaben in Fehlerkontexten. |

### Delta Batch 050

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/packages.php` | umgesetzt | POST-Aktionsfläche per Allowlist begrenzt und Redirect-/Flash-Flow für CSRF- und Aktionsfehler vereinheitlicht. | Der Paket-Entry verarbeitet keine unerwarteten Mutationen mehr und folgt enger dem gemeinsamen Admin-Wrapper-Muster. |
| `CMS/admin/modules/subscriptions/PackagesModule.php` | umgesetzt | Interne Admin-Guards, restriktivere Slug-/Text-Validierung, Audit-Logging und generische Fehlerpfade nachgezogen. | Die Paketlogik akzeptiert weniger lose Eingaben, protokolliert Mutationen nachvollziehbarer und leakt keine rohen Exception-Texte mehr in die Admin-Oberfläche. |

### Delta Batch 051

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncService.php` | umgesetzt | Interner Admin-Guard, gemeinsamer Sync-Lock und konfigurationsbewusste Capability-Ausgabe nachgezogen. | Der Doku-Sync-Orchestrator blockt direkte Nicht-Admin-Aufrufe selbst, serialisiert parallele Git-/ZIP-Syncs zentral und zeigt der Oberfläche kaputte Sync-Konfigurationen nicht mehr als scheinbar nutzbaren Modus an. |

### Delta Batch 052

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/MailSettingsModule.php` | umgesetzt | Operative Queue-/Cache-/Test-Ausnahmen zentralisiert abgefangen, Unterservice-Rückgaben für die UI sanitisiert und Queue-Save-Audit um Token-Rotation ergänzt. | Das Mail-Settings-Modul reagiert robuster auf Sonderfälle aus Mail-/Graph-/Queue-Services, lässt weniger lose Antwortdaten in den Admin-Flow und protokolliert Queue-Mutationen nachvollziehbarer. |

### Delta Batch 053

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/core/Services/BackupService.php` | umgesetzt | Realpath-basierte Root-Gates, speicherschonender Tabellen-Dump und engerer REST-S3-Uploadpfad nachgezogen. | Der Backup-Service akzeptiert weniger ausreißende Zielpfade, erzeugt Datenbank-Dumps mit weniger Peak-Memory und verhindert ungebremste REST-Uploads von auffälligen oder zu großen Backup-Dateien. |

### Delta Batch 054

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationGithubZipSync.php` | umgesetzt | ZIP-URL ohne Query-/Credential-Anteile, lokale Archivvalidierung vor dem Entpacken und gezielteres Cleanup/kompaktere Logpfade nachgezogen. | Der GitHub-ZIP-Sync akzeptiert weniger lose Quell-URLs, entpackt nur nochmals validierte Archive und hinterlässt nach Rollback/Restore weniger Repo-Artefakte oder unnötig absolute Pfadangaben im Log. |

### Delta Batch 055

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/orders.php` | umgesetzt | POST-Dispatch auf Aktions-Allowlist gebracht, Statusfilter serverseitig normalisiert und Flash-/Redirect-Flow vereinheitlicht. | Der Orders-Entry verarbeitet keine losen Mutationen mehr und spiegelt weniger rohe Request-Werte in den Admin-Flow zurück. |
| `CMS/admin/modules/subscriptions/OrdersModule.php` | umgesetzt | Status-/Billing-Normalisierung, Bestell-Existenzchecks und maskiertes Audit-Logging für Statuswechsel/Löschung nachgezogen. | Die Bestelllogik blockt ungültige oder verwaiste Mutationen früher, reduziert rohe Kundenkontexte im Audit-Trail und hält Listen-/Mutationspfade konsistenter. |

### Delta Batch 056

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/subscription-settings.php` | umgesetzt | Entry-POST-Dispatch auf explizite Action-Allowlist gebracht, Flash-/Redirect-Flow vereinheitlicht und Session-Alert defensiver übernommen. | Der Wrapper verarbeitet keine impliziten Mutationen mehr und hält CSRF-/Aktionsfehler näher am gemeinsamen Admin-Standard. |
| `CMS/admin/views/subscriptions/settings.php` | umgesetzt | Gemeinsamen Flash-Alert-Partial eingebunden und Mutation explizit als `save_settings` verdrahtet. | Die View reduziert eigenes Alert-Markup, folgt dem UI-Standard enger und macht den Save-Pfad für den Wrapper eindeutiger. |

### Delta Batch 057

| Datei/Bereich | Status | Nachgezogener Punkt aus `PRÜFUNG.MD` | Wirkung |
|---|---|---|---|
| `CMS/admin/modules/system/DocumentationSyncEnvironment.php` | umgesetzt | Repo-Root früh validiert, Shell-Kommandos auf definierte Git-Subcommands begrenzt und auffällige Command-Payloads geblockt. | Die Environment-Schicht behandelt kaputte Arbeitsverzeichnisse und unsaubere Shell-Aufrufe früher als kontrollierten Nicht-Verfügbar-Zustand statt sie lose bis in Git-Pfade durchzureichen. |

## Grundlage

Diese Datei bewertet den aktuellen CMS-Codebestand **dateiweise** nach:

- **Security** ($1$ bis $100$)
- **Speed** ($1$ bis $100$)
- **PHP/BP** ($1$ bis $100$) – bei Nicht-PHP-Dateien als Stack-/Best-Practice-Konformität des jeweiligen Dateityps
- **Gesamt** ($1$ bis $100$)

Geprüfter Scope:

- `CMS/**`
- **ohne** `CMS/vendor/**`
- **ohne** `CMS/themes/**`
- unter `CMS/assets/**` **nur** `assets/css/**` und `assets/js/**`

Verwendete Referenzbasis für die Einordnung:

- OWASP Top 10 (2025)
- OWASP Cheat Sheets: PHP Configuration, XSS Prevention, SQL Injection Prevention, Session Management, Input Validation, Error Handling, File Upload
- PHP Manual – Security
- PSR-12
- PHP The Right Way

## Bewertungslogik

| Bereich | Bedeutung |
|---|---|
| `90–100` | sehr stark / vorbildlich |
| `75–89` | gut / solide |
| `60–74` | brauchbar, aber mit klaren Baustellen |
| `40–59` | deutlich verbesserungsbedürftig |
| `< 40` | kritisch |

## Admin

### Bewertung

| Datei | Kurzbeschreibung | Verknüpfung/Abhängigkeiten | Security | Speed | PHP/BP | Gesamt |
|---|---|---|---:|---:|---:|---:|
| `CMS/admin/analytics.php` | Analytics-Entry | SEO/Analytics-Modul, Layout, SEO-View | 84 | 86 | 85 | 85 |
| `CMS/admin/antispam.php` | Antispam-Entry | `AntispamModule`, Security-View | 84 | 86 | 85 | 85 |
| `CMS/admin/backups.php` | Backup-Entry | `BackupsModule`, System-View, I/O | 79 | 74 | 84 | 79 |
| `CMS/admin/comments.php` | Kommentar-Entry | `CommentsModule`, Comments-View | 89 | 86 | 88 | 88 |
| `CMS/admin/cookie-manager.php` | Cookie-Entry | `CookieManagerModule`, Legal-View | 80 | 78 | 84 | 80 |
| `CMS/admin/data-requests.php` | DSGVO-Export-Entry | Legal-Modul, Legal-View | 84 | 86 | 85 | 85 |
| `CMS/admin/deletion-requests.php` | Löschanfrage-Entry | Legal-Modul, Legal-View | 84 | 86 | 85 | 85 |
| `CMS/admin/design-settings.php` | Design-Entry | Theme-Design-Modul, Theme-View | 84 | 86 | 85 | 85 |
| `CMS/admin/diagnose.php` | Diagnose-Entry | System-Modul, Diagnose-View | 89 | 93 | 86 | 89 |
| `CMS/admin/documentation.php` | Doku-Entry | Dokumentations-Module, Doku-View | 80 | 74 | 84 | 80 |
| `CMS/admin/error-report.php` | Error-Report-Entry | Error-Services, Formular-Flow | 78 | 80 | 83 | 80 |
| `CMS/admin/firewall.php` | Firewall-Entry | `FirewallModule`, Security-View | 84 | 79 | 86 | 83 |
| `CMS/admin/font-manager.php` | Font-Manager-Entry | Font-Modul, Theme-Fonts-View, FS | 78 | 76 | 84 | 79 |
| `CMS/admin/groups.php` | Gruppen-Entry | `GroupsModule`, Users-View | 84 | 86 | 85 | 85 |
| `CMS/admin/hub-sites.php` | Hub-Sites-Entry | Hub-Module, Hub-Views | 85 | 80 | 86 | 84 |
| `CMS/admin/index.php` | Dashboard-Entry | `DashboardModule`, Header/Sidebar, Dashboard-View | 89 | 93 | 86 | 89 |
| `CMS/admin/info.php` | Info-Entry | SystemInfo-Modul, System-View | 89 | 93 | 86 | 89 |
| `CMS/admin/landing-page.php` | Landing-Builder-Entry | `LandingPageModule`, Landing-View | 80 | 78 | 84 | 80 |
| `CMS/admin/legal-sites.php` | Rechtstexte-Entry | `LegalSitesModule`, Legal-Views | 80 | 76 | 84 | 79 |
| `CMS/admin/mail-settings.php` | Mail-Entry | `MailSettingsModule`, Mail-View, API-Test | 85 | 82 | 88 | 85 |
| `CMS/admin/media.php` | Medien-Entry | `MediaModule`, Media-Views, Upload/FS | 76 | 72 | 82 | 77 |
| `CMS/admin/member-dashboard-design.php` | Member-Design-Entry | Member-Modul, Member-View | 84 | 86 | 85 | 85 |
| `CMS/admin/member-dashboard-frontend-modules.php` | Member-Module-Entry | Member-Modul, Member-View | 84 | 86 | 85 | 85 |
| `CMS/admin/member-dashboard-general.php` | Member-General-Entry | Member-Modul, Member-View | 84 | 86 | 85 | 85 |
| `CMS/admin/member-dashboard-notifications.php` | Member-Notify-Entry | Member-Modul, Member-View | 84 | 86 | 85 | 85 |
| `CMS/admin/member-dashboard-onboarding.php` | Member-Onboarding-Entry | Member-Modul, Member-View | 84 | 86 | 85 | 85 |
| `CMS/admin/member-dashboard-page.php` | Member-Page-Entry | Member-Modul, Member-View | 84 | 86 | 85 | 85 |
| `CMS/admin/member-dashboard-plugin-widgets.php` | Member-Pluginwidget-Entry | Member-Modul, Member-View | 84 | 86 | 85 | 85 |
| `CMS/admin/member-dashboard-profile-fields.php` | Member-Profilfeld-Entry | Member-Modul, Member-View | 84 | 86 | 85 | 85 |
| `CMS/admin/member-dashboard-widgets.php` | Member-Widget-Entry | Member-Modul, Member-View | 84 | 86 | 85 | 85 |
| `CMS/admin/member-dashboard.php` | Member-Dashboard-Entry | `MemberDashboardModule`, Member-Views | 84 | 86 | 85 | 85 |
| `CMS/admin/menu-editor.php` | Menü-Editor-Entry | `MenuEditorModule`, Menus-View | 84 | 86 | 85 | 85 |
| `CMS/admin/monitor-cron-status.php` | Cron-Monitor-Entry | Performance/System-View | 89 | 93 | 86 | 89 |
| `CMS/admin/monitor-disk-usage.php` | Disk-Monitor-Entry | Performance/System-View, FS | 89 | 90 | 86 | 88 |
| `CMS/admin/monitor-email-alerts.php` | Alert-Monitor-Entry | System-View | 89 | 93 | 86 | 89 |
| `CMS/admin/monitor-health-check.php` | Health-Monitor-Entry | System-View | 89 | 93 | 86 | 89 |
| `CMS/admin/monitor-response-time.php` | Response-Monitor-Entry | System-View | 89 | 93 | 86 | 89 |
| `CMS/admin/monitor-scheduled-tasks.php` | Task-Monitor-Entry | System-View | 89 | 93 | 86 | 89 |
| `CMS/admin/not-found-monitor.php` | 404-Monitor-Entry | Redirect-Modul, SEO-View | 84 | 86 | 85 | 85 |
| `CMS/admin/orders.php` | Orders-Entry | `OrdersModule`, Subscription-View | 86 | 87 | 87 | 87 |
| `CMS/admin/packages.php` | Pakete-Entry | `PackagesModule`, Subscription-View | 86 | 87 | 86 | 86 |
| `CMS/admin/pages.php` | Pages-Entry | `PagesModule`, Pages-Views, Editor | 87 | 85 | 86 | 86 |
| `CMS/admin/performance-cache.php` | Cache-Entry | `PerformanceModule`, Performance-View | 84 | 86 | 85 | 85 |
| `CMS/admin/performance-database.php` | DB-Perf-Entry | `PerformanceModule`, Performance-View | 84 | 84 | 85 | 84 |
| `CMS/admin/performance-media.php` | Media-Perf-Entry | `PerformanceModule`, Performance-View | 84 | 84 | 85 | 84 |
| `CMS/admin/performance-page.php` | PageSpeed-Entry | `PerformanceModule`, SEO-Perf-View | 84 | 86 | 85 | 85 |
| `CMS/admin/performance-sessions.php` | Sessions-Entry | `PerformanceModule`, Performance-View | 84 | 86 | 85 | 85 |
| `CMS/admin/performance-settings.php` | Perf-Settings-Entry | `PerformanceModule`, Performance-View | 84 | 86 | 85 | 85 |
| `CMS/admin/performance.php` | Performance-Entry | `PerformanceModule`, SEO/Perf-Views | 84 | 86 | 85 | 85 |
| `CMS/admin/plugin-marketplace.php` | Plugin-Marketplace-Entry | Marketplace-Modul, Remote-Registry | 74 | 68 | 82 | 74 |
| `CMS/admin/plugins.php` | Plugins-Entry | `PluginsModule`, Plugins-Views | 80 | 82 | 84 | 81 |
| `CMS/admin/post-categories.php` | Post-Kategorien-Entry | Posts/Page-Modul, Posts-View | 84 | 86 | 85 | 85 |
| `CMS/admin/post-tags.php` | Post-Tags-Entry | Posts-Modul, Posts-View | 84 | 86 | 85 | 85 |
| `CMS/admin/posts.php` | Posts-Entry | `PostsModule`, Posts-Views, EditorJS | 86 | 83 | 86 | 85 |
| `CMS/admin/privacy-requests.php` | Privacy-Requests-Entry | Legal-Modul, Legal-View | 84 | 86 | 85 | 85 |
| `CMS/admin/redirect-manager.php` | Redirect-Entry | `RedirectManagerModule`, SEO-View | 84 | 84 | 85 | 84 |
| `CMS/admin/roles.php` | Rollen-Entry | `RolesModule`, Users-View | 84 | 86 | 85 | 85 |
| `CMS/admin/security-audit.php` | Security-Audit-Entry | `SecurityAuditModule`, Security-View | 80 | 80 | 84 | 81 |
| `CMS/admin/seo-audit.php` | SEO-Audit-Entry | SEO-Suite, SEO-View | 84 | 84 | 85 | 84 |
| `CMS/admin/seo-dashboard.php` | SEO-Dashboard-Entry | SEO-Module, SEO-View | 84 | 86 | 85 | 85 |
| `CMS/admin/seo-meta.php` | SEO-Meta-Entry | SEO-Suite, SEO-View | 84 | 86 | 85 | 85 |
| `CMS/admin/seo-page.php` | SEO-Page-Entry | SEO-Suite, SEO-View | 84 | 84 | 85 | 84 |
| `CMS/admin/seo-schema.php` | SEO-Schema-Entry | SEO-Suite, SEO-View | 84 | 86 | 85 | 85 |
| `CMS/admin/seo-sitemap.php` | SEO-Sitemap-Entry | SEO-Suite, Sitemap/Indexing | 80 | 78 | 84 | 80 |
| `CMS/admin/seo-social.php` | SEO-Social-Entry | SEO-Suite, SEO-View | 84 | 86 | 85 | 85 |
| `CMS/admin/seo-technical.php` | SEO-Technical-Entry | SEO-Suite, Redirect/Link-Checks | 82 | 80 | 84 | 82 |
| `CMS/admin/settings.php` | Settings-Entry | `SettingsModule`, Settings-View | 82 | 80 | 84 | 82 |
| `CMS/admin/site-tables.php` | Site-Tables-Entry | `TablesModule`, Tables-Views | 80 | 78 | 84 | 80 |
| `CMS/admin/subscription-settings.php` | Subscription-Settings-Entry | Billing-Modul, Subscription-View | 84 | 81 | 86 | 84 |
| `CMS/admin/support.php` | Support-Entry | `SupportModule`, Support-View | 89 | 93 | 86 | 89 |
| `CMS/admin/system-info.php` | System-Info-Entry | `SystemInfoModule`, System-View | 89 | 93 | 86 | 89 |
| `CMS/admin/system-monitor-page.php` | System-Monitor-Entry | Performance/System-View | 89 | 93 | 86 | 89 |
| `CMS/admin/table-of-contents.php` | TOC-Entry | `TocModule`, TOC-View | 84 | 86 | 85 | 85 |
| `CMS/admin/theme-editor.php` | Theme-Editor-Entry | Theme-Editor-Modul, FS | 72 | 72 | 82 | 74 |
| `CMS/admin/theme-explorer.php` | Theme-Explorer-Entry | Theme-Modul, FS | 80 | 79 | 85 | 81 |
| `CMS/admin/theme-marketplace.php` | Theme-Marketplace-Entry | Theme-Marketplace, Remote | 74 | 68 | 82 | 74 |
| `CMS/admin/theme-settings.php` | Theme-Settings-Entry | Theme-Modul, Theme-View | 84 | 86 | 85 | 85 |
| `CMS/admin/themes.php` | Themes-Entry | `ThemesModule`, Theme-Views | 80 | 82 | 84 | 81 |
| `CMS/admin/updates.php` | Updates-Entry | `UpdatesModule`, System-View | 78 | 76 | 84 | 79 |
| `CMS/admin/user-settings.php` | User-Settings-Entry | `UserSettingsModule`, Users-View | 84 | 86 | 85 | 85 |
| `CMS/admin/users.php` | Users-Entry | `UsersModule`, Users-Views | 84 | 84 | 85 | 84 |
| `CMS/admin/modules/comments/CommentsModule.php` | Kommentar-Logik | DB, Kommentarstatus, View-Daten | 87 | 77 | 87 | 84 |
| `CMS/admin/modules/dashboard/DashboardModule.php` | Dashboard-Datenlogik | `DashboardService`, DB, KPI-Builder | 80 | 74 | 84 | 79 |
| `CMS/admin/modules/hub/HubSitesModule.php` | Hub-Sites-Logik | DB, Settings, Hub-Views | 83 | 71 | 85 | 80 |
| `CMS/admin/modules/hub/HubTemplateProfileCatalog.php` | Hub-Profilkatalog | Profile, Defaults, Hub-Editor | 84 | 88 | 84 | 85 |
| `CMS/admin/modules/hub/HubTemplateProfileManager.php` | Hub-Profilmanager | DB, JSON, Hub-Templates | 84 | 74 | 87 | 82 |
| `CMS/admin/modules/landing/LandingPageModule.php` | Landing-Builder-Logik | DB/Settings, Landing-View | 88 | 76 | 86 | 83 |
| `CMS/admin/modules/legal/CookieManagerModule.php` | Cookie-Manager-Logik | DB, Settings, Code-Snippets | 85 | 76 | 87 | 83 |
| `CMS/admin/modules/legal/DeletionRequestsModule.php` | Löschanfragen-Logik | DB, DSGVO-Hooks | 80 | 76 | 84 | 80 |
| `CMS/admin/modules/legal/LegalSitesModule.php` | Rechtstexte-Logik | DB, Templates, Escaping | 86 | 74 | 85 | 82 |
| `CMS/admin/modules/legal/PrivacyRequestsModule.php` | Privacy-Request-Logik | DB, DSGVO-Prozess | 80 | 76 | 84 | 80 |
| `CMS/admin/modules/media/MediaModule.php` | Medienlogik | `MediaService`, Upload, FS | 76 | 70 | 84 | 77 |
| `CMS/admin/modules/member/MemberDashboardModule.php` | Member-Dashboard-Logik | DB, Settings, Widgets | 88 | 72 | 86 | 83 |
| `CMS/admin/modules/menus/MenuEditorModule.php` | Menülogik | DB, Menübaum, CRUD | 80 | 70 | 84 | 78 |
| `CMS/admin/modules/pages/PagesModule.php` | Seitenlogik | DB, SEO, Kategorien, Bulk | 83 | 68 | 86 | 80 |
| `CMS/admin/modules/plugins/PluginMarketplaceModule.php` | Plugin-Marketplace-Logik | Registry, Remote-Download | 84 | 72 | 86 | 81 |
| `CMS/admin/modules/plugins/PluginsModule.php` | Plugin-Management-Logik | Settings, Aktivierung, FS | 78 | 80 | 84 | 80 |
| `CMS/admin/modules/posts/PostsCategoryViewModelBuilder.php` | Kategorie-ViewModel-Helfer | Posts-Modul, Kategorien-UI | 88 | 92 | 86 | 89 |
| `CMS/admin/modules/posts/PostsModule.php` | Beitragslogik | DB, SEO, Media, Redirects | 83 | 66 | 86 | 79 |
| `CMS/admin/modules/security/AntispamModule.php` | Antispam-Logik | Settings, Regeln, Security-View | 84 | 86 | 84 | 85 |
| `CMS/admin/modules/security/FirewallModule.php` | Firewall-Logik | DB, Regeln, Security-Logs | 84 | 73 | 87 | 82 |
| `CMS/admin/modules/security/SecurityAuditModule.php` | Security-Audit-Logik | Scanner, Settings, Reports | 90 | 78 | 86 | 85 |
| `CMS/admin/modules/seo/AnalyticsModule.php` | SEO-Analytics-Logik | DB, PageViews, KPIs | 80 | 66 | 84 | 77 |
| `CMS/admin/modules/seo/PerformanceModule.php` | Performance-Logik | DB, FS, Sessions, Cache | 82 | 68 | 86 | 79 |
| `CMS/admin/modules/seo/RedirectManagerModule.php` | Redirect-Logik | Redirect-Service, DB | 82 | 78 | 84 | 81 |
| `CMS/admin/modules/seo/SeoDashboardModule.php` | SEO-Dashboard-Logik | SEO-Services, KPIs | 84 | 82 | 84 | 83 |
| `CMS/admin/modules/seo/SeoSuiteModule.php` | SEO-Suite-Kernlogik | SEO/Analytics/Indexing/Redirect | 84 | 68 | 86 | 79 |
| `CMS/admin/modules/settings/SettingsModule.php` | Settings-Kernlogik | DB, Mail, URL-Migration | 84 | 72 | 86 | 81 |
| `CMS/admin/modules/subscriptions/OrdersModule.php` | Orders-Logik | DB, Abos, Zahlungsdaten | 86 | 80 | 88 | 85 |
| `CMS/admin/modules/subscriptions/PackagesModule.php` | Paket-Logik | DB, Paket-CRUD | 85 | 81 | 86 | 84 |
| `CMS/admin/modules/subscriptions/SubscriptionSettingsModule.php` | Billing-Settings-Logik | Settings, Gateway-Optionen | 84 | 80 | 88 | 84 |
| `CMS/admin/modules/system/BackupsModule.php` | Backup-Logik | FS, Dumps, Restore | 91 | 72 | 88 | 84 |
| `CMS/admin/modules/system/DocumentationCatalog.php` | Doku-Katalog | Doku-Service, Quellen | 88 | 90 | 85 | 88 |
| `CMS/admin/modules/system/DocumentationGitSync.php` | Git-Doku-Sync | Git/Remote, FS | 88 | 70 | 86 | 81 |
| `CMS/admin/modules/system/DocumentationGithubZipSync.php` | GitHub-Zip-Sync | Remote-Zip, FS | 90 | 68 | 89 | 83 |
| `CMS/admin/modules/system/DocumentationModule.php` | Doku-Logik | Renderer, Sync, Catalog | 89 | 79 | 90 | 86 |
| `CMS/admin/modules/system/DocumentationRenderer.php` | Doku-Renderer | Markdown→HTML, Escaping | 89 | 81 | 86 | 85 |
| `CMS/admin/modules/system/DocumentationSyncDownloader.php` | Doku-Downloader | HTTP/Remote, FS | 93 | 72 | 91 | 85 |
| `CMS/admin/modules/system/DocumentationSyncEnvironment.php` | Doku-Env-Check | Runtime/Env-Checks | 89 | 91 | 88 | 89 |
| `CMS/admin/modules/system/DocumentationSyncFilesystem.php` | Doku-FS-Logik | FS, Pfade, Speicherung | 88 | 74 | 86 | 84 |
| `CMS/admin/modules/system/DocumentationSyncService.php` | Doku-Sync-Orchestrator | Downloader, FS, GitSync | 90 | 72 | 91 | 85 |
| `CMS/admin/modules/system/MailSettingsModule.php` | Mail-Settings-Logik | Mailservice, Settings | 85 | 78 | 88 | 84 |
| `CMS/admin/modules/system/SupportModule.php` | Support-Logik | Tickets/Hinweise, leicht | 86 | 88 | 84 | 86 |
| `CMS/admin/modules/system/SystemInfoModule.php` | Systeminfo-Logik | Env/PHP/Serverdaten | 88 | 84 | 84 | 86 |
| `CMS/admin/modules/system/UpdatesModule.php` | Update-Logik | Remote/Versionen, FS | 76 | 70 | 84 | 77 |
| `CMS/admin/modules/tables/TablesModule.php` | Site-Tables-Logik | DB, JSON, Tabellen-CRUD | 76 | 64 | 84 | 75 |
| `CMS/admin/modules/themes/DesignSettingsModule.php` | Theme-Design-Logik | Settings, Theme-Optionen | 84 | 82 | 84 | 83 |
| `CMS/admin/modules/themes/FontManagerModule.php` | Font-Logik | Upload, FS, Theme-Assets | 78 | 70 | 84 | 77 |
| `CMS/admin/modules/themes/ThemeEditorModule.php` | Theme-Editor-Logik | FS, Theme-Dateien | 78 | 75 | 85 | 79 |
| `CMS/admin/modules/themes/ThemeMarketplaceModule.php` | Theme-Marketplace-Logik | Remote-Registry, Download | 72 | 66 | 82 | 73 |
| `CMS/admin/modules/themes/ThemesModule.php` | Theme-Management-Logik | Aktivierung, Löschung, FS | 80 | 78 | 84 | 80 |
| `CMS/admin/modules/toc/TocModule.php` | TOC-Logik | Settings, Parser, UI | 84 | 88 | 84 | 85 |
| `CMS/admin/modules/users/GroupsModule.php` | Gruppen-Logik | DB, User-Gruppen | 82 | 82 | 84 | 82 |
| `CMS/admin/modules/users/RolesModule.php` | Rollen-Logik | DB, RBAC | 82 | 82 | 84 | 82 |
| `CMS/admin/modules/users/UserSettingsModule.php` | User-Settings-Logik | Settings, Registrierung | 84 | 84 | 84 | 84 |
| `CMS/admin/modules/users/UsersModule.php` | User-CRUD-Logik | `UserService`, DB, Filter | 80 | 76 | 84 | 80 |
| `CMS/admin/partials/footer.php` | Admin-Footer | globale JS, Page-Assets | 88 | 92 | 84 | 88 |
| `CMS/admin/partials/header.php` | Admin-Header | Meta, CSS, Cache-Header | 88 | 92 | 84 | 88 |
| `CMS/admin/partials/section-page-shell.php` | Section-Shell | Auth, CSRF, Modul-Factory, View | 90 | 94 | 85 | 90 |
| `CMS/admin/partials/sidebar.php` | Admin-Sidebar | Navigation, DB-Settings, Icons | 84 | 86 | 84 | 84 |
| `CMS/admin/views/comments/list.php` | Kommentar-Liste | Comments-Modul, Flash-Partial | 88 | 88 | 86 | 87 |
| `CMS/admin/views/dashboard/index.php` | Dashboard-UI | `DashboardModule`, Inline-Helfer, KPIs | 82 | 74 | 83 | 80 |
| `CMS/admin/views/hub/edit.php` | Hub-Site-Edit-UI | Hub-Modul | 82 | 80 | 83 | 82 |
| `CMS/admin/views/hub/list.php` | Hub-Site-Liste | Hub-Modul | 86 | 88 | 84 | 86 |
| `CMS/admin/views/hub/template-edit.php` | Hub-Template-Edit-UI | Hub-Modul, Subpartials | 78 | 72 | 82 | 77 |
| `CMS/admin/views/hub/template-edit/main-column.php` | Hub-Edit-Hauptspalte | Parent-View | 86 | 90 | 84 | 87 |
| `CMS/admin/views/hub/template-edit/sidebar-column.php` | Hub-Edit-Seitenspalte | Parent-View | 86 | 90 | 84 | 87 |
| `CMS/admin/views/hub/templates.php` | Hub-Template-Liste | Hub-Module | 86 | 88 | 84 | 86 |
| `CMS/admin/views/landing/page.php` | Landing-Builder-UI | `LandingPageModule`, viele Formblöcke | 80 | 70 | 82 | 78 |
| `CMS/admin/views/legal/cookies.php` | Cookie-UI | `CookieManagerModule` | 82 | 80 | 83 | 82 |
| `CMS/admin/views/legal/data-requests.php` | DSGVO-Export-UI | Legal-Modul | 86 | 88 | 84 | 86 |
| `CMS/admin/views/legal/deletion-requests.php` | Löschanfrage-UI | Legal-Modul | 86 | 88 | 84 | 86 |
| `CMS/admin/views/legal/privacy-requests.php` | Privacy-Request-UI | Legal-Modul | 86 | 88 | 84 | 86 |
| `CMS/admin/views/legal/sites.php` | Rechtstext-UI | `LegalSitesModule` | 82 | 80 | 83 | 82 |
| `CMS/admin/views/media/categories.php` | Medien-Kategorien-UI | `MediaModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/media/library.php` | Medienbibliothek-UI | `MediaModule`, Upload/Picker | 80 | 74 | 82 | 79 |
| `CMS/admin/views/media/settings.php` | Medien-Settings-UI | `MediaModule` | 84 | 84 | 83 | 84 |
| `CMS/admin/views/member/dashboard.php` | Member-Dashboard-UI | `MemberDashboardModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/member/design.php` | Member-Design-UI | Member-Modul | 86 | 88 | 84 | 86 |
| `CMS/admin/views/member/frontend-modules.php` | Member-Frontend-Module-UI | Member-Modul | 86 | 88 | 84 | 86 |
| `CMS/admin/views/member/general.php` | Member-General-UI | Member-Modul | 86 | 88 | 84 | 86 |
| `CMS/admin/views/member/notifications.php` | Member-Notifications-UI | Member-Modul | 86 | 88 | 84 | 86 |
| `CMS/admin/views/member/onboarding.php` | Member-Onboarding-UI | Member-Modul | 86 | 88 | 84 | 86 |
| `CMS/admin/views/member/plugin-widgets.php` | Member-Pluginwidget-UI | Member-Modul | 86 | 88 | 84 | 86 |
| `CMS/admin/views/member/profile-fields.php` | Member-Profilfeld-UI | Member-Modul | 84 | 84 | 83 | 84 |
| `CMS/admin/views/member/subnav.php` | Member-Subnav | Member-Views | 90 | 95 | 85 | 90 |
| `CMS/admin/views/member/widgets.php` | Member-Widgets-UI | Member-Modul | 86 | 88 | 84 | 86 |
| `CMS/admin/views/menus/editor.php` | Menü-Editor-UI | `MenuEditorModule`, viele Formblöcke | 78 | 72 | 82 | 77 |
| `CMS/admin/views/pages/edit.php` | Seiten-Editor-UI | `PagesModule`, SEO-Partials, EditorJS | 78 | 72 | 82 | 77 |
| `CMS/admin/views/pages/list.php` | Seiten-Liste | `PagesModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/partials/content-advanced-seo-panel.php` | SEO-Advanced-Panel | Posts/Pages-Edit | 90 | 95 | 85 | 90 |
| `CMS/admin/views/partials/content-preview-card.php` | SERP/Social-Preview | Posts/Pages-Edit | 90 | 95 | 85 | 90 |
| `CMS/admin/views/partials/content-readability-card.php` | Readability-Card | Posts/Pages-Edit | 90 | 95 | 85 | 90 |
| `CMS/admin/views/partials/content-seo-score-panel.php` | SEO-Score-Panel | Posts/Pages-Edit | 90 | 95 | 85 | 90 |
| `CMS/admin/views/partials/empty-table-row.php` | Empty-State-Zeile | Tabellen-Views | 90 | 95 | 85 | 90 |
| `CMS/admin/views/partials/featured-image-picker.php` | Featured-Image-Picker | Posts/Pages-Edit, Media | 86 | 90 | 84 | 87 |
| `CMS/admin/views/partials/flash-alert.php` | Flash-Alert-Partial | viele Admin-Views | 90 | 95 | 85 | 90 |
| `CMS/admin/views/partials/section-subnav.php` | Section-Subnav | SEO/System/Performance-Views | 90 | 95 | 85 | 90 |
| `CMS/admin/views/performance/cache.php` | Cache-UI | `PerformanceModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/performance/database.php` | DB-Perf-UI | `PerformanceModule` | 84 | 84 | 83 | 84 |
| `CMS/admin/views/performance/media.php` | Media-Perf-UI | `PerformanceModule` | 84 | 84 | 83 | 84 |
| `CMS/admin/views/performance/sessions.php` | Sessions-UI | `PerformanceModule` | 84 | 84 | 83 | 84 |
| `CMS/admin/views/performance/settings.php` | Perf-Settings-UI | `PerformanceModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/performance/subnav.php` | Performance-Subnav | Performance-Views | 90 | 95 | 85 | 90 |
| `CMS/admin/views/plugins/list.php` | Plugin-Liste | `PluginsModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/plugins/marketplace.php` | Plugin-Marketplace-UI | Marketplace-Modul, Remote | 82 | 80 | 83 | 82 |
| `CMS/admin/views/posts/categories.php` | Post-Kategorien-UI | Posts/Page-Module | 86 | 88 | 84 | 86 |
| `CMS/admin/views/posts/edit.php` | Beitrags-Editor-UI | `PostsModule`, SEO-Partials, EditorJS | 76 | 70 | 82 | 76 |
| `CMS/admin/views/posts/list.php` | Beitrags-Liste | `PostsModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/posts/tags.php` | Post-Tags-UI | `PostsModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/security/antispam.php` | Antispam-UI | `AntispamModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/security/audit.php` | Security-Audit-UI | `SecurityAuditModule` | 84 | 84 | 83 | 84 |
| `CMS/admin/views/security/firewall.php` | Firewall-UI | `FirewallModule` | 87 | 84 | 85 | 85 |
| `CMS/admin/views/seo/analytics.php` | SEO-Analytics-UI | `AnalyticsModule` | 84 | 82 | 83 | 83 |
| `CMS/admin/views/seo/audit.php` | SEO-Audit-UI | SEO-Suite | 84 | 82 | 83 | 83 |
| `CMS/admin/views/seo/dashboard.php` | SEO-Dashboard-UI | SEO-Module | 86 | 88 | 84 | 86 |
| `CMS/admin/views/seo/meta.php` | SEO-Meta-UI | SEO-Suite | 86 | 88 | 84 | 86 |
| `CMS/admin/views/seo/not-found.php` | 404/Redirect-UI | Redirect-Modul | 84 | 84 | 83 | 84 |
| `CMS/admin/views/seo/performance.php` | SEO-Perf-UI | Performance-/SEO-Modul | 84 | 82 | 83 | 83 |
| `CMS/admin/views/seo/redirects.php` | Redirect-UI | Redirect-Modul | 84 | 84 | 83 | 84 |
| `CMS/admin/views/seo/schema.php` | Schema-UI | SEO-Suite | 86 | 88 | 84 | 86 |
| `CMS/admin/views/seo/sitemap.php` | Sitemap-UI | SEO-Suite, Indexing | 84 | 84 | 83 | 84 |
| `CMS/admin/views/seo/social.php` | SEO-Social-UI | SEO-Suite | 86 | 88 | 84 | 86 |
| `CMS/admin/views/seo/subnav.php` | SEO-Subnav | SEO-Views | 90 | 95 | 85 | 90 |
| `CMS/admin/views/seo/technical.php` | SEO-Technical-UI | SEO-Suite, Redirects | 84 | 82 | 83 | 83 |
| `CMS/admin/views/settings/general.php` | General-Settings-UI | `SettingsModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/subscriptions/orders.php` | Orders-UI | `OrdersModule` | 90 | 92 | 90 | 91 |
| `CMS/admin/views/subscriptions/packages.php` | Packages-UI | `PackagesModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/subscriptions/settings.php` | Billing-Settings-UI | Subscription-Settings-Modul | 86 | 87 | 85 | 86 |
| `CMS/admin/views/system/backups.php` | Backup-UI | `BackupsModule` | 84 | 84 | 83 | 84 |
| `CMS/admin/views/system/cron-status.php` | Cron-Status-UI | System-/Performance-Modul | 88 | 92 | 84 | 88 |
| `CMS/admin/views/system/diagnose.php` | Diagnose-UI | System-Modul | 88 | 92 | 84 | 88 |
| `CMS/admin/views/system/disk-usage.php` | Disk-Usage-UI | System-/FS-Daten | 88 | 90 | 84 | 87 |
| `CMS/admin/views/system/documentation.php` | Doku-UI | Doku-Module, Renderer | 88 | 87 | 89 | 88 |
| `CMS/admin/views/system/email-alerts.php` | Email-Alerts-UI | System-/Log-Daten | 88 | 92 | 84 | 88 |
| `CMS/admin/views/system/health-check.php` | Health-Check-UI | System-Modul | 88 | 92 | 84 | 88 |
| `CMS/admin/views/system/info.php` | Systeminfo-UI | SystemInfo-Modul | 88 | 92 | 84 | 88 |
| `CMS/admin/views/system/mail-settings.php` | Mail-Settings-UI | Mail-Modul | 89 | 89 | 89 | 89 |
| `CMS/admin/views/system/response-time.php` | Response-Time-UI | Perf-/System-Modul | 88 | 92 | 84 | 88 |
| `CMS/admin/views/system/scheduled-tasks.php` | Scheduled-Tasks-UI | System-Modul | 88 | 92 | 84 | 88 |
| `CMS/admin/views/system/subnav.php` | System-Subnav | System-Views | 90 | 95 | 85 | 90 |
| `CMS/admin/views/system/support.php` | Support-UI | Support-Modul | 88 | 92 | 84 | 88 |
| `CMS/admin/views/system/updates.php` | Updates-UI | `UpdatesModule` | 84 | 84 | 83 | 84 |
| `CMS/admin/views/tables/edit.php` | Tabellen-Editor-UI | `TablesModule` | 82 | 80 | 83 | 82 |
| `CMS/admin/views/tables/list.php` | Tabellen-Liste | `TablesModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/tables/settings.php` | Tabellen-Settings-UI | `TablesModule` | 84 | 84 | 83 | 84 |
| `CMS/admin/views/themes/editor.php` | Theme-Editor-UI | Theme-Editor-Modul, FS | 80 | 76 | 84 | 80 |
| `CMS/admin/views/themes/fonts.php` | Theme-Fonts-UI | Font-Modul | 84 | 84 | 83 | 84 |
| `CMS/admin/views/themes/list.php` | Theme-Liste | `ThemesModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/themes/marketplace.php` | Theme-Marketplace-UI | Marketplace-Modul, Remote | 82 | 80 | 83 | 82 |
| `CMS/admin/views/themes/settings.php` | Theme-Settings-UI | Theme-Modul | 86 | 88 | 84 | 86 |
| `CMS/admin/views/toc/settings.php` | TOC-Settings-UI | `TocModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/users/edit.php` | User-Edit-UI | `UsersModule` | 84 | 84 | 83 | 84 |
| `CMS/admin/views/users/groups.php` | User-Groups-UI | `GroupsModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/users/list.php` | User-Liste | `UsersModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/users/roles.php` | User-Roles-UI | `RolesModule` | 86 | 88 | 84 | 86 |
| `CMS/admin/views/users/settings.php` | User-Settings-UI | `UserSettingsModule` | 86 | 88 | 84 | 86 |

## Core & Includes

### Bewertung

| Datei | Kurzbeschreibung | Verknüpfung/Abhängigkeiten | Security | Speed | PHP/BP | Gesamt |
|---|---|---|---:|---:|---:|---:|
| `core/Api.php` | API-Fassade für Kernendpunkte und JSON-Antworten. | Router, Auth, Json, Service-Layer | 72 | 78 | 80 | 77 |
| `core/AuditLogger.php` | Protokolliert sicherheits- und administrationsrelevante Ereignisse. | Logger, Database, Security, Konfiguration | 84 | 80 | 84 | 83 |
| `core/Auth.php` | Kompakter Zugriffspunkt für Authentifizierungslogik. | AuthManager, Security, Session, User-Kontext | 74 | 82 | 80 | 79 |
| `core/Auth/AuthManager.php` | Orchestriert Login, Session und Auth-Provider. | Database, Security, UserService, Session | 72 | 80 | 84 | 79 |
| `core/Auth/LDAP/LdapAuthProvider.php` | Bindet einen LDAP-Provider für externe Anmeldung an. | AuthManager, LDAP-Erweiterung, Konfiguration | 66 | 74 | 80 | 73 |
| `core/Auth/MFA/BackupCodesManager.php` | Verwaltet Fallback-Codes für MFA-Szenarien. | Security, Database, User-Kontext | 70 | 82 | 84 | 79 |
| `core/Auth/MFA/TotpAdapter.php` | Adapter für TOTP-basierte Mehrfaktorprüfung. | Totp, Security, AuthManager | 71 | 83 | 85 | 80 |
| `core/Auth/Passkey/WebAuthnAdapter.php` | Adapter für Passkey/WebAuthn-Authentifizierung. | AuthManager, Security, WebAuthn-Bibliothek | 69 | 76 | 83 | 76 |
| `core/autoload.php` | Lädt Kernklassen automatisch nach Namenskonvention. | Bootstrap, Dateisystem, Klassenpfade | 88 | 90 | 76 | 85 |
| `core/Bootstrap.php` | Startet den Kern, lädt Abhängigkeiten und initialisiert Routing. | autoload, Container, Router, includes/functions.php | 78 | 76 | 84 | 79 |
| `core/CacheManager.php` | Vereinheitlicht Cache-Zugriffe und Cache-Backends. | CacheInterface, Konfiguration, Dateisystem | 86 | 88 | 86 | 87 |
| `core/Container.php` | Einfacher DI-/Service-Container für Kernobjekte. | Bootstrap, Services, Konfiguration | 84 | 87 | 86 | 86 |
| `core/Contracts/CacheInterface.php` | Vertrag für Cache-Implementierungen. | CacheManager, konkrete Cache-Backends | 94 | 95 | 92 | 94 |
| `core/Contracts/DatabaseInterface.php` | Vertrag für Datenbankzugriffe im Kern. | Database, Repositories, Services | 94 | 95 | 92 | 94 |
| `core/Contracts/LoggerInterface.php` | Vertrag für Logging-Implementierungen. | Logger, AuditLogger, ErrorReportService | 94 | 95 | 92 | 94 |
| `core/Database.php` | Zentrale Datenbankabstraktion für Queries und Verbindungen. | PDO, Konfiguration, Repositories, Services | 72 | 74 | 82 | 76 |
| `core/Debug.php` | Hilfslogik für Debug-Ausgaben und Diagnose. | Logger, Konfiguration, Laufzeitstatus | 82 | 84 | 78 | 81 |
| `core/Hooks.php` | Event-/Filter-System für Core, Themes und Plugins. | Bootstrap, PluginManager, ThemeManager | 84 | 88 | 84 | 85 |
| `core/Http/Client.php` | HTTP-Client für externe Requests und APIs. | cURL/Streams, Konfiguration, externe Dienste | 70 | 75 | 82 | 76 |
| `core/Json.php` | Kleine Hilfsschicht für JSON-Encoding und -Decoding. | Api, Services, PHP-JSON | 88 | 90 | 84 | 87 |
| `core/Logger.php` | Basislogger für Fehler, Hinweise und Betriebsdaten. | LoggerInterface, Dateisystem, Konfiguration | 85 | 83 | 83 | 84 |
| `core/Member/PluginDashboardRegistry.php` | Registriert Plugin-Widgets für das Member-Dashboard. | Hooks, PluginManager, MemberRouter | 86 | 88 | 84 | 86 |
| `core/MigrationManager.php` | Führt Schema- und Datenmigrationen zentral aus. | Database, SchemaManager, UpdateService | 74 | 70 | 82 | 75 |
| `core/PageManager.php` | Verwaltert Seitenlogik und Seitentemplates im Kern. | ThemeManager, Router, Database | 80 | 82 | 82 | 81 |
| `core/PluginManager.php` | Lädt, aktiviert und koordiniert Plugins. | Hooks, Bootstrap, Database, Dateisystem | 78 | 80 | 84 | 81 |
| `core/Router.php` | Gemeinsame Routing-Basis für unterschiedliche Kontexte. | PublicRouter, AdminRouter, ApiRouter, ThemeRouter | 78 | 82 | 84 | 81 |
| `core/Routing/AdminRouter.php` | Routet Admin-Anfragen in den Backend-Kontext. | Router, Auth, Security, Admin-Module | 74 | 80 | 82 | 79 |
| `core/Routing/ApiRouter.php` | Routet API-Anfragen und Übergaben an Handler. | Router, Api, Auth, Json | 72 | 79 | 82 | 78 |
| `core/Routing/MemberRouter.php` | Routet Anfragen für den Mitgliederbereich. | Router, Auth, MemberService, Themes/Plugins | 75 | 80 | 82 | 79 |
| `core/Routing/PublicRouter.php` | Öffentliche Frontend-Routen für Seiten und Inhalte. | Router, ThemeRouter, PageManager | 77 | 80 | 82 | 80 |
| `core/Routing/ThemeArchiveRepository.php` | Liefert Archivdaten für Theme-Routen. | ThemeRouter, Database, PermalinkService | 82 | 81 | 84 | 82 |
| `core/Routing/ThemeRouter.php` | Verbindet Frontend-Routing mit Theme-Templates. | PublicRouter, ThemeManager, PermalinkService | 78 | 79 | 84 | 80 |
| `core/SchemaManager.php` | Verwaltet DB-Schemas und strukturierte Tabellenänderungen. | Database, MigrationManager, UpdateService | 76 | 74 | 82 | 77 |
| `core/Security.php` | Stellt Kernfunktionen für Tokens und Schutzmechanismen bereit. | Auth, Session, CSRF/Token-Logik | 76 | 78 | 84 | 79 |
| `core/Services/AnalyticsService.php` | Bündelt Metriken und einfache Analytics-Auswertungen. | Database, TrackingService, Seo/Reporting | 76 | 76 | 82 | 78 |
| `core/Services/AzureMailTokenProvider.php` | Beschafft Zugriffstoken für Azure-/Graph-Mailversand. | GraphApiService, Konfiguration, externe APIs | 82 | 74 | 85 | 80 |
| `core/Services/BackupService.php` | Erstellt Sicherungen von Daten oder Dateien. | Database, Dateisystem, Zip/Archiv | 78 | 75 | 83 | 79 |
| `core/Services/CommentService.php` | Verarbeitet Kommentarlogik und zugehörige Aktionen. | Database, Security, Content/Member-Kontext | 84 | 80 | 85 | 83 |
| `core/Services/ContentLocalizationService.php` | Unterstützt Lokalisierung von Inhalten und Varianten. | TranslationService, Database, Routing | 82 | 80 | 84 | 82 |
| `core/Services/CookieConsentService.php` | Verwaltet Consent-Status und Cookie-Banner-Logik. | Security, SettingsService, Frontend-Hooks | 83 | 82 | 84 | 83 |
| `core/Services/CoreWebVitalsService.php` | Aggregiert oder bewertet Performance-Signale. | AnalyticsService, TrackingService, Frontend-Metriken | 85 | 72 | 84 | 80 |
| `core/Services/DashboardService.php` | Liefert Daten und Bausteine für Dashboards. | Database, Hooks, PluginDashboardRegistry | 80 | 82 | 84 | 82 |
| `core/Services/EditorJs/EditorJsAssetService.php` | Liefert Assets und Konfiguration für Editor.js. | EditorJsService, Theme/Asset-Layer, Dateisystem | 84 | 86 | 84 | 85 |
| `core/Services/EditorJs/EditorJsImageLibraryService.php` | Bindet Bildbibliothek in Editor.js ein. | MediaService, EditorJsMediaService, Uploads | 79 | 78 | 83 | 80 |
| `core/Services/EditorJs/EditorJsMediaService.php` | Vermittelt Mediendaten an Editor.js-Workflows. | MediaService, UploadHandler, RequestGuard | 73 | 76 | 82 | 77 |
| `core/Services/EditorJs/EditorJsRemoteMediaService.php` | Holt oder verarbeitet Remote-Medien für Editor.js. | Http\Client, MediaService, Sanitizer | 86 | 72 | 84 | 80 |
| `core/Services/EditorJs/EditorJsRequestGuard.php` | Prüft Berechtigungen und Request-Schutz für Editor.js. | Auth, Security, MemberService | 86 | 85 | 86 | 86 |
| `core/Services/EditorJs/EditorJsSanitizer.php` | Säubert Editor.js-Blockdaten vor Speicherung/Rendern. | PurifierService, Security, EditorJsService | 90 | 86 | 86 | 87 |
| `core/Services/EditorJs/EditorJsUploadService.php` | Upload-Workflow für Editor.js-Dateien und Bilder. | FileUploadService, MediaService, Security | 69 | 74 | 82 | 75 |
| `core/Services/EditorJsRenderer.php` | Rendert Editor.js-Daten in HTML-Ausgabe. | EditorJsService, Sanitizer, Theme-Templates | 84 | 84 | 84 | 84 |
| `core/Services/EditorJsService.php` | Zentrale Koordination für Editor.js-Inhalte. | Renderer, Sanitizer, AssetService | 78 | 80 | 82 | 80 |
| `core/Services/EditorService.php` | Allgemeiner Editor-Service für Content-Bearbeitung. | EditorJsService, MediaService, SettingsService | 80 | 81 | 82 | 81 |
| `core/Services/ElfinderService.php` | Adapter/Service für elFinder-Dateiverwaltung. | Dateisystem, Auth, externe elFinder-Assets | 68 | 70 | 78 | 72 |
| `core/Services/ErrorReportService.php` | Sammelt und meldet Fehlerberichte strukturiert. | Logger, MailService, AuditLogger | 78 | 76 | 82 | 79 |
| `core/Services/FeatureUsageService.php` | Erfasst oder aggregiert Feature-Nutzungsdaten. | Database, AnalyticsService, PluginManager | 80 | 84 | 84 | 83 |
| `core/Services/FeedService.php` | Verarbeitet externe oder interne Feed-Inhalte. | Http\Client, XML/Feed-Library, Cache | 84 | 76 | 84 | 81 |
| `core/Services/FileUploadService.php` | Kapselt generische Datei-Uploads und Validierung. | Security, Dateisystem, MediaService | 77 | 74 | 84 | 78 |
| `core/Services/GraphApiService.php` | Wrapper für Microsoft Graph-bezogene Aufrufe. | Http\Client, AzureMailTokenProvider, Konfiguration | 80 | 71 | 85 | 79 |
| `core/Services/ImageService.php` | Allzweckservice für Bildverarbeitung und Bildpfade. | ImageProcessor, MediaService, Dateisystem | 76 | 72 | 82 | 77 |
| `core/Services/IndexingService.php` | Reicht URLs/Änderungen an Suchmaschinen-Indizierung weiter. | SitemapService, Http\Client, SEO-Settings | 74 | 77 | 82 | 78 |
| `core/Services/JwtService.php` | Erzeugt und prüft JWTs für API-/Token-Szenarien. | Security, Json, Konfiguration | 68 | 84 | 82 | 78 |
| `core/Services/Landing/LandingDefaultsProvider.php` | Liefert Default-Daten für Landing-Page-Bausteine. | LandingPageService, ThemeManager, Konfiguration | 86 | 88 | 84 | 86 |
| `core/Services/Landing/LandingFeatureService.php` | Baut Feature-Abschnitte für Landing-Pages auf. | LandingRepository, LandingDefaultsProvider, Themes | 82 | 83 | 84 | 83 |
| `core/Services/Landing/LandingHeaderService.php` | Verwaltet Header-/Hero-Daten für Landing-Pages. | LandingRepository, ThemeManager, MediaService | 84 | 85 | 84 | 84 |
| `core/Services/Landing/LandingPluginService.php` | Bindet Plugins in Landing-Page-Module ein. | PluginManager, LandingPageService, Hooks | 76 | 80 | 82 | 79 |
| `core/Services/Landing/LandingRepository.php` | Persistiert Landing-Page-Daten und Konfigurationen. | Database, LandingSectionService, MediaService | 78 | 77 | 84 | 80 |
| `core/Services/Landing/LandingSanitizer.php` | Säubert Landing-Page-Eingaben und Blockdaten. | PurifierService, Security, LandingRepository | 90 | 86 | 86 | 87 |
| `core/Services/Landing/LandingSectionProfileService.php` | Liefert Profil-/Schema-Infos für Landing-Sections. | LandingSectionService, DefaultsProvider, Themes | 81 | 82 | 84 | 82 |
| `core/Services/Landing/LandingSectionService.php` | Verarbeitet einzelne Inhaltssektionen von Landing-Pages. | LandingRepository, Sanitizer, MediaService | 80 | 82 | 84 | 82 |
| `core/Services/LandingPageService.php` | Oberer Service für Landing-Page-Renderlogik und Daten. | Landing-Subservices, ThemeManager, Hooks | 79 | 80 | 84 | 81 |
| `core/Services/MailLogService.php` | Protokolliert Mail-Versand und Zustellstatus. | MailService, Database, Logger | 78 | 80 | 82 | 80 |
| `core/Services/MailQueueService.php` | Verwaltet Warteschlange für asynchronen Mailversand. | MailService, Database, Cron/Queue-Logik | 76 | 78 | 82 | 79 |
| `core/Services/MailService.php` | Zentraler Versandservice für E-Mails. | Konfiguration, Queue/Log, externe Mailer | 88 | 74 | 86 | 83 |
| `core/Services/Media/ImageProcessor.php` | Führt Bildtransformationen und Ableitungen aus. | GD/Imagick, Dateisystem, ImageService | 74 | 70 | 82 | 75 |
| `core/Services/Media/MediaRepository.php` | Persistiert Metadaten von Medienobjekten. | Database, MediaService, Uploads | 78 | 80 | 84 | 81 |
| `core/Services/Media/UploadHandler.php` | Operativer Handler für Datei- und Medienuploads. | FileUploadService, Security, Dateisystem | 69 | 72 | 80 | 74 |
| `core/Services/MediaDeliveryService.php` | Steuert Auslieferung und Pfadauflösung für Medien. | MediaRepository, ImageService, Cache | 74 | 82 | 82 | 79 |
| `core/Services/MediaService.php` | Zentrale Medienverwaltung für Uploads und Abrufe. | MediaRepository, UploadHandler, ImageService | 72 | 76 | 82 | 77 |
| `core/Services/MemberService.php` | Verwaltet Berechtigungen und Daten des Member-Bereichs. | Auth, Database, MemberRouter, Plugins | 74 | 78 | 82 | 78 |
| `core/Services/MessageService.php` | Vereinheitlicht Flash-/Systemmeldungen im Laufzeitkontext. | Session, Router, Admin/Member/UI | 82 | 86 | 84 | 84 |
| `core/Services/OpcacheWarmupService.php` | Wärmt wichtige Skripte/Assets für schnellere Laufzeit auf. | Dateisystem, PHP-OPcache, Bootstrap | 80 | 88 | 82 | 83 |
| `core/Services/PdfService.php` | Erzeugt oder liefert PDF-Dokumente aus. | dompdf/Renderer, Dateisystem, Content-Daten | 70 | 68 | 80 | 73 |
| `core/Services/PermalinkService.php` | Berechnet konsistente URLs und Permalink-Strukturen. | Routing, ThemeRouter, SettingsService | 84 | 84 | 86 | 85 |
| `core/Services/PurifierService.php` | Kapselt HTML-Sanitizing für vertrauensarme Inhalte. | Security, externe Purifier-Library, Editor/Landing | 90 | 80 | 84 | 85 |
| `core/Services/RedirectService.php` | Verwaltet Redirect-Regeln und deren Auflösung. | Database, Router, SettingsService | 74 | 84 | 84 | 81 |
| `core/Services/SearchService.php` | Führt Suchabfragen aus und bereitet Treffer auf. | Database oder TNTSearch, Cache, Routing | 78 | 70 | 82 | 77 |
| `core/Services/SeoAnalysisService.php` | Analysiert Inhalte auf SEO-/Lesbarkeitsaspekte. | SEOService, Content-Daten, Analyzer-Logik | 84 | 76 | 84 | 81 |
| `core/Services/SEO/SeoAnalyticsRenderer.php` | Rendert SEO-bezogene Kennzahlen für UI/Reports. | SeoAnalysisService, DashboardService | 82 | 84 | 84 | 83 |
| `core/Services/SEO/SeoAuditService.php` | Führt SEO-Audits über Inhalte und Metadaten aus. | SeoMetaService, SitemapService, AnalysisService | 82 | 76 | 84 | 81 |
| `core/Services/SEO/SeoHeadRenderer.php` | Rendert Meta-Tags und Head-Ausgaben. | SeoMetaService, Theme-Hooks, SettingsStore | 84 | 86 | 84 | 85 |
| `core/Services/SEO/SeoMetaRepository.php` | Persistiert SEO-Metadaten in der Datenhaltung. | Database, SeoMetaService, Content-Daten | 79 | 80 | 84 | 81 |
| `core/Services/SEO/SeoMetaService.php` | Verarbeitet SEO-Metaobjekte und Standardwerte. | SeoMetaRepository, SettingsStore, HeadRenderer | 82 | 82 | 84 | 83 |
| `core/Services/SEO/SeoSchemaRenderer.php` | Rendert strukturierte Daten für Suchmaschinen. | SeoMetaService, Theme-Hooks, JSON-LD | 84 | 86 | 84 | 85 |
| `core/Services/SEO/SeoSettingsStore.php` | Kapselt Speicherung und Zugriff auf SEO-Settings. | SettingsService, Database, SEOService | 80 | 82 | 84 | 82 |
| `core/Services/SEO/SeoSitemapService.php` | Baut SEO-orientierte Sitemaps und zugehörige Daten. | SitemapService, Routing, SEO-Settings | 84 | 84 | 84 | 84 |
| `core/Services/SEOService.php` | Übergeordneter Service für SEO-Konfiguration und Abläufe. | Seo*-Services, SettingsService, Hooks | 80 | 81 | 84 | 82 |
| `core/Services/SettingsService.php` | Zentraler Zugriff auf Laufzeit- und Systemsettings. | Database, Konfiguration, Cache | 80 | 80 | 84 | 81 |
| `core/Services/SitemapService.php` | Erstellt und verwaltet XML-/Seiten-Sitemaps. | Routing, PermalinkService, SEOService | 84 | 82 | 84 | 83 |
| `core/Services/SiteTable/SiteTableDisplaySettings.php` | Hält Anzeigeeinstellungen für Site-Tabellen zusammen. | SiteTableService, SettingsService, UI-Renderer | 84 | 86 | 84 | 85 |
| `core/Services/SiteTable/SiteTableHubRenderer.php` | Rendert Hub-/Übersichtsansichten für Site-Tabellen. | SiteTableRepository, TemplateRegistry, Theme/UI | 82 | 84 | 84 | 83 |
| `core/Services/SiteTable/SiteTableRepository.php` | Datenzugriffsschicht für Site-Tabellen. | Database, SiteTableService | 79 | 79 | 84 | 81 |
| `core/Services/SiteTable/SiteTableTableRenderer.php` | Rendert tabellarische Ausgaben für Site-Daten. | Repository, DisplaySettings, Templates | 84 | 85 | 84 | 84 |
| `core/Services/SiteTable/SiteTableTemplateRegistry.php` | Registriert verfügbare Templates für Site-Tabellen. | Renderer, ThemeManager, PluginManager | 84 | 87 | 84 | 85 |
| `core/Services/SiteTableService.php` | Oberer Service für Site-Tabellen und Darstellung. | Repository, Renderer, Settings | 80 | 81 | 84 | 82 |
| `core/Services/StatusService.php` | Liefert kompakte Status-/Gesundheitsinformationen des Systems. | SystemService, UpdateService, Cache | 84 | 88 | 84 | 85 |
| `core/Services/SystemService.php` | Sammelt Systeminfos und Kernzustände. | StatusService, Konfiguration, Dateisystem | 82 | 80 | 84 | 82 |
| `core/Services/ThemeCustomizer.php` | Bindet Theme-Einstellungen und CSS-Generierung ein. | ThemeManager, SettingsService, Themes | 80 | 80 | 84 | 81 |
| `core/Services/TrackingService.php` | Erfasst Tracking-Ereignisse und einfache Nutzungsdaten. | AnalyticsService, CookieConsentService, Database | 74 | 78 | 82 | 78 |
| `core/Services/TranslationService.php` | Verwaltet Übersetzungen und sprachabhängige Inhalte. | includes/functions/translation.php, Settings, Content | 84 | 82 | 84 | 83 |
| `core/Services/UpdateService.php` | Steuert Update-Prüfung, Versionierung und Rollout-Schritte. | Version, MigrationManager, Netzwerk/Dateisystem | 72 | 74 | 82 | 76 |
| `core/Services/UserService.php` | Liefert Benutzerlogik jenseits des reinen Auth-Flows. | Database, AuthManager, MemberService | 74 | 79 | 82 | 78 |
| `core/SubscriptionManager.php` | Verwaltet Abos bzw. Subskriptionszustände zentral. | Database, MailService, subscription-helpers | 76 | 80 | 82 | 79 |
| `core/TableOfContents.php` | Erstellt Inhaltsverzeichnisse aus Content-Strukturen. | Editor/Content-Rendering, HTML-Parsing | 86 | 84 | 84 | 85 |
| `core/ThemeManager.php` | Lädt Themes, Assets und Template-Pfade. | Hooks, ThemeRouter, SettingsService, Dateisystem | 82 | 84 | 84 | 83 |
| `core/Totp.php` | Basiskomponente für TOTP-Berechnung und Prüfung. | Security, MFA-Adapter, Zeit-/Hashfunktionen | 74 | 84 | 82 | 80 |
| `core/VendorRegistry.php` | Registriert externe Pakete/Assets des Systems. | autoload, Bootstrap, Dateisystem | 84 | 88 | 84 | 85 |
| `core/Version.php` | Hält Versionsinformationen und Vergleichslogik bereit. | UpdateService, Bootstrap | 90 | 94 | 86 | 90 |
| `core/WP_Error.php` | Kompatibilitätshülle für WordPress-artige Fehlerobjekte. | wordpress-compat, Api, Services | 88 | 92 | 78 | 86 |
| `includes/functions.php` | Kanonischer Sammel-Loader für globale Hilfsfunktionen. | Bootstrap, functions/*.php, Core-Helfer | 80 | 84 | 76 | 80 |
| `includes/functions/admin-menu.php` | Hilfsfunktionen rund um Menüaufbau im Adminbereich. | Hooks, Theme/Plugin-Menüs, Router | 84 | 88 | 78 | 83 |
| `includes/functions/escaping.php` | Sammlung für sicheres Escaping in Ausgabe-Kontexten. | Security, Templates, globale Helfer | 92 | 92 | 84 | 89 |
| `includes/functions/mail.php` | Globale Mail-Helfer als dünne Komfortschicht. | MailService, Konfiguration, Queue/Log | 74 | 80 | 76 | 77 |
| `includes/functions/options-runtime.php` | Runtime-Helfer zum Lesen/Schreiben von Optionen. | SettingsService, Database, Cache | 82 | 86 | 78 | 82 |
| `includes/functions/redirects-auth.php` | Hilfsfunktionen für Auth-Redirects und Zielpfade. | Auth, Router, Security | 74 | 82 | 76 | 77 |
| `includes/functions/roles.php` | Rollen- und Berechtigungshelfer im globalen Kontext. | Auth, MemberService, Security | 82 | 88 | 78 | 83 |
| `includes/functions/translation.php` | Sprach- und Übersetzungshelfer für Templates/Core. | TranslationService, SettingsService | 86 | 88 | 80 | 85 |
| `includes/functions/wordpress-compat.php` | Kompatibilitätsschicht für WP-nahe Helper-APIs. | WP_Error, globale Helper, Legacy-Aufrufer | 84 | 86 | 74 | 81 |
| `includes/subscription-helpers.php` | Ergänzende Hilfsfunktionen für Abo-/Subscription-Flows. | SubscriptionManager, MailService, Database | 80 | 84 | 78 | 81 |

## Restliche Bereiche

### Bewertung

| Datei | Kurzbeschreibung | Verknüpfung/Abhängigkeiten | Security | Speed | PHP/BP | Gesamt |
| --- | --- | --- | ---: | ---: | ---: | ---: |
| `.htaccess` | Apache-Schutz und URL-Rewrite im CMS-Root | Apache `mod_rewrite`; Webserver-Zugriffsschutz | 80 | 82 | 75 | 79 |
| `assets/css/admin-hub-site-edit.css` | Styles für Hub-Site-Editor | Admin-Hub-UI; SunEditor-Markup | 88 | 78 | 72 | 79 |
| `assets/css/admin-hub-template-edit.css` | Styles für Hub-Template-Bearbeitung | Hub-Template-Preview; Admin-Editor | 88 | 78 | 76 | 81 |
| `assets/css/admin-hub-template-editor.css` | Styles für Hub-Template-Preview/Editor | Hub-Template-Renderer; Preview-Tokens | 88 | 78 | 76 | 81 |
| `assets/css/admin-tabler.css` | Tabler-Overrides und Admin-Branding | Tabler-Core; Admin-Layout | 88 | 78 | 78 | 81 |
| `assets/css/admin.css` | Allgemeine Admin-Komponentenstyles | Admin-UI; Formular- und Card-Komponenten | 88 | 72 | 72 | 77 |
| `assets/css/hub-sites.css` | Frontend-Styles für Hub-Sites | Hub-Site-Ausgabe; CSS-Custom-Properties | 88 | 78 | 72 | 79 |
| `assets/css/main.css` | Haupt-Frontend-Styles | Frontend-Templates; globale UI-Bausteine | 88 | 78 | 76 | 81 |
| `assets/css/member-dashboard.css` | Styles für Member-Dashboard | Member-Seiten; Design-Tokens | 88 | 78 | 78 | 81 |
| `assets/js/admin-content-editor.js` | Admin-Content-Editor-Logik | DOM; Editor-Formulare; JSON-Payloads | 62 | 79 | 80 | 74 |
| `assets/js/admin-grid.js` | Grid.js-Helfer für Admin-Tabellen | Grid.js; serverseitige Tabellen | 72 | 79 | 84 | 78 |
| `assets/js/admin-hub-site-edit.js` | Interaktion für Hub-Site-Bearbeitung | DOM; Hub-Site-Editor; JSON-Daten | 62 | 79 | 80 | 74 |
| `assets/js/admin-hub-template-edit.js` | Interaktion für Template-Bearbeitung | DOM; Hub-Template-Formulare | 62 | 79 | 84 | 75 |
| `assets/js/admin-hub-template-editor.js` | Logik für Template-Editor-Ansicht | DOM; Preview-Payload; Editor-Initialisierung | 62 | 75 | 80 | 72 |
| `assets/js/admin-media-integrations.js` | Einbindung externer Medien-Tools | FilePond; elFinder; Admin-Medien | 62 | 79 | 82 | 74 |
| `assets/js/admin-seo-editor.js` | SEO-Editor-Interaktionen | DOM; SEO-Felder; String-Normalisierung | 62 | 76 | 78 | 72 |
| `assets/js/admin-seo-redirects.js` | Redirect-/SEO-UI-Logik | DOM; Redirect-Konfiguration | 72 | 79 | 84 | 78 |
| `assets/js/admin.js` | Allgemeine Admin-JavaScript-Basis | Admin-UI; globale DOM-Interaktion | 72 | 79 | 84 | 78 |
| `assets/js/cookieconsent-init.js` | Initialisierung des Cookie-Consent-Banners | Frontend; CookieConsent; DOM | 72 | 79 | 80 | 77 |
| `assets/js/editor-init.js` | Initialisierung von Editor.js | Editor.js; HTML-zu-Blocks-Konvertierung | 62 | 79 | 86 | 76 |
| `assets/js/gridjs-init.js` | Grid.js-Initialisierungshilfe | Grid.js; serverseitige Data-Endpoints | 62 | 79 | 79 | 73 |
| `assets/js/member-dashboard.js` | Interaktionen im Member-Dashboard | DOM; Member-UI; WebAuthn/Base64-Helper | 72 | 79 | 84 | 78 |
| `assets/js/photoswipe-init.js` | Initialisierung der Lightbox | PhotoSwipe; Frontend-Galerien | 72 | 79 | 84 | 78 |
| `assets/js/web-vitals.js` | Versand/Erfassung von Web-Vitals | Browser-Performance-APIs; Telemetrie-Endpoint | 72 | 76 | 80 | 76 |
| `config.php` | Bootstrap-Stub für Konfiguration | `config/app.php`; `CacheManager`; Installer-Redirect | 81 | 67 | 86 | 78 |
| `config/.htaccess` | Webzugriffsschutz für Config-Verzeichnis | Apache-Zugriffsschutz; sensible Konfigs | 80 | 82 | 75 | 79 |
| `config/app.php` | Anwendungs-Konfigurationsvorlage | Bootstrap; DB-/App-Konstanten; Installer | 82 | 67 | 86 | 78 |
| `config/media-meta.json` | Medienkategorien und Metadaten | Medienverwaltung; Kategorie-Mapping | 82 | 85 | 82 | 83 |
| `config/media-settings.json` | Medien-Upload- und Typ-Einstellungen | Medienservice; Upload-Regeln | 82 | 85 | 82 | 83 |
| `cron.php` | CLI/Web-Cron-Endpunkt | `config.php`; `Bootstrap`; `MailQueueService`; `SettingsService`; `CMS\Hooks` | 76 | 74 | 82 | 77 |
| `index.php` | Haupt-Entry-Point des CMS | `config.php`; Autoloader; `CMS\Bootstrap`; Theme-Error-Fallback | 78 | 67 | 82 | 76 |
| `install.php` | Startpunkt des Installers | Installer-Bootstrap; Install-Controller | 75 | 67 | 82 | 75 |
| `install/InstallerController.php` | Steuerung des Installationsablaufs | `InstallerService`; PDO; Install-Views; Session | 80 | 68 | 94 | 81 |
| `install/InstallerService.php` | Service für Setup, Config und DB-Anlage | PDO; Dateisystem; Config-Erzeugung; Tabellenanlage | 80 | 67 | 90 | 79 |
| `install/views/admin.php` | Installer-View für Admin-Anlage | Installer-Controller; HTML-Formular | 75 | 68 | 82 | 75 |
| `install/views/blocked.php` | Installer-Blockierungsansicht | Installer-Guard; statische View | 75 | 68 | 82 | 75 |
| `install/views/database.php` | Installer-View für Datenbankdaten | Installer-Controller; Formularschritt DB | 75 | 68 | 82 | 75 |
| `install/views/site.php` | Installer-View für Site-Konfiguration | Installer-Controller; Formularschritt Site | 75 | 68 | 82 | 75 |
| `install/views/success.php` | Erfolgsansicht nach Installation | Installer-Session; Abschlussseite | 75 | 68 | 82 | 75 |
| `install/views/update.php` | Update-/Reinstall-Ansicht | Installer-Service; Formularschritt Update | 75 | 68 | 82 | 75 |
| `install/views/welcome.php` | Willkommens- und Vorabcheck-Ansicht | Installer-Controller; Systemchecks | 75 | 68 | 82 | 75 |
| `lang/de.yaml` | Deutsche Übersetzungsstrings | Lokalisierung; UI-Text-Mapping | 84 | 85 | 80 | 83 |
| `lang/en.yaml` | Englische Übersetzungsstrings | Lokalisierung; UI-Text-Mapping | 84 | 85 | 80 | 83 |
| `logs/.gitignore` | Hält Log-Verzeichnis im Repo schlank | Git; Laufzeit-Logs | 80 | 82 | 75 | 79 |
| `logs/.htaccess` | Sperrt Webzugriff auf Logs | Apache-Zugriffsschutz; Laufzeit-Logs | 80 | 82 | 75 | 79 |
| `member/dashboard.php` | Dashboard-Seite im Member-Bereich | `member/includes/bootstrap.php`; Member-Services | 81 | 67 | 86 | 78 |
| `member/favorites.php` | Favoriten-Seite im Member-Bereich | Member-Bootstrap; Favoriten-Daten | 81 | 67 | 86 | 78 |
| `member/includes/bootstrap.php` | Minimaler Loader für Member-Bereich | `class-member-controller.php` | 75 | 67 | 82 | 75 |
| `member/includes/class-member-controller.php` | Zentraler Controller für Member-Funktionen | Auth; Database; Security; Hooks; Member-/Message-/MFA-/Passkey-Services | 68 | 67 | 86 | 74 |
| `member/media.php` | Medienseite für Mitglieder | Member-Bootstrap; MediaService; Form-Handling | 88 | 67 | 90 | 82 |
| `member/messages.php` | Nachrichtenansicht für Mitglieder | Member-Bootstrap; MessageService; Form-Handling | 82 | 67 | 90 | 80 |
| `member/notifications.php` | Benachrichtigungseinstellungen | Member-Bootstrap; MemberService; Form-Handling | 88 | 67 | 90 | 82 |
| `member/partials/alerts.php` | Alert-Partial für Member-Seiten | `MemberController::consumeFlash()` | 81 | 68 | 86 | 78 |
| `member/partials/footer.php` | Footer-Partial für Member-Seiten | `CMS\Hooks`; Seitenassets; Scripts | 81 | 68 | 86 | 78 |
| `member/partials/header.php` | Header-Partial für Member-Seiten | `CMS\Hooks`; Controller; Design-Tokens | 81 | 68 | 86 | 78 |
| `member/partials/plugin-not-found.php` | Fallback-Partial für fehlende Plugins | Member-Plugin-Sektion; statische Ausgabe | 81 | 68 | 86 | 78 |
| `member/partials/sidebar.php` | Sidebar-Partial im Member-Bereich | `MemberController`; Menüdaten | 81 | 68 | 86 | 78 |
| `member/plugin-section.php` | Plugin-Sektion im Member-Bereich | Member-Bootstrap; `CMS\Hooks` | 81 | 67 | 86 | 78 |
| `member/privacy.php` | Datenschutzseite für Mitglieder | Member-Bootstrap; Export-/Delete-Formulare | 88 | 67 | 90 | 82 |
| `member/profile.php` | Profilseite für Mitglieder | Member-Bootstrap; Profilformular | 88 | 67 | 90 | 82 |
| `member/security.php` | Sicherheitsseite für Mitglieder | Member-Bootstrap; Passwort/TOTP/Passkeys | 88 | 67 | 90 | 82 |
| `member/subscription.php` | Abo- und Bestellübersicht | Member-Bootstrap; Bestell-/Plan-Daten | 81 | 67 | 86 | 78 |
| `orders.php` | Öffentliche Checkout-/Bestellseite | Auth; Database; Security; SubscriptionManager; ThemeManager | 74 | 67 | 90 | 77 |
| `plugins/cms-importer/admin/log.php` | Admin-View für Import-Protokolle | Importer-Admin; Formular-/Logausgabe | 88 | 68 | 76 | 77 |
| `plugins/cms-importer/admin/page.php` | Haupt-Admin-View des Importers | Importer-Admin; Upload-/Import-UI | 88 | 68 | 76 | 77 |
| `plugins/cms-importer/assets/css/importer.css` | Styles für Importer-Admin | Importer-Admin-UI; CSS-Tokens | 88 | 78 | 82 | 83 |
| `plugins/cms-importer/assets/js/importer.js` | Admin-JS für Import-Workflow | DOM; Upload; Preview; Import-Requests | 62 | 79 | 80 | 74 |
| `plugins/cms-importer/cms-importer.php` | Plugin-Bootstrap des Importers | `CMS\Hooks`; Admin-Klasse; Importer-/Parser-Klassen | 81 | 67 | 86 | 78 |
| `plugins/cms-importer/includes/class-admin.php` | Admin-Controller des Importers | `CMS\Hooks`; Upload/AJAX; Admin-Views | 74 | 67 | 90 | 77 |
| `plugins/cms-importer/includes/class-importer.php` | Kernlogik und DB-Zugriff des Importers | Import-DB; Datenmapping; Parser; Berichts-Traits | 73 | 63 | 86 | 74 |
| `plugins/cms-importer/includes/class-xml-parser.php` | Parser für WXR- und SEO-JSON-Dateien | XML/JSON-Parsing; Import-Service | 81 | 64 | 86 | 77 |
| `plugins/cms-importer/includes/trait-admin-cleanup.php` | Cleanup-Helfer für Importer-Admin | Importer-Admin; Datenbereinigung | 73 | 67 | 86 | 75 |
| `plugins/cms-importer/includes/trait-importer-preview.php` | Preview- und Planungslogik des Importers | Importer-Service; Vorschau-/Filterlogik | 73 | 68 | 86 | 76 |
| `plugins/cms-importer/includes/trait-importer-reporting.php` | Reporting- und Meta-Helfer | Importer-Service; Markdown-Berichte | 81 | 68 | 86 | 78 |
| `plugins/cms-importer/readme.txt` | Textdoku für das Importer-Plugin | Plugin-Dokumentation; Feature-Überblick | 88 | 86 | 78 | 84 |
| `plugins/cms-importer/reports/EXAMPLE_meta-report.md` | Beispielbericht unbekannter Meta-Felder | Importer-Reporting; Markdown-Demo | 90 | 88 | 82 | 87 |
| `plugins/cms-importer/update.json` | Update-Metadaten des Importer-Plugins | Plugin-Updater; Versionsinfos | 82 | 85 | 82 | 83 |
| `update.json` | Update-Metadaten des CMS-Kerns | Core-Updater; Versionsinfos | 82 | 85 | 82 | 83 |
| `uploads/.gitkeep` | Platzhalter für Upload-Verzeichnis | Laufzeit-Uploads; Repo-Struktur | 80 | 82 | 75 | 79 |
| `uploads/.htaccess` | Schutzregeln für Upload-Dateien | Apache-Headers; Upload-Sicherheit | 80 | 82 | 75 | 79 |

## Gesamtbewertung nach Kategorien

### Aggregierte Matrix

| Kategorie | Dateien | Ø Security | Ø Speed | Ø PHP/BP | Ø Gesamt | Schwächste Dateien | Stärkste Dateien | Audit-Fokus |
|---|---:|---:|---:|---:|---:|---|---|---|
| **Admin – Entry-Points** | 81 | 83,4 | 84,1 | 84,8 | 83,9 | `theme-editor.php`, `theme-marketplace.php` | `diagnose.php`, `index.php`, `info.php`, `support.php`, `system-*.php` | Remote-Zugriffe in Marketplace-/Theme-Entrypoints härten |
| **Admin – Module** | 55 | 82,0 | 74,6 | 84,6 | 80,6 | `DocumentationGitSync.php`, `SeoSuiteModule.php`, `PerformanceModule.php` | `PostsCategoryViewModelBuilder.php`, `SystemInfoModule.php` | Performance- und Qualitäts-Gates für große Module priorisieren |
| **Admin – Layout-Partials** | 4 | 87,5 | 91,0 | 84,3 | 87,5 | `sidebar.php` | `section-page-shell.php` | Bereits stark; nur Regressionen verhindern |
| **Admin – Views** | 89 | 84,4 | 83,9 | 83,7 | 83,4 | `posts/edit.php`, `pages/edit.php`, `landing/page.php` | `member/subnav.php`, `performance/subnav.php`, `seo/subnav.php` | Editor-Komplexität und Formularpfade weiter entkoppeln |
| **Admin – View-Partials** | 8 | 89,5 | 94,4 | 84,9 | 89,6 | `featured-image-picker.php` | `content-advanced-seo-panel.php`, `content-preview-card.php`, `content-readability-card.php`, `content-seo-score-panel.php` | Sehr guter Standard – als Referenzmuster konservieren |
| **Core – Kernel & Infrastruktur** | 27 | 80,3 | 81,6 | 82,5 | 81,3 | `Http/Client.php`, `Database.php`, `Api.php` | `Version.php`, `CacheManager.php`, `Json.php` | HTTP-Client, DB-Abstraktion und Bootstrap weiter entlasten |
| **Core – Contracts** | 3 | 94,0 | 95,0 | 92,0 | 94,0 | – | alle Contracts | Referenzniveau halten, keine unnötige Aufblähung |
| **Core – Auth Provider & MFA** | 5 | 69,6 | 79,0 | 83,2 | 77,4 | `LdapAuthProvider.php`, `WebAuthnAdapter.php` | `TotpAdapter.php`, `AuthManager.php` | LDAP-, Passkey- und MFA-Randfälle gezielt testen |
| **Core – Routing** | 6 | 76,3 | 79,8 | 82,7 | 79,7 | `ApiRouter.php` | `ThemeArchiveRepository.php` | API-/Routing-Validierung und Request-Härtung vertiefen |
| **Core – Allgemeine Services** | 42 | 80,7 | 77,8 | 83,1 | 79,4 | `RemoteImageService.php`, `RemoteFileService.php`, `DocumentationSyncFilesystem.php` | `PurifierService.php`, `PermalinkService.php`, `StatusService.php` | Externe APIs, Remote-URLs und Fehlerpfade robuster machen |
| **Core – EditorJs Services** | 9 | 81,0 | 80,1 | 83,6 | 81,6 | `EditorJsUploadService.php`, `EditorJsMediaService.php` | `EditorJsSanitizer.php`, `EditorJsRequestGuard.php` | Remote-Media und Upload-Härtung priorisieren |
| **Core – Landing Services** | 9 | 81,8 | 82,6 | 84,0 | 82,7 | `LandingPluginService.php` | `LandingSanitizer.php` | Sanitizer-Qualität halten, Plugin-Integration schärfer absichern |
| **Core – Media Services** | 3 | 73,7 | 74,0 | 82,0 | 76,7 | `UploadHandler.php` | `MediaRepository.php` | Upload-Pfade, Dateitypen und Filesystem-Grenzen prüfen |
| **Core – SEO Services** | 8 | 82,1 | 82,5 | 84,0 | 83,0 | `SeoAuditService.php`, `SeoMetaRepository.php` | `SeoHeadRenderer.php`, `SeoSchemaRenderer.php` | Head-/Schema-Ausgabe weiter als Best Practice festigen |
| **Core – SiteTable Services** | 5 | 82,6 | 84,2 | 84,0 | 83,6 | `SiteTableRepository.php` | `SiteTableDisplaySettings.php`, `SiteTableTemplateRegistry.php` | Renderer-Escaping und Template-Registrierung stabil halten |
| **Includes – Globale Helper** | 9 | 82,0 | 86,0 | 77,8 | 81,9 | `mail.php`, `redirects-auth.php` | `escaping.php` | `escaping.php` als verbindlichen Standard weiterziehen |
| **Includes – Subscription Helper** | 1 | 80,0 | 84,0 | 78,0 | 81,0 | – | `subscription-helpers.php` | Abo-Helfer stärker gegen Komplexitätswachstum absichern |
| **Rest – Root** | 7 | 75,6 | 71,7 | 81,6 | 76,4 | `cron.php` | `update.json` | Root-Entrypoints, speziell `cron.php`, enger absichern |
| **Rest – Assets CSS** | 8 | 88,0 | 77,3 | 75,0 | 80,0 | `admin.css`, `admin-hub-*.css` | mehrere Dateien bei 81 | CSS-Tokens, Breakpoints und Altlasten konsolidieren |
| **Rest – Assets JS** | 15 | 67,2 | 78,5 | 81,7 | 77,3 | `admin-hub-template-editor.js`, `admin-seo-editor.js` | `admin.js`, `admin-seo-redirects.js` | Höchste Frontend-Sicherheitspriorität: DOM-/XSS-Audit |
| **Rest – Config** | 4 | 81,5 | 79,8 | 81,3 | 80,8 | `app.php` | `media-meta.json`, `media-settings.json` | Config-Defaults, Secret-Handling und Upload-Policies überprüfen |
| **Rest – Installer Core** | 2 | 80,0 | 67,5 | 92,0 | 80,0 | – | `InstallerController.php` | Installer-Härtung und Reinstall-/Update-Schutz prüfen |
| **Rest – Installer Views** | 7 | 75,0 | 68,0 | 82,0 | 75,0 | alle annähernd gleich | alle annähernd gleich | Fehlerführung, CSRF und UX im Setup-Prozess nachschärfen |
| **Rest – Sprache** | 2 | 84,0 | 85,0 | 80,0 | 83,0 | – | beide Sprachdateien | Stabile Zone, nur Konsistenzpflege nötig |
| **Rest – Logs** | 2 | 80,0 | 82,0 | 75,0 | 79,0 | – | – | Infrastruktur-Schutz ausreichend, geringe Priorität |
| **Rest – Member Core** | 2 | 71,5 | 67,0 | 84,0 | 74,5 | `class-member-controller.php` | `bootstrap.php` | Member-Controller weiter zerlegen und request-sicher halten |
| **Rest – Member Pages** | 10 | 84,6 | 67,0 | 88,4 | 80,2 | `dashboard.php`, `favorites.php`, `plugin-section.php` | `media.php`, `notifications.php`, `privacy.php`, `profile.php`, `security.php` | Performance-Bottleneck im Member-Bereich untersuchen |
| **Rest – Member Partials** | 5 | 81,0 | 68,0 | 86,0 | 78,0 | alle annähernd gleich | alle annähernd gleich | Partials stabil, aber Speed hängt am umgebenden Rendering |
| **Rest – Importer Admin** | 2 | 88,0 | 68,0 | 76,0 | 77,0 | – | – | Import-UI, Fehlertexte und Upload-Grenzen verbessern |
| **Rest – Importer Core** | 7 | 76,6 | 66,3 | 86,6 | 76,4 | `class-importer.php`, `trait-admin-cleanup.php` | `trait-importer-reporting.php`, `cms-importer.php` | Memory-/Batch-Verhalten und Cleanup-Sicherheit priorisieren |
| **Rest – Importer JS** | 1 | 62,0 | 79,0 | 80,0 | 74,0 | `importer.js` | `importer.js` | DOM-Sicherheit und Request-/Response-Sanitizing prüfen |
| **Rest – Importer CSS** | 1 | 88,0 | 78,0 | 82,0 | 83,0 | `importer.css` | `importer.css` | Geringe Priorität |
| **Rest – Importer Sonstiges** | 3 | 86,7 | 86,3 | 80,7 | 84,7 | `readme.txt` | `EXAMPLE_meta-report.md` | Dokumentationsqualität hoch, nur Sync sauber halten |
| **Rest – Uploads** | 2 | 80,0 | 82,0 | 75,0 | 79,0 | – | – | Upload-Verzeichnis weiter strikt als Infrastruktur-Schutzzone behandeln |

### Auditfazit

Die Gesamtbewertung zeigt ein **solides bis starkes Architektur-Niveau** mit klarer Reife im Backend-Layout, bei den Core-Contracts sowie in mehreren sicherheitsnahen Service-Schichten. Besonders positiv fallen die wiederverwendbaren Admin-Partials, die Contracts, `escaping.php`, `PurifierService` sowie die SEO-/Head- und Sanitizer-Pfade auf. Hier ist bereits ein belastbarer Qualitätsstandard sichtbar.

Die **riskantesten Zonen** liegen nicht im Layout, sondern in den dynamischeren und I/O-lastigen Bereichen: `assets/js/**`, Auth-/Provider-Integrationen, Upload-/Media-Pfade, Importer-Core sowie einzelne Root-Entrypoints wie `cron.php`. Dort ist die Punktzahl nicht katastrophal, aber klar unter dem Reifegrad der besten Kernbereiche – genau dort lohnt sich die nächste Audit-Runde am meisten.

Für die nächste Iteration sollten vor allem vier Themen priorisiert werden:

- **JavaScript-Sicherheitsaudit** für DOM-Manipulationen, Preview-Rendering und Editor-nahe Admin-Skripte
- **Cron-/Entrypoint-Härtung** inklusive Authentisierung, Rate-Limits und sauberem Fehler-/Logging-Verhalten
- **Upload-/Importer-Härtung** mit Fokus auf Pfade, Dateitypen, Größenlimits, Cleanup und Speicherverbrauch
- **Member-/Controller-Performance** durch Entkopplung, Query-Profiling und gezieltes Lazy-/Cache-Verhalten

Unterm Strich ist die Codebasis **nicht roh oder ungeordnet**, sondern bereits deutlich strukturiert und enterprise-tauglich. Der größte Hebel liegt jetzt nicht mehr im groben Neuaufbau, sondern in der **gezielten Härtung der beweglichen Teile**: Remote-Integrationen, Uploads, JS, Importpfade und stark verdichtete Controller-/Modulebene.
