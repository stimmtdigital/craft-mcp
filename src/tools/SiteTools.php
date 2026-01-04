<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\SafeExecution;

/**
 * Multi-site management tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class SiteTools {
    /**
     * List all sites in Craft CMS.
     */
    #[McpTool(
        name: 'list_sites',
        description: 'List all sites in Craft CMS with their handles, languages, and configuration',
    )]
    #[McpToolMeta(category: ToolCategory::MULTISITE)]
    public function listSites(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
            $sites = Craft::$app->getSites()->getAllSites();

            $result = [];
            foreach ($sites as $site) {
                $result[] = [
                    'id' => $site->id,
                    'uid' => $site->uid,
                    'handle' => $site->handle,
                    'name' => $site->getName(),
                    'language' => $site->language,
                    'primary' => $site->primary,
                    'enabled' => $site->enabled,
                    'baseUrl' => $site->getBaseUrl(),
                    'groupId' => $site->groupId,
                    'sortOrder' => $site->sortOrder,
                    'dateCreated' => $site->dateCreated?->format('Y-m-d H:i:s'),
                    'dateUpdated' => $site->dateUpdated?->format('Y-m-d H:i:s'),
                ];
            }

            return [
                'count' => count($result),
                'sites' => $result,
            ];
        });
    }

    /**
     * Get detailed information about a specific site.
     */
    #[McpTool(
        name: 'get_site',
        description: 'Get detailed information about a specific site by ID or handle',
    )]
    #[McpToolMeta(category: ToolCategory::MULTISITE)]
    public function getSite(?int $id = null, ?string $handle = null, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($id, $handle): array {
            if ($id === null && $handle === null) {
                throw new ToolCallException('Either id or handle must be provided');
            }

            $sitesService = Craft::$app->getSites();

            $site = $id !== null
                ? $sitesService->getSiteById($id)
                : $sitesService->getSiteByHandle($handle);

            if ($site === null) {
                $identifier = $id !== null ? "ID {$id}" : "handle '{$handle}'";

                throw new ToolCallException("Site with {$identifier} not found");
            }

            $group = $sitesService->getGroupById($site->groupId);

            return [
                'success' => true,
                'site' => [
                    'id' => $site->id,
                    'uid' => $site->uid,
                    'handle' => $site->handle,
                    'name' => $site->getName(),
                    'language' => $site->language,
                    'primary' => $site->primary,
                    'enabled' => $site->enabled,
                    'baseUrl' => $site->getBaseUrl(),
                    'sortOrder' => $site->sortOrder,
                    'group' => $group ? [
                        'id' => $group->id,
                        'name' => $group->getName(),
                    ] : null,
                    'dateCreated' => $site->dateCreated?->format('Y-m-d H:i:s'),
                    'dateUpdated' => $site->dateUpdated?->format('Y-m-d H:i:s'),
                ],
            ];
        });
    }

    /**
     * List all site groups.
     */
    #[McpTool(
        name: 'list_site_groups',
        description: 'List all site groups in Craft CMS',
    )]
    #[McpToolMeta(category: ToolCategory::MULTISITE)]
    public function listSiteGroups(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
            $groups = Craft::$app->getSites()->getAllGroups();

            $result = [];
            foreach ($groups as $group) {
                $sites = Craft::$app->getSites()->getSitesByGroupId($group->id);
                $siteHandles = array_map(fn ($site) => $site->handle, $sites);

                $result[] = [
                    'id' => $group->id,
                    'uid' => $group->uid,
                    'name' => $group->getName(),
                    'siteCount' => count($sites),
                    'siteHandles' => $siteHandles,
                ];
            }

            return [
                'count' => count($result),
                'groups' => $result,
            ];
        });
    }
}
