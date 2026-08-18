<?php

declare(strict_types=1);

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use stimmt\craft\Mcp\enums\ResponseFormat;
use stimmt\craft\Mcp\support\Palette;
use stimmt\craft\Mcp\support\Presenter;
use stimmt\craft\Mcp\support\Renderer;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\tools\EntryTools;
use stimmt\craft\Mcp\tools\EntryWorkflowTools;
use stimmt\craft\Mcp\tools\NestedEntryTools;

/**
 * A write the tool itself refused used to reach the client as a SUCCESSFUL
 * call whose JSON happened to say otherwise, so nothing told the model to
 * self-correct. Response::failure() states the outcome and the Presenter turns
 * that into isError on the wire.
 *
 * Helpers are closures, not named functions: Pest shares one global function
 * namespace across the whole suite, and tests/Unit/Support/PresenterTest.php
 * already owns `present`, `returning` and `toolReference`.
 */
$handlerReturning = static fn (mixed $result): ReferenceHandlerInterface => new class ($result) implements ReferenceHandlerInterface {
    public function __construct(private readonly mixed $result) {
    }

    public function handle(ElementReference $reference, array $arguments): mixed {
        return $this->result;
    }
};

/** The schema shape that opts a tool into the text view. */
$textSchema = [
    'type' => 'object',
    'properties' => [
        'output' => ['type' => 'string', 'enum' => array_column(ResponseFormat::cases(), 'value')],
    ],
];

$present = static function (
    mixed $result,
    array $arguments = [],
    ?array $inputSchema = null,
    ?array $outputSchema = null,
) use ($handlerReturning): mixed {
    $reference = new ToolReference(
        new Tool('demo', null, $inputSchema ?? ['type' => 'object'], null, null, outputSchema: $outputSchema),
        static fn (): array => [],
    );

    return (new Presenter($handlerReturning($result), new Renderer(new Palette(false))))
        ->handle($reference, $arguments);
};

/** The body of one tool method, so a call site can be pinned without a booted Craft app. */
$methodSource = static function (string $class, string $method): string {
    $reflection = new ReflectionMethod($class, $method);
    $lines = file((string) $reflection->getFileName());

    return implode('', array_slice(
        (array) $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
};

describe('Response::failure()', function () {
    it('states the outcome first, exactly as the hand-written payload did', function () {
        $result = Response::failure(['action' => 'created', 'elementId' => null, 'errors' => ['title' => ['Title cannot be blank.']]]);

        expect($result)->toBe([
            'success' => false,
            'action' => 'created',
            'elementId' => null,
            'errors' => ['title' => ['Title cannot be blank.']],
        ])->and(array_key_first($result))->toBe('success');
    });

    it('is the mirror of success(), under the same key', function () {
        expect(array_keys(Response::failure(['a' => 1])))->toBe(array_keys(Response::success(['a' => 1])));
    });
});

describe('Response::isFailure()', function () {
    it('recognises what failure() produces and nothing success() produces', function () {
        expect(Response::isFailure(Response::failure(['action' => 'created'])))->toBeTrue()
            ->and(Response::isFailure(Response::success(['action' => 'created'])))->toBeFalse();
    });

    it('leaves a payload that carries no outcome alone', function () {
        expect(Response::isFailure(['count' => 0, 'entries' => []]))->toBeFalse()
            ->and(Response::isFailure(Response::list('entries', [])))->toBeFalse()
            ->and(Response::isFailure(Response::found('entry', null)))->toBeFalse()
            ->and(Response::isFailure([]))->toBeFalse();
    });

    it('is not fooled by a falsy lookalike or a nested outcome', function () {
        expect(Response::isFailure(['success' => 'false']))->toBeFalse()
            ->and(Response::isFailure(['success' => 0]))->toBeFalse()
            ->and(Response::isFailure(['success' => null]))->toBeFalse()
            ->and(Response::isFailure(['data' => ['success' => false]]))->toBeFalse();
    });

    it('ignores anything that is not an array, which is what tinker and read_logs return', function () {
        expect(Response::isFailure(new TextContent('> $x')))->toBeFalse()
            ->and(Response::isFailure(null))->toBeFalse()
            ->and(Response::isFailure('success'))->toBeFalse();
    });
});

describe('a refused write on the wire', function () use ($present, $textSchema) {
    it('flags the call as an error', function () use ($present) {
        $result = $present(Response::failure(['action' => 'created', 'errors' => ['title' => ['Title cannot be blank.']]]));

        expect($result)->toBeInstanceOf(CallToolResult::class)
            ->and($result->isError)->toBeTrue()
            ->and($result->jsonSerialize()['isError'])->toBeTrue();
    });

    it('carries the same payload bytes it carried before the flag existed', function () use ($present) {
        $payload = Response::failure(['action' => 'created', 'elementId' => null, 'errors' => ['title' => ['Title cannot be blank.']]]);
        $result = $present($payload);

        expect($result->content)->toHaveCount(1)
            ->and(json_decode($result->content[0]->text, true))->toBe($payload);
    });

    it('never attaches structuredContent, not even when the tool declares an output schema', function () use ($present) {
        $result = $present(
            Response::failure(['errors' => ['title' => ['Title cannot be blank.']]]),
            outputSchema: ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean']]],
        );

        expect($result->structuredContent)->toBeNull()
            ->and($result->jsonSerialize())->not->toHaveKey('structuredContent');
    });

    it('renders as text for a tool that opted into the text view', function () use ($present, $textSchema) {
        $result = $present(
            Response::failure(['action' => 'created']),
            ['output' => 'text'],
            $textSchema,
        );

        expect($result->isError)->toBeTrue()
            ->and($result->content[0]->text)->toBe("success: false\naction:  created");
    });

    it('leaves a successful payload unflagged', function () use ($present) {
        $result = $present(Response::success(['action' => 'created', 'elementId' => 12]));

        expect($result->isError)->toBeFalse();
    });

    it('leaves a read that found nothing unflagged', function () use ($present) {
        expect($present(Response::list('entries', []))->isError)->toBeFalse()
            ->and($present(['found' => false, 'message' => 'No errors found in recent logs'])->isError)->toBeFalse();
    });
});

describe('the write tools that can be refused', function () use ($methodSource) {
    it('signals through the shared producer rather than a hand-written payload', function (string $class, string $method) use ($methodSource) {
        expect($methodSource($class, $method))->toContain('Response::failure(');
    })->with([
        'create_entry' => [EntryTools::class, 'createEntry'],
        'update_entry' => [EntryTools::class, 'updateEntry'],
        'duplicate_entry' => [EntryWorkflowTools::class, 'duplicateEntry'],
        'copy_entry_to_site' => [EntryWorkflowTools::class, 'copyEntryToSite'],
        'create_nested_entry' => [NestedEntryTools::class, 'createNestedEntry'],
    ]);

    // The old spelling was `['success' => false] + $result->toArray()`, which
    // the Presenter cannot see as a failure unless it goes through Response.
    // One left behind is one write that still reports success while refusing.
    it('leaves no tool spelling the outcome by hand', function () {
        $offenders = array_values(array_filter(
            (array) glob(__DIR__ . '/../../../src/tools/*.php'),
            static fn (string $file): bool => str_contains((string) file_get_contents($file), "'success' => false"),
        ));

        expect($offenders)->toBe([]);
    });
});
