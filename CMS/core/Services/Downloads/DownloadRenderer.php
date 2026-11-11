<?php
/**
 * Darstellung der Downloads: Editor.js-Block „download“, drei Templates als Partials, URL-Helfer,
 * Frontend-Assets und signierter Quellseiten-Parameter.
 *
 * Partials: `CMS/views/partials/download/{compact,card,featured}.php`, im Theme überschreibbar unter
 * `themes/<slug>/partials/download/<template>.php`. Jedes Partial erhält `$downloads` (Liste von
 * View-Modellen, siehe viewModel()), `$download` (erstes Element), `$options` (Blockoptionen),
 * `$template` und `$mode` (`frontend`, `preview` oder `page`).
 *
 * Blockdaten enthalten nur Referenzen: `{downloadIds, version, template, options{…}}` – nie Pfade oder URLs.
 *
 * @package CMSv2\Core\Services\Downloads
 */

declare(strict_types=1);

namespace CMS\Services\Downloads;

use CMS\Logger;
use CMS\ThemeManager;

if (!defined('ABSPATH')) {
    exit;
}

final class DownloadRenderer
{
    public const BLOCK_TYPE = 'download';
    public const MAX_IDS = 50;
    public const NEW_DAYS = 30;

    public const DEFAULT_OPTIONS = [
        'showDescription' => true,
        'showSize' => true,
        'showVersion' => true,
        'showDate' => true,
        'showHash' => true,
        'showChangelog' => true,
        'showCount' => true,
        'showProperties' => [],
        'buttonLabel' => '',
        'titleOverride' => '',
    ];

    public const BOOL_OPTIONS = ['showDescription', 'showSize', 'showVersion', 'showDate', 'showHash', 'showChangelog', 'showCount'];

    private static ?self $instance = null;

    private bool $assetsNeeded = false;
    private bool $stylesPrinted = false;
    private bool $scriptPrinted = false;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    // ── URLs ─────────────────────────────────────────────────────────────────

    public static function language(): string
    {
        return function_exists('cms_plugin_public_language') && cms_plugin_public_language() === 'en' ? 'en' : 'de';
    }

    public static function siteBase(): string
    {
        return rtrim(defined('SITE_URL') ? (string) SITE_URL : '', '/');
    }

    /**
     * @param array<string,string|int> $query
     */
    public static function archiveUrl(string $lang = 'de', array $query = []): string
    {
        $url = self::siteBase() . ($lang === 'en' ? '/en' : '') . '/downloads';
        $query = array_filter($query, static fn ($value): bool => $value !== '' && $value !== 0 && $value !== null);

        return $query !== [] ? $url . '?' . http_build_query($query) : $url;
    }

    public static function categoryUrl(string $slug, string $lang = 'de'): string
    {
        return self::siteBase() . ($lang === 'en' ? '/en' : '') . '/downloads/category/' . rawurlencode($slug);
    }

    public static function singleUrl(string $slug, string $lang = 'de'): string
    {
        return self::siteBase() . ($lang === 'en' ? '/en' : '') . '/downloads/' . rawurlencode($slug);
    }

    /** Kontrollierte Auslieferungs-URL (nie ein direkter Dateipfad). */
    public static function fileUrl(string $slug, string $version = ''): string
    {
        return self::siteBase() . '/download/' . rawurlencode($slug) . ($version !== '' ? '/' . rawurlencode($version) : '');
    }

