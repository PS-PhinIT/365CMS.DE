<?php
declare(strict_types=1);

namespace CMS\Services\SiteTable;

if (!defined('ABSPATH')) {
    exit;
}

final class SiteTableTemplateRegistry
{
    private const TEMPLATE_SETTING_KEY = 'hub_site_templates';

    private const DEFAULT_SETTINGS = [
        'responsive' => true,
        'style_theme' => 'default',
        'caption' => '',
        'aria_label' => '',
        'allow_export_csv' => true,
        'allow_export_json' => false,
        'allow_export_excel' => false,
        'content_mode' => 'table',
        'hub_slug' => '',
        'hub_template' => 'general-it',
        'hub_feature_card_interval' => 0,
        'hub_feature_cards_json' => '[]',
        'hub_badge' => '',
        'hub_badge_en' => '',
        'hub_description_en' => '',
        'hub_hero_title' => '',
        'hub_hero_title_en' => '',
        'hub_hero_text' => '',
        'hub_hero_text_en' => '',
        'hub_cta_label' => '',
        'hub_cta_label_en' => '',
        'hub_cta_url' => '',
        'hub_meta_audience' => '',
        'hub_meta_audience_en' => '',
        'hub_meta_owner' => '',
        'hub_meta_owner_en' => '',
        'hub_meta_update_cycle' => '',
        'hub_meta_update_cycle_en' => '',
        'hub_meta_focus' => '',
        'hub_meta_focus_en' => '',
        'hub_meta_kpi' => '',
        'hub_meta_kpi_en' => '',
        'hub_links_json' => '[]',
        'hub_sections_json' => '[]',
        'hub_card_layout' => '',
        'hub_card_image_position' => '',
        'hub_card_image_fit' => '',
        'hub_card_image_ratio' => '',
        'hub_card_meta_layout' => '',
        'hub_card_radius' => '',
        'hub_card_row_gap' => '',
        'hub_feature_image_width' => '',
        'hub_feature_image_height' => '',
    ];

