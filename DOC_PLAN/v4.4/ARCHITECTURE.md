# 365CMS 4.4: Architektur

> **Stand:** 2026-11-23

## Bestehende Anschlussstellen

| Bereich | Vorhandener Vertrag | 4.4-Erweiterung |
|---|---|---|
| Inhaltstypen | `ContentTypeRegistry`, `TrashProvider`, `ExchangeProvider`, `SearchDocumentProvider` | neuer `FieldProvider`/`TranslationProvider`, bestehende Provider erweitern statt Sonderfälle |
| Revisionen | `PostRevisionService`, `page_revisions` | sprachliche/Custom-Field-Snapshots versionieren |
| Editor | `EditorJsRenderer`, `ContentNormalizer`, `HtmlSanitizer`, Block Registry | Live-Preview mit identischem Render-Pipeline-Vertrag |
| Übersetzung | `EditorJsTranslationPipeline`, `ContentLanguageCopyService`, `TranslationStatusService` | Locale-/Translations-Repository statt feste DE/EN-Felder |
| API | `ApiRouter`, `JwtService`, Bearer-Authentifizierung, RBAC | Integrationsidentität, Scopes, Client Credentials, OpenAPI |
| CLI/Alerting | für 4.3 geplant | Migration/Preflight/Key-Rotation/Health aus CLI aufrufbar |

## Empfohlene Dienste

```text
CMS\core\Services\Content\
  CustomFields\
    CustomFieldGroupService
    CustomFieldDefinitionRepository
    CustomFieldValueBag
    CustomFieldValidator
  Types\
    ContentTypeDefinitionService
    DynamicContentRepository
  Translations\
    TranslationRepository
    LocaleResolver
    TranslationMigrator
  Preview\
    LivePreviewService

CMS\core\Services\API\
  IntegrationClientRepository
  IntegrationTokenHasher
  ScopeResolver
  ClientCredentialsService
  OpenApiRegistry
```

Namen sind Architekturvorschläge; die bestehenden Namespace-Konventionen und
`Service::getInstance()`/Container-Registrierung bleiben maßgeblich.

## Schema-Entwurf

### Felddefinitionen und eigene Typen

```sql
custom_field_groups
  id, name, slug, target_types_json, settings_json, is_active,
  created_at, updated_at

custom_fields
  id, group_id, name, key, type, rules_json, options_json, sort_order,
  is_searchable, is_exportable, is_active, created_at, updated_at

content_types
  id, slug, label, settings_json, route_prefix, field_schema_version,
  is_active, created_at, updated_at

content_entries
  id, content_type_id, title, slug, status, fields_json, author_id,
  created_at, updated_at, published_at, previous_status, trashed_at, trashed_by
```

Bestehende Typen erhalten ausschließlich ein versioniertes
`custom_fields_json`, sobald die jeweilige Tabelle und ihr Exchange-/Revision-
Vertrag angepasst sind.

### Übersetzungen

```sql
content_translations
  id, entity_type, entity_id, locale, slug, title, content_json,
  excerpt, status, meta_json, source_locale, source_version,
  created_at, updated_at, published_at

UNIQUE (entity_type, entity_id, locale)
UNIQUE (entity_type, locale, slug)
```

Der zweite Unique-Index benötigt je Site/Tenant ggf. einen Site-Schlüssel; dies
ist eine W0-Entscheidung.

### Integrationen

```sql
api_clients
  id, name, client_id, secret_hash, scopes_json, expires_at,
  status, last_used_at, created_by, created_at, updated_at, revoked_at

api_client_usage
  client_id, day, request_count, failed_count, last_used_at
```

Direkte API-Keys können denselben geheimen Identifikatorvertrag nutzen, benötigen
aber keine zusätzliche Klartextspalte. Client-Secrets und Keys werden niemals
verschlüsselt zur Wiederanzeige gespeichert.

## Resolver-Regeln

1. `ContentFieldResolver`: Definition → Typprüfung → Zugriff → Normalisierung →
   escape-/formatierter Wert.
2. `TranslationResolver`: exakte Locale → konfigurierte Fallback-Kette → Basis-
   feld (nur während Übergang) → „nicht verfügbar“.
3. `ScopeResolver`: Key/Token → Client → Scope → Capability → Inhaltssichtbarkeit.
4. `LivePreviewService`: Editor-State → Normalisierung → Block Rendering →
   Purifier → Theme-Preview-Frame.

Kein Resolver greift direkt auf `$_GET`, `$_POST` oder Roh-HTML zu.

## Kompatibilität

- Altspalten `*_en` bleiben zunächst lesbar; neuer Code schreibt durch den
  Translation Resolver.
- Views oder SQL-Wrapper sind optional, nicht garantiert: Die Auslieferung darf
  MySQL/MariaDB-Versionen mit unterschiedlichen View-/JSON-Grenzen nicht
  voraussetzen.
- Custom Fields ohne Definition werden erhalten, aber im Admin als unbekanntes
  Versionsformat markiert.
- API v1 bleibt erhalten; erweiterte Endpunkte werden additive und in OpenAPI
  versioniert.
- Ein Live-Preview-Fehler verändert weder Persistenz noch Revisionen.
