<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\Serializer;

/**
 * Global set MCP tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class GlobalSetTools {
    /**
     * List all global sets.
     */
    #[McpTool(
        name: 'list_globals',
        description: 'List all global sets in Craft CMS with their field values',
    )]
    public function listGlobals(): array {
        $globalSets = Craft::$app->getGlobals()->getAllSets();
        $results = array_map($this->serializeGlobalSet(...), $globalSets);

        return Response::list('globals', $results);
    }

    /**
     * Serialize a global set to array.
     */
    private function serializeGlobalSet(mixed $globalSet): array {
        $fieldValues = [];
        $fieldLayout = $globalSet->getFieldLayout();

        if ($fieldLayout !== null) {
            foreach ($fieldLayout->getCustomFields() as $field) {
                $fieldValues[$field->handle] = Serializer::serialize(
                    $globalSet->getFieldValue($field->handle),
                );
            }
        }

        return [
            'id' => $globalSet->id,
            'handle' => $globalSet->handle,
            'name' => $globalSet->name,
            'fields' => $fieldValues,
        ];
    }
}
