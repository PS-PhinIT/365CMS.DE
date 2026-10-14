> **Website:** [365CMS.DE](https://365cms.de/) | **Version:** 3.5.0
> **Datum:** 2026-09-06 | **Status:** Stable – **Zuletzt aktualisiert am:** 2026-10-02 (Quellcode-Abgleich: Provider-Klassen, Quota-Tabelle, Editor-Einbindung)
> **Kurzbeschreibung:** Complete user and technical documentation for the protected 365CMS AI Services, including administration, translation, content drafts, SEO metadata, provider policies, quotas, security, and integration assets.

# 365CMS AI Services

This document is written in two language blocks. Each language starts with an operator-friendly guide and continues with a technical reference; the English block comes first, followed by the German block.

---

## English

### Administrator guide

### 1. What AI Services is

AI Services is the protected, central AI workspace in the 365CMS administration. It helps editors and administrators with controlled, reviewable content work:

- translating Editor.js content, currently with German-to-English as the primary workflow;
- creating editorial drafts such as summaries, outlines, and call-to-action variants;
- preparing SEO metadata drafts from existing page or post content;
- checking whether a configured AI provider is reachable;
- monitoring usage, quotas, durations, failures, and recent generations.

AI Services is an **assistant**, not an automatic publishing system. Results are proposals. A person must review them before they are used in published content.

### 2. What AI Services does not do

AI Services is deliberately not:

- a public chatbot;
- a replacement for editorial review;
- an automatic website-wide translation engine;
- an automatic publisher;
- a tool that silently changes titles, URLs, canonical URLs, images, or hreflang relationships;
- a reason to send every CMS document to an external cloud provider;
- a place where API keys, prompts, or full source text are displayed in monitoring screens.

### 3. Where to find it

AI Services is available only in the protected administration:

| Admin page | Purpose |
|---|---|
| `/admin/ai-services` | Dashboard, status, usage monitoring, recent runs |
| `/admin/ai-translation` | Translation rules and translation prompt template |
| `/admin/ai-content-creator` | Content-draft prompt and admin-only draft generation |
| `/admin/ai-seo-creator` | SEO prompt template and SEO workflow configuration |
| `/admin/ai-settings` | Providers, feature gates, logging, quotas, and healthchecks |

The old tab-style URLs are redirected to the matching dedicated page where applicable.

### 4. Before you start

An administrator should verify the following before editors use an AI feature:

1. The **AI Services core module** is enabled.
2. At least one provider is configured and enabled.
3. The provider has a valid endpoint, model/deployment, and secret where required.
4. The required global feature gate is enabled.
5. The provider scope allows the requested feature.
6. The target language is in the provider locale allowlist.
7. The user has the required capability.
8. Quotas are large enough for the intended content.
9. External data sharing is explicitly enabled when a cloud provider will receive CMS content.

If one of these conditions is missing, the feature should remain unavailable or return a clear error. This is intentional: a hidden policy failure is safer than an unexpected provider call.

### 5. Translating Editor.js content

#### 5.1 Typical workflow

1. Open an existing page or post in the CMS editor.
2. Make sure the source content is complete and saved as a draft.
3. Choose the AI translation action in the editor.
4. Select or confirm the target language, normally English.
5. Wait for the translation result and the processing summary.
6. Review the title, excerpt, slug, and translated Editor.js blocks.
7. Compare the result in the preview/diff step.
8. Accept the result only when the wording, links, formatting, and meaning are correct.
9. Continue editing or publish through the normal CMS workflow.

The translation action is not a publish action. It creates a reviewable localized result and does not bypass the normal editorial process.

#### 5.2 What is translated

The pipeline extracts supported text segments from the title, excerpt, and supported Editor.js block data. It keeps the Editor.js structure and writes translated text back into the same logical locations.

The implementation recognizes the following block types in its configuration:

`paragraph`, `header`, `list`, `checklist`, `quote`, `image`, `code`, `table`, `delimiter`, `spacer`, `embed`, `attaches`, `linkTool`, `raw`, `callout`, `warning`, `alert`, `accordion`, `imageGallery`, and `mediaText`.

Whether a type is actually translated is controlled by `supported_block_types`. Unsupported blocks can be preserved unchanged. Raw/HTML blocks can be explicitly skipped. A block that contains code or technical data must be checked carefully after the run.

#### 5.3 Translation safety limits

Translations are split into compact provider batches:

- maximum 2,400 characters per batch;
- maximum 12 segments per batch;
- a single segment above 2,400 characters is rejected and must be split in the editor;
- unsupported blocks are preserved when configured;
- a warning is returned when no supported text segment is found.

These limits reduce provider timeouts and make partial failures easier to understand.

#### 5.3a Block-by-block translation in the editor

The editor's “Mit AI nach EN übersetzen” button sends one HTTP request per unit: first title/excerpt (if present), then exactly one Editor.js block per request. Each translated block is applied to the EN editor before the next block is requested. A failed block is retried once; afterwards the run stops, already applied blocks remain, and clicking again resumes at the failed block. If the EN version already has content, the editor asks whether to apply block by block or to collect everything and show a preview; `preview_required` / result mode `preview` always forces the preview.

The first request of a run (`chunk_total`) counts as one user request and opens a server-side session in `$_SESSION` (30 minutes, bound to user, content type and target locale; budget = requests of the run, max. 121, plus up to 10 retries). Continuation requests (`chunk_session`) do not count another daily request but still reserve characters and every provider call; they may contain at most one block and no title/excerpt. Invalid or expired sessions return `error_code = chunk_session_invalid`, and the editor then starts a new session automatically.

#### 5.4 Translation result

A successful response contains, depending on the request:

- translated title;
- translated excerpt;
- a localized slug derived from the translated title;
- translated Editor.js data and JSON;
- warnings;
- total, translated, preserved, dropped, and skipped block counts;
- translated segment count;
- provider batch count.

The slug is a localized draft value. Editors must still check URL conventions, redirects, existing pages, and SEO implications.

### 6. Creating content drafts

The Content Creator is an admin-only assistant for unsaved editorial proposals. It supports exactly three tasks:

| Task | Output |
|---|---|
| Summary | A concise editorial summary |
| Outline | A structured Markdown outline |
| CTA | Three concise call-to-action variants |

Provide a useful briefing, optional context, a desired tone, and a locale. The generated result is a draft in the response. It is not automatically saved, sent, or published.

The assistant is instructed to use only the submitted briefing and context. It must not invent facts, people, statistics, quotes, prices, URLs, certifications, or guarantees. Nevertheless, editors remain responsible for fact-checking every output.

### 7. Generating SEO metadata drafts

The SEO Creator can prepare reviewable metadata based on the current primary text of a page or post. It can return:

- excerpt;
- focus keyphrase;
- keywords;
- meta title and meta description;
- Open Graph title and description;
- Twitter title, description, and card type;
- schema type;
- sitemap priority and change frequency;
- robots index and follow suggestions.

The SEO workflow does not modify the document title, slug, canonical URL, image URLs, or hreflang group through the AI response. These fields are protected by a server-side whitelist and must be managed through their normal CMS controls.

SEO output is a suggestion. Verify length, truthfulness, search intent, brand language, accessibility, and the actual page before saving or publishing any metadata.

### 8. Provider administration

The provider page lets an authorized administrator create, edit, enable, disable, select, and delete provider entries. Supported provider types are:

| Provider | Typical use | Secret |
|---|---|---|
| Mock Provider | Local UI/runtime tests | Not required |
| Ollama | Local or internal live inference | Not required by the CMS |
| Azure AI | Azure OpenAI/Azure AI endpoint | API key required |
| OpenAI | OpenAI API | API key required |
| Mistral | Mistral API | API key required |
| OpenRouter | Routed model access | API key required |

For cloud providers, use HTTPS endpoints. For Ollama, private destinations must match the explicitly configured internal host allowlist. Do not paste secrets into labels, prompts, notes, or regular log messages.

Each provider can have:

- a human-readable label;
- a provider type and stable ID;
- an enabled state;
- an operational profile;
- a default model;
- endpoint, deployment, and API version values where applicable;
- feature scopes;
- allowed locales (translation targets and the editor language for SEO/content drafts; the German editor requires `de`);
- a beta-only flag;
- a configured secret indicator.

Deleting a provider also removes its associated secret through the settings service. Check the active and fallback provider before deleting an entry.

### 9. Feature gates and profiles

Feature gates provide a global safety switch. The provider scope provides a second, provider-specific switch. A feature is usable only when both layers allow it.

Global gates include:

- AI Services;
- translation;
- rewrite;
- summary;
- SEO metadata;
- Editor.js;
- beta providers;
- external provider data sharing.

Operational profiles include:

| Profile | Meaning |
|---|---|
| `all` | All enabled provider scopes, still subject to every policy and quota |
| `editor-translation` | Editor.js translation focus |
| `content-assist` | Content assistance focus |
| `seo-assist` | SEO assistance focus |
| `beta` | Requires beta enablement |
| `disabled` | Provider is blocked |

`all` is not an authorization bypass. User capabilities, locale rules, quotas, egress rules, and feature gates are still evaluated.

### 10. Monitoring, quotas, and healthchecks

The dashboard intentionally shows operational information instead of content:

- request totals;
- character and block quantities;
- successful and failed runs;
- success rate;
- average duration;
- current-user daily usage;
- active-provider monthly usage;
- provider breakdown;
- recent generation history;
- quota percentages.

Raw prompts, full source content, secrets, request headers, and API keys are not shown in the monitoring view.

An administrator can run an explicit provider healthcheck. It sends a content-free probe and does not represent a translation or content request. A healthcheck can still fail because the provider is disabled, incomplete, blocked by policy, unreachable, or missing a required secret.

Quotas may limit:

- characters per request;
- blocks per request;
- timeout seconds;
- retries;
- daily requests per user;
- daily characters per user;
- monthly requests per provider.

Retries and fallback calls are controlled by the execution service and are reserved atomically so that usage accounting cannot be bypassed by repeated failures.

### 11. Troubleshooting for operators

| Symptom | First checks |
|---|---|
| AI menu is missing | User is an administrator, has an AI read capability, the admin page is enabled, and the core module is active |
| Save button is rejected | User has `manage_settings`, CSRF token is valid, and the action belongs to the current section |
| Provider is not ready | Type, endpoint, model/deployment, API version, secret, HTTPS, and Ollama allowlist |
| Translation is unavailable | Global translation and Editor.js gates, provider scopes, locale allowlist, and user capability |
| Content draft is rejected | Briefing or context is missing, content gate/provider scope is disabled, or quota is exhausted |
| SEO response is rejected | Primary page/post text is empty, SEO gate/scope is disabled, or provider returned invalid JSON |
| Large translation fails | Split the long block; the hard batch limit is 2,400 characters and 12 segments |
| Provider call times out | Check endpoint reachability, timeout quota, provider load, and fallback configuration |
| Recent run is not visible | Request metrics/logging may be disabled or the retention window may have expired |

Never solve an error by copying a secret into a log or by disabling all policy gates. Fix the smallest configuration problem and rerun the explicit action.

### 12. Editorial checklist

Before accepting an AI result, verify:

- meaning and factual accuracy;
- names, dates, prices, legal statements, and technical values;
- links, media, code, embeds, and structured data;
- language quality and terminology;
- title, excerpt, slug, and SEO length;
- accessibility and inclusive language;
- that no confidential content was sent to an external provider;
- that the result is saved in the intended language field;
- that publication still goes through the normal human workflow.

---

### Technical reference

### 13. Architecture overview

The implementation is split into configuration, policy, execution, provider, pipeline, admin, and audit layers:

```text
Admin route / editor action
        |
        v
Admin module / CMS AI service
        |
        +--> AiSettingsService
        +--> AiProviderPolicyService
        +--> AiExecutionService
        |        +--> AiQuotaService
        |        +--> AiProviderFactory
        |                +--> concrete provider
        |
        +--> EditorJsTranslationPipeline
        +--> ContentDraftGenerationPipeline
        +--> SeoMetadataGenerationPipeline
        |
        v
Validated result + audit metrics + review UI
```

The central executor applies policy and quota checks before a provider operation. It retries transient failures at most twice according to the configured retry value and may use a configured fallback provider only after the fallback passes the same policy, readiness, locale, and quota checks.

### 14. Source map

#### Admin entry points

- `CMS/admin/ai-services.php` — overview route wrapper.
- `CMS/admin/ai-translation.php` — translation route wrapper.
- `CMS/admin/ai-content-creator.php` — content creator route wrapper.
- `CMS/admin/ai-seo-creator.php` — SEO creator route wrapper.
- `CMS/admin/ai-settings.php` — settings route wrapper.
- `CMS/admin/ai-page.php` — shared route configuration, access checks, action routing, CSRF shell, and section redirects.
- `CMS/admin/modules/system/AiServicesModule.php` — admin data loading and mutation actions.
- `CMS/admin/modules/system/AiEditorJsTranslationModule.php` — Editor.js translation request handling.
- `CMS/admin/modules/system/AiEditorJsSeoMetadataModule.php` — SEO metadata request handling.
- `CMS/admin/views/system/ai-services.php` — shared section view.
- `CMS/admin/ai-translate-editorjs.php` — protected JSON translation endpoint.
- `CMS/admin/ai-generate-seo-metadata.php` — protected JSON SEO endpoint.

#### Core AI services

- `CMS/core/Services/AI/AiSettingsService.php`
- `CMS/core/Services/AI/AiProviderFactory.php`
- `CMS/core/Services/AI/AiProviderGateway.php`
- `CMS/core/Services/AI/AiProviderPolicyService.php`
- `CMS/core/Services/AI/AiExecutionService.php`
- `CMS/core/Services/AI/AiQuotaService.php`
- `CMS/core/Services/AI/QuotaAwareAiProvider.php`
- `CMS/core/Services/AI/EditorJsTranslationPipeline.php`
- `CMS/core/Services/AI/ContentDraftGenerationPipeline.php`
- `CMS/core/Services/AI/SeoMetadataGenerationPipeline.php`
- `CMS/core/Services/AI/AiService.php` — façade used by admin modules
- `CMS/core/Services/AI/AiProviderInterface.php` — provider contract
- `CMS/core/Services/AI/Providers/AbstractPromptingAiProvider.php` — shared prompt/JSON handling
- `CMS/core/Services/AI/Providers/MockAiProvider.php` — deterministic local provider (default `active_provider_id = mock`)
- `CMS/core/Services/AI/Providers/OllamaAiProvider.php` — self-hosted Ollama
- `CMS/core/Services/AI/Providers/AzureOpenAiProvider.php` — Azure OpenAI deployments
- `CMS/core/Services/AI/Providers/OpenAiCompatibleProvider.php` — OpenAI, Mistral and OpenRouter (provider types `openai`, `mistral`, `openrouter`)

Known provider types (`AiSettingsService::PROVIDER_SLUGS`): `mock`, `openai`, `mistral`, `azure_openai`, `ollama`, `openrouter`.

#### Browser assets and permissions

- `CMS/assets/js/admin-ai-services.js` — CSP-compatible AI admin behavior.
- `CMS/assets/js/admin-content-editor.js` — editor translation and preview/diff integration.
- `CMS/includes/functions/roles.php` — default AI capabilities.

### 15. Routes and action contract

The shared admin page normalizes sections to `overview`, `translation`, `content_creator`, `seo_creator`, or `settings`.

Allowed actions are section-specific:

| Section | Actions |
|---|---|
| Overview | None |
| Translation | `save_translation`, `save_translation_prompts` |
| Content Creator | `save_content_prompts`, `generate_content_draft` |
| SEO Creator | `save_seo_prompts` |
| Settings | `save_providers`, `delete_provider`, `save_features`, `save_logging`, `save_quotas`, `check_provider_health` |

The shared shell:

1. normalizes the page configuration;
2. checks admin status, read capability, and enabled admin page;
3. loads the module and section data;
4. checks write capability for mutations;
5. validates the action and section pairing;
6. verifies the persistent CSRF token;
7. invokes the module method;
8. redirects to the resolved section.

### 16. JSON endpoints

`ai-translate-editorjs.php` and `ai-generate-seo-metadata.php`:

- send private response headers;
- send `Content-Type: application/json`;
- send `X-Robots-Tag: noindex, nofollow, noarchive`;
- require `POST`;
- require an administrator;
- require the `ai_services` core module;
- accept a suitable capability (`manage_ai_services`, `manage_settings`, feature capability, or content-edit capability);
- verify a persistent CSRF token;
- set a maximum runtime of 300 seconds;
- return JSON with a success/error result and HTTP 200 or 422.

The translation endpoint uses the token action `admin_ai_editorjs_translation`. The SEO endpoint uses `admin_ai_seo_metadata`.

### 17. Settings persistence

Settings are stored through the existing `SettingsService` as logical groups (secrets AES-256 encrypted). The only AI-specific table is `cms_ai_quota_usage` (counters per scope, period, user and provider) used by `AiQuotaService`:

| Group | Responsibility |
|---|---|
| `ai.providers` | active/fallback provider, provider entries, encrypted provider secrets |
| `ai.features` | global feature and egress gates |
| `ai.translation` | locales, block policy, preview/result mode |
| `ai.logging` | mode, retention, hashes, metrics, error context |
| `ai.quotas` | request, character, block, timeout, retry, daily, and monthly limits |
| `ai.prompts` | translation, content creator, and SEO templates |

Provider entries contain, among other fields:

`id`, `type`, `label`, `enabled`, `profile`, `default_model`, `endpoint`, `deployment`, `api_version`, `translation_enabled`, `rewrite_enabled`, `summary_enabled`, `seo_meta_enabled`, `editorjs_enabled`, `allowed_locales`, `beta_only`, and `allowed_internal_hosts`.

Secrets are stored separately using provider-specific secret keys such as `provider_secret_<providerId>`. The UI exposes only whether a secret is configured.

Important global feature keys:

`ai_services_enabled`, `ai_translation_enabled`, `ai_rewrite_enabled`, `ai_summary_enabled`, `ai_seo_meta_enabled`, `ai_editorjs_enabled`, `ai_beta_providers_enabled`, and `ai_external_provider_data_sharing_enabled`.

Important translation keys:

`default_source_locale`, `default_target_locale`, `allowed_target_locales`, `supported_block_types`, `preview_required`, `preserve_unsupported_blocks`, `skip_html_blocks`, and `result_mode`.

Important logging keys:

`logging_mode`, `retention_days`, `store_content_hashes`, `store_request_metrics`, `store_error_context`, and `store_prompt_preview`. Prompt preview remains disabled for privacy; raw prompts are not persisted.

Important quota keys:

`max_chars_per_request`, `max_blocks_per_request`, `timeout_seconds`, `retry_count`, `daily_requests_per_user`, `daily_chars_per_user`, and `monthly_requests_per_provider`.

### 18. Provider policy and readiness

For a feature request, the policy layer must confirm:

1. the global service gate;
2. the global feature gate;
3. provider enabled state;
4. provider profile and beta gate;
5. provider feature scope;
6. user capability;
7. target locale allowlist;
8. endpoint and model/deployment readiness;
9. secret readiness;
10. external data-sharing consent for cloud egress;
11. request and quota limits.

Cloud endpoints require HTTPS. Ollama private endpoints are passed to the HTTP client only if their host exactly matches the configured internal allowlist. This is an egress control, not a general network discovery mechanism.

### 19. Editor.js translation contract

The translation pipeline accepts a payload containing editor data plus optional title, excerpt, slug, content type, source locale, and target locale. It:

1. normalizes Editor.js blocks;
2. identifies supported types;
3. preserves or drops unsupported blocks according to configuration;
4. extracts text paths and metadata segments;
5. batches segments at 2,400 characters/12 segments;
6. calls `translateBatch`;
7. requires a complete response for each batch;
8. writes translations into the original nested paths;
9. derives a localized slug;
10. returns editor JSON and statistics.

The pipeline never silently reorders blocks. A provider response with the wrong number of translations is an error. Empty translations fall back to the original segment rather than creating an empty block.

### 20. Content draft contract

`ContentDraftGenerationPipeline` accepts:

- task: `summary`, `outline`, or `cta`;
- brief: maximum normalized input of 2,000 characters;
- context: maximum normalized input of 12,000 characters;
- tone: maximum normalized input of 120 characters;
- locale;
- provider and optional prompt template.

The provider response must decode to `{"content":"..."}`. The result is sanitized draft content and is never persisted by the pipeline. The mock provider returns deterministic local output for testing.

### 21. SEO metadata contract

`SeoMetadataGenerationPipeline` requires non-empty primary content. The provider receives a structured task payload and must return the documented metadata object. The response is decoded, normalized, length-limited, and merged with safe fallback metadata where values are missing.

The allowed response includes:

`excerpt`, `focus_keyphrase`, `keywords`, `meta_title`, `meta_description`, `og_title`, `og_description`, `twitter_title`, `twitter_description`, `twitter_card`, `schema_type`, `sitemap_priority`, `sitemap_changefreq`, `robots_index`, and `robots_follow`.

The pipeline explicitly excludes document title, URL, slug, canonical URL, Open Graph image, Twitter image, and hreflang group. Custom prompt templates cannot remove the mandatory structured payload or security rules.

### 22. Prompt templates

Prompt templates are configurable per feature:

- translation;
- content creator;
- SEO creator.

A template can have `enabled`, `label`, `system_prompt`, `user_template`, and internal `notes`. Template variables are feature-specific. Examples include:

- translation: `{source_locale}`, `{target_locale}`, `{content_type}`, `{segment_count}`, `{segments_json}`;
- content: `{content_brief}`, `{context}`, `{tone}`, `{format}`;
- SEO: `{context}`, `{keyword}`, `{locale}`, `{content_type}`.

Templates are an editorial control surface, not a security bypass. Runtime code appends mandatory scope, untrusted-data, JSON-contract, and secret-protection rules. Raw prompts are not written to the audit log.

### 23. Quota and execution semantics

`AiExecutionService` reserves user operations and actual provider calls atomically through `AiQuotaService`. A logical operation can contain multiple batches. Retries and fallback provider calls are accounted for according to the quota service rules.

Transient failures include timeout-style failures, HTTP 429, and HTTP 5xx responses. The configured retry count is clamped to a maximum of two. Non-transient failures are surfaced immediately. A fallback is attempted only when:

- a fallback provider is configured;
- it is different from the primary provider;
- the primary failure is transient;
- the fallback passes all policy/readiness/locale checks;
- the fallback can reserve its quota.

### 24. Audit and privacy model

The dashboard uses technical metrics and recent audit context. Suitable data includes provider ID/label, feature, model, block count, character count, duration, status, attempts, fallback usage, and error class.

The default model does not store:

- API keys or secrets;
- authorization headers;
- full prompts;
- full source or generated content;
- hidden system instructions.

Retention is configurable. Content hashes may be stored when enabled, but a hash is not a substitute for a legal privacy assessment.

### 25. Security controls

The implementation combines:

- admin-only access;
- capability checks;
- module enablement checks;
- section/action allowlists;
- persistent CSRF tokens;
- private/no-index response headers;
- input normalization and length limits;
- provider readiness checks;
- HTTPS enforcement for cloud egress;
- exact Ollama internal-host allowlists;
- structured response contracts;
- server-side output sanitization;
- no automatic publishing;
- secret cleanup on provider deletion;
- CSP-compatible external JavaScript.

### 26. Extension guidance

When adding a provider or feature:

1. add the provider definition and factory mapping;
2. define whether it requires a secret and whether it supports live calls;
3. add policy scope and locale handling;
4. use the central executor rather than calling a provider directly;
5. reserve quota through the quota-aware wrapper;
6. define a strict input/output contract;
7. sanitize provider output server-side;
8. add admin action and capability checks;
9. avoid logging content and secrets;
10. provide a mock or deterministic test path;
11. update this document and related admin documentation;
12. test transient failure, fallback, quota exhaustion, invalid JSON, disabled gates, and unauthorized access.

Do not introduce a second provider configuration format, direct API-key handling in a view, inline admin scripts, or automatic publication.

### 27. Current implementation status

Implemented in the current CMS:

- central AI settings groups and provider entries;
- Mock, Ollama, Azure AI, OpenAI, Mistral, and OpenRouter provider definitions;
- provider gateway/factory and policy service;
- quota-aware execution with bounded retry and fallback;
- provider healthcheck without content;
- Editor.js translation with conservative batching;
- localized title, excerpt, slug, and Editor.js result;
- preview/diff review integration;
- content summary, outline, and CTA drafts;
- SEO metadata draft generation with a field whitelist;
- provider, feature, translation, logging, quota, and prompt administration;
- usage monitoring and generation history without raw content;
- protected admin routes and JSON endpoints;
- role capabilities for AI management and feature use;
- CSP-compatible AI administration assets.

### 28. Related documentation

- [CMS admin documentation](../admin/README.md)
- [System settings documentation](../admin/system-settings/README.md)
- [AI asset and platform documentation](AI-ASSETS.md)
- [CMS documentation index](../README.md)
- [Asset documentation](../assets/README.md)

---

## Deutsch

### Anwenderleitfaden

### 1. Was sind AI Services?

AI Services ist der geschützte, zentrale KI-Bereich der 365CMS-Administration. Er unterstützt Redakteure und Administratoren bei kontrollierten, überprüfbaren Aufgaben:

- Editor.js-Inhalte übersetzen, derzeit insbesondere Deutsch nach Englisch;
- redaktionelle Entwürfe für Zusammenfassungen, Gliederungen und Call-to-Action-Varianten erstellen;
- SEO-Metadaten aus vorhandenen Seiten- oder Beitragsinhalten vorbereiten;
- die Erreichbarkeit eines konfigurierten AI-Providers prüfen;
- Nutzung, Kontingente, Laufzeiten, Fehler und letzte Generierungen überwachen.

AI Services ist ein **Assistent** und kein automatisches Veröffentlichungssystem. Ergebnisse sind Vorschläge. Vor der Verwendung muss ein Mensch prüfen und entscheiden.

### 2. Was AI Services ausdrücklich nicht macht

AI Services ist bewusst:

- kein öffentlicher Chatbot;
- kein Ersatz für redaktionelle Prüfung;
- keine automatische Komplettübersetzung der Website;
- kein automatischer Publisher;
- kein Werkzeug zum stillen Ändern von Titel, URL, Canonical-URL, Bildern oder hreflang-Beziehungen;
- keine Begründung, jedes CMS-Dokument an einen externen Cloud-Provider zu senden;
- kein Bereich, in dem API-Keys, Prompts oder vollständige Quelltexte im Monitoring angezeigt werden.

### 3. Aufruf im Admin

AI Services ist ausschließlich im geschützten Adminbereich verfügbar:

| Admin-Seite | Zweck |
|---|---|
| `/admin/ai-services` | Dashboard, Status, Nutzungsmonitoring, letzte Läufe |
| `/admin/ai-translation` | Übersetzungsregeln und Übersetzungsvorlage |
| `/admin/ai-content-creator` | Content-Prompt und admin-only Entwurfserstellung |
| `/admin/ai-seo-creator` | SEO-Prompt und SEO-Konfiguration |
| `/admin/ai-settings` | Provider, Feature-Gates, Logging, Quotas, Healthchecks |

Frühere tab-basierte Aufrufe werden, soweit vorgesehen, auf die passende eigene Seite weitergeleitet.

### 4. Vorbereitung durch Administratoren

Vor der Freigabe für Redakteure sollte geprüft werden:

1. Das Core-Modul **AI Services** ist aktiviert.
2. Mindestens ein Provider ist eingerichtet und aktiviert.
3. Endpoint, Modell/Deployment und gegebenenfalls Secret sind gültig.
4. Das erforderliche globale Feature-Gate ist aktiv.
5. Der Provider-Scope erlaubt die gewünschte Funktion.
6. Die Zielsprache steht in der Locale-Allowlist.
7. Der Benutzer hat die notwendige Capability.
8. Die Quotas reichen für die geplante Inhaltsmenge.
9. Bei Cloud-Providern ist die externe Datenweitergabe ausdrücklich freigegeben.

Fehlt eine Bedingung, bleibt die Funktion gesperrt oder liefert einen verständlichen Fehler. Das ist beabsichtigt: Eine blockierte Anfrage ist sicherer als ein unerwarteter Provider-Aufruf.

### 5. Editor.js-Inhalte übersetzen

#### 5.1 Typischer Ablauf

1. Eine bestehende Seite oder einen Beitrag im CMS-Editor öffnen.
2. Prüfen, dass der Ausgangstext vollständig ist und als Entwurf gespeichert wurde.
3. Die AI-Übersetzungsaktion im Editor auslösen.
4. Die Zielsprache auswählen oder bestätigen, normalerweise Englisch.
5. Die Übersetzung läuft Block für Block (siehe 5.3a); Fortschritt und Ergebnis abwarten.
6. Titel, Excerpt, Slug und Editor.js-Blöcke prüfen.
7. Die Vorschau bzw. den Diff-Vergleich verwenden.
8. Das Ergebnis nur übernehmen, wenn Sprache, Links, Formatierung und Bedeutung stimmen.
9. Danach normal weiterbearbeiten oder über den regulären CMS-Ablauf veröffentlichen.

Die Übersetzungsaktion veröffentlicht nicht. Sie erzeugt einen überprüfbaren lokalisierten Entwurf.

#### 5.2 Übersetzbare Inhalte

Die Pipeline extrahiert unterstützte Textsegmente aus Titel, Excerpt und konfigurierten Editor.js-Blöcken. Die Editor.js-Struktur bleibt erhalten; übersetzter Text wird in dieselben logischen Positionen zurückgeschrieben.

In der Konfiguration sind folgende Blocktypen bekannt:

`paragraph`, `header`, `list`, `checklist`, `quote`, `image`, `code`, `table`, `delimiter`, `spacer`, `embed`, `attaches`, `linkTool`, `raw`, `callout`, `warning`, `alert`, `accordion`, `imageGallery` und `mediaText`.

Ob ein Typ tatsächlich übersetzt wird, entscheidet `supported_block_types`. Nicht unterstützte Blöcke können unverändert erhalten bleiben. Raw-/HTML-Blöcke können ausdrücklich übersprungen werden. Code und technische Daten müssen nach jedem Lauf besonders geprüft werden.

#### 5.3 Sicherheitslimits

Übersetzungen werden in kleine Provider-Batches geteilt:

- maximal 2.400 Zeichen pro Batch;
- maximal 12 Segmente pro Batch;
- ein einzelnes Segment über 2.400 Zeichen wird abgelehnt und muss im Editor geteilt werden;
- nicht unterstützte Blöcke bleiben bei entsprechender Konfiguration erhalten;
- wenn kein unterstütztes Textsegment gefunden wird, kommt eine Warnung zurück.

Die Limits verringern Timeouts und machen Teilfehler nachvollziehbarer.

#### 5.3a Blockweise Übersetzung im Editor

Der Button „Mit AI nach EN übersetzen“ sendet nicht mehr das komplette Dokument in einem HTTP-Request, sondern arbeitet Block für Block:

1. zuerst Titel und Kurzfassung (falls vorhanden), danach jeweils genau ein Editor.js-Block pro Request;
2. jeder übersetzte Block wird sofort in den EN-Editor übernommen, bevor der nächste Block angefragt wird;
3. ein fehlgeschlagener Block wird einmal automatisch wiederholt; bleibt er fehlerhaft, stoppt der Lauf, bereits übernommene Blöcke bleiben erhalten und ein erneuter Klick setzt ab dem fehlgeschlagenen Block fort;
4. „Übersetzung abbrechen“ stoppt nach dem aktuell laufenden Block;
5. enthält die EN-Fassung bereits Inhalte, fragt der Editor, ob blockweise direkt übernommen oder zuerst alles übersetzt und als Vorschau geprüft werden soll; ist `preview_required` bzw. der Ergebnismodus `preview` aktiv, wird immer erst am Ende eine Vorschau gezeigt.

Quota: Der erste Request eines Laufs (`chunk_total`) zählt als ein Benutzer-Request und legt eine serverseitige Sitzung in `$_SESSION` an (30 Minuten gültig, benutzer-, inhaltstyp- und zielsprachengebunden, Budget = Anzahl Requests des Laufs (max. 121) plus max. 10 Wiederholungen; Folge-Requests dürfen höchstens einen Block und keine Titel-/Kurzfassungsdaten enthalten). Folge-Requests (`chunk_session`) zählen keinen weiteren Tages-Request, reservieren aber weiterhin Zeichen und jeden Provider-Aufruf. Ungültige oder abgelaufene Sitzungen liefern `error_code = chunk_session_invalid`; der Editor startet dann automatisch eine neue Sitzung.

#### 5.4 Ergebnis

Eine erfolgreiche Antwort kann enthalten:

- übersetzten Titel;
- übersetztes Excerpt;
- einen aus dem übersetzten Titel abgeleiteten lokalen Slug;
- übersetzte Editor.js-Daten und JSON;
- Warnungen;
- Anzahl aller, übersetzter, erhaltener, verworfener und übersprungener Blöcke;
- Anzahl übersetzter Segmente;
- Anzahl der Provider-Batches.

Der Slug ist ein lokaler Entwurfswert. URL-Konventionen, Weiterleitungen, vorhandene Seiten und SEO müssen weiterhin redaktionell geprüft werden.

### 6. Content Creator

Der Content Creator ist ein admin-only Assistent für nicht gespeicherte redaktionelle Vorschläge. Er unterstützt genau drei Aufgaben:

| Aufgabe | Ausgabe |
|---|---|
| Summary | Kurze redaktionelle Zusammenfassung |
| Outline | Strukturierte Markdown-Gliederung |
| CTA | Drei kurze Call-to-Action-Varianten |

Ein gutes Briefing, optionaler Kontext, gewünschte Tonalität und Sprache verbessern das Ergebnis. Der Entwurf wird weder automatisch gespeichert noch versendet oder veröffentlicht.

Der Assistent darf nur Briefing und Kontext verwenden. Er soll keine Fakten, Personen, Statistiken, Zitate, Preise, URLs, Zertifizierungen oder Garantien erfinden. Jede Ausgabe muss trotzdem fachlich geprüft werden.

### 7. SEO-Metadaten erzeugen

Der SEO Creator erstellt aus dem Haupttext einer Seite oder eines Beitrags einen prüfbaren Metadaten-Entwurf. Möglich sind:

- Excerpt;
- Fokus-Keyphrase;
- Keywords;
- Meta-Titel und Meta-Description;
- Open-Graph-Titel und -Description;
- Twitter-Titel, -Description und Card-Typ;
- Schema-Typ;
- Sitemap-Priorität und Änderungsfrequenz;
- Robots-Index- und Follow-Vorschläge.

Dokumenttitel, Slug, Canonical-URL, Bild-URLs und hreflang-Gruppe werden nicht aus der AI-Antwort übernommen. Dafür gelten die normalen CMS-Steuerungen.

SEO-Ergebnisse sind Vorschläge. Länge, Wahrheit, Suchintention, Marke, Barrierefreiheit und der echte Seiteninhalt müssen vor dem Speichern geprüft werden.

### 8. Provider verwalten

Berechtigte Administratoren können Provider-Einträge anlegen, bearbeiten, aktivieren, deaktivieren, auswählen und löschen:

| Provider | Typischer Einsatz | Secret |
|---|---|---|
| Mock Provider | Lokale UI-/Runtime-Tests | Nicht erforderlich |
| Ollama | Lokale oder interne Live-Inferenz | Im CMS nicht erforderlich |
| Azure AI | Azure-OpenAI-/Azure-AI-Endpoint | API-Key erforderlich |
| OpenAI | OpenAI-API | API-Key erforderlich |
| Mistral | Mistral-API | API-Key erforderlich |
| OpenRouter | Gerouteter Modellzugriff | API-Key erforderlich |

Cloud-Endpoints müssen HTTPS verwenden. Private Ollama-Ziele müssen in der exakt konfigurierten internen Host-Allowlist stehen. Secrets gehören nicht in Labels, Prompts, Notizen oder normale Logs.

Ein Provider-Eintrag kann unter anderem Label, ID, Typ, Aktivstatus, Profil, Modell, Endpoint, Deployment, API-Version, Feature-Scopes, Locales, Beta-Markierung und Secret-Status enthalten.

Beim Löschen werden die zugehörigen Secrets über den Settings-Service entfernt. Vorher aktiven und Fallback-Provider prüfen.

### 9. Feature-Gates und Profile

Ein globales Gate ist der zentrale Schalter; der Provider-Scope ist die zweite Schranke. Erst wenn beide Ebenen erlauben, darf eine Funktion ausgeführt werden.

Globale Gates:

- AI Services;
- Übersetzung;
- Rewrite;
- Summary;
- SEO-Metadaten;
- Editor.js;
- Beta-Provider;
- externe Provider-Datenweitergabe.

Profile:

| Profil | Bedeutung |
|---|---|
| `all` | Alle aktivierten Scopes, weiterhin mit allen Policies und Quotas |
| `editor-translation` | Schwerpunkt Editor.js-Übersetzung |
| `content-assist` | Schwerpunkt Content-Unterstützung |
| `seo-assist` | Schwerpunkt SEO-Unterstützung |
| `beta` | Benötigt aktivierte Beta-Freigabe |
| `disabled` | Provider ist blockiert |

`all` umgeht keine Berechtigung und keine Sicherheitsprüfung.

### 10. Monitoring, Quotas und Healthchecks

Das Dashboard zeigt bewusst technische statt inhaltliche Daten:

- Requests;
- Zeichen- und Blockmengen;
- erfolgreiche und fehlgeschlagene Läufe;
- Erfolgsrate;
- durchschnittliche Laufzeit;
- Tagesnutzung des aktuellen Benutzers;
- Monatsnutzung des aktiven Providers;
- Provider-Aufschlüsselung;
- letzte Generierungen;
- Quota-Prozente.

Rohprompts, vollständige Inhalte, Secrets, Header und API-Keys werden nicht angezeigt.

Ein Administrator kann einen expliziten Healthcheck auslösen. Dieser sendet eine inhaltsfreie Probe und ist keine Übersetzungs- oder Content-Anfrage. Er kann trotzdem wegen fehlendem Secret, blockiertem Provider, ungültiger Konfiguration oder fehlender Erreichbarkeit scheitern.

Quotas begrenzen unter anderem:

- Zeichen pro Anfrage;
- Blöcke pro Anfrage;
- Timeout;
- Wiederholungen;
- Requests pro Benutzer und Tag;
- Zeichen pro Benutzer und Tag;
- Requests pro Provider und Monat.

Retries und Fallback-Aufrufe werden zentral und atomar berücksichtigt.

### 11. Fehlerhilfe

| Symptom | Erste Prüfung |
|---|---|
| AI-Menü fehlt | Adminstatus, AI-Lesecapability, aktivierte Adminseite und Core-Modul |
| Speichern wird abgelehnt | `manage_settings`, CSRF-Token und passende Aktion für den Bereich |
| Provider nicht bereit | Typ, Endpoint, Modell/Deployment, API-Version, Secret, HTTPS, Ollama-Allowlist |
| Übersetzung nicht verfügbar | globale Gates, Provider-Scopes, Locale-Allowlist und Benutzer-Capability |
| Content-Entwurf abgelehnt | Briefing oder Kontext fehlt, Gate/Scope deaktiviert oder Quota erschöpft |
| SEO-Antwort abgelehnt | Haupttext leer, Gate/Scope deaktiviert oder ungültiges JSON |
| Große Übersetzung scheitert | langen Block teilen; Grenze 2.400 Zeichen/12 Segmente |
| Provider-Timeout | Endpoint, Timeout, Providerlast und Fallback prüfen |
| Lauf nicht im Verlauf | Request-Metriken deaktiviert oder Aufbewahrungszeit abgelaufen |

Ein Fehler darf nicht durch das Eintragen von Secrets in Logs oder durch das pauschale Abschalten aller Policies gelöst werden.

### 12. Redaktionscheckliste

Vor der Übernahme prüfen:

- Sinn und Fakten;
- Namen, Daten, Preise, Rechtstexte und technische Werte;
- Links, Medien, Code, Embeds und strukturierte Daten;
- Sprache und Terminologie;
- Titel, Excerpt, Slug und SEO-Längen;
- Barrierefreiheit und inklusive Sprache;
- vertrauliche Inhalte und externe Datenweitergabe;
- das richtige Sprachfeld;
- den normalen menschlichen Veröffentlichungsablauf.

---

### Technische Referenz

### 13. Architektur

Die Implementierung trennt Konfiguration, Policy, Ausführung, Provider, Pipelines, Admin und Audit:

```text
Admin-Route / Editor-Aktion
        |
        v
Admin-Modul / CMS-AI-Service
        |
        +--> AiSettingsService
        +--> AiProviderPolicyService
        +--> AiExecutionService
        |        +--> AiQuotaService
        |        +--> AiProviderFactory
        |                +--> konkreter Provider
        |
        +--> EditorJsTranslationPipeline
        +--> ContentDraftGenerationPipeline
        +--> SeoMetadataGenerationPipeline
        |
        v
Validiertes Ergebnis + Audit-Metriken + Review-UI
```

Der zentrale Executor prüft Policies und Quotas vor dem Provider-Aufruf. Transiente Fehler werden entsprechend der Konfiguration, höchstens zweimal, wiederholt. Ein Fallback wird nur verwendet, wenn auch er alle Policies, Readiness-, Locale- und Quota-Prüfungen besteht.

### 14. Quellcode-Übersicht

#### Admin

- `CMS/admin/ai-services.php` — Wrapper für die Übersichtsroute.
- `CMS/admin/ai-translation.php` — Wrapper für Übersetzung.
- `CMS/admin/ai-content-creator.php` — Wrapper für Content Creator.
- `CMS/admin/ai-seo-creator.php` — Wrapper für SEO Creator.
- `CMS/admin/ai-settings.php` — Wrapper für Einstellungen.
- `CMS/admin/ai-page.php` — gemeinsame Routen-, Rechte-, Aktions- und CSRF-Logik.
- `CMS/admin/modules/system/AiServicesModule.php` — Daten und Mutationsaktionen im Admin.
- `CMS/admin/modules/system/AiEditorJsTranslationModule.php` — Editor.js-Übersetzungsrequest.
- `CMS/admin/modules/system/AiEditorJsSeoMetadataModule.php` — SEO-Request.
- `CMS/admin/views/system/ai-services.php` — gemeinsame Bereichsansicht.
- `CMS/admin/ai-translate-editorjs.php` — geschützter JSON-Übersetzungsendpoint.
- `CMS/admin/ai-generate-seo-metadata.php` — geschützter JSON-SEO-Endpoint.

#### Core

- `CMS/core/Services/AI/AiSettingsService.php`
- `CMS/core/Services/AI/AiProviderFactory.php`
- `CMS/core/Services/AI/AiProviderGateway.php`
- `CMS/core/Services/AI/AiProviderPolicyService.php`
- `CMS/core/Services/AI/AiExecutionService.php`
- `CMS/core/Services/AI/AiQuotaService.php`
- `CMS/core/Services/AI/QuotaAwareAiProvider.php`
- `CMS/core/Services/AI/EditorJsTranslationPipeline.php`
- `CMS/core/Services/AI/ContentDraftGenerationPipeline.php`
- `CMS/core/Services/AI/SeoMetadataGenerationPipeline.php`
- `CMS/core/Services/AI/AiService.php` — Fassade für die Admin-Module
- `CMS/core/Services/AI/AiProviderInterface.php` — Provider-Vertrag
- `CMS/core/Services/AI/Providers/AbstractPromptingAiProvider.php` — gemeinsame Prompt-/JSON-Logik
- `CMS/core/Services/AI/Providers/MockAiProvider.php` — deterministischer lokaler Provider (Standard `active_provider_id = mock`)
- `CMS/core/Services/AI/Providers/OllamaAiProvider.php` — selbst gehostetes Ollama
- `CMS/core/Services/AI/Providers/AzureOpenAiProvider.php` — Azure-OpenAI-Deployments
- `CMS/core/Services/AI/Providers/OpenAiCompatibleProvider.php` — OpenAI, Mistral und OpenRouter (Typen `openai`, `mistral`, `openrouter`)

Bekannte Provider-Typen (`AiSettingsService::PROVIDER_SLUGS`): `mock`, `openai`, `mistral`, `azure_openai`, `ollama`, `openrouter`.

#### Assets und Rollen

- `CMS/assets/js/admin-ai-services.js` — CSP-kompatibles Admin-JavaScript.
- `CMS/assets/js/admin-content-editor.js` — Editor-Übersetzung und Preview/Diff.
- `CMS/includes/functions/roles.php` — Default-Capabilities.

### 15. Routen und Actions

Die gemeinsame Adminseite normalisiert die Bereiche `overview`, `translation`, `content_creator`, `seo_creator` und `settings`.

| Bereich | Erlaubte Actions |
|---|---|
| Overview | keine |
| Translation | `save_translation`, `save_translation_prompts` |
| Content Creator | `save_content_prompts`, `generate_content_draft` |
| SEO Creator | `save_seo_prompts` |
| Settings | `save_providers`, `delete_provider`, `save_features`, `save_logging`, `save_quotas`, `check_provider_health` |

Der gemeinsame Shell-Ablauf normalisiert Konfiguration, prüft Adminstatus und Leserechte, lädt Modul-/Bereichsdaten, prüft Schreibrechte, validiert Action und Bereich, verifiziert den persistenten CSRF-Token, ruft die Modulmethode auf und leitet auf den passenden Bereich zurück.

### 16. JSON-Endpoints

`ai-translate-editorjs.php` und `ai-generate-seo-metadata.php`:

- setzen private Response-Header;
- liefern `application/json`;
- setzen `X-Robots-Tag: noindex, nofollow, noarchive`;
- erlauben nur `POST`;
- verlangen Adminstatus und aktiviertes `ai_services`-Modul;
- akzeptieren passende Capabilities;
- prüfen persistente CSRF-Tokens;
- setzen ein maximales Laufzeitfenster von 300 Sekunden;
- liefern HTTP 200 bei Erfolg beziehungsweise 422 bei fachlichem Fehler.

Token-Actions:

- Übersetzung: `admin_ai_editorjs_translation`;
- SEO: `admin_ai_seo_metadata`.

### 17. Persistenz der Einstellungen

Über `SettingsService` werden logische Gruppen gespeichert (Secrets AES-256-verschlüsselt). Einzige KI-spezifische Tabelle ist `cms_ai_quota_usage` (Zähler je Scope, Zeitraum, Benutzer und Provider) für `AiQuotaService`:

| Gruppe | Aufgabe |
|---|---|
| `ai.providers` | aktiver/Fallback-Provider, Einträge und verschlüsselte Secrets |
| `ai.features` | globale Feature- und Egress-Gates |
| `ai.translation` | Locales, Blockregeln, Preview und Resultatmodus |
| `ai.logging` | Modus, Retention, Hashes, Metriken und Fehlerkontext |
| `ai.quotas` | Limits, Timeout, Retries sowie Tages-/Monatsbudgets |
| `ai.prompts` | Vorlagen für Translation, Content Creator und SEO |

Provider-Einträge enthalten unter anderem `id`, `type`, `label`, `enabled`, `profile`, `default_model`, `endpoint`, `deployment`, `api_version`, Feature-Scopes, `allowed_locales`, `beta_only` und `allowed_internal_hosts`.

Secrets werden separat unter Schlüsseln wie `provider_secret_<providerId>` gespeichert. Die Oberfläche zeigt nur den konfigurierten Status.

### 18. Policy und Readiness

Jeder Feature-Request muss globale Gates, Provideraktivierung, Profil/Beta, Feature-Scope, Benutzer-Capability, Locale-Allowlist, Endpoint, Modell/Deployment, Secret, Cloud-Egress-Freigabe und Quotas bestehen.

Cloud-Endpoints benötigen HTTPS. Private Ollama-Endpoints werden nur bei exakter Übereinstimmung mit der internen Host-Allowlist an den HTTP-Client weitergegeben.

### 19. Editor.js-Vertrag

Die Pipeline verarbeitet Editor-Daten mit optionalem Titel, Excerpt, Slug, Contenttyp sowie Quell- und Zielsprache. Sie klassifiziert Blöcke, erhält oder verwirft nicht unterstützte Blöcke nach Konfiguration, extrahiert Textpfade, batcht mit 2.400 Zeichen/12 Segmenten, verlangt vollständige Antworten, schreibt in verschachtelte Originalpfade zurück, erzeugt einen lokalen Slug und liefert Statistiken.

Blöcke werden nicht still umsortiert. Eine falsche Anzahl von Übersetzungen ist ein Fehler. Leere Providertexte fallen auf den Ausgangstext zurück, damit keine leeren Blöcke entstehen.

### 20. Content-Draft-Vertrag

`ContentDraftGenerationPipeline` akzeptiert `summary`, `outline` oder `cta`, ein Briefing bis 2.000 Zeichen, Kontext bis 12.000 Zeichen, Tonalität bis 120 Zeichen, Locale, Provider und optionale Vorlage.

Die Providerantwort muss `{"content":"..."}` liefern. Der Pipeline-Output wird sanitisiert und nicht persistiert. Der Mock Provider ermöglicht reproduzierbare lokale Tests.

### 21. SEO-Vertrag

Der SEO-Pipeline ist ein nicht leerer Haupttext erforderlich. Die Providerantwort wird als strukturiertes JSON dekodiert, normalisiert, längenbegrenzt und bei fehlenden Werten mit sicheren Fallbacks ergänzt.

Erlaubte Felder sind `excerpt`, `focus_keyphrase`, `keywords`, `meta_title`, `meta_description`, `og_title`, `og_description`, `twitter_title`, `twitter_description`, `twitter_card`, `schema_type`, `sitemap_priority`, `sitemap_changefreq`, `robots_index` und `robots_follow`.

Dokumenttitel, URL, Slug, Canonical-URL, Open-Graph-Bild, Twitter-Bild und hreflang-Gruppe sind ausdrücklich ausgeschlossen. Vorlagen können den strukturierten Payload und die Pflicht-Sicherheitsregeln nicht entfernen.

### 22. Prompt-Vorlagen

Vorlagen existieren für Translation, Content Creator und SEO. Typische Felder sind `enabled`, `label`, `system_prompt`, `user_template` und interne `notes`.

Beispiele für Vorlagenvariablen:

- Translation: `{source_locale}`, `{target_locale}`, `{content_type}`, `{segment_count}`, `{segments_json}`;
- Content: `{content_brief}`, `{context}`, `{tone}`, `{format}`;
- SEO: `{context}`, `{keyword}`, `{locale}`, `{content_type}`.

Vorlagen sind keine Sicherheitsumgehung. Die Runtime ergänzt Regeln für untrusted data, Scope, JSON-Vertrag und Secret-Schutz. Rohprompts werden nicht im Audit gespeichert.

### 23. Quota- und Ausführungssemantik

`AiExecutionService` reserviert Benutzeroperationen und reale Provideraufrufe atomar über `AiQuotaService`. Retries und Fallbacks werden nach den Regeln des Quota-Service berücksichtigt.

Transiente Fehler umfassen Timeouts, HTTP 429 und HTTP 5xx. Der Retry-Wert wird auf höchstens zwei begrenzt. Nicht-transiente Fehler werden sofort weitergegeben. Ein Fallback ist nur bei konfiguriertem anderem Provider, transientem Primärfehler, erfolgreicher Policy-/Readiness-/Locale-Prüfung und erfolgreicher Quota-Reservierung zulässig.

### 24. Audit und Datenschutz

Geeignete Auditdaten sind Provider, Feature, Modell, Block- und Zeichenzahl, Laufzeit, Status, Versuche, Fallback-Nutzung und Fehlerklasse.

Standardmäßig werden nicht gespeichert:

- API-Keys und Secrets;
- Authorization-Header;
- vollständige Prompts;
- vollständige Quell- oder Ausgabedaten;
- versteckte Systemanweisungen.

Die Aufbewahrungsdauer ist konfigurierbar. Inhaltshashes können optional gespeichert werden, ersetzen aber keine Datenschutzprüfung.

### 25. Sicherheitsmaßnahmen

Die Lösung kombiniert Admin- und Capability-Prüfungen, Core-Modul- und Action-Gates, persistente CSRF-Tokens, private/no-index Header, Input-Limits, Provider-Readiness, HTTPS, exakte Ollama-Allowlists, strukturierte Antwortverträge, serverseitige Sanitization, keine automatische Veröffentlichung, Secret-Bereinigung und CSP-kompatible externe Assets.

### 26. Erweiterungshinweise

Bei einem neuen Provider oder Feature:

1. Providerdefinition und Factory-Mapping ergänzen;
2. Secret- und Live-Unterstützung definieren;
3. Scope- und Locale-Regeln ergänzen;
4. ausschließlich den zentralen Executor verwenden;
5. Quota-aware Wrapper verwenden;
6. strikten Input-/Output-Vertrag festlegen;
7. Provideroutput serverseitig sanitizen;
8. Adminaction und Capabilities prüfen;
9. keine Inhalte oder Secrets loggen;
10. Mock-/Testpfad bereitstellen;
11. Doku aktualisieren;
12. Fehler, Fallback, Quota, ungültiges JSON, gesperrte Gates und unberechtigten Zugriff testen.

Keine zweite Provider-Konfiguration, keine API-Key-Verarbeitung in Views, keine Inline-Skripte und keine automatische Veröffentlichung einführen.

### 27. Implementierungsstand

Aktuell umgesetzt sind zentrale Settings-Gruppen, Providerverwaltung für Mock/Ollama/Azure AI/OpenAI/Mistral/OpenRouter, Factory/Gateway/Policy, Quota-aware Execution mit Retry/Fallback, inhaltsfreier Healthcheck, Editor.js-Übersetzung, lokalisierte Felder, Preview/Diff, Summary/Outline/CTA-Entwürfe, SEO-Entwürfe mit Whitelist, Adminverwaltung, Monitoring ohne Rohinhalt, geschützte Routes/Endpoints, Default-Capabilities und CSP-kompatible Assets.

### 28. Verwandte Dokumentation

- [CMS-Admin-Dokumentation](../admin/README.md)
- [Systemeinstellungen](../admin/system-settings/README.md)
- [Dokumentationsindex](../README.md)
- [Asset-Dokumentation](../assets/README.md)
