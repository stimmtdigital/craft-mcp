<?php

declare(strict_types=1);

use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Schema as SchemaDefinition;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\tools\GraphqlTools;

/**
 * What get_graphql_schema returns, and what it refuses.
 *
 * The tool used to answer `"sdl": null, "sdlLength": 0` with success set,
 * because it cast a GraphQL\Type\Schema object to string and swallowed the
 * TypeError. Printing it correctly is not enough on its own: the SDL of a
 * stock Craft schema is over half a megabyte, ten times what the stdio
 * transport can hand over in one write, so the shape of the answer is the
 * fix. These cover that shape on plain webonyx types, no Craft boot needed.
 */
$tools = new GraphqlTools();

$call = static fn (string $method, mixed ...$arguments): mixed => (new ReflectionMethod(GraphqlTools::class, $method))
    ->invoke($tools, ...$arguments);

/** A description long enough to push a single field past the tool's SDL limit. */
$hugeDescription = str_repeat('This field is documented at unreasonable length. ', 900);

$entryInterface = new InterfaceType([
    'name' => 'EntryInterface',
    'fields' => [
        'id' => ['type' => Type::id()],
        'title' => ['type' => Type::string(), 'description' => 'The entry title.'],
    ],
]);

$criteria = new InputObjectType([
    'name' => 'EntryCriteriaInput',
    'fields' => [
        'section' => ['type' => Type::listOf(Type::string()), 'description' => 'Section handles.'],
        'limit' => ['type' => Type::int(), 'defaultValue' => 10],
    ],
]);

$queryType = new ObjectType([
    'name' => 'Query',
    'fields' => [
        'entries' => [
            'type' => Type::listOf($entryInterface),
            'description' => 'Query for entries.',
            'args' => [
                'criteria' => ['type' => $criteria, 'description' => 'Narrows the results.'],
                'limit' => ['type' => Type::int(), 'defaultValue' => 100],
            ],
        ],
        'ping' => ['type' => Type::string()],
    ],
]);

$colour = new EnumType([
    'name' => 'Colour',
    'values' => ['RED' => ['value' => 'red'], 'BLUE' => ['value' => 'blue']],
]);

$union = new UnionType([
    'name' => 'AnyEntry',
    'types' => [new ObjectType(['name' => 'Article', 'fields' => ['id' => ['type' => Type::id()]]])],
]);

$verbose = new ObjectType([
    'name' => 'Verbose',
    'fields' => [
        'big' => ['type' => Type::string(), 'description' => $hugeDescription],
    ],
]);

$definition = new SchemaDefinition([
    'query' => $queryType,
    'types' => [$entryInterface, $criteria, $colour, $union, $verbose],
]);

describe('type index', function () use ($call, $definition) {
    it('lists every type the schema exposes with its kind and printed size', function () use ($call, $definition) {
        $index = $call('typeIndex', $definition);
        $byName = array_column($index, null, 'name');

        expect($byName['Query']['kind'])->toBe('object')
            ->and($byName['Query']['fields'])->toBe(2)
            ->and($byName['Query']['sdlBytes'])->toBeGreaterThan(0)
            ->and($byName['EntryInterface']['kind'])->toBe('interface')
            ->and($byName['EntryCriteriaInput']['kind'])->toBe('input')
            ->and($byName['Colour']['kind'])->toBe('enum')
            ->and($byName['AnyEntry']['kind'])->toBe('union');
    });

    // The sizes are what a caller reads to know which types it can ask for
    // whole, so they have to be the size of the thing that would come back.
    it('reports each type sdlBytes as the length of that type printed', function () use ($call, $definition) {
        $index = $call('typeIndex', $definition);
        $query = array_column($index, null, 'name')['Query'];
        $sdl = $call('sdlFor', $definition, 'Query', null);

        expect($query['sdlBytes'])->toBe($sdl['sdlBytes'])
            ->and($query['sdlBytes'])->toBe(strlen($sdl['sdl']));
    });

    it('leaves out the built-in scalars and introspection types, as the SDL does', function () use ($call, $definition) {
        $names = array_column($call('typeIndex', $definition), 'name');

        expect($names)->not->toContain('String')
            ->and($names)->not->toContain('Boolean')
            ->and($names)->not->toContain('__Schema')
            ->and($names)->not->toContain('__Type');
    });

    it('sorts by name, so two runs of the same schema diff cleanly', function () use ($call, $definition) {
        $names = array_column($call('typeIndex', $definition), 'name');
        $sorted = $names;
        sort($sorted);

        expect($names)->toBe($sorted);
    });

    it('omits the field count for a kind that has no fields', function () use ($call, $definition) {
        $byName = array_column($call('typeIndex', $definition), null, 'name');

        expect($byName['Colour'])->not->toHaveKey('fields')
            ->and($byName['AnyEntry'])->not->toHaveKey('fields');
    });
});

describe('one type', function () use ($call, $definition) {
    it('returns that type printed as SDL', function () use ($call, $definition) {
        $result = $call('sdlFor', $definition, 'EntryInterface', null);

        expect($result['name'])->toBe('EntryInterface')
            ->and($result['kind'])->toBe('interface')
            ->and($result['sdl'])->toContain('interface EntryInterface')
            ->and($result['sdl'])->toContain('title: String')
            ->and($result['sdlBytes'])->toBe(strlen($result['sdl']))
            ->and($result)->not->toHaveKey('field');
    });

    it('names the mistake and the way out when the type does not exist', function () use ($call, $definition) {
        $call('sdlFor', $definition, 'Nope', null);
    })->throws(ToolCallException::class, "No type 'Nope' in this schema");
});