    private const TEMPLATE_PLACEHOLDERS = [
        'general-it' => [
            'links' => [
                ['label' => 'Strategie', 'url' => '#strategie'],
                ['label' => 'Infrastruktur', 'url' => '#infrastruktur'],
                ['label' => 'Security', 'url' => '#security'],
                ['label' => 'Betrieb', 'url' => '#betrieb'],
            ],
            'sections' => [
                ['title' => 'IT-Roadmap', 'text' => 'Platzhalter für strategische Themen, Modernisierung, Infrastruktur und operative Prioritäten.', 'actionLabel' => 'Mehr zur Roadmap', 'actionUrl' => '#roadmap'],
                ['title' => 'Betriebsmodelle', 'text' => 'Platzhalter für Managed Services, Support-Level, SLA-Modelle und Betriebsverantwortung.', 'actionLabel' => 'Betrieb ansehen', 'actionUrl' => '#betrieb'],
            ],
        ],
        'services' => [
            'links' => [
                ['label' => 'Beratung', 'url' => '#beratung'],
                ['label' => 'Microsoft 365', 'url' => '#microsoft-365'],
                ['label' => 'CMS & Web', 'url' => '#cms-web'],
                ['label' => 'Betrieb', 'url' => '#betrieb'],
                ['label' => 'Workshops', 'url' => '#workshops'],
                ['label' => 'Kontakt', 'url' => '/contact'],
            ],
            'sections' => [
                ['title' => 'Von der Idee zur belastbaren Roadmap', 'text' => 'Strukturierte Beratung, Bestandsaufnahme und Priorisierung für Microsoft 365, Security, Web-Plattformen und Automatisierung.', 'actionLabel' => 'Roadmap starten', 'actionUrl' => '#beratung'],
                ['title' => 'Umsetzung mit Betriebsblick', 'text' => 'Technische Umsetzung, Dokumentation, Übergabe und optionaler Betrieb werden von Anfang an zusammen gedacht.', 'actionLabel' => 'Delivery ansehen', 'actionUrl' => '#betrieb'],
                ['title' => 'Enablement statt Blackbox', 'text' => 'Workshops, Runbooks und klare Entscheidungsgrundlagen sorgen dafür, dass Teams Lösungen auch nach dem Projekt souverän betreiben können.', 'actionLabel' => 'Workshops planen', 'actionUrl' => '#workshops'],
            ],
        ],
        'general-table' => [
            'links' => [
                ['label' => 'Übersicht', 'url' => '#uebersicht'],
                ['label' => 'Tabellen', 'url' => '#tabellen'],
                ['label' => 'Vergleiche', 'url' => '#vergleiche'],
                ['label' => 'Details', 'url' => '#details'],
            ],
            'sections' => [
                ['title' => 'Tabellen-Übersicht', 'text' => 'Platzhalter für allgemeine Referenztabellen, Übersichten und strukturierte Vergleichsansichten.', 'actionLabel' => 'Tabellen öffnen', 'actionUrl' => '#tabellen'],
                ['title' => 'Fachliche Details', 'text' => 'Platzhalter für erläuternde Texte, Hinweise und weiterführende Informationen unterhalb der Tabellen.', 'actionLabel' => 'Details ansehen', 'actionUrl' => '#details'],
            ],
        ],
        'microsoft-365' => [
            'links' => [
                ['label' => 'Teams', 'url' => '#teams'],
                ['label' => 'SharePoint', 'url' => '#sharepoint'],
                ['label' => 'Security', 'url' => '#security'],
                ['label' => 'Automation', 'url' => '#automation'],
            ],
            'sections' => [
                ['title' => 'Collaboration Stack', 'text' => 'Platzhalter für Teams, Exchange, SharePoint und Viva-Szenarien.', 'actionLabel' => 'Workspace öffnen', 'actionUrl' => '#workspace'],
                ['title' => 'Governance & Adoption', 'text' => 'Platzhalter für Richtlinien, Rollout-Phasen, Schulungen und Governance-Standards.', 'actionLabel' => 'Governance prüfen', 'actionUrl' => '#governance'],
            ],
        ],
        'm365-table' => [
            'links' => [
                ['label' => 'Lizenzen', 'url' => '#lizenzen'],
                ['label' => 'Features', 'url' => '#features'],
                ['label' => 'Use Cases', 'url' => '#usecases'],
                ['label' => 'Rollout', 'url' => '#rollout'],
            ],
            'sections' => [
                ['title' => 'Lizenz- & Feature-Tabellen', 'text' => 'Platzhalter für SKU-Vergleiche, Funktionsmatrizen und Workload-Zuordnungen im Tabellenformat.', 'actionLabel' => 'Matrizen öffnen', 'actionUrl' => '#lizenzen'],
                ['title' => 'Rollout & Use Cases', 'text' => 'Platzhalter für strukturierte Rollout-Tabellen, Verantwortlichkeiten und Business-Nutzen je Szenario.', 'actionLabel' => 'Use Cases ansehen', 'actionUrl' => '#usecases'],
            ],
        ],
        'powershell-table' => [
            'links' => [
                ['label' => 'Cmdlets', 'url' => '#cmdlets'],
                ['label' => 'Runbooks', 'url' => '#runbooks'],
                ['label' => 'Module', 'url' => '#module'],
                ['label' => 'Automation', 'url' => '#automation'],
            ],
            'sections' => [
                ['title' => 'Cmdlet- & Modul-Tabellen', 'text' => 'Platzhalter für Cmdlet-Übersichten, Modul-Vergleiche, Parameter-Tabellen und wiederkehrende Shell-Referenzen.', 'actionLabel' => 'Cmdlets öffnen', 'actionUrl' => '#cmdlets'],
                ['title' => 'Runbooks & Automation', 'text' => 'Platzhalter für Betriebsrunbooks, Scheduling, Aufgabenketten und PowerShell-basierte Automationspfade.', 'actionLabel' => 'Runbooks ansehen', 'actionUrl' => '#runbooks'],
            ],
        ],
        'datenschutz' => [
            'links' => [
                ['label' => 'DSGVO', 'url' => '#dsgvo'],
                ['label' => 'Verzeichnis', 'url' => '#vvt'],
                ['label' => 'Risiken', 'url' => '#risiken'],
                ['label' => 'Betroffenenrechte', 'url' => '#betroffenenrechte'],
            ],
            'sections' => [
                ['title' => 'Prüfpfade & Nachweise', 'text' => 'Platzhalter für TOMs, AV-Verträge, Löschkonzepte und Nachweisführung.', 'actionLabel' => 'Nachweise ansehen', 'actionUrl' => '#nachweise'],
                ['title' => 'Umsetzungspakete', 'text' => 'Platzhalter für Audits, Gap-Analysen, Schulungen und Datenschutz-Projekte.', 'actionLabel' => 'Pakete öffnen', 'actionUrl' => '#umsetzung'],
            ],
        ],
        'compliance' => [
            'links' => [
                ['label' => 'Policies', 'url' => '#policies'],
                ['label' => 'Audits', 'url' => '#audits'],
                ['label' => 'Rollen', 'url' => '#rollen'],
                ['label' => 'Nachweise', 'url' => '#nachweise'],
            ],
            'sections' => [
                ['title' => 'Governance Framework', 'text' => 'Platzhalter für Richtlinienlandschaft, Rollenkonzepte und Kontrollmechanismen.', 'actionLabel' => 'Framework ansehen', 'actionUrl' => '#framework'],
                ['title' => 'Audit-Vorbereitung', 'text' => 'Platzhalter für Auditpläne, Kontrollpunkte, Maßnahmenlisten und Dokumentation.', 'actionLabel' => 'Audit-Bereich öffnen', 'actionUrl' => '#audit'],
            ],
        ],
        'datenschutz-compliance-table' => [
            'links' => [
                ['label' => 'Nachweise', 'url' => '#nachweise'],
                ['label' => 'Controls', 'url' => '#controls'],
                ['label' => 'Fristen', 'url' => '#fristen'],
                ['label' => 'Audits', 'url' => '#audits'],
            ],
            'sections' => [
                ['title' => 'Nachweis- & Kontrolltabellen', 'text' => 'Platzhalter für kombinierte DSGVO-/Compliance-Tabellen zu Nachweisen, Rollen, Maßnahmen und Kontrollfamilien.', 'actionLabel' => 'Kontrollen öffnen', 'actionUrl' => '#controls'],
                ['title' => 'Fristen, Audits & Maßnahmen', 'text' => 'Platzhalter für Fristen-Tracking, Auditpunkte, Reviews und priorisierte Maßnahmenlisten im Tabellenkontext.', 'actionLabel' => 'Audits ansehen', 'actionUrl' => '#audits'],
            ],
        ],
        'linux' => [
            'links' => [
                ['label' => 'Server', 'url' => '#server'],
                ['label' => 'Container', 'url' => '#container'],
                ['label' => 'Automation', 'url' => '#automation'],
                ['label' => 'Hardening', 'url' => '#hardening'],
            ],
            'sections' => [
                ['title' => 'Platform Engineering', 'text' => 'Platzhalter für Linux-Betrieb, Hosting, Kubernetes, Container und Plattform-Themen.', 'actionLabel' => 'Plattform öffnen', 'actionUrl' => '#plattform'],
                ['title' => 'Shell, CI/CD & Hardening', 'text' => 'Platzhalter für Automatisierung, Pipelines, Monitoring und Security-Baselines.', 'actionLabel' => 'Hardening ansehen', 'actionUrl' => '#hardening'],
            ],
        ],
        'linux-table' => [
            'links' => [
                ['label' => 'Server', 'url' => '#server'],
                ['label' => 'Pakete', 'url' => '#pakete'],
                ['label' => 'Runbooks', 'url' => '#runbooks'],
                ['label' => 'Hardening', 'url' => '#hardening'],
            ],
            'sections' => [
                ['title' => 'System- & Paket-Tabellen', 'text' => 'Platzhalter für Paketstände, Systemvarianten, Baselines und technische Vergleichstabellen.', 'actionLabel' => 'Tabellen öffnen', 'actionUrl' => '#pakete'],
                ['title' => 'Runbooks & Betriebsansichten', 'text' => 'Platzhalter für Wartungsfenster, Betriebsdaten und strukturierte Linux-Runbooks mit Tabellenbezug.', 'actionLabel' => 'Runbooks ansehen', 'actionUrl' => '#runbooks'],
            ],
        ],
    ];

