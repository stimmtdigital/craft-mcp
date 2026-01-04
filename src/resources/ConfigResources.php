<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\resources;

use Craft;
use craft\config\GeneralConfig;
use craft\elements\Asset;
use craft\models\Site;
use craft\models\Volume;
use craft\services\Config;
use craft\services\Plugins;
use craft\services\Routes;
use craft\services\Sites;
use craft\services\Volumes;
use Mcp\Capability\Attribute\McpResource;
use ReflectionClass;
use stimmt\craft\Mcp\attributes\McpResourceMeta;
use stimmt\craft\Mcp\enums\ResourceCategory;

/**
 * MCP resources for Craft CMS configuration information.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class ConfigResources {
    /**
     * Get safe general configuration values (no secrets).
     *
     * @return array{general: array<string, mixed>}
     */
    #[McpResource(
        uri: 'craft://config/general',
        name: 'general-config',
        description: 'Safe general configuration values from Craft CMS (excludes sensitive data).',
        mimeType: 'application/json',
    )]
    #[McpResourceMeta(category: ResourceCategory::CONFIG)]
    public function generalConfig(): array {
        /** @var Config $configService */
        $configService = Craft::$app->getConfig();

        /** @var GeneralConfig $config */
        $config = $configService->getGeneral();

        // Only expose safe configuration values
        return [
            'general' => [
                'devMode' => $config->devMode,
                'allowAdminChanges' => $config->allowAdminChanges,
                'allowUpdates' => $config->allowUpdates,
                'cpTrigger' => $config->cpTrigger,
                'defaultWeekStartDay' => $config->defaultWeekStartDay,
                'enableGql' => $config->enableGql,
                'errorTemplatePrefix' => $config->errorTemplatePrefix,
                'generateTransformsBeforePageLoad' => $config->generateTransformsBeforePageLoad,
                'headlessMode' => $config->headlessMode,
                'isSystemLive' => $config->isSystemLive,
                'maxRevisions' => $config->maxRevisions,
                'omitScriptNameInUrls' => $config->omitScriptNameInUrls,
                'pageTrigger' => $config->pageTrigger,
                'runQueueAutomatically' => $config->runQueueAutomatically,
                'sendPoweredByHeader' => $config->sendPoweredByHeader,
                'timezone' => $config->timezone,
                'translationDebugOutput' => $config->translationDebugOutput,
                'useEmailAsUsername' => $config->useEmailAsUsername,
                'usePathInfo' => $config->usePathInfo,
            ],
        ];
    }

    /**
     * Get registered routes.
     *
     * @return array{routes: array<string, string>}
     */
    #[McpResource(
        uri: 'craft://config/routes',
        name: 'routes-config',
        description: 'Custom routes configured in Craft CMS.',
        mimeType: 'application/json',
    )]
    #[McpResourceMeta(category: ResourceCategory::CONFIG)]
    public function routesConfig(): array {
        /** @var Routes $routesService */
        $routesService = Craft::$app->getRoutes();

        /** @var array<string, string> $routes */
        $routes = $routesService->getProjectConfigRoutes();

        return ['routes' => $routes];
    }

    /**
     * Get sites configuration.
     *
     * @return array{sites: list<array{handle: string, name: string, language: string, primary: bool, enabled: bool, baseUrl: string|null}>}
     */
    #[McpResource(
        uri: 'craft://config/sites',
        name: 'sites-config',
        description: 'All configured sites in Craft CMS with their settings.',
        mimeType: 'application/json',
    )]
    #[McpResourceMeta(category: ResourceCategory::CONFIG)]
    public function sitesConfig(): array {
        /** @var Sites $sitesService */
        $sitesService = Craft::$app->getSites();

        /** @var Site[] $sites */
        $sites = $sitesService->getAllSites();

        return [
            'sites' => array_values(array_map($this->buildSiteInfo(...), $sites)),
        ];
    }

    /**
     * Get volumes configuration.
     *
     * @return array{volumes: list<array{handle: string, name: string, fsType: string, hasUrls: bool, assetCount: int}>}
     */
    #[McpResource(
        uri: 'craft://config/volumes',
        name: 'volumes-config',
        description: 'All configured asset volumes in Craft CMS.',
        mimeType: 'application/json',
    )]
    #[McpResourceMeta(category: ResourceCategory::CONFIG)]
    public function volumesConfig(): array {
        /** @var Volumes $volumesService */
        $volumesService = Craft::$app->getVolumes();

        /** @var Volume[] $volumes */
        $volumes = $volumesService->getAllVolumes();

        return [
            'volumes' => array_values(array_map($this->buildVolumeInfo(...), $volumes)),
        ];
    }

    /**
     * Get installed plugins information.
     *
     * @return array{plugins: list<array{handle: string, name: string, version: string, enabled: bool, developer: string|null}>}
     */
    #[McpResource(
        uri: 'craft://config/plugins',
        name: 'installed-plugins',
        description: 'List of all installed plugins with their status.',
        mimeType: 'application/json',
    )]
    #[McpResourceMeta(category: ResourceCategory::CONFIG)]
    public function pluginsConfig(): array {
        /** @var Plugins $pluginsService */
        $pluginsService = Craft::$app->getPlugins();

        /** @var array<string, array{name: string, version: string, isInstalled: bool, isEnabled: bool, developer?: string}> $plugins */
        $plugins = $pluginsService->getAllPluginInfo();

        $result = [];
        foreach ($plugins as $handle => $info) {
            $result[] = [
                'handle' => $handle,
                'name' => $info['name'],
                'version' => $info['version'],
                'enabled' => $info['isInstalled'] && $info['isEnabled'],
                'developer' => $info['developer'] ?? null,
            ];
        }

        return ['plugins' => $result];
    }

    /**
     * Build site info array.
     *
     * @return array{handle: string, name: string, language: string, primary: bool, enabled: bool, baseUrl: string|null}
     */
    private function buildSiteInfo(Site $site): array {
        return [
            'handle' => $site->handle ?? '',
            'name' => $site->getName(),
            'language' => $site->language,
            'primary' => $site->primary,
            'enabled' => (bool) $site->enabled,
            'baseUrl' => $site->getBaseUrl(),
        ];
    }

    /**
     * Build volume info array.
     *
     * @return array{handle: string, name: string, fsType: string, hasUrls: bool, assetCount: int}
     */
    private function buildVolumeInfo(Volume $volume): array {
        $fs = $volume->getFs();
        $handle = $volume->handle ?? '';

        return [
            'handle' => $handle,
            'name' => $volume->name ?? '',
            'fsType' => (new ReflectionClass($fs))->getShortName(),
            'hasUrls' => $fs->hasUrls,
            'assetCount' => (int) Asset::find()->volume($handle)->count(),
        ];
    }
}
