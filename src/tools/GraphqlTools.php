<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\models\GqlSchema;
use Mcp\Capability\Attribute\McpTool;
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
    public function listGraphqlSchemas(): array {
        try {
            $gql = Craft::$app->getGql();
            $schemas = $gql->getSchemas();

            $result = [];
            foreach ($schemas as $schema) {
                $result[] = $this->serializeSchema($schema);
            }

            // Also include the public schema if it exists
            $publicSchema = $gql->getPublicSchema();
            if ($publicSchema !== null) {
                $hasPublic = false;
                foreach ($result as $s) {
                    if ($s['id'] === $publicSchema->id) {
                        $hasPublic = true;
                        break;
                    }
                }
                if (!$hasPublic) {
                    array_unshift($result, [
                        ...$this->serializeSchema($publicSchema),
                        'isPublic' => true,
                    ]);
                }
            }

            return [
                'count' => count($result),
                'schemas' => $result,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get a specific GraphQL schema by ID or handle.
     */
    #[McpTool(
        name: 'get_graphql_schema',
        description: 'Get detailed information about a specific GraphQL schema including its SDL (Schema Definition Language)',
    )]
    public function getGraphqlSchema(?int $id = null, ?string $uid = null): array {
        if ($id === null && $uid === null) {
            return [
                'success' => false,
                'error' => 'Either id or uid must be provided',
            ];
        }

        try {
            $gql = Craft::$app->getGql();

            $schema = $id !== null
                ? $gql->getSchemaById($id)
                : $gql->getSchemaByUid($uid);

            if ($schema === null) {
                return [
                    'success' => false,
                    'error' => $id !== null
                        ? "Schema with ID {$id} not found"
                        : "Schema with UID '{$uid}' not found",
                ];
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
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Execute a GraphQL query.
     */
    #[McpTool(
        name: 'execute_graphql',
        description: 'Execute a GraphQL query against Craft CMS. WARNING: This is a dangerous operation that can modify data via mutations.',
    )]
    public function executeGraphql(
        string $query,
        ?string $variables = null,
        ?string $operationName = null,
        ?int $schemaId = null,
    ): array {
        try {
            $gql = Craft::$app->getGql();

            // Get the schema to use
            $schema = $schemaId !== null
                ? $gql->getSchemaById($schemaId)
                : $gql->getPublicSchema();

            if ($schema === null) {
                return [
                    'success' => false,
                    'error' => $schemaId !== null
                        ? "Schema with ID {$schemaId} not found"
                        : 'No public schema available. Provide a schemaId.',
                ];
            }

            // Parse variables if provided
            $parsedVariables = null;
            if ($variables !== null) {
                $parsedVariables = json_decode($variables, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return [
                        'success' => false,
                        'error' => 'Invalid JSON in variables: ' . json_last_error_msg(),
                    ];
                }
            }

            // Execute the query
            $result = $gql->executeQuery(
                $schema,
                $query,
                $parsedVariables,
                $operationName,
            );

            return [
                'success' => true,
                'data' => $result['data'] ?? null,
                'errors' => $result['errors'] ?? null,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * List available GraphQL tokens.
     */
    #[McpTool(
        name: 'list_graphql_tokens',
        description: 'List all GraphQL tokens (API keys) with their associated schemas',
    )]
    public function listGraphqlTokens(): array {
        try {
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
                    'dateLastUsed' => $token->dateLastUsed?->format('Y-m-d H:i:s'),
                    // Note: We don't expose the actual access token for security
                ];
            }

            return [
                'count' => count($result),
                'tokens' => $result,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
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
        if (empty($scope)) {
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