    private const DEFAULT_TEMPLATE_CARD_DESIGN = [
        'general-it' => ['layout' => 'standard', 'image_position' => 'top', 'image_fit' => 'cover', 'image_ratio' => 'wide', 'meta_layout' => 'split', 'card_radius' => 20],
        'services' => ['layout' => 'feature', 'image_position' => 'top', 'image_fit' => 'cover', 'image_ratio' => 'wide', 'meta_layout' => 'split', 'card_radius' => 18],
        'general-table' => ['layout' => 'standard', 'image_position' => 'top', 'image_fit' => 'cover', 'image_ratio' => 'wide', 'meta_layout' => 'split', 'card_radius' => 20],
        'microsoft-365' => ['layout' => 'feature', 'image_position' => 'left', 'image_fit' => 'cover', 'image_ratio' => 'wide', 'meta_layout' => 'split', 'card_radius' => 20],
        'm365-table' => ['layout' => 'standard', 'image_position' => 'top', 'image_fit' => 'cover', 'image_ratio' => 'wide', 'meta_layout' => 'split', 'card_radius' => 20],
        'powershell-table' => ['layout' => 'standard', 'image_position' => 'top', 'image_fit' => 'contain', 'image_ratio' => 'wide', 'meta_layout' => 'split', 'card_radius' => 20],
        'datenschutz' => ['layout' => 'standard', 'image_position' => 'top', 'image_fit' => 'contain', 'image_ratio' => 'square', 'meta_layout' => 'stacked', 'card_radius' => 20],
        'compliance' => ['layout' => 'feature', 'image_position' => 'right', 'image_fit' => 'cover', 'image_ratio' => 'wide', 'meta_layout' => 'split', 'card_radius' => 20],
        'datenschutz-compliance-table' => ['layout' => 'standard', 'image_position' => 'top', 'image_fit' => 'cover', 'image_ratio' => 'wide', 'meta_layout' => 'split', 'card_radius' => 20],
        'linux' => ['layout' => 'compact', 'image_position' => 'top', 'image_fit' => 'contain', 'image_ratio' => 'square', 'meta_layout' => 'stacked', 'card_radius' => 20],
        'linux-table' => ['layout' => 'standard', 'image_position' => 'top', 'image_fit' => 'contain', 'image_ratio' => 'wide', 'meta_layout' => 'split', 'card_radius' => 20],
    ];

    private const DEFAULT_META_LABELS = [
        'audience' => 'Zielgruppe',
        'owner' => 'Verantwortlich',
        'update_cycle' => 'Update-Zyklus',
        'focus' => 'Fokus',
        'kpi' => 'KPI',
    ];

    private ?array $cachedProfiles = null;

    public function __construct(private SiteTableRepository $repository)
    {
    }

    public function getDefaultSettings(): array
    {
        return self::DEFAULT_SETTINGS;
    }

    public function getTemplateProfile(string $key): array
    {
        $profiles = $this->getTemplateProfiles();
        $key = trim($key);
        if ($key !== '' && isset($profiles[$key])) {
            return $profiles[$key];
        }

        return $profiles['general-it'] ?? [
            'label' => 'general-it',
            'base_template' => 'general-it',
            'summary' => '',
            'meta' => [],
            'meta_labels' => self::DEFAULT_META_LABELS,
            'navigation' => ['toc_enabled' => false],
            'links' => self::TEMPLATE_PLACEHOLDERS['general-it']['links'],
            'sections' => self::TEMPLATE_PLACEHOLDERS['general-it']['sections'],
            'colors' => $this->getDefaultTemplateColors('general-it'),
            'card_schema' => $this->getDefaultCardSchema(),
            'card_design' => self::DEFAULT_TEMPLATE_CARD_DESIGN['general-it'],
            'starter_cards' => [],
        ];
    }

    public function resolveHubLinks(array $settings, array $templateProfile, string $template, string $locale = 'de'): array
    {
        $links = $locale !== 'de' && is_array($templateProfile['links_' . $locale] ?? null)
            ? ($templateProfile['links_' . $locale] ?? [])
            : ($templateProfile['links'] ?? []);

        return $this->normalizeHubLinks(is_array($links) ? $links : [], '');
    }

    public function resolveHubSections(array $settings, array $templateProfile, string $template, string $locale = 'de'): array
    {
        $sections = $locale !== 'de' && is_array($templateProfile['sections_' . $locale] ?? null)
            ? ($templateProfile['sections_' . $locale] ?? [])
            : ($templateProfile['sections'] ?? []);

        return $this->normalizeHubSections(is_array($sections) ? $sections : [], '');
    }

