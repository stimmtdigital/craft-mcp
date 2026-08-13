<?php

declare(strict_types=1);

use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use stimmt\craft\Mcp\enums\ResponseFormat;
use stimmt\craft\Mcp\support\Palette;
use stimmt\craft\Mcp\support\Presenter;
use stimmt\craft\Mcp\support\Renderer;
use stimmt\craft\Mcp\tools\DatabaseTools;
use stimmt\craft\Mcp\tools\EntryTools;

/**
 * The text view is opt-in by signature: a tool declares
 * `ResponseFormat $output`, which puts the enum in its JSON schema, and the
 * Presenter keys off that schema. These tests generate the schema with the
 * SDK's own generator from the real method signatures, so an opted-in tool
 * that stops advertising the parameter fails here rather than silently
 * dropping back to JSON in production.
 */
function schemaFor(string $class, string $method): array {
    return (new SchemaGenerator(new DocBlockParser()))->generate(new ReflectionMethod($class, $method));
}

function presentPayload(array $schema, array $payload, array $arguments): CallToolResult {
    $inner = new class ($payload) implements ReferenceHandlerInterface {
        public function __construct(private readonly array $payload) {
        }

        public function handle(ElementReference $reference, array $arguments): mixed {
            return $this->payload;
        }
    };

    $reference = new ToolReference(
        new Tool('demo', null, $schema, null, null),
        static fn (): array => [],
    );

    return (new Presenter($inner, new Renderer(new Palette(false))))->handle($reference, $arguments);
}

describe('tools opted into the text view', function () {
    it('advertises the response format enum in its schema', function (string $class, string $method) {
        $schema = schemaFor($class, $method);

        expect($schema['properties']['output']['enum'])
            ->toBe(array_column(ResponseFormat::cases(), 'value'))
            ->and($schema['properties']['output']['description'])->toBe(Presenter::OUTPUT_DESCRIPTION)
            ->and($schema['required'] ?? [])->not->toContain('output');
    })->with([
        'run_query' => [DatabaseTools::class, 'runQuery'],
        'get_table_counts' => [DatabaseTools::class, 'getTableCounts'],
        'count_entries' => [EntryTools::class, 'countEntries'],
    ]);

    it('renders run_query rows as a table when text is asked for', function () {
        $result = presentPayload(
            schemaFor(DatabaseTools::class, 'runQuery'),
            [
                'success' => true,
                'count' => 2,
                'columns' => ['id', 'title'],
                'rows' => [
                    ['id' => 1, 'title' => 'Hello'],
                    ['id' => 2, 'title' => 'Goodbye'],
                ],
            ],
            ['output' => 'text'],
        );

        expect($result->content[0]->text)->toBe(<<<'TEXT'
            success: true
            count:   2
            columns: id, title
            rows:
              id  title
              --  -------
              1   Hello
              2   Goodbye
            TEXT);
    });

    it('renders a count_entries breakdown as a table of buckets', function () {
        $result = presentPayload(
            schemaFor(EntryTools::class, 'countEntries'),
            [
                'success' => true,
                'total' => 50,
                'buckets' => [
                    ['key' => '2023-12', 'count' => 18],
                    ['key' => '2024-01', 'count' => 32],
                ],
                'groupBy' => 'month:dateUpdated',
            ],
            ['output' => 'text'],
        );

        expect($result->content[0]->text)->toBe(<<<'TEXT'
            success: true
            total:   50
            buckets:
              key      count
              -------  -----
              2023-12  18
              2024-01  32
            groupBy: month:dateUpdated
            TEXT);
    });

    it('renders a plain total with no breakdown', function () {
        $result = presentPayload(
            schemaFor(EntryTools::class, 'countEntries'),
            ['success' => true, 'total' => 245, 'groupBy' => null],
            ['output' => 'text'],
        );

        expect($result->content[0]->text)->toBe(<<<'TEXT'
            success: true
            total:   245
            groupBy: null
            TEXT);
    });

    it('renders get_table_counts as a table keyed by table name', function () {
        $result = presentPayload(
            schemaFor(DatabaseTools::class, 'getTableCounts'),
            [
                'entries' => ['label' => 'Entries', 'count' => 56],
                'assets' => ['label' => 'Assets', 'count' => 4],
            ],
            ['output' => 'text'],
        );

        expect($result->content[0]->text)->toBe(<<<'TEXT'
                     label    count
            -------  -------  -----
            entries  Entries  56
            assets   Assets   4
            TEXT);
    });

    it('still returns the JSON payload by default', function () {
        $result = presentPayload(
            schemaFor(DatabaseTools::class, 'getTableCounts'),
            ['entries' => ['label' => 'Entries', 'count' => 56]],
            [],
        );

        expect(json_decode($result->content[0]->text, true))
            ->toBe(['entries' => ['label' => 'Entries', 'count' => 56]])
            ->and($result->structuredContent)->toBeNull();
    });
});

describe('tinker is not opted in', function () {
    it('keeps its own unrelated output parameter out of the convention', function () {
        $schema = schemaFor(stimmt\craft\Mcp\tools\TinkerTools::class, 'tinker');

        expect($schema['properties']['output']['enum'] ?? null)->toBeNull();
    });
});
