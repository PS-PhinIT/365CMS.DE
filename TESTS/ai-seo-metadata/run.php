<?php
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'CMS' . DIRECTORY_SEPARATOR);

require ABSPATH . 'core/Services/AI/AiProviderInterface.php';
require ABSPATH . 'core/Services/AI/Providers/MockAiProvider.php';
require ABSPATH . 'core/Services/AI/SeoMetadataGenerationPipeline.php';

use CMS\Services\AI\AiProviderInterface;
use CMS\Services\AI\Providers\MockAiProvider;
use CMS\Services\AI\SeoMetadataGenerationPipeline;

final class SeoMetadataTestProvider implements AiProviderInterface
{
    /** @var list<array{role:string,content:string}> */
    public array $messages = [];

    public function getSlug(): string
    {
        return 'test';
    }

    public function getLabel(): string
    {
        return 'Test Provider';
    }

    public function isMock(): bool
    {
        return false;
    }

    public function getDefaultModel(): string
    {
        return 'test-model';
    }

    public function complete(array $messages, array $options = []): string
    {
        $this->messages = $messages;

        return json_encode([
            'excerpt' => 'Cloud-Migration sicher planen und strukturiert umsetzen.',
            'focus_keyphrase' => 'Cloud Migration planen',
            'keywords' => ['Cloud-Migration', 'Azure Strategie', 'IT Modernisierung'],
            'meta_title' => 'Cloud-Migration sicher planen',
            'meta_description' => '<strong>Cloud-Migration</strong> strukturiert planen und Risiken minimieren.',
            'og_title' => 'Cloud-Migration mit klarer Strategie',
            'og_description' => 'Schritt für Schritt zur sicheren Cloud-Migration.',
            'twitter_title' => 'Cloud-Migration planen',
            'twitter_description' => 'Leitfaden für die sichere IT-Modernisierung.',
            'twitter_card' => 'summary_large_image',
            'schema_type' => 'Article',
            'sitemap_priority' => '0.7',
            'sitemap_changefreq' => 'monthly',
            'robots_index' => true,
            'robots_follow' => true,
            'title' => 'DARF NICHT ÜBERNOMMEN WERDEN',
            'slug' => 'darf-nicht-uebernommen-werden',
            'canonical_url' => 'https://example.invalid/forbidden',
            'og_image' => 'https://example.invalid/og.jpg',
            'twitter_image' => 'https://example.invalid/twitter.jpg',
            'hreflang_group' => 'forbidden-group',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function translateBatch(array $segments, array $context = []): array
    {
        return $segments;
    }
}

/** @throws RuntimeException */
function aiSeoMetadataAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pipeline = new SeoMetadataGenerationPipeline();
$provider = new SeoMetadataTestProvider();
$result = $pipeline->generate(
    'Cloud-Migration benötigt eine klare Strategie. Der Haupttext erklärt die sichere Modernisierung der IT-Landschaft.',
    'post',
    'de',
    $provider,
    [
        'enabled' => true,
        'system_prompt' => 'Benutzerdefinierte SEO-Tonalität.',
        'user_template' => 'Darstellung ohne Haupttext.',
    ]
);

$expectedFields = [
    'excerpt', 'focus_keyphrase', 'keywords', 'meta_title', 'meta_description', 'og_title', 'og_description',
    'twitter_title', 'twitter_description', 'twitter_card', 'schema_type', 'sitemap_priority',
    'sitemap_changefreq', 'robots_index', 'robots_follow',
];
foreach ($expectedFields as $field) {
    aiSeoMetadataAssert(array_key_exists($field, $result), 'SEO-Feld fehlt: ' . $field);
    aiSeoMetadataAssert($result[$field] !== '', 'SEO-Feld ist leer: ' . $field);
}

aiSeoMetadataAssert($result['meta_description'] === 'Cloud-Migration strukturiert planen und Risiken minimieren.', 'HTML wurde nicht aus der Meta-Beschreibung entfernt.');
aiSeoMetadataAssert($result['keywords'] === 'Cloud-Migration, Azure Strategie, IT Modernisierung', 'Keywords wurden nicht normalisiert.');
aiSeoMetadataAssert($result['schema_type'] === 'Article', 'Schema-Typ wurde nicht übernommen.');
aiSeoMetadataAssert($result['robots_index'] === true && $result['robots_follow'] === true, 'Robots-Direktiven wurden nicht übernommen.');
aiSeoMetadataAssert(str_contains((string) ($provider->messages[0]['content'] ?? ''), '"focus_keyphrase":"string"'), 'Der unveränderliche SEO-JSON-Vertrag fehlt im System-Prompt.');
aiSeoMetadataAssert(str_contains((string) ($provider->messages[1]['content'] ?? ''), 'INPUT_JSON:'), 'Der unveränderliche SEO-Input-Payload fehlt bei einer benutzerdefinierten Vorlage.');

foreach (['title', 'slug', 'canonical_url', 'og_image', 'twitter_image', 'hreflang_group'] as $forbiddenField) {
    aiSeoMetadataAssert(!array_key_exists($forbiddenField, $result), 'Unzulässiges Feld wurde aus der AI-Antwort übernommen: ' . $forbiddenField);
}

$mockResult = $pipeline->generate(
    'Diese ausführliche Cloud Migration erklärt moderne IT Strategien für Unternehmen.',
    'page',
    'de',
    new MockAiProvider()
);
foreach ($expectedFields as $field) {
    aiSeoMetadataAssert(array_key_exists($field, $mockResult), 'Mock-Fallback enthält nicht alle SEO-Felder: ' . $field);
    aiSeoMetadataAssert($mockResult[$field] !== '', 'Mock-Fallback hat ein leeres SEO-Feld: ' . $field);
}
aiSeoMetadataAssert($mockResult['schema_type'] === 'WebPage', 'Mock-Fallback nutzt für Seiten nicht WebPage als Schema-Typ.');

echo "AI SEO metadata generation tests passed.\n";