    public function resolveHubCardDesign(array $settings, array $templateProfile, string $template): array
    {
        $defaultDesign = self::DEFAULT_TEMPLATE_CARD_DESIGN[$template] ?? self::DEFAULT_TEMPLATE_CARD_DESIGN['general-it'];
        $profileDesign = is_array($templateProfile['card_design'] ?? null) ? $templateProfile['card_design'] : [];
        $resolveProfileStringValue = static function (string $designKey, string $fallback) use ($profileDesign, $defaultDesign): string {
            return (string) ($profileDesign[$designKey] ?? $defaultDesign[$designKey] ?? $fallback);
        };

        $resolveNumberValue = static function (string $settingKey, string $designKey, int $fallback) use ($settings, $profileDesign, $defaultDesign): int {
            if (array_key_exists($settingKey, $settings) && $settings[$settingKey] !== '' && $settings[$settingKey] !== null) {
                return (int) $settings[$settingKey];
            }

            return (int) ($profileDesign[$designKey] ?? $defaultDesign[$designKey] ?? $fallback);
        };

        return [
            'layout' => $this->normalizeOption(
                $resolveProfileStringValue('layout', 'standard'),
                ['standard', 'feature', 'compact'],
                'standard'
            ),
            'image_position' => $this->normalizeOption(
                $resolveProfileStringValue('image_position', 'top'),
                ['top', 'left', 'right'],
                'top'
            ),
            'image_fit' => $this->normalizeOption(
                $resolveProfileStringValue('image_fit', 'cover'),
                ['cover', 'contain'],
                'cover'
            ),
            'image_ratio' => $this->normalizeOption(
                $resolveProfileStringValue('image_ratio', 'wide'),
                ['wide', 'square', 'portrait'],
                'wide'
            ),
            'meta_layout' => $this->normalizeOption(
                $resolveProfileStringValue('meta_layout', 'split'),
                ['split', 'stacked'],
                'split'
            ),
            'card_radius' => $this->normalizeNumber(
                $resolveNumberValue('hub_card_radius', 'card_radius', 20),
                0,
                48,
                20
            ),
            'card_row_gap' => $this->normalizeNumber(
                $resolveNumberValue('hub_card_row_gap', 'card_row_gap', 30),
                30,
                160,
                30
            ),
            'feature_image_width' => $this->normalizeNumber(
                $resolveNumberValue('hub_feature_image_width', 'feature_image_width', 34),
                20,
                60,
                34
            ),
            'feature_image_height' => $this->normalizeNumber(
                $resolveNumberValue('hub_feature_image_height', 'feature_image_height', 260),
                160,
                520,
                260
            ),
        ];
    }

    public function buildHubMetaItems(array $settings, string $template, array $profile = [], string $locale = 'de'): array
    {
        $profileMeta = is_array($profile['meta'] ?? null) ? $profile['meta'] : [];

        $defaultLabels = $locale === 'en'
            ? ['audience' => 'Audience', 'owner' => 'Owner', 'update_cycle' => 'Update cycle', 'focus' => 'Focus', 'kpi' => 'KPI']
            : self::DEFAULT_META_LABELS;
        $profileLabels = $locale !== 'de' && is_array($profile['meta_labels_' . $locale] ?? null)
            ? $profile['meta_labels_' . $locale]
            : (is_array($profile['meta_labels'] ?? null) ? $profile['meta_labels'] : []);
        $labels = array_merge($defaultLabels, $profileLabels);
        $contentLanguage = $this->getTemplateContentLanguage($template, $locale);

        $map = [
            'audience' => ['label' => (string) ($labels['audience'] ?? 'Zielgruppe'), 'value' => trim((string) ($settings['hub_meta_audience'] ?? '')) ?: trim((string) ($profileMeta['audience'] ?? ''))],
            'owner' => ['label' => (string) ($labels['owner'] ?? 'Verantwortlich'), 'value' => trim((string) ($settings['hub_meta_owner'] ?? '')) ?: trim((string) ($profileMeta['owner'] ?? ''))],
            'update_cycle' => ['label' => (string) ($labels['update_cycle'] ?? 'Update-Zyklus'), 'value' => trim((string) ($settings['hub_meta_update_cycle'] ?? '')) ?: trim((string) ($profileMeta['update_cycle'] ?? ''))],
            'focus' => ['label' => (string) ($labels['focus'] ?? 'Fokus'), 'value' => trim((string) ($settings['hub_meta_focus'] ?? '')) ?: trim((string) ($profileMeta['focus'] ?? ''))],
            'kpi' => ['label' => (string) ($labels['kpi'] ?? 'KPI'), 'value' => trim((string) ($settings['hub_meta_kpi'] ?? '')) ?: trim((string) ($profileMeta['kpi'] ?? ''))],
        ];

        $items = [];
        foreach ($map as $key => $item) {
            if ((string) ($item['value'] ?? '') === '') {
                continue;
            }
            $items[] = [
                'key' => $key,
                'label' => (string) $item['label'],
                'value' => (string) $item['value'],
                'icon' => (string) ($contentLanguage['meta_icons'][$key] ?? '•'),
            ];
        }

        return array_slice($items, 0, 5);
    }

    public function buildHubStyleVariables(array $colors, array $cardDesign = []): string
    {
        $palette = array_merge($this->getDefaultTemplateColors('general-it'), $colors);
        $cardRadius = $this->normalizeNumber((int) ($cardDesign['card_radius'] ?? 20), 0, 48, 20);
        $cardRowGap = $this->normalizeNumber((int) ($cardDesign['card_row_gap'] ?? 30), 30, 160, 30);
        $featureImageWidth = $this->normalizeNumber((int) ($cardDesign['feature_image_width'] ?? 34), 20, 60, 34);
        $featureImageHeight = $this->normalizeNumber((int) ($cardDesign['feature_image_height'] ?? 260), 160, 520, 260);

        $pairs = [
            '--cms-hub-hero-start' => $this->normalizeColorValue((string) ($palette['hero_start'] ?? '#1f2937'), '#1f2937'),
            '--cms-hub-hero-end' => $this->normalizeColorValue((string) ($palette['hero_end'] ?? '#0f172a'), '#0f172a'),
            '--cms-hub-accent' => $this->normalizeColorValue((string) ($palette['accent'] ?? '#2563eb'), '#2563eb'),
            '--cms-hub-surface' => $this->normalizeColorValue((string) ($palette['surface'] ?? '#ffffff'), '#ffffff'),
            '--cms-hub-card-bg' => $this->normalizeColorValue((string) ($palette['card_background'] ?? '#ffffff'), '#ffffff'),
            '--cms-hub-card-text' => $this->normalizeColorValue((string) ($palette['card_text'] ?? '#0f172a'), '#0f172a'),
            '--cms-hub-section-bg' => $this->normalizeColorValue((string) ($palette['section_background'] ?? '#ffffff'), '#ffffff'),
            '--cms-hub-table-head-start' => $this->normalizeColorValue((string) ($palette['table_header_start'] ?? $palette['hero_start'] ?? '#1f2937'), '#1f2937'),
            '--cms-hub-table-head-end' => $this->normalizeColorValue((string) ($palette['table_header_end'] ?? $palette['hero_end'] ?? '#0f172a'), '#0f172a'),
            '--cms-hub-card-radius' => $cardRadius . 'px',
            '--hubsite-card-row-gap' => $cardRowGap . 'px',
            '--hubsite-feature-image-width' => $featureImageWidth . '%',
            '--hubsite-feature-image-height' => $featureImageHeight . 'px',
        ];

        $chunks = [];
        foreach ($pairs as $key => $value) {
            $chunks[] = $key . ':' . $value;
        }

        return implode(';', $chunks);
    }

