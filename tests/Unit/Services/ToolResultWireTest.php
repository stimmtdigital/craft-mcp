<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Fixtures/RealCraft.php';

use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use stimmt\craft\Mcp\enums\ResponseFormat;
use stimmt\craft\Mcp\pipeline\ErrorBoundary;
use stimmt\craft\Mcp\pipeline\Freshness;
use stimmt\craft\Mcp\pipeline\Presenter;
use stimmt\craft\Mcp\services\ServerFactory;
use stimmt\craft\Mcp\text\Palette;
use stimmt\craft\Mcp\text\Renderer;

/**
 * ServerFactory::create() needs a booted Craft app, so this drives the real
 * SDK CallToolHandler with the same collaborators the factory hands it. It is
 * the end-to-end proof that a tool payload now crosses the wire once: without
 * the Presenter, CallToolHandler emits `content` AND `structuredContent` for
 * every array return.
 */
function callTool(callable $handler, array $inputSchema, array $arguments, bool $present = true, bool $guard = false): array {
    $registry = new Registry();
    $registry->registerTool(
        new Tool(name: 'demo', title: null, inputSchema: $inputSchema, description: null, annotations: null),
        $handler,
    );

    $inner = new ReferenceHandler();
    $reference = $present ? new Presenter($inner, new Renderer(new Palette(false))) : $inner;
    $reference = $guard ? new ErrorBoundary($reference) : $reference;

    $result = (new CallToolHandler($registry, $reference))->handle(
        (new CallToolRequest('demo', $arguments))->withId(1),
        new Session(new InMemorySessionStore()),
    );

    // A throw that reaches the SDK's own catch produces an Error envelope, not
    // a result: the whole point of the boundary is that the client gets a
    // readable tool result instead. Both shapes are returned so a test can say
    // which one it got.
    return $result instanceof Response
        ? json_decode(json_encode($result->result), true)
        : ['error' => ['code' => $result->code, 'message' => $result->message]];
}

describe('tool result on the wire', function () {
    it('sends an array payload twice without the presenter (the bug)', function () {
        $wire = callTool(
            static fn (): array => ['count' => 1, 'handle' => 'default'],
            ['type' => 'object'],
            [],
            present: false,
        );

        expect($wire)->toHaveKey('structuredContent')
            ->and($wire['structuredContent'])->toBe(['count' => 1, 'handle' => 'default'])
            ->and(json_decode($wire['content'][0]['text'], true))->toBe(['count' => 1, 'handle' => 'default']);
    });

    it('sends it exactly once with the presenter installed', function () {
        $wire = callTool(
            static fn (): array => ['count' => 1, 'handle' => 'default'],
            ['type' => 'object'],
            [],
        );

        expect($wire)->not->toHaveKey('structuredContent')
            ->and($wire['content'])->toHaveCount(1)
            ->and(json_decode($wire['content'][0]['text'], true))->toBe(['count' => 1, 'handle' => 'default']);
    });

    it('renders text for a tool that declares the output parameter', function () {
        $wire = callTool(
            static fn (ResponseFormat $output = ResponseFormat::STRUCTURED): array => ['count' => 1, 'handle' => 'default'],
            [
                'type' => 'object',
                'properties' => [
                    'output' => ['type' => 'string', 'enum' => array_column(ResponseFormat::cases(), 'value')],
                ],
            ],
            ['output' => 'text'],
        );

        expect($wire['content'][0]['text'])->toBe("count:  1\nhandle: default")
            ->and($wire)->not->toHaveKey('structuredContent');
    });
});

describe('tools that build their own content', function () {
    /**
     * tinker and read_logs (output=text) return a TextContent. Their wire
     * payload is the regression baseline for this change: it must be exactly
     * what the SDK sent before the presenter existed, byte for byte.
     */
    it('sends a TextContent return exactly as the SDK did', function () {
        $handler = static fn (): TextContent => new TextContent("> \$x = 1\n= 1");
        $schema = ['type' => 'object'];

        expect(callTool($handler, $schema, []))
            ->toBe(callTool($handler, $schema, [], present: false));
    });
});

