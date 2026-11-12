<?php

declare(strict_types=1);

namespace CMS\Services\Knowledgebase\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class RequestInspector
{
    private static ?string $cachedBasePath = null;

    private static ?bool $cachedTooltipAssets = null;

    private static ?bool $cachedStyleVariables = null;

    private static ?bool $cachedPostSingle = null;

    public static function currentBasePath(): string
    {
        if (self::$cachedBasePath !== null) {
            return self::$cachedBasePath;
        }

        $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $requestPath = $requestPath !== '' ? $requestPath : '/';

        try {
            $context = \CMS\Services\ContentLocalizationService::getInstance()->resolveRequestContext($requestPath);
            $baseUri = (string) ($context['base_uri'] ?? $requestPath);

            self::$cachedBasePath = $baseUri !== '' ? $baseUri : '/';
            return self::$cachedBasePath;
        } catch (\Throwable) {
            self::$cachedBasePath = $requestPath;
            return self::$cachedBasePath;
        }
    }

    public static function isKnowledgebaseRequest(?string $path = null): bool
    {
        $path = $path ?? self::currentBasePath();
        $normalizedPath = self::normalizePublicPath($path);

        return $normalizedPath === '/kb'
            || str_starts_with($normalizedPath, '/kb/')
            || $normalizedPath === '/glossar';
    }

    public static function isGlossaryRequest(?string $path = null): bool
    {
        $path = $path ?? self::currentBasePath();
        $normalizedPath = self::normalizePublicPath($path);

        return $normalizedPath === '/glossar';
    }

    public static function shouldLoadTooltipAssets(): bool
    {
        if (self::$cachedTooltipAssets !== null) {
            return self::$cachedTooltipAssets;
        }

        if (PHP_SAPI === 'cli') {
            self::$cachedTooltipAssets = false;
            return self::$cachedTooltipAssets;
        }

        $path = self::currentBasePath();
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/api/')) {
            self::$cachedTooltipAssets = false;
            return self::$cachedTooltipAssets;
        }

        self::$cachedTooltipAssets = self::isKnowledgebaseRequest($path);

        return self::$cachedTooltipAssets;
    }

    public static function shouldOutputPublicStyleVariables(): bool
    {
        if (self::$cachedStyleVariables !== null) {
            return self::$cachedStyleVariables;
        }

        if (PHP_SAPI === 'cli') {
            self::$cachedStyleVariables = false;
            return self::$cachedStyleVariables;
        }

        $path = self::currentBasePath();
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/api/')) {
            self::$cachedStyleVariables = false;
            return self::$cachedStyleVariables;
        }

        self::$cachedStyleVariables = self::isKnowledgebaseRequest($path);

        return self::$cachedStyleVariables;
    }

    public static function shouldAttachTooltipAttributes(): bool
    {
        return self::shouldLoadTooltipAssets();
    }

    public static function isPostSingleRequest(?string $path = null): bool
    {
        if ($path === null && self::$cachedPostSingle !== null) {
            return self::$cachedPostSingle;
        }

        $path = $path ?? self::currentBasePath();
        if ($path === '/' || $path === '') {
            if ($path === self::currentBasePath()) {
                self::$cachedPostSingle = false;
            }

            return false;
        }

        $postSlug = null;

        try {
            if (class_exists('CMS\\Services\\PermalinkService')) {
                $postSlug = \CMS\Services\PermalinkService::getInstance()->extractPostSlugFromPath($path);
            }
        } catch (\Throwable) {
            $postSlug = null;
        }

        if (($postSlug === null || $postSlug === '') && preg_match('#^/blog/(?P<slug>[^/]+)$#', $path, $matches) === 1) {
            $postSlug = rawurldecode((string) ($matches['slug'] ?? ''));
        }

        if (!is_string($postSlug) || trim($postSlug) === '') {
            if ($path === self::currentBasePath()) {
                self::$cachedPostSingle = false;
            }

            return false;
        }

        try {
            $db = \CMS\Database::instance();
            $row = $db->get_row(
                "SELECT id FROM {$db->getPrefix()}posts WHERE status = 'published' AND (slug = ? OR slug_en = ?) LIMIT 1",
                [$postSlug, $postSlug]
            );

            $result = $row !== null;
            if ($path === self::currentBasePath()) {
                self::$cachedPostSingle = $result;
            }

            return $result;
        } catch (\Throwable) {
            if ($path === self::currentBasePath()) {
                self::$cachedPostSingle = false;
            }

            return false;
        }
    }

    private static function normalizePublicPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        if (function_exists('cms_plugin_public_normalize_path') && function_exists('cms_plugin_public_path_without_lang')) {
            $normalizedPath = cms_plugin_public_normalize_path($path);
            $withoutLang = cms_plugin_public_path_without_lang($normalizedPath);

            return '/' . ltrim($withoutLang, '/');
        }

        return $path;
    }
}