    public function templateSupportsFeatureCards(string $template): bool
    {
        return in_array($template, ['general-it', 'services', 'microsoft-365', 'datenschutz', 'compliance'], true);
    }

    public function getTemplateContentLanguage(string $template, string $locale = 'de'): array
    {
        if ($locale === 'en') {
            return match ($template) {
                'microsoft-365' => [
                    'meta_icons' => ['audience' => '◈', 'owner' => '☁', 'update_cycle' => '↺', 'focus' => '✦', 'kpi' => '↑'],
                    'quicklink_icons' => ['T', 'S', 'C', 'G'],
                    'section_eyebrows' => ['Workspace Layer', 'Guardrails'],
                    'section_icons' => ['☁', '✓'],
                    'section_modifiers' => ['spotlight', 'stacked'],
                    'section_notes' => ['Workloads & journeys', 'Policies & rollout'],
                ],
                'services' => [
                    'meta_icons' => ['audience' => '◎', 'owner' => '◆', 'update_cycle' => '↺', 'focus' => '✦', 'kpi' => '↑'],
                    'quicklink_icons' => ['C', 'M', 'W', 'O', 'E', '@'],
                    'section_eyebrows' => ['Consulting', 'Delivery', 'Enablement'],
                    'section_icons' => ['◆', '▲', '✓'],
                    'section_modifiers' => ['spotlight', 'stacked', 'spotlight'],
                    'section_notes' => ['Discovery & roadmap', 'Implementation & operations', 'Workshops & handover'],
                    'card_cta_label' => 'Request service',
                ],
                'm365-table' => [
                    'meta_icons' => ['audience' => '◈', 'owner' => '☁', 'update_cycle' => '↺', 'focus' => '▦', 'kpi' => '↑'],
                    'quicklink_icons' => ['L', 'F', 'U', 'R'],
                    'section_eyebrows' => ['Matrizen', 'Use Cases'],
                    'section_icons' => ['▦', '✓'],
                    'section_modifiers' => ['spotlight', 'stacked'],
                    'section_notes' => ['Tabellen & Vergleiche', 'Rollout & Nutzen'],
                ],
                'powershell-table' => [
                    'meta_icons' => ['audience' => '⌁', 'owner' => 'PS', 'update_cycle' => '↻', 'focus' => '▦', 'kpi' => '●'],
                    'quicklink_icons' => ['C', 'R', 'M', 'A'],
                    'section_eyebrows' => ['Cmdlets', 'Runbooks'],
                    'section_icons' => ['⌁', '>'],
                    'section_modifiers' => ['terminal', 'terminal'],
                    'section_notes' => ['$ modules=ready', '$ jobs=green'],
                ],
                'datenschutz' => [
                    'meta_icons' => ['audience' => '§', 'owner' => '⚖', 'update_cycle' => '⏱', 'focus' => '✓', 'kpi' => '▣'],
                    'quicklink_icons' => ['§', 'V', 'T', 'R'],
                    'section_eyebrows' => ['Evidence', 'Obligations'],
                    'section_icons' => ['✓', '⚖'],
                    'section_modifiers' => ['trust', 'checklist'],
                    'section_notes' => ['Documentation & proof', 'Deadlines & actions'],
                ],
                'linux' => [
                    'meta_icons' => ['audience' => '⌘', 'owner' => '#', 'update_cycle' => '↻', 'focus' => '▤', 'kpi' => '●'],
                    'quicklink_icons' => ['#', '□', '>', '!'],
                    'section_eyebrows' => ['Runtime', 'Runbooks'],
                    'section_icons' => ['⌘', '>'],
                    'section_modifiers' => ['terminal', 'terminal'],
                    'section_notes' => ['$ health=ok', '$ status=watch'],
                ],
                'linux-table' => [
                    'meta_icons' => ['audience' => '⌘', 'owner' => '#', 'update_cycle' => '↻', 'focus' => '▦', 'kpi' => '●'],
                    'quicklink_icons' => ['#', 'P', '>', '!'],
                    'section_eyebrows' => ['Tabellen', 'Runbooks'],
                    'section_icons' => ['▦', '>'],
                    'section_modifiers' => ['terminal', 'terminal'],
                    'section_notes' => ['$ tables=ok', '$ runbooks=ready'],
                ],
                'general-table' => [
                    'meta_icons' => ['audience' => '◎', 'owner' => '◆', 'update_cycle' => '↺', 'focus' => '▦', 'kpi' => '▲'],
                    'quicklink_icons' => ['Ü', 'T', 'V', 'D'],
                    'section_eyebrows' => ['Tabellen', 'Details'],
                    'section_icons' => ['▦', '▲'],
                    'section_modifiers' => ['spotlight', 'stacked'],
                    'section_notes' => ['Übersichten & Vergleiche', 'Details & Hinweise'],
                ],
                'compliance' => [
                    'meta_icons' => ['audience' => '◎', 'owner' => '◆', 'update_cycle' => '↺', 'focus' => '◌', 'kpi' => '▲'],
                    'quicklink_icons' => ['P', 'A', 'R', 'N'],
                    'section_eyebrows' => ['Controls', 'Evidence'],
                    'section_icons' => ['◆', '▲'],
                    'section_modifiers' => ['spotlight', 'stacked'],
                    'section_notes' => ['Controls & roles', 'Audit & evidence'],
                ],
                'datenschutz-compliance-table' => [
                    'meta_icons' => ['audience' => '§', 'owner' => '◆', 'update_cycle' => '↺', 'focus' => '▦', 'kpi' => '▲'],
                    'quicklink_icons' => ['N', 'C', 'F', 'A'],
                    'section_eyebrows' => ['Evidence', 'Reviews'],
                    'section_icons' => ['✓', '▲'],
                    'section_modifiers' => ['trust', 'stacked'],
                    'section_notes' => ['Nachweise & Kontrollen', 'Fristen & Audits'],
                    'card_cta_label' => '… read more',
                ],
                default => [
                    'meta_icons' => ['audience' => '◎', 'owner' => '◆', 'update_cycle' => '↺', 'focus' => '◌', 'kpi' => '▲'],
                    'quicklink_icons' => ['S', 'P', 'C', 'O'],
                    'section_eyebrows' => ['Architecture', 'Operations'],
                    'section_icons' => ['◆', '▲'],
                    'section_modifiers' => ['spotlight', 'stacked'],
                    'section_notes' => ['Blueprint & standards', 'Services & delivery'],
                    'card_cta_label' => '… read more',
                ],
            };
        }

        return match ($template) {
            'microsoft-365' => [
                'meta_icons' => ['audience' => '◈', 'owner' => '☁', 'update_cycle' => '↺', 'focus' => '✦', 'kpi' => '↑'],
                'quicklink_icons' => ['T', 'S', 'C', 'G'],
                'section_eyebrows' => ['Workspace Layer', 'Guardrails'],
                'section_icons' => ['☁', '✓'],
                'section_modifiers' => ['spotlight', 'stacked'],
                'section_notes' => ['Workloads & Journeys', 'Policies & Rollout'],
                'card_cta_label' => '… weiter Lesen',
            ],
            'services' => [
                'meta_icons' => ['audience' => '◎', 'owner' => '◆', 'update_cycle' => '↺', 'focus' => '✦', 'kpi' => '↑'],
                'quicklink_icons' => ['B', 'M', 'W', 'O', 'E', '@'],
                'section_eyebrows' => ['Beratung', 'Umsetzung', 'Enablement'],
                'section_icons' => ['◆', '▲', '✓'],
                'section_modifiers' => ['spotlight', 'stacked', 'spotlight'],
                'section_notes' => ['Discovery & Roadmap', 'Implementierung & Betrieb', 'Workshops & Übergabe'],
                'card_cta_label' => 'Leistung anfragen',
            ],
            'm365-table' => [
                'meta_icons' => ['audience' => '◈', 'owner' => '☁', 'update_cycle' => '↺', 'focus' => '▦', 'kpi' => '↑'],
                'quicklink_icons' => ['L', 'F', 'U', 'R'],
                'section_eyebrows' => ['Matrizen', 'Use Cases'],
                'section_icons' => ['▦', '✓'],
                'section_modifiers' => ['spotlight', 'stacked'],
                'section_notes' => ['Tabellen & Vergleiche', 'Rollout & Nutzen'],
                'card_cta_label' => '… weiter Lesen',
            ],
            'powershell-table' => [
                'meta_icons' => ['audience' => '⌁', 'owner' => 'PS', 'update_cycle' => '↻', 'focus' => '▦', 'kpi' => '●'],
                'quicklink_icons' => ['C', 'R', 'M', 'A'],
                'section_eyebrows' => ['Cmdlets', 'Runbooks'],
                'section_icons' => ['⌁', '>'],
                'section_modifiers' => ['terminal', 'terminal'],
                'section_notes' => ['$ modules=ready', '$ jobs=green'],
                'card_cta_label' => '… weiter Lesen',
            ],
            'datenschutz' => [
                'meta_icons' => ['audience' => '§', 'owner' => '⚖', 'update_cycle' => '⏱', 'focus' => '✓', 'kpi' => '▣'],
                'quicklink_icons' => ['§', 'V', 'T', 'R'],
                'section_eyebrows' => ['Nachweise', 'Pflichten'],
                'section_icons' => ['✓', '⚖'],
                'section_modifiers' => ['trust', 'checklist'],
                'section_notes' => ['Dokumentation & Belege', 'Fristen & Maßnahmen'],
                'card_cta_label' => '… weiter Lesen',
            ],
            'linux' => [
                'meta_icons' => ['audience' => '⌘', 'owner' => '#', 'update_cycle' => '↻', 'focus' => '▤', 'kpi' => '●'],
                'quicklink_icons' => ['#', '□', '>', '!'],
                'section_eyebrows' => ['Runtime', 'Runbooks'],
                'section_icons' => ['⌘', '>'],
                'section_modifiers' => ['terminal', 'terminal'],
                'section_notes' => ['$ health=ok', '$ status=watch'],
                'card_cta_label' => '… weiter Lesen',
            ],
            'linux-table' => [
                'meta_icons' => ['audience' => '⌘', 'owner' => '#', 'update_cycle' => '↻', 'focus' => '▦', 'kpi' => '●'],
                'quicklink_icons' => ['#', 'P', '>', '!'],
                'section_eyebrows' => ['Tabellen', 'Runbooks'],
                'section_icons' => ['▦', '>'],
                'section_modifiers' => ['terminal', 'terminal'],
                'section_notes' => ['$ tables=ok', '$ runbooks=ready'],
                'card_cta_label' => '… weiter Lesen',
            ],
            'general-table' => [
                'meta_icons' => ['audience' => '◎', 'owner' => '◆', 'update_cycle' => '↺', 'focus' => '▦', 'kpi' => '▲'],
                'quicklink_icons' => ['Ü', 'T', 'V', 'D'],
                'section_eyebrows' => ['Tabellen', 'Details'],
                'section_icons' => ['▦', '▲'],
                'section_modifiers' => ['spotlight', 'stacked'],
                'section_notes' => ['Übersichten & Vergleiche', 'Details & Hinweise'],
                'card_cta_label' => '… weiter Lesen',
            ],
            'compliance' => [
                'meta_icons' => ['audience' => '◎', 'owner' => '◆', 'update_cycle' => '↺', 'focus' => '◌', 'kpi' => '▲'],
                'quicklink_icons' => ['P', 'A', 'R', 'N'],
                'section_eyebrows' => ['Controls', 'Evidence'],
                'section_icons' => ['◆', '▲'],
                'section_modifiers' => ['spotlight', 'stacked'],
                'section_notes' => ['Kontrollen & Rollen', 'Audit & Evidence'],
                'card_cta_label' => '… weiter Lesen',
            ],
            'datenschutz-compliance-table' => [
                'meta_icons' => ['audience' => '§', 'owner' => '◆', 'update_cycle' => '↺', 'focus' => '▦', 'kpi' => '▲'],
                'quicklink_icons' => ['N', 'C', 'F', 'A'],
                'section_eyebrows' => ['Nachweise', 'Reviews'],
                'section_icons' => ['✓', '▲'],
                'section_modifiers' => ['trust', 'stacked'],
                'section_notes' => ['Nachweise & Kontrollen', 'Fristen & Audits'],
                'card_cta_label' => '… weiter Lesen',
            ],
            default => [
                'meta_icons' => ['audience' => '◎', 'owner' => '◆', 'update_cycle' => '↺', 'focus' => '◌', 'kpi' => '▲'],
                'quicklink_icons' => ['S', 'P', 'C', 'B'],
                'section_eyebrows' => ['Architektur', 'Betrieb'],
                'section_icons' => ['◆', '▲'],
                'section_modifiers' => ['spotlight', 'stacked'],
                'section_notes' => ['Zielbild & Standards', 'Services & Delivery'],
                'card_cta_label' => '… weiter Lesen',
            ],
        };
    }

