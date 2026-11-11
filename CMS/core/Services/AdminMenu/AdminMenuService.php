<?php
/**
 * Fassade des datengetriebenen Admin-Menüs: Profil-Auflösung, Registry, Builder, Cache, Speichern und Audit.
 *
 * Auflösung des aktiven Layouts (KLARSTELLUNGEN §7):
 * 1. MultiSite konfiguriert und für die aktuelle Site ist ein eigenes Layout gespeichert → dieses (Bereich „site“)
 * 2. Einstellung `admin_menu_profile` = custom und ein globales eigenes Layout existiert → dieses (Bereich „global“)
 * 3. sonst das eingebaute Profil (classic | structured; custom ohne Layout → classic)
 *
 * Der aufgelöste Baum wird gecacht. Der Schlüssel enthält Profil, Bereich, Site, Layout-Version,
 * Plugin-Fingerprint, Capabilities, Core-Module, vorhandene Seitendateien, Marketplace, Sprache,
 * CMS-Version und den Code-Stand des Menüs; jede Änderung daran erzeugt einen neuen Eintrag.
 * Badges und der aktive Zustand werden nie gecacht (AdminNavigationService ergänzt sie danach).
 *
 * @package CMSv2\Core\Services\AdminMenu
 */

declare(strict_types=1);

namespace CMS\Services\AdminMenu;

