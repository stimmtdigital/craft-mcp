<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Composer\Autoload\ClassLoader;
use Craft;
use craft\helpers\ArrayHelper;
use craft\helpers\FileHelper;
use ReflectionClass;
use Throwable;
use yii\helpers\Inflector;

/**
 * Handles refreshing Craft's plugin discovery cache.
 *
 * Craft caches composer plugin info at bootstrap. This class allows
 * refreshing that cache to detect newly installed plugins without
 * a full process restart.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class PluginReloader {
    private const PLUGINS_SERVICE_PROPERTIES = [
        '_storedPluginInfo' => [],
        '_pluginsLoaded' => false,
        '_loadingPlugins' => false,
        '_plugins' => [],
    ];

    /**
     * Reload Composer's classmap to detect new plugin classes.
     *
     * Composer's ClassLoader caches the classmap in memory at bootstrap.
     * This method reloads the classmap file to include newly installed classes.
     */
    public static function reloadComposerClassmap(): void {
        $vendorPath = Craft::$app->getVendorPath();
        $classMapFile = $vendorPath . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'autoload_classmap.php';

        if (!file_exists($classMapFile)) {
            return;
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($classMapFile, true);
        }

        /** @var ClassLoader $loader */
        $loader = require $vendorPath . DIRECTORY_SEPARATOR . 'autoload.php';
        $loader->addClassMap(require $classMapFile);
    }

    /**
     * Clear the project config internal cache.
     *
     * Craft caches project config data with key 'projectConfig:internal'.
     * Must be cleared before reset() to force re-reading from YAML files.
     */
    public static function clearProjectConfigCache(): void {
        Craft::$app->getCache()->delete('projectConfig:internal');
    }

    /**
     * Reset the Plugins service to allow reloading.
     *
     * Clears all internal caches so loadPlugins() will re-read from database
     * and reinstantiate all plugins.
     */
    public static function resetPluginsService(): void {
        $plugins = Craft::$app->getPlugins();
        $ref = new ReflectionClass($plugins);

        foreach (self::PLUGINS_SERVICE_PROPERTIES as $propertyName => $resetValue) {
            $property = $ref->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue($plugins, $resetValue);
        }
    }

    /**
     * Refresh Craft's composer plugin info cache.
     *
     * Re-reads vendor/craftcms/plugins.php and updates the internal cache.
     *
     * @return array{refreshed: bool, plugins: string[], error?: string}
     */
    public static function refreshComposerPluginInfo(): array {
        $plugins = Craft::$app->getPlugins();
        $path = Craft::$app->getVendorPath() . DIRECTORY_SEPARATOR . 'craftcms' . DIRECTORY_SEPARATOR . 'plugins.php';

        if (!file_exists($path)) {
            return [
                'refreshed' => false,
                'plugins' => [],
                'error' => 'plugins.php not found',
            ];
        }

        try {
            // Clear OPcache for this specific file
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($path, true);
            }

            // Re-read plugins.php (use a fresh require to bypass any caching)
            $pluginConfigs = require $path;

            // Build the new composer plugin info array (mirrors Craft's init logic)
            $normalized = array_map(
                fn (string $packageName, array $plugin) => self::normalizePluginConfig($packageName, $plugin),
                array_keys($pluginConfigs),
                $pluginConfigs,
            );

            // Re-key by handle and remove handle from values
            $composerPluginInfo = [];
            foreach ($normalized as $plugin) {
                $handle = ArrayHelper::remove($plugin, 'handle');
                $composerPluginInfo[$handle] = $plugin;
            }

            // Update the private property via reflection
            $ref = new ReflectionClass($plugins);
            $prop = $ref->getProperty('_composerPluginInfo');
            $prop->setAccessible(true);
            $prop->setValue($plugins, $composerPluginInfo);

            return [
                'refreshed' => true,
                'plugins' => array_keys($composerPluginInfo),
            ];
        } catch (Throwable $e) {
            return [
                'refreshed' => false,
                'plugins' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Normalize a single plugin config entry.
     *
     * @return array<string, mixed>
     */
    private static function normalizePluginConfig(string $packageName, array $plugin): array {
        $plugin['packageName'] = $packageName;
        $plugin['handle'] = self::normalizeHandle($plugin['handle'] ?? '');

        $basePath = $plugin['basePath'] ?? null;
        $resolvedPath = $basePath !== null ? realpath($basePath) : false;

        $plugin['basePath'] = $resolvedPath !== false
            ? FileHelper::normalizePath($resolvedPath)
            : null;

        return array_filter($plugin, fn ($v) => $v !== null);
    }

    /**
     * Normalize a plugin handle (mirrors Craft's internal method).
     *
     * Converts camelCase to kebab-case (e.g., feedMe -> feed-me).
     */
    private static function normalizeHandle(string $handle): string {
        if (strtolower($handle) !== $handle) {
            $handle = preg_replace('/\-{2,}/', '-', Inflector::camel2id($handle)) ?? $handle;
        }

        return $handle;
    }
}
