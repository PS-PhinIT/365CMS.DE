<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CMS_ADMIN_AI_VIEW')) {
    exit;
}

$providersData = is_array($data['providers'] ?? null) ? $data['providers'] : [];
$providers = array_values(array_filter(
    (array) ($providersData['entries'] ?? []),
    static fn (mixed $entry): bool => is_array($entry)
));
$providerCatalog = is_array($providersData['catalog'] ?? null) ? $providersData['catalog'] : [];
$providerProfiles = is_array($providersData['profiles'] ?? null) ? $providersData['profiles'] : [
    'all' => 'Alle Funktionen',
    'editor-translation' => 'Editor.js-Übersetzung',
    'content-assist' => 'Content Assist',
    'seo-assist' => 'SEO Assist',
    'beta' => 'Nur Beta',
    'disabled' => 'Deaktiviert',
];
$providerOptions = [];
$providerValuesByType = [];
foreach ($providers as $provider) {
    $providerId = (string) ($provider['id'] ?? '');
    if ($providerId === '') {
        continue;
    }

    $providerOptions[$providerId] = $provider;
    $providerType = (string) ($provider['type'] ?? '');
    if ($providerType !== '') {
        $providerValuesByType[$providerType] = [
            'label' => (string) ($provider['label'] ?? ''),
            'enabled' => !empty($provider['enabled']),
            'model' => (string) ($provider['default_model'] ?? ''),
            'endpoint' => (string) ($provider['endpoint'] ?? ''),
            'deployment' => (string) ($provider['deployment'] ?? ''),
            'api_version' => (string) ($provider['api_version'] ?? ''),
            'allowed_locales' => implode(',', (array) ($provider['allowed_locales'] ?? ['de', 'en'])),
            'allowed_internal_hosts' => implode(',', (array) ($provider['allowed_internal_hosts'] ?? [])),
            'profile' => (string) ($provider['profile'] ?? 'editor-translation'),
            'translation_enabled' => !empty($provider['translation_enabled']),
            'rewrite_enabled' => !empty($provider['rewrite_enabled']),
            'summary_enabled' => !empty($provider['summary_enabled']),
            'seo_meta_enabled' => !empty($provider['seo_meta_enabled']),
            'editorjs_enabled' => !empty($provider['editorjs_enabled']),
            'beta_only' => !empty($provider['beta_only']),
            'secret_configured' => !empty($provider['secret_configured']),
        ];
    }
}
$activeProviderId = (string) ($providersData['active_provider_id'] ?? '');
$activeProvider = is_array($providerOptions[$activeProviderId] ?? null) ? $providerOptions[$activeProviderId] : (is_array($providers[0] ?? null) ? $providers[0] : []);
$activeProviderType = (string) ($activeProvider['type'] ?? 'mock');
$activeProviderLabel = (string) (($providerOptions[$activeProviderId]['label'] ?? '') ?: '—');
$fallbackProviderId = (string) ($providersData['fallback_provider_id'] ?? '');
$providerHealth = is_array($alert['report_payload']['provider_health'] ?? null) ? $alert['report_payload']['provider_health'] : [];
$features = is_array($data['features'] ?? null) ? $data['features'] : [];
$translation = is_array($data['translation'] ?? null) ? $data['translation'] : [];
$logging = is_array($data['logging'] ?? null) ? $data['logging'] : [];
$quotas = is_array($data['quotas'] ?? null) ? $data['quotas'] : [];
$prompts = is_array($data['prompts'] ?? null) ? $data['prompts'] : [];
$translationPromptTemplate = is_array($prompts['translation'] ?? null) ? $prompts['translation'] : [];
$contentPromptTemplate = is_array($prompts['content_creator'] ?? null) ? $prompts['content_creator'] : [];
$seoPromptTemplate = is_array($prompts['seo_creator'] ?? null) ? $prompts['seo_creator'] : [];
$summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
$monitoring = is_array($data['monitoring'] ?? null) ? $data['monitoring'] : [];
$currentUserMonitoring = is_array($monitoring['current_user'] ?? null) ? $monitoring['current_user'] : [];
$activeProviderMonitoring = is_array($monitoring['active_provider'] ?? null) ? $monitoring['active_provider'] : [];
$providerBreakdown = array_values(array_filter(
    (array) ($monitoring['provider_breakdown'] ?? []),
    static fn (mixed $entry): bool => is_array($entry)
));
$generationHistory = array_values(array_filter(
    (array) ($data['generation_history'] ?? []),
    static fn (mixed $entry): bool => is_array($entry)
));
$currentSection = $currentSection ?? 'overview';
$navItems = [
    'overview' => ['label' => 'Dashboard', 'url' => '/admin/ai-services'],
    'translation' => ['label' => 'Übersetzung', 'url' => '/admin/ai-translation'],
    'content_creator' => ['label' => 'Inhaltsassistent', 'url' => '/admin/ai-content-creator'],
    'seo_creator' => ['label' => 'SEO-Assistent', 'url' => '/admin/ai-seo-creator'],
    'settings' => ['label' => 'Einstellungen', 'url' => '/admin/ai-settings'],
];
$loggingModes = [
    'minimal' => 'Minimal',
    'technical' => 'Technical',
    'debug-no-content' => 'Debug ohne Rohinhalt',
];
$resultModes = [
    'preview' => 'Nur Preview / keine Persistenz',
    'localized-field' => 'In separates Sprachfeld zurückführen',
    'overwrite-current-draft' => 'Aktuellen Draft überschreiben',
];
$statusBadge = static fn (bool $condition): string => $condition ? 'success' : 'secondary';
$isCurrentSection = static fn (string $section): bool => $currentSection === $section;
$isSelected = static fn (string $value, string $expected): string => $value === $expected ? 'selected' : '';
$renderFormContext = static function (string $action) use ($csrfToken): void {
    ?>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $csrfToken); ?>">
    <input type="hidden" name="action" value="<?php echo htmlspecialchars($action, ENT_QUOTES); ?>">
    <?php
};
$renderBadge = static function (string $class, string $label): void {
    ?>
    <span class="badge bg-<?php echo htmlspecialchars($class, ENT_QUOTES); ?>-lt"><?php echo htmlspecialchars($label); ?></span>
    <?php
};
$renderMetricCard = static function (string $label, string $value, string $sub = ''): void {
    ?>
    <div class="col-sm-6 col-xl-3">
        <div class="card">
            <div class="card-body">
                <div class="subheader"><?php echo htmlspecialchars($label); ?></div>
                <div class="h1 mb-1"><?php echo htmlspecialchars($value); ?></div>
                <?php if ($sub !== ''): ?>
                    <div class="text-secondary small"><?php echo htmlspecialchars($sub); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
};
$renderSwitch = static function (string $name, string $label, bool $checked, string $hint = ''): void {
    ?>
    <label class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" name="<?php echo htmlspecialchars($name, ENT_QUOTES); ?>" value="1" <?php echo $checked ? 'checked' : ''; ?>>
        <span class="form-check-label fw-medium"><?php echo htmlspecialchars($label); ?></span>
        <?php if ($hint !== ''): ?>
            <span class="form-hint d-block ms-0"><?php echo htmlspecialchars($hint); ?></span>
        <?php endif; ?>
    </label>
    <?php
};
$renderPromptTemplateForm = static function (string $action, array $template, string $title, string $description) use ($renderFormContext, $renderSwitch): void {
    $placeholders = array_values(array_filter(array_map('strval', (array) ($template['placeholders'] ?? []))));
    $fieldPrefix = 'ai-' . preg_replace('/[^a-z0-9-]/i', '-', $action);
    ?>
    <form method="post" class="card h-100">
        <?php $renderFormContext($action); ?>
        <div class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
            <div>
                <h3 class="card-title mb-1"><?php echo htmlspecialchars($title); ?></h3>
                <div class="text-secondary small"><?php echo htmlspecialchars($description); ?></div>
            </div>
            <button type="submit" class="btn btn-primary">Vorlage speichern</button>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-7">
                    <label for="<?php echo htmlspecialchars($fieldPrefix, ENT_QUOTES); ?>-label" class="form-label">Anzeigename</label>
                    <input id="<?php echo htmlspecialchars($fieldPrefix, ENT_QUOTES); ?>-label" type="text" class="form-control" name="prompt_label" maxlength="120" value="<?php echo htmlspecialchars((string) ($template['label'] ?? ''), ENT_QUOTES); ?>">
                </div>
                <div class="col-md-5 d-flex align-items-end">
                    <?php $renderSwitch('prompt_enabled', 'Vorlage aktiv verwenden', !empty($template['enabled']), 'Bei Translation und SEO wirkt die Vorlage direkt in der Live-Pipeline; der Content Creator bleibt vorbereitet.'); ?>
                </div>
                <div class="col-12">
                    <label for="<?php echo htmlspecialchars($fieldPrefix, ENT_QUOTES); ?>-system-prompt" class="form-label">System-Prompt / Leitplanken</label>
                    <textarea id="<?php echo htmlspecialchars($fieldPrefix, ENT_QUOTES); ?>-system-prompt" class="form-control" name="system_prompt" rows="6" maxlength="4000" spellcheck="false"><?php echo htmlspecialchars((string) ($template['system_prompt'] ?? '')); ?></textarea>
                    <div class="form-hint">Serverseitig werden zusätzliche Pflichtregeln gegen Prompt Injection, Secret-Leaks und System-Prompt-Offenlegung angehängt.</div>
                </div>
                <div class="col-12">
                    <label for="<?php echo htmlspecialchars($fieldPrefix, ENT_QUOTES); ?>-user-template" class="form-label">User-Template / strukturierte Nutzdaten</label>
                    <textarea id="<?php echo htmlspecialchars($fieldPrefix, ENT_QUOTES); ?>-user-template" class="form-control" name="user_template" rows="6" maxlength="4000" spellcheck="false"><?php echo htmlspecialchars((string) ($template['user_template'] ?? '')); ?></textarea>
                    <?php if ($placeholders !== []): ?>
                        <div class="form-hint">Platzhalter: <?php echo htmlspecialchars(implode(', ', $placeholders)); ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-12">
                    <label for="<?php echo htmlspecialchars($fieldPrefix, ENT_QUOTES); ?>-notes" class="form-label">Interne Notiz</label>
                    <textarea id="<?php echo htmlspecialchars($fieldPrefix, ENT_QUOTES); ?>-notes" class="form-control" name="prompt_notes" rows="3" maxlength="1000"><?php echo htmlspecialchars((string) ($template['notes'] ?? '')); ?></textarea>
                    <div class="form-hint">Notizen werden gespeichert, aber nicht an Provider gesendet.</div>
                </div>
                <div class="col-12">
                    <div class="alert alert-info mb-0 small">
                        Best-Practice: Instruktionen und Nutzdaten getrennt halten. Keine API-Keys, personenbezogenen Details oder Rohprompts in Notizen oder Logs ablegen.
                    </div>
                </div>
            </div>
        </div>
    </form>
    <?php
};
$translationReadyProviders = array_values(array_filter($providers, static fn (array $provider): bool => !empty($provider['readiness']['translation']['ready'])));
$contentAssistProviders = array_values(array_filter($providers, static fn (array $provider): bool => !empty($provider['readiness']['content_rewrite']['ready']) || !empty($provider['readiness']['content_summary']['ready'])));
$seoAssistProviders = array_values(array_filter($providers, static fn (array $provider): bool => !empty($provider['readiness']['seo_metadata']['ready'])));
$contentCreatorTasks = [];
if (!empty($activeProvider['readiness']['content_summary']['ready'])) {
    $contentCreatorTasks['summary'] = 'Kurzfassung erstellen';
}
if (!empty($activeProvider['readiness']['content_rewrite']['ready'])) {
    $contentCreatorTasks['outline'] = 'Gliederung erstellen';
    $contentCreatorTasks['cta'] = 'CTA-Varianten erstellen';
}
$contentDraft = is_array($alert['report_payload']['content_draft'] ?? null) ? $alert['report_payload']['content_draft'] : [];
$contentDraftTaskLabels = [
    'summary' => 'Kurzfassung',
    'outline' => 'Gliederung',
    'cta' => 'CTA-Varianten',
];
$showAiSubtitle = $isCurrentSection('overview');
$aiBlockingBadges = [];
if (empty($features['ai_services_enabled'])) {
    $aiBlockingBadges[] = 'KI global deaktiviert';
}
if (empty($summary['translation_ready'])) {
    $aiBlockingBadges[] = 'Übersetzung blockiert';
}
?>

