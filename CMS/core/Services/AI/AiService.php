<?php
declare(strict_types=1);

namespace CMS\Services\AI;

use CMS\Json;
use CMS\Logger;
use CMS\Services\EditorJs\EditorJsSanitizer;

if (!defined('ABSPATH')) {
    exit;
}

final class AiService
{
    private static ?self $instance = null;

    private AiSettingsService $settings;
    private AiProviderFactory $providerFactory;
    private EditorJsTranslationPipeline $pipeline;
    private SeoMetadataGenerationPipeline $seoMetadataPipeline;
    private ContentDraftGenerationPipeline $contentDraftPipeline;
    private AiExecutionService $executionService;
    private EditorJsSanitizer $editorJsSanitizer;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->settings = AiSettingsService::getInstance();
        $this->providerFactory = AiProviderFactory::getInstance();
        $this->pipeline = EditorJsTranslationPipeline::getInstance();
        $this->seoMetadataPipeline = new SeoMetadataGenerationPipeline();
        $this->contentDraftPipeline = new ContentDraftGenerationPipeline();
        $this->executionService = new AiExecutionService(
            $this->providerFactory,
            new AiQuotaService(),
            new AiProviderPolicyService()
        );
        $this->editorJsSanitizer = new EditorJsSanitizer();
    }

    /**
     * Beispiel-API-Call für alle Features: kein Feature wählt Provider selbst aus.
     *
     * @param list<array{role:string,content:string}> $messages
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function complete(array $messages, array $options = []): array
    {
        $startedAt = microtime(true);
        $configuration = $this->settings->getConfiguration();
        $messages = $this->sanitizeMessages($messages);
        $feature = trim((string) ($options['feature'] ?? ''));
        if (!in_array($feature, ['content_summary', 'content_rewrite'], true)) {
            throw new \InvalidArgumentException('Generische AI-Aufrufe benötigen einen zulässigen expliziten Feature-Scope.');
        }

        $characterCount = 0;
        foreach ($messages as $message) {
            $characterCount += $this->countCharacters((string) ($message['content'] ?? ''));
        }

        $execution = $this->executionService->execute(
            $configuration,
            $feature,
            max(0, (int) ($options['user_id'] ?? 0)),
            $characterCount,
            static fn (AiProviderInterface $provider): string => $provider->complete($messages, $options),
            (string) ($options['locale'] ?? '')
        );
        $content = (string) ($execution['result'] ?? '');
        $provider = $execution['provider'];
        $providerConfig = $execution['provider_config'];

        return [
            'provider' => $this->buildProviderMeta($provider, $providerConfig, (string) ($execution['resolved_via'] ?? 'global')),
            'content' => $content,
            'telemetry' => [
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'resolved_provider' => $provider->getSlug(),
                'resolved_provider_type' => (string) ($providerConfig['type'] ?? 'mock'),
                'resolved_via' => (string) ($execution['resolved_via'] ?? 'global'),
                'attempts' => (int) ($execution['attempts'] ?? 1),
                'used_fallback' => !empty($execution['used_fallback']),
            ],
        ];
    }

    /**
     * Runs an explicit, content-free provider health probe for the admin settings page.
     *
     * @return array<string, mixed>
     */
    public function checkProviderHealth(string $providerId = ''): array
    {
        $startedAt = microtime(true);
        $configuration = $this->settings->getConfiguration();
        $execution = $this->executionService->checkHealth($configuration, $providerId);
        $provider = $execution['provider'];
        $providerConfig = $execution['provider_config'];

        return [
            'provider' => $this->buildProviderMeta($provider, $providerConfig, 'healthcheck'),
            'status' => 'healthy',
            'attempts' => (int) ($execution['attempts'] ?? 1),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function translateEditorJsDraft(array $request): array
    {
        $startedAt = microtime(true);
        $configuration = $this->settings->getConfiguration();
        $features = is_array($configuration['features'] ?? null) ? $configuration['features'] : [];
        $translationConfig = is_array($configuration['translation'] ?? null) ? $configuration['translation'] : [];
        $loggingConfig = is_array($configuration['logging'] ?? null) ? $configuration['logging'] : [];
        $quotaConfig = is_array($configuration['quotas'] ?? null) ? $configuration['quotas'] : [];
        $providersConfig = is_array($configuration['providers'] ?? null) ? $configuration['providers'] : [];
        $promptsConfig = is_array($configuration['prompts'] ?? null) ? $configuration['prompts'] : [];

        $this->assertEditorJsTranslationEnabled($features);

        $sourceLocale = $this->normalizeLocale((string) ($request['source_locale'] ?? ($translationConfig['default_source_locale'] ?? 'de')), (string) ($translationConfig['default_source_locale'] ?? 'de'));
        $targetLocale = $this->normalizeLocale((string) ($request['target_locale'] ?? ($translationConfig['default_target_locale'] ?? 'en')), (string) ($translationConfig['default_target_locale'] ?? 'en'));
        $this->assertTargetLocaleAllowed($targetLocale, (array) ($translationConfig['allowed_target_locales'] ?? ['en']));

        $title = trim((string) ($request['title'] ?? ''));
        $excerpt = trim((string) ($request['excerpt'] ?? ''));
        $slug = trim((string) ($request['slug'] ?? ''));
        $contentType = $this->normalizeContentType((string) ($request['content_type'] ?? 'editorjs'));

        $sourceJson = (string) ($request['editor_data'] ?? '');
        $sanitizedJson = $this->editorJsSanitizer->sanitize($sourceJson);
        $editorData = Json::decodeArray($sanitizedJson, ['blocks' => []]);
        $blocks = is_array($editorData['blocks'] ?? null) ? $editorData['blocks'] : [];

        $this->enforceQuotas($title, $excerpt, $sanitizedJson, count($blocks), $quotaConfig);
        $execution = $this->executionService->execute(
            $configuration,
            'translation',
            max(0, (int) ($request['user_id'] ?? 0)),
            $this->measureCharCount($title, $excerpt, $sanitizedJson),
            function (AiProviderInterface $provider) use ($title, $excerpt, $slug, $contentType, $sourceLocale, $targetLocale, $editorData, $translationConfig, $promptsConfig): array {
                return $this->pipeline->translate(
                    [
                        'title' => $title,
                        'excerpt' => $excerpt,
                        'slug' => $slug,
                        'content_type' => $contentType,
                        'source_locale' => $sourceLocale,
                        'target_locale' => $targetLocale,
                        'editor_data' => $editorData,
                    ],
                    $provider,
                    $translationConfig,
                    is_array($promptsConfig['translation'] ?? null) ? $promptsConfig['translation'] : []
                );
            },
            $targetLocale,
            ($request['count_user_request'] ?? true) !== false
        );
        $pipelineResult = is_array($execution['result'] ?? null) ? $execution['result'] : [];
        $provider = $execution['provider'];
        $providerConfig = $execution['provider_config'];
        $this->sanitizeTranslationResult($pipelineResult);

        $telemetry = [
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'requested_provider' => (string) ($providersConfig['active_provider_id'] ?? 'mock'),
            'resolved_provider' => $provider->getSlug(),
            'resolved_provider_type' => (string) ($providerConfig['type'] ?? 'mock'),
            'resolved_via' => (string) ($execution['resolved_via'] ?? 'global'),
            'attempts' => (int) ($execution['attempts'] ?? 1),
            'used_fallback' => !empty($execution['used_fallback']),
            'content_type' => $contentType,
            'source_locale' => $sourceLocale,
            'target_locale' => $targetLocale,
            'prompt_template_enabled' => !empty($promptsConfig['translation']['enabled']),
        ];

        if (!empty($loggingConfig['store_content_hashes'])) {
            $telemetry['source_hash'] = hash('sha256', $sanitizedJson);
            $telemetry['translated_hash'] = hash('sha256', (string) ($pipelineResult['editor_json'] ?? '{"blocks":[]}'));
        }

        if (!empty($loggingConfig['store_request_metrics'])) {
            $telemetry['char_count'] = $this->measureCharCount($title, $excerpt, $sanitizedJson);
            $telemetry['block_count'] = count($blocks);
            $telemetry['translated_blocks'] = (int) (($pipelineResult['stats']['translated_blocks'] ?? 0));
        }

        Logger::instance()->withChannel('ai.service')->info('AI Editor.js-Übersetzung wurde über den globalen Provider verarbeitet.', [
            'provider' => $provider->getSlug(),
            'provider_type' => (string) ($providerConfig['type'] ?? 'mock'),
            'content_type' => $contentType,
            'target_locale' => $targetLocale,
            'duration_ms' => (int) ($telemetry['duration_ms'] ?? 0),
            'translated_blocks' => (int) (($pipelineResult['stats']['translated_blocks'] ?? 0)),
        ]);

        return [
            'provider' => $this->buildProviderMeta($provider, $providerConfig, (string) ($execution['resolved_via'] ?? 'global')),
            'preview_required' => !empty($translationConfig['preview_required']),
            'result_mode' => (string) ($translationConfig['result_mode'] ?? 'localized-field'),
            'warnings' => array_values(array_filter((array) ($pipelineResult['warnings'] ?? []))),
            'translation' => [
                'title' => (string) ($pipelineResult['title'] ?? ''),
                'excerpt' => (string) ($pipelineResult['excerpt'] ?? ''),
                'slug' => (string) ($pipelineResult['slug'] ?? ''),
                'content_data' => is_array($pipelineResult['editor_data'] ?? null) ? $pipelineResult['editor_data'] : ['blocks' => []],
                'content_json' => (string) ($pipelineResult['editor_json'] ?? '{"blocks":[]}'),
            ],
            'stats' => is_array($pipelineResult['stats'] ?? null) ? $pipelineResult['stats'] : [],
            'telemetry' => $telemetry,
        ];
    }

    /**
     * Creates an SEO metadata draft from the supplied primary content without persisting it.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function generateSeoMetadataDraft(array $request): array
    {
        $startedAt = microtime(true);
        $configuration = $this->settings->getConfiguration();
        $features = is_array($configuration['features'] ?? null) ? $configuration['features'] : [];
        $quotaConfig = is_array($configuration['quotas'] ?? null) ? $configuration['quotas'] : [];
        $providersConfig = is_array($configuration['providers'] ?? null) ? $configuration['providers'] : [];
        $promptsConfig = is_array($configuration['prompts'] ?? null) ? $configuration['prompts'] : [];
        $loggingConfig = is_array($configuration['logging'] ?? null) ? $configuration['logging'] : [];

        $contentType = $this->normalizeContentType((string) ($request['content_type'] ?? ''));
        if (!in_array($contentType, ['post', 'page'], true)) {
            throw new \InvalidArgumentException('Für die SEO-Generierung ist ein gültiger Inhaltstyp erforderlich.');
        }

        $locale = $this->normalizeLocale((string) ($request['locale'] ?? 'de'), 'de');
        $sourceText = $this->sanitizeSeoSourceText((string) ($request['source_text'] ?? ''));
        if ($sourceText === '') {
            throw new \InvalidArgumentException('Für die SEO-Generierung ist ein nicht leerer Haupttext erforderlich.');
        }

        $maxCharacters = max(250, (int) ($quotaConfig['max_chars_per_request'] ?? 12000));
        $sourceWasTruncated = $this->countCharacters($sourceText) > $maxCharacters;
        if ($sourceWasTruncated) {
            $sourceText = $this->truncateText($sourceText, $maxCharacters);
        }
        $sourceCharacterCount = $this->countCharacters($sourceText);

        $execution = $this->executionService->execute(
            $configuration,
            'seo_metadata',
            max(0, (int) ($request['user_id'] ?? 0)),
            $sourceCharacterCount,
            function (AiProviderInterface $provider) use ($sourceText, $contentType, $locale, $promptsConfig): array {
                return $this->seoMetadataPipeline->generate(
                    $sourceText,
                    $contentType,
                    $locale,
                    $provider,
                    is_array($promptsConfig['seo_creator'] ?? null) ? $promptsConfig['seo_creator'] : []
                );
            },
            $locale
        );
        $metadata = is_array($execution['result'] ?? null) ? $execution['result'] : [];
        $provider = $execution['provider'];
        $providerConfig = $execution['provider_config'];

        $telemetry = [
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'resolved_provider' => $provider->getSlug(),
            'resolved_provider_type' => (string) ($providerConfig['type'] ?? 'mock'),
            'resolved_via' => (string) ($execution['resolved_via'] ?? 'global'),
            'attempts' => (int) ($execution['attempts'] ?? 1),
            'used_fallback' => !empty($execution['used_fallback']),
            'content_type' => $contentType,
            'locale' => $locale,
            'source_truncated' => $sourceWasTruncated,
            'prompt_template_enabled' => !empty($promptsConfig['seo_creator']['enabled']),
        ];

        if (!empty($loggingConfig['store_content_hashes'])) {
            $telemetry['source_hash'] = hash('sha256', $sourceText);
        }
        if (!empty($loggingConfig['store_request_metrics'])) {
            $telemetry['source_char_count'] = $sourceCharacterCount;
            $telemetry['source_block_count'] = max(0, (int) ($request['block_count'] ?? 0));
        }

        Logger::instance()->withChannel('ai.service')->info('SEO-Metadaten wurden über den globalen AI-Provider als Entwurf erzeugt.', [
            'provider' => $provider->getSlug(),
            'provider_type' => (string) ($providerConfig['type'] ?? 'mock'),
            'content_type' => $contentType,
            'locale' => $locale,
            'duration_ms' => $telemetry['duration_ms'],
            'source_char_count' => $sourceCharacterCount,
            'source_truncated' => $sourceWasTruncated,
        ]);

        return [
            'provider' => $this->buildProviderMeta($provider, $providerConfig, (string) ($execution['resolved_via'] ?? 'global')),
            'metadata' => $metadata,
            'stats' => [
                'generated_field_count' => count($metadata),
                'source_char_count' => $sourceCharacterCount,
                'source_block_count' => max(0, (int) ($request['block_count'] ?? 0)),
                'source_truncated' => $sourceWasTruncated,
            ],
            'telemetry' => $telemetry,
        ];
    }

    /**
     * Creates a reviewable content draft without changing a page, post or public output.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function generateContentDraft(array $request): array
    {
        $startedAt = microtime(true);
        $configuration = $this->settings->getConfiguration();
        $features = is_array($configuration['features'] ?? null) ? $configuration['features'] : [];
        $quotaConfig = is_array($configuration['quotas'] ?? null) ? $configuration['quotas'] : [];
        $providersConfig = is_array($configuration['providers'] ?? null) ? $configuration['providers'] : [];
        $promptsConfig = is_array($configuration['prompts'] ?? null) ? $configuration['prompts'] : [];
        $loggingConfig = is_array($configuration['logging'] ?? null) ? $configuration['logging'] : [];

        $task = $this->normalizeContentDraftTask((string) ($request['task'] ?? ''));
        $brief = $this->sanitizeContentDraftInput((string) ($request['brief'] ?? ''), 2000);
        $context = $this->sanitizeContentDraftInput((string) ($request['context'] ?? ''), 12000);
        $tone = $this->sanitizeContentDraftInput((string) ($request['tone'] ?? ''), 120);
        $locale = $this->normalizeLocale((string) ($request['locale'] ?? 'de'), 'de');
        if ($brief === '' && $context === '') {
            throw new \InvalidArgumentException('Für den Content-Entwurf ist ein Briefing oder Kontext erforderlich.');
        }

        $maxCharacters = max(250, (int) ($quotaConfig['max_chars_per_request'] ?? 12000));
        $sourceText = trim($brief . "\n\n" . $context);
        $sourceWasTruncated = $this->countCharacters($sourceText) > $maxCharacters;
        if ($sourceWasTruncated) {
            $context = $this->truncateText($sourceText, $maxCharacters);
            $brief = '';
            $sourceText = $context;
        }
        $inputCharacterCount = $this->countCharacters($sourceText);

        $execution = $this->executionService->execute(
            $configuration,
            $task === 'summary' ? 'content_summary' : 'content_rewrite',
            max(0, (int) ($request['user_id'] ?? 0)),
            $inputCharacterCount,
            function (AiProviderInterface $provider) use ($task, $brief, $context, $tone, $locale, $promptsConfig): array {
                return $this->contentDraftPipeline->generate(
                    $task,
                    $brief,
                    $context,
                    $tone,
                    $locale,
                    $provider,
                    is_array($promptsConfig['content_creator'] ?? null) ? $promptsConfig['content_creator'] : []
                );
            },
            $locale
        );
        $draft = is_array($execution['result'] ?? null) ? $execution['result'] : [];
        $provider = $execution['provider'];
        $providerConfig = $execution['provider_config'];

        $telemetry = [
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'resolved_provider' => $provider->getSlug(),
            'resolved_provider_type' => (string) ($providerConfig['type'] ?? 'mock'),
            'resolved_via' => (string) ($execution['resolved_via'] ?? 'global'),
            'attempts' => (int) ($execution['attempts'] ?? 1),
            'used_fallback' => !empty($execution['used_fallback']),
            'task' => $task,
            'locale' => $locale,
            'source_truncated' => $sourceWasTruncated,
            'prompt_template_enabled' => !empty($promptsConfig['content_creator']['enabled']),
        ];

        if (!empty($loggingConfig['store_content_hashes'])) {
            $telemetry['source_hash'] = hash('sha256', $sourceText);
        }
        if (!empty($loggingConfig['store_request_metrics'])) {
            $telemetry['input_char_count'] = $inputCharacterCount;
        }

        Logger::instance()->withChannel('ai.service')->info('Content-Entwurf wurde über den globalen AI-Provider erzeugt.', [
            'provider' => $provider->getSlug(),
            'provider_type' => (string) ($providerConfig['type'] ?? 'mock'),
            'task' => $task,
            'locale' => $locale,
            'duration_ms' => $telemetry['duration_ms'],
            'input_char_count' => $inputCharacterCount,
            'source_truncated' => $sourceWasTruncated,
        ]);

        return [
            'provider' => $this->buildProviderMeta($provider, $providerConfig, (string) ($execution['resolved_via'] ?? 'global')),
            'draft' => $draft,
            'stats' => [
                'input_char_count' => $inputCharacterCount,
                'source_truncated' => $sourceWasTruncated,
            ],
            'telemetry' => $telemetry,
        ];
    }


    /** @param array<string, mixed> $features */
    private function assertAiEnabled(array $features): void
    {
        if (empty($features['ai_services_enabled'])) {
            throw new \RuntimeException('AI Services sind aktuell global deaktiviert.');
        }
    }

    /** @param array<string, mixed> $features */
    private function assertEditorJsTranslationEnabled(array $features): void
    {
        $this->assertAiEnabled($features);

        if (empty($features['ai_translation_enabled'])) {
            throw new \RuntimeException('AI-Übersetzungen sind aktuell global deaktiviert.');
        }

        if (empty($features['ai_editorjs_enabled'])) {
            throw new \RuntimeException('Die Editor.js-Integration für AI ist aktuell deaktiviert.');
        }
    }



    /** @param list<string> $allowedTargetLocales */
    private function assertTargetLocaleAllowed(string $targetLocale, array $allowedTargetLocales): void
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn (string $locale): string => $this->normalizeLocale($locale, ''),
            $allowedTargetLocales
        ))));

        if ($normalized === []) {
            $normalized = ['en'];
        }

        if (!in_array($targetLocale, $normalized, true)) {
            throw new \InvalidArgumentException('Die gewählte Zielsprache ist für die aktuelle AI-Konfiguration nicht freigegeben.');
        }
    }

    /** @param array<string, mixed> $quotaConfig */
    private function enforceQuotas(string $title, string $excerpt, string $editorJson, int $blockCount, array $quotaConfig): void
    {
        $charCount = $this->measureCharCount($title, $excerpt, $editorJson);
        $maxChars = max(250, (int) ($quotaConfig['max_chars_per_request'] ?? 12000));
        $maxBlocks = max(1, (int) ($quotaConfig['max_blocks_per_request'] ?? 40));

        if ($charCount > $maxChars) {
            throw new \InvalidArgumentException('Die Übersetzungsanfrage überschreitet das aktuell erlaubte Zeichenlimit.');
        }

        if ($blockCount > $maxBlocks) {
            throw new \InvalidArgumentException('Die Übersetzungsanfrage überschreitet die aktuell erlaubte Blockanzahl.');
        }
    }

    private function measureCharCount(string $title, string $excerpt, string $editorJson): int
    {
        $payload = $title . "\n" . $excerpt . "\n" . $editorJson;

        return function_exists('mb_strlen')
            ? mb_strlen($payload, 'UTF-8')
            : strlen($payload);
    }

    private function sanitizeSeoSourceText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /** @param array<string, mixed> $pipelineResult */
    private function sanitizeTranslationResult(array &$pipelineResult): void
    {
        $editorData = is_array($pipelineResult['editor_data'] ?? null) ? $pipelineResult['editor_data'] : ['blocks' => []];
        $encoded = json_encode($editorData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('Die übersetzte Editor.js-Struktur konnte nicht serialisiert werden.');
        }

        $sanitized = $this->editorJsSanitizer->sanitize($encoded);
        $sanitizedData = Json::decodeArray($sanitized, ['blocks' => []]);
        if (!is_array($sanitizedData['blocks'] ?? null)) {
            throw new \RuntimeException('Die übersetzte Editor.js-Struktur ist ungültig.');
        }

        $pipelineResult['editor_data'] = $sanitizedData;
        $pipelineResult['editor_json'] = $sanitized;
    }

    private function sanitizeContentDraftInput(string $value, int $maxLength): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/[ \t]+/u', ' ', $value) ?? '');

        return $this->truncateText($value, $maxLength);
    }

    private function countCharacters(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }

    private function truncateText(string $value, int $maxCharacters): string
    {
        if ($this->countCharacters($value) <= $maxCharacters) {
            return $value;
        }

        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxCharacters, 'UTF-8')
            : substr($value, 0, $maxCharacters);
    }

    private function normalizeContentDraftTask(string $value): string
    {
        $value = strtolower(trim($value));

        if (!in_array($value, ['summary', 'outline', 'cta'], true)) {
            throw new \InvalidArgumentException('Die gewählte Content-Aktion ist nicht erlaubt.');
        }

        return $value;
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     * @return list<array{role:string,content:string}>
     */
    private function sanitizeMessages(array $messages): array
    {
        $sanitized = [];
        foreach ($messages as $message) {
            $role = strtolower(trim((string) ($message['role'] ?? 'user')));
            if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                $role = 'user';
            }

            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $sanitized[] = ['role' => $role, 'content' => $content];
        }

        if ($sanitized === []) {
            throw new \InvalidArgumentException('AI-Request enthält keine Nachricht.');
        }

        return $sanitized;
    }

    /** @param array<string, mixed> $providerConfig */
    private function buildProviderMeta(AiProviderInterface $provider, array $providerConfig, string $resolvedVia): array
    {
        return [
            'slug' => $provider->getSlug(),
            'type' => (string) ($providerConfig['type'] ?? 'mock'),
            'id' => (string) ($providerConfig['id'] ?? $provider->getSlug()),
            'label' => $provider->getLabel(),
            'model' => (string) ($providerConfig['default_model'] ?? $provider->getDefaultModel()),
            'mock' => $provider->isMock(),
            'resolved_via' => $resolvedVia,
        ];
    }

    private function normalizeLocale(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '', $value) ?? '';

        return $value !== '' ? $value : $fallback;
    }

    private function normalizeContentType(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['post', 'page'], true) ? $value : 'editorjs';
    }

    private function sanitizeProviderId(string $providerId): string
    {
        $providerId = strtolower(trim($providerId));
        $providerId = preg_replace('/[^a-z0-9._-]+/', '-', $providerId) ?? '';
        $providerId = preg_replace('/-+/', '-', $providerId) ?? '';

        return trim($providerId, '-');
    }
}