describe('one field of a type', function () use ($call, $definition) {
    it('prints a single field of an object type, arguments and defaults included', function () use ($call, $definition) {
        $result = $call('sdlFor', $definition, 'Query', 'entries');

        expect($result['field'])->toBe('entries')
            ->and($result['sdl'])->toContain('type Query {')
            ->and($result['sdl'])->toContain('entries(')
            ->and($result['sdl'])->toContain('criteria: EntryCriteriaInput')
            ->and($result['sdl'])->toContain('limit: Int = 100')
            ->and($result['sdl'])->not->toContain('ping');
    });

    it('prints a single field of an interface under the interface keyword', function () use ($call, $definition) {
        $result = $call('sdlFor', $definition, 'EntryInterface', 'title');

        expect($result['sdl'])->toContain('interface EntryInterface')
            ->and($result['sdl'])->toContain('title: String')
            ->and($result['sdl'])->not->toContain('id: ID');
    });

    // An input field is rebuilt from its config array rather than handed over
    // as an object, which is the one path that could silently lose a default.
    it('prints a single field of an input type, keeping its default value', function () use ($call, $definition) {
        $result = $call('sdlFor', $definition, 'EntryCriteriaInput', 'limit');

        expect($result['sdl'])->toContain('input EntryCriteriaInput')
            ->and($result['sdl'])->toContain('limit: Int = 10')
            ->and($result['sdl'])->not->toContain('section');
    });

    it('lists the fields there are when the one asked for is not among them', function () use ($call, $definition) {
        $call('sdlFor', $definition, 'Query', 'entrys');
    })->throws(ToolCallException::class, "No field 'entrys' on type 'Query'. Its fields: entries, ping.");

    it('refuses a field on a kind that has none', function () use ($call, $definition) {
        $call('sdlFor', $definition, 'Colour', 'RED');
    })->throws(ToolCallException::class, "Type 'Colour' has no fields");
});

describe('size limit', function () use ($call, $definition) {
    it('refuses a type too large to deliver and names the parameter that narrows it', function () use ($call, $definition) {
        expect(fn () => $call('sdlFor', $definition, 'Verbose', null))
            ->toThrow(ToolCallException::class, 'by passing field. Its fields: big.');
    });

    it('states the actual size and the limit, so the caller can see the gap', function () use ($call, $definition) {
        expect(fn () => $call('sdlFor', $definition, 'Verbose', null))
            ->toThrow(ToolCallException::class, 'the limit for one call is 32768, so it was not sent');
    });

    // The one case with nothing left to narrow: it has to point somewhere
    // else rather than repeat the advice that just failed.
    it('sends a field that is itself too large to introspection instead', function () use ($call, $definition) {
        expect(fn () => $call('sdlFor', $definition, 'Verbose', 'big'))
            ->toThrow(ToolCallException::class, 'query_graphql');
    });

    it('lets a type through when it fits', function () use ($call, $definition) {
        $result = $call('sdlFor', $definition, 'Query', null);

        expect($result['sdlBytes'])->toBeLessThan(32768);
    });
});

describe('get_graphql_schema surface', function () {
    it('declares type and field, each described for an agent reading it cold', function () {
        $parameters = [];

        foreach ((new ReflectionMethod(GraphqlTools::class, 'getGraphqlSchema'))->getParameters() as $parameter) {
            $attributes = $parameter->getAttributes(Schema::class);
            if ($attributes === []) {
                continue;
            }

            $parameters[$parameter->getName()] = $attributes[0]->newInstance()->description;
        }

        expect($parameters)->toHaveKeys(['id', 'uid', 'type', 'field'])
            ->and($parameters['type'])->toContain('sdlBytes')
            ->and($parameters['field'])->toContain('size limit');
    });

    it('says in its own description that the whole SDL never comes back', function () {
        $tool = (new ReflectionMethod(GraphqlTools::class, 'getGraphqlSchema'))
            ->getAttributes(McpTool::class)[0]->newInstance();

        expect($tool->description)->toContain('SDL')
            ->and($tool->description)->toContain('never returned whole');
    });

    // Both input guards sit ahead of every Craft call, so a caller learns the
    // parameter is misused without a schema lookup happening first.
    it('rejects field without type before touching Craft', function () {
        (new GraphqlTools())->getGraphqlSchema(id: 1, field: 'entries');
    })->throws(ToolCallException::class, 'needs type as well');

    it('still requires an id or a uid', function () {
        (new GraphqlTools())->getGraphqlSchema();
    })->throws(ToolCallException::class, 'Either id or uid must be provided');

    // The defect itself: getSchemaDef() returns a Schema object, so this cast
    // threw on every call, and the bare catch below it reported success.
    it('never casts the schema definition to a string', function () {
        $source = (string) file_get_contents((string) (new ReflectionClass(GraphqlTools::class))->getFileName());

        expect($source)->not->toContain('(string) $gql->getSchemaDef')
            ->and($source)->toContain('SchemaPrinter::doPrint')
            ->and($source)->not->toContain('catch (Throwable)');
    });
});
