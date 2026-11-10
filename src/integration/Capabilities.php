<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integration;

use Craft;
use stimmt\craft\Mcp\capabilities\BackupTools;
use stimmt\craft\Mcp\capabilities\CategoryTools;
use stimmt\craft\Mcp\capabilities\CommerceTools;
use stimmt\craft\Mcp\capabilities\DatabaseTools;
use stimmt\craft\Mcp\capabilities\DebugTools;
use stimmt\craft\Mcp\capabilities\EntryTools;
use stimmt\craft\Mcp\capabilities\EntryWorkflowTools;
use stimmt\craft\Mcp\capabilities\GlobalSetTools;
use stimmt\craft\Mcp\capabilities\GraphqlTools;
use stimmt\craft\Mcp\capabilities\GuideResources;
use stimmt\craft\Mcp\capabilities\NestedEntryTools;
use stimmt\craft\Mcp\capabilities\SystemTools;
use stimmt\craft\Mcp\capabilities\TinkerTools;

/**
 * The capability classes the plugin adds to Craft's MCP server.
 *
 * Registered on `craft\services\Mcp::EVENT_REGISTER_CAPABILITY_TYPES`. Every
 * class is registered whatever the edition and settings: which of their tools
 * a client sees is the Gate's decision, made per request.
 */
final class Capabilities {
    /** @var list<class-string> */
    public const array CORE = [
        BackupTools::class,
        CategoryTools::class,
        DatabaseTools::class,
        DebugTools::class,
        EntryTools::class,
        EntryWorkflowTools::class,
        GlobalSetTools::class,
        GraphqlTools::class,
        GuideResources::class,
        NestedEntryTools::class,
        SystemTools::class,
        TinkerTools::class,
    ];

    /**
     * @return list<class-string>
     */
    public static function classes(): array {
        $classes = self::CORE;
        if (Craft::$app->getPlugins()->isPluginEnabled('commerce')) {
            $classes[] = CommerceTools::class;
        }

        return $classes;
    }
}
