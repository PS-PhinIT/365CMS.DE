# Community: Funktionen

> **Stand 4.2.0:** 2026-11-23

| Status | Funktion | Beleg |
|---|---|---|
| [x] | Kategorien und verschachtelte Foren | `CategoryRepository`, `ForumRepository`, `/admin/community-structure` |
| [x] | Themen, Antworten, Bearbeitungsverlauf und Papierkorb | `TopicService`, `PostService`, `TopicTrashService` |
| [x] | Markdown, Zitate, Vorschau und visuelle Bearbeitung | `ContentRenderer`, `QuoteService`, `RenderService`, `community/editor.js` |
| [x] | Anhänge, Entwürfe und geschützte Dateiauslieferung | `AttachmentService`, `DraftService`, `AttachmentController` |
| [x] | Lesestatus, Abos, Reaktionen, Ränge und Benachrichtigungen | zugehörige Repositories und Services |
| [x] | Suche, Sitemap und Medienreferenzen | `CommunitySearch`, `SearchRepository`, `CommunityMediaExtractor` |
| [x] | Moderation, Meldungen, Sperren und Zählerkonsistenz | `ModerationService`, `ReportService`, `BanService`, `CounterBook` |
| [x] | Öffentliche Profile, Signaturen und Mitgliederbereich | `ProfileService`, `CommunityMemberSection`, Views unter `CMS/views/community/` |
| [x] | Admin-Verwaltung und zentrale Papierkorb-Integration | `CommunityAdminModule`, `CMS/admin/community*.php`, `CommunityContentProvider` |
| [x] | DSGVO-Export, Löschung und Aufbewahrung | `CommunityPrivacy`, `CommunityRetention`, `CommunityLegal` |

Nicht angekündigte Erweiterungen wie WebSockets oder Gruppenchat gehören nicht zum 4.2.0-Lieferumfang.