    public static function loginUrl(string $returnUrl = ''): string
    {
        $path = $returnUrl !== '' ? (string) parse_url($returnUrl, PHP_URL_PATH) : (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        return self::siteBase() . '/login' . ($path !== '' && $path !== '/' ? '?redirect=' . rawurlencode($path) : '');
    }

    // ── Block ────────────────────────────────────────────────────────────────

    /**
     * Bereinigt Blockdaten (nur Referenzen und Anzeigeoptionen).
     *
     * @param array<string,mixed> $data
     * @return array{downloadIds:list<int>,version:string,template:string,options:array<string,mixed>}
     */
    public static function sanitizeBlock(array $data): array
    {
        $template = (string) ($data['template'] ?? '');
        if (!in_array($template, DownloadSettings::TEMPLATES, true)) {
            $template = self::defaultTemplate();
        }

        $ids = array_slice(DownloadRepository::intList(is_array($data['downloadIds'] ?? null) ? $data['downloadIds'] : []), 0, self::MAX_IDS);
        if ($template !== 'compact') {
            $ids = array_slice($ids, 0, 1);
        }

        $version = trim((string) ($data['version'] ?? 'latest'));
        if ($version === '' || count($ids) !== 1 || ($version !== 'latest' && !DownloadSecurity::isValidVersion($version))) {
            $version = 'latest';
        }

        $raw = is_array($data['options'] ?? null) ? $data['options'] : [];
        $options = self::DEFAULT_OPTIONS;
        foreach (self::BOOL_OPTIONS as $key) {
            if (array_key_exists($key, $raw)) {
                $options[$key] = DownloadSettings::toBool($raw[$key]);
            }
        }
        $properties = [];
        foreach (is_array($raw['showProperties'] ?? null) ? $raw['showProperties'] : [] as $key) {
            if (is_string($key) && preg_match('/^[a-z0-9_]{1,64}$/', $key) === 1 && !in_array($key, $properties, true)) {
                $properties[] = $key;
            }
        }
        $options['showProperties'] = array_slice($properties, 0, 20);
        $options['buttonLabel'] = DownloadService::plainText((string) ($raw['buttonLabel'] ?? ''), 80);
        $options['titleOverride'] = count($ids) <= 1 ? DownloadService::plainText((string) ($raw['titleOverride'] ?? ''), 200) : '';

        return ['downloadIds' => $ids, 'version' => $version, 'template' => $template, 'options' => $options];
    }

    public static function defaultTemplate(): string
    {
        try {
            $template = DownloadSettings::getInstance()->string('default_template');
        } catch (\Throwable) {
            $template = 'card';
        }

        return in_array($template, DownloadSettings::TEMPLATES, true) ? $template : 'card';
    }

    /**
     * Renderer des Editor.js-Blocks. Frontend: nicht verfügbare Downloads werden ausgeblendet;
     * Vorschau (`mode = preview`): Warnhinweis statt Ausblenden.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $tunes
     * @param array{mode?:string} $context
     */
    public function renderBlock(array $data, array $tunes = [], array $context = []): string
    {
        $mode = ($context['mode'] ?? 'frontend') === 'preview' ? 'preview' : 'frontend';
        $block = self::sanitizeBlock($data);
        if ($block['downloadIds'] === []) {
            return $mode === 'preview' ? $this->notice(__('downloads.block.empty'), 'info') : '';
        }

        $service = DownloadService::getInstance();
        if (!$service->isAvailable()) {
            return $mode === 'preview' ? $this->notice(__('downloads.block.service_unavailable'), 'warning') : '';
        }

        $items = $service->getMany($block['downloadIds']);
        $properties = $items !== [] ? $service->repository()->propertyValuesForItems(array_keys($items)) : [];
        $models = [];
        $warnings = [];
        foreach ($block['downloadIds'] as $id) {
            $item = $items[$id] ?? null;
            $reason = $item === null ? 'missing' : self::unavailableReason($item);
            $version = null;
            if ($item !== null && ($reason === '' || $reason === 'draft')) {
                $version = $service->resolveVersion($item, $block['version']);
                if ($version === null) {
                    $reason = $block['version'] === 'latest' ? 'no_version' : 'version_missing';
                }
            }

            if ($reason !== '') {
                if ($mode === 'preview') {
                    $warnings[] = self::reasonText($reason, $item, $id, $block['version']);
                }
                if ($reason !== 'draft' || $mode !== 'preview') {
                    continue;
                }
            }

            $models[] = $this->viewModel($item, $version, $properties[$id] ?? [], $block['options'], $mode);
        }

        $html = '';
        foreach ($warnings as $warning) {
            $html .= $this->notice($warning, 'warning');
        }
        if ($models !== []) {
            $html .= $this->renderPartial($block['template'], $models, $block['options'], $mode);
        }

        return $html;
    }

    /**
     * @param array<string,mixed> $item
     */
    public static function unavailableReason(array $item): string
    {
        return match ((string) ($item['status'] ?? '')) {
            'published' => '',
            'draft' => 'draft',
            'archived' => 'archived',
            'trash' => 'trash',
            default => 'missing',
        };
    }

    /**
     * @param array<string,mixed>|null $item
     */
    public static function reasonText(string $reason, ?array $item, int $id, string $version = 'latest'): string
    {
        $label = $item !== null ? '„' . (string) ($item['title'] ?? '') . '“' : '#' . $id;

        return match ($reason) {
            'draft' => sprintf(__('downloads.block.warning_draft'), $label),
            'archived' => sprintf(__('downloads.block.warning_archived'), $label),
            'trash' => sprintf(__('downloads.block.warning_trash'), $label),
            'no_version' => sprintf(__('downloads.block.warning_no_version'), $label),
            'version_missing' => sprintf(__('downloads.block.warning_version_missing'), $label, $version),
            default => sprintf(__('downloads.block.warning_missing'), $label),
        };
    }

    public function notice(string $message, string $type = 'warning'): string
    {
        $this->assetsNeeded = true;
        $type = in_array($type, ['info', 'warning'], true) ? $type : 'warning';

        return '<div class="cms-dl-notice cms-dl-notice--' . $type . '" role="note">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    // ── Partials ─────────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $downloads View-Modelle
     * @param array<string,mixed> $options
     */
    public function renderPartial(string $template, array $downloads, array $options, string $mode = 'frontend'): string
    {
        if (!in_array($template, DownloadSettings::TEMPLATES, true)) {
            $template = 'card';
        }
        $file = $this->partialPath($template);
        if ($file === null || $downloads === []) {
            return '';
        }
        if ($template !== 'compact') {
            $downloads = array_slice($downloads, 0, 1);
        }
        $this->assetsNeeded = true;

        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__partial, array $downloads, array $download, array $options, string $template, string $mode): void {
                include $__partial;
            })($file, $downloads, $downloads[0], $options, $template, $mode);
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            Logger::instance()->withChannel('downloads')->error('Download-Template konnte nicht gerendert werden.', [
                'template' => $template,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return '';
        }

        return (string) ob_get_clean();
    }

    /**
     * Theme-Override vor Core-Partial.
     */
    public function partialPath(string $template): ?string
    {
        if (!in_array($template, DownloadSettings::TEMPLATES, true)) {
            return null;
        }
        try {
            $themePath = ThemeManager::instance()->getThemePath();
        } catch (\Throwable) {
            $themePath = '';
        }
        if ($themePath !== '') {
            $candidate = rtrim($themePath, '/\\') . '/partials/download/' . $template . '.php';
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        $core = rtrim((string) ABSPATH, '/\\') . '/views/partials/download/' . $template . '.php';

        return is_file($core) ? $core : null;
    }

    /**
     * View-Modell für Partials und Frontend-Seiten (alle Werte roh; Escaping im Template).
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $version
     * @param array<string,string> $propertyValues
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function viewModel(array $item, array $version, array $propertyValues, array $options, string $mode = 'frontend'): array
    {
        $service = DownloadService::getInstance();
        $lang = self::language();
        $slug = (string) $item['slug'];
        $state = $service->accessState($item);
        $locked = $state !== DownloadService::ACCESS_GRANTED;
        $isExternal = !empty($version['is_external']);
        $ext = $isExternal ? 'link' : strtolower((string) (($version['file_ext'] ?? '') !== '' ? $version['file_ext'] : ($item['file_type'] ?? '')));
        $versionNumber = (string) ($version['version'] ?? '');
        $pinned = empty($version['is_current']);
        $size = (int) ($version['file_size'] ?? 0);
        $sizeLabel = $size > 0 ? DownloadSecurity::formatBytes($size) : '';
        $fileName = $isExternal ? (string) parse_url((string) $version['external_url'], PHP_URL_HOST) : (string) ($version['file_name'] ?? '');
        $title = (string) ($options['titleOverride'] ?? '') !== '' ? (string) $options['titleOverride'] : (string) $item['title'];
        $releaseDate = (string) ($version['release_date'] ?? '');
        $createdAt = strtotime((string) ($item['created_at'] ?? '')) ?: 0;
        $isNew = $createdAt > 0 && $createdAt >= time() - self::NEW_DAYS * 86400;
        $updatedLabel = self::formatDate($releaseDate !== '' ? $releaseDate : (string) ($item['updated_at'] ?? ''), $lang);

        $properties = [];
        $fieldMap = DownloadSettings::getInstance()->propertyFieldMap();
        foreach ((array) ($options['showProperties'] ?? []) as $key) {
            if (!isset($fieldMap[$key])) {
                continue;
            }
            $value = trim((string) ($propertyValues[$key] ?? ''));
            if ($value === '' || ($fieldMap[$key]['type'] === 'boolean' && !DownloadSettings::toBool($value) && $value !== '0')) {
                continue;
            }
            $properties[] = [
                'key' => $key,
                'label' => (string) $fieldMap[$key]['label'],
                'type' => (string) $fieldMap[$key]['type'],
                'value' => self::formatProperty((string) $fieldMap[$key]['type'], $value, $lang),
                'url' => $fieldMap[$key]['type'] === 'url' && preg_match('#^https?://#i', $value) === 1 ? $value : '',
            ];
        }

        $changelog = trim(strip_tags((string) ($version['changelog'] ?? '')));
        $buttonLabel = (string) ($options['buttonLabel'] ?? '') !== ''
            ? (string) $options['buttonLabel']
            : ($isExternal ? __('downloads.button.external') : __('downloads.button.download'));

        $lockMessage = match ($state) {
            DownloadService::ACCESS_LOGIN => __('downloads.lock.login'),
            DownloadService::ACCESS_ROLE => __('downloads.lock.role'),
            DownloadService::ACCESS_MEMBERSHIP => __('downloads.lock.membership'),
            default => '',
        };

        return [
            'id' => (int) $item['id'],
            'title' => $title,
            'slug' => $slug,
            'summary' => (string) ($item['summary'] ?? ''),
            'category' => (string) ($item['category_name'] ?? ''),
            'category_url' => (string) ($item['category_slug'] ?? '') !== '' ? self::categoryUrl((string) $item['category_slug'], $lang) : '',
            'ext' => $ext,
            'ext_label' => $isExternal ? __('downloads.type.link') : strtoupper($ext !== '' ? $ext : 'file'),
            'file_group' => DownloadSettings::fileTypeGroup($ext),
            'file_name' => $fileName,
            'is_external' => $isExternal,
            'version' => $versionNumber,
            'version_pinned' => $pinned,
            'release_date' => $releaseDate,
            'release_date_label' => self::formatDate($releaseDate, $lang),
            'size' => $size,
            'size_label' => $sizeLabel,
            'count' => (int) ($item['download_count'] ?? 0),
            'count_label' => number_format((int) ($item['download_count'] ?? 0), 0, ',', '.'),
            'sha256' => $isExternal ? '' : (string) ($version['sha256'] ?? ''),
            'changelog' => $changelog,
            'changelog_excerpt' => self::excerpt($changelog, 280),
            'requirements' => (string) ($item['requirements'] ?? ''),
            'license' => (string) ($item['license'] ?? ''),
            'author' => (string) ($item['author_name'] ?? ''),
            'is_signed' => !empty($item['is_signed']),
            'signature_info' => (string) ($item['signature_info'] ?? ''),
            'cover_url' => self::coverUrl((string) ($item['cover_media'] ?? '')),
            'external_links' => (array) ($item['external_links'] ?? []),
            'properties' => $properties,
            'url' => $locked ? '' : self::fileUrl($slug, $pinned ? $versionNumber : ''),
            'track_source' => $mode === 'frontend' && !$locked,
            'locked' => $locked,
            'lock_state' => $locked ? $state : '',
            'lock_message' => $lockMessage,
            'login_url' => $state === DownloadService::ACCESS_LOGIN ? self::loginUrl() : '',
            'single_url' => self::singleUrl($slug, $lang),
            'button_label' => $buttonLabel,
            'aria_label' => $isExternal
                ? sprintf(__('downloads.aria.external'), $title, $fileName)
                : sprintf(__('downloads.aria.download'), $title, $fileName !== '' ? $fileName : $title, $sizeLabel !== '' ? $sizeLabel : '–'),
            'badge' => $isNew ? 'new' : 'updated',
            'badge_label' => $isNew ? __('downloads.badge.new') : sprintf(__('downloads.badge.updated'), $updatedLabel),
            'status' => (string) ($item['status'] ?? ''),
            'mode' => $mode,
        ];
    }

    public static function formatDate(string $value, string $lang = 'de'): string
    {
        $timestamp = $value !== '' ? strtotime($value) : false;
        if ($timestamp === false || $timestamp <= 0) {
            return '';
        }

        return date($lang === 'en' ? 'Y-m-d' : 'd.m.Y', $timestamp);
    }

    public static function formatProperty(string $type, string $value, string $lang = 'de'): string
    {
        return match ($type) {
            'boolean' => DownloadSettings::toBool($value) ? __('downloads.yes') : __('downloads.no'),
            'date' => self::formatDate($value, $lang) ?: $value,
            default => $value,
        };
    }

    public static function excerpt(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($length <= $max) {
            return $text;
        }
        $cut = function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);

        return rtrim($cut) . '…';
    }

    public static function coverUrl(string $cover): string
    {
        if ($cover === '') {
            return '';
        }
        if (str_starts_with($cover, '/uploads/')) {
            return self::siteBase() . $cover;
        }
        $base = self::siteBase();

        return $base !== '' && str_starts_with($cover, $base . '/') ? $cover : '';
    }

    /** SVG-Symbol (dekorativ) für einen Dateityp. */
    public static function iconSvg(string $group): string
    {
        $path = match ($group) {
            'archive' => '<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M10 5h1M10 8h1M10 11h1M10 14h1v2h-1z"/>',
            'link' => '<path d="M9 15l6 -6"/><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464"/><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463"/>',
            'image' => '<path d="M15 8h.01"/><path d="M3 6a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v12a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3v-12z"/><path d="M3 16l5 -5c.928 -.893 2.072 -.893 3 0l5 5"/>',
            'audio' => '<path d="M3 17a3 3 0 1 0 6 0a3 3 0 0 0 -6 0"/><path d="M9 17v-13h10v13"/><path d="M9 8h10"/>',
            'video' => '<path d="M15 10l4.553 -2.276a1 1 0 0 1 1.447 .894v6.764a1 1 0 0 1 -1.447 .894l-4.553 -2.276v-4z"/><path d="M3 6m0 2a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z"/>',
            default => '<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M9 13h6M9 17h6"/>',
        };

        return '<svg class="cms-dl__svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
    }

    public static function lockSvg(): string
    {
        return '<svg class="cms-dl__svg cms-dl__svg--lock" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z"/><path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0"/><path d="M8 11v-4a4 4 0 1 1 8 0v4"/></svg>';
    }

    /**
     * Hinweis für eingeschränkte Downloads (Schloss + Login-/Mitgliedschaftshinweis, keine Datei-URL).
     *
     * @param array<string,mixed> $dl View-Modell
     */
    public static function lockNotice(array $dl): string
    {
        $html = '<p class="cms-dl__lock">' . self::lockSvg() . '<span>' . htmlspecialchars((string) $dl['lock_message'], ENT_QUOTES, 'UTF-8') . '</span>';
        if ((string) ($dl['login_url'] ?? '') !== '') {
            $html .= ' <a class="cms-dl__login" href="' . htmlspecialchars((string) $dl['login_url'], ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars(__('downloads.lock.login_link'), ENT_QUOTES, 'UTF-8') . '</a>';
        }

        return $html . '</p>';
    }

    /**
     * SHA-256 mit Kopier-Button (CSP-konform: Ereignis über assets/js/downloads.js).
     *
     * @param array<string,mixed> $dl View-Modell
     */
    public static function hashBox(array $dl): string
    {
        $hash = (string) ($dl['sha256'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            return '';
        }
        $label = sprintf(__('downloads.hash.copy_aria'), (string) ($dl['file_name'] ?? '') !== '' ? (string) $dl['file_name'] : (string) $dl['title']);

        return '<div class="cms-dl__hash"><span class="cms-dl__hash-label">SHA-256</span>'
            . '<code class="cms-dl__hash-value">' . $hash . '</code>'
            . '<button type="button" class="cms-dl__copy" data-cms-dl-copy="' . $hash . '" data-copied-label="'
            . htmlspecialchars(__('downloads.hash.copied'), ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars(__('downloads.hash.copy'), ENT_QUOTES, 'UTF-8') . '</button>'
            . '<span class="cms-dl__copy-status" role="status" aria-live="polite"></span></div>';
    }

    // ── Assets und Quellseite ────────────────────────────────────────────────

    public function markAssetsNeeded(): void
    {
        $this->assetsNeeded = true;
    }

    /** `head`: Stylesheet, falls auf dieser Seite Download-Markup gerendert wurde. */
    public function printHeadAssets(): void
    {
        if (!$this->assetsNeeded || $this->stylesPrinted) {
            return;
        }
        $this->stylesPrinted = true;
        echo '<link rel="stylesheet" href="' . htmlspecialchars(self::assetUrl('css/downloads.css'), ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }

    /** `body_end`: Kopier-Skript (und Stylesheet, falls der Inhalt erst nach dem Kopf gerendert wurde). */
    public function printFooterAssets(): void
    {
        if (!$this->assetsNeeded || $this->scriptPrinted) {
            return;
        }
        $this->printHeadAssets();
        $this->scriptPrinted = true;
        $nonce = '';
        try {
            $nonce = (string) \CMS\Security::instance()->getNonce();
        } catch (\Throwable) {
        }
        echo '<script src="' . htmlspecialchars(self::assetUrl('js/downloads.js'), ENT_QUOTES, 'UTF-8') . '"'
            . ($nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '') . ' defer></script>' . "\n";
    }

    public static function assetUrl(string $relative): string
    {
        if (function_exists('cms_asset_url')) {
            return cms_asset_url($relative);
        }

        return self::siteBase() . '/assets/' . ltrim($relative, '/');
    }

    /**
     * `content_render`: Download-Links aus Blöcken erhalten den signierten Quellseiten-Parameter `src`
     * (nur Typ + ID der Seite/des Beitrags, HMAC-signiert).
     */
    public function applySourceToken(string $html, string $type, int $id): string
    {
        if (!str_contains($html, 'data-cms-dl-src')) {
            return $html;
        }
        $token = in_array($type, DownloadSecurity::SOURCE_TYPES, true) && $id > 0 ? DownloadSecurity::signSource($type, $id) : '';

        return (string) preg_replace_callback('/<a\b([^>]*?)\sdata-cms-dl-src(?:="[^"]*")?([^>]*)>/i', static function (array $match) use ($token): string {
            $attributes = $match[1] . $match[2];
            if ($token !== '') {
                $attributes = (string) preg_replace_callback('/\shref="([^"]*)"/i', static function (array $href) use ($token): string {
                    $separator = str_contains($href[1], '?') ? '&amp;' : '?';

                    return ' href="' . $href[1] . $separator . 'src=' . rawurlencode($token) . '"';
                }, $attributes, 1);
            }

            return '<a' . $attributes . '>';
        }, $html);
    }
}
