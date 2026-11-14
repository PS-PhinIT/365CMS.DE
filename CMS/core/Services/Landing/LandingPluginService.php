<?php
declare(strict_types=1);

namespace CMS\Services\Landing;

use CMS\Json;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class LandingPluginService
{
    private const ALLOWED_PLUGIN_AREAS = ['header', 'content', 'footer'];

    public function __construct(
        private readonly LandingRepository $repository,
        private readonly LandingSanitizer $sanitizer,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getRegisteredPlugins(): array
    {
        if (!class_exists('\CMS\Hooks')) {
            return [];
        }

        $plugins = \CMS\Hooks::applyFilters('landing_page_plugins', []);
        if (!is_array($plugins)) {
            return [];
        }

        $normalized = [];
        foreach ($plugins as $pluginId => $plugin) {
            if (!is_array($plugin)) {
                continue;
            }

            $id = is_string($pluginId) && $pluginId !== ''
                ? $pluginId
                : $this->sanitizer->sanitizePluginId((string)($plugin['id'] ?? ''));

            if ($id === '') {
                continue;
            }

            $targets = array_values(array_intersect(
                self::ALLOWED_PLUGIN_AREAS,
                array_map('strval', (array)($plugin['targets'] ?? []))
            ));

            $normalized[$id] = [
                'id' => $id,
                'name' => $this->sanitizer->sanitizePlainText((string)($plugin['name'] ?? $id), 120),
                'description' => $this->sanitizer->sanitizePlainText((string)($plugin['description'] ?? ''), 280),
                'version' => $this->sanitizer->sanitizePlainText((string)($plugin['version'] ?? ''), 40),
                'author' => $this->sanitizer->sanitizePlainText((string)($plugin['author'] ?? ''), 80),
                'targets' => $targets,
                'render_callback' => is_callable($plugin['render_callback'] ?? null) ? $plugin['render_callback'] : null,
                'settings_callback' => is_callable($plugin['settings_callback'] ?? null) ? $plugin['settings_callback'] : null,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPluginOverrides(): array
    {
        try {
            $result = $this->repository->getSection('plugin_overrides');
            if ($result === null) {
                return $this->getDefaultPluginOverrides();
            }

            $data = Json::decodeArray($result['data'] ?? null, []);
            $defaults = $this->getDefaultPluginOverrides();
            $merged = array_merge($defaults, is_array($data) ? $data : []);
            $merged['id'] = (int)($result['id'] ?? 0);
            $merged['plugin_settings'] = is_array($merged['plugin_settings'] ?? null) ? $merged['plugin_settings'] : [];

            return $merged;
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('landing')->warning('Landing plugin overrides could not be loaded.', [
                'exception' => $e,
            ]);
            return $this->getDefaultPluginOverrides();
        }
    }

    public function updatePluginOverride(array $data): bool
    {
        $area = (string)($data['area'] ?? '');
        $pluginId = $this->sanitizer->sanitizePluginId((string)($data['plugin_id'] ?? ''));

        if (!in_array($area, self::ALLOWED_PLUGIN_AREAS, true)) {
            return false;
        }

        if ($pluginId !== '') {
            $plugins = $this->getRegisteredPlugins();
            if (
                !isset($plugins[$pluginId])
                || !in_array($area, $plugins[$pluginId]['targets'], true)
                || !is_callable($plugins[$pluginId]['render_callback'] ?? null)
            ) {
                return false;
            }
        }

        $overrides = $this->getPluginOverrides();
        $overrides[$area] = $pluginId === '' ? null : $pluginId;
        unset($overrides['id']);

        return $this->savePluginOverridesRecord($overrides);
    }

    public function savePluginSettings(string $pluginId, array $data): bool
    {
        $pluginId = $this->sanitizer->sanitizePluginId($pluginId);
        if ($pluginId === '') {
            return false;
        }

        $plugins = $this->getRegisteredPlugins();
        if (!isset($plugins[$pluginId])) {
            return false;
        }

        $overrides = $this->getPluginOverrides();
        $overrides['plugin_settings'] = is_array($overrides['plugin_settings'] ?? null) ? $overrides['plugin_settings'] : [];

        $cleanSettings = [];
        foreach ($data as $key => $value) {
            $cleanKey = $this->sanitizer->sanitizePluginId((string)$key);
            if ($cleanKey === '') {
                continue;
            }

            $cleanSettings[$cleanKey] = is_array($value)
                ? $this->sanitizer->sanitizePluginSettingsArray($value)
                : $this->sanitizer->sanitizePlainText((string)$value, 5000);
        }

        $overrides['plugin_settings'][$pluginId] = $cleanSettings;
        unset($overrides['id']);

        return $this->savePluginOverridesRecord($overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPluginSettings(string $pluginId): array
    {
        $overrides = $this->getPluginOverrides();
        return (array)($overrides['plugin_settings'][$pluginId] ?? []);
    }

    /**
     * Rendert den aktiven Plugin-Override für einen Landing-Bereich.
     *
     * @param array<string, mixed> $context
     */
    public function renderPluginOverride(string $area, array $context = []): string
    {
        if (!in_array($area, self::ALLOWED_PLUGIN_AREAS, true)) {
            return '';
        }

        $overrides = $this->getPluginOverrides();
        $pluginId = $this->sanitizer->sanitizePluginId((string) ($overrides[$area] ?? ''));
        if ($pluginId === '') {
            return '';
        }

        $plugins = $this->getRegisteredPlugins();
        $plugin = $plugins[$pluginId] ?? null;
        if (!is_array($plugin) || !in_array($area, (array) ($plugin['targets'] ?? []), true)) {
            return '';
        }

        $callback = $plugin['render_callback'] ?? null;
        if (!is_callable($callback)) {
            return '';
        }

        $settings = $this->getPluginSettings($pluginId);
        $payload = [
            'area' => $area,
            'plugin' => $plugin,
            'settings' => $settings,
            'context' => $context,
        ];

        $buffer = '';

        try {
            ob_start();
            $result = $this->invokePluginCallback($callback, [$payload, $area, $settings, $context, $plugin]);
            $buffer = (string) ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            Logger::instance()->withChannel('landing')->warning('Landing plugin override could not be rendered.', [
                'area' => $area,
                'plugin_id' => $pluginId,
                'exception' => $e,
            ]);

            return '';
        }

        if (is_string($result) && $result !== '') {
            $buffer .= $result;
        }

        return trim($buffer);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function savePluginOverridesRecord(array $overrides): bool
    {
        try {
            return $this->repository->upsertSection('plugin_overrides', $overrides, 200);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('landing')->warning('Landing plugin overrides could not be saved.', [
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getDefaultPluginOverrides(): array
    {
        return [
            'id' => null,
            'header' => null,
            'content' => null,
            'footer' => null,
            'plugin_settings' => [],
        ];
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invokePluginCallback(callable $callback, array $arguments): mixed
    {
        $reflection = $this->reflectCallback($callback);

        if ($reflection !== null && !$reflection->isVariadic()) {
            $arguments = array_slice($arguments, 0, $reflection->getNumberOfParameters());
        }

        return call_user_func_array($callback, $arguments);
    }

    private function reflectCallback(callable $callback): \ReflectionFunctionAbstract|null
    {
        try {
            if (is_array($callback) && isset($callback[0], $callback[1])) {
                return new \ReflectionMethod($callback[0], (string) $callback[1]);
            }

            if (is_string($callback) && str_contains($callback, '::')) {
                return \ReflectionMethod::createFromMethodName($callback);
            }

            if ($callback instanceof \Closure) {
                return new \ReflectionFunction($callback);
            }

            if (is_object($callback) && method_exists($callback, '__invoke')) {
                return new \ReflectionMethod($callback, '__invoke');
            }

            return new \ReflectionFunction(\Closure::fromCallable($callback));
        } catch (\Throwable) {
            return null;
        }
    }
}