    public function getTemplateProfiles(): array
    {
        if ($this->cachedProfiles !== null) {
            return $this->cachedProfiles;
        }

        $defaults = [];
        foreach (self::TEMPLATE_PLACEHOLDERS as $key => $placeholder) {
            $defaults[$key] = [
                'label' => $key,
                'base_template' => $key,
                'summary' => '',
                'meta' => [],
                'meta_labels' => self::DEFAULT_META_LABELS,
                'navigation' => ['toc_enabled' => false],
                'links' => $placeholder['links'] ?? [],
                'sections' => $placeholder['sections'] ?? [],
                'colors' => $this->getDefaultTemplateColors($key),
                'card_schema' => $this->getDefaultCardSchema(),
                'card_design' => self::DEFAULT_TEMPLATE_CARD_DESIGN[$key] ?? self::DEFAULT_TEMPLATE_CARD_DESIGN['general-it'],
                'starter_cards' => [],
            ];
        }

        $stored = $this->repository->getStoredTemplateProfiles(self::TEMPLATE_SETTING_KEY);
        foreach ($stored as $key => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $baseTemplate = (string) ($profile['base_template'] ?? $key);
            $baseTemplate = in_array($baseTemplate, array_keys(self::TEMPLATE_PLACEHOLDERS), true) ? $baseTemplate : 'general-it';

            $defaults[$key] = [
                'label' => (string) ($profile['label'] ?? $defaults[$key]['label'] ?? $key),
                'base_template' => $baseTemplate,
                'summary' => (string) ($profile['summary'] ?? $defaults[$key]['summary'] ?? ''),
                'meta' => is_array($profile['meta'] ?? null) ? $profile['meta'] : [],
                'meta_labels' => is_array($profile['meta_labels'] ?? null)
                    ? array_merge(self::DEFAULT_META_LABELS, $profile['meta_labels'])
                    : self::DEFAULT_META_LABELS,
                'navigation' => is_array($profile['navigation'] ?? null)
                    ? array_merge(['toc_enabled' => false], $profile['navigation'])
                    : ['toc_enabled' => false],
                'links' => is_array($profile['links'] ?? null) ? $profile['links'] : [],
                'sections' => is_array($profile['sections'] ?? null) ? $profile['sections'] : [],
                'colors' => is_array($profile['colors'] ?? null)
                    ? array_merge($this->getDefaultTemplateColors($baseTemplate), $profile['colors'])
                    : $this->getDefaultTemplateColors($baseTemplate),
                'card_schema' => is_array($profile['card_schema'] ?? null)
                    ? array_merge($this->getDefaultCardSchema(), $profile['card_schema'])
                    : $this->getDefaultCardSchema(),
                'card_design' => is_array($profile['card_design'] ?? null)
                    ? $profile['card_design']
                    : (self::DEFAULT_TEMPLATE_CARD_DESIGN[$baseTemplate] ?? self::DEFAULT_TEMPLATE_CARD_DESIGN['general-it']),
                'starter_cards' => is_array($profile['starter_cards'] ?? null) ? $profile['starter_cards'] : [],
            ];
        }

        $this->cachedProfiles = $defaults;

        return $defaults;
    }