describe('the error boundary', function () {
    /**
     * The leak this closes: anything thrown outside a tool body reaches the
     * SDK's generic catch, which answers with the fixed string 'Error while
     * executing tool' and discards the real message. A tool body's own wrapper
     * cannot cover argument preparation, result formatting, or a fault in the
     * output layer itself, which is exactly the class of bug that shipped here.
     */
    it('keeps the real message where the SDK would have replaced it', function () {
        $thrower = static fn (): array => throw new RuntimeException('the database went away');
        $schema = ['type' => 'object'];

        $unguarded = callTool($thrower, $schema, [], present: false);
        $guarded = callTool($thrower, $schema, [], present: false, guard: true);

        // Without it: a JSON-RPC error carrying a fixed string, with the real
        // message discarded and nothing for the agent to act on.
        expect($unguarded['error']['message'])->toBe('Error while executing tool');

        // With it: a normal tool result flagged as an error, naming the cause.
        expect($guarded['isError'])->toBeTrue()
            ->and($guarded['content'][0]['text'])->toContain('the database went away');
    });

    it('leaves an exception that already carries a chosen message alone', function () {
        $thrower = static fn (): array => throw new ToolCallException('Section \'news\' not found');

        $wire = callTool($thrower, ['type' => 'object'], [], present: false, guard: true);

        expect($wire['content'][0]['text'])->toBe('Section \'news\' not found');
    });
});

describe('ServerFactory wiring', function () {
    /**
     * create() itself needs a booted Craft app, so this pins the chain it
     * installs. The order is the design: ErrorBoundary has to sit outside the
     * Presenter to catch argument preparation, result formatting and any fault
     * in the Presenter itself, and an inner boundary would catch none of them.
     */
    it('wraps the SDK reference handler in the boundary, the refresher and the presenter', function () {
        $handler = (new ReflectionMethod(ServerFactory::class, 'presenter'))
            ->invoke(new ServerFactory());

        $unwrap = static fn (object $decorator, string $class): object => (new ReflectionProperty($class, 'handler'))
            ->getValue($decorator);

        expect($handler)->toBeInstanceOf(ErrorBoundary::class);

        $refresh = $unwrap($handler, ErrorBoundary::class);
        expect($refresh)->toBeInstanceOf(Freshness::class);

        $presenter = $unwrap($refresh, Freshness::class);
        expect($presenter)->toBeInstanceOf(Presenter::class)
            ->and($unwrap($presenter, Presenter::class))->toBeInstanceOf(ReferenceHandler::class);
    });

    it('passes the reference handler to the builder', function () {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/services/ServerFactory.php');

        expect($source)->toContain('->setReferenceHandler($this->presenter())');
    });
});

describe('outputSchema', function () {
    /**
     * The Presenter drops structuredContent because no tool declares an output
     * schema, so the copy is a duplicate the client cannot be obliged to read.
     * A tool that DOES declare one is contractually owed it, and dropping it
     * then would mean advertising a schema the server never honours. Dormant
     * today, and the failure it prevents would be silent.
     */
    it('attaches structuredContent for a tool that declares an output schema', function () {
        $registry = new Registry();
        $registry->registerTool(
            new Tool(
                name: 'demo',
                title: null,
                inputSchema: ['type' => 'object'],
                description: null,
                annotations: null,
                outputSchema: ['type' => 'object', 'properties' => ['count' => ['type' => 'integer']]],
            ),
            static fn (): array => ['count' => 2],
        );

        $result = (new CallToolHandler($registry, new Presenter(new ReferenceHandler(), new Renderer(new Palette(false)))))
            ->handle((new CallToolRequest('demo', []))->withId(1), new Session(new InMemorySessionStore()));

        $wire = json_decode(json_encode($result->result), true);

        expect($wire['structuredContent'])->toBe(['count' => 2]);
    });
});
