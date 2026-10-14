<?php
/**
 * Landing Page Service
 * 
 * Verwaltet Landing Page Header und Feature Grid
 * 
 * @package CMSv2\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Logger;
use CMS\Services\Landing\LandingPluginService;
use CMS\Services\Landing\LandingRepository;
use CMS\Services\Landing\LandingSanitizer;
use CMS\Services\Landing\LandingSectionService;

if (!defined('ABSPATH')) {
    exit;
}

class LandingPageService
{
    private static ?self $instance = null;
    private readonly LandingSectionService $sectionService;
    private readonly LandingPluginService $pluginService;
    private readonly Logger $logger;
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct()
    {
        $repository = new LandingRepository(Database::instance());
        $sanitizer = new LandingSanitizer();

        $this->sectionService = new LandingSectionService($repository, $sanitizer);
        $this->pluginService = new LandingPluginService($repository, $sanitizer);
        $this->logger = Logger::instance()->withChannel('service.landing-page');
    }

    /**
     * Stellt sicher, dass alle Basis-Sektionen der Landing Page vorhanden sind
     * und aktualisiert ältere Standard-Inhalte auf die aktuellen 365CMS-Features.
     */
    public function ensureDefaults(): void
    {
        $this->sectionService->ensureDefaults();
    }
    
    /**
     * Get Landing Page Header
     */
    public function getHeader(): array
    {
        return $this->sectionService->getHeader();
    }
    
    /**
     * Update Landing Page Header
     */
    public function updateHeader(array $data): bool
    {
        return $this->sectionService->updateHeader($data);
    }
    
    /**
     * Get all Feature Cards (4x3 Grid)
     */
    public function getFeatures(): array
    {
        return $this->sectionService->getFeatures();
    }
    
    /**
     * Add/Update Feature
     */
    public function saveFeature(?int $id, array $data): int
    {
        return $this->sectionService->saveFeature($id, $data);
    }
    
    /**
     * Delete Feature
     */
    public function deleteFeature(int $id): bool
    {
        return $this->sectionService->deleteFeature($id);
    }
    
    /**
     * Default Features (12 Features für 4x3 Grid)
     */
    private function getDefaultFeatures(): array
    {
        return [
            ['id' => null, 'icon' => '🧩', 'title' => 'Seiten, Beiträge & Landing Pages', 'description' => 'Verwalte klassische Seiten, Blogbeiträge, Hero-Bereiche und eigenständige Landing Pages in einem durchgängigen Workflow.', 'sort_order' => 1],
            ['id' => null, 'icon' => '🧱', 'title' => 'Editor.js & Content-Blöcke', 'description' => 'Nutze strukturierte Inhalte mit modernen Blöcken wie Medien+Text, Galerien, Tabellen, Accordions und weiteren Editor.js-Tools.', 'sort_order' => 2],
            ['id' => null, 'icon' => '🎨', 'title' => 'Theme-Customizer & Design', 'description' => 'Passe Farben, Layouts, Header, Footer, Kartenstile und Theme-Bereiche ohne Code direkt im Admin an.', 'sort_order' => 3],
            ['id' => null, 'icon' => '🖼️', 'title' => 'Medienbibliothek & Uploads', 'description' => 'Organisiere Bilder, Dateien, WebP-Assets und Uploads zentral mit komfortabler Bibliothek und Picker-Workflows.', 'sort_order' => 4],
            ['id' => null, 'icon' => '🔌', 'title' => 'Plugin-Ökosystem', 'description' => 'Erweitere 365CMS flexibel um Unternehmen, Events, Experten, Jobs, Feeds, Formulare und weitere Business-Module.', 'sort_order' => 5],
            ['id' => null, 'icon' => '👤', 'title' => 'Mitgliederbereich', 'description' => 'Biete Dashboard, Profile, Favoriten, Benachrichtigungen und persönliche Bereiche für registrierte Nutzer direkt im System an.', 'sort_order' => 6],
            ['id' => null, 'icon' => '🔐', 'title' => 'Rollen, Passkeys & 2FA', 'description' => 'Arbeite mit granularen Rechten, sicherer Authentifizierung, Passkeys, TOTP und zusätzlicher Zugriffshärtung.', 'sort_order' => 7],
            ['id' => null, 'icon' => '🌐', 'title' => 'SEO, Sitemap & IndexNow', 'description' => 'Steuere Meta-Daten, Redirects, Sitemaps, technische SEO-Prüfungen und IndexNow direkt im Core.', 'sort_order' => 8],
            ['id' => null, 'icon' => '🔎', 'title' => 'Suche & Indizierung', 'description' => 'Nutze Volltextsuche, TNTSearch-Indizes und aktualisierte Suchdaten für Seiten, Beiträge und mehrsprachige Inhalte.', 'sort_order' => 9],
            ['id' => null, 'icon' => '✉️', 'title' => 'Mail Queue & Zustellung', 'description' => 'Versende System- und Projektmails zuverlässig über Queue, SMTP, MIME sowie moderne OAuth- und Retry-Pfade.', 'sort_order' => 10],
            ['id' => null, 'icon' => '📣', 'title' => 'Formulare, Leads & Kontakt', 'description' => 'Bündele Kontaktanfragen, Newsletter-Workflows, Lead-Erfassung und automatische Benachrichtigungen an einer Stelle.', 'sort_order' => 11],
            ['id' => null, 'icon' => '⚙️', 'title' => 'Cron Runner & Automationen', 'description' => 'Starte Cron-Aufgaben, Worker und geplante Prozesse direkt aus dem Admin oder automatisiert im Hintergrund.', 'sort_order' => 12],
            ['id' => null, 'icon' => '🚀', 'title' => 'Performance & Cache', 'description' => 'Verbessere Auslieferung, Assets, Medien, Cache-Verhalten und Reaktionszeiten für schnelle Frontends.', 'sort_order' => 13],
            ['id' => null, 'icon' => '📊', 'title' => 'Monitoring & Health Checks', 'description' => 'Überwache Cron, Antwortzeiten, Speicher, Disk-Usage, Health-Checks und Systemzustände direkt im Dashboard.', 'sort_order' => 14],
            ['id' => null, 'icon' => '♻️', 'title' => 'Updates & Backups', 'description' => 'Halte Core, Themes und Plugins aktuell und kombiniere das mit Backup- und Wiederherstellungsprozessen.', 'sort_order' => 15],
            ['id' => null, 'icon' => '🧾', 'title' => 'DSGVO & Legal Sites', 'description' => 'Pflege Datenschutz- und Rechtsseiten, Consent, Datenexporte sowie Löschprozesse systemweit nachvollziehbar.', 'sort_order' => 16],
            ['id' => null, 'icon' => '🧭', 'title' => 'Menüs, Redirects & Navigation', 'description' => 'Verwalte Menüpositionen, slugbasierte Links, Weiterleitungen und Navigationsstrukturen zentral im Admin.', 'sort_order' => 17],
            ['id' => null, 'icon' => '🧠', 'title' => 'Themes, Hooks & APIs', 'description' => 'Setze auf Customizer, Hooks, Services und dokumentierte Erweiterungspunkte für individuelle 365CMS-Lösungen.', 'sort_order' => 18]
        ];
    }
    
    /**
     * Initialize Default Landing Page
     */
    public function initializeDefaults(): void
    {
        $this->sectionService->initializeDefaults();
    }
    
    /**
     * Get Colors only
     */
    public function getColors(): array
    {
        return $this->sectionService->getColors();
    }

    /**
     * Get Footer Section
     */
    public function getFooter(): array
    {
        return $this->sectionService->getFooter();
    }

    /**
     * Update Footer Section
     */
    public function updateFooter(array $data): bool
    {
        return $this->sectionService->updateFooter($data);
    }

    /**
     * Get Content Section Settings (Grid vs. Freitext)
     */
    public function getContentSettings(): array
    {
        return $this->sectionService->getContentSettings();
    }

    /**
     * Update Content Section Settings
     */
    public function updateContentSettings(array $data): bool
    {
        return $this->sectionService->updateContentSettings($data);
    }

    /**
     * Update Colors (stored inside header record)
     */
    public function updateColors(array $data): bool
    {
        return $this->sectionService->updateColors($data);
    }

    /**
     * Get Landing Page Settings
     */
    public function getSettings(): array
    {
        return $this->sectionService->getSettings();
    }

    /**
     * Update Landing Page Settings
     */
    public function updateSettings(array $data): bool
    {
        return $this->sectionService->updateSettings($data);
    }

    // ── Design Tokens ────────────────────────────────────────────────

    /**
     * Get Landing Page Design Tokens (shape, layout, spacing).
     */
    public function getDesign(): array
    {
        return $this->sectionService->getDesign();
    }

    /**
     * Update Landing Page Design Tokens.
     */
    public function updateDesign(array $data): bool
    {
        return $this->sectionService->updateDesign($data);
    }

    private function hasSectionRecord(string $type): bool
    {
        return $this->countSectionsByType($type) > 0;
    }

    private function countSectionsByType(string $type): int
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->db->prefix()}landing_sections WHERE type = ?");
            if (!$stmt) {
                return 0;
            }

            $stmt->execute([$type]);

            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            $this->logger->error('Landing-Sektionsanzahl konnte nicht geladen werden.', [
                'type' => $type,
                'exception' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    private function ensureSingleSectionRecord(string $type, array $data, int $sortOrder): void
    {
        if ($this->hasSectionRecord($type)) {
            return;
        }

        $payload = $data;
        unset($payload['id']);

        $stmt = $this->db->prepare(
            "INSERT INTO {$this->db->prefix()}landing_sections (type, data, sort_order, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())"
        );

        if ($stmt) {
            $stmt->execute([$type, json_encode($payload), $sortOrder]);
        }
    }

    private function upgradeLegacyFeatureDefaults(): void
    {
        $features = $this->getFeatures();
        $legacyTitles = [
            'Blitzschnell',
            'Sicher',
            'Responsive',
            'Anpassbar',
            'Erweiterbar',
            'Analytics',
            'Multi-User',
            'SEO-Ready',
            'REST API',
            'Backups',
            'Updates',
            'Editor',
        ];

        $currentTitles = array_values(array_map(
            static fn(array $feature): string => trim((string)($feature['title'] ?? '')),
            $features
        ));

        if ($currentTitles !== $legacyTitles) {
            return;
        }

        $deleteStmt = $this->db->prepare("DELETE FROM {$this->db->prefix()}landing_sections WHERE type = 'feature'");
        if ($deleteStmt) {
            $deleteStmt->execute();
        }

        foreach ($this->getDefaultFeatures() as $feature) {
            $this->saveFeature(null, $feature);
        }
    }

    private function backfillMissingDefaultFeatures(): void
    {
        $existingFeatures = $this->getFeatures();
        $defaultFeatures = $this->getDefaultFeatures();

        if (count($existingFeatures) >= count($defaultFeatures)) {
            return;
        }

        $existingTitles = [];
        $existingSortOrders = [];

        foreach ($existingFeatures as $feature) {
            $title = trim((string)($feature['title'] ?? ''));
            if ($title !== '') {
                $existingTitles[] = $this->normalizeLowercase($title);
            }

            $sortOrder = (int)($feature['sort_order'] ?? 0);
            if ($sortOrder > 0) {
                $existingSortOrders[] = $sortOrder;
            }
        }

        foreach ($defaultFeatures as $feature) {
            $defaultTitle = $this->normalizeLowercase(trim((string)($feature['title'] ?? '')));
            $defaultSortOrder = (int)($feature['sort_order'] ?? 0);

            if (
                ($defaultTitle !== '' && in_array($defaultTitle, $existingTitles, true))
                || ($defaultSortOrder > 0 && in_array($defaultSortOrder, $existingSortOrders, true))
            ) {
                continue;
            }

            $this->saveFeature(null, $feature);
        }
    }

    private function mergeWithDefaultFeatures(array $features): array
    {
        $defaultFeatures = $this->getDefaultFeatures();

        if (count($features) >= count($defaultFeatures)) {
            usort($features, static fn(array $a, array $b): int => ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0)));
            return $features;
        }

        $merged = $features;
        $existingTitles = [];
        $existingSortOrders = [];

        foreach ($features as $feature) {
            $title = trim((string)($feature['title'] ?? ''));
            if ($title !== '') {
                $existingTitles[] = $this->normalizeLowercase($title);
            }

            $sortOrder = (int)($feature['sort_order'] ?? 0);
            if ($sortOrder > 0) {
                $existingSortOrders[] = $sortOrder;
            }
        }

        foreach ($defaultFeatures as $feature) {
            $defaultTitle = $this->normalizeLowercase(trim((string)($feature['title'] ?? '')));
            $defaultSortOrder = (int)($feature['sort_order'] ?? 0);

            if (
                ($defaultTitle !== '' && in_array($defaultTitle, $existingTitles, true))
                || ($defaultSortOrder > 0 && in_array($defaultSortOrder, $existingSortOrders, true))
            ) {
                continue;
            }

            $merged[] = $feature;
        }

        usort($merged, static fn(array $a, array $b): int => ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0)));

        return $merged;
    }

    // ── Plugin Override System ────────────────────────────────────────────────

    /**
     * Get all registered Landing Page plugins.
     * Plugins register via Hooks::addFilter('landing_page_plugins', callback).
     * Each entry: ['id', 'name', 'description', 'version', 'author',
     *              'targets' => ['header'|'content'|'footer'],
     *              'render_callback' => callable|null,
     *              'settings_callback' => callable|null]
     */
    public function getRegisteredPlugins(): array
    {
        return $this->pluginService->getRegisteredPlugins();
    }

    /**
     * Get active plugin overrides per area.
     * Returns: ['header' => 'plugin-id'|null, 'content' => ..., 'footer' => ...,
     *           'plugin_settings' => ['plugin-id' => [...]]]
     */
    public function getPluginOverrides(): array
    {
        return $this->pluginService->getPluginOverrides();
    }

    /**
     * Activate or deactivate a plugin override for a specific area.
     * Pass $data['area'] ('header'|'content'|'footer') and
     * $data['plugin_id'] (plugin id string, or '' to reset to CMS default).
     */
    public function updatePluginOverride(array $data): bool
    {
        return $this->pluginService->updatePluginOverride($data);
    }

    /**
     * Save plugin-specific settings for one plugin.
     */
    public function savePluginSettings(string $pluginId, array $data): bool
    {
        return $this->pluginService->savePluginSettings($pluginId, $data);
    }

    /**
     * Get plugin-specific settings for one plugin.
     */
    public function getPluginSettings(string $pluginId): array
    {
        return $this->pluginService->getPluginSettings($pluginId);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function renderPluginOverride(string $area, array $context = []): string
    {
        return $this->pluginService->renderPluginOverride($area, $context);
    }

    private function normalizeLowercase(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return (string) mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }
}