<div class="container-xl">
    <div class="page-header d-print-none mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <?php if ($showAiSubtitle): ?>
                    <div class="page-pretitle">KI-Dienste</div>
                <?php endif; ?>
                <h2 class="page-title"><?php echo htmlspecialchars((string) ($navItems[$currentSection]['label'] ?? 'AI Services')); ?></h2>
                <?php if ($showAiSubtitle): ?>
                    <div class="text-secondary mt-1">Eigener Admin-Bereich für KI-Workflows, Provider-Leitplanken und produktive Übergaben zwischen Übersetzung, Inhalts- und SEO-Assistenten.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php $alertData = is_array($alert ?? null) ? $alert : []; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>

    <?php if (!empty($data['error'])): ?>
        <div class="alert alert-warning mb-4"><?php echo htmlspecialchars((string) $data['error']); ?></div>
    <?php endif; ?>

    <div class="mb-4 d-flex flex-wrap gap-2">
        <?php foreach ($navItems as $section => $item): ?>
            <a class="btn <?php echo $isCurrentSection($section) ? 'btn-primary' : 'btn-outline-primary'; ?>" href="<?php echo htmlspecialchars((string) $item['url'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars((string) $item['label']); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($aiBlockingBadges !== []): ?>
        <div class="alert alert-warning py-2 mb-4">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <strong>Blocker</strong>
                <?php foreach ($aiBlockingBadges as $badgeLabel): ?>
                    <span class="badge bg-warning text-dark"><?php echo htmlspecialchars((string) $badgeLabel); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isCurrentSection('overview')): ?>
        <div class="row row-cards mb-4">
            <?php $renderMetricCard('Aktive Provider', (string) ((int) ($summary['provider_enabled'] ?? 0)) . ' / ' . (string) ((int) ($summary['provider_total'] ?? count($providers))), 'konfigurierter Provider-Pool'); ?>
            <?php $renderMetricCard('Aktive Gates', (string) (int) ($summary['feature_enabled'] ?? 0), 'globale Feature-Freigaben'); ?>
            <?php $renderMetricCard('Translation-Provider', (string) count($translationReadyProviders), 'für DE → EN nutzbar'); ?>
            <?php $renderMetricCard('Prompt-Vorlagen', (string) (int) ($summary['prompt_templates_enabled'] ?? 0) . ' / 3', 'aktiv verwaltete Bereiche'); ?>
        </div>

        <?php if ($monitoring !== []): ?>
            <div class="row row-cards mb-4">
                <?php $renderMetricCard('AI-Läufe · 24h', (string) (int) ($monitoring['runs_24h'] ?? 0), ((int) ($monitoring['failures_24h'] ?? 0)) > 0 ? (string) (int) ($monitoring['failures_24h'] ?? 0) . ' fehlgeschlagen' : 'keine Fehler protokolliert'); ?>
                <?php $renderMetricCard('Erfolgsquote · 30 Tage', (string) (int) ($monitoring['success_rate_30d'] ?? 0) . ' %', (string) ((int) ($monitoring['successes_30d'] ?? 0) + (int) ($monitoring['failures_30d'] ?? 0)) . ' dokumentierte Läufe'); ?>
                <?php $renderMetricCard('Dein Tagesbudget', (string) (int) ($currentUserMonitoring['requests_24h'] ?? 0) . ' / ' . (string) max(0, (int) ($currentUserMonitoring['request_limit'] ?? 0)), 'atomar reservierte Requests im UTC-Tag'); ?>
                <?php $renderMetricCard('Ø Laufzeit · 30 Tage', (string) (int) ($monitoring['avg_duration_ms_30d'] ?? 0) . ' ms', 'auf Basis erfolgreicher Läufe'); ?>
            </div>

            <?php if (!empty($monitoring['load_error'])): ?>
                <div class="alert alert-warning mb-4"><?php echo htmlspecialchars((string) $monitoring['load_error']); ?></div>
            <?php endif; ?>

            <?php if (empty($monitoring['metrics_logging_enabled'])): ?>
                <div class="alert alert-info mb-4">
                    Request-Metriken sind derzeit nicht vollständig aktiviert. Das Dashboard zeigt deshalb bewusst nur request- und quota-nahe Nutzungsdaten; Rohprompts, Volltexte und exakte Tokenkosten bleiben ausgeblendet.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="row row-cards">
            <div class="col-12 col-xl-7">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between gap-2">
                        <h3 class="card-title mb-0">Aktueller Umsetzungsstand</h3>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#aiImplementationStatus" aria-expanded="false" aria-controls="aiImplementationStatus">Details</button>
                    </div>
                    <div class="card-body collapse show" id="aiImplementationStatus">
                        <ul class="list-unstyled mb-0 small text-secondary">
                            <li class="mb-2">✅ AI Services laufen jetzt als eigener Hauptbereich mit separaten Unterseiten.</li>
                            <li class="mb-2">✅ Provider-, Feature-, Translation-, Logging- und Quota-Persistenz ist im Core verdrahtet.</li>
                            <li class="mb-2">✅ Provider erscheinen jetzt nur noch als bewusst angelegte Liste statt als starre Komplettmatrix.</li>
                            <li class="mb-2">✅ Der Editor.js-Übersetzungs-Endpoint bleibt geschützt und an die zentralen Feature-Gates gekoppelt.</li>
                            <li class="mb-2">✅ Ollama und Azure AI sind als erste echte Live-Provider im Gateway verdrahtet.</li>
                            <li class="mb-2">✅ Translation, Inhalts-Assistent und SEO-Assist lassen sich auf Provider-Ebene getrennt schalten.</li>
                            <li class="mb-2">✅ Das AI-Dashboard zeigt jetzt request- und quota-nahe Nutzungsdaten sowie letzte Generierungsläufe aus dem Audit-Log, ohne Rohprompts oder Volltexte offenzulegen.</li>
                            <li class="mb-2">✅ Prompt-Vorlagen lassen sich je Bereich verwalten; die Translation-Vorlage wirkt direkt in der Live-Pipeline und bleibt durch serverseitige Pflicht-Leitplanken abgesichert.</li>
                            <li class="mb-2">✅ Der SEO-Assistent erzeugt im Page-/Post-Editor aus dem Haupttext einen übernehmbaren Entwurf für Meta-, Social-, Schema-, Sitemap- und Robots-Felder; Dokumenttitel, Slug und URL-Felder bleiben ausgeschlossen.</li>
                            <li class="mb-2">✅ Der Content Creator erzeugt im geschützten AI-Adminbereich ungespeicherte Kurzfassungen, Gliederungen und CTA-Varianten aus Briefing und Kontext; Veröffentlichung oder automatische Übernahme ist ausgeschlossen.</li>
                            <li>✅ UTC-Tages-/Monatsquoten werden vor jedem AI-Lauf atomar reserviert. Retries sind auf zwei transiente Wiederholungen begrenzt; ein optionaler Fallback durchläuft dieselbe Policy und Provider-Quota.</li>
                            <li>⚠️ Providerübergreifende Token-/Kostenabrechnung bleibt optional, solange Live-Provider ihre Usage-Daten nicht konsistent zurückmelden.</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-5">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Bereitschaftsprüfung</h3></div>
                    <div class="card-body">
                        <dl class="row mb-0 small">
                            <dt class="col-7">Master-Schalter</dt>
                            <dd class="col-5"><?php $renderBadge(!empty($features['ai_services_enabled']) ? 'success' : 'secondary', !empty($features['ai_services_enabled']) ? 'an' : 'aus'); ?></dd>
                            <dt class="col-7">Übersetzungs-Freigabe</dt>
                            <dd class="col-5"><?php $renderBadge(!empty($features['ai_translation_enabled']) ? 'success' : 'secondary', !empty($features['ai_translation_enabled']) ? 'an' : 'aus'); ?></dd>
                            <dt class="col-7">Inhalts-Assistent</dt>
                            <dd class="col-5"><?php echo count($contentAssistProviders); ?> Provider</dd>
                            <dt class="col-7">SEO-Assist</dt>
                            <dd class="col-5"><?php echo count($seoAssistProviders); ?> Provider</dd>
                            <dt class="col-7">Logging-Modus</dt>
                            <dd class="col-5"><?php echo htmlspecialchars((string) ($summary['logging_mode'] ?? 'technical')); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between gap-2">
                        <h3 class="card-title mb-0">Kanonische Doku</h3>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#aiCanonicalDocs" aria-expanded="false" aria-controls="aiCanonicalDocs">Ein-/ausklappen</button>
                    </div>
                    <div class="card-body text-secondary small collapse show" id="aiCanonicalDocs">
                        Die fachliche Hauptdoku liegt in <code>DOC/ai/AI-SERVICES.md</code>. Dieser Bereich bildet den aktuellen Settings-, Routing-, Monitoring- und Readiness-Rahmen im Core ab.
                    </div>
                </div>
            </div>
        </div>

        <?php if ($monitoring !== [] || $generationHistory !== []): ?>
            <div class="row row-cards mt-4">
                <div class="col-12 col-xl-5">
                    <div class="card h-100">
                        <div class="card-header"><h3 class="card-title">Nutzungsmonitoring & Kontingente</h3></div>
                        <div class="card-body">
                            <dl class="row mb-4 small">
                                <dt class="col-7">Dein Tagesbudget · UTC</dt>
                                <dd class="col-5"><?php echo (int) ($currentUserMonitoring['requests_24h'] ?? 0); ?> / <?php echo (int) ($currentUserMonitoring['request_limit'] ?? 0); ?> Requests</dd>
                                <dt class="col-7">Dein Zeichenbudget · UTC</dt>
                                <dd class="col-5">
                                    <?php if (!empty($currentUserMonitoring['char_metrics_available'])): ?>
                                        <?php echo number_format((int) ($currentUserMonitoring['chars_24h'] ?? 0), 0, ',', '.'); ?> / <?php echo number_format((int) ($currentUserMonitoring['char_limit'] ?? 0), 0, ',', '.'); ?>
                                    <?php else: ?>
                                        <span class="text-secondary">noch keine Zeichenmetriken</span>
                                    <?php endif; ?>
                                </dd>
                                <dt class="col-7">Aktiver Provider</dt>
                                <dd class="col-5"><?php echo htmlspecialchars((string) ($activeProviderMonitoring['provider_label'] ?? '—')); ?></dd>
                                <dt class="col-7">Provider-Budget · Monat</dt>
                                <dd class="col-5"><?php echo (int) ($activeProviderMonitoring['requests_30d'] ?? 0); ?> / <?php echo (int) ($activeProviderMonitoring['request_limit'] ?? 0); ?></dd>
                            </dl>

                            <div class="text-secondary small mb-2">Meistgenutzte Provider der letzten 30 Tage</div>
                            <div class="table-responsive">
                                <table class="table table-sm table-vcenter mb-0">
                                    <thead>
                                        <tr>
                                            <th>Provider</th>
                                            <th>Requests</th>
                                            <th>Blöcke</th>
                                            <th>Zeichen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($providerBreakdown === []): ?>
                                            <tr><td colspan="4" class="text-center text-secondary py-3">Noch keine protokollierten AI-Läufe im 30-Tage-Fenster.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($providerBreakdown as $providerStats): ?>
                                                <tr>
                                                    <td class="fw-semibold"><?php echo htmlspecialchars((string) ($providerStats['provider_label'] ?? '—')); ?></td>
                                                    <td><?php echo (int) ($providerStats['requests_30d'] ?? 0); ?></td>
                                                    <td><?php echo number_format((int) ($providerStats['blocks_30d'] ?? 0), 0, ',', '.'); ?></td>
                                                    <td>
                                                        <?php if (!empty($providerStats['char_metrics_available'])): ?>
                                                            <?php echo number_format((int) ($providerStats['chars_30d'] ?? 0), 0, ',', '.'); ?>
                                                        <?php else: ?>
                                                            <span class="text-secondary">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="text-secondary small mt-3 mb-0">Es werden bewusst keine Rohprompts, Inhaltsblöcke oder Secrets angezeigt – nur betriebsnahe Audit-Metadaten.</div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-7">
                    <div class="card h-100">
                        <div class="card-header"><h3 class="card-title">Letzte AI-Läufe</h3></div>
                        <div class="table-responsive">
                            <table class="table table-vcenter card-table table-striped">
                                <thead>
                                    <tr>
                                        <th>Zeitpunkt</th>
                                        <th>Status</th>
                                        <th>Benutzer</th>
                                        <th>Provider</th>
                                        <th>Ziel</th>
                                        <th>Dauer</th>
                                        <th>Blöcke / Zeichen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($generationHistory === []): ?>
                                        <tr><td colspan="7" class="text-center text-secondary py-4">Noch keine Generierungsläufe protokolliert.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($generationHistory as $historyEntry): ?>
                                            <tr>
                                                <td class="text-secondary small"><?php echo htmlspecialchars((string) ($historyEntry['created_at'] ?? '—')); ?></td>
                                                <td>
                                                    <?php $renderBadge((string) ($historyEntry['status'] ?? 'secondary'), (string) ($historyEntry['status_label'] ?? 'unbekannt')); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars((string) ($historyEntry['user_label'] ?? '—')); ?></td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars((string) ($historyEntry['provider_label'] ?? '—')); ?></div>
                                                    <div class="text-secondary small"><?php echo htmlspecialchars((string) ($historyEntry['resolved_via'] ?? 'direct')); ?></div>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars((string) ($historyEntry['operation'] ?? 'AI-Lauf')); ?></div>
                                                    <div class="text-secondary small"><?php echo htmlspecialchars((string) ($historyEntry['target_locale'] ?? '—')); ?></div>
                                                </td>
                                                <td>
                                                    <?php if (($historyEntry['duration_ms'] ?? null) !== null): ?>
                                                        <?php echo number_format((int) $historyEntry['duration_ms'], 0, ',', '.'); ?> ms
                                                    <?php else: ?>
                                                        <span class="text-secondary">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo number_format((int) ($historyEntry['translated_blocks'] ?? 0), 0, ',', '.'); ?>
                                                    /
                                                    <?php if (($historyEntry['char_count'] ?? null) !== null): ?>
                                                        <?php echo number_format((int) $historyEntry['char_count'], 0, ',', '.'); ?>
                                                    <?php else: ?>
                                                        <span class="text-secondary">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php elseif ($isCurrentSection('translation')): ?>
        <div class="row row-cards mb-4">
            <?php $renderMetricCard('Default-Quelle', (string) ($translation['default_source_locale'] ?? 'de'), 'typisch DE'); ?>
            <?php $renderMetricCard('Default-Ziel', (string) ($translation['default_target_locale'] ?? 'en'), 'typisch EN'); ?>
            <?php $renderMetricCard('Zielsprachen', (string) count((array) ($translation['allowed_target_locales'] ?? ['en'])), 'erlaubte Locales'); ?>
            <?php $renderMetricCard('Translation-Provider', (string) count($translationReadyProviders), 'aktiv & berechtigt'); ?>
        </div>

        <div class="row row-cards">
            <div class="col-12 col-xl-8">
                <form method="post" class="card h-100">
                    <?php $renderFormContext('save_translation'); ?>
                    <div class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
                        <h3 class="card-title mb-0">Übersetzungsprofil</h3>
                        <button type="submit" class="btn btn-primary">Übersetzungs-Einstellungen speichern</button>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="fld-default_source_locale" class="form-label">Standard-Quellsprache</label>
                                <input id="fld-default_source_locale" type="text" class="form-control" name="default_source_locale" value="<?php echo htmlspecialchars((string) ($translation['default_source_locale'] ?? 'de')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-default_target_locale" class="form-label">Standard-Zielsprache</label>
                                <input id="fld-default_target_locale" type="text" class="form-control" name="default_target_locale" value="<?php echo htmlspecialchars((string) ($translation['default_target_locale'] ?? 'en')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-allowed_target_locales" class="form-label">Erlaubte Zielsprachen</label>
                                <input id="fld-allowed_target_locales" type="text" class="form-control" name="allowed_target_locales" value="<?php echo htmlspecialchars(implode(',', (array) ($translation['allowed_target_locales'] ?? ['en']))); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-supported_block_types" class="form-label">Unterstützte Blocktypen</label>
                                <input id="fld-supported_block_types" type="text" class="form-control" name="supported_block_types" value="<?php echo htmlspecialchars(implode(',', (array) ($translation['supported_block_types'] ?? []))); ?>">
                                <div class="form-text">Aktive EditorJS-Blocktypen sind u. a. paragraph, header, list/checklist, image, table, attaches, linkTool, warning, alert, accordion, imageGallery und mediaText. Strukturblöcke wie spacer, delimiter, embed, code und raw werden erhalten; übersetzt werden nur sichere Textsegmente.</div>
                            </div>
                            <div class="col-12">
                                <label for="fld-result_mode" class="form-label">Ergebnis-Modus</label>
                                <select id="fld-result_mode" class="form-select" name="result_mode">
                                    <?php foreach ($resultModes as $modeValue => $modeLabel): ?>
                                        <option value="<?php echo htmlspecialchars($modeValue, ENT_QUOTES); ?>" <?php echo $isSelected((string) ($translation['result_mode'] ?? 'localized-field'), $modeValue); ?>><?php echo htmlspecialchars($modeLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <input type="hidden" name="preview_required" value="1">
                            <div class="col-12">
                                <div class="alert alert-info mb-0">
                                    Generierte Übersetzungen werden immer erst als Vorschau/Diff bereitgestellt und nur nach expliziter Bestätigung übernommen.
                                </div>
                            </div>
                            <div class="col-md-6"><?php $renderSwitch('preserve_unsupported_blocks', 'Nicht unterstützte Blöcke bewahren', !empty($translation['preserve_unsupported_blocks'])); ?></div>
                            <div class="col-md-6"><?php $renderSwitch('skip_html_blocks', 'HTML-/Raw-Blöcke überspringen', !empty($translation['skip_html_blocks'])); ?></div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="col-12 col-xl-4">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Editor-Workflow</h3></div>
                    <div class="card-body text-secondary small">
                        <p>Die bestehende Übersetzungspipeline bedient aktuell Editor.js-Inhalte und führt DE-Inhalte nach EN in lokalisierte Felder zurück.</p>
                        <ul class="mb-0 ps-3">
                            <li>Vorschau-/Vergleichsschritt vor der Übernahme</li>
                            <li>Provider-abhängige Übersetzungs-Freigaben</li>
                            <li>Blocktypen können granular eingegrenzt werden</li>
                            <li>Nicht unterstützte Inhalte lassen sich fail-soft bewahren</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <?php $renderPromptTemplateForm('save_translation_prompts', $translationPromptTemplate, 'Prompt-Vorlage · Übersetzung', 'Strukturierte Runtime-Vorlage für Editor.js-Übersetzungen mit klarer Trennung von Instruktion und Segmentdaten.'); ?>
            </div>
        </div>
    <?php elseif ($isCurrentSection('content_creator')): ?>
        <div class="row row-cards mb-4">
            <?php $renderMetricCard('Assist-Provider', (string) count($contentAssistProviders), 'Rewrite oder Summary aktiv'); ?>
            <?php $renderMetricCard('Master-Gate', !empty($features['ai_services_enabled']) ? 'an' : 'aus', 'globale Freigabe'); ?>
            <?php $renderMetricCard('Rewrite-Gate', !empty($features['ai_rewrite_enabled']) ? 'an' : 'aus', 'für Content-Helfer'); ?>
            <?php $renderMetricCard('Summary-Gate', !empty($features['ai_summary_enabled']) ? 'an' : 'aus', 'für Teaser & Zusammenfassungen'); ?>
        </div>

        <div class="row row-cards">
            <div class="col-12 col-xl-7">
                <form method="post" class="card h-100">
                    <?php $renderFormContext('generate_content_draft'); ?>
                    <div class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
                        <div>
                            <h3 class="card-title mb-1">Inhaltsentwurf erstellen</h3>
                            <div class="text-secondary small">Erstellt einen redaktionellen Vorschlag ausschließlich für die manuelle Prüfung.</div>
                        </div>
                        <button type="submit" class="btn btn-primary" <?php echo $contentCreatorTasks === [] ? 'disabled' : ''; ?>>Entwurf erstellen</button>
                    </div>
                    <div class="card-body text-secondary small">
                        <?php if ($contentCreatorTasks === []): ?>
                            <div class="alert alert-warning mb-3">Aktiviere unter <a href="/admin/ai-settings" class="alert-link">AI-Einstellungen</a> mindestens Zusammenfassung oder Umschreiben sowie die entsprechende Fähigkeit des aktiven Providers.</div>
                        <?php endif; ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="aiContentTask">Aktion</label>
                                <select class="form-select" id="aiContentTask" name="content_task" <?php echo $contentCreatorTasks === [] ? 'disabled' : ''; ?>>
                                    <?php foreach ($contentCreatorTasks as $taskValue => $taskLabel): ?>
                                        <option value="<?php echo htmlspecialchars($taskValue, ENT_QUOTES); ?>"><?php echo htmlspecialchars($taskLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="aiContentLocale">Ausgabesprache</label>
                                <select class="form-select" id="aiContentLocale" name="content_locale" <?php echo $contentCreatorTasks === [] ? 'disabled' : ''; ?>>
                                    <option value="de">Deutsch</option>
                                    <option value="en">Englisch</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="aiContentBrief">Briefing</label>
                                <textarea class="form-control" id="aiContentBrief" name="content_brief" rows="4" maxlength="2000" required <?php echo $contentCreatorTasks === [] ? 'disabled' : ''; ?> placeholder="Ziel, Zielgruppe, Kernbotschaft und gewünschtes Ergebnis …"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="aiContentContext">Optionaler Kontext</label>
                                <textarea class="form-control" id="aiContentContext" name="content_context" rows="5" maxlength="12000" <?php echo $contentCreatorTasks === [] ? 'disabled' : ''; ?> placeholder="Fakten, vorhandene Notizen oder eine Rohfassung …"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="aiContentTone">Tonalität</label>
                                <input type="text" class="form-control" id="aiContentTone" name="content_tone" maxlength="120" <?php echo $contentCreatorTasks === [] ? 'disabled' : ''; ?> placeholder="z. B. sachlich, prägnant, professionell">
                            </div>
                        </div>
                    </div>
                    <div class="card-footer text-secondary small">Der Entwurf wird weder gespeichert noch veröffentlicht. Er kann nach Prüfung manuell in einen Seiten- oder Beitragsentwurf übernommen werden.</div>
                </form>
            </div>
            <div class="col-12 col-xl-5">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Status</h3></div>
                    <div class="card-body text-secondary small">
                        <?php if ($contentCreatorTasks !== []): ?>
                            Der aktive Provider <strong><?php echo htmlspecialchars((string) ($activeProvider['label'] ?? '')); ?></strong> ist für <?php echo htmlspecialchars(implode(' und ', array_values($contentCreatorTasks))); ?> freigegeben. Alle Ergebnisse bleiben bis zur manuellen Übernahme reine Admin-Entwürfe.
                        <?php else: ?>
                            Der Content Creator ist installiert, aber für den aktiven Provider oder die globalen Feature-Gates noch nicht freigegeben.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($contentDraft !== [] && trim((string) ($contentDraft['content'] ?? '')) !== ''): ?>
                <div class="col-12">
                    <div class="card border-primary">
                        <div class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
                            <div>
                                <h3 class="card-title mb-1">Content-Entwurf · <?php echo htmlspecialchars((string) ($contentDraftTaskLabels[$contentDraft['task'] ?? ''] ?? 'Vorschlag')); ?></h3>
                                <div class="text-secondary small">Provider: <?php echo htmlspecialchars((string) ($contentDraft['provider']['label'] ?? '—')); ?> · Nicht gespeichert, nicht veröffentlicht</div>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="copyAiContentDraftButton">Entwurf kopieren</button>
                        </div>
                        <div class="card-body">
                            <textarea class="form-control" id="aiContentDraftOutput" aria-label="Content-Entwurf" rows="12" readonly><?php echo htmlspecialchars((string) ($contentDraft['content'] ?? '')); ?></textarea>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Assist-fähige Provider</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table table-striped">
                            <thead>
                                <tr>
                                    <th>Provider</th>
                                    <th>Profil</th>
                                    <th>Fähigkeiten</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($contentAssistProviders === []): ?>
                                    <tr><td colspan="4" class="text-center text-secondary py-4">Derzeit kein Provider mit Inhalts-Assistent-Fähigkeiten aktiv.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($contentAssistProviders as $provider): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo htmlspecialchars((string) ($provider['label'] ?? 'Provider')); ?></td>
                                            <td><code><?php echo htmlspecialchars((string) ($provider['profile'] ?? 'disabled')); ?></code></td>
                                            <td class="text-secondary small"><?php echo trim((!empty($provider['rewrite_enabled']) ? 'Rewrite ' : '') . (!empty($provider['summary_enabled']) ? 'Summary' : '')); ?></td>
                                            <td><?php $renderBadge(!empty($provider['enabled']) ? 'success' : 'secondary', !empty($provider['enabled']) ? 'aktiv' : 'aus'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <?php $renderPromptTemplateForm('save_content_prompts', $contentPromptTemplate, 'Prompt-Vorlage · Content Creator', 'Produktive Vorlage für Kurzfassungen, Gliederungen und CTA-Varianten mit Human-in-the-Loop-Ausgabe.'); ?>
            </div>
        </div>
    <?php elseif ($isCurrentSection('seo_creator')): ?>
        <div class="row row-cards mb-4">
            <?php $renderMetricCard('SEO-Provider', (string) count($seoAssistProviders), 'Meta-/SEO-Unterstützung aktiv'); ?>
            <?php $renderMetricCard('SEO-Gate', !empty($features['ai_seo_meta_enabled']) ? 'an' : 'aus', 'globale Freigabe'); ?>
            <?php $renderMetricCard('SEO-Modul', class_exists('\\CMS\\Services\\CoreModuleService') && \CMS\Services\CoreModuleService::getInstance()->isModuleEnabled('seo') ? 'an' : 'aus', 'Core-Modulstatus'); ?>
            <?php $renderMetricCard('Editor.js', !empty($features['ai_editorjs_enabled']) ? 'an' : 'aus', 'für spätere Inline-Helfer'); ?>
        </div>

        <div class="row row-cards">
            <div class="col-12 col-xl-7">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Aktiver SEO-Workflow</h3></div>
                    <div class="card-body text-secondary small">
                        <ul class="mb-0 ps-3">
                            <li>Aktion „SEO mit AI füllen“ direkt im Page-/Post-Editor</li>
                            <li>Haupttext → Kurzfassung, Keyphrase, Keywords, Meta- und Social-Snippets</li>
                            <li>Schema-Typ, Sitemap-Priorität/-Frequenz und Robots als Entwurf</li>
                            <li>Dokumenttitel, Slug, Canonical-, Bild- und hreflang-Felder bleiben unverändert</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-5">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title">Status</h3></div>
                    <div class="card-body text-secondary small">
                        Der SEO Creator ist produktiv an die Page-/Post-Editoren angebunden. Ein Ergebnis füllt nur den lokalen, noch ungespeicherten Formulardraft; die redaktionelle Prüfung und der normale Speichervorgang bleiben zwingend.
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">SEO-fähige Provider</h3></div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table table-striped">
                            <thead>
                                <tr>
                                    <th>Provider</th>
                                    <th>Profil</th>
                                    <th>SEO-Fokus</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($seoAssistProviders === []): ?>
                                    <tr><td colspan="4" class="text-center text-secondary py-4">Derzeit kein Provider mit SEO-Assist aktiv.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($seoAssistProviders as $provider): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo htmlspecialchars((string) ($provider['label'] ?? 'Provider')); ?></td>
                                            <td><code><?php echo htmlspecialchars((string) ($provider['profile'] ?? 'disabled')); ?></code></td>
                                            <td class="text-secondary small">Meta, Snippets, SEO-Hilfen</td>
                                            <td><?php $renderBadge(!empty($provider['enabled']) ? 'success' : 'secondary', !empty($provider['enabled']) ? 'aktiv' : 'aus'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <?php $renderPromptTemplateForm('save_seo_prompts', $seoPromptTemplate, 'Prompt-Vorlage · SEO Creator', 'Produktive Vorlage für Meta-, Social-, Schema-, Sitemap- und Robots-Entwürfe aus dem Editor.js-Haupttext mit redaktioneller Freigabe.'); ?>
            </div>
        </div>
    <?php else: ?>
        <div class="row row-cards">
            <div class="col-12">
                <form method="post" class="card mb-4">
                    <?php $renderFormContext('save_features'); ?>
                    <div class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
                        <h3 class="card-title mb-0">Globale Feature-Gates</h3>
                        <button type="submit" class="btn btn-primary">Feature-Gates speichern</button>
                    </div>
                    <div class="card-body">
                        <div class="row row-cards">
                            <div class="col-md-4">
                                <?php $renderSwitch('ai_services_enabled', 'AI Services global aktivieren', !empty($features['ai_services_enabled']), 'Master-Schalter für alle AI-bezogenen Workflows.'); ?>
                                <?php $renderSwitch('ai_translation_enabled', 'Übersetzung erlauben', !empty($features['ai_translation_enabled'])); ?>
                            </div>
                            <div class="col-md-4">
                                <?php $renderSwitch('ai_rewrite_enabled', 'Rewrite erlauben', !empty($features['ai_rewrite_enabled'])); ?>
                                <?php $renderSwitch('ai_summary_enabled', 'Zusammenfassungen erlauben', !empty($features['ai_summary_enabled'])); ?>
                            </div>
                            <div class="col-md-4">
                                <?php $renderSwitch('ai_seo_meta_enabled', 'SEO-/Meta-Helfer erlauben', !empty($features['ai_seo_meta_enabled'])); ?>
                                <?php $renderSwitch('ai_editorjs_enabled', 'Editor.js-Integration erlauben', !empty($features['ai_editorjs_enabled'])); ?>
                                <?php $renderSwitch('ai_beta_providers_enabled', 'Beta-Provider ausdrücklich erlauben', !empty($features['ai_beta_providers_enabled']), 'Erforderlich für Provider mit dem Profil „Nur Beta“.'); ?>
                                <?php $renderSwitch('ai_external_provider_data_sharing_enabled', 'Externe Datenweitergabe erlauben', !empty($features['ai_external_provider_data_sharing_enabled']), 'Erforderlich, bevor Inhalte an Cloud-Provider übermittelt werden.'); ?>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($activeProviderId !== '' && $activeProviderId !== 'mock'): ?>
                <div class="col-12">
                    <form method="post" class="card border-danger mb-4" data-cms-ai-delete-provider="1">
                        <?php $renderFormContext('delete_provider'); ?>
                        <input type="hidden" name="provider_id" value="<?php echo htmlspecialchars($activeProviderId, ENT_QUOTES); ?>">
                        <div class="card-body d-flex justify-content-between align-items-center gap-3 flex-wrap">
                            <div>
                                <div class="fw-semibold text-danger">Provider dauerhaft löschen</div>
                                <div class="text-secondary small">Entfernt <strong><?php echo htmlspecialchars((string) ($activeProvider['label'] ?? $activeProviderId)); ?></strong> inklusive zugehörigem API-Secret. Der aktive bzw. Ersatz-Provider wird anschließend sicher neu aufgelöst.</div>
                            </div>
                            <button type="submit" class="btn btn-outline-danger">Provider löschen</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <div class="col-12">
                <form method="post" class="card mb-4">
                    <?php $renderFormContext('save_providers'); ?>
                    <div class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
                        <div>
                            <h3 class="card-title mb-1">Globale Provider-Verwaltung</h3>
                            <div class="text-secondary small">Alle AI-Features nutzen ausschließlich diesen aktiven Provider über <code>AiService</code> und <code>AiProviderFactory</code>.</div>
                        </div>
                        <button type="submit" class="btn btn-primary">Provider speichern</button>
                    </div>
                    <div class="card-body">
                        <div class="row g-3" id="aiProviderForm" data-provider-catalog="<?php echo htmlspecialchars((string) json_encode($providerCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>" data-provider-values="<?php echo htmlspecialchars((string) json_encode($providerValuesByType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="col-md-6">
                                <label for="aiProviderType" class="form-label">Aktiver Provider</label>
                                <select class="form-select" name="active_provider_type" id="aiProviderType">
                                    <?php foreach ($providerCatalog as $providerType => $catalogEntry): ?>
                                        <option value="<?php echo htmlspecialchars((string) $providerType, ENT_QUOTES); ?>" <?php echo $isSelected($activeProviderType, (string) $providerType); ?>><?php echo htmlspecialchars((string) ($catalogEntry['label'] ?? $providerType)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint" id="aiProviderDescription"><?php echo htmlspecialchars((string) ($providerCatalog[$activeProviderType]['description'] ?? '')); ?></div>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <?php $renderSwitch('provider_enabled', 'Globalen Provider aktivieren', !empty($activeProvider['enabled']), 'Mock ist immer aktiv; echte Provider benötigen Endpoint/Modell und ggf. Secret.'); ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="aiProviderProfile">Betriebsprofil</label>
                                <select class="form-select" name="provider_profile" id="aiProviderProfile">
                                    <?php foreach ($providerProfiles as $profileValue => $profileLabel): ?>
                                        <option value="<?php echo htmlspecialchars($profileValue, ENT_QUOTES); ?>" <?php echo $isSelected((string) ($activeProvider['profile'] ?? 'editor-translation'), $profileValue); ?>><?php echo htmlspecialchars($profileLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint">„Alle Funktionen“ erlaubt alle aktivierten Provider-Scopes. Globale Features, Berechtigungen, Locale-, Beta- und Datenfreigabe-Gates bleiben weiterhin verpflichtend.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="aiFallbackProvider">Ersatz-Provider</label>
                                <select class="form-select" name="fallback_provider_id" id="aiFallbackProvider">
                                    <option value="">Kein Ersatz-Provider</option>
                                    <?php foreach ($providers as $provider): ?>
                                        <?php $providerId = (string) ($provider['id'] ?? ''); ?>
                                        <?php if ($providerId === '' || $providerId === $activeProviderId): continue; endif; ?>
                                        <option value="<?php echo htmlspecialchars($providerId, ENT_QUOTES); ?>" <?php echo $isSelected($fallbackProviderId, $providerId); ?>><?php echo htmlspecialchars((string) ($provider['label'] ?? $providerId)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint">Wird nur nach einem transienten Fehler und erneutem Policy-/Quota-Check genutzt.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="aiProviderLabel" class="form-label">Anzeigename</label>
                                <input type="text" class="form-control" name="provider_label" id="aiProviderLabel" maxlength="120" value="<?php echo htmlspecialchars((string) ($activeProvider['label'] ?? $activeProviderLabel), ENT_QUOTES); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="aiProviderModel" class="form-label">Modell</label>
                                <input type="text" class="form-control" name="provider_model" id="aiProviderModel" maxlength="120" value="<?php echo htmlspecialchars((string) ($activeProvider['default_model'] ?? ''), ENT_QUOTES); ?>">
                            </div>
                            <div class="col-12" data-provider-field="endpoint">
                                <label for="aiProviderEndpoint" class="form-label">Endpoint</label>
                                <input type="url" class="form-control" name="provider_endpoint" id="aiProviderEndpoint" maxlength="255" value="<?php echo htmlspecialchars((string) ($activeProvider['endpoint'] ?? ''), ENT_QUOTES); ?>" placeholder="https://...">
                            </div>
                            <div class="col-md-6" data-provider-field="deployment">
                                <label for="aiProviderDeployment" class="form-label">Deployment</label>
                                <input type="text" class="form-control" name="provider_deployment" id="aiProviderDeployment" maxlength="120" value="<?php echo htmlspecialchars((string) ($activeProvider['deployment'] ?? ''), ENT_QUOTES); ?>" placeholder="Azure Deployment-Name">
                            </div>
                            <div class="col-md-6" data-provider-field="api_version">
                                <label for="aiProviderApiVersion" class="form-label">API-Version</label>
                                <input type="text" class="form-control" name="provider_api_version" id="aiProviderApiVersion" maxlength="120" value="<?php echo htmlspecialchars((string) ($activeProvider['api_version'] ?? ''), ENT_QUOTES); ?>" placeholder="2024-10-21">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="aiProviderAllowedLocales">Erlaubte Sprachen</label>
                                <input type="text" class="form-control" name="provider_allowed_locales" id="aiProviderAllowedLocales" value="<?php echo htmlspecialchars(implode(',', (array) ($activeProvider['allowed_locales'] ?? ['de', 'en'])), ENT_QUOTES); ?>" placeholder="de,en">
                                <div class="form-hint">Gilt für Übersetzungsziele und für SEO-/Content-Entwürfe in der jeweiligen Editorsprache. Für AI-Buttons im deutschen Editor muss <code>de</code> enthalten sein.</div>
                            </div>
                            <div class="col-md-6" data-provider-field="internal_hosts">
                                <label for="aiProviderAllowedInternalHosts" class="form-label">Erlaubte interne Ollama-Hosts</label>
                                <input type="text" class="form-control" name="provider_allowed_internal_hosts" id="aiProviderAllowedInternalHosts" value="<?php echo htmlspecialchars(implode(',', (array) ($activeProvider['allowed_internal_hosts'] ?? [])), ENT_QUOTES); ?>" placeholder="127.0.0.1,localhost">
                                <div class="form-hint">Nur für Ollama. Private Ziele sind ausschließlich über diese exakte Allowlist erlaubt.</div>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <label class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="provider_beta_only" id="aiProviderBetaOnly" value="1" <?php echo !empty($activeProvider['beta_only']) ? 'checked' : ''; ?>>
                                    <span class="form-check-label fw-medium">Nur Beta</span>
                                </label>
                            </div>
                            <div class="col-12" data-provider-field="secret">
                                <label for="fld-provider_secret" class="form-label" id="aiProviderSecretLabel"><?php echo htmlspecialchars((string) ($activeProvider['secret_label'] ?? 'API-Key')); ?></label>
                                <input id="fld-provider_secret" type="password" class="form-control" name="provider_secret" value="" placeholder="Leer lassen = gespeichertes Secret behalten" autocomplete="new-password" spellcheck="false" autocapitalize="off" autocorrect="off">
                                <div class="form-hint">Aktuell gespeichert: <span id="aiProviderSecretState"><?php echo !empty($activeProvider['secret_configured']) ? 'Ja' : 'Nein'; ?></span></div>
                                <label class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="clear_provider_secret" value="1">
                                    <span class="form-check-label">Gespeichertes Secret löschen</span>
                                </label>
                            </div>
                            <div class="col-12">
                                <div class="border rounded p-3">
                                    <div class="fw-semibold mb-2">Feature-Scopes dieses Providers</div>
                                    <div class="row">
                                        <div class="col-md-4"><?php $renderSwitch('provider_translation_enabled', 'Übersetzung', !empty($activeProvider['translation_enabled'])); ?></div>
                                        <div class="col-md-4"><?php $renderSwitch('provider_summary_enabled', 'Zusammenfassungen', !empty($activeProvider['summary_enabled'])); ?></div>
                                        <div class="col-md-4"><?php $renderSwitch('provider_rewrite_enabled', 'Content-Entwürfe', !empty($activeProvider['rewrite_enabled'])); ?></div>
                                        <div class="col-md-6"><?php $renderSwitch('provider_seo_meta_enabled', 'SEO-Metadaten', !empty($activeProvider['seo_meta_enabled'])); ?></div>
                                        <div class="col-md-6"><?php $renderSwitch('provider_editorjs_enabled', 'Editor.js-Kontext', !empty($activeProvider['editorjs_enabled'])); ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="alert alert-info mb-0 small">
                                    Jeder AI-Lauf benötigt einen expliziten Feature-Scope und durchläuft Policy, Quota, Retry und optionalen Fallback zentral über <code>AiService</code>.
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="col-12">
                <form method="post" class="card mb-4">
                    <?php $renderFormContext('check_provider_health'); ?>
                    <div class="card-body d-flex justify-content-between align-items-center gap-3 flex-wrap">
                        <div>
                            <div class="fw-semibold">Provider-Verbindungstest</div>
                            <div class="text-secondary small">Sendet keine Redaktionsinhalte und prüft Konfiguration, Policy sowie bei Live-Providern einen minimalen Modellaufruf.</div>
                        </div>
                        <input type="hidden" name="provider_id" value="<?php echo htmlspecialchars($activeProviderId, ENT_QUOTES); ?>">
                        <button type="submit" class="btn btn-outline-primary">Aktiven Provider prüfen</button>
                    </div>
                    <?php if ($providerHealth !== []): ?>
                        <div class="card-footer text-secondary small">
                            <strong class="text-success">Erreichbar:</strong> <?php echo htmlspecialchars((string) ($providerHealth['provider']['label'] ?? 'Provider')); ?> · <?php echo (int) ($providerHealth['duration_ms'] ?? 0); ?> ms · <?php echo (int) ($providerHealth['attempts'] ?? 1); ?> Versuch(e)
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <div class="col-12 col-xl-6">
                <form method="post" class="card h-100">
                    <?php $renderFormContext('save_logging'); ?>
                    <div class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
                        <h3 class="card-title mb-0">Logging & Audit</h3>
                        <button type="submit" class="btn btn-primary">Logging speichern</button>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="fld-logging_mode" class="form-label">Logging-Modus</label>
                                <select id="fld-logging_mode" class="form-select" name="logging_mode">
                                    <?php foreach ($loggingModes as $modeValue => $modeLabel): ?>
                                        <option value="<?php echo htmlspecialchars($modeValue, ENT_QUOTES); ?>" <?php echo $isSelected((string) ($logging['logging_mode'] ?? 'technical'), $modeValue); ?>><?php echo htmlspecialchars($modeLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="fld-retention_days" class="form-label">Aufbewahrung (Tage)</label>
                                <input id="fld-retention_days" type="number" class="form-control" name="retention_days" min="1" max="3650" value="<?php echo (int) ($logging['retention_days'] ?? 30); ?>">
                            </div>
                            <div class="col-md-6"><?php $renderSwitch('store_content_hashes', 'Content-Hashes speichern', !empty($logging['store_content_hashes'])); ?></div>
                            <div class="col-md-6"><?php $renderSwitch('store_request_metrics', 'Request-Metriken speichern', !empty($logging['store_request_metrics'])); ?></div>
                            <div class="col-md-6"><?php $renderSwitch('store_error_context', 'Fehlerkontext speichern', !empty($logging['store_error_context'])); ?></div>
                            <div class="col-md-6"><div class="form-hint pt-2">Rohprompts und Inhaltsvorschauen werden aus Datenschutzgründen niemals gespeichert.</div></div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="col-12 col-xl-6">
                <form method="post" class="card h-100">
                    <?php $renderFormContext('save_quotas'); ?>
                    <div class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
                        <h3 class="card-title mb-0">Quotas & technische Limits</h3>
                        <button type="submit" class="btn btn-primary">Quotas speichern</button>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="fld-max_chars_per_request" class="form-label">Max. Zeichen pro Anfrage</label>
                                <input id="fld-max_chars_per_request" type="number" class="form-control" name="max_chars_per_request" min="250" max="250000" value="<?php echo (int) ($quotas['max_chars_per_request'] ?? 12000); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-max_blocks_per_request" class="form-label">Max. Blöcke pro Anfrage</label>
                                <input id="fld-max_blocks_per_request" type="number" class="form-control" name="max_blocks_per_request" min="1" max="500" value="<?php echo (int) ($quotas['max_blocks_per_request'] ?? 40); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="fld-timeout_seconds" class="form-label">Timeout (Sekunden)</label>
                                <input id="fld-timeout_seconds" type="number" class="form-control" name="timeout_seconds" min="5" max="300" value="<?php echo (int) ($quotas['timeout_seconds'] ?? 25); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="fld-retry_count" class="form-label">Retry Count</label>
                                <input id="fld-retry_count" type="number" class="form-control" name="retry_count" min="0" max="2" value="<?php echo (int) ($quotas['retry_count'] ?? 1); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="fld-daily_requests_per_user" class="form-label">Anfragen pro Nutzer / Tag</label>
                                <input id="fld-daily_requests_per_user" type="number" class="form-control" name="daily_requests_per_user" min="1" max="5000" value="<?php echo (int) ($quotas['daily_requests_per_user'] ?? 40); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-daily_chars_per_user" class="form-label">Zeichen pro Nutzer / Tag</label>
                                <input id="fld-daily_chars_per_user" type="number" class="form-control" name="daily_chars_per_user" min="500" max="2000000" value="<?php echo (int) ($quotas['daily_chars_per_user'] ?? 120000); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="fld-monthly_requests_per_provider" class="form-label">Anfragen pro Provider / Monat</label>
                                <input id="fld-monthly_requests_per_provider" type="number" class="form-control" name="monthly_requests_per_provider" min="10" max="1000000" value="<?php echo (int) ($quotas['monthly_requests_per_provider'] ?? 5000); ?>">
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
