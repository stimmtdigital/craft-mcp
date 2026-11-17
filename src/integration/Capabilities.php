<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integration;

use Closure;
use stimmt\craft\Mcp\capabilities\ActivityTools;
use stimmt\craft\Mcp\capabilities\AuditTools;
use stimmt\craft\Mcp\capabilities\BackupTools;
use stimmt\craft\Mcp\capabilities\CategoryTools;
use stimmt\craft\Mcp\capabilities\CommerceTools;
use stimmt\craft\Mcp\capabilities\DatabaseTools;
use stimmt\craft\Mcp\capabilities\DebugTools;
use stimmt\craft\Mcp\capabilities\DependencyTools;
use stimmt\craft\Mcp\capabilities\EntryTools;
use stimmt\craft\Mcp\capabilities\EntryWorkflowTools;
use stimmt\craft\Mcp\capabilities\FormieTools;
use stimmt\craft\Mcp\capabilities\GlobalSetTools;
use stimmt\craft\Mcp\capabilities\GraphqlTools;
use stimmt\craft\Mcp\capabilities\GuideResources;
use stimmt\craft\Mcp\capabilities\NestedEntryTools;
use stimmt\craft\Mcp\capabilities\OperationsTools;
use stimmt\craft\Mcp\capabilities\ProfilingTools;
use stimmt\craft\Mcp\capabilities\ReportTools;
use stimmt\craft\Mcp\capabilities\RetourTools;
use stimmt\craft\Mcp\capabilities\ReviewTools;
use stimmt\craft\Mcp\capabilities\SeomaticTools;
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
        ActivityTools::class,
        AuditTools::class,
        BackupTools::class,
        CategoryTools::class,
        DatabaseTools::class,
        DebugTools::class,
        DependencyTools::class,
        EntryTools::class,
        EntryWorkflowTools::class,
        GlobalSetTools::class,
        GraphqlTools::class,
        GuideResources::class,
        NestedEntryTools::class,
        OperationsTools::class,
        ProfilingTools::class,
        ReportTools::class,
        ReviewTools::class,
        SystemTools::class,
        TinkerTools::class,
    ];

    /**
     * Capability classes for optional plugins, each registered only when its
     * own isAvailable() says the plugin is installed, enabled and on a
     * version the class was written against.
     *
     * @var list<class-string>
     */
    public const array OPTIONAL = [
        CommerceTools::class,
        FormieTools::class,
        RetourTools::class,
        SeomaticTools::class,
    ];

    /**
     * @param (Closure(class-string): bool)|null $isAvailable defaults to each class's own isAvailable()
     * @return list<class-string>
     */
    public static function classes(?Closure $isAvailable = null): array {
        $isAvailable ??= static fn (string $class): bool => $class::isAvailable();
        $optional = array_filter(self::OPTIONAL, $isAvailable);

        return [...self::CORE, ...array_values($optional)];
    }
}
