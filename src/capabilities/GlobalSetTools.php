<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\capabilities;

use Craft;
use craft\elements\GlobalSet;
use craft\mcp\attributes\RequiresAdmin;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\elements\LayoutFields;
use stimmt\craft\Mcp\elements\Reader;
use stimmt\craft\Mcp\support\ElementModule;
use stimmt\craft\Mcp\support\Response;

/**
 * Global set MCP tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class GlobalSetTools {
    private readonly Reader $reader;

    public function __construct(?Reader $reader = null) {
        $this->reader = $reader ?? ElementModule::reader();
    }

    /**
     * List all global sets.
     */
    #[McpTool(
        name: 'stimmt_globals_list',
        title: 'Global set values',
        description: 'List all global sets in Craft CMS with their field values',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[RequiresAdmin]
    public function listGlobals(?RequestContext $context = null): array {
        $globalSets = Craft::$app->getGlobals()->getAllSets();
        $results = array_map($this->serializeGlobalSet(...), $globalSets);

        return Response::list('globals', $results);
    }

    /**
     * Serialize a global set to array.
     *
     * Field values go through the same reader every other content tool uses,
     * so they arrive in the documented payload format: natural keys for
     * relations, and [] for an empty one. Serializing the live value straight
     * off the element handed back the internal query object instead, class
     * name and all, which is neither the format nor anything a caller can use.
     */
    private function serializeGlobalSet(GlobalSet $globalSet): array {
        $handles = array_keys(LayoutFields::of($globalSet->getFieldLayout()));

        return [
            'id' => $globalSet->id,
            'handle' => $globalSet->handle,
            'name' => $globalSet->name,
            'fields' => $this->reader->readFields($globalSet, $handles),
        ];
    }
}
