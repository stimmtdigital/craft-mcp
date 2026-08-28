<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\models\GqlSchema;
use GraphQL\Error\SyntaxError;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InputObjectField;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Schema as SchemaDefinition;
use GraphQL\Utils\SchemaPrinter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;

/**
 * GraphQL tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class GraphqlTools {
    /**
     * The most SDL one call hands back.
     *
     * Not the response budget, which is a megabyte and would pass all of this:
     * the stdio transport blocks on a write larger than the pipe buffer, so a
     * payload well inside that budget can still hang the client that asked for
     * it. Nor is a limit an edge case for a Craft schema. A stock install
     * prints past half a megabyte of SDL in total, and its biggest single
     * types print at around a hundred kilobytes each, so handing back a PART
     * of the schema is the tool's normal job rather than its failure mode. The
     * limit leaves room for JSON escaping on top, which costs a second byte
     * for every newline in a newline-dense string.
     */
    private const int MAX_SDL_BYTES = 32768;

    /**
     * List all GraphQL schemas.
     */
    #[McpTool(
        name: 'list_graphql_schemas',
        title: 'GraphQL schemas',
        description: 'List all GraphQL schemas in Craft CMS with their scopes and permissions',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::GRAPHQL)]
    public function listGraphqlSchemas(?RequestContext $context = null): array {
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
    }

    /**
     * Get a specific GraphQL schema by ID or handle.
     */
    #[McpTool(
        name: 'get_graphql_schema',
        title: 'GraphQL schema definition',
        description: 'Read a GraphQL schema: the scope it grants, and the types it exposes. Returns an index of every type by default (name, kind, field count, SDL size); pass type for that one type\'s SDL, and type together with field for a single field and its arguments. A Craft schema prints hundreds of kilobytes of SDL, more than one response can carry, so it is never returned whole.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::GRAPHQL)]
    public function getGraphqlSchema(
        #[Schema(description: 'Schema id, as list_graphql_schemas reports it.')]
        ?int $id = null,
        #[Schema(description: 'Schema uid, as an alternative to id.')]
        ?string $uid = null,
        #[Schema(description: 'A type name from the index this tool returns without it, such as "Query" or "EntryInterface". Returns that type\'s SDL in place of the index. The index gives every type\'s sdlBytes, so you can see beforehand which types are too large to come back whole.')]
        ?string $type = null,
        #[Schema(description: 'One field of that type, such as "entries" on "Query". Returns the SDL for that field alone, its arguments included. This is how to read a type whose own SDL is over the size limit.')]
        ?string $field = null,
        ?RequestContext $context = null,
    ): array {
        if ($field !== null && $type === null) {
            throw new ToolCallException(
                'field narrows a type, so it needs type as well. Call with neither to see which types this schema has.',
            );
        }

        $schema = $this->schemaFor($id, $uid);

        // Deliberately uncaught. A bare catch here used to report success with
        // a null SDL, which is how the actual failure stayed invisible for the
        // life of this tool: getSchemaDef() returns a Schema object, and the
        // cast to string that used to follow threw on every call. ErrorBoundary
        // renders whatever a schema that will not build throws as a failed
        // call, which is what it is.
        $definition = Craft::$app->getGql()->getSchemaDef($schema);

        if ($type === null) {
            return $this->schemaIndex($schema, $definition);
        }

        return [
            'success' => true,
            // The schema's identity only: its scope runs to kilobytes of uids
            // on a real install, which would dwarf the SDL asked for here.
            'schema' => ['id' => $schema->id, 'uid' => $schema->uid, 'name' => $schema->name],
            'type' => $this->sdlFor($definition, $type, $field),
        ];
    }

    /**
     * The schema named by id or uid, or a refusal naming what was not found.
     *
     * Reads Craft only after the arguments hold up, so a caller that named
     * neither is told so rather than being handed whatever the lookup does
     * with nothing.
     */
    private function schemaFor(?int $id, ?string $uid): GqlSchema {
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

        return $schema;
    }

    /**
     * What the schema holds, without the SDL itself: the scope it grants, the
     * root operation types a query starts from, and every type in it.
     *
     * @return array<string, mixed>
     */
    private function schemaIndex(GqlSchema $schema, SchemaDefinition $definition): array {
        $types = $this->typeIndex($definition);

        return [
            'success' => true,
            'schema' => $this->serializeSchema($schema),
            // The size of the SDL this response is NOT carrying, so the reason
            // one type at a time is the only way through is a number rather
            // than a claim.
            'sdlBytes' => strlen(SchemaPrinter::doPrint($definition)),
            'roots' => [
                'query' => $definition->getQueryType()?->name,
                'mutation' => $definition->getMutationType()?->name,
                'subscription' => $definition->getSubscriptionType()?->name,
            ],
            'count' => count($types),
            'types' => $types,
        ];
    }

    /**
     * Every type the schema exposes, with the size of the SDL each would
     * print. The sizes are the point: they are how a caller knows which types
     * it can ask for whole and which it has to read a field at a time.
     *
     * @return list<array<string, mixed>>
     */
    private function typeIndex(SchemaDefinition $definition): array {
        $index = [];

        foreach ($definition->getTypeMap() as $type) {
            // The introspection types and the standard scalars are identical
            // in every GraphQL schema, and SchemaPrinter leaves them out of
            // the full SDL too, so the index mirrors what the SDL would show.
            if (Type::isBuiltInType($type)) {
                continue;
            }

            $fields = $this->fieldsOf($type);

            $index[$type->name] = [
                'name' => $type->name,
                'kind' => $this->kindOf($type),
                ...($fields === null ? [] : ['fields' => count($fields)]),
                'sdlBytes' => strlen(SchemaPrinter::printType($type)),
            ];
        }

        ksort($index);

        return array_values($index);
    }

    /**
     * The SDL for one type, or for one field of it.
     *
     * @return array<string, mixed>
     */
    private function sdlFor(SchemaDefinition $definition, string $name, ?string $field): array {
        $types = $definition->getTypeMap();

        // Looked up in the map rather than through getType(): Craft's type
        // loader throws on a name it does not know instead of returning null,
        // and a typo deserves an answer that says what to call instead.
        if (!isset($types[$name])) {
            throw new ToolCallException(
                "No type '{$name}' in this schema. Call this tool without type to see the ones it has.",
            );
        }

        $type = $types[$name];
        $sdl = SchemaPrinter::printType($field === null ? $type : $this->narrow($type, $field));
        $bytes = strlen($sdl);

        if ($bytes > self::MAX_SDL_BYTES) {
            throw new ToolCallException($this->oversized($type, $field, $bytes));
        }

        return [
            'name' => $name,
            'kind' => $this->kindOf($type),
            ...($field === null ? [] : ['field' => $field]),
            'sdlBytes' => $bytes,
            'sdl' => $sdl,
        ];
    }

    /**
     * A copy of a type carrying one field, which is the only way to print a
     * field on its own: SchemaPrinter has no per-field entry point, and
     * writing one would mean a second SDL formatter, with its own rules for
     * arguments and default values, drifting away from the library's.
     */
    private function narrow(Type $type, string $field): Type {
        $fields = $this->fieldsOf($type);

        if ($fields === null) {
            throw new ToolCallException("Type '{$type->name}' has no fields, so field does not apply to it.");
        }

        if (!isset($fields[$field])) {
            throw new ToolCallException(
                "No field '{$field}' on type '{$type->name}'. Its fields: " . implode(', ', array_keys($fields)) . '.',
            );
        }

        $config = ['name' => $type->name, 'description' => $type->description];
        $definition = $fields[$field];

        return match (true) {
            // An input field cannot be handed back as an object: the
            // constructor rebuilds every one of them from an array, so its own
            // config array is what goes back in.
            $type instanceof InputObjectType => new InputObjectType([...$config, 'fields' => [$field => $definition->config]]),
            $type instanceof InterfaceType => new InterfaceType([...$config, 'fields' => [$definition]]),
            default => new ObjectType([...$config, 'fields' => [$definition]]),
        };
    }

    /**
     * The refusal for SDL that will not fit, written so the next call is
     * obvious: for a type, the field names ARE the vocabulary the caller needs
     * to narrow it.
     */
    private function oversized(Type $type, ?string $field, int $bytes): string {
        if ($field !== null) {
            return sprintf(
                "Field '%s' on type '%s' prints as %d bytes of SDL and the limit for one call is %d, so it was not sent. "
                . 'There is nothing narrower to ask for here; read its argument names without their descriptions through '
                . 'query_graphql, with an introspection query such as { __type(name: "%s") { fields { name args { name } } } }.',
                $field,
                $type->name,
                $bytes,
                self::MAX_SDL_BYTES,
                $type->name,
            );
        }

        $fields = $this->fieldsOf($type);
        $narrowing = $fields === null
            ? 'It has no fields, so there is nothing narrower to ask for here; read it through query_graphql with an introspection query instead.'
            : 'Ask for one field of it at a time by passing field. Its fields: ' . implode(', ', array_keys($fields)) . '.';

        return sprintf(
            "Type '%s' prints as %d bytes of SDL and the limit for one call is %d, so it was not sent. %s",
            $type->name,
            $bytes,
            self::MAX_SDL_BYTES,
            $narrowing,
        );
    }

    /**
     * The SDL keyword a type prints under, which is what tells a caller
     * whether it can select on it, pass it as an argument, or neither.
     */
    private function kindOf(Type $type): string {
        return match (true) {
            $type instanceof InputObjectType => 'input',
            $type instanceof InterfaceType => 'interface',
            $type instanceof UnionType => 'union',
            $type instanceof EnumType => 'enum',
            $type instanceof ObjectType => 'object',
            default => 'scalar',
        };
    }

    /**
     * The fields of a type, or null for a kind that has none. A union's
     * members and an enum's values are not fields, and neither can be narrowed
     * to one of them.
     *
     * @return array<string, FieldDefinition|InputObjectField>|null
     */
    private function fieldsOf(Type $type): ?array {
        return $type instanceof ObjectType || $type instanceof InterfaceType || $type instanceof InputObjectType
            ? $type->getFields()
            : null;
    }

    /**
     * Run a read-only GraphQL query.
     */
    #[McpTool(
        name: 'query_graphql',
        title: 'Run a GraphQL query',
        description: 'Run a read-only GraphQL query against Craft\'s GraphQL API. Mutations and subscriptions are rejected before execution, so this is safe for browsing any GraphQL-exposed data (assets, categories, users, plugin types) with exactly the response shape you ask for. Use get_graphql_schema to discover the available types first.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::GRAPHQL, privileged: true)]
    public function queryGraphql(
        #[Schema(description: 'The GraphQL document. Only query operations are accepted; a mutation or subscription is rejected at the AST level, before execution.')]
        string $query,
        #[Schema(description: 'Query variables as a JSON-encoded STRING (not a nested object).')]
        ?string $variables = null,
        #[Schema(description: 'Which operation to run, when the document defines more than one.')]
        ?string $operationName = null,
        #[Schema(description: 'Schema id from list_graphql_schemas, which decides what the query may reach. Omit to use the public schema.')]
        ?int $schemaId = null,
        ?RequestContext $context = null,
    ): array {
        $this->assertReadOnly($query);

        return $this->execute($query, $variables, $operationName, $schemaId, $context);
    }

    /**
     * Execute a GraphQL query.
     */
    #[McpTool(
        name: 'execute_graphql',
        title: 'Execute GraphQL, mutations included',
        description: 'Execute a GraphQL query against Craft CMS. WARNING: This is a dangerous operation that can modify data via mutations.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::GRAPHQL, dangerous: true)]
    public function executeGraphql(
        #[Schema(description: 'The GraphQL document. Mutations run here, so this can create, change, and delete data.')]
        string $query,
        #[Schema(description: 'Query variables as a JSON-encoded STRING (not a nested object).')]
        ?string $variables = null,
        #[Schema(description: 'Which operation to run, when the document defines more than one.')]
        ?string $operationName = null,
        #[Schema(description: 'Schema id from list_graphql_schemas, which decides what the operation may reach. Omit to use the public schema.')]
        ?int $schemaId = null,
        ?RequestContext $context = null,
    ): array {
        return $this->execute($query, $variables, $operationName, $schemaId, $context);
    }

    /**
     * Mutations are rejected at the AST level, before any execution: this is
     * what lets query_graphql stay out of the dangerous-tools gate.
     */
    private function assertReadOnly(string $query): void {
        try {
            $document = Parser::parse($query);
        } catch (SyntaxError $e) {
            throw new ToolCallException('GraphQL syntax error: ' . $e->getMessage(), $e->getCode(), $e);
        }

        foreach ($document->definitions as $definition) {
            if ($definition instanceof OperationDefinitionNode && $definition->operation !== 'query') {
                throw new ToolCallException(
                    "Only query operations are allowed here; '{$definition->operation}' requires execute_graphql (dangerous tools)",
                );
            }
        }
    }

    /**
     * @return array{success: bool, data: mixed, errors: mixed}
     */
    private function execute(string $query, ?string $variables, ?string $operationName, ?int $schemaId, ?RequestContext $context): array {
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
    }

    /**
     * List available GraphQL tokens.
     */
    #[McpTool(
        name: 'list_graphql_tokens',
        title: 'GraphQL tokens',
        description: 'List all GraphQL tokens (API keys) with their associated schemas',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::GRAPHQL, privileged: true)]
    public function listGraphqlTokens(?RequestContext $context = null): array {
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
            'isPublic' => $schema->isPublic,
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
