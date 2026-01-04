<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\models\GqlSchema;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\SafeExecution;
use Throwable;

/**
 * GraphQL tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class GraphqlTools {
    /**
     * List all GraphQL schemas.
     */
    #[McpTool(
        name: 'list_graphql_schemas',
        description: 'List all GraphQL schemas in Craft CMS with their scopes and permissions',
    )]
    #[McpToolMeta(category: ToolCategory::GRAPHQL)]
    public function listGraphqlSchemas(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
            $gql = Craft::$app->getGql();
            $schemas = $gql->getSchemas();

            $result = array_map(
                $this->serializeSchema(...),
                $schemas,
            );

            // Also include the public schema if it exists
            $publicSchema = $gql->getPublicSchema();
            if ($publicSchema !== null && !$this->hasSchemaId($result, $publicSchema->id)) {
                array_unshift($result, [
                    ...$this->serializeSchema($publicSchema),
                    'isPublic' => true,
                ]);
            }

            return [
                'count' => count($result),
                'schemas' => $result,
            ];
        });
    }

    /**
     * Get a specific GraphQL schema by ID or handle.
     */
    #[McpTool(
        name: 'get_graphql_schema',
        description: 'Get detailed information about a specific GraphQL schema including its SDL (Schema Definition Language)',
    )]
    #[McpToolMeta(category: ToolCategory::GRAPHQL)]
    public function getGraphqlSchema(?int $id = null, ?string $uid = null, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($id, $uid): array {
            if ($id === null && $uid === null) {
                throw new ToolCallException('Either id or uid must be provided');
            }

            $gql = Craft::$app->getGql();

            $schema = $id !== null
                ? $gql->getSchemaById($id)
                : $gql->getSchemaByUid($uid);

            if ($schema === null) {
                $identifier = $id !== null ? "ID {$id}" : "UID '{$uid}'";

                throw new ToolCallException("Schema with {$identifier} not found");
            }

            // Get the SDL for this schema
            $sdl = null;

            try {
                $schemaDef = $gql->getSchemaDef($schema);
                $sdl = $schemaDef !== null ? (string) $schemaDef : null;
            } catch (Throwable) {
                // SDL generation might fail for some schemas
            }

            return [
                'success' => true,
                'schema' => [
                    ...$this->serializeSchema($schema),
                    'sdl' => $sdl,
                    'sdlLength' => $sdl !== null ? strlen($sdl) : 0,
                ],
            ];
        });
    }

    /**
     * Execute a GraphQL query.
     */
    #[McpTool(
        name: 'execute_graphql',
        description: 'Execute a GraphQL query against Craft CMS. WARNING: This is a dangerous operation that can modify data via mutations.',
    )]
    #[McpToolMeta(category: ToolCategory::GRAPHQL, dangerous: true)]
    public function executeGraphql(
        string $query,
        ?string $variables = null,
        ?string $operationName = null,
        ?int $schemaId = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($query, $variables, $operationName, $schemaId, $context): array {
            $context?->getClientGateway()?->progress(0, 2, 'Executing GraphQL query...');

            $gql = Craft::$app->getGql();

            // Get the schema to use
            $schema = $schemaId !== null
                ? $gql->getSchemaById($schemaId)
                : $gql->getPublicSchema();

            if ($schema === null) {
                $error = $schemaId !== null
                    ? "Schema with ID {$schemaId} not found"
                    : 'No public schema available. Provide a schemaId.';

                throw new ToolCallException($error);
            }

            // Parse variables if provided
            $parsedVariables = $this->parseVariables($variables);
            if ($parsedVariables === false) {
                throw new ToolCallException('Invalid JSON in variables: ' . json_last_error_msg());
            }

            // Execute the query
            $result = $gql->executeQuery(
                $schema,
                $query,
                $parsedVariables,
                $operationName,
            );

            $context?->getClientGateway()?->progress(2, 2, 'Query complete');

            return [
                'success' => true,
                'data' => $result['data'] ?? null,
                'errors' => $result['errors'] ?? null,
            ];
        });
    }

    /**
     * List available GraphQL tokens.
     */
    #[McpTool(
        name: 'list_graphql_tokens',
        description: 'List all GraphQL tokens (API keys) with their associated schemas',
    )]
    #[McpToolMeta(category: ToolCategory::GRAPHQL)]
    public function listGraphqlTokens(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
            $gql = Craft::$app->getGql();
            $tokens = $gql->getTokens();

            $result = [];
            foreach ($tokens as $token) {
                // Get associated schema
                $schema = $token->getSchema();

                $result[] = [
                    'id' => $token->id,
                    'uid' => $token->uid,
                    'name' => $token->name,
                    'enabled' => $token->enabled,
                    'expiryDate' => $token->expiryDate?->format('Y-m-d H:i:s'),
                    'schema' => $schema ? [
                        'id' => $schema->id,
                        'name' => $schema->name,
                    ] : null,
                    'dateCreated' => $token->dateCreated?->format('Y-m-d H:i:s'),
                ];
            }

            return [
                'count' => count($result),
                'tokens' => $result,
            ];
        });
    }

    /**
     * Parse JSON variables string.
     *
     * @return array<string, mixed>|null|false Null if no variables, false on error, array otherwise
     */
    private function parseVariables(?string $variables): array|null|false {
        if ($variables === null) {
            return null;
        }

        $parsed = json_decode($variables, true);

        return json_last_error() === JSON_ERROR_NONE ? $parsed : false;
    }

    /**
     * Check if a schema ID exists in the results.
     *
     * @param array<array<string, mixed>> $schemas
     */
    private function hasSchemaId(array $schemas, ?int $id): bool {
        if ($id === null) {
            return false;
        }

        return array_any($schemas, fn (array $schema) => ($schema['id'] ?? null) === $id);
    }

    /**
     * Serialize a GraphQL schema to array.
     */
    private function serializeSchema(GqlSchema $schema): array {
        return [
            'id' => $schema->id,
            'uid' => $schema->uid,
            'name' => $schema->name,
            'scope' => $schema->scope,
            'permissions' => $this->parseScope($schema->scope),
            'isPublic' => $schema->isPublic ?? false,
        ];
    }

    /**
     * Parse scope array into a readable permissions structure.
     *
     * Transforms scope strings like "sections.news:read" into:
     * ['sections' => ['news' => ['read']]]
     *
     * @param array<string>|null $scope
     * @return array<string, array<string, array<string>>>
     */
    private function parseScope(?array $scope): array {
        if ($scope === null || $scope === []) {
            return [];
        }

        $permissions = [];

        foreach ($scope as $scopeItem) {
            $parsed = $this->parseScopeItem($scopeItem);
            if ($parsed === null) {
                continue;
            }

            [$type, $handle, $action] = $parsed;
            $permissions[$type][$handle][] = $action;
        }

        return $this->sortPermissions($permissions);
    }

    /**
     * Parse a single scope item into [type, handle, action] or null if invalid.
     *
     * @return array{string, string, string}|null
     */
    private function parseScopeItem(string $scopeItem): ?array {
        if (!str_contains($scopeItem, ':')) {
            return null;
        }

        [$resource, $action] = explode(':', $scopeItem, 2);
        [$type, $handle] = str_contains($resource, '.')
            ? explode('.', $resource, 2)
            : [$resource, '*'];

        return [$type, $handle, $action];
    }

    /**
     * Sort permissions array and deduplicate actions.
     *
     * @param array<string, array<string, array<string>>> $permissions
     * @return array<string, array<string, array<string>>>
     */
    private function sortPermissions(array $permissions): array {
        ksort($permissions);

        return array_map(function (array $handles): array {
            ksort($handles);

            return array_map(fn (array $actions) => array_values(array_unique($actions)), $handles);
        }, $permissions);
    }
}
