<?php
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'CMS' . DIRECTORY_SEPARATOR);

require ABSPATH . 'core/Services/AI/AiProviderInterface.php';
require ABSPATH . 'core/Services/AI/Providers/AbstractPromptingAiProvider.php';
require ABSPATH . 'core/Services/AI/EditorJsTranslationPipeline.php';

use CMS\Services\AI\AiProviderInterface;
use CMS\Services\AI\EditorJsTranslationPipeline;
use CMS\Services\AI\Providers\AbstractPromptingAiProvider;

final class IndexedEditorJsTranslationTestProvider extends AbstractPromptingAiProvider
{
    public function __construct()
    {
        parent::__construct('indexed-test', 'Indexed Test Provider', 'test-model');
    }

    public function complete(array $messages, array $options = []): string
    {
        return '';
    }

    public function translateBatch(array $segments, array $context = []): array
    {
        return $this->parseResponse(
            '{"translations":[{"index":1,"translation":"two"},{"index":0,"translation":"one"}]}',
            $segments
        );
    }

    /** @param list<string> $segments @return list<string> */
    public function parseResponse(string $response, array $segments): array
    {
        return $this->extractTranslationsFromResponse($response, $segments);
    }
}

final class EditorJsTranslationBatchTestProvider implements AiProviderInterface
{
    /** @var list<list<string>> */
    public array $batches = [];

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
        return true;
    }

    public function getDefaultModel(): string
    {
        return 'test-model';
    }

    public function complete(array $messages, array $options = []): string
    {
        return '';
    }

    public function translateBatch(array $segments, array $context = []): array
    {
        $this->batches[] = $segments;

        return array_map(static fn (string $segment): string => 'EN ' . $segment, $segments);
    }
}

/** @throws RuntimeException */
function aiEditorJsTranslationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$provider = new EditorJsTranslationBatchTestProvider();
$indexedTranslations = (new IndexedEditorJsTranslationTestProvider())->translateBatch(['eins', 'zwei']);
aiEditorJsTranslationAssert($indexedTranslations === ['one', 'two'], 'Indexierte Live-Provider-Antworten müssen in Segmentreihenfolge übernommen werden.');
$oneBasedTranslations = (new IndexedEditorJsTranslationTestProvider())->parseResponse(
    '[{"index":2,"translation":"two"},{"index":1,"translation":"one"}]',
    ['eins', 'zwei']
);
aiEditorJsTranslationAssert($oneBasedTranslations === ['one', 'two'], '1-basierte indexierte Provider-Antworten müssen deterministisch normalisiert werden.');
$blocks = [];
for ($index = 0; $index < 5; $index++) {
    $blocks[] = [
        'type' => 'paragraph',
        'data' => ['text' => str_repeat((string) ($index + 1), 1000)],
    ];
}

$result = EditorJsTranslationPipeline::getInstance()->translate(
    [
        'title' => 'Langer Testartikel',
        'source_locale' => 'de',
        'target_locale' => 'en',
        'editor_data' => ['blocks' => $blocks],
    ],
    $provider,
    [
        'supported_block_types' => ['paragraph'],
        'preserve_unsupported_blocks' => true,
    ]
);

aiEditorJsTranslationAssert(count($provider->batches) === 3, 'Große Übersetzungen müssen in konservative Provider-Batches aufgeteilt werden.');
aiEditorJsTranslationAssert(($result['stats']['translation_batches'] ?? 0) === 3, 'Die Batch-Anzahl fehlt in den Übersetzungsstatistiken.');
aiEditorJsTranslationAssert(($result['stats']['translated_segments'] ?? 0) === 6, 'Alle Textsegmente einschließlich Titel müssen übersetzt werden.');
aiEditorJsTranslationAssert(($result['title'] ?? '') === 'EN Langer Testartikel', 'Der Titel wurde nicht aus dem ersten Batch übernommen.');

foreach ($provider->batches as $batch) {
    $characterCount = array_sum(array_map(
        static fn (string $text): int => function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text),
        $batch
    ));
    aiEditorJsTranslationAssert($characterCount <= 2400, 'Ein Übersetzungs-Batch überschreitet die sichere Zeichengrenze.');
}

foreach ($result['editor_data']['blocks'] ?? [] as $index => $block) {
    aiEditorJsTranslationAssert(
        ($block['data']['text'] ?? '') === 'EN ' . str_repeat((string) ($index + 1), 1000),
        'Block ' . $index . ' wurde nicht in der ursprünglichen Reihenfolge übernommen.'
    );
}

$oversizedSegmentRejected = false;
try {
    EditorJsTranslationPipeline::getInstance()->translate(
        [
            'editor_data' => [
                'blocks' => [[
                    'type' => 'paragraph',
                    'data' => ['text' => str_repeat('x', 2401)],
                ]],
            ],
        ],
        new EditorJsTranslationBatchTestProvider(),
        ['supported_block_types' => ['paragraph']]
    );
} catch (InvalidArgumentException) {
    $oversizedSegmentRejected = true;
}
aiEditorJsTranslationAssert($oversizedSegmentRejected, 'Ein übergroßes einzelnes Übersetzungssegment wurde nicht abgelehnt.');

$executionServiceSource = (string) file_get_contents(ABSPATH . 'core/Services/AI/AiExecutionService.php');
aiEditorJsTranslationAssert(str_contains($executionServiceSource, 'übersetzungs-batch-vertrag'), 'Ungültige Übersetzungsbatches lösen keinen zentralen Retry-/Fallback-Pfad aus.');

echo "AI EditorJS translation batching tests passed.\n";
