<?php
/**
 * Zentrale Registry der Inhaltstyp-Provider für Papierkorb, Export/Import und Suchindex.
 *
 * Provider melden sich über die Action `cms_register_content_type_providers` an (Parameter: diese
 * Registry), die beim ersten Zugriff einmal ausgelöst wird. Core-Provider registrieren die
 * Funktionsbereiche in ihren `FeatureHooks`-Klassen, Plugins auf dieselbe Weise:
 *
 *     Hooks::addAction('cms_register_content_type_providers', static function (ContentTypeRegistry $registry): void {
 *         $registry->register(new MyTrashProvider());
 *     });
 *
 * Ein Provider wird unter jeder Fähigkeits-Schnittstelle abgelegt, die er implementiert. Je Fähigkeit und
 * Typ gilt die erste Anmeldung; spätere Doppelanmeldungen werden protokolliert und ignoriert.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\ContentTypes;

use CMS\Hooks;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentTypeRegistry
{
    public const REGISTER_ACTION = 'cms_register_content_type_providers';

    /** @var list<class-string<ContentTypeProvider>> */
    private const CAPABILITIES = [
        TrashProvider::class,
        ExchangeProvider::class,
        SearchDocumentProvider::class,
    ];

    private static ?self $instance = null;

    /** @var array<class-string<ContentTypeProvider>, array<string, ContentTypeProvider>> */
    private array $providers = [];

    private bool $booted = false;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Meldet einen Provider unter allen implementierten Fähigkeiten an.
     *
     * @return bool false, wenn der Typ ungültig ist oder keine Fähigkeit neu angemeldet wurde
     */
    public function register(ContentTypeProvider $provider): bool
    {
        $type = $provider->type();
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $type) !== 1) {
            Logger::instance()->withChannel('core.content-types')->warning('Inhaltstyp-Provider mit ungültigem Typ ignoriert.', [
                'type' => $type,
                'provider' => $provider::class,
            ]);
            return false;
        }

        $registered = false;
        foreach (self::CAPABILITIES as $capability) {
            if (!$provider instanceof $capability) {
                continue;
            }

            if (isset($this->providers[$capability][$type])) {
                Logger::instance()->withChannel('core.content-types')->notice('Inhaltstyp-Provider bereits angemeldet, Doppelanmeldung ignoriert.', [
                    'type' => $type,
                    'capability' => $capability,
                    'registered' => $this->providers[$capability][$type]::class,
                    'ignored' => $provider::class,
                ]);
                continue;
            }

            $this->providers[$capability][$type] = $provider;
            $registered = true;
        }

        return $registered;
    }

    /** @return array<string, TrashProvider> Typ => Provider in Anmeldereihenfolge */
    public function trashProviders(): array
    {
        /** @var array<string, TrashProvider> */
        return $this->providersFor(TrashProvider::class);
    }

    /** @return array<string, ExchangeProvider> Typ => Provider, sortiert nach `importPriority()` */
    public function exchangeProviders(): array
    {
        /** @var array<string, ExchangeProvider> $providers */
        $providers = $this->providersFor(ExchangeProvider::class);
        uasort(
            $providers,
            static fn (ExchangeProvider $a, ExchangeProvider $b): int => $a->importPriority() <=> $b->importPriority()
        );

        return $providers;
    }

    /** @return array<string, SearchDocumentProvider> Typ => Provider in Anmeldereihenfolge */
    public function searchProviders(): array
    {
        /** @var array<string, SearchDocumentProvider> */
        return $this->providersFor(SearchDocumentProvider::class);
    }

    public function trashProvider(string $type): ?TrashProvider
    {
        $provider = $this->providersFor(TrashProvider::class)[$type] ?? null;
        return $provider instanceof TrashProvider ? $provider : null;
    }

    public function exchangeProvider(string $type): ?ExchangeProvider
    {
        $provider = $this->providersFor(ExchangeProvider::class)[$type] ?? null;
        return $provider instanceof ExchangeProvider ? $provider : null;
    }

    public function searchProvider(string $type): ?SearchDocumentProvider
    {
        $provider = $this->providersFor(SearchDocumentProvider::class)[$type] ?? null;
        return $provider instanceof SearchDocumentProvider ? $provider : null;
    }

    /**
     * @param class-string<ContentTypeProvider> $capability
     * @return array<string, ContentTypeProvider>
     */
    private function providersFor(string $capability): array
    {
        $this->boot();

        return $this->providers[$capability] ?? [];
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        try {
            Hooks::doAction(self::REGISTER_ACTION, $this);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('core.content-types')->error('Inhaltstyp-Provider konnten nicht vollständig angemeldet werden.', [
                'exception' => $e,
            ]);
        }
    }
}
