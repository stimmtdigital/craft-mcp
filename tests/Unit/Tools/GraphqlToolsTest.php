<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\tools\GraphqlTools;

describe('GraphqlTools class structure', function () {
    it('has list_graphql_schemas tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(GraphqlTools::class, 'listGraphqlSchemas');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('list_graphql_schemas')
            ->and($instance->description)->toContain('GraphQL schemas');
    });

    it('has get_graphql_schema tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(GraphqlTools::class, 'getGraphqlSchema');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('get_graphql_schema')
            ->and($instance->description)->toContain('SDL');
    });

    it('has execute_graphql tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(GraphqlTools::class, 'executeGraphql');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('execute_graphql')
            ->and($instance->description)->toContain('dangerous');
    });

    it('has list_graphql_tokens tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(GraphqlTools::class, 'listGraphqlTokens');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('list_graphql_tokens')
            ->and($instance->description)->toContain('tokens');
    });
});

describe('GraphqlTools method signatures', function () {
    it('executeGraphql requires query parameter', function () {
        $reflection = new ReflectionMethod(GraphqlTools::class, 'executeGraphql');
        $parameters = $reflection->getParameters();

        // First parameter should be query (required)
        expect($parameters[0]->getName())->toBe('query')
            ->and($parameters[0]->isOptional())->toBeFalse();
    });

    it('executeGraphql has optional variables, operationName, schemaId and context parameters', function () {
        $reflection = new ReflectionMethod(GraphqlTools::class, 'executeGraphql');
        $parameters = $reflection->getParameters();

        expect($parameters)->toHaveCount(5);

        // variables, operationName, schemaId should all be optional
        expect($parameters[1]->getName())->toBe('variables')
            ->and($parameters[1]->isOptional())->toBeTrue();

        expect($parameters[2]->getName())->toBe('operationName')
            ->and($parameters[2]->isOptional())->toBeTrue();

        expect($parameters[3]->getName())->toBe('schemaId')
            ->and($parameters[3]->isOptional())->toBeTrue();
    });

    it('all methods return array', function () {
        $methods = ['listGraphqlSchemas', 'getGraphqlSchema', 'executeGraphql', 'listGraphqlTokens'];

        foreach ($methods as $methodName) {
            $reflection = new ReflectionMethod(GraphqlTools::class, $methodName);
            expect($reflection->getReturnType()?->getName())->toBe('array');
        }
    });
});

describe('GraphqlTools dangerous tool', function () {
    it('execute_graphql is marked as dangerous', function () {
        expect(Mcp::DANGEROUS_TOOLS)->toContain('execute_graphql');
    });
});

describe('GraphqlTools tool count', function () {
    it('has exactly 5 public methods with McpTool attribute', function () {
        $reflection = new ReflectionClass(GraphqlTools::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        $toolMethods = array_filter($methods, function ($method) {
            return !empty($method->getAttributes(McpTool::class));
        });

        expect($toolMethods)->toHaveCount(5);
    });
});

use stimmt\craft\Mcp\attributes\McpToolMeta;

describe('query_graphql', function () {
    it('is registered read-only and not dangerous', function () {
        $method = new ReflectionMethod(GraphqlTools::class, 'queryGraphql');
        $tool = $method->getAttributes(McpTool::class)[0]->newInstance();
        $meta = $method->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($tool->name)->toBe('query_graphql')
            ->and($tool->annotations->readOnlyHint)->toBeTrue()
            ->and($meta->dangerous)->toBeFalse();
    });

    it('rejects non-query operations by parsing the document', function () {
        $source = (string) file_get_contents((new ReflectionClass(GraphqlTools::class))->getFileName());

        expect($source)->toContain('Parser::parse(')
            ->and($source)->toContain('OperationDefinitionNode');
    });
});

// Behavioral coverage for the security mechanism itself: assertReadOnly only
// touches the GraphQL parser (pure PHP, no Craft boot), so it can run here.
describe('query_graphql read-only guard', function () {
    $guard = function (string $query): void {
        $tools = (new ReflectionClass(GraphqlTools::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod(GraphqlTools::class, 'assertReadOnly'))->invoke($tools, $query);
    };

    it('rejects mutations before execution', function () use ($guard) {
        $guard('mutation { deleteEntry(id: 1) }');
    })->throws(\Mcp\Exception\ToolCallException::class, 'execute_graphql');

    it('rejects subscriptions before execution', function () use ($guard) {
        $guard('subscription { entryUpdated { id } }');
    })->throws(\Mcp\Exception\ToolCallException::class);

    it('rejects syntax errors with the parser message', function () use ($guard) {
        $guard('query {');
    })->throws(\Mcp\Exception\ToolCallException::class, 'GraphQL syntax error');

    it('allows named, anonymous, and fragment-bearing queries', function (string $query) use ($guard) {
        $guard($query);

        expect(true)->toBeTrue();
    })->with([
        ['query Entries { entries { id } }'],
        ['{ entries { id } }'],
        ['query Entries { entries { ...ids } } fragment ids on EntryInterface { id }'],
    ]);
});