    private function decodeConfiguredArray(string $json): array
    {
        $decoded = \CMS\Json::decodeArray($json, []);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeHubLinks(array $links, string $template): array
    {
        if ($links === [] && $template !== '') {
            $links = self::TEMPLATE_PLACEHOLDERS[$template]['links'] ?? self::TEMPLATE_PLACEHOLDERS['general-it']['links'];
        }

        $normalized = [];
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $label = trim((string) ($link['label'] ?? ''));
            $url = trim((string) ($link['url'] ?? '#'));
            if ($label === '') {
                continue;
            }
            $normalized[] = ['label' => mb_substr($label, 0, 80), 'url' => $url !== '' ? $url : '#'];
        }

        return array_slice($normalized, 0, 6);
    }

    private function normalizeHubSections(array $sections, string $template): array
    {
        if ($sections === [] && $template !== '') {
            $sections = self::TEMPLATE_PLACEHOLDERS[$template]['sections'] ?? self::TEMPLATE_PLACEHOLDERS['general-it']['sections'];
        }

        $normalized = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $title = trim((string) ($section['title'] ?? ''));
            $text = trim((string) ($section['text'] ?? ''));
            if ($title === '' && $text === '') {
                continue;
            }
            $normalized[] = [
                'title' => mb_substr($title, 0, 120),
                'text' => mb_substr($text, 0, 600),
                'actionLabel' => mb_substr(trim((string) ($section['actionLabel'] ?? '')), 0, 80),
                'actionUrl' => mb_substr(trim((string) ($section['actionUrl'] ?? '')), 0, 240),
            ];
        }

