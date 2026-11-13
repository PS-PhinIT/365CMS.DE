<?php
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'CMS' . DIRECTORY_SEPARATOR);

require ABSPATH . 'core/Services/AI/AiProviderInterface.php';
require ABSPATH . 'core/Services/AI/Providers/MockAiProvider.php';
require ABSPATH . 'core/Services/AI/ContentDraftGenerationPipeline.php';

use CMS\Services\AI\AiProviderInterface;
use CMS\Services\AI\ContentDraftGenerationPipeline;
use CMS\Services\AI\Providers\MockAiProvider;

final class ContentDraftTestProvider implements AiProviderInterface
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

        return '{"content":"<strong>Prüfbarer</strong> Content-Entwurf\n<script>alert(1)</script>"}';
    }

    public function translateBatch(array $segments, array $context = []): array
    {
        return $segments;
    }
}

/** @throws RuntimeException */
function aiContentCreatorAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pipeline = new ContentDraftGenerationPipeline();
$summary = $pipeline->generate('summary', 'Neue Cloud-Strategie erklären.', 'Der Entwurf richtet sich an IT-Verantwortliche.', 'sachlich', 'de', new MockAiProvider());
aiContentCreatorAssert($summary['task'] === 'summary', 'Der Summary-Task wurde nicht beibehalten.');
aiContentCreatorAssert($summary['content'] !== '', 'Der Mock-Summary-Entwurf ist leer.');

$outline = $pipeline->generate('outline', 'Einführung in Cloud-Migration.', '', '', 'de', new MockAiProvider());
aiContentCreatorAssert(str_contains($outline['content'], '## Ziel'), 'Der Mock-Outline-Entwurf enthält keine Gliederung.');

$liveProvider = new ContentDraftTestProvider();
$liveDraft = $pipeline->generate('cta', 'Beratung bewerben.', 'Keine Garantien versprechen.', 'prägnant', 'de', $liveProvider, [
    'enabled' => true,
    'system_prompt' => 'Benutzerdefinierte Tonalität.',
    'user_template' => 'Nur eine Darstellung ohne die kanonischen Daten.',
]);
aiContentCreatorAssert($liveDraft['task'] === 'cta', 'Der CTA-Task wurde nicht beibehalten.');
aiContentCreatorAssert($liveDraft['content'] === "Prüfbarer Content-Entwurf\nalert(1)", 'Provider-Ausgabe wurde nicht als reiner Text bereinigt.');
aiContentCreatorAssert(str_contains((string) ($liveProvider->messages[0]['content'] ?? ''), '{"content":"..."}'), 'Der unveränderliche JSON-Antwortvertrag fehlt im System-Prompt.');
aiContentCreatorAssert(str_contains((string) ($liveProvider->messages[1]['content'] ?? ''), 'INPUT_JSON:'), 'Der unveränderliche Input-Payload fehlt bei einer benutzerdefinierten Vorlage.');
aiContentCreatorAssert(str_contains((string) ($liveProvider->messages[1]['content'] ?? ''), '"locale":"de"'), 'Die Ausgabesprache fehlt im kanonischen Content-Payload.');

try {
    $pipeline->generate('publish', 'Unzulässige Aktion.', '', '', 'de', new MockAiProvider());
    throw new RuntimeException('Unzulässige Content-Aktion wurde nicht abgelehnt.');
} catch (InvalidArgumentException) {
}

$aiPageSource = file_get_contents(ABSPATH . 'admin/ai-page.php');
aiContentCreatorAssert(is_string($aiPageSource) && str_contains($aiPageSource, "'generate_content_draft'"), 'Die Content-Creator-Aktion ist nicht auf der Admin-Route freigegeben.');
aiContentCreatorAssert(!is_file(ABSPATH . 'themes/cms-default/ai-content-creator.php'), 'Content Creator darf keine neue Public-Theme-Datei erzeugen.');

echo "AI Content Creator tests passed.\n";
