<?php
/**
 * Hooks des Funktionsbereichs „Medien“: Urheber- und Lizenzangaben im Frontend (#11) und Fortsetzung des
 * Auftrags „Vorschaubilder neu erzeugen“ im stündlichen Cron (#13).
 *
 * - Credit-Zeile: Der Editor.js-Renderer fragt je Bild den Filter `cms_image_figcaption_credit` ab
 *   (Einzelbild, Galerie, Karussell). Gibt es zur Bild-URL Angaben in der Mediathek (auch für erzeugte
 *   Größen und WebP-Derivate), wird `<small class="cms-media-credit">…</small>` in der `figcaption`
 *   ergänzt. Abschaltbar global (Medien → Medien-Werkzeuge → Urheberangaben) und durch das Theme über den Filter
 *   `cms_media_credit_line_enabled` (bool, Bild-URL, Kontext).
 * - Schema.org: Alle so ausgegebenen Bilder mit Angaben werden als `ImageObject` (creator, license,
 *   copyrightNotice, creditText) gesammelt und einmal pro Seite als JSON-LD vor `</body>` ausgegeben –
 *   nur im öffentlichen Frontend (`CMS_MODE === 'web'`), nicht im Admin oder in der API.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\FeatureHooks;

use CMS\Hooks;
use CMS\Logger;
use CMS\Services\Media\MediaLicenseService;

if (!defined('ABSPATH')) {
    exit;
}

final class MediaFeatureHooks
{
    private const MAX_SCHEMA_OBJECTS = 50;

    /** @var array<string, array<string, mixed>> contentUrl => ImageObject */
    private static array $schemaObjects = [];

    public static function register(): void
    {
        Hooks::addFilter('cms_image_figcaption_credit', [self::class, 'filterFigcaptionCredit'], 10);

        // #13: Vorschaubilder neu erzeugen – ein im Browser verlassener Auftrag läuft im Cron weiter.
        Hooks::addAction('cms_cron_hourly', static function (): void {
            try {
                \CMS\Services\Media\ThumbnailRegenerationService::getInstance()->continueInCron();
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('media')->warning('Vorschaubilder konnten im Cron nicht weiter erzeugt werden.', ['exception' => $e->getMessage()]);
            }
        }, 60);
        self::registerUsageIndexHooks();

        // 4.1.0 (2a): „AVIF nachgenerieren“ läuft ebenso im Cron weiter.
        Hooks::addAction('cms_cron_hourly', static function (): void {
            try {
                \CMS\Services\Media\AvifRegenerationService::getInstance()->continueInCron();
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('media')->warning('AVIF-Fassungen konnten im Cron nicht weiter erzeugt werden.', ['exception' => $e->getMessage()]);
            }
        }, 61);

        if (defined('CMS_MODE') && CMS_MODE === 'web') {
            Hooks::addAction('body_end', [self::class, 'printSchema'], 20);
        }
    }

    /**
     * 4.1.0 (2b): Verwendungsindex der Medien über die Inhalts-Hooks aktuell halten (Papierkorb und Wiederherstellen
     * lösen die Speicher-Hooks aus und zählen weiter als Verwendung); der Neuaufbau läuft im Cron weiter.
     */
    private static function registerUsageIndexHooks(): void
    {
        $hooks = [
            'cms_after_post_save' => ['post', false],
            'cms_after_post_copy_de_to_en' => ['post', false],
            'post_deleted' => ['post', true],
            'cms_after_page_save' => ['page', false],
            'cms_after_page_copy_de_to_en' => ['page', false],
            'page_deleted' => ['page', true],
            'cms_after_hub_save' => ['hub_site', false],
            'cms_after_download_save' => ['download', false],
            'cms_download_trashed' => ['download', false],
            'download_deleted' => ['download', true],
            'cms_after_form_save' => ['form', false],
            'cms_form_deleted' => ['form', true],
            'cms_reusable_block_saved' => ['reusable_block', false],
            'cms_reusable_block_deleted' => ['reusable_block', true],
        ];
        foreach ($hooks as $hook => [$type, $deleted]) {
            Hooks::addAction($hook, static function (mixed $id = 0) use ($type, $deleted): void {
                self::guardUsage(static function () use ($type, $deleted, $id): void {
                    $index = \CMS\Services\Media\MediaUsageIndex::getInstance();
                    $deleted ? $index->removeContent($type, (int) $id) : $index->reindexContent($type, (int) $id);
                });
            }, 70);
        }

        // Theme-Einstellungen: mehrere Werte je Speichern → einmal am Ende der Anfrage neu indizieren.
        Hooks::addAction('cms_theme_customizer_saved', static function (): void {
            static $scheduled = false;
            if ($scheduled) {
                return;
            }
            $scheduled = true;
            register_shutdown_function(static function (): void {
                self::guardUsage(static fn () => \CMS\Services\Media\MediaUsageIndex::getInstance()->reindexContent('theme', 0));
            });
        }, 70);

        Hooks::addAction('cms_cron_hourly', static function (): void {
            self::guardUsage(static fn () => \CMS\Services\Media\MediaUsageRebuildService::getInstance()->continueInCron());
        }, 62);
    }

    private static function guardUsage(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('media')->warning('Verwendungsindex der Medien konnte nicht aktualisiert werden.', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * @param mixed $html bisherige Credit-Ausgabe (Standard: leer)
     * @param mixed $imageUrl ausgegebene Bild-URL
     * @param mixed $context ['context' => image|gallery|carousel, 'original_url' => URL vor dem Zuschnitt]
     */
    public static function filterFigcaptionCredit(mixed $html = '', mixed $imageUrl = '', mixed $context = []): mixed
    {
        if (!is_string($imageUrl) || $imageUrl === '') {
            return $html;
        }
        $context = is_array($context) ? $context : [];

        $credit = self::creditForUrl($imageUrl, (string) ($context['context'] ?? 'image'), (string) ($context['original_url'] ?? ''));
        if ($credit === '') {
            return $html;
        }

        return (is_string($html) && $html !== '' ? $html . ' ' : '') . $credit;
    }

    /**
     * Credit-Zeile (HTML) zu einer Bild-URL aus der Mediathek – auch für Themes, z. B. bei Beitragsbildern.
     * Merkt das Bild zugleich für das Schema.org-JSON-LD vor. Leer ohne Angaben oder wenn abgeschaltet.
     */
    public static function creditForUrl(string $imageUrl, string $context = 'theme', string $originalUrl = ''): string
    {
        try {
            $service = MediaLicenseService::getInstance();
            $match = $service->findByUrl($imageUrl);
            if ($match === null && $originalUrl !== '' && $originalUrl !== $imageUrl) {
                $match = $service->findByUrl($originalUrl);
            }
            if ($match === null || $match['license'] === []) {
                return '';
            }

            if ($service->isSchemaEnabled()) {
                self::rememberSchema($match['license'], $imageUrl);
            }

            $enabled = (bool) Hooks::applyFilters('cms_media_credit_line_enabled', $service->isCreditLineEnabled(), $imageUrl, $context);

            return $enabled ? MediaLicenseService::creditHtml($match['license']) : '';
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('media.license')->warning('Credit-Zeile konnte nicht ermittelt werden.', [
                'exception' => $e->getMessage(),
            ]);

            return '';
        }
    }

    public static function printSchema(): void
    {
        if (self::$schemaObjects === []) {
            return;
        }

        $payload = [
            '@context' => 'https://schema.org',
            '@graph' => array_values(self::$schemaObjects),
        ];
        self::$schemaObjects = [];

        $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            return;
        }

        echo '<script type="application/ld+json" data-cms-schema="media-license">' . $json . '</script>' . "\n";
    }

    /** @param array<string, mixed> $license */
    private static function rememberSchema(array $license, string $imageUrl): void
    {
        $contentUrl = self::absoluteUrl($imageUrl);
        if ($contentUrl === '' || isset(self::$schemaObjects[$contentUrl]) || count(self::$schemaObjects) >= self::MAX_SCHEMA_OBJECTS) {
            return;
        }

        $object = MediaLicenseService::schemaImageObject($license, $contentUrl);
        if ($object !== []) {
            self::$schemaObjects[$contentUrl] = $object;
        }
    }

    private static function absoluteUrl(string $url): string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return '';
        }

        $site = parse_url(defined('SITE_URL') ? (string) SITE_URL : '');
        if (!is_array($site) || empty($site['host'])) {
            return '';
        }

        return ($site['scheme'] ?? 'https') . '://' . $site['host'] . (isset($site['port']) ? ':' . $site['port'] : '') . $url;
    }
}