use CMS\AuditLogger;
use CMS\Auth;
use CMS\CacheManager;
use CMS\Database;
use CMS\Services\AdminSiteContextService;
use CMS\Services\CoreModuleService;
use CMS\Services\OptionStore;
use CMS\Services\PluginSiteAssignmentService;
use CMS\Services\TranslationService;
use CMS\Version;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminMenuService
{
    public const OPTION_PROFILE = 'admin_menu_profile';
    public const CAPABILITY = 'manage_admin_menu';
    public const DEFAULT_PROFILE = ProfileLayouts::CLASSIC;

    /** Herkunft des aktiven Layouts. */
    public const SCOPE_PROFILE = 'profile';
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_SITE = 'site';

    public const CACHE_TTL = 600;
    private const CACHE_PREFIX = 'admin_menu_tree_v1_';

    private static ?self $instance = null;
    private static bool $pluginMenusLoaded = false;

    private ?LayoutRepository $repository = null;
    /** @var array<string, bool> */
    private array $capabilityMemo = [];
    private ?bool $marketplaceEnabled = null;
    /** @var array<string, array{tree:array<string, mixed>, active:array<string, mixed>, registry:MenuRegistry, cached:bool}> je Request (Sidebar + Kachelseite) */
    private array $treeMemo = [];
    /** @var array<string, true>|null */
    private static ?array $catalogIds = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    public function repository(): LayoutRepository
    {
        return $this->repository ??= new LayoutRepository(Database::instance());
    }

    /**
     * Registrierte Plugin-Menüs (Hook `cms_admin_menu` höchstens einmal je Request durch diesen Dienst).
     * add_menu_page()/add_submenu_page() ersetzen Einträge mit gleichem Slug, ein weiterer Aufruf
     * (z. B. durch den Admin-Router) erzeugt keine Dubletten.
     *
     * @return array<int|string, mixed>
     */
    public function registeredPluginMenus(): array
    {
        try {
            if (!self::$pluginMenusLoaded) {
                self::$pluginMenusLoaded = true;
                \CMS\Hooks::doAction('cms_admin_menu');
            }

            return function_exists('get_registered_admin_menus') ? (array) get_registered_admin_menus() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function canManage(): bool
    {
        try {
            $auth = Auth::instance();

            return $auth->isAdmin() && $auth->hasCapability(self::CAPABILITY);
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Einstellung und Auflösung ───────────────────────────────────────────

    public function getProfileSetting(): string
    {
        try {
            $value = (string) (OptionStore::getInstance()->get(self::OPTION_PROFILE) ?? '');
        } catch (\Throwable) {
            $value = '';
        }

        return ProfileLayouts::isProfile($value) ? $value : self::DEFAULT_PROFILE;
    }

    /**
     * Eigene Layouts je Site gibt es nur, wenn unter „MultiSites“ Sites gespeichert sind.
     */
    public function siteScopesEnabled(): bool
    {
        try {
            return AdminSiteContextService::getInstance()->hasConfiguredSites();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * '' (global) oder ein Site-Key aus „MultiSites“.
     */
    public function isValidScopeKey(string $siteKey): bool
    {
        if ($siteKey === LayoutRepository::GLOBAL_SCOPE) {
            return true;
        }

        try {
            return $this->siteScopesEnabled()
                && LayoutRepository::isValidScope($siteKey)
                && AdminSiteContextService::getInstance()->isValidSiteKey($siteKey);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Welches Layout gilt für die Site? Liest höchstens die Versionsnummern (kein layout_json).
     *
     * @return array{profile:string, scope:string, site_key:string, version:int, setting:string}
     */
    public function resolveActive(string $siteKey): array
    {
        $setting = $this->getProfileSetting();
        $siteKey = $siteKey !== '' && $this->isValidScopeKey($siteKey) ? $siteKey : '';

        $versions = [];
        if ($siteKey !== '' || $setting === ProfileLayouts::CUSTOM) {
            try {
                $versions = $this->repository()->versions($siteKey !== '' ? [$siteKey, LayoutRepository::GLOBAL_SCOPE] : [LayoutRepository::GLOBAL_SCOPE]);
            } catch (\Throwable) {
                $versions = [];
            }
        }

        if ($siteKey !== '' && isset($versions[$siteKey])) {
            return ['profile' => ProfileLayouts::CUSTOM, 'scope' => self::SCOPE_SITE, 'site_key' => $siteKey, 'version' => $versions[$siteKey], 'setting' => $setting];
        }
        if ($setting === ProfileLayouts::CUSTOM && isset($versions[LayoutRepository::GLOBAL_SCOPE])) {
            return ['profile' => ProfileLayouts::CUSTOM, 'scope' => self::SCOPE_GLOBAL, 'site_key' => '', 'version' => $versions[LayoutRepository::GLOBAL_SCOPE], 'setting' => $setting];
        }

        return [
            'profile' => $setting === ProfileLayouts::CUSTOM ? self::DEFAULT_PROFILE : $setting,
            'scope' => self::SCOPE_PROFILE,
            'site_key' => '',
            'version' => 0,
            'setting' => $setting,
        ];
    }

    /**
     * Gespeichertes eigenes Layout eines Bereichs, beim Laden erneut validiert (Katalog kann sich geändert haben).
     *
     * @return array{layout:array<string, mixed>, version:int, updated_at:string, updated_by:int|null, warnings:list<string>}|null
     */
    public function storedLayout(string $siteKey): ?array
    {
        try {
            $row = $this->repository()->find($siteKey);
        } catch (\Throwable) {
            $row = null;
        }
        if ($row === null) {
            return null;
        }

        $result = LayoutValidator::validateJson($row['layout_json'], self::catalogIds(), CoreCatalog::lockedIds());
        if (!$result['valid']) {
            return null;
        }

        return [
            'layout' => $result['layout'],
            'version' => $row['version'],
            'updated_at' => $row['updated_at'],
            'updated_by' => $row['updated_by'],
            'warnings' => $result['warnings'],
        ];
    }

    /**
     * Layout zur Auflösung aus resolveActive().
     *
     * @param array{profile:string, scope:string, site_key:string} $active
     * @return array<string, mixed>
     */
    public function layoutFor(array $active): array
    {
        if ($active['scope'] !== self::SCOPE_PROFILE) {
            $stored = $this->storedLayout($active['site_key']);
            if ($stored !== null) {
                return $stored['layout'];
            }
        }

        return ProfileLayouts::get($active['profile'] === ProfileLayouts::CUSTOM ? self::DEFAULT_PROFILE : $active['profile']);
    }

    /**
     * Registry aus Core-Katalog und registrierten Plugin-Menüs; Plugins, die der Site nicht
     * zugewiesen sind, werden ausgelassen (und als ausgeblendet gezählt).
     *
     * @param array<int|string, mixed> $registeredPluginMenus Unverändertes Ergebnis von get_registered_admin_menus()
     */
    public function registry(array $registeredPluginMenus, string $siteKey, string $siteUrl): MenuRegistry
    {
        $isAssigned = null;
        if ($siteKey !== '') {
            try {
                $assignments = PluginSiteAssignmentService::getInstance();
                $isAssigned = static function (array $menu) use ($assignments, $siteKey): bool {
                    try {
                        $pluginSlug = $assignments->resolveMenuPluginSlug($menu);

                        return $pluginSlug === null || $assignments->isAssigned($pluginSlug, $siteKey);
                    } catch (\Throwable) {
                        return true;
                    }
                };
            } catch (\Throwable) {
                $isAssigned = null;
            }
        }

        $preferred = function_exists('cms_admin_menu_get_preferred_parents') ? cms_admin_menu_get_preferred_parents() : [];

        return MenuRegistry::create($registeredPluginMenus, $preferred, $isAssigned, rtrim($siteUrl, '/'));
    }

    public function builder(): MenuBuilder
    {
        return new MenuBuilder(
            fn (array $capabilities): bool => $this->canAny($capabilities),
            static function (string $pageSlug): bool {
                try {
                    return CoreModuleService::getInstance()->isAdminPageEnabled($pageSlug);
                } catch (\Throwable) {
                    return true;
                }
            },
            static fn (string $file): bool => is_file(ABSPATH . $file),
            $this->isMarketplaceEnabled(),
            fn (string $key, string $fallback): string => $this->translate($key, $fallback),
        );
    }

    /**
     * Menübaum für die Sidebar (gecacht). Badges und aktiver Zustand werden danach ergänzt.
     *
     * @param array<int|string, mixed> $registeredPluginMenus
     * @return array{tree:array<string, mixed>, active:array{profile:string, scope:string, site_key:string, version:int, setting:string}, registry:MenuRegistry, cached:bool}
     */
    public function tree(array $registeredPluginMenus, string $siteKey, string $siteUrl): array
    {
        $active = $this->resolveActive($siteKey);
        $registry = $this->registry($registeredPluginMenus, $siteKey, $siteUrl);
        $cacheKey = $this->cacheKey($active, $registry);
        $memoKey = $cacheKey . '|' . rtrim($siteUrl, '/');
        if (isset($this->treeMemo[$memoKey])) {
            return $this->treeMemo[$memoKey];
        }

        try {
            $cached = CacheManager::instance()->get($cacheKey);
            if (is_array($cached) && isset($cached['sections']) && is_array($cached['sections'])) {
                return $this->treeMemo[$memoKey] = ['tree' => $cached, 'active' => $active, 'registry' => $registry, 'cached' => true];
            }
        } catch (\Throwable) {
        }

        $tree = $this->builder()->build($registry, $this->layoutFor($active), $active['profile']);

        try {
            CacheManager::instance()->set($cacheKey, $tree, self::CACHE_TTL);
        } catch (\Throwable) {
        }

        return $this->treeMemo[$memoKey] = ['tree' => $tree, 'active' => $active, 'registry' => $registry, 'cached' => false];
    }

    /**
     * Vorschau ohne Cache: eingebautes Profil oder ein (ungespeichertes) eigenes Layout.
     *
     * @param array<string, mixed>|null $layout validiertes Layout (nur für custom)
     * @return array<string, mixed>
     */
    public function preview(MenuRegistry $registry, string $profile, ?array $layout = null): array
    {
        if ($profile !== ProfileLayouts::CUSTOM || $layout === null) {
            $profile = $profile === ProfileLayouts::STRUCTURED ? ProfileLayouts::STRUCTURED : ProfileLayouts::CLASSIC;
            $layout = ProfileLayouts::get($profile);
        }

        return $this->builder()->build($registry, $layout, $profile);
    }

    /**
     * @param array{profile:string, scope:string, site_key:string, version:int} $active
     */
    private function cacheKey(array $active, MenuRegistry $registry): string
    {
        $capabilities = [];
        $files = [];
        foreach ($registry->items() as $item) {
            foreach ($item->capabilities as $capability) {
                $capabilities[$capability] = true;
            }
            if ($item->file !== '' && !isset($files[$item->file])) {
                $files[$item->file] = is_file(ABSPATH . $item->file);
            }
        }
        ksort($capabilities);
        foreach ($capabilities as $capability => $_) {
            $capabilities[$capability] = $this->can((string) $capability);
        }

        $modules = [];
        try {
            $moduleService = CoreModuleService::getInstance();
            foreach ($moduleService->getKnownModuleSlugs() as $slug) {
                $modules[(string) $slug] = $moduleService->isModuleEnabled((string) $slug);
            }
        } catch (\Throwable) {
            $modules = ['unavailable' => true];
        }

        $data = [
            'profile' => $active['profile'],
            'scope' => $active['scope'],
            'site' => $active['site_key'],
            'layout_version' => $active['version'],
            'plugins' => $registry->pluginFingerprint(),
            'capabilities' => $capabilities,
            'modules' => $modules,
            'files' => $files,
            'marketplace' => $this->isMarketplaceEnabled(),
            'locale' => $this->locale(),
            'cms' => Version::CURRENT,
            'code' => self::codeRevision(),
        ];

        return self::CACHE_PREFIX . sha1((string) json_encode($data));
    }

    /**
     * Änderungszeiten der Menü-Klassen: neue Katalog- oder Profilstände verwerfen alte Cache-Einträge.
     */
    private static function codeRevision(): string
    {
        $stamps = [];
        foreach (glob(__DIR__ . '/*.php') ?: [] as $file) {
            $stamps[basename($file)] = (int) @filemtime($file);
        }

        return sha1((string) json_encode($stamps));
    }

    /**
     * @param list<string> $capabilities eine davon genügt
     */
    public function canAny(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->can((string) $capability)) {
                return true;
            }
        }

        return $capabilities === [];
    }

    private function can(string $capability): bool
    {
        if (!array_key_exists($capability, $this->capabilityMemo)) {
            try {
                $this->capabilityMemo[$capability] = Auth::instance()->hasCapability($capability);
            } catch (\Throwable) {
                $this->capabilityMemo[$capability] = false;
            }
        }

        return $this->capabilityMemo[$capability];
    }

    public function isMarketplaceEnabled(): bool
    {
        if ($this->marketplaceEnabled === null) {
            try {
                $this->marketplaceEnabled = (string) (OptionStore::getInstance()->get('marketplace_enabled') ?? '1') !== '0';
            } catch (\Throwable) {
                $this->marketplaceEnabled = true;
            }
        }

        return $this->marketplaceEnabled;
    }

    private function translate(string $key, string $fallback): string
    {
        try {
            $translated = TranslationService::getInstance()->translate($key);
        } catch (\Throwable) {
            return $fallback;
        }

        return $translated !== '' && $translated !== $key ? $translated : $fallback;
    }

    private function locale(): string
    {
        try {
            return TranslationService::getInstance()->getLocale();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<string, true>
     */
    public static function catalogIds(): array
    {
        if (self::$catalogIds === null) {
            self::$catalogIds = [];
            foreach (CoreCatalog::items() as $item) {
                self::$catalogIds[$item->id] = true;
            }
        }

        return self::$catalogIds;
    }

    // ── Änderungen (jeweils mit Audit-Eintrag) ──────────────────────────────

    /**
     * @return array{success:bool, message:string}
     */
    public function setProfile(string $profile, ?int $userId = null): array
    {
        if (!ProfileLayouts::isProfile($profile)) {
            return ['success' => false, 'message' => 'Unbekanntes Menü-Profil.'];
        }
        if ($profile === ProfileLayouts::CUSTOM && $this->storedLayout(LayoutRepository::GLOBAL_SCOPE) === null) {
            return ['success' => false, 'message' => 'Für „Eigenes Layout“ ist noch kein Layout gespeichert. Bitte zuerst im Menü-Editor ein Layout anlegen und speichern.'];
        }

        $previous = $this->getProfileSetting();
        if ($previous === $profile) {
            return ['success' => true, 'message' => 'Das Menü-Profil ist unverändert.'];
        }
        if (!$this->writeProfileOption($profile)) {
            return ['success' => false, 'message' => 'Das Menü-Profil konnte nicht gespeichert werden.'];
        }

        $this->audit('admin_menu.profile', sprintf('Admin-Menü-Profil geändert: %s → %s', $previous, $profile), [
            'previous' => $previous,
            'profile' => $profile,
        ]);

        return ['success' => true, 'message' => 'Das Menü-Profil wurde gespeichert.'];
    }

    /**
     * Speichert ein eigenes Layout (Bereich '' = global oder Site-Key). Das globale Layout wird
     * dabei zum aktiven Profil „Eigenes Layout“; ein Site-Layout gilt sofort für diese Site.
     *
     * @return array{success:bool, message:string, errors:list<string>, warnings:list<string>, version:int}
     */
    public function saveLayout(string $siteKey, mixed $input, ?int $userId = null): array
    {
        return $this->store($siteKey, LayoutValidator::validate($input, self::catalogIds(), CoreCatalog::lockedIds()), $userId, 'admin_menu.save');
    }

    /**
     * Import einer exportierten JSON-Datei; unbekannte IDs werden mit Warnung übersprungen.
     *
     * @return array{success:bool, message:string, errors:list<string>, warnings:list<string>, version:int}
     */
    public function importJson(string $siteKey, string $json, ?int $userId = null): array
    {
        return $this->store($siteKey, LayoutValidator::validateJson($json, self::catalogIds(), CoreCatalog::lockedIds()), $userId, 'admin_menu.import');
    }

    /**
     * @param array{valid:bool, layout:array<string, mixed>, warnings:list<string>, errors:list<string>} $result
     * @return array{success:bool, message:string, errors:list<string>, warnings:list<string>, version:int}
     */
    private function store(string $siteKey, array $result, ?int $userId, string $action): array
    {
        $fail = static fn (array $errors): array => [
            'success' => false,
            'message' => 'Das Layout wurde nicht gespeichert.',
            'errors' => array_values($errors),
            'warnings' => array_values($result['warnings']),
            'version' => 0,
        ];

        if (!$this->isValidScopeKey($siteKey)) {
            return $fail(['Unbekannter Bereich: Bitte global oder eine unter „MultiSites“ gespeicherte Site wählen.']);
        }
        if (!$result['valid']) {
            return $fail($result['errors'] !== [] ? $result['errors'] : ['Das Layout ist ungültig.']);
        }

        $json = json_encode($result['layout'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return $fail(['Das Layout konnte nicht gespeichert werden.']);
        }

        try {
            $version = $this->repository()->save($siteKey, $json, $userId);
        } catch (\Throwable) {
            return $fail(['Das Layout konnte nicht gespeichert werden.']);
        }

        $previous = $this->getProfileSetting();
        $switched = $siteKey === LayoutRepository::GLOBAL_SCOPE
            && $previous !== ProfileLayouts::CUSTOM
            && $this->writeProfileOption(ProfileLayouts::CUSTOM);

        $scopeLabel = $this->scopeLabel($siteKey);
        $this->audit($action, sprintf(
            $action === 'admin_menu.import' ? 'Admin-Menü-Layout importiert (%s, Version %d)' : 'Admin-Menü-Layout gespeichert (%s, Version %d)',
            $scopeLabel,
            $version
        ), [
            'scope' => $siteKey === '' ? self::SCOPE_GLOBAL : self::SCOPE_SITE,
            'site' => $siteKey,
            'version' => $version,
            'base' => (string) ($result['layout']['base'] ?? ''),
            'nodes' => count((array) ($result['layout']['nodes'] ?? [])),
            'warnings' => count($result['warnings']),
            'profile_switched' => $switched ? $previous . ' → ' . ProfileLayouts::CUSTOM : '',
        ]);

        $message = $action === 'admin_menu.import' ? 'Das Layout wurde importiert und gespeichert.' : 'Das Layout wurde gespeichert.';
        if ($switched) {
            $message .= ' Das Profil „Eigenes Layout“ ist jetzt aktiv.';
        }

        return [
            'success' => true,
            'message' => $message,
            'errors' => [],
            'warnings' => array_values($result['warnings']),
            'version' => $version,
        ];
    }

    /**
     * Verwirft das eigene Layout eines Bereichs. Eine Site nutzt danach wieder die globale Einstellung;
     * global wird auf das Ausgangsprofil des Layouts (Classic/Structured) zurückgestellt.
     *
     * @return array{success:bool, message:string}
     */
    public function reset(string $siteKey, ?int $userId = null): array
    {
        if (!$this->isValidScopeKey($siteKey)) {
            return ['success' => false, 'message' => 'Unbekannter Bereich.'];
        }

        $stored = $this->storedLayout($siteKey);
        try {
            $deleted = $this->repository()->delete($siteKey, $userId);
        } catch (\Throwable) {
            return ['success' => false, 'message' => 'Das Layout konnte nicht zurückgesetzt werden.'];
        }

        $restored = '';
        if ($siteKey === LayoutRepository::GLOBAL_SCOPE && $this->getProfileSetting() === ProfileLayouts::CUSTOM) {
            $base = (string) ($stored['layout']['base'] ?? self::DEFAULT_PROFILE);
            $restored = in_array($base, ProfileLayouts::PROFILES, true) ? $base : self::DEFAULT_PROFILE;
            if (!$this->writeProfileOption($restored)) {
                $restored = '';
            }
        }

        if (!$deleted && $restored === '') {
            return ['success' => true, 'message' => 'Für diesen Bereich ist kein eigenes Layout gespeichert.'];
        }

        $this->audit('admin_menu.reset', sprintf('Admin-Menü-Layout zurückgesetzt (%s)', $this->scopeLabel($siteKey)), [
            'scope' => $siteKey === '' ? self::SCOPE_GLOBAL : self::SCOPE_SITE,
            'site' => $siteKey,
            'previous_version' => (int) ($stored['version'] ?? 0),
            'profile_restored' => $restored,
        ]);

        $message = $siteKey === ''
            ? 'Das eigene Layout wurde verworfen.' . ($restored !== '' ? sprintf(' Aktiv ist wieder das Profil „%s“.', $this->profileLabel($restored)) : '')
            : sprintf('Das Layout für %s wurde verworfen. Die Site nutzt wieder die globale Einstellung.', $this->scopeLabel($siteKey));

        return ['success' => true, 'message' => $message];
    }

    /**
     * JSON-Export des eigenen Layouts eines Bereichs; ohne gespeichertes Layout das dort wirksame Profil.
     */
    public function exportJson(string $siteKey): string
    {
        $siteKey = $this->isValidScopeKey($siteKey) ? $siteKey : LayoutRepository::GLOBAL_SCOPE;
        $stored = $this->storedLayout($siteKey);
        $layout = $stored['layout'] ?? $this->layoutFor($this->resolveActive($siteKey));

        $payload = [
            'format' => LayoutValidator::FORMAT,
            'format_version' => LayoutValidator::FORMAT_VERSION,
            'exported_at' => date('c'),
            'cms_version' => Version::CURRENT,
            'scope' => $siteKey === '' ? self::SCOPE_GLOBAL : self::SCOPE_SITE,
            'site_key' => $siteKey,
            'layout' => $layout,
        ];

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Entfernt Site-Layouts von Sites, die unter „MultiSites“ gelöscht wurden.
     *
     * @return int Anzahl entfernter Layouts
     */
    public function pruneUnknownSites(?int $userId = null): int
    {
        try {
            $scopes = $this->repository()->scopes();
            $sites = AdminSiteContextService::getInstance()->getConfiguredSites();
        } catch (\Throwable) {
            return 0;
        }

        $removed = [];
        foreach (array_keys($scopes) as $siteKey) {
            $siteKey = (string) $siteKey;
            if ($siteKey === LayoutRepository::GLOBAL_SCOPE || isset($sites[$siteKey])) {
                continue;
            }
            try {
                if ($this->repository()->delete($siteKey, $userId)) {
                    $removed[] = $siteKey;
                }
            } catch (\Throwable) {
            }
        }

        if ($removed !== []) {
            $this->audit('admin_menu.reset', sprintf('Admin-Menü-Layouts gelöschter Sites entfernt: %s', implode(', ', $removed)), [
                'scope' => self::SCOPE_SITE,
                'sites' => $removed,
                'reason' => 'site_deleted',
            ]);
        }

        return count($removed);
    }

    /**
     * Bereiche für den Einstellungs-Tab: global und (bei MultiSite) jede gespeicherte Site.
     *
     * @return list<array{key:string, label:string, url:string, has_layout:bool, version:int, updated_at:string, updated_by:int|null}>
     */
    public function scopeOverview(): array
    {
        try {
            $scopes = $this->repository()->scopes();
        } catch (\Throwable) {
            $scopes = [];
        }

        $row = static fn (string $key, string $label, string $url): array => [
            'key' => $key,
            'label' => $label,
            'url' => $url,
            'has_layout' => isset($scopes[$key]),
            'version' => (int) ($scopes[$key]['version'] ?? 0),
            'updated_at' => (string) ($scopes[$key]['updated_at'] ?? ''),
            'updated_by' => $scopes[$key]['updated_by'] ?? null,
        ];

        $overview = [$row(LayoutRepository::GLOBAL_SCOPE, 'Global (alle Sites)', '')];
        if ($this->siteScopesEnabled()) {
            try {
                foreach (AdminSiteContextService::getInstance()->getConfiguredSites() as $key => $site) {
                    $overview[] = $row((string) $key, (string) ($site['label'] ?? $key), (string) ($site['url'] ?? ''));
                }
            } catch (\Throwable) {
            }
        }

        return $overview;
    }

    public function scopeLabel(string $siteKey): string
    {
        if ($siteKey === LayoutRepository::GLOBAL_SCOPE) {
            return 'global';
        }

        try {
            $site = AdminSiteContextService::getInstance()->getSite($siteKey);
        } catch (\Throwable) {
            $site = null;
        }

        return 'Site „' . (string) ($site['label'] ?? $siteKey) . '“';
    }

    public function profileLabel(string $profile): string
    {
        return $this->translate(
            'admin_menu.profile.' . $profile,
            ProfileLayouts::LABELS[$profile] ?? ProfileLayouts::LABELS[ProfileLayouts::CLASSIC]
        );
    }

    private function writeProfileOption(string $profile): bool
    {
        try {
            $db = Database::instance();
            $db->execute(
                'INSERT INTO ' . $db->getPrefix() . 'settings (option_name, option_value, autoload) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)',
                [self::OPTION_PROFILE, $profile]
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(string $action, string $description, array $metadata): void
    {
        try {
            AuditLogger::instance()->log(AuditLogger::CAT_SETTING, $action, $description, 'admin_menu', null, $metadata);
        } catch (\Throwable) {
        }
    }

    /**
     * Nur für Tests: Request-Zustand verwerfen.
     */
    public static function resetForTests(): void
    {
        self::$instance = null;
        self::$pluginMenusLoaded = false;
        self::$catalogIds = null;
    }
}
