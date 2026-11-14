<?php
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'CMS' . DIRECTORY_SEPARATOR);

require ABSPATH . 'core/Services/AI/AiProviderPolicyService.php';

use CMS\Services\AI\AiProviderPolicyService;

/** @throws RuntimeException */
function aiPolicyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$policy = new AiProviderPolicyService();
$features = [
    'ai_services_enabled' => true,
    'ai_translation_enabled' => true,
    'ai_editorjs_enabled' => true,
    'ai_seo_meta_enabled' => true,
    'ai_summary_enabled' => true,
    'ai_rewrite_enabled' => true,
    'ai_beta_providers_enabled' => false,
    'ai_external_provider_data_sharing_enabled' => false,
];
$mockProvider = [
    'id' => 'mock',
    'type' => 'mock',
    'enabled' => true,
    'profile' => 'editor-translation',
    'translation_enabled' => true,
    'editorjs_enabled' => true,
    'seo_meta_enabled' => true,
    'summary_enabled' => true,
    'rewrite_enabled' => true,
    'beta_only' => false,
];

$policy->assertFeatureAllowed($features, $mockProvider, 'translation');
$policy->assertFeatureAllowed($features, $mockProvider, 'seo_metadata');
$policy->assertFeatureAllowed($features, $mockProvider, 'content_summary');
$policy->assertFeatureAllowed($features, $mockProvider, 'content_rewrite');

$allFunctionsProvider = array_replace($mockProvider, ['profile' => 'all']);
$policy->assertFeatureAllowed($features, $allFunctionsProvider, 'translation');
$policy->assertFeatureAllowed($features, $allFunctionsProvider, 'seo_metadata');
$policy->assertFeatureAllowed($features, $allFunctionsProvider, 'content_summary');
$policy->assertFeatureAllowed($features, $allFunctionsProvider, 'content_rewrite');

$externalProvider = array_replace($mockProvider, ['type' => 'openai']);
$externalRejected = false;
try {
    $policy->assertFeatureAllowed($features, $externalProvider, 'translation');
} catch (RuntimeException) {
    $externalRejected = true;
}
aiPolicyAssert($externalRejected, 'Externe Datenweitergabe wurde ohne explizites Gate zugelassen.');

$features['ai_external_provider_data_sharing_enabled'] = true;
$betaProvider = array_replace($externalProvider, ['profile' => 'beta', 'beta_only' => true]);
$betaRejected = false;
try {
    $policy->assertFeatureAllowed($features, $betaProvider, 'translation');
} catch (RuntimeException) {
    $betaRejected = true;
}
aiPolicyAssert($betaRejected, 'Beta-Provider wurde ohne explizites Gate zugelassen.');

$features['ai_beta_providers_enabled'] = true;
$policy->assertFeatureAllowed($features, $betaProvider, 'translation');

$restrictedProvider = array_replace($mockProvider, ['seo_meta_enabled' => false]);
$seoScopeRejected = false;
try {
    $policy->assertFeatureAllowed($features, $restrictedProvider, 'seo_metadata');
} catch (RuntimeException) {
    $seoScopeRejected = true;
}
aiPolicyAssert($seoScopeRejected, 'Deaktivierter Provider-SEO-Scope wurde zugelassen.');

echo "AI policy tests passed.\n";
