<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\base\FieldInterface;
use craft\models\Section;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\services\SchemaHelper;
use stimmt\craft\Mcp\support\SafeExecution;

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
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::SCHEMA)]
    public function listPlugins(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
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
        });
    }

    /**
     * List all sections (channels, structures, singles) in Craft.
     */
    #[McpTool(
        name: 'list_sections',
        description: 'List all sections (channels, structures, singles) in Craft CMS with their entry types. Optionally filter by handle/name search term.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::SCHEMA)]
    public function listSections(?string $search = null, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($search): array {
            $sectionsService = Craft::$app->getEntries();
            $allSections = $sectionsService->getAllSections();

            if ($search !== null) {
                $allSections = array_values(array_filter(
                    $allSections,
                    fn (Section $section): bool => stripos($section->handle ?? '', $search) !== false
                        || stripos($section->name ?? '', $search) !== false,
                ));
            }

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
        });
    }

    /**
     * Get information about the Craft CMS installation.
     */
    #[McpTool(
        name: 'get_system_info',
        description: 'Get information about the Craft CMS installation including version, PHP version, and database info',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM)]
    public function getSystemInfo(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
            $info = Craft::$app->getInfo();
            $db = Craft::$app->getDb();

            return [
                'craft' => [
                    'version' => Craft::$app->getVersion(),
                    'edition' => Craft::$app->getEditionName(),
                    'schemaVersion' => $info->schemaVersion,
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
        });
    }

    /**
     * List all custom fields in the Craft installation.
     */
    #[McpTool(
        name: 'list_fields',
        description: 'List all custom fields in Craft CMS with their type and group. Optionally filter by handle/name search term or field type.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::SCHEMA)]
    public function listFields(?string $search = null, ?string $type = null, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($search, $type): array {
            $fieldsService = Craft::$app->getFields();
            $allFields = $fieldsService->getAllFields();

            if ($search !== null) {
                $allFields = array_filter(
                    $allFields,
                    fn (FieldInterface $field): bool => stripos($field->handle ?? '', $search) !== false
                        || stripos($field->name ?? '', $search) !== false,
                );
            }

            if ($type !== null) {
                $allFields = array_filter(
                    $allFields,
                    fn (FieldInterface $field): bool => stripos(SchemaHelper::getFieldTypeName($field), $type) !== false,
                );
            }

            $fields = [];
            foreach ($allFields as $field) {
                $fields[] = [
                    'id' => $field->id,
                    'handle' => $field->handle,
                    'name' => $field->name,
                    'type' => $field::class,
                    'instructions' => $field->instructions,
                    'searchable' => $field->searchable,
                ];
            }

            return [
                'count' => count($fields),
                'fields' => $fields,
            ];
        });
    }
}
