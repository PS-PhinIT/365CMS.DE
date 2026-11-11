<?php
/**
 * In den Core integrierte Plugins (cms-feed, cms-downloads, cms-contact).
 *
 * - Aktive Exemplare werden beim Laden der Plugins deaktiviert (nicht gelöscht) und nie mehr geladen,
 *   damit keine Klassen, Funktionen oder Routen doppelt registriert werden.
 * - Ihre Daten übernimmt je Plugin ein `PluginMigration\PluginMigrator` (idempotent, in Blöcken):
 *   bei Admin-Aufrufen (5 s je Aufruf) und im täglichen Cron (60 s), bis die Übernahme abgeschlossen ist.
 * - Der Sidebar-Footer zeigt 30 Tage lang den Hinweis „jetzt Teil des Cores“.
 * - Das alles greift je Plugin erst, wenn sein Core-Ersatz im Code-Stand vorhanden ist (erkennbar an der
 *   Migrator-Klasse). Bis dahin läuft das Plugin unverändert weiter.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\AuditLogger;
use CMS\Database;
use CMS\Hooks;
use CMS\Logger;
use CMS\Services\PluginMigration\PluginMigrator;

if (!defined('ABSPATH')) {
    exit;
}

final class IntegratedPluginService
{
    /** Core-Version, mit der die Plugins integriert wurden. */
    public const SINCE_VERSION = '4.0.0';

    /** @var array<string, array{label:string, migrator:class-string, url:string, admin_redirects:array<string, string>}> */
    public const PLUGINS = [
        'cms-feed' => [
            'label' => 'CMS Feed',
            'migrator' => PluginMigration\FeedPluginMigrator::class,
            'url' => '/admin/settings?tab=feeds',
            // Alte Plugin-Adminseiten (/admin/plugins/<plugin>/<seite>) → neue Core-Seiten; '*' = Standard
            'admin_redirects' => ['*' => '/admin/settings?tab=feeds'],
        ],
        'cms-downloads' => [
            'label' => 'CMS Downloads',
            'migrator' => PluginMigration\DownloadsPluginMigrator::class,
            'url' => '/admin/downloads',
            'admin_redirects' => ['*' => '/admin/downloads'],
        ],
        'cms-contact' => [
            'label' => 'CMS Contact',
            'migrator' => PluginMigration\ContactPluginMigrator::class,
            'url' => '/admin/forms',
            'admin_redirects' => [
                'contact-submissions' => '/admin/form-submissions',
                '*' => '/admin/forms',
            ],
        ],
    ];

    /**
     * Ziel-URL für eine alte Plugin-Adminseite eines integrierten Plugins (null = kein integriertes Plugin).
     */
    public static function adminRedirectFor(string $plugin, string $page): ?string
    {
        $plugin = strtolower(trim($plugin));
        if (!self::isIntegrated($plugin)) {
            return null;
        }

        $redirects = self::PLUGINS[$plugin]['admin_redirects'] ?? null;
        if (!is_array($redirects)) {
            return null;
        }

        return $redirects[$page] ?? $redirects['*'] ?? self::PLUGINS[$plugin]['url'];
    }

    private const RETIRED_OPTION = 'integrated_plugins_retired';
    private const MIGRATION_OPTION = 'integrated_plugins_migration';
    private const NOTICE_DAYS = 30;

    private static ?self $instance = null;
    private static bool $migrationRunning = false;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /** Integriert = in PLUGINS aufgeführt und der Core-Ersatz (Migrator-Klasse) ist vorhanden. */
    public static function isIntegrated(string $pluginSlug): bool
    {
        $definition = self::PLUGINS[strtolower(trim($pluginSlug))] ?? null;

        return $definition !== null && class_exists($definition['migrator']);
    }

    /** Hooks: Fortsetzung offener Übernahmen im Admin und im täglichen Cron, Systemhinweis. */
    public static function register(): void
    {
        Hooks::addAction('cms_init_admin', static function (): void {
            self::getInstance()->runPendingMigrations(5.0);
        }, 50);
        Hooks::addAction('cms_cron_daily', static function (): void {
            self::getInstance()->runPendingMigrations(60.0);
        }, 30);
        AdminNoticeService::registerCheck('Integrierte Plugins', static fn (): ?array => self::getInstance()->noticeCheck());
    }

    /**
     * Trennt integrierte Plugins von der Liste aktiver Plugins.
     *
     * @param list<string> $activePlugins
     * @return array{0:list<string>,1:list<string>} [weiter aktiv, deaktiviert]
     */
    public function filterActivePlugins(array $activePlugins): array
    {
        $remaining = [];
        $retired = [];
        foreach ($activePlugins as $slug) {
            if (self::isIntegrated($slug)) {
                $retired[] = $slug;
                continue;
            }
            $remaining[] = $slug;
        }

        return [$remaining, $retired];
    }

    /**
     * Merkt deaktivierte Plugins für den Hinweis vor, protokolliert und bereinigt Site-Zuordnungen.
     *
     * @param list<string> $pluginSlugs
     */
    public function markRetired(array $pluginSlugs): void
    {
        if ($pluginSlugs === []) {
            return;
        }

        $retired = $this->readJsonOption(self::RETIRED_OPTION);
        foreach ($pluginSlugs as $slug) {
            if (!self::isIntegrated($slug)) {
                continue;
            }
            $retired[$slug] = ['at' => date('c'), 'since' => self::SINCE_VERSION];

            try {
                AuditLogger::instance()->log(
                    'plugin',
                    'plugin.integrated_into_core',
                    sprintf('Plugin „%s“ ist seit 365CMS %s Teil des Cores und wurde deaktiviert.', self::PLUGINS[$slug]['label'], self::SINCE_VERSION),
                    'plugin',
                    null,
                    ['plugin' => $slug, 'since' => self::SINCE_VERSION]
                );
            } catch (\Throwable) {
            }
        }
        $this->writeJsonOption(self::RETIRED_OPTION, $retired);
        $this->pruneSiteAssignments($pluginSlugs);

        try {
            AdminNoticeService::getInstance()->clearCache();
        } catch (\Throwable) {
        }
    }

    /**
     * Setzt offene Datenübernahmen fort, bis alle abgeschlossen sind oder das Zeitbudget verbraucht ist.
     *
     * @return array<string, array{done:bool, report:array<string,int>, at:string}>
     */
    public function runPendingMigrations(float $budgetSeconds = 10.0): array
    {
        $state = $this->readJsonOption(self::MIGRATION_OPTION);
        if (self::$migrationRunning || $this->allMigrationsDone($state)) {
            return $state;
        }
        self::$migrationRunning = true;
        $deadline = microtime(true) + max(0.5, $budgetSeconds);

        try {
            foreach (self::PLUGINS as $slug => $definition) {
                if (!empty($state[$slug]['done'])) {
                    continue;
                }

                $migrator = $this->createMigrator($definition['migrator']);
                if ($migrator === null) {
                    // Übernahme für diesen Bereich noch nicht verfügbar – beim nächsten Code-Stand erneut prüfen.
                    continue;
                }

                $done = false;
                try {
                    if (!$migrator->hasSourceData()) {
                        $done = true;
                    } else {
                        do {
                            $done = $migrator->migrate();
                        } while (!$done && microtime(true) < $deadline);
                    }
                } catch (\Throwable $e) {
                    Logger::instance()->withChannel('plugins.integration')->error('Datenübernahme aus integriertem Plugin fehlgeschlagen.', [
                        'plugin' => $slug,
                        'exception' => $e,
                    ]);
                }

                $report = [];
                try {
                    $report = $migrator->report();
                } catch (\Throwable) {
                }

                $state[$slug] = ['done' => $done, 'report' => $report, 'at' => date('c')];
                if ($done) {
                    try {
                        AuditLogger::instance()->log(
                            'plugin',
                            'plugin.data_migrated',
                            sprintf('Daten des Plugins „%s“ wurden in den Core übernommen.', $definition['label']),
                            'plugin',
                            null,
                            ['plugin' => $slug, 'report' => $report]
                        );
                    } catch (\Throwable) {
                    }
                }

                if (microtime(true) >= $deadline) {
                    break;
                }
            }

            $this->writeJsonOption(self::MIGRATION_OPTION, $state);
        } finally {
            self::$migrationRunning = false;
        }

        return $state;
    }

    /**
     * @return array<string, array{label:string, url:string, retired_at:?string, migration_done:bool, report:array<string,int>}>
     */
    public function getStatus(): array
    {
        $retired = $this->readJsonOption(self::RETIRED_OPTION);
        $state = $this->readJsonOption(self::MIGRATION_OPTION);
        $status = [];
        foreach (self::PLUGINS as $slug => $definition) {
            $status[$slug] = [
                'label' => $definition['label'],
                'url' => $definition['url'],
                'retired_at' => is_string($retired[$slug]['at'] ?? null) ? $retired[$slug]['at'] : null,
                'migration_done' => !empty($state[$slug]['done']),
                'report' => is_array($state[$slug]['report'] ?? null) ? $state[$slug]['report'] : [],
            ];
        }

        return $status;
    }

    /**
     * Hinweis im Sidebar-Footer für kürzlich deaktivierte Plugins.
     *
     * @return array{id:string,severity:string,title:string,text:string,url:string,link_label:string}|null
     */
    public function noticeCheck(): ?array
    {
        $retired = $this->readJsonOption(self::RETIRED_OPTION);
        $cutoff = time() - self::NOTICE_DAYS * 86400;
        $labels = [];
        $url = '/admin/plugins';
        foreach ($retired as $slug => $entry) {
            $at = is_array($entry) ? strtotime((string) ($entry['at'] ?? '')) : false;
            if (!self::isIntegrated((string) $slug) || $at === false || $at < $cutoff) {
                continue;
            }
            $labels[] = self::PLUGINS[$slug]['label'];
            $url = self::PLUGINS[$slug]['url'];
        }

        if ($labels === []) {
            return null;
        }

        $translate = static fn (string $text): string => function_exists('__') ? (string) __($text) : $text;

        return AdminNoticeService::makeNotice(
            'plugins_integrated',
            'info',
            $translate('Plugins jetzt Teil des Cores'),
            sprintf(
                $translate('%1$s ist seit 365CMS %2$s Teil des Cores. Das Plugin wurde deaktiviert, seine Daten wurden übernommen.'),
                implode(', ', $labels),
                self::SINCE_VERSION
            ),
            count($labels) === 1 ? $url : '/admin/plugins',
            $translate('Öffnen')
        );
    }

    /** @param class-string $class */
    private function createMigrator(string $class): ?PluginMigrator
    {
        if (!class_exists($class)) {
            return null;
        }

        $migrator = method_exists($class, 'getInstance') ? $class::getInstance() : new $class();

        return $migrator instanceof PluginMigrator ? $migrator : null;
    }

    /** @param array<string, mixed> $state */
    private function allMigrationsDone(array $state): bool
    {
        foreach (self::PLUGINS as $slug => $definition) {
            if (empty($state[$slug]['done']) && class_exists($definition['migrator'])) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $pluginSlugs */
    private function pruneSiteAssignments(array $pluginSlugs): void
    {
        try {
            PluginSiteAssignmentService::getInstance()->removePlugins($pluginSlugs);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('plugins.integration')->warning('Site-Zuordnungen integrierter Plugins konnten nicht bereinigt werden.', [
                'exception' => $e,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function readJsonOption(string $name): array
    {
        try {
            $db = Database::instance();
            $raw = $db->get_var(
                "SELECT option_value FROM {$db->getPrefix()}settings WHERE option_name = ? LIMIT 1",
                [$name]
            );
        } catch (\Throwable) {
            return [];
        }

        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $value */
    private function writeJsonOption(string $name, array $value): void
    {
        try {
            $db = Database::instance();
            $json = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $exists = (int) $db->get_var("SELECT COUNT(*) FROM {$db->getPrefix()}settings WHERE option_name = ?", [$name]) > 0;
            if ($exists) {
                $db->execute("UPDATE {$db->getPrefix()}settings SET option_value = ? WHERE option_name = ?", [$json, $name]);
            } else {
                $db->execute("INSERT INTO {$db->getPrefix()}settings (option_name, option_value) VALUES (?, ?)", [$name, $json]);
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('plugins.integration')->warning('Status integrierter Plugins konnte nicht gespeichert werden.', [
                'option' => $name,
                'exception' => $e,
            ]);
        }
    }
}
