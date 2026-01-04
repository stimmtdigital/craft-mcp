<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;

/**
 * MCP Tools for Craft CMS
 *
 * These methods are exposed as MCP tools that AI assistants can call.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class CraftTools {
    /**
     * List all installed plugins with their status and version info.
     */
    #[McpTool(
        name: 'list_plugins',
        description: 'List all installed Craft CMS plugins with their enabled status, version, and handle',
    )]
    #[McpToolMeta(category: ToolCategory::SCHEMA)]
    public function listPlugins(): array {
        $pluginsService = Craft::$app->getPlugins();
        $allPluginInfo = $pluginsService->getAllPluginInfo();

        $plugins = [];
        foreach ($allPluginInfo as $handle => $info) {
            $plugins[] = [
                'handle' => $handle,
                'name' => $info['name'] ?? $handle,
                'version' => $info['version'] ?? 'unknown',
                'isInstalled' => $info['isInstalled'] ?? false,
                'isEnabled' => $info['isEnabled'] ?? false,
                'schemaVersion' => $info['schemaVersion'] ?? null,
                'description' => $info['description'] ?? null,
                'developer' => $info['developer'] ?? null,
            ];
        }

        return [
            'count' => count($plugins),
            'plugins' => $plugins,
        ];
    }

    /**
     * List all sections (channels, structures, singles) in Craft.
     */
    #[McpTool(
        name: 'list_sections',
        description: 'List all sections (channels, structures, singles) in Craft CMS with their entry types',
    )]
    #[McpToolMeta(category: ToolCategory::SCHEMA)]
    public function listSections(): array {
        $sectionsService = Craft::$app->getEntries();
        $allSections = $sectionsService->getAllSections();

        $sections = array_map(
            fn ($section) => [
                'id' => $section->id,
                'handle' => $section->handle,
                'name' => $section->name,
                'type' => is_string($section->type) ? $section->type : $section->type->value,
                'entryTypes' => array_map(
                    fn ($entryType) => [
                        'id' => $entryType->id,
                        'handle' => $entryType->handle,
                        'name' => $entryType->name,
                    ],
                    $section->getEntryTypes(),
                ),
                'siteSettings' => array_map(
                    fn ($settings) => [
                        'siteId' => $settings->siteId,
                        'hasUrls' => $settings->hasUrls,
                        'uriFormat' => $settings->uriFormat,
                        'template' => $settings->template,
                    ],
                    $section->getSiteSettings(),
                ),
            ],
            $allSections,
        );

        return [
            'count' => count($sections),
            'sections' => $sections,
        ];
    }

    /**
     * Get information about the Craft CMS installation.
     */
    #[McpTool(
        name: 'get_system_info',
        description: 'Get information about the Craft CMS installation including version, PHP version, and database info',
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM)]
    public function getSystemInfo(): array {
        $info = Craft::$app->getInfo();
        $db = Craft::$app->getDb();

        return [
            'craft' => [
                'version' => Craft::$app->getVersion(),
                'edition' => Craft::$app->getEditionName(),
                'schemaVersion' => $info->schemaVersion ?? null,
                'environment' => Craft::$app->env ?? 'production',
                'devMode' => Craft::$app->getConfig()->getGeneral()->devMode,
            ],
            'php' => [
                'version' => PHP_VERSION,
            ],
            'database' => [
                'driver' => $db->getDriverName(),
                'version' => $db->getServerVersion(),
            ],
            'sites' => array_map(
                fn ($site) => [
                    'id' => $site->id,
                    'handle' => $site->handle,
                    'name' => $site->name,
                    'primary' => $site->primary,
                    'baseUrl' => $site->getBaseUrl(),
                ],
                Craft::$app->getSites()->getAllSites(),
            ),
        ];
    }

    /**
     * List all custom fields in the Craft installation.
     */
    #[McpTool(
        name: 'list_fields',
        description: 'List all custom fields in Craft CMS with their type and group',
    )]
    #[McpToolMeta(category: ToolCategory::SCHEMA)]
    public function listFields(): array {
        $fieldsService = Craft::$app->getFields();
        $allFields = $fieldsService->getAllFields();

        $fields = [];
        foreach ($allFields as $field) {
            $group = $field->getGroup();
            $fields[] = [
                'id' => $field->id,
                'handle' => $field->handle,
                'name' => $field->name,
                'type' => $field::class,
                'instructions' => $field->instructions,
                'required' => $field->required,
                'groupId' => $field->groupId,
                'groupName' => $group?->name,
            ];
        }

        return [
            'count' => count($fields),
            'fields' => $fields,
        ];
    }
}