        return array_slice($normalized, 0, 4);
    }

    private function normalizeOption(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function normalizeColorValue(string $value, string $fallback): string
    {
        $value = trim($value);
        if ((bool) preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return strtolower($value);
        }

        return strtolower($fallback);
    }

    private function normalizeNumber(int $value, int $min, int $max, int $fallback): int
    {
        if ($value < $min || $value > $max) {
            return $fallback;
        }

        return $value;
    }

    private function getDefaultCardSchema(): array
    {
        return [
            'columns' => 2,
            'title_label' => 'Titel',
            'summary_label' => 'Kurzbeschreibung',
            'badge_label' => 'Badge',
            'meta_left_label' => 'Meta links',
            'meta_right_label' => 'Meta rechts',
            'image_label' => 'Bild-URL',
            'image_alt_label' => 'Bild-Alt',
            'button_text_label' => 'Button-Text',
            'button_link_label' => 'Button-Link',
        ];
    }

    private function getDefaultTemplateColors(string $template): array
    {
        return match ($template) {
            'microsoft-365' => ['hero_start' => '#2d547a', 'hero_end' => '#1e3a5f', 'accent' => '#14b8a6', 'surface' => '#ffffff', 'card_background' => '#ffffff', 'card_text' => '#1e293b', 'section_background' => '#eef4fb', 'table_header_start' => '#2d547a', 'table_header_end' => '#1e3a5f'],
            'services' => ['hero_start' => '#0f2240', 'hero_end' => '#1e3a5f', 'accent' => '#e8a838', 'surface' => '#f8fafc', 'card_background' => '#ffffff', 'card_text' => '#1e293b', 'section_background' => '#f1f5f9', 'table_header_start' => '#0f2240', 'table_header_end' => '#1e3a5f'],
            'm365-table' => ['hero_start' => '#2d547a', 'hero_end' => '#1e3a5f', 'accent' => '#14b8a6', 'surface' => '#ffffff', 'card_background' => '#ffffff', 'card_text' => '#1e293b', 'section_background' => '#eef4fb', 'table_header_start' => '#2d547a', 'table_header_end' => '#1e3a5f'],
            'powershell-table' => ['hero_start' => '#0f2240', 'hero_end' => '#2d547a', 'accent' => '#14b8a6', 'surface' => '#111827', 'card_background' => '#162030', 'card_text' => '#f8fafc', 'section_background' => '#111827', 'table_header_start' => '#2d547a', 'table_header_end' => '#14b8a6'],
            'general-table' => ['hero_start' => '#1e3a5f', 'hero_end' => '#0f2240', 'accent' => '#0d9488', 'surface' => '#ffffff', 'card_background' => '#ffffff', 'card_text' => '#1e293b', 'section_background' => '#f1f5f9', 'table_header_start' => '#0f2240', 'table_header_end' => '#2d547a'],
            'datenschutz' => ['hero_start' => '#0f2240', 'hero_end' => '#0d9488', 'accent' => '#0d9488', 'surface' => '#ffffff', 'card_background' => '#ffffff', 'card_text' => '#1e293b', 'section_background' => '#f2fbfa', 'table_header_start' => '#0d9488', 'table_header_end' => '#1e3a5f'],
            'compliance' => ['hero_start' => '#1e3a5f', 'hero_end' => '#0f2240', 'accent' => '#e8a838', 'surface' => '#ffffff', 'card_background' => '#ffffff', 'card_text' => '#1e293b', 'section_background' => '#fbf8f1', 'table_header_start' => '#1e3a5f', 'table_header_end' => '#e8a838'],
            'datenschutz-compliance-table' => ['hero_start' => '#0f2240', 'hero_end' => '#1e3a5f', 'accent' => '#e8a838', 'surface' => '#ffffff', 'card_background' => '#ffffff', 'card_text' => '#1e293b', 'section_background' => '#f7f8f6', 'table_header_start' => '#0d9488', 'table_header_end' => '#1e3a5f'],
            'linux' => ['hero_start' => '#111827', 'hero_end' => '#0f2240', 'accent' => '#e8a838', 'surface' => '#111827', 'card_background' => '#111827', 'card_text' => '#f1f5f9', 'section_background' => '#111827', 'table_header_start' => '#1e3a5f', 'table_header_end' => '#e8a838'],
            'linux-table' => ['hero_start' => '#111827', 'hero_end' => '#0f2240', 'accent' => '#e8a838', 'surface' => '#111827', 'card_background' => '#111827', 'card_text' => '#f1f5f9', 'section_background' => '#111827', 'table_header_start' => '#1e3a5f', 'table_header_end' => '#e8a838'],
            default => ['hero_start' => '#1e3a5f', 'hero_end' => '#0f2240', 'accent' => '#0d9488', 'surface' => '#ffffff', 'card_background' => '#ffffff', 'card_text' => '#1e293b', 'section_background' => '#f1f5f9', 'table_header_start' => '#1e3a5f', 'table_header_end' => '#0f2240'],
        };
    }
}
