<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$cms = $root . 'CMS' . DIRECTORY_SEPARATOR;

/** @throws RuntimeException */
function aiAdminContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$files = [
    'aiPage' => $cms . 'admin/ai-page.php',
    'adminView' => $cms . 'admin/views/system/ai-services.php',
    'adminAsset' => $cms . 'assets/js/admin-ai-services.js',
    'publicRouter' => $cms . 'core/Routing/PublicRouter.php',
    'execution' => $cms . 'core/Services/AI/AiExecutionService.php',
    'policy' => $cms . 'core/Services/AI/AiProviderPolicyService.php',
    'quota' => $cms . 'core/Services/AI/AiQuotaService.php',
    'quotaAwareProvider' => $cms . 'core/Services/AI/QuotaAwareAiProvider.php',
    'settings' => $cms . 'core/Services/AI/AiSettingsService.php',
    'factory' => $cms . 'core/Services/AI/AiProviderFactory.php',
    'ollama' => $cms . 'core/Services/AI/Providers/OllamaAiProvider.php',
    'openAiCompatible' => $cms . 'core/Services/AI/Providers/OpenAiCompatibleProvider.php',
    'translationModule' => $cms . 'admin/modules/system/AiEditorJsTranslationModule.php',
    'seoModule' => $cms . 'admin/modules/system/AiEditorJsSeoMetadataModule.php',
    'service' => $cms . 'core/Services/AI/AiService.php',
];
foreach ($files as $label => $file) {
    aiAdminContractAssert(is_file($file), 'Erforderliche AI-Vertragsdatei fehlt: ' . $label);
    $content = file_get_contents($file);
    aiAdminContractAssert(is_string($content) && $content !== '', 'AI-Vertragsdatei ist nicht lesbar: ' . $label);
    $files[$label] = $content;
}

aiAdminContractAssert(str_contains($files['aiPage'], "'check_provider_health'"), 'Provider-Healthcheck ist nicht nur auf der AI-Adminseite registriert.');
aiAdminContractAssert(str_contains($files['aiPage'], "'delete_provider'"), 'Sichere Provider-Löschung ist nicht nur auf der AI-Adminseite registriert.');
aiAdminContractAssert(!str_contains($files['publicRouter'], 'ai-generate-seo-metadata'), 'SEO-AI-Endpunkt darf nicht im PublicRouter registriert sein.');
aiAdminContractAssert(!str_contains($files['publicRouter'], 'generate_content_draft'), 'Content-Creator darf nicht im PublicRouter registriert sein.');
aiAdminContractAssert(!str_contains($files['publicRouter'], 'ai-translate-editorjs'), 'Translation darf nicht im PublicRouter registriert sein.');
aiAdminContractAssert(!str_contains(strtolower($files['adminView']), '<script'), 'Die AI-Admin-View darf kein CSP-inkompatibles Inline-Skript enthalten.');
aiAdminContractAssert(str_contains($files['adminAsset'], 'bindProviderDeletionConfirm'), 'Die Provider-Löschbestätigung fehlt im dedizierten Admin-Asset.');
aiAdminContractAssert(str_contains($files['execution'], 'quotaService->reserve'), 'AI-Ausführung reserviert kein Kontingent vor Provider-Aufrufen.');
aiAdminContractAssert(str_contains($files['execution'], 'QuotaAwareAiProvider'), 'AI-Ausführung zählt keine einzelnen Provider-Aufrufe.');
aiAdminContractAssert(str_contains($files['execution'], '$quotaAwareProvider->complete'), 'Live-Healthchecks werden nicht gegen die Provider-Quota gezählt.');
aiAdminContractAssert(str_contains($files['execution'], "'fallback'"), 'AI-Ausführung enthält keinen Fallback-Pfad.');
aiAdminContractAssert(str_contains($files['policy'], 'ai_external_provider_data_sharing_enabled'), 'External-Egress-Gate wird nicht erzwungen.');
aiAdminContractAssert(str_contains($files['policy'], 'ai_beta_providers_enabled'), 'Beta-Gate wird nicht erzwungen.');
aiAdminContractAssert(str_contains($files['quota'], 'FOR UPDATE'), 'AI-Quota-Reservierung ist nicht atomar gesperrt.');
aiAdminContractAssert(str_contains($files['quota'], 'reserveProviderCall'), 'AI-Quota trennt Provider-Aufrufe nicht von Benutzeroperationen.');
aiAdminContractAssert(str_contains($files['quotaAwareProvider'], 'reserveProviderCall'), 'Provider-Aufrufe werden nicht vor jeder Batch-/Completion-Anfrage reserviert.');
aiAdminContractAssert(str_contains($files['factory'], 'assertHttpsEndpoint'), 'Cloud-AI-Endpoints werden nicht auf HTTPS erzwungen.');
aiAdminContractAssert(str_contains($files['settings'], 'getProviderReadiness'), 'AI-Admin besitzt keine zentrale Readiness-Prüfung.');
aiAdminContractAssert(str_contains($files['settings'], "'all' => 'Alle Funktionen'"), 'Das Betriebsprofil „Alle Funktionen“ ist nicht zentral definiert.');
aiAdminContractAssert(str_contains($files['adminView'], '$providerProfiles'), 'Die AI-Admin-View rendert Betriebsprofile nicht zentral.');
aiAdminContractAssert(substr_count($files['service'], '$locale') >= 8, 'SEO-/Content-Entwürfe erzwingen keine Provider-Locale-Policy.');
aiAdminContractAssert(str_contains($files['openAiCompatible'], 'HTTP-Status'), 'Providerfehler bewahren HTTP-Status nicht für Retry/Fallback.');
aiAdminContractAssert(!str_contains($files['ollama'], "'allowPrivateHosts' => true"), 'Ollama darf den globalen SSRF-Schutz nicht deaktivieren.');
aiAdminContractAssert(str_contains($files['ollama'], 'allowedPrivateHosts'), 'Ollama benötigt eine explizite Private-Host-Allowlist.');
aiAdminContractAssert(str_contains($files['translationModule'], "'user_id' => \$userId"), 'Translation übergibt keinen Benutzer an die Quota-Schicht.');
aiAdminContractAssert(str_contains($files['seoModule'], "'user_id' => \$userId"), 'SEO-Generator übergibt keinen Benutzer an die Quota-Schicht.');
aiAdminContractAssert(str_contains($files['service'], 'sanitizeTranslationResult'), 'Provider-Übersetzungen werden nicht serverseitig erneut sanitisiert.');
aiAdminContractAssert(str_contains($files['translationModule'], 'resolveChunkSession'), 'Blockweise Übersetzung besitzt keine serverseitige Sitzungsprüfung.');
aiAdminContractAssert(str_contains($files['quota'], '[self::USER_DAILY_CHARACTERS, $today, $userId, \'\', 0, $characterCount]'), 'Folge-Chunks der blockweisen Übersetzung reservieren keine Zeichen-Quota.');

echo "AI admin contract tests passed.\n";
