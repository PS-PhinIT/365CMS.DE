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

}
