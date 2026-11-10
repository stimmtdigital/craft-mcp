<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\attributes\Dangerous;
use stimmt\craft\Mcp\capabilities\GraphqlTools;

describe('GraphqlTools class structure', function () {
    it('has stimmt_graphql_schemas_list tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(GraphqlTools::class, 'listGraphqlSchemas');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('stimmt_graphql_schemas_list')
            ->and($instance->description)->toContain('GraphQL schemas');
    });

    it('has stimmt_graphql_schema_get tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(GraphqlTools::class, 'getGraphqlSchema');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('stimmt_graphql_schema_get')
            ->and($instance->description)->toContain('SDL');
    });

    it('has stimmt_graphql_execute tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(GraphqlTools::class, 'executeGraphql');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('stimmt_graphql_execute')
            ->and($instance->description)->toContain('dangerous');
    });

    it('has stimmt_graphql_tokens_list tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(GraphqlTools::class, 'listGraphqlTokens');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('stimmt_graphql_tokens_list')
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

        expect($parameters)->toHaveCount(5)
            ->and($parameters[4]->getName())->toBe('context');

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
            expect((string) $reflection->getReturnType())->toBe('array');
        }
    });
});

describe('GraphqlTools dangerous tool', function () {
    it('stimmt_graphql_execute is marked as dangerous', function () {
        expect((new ReflectionMethod(GraphqlTools::class, 'executeGraphql'))->getAttributes(Dangerous::class))->toHaveCount(1);
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

describe('stimmt_graphql_query', function () {
    // The read-only guard below is what lets this tool stay out of the
    // dangerous-tools gate, so the two must agree.
    it('is registered read-only and not dangerous', function () {
        $method = new ReflectionMethod(GraphqlTools::class, 'queryGraphql');
        $tool = $method->getAttributes(McpTool::class)[0]->newInstance();

        expect($tool->name)->toBe('stimmt_graphql_query')
            ->and($tool->annotations?->readOnlyHint)->toBeTrue()
            ->and($method->getAttributes(Dangerous::class))->toBe([]);
    });

    it('rejects non-query operations by parsing the document', function () {
        $source = (string) file_get_contents((new ReflectionClass(GraphqlTools::class))->getFileName());

        expect($source)->toContain('Parser::parse(')
            ->and($source)->toContain('OperationDefinitionNode');
    });
});

// Behavioral coverage for the security mechanism itself: assertReadOnly only
// touches the GraphQL parser (pure PHP, no Craft boot), so it can run here.
describe('stimmt_graphql_query read-only guard', function () {
    $guard = function (string $query): void {
        $tools = (new ReflectionClass(GraphqlTools::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod(GraphqlTools::class, 'assertReadOnly'))->invoke($tools, $query);
    };

    it('rejects mutations before execution', function () use ($guard) {
        $guard('mutation { deleteEntry(id: 1) }');
    })->throws(ToolCallException::class, "Only query operations are allowed here; 'mutation'");

    it('rejects subscriptions before execution', function () use ($guard) {
        $guard('subscription { entryUpdated { id } }');
    })->throws(ToolCallException::class);

    it('rejects syntax errors with the parser message', function () use ($guard) {
        $guard('query {');
    })->throws(ToolCallException::class, 'GraphQL syntax error');

    it('allows named, anonymous, and fragment-bearing queries', function (string $query) use ($guard) {
        $guard($query);

        expect(true)->toBeTrue();
    })->with([
        ['query Entries { entries { id } }'],
        ['{ entries { id } }'],
        ['query Entries { entries { ...ids } } fragment ids on EntryInterface { id }'],
    ]);
});
