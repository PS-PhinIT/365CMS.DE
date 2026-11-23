# 365CMS 4.4: Lokale Evidenz

## Custom Fields und Registry

- `CMS/core/Services/ContentTypes/ContentTypeRegistry.php`
- `CMS/core/Services/ContentTypes/ContentTypeProvider.php`
- `CMS/core/Services/ContentTypes/TrashProvider.php`
- `CMS/core/Services/ContentTypes/ExchangeProvider.php`
- `CMS/core/Services/ContentTypes/SearchDocumentProvider.php`
- `CMS/core/Services/Exchange/ContentExchangeService.php`
- `CMS/core/Services/Trash/TrashService.php`
- `CMS/core/Services/Search/SearchIndexService.php`
- `CMS/core/Services/PostRevisionService.php`
- [Inhaltsplattform B/C](../ROADMAP-CONTENT-PLATFORM.md)
- [Content-Type-Provider-Doku](../../DOC/core/CONTENT-TYPE-PROVIDERS.md)

## N Sprachen

- `CMS/core/Services/ContentLanguageCopyService.php`
- `CMS/core/Services/TranslationStatusService.php`
- `CMS/core/Services/AI/EditorJsTranslationPipeline.php`
- `CMS/core/Services/ContentLocalizationService.php`
- `CMS/core/Services/Exchange/ContentRowExchangeProvider.php`
- `CMS/core/Services/Search/AbstractTableSearchProvider.php`
- [Inhaltsplattform B/C](../ROADMAP-CONTENT-PLATFORM.md)

## Live Preview

- `CMS/admin/post-editor-api.php`
- `CMS/core/Services/EditorJs/EditorJsRenderer.php`
- `CMS/core/Services/EditorJs/ContentNormalizer.php`
- `CMS/core/Services/EditorJs/HtmlSanitizer.php`
- `CMS/core/Services/PurifierService.php`
- `CMS/core/Services/PostRevisionService.php`
- vorhandene `/preview/<token>`- und Block-Preview-Pfade

## Managed API

- `CMS/core/Routing/ApiRouter.php`
- `CMS/core/Auth.php` (`authenticateBearerToken()`)
- `CMS/core/Services/JwtService.php`
- `CMS/core/Security.php`
- [API-Referenz](../../DOC/core/API-REFERENCE.md)
- [API-/AJAX-Doku](../../DOC/admin/ADMIN-API-AJAX.md)

## Vergleichsgrundlage

Die externen, offiziellen CMS-Quellen und die Einordnung, ob eine Fähigkeit
tatsächlich üblich oder produktspezifisch ist, stehen in
[../v4.3/CMS-COMPARISON.md](../v4.3/CMS-COMPARISON.md).
